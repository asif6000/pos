const express = require('express');
const router = express.Router();
const pool = require('../config/db');
const { authenticate } = require('../middleware/auth');

router.use(authenticate);

function generateInvoiceNumber() {
  const now = new Date();
  const y = now.getFullYear();
  const m = String(now.getMonth() + 1).padStart(2, '0');
  const d = String(now.getDate()).padStart(2, '0');
  const rand = String(Math.floor(100000 + Math.random() * 900000));
  return `INV-${y}${m}${d}-${rand}`;
}

router.get('/', async (req, res) => {
  try {
    const { start_date, end_date, payment_method, customer_id, page = 1, limit = 20 } = req.query;
    const offset = (parseInt(page) - 1) * parseInt(limit);

    let where = [];
    let params = [];

    if (start_date) {
      where.push('s.sale_date >= ?');
      params.push(start_date);
    }
    if (end_date) {
      where.push('s.sale_date <= ?');
      params.push(end_date + ' 23:59:59');
    }
    if (payment_method) {
      where.push('s.payment_method = ?');
      params.push(payment_method);
    }
    if (customer_id) {
      where.push('s.customer_id = ?');
      params.push(customer_id);
    }

    const whereClause = where.length > 0 ? 'WHERE ' + where.join(' AND ') : '';

    const [countResult] = await pool.query(`SELECT COUNT(*) as total FROM sales s ${whereClause}`, params);
    const total = countResult[0].total;

    const [sales] = await pool.query(
      `SELECT s.*, c.name as customer_name 
       FROM sales s 
       LEFT JOIN customers c ON s.customer_id = c.id 
       ${whereClause} 
       ORDER BY s.id DESC 
       LIMIT ? OFFSET ?`,
      [...params, parseInt(limit), offset]
    );

    res.json({
      success: true,
      data: sales,
      pagination: {
        total,
        page: parseInt(page),
        limit: parseInt(limit),
        totalPages: Math.ceil(total / parseInt(limit))
      }
    });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

router.get('/:id', async (req, res) => {
  try {
    const [sales] = await pool.query(
      `SELECT s.*, c.name as customer_name, c.phone as customer_phone 
       FROM sales s 
       LEFT JOIN customers c ON s.customer_id = c.id 
       WHERE s.id = ?`,
      [req.params.id]
    );
    if (sales.length === 0) {
      return res.status(404).json({ success: false, message: 'Sale not found' });
    }

    const [items] = await pool.query(
      `SELECT si.*, p.name as product_name, p.barcode 
       FROM sale_items si 
       LEFT JOIN products p ON si.product_id = p.id 
       WHERE si.sale_id = ?`,
      [req.params.id]
    );

    res.json({
      success: true,
      data: {
        ...sales[0],
        items
      }
    });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

router.post('/', async (req, res) => {
  const connection = await pool.getConnection();
  try {
    await connection.beginTransaction();

    const { customer_id, items, discount, vat, payment_method, paid_amount } = req.body;

    if (!items || items.length === 0) {
      return res.status(400).json({ success: false, message: 'At least one item is required' });
    }

    let subtotal = 0;
    for (const item of items) {
      subtotal += item.quantity * item.price;
    }

    const discountAmount = parseFloat(discount) || 0;
    const vatAmount = parseFloat(vat) || 0;
    const totalAmount = subtotal - discountAmount + vatAmount;
    const paidAmt = parseFloat(paid_amount) || 0;
    const dueAmount = totalAmount - paidAmt;

    const invoiceNumber = generateInvoiceNumber();

    const [saleResult] = await connection.query(
      `INSERT INTO sales (invoice_number, customer_id, subtotal, discount, vat, total_amount, paid_amount, due_amount, payment_method, sale_date, created_by) 
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)`,
      [invoiceNumber, customer_id || null, subtotal, discountAmount, vatAmount, totalAmount, paidAmt, dueAmount, payment_method || 'cash', req.user.id]
    );

    const saleId = saleResult.insertId;

    for (const item of items) {
      await connection.query(
        `INSERT INTO sale_items (sale_id, product_id, quantity, price, total) VALUES (?, ?, ?, ?, ?)`,
        [saleId, item.product_id, item.quantity, item.price, item.quantity * item.price]
      );

      await connection.query(
        'UPDATE products SET stock = stock - ? WHERE id = ?',
        [item.quantity, item.product_id]
      );

      await connection.query(
        `INSERT INTO stock_history (product_id, type, quantity, reference_type, reference_id, note, created_by) 
         VALUES (?, 'sale', ?, 'sale', ?, ?, ?)`,
        [item.product_id, item.quantity, saleId, `Sale ${invoiceNumber}`, req.user.id]
      );
    }

    if (customer_id && dueAmount > 0) {
      await connection.query(
        'UPDATE customers SET due_amount = due_amount + ? WHERE id = ?',
        [dueAmount, customer_id]
      );
    }

    await connection.commit();

    const [sale] = await pool.query(
      `SELECT s.*, c.name as customer_name FROM sales s LEFT JOIN customers c ON s.customer_id = c.id WHERE s.id = ?`,
      [saleId]
    );
    const [saleItems] = await pool.query(
      `SELECT si.*, p.name as product_name FROM sale_items si LEFT JOIN products p ON si.product_id = p.id WHERE si.sale_id = ?`,
      [saleId]
    );

    res.status(201).json({
      success: true,
      message: 'Sale created successfully',
      data: { ...sale[0], items: saleItems }
    });
  } catch (err) {
    await connection.rollback();
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  } finally {
    connection.release();
  }
});

router.delete('/:id', async (req, res) => {
  const connection = await pool.getConnection();
  try {
    await connection.beginTransaction();

    const { id } = req.params;

    const [sales] = await connection.query('SELECT * FROM sales WHERE id = ?', [id]);
    if (sales.length === 0) {
      return res.status(404).json({ success: false, message: 'Sale not found' });
    }

    const sale = sales[0];

    const [items] = await connection.query('SELECT * FROM sale_items WHERE sale_id = ?', [id]);

    for (const item of items) {
      await connection.query(
        'UPDATE products SET stock = stock + ? WHERE id = ?',
        [item.quantity, item.product_id]
      );

      await connection.query(
        `INSERT INTO stock_history (product_id, type, quantity, reference_type, reference_id, note, created_by) 
         VALUES (?, 'sale_return', ?, 'sale', ?, ?, ?)`,
        [item.product_id, item.quantity, id, `Sale ${sale.invoice_number} deleted`, req.user.id]
      );
    }

    if (sale.customer_id && sale.due_amount > 0) {
      await connection.query(
        'UPDATE customers SET due_amount = due_amount - ? WHERE id = ?',
        [sale.due_amount, sale.customer_id]
      );
    }

    await connection.query('DELETE FROM sale_items WHERE sale_id = ?', [id]);
    await connection.query('DELETE FROM sales WHERE id = ?', [id]);

    await connection.commit();
    res.json({ success: true, message: 'Sale deleted and stock restored successfully' });
  } catch (err) {
    await connection.rollback();
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  } finally {
    connection.release();
  }
});

module.exports = router;

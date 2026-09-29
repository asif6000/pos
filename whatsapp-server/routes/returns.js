const express = require('express');
const router = express.Router();
const pool = require('../config/db');
const { authenticate } = require('../middleware/auth');

router.use(authenticate);

router.get('/', async (req, res) => {
  try {
    const { page = 1, limit = 20, start_date, end_date } = req.query;
    const offset = (parseInt(page) - 1) * parseInt(limit);

    let where = [];
    let params = [];

    if (start_date) {
      where.push('r.created_at >= ?');
      params.push(start_date);
    }
    if (end_date) {
      where.push('r.created_at <= ?');
      params.push(end_date + ' 23:59:59');
    }

    const whereClause = where.length > 0 ? 'WHERE ' + where.join(' AND ') : '';

    const [countResult] = await pool.query(`SELECT COUNT(*) as total FROM returns r ${whereClause}`, params);
    const total = countResult[0].total;

    const [returns] = await pool.query(
      `SELECT r.*, s.invoice_number, c.name as customer_name 
       FROM returns r 
       LEFT JOIN sales s ON r.sale_id = s.id 
       LEFT JOIN customers c ON s.customer_id = c.id 
       ${whereClause} 
       ORDER BY r.id DESC 
       LIMIT ? OFFSET ?`,
      [...params, parseInt(limit), offset]
    );

    res.json({
      success: true,
      data: returns,
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
    const [returns] = await pool.query(
      `SELECT r.*, s.invoice_number, c.name as customer_name, c.phone as customer_phone 
       FROM returns r 
       LEFT JOIN sales s ON r.sale_id = s.id 
       LEFT JOIN customers c ON s.customer_id = c.id 
       WHERE r.id = ?`,
      [req.params.id]
    );
    if (returns.length === 0) {
      return res.status(404).json({ success: false, message: 'Return not found' });
    }

    const [items] = await pool.query(
      `SELECT ri.*, p.name as product_name, p.barcode 
       FROM return_items ri 
       LEFT JOIN products p ON ri.product_id = p.id 
       WHERE ri.return_id = ?`,
      [req.params.id]
    );

    res.json({
      success: true,
      data: {
        ...returns[0],
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

    const { sale_id, items, refund_method, reason } = req.body;

    if (!sale_id || !items || items.length === 0) {
      return res.status(400).json({ success: false, message: 'Sale ID and items are required' });
    }

    const [sale] = await connection.query('SELECT * FROM sales WHERE id = ?', [sale_id]);
    if (sale.length === 0) {
      return res.status(404).json({ success: false, message: 'Sale not found' });
    }

    let totalRefund = 0;
    for (const item of items) {
      totalRefund += item.quantity * item.price;
    }

    const [result] = await connection.query(
      'INSERT INTO returns (sale_id, total_refund, refund_method, reason, processed_by) VALUES (?, ?, ?, ?, ?)',
      [sale_id, totalRefund, refund_method || 'cash', reason || null, req.user.id]
    );

    const returnId = result.insertId;

    for (const item of items) {
      await connection.query(
        'INSERT INTO return_items (return_id, product_id, quantity, price, total) VALUES (?, ?, ?, ?, ?)',
        [returnId, item.product_id, item.quantity, item.price, item.quantity * item.price]
      );

      await connection.query(
        'UPDATE products SET stock = stock + ? WHERE id = ?',
        [item.quantity, item.product_id]
      );

      await connection.query(
        `INSERT INTO stock_history (product_id, type, quantity, reference_type, reference_id, note, created_by) 
         VALUES (?, 'return', ?, 'return', ?, ?, ?)`,
        [item.product_id, item.quantity, returnId, `Return for sale ${sale[0].invoice_number}`, req.user.id]
      );
    }

    if (sale[0].customer_id) {
      await connection.query(
        'UPDATE customers SET due_amount = GREATEST(due_amount - ?, 0) WHERE id = ?',
        [totalRefund, sale[0].customer_id]
      );
    }

    await connection.commit();

    const [returnRecord] = await pool.query(
      `SELECT r.*, s.invoice_number FROM returns r 
       LEFT JOIN sales s ON r.sale_id = s.id 
       WHERE r.id = ?`,
      [returnId]
    );
    const [returnItems] = await pool.query(
      `SELECT ri.*, p.name as product_name FROM return_items ri 
       LEFT JOIN products p ON ri.product_id = p.id 
       WHERE ri.return_id = ?`,
      [returnId]
    );

    res.status(201).json({
      success: true,
      message: 'Return processed successfully',
      data: { ...returnRecord[0], items: returnItems }
    });
  } catch (err) {
    await connection.rollback();
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  } finally {
    connection.release();
  }
});

module.exports = router;

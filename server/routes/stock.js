const express = require('express');
const router = express.Router();
const pool = require('../config/db');
const { authenticate } = require('../middleware/auth');

router.use(authenticate);

router.get('/', async (req, res) => {
  try {
    const { search, category_id, page = 1, limit = 20 } = req.query;
    const offset = (parseInt(page) - 1) * parseInt(limit);

    let where = [];
    let params = [];

    if (search) {
      where.push('(p.name LIKE ? OR p.barcode LIKE ?)');
      params.push(`%${search}%`, `%${search}%`);
    }
    if (category_id) {
      where.push('p.category_id = ?');
      params.push(category_id);
    }

    const whereClause = where.length > 0 ? 'WHERE ' + where.join(' AND ') : '';

    const [countResult] = await pool.query(`SELECT COUNT(*) as total FROM products p ${whereClause}`, params);
    const total = countResult[0].total;

    const [products] = await pool.query(
      `SELECT p.id, p.name, p.barcode, p.stock, p.min_stock, p.unit, p.buy_price, p.sell_price, 
              c.name as category_name
       FROM products p 
       LEFT JOIN categories c ON p.category_id = c.id 
       ${whereClause} 
       ORDER BY p.stock ASC 
       LIMIT ? OFFSET ?`,
      [...params, parseInt(limit), offset]
    );

    res.json({
      success: true,
      data: products,
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

router.get('/history', async (req, res) => {
  try {
    const { product_id, type, start_date, end_date, page = 1, limit = 20 } = req.query;
    const offset = (parseInt(page) - 1) * parseInt(limit);

    let where = [];
    let params = [];

    if (product_id) {
      where.push('sh.product_id = ?');
      params.push(product_id);
    }
    if (type) {
      where.push('sh.type = ?');
      params.push(type);
    }
    if (start_date) {
      where.push('sh.created_at >= ?');
      params.push(start_date);
    }
    if (end_date) {
      where.push('sh.created_at <= ?');
      params.push(end_date + ' 23:59:59');
    }

    const whereClause = where.length > 0 ? 'WHERE ' + where.join(' AND ') : '';

    const [countResult] = await pool.query(
      `SELECT COUNT(*) as total FROM stock_history sh ${whereClause}`,
      params
    );
    const total = countResult[0].total;

    const [history] = await pool.query(
      `SELECT sh.*, p.name as product_name 
       FROM stock_history sh 
       LEFT JOIN products p ON sh.product_id = p.id 
       ${whereClause} 
       ORDER BY sh.id DESC 
       LIMIT ? OFFSET ?`,
      [...params, parseInt(limit), offset]
    );

    res.json({
      success: true,
      data: history,
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

router.get('/low', async (req, res) => {
  try {
    const [products] = await pool.query(
      `SELECT p.*, c.name as category_name 
       FROM products p 
       LEFT JOIN categories c ON p.category_id = c.id 
       WHERE p.stock <= p.min_stock AND p.min_stock > 0 
       ORDER BY p.stock ASC`
    );
    res.json({ success: true, data: products });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

router.post('/adjust', async (req, res) => {
  const connection = await pool.getConnection();
  try {
    await connection.beginTransaction();

    const { product_id, quantity_change, type, note } = req.body;

    if (!product_id || quantity_change === undefined || !type) {
      return res.status(400).json({ success: false, message: 'product_id, quantity_change, and type are required' });
    }

    const [products] = await connection.query('SELECT * FROM products WHERE id = ?', [product_id]);
    if (products.length === 0) {
      return res.status(404).json({ success: false, message: 'Product not found' });
    }

    const newStock = products[0].stock + quantity_change;
    if (newStock < 0) {
      return res.status(400).json({ success: false, message: 'Stock cannot be negative' });
    }

    await connection.query('UPDATE products SET stock = ? WHERE id = ?', [newStock, product_id]);

    await connection.query(
      `INSERT INTO stock_history (product_id, type, quantity, reference_type, note, created_by) 
       VALUES (?, ?, ?, 'adjustment', ?, ?)`,
      [product_id, type, quantity_change, note || 'Stock adjustment', req.user.id]
    );

    await connection.commit();

    const [product] = await pool.query('SELECT * FROM products WHERE id = ?', [product_id]);
    res.json({ success: true, message: 'Stock adjusted successfully', data: product[0] });
  } catch (err) {
    await connection.rollback();
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  } finally {
    connection.release();
  }
});

module.exports = router;

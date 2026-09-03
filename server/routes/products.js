const express = require('express');
const router = express.Router();
const pool = require('../config/db');
const { authenticate } = require('../middleware/auth');

router.use(authenticate);

router.get('/barcode/:barcode', async (req, res) => {
  try {
    const { barcode } = req.params;
    const [products] = await pool.query(
      `SELECT p.*, c.name as category_name 
       FROM products p 
       LEFT JOIN categories c ON p.category_id = c.id 
       WHERE p.barcode = ?`,
      [barcode]
    );
    if (products.length === 0) {
      return res.status(404).json({ success: false, message: 'Product not found' });
    }
    res.json({ success: true, data: products[0] });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

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

    const [countResult] = await pool.query(
      `SELECT COUNT(*) as total FROM products p ${whereClause}`,
      params
    );
    const total = countResult[0].total;

    const [products] = await pool.query(
      `SELECT p.*, c.name as category_name 
       FROM products p 
       LEFT JOIN categories c ON p.category_id = c.id 
       ${whereClause} 
       ORDER BY p.id DESC 
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

router.get('/:id', async (req, res) => {
  try {
    const [products] = await pool.query(
      `SELECT p.*, c.name as category_name 
       FROM products p 
       LEFT JOIN categories c ON p.category_id = c.id 
       WHERE p.id = ?`,
      [req.params.id]
    );
    if (products.length === 0) {
      return res.status(404).json({ success: false, message: 'Product not found' });
    }
    res.json({ success: true, data: products[0] });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

router.post('/', async (req, res) => {
  try {
    const { name, barcode, category_id, buy_price, sell_price, stock, min_stock, unit, description } = req.body;

    if (!name || !sell_price) {
      return res.status(400).json({ success: false, message: 'Name and sell price are required' });
    }

    if (barcode) {
      const [existing] = await pool.query('SELECT id FROM products WHERE barcode = ?', [barcode]);
      if (existing.length > 0) {
        return res.status(400).json({ success: false, message: 'Product with this barcode already exists' });
      }
    }

    const [result] = await pool.query(
      `INSERT INTO products (name, barcode, category_id, buy_price, sell_price, stock, min_stock, unit, description) 
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [name, barcode || null, category_id || null, buy_price || 0, sell_price, stock || 0, min_stock || 0, unit || 'pc', description || null]
    );

    const [product] = await pool.query('SELECT * FROM products WHERE id = ?', [result.insertId]);
    res.status(201).json({ success: true, message: 'Product created successfully', data: product[0] });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

router.put('/:id', async (req, res) => {
  try {
    const { id } = req.params;
    const { name, barcode, category_id, buy_price, sell_price, stock, min_stock, unit, description } = req.body;

    const [existing] = await pool.query('SELECT id FROM products WHERE id = ?', [id]);
    if (existing.length === 0) {
      return res.status(404).json({ success: false, message: 'Product not found' });
    }

    if (barcode) {
      const [dup] = await pool.query('SELECT id FROM products WHERE barcode = ? AND id != ?', [barcode, id]);
      if (dup.length > 0) {
        return res.status(400).json({ success: false, message: 'Product with this barcode already exists' });
      }
    }

    await pool.query(
      `UPDATE products SET name = ?, barcode = ?, category_id = ?, buy_price = ?, sell_price = ?, 
       stock = ?, min_stock = ?, unit = ?, description = ? WHERE id = ?`,
      [name, barcode || null, category_id || null, buy_price || 0, sell_price, stock || 0, min_stock || 0, unit || 'pc', description || null, id]
    );

    const [product] = await pool.query('SELECT * FROM products WHERE id = ?', [id]);
    res.json({ success: true, message: 'Product updated successfully', data: product[0] });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

router.delete('/:id', async (req, res) => {
  try {
    const { id } = req.params;

    const [existing] = await pool.query('SELECT id FROM products WHERE id = ?', [id]);
    if (existing.length === 0) {
      return res.status(404).json({ success: false, message: 'Product not found' });
    }

    await pool.query('DELETE FROM products WHERE id = ?', [id]);
    res.json({ success: true, message: 'Product deleted successfully' });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

module.exports = router;

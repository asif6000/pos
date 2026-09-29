const express = require('express');
const router = express.Router();
const pool = require('../config/db');
const { authenticate } = require('../middleware/auth');

router.use(authenticate);

router.get('/', async (req, res) => {
  try {
    const { search, page = 1, limit = 20 } = req.query;
    const offset = (parseInt(page) - 1) * parseInt(limit);

    let where = '';
    let params = [];

    if (search) {
      where = 'WHERE name LIKE ? OR phone LIKE ? OR email LIKE ?';
      params = [`%${search}%`, `%${search}%`, `%${search}%`];
    }

    const [countResult] = await pool.query(`SELECT COUNT(*) as total FROM customers ${where}`, params);
    const total = countResult[0].total;

    const [customers] = await pool.query(
      `SELECT * FROM customers ${where} ORDER BY id DESC LIMIT ? OFFSET ?`,
      [...params, parseInt(limit), offset]
    );

    res.json({
      success: true,
      data: customers,
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
    const [customers] = await pool.query('SELECT * FROM customers WHERE id = ?', [req.params.id]);
    if (customers.length === 0) {
      return res.status(404).json({ success: false, message: 'Customer not found' });
    }

    const [purchases] = await pool.query(
      `SELECT s.*, 
        (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id) as item_count
       FROM sales s 
       WHERE s.customer_id = ? 
       ORDER BY s.id DESC`,
      [req.params.id]
    );

    res.json({
      success: true,
      data: {
        ...customers[0],
        purchases
      }
    });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

router.post('/', async (req, res) => {
  try {
    const { name, phone, email, address, due_amount } = req.body;

    if (!name) {
      return res.status(400).json({ success: false, message: 'Customer name is required' });
    }

    const [result] = await pool.query(
      'INSERT INTO customers (name, phone, email, address, due_amount) VALUES (?, ?, ?, ?, ?)',
      [name, phone || null, email || null, address || null, due_amount || 0]
    );

    const [customer] = await pool.query('SELECT * FROM customers WHERE id = ?', [result.insertId]);
    res.status(201).json({ success: true, message: 'Customer created successfully', data: customer[0] });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

router.put('/:id', async (req, res) => {
  try {
    const { id } = req.params;
    const { name, phone, email, address, due_amount } = req.body;

    const [existing] = await pool.query('SELECT id FROM customers WHERE id = ?', [id]);
    if (existing.length === 0) {
      return res.status(404).json({ success: false, message: 'Customer not found' });
    }

    await pool.query(
      'UPDATE customers SET name = ?, phone = ?, email = ?, address = ?, due_amount = ? WHERE id = ?',
      [name, phone || null, email || null, address || null, due_amount || 0, id]
    );

    const [customer] = await pool.query('SELECT * FROM customers WHERE id = ?', [id]);
    res.json({ success: true, message: 'Customer updated successfully', data: customer[0] });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

router.delete('/:id', async (req, res) => {
  try {
    const { id } = req.params;

    const [existing] = await pool.query('SELECT id FROM customers WHERE id = ?', [id]);
    if (existing.length === 0) {
      return res.status(404).json({ success: false, message: 'Customer not found' });
    }

    const [sales] = await pool.query('SELECT COUNT(*) as count FROM sales WHERE customer_id = ?', [id]);
    if (sales[0].count > 0) {
      return res.status(400).json({ success: false, message: 'Cannot delete customer with existing sales' });
    }

    await pool.query('DELETE FROM customers WHERE id = ?', [id]);
    res.json({ success: true, message: 'Customer deleted successfully' });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

module.exports = router;

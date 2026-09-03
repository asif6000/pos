const express = require('express');
const router = express.Router();
const pool = require('../config/db');
const { authenticate } = require('../middleware/auth');

router.use(authenticate);

router.get('/', async (req, res) => {
  try {
    const [stores] = await pool.query('SELECT * FROM stores ORDER BY id ASC');
    res.json({ success: true, data: stores });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

router.post('/', async (req, res) => {
  try {
    const { name, address, phone, email } = req.body;

    if (!name) {
      return res.status(400).json({ success: false, message: 'Store name is required' });
    }

    const [result] = await pool.query(
      'INSERT INTO stores (name, address, phone, email) VALUES (?, ?, ?, ?)',
      [name, address || null, phone || null, email || null]
    );

    const [store] = await pool.query('SELECT * FROM stores WHERE id = ?', [result.insertId]);
    res.status(201).json({ success: true, message: 'Store created successfully', data: store[0] });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

router.put('/:id', async (req, res) => {
  try {
    const { id } = req.params;
    const { name, address, phone, email } = req.body;

    const [existing] = await pool.query('SELECT id FROM stores WHERE id = ?', [id]);
    if (existing.length === 0) {
      return res.status(404).json({ success: false, message: 'Store not found' });
    }

    await pool.query(
      'UPDATE stores SET name = ?, address = ?, phone = ?, email = ? WHERE id = ?',
      [name, address || null, phone || null, email || null, id]
    );

    const [store] = await pool.query('SELECT * FROM stores WHERE id = ?', [id]);
    res.json({ success: true, message: 'Store updated successfully', data: store[0] });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

router.delete('/:id', async (req, res) => {
  try {
    const { id } = req.params;

    const [existing] = await pool.query('SELECT id FROM stores WHERE id = ?', [id]);
    if (existing.length === 0) {
      return res.status(404).json({ success: false, message: 'Store not found' });
    }

    const [transfers] = await pool.query(
      'SELECT COUNT(*) as count FROM transfers WHERE from_store_id = ? OR to_store_id = ?',
      [id, id]
    );
    if (transfers[0].count > 0) {
      return res.status(400).json({ success: false, message: 'Cannot delete store with existing transfers' });
    }

    await pool.query('DELETE FROM store_stocks WHERE store_id = ?', [id]);
    await pool.query('DELETE FROM stores WHERE id = ?', [id]);
    res.json({ success: true, message: 'Store deleted successfully' });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

module.exports = router;

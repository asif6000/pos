const express = require('express');
const router = express.Router();
const bcrypt = require('bcryptjs');
const pool = require('../config/db');
const { authenticate, authorize } = require('../middleware/auth');

router.use(authenticate);
router.use(authorize('admin'));

router.get('/', async (req, res) => {
  try {
    const { search, page = 1, limit = 20 } = req.query;
    const offset = (parseInt(page) - 1) * parseInt(limit);

    let where = '';
    let params = [];

    if (search) {
      where = 'WHERE name LIKE ? OR email LIKE ?';
      params = [`%${search}%`, `%${search}%`];
    }

    const [countResult] = await pool.query(`SELECT COUNT(*) as total FROM users ${where}`, params);
    const total = countResult[0].total;

    const [users] = await pool.query(
      `SELECT id, name, email, role, created_at FROM users ${where} ORDER BY id DESC LIMIT ? OFFSET ?`,
      [...params, parseInt(limit), offset]
    );

    res.json({
      success: true,
      data: users,
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

router.post('/', async (req, res) => {
  try {
    const { name, email, password, role } = req.body;

    if (!name || !email || !password) {
      return res.status(400).json({ success: false, message: 'Name, email, and password are required' });
    }

    const [existing] = await pool.query('SELECT id FROM users WHERE email = ?', [email]);
    if (existing.length > 0) {
      return res.status(400).json({ success: false, message: 'Email already exists' });
    }

    const salt = await bcrypt.genSalt(10);
    const hashedPassword = await bcrypt.hash(password, salt);

    const [result] = await pool.query(
      'INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)',
      [name, email, hashedPassword, role || 'user']
    );

    const [user] = await pool.query(
      'SELECT id, name, email, role, created_at FROM users WHERE id = ?',
      [result.insertId]
    );

    res.status(201).json({ success: true, message: 'User created successfully', data: user[0] });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

router.put('/:id', async (req, res) => {
  try {
    const { id } = req.params;
    const { name, email, password, role } = req.body;

    const [existing] = await pool.query('SELECT id FROM users WHERE id = ?', [id]);
    if (existing.length === 0) {
      return res.status(404).json({ success: false, message: 'User not found' });
    }

    if (email) {
      const [dup] = await pool.query('SELECT id FROM users WHERE email = ? AND id != ?', [email, id]);
      if (dup.length > 0) {
        return res.status(400).json({ success: false, message: 'Email already exists' });
      }
    }

    if (password) {
      const salt = await bcrypt.genSalt(10);
      const hashedPassword = await bcrypt.hash(password, salt);
      await pool.query(
        'UPDATE users SET name = ?, email = ?, password = ?, role = ? WHERE id = ?',
        [name, email, hashedPassword, role || 'user', id]
      );
    } else {
      await pool.query(
        'UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?',
        [name, email, role || 'user', id]
      );
    }

    const [user] = await pool.query(
      'SELECT id, name, email, role, created_at FROM users WHERE id = ?',
      [id]
    );

    res.json({ success: true, message: 'User updated successfully', data: user[0] });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

router.delete('/:id', async (req, res) => {
  try {
    const { id } = req.params;

    if (parseInt(id) === req.user.id) {
      return res.status(400).json({ success: false, message: 'Cannot delete your own account' });
    }

    const [existing] = await pool.query('SELECT id FROM users WHERE id = ?', [id]);
    if (existing.length === 0) {
      return res.status(404).json({ success: false, message: 'User not found' });
    }

    await pool.query('DELETE FROM users WHERE id = ?', [id]);
    res.json({ success: true, message: 'User deleted successfully' });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

module.exports = router;

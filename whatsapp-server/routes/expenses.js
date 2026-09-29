const express = require('express');
const router = express.Router();
const pool = require('../config/db');
const { authenticate } = require('../middleware/auth');

router.use(authenticate);

router.get('/', async (req, res) => {
  try {
    const { start_date, end_date, category, page = 1, limit = 20 } = req.query;
    const offset = (parseInt(page) - 1) * parseInt(limit);

    let where = [];
    let params = [];

    if (start_date) {
      where.push('created_at >= ?');
      params.push(start_date);
    }
    if (end_date) {
      where.push('created_at <= ?');
      params.push(end_date + ' 23:59:59');
    }
    if (category) {
      where.push('category = ?');
      params.push(category);
    }

    const whereClause = where.length > 0 ? 'WHERE ' + where.join(' AND ') : '';

    const [countResult] = await pool.query(
      `SELECT COUNT(*) as total FROM settings WHERE type = 'expense' ${whereClause.replace('AND ', 'AND ')}`,
      params
    );
    const total = countResult[0].total;

    const [expenses] = await pool.query(
      `SELECT * FROM settings WHERE type = 'expense' ${whereClause.replace('AND ', 'AND ')} ORDER BY id DESC LIMIT ? OFFSET ?`,
      [...params, parseInt(limit), offset]
    );

    const parsedExpenses = expenses.map(e => ({
      ...e,
      value: e.value ? JSON.parse(e.value) : null
    }));

    res.json({
      success: true,
      data: parsedExpenses,
      pagination: {
        total,
        page: parseInt(page),
        limit: parseInt(limit),
        totalPages: Math.ceil(total / parseInt(limit))
      }
    });
  } catch (err) {
    try {
      const { start_date, end_date, page = 1, limit = 20 } = req.query;
      const offset = (parseInt(page) - 1) * parseInt(limit);

      let where = [];
      let params = [];

      if (start_date) {
        where.push('created_at >= ?');
        params.push(start_date);
      }
      if (end_date) {
        where.push('created_at <= ?');
        params.push(end_date + ' 23:59:59');
      }

      const whereClause = where.length > 0 ? 'WHERE ' + where.join(' AND ') : '';

      const [countResult] = await pool.query(
        `SELECT COUNT(*) as total FROM cashbook ${whereClause}`,
        params
      );
      const total = countResult[0].total;

      const [expenses] = await pool.query(
        `SELECT * FROM cashbook ${whereClause} ORDER BY id DESC LIMIT ? OFFSET ?`,
        [...params, parseInt(limit), offset]
      );

      res.json({
        success: true,
        data: expenses,
        pagination: {
          total,
          page: parseInt(page),
          limit: parseInt(limit),
          totalPages: Math.ceil(total / parseInt(limit))
        }
      });
    } catch (err2) {
      res.status(500).json({ success: false, message: 'Server error', error: err.message });
    }
  }
});

router.post('/', async (req, res) => {
  try {
    const { title, amount, category, description, date } = req.body;

    if (!title || amount === undefined) {
      return res.status(400).json({ success: false, message: 'Title and amount are required' });
    }

    const [result] = await pool.query(
      'INSERT INTO settings (`key`, `value`, `type`) VALUES (?, ?, ?)',
      [`expense_${Date.now()}`, JSON.stringify({ title, amount, category: category || 'general', description: description || null, date: date || new Date().toISOString().split('T')[0] }), 'expense']
    );

    res.status(201).json({
      success: true,
      message: 'Expense created successfully',
      data: { id: result.insertId, title, amount, category: category || 'general', description, date }
    });
  } catch (err) {
    try {
      const { title, amount, category, description, date } = req.body;

      if (!title || amount === undefined) {
        return res.status(400).json({ success: false, message: 'Title and amount are required' });
      }

      const [result] = await pool.query(
        'INSERT INTO cashbook (title, amount, category, description, date, created_by) VALUES (?, ?, ?, ?, ?, ?)',
        [title, amount, category || 'general', description || null, date || new Date().toISOString().split('T')[0], req.user.id]
      );

      res.status(201).json({
        success: true,
        message: 'Expense created successfully',
        data: { id: result.insertId, title, amount, category: category || 'general', description, date }
      });
    } catch (err2) {
      res.status(500).json({ success: false, message: 'Server error', error: err.message });
    }
  }
});

router.delete('/:id', async (req, res) => {
  try {
    const { id } = req.params;

    const [existing] = await pool.query('SELECT * FROM settings WHERE id = ? AND type = ?', [id, 'expense']);
    if (existing.length === 0) {
      return res.status(404).json({ success: false, message: 'Expense not found' });
    }

    await pool.query('DELETE FROM settings WHERE id = ? AND type = ?', [id, 'expense']);
    res.json({ success: true, message: 'Expense deleted successfully' });
  } catch (err) {
    try {
      const { id } = req.params;

      const [existing] = await pool.query('SELECT * FROM cashbook WHERE id = ?', [id]);
      if (existing.length === 0) {
        return res.status(404).json({ success: false, message: 'Expense not found' });
      }

      await pool.query('DELETE FROM cashbook WHERE id = ?', [id]);
      res.json({ success: true, message: 'Expense deleted successfully' });
    } catch (err2) {
      res.status(500).json({ success: false, message: 'Server error', error: err.message });
    }
  }
});

module.exports = router;

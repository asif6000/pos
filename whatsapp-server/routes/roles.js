const express = require('express');
const router = express.Router();
const pool = require('../config/db');
const { authenticate, authorize } = require('../middleware/auth');

router.use(authenticate);

router.get('/', async (req, res) => {
  try {
    const [roles] = await pool.query('SELECT * FROM roles ORDER BY id ASC');
    res.json({ success: true, data: roles });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

router.post('/', authorize('admin'), async (req, res) => {
  try {
    const { name, description } = req.body;

    if (!name) {
      return res.status(400).json({ success: false, message: 'Role name is required' });
    }

    const [existing] = await pool.query('SELECT id FROM roles WHERE name = ?', [name]);
    if (existing.length > 0) {
      return res.status(400).json({ success: false, message: 'Role with this name already exists' });
    }

    const [result] = await pool.query(
      'INSERT INTO roles (name, description) VALUES (?, ?)',
      [name, description || null]
    );

    const [role] = await pool.query('SELECT * FROM roles WHERE id = ?', [result.insertId]);
    res.status(201).json({ success: true, message: 'Role created successfully', data: role[0] });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

router.put('/:id', authorize('admin'), async (req, res) => {
  try {
    const { id } = req.params;
    const { name, description } = req.body;

    const [existing] = await pool.query('SELECT id FROM roles WHERE id = ?', [id]);
    if (existing.length === 0) {
      return res.status(404).json({ success: false, message: 'Role not found' });
    }

    await pool.query(
      'UPDATE roles SET name = ?, description = ? WHERE id = ?',
      [name, description || null, id]
    );

    const [role] = await pool.query('SELECT * FROM roles WHERE id = ?', [id]);
    res.json({ success: true, message: 'Role updated successfully', data: role[0] });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

router.delete('/:id', authorize('admin'), async (req, res) => {
  try {
    const { id } = req.params;

    const [existing] = await pool.query('SELECT id FROM roles WHERE id = ?', [id]);
    if (existing.length === 0) {
      return res.status(404).json({ success: false, message: 'Role not found' });
    }

    const [users] = await pool.query('SELECT COUNT(*) as count FROM users WHERE role = ?', [existing[0]?.name || '']);
    if (users[0].count > 0) {
      return res.status(400).json({ success: false, message: 'Cannot delete role with assigned users' });
    }

    await pool.query('DELETE FROM role_permissions WHERE role_id = ?', [id]);
    await pool.query('DELETE FROM roles WHERE id = ?', [id]);
    res.json({ success: true, message: 'Role deleted successfully' });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

router.get('/:id/permissions', async (req, res) => {
  try {
    const { id } = req.params;

    const [role] = await pool.query('SELECT * FROM roles WHERE id = ?', [id]);
    if (role.length === 0) {
      return res.status(404).json({ success: false, message: 'Role not found' });
    }

    const [permissions] = await pool.query(
      'SELECT * FROM role_permissions WHERE role_id = ?',
      [id]
    );

    res.json({
      success: true,
      data: {
        role: role[0],
        permissions
      }
    });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

router.put('/:id/permissions', authorize('admin'), async (req, res) => {
  try {
    const { id } = req.params;
    const { permissions } = req.body;

    const [role] = await pool.query('SELECT * FROM roles WHERE id = ?', [id]);
    if (role.length === 0) {
      return res.status(404).json({ success: false, message: 'Role not found' });
    }

    if (!permissions || !Array.isArray(permissions)) {
      return res.status(400).json({ success: false, message: 'Permissions array is required' });
    }

    await pool.query('DELETE FROM role_permissions WHERE role_id = ?', [id]);

    for (const permission of permissions) {
      await pool.query(
        'INSERT INTO role_permissions (role_id, permission) VALUES (?, ?)',
        [id, permission]
      );
    }

    const [updatedPermissions] = await pool.query(
      'SELECT * FROM role_permissions WHERE role_id = ?',
      [id]
    );

    res.json({
      success: true,
      message: 'Permissions updated successfully',
      data: {
        role: role[0],
        permissions: updatedPermissions
      }
    });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

module.exports = router;

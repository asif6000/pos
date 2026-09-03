const express = require('express');
const router = express.Router();
const pool = require('../config/db');
const { authenticate } = require('../middleware/auth');

router.use(authenticate);

router.get('/', async (req, res) => {
  try {
    const [settings] = await pool.query('SELECT * FROM settings ORDER BY id ASC');
    res.json({ success: true, data: settings });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

router.put('/', async (req, res) => {
  try {
    const { settings } = req.body;

    if (!settings || !Array.isArray(settings)) {
      return res.status(400).json({ success: false, message: 'Settings array is required' });
    }

    for (const setting of settings) {
      if (setting.key !== undefined && setting.value !== undefined) {
        await pool.query(
          'INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?',
          [setting.key, setting.value, setting.value]
        );
      }
    }

    const [updatedSettings] = await pool.query('SELECT * FROM settings ORDER BY id ASC');
    res.json({ success: true, message: 'Settings updated successfully', data: updatedSettings });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

module.exports = router;

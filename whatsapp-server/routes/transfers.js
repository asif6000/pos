const express = require('express');
const router = express.Router();
const pool = require('../config/db');
const { authenticate } = require('../middleware/auth');

router.use(authenticate);

router.get('/', async (req, res) => {
  try {
    const { status, page = 1, limit = 20 } = req.query;
    const offset = (parseInt(page) - 1) * parseInt(limit);

    let where = [];
    let params = [];

    if (status) {
      where.push('t.status = ?');
      params.push(status);
    }

    const whereClause = where.length > 0 ? 'WHERE ' + where.join(' AND ') : '';

    const [countResult] = await pool.query(`SELECT COUNT(*) as total FROM transfers t ${whereClause}`, params);
    const total = countResult[0].total;

    const [transfers] = await pool.query(
      `SELECT t.*, 
        fs.name as from_store_name, 
        ts.name as to_store_name,
        u.name as created_by_name
       FROM transfers t 
       LEFT JOIN stores fs ON t.from_store_id = fs.id 
       LEFT JOIN stores ts ON t.to_store_id = ts.id 
       LEFT JOIN users u ON t.created_by = u.id 
       ${whereClause} 
       ORDER BY t.id DESC 
       LIMIT ? OFFSET ?`,
      [...params, parseInt(limit), offset]
    );

    for (const transfer of transfers) {
      const [items] = await pool.query(
        `SELECT ti.*, p.name as product_name, p.barcode 
         FROM transfer_items ti 
         LEFT JOIN products p ON ti.product_id = p.id 
         WHERE ti.transfer_id = ?`,
        [transfer.id]
      );
      transfer.items = items;
    }

    res.json({
      success: true,
      data: transfers,
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
  const connection = await pool.getConnection();
  try {
    await connection.beginTransaction();

    const { from_store_id, to_store_id, items } = req.body;

    if (!from_store_id || !to_store_id || !items || items.length === 0) {
      return res.status(400).json({ success: false, message: 'from_store_id, to_store_id, and items are required' });
    }

    if (from_store_id === to_store_id) {
      return res.status(400).json({ success: false, message: 'Source and destination stores must be different' });
    }

    const [fromStore] = await connection.query('SELECT id FROM stores WHERE id = ?', [from_store_id]);
    const [toStore] = await connection.query('SELECT id FROM stores WHERE id = ?', [to_store_id]);

    if (fromStore.length === 0 || toStore.length === 0) {
      return res.status(404).json({ success: false, message: 'Store not found' });
    }

    const [result] = await connection.query(
      'INSERT INTO transfers (from_store_id, to_store_id, status, created_by) VALUES (?, ?, ?, ?)',
      [from_store_id, to_store_id, 'pending', req.user.id]
    );

    const transferId = result.insertId;

    for (const item of items) {
      await connection.query(
        'INSERT INTO transfer_items (transfer_id, product_id, quantity) VALUES (?, ?, ?)',
        [transferId, item.product_id, item.quantity]
      );
    }

    await connection.commit();

    const [transfer] = await pool.query(
      `SELECT t.*, fs.name as from_store_name, ts.name as to_store_name 
       FROM transfers t 
       LEFT JOIN stores fs ON t.from_store_id = fs.id 
       LEFT JOIN stores ts ON t.to_store_id = ts.id 
       WHERE t.id = ?`,
      [transferId]
    );
    const [transferItems] = await pool.query(
      `SELECT ti.*, p.name as product_name 
       FROM transfer_items ti 
       LEFT JOIN products p ON ti.product_id = p.id 
       WHERE ti.transfer_id = ?`,
      [transferId]
    );

    res.status(201).json({
      success: true,
      message: 'Transfer created successfully',
      data: { ...transfer[0], items: transferItems }
    });
  } catch (err) {
    await connection.rollback();
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  } finally {
    connection.release();
  }
});

router.put('/:id/complete', async (req, res) => {
  const connection = await pool.getConnection();
  try {
    await connection.beginTransaction();

    const { id } = req.params;

    const [transfers] = await connection.query(
      'SELECT * FROM transfers WHERE id = ?',
      [id]
    );
    if (transfers.length === 0) {
      return res.status(404).json({ success: false, message: 'Transfer not found' });
    }

    if (transfers[0].status !== 'pending') {
      return res.status(400).json({ success: false, message: 'Only pending transfers can be completed' });
    }

    const [items] = await connection.query(
      'SELECT * FROM transfer_items WHERE transfer_id = ?',
      [id]
    );

    for (const item of items) {
      const [fromStock] = await connection.query(
        'SELECT * FROM store_stocks WHERE store_id = ? AND product_id = ?',
        [transfers[0].from_store_id, item.product_id]
      );

      if (fromStock.length === 0 || fromStock[0].quantity < item.quantity) {
        await connection.rollback();
        return res.status(400).json({
          success: false,
          message: `Insufficient stock for product ID ${item.product_id} in source store`
        });
      }

      await connection.query(
        'UPDATE store_stocks SET quantity = quantity - ? WHERE store_id = ? AND product_id = ?',
        [item.quantity, transfers[0].from_store_id, item.product_id]
      );

      const [toStock] = await connection.query(
        'SELECT * FROM store_stocks WHERE store_id = ? AND product_id = ?',
        [transfers[0].to_store_id, item.product_id]
      );

      if (toStock.length > 0) {
        await connection.query(
          'UPDATE store_stocks SET quantity = quantity + ? WHERE store_id = ? AND product_id = ?',
          [item.quantity, transfers[0].to_store_id, item.product_id]
        );
      } else {
        await connection.query(
          'INSERT INTO store_stocks (store_id, product_id, quantity) VALUES (?, ?, ?)',
          [transfers[0].to_store_id, item.product_id, item.quantity]
        );
      }

      await connection.query(
        `INSERT INTO stock_history (product_id, type, quantity, reference_type, reference_id, note, created_by) 
         VALUES (?, 'transfer', ?, 'transfer', ?, ?, ?)`,
        [item.product_id, -item.quantity, id, `Transfer #${id} from store ${transfers[0].from_store_id} to store ${transfers[0].to_store_id}`, req.user.id]
      );
    }

    await connection.query('UPDATE transfers SET status = ?, completed_at = NOW() WHERE id = ?', ['completed', id]);

    await connection.commit();

    const [transfer] = await pool.query(
      `SELECT t.*, fs.name as from_store_name, ts.name as to_store_name 
       FROM transfers t 
       LEFT JOIN stores fs ON t.from_store_id = fs.id 
       LEFT JOIN stores ts ON t.to_store_id = ts.id 
       WHERE t.id = ?`,
      [id]
    );

    res.json({ success: true, message: 'Transfer completed successfully', data: transfer[0] });
  } catch (err) {
    await connection.rollback();
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  } finally {
    connection.release();
  }
});

router.put('/:id/cancel', async (req, res) => {
  try {
    const { id } = req.params;

    const [transfers] = await pool.query('SELECT * FROM transfers WHERE id = ?', [id]);
    if (transfers.length === 0) {
      return res.status(404).json({ success: false, message: 'Transfer not found' });
    }

    if (transfers[0].status !== 'pending') {
      return res.status(400).json({ success: false, message: 'Only pending transfers can be cancelled' });
    }

    await pool.query('UPDATE transfers SET status = ? WHERE id = ?', ['cancelled', id]);

    const [transfer] = await pool.query('SELECT * FROM transfers WHERE id = ?', [id]);
    res.json({ success: true, message: 'Transfer cancelled successfully', data: transfer[0] });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

module.exports = router;

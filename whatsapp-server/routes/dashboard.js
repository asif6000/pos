const express = require('express');
const router = express.Router();
const pool = require('../config/db');
const { authenticate } = require('../middleware/auth');

router.use(authenticate);

router.get('/', async (req, res) => {
  try {
    const today = new Date().toISOString().split('T')[0];
    const firstDayOfMonth = new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0];

    const [todaySales] = await pool.query(
      'SELECT COALESCE(SUM(total_amount), 0) as total, COUNT(*) as count FROM sales WHERE DATE(sale_date) = ?',
      [today]
    );

    const [monthlySales] = await pool.query(
      'SELECT COALESCE(SUM(total_amount), 0) as total, COUNT(*) as count FROM sales WHERE sale_date >= ?',
      [firstDayOfMonth]
    );

    const [totalProducts] = await pool.query('SELECT COUNT(*) as count FROM products');

    const [totalCustomers] = await pool.query('SELECT COUNT(*) as count FROM customers');

    const [lowStock] = await pool.query(
      'SELECT COUNT(*) as count FROM products WHERE stock <= min_stock AND min_stock > 0'
    );

    const [recentSales] = await pool.query(
      `SELECT s.*, c.name as customer_name 
       FROM sales s 
       LEFT JOIN customers c ON s.customer_id = c.id 
       ORDER BY s.id DESC LIMIT 5`
    );

    const [topProducts] = await pool.query(
      `SELECT p.name, SUM(si.quantity) as total_sold, SUM(si.total) as total_revenue 
       FROM sale_items si 
       JOIN products p ON si.product_id = p.id 
       JOIN sales s ON si.sale_id = s.id 
       WHERE s.sale_date >= ? 
       GROUP BY si.product_id, p.name 
       ORDER BY total_sold DESC 
       LIMIT 5`,
      [firstDayOfMonth]
    );

    const [totalDue] = await pool.query(
      'SELECT COALESCE(SUM(due_amount), 0) as total FROM customers WHERE due_amount > 0'
    );

    res.json({
      success: true,
      data: {
        todaySales: {
          total: todaySales[0].total,
          count: todaySales[0].count
        },
        monthlySales: {
          total: monthlySales[0].total,
          count: monthlySales[0].count
        },
        totalProducts: totalProducts[0].count,
        totalCustomers: totalCustomers[0].count,
        lowStockCount: lowStock[0].count,
        totalDue: totalDue[0].total,
        recentSales,
        topProducts
      }
    });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

module.exports = router;

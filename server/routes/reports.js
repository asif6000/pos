const express = require('express');
const router = express.Router();
const pool = require('../config/db');
const { authenticate } = require('../middleware/auth');

router.use(authenticate);

router.get('/daily', async (req, res) => {
  try {
    const { date } = req.query;
    const reportDate = date || new Date().toISOString().split('T')[0];

    const [sales] = await pool.query(
      `SELECT s.*, c.name as customer_name 
       FROM sales s 
       LEFT JOIN customers c ON s.customer_id = c.id 
       WHERE DATE(s.sale_date) = ? 
       ORDER BY s.id DESC`,
      [reportDate]
    );

    const [summary] = await pool.query(
      `SELECT 
        COUNT(*) as total_sales,
        COALESCE(SUM(total_amount), 0) as total_revenue,
        COALESCE(SUM(paid_amount), 0) as total_collected,
        COALESCE(SUM(due_amount), 0) as total_due,
        COALESCE(SUM(discount), 0) as total_discount,
        COALESCE(SUM(vat), 0) as total_vat
       FROM sales WHERE DATE(sale_date) = ?`,
      [reportDate]
    );

    res.json({
      success: true,
      data: {
        date: reportDate,
        summary: summary[0],
        sales
      }
    });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

router.get('/monthly', async (req, res) => {
  try {
    const { year, month } = req.query;
    const reportYear = year || new Date().getFullYear();
    const reportMonth = month || new Date().getMonth() + 1;
    const startDate = `${reportYear}-${String(reportMonth).padStart(2, '0')}-01`;
    const endDate = new Date(reportYear, reportMonth, 0).toISOString().split('T')[0];

    const [dailySummary] = await pool.query(
      `SELECT 
        DATE(sale_date) as date,
        COUNT(*) as total_sales,
        COALESCE(SUM(total_amount), 0) as total_revenue,
        COALESCE(SUM(paid_amount), 0) as total_collected,
        COALESCE(SUM(due_amount), 0) as total_due
       FROM sales 
       WHERE sale_date >= ? AND sale_date <= ? 
       GROUP BY DATE(sale_date) 
       ORDER BY date`,
      [startDate, endDate + ' 23:59:59']
    );

    const [totalSummary] = await pool.query(
      `SELECT 
        COUNT(*) as total_sales,
        COALESCE(SUM(total_amount), 0) as total_revenue,
        COALESCE(SUM(paid_amount), 0) as total_collected,
        COALESCE(SUM(due_amount), 0) as total_due,
        COALESCE(SUM(discount), 0) as total_discount,
        COALESCE(SUM(vat), 0) as total_vat
       FROM sales 
       WHERE sale_date >= ? AND sale_date <= ?`,
      [startDate, endDate + ' 23:59:59']
    );

    res.json({
      success: true,
      data: {
        year: parseInt(reportYear),
        month: parseInt(reportMonth),
        startDate,
        endDate,
        summary: totalSummary[0],
        dailyBreakdown: dailySummary
      }
    });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

router.get('/top-products', async (req, res) => {
  try {
    const { start_date, end_date, limit = 10 } = req.query;

    let where = [];
    let params = [];

    if (start_date) {
      where.push('s.sale_date >= ?');
      params.push(start_date);
    }
    if (end_date) {
      where.push('s.sale_date <= ?');
      params.push(end_date + ' 23:59:59');
    }

    const whereClause = where.length > 0 ? 'WHERE ' + where.join(' AND ') : '';

    const [products] = await pool.query(
      `SELECT p.id, p.name, p.barcode, 
        SUM(si.quantity) as total_sold, 
        SUM(si.total) as total_revenue,
        COUNT(DISTINCT si.sale_id) as sales_count
       FROM sale_items si 
       JOIN products p ON si.product_id = p.id 
       JOIN sales s ON si.sale_id = s.id 
       ${whereClause}
       GROUP BY si.product_id, p.name, p.barcode 
       ORDER BY total_sold DESC 
       LIMIT ?`,
      [...params, parseInt(limit)]
    );

    res.json({ success: true, data: products });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

router.get('/payment-methods', async (req, res) => {
  try {
    const { start_date, end_date } = req.query;

    let where = [];
    let params = [];

    if (start_date) {
      where.push('sale_date >= ?');
      params.push(start_date);
    }
    if (end_date) {
      where.push('sale_date <= ?');
      params.push(end_date + ' 23:59:59');
    }

    const whereClause = where.length > 0 ? 'WHERE ' + where.join(' AND ') : '';

    const [methods] = await pool.query(
      `SELECT 
        payment_method,
        COUNT(*) as total_sales,
        COALESCE(SUM(total_amount), 0) as total_amount,
        COALESCE(SUM(paid_amount), 0) as total_collected
       FROM sales 
       ${whereClause}
       GROUP BY payment_method 
       ORDER BY total_amount DESC`,
      params
    );

    res.json({ success: true, data: methods });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

router.get('/category-wise', async (req, res) => {
  try {
    const { start_date, end_date } = req.query;

    let where = [];
    let params = [];

    if (start_date) {
      where.push('s.sale_date >= ?');
      params.push(start_date);
    }
    if (end_date) {
      where.push('s.sale_date <= ?');
      params.push(end_date + ' 23:59:59');
    }

    const whereClause = where.length > 0 ? 'WHERE ' + where.join(' AND ') : '';

    const [categories] = await pool.query(
      `SELECT 
        c.id, c.name,
        COALESCE(SUM(si.quantity), 0) as total_sold,
        COALESCE(SUM(si.total), 0) as total_revenue,
        COUNT(DISTINCT si.sale_id) as sales_count
       FROM sale_items si 
       JOIN products p ON si.product_id = p.id 
       LEFT JOIN categories c ON p.category_id = c.id 
       JOIN sales s ON si.sale_id = s.id 
       ${whereClause}
       GROUP BY c.id, c.name 
       ORDER BY total_revenue DESC`,
      params
    );

    res.json({ success: true, data: categories });
  } catch (err) {
    res.status(500).json({ success: false, message: 'Server error', error: err.message });
  }
});

module.exports = router;

<?php
/**
 * The dashboard KPI cards an admin can grant one account at a time.
 *
 * Keys are stored in `user_kpi_permissions.kpi_key` and checked by
 * hasKpiPermission(). Only counter accounts (staff / cashier) are limited by
 * them - see kpiGatedRoles() in config/db.php - so the owner's and manager's
 * dashboard is unchanged.
 *
 * The label is deliberately the same words as the card on admin/dashboard.php,
 * because the admin ticks a box here and then looks for the same card there.
 */
function dashboardKpiDefs()
{
    return array(
        'kpi_today_sale'      => array('label' => "Today's Sale",      'icon' => 'fa-money-check-alt'),
        'kpi_exchange'        => array('label' => 'Exchange Extra',    'icon' => 'fa-arrow-down'),
        'kpi_refund'          => array('label' => 'Refund',            'icon' => 'fa-arrow-up'),
        'kpi_monthly_sale'    => array('label' => 'This Month',        'icon' => 'fa-calendar-days'),
        'kpi_yearly_sale'     => array('label' => 'This Year',         'icon' => 'fa-chart-column'),
        'kpi_bkash_month'     => array('label' => 'bKash (This Month)', 'icon' => 'fa-mobile-alt'),
        'kpi_total_products'  => array('label' => 'Total Products',    'icon' => 'fa-box-open'),
        'kpi_top_product'     => array('label' => 'Top Product (Month)', 'icon' => 'fa-trophy'),
        'kpi_total_customers' => array('label' => 'Total Customers',   'icon' => 'fa-users'),
        'kpi_total_invoices'  => array('label' => 'Total Transactions', 'icon' => 'fa-receipt'),
        'kpi_sms_sent'        => array('label' => 'SMS Sent',          'icon' => 'fa-comment-sms'),
        'kpi_online_orders'   => array('label' => 'Online Orders',     'icon' => 'fa-basket-shopping'),
        'kpi_total_expense'   => array('label' => 'Expenses',          'icon' => 'fa-arrow-up'),
        'kpi_net_balance'     => array('label' => 'Net Balance',       'icon' => 'fa-scale-balanced'),
        'kpi_cash_balance'    => array('label' => 'Cash Balance',      'icon' => 'fa-hand-holding-usd'),
        'kpi_commission'      => array('label' => 'Commission',        'icon' => 'fa-coins'),
        'kpi_staff_members'   => array('label' => 'Staff Members',     'icon' => 'fa-user-tie'),
        'kpi_salary_balance'  => array('label' => 'Salary Balance',    'icon' => 'fa-wallet'),
        'kpi_low_stock'       => array('label' => 'Low Stock Alerts',  'icon' => 'fa-exclamation-triangle'),
    );
}

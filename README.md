# POS System - Point of Sale for Bangladesh SMBs

A clean, fast, and professional Point of Sale (POS) system built for small and medium businesses in Bangladesh.

## 🚀 Features

### Authentication
- ✅ Login/Register system
- ✅ Role-based access (Admin, Cashier)
- ✅ Secure session management
- ✅ Password hashing

### Dashboard
- ✅ Today's & Monthly sales overview
- ✅ Total products & customers count
- ✅ Low stock alerts
- ✅ Recent sales list
- ✅ Top selling products

### Product Management
- ✅ Add/Edit/Delete products
- ✅ Barcode support
- ✅ Category management
- ✅ Search & filter
- ✅ Stock tracking

### Customer Management
- ✅ Add/Edit/Delete customers
- ✅ Purchase history
- ✅ Customer search

### POS / Billing
- ✅ Product search by name/barcode
- ✅ Category filtering
- ✅ Shopping cart with quantity control
- ✅ Auto total calculation
- ✅ Discount & VAT support
- ✅ Multiple payment methods (Cash, bKash, Nagad, Rocket, Card, Bank)
- ✅ Invoice generation
- ✅ Print invoice

### Sales Management
- ✅ Sales list with filters
- ✅ View invoice details
- ✅ Date range filtering
- ✅ Payment method filter

### Stock Management
- ✅ Stock overview
- ✅ Low stock alerts
- ✅ Stock adjustment
- ✅ Stock history tracking

### Reports
- ✅ Daily sales report
- ✅ Monthly sales report
- ✅ Top selling products
- ✅ Payment method breakdown
- ✅ Category-wise sales

### Settings
- ✅ Shop information
- ✅ Currency configuration (BDT)
- ✅ VAT percentage
- ✅ Receipt customization

## 🛠️ Tech Stack

- **Frontend:** HTML5, CSS3 (Flexbox/Grid), Vanilla JavaScript
- **Backend:** PHP 7.4+ with PDO
- **Database:** MySQL 5.7+
- **Security:** Password hashing, Prepared statements, Session management

## 📂 Project Structure

```
├── /admin                    # Admin panel
│   ├── /api                  # AJAX API endpoints
│   │   ├── process-sale.php
│   │   ├── get-invoice.php
│   │   ├── stock-history.php
│   │   ├── process-transfer.php
│   │   └── ...
│   ├── /includes
│   │   ├── header.php
│   │   └── footer.php
│   ├── dashboard.php
│   ├── pos.php
│   ├── products.php
│   ├── categories.php
│   ├── customers.php
│   ├── customer-history.php
│   ├── sales.php
│   ├── returns.php
│   ├── stock.php
│   ├── transfers.php
│   ├── cashbook.php
│   ├── expense.php
│   ├── reports.php
│   ├── discount-report.php
│   ├── users.php
│   ├── staff.php
│   ├── roles.php
│   ├── stores.php
│   ├── plans.php
│   ├── pricing.php
│   ├── subscription.php
│   ├── settings.php
│   ├── payment-settings.php
│   ├── barcode-settings.php
│   ├── voucher-settings.php
│   ├── vouchers.php
│   ├── print-labels.php
│   └── login.php
├── /cashier                  # Cashier panel
│   ├── /includes
│   ├── pos.php
│   ├── sales.php
│   ├── customers.php
│   └── products.php
├── /staff                    # Staff panel
│   ├── /includes
│   └── dashboard.php
├── /auth                     # Authentication
│   ├── login.php
│   ├── register.php
│   └── checkout.php
├── /config                   # Configuration
│   ├── db.php                # Database connection & helpers
│   ├── database.sql          # Main database schema
│   └── migration_*.sql       # Migration scripts
├── /assets
│   ├── /css
│   │   └── style.css
│   ├── /js
│   │   └── app.js
│   ├── /fonts
│   └── /img
├── index.php                 # Entry point
├── landing.php               # Landing page
├── logout.php
└── schema.sql
```

## 🚀 Installation

### Requirements
- PHP 7.4 or higher
- MySQL 5.7 or higher
- Apache/Nginx web server (with `mod_rewrite` enabled)
- Shared hosting also supported

### Local Setup (XAMPP / WAMP / Laragon)

1. **Clone or Download**
   ```bash
   git clone https://github.com/asif6000/pos.git
   ```
   Or download the ZIP and extract into your server root (`htdocs`, `www`, etc.)

2. **Create Database**
   - Open phpMyAdmin (`http://localhost/phpmyadmin`)
   - Create a new database, e.g. `pos_system`
   - Select the database → click **Import** → choose `config/database.sql` → click **Go**

3. **Configure Database Connection**
   Edit `config/db.php` and update:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'pos_system');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   ```

4. **Apply Migrations**
   In phpMyAdmin, run the following SQL files **in order**:
   - `config/migration_admin.sql`
   - `config/migration_superadmin.sql`
   - `config/migration_permissions.sql`
   - `config/migration_returns.sql`

5. **Set Folder Permissions**
   - Make sure the `sessions/` folder is writable by the web server
   - Create an `uploads/` folder if you plan to upload product images

6. **Access the System**
   - Local: `http://localhost/smart/`
   - Online: `https://yourdomain.com/`

7. **Default Login**
   | Role  | Email            | Password  |
   |-------|------------------|-----------|
   | Admin | `admin@pos.com`  | `admin123`|

### Live Server (cPanel / Shared Hosting)

1. Upload all files to `public_html/` or a subdirectory via **File Manager** or **FTP**
2. Create a MySQL database and user from **cPanel → MySQL Databases**
3. Import `config/database.sql` via **phpMyAdmin**
4. Edit `config/db.php` with your live database credentials
5. Run the migration SQL files (same as step 4 above)
6. Ensure `sessions/` is writable (`chmod 755`)
7. Visit your domain to access the system

### Post-Installation

- Go to **Admin → Settings** to configure shop name, address, and currency
- Go to **Admin → Plans** to set up subscription plans (SaaS mode)
- Create additional admin/cashier users from **Admin → Users**
- Set up roles and permissions from **Admin → Roles**

## ❓ Troubleshooting

| Problem | Solution |
|---------|----------|
| Blank page / 500 error | Enable error reporting in `php.ini`: `display_errors = On` |
| Database connection failed | Check `config/db.php` credentials |
| Styles not loading | Make sure `assets/` folder is accessible via browser |
| Session errors | Ensure `sessions/` folder exists and is writable |
| Migration errors | Run migration SQL files in the correct order |

## 🔐 Security Features

- **PDO with Prepared Statements** - Prevents SQL injection
- **Password Hashing** - Uses PHP's `password_hash()`
- **Input Sanitization** - All inputs are sanitized
- **Session Security** - HTTP-only cookies, secure sessions
- **Role-Based Access** - Admin and Cashier roles

## 💰 Currency

The system is configured for **Bangladeshi Taka (BDT)** by default.
You can change currency settings in **Settings > System Settings**.

## 📱 Mobile Friendly

The system is fully responsive and works on:
- Desktop computers
- Tablets
- Mobile phones

## 🎨 Design

- Clean, professional light theme
- Modern UI with professional fonts
- Fast loading, no unnecessary animations
- Sidebar navigation
- Mobile-friendly responsive design

## 📝 License

This project is free to use for personal and commercial purposes.

## 🆘 Support

For any issues or questions, please check the code comments or create an issue.

---

**Made with ❤️ for Bangladesh SMBs**

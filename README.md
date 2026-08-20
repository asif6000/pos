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
/pos-system
├── /assets
│   ├── /css
│   │   └── style.css
│   ├── /js
│   │   └── app.js
│   └── /images
├── /config
│   ├── db.php
│   └── database.sql
├── /auth
│   ├── login.php
│   └── register.php
├── /admin
│   ├── /api
│   │   ├── process-sale.php
│   │   ├── get-invoice.php
│   │   └── stock-history.php
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
│   ├── stock.php
│   ├── reports.php
│   ├── users.php
│   └── settings.php
├── /cashier
│   ├── /includes
│   │   ├── header.php
│   │   └── footer.php
│   ├── pos.php
│   ├── sales.php
│   ├── customers.php
│   └── products.php
├── index.php
├── logout.php
└── README.md
```

## 🚀 Installation

### Requirements
- PHP 7.4 or higher
- MySQL 5.7 or higher
- Apache/Nginx web server
- Shared hosting supported

### Steps

1. **Upload Files**
   Upload all files to your web hosting or local server (e.g., `htdocs`, `www`, or `public_html`)

2. **Create Database**
   - Create a new MySQL database (e.g., `pos_system`)
   - Import the SQL file: `config/database.sql`

3. **Configure Database Connection**
   Edit `config/db.php` and update these values:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'pos_system');
   define('DB_USER', 'your_username');
   define('DB_PASS', 'your_password');
   ```

4. **Access the System**
   Open your browser and navigate to:
   - Local: `http://localhost/pos/`
   - Online: `https://yourdomain.com/pos/`

5. **Login**
   - **Default Admin Account:**
     - Email: `admin@pos.com`
     - Password: `admin123`

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

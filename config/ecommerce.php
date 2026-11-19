<?php
/**
 * The online shop - the customer-facing half of the POS.
 *
 * WHAT THIS FILE IS FOR
 * ---------------------
 * A shopkeeper's catalogue, prices and stock already live in this database. An
 * e-commerce front end must not keep a second copy of them, because the second
 * copy is the one that goes stale: the counter sells the last piece and the app
 * keeps taking orders for it. So the storefront reads the POS tables directly,
 * and an order taken online is written into the POS tables - it is not a parallel
 * system with an export step.
 *
 * THE TWO HANDSHAKES
 * ------------------
 * POS -> shop:  a product is not online just because it exists. ecom_listings
 *               holds one row per (owner, product) with 'visible', and the store
 *               whose counter fulfils the order. Ticking that row is the "sync to
 *               e-commerce" the shop asked for; unticking hides it again without
 *               touching the product.
 * Shop -> POS:  an order arrives as 'pending' with its own number. Nothing is
 *               deducted yet, because an abandoned or wrong-number order must not
 *               eat stock. When the shop accepts it, ecomAcceptOrder() writes a
 *               real sales + sale_items row, deducts store_stocks for THAT store,
 *               logs stock_history and stores the sale id back on the order - so
 *               the order is visible in the POS Sales page, and pressing it opens
 *               the invoice like any other sale. ecommerceSetOrderStatus() is the
 *               one door for every later change, including the backwards ones:
 *               moving a billed order off 'accepted' runs the matching reversal, so
 *               a status and the stock behind it cannot drift apart.
 *
 * WHY PRICE AND STOCK ARE NEVER READ FROM THE REQUEST
 * ---------------------------------------------------
 * Same rule as config/plans.php: the browser shows numbers, the server decides
 * them. A client-sent price is a price anyone can set to ৳1 before submitting, so
 * ecomPlaceOrder() re-prices every line from products/ecom_listings and refuses a
 * quantity the store does not have.
 */

/**
 * Tables are created on first use, exactly like config/sms_wallet.php does, so a
 * host that grants CREATE needs no manual step. migration_006_ecommerce.sql is
 * the same DDL for cPanel accounts whose DB user cannot create tables.
 */
function ecommerceEnsureTables()
{
    static $done = false;
    if ($done) { return; }
    $done = true;

    $db = getDB();

    // The shelf photos come from products.image, a column config/database.sql never
    // declared - admin/products.php adds it on its first run. Do the same here, or a
    // marketplace request dies with "Unknown column p.image" on a fresh install.
    appSafeDdl($db, "ALTER TABLE `products` ADD COLUMN `image` VARCHAR(255) NULL");

    $db->exec("CREATE TABLE IF NOT EXISTS `ecom_shops` (
        `owner_id` INT NOT NULL,
        `slug` VARCHAR(60) NOT NULL,
        `name` VARCHAR(150) NULL,
        `phone` VARCHAR(20) NULL,
        `address` VARCHAR(255) NULL,
        `area` VARCHAR(120) NULL,
        `delivery_charge` DECIMAL(12,2) NOT NULL DEFAULT 0,
        `free_over` DECIMAL(12,2) NOT NULL DEFAULT 0,
        `min_order` DECIMAL(12,2) NOT NULL DEFAULT 0,
        `is_open` TINYINT(1) NOT NULL DEFAULT 1,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`owner_id`),
        UNIQUE KEY `uq_slug` (`slug`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // The shop's own front picture, chosen on the Online Shop page. It comes after
    // the CREATE above so a brand-new install gets the column in the same run, and
    // appSafeDdl swallows the duplicate-column error on every later run. A shop
    // without one keeps falling back to a product photo, as shop/api.php always did.
    appSafeDdl($db, "ALTER TABLE `ecom_shops` ADD COLUMN `cover` VARCHAR(255) NULL");

    $db->exec("CREATE TABLE IF NOT EXISTS `ecom_listings` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `owner_id` INT NOT NULL,
        `product_id` INT NOT NULL,
        `store_id` INT NULL,
        `visible` TINYINT(1) NOT NULL DEFAULT 0,
        `price_override` DECIMAL(12,2) NULL,
        `note` VARCHAR(255) NULL,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_owner_product` (`owner_id`, `product_id`),
        INDEX `idx_owner_visible` (`owner_id`, `visible`),
        INDEX `idx_store` (`store_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS `ecom_orders` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `order_no` VARCHAR(30) NOT NULL,
        `owner_id` INT NOT NULL,
        `store_id` INT NULL,
        `customer_name` VARCHAR(150) NULL,
        `customer_phone` VARCHAR(20) NULL,
        `address` VARCHAR(255) NULL,
        `items_total` DECIMAL(12,2) NOT NULL DEFAULT 0,
        `delivery_charge` DECIMAL(12,2) NOT NULL DEFAULT 0,
        `total` DECIMAL(12,2) NOT NULL DEFAULT 0,
        `status` ENUM('pending','accepted','rejected','delivered','cancelled') NOT NULL DEFAULT 'pending',
        `note` VARCHAR(500) NULL,
        `sale_id` INT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_order_no` (`order_no`),
        INDEX `idx_owner_status` (`owner_id`, `status`),
        INDEX `idx_phone` (`customer_phone`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS `ecom_order_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `order_id` INT NOT NULL,
        `product_id` INT NOT NULL,
        `product_name` VARCHAR(200) NOT NULL,
        `quantity` INT NOT NULL DEFAULT 1,
        `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0,
        `total_price` DECIMAL(12,2) NOT NULL DEFAULT 0,
        INDEX `idx_order` (`order_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Store an uploaded shop front picture.
 *
 * Deliberately the same discipline admin/products.php uses for a product photo:
 * getimagesize() decides whether the bytes are an image at all, and the saved
 * filename is made here from the detected MIME type - what the browser called the
 * file is never used. That is what stops a script renamed to .jpg from being stored
 * where the storefront will render it.
 *
 * Returns ['path' => relative path|null, 'error' => message|null]. A null path with
 * no error means nothing was uploaded, which the caller reads as "leave the picture
 * alone" - saving shop details must not wipe the cover every time.
 */
function ecomStoreShopImage($file)
{
    $result = ['path' => null, 'error' => null];
    if (!is_array($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return $result;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $result['error'] = 'Image upload failed. Please try again.';
        return $result;
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        $result['error'] = 'Shop image must be 2 MB or smaller.';
        return $result;
    }
    $info = @getimagesize($file['tmp_name']);
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if ($info === false || !isset($extMap[$info['mime'] ?? ''])) {
        $result['error'] = 'Only JPG, PNG, WEBP or GIF images are allowed.';
        return $result;
    }
    $dir = __DIR__ . '/../assets/img/shops';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $name = 'shop' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $extMap[$info['mime']];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        $result['error'] = 'Could not save the image. Please try again.';
        return $result;
    }
    $result['path'] = 'assets/img/shops/' . $name;
    return $result;
}

/**
 * Delete a stored shop picture, but only if the resolved path really is inside the
 * shops folder - a tampered DB value must not turn this into an arbitrary delete.
 */
function ecomDeleteShopImage($relPath)
{
    if (empty($relPath)) {
        return;
    }
    $base = realpath(__DIR__ . '/../assets/img/shops');
    $full = realpath(__DIR__ . '/../' . $relPath);
    if ($base && $full && strpos($full, $base) === 0 && is_file($full)) {
        @unlink($full);
    }
}

/**
 * The shop's public identity. One row per owner; the slug is the address customers
 * type, so it is created lazily from the owner's own name rather than making a
 * first-time shopkeeper fill a form before they can switch the shop on.
 */
function ecommerceShopSettings($ownerId)
{
    ecommerceEnsureTables();
    $db = getDB();

    $stmt = $db->prepare("SELECT * FROM ecom_shops WHERE owner_id = ? LIMIT 1");
    $stmt->execute([(int)$ownerId]);
    $shop = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($shop) { return $shop; }

    $slug = ecommerceUniqueSlug($ownerId);
    $name = null;
    try {
        $stmt = $db->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$ownerId]);
        $name = $stmt->fetchColumn();
    } catch (Exception $e) {
        $name = null;
    }

    $db->prepare("INSERT INTO ecom_shops (owner_id, slug, name) VALUES (?, ?, ?)")
       ->execute([(int)$ownerId, $slug, $name ?: 'My Shop']);

    $stmt = $db->prepare("SELECT * FROM ecom_shops WHERE owner_id = ? LIMIT 1");
    $stmt->execute([(int)$ownerId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function ecommerceUniqueSlug($ownerId)
{
    $db = getDB();
    for ($i = 0; $i < 10; $i++) {
        $slug = substr(preg_replace('/[^a-z0-9]/', '', strtolower(substr(md5($ownerId . '|' . microtime(true) . '|' . $i), 0, 10))), 0, 10);
        if ($slug === '') { continue; }
        $chk = $db->prepare("SELECT owner_id FROM ecom_shops WHERE slug = ? LIMIT 1");
        $chk->execute([$slug]);
        if (!$chk->fetchColumn()) { return $slug; }
    }
    return 'shop' . (int)$ownerId;
}

/**
 * What the shop sees on Products/Online-shop: every active product with whether it
 * is online, and the store that would fulfil it. Products without a listing row are
 * still returned (visible = 0) - a shopkeeper browsing their own catalogue should
 * see the whole catalogue, not only the part already published.
 */
function ecommerceCatalogueRows($ownerId, $onlyVisible = false)
{
    ecommerceEnsureTables();
    $db = getDB();

    $sql = "SELECT p.id, p.name, p.barcode, p.image, p.sell_price, p.stock, p.unit, p.description, p.category_id,
                   c.name AS category_name,
                   l.store_id, l.visible, l.price_override,
                   COALESCE(ss.quantity, 0) AS store_qty
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN ecom_listings l ON l.product_id = p.id AND l.owner_id = p.owner_id
            LEFT JOIN store_stocks ss ON ss.product_id = p.id
                 AND ss.store_id = COALESCE(l.store_id,
                     (SELECT MIN(s2.id) FROM stores s2 WHERE s2.owner_id = p.owner_id AND s2.status = 'active'))
            WHERE p.owner_id = ? AND p.status = 'active'";
    // No products.is_voucher filter here on purpose: that column exists only in the
    // local XAMPP dump, never in config/database.sql, so referencing it killed the
    // whole query on the live host.
    if ($onlyVisible) {
        $sql .= " AND l.visible = 1";
    }
    $sql .= " ORDER BY p.name ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute([(int)$ownerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * The one switch the shopkeeper asked for: a product in the POS, on the shop, or
 * back off it. store_id says whose counter holds it - that is what decides where a
 * later order lands, so it is written here rather than guessed at checkout.
 */
function ecommerceSetListing($ownerId, $productId, $visible, $storeId = null, $priceOverride = null)
{
    ecommerceEnsureTables();
    $db = getDB();

    $stmt = $db->prepare("SELECT id FROM products WHERE id = ? AND owner_id = ? LIMIT 1");
    $stmt->execute([(int)$productId, (int)$ownerId]);
    if (!$stmt->fetchColumn()) {
        throw new Exception('That product is not in this shop.');
    }

    if ($storeId !== null && (int)$storeId > 0) {
        $chk = $db->prepare("SELECT id FROM stores WHERE id = ? AND owner_id = ? LIMIT 1");
        $chk->execute([(int)$storeId, (int)$ownerId]);
        if (!$chk->fetchColumn()) {
            throw new Exception('That store does not belong to this account.');
        }
    }

    $db->prepare("INSERT INTO ecom_listings (owner_id, product_id, store_id, visible, price_override)
                  VALUES (?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE
                    store_id = VALUES(store_id),
                    visible = VALUES(visible),
                    price_override = VALUES(price_override)")
       ->execute([
           (int)$ownerId,
           (int)$productId,
           ($storeId !== null && (int)$storeId > 0) ? (int)$storeId : null,
           $visible ? 1 : 0,
           ($priceOverride !== null && $priceOverride !== '') ? (float)$priceOverride : null,
       ]);
}

/**
 * Price the shop shows for a product: an override is allowed (a shop may quote the
 * street price online and keep the counter price), otherwise the POS sell_price.
 */
function ecommercePrice($row)
{
    if (isset($row['price_override']) && $row['price_override'] !== null && (float)$row['price_override'] > 0) {
        return round((float)$row['price_override'], 2);
    }
    return round((float)$row['sell_price'], 2);
}

/**
 * Public catalogue for one shop, by slug. Only visible products, and only with the
 * fields a stranger needs - no buy_price, no comment, no owner_id.
 */
function ecommercePublicCatalogue($slug)
{
    ecommerceEnsureTables();
    $db = getDB();

    $stmt = $db->prepare("SELECT * FROM ecom_shops WHERE slug = ? LIMIT 1");
    $stmt->execute([(string)$slug]);
    $shop = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$shop) { return null; }

    $rows = ecommerceCatalogueRows($shop['owner_id'], true);
    // A project-relative path is useless to a phone on another origin, so the app
    // gets a full URL: getAppBaseUrl() carries the install's base folder (/admin on
    // XAMPP, nothing on the live docroot) and products.image is relative to it.
    $base = rtrim(getAppBaseUrl(), '/');
    // The same absolute-picture rule the shops list applies: a project-relative
    // path means nothing to a phone on another origin.
    $shop['cover_url'] = (trim((string)($shop['cover'] ?? '')) !== '') ? $base . '/' . ltrim((string)$shop['cover'], '/') : null;
    $out = [];
    foreach ($rows as $r) {
        $qty = (float)$r['store_qty'] > 0 ? (int)$r['store_qty'] : (int)$r['stock'];
        $out[] = [
            'id'       => (int)$r['id'],
            'name'     => $r['name'],
            'barcode'  => $r['barcode'],
            'image'    => $r['image'],
            'image_url' => ($r['image'] ? $base . '/' . ltrim((string)$r['image'], '/') : null),
            'category' => $r['category_name'],
            'unit'     => $r['unit'],
            'description' => $r['description'],
            'price'    => ecommercePrice($r),
            'stock'    => $qty,
            'in_stock' => $qty > 0,
        ];
    }
    return ['shop' => $shop, 'products' => $out];
}

/**
 * The marketplace-wide shelf: every published product of every shop, plus the
 * category list built from the same rows.
 *
 * ?action=catalogue answers one shop, which is enough for a shop page but not for
 * a home screen that has to show categories and popular items across all of them.
 * Fetching every shop's catalogue to join them on the phone would be one request
 * per shop on a customer's metered data, so the join happens here - against
 * exactly the rows the single-shop call already publishes (visible listings), and
 * nothing new is revealed by it.
 *
 * $opts: q (name or barcode fragment), category (exact name), sort
 * (popular | newest | price_low | price_high | name), limit, offset.
 */
function ecommerceBrowse($opts = [])
{
    ecommerceEnsureTables();
    $db = getDB();

    $q = trim((string)($opts['q'] ?? ''));
    $category = trim((string)($opts['category'] ?? ''));
    $sort = (string)($opts['sort'] ?? 'popular');
    $limit = (int)($opts['limit'] ?? 60);
    $limit = $limit < 1 ? 1 : ($limit > 200 ? 200 : $limit);
    $offset = max(0, (int)($opts['offset'] ?? 0));

    $where = "WHERE l.visible = 1 AND p.status = 'active'";
    $args = [];
    if ($category !== '') {
        $where .= " AND c.name = ?";
        $args[] = $category;
    }
    if ($q !== '') {
        $where .= " AND (p.name LIKE ? OR p.barcode LIKE ?)";
        $args[] = '%' . $q . '%';
        $args[] = '%' . $q . '%';
    }

    // What a shop has actually delivered, not a click counter this app does not
    // have: the home screen can call a product popular without inventing a rating.
    $sold = "(SELECT COALESCE(SUM(oi.quantity), 0) FROM ecom_order_items oi
                  INNER JOIN ecom_orders o ON o.id = oi.order_id
              WHERE oi.product_id = p.id AND o.owner_id = p.owner_id
                AND o.status IN ('accepted', 'delivered'))";

    // The same rule ecommercePrice() applies, written in SQL so the list can be
    // ordered by the price the customer is about to be shown.
    $price = "COALESCE(NULLIF(l.price_override, 0), p.sell_price)";

    $order = "$sold DESC, p.name ASC";
    if ($sort === 'newest') { $order = 'l.id DESC'; }
    elseif ($sort === 'price_low') { $order = "$price ASC"; }
    elseif ($sort === 'price_high') { $order = "$price DESC"; }
    elseif ($sort === 'name') { $order = 'p.name ASC'; }

    $stmt = $db->prepare("SELECT p.id, p.name, p.barcode, p.image, p.stock, p.unit, p.description,
                                 c.name AS category_name,
                                 l.price_override,
                                 $price AS price,
                                 s.slug AS shop_slug, s.name AS shop_name, s.area AS shop_area, s.is_open AS shop_open,
                                 COALESCE(ss.quantity, 0) AS store_qty,
                                 $sold AS sold
                          FROM ecom_listings l
                          INNER JOIN ecom_shops s ON s.owner_id = l.owner_id
                          INNER JOIN products p ON p.id = l.product_id AND p.owner_id = l.owner_id
                          LEFT JOIN categories c ON c.id = p.category_id
                          LEFT JOIN store_stocks ss ON ss.product_id = p.id
                               AND ss.store_id = COALESCE(l.store_id,
                                   (SELECT MIN(s2.id) FROM stores s2 WHERE s2.owner_id = p.owner_id AND s2.status = 'active'))
                          $where
                          ORDER BY $order
                          LIMIT $limit OFFSET $offset");
    $stmt->execute($args);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $base = rtrim(getAppBaseUrl(), '/');
    $products = [];
    foreach ($rows as $r) {
        $qty = (float)$r['store_qty'] > 0 ? (int)$r['store_qty'] : (int)$r['stock'];
        $products[] = [
            'id'         => (int)$r['id'],
            'name'       => $r['name'],
            'barcode'    => $r['barcode'],
            'image'      => $r['image'],
            'image_url'  => ($r['image'] ? $base . '/' . ltrim((string)$r['image'], '/') : null),
            'category'   => $r['category_name'],
            'unit'       => $r['unit'],
            'description' => $r['description'],
            'price'      => round((float)$r['price'], 2),
            'stock'      => $qty,
            'in_stock'   => $qty > 0,
            'sold'       => (int)$r['sold'],
            'shop_slug'  => $r['shop_slug'],
            'shop_name'  => $r['shop_name'],
            'shop_area'  => $r['shop_area'],
            'shop_open'  => (int)$r['shop_open'],
        ];
    }

    // Categories come from the same join, so a category can never appear on the
    // Categories screen with nothing behind it. The picture is one of the products
    // actually listed under that name, so a category with no photographed product
    // simply has no image rather than a made-up icon.
    $cats = $db->query("SELECT c.name AS name, COUNT(*) AS products, COUNT(DISTINCT l.owner_id) AS shops,
                               MIN(NULLIF(p.image, '')) AS pic
                        FROM ecom_listings l
                        INNER JOIN ecom_shops s ON s.owner_id = l.owner_id
                        INNER JOIN products p ON p.id = l.product_id AND p.owner_id = l.owner_id
                        INNER JOIN categories c ON c.id = p.category_id
                        WHERE l.visible = 1 AND p.status = 'active' AND c.name IS NOT NULL AND c.name <> ''
                        GROUP BY c.name
                        ORDER BY products DESC, c.name ASC
                        LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
    $categories = [];
    foreach ($cats as $c) {
        $pic = trim((string)($c['pic'] ?? ''));
        $categories[] = [
            'name'      => $c['name'],
            'products'  => (int)$c['products'],
            'shops'     => (int)$c['shops'],
            'image_url' => $pic !== '' ? $base . '/' . ltrim($pic, '/') : null,
        ];
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM ecom_listings l
                               INNER JOIN products p ON p.id = l.product_id AND p.owner_id = l.owner_id
                               LEFT JOIN categories c ON c.id = p.category_id
                               $where");
    $countStmt->execute($args);

    return ['categories' => $categories, 'products' => $products, 'total' => (int)$countStmt->fetchColumn()];
}

/**
 * Take an order. Nothing is deducted here: stock moves when the shop accepts, so a
 * wrong number or an abandoned order cannot drain the counter.
 *
 * $lines is [product_id => quantity] and arrives from a customer, which is exactly
 * why every price and every availability check below is re-derived from the
 * database instead of trusting it.
 */
function ecommercePlaceOrder($slug, $lines, $customer)
{
    ecommerceEnsureTables();
    $db = getDB();

    $stmt = $db->prepare("SELECT * FROM ecom_shops WHERE slug = ? LIMIT 1");
    $stmt->execute([(string)$slug]);
    $shop = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$shop) { throw new Exception('Shop not found.'); }
    if (!$shop['is_open']) { throw new Exception('This shop is closed right now.'); }
    if (!is_array($lines) || count($lines) === 0) { throw new Exception('Your cart is empty.'); }

    $phone = preg_replace('/[^0-9]/', '', (string)($customer['phone'] ?? ''));
    if (strlen($phone) < 10) { throw new Exception('Enter a valid phone number.'); }
    $name = trim((string)($customer['name'] ?? ''));
    if ($name === '') { throw new Exception('Enter your name.'); }
    $address = trim((string)($customer['address'] ?? ''));

    $ownerId = (int)$shop['owner_id'];
    $items = [];
    $storeId = null;
    $subtotal = 0.0;

    $stmtP = $db->prepare("SELECT p.id, p.name, p.sell_price, p.stock, p.status,
                                  l.id AS lid, l.visible, l.store_id, l.price_override,
                                  ss.quantity AS store_qty
                           FROM products p
                           INNER JOIN ecom_listings l ON l.product_id = p.id AND l.owner_id = p.owner_id
                           LEFT JOIN store_stocks ss ON ss.product_id = p.id AND ss.store_id = l.store_id
                           WHERE p.id = ? AND p.owner_id = ? LIMIT 1");

    foreach ($lines as $productId => $qty) {
        $qty = (int)$qty;
        if ($qty < 1 || $qty > 999) { continue; }

        $stmtP->execute([(int)$productId, $ownerId]);
        $row = $stmtP->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status'] !== 'active' || !$row['visible']) {
            throw new Exception('One of the items is no longer available online.');
        }

        $available = (float)$row['store_qty'] > 0 ? (int)$row['store_qty'] : (int)$row['stock'];
        if ($available < $qty) {
            throw new Exception('Only ' . $available . ' left of "' . $row['name'] . '".');
        }

        $price  = ecommercePrice($row);
        $lineTotal = round($price * $qty, 2);
        $subtotal += $lineTotal;

        if ($storeId === null) { $storeId = $row['store_id'] !== null ? (int)$row['store_id'] : null; }
        elseif ($row['store_id'] !== null && (int)$row['store_id'] !== $storeId) {
            throw new Exception('These items come from different branches. Place one order per branch.');
        }

        $items[] = [
            'product_id'   => (int)$row['id'],
            'product_name' => $row['name'],
            'quantity'     => $qty,
            'unit_price'   => $price,
            'total_price'  => $lineTotal,
        ];
    }

    if (!$items) { throw new Exception('Your cart is empty.'); }
    $subtotal = round($subtotal, 2);
    if ($subtotal < (float)$shop['min_order']) {
        throw new Exception('Minimum order for this shop is ৳' . rtrim(rtrim(number_format((float)$shop['min_order'], 2, '.', ''), '0'), '.') . '.');
    }

    $delivery = (float)$shop['delivery_charge'];
    if ((float)$shop['free_over'] > 0 && $subtotal >= (float)$shop['free_over']) { $delivery = 0.0; }

    $db->beginTransaction();
    try {
        $orderNo = null;
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $last = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM ecom_orders")->fetchColumn();
            $candidate = 'ECM-' . str_pad($last + 1, 6, '0', STR_PAD_LEFT);
            $chk = $db->prepare("SELECT id FROM ecom_orders WHERE order_no = ? LIMIT 1");
            $chk->execute([$candidate]);
            if (!$chk->fetchColumn()) { $orderNo = $candidate; break; }
        }
        if ($orderNo === null) { throw new Exception('Could not allocate an order number, please try again.'); }

        $db->prepare("INSERT INTO ecom_orders
            (order_no, owner_id, store_id, customer_name, customer_phone, address,
             items_total, delivery_charge, total, status, note)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)")
           ->execute([
               $orderNo, $ownerId, $storeId, $name, $phone, $address,
               $subtotal, $delivery, round($subtotal + $delivery, 2),
               trim((string)($customer['note'] ?? '')),
           ]);

        $orderId = (int)$db->lastInsertId();

        $stmtI = $db->prepare("INSERT INTO ecom_order_items
            (order_id, product_id, product_name, quantity, unit_price, total_price)
            VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($items as $it) {
            $stmtI->execute([$orderId, $it['product_id'], $it['product_name'], $it['quantity'], $it['unit_price'], $it['total_price']]);
        }

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        throw $e;
    }

    ecommerceNotifyOwner($ownerId, $orderNo, $storeId, count($items), round($subtotal + $delivery, 2));

    return ['order_no' => $orderNo, 'items_total' => $subtotal, 'delivery' => $delivery,
            'total' => round($subtotal + $delivery, 2)];
}

/**
 * A pending order is worth nothing to a shopkeeper who is not told about it, so a
 * customer's order pushes to the same owner/manager devices the counter sale already
 * reaches. Deliberately built from push.php's own primitives rather than a second
 * token table, and wrapped in Throwable because an unreachable push service must
 * never turn a placed order into an error the customer sees.
 */
function ecommerceNotifyOwner($ownerId, $orderNo, $storeId, $lineCount, $total)
{
    try {
        require_once __DIR__ . '/push.php';
        $db = getDB();
        pushEnsureTable($db);

        $placeholders = implode(',', array_fill(0, count(pushNotifyRoles()), '?'));
        $stmt = $db->prepare("SELECT token FROM push_tokens
                               WHERE owner_id = ? AND role IN ($placeholders)");
        $stmt->execute(array_merge([(int)$ownerId], pushNotifyRoles()));
        $tokens = array_values(array_unique(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN))));
        if (!$tokens) { return; }

        $messages = [];
        foreach ($tokens as $tok) {
            $messages[] = [
                'to'        => $tok,
                'sound'     => 'default',
                'title'     => 'New online order — ' . $orderNo,
                'body'      => $lineCount . ' item(s) • ৳' . number_format((float)$total, 2)
                              . ' • open My Online Shop to accept',
                'priority'  => 'high',
                'data'      => ['type' => 'ecom_order', 'order_no' => $orderNo],
                'channelId' => 'sales',
            ];
        }
        pushSendBatch($db, $messages, (int)$ownerId);
    } catch (Throwable $e) {
        error_log('ecommerce notify failed: ' . $e->getMessage());
    }
}

/**
 * Accept -> the order becomes a real POS sale.
 *
 * The writes mirror mobile_api/sell.php line for line (invoice allocation with the
 * same retry, sale_items, store_stocks ensure + deduct, stock_history, cashbook
 * entry) because an online order that behaves slightly differently from a counter
 * sale is how reports stop matching the till.
 *
 * $userId is WHO pressed Accept, and it is not free to guess: sales.user_id carries
 * a foreign key to users.id, so the account that takes orders must be one row that
 * exists. The acting shopkeeper is also simply the right name on the invoice - a
 * manager who accepts an order should be the one the Sales page credits, the same as
 * at the counter.
 */
function ecommerceAcceptOrder($orderId, $ownerId, $userId = null)
{
    ecommerceEnsureTables();
    $db = getDB();

    $stmt = $db->prepare("SELECT * FROM ecom_orders WHERE id = ? AND owner_id = ? LIMIT 1");
    $stmt->execute([(int)$orderId, (int)$ownerId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) { throw new Exception('Order not found.'); }
    if ($order['status'] !== 'pending') {
        throw new Exception('This order is already ' . $order['status'] . '.');
    }
    if (!empty($order['sale_id'])) { throw new Exception('This order has already been billed.'); }

    $items = $db->prepare("SELECT * FROM ecom_order_items WHERE order_id = ?");
    $items->execute([(int)$order['id']]);
    $items = $items->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) { throw new Exception('This order has no items.'); }

    $storeId  = ecommerceFulfillmentStore($db, (int)$ownerId, $order['store_id']);
    $userId   = (int)($userId !== null ? $userId : $ownerId);
    $total    = round((float)$order['items_total'], 2);

    $db->beginTransaction();
    try {
        $invoiceNumber = null;
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $last = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM sales")->fetchColumn();
            $candidate = 'INV-' . str_pad($last + 1, 6, '0', STR_PAD_LEFT);
            $chk = $db->prepare("SELECT id FROM sales WHERE invoice_number = ? LIMIT 1");
            $chk->execute([$candidate]);
            if (!$chk->fetchColumn()) { $invoiceNumber = $candidate; break; }
        }
        if ($invoiceNumber === null) { throw new Exception('Could not allocate an invoice number.'); }

        // Cash on delivery: billed as unpaid until the counter or the rider collects.
        $db->prepare("INSERT INTO sales
            (invoice_number, customer_id, user_id, subtotal, discount_percent, discount_amount,
             vat_percent, vat_amount, total, paid_amount, change_amount,
             payment_method, payment_status, note, owner_id)
            VALUES (?, NULL, ?, ?, 0, 0, 0, 0, ?, 0, 0, 'cash', 'unpaid', ?, ?)")
           ->execute([$invoiceNumber, $userId, $total, $total,
                      'Online order ' . $order['order_no']
                      . ($order['customer_name'] ? ' - ' . $order['customer_name'] : '')
                      . ($order['customer_phone'] ? ' (' . $order['customer_phone'] . ')' : ''),
                      (int)$ownerId]);

        $saleId = (int)$db->lastInsertId();

        // Customer by phone, the same way the mobile till does it.
        $customerId = null;
        if ($order['customer_phone']) {
            $c = $db->prepare("SELECT id FROM customers WHERE phone = ? AND owner_id = ? LIMIT 1");
            $c->execute([$order['customer_phone'], (int)$ownerId]);
            $found = $c->fetchColumn();
            if ($found) {
                $customerId = (int)$found;
            } else {
                $db->prepare("INSERT INTO customers (name, phone, email, address, owner_id) VALUES (?, ?, NULL, ?, ?)")
                  ->execute([$order['customer_name'] ?: 'Online Customer', $order['customer_phone'], $order['address'], (int)$ownerId]);
                $customerId = (int)$db->lastInsertId();
            }
            $db->prepare("UPDATE sales SET customer_id = ? WHERE id = ?")->execute([$customerId, $saleId]);
        }

        $stmtItem  = $db->prepare("INSERT INTO sale_items (sale_id, product_id, product_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)");
        $readQty   = $db->prepare("SELECT quantity FROM store_stocks WHERE store_id = ? AND product_id = ? LIMIT 1");
        $openQty   = $db->prepare("INSERT INTO store_stocks (store_id, product_id, quantity) VALUES (?, ?, ?)");
        $openFrom  = $db->prepare("SELECT stock FROM products WHERE id = ? LIMIT 1");
        $deduct    = $db->prepare("UPDATE store_stocks SET quantity = quantity - ? WHERE store_id = ? AND product_id = ?");
        $history   = $db->prepare("INSERT INTO stock_history (product_id, quantity_change, type, reference_id, note, user_id) VALUES (?, ?, 'sale', ?, ?, ?)");

        foreach ($items as $it) {
            $pid = (int)$it['product_id'];
            $stmtItem->execute([$saleId, $pid, $it['product_name'], (int)$it['quantity'],
                                (float)$it['unit_price'], (float)$it['total_price']]);
            if ($storeId) {
                // A product that has never been given a counter row still carries the
                // opening number the Products page holds. Seeding the row from it here
                // is what lets the shop's stock count drop by the quantity sold; without
                // it the row would appear at zero, the till would read -1, and the
                // storefront would keep showing the old number forever.
                $readQty->execute([$storeId, $pid]);
                if ($readQty->fetchColumn() === false) {
                    $openFrom->execute([$pid]);
                    $openQty->execute([$storeId, $pid, (float)$openFrom->fetchColumn()]);
                }
                $deduct->execute([(int)$it['quantity'], $storeId, $pid]);
            }
            $history->execute([$pid, -((int)$it['quantity']), $saleId,
                              'Online order: ' . $invoiceNumber, $userId]);
        }

        $db->prepare("UPDATE ecom_orders SET status = 'accepted', sale_id = ?, store_id = COALESCE(store_id, ?) WHERE id = ?")
           ->execute([$saleId, $storeId, (int)$order['id']]);

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        throw $e;
    }

    return ['sale_id' => $saleId, 'invoice_number' => $invoiceNumber];
}

/**
 * Which counter an order's stock moves at.
 *
 * A listing saved as "shared stock" stores no branch, and until now an order of
 * those products was billed without anything being deducted anywhere - the till
 * kept its count and the shop kept selling the same piece. The POS itself has the
 * same convention everywhere else (the Products and Stock pages read the owner's
 * first active store), so that is what an order with no branch falls back to.
 */
function ecommerceFulfillmentStore($db, $ownerId, $storeId)
{
    if ($storeId !== null && (int)$storeId > 0) { return (int)$storeId; }
    $stmt = $db->prepare("SELECT id FROM stores WHERE owner_id = ? AND status = 'active' ORDER BY id ASC LIMIT 1");
    $stmt->execute([(int)$ownerId]);
    $found = $stmt->fetchColumn();
    return $found ? (int)$found : null;
}

/**
 * Undo the billing of an accepted order: the stock goes back to the counter it
 * came from and the POS invoice is removed.
 *
 * This is admin/api/delete-sale.php's reversal, narrowed to an online order. It
 * has to be as careful as that endpoint, because the same button that cancels a
 * wrong order also has to refuse one the shop has already taken money for: a paid
 * invoice or a recorded return is evidence the shopkeeper cannot see here.
 *
 * Returns the invoice number that was removed, or null when nothing was billed.
 */
function ecommerceUnbillOrder($orderId, $ownerId, $userId = null)
{
    ecommerceEnsureTables();
    $db = getDB();

    $stmt = $db->prepare("SELECT * FROM ecom_orders WHERE id = ? AND owner_id = ? LIMIT 1");
    $stmt->execute([(int)$orderId, (int)$ownerId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) { throw new Exception('Order not found.'); }
    if (empty($order['sale_id'])) { return null; }

    $saleId = (int)$order['sale_id'];
    // The id is ours, but a hand-edited row must not be able to point at another
    // shop's invoice: every write below is scoped by owner_id as well.
    $sale = $db->prepare("SELECT id, invoice_number, paid_amount, payment_status FROM sales WHERE id = ? AND owner_id = ? LIMIT 1");
    $sale->execute([$saleId, (int)$ownerId]);
    $saleRow = $sale->fetch(PDO::FETCH_ASSOC);

    if ($saleRow) {
        if ((float)$saleRow['paid_amount'] > 0 || $saleRow['payment_status'] === 'paid') {
            throw new Exception('This order has been paid for on invoice ' . $saleRow['invoice_number']
                . '. Reverse that payment on the Sales page first.');
        }
        $ret = $db->prepare("SELECT COUNT(*) FROM returns WHERE sale_id = ?");
        $ret->execute([$saleId]);
        if ((int)$ret->fetchColumn() > 0) {
            throw new Exception('A return is recorded against ' . $saleRow['invoice_number']
                . '. Delete that return on the Returns page first.');
        }
    }

    $storeId = ecommerceFulfillmentStore($db, (int)$ownerId, $order['store_id']);
    // stock_history.user_id is NOT NULL and carries a foreign key, so the reversal
    // is credited to the shopkeeper who asked for it (or the owner) rather than 0.
    $userId = (int)($userId !== null ? $userId : $ownerId);
    $items = [];
    if ($saleRow) {
        $st = $db->prepare("SELECT product_id, quantity FROM sale_items WHERE sale_id = ?");
        $st->execute([$saleId]);
        $items = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    $db->beginTransaction();
    try {
        if ($saleRow) {
            $ensure = $db->prepare("INSERT IGNORE INTO store_stocks (store_id, product_id, quantity) VALUES (?, ?, 0)");
            $restore = $db->prepare("UPDATE store_stocks SET quantity = quantity + ? WHERE store_id = ? AND product_id = ?");
            $history = $db->prepare("INSERT INTO stock_history (product_id, quantity_change, type, reference_id, note, user_id) VALUES (?, ?, 'sale_delete', ?, ?, ?)");

            foreach ($items as $it) {
                $pid = (int)$it['product_id'];
                $qty = (int)$it['quantity'];
                if ($storeId) {
                    $ensure->execute([$storeId, $pid]);
                    $restore->execute([$qty, $storeId, $pid]);
                }
                $history->execute([$pid, $qty, $saleId, 'Online order cancelled: ' . $saleRow['invoice_number'], $userId]);
            }

            $db->prepare("DELETE FROM sale_items WHERE sale_id = ?")->execute([$saleId]);
            $db->prepare("DELETE FROM sales WHERE id = ? AND owner_id = ?")->execute([$saleId, (int)$ownerId]);
            deleteAutoCashbookEntries('sale', $saleId);
        }
        $db->prepare("UPDATE ecom_orders SET sale_id = NULL WHERE id = ? AND owner_id = ?")
           ->execute([(int)$orderId, (int)$ownerId]);
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        throw $e;
    }

    return $saleRow ? (string)$saleRow['invoice_number'] : null;
}

/**
 * Move an order to a chosen status, doing whatever the POS half of that means.
 *
 * The old page had one button per step and only allowed the forward ones, so a
 * shopkeeper who accepted the wrong order, or who delivered and then had to take
 * the order back, had no way out. Each transition here is the same write the
 * single-purpose button used to do, and the two backwards ones run the reversal
 * above, so a status can never drift away from the stock and the invoice.
 *
 * Returns ['text' => flash message, 'tone' => success|warning].
 */
function ecommerceSetOrderStatus($orderId, $ownerId, $status, $userId = null)
{
    $legal = ['pending', 'accepted', 'rejected', 'delivered', 'cancelled'];
    if (!in_array($status, $legal, true)) { throw new Exception('Unknown order status.'); }

    ecommerceEnsureTables();
    $db = getDB();

    $stmt = $db->prepare("SELECT * FROM ecom_orders WHERE id = ? AND owner_id = ? LIMIT 1");
    $stmt->execute([(int)$orderId, (int)$ownerId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) { throw new Exception('Order not found.'); }

    $from = (string)$order['status'];
    if ($from === $status) {
        return ['text' => 'Nothing changed: the order was already ' . $status . '.', 'tone' => 'warning'];
    }

    $wasBilled = in_array($from, ['accepted', 'delivered'], true) && !empty($order['sale_id']);
    $willBill  = in_array($status, ['accepted', 'delivered'], true);

    if ($wasBilled && !$willBill) {
        $invoice = ecommerceUnbillOrder($orderId, $ownerId, $userId);
        $db->prepare("UPDATE ecom_orders SET status = ? WHERE id = ? AND owner_id = ?")
           ->execute([$status, (int)$orderId, (int)$ownerId]);
        return [
            'text' => 'Order is now ' . $status . '. '
                     . ($invoice ? 'Invoice ' . $invoice . ' was removed and the stock went back to the counter.' : ''),
            'tone' => 'warning',
        ];
    }

    if ($from === 'pending' && $willBill) {
        $res = ecommerceAcceptOrder($orderId, $ownerId, $userId);
        if ($status === 'delivered') {
            $db->prepare("UPDATE ecom_orders SET status = 'delivered' WHERE id = ? AND owner_id = ?")
               ->execute([(int)$orderId, (int)$ownerId]);
        }
        return [
            'text' => 'Order accepted and billed as ' . $res['invoice_number']
                     . '. Stock was reduced at the fulfilling branch.',
            'tone' => 'success',
        ];
    }

    $db->prepare("UPDATE ecom_orders SET status = ? WHERE id = ? AND owner_id = ?")
       ->execute([$status, (int)$orderId, (int)$ownerId]);

    return ['text' => 'Order is now ' . $status . '.', 'tone' => 'success'];
}

/**
 * Remove an order outright.
 *
 * Used for the two things a status cannot express: a test order that should never
 * have existed, and a duplicate. Anything already billed is unbilled first, so
 * deleting the order can never leave an invoice and a stock deduction behind.
 */
function ecommerceDeleteOrder($orderId, $ownerId, $userId = null)
{
    ecommerceEnsureTables();
    $db = getDB();

    $stmt = $db->prepare("SELECT * FROM ecom_orders WHERE id = ? AND owner_id = ? LIMIT 1");
    $stmt->execute([(int)$orderId, (int)$ownerId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) { throw new Exception('Order not found.'); }

    $invoice = !empty($order['sale_id']) ? ecommerceUnbillOrder($orderId, $ownerId, $userId) : null;

    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM ecom_order_items WHERE order_id = ?")->execute([(int)$orderId]);
        $db->prepare("DELETE FROM ecom_orders WHERE id = ? AND owner_id = ?")->execute([(int)$orderId, (int)$ownerId]);
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        throw $e;
    }

    return ['order_no' => (string)$order['order_no'], 'invoice' => $invoice];
}

function ecommerceOrders($ownerId, $status = null)
{
    ecommerceEnsureTables();
    $db = getDB();

    // sales is joined only to name the invoice a billed order became: the shopkeeper
    // needs the number to follow it into the Sales page, and an order whose invoice
    // was deleted elsewhere must still list.
    $sql = "SELECT o.*, s.name AS store_name, sa.invoice_number
            FROM ecom_orders o
            LEFT JOIN stores s ON s.id = o.store_id
            LEFT JOIN sales sa ON sa.id = o.sale_id
            WHERE o.owner_id = ?";
    $args = [(int)$ownerId];
    if ($status !== null && $status !== '') {
        $sql .= " AND o.status = ?";
        $args[] = $status;
    }
    $sql .= " ORDER BY o.id DESC LIMIT 200";

    $stmt = $db->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ecommerceOrderItems($orderId)
{
    ecommerceEnsureTables();
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM ecom_order_items WHERE order_id = ? ORDER BY id ASC");
    $stmt->execute([(int)$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

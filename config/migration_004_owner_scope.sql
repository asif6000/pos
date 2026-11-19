-- ============================================================================
--  Migration 004 - owner scope reconciliation
--  Smart Collection POS
--
--  THE PROBLEM
--  -----------
--  Almost every owner-scoped query in this application filters on
--      WHERE owner_id = :owner
--  and `owner_id = NULL` is never true in SQL. Rows inserted before an
--  owner was assigned, or seeded by config/database.sql which omits owner_id
--  entirely, therefore become invisible to every user - permanently, and
--  without any error.
--
--  Measured on the live deployment (https://smartercollection.shop):
--      POST /mobile_api/products.php  owner_id=1  -> products=0 categories=0
--      POST /mobile_api/products.php  owner_id=2  -> products=0 categories=0
--  for every store, while the same query against the development database
--  returns 2 products. The shop signs in successfully and then sees an empty
--  POS with no indication that anything is wrong.
--
--  Products and categories are the visible symptom, but the same NULL applies
--  to customers, sales, returns, cashbook entries and settings.
--
--  THE FIX
--  -------
--  Adopt one owner for the orphaned rows. This is a single-shop install, so
--  the target is chosen from the data rather than hardcoded: the owner that
--  already holds this shop's sales. Where nothing has been sold yet, the owner
--  of the oldest active store is used instead.
--
--  SAFETY
--  ------
--  Every statement is guarded on `owner_id IS NULL`, so:
--    - already-correct rows are never touched
--    - it is safe to run more than once
--    - rows already owned by somebody are never reassigned
--  No row is deleted, renamed or retyped. Take a backup first anyway.
--
--  HOW TO USE
--  Import with phpMyAdmin. Read the report it prints at the end.
--
--  Verified against MySQL 5.7 / MariaDB 10.4.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. Choose the owner this shop belongs to.
--
--    Preference order:
--      a) the owner holding the most sales - proof the shop trades as them
--      b) the owner of the oldest active store - the shop exists, just idle
--      c) the lowest users.id - a last resort for a completely fresh install
--
--    Stored once so every later statement agrees on the same target.
-- ---------------------------------------------------------------------------
SET @owner := (
    SELECT owner_id FROM sales WHERE owner_id IS NOT NULL
    GROUP BY owner_id ORDER BY COUNT(*) DESC, owner_id ASC LIMIT 1
);

SET @owner := COALESCE(@owner, (
    SELECT owner_id FROM stores WHERE owner_id IS NOT NULL AND status = 'active'
    ORDER BY id ASC LIMIT 1
));

SET @owner := COALESCE(@owner, (SELECT MIN(id) FROM users));

-- ---------------------------------------------------------------------------
-- 2. An admin account IS the owner of their shop.
--    `users.owner_id IS NULL` means "top-level admin", which the code already
--    compensates for via getCurrentUser(). Writing it out removes the ambiguity
--    and stops 30 files reading the raw column disagreeing with the other 40.
-- ---------------------------------------------------------------------------
UPDATE users SET owner_id = id WHERE owner_id IS NULL;

-- Staff and cashier accounts inherit the owner of the shop they work for,
-- resolved through their store when they have one.
UPDATE users u
  JOIN stores s ON s.id = u.store_id
   SET u.owner_id = s.owner_id
 WHERE u.owner_id IS NULL AND s.owner_id IS NOT NULL;

-- Anything still unowned goes to the shop owner. A cashier with no store would
-- otherwise see an empty catalogue.
UPDATE users SET owner_id = @owner WHERE owner_id IS NULL;

-- Stores are adopted HERE, before section 6, and the ordering is load-bearing.
--
-- Section 6 creates a store_stocks row per product by joining stores to products
-- on owner_id. With stores.owner_id still NULL that join matches nothing, so no
-- stock rows are written, and a product with no stock row is not shown as out of
-- stock - it is not shown at all (products.php filters
-- COALESCE(ss.quantity,0) > 0). That failure is silent: the migration reports
-- success and the POS is simply short of products.
UPDATE stores SET owner_id = @owner WHERE owner_id IS NULL;

-- ---------------------------------------------------------------------------
-- 3. Catalogue. These are the rows that make the POS look empty.
-- ---------------------------------------------------------------------------
UPDATE products SET owner_id = @owner WHERE owner_id IS NULL;
UPDATE categories SET owner_id = @owner WHERE owner_id IS NULL;

-- ---------------------------------------------------------------------------
-- 4. Trading data.
-- ---------------------------------------------------------------------------
UPDATE customers SET owner_id = @owner WHERE owner_id IS NULL;
UPDATE sales     SET owner_id = @owner WHERE owner_id IS NULL;
UPDATE returns   SET owner_id = @owner WHERE owner_id IS NULL;
UPDATE cashbook_entries SET owner_id = @owner WHERE owner_id IS NULL;
UPDATE transfers SET owner_id = @owner WHERE owner_id IS NULL;
UPDATE stock_history SET user_id = (SELECT MIN(id) FROM users)
 WHERE user_id IS NULL;

-- ---------------------------------------------------------------------------
-- 5. Settings.
--
--    settings has UNIQUE KEY (owner_id, setting_key). Setting owner_id on an
--    orphan can therefore collide with an already-owned row of the same key and
--    abort the whole import with
--        SQLSTATE[23000] Duplicate entry '2-vat_percent' for key 'uk_owner_key'
--    part-way through, leaving the file half-applied.
--
--    So an orphan is adopted only when NO row already exists for that key under
--    this owner. The owned copy is authoritative and is left untouched.
--
--    WHY A MULTI-TABLE UPDATE RATHER THAN A SUBQUERY
--    ---------------------------------------------
--    Every obvious way of writing this predicate fails somewhere:
--
--      NOT EXISTS (SELECT ... FROM settings ...)   MySQL error 1093,
--                                                   "You can't specify target
--                                                   table 'settings' for update
--                                                   in FROM clause"
--      NOT EXISTS (SELECT ... FROM (SELECT ...))  accepted on MariaDB 10.4, but
--                                                   MySQL 8.0 may merge the
--                                                   derived table back and
--                                                   re-raise 1093
--      via a TEMPORARY table                        TEMPORARY tables are
--                                                   session-scoped. An importer
--                                                   that reconnects between
--                                                   statements - which phpMyAdmin
--                                                   may do - drops the table and
--                                                   raises ERROR 1146 (42S02):
--                                                   Table doesn't exist, again
--                                                   part-way through the import.
-- ---------------------------------------------------------------------------
-- ---------------------------------------------------------------------------
--    A multi-table UPDATE that joins settings to itself is the form MySQL
--    documents for this situation, and it is subject to none of the above.
--    `o.id IS NULL` is the "no owned twin exists" test, and unlike NOT IN it is
--    NULL-safe.
--
--    THE SECOND JOIN IS NOT OPTIONAL
--    ------------------------------
--    o answers "is this key already owned?". That alone is not enough, and the
--    omission produced
--        ERROR 1062 (23000) Duplicate entry '1-barcode_top_margin'
--                  for key 'uk_owner_key'
--    on a live database.
--
--    MySQL treats NULLs as DISTINCT inside a unique index, so several rows may
--    share one setting_key while all of them have owner_id = NULL - legal
--    today, invisible to o, and adopted together by that guard alone. Two rows
--    become two rows of (owner_id, setting_key) and the index rejects the second.
--
--    d closes that: it matches any sibling orphan STRICTLY NEWER than s, so only
--    the newest orphan per key - the one with no newer sibling - adopts. COALESCE
--    keeps a NULL updated_at from making every sibling compare equal, which
--    would put the original 1062 straight back. The newest value wins because a
--    more recently written setting is the more likely current one; ties break on
--    id, which is what makes a second run a no-op.
--
--    Any leftover orphans are listed by the report at the end of this file so
--    the shop can decide whether the discarded values mattered.
-- ---------------------------------------------------------------------------
UPDATE settings s
LEFT JOIN settings o
       ON o.setting_key = s.setting_key
      AND o.owner_id    = @owner
LEFT JOIN settings d
       ON d.setting_key = s.setting_key
      AND d.owner_id IS NULL
      AND (COALESCE(d.updated_at, '1970-01-01 00:00:00'), d.id)
        > (COALESCE(s.updated_at, '1970-01-01 00:00:00'), s.id)
   SET s.owner_id = @owner
 WHERE s.owner_id IS NULL
   AND o.id IS NULL
   AND d.id IS NULL;

-- ---------------------------------------------------------------------------
-- 6. Give every product a stock row for the shop's store.
--
--    products.php joins store_stocks and filters COALESCE(ss.quantity,0) > 0,
--    so a product with no stock row is not shown as out of stock - it is not
--    shown at all. Creating the row makes it visible and honest.
--
--    Quantity starts at 0 deliberately. Guessing a stock figure would invent
--    inventory that was never counted; the owner enters the real number on the
--    Stock page.
--
--    The store is resolved into @store first rather than joined on owner_id.
--    The join version silently wrote NOTHING whenever stores.owner_id was still
--    NULL, which is exactly the state this migration exists to repair - the
--    report said "success" while the POS stayed short of products.
--
--    INSERT IGNORE leans on UNIQUE KEY idx_store_product (store_id, product_id)
--    to skip products that already have a row, which is what the previous
--    NOT EXISTS was doing by hand. The derived table in that subquery was also
--    the shape MySQL 8.0 may merge back into the statement and reject with
--    error 1093. One index does the same job with no subquery at all.
-- ---------------------------------------------------------------------------
SET @store := (
    SELECT id FROM stores WHERE owner_id = @owner AND status = 'active'
     ORDER BY id ASC LIMIT 1
);

SET @store := COALESCE(@store, (SELECT MIN(id) FROM stores));

INSERT IGNORE INTO store_stocks (store_id, product_id, quantity)
SELECT @store, p.id, 0
  FROM products p
 WHERE p.owner_id = @owner;

-- ============================================================================
--  REPORT
--
--  Every query below uses ordinary column aliases. An earlier version of this
--  file set column headings with empty ones -
--      SELECT t AS ``, n AS ``
--  which is not a legal alias and made phpMyAdmin's SQL parser emit
--      Warning in ./vendor/phpmyadmin/sql-parser/src/Utils/Query.php#661
--      Attempt to read property "keyword" on null
--  twice per statement while rendering the results grid. The import itself had
--  already succeeded; the noise was in the report, not the migration - but a
--  wall of backtraces is not an acceptable way to find that out.
-- ============================================================================
SELECT CONCAT('Adopted owner_id = ', @owner) AS adopted_owner,
       CONCAT('Store in use    = ', IFNULL(@store, 'none')) AS store_in_use;

SELECT t AS table_name, n AS rows_still_null FROM (
    SELECT 'products'         AS t, COUNT(*) AS n FROM products            WHERE owner_id IS NULL
    UNION ALL SELECT 'categories',          COUNT(*) FROM categories         WHERE owner_id IS NULL
    UNION ALL SELECT 'customers',           COUNT(*) FROM customers          WHERE owner_id IS NULL
    UNION ALL SELECT 'sales',               COUNT(*) FROM sales              WHERE owner_id IS NULL
    UNION ALL SELECT 'returns',             COUNT(*) FROM returns            WHERE owner_id IS NULL
    UNION ALL SELECT 'users',               COUNT(*) FROM users              WHERE owner_id IS NULL
    UNION ALL SELECT 'cashbook_entries',    COUNT(*) FROM cashbook_entries   WHERE owner_id IS NULL
    UNION ALL SELECT 'settings',            COUNT(*) FROM settings           WHERE owner_id IS NULL
    UNION ALL SELECT 'stores',              COUNT(*) FROM stores             WHERE owner_id IS NULL
    UNION ALL SELECT 'transfers',           COUNT(*) FROM transfers          WHERE owner_id IS NULL
) leftover
ORDER BY n DESC, t;

-- What the POS will now show. If active_products is 0 the shop still has an
-- empty catalogue; if `products_without_stock_row` is above 0, some are
-- invisible until a quantity is entered on the Stock page.
SELECT
    (SELECT COUNT(*) FROM products WHERE status = 'active') AS active_products,
    (SELECT COUNT(*) FROM categories WHERE status = 'active') AS active_categories,
    (SELECT COUNT(*) FROM customers) AS customers,
    (SELECT COUNT(*) FROM sales) AS sales,
    (SELECT COUNT(*) FROM products p
       WHERE p.status = 'active'
         AND NOT EXISTS (SELECT 1 FROM (SELECT product_id FROM store_stocks) x
                          WHERE x.product_id = p.id)) AS products_without_stock_row;

SELECT p.id AS product_id,
       p.name AS product_name,
       p.owner_id,
       COALESCE(ss.quantity, 0) AS stock
  FROM products p
  LEFT JOIN store_stocks ss ON ss.product_id = p.id
 WHERE p.status = 'active'
 ORDER BY p.id
 LIMIT 20;

-- Any setting that existed more than once while unowned could only keep one
-- value. The others are still here with owner_id NULL, unread by the application.
-- Review these: if a discarded value was the one the shop was actually using,
-- set it on the Settings page and it will be adopted on the next run.
SELECT s.setting_key,
       COUNT(*)        AS copies,
       GROUP_CONCAT(s.setting_value ORDER BY s.id SEPARATOR ' | ') AS values_found,
       MAX(s.id)       AS kept_id
  FROM settings s
 WHERE s.owner_id IS NULL
 GROUP BY s.setting_key
HAVING COUNT(*) > 1
 ORDER BY s.setting_key;
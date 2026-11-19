-- ============================================================================
--  migration_004_apply_only.sql
--
--  Same repairs as migration_004_owner_scope.sql, with the report removed.
--
--  WHY A SEPARATE FILE
--  -------------------
--  phpMyAdmin's SQL parser choked on the reporting queries in the full file
--  (empty column aliases), printing a wall of
--      Attempt to read property "keyword" on null
--  backtraces after the work had already succeeded. This file contains only
--  statements that change data, so a clean run here means clean run there.
--
--  Safe to run more than once. Every UPDATE is guarded on `owner_id IS NULL`,
--  so rows already repaired are never touched again.
--
--  Paste the whole thing into phpMyAdmin -> SQL -> select your database -> Go.
--  Take a backup first.
-- ============================================================================

SET @owner := (
    SELECT owner_id FROM sales WHERE owner_id IS NOT NULL
    GROUP BY owner_id ORDER BY COUNT(*) DESC, owner_id ASC LIMIT 1
);
SET @owner := COALESCE(@owner, (
    SELECT owner_id FROM stores WHERE owner_id IS NOT NULL AND status = 'active'
    ORDER BY id ASC LIMIT 1
));
SET @owner := COALESCE(@owner, (SELECT MIN(id) FROM users));

-- An admin account is the owner of their own shop.
UPDATE users SET owner_id = id WHERE owner_id IS NULL;

UPDATE users u
  JOIN stores s ON s.id = u.store_id
   SET u.owner_id = s.owner_id
 WHERE u.owner_id IS NULL AND s.owner_id IS NOT NULL;

UPDATE users SET owner_id = @owner WHERE owner_id IS NULL;

-- Stores must be adopted before stock rows are created, or section 6 below
-- joins on owner_id, matches nothing, and silently leaves products invisible.
UPDATE stores SET owner_id = @owner WHERE owner_id IS NULL;

UPDATE products   SET owner_id = @owner WHERE owner_id IS NULL;
UPDATE categories SET owner_id = @owner WHERE owner_id IS NULL;
UPDATE customers  SET owner_id = @owner WHERE owner_id IS NULL;
UPDATE sales      SET owner_id = @owner WHERE owner_id IS NULL;
UPDATE returns    SET owner_id = @owner WHERE owner_id IS NULL;
UPDATE cashbook_entries SET owner_id = @owner WHERE owner_id IS NULL;
UPDATE transfers  SET owner_id = @owner WHERE owner_id IS NULL;

UPDATE stock_history SET user_id = (SELECT MIN(id) FROM users) WHERE user_id IS NULL;

-- Settings. Two joins, both necessary.
--   o = "is this key already owned?"          -> skip, owned copy wins
--   d = "is there a NEWER orphan with this key?" -> skip, keep only the newest
-- Without d, two rows sharing one setting_key while owner_id is NULL are both
-- adopted and the unique index rejects the second with
--   Duplicate entry '1-barcode_top_margin' for key 'uk_owner_key'
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

-- Stock rows for every owned product. INSERT IGNORE relies on
-- UNIQUE KEY idx_store_product (store_id, product_id) to skip existing rows.
-- Quantity starts at 0 on purpose: a guessed stock figure would be invented
-- inventory. Enter real numbers on the Stock page.
SET @store := (
    SELECT id FROM stores WHERE owner_id = @owner AND status = 'active'
     ORDER BY id ASC LIMIT 1
);
SET @store := COALESCE(@store, (SELECT MIN(id) FROM stores));

INSERT IGNORE INTO store_stocks (store_id, product_id, quantity)
SELECT @store, p.id, 0 FROM products p WHERE p.owner_id = @owner;

-- Two plain results so you can confirm it worked.
SELECT @owner AS adopted_owner_id, @store AS store_id;

SELECT 'products' AS table_name, COUNT(*) AS rows_still_null FROM products   WHERE owner_id IS NULL
UNION ALL SELECT 'categories', COUNT(*) FROM categories WHERE owner_id IS NULL
UNION ALL SELECT 'users',     COUNT(*) FROM users     WHERE owner_id IS NULL
UNION ALL SELECT 'stores',    COUNT(*) FROM stores    WHERE owner_id IS NULL
UNION ALL SELECT 'customers', COUNT(*) FROM customers WHERE owner_id IS NULL
UNION ALL SELECT 'sales',     COUNT(*) FROM sales     WHERE owner_id IS NULL
UNION ALL SELECT 'settings',  COUNT(*) FROM settings  WHERE owner_id IS NULL;
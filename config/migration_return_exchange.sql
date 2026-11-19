-- Return & Exchange ledger columns
-- ============================================================
-- WHAT THIS DOES
-- Adds four columns to `returns` so every return/exchange row says what kind of
-- transaction it was and how much cash actually moved, instead of that being
-- inferred from the '[EXCHANGE] ' prefix on a return_items product name.
--
--   transaction_type      'return' or 'exchange'
--   exchange_amount       value of the goods the customer took in exchange (0 for a plain return)
--   extra_amount_received money the customer paid on top, when exchange > returned
--   refund_amount         money handed back, when returned > exchange
--
-- HOW TO RUN
-- In phpMyAdmin: select your database first, then open the SQL tab and paste ONE
-- statement at a time, running them in the order below. The ALTER is safe to skip
-- if the app already added the columns by itself (it does on first use).
--
-- This file changes no money and deletes nothing. The UPDATE at the end only fills
-- in the four new columns for rows that already exist.

ALTER TABLE `returns`
  ADD COLUMN `transaction_type` VARCHAR(10) NOT NULL DEFAULT 'return' AFTER `total_amount`,
  ADD COLUMN `exchange_amount` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `transaction_type`,
  ADD COLUMN `extra_amount_received` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `exchange_amount`,
  ADD COLUMN `refund_amount` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `extra_amount_received`;

-- Mark the returns that were exchanges: any return with a '[EXCHANGE] ' line under it.
UPDATE `returns` r
SET r.`transaction_type` = 'exchange'
WHERE EXISTS (
  SELECT 1 FROM `return_items` ri
  WHERE ri.return_id = r.id AND ri.product_name LIKE '[EXCHANGE] %'
);

-- Exchange value = the sum of the '[EXCHANGE] ' lines.
UPDATE `returns` r
SET r.`exchange_amount` = COALESCE((
  SELECT SUM(ri.total_price) FROM `return_items` ri
  WHERE ri.return_id = r.id AND ri.product_name LIKE '[EXCHANGE] %'
), 0)
WHERE r.`transaction_type` = 'exchange';

-- The cash difference, split the way the app books it: one direction or the other.
UPDATE `returns` r
SET r.`extra_amount_received` = GREATEST(r.`exchange_amount` - r.`total_amount`, 0),
    r.`refund_amount`         = GREATEST(r.`total_amount` - r.`exchange_amount`, 0)
WHERE r.`transaction_type` = 'exchange';

-- A plain return hands back the whole returned value.
UPDATE `returns` r
SET r.`refund_amount` = r.`total_amount`
WHERE r.`transaction_type` = 'return';

-- Cashbook refund detail: how the money moved and against which bill
-- ============================================================
-- WHAT THIS DOES
-- Adds two columns to `cashbook_entries`:
--
--   payment_method  how the money moved - cash, bkash, nagad, rocket, card, bank,
--                   store_credit
--   invoice_no      the invoice number of the bill a refund came back from
--
-- Both are nullable, so every existing row and every other writer keeps working
-- untouched.
--
-- WHY
-- A refund now leaves two traces, and they have to be readable as one event: the
-- sale it was taken off, and the Expense line that says the cash left the drawer.
-- Without these columns that line says only "Return RET-20261020-0001 (refund)" and
-- nothing tells the shop which bill it settled or whether cash actually moved. The
-- method matters because a refund paid as Store Credit pays out no cash at all, and
-- the available Cash Balance is what a cash refund is checked against before the
-- till is allowed to pay it.
--
-- The refund lines are also filed under the shop's "Product Refund" expense
-- category, so they appear in the Expense list's category totals. That category is
-- created per shop on its first refund by the app itself - it is deliberately not
-- inserted here, because a shop that never takes a return should not get an empty
-- category, and because a category belongs to one owner while this file says
-- nothing about owners.
--
-- HOW TO RUN
-- In phpMyAdmin: select your database first, open the SQL tab, and paste ONE
-- statement at a time, in the order below. Check the affected-row count on each -
-- the green success banner also shows when only part of a pasted file ran.
--
-- Nothing here deletes a row or changes a money value. The app adds these two
-- columns by itself on first use, so if the ALTER errors with "Duplicate column
-- name" the fix is to skip that statement, not to force it.

ALTER TABLE `cashbook_entries`
  ADD COLUMN `payment_method` VARCHAR(20) DEFAULT NULL AFTER `category_id`,
  ADD COLUMN `invoice_no` VARCHAR(50) DEFAULT NULL AFTER `payment_method`;

ALTER TABLE `cashbook_entries`
  ADD KEY `idx_cashbook_source` (`source_type`, `source_id`);

-- Optional, and safe to skip: name the method on the refund and exchange lines that
-- were already booked. Every one of them was paid in cash, because until now the
-- return doors moved cash and nothing else. Rows that are not a return's stay NULL -
-- a rent paid by bank transfer is not assumed to have been cash.
UPDATE `cashbook_entries`
SET `payment_method` = 'cash'
WHERE `source_type` = 'return'
  AND `payment_method` IS NULL;

-- Optional, and safe to skip: fill in the bill each old refund came back from, read
-- through the return it is linked to. Returns whose sale has since been deleted are
-- left NULL rather than guessed at.
UPDATE `cashbook_entries` ce
JOIN `returns` r ON r.`id` = ce.`source_id`
JOIN `sales` s ON s.`id` = r.`sale_id`
SET ce.`invoice_no` = s.`invoice_number`
WHERE ce.`source_type` = 'return'
  AND ce.`invoice_no` IS NULL;

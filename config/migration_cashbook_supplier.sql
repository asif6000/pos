-- Cashbook: pay a supplier out of a named book
-- ============================================================
-- WHAT THIS DOES
-- Adds one nullable column to `cash_books`:
--
--   supplier_id  the supplier whose payments leave this book
--
-- NULL means "nobody in particular", which is every book in every shop today, so
-- no existing row changes behaviour.
--
-- WHY
-- Until now a payment to a supplier was booked against whichever book the till
-- fell back to - the shop's default - because nothing said otherwise. A shop that
-- pays one supplier from one account ("Rahim's bKash") can now name that account
-- on the cashbook, and the payment is booked out of it instead. The assignment is
-- made on the Create/Edit Cashbook form; the supplier's payment forms then open
-- with that book already chosen, and the person paying can still change it.
--
-- HOW TO RUN
-- In phpMyAdmin: select your database, open the SQL tab, and paste the single
-- statement below. If it answers "Duplicate column name", the app already added
-- it on first use - skip it, do not force it.
--
-- Nothing here deletes a row or changes a money value.

ALTER TABLE `cash_books`
  ADD COLUMN `supplier_id` INT DEFAULT NULL AFTER `type`,
  ADD KEY `idx_cb_supplier` (`supplier_id`);

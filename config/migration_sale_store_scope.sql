-- Super Admin cross-store sales: which store a sale belongs to, and double-tap protection
-- ============================================================
-- WHAT THIS DOES
-- Adds two columns to `sales`:
--
--   store_id         the store the goods physically came out of
--   idempotency_key  a token the till generates per basket, so the same sale cannot
--                    be written twice by a double tap or a retried request
--
-- plus two indexes.
--
-- WHY store_id
-- Until now the store of a sale was only knowable through the cashier: every report
-- joined sales -> users -> users.store_id. That holds while a shop's own staff rings
-- up its own goods. The platform Super Admin sells from any store and has no store
-- of their own, and one basket can hold goods from two stores (which becomes one
-- sale per store). So the store has to be written on the sale row itself.
--
-- The column is nullable and older rows stay NULL on purpose: every reader will be
-- switched to COALESCE(sales.store_id, users.store_id), so a sale from before this
-- file keeps reporting exactly where it reports today. The UPDATE at the bottom
-- fills the old rows anyway, but nothing depends on it.
--
-- There is deliberately no foreign key on store_id. A sale must outlive a store that
-- gets deleted - dropping the row's store would rewrite history in the reports, and
-- blocking the delete would make old stores undeletable.
--
-- WHY idempotency_key
-- The web POS, the staff app and the marketplace all wrap the sale in a transaction,
-- but nothing anywhere stopped the same basket being posted twice. It is a nullable
-- UNIQUE: many NULLs are allowed, so rows that predate this file (and the doors that
-- have not been given a token yet) are unaffected, while two posts carrying the same
-- token cannot both insert.
--
-- HOW TO RUN
-- In phpMyAdmin: select your database first, open the SQL tab, and paste ONE
-- statement at a time, in the order below. Check the affected-row count on each -
-- the green success banner also shows when only part of a pasted file ran.
--
-- Nothing here deletes a row or changes a money value. If the app has already added
-- a column by itself, that ALTER will error with "Duplicate column name" and the
-- fix is to skip that one statement, not to force it.

ALTER TABLE `sales`
  ADD COLUMN `store_id` INT(11) DEFAULT NULL AFTER `user_id`,
  ADD COLUMN `idempotency_key` VARCHAR(64) DEFAULT NULL AFTER `note`;

ALTER TABLE `sales`
  ADD KEY `idx_sales_owner_store` (`owner_id`, `store_id`, `created_at`);

ALTER TABLE `sales`
  ADD UNIQUE KEY `uq_sales_idempotency` (`idempotency_key`);

-- Optional, and safe to skip: give the old rows the store their cashier belonged to.
-- Rows whose cashier is not bound to a store (the Super Admin, a deleted account) are
-- left NULL, because guessing a store for them would invent history.
UPDATE `sales` s
JOIN `users` u ON u.`id` = s.`user_id`
SET s.`store_id` = u.`store_id`
WHERE s.`store_id` IS NULL
  AND u.`store_id` IS NOT NULL;

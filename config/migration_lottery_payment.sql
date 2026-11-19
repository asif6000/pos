-- Lottery Sale payment method
-- ============================================================
-- WHAT THIS DOES
-- Adds 'lottery' to `sales.payment_method`, so the new Lottery Sale card on the
-- POS checkout can be stored like every other method.
--
-- Existing rows keep their method untouched; only the enum list grows.
-- Safe to run more than once.
--
-- HOW TO RUN
-- phpMyAdmin > select the shop database > SQL tab > paste this and Go.
-- Nothing else needs migrating: the Lottery Sale list is the existing Sales list
-- with its payment filter pinned to this key.

ALTER TABLE sales
    MODIFY COLUMN payment_method
    ENUM('cash', 'bkash', 'nagad', 'rocket', 'card', 'bank', 'lottery')
    NOT NULL DEFAULT 'cash';

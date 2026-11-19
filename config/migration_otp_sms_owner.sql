-- ============================================================================
-- otp_sms_owner_id: which account pays for signup / reset / login-fallback OTP
-- ============================================================================
-- Run on the LIVE database in phpMyAdmin, one statement per execution.
-- This file is not uploaded to the server; it is a copy-paste source.
-- ============================================================================

-- 1) Which owners already have a real SMS gateway (not "manual")?
--    Use the owner_id this returns. Do not guess: a shop's SMS credit is spent
--    by these codes, so the account has to be the one meant to pay for them.
SELECT owner_id, setting_value AS provider
FROM settings
WHERE setting_key = 'marketing_sms_provider'
  AND setting_value <> 'manual';

-- 2) Replace 2 below with the owner_id from step 1, then run this alone.
INSERT INTO system_settings (setting_key, setting_value)
VALUES ('otp_sms_owner_id', '2')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- 3) Verify.
SELECT setting_key, setting_value
FROM system_settings
WHERE setting_key = 'otp_sms_owner_id';

-- ============================================================================
-- After this row exists, signup OTP and the number-login fallback use that
-- account's gateway (currently bulkSMSBD). The server's IP must be whitelisted
-- by the provider and the Sender ID registered, or sends fail with the
-- provider's own error.
-- ============================================================================

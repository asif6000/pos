<?php
/**
 * Marketing Module - shared settings + phone helpers
 * POS System - Bangladesh
 *
 * SMS campaign settings live in the settings table per owner, and the phone
 * normalising helpers are shared by the SMS gateway, the OTP flow and the
 * customer forms.
 */

require_once __DIR__ . '/db.php';

/** Default marketing settings for a shop that has never configured anything */
function getMarketingDefaults()
{
    return [
        'marketing_delay_min'  => '3',   // seconds between messages
        'marketing_delay_max'  => '8',
        'marketing_sender_name' => '',
    ];
}

/**
 * Strip a UTF-8 BOM and surrounding whitespace from a setting value.
 *
 * A stray BOM is easy to paste in (copying a token out of a file saved by a
 * Windows editor does it), so every value is cleaned on the way in.
 */
function marketingCleanValue($value)
{
    return trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$value));
}

/**
 * Read all marketing settings for one owner
 * @return array<string,string>
 */
function getMarketingSettings($ownerId)
{
    // Held for the rest of the request, so a save followed by a read in the
    // same request sees the new value.
    $cacheKey = (string)$ownerId;
    if (isset($GLOBALS['__marketing_settings_cache'][$cacheKey])) {
        return $GLOBALS['__marketing_settings_cache'][$cacheKey];
    }

    $settings = getMarketingDefaults();
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings
                              WHERE owner_id = ? AND setting_key LIKE 'marketing_%'");
        $stmt->execute([$ownerId]);
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = marketingCleanValue($row['setting_value']);
        }
    } catch (Exception $e) {
        error_log("Error reading marketing settings: " . $e->getMessage());
    }

    $GLOBALS['__marketing_settings_cache'][$cacheKey] = $settings;

    return $settings;
}

/**
 * Save a single marketing setting for one owner
 */
function saveMarketingSetting($key, $value, $ownerId)
{
    if (!preg_match('/^marketing_[a-z_]+$/', $key)) {
        return false;
    }

    // Drop the per-request cache before writing, so anything that reads these
    // settings back later in the same request sees the new value.
    marketingForgetSettingsCache($ownerId);

    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value, owner_id)
                              VALUES (?, ?, ?)
                              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$key, marketingCleanValue($value), $ownerId]);
        return true;
    } catch (Exception $e) {
        error_log("Error saving marketing setting [$key]: " . $e->getMessage());
        return false;
    }
}

/**
 * Forget the cached settings for one owner.
 *
 * Only the per-owner entry is dropped: a request that touches two owners keeps
 * the other one's still-valid entry.
 */
function marketingForgetSettingsCache($ownerId)
{
    unset($GLOBALS['__marketing_settings_cache'][(string)$ownerId]);
}

/**
 * Normalise a Bangladeshi phone number for storage
 * Accepts 01712345678, +8801712345678, 8801712345678, 8801712345678
 * @return string  digits only, e.g. 8801712345678 (or '' when unusable)
 */
function marketingNormalizePhone($raw)
{
    $digits = preg_replace('/[^0-9]/', '', (string)$raw);
    if ($digits === '') {
        return '';
    }
    // 8801712345678
    if (strpos($digits, '880') === 0 && strlen($digits) === 13) {
        return $digits;
    }
    // 01712345678
    if (strlen($digits) === 11 && $digits[0] === '0') {
        return '880' . substr($digits, 1);
    }
    // 1712345678 (trunk prefix dropped)
    if (strlen($digits) === 10 && $digits[0] === '1') {
        return '880' . $digits;
    }
    return $digits;
}

/** Display form of a stored number, e.g. 8801712345678 -> 01712-345678 */
function marketingFormatPhone($stored)
{
    $d = preg_replace('/[^0-9]/', '', (string)$stored);
    if (strlen($d) === 13 && strpos($d, '880') === 0) {
        $d = '0' . substr($d, 3);
    }
    if (strlen($d) === 11) {
        return substr($d, 0, 4) . '-' . substr($d, 4, 4) . '-' . substr($d, 8, 3);
    }
    return (string)$stored;
}

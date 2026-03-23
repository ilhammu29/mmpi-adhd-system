<?php
// includes/test_mode_config.php

/**
 * TEST MODE CONFIGURATION
 * Set to false when payment system is ready
 */

define('TEST_MODE_ENABLED', defined('TEST_MODE') ? TEST_MODE : false);
define('REQUIRE_PAYMENT', !TEST_MODE_ENABLED);
define('TEST_MODE_EXPIRY_DAYS', 30); // Package expiry in test mode
define('TEST_MODE_MAX_TESTS', 10); // Max tests per user in test mode

/**
 * Check if test mode is enabled
 */
function isTestMode() {
    return TEST_MODE_ENABLED;
}

/**
 * Check if payment is required
 */
function requirePayment() {
    return REQUIRE_PAYMENT;
}

/**
 * Get test mode expiry date
 */
function getTestModeExpiryDate() {
    return date('Y-m-d H:i:s', strtotime('+' . TEST_MODE_EXPIRY_DAYS . ' days'));
}

/**
 * Check if user can take more tests in test mode
 */
function canTakeMoreTests($userId) {
    if (!isTestMode()) return true;
    
    global $db;
    $stmt = $db->prepare("
        SELECT COUNT(*) as test_count 
        FROM test_results 
        WHERE user_id = ? 
        AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt->execute([$userId, TEST_MODE_EXPIRY_DAYS]);
    $result = $stmt->fetch();
    
    return $result['test_count'] < TEST_MODE_MAX_TESTS;
}

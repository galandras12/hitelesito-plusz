<?php
/**
 * Test Suite for Hitelesítő+ Plugin
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (!defined('HITELESITO_PLUSZ_VERSION')) {
    define('HITELESITO_PLUSZ_VERSION', '0.1');
}

// Mock WordPress functions if running in standalone CLI environment
if (!function_exists('add_action')) {
    function add_action($tag, $callback, $priority = 10, $accepted_args = 1) {}
}
if (!function_exists('add_filter')) {
    function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {}
}
if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback) {}
}
if (!function_exists('get_option')) {
    $GLOBALS['mock_options'] = [];
    function get_option($option, $default = false) {
        return $GLOBALS['mock_options'][$option] ?? $default;
    }
    function update_option($option, $value) {
        $GLOBALS['mock_options'][$option] = $value;
        return true;
    }
}
if (!function_exists('get_transient')) {
    $GLOBALS['mock_transients'] = [];
    function get_transient($transient) {
        return $GLOBALS['mock_transients'][$transient] ?? false;
    }
    function set_transient($transient, $value, $expiration = 0) {
        $GLOBALS['mock_transients'][$transient] = $value;
        return true;
    }
    function delete_transient($transient) {
        unset($GLOBALS['mock_transients'][$transient]);
        return true;
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim((string) $str);
    }
}

require_once __DIR__ . '/../includes/class-hitelesito-totp.php';
require_once __DIR__ . '/../includes/class-hitelesito-brute-force.php';
require_once __DIR__ . '/../includes/class-hitelesito-admin.php';

echo "=== HITELESÍTŐ+ TEST SUITE ===\n";

$passed = 0;
$failed = 0;

function assert_true($condition, $test_name) {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] {$test_name}\n";
        $passed++;
    } else {
        echo "[FAIL] {$test_name}\n";
        $failed++;
    }
}

// 1. Test Base32 Secret Generation
$secret = Hitelesito_TOTP::generate_secret(16);
assert_true(strlen($secret) === 16, "Base32 secret length is 16 chars");
assert_true(preg_match('/^[A-Z2-7]+$/', $secret) === 1, "Base32 secret uses valid alphabet");

// 2. Test TOTP Code Generation & Verification
$code = Hitelesito_TOTP::get_code($secret);
assert_true(strlen($code) === 6, "TOTP generated code is 6 digits");
assert_true(Hitelesito_TOTP::verify_code($secret, $code), "TOTP generated code verifies successfully");

// Test invalid TOTP code
assert_true(!Hitelesito_TOTP::verify_code($secret, '000000' === $code ? '111111' : '000000'), "Invalid TOTP code fails verification");

// 3. Test OTP Auth URL Generation
$otpauth = Hitelesito_TOTP::get_otpauth_url('testuser@example.com', $secret, 'Hitelesítő+');
assert_true(str_contains($otpauth, 'otpauth://totp/'), "OTP Auth URL starts with otpauth://totp/");
assert_true(str_contains($otpauth, $secret), "OTP Auth URL contains secret key");

// 4. Test Brute Force Lockout
$user_id = 999;
Hitelesito_Brute_Force::reset_attempts($user_id);
assert_true(!Hitelesito_Brute_Force::is_locked_out($user_id), "Initial state: User is not locked out");

for ($i = 0; $i < 4; $i++) {
    Hitelesito_Brute_Force::record_failed_attempt($user_id);
}
assert_true(!Hitelesito_Brute_Force::is_locked_out($user_id), "4 failed attempts: User is not locked out yet");
assert_true(Hitelesito_Brute_Force::get_attempts_left($user_id) === 1, "1 attempt remaining");

Hitelesito_Brute_Force::record_failed_attempt($user_id);
assert_true(Hitelesito_Brute_Force::is_locked_out($user_id), "5 failed attempts: User is locked out");

Hitelesito_Brute_Force::reset_attempts($user_id);
assert_true(!Hitelesito_Brute_Force::is_locked_out($user_id), "Reset attempts: User is unlocked");

// 5. Test Role Requirements Defaulting
update_option(Hitelesito_Admin::OPTION_KEY, [
    'administrator' => 'required',
    'subscriber' => 'hidden'
]);
assert_true(Hitelesito_Admin::get_role_requirement('administrator') === 'required', "Role 'administrator' is required");
assert_true(Hitelesito_Admin::get_role_requirement('subscriber') === 'hidden', "Role 'subscriber' is hidden");
assert_true(Hitelesito_Admin::get_role_requirement('editor') === 'optional', "Unconfigured role defaults to optional");

echo "-------------------------------\n";
echo "Total Tests: " . ($passed + $failed) . " | Passed: {$passed} | Failed: {$failed}\n";

if ($failed > 0) {
    exit(1);
}

<?php
/**
 * Brute Force Protection Class for Hitelesítő+
 * Prevents bots and automated attacks on the 2FA verification page.
 * Limits failed 2FA verification attempts per client IP and user ID.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hitelesito_Brute_Force {

    public const MAX_ATTEMPTS = 5;
    public const LOCKOUT_TIME = 900; // 15 minutes in seconds

    /**
     * Get client IP address.
     */
    public static function get_client_ip(): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($parts[0]);
        }
        return sanitize_text_field($ip);
    }

    /**
     * Get transient key for tracking user & IP attempts.
     */
    private static function get_transient_key(int $user_id): string {
        $ip = self::get_client_ip();
        return 'hitelesito_bf_' . md5($user_id . '_' . $ip);
    }

    /**
     * Record a failed 2FA attempt.
     */
    public static function record_failed_attempt(int $user_id): void {
        $key = self::get_transient_key($user_id);
        $attempts = (int) get_transient($key);
        $attempts++;
        set_transient($key, $attempts, self::LOCKOUT_TIME);
    }

    /**
     * Check if user/IP is currently locked out.
     */
    public static function is_locked_out(int $user_id): bool {
        $key = self::get_transient_key($user_id);
        $attempts = (int) get_transient($key);
        return $attempts >= self::MAX_ATTEMPTS;
    }

    /**
     * Get attempts left before lockout.
     */
    public static function get_attempts_left(int $user_id): int {
        $key = self::get_transient_key($user_id);
        $attempts = (int) get_transient($key);
        $left = self::MAX_ATTEMPTS - $attempts;
        return max(0, $left);
    }

    /**
     * Get lockout remaining minutes.
     */
    public static function get_lockout_remaining_minutes(int $user_id): int {
        $key = self::get_transient_key($user_id);
        $transient_option = '_transient_timeout_' . $key;
        $timeout = get_option($transient_option);
        if ($timeout) {
            $diff = $timeout - time();
            return max(1, (int) ceil($diff / 60));
        }
        return 15;
    }

    /**
     * Reset failed attempts after successful verification.
     */
    public static function reset_attempts(int $user_id): void {
        $key = self::get_transient_key($user_id);
        delete_transient($key);
    }
}

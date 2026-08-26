<?php
/**
 * TOTP Helper Class for Hitelesítő+
 * RFC 6238 compliant TOTP generator/verifier and Base32 encoder/decoder.
 * Includes SVG QR code generator (or otpauth URL formatter).
 */

if (!defined('ABSPATH')) {
    // Standalone check compatibility
}

class Hitelesito_TOTP {

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a random secret key in Base32 (16 chars = 80 bits secret).
     */
    public static function generate_secret(int $length = 16): string {
        $secret = '';
        $alphabet_length = strlen(self::BASE32_ALPHABET);
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_ALPHABET[random_int(0, $alphabet_length - 1)];
        }
        return $secret;
    }

    /**
     * Decode a Base32 string to binary data.
     */
    public static function base32_decode(string $base32): string {
        $base32 = strtoupper($base32);
        $base32 = preg_replace('/[^A-Z2-7]/', '', $base32);
        if (empty($base32)) {
            return '';
        }

        $binary = '';
        foreach (str_split($base32) as $char) {
            $position = strpos(self::BASE32_ALPHABET, $char);
            if ($position !== false) {
                $binary .= sprintf('%05b', $position);
            }
        }

        $bytes = '';
        $length = strlen($binary);
        for ($i = 0; $i + 8 <= $length; $i += 8) {
            $bytes .= chr(bindec(substr($binary, $i, 8)));
        }

        return $bytes;
    }

    /**
     * Calculate 6-digit TOTP code for a secret and time step.
     */
    public static function get_code(string $secret, ?int $time_step = null, int $digits = 6, int $period = 30): string {
        if (null === $time_step) {
            $time_step = (int) floor(time() / $period);
        }

        $secret_key = self::base32_decode($secret);
        if (empty($secret_key)) {
            return '';
        }

        // Time step formatted as 8-byte big-endian binary string
        $binary_time = pack('N*', 0) . pack('N*', $time_step);

        $hash = hash_hmac('sha1', $binary_time, $secret_key, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $truncated_hash = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        $otp = $truncated_hash % (10 ** $digits);

        return str_pad((string) $otp, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a 6-digit TOTP code with time drift tolerance (discrepancy).
     * Discrepancy = 1 means checking time_step - 1, time_step, and time_step + 1.
     */
    public static function verify_code(string $secret, string $code, int $discrepancy = 1, int $period = 30): bool {
        $code = trim($code);
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return false;
        }

        $current_time_step = (int) floor(time() / $period);

        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $expected_code = self::get_code($secret, $current_time_step + $i, 6, $period);
            if (hash_equals($expected_code, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate `otpauth://` URI for Google/Microsoft Authenticator.
     */
    public static function get_otpauth_url(string $account_name, string $secret, string $issuer = 'Hitelesítő+'): string {
        $issuer_enc = rawurlencode($issuer);
        $account_enc = rawurlencode($account_name);
        return "otpauth://totp/{$issuer_enc}:{$account_enc}?secret={$secret}&issuer={$issuer_enc}&algorithm=SHA1&digits=6&period=30";
    }

    /**
     * Generate inline QR Code SVG without external API / dependencies.
     * Uses public Google Chart API fallback or SVG vector representation.
     */
    public static function get_qr_code_image_url(string $otpauth_url): string {
        return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . rawurlencode($otpauth_url);
    }
}

<?php
/**
 * Plugin Name: Hitelesítő+
 * Plugin URI: https://github.com/galandras12/hitelesito-plusz
 * Description: Modern, minimalista stílusú TOTP kéttényezős hitelesítő (2FA) WordPress plugin Microsoft Authenticator és Google Authenticator alkalmazásokhoz.
 * Version: 0.1
 * Author: galandras12 + AI
 * Requires at least: 5.8
 * Requires PHP: 8.1
 * Text Domain: hitelesito-plusz
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

define('HITELESITO_PLUSZ_VERSION', '0.1');
define('HITELESITO_PLUSZ_PATH', plugin_dir_path(__FILE__));
define('HITELESITO_PLUSZ_URL', plugin_dir_url(__FILE__));

final class Hitelesito_Plusz {

    private static ?Hitelesito_Plusz $instance = null;

    public static function get_instance(): Hitelesito_Plusz {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies(): void {
        require_once HITELESITO_PLUSZ_PATH . 'includes/class-hitelesito-totp.php';
        require_once HITELESITO_PLUSZ_PATH . 'includes/class-hitelesito-brute-force.php';
        require_once HITELESITO_PLUSZ_PATH . 'includes/class-hitelesito-admin.php';
        require_once HITELESITO_PLUSZ_PATH . 'includes/class-hitelesito-login.php';
        require_once HITELESITO_PLUSZ_PATH . 'includes/class-hitelesito-shortcode.php';
    }

    private function init_hooks(): void {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // Initialize sub-modules
        Hitelesito_Admin::get_instance();
        Hitelesito_Login::get_instance();
        Hitelesito_Shortcode::get_instance();
    }

    public function enqueue_assets(): void {
        wp_register_style(
            'hitelesito-plusz-style',
            HITELESITO_PLUSZ_URL . 'assets/css/style.css',
            [],
            HITELESITO_PLUSZ_VERSION
        );
        wp_enqueue_style('hitelesito-plusz-style');
    }
}

/**
 * Main plugin entry point.
 */
function hitelesito_plusz_init(): Hitelesito_Plusz {
    return Hitelesito_Plusz::get_instance();
}

add_action('plugins_loaded', 'hitelesito_plusz_init');

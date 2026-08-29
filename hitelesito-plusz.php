<?php
/**
 * Plugin Name: Hitelesítő+
 * Plugin URI: https://github.com/galandras12/hitelesito-plusz
 * Description: Többfaktoros hitelesítés (TOTP, e-mail kód, Passkey/WebAuthn, biztonsági mentési kódok) szerepkör alapú kötelezővé tételi lehetőséggel, bejelentkezés utáni átirányításos hitelesítő felülettel és opcionális brute force védelemmel.
 * Version: 1.7
 * Author: galandras12+AI
 * Author URI: https://github.com/galandras12
 * License: GPLv2 or later
 * Text Domain: hitelesito-plusz
 * Domain Path: /languages
 * Requires PHP: 8.0
 * Requires at least: 5.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'H2F_VERSION', '1.7' );
define( 'H2F_PLUGIN_FILE', __FILE__ );
define( 'H2F_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'H2F_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'H2F_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Osztályok betöltése.
 */
require_once H2F_PLUGIN_DIR . 'includes/class-h2f-db.php';
require_once H2F_PLUGIN_DIR . 'includes/class-h2f-settings.php';
require_once H2F_PLUGIN_DIR . 'includes/class-h2f-totp.php';
require_once H2F_PLUGIN_DIR . 'includes/class-h2f-qrcode.php';
require_once H2F_PLUGIN_DIR . 'includes/class-h2f-backup-codes.php';
require_once H2F_PLUGIN_DIR . 'includes/class-h2f-email-2fa.php';
require_once H2F_PLUGIN_DIR . 'includes/class-h2f-webauthn-helper.php';
require_once H2F_PLUGIN_DIR . 'includes/class-h2f-passkey.php';
require_once H2F_PLUGIN_DIR . 'includes/class-h2f-brute-force.php';
require_once H2F_PLUGIN_DIR . 'includes/class-h2f-admin-alert.php';
require_once H2F_PLUGIN_DIR . 'includes/class-h2f-login-flow.php';
require_once H2F_PLUGIN_DIR . 'includes/class-h2f-user-profile.php';
require_once H2F_PLUGIN_DIR . 'includes/class-h2f-admin.php';
require_once H2F_PLUGIN_DIR . 'includes/class-h2f-shortcode.php';
require_once H2F_PLUGIN_DIR . 'includes/class-h2f-ajax.php';

/**
 * Aktiváláskor: táblák létrehozása, alap beállítások.
 */
function h2f_activate() {
	H2F_DB::create_tables();
	H2F_Settings::maybe_set_defaults();
	if ( ! wp_next_scheduled( 'h2f_cleanup_event' ) ) {
		wp_schedule_event( time(), 'hourly', 'h2f_cleanup_event' );
	}
}
register_activation_hook( __FILE__, 'h2f_activate' );

/**
 * Deaktiváláskor: ütemezett feladat törlése (adatokat NEM töröljük).
 */
function h2f_deactivate() {
	wp_clear_scheduled_hook( 'h2f_cleanup_event' );
}
register_deactivation_hook( __FILE__, 'h2f_deactivate' );

/**
 * Lejárt e-mail kódok / brute force naplók takarítása óránként.
 */
add_action( 'h2f_cleanup_event', array( 'H2F_Email_2FA', 'cleanup_expired_codes' ) );
add_action( 'h2f_cleanup_event', array( 'H2F_Brute_Force', 'cleanup_old_attempts' ) );

/**
 * Nyelvi fájlok betöltése.
 */
add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'hitelesito-plusz', false, dirname( H2F_PLUGIN_BASENAME ) . '/languages' );
} );

/**
 * Fő inicializálás.
 */
function h2f_init() {
	H2F_Settings::init();
	H2F_Brute_Force::init();
	H2F_Login_Flow::init();
	H2F_Passkey::init();
	H2F_User_Profile::init();
	H2F_Ajax::init();

	if ( is_admin() ) {
		H2F_Admin::init();
	}

	H2F_Shortcode::init();
}
add_action( 'init', 'h2f_init' );

/**
 * Beállítások link a plugin listában.
 */
add_filter( 'plugin_action_links_' . H2F_PLUGIN_BASENAME, function ( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=hitelesito-plusz' ) ) . '">' . esc_html__( 'Beállítások', 'hitelesito-plusz' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
} );
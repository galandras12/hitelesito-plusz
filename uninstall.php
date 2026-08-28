<?php
/**
 * Eltávolításkor (nem csak deaktiváláskor) fut le. Csak akkor töröljük az
 * összes hitelesítési adatot, ha a plugint ténylegesen törlik a
 * WordPress admin felületéről.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'h2f_totp',
	$wpdb->prefix . 'h2f_backup_codes',
	$wpdb->prefix . 'h2f_passkeys',
	$wpdb->prefix . 'h2f_email_codes',
	$wpdb->prefix . 'h2f_login_attempts',
	$wpdb->prefix . 'h2f_webauthn_challenges',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

delete_option( 'h2f_settings' );
delete_option( 'h2f_db_version' );

// Felhasználói meta mezők eltávolítása.
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'h2f_email_enabled'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

wp_clear_scheduled_hook( 'h2f_cleanup_event' );

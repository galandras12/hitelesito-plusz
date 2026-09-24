<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Egyéni adatbázis táblák létrehozása és elérése.
 */
class H2F_DB {

	public static function table_totp() {
		global $wpdb;
		return $wpdb->prefix . 'h2f_totp';
	}

	public static function table_backup_codes() {
		global $wpdb;
		return $wpdb->prefix . 'h2f_backup_codes';
	}

	public static function table_passkeys() {
		global $wpdb;
		return $wpdb->prefix . 'h2f_passkeys';
	}

	public static function table_email_codes() {
		global $wpdb;
		return $wpdb->prefix . 'h2f_email_codes';
	}

	public static function table_login_attempts() {
		global $wpdb;
		return $wpdb->prefix . 'h2f_login_attempts';
	}

	public static function table_webauthn_challenges() {
		global $wpdb;
		return $wpdb->prefix . 'h2f_webauthn_challenges';
	}

	/**
	 * Önjavítás: ha a tárolt séma-verzió nem egyezik a futó plugin
	 * verziójával, újra lefuttatjuk a (idempotens) tábla-létrehozást.
	 *
	 * A `register_activation_hook` KIZÁRÓLAG az inaktív -> aktív
	 * állapotváltáskor fut le. Egy már aktív bővítmény helyben történő
	 * frissítése - akár a wp-admin "Feltöltés -> Csere a feltöltöttre"
	 * funkciójával, akár FTP-vel, akár bármilyen más fájlcserével - ezt
	 * SOHA nem váltja ki, tehát az aktiváláskor lefutó `create_tables()`
	 * egy ilyen frissítés után elmaradhat. Ez a metódus ettől függetlenül,
	 * minden kérésnél ellenőrzi és szükség esetén pótolja a táblákat, hogy
	 * egy frissítés se hagyhassa tábla nélkül (és ezzel 2FA nélkül) az
	 * oldalt, függetlenül attól, hogyan történt a telepítés.
	 */
	public static function maybe_create_tables() {
		if ( get_option( 'h2f_db_version' ) === H2F_VERSION ) {
			return;
		}

		self::create_tables();
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$sql = array();

		$sql[] = "CREATE TABLE {$wpdb->prefix}h2f_totp (
			user_id BIGINT UNSIGNED NOT NULL,
			secret VARCHAR(64) NOT NULL,
			confirmed TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (user_id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$wpdb->prefix}h2f_backup_codes (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			code_hash VARCHAR(255) NOT NULL,
			used TINYINT(1) NOT NULL DEFAULT 0,
			used_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$wpdb->prefix}h2f_passkeys (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			credential_id TEXT NOT NULL,
			public_key TEXT NOT NULL,
			sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			device_name VARCHAR(191) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			last_used_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$wpdb->prefix}h2f_email_codes (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			code_hash VARCHAR(255) NOT NULL,
			expires_at DATETIME NOT NULL,
			used TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$wpdb->prefix}h2f_login_attempts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ip_address VARCHAR(45) NOT NULL,
			username VARCHAR(191) NOT NULL DEFAULT '',
			attempted_at DATETIME NOT NULL,
			success TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY ip_address (ip_address),
			KEY attempted_at (attempted_at)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$wpdb->prefix}h2f_webauthn_challenges (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			challenge VARCHAR(255) NOT NULL,
			type VARCHAR(20) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id)
		) $charset_collate;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( 'h2f_db_version', H2F_VERSION );
	}
}

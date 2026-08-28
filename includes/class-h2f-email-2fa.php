<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * E-mailben kiküldött egyszer használatos kód kezelése.
 */
class H2F_Email_2FA {

	/**
	 * Új kód generálása, elmentése és kiküldése.
	 */
	public static function send_code( $user ) {
		if ( ! $user instanceof WP_User ) {
			$user = get_userdata( $user );
		}
		if ( ! $user ) {
			return false;
		}

		global $wpdb;

		// Korábbi, még fel nem használt kódok érvénytelenítése.
		$wpdb->update(
			H2F_DB::table_email_codes(),
			array( 'used' => 1 ),
			array( 'user_id' => $user->ID, 'used' => 0 ),
			array( '%d' ),
			array( '%d', '%d' )
		);

		$code       = self::generate_code();
		$lifetime   = (int) H2F_Settings::get( 'email_code_lifetime', 900 );
		$expires_at = date( 'Y-m-d H:i:s', time() + $lifetime );

		$wpdb->insert(
			H2F_DB::table_email_codes(),
			array(
				'user_id'    => $user->ID,
				'code_hash'  => wp_hash_password( $code ),
				'expires_at' => $expires_at,
				'used'       => 0,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%d', '%s' )
		);

		self::mail_code( $user, $code, $lifetime );

		return true;
	}

	protected static function generate_code() {
		return str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
	}

	protected static function mail_code( $user, $code, $lifetime_seconds ) {
		$subject = H2F_Settings::get( 'email_subject', __( 'A kétfaktoros hitelesítő kódod', 'hitelesito-plusz' ) );
		$body    = H2F_Settings::get( 'email_body', H2F_Settings::default_email_body() );

		$replacements = array(
			'{code}'         => $code,
			'{display_name}' => $user->display_name,
			'{user_login}'   => $user->user_login,
			'{site_name}'    => get_bloginfo( 'name' ),
			'{lifetime}'     => (string) round( $lifetime_seconds / 60 ),
		);

		$subject = strtr( $subject, $replacements );
		$body    = strtr( $body, $replacements );

		add_filter( 'wp_mail_content_type', array( __CLASS__, 'set_html_content_type' ) );
		wp_mail( $user->user_email, $subject, $body );
		remove_filter( 'wp_mail_content_type', array( __CLASS__, 'set_html_content_type' ) );
	}

	public static function set_html_content_type() {
		return 'text/html';
	}

	/**
	 * Kód ellenőrzése és felhasználtnak jelölése.
	 */
	public static function verify_and_consume( $user_id, $code ) {
		global $wpdb;

		$code = trim( $code );

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . H2F_DB::table_email_codes() . "
			 WHERE user_id = %d AND used = 0 AND expires_at >= %s
			 ORDER BY id DESC LIMIT 1",
			$user_id,
			current_time( 'mysql' )
		) );

		if ( ! $row ) {
			return false;
		}

		if ( ! wp_check_password( $code, $row->code_hash ) ) {
			return false;
		}

		$wpdb->update(
			H2F_DB::table_email_codes(),
			array( 'used' => 1 ),
			array( 'id' => $row->id ),
			array( '%d' ),
			array( '%d' )
		);

		return true;
	}

	public static function cleanup_expired_codes() {
		global $wpdb;
		$wpdb->query(
			"DELETE FROM " . H2F_DB::table_email_codes() . " WHERE expires_at < NOW() - INTERVAL 1 DAY"
		);
	}

	public static function disable( $user_id ) {
		global $wpdb;
		$wpdb->delete( H2F_DB::table_email_codes(), array( 'user_id' => $user_id ), array( '%d' ) );
	}
}

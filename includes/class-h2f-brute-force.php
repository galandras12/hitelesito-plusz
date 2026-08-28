<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opcionális brute force védelem a bejelentkezéshez.
 * IP + felhasználónév alapú próbálkozás-számlálás, ideiglenes zárolással.
 */
class H2F_Brute_Force {

	public static function init() {
		add_filter( 'authenticate', array( __CLASS__, 'check_lockout' ), 20, 3 );
		add_action( 'wp_login_failed', array( __CLASS__, 'log_failed_attempt' ) );
		add_action( 'wp_login', array( __CLASS__, 'log_successful_attempt' ), 10, 2 );
	}

	protected static function is_enabled() {
		return (bool) H2F_Settings::get( 'brute_force_enabled', 1 );
	}

	public static function get_ip() {
		$candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
		foreach ( $candidates as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}

	public static function check_lockout( $user, $username, $password ) {
		if ( ! self::is_enabled() || empty( $username ) ) {
			return $user;
		}

		if ( self::is_locked_out( self::get_ip(), $username ) ) {
			return new WP_Error(
				'h2f_locked_out',
				__( '<strong>Hiba:</strong> Túl sok sikertelen bejelentkezési kísérlet történt. Kérjük, próbáld újra később.', 'hitelesito-plusz' )
			);
		}

		return $user;
	}

	public static function is_locked_out( $ip, $username ) {
		global $wpdb;

		$window_minutes  = (int) H2F_Settings::get( 'brute_force_window', 15 );
		$max_tries       = (int) H2F_Settings::get( 'brute_force_max_tries', 5 );
		$since           = date( 'Y-m-d H:i:s', time() - ( $window_minutes * 60 ) );

		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . H2F_DB::table_login_attempts() . "
			 WHERE ( ip_address = %s OR username = %s ) AND success = 0 AND attempted_at >= %s",
			$ip,
			$username,
			$since
		) );

		return $count >= $max_tries;
	}

	public static function log_failed_attempt( $username ) {
		if ( ! self::is_enabled() ) {
			return;
		}
		global $wpdb;
		$wpdb->insert(
			H2F_DB::table_login_attempts(),
			array(
				'ip_address'   => self::get_ip(),
				'username'     => sanitize_text_field( $username ),
				'attempted_at' => current_time( 'mysql' ),
				'success'      => 0,
			),
			array( '%s', '%s', '%s', '%d' )
		);
	}

	public static function log_successful_attempt( $username, $user ) {
		if ( ! self::is_enabled() ) {
			return;
		}
		global $wpdb;
		$wpdb->insert(
			H2F_DB::table_login_attempts(),
			array(
				'ip_address'   => self::get_ip(),
				'username'     => sanitize_text_field( $username ),
				'attempted_at' => current_time( 'mysql' ),
				'success'      => 1,
			),
			array( '%s', '%s', '%s', '%d' )
		);
	}

	public static function cleanup_old_attempts() {
		global $wpdb;
		$lockout_minutes = max( (int) H2F_Settings::get( 'brute_force_window', 15 ), (int) H2F_Settings::get( 'brute_force_lockout', 30 ) );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM " . H2F_DB::table_login_attempts() . " WHERE attempted_at < %s",
			date( 'Y-m-d H:i:s', time() - ( $lockout_minutes * 60 * 4 ) )
		) );
	}
}

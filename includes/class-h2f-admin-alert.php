<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Értesítő e-mail küldése a beállított admin címekre, ha egy felhasználó
 * a bejelentkezés utáni kétfaktoros hitelesítést egymás után többször
 * (alapértelmezetten 5 alkalommal) elrontja. A számláló sikeres
 * hitelesítéskor vagy a riasztás kiküldése után nullázódik, hogy ne
 * kapjon admin minden egyes további sikertelen próbálkozásnál újabb levelet.
 */
class H2F_Admin_Alert {

	const COUNTER_TTL = 30 * MINUTE_IN_SECONDS;

	protected static function counter_key( $user_id ) {
		return 'h2f_2fa_fail_count_' . $user_id;
	}

	public static function is_enabled() {
		return (bool) H2F_Settings::get( 'admin_alert_enabled', 0 );
	}

	public static function get_threshold() {
		$threshold = (int) H2F_Settings::get( 'admin_alert_threshold', 5 );
		return max( 1, $threshold );
	}

	public static function get_recipient_emails() {
		$raw = (string) H2F_Settings::get( 'admin_alert_emails', get_option( 'admin_email' ) );
		$emails = array_filter( array_map( 'trim', preg_split( '/[,;\n]+/', $raw ) ) );
		$emails = array_filter( $emails, 'is_email' );
		if ( empty( $emails ) ) {
			$emails = array( get_option( 'admin_email' ) );
		}
		return $emails;
	}

	/**
	 * Sikertelen kétfaktoros próbálkozás rögzítése. Ha eléri a küszöböt,
	 * riasztást küld és nullázza a számlálót.
	 */
	public static function record_failure( $user_id, $method ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		$key   = self::counter_key( $user_id );
		$count = (int) get_transient( $key );
		$count++;

		set_transient( $key, $count, self::COUNTER_TTL );

		if ( $count >= self::get_threshold() ) {
			self::send_alert( $user_id, $method, $count );
			delete_transient( $key );
		}
	}

	/**
	 * Sikeres hitelesítéskor a számláló nullázása.
	 */
	public static function reset_counter( $user_id ) {
		delete_transient( self::counter_key( $user_id ) );
	}

	protected static function method_label( $method ) {
		$methods = H2F_Settings::methods();
		$methods['backup'] = __( 'Biztonsági mentési kód', 'hitelesito-plusz' );
		return isset( $methods[ $method ] ) ? $methods[ $method ] : $method;
	}

	protected static function send_alert( $user_id, $method, $count ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Gyanús, ismételten sikertelen kétfaktoros hitelesítési kísérlet', 'hitelesito-plusz' ),
			get_bloginfo( 'name' )
		);

		$body = sprintf(
			/* translators: 1: user display name, 2: username, 3: attempt count, 4: method label, 5: IP address, 6: date/time */
			__(
				"Biztonsági figyelmeztetés a(z) %6\$s oldalon.\n\n" .
				"Felhasználó: %1\$s (%2\$s)\n" .
				"Sikertelen kétfaktoros hitelesítési kísérletek száma: %3\$d\n" .
				"Legutóbb próbált hitelesítő módszer: %4\$s\n" .
				"IP-cím: %5\$s\n" .
				"Időpont: %7\$s\n\n" .
				"Javasolt ellenőrizni, hogy valóban a felhasználó próbál-e bejelentkezni, vagy illetéktelen hozzáférési kísérlet történik.",
				'hitelesito-plusz'
			),
			$user->display_name,
			$user->user_login,
			$count,
			self::method_label( $method ),
			H2F_Brute_Force::get_ip(),
			get_bloginfo( 'name' ),
			date_i18n( 'Y-m-d H:i:s' )
		);

		foreach ( self::get_recipient_emails() as $email ) {
			wp_mail( $email, $subject, $body );
		}
	}
}
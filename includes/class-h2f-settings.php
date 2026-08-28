<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Központi beállítás-kezelő.
 *
 * Egyetlen "h2f_settings" opcióban tárolunk mindent (JSON-szerű tömb),
 * hogy egyszerű legyen a mentés/betöltés az admin felületről.
 */
class H2F_Settings {

	const OPTION_KEY = 'h2f_settings';

	public static function init() {
		// Jelenleg nincs külön hook, a beállításokat az admin osztály menti.
	}

	/**
	 * Elérhető hitelesítési módok.
	 */
	public static function methods() {
		return array(
			'totp'    => __( 'TOTP (hitelesítő alkalmazás)', 'hitelesito-plusz' ),
			'email'   => __( 'E-mail kód', 'hitelesito-plusz' ),
			'passkey' => __( 'Passkey', 'hitelesito-plusz' ),
		);
	}

	/**
	 * Szerepkör-mátrix lehetséges állapotai.
	 */
	public static function policy_states() {
		return array(
			'required' => __( 'Kötelező', 'hitelesito-plusz' ),
			'optional' => __( 'Opcionális', 'hitelesito-plusz' ),
			'hidden'   => __( 'Rejtett', 'hitelesito-plusz' ),
		);
	}

	public static function email_lifetime_options() {
		return array(
			900   => __( '15 perc', 'hitelesito-plusz' ),
			1800  => __( '30 perc', 'hitelesito-plusz' ),
			3600  => __( '1 óra', 'hitelesito-plusz' ),
			7200  => __( '2 óra', 'hitelesito-plusz' ),
			14400 => __( '4 óra', 'hitelesito-plusz' ),
			28800 => __( '8 óra', 'hitelesito-plusz' ),
			43200 => __( '12 óra', 'hitelesito-plusz' ),
		);
	}

	public static function default_email_body() {
		return "<p>Kedves {display_name}!</p>\n<p>A bejelentkezéshez szükséges kétfaktoros hitelesítő kódod:</p>\n<p style=\"font-size:28px;font-weight:bold;letter-spacing:4px;\">{code}</p>\n<p>A kód {lifetime} percig érvényes. Ha nem te kezdeményezted a bejelentkezést, hagyd figyelmen kívül ezt az üzenetet.</p>\n<p>Üdvözlettel,<br>{site_name}</p>";
	}

	public static function defaults() {
		$roles = array();
		if ( function_exists( 'get_editable_roles' ) ) {
			foreach ( get_editable_roles() as $slug => $data ) {
				$roles[ $slug ] = array(
					'totp'    => 'optional',
					'email'   => 'optional',
					'passkey' => 'optional',
				);
			}
		}

		return array(
			'role_policy'           => $roles,
			'email_subject'         => __( 'A kétfaktoros hitelesítő kódod', 'hitelesito-plusz' ),
			'email_body'            => self::default_email_body(),
			'email_code_lifetime'   => 900,
			'brute_force_enabled'   => 1,
			'brute_force_max_tries' => 5,
			'brute_force_window'    => 15,  // perc
			'brute_force_lockout'   => 30,  // perc
			'admin_alert_enabled'   => 0,
			'admin_alert_threshold' => 5,
			'admin_alert_emails'    => get_option( 'admin_email' ),
		);
	}

	public static function maybe_set_defaults() {
		if ( false === get_option( self::OPTION_KEY, false ) ) {
			add_option( self::OPTION_KEY, self::defaults() );
		}
	}

	public static function get_all() {
		$settings = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $settings, self::defaults() );
	}

	public static function get( $key, $default = null ) {
		$all = self::get_all();
		return isset( $all[ $key ] ) ? $all[ $key ] : $default;
	}

	public static function update( $new_values ) {
		$all = self::get_all();
		$all = array_merge( $all, $new_values );
		update_option( self::OPTION_KEY, $all );
		return $all;
	}

	/**
	 * Egy adott szerepkör + módszer aktuális policy-je.
	 */
	public static function get_policy_for_role( $role, $method ) {
		$policy = self::get( 'role_policy', array() );
		if ( isset( $policy[ $role ][ $method ] ) ) {
			return $policy[ $role ][ $method ];
		}
		return 'optional';
	}

	/**
	 * Egy felhasználó legszigorúbb policy-je egy adott módszerre (ha több
	 * szerepköre van, a "required" felülír mindent, utána "optional", végül "hidden").
	 */
	public static function get_policy_for_user( $user, $method ) {
		if ( ! $user instanceof WP_User ) {
			$user = get_userdata( $user );
		}
		if ( ! $user ) {
			return 'hidden';
		}

		$rank   = array( 'hidden' => 0, 'optional' => 1, 'required' => 2 );
		$result = 'hidden';

		foreach ( (array) $user->roles as $role ) {
			$p = self::get_policy_for_role( $role, $method );
			if ( isset( $rank[ $p ] ) && $rank[ $p ] > $rank[ $result ] ) {
				$result = $p;
			}
		}

		return $result;
	}

	/**
	 * Van-e a felhasználónak legalább egy "required" módszere.
	 */
	public static function user_has_required_method( $user ) {
		foreach ( array_keys( self::methods() ) as $method ) {
			if ( 'required' === self::get_policy_for_user( $user, $method ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A felhasználó számára ténylegesen felkínálható (nem "hidden") módszerek.
	 */
	public static function visible_methods_for_user( $user ) {
		$visible = array();
		foreach ( array_keys( self::methods() ) as $method ) {
			if ( 'hidden' !== self::get_policy_for_user( $user, $method ) ) {
				$visible[] = $method;
			}
		}
		return $visible;
	}
}

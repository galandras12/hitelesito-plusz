<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Passkey (WebAuthn) regisztrációs és hitelesítési ceremóniák.
 */
class H2F_Passkey {

	public static function init() {
		// Az AJAX végpontokat a H2F_Ajax osztály regisztrálja.
	}

	public static function rp_id() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return apply_filters( 'h2f_webauthn_rp_id', $host );
	}

	public static function rp_name() {
		return get_bloginfo( 'name' );
	}

	public static function origin() {
		return home_url();
	}

	/**
	 * Regisztrációs kihívás (challenge) létrehozása és eltárolása.
	 */
	public static function create_registration_options( $user ) {
		global $wpdb;

		$challenge = H2F_WebAuthn_Helper::generate_challenge();

		$wpdb->insert(
			H2F_DB::table_webauthn_challenges(),
			array(
				'user_id'    => $user->ID,
				'challenge'  => H2F_WebAuthn_Helper::base64url_encode( $challenge ),
				'type'       => 'register',
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		$existing = self::get_user_credential_ids( $user->ID );

		return array(
			'rp'                     => array(
				'name' => self::rp_name(),
				'id'   => self::rp_id(),
			),
			'user'                   => array(
				'id'          => H2F_WebAuthn_Helper::base64url_encode( (string) $user->ID ),
				'name'        => $user->user_login,
				'displayName' => $user->display_name,
			),
			'challenge'              => H2F_WebAuthn_Helper::base64url_encode( $challenge ),
			'pubKeyCredParams'       => array(
				array( 'type' => 'public-key', 'alg' => -7 ),   // ES256
				array( 'type' => 'public-key', 'alg' => -257 ), // RS256
			),
			'timeout'                => 60000,
			'attestation'            => 'none',
			'excludeCredentials'     => array_map( function ( $id ) {
				return array( 'type' => 'public-key', 'id' => $id );
			}, $existing ),
			'authenticatorSelection' => array(
				'userVerification' => 'preferred',
			),
		);
	}

	/**
	 * Regisztrációs válasz feldolgozása és a hitelesítő elmentése.
	 */
	public static function verify_registration( $user_id, $credential, $device_name ) {
		global $wpdb;

		$challenge_row = self::get_latest_challenge( $user_id, 'register' );
		if ( ! $challenge_row ) {
			return new WP_Error( 'h2f_no_challenge', __( 'Lejárt vagy hiányzó kérés, próbáld újra.', 'hitelesito-plusz' ) );
		}

		$client_data_json = H2F_WebAuthn_Helper::base64url_decode( $credential['response']['clientDataJSON'] );
		$client_data       = json_decode( $client_data_json, true );

		if ( ! $client_data || 'webauthn.create' !== ( $client_data['type'] ?? '' ) ) {
			return new WP_Error( 'h2f_bad_type', __( 'Érvénytelen hitelesítési válasz.', 'hitelesito-plusz' ) );
		}

		if ( ! hash_equals( $challenge_row->challenge, $client_data['challenge'] ) ) {
			return new WP_Error( 'h2f_challenge_mismatch', __( 'A kihívás nem egyezik, próbáld újra.', 'hitelesito-plusz' ) );
		}

		$origin = untrailingslashit( self::origin() );
		if ( untrailingslashit( $client_data['origin'] ?? '' ) !== $origin ) {
			return new WP_Error( 'h2f_origin_mismatch', __( 'A forrás (origin) nem egyezik.', 'hitelesito-plusz' ) );
		}

		$attestation_object = H2F_WebAuthn_Helper::base64url_decode( $credential['response']['attestationObject'] );
		$attestation         = H2F_WebAuthn_Helper::cbor_decode( $attestation_object );

		if ( empty( $attestation['authData'] ) ) {
			return new WP_Error( 'h2f_bad_attestation', __( 'Hibás attesztációs adat.', 'hitelesito-plusz' ) );
		}

		$auth_data = H2F_WebAuthn_Helper::parse_auth_data( $attestation['authData'] );

		$expected_rp_hash = hash( 'sha256', self::rp_id(), true );
		if ( ! hash_equals( $expected_rp_hash, $auth_data['rp_id_hash'] ) ) {
			return new WP_Error( 'h2f_rpid_mismatch', __( 'Az oldal azonosítója nem egyezik.', 'hitelesito-plusz' ) );
		}

		if ( empty( $auth_data['credential_id'] ) || empty( $auth_data['public_key'] ) ) {
			return new WP_Error( 'h2f_no_credential', __( 'Nem sikerült kiolvasni a hitelesítő adatait.', 'hitelesito-plusz' ) );
		}

		$pem = H2F_WebAuthn_Helper::cose_key_to_pem( $auth_data['public_key'] );
		if ( ! $pem ) {
			return new WP_Error( 'h2f_unsupported_key', __( 'Nem támogatott kulcstípus.', 'hitelesito-plusz' ) );
		}

		$wpdb->insert(
			H2F_DB::table_passkeys(),
			array(
				'user_id'       => $user_id,
				'credential_id' => H2F_WebAuthn_Helper::base64url_encode( $auth_data['credential_id'] ),
				'public_key'    => base64_encode( $pem ),
				'sign_count'    => $auth_data['sign_count'],
				'device_name'   => sanitize_text_field( $device_name ),
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		self::clear_challenges( $user_id, 'register' );

		return true;
	}

	/**
	 * Hitelesítési (bejelentkezési) kihívás generálása.
	 */
	public static function create_auth_options( $user_id = null ) {
		global $wpdb;

		$challenge = H2F_WebAuthn_Helper::generate_challenge();

		$wpdb->insert(
			H2F_DB::table_webauthn_challenges(),
			array(
				'user_id'    => (int) $user_id,
				'challenge'  => H2F_WebAuthn_Helper::base64url_encode( $challenge ),
				'type'       => 'auth',
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		$allow_credentials = array();
		if ( $user_id ) {
			foreach ( self::get_user_credential_ids( $user_id ) as $cred_id ) {
				$allow_credentials[] = array( 'type' => 'public-key', 'id' => $cred_id );
			}
		}

		return array(
			'challenge'        => H2F_WebAuthn_Helper::base64url_encode( $challenge ),
			'timeout'          => 60000,
			'rpId'             => self::rp_id(),
			'allowCredentials' => $allow_credentials,
			'userVerification' => 'preferred',
		);
	}

	/**
	 * Hitelesítési válasz ellenőrzése.
	 */
	public static function verify_authentication( $user_id, $credential ) {
		global $wpdb;

		$challenge_row = self::get_latest_challenge( $user_id, 'auth' );
		if ( ! $challenge_row ) {
			return new WP_Error( 'h2f_no_challenge', __( 'Lejárt vagy hiányzó kérés, próbáld újra.', 'hitelesito-plusz' ) );
		}

		$credential_id = H2F_WebAuthn_Helper::base64url_encode(
			H2F_WebAuthn_Helper::base64url_decode( $credential['id'] )
		);

		$stored = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . H2F_DB::table_passkeys() . " WHERE user_id = %d AND credential_id = %s",
			$user_id,
			$credential_id
		) );

		if ( ! $stored ) {
			return new WP_Error( 'h2f_unknown_credential', __( 'Ismeretlen hitelesítő eszköz.', 'hitelesito-plusz' ) );
		}

		$client_data_json = H2F_WebAuthn_Helper::base64url_decode( $credential['response']['clientDataJSON'] );
		$client_data       = json_decode( $client_data_json, true );

		if ( ! $client_data || 'webauthn.get' !== ( $client_data['type'] ?? '' ) ) {
			return new WP_Error( 'h2f_bad_type', __( 'Érvénytelen hitelesítési válasz.', 'hitelesito-plusz' ) );
		}

		if ( ! hash_equals( $challenge_row->challenge, $client_data['challenge'] ) ) {
			return new WP_Error( 'h2f_challenge_mismatch', __( 'A kihívás nem egyezik, próbáld újra.', 'hitelesito-plusz' ) );
		}

		$origin = untrailingslashit( self::origin() );
		if ( untrailingslashit( $client_data['origin'] ?? '' ) !== $origin ) {
			return new WP_Error( 'h2f_origin_mismatch', __( 'A forrás (origin) nem egyezik.', 'hitelesito-plusz' ) );
		}

		$auth_data_raw = H2F_WebAuthn_Helper::base64url_decode( $credential['response']['authenticatorData'] );
		$signature     = H2F_WebAuthn_Helper::base64url_decode( $credential['response']['signature'] );

		$expected_rp_hash = hash( 'sha256', self::rp_id(), true );
		if ( ! hash_equals( $expected_rp_hash, substr( $auth_data_raw, 0, 32 ) ) ) {
			return new WP_Error( 'h2f_rpid_mismatch', __( 'Az oldal azonosítója nem egyezik.', 'hitelesito-plusz' ) );
		}

		$pem = base64_decode( $stored->public_key );
		$signed_data = $auth_data_raw . hash( 'sha256', $client_data_json, true );

		if ( ! H2F_WebAuthn_Helper::verify_signature( $pem, $signed_data, $signature ) ) {
			return new WP_Error( 'h2f_bad_signature', __( 'Az aláírás ellenőrzése sikertelen.', 'hitelesito-plusz' ) );
		}

		$new_sign_count = unpack( 'N', substr( $auth_data_raw, 33, 4 ) )[1];
		if ( $new_sign_count > 0 && $new_sign_count <= (int) $stored->sign_count ) {
			// Lehetséges klónozott hitelesítő - visszaesett a számláló.
			return new WP_Error( 'h2f_replay', __( 'Gyanús ismételt hitelesítési kísérlet.', 'hitelesito-plusz' ) );
		}

		$wpdb->update(
			H2F_DB::table_passkeys(),
			array( 'sign_count' => $new_sign_count, 'last_used_at' => current_time( 'mysql' ) ),
			array( 'id' => $stored->id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		self::clear_challenges( $user_id, 'auth' );

		return true;
	}

	public static function get_user_credential_ids( $user_id ) {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare(
			"SELECT credential_id FROM " . H2F_DB::table_passkeys() . " WHERE user_id = %d",
			$user_id
		) );
	}

	public static function get_user_passkeys( $user_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . H2F_DB::table_passkeys() . " WHERE user_id = %d ORDER BY created_at DESC",
			$user_id
		) );
	}

	public static function delete_passkey( $user_id, $passkey_id ) {
		global $wpdb;
		return $wpdb->delete(
			H2F_DB::table_passkeys(),
			array( 'id' => $passkey_id, 'user_id' => $user_id ),
			array( '%d', '%d' )
		);
	}

	public static function has_passkeys( $user_id ) {
		return (bool) self::get_user_credential_ids( $user_id );
	}

	public static function disable_all( $user_id ) {
		global $wpdb;
		$wpdb->delete( H2F_DB::table_passkeys(), array( 'user_id' => $user_id ), array( '%d' ) );
	}

	protected static function get_latest_challenge( $user_id, $type ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . H2F_DB::table_webauthn_challenges() . "
			 WHERE user_id = %d AND type = %s AND created_at >= %s
			 ORDER BY id DESC LIMIT 1",
			$user_id,
			$type,
			date( 'Y-m-d H:i:s', time() - 300 )
		) );
	}

	protected static function clear_challenges( $user_id, $type ) {
		global $wpdb;
		$wpdb->delete(
			H2F_DB::table_webauthn_challenges(),
			array( 'user_id' => $user_id, 'type' => $type ),
			array( '%d', '%s' )
		);
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minden AJAX végpont egy helyen: a "pending" (még nem teljesen bejelentkezett)
 * és a már bejelentkezett felhasználók saját beállítási műveletei.
 */
class H2F_Ajax {

	public static function init() {
		// --- Bejelentkezés közbeni ellenőrzés (nem bejelentkezett állapot) ---
		add_action( 'wp_ajax_nopriv_h2f_verify_totp', array( __CLASS__, 'verify_totp_pending' ) );
		add_action( 'wp_ajax_nopriv_h2f_verify_backup', array( __CLASS__, 'verify_backup_pending' ) );
		add_action( 'wp_ajax_nopriv_h2f_send_email_code', array( __CLASS__, 'send_email_code_pending' ) );
		add_action( 'wp_ajax_nopriv_h2f_verify_email', array( __CLASS__, 'verify_email_pending' ) );
		add_action( 'wp_ajax_nopriv_h2f_passkey_auth_options', array( __CLASS__, 'passkey_auth_options_pending' ) );
		add_action( 'wp_ajax_nopriv_h2f_passkey_auth_verify', array( __CLASS__, 'passkey_auth_verify_pending' ) );

		// --- Saját fiók beállítása (bejelentkezett állapot) ---
		add_action( 'wp_ajax_h2f_setup_totp_start', array( __CLASS__, 'setup_totp_start' ) );
		add_action( 'wp_ajax_h2f_setup_totp_confirm', array( __CLASS__, 'setup_totp_confirm' ) );
		add_action( 'wp_ajax_h2f_setup_totp_disable', array( __CLASS__, 'setup_totp_disable' ) );
		add_action( 'wp_ajax_h2f_setup_backup_generate', array( __CLASS__, 'setup_backup_generate' ) );
		add_action( 'wp_ajax_h2f_setup_backup_disable', array( __CLASS__, 'setup_backup_disable' ) );
		add_action( 'wp_ajax_h2f_setup_email_toggle', array( __CLASS__, 'setup_email_toggle' ) );
		add_action( 'wp_ajax_h2f_setup_passkey_register_options', array( __CLASS__, 'setup_passkey_register_options' ) );
		add_action( 'wp_ajax_h2f_setup_passkey_register_verify', array( __CLASS__, 'setup_passkey_register_verify' ) );
		add_action( 'wp_ajax_h2f_setup_passkey_delete', array( __CLASS__, 'setup_passkey_delete' ) );

		// --- Biztonsági kódok letöltése TXT-ként ---
		add_action( 'admin_post_h2f_download_backup_codes', array( __CLASS__, 'download_backup_codes' ) );
	}

	public static function download_backup_codes() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Be kell jelentkezned.', 'hitelesito-plusz' ) );
		}
		check_admin_referer( 'h2f_download_backup_codes' );

		$user  = wp_get_current_user();
		$codes = get_transient( 'h2f_plain_backup_' . $user->ID );

		if ( ! $codes ) {
			wp_die( esc_html__( 'A letöltési link lejárt. Generálj új kódokat.', 'hitelesito-plusz' ) );
		}

		delete_transient( 'h2f_plain_backup_' . $user->ID );

		$content  = H2F_Backup_Codes::build_txt_content( $user, $codes );
		$filename = sanitize_file_name( 'hitelesito-plusz-biztonsagi-kodok-' . $user->user_login . '.txt' );

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $content ) );
		echo $content;
		exit;
	}

	/* ---------------------------------------------------------------
	 * Segédek
	 * ------------------------------------------------------------- */

	protected static function get_pending_session_or_die() {
		if ( empty( $_COOKIE[ H2F_Login_Flow::PENDING_COOKIE ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Lejárt munkamenet, jelentkezz be újra.', 'hitelesito-plusz' ) ), 401 );
		}
		$token = sanitize_text_field( wp_unslash( $_COOKIE[ H2F_Login_Flow::PENDING_COOKIE ] ) );
		$data  = get_transient( 'h2f_pending_' . $token );
		if ( ! $data ) {
			wp_send_json_error( array( 'message' => __( 'Lejárt munkamenet, jelentkezz be újra.', 'hitelesito-plusz' ) ), 401 );
		}
		$data['token'] = $token;

		check_ajax_referer( 'h2f_pending_' . $token, 'nonce' );

		return $data;
	}

	protected static function finalize_and_respond( $session ) {
		H2F_Admin_Alert::reset_counter( $session['user_id'] );
		H2F_Login_Flow::finalize_login( $session );
		wp_send_json_success( array(
			'redirect' => H2F_Login_Flow::get_redirect_after_login( $session ),
		) );
	}

	/* ---------------------------------------------------------------
	 * Pending (bejelentkezés közbeni) ellenőrzések
	 * ------------------------------------------------------------- */

	public static function verify_totp_pending() {
		$session = self::get_pending_session_or_die();
		$code    = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';

		$row = H2F_TOTP::get_secret( $session['user_id'] );
		if ( ! $row || ! $row->confirmed ) {
			wp_send_json_error( array( 'message' => __( 'A TOTP nincs beállítva ehhez a fiókhoz.', 'hitelesito-plusz' ) ) );
		}

		if ( ! H2F_TOTP::verify( $row->secret, $code ) ) {
			H2F_Admin_Alert::record_failure( $session['user_id'], 'totp' );
			wp_send_json_error( array( 'message' => __( 'Hibás vagy lejárt kód.', 'hitelesito-plusz' ) ) );
		}

		self::finalize_and_respond( $session );
	}

	public static function verify_backup_pending() {
		$session = self::get_pending_session_or_die();
		$code    = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';

		if ( ! H2F_Backup_Codes::verify_and_consume( $session['user_id'], $code ) ) {
			H2F_Admin_Alert::record_failure( $session['user_id'], 'backup' );
			wp_send_json_error( array( 'message' => __( 'Hibás vagy már felhasznált biztonsági kód.', 'hitelesito-plusz' ) ) );
		}

		self::finalize_and_respond( $session );
	}

	public static function send_email_code_pending() {
		$session = self::get_pending_session_or_die();
		$user    = get_userdata( $session['user_id'] );

		if ( ! $user ) {
			wp_send_json_error( array( 'message' => __( 'Ismeretlen felhasználó.', 'hitelesito-plusz' ) ) );
		}

		H2F_Email_2FA::send_code( $user );

		// Elfedett e-mail cím a visszajelzésben.
		$masked = self::mask_email( $user->user_email );

		wp_send_json_success( array( 'message' => sprintf( __( 'Kódot küldtünk erre a címre: %s', 'hitelesito-plusz' ), $masked ) ) );
	}

	public static function verify_email_pending() {
		$session = self::get_pending_session_or_die();
		$code    = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';

		if ( ! H2F_Email_2FA::verify_and_consume( $session['user_id'], $code ) ) {
			H2F_Admin_Alert::record_failure( $session['user_id'], 'email' );
			wp_send_json_error( array( 'message' => __( 'Hibás vagy lejárt kód.', 'hitelesito-plusz' ) ) );
		}

		self::finalize_and_respond( $session );
	}

	public static function passkey_auth_options_pending() {
		$session = self::get_pending_session_or_die();
		$options = H2F_Passkey::create_auth_options( $session['user_id'] );
		wp_send_json_success( $options );
	}

	public static function passkey_auth_verify_pending() {
		$session    = self::get_pending_session_or_die();
		$credential = isset( $_POST['credential'] ) ? json_decode( wp_unslash( $_POST['credential'] ), true ) : null;

		if ( ! $credential ) {
			wp_send_json_error( array( 'message' => __( 'Érvénytelen kérés.', 'hitelesito-plusz' ) ) );
		}

		$result = H2F_Passkey::verify_authentication( $session['user_id'], $credential );

		if ( is_wp_error( $result ) ) {
			H2F_Admin_Alert::record_failure( $session['user_id'], 'passkey' );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		self::finalize_and_respond( $session );
	}

	protected static function mask_email( $email ) {
		$parts = explode( '@', $email );
		if ( count( $parts ) !== 2 ) {
			return $email;
		}
		$name = $parts[0];
		$masked_name = mb_substr( $name, 0, 1 ) . str_repeat( '*', max( 1, mb_strlen( $name ) - 2 ) ) . mb_substr( $name, -1 );
		return $masked_name . '@' . $parts[1];
	}

	/* ---------------------------------------------------------------
	 * Saját fiók beállítása (bejelentkezve)
	 * ------------------------------------------------------------- */

	protected static function require_login_and_nonce() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Be kell jelentkezned.', 'hitelesito-plusz' ) ), 401 );
		}
		check_ajax_referer( 'h2f_setup_nonce', 'nonce' );
	}

	public static function setup_totp_start() {
		self::require_login_and_nonce();
		$user   = wp_get_current_user();
		$secret = H2F_TOTP::generate_secret();
		H2F_TOTP::save_new_secret( $user->ID, $secret );

		$uri = H2F_TOTP::provisioning_uri( $secret, $user->user_email, get_bloginfo( 'name' ) );
		$qr_svg = H2F_QRCode::svg_for_text( $uri, 8, 3 );

		wp_send_json_success( array(
			'secret'      => $secret,
			'otpauth_uri' => $uri,
			'qr_svg'      => $qr_svg,
		) );
	}

	public static function setup_totp_confirm() {
		self::require_login_and_nonce();
		$user = wp_get_current_user();
		$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';

		$row = H2F_TOTP::get_secret( $user->ID );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Először indítsd el a beállítást.', 'hitelesito-plusz' ) ) );
		}

		if ( ! H2F_TOTP::verify( $row->secret, $code ) ) {
			wp_send_json_error( array( 'message' => __( 'Hibás kód, próbáld újra.', 'hitelesito-plusz' ) ) );
		}

		H2F_TOTP::confirm( $user->ID );
		wp_send_json_success( array( 'message' => __( 'A hitelesítő alkalmazás sikeresen beállítva.', 'hitelesito-plusz' ) ) );
	}

	public static function setup_totp_disable() {
		self::require_login_and_nonce();
		H2F_TOTP::disable( get_current_user_id() );
		wp_send_json_success();
	}

	public static function setup_backup_generate() {
		self::require_login_and_nonce();
		$user  = wp_get_current_user();
		$codes = H2F_Backup_Codes::generate_new_set( $user->ID );

		set_transient( 'h2f_plain_backup_' . $user->ID, $codes, 5 * MINUTE_IN_SECONDS );

		wp_send_json_success( array(
			'codes'        => $codes,
			'download_url' => wp_nonce_url(
				admin_url( 'admin-post.php?action=h2f_download_backup_codes' ),
				'h2f_download_backup_codes'
			),
		) );
	}

	public static function setup_backup_disable() {
		self::require_login_and_nonce();
		H2F_Backup_Codes::disable( get_current_user_id() );
		wp_send_json_success();
	}

	public static function setup_email_toggle() {
		self::require_login_and_nonce();
		$user    = wp_get_current_user();
		$enabled = ! empty( $_POST['enabled'] );
		update_user_meta( $user->ID, 'h2f_email_enabled', $enabled ? 1 : 0 );
		wp_send_json_success();
	}

	public static function setup_passkey_register_options() {
		self::require_login_and_nonce();
		$user    = wp_get_current_user();
		$options = H2F_Passkey::create_registration_options( $user );
		wp_send_json_success( $options );
	}

	public static function setup_passkey_register_verify() {
		self::require_login_and_nonce();
		$user        = wp_get_current_user();
		$credential  = isset( $_POST['credential'] ) ? json_decode( wp_unslash( $_POST['credential'] ), true ) : null;
		$device_name = isset( $_POST['device_name'] ) ? sanitize_text_field( wp_unslash( $_POST['device_name'] ) ) : __( 'Ismeretlen eszköz', 'hitelesito-plusz' );

		if ( ! $credential ) {
			wp_send_json_error( array( 'message' => __( 'Érvénytelen kérés.', 'hitelesito-plusz' ) ) );
		}

		$result = H2F_Passkey::verify_registration( $user->ID, $credential, $device_name );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'A passkey sikeresen hozzáadva.', 'hitelesito-plusz' ) ) );
	}

	public static function setup_passkey_delete() {
		self::require_login_and_nonce();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		H2F_Passkey::delete_passkey( get_current_user_id(), $id );
		wp_send_json_success();
	}
}

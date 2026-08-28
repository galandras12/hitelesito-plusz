<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A bejelentkezés utáni 2FA-átirányítás vezérlése.
 *
 * Folyamat:
 * 1. A felhasználó a normál wp-login.php-n jelentkezik be (jelszóval).
 * 2. Sikeres jelszavas belépés után (wp_login hook) megnézzük, kell-e neki
 *    2FA. Ha igen: azonnal kijelentkeztetjük (auth cookie törlés), majd
 *    létrehozunk egy ideiglenes "pending" tokent és átirányítjuk egy saját,
 *    nem wp-login.php alapú oldalra.
 * 3. Az ottani felület kezeli a hitelesítő kiválasztását és ellenőrzését.
 * 4. Sikeres hitelesítés után állítjuk be ténylegesen az auth cookie-t.
 */
class H2F_Login_Flow {

	const PENDING_COOKIE = 'h2f_pending_token';
	const PENDING_TTL    = 600; // 10 perc

	public static function init() {
		add_action( 'wp_login', array( __CLASS__, 'maybe_intercept_login' ), 20, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'route' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'enforce_required_setup' ), 5 );
		add_action( 'admin_init', array( __CLASS__, 'enforce_required_setup' ), 5 );
	}

	/**
	 * Ha a felhasználónak van "kötelező" hitelesítő módszere, de még nem
	 * állította be, a saját beállító felületre irányítjuk (nem blokkoljuk
	 * ki teljesen, hiszen elsőre be kell tudnia állítani).
	 */
	public static function enforce_required_setup() {
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			return;
		}

		$action = get_query_var( 'h2f_action' );
		if ( 'setup' === $action ) {
			return;
		}
		if ( isset( $_GET['action'] ) && 'logout' === $_GET['action'] ) {
			return;
		}
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		$user = wp_get_current_user();

		if ( ! H2F_Settings::user_has_required_method( $user ) ) {
			return;
		}

		$missing = false;
		foreach ( array_keys( H2F_Settings::methods() ) as $method ) {
			if ( 'required' !== H2F_Settings::get_policy_for_user( $user, $method ) ) {
				continue;
			}
			if ( 'totp' === $method && ! H2F_TOTP::is_confirmed( $user->ID ) ) {
				$missing = true;
			}
			if ( 'passkey' === $method && ! H2F_Passkey::has_passkeys( $user->ID ) ) {
				$missing = true;
			}
			// az e-mail módszer nem igényel előzetes beállítást, mindig használható
		}

		if ( ! $missing ) {
			return;
		}

		if ( is_admin() ) {
			wp_safe_redirect( add_query_arg( 'h2f_required', '1', self::setup_url() ) );
			exit;
		}
	}

	public static function maybe_enqueue_assets() {
		$action = get_query_var( 'h2f_action' );
		if ( empty( $action ) ) {
			return;
		}

		wp_enqueue_style( 'h2f-frontend', H2F_PLUGIN_URL . 'assets/frontend.css', array(), H2F_VERSION );
		wp_enqueue_script( 'h2f-frontend', H2F_PLUGIN_URL . 'assets/frontend.js', array(), H2F_VERSION, true );

		$data = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'i18n'    => array(
				'copied'          => __( 'Másolva!', 'hitelesito-plusz' ),
				'copy'            => __( 'Másolás', 'hitelesito-plusz' ),
				'wrongCode'       => __( 'Hibás kód, próbáld újra.', 'hitelesito-plusz' ),
				'genericError'    => __( 'Hiba történt, próbáld újra.', 'hitelesito-plusz' ),
				'passkeyNotSupp'  => __( 'Ez a böngésző nem támogatja a Passkey hitelesítést.', 'hitelesito-plusz' ),
				'sending'         => __( 'Küldés...', 'hitelesito-plusz' ),
				'verifying'       => __( 'Ellenőrzés...', 'hitelesito-plusz' ),
			),
		);

		if ( 'verify' === $action ) {
			$session = self::get_pending_session();
			if ( $session ) {
				$data['nonce'] = wp_create_nonce( 'h2f_pending_' . $session['token'] );
			}
		} elseif ( 'setup' === $action && is_user_logged_in() ) {
			$data['nonce'] = wp_create_nonce( 'h2f_setup_nonce' );
		}

		wp_add_inline_script( 'h2f-frontend', 'var H2F = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	public static function add_query_vars( $vars ) {
		$vars[] = 'h2f_action';
		return $vars;
	}

	public static function verify_url() {
		return add_query_arg( 'h2f_action', 'verify', home_url( '/' ) );
	}

	public static function setup_url() {
		return add_query_arg( 'h2f_action', 'setup', home_url( '/' ) );
	}

	/**
	 * Kell-e 2FA-t végrehajtania a felhasználónak.
	 */
	public static function user_needs_2fa( $user ) {
		if ( ! $user instanceof WP_User ) {
			return false;
		}

		$has_totp    = H2F_TOTP::is_confirmed( $user->ID );
		$has_email   = 'hidden' !== H2F_Settings::get_policy_for_user( $user, 'email' ); // email mindig elérhető, ha nem rejtett
		$has_passkey = H2F_Passkey::has_passkeys( $user->ID );

		$enabled_methods = array();
		if ( $has_totp ) {
			$enabled_methods[] = 'totp';
		}
		if ( $has_passkey ) {
			$enabled_methods[] = 'passkey';
		}

		// Ha van legalább egy már beállított módszere -> mindenképp kérjünk 2FA-t.
		if ( ! empty( $enabled_methods ) ) {
			return true;
		}

		// Ha nincs beállított módszere, de van "required" policy-je -> szintén
		// kérjük (a felületen fel kell ajánlani neki a beállítást / e-mail kódot).
		if ( H2F_Settings::user_has_required_method( $user ) ) {
			return true;
		}

		return false;
	}

	public static function maybe_intercept_login( $user_login, $user = null ) {
		if ( ! $user ) {
			$user = get_user_by( 'login', $user_login );
		}
		if ( ! $user ) {
			return;
		}

		if ( ! self::user_needs_2fa( $user ) ) {
			return;
		}

		$redirect_to = '';
		if ( ! empty( $_REQUEST['redirect_to'] ) ) {
			$redirect_to = esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) );
		}

		// Azonnal érvénytelenítjük a most létrejött teljes jogú munkamenetet.
		wp_clear_auth_cookie();
		wp_set_current_user( 0 );

		$token = wp_generate_password( 43, false, false );

		set_transient( 'h2f_pending_' . $token, array(
			'user_id'     => $user->ID,
			'redirect_to' => $redirect_to,
			'remember'    => ! empty( $_REQUEST['rememberme'] ),
		), self::PENDING_TTL );

		$secure = is_ssl();
		setcookie( self::PENDING_COOKIE, $token, time() + self::PENDING_TTL, COOKIEPATH ?: '/', COOKIE_DOMAIN, $secure, true );

		wp_safe_redirect( self::verify_url() );
		exit;
	}

	protected static function get_pending_session() {
		if ( empty( $_COOKIE[ self::PENDING_COOKIE ] ) ) {
			return false;
		}
		$token = sanitize_text_field( wp_unslash( $_COOKIE[ self::PENDING_COOKIE ] ) );
		$data  = get_transient( 'h2f_pending_' . $token );
		if ( ! $data ) {
			return false;
		}
		$data['token'] = $token;
		return $data;
	}

	public static function clear_pending_session( $token ) {
		delete_transient( 'h2f_pending_' . $token );
		$secure = is_ssl();
		setcookie( self::PENDING_COOKIE, '', time() - 3600, COOKIEPATH ?: '/', COOKIE_DOMAIN, $secure, true );
	}

	/**
	 * Sikeres 2FA után a tényleges bejelentkezés véglegesítése.
	 */
	public static function finalize_login( $session ) {
		$user = get_userdata( $session['user_id'] );
		if ( ! $user ) {
			return false;
		}

		wp_set_auth_cookie( $user->ID, ! empty( $session['remember'] ) );
		wp_set_current_user( $user->ID );
		do_action( 'wp_login', $user->user_login, $user );
		self::clear_pending_session( $session['token'] );

		return true;
	}

	public static function get_redirect_after_login( $session ) {
		if ( ! empty( $session['redirect_to'] ) ) {
			return $session['redirect_to'];
		}
		return admin_url();
	}

	/**
	 * Kérés-irányítás a saját (nem wp-login.php) végpontokra.
	 */
	public static function route() {
		$action = get_query_var( 'h2f_action' );
		if ( empty( $action ) ) {
			return;
		}

		if ( 'verify' === $action ) {
			self::render_verify_page();
			exit;
		}

		if ( 'setup' === $action ) {
			self::render_setup_page();
			exit;
		}
	}

	protected static function render_verify_page() {
		$session = self::get_pending_session();

		if ( ! $session ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		$user = get_userdata( $session['user_id'] );
		if ( ! $user ) {
			self::clear_pending_session( $session['token'] );
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		require H2F_PLUGIN_DIR . 'templates/verify-2fa.php';
	}

	protected static function render_setup_page() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::setup_url() ) );
			exit;
		}
		$user = wp_get_current_user();
		require H2F_PLUGIN_DIR . 'templates/setup-2fa.php';
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Együttműködés az And Security bővítménnyel.
 *
 * A Hitelesítő+ kizárólag a kétfaktoros hitelesítésért felel: nem tűzfal,
 * nem szűri a rosszindulatú kéréseket, és a saját brute force védelme is
 * csak egy egyszerű IP+felhasználónév alapú számláló. A teljes körű
 * belépés-védelmet (fokozatos zárolás, bot-felismerés, tűzfal) az And
 * Security testvérbővítmény adja - a kettő szándékosan kiegészíti egymást:
 *
 *  - ha az And Security NINCS telepítve, felajánljuk az adminnak, hogy a
 *    nagyobb védelem érdekében telepítse;
 *  - ha az And Security AKTÍV és a saját belépés-védelme is be van
 *    kapcsolva nála, a Hitelesítő+ saját brute force védelmét automatikusan
 *    kikapcsoljuk (nem csak futásidőben, a tárolt beállítást is frissítjük),
 *    és a Biztonság fülön a kapcsolót inaktívvá tesszük egy magyarázó
 *    szöveggel - két, egymástól független zárolás soha nem fut egyszerre.
 */
class H2F_Compat {

	const DISMISS_META_KEY = 'h2f_dismiss_andsec_recommend';

	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_post_h2f_dismiss_andsec_recommend', array( __CLASS__, 'handle_dismiss' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_sync_brute_force_setting' ) );
	}

	/**
	 * Aktív-e az And Security a jelenlegi oldalon.
	 */
	public static function is_andsec_active() {
		return defined( 'ANDSEC_VERSION' );
	}

	/**
	 * Ténylegesen védi-e az And Security a bejelentkezést.
	 *
	 * Csak akkor igaz, ha az And Security telepítve ÉS a saját
	 * belépés-védelem modulja is be van kapcsolva nála - ha valaki
	 * kifejezetten kikapcsolta az And Security oldalán (vagy az egész
	 * bővítményt), a Hitelesítő+ saját védelmét nem vesszük el tőle
	 * feleslegesen.
	 */
	public static function is_andsec_login_protection_active() {
		if ( ! self::is_andsec_active() ) {
			return false;
		}

		$settings = get_option( 'andsec_settings', array() );

		if ( ! is_array( $settings ) ) {
			return true;
		}

		if ( isset( $settings['general']['enabled'] ) && ! $settings['general']['enabled'] ) {
			return false;
		}

		if ( isset( $settings['login']['enabled'] ) && ! $settings['login']['enabled'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Csak a Hitelesítő+ saját admin oldalain jelenítünk meg bármit, hogy ne
	 * zsúfoljuk tele az összes admin oldalt egy másik bővítmény reklámjával.
	 */
	protected static function is_own_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && false !== strpos( (string) $screen->id, 'hitelesito-plusz' );
	}

	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) || ! self::is_own_screen() ) {
			return;
		}

		// Ha az And Security aktív, a Biztonság fülön lévő inaktív kapcsoló
		// és a mellette lévő magyarázat már megmondja, mi történik - külön
		// admin-értesítés nem kell hozzá.
		if ( self::is_andsec_active() ) {
			return;
		}

		self::render_recommend_notice();
	}

	/**
	 * And Security nincs telepítve: ajánljuk a nagyobb védelem érdekében.
	 */
	protected static function render_recommend_notice() {
		if ( get_user_meta( get_current_user_id(), self::DISMISS_META_KEY, true ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=h2f_dismiss_andsec_recommend' ),
			'h2f_dismiss_andsec_recommend'
		);
		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<strong><?php esc_html_e( 'Hitelesítő+ × And Security', 'hitelesito-plusz' ); ?></strong><br />
				<?php esc_html_e( 'A Hitelesítő+ a kétfaktoros hitelesítésről gondoskodik, de önmagában nem tűzfal: nem szűri a rosszindulatú kéréseket, és csak alap brute force védelmet ad. Telepítsd mellé az And Security bővítményt a teljes körű védelemhez (tűzfal, fokozatos IP-zárolás, bot-felismerés) - a két bővítmény automatikusan felismeri és kiegészíti egymást.', 'hitelesito-plusz' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="https://github.com/galandras12/and-security" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'And Security megtekintése', 'hitelesito-plusz' ); ?></a>
				<a class="button" href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Ne mutassa többé', 'hitelesito-plusz' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Ha az And Security kezeli a belépés-védelmet, a Hitelesítő+ saját
	 * brute force kapcsolóját automatikusan kikapcsoljuk - a TÁROLT
	 * beállítást is frissítjük (nem csak futásidőben döntünk másképp),
	 * hogy:
	 *  - a Biztonság fülön a kapcsoló ténylegesen kikapcsolt állapotot
	 *    mutasson (ne csak inaktívan, de "bekapcsolva" állva legyen tiltva),
	 *  - az And Security saját ütközés-figyelése is a valós állapotot lássa.
	 *
	 * Ha And Security nélkül futsz tovább (vagy kikapcsolod nála a
	 * belépés-védelmet), a kapcsoló a Biztonság fülön újra elérhetővé és
	 * kézzel bekapcsolhatóvá válik - ezt a metódust ilyenkor nem hívjuk.
	 */
	public static function maybe_sync_brute_force_setting() {
		if ( ! self::is_andsec_login_protection_active() ) {
			return;
		}

		if ( ! (bool) H2F_Settings::get( 'brute_force_enabled', 1 ) ) {
			return;
		}

		H2F_Settings::update( array( 'brute_force_enabled' => 0 ) );
	}

	public static function handle_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Nincs jogosultságod ehhez a művelethez.', 'hitelesito-plusz' ) );
		}

		check_admin_referer( 'h2f_dismiss_andsec_recommend' );

		update_user_meta( get_current_user_id(), self::DISMISS_META_KEY, 1 );

		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url( 'admin.php?page=hitelesito-plusz' ) );
		exit;
	}
}

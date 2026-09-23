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
 *  - ha MINDKETTŐ aktív és mindkét saját brute force védelme be van
 *    kapcsolva, jelezzük az átfedést, és átirányítjuk a Biztonság fülre,
 *    ahol az egyik kikapcsolható (az And Security oldaláról ugyanez a
 *    kikapcsolás egy kattintással is elvégezhető).
 */
class H2F_Compat {

	const DISMISS_META_KEY = 'h2f_dismiss_andsec_recommend';

	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_post_h2f_dismiss_andsec_recommend', array( __CLASS__, 'handle_dismiss' ) );
	}

	/**
	 * Aktív-e az And Security a jelenlegi oldalon.
	 */
	public static function is_andsec_active() {
		return defined( 'ANDSEC_VERSION' );
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

		if ( self::is_andsec_active() ) {
			self::render_active_notice();
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
	 * And Security is aktív: jelezzük, ha a két brute force védelem átfedi
	 * egymást, és a Biztonság fülre irányítjuk az adminisztrátort.
	 */
	protected static function render_active_notice() {
		if ( ! (bool) H2F_Settings::get( 'brute_force_enabled', 1 ) ) {
			return;
		}

		$security_url = admin_url( 'admin.php?page=hitelesito-plusz&tab=security' );
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Hitelesítő+ × And Security', 'hitelesito-plusz' ); ?></strong><br />
				<?php esc_html_e( 'Mindkét bővítmény saját brute force védelmet futtat egyszerre. Ez ellentmondó hibaüzeneteket és felesleges dupla zárolást okozhat. Javasolt az And Securityre bízni a bejelentkezés-védelmet, és itt kikapcsolni a Hitelesítő+ saját brute force zárolását (ez az And Security Kompatibilitás oldaláról is elvégezhető egy kattintással).', 'hitelesito-plusz' ); ?>
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( $security_url ); ?>"><?php esc_html_e( 'Ugrás a Biztonság fülre', 'hitelesito-plusz' ); ?></a>
			</p>
		</div>
		<?php
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

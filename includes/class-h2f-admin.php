<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin menü és beállítási felület.
 */
class H2F_Admin {

	const CAPABILITY = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_save_settings' ) );
	}

	public static function register_menu() {
		add_menu_page(
			__( 'Hitelesítő+', 'hitelesito-plusz' ),
			__( 'Hitelesítő+', 'hitelesito-plusz' ),
			self::CAPABILITY,
			'hitelesito-plusz',
			array( __CLASS__, 'render_page' ),
			self::menu_icon_svg(),
			80
		);
	}

	protected static function menu_icon_svg() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#a7aaad"><path d="M3 3h4v4H3V3zm2 2v0zm7-2h2v2h-2V3zm4 0h4v4h-4V3zm2 2v0zM3 8h2v2H3V8zm4 0h2v2H7V8zm4 0h2v2h-2V8zm-4 4h2v2H7v-2zm-4 0h2v2H3v-2zm8 0h4v4h-4v-2h-2v-2h2zm6-2h2v6h-2v2h-4v-2h2v-2h2v-4zM3 17h4v4H3v-4zm2 2v0zm6-2h2v2h-2v-2zm0 4h2v2h-2v-2zm4-2h4v4h-4v-4zm2 2v0z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	public static function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'hitelesito-plusz' ) === false ) {
			return;
		}
		wp_enqueue_style( 'h2f-admin', H2F_PLUGIN_URL . 'assets/admin.css', array(), H2F_VERSION );
		wp_enqueue_script( 'h2f-admin', H2F_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), H2F_VERSION, true );
	}

	protected static function current_tab() {
		$tabs = array( 'roles', 'email', 'security', 'shortcode' );
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'roles';
		return in_array( $tab, $tabs, true ) ? $tab : 'roles';
	}

	public static function maybe_save_settings() {
		if ( empty( $_POST['h2f_settings_nonce'] ) || ! isset( $_POST['h2f_save'] ) ) {
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['h2f_settings_nonce'] ) ), 'h2f_save_settings' ) ) {
			return;
		}

		$tab = self::current_tab();

		if ( 'roles' === $tab ) {
			$policy = array();
			$roles  = function_exists( 'get_editable_roles' ) ? get_editable_roles() : array();
			foreach ( $roles as $slug => $data ) {
				foreach ( array_keys( H2F_Settings::methods() ) as $method ) {
					$field = "policy_{$slug}_{$method}";
					$value = isset( $_POST[ $field ] ) ? sanitize_key( wp_unslash( $_POST[ $field ] ) ) : 'optional';
					if ( ! array_key_exists( $value, H2F_Settings::policy_states() ) ) {
						$value = 'optional';
					}
					$policy[ $slug ][ $method ] = $value;
				}
			}
			H2F_Settings::update( array( 'role_policy' => $policy ) );
			add_settings_error( 'h2f', 'h2f_saved', __( 'A szerepkör-beállítások elmentve.', 'hitelesito-plusz' ), 'success' );
		}

		if ( 'email' === $tab ) {
			$subject  = isset( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : '';
			$body     = isset( $_POST['email_body'] ) ? wp_kses_post( wp_unslash( $_POST['email_body'] ) ) : '';
			$lifetime = isset( $_POST['email_code_lifetime'] ) ? absint( $_POST['email_code_lifetime'] ) : 900;

			if ( ! array_key_exists( $lifetime, H2F_Settings::email_lifetime_options() ) ) {
				$lifetime = 900;
			}

			H2F_Settings::update( array(
				'email_subject'       => $subject,
				'email_body'          => $body,
				'email_code_lifetime' => $lifetime,
			) );
			add_settings_error( 'h2f', 'h2f_saved', __( 'Az e-mail beállítások elmentve.', 'hitelesito-plusz' ), 'success' );
		}

		if ( 'security' === $tab ) {
			H2F_Settings::update( array(
				'brute_force_enabled'   => isset( $_POST['brute_force_enabled'] ) ? 1 : 0,
				'brute_force_max_tries' => isset( $_POST['brute_force_max_tries'] ) ? max( 1, absint( $_POST['brute_force_max_tries'] ) ) : 5,
				'brute_force_window'    => isset( $_POST['brute_force_window'] ) ? max( 1, absint( $_POST['brute_force_window'] ) ) : 15,
				'brute_force_lockout'   => isset( $_POST['brute_force_lockout'] ) ? max( 1, absint( $_POST['brute_force_lockout'] ) ) : 30,
				'admin_alert_enabled'   => isset( $_POST['admin_alert_enabled'] ) ? 1 : 0,
				'admin_alert_threshold' => isset( $_POST['admin_alert_threshold'] ) ? max( 1, absint( $_POST['admin_alert_threshold'] ) ) : 5,
				'admin_alert_emails'    => isset( $_POST['admin_alert_emails'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_alert_emails'] ) ) : get_option( 'admin_email' ),
			) );
			add_settings_error( 'h2f', 'h2f_saved', __( 'A biztonsági beállítások elmentve.', 'hitelesito-plusz' ), 'success' );
		}
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$tab = self::current_tab();
		?>
		<div class="wrap h2f-wrap">
			<div class="h2f-header">
				<div class="h2f-header-icon"><?php echo self::inline_logo(); // phpcs:ignore ?></div>
				<div>
					<h1><?php esc_html_e( 'Hitelesítő+', 'hitelesito-plusz' ); ?></h1>
					<p class="h2f-subtitle"><?php esc_html_e( 'Kétfaktoros hitelesítés kezelése - TOTP, e-mail kód, Passkey és biztonsági mentési kódok.', 'hitelesito-plusz' ); ?></p>
				</div>
			</div>

			<?php settings_errors( 'h2f' ); ?>

			<nav class="h2f-tabs">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'roles' ) ); ?>" class="h2f-tab <?php echo 'roles' === $tab ? 'active' : ''; ?>"><?php esc_html_e( 'Szerepkörök', 'hitelesito-plusz' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'email' ) ); ?>" class="h2f-tab <?php echo 'email' === $tab ? 'active' : ''; ?>"><?php esc_html_e( 'E-mail hitelesítés', 'hitelesito-plusz' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'security' ) ); ?>" class="h2f-tab <?php echo 'security' === $tab ? 'active' : ''; ?>"><?php esc_html_e( 'Biztonság', 'hitelesito-plusz' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'shortcode' ) ); ?>" class="h2f-tab <?php echo 'shortcode' === $tab ? 'active' : ''; ?>"><?php esc_html_e( 'Shortcode', 'hitelesito-plusz' ); ?></a>
			</nav>

			<div class="h2f-card">
				<?php
				switch ( $tab ) {
					case 'email':
						self::render_email_tab();
						break;
					case 'security':
						self::render_security_tab();
						break;
					case 'shortcode':
						self::render_shortcode_tab();
						break;
					default:
						self::render_roles_tab();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	protected static function inline_logo() {
		return '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="24" height="24" rx="6" fill="#1d2327"/><path d="M6 6h3v3H6V6zm0 4.5h1.5V12H6v-1.5zM9 9h1.5v1.5H9V9zm0 3h1.5v1.5H9V12zm-3 3h3v3H6v-3zm5.5-9H13v1.5h-1.5V6zm2.5 0h4.5v4.5H15V6zm1.5 1.5v1.5H18V7.5h-1.5zM11.5 9H13v1.5h-1.5V9zm2 0H16v2h-1.5V9zm2.5 2.5H18V15h-1.5v-1.5H15v-1.5h1zm-4 1.5h1.5v1.5H11.5V13zm2.5 1.5h1.5V16H14v-1.5zm-2.5 1.5H13v1.5h-1.5V16zM6 20h1.5v-1.5H6V20zm3-3h1.5v1.5H9V17zm0 3h1.5v1.5H9V20zm5.5-2H16v2h-1.5v-2zm2.5 0h1.5v2H17v-2z" fill="#fff"/></svg>';
	}

	protected static function render_roles_tab() {
		$roles = function_exists( 'get_editable_roles' ) ? get_editable_roles() : array();
		$methods = H2F_Settings::methods();
		$states  = H2F_Settings::policy_states();
		?>
		<form method="post">
			<?php wp_nonce_field( 'h2f_save_settings', 'h2f_settings_nonce' ); ?>
			<p class="h2f-help"><?php esc_html_e( 'Add meg szerepkörönként, hogy az egyes hitelesítési módszerek kötelezők, opcionálisak, vagy rejtettek (nem elérhetők) legyenek.', 'hitelesito-plusz' ); ?></p>

			<div class="h2f-table-scroll">
			<table class="h2f-matrix">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Szerepkör', 'hitelesito-plusz' ); ?></th>
						<?php foreach ( $methods as $method_key => $method_label ) : ?>
							<th><?php echo esc_html( $method_label ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $roles as $slug => $data ) : ?>
						<tr>
							<td class="h2f-role-name"><?php echo esc_html( translate_user_role( $data['name'] ) ); ?></td>
							<?php foreach ( array_keys( $methods ) as $method_key ) :
								$current = H2F_Settings::get_policy_for_role( $slug, $method_key );
								?>
								<td>
									<div class="h2f-segmented">
										<?php foreach ( $states as $state_key => $state_label ) : ?>
											<label class="h2f-segment <?php echo $current === $state_key ? 'is-active' : ''; ?>">
												<input type="radio"
													name="policy_<?php echo esc_attr( $slug ); ?>_<?php echo esc_attr( $method_key ); ?>"
													value="<?php echo esc_attr( $state_key ); ?>"
													<?php checked( $current, $state_key ); ?> />
												<span><?php echo esc_html( $state_label ); ?></span>
											</label>
										<?php endforeach; ?>
									</div>
								</td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>

			<p class="submit">
				<button type="submit" name="h2f_save" class="button button-primary h2f-btn-primary"><?php esc_html_e( 'Beállítások mentése', 'hitelesito-plusz' ); ?></button>
			</p>
		</form>
		<?php
	}

	protected static function render_email_tab() {
		$subject  = H2F_Settings::get( 'email_subject' );
		$body     = H2F_Settings::get( 'email_body' );
		$lifetime = (int) H2F_Settings::get( 'email_code_lifetime', 900 );
		?>
		<form method="post">
			<?php wp_nonce_field( 'h2f_save_settings', 'h2f_settings_nonce' ); ?>

			<div class="h2f-field">
				<label for="email_subject"><?php esc_html_e( 'E-mail tárgya', 'hitelesito-plusz' ); ?></label>
				<input type="text" id="email_subject" name="email_subject" class="regular-text h2f-input" value="<?php echo esc_attr( $subject ); ?>" />
			</div>

			<div class="h2f-field">
				<label for="email_body"><?php esc_html_e( 'E-mail HTML tartalma', 'hitelesito-plusz' ); ?></label>
				<textarea id="email_body" name="email_body" rows="10" class="large-text code h2f-textarea"><?php echo esc_textarea( $body ); ?></textarea>
				<p class="h2f-help">
					<?php esc_html_e( 'Elérhető helyőrzők:', 'hitelesito-plusz' ); ?>
					<code>{code}</code>, <code>{display_name}</code>, <code>{user_login}</code>, <code>{site_name}</code>, <code>{lifetime}</code>
				</p>
			</div>

			<div class="h2f-field">
				<label for="email_code_lifetime"><?php esc_html_e( 'A kód érvényessége a küldéstől számítva', 'hitelesito-plusz' ); ?></label>
				<select id="email_code_lifetime" name="email_code_lifetime" class="h2f-select">
					<?php foreach ( H2F_Settings::email_lifetime_options() as $seconds => $label ) : ?>
						<option value="<?php echo esc_attr( $seconds ); ?>" <?php selected( $lifetime, $seconds ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<p class="submit">
				<button type="submit" name="h2f_save" class="button button-primary h2f-btn-primary"><?php esc_html_e( 'Beállítások mentése', 'hitelesito-plusz' ); ?></button>
			</p>
		</form>
		<?php
	}

	protected static function render_security_tab() {
		$enabled    = (bool) H2F_Settings::get( 'brute_force_enabled', 1 );
		$max_tries  = (int) H2F_Settings::get( 'brute_force_max_tries', 5 );
		$window     = (int) H2F_Settings::get( 'brute_force_window', 15 );
		$lockout    = (int) H2F_Settings::get( 'brute_force_lockout', 30 );
		$alert_enabled   = (bool) H2F_Settings::get( 'admin_alert_enabled', 0 );
		$alert_threshold = (int) H2F_Settings::get( 'admin_alert_threshold', 5 );
		$alert_emails    = (string) H2F_Settings::get( 'admin_alert_emails', get_option( 'admin_email' ) );
		?>
		<form method="post">
			<?php wp_nonce_field( 'h2f_save_settings', 'h2f_settings_nonce' ); ?>

			<div class="h2f-field h2f-switch-row">
				<label class="h2f-switch">
					<input type="checkbox" name="brute_force_enabled" value="1" <?php checked( $enabled ); ?> />
					<span class="h2f-switch-slider"></span>
				</label>
				<div>
					<strong><?php esc_html_e( 'Brute force védelem bekapcsolása', 'hitelesito-plusz' ); ?></strong>
					<p class="h2f-help"><?php esc_html_e( 'IP-cím és felhasználónév alapján ideiglenesen zárolja a bejelentkezést túl sok sikertelen próbálkozás után, hogy botok ne tudjanak belépni.', 'hitelesito-plusz' ); ?></p>
				</div>
			</div>

			<div class="h2f-field">
				<label for="brute_force_max_tries"><?php esc_html_e( 'Megengedett sikertelen próbálkozások száma', 'hitelesito-plusz' ); ?></label>
				<input type="number" min="1" max="50" id="brute_force_max_tries" name="brute_force_max_tries" class="small-text h2f-input" value="<?php echo esc_attr( $max_tries ); ?>" />
			</div>

			<div class="h2f-field">
				<label for="brute_force_window"><?php esc_html_e( 'Időablak (perc), amin belül a próbálkozásokat számoljuk', 'hitelesito-plusz' ); ?></label>
				<input type="number" min="1" max="1440" id="brute_force_window" name="brute_force_window" class="small-text h2f-input" value="<?php echo esc_attr( $window ); ?>" />
			</div>

			<div class="h2f-field">
				<label for="brute_force_lockout"><?php esc_html_e( 'Zárolás időtartama (perc)', 'hitelesito-plusz' ); ?></label>
				<input type="number" min="1" max="1440" id="brute_force_lockout" name="brute_force_lockout" class="small-text h2f-input" value="<?php echo esc_attr( $lockout ); ?>" />
			</div>

			<hr style="margin:28px 0; border-color:#e2e4e7;" />

			<h2 style="font-size:15px; margin-bottom:4px;"><?php esc_html_e( 'Admin riasztás ismételt sikertelen kétfaktoros próbálkozásnál', 'hitelesito-plusz' ); ?></h2>
			<p class="h2f-help"><?php esc_html_e( 'Ha egy felhasználó a bejelentkezés utáni kétfaktoros hitelesítést egymás után többször elrontja, e-mail értesítést küldhetünk a megadott admin címekre a felhasználó nevével és a próbált hitelesítő módszerrel.', 'hitelesito-plusz' ); ?></p>

			<div class="h2f-field h2f-switch-row">
				<label class="h2f-switch">
					<input type="checkbox" name="admin_alert_enabled" value="1" <?php checked( $alert_enabled ); ?> />
					<span class="h2f-switch-slider"></span>
				</label>
				<div>
					<strong><?php esc_html_e( 'Admin riasztás e-mail bekapcsolása', 'hitelesito-plusz' ); ?></strong>
				</div>
			</div>

			<div class="h2f-field">
				<label for="admin_alert_threshold"><?php esc_html_e( 'Hány sikertelen próbálkozás után küldjön riasztást', 'hitelesito-plusz' ); ?></label>
				<input type="number" min="1" max="50" id="admin_alert_threshold" name="admin_alert_threshold" class="small-text h2f-input" value="<?php echo esc_attr( $alert_threshold ); ?>" />
				<p class="h2f-help"><?php esc_html_e( 'Alapértelmezett: 5. A riasztás csak akkor megy ki, ha ezt a számot eléri az egymást követő sikertelen kétfaktoros próbálkozások száma; utána a számláló nullázódik.', 'hitelesito-plusz' ); ?></p>
			</div>

			<div class="h2f-field">
				<label for="admin_alert_emails"><?php esc_html_e( 'Értesítendő e-mail cím(ek)', 'hitelesito-plusz' ); ?></label>
				<textarea id="admin_alert_emails" name="admin_alert_emails" rows="3" class="large-text h2f-textarea" placeholder="admin@pelda.hu, masik.admin@pelda.hu"><?php echo esc_textarea( $alert_emails ); ?></textarea>
				<p class="h2f-help"><?php esc_html_e( 'Több cím vesszővel, pontosvesszővel vagy új sorral elválasztva adható meg. Ha üresen hagyod, az oldal alap admin e-mail címére megy a riasztás.', 'hitelesito-plusz' ); ?></p>
			</div>

			<p class="submit">
				<button type="submit" name="h2f_save" class="button button-primary h2f-btn-primary"><?php esc_html_e( 'Beállítások mentése', 'hitelesito-plusz' ); ?></button>
			</p>
		</form>
		<?php
	}

	protected static function render_shortcode_tab() {
		?>
		<div class="h2f-field">
			<p><?php esc_html_e( 'Illeszd be az alábbi shortcode-ot bármely bejegyzésbe, oldalba vagy szöveges widgetbe. A hivatkozásra kattintva a felhasználó a saját 2 faktoros hitelesítő beállításaihoz jut, ahol láthatja a már beállított és a még be nem állított módszereket.', 'hitelesito-plusz' ); ?></p>
			<div class="h2f-code-box">
				<code>[hitelesito_plusz_beallitas]</code>
				<button type="button" class="button h2f-copy-btn" data-copy="[hitelesito_plusz_beallitas]"><?php esc_html_e( 'Másolás', 'hitelesito-plusz' ); ?></button>
			</div>

			<p class="h2f-help" style="margin-top:16px;"><?php esc_html_e( 'Egyedi felirat megadása:', 'hitelesito-plusz' ); ?></p>
			<div class="h2f-code-box">
				<code>[hitelesito_plusz_beallitas label="Kétfaktoros hitelesítés kezelése"]</code>
				<button type="button" class="button h2f-copy-btn" data-copy='[hitelesito_plusz_beallitas label="Kétfaktoros hitelesítés kezelése"]'><?php esc_html_e( 'Másolás', 'hitelesito-plusz' ); ?></button>
			</div>

			<p class="h2f-help" style="margin-top:16px;"><?php esc_html_e( 'Közvetlen link (pl. menübe vagy gombra):', 'hitelesito-plusz' ); ?></p>
			<div class="h2f-code-box">
				<code><?php echo esc_url( H2F_Login_Flow::setup_url() ); ?></code>
				<button type="button" class="button h2f-copy-btn" data-copy="<?php echo esc_attr( H2F_Login_Flow::setup_url() ); ?>"><?php esc_html_e( 'Másolás', 'hitelesito-plusz' ); ?></button>
			</div>
		</div>
		<?php
	}
}

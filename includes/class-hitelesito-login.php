<?php
/**
 * Login Interceptor and 2FA Verification Handler for Hitelesítő+
 * Intercepts successful username/password authentication without overloading login.php
 * Redirects to standard verification page for entering TOTP code or configuring mandatory 2FA.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hitelesito_Login {

    private static ?Hitelesito_Login $instance = null;
    public const PENDING_COOKIE = 'hitelesito_pending_2fa';

    public static function get_instance(): Hitelesito_Login {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('authenticate', [$this, 'intercept_login'], 30, 3);
        add_action('init', [$this, 'handle_2fa_page_request']);
    }

    /**
     * Intercept login authentication.
     * If user credentials are valid, check 2FA requirements for the user's role.
     */
    public function intercept_login($user, $username, $password) {
        if (is_wp_error($user) || !($user instanceof WP_User)) {
            return $user;
        }

        // Determine requirement for user's roles
        $requirement = $this->get_user_highest_requirement($user);

        // If role is hidden, skip 2FA completely
        if ($requirement === 'hidden') {
            return $user;
        }

        $secret = get_user_meta($user->ID, 'hitelesito_totp_secret', true);
        $enabled = get_user_meta($user->ID, 'hitelesito_totp_enabled', true);

        // If 2FA is not required and user has not enabled it, proceed with standard login
        if ($requirement === 'optional' && !$enabled) {
            return $user;
        }

        // If required but not setup, OR if enabled, we intercept and redirect to second factor page!
        if ($requirement === 'required' || $enabled) {
            $pending_token = wp_generate_password(32, false);
            $expiration = time() + 300; // 5 minutes valid

            // Store transient with user_id and expiration
            set_transient('hitelesito_pending_' . $pending_token, $user->ID, 300);

            // Set encrypted/secure cookie
            setcookie(
                self::PENDING_COOKIE,
                $pending_token,
                $expiration,
                COOKIEPATH,
                COOKIE_DOMAIN,
                is_ssl(),
                true
            );

            // Redirect to 2FA page
            $redirect_url = add_query_arg('hitelesito_2fa', '1', home_url('/'));
            wp_redirect($redirect_url);
            exit;
        }

        return $user;
    }

    /**
     * Get highest 2FA requirement among user's assigned roles.
     * Order of priority: required > optional > hidden
     */
    public function get_user_highest_requirement(WP_User $user): string {
        $roles = (array) $user->roles;
        if (empty($roles)) {
            return 'optional';
        }

        $has_required = false;
        $has_optional = false;

        foreach ($roles as $role) {
            $req = Hitelesito_Admin::get_role_requirement($role);
            if ($req === 'required') {
                $has_required = true;
            } elseif ($req === 'optional') {
                $has_optional = true;
            }
        }

        if ($has_required) {
            return 'required';
        }
        if ($has_optional) {
            return 'optional';
        }

        return 'hidden';
    }

    /**
     * Handle the 2FA verification & setup page request (`?hitelesito_2fa=1`).
     */
    public function handle_2fa_page_request(): void {
        if (!isset($_GET['hitelesito_2fa'])) {
            return;
        }

        // Check if there is a pending login token cookie
        $pending_token = $_COOKIE[self::PENDING_COOKIE] ?? '';
        if (empty($pending_token)) {
            wp_die('A munkamenet lejárt vagy érvénytelen. Kérjük, jelentkezzen be újra.', 'Érvénytelen munkamenet', ['response' => 403]);
        }

        $user_id = get_transient('hitelesito_pending_' . $pending_token);
        if (!$user_id) {
            wp_die('A 2FA azonosítási munkamenet lejárt. Kérjük, próbálja újra bejelentkezni.', 'Lejárt munkamenet', ['response' => 403]);
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            wp_die('Érvénytelen felhasználó.', 'Hiba', ['response' => 403]);
        }

        $message = '';
        $error = '';

        // Check brute force lockout
        if (Hitelesito_Brute_Force::is_locked_out($user_id)) {
            $remaining = Hitelesito_Brute_Force::get_lockout_remaining_minutes($user_id);
            $error = "Túl sok hibás próbálkozás. A fiók zárolva van még {$remaining} percig a biztonság érdekében.";
        } elseif (isset($_POST['hitelesito_verify_action'])) {
            check_admin_referer('hitelesito_2fa_verify');

            $code = sanitize_text_field($_POST['totp_code'] ?? '');
            $secret = get_user_meta($user_id, 'hitelesito_totp_secret', true);

            // If user doesn't have secret set yet, get temporary setup secret
            if (empty($secret) && isset($_POST['temp_secret'])) {
                $secret = sanitize_text_field($_POST['temp_secret']);
            }

            if (empty($secret)) {
                $error = 'Hiányzó titkos kulcs. Próbálja újra az oldalt frissíteni.';
            } else {
                if (Hitelesito_TOTP::verify_code($secret, $code)) {
                    // Success! Reset failed attempts
                    Hitelesito_Brute_Force::reset_attempts($user_id);

                    // If not enabled yet, enable TOTP for user
                    update_user_meta($user_id, 'hitelesito_totp_secret', $secret);
                    update_user_meta($user_id, 'hitelesito_totp_enabled', 1);

                    // Clear pending token & cookie
                    delete_transient('hitelesito_pending_' . $pending_token);
                    setcookie(self::PENDING_COOKIE, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);

                    // Perform WP Log in
                    wp_clear_auth_cookie();
                    wp_set_current_user($user_id, $user->user_login);
                    wp_set_auth_cookie($user_id, true);
                    do_action('wp_login', $user->user_login, $user);

                    // Redirect to standard WP Admin or homepage
                    $redirect_to = admin_url();
                    wp_safe_redirect($redirect_to);
                    exit;
                } else {
                    Hitelesito_Brute_Force::record_failed_attempt($user_id);
                    $attempts_left = Hitelesito_Brute_Force::get_attempts_left($user_id);
                    $error = "Érvénytelen azonosító kód! Kérjük ellenőrizze az alkalmazást. Hátralévő próbálkozások: {$attempts_left}";
                }
            }
        }

        // Render standard modern minimalist 2FA screen
        $this->render_2fa_screen($user, $error, $message);
        exit;
    }

    /**
     * Render the modern minimalist 2FA screen.
     */
    private function render_2fa_screen(WP_User $user, string $error = '', string $message = ''): void {
        $enabled = get_user_meta($user->ID, 'hitelesito_totp_enabled', true);
        $secret = get_user_meta($user->ID, 'hitelesito_totp_secret', true);
        $temp_secret = '';

        if (!$enabled || empty($secret)) {
            $temp_secret = Hitelesito_TOTP::generate_secret();
            $otpauth_url = Hitelesito_TOTP::get_otpauth_url($user->user_email, $temp_secret, get_bloginfo('name'));
            $qr_code_url = Hitelesito_TOTP::get_qr_code_image_url($otpauth_url);
        }

        wp_enqueue_style('hitelesito-plusz-style', HITELESITO_PLUSZ_URL . 'assets/css/style.css', [], HITELESITO_PLUSZ_VERSION);

        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Kéttényezős Hitelesítés (2FA) - <?php bloginfo('name'); ?></title>
            <?php wp_print_styles('hitelesito-plusz-style'); ?>
        </head>
        <body class="hitelesito-auth-body">
            <div class="hitelesito-auth-card">
                <div class="hitelesito-auth-header">
                    <h2>Hitelesítő+ 2FA</h2>
                    <p>Második tényezős azonosítás</p>
                </div>

                <?php if ($error): ?>
                    <div class="hitelesito-alert hitelesito-alert-danger"><?php echo esc_html($error); ?></div>
                <?php endif; ?>

                <?php if ($message): ?>
                    <div class="hitelesito-alert hitelesito-alert-success"><?php echo esc_html($message); ?></div>
                <?php endif; ?>

                <?php if (!$enabled || empty($secret)): ?>
                    <div class="hitelesito-setup-instructions">
                        <p><strong>2FA Beállítása Kötelező:</strong> Olvassa be az alábbi QR kódot a <strong>Google Authenticator</strong> vagy <strong>Microsoft Authenticator</strong> alkalmazással:</p>
                        <div class="hitelesito-qr-box">
                            <img src="<?php echo esc_url($qr_code_url); ?>" alt="2FA QR Code" width="180" height="180">
                        </div>
                        <p class="hitelesito-secret-key">Manuális kulcs: <code><?php echo esc_html($temp_secret); ?></code></p>
                    </div>
                <?php else: ?>
                    <p class="hitelesito-auth-desc">Nyissa meg az hitelesítő alkalmazását (Google / Microsoft Authenticator) és adja meg a 6-jegyű kódot.</p>
                <?php endif; ?>

                <form method="post" class="hitelesito-auth-form">
                    <?php wp_nonce_field('hitelesito_2fa_verify'); ?>
                    <input type="hidden" name="hitelesito_verify_action" value="1">
                    <?php if (!empty($temp_secret)): ?>
                        <input type="hidden" name="temp_secret" value="<?php echo esc_attr($temp_secret); ?>">
                    <?php endif; ?>

                    <div class="hitelesito-form-group">
                        <label for="totp_code">6-jegyű Hitelesítő Kód:</label>
                        <input type="text" id="totp_code" name="totp_code" maxlength="6" pattern="[0-9]{6}" autocomplete="off" placeholder="123456" required autofocus class="hitelesito-code-input">
                    </div>

                    <button type="submit" class="hitelesito-btn hitelesito-btn-block">Azonosítás és Bejelentkezés</button>
                </form>

                <div class="hitelesito-auth-footer">
                    <p>&copy; <?php echo date('Y'); ?> <?php bloginfo('name'); ?> — Hitelesítő+ v0.1</p>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
}

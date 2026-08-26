<?php
/**
 * Shortcode Class for Hitelesítő+
 * Registers `[hitelesito_plusz]` shortcode for embedding 2FA configuration UI in any post, page, or widget.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hitelesito_Shortcode {

    private static ?Hitelesito_Shortcode $instance = null;

    public static function get_instance(): Hitelesito_Shortcode {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode('hitelesito_plusz', [$this, 'render_shortcode']);
    }

    /**
     * Render [hitelesito_plusz] shortcode.
     */
    public function render_shortcode($atts = [], $content = null): string {
        if (!is_user_logged_in()) {
            return '<div class="hitelesito-alert hitelesito-alert-warning">Kérjük, jelentkezzen be a kéttényezős hitelesítés (2FA) beállításához!</div>';
        }

        $user = wp_get_current_user();

        // Check if user's role is set to hidden
        $login_handler = Hitelesito_Login::get_instance();
        $requirement = $login_handler->get_user_highest_requirement($user);

        if ($requirement === 'hidden') {
            return ''; // Hide completely for roles with 'hidden' requirement
        }

        ob_start();
        $this->handle_shortcode_form_submissions($user);
        $this->render_user_2fa_widget($user);
        return ob_get_clean();
    }

    /**
     * Process shortcode form actions (Enable / Disable TOTP).
     */
    private function handle_shortcode_form_submissions(WP_User $user): void {
        if (!isset($_POST['hitelesito_shortcode_action'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'hitelesito_shortcode_nonce')) {
            echo '<div class="hitelesito-alert hitelesito-alert-danger">Biztonsági ellenőrzés sikertelen. Próbálja újra.</div>';
            return;
        }

        $action = sanitize_text_field($_POST['hitelesito_shortcode_action']);

        if ($action === 'enable_2fa') {
            $secret = sanitize_text_field($_POST['secret'] ?? '');
            $code = sanitize_text_field($_POST['code'] ?? '');

            if (empty($secret) || empty($code)) {
                echo '<div class="hitelesito-alert hitelesito-alert-danger">Minden mező kitöltése kötelező!</div>';
                return;
            }

            if (Hitelesito_TOTP::verify_code($secret, $code)) {
                update_user_meta($user->ID, 'hitelesito_totp_secret', $secret);
                update_user_meta($user->ID, 'hitelesito_totp_enabled', 1);
                echo '<div class="hitelesito-alert hitelesito-alert-success">Sikeres! A kéttényezős hitelesítés (2FA) mostantól aktív a fiókján.</div>';
            } else {
                echo '<div class="hitelesito-alert hitelesito-alert-danger">Hibás ellenőrző kód! Próbálja újra az hitelesítő alkalmazásban lévő kóddal.</div>';
            }
        } elseif ($action === 'disable_2fa') {
            // Check if requirement is required. If required, user cannot disable it!
            $login_handler = Hitelesito_Login::get_instance();
            $requirement = $login_handler->get_user_highest_requirement($user);

            if ($requirement === 'required') {
                echo '<div class="hitelesito-alert hitelesito-alert-danger">Az Ön felhasználói szerepkörében a kéttényezős hitelesítés (2FA) kötelező, ezért nem kikapcsolható.</div>';
                return;
            }

            delete_user_meta($user->ID, 'hitelesito_totp_secret');
            delete_user_meta($user->ID, 'hitelesito_totp_enabled');
            echo '<div class="hitelesito-alert hitelesito-alert-success">A kéttényezős hitelesítést kikapcsoltuk a fiókján.</div>';
        }
    }

    /**
     * Render the shortcode UI widget.
     */
    private function render_user_2fa_widget(WP_User $user): void {
        $enabled = (bool) get_user_meta($user->ID, 'hitelesito_totp_enabled', true);
        $secret = get_user_meta($user->ID, 'hitelesito_totp_secret', true);

        wp_enqueue_style('hitelesito-plusz-style');

        ?>
        <div class="hitelesito-widget-card">
            <div class="hitelesito-widget-header">
                <h3>Hitelesítő+ 2FA Beállítások</h3>
                <span class="hitelesito-badge <?php echo $enabled ? 'hitelesito-badge-active' : 'hitelesito-badge-inactive'; ?>">
                    <?php echo $enabled ? 'AKTÍV' : 'INAKTÍV'; ?>
                </span>
            </div>

            <?php if ($enabled): ?>
                <div class="hitelesito-widget-body">
                    <p class="hitelesito-status-text">
                        <span class="dashicons dashicons-shield"></span> A fiókja védve van a Microsoft / Google Authenticator kéttényezős azonosítással.
                    </p>

                    <form method="post" class="hitelesito-widget-form">
                        <?php wp_nonce_field('hitelesito_shortcode_nonce'); ?>
                        <input type="hidden" name="hitelesito_shortcode_action" value="disable_2fa">
                        <button type="submit" class="hitelesito-btn hitelesito-btn-danger" onclick="return confirm('Biztosan ki szeretné kapcsolni a 2FA védelmet?');">2FA Kikapcsolása</button>
                    </form>
                </div>
            <?php else:
                $new_secret = Hitelesito_TOTP::generate_secret();
                $otpauth_url = Hitelesito_TOTP::get_otpauth_url($user->user_email, $new_secret, get_bloginfo('name'));
                $qr_code_url = Hitelesito_TOTP::get_qr_code_image_url($otpauth_url);
            ?>
                <div class="hitelesito-widget-body">
                    <p>Kövesse az alábbi lépéseket a 2FA beállításához:</p>
                    <ol class="hitelesito-steps">
                        <li>Töltse le a <strong>Microsoft Authenticator</strong> vagy <strong>Google Authenticator</strong> alkalmazást a telefonjára.</li>
                        <li>Olvassa be a QR kódot vagy adja meg a manuális kulcsot:</li>
                    </ol>

                    <div class="hitelesito-qr-box">
                        <img src="<?php echo esc_url($qr_code_url); ?>" alt="2FA QR Code" width="180" height="180">
                    </div>

                    <p class="hitelesito-secret-key">Titkos Kulcs: <code><?php echo esc_html($new_secret); ?></code></p>

                    <form method="post" class="hitelesito-widget-form">
                        <?php wp_nonce_field('hitelesito_shortcode_nonce'); ?>
                        <input type="hidden" name="hitelesito_shortcode_action" value="enable_2fa">
                        <input type="hidden" name="secret" value="<?php echo esc_attr($new_secret); ?>">

                        <div class="hitelesito-form-group">
                            <label for="shortcode_totp_code">Adja meg a generált 6-jegyű kódot az aktiváláshoz:</label>
                            <input type="text" id="shortcode_totp_code" name="code" maxlength="6" pattern="[0-9]{6}" autocomplete="off" placeholder="123456" required class="hitelesito-code-input">
                        </div>

                        <button type="submit" class="hitelesito-btn hitelesito-btn-success">2FA Aktiválása</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

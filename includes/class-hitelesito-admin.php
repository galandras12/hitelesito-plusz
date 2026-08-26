<?php
/**
 * Admin Settings Class for Hitelesítő+
 * Provides settings page, role-based 2FA options, timezone display.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hitelesito_Admin {

    private static ?Hitelesito_Admin $instance = null;
    public const OPTION_KEY = 'hitelesito_plusz_role_requirements';

    public static function get_instance(): Hitelesito_Admin {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function add_admin_menu(): void {
        add_menu_page(
            'Hitelesítő+',
            'Hitelesítő+',
            'manage_options',
            'hitelesito-plusz',
            [$this, 'render_settings_page'],
            'dashicons-shield-alt',
            80
        );
    }

    public function register_settings(): void {
        register_setting('hitelesito_plusz_settings_group', self::OPTION_KEY);
    }

    /**
     * Get 2FA requirement rule for a specific role.
     * Options: 'required' (kötelező), 'optional' (opcionális), 'hidden' (ne is legyen látható)
     */
    public static function get_role_requirement(string $role): string {
        $options = get_option(self::OPTION_KEY, []);
        if (is_array($options) && isset($options[$role])) {
            return $options[$role];
        }
        // Default to optional
        return 'optional';
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Get WP timezone
        if (function_exists('wp_timezone_string')) {
            $timezone_str = wp_timezone_string();
        } else {
            $timezone_str = get_option('timezone_string') ?: 'UTC';
        }

        $datetime = new DateTime('now', new DateTimeZone($timezone_str));
        $formatted_time = $datetime->format('Y-m-d H:i:s (P)');

        global $wp_roles;
        if (!isset($wp_roles)) {
            $wp_roles = new WP_Roles();
        }
        $roles = $wp_roles->get_names();

        $saved_settings = get_option(self::OPTION_KEY, []);
        ?>
        <div class="wrap hitelesito-admin-container">
            <div class="hitelesito-header">
                <h1>Hitelesítő+ <span class="hitelesito-badge">v<?php echo esc_html(HITELESITO_PLUSZ_VERSION); ?></span></h1>
                <p class="hitelesito-subtitle">Kéttényezős hitelesítés (TOTP) beállításai a WordPress rendszerhez</p>
            </div>

            <div class="hitelesito-card hitelesito-info-card">
                <h3><span class="dashicons dashicons-clock"></span> Rendszer Időzóna & Pontos Idő</h3>
                <p>A TOTP (kétlépcsős azonosító) kódok generálásához és ellenőrzéséhez elengedhetetlen a pontos szerveridő.</p>
                <div class="hitelesito-time-display">
                    <strong>Beállított időzóna:</strong> <code><?php echo esc_html($timezone_str); ?></code><br>
                    <strong>Aktuális pontos idő:</strong> <code><?php echo esc_html($formatted_time); ?></code>
                </div>
            </div>

            <form method="post" action="options.php">
                <?php
                settings_fields('hitelesito_plusz_settings_group');
                do_settings_sections('hitelesito_plusz_settings_group');
                ?>

                <div class="hitelesito-card">
                    <h3><span class="dashicons dashicons-groups"></span> Jogosultsági Körök (Szerepkörök) Beállításai</h3>
                    <p>Módosítsa, hogy mely felhasználói szerepkörök számára legyen kötelező, opcionális vagy rejtett a 2FA beállítása.</p>

                    <table class="hitelesito-table">
                        <thead>
                            <tr>
                                <th>Felhasználói Szerepkör</th>
                                <th>Beállítás módja</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roles as $role_key => $role_name):
                                $current_val = $saved_settings[$role_key] ?? 'optional';
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html(translate_user_role($role_name)); ?></strong> <code>(<?php echo esc_html($role_key); ?>)</code></td>
                                <td>
                                    <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($role_key); ?>]">
                                        <option value="optional" <?php selected($current_val, 'optional'); ?>>Opcionális (A felhasználó döntheti el)</option>
                                        <option value="required" <?php selected($current_val, 'required'); ?>>Kötelező (Azonnali beállítás szükséges)</option>
                                        <option value="hidden" <?php selected($current_val, 'hidden'); ?>>Rejtett (Ne is legyen látható a felület)</option>
                                    </select>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php submit_button('Mentés', 'primary hitelesito-btn'); ?>
                </div>
            </form>

            <div class="hitelesito-card">
                <h3><span class="dashicons dashicons-shortcode"></span> Shortcode Használat</h3>
                <p>Bármelyik oldalba, bejegyzésbe vagy widgetbe beillesztheti a <code>[hitelesito_plusz]</code> shortcode-ot.</p>
                <p>Ez megjeleníti a bejelentkezett felhasználónak a TOTP 2FA beállítási felületét (QR kód, titkos kulcs, tesztelés és állapot).</p>
            </div>

            <div class="hitelesito-footer">
                <p>Fejlesztő: <strong>galandras12 + AI</strong> | Hitelesítő+ Verzió: 0.1</p>
            </div>
        </div>
        <?php
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A felhasználó szerkesztése (profile.php / user-edit.php) oldalon egy
 * "Hitelesítő+" doboz, ahol adminok felhasználónként letilthatják bármelyik
 * beállított hitelesítő módszert (pl. ha valaki elveszíti a hozzáférését).
 */
class H2F_User_Profile {

	public static function init() {
		add_action( 'show_user_profile', array( __CLASS__, 'render_box' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_box' ) );
		add_action( 'admin_post_h2f_admin_disable_method', array( __CLASS__, 'handle_disable_method' ) );

		add_filter( 'manage_users_columns', array( __CLASS__, 'add_user_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_user_column' ), 10, 3 );
	}

	public static function add_user_column( $columns ) {
		$columns['h2f_status'] = __( 'Hitelesítő+', 'hitelesito-plusz' );
		return $columns;
	}

	public static function render_user_column( $output, $column_name, $user_id ) {
		if ( 'h2f_status' !== $column_name ) {
			return $output;
		}

		$badges = array();
		if ( H2F_TOTP::is_confirmed( $user_id ) ) {
			$badges[] = '<span class="h2f-badge h2f-badge-totp" title="' . esc_attr__( 'TOTP', 'hitelesito-plusz' ) . '">TOTP</span>';
		}
		if ( H2F_Passkey::has_passkeys( $user_id ) ) {
			$badges[] = '<span class="h2f-badge h2f-badge-passkey" title="' . esc_attr__( 'Passkey', 'hitelesito-plusz' ) . '">Passkey</span>';
		}
		if ( get_user_meta( $user_id, 'h2f_email_enabled', true ) ) {
			$badges[] = '<span class="h2f-badge h2f-badge-email" title="' . esc_attr__( 'E-mail', 'hitelesito-plusz' ) . '">E-mail</span>';
		}

		if ( empty( $badges ) ) {
			return '<span style="color:#a7aaad;">—</span>';
		}

		return implode( ' ', $badges );
	}

	public static function render_box( $user ) {
		if ( ! current_user_can( 'edit_users' ) && get_current_user_id() !== $user->ID ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Hitelesítő+ - kétfaktoros hitelesítés', 'hitelesito-plusz' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'TOTP hitelesítő alkalmazás', 'hitelesito-plusz' ); ?></th>
				<td>
					<?php if ( H2F_TOTP::is_confirmed( $user->ID ) ) : ?>
						<span class="h2f-status-on"><?php esc_html_e( 'Beállítva', 'hitelesito-plusz' ); ?></span>
						<?php if ( current_user_can( 'edit_users' ) ) : ?>
							<?php self::render_disable_button( $user->ID, 'totp' ); ?>
						<?php endif; ?>
					<?php else : ?>
						<span class="h2f-status-off"><?php esc_html_e( 'Nincs beállítva', 'hitelesito-plusz' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Passkey-ek', 'hitelesito-plusz' ); ?></th>
				<td>
					<?php $passkeys = H2F_Passkey::get_user_passkeys( $user->ID ); ?>
					<?php if ( $passkeys ) : ?>
						<ul style="margin:0;">
						<?php foreach ( $passkeys as $pk ) : ?>
							<li><?php echo esc_html( $pk->device_name ? $pk->device_name : __( 'Névtelen eszköz', 'hitelesito-plusz' ) ); ?>
								(<?php echo esc_html( date_i18n( 'Y-m-d', strtotime( $pk->created_at ) ) ); ?>)</li>
						<?php endforeach; ?>
						</ul>
						<?php if ( current_user_can( 'edit_users' ) ) : ?>
							<?php self::render_disable_button( $user->ID, 'passkey' ); ?>
						<?php endif; ?>
					<?php else : ?>
						<span class="h2f-status-off"><?php esc_html_e( 'Nincs beállítva', 'hitelesito-plusz' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Biztonsági mentési kódok', 'hitelesito-plusz' ); ?></th>
				<td>
					<?php if ( H2F_Backup_Codes::has_codes( $user->ID ) ) : ?>
						<span class="h2f-status-on">
							<?php
							printf(
								/* translators: %d: remaining backup code count */
								esc_html__( '%d db fel nem használt kód', 'hitelesito-plusz' ),
								(int) H2F_Backup_Codes::count_remaining( $user->ID )
							);
							?>
						</span>
						<?php if ( current_user_can( 'edit_users' ) ) : ?>
							<?php self::render_disable_button( $user->ID, 'backup' ); ?>
						<?php endif; ?>
					<?php else : ?>
						<span class="h2f-status-off"><?php esc_html_e( 'Nincs generálva', 'hitelesito-plusz' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	protected static function render_disable_button( $user_id, $method ) {
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=h2f_admin_disable_method&user_id=' . $user_id . '&method=' . $method ),
			'h2f_admin_disable_method_' . $user_id . '_' . $method
		);
		?>
		<a href="<?php echo esc_url( $url ); ?>" class="button button-secondary h2f-disable-btn"
			onclick="return confirm('<?php echo esc_js( __( 'Biztosan letiltod ezt a hitelesítő módszert ennél a felhasználónál?', 'hitelesito-plusz' ) ); ?>');">
			<?php esc_html_e( 'Letiltás', 'hitelesito-plusz' ); ?>
		</a>
		<?php
	}

	public static function handle_disable_method() {
		if ( ! current_user_can( 'edit_users' ) ) {
			wp_die( esc_html__( 'Nincs jogosultságod ehhez a művelethez.', 'hitelesito-plusz' ) );
		}

		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		$method  = isset( $_GET['method'] ) ? sanitize_key( $_GET['method'] ) : '';

		check_admin_referer( 'h2f_admin_disable_method_' . $user_id . '_' . $method );

		switch ( $method ) {
			case 'totp':
				H2F_TOTP::disable( $user_id );
				break;
			case 'passkey':
				H2F_Passkey::disable_all( $user_id );
				break;
			case 'backup':
				H2F_Backup_Codes::disable( $user_id );
				break;
			case 'email':
				delete_user_meta( $user_id, 'h2f_email_enabled' );
				break;
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'user-edit.php?user_id=' . $user_id ) );
		exit;
	}
}

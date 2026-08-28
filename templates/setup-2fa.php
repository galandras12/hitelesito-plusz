<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** @var WP_User $user */

$totp_confirmed   = H2F_TOTP::is_confirmed( $user->ID );
$passkeys         = H2F_Passkey::get_user_passkeys( $user->ID );
$email_enabled    = (bool) get_user_meta( $user->ID, 'h2f_email_enabled', true );
$backup_remaining = H2F_Backup_Codes::count_remaining( $user->ID );
$has_backup       = H2F_Backup_Codes::has_codes( $user->ID );

$policy_totp    = H2F_Settings::get_policy_for_user( $user, 'totp' );
$policy_passkey = H2F_Settings::get_policy_for_user( $user, 'passkey' );
$policy_email   = H2F_Settings::get_policy_for_user( $user, 'email' );

$show_required_banner = isset( $_GET['h2f_required'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

if ( ! function_exists( 'h2f_policy_pill' ) ) {
	function h2f_policy_pill( $policy ) {
		if ( 'required' === $policy ) {
			return '<span class="h2f-pill h2f-pill-required">' . esc_html__( 'Kötelező', 'hitelesito-plusz' ) . '</span>';
		}
		return '';
	}
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php esc_html_e( 'Kétfaktoros hitelesítés beállításai', 'hitelesito-plusz' ); ?> - <?php bloginfo( 'name' ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="h2f-frontend-body">
	<div class="h2f-shell" style="max-width:560px;">
		<div class="h2f-brand">
			<svg width="26" height="26" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="24" height="24" rx="6" fill="#1d2327"/><path d="M6 6h4v4H6V6zm6 0h2v2h-2V6zm4 0h2v2h-2V6zM6 12h2v2H6v-2zm4 0h4v4h-4v-4zm6 0h2v6h-6v-2h2v-2h2v-2zM6 16h4v2H6v-2z" fill="#fff"/></svg>
			<span class="h2f-brand-name"><?php bloginfo( 'name' ); ?></span>
		</div>

		<div class="h2f-panel">
			<h1><?php esc_html_e( '2 Faktoros hitelesítés beállítása', 'hitelesito-plusz' ); ?></h1>
			<p class="h2f-lead"><?php printf( esc_html__( 'Szia %s! Itt kezelheted a fiókodhoz tartozó kétfaktoros hitelesítő módszereket.', 'hitelesito-plusz' ), esc_html( $user->display_name ) ); ?></p>

			<?php if ( $show_required_banner ) : ?>
				<div class="h2f-alert h2f-alert-info">
					<?php esc_html_e( 'A szerepköröd miatt legalább egy hitelesítő módszer beállítása kötelező. Kérjük, állíts be egyet az alábbiak közül a folytatáshoz.', 'hitelesito-plusz' ); ?>
				</div>
			<?php endif; ?>

			<!-- TOTP -->
			<div class="h2f-setup-card">
				<div class="h2f-setup-card-head">
					<div class="h2f-setup-card-title">
						<span class="h2f-method-icon">📱</span>
						<div>
							<strong><?php esc_html_e( 'Hitelesítő alkalmazás (TOTP)', 'hitelesito-plusz' ); ?></strong>
							<p><?php esc_html_e( 'Google Authenticator, Microsoft Authenticator, stb.', 'hitelesito-plusz' ); ?></p>
						</div>
					</div>
					<div>
						<?php echo h2f_policy_pill( $policy_totp ); // phpcs:ignore ?>
						<span class="h2f-pill <?php echo $totp_confirmed ? 'h2f-pill-on' : 'h2f-pill-off'; ?>" data-h2f-totp-status>
							<?php echo $totp_confirmed ? esc_html__( 'Beállítva', 'hitelesito-plusz' ) : esc_html__( 'Nincs beállítva', 'hitelesito-plusz' ); ?>
						</span>
					</div>
				</div>

				<div class="h2f-setup-card-body" data-h2f-totp-off <?php echo $totp_confirmed ? 'style="display:none;"' : ''; ?>>
					<button type="button" class="h2f-btn h2f-btn-secondary" data-h2f-totp-start><?php esc_html_e( 'Beállítás elindítása', 'hitelesito-plusz' ); ?></button>

					<div data-h2f-totp-setup-box style="display:none; margin-top:18px;">
						<div class="h2f-qr-wrap" data-h2f-qr-holder></div>
						<button type="button" class="h2f-toggle-manual" data-h2f-toggle-manual><?php esc_html_e( 'Nem tudod beolvasni? Kód kézi megadása', 'hitelesito-plusz' ); ?></button>
						<div class="h2f-manual-secret" data-h2f-manual-secret style="display:none;"></div>
						<p class="h2f-lead"><?php esc_html_e( 'Olvasd be a QR kódot a hitelesítő alkalmazással, majd add meg az általa mutatott 6 jegyű kódot a megerősítéshez.', 'hitelesito-plusz' ); ?></p>
						<div class="h2f-alert h2f-alert-error" data-h2f-error="totp-confirm" style="display:none;"></div>
						<input type="text" inputmode="numeric" maxlength="6" class="h2f-code-input" data-h2f-input="totp-confirm" placeholder="000000" />
						<button type="button" class="h2f-btn" data-h2f-totp-confirm><?php esc_html_e( 'Megerősítés és bekapcsolás', 'hitelesito-plusz' ); ?></button>
					</div>
				</div>

				<div class="h2f-setup-card-body" data-h2f-totp-on <?php echo ! $totp_confirmed ? 'style="display:none;"' : ''; ?>>
					<p class="h2f-lead" style="margin-bottom:12px;"><?php esc_html_e( 'A hitelesítő alkalmazás aktív ehhez a fiókhoz.', 'hitelesito-plusz' ); ?></p>
					<button type="button" class="h2f-btn h2f-btn-secondary" data-h2f-totp-disable><?php esc_html_e( 'Letiltás', 'hitelesito-plusz' ); ?></button>
				</div>
			</div>

			<!-- Passkey -->
			<div class="h2f-setup-card">
				<div class="h2f-setup-card-head">
					<div class="h2f-setup-card-title">
						<span class="h2f-method-icon">🔑</span>
						<div>
							<strong><?php esc_html_e( 'Passkey-ek', 'hitelesito-plusz' ); ?></strong>
							<p><?php esc_html_e( 'Ujjlenyomat, arcfelismerés vagy biztonsági kulcs', 'hitelesito-plusz' ); ?></p>
						</div>
					</div>
					<div>
						<?php echo h2f_policy_pill( $policy_passkey ); // phpcs:ignore ?>
						<span class="h2f-pill <?php echo $passkeys ? 'h2f-pill-on' : 'h2f-pill-off'; ?>" data-h2f-passkey-status>
							<?php
							if ( $passkeys ) {
								printf( esc_html__( '%d beállítva', 'hitelesito-plusz' ), count( $passkeys ) );
							} else {
								esc_html_e( 'Nincs beállítva', 'hitelesito-plusz' );
							}
							?>
						</span>
					</div>
				</div>

				<div class="h2f-setup-card-body">
					<div data-h2f-passkey-list>
						<?php foreach ( $passkeys as $pk ) : ?>
							<div class="h2f-passkey-item" data-h2f-passkey-row="<?php echo esc_attr( $pk->id ); ?>">
								<span>🔐 <?php echo esc_html( $pk->device_name ? $pk->device_name : __( 'Névtelen eszköz', 'hitelesito-plusz' ) ); ?>
									<small style="color:#a7aaad;"> - <?php echo esc_html( date_i18n( 'Y-m-d', strtotime( $pk->created_at ) ) ); ?></small>
								</span>
								<button type="button" class="h2f-danger-link" data-h2f-passkey-delete="<?php echo esc_attr( $pk->id ); ?>"><?php esc_html_e( 'Törlés', 'hitelesito-plusz' ); ?></button>
							</div>
						<?php endforeach; ?>
					</div>

					<div class="h2f-alert h2f-alert-error" data-h2f-error="passkey-setup" style="display:none;"></div>

					<div data-h2f-passkey-name-box style="display:none; margin-top:14px;">
						<div class="h2f-inline-field">
							<input type="text" data-h2f-input="passkey-name" placeholder="<?php esc_attr_e( 'Eszköz neve (pl. Munkahelyi laptop)', 'hitelesito-plusz' ); ?>" />
							<button type="button" class="h2f-btn" style="width:auto;" data-h2f-passkey-confirm><?php esc_html_e( 'Folytatás', 'hitelesito-plusz' ); ?></button>
						</div>
					</div>

					<button type="button" class="h2f-btn h2f-btn-secondary" style="margin-top:10px;" data-h2f-passkey-add><?php esc_html_e( '+ Új passkey hozzáadása', 'hitelesito-plusz' ); ?></button>
				</div>
			</div>

			<!-- Email -->
			<div class="h2f-setup-card">
				<div class="h2f-setup-card-head">
					<div class="h2f-setup-card-title">
						<span class="h2f-method-icon">✉️</span>
						<div>
							<strong><?php esc_html_e( 'E-mail kód', 'hitelesito-plusz' ); ?></strong>
							<p><?php echo esc_html( $user->user_email ); ?></p>
						</div>
					</div>
					<div>
						<?php echo h2f_policy_pill( $policy_email ); // phpcs:ignore ?>
						<span class="h2f-pill <?php echo $email_enabled ? 'h2f-pill-on' : 'h2f-pill-off'; ?>" data-h2f-email-status>
							<?php echo $email_enabled ? esc_html__( 'Bekapcsolva', 'hitelesito-plusz' ) : esc_html__( 'Kikapcsolva', 'hitelesito-plusz' ); ?>
						</span>
					</div>
				</div>
				<div class="h2f-setup-card-body">
					<button type="button" class="h2f-btn h2f-btn-secondary" data-h2f-email-toggle data-current="<?php echo $email_enabled ? '1' : '0'; ?>">
						<?php echo $email_enabled ? esc_html__( 'Kikapcsolás', 'hitelesito-plusz' ) : esc_html__( 'Bekapcsolás', 'hitelesito-plusz' ); ?>
					</button>
				</div>
			</div>

			<!-- Backup codes -->
			<div class="h2f-setup-card">
				<div class="h2f-setup-card-head">
					<div class="h2f-setup-card-title">
						<span class="h2f-method-icon">🗝️</span>
						<div>
							<strong><?php esc_html_e( 'Biztonsági mentési kódok', 'hitelesito-plusz' ); ?></strong>
							<p><?php esc_html_e( 'Vészhelyzeti belépés, ha más módszer nem elérhető', 'hitelesito-plusz' ); ?></p>
						</div>
					</div>
					<span class="h2f-pill <?php echo $has_backup ? 'h2f-pill-on' : 'h2f-pill-off'; ?>" data-h2f-backup-status>
						<?php
						if ( $has_backup ) {
							printf( esc_html__( '%d kód hátra', 'hitelesito-plusz' ), (int) $backup_remaining );
						} else {
							esc_html_e( 'Nincs generálva', 'hitelesito-plusz' );
						}
						?>
					</span>
				</div>
				<div class="h2f-setup-card-body">
					<div data-h2f-backup-codes-box style="display:none;"></div>
					<p class="h2f-lead"><?php esc_html_e( 'Az új kódok generálása érvényteleníti a régieket. Töltsd le és tárold biztonságos helyen.', 'hitelesito-plusz' ); ?></p>
					<button type="button" class="h2f-btn h2f-btn-secondary" data-h2f-backup-generate><?php esc_html_e( 'Új kódok generálása', 'hitelesito-plusz' ); ?></button>
					<?php if ( $has_backup ) : ?>
						<button type="button" class="h2f-danger-link" style="display:block; margin-top:12px;" data-h2f-backup-disable><?php esc_html_e( 'Összes kód érvénytelenítése', 'hitelesito-plusz' ); ?></button>
					<?php endif; ?>
				</div>
			</div>

			<p style="text-align:center; margin-top:8px;">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="color:#6b7280; font-size:13px; text-decoration:none;">← <?php esc_html_e( 'Vissza a főoldalra', 'hitelesito-plusz' ); ?></a>
			</p>
		</div>
	</div>

	<script>
	document.addEventListener('DOMContentLoaded', function () {
		if (window.H2FSetup) {
			window.H2FSetup.init();
		}
	});
	</script>

	<?php wp_footer(); ?>
</body>
</html>

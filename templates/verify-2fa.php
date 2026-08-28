<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** @var WP_User $user */
/** @var array $session */

$has_totp    = H2F_TOTP::is_confirmed( $user->ID );
$has_passkey = H2F_Passkey::has_passkeys( $user->ID );
$has_email   = 'hidden' !== H2F_Settings::get_policy_for_user( $user, 'email' );
$has_backup  = H2F_Backup_Codes::has_codes( $user->ID );

$methods_available = array();
if ( $has_totp ) {
	$methods_available[] = 'totp';
}
if ( $has_passkey ) {
	$methods_available[] = 'passkey';
}
if ( $has_email ) {
	$methods_available[] = 'email';
}

$first_method = ! empty( $methods_available ) ? $methods_available[0] : '';
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php esc_html_e( 'Kétfaktoros hitelesítés', 'hitelesito-plusz' ); ?> - <?php bloginfo( 'name' ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="h2f-frontend-body">
	<div class="h2f-shell">
		<div class="h2f-brand">
			<svg width="26" height="26" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="24" height="24" rx="6" fill="#1d2327"/><path d="M6 6h4v4H6V6zm6 0h2v2h-2V6zm4 0h2v2h-2V6zM6 12h2v2H6v-2zm4 0h4v4h-4v-4zm6 0h2v6h-6v-2h2v-2h2v-2zM6 16h4v2H6v-2z" fill="#fff"/></svg>
			<span class="h2f-brand-name"><?php bloginfo( 'name' ); ?></span>
		</div>

		<div class="h2f-panel">

			<!-- Módszer választó -->
			<div data-h2f-view="choose" <?php echo $first_method ? 'style="display:none;"' : ''; ?>>
				<h1><?php esc_html_e( 'Kétfaktoros hitelesítés', 'hitelesito-plusz' ); ?></h1>
				<p class="h2f-lead"><?php printf( esc_html__( 'Szia %s! Válaszd ki, hogyan szeretnéd megerősíteni a bejelentkezést.', 'hitelesito-plusz' ), esc_html( $user->display_name ) ); ?></p>

				<div class="h2f-method-list">
					<?php if ( $has_totp ) : ?>
					<button type="button" class="h2f-method-btn" data-h2f-goto="totp">
						<span class="h2f-method-icon">📱</span>
						<span class="h2f-method-text">
							<strong><?php esc_html_e( 'Hitelesítő alkalmazás', 'hitelesito-plusz' ); ?></strong>
							<span><?php esc_html_e( 'Kód a Google/Microsoft Authenticatorból', 'hitelesito-plusz' ); ?></span>
						</span>
					</button>
					<?php endif; ?>

					<?php if ( $has_passkey ) : ?>
					<button type="button" class="h2f-method-btn" data-h2f-goto="passkey">
						<span class="h2f-method-icon">🔑</span>
						<span class="h2f-method-text">
							<strong><?php esc_html_e( 'Passkey', 'hitelesito-plusz' ); ?></strong>
							<span><?php esc_html_e( 'Ujjlenyomat, arcfelismerés vagy biztonsági kulcs', 'hitelesito-plusz' ); ?></span>
						</span>
					</button>
					<?php endif; ?>

					<?php if ( $has_email ) : ?>
					<button type="button" class="h2f-method-btn" data-h2f-goto="email">
						<span class="h2f-method-icon">✉️</span>
						<span class="h2f-method-text">
							<strong><?php esc_html_e( 'E-mail kód', 'hitelesito-plusz' ); ?></strong>
							<span><?php esc_html_e( 'Egyszer használatos kód e-mailben', 'hitelesito-plusz' ); ?></span>
						</span>
					</button>
					<?php endif; ?>

					<?php if ( $has_backup ) : ?>
					<button type="button" class="h2f-method-btn" data-h2f-goto="backup">
						<span class="h2f-method-icon">🗝️</span>
						<span class="h2f-method-text">
							<strong><?php esc_html_e( 'Biztonsági mentési kód', 'hitelesito-plusz' ); ?></strong>
							<span><?php esc_html_e( 'Ha egyik fenti sem elérhető', 'hitelesito-plusz' ); ?></span>
						</span>
					</button>
					<?php endif; ?>
				</div>
			</div>

			<!-- TOTP -->
			<div data-h2f-view="totp" style="display:none;">
				<?php if ( count( $methods_available ) > 1 || $has_backup ) : ?>
					<button type="button" class="h2f-back-link" data-h2f-goto="choose">← <?php esc_html_e( 'Másik módszer', 'hitelesito-plusz' ); ?></button>
				<?php endif; ?>
				<h1><?php esc_html_e( 'Add meg a hitelesítő kódot', 'hitelesito-plusz' ); ?></h1>
				<p class="h2f-lead"><?php esc_html_e( 'Nyisd meg a hitelesítő alkalmazást (pl. Google Authenticator vagy Microsoft Authenticator), és írd be az aktuális 6 jegyű kódot.', 'hitelesito-plusz' ); ?></p>
				<div class="h2f-alert h2f-alert-error" data-h2f-error="totp" style="display:none;"></div>
				<input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" class="h2f-code-input" data-h2f-input="totp" placeholder="000000" />
				<button type="button" class="h2f-btn" data-h2f-submit="totp"><?php esc_html_e( 'Megerősítés', 'hitelesito-plusz' ); ?></button>
			</div>

			<!-- Passkey -->
			<div data-h2f-view="passkey" style="display:none;">
				<?php if ( count( $methods_available ) > 1 || $has_backup ) : ?>
					<button type="button" class="h2f-back-link" data-h2f-goto="choose">← <?php esc_html_e( 'Másik módszer', 'hitelesito-plusz' ); ?></button>
				<?php endif; ?>
				<h1><?php esc_html_e( 'Passkey hitelesítés', 'hitelesito-plusz' ); ?></h1>
				<p class="h2f-lead"><?php esc_html_e( 'Kattints a gombra, majd erősítsd meg a böngésző vagy eszköz kérésére (ujjlenyomat, arcfelismerés, PIN vagy biztonsági kulcs).', 'hitelesito-plusz' ); ?></p>
				<div class="h2f-alert h2f-alert-error" data-h2f-error="passkey" style="display:none;"></div>
				<button type="button" class="h2f-btn" data-h2f-submit="passkey"><?php esc_html_e( 'Passkey használata', 'hitelesito-plusz' ); ?></button>
			</div>

			<!-- Email -->
			<div data-h2f-view="email" style="display:none;">
				<?php if ( count( $methods_available ) > 1 || $has_backup ) : ?>
					<button type="button" class="h2f-back-link" data-h2f-goto="choose">← <?php esc_html_e( 'Másik módszer', 'hitelesito-plusz' ); ?></button>
				<?php endif; ?>
				<h1><?php esc_html_e( 'E-mail kód', 'hitelesito-plusz' ); ?></h1>
				<p class="h2f-lead" data-h2f-email-lead><?php esc_html_e( 'Kattints a gombra, és küldünk egy egyszer használatos kódot a fiókodhoz tartozó e-mail címre.', 'hitelesito-plusz' ); ?></p>
				<div class="h2f-alert h2f-alert-error" data-h2f-error="email" style="display:none;"></div>
				<div class="h2f-alert h2f-alert-success" data-h2f-info="email" style="display:none;"></div>

				<button type="button" class="h2f-btn" data-h2f-send="email"><?php esc_html_e( 'Kód küldése', 'hitelesito-plusz' ); ?></button>

				<div data-h2f-email-code style="display:none; margin-top:16px;">
					<input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" class="h2f-code-input" data-h2f-input="email" placeholder="000000" />
					<button type="button" class="h2f-btn" data-h2f-submit="email"><?php esc_html_e( 'Megerősítés', 'hitelesito-plusz' ); ?></button>
					<button type="button" class="h2f-link-btn" data-h2f-send="email"><?php esc_html_e( 'Új kód küldése', 'hitelesito-plusz' ); ?></button>
				</div>
			</div>

			<!-- Backup -->
			<div data-h2f-view="backup" style="display:none;">
				<button type="button" class="h2f-back-link" data-h2f-goto="choose">← <?php esc_html_e( 'Másik módszer', 'hitelesito-plusz' ); ?></button>
				<h1><?php esc_html_e( 'Biztonsági mentési kód', 'hitelesito-plusz' ); ?></h1>
				<p class="h2f-lead"><?php esc_html_e( 'Add meg az egyik letöltött, még fel nem használt biztonsági mentési kódot. Minden kód csak egyszer használható.', 'hitelesito-plusz' ); ?></p>
				<div class="h2f-alert h2f-alert-error" data-h2f-error="backup" style="display:none;"></div>
				<input type="text" autocapitalize="characters" class="h2f-code-input" style="letter-spacing:3px;font-size:18px;" data-h2f-input="backup" placeholder="XXXXX-XXXXX" />
				<button type="button" class="h2f-btn" data-h2f-submit="backup"><?php esc_html_e( 'Megerősítés', 'hitelesito-plusz' ); ?></button>
			</div>

		</div>

		<p class="h2f-footer-note"><?php esc_html_e( 'Hitelesítő+ - biztonságos kétfaktoros hitelesítés', 'hitelesito-plusz' ); ?></p>
	</div>

	<script>
	document.addEventListener('DOMContentLoaded', function () {
		if (window.H2FVerify) {
			window.H2FVerify.init({
				firstMethod: <?php echo wp_json_encode( $first_method ); ?>
			});
		}
	});
	</script>

	<?php wp_footer(); ?>
</body>
</html>

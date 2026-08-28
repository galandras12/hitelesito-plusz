<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [hitelesito_plusz_beallitas] shortcode - bejegyzésbe, oldalba vagy
 * widgetbe illeszthető hivatkozás a felhasználó saját 2FA beállító
 * felületére.
 */
class H2F_Shortcode {

	public static function init() {
		add_shortcode( 'hitelesito_plusz_beallitas', array( __CLASS__, 'render' ) );
		add_shortcode( 'h2f_2fa_setup', array( __CLASS__, 'render' ) ); // rövidebb alias
	}

	public static function render( $atts ) {
		$atts = shortcode_atts( array(
			'label' => __( '2 Faktoros hitelesítés beállítása', 'hitelesito-plusz' ),
			'class' => '',
		), $atts, 'hitelesito_plusz_beallitas' );

		$url = H2F_Login_Flow::setup_url();

		if ( ! is_user_logged_in() ) {
			$url = wp_login_url( $url );
		}

		return sprintf(
			'<a href="%1$s" class="h2f-setup-link %2$s">%3$s</a>',
			esc_url( $url ),
			esc_attr( $atts['class'] ),
			esc_html( $atts['label'] )
		);
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TOTP (RFC 6238) / HOTP (RFC 4226) implementáció külső függőség nélkül.
 */
class H2F_TOTP {

	const SECRET_LENGTH = 20; // 160 bit, Base32-ben ~32 karakter
	const PERIOD        = 30;
	const DIGITS        = 6;

	/**
	 * Új Base32 titkos kulcs generálása.
	 */
	public static function generate_secret() {
		$bytes = random_bytes( self::SECRET_LENGTH );
		return self::base32_encode( $bytes );
	}

	public static function base32_encode( $data ) {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$binary   = '';
		foreach ( str_split( $data ) as $char ) {
			$binary .= str_pad( decbin( ord( $char ) ), 8, '0', STR_PAD_LEFT );
		}
		$output = '';
		foreach ( str_split( $binary, 5 ) as $chunk ) {
			$chunk = str_pad( $chunk, 5, '0', STR_PAD_RIGHT );
			$output .= $alphabet[ bindec( $chunk ) ];
		}
		return $output;
	}

	public static function base32_decode( $b32 ) {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$b32      = strtoupper( preg_replace( '/[^A-Z2-7]/', '', $b32 ) );
		$binary   = '';
		foreach ( str_split( $b32 ) as $char ) {
			$pos = strpos( $alphabet, $char );
			if ( false === $pos ) {
				continue;
			}
			$binary .= str_pad( decbin( $pos ), 5, '0', STR_PAD_LEFT );
		}
		$bytes = '';
		foreach ( str_split( $binary, 8 ) as $chunk ) {
			if ( strlen( $chunk ) < 8 ) {
				continue;
			}
			$bytes .= chr( bindec( $chunk ) );
		}
		return $bytes;
	}

	/**
	 * HOTP kód generálása egy adott számlálóra.
	 */
	public static function hotp( $secret_base32, $counter ) {
		$key    = self::base32_decode( $secret_base32 );
		$binary = pack( 'N*', 0 ) . pack( 'N*', $counter ); // 8 bájtos big-endian számláló
		$hash   = hash_hmac( 'sha1', $binary, $key, true );

		$offset = ord( substr( $hash, -1 ) ) & 0x0F;
		$part   = substr( $hash, $offset, 4 );

		$value = unpack( 'N', $part )[1] & 0x7FFFFFFF;
		$otp   = $value % ( 10 ** self::DIGITS );

		return str_pad( (string) $otp, self::DIGITS, '0', STR_PAD_LEFT );
	}

	/**
	 * TOTP kód egy adott időpontra.
	 */
	public static function totp_at( $secret_base32, $timestamp = null ) {
		if ( null === $timestamp ) {
			$timestamp = time();
		}
		$counter = (int) floor( $timestamp / self::PERIOD );
		return self::hotp( $secret_base32, $counter );
	}

	/**
	 * Kód ellenőrzése +-1 időablak toleranciával (óra-eltolódás miatt).
	 */
	public static function verify( $secret_base32, $code, $window = 1 ) {
		$code = preg_replace( '/\s+/', '', (string) $code );
		if ( ! preg_match( '/^\d{6}$/', $code ) ) {
			return false;
		}

		$current_counter = (int) floor( time() / self::PERIOD );

		for ( $i = -$window; $i <= $window; $i++ ) {
			$candidate = self::hotp( $secret_base32, $current_counter + $i );
			if ( hash_equals( $candidate, $code ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * otpauth:// URI a QR kódhoz.
	 */
	public static function provisioning_uri( $secret_base32, $account_name, $issuer ) {
		$label = rawurlencode( $issuer ) . ':' . rawurlencode( $account_name );
		$params = http_build_query( array(
			'secret'    => $secret_base32,
			'issuer'    => $issuer,
			'algorithm' => 'SHA1',
			'digits'    => self::DIGITS,
			'period'    => self::PERIOD,
		) );
		return 'otpauth://totp/' . $label . '?' . $params;
	}

	public static function get_secret( $user_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . H2F_DB::table_totp() . " WHERE user_id = %d",
			$user_id
		) );
	}

	public static function is_confirmed( $user_id ) {
		$row = self::get_secret( $user_id );
		return $row && (int) $row->confirmed === 1;
	}

	public static function save_new_secret( $user_id, $secret ) {
		global $wpdb;
		$wpdb->replace(
			H2F_DB::table_totp(),
			array(
				'user_id'    => $user_id,
				'secret'     => $secret,
				'confirmed'  => 0,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%s' )
		);
	}

	public static function confirm( $user_id ) {
		global $wpdb;
		$wpdb->update(
			H2F_DB::table_totp(),
			array( 'confirmed' => 1 ),
			array( 'user_id' => $user_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	public static function disable( $user_id ) {
		global $wpdb;
		$wpdb->delete( H2F_DB::table_totp(), array( 'user_id' => $user_id ), array( '%d' ) );
	}
}

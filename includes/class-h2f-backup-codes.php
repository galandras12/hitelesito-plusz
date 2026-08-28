<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Előre generált egyszer használatos biztonsági mentési kódok.
 */
class H2F_Backup_Codes {

	const CODE_COUNT  = 10;
	const CODE_LENGTH = 10;

	/**
	 * Új kódkészlet generálása egy felhasználónak (a régiek törlésével).
	 * Visszaadja a nyílt szöveges kódokat (csak ekkor látszanak!).
	 */
	public static function generate_new_set( $user_id ) {
		global $wpdb;

		$wpdb->delete( H2F_DB::table_backup_codes(), array( 'user_id' => $user_id ), array( '%d' ) );

		$plain_codes = array();
		for ( $i = 0; $i < self::CODE_COUNT; $i++ ) {
			$code          = self::generate_single_code();
			$plain_codes[] = $code;

			$wpdb->insert(
				H2F_DB::table_backup_codes(),
				array(
					'user_id'    => $user_id,
					'code_hash'  => wp_hash_password( $code ),
					'used'       => 0,
					'created_at' => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%d', '%s' )
			);
		}

		return $plain_codes;
	}

	protected static function generate_single_code() {
		// Olvasható formátum: XXXXX-XXXXX (nagybetűk + számok, hasonlító karakterek nélkül)
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$raw      = '';
		for ( $i = 0; $i < self::CODE_LENGTH; $i++ ) {
			$raw .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}
		return substr( $raw, 0, 5 ) . '-' . substr( $raw, 5 );
	}

	public static function count_remaining( $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . H2F_DB::table_backup_codes() . " WHERE user_id = %d AND used = 0",
			$user_id
		) );
	}

	public static function has_codes( $user_id ) {
		global $wpdb;
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . H2F_DB::table_backup_codes() . " WHERE user_id = %d",
			$user_id
		) );
		return $total > 0;
	}

	/**
	 * Kód ellenőrzése és felhasználtnak jelölése, ha helyes.
	 */
	public static function verify_and_consume( $user_id, $code ) {
		global $wpdb;

		$code = strtoupper( trim( $code ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . H2F_DB::table_backup_codes() . " WHERE user_id = %d AND used = 0",
			$user_id
		) );

		foreach ( $rows as $row ) {
			if ( wp_check_password( $code, $row->code_hash ) ) {
				$wpdb->update(
					H2F_DB::table_backup_codes(),
					array( 'used' => 1, 'used_at' => current_time( 'mysql' ) ),
					array( 'id' => $row->id ),
					array( '%d', '%s' ),
					array( '%d' )
				);
				return true;
			}
		}
		return false;
	}

	public static function disable( $user_id ) {
		global $wpdb;
		$wpdb->delete( H2F_DB::table_backup_codes(), array( 'user_id' => $user_id ), array( '%d' ) );
	}

	/**
	 * TXT export tartalom generálása.
	 */
	public static function build_txt_content( $user, $codes ) {
		$site_name = get_bloginfo( 'name' );
		$lines   = array();
		$lines[] = '=== ' . $site_name . ' - Hitelesítő+ biztonsági mentési kódok ===';
		$lines[] = 'Felhasználó: ' . $user->user_login;
		$lines[] = 'Generálva: ' . date_i18n( 'Y-m-d H:i' );
		$lines[] = '';
		$lines[] = 'Minden kód egyszer használható fel, ha nem férsz hozzá a többi hitelesítő módszerhez.';
		$lines[] = 'Tárold biztonságos, offline helyen (pl. nyomtatva, széfben).';
		$lines[] = '';
		foreach ( $codes as $i => $code ) {
			$lines[] = str_pad( ( $i + 1 ) . '.', 4 ) . $code;
		}
		$lines[] = '';
		$lines[] = 'Ha ezeket a kódokat felhasználod vagy elveszíted, generálj újakat a fiókod beállításai között.';

		return implode( "\n", $lines );
	}
}

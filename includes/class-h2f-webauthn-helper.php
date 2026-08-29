<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimális, függőségmentes WebAuthn (FIDO2) segédosztály.
 *
 * Támogatja a "none" és "packed" attesztációt, ES256 (EC P-256) és
 * RS256 (RSA) nyilvános kulcsokat - ez fedi a gyakorlatban használt
 * platform hitelesítők (Windows Hello, Touch ID, Android, biztonsági
 * kulcsok) döntő többségét.
 */
class H2F_WebAuthn_Helper {

	public static function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	public static function base64url_decode( $data ) {
		$data = strtr( $data, '-_', '+/' );
		$pad  = strlen( $data ) % 4;
		if ( $pad ) {
			$data .= str_repeat( '=', 4 - $pad );
		}
		return base64_decode( $data );
	}

	public static function generate_challenge() {
		return random_bytes( 32 );
	}

	/* ---------------------------------------------------------------
	 * Nagyon egyszerű CBOR dekóder (csak a WebAuthn válaszokban
	 * előforduló típusokra: map, array, uint, negint, bytestring,
	 * textstring, bool, simple/float placeholder).
	 * ------------------------------------------------------------- */
	public static function cbor_decode( $data ) {
		$offset = 0;
		return self::cbor_decode_item( $data, $offset );
	}

	protected static function cbor_decode_item( $data, &$offset ) {
		$first        = ord( $data[ $offset ] );
		$major_type   = $first >> 5;
		$additional   = $first & 0x1F;
		$offset++;

		$length = self::cbor_read_length( $data, $offset, $additional );

		switch ( $major_type ) {
			case 0: // unsigned int
				return $length;
			case 1: // negative int
				return -1 - $length;
			case 2: // byte string
				$value = substr( $data, $offset, $length );
				$offset += $length;
				return $value;
			case 3: // text string
				$value = substr( $data, $offset, $length );
				$offset += $length;
				return $value;
			case 4: // array
				$arr = array();
				for ( $i = 0; $i < $length; $i++ ) {
					$arr[] = self::cbor_decode_item( $data, $offset );
				}
				return $arr;
			case 5: // map
				$map = array();
				for ( $i = 0; $i < $length; $i++ ) {
					$key         = self::cbor_decode_item( $data, $offset );
					$val         = self::cbor_decode_item( $data, $offset );
					$map[ $key ] = $val;
				}
				return $map;
			case 6: // tag - skip tag, decode wrapped value
				return self::cbor_decode_item( $data, $offset );
			case 7: // float/simple/bool/null
				if ( 20 === $additional ) {
					return false;
				}
				if ( 21 === $additional ) {
					return true;
				}
				if ( 22 === $additional || 23 === $additional ) {
					return null;
				}
				return null;
			default:
				return null;
		}
	}

	protected static function cbor_read_length( $data, &$offset, $additional ) {
		if ( $additional < 24 ) {
			return $additional;
		}
		if ( 24 === $additional ) {
			$val = ord( $data[ $offset ] );
			$offset += 1;
			return $val;
		}
		if ( 25 === $additional ) {
			$val = unpack( 'n', substr( $data, $offset, 2 ) )[1];
			$offset += 2;
			return $val;
		}
		if ( 26 === $additional ) {
			$val = unpack( 'N', substr( $data, $offset, 4 ) )[1];
			$offset += 4;
			return $val;
		}
		if ( 27 === $additional ) {
			$high = unpack( 'N', substr( $data, $offset, 4 ) )[1];
			$low  = unpack( 'N', substr( $data, $offset + 4, 4 ) )[1];
			$offset += 8;
			return ( $high << 32 ) | $low;
		}
		return 0;
	}

	/**
	 * authData feldolgozása (regisztrációs attestationObject "authData" mezője).
	 * Visszaadja: rpIdHash, flags, signCount, credentialId, publicKey (COSE map), aaguid
	 */
	public static function parse_auth_data( $auth_data ) {
		$offset       = 0;
		$rp_id_hash   = substr( $auth_data, $offset, 32 ); $offset += 32;
		$flags_byte   = ord( $auth_data[ $offset ] ); $offset += 1;
		$sign_count   = unpack( 'N', substr( $auth_data, $offset, 4 ) )[1]; $offset += 4;

		$flags = array(
			'up' => (bool) ( $flags_byte & 0x01 ),
			'uv' => (bool) ( $flags_byte & 0x04 ),
			'at' => (bool) ( $flags_byte & 0x40 ),
			'ed' => (bool) ( $flags_byte & 0x80 ),
		);

		$result = array(
			'rp_id_hash' => $rp_id_hash,
			'flags'      => $flags,
			'sign_count' => $sign_count,
		);

		if ( $flags['at'] ) {
			$aaguid = substr( $auth_data, $offset, 16 ); $offset += 16;
			$cred_id_len = unpack( 'n', substr( $auth_data, $offset, 2 ) )[1]; $offset += 2;
			$credential_id = substr( $auth_data, $offset, $cred_id_len ); $offset += $cred_id_len;

			$remaining   = substr( $auth_data, $offset );
			$cbor_offset = 0;
			$public_key  = self::cbor_decode_item( $remaining, $cbor_offset );

			$result['aaguid']        = $aaguid;
			$result['credential_id'] = $credential_id;
			$result['public_key']    = $public_key; // COSE map (int kulcsokkal)
		}

		return $result;
	}

	/**
	 * COSE nyilvános kulcs (map) -> PEM formátum, hogy openssl_verify tudja használni.
	 */
	public static function cose_key_to_pem( $cose_map ) {
		$kty = isset( $cose_map[1] ) ? $cose_map[1] : null; // 2 = EC2, 3 = RSA

		if ( 2 === $kty ) {
			// EC2 kulcs: -1 = crv, -2 = x, -3 = y
			$x = $cose_map[-2];
			$y = $cose_map[-3];
			return self::ec_point_to_pem( $x, $y );
		}

		if ( 3 === $kty ) {
			// RSA kulcs: -1 = n, -2 = e
			$n = $cose_map[-1];
			$e = $cose_map[-2];
			return self::rsa_components_to_pem( $n, $e );
		}

		return false;
	}

	/**
	 * P-256 EC pont (x,y) -> PEM. A DER burkolatot kézzel építjük fel
	 * (SubjectPublicKeyInfo, prime256v1 / secp256r1 OID).
	 */
	protected static function ec_point_to_pem( $x, $y ) {
		$point = "\x04" . $x . $y; // uncompressed point

		// SEQUENCE { SEQUENCE { OID id-ecPublicKey, OID prime256v1 }, BIT STRING point }
		$oid_ec_pubkey = hex2bin( '2a8648ce3d0201' );
		$oid_prime256  = hex2bin( '2a8648ce3d030107' );

		$alg_id = self::der_sequence(
			self::der_object_identifier_raw( $oid_ec_pubkey ) .
			self::der_object_identifier_raw( $oid_prime256 )
		);

		$bit_string = "\x00" . $point;
		$pubkey_der = self::der_sequence(
			$alg_id . self::der_bit_string( $bit_string )
		);

		return self::der_to_pem( $pubkey_der, 'PUBLIC KEY' );
	}

	protected static function rsa_components_to_pem( $n, $e ) {
		$n_int = self::der_integer_from_unsigned( $n );
		$e_int = self::der_integer_from_unsigned( $e );

		$rsa_pubkey_seq = self::der_sequence( $n_int . $e_int );

		$oid_rsa    = hex2bin( '2a864886f70d010101' );
		$alg_id     = self::der_sequence( self::der_object_identifier_raw( $oid_rsa ) . self::der_null() );
		$bit_string = "\x00" . $rsa_pubkey_seq;

		$pubkey_der = self::der_sequence( $alg_id . self::der_bit_string( $bit_string ) );

		return self::der_to_pem( $pubkey_der, 'PUBLIC KEY' );
	}

	/* --- apró DER építő segédek --- */

	protected static function der_length( $len ) {
		if ( $len < 128 ) {
			return chr( $len );
		}
		$bytes = '';
		while ( $len > 0 ) {
			$bytes = chr( $len & 0xFF ) . $bytes;
			$len >>= 8;
		}
		return chr( 0x80 | strlen( $bytes ) ) . $bytes;
	}

	protected static function der_sequence( $content ) {
		return "\x30" . self::der_length( strlen( $content ) ) . $content;
	}

	protected static function der_bit_string( $content ) {
		return "\x03" . self::der_length( strlen( $content ) ) . $content;
	}

	protected static function der_null() {
		return "\x05\x00";
	}

	protected static function der_object_identifier_raw( $raw_oid_bytes ) {
		return "\x06" . self::der_length( strlen( $raw_oid_bytes ) ) . $raw_oid_bytes;
	}

	protected static function der_integer_from_unsigned( $bytes ) {
		// Ha az első bit 1, előtag 0x00 kell, különben negatívnak olvasná az ASN.1 parser.
		if ( strlen( $bytes ) > 0 && ( ord( $bytes[0] ) & 0x80 ) ) {
			$bytes = "\x00" . $bytes;
		}
		return "\x02" . self::der_length( strlen( $bytes ) ) . $bytes;
	}

	protected static function der_to_pem( $der, $label ) {
		$b64 = base64_encode( $der );
		$lines = chunk_split( $b64, 64, "\n" );
		return "-----BEGIN {$label}-----\n{$lines}-----END {$label}-----\n";
	}

	/**
	 * Aláírás ellenőrzése (assertion): OpenSSL SHA256-tal.
	 * A COSE alg -7 (ES256) esetén az aláírás DER-kódolt ECDSA aláírás,
	 * amit openssl_verify natívan tud kezelni.
	 */
	public static function verify_signature( $pem_public_key, $signed_data, $signature ) {
		$result = openssl_verify( $signed_data, $signature, $pem_public_key, OPENSSL_ALGO_SHA256 );
		return 1 === $result;
	}
}
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimális, külső könyvtár nélküli QR kód enkóder.
 * Byte módot és "L" hibajavítási szintet használ, 1-10-es verziótartományban
 * (ez bőven elég egy otpauth:// URI kódolásához). Az eredményt SVG-ként adja vissza,
 * hogy tetszőleges méretben, éles kontraszttal jeleníthető legyen meg
 * (fontos a gyengébb kamerás telefonokkal való beolvasáshoz).
 */
class H2F_QRCode {

	// [version] => data codewords (EC level L)
	protected static $data_codewords = array(
		1 => 19, 2 => 34, 3 => 55, 4 => 80, 5 => 108,
		6 => 136, 7 => 156, 8 => 194, 9 => 232, 10 => 274,
	);

	// [version] => ec codewords per block (EC level L)
	protected static $ec_per_block = array(
		1 => 7, 2 => 10, 3 => 15, 4 => 20, 5 => 26,
		6 => 18, 7 => 20, 8 => 24, 9 => 30, 10 => 18,
	);

	// [version] => array( array(count, data_codewords_per_block), ... groups )
	protected static $block_structure = array(
		1  => array( array( 1, 19 ) ),
		2  => array( array( 1, 34 ) ),
		3  => array( array( 1, 55 ) ),
		4  => array( array( 1, 80 ) ),
		5  => array( array( 1, 108 ) ),
		6  => array( array( 2, 68 ) ),
		7  => array( array( 2, 78 ) ),
		8  => array( array( 2, 97 ) ),
		9  => array( array( 2, 116 ) ),
		10 => array( array( 2, 68 ), array( 2, 69 ) ),
	);

	protected static $remainder_bits = array(
		1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7,
		7 => 0, 8 => 0, 9 => 0, 10 => 0,
	);

	protected static $alignment_positions = array(
		1 => array(),
		2 => array( 6, 18 ),
		3 => array( 6, 22 ),
		4 => array( 6, 26 ),
		5 => array( 6, 30 ),
		6 => array( 6, 34 ),
		7 => array( 6, 22, 38 ),
		8 => array( 6, 24, 42 ),
		9 => array( 6, 26, 46 ),
		10 => array( 6, 28, 50 ),
	);

	protected $version;
	protected $size;
	protected $modules;   // 2D array: true = dark, false = light
	protected $reserved;  // 2D array: true = ne írjunk rá adatot

	/**
	 * Statikus belépési pont: SVG markup legenerálása egy szöveghez.
	 */
	public static function svg_for_text( $text, $module_px = 8, $margin_modules = 4 ) {
		$qr = new self();
		$qr->encode( $text );
		return $qr->to_svg( $module_px, $margin_modules );
	}

	protected function encode( $text ) {
		$data_bytes = array_values( unpack( 'C*', $text ) );

		$version = $this->select_version( count( $data_bytes ) );
		$this->version = $version;
		$this->size    = $version * 4 + 17;

		$bitstream = $this->build_bitstream( $data_bytes, $version );
		$codewords = $this->bits_to_codewords( $bitstream );
		$codewords = $this->pad_codewords( $codewords, self::$data_codewords[ $version ] );

		$blocks    = $this->split_into_blocks( $codewords, $version );
		$final     = $this->interleave_with_ec( $blocks, $version );
		$final_bits = $this->codewords_to_bitstring( $final ) . str_repeat( '0', self::$remainder_bits[ $version ] );

		$this->init_matrix();
		$this->place_function_patterns();
		$this->place_data_bits( $final_bits );

		$mask_id = $this->choose_best_mask();
		$this->apply_mask( $mask_id );
		$this->place_format_info( $mask_id );
		if ( $version >= 7 ) {
			$this->place_version_info();
		}
	}

	protected function select_version( $byte_count ) {
		foreach ( self::$data_codewords as $version => $capacity ) {
			// header: mode(4) + count indicator(8 v<=9 / 16 v>=10), lefoglalt bájtokban becsülve
			$count_bits = ( $version <= 9 ) ? 8 : 16;
			$header_bits = 4 + $count_bits;
			$available_bits = $capacity * 8 - $header_bits - 4; // -4 a terminátornak
			if ( $byte_count * 8 <= $available_bits ) {
				return $version;
			}
		}
		// Ha minden verzióból kifutnánk, csonkoljuk az utolsó (10-es) verzió méretére.
		return 10;
	}

	protected function build_bitstream( $data_bytes, $version ) {
		$max_bytes = self::$data_codewords[ $version ];
		if ( count( $data_bytes ) > $max_bytes - 3 ) {
			$data_bytes = array_slice( $data_bytes, 0, max( 1, $max_bytes - 3 ) );
		}

		$bits = '0100'; // byte mode indicator

		$count_bits = ( $version <= 9 ) ? 8 : 16;
		$bits .= str_pad( decbin( count( $data_bytes ) ), $count_bits, '0', STR_PAD_LEFT );

		foreach ( $data_bytes as $byte ) {
			$bits .= str_pad( decbin( $byte ), 8, '0', STR_PAD_LEFT );
		}

		return $bits;
	}

	protected function bits_to_codewords( $bits ) {
		$capacity_bits = self::$data_codewords[ $this->version ] * 8;

		// Terminátor (max 4 nulla bit, amennyi még belefér)
		$remaining = $capacity_bits - strlen( $bits );
		$terminator_len = min( 4, max( 0, $remaining ) );
		$bits .= str_repeat( '0', $terminator_len );

		// Bájthatárra igazítás
		while ( strlen( $bits ) % 8 !== 0 ) {
			$bits .= '0';
		}

		$codewords = array();
		foreach ( str_split( $bits, 8 ) as $byte ) {
			$codewords[] = bindec( $byte );
		}
		return $codewords;
	}

	protected function pad_codewords( $codewords, $target_count ) {
		$pad_bytes = array( 0xEC, 0x11 );
		$i = 0;
		while ( count( $codewords ) < $target_count ) {
			$codewords[] = $pad_bytes[ $i % 2 ];
			$i++;
		}
		return array_slice( $codewords, 0, $target_count );
	}

	protected function split_into_blocks( $codewords, $version ) {
		$blocks = array();
		$pos = 0;
		foreach ( self::$block_structure[ $version ] as $group ) {
			list( $count, $per_block ) = $group;
			for ( $i = 0; $i < $count; $i++ ) {
				$blocks[] = array_slice( $codewords, $pos, $per_block );
				$pos += $per_block;
			}
		}
		return $blocks;
	}

	/**
	 * Reed-Solomon hibajavító kódszavak számítása és interleave-elés.
	 */
	protected function interleave_with_ec( $blocks, $version ) {
		$ec_len = self::$ec_per_block[ $version ];
		$ec_blocks = array();
		foreach ( $blocks as $block ) {
			$ec_blocks[] = $this->rs_encode( $block, $ec_len );
		}

		$data_out = array();
		$max_len  = max( array_map( 'count', $blocks ) );
		for ( $i = 0; $i < $max_len; $i++ ) {
			foreach ( $blocks as $block ) {
				if ( isset( $block[ $i ] ) ) {
					$data_out[] = $block[ $i ];
				}
			}
		}

		$ec_out = array();
		for ( $i = 0; $i < $ec_len; $i++ ) {
			foreach ( $ec_blocks as $ec_block ) {
				$ec_out[] = $ec_block[ $i ];
			}
		}

		return array_merge( $data_out, $ec_out );
	}

	protected function codewords_to_bitstring( $codewords ) {
		$bits = '';
		foreach ( $codewords as $cw ) {
			$bits .= str_pad( decbin( $cw ), 8, '0', STR_PAD_LEFT );
		}
		return $bits;
	}

	/* -------------------- Reed-Solomon GF(256) -------------------- */

	protected function gf_tables() {
		static $exp = null, $log = null;
		if ( null !== $exp ) {
			return array( $exp, $log );
		}
		$exp = array_fill( 0, 512, 0 );
		$log = array_fill( 0, 256, 0 );
		$x = 1;
		for ( $i = 0; $i < 255; $i++ ) {
			$exp[ $i ] = $x;
			$log[ $x ] = $i;
			$x <<= 1;
			if ( $x & 0x100 ) {
				$x ^= 0x11D;
			}
		}
		for ( $i = 255; $i < 512; $i++ ) {
			$exp[ $i ] = $exp[ $i - 255 ];
		}
		return array( $exp, $log );
	}

	protected function gf_mul( $a, $b ) {
		if ( 0 === $a || 0 === $b ) {
			return 0;
		}
		list( $exp, $log ) = $this->gf_tables();
		return $exp[ $log[ $a ] + $log[ $b ] ];
	}

	protected function rs_generator_poly( $degree ) {
		list( $exp, $log ) = $this->gf_tables();
		$poly = array( 1 );
		for ( $i = 0; $i < $degree; $i++ ) {
			$poly[] = 0;
			for ( $j = count( $poly ) - 1; $j > 0; $j-- ) {
				$poly[ $j ] = $poly[ $j ] ^ $this->gf_mul( $poly[ $j - 1 ], $exp[ $i ] );
			}
		}
		return $poly;
	}

	protected function rs_encode( $data, $ec_len ) {
		$generator = $this->rs_generator_poly( $ec_len );
		$result    = array_merge( $data, array_fill( 0, $ec_len, 0 ) );

		for ( $i = 0; $i < count( $data ); $i++ ) {
			$coef = $result[ $i ];
			if ( 0 === $coef ) {
				continue;
			}
			for ( $j = 0; $j < count( $generator ); $j++ ) {
				$result[ $i + $j ] ^= $this->gf_mul( $generator[ $j ], $coef );
			}
		}

		return array_slice( $result, count( $data ), $ec_len );
	}

	/* -------------------- Mátrix felépítés -------------------- */

	protected function init_matrix() {
		$this->modules  = array_fill( 0, $this->size, array_fill( 0, $this->size, false ) );
		$this->reserved = array_fill( 0, $this->size, array_fill( 0, $this->size, false ) );
	}

	protected function set_module( $row, $col, $dark, $reserve = true ) {
		if ( $row < 0 || $row >= $this->size || $col < 0 || $col >= $this->size ) {
			return;
		}
		$this->modules[ $row ][ $col ]  = $dark;
		if ( $reserve ) {
			$this->reserved[ $row ][ $col ] = true;
		}
	}

	protected function place_finder_pattern( $row, $col ) {
		for ( $r = -1; $r <= 7; $r++ ) {
			for ( $c = -1; $c <= 7; $c++ ) {
				$rr = $row + $r;
				$cc = $col + $c;
				if ( $rr < 0 || $rr >= $this->size || $cc < 0 || $cc >= $this->size ) {
					continue;
				}
				$is_border = ( 0 === $r || 6 === $r || 0 === $c || 6 === $c ) && $r >= 0 && $r <= 6 && $c >= 0 && $c <= 6;
				$is_core   = $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4;
				$dark = $is_border || $is_core;
				$this->set_module( $rr, $cc, $dark, true );
			}
		}
	}

	protected function place_function_patterns() {
		$this->place_finder_pattern( 0, 0 );
		$this->place_finder_pattern( 0, $this->size - 7 );
		$this->place_finder_pattern( $this->size - 7, 0 );

		// Timing patterns
		for ( $i = 8; $i < $this->size - 8; $i++ ) {
			$dark = ( 0 === $i % 2 );
			$this->set_module( 6, $i, $dark, true );
			$this->set_module( $i, 6, $dark, true );
		}

		// Alignment patterns
		$positions = self::$alignment_positions[ $this->version ];
		foreach ( $positions as $row ) {
			foreach ( $positions as $col ) {
				if ( ( 6 === $row && 6 === $col ) || ( 6 === $row && $col > $this->size - 10 ) || ( 6 === $col && $row > $this->size - 10 ) ) {
					// Kihagyjuk a finder mintákkal átfedő pozíciókat.
				}
				if ( $this->overlaps_finder( $row, $col ) ) {
					continue;
				}
				$this->place_alignment_pattern( $row, $col );
			}
		}

		// Dark module
		$this->set_module( $this->size - 8, 8, true, true );

		// Formátum infó helyfoglalás (értékeket később töltjük ki)
		for ( $i = 0; $i <= 8; $i++ ) {
			if ( 6 !== $i ) {
				$this->set_module( 8, $i, false, true );
				$this->set_module( $i, 8, false, true );
			}
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$this->set_module( 8, $this->size - 1 - $i, false, true );
		}
		for ( $i = 0; $i < 7; $i++ ) {
			$this->set_module( $this->size - 1 - $i, 8, false, true );
		}
		$this->set_module( 8, 8, false, true );

		// Verzió infó helyfoglalás (v7+)
		if ( $this->version >= 7 ) {
			for ( $r = 0; $r < 6; $r++ ) {
				for ( $c = 0; $c < 3; $c++ ) {
					$this->set_module( $r, $this->size - 11 + $c, false, true );
					$this->set_module( $this->size - 11 + $c, $r, false, true );
				}
			}
		}
	}

	protected function overlaps_finder( $row, $col ) {
		$corners = array( array( 0, 0 ), array( 0, $this->size - 7 ), array( $this->size - 7, 0 ) );
		foreach ( $corners as $corner ) {
			if ( abs( $row - ( $corner[0] + 3 ) ) <= 4 && abs( $col - ( $corner[1] + 3 ) ) <= 4 ) {
				return true;
			}
		}
		return false;
	}

	protected function place_alignment_pattern( $center_row, $center_col ) {
		for ( $r = -2; $r <= 2; $r++ ) {
			for ( $c = -2; $c <= 2; $c++ ) {
				$is_border = ( -2 === $r || 2 === $r || -2 === $c || 2 === $c );
				$is_core   = ( 0 === $r && 0 === $c );
				$dark = $is_border || $is_core;
				$this->set_module( $center_row + $r, $center_col + $c, $dark, true );
			}
		}
	}

	protected function place_data_bits( $bits ) {
		$bit_index = 0;
		$bit_len   = strlen( $bits );
		$dir_up    = true;

		$col = $this->size - 1;
		while ( $col > 0 ) {
			if ( 6 === $col ) {
				$col--; // időzítő oszlop kihagyása
			}

			for ( $count = 0; $count < $this->size; $count++ ) {
				$row = $dir_up ? ( $this->size - 1 - $count ) : $count;

				foreach ( array( $col, $col - 1 ) as $cur_col ) {
					if ( $this->reserved[ $row ][ $cur_col ] ) {
						continue;
					}
					$bit = ( $bit_index < $bit_len ) ? ( '1' === $bits[ $bit_index ] ) : false;
					$this->modules[ $row ][ $cur_col ] = $bit;
					$bit_index++;
				}
			}

			$dir_up = ! $dir_up;
			$col -= 2;
		}
	}

	protected function mask_condition( $mask_id, $row, $col ) {
		switch ( $mask_id ) {
			case 0: return ( $row + $col ) % 2 === 0;
			case 1: return $row % 2 === 0;
			case 2: return $col % 3 === 0;
			case 3: return ( $row + $col ) % 3 === 0;
			case 4: return ( floor( $row / 2 ) + floor( $col / 3 ) ) % 2 === 0;
			case 5: return ( ( $row * $col ) % 2 ) + ( ( $row * $col ) % 3 ) === 0;
			case 6: return ( ( ( $row * $col ) % 2 ) + ( ( $row * $col ) % 3 ) ) % 2 === 0;
			case 7: return ( ( ( $row + $col ) % 2 ) + ( ( $row * $col ) % 3 ) ) % 2 === 0;
		}
		return false;
	}

	protected function apply_mask( $mask_id ) {
		for ( $row = 0; $row < $this->size; $row++ ) {
			for ( $col = 0; $col < $this->size; $col++ ) {
				if ( $this->reserved[ $row ][ $col ] ) {
					continue;
				}
				if ( $this->mask_condition( $mask_id, $row, $col ) ) {
					$this->modules[ $row ][ $col ] = ! $this->modules[ $row ][ $col ];
				}
			}
		}
	}

	protected function choose_best_mask() {
		$best_id    = 0;
		$best_score = null;
		$backup     = $this->modules;

		for ( $mask_id = 0; $mask_id < 8; $mask_id++ ) {
			$this->modules = $backup;
			$this->apply_mask( $mask_id );
			$score = $this->penalty_score();
			$this->modules = $backup; // vissza, hogy a következő maszkolás tiszta lapról induljon

			if ( null === $best_score || $score < $best_score ) {
				$best_score = $score;
				$best_id    = $mask_id;
			}
		}

		return $best_id;
	}

	protected function penalty_score() {
		$size    = $this->size;
		$penalty = 0;

		// Szabály 1: 5+ egymást követő azonos színű modul soronként/oszloponként
		for ( $row = 0; $row < $size; $row++ ) {
			$run = 1;
			for ( $col = 1; $col < $size; $col++ ) {
				if ( $this->modules[ $row ][ $col ] === $this->modules[ $row ][ $col - 1 ] ) {
					$run++;
				} else {
					if ( $run >= 5 ) {
						$penalty += 3 + ( $run - 5 );
					}
					$run = 1;
				}
			}
			if ( $run >= 5 ) {
				$penalty += 3 + ( $run - 5 );
			}
		}
		for ( $col = 0; $col < $size; $col++ ) {
			$run = 1;
			for ( $row = 1; $row < $size; $row++ ) {
				if ( $this->modules[ $row ][ $col ] === $this->modules[ $row - 1 ][ $col ] ) {
					$run++;
				} else {
					if ( $run >= 5 ) {
						$penalty += 3 + ( $run - 5 );
					}
					$run = 1;
				}
			}
			if ( $run >= 5 ) {
				$penalty += 3 + ( $run - 5 );
			}
		}

		// Szabály 2: 2x2 azonos színű blokkok
		for ( $row = 0; $row < $size - 1; $row++ ) {
			for ( $col = 0; $col < $size - 1; $col++ ) {
				$v = $this->modules[ $row ][ $col ];
				if ( $v === $this->modules[ $row ][ $col + 1 ] && $v === $this->modules[ $row + 1 ][ $col ] && $v === $this->modules[ $row + 1 ][ $col + 1 ] ) {
					$penalty += 3;
				}
			}
		}

		// Szabály 3: finder-szerű minta (1:1:3:1:1 arány sötét-világos)
		$pattern_dark  = array( true, false, true, true, true, false, true );
		$pattern_light = array( false, false, false, false, true, false, true, true, true, false, true );
		for ( $row = 0; $row < $size; $row++ ) {
			for ( $col = 0; $col <= $size - 7; $col++ ) {
				$slice = array();
				for ( $k = 0; $k < 7; $k++ ) {
					$slice[] = $this->modules[ $row ][ $col + $k ];
				}
				if ( $slice === $pattern_dark ) {
					$penalty += 40;
				}
			}
		}

		// Szabály 4: sötét/világos arány eltérése az 50%-tól
		$dark_count = 0;
		foreach ( $this->modules as $row_data ) {
			foreach ( $row_data as $v ) {
				if ( $v ) {
					$dark_count++;
				}
			}
		}
		$percent = ( $dark_count / ( $size * $size ) ) * 100;
		$penalty += (int) ( abs( $percent - 50 ) / 5 ) * 10;

		return $penalty;
	}

	/* -------------------- Formátum / verzió infó -------------------- */

	protected function bch_encode( $data, $data_bits, $generator, $ec_bits ) {
		$value = $data << $ec_bits;
		$gen_len = $ec_bits + 1;

		for ( $shift = $data_bits - 1; $shift >= 0; $shift-- ) {
			if ( $value & ( 1 << ( $shift + $ec_bits ) ) ) {
				$value ^= ( $generator << $shift );
			}
		}

		return ( $data << $ec_bits ) | $value;
	}

	protected function place_format_info( $mask_id ) {
		// EC szint "L" jelölése a spec szerint: 01
		$ec_level_bits = 0b01;
		$data = ( $ec_level_bits << 3 ) | $mask_id;

		$bch = $this->bch_encode( $data, 5, 0x537, 10 );
		$format_bits = $bch ^ 0x5412;

		$bits = str_pad( decbin( $format_bits ), 15, '0', STR_PAD_LEFT );

		// Első másolat (a bal felső finder körül)
		$positions_a = array();
		for ( $i = 0; $i <= 5; $i++ ) {
			$positions_a[] = array( 8, $i );
		}
		$positions_a[] = array( 8, 7 );
		$positions_a[] = array( 8, 8 );
		$positions_a[] = array( 7, 8 );
		for ( $i = 5; $i >= 0; $i-- ) {
			$positions_a[] = array( $i, 8 );
		}

		for ( $i = 0; $i < 15; $i++ ) {
			$this->modules[ $positions_a[ $i ][0] ][ $positions_a[ $i ][1] ] = ( '1' === $bits[ $i ] );
		}

		// Második másolat (jobb felső + bal alsó) - fordított (LSB-first) sorrendben
		for ( $i = 0; $i < 8; $i++ ) {
			$this->modules[ 8 ][ $this->size - 1 - $i ] = ( '1' === $bits[ 14 - $i ] );
		}
		for ( $i = 0; $i < 7; $i++ ) {
			$this->modules[ $this->size - 7 + $i ][ 8 ] = ( '1' === $bits[ 6 - $i ] );
		}
	}

	protected function place_version_info() {
		$bch = $this->bch_encode( $this->version, 6, 0x1F25, 12 );
		$bits = str_pad( decbin( $bch ), 18, '0', STR_PAD_LEFT );

		for ( $i = 0; $i < 18; $i++ ) {
			$row = intdiv( $i, 3 );
			$col = $i % 3;
			$bit = ( '1' === $bits[17 - $i] );
			$this->modules[ $row ][ $this->size - 11 + $col ] = $bit;
			$this->modules[ $this->size - 11 + $col ][ $row ] = $bit;
		}
	}

	/* -------------------- SVG kimenet -------------------- */

	public function to_svg( $module_px = 8, $margin_modules = 4 ) {
		$total_modules = $this->size + ( $margin_modules * 2 );
		$px = $total_modules * $module_px;

		$svg  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $px . ' ' . $px . '" width="' . $px . '" height="' . $px . '" shape-rendering="crispEdges">';
		$svg .= '<rect width="' . $px . '" height="' . $px . '" fill="#ffffff"/>';

		$path = '';
		for ( $row = 0; $row < $this->size; $row++ ) {
			for ( $col = 0; $col < $this->size; $col++ ) {
				if ( $this->modules[ $row ][ $col ] ) {
					$x = ( $col + $margin_modules ) * $module_px;
					$y = ( $row + $margin_modules ) * $module_px;
					$path .= 'M' . $x . ',' . $y . 'h' . $module_px . 'v' . $module_px . 'h-' . $module_px . 'z';
				}
			}
		}
		$svg .= '<path d="' . $path . '" fill="#000000"/>';
		$svg .= '</svg>';

		return $svg;
	}
}
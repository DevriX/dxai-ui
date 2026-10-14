<?php
/**
 * A font's line metrics and average width, read from its TrueType/OpenType file, and the fallback face that matches them.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Media;

/**
 * Text is drawn in a fallback font until a web font arrives (`font-display: swap`), and then drawn again. When the two fonts have other
 * line heights and widths, everything below moves: on H2O Away's Home the phone header was 69 px tall in the fallback and 74 px in Hind,
 * and the page moved down 5 px as the fonts came — all of its layout shift (CLS 0.077; docs/PLAN-PERFECT-PAGES.md, T5.2). The fix the
 * browsers made for it: a fallback face of a local font (Arial, or Times New Roman for a serif) with the web font's ascent, descent and
 * line gap (`ascent-override`, `descent-override`, `line-gap-override`) and its average width (`size-adjust`), named after the web font
 * and placed right after it in the stack. Once the web font is there nothing of it shows; before, the text already takes the web font's
 * room.
 *
 * The numbers are read from the font file: `head` (units per em), `hhea` (ascender, descender, line gap — or `OS/2`'s typographic ones
 * when the font asks for them, USE_TYPO_METRICS), `cmap` and `hmtx` (the advance of each letter, averaged by how often it occurs in
 * English text). WOFF2 is compressed with Brotli, which PHP does not read, so the file is the TrueType one Google Fonts serves a plain
 * client. The local fonts' numbers are read the same way from their files (Arial, Arial Bold, Times New Roman, Times New Roman Bold).
 */
final class Font_Metrics {

	/** How often each letter occurs in English text (Lewand), whatever its case. */
	private const LETTERS = array(
		'a' => 0.0668,
		'b' => 0.0122,
		'c' => 0.0228,
		'd' => 0.0348,
		'e' => 0.1039,
		'f' => 0.0182,
		'g' => 0.0165,
		'h' => 0.0499,
		'i' => 0.057,
		'j' => 0.0013,
		'k' => 0.0063,
		'l' => 0.0329,
		'm' => 0.0197,
		'n' => 0.0552,
		'o' => 0.0614,
		'p' => 0.0158,
		'q' => 0.0008,
		'r' => 0.049,
		's' => 0.0518,
		't' => 0.0741,
		'u' => 0.0226,
		'v' => 0.008,
		'w' => 0.0193,
		'x' => 0.0012,
		'y' => 0.0161,
		'z' => 0.0006,
	);

	/**
	 * The share of each character in running English text, as a heading or a paragraph has it: letters (one in thirteen a capital: names,
	 * the start of sentences, Title Case headings), the space between words, commas and full stops.
	 *
	 * @return array<string, float>
	 */
	private static function weights(): array {
		$sum = array_sum( self::LETTERS );
		$out = array(
			' ' => 0.17,
			',' => 0.01,
			'.' => 0.01,
		);
		foreach ( self::LETTERS as $letter => $share ) {
			$out[ $letter ]               = 0.75 * $share / $sum;
			$out[ strtoupper( $letter ) ] = 0.06 * $share / $sum;
		}

		return $out;
	}

	/**
	 * The local fonts a fallback face is made of: the names a browser finds them by (Liberation and the Croscore fonts have Arial's and
	 * Times New Roman's widths, for systems without the Microsoft fonts), and their numbers, read by self::read() from Windows' files
	 * (arial.ttf, arialbd.ttf, times.ttf, timesbd.ttf; by letters alone Arial's average is 903.37, capsize's 904).
	 */
	public const LOCAL = array(
		'sans'  => array(
			'normal' => array(
				'local'   => array( 'Arial', 'ArialMT', 'Liberation Sans', 'Arimo' ),
				'metrics' => array( 'upm' => 2048, 'ascent' => 1854, 'descent' => -434, 'gap' => 67, 'avg' => 923.23 ),
			),
			'bold'   => array(
				'local'   => array( 'Arial Bold', 'Arial-BoldMT', 'Liberation Sans Bold', 'Arimo Bold' ),
				'metrics' => array( 'upm' => 2048, 'ascent' => 1854, 'descent' => -434, 'gap' => 67, 'avg' => 991.4 ),
			),
		),
		'serif' => array(
			'normal' => array(
				'local'   => array( 'Times New Roman', 'TimesNewRomanPSMT', 'Liberation Serif', 'Tinos' ),
				'metrics' => array( 'upm' => 2048, 'ascent' => 1825, 'descent' => -443, 'gap' => 87, 'avg' => 842.46 ),
			),
			'bold'   => array(
				'local'   => array( 'Times New Roman Bold', 'TimesNewRomanPS-BoldMT', 'Liberation Serif Bold', 'Tinos Bold' ),
				'metrics' => array( 'upm' => 2048, 'ascent' => 1825, 'descent' => -443, 'gap' => 87, 'avg' => 895.0 ),
			),
		),
	);

	/**
	 * A font file's numbers, or null when it is not one this can read (TrueType or OpenType with a Unicode cmap of format 4 or 12).
	 *
	 * @return array{upm:int, ascent:int, descent:int, gap:int, avg:float}|null
	 */
	public static function read( string $bytes ): ?array {
		if ( strlen( $bytes ) < 12 || ! in_array( substr( $bytes, 0, 4 ), array( "\x00\x01\x00\x00", 'OTTO', 'true' ), true ) ) {
			return null;
		}
		$tables = array();
		$count  = self::u16( $bytes, 4 );
		for ( $i = 0; $i < $count; $i++ ) {
			$at = 12 + 16 * $i;
			if ( $at + 16 > strlen( $bytes ) ) {
				return null;
			}
			$tables[ substr( $bytes, $at, 4 ) ] = array( self::u32( $bytes, $at + 8 ), self::u32( $bytes, $at + 12 ) );
		}
		foreach ( array( 'head', 'hhea', 'hmtx', 'cmap' ) as $need ) {
			if ( ! isset( $tables[ $need ] ) || $tables[ $need ][0] + $tables[ $need ][1] > strlen( $bytes ) ) {
				return null;
			}
		}
		$upm = self::u16( $bytes, $tables['head'][0] + 18 );
		$hh  = $tables['hhea'][0];
		$asc = self::s16( $bytes, $hh + 4 );
		$dsc = self::s16( $bytes, $hh + 6 );
		$gap = self::s16( $bytes, $hh + 8 );
		$num = self::u16( $bytes, $hh + 34 );
		// A font that says to use its typographic metrics (OS/2 fsSelection bit 7) is drawn with them.
		if ( isset( $tables['OS/2'] ) && $tables['OS/2'][1] >= 74 && ( self::u16( $bytes, $tables['OS/2'][0] + 62 ) & 0x80 ) ) {
			$os  = $tables['OS/2'][0];
			$asc = self::s16( $bytes, $os + 68 );
			$dsc = self::s16( $bytes, $os + 70 );
			$gap = self::s16( $bytes, $os + 72 );
		}
		if ( $upm < 16 || $num < 1 ) {
			return null;
		}
		$glyphs = self::glyphs( $bytes, $tables['cmap'][0], $tables['cmap'][1], array_map( 'ord', array_map( 'strval', array_keys( self::weights() ) ) ) );
		if ( $glyphs === null ) {
			return null;
		}
		$total = 0.0;
		$sum   = 0.0;
		foreach ( self::weights() as $char => $weight ) {
			$glyph = $glyphs[ ord( (string) $char ) ] ?? 0;
			if ( $glyph < 1 ) {
				continue;
			}
			$advance = self::u16( $bytes, $tables['hmtx'][0] + 4 * min( $glyph, $num - 1 ) );
			$sum    += $advance * $weight;
			$total  += $weight;
		}
		if ( $total < 0.5 ) {
			return null;
		}

		return array(
			'upm'     => $upm,
			'ascent'  => $asc,
			'descent' => $dsc,
			'gap'     => $gap,
			'avg'     => round( $sum / $total, 2 ),
		);
	}

	/**
	 * The fallback faces for a web font: a local font with the web font's room, under "<family> Fallback". Two faces: the normal weights
	 * from the local regular, the bold ones from its bold (a bold the browser made up of the regular would be narrower), each scaled to
	 * the web font's face of that kind when it is known ($bold), else to its regular.
	 *
	 * @param array{upm:int, ascent:int, descent:int, gap:int, avg:float}      $web  The web font's regular face.
	 * @param array{upm:int, ascent:int, descent:int, gap:int, avg:float}|null $bold Its bold face (600–700), when it has one.
	 */
	public static function fallback_css( string $family, array $web, string $kind = 'sans', ?array $bold = null ): string {
		$local = self::LOCAL[ $kind ] ?? self::LOCAL['sans'];
		$out   = '';
		$faces = array(
			'normal' => $web,
			'bold'   => $bold ?? $web,
		);
		foreach ( array( 'normal' => '100 500', 'bold' => '600 900' ) as $weight => $range ) {
			$base = self::local_metrics( $kind, $weight );
			$web  = $faces[ $weight ];
			if ( $base === null || $web['avg'] <= 0 || $web['upm'] < 1 || $base['avg'] <= 0 ) {
				continue;
			}
			// How much the local font is scaled to take the web font's width per em; the line metrics are then given in the scaled em.
			$adjust = ( $web['avg'] / $web['upm'] ) / ( $base['avg'] / $base['upm'] );
			$pct    = static fn( float $v ): string => rtrim( rtrim( number_format( $v * 100, 2, '.', '' ), '0' ), '.' ) . '%';
			$names  = implode( ',', array_map( static fn( string $n ): string => 'local("' . $n . '")', $local[ $weight ]['local'] ) );
			$out   .= '@font-face{font-family:"' . self::fallback_name( $family ) . '";font-style:normal;font-weight:' . $range . ';src:' . $names
				. ';size-adjust:' . $pct( $adjust )
				. ';ascent-override:' . $pct( $web['ascent'] / $web['upm'] / $adjust )
				. ';descent-override:' . $pct( abs( $web['descent'] ) / $web['upm'] / $adjust )
				. ';line-gap-override:' . $pct( max( 0, $web['gap'] ) / $web['upm'] / $adjust ) . '}';
		}

		return $out;
	}

	/**
	 * A Google font's numbers, regular and bold, from the TrueType files Google serves a client that is not a browser it knows (the
	 * WOFF2 a browser gets is Brotli, which PHP does not read). The regular is weight 400 when the design loads it, else its lightest;
	 * the bold is 700, 600 or 800, the first the design loads. A face that cannot be fetched or read is null.
	 *
	 * @param array<int, int> $weights The weights the design loads.
	 * @return array{normal: array{upm:int, ascent:int, descent:int, gap:int, avg:float}|null, bold: array{upm:int, ascent:int, descent:int, gap:int, avg:float}|null}
	 */
	public static function of_google( string $family, array $weights ): array {
		$out = array(
			'normal' => null,
			'bold'   => null,
		);
		if ( preg_match( '/^[A-Za-z0-9 ._-]{1,80}$/', $family ) !== 1 ) {
			return $out;
		}
		$weights       = array_values( array_unique( array_map( 'intval', $weights ) ) );
		$normal        = in_array( 400, $weights, true ) || $weights === array() ? 400 : min( $weights );
		$out['normal'] = self::fetch( $family, $normal );
		foreach ( array( 700, 600, 800 ) as $bold ) {
			if ( in_array( $bold, $weights, true ) ) {
				$out['bold'] = self::fetch( $family, $bold );
				break;
			}
		}

		return $out;
	}

	/**
	 * One face of a Google font, read.
	 *
	 * @return array{upm:int, ascent:int, descent:int, gap:int, avg:float}|null
	 */
	private static function fetch( string $family, int $weight ): ?array {
		$css = wp_safe_remote_get( 'https://fonts.googleapis.com/css2?family=' . str_replace( ' ', '+', $family ) . ':wght@' . $weight, array( 'timeout' => 10 ) );
		$css = is_wp_error( $css ) || (int) wp_remote_retrieve_response_code( $css ) !== 200 ? '' : (string) wp_remote_retrieve_body( $css );
		if ( preg_match( '#url\(\s*[\'"]?(https://fonts\.gstatic\.com/[A-Za-z0-9/._-]+\.(?:ttf|otf))[\'"]?\s*\)#i', $css, $m ) !== 1 ) {
			return null;
		}
		$file = wp_safe_remote_get(
			$m[1],
			array(
				'timeout'             => 15,
				'limit_response_size' => 5 * MB_IN_BYTES,
			)
		);
		if ( is_wp_error( $file ) || (int) wp_remote_retrieve_response_code( $file ) !== 200 ) {
			return null;
		}

		return self::read( (string) wp_remote_retrieve_body( $file ) );
	}

	/** The name of a web font's fallback face. */
	public static function fallback_name( string $family ): string {
		return $family . ' Fallback';
	}

	/**
	 * The local font's numbers.
	 *
	 * @return array{upm:int, ascent:int, descent:int, gap:int, avg:float}|null
	 */
	public static function local_metrics( string $kind, string $weight ): ?array {
		$m = self::LOCAL[ $kind ][ $weight ]['metrics'] ?? null;

		return is_array( $m ) ? $m : null;
	}

	/**
	 * The glyph of each code point asked for, from the font's Unicode cmap (format 4, or 12), or null when it has neither.
	 *
	 * @param array<int, int> $codes
	 * @return array<int, int>|null
	 */
	private static function glyphs( string $b, int $cmap, int $length, array $codes ): ?array {
		$count = self::u16( $b, $cmap + 2 );
		$best  = null;
		for ( $i = 0; $i < $count; $i++ ) {
			$rec      = $cmap + 4 + 8 * $i;
			$platform = self::u16( $b, $rec );
			$encoding = self::u16( $b, $rec + 2 );
			$offset   = $cmap + self::u32( $b, $rec + 4 );
			if ( $offset + 4 > $cmap + $length ) {
				continue;
			}
			$format = self::u16( $b, $offset );
			if ( ( $platform === 3 && in_array( $encoding, array( 1, 10 ), true ) ) || $platform === 0 ) {
				if ( $format === 12 || ( $format === 4 && $best === null ) ) {
					$best = array( $format, $offset );
				}
			}
		}
		if ( $best === null ) {
			return null;
		}
		list( $format, $at ) = $best;
		$out                 = array();
		if ( $format === 4 ) {
			$segs  = intdiv( self::u16( $b, $at + 6 ), 2 );
			$ends  = $at + 14;
			$start = $ends + 2 * $segs + 2;
			$delta = $start + 2 * $segs;
			$range = $delta + 2 * $segs;
			foreach ( $codes as $code ) {
				for ( $s = 0; $s < $segs; $s++ ) {
					if ( $code > self::u16( $b, $ends + 2 * $s ) ) {
						continue;
					}
					$first = self::u16( $b, $start + 2 * $s );
					if ( $code < $first ) {
						break;
					}
					$ro = self::u16( $b, $range + 2 * $s );
					if ( $ro === 0 ) {
						$glyph = ( $code + self::s16( $b, $delta + 2 * $s ) ) & 0xFFFF;
					} else {
						$glyph = self::u16( $b, $range + 2 * $s + $ro + 2 * ( $code - $first ) );
						$glyph = $glyph === 0 ? 0 : ( $glyph + self::s16( $b, $delta + 2 * $s ) ) & 0xFFFF;
					}
					$out[ $code ] = $glyph;
					break;
				}
			}
		} else {
			$groups = self::u32( $b, $at + 12 );
			foreach ( $codes as $code ) {
				for ( $g = 0; $g < $groups; $g++ ) {
					$rec = $at + 16 + 12 * $g;
					$lo  = self::u32( $b, $rec );
					$hi  = self::u32( $b, $rec + 4 );
					if ( $code >= $lo && $code <= $hi ) {
						$out[ $code ] = self::u32( $b, $rec + 8 ) + ( $code - $lo );
						break;
					}
				}
			}
		}

		return $out;
	}

	private static function u16( string $b, int $at ): int {
		return $at + 2 <= strlen( $b ) ? ( ord( $b[ $at ] ) << 8 ) | ord( $b[ $at + 1 ] ) : 0;
	}

	private static function s16( string $b, int $at ): int {
		$v = self::u16( $b, $at );

		return $v >= 0x8000 ? $v - 0x10000 : $v;
	}

	private static function u32( string $b, int $at ): int {
		return $at + 4 <= strlen( $b ) ? ( self::u16( $b, $at ) << 16 ) | self::u16( $b, $at + 2 ) : 0;
	}
}

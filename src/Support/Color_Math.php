<?php
/**
 * Colour parsing, perceptual distance and contrast.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Support;

/**
 * The arithmetic the theme-palette mapping needs, in one place and without
 * WordPress: a CSS colour read into sRGB, its OKLab/OKLCH coordinates, the
 * distance between two colours as a person sees it, and the WCAG contrast
 * ratio of a text/background pair.
 *
 * OKLab rather than CIELAB: it is what CSS itself mixes in (`color-mix(in
 * oklab …)`, `oklch(from …)`), so a tint expressed relative to a theme colour
 * lands where the distance measured it. Distance is Euclidean OKLab × 100 —
 * about 2 is where two swatches stop looking alike side by side.
 */
final class Color_Math {

	/**
	 * A CSS colour as [r, g, b, a] (0-255, alpha 0-1), or null when it is not one this reads: hex (3/4/6/8),
	 * rgb()/rgba() (commas or spaces, % or numbers), hsl()/hsla(), `transparent`, and the few keywords a design
	 * or a theme palette uses. Anything with var() or a relative colour is not a colour yet.
	 *
	 * @return array{0:float,1:float,2:float,3:float}|null
	 */
	public static function parse( string $css ): ?array {
		$c = strtolower( trim( $css ) );
		$named = array(
			'white'       => '#ffffff',
			'black'       => '#000000',
			'transparent' => '#00000000',
			'red'         => '#ff0000',
			'currentcolor' => '',
		);
		if ( isset( $named[ $c ] ) ) {
			$c = $named[ $c ];
			if ( $c === '' ) {
				return null;
			}
		}
		if ( preg_match( '/^#([0-9a-f]{3,8})$/', $c, $m ) ) {
			$h = $m[1];
			if ( strlen( $h ) === 3 || strlen( $h ) === 4 ) {
				$h = implode( '', array_map( static fn( $ch ) => $ch . $ch, str_split( $h ) ) );
			}
			if ( strlen( $h ) !== 6 && strlen( $h ) !== 8 ) {
				return null;
			}
			return array(
				(float) hexdec( substr( $h, 0, 2 ) ),
				(float) hexdec( substr( $h, 2, 2 ) ),
				(float) hexdec( substr( $h, 4, 2 ) ),
				strlen( $h ) === 8 ? hexdec( substr( $h, 6, 2 ) ) / 255 : 1.0,
			);
		}
		if ( preg_match( '/^(rgba?|hsla?)\(\s*([^)]*)\)$/', $c, $m ) ) {
			$parts = preg_split( '/[\s,\/]+/', trim( $m[2] ) ) ?: array();
			if ( count( $parts ) < 3 ) {
				return null;
			}
			$alpha = isset( $parts[3] ) ? self::unit( $parts[3], 1.0 ) : 1.0;
			if ( $m[1][0] === 'r' ) {
				return array( self::unit( $parts[0], 255.0 ), self::unit( $parts[1], 255.0 ), self::unit( $parts[2], 255.0 ), $alpha );
			}
			$h = fmod( (float) $parts[0], 360.0 );
			$s = self::unit( $parts[1], 1.0 );
			$l = self::unit( $parts[2], 1.0 );
			return array_merge( self::hsl_rgb( $h < 0 ? $h + 360 : $h, $s, $l ), array( $alpha ) );
		}

		return null;
	}

	/** `#rrggbb` of a colour (alpha dropped), or '' when it cannot be read. */
	public static function hex( string $css ): string {
		$rgb = self::parse( $css );
		if ( $rgb === null ) {
			return '';
		}

		return sprintf( '#%02x%02x%02x', (int) round( $rgb[0] ), (int) round( $rgb[1] ), (int) round( $rgb[2] ) );
	}

	/**
	 * OKLab of an sRGB colour (0-255 channels).
	 *
	 * @param array{0:float,1:float,2:float} $rgb
	 * @return array{0:float,1:float,2:float} L (0-1), a, b
	 */
	public static function oklab( array $rgb ): array {
		$lin = array_map(
			static function ( $v ) {
				$v = $v / 255;
				return $v <= 0.04045 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4;
			},
			array( $rgb[0], $rgb[1], $rgb[2] )
		);
		$l = 0.4122214708 * $lin[0] + 0.5363325363 * $lin[1] + 0.0514459929 * $lin[2];
		$m = 0.2119034982 * $lin[0] + 0.6806995451 * $lin[1] + 0.1073969566 * $lin[2];
		$s = 0.0883024619 * $lin[0] + 0.2817188376 * $lin[1] + 0.6299787005 * $lin[2];
		$l = self::cbrt( $l );
		$m = self::cbrt( $m );
		$s = self::cbrt( $s );

		return array(
			0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
			1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
			0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
		);
	}

	/**
	 * OKLCH of an sRGB colour: lightness 0-1, chroma, hue in degrees.
	 *
	 * @param array{0:float,1:float,2:float} $rgb
	 * @return array{0:float,1:float,2:float}
	 */
	public static function oklch( array $rgb ): array {
		list( $l, $a, $b ) = self::oklab( $rgb );
		$h                 = rad2deg( atan2( $b, $a ) );

		return array( $l, sqrt( $a * $a + $b * $b ), $h < 0 ? $h + 360 : $h );
	}

	/** Perceptual distance of two CSS colours (OKLab × 100); INF when either cannot be read. */
	public static function distance( string $a, string $b ): float {
		$x = self::parse( $a );
		$y = self::parse( $b );
		if ( $x === null || $y === null ) {
			return INF;
		}
		$p = self::oklab( $x );
		$q = self::oklab( $y );

		return 100 * sqrt( ( $p[0] - $q[0] ) ** 2 + ( $p[1] - $q[1] ) ** 2 + ( $p[2] - $q[2] ) ** 2 );
	}

	/** WCAG 2 relative luminance of an sRGB colour. */
	public static function luminance( array $rgb ): float {
		$c = array_map(
			static function ( $v ) {
				$v = $v / 255;
				return $v <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4;
			},
			array( $rgb[0], $rgb[1], $rgb[2] )
		);

		return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
	}

	/**
	 * WCAG contrast ratio of text on a background (1-21). A translucent text colour is composited on the background
	 * first; a translucent background on white. 0 when either cannot be read.
	 */
	public static function contrast( string $fg, string $bg ): float {
		$b = self::parse( $bg );
		$f = self::parse( $fg );
		if ( $b === null || $f === null ) {
			return 0.0;
		}
		$b = self::over( $b, array( 255.0, 255.0, 255.0, 1.0 ) );
		$f = self::over( $f, $b );
		$l1 = self::luminance( $f );
		$l2 = self::luminance( $b );

		return ( max( $l1, $l2 ) + 0.05 ) / ( min( $l1, $l2 ) + 0.05 );
	}

	/**
	 * A colour composited over an opaque one.
	 *
	 * @param array{0:float,1:float,2:float,3:float} $top
	 * @param array{0:float,1:float,2:float,3?:float} $under
	 * @return array{0:float,1:float,2:float,3:float}
	 */
	public static function over( array $top, array $under ): array {
		$a = $top[3] ?? 1.0;

		return array(
			$top[0] * $a + $under[0] * ( 1 - $a ),
			$top[1] * $a + $under[1] * ( 1 - $a ),
			$top[2] * $a + $under[2] * ( 1 - $a ),
			1.0,
		);
	}

	/** A number or percentage as a fraction of $scale (`50%` of 255 → 127.5; `0.5` alpha → 0.5). */
	private static function unit( string $v, float $scale ): float {
		$v = trim( $v );
		if ( str_ends_with( $v, '%' ) ) {
			return max( 0.0, min( 100.0, (float) $v ) ) / 100 * $scale;
		}
		// hsl() saturation/lightness written as plain numbers are percentages (the legacy syntax).
		$n = (float) $v;
		if ( $scale === 1.0 && $n > 1.0 ) {
			return min( 100.0, $n ) / 100;
		}

		return max( 0.0, min( $scale, $n ) );
	}

	/**
	 * @return array{0:float,1:float,2:float}
	 */
	private static function hsl_rgb( float $h, float $s, float $l ): array {
		$c = ( 1 - abs( 2 * $l - 1 ) ) * $s;
		$x = $c * ( 1 - abs( fmod( $h / 60, 2 ) - 1 ) );
		$m = $l - $c / 2;
		$seg = (int) floor( $h / 60 ) % 6;
		$rgb = array( array( $c, $x, 0 ), array( $x, $c, 0 ), array( 0, $c, $x ), array( 0, $x, $c ), array( $x, 0, $c ), array( $c, 0, $x ) )[ $seg ];

		return array( ( $rgb[0] + $m ) * 255, ( $rgb[1] + $m ) * 255, ( $rgb[2] + $m ) * 255 );
	}

	private static function cbrt( float $v ): float {
		return $v < 0 ? -( ( -$v ) ** ( 1 / 3 ) ) : $v ** ( 1 / 3 );
	}
}

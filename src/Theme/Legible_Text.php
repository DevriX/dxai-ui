<?php
/**
 * Text that nearly reads on its backgrounds, in the nearest colour that does.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Theme;

use DXAI_UI\Compiler\Color_Usage;
use DXAI_UI\Support\Color_Math;

/**
 * Lighthouse's accessibility audit asks normal text for 4.5:1 against its background (3:1 for large text). H2O Away's muted grey,
 * #6a737d, is 4.45:1 on its own card colour and 4.48:1 on the theme's #f5f7f9 the card follows: the Home scored 95 for it
 * (docs/PLAN-PERFECT-PAGES.md, T5.5). A text colour that nearly reads on every background it sits on (Color_Usage's pairs, at their
 * values on this request: Theme_Binding::values()) — within NEAR of what it needs everywhere — was meant to read, and is given the
 * nearest colour that does: the same hue and chroma, moved away from the background it reads worst on (darker on a light one,
 * lighter on a dark one) by the least step that reaches the ratio on all of them, at most MAX_STEP of OKLCH lightness. It goes on the token's text channel only (`--dxai-<token>--fg`, which
 * the design's text, fill and stroke read; Token_Styles::fg_channel()), so its backgrounds and borders keep the design's value. A colour
 * further off is the design's decision and is left as it is, and so is a token whose text the theme binding already chose
 * (Theme_Binding::text_values()). `dxai_ui_legible_text` turns it off.
 */
final class Legible_Text {

	/** What normal text needs. */
	public const TEXT_MIN = 4.5;

	/** What large text needs (24 px, or 18.66 px bold). */
	public const LARGE_MIN = 3.0;

	/** How far under what it needs a pair may be and still be brought to it. */
	public const NEAR = 0.5;

	/** The most a colour is moved, in OKLCH lightness (0-1). */
	public const MAX_STEP = 0.05;

	/** @var array<string, string> */
	private static array $memo = array();

	/** The rule with the text channels a post's design needs ('' when none). */
	public static function css( int $post_id ): string {
		$home = Theme_Binding::home_of( $post_id );
		if ( $home < 1 ) {
			return '';
		}
		/**
		 * Whether a design's text colours that nearly read on their backgrounds are given the nearest colour that does.
		 *
		 * @param bool $on   Default true.
		 * @param int  $home The design's Home.
		 */
		if ( ! (bool) apply_filters( 'dxai_ui_legible_text', true, $home ) ) {
			return '';
		}
		$usage = get_post_meta( $home, Color_Usage::META, true );
		$pairs = Color_Usage::pair_rows( is_array( $usage ) && is_array( $usage['pairs'] ?? null ) ? $usage['pairs'] : array() );
		if ( $pairs === array() ) {
			return '';
		}
		$values = Theme_Binding::values( $home );
		$skip   = array_keys( Theme_Binding::text_values( $home ) );
		$key    = md5( (string) wp_json_encode( array( $home, $values, $pairs, $skip ) ) );
		if ( ! isset( self::$memo[ $key ] ) ) {
			$decls = array();
			foreach ( self::adjust( $values, $pairs, $skip ) as $token => $hex ) {
				$decls[] = '--dxai-' . $token . '--fg:' . $hex;
			}
			self::$memo[ $key ] = $decls === array() ? '' : '.dxai-ui.dxai-ui--' . $home . '{' . implode( ';', $decls ) . '}';
		}

		return self::$memo[ $key ];
	}

	/**
	 * The text colours to set: token => colour.
	 *
	 * @param array<string, string>                                $values Token => colour now.
	 * @param array<int, array{0:string, 1:string, 2:int, 3:bool}> $pairs  Text token, background token, uses, large (Color_Usage::pair_rows()).
	 * @param array<int, string>                                   $skip   Tokens whose text channel is chosen elsewhere.
	 * @return array<string, string>
	 */
	public static function adjust( array $values, array $pairs, array $skip = array() ): array {
		$by_fg = array();
		foreach ( $pairs as $pair ) {
			$fg = (string) $pair[0];
			$bg = Color_Math::parse( (string) ( $values[ (string) $pair[1] ] ?? '' ) );
			// A translucent background is not what the text is read on (the colour under it is): left out.
			if ( ! isset( $values[ $fg ] ) || in_array( $fg, $skip, true ) || $bg === null || $bg[3] < 1 ) {
				continue;
			}
			$by_fg[ $fg ][] = array( (string) $values[ (string) $pair[1] ], ! empty( $pair[3] ) ? self::LARGE_MIN : self::TEXT_MIN );
		}
		$out = array();
		foreach ( $by_fg as $token => $grounds ) {
			$rgba = Color_Math::parse( $values[ $token ] );
			if ( $rgba === null || $rgba[3] < 1 ) {
				continue;
			}
			$short = self::shortfall( $values[ $token ], $grounds );
			if ( $short <= 0.0 || $short > self::NEAR ) {
				continue;
			}
			// Away from the background it reads worst on: darker on a lighter one, lighter on a darker one. Every other one must
			// still read after the step (the search below asks it of all of them).
			$text  = Color_Math::luminance( $rgba );
			$worst = $grounds[0];
			foreach ( $grounds as $g ) {
				if ( $g[1] - Color_Math::contrast( $values[ $token ], $g[0] ) > $worst[1] - Color_Math::contrast( $values[ $token ], $worst[0] ) ) {
					$worst = $g;
				}
			}
			$sign = Color_Math::luminance( (array) Color_Math::parse( $worst[0] ) ) > $text ? -1 : 1;
			$lch  = Color_Math::oklch( $rgba );
			for ( $step = 0.002; $step <= self::MAX_STEP + 1e-9; $step += 0.002 ) {
				$hex = Color_Math::from_oklch( max( 0.0, min( 1.0, $lch[0] + $sign * $step ) ), $lch[1], $lch[2] );
				if ( self::shortfall( $hex, $grounds, 0.01 ) <= 0.0 ) {
					$out[ $token ] = $hex;
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * How far a text colour is under what it needs on its worst background (0 when it reads everywhere).
	 *
	 * @param array<int, array{0:string, 1:float}> $grounds Background colour, ratio needed.
	 */
	private static function shortfall( string $fg, array $grounds, float $margin = 0.0 ): float {
		$worst = 0.0;
		foreach ( $grounds as $g ) {
			$worst = max( $worst, $g[1] + $margin - Color_Math::contrast( $fg, $g[0] ) );
		}

		return $worst;
	}
}

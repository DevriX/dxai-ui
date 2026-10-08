<?php
/**
 * Widths and paddings of a design's blocks within the site's design system.
 *
 * @package DXAI_UI\Blocks\Native
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks\Native;

use DXAI_UI\Compiler\Style_Hoister;
use DXAI_UI\Compiler\Utility_Classes;
use DXAI_UI\Theme\Capabilities;

/**
 * The plugin makes classes where the theme has none (`max-w-1280px` beside the theme's `max-w-1200px`), and a class of a width or a
 * padding that the site does not use anywhere else is a new value in its design system. New imports keep within it
 * (Utility_Classes::fit_system()); this brings the blocks of a design imported before into it, when someone chooses to:
 *
 *  - a `max-w-<n>px` class of the plugin's — wider than the widest the theme has, or off its 5px grid — becomes the theme's class of the
 *    nearest width it has;
 *  - a block's own CSS (`dxaiCss`) with such a width, or a padding side above the largest the theme has, is written again with the
 *    fitted size (and its dxs- class, which is the hash of it, follows).
 *
 * Nothing else about a block changes. Declined: a block whose stored tag is not what its attributes say (someone edited it), and a
 * site that has no design system to keep within (Utility_Classes::system() is null).
 */
final class Sizes extends Converter {

	/** The blocks that carry a class list of the design's. */
	private const BLOCKS = array(
		'core/group',
		'core/columns',
		'core/column',
		'core/paragraph',
		'core/heading',
		'core/list',
		'core/list-item',
		'core/quote',
		'core/buttons',
		'core/button',
		'core/image',
		'dxai-ui/box',
		'dxai-ui/text',
		'dxai-ui/link',
		'dxai-ui/image',
		'dxai-ui/svg',
	);

	public function id(): string {
		return 'sizes';
	}

	public function label(): string {
		return __( 'Widths and paddings within the site\'s design system', 'dxai-ui' );
	}

	public function source(): string {
		return 'core/group';
	}

	public function sources(): array {
		// …and the theme's own blocks a design's elements become (a link box, a label, a picture).
		return array_merge( self::BLOCKS, Capabilities::blocks( 'link_box' ), Capabilities::blocks( 'span' ), Capabilities::blocks( 'picture' ) );
	}

	public function target(): string {
		return 'core/group';
	}

	public function available(): bool {
		return Utility_Classes::system() !== null;
	}

	public function convert( array $block, ?array $parent = null ): ?array {
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$class = (string) ( $attrs['className'] ?? '' );
		$css   = (string) ( $attrs['dxaiCss'] ?? '' );
		if ( $class === '' && $css === '' ) {
			return null;
		}
		$system = Utility_Classes::system();
		if ( $system === null ) {
			return null;
		}

		// The classes: a width the theme has no class for becomes the one it has.
		$tokens = preg_split( '/\s+/', trim( $class ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		$new    = array();
		$swap   = array();
		foreach ( $tokens as $token ) {
			$to = $token;
			if ( preg_match( '/^max-w-(\d+)px$/', $token, $m ) === 1 ) {
				$to = Utility_Classes::family( $token ) !== null ? 'max-w-' . self::fit_width( (int) $m[1], $system['max_width'] ) . 'px' : $token;
			}
			if ( $to !== $token ) {
				$swap[ $token ] = $to;
			}
			$new[] = $to;
		}

		// The block's own CSS.
		$fitted  = $css === '' ? '' : Utility_Classes::fit_system( $css );
		$changed = $swap !== array() || $fitted !== $css;
		if ( ! $changed ) {
			return null;
		}
		$next = $attrs;
		if ( $swap !== array() ) {
			$next['className'] = implode( ' ', array_values( array_unique( $new ) ) );
		}
		if ( $fitted !== $css ) {
			$next['dxaiCss'] = $fitted;
			$swap[ Style_Hoister::css_class( $css ) ] = $fitted === '' ? '' : Style_Hoister::css_class( $fitted );
		}

		return self::retag( $block, $next, $swap );
	}

	/** A width on the theme's grid and no wider than its widest. */
	private static function fit_width( int $n, int $max ): int {
		return $n < 5 ? $n : (int) min( (float) $max, round( $n / 5 ) * 5 );
	}

	/**
	 * The block with the classes in $swap (old => new, '' drops one) replaced in its first opening tag and its attributes set. Null when
	 * the tag does not carry every class the attributes say it does.
	 *
	 * @param array<string, mixed> $block
	 * @param array<string, mixed> $next
	 * @param array<string, string> $swap
	 * @return array<string, mixed>|null
	 */
	private static function retag( array $block, array $next, array $swap ): ?array {
		$content = is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array();
		$at      = null;
		foreach ( $content as $i => $chunk ) {
			if ( is_string( $chunk ) && preg_match( '/^\s*<([a-z][a-z0-9]*)\b([^>]*)>/i', $chunk ) === 1 ) {
				$at = $i;
				break;
			}
			if ( is_string( $chunk ) && trim( $chunk ) !== '' ) {
				return null;
			}
		}
		if ( $at === null ) {
			// A block that writes its markup from its attributes (a dynamic one): only the attributes change.
			if ( ( $block['innerHTML'] ?? '' ) === '' ) {
				$block['attrs'] = $next;

				return $block;
			}

			return null;
		}
		preg_match( '/^(\s*)(<([a-z][a-z0-9]*)\b)([^>]*)(>)/i', (string) $content[ $at ], $m );
		if ( preg_match( '/\sclass="([^"]*)"/', $m[4], $c ) !== 1 ) {
			return null;
		}
		$have = preg_split( '/\s+/', trim( $c[1] ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		foreach ( array_keys( $swap ) as $old ) {
			if ( ! in_array( $old, $have, true ) ) {
				return null;
			}
		}
		$want = array();
		foreach ( $have as $token ) {
			if ( array_key_exists( $token, $swap ) ) {
				if ( $swap[ $token ] !== '' ) {
					$want[] = $swap[ $token ];
				}
			} else {
				$want[] = $token;
			}
		}
		$class = implode( ' ', array_values( array_unique( $want ) ) );
		$attrs = $class === '' ? str_replace( $c[0], '', $m[4] ) : str_replace( $c[0], ' class="' . esc_attr( $class ) . '"', $m[4] );

		$content[ $at ]        = $m[1] . $m[2] . $attrs . $m[5] . substr( (string) $content[ $at ], strlen( $m[0] ) );
		$block['attrs']        = $next;
		$block['innerContent'] = $content;
		$block['innerHTML']    = implode( '', array_filter( $content, 'is_string' ) );

		return $block;
	}
}

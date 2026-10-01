<?php
/**
 * An icon that is a plain drawing: core's Image, with the drawing as a file in the media library.
 *
 * @package DXAI_UI\Blocks\Native
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks\Native;

use DXAI_UI\Compiler\Utility_Classes;
use DXAI_UI\Media\Svg_Files;
use DXAI_UI\Support\Upload_Paths;

/**
 * `dxai-ui/svg` → `core/image` pointing at an SVG file, the way the team's sites hold their icons: swapped from the
 * library, sized in the block's panel. The design's classes and CSS were written for the `<svg>` and go on the figure
 * as for any picture (Image, `dxai-part-img`), their rules written for the image inside it.
 *
 * Only a drawing is a picture. An icon that takes its colour from the text around it (`currentColor`), from a variable
 * of the design, or from a class of its own on a shape; one with more than shapes in it (a gradient, a clip, a symbol
 * reference, an animation, a style); one whose size nothing says; and one in a place the picture's wrapper would
 * disturb (Image's guards) stay the design's inline icon.
 */
final class Svg_Image extends Converter {

	private const KNOWN = array( 'svgAttrs', 'svgInner', 'dxaiInner' );

	/** @var array<string, array{safe:bool, display:string}> The design's stylesheet path + mtime => what it says about svgs. */
	private static array $sheets = array();

	public function id(): string {
		return 'svg';
	}

	public function label(): string {
		return __( 'Icons → Image', 'dxai-ui' );
	}

	public function source(): string {
		return 'dxai-ui/svg';
	}

	public function target(): string {
		return 'core/image';
	}

	public function available(): bool {
		return parent::available() && Svg_Files::available();
	}

	public function convert( array $block, ?array $parent = null ): ?array {
		if ( ( $block['blockName'] ?? '' ) !== $this->source() || ( $block['innerBlocks'] ?? array() ) !== array() ) {
			return null;
		}
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		if ( ! self::only( $attrs, self::KNOWN ) || Image::spaces_children( $parent ) || ! Image::sheet_allows() || ! self::sheet( (int) ( self::$context['home'] ?? 0 ) )['safe'] ) {
			return null;
		}
		$content = is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array();
		if ( count( $content ) !== 1 || ! is_string( $content[0] ) || preg_match( '/^(\s*)<svg\b.*<\/svg>(\s*)$/s', $content[0], $m ) !== 1 ) {
			return null;
		}
		$inner = (string) ( $attrs['svgInner'] ?? '' );
		// A shape's own class is a rule of the page that a file cannot carry; so are colours taken from the page.
		if ( preg_match( '/\sclass=|\sstyle=|currentcolor|var\(|<use\b|<style\b|<script\b/i', $inner ) === 1 || stripos( (string) wp_json_encode( $attrs['svgAttrs'] ?? array() ), 'currentcolor' ) !== false ) {
			return null;
		}
		$pairs = array();
		foreach ( (array) ( $attrs['svgAttrs'] ?? array() ) as $pair ) {
			if ( ! is_array( $pair ) || count( $pair ) !== 2 || ! is_string( $pair[0] ) || ! is_string( $pair[1] ) || isset( $pairs[ strtolower( $pair[0] ) ] ) ) {
				return null;
			}
			$pairs[ strtolower( $pair[0] ) ] = $pair[1];
		}
		// A drawing stretched to its box (the wave between two sections) is the page's: as a picture its curve is drawn on
		// whole pixels and the edge moves by a fraction of one.
		if ( isset( $pairs['preserveaspectratio'] ) && stripos( $pairs['preserveaspectratio'], 'none' ) !== false ) {
			return null;
		}
		// What is about the page rather than the drawing is taken out; anything else the drawing does not know is a reason to leave it.
		$class = (string) ( $pairs['class'] ?? '' );
		$alt   = '';
		foreach ( array( 'aria-hidden', 'focusable', 'role', 'class' ) as $page ) {
			unset( $pairs[ $page ] );
		}
		if ( isset( $pairs['aria-label'] ) ) {
			$alt = (string) $pairs['aria-label'];
			unset( $pairs['aria-label'] );
		}
		$root = Svg_Files::document( $pairs, $inner );
		if ( $root === null ) {
			return null;
		}

		// The root's class: utilities and the one hoisted rule of its own; a class of the design's is a rule we cannot see.
		$utilities = array();
		$css       = '';
		$hoisted   = is_array( $attrs['dxaiInner'] ?? null ) ? $attrs['dxaiInner'] : array();
		foreach ( preg_split( '/\s+/', trim( $class ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $token ) {
			if ( preg_match( '/^dxs-[a-z0-9]+$/', $token ) === 1 ) {
				if ( ! isset( $hoisted[ $token ] ) || ! is_string( $hoisted[ $token ] ) ) {
					return null;
				}
				$css .= ( $css === '' ? '' : ';' ) . rtrim( trim( $hoisted[ $token ] ), ';' );
				unset( $hoisted[ $token ] );
			} elseif ( Utility_Classes::is_utility( $token ) ) {
				$utilities[] = $token;
			} else {
				return null;
			}
		}
		if ( $hoisted !== array() || self::hazard( $alt ) || stripos( $css, 'important' ) !== false ) {
			return null;
		}
		// Something must say how big the picture is, or an image of a viewBox alone is drawn at its default size.
		$sized = ( isset( $pairs['width'], $pairs['height'] ) && preg_match( '/^[\d.]+(?:px)?$/', $pairs['width'] . $pairs['height'] ) === 1 )
			|| preg_match( '/(?:^|;)\s*(?:width|height)\s*:/', $css ) === 1
			|| preg_match( '/(?:^|\s)(?:[a-z]+:)?(?:w|h|size)-/', ' ' . implode( ' ', $utilities ) ) === 1;
		if ( ! $sized ) {
			return null;
		}

		// The design's `img` rules (a picture is a block) are not the svg's: the picture keeps the display the svg had, and
		// a width limit the svg never had is taken off — unless the icon says so itself.
		$own = array( 'className' => implode( ' ', $utilities ), 'dxaiCss' => $css );
		if ( ! self::states_display( $own ) ) {
			$css = 'display:' . self::sheet( (int) ( self::$context['home'] ?? 0 ) )['display'] . ( $css !== '' ? ';' . $css : '' );
		}
		if ( preg_match( '/(?:^|\s)(?:[a-z]+:)?max-w-/', ' ' . implode( ' ', $utilities ) ) !== 1 && preg_match( '/(?:^|;)\s*max-width\s*:/', $css ) !== 1 ) {
			$css .= ( $css !== '' ? ';' : '' ) . 'max-width:none';
		}

		// Planning (Native_Blocks::plan) counts what would change and makes nothing: no file, no attachment.
		$file = ! empty( self::$context['dry'] ) ? array( 'id' => 0, 'url' => '', 'width' => 0, 'height' => 0 ) : Svg_Files::ensure( $root );
		if ( $file === null ) {
			return null;
		}
		$new = array(
			'id'              => $file['id'],
			'sizeSlug'        => 'full',
			'linkDestination' => 'none',
			'className'       => trim( 'dxai-part-img dxai-icon ' . implode( ' ', $utilities ) ),
		);
		if ( $css !== '' ) {
			$new['dxaiCss'] = $css;
		}
		$html = '<figure class="' . esc_attr( trim( 'wp-block-image size-full ' . self::class_tail( $new ) ) ) . '">'
			. '<img src="' . esc_url( $file['url'] ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . $file['id'] . '"/></figure>';

		$block['blockName']    = $this->target();
		$block['attrs']        = $new;
		$block['innerContent'] = array( $m[1] . $html . $m[2] );
		$block['innerHTML']    = $block['innerContent'][0];

		return $block;
	}

	/**
	 * What the design's stylesheet says about svgs: whether every rule for them can be carried to a picture, the display
	 * an svg has, and those rules written for the picture (see read()).
	 *
	 * @return array{safe:bool, display:string, mirror:string}
	 */
	private static function sheet( int $home ): array {
		$sheet = $home > 0 ? Upload_Paths::for_meta( $home, '_dxai_ui_css_url' ) : array();
		$path  = (string) ( $sheet['path'] ?? '' );
		if ( $path === '' || ! is_readable( $path ) ) {
			return array( 'safe' => true, 'display' => 'inline', 'mirror' => '' );
		}
		$key = $path . '|' . (int) filemtime( $path );
		if ( ! isset( self::$sheets[ $key ] ) ) {
			self::$sheets[ $key ] = self::read( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		return self::$sheets[ $key ];
	}

	/**
	 * The rules a design wrote for its svgs, as far as a picture can have them. A rule whose last compound is an svg
	 * (`[data-stats] svg { width: 32px }`, `.card > svg`, `svg:hover`) is written again for the picture — `svg` becomes
	 * `.dxai-icon img`, the rest of the selector and the media query around it stay — and that is `mirror`. A rule
	 * that cannot be carried over makes the sheet unsafe, and the design's icons stay inline: one that styles what is
	 * inside an svg (`svg path`), one that gives an svg a colour (`fill`, `stroke`, `color`), one with a class or a
	 * position on the svg itself. A rule that names an image as well reaches the picture already.
	 *
	 * @return array{safe:bool, display:string, mirror:string}
	 */
	public static function read( string $css ): array {
		$css  = (string) preg_replace( '~/\*.*?\*/~s', '', $css );
		$info = array(
			'safe'    => true,
			'display' => 'inline',
		);
		$mirror = self::mirror_block( $css, $info );

		return array(
			'safe'    => $info['safe'],
			'display' => in_array( $info['display'], array( 'block', 'inline', 'inline-block', 'flex', 'inline-flex', 'grid' ), true ) ? $info['display'] : 'inline',
			'mirror'  => $info['safe'] ? $mirror : '',
		);
	}

	/**
	 * The rules of a block of CSS (the sheet, or what is inside a media query) written for the picture.
	 *
	 * @param array{safe:bool, display:string} $info Filled in: whether it is safe, the display an svg has.
	 */
	private static function mirror_block( string $css, array &$info ): string {
		$out = '';
		$n   = strlen( $css );
		$i   = 0;
		while ( $i < $n ) {
			$open = strpos( $css, '{', $i );
			$semi = strpos( $css, ';', $i );
			if ( $semi !== false && ( $open === false || $semi < $open ) ) {
				$i = $semi + 1; // @import, @charset
				continue;
			}
			if ( $open === false ) {
				break;
			}
			$prelude = trim( substr( $css, $i, $open - $i ) );
			$depth   = 1;
			$j       = $open + 1;
			while ( $j < $n && $depth > 0 ) {
				$ch = $css[ $j ];
				if ( $ch === '{' ) {
					++$depth;
				} elseif ( $ch === '}' ) {
					--$depth;
				}
				++$j;
			}
			$body = substr( $css, $open + 1, max( 0, $j - $open - 2 ) );
			$i    = $j;
			if ( $prelude === '' ) {
				continue;
			}
			if ( $prelude[0] === '@' ) {
				if ( preg_match( '/^@(?:media|supports|layer|container)\b/i', $prelude ) === 1 ) {
					$inner = self::mirror_block( $body, $info );
					if ( $inner !== '' ) {
						$out .= $prelude . '{' . $inner . '}';
					}
				}
				continue; // @font-face, @keyframes
			}
			$out .= self::mirror_rule( $prelude, $body, $info );
		}

		return $out;
	}

	/**
	 * One rule. Empty when it has nothing for a picture.
	 *
	 * @param array{safe:bool, display:string} $info
	 */
	private static function mirror_rule( string $prelude, string $body, array &$info ): string {
		$svg = '/(?<![\w\-.#:])svg(?![\w-])/i';
		$img = '/(?<![\w\-.#:])img(?![\w-])/i';
		$bare = (string) preg_replace( '/\[[^\]]*\]/', '', $prelude );
		if ( preg_match( $svg, $bare ) !== 1 ) {
			return '';
		}
		// A rule that names an image as well reaches the picture already.
		if ( preg_match( $img, $bare ) === 1 ) {
			if ( preg_match( '/(?:^|;)\s*display\s*:\s*([a-z-]+)/i', $body, $m ) === 1 ) {
				$info['display'] = strtolower( $m[1] );
			}

			return '';
		}
		// A colour for the drawing cannot be given to a file.
		if ( preg_match( '/(?:^|;)\s*(?:fill|stroke|color|stroke-[a-z]+|fill-[a-z]+)\s*:/i', $body ) === 1 ) {
			$info['safe'] = false;

			return '';
		}
		$out     = array();
		$boosted = array();
		foreach ( self::split_selectors( $prelude ) as $selector ) {
			if ( preg_match( $svg, (string) preg_replace( '/\[[^\]]*\]/', '', $selector ) ) !== 1 ) {
				continue;
			}
			// The last compound must be the svg, bare or with a state: anything after or on it is not carried.
			if ( preg_match( '/^(.*?)((?<![\w\-.#:])svg)((?::hover|:focus|:active|:focus-visible)*)\s*$/is', $selector, $m ) !== 1 || preg_match( $svg, (string) preg_replace( '/\[[^\]]*\]/', '', $m[1] ) ) === 1 ) {
				$info['safe'] = false;

				return '';
			}
			$out[]     = $m[1] . '.dxai-icon img' . $m[3];
			$boosted[] = $m[1] . str_repeat( ':is(.dxai-icon,#dxai-h)', 4 ) . ' img' . $m[3];
			if ( trim( $m[1] ) === '' && preg_match( '/(?:^|;)\s*display\s*:\s*([a-z-]+)/i', $body, $d ) === 1 ) {
				$info['display'] = strtolower( $d[1] );
			}
		}

		if ( $out === array() ) {
			return '';
		}
		$rule = implode( ',', $out ) . '{' . $body . '}';
		// The icon's own rule has the precedence an inline style had, and !important on the picture (Style_Rules::rule): an
		// !important of the design, which beat the inline style on the svg, is written above it.
		$important = array();
		foreach ( Utility_Classes::declarations( $body ) as $declaration ) {
			if ( $declaration['important'] ) {
				$important[] = $declaration['raw'];
			}
		}

		return $important === array() ? $rule : $rule . implode( ',', $boosted ) . '{' . implode( ';', $important ) . '}';
	}

	/**
	 * A selector list as its selectors: cut at the commas that are not inside a bracket or parenthesis.
	 *
	 * @return array<int, string>
	 */
	private static function split_selectors( string $list ): array {
		$out   = array();
		$depth = 0;
		$cur   = '';
		for ( $i = 0, $n = strlen( $list ); $i < $n; $i++ ) {
			$ch = $list[ $i ];
			if ( $ch === '(' || $ch === '[' ) {
				++$depth;
			} elseif ( $ch === ')' || $ch === ']' ) {
				--$depth;
			}
			if ( $ch === ',' && $depth === 0 ) {
				$out[] = trim( $cur );
				$cur   = '';
				continue;
			}
			$cur .= $ch;
		}

		return array_values( array_filter( array_merge( $out, array( trim( $cur ) ) ), static fn( $x ) => $x !== '' ) );
	}

	/**
	 * The rules the design wrote for its svgs, written for the pictures that replaced some of them. Printed with a page
	 * that holds one (Style_Rules). Empty when the sheet is unsafe for pictures or says nothing about svgs.
	 */
	public static function mirrored_css( int $design ): string {
		return self::sheet( $design )['mirror'];
	}
}

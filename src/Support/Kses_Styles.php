<?php
/**
 * What WordPress's KSES filter does to a converted design's inline styles.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Support;

use DXAI_UI\Compiler\Style_Hoister;

/**
 * KSES runs on every post saved by a user without `unfiltered_html`: a site
 * admin on multisite, anyone on a site with DISALLOW_UNFILTERED_HTML, an
 * Author, anything saved from cron or WP-CLI with no user. Its CSS filter,
 * safecss_filter_attr(), keeps a declaration only when the property is on
 * core's list and the value holds no `(` outside the few functions it knows.
 * A converted design loses a lot to that — measured over this plugin's own
 * output: `text-wrap`, `inset`, `box-sizing`, `transition` and its delay (the
 * reveal stagger), `animation`, `backdrop-filter`, `mix-blend-mode`, and every
 * colour written as rgba(), hsl(), oklch() or color-mix().
 *
 * Worse than the loss is what it does to blocks. KSES filters the style=""
 * in the HTML but not the same CSS kept in the block's `dxaiStyle` attribute
 * (it runs a plain-text filter over attribute strings), and the editor
 * rebuilds style="" from the attribute — so the two no longer match, and every
 * such block opens as "This block contains unexpected or invalid content".
 * On this install that was 227 blocks on 16 pages, and 711 blocks on 40 pages
 * once the rest of the content goes through the same filter.
 *
 * Three filters, all on core hooks, so they hold wherever KSES runs:
 *  - safe_style_css: the properties these designs use that core's list
 *    lacks. A property only gets past the list; its value is still checked.
 *  - safecss_filter_attr_allow_css: colour, filter, easing and transform
 *    functions whose arguments are nothing but values. A function that holds
 *    anything else — url() above all — still fails the check, exactly as it
 *    does in core, because only innermost calls made of plain values are
 *    removed before core's test is repeated.
 *  - pre_kses: every block's CSS attribute goes through safecss_filter_attr()
 *    too, so the attribute and the HTML come out of KSES identical and the
 *    block stays valid whatever was removed.
 *
 * None of this lets anything through that could run script or load a
 * resource; it widens what counts as plain styling.
 */
final class Kses_Styles {

	/**
	 * Properties added to core's safe list.
	 *
	 * @var array<int, string>
	 */
	public const PROPERTIES = array(
		// Layout.
		'inset',
		'inset-block',
		'inset-block-start',
		'inset-block-end',
		'inset-inline',
		'inset-inline-start',
		'inset-inline-end',
		'box-sizing',
		'place-content',
		'place-items',
		'place-self',
		'order',
		'grid-area',
		'grid-template',
		'grid-template-areas',
		'grid-auto-flow',
		'overflow-x',
		'overflow-y',
		'overflow-wrap',
		'word-break',
		'word-wrap',
		'isolation',
		'contain',
		'scroll-margin-top',
		'scroll-behavior',
		'overscroll-behavior',
		'list-style',
		'list-style-position',
		'table-layout',
		'content-visibility',
		'scrollbar-width',
		'scrollbar-color',
		'scrollbar-gutter',
		'-webkit-overflow-scrolling',
		'-webkit-clip-path',
		// Text.
		'text-wrap',
		'text-wrap-mode',
		'text-wrap-style',
		'text-overflow',
		'text-shadow',
		'text-underline-offset',
		'text-decoration-line',
		'text-decoration-color',
		'text-decoration-style',
		'text-decoration-thickness',
		'font-feature-settings',
		'font-variant-numeric',
		'font-variation-settings',
		'font-optical-sizing',
		'hyphens',
		'line-clamp',
		'-webkit-line-clamp',
		'-webkit-box-orient',
		'-webkit-text-fill-color',
		'-webkit-text-stroke',
		'-webkit-font-smoothing',
		'tab-size',
		// Effects and motion.
		'transition',
		'transition-property',
		'transition-duration',
		'transition-delay',
		'transition-timing-function',
		'transition-behavior',
		'animation',
		'animation-name',
		'animation-duration',
		'animation-delay',
		'animation-timing-function',
		'animation-iteration-count',
		'animation-direction',
		'animation-fill-mode',
		'animation-play-state',
		'backdrop-filter',
		'-webkit-backdrop-filter',
		'mix-blend-mode',
		'will-change',
		'backface-visibility',
		'perspective',
		'perspective-origin',
		'transform-style',
		'translate',
		'rotate',
		'scale',
		'outline',
		'outline-color',
		'outline-style',
		'outline-width',
		'outline-offset',
		'background-clip',
		'-webkit-background-clip',
		'background-origin',
		// Interaction.
		'user-select',
		'-webkit-user-select',
		'appearance',
		'-webkit-appearance',
		'resize',
		'accent-color',
		'caret-color',
		'touch-action',
	);

	/**
	 * Functions whose arguments are plain values: colours, filters, easing,
	 * transforms (older WordPress versions do not know the transform ones).
	 */
	private const FUNCTIONS = 'rgba?|hsla?|hwb|lab|lch|oklab|oklch|color|color-mix|light-dark'
		. '|blur|brightness|contrast|drop-shadow|grayscale|hue-rotate|invert|opacity|saturate|sepia'
		. '|cubic-bezier|steps|linear'
		. '|linear-gradient|radial-gradient|conic-gradient|repeating-linear-gradient|repeating-radial-gradient|repeating-conic-gradient'
		. '|matrix|matrix3d|perspective|rotate|rotate3d|rotateX|rotateY|rotateZ|scale|scale3d|scaleX|scaleY|scaleZ|skew|skewX|skewY|translate|translate3d|translateX|translateY|translateZ'
		. '|var|calc|min|max|clamp|env|fit-content|minmax|repeat';

	/** Block attributes that hold a CSS declaration list mirrored into style="". */
	/*
	 * dxaiCss is not mirrored into the HTML (Style_Hoister writes a class
	 * instead), but it is CSS someone without unfiltered_html could edit, so
	 * KSES's CSS filter applies to it all the same.
	 */
	private const STYLE_ATTRS = array( 'dxaiStyle', 'style', 'dxaiCss' );

	public function register(): void {
		add_filter( 'safe_style_css', array( self::class, 'properties' ) );
		add_filter( 'safecss_filter_attr_allow_css', array( self::class, 'allow_functions' ), 10, 2 );
		// After wp_pre_kses_block_attributes (priority 10), which has already
		// parsed and re-serialised the blocks.
		add_filter( 'pre_kses', array( self::class, 'sync_block_styles' ), 11, 2 );
	}

	/**
	 * @param array<int, string> $properties
	 * @return array<int, string>
	 */
	public static function properties( $properties ): array {
		return array_values( array_unique( array_merge( is_array( $properties ) ? $properties : array(), self::PROPERTIES ) ) );
	}

	/**
	 * Allow a declaration core refused only because of a function it does not
	 * know.
	 *
	 * Innermost calls whose arguments hold no parenthesis are removed, over
	 * and over, and core's own test is then repeated on what is left. So
	 * `drop-shadow(0 2px 4px rgba(0,0,0,.2))` clears (rgba first, then
	 * drop-shadow), while `color-mix(in srgb, url(x) 50%, red)` does not:
	 * url() is never removed, so its parent never becomes innermost, and the
	 * `(` that remains fails the test exactly as it did before.
	 *
	 * @param bool   $allow Core's verdict.
	 * @param string $test  The declaration with core's known functions removed.
	 */
	public static function allow_functions( $allow, $test ): bool {
		if ( $allow ) {
			return true;
		}
		$rest = (string) $test;
		for ( $i = 0; $i < 12; $i++ ) {
			// Arguments made of plain values only: letters, digits, spaces and
			// . , % / + # - — no quote, colon, brace, backslash or ampersand.
			$next = preg_replace( '/(?<![\w-])(?:' . self::FUNCTIONS . ')\([a-zA-Z0-9\s.,%\/+#-]*\)/i', '', $rest );
			if ( ! is_string( $next ) || $next === $rest ) {
				break;
			}
			$rest = $next;
		}

		return 0 === preg_match( '%[\\\\(&=}]|/\*%', $rest );
	}

	/**
	 * Run each block's CSS attribute through the same filter KSES is about
	 * to run over its HTML, so both come out the same.
	 *
	 * Only for post content in the 'post' context, and only when a block
	 * carries such an attribute; anything else is returned untouched, byte
	 * for byte.
	 *
	 * @param string|mixed        $content
	 * @param array<mixed>|string $context wp_kses() allowed-HTML argument.
	 * @return string|mixed
	 */
	public static function sync_block_styles( $content, $context = '' ) {
		if ( ! is_string( $content ) || ! str_contains( $content, '<!-- wp:' ) ) {
			return $content;
		}
		if ( ! str_contains( $content, '"dxaiStyle"' ) && ! str_contains( $content, '"dxaiCss"' ) && ! str_contains( $content, '"dxaiInner"' ) && ! str_contains( $content, '"style":"' ) && ! str_contains( $content, '&amp;' ) ) {
			return $content;
		}
		if ( is_string( $context ) && $context !== 'post' ) {
			return $content;
		}

		$changed = false;
		$blocks  = self::sync_blocks( parse_blocks( $content ), $changed );

		return $changed ? serialize_blocks( $blocks ) : $content;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	private static function sync_blocks( array $blocks, bool &$changed ): array {
		foreach ( $blocks as $i => $block ) {
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			foreach ( self::STYLE_ATTRS as $key ) {
				if ( ! isset( $attrs[ $key ] ) || ! is_string( $attrs[ $key ] ) ) {
					continue;
				}
				$clean = self::filter( $attrs[ $key ] );
				if ( $clean === self::normalise( $attrs[ $key ] ) ) {
					continue;
				}
				if ( $key === Style_Hoister::ATTR ) {
					$blocks[ $i ]['innerContent'] = self::swap_class(
						(array) ( $block['innerContent'] ?? array() ),
						Style_Hoister::css_class( $attrs[ $key ] ),
						$clean === '' ? '' : Style_Hoister::css_class( $clean )
					);
					if ( isset( $block['innerHTML'] ) ) {
						$blocks[ $i ]['innerHTML'] = implode( '', array_filter( $blocks[ $i ]['innerContent'], 'is_string' ) );
					}
				}
				if ( $clean === '' ) {
					unset( $attrs[ $key ] );
				} else {
					$attrs[ $key ] = $clean;
				}
				$changed = true;
			}
			if ( isset( $attrs[ Style_Hoister::INNER_ATTR ] ) ) {
				$attrs = self::sync_inner( $attrs, $changed );
			}
			$attrs                 = self::plain_text_attrs( (string) ( $block['blockName'] ?? '' ), $attrs, $changed );
			$blocks[ $i ]['attrs'] = $attrs;
			if ( ! empty( $block['innerBlocks'] ) ) {
				$blocks[ $i ]['innerBlocks'] = self::sync_blocks( $block['innerBlocks'], $changed );
			}
		}

		return $blocks;
	}

	/**
	 * A block's dxaiInner (class => declarations) through the same CSS filter.
	 *
	 * The classes are the keys, so a value KSES trims keeps its class and the
	 * element inside still matches it. Only `dxs-` keys are kept: the rule is
	 * written for the key, and nothing else is this plugin's to style.
	 *
	 * @param array<string, mixed> $attrs
	 * @return array<string, mixed>
	 */
	private static function sync_inner( array $attrs, bool &$changed ): array {
		$inner = $attrs[ Style_Hoister::INNER_ATTR ];
		if ( ! is_array( $inner ) ) {
			unset( $attrs[ Style_Hoister::INNER_ATTR ] );
			$changed = true;

			return $attrs;
		}
		$kept = array();
		foreach ( $inner as $class => $css ) {
			if ( ! is_string( $class ) || ! is_string( $css ) || preg_match( '/^dxs-[a-z0-9]+$/', $class ) !== 1 ) {
				$changed = true;
				continue;
			}
			$clean = self::filter( $css );
			if ( $clean === self::normalise( $css ) ) {
				$kept[ $class ] = $css;
				continue;
			}
			$changed = true;
			if ( $clean !== '' ) {
				$kept[ $class ] = $clean;
			}
		}
		if ( $kept === array() ) {
			unset( $attrs[ Style_Hoister::INNER_ATTR ] );
		} else {
			$attrs[ Style_Hoister::INNER_ATTR ] = $kept;
		}

		return $attrs;
	}

	/**
	 * The block's element with its dxs- class renamed after its CSS changed.
	 *
	 * dxaiCss is not mirrored into style="", but its hash is: the element
	 * carries `dxs-{hash}`, the class Style_Rules writes the rule for. When
	 * KSES drops a declaration the value — and so the hash — changes, and the
	 * old class would name a rule no longer written: the declarations KSES
	 * kept would stop applying and the block would no longer match its save().
	 * The first element carrying the old class is the block's own.
	 *
	 * @param array<int, mixed> $chunks innerContent.
	 * @return array<int, mixed>
	 */
	private static function swap_class( array $chunks, string $old, string $new ): array {
		foreach ( $chunks as $j => $chunk ) {
			if ( ! is_string( $chunk ) || ! str_contains( $chunk, $old ) ) {
				continue;
			}
			$done = false;
			$next = preg_replace_callback(
				'/(\sclass=")([^"]*)(")/i',
				static function ( array $m ) use ( $old, $new, &$done ): string {
					$tokens = preg_split( '/\s+/', trim( $m[2] ) );
					$at     = $done || ! is_array( $tokens ) ? false : array_search( $old, $tokens, true );
					if ( $at === false ) {
						return $m[0];
					}
					$done = true;
					if ( $new === '' ) {
						unset( $tokens[ $at ] );
					} else {
						$tokens[ $at ] = $new;
					}

					return $m[1] . implode( ' ', $tokens ) . $m[3];
				},
				$chunk
			);
			if ( is_string( $next ) && $done ) {
				$chunks[ $j ] = $next;
				break;
			}
		}

		return $chunks;
	}

	/**
	 * Undo KSES's `&` → `&amp;` in attributes that hold plain text.
	 *
	 * core runs every string attribute of a block comment through wp_kses(),
	 * which strips tags — wanted — and rewrites a bare `&` as `&amp;`. This
	 * plugin's blocks keep link text, alt text, labels, URLs and data-* values
	 * as plain text, and save() escapes them itself, so the editor then wrote
	 * `&amp;amp;`: "Sewage & Pipe Cleanup" came back as "Sewage &amp; Pipe
	 * Cleanup" (116 links over the corpus), a query string's `&` broke, and a
	 * Tailwind class such as `md:[&]:border-l` stopped matching its rule.
	 * Only `&amp;` is turned back — the one rewrite KSES makes to a value it
	 * keeps — and never in a value that still holds markup (the SVG block's
	 * inner markup is real HTML, where `&amp;` is correct).
	 *
	 * @param array<string, mixed> $attrs
	 * @return array<string, mixed>
	 */
	private static function plain_text_attrs( string $name, array $attrs, bool &$changed ): array {
		$own = str_starts_with( $name, 'dxai-ui/' );
		foreach ( $attrs as $key => $value ) {
			if ( in_array( $key, array( 'svgInner', 'svgAttrs', 'dxaiStyle', 'style', 'dxaiCss', 'dxaiInner' ), true ) ) {
				continue;
			}
			if ( ! $own && ! in_array( $key, array( 'className', 'ariaLabel', 'labelledBy' ), true ) ) {
				continue;
			}
			$decoded = self::decode_amp( $value );
			if ( $decoded !== $value ) {
				$attrs[ $key ] = $decoded;
				$changed       = true;
			}
		}

		return $attrs;
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private static function decode_amp( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::decode_amp( $v );
			}

			return $value;
		}
		if ( ! is_string( $value ) || ( ! str_contains( $value, '&amp;' ) && ! str_contains( $value, '&gt;' ) ) || str_contains( $value, '<' ) ) {
			return $value;
		}

		// And a lone `>`, which KSES spells `&gt;`: Tailwind's `md:[&>*]:w-auto`
		// came back as `md:[&&gt;*]:w-auto` and matched nothing.
		return str_replace( array( '&gt;', '&amp;' ), array( '>', '&' ), $value );
	}

	/**
	 * A declaration list as safecss_filter_attr() leaves it.
	 */
	public static function filter( string $css ): string {
		return (string) safecss_filter_attr( $css );
	}

	/**
	 * A declaration list spelled the way safecss_filter_attr() spells what it
	 * keeps — trimmed, joined with ";" — so an attribute that loses nothing is
	 * recognised as unchanged and left as it was written.
	 */
	public static function normalise( string $css ): string {
		$parts = array();
		// safecss_filter_attr() drops line breaks and tabs before it splits.
		$css = str_replace( array( "\n", "\r", "\t" ), '', $css );
		foreach ( explode( ';', $css ) as $part ) {
			$part = trim( $part );
			if ( $part !== '' ) {
				$parts[] = $part;
			}
		}

		return implode( ';', $parts );
	}
}

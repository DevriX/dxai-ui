<?php
/**
 * Gutenberg HTML wrappers and design-safe KSES for converted pages.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Design_Html {

	private const VOID = array( 'img', 'br', 'hr', 'input', 'meta', 'link', 'source', 'area', 'col', 'embed', 'wbr', 'path', 'line', 'circle', 'rect', 'polygon', 'polyline', 'stop', 'use' );

	/**
	 * The block design chrome is parked in, instead of `core/html`.
	 *
	 * Every `core/html` block carries a "Convert to Blocks" item that feeds its
	 * markup to Gutenberg's rawHandler, whose cleaner drops every class it is
	 * not explicitly told to keep. On a converted page that is one click per
	 * island away from losing the entire Tailwind layer. The button is guarded
	 * by `block.name !== 'core/html'`, so a block of our own never offers it.
	 */
	public const RAW_BLOCK = 'dxai-ui/html';

	/**
	 * Matches either delimiter, so markup written before the switch still parses.
	 */
	public static function raw_block_pattern(): string {
		return '/<!--\s*wp:(?:' . preg_quote( self::RAW_BLOCK, '/' ) . '|html)\s*-->\s*([\s\S]*?)\s*<!--\s*\/wp:(?:' . preg_quote( self::RAW_BLOCK, '/' ) . '|html)\s*-->/';
	}

	/**
	 * Wrap a visual section as native Gutenberg (group + html).
	 * core/html is last-resort so custom class trees, SVG, and details stay 1:1.
	 */
	public static function wrap( string $html, string $class_name = '' ): string {
		$html = trim( $html );
		if ( $html === '' ) {
			return '';
		}

		$html = preg_replace( '/<!--(?!\s*\/?wp:)[\s\S]*?-->/', '', $html ) ?? $html;
		$class_name = trim( $class_name );
		$json       = '';
		if ( $class_name !== '' ) {
			$encoded = wp_json_encode( array( 'className' => $class_name ) );
			$json    = is_string( $encoded ) ? ' ' . $encoded : '';
		}

		return '<!-- wp:group' . $json . ' -->' . "\n"
			. '<div class="wp-block-group' . ( $class_name !== '' ? ' ' . esc_attr( $class_name ) : '' ) . '">' . "\n"
			. '<!-- wp:' . self::RAW_BLOCK . ' -->' . "\n"
			. $html . "\n"
			. '<!-- /wp:' . self::RAW_BLOCK . ' -->' . "\n"
			. '</div>' . "\n"
			. '<!-- /wp:group -->';
	}

	/**
	 * One HTML tree so design selectors like .rv-root > section match the ZIP DOM.
	 *
	 * @param array<int, string> $parts
	 */
	public static function document( array $parts ): string {
		$html = self::join_parts( $parts );
		if ( $html === '' ) {
			return '';
		}

		return '<!-- wp:' . self::RAW_BLOCK . " -->\n{$html}\n<!-- /wp:" . self::RAW_BLOCK . ' -->';
	}

	/**
	 * @param array<int, string> $parts
	 */
	public static function join_parts( array $parts ): string {
		$keep = array();
		foreach ( $parts as $part ) {
			$part = trim( (string) $part );
			if ( $part === '' ) {
				continue;
			}
			$part = preg_replace( '/<!--(?!\s*\/?wp:)[\s\S]*?-->/', '', $part ) ?? $part;
			$keep[] = trim( $part );
		}

		return trim( implode( "\n", $keep ) );
	}

	/**
	 * The design's own HTML back out of block markup.
	 *
	 * Two paths. A structure that still has a dxai-ui/html raw block hands back
	 * its contents verbatim. A structure converted entirely to real blocks has
	 * no raw block, so the block comments come off and what is left is the
	 * design's markup with WordPress's own group wrapper still in it.
	 *
	 * That second path used to delete the wrapper element:
	 *
	 *     '/<\/?div\b[^>]*class="[^"]*wp-block-group[^"]*"[^>]*>/'
	 *
	 * The `<\/?div` alternation cannot match a CLOSING div, because a closing
	 * tag carries no class attribute for `[^>]*class="…"` to find. So every
	 * opener it removed left its `</div>` behind. Measured over the corpus
	 * (scratch: collapse/innerhtml-probe.php): 26 of 89 structures take this
	 * path and 22 of those came back unbalanced, one of them by 28 orphaned
	 * closers. The collapse is what made it matter — turning containers that
	 * used to be raw HTML into real blocks moved structures onto this path.
	 *
	 * Nothing reads a broken one today: the only caller is Blog_Hydrator, which
	 * needs one of four headings before it looks at the markup at all, and the
	 * single structure in the corpus carrying one takes the raw-block path. It
	 * is a trap rather than a live defect, and the fix is to stop deleting the
	 * element.
	 *
	 * Deleting it was the wrong intent anyway. Design_Html::wrap() and group()
	 * both put the design's OWN class on the group div — `class="wp-block-group
	 * rv-related"` — and Blog_Hydrator::article_section() then looks for the
	 * nearest wrapping section or div around its heading. Removing the element
	 * takes away the structure that caller is looking for. Taking off the one
	 * class WordPress added leaves the design's element exactly as the design
	 * wrote it, which is what this method is for, and cannot unbalance
	 * anything because it rewrites an attribute instead of a tag.
	 */
	public static function inner_html( string $markup ): string {
		$markup = trim( $markup );
		if ( $markup === '' ) {
			return '';
		}
		if ( preg_match_all( self::raw_block_pattern(), $markup, $matches ) ) {
			return trim( implode( "\n", $matches[1] ) );
		}

		$stripped = preg_replace( '/<!--\s*\/?wp:[\s\S]*?-->/', '', $markup ) ?? $markup;

		// Any element, not just `div`. core/group honours `tagName`, so the
		// wrapper is a `section` on 1 of the 26 structures here and could be a
		// header, footer, main, article or aside on the next design — the old
		// regex said `div` and silently left those alone.
		//
		// The outer pattern is a loose gate and the callback does the exact
		// work, because `\b` is no help against a hyphen: `\bwp-block-group\b`
		// matches inside `my-wp-block-group-x` since `-` is already a
		// non-word character. Splitting on white space and comparing whole
		// tokens is what actually spares a design class that contains the
		// name. An element left with an empty class attribute keeps it: the
		// design may have written one.
		$stripped = preg_replace_callback(
			'/<[a-z][a-z0-9]*\b[^>]*\sclass="[^"]*wp-block-group[^"]*"[^>]*>/i',
			static function ( array $m ): string {
				return (string) preg_replace_callback(
					'/\sclass="([^"]*)"/i',
					static function ( array $c ): string {
						$classes = preg_split( '/\s+/', trim( $c[1] ) ) ?: array();
						$kept    = array_filter(
							$classes,
							static fn( string $class ): bool => $class !== '' && $class !== 'wp-block-group'
						);

						return ' class="' . implode( ' ', $kept ) . '"';
					},
					$m[0],
					1
				);
			},
			$stripped
		) ?? $stripped;

		return trim( $stripped );
	}

	/**
	 * A `<main>` that is only a landmark around the page's bands — no class,
	 * and no style beyond `display:block`, which a <main> already has — so
	 * unwrapping it changes no box.
	 *
	 * Unwrapped, its bands become top-level blocks: edited, selected and moved
	 * one by one, instead of all living inside one group. Semper Dry's
	 * `<main id="main" style="display:block">` held every section of the page.
	 */
	public static function is_plain_main( \DOMElement $el ): bool {
		if ( strtolower( $el->nodeName ) !== 'main' || trim( $el->getAttribute( 'class' ) ) !== '' ) {
			return false;
		}
		$style = strtolower( (string) preg_replace( '/[\s;]+/', '', $el->getAttribute( 'style' ) ) );

		return $style === '' || $style === 'display:block';
	}

	/**
	 * The element children a plain <main> is unwrapped into, its id moved
	 * onto the first of them so links to it keep working (a skip link, a
	 * logo linking to `#main`). The moved id is marked `data-dxai-anchor`, so
	 * the geometry oracle knows the band is the design's band and not a node
	 * with a new identity. A first child with an id of its own keeps it.
	 *
	 * @return array<int, \DOMElement>
	 */
	public static function unwrap_main( \DOMElement $main ): array {
		$children = array();
		foreach ( $main->childNodes as $child ) {
			if ( $child instanceof \DOMElement ) {
				$children[] = $child;
			}
		}
		$id = trim( $main->getAttribute( 'id' ) );
		if ( $id !== '' && isset( $children[0] ) && trim( $children[0]->getAttribute( 'id' ) ) === '' ) {
			$children[0]->setAttribute( 'id', $id );
			$children[0]->setAttribute( 'data-dxai-anchor', $id );
		}

		return $children;
	}

	public static function is_void( string $tag ): bool {
		return in_array( strtolower( $tag ), self::VOID, true );
	}

	/**
	 * Allow SVG, details, data-* and style so admin preview matches the design.
	 *
	 * @param array<string, mixed>|string $html
	 */
	public static function kses( string $html ): string {
		$tags = wp_kses_allowed_html( 'post' );

		$extra = array(
			'section'    => true,
			'header'     => true,
			'footer'     => true,
			'nav'        => true,
			'main'       => true,
			'article'    => true,
			'aside'      => true,
			'figure'     => true,
			'figcaption' => true,
			'details'    => true,
			'summary'    => true,
			'button'     => true,
			'svg'        => true,
			'path'       => true,
			'line'       => true,
			'circle'     => true,
			'rect'       => true,
			'g'          => true,
			'defs'       => true,
			'lineargradient' => true,
			'stop'       => true,
			'polygon'    => true,
			'polyline'   => true,
			'use'        => true,
			'symbol'     => true,
			'title'      => true,
			'desc'       => true,
			'span'       => true,
			'div'        => true,
			'ul'         => true,
			'ol'         => true,
			'li'         => true,
			'p'          => true,
			'a'          => true,
			'img'        => true,
			'h1'         => true,
			'h2'         => true,
			'h3'         => true,
			'h4'         => true,
			'h5'         => true,
			'h6'         => true,
			'blockquote' => true,
			'em'         => true,
			'i'          => true,
			'strong'     => true,
			'small'      => true,
			'label'      => true,
			'form'       => true,
			'input'      => true,
			'textarea'   => true,
			'select'     => true,
			'option'     => true,
			'video'      => true,
			'source'     => true,
			'picture'    => true,
			'time'       => true,
			'address'    => true,
			'style'      => array(
				'type' => true,
			),
		);

		$common = array(
			'class'               => true,
			'id'                  => true,
			'style'               => true,
			'role'                => true,
			'tabindex'            => true,
			'hidden'              => true,
			'title'               => true,
			'lang'                => true,
			'dir'                 => true,
			'width'               => true,
			'height'              => true,
			'xmlns'               => true,
			'viewbox'             => true,
			'preserveaspectratio' => true,
			'fill'                => true,
			'stroke'              => true,
			'stroke-width'        => true,
			'stroke-linecap'      => true,
			'stroke-linejoin'     => true,
			'stroke-dasharray'    => true,
			'd'                   => true,
			'x'                   => true,
			'y'                   => true,
			'x1'                  => true,
			'y1'                  => true,
			'x2'                  => true,
			'y2'                  => true,
			'cx'                  => true,
			'cy'                  => true,
			'r'                   => true,
			'rx'                  => true,
			'ry'                  => true,
			'points'              => true,
			'transform'           => true,
			'opacity'             => true,
			'href'                => true,
			'src'                 => true,
			'alt'                 => true,
			'target'              => true,
			'rel'                 => true,
			'type'                => true,
			'name'                => true,
			'value'               => true,
			'placeholder'         => true,
			'required'            => true,
			'disabled'            => true,
			'checked'             => true,
			'selected'            => true,
			'open'                => true,
			'aria-hidden'         => true,
			'aria-label'          => true,
			'aria-labelledby'     => true,
			'aria-selected'       => true,
			'aria-expanded'       => true,
			'aria-controls'       => true,
			'aria-current'        => true,
			'loading'             => true,
			'decoding'            => true,
		);

		foreach ( $extra as $tag => $_ ) {
			$existing          = is_array( $tags[ $tag ] ?? null ) ? $tags[ $tag ] : array();
			$tags[ $tag ]      = array_merge( $existing, $common );
			$tags[ $tag ]['data-*'] = true;
		}

		foreach ( array_keys( $tags ) as $tag ) {
			if ( is_array( $tags[ $tag ] ) ) {
				$tags[ $tag ]['data-*'] = true;
				$tags[ $tag ]['style']  = true;
			}
		}

		return wp_kses( $html, $tags );
	}

	/**
	 * Turn `u00b7` back into the character a stripcslashes pass ate.
	 *
	 * A leading backslash means the sequence is a real JSON escape in a block
	 * comment rather than damage. `serialize_block_attributes()` writes `--` as
	 * `--`, so repairing those produced `\-`, an invalid escape that
	 * made the whole attribute JSON unparseable and silently dropped every
	 * attribute on the block — any Tailwind class holding a CSS custom
	 * property, such as `bg-[var(--ara-tint)]`, hit it.
	 *
	 * Lived in three copies that drifted apart; keep it in one.
	 */
	public static function repair_unicode_artifacts( string $html ): string {
		return preg_replace_callback(
			'/(?<![A-Za-z0-9_\\\\])u00([0-9a-fA-F]{2})(?![A-Za-z0-9_])/',
			static function ( array $m ): string {
				$code = hexdec( $m[1] );
				if ( function_exists( 'mb_chr' ) ) {
					$char = mb_chr( $code, 'UTF-8' );

					return is_string( $char ) && $char !== '' ? $char : $m[0];
				}

				return html_entity_decode( '&#x' . $m[1] . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			},
			$html
		) ?? $html;
	}
}

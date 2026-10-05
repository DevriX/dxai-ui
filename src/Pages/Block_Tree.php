<?php
/**
 * Editing parsed blocks in place without changing how they validate.
 *
 * A page built from the imported design copies the design's own blocks and
 * changes only what they say: the text inside an element, a link's href, an
 * image's src and alt, how many copies of a repeated item there are. Nothing
 * here adds a class, a style or an attribute the design did not have, so the
 * copies render with the design's CSS and open in the editor as valid blocks.
 *
 * Text is replaced at the string level, inside the element's own markup: the
 * block's opening tag, its classes and any decorative children it has (an
 * aria-hidden icon or check mark, the +/− of a disclosure) are kept byte for
 * byte, and only the content between them changes.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

final class Block_Tree {

	/** Elements that never have a closing tag. */
	private const VOID = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' );

	/**
	 * The block at a path of inner-block indexes below $root.
	 *
	 * @param array<string, mixed> $root
	 * @param array<int, int>      $path
	 * @return array<string, mixed>
	 */
	public static function &at( array &$root, array $path ): array {
		$node = &$root;
		foreach ( $path as $i ) {
			if ( ! isset( $node['innerBlocks'][ $i ] ) ) {
				throw new \RuntimeException( 'No block at ' . implode( '.', $path ) );
			}
			$node = &$node['innerBlocks'][ $i ];
		}

		return $node;
	}

	/**
	 * The text a block shows itself (its own markup, not its inner blocks), decoded.
	 *
	 * @param array<string, mixed> $block
	 */
	public static function own_text( array $block ): string {
		$html = '';
		foreach ( (array) ( $block['innerContent'] ?? array() ) as $part ) {
			if ( is_string( $part ) ) {
				$html .= $part;
			}
		}
		$html = preg_replace( '#<(\w+)\b[^>]*\baria-hidden="true"[^>]*>.*?</\1>#s', ' ', $html ) ?? $html;
		// A line break reads as a space, as in Content_Extractor::plain().
		$html = preg_replace( '#<br\s*/?>#i', ' ', $html ) ?? $html;

		return self::clean( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/** Whitespace collapsed and trimmed. */
	public static function clean( string $text ): string {
		return trim( preg_replace( '/[\s\x{00a0}\x{200b}]+/u', ' ', $text ) ?? $text );
	}

	/**
	 * Replace the visible content of a block that has no inner blocks — a
	 * heading, paragraph, list item, summary, caption or a plain link — with
	 * $html (text or inline markup). Decorative aria-hidden children stay.
	 *
	 * @param array<string, mixed> $block
	 */
	public static function set_content( array &$block, string $html ): void {
		if ( ! empty( $block['innerBlocks'] ) ) {
			throw new \RuntimeException( 'set_content: ' . (string) $block['blockName'] . ' has inner blocks' );
		}
		$markup = trim( (string) $block['innerHTML'] );
		$new    = self::replace_in_element( $markup, $html );
		if ( $new === null ) {
			throw new \RuntimeException( 'set_content: ' . (string) $block['blockName'] . ' is not a single element' );
		}
		$lead = substr( (string) $block['innerHTML'], 0, strlen( (string) $block['innerHTML'] ) - strlen( ltrim( (string) $block['innerHTML'] ) ) );
		$tail = substr( (string) $block['innerHTML'], strlen( rtrim( (string) $block['innerHTML'] ) ) );
		$block['innerHTML']    = $lead . $new . $tail;
		$block['innerContent'] = array( $block['innerHTML'] );
		if ( ( $block['blockName'] ?? '' ) === 'dxai-ui/link' && array_key_exists( 'text', (array) $block['attrs'] ) ) {
			$block['attrs']['text'] = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
	}

	/**
	 * The element string with its content replaced, or null when $markup is
	 * not one element.
	 */
	private static function replace_in_element( string $markup, string $html ): ?string {
		if ( preg_match( '#^<([a-zA-Z][\w-]*)\b[^>]*>#', $markup, $open ) !== 1 ) {
			return null;
		}
		$tag   = strtolower( $open[1] );
		$close = '</' . $open[1] . '>';
		if ( in_array( $tag, self::VOID, true ) || ! str_ends_with( strtolower( $markup ), strtolower( $close ) ) ) {
			return null;
		}
		$inner = substr( $markup, strlen( $open[0] ), -strlen( $close ) );
		$nodes = self::split_top( $inner );
		if ( $nodes === null ) {
			return null;
		}

		// One styled wrapper element around all the content (li > p): replace inside it.
		$content = array_values( array_filter( $nodes, static fn( $n ) => ! $n['hidden'] && ! ( $n['type'] === 'text' && trim( $n['html'] ) === '' ) ) );
		if ( count( $content ) === 1 && $content[0]['type'] === 'el' && ! in_array( $content[0]['tag'], array( 'a', 'strong', 'b', 'em', 'i', 'span', 'br', 'sup', 'sub', 'small', 'mark', 'u' ), true ) ) {
			$inner_new = self::replace_in_element( $content[0]['html'], $html );
			if ( $inner_new !== null ) {
				return $open[0] . str_replace( $content[0]['html'], $inner_new, $inner ) . $close;
			}
		}

		// Keep the decorative (aria-hidden) nodes where they are; the content goes where the first content node was.
		$out    = '';
		$placed = false;
		foreach ( $nodes as $i => $n ) {
			if ( $n['hidden'] ) {
				$out .= $n['html'];
				continue;
			}
			if ( ! $placed ) {
				$lead = $n['type'] === 'text' ? ( preg_match( '/^\s+/', $n['html'], $m ) ? $m[0] : '' ) : '';
				// A leading icon followed by a space keeps the space.
				if ( $lead === '' && $i > 0 && $n['type'] === 'text' && preg_match( '/^\s/', $n['html'] ) ) {
					$lead = ' ';
				}
				$out   .= $lead . $html;
				$placed = true;
			}
		}
		if ( ! $placed ) {
			$out .= $html;
		}

		return $open[0] . $out . $close;
	}

	/**
	 * The top-level nodes of an HTML fragment: text runs and balanced
	 * elements, each marked hidden when it is an aria-hidden element.
	 *
	 * @return array<int, array{type:string, html:string, tag:string, hidden:bool}>|null
	 */
	private static function split_top( string $html ): ?array {
		$nodes = array();
		$len   = strlen( $html );
		$pos   = 0;
		while ( $pos < $len ) {
			$lt = strpos( $html, '<', $pos );
			if ( $lt === false ) {
				$nodes[] = array( 'type' => 'text', 'html' => substr( $html, $pos ), 'tag' => '', 'hidden' => false );
				break;
			}
			if ( $lt > $pos ) {
				$nodes[] = array( 'type' => 'text', 'html' => substr( $html, $pos, $lt - $pos ), 'tag' => '', 'hidden' => false );
			}
			if ( preg_match( '#\G<([a-zA-Z][\w-]*)\b[^>]*?(/?)>#A', $html, $m, 0, $lt ) !== 1 ) {
				if ( preg_match( '#\G<!--.*?-->#sA', $html, $c, 0, $lt ) === 1 ) {
					$nodes[] = array( 'type' => 'text', 'html' => $c[0], 'tag' => '', 'hidden' => true );
					$pos     = $lt + strlen( $c[0] );
					continue;
				}
				return null;
			}
			$tag = strtolower( $m[1] );
			$end = self::element_end( $html, $lt, $tag, $m[2] === '/' );
			if ( $end === null ) {
				return null;
			}
			$el      = substr( $html, $lt, $end - $lt );
			$nodes[] = array(
				'type'   => 'el',
				'html'   => $el,
				'tag'    => $tag,
				'hidden' => (bool) preg_match( '#^<[^>]*\baria-hidden="true"#', $el ),
			);
			$pos     = $end;
		}

		return $nodes;
	}

	/** Offset just after the element that opens at $start, or null when unbalanced. */
	private static function element_end( string $html, int $start, string $tag, bool $self_closed ): ?int {
		$gt = strpos( $html, '>', $start );
		if ( $gt === false ) {
			return null;
		}
		if ( $self_closed || in_array( $tag, self::VOID, true ) ) {
			return $gt + 1;
		}
		$depth = 1;
		$pos   = $gt + 1;
		while ( $depth > 0 && preg_match( '#<(/?)([a-zA-Z][\w-]*)\b[^>]*?(/?)>#', $html, $m, PREG_OFFSET_CAPTURE, $pos ) === 1 ) {
			$name = strtolower( $m[2][0] );
			$pos  = $m[0][1] + strlen( $m[0][0] );
			if ( $name !== $tag ) {
				continue;
			}
			if ( $m[1][0] === '/' ) {
				--$depth;
			} elseif ( $m[3][0] !== '/' ) {
				++$depth;
			}
		}

		return $depth === 0 ? $pos : null;
	}

	/**
	 * Point a link block at $url, and when it has no inner blocks, give it $text.
	 *
	 * @param array<string, mixed> $block dxai-ui/link or core/button.
	 */
	public static function set_link( array &$block, string $url, ?string $text = null ): void {
		$name = (string) ( $block['blockName'] ?? '' );
		$old  = (string) ( $block['attrs']['url'] ?? '' );
		if ( $old === '' && preg_match( '#<a\b[^>]*\bhref="([^"]*)"#', (string) $block['innerHTML'], $m ) === 1 ) {
			$old = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		$from = 'href="' . esc_attr( $old ) . '"';
		$to   = 'href="' . esc_attr( $url ) . '"';
		$swap = static function ( string $s ) use ( $from, $to ): string {
			$at = strpos( $s, $from );
			return $at === false ? $s : substr_replace( $s, $to, $at, strlen( $from ) );
		};
		$block['innerHTML'] = $swap( (string) $block['innerHTML'] );
		foreach ( $block['innerContent'] as $k => $part ) {
			if ( is_string( $part ) ) {
				$block['innerContent'][ $k ] = $swap( $part );
				break; // the opening <a> is in the first string
			}
		}
		if ( $name === 'dxai-ui/link' || array_key_exists( 'url', (array) $block['attrs'] ) ) {
			$block['attrs']['url'] = $url;
		}
		if ( $text !== null && empty( $block['innerBlocks'] ) ) {
			if ( $name === 'core/button' ) {
				// The words of a button are in the link inside its wrapper: the wrapper keeps the link.
				$put = static fn( string $s ): string => (string) preg_replace_callback( '#(<a\b[^>]*>)(.*?)(</a>)#s', static fn( $m ) => $m[1] . esc_html( $text ) . $m[3], $s, 1 );
				$block['innerHTML'] = $put( (string) $block['innerHTML'] );
				foreach ( $block['innerContent'] as $k => $part ) {
					if ( is_string( $part ) ) {
						$block['innerContent'][ $k ] = $put( $part );
						break;
					}
				}
			} else {
				self::set_content( $block, esc_html( $text ) );
			}
		}
	}

	/** The attachment an image block shows: core's `id`, DX Picture's `imageId`, or 0. @param array<string, mixed> $block */
	public static function image_id( array $block ): int {
		return (int) ( $block['attrs']['id'] ?? $block['attrs']['imageId'] ?? 0 );
	}

	/**
	 * Show another image in an image block: its url/src and alt, in the attributes and the markup.
	 *
	 * @param array<string, mixed> $block dxai-ui/image, core/image, the theme's dx/picture or a dxai-ui/box rendering an <img>.
	 */
	public static function set_image( array &$block, string $url, string $alt, int $id = 0 ): void {
		// DX Picture is printed by the server from its attachment: the picture is another attachment, with its own size.
		if ( ( $block['blockName'] ?? '' ) === 'dx/picture' ) {
			if ( $id > 0 ) {
				$block['attrs']['imageId']  = $id;
				$block['attrs']['imageUrl'] = $url;
				$meta                       = wp_get_attachment_metadata( $id );
				if ( is_array( $meta ) && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
					$block['attrs']['imageWidth']  = (int) $meta['width'];
					$block['attrs']['imageHeight'] = (int) $meta['height'];
				}
				// A phone's own picture was for the old one.
				unset( $block['attrs']['mobileImageId'], $block['attrs']['mobileImageUrl'], $block['attrs']['mobileImageWidth'], $block['attrs']['mobileImageHeight'] );
			}
			if ( $alt !== '' ) {
				$block['attrs']['imageAlt'] = $alt;
			} else {
				unset( $block['attrs']['imageAlt'] );
			}

			return;
		}
		$attrs   = (array) $block['attrs'];
		$old_src = (string) ( $attrs['url'] ?? $attrs['src'] ?? '' );
		if ( $old_src === '' && preg_match( '#<img\b[^>]*\bsrc="([^"]*)"#', (string) $block['innerHTML'], $m ) === 1 ) {
			$old_src = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		$html = (string) $block['innerHTML'];
		$html = str_replace( 'src="' . esc_attr( $old_src ) . '"', 'src="' . esc_attr( $url ) . '"', $html );
		// The design picture's other sizes (srcset) and its pixel size would still show or shape the old picture.
		$html = (string) preg_replace_callback(
			'#<img\b[^>]*>#i',
			static fn( $m ) => (string) preg_replace( '#\s(srcset|sizes|width|height)="[^"]*"#i', '', $m[0] ),
			$html,
			1
		);
		unset( $block['attrs']['srcset'], $block['attrs']['sizes'], $block['attrs']['width'], $block['attrs']['height'] );
		// A decorative picture (aria-hidden) keeps its empty alt, as the design has it.
		$decor = (bool) preg_match( '#<img\b[^>]*\baria-hidden="true"#', $html );
		if ( $decor ) {
			$alt = (string) ( $attrs['alt'] ?? '' );
		}
		if ( ! $decor && preg_match( '#<img\b[^>]*\balt="[^"]*"#', $html ) === 1 ) {
			$html = preg_replace( '#(<img\b[^>]*\balt=")[^"]*(")#', '${1}' . str_replace( '$', '\$', esc_attr( $alt ) ) . '${2}', $html, 1 ) ?? $html;
		}
		// core/image carries the attachment id in a wp-image-N class.
		if ( ( $block['blockName'] ?? '' ) === 'core/image' && $id > 0 ) {
			$html = preg_replace( '/\bwp-image-\d+\b/', 'wp-image-' . $id, $html ) ?? $html;
			$block['attrs']['id'] = $id;
		}
		$block['innerHTML']    = $html;
		$block['innerContent'] = array( $html );
		if ( array_key_exists( 'url', $attrs ) ) {
			$block['attrs']['url'] = $url;
		}
		if ( array_key_exists( 'src', $attrs ) ) {
			$block['attrs']['src'] = $url;
		}
		if ( ! $decor && ( array_key_exists( 'alt', $attrs ) || in_array( (string) ( $block['blockName'] ?? '' ), array( 'dxai-ui/image', 'dxai-ui/box' ), true ) ) ) {
			$block['attrs']['alt'] = $alt;
		}
	}

	/**
	 * What a block's own look is made of: its CSS (dxaiCss) and the declarations of its utility classes.
	 *
	 * An import on a site with the DevriX theme writes much of a block's CSS as the theme's utility classes
	 * (`absolute inset-0 w-full h-full object-fit-cover`) and keeps only the rest in dxaiCss, so a question like
	 * "is this picture laid over its section?" has to read both. Lower-case, `!important` dropped, one space at
	 * most between words and none around ":" and ";" — so `str_contains( $css, 'position:absolute' )` holds
	 * whichever way the design wrote it.
	 *
	 * @param array<string, mixed> $block
	 */
	public static function css_of( array $block ): string {
		$css   = (string) ( $block['attrs']['dxaiCss'] ?? '' );
		$names = preg_split( '/\s+/', (string) ( $block['attrs']['className'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		foreach ( $names as $name ) {
			if ( \DXAI_UI\Compiler\Utility_Classes::is_utility( $name ) ) {
				$css .= ';' . \DXAI_UI\Compiler\Utility_Classes::base_declarations( $name );
			}
		}
		$css = strtolower( (string) preg_replace( '/\s*!important/i', '', $css ) );
		$css = (string) preg_replace( '/\s+/', ' ', $css );

		return trim( (string) preg_replace( '/\s*([:;])\s*/', '$1', $css ), '; ' );
	}

	/**
	 * Give a block other CSS of its own (dxaiCss), and its element the dxs- class of that CSS, as the editor
	 * names it (Style_Hoister::css_class()), so the block stays valid and Style_Rules writes its rule.
	 *
	 * @param array<string, mixed> $block
	 */
	public static function set_css( array &$block, string $css ): void {
		$old       = (string) ( $block['attrs']['dxaiCss'] ?? '' );
		$old_class = $old !== '' ? \DXAI_UI\Compiler\Style_Hoister::css_class( $old ) : '';
		$new_class = \DXAI_UI\Compiler\Style_Hoister::css_class( $css );
		$swap      = static function ( $part ) use ( $old_class, $new_class ) {
			if ( ! is_string( $part ) || $part === '' ) {
				return $part;
			}
			if ( $old_class !== '' && str_contains( $part, $old_class ) ) {
				return (string) preg_replace( '/\b' . preg_quote( $old_class, '/' ) . '\b/', $new_class, $part, 1 );
			}
			// No class of its own yet: on the first element, beside its other classes.
			if ( preg_match( '/^(\s*<[a-z][a-z0-9-]*\b[^>]*?\bclass=")([^"]*)"/i', $part ) === 1 ) {
				return (string) preg_replace( '/^(\s*<[a-z][a-z0-9-]*\b[^>]*?\bclass=")([^"]*)"/i', '${1}${2} ' . $new_class . '"', $part, 1 );
			}

			return (string) preg_replace( '/^(\s*<[a-z][a-z0-9-]*)\b/i', '${1} class="' . $new_class . '"', $part, 1 );
		};
		$done = false;
		foreach ( $block['innerContent'] as $k => $part ) {
			if ( ! $done && is_string( $part ) && trim( $part ) !== '' ) {
				$block['innerContent'][ $k ] = $swap( $part );
				$done                        = true;
			}
		}
		$block['innerHTML']        = $swap( (string) $block['innerHTML'] );
		$block['attrs']['dxaiCss'] = $css;
	}

	/**
	 * A CSS declaration list with one property set: its value replaced, or added at the end — after any
	 * shorthand that also sets it (margin before margin-bottom), so it wins.
	 */
	public static function with_css( string $css, string $prop, string $value ): string {
		$out  = array();
		$done = false;
		foreach ( array_filter( array_map( 'trim', explode( ';', $css ) ), 'strlen' ) as $decl ) {
			$name = strtolower( trim( (string) strstr( $decl, ':', true ) ) );
			if ( $name === $prop ) {
				if ( ! $done ) {
					$out[] = $prop . ':' . $value;
					$done  = true;
				}
				continue;
			}
			$out[] = $decl;
		}
		if ( ! $done ) {
			$out[] = $prop . ':' . $value;
		}

		return implode( ';', $out );
	}

	/**
	 * Remove inner block $idx of $parent, and its slot in innerContent.
	 *
	 * @param array<string, mixed> $parent
	 */
	public static function drop( array &$parent, int $idx ): void {
		if ( ! isset( $parent['innerBlocks'][ $idx ] ) ) {
			return;
		}
		array_splice( $parent['innerBlocks'], $idx, 1 );
		$seen = -1;
		foreach ( $parent['innerContent'] as $k => $part ) {
			if ( $part === null && ++$seen === $idx ) {
				array_splice( $parent['innerContent'], $k, 1 );
				break;
			}
		}
	}

	/**
	 * Remove the block at $path, and the containers it leaves with nothing in them.
	 *
	 * @param array<string, mixed> $block
	 * @param array<int, int>      $path
	 */
	public static function remove( array &$block, array $path ): void {
		while ( $path !== array() ) {
			$idx    = (int) array_pop( $path );
			$parent = &self::at( $block, $path );
			self::drop( $parent, $idx );
			$left = ! empty( $parent['innerBlocks'] );
			unset( $parent );
			if ( $left ) {
				return;
			}
		}
	}

	/**
	 * Insert $block as inner block $idx of $parent (a copy of a sibling, usually).
	 *
	 * @param array<string, mixed> $parent
	 * @param array<string, mixed> $block
	 */
	public static function insert( array &$parent, int $idx, array $block ): void {
		$count = count( (array) $parent['innerBlocks'] );
		$idx   = max( 0, min( $idx, $count ) );
		array_splice( $parent['innerBlocks'], $idx, 0, array( $block ) );
		// The new slot goes after the null of the block before it (or before the first null).
		$seen   = -1;
		$target = null;
		$sep    = '';
		foreach ( $parent['innerContent'] as $k => $part ) {
			if ( $part === null ) {
				++$seen;
				if ( $seen === $idx - 1 ) {
					$target = $k + 1;
					$next   = $parent['innerContent'][ $k + 1 ] ?? null;
					$sep    = is_string( $next ) && trim( $next ) === '' ? $next : '';
					break;
				}
				if ( $idx === 0 ) {
					$target = $k;
					break;
				}
			}
		}
		if ( $target === null ) {
			// No inner blocks yet: before the closing markup.
			$target = max( 0, count( $parent['innerContent'] ) - 1 );
		}
		array_splice( $parent['innerContent'], $target, 0, $idx === 0 ? array( null, '' ) : array( $sep, null ) );
	}

	/**
	 * Drop the anchor (id) of every block below $block, for a second copy of a section on one page.
	 *
	 * @param array<string, mixed> $block
	 */
	public static function strip_anchors( array &$block ): void {
		if ( isset( $block['attrs']['anchor'] ) ) {
			$id                    = (string) $block['attrs']['anchor'];
			$strip                 = static fn( $s ) => is_string( $s ) ? preg_replace( '#\sid="' . preg_quote( esc_attr( $id ), '#' ) . '"#', '', $s, 1 ) : $s;
			$block['innerHTML']    = $strip( (string) $block['innerHTML'] );
			$block['innerContent'] = array_map( $strip, (array) $block['innerContent'] );
			unset( $block['attrs']['anchor'] );
		}
		foreach ( $block['innerBlocks'] as &$inner ) {
			self::strip_anchors( $inner );
		}
		unset( $inner );
	}

	/**
	 * Serialize blocks and check WordPress parses them back to the same markup.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return string|\WP_Error
	 */
	public static function serialize_checked( array $blocks ) {
		$markup = '';
		foreach ( $blocks as $block ) {
			$markup .= serialize_block( $block ) . "\n\n";
		}
		$again = '';
		foreach ( parse_blocks( $markup ) as $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				$again .= serialize_block( $block ) . "\n\n";
			}
		}
		if ( $again !== $markup ) {
			return new \WP_Error( 'dxai_ui_compose_roundtrip', __( 'The composed page does not parse back to the same blocks.', 'dxai-ui' ) );
		}

		return $markup;
	}
}

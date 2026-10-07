<?php
/**
 * Small, exact edits to the HTML a header block saved.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Chrome;

/**
 * The header's blocks are static: what renders is the HTML they saved, so a
 * menu item's label and link are written into that HTML, not into block
 * attributes. Every edit here touches only what it has to — an attribute
 * through WP_HTML_Tag_Processor, which leaves every other byte alone, and a
 * label by replacing the text between an element's tags — so an item whose
 * label and link are the design's comes out byte for byte as it went in.
 * That is what lets the header block render DOM-identically to the design
 * while the menus still hold the design's items.
 *
 * The markup these functions see is the plugin's own save() output: one
 * element per leaf block, attributes quoted, nothing a browser would have to
 * repair. The scanner below relies on that and on nothing else.
 */
final class Header_Html {

	/** Elements with no end tag. */
	private const VOID = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' );

	/**
	 * A start tag, end tag or comment. Attribute values may hold `>` inside
	 * quotes (an aria-label "a > b" the JS save wrote unescaped), so a value is
	 * matched as a quoted string, never as "anything up to `>`".
	 */
	private const TAG = '~<!--.*?-->|<(/?)([a-zA-Z][a-zA-Z0-9:-]*)((?:\s+[^\s"\'>/=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?)*)\s*(/?)>~s';

	/**
	 * Every tag and comment of $html, in order.
	 *
	 * @return array<int, array{kind:string, name:string, start:int, end:int, self:bool}>
	 */
	public static function tokens( string $html ): array {
		$out = array();
		if ( preg_match_all( self::TAG, $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) === false ) {
			return $out;
		}
		foreach ( $m as $hit ) {
			$raw   = $hit[0][0];
			$start = (int) $hit[0][1];
			if ( str_starts_with( $raw, '<!--' ) ) {
				$out[] = array(
					'kind'  => 'comment',
					'name'  => '',
					'start' => $start,
					'end'   => $start + strlen( $raw ),
					'self'  => true,
				);
				continue;
			}
			$name  = strtolower( (string) $hit[2][0] );
			$out[] = array(
				'kind'  => $hit[1][0] === '/' ? 'close' : 'open',
				'name'  => $name,
				'start' => $start,
				'end'   => $start + strlen( $raw ),
				'self'  => ( $hit[4][0] ?? '' ) === '/' || in_array( $name, self::VOID, true ),
			);
		}

		return $out;
	}

	/**
	 * Where an element sits in $html: its start tag, and its end tag when it
	 * has one inside $html. 'root' is the first element; 'a' the first link.
	 *
	 * @return array{open_start:int, open_end:int, close_start:int, close_end:int, name:string}|null
	 */
	public static function element( string $html, string $which ): ?array {
		$tokens = self::tokens( $html );
		foreach ( $tokens as $i => $token ) {
			if ( $token['kind'] !== 'open' || ( $which !== 'root' && $token['name'] !== $which ) ) {
				continue;
			}
			$range = array(
				'open_start'  => $token['start'],
				'open_end'    => $token['end'],
				'close_start' => -1,
				'close_end'   => -1,
				'name'        => $token['name'],
			);
			if ( $token['self'] ) {
				return $range;
			}
			$depth = 0;
			$count = count( $tokens );
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$t = $tokens[ $j ];
				if ( $t['kind'] === 'open' && ! $t['self'] ) {
					++$depth;
				} elseif ( $t['kind'] === 'close' ) {
					if ( $depth === 0 ) {
						if ( $t['name'] === $token['name'] ) {
							$range['close_start'] = $t['start'];
							$range['close_end']   = $t['end'];
						}
						return $range;
					}
					--$depth;
				}
			}

			return $range;
		}

		return null;
	}

	/**
	 * The HTML between an element's tags, or null when it has no end tag in
	 * $html (a container's opening chunk).
	 */
	public static function inner( string $html, string $which ): ?string {
		$el = self::element( $html, $which );
		if ( null === $el || $el['close_start'] < 0 ) {
			return null;
		}

		return substr( $html, $el['open_end'], $el['close_start'] - $el['open_end'] );
	}

	/**
	 * $html with the content of element $which replaced by $content.
	 */
	public static function replace_inner( string $html, string $which, string $content ): string {
		$el = self::element( $html, $which );
		if ( null === $el || $el['close_start'] < 0 ) {
			return $html;
		}

		return substr( $html, 0, $el['open_end'] ) . $content . substr( $html, $el['close_start'] );
	}

	/**
	 * The top-level nodes of an inline fragment: text runs, elements (with
	 * their whole subtree) and comments.
	 *
	 * An element is a decoration when a reader is not meant to read it as the
	 * label: aria-hidden (a caret, a row of stars), an icon with no text, or
	 * screen-reader text. A decoration is kept around any new label; an
	 * element that is part of the label (a styled run) is not.
	 *
	 * @return array<int, array{type:string, raw:string, text:string, deco:bool, open:string, close:string}>
	 */
	public static function nodes( string $fragment ): array {
		$nodes  = array();
		$tokens = self::tokens( $fragment );
		$pos    = 0;
		$count  = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$t = $tokens[ $i ];
			if ( $t['start'] < $pos ) {
				continue;
			}
			if ( $t['start'] > $pos ) {
				$nodes[] = self::text_node( substr( $fragment, $pos, $t['start'] - $pos ) );
			}
			if ( $t['kind'] === 'comment' || $t['kind'] === 'close' ) {
				// A stray end tag cannot occur in save() output; keep it as it is.
				$nodes[] = array(
					'type'  => 'comment',
					'raw'   => substr( $fragment, $t['start'], $t['end'] - $t['start'] ),
					'text'  => '',
					'deco'  => true,
					'open'  => '',
					'close' => '',
				);
				$pos     = $t['end'];
				continue;
			}
			$el    = self::element( substr( $fragment, $t['start'] ), 'root' );
			$end   = null === $el ? $t['end'] : $t['start'] + ( $el['close_end'] >= 0 ? $el['close_end'] : $el['open_end'] );
			$raw   = substr( $fragment, $t['start'], $end - $t['start'] );
			$open  = substr( $fragment, $t['start'], $t['end'] - $t['start'] );
			$close = null !== $el && $el['close_start'] >= 0 ? substr( $raw, $el['close_start'], $el['close_end'] - $el['close_start'] ) : '';
			$text  = self::text( $raw );
			$attrs = self::attributes( $open );
			// A caret span with no aria-hidden (Arcus Restoration's
			// `<span>▼</span>`) is no more a word than one with it.
			$deco  = 'true' === strtolower( (string) ( $attrs['aria-hidden'] ?? '' ) )
				|| ( $text === '' )
				|| ! preg_match( '/[\p{L}\p{N}]/u', $text )
				|| (bool) preg_match( '/(^|\s)(sr-only|screen-reader-text|visually-hidden)(\s|$)/', (string) ( $attrs['class'] ?? '' ) );

			$nodes[] = array(
				'type'  => 'el',
				'raw'   => $raw,
				'text'  => $text,
				'deco'  => $deco,
				'open'  => $open,
				'close' => $close,
			);
			$pos     = $end;
		}
		if ( $pos < strlen( $fragment ) ) {
			$nodes[] = self::text_node( substr( $fragment, $pos ) );
		}

		return $nodes;
	}

	/**
	 * @return array{type:string, raw:string, text:string, deco:bool, open:string, close:string}
	 */
	private static function text_node( string $raw ): array {
		return array(
			'type'  => 'text',
			'raw'   => $raw,
			'text'  => html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'deco'  => false,
			'open'  => '',
			'close' => '',
		);
	}

	/**
	 * The elements with words in an inline fragment that is a stack of them: a
	 * link holding a title and, beside it, a line that describes it
	 * (`<span>Water Removal</span><span>Burst pipes</span>`), each as its place among
	 * nodes() and its words (less the glyphs it starts or ends with).
	 *
	 * Null unless there are two or more, and no run of bare text carries words
	 * among them: a label with a styled word in it is one label, and stays one
	 * (fill() writes it as it always did).
	 *
	 * @return array<int, array{index:int, text:string, visible:string}>|null
	 */
	public static function parts( string $fragment ): ?array {
		$out = array();
		foreach ( self::nodes( $fragment ) as $i => $node ) {
			if ( $node['type'] === 'text' ) {
				if ( trim( $node['text'] ) !== '' ) {
					return null;
				}
				continue;
			}
			if ( $node['type'] === 'el' && ! $node['deco'] ) {
				$out[] = array(
					'index'   => $i,
					'text'    => self::label_text( $node['raw'] ),
					'visible' => self::visible_text( $node['raw'] ),
				);
			}
		}

		return count( $out ) >= 2 ? $out : null;
	}

	/**
	 * The parts() of a fragment, looking through the one wrapper element that
	 * carries all its words: a link with an icon and then a column holding a
	 * title and a description. Null when there are not two to be found.
	 *
	 * @return array{via:array<int, int>, parts:array<int, array{index:int, text:string, visible:string}>}|null
	 */
	public static function stack( string $fragment ): ?array {
		$flat = self::parts( $fragment );
		if ( null !== $flat ) {
			return array(
				'via'   => array(),
				'parts' => $flat,
			);
		}
		$only = null;
		foreach ( self::nodes( $fragment ) as $i => $node ) {
			if ( $node['type'] === 'text' ) {
				if ( trim( $node['text'] ) !== '' ) {
					return null;
				}
				continue;
			}
			if ( $node['type'] === 'el' && ! $node['deco'] ) {
				if ( null !== $only ) {
					return null;
				}
				$only = $i;
			}
		}
		if ( null === $only ) {
			return null;
		}
		$nodes = self::nodes( $fragment );
		$inner = self::inner( $nodes[ $only ]['raw'], 'root' );
		$sub   = null === $inner ? null : self::stack( $inner );

		return null === $sub
			? null
			: array(
				'via'   => array_merge( array( $only ), $sub['via'] ),
				'parts' => $sub['parts'],
			);
	}

	/**
	 * $fragment with one of its parts() written again: the words between that
	 * element's tags replaced by $content (markup that is already safe), or the
	 * element taken out when $content is null. Everything else stays as it was.
	 * $via: the places among nodes() of the wrapper elements the words are in,
	 * outermost first (stack()); none when they are in $fragment itself.
	 *
	 * @param array<int, int> $via
	 */
	public static function replace_part( string $fragment, int $part, ?string $content, array $via = array() ): string {
		if ( $via !== array() ) {
			// The words are in a wrapper element: write inside it, leave the rest as it is.
			$nodes = self::nodes( $fragment );
			$at    = (int) array_shift( $via );
			$inner = isset( $nodes[ $at ] ) && $nodes[ $at ]['type'] === 'el' ? self::inner( $nodes[ $at ]['raw'], 'root' ) : null;
			if ( null === $inner ) {
				return $fragment;
			}
			$nodes[ $at ]['raw'] = $nodes[ $at ]['open'] . self::replace_part( $inner, $part, $content, $via ) . $nodes[ $at ]['close'];

			return implode( '', array_column( $nodes, 'raw' ) );
		}
		$parts = self::parts( $fragment );
		if ( null === $parts || ! isset( $parts[ $part ] ) ) {
			return $fragment;
		}
		$target = $parts[ $part ]['index'];
		$out    = '';
		foreach ( self::nodes( $fragment ) as $i => $node ) {
			if ( $i !== $target ) {
				$out .= $node['raw'];
			} elseif ( null !== $content ) {
				$out .= $node['open'] . $content . $node['close'];
			}
		}

		return $out;
	}

	/**
	 * The attributes of one start tag, lower-cased names, decoded values.
	 *
	 * @return array<string, string>
	 */
	public static function attributes( string $open_tag ): array {
		$out = array();
		$p   = new \WP_HTML_Tag_Processor( $open_tag );
		if ( ! $p->next_tag() ) {
			return $out;
		}
		foreach ( (array) $p->get_attribute_names_with_prefix( '' ) as $name ) {
			$value = $p->get_attribute( $name );
			// A boolean attribute (`hidden`, `open`) reads back as true.
			$out[ strtolower( (string) $name ) ] = is_string( $value ) ? $value : '';
		}

		return $out;
	}

	/**
	 * What a reader reads in $html: text only, decorations left out.
	 */
	public static function visible_text( string $html ): string {
		$out = '';
		foreach ( self::nodes( $html ) as $node ) {
			if ( $node['type'] === 'text' ) {
				$out .= $node['text'];
			} elseif ( $node['type'] === 'el' && ! $node['deco'] ) {
				$inner = self::inner( $node['raw'], 'root' );
				$out  .= null === $inner ? $node['text'] : self::visible_text( $inner );
			}
		}

		return self::norm( $out );
	}

	/**
	 * A symbol a label may start or end with that is not part of its words:
	 * a caret (▼ ▾), an arrow (→ ↗ ›), an icon emoji (📞), with the
	 * variation selector and joiner emoji use. Non-ASCII only, so `17,000+`
	 * keeps its plus and `Q&A` its ampersand.
	 */
	private const GLYPH = '(?:(?![\x{0000}-\x{007F}])[\p{So}\p{Sm}\p{Sk}\p{Pi}\p{Pf}]|\x{FE0F}|\x{200D})';

	/**
	 * What a menu item's label is in $html: the text a reader reads, less
	 * the glyphs it starts or ends with. Arcus Restoration writes its caret
	 * into the trigger's text ("Services ▼"), H2O Away its phone icon into
	 * the button's ("📞 Call …"): the menu item is "Services", the caret is
	 * the design's and stays where it was when the item is renamed (fill()).
	 */
	public static function label_text( string $html ): string {
		return self::strip_glyphs( self::visible_text( $html ) );
	}

	/**
	 * $text without the glyph runs (GLYPH, with the whitespace around them)
	 * at its start and end.
	 */
	public static function strip_glyphs( string $text ): string {
		$out = preg_replace( '/^(?:' . self::GLYPH . '|[\s\x{00A0}])+|(?:' . self::GLYPH . '|[\s\x{00A0}])+$/u', '', $text );

		return is_string( $out ) ? $out : $text;
	}

	/**
	 * Whether a label starts or ends with a glyph of its own.
	 */
	public static function has_glyphs( string $text ): bool {
		$text = self::norm( $text );

		return $text !== self::strip_glyphs( $text );
	}

	/**
	 * The glyph run (with its whitespace) at the start or the end of a raw
	 * text run as saved: literal characters or character references.
	 */
	private static function glyph_run( string $raw, bool $at_end ): string {
		$unit = '(?:' . self::GLYPH . '|[\s\x{00A0}]|&#?[a-zA-Z0-9]+;)';
		if ( ! preg_match( $at_end ? '/' . $unit . '+$/u' : '/^' . $unit . '+/u', $raw, $m ) ) {
			return '';
		}
		// A character reference counts only when it stands for a glyph or a space.
		$decoded = html_entity_decode( $m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( preg_match( '/^(?:' . self::GLYPH . '|[\s\x{00A0}])*$/u', $decoded ) !== 1 ) {
			return preg_match( $at_end ? '/[\s\x{00A0}]+$/u' : '/^[\s\x{00A0}]+/u', $raw, $ws ) ? $ws[0] : '';
		}

		return $m[0];
	}

	/**
	 * All the text in $html, decorations included, whitespace collapsed.
	 */
	public static function text( string $html ): string {
		return self::norm( $html );
	}

	/**
	 * A label reduced to what two spellings of it share: tags dropped,
	 * entities decoded, whitespace (non-breaking included) collapsed.
	 * `Water Removal &amp; Extraction` as saved by the block editor and
	 * `Water Removal & Extraction` as stored in a menu item compare equal.
	 */
	public static function norm( string $s ): string {
		$s = html_entity_decode( wp_strip_all_tags( $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$s = preg_replace( '/[\s\x{00A0}]+/u', ' ', $s ) ?? $s;

		return trim( $s );
	}

	/**
	 * A menu label as HTML. A label with no markup is text and is escaped. A
	 * label someone wrote markup into — Appearance > Menus accepts it, and
	 * core's walker prints it — keeps the inline elements a label can hold;
	 * anything else is stripped, whoever saved it.
	 */
	public static function label_html( string $title ): string {
		if ( ! str_contains( $title, '<' ) ) {
			return esc_html( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}
		$span = array(
			'class'       => true,
			'aria-hidden' => true,
		);

		return wp_kses(
			$title,
			array(
				'strong' => $span,
				'em'     => $span,
				'b'      => $span,
				'i'      => $span,
				'span'   => $span,
				'small'  => $span,
				'mark'   => $span,
				'code'   => $span,
				'sup'    => $span,
				'sub'    => $span,
				's'      => $span,
				'br'     => array(),
			)
		);
	}

	/**
	 * $fragment with its label replaced by $label_html.
	 *
	 * Decorations before the first piece of label text and after the last
	 * stay where they were — the caret after "Services", the stars before a
	 * rating — with the whitespace that separated them from the text. One
	 * between two runs of label text belonged to the old wording and goes.
	 * A styled run of the old label (an accent-coloured word) is re-applied
	 * where its text still occurs in the new one, so a label that changes
	 * around it keeps its look; a label that no longer holds it loses it.
	 * With $wrap, the label is wrapped in that element (a link a top-bar
	 * text item gained).
	 */
	public static function fill( string $fragment, string $label_html, string $wrap_open = '', string $wrap_close = '' ): string {
		$nodes = self::nodes( $fragment );
		$first = null;
		$last  = null;
		foreach ( $nodes as $i => $node ) {
			$bearing = ( $node['type'] === 'text' && trim( $node['text'] ) !== '' ) || ( $node['type'] === 'el' && ! $node['deco'] );
			if ( $bearing ) {
				$first = $first ?? $i;
				$last  = $i;
			}
		}

		if ( null === $first ) {
			// Nothing but decorations: the label goes after them.
			return $fragment . $wrap_open . $label_html . $wrap_close;
		}

		// The whitespace around the label, and a glyph it starts or ends with
		// in its own text (label_text()): "Services ▼" renamed is "Offers ▼".
		$lead_ws  = '';
		$trail_ws = '';
		if ( $nodes[ $first ]['type'] === 'text' ) {
			$lead_ws = self::glyph_run( $nodes[ $first ]['raw'], false );
		}
		if ( $nodes[ $last ]['type'] === 'text' ) {
			$trail_ws = self::glyph_run( $nodes[ $last ]['raw'], true );
			if ( $first === $last && strlen( $lead_ws ) + strlen( $trail_ws ) > strlen( $nodes[ $last ]['raw'] ) ) {
				$trail_ws = '';
			}
		}

		// Styled runs, re-applied only to a label that is plain text.
		if ( ! str_contains( $label_html, '<' ) ) {
			for ( $i = $first; $i <= $last; $i++ ) {
				$node = $nodes[ $i ];
				if ( $node['type'] !== 'el' || $node['deco'] || $node['close'] === '' ) {
					continue;
				}
				$needle = esc_html( $node['text'] );
				$at     = $needle === '' ? false : strpos( $label_html, $needle );
				if ( false !== $at ) {
					$label_html = substr( $label_html, 0, $at ) . $node['open'] . $needle . $node['close'] . substr( $label_html, $at + strlen( $needle ) );
					break;
				}
			}
		}

		$out = '';
		foreach ( $nodes as $i => $node ) {
			if ( $i < $first || $i > $last ) {
				$out .= $node['raw'];
			} elseif ( $i === $first ) {
				$out .= $lead_ws . $wrap_open . $label_html . $wrap_close . $trail_ws;
			}
		}

		return $out;
	}

	/**
	 * $fragment with every decoration left out: a dropdown's trigger reused
	 * as a plain link has no panel for its caret to point at.
	 */
	public static function strip_decorations( string $fragment ): string {
		$out = '';
		foreach ( self::nodes( $fragment ) as $node ) {
			if ( $node['type'] === 'el' && $node['deco'] ) {
				continue;
			}
			$out .= $node['raw'];
		}
		// A caret written as text goes with the caret elements.
		$glyphs = self::glyph_run( $out, true );
		if ( $glyphs !== '' && trim( $out ) !== trim( $glyphs ) ) {
			$out = substr( $out, 0, -strlen( $glyphs ) );
		}

		return trim( $out ) === '' ? $fragment : rtrim( $out );
	}
}

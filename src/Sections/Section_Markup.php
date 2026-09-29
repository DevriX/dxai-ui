<?php
/**
 * Text-level reading and editing of a block's saved HTML.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Sections;

/**
 * The page builder changes a section's copy without knowing how each block
 * stores it: it finds the ORIGINAL text a person sees and swaps it, leaving
 * every element, class and attribute around it as the Home had it.
 *
 * A block's saved HTML is serialized markup (Gutenberg's or the converter's),
 * so it tokenizes cleanly into tags, comments and text runs. Text inside an
 * `aria-hidden="true"` element, an `<svg>`, `<script>` or `<style>` is
 * decoration — the check mark before a bullet, the `+`/`−` of a FAQ row, the
 * `·` between cities — and is never matched or replaced: replacing "On site
 * within 60 minutes, 24/7" keeps the design's check mark in front of it.
 *
 * Three ways a `find` can match, tried in this order:
 *
 * - whole: the visible text of the whole fragment, when it is one run of
 *   text and inline formatting (a, strong, em, span, br …) with no
 *   decoration inside — the run is replaced, decorations before and after it
 *   stay;
 * - node: one text run;
 * - part: a substring of one text run (only when asked for).
 *
 * Every comparison decodes entities and collapses whitespace; a second,
 * loose pass also folds typographic quotes and dashes, so "Virginia's" finds
 * "Virginia’s". Replacement text is escaped; replacement HTML is limited to
 * inline <strong>, <em>, <b>, <i>, <br> and <a href>.
 */
final class Section_Markup {

	/** Elements with no closing tag. */
	private const VOID = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' );

	/** Elements whose text is never copy. */
	private const DECOR = array( 'svg', 'script', 'style', 'template', 'noscript' );

	/** Elements a whole-text run may span. */
	private const INLINE = array( 'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'data', 'del', 'dfn', 'em', 'i', 'ins', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr' );

	/** Elements that separate words when text is read out of a fragment. */
	private const BREAKS = array( 'address', 'article', 'blockquote', 'br', 'button', 'dd', 'details', 'div', 'dl', 'dt', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'label', 'legend', 'li', 'ol', 'option', 'p', 'section', 'summary', 'td', 'th', 'tr', 'ul' );

	/** What a replacement's HTML may contain. */
	public const ALLOWED_HTML = array(
		'strong' => array(),
		'em'     => array(),
		'b'      => array(),
		'i'      => array(),
		'br'     => array(),
		'a'      => array(
			'href'   => true,
			'target' => true,
			'rel'    => true,
		),
	);

	/**
	 * The fragment as tags, comments and text runs, each text run marked as
	 * decoration or copy, with the index of the element it sits in.
	 *
	 * @return array<int, array{type:string, raw:string, name:string, closing:bool, void:bool, decor:bool, parent:int, anchor:int}>
	 */
	public static function tokens( string $html ): array {
		$out = array();
		if ( $html === '' ) {
			return $out;
		}
		preg_match_all( '/<!--.*?-->|<\/?[a-zA-Z][a-zA-Z0-9:-]*(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/s', $html, $m, PREG_OFFSET_CAPTURE );
		$pos   = 0;
		$stack = array();
		$push  = static function ( array $token ) use ( &$out, &$stack ): void {
			$parent          = $stack === array() ? -1 : (int) end( $stack )['i'];
			$decor           = false;
			$anchor          = -1;
			foreach ( $stack as $open ) {
				if ( $open['decor'] ) {
					$decor = true;
				}
				if ( $open['name'] === 'a' ) {
					$anchor = (int) $open['i'];
				}
			}
			$token['parent'] = $parent;
			$token['anchor'] = $anchor;
			$token['decor']  = $decor || ( $token['decor'] ?? false );
			$out[]           = $token;
		};
		foreach ( $m[0] as $match ) {
			$raw = (string) $match[0];
			$off = (int) $match[1];
			if ( $off > $pos ) {
				$push( self::text_token( substr( $html, $pos, $off - $pos ) ) );
			}
			$pos = $off + strlen( $raw );
			if ( str_starts_with( $raw, '<!--' ) ) {
				$push(
					array(
						'type'    => 'comment',
						'raw'     => $raw,
						'name'    => '',
						'closing' => false,
						'void'    => true,
						'decor'   => true,
					)
				);
				continue;
			}
			preg_match( '/^<(\/?)([a-zA-Z][a-zA-Z0-9:-]*)/', $raw, $t );
			$name    = strtolower( (string) ( $t[2] ?? '' ) );
			$closing = ( $t[1] ?? '' ) === '/';
			if ( $closing ) {
				// Pop to the matching element; a stray closing tag closes nothing.
				for ( $k = count( $stack ) - 1; $k >= 0; $k-- ) {
					if ( $stack[ $k ]['name'] === $name ) {
						$decor = $stack[ $k ]['decor'];
						array_splice( $stack, $k );
						$push(
							array(
								'type'    => 'tag',
								'raw'     => $raw,
								'name'    => $name,
								'closing' => true,
								'void'    => false,
								'decor'   => $decor,
							)
						);
						continue 2;
					}
				}
				$push(
					array(
						'type'    => 'tag',
						'raw'     => $raw,
						'name'    => $name,
						'closing' => true,
						'void'    => false,
						'decor'   => false,
					)
				);
				continue;
			}
			$void   = in_array( $name, self::VOID, true ) || str_ends_with( rtrim( substr( $raw, 0, -1 ) ), '/' );
			$hidden = in_array( $name, self::DECOR, true ) || preg_match( '/\saria-hidden\s*=\s*("true"|\'true\'|true\b)/i', $raw ) === 1;
			$push(
				array(
					'type'    => 'tag',
					'raw'     => $raw,
					'name'    => $name,
					'closing' => false,
					'void'    => $void,
					'decor'   => $hidden,
				)
			);
			if ( ! $void ) {
				$stack[] = array(
					'name'  => $name,
					'decor' => (bool) $out[ count( $out ) - 1 ]['decor'],
					'i'     => count( $out ) - 1,
				);
			}
		}
		if ( $pos < strlen( $html ) ) {
			$push( self::text_token( substr( $html, $pos ) ) );
		}

		return $out;
	}

	/**
	 * @return array{type:string, raw:string, name:string, closing:bool, void:bool, decor:bool}
	 */
	private static function text_token( string $raw ): array {
		return array(
			'type'    => 'text',
			'raw'     => $raw,
			'name'    => '',
			'closing' => false,
			'void'    => true,
			'decor'   => false,
		);
	}

	/** Entities decoded, no-break spaces as spaces, whitespace collapsed. */
	public static function norm( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\u{00A0}", ' ', $text );
		$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;

		return trim( $text );
	}

	/** norm() with typographic quotes, dashes and ellipses folded to ASCII. */
	public static function loose( string $text ): string {
		return strtr(
			self::norm( $text ),
			array(
				'’' => "'",
				'‘' => "'",
				'“' => '"',
				'”' => '"',
				'–' => '-',
				'—' => '-',
				'−' => '-',
				'…' => '...',
			)
		);
	}

	/** The visible copy of a fragment: text runs outside decoration, words kept apart at element breaks. */
	public static function text_of( string $html ): string {
		$buf = '';
		foreach ( self::tokens( $html ) as $token ) {
			if ( $token['decor'] ) {
				continue;
			}
			if ( $token['type'] === 'text' ) {
				$buf .= $token['raw'];
			} elseif ( $token['type'] === 'tag' && in_array( $token['name'], self::BREAKS, true ) ) {
				$buf .= ' ';
			}
		}

		return self::norm( $buf );
	}

	/**
	 * Every copy text run of a fragment, in order.
	 *
	 * @return array<int, string>
	 */
	public static function parts( string $html ): array {
		$out = array();
		foreach ( self::tokens( $html ) as $token ) {
			if ( $token['type'] === 'text' && ! $token['decor'] ) {
				$text = self::norm( $token['raw'] );
				if ( $text !== '' ) {
					$out[] = $text;
				}
			}
		}

		return $out;
	}

	/**
	 * Where $find matches in a fragment, or null.
	 *
	 * @param string $mode 'exact' (whole or one text run) or 'part' (also a substring of one run).
	 * @return array{how:string, from:int, to:int, anchor:int, loose:bool}|null Token indexes of the first and last text run replaced.
	 */
	public static function locate( string $html, string $find, string $mode = 'exact', ?array $tokens = null ): ?array {
		$tokens = $tokens ?? self::tokens( $html );
		foreach ( array( false, true ) as $loose ) {
			$want = $loose ? self::loose( $find ) : self::norm( $find );
			if ( $want === '' ) {
				return null;
			}
			$fold = static fn( string $s ): string => $loose ? self::loose( $s ) : self::norm( $s );

			$whole = self::whole_run( $tokens );
			if ( $whole !== null && $fold( $whole['text'] ) === $want ) {
				return array(
					'how'    => 'whole',
					'from'   => $whole['from'],
					'to'     => $whole['to'],
					'anchor' => $whole['anchor'],
					'loose'  => $loose,
				);
			}
			foreach ( $tokens as $i => $token ) {
				if ( $token['type'] === 'text' && ! $token['decor'] && $fold( $token['raw'] ) === $want ) {
					return array(
						'how'    => 'node',
						'from'   => $i,
						'to'     => $i,
						'anchor' => $token['anchor'],
						'loose'  => $loose,
					);
				}
			}
			if ( $mode === 'part' ) {
				foreach ( $tokens as $i => $token ) {
					if ( $token['type'] === 'text' && ! $token['decor'] && str_contains( $fold( $token['raw'] ), $want ) ) {
						return array(
							'how'    => 'part',
							'from'   => $i,
							'to'     => $i,
							'anchor' => $token['anchor'],
							'loose'  => $loose,
						);
					}
				}
			}
		}

		return null;
	}

	/**
	 * The one run of copy a fragment holds, when it has exactly one: from its
	 * first to its last non-blank text run, with only inline elements and no
	 * decoration between them. Null when the copy is split across blocks of
	 * the design (a step's number, title and text) or broken by decoration.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @return array{from:int, to:int, text:string, anchor:int}|null
	 */
	private static function whole_run( array $tokens ): ?array {
		$first = -1;
		$last  = -1;
		foreach ( $tokens as $i => $token ) {
			if ( $token['type'] === 'text' && ! $token['decor'] && trim( self::norm( $token['raw'] ) ) !== '' ) {
				if ( $first < 0 ) {
					$first = $i;
				}
				$last = $i;
			}
		}
		if ( $first < 0 ) {
			return null;
		}
		$buf    = '';
		$anchor = (int) $tokens[ $first ]['anchor'];
		for ( $i = $first; $i <= $last; $i++ ) {
			$token = $tokens[ $i ];
			if ( $token['decor'] ) {
				return null;
			}
			if ( $token['type'] === 'tag' ) {
				if ( ! in_array( $token['name'], self::INLINE, true ) ) {
					return null;
				}
				$buf .= $token['name'] === 'br' ? ' ' : '';
				continue;
			}
			if ( $token['type'] === 'text' ) {
				$buf .= $token['raw'];
				if ( (int) $token['anchor'] !== $anchor ) {
					$anchor = -1;
				}
			}
		}
		// A run that opens an element it does not close (or the reverse)
		// cannot be swapped without unbalancing the markup.
		$depth = 0;
		for ( $i = $first; $i <= $last; $i++ ) {
			$token = $tokens[ $i ];
			if ( $token['type'] === 'tag' && ! $token['void'] ) {
				$depth += $token['closing'] ? -1 : 1;
				if ( $depth < 0 ) {
					return null;
				}
			}
		}
		if ( $depth !== 0 ) {
			return null;
		}

		return array(
			'from'   => $first,
			'to'     => $last,
			'text'   => $buf,
			'anchor' => $anchor,
		);
	}

	/**
	 * The fragment with the located run replaced by $replacement (HTML,
	 * already escaped or sanitized). The whitespace before the first and
	 * after the last replaced run is kept, as is everything outside them. A
	 * `data-dxai-text-*` state label on the element holding a replaced run
	 * (the runtime swaps a button's text from it) gets the new text too.
	 *
	 * @param array{how:string, from:int, to:int, anchor:int, loose:bool} $at
	 * @param string $plain The replacement as plain text, for state labels.
	 */
	public static function replace( string $html, array $at, string $find, string $replacement, string $plain ): string {
		$tokens = self::tokens( $html );
		$from   = $at['from'];
		$to     = $at['to'];
		if ( ! isset( $tokens[ $from ], $tokens[ $to ] ) ) {
			return $html;
		}
		$old = self::text_of( self::join( array_slice( $tokens, $from, $to - $from + 1 ) ) );
		if ( $at['how'] === 'part' ) {
			$raw     = html_entity_decode( $tokens[ $from ]['raw'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$needle  = $at['loose'] ? self::loose( $find ) : self::norm( $find );
			$hit     = self::find_part( $raw, $needle, (bool) $at['loose'] );
			if ( $hit === null ) {
				return $html;
			}
			$escaped = self::escape_text( substr( $raw, 0, $hit[0] ) ) . $replacement . self::escape_text( substr( $raw, $hit[0] + $hit[1] ) );
			$tokens[ $from ]['raw'] = $escaped;
		} else {
			$lead = preg_match( '/^\s+/u', $tokens[ $from ]['raw'], $lm ) === 1 ? $lm[0] : '';
			$tail = preg_match( '/\s+$/u', $tokens[ $to ]['raw'], $tm ) === 1 ? $tm[0] : '';
			$tokens[ $from ]['raw'] = $lead . $replacement . $tail;
			for ( $i = $from + 1; $i <= $to; $i++ ) {
				$tokens[ $i ]['raw'] = '';
			}
			// State labels on the element that holds the run.
			$parent = (int) $tokens[ $from ]['parent'];
			if ( $parent >= 0 && isset( $tokens[ $parent ] ) && str_contains( $tokens[ $parent ]['raw'], 'data-dxai-text-' ) ) {
				$tokens[ $parent ]['raw'] = self::relabel( $tokens[ $parent ]['raw'], $old, $plain );
			}
		}

		return self::join( $tokens );
	}

	/**
	 * The byte offset and length of $needle in $raw (decoded text), matching
	 * whitespace runs and, when loose, typographic variants.
	 *
	 * @return array{0:int, 1:int}|null
	 */
	private static function find_part( string $raw, string $needle, bool $loose ): ?array {
		$words = preg_split( '/\s+/u', trim( $needle ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		if ( $words === array() ) {
			return null;
		}
		$quote = static function ( string $w ) use ( $loose ): string {
			$q = preg_quote( $w, '/' );
			if ( $loose ) {
				$q = strtr(
					$q,
					array(
						"'" => "['’‘]",
						'"' => '["“”]',
						'\\-' => '[-–—−]',
					)
				);
			}

			return $q;
		};
		$pattern = '/' . implode( '[\s\x{00A0}]+', array_map( $quote, $words ) ) . '/u';
		if ( preg_match( $pattern, $raw, $m, PREG_OFFSET_CAPTURE ) !== 1 ) {
			return null;
		}

		return array( (int) $m[0][1], strlen( (string) $m[0][0] ) );
	}

	/**
	 * An element's `data-dxai-text-*` JSON labels with $old swapped for $new.
	 */
	private static function relabel( string $tag, string $old, string $new ): string {
		return (string) preg_replace_callback(
			'/(\sdata-dxai-text-[a-z0-9_-]+=)(\'[^\']*\'|"[^"]*")/i',
			static function ( array $m ) use ( $old, $new ): string {
				$quote = $m[2][0];
				$json  = html_entity_decode( substr( $m[2], 1, -1 ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$map   = json_decode( $json, true );
				if ( ! is_array( $map ) ) {
					return $m[0];
				}
				$hit = false;
				foreach ( $map as $key => $value ) {
					if ( is_string( $value ) && self::norm( $value ) === self::norm( $old ) ) {
						$map[ $key ] = $new;
						$hit         = true;
					}
				}
				if ( ! $hit ) {
					return $m[0];
				}
				$encoded = (string) wp_json_encode( $map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				$encoded = $quote === "'" ? str_replace( array( '&', "'" ), array( '&amp;', '&#039;' ), $encoded ) : str_replace( array( '&', '"' ), array( '&amp;', '&quot;' ), $encoded );

				return $m[1] . $quote . $encoded . $quote;
			},
			$tag
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $tokens
	 */
	public static function join( array $tokens ): string {
		$out = '';
		foreach ( $tokens as $token ) {
			$out .= (string) $token['raw'];
		}

		return $out;
	}

	/** Plain text as HTML text: `&`, `<` and `>` escaped, quotes left as the editor leaves them. */
	public static function escape_text( string $text ): string {
		return htmlspecialchars( $text, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', true );
	}

	/**
	 * A replacement's HTML reduced to ALLOWED_HTML, `<br />` written the way
	 * the editor writes it.
	 *
	 * @return array{html:string, changed:bool}
	 */
	public static function clean_html( string $html ): array {
		$clean = wp_kses( $html, self::ALLOWED_HTML );
		$clean = (string) preg_replace( '/<br\s*\/?>/i', '<br>', $clean );

		return array(
			'html'    => $clean,
			'changed' => self::norm( (string) preg_replace( '/<br\s*\/?>/i', '<br>', $html ) ) !== self::norm( $clean ),
		);
	}

	/**
	 * The attributes of the first tag of a fragment named $name (or the
	 * first tag at all), as the tag processor reads them.
	 */
	public static function first_tag_attr( string $html, string $attr, string $name = '' ): ?string {
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return null;
		}
		$tags = new \WP_HTML_Tag_Processor( $html );
		$ok   = $name === '' ? $tags->next_tag() : $tags->next_tag( array( 'tag_name' => $name ) );
		if ( ! $ok ) {
			return null;
		}
		$value = $tags->get_attribute( $attr );

		return is_string( $value ) ? $value : ( $value === true ? '' : null );
	}

	/**
	 * Set (or, with null, remove) attributes on the first tag of a fragment
	 * named $name — or on the $nth, counting from 0.
	 *
	 * @param array<string, string|null> $attrs
	 */
	public static function set_tag_attrs( string $html, array $attrs, string $name = '', int $nth = 0 ): string {
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}
		$tags  = new \WP_HTML_Tag_Processor( $html );
		$query = $name === '' ? array() : array( 'tag_name' => $name );
		$seen  = -1;
		while ( $tags->next_tag( $query ) ) {
			if ( $tags->is_tag_closer() ) {
				continue;
			}
			++$seen;
			if ( $seen < $nth ) {
				continue;
			}
			foreach ( $attrs as $key => $value ) {
				if ( $value === null ) {
					$tags->remove_attribute( $key );
				} else {
					$tags->set_attribute( $key, $value );
				}
			}
			break;
		}

		return $tags->get_updated_html();
	}

	/**
	 * Which `<a>` (counting from 0) the token at $index opens, or -1.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 */
	public static function anchor_ordinal( array $tokens, int $index ): int {
		if ( $index < 0 ) {
			return -1;
		}
		$n = -1;
		foreach ( $tokens as $i => $token ) {
			if ( $token['type'] === 'tag' && ! $token['closing'] && $token['name'] === 'a' ) {
				++$n;
				if ( $i === $index ) {
					return $n;
				}
			}
		}

		return -1;
	}

	/**
	 * Runs of repeated inline elements directly inside a fragment's root
	 * element — the cities of a "Top Cities" line, the chips of a tag row —
	 * with the decoration between them: `a · a · a`.
	 *
	 * @return array<int, array{items:array<int, array{from:int, to:int, text:string, href:string}>, seps:array<int, array{from:int, to:int}>, sig:string}>
	 */
	public static function inline_groups( string $html ): array {
		$tokens = self::tokens( $html );
		// The root element: the first open tag; its children sit one level in.
		$root = -1;
		foreach ( $tokens as $i => $token ) {
			if ( $token['type'] === 'tag' && ! $token['closing'] ) {
				$root = $i;
				break;
			}
		}
		if ( $root < 0 ) {
			return array();
		}
		$children = array();
		$n        = count( $tokens );
		for ( $i = $root + 1; $i < $n; $i++ ) {
			$token = $tokens[ $i ];
			if ( (int) $token['parent'] !== $root ) {
				continue;
			}
			if ( $token['type'] === 'tag' && $token['closing'] ) {
				break;
			}
			if ( $token['type'] === 'text' ) {
				$children[] = array(
					'kind' => trim( $token['raw'] ) === '' ? 'space' : 'text',
					'from' => $i,
					'to'   => $i,
				);
				continue;
			}
			if ( $token['type'] !== 'tag' ) {
				continue;
			}
			$end = $i;
			if ( ! $token['void'] ) {
				$depth = 0;
				for ( $j = $i; $j < $n; $j++ ) {
					if ( $tokens[ $j ]['type'] === 'tag' && ! $tokens[ $j ]['void'] ) {
						$depth += $tokens[ $j ]['closing'] ? -1 : 1;
						if ( $depth === 0 ) {
							$end = $j;
							break;
						}
					}
				}
			}
			$children[] = array(
				'kind' => $token['decor'] ? 'sep' : 'el',
				'from' => $i,
				'to'   => $end,
				'sig'  => $token['name'] . '|' . self::class_sig( $token['raw'] ),
			);
			$i = $end;
		}

		$groups = array();
		$run    = array();
		$flush  = static function () use ( &$groups, &$run, $tokens ): void {
			if ( count( $run ) >= 2 ) {
				$items = array();
				$seps  = array();
				foreach ( $run as $k => $child ) {
					$slice   = self::join( array_slice( $tokens, $child['from'], $child['to'] - $child['from'] + 1 ) );
					$items[] = array(
						'from' => $child['from'],
						'to'   => $child['to'],
						'text' => self::text_of( $slice ),
						'href' => (string) ( self::first_tag_attr( $slice, 'href' ) ?? '' ),
					);
					if ( $k > 0 ) {
						// What sits between the previous item and this one.
						$seps[] = array(
							'from' => $run[ $k - 1 ]['to'] + 1,
							'to'   => $child['from'] - 1,
						);
					}
				}
				$groups[] = array(
					'items' => $items,
					'seps'  => $seps,
					'sig'   => (string) $run[0]['sig'],
				);
			}
			$run = array();
		};
		foreach ( $children as $child ) {
			if ( $child['kind'] === 'el' ) {
				if ( $run !== array() && $child['sig'] !== $run[0]['sig'] ) {
					$flush();
				}
				$run[] = $child;
			} elseif ( $child['kind'] === 'text' ) {
				// Copy between the elements: not a list of like items.
				$flush();
			}
			// Decoration and blank space may separate items.
		}
		$flush();

		return $groups;
	}

	/** A class attribute with numbered classes folded (`dxai-sh-38` → `dxai-sh-#`), sorted. */
	public static function class_sig( string $tag ): string {
		if ( preg_match( '/\sclass\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $tag, $m ) !== 1 ) {
			return '';
		}

		return self::fold_classes( (string) ( $m[2] !== '' ? $m[2] : ( $m[3] ?? '' ) ) );
	}

	/** Class names with trailing numbers folded, sorted, joined. */
	public static function fold_classes( string $classes ): string {
		$names = preg_split( '/\s+/', trim( $classes ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		$names = array_map( static fn( string $c ): string => (string) preg_replace( '/-\d+$/', '-#', $c ), $names );
		sort( $names );

		return implode( ' ', array_unique( $names ) );
	}
}

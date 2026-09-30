<?php
/**
 * A stylesheet cut down to the rules a page can use.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Support;

/**
 * A DevriX theme prints its whole stylesheet (1.1 MB) inline on every page, and a page uses about 2% of it; the
 * browser still parses all of it before the first paint. This keeps the rules the page can match and drops the rest.
 *
 * A style rule stays when some selector of it can match: every class and id it requires is on the page — in its
 * markup, or as a word in a script the page loads (a class a script adds is not in the markup) — and every element
 * name it requires is one of the page's. Nothing that needs a state counts against a rule: pseudo-classes and
 * pseudo-elements, attribute selectors and the insides of :is(), :where(), :not() and :has() are not required, so
 * a hover, a focus, an open menu and a checked box keep their rules. A script word that ends in `-` or `_` is a
 * prefix a script builds a class from (`'is-' + state`): every class that starts with it stays. Selectors of the
 * document itself (:root, html, body, *) always stay. Media, supports, layer and container blocks are cut inside;
 * @font-face, @keyframes and every other at-rule stay whole.
 *
 * It only ever removes: what is left is a subset of the sheet, in its order, so what is kept renders as it did.
 * Pure — no WordPress, no I/O — and it answers null for CSS it cannot read to the end, so the caller keeps the
 * original.
 */
final class Css_Trim {

	/** The class, id and element tokens of a page, and the words of its scripts. */
	public static function tokens( string $html, array $scripts = array() ): array {
		$tok = array(
			'classes'  => array(),
			'ids'      => array(),
			'tags'     => array(),
			'words'    => array(),
			'prefixes' => array(),
		);
		if ( preg_match_all( '/\sclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $m ) ) {
			foreach ( array_merge( $m[1], $m[2] ) as $value ) {
				foreach ( preg_split( '/\s+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $name ) {
					$tok['classes'][ $name ] = true;
				}
			}
		}
		if ( preg_match_all( '/\sid\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $m ) ) {
			foreach ( array_merge( $m[1], $m[2] ) as $value ) {
				if ( $value !== '' ) {
					$tok['ids'][ $value ] = true;
				}
			}
		}
		if ( preg_match_all( '/<([a-zA-Z][a-zA-Z0-9-]*)/', $html, $m ) ) {
			foreach ( array_unique( $m[1] ) as $tag ) {
				$tok['tags'][ strtolower( $tag ) ] = true;
			}
		}
		// A class a script toggles by a name kept in an attribute (data-toggle-class="is-open").
		if ( preg_match_all( '/\sdata-[\w-]+\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $m ) ) {
			self::add_words( $tok, implode( ' ', array_merge( $m[1], $m[2] ) ) );
		}
		foreach ( $scripts as $script ) {
			self::add_words( $tok, (string) $script );
		}

		return $tok;
	}

	/**
	 * Every name trim() can be asked about, for one stylesheet: the words in it (element names among them) and the
	 * class and id names it spells, unescaped; and the prefixes such a name starts with (`is-` of `is-open`).
	 *
	 * What trim() keeps depends only on the tokens that are in here: a class, an id or a word that is not in the
	 * stylesheet cannot decide a rule, whatever the page holds. So a page's tokens cut down to this set say all
	 * there is to say about what is kept — a nonce in a script or a random id in the markup is not among them —
	 * and the same set always gives the same result.
	 *
	 * @return array{v: array<string, true>, p: array<string, true>}
	 */
	public static function vocabulary( string $css ): array {
		$names = '(?:\\\\[0-9a-fA-F]{1,6}\s?|\\\\.|[\w\-\x{80}-\x{10FFFF}])+';
		$v     = array();
		$p     = array();
		$n     = strlen( $css );
		$pos   = 0;
		while ( $pos < $n ) {
			// Slices cut after a `}`, so a name is never split, and the matches of a megabyte never sit in memory together.
			$end = min( $n, $pos + 262144 );
			if ( $end < $n ) {
				$cut = strpos( $css, '}', $end );
				$end = $cut === false ? $n : $cut + 1;
			}
			$part = substr( $css, $pos, $end - $pos );
			$pos  = $end;
			if ( preg_match_all( '/[A-Za-z_][\w-]*/', $part, $m ) ) {
				foreach ( array_unique( $m[0] ) as $word ) {
					$v[ $word ]              = true;
					$v[ strtolower( $word ) ] = true;
				}
			}
			if ( preg_match_all( '/[.#](' . $names . ')/u', $part, $m ) ) {
				foreach ( array_unique( $m[1] ) as $name ) {
					$name = self::unescape( $name );
					$v[ $name ] = true;
					for ( $i = 1, $len = strlen( $name ); $i < $len; ++$i ) {
						if ( $name[ $i - 1 ] === '-' || $name[ $i - 1 ] === '_' ) {
							$p[ substr( $name, 0, $i ) ] = true;
						}
					}
				}
			}
		}

		return array( 'v' => $v, 'p' => $p );
	}

	/**
	 * The rules of a stylesheet that name one of $classes, with each selector that names one narrowed to $scope
	 * (`:where(.dxai-ui) .faq-answer:not(.is-open)`): the same rule at the same weight, for the elements inside the scope only. The @keyframes
	 * such a rule animates with come along. A selector that starts at the document (html, body, :root, *) is not a
	 * component's and is left out. Null when the CSS cannot be read to the end.
	 *
	 * @param array<int, string> $classes
	 */
	public static function components( string $css, array $classes, string $scope ): ?string {
		$want = array_fill_keys( array_map( 'strval', $classes ), true );
		$frames = array();
		$kept   = self::pick( $css, $want, $scope, $frames );
		if ( $kept === null ) {
			return null;
		}
		$anim = '';
		if ( preg_match_all( '/animation(?:-name)?\s*:\s*([a-z][\w-]*)/i', $kept, $m ) ) {
			foreach ( array_unique( $m[1] ) as $name ) {
				$anim .= $frames[ $name ] ?? '';
			}
		}

		return $anim . $kept;
	}

	/**
	 * @param array<string, true>   $want
	 * @param array<string, string> $frames
	 */
	private static function pick( string $css, array $want, string $scope, array &$frames ): ?string {
		$out  = '';
		$i    = 0;
		$n    = strlen( $css );
		$name = '(?:\\\\[0-9a-fA-F]{1,6}\s?|\\\\.|[\w\-\x{80}-\x{10FFFF}])+';
		while ( $i < $n ) {
			$i = self::skip( $css, $i, $n );
			if ( $i >= $n ) {
				break;
			}
			if ( $css[ $i ] === '}' ) {
				++$i;
				continue;
			}
			$start = $i;
			$i     = self::prelude_end( $css, $i, $n );
			$p     = trim( substr( $css, $start, $i - $start ) );
			if ( $i >= $n || $css[ $i ] === ';' ) {
				++$i;
				continue;
			}
			$from = $i;
			$i    = self::block_end( $css, $i, $n );
			if ( $i > $n || $css[ $i - 1 ] !== '}' ) {
				return null;
			}
			$block = substr( $css, $from, $i - $from );
			if ( $p === '' ) {
				continue;
			}
			if ( $p[0] === '@' ) {
				if ( preg_match( '/^@(?:-webkit-)?keyframes\s+([\w-]+)/i', $p, $m ) === 1 ) {
					$frames[ $m[1] ] = $p . $block;
				} elseif ( preg_match( '/^@(?:media|supports|layer|container)\b/i', $p ) === 1 ) {
					$inner = self::pick( substr( $block, 1, -1 ), $want, $scope, $frames );
					if ( $inner === null ) {
						return null;
					}
					if ( $inner !== '' ) {
						$out .= $p . '{' . $inner . '}';
					}
				}
				continue;
			}
			$kept = array();
			foreach ( self::split_selectors( $p ) as $selector ) {
				$selector = trim( $selector );
				if ( $selector === '' || preg_match( '/^(?::root|html|body|\*)(?![\w-])/', $selector ) === 1 ) {
					continue;
				}
				if ( preg_match_all( '/\.(' . $name . ')/u', self::strip_states( $selector ), $m ) ) {
					foreach ( $m[1] as $found ) {
						if ( isset( $want[ self::unescape( $found ) ] ) ) {
							$kept[] = $scope . ' ' . $selector;
							break;
						}
					}
				}
			}
			if ( $kept !== array() ) {
				$out .= implode( ',', $kept ) . $block;
			}
		}

		return $out;
	}

	/**
	 * The stylesheet with only the rules $tok can match; null when it cannot be read to the end.
	 *
	 * @param array<string, array<string, true>> $tok  From tokens().
	 * @param array{rules:int,kept:int}|null     $stats Rules looked at and rules kept.
	 */
	public static function trim( string $css, array $tok, ?array &$stats = null ): ?string {
		$stats = array( 'rules' => 0, 'kept' => 0 );

		return self::walk( $css, $tok, $stats );
	}

	/**
	 * @param array<string, array<string, true>> $tok
	 * @param array{rules:int,kept:int}          $stats
	 */
	private static function walk( string $css, array $tok, array &$stats ): ?string {
		$out = '';
		$i   = 0;
		$n   = strlen( $css );
		while ( $i < $n ) {
			$i = self::skip( $css, $i, $n );
			if ( $i >= $n ) {
				break;
			}
			if ( $css[ $i ] === '}' ) {
				++$i;
				continue;
			}
			$start = $i;
			$i     = self::prelude_end( $css, $i, $n );
			$p     = trim( substr( $css, $start, $i - $start ) );
			if ( $i >= $n || $css[ $i ] === ';' ) {
				++$i;
				if ( $p !== '' ) {
					$out .= $p . ';';
				}
				continue;
			}
			$from = $i;
			$i    = self::block_end( $css, $i, $n );
			if ( $i > $n || $css[ $i - 1 ] !== '}' ) {
				return null;
			}
			$block = substr( $css, $from, $i - $from );
			if ( $p !== '' && $p[0] === '@' ) {
				if ( preg_match( '/^@(?:media|supports|layer|container|document)\b/i', $p ) === 1 ) {
					$inner = self::walk( substr( $block, 1, -1 ), $tok, $stats );
					if ( $inner === null ) {
						return null;
					}
					if ( trim( $inner ) !== '' ) {
						$out .= $p . '{' . $inner . '}';
					}
				} else {
					$out .= $p . $block;
				}
				continue;
			}
			++$stats['rules'];
			$kept = array();
			foreach ( self::split_selectors( $p ) as $selector ) {
				$selector = trim( $selector );
				if ( $selector !== '' && ( preg_match( '/^(?::root|html|body|\*)(?![\w-])/', $selector ) === 1 || self::keeps( $selector, $tok ) ) ) {
					$kept[] = $selector;
				}
			}
			if ( $kept !== array() ) {
				++$stats['kept'];
				$out .= implode( ',', $kept ) . $block;
			}
		}

		return $out;
	}

	/** Whether one selector can match a page with these tokens. */
	private static function keeps( string $selector, array $tok ): bool {
		$s = self::strip_states( $selector );
		// A class or an id: a name, with its escapes (`.md\:flex`, `.\31 0`).
		$name = '(?:\\\\[0-9a-fA-F]{1,6}\s?|\\\\.|[\w\-\x{80}-\x{10FFFF}])+';
		if ( preg_match_all( '/([.#])(' . $name . ')/u', $s, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $found ) {
				$word = self::unescape( $found[2] );
				$in   = $found[1] === '.' ? isset( $tok['classes'][ $word ] ) : isset( $tok['ids'][ $word ] );
				if ( ! $in && ! isset( $tok['words'][ $word ] ) && ! self::built_from_prefix( $word, $tok ) ) {
					return false;
				}
			}
		}
		// An element name: not inside a class or an id.
		$bare = (string) preg_replace( '/[.#]' . $name . '/u', ' ', $s );
		if ( preg_match_all( '/(?:^|[\s>+~])([a-zA-Z][a-zA-Z0-9-]*)/', $bare, $m ) ) {
			foreach ( $m[1] as $tag ) {
				$tag = strtolower( $tag );
				if ( ! isset( $tok['tags'][ $tag ] ) && ! isset( $tok['words'][ $tag ] ) && ! in_array( $tag, array( 'html', 'body', 'svg' ), true ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/** A script builds this class from a prefix it holds (`'is-' + state`). */
	private static function built_from_prefix( string $word, array $tok ): bool {
		foreach ( $tok['prefixes'] as $prefix => $_ ) {
			if ( str_starts_with( $word, (string) $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/** The selector without what needs a state: pseudo-classes, pseudo-elements, attribute selectors, the insides of :not() and the like. */
	private static function strip_states( string $s ): string {
		$out = '';
		$i   = 0;
		$n   = strlen( $s );
		while ( $i < $n ) {
			$c = $s[ $i ];
			if ( $c === '\\' ) {
				$out .= substr( $s, $i, 2 );
				$i   += 2;
				continue;
			}
			if ( $c === '[' ) {
				++$i;
				while ( $i < $n && $s[ $i ] !== ']' ) {
					if ( $s[ $i ] === '\\' ) {
						++$i;
					} elseif ( $s[ $i ] === '"' || $s[ $i ] === "'" ) {
						$i = self::string_end( $s, $i, $n ) - 1;
					}
					++$i;
				}
				++$i;
				$out .= ' ';
				continue;
			}
			if ( $c === ':' ) {
				$j = $i;
				while ( $j < $n && $s[ $j ] === ':' ) {
					++$j;
				}
				$k = $j;
				while ( $k < $n && ( ctype_alnum( $s[ $k ] ) || $s[ $k ] === '-' || $s[ $k ] === '_' ) ) {
					++$k;
				}
				$i    = ( $k < $n && $s[ $k ] === '(' ) ? self::paren_end( $s, $k, $n ) : $k;
				$out .= ' ';
				continue;
			}
			$out .= $c;
			++$i;
		}

		return $out;
	}

	/** A name as the class it spells: `md\:flex` → `md:flex`, `\31 0` → `10`. */
	private static function unescape( string $name ): string {
		if ( ! str_contains( $name, '\\' ) ) {
			return $name;
		}
		$name = (string) preg_replace_callback(
			'/\\\\([0-9a-fA-F]{1,6})\s?/',
			static function ( array $m ): string {
				$code = (int) hexdec( $m[1] );

				return $code < 1 || $code > 0x10FFFF ? "\u{FFFD}" : html_entity_decode( '&#' . $code . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			},
			$name
		);

		return (string) preg_replace( '/\\\\(.)/su', '$1', $name );
	}

	/** @return array<int, string> */
	private static function split_selectors( string $p ): array {
		$out   = array();
		$depth = 0;
		$cur   = '';
		$n     = strlen( $p );
		for ( $i = 0; $i < $n; ++$i ) {
			$c = $p[ $i ];
			if ( $c === '\\' ) {
				$cur .= substr( $p, $i, 2 );
				++$i;
				continue;
			}
			if ( $c === '"' || $c === "'" ) {
				$end  = self::string_end( $p, $i, $n );
				$cur .= substr( $p, $i, $end - $i );
				$i    = $end - 1;
				continue;
			}
			if ( $c === '(' || $c === '[' ) {
				++$depth;
			} elseif ( $c === ')' || $c === ']' ) {
				--$depth;
			}
			if ( $c === ',' && $depth === 0 ) {
				$out[] = $cur;
				$cur   = '';
			} else {
				$cur .= $c;
			}
		}
		$out[] = $cur;

		return $out;
	}

	/** @param array<string, array<string, true>> $tok */
	private static function add_words( array &$tok, string $text ): void {
		if ( $text === '' || ! preg_match_all( '/[A-Za-z_][\w-]{1,60}/', $text, $m ) ) {
			return;
		}
		foreach ( array_unique( $m[0] ) as $word ) {
			$tok['words'][ $word ] = true;
			$last                  = $word[ strlen( $word ) - 1 ];
			if ( ( $last === '-' || $last === '_' ) && strlen( $word ) >= 3 ) {
				$tok['prefixes'][ $word ] = true;
			}
		}
	}

	/** Past whitespace and comments. */
	private static function skip( string $css, int $i, int $n ): int {
		while ( $i < $n ) {
			$i += strspn( $css, " \t\r\n\f", $i );
			if ( $i + 1 < $n && $css[ $i ] === '/' && $css[ $i + 1 ] === '*' ) {
				$end = strpos( $css, '*/', $i + 2 );
				$i   = $end === false ? $n : $end + 2;
				continue;
			}
			break;
		}

		return $i;
	}

	/** The `{` or `;` that ends a selector or an at-rule's prelude (outside strings, parentheses and escapes); $n at the end. */
	private static function prelude_end( string $css, int $i, int $n ): int {
		while ( $i < $n ) {
			$i += strcspn( $css, "{;\"'\\(", $i );
			if ( $i >= $n ) {
				break;
			}
			$c = $css[ $i ];
			if ( $c === '{' || $c === ';' ) {
				return $i;
			}
			if ( $c === '\\' ) {
				$i += 2;
			} elseif ( $c === '(' ) {
				$i = self::paren_end( $css, $i, $n );
			} else {
				$i = self::string_end( $css, $i, $n );
			}
		}

		return $n;
	}

	/** Just past the `}` that closes the block opened at $i; $n + 1 when it never closes. */
	private static function block_end( string $css, int $i, int $n ): int {
		$depth = 0;
		while ( $i < $n ) {
			$i += strcspn( $css, "{}\"'\\(/", $i );
			if ( $i >= $n ) {
				break;
			}
			$c = $css[ $i ];
			if ( $c === '\\' ) {
				$i += 2;
			} elseif ( $c === '"' || $c === "'" ) {
				$i = self::string_end( $css, $i, $n );
			} elseif ( $c === '(' ) {
				$i = self::paren_end( $css, $i, $n );
			} elseif ( $c === '/' ) {
				if ( $i + 1 < $n && $css[ $i + 1 ] === '*' ) {
					$end = strpos( $css, '*/', $i + 2 );
					$i   = $end === false ? $n : $end + 2;
				} else {
					++$i;
				}
			} elseif ( $c === '{' ) {
				++$depth;
				++$i;
			} else {
				--$depth;
				++$i;
				if ( $depth === 0 ) {
					return $i;
				}
			}
		}

		return $n + 1;
	}

	/** Just past the string that opens at $i. */
	private static function string_end( string $css, int $i, int $n ): int {
		$quote = $css[ $i ];
		++$i;
		while ( $i < $n ) {
			$i += strcspn( $css, $quote . '\\', $i );
			if ( $i >= $n ) {
				return $n;
			}
			if ( $css[ $i ] === '\\' ) {
				$i += 2;
				continue;
			}

			return $i + 1;
		}

		return $n;
	}

	/** Just past the `)` that closes the `(` at $i. */
	private static function paren_end( string $css, int $i, int $n ): int {
		$depth = 0;
		while ( $i < $n ) {
			$i += strcspn( $css, "()\"'\\", $i );
			if ( $i >= $n ) {
				break;
			}
			$c = $css[ $i ];
			if ( $c === '\\' ) {
				$i += 2;
			} elseif ( $c === '"' || $c === "'" ) {
				$i = self::string_end( $css, $i, $n );
			} elseif ( $c === '(' ) {
				++$depth;
				++$i;
			} else {
				--$depth;
				++$i;
				if ( $depth === 0 ) {
					return $i;
				}
			}
		}

		return $n;
	}
}

<?php
/**
 * A stylesheet that stops at the design's sections.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Support;

/**
 * Rewrites a stylesheet so none of its rules reaches inside `.dxai-ui`: every
 * selector gets `:not(:where(.dxai-ui *))` on its subject. Nothing else
 * changes — `:where()` has no specificity, so the sheet's rules keep their
 * weight against each other and against every other sheet, and an element
 * outside a design section matches exactly what it matched before.
 *
 * What it is for (Theme_Fence): an ordinary page of a classic theme that holds
 * sections copied from a design page. The theme's stylesheet has to stay — it
 * draws that page's header, footer and the rest of the content — but on the
 * design's own pages the theme's CSS is not there at all, and inside the
 * copied sections it restyled the design: american-restoration's
 * `h2{line-height:1.2 !important}`, its heading font, its link weight and the
 * block gap of its theme.json. With the fence the sections render under the
 * same CSS as on the design page, and the theme keeps everything around them.
 *
 * The element carrying `.dxai-ui` itself stays in the theme's reach (it is not
 * inside itself): it is one of the content's blocks, and the theme lays it out
 * in its column like any other. At-rules that hold rules (@media, @supports,
 * @layer, @container) are walked; the others (@font-face, @keyframes, @page…)
 * are copied as they are, and so is a rule's body — a nested rule inside one
 * (native CSS nesting, which a compiled theme sheet does not use) is not fenced.
 */
final class Css_Fence {

	/** What goes on each selector's subject. */
	public const FENCE = ':not(:where(.dxai-ui *))';

	/** Bumped when the output changes, so cached copies are made again. */
	public const VERSION = '1';

	/** At-rules whose block holds style rules. */
	private const GROUPS = array( 'media', 'supports', 'layer', 'container', 'document', '-moz-document', 'scope', 'starting-style' );

	/**
	 * The sheet with every style rule fenced. $base, when given, is the URL the sheet was loaded from: its relative
	 * url()s and @imports are made absolute against it, for a copy served from elsewhere.
	 */
	public static function fence( string $css, string $base = '' ): string {
		// An unquoted url() is one token: a data: URI in it may hold braces and semicolons.
		if ( preg_match_all( '~/\*.*?(?:\*/|$)|"(?:[^"\\\\]|\\\\.)*"?|\'(?:[^\'\\\\]|\\\\.)*\'?|[{};]|url\(\s*(?!["\'])[^)]*\)|url\(|(?:(?!url\()[^{};"\'/])+|/~is', $css, $m ) === false ) {
			return $css;
		}
		$tokens = $m[0];
		$count  = count( $tokens );
		$out    = '';
		$pre    = '';
		$groups = 0;
		for ( $i = 0; $i < $count; $i++ ) {
			$t = $tokens[ $i ];
			if ( $t === '{' ) {
				$head = trim( $pre );
				$pre  = '';
				if ( $head !== '' && $head[0] === '@' ) {
					$name = strtolower( (string) preg_replace( '/^@([a-z-]+).*$/is', '$1', $head ) );
					if ( in_array( $name, self::GROUPS, true ) ) {
						++$groups;
						$out .= $head . '{';
						continue;
					}
					$out .= $head . '{' . self::block( $tokens, $i );
					continue;
				}
				$out .= self::selectors( $head ) . '{' . self::block( $tokens, $i );
				continue;
			}
			if ( $t === '}' ) {
				// A stray close brace with nothing open is dropped, as a browser drops it.
				$out .= $pre;
				$pre  = '';
				if ( $groups > 0 ) {
					--$groups;
					$out .= '}';
				}
				continue;
			}
			if ( $t === ';' ) {
				// A statement (@import, @charset, `@layer a, b;`); at the top of a group a lone `;` is kept as is.
				$out .= $pre . ';';
				$pre  = '';
				continue;
			}
			if ( str_starts_with( $t, '/*' ) ) {
				// Comments between rules stay where they are; one inside a selector goes with it.
				if ( trim( $pre ) === '' ) {
					$out .= $pre . $t;
					$pre  = '';
				} else {
					$pre .= $t;
				}
				continue;
			}
			$pre .= $t;
		}
		$out .= $pre;
		for ( ; $groups > 0; $groups-- ) {
			$out .= '}';
		}

		return $base !== '' ? self::absolute_urls( $out, $base ) : $out;
	}

	/** The tokens after an open brace up to its matching close brace, braces included; $i ends on the close. */
	private static function block( array $tokens, int &$i ): string {
		$out   = '';
		$depth = 1;
		$count = count( $tokens );
		for ( ++$i; $i < $count; $i++ ) {
			$t = $tokens[ $i ];
			if ( $t === '{' ) {
				++$depth;
			} elseif ( $t === '}' ) {
				--$depth;
				if ( $depth === 0 ) {
					return $out . '}';
				}
			}
			$out .= $t;
		}

		return $out . '}';
	}

	/** A selector list with the fence on each selector's subject. */
	public static function selectors( string $list ): string {
		$out = array();
		foreach ( self::split( $list ) as $sel ) {
			$sel = trim( $sel );
			if ( $sel === '' ) {
				continue;
			}
			$at    = self::pseudo_element_at( $sel );
			$out[] = $at === -1 ? $sel . self::FENCE : substr( $sel, 0, $at ) . self::FENCE . substr( $sel, $at );
		}

		return implode( ',', $out );
	}

	/**
	 * A selector list split at its own commas — not those inside :is(), :not(), an attribute value or a string.
	 *
	 * @return array<int, string>
	 */
	private static function split( string $list ): array {
		$parts = array();
		$buf   = '';
		$depth = 0;
		$quote = '';
		$len   = strlen( $list );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $list[ $i ];
			if ( $quote !== '' ) {
				$buf .= $ch;
				if ( $ch === '\\' && $i + 1 < $len ) {
					$buf .= $list[ ++$i ];
				} elseif ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( $ch === '"' || $ch === "'" ) {
				$quote = $ch;
			} elseif ( $ch === '(' || $ch === '[' ) {
				++$depth;
			} elseif ( ( $ch === ')' || $ch === ']' ) && $depth > 0 ) {
				--$depth;
			} elseif ( $ch === ',' && $depth === 0 ) {
				$parts[] = $buf;
				$buf     = '';
				continue;
			} elseif ( $ch === '\\' && $i + 1 < $len ) {
				$buf .= $ch . $list[ ++$i ];
				continue;
			}
			$buf .= $ch;
		}
		$parts[] = $buf;

		return $parts;
	}

	/**
	 * Where a selector's pseudo-element starts (`::before`, or the old one-colon `:before`, `:after`,
	 * `:first-line`, `:first-letter`), outside parentheses and strings; -1 when it has none. A pseudo-element
	 * ends the selector, so the fence goes in front of it.
	 */
	private static function pseudo_element_at( string $sel ): int {
		$depth = 0;
		$quote = '';
		$len   = strlen( $sel );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $sel[ $i ];
			if ( $quote !== '' ) {
				if ( $ch === '\\' ) {
					++$i;
				} elseif ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( $ch === '\\' ) {
				++$i;
			} elseif ( $ch === '"' || $ch === "'" ) {
				$quote = $ch;
			} elseif ( $ch === '(' || $ch === '[' ) {
				++$depth;
			} elseif ( ( $ch === ')' || $ch === ']' ) && $depth > 0 ) {
				--$depth;
			} elseif ( $ch === ':' && $depth === 0 ) {
				if ( ( $sel[ $i + 1 ] ?? '' ) === ':' ) {
					return $i;
				}
				if ( preg_match( '/\G:(before|after|first-line|first-letter)(?![a-z0-9_-])/i', $sel, $m, 0, $i ) === 1 ) {
					return $i;
				}
			}
		}

		return -1;
	}

	/** Relative url()s and @imports made absolute against the sheet's own URL. */
	private static function absolute_urls( string $css, string $base ): string {
		$dir = (string) preg_replace( '#[?\#].*$#', '', $base );
		$dir = substr( $dir, 0, (int) strrpos( $dir, '/' ) + 1 );
		$fix = static function ( string $url ) use ( $dir ): string {
			return $url === '' || preg_match( '#^(?:[a-z][a-z0-9+.-]*:|/|\#)#i', $url ) === 1 ? $url : $dir . $url;
		};
		$css = (string) preg_replace_callback(
			'/url\(\s*([\'"]?)([^\'")]*)\1\s*\)/i',
			static fn( $m ) => 'url(' . $m[1] . $fix( trim( $m[2] ) ) . $m[1] . ')',
			$css
		);

		return (string) preg_replace_callback(
			'/@import\s+([\'"])([^\'"]+)\1/i',
			static fn( $m ) => '@import ' . $m[1] . $fix( $m[2] ) . $m[1],
			$css
		);
	}
}

<?php
/**
 * A page's stylesheet without the rules that cannot match anything on it.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

/**
 * Every page of a design is compiled against the design's whole stylesheet (Dc_Connector merges every page's and part's
 * style into one), so each page carried every other page's rules too. Gemini Restoration — an Elementor site rebuilt as a
 * Claude Design package — wrote the same 4.4 MB `pattern-{id}.css` for each of its 13 pages: 263 KiB over the wire that
 * blocked the first paint (PageSpeed: render-blocking ~3 s, "unused CSS" 237 KiB).
 *
 * The test is by token, and it keeps rather than drops whenever it cannot tell: a rule stays when, for one of its
 * selectors, every class, id and attribute name it requires is in the page's markup (its content, its header and footer,
 * the state classes the runtime projects, named in data attributes) or is one the page's scripts add (`is-in`, `dxai-…`).
 * What is inside :is()/:where()/:not()/:has() is never required; @keyframes, @font-face and the like stay whole; a rule
 * with no class, id or attribute (html, body, *, a tag) stays.
 *
 * Only on a stylesheet over MIN_BYTES: the designs the converter was built on have far smaller sheets and their classes
 * are compiled per page already (Tailwind_Purger). `dxai_ui_page_css_trim` decides per page.
 */
final class Page_Css_Trim {

	/** A stylesheet smaller than this is left as it is. */
	public const MIN_BYTES = 1000000;

	/** Classes a script adds at run time, never written in the markup. */
	private const RUNTIME = '/^(?:is-|has-|dxai-|wp-|js|no-js|admin-bar|logged-in|customize-|screen-reader|sticky|active|open|opened|show|shown|visible|hidden|current|focus|hover|lazy|loaded|swiper-|e--|e-lazyloaded|animated|dxg-anim)/';

	/** At-rules whose block holds rules to test; every other block at-rule (keyframes, font-face…) stays whole. */
	private const GROUPING = array( 'media', 'supports', 'container', 'layer', 'document', 'scope' );

	/** The page's sheet, trimmed when it is large enough and the filter agrees. */
	public static function apply( string $css, string $source_html, string $page_markup, int $page_id ): string {
		$on = strlen( $css ) > self::MIN_BYTES;
		/**
		 * Whether this page's stylesheet keeps only the rules its markup can use (Page_Css_Trim).
		 *
		 * @param bool $on      Default: when the sheet is over Page_Css_Trim::MIN_BYTES.
		 * @param int  $page_id The page.
		 */
		if ( ! (bool) apply_filters( 'dxai_ui_page_css_trim', $on, $page_id ) ) {
			return $css;
		}

		self::$failed = false;
		$tokens       = self::tokens( self::chrome( $source_html ) . "\n" . $page_markup );
		// A regex that gave up (pcre.backtrack_limit on a huge page) reads as "no classes": the sheet would lose every rule.
		if ( self::$failed || $tokens['class'] === array() ) {
			return $css;
		}
		$trimmed = self::trim( $css, $tokens );

		return trim( $trimmed ) !== '' ? $trimmed : $css;
	}

	/**
	 * What can_match() said of each selector list during one trim() (a list repeats under each @media).
	 *
	 * @var array<string, bool>
	 */
	private static array $memo = array();

	/**
	 * The @font-face bodies one trim() has written.
	 *
	 * @var array<string, true>
	 */
	private static array $faces = array();

	/** Set when a regex of tokens() failed, so apply() keeps the whole sheet. */
	private static bool $failed = false;

	/**
	 * preg_match_all() that records a failure.
	 *
	 * @param array<int, array<int, string>>|null $m
	 */
	private static function all( string $pattern, string $subject, ?array &$m ): bool {
		$n = preg_match_all( $pattern, $subject, $m );
		if ( $n === false ) {
			self::$failed = true;
		}

		return (bool) $n;
	}

	/**
	 * The header and footer of the design's source (every page shows them), else the whole source.
	 */
	public static function chrome( string $source_html ): string {
		$out  = '';
		$open = stripos( $source_html, '<header' );
		if ( $open !== false ) {
			$close = stripos( $source_html, '</header>', $open );
			$out  .= $close !== false ? substr( $source_html, $open, $close - $open + 9 ) : '';
		}
		$open = strripos( $source_html, '<footer' );
		if ( $open !== false ) {
			$close = stripos( $source_html, '</footer>', $open );
			$out  .= $close !== false ? "\n" . substr( $source_html, $open, $close - $open + 9 ) : '';
		}

		return $out !== '' ? $out : $source_html;
	}

	/**
	 * The names a selector can need from this markup: classes, ids, attribute names, and the class-like words in data
	 * attributes (the runtime's projections: data-dxai-on-sub1="dxai-dc-5--sub1-on"). Block markup counts too: a dynamic
	 * block (dxai-ui/box, dxai-ui/link…) writes its classes and attributes from its comment's JSON when it renders, so every
	 * word of every string there is taken as a class or an id, and every key as an attribute name.
	 *
	 * @return array{class: array<string, true>, id: array<string, true>, attr: array<string, true>, chars?: array<int, true>}
	 */
	public static function tokens( string $html ): array {
		$out = array( 'class' => array(), 'id' => array(), 'attr' => array(), 'chars' => array() );
		if ( str_contains( $html, '<!-- wp:' ) && function_exists( 'parse_blocks' ) ) {
			self::block_words( parse_blocks( $html ), $out );
		}
		// JSON-escaped markup (a block attribute holding HTML): \u0022 for the quote, \/ for the slash.
		$decoded = preg_replace_callback( '/\\\\u([0-9a-fA-F]{4})/', static fn( array $m ): string => hexdec( $m[1] ) < 128 ? chr( (int) hexdec( $m[1] ) ) : $m[0], str_replace( '\/', '/', $html ) );
		if ( ! is_string( $decoded ) ) {
			self::$failed = true;

			return $out;
		}
		$html = $decoded;
		// The characters past ASCII the page shows, for the @font-face rules of a subset (unicode-range). Unknown when the
		// text is not UTF-8: -1 keeps every face.
		$text = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( preg_match_all( '/[^\x00-\x7F]/u', $text, $c ) === false ) {
			$out['chars'][-1] = true;
		} else {
			foreach ( array_unique( $c[0] ) as $char ) {
				$out['chars'][ self::code_point( (string) $char ) ] = true;
			}
		}
		if ( self::all( '/\sclass\s*=\s*(["\'])(.*?)\1/is', $html, $m ) ) {
			foreach ( $m[2] as $list ) {
				foreach ( preg_split( '/\s+/', html_entity_decode( (string) $list ) ) ?: array() as $c ) {
					if ( $c !== '' ) {
						$out['class'][ $c ] = true;
					}
				}
			}
		}
		if ( self::all( '/\sid\s*=\s*(["\'])(.*?)\1/is', $html, $m ) ) {
			foreach ( $m[2] as $id ) {
				$out['id'][ trim( html_entity_decode( (string) $id ) ) ] = true;
			}
		}
		if ( self::all( '/\s([a-zA-Z_:][-\w:.]*)\s*=/', $html, $m ) ) {
			foreach ( $m[1] as $name ) {
				$out['attr'][ strtolower( (string) $name ) ] = true;
			}
		}
		if ( self::all( '/\sdata-[\w-]+\s*=\s*(["\'])(.*?)\1/is', $html, $m ) ) {
			foreach ( $m[2] as $value ) {
				foreach ( preg_split( '/[\s,;:|]+/', html_entity_decode( (string) $value ) ) ?: array() as $w ) {
					if ( preg_match( '/^-?[_a-zA-Z][\w-]*$/', $w ) === 1 ) {
						$out['class'][ $w ] = true;
					}
				}
			}
		}

		return $out;
	}

	/**
	 * The attributes of every block in the tree, added to the tokens.
	 *
	 * @param array<int, array<string, mixed>>                                                     $blocks
	 * @param array{class: array<string, true>, id: array<string, true>, attr: array<string, true>, chars?: array<int, true>} $out
	 */
	private static function block_words( array $blocks, array &$out ): void {
		foreach ( $blocks as $block ) {
			if ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				self::words( $block['attrs'], $out );
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::block_words( $block['innerBlocks'], $out );
			}
		}
	}

	/**
	 * The words of a block's attributes, added to the tokens: keys as attribute names, string values word by word as
	 * classes and ids (an attribute's HTML, as in dxai-ui/html, is read for its own class and id attributes too).
	 *
	 * @param array<mixed>                                                                         $attrs
	 * @param array{class: array<string, true>, id: array<string, true>, attr: array<string, true>, chars?: array<int, true>} $out
	 */
	private static function words( array $attrs, array &$out ): void {
		foreach ( $attrs as $key => $value ) {
			if ( is_string( $key ) ) {
				$out['attr'][ strtolower( $key ) ] = true;
			}
			if ( is_array( $value ) ) {
				self::words( $value, $out );
			} elseif ( is_string( $value ) && $value !== '' ) {
				if ( str_contains( $value, '<' ) ) {
					foreach ( self::tokens( $value ) as $kind => $names ) {
						$out[ $kind ] += $names;
					}
				}
				foreach ( preg_split( '/[\s,;|"\'<>=]+/', $value ) ?: array() as $w ) {
					if ( preg_match( '/^-?[_a-zA-Z][\w-]*$/', $w ) === 1 ) {
						$out['class'][ $w ] = true;
						$out['id'][ $w ]    = true;
					}
				}
			}
		}
	}

	/**
	 * The stylesheet with every rule no selector of which can match the tokens taken out.
	 *
	 * @param array{class: array<string, true>, id: array<string, true>, attr: array<string, true>, chars?: array<int, true>} $tokens
	 */
	public static function trim( string $css, array $tokens ): string {
		self::$memo  = array();
		self::$faces = array();
		if ( isset( $tokens['chars'] ) && preg_match_all( '/content\\s*:\\s*([^;}]+)/i', $css, $m ) ) {
			foreach ( $m[1] as $value ) {
				if ( preg_match_all( '/\\\\([0-9a-fA-F]{1,6})/', (string) $value, $e ) ) {
					foreach ( $e[1] as $hex ) {
						$tokens['chars'][ (int) hexdec( (string) $hex ) ] = true;
					}
				}
				if ( preg_match_all( '/[^\x00-\x7F]/u', (string) $value, $e ) ) {
					foreach ( $e[0] as $char ) {
						$tokens['chars'][ self::code_point( (string) $char ) ] = true;
					}
				}
			}
		}
		$out         = self::block( $css, $tokens );
		self::$memo  = array();
		self::$faces = array();

		return $out;
	}

	/**
	 * Every style rule of a sheet with the grouping at-rules it sits in (`@media (max-width:767px)`, outermost first), for a reader
	 * that needs one rule's declarations under its conditions (Lcp_Preload).
	 *
	 * @return array<int, array{at: array<int, string>, selector: string, body: string}>
	 */
	public static function rules( string $css ): array {
		$out = array();
		self::collect( $css, array(), $out );

		return $out;
	}

	/**
	 * @param array<int, string>                                                   $at
	 * @param array<int, array{at: array<int, string>, selector: string, body: string}> $out
	 */
	private static function collect( string $css, array $at, array &$out ): void {
		$len = strlen( $css );
		$i   = 0;
		while ( $i < $len ) {
			if ( $css[ $i ] === '/' && ( $css[ $i + 1 ] ?? '' ) === '*' ) {
				$end = strpos( $css, '*/', $i + 2 );
				$i   = $end === false ? $len : $end + 2;
				continue;
			}
			if ( ctype_space( $css[ $i ] ) ) {
				$i += strspn( $css, " \t\r\n\f\v", $i );
				continue;
			}
			[ $stop, $char ] = self::prelude_end( $css, $i );
			if ( $stop === -1 ) {
				return;
			}
			$prelude = trim( substr( $css, $i, $stop - $i ) );
			if ( $char === ';' ) {
				$i = $stop + 1;
				continue;
			}
			$close = self::matching_brace( $css, $stop );
			if ( $close === -1 ) {
				return;
			}
			$body = substr( $css, $stop + 1, $close - $stop - 1 );
			$i    = $close + 1;
			if ( $prelude !== '' && $prelude[0] === '@' ) {
				$name = strtolower( (string) preg_replace( '/^@([\w-]+).*$/s', '$1', $prelude ) );
				if ( in_array( $name, self::GROUPING, true ) ) {
					self::collect( $body, array_merge( $at, array( $prelude ) ), $out );
				}
				continue;
			}
			if ( ! str_contains( $body, '{' ) ) {
				$out[] = array(
					'at'       => $at,
					'selector' => $prelude,
					'body'     => $body,
				);
			}
		}
	}

	/** One level of statements: rules, grouping at-rules (recursed), other at-rules (kept). */
	private static function block( string $css, array $tokens ): string {
		$out = '';
		$len = strlen( $css );
		$i   = 0;
		while ( $i < $len ) {
			// Comments between statements go.
			if ( $css[ $i ] === '/' && ( $css[ $i + 1 ] ?? '' ) === '*' ) {
				$end = strpos( $css, '*/', $i + 2 );
				$i   = $end === false ? $len : $end + 2;
				continue;
			}
			if ( ctype_space( $css[ $i ] ) ) {
				$i += strspn( $css, " \t\r\n\f\v", $i );
				continue;
			}
			// The prelude: up to the first `{` or `;` outside strings, brackets and parentheses.
			[ $stop, $char ] = self::prelude_end( $css, $i );
			if ( $stop === -1 ) {
				$out .= substr( $css, $i );
				break;
			}
			$prelude = trim( substr( $css, $i, $stop - $i ) );
			if ( $char === ';' ) {
				$out .= $prelude . ';';
				$i    = $stop + 1;
				continue;
			}
			$close = self::matching_brace( $css, $stop );
			if ( $close === -1 ) {
				$out .= substr( $css, $i );
				break;
			}
			$body = substr( $css, $stop + 1, $close - $stop - 1 );
			$i    = $close + 1;
			if ( $prelude !== '' && $prelude[0] === '@' ) {
				$name = strtolower( (string) preg_replace( '/^@([\w-]+).*$/s', '$1', $prelude ) );
				if ( in_array( $name, self::GROUPING, true ) ) {
					$inner = self::block( $body, $tokens );
					if ( trim( $inner ) !== '' ) {
						$out .= $prelude . '{' . $inner . '}';
					}
				} elseif ( $name !== 'font-face' || ( ! isset( self::$faces[ $body ] ) && ( ! isset( $tokens['chars'] ) || self::face_needed( $body, $tokens['chars'] ) ) ) ) {
					if ( $name === 'font-face' ) {
						// The same face twice (two sheets of the design loaded one font CSS) is one face.
						self::$faces[ $body ] = true;
					}
					$out .= $prelude . '{' . $body . '}';
				}
				continue;
			}
			// A rule holding a nested rule (CSS nesting) is kept whole: its children are not tested here.
			if ( str_contains( $body, '{' ) || ( self::$memo[ $prelude ] ??= self::can_match( $prelude, $tokens ) ) ) {
				$out .= $prelude . '{' . $body . '}';
			}
		}

		return $out;
	}

	/**
	 * Where the statement's prelude ends: the index of its `{` or `;` and which, or -1.
	 *
	 * @return array{0: int, 1: string}
	 */
	private static function prelude_end( string $css, int $i ): array {
		$len   = strlen( $css );
		$depth = 0;
		// strcspn() jumps to the next character that matters: a sheet of megabytes is read in a fraction of a second.
		while ( $i < $len ) {
			$i += strcspn( $css, "{;\"'()[]\\", $i );
			if ( $i >= $len ) {
				break;
			}
			$c = $css[ $i ];
			if ( $c === '"' || $c === "'" ) {
				$i = self::after_string( $css, $i );
				continue;
			}
			if ( $c === '\\' ) {
				$i += 2;
				continue;
			}
			if ( $c === '(' || $c === '[' ) {
				++$depth;
			} elseif ( $c === ')' || $c === ']' ) {
				--$depth;
			} elseif ( $depth <= 0 ) {
				return array( $i, $c );
			}
			++$i;
		}

		return array( -1, '' );
	}

	/** The `}` that closes the `{` at $open, skipping strings and comments, or -1. */
	private static function matching_brace( string $css, int $open ): int {
		$len   = strlen( $css );
		$depth = 0;
		$i     = $open;
		while ( $i < $len ) {
			$i += strcspn( $css, "{}\"'/\\", $i );
			if ( $i >= $len ) {
				break;
			}
			$c = $css[ $i ];
			if ( $c === '"' || $c === "'" ) {
				$i = self::after_string( $css, $i );
				continue;
			}
			if ( $c === '\\' ) {
				$i += 2;
				continue;
			}
			if ( $c === '/' ) {
				if ( ( $css[ $i + 1 ] ?? '' ) === '*' ) {
					$end = strpos( $css, '*/', $i + 2 );
					if ( $end === false ) {
						return -1;
					}
					$i = $end + 2;
				} else {
					++$i;
				}
				continue;
			}
			if ( $c === '{' ) {
				++$depth;
			} else {
				--$depth;
				if ( $depth === 0 ) {
					return $i;
				}
			}
			++$i;
		}

		return -1;
	}

	/**
	 * Whether a @font-face is needed: it has no unicode-range, or its range holds ASCII or a character the page shows.
	 * Google Fonts' CSS declares each family once per subset (cyrillic, greek, vietnamese, latin-ext, latin): 830 faces,
	 * 359 KB, in Gemini Restoration's sheet, of which an English page uses the latin ones.
	 *
	 * @param array<int, true> $chars
	 */
	private static function face_needed( string $body, array $chars ): bool {
		if ( isset( $chars[-1] ) || preg_match( '/unicode-range\\s*:\\s*([^;}]+)/i', $body, $m ) !== 1 ) {
			return true;
		}
		foreach ( explode( ',', $m[1] ) as $range ) {
			if ( preg_match( '/^\\s*U\\+([0-9a-fA-F?]{1,6})(?:-([0-9a-fA-F]{1,6}))?\\s*$/i', $range, $r ) !== 1 ) {
				return true;
			}
			$low  = (int) hexdec( str_replace( '?', '0', $r[1] ) );
			$high = isset( $r[2] ) ? (int) hexdec( $r[2] ) : (int) hexdec( str_replace( '?', 'F', $r[1] ) );
			if ( $low <= 0x7E && $high >= 0x20 ) {
				return true;
			}
			foreach ( $chars as $point => $_ ) {
				if ( $point >= $low && $point <= $high ) {
					return true;
				}
			}
		}

		return false;
	}

	/** The code point of one UTF-8 character (no mbstring: it is not on every host). */
	private static function code_point( string $char ): int {
		$bytes = array_values( (array) unpack( 'C*', $char ) );
		$n     = count( $bytes );
		if ( $n === 2 ) {
			return ( ( $bytes[0] & 0x1F ) << 6 ) | ( $bytes[1] & 0x3F );
		}
		if ( $n === 3 ) {
			return ( ( $bytes[0] & 0x0F ) << 12 ) | ( ( $bytes[1] & 0x3F ) << 6 ) | ( $bytes[2] & 0x3F );
		}
		if ( $n === 4 ) {
			return ( ( $bytes[0] & 0x07 ) << 18 ) | ( ( $bytes[1] & 0x3F ) << 12 ) | ( ( $bytes[2] & 0x3F ) << 6 ) | ( $bytes[3] & 0x3F );
		}

		return (int) ( $bytes[0] ?? 0 );
	}

	/** The index after the string whose opening quote is at $i. */
	private static function after_string( string $css, int $i ): int {
		$len   = strlen( $css );
		$quote = $css[ $i ];
		++$i;
		while ( $i < $len ) {
			$i += strcspn( $css, $quote . '\\', $i );
			if ( $i >= $len ) {
				break;
			}
			if ( $css[ $i ] === '\\' ) {
				$i += 2;
				continue;
			}

			return $i + 1;
		}

		return $len;
	}

	/**
	 * Whether one selector of the list can match: every name it requires is in the tokens or added at run time.
	 *
	 * @param array{class: array<string, true>, id: array<string, true>, attr: array<string, true>, chars?: array<int, true>} $tokens
	 */
	public static function can_match( string $selector_list, array $tokens ): bool {
		foreach ( self::split_list( $selector_list ) as $selector ) {
			$plain = self::without_functional_pseudos( $selector, $tokens );
			if ( $plain === null ) {
				continue;
			}
			$ok    = true;
			// Attribute names are required; their values are not names (`[href$=".pdf"]` needs no class `pdf`).
			if ( str_contains( $plain, '[' ) ) {
				if ( preg_match_all( '/\[\s*([a-zA-Z_:][-\w:.]*)/', $plain, $m ) ) {
					foreach ( $m[1] as $name ) {
						if ( ! isset( $tokens['attr'][ strtolower( (string) $name ) ] ) ) {
							$ok = false;
							break;
						}
					}
				}
				$plain = (string) preg_replace( '/\[(?:[^\]"\']|"[^"]*"|\'[^\']*\')*\]/', '', $plain );
			}
			// Pseudo-classes and pseudo-elements say when or what part, not which element; an escaped colon is part of a
			// class name (`.md\:flex`).
			$plain = (string) preg_replace( '/(?<!\\\\)::?[a-zA-Z-]+/', '', $plain );
			if ( $ok && preg_match_all( '/\.((?:[\w-]|\\\\.)+)/', $plain, $m ) ) {
				foreach ( $m[1] as $raw ) {
					$class = (string) preg_replace( '/\\\\(.)/', '$1', $raw );
					if ( ! isset( $tokens['class'][ $class ] ) && preg_match( self::RUNTIME, $class ) !== 1 ) {
						$ok = false;
						break;
					}
				}
			}
			if ( $ok && preg_match_all( '/#((?:[\w-]|\\\\.)+)/', $plain, $m ) ) {
				foreach ( $m[1] as $raw ) {
					if ( ! isset( $tokens['id'][ (string) preg_replace( '/\\\\(.)/', '$1', $raw ) ] ) ) {
						$ok = false;
						break;
					}
				}
			}
			if ( $ok ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The selectors of a list, split on the commas outside parentheses and brackets.
	 *
	 * @return array<int, string>
	 */
	private static function split_list( string $list ): array {
		if ( strpbrk( $list, '([\\' ) === false ) {
			return array_values( array_filter( array_map( 'trim', explode( ',', $list ) ), 'strlen' ) );
		}
		$out   = array();
		$depth = 0;
		$start = 0;
		$len   = strlen( $list );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $list[ $i ];
			if ( $c === '\\' ) {
				++$i;
			} elseif ( $c === '(' || $c === '[' ) {
				++$depth;
			} elseif ( $c === ')' || $c === ']' ) {
				--$depth;
			} elseif ( $c === ',' && $depth === 0 ) {
				$out[] = substr( $list, $start, $i - $start );
				$start = $i + 1;
			}
		}
		$out[] = substr( $list, $start );

		return array_values( array_filter( array_map( 'trim', $out ), 'strlen' ) );
	}

	/**
	 * The selector with every :is()/:where()/:not()/:has()/:nth-…() group taken out, nested ones included, or null when an
	 * :is()/:where() group cannot match.
	 *
	 * @param array{class: array<string, true>, id: array<string, true>, attr: array<string, true>, chars?: array<int, true>} $tokens
	 */
	private static function without_functional_pseudos( string $selector, array $tokens ): ?string {
		if ( ! str_contains( $selector, '(' ) ) {
			return $selector;
		}
		$out = '';
		$len = strlen( $selector );
		for ( $i = 0; $i < $len; $i++ ) {
			if ( $selector[ $i ] === '\\' ) {
				$out .= substr( $selector, $i, 2 );
				++$i;
				continue;
			}
			if ( $selector[ $i ] === ':' && preg_match( '/\G::?([a-zA-Z-]+)\(/', $selector, $m, 0, $i ) === 1 ) {
				$depth = 0;
				$from  = $i + strlen( $m[0] );
				for ( $j = $from - 1; $j < $len; $j++ ) {
					if ( $selector[ $j ] === '(' ) {
						++$depth;
					} elseif ( $selector[ $j ] === ')' ) {
						--$depth;
						if ( $depth === 0 ) {
							break;
						}
					}
				}
				// :is()/:where() match when one of their selectors does: the design's per-page rules
				// (`:where(.dxg-page-about) .hero p`) cannot match on another page. :not(), :has() and the rest stay unread.
				if ( in_array( strtolower( $m[1] ), array( 'is', 'where', 'matches', '-webkit-any', '-moz-any' ), true )
					&& ! self::can_match( substr( $selector, $from, $j - $from ), $tokens ) ) {
					return null;
				}
				$i = $j;
				continue;
			}
			$out .= $selector[ $i ];
		}

		return $out;
	}
}

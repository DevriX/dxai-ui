<?php
/**
 * Prefix design CSS under .dxai-ui without breaking :root, @keyframes, or @media.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Css_Scoper {

	/**
	 * Classes that sit on the scoped wrapper itself rather than a descendant.
	 *
	 * @var array<int, string>
	 */
	private static array $root_classes = array( 'rv-root' );

	/**
	 * Declare the design's own root classes so their rules keep matching the
	 * wrapper element instead of being pushed onto a descendant.
	 *
	 * @param array<int, string>|string $classes
	 */
	public static function set_root_classes( array|string $classes ): void {
		$list = is_string( $classes ) ? preg_split( '/\s+/', $classes ) : $classes;
		$out  = array( 'rv-root' );
		foreach ( $list ?: array() as $class ) {
			$class = ltrim( trim( (string) $class ), '.' );
			// Utilities are emitted by the Tailwind engine, which already
			// targets the wrapper; only design-authored classes belong here.
			if ( $class !== '' && ! in_array( $class, $out, true ) && 1 === preg_match( '/^[a-z][a-z0-9_-]*$/i', $class ) ) {
				$out[] = $class;
			}
		}
		self::$root_classes = $out;
	}

	public static function apply( string $scope, string $css ): string {
		$css = trim( $css );
		if ( $css === '' ) {
			return '';
		}

		$out    = '';
		$length = strlen( $css );
		$i      = 0;
		while ( $i < $length ) {
			while ( $i < $length && ctype_space( $css[ $i ] ) ) {
				$out .= $css[ $i ];
				++$i;
			}
			if ( $i >= $length ) {
				break;
			}
			if ( $css[ $i ] === '/' && ( $i + 1 ) < $length && $css[ $i + 1 ] === '*' ) {
				$end = strpos( $css, '*/', $i + 2 );
				$end = false === $end ? $length : $end + 2;
				$out .= substr( $css, $i, $end - $i );
				$i    = $end;
				continue;
			}
			if ( $css[ $i ] === '@' ) {
				$chunk = self::at_rule( $css, $i, $scope );
				$out  .= $chunk['css'];
				$i     = $chunk['next'];
				continue;
			}
			$brace = strpos( $css, '{', $i );
			if ( false === $brace ) {
				$out .= substr( $css, $i );
				break;
			}
			$selector = trim( substr( $css, $i, $brace - $i ) );
			$end      = self::matching_brace( $css, $brace );
			if ( $end === -1 ) {
				$out .= substr( $css, $i );
				break;
			}
			$body = substr( $css, $brace + 1, $end - $brace - 1 );
			$out .= self::prefix_selector( $scope, $selector ) . ' {' . $body . '}';
			$out .= self::document_scroll_rule( $scope, $selector, $body );
			$i    = $end + 1;
		}

		return $out;
	}

	/**
	 * Scroll declarations the design put on the document stay on the document.
	 *
	 * `html`, `:root` and `body` are rewritten to the scope wrapper above, and
	 * for colour, font and background that is right — the wrapper is the
	 * design's page. `scroll-behavior` and `scroll-padding-*` are not
	 * inherited and act only on the element that scrolls: on a <div> that
	 * does not scroll they do nothing, and Arcus's `html { scroll-behavior:
	 * smooth }` — every anchor link and its FAQ jumps glide in the design —
	 * arrived as `.dxai-ui--N { scroll-behavior: smooth }` and jumped on the
	 * page. They are repeated on `html`, narrowed with :has() to the pages
	 * that carry this design so another page on the site never scrolls
	 * differently for it. Media queries around the rule are kept: this runs
	 * inside them too.
	 */
	private static function document_scroll_rule( string $scope, string $selector, string $body ): string {
		if ( preg_match( '/^(?:html|:root|body)(?:\s*,\s*(?:html|:root|body))*$/i', trim( $selector ) ) !== 1 ) {
			return '';
		}
		if ( preg_match_all( '/(?:^|;)\s*(scroll-(?:behavior|padding(?:-[a-z]+)?)\s*:[^;]+)/i', $body, $m ) === 0 ) {
			return '';
		}

		return "\nhtml:has(" . $scope . ') {' . implode( ';', array_map( 'trim', $m[1] ) ) . ';}';
	}

	/**
	 * @return array{css:string, next:int}
	 */
	private static function at_rule( string $css, int $start, string $scope ): array {
		$length = strlen( $css );
		$brace  = strpos( $css, '{', $start );
		$semi   = strpos( $css, ';', $start );
		$name   = strtolower( (string) preg_replace( '/^@([a-z-]+).*/is', '$1', substr( $css, $start, 40 ) ) );

		if ( in_array( $name, array( 'import', 'charset', 'namespace' ), true ) || ( false === $brace && false !== $semi ) ) {
			$stop = false !== $semi ? $semi + 1 : $length;
			return array(
				'css'  => substr( $css, $start, $stop - $start ),
				'next' => $stop,
			);
		}

		if ( false === $brace ) {
			return array(
				'css'  => substr( $css, $start ),
				'next' => $length,
			);
		}

		$end  = self::matching_brace( $css, $brace );
		$head = trim( substr( $css, $start, $brace - $start ) );
		$body = $end === -1 ? substr( $css, $brace + 1 ) : substr( $css, $brace + 1, $end - $brace - 1 );
		$next = $end === -1 ? $length : $end + 1;

		if ( in_array( $name, array( 'keyframes', '-webkit-keyframes', 'font-face', 'property' ), true ) ) {
			return array(
				'css'  => $head . '{' . $body . '}',
				'next' => $next,
			);
		}

		if ( in_array( $name, array( 'media', 'supports', 'layer', 'container' ), true ) ) {
			return array(
				'css'  => $head . '{' . self::apply( $scope, $body ) . '}',
				'next' => $next,
			);
		}

		return array(
			'css'  => $head . '{' . $body . '}',
			'next' => $next,
		);
	}

	/**
	 * A design's `p` type selectors must not reach paragraphs the design did
	 * not write as <p>.
	 *
	 * Html_To_Blocks turns a text-only <div> or <span> into a paragraph block,
	 * and a paragraph block is a <p>. The design's own rules then meet an
	 * element they never matched: Integration Hub's `.rv-root p { max-width:
	 * 68ch; color: var(--rf-mute) }` narrowed its `div.rv-eng-out-label` from
	 * 720px to 428px at 768 and recoloured ten eyebrows. The converter marks
	 * every such element `dxai-not-p`; here every bare `p` type selector in
	 * the design's CSS is narrowed to `p:where(:not(.dxai-not-p))` — :where()
	 * keeps the rule's specificity exactly as written, so nothing else in the
	 * cascade moves — which is the set of elements the selector matched in
	 * the design.
	 *
	 * The `p` has to stand alone as a type: not `.p`, `#p`, `:p…`, `[p`, a
	 * quoted attribute value, or a letter inside `pre`, `path`, `picture`,
	 * `progress`, `.rv-p`.
	 */
	private static function spare_converted_paragraphs( string $part ): string {
		return (string) preg_replace( '/(?<![\w\-#.:\[\\\\"\'=])p(?![\w\-])/', 'p:where(:not(.dxai-not-p))', $part );
	}

	/**
	 * A selector list split at its own commas only — not those inside
	 * `:is()`, `:where()`, `:not()`, `:has()`, an attribute selector, a
	 * quoted value or a comment. A comment is skipped whole, so the
	 * apostrophe in a design's "Form controls don't inherit fonts" note above
	 * `button, input, …` is not read as a quote that hides every comma after
	 * it. Each selector is returned as written, untrimmed.
	 *
	 * Splitting on every comma cut `.rv-root .rv-footer :is(h2,h3,h4,strong)`
	 * into four pieces and scoped each: `:is(h2, .dxai-ui… h3, …)`, whose
	 * scoped arguments lifted the rule to (0,2,0) above the rules that were
	 * meant to beat it (Integration Hub's primary objective cards), and a
	 * piece given the same-node copy as well left a `(` unclosed, which
	 * swallows every rule after it.
	 *
	 * @return array<int, string>
	 */
	public static function split_selectors( string $list ): array {
		$out   = array();
		$depth = 0;
		$quote = '';
		$start = 0;
		$len   = strlen( $list );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $list[ $i ];
			if ( $ch === '\\' ) {
				++$i;
			} elseif ( $quote === '' && $ch === '/' && ( $list[ $i + 1 ] ?? '' ) === '*' ) {
				$end = strpos( $list, '*/', $i + 2 );
				$i   = false === $end ? $len : $end + 1;
			} elseif ( $quote !== '' ) {
				if ( $ch === $quote ) {
					$quote = '';
				}
			} elseif ( $ch === '"' || $ch === "'" ) {
				$quote = $ch;
			} elseif ( $ch === '(' || $ch === '[' ) {
				++$depth;
			} elseif ( $ch === ')' || $ch === ']' ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( $ch === ',' && $depth === 0 ) {
				$out[] = substr( $list, $start, $i - $start );
				$start = $i + 1;
			}
		}
		$out[] = substr( $list, $start );

		return $out;
	}

	private static function prefix_selector( string $scope, string $selector ): string {
		$parts = array_map( 'trim', self::split_selectors( $selector ) );
		$out   = array();
		foreach ( $parts as $part ) {
			if ( $part === '' ) {
				continue;
			}
			$part = self::spare_converted_paragraphs( $part );
			if ( str_starts_with( $part, $scope ) || str_starts_with( $part, ':root' ) || $part === 'html' || $part === 'body' ) {
				if ( $part === ':root' || $part === 'html' || $part === 'body' ) {
					$out[] = $scope;
					continue;
				}
				$out[] = $part;
				continue;
			}
			if ( str_starts_with( $part, '.dark' ) ) {
				$out[] = $scope . $part;
				continue;
			}
			// Same node as the scoped wrapper: keep the root class attached so
			// descendant specificity (.rv-root a vs .rv-cta) matches the ZIP.
			$matched = false;
			foreach ( self::$root_classes as $root ) {
				if ( preg_match( '/^\.' . preg_quote( $root, '/' ) . '(?=$|[\s>+~:\[,#.])/', $part ) ) {
					$out[]   = $scope . '.' . $root . (string) preg_replace( '/^\.' . preg_quote( $root, '/' ) . '/', '', $part );
					$matched = true;
					break;
				}
			}
			if ( $matched ) {
				continue;
			}
			$out[] = $scope . ' ' . $part;

			/*
			 * Also the same-node form, as the utility layer already emits for
			 * every class it generates. A class can land on the wrapper itself:
			 * Jsx_Compiler::section_level() carries a container's classes up to
			 * the page root when it descends past it, and a descendant-only
			 * selector then never matches. That silently dropped a design's
			 * `.edge { padding-inline: 2.5rem }` — 80px of page gutter — which
			 * changed where every heading below it wrapped.
			 *
			 * Only where it is syntactically valid: attaching the scope to a
			 * type selector would read as one word.
			 */
			if ( preg_match( '/^(?:\.|\[|:where\(|:is\(|:not\()/', $part ) === 1 ) {
				$out[] = $scope . $part;
			}
		}

		return implode( ', ', $out );
	}

	private static function matching_brace( string $css, int $open ): int {
		$depth  = 0;
		$length = strlen( $css );
		for ( $i = $open; $i < $length; $i++ ) {
			if ( $css[ $i ] === '{' ) {
				++$depth;
			} elseif ( $css[ $i ] === '}' ) {
				--$depth;
				if ( $depth === 0 ) {
					return $i;
				}
			}
		}

		return -1;
	}
}

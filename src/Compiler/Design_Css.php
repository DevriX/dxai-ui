<?php
/**
 * Keep pixel-perfect design CSS (tokens + custom classes) for Gutenberg.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Design_Css {

	/**
	 * Strip Tailwind v4 tooling, keep tokens, @keyframes, @media, and custom classes.
	 */
	public static function prepare( string $css ): string {
		$css = trim( $css );
		if ( $css === '' ) {
			return '';
		}

		/*
		 * Whether this is a Tailwind v3 stylesheet, read before the directives
		 * that say so are stripped below. It decides what flattening a layer
		 * costs: v4's `@layer` is a real cascade layer, v3's is a PostCSS
		 * convention that emits plain rules — so under v3 the author's own
		 * selector weight is what settles a conflict with a utility.
		 */
		$legacy = preg_match( '/@tailwind\s+(?:base|components|utilities)\s*;/i', $css ) === 1;

		$css = preg_replace( '/@import\s+["\']tailwindcss["\'][^;]*;/', '', $css ) ?? $css;
		$css = preg_replace( '/@import\s+["\']tw-animate-css["\'][^;]*;/', '', $css ) ?? $css;
		/*
		 * v3's entry directives. v4 replaced all three with the `@import` above,
		 * so nothing here had ever seen them — and a Tailwind v3 design, which
		 * is what the classic Lovable template produces, carries all three at
		 * the top of `src/index.css`. They were emitted verbatim into the
		 * page's stylesheet, where a browser treats an unknown at-rule as an
		 * error and skips to the next one.
		 */
		$css = preg_replace( '/@tailwind\s+[a-z-]+\s*;/i', '', $css ) ?? $css;
		$css = preg_replace( '/@config\s+[^;]+;/i', '', $css ) ?? $css;
		$css = preg_replace( '/@source\s+[^;]+;/', '', $css ) ?? $css;
		$css = preg_replace( '/@custom-variant\s+[^;]+;/', '', $css ) ?? $css;
		$css = preg_replace( '/@theme\s+inline\s*\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', '', $css ) ?? $css;
		$css = preg_replace( '/@theme\s*\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', '', $css ) ?? $css;
		$css = self::expand_utilities( $css );
		$css = self::unwrap_layers( $css, $legacy );
		$css = self::colour_mix_fallbacks( $css );

		return trim( $css );
	}

	/**
	 * Flatten every `@layer` block, keeping the cascade order it encoded.
	 *
	 * A layer must not survive into our output. Unlayered CSS beats every
	 * layer, whatever the selectors say, and our own preflight is unlayered —
	 * so a design's `@layer components { .edge { padding-inline: 2.5rem } }`
	 * lost to `* { padding: 0 }` and a page-wide gutter simply vanished, 80px
	 * of it, which changed where headings wrapped.
	 *
	 * The rules come out inside `:where()`, which reproduces the ladder Tailwind
	 * gets from real layers: base and components carry no specificity and settle
	 * between themselves by file order, while a utility class still outranks
	 * both. Token and document rules keep their weight — a custom property
	 * defined once should not become the weakest declaration on the page.
	 */
	/**
	 * A plain-colour fallback in front of every `color-mix()`.
	 *
	 * The design's own build does this and we did not. Running the Tailwind CLI
	 * over Integration Hub's stylesheet — the same CLI, the same input — gives
	 * `border: 1px solid var(--dx-green)` at the top level and the `color-mix()`
	 * inside `@supports (color: color-mix(in lab, red, red))`. Our sheet
	 * carried the `color-mix()` alone: 97 declarations across one design, no
	 * `@supports` anywhere. Chrome renders both the same, which is why nothing
	 * measured it; a browser without `color-mix` drops our declaration as
	 * invalid, so a border, a background or a shadow disappears where the
	 * design degrades to a solid colour.
	 *
	 * Written as two declarations in one rule rather than as an `@supports`
	 * block, which is the same guarantee with none of the restructuring: the
	 * fallback comes first and the `color-mix()` overrides it wherever it is
	 * understood. The fallback is the mix's own first colour, which is what the
	 * CLI uses.
	 */
	private static function colour_mix_fallbacks( string $css ): string {
		if ( ! str_contains( $css, 'color-mix(' ) ) {
			return $css;
		}

		return (string) preg_replace_callback(
			// One declaration: a property, a value holding a color-mix(), up to
			// the `;` or the rule's closing brace.
			'/(^|[;{])\s*([-a-zA-Z]+)\s*:\s*([^;{}]*color-mix\(\s*in\s+[a-z]+\s*,\s*([^,()]*(?:\([^()]*\))?[^,()]*)\s+[\d.]+%\s*,[^;{}]*)/',
			static function ( array $m ): string {
				$lead     = $m[1];
				$property = $m[2];
				$whole    = $m[3];
				$base     = trim( $m[4] );
				if ( $base === '' || stripos( $base, 'color-mix(' ) !== false ) {
					return $m[0];
				}
				// The fallback keeps everything around the mix — a border's
				// `1px solid`, a shadow's offsets — with the mix replaced by
				// its own first colour.
				$fallback = (string) preg_replace( '/color-mix\([^()]*(?:\([^()]*\)[^()]*)*\)/', $base, $whole );
				if ( $fallback === $whole ) {
					return $m[0];
				}

				return $lead . $property . ':' . $fallback . ';' . $property . ':' . $whole;
			},
			$css
		);
	}

	private static function unwrap_layers( string $css, bool $legacy = false ): string {
		$offset = 0;
		while ( preg_match( '/@layer\s+[A-Za-z_][\w-]*(?:\s*,\s*[A-Za-z_][\w-]*)*\s*\{/', $css, $m, PREG_OFFSET_CAPTURE, $offset ) === 1 ) {
			$start = (int) $m[0][1];
			$brace = $start + strlen( (string) $m[0][0] ) - 1;
			$end   = self::matching_brace( $css, $brace );
			if ( $end === -1 ) {
				break;
			}
			$body = substr( $css, $brace + 1, $end - $brace - 1 );
			/*
			 * A v3 layer was never a layer: PostCSS sorted its rules into the
			 * sheet and specificity decided the rest. Weakening them to
			 * `:where()` handed every conflict to the utility — and the classic
			 * template writes `.rule-list > li { @apply border-border }`, two
			 * components to a utility's one, which wins in the design's own
			 * build. Measured against it: four borders the wrong colour.
			 */
			$inner = $legacy ? $body : self::weaken_rules( $body );
			$css   = substr( $css, 0, $start ) . $inner . substr( $css, $end + 1 );
			$offset = $start + strlen( $inner );
		}

		return $css;
	}

	/** Selectors that keep their own weight when a layer is flattened. */
	private static function keeps_weight( string $selector ): bool {
		$bare = strtolower( trim( $selector ) );

		return in_array( $bare, array( ':root', 'html', 'body', '*', ':host' ), true )
			|| str_starts_with( $bare, ':root' )
			|| str_contains( $bare, '@' );
	}

	/** Wrap each rule's selector in `:where()`, recursing through at-rules. */
	private static function weaken_rules( string $css ): string {
		$out    = '';
		$i      = 0;
		$length = strlen( $css );

		while ( $i < $length ) {
			$brace = strpos( $css, '{', $i );
			if ( false === $brace ) {
				$out .= substr( $css, $i );
				break;
			}
			$end = self::matching_brace( $css, $brace );
			if ( $end === -1 ) {
				$out .= substr( $css, $i );
				break;
			}
			$head = trim( substr( $css, $i, $brace - $i ) );
			$body = substr( $css, $brace + 1, $end - $brace - 1 );
			$i    = $end + 1;

			if ( str_starts_with( $head, '@' ) ) {
				// A conditional group wraps rules; a descriptor block (@font-face,
				// @keyframes, @property) holds declarations and passes through.
				$name = strtolower( (string) preg_replace( '/^@([a-z-]+).*/is', '$1', $head ) );
				$out .= $head . '{' . ( in_array( $name, array( 'media', 'supports', 'container', 'layer' ), true ) ? self::weaken_rules( $body ) : $body ) . "}\n";
				continue;
			}

			// At the list's own commas only: `.btn:is(:hover,:focus-visible)`
			// split at every comma became `:where(.btn:is(:hover),:where(:focus-visible))`.
			$parts = array();
			foreach ( Css_Scoper::split_selectors( $head ) as $selector ) {
				$selector = trim( $selector );
				if ( $selector === '' ) {
					continue;
				}
				$parts[] = self::keeps_weight( $selector ) ? $selector : ':where(' . $selector . ')';
			}
			$out .= implode( ',', $parts ) . '{' . $body . "}\n";
		}

		return $out;
	}

	/**
	 * Turn Tailwind v4 `@utility` blocks into the class rules they stand for.
	 *
	 * These were passing through verbatim, and a browser ignores an at-rule it
	 * does not know — so a design's own component classes did nothing at all.
	 * `@utility eyebrow { font-size: 0.75rem; line-height: 1 }` is how one
	 * design sizes every label on the page, and losing it cost 12px on each,
	 * repeated through the whole document. Four of seven real designs use them;
	 * the one that does not was the only one measuring pixel-exact.
	 *
	 * Nesting is expanded rather than left to native CSS nesting, because the
	 * result is scoped per page afterwards and a resolved selector is what the
	 * scoper knows how to prefix.
	 */
	private static function expand_utilities( string $css ): string {
		$offset = 0;
		// A trailing `-*` is v4's functional form, which takes a `--value()`
		// body this does not model. Leave those alone rather than emit a class
		// literally named `tab-*`.
		while ( preg_match( '/@utility\s+([A-Za-z_][\w-]*)\s*\{/', $css, $m, PREG_OFFSET_CAPTURE, $offset ) === 1 ) {
			$start = (int) $m[0][1];
			$brace = $start + strlen( (string) $m[0][0] ) - 1;
			$end   = self::matching_brace( $css, $brace );
			if ( $end === -1 ) {
				break;
			}
			$body = self::strip_comments( substr( $css, $brace + 1, $end - $brace - 1 ) );
			/*
			 * `:where()` so a real utility on the same element wins the tie.
			 * Both are single classes, so order in the file would decide, and
			 * the design CSS is emitted after the generated utilities — which
			 * put our `.eyebrow { font-size: .75rem }` ahead of the author's
			 * own `text-[0.625rem]` on the very same element. Tailwind resolves
			 * that the other way, and the oracle agreed: 10px, not 12px. Same
			 * for `.num { line-height: .9 }` against `lg:text-4xl`.
			 */
			$rules = self::nest_to_rules( ':where(.' . $m[1][0] . ')', $body );
			$css   = substr( $css, 0, $start ) . $rules . substr( $css, $end + 1 );
			$offset = $start + strlen( $rules );
		}

		return $css;
	}

	/**
	 * Flatten one nested block into plain rules, in source order.
	 *
	 * Declarations are flushed before each nested block rather than gathered,
	 * so a property set both before and after a nested rule keeps the order the
	 * author wrote.
	 */
	private static function nest_to_rules( string $selector, string $body ): string {
		$out    = '';
		$buffer = '';
		$length = strlen( $body );

		for ( $i = 0; $i < $length; $i++ ) {
			// Copy a quoted value through whole, so a brace inside `content:`
			// is not mistaken for the start of a nested rule.
			if ( $body[ $i ] === '"' || $body[ $i ] === "'" ) {
				$quote   = $body[ $i ];
				$buffer .= $quote;
				for ( ++$i; $i < $length; $i++ ) {
					$buffer .= $body[ $i ];
					if ( $body[ $i ] === '\\' && $i + 1 < $length ) {
						$buffer .= $body[ ++$i ];
						continue;
					}
					if ( $body[ $i ] === $quote ) {
						break;
					}
				}
				continue;
			}
			if ( $body[ $i ] !== '{' ) {
				$buffer .= $body[ $i ];
				continue;
			}
			$close = self::matching_brace( $body, $i );
			if ( $close === -1 ) {
				break;
			}

			// Everything up to the last `;` is declarations for this selector;
			// what follows it is the nested block's own selector or at-rule.
			$cut    = strrpos( $buffer, ';' );
			$decls  = false === $cut ? '' : substr( $buffer, 0, $cut + 1 );
			$head   = trim( false === $cut ? $buffer : substr( $buffer, $cut + 1 ) );
			$buffer = '';

			$out  .= self::rule( $selector, $decls );
			$inner = substr( $body, $i + 1, $close - $i - 1 );
			$i     = $close;

			if ( str_starts_with( $head, '@' ) ) {
				$nested = trim( self::nest_to_rules( $selector, $inner ) );
				if ( $nested !== '' ) {
					$out .= $head . "{\n" . $nested . "\n}\n";
				}
				continue;
			}

			$out .= self::nest_to_rules( self::resolve( $selector, $head ), $inner );
		}

		return $out . self::rule( $selector, $buffer );
	}

	private static function rule( string $selector, string $declarations ): string {
		$declarations = trim( $declarations );

		return $declarations === '' ? '' : $selector . '{' . $declarations . "}\n";
	}

	/**
	 * Resolve a nested selector list against its parent, `&` included. Split
	 * at the list's own commas (Css_Scoper::split_selectors()), so `&:is(a,b)`
	 * stays one selector.
	 */
	private static function resolve( string $parent, string $nested ): string {
		$out = array();
		foreach ( Css_Scoper::split_selectors( $nested ) as $part ) {
			$part = trim( $part );
			if ( $part === '' ) {
				continue;
			}
			$out[] = str_contains( $part, '&' )
				? str_replace( '&', $parent, $part )
				: $parent . ' ' . $part;
		}

		return implode( ',', $out );
	}

	/** A `}` or `;` inside a comment would derail the brace matching above. */
	private static function strip_comments( string $css ): string {
		return preg_replace( '#/\*.*?\*/#s', '', $css ) ?? $css;
	}

	public static function excerpt( string $css, int $max_bytes = 80000 ): string {
		$css = trim( $css );
		if ( $css === '' ) {
			return '';
		}

		$parts = array();
		foreach ( array( ':root', '.dark', 'html', 'body' ) as $selector ) {
			$block = self::block_for_selector( $css, $selector );
			if ( $block !== '' ) {
				$parts[] = $block;
			}
		}

		if ( preg_match_all( '/@font-face\s*\{[^{}]*\}/is', $css, $faces ) ) {
			$parts = array_merge( $parts, $faces[0] );
		}

		$custom = self::custom_rules( $css );
		if ( $custom !== '' ) {
			$parts[] = $custom;
		}

		$out = trim( implode( "\n\n", array_unique( $parts ) ) );
		if ( $out === '' ) {
			$out = $css;
		}

		if ( strlen( $out ) > $max_bytes ) {
			return substr( $out, 0, $max_bytes );
		}

		return $out;
	}

	public static function tokens_only( string $css, int $max_bytes = 8000 ): string {
		$parts = array();
		foreach ( array( ':root', '.dark' ) as $selector ) {
			$block = self::block_for_selector( $css, $selector );
			if ( $block !== '' ) {
				$parts[] = $block;
			}
		}
		$out = trim( implode( "\n\n", $parts ) );
		if ( strlen( $out ) > $max_bytes ) {
			return substr( $out, 0, $max_bytes );
		}

		return $out;
	}

	private static function block_for_selector( string $css, string $selector ): string {
		$pattern = '/' . preg_quote( $selector, '/' ) . '\s*\{/';
		if ( ! preg_match( $pattern, $css, $m, PREG_OFFSET_CAPTURE ) ) {
			return '';
		}
		$start = (int) $m[0][1];
		$open  = strpos( $css, '{', $start );
		if ( false === $open ) {
			return '';
		}
		$end = self::matching_brace( $css, $open );
		if ( $end === -1 ) {
			return '';
		}

		return trim( substr( $css, $start, $end - $start + 1 ) );
	}

	private static function custom_rules( string $css ): string {
		$keep = array();
		$length = strlen( $css );
		$i      = 0;
		while ( $i < $length ) {
			while ( $i < $length && ctype_space( $css[ $i ] ) ) {
				++$i;
			}
			if ( $i >= $length ) {
				break;
			}
			if ( $css[ $i ] === '/' && ( $i + 1 ) < $length && $css[ $i + 1 ] === '*' ) {
				$end = strpos( $css, '*/', $i + 2 );
				$i   = false === $end ? $length : $end + 2;
				continue;
			}
			if ( $css[ $i ] === '@' ) {
				$brace = strpos( $css, '{', $i );
				if ( false === $brace ) {
					break;
				}
				$end = self::matching_brace( $css, $brace );
				$i   = $end === -1 ? $length : $end + 1;
				continue;
			}
			$brace = strpos( $css, '{', $i );
			if ( false === $brace ) {
				break;
			}
			$selector = trim( substr( $css, $i, $brace - $i ) );
			$end      = self::matching_brace( $css, $brace );
			if ( $end === -1 ) {
				break;
			}
			if ( self::keep_selector( $selector ) ) {
				$keep[] = trim( substr( $css, $i, $end - $i + 1 ) );
			}
			$i = $end + 1;
		}

		return implode( "\n\n", $keep );
	}

	private static function keep_selector( string $selector ): bool {
		return str_contains( $selector, '.rv-' )
			|| str_contains( $selector, '.sr-only' )
			|| str_contains( $selector, '[data-countup]' );
	}

	private static function unwrap_at_layer( string $css, string $name ): string {
		$offset = 0;
		$needle = '@layer';
		while ( ( $pos = stripos( $css, $needle, $offset ) ) !== false ) {
			$after = substr( $css, $pos + strlen( $needle ) );
			if ( ! preg_match( '/^\s+' . preg_quote( $name, '/' ) . '\s*\{/', $after, $m ) ) {
				$offset = $pos + strlen( $needle );
				continue;
			}
			$brace = $pos + strlen( $needle ) + (int) strpos( $after, '{' );
			$end   = self::matching_brace( $css, $brace );
			if ( $end === -1 ) {
				break;
			}
			$inner = substr( $css, $brace + 1, $end - $brace - 1 );
			$css    = substr( $css, 0, $pos ) . $inner . substr( $css, $end + 1 );
			$offset = $pos + strlen( $inner );
		}

		return $css;
	}

	/**
	 * Index of the `}` closing the `{` at $open.
	 *
	 * Comments and quoted values are skipped: a `}` inside either is not a
	 * brace, and counting it split a rule in the middle of a `content: "…"`
	 * declaration.
	 */
	private static function matching_brace( string $css, int $open ): int {
		$depth  = 0;
		$length = strlen( $css );

		for ( $i = $open; $i < $length; $i++ ) {
			$ch = $css[ $i ];

			if ( $ch === '/' && $i + 1 < $length && $css[ $i + 1 ] === '*' ) {
				$close = strpos( $css, '*/', $i + 2 );
				if ( false === $close ) {
					return -1;
				}
				$i = $close + 1;
				continue;
			}
			if ( $ch === '"' || $ch === "'" ) {
				for ( ++$i; $i < $length; $i++ ) {
					if ( $css[ $i ] === '\\' ) {
						++$i;
						continue;
					}
					if ( $css[ $i ] === $ch ) {
						break;
					}
				}
				continue;
			}
			if ( $ch === '{' ) {
				++$depth;
			} elseif ( $ch === '}' ) {
				--$depth;
				if ( $depth === 0 ) {
					return $i;
				}
			}
		}

		return -1;
	}
}

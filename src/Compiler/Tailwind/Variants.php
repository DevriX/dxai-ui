<?php
/**
 * Turns a candidate's variant list into at-rules and selector fragments.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler\Tailwind;

/**
 * Everything to the left of the last `:` in a class name lands here.
 *
 * A variant contributes at most four things: an at-rule wrapper, selector text
 * that sits in front of the utility class (`.group:hover`, `.dark`), selector
 * text glued to the class itself (`:hover`, `[data-state="open"]`, ` > *`), and
 * a cascade weight so the Engine can sort breakpoints. Stacked variants merge:
 * at-rules accumulate outermost first, prefixes join with a space and suffixes
 * append in author order.
 *
 * Pseudo-elements are collected apart from the other suffixes and appended
 * last, because CSS only allows a pseudo-element at the very end of a compound
 * selector; `hover:before:` and `before:hover:` therefore both come out as
 * `:hover::before`. The `content` declaration `before:`/`after:` need is not
 * this class's business - only the Engine's modules emit declarations, so the
 * caller is responsible for pairing a `::before` suffix with
 * `content: var(--tw-content, "")`.
 *
 * Anything unrecognised returns null for the whole list. The Engine then drops
 * the class instead of emitting a rule that would apply unconditionally.
 */
final class Variants {

	/** Root font size assumed when converting a rem breakpoint to a weight. */
	private const PIXELS_PER_REM = 16.0;

	/** Max-width weights count down from here so wide rules sort first. */
	private const MAX_WIDTH_BASE = 10000;

	/** Nudge that keeps a `max-*` query below its breakpoint, as CSS. */
	private const EPSILON = '0.02px';

	/** The same nudge as a number, for values already in pixels. */
	private const EPSILON_PX = 0.02;

	/** Ancestor class the shadcn-style designs in this repo switch themes with. */
	private const DARK = '.dark';

	/**
	 * Tailwind 4 gates every hover variant behind this, so a tap on a touch
	 * screen does not leave the hover state stuck on until the next tap
	 * elsewhere. Emitting `:hover` bare reproduced that bug on every card and
	 * link in a converted design; the parity harness against upstream is what
	 * surfaced it, on 18 classes in one design.
	 */
	private const HOVER_MEDIA = '@media (hover: hover)';

	/**
	 * Pseudo-class variants, including the structural ones Tailwind renames.
	 *
	 * @var array<string, string>
	 */
	private const PSEUDO_CLASSES = array(
		'hover'             => ':hover',
		'focus'             => ':focus',
		'focus-visible'     => ':focus-visible',
		'focus-within'      => ':focus-within',
		'active'            => ':active',
		'visited'           => ':visited',
		'target'            => ':target',
		'disabled'          => ':disabled',
		'enabled'           => ':enabled',
		'checked'           => ':checked',
		'indeterminate'     => ':indeterminate',
		'default'           => ':default',
		'required'          => ':required',
		'optional'          => ':optional',
		'valid'             => ':valid',
		'invalid'           => ':invalid',
		'in-range'          => ':in-range',
		'out-of-range'      => ':out-of-range',
		'placeholder-shown' => ':placeholder-shown',
		'autofill'          => ':autofill',
		'read-only'         => ':read-only',
		'read-write'        => ':read-write',
		'first'             => ':first-child',
		'last'              => ':last-child',
		'odd'               => ':nth-child(odd)',
		'even'              => ':nth-child(even)',
		'first-of-type'     => ':first-of-type',
		'last-of-type'      => ':last-of-type',
		'only'              => ':only-child',
		'only-of-type'      => ':only-of-type',
		'empty'             => ':empty',
		'open'              => ':is([open],:popover-open,:open)',
		'inert'             => ':is([inert],[inert] *)',
	);

	/**
	 * Pseudo-element variants. Always emitted at the end of the suffix.
	 *
	 * @var array<string, string>
	 */
	private const PSEUDO_ELEMENTS = array(
		'before'          => '::before',
		'after'           => '::after',
		'placeholder'     => '::placeholder',
		'selection'       => '::selection',
		'marker'          => '::marker',
		'file'            => '::file-selector-button',
		'first-line'      => '::first-line',
		'first-letter'    => '::first-letter',
		'backdrop'        => '::backdrop',
		'details-content' => '::details-content',
	);

	/**
	 * Variants that are only an at-rule wrapper.
	 *
	 * @var array<string, string>
	 */
	private const AT_RULES = array(
		'motion-safe'   => '@media (prefers-reduced-motion: no-preference)',
		'motion-reduce' => '@media (prefers-reduced-motion: reduce)',
		'print'         => '@media print',
		'forced-colors' => '@media (forced-colors: active)',
		'portrait'      => '@media (orientation: portrait)',
		'landscape'     => '@media (orientation: landscape)',
		'contrast-more' => '@media (prefers-contrast: more)',
		'contrast-less' => '@media (prefers-contrast: less)',
		'noscript'      => '@media (scripting: none)',
		'starting'      => '@starting-style',
	);

	/**
	 * Writing direction. Matched on the element or any ancestor, so a design
	 * that puts `dir` on its wrapper and one that puts it on the field itself
	 * both work. `:dir()` is harmless where unsupported: `:where()` parses its
	 * argument list forgivingly and drops what it cannot match.
	 *
	 * @var array<string, string>
	 */
	private const DIRECTIONS = array(
		'rtl' => ':where(:dir(rtl),[dir="rtl"],[dir="rtl"] *)',
		'ltr' => ':where(:dir(ltr),[dir="ltr"],[dir="ltr"] *)',
	);

	/**
	 * Descendant targeting variants.
	 *
	 * @var array<string, string>
	 */
	private const CHILDREN = array(
		'*'  => ' > *',
		'**' => ' *',
	);

	/**
	 * Marker class of a relationship variant mapped to its combinator. A group
	 * is an ancestor, a peer is a preceding sibling.
	 *
	 * @var array<string, string>
	 */
	private const RELATIONS = array(
		'group' => '',
		'peer'  => ' ~',
	);

	/**
	 * Nth-child style variants mapped to the pseudo-class they select with.
	 * Longest key first so `nth-last-of-type-` is not eaten by `nth-`.
	 *
	 * @var array<string, string>
	 */
	private const NTH = array(
		'nth-last-of-type' => ':nth-last-of-type',
		'nth-of-type'      => ':nth-of-type',
		'nth-last'         => ':nth-last-child',
		'nth'              => ':nth-child',
	);

	/**
	 * Resolve a whole variant list, outermost first.
	 *
	 * @param array<int, mixed> $variants Author-order variants from Candidate.
	 *
	 * @return array{at_rules:array<int,string>, prefix:string, suffix:string, weight:int}|null
	 */
	public static function resolve( array $variants, Theme $theme ): ?array {
		$at_rules = array();
		$prefixes = array();
		$suffix   = '';
		$element  = '';
		$width    = 0;
		$other    = false;

		foreach ( $variants as $variant ) {
			if ( ! is_string( $variant ) ) {
				return null;
			}

			$variant = trim( $variant );
			if ( $variant === '' ) {
				return null;
			}

			$resolved = self::single( $variant, $theme );
			if ( $resolved === null ) {
				return null;
			}

			if ( $resolved['at_rule'] !== '' ) {
				$at_rules[] = $resolved['at_rule'];
				if ( $resolved['weight'] > 0 ) {
					$width = max( $width, $resolved['weight'] );
				} else {
					$other = true;
				}
			}

			if ( $resolved['prefix'] !== '' ) {
				$prefixes[] = $resolved['prefix'];
			}

			$suffix  .= $resolved['suffix'];
			$element .= $resolved['element'];
		}

		return array(
			'at_rules' => $at_rules,
			'prefix'   => implode( ' ', $prefixes ),
			'suffix'   => $suffix . $element,
			'weight'   => $width > 0 ? $width : ( $other ? 1 : 0 ),
		);
	}

	/**
	 * One variant. The checks are ordered so bracketed and prefixed forms are
	 * claimed before the exact-match tables, and every table is an exact
	 * lookup, so `first`, `first-of-type` and `first-line` cannot collide.
	 *
	 * @return array{at_rule:string, prefix:string, suffix:string, element:string, weight:int}|null
	 */
	private static function single( string $variant, Theme $theme ): ?array {
		if ( self::is_bracketed( $variant ) ) {
			return self::arbitrary( $variant );
		}

		if ( str_starts_with( $variant, '@' ) ) {
			return self::container( $variant, $theme );
		}

		$screen = $theme->screen( $variant );
		if ( is_string( $screen ) && $screen !== '' ) {
			return self::media( $screen, false );
		}

		if ( str_starts_with( $variant, 'max-' ) ) {
			$screen = $theme->screen( substr( $variant, 4 ) );
			if ( is_string( $screen ) && $screen !== '' ) {
				return self::media( $screen, true );
			}
		}

		foreach ( array( 'min-' => false, 'max-' => true ) as $prefix => $is_max ) {
			if ( str_starts_with( $variant, $prefix . '[' ) && str_ends_with( $variant, ']' ) ) {
				$inner = Candidate::decode_arbitrary( substr( $variant, strlen( $prefix ) + 1, -1 ) );

				return self::media( $inner, $is_max );
			}
		}

		if ( isset( self::PSEUDO_ELEMENTS[ $variant ] ) ) {
			return self::rule( '', '', '', self::PSEUDO_ELEMENTS[ $variant ] );
		}

		if ( isset( self::AT_RULES[ $variant ] ) ) {
			return self::rule( self::AT_RULES[ $variant ], '', '', '' );
		}

		if ( isset( self::DIRECTIONS[ $variant ] ) ) {
			return self::rule( '', '', self::DIRECTIONS[ $variant ], '' );
		}

		if ( isset( self::CHILDREN[ $variant ] ) ) {
			return self::rule( '', '', self::CHILDREN[ $variant ], '' );
		}

		if ( $variant === 'dark' ) {
			return self::rule( '', self::DARK, '', '' );
		}

		$relation = self::relation( $variant );
		if ( $relation !== null ) {
			return self::rule( self::hover_gate( $variant ), $relation, '', '' );
		}

		$supports = self::supports( $variant );
		if ( $supports !== null ) {
			return self::rule( $supports, '', '', '' );
		}

		$condition = self::condition( $variant );
		if ( $condition !== null ) {
			return self::rule( self::hover_gate( $variant ), '', $condition, '' );
		}

		return null;
	}

	/**
	 * A width media query. `min-width` keeps the theme's own unit so a host
	 * theme with a different root font size still breaks where the design did.
	 * `max-width` is pulled just below the breakpoint, so `md:` wins over
	 * `max-md:` at exactly 48rem the way Tailwind's exclusive `width < 48rem`
	 * does.
	 *
	 * @return array{at_rule:string, prefix:string, suffix:string, element:string, weight:int}|null
	 */
	private static function media( string $value, bool $max ): ?array {
		$value = trim( $value );
		if ( $value === '' ) {
			return null;
		}

		$pixels = self::pixels( $value );

		if ( ! $max ) {
			return self::rule(
				'@media (min-width: ' . $value . ')',
				'',
				'',
				'',
				$pixels === null ? 0 : (int) round( $pixels )
			);
		}

		if ( preg_match( '/^(-?\d*\.?\d+)px$/', $value, $matches ) === 1 ) {
			$below = self::number( (float) $matches[1] - self::EPSILON_PX ) . 'px';
		} else {
			$below = 'calc(' . $value . ' - ' . self::EPSILON . ')';
		}

		return self::rule(
			'@media (max-width: ' . $below . ')',
			'',
			'',
			'',
			$pixels === null ? 0 : self::MAX_WIDTH_BASE - (int) round( $pixels )
		);
	}

	/**
	 * Container queries: `@md`, `@max-lg`, `@min-[30rem]`, `@lg/sidebar`. Not a
	 * media query, so it takes the generic at-rule weight.
	 *
	 * @return array{at_rule:string, prefix:string, suffix:string, element:string, weight:int}|null
	 */
	private static function container( string $variant, Theme $theme ): ?array {
		$parts = Candidate::split_top_level( substr( $variant, 1 ), '/' );
		$name  = count( $parts ) > 1 ? (string) array_pop( $parts ) : '';
		$key   = implode( '/', $parts );
		$max   = false;

		if ( str_starts_with( $key, 'max-' ) ) {
			$max = true;
			$key = substr( $key, 4 );
		} elseif ( str_starts_with( $key, 'min-' ) ) {
			$key = substr( $key, 4 );
		}

		if ( self::is_bracketed( $key ) ) {
			$size = Candidate::decode_arbitrary( substr( $key, 1, -1 ) );
		} else {
			$size = $key === '' ? null : $theme->value( 'container', $key );
		}

		if ( ! is_string( $size ) || trim( $size ) === '' ) {
			return null;
		}

		$size    = trim( $size );
		$feature = $max
			? '(max-width: calc(' . $size . ' - ' . self::EPSILON . '))'
			: '(min-width: ' . $size . ')';

		return self::rule( '@container ' . ( $name === '' ? '' : $name . ' ' ) . $feature, '', '', '' );
	}

	/**
	 * An arbitrary variant. `&` stands for the utility class itself, so a
	 * leading `&` leaves a suffix and a trailing `&` leaves an ancestor prefix.
	 * A variant that opens with `@` is passed through as an at-rule. Anything
	 * with `&` in the middle would need both halves of a selector the Engine
	 * builds itself, so it is declined.
	 *
	 * @return array{at_rule:string, prefix:string, suffix:string, element:string, weight:int}|null
	 */
	private static function arbitrary( string $variant ): ?array {
		$inner = Candidate::decode_arbitrary( substr( $variant, 1, -1 ) );
		if ( $inner === '' ) {
			return null;
		}

		if ( str_starts_with( $inner, '@' ) ) {
			return self::rule( $inner, '', '', '', self::at_rule_weight( $inner ) );
		}

		if ( ! str_contains( $inner, '&' ) ) {
			return null;
		}

		if ( str_starts_with( $inner, '&' ) ) {
			$rest = substr( $inner, 1 );
			if ( str_contains( $rest, '&' ) ) {
				return null;
			}

			if ( trim( $rest ) === '' ) {
				return self::rule( '', '', '', '' );
			}

			if ( preg_match( '/^\s*([>+~])\s*(\S.*)$/', $rest, $matches ) === 1 ) {
				return self::rule( '', '', ' ' . $matches[1] . ' ' . trim( $matches[2] ), '' );
			}

			if ( str_starts_with( $rest, ' ' ) ) {
				return self::rule( '', '', ' ' . trim( $rest ), '' );
			}

			if ( str_starts_with( $rest, '::' ) ) {
				return self::rule( '', '', '', $rest );
			}

			if ( preg_match( '/^[:.#\[]/', $rest ) === 1 ) {
				return self::rule( '', '', $rest, '' );
			}

			return null;
		}

		$trimmed = rtrim( $inner );
		if ( ! str_ends_with( $trimmed, '&' ) ) {
			return null;
		}

		$head = trim( substr( $trimmed, 0, -1 ) );
		if ( $head === '' || str_contains( $head, '&' ) ) {
			return null;
		}

		return self::rule( '', $head, '', '' );
	}

	/**
	 * The media query a hover variant belongs inside, if this variant is one.
	 *
	 * Covers the element's own `hover:` and the companion forms `group-hover:`
	 * and `peer-hover:`, named (`group-hover/menu:`) included — they all end up
	 * as `:hover` and all suffer the same stuck state on a touch screen.
	 * Deliberately an exact match on the state: `has-hover` and an arbitrary
	 * `[&:hover]` are the author asking for something specific, and upstream
	 * leaves those ungated too.
	 */
	private static function hover_gate( string $variant ): string {
		if ( $variant === 'hover' ) {
			return self::HOVER_MEDIA;
		}

		foreach ( array_keys( self::RELATIONS ) as $marker ) {
			if ( ! str_starts_with( $variant, $marker . '-' ) ) {
				continue;
			}

			$parts = Candidate::split_top_level( substr( $variant, strlen( $marker ) + 1 ), '/' );
			if ( count( $parts ) > 1 ) {
				array_pop( $parts );
			}

			if ( implode( '/', $parts ) === 'hover' ) {
				return self::HOVER_MEDIA;
			}
		}

		return '';
	}

	/**
	 * `group-*` and `peer-*`, including the named `group-hover/menu` form and
	 * the arbitrary `group-[.is-open]` form.
	 */
	private static function relation( string $variant ): ?string {
		foreach ( self::RELATIONS as $marker => $combinator ) {
			if ( ! str_starts_with( $variant, $marker . '-' ) ) {
				continue;
			}

			$parts = Candidate::split_top_level( substr( $variant, strlen( $marker ) + 1 ), '/' );
			$name  = count( $parts ) > 1 ? (string) array_pop( $parts ) : '';
			$rest  = implode( '/', $parts );
			if ( $rest === '' ) {
				return null;
			}

			$base = '.' . Candidate::escape( $name === '' ? $marker : $marker . '/' . $name );

			if ( self::is_bracketed( $rest ) ) {
				$inner = Candidate::decode_arbitrary( substr( $rest, 1, -1 ) );
				if ( $inner === '' ) {
					return null;
				}
				$selector = str_contains( $inner, '&' ) ? str_replace( '&', $base, $inner ) : $base . $inner;
			} else {
				$condition = self::condition( $rest );
				if ( $condition === null ) {
					return null;
				}
				$selector = $base . $condition;
			}

			return trim( $selector ) . $combinator;
		}

		return null;
	}

	/**
	 * A state test that can be attached to a single element: a pseudo-class, a
	 * `data-*`/`aria-*` attribute, an nth selector, or a `has-`/`not-` wrapper
	 * around any of those. Shared by the element itself and by group/peer.
	 */
	private static function condition( string $variant ): ?string {
		if ( isset( self::PSEUDO_CLASSES[ $variant ] ) ) {
			return self::PSEUDO_CLASSES[ $variant ];
		}

		$attribute = self::attribute( $variant );
		if ( $attribute !== null ) {
			return $attribute;
		}

		$nth = self::nth( $variant );
		if ( $nth !== null ) {
			return $nth;
		}

		$has = self::functional( $variant, 'has-', ':has', true );
		if ( $has !== null ) {
			return $has;
		}

		return self::functional( $variant, 'not-', ':not', false );
	}

	/**
	 * `has-[...]`, `has-checked`, `not-[...]`, `not-first`. Tailwind matches a
	 * descendant for `has-`, hence the `*` in `:has(*:checked)`.
	 */
	private static function functional( string $variant, string $prefix, string $pseudo, bool $universal ): ?string {
		if ( ! str_starts_with( $variant, $prefix ) ) {
			return null;
		}

		$rest = substr( $variant, strlen( $prefix ) );
		if ( $rest === '' ) {
			return null;
		}

		if ( self::is_bracketed( $rest ) ) {
			$inner = Candidate::decode_arbitrary( substr( $rest, 1, -1 ) );
			if ( str_starts_with( $inner, '&' ) ) {
				$inner = trim( substr( $inner, 1 ) );
			}

			return $inner === '' ? null : $pseudo . '(' . $inner . ')';
		}

		$condition = self::condition( $rest );
		if ( $condition === null ) {
			return null;
		}

		if ( $universal && preg_match( '/^[:\[]/', $condition ) === 1 ) {
			$condition = '*' . $condition;
		}

		return $pseudo . '(' . $condition . ')';
	}

	/**
	 * `data-[state=open]`, `aria-[expanded=true]`, and the bare shorthands
	 * `data-open` and `aria-checked`.
	 */
	private static function attribute( string $variant ): ?string {
		foreach ( array( 'data', 'aria' ) as $kind ) {
			if ( ! str_starts_with( $variant, $kind . '-' ) ) {
				continue;
			}

			$rest = substr( $variant, strlen( $kind ) + 1 );
			if ( $rest === '' ) {
				return null;
			}

			if ( self::is_bracketed( $rest ) ) {
				$inner = Candidate::decode_arbitrary( substr( $rest, 1, -1 ) );

				return $inner === '' ? null : self::attribute_selector( $kind, $inner );
			}

			if ( preg_match( '/^[a-zA-Z][a-zA-Z0-9-]*$/', $rest ) !== 1 ) {
				return null;
			}

			return $kind === 'data'
				? '[data-' . $rest . ']'
				: '[aria-' . $rest . '="true"]';
		}

		return null;
	}

	/**
	 * Build `[data-state="open"]` from `state=open`, quoting the value and
	 * keeping any comparison operator or case-sensitivity flag.
	 */
	private static function attribute_selector( string $kind, string $inner ): ?string {
		if ( preg_match( '/^([a-zA-Z][a-zA-Z0-9-]*)\s*([~|^$*]?=)\s*(\S.*)$/', $inner, $matches ) === 1 ) {
			$value = trim( $matches[3] );
			$flag  = '';

			if ( preg_match( '/^(\S.*?)\s+([isIS])$/', $value, $cased ) === 1 ) {
				$value = trim( $cased[1] );
				$flag  = ' ' . strtolower( $cased[2] );
			}

			$quoted = ( str_starts_with( $value, '"' ) && str_ends_with( $value, '"' ) )
				|| ( str_starts_with( $value, "'" ) && str_ends_with( $value, "'" ) );

			if ( ! $quoted ) {
				$value = '"' . str_replace( '"', '\\"', $value ) . '"';
			}

			return '[' . $kind . '-' . $matches[1] . $matches[2] . $value . $flag . ']';
		}

		if ( preg_match( '/^[a-zA-Z][a-zA-Z0-9-]*$/', $inner ) === 1 ) {
			return '[' . $kind . '-' . $inner . ']';
		}

		return null;
	}

	/**
	 * `nth-3`, `nth-[2n+1]`, `nth-last-of-type-[3]`.
	 */
	private static function nth( string $variant ): ?string {
		foreach ( self::NTH as $key => $pseudo ) {
			if ( ! str_starts_with( $variant, $key . '-' ) ) {
				continue;
			}

			$rest  = substr( $variant, strlen( $key ) + 1 );
			$inner = self::is_bracketed( $rest )
				? Candidate::decode_arbitrary( substr( $rest, 1, -1 ) )
				: $rest;

			if ( preg_match( '/^(odd|even|-?\d+|-?\d*n(\s*[+-]\s*\d+)?)$/i', $inner ) !== 1 ) {
				return null;
			}

			return $pseudo . '(' . $inner . ')';
		}

		return null;
	}

	/**
	 * `supports-[display:grid]` and `supports-not-[...]`. A bare property name
	 * becomes a value test the way Tailwind writes it.
	 */
	private static function supports( string $variant ): ?string {
		if ( ! str_starts_with( $variant, 'supports-' ) ) {
			return null;
		}

		$rest    = substr( $variant, 9 );
		$negated = false;

		if ( str_starts_with( $rest, 'not-' ) ) {
			$negated = true;
			$rest    = substr( $rest, 4 );
		}

		if ( ! self::is_bracketed( $rest ) ) {
			return null;
		}

		$inner = Candidate::decode_arbitrary( substr( $rest, 1, -1 ) );
		if ( $inner === '' ) {
			return null;
		}

		if ( str_starts_with( $inner, '(' ) || str_starts_with( $inner, 'not ' ) || str_starts_with( $inner, 'selector(' ) ) {
			$query = $inner;
		} elseif ( str_contains( $inner, ':' ) ) {
			$query = '(' . $inner . ')';
		} else {
			$query = '(' . $inner . ': var(--tw))';
		}

		return '@supports ' . ( $negated ? 'not ' : '' ) . $query;
	}

	/**
	 * Weight for a hand written at-rule, read off its width feature.
	 */
	private static function at_rule_weight( string $at_rule ): int {
		if ( preg_match( '/min-width\s*:\s*([^)\s]+)/i', $at_rule, $matches ) === 1 ) {
			$pixels = self::pixels( $matches[1] );

			return $pixels === null ? 0 : (int) round( $pixels );
		}

		if ( preg_match( '/max-width\s*:\s*([^)\s]+)/i', $at_rule, $matches ) === 1 ) {
			$pixels = self::pixels( $matches[1] );

			return $pixels === null ? 0 : self::MAX_WIDTH_BASE - (int) round( $pixels );
		}

		return 0;
	}

	/**
	 * A length in pixels, or null when the value is not a plain length.
	 */
	private static function pixels( string $value ): ?float {
		if ( preg_match( '/^(-?\d*\.?\d+)\s*(px|rem|em)?$/', trim( $value ), $matches ) !== 1 ) {
			return null;
		}

		$number = (float) $matches[1];
		$unit   = $matches[2] ?? '';

		return ( $unit === 'rem' || $unit === 'em' ) ? $number * self::PIXELS_PER_REM : $number;
	}

	/**
	 * Shortest decimal form of a number, without a trailing dot or zeros.
	 */
	private static function number( float $value ): string {
		$out = rtrim( rtrim( number_format( $value, 4, '.', '' ), '0' ), '.' );

		return ( $out === '' || $out === '-' ) ? '0' : $out;
	}

	private static function is_bracketed( string $value ): bool {
		return strlen( $value ) > 2 && str_starts_with( $value, '[' ) && str_ends_with( $value, ']' );
	}

	/**
	 * @return array{at_rule:string, prefix:string, suffix:string, element:string, weight:int}
	 */
	private static function rule( string $at_rule, string $prefix, string $suffix, string $element, int $weight = 0 ): array {
		return array(
			'at_rule' => $at_rule,
			'prefix'  => $prefix,
			'suffix'  => $suffix,
			'element' => $element,
			'weight'  => $weight,
		);
	}
}

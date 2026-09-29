<?php
/**
 * Padding, margin and sizing utilities.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler\Tailwind\Utilities;

use DXAI_UI\Compiler\Tailwind\Candidate;
use DXAI_UI\Compiler\Tailwind\Theme;
use DXAI_UI\Compiler\Tailwind\Utility_Module;

/**
 * Owns `p-*`/`m-*` in every axis and logical variant plus the box sizing
 * family `w-*`, `h-*`, `size-*`, `min-w-*`, `min-h-*`, `max-w-*`, `max-h-*`
 * and its logical mirror `block-*`, `inline-*`, `min-block-*`, `min-inline-*`,
 * `max-block-*`, `max-inline-*`.
 *
 * Owning the `block` and `inline` roots puts this module in front of Layout's
 * `display` keywords, since the Engine consults us first: `block` and `inline`
 * on their own are `display`, and so are `inline-block`, `inline-flex`,
 * `inline-grid` and `inline-table`. Two things keep those working and both are
 * load-bearing -- match_prefix() only ever matches `<prefix>-`, so a hyphenless
 * base cannot reach the sizing path at all, and INLINE_DISPLAY names the four
 * hyphenated ones explicitly.
 *
 * `space-x-*`/`space-y-*` emit margin on siblings; the Engine appends the
 * `> :not(:last-child)` combinator so the declarations land
 * on the children, matching Tailwind v4.
 */
final class Spacing implements Utility_Module {

	/**
	 * Padding prefixes mapped to the properties they set.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const PADDING = array(
		'p'   => array( 'padding' ),
		'px'  => array( 'padding-inline' ),
		'py'  => array( 'padding-block' ),
		'pt'  => array( 'padding-top' ),
		'pr'  => array( 'padding-right' ),
		'pb'  => array( 'padding-bottom' ),
		'pl'  => array( 'padding-left' ),
		'ps'  => array( 'padding-inline-start' ),
		'pe'  => array( 'padding-inline-end' ),
		// The block-axis pair of `ps`/`pe`, not a longer spelling of `pb`:
		// `pb` is always the physical bottom, `pbe` follows the writing mode.
		'pbs' => array( 'padding-block-start' ),
		'pbe' => array( 'padding-block-end' ),
	);

	/**
	 * Margin prefixes mapped to the properties they set.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const MARGIN = array(
		'm'   => array( 'margin' ),
		'mx'  => array( 'margin-inline' ),
		'my'  => array( 'margin-block' ),
		'mt'  => array( 'margin-top' ),
		'mr'  => array( 'margin-right' ),
		'mb'  => array( 'margin-bottom' ),
		'ml'  => array( 'margin-left' ),
		'ms'  => array( 'margin-inline-start' ),
		'me'  => array( 'margin-inline-end' ),
		// Same relationship as `pbs`/`pbe` to `pb`. `space-y-*` writes these
		// two properties as well, on siblings rather than on the element.
		'mbs' => array( 'margin-block-start' ),
		'mbe' => array( 'margin-block-end' ),
	);

	/**
	 * Sizing prefixes mapped to the properties they set.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const SIZING = array(
		'w'          => array( 'width' ),
		'h'          => array( 'height' ),
		'size'       => array( 'width', 'height' ),
		'min-w'      => array( 'min-width' ),
		'min-h'      => array( 'min-height' ),
		'max-w'      => array( 'max-width' ),
		'max-h'      => array( 'max-height' ),
		// The logical half. `block` is the writing-mode counterpart of `h` and
		// `inline` of `w`, so these prefixes deliberately mirror the keyword
		// and container behaviour of their physical twins rather than each
		// other -- see sizing_keyword() and sizing_value().
		'block'      => array( 'block-size' ),
		'inline'     => array( 'inline-size' ),
		'min-block'  => array( 'min-block-size' ),
		'min-inline' => array( 'min-inline-size' ),
		'max-block'  => array( 'max-block-size' ),
		'max-inline' => array( 'max-inline-size' ),
	);

	/**
	 * Sibling-gap utilities. Declarations apply to children via Engine.
	 *
	 * @var array<string, array{start: string, end: string, reverse: string}>
	 */
	private const SPACE = array(
		'space-x' => array(
			'start'   => 'margin-inline-start',
			'end'     => 'margin-inline-end',
			'reverse' => '--tw-space-x-reverse',
		),
		'space-y' => array(
			'start'   => 'margin-block-start',
			'end'     => 'margin-block-end',
			'reverse' => '--tw-space-y-reverse',
		),
	);

	/**
	 * Sizing keywords that must never fall through to the spacing scale.
	 *
	 * @var array<int, string>
	 */
	private const RESERVED = array(
		'full',
		'screen',
		'min',
		'max',
		'fit',
		'none',
		'auto',
		'dvw',
		'dvh',
		'lvw',
		'lvh',
		'svw',
		'svh',
		'lh',
		'prose',
	);

	/**
	 * Tokens that make `inline-*` a `display` utility instead of a size.
	 *
	 * `inline-block`, `inline-flex`, `inline-grid` and `inline-table` belong to
	 * Layout, which the Engine consults *after* us, so owning the `inline`
	 * prefix means we are now the module standing in front of four of the most
	 * common classes there are. They already fail every branch of
	 * sizing_value(), but only because nothing declares a `--spacing-flex`
	 * token -- a design that did would silently turn its `inline-flex` into an
	 * `inline-size`. Refusing them by name removes that dependency.
	 *
	 * Kept out of RESERVED because that table is read for `w-*` and `h-*` too,
	 * where these tokens are not classes at all and blocking them would only
	 * mask a design's own `--spacing-*` names.
	 *
	 * @var array<int, string>
	 */
	private const INLINE_DISPLAY = array(
		'block',
		'flex',
		'grid',
		'table',
	);

	/**
	 * Data type hints Tailwind allows in front of an arbitrary value.
	 *
	 * @var array<int, string>
	 */
	private const DATA_TYPES = array(
		'length',
		'percentage',
		'number',
		'integer',
		'ratio',
		'size',
		'any',
	);

	/**
	 * Shape of a container scale token: `xs`, `sm` … `xl`, optionally prefixed
	 * with a multiplier as in `2xs`, `3xl` or a theme's own `8xl`.
	 */
	private const CONTAINER_PATTERN = '/^\d*(?:xs|sm|md|lg|xl)$/';

	/**
	 * @return array<string, string>|null
	 */
	public function resolve( Candidate $candidate, Theme $theme ): ?array {
		$base = $candidate->base;

		if ( $base === 'space-x-reverse' ) {
			return $candidate->negative || $candidate->modifier !== '' ? null : array( '--tw-space-x-reverse' => '1' );
		}
		if ( $base === 'space-y-reverse' ) {
			return $candidate->negative || $candidate->modifier !== '' ? null : array( '--tw-space-y-reverse' => '1' );
		}

		foreach ( self::SPACE as $prefix => $axes ) {
			if ( $base !== $prefix && ! str_starts_with( $base, $prefix . '-' ) ) {
				continue;
			}
			$token = $base === $prefix ? '1' : substr( $base, strlen( $prefix ) + 1 );
			$value = self::box_value( $token, $candidate, $theme, true );
			if ( $value === null || $value === 'auto' ) {
				return null;
			}

			/*
			 * The two majors put the gap on opposite sides. v4 selects every
			 * child but the LAST and gives it a trailing margin; v3 selects
			 * every child but the FIRST and gives it a leading one. The gaps
			 * land in the same places and the container is the same height, so
			 * no pixel total can see the difference — but a per-node box can,
			 * and against a v3 build every shadcn CardHeader reported a
			 * margin-top the design has and we do not. The selector half of
			 * this lives in Engine::child_combinator().
			 */
			if ( $theme->is_legacy() ) {
				return array(
					$axes['reverse'] => '0',
					$axes['start']   => 'calc(' . $value . ' * calc(1 - var(' . $axes['reverse'] . ')))',
					$axes['end']     => 'calc(' . $value . ' * var(' . $axes['reverse'] . '))',
				);
			}

			return array(
				$axes['reverse'] => '0',
				$axes['start']   => 'calc(' . $value . ' * var(' . $axes['reverse'] . '))',
				$axes['end']     => 'calc(' . $value . ' * calc(1 - var(' . $axes['reverse'] . ')))',
			);
		}

		$prefix = self::match_prefix( $base );
		if ( $prefix === null ) {
			return null;
		}

		$token = substr( $base, strlen( $prefix ) + 1 );
		if ( $token === '' ) {
			return null;
		}

		if ( isset( self::PADDING[ $prefix ] ) ) {
			$value = self::box_value( $token, $candidate, $theme, false );

			return $value === null ? null : self::declarations( self::PADDING[ $prefix ], $value );
		}

		if ( isset( self::MARGIN[ $prefix ] ) ) {
			$value = self::box_value( $token, $candidate, $theme, true );

			return $value === null ? null : self::declarations( self::MARGIN[ $prefix ], $value );
		}

		$value = self::sizing_value( $prefix, $token, $candidate, $theme );

		return $value === null ? null : self::declarations( self::SIZING[ $prefix ], $value );
	}

	/**
	 * @return array<string, string>
	 */
	public function keyframes(): array {
		return array();
	}

	/**
	 * Longest owned prefix the base begins with, or null when unowned.
	 */
	private static function match_prefix( string $base ): ?string {
		$prefixes = array_merge(
			array_keys( self::SIZING ),
			array_keys( self::MARGIN ),
			array_keys( self::PADDING )
		);

		$match = null;
		foreach ( $prefixes as $prefix ) {
			if ( ! str_starts_with( $base, $prefix . '-' ) ) {
				continue;
			}
			if ( $match === null || strlen( $prefix ) > strlen( $match ) ) {
				$match = $prefix;
			}
		}

		return $match;
	}

	/**
	 * @param array<int, string> $properties
	 *
	 * @return array<string, string>
	 */
	private static function declarations( array $properties, string $value ): array {
		$out = array();
		foreach ( $properties as $property ) {
			$out[ $property ] = $value;
		}

		return $out;
	}

	/**
	 * Padding and margin share one scale; only margin takes `auto` and only
	 * margin may be negated.
	 */
	private static function box_value( string $token, Candidate $candidate, Theme $theme, bool $is_margin ): ?string {
		// Fractions are not part of the padding or margin scale and no other
		// modifier applies, so a modifier means the class is not really ours.
		if ( $candidate->modifier !== '' ) {
			return null;
		}

		if ( $candidate->negative && ! $is_margin ) {
			return null;
		}

		$value = self::explicit_value( $token, $candidate );

		if ( $value === null && $is_margin && $token === 'auto' ) {
			$value = 'auto';
		}

		if ( $value === null ) {
			$value = self::scale_value( $token, $theme );
		}

		if ( $value === null ) {
			return null;
		}

		return $candidate->negative ? self::negate( $value ) : $value;
	}

	/**
	 * Width, height, size, their min/max forms and the logical equivalents.
	 */
	private static function sizing_value( string $prefix, string $token, Candidate $candidate, Theme $theme ): ?string {
		if ( $prefix === 'inline' && in_array( $token, self::INLINE_DISPLAY, true ) ) {
			return null;
		}

		if ( $candidate->negative ) {
			return null;
		}

		if ( $candidate->modifier !== '' ) {
			return self::fraction( $token, $candidate->modifier );
		}

		$value = self::explicit_value( $token, $candidate );
		if ( $value !== null ) {
			return $value;
		}

		$value = self::sizing_keyword( $prefix, $token );
		if ( $value !== null ) {
			return $value;
		}

		// The container scale is a measure width, so it follows the inline axis
		// only: `inline-md` exists upstream, `block-md` does not, exactly as
		// `w-md` exists and `h-md` does not.
		if ( in_array( $prefix, array( 'w', 'min-w', 'max-w', 'inline', 'min-inline', 'max-inline' ), true ) ) {
			$container = self::container_value( $token, $theme );
			if ( $container !== null ) {
				return $container;
			}
		}

		if ( $prefix === 'max-w' && str_starts_with( $token, 'screen-' ) ) {
			$screen = $theme->screen( substr( $token, strlen( 'screen-' ) ) );

			return is_string( $screen ) && $screen !== '' ? $screen : null;
		}

		return self::scale_value( $token, $theme );
	}

	/**
	 * `w-sm`, `min-w-3xs`, `max-w-7xl` and any further container step the
	 * design's own `@theme` block declares.
	 */
	private static function container_value( string $token, Theme $theme ): ?string {
		/*
		 * The pattern is the BUILT-IN scale's shape, and gating on it alone
		 * refused every name a design invented for itself: `max-w-measure`
		 * against a `maxWidth: { measure: '34rem' }` resolved to nothing, and
		 * the element fell back to its parent's width. A token the design
		 * declared is a token, whatever it is called.
		 */
		if ( preg_match( self::CONTAINER_PATTERN, $token ) !== 1 && ! $theme->declares( 'container', $token ) ) {
			return null;
		}

		$value = $theme->value( 'container', $token );

		return is_string( $value ) && $value !== '' ? $value : null;
	}

	/**
	 * Keywords each sizing property accepts, including the viewport units.
	 */
	private static function sizing_keyword( string $prefix, string $token ): ?string {
		$vertical = in_array( $prefix, array( 'h', 'min-h', 'max-h', 'block', 'min-block', 'max-block' ), true );
		$logical  = in_array( $prefix, array( 'block', 'min-block', 'max-block', 'inline', 'min-inline', 'max-inline' ), true );

		$shared = array(
			'full' => '100%',
			'min'  => 'min-content',
			'max'  => 'max-content',
			'fit'  => 'fit-content',
			'dvw'  => '100dvw',
			'dvh'  => '100dvh',
			'lvw'  => '100lvw',
			'lvh'  => '100lvh',
			'svw'  => '100svw',
			'svh'  => '100svh',
		);

		// The physical prefixes take all six viewport units regardless of their
		// own axis -- `w-dvh` and `h-dvw` are both real upstream classes. The
		// logical prefixes do not: upstream names the unit after the axis the
		// property already fixes, so `block-dvh` and `inline-dvw` exist while
		// `block-dvw` and `inline-dvh` are never generated.
		if ( $logical && preg_match( '/^[dls]v[wh]$/', $token ) === 1 ) {
			return $vertical === str_ends_with( $token, 'h' ) ? $shared[ $token ] : null;
		}

		if ( isset( $shared[ $token ] ) ) {
			return $shared[ $token ];
		}

		if ( $token === 'auto' && in_array( $prefix, array( 'w', 'h', 'size', 'min-w', 'min-h', 'block', 'inline', 'min-block', 'min-inline' ), true ) ) {
			return 'auto';
		}

		if ( $token === 'none' && in_array( $prefix, array( 'max-w', 'max-h', 'max-block', 'max-inline' ), true ) ) {
			return 'none';
		}

		if ( $token === 'screen' && $prefix !== 'size' ) {
			return $vertical ? '100vh' : '100vw';
		}

		if ( $token === 'lh' && $vertical ) {
			return '1lh';
		}

		if ( $token === 'prose' && $prefix === 'max-w' ) {
			return '65ch';
		}

		return null;
	}

	/**
	 * `[...]` arbitrary values and the `(--var)` shorthand.
	 */
	private static function explicit_value( string $token, Candidate $candidate ): ?string {
		if ( str_starts_with( $token, '[' ) && str_ends_with( $token, ']' ) ) {
			$value = self::strip_data_type( $candidate->arbitrary_value() );

			return $value === '' ? null : self::normalize_math( $value );
		}

		if ( str_starts_with( $token, '(' ) && str_ends_with( $token, ')' ) ) {
			$inner = self::strip_data_type( Candidate::decode_arbitrary( substr( $token, 1, -1 ) ) );

			return $inner === '' ? null : 'var(' . $inner . ')';
		}

		return null;
	}

	/**
	 * Numeric spacing steps, `px`, and any named `--spacing-*` theme token.
	 */
	private static function scale_value( string $token, Theme $theme ): ?string {
		if ( in_array( $token, self::RESERVED, true ) ) {
			return null;
		}

		$numeric = preg_match( '/^\d+(?:\.\d+)?$/', $token ) === 1;
		if ( ! $numeric && $token !== 'px' && preg_match( '/^[a-z][a-z0-9-]*$/', $token ) !== 1 ) {
			return null;
		}

		$value = $theme->spacing( $token );
		if ( is_string( $value ) && $value !== '' ) {
			return $value;
		}

		return $token === '0' ? '0px' : null;
	}

	/**
	 * `w-1/2` reaches us as base `w-1` plus modifier `2`.
	 */
	private static function fraction( string $token, string $modifier ): ?string {
		if ( preg_match( '/^\d+$/', $token ) !== 1 || preg_match( '/^\d+$/', $modifier ) !== 1 ) {
			return null;
		}

		if ( (int) $modifier === 0 ) {
			return null;
		}

		return 'calc(' . $token . '/' . $modifier . ' * 100%)';
	}

	/**
	 * Tailwind spaces out the operators inside math functions, so the authored
	 * `h-[calc(100vh-4rem)]` still becomes valid `calc(100vh - 4rem)`. Only
	 * `+` and `-` need it; `*` and `/` are legal unspaced. Values with no math
	 * function are returned untouched.
	 */
	private static function normalize_math( string $value ): string {
		if ( preg_match( '/(?:^|[^\w-])(?:calc|min|max|clamp)\s*\(/i', $value ) !== 1 ) {
			return $value;
		}

		$out = '';
		$len = strlen( $value );

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $value[ $i ];

			// A custom property name is full of hyphens, so copy `var(...)` verbatim.
			if ( ( $char === 'v' || $char === 'V' ) && preg_match( '/^var\s*\(/i', substr( $value, $i, 8 ) ) === 1 ) {
				$depth = 0;
				for ( ; $i < $len; $i++ ) {
					$inner = $value[ $i ];
					if ( $inner === '(' ) {
						++$depth;
					} elseif ( $inner === ')' ) {
						--$depth;
					}
					$out .= $inner;
					if ( $depth === 0 && $inner === ')' ) {
						break;
					}
				}
				continue;
			}

			if ( ( $char === '+' || $char === '-' ) && self::is_math_operator( $value, $i ) ) {
				$out .= ' ' . $char . ' ';
				continue;
			}

			$out .= $char;
		}

		return $out;
	}

	/**
	 * True when the character at $offset is a binary operator rather than a
	 * sign, an identifier hyphen or an exponent.
	 */
	private static function is_math_operator( string $value, int $offset ): bool {
		if ( $offset < 1 || $offset + 1 >= strlen( $value ) ) {
			return false;
		}

		$prev = $value[ $offset - 1 ];
		$next = $value[ $offset + 1 ];

		// Needs a complete value on the left and something on the right.
		if ( preg_match( '/[0-9a-zA-Z%)\]]/', $prev ) !== 1 || $next === ' ' ) {
			return false;
		}

		// `1e-5` keeps its sign attached to the exponent.
		if ( ( $prev === 'e' || $prev === 'E' ) && $offset >= 2 && preg_match( '/[0-9.]/', $value[ $offset - 2 ] ) === 1 ) {
			return false;
		}

		return true;
	}

	/**
	 * Tailwind allows `w-[length:var(--x)]`; the hint itself is not CSS.
	 */
	private static function strip_data_type( string $value ): string {
		if ( preg_match( '/^([a-z-]+):(.+)$/s', $value, $matches ) !== 1 ) {
			return $value;
		}

		return in_array( $matches[1], self::DATA_TYPES, true ) ? trim( $matches[2] ) : $value;
	}

	/**
	 * Flip a resolved length so `-mt-4` mirrors `mt-4`.
	 */
	private static function negate( string $value ): ?string {
		if ( $value === '' || $value === 'auto' ) {
			return null;
		}

		if ( str_starts_with( $value, '-' ) ) {
			return trim( substr( $value, 1 ) );
		}

		if ( preg_match( '/^\d*\.?\d+(?:[a-z%]+)?$/i', $value ) === 1 ) {
			return '-' . $value;
		}

		return 'calc(' . $value . ' * -1)';
	}
}

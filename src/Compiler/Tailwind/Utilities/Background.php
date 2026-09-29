<?php
/**
 * Background utilities: colour, image, gradients, size, position, repeat,
 * clip, origin and attachment.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler\Tailwind\Utilities;

use DXAI_UI\Compiler\Tailwind\Candidate;
use DXAI_UI\Compiler\Tailwind\Theme;
use DXAI_UI\Compiler\Tailwind\Utility_Module;

/**
 * Owns `bg-*` plus the gradient stop utilities `from-*`, `via-*` and `to-*`.
 *
 * Gradients follow Tailwind v4's `--tw-gradient-*` custom property scheme so
 * that a direction utility and any combination of stops compose on the same
 * element. Because the engine cannot emit `@property` rules, every reference
 * carries the same fallback Tailwind registers as the property's initial
 * value, which keeps the shorthand valid when a stop is absent.
 */
final class Background implements Utility_Module {

	/** Interpolation used when no `/modifier` asks for another colour space. */
	private const INTERPOLATION = 'in oklab';

	/** Matches a CSS number, with or without a leading zero. */
	private const NUMBER = '(?:\d+(?:\.\d+)?|\.\d+)';

	/** Placeholder colour for an unset gradient stop. */
	private const STOP_NONE = '#0000';

	/** Suffix on `bg-linear-to-*` mapped to a gradient direction. */
	private const DIRECTIONS = array(
		't'  => 'to top',
		'tr' => 'to top right',
		'r'  => 'to right',
		'br' => 'to bottom right',
		'b'  => 'to bottom',
		'bl' => 'to bottom left',
		'l'  => 'to left',
		'tl' => 'to top left',
	);

	/** Utilities whose value never varies. */
	private const KEYWORDS = array(
		'bg-none'          => array( 'background-image' => 'none' ),

		'bg-auto'          => array( 'background-size' => 'auto' ),
		'bg-cover'         => array( 'background-size' => 'cover' ),
		'bg-contain'       => array( 'background-size' => 'contain' ),

		'bg-repeat'        => array( 'background-repeat' => 'repeat' ),
		'bg-no-repeat'     => array( 'background-repeat' => 'no-repeat' ),
		'bg-repeat-x'      => array( 'background-repeat' => 'repeat-x' ),
		'bg-repeat-y'      => array( 'background-repeat' => 'repeat-y' ),
		'bg-repeat-round'  => array( 'background-repeat' => 'round' ),
		'bg-repeat-space'  => array( 'background-repeat' => 'space' ),

		'bg-fixed'         => array( 'background-attachment' => 'fixed' ),
		'bg-local'         => array( 'background-attachment' => 'local' ),
		'bg-scroll'        => array( 'background-attachment' => 'scroll' ),

		'bg-clip-border'   => array( 'background-clip' => 'border-box' ),
		'bg-clip-padding'  => array( 'background-clip' => 'padding-box' ),
		'bg-clip-content'  => array( 'background-clip' => 'content-box' ),
		'bg-clip-text'     => array( 'background-clip' => 'text' ),

		'bg-origin-border'  => array( 'background-origin' => 'border-box' ),
		'bg-origin-padding' => array( 'background-origin' => 'padding-box' ),
		'bg-origin-content' => array( 'background-origin' => 'content-box' ),

		'bg-center'        => array( 'background-position' => 'center' ),
		'bg-top'           => array( 'background-position' => 'top' ),
		'bg-bottom'        => array( 'background-position' => 'bottom' ),
		'bg-left'          => array( 'background-position' => 'left' ),
		'bg-right'         => array( 'background-position' => 'right' ),
		'bg-top-left'      => array( 'background-position' => 'left top' ),
		'bg-top-right'     => array( 'background-position' => 'right top' ),
		'bg-bottom-left'   => array( 'background-position' => 'left bottom' ),
		'bg-bottom-right'  => array( 'background-position' => 'right bottom' ),
		'bg-left-top'      => array( 'background-position' => 'left top' ),
		'bg-left-bottom'   => array( 'background-position' => 'left bottom' ),
		'bg-right-top'     => array( 'background-position' => 'right top' ),
		'bg-right-bottom'  => array( 'background-position' => 'right bottom' ),
	);

	/** Colour keywords Tailwind resolves without touching the theme. */
	private const COLOR_KEYWORDS = array(
		'inherit'     => 'inherit',
		'current'     => 'currentColor',
		'transparent' => 'transparent',
	);

	/**
	 * @return array<string, string>|null
	 */
	public function resolve( Candidate $candidate, Theme $theme ): ?array {
		$base = $candidate->base;

		$stop = $this->gradient_stop( $candidate, $theme );
		if ( $stop !== null ) {
			return $stop;
		}

		$paint = $this->svg_paint( $candidate, $theme );
		if ( $paint !== null ) {
			return $paint;
		}

		if ( ! str_starts_with( $base, 'bg-' ) ) {
			return null;
		}

		if ( isset( self::KEYWORDS[ $base ] ) ) {
			if ( $candidate->negative || $candidate->modifier !== '' ) {
				return null;
			}

			return self::KEYWORDS[ $base ];
		}

		$gradient = $this->gradient( $candidate );
		if ( $gradient !== null ) {
			return $gradient;
		}

		$value = substr( $base, 3 );
		if ( $value === '' ) {
			return null;
		}

		foreach ( array( 'size' => 'background-size', 'position' => 'background-position' ) as $prefix => $property ) {
			if ( ! str_starts_with( $value, $prefix . '-' ) ) {
				continue;
			}
			if ( $candidate->modifier !== '' || $candidate->negative ) {
				return null;
			}

			$explicit = $this->free_value( substr( $value, strlen( $prefix ) + 1 ) );

			return $explicit === null ? null : array( $property => $explicit );
		}

		return $this->background_value( $candidate, $theme, $value );
	}

	/**
	 * @return array<string, string>
	 */
	public function keyframes(): array {
		return array();
	}

	/**
	 * `bg-[...]`, `bg-(...)` and every theme colour.
	 *
	 * @return array<string, string>|null
	 */
	private function background_value( Candidate $candidate, Theme $theme, string $value ): ?array {
		if ( self::is_wrapped( $value ) ) {
			$parsed = self::unwrap( $value );
			if ( $parsed === null ) {
				return null;
			}

			list( $hint, $inner ) = $parsed;

			$property = self::hinted_property( $hint );
			if ( $hint !== '' && $property === null ) {
				return null;
			}
			if ( $property === null ) {
				$property = self::is_image( $inner ) ? 'background-image' : 'background-color';
			}

			if ( $property === 'background-color' ) {
				$color = $this->apply_alpha( $inner, $candidate->modifier, $theme );

				return $color === null || $candidate->negative ? null : array( 'background-color' => $color );
			}

			if ( $candidate->modifier !== '' || $candidate->negative ) {
				return null;
			}

			return array( $property => $inner );
		}

		if ( $candidate->negative ) {
			return null;
		}

		$color = $this->theme_color( $theme, $value );
		if ( $color === null ) {
			return null;
		}

		$color = $this->apply_alpha( $color, $candidate->modifier, $theme );

		return $color === null ? null : array( 'background-color' => $color );
	}

	/**
	 * `bg-linear-to-r`, `bg-linear-45`, `bg-radial`, `bg-conic-180`, their
	 * arbitrary forms and the deprecated `bg-gradient-to-*` spelling.
	 *
	 * @return array<string, string>|null
	 */
	private function gradient( Candidate $candidate ): ?array {
		$base = $candidate->base;
		$kind = '';
		$rest = '';

		foreach ( array( 'bg-linear' => 'linear', 'bg-gradient' => 'linear', 'bg-radial' => 'radial', 'bg-conic' => 'conic' ) as $prefix => $name ) {
			if ( $base === $prefix || str_starts_with( $base, $prefix . '-' ) || str_starts_with( $base, $prefix . '[' ) || str_starts_with( $base, $prefix . '(' ) ) {
				$kind = $name;
				$rest = substr( $base, strlen( $prefix ) );
				break;
			}
		}

		if ( $kind === '' ) {
			return null;
		}

		if ( $rest !== '' && str_starts_with( $rest, '-' ) ) {
			$rest = substr( $rest, 1 );
		}

		$interpolation = $this->interpolation( $candidate->modifier );
		if ( $interpolation === null ) {
			return null;
		}

		$image    = $kind . '-gradient(var(--tw-gradient-stops))';
		$position = null;

		if ( $rest === '' ) {
			if ( $candidate->negative ) {
				return null;
			}
			$position = $interpolation;
		} elseif ( self::is_wrapped( $rest ) ) {
			$parsed = self::unwrap( $rest );
			if ( $parsed === null || $candidate->negative ) {
				return null;
			}

			$custom = $parsed[1];
			if ( str_contains( $custom, ',' ) ) {
				$position = $custom;
				$image    = $kind . '-gradient(var(--tw-gradient-stops,' . $custom . '))';
			} else {
				$position = self::has_interpolation( $custom ) ? $custom : $custom . ' ' . $interpolation;
			}
		} elseif ( 'linear' === $kind && str_starts_with( $rest, 'to-' ) && isset( self::DIRECTIONS[ substr( $rest, 3 ) ] ) ) {
			if ( $candidate->negative ) {
				return null;
			}
			$position = self::DIRECTIONS[ substr( $rest, 3 ) ] . ' ' . $interpolation;
		} elseif ( 'radial' !== $kind && preg_match( '/^\d+(?:\.\d+)?$/', $rest ) === 1 ) {
			$angle    = $candidate->negative ? 'calc(' . $rest . 'deg * -1)' : $rest . 'deg';
			$position = ( 'conic' === $kind ? 'from ' . $angle : $angle ) . ' ' . $interpolation;
		}

		if ( $position === null ) {
			return null;
		}

		return array(
			'--tw-gradient-position' => $position,
			'background-image'       => $image,
		);
	}

	/**
	 * `from-*`, `via-*` and `to-*`, both colour and position stops.
	 *
	 * @return array<string, string>|null
	 */
	private function gradient_stop( Candidate $candidate, Theme $theme ): ?array {
		$base = $candidate->base;
		$stop = '';
		$value = '';

		foreach ( array( 'from', 'via', 'to' ) as $name ) {
			if ( str_starts_with( $base, $name . '-' ) || str_starts_with( $base, $name . '[' ) || str_starts_with( $base, $name . '(' ) ) {
				$stop  = $name;
				$value = substr( $base, strlen( $name ) );
				break;
			}
		}

		if ( $stop === '' ) {
			return null;
		}

		if ( str_starts_with( $value, '-' ) ) {
			$value = substr( $value, 1 );
		}

		if ( $value === '' ) {
			return null;
		}

		/*
		 * `via-none` drops the middle stop again. It cannot come from the
		 * colour path — `none` is not a colour — so v4 registers it as a
		 * static utility that resets the stop list this module installs in
		 * `via_stops()` to its guaranteed-invalid initial value, which sends
		 * every `var(--tw-gradient-via-stops, <base>)` reference back to the
		 * two-stop fallback. It restates no `--tw-gradient-stops`, takes no
		 * `/interpolation`, and has no `from-none` or `to-none` counterpart.
		 */
		if ( 'via' === $stop && 'none' === $value ) {
			if ( $candidate->negative || $candidate->modifier !== '' ) {
				return null;
			}

			return array( '--tw-gradient-via-stops' => 'initial' );
		}

		$position = $this->stop_position( $candidate, $value );
		if ( $position !== null ) {
			return array( '--tw-gradient-' . $stop . '-position' => $position );
		}

		if ( $candidate->negative ) {
			return null;
		}

		$color = $this->stop_color( $candidate, $theme, $value );
		if ( $color === null ) {
			return null;
		}

		if ( 'via' === $stop ) {
			return array(
				'--tw-gradient-via'       => $color,
				'--tw-gradient-via-stops' => self::via_stops(),
				'--tw-gradient-stops'     => 'var(--tw-gradient-via-stops)',
			);
		}

		return array(
			'--tw-gradient-' . $stop => $color,
			'--tw-gradient-stops'    => 'var(--tw-gradient-via-stops,' . self::base_stops() . ')',
		);
	}

	/**
	 * The stop list used when no `via-*` utility is present.
	 */
	private static function base_stops(): string {
		return 'var(--tw-gradient-position,' . self::INTERPOLATION . '),'
			. 'var(--tw-gradient-from,' . self::STOP_NONE . ') var(--tw-gradient-from-position,0%),'
			. 'var(--tw-gradient-to,' . self::STOP_NONE . ') var(--tw-gradient-to-position,100%)';
	}

	/**
	 * The stop list a `via-*` utility installs, which adds the middle stop.
	 */
	private static function via_stops(): string {
		return 'var(--tw-gradient-position,' . self::INTERPOLATION . '),'
			. 'var(--tw-gradient-from,' . self::STOP_NONE . ') var(--tw-gradient-from-position,0%),'
			. 'var(--tw-gradient-via,' . self::STOP_NONE . ') var(--tw-gradient-via-position,50%),'
			. 'var(--tw-gradient-to,' . self::STOP_NONE . ') var(--tw-gradient-to-position,100%)';
	}

	/**
	 * Percentage stops such as `from-50%`, `via-[40%]` or `to-[calc(50%+2rem)]`.
	 */
	private function stop_position( Candidate $candidate, string $value ): ?string {
		if ( $candidate->modifier !== '' ) {
			return null;
		}

		$position = null;

		if ( self::is_wrapped( $value ) ) {
			$parsed = self::unwrap( $value );
			if ( $parsed === null ) {
				return null;
			}

			list( $hint, $inner ) = $parsed;

			if ( 'position' === $hint || 'length' === $hint || 'percentage' === $hint ) {
				$position = $inner;
			} elseif ( '' === $hint && self::is_dimension( $inner ) ) {
				$position = $inner;
			}
		} elseif ( preg_match( '/^' . self::NUMBER . '%$/', $value ) === 1 ) {
			$position = $value;
		}

		if ( $position === null ) {
			return null;
		}

		return $candidate->negative ? self::negate( $position ) : $position;
	}

	/**
	 * SVG paint: `fill-current`, `fill-none`, `stroke-white/60`, `stroke-2`.
	 *
	 * These resolved to nothing at all, so an inline icon kept the browser's
	 * default black fill however the design coloured it. Geometry diffing
	 * cannot see a wrong colour, and none of the designs on hand used them —
	 * comparing against Tailwind's whole utility surface is what surfaced it.
	 *
	 * Housed here because paint is a colour and this module already carries the
	 * theme lookup and the `/opacity` folding.
	 *
	 * @return array<string, string>|null
	 */
	private function svg_paint( Candidate $candidate, Theme $theme ): ?array {
		foreach ( array( 'fill', 'stroke' ) as $property ) {
			$base = $candidate->base;
			if ( ! str_starts_with( $base, $property . '-' )
				&& ! str_starts_with( $base, $property . '[' )
				&& ! str_starts_with( $base, $property . '(' ) ) {
				continue;
			}

			if ( $candidate->negative ) {
				return null;
			}

			$value = substr( $base, strlen( $property ) );
			$value = str_starts_with( $value, '-' ) ? substr( $value, 1 ) : $value;
			if ( $value === '' ) {
				return null;
			}

			if ( $value === 'none' ) {
				return $candidate->modifier === '' ? array( $property => 'none' ) : null;
			}

			/*
			 * `stroke-2` is a width, not a colour — the only place the two
			 * families differ, and upstream emits the number unitless.
			 */
			if ( $property === 'stroke' && $candidate->modifier === ''
				&& preg_match( '/^' . self::NUMBER . '$/', $value ) === 1 ) {
				return array( 'stroke-width' => $value );
			}

			$color = $this->stop_color( $candidate, $theme, $value );

			return $color === null ? null : array( $property => $color );
		}

		return null;
	}

	/**
	 * Colour for a gradient stop, honouring `[...]`, `(...)` and `/opacity`.
	 */
	private function stop_color( Candidate $candidate, Theme $theme, string $value ): ?string {
		if ( self::is_wrapped( $value ) ) {
			$parsed = self::unwrap( $value );
			if ( $parsed === null ) {
				return null;
			}

			list( $hint, $inner ) = $parsed;

			if ( '' !== $hint && 'color' !== $hint ) {
				return null;
			}

			return $this->apply_alpha( $inner, $candidate->modifier, $theme );
		}

		$color = $this->theme_color( $theme, $value );

		return $color === null ? null : $this->apply_alpha( $color, $candidate->modifier, $theme );
	}

	/**
	 * A named colour: the three CSS keywords first, then the theme.
	 */
	private function theme_color( Theme $theme, string $value ): ?string {
		if ( isset( self::COLOR_KEYWORDS[ $value ] ) ) {
			return self::COLOR_KEYWORDS[ $value ];
		}

		return $theme->color( $value );
	}

	/**
	 * `bg-size-[...]` and `bg-position-[...]`, which take no theme lookup.
	 */
	private function free_value( string $value ): ?string {
		if ( ! self::is_wrapped( $value ) ) {
			return null;
		}

		$parsed = self::unwrap( $value );

		return $parsed === null ? null : $parsed[1];
	}

	/**
	 * Turn a `/modifier` into the gradient's colour interpolation clause.
	 */
	private function interpolation( string $modifier ): ?string {
		if ( $modifier === '' ) {
			return self::INTERPOLATION;
		}

		if ( self::is_wrapped( $modifier ) ) {
			$parsed = self::unwrap( $modifier );
			if ( $parsed === null || $parsed[0] !== '' ) {
				return null;
			}
			$value = $parsed[1];
		} else {
			$value = $modifier;
		}

		if ( $value === '' ) {
			return null;
		}

		return str_starts_with( $value, 'in ' ) ? $value : 'in ' . $value;
	}

	/**
	 * Fold an opacity modifier into a colour the way Tailwind v4 does.
	 *
	 * Bare modifiers are percentages (`/90`), bracketed ones are alpha
	 * channels (`/[0.03]`), and a modifier that is itself an expression is
	 * scaled to a percentage inside the `color-mix()`.
	 */
	private function apply_alpha( string $color, string $modifier, Theme $theme ): ?string {
		if ( $color === '' ) {
			return null;
		}
		if ( $modifier === '' ) {
			return $color;
		}

		$fraction = false;

		if ( self::is_wrapped( $modifier ) ) {
			$parsed = self::unwrap( $modifier );
			if ( $parsed === null || $parsed[0] !== '' ) {
				return null;
			}
			$value    = $parsed[1];
			$fraction = true;
		} else {
			$value = $modifier;
			if ( preg_match( '/^' . self::NUMBER . '$/', $value ) !== 1 ) {
				$resolved = $theme->value( 'opacity', $value );
				if ( $resolved === null ) {
					return null;
				}
				$value    = $resolved;
				$fraction = true;
			}
		}

		if ( $value === '' ) {
			return null;
		}

		if ( preg_match( '/^(' . self::NUMBER . ')%$/', $value, $match ) === 1 ) {
			return $theme->with_alpha( $color, $match[1] );
		}

		if ( preg_match( '/^' . self::NUMBER . '$/', $value ) === 1 ) {
			$number = (float) $value;
			if ( $fraction && $number <= 1.0 ) {
				$number *= 100.0;
			}

			return $theme->with_alpha( $color, self::format_number( $number ) );
		}

		return 'color-mix(in oklab, ' . $color . ' calc(' . $value . ' * 100%), transparent)';
	}

	/**
	 * Which property an explicit `[type:value]` hint targets.
	 */
	private static function hinted_property( string $hint ): ?string {
		$map = array(
			'color'    => 'background-color',
			'image'    => 'background-image',
			'url'      => 'background-image',
			'length'   => 'background-size',
			'size'     => 'background-size',
			'position' => 'background-position',
		);

		return $map[ $hint ] ?? null;
	}

	/**
	 * True for a length or percentage, the data types a gradient stop position
	 * accepts. Math functions count, since `to-[calc(100%_-_2rem)]` is a
	 * position while `to-[color-mix(in_oklab,red_50%,blue)]` is a colour.
	 */
	private static function is_dimension( string $value ): bool {
		if ( preg_match( '/^-?(?:\d+(?:\.\d+)?|\.\d+)(?:%|px|r?em|v(?:h|w|min|max|i|b)|ch|ex|cap|rlh|lh|pt|pc|cm|mm|q|in)$/i', $value ) === 1 ) {
			return true;
		}

		return preg_match( '/^(?:calc|min|max|clamp)\s*\(/i', $value ) === 1;
	}

	/**
	 * True when an arbitrary gradient position already names a colour space,
	 * so the default `in oklab` must not be appended a second time.
	 */
	private static function has_interpolation( string $value ): bool {
		return preg_match( '/(?:^|\s)in\s+[a-z]/i', $value ) === 1;
	}

	/**
	 * True when the arbitrary value is an image rather than a colour.
	 */
	private static function is_image( string $value ): bool {
		if ( 'none' === $value ) {
			return true;
		}

		return preg_match( '/^(?:url|image-set|-webkit-image-set|image|cross-fade|element|paint|(?:repeating-)?(?:linear|radial|conic)-gradient)\s*\(/i', $value ) === 1;
	}

	/**
	 * True for `[...]` and `(...)` values, including modifiers.
	 */
	private static function is_wrapped( string $value ): bool {
		return ( str_starts_with( $value, '[' ) && str_ends_with( $value, ']' ) )
			|| ( str_starts_with( $value, '(' ) && str_ends_with( $value, ')' ) );
	}

	/**
	 * Split a wrapped value into its optional `type:` hint and its value,
	 * expanding the `(--var)` shorthand into a `var()` reference.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private static function unwrap( string $value ): ?array {
		$parenthesised = str_starts_with( $value, '(' );
		$inner         = Candidate::decode_arbitrary( substr( $value, 1, -1 ) );
		if ( $inner === '' ) {
			return null;
		}

		$hint = '';
		if ( preg_match( '/^([a-z-]+):(.*)$/s', $inner, $match ) === 1 ) {
			$hint  = $match[1];
			$inner = trim( $match[2] );
		}

		if ( $inner === '' ) {
			return null;
		}

		if ( $parenthesised && ! str_starts_with( $inner, '--' ) ) {
			return null;
		}

		if ( str_starts_with( $inner, '--' ) ) {
			$inner = 'var(' . $inner . ')';
		}

		return array( $hint, $inner );
	}

	/**
	 * Negate a resolved value, wrapping anything that is not a plain number.
	 */
	private static function negate( string $value ): string {
		if ( str_starts_with( $value, '-' ) ) {
			return substr( $value, 1 );
		}

		if ( preg_match( '/^\d+(?:\.\d+)?[a-z%]*$/i', $value ) === 1 ) {
			return '-' . $value;
		}

		return 'calc(' . $value . ' * -1)';
	}

	/**
	 * Trim float noise so `0.03 * 100` prints as `3`, not `3.0000000000000004`.
	 */
	private static function format_number( float $number ): string {
		$formatted = number_format( $number, 6, '.', '' );
		$formatted = rtrim( rtrim( $formatted, '0' ), '.' );

		return $formatted === '' || $formatted === '-' ? '0' : $formatted;
	}
}

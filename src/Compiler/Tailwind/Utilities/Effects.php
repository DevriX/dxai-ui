<?php
/**
 * Effect utilities: shadows, opacity, blend modes, filters and backdrop
 * filters.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler\Tailwind\Utilities;

use DXAI_UI\Compiler\Tailwind\Candidate;
use DXAI_UI\Compiler\Tailwind\Theme;
use DXAI_UI\Compiler\Tailwind\Utility_Module;

/**
 * Owns `shadow-*`, `inset-shadow-*`, `drop-shadow-*`, `opacity-*`,
 * `mix-blend-*`, `bg-blend-*`, the `filter` family and the `backdrop-filter`
 * family.
 *
 * Every one of these composes in Tailwind v4 through private `--tw-*` custom
 * properties, which `Preflight` seeds with the same defaults v4 registers via
 * `@property`. A size utility writes the geometry into `--tw-shadow` with the
 * colour slot left as `var(--tw-shadow-color, <original>)`, and a colour
 * utility writes only `--tw-shadow-color`, so `shadow-lg shadow-ara-navy/40`
 * behaves the way it does in a real Tailwind build. Filters work the same way:
 * each utility sets one `--tw-blur`-style property and restates the full
 * `filter` shorthand so any combination of them stacks.
 */
final class Effects implements Utility_Module {

	/** The `box-shadow` shorthand every shadow utility restates. */
	private const BOX_SHADOW = 'var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow)';

	/**
	 * v3's shorthand, which has two slots rather than four.
	 *
	 * v4 added the inset-shadow and inset-ring layers. They are transparent
	 * zero-size placeholders, so the rendering is identical either way — but
	 * `box-shadow` is a computed value, and against a v3 build every shadowed
	 * element reported a difference that was two empty layers and nothing else.
	 */
	private const BOX_SHADOW_V3 = 'var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), var(--tw-shadow)';

	/** The `filter` shorthand every filter utility restates. */
	private const FILTER = 'var(--tw-blur,) var(--tw-brightness,) var(--tw-contrast,) var(--tw-grayscale,) var(--tw-hue-rotate,) var(--tw-invert,) var(--tw-saturate,) var(--tw-sepia,) var(--tw-drop-shadow,)';

	/** The `backdrop-filter` shorthand every backdrop utility restates. */
	private const BACKDROP_FILTER = 'var(--tw-backdrop-blur,) var(--tw-backdrop-brightness,) var(--tw-backdrop-contrast,) var(--tw-backdrop-grayscale,) var(--tw-backdrop-hue-rotate,) var(--tw-backdrop-invert,) var(--tw-backdrop-opacity,) var(--tw-backdrop-saturate,) var(--tw-backdrop-sepia,)';

	/** The transparent shadow `shadow-none` installs. */
	private const SHADOW_NONE = '0 0 #0000';

	/** Filter namespaces, longest-lived first; none is a prefix of another. */
	private const FILTERS = array(
		'blur',
		'brightness',
		'contrast',
		'grayscale',
		'hue-rotate',
		'invert',
		'saturate',
		'sepia',
	);

	/** `backdrop-opacity-*` exists where plain `opacity-*` sets the property. */
	private const BACKDROP_FILTERS = array(
		'blur',
		'brightness',
		'contrast',
		'grayscale',
		'hue-rotate',
		'invert',
		'opacity',
		'saturate',
		'sepia',
	);

	/** Filters whose bare form means "fully applied". */
	private const SATURATING_FILTERS = array( 'grayscale', 'invert', 'sepia' );

	/** Blend modes shared by `mix-blend-*` and `bg-blend-*`. */
	private const BLEND_MODES = array(
		'normal'      => 'normal',
		'multiply'    => 'multiply',
		'screen'      => 'screen',
		'overlay'     => 'overlay',
		'darken'      => 'darken',
		'lighten'     => 'lighten',
		'color-dodge' => 'color-dodge',
		'color-burn'  => 'color-burn',
		'hard-light'  => 'hard-light',
		'soft-light'  => 'soft-light',
		'difference'  => 'difference',
		'exclusion'   => 'exclusion',
		'hue'         => 'hue',
		'saturation'  => 'saturation',
		'color'       => 'color',
		'luminosity'  => 'luminosity',
	);

	/** Compositing modes only `mix-blend-*` offers. */
	private const MIX_BLEND_MODES = array(
		'plus-darker'  => 'plus-darker',
		'plus-lighter' => 'plus-lighter',
	);

	/**
	 * v4's default scales, used when the design's `@theme` does not override
	 * them. `DEFAULT` backs the bare `shadow`, `drop-shadow` and `blur`
	 * spellings a generated design still tends to use.
	 */
	private const SCALES = array(
		'shadow'       => array(
			'DEFAULT' => '0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1)',
			'2xs'     => '0 1px rgb(0 0 0 / 0.05)',
			'xs'      => '0 1px 2px 0 rgb(0 0 0 / 0.05)',
			'sm'      => '0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1)',
			'md'      => '0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1)',
			'lg'      => '0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1)',
			'xl'      => '0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1)',
			'2xl'     => '0 25px 50px -12px rgb(0 0 0 / 0.25)',
			'inner'   => 'inset 0 2px 4px 0 rgb(0 0 0 / 0.05)',
		),
		'inset-shadow' => array(
			'DEFAULT' => 'inset 0 2px 4px rgb(0 0 0 / 0.05)',
			'2xs'     => 'inset 0 1px rgb(0 0 0 / 0.05)',
			'xs'      => 'inset 0 1px 1px rgb(0 0 0 / 0.05)',
			'sm'      => 'inset 0 2px 4px rgb(0 0 0 / 0.05)',
		),
		'drop-shadow'  => array(
			'DEFAULT' => '0 1px 2px rgb(0 0 0 / 0.1), 0 1px 1px rgb(0 0 0 / 0.06)',
			'xs'      => '0 1px 1px rgb(0 0 0 / 0.05)',
			'sm'      => '0 1px 2px rgb(0 0 0 / 0.15)',
			'md'      => '0 3px 3px rgb(0 0 0 / 0.12)',
			'lg'      => '0 4px 4px rgb(0 0 0 / 0.15)',
			'xl'      => '0 9px 7px rgb(0 0 0 / 0.1)',
			'2xl'     => '0 25px 25px rgb(0 0 0 / 0.15)',
		),
		'blur'         => array(
			'DEFAULT' => '8px',
			'xs'      => '4px',
			'sm'      => '8px',
			'md'      => '12px',
			'lg'      => '16px',
			'xl'      => '24px',
			'2xl'     => '40px',
			'3xl'     => '64px',
		),
	);

	/** Colour keywords Tailwind resolves without touching the theme. */
	private const COLOR_KEYWORDS = array(
		'inherit'     => 'inherit',
		'current'     => 'currentColor',
		'transparent' => 'transparent',
	);

	/** Type hints accepted on an arbitrary shadow geometry. */
	private const SHADOW_HINTS = array( 'shadow', 'drop-shadow', 'inset-shadow' );

	/** Type hints accepted on an arbitrary filter argument. */
	private const NUMERIC_HINTS = array( '', 'length', 'percentage', 'number', 'angle' );

	/**
	 * @return array<string, string>|null
	 */
	public function resolve( Candidate $candidate, Theme $theme ): ?array {
		$base = $candidate->base;

		$rest = self::segment( $base, 'opacity' );
		if ( $rest !== null ) {
			return $this->opacity( $candidate, $theme, $rest );
		}

		foreach ( array( 'mix-blend' => 'mix-blend-mode', 'bg-blend' => 'background-blend-mode' ) as $prefix => $property ) {
			$rest = self::segment( $base, $prefix );
			if ( $rest !== null ) {
				return $this->blend( $candidate, $property, $rest, 'mix-blend' === $prefix );
			}
		}

		foreach ( array( 'inset-shadow', 'drop-shadow', 'shadow' ) as $kind ) {
			$rest = self::segment( $base, $kind );
			if ( $rest !== null ) {
				return $this->shadow( $candidate, $theme, $kind, $rest );
			}
		}

		if ( str_starts_with( $base, 'backdrop-' ) ) {
			return $this->filter_family( $candidate, $theme, substr( $base, strlen( 'backdrop-' ) ), true );
		}

		return $this->filter_family( $candidate, $theme, $base, false );
	}

	/**
	 * @return array<string, string>
	 */
	public function keyframes(): array {
		return array();
	}

	/**
	 * `opacity-70`, `opacity-[0.03]` and `opacity-(--my-opacity)`.
	 *
	 * @return array<string, string>|null
	 */
	private function opacity( Candidate $candidate, Theme $theme, string $rest ): ?array {
		if ( $candidate->negative || $candidate->modifier !== '' || $rest === '' ) {
			return null;
		}

		if ( self::is_wrapped( $rest ) ) {
			$parsed = self::unwrap( $rest );
			if ( $parsed === null || ! in_array( $parsed[0], self::NUMERIC_HINTS, true ) ) {
				return null;
			}

			return array( 'opacity' => $parsed[1] );
		}

		if ( self::is_number( $rest ) ) {
			return (float) $rest > 100.0 ? null : array( 'opacity' => $rest . '%' );
		}

		$value = $theme->value( 'opacity', $rest );

		return $value === null ? null : array( 'opacity' => $value );
	}

	/**
	 * `mix-blend-*` and `bg-blend-*`.
	 *
	 * @return array<string, string>|null
	 */
	private function blend( Candidate $candidate, string $property, string $rest, bool $mix ): ?array {
		if ( $candidate->negative || $candidate->modifier !== '' || $rest === '' ) {
			return null;
		}

		if ( isset( self::BLEND_MODES[ $rest ] ) ) {
			return array( $property => self::BLEND_MODES[ $rest ] );
		}

		if ( $mix && isset( self::MIX_BLEND_MODES[ $rest ] ) ) {
			return array( $property => self::MIX_BLEND_MODES[ $rest ] );
		}

		return null;
	}

	/**
	 * `shadow-*`, `inset-shadow-*` and `drop-shadow-*`, in both their geometry
	 * and their colour spellings.
	 *
	 * @return array<string, string>|null
	 */
	private function shadow( Candidate $candidate, Theme $theme, string $kind, string $rest ): ?array {
		if ( $candidate->negative ) {
			return null;
		}

		$drop      = 'drop-shadow' === $kind;
		$variable  = '--tw-' . $kind;
		$color_var = $variable . '-color';

		if ( 'none' === $rest ) {
			if ( $candidate->modifier !== '' ) {
				return null;
			}

			if ( $drop ) {
				return array(
					'--tw-drop-shadow' => '',
					'filter'           => self::FILTER,
				);
			}

			return array(
				$variable    => self::SHADOW_NONE,
				'box-shadow' => $theme->is_legacy() ? self::BOX_SHADOW_V3 : self::BOX_SHADOW,
			);
		}

		/*
		 * `initial` is not a colour, so v4 cannot reach it through the same
		 * theme lookup as `shadow-red-500`: it registers `shadow-initial` and
		 * `inset-shadow-initial` as static utilities instead, ahead of the
		 * functional handler. They put the colour slot back to the
		 * guaranteed-invalid initial value `@property` gives it, so every
		 * `var(--tw-shadow-color, <original>)` falls back to the scale's own
		 * colour, and they restate no `box-shadow` — the geometry is untouched.
		 * Being static, they take no `/alpha`. v4 registers no
		 * `drop-shadow-initial` at all, so the `$drop` kind is excluded here
		 * because the class does not exist — not because it is unfinished.
		 */
		if ( 'initial' === $rest && ! $drop ) {
			if ( $candidate->modifier !== '' ) {
				return null;
			}

			return array( $color_var => 'initial' );
		}

		$geometry = null;
		$color    = null;

		if ( self::is_wrapped( $rest ) ) {
			$parsed = self::unwrap( $rest );
			if ( $parsed === null ) {
				return null;
			}

			list( $hint, $inner, $parenthesised ) = $parsed;

			if ( 'color' === $hint ) {
				$color = $inner;
			} elseif ( in_array( $hint, self::SHADOW_HINTS, true ) ) {
				$geometry = $inner;
			} elseif ( '' === $hint ) {
				if ( ! $parenthesised && self::is_color_value( $inner ) ) {
					$color = $inner;
				} else {
					$geometry = $inner;
				}
			} else {
				return null;
			}
		} else {
			$geometry = $this->scale( $theme, $kind, $rest === '' ? 'DEFAULT' : $rest );

			if ( $geometry === null ) {
				if ( $rest === '' ) {
					return null;
				}

				$color = $this->theme_color( $theme, $rest );
				if ( $color === null ) {
					return null;
				}
			}
		}

		if ( $color !== null ) {
			$color = $this->apply_alpha( $color, $candidate->modifier, $theme );
			if ( $color === null ) {
				return null;
			}

			if ( $drop ) {
				return array(
					'--tw-drop-shadow-color' => $color,
					'--tw-drop-shadow'       => 'var(--tw-drop-shadow-size)',
				);
			}

			return array( $color_var => $color );
		}

		$parts = array();
		foreach ( Candidate::split_top_level( (string) $geometry, ',' ) as $piece ) {
			$piece = trim( $piece );
			if ( $piece === '' ) {
				continue;
			}

			if ( 'inset-shadow' === $kind && ! preg_match( '/^inset\b/i', $piece ) ) {
				$piece = 'inset ' . $piece;
			}

			$piece   = $this->compose_color( $piece, $color_var, $candidate->modifier, $theme );
			$parts[] = $drop ? 'drop-shadow(' . $piece . ')' : $piece;
		}

		if ( $parts === array() ) {
			return null;
		}

		if ( $drop ) {
			return array(
				'--tw-drop-shadow-size' => implode( ' ', $parts ),
				'--tw-drop-shadow'      => 'var(--tw-drop-shadow-size)',
				'filter'                => self::FILTER,
			);
		}

		$declarations = array(
			$variable    => implode( ', ', $parts ),
			'box-shadow' => $theme->is_legacy() ? self::BOX_SHADOW_V3 : self::BOX_SHADOW,
		);

		/*
		 * One name, two tokens, and v3 applies both.
		 *
		 * `shadow-card` against a config that declares `boxShadow.card` AND
		 * `colors.card` — which every shadcn config does, since `card` is one
		 * of its surface colours — is registered by v3 as the geometry utility
		 * and as a shadow-COLOUR utility. Both land in the same rule, colour
		 * last, so the shadow paints in `colors.card` and the colours the
		 * boxShadow string named are discarded. Measured against a v3 build:
		 * three cards whose shadow is white, not the grey hairline the string
		 * asks for. v4 has no shadow-colour collision because its utilities
		 * come from separate namespaces.
		 */
		if ( $theme->is_legacy() && ! self::is_wrapped( $rest ) && $rest !== '' ) {
			$collision = $this->theme_color( $theme, $rest );
			if ( $collision !== null ) {
				$collision = $this->apply_alpha( $collision, $candidate->modifier, $theme );
				if ( $collision !== null ) {
					$declarations[ $color_var ] = $collision;
				}
			}
		}

		return $declarations;
	}

	/**
	 * Rewrite one shadow so its colour comes from `--tw-*-shadow-color`, with
	 * the scale's own colour as the fallback. A shadow with no colour at all
	 * gains the slot, since CSS would otherwise pin it to `currentcolor`.
	 */
	private function compose_color( string $shadow, string $color_var, string $modifier, Theme $theme ): string {
		$tokens = self::tokenize( $shadow );
		$last   = count( $tokens ) - 1;
		$index  = null;

		foreach ( $tokens as $position => $token ) {
			if ( self::is_color_token( $token ) || ( $position === $last && str_starts_with( strtolower( $token ), 'var(' ) ) ) {
				$index = $position;
			}
		}

		if ( $index === null ) {
			$tokens[] = 'var(' . $color_var . ', currentcolor)';

			return implode( ' ', $tokens );
		}

		$color            = $this->apply_alpha( $tokens[ $index ], $modifier, $theme ) ?? $tokens[ $index ];
		$tokens[ $index ] = 'var(' . $color_var . ', ' . $color . ')';

		return implode( ' ', $tokens );
	}

	/**
	 * Dispatch inside the `filter` or `backdrop-filter` family.
	 *
	 * @return array<string, string>|null
	 */
	private function filter_family( Candidate $candidate, Theme $theme, string $base, bool $backdrop ): ?array {
		$rest = self::segment( $base, 'filter' );
		if ( $rest !== null ) {
			return $this->filter_shorthand( $candidate, $rest, $backdrop );
		}

		foreach ( $backdrop ? self::BACKDROP_FILTERS : self::FILTERS as $name ) {
			$rest = self::segment( $base, $name );
			if ( $rest === null ) {
				continue;
			}

			return $this->filter( $candidate, $theme, $name, $rest, $backdrop );
		}

		return null;
	}

	/**
	 * `filter-none`, `backdrop-filter-none`, their arbitrary forms and the bare
	 * spelling that only re-declares the shorthand.
	 *
	 * @return array<string, string>|null
	 */
	private function filter_shorthand( Candidate $candidate, string $rest, bool $backdrop ): ?array {
		if ( $candidate->negative || $candidate->modifier !== '' ) {
			return null;
		}

		if ( 'none' === $rest ) {
			return $this->shorthand( 'none', $backdrop );
		}

		if ( $rest === '' ) {
			return $this->shorthand( null, $backdrop );
		}

		if ( ! self::is_wrapped( $rest ) ) {
			return null;
		}

		$parsed = self::unwrap( $rest );
		if ( $parsed === null || $parsed[0] !== '' ) {
			return null;
		}

		return $this->shorthand( $parsed[1], $backdrop );
	}

	/**
	 * One filter utility: its own `--tw-*` property plus the shorthand.
	 *
	 * @return array<string, string>|null
	 */
	private function filter( Candidate $candidate, Theme $theme, string $name, string $rest, bool $backdrop ): ?array {
		if ( $candidate->modifier !== '' ) {
			return null;
		}
		if ( $candidate->negative && 'hue-rotate' !== $name ) {
			return null;
		}

		$value = $this->filter_value( $candidate, $theme, $name, $rest );
		if ( $value === null ) {
			return null;
		}

		$declarations = array( ( $backdrop ? '--tw-backdrop-' : '--tw-' ) . $name => $value );
		foreach ( $this->shorthand( null, $backdrop ) as $property => $shorthand ) {
			$declarations[ $property ] = $shorthand;
		}

		return $declarations;
	}

	/**
	 * The `blur(8px)`-style function one filter utility installs, or an empty
	 * string for the `-none` form, which drops out of the shorthand.
	 */
	private function filter_value( Candidate $candidate, Theme $theme, string $name, string $rest ): ?string {
		if ( 'none' === $rest ) {
			return $candidate->negative ? null : '';
		}

		if ( self::is_wrapped( $rest ) ) {
			$parsed = self::unwrap( $rest );
			if ( $parsed === null || ! in_array( $parsed[0], self::NUMERIC_HINTS, true ) ) {
				return null;
			}

			$inner = $candidate->negative ? self::negate( $parsed[1] ) : $parsed[1];

			return $name . '(' . $inner . ')';
		}

		if ( 'blur' === $name ) {
			$length = $this->scale( $theme, 'blur', $rest === '' ? 'DEFAULT' : $rest );

			return $length === null ? null : 'blur(' . $length . ')';
		}

		if ( 'hue-rotate' === $name ) {
			if ( ! self::is_number( $rest ) ) {
				return null;
			}

			$angle = $rest . 'deg';

			return 'hue-rotate(' . ( $candidate->negative ? self::negate( $angle ) : $angle ) . ')';
		}

		if ( $rest === '' ) {
			return in_array( $name, self::SATURATING_FILTERS, true ) ? $name . '(100%)' : null;
		}

		return self::is_number( $rest ) ? $name . '(' . $rest . '%)' : null;
	}

	/**
	 * The shorthand declarations a filter utility restates. `null` composes the
	 * `--tw-*` properties; a string sets both spellings outright.
	 *
	 * @return array<string, string>
	 */
	private function shorthand( ?string $value, bool $backdrop ): array {
		if ( ! $backdrop ) {
			return array( 'filter' => $value ?? self::FILTER );
		}

		$composed = $value ?? self::BACKDROP_FILTER;

		return array(
			'-webkit-backdrop-filter' => $composed,
			'backdrop-filter'         => $composed,
		);
	}

	/**
	 * A named token from a theme namespace, falling back to v4's own scale.
	 */
	private function scale( Theme $theme, string $namespace, string $token ): ?string {
		if ( 'DEFAULT' !== $token ) {
			$value = $theme->value( $namespace, $token );
			if ( $value !== null && $value !== '' ) {
				return $value;
			}
		}

		return self::SCALES[ $namespace ][ $token ] ?? null;
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
			if ( ! self::is_number( $value ) ) {
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

		if ( preg_match( '/^(\d+(?:\.\d+)?)%$/', $value, $match ) === 1 ) {
			return $theme->with_alpha( $color, $match[1] );
		}

		if ( self::is_number( $value ) ) {
			$number = (float) $value;
			if ( $fraction && $number <= 1.0 ) {
				$number *= 100.0;
			}

			return $theme->with_alpha( $color, self::format_number( $number ) );
		}

		return 'color-mix(in oklab, ' . $color . ' calc(' . $value . ' * 100%), transparent)';
	}

	/**
	 * The part of a utility after its namespace, or null when it belongs to
	 * another one. `shadow` yields '', `shadow-xl` yields 'xl' and
	 * `shadow-[0_1px_2px_red]` yields '[0 1px 2px red]'.
	 */
	private static function segment( string $base, string $prefix ): ?string {
		if ( $base === $prefix ) {
			return '';
		}

		if ( str_starts_with( $base, $prefix . '-' ) ) {
			return substr( $base, strlen( $prefix ) + 1 );
		}

		if ( str_starts_with( $base, $prefix . '[' ) || str_starts_with( $base, $prefix . '(' ) ) {
			return substr( $base, strlen( $prefix ) );
		}

		return null;
	}

	/**
	 * Split a shadow into its top-level tokens, so `rgb(0 0 0 / 0.1)` stays
	 * one token.
	 *
	 * @return array<int, string>
	 */
	private static function tokenize( string $value ): array {
		$tokens = array();
		foreach ( Candidate::split_top_level( $value, ' ' ) as $token ) {
			$token = trim( $token );
			if ( $token !== '' ) {
				$tokens[] = $token;
			}
		}

		return $tokens;
	}

	/**
	 * True when a whole arbitrary value is a colour rather than a shadow.
	 */
	private static function is_color_value( string $value ): bool {
		$tokens = self::tokenize( $value );

		return count( $tokens ) === 1 && self::is_color_token( $tokens[0] );
	}

	/**
	 * True for the colour token of a shadow. Tested by exclusion: anything that
	 * is a length, a number or a shadow keyword is geometry, so hex triplets,
	 * colour functions and the 148 CSS colour names all fall through to true
	 * without a lookup table.
	 */
	private static function is_color_token( string $token ): bool {
		$token = strtolower( $token );

		if ( in_array( $token, array( 'inset', 'none', 'initial', 'unset', 'revert' ), true ) ) {
			return false;
		}

		if ( in_array( $token, array( 'currentcolor', 'transparent', 'inherit' ), true ) ) {
			return true;
		}

		if ( preg_match( '/^#[0-9a-f]{3,8}$/', $token ) === 1 ) {
			return true;
		}

		if ( preg_match( '/^(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color|color-mix|light-dark)\(/', $token ) === 1 ) {
			return true;
		}

		return preg_match( '/^[a-z]+$/', $token ) === 1;
	}

	/**
	 * True for `[...]` and `(...)` values, including modifiers.
	 */
	private static function is_wrapped( string $value ): bool {
		return ( str_starts_with( $value, '[' ) && str_ends_with( $value, ']' ) )
			|| ( str_starts_with( $value, '(' ) && str_ends_with( $value, ')' ) );
	}

	/**
	 * Split a wrapped value into its optional `type:` hint, its value and
	 * whether it was the `(--var)` shorthand, which expands to a `var()`
	 * reference and is never read as a colour.
	 *
	 * @return array{0: string, 1: string, 2: bool}|null
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

		if ( $parenthesised ) {
			if ( ! str_starts_with( $inner, '--' ) ) {
				return null;
			}
			$inner = 'var(' . $inner . ')';
		}

		return array( $hint, $inner, $parenthesised );
	}

	/**
	 * True for a plain unsigned decimal.
	 */
	private static function is_number( string $value ): bool {
		return preg_match( '/^\d+(?:\.\d+)?$/', $value ) === 1;
	}

	/**
	 * Negate a resolved value, wrapping anything that is not a plain length.
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

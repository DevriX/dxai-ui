<?php
/**
 * Transition, animation and transform utilities.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler\Tailwind\Utilities;

use DXAI_UI\Compiler\Tailwind\Candidate;
use DXAI_UI\Compiler\Tailwind\Theme;
use DXAI_UI\Compiler\Tailwind\Utility_Module;

/**
 * Owns everything that moves: `transition-*`, `duration-*`, `ease-*`,
 * `delay-*`, `animate-*` and the whole transform family (`scale-*`,
 * `rotate-*`, `translate-*`, `skew-*`, `transform*`, `origin-*`,
 * `perspective-*`, `backface-*`, `will-change-*`).
 *
 * Transforms follow Tailwind v4's composition scheme so that several
 * utilities on one element combine instead of overwriting each other. The 2D
 * pieces go through the individual `translate`, `scale` and `rotate`
 * properties fed by `--tw-translate-x`, `--tw-scale-x` and friends, while the
 * 3D rotations and the skews accumulate in `--tw-rotate-x`/`--tw-skew-x` and
 * are replayed by a single `transform` shorthand. Preflight registers the
 * defaults for those custom properties, so the shorthands stay valid when
 * only one axis is set.
 *
 * Every `transition-*` utility also carries v4's default duration and timing
 * function, each read through `--tw-duration`/`--tw-ease` so a later
 * `duration-*` or `ease-*` on the same element still wins. Both references
 * fall back to a literal, which keeps the CSS correct in a design whose
 * `@theme` never declared the `--default-transition-*` tokens.
 */
final class Motion implements Utility_Module {

	/** Timing function a `transition-*` utility installs. */
	private const TIMING = 'var(--tw-ease, var(--default-transition-timing-function, cubic-bezier(0.4, 0, 0.2, 1)))';

	/** Duration a `transition-*` utility installs. */
	private const DURATION = 'var(--tw-duration, var(--default-transition-duration, 150ms))';

	/** Slots replayed by the `transform` shorthand. */
	private const TRANSFORM_SLOTS = 'var(--tw-rotate-x,) var(--tw-rotate-y,) var(--tw-rotate-z,) var(--tw-skew-x,) var(--tw-skew-y,)';

	/** Slots replayed by the `translate` property. */
	private const TRANSLATE_SLOTS = 'var(--tw-translate-x) var(--tw-translate-y)';

	/** `translate` including the Z axis. */
	private const TRANSLATE_SLOTS_3D = 'var(--tw-translate-x) var(--tw-translate-y) var(--tw-translate-z)';

	/** Slots replayed by the `scale` property. */
	private const SCALE_SLOTS = 'var(--tw-scale-x) var(--tw-scale-y)';

	/** `scale` including the Z axis. */
	private const SCALE_SLOTS_3D = 'var(--tw-scale-x) var(--tw-scale-y) var(--tw-scale-z)';

	/** The colour-ish properties `transition` and `transition-colors` animate. */
	private const COLOR_PROPERTIES = 'color, background-color, border-color, outline-color, text-decoration-color, fill, stroke, --tw-gradient-from, --tw-gradient-via, --tw-gradient-to';

	/**
	 * Named `transition-*` utilities mapped to their property list.
	 *
	 * @var array<string, string>
	 */
	private const TRANSITION_PROPERTIES = array(
		'transition'           => self::COLOR_PROPERTIES . ', opacity, box-shadow, transform, translate, scale, rotate, filter, -webkit-backdrop-filter, backdrop-filter, display, content-visibility, overlay, pointer-events',
		'transition-all'       => 'all',
		'transition-colors'    => self::COLOR_PROPERTIES,
		'transition-opacity'   => 'opacity',
		'transition-shadow'    => 'box-shadow',
		'transition-transform' => 'transform, translate, scale, rotate',
	);

	/**
	 * Utilities whose declarations never vary.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const KEYWORDS = array(
		'transition-none'       => array( 'transition-property' => 'none' ),
		'transition-discrete'   => array( 'transition-behavior' => 'allow-discrete' ),
		'transition-normal'     => array( 'transition-behavior' => 'normal' ),

		/*
		 * Only the variable. These two exist to *undo* a duration or easing
		 * so the `transition-*` shorthand falls back to the theme default;
		 * also writing `transition-duration: initial` overrode that shorthand
		 * and left the element with no transition at all.
		 */
		'duration-initial'      => array( '--tw-duration' => 'initial' ),
		'ease-initial'          => array( '--tw-ease' => 'initial' ),
		'ease-linear'           => array(
			'--tw-ease'                  => 'linear',
			'transition-timing-function' => 'linear',
		),

		'animate-none'          => array( 'animation' => 'none' ),

		'transform'             => array( 'transform' => self::TRANSFORM_SLOTS ),
		'transform-cpu'         => array( 'transform' => self::TRANSFORM_SLOTS ),
		'transform-gpu'         => array( 'transform' => 'translateZ(0) ' . self::TRANSFORM_SLOTS ),
		'transform-none'        => array( 'transform' => 'none' ),
		'transform-flat'        => array( 'transform-style' => 'flat' ),
		'transform-3d'          => array( 'transform-style' => 'preserve-3d' ),
		'transform-content'     => array( 'transform-box' => 'content-box' ),
		'transform-border'      => array( 'transform-box' => 'border-box' ),
		'transform-fill'        => array( 'transform-box' => 'fill-box' ),
		'transform-stroke'      => array( 'transform-box' => 'stroke-box' ),
		'transform-view'        => array( 'transform-box' => 'view-box' ),

		'scale-none'            => array( 'scale' => 'none' ),
		'scale-3d'              => array( 'scale' => self::SCALE_SLOTS_3D ),
		'rotate-none'           => array( 'rotate' => 'none' ),
		'translate-none'        => array( 'translate' => 'none' ),
		'translate-3d'          => array( 'translate' => self::TRANSLATE_SLOTS_3D ),

		'perspective-none'      => array( 'perspective' => 'none' ),

		'backface-hidden'       => array( 'backface-visibility' => 'hidden' ),
		'backface-visible'      => array( 'backface-visibility' => 'visible' ),

		'will-change-auto'      => array( 'will-change' => 'auto' ),
		'will-change-scroll'    => array( 'will-change' => 'scroll-position' ),
		'will-change-contents'  => array( 'will-change' => 'contents' ),
		'will-change-transform' => array( 'will-change' => 'transform' ),
	);

	/**
	 * `origin-*` and `perspective-origin-*` keywords. Both spelling orders are
	 * accepted because v4 moved from `right-top` to `top-right`.
	 *
	 * @var array<string, string>
	 */
	private const POSITIONS = array(
		'center'       => 'center',
		'top'          => 'top',
		'right'        => 'right',
		'bottom'       => 'bottom',
		'left'         => 'left',
		'top-left'     => 'top left',
		'top-right'    => 'top right',
		'bottom-left'  => 'bottom left',
		'bottom-right' => 'bottom right',
		'left-top'     => 'left top',
		'right-top'    => 'right top',
		'left-bottom'  => 'left bottom',
		'right-bottom' => 'right bottom',
	);

	/**
	 * Tailwind's own `--ease-*` values, used when the theme resolves none.
	 *
	 * @var array<string, string>
	 */
	private const EASES = array(
		'in'     => 'cubic-bezier(0.4, 0, 1, 1)',
		'out'    => 'cubic-bezier(0, 0, 0.2, 1)',
		'in-out' => 'cubic-bezier(0.4, 0, 0.2, 1)',
	);

	/**
	 * Tailwind's own `--perspective-*` values.
	 *
	 * @var array<string, string>
	 */
	private const PERSPECTIVES = array(
		'dramatic' => '100px',
		'near'     => '300px',
		'normal'   => '500px',
		'midrange' => '800px',
		'distant'  => '1200px',
	);

	/**
	 * Tailwind's own `--animate-*` values.
	 *
	 * @var array<string, string>
	 */
	private const ANIMATIONS = array(
		'spin'   => 'spin 1s linear infinite',
		'ping'   => 'ping 1s cubic-bezier(0, 0, 0.2, 1) infinite',
		'pulse'  => 'pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite',
		'bounce' => 'bounce 1s infinite',
	);

	/**
	 * Keyframes for the built-in animations.
	 *
	 * @var array<string, string>
	 */
	private const ANIMATION_KEYFRAMES = array(
		'spin'   => "\tto { transform: rotate(360deg); }",
		'ping'   => "\t75%, 100% { transform: scale(2); opacity: 0; }",
		'pulse'  => "\t50% { opacity: 0.5; }",
		'bounce' => "\t0%, 100% { transform: translateY(-25%); animation-timing-function: cubic-bezier(0.8, 0, 1, 1); }\n\t50% { transform: none; animation-timing-function: cubic-bezier(0, 0, 0.2, 1); }",
	);

	/** @var array<string, bool> Built-in animations this compile referenced. */
	private array $animations = array();

	/**
	 * @return array<string, string>|null
	 */
	public function resolve( Candidate $candidate, Theme $theme ): ?array {
		$base = $candidate->base;

		if ( ! $candidate->negative && '' === $candidate->modifier && isset( self::KEYWORDS[ $base ] ) ) {
			return self::KEYWORDS[ $base ];
		}

		if ( 'transition' === $base || str_starts_with( $base, 'transition-' ) ) {
			return self::transition( $candidate );
		}

		if ( str_starts_with( $base, 'duration-' ) ) {
			return self::duration( $candidate, $theme );
		}

		if ( str_starts_with( $base, 'ease-' ) ) {
			return self::ease( $candidate, $theme );
		}

		if ( str_starts_with( $base, 'delay-' ) ) {
			return self::delay( $candidate );
		}

		if ( str_starts_with( $base, 'animate-' ) ) {
			return $this->animate( $candidate, $theme );
		}

		if ( str_starts_with( $base, 'scale-' ) ) {
			return self::scale( $candidate );
		}

		if ( str_starts_with( $base, 'rotate-' ) ) {
			return self::rotate( $candidate );
		}

		if ( str_starts_with( $base, 'translate-' ) ) {
			return self::translate( $candidate, $theme );
		}

		if ( str_starts_with( $base, 'skew-' ) ) {
			return self::skew( $candidate );
		}

		if ( str_starts_with( $base, 'transform-' ) ) {
			return self::transform( $candidate );
		}

		if ( str_starts_with( $base, 'origin-' ) ) {
			return self::origin( $candidate );
		}

		if ( str_starts_with( $base, 'perspective-origin-' ) ) {
			return self::perspective_origin( $candidate );
		}

		if ( str_starts_with( $base, 'perspective-' ) ) {
			return self::perspective( $candidate, $theme );
		}

		if ( str_starts_with( $base, 'will-change-' ) ) {
			return self::will_change( $candidate );
		}

		return null;
	}

	/**
	 * @return array<string, string>
	 */
	public function keyframes(): array {
		$out = array();
		foreach ( array_keys( $this->animations ) as $name ) {
			if ( isset( self::ANIMATION_KEYFRAMES[ $name ] ) ) {
				$out[ $name ] = self::ANIMATION_KEYFRAMES[ $name ];
			}
		}

		return $out;
	}

	/**
	 * `transition`, the named property groups and `transition-[...]`. All of
	 * them install the default duration and timing function alongside the
	 * property list, the way v4 does.
	 *
	 * @return array<string, string>|null
	 */
	private static function transition( Candidate $candidate ): ?array {
		if ( $candidate->negative || '' !== $candidate->modifier ) {
			return null;
		}

		$base = $candidate->base;
		if ( isset( self::TRANSITION_PROPERTIES[ $base ] ) ) {
			$property = self::TRANSITION_PROPERTIES[ $base ];
		} else {
			$property = self::explicit_value( substr( $base, strlen( 'transition-' ) ), $candidate );
			if ( null === $property ) {
				return null;
			}
		}

		return array(
			'transition-property'        => $property,
			'transition-timing-function' => self::TIMING,
			'transition-duration'        => self::DURATION,
		);
	}

	/**
	 * `duration-300`, `duration-[2s]` and any `--duration-*` theme token.
	 *
	 * @return array<string, string>|null
	 */
	private static function duration( Candidate $candidate, Theme $theme ): ?array {
		if ( $candidate->negative || '' !== $candidate->modifier ) {
			return null;
		}

		$token = substr( $candidate->base, strlen( 'duration-' ) );
		$value = self::explicit_value( $token, $candidate );
		if ( null === $value ) {
			$value = self::milliseconds( $token ) ?? $theme->value( 'duration', $token );
		}

		if ( null === $value || '' === $value ) {
			return null;
		}

		return array(
			'--tw-duration'       => $value,
			'transition-duration' => $value,
		);
	}

	/**
	 * `ease-in`, `ease-out`, `ease-in-out` and `ease-[...]`.
	 *
	 * @return array<string, string>|null
	 */
	private static function ease( Candidate $candidate, Theme $theme ): ?array {
		if ( $candidate->negative || '' !== $candidate->modifier ) {
			return null;
		}

		$token = substr( $candidate->base, strlen( 'ease-' ) );
		$value = self::explicit_value( $token, $candidate );
		if ( null === $value ) {
			$value = $theme->value( 'ease', $token ) ?? ( self::EASES[ $token ] ?? null );
		}

		if ( null === $value || '' === $value ) {
			return null;
		}

		return array(
			'--tw-ease'                  => $value,
			'transition-timing-function' => $value,
		);
	}

	/**
	 * `delay-150` and `delay-[300ms]`.
	 *
	 * @return array<string, string>|null
	 */
	private static function delay( Candidate $candidate ): ?array {
		if ( $candidate->negative || '' !== $candidate->modifier ) {
			return null;
		}

		$token = substr( $candidate->base, strlen( 'delay-' ) );
		$value = self::explicit_value( $token, $candidate ) ?? self::milliseconds( $token );
		if ( null === $value || '' === $value ) {
			return null;
		}

		return array( 'transition-delay' => $value );
	}

	/**
	 * `animate-spin` and friends, any `--animate-*` theme token and
	 * `animate-[...]`. Built-in animations record their keyframes so the
	 * engine can emit them even when the design's own CSS has none.
	 *
	 * @return array<string, string>|null
	 */
	private function animate( Candidate $candidate, Theme $theme ): ?array {
		if ( $candidate->negative || '' !== $candidate->modifier ) {
			return null;
		}

		$token = substr( $candidate->base, strlen( 'animate-' ) );
		$value = self::explicit_value( $token, $candidate );

		if ( null === $value ) {
			$value = $theme->value( 'animate', $token );
			if ( null === $value && isset( self::ANIMATIONS[ $token ] ) ) {
				$value = self::ANIMATIONS[ $token ];
			}
			if ( isset( self::ANIMATION_KEYFRAMES[ $token ] ) ) {
				$this->animations[ $token ] = true;
			}
		}

		if ( null === $value || '' === $value ) {
			return null;
		}

		return array( 'animation' => $value );
	}

	/**
	 * `scale-95`, `scale-x-110`, `scale-[1.04]`, `-scale-50`.
	 *
	 * @return array<string, string>|null
	 */
	private static function scale( Candidate $candidate ): ?array {
		if ( '' !== $candidate->modifier ) {
			return null;
		}

		$axis  = self::axis( $candidate->base, 'scale' );
		$token = self::token( $candidate->base, 'scale', $axis );
		if ( null === $token ) {
			return null;
		}

		$value = self::explicit_value( $token, $candidate );
		if ( null === $value ) {
			$number = self::number( $token );
			if ( null === $number ) {
				return null;
			}
			$value = $number . '%';
		}

		if ( $candidate->negative ) {
			$value = self::negate( $value );
		}

		if ( 'x' === $axis ) {
			return array(
				'--tw-scale-x' => $value,
				'scale'        => self::SCALE_SLOTS,
			);
		}

		if ( 'y' === $axis ) {
			return array(
				'--tw-scale-y' => $value,
				'scale'        => self::SCALE_SLOTS,
			);
		}

		if ( 'z' === $axis ) {
			return array(
				'--tw-scale-z' => $value,
				'scale'        => self::SCALE_SLOTS_3D,
			);
		}

		/*
		 * An arbitrary value sets the property outright, a theme value goes
		 * through the axis slots — upstream splits it exactly there, and the
		 * difference is observable: `scale-x-50 scale-[1.02]` overrides on one
		 * path and merges on the other.
		 *
		 * Worth stating because I had this wrong in both directions. Matching
		 * only the `scale-[1.02]` a design happened to use turned all 22
		 * theme-valued `scale-*` and `-scale-*` classes into divergences, which
		 * is how running the comparison over Tailwind's whole surface earns its
		 * keep over running it on one design.
		 */
		if ( $candidate->is_arbitrary() ) {
			return array( 'scale' => $value );
		}

		return array(
			'--tw-scale-x' => $value,
			'--tw-scale-y' => $value,
			'--tw-scale-z' => $value,
			'scale'        => self::SCALE_SLOTS,
		);
	}

	/**
	 * `rotate-45` uses the standalone `rotate` property, while the axis
	 * variants join the `transform` chain the way v4 splits them.
	 *
	 * @return array<string, string>|null
	 */
	private static function rotate( Candidate $candidate ): ?array {
		if ( '' !== $candidate->modifier ) {
			return null;
		}

		$axis  = self::axis( $candidate->base, 'rotate' );
		$token = self::token( $candidate->base, 'rotate', $axis );
		if ( null === $token ) {
			return null;
		}

		$value = self::angle( $token, $candidate );
		if ( null === $value ) {
			return null;
		}

		if ( '' === $axis ) {
			return array( 'rotate' => $value );
		}

		return array(
			'--tw-rotate-' . $axis => 'rotate' . strtoupper( $axis ) . '(' . $value . ')',
			'transform'            => self::TRANSFORM_SLOTS,
		);
	}

	/**
	 * `translate-x-4`, `-translate-y-0.5`, `translate-x-1/2`,
	 * `translate-y-full`, `translate-[3px]`.
	 *
	 * @return array<string, string>|null
	 */
	private static function translate( Candidate $candidate, Theme $theme ): ?array {
		$axis  = self::axis( $candidate->base, 'translate' );
		$token = self::token( $candidate->base, 'translate', $axis );
		if ( null === $token ) {
			return null;
		}

		$value = self::explicit_value( $token, $candidate );

		if ( null === $value && '' !== $candidate->modifier ) {
			$value = self::fraction( $token, $candidate->modifier );
			if ( null === $value ) {
				return null;
			}
		}

		if ( null === $value ) {
			if ( '' !== $candidate->modifier ) {
				return null;
			}
			$value = $theme->spacing( $token );
		}

		if ( null === $value || '' === $value || 'auto' === $value ) {
			return null;
		}

		if ( $candidate->negative ) {
			$value = self::negate( $value );
		}

		if ( 'x' === $axis ) {
			return array(
				'--tw-translate-x' => $value,
				'translate'        => self::TRANSLATE_SLOTS,
			);
		}

		if ( 'y' === $axis ) {
			return array(
				'--tw-translate-y' => $value,
				'translate'        => self::TRANSLATE_SLOTS,
			);
		}

		if ( 'z' === $axis ) {
			return array(
				'--tw-translate-z' => $value,
				'translate'        => self::TRANSLATE_SLOTS_3D,
			);
		}

		return array(
			'--tw-translate-x' => $value,
			'--tw-translate-y' => $value,
			'translate'        => self::TRANSLATE_SLOTS,
		);
	}

	/**
	 * `skew-3`, `skew-x-6`, `-skew-y-12`, `skew-[10deg]`.
	 *
	 * @return array<string, string>|null
	 */
	private static function skew( Candidate $candidate ): ?array {
		if ( '' !== $candidate->modifier ) {
			return null;
		}

		$axis  = self::axis( $candidate->base, 'skew' );
		$token = self::token( $candidate->base, 'skew', $axis );
		if ( null === $token || 'z' === $axis ) {
			return null;
		}

		$value = self::angle( $token, $candidate );
		if ( null === $value ) {
			return null;
		}

		if ( 'x' === $axis ) {
			return array(
				'--tw-skew-x' => 'skewX(' . $value . ')',
				'transform'   => self::TRANSFORM_SLOTS,
			);
		}

		if ( 'y' === $axis ) {
			return array(
				'--tw-skew-y' => 'skewY(' . $value . ')',
				'transform'   => self::TRANSFORM_SLOTS,
			);
		}

		return array(
			'--tw-skew-x' => 'skewX(' . $value . ')',
			'--tw-skew-y' => 'skewY(' . $value . ')',
			'transform'   => self::TRANSFORM_SLOTS,
		);
	}

	/**
	 * `transform-[matrix(...)]`, the only `transform-*` shape the keyword table
	 * does not already cover.
	 *
	 * @return array<string, string>|null
	 */
	private static function transform( Candidate $candidate ): ?array {
		if ( $candidate->negative || '' !== $candidate->modifier ) {
			return null;
		}

		$value = self::explicit_value( substr( $candidate->base, strlen( 'transform-' ) ), $candidate );

		return null === $value ? null : array( 'transform' => $value );
	}

	/**
	 * @return array<string, string>|null
	 */
	private static function origin( Candidate $candidate ): ?array {
		$value = self::position( $candidate, 'origin-' );

		return null === $value ? null : array( 'transform-origin' => $value );
	}

	/**
	 * @return array<string, string>|null
	 */
	private static function perspective_origin( Candidate $candidate ): ?array {
		$value = self::position( $candidate, 'perspective-origin-' );

		return null === $value ? null : array( 'perspective-origin' => $value );
	}

	/**
	 * `perspective-normal`, any `--perspective-*` theme token and
	 * `perspective-[500px]`.
	 *
	 * @return array<string, string>|null
	 */
	private static function perspective( Candidate $candidate, Theme $theme ): ?array {
		if ( $candidate->negative || '' !== $candidate->modifier ) {
			return null;
		}

		$token = substr( $candidate->base, strlen( 'perspective-' ) );
		$value = self::explicit_value( $token, $candidate );
		if ( null === $value ) {
			$value = $theme->value( 'perspective', $token ) ?? ( self::PERSPECTIVES[ $token ] ?? null );
		}

		if ( null === $value || '' === $value ) {
			return null;
		}

		return array( 'perspective' => $value );
	}

	/**
	 * Only `will-change-[...]` reaches here; the four keywords are in the
	 * keyword table and anything else is not a Tailwind utility.
	 *
	 * @return array<string, string>|null
	 */
	private static function will_change( Candidate $candidate ): ?array {
		if ( $candidate->negative || '' !== $candidate->modifier ) {
			return null;
		}

		$value = self::explicit_value( substr( $candidate->base, strlen( 'will-change-' ) ), $candidate );

		return null === $value ? null : array( 'will-change' => $value );
	}

	/**
	 * Shared reader for `origin-*` and `perspective-origin-*`.
	 */
	private static function position( Candidate $candidate, string $prefix ): ?string {
		if ( $candidate->negative || '' !== $candidate->modifier ) {
			return null;
		}

		$token = substr( $candidate->base, strlen( $prefix ) );
		if ( isset( self::POSITIONS[ $token ] ) ) {
			return self::POSITIONS[ $token ];
		}

		$value = self::explicit_value( $token, $candidate );

		return '' === $value ? null : $value;
	}

	/**
	 * Axis suffix a transform utility carries, `''` when it sets both.
	 */
	private static function axis( string $base, string $prefix ): string {
		foreach ( array( 'x', 'y', 'z' ) as $axis ) {
			if ( str_starts_with( $base, $prefix . '-' . $axis . '-' ) ) {
				return $axis;
			}
		}

		return '';
	}

	/**
	 * Value token left after the prefix and any axis, or null when empty.
	 */
	private static function token( string $base, string $prefix, string $axis ): ?string {
		$head  = '' === $axis ? $prefix . '-' : $prefix . '-' . $axis . '-';
		$token = substr( $base, strlen( $head ) );

		return '' === $token ? null : $token;
	}

	/**
	 * An angle for `rotate-*` and `skew-*`: a bare number becomes degrees and
	 * a leading `-` flips it.
	 */
	private static function angle( string $token, Candidate $candidate ): ?string {
		$value = self::explicit_value( $token, $candidate );
		if ( null === $value ) {
			$number = self::number( $token );
			if ( null === $number ) {
				return null;
			}
			$value = $number . 'deg';
		}

		return $candidate->negative ? self::negate( $value ) : $value;
	}

	/**
	 * A value written as `[...]`, or with v4's `(--var)` shorthand. Null when
	 * the token carries neither.
	 */
	private static function explicit_value( string $token, Candidate $candidate ): ?string {
		if ( str_starts_with( $token, '[' ) && str_ends_with( $token, ']' ) ) {
			$value = $candidate->arbitrary_value();

			return '' === $value ? null : $value;
		}

		if ( str_starts_with( $token, '(' ) && str_ends_with( $token, ')' ) ) {
			$inner = Candidate::decode_arbitrary( substr( $token, 1, -1 ) );

			return '' === $inner ? null : 'var(' . $inner . ')';
		}

		return null;
	}

	/**
	 * `translate-x-1/2` and friends: the numerator is the token, the
	 * denominator arrives as the candidate's modifier.
	 */
	private static function fraction( string $token, string $modifier ): ?string {
		if ( preg_match( '/^\d+(?:\.\d+)?$/', $token ) !== 1 || preg_match( '/^\d+(?:\.\d+)?$/', $modifier ) !== 1 ) {
			return null;
		}

		$denominator = (float) $modifier;
		if ( 0.0 === $denominator ) {
			return null;
		}

		return self::format_number( ( (float) $token / $denominator ) * 100 ) . '%';
	}

	/**
	 * A bare number, kept as authored so `scale-105` stays `105`.
	 */
	private static function number( string $token ): ?string {
		return preg_match( '/^\d+(?:\.\d+)?$/', $token ) === 1 ? $token : null;
	}

	/**
	 * A bare number read as milliseconds, the unit v4 gives `duration-*` and
	 * `delay-*`.
	 */
	private static function milliseconds( string $token ): ?string {
		$number = self::number( $token );

		return null === $number ? null : $number . 'ms';
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
	 * Trim float noise so `1 / 3 * 100` prints as `33.333333`, not a repeating
	 * tail of digits.
	 */
	private static function format_number( float $number ): string {
		$formatted = number_format( $number, 6, '.', '' );
		$formatted = rtrim( rtrim( $formatted, '0' ), '.' );

		return '' === $formatted || '-' === $formatted ? '0' : $formatted;
	}
}

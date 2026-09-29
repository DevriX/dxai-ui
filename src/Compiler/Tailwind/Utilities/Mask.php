<?php
/**
 * Mask utilities: the `mask-image` composition system and the mask layer
 * properties.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler\Tailwind\Utilities;

use DXAI_UI\Compiler\Tailwind\Candidate;
use DXAI_UI\Compiler\Tailwind\Theme;
use DXAI_UI\Compiler\Tailwind\Utility_Module;

/**
 * Owns every `mask-*` utility, plus the only two negatable shapes in the
 * family, `-mask-linear-<angle>` and `-mask-conic-<angle>`.
 *
 * The composition half exists because `mask-image` is one property while
 * Tailwind lets four edge fades, a linear, a radial and a conic gradient all
 * land on the same element. It resolves that by making every participating
 * utility restate four identical things — the `mask-image` shorthand,
 * `mask-composite: intersect`, the sub-layer list it belongs to and the one
 * gradient it defines — and then write only the single `--tw-mask-*` slot the
 * class actually names. Two such classes on one element therefore agree on
 * everything except their own slot, which is what makes `mask-b-from-20
 * mask-b-to-80` compose into a single two-stop fade instead of the second
 * rule replacing the first. Restated per class rather than emitted once
 * because each class is its own selector and there is no shared rule to hang
 * it on.
 *
 * The three colour and position slots each gradient reads but the class does
 * not set come from the `@property` initial values in Preflight. Unlike the
 * gradient utilities in Background, these are written bare, with no inline
 * `var()` fallback — that is what upstream emits, and it means the
 * registrations are load-bearing: without them the `linear-gradient()` is
 * invalid and the mask silently does not apply at all.
 *
 * The layer half — clip, origin, mode, type, size, position, repeat and the
 * composite keywords — is a flat keyword table. Those classes deliberately do
 * *not* restate `mask-image` or `mask-composite`; `mask-circle` and
 * `mask-radial-at-top-left` act purely through the slots that the radial stop
 * list splices in, so they compose with a `mask-radial-from-*` on the same
 * element and are inert without one.
 */
final class Mask implements Utility_Module {

	/** Matches a CSS number, with or without a leading zero. */
	private const NUMBER = '(?:\d+(?:\.\d+)?|\.\d+)';

	/**
	 * The wider number shape upstream's data-type regexes use, sign and
	 * exponent included.
	 *
	 * Deliberately not `NUMBER`: that one describes the bare values Tailwind's
	 * own scales are keyed by, which are never signed. This one has to reject
	 * `[+10%]` and `[1e2%]` as percentages the way upstream does, and it can
	 * only do that by recognising them first.
	 */
	private const CSS_NUMBER = '[+-]?\d*\.?\d+(?:[eE][+-]?\d+)?';

	/** The shorthand and composite mode every composition utility restates. */
	private const COMPOSITION = array(
		'mask-image'     => 'var(--tw-mask-linear), var(--tw-mask-radial), var(--tw-mask-conic)',
		'mask-composite' => 'intersect',
	);

	/**
	 * The edge sub-layer list, identical for all six edge tokens.
	 *
	 * It is the layer stack, not the direction the class fades in, so
	 * `mask-t-from-*` and `mask-l-from-*` write the same string; it is not
	 * rotated per edge.
	 */
	private const EDGE_LAYERS = 'var(--tw-mask-left), var(--tw-mask-right), var(--tw-mask-bottom), var(--tw-mask-top)';

	/**
	 * Edge token mapped to the sides it drives, in the order upstream emits
	 * them. `x` writes right before left while `y` writes top before bottom,
	 * so the two axes are not mirror images of one another and the pairs
	 * cannot share one table.
	 */
	private const EDGES = array(
		't' => array( 'top' ),
		'r' => array( 'right' ),
		'b' => array( 'bottom' ),
		'l' => array( 'left' ),
		'x' => array( 'right', 'left' ),
		'y' => array( 'top', 'bottom' ),
	);

	/**
	 * The stop list each named gradient installs.
	 *
	 * Radial splices in the shape, size and position slots, which is how
	 * `mask-circle`, `mask-radial-closest-side` and `mask-radial-at-top-left`
	 * take effect without restating anything themselves. Conic opens with a
	 * literal `from`, which belongs to the stop list rather than to the angle
	 * utility — `mask-conic-45` writes a bare angle with no `from`.
	 */
	private const GRADIENT_STOPS = array(
		'linear' => 'var(--tw-mask-linear-position), var(--tw-mask-linear-from-color) var(--tw-mask-linear-from-position), var(--tw-mask-linear-to-color) var(--tw-mask-linear-to-position)',
		'radial' => 'var(--tw-mask-radial-shape) var(--tw-mask-radial-size) at var(--tw-mask-radial-position), var(--tw-mask-radial-from-color) var(--tw-mask-radial-from-position), var(--tw-mask-radial-to-color) var(--tw-mask-radial-to-position)',
		'conic'  => 'from var(--tw-mask-conic-position), var(--tw-mask-conic-from-color) var(--tw-mask-conic-from-position), var(--tw-mask-conic-to-color) var(--tw-mask-conic-to-position)',
	);

	/**
	 * Utilities whose value never varies, in upstream's emission order.
	 *
	 * Checked before any prefix dispatch, which is what keeps the family's
	 * near-collisions apart: `mask-contain` is resolved here and never offered
	 * to the conic parser, `mask-center` never reaches an edge match, and
	 * `mask-radial-closest-side` never looks like a radial stop.
	 */
	private const KEYWORDS = array(
		// Emitted after the whole composition block, so it wins the
		// `mask-image` cascade against every `mask-b-from-*` on the element.
		'mask-none'                  => array( 'mask-image' => 'none' ),

		'mask-circle'                => array( '--tw-mask-radial-shape' => 'circle' ),
		'mask-ellipse'               => array( '--tw-mask-radial-shape' => 'ellipse' ),

		'mask-radial-closest-corner'  => array( '--tw-mask-radial-size' => 'closest-corner' ),
		'mask-radial-closest-side'    => array( '--tw-mask-radial-size' => 'closest-side' ),
		'mask-radial-farthest-corner' => array( '--tw-mask-radial-size' => 'farthest-corner' ),
		'mask-radial-farthest-side'   => array( '--tw-mask-radial-size' => 'farthest-side' ),

		/*
		 * Two words in class-name order here — `mask-radial-at-top-left` is
		 * `top left`. The named `mask-position` table below reverses them
		 * instead, so the two must not share a lookup.
		 */
		'mask-radial-at-bottom'       => array( '--tw-mask-radial-position' => 'bottom' ),
		'mask-radial-at-bottom-left'  => array( '--tw-mask-radial-position' => 'bottom left' ),
		'mask-radial-at-bottom-right' => array( '--tw-mask-radial-position' => 'bottom right' ),
		'mask-radial-at-center'       => array( '--tw-mask-radial-position' => 'center' ),
		'mask-radial-at-left'         => array( '--tw-mask-radial-position' => 'left' ),
		'mask-radial-at-right'        => array( '--tw-mask-radial-position' => 'right' ),
		'mask-radial-at-top'          => array( '--tw-mask-radial-position' => 'top' ),
		'mask-radial-at-top-left'     => array( '--tw-mask-radial-position' => 'top left' ),
		'mask-radial-at-top-right'    => array( '--tw-mask-radial-position' => 'top right' ),

		// Also emitted after the composition block, which writes `intersect`.
		'mask-add'                   => array( 'mask-composite' => 'add' ),
		'mask-exclude'               => array( 'mask-composite' => 'exclude' ),
		'mask-intersect'             => array( 'mask-composite' => 'intersect' ),
		'mask-subtract'              => array( 'mask-composite' => 'subtract' ),

		// `mask-match` is the one entry in the family whose value is not its
		// own suffix.
		'mask-alpha'                 => array( 'mask-mode' => 'alpha' ),
		'mask-luminance'             => array( 'mask-mode' => 'luminance' ),
		'mask-match'                 => array( 'mask-mode' => 'match-source' ),

		// A different property from `mask-alpha` / `mask-luminance` above,
		// despite the shared value words.
		'mask-type-alpha'            => array( 'mask-type' => 'alpha' ),
		'mask-type-luminance'        => array( 'mask-type' => 'luminance' ),

		'mask-auto'                  => array( 'mask-size' => 'auto' ),
		'mask-contain'               => array( 'mask-size' => 'contain' ),
		'mask-cover'                 => array( 'mask-size' => 'cover' ),

		// Six boxes plus `no-clip`, which is spelled `mask-no-clip` and not
		// `mask-clip-no-clip`. Wider than Background's four-entry `bg-clip`
		// table, and there is no `mask-clip-text`.
		'mask-clip-border'           => array( 'mask-clip' => 'border-box' ),
		'mask-clip-content'          => array( 'mask-clip' => 'content-box' ),
		'mask-clip-fill'             => array( 'mask-clip' => 'fill-box' ),
		'mask-clip-padding'          => array( 'mask-clip' => 'padding-box' ),
		'mask-clip-stroke'           => array( 'mask-clip' => 'stroke-box' ),
		'mask-clip-view'             => array( 'mask-clip' => 'view-box' ),
		'mask-no-clip'               => array( 'mask-clip' => 'no-clip' ),

		// Two-word values reversed relative to the class name, the opposite
		// convention from `mask-radial-at-*`.
		'mask-bottom'                => array( 'mask-position' => 'bottom' ),
		'mask-bottom-left'           => array( 'mask-position' => 'left bottom' ),
		'mask-bottom-right'          => array( 'mask-position' => 'right bottom' ),
		'mask-center'                => array( 'mask-position' => 'center' ),
		'mask-left'                  => array( 'mask-position' => 'left' ),
		'mask-right'                 => array( 'mask-position' => 'right' ),
		'mask-top'                   => array( 'mask-position' => 'top' ),
		'mask-top-left'              => array( 'mask-position' => 'left top' ),
		'mask-top-right'             => array( 'mask-position' => 'right top' ),

		'mask-no-repeat'             => array( 'mask-repeat' => 'no-repeat' ),
		'mask-repeat'                => array( 'mask-repeat' => 'repeat' ),
		'mask-repeat-round'          => array( 'mask-repeat' => 'round' ),
		'mask-repeat-space'          => array( 'mask-repeat' => 'space' ),
		'mask-repeat-x'              => array( 'mask-repeat' => 'repeat-x' ),
		'mask-repeat-y'              => array( 'mask-repeat' => 'repeat-y' ),

		// Six boxes, where Background's `bg-origin` has only three.
		'mask-origin-border'         => array( 'mask-origin' => 'border-box' ),
		'mask-origin-content'        => array( 'mask-origin' => 'content-box' ),
		'mask-origin-fill'           => array( 'mask-origin' => 'fill-box' ),
		'mask-origin-padding'        => array( 'mask-origin' => 'padding-box' ),
		'mask-origin-stroke'         => array( 'mask-origin' => 'stroke-box' ),
		'mask-origin-view'           => array( 'mask-origin' => 'view-box' ),
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

		/*
		 * The single guard that keeps this module honest. Everything it owns
		 * begins at the `mask-` root, so keying on that — never on an inner
		 * `from-` or `to-` segment — leaves `from-red-500`, `to-50%`,
		 * `origin-center` and `object-cover` to the modules that own them.
		 */
		if ( ! str_starts_with( $base, 'mask-' ) ) {
			return null;
		}

		if ( isset( self::KEYWORDS[ $base ] ) ) {
			// A keyword utility has no value to negate and takes no modifier.
			if ( $candidate->negative || $candidate->modifier !== '' ) {
				return null;
			}

			return self::KEYWORDS[ $base ];
		}

		$rest = substr( $base, strlen( 'mask-' ) );
		if ( $rest === '' ) {
			return null;
		}

		$edge = $this->edge( $candidate, $theme, $rest );
		if ( $edge !== null ) {
			return $edge;
		}

		$gradient = $this->gradient( $candidate, $theme, $rest );
		if ( $gradient !== null ) {
			return $gradient;
		}

		return $this->layer_value( $candidate, $rest );
	}

	/**
	 * @return array<string, string>
	 */
	public function keyframes(): array {
		return array();
	}

	/**
	 * `mask-t-from-*`, `mask-b-to-*` and the two axis forms `mask-x-*` /
	 * `mask-y-*`.
	 *
	 * The edge token is matched as a whole hyphen-delimited segment. That is
	 * the only thing keeping `mask-type-alpha` from being read as a `t` edge:
	 * `type-alpha` does not start with `t-`, but a first-character test would
	 * misfire on it, and on `mask-repeat-x` and `mask-luminance` too.
	 *
	 * Declaration order is load-bearing for the axis forms, which interleave
	 * gradient definition and slot per side rather than grouping them.
	 *
	 * @return array<string, string>|null
	 */
	private function edge( Candidate $candidate, Theme $theme, string $rest ): ?array {
		foreach ( self::EDGES as $token => $sides ) {
			foreach ( array( 'from', 'to' ) as $stop ) {
				$prefix = $token . '-' . $stop . '-';
				if ( ! str_starts_with( $rest, $prefix ) ) {
					continue;
				}

				$slot = $this->slot( $candidate, $theme, substr( $rest, strlen( $prefix ) ) );
				if ( $slot === null ) {
					return null;
				}

				$out                     = self::COMPOSITION;
				$out['--tw-mask-linear'] = self::EDGE_LAYERS;

				foreach ( $sides as $side ) {
					$out[ '--tw-mask-' . $side ] = 'linear-gradient(to ' . $side . ', '
						. 'var(--tw-mask-' . $side . '-from-color) var(--tw-mask-' . $side . '-from-position), '
						. 'var(--tw-mask-' . $side . '-to-color) var(--tw-mask-' . $side . '-to-position))';

					$out[ '--tw-mask-' . $side . '-' . $stop . '-' . $slot[0] ] = $slot[1];
				}

				return $out;
			}
		}

		return null;
	}

	/**
	 * The three named gradients: `mask-linear-from/to-*`, `mask-radial-from/to-*`,
	 * `mask-conic-from/to-*`, and the `mask-linear-<angle>` /
	 * `mask-conic-<angle>` rotations.
	 *
	 * `from-` and `to-` are tested before the bare-number angle so
	 * `mask-linear-from-45` cannot be mistaken for `mask-linear-45`. The two
	 * emit different `--tw-mask-linear` values — only the angle form carries a
	 * `var(..., ...)` fallback — and swapping them is exactly how two mask
	 * classes end up cancelling instead of composing.
	 *
	 * @return array<string, string>|null
	 */
	private function gradient( Candidate $candidate, Theme $theme, string $rest ): ?array {
		foreach ( self::GRADIENT_STOPS as $kind => $stops ) {
			if ( ! str_starts_with( $rest, $kind . '-' ) ) {
				continue;
			}

			$tail = substr( $rest, strlen( $kind ) + 1 );

			foreach ( array( 'from', 'to' ) as $stop ) {
				if ( ! str_starts_with( $tail, $stop . '-' ) ) {
					continue;
				}

				$slot = $this->slot( $candidate, $theme, substr( $tail, strlen( $stop ) + 1 ) );
				if ( $slot === null ) {
					return null;
				}

				/*
				 * No fallback on `--tw-mask-<kind>` here: this form is the one
				 * that defines the stops, so reading them bare is right.
				 */
				$out                                    = self::COMPOSITION;
				$out[ '--tw-mask-' . $kind . '-stops' ] = $stops;
				$out[ '--tw-mask-' . $kind ]            = $kind . '-gradient(var(--tw-mask-' . $kind . '-stops))';

				$out[ '--tw-mask-' . $kind . '-' . $stop . '-' . $slot[0] ] = $slot[1];

				return $out;
			}

			if ( 'radial' === $kind ) {
				return $this->radial( $candidate, $tail );
			}

			return $this->angle( $candidate, $kind, $tail );
		}

		return null;
	}

	/**
	 * `mask-linear-45`, `mask-conic-180` and their negatives.
	 *
	 * Two details the shape depends on. The angle is spliced into
	 * `calc(1deg * <n>)` unchanged and negated by a literal `-` on the number,
	 * so `-mask-linear-0` really does emit `calc(1deg * -0)` — Background's
	 * `calc(45deg * -1)` spelling is a different string and must not be
	 * reused. And `--tw-mask-<kind>` gets the only fallback in the family,
	 * which is what lets the angle work alone (nothing registers
	 * `--tw-mask-<kind>-stops`, so it falls through to the bare angle) and
	 * still compose with a `mask-linear-from-*` that does set the stops.
	 *
	 * Radial has no bare-number form — `mask-radial-45` is not a utility — but
	 * it does take the fallback, keyed on `--tw-mask-radial-size` rather than
	 * the `-position` linear and conic use. {@see self::radial()}.
	 *
	 * @return array<string, string>|null
	 */
	private function angle( Candidate $candidate, string $kind, string $tail ): ?array {
		if ( 'radial' === $kind || $candidate->modifier !== '' ) {
			return null;
		}

		if ( self::is_wrapped( $tail ) ) {
			$parsed = self::unwrap( $tail );
			if ( $parsed === null || ! in_array( $parsed[0], array( '', 'angle' ), true ) ) {
				return null;
			}

			/*
			 * The wrapped form carries its own unit, so it is spliced in as
			 * written and negated by multiplying — `calc(45deg * -1)`. That is
			 * not the spelling the bare-number form below uses, and the two
			 * really are different strings in the reference build.
			 */
			return self::composed(
				$kind,
				'position',
				$candidate->negative ? 'calc(' . $parsed[1] . ' * -1)' : $parsed[1]
			);
		}

		if ( preg_match( '/^' . self::NUMBER . '$/', $tail ) !== 1 ) {
			return null;
		}

		$sign = $candidate->negative ? '-' : '';

		return self::composed( $kind, 'position', 'calc(1deg * ' . $sign . $tail . ')' );
	}

	/**
	 * `mask-radial-[...]` and `mask-radial-at-[...]`.
	 *
	 * Two utilities that look like one. The size form is a full composition
	 * block; the position form writes a single slot and nothing else, because
	 * the stop list already reads that slot. `at-` has to be tested first, or
	 * the size form swallows `mask-radial-at-[25%]` whole.
	 *
	 * @return array<string, string>|null
	 */
	private function radial( Candidate $candidate, string $tail ): ?array {
		if ( $candidate->negative || $candidate->modifier !== '' ) {
			return null;
		}

		if ( str_starts_with( $tail, 'at-' ) ) {
			$position = self::free_value( substr( $tail, 3 ) );

			return $position === null ? null : array( '--tw-mask-radial-position' => $position );
		}

		$size = self::free_value( $tail );

		return $size === null ? null : self::composed( 'radial', 'size', $size );
	}

	/**
	 * One composition block: the shared shorthand, the gradient that reads the
	 * named slot as its fallback, and the slot itself.
	 *
	 * @return array<string, string>
	 */
	private static function composed( string $kind, string $slot, string $value ): array {
		$out                                      = self::COMPOSITION;
		$out[ '--tw-mask-' . $kind ]              = $kind . '-gradient(var(--tw-mask-' . $kind . '-stops, var(--tw-mask-' . $kind . '-' . $slot . ')))';
		$out[ '--tw-mask-' . $kind . '-' . $slot ] = $value;

		return $out;
	}

	/**
	 * Which slot a composition utility fills, and the value for it.
	 *
	 * Position is tried before colour, the same order `Background`'s gradient
	 * stops use, because that is the only thing separating the two halves of
	 * one 392-value scale: a bare number or a percentage is a position and
	 * everything else is a colour name. Offering `20` to the colour namespace
	 * first would be a lookup that happens to miss rather than a decision.
	 *
	 * Positions resolve through `$theme->spacing()` so `mask-b-from-20` emits
	 * the same `5rem` that `p-20` does; upstream writes
	 * `calc(var(--spacing) * 20)` for both, and matching its literal text here
	 * would put this family out of step with every sibling in the sheet.
	 *
	 * Only ever one slot: a value is a position or a colour, never both.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function slot( Candidate $candidate, Theme $theme, string $token ): ?array {
		/*
		 * Only the two angle forms are negatable. Refusing the negative here
		 * is what stops the engine inventing seven thousand `-mask-*` classes
		 * upstream does not emit.
		 */
		if ( $token === '' || $candidate->negative ) {
			return null;
		}

		if ( self::is_wrapped( $token ) ) {
			return $this->wrapped_slot( $candidate, $theme, $token );
		}

		// A `/opacity` can only belong to a colour, so it rules out a
		// position outright rather than being ignored on one.
		if ( $candidate->modifier === '' ) {
			if ( preg_match( '/^' . self::NUMBER . '$/', $token ) === 1 ) {
				$spacing = $theme->spacing( $token );

				return $spacing === null ? null : array( 'position', $spacing );
			}

			if ( preg_match( '/^' . self::NUMBER . '%$/', $token ) === 1 ) {
				return array( 'position', $token );
			}
		}

		$color = $this->theme_color( $theme, $token );
		if ( $color === null ) {
			return null;
		}

		if ( $candidate->modifier !== '' ) {
			$color = $theme->with_alpha( $color, $candidate->modifier );
			if ( $color === '' ) {
				return null;
			}
		}

		return array( 'color', $color );
	}

	/**
	 * A `[...]` or `(...)` value on one of the eighteen composition prefixes.
	 *
	 * Which slot it fills is decided by the value, and the reference build is
	 * blunt about where the line falls: `mask-b-from-[#fff]` writes the colour
	 * slot, while `mask-b-from-[50%]`, `mask-b-from-[10px]` *and*
	 * `mask-b-from-(--c)` all write the position slot. So a wrapped value is a
	 * colour only when it reads as one — a parenthesised custom property is
	 * not, however plausible a colour it might hold at runtime, because
	 * upstream cannot see inside it either and picks position.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function wrapped_slot( Candidate $candidate, Theme $theme, string $token ): ?array {
		$parsed = self::unwrap( $token );
		if ( $parsed === null ) {
			return null;
		}

		list( $hint, $value ) = $parsed;

		$is_color = 'color' === $hint
			|| ( '' === $hint && ! str_starts_with( $token, '(' ) && self::is_color( $value ) );

		if ( ! $is_color ) {
			// A `/opacity` belongs to a colour, so it rules out a position
			// rather than being silently dropped on one.
			if ( '' !== $candidate->modifier ) {
				return null;
			}

			return in_array( $hint, array( '', 'length', 'percentage', 'position' ), true )
				? array( 'position', $value )
				: null;
		}

		if ( '' !== $candidate->modifier ) {
			$value = $theme->with_alpha( $value, $candidate->modifier );
			if ( '' === $value ) {
				return null;
			}
		}

		return array( 'color', $value );
	}

	/**
	 * True for a value that reads as a colour without resolving it.
	 *
	 * Deliberately syntactic: a hex triplet, one of the CSS colour functions,
	 * or one of the three keywords. Anything else — including `var(--x)` — is
	 * treated as a position, which is what upstream does.
	 */
	private static function is_color( string $value ): bool {
		if ( str_starts_with( $value, '#' ) || isset( self::COLOR_KEYWORDS[ $value ] ) ) {
			return true;
		}

		return preg_match(
			'/^(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color|color-mix|light-dark)\(/i',
			$value
		) === 1;
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
	 * The arbitrary layer forms: `mask-[...]` and `mask-(...)` for the image,
	 * plus `mask-size-[...]` and `mask-position-[...]`.
	 *
	 * Tailwind's static utility surface names none of these, so unlike every
	 * other shape in this file they are not checked against a reference build.
	 * They are here for the same reason Background carries `bg-size-[...]` and
	 * `bg-position-[...]`, which are likewise absent from that surface: a
	 * design that reaches for one would otherwise get nothing at all. Gated on
	 * a wrapped value, which is what keeps them from ever shadowing
	 * `mask-none`, the nine named positions or the three size keywords.
	 *
	 * @return array<string, string>|null
	 */
	private function layer_value( Candidate $candidate, string $rest ): ?array {
		if ( $candidate->negative || $candidate->modifier !== '' ) {
			return null;
		}

		foreach ( array( 'size' => 'mask-size', 'position' => 'mask-position' ) as $prefix => $property ) {
			if ( ! str_starts_with( $rest, $prefix . '-' ) ) {
				continue;
			}

			$explicit = self::free_value( substr( $rest, strlen( $prefix ) + 1 ) );

			return $explicit === null ? null : array( $property => $explicit );
		}

		if ( ! self::is_wrapped( $rest ) ) {
			return null;
		}

		$parsed = self::unwrap( $rest );
		if ( $parsed === null ) {
			return null;
		}

		$property = self::layer_property( $parsed[0], $parsed[1], str_starts_with( $rest, '(' ) );

		return $property === null ? null : array( $property => $parsed[1] );
	}

	/**
	 * Which of the three properties a bare `mask-[...]` writes.
	 *
	 * `mask` is one functional utility over three properties, and upstream
	 * picks between them by data type — trying, in order, image, percentage,
	 * position, bg-size, length, url, and keeping the first the value fits.
	 * That order is the whole subtlety: a bare `mask-[20px]` is a valid
	 * `<position>` before it is ever considered a length, so it lands on
	 * `mask-position`, and only an explicit `length:` or `size:` hint sends it
	 * to `mask-size`. We wrote every one of these to `mask-image`, which is
	 * not merely a different property but invalid CSS on it.
	 *
	 * A parenthesised `(--x)` is the exception: nothing can be inferred from a
	 * custom property, and upstream treats it as an image.
	 */
	private static function layer_property( string $hint, string $value, bool $parenthesised ): ?string {
		if ( '' !== $hint ) {
			$hints = array(
				'image'      => 'mask-image',
				'url'        => 'mask-image',
				'length'     => 'mask-size',
				'size'       => 'mask-size',
				'bg-size'    => 'mask-size',
				'percentage' => 'mask-position',
				'position'   => 'mask-position',
			);

			return $hints[ $hint ] ?? null;
		}

		if ( $parenthesised || self::is_image( $value ) ) {
			return 'mask-image';
		}

		// The three size keywords are not valid positions, so upstream's
		// ordered search reaches bg-size for them and nothing else.
		if ( in_array( $value, array( 'cover', 'contain', 'auto' ), true ) ) {
			return 'mask-size';
		}

		return 'mask-position';
	}

	/**
	 * True for a value CSS would accept as an image.
	 */
	private static function is_image( string $value ): bool {
		if ( 'none' === $value ) {
			return true;
		}

		return preg_match(
			'/^(?:url|image|image-set|cross-fade|element|paint|(?:repeating-)?(?:linear|radial|conic)-gradient)\(/i',
			$value
		) === 1;
	}

	/**
	 * The inside of a `[...]` or `(...)` value, or null when it is neither.
	 */
	private static function free_value( string $value ): ?string {
		if ( ! self::is_wrapped( $value ) ) {
			return null;
		}

		$parsed = self::unwrap( $value );

		return $parsed === null ? null : $parsed[1];
	}

	/**
	 * True for `[...]` and `(...)` values.
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
}

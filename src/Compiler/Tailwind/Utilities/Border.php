<?php
/**
 * Border, radius, outline and ring utilities.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler\Tailwind\Utilities;

use DXAI_UI\Compiler\Tailwind\Candidate;
use DXAI_UI\Compiler\Tailwind\Theme;
use DXAI_UI\Compiler\Tailwind\Utility_Module;

/**
 * Owns `border-*` in all three of its senses (width, colour and style),
 * `rounded-*`, `outline-*` and the `ring-*` / `inset-ring-*` families.
 *
 * `border-*` is ambiguous the same way `text-*` is, so the disambiguation
 * order in {@see self::border()} is the contract: the style keywords first,
 * then an optional side segment, then the declared data type of a bracketed
 * or parenthesised value, then a bare number as a pixel width, and finally the
 * colour registry. `border-collapse` and `border-separate` set
 * `border-collapse`, which is Tailwind's table namespace rather than this one,
 * and fall through as null so `Layout` — which owns the table and caption
 * families — answers them. That fall-through is conditional on the colour
 * registry missing, so a design that declared a `--color-collapse` token would
 * be answered here with a border colour and never reach `Layout`. Upstream
 * emits both declarations for that class; a module returns one block and
 * cannot, so the case is recorded rather than handled.
 *
 * `border-spacing-*` does belong here. It writes `--tw-border-spacing-x` and
 * `--tw-border-spacing-y` and then restates the `border-spacing` shorthand
 * from both variables, which is what lets `border-spacing-x-2
 * border-spacing-y-4` compose: a shorthand built from literal values would
 * make whichever of the two the cascade applied last discard the other axis.
 * It is dispatched ahead of the rest of the namespace because its axis letter
 * is the second segment of the token rather than the first, so the side split
 * {@see self::border()} performs would find no side in `border-spacing-x-4`
 * and hand the whole of `spacing-x-4` to the colour registry.
 *
 * `divide-*` paints borders between children; the Engine appends
 * `> :not(:last-child)` so the declarations match Tailwind v4.
 *
 * Widths, radii and offsets are written the way v4 writes them: the width
 * utilities pair their width with `border-style: var(--tw-border-style)` so a
 * `border-dashed` on the same element still wins, and the ring utilities
 * compose through the `--tw-ring-*` custom properties the preflight registers.
 */
final class Border implements Utility_Module {

	/**
	 * Border side segment mapped to the style, width and colour properties it
	 * drives. The empty key is the all-sides shorthand.
	 *
	 * `bs` and `be` are the block-direction logical sides, the pair `s`/`e`
	 * leaves out. They are two letters long, so they only stay distinct from
	 * `b` because {@see self::border()} matches a whole hyphen segment against
	 * this table: a prefix test would read `border-bs` as side `b` with a
	 * leftover `s`, and `border-blue-500` as a bottom border.
	 *
	 * @var array<string, array{style: string, width: string, color: string}>
	 */
	private const SIDES = array(
		''  => array(
			'style' => 'border-style',
			'width' => 'border-width',
			'color' => 'border-color',
		),
		'x' => array(
			'style' => 'border-inline-style',
			'width' => 'border-inline-width',
			'color' => 'border-inline-color',
		),
		'y' => array(
			'style' => 'border-block-style',
			'width' => 'border-block-width',
			'color' => 'border-block-color',
		),
		's' => array(
			'style' => 'border-inline-start-style',
			'width' => 'border-inline-start-width',
			'color' => 'border-inline-start-color',
		),
		'e' => array(
			'style' => 'border-inline-end-style',
			'width' => 'border-inline-end-width',
			'color' => 'border-inline-end-color',
		),
		'bs' => array(
			'style' => 'border-block-start-style',
			'width' => 'border-block-start-width',
			'color' => 'border-block-start-color',
		),
		'be' => array(
			'style' => 'border-block-end-style',
			'width' => 'border-block-end-width',
			'color' => 'border-block-end-color',
		),
		't' => array(
			'style' => 'border-top-style',
			'width' => 'border-top-width',
			'color' => 'border-top-color',
		),
		'r' => array(
			'style' => 'border-right-style',
			'width' => 'border-right-width',
			'color' => 'border-right-color',
		),
		'b' => array(
			'style' => 'border-bottom-style',
			'width' => 'border-bottom-width',
			'color' => 'border-bottom-color',
		),
		'l' => array(
			'style' => 'border-left-style',
			'width' => 'border-left-width',
			'color' => 'border-left-color',
		),
	);

	/**
	 * `border-spacing-*` axis segment mapped to the custom properties it
	 * writes. The empty key is both axes.
	 *
	 * The axis letter is the second segment of the token here, not the first,
	 * and it names a variable rather than a physical property, so this family
	 * cannot borrow {@see self::SIDES}.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const SPACING_AXES = array(
		''  => array( '--tw-border-spacing-x', '--tw-border-spacing-y' ),
		'x' => array( '--tw-border-spacing-x' ),
		'y' => array( '--tw-border-spacing-y' ),
	);

	/**
	 * The shorthand every `border-spacing-*` utility restates from both
	 * variables, whichever axis it actually set.
	 */
	private const BORDER_SPACING = 'var(--tw-border-spacing-x) var(--tw-border-spacing-y)';

	/**
	 * Sizing keywords the `--spacing` namespace also answers, because `w-*`
	 * and `h-*` read them out of it.
	 *
	 * None of them mean anything between two table cells and v4 generates no
	 * `border-spacing-full` or `border-spacing-auto`, so they are rejected
	 * here instead of being allowed to reach the scale — the same guard the
	 * spacing module keeps over its own utilities.
	 *
	 * @var array<int, string>
	 */
	private const SPACING_RESERVED = array(
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
	 * Radius corner segment mapped to the properties it sets. The empty key is
	 * the all-corners shorthand.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const CORNERS = array(
		''   => array( 'border-radius' ),
		's'  => array( 'border-start-start-radius', 'border-end-start-radius' ),
		'e'  => array( 'border-start-end-radius', 'border-end-end-radius' ),
		't'  => array( 'border-top-left-radius', 'border-top-right-radius' ),
		'r'  => array( 'border-top-right-radius', 'border-bottom-right-radius' ),
		'b'  => array( 'border-bottom-right-radius', 'border-bottom-left-radius' ),
		'l'  => array( 'border-top-left-radius', 'border-bottom-left-radius' ),
		'ss' => array( 'border-start-start-radius' ),
		'se' => array( 'border-start-end-radius' ),
		'ee' => array( 'border-end-end-radius' ),
		'es' => array( 'border-end-start-radius' ),
		'tl' => array( 'border-top-left-radius' ),
		'tr' => array( 'border-top-right-radius' ),
		'br' => array( 'border-bottom-right-radius' ),
		'bl' => array( 'border-bottom-left-radius' ),
	);

	/**
	 * Tailwind v4 default radius scale, used whenever the design's `@theme`
	 * block does not redefine the token.
	 *
	 * @var array<string, string>
	 */
	private const RADIUS = array(
		'xs'  => '0.125rem',
		'sm'  => '0.25rem',
		'md'  => '0.375rem',
		'lg'  => '0.5rem',
		'xl'  => '0.75rem',
		'2xl' => '1rem',
		'3xl' => '1.5rem',
		'4xl' => '2rem',
	);

	/**
	 * Radius of the bare `rounded` alias v4 keeps for compatibility. It is a
	 * fixed value rather than a theme token, so a design that redefines
	 * `--radius-sm` does not move it.
	 */
	private const DEFAULT_RADIUS = '0.25rem';

	/** @var array<string, string> */
	private const BORDER_STYLES = array(
		'solid'  => 'solid',
		'dashed' => 'dashed',
		'dotted' => 'dotted',
		'double' => 'double',
		'hidden' => 'hidden',
		'none'   => 'none',
	);

	/** @var array<string, string> */
	private const OUTLINE_STYLES = array(
		'solid'  => 'solid',
		'dashed' => 'dashed',
		'dotted' => 'dotted',
		'double' => 'double',
	);

	/** Colour keywords Tailwind resolves without touching the theme. */
	private const COLOR_KEYWORDS = array(
		'inherit'     => 'inherit',
		'current'     => 'currentColor',
		'transparent' => 'transparent',
	);

	/**
	 * The full shadow chain a ring utility has to restate, so a ring and a
	 * `shadow-*` on the same element stack instead of replacing each other.
	 */
	private const SHADOW_CHAIN = 'var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow)';

	/**
	 * @return array<string, string>|null
	 */
	public function resolve( Candidate $candidate, Theme $theme ): ?array {
		$base = $candidate->base;

		if ( 'rounded' === $base || str_starts_with( $base, 'rounded-' ) ) {
			return $this->rounded( $candidate, $theme );
		}

		if ( $base === 'divide' || str_starts_with( $base, 'divide-' ) ) {
			return $this->divide( $candidate, $theme );
		}

		/*
		 * Not a terminal branch. Upstream parses a functional class against
		 * every candidate root from longest to shortest and keeps the first
		 * that compiles, so `border-spacing-brand` tries root `border-spacing`
		 * (no `--spacing-brand`, does not compile) and then root `border`
		 * (a design's `--color-spacing-brand` does), and emits a border colour.
		 * Returning border_spacing()'s null as final swallowed that fallback.
		 */
		if ( str_starts_with( $base, 'border-spacing-' ) ) {
			$spacing = $this->border_spacing( $candidate, $theme );
			if ( null !== $spacing ) {
				return $spacing;
			}
		}

		if ( 'border' === $base || str_starts_with( $base, 'border-' ) ) {
			return $this->border( $candidate, $theme );
		}

		if ( 'outline' === $base || str_starts_with( $base, 'outline-' ) ) {
			return $this->outline( $candidate, $theme );
		}

		if ( 'inset-ring' === $base || str_starts_with( $base, 'inset-ring-' ) ) {
			return $this->ring( $candidate, $theme, 'inset-ring' );
		}

		if ( 'ring' === $base || str_starts_with( $base, 'ring-' ) ) {
			return $this->ring( $candidate, $theme, 'ring' );
		}

		return null;
	}

	/**
	 * @return array<string, string>
	 */
	public function keyframes(): array {
		return array();
	}

	/**
	 * `divide-x` / `divide-y` plus colour and style. The Engine hangs these
	 * declarations on `> :not(:last-child)`.
	 *
	 * @return array<string, string>|null
	 */
	private function divide( Candidate $candidate, Theme $theme ): ?array {
		if ( $candidate->negative ) {
			return null;
		}

		$token = 'divide' === $candidate->base ? 'x' : substr( $candidate->base, strlen( 'divide-' ) );
		if ( $token === '' ) {
			$token = 'x';
		}

		if ( isset( self::BORDER_STYLES[ $token ] ) ) {
			/*
			 * Also the variable the width utilities read. `divide-y
			 * divide-dashed` puts both rules on the same
			 * `> :not(:last-child)`, where `border-block-style:
			 * var(--tw-border-style)` and `border-style: dashed` have equal
			 * specificity — so which one won came down to emission order.
			 * Setting the variable makes it compose instead of race.
			 */
			return array(
				'--tw-border-style' => self::BORDER_STYLES[ $token ],
				'border-style'      => self::BORDER_STYLES[ $token ],
			);
		}

		$axis = '';
		$rest = $token;
		if ( $token === 'x' || $token === 'y' || str_starts_with( $token, 'x-' ) || str_starts_with( $token, 'y-' ) ) {
			$axis = $token[0];
			$rest = strlen( $token ) > 1 ? substr( $token, 2 ) : '';
		}

		if ( $axis !== '' ) {
			/*
			 * `divide-x-reverse` / `divide-y-reverse` move the border to the
			 * other edge of each child, which is what a `flex-row-reverse`
			 * container needs. Previously unresolved, so a design using it got
			 * no divider at all.
			 */
			if ( $rest === 'reverse' ) {
				return array( '--tw-divide-' . $axis . '-reverse' => '1' );
			}

			$width = '1px';
			if ( $rest !== '' ) {
				if ( preg_match( '/^\d+$/', $rest ) === 1 ) {
					$width = $rest . 'px';
				} else {
					return null;
				}
			}

			/*
			 * Both edges, each scaled by the reverse flag, exactly as upstream
			 * does it: the flag is 0 by default, so the start edge computes to
			 * zero width and only the end edge draws. Setting the end edge
			 * alone rendered identically until a design used the reverse
			 * utility, at which point nothing moved.
			 */
			$reverse = 'var(--tw-divide-' . $axis . '-reverse)';
			$start   = 'calc(' . $width . ' * ' . $reverse . ')';
			$end     = 'calc(' . $width . ' * calc(1 - ' . $reverse . '))';

			if ( $axis === 'x' ) {
				return array(
					'--tw-divide-x-reverse'     => '0',
					'border-inline-style'       => 'var(--tw-border-style)',
					'border-inline-start-width' => $start,
					'border-inline-end-width'   => $end,
				);
			}

			return array(
				'--tw-divide-y-reverse' => '0',
				'border-top-style'      => 'var(--tw-border-style)',
				'border-bottom-style'   => 'var(--tw-border-style)',
				'border-top-width'      => $start,
				'border-bottom-width'   => $end,
			);
		}

		$color = $this->theme_color( $theme, $token );

		return null === $color ? null : $this->color( 'border-color', $color, $candidate, $theme );
	}

	/**
	 * `border-*`: style keywords, then side, then width or colour.
	 *
	 * @return array<string, string>|null
	 */
	private function border( Candidate $candidate, Theme $theme ): ?array {
		$token = 'border' === $candidate->base ? '' : substr( $candidate->base, 7 );

		if ( isset( self::BORDER_STYLES[ $token ] ) ) {
			if ( $candidate->negative || '' !== $candidate->modifier ) {
				return null;
			}

			return array(
				'--tw-border-style' => self::BORDER_STYLES[ $token ],
				'border-style'      => self::BORDER_STYLES[ $token ],
			);
		}

		$side = '';
		if ( '' !== $token && ! self::is_wrapped( $token ) ) {
			$split = explode( '-', $token, 2 );
			if ( '' !== $split[0] && isset( self::SIDES[ $split[0] ] ) ) {
				$side  = $split[0];
				$token = $split[1] ?? '';
			}
		}

		$properties = self::SIDES[ $side ];

		if ( '' === $token ) {
			return $this->width( $properties, '1px', $candidate );
		}

		if ( self::is_wrapped( $token ) ) {
			$parsed = self::unwrap( $token );
			if ( null === $parsed ) {
				return null;
			}

			list( $hint, $value ) = $parsed;

			if ( 'length' === $hint ) {
				return $this->width( $properties, $value, $candidate );
			}
			if ( 'color' === $hint ) {
				return $this->color( $properties['color'], $value, $candidate, $theme );
			}
			if ( '' !== $hint ) {
				return null;
			}

			// An unhinted custom property is a colour on `border-*`, as in v4.
			if ( str_starts_with( $token, '(' ) || ! self::is_length( $value ) ) {
				return $this->color( $properties['color'], $value, $candidate, $theme );
			}

			return $this->width( $properties, $value, $candidate );
		}

		if ( self::is_number( $token ) ) {
			return $this->width( $properties, $token . 'px', $candidate );
		}

		$color = $this->theme_color( $theme, $token );

		return null === $color ? null : $this->color( $properties['color'], $color, $candidate, $theme );
	}

	/**
	 * `border-spacing-*` and its two axis forms.
	 *
	 * Only the axes the utility names are written; the shorthand is then
	 * restated from both variables so the other axis keeps whatever a second
	 * class on the element gave it.
	 *
	 * @return array<string, string>|null
	 */
	private function border_spacing( Candidate $candidate, Theme $theme ): ?array {
		// No negative border-spacing exists, and the /modifier is a colour idea.
		if ( $candidate->negative || '' !== $candidate->modifier ) {
			return null;
		}

		$token = substr( $candidate->base, strlen( 'border-spacing-' ) );

		$axis = '';
		if ( ! self::is_wrapped( $token ) ) {
			$split = explode( '-', $token, 2 );
			// A bare `x` or `y` is the whole token, and has no value to set.
			if ( isset( $split[1] ) && ( 'x' === $split[0] || 'y' === $split[0] ) ) {
				$axis  = $split[0];
				$token = $split[1];
			}
		}

		$value = $this->spacing_value( $token, $theme );
		if ( null === $value ) {
			return null;
		}

		$out = array();
		foreach ( self::SPACING_AXES[ $axis ] as $property ) {
			$out[ $property ] = $value;
		}
		$out['border-spacing'] = self::BORDER_SPACING;

		return $out;
	}

	/**
	 * A `border-spacing` length: a bracketed or parenthesised value, then a
	 * numeric step, `px` or a named token off the `--spacing` scale.
	 */
	private function spacing_value( string $token, Theme $theme ): ?string {
		if ( '' === $token ) {
			return null;
		}

		if ( self::is_wrapped( $token ) ) {
			$parsed = self::unwrap( $token );
			if ( null === $parsed || ( '' !== $parsed[0] && 'length' !== $parsed[0] ) ) {
				return null;
			}

			return $parsed[1];
		}

		if ( in_array( $token, self::SPACING_RESERVED, true ) ) {
			return null;
		}

		if ( ! self::is_number( $token ) && 'px' !== $token && preg_match( '/^[a-z][a-z0-9-]*$/', $token ) !== 1 ) {
			return null;
		}

		$value = $theme->spacing( $token );

		return '' === $value ? null : $value;
	}

	/**
	 * `rounded-*` in every corner combination.
	 *
	 * @return array<string, string>|null
	 */
	private function rounded( Candidate $candidate, Theme $theme ): ?array {
		if ( $candidate->negative || '' !== $candidate->modifier ) {
			return null;
		}

		$token = 'rounded' === $candidate->base ? '' : substr( $candidate->base, 8 );

		$corner = '';
		if ( '' !== $token && ! self::is_wrapped( $token ) ) {
			$split = explode( '-', $token, 2 );
			if ( '' !== $split[0] && isset( self::CORNERS[ $split[0] ] ) ) {
				$corner = $split[0];
				$token  = $split[1] ?? '';
			}
		}

		$value = $this->radius( $token, $theme );
		if ( null === $value ) {
			return null;
		}

		$out = array();
		foreach ( self::CORNERS[ $corner ] as $property ) {
			$out[ $property ] = $value;
		}

		return $out;
	}

	/**
	 * A single radius value: the two keywords, then a bracketed length, then
	 * the theme scale with the v4 defaults behind it.
	 */
	private function radius( string $token, Theme $theme ): ?string {
		if ( '' === $token ) {
			return self::DEFAULT_RADIUS;
		}

		if ( 'none' === $token ) {
			return '0';
		}

		if ( 'full' === $token ) {
			return 'calc(infinity * 1px)';
		}

		if ( self::is_wrapped( $token ) ) {
			$parsed = self::unwrap( $token );
			if ( null === $parsed || ( '' !== $parsed[0] && 'length' !== $parsed[0] ) ) {
				return null;
			}

			return $parsed[1];
		}

		$themed = $theme->value( 'radius', $token );
		if ( null !== $themed ) {
			return $themed;
		}

		return self::RADIUS[ $token ] ?? null;
	}

	/**
	 * `outline-*`: offsets, the style keywords, then width or colour.
	 *
	 * `outline-hidden` keeps only the declarations; the `forced-colors` block
	 * v4 pairs with it needs a media query no module can emit.
	 *
	 * @return array<string, string>|null
	 */
	private function outline( Candidate $candidate, Theme $theme ): ?array {
		$token = 'outline' === $candidate->base ? '' : substr( $candidate->base, 8 );

		if ( str_starts_with( $token, 'offset-' ) ) {
			return $this->outline_offset( substr( $token, 7 ), $candidate );
		}

		if ( '' === $token ) {
			return $this->outline_width( '1px', $candidate );
		}

		if ( 'none' === $token || 'hidden' === $token ) {
			if ( $candidate->negative || '' !== $candidate->modifier ) {
				return null;
			}

			return array(
				'--tw-outline-style' => 'none',
				'outline-style'      => 'none',
			);
		}

		if ( isset( self::OUTLINE_STYLES[ $token ] ) ) {
			if ( $candidate->negative || '' !== $candidate->modifier ) {
				return null;
			}

			return array(
				'--tw-outline-style' => self::OUTLINE_STYLES[ $token ],
				'outline-style'      => self::OUTLINE_STYLES[ $token ],
			);
		}

		if ( self::is_wrapped( $token ) ) {
			$parsed = self::unwrap( $token );
			if ( null === $parsed ) {
				return null;
			}

			list( $hint, $value ) = $parsed;

			if ( 'length' === $hint ) {
				return $this->outline_width( $value, $candidate );
			}
			if ( 'color' === $hint ) {
				return $this->color( 'outline-color', $value, $candidate, $theme );
			}
			if ( '' !== $hint ) {
				return null;
			}

			if ( str_starts_with( $token, '(' ) || ! self::is_length( $value ) ) {
				return $this->color( 'outline-color', $value, $candidate, $theme );
			}

			return $this->outline_width( $value, $candidate );
		}

		if ( self::is_number( $token ) ) {
			return $this->outline_width( $token . 'px', $candidate );
		}

		$color = $this->theme_color( $theme, $token );

		return null === $color ? null : $this->color( 'outline-color', $color, $candidate, $theme );
	}

	/**
	 * @return array<string, string>|null
	 */
	private function outline_width( string $value, Candidate $candidate ): ?array {
		if ( $candidate->negative || '' !== $candidate->modifier || '' === $value ) {
			return null;
		}

		return array(
			'outline-style' => 'var(--tw-outline-style)',
			'outline-width' => $value,
		);
	}

	/**
	 * `outline-offset-*`, the one negatable member of the namespace.
	 *
	 * @return array<string, string>|null
	 */
	private function outline_offset( string $token, Candidate $candidate ): ?array {
		if ( '' === $token || '' !== $candidate->modifier ) {
			return null;
		}

		if ( self::is_wrapped( $token ) ) {
			$parsed = self::unwrap( $token );
			if ( null === $parsed || ( '' !== $parsed[0] && 'length' !== $parsed[0] ) ) {
				return null;
			}
			$value = $parsed[1];
		} elseif ( self::is_number( $token ) ) {
			$value = $token . 'px';
		} else {
			return null;
		}

		if ( '' === $value ) {
			return null;
		}

		return array( 'outline-offset' => $candidate->negative ? self::negate( $value ) : $value );
	}

	/**
	 * `ring-*` and `inset-ring-*`: the inset flag and ring offsets first, then
	 * width or colour.
	 *
	 * @return array<string, string>|null
	 */
	private function ring( Candidate $candidate, Theme $theme, string $prefix ): ?array {
		$inset = 'inset-ring' === $prefix;
		$token = $candidate->base === $prefix ? '' : substr( $candidate->base, strlen( $prefix ) + 1 );

		if ( ! $inset && 'inset' === $token ) {
			if ( $candidate->negative || '' !== $candidate->modifier ) {
				return null;
			}

			return array( '--tw-ring-inset' => 'inset' );
		}

		if ( ! $inset && str_starts_with( $token, 'offset-' ) ) {
			return $this->ring_offset( substr( $token, 7 ), $candidate, $theme );
		}

		if ( '' === $token ) {
			return $this->ring_width( $inset, '1px', $candidate );
		}

		$property = $inset ? '--tw-inset-ring-color' : '--tw-ring-color';

		if ( self::is_wrapped( $token ) ) {
			$parsed = self::unwrap( $token );
			if ( null === $parsed ) {
				return null;
			}

			list( $hint, $value ) = $parsed;

			if ( 'length' === $hint ) {
				return $this->ring_width( $inset, $value, $candidate );
			}
			if ( 'color' === $hint ) {
				return $this->color( $property, $value, $candidate, $theme );
			}
			if ( '' !== $hint ) {
				return null;
			}

			if ( str_starts_with( $token, '(' ) || ! self::is_length( $value ) ) {
				return $this->color( $property, $value, $candidate, $theme );
			}

			return $this->ring_width( $inset, $value, $candidate );
		}

		if ( self::is_number( $token ) ) {
			return $this->ring_width( $inset, $token . 'px', $candidate );
		}

		$color = $this->theme_color( $theme, $token );

		return null === $color ? null : $this->color( $property, $color, $candidate, $theme );
	}

	/**
	 * A ring of a given width, restated as the box-shadow layer v4 composes.
	 *
	 * @return array<string, string>|null
	 */
	private function ring_width( bool $inset, string $value, Candidate $candidate ): ?array {
		if ( $candidate->negative || '' !== $candidate->modifier || '' === $value ) {
			return null;
		}

		if ( $inset ) {
			return array(
				'--tw-inset-ring-shadow' => 'inset 0 0 0 ' . $value . ' var(--tw-inset-ring-color, currentcolor)',
				'box-shadow'             => self::SHADOW_CHAIN,
			);
		}

		return array(
			'--tw-ring-shadow' => 'var(--tw-ring-inset,) 0 0 0 calc(' . $value . ' + var(--tw-ring-offset-width)) var(--tw-ring-color, currentcolor)',
			'box-shadow'       => self::SHADOW_CHAIN,
		);
	}

	/**
	 * `ring-offset-*`, which is a width or a colour like the ring itself.
	 *
	 * @return array<string, string>|null
	 */
	private function ring_offset( string $token, Candidate $candidate, Theme $theme ): ?array {
		if ( '' === $token ) {
			return null;
		}

		if ( self::is_wrapped( $token ) ) {
			$parsed = self::unwrap( $token );
			if ( null === $parsed ) {
				return null;
			}

			list( $hint, $value ) = $parsed;

			if ( 'length' === $hint ) {
				return $this->ring_offset_width( $value, $candidate );
			}
			if ( 'color' === $hint ) {
				return $this->color( '--tw-ring-offset-color', $value, $candidate, $theme );
			}
			if ( '' !== $hint ) {
				return null;
			}

			if ( self::is_length( $value ) ) {
				return $this->ring_offset_width( $value, $candidate );
			}

			return $this->color( '--tw-ring-offset-color', $value, $candidate, $theme );
		}

		if ( self::is_number( $token ) ) {
			return $this->ring_offset_width( $token . 'px', $candidate );
		}

		$color = $this->theme_color( $theme, $token );

		return null === $color ? null : $this->color( '--tw-ring-offset-color', $color, $candidate, $theme );
	}

	/**
	 * @return array<string, string>|null
	 */
	private function ring_offset_width( string $value, Candidate $candidate ): ?array {
		if ( $candidate->negative || '' !== $candidate->modifier || '' === $value ) {
			return null;
		}

		return array(
			'--tw-ring-offset-width'  => $value,
			'--tw-ring-offset-shadow' => 'var(--tw-ring-inset,) 0 0 0 var(--tw-ring-offset-width) var(--tw-ring-offset-color)',
		);
	}

	/**
	 * A border width, paired with the style variable v4 emits alongside it.
	 *
	 * @param array{style: string, width: string, color: string} $properties
	 *
	 * @return array<string, string>|null
	 */
	private function width( array $properties, string $value, Candidate $candidate ): ?array {
		if ( $candidate->negative || '' !== $candidate->modifier || '' === $value ) {
			return null;
		}

		return array(
			$properties['style'] => 'var(--tw-border-style)',
			$properties['width'] => $value,
		);
	}

	/**
	 * A colour declaration with the `/opacity` modifier folded in.
	 *
	 * @return array<string, string>|null
	 */
	private function color( string $property, string $color, Candidate $candidate, Theme $theme ): ?array {
		if ( $candidate->negative ) {
			return null;
		}

		$resolved = $this->apply_alpha( $color, $candidate->modifier, $theme );

		return null === $resolved ? null : array( $property => $resolved );
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
	 * Bare modifiers are percentages (`/10`), bracketed ones are alpha
	 * channels (`/[0.06]`), and a modifier that is itself an expression is
	 * scaled to a percentage inside the `color-mix()`.
	 */
	private function apply_alpha( string $color, string $modifier, Theme $theme ): ?string {
		if ( '' === $color ) {
			return null;
		}
		if ( '' === $modifier ) {
			return $color;
		}

		$fraction = false;

		if ( self::is_wrapped( $modifier ) ) {
			$parsed = self::unwrap( $modifier );
			if ( null === $parsed || '' !== $parsed[0] ) {
				return null;
			}
			$value    = $parsed[1];
			$fraction = true;
		} else {
			$value = $modifier;
			if ( preg_match( '/^\d+(?:\.\d+)?$/', $value ) !== 1 ) {
				$resolved = $theme->value( 'opacity', $value );
				if ( null === $resolved ) {
					return null;
				}
				$value    = $resolved;
				$fraction = true;
			}
		}

		if ( '' === $value ) {
			return null;
		}

		if ( preg_match( '/^(\d+(?:\.\d+)?)%$/', $value, $match ) === 1 ) {
			return $theme->with_alpha( $color, $match[1] );
		}

		if ( preg_match( '/^\d+(?:\.\d+)?$/', $value ) === 1 ) {
			$number = (float) $value;
			if ( $fraction && $number <= 1.0 ) {
				$number *= 100.0;
			}

			return $theme->with_alpha( $color, self::format_number( $number ) );
		}

		return 'color-mix(in oklab, ' . $color . ' calc(' . $value . ' * 100%), transparent)';
	}

	/** True for a bare number, the form Tailwind reads as pixels here. */
	private static function is_number( string $value ): bool {
		return preg_match( '/^\d+(?:\.\d+)?$/', $value ) === 1;
	}

	/**
	 * True for anything that reads as a CSS length or size calculation rather
	 * than a colour.
	 */
	private static function is_length( string $value ): bool {
		if ( '' === $value ) {
			return false;
		}

		if ( preg_match( '/^-?(?:\d+(?:\.\d+)?|\.\d+)(?:px|rem|em|%|pt|pc|in|q|cm|mm|ex|ch|cap|ic|lh|rlh|vw|vh|vi|vb|vmin|vmax|svw|svh|lvw|lvh|dvw|dvh)$/i', $value ) === 1 ) {
			return true;
		}

		if ( preg_match( '/^-?(?:\d+(?:\.\d+)?|\.\d+)$/', $value ) === 1 ) {
			return true;
		}

		return preg_match( '/^(?:calc|clamp|min|max|round|mod|rem)\s*\(/i', $value ) === 1;
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
		if ( '' === $inner ) {
			return null;
		}

		$hint = '';
		if ( preg_match( '/^([a-z-]+):(.*)$/s', $inner, $match ) === 1 ) {
			$hint  = $match[1];
			$inner = trim( $match[2] );
		}

		if ( '' === $inner ) {
			return null;
		}

		if ( $parenthesised ) {
			if ( ! str_starts_with( $inner, '--' ) ) {
				return null;
			}
			$inner = 'var(' . $inner . ')';
		}

		return array( $hint, $inner );
	}

	/**
	 * Negate a resolved value, wrapping anything that is not a plain number.
	 */
	private static function negate( string $value ): string {
		if ( '0' === $value || '0px' === $value || '0rem' === $value ) {
			return $value;
		}

		if ( str_starts_with( $value, '-' ) ) {
			return substr( $value, 1 );
		}

		if ( preg_match( '/^\d+(?:\.\d+)?[a-z%]*$/i', $value ) === 1 ) {
			return '-' . $value;
		}

		return 'calc(' . $value . ' * -1)';
	}

	/**
	 * Trim float noise so `0.06 * 100` prints as `6`, not `6.000000000000001`.
	 */
	private static function format_number( float $number ): string {
		$formatted = number_format( $number, 6, '.', '' );
		$formatted = rtrim( rtrim( $formatted, '0' ), '.' );

		return '' === $formatted || '-' === $formatted ? '0' : $formatted;
	}
}

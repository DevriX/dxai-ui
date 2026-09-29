<?php
/**
 * tailwind-merge's conflict resolution, for the `cn()` every shadcn file uses.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler\Tailwind;

/**
 * `cn()` is not a join. Every shadcn project defines it as
 * `twMerge(clsx(inputs))`, and `twMerge` DROPS a class that a later one
 * overrides — so the class list the real app renders is shorter than the
 * concatenation of its parts. A Button built from
 *
 *     cva("… text-sm …", { variants: { size: { lg: "h-12 px-8 text-base" } } })
 *
 * renders `text-base` with no `text-sm` beside it. Joining instead kept both,
 * and which one won was then decided by our emitted sheet rather than by the
 * design — right by luck for that pair, and a different class list from the
 * one the real build produces, which is how three buttons read as lost nodes
 * against their own React build.
 *
 * What it models
 * --------------
 * Upstream assigns each class a GROUP, and a later class removes earlier ones
 * in the same group. Some groups also override narrower ones: a later `p-4`
 * removes an earlier `px-2`, while a later `px-2` leaves an earlier `p-4`
 * alone — the relation is one-directional and declared on the broader group.
 * Conflicts are confined to classes carrying the same variants, so
 * `hover:px-4` never displaces `px-2`.
 *
 * What it does not model, deliberately
 * ------------------------------------
 * A class this table does not recognise has NO group, and a class with no
 * group is never removed and never removes anything — which is exactly the
 * behaviour this code replaced. So an omission costs nothing beyond leaving a
 * duplicate for the cascade to settle, and only a WRONG entry can lose a class
 * the design meant to keep. That asymmetry is the point: the table covers what
 * it can state exactly and stays silent elsewhere.
 */
final class Class_Merge {

	/** Sides and corners, which turn `rounded-t-lg` into a side group. */
	private const EDGES = array( 't', 'r', 'b', 'l', 'x', 'y', 's', 'e' );

	/** Corner keys of the radius family. */
	private const CORNERS = array( 'tl', 'tr', 'br', 'bl', 'ss', 'se', 'ee', 'es' );

	/** Font sizes, which is what separates `text-sm` from `text-slate-500`. */
	private const FONT_SIZES = array(
		'xs', 'sm', 'base', 'lg', 'xl', '2xl', '3xl', '4xl', '5xl',
		'6xl', '7xl', '8xl', '9xl',
	);

	/** Font weights, which separate `font-bold` from `font-display`. */
	private const FONT_WEIGHTS = array(
		'thin', 'extralight', 'light', 'normal', 'medium', 'semibold',
		'bold', 'extrabold', 'black',
	);

	/**
	 * Classes whose whole name is the class, mapped to their group.
	 *
	 * @var array<string, string>
	 */
	private const KEYWORDS = array(
		// display
		'block'              => 'display',
		'inline-block'       => 'display',
		'inline'             => 'display',
		'flex'               => 'display',
		'inline-flex'        => 'display',
		'table'              => 'display',
		'inline-table'       => 'display',
		'table-caption'      => 'display',
		'table-cell'         => 'display',
		'table-row'          => 'display',
		'flow-root'          => 'display',
		'grid'               => 'display',
		'inline-grid'        => 'display',
		'contents'           => 'display',
		'list-item'          => 'display',
		'hidden'             => 'display',
		// position
		'static'             => 'position',
		'fixed'              => 'position',
		'absolute'           => 'position',
		'relative'           => 'position',
		'sticky'             => 'position',
		// visibility
		'visible'            => 'visibility',
		'invisible'          => 'visibility',
		'collapse'           => 'visibility',
		// type
		'italic'             => 'font-style',
		'not-italic'         => 'font-style',
		'uppercase'          => 'text-transform',
		'lowercase'          => 'text-transform',
		'capitalize'         => 'text-transform',
		'normal-case'        => 'text-transform',
		'underline'          => 'text-decoration',
		'overline'           => 'text-decoration',
		'line-through'       => 'text-decoration',
		'no-underline'       => 'text-decoration',
		'truncate'           => 'text-overflow',
		'text-ellipsis'      => 'text-overflow',
		'text-clip'          => 'text-overflow',
		'antialiased'        => 'font-smoothing',
		'subpixel-antialiased' => 'font-smoothing',
		// box and flex
		'box-border'         => 'box-sizing',
		'box-content'        => 'box-sizing',
		'flex-row'           => 'flex-direction',
		'flex-row-reverse'   => 'flex-direction',
		'flex-col'           => 'flex-direction',
		'flex-col-reverse'   => 'flex-direction',
		'flex-wrap'          => 'flex-wrap',
		'flex-wrap-reverse'  => 'flex-wrap',
		'flex-nowrap'        => 'flex-wrap',
		// borders that are not a width
		'border-solid'       => 'border-style',
		'border-dashed'      => 'border-style',
		'border-dotted'      => 'border-style',
		'border-double'      => 'border-style',
		'border-hidden'      => 'border-style',
		'border-none'        => 'border-style',
		'border-collapse'    => 'border-collapse',
		'border-separate'    => 'border-collapse',
		// accessibility
		'sr-only'            => 'sr',
		'not-sr-only'        => 'sr',
		// misc keywords
		'isolate'            => 'isolation',
		'isolation-auto'     => 'isolation',
		'overflow-auto'      => 'overflow',
		'overflow-hidden'    => 'overflow',
		'overflow-clip'      => 'overflow',
		'overflow-visible'   => 'overflow',
		'overflow-scroll'    => 'overflow',
	);

	/**
	 * Prefix families whose group is the prefix itself, with the narrower
	 * groups each one overrides.
	 *
	 * Order matters only in that the LONGEST matching prefix wins, which is
	 * resolved by comparing lengths rather than by the order written here.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const FAMILIES = array(
		// padding
		'p'            => array( 'px', 'py', 'pt', 'pr', 'pb', 'pl', 'ps', 'pe' ),
		'px'           => array( 'pl', 'pr', 'ps', 'pe' ),
		'py'           => array( 'pt', 'pb' ),
		'pt'           => array(),
		'pr'           => array(),
		'pb'           => array(),
		'pl'           => array(),
		'ps'           => array(),
		'pe'           => array(),
		// margin
		'm'            => array( 'mx', 'my', 'mt', 'mr', 'mb', 'ml', 'ms', 'me' ),
		'mx'           => array( 'ml', 'mr', 'ms', 'me' ),
		'my'           => array( 'mt', 'mb' ),
		'mt'           => array(),
		'mr'           => array(),
		'mb'           => array(),
		'ml'           => array(),
		'ms'           => array(),
		'me'           => array(),
		// sizing
		'size'         => array( 'w', 'h' ),
		'w'            => array(),
		'h'            => array(),
		'min-w'        => array(),
		'min-h'        => array(),
		'max-w'        => array(),
		'max-h'        => array(),
		'aspect'       => array(),
		// position offsets
		'inset'        => array( 'inset-x', 'inset-y', 'top', 'right', 'bottom', 'left', 'start', 'end' ),
		'inset-x'      => array( 'right', 'left', 'start', 'end' ),
		'inset-y'      => array( 'top', 'bottom' ),
		'top'          => array(),
		'right'        => array(),
		'bottom'       => array(),
		'left'         => array(),
		'start'        => array(),
		'end'          => array(),
		// flex and grid
		'basis'        => array(),
		'grow'         => array(),
		'shrink'       => array(),
		'order'        => array(),
		'grid-cols'    => array(),
		'grid-rows'    => array(),
		'col-span'     => array(),
		'col-start'    => array(),
		'col-end'      => array(),
		'row-span'     => array(),
		'row-start'    => array(),
		'row-end'      => array(),
		'auto-cols'    => array(),
		'auto-rows'    => array(),
		'grid-flow'    => array(),
		'gap'          => array( 'gap-x', 'gap-y' ),
		'gap-x'        => array(),
		'gap-y'        => array(),
		'space-x'      => array(),
		'space-y'      => array(),
		'items'        => array(),
		'justify'      => array(),
		'self'         => array(),
		'place-items'  => array(),
		'place-content' => array(),
		'place-self'   => array(),
		// typography
		'leading'      => array(),
		'tracking'     => array(),
		'indent'       => array(),
		'align'        => array(),
		'whitespace'   => array(),
		'break'        => array(),
		'list'         => array(),
		'decoration'   => array(),
		'underline-offset' => array(),
		// effects and motion
		'opacity'      => array(),
		'z'            => array(),
		'duration'     => array(),
		'delay'        => array(),
		'ease'         => array(),
		'animate'      => array(),
		'transition'   => array(),
		'blur'         => array(),
		'brightness'   => array(),
		'contrast'     => array(),
		'saturate'     => array(),
		'grayscale'    => array(),
		'invert'       => array(),
		'sepia'        => array(),
		'scale'        => array( 'scale-x', 'scale-y' ),
		'scale-x'      => array(),
		'scale-y'      => array(),
		'rotate'       => array(),
		'translate-x'  => array(),
		'translate-y'  => array(),
		'skew-x'       => array(),
		'skew-y'       => array(),
		'origin'       => array(),
		// interaction and paint that has no colour/size ambiguity
		'cursor'       => array(),
		'select'       => array(),
		'pointer-events' => array(),
		'resize'       => array(),
		'object'       => array(),
		'overflow-x'   => array(),
		'overflow-y'   => array(),
		'fill'         => array(),
		'stroke-width' => array(),
		'outline-offset' => array(),
	);

	/**
	 * Families that only group when the value is one upstream recognises.
	 *
	 * tailwind-merge validates the value before it assigns a group, and it
	 * knows only the DEFAULT scales — so `p-gutter`, where `gutter` is the
	 * design's own spacing token, is not in the padding group at all and
	 * twMerge keeps it beside a later `p-4`. Grouping it anyway would drop a
	 * class upstream keeps, which is the one direction that loses content.
	 * Families whose values are a closed set of keywords are absent from this
	 * list and group unconditionally.
	 *
	 * @var array<int, string>
	 */
	private const SCALED = array(
		'p', 'px', 'py', 'pt', 'pr', 'pb', 'pl', 'ps', 'pe',
		'm', 'mx', 'my', 'mt', 'mr', 'mb', 'ml', 'ms', 'me',
		'w', 'h', 'size', 'min-w', 'min-h', 'max-w', 'max-h', 'aspect',
		'inset', 'inset-x', 'inset-y', 'top', 'right', 'bottom', 'left', 'start', 'end',
		'gap', 'gap-x', 'gap-y', 'space-x', 'space-y',
		'basis', 'grow', 'shrink', 'order', 'z', 'opacity',
		'grid-cols', 'grid-rows', 'col-span', 'col-start', 'col-end',
		'row-span', 'row-start', 'row-end',
		'leading', 'tracking', 'indent', 'underline-offset',
		'duration', 'delay', 'blur', 'brightness', 'contrast', 'saturate',
		'grayscale', 'invert', 'sepia',
		'scale', 'scale-x', 'scale-y', 'rotate', 'translate-x', 'translate-y',
		'skew-x', 'skew-y', 'stroke-width', 'outline-offset',
	);

	/** Non-numeric values the default scales do ship. */
	private const SCALE_WORDS = array(
		'px', 'auto', 'full', 'screen', 'min', 'max', 'fit', 'none', 'normal',
		'reverse', 'initial', 'prose', 'dvh', 'dvw', 'lvh', 'lvw', 'svh', 'svw',
		'tight', 'tighter', 'snug', 'relaxed', 'loose', 'wide', 'wider', 'widest',
		'3xs', '2xs', 'xs', 'sm', 'md', 'lg', 'xl', '2xl', '3xl', '4xl', '5xl',
		'6xl', '7xl', '8xl', '9xl',
	);

	/**
	 * Merge a joined class list the way `twMerge` does.
	 */
	public static function merge( string $classes ): string {
		$tokens = preg_split( '/\s+/', trim( $classes ) ) ?: array();
		$kept   = array();

		foreach ( $tokens as $token ) {
			if ( $token === '' ) {
				continue;
			}

			$group = self::classify( $token );
			if ( $group === null ) {
				$kept[] = array( 'class' => $token, 'variants' => '', 'group' => null, 'overrides' => array() );
				continue;
			}

			$beaten = array_merge( array( $group['group'] ), $group['overrides'] );
			foreach ( $kept as $index => $earlier ) {
				if ( $earlier['group'] === null || $earlier['variants'] !== $group['variants'] ) {
					continue;
				}
				if ( in_array( $earlier['group'], $beaten, true ) ) {
					unset( $kept[ $index ] );
				}
			}
			$kept   = array_values( $kept );
			$kept[] = array(
				'class'     => $token,
				'variants'  => $group['variants'],
				'group'     => $group['group'],
				'overrides' => $group['overrides'],
			);
		}

		return implode( ' ', array_column( $kept, 'class' ) );
	}

	/**
	 * The group a class belongs to, or null when this table cannot say.
	 *
	 * @return array{group: string, overrides: array<int, string>, variants: string}|null
	 */
	private static function classify( string $class ): ?array {
		$candidate = Candidate::parse( $class );
		if ( ! $candidate instanceof Candidate ) {
			return null;
		}

		/*
		 * Variants partition everything, and their ORDER does not: upstream
		 * sorts them before comparing, so `md:hover:` and `hover:md:` are the
		 * same bucket and do conflict with each other.
		 */
		$variants = $candidate->variants;
		sort( $variants );
		$bucket = implode( ':', $variants );

		$base = ltrim( $candidate->base, '!' );
		$made = self::group_for( $base );
		if ( $made === null ) {
			return null;
		}

		return array(
			'group'     => $made[0],
			'overrides' => $made[1],
			'variants'  => $bucket,
		);
	}

	/**
	 * @return array{0: string, 1: array<int, string>}|null
	 */
	private static function group_for( string $base ): ?array {
		if ( isset( self::KEYWORDS[ $base ] ) ) {
			return array( self::KEYWORDS[ $base ], array() );
		}

		// The families whose value can be a colour OR something else, where
		// only the value says which group the class is in.
		$ambiguous = self::ambiguous( $base );
		if ( $ambiguous !== null ) {
			return $ambiguous;
		}

		// `flex-1`, `flex-auto`, `flex-initial`, `flex-none` — the shorthand,
		// not the direction keywords above, which KEYWORDS already took.
		if ( $base === 'flex' || str_starts_with( $base, 'flex-' ) ) {
			return array( 'flex', array() );
		}

		$best = null;
		foreach ( self::FAMILIES as $prefix => $overrides ) {
			if ( $base !== $prefix && ! str_starts_with( $base, $prefix . '-' ) ) {
				continue;
			}
			if ( $best === null || strlen( $prefix ) > strlen( (string) $best ) ) {
				$best = $prefix;
			}
		}

		if ( $best === null ) {
			return null;
		}

		// A design's own token is not on any scale upstream knows, so upstream
		// does not group the class — see SCALED.
		if ( in_array( $best, self::SCALED, true ) ) {
			$value = $base === $best ? '' : substr( $base, strlen( $best ) + 1 );
			if ( ! self::is_scale_value( $value ) ) {
				return null;
			}
		}

		return array( $best, self::FAMILIES[ $best ] );
	}

	/**
	 * Whether a value sits on one of the default scales.
	 */
	private static function is_scale_value( string $value ): bool {
		if ( $value === '' ) {
			return true;
		}
		if ( self::is_bracketed( $value ) || self::is_number( $value ) ) {
			return true;
		}
		// `w-1/2`, `basis-2/3`.
		if ( preg_match( '#^\d+/\d+$#', $value ) === 1 ) {
			return true;
		}
		// `max-w-screen-md`, `w-screen`.
		if ( str_starts_with( $value, 'screen-' ) ) {
			return true;
		}

		return in_array( $value, self::SCALE_WORDS, true );
	}

	/**
	 * Families where the value decides the group.
	 *
	 * @return array{0: string, 1: array<int, string>}|null
	 */
	private static function ambiguous( string $base ): ?array {
		// `text-*`: an alignment, a wrap mode, a font size, or a colour.
		if ( str_starts_with( $base, 'text-' ) ) {
			$rest = substr( $base, strlen( 'text-' ) );
			if ( in_array( $rest, array( 'left', 'center', 'right', 'justify', 'start', 'end' ), true ) ) {
				return array( 'text-align', array() );
			}
			if ( in_array( $rest, array( 'wrap', 'nowrap', 'balance', 'pretty' ), true ) ) {
				return array( 'text-wrap', array() );
			}
			if ( in_array( $rest, self::FONT_SIZES, true ) || self::is_bracketed( $rest ) ) {
				return array( 'font-size', array() );
			}

			return array( 'text-color', array() );
		}

		// `font-*`: a weight or a family.
		if ( str_starts_with( $base, 'font-' ) ) {
			$rest = substr( $base, strlen( 'font-' ) );

			return in_array( $rest, self::FONT_WEIGHTS, true ) || self::is_number( $rest )
				? array( 'font-weight', array() )
				: array( 'font-family', array() );
		}

		// `rounded*`: a radius on every corner, on one side, or on one corner.
		if ( $base === 'rounded' || str_starts_with( $base, 'rounded-' ) ) {
			$rest    = $base === 'rounded' ? '' : substr( $base, strlen( 'rounded-' ) );
			$segment = $rest === '' ? '' : explode( '-', $rest )[0];

			if ( in_array( $segment, self::CORNERS, true ) ) {
				return array( 'rounded-' . $segment, array() );
			}
			if ( in_array( $segment, self::EDGES, true ) ) {
				return array( 'rounded-' . $segment, self::radius_corners( $segment ) );
			}

			return array( 'rounded', self::radius_all() );
		}

		// `border*`: a width or a colour, per side.
		if ( $base === 'border' || str_starts_with( $base, 'border-' ) ) {
			$rest    = $base === 'border' ? '' : substr( $base, strlen( 'border-' ) );
			$segment = $rest === '' ? '' : explode( '-', $rest )[0];
			$side    = in_array( $segment, self::EDGES, true ) ? $segment : '';
			$value   = $side === '' ? $rest : substr( $rest, strlen( $side ) + 1 );

			// `border`, `border-2`, `border-x`, `border-x-2`, `border-[3px]`.
			$is_width = $value === '' || self::is_number( $value ) || self::is_bracketed( $value );
			$kind     = $is_width ? 'border-w' : 'border-color';
			if ( $side === '' ) {
				return array( $kind, self::border_sides( $kind ) );
			}

			return array( $kind . '-' . $side, self::border_sides( $kind . '-' . $side ) );
		}

		/*
		 * The `-offset-` families, BEFORE their parent prefix can claim them.
		 *
		 * `ring-offset-2` is not a ring, and `outline-offset-2` is not an
		 * outline: they write different properties. Letting the `ring-`
		 * branch below see them put `ring-offset-2` in the ring-COLOUR group,
		 * where it deleted the `focus-visible:ring-ring` beside it — measured
		 * on the shadcn Input, whose focus ring lost its colour.
		 */
		foreach ( array( 'ring-offset', 'outline-offset' ) as $prefix ) {
			if ( $base !== $prefix && ! str_starts_with( $base, $prefix . '-' ) ) {
				continue;
			}
			$rest = $base === $prefix ? '' : substr( $base, strlen( $prefix ) + 1 );

			return self::is_number( $rest ) || self::is_bracketed( $rest ) || $rest === ''
				? array( $prefix . '-w', array() )
				: array( $prefix . '-color', array() );
		}

		/*
		 * Gradient stops and background images are not background colours.
		 * `bg-gradient-to-r bg-primary` is a gradient over a colour and both
		 * survive upstream; one group for both would drop the first.
		 */
		if ( str_starts_with( $base, 'bg-gradient' ) || $base === 'bg-none' ) {
			return array( 'bg-image', array() );
		}

		/*
		 * The remaining colour-or-other families. `bg-primary` is a colour and
		 * `bg-cover` is a background-size; `shadow-lg` is a size and
		 * `shadow-card` may be either, which is why the size lists are
		 * explicit and everything else is the colour group. Getting this the
		 * wrong way round would merge a colour with a size and lose one of
		 * them, so each list names only values upstream ships.
		 */
		$split = array(
			'bg'        => array(
				'other' => array(
					'fixed', 'local', 'scroll', 'bottom', 'center', 'left', 'left-bottom', 'left-top',
					'right', 'right-bottom', 'right-top', 'top', 'repeat', 'no-repeat', 'repeat-x',
					'repeat-y', 'repeat-round', 'repeat-space', 'auto', 'cover', 'contain', 'none',
					'clip-border', 'clip-padding', 'clip-content', 'clip-text',
					'origin-border', 'origin-padding', 'origin-content',
					'blend-normal', 'blend-multiply', 'blend-screen', 'blend-overlay',
				),
				'group' => 'bg-color',
				'alt'   => 'bg-other',
			),
			'shadow'    => array(
				'other' => array( '', '2xs', 'xs', 'sm', 'md', 'lg', 'xl', '2xl', 'inner', 'none' ),
				'group' => 'shadow-color',
				'alt'   => 'shadow-size',
			),
			'ring'      => array(
				'other' => array( '', '0', '1', '2', '3', '4', '8', 'inset' ),
				'group' => 'ring-color',
				'alt'   => 'ring-w',
			),
			'outline'   => array(
				'other' => array( '', '0', '1', '2', '4', '8', 'none', 'dashed', 'dotted', 'double', 'hidden', 'solid' ),
				'group' => 'outline-color',
				'alt'   => 'outline-w',
			),
			'divide-x'  => array(
				'other' => array( '', '0', '2', '4', '8', 'reverse' ),
				'group' => 'divide-x-color',
				'alt'   => 'divide-x-w',
			),
			'divide-y'  => array(
				'other' => array( '', '0', '2', '4', '8', 'reverse' ),
				'group' => 'divide-y-color',
				'alt'   => 'divide-y-w',
			),
			'stroke'    => array(
				'other' => array( '0', '1', '2' ),
				'group' => 'stroke-color',
				'alt'   => 'stroke-w',
			),
		);

		foreach ( $split as $prefix => $rule ) {
			if ( $base !== $prefix && ! str_starts_with( $base, $prefix . '-' ) ) {
				continue;
			}
			$rest = $base === $prefix ? '' : substr( $base, strlen( $prefix ) + 1 );

			return in_array( $rest, $rule['other'], true ) || self::is_bracketed( $rest )
				? array( $rule['alt'], array() )
				: array( $rule['group'], array() );
		}

		return null;
	}

	/**
	 * Every radius group, for the `rounded` shorthand to override.
	 *
	 * @return array<int, string>
	 */
	private static function radius_all(): array {
		$out = array();
		foreach ( array_merge( self::EDGES, self::CORNERS ) as $part ) {
			$out[] = 'rounded-' . $part;
		}

		return $out;
	}

	/**
	 * The corners one radius side covers.
	 *
	 * @return array<int, string>
	 */
	private static function radius_corners( string $side ): array {
		$map = array(
			't' => array( 'tl', 'tr' ),
			'r' => array( 'tr', 'br' ),
			'b' => array( 'br', 'bl' ),
			'l' => array( 'tl', 'bl' ),
			's' => array( 'ss', 'es' ),
			'e' => array( 'se', 'ee' ),
			'x' => array( 'tl', 'tr', 'br', 'bl' ),
			'y' => array( 'tl', 'tr', 'br', 'bl' ),
		);

		$out = array();
		foreach ( $map[ $side ] ?? array() as $corner ) {
			$out[] = 'rounded-' . $corner;
		}

		return $out;
	}

	/**
	 * The per-side groups a border shorthand overrides.
	 *
	 * @return array<int, string>
	 */
	private static function border_sides( string $group ): array {
		$kind = str_starts_with( $group, 'border-color' ) ? 'border-color' : 'border-w';
		$side = substr( $group, strlen( $kind ) );

		$covers = array(
			''   => array( 'x', 'y', 't', 'r', 'b', 'l', 's', 'e' ),
			'-x' => array( 'r', 'l', 's', 'e' ),
			'-y' => array( 't', 'b' ),
		);

		$out = array();
		foreach ( $covers[ $side ] ?? array() as $one ) {
			$out[] = $kind . '-' . $one;
		}

		return $out;
	}

	private static function is_number( string $value ): bool {
		return $value !== '' && preg_match( '/^\d+(?:\.\d+)?$/', $value ) === 1;
	}

	private static function is_bracketed( string $value ): bool {
		return ( str_starts_with( $value, '[' ) && str_ends_with( $value, ']' ) )
			|| ( str_starts_with( $value, '(' ) && str_ends_with( $value, ')' ) );
	}
}

<?php
/**
 * Generates scoped CSS for the Tailwind classes a design actually uses.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler\Tailwind;

final class Engine {

	/** @var array<int, Utility_Module> */
	private array $modules;

	private Theme $theme;

	/** @var array<int, string> Classes no module claimed, for diagnostics. */
	private array $unresolved = array();

	/**
	 * @param array<int, Utility_Module>|null $modules
	 */
	public function __construct( Theme $theme, ?array $modules = null ) {
		$this->theme   = $theme;
		$this->modules = $modules ?? self::default_modules();
	}

	/**
	 * Registration order decides which module answers first, so the narrow
	 * namespaces are asked before the broad ones.
	 *
	 * @return array<int, Utility_Module>
	 */
	public static function default_modules(): array {
		$classes = array(
			Utilities\Motion::class,
			// After Motion, which owns the real `animate-*` namespace and
			// returns null for the plugin's own `animate-in` / `animate-out`.
			Utilities\Animate::class,
			Utilities\Typography::class,
			Utilities\Flexgrid::class,
			Utilities\Border::class,
			Utilities\Background::class,
			// Next to Background, its nearest neighbour: `mask-image`,
			// `mask-position`, `mask-size`, `mask-repeat`, `mask-origin` and
			// `mask-clip` are the twins of the `bg-*` forms above.
			Utilities\Mask::class,
			Utilities\Effects::class,
			// Its declarations are placed by the `::placeholder` suffix in
			// child_combinator(); the two halves are one feature.
			Utilities\Placeholder::class,
			Utilities\Interactivity::class,
			Utilities\Spacing::class,
			Utilities\Layout::class,
		);

		$modules = array();
		foreach ( $classes as $class ) {
			if ( class_exists( $class ) ) {
				$modules[] = new $class();
			}
		}

		return $modules;
	}

	/**
	 * Compile every utility found in the markup into CSS scoped to $scope.
	 */
	public function compile( string $markup, string $scope ): string {
		$this->unresolved = array();
		$rules            = array();
		$index            = 0;

		foreach ( self::extract_classes( $markup ) as $class ) {
			$candidate = Candidate::parse( $class );
			if ( ! $candidate instanceof Candidate ) {
				continue;
			}

			$declarations = $this->declarations( $candidate );
			if ( $declarations === null ) {
				$this->unresolved[] = $class;
				continue;
			}
			if ( $declarations === array() ) {
				continue;
			}

			$variant = Variants::resolve( $candidate->variants, $this->theme );
			if ( $variant === null ) {
				$this->unresolved[] = $class;
				continue;
			}

			/*
			 * A generated `::before`/`::after` needs `content` or the browser
			 * does not render the box at all, so upstream pairs every such
			 * variant with it. Variants deliberately leaves this to us — only
			 * the utility modules emit declarations — and nothing was doing it,
			 * so a design's `before:` decoration silently disappeared unless
			 * the author also wrote `before:content-['']`. The `--tw-content`
			 * indirection is what lets that class override the empty default.
			 */
			if ( preg_match( '/::(before|after)$/', (string) $variant['suffix'] ) === 1
				&& ! isset( $declarations['content'] ) ) {
				$declarations = array( 'content' => 'var(--tw-content, "")' ) + $declarations;
			}

			$rules[] = array(
				'at_rules' => $variant['at_rules'],
				'selector' => $this->selector( $scope, $class, $variant ),
				'body'     => self::declaration_block( $declarations ),
				'weight'   => (int) $variant['weight'],
				'base'     => $candidate->base,
				'depth'    => count( $candidate->variants ),
				'index'    => $index++,
			);

			foreach ( $this->conditional_blocks( $candidate->base ) as $extra ) {
				$rules[] = array(
					'at_rules' => array_merge( $variant['at_rules'], array( $extra['at_rule'] ) ),
					'selector' => $this->selector( $scope, $class, $variant ),
					'body'     => self::declaration_block( $extra['declarations'] ),
					'weight'   => (int) $variant['weight'],
					'base'     => $candidate->base,
					'depth'    => count( $candidate->variants ),
					'index'    => $index++,
				);
			}
		}

		return $this->emit( $rules ) . $this->emit_keyframes( $scope );
	}

	/**
	 * Blocks a utility needs *in addition* to its own declarations, each under
	 * its own at-rule.
	 *
	 * A module returns one declaration block, which is the right shape for
	 * almost every utility. Two are not: `container` is `width: 100%` plus a
	 * `max-width` at every breakpoint, and `outline-hidden` has to restore a
	 * visible outline under forced colours. Both could previously emit only
	 * their unconditional half — a design using Tailwind's own wrapper class
	 * got a full-bleed page at every size above mobile.
	 *
	 * @return array<int, array{at_rule:string, declarations:array<string, string>}>
	 */
	private function conditional_blocks( string $base ): array {
		if ( $base === 'container' ) {
			$out = array();
			/*
			 * A v3 `theme.container.screens` REPLACES the breakpoint caps
			 * rather than adding to them: the stock Lovable config lists only
			 * `2xl: 1400px`, which means the container is fluid up to 1400px
			 * and capped there — not capped at 640/768/1024/1280 on the way.
			 * Using the design's own breakpoints for both would have narrowed
			 * every section on every screen above `sm`.
			 */
			$config = $this->theme->container_config();
			$sizes  = ! empty( $config['screens'] ) ? $config['screens'] : $this->theme->screens();
			foreach ( $sizes as $size ) {
				$out[] = array(
					'at_rule'      => '@media (min-width: ' . $size . ')',
					'declarations' => array( 'max-width' => $size ),
				);
			}

			return $out;
		}

		if ( $base === 'outline-hidden' ) {
			return array(
				array(
					'at_rule'      => '@media (forced-colors: active)',
					'declarations' => array(
						'outline'        => '2px solid transparent',
						'outline-offset' => '2px',
					),
				),
			);
		}

		return array();
	}

	/**
	 * Classes the engine could not turn into CSS. Useful for coverage checks.
	 *
	 * @return array<int, string>
	 */
	public function unresolved(): array {
		return array_values( array_unique( $this->unresolved ) );
	}

	/**
	 * True when $class is a Tailwind utility (or variant stack), not a
	 * design-authored class such as `mobile-section-nav`.
	 */
	public static function looks_like_utility( string $class ): bool {
		$candidate = Candidate::parse( $class );
		if ( ! $candidate instanceof Candidate ) {
			return false;
		}
		if ( $candidate->variants !== array() || $candidate->modifier !== '' || $candidate->is_arbitrary() ) {
			return true;
		}

		$base = $candidate->base;
		if ( isset( self::UTILITY_KEYWORDS[ $base ] ) ) {
			return true;
		}

		$root = explode( '-', $base )[0];

		return isset( self::UTILITY_ROOTS[ $root ] );
	}

	/**
	 * Single-token utilities with no hyphen.
	 *
	 * @var array<string, true>
	 */
	private const UTILITY_KEYWORDS = array(
		'flex' => true, 'grid' => true, 'hidden' => true, 'block' => true, 'inline' => true,
		'contents' => true, 'truncate' => true, 'italic' => true, 'underline' => true,
		'overline' => true, 'uppercase' => true, 'lowercase' => true, 'capitalize' => true,
		'visible' => true, 'invisible' => true, 'collapse' => true, 'isolate' => true,
		'static' => true, 'absolute' => true, 'relative' => true, 'sticky' => true, 'fixed' => true,
		'container' => true, 'antialiased' => true, 'grow' => true, 'shrink' => true,
		'border' => true, 'rounded' => true, 'shadow' => true, 'ring' => true, 'outline' => true,
		'transition' => true, 'transform' => true, 'resize' => true, 'filter' => true,
		'table' => true, 'list-item' => true, 'sr-only' => true, 'not-sr-only' => true,
	);

	/**
	 * First hyphen segment of a utility (`bg-red-500` → `bg`).
	 *
	 * @var array<string, true>
	 */
	/*
	 * Deliberately absent: `block` and `inline`. Both are real utility roots
	 * (`block-40` is `block-size`, `inline-40` is `inline-size`), but this table
	 * only decides whether an *unresolved* class is worth reporting, and a
	 * design class named `.block-title` is far likelier than a design reaching
	 * for the logical sizing family. The parity harness covers those families
	 * properly and does not consult this table, so the honest report is the one
	 * that stays quiet here.
	 */
	private const UTILITY_ROOTS = array(
		'accent' => true, 'align' => true, 'animate' => true, 'aspect' => true, 'auto' => true,
		'backdrop' => true, 'basis' => true, 'bg' => true, 'blur' => true, 'border' => true,
		'bottom' => true, 'break' => true, 'brightness' => true, 'caret' => true, 'clear' => true,
		'col' => true, 'columns' => true, 'content' => true, 'contrast' => true, 'cursor' => true,
		'decoration' => true, 'delay' => true, 'divide' => true, 'drop' => true, 'duration' => true,
		'ease' => true, 'end' => true, 'fill' => true, 'filter' => true, 'flex' => true,
		'float' => true, 'font' => true, 'from' => true, 'gap' => true, 'grayscale' => true,
		'grid' => true, 'grow' => true, 'h' => true, 'hue' => true, 'hyphens' => true,
		'indent' => true, 'inline' => true, 'inset' => true, 'invert' => true, 'items' => true,
		'justify' => true, 'leading' => true, 'left' => true, 'line' => true, 'list' => true,
		'm' => true, 'max' => true, 'mb' => true, 'me' => true, 'min' => true, 'mix' => true,
		'mask' => true, 'mbe' => true, 'mbs' => true, 'ml' => true, 'mr' => true,
		'ms' => true, 'mt' => true, 'mx' => true, 'my' => true,
		'object' => true, 'opacity' => true, 'order' => true, 'origin' => true, 'outline' => true,
		'overflow' => true, 'overscroll' => true, 'p' => true, 'pb' => true,
		'pbe' => true, 'pbs' => true, 'pe' => true, 'placeholder' => true,
		'perspective' => true, 'pl' => true, 'place' => true, 'pointer' => true, 'pr' => true,
		'prose' => true, 'ps' => true, 'pt' => true, 'px' => true, 'py' => true, 'resize' => true,
		'right' => true, 'ring' => true, 'rotate' => true, 'rounded' => true, 'row' => true,
		'saturate' => true, 'scale' => true, 'scroll' => true, 'select' => true, 'self' => true,
		'sepia' => true, 'shadow' => true, 'shrink' => true, 'size' => true, 'skew' => true,
		'snap' => true, 'space' => true, 'sr' => true, 'start' => true, 'stroke' => true,
		'table' => true, 'text' => true, 'to' => true, 'top' => true, 'tracking' => true,
		'transform' => true, 'transition' => true, 'translate' => true, 'via' => true,
		'w' => true, 'whitespace' => true, 'will' => true, 'z' => true,
	);

	/**
	 * Canonical emission order, taken from the Tailwind v4 CLI's own output.
	 *
	 * Two utilities writing the same property must land in the order Tailwind
	 * would emit them, or whichever the markup happens to list last wins and
	 * pairs like `md:px-6` / `md:px-0` resolve backwards. Values are ranks; a
	 * base that is absent sorts after the known ones, in appearance order.
	 *
	 * KNOWN LIMIT. This table names 243 classes and the engine emits over
	 * 13,700, so the great majority are unranked and tie with one another. The
	 * tie only bites where two utilities on one element write the same
	 * property — a shorthand against its own longhand (`my-4` with `mbs-4`), or
	 * one property twice — and there the outcome follows markup order instead
	 * of Tailwind's. It is deliberately not extended by guesswork: the ranks
	 * here were read off real CLI output for the classes seven designs
	 * actually use, and inventing ranks for the rest would disturb that.
	 *
	 * The parity harness cannot see this. It compares each class on its own —
	 * selector shape, property set, conditions — and two classes that are
	 * individually right can still be emitted in the wrong order. Every
	 * ordering fact recorded here came from reading CLI output or from a
	 * reviewer tracing the sort, never from the harness going green.
	 *
	 * @var array<string, int>
	 */
	private const UTILITY_ORDER = array(
		'pointer-events-none' => 0,
		'absolute' => 1,
		'relative' => 2,
		'sticky' => 3,
		'inset-0' => 4,
		'top-0' => 5,
		'top-3' => 6,
		'bottom-4' => 7,
		'left-3' => 8,
		'left-4' => 9,
		'z-30' => 10,
		'm-0' => 11,
		'mx-0' => 12,
		'mx-2' => 13,
		'mx-4' => 14,
		'mx-auto' => 15,
		'my-0' => 16,
		'my-2' => 17,
		'my-4' => 18,
		'mt-0' => 19,
		'mt-0.5' => 20,
		'mt-1' => 21,
		'mt-1.5' => 22,
		'mt-2' => 23,
		'mt-3' => 24,
		'mt-4' => 25,
		'mt-5' => 26,
		'mt-6' => 27,
		'mt-7' => 28,
		'mt-8' => 29,
		'mt-10' => 30,
		'mr-1.5' => 31,
		'mb-0' => 32,
		'block' => 33,
		'contents' => 34,
		'flex' => 35,
		'grid' => 36,
		'hidden' => 37,
		'inline-flex' => 38,
		'aspect-[16/10]' => 39,
		'size-full' => 40,
		'h-0' => 41,
		'h-3.5' => 42,
		'h-4' => 43,
		'h-5' => 44,
		'h-6' => 45,
		'h-full' => 46,
		'min-h-0' => 47,
		'min-h-[44px]' => 48,
		'min-h-[48px]' => 49,
		'min-h-screen' => 50,
		'w-0' => 51,
		'w-3.5' => 52,
		'w-4' => 53,
		'w-5' => 54,
		'w-6' => 55,
		'w-full' => 56,
		'max-w-2xl' => 57,
		'max-w-3xl' => 58,
		'max-w-4xl' => 59,
		'max-w-7xl' => 60,
		'max-w-[580px]' => 61,
		'max-w-[600px]' => 62,
		'max-w-none' => 63,
		'max-w-xl' => 64,
		'min-w-0' => 65,
		'flex-1' => 66,
		'flex-none' => 67,
		'shrink-0' => 68,
		'translate-x-0.5' => 69,
		'translate-y-0' => 70,
		'translate-y-0.5' => 71,
		'scale-[1.02]' => 72,
		'scale-[1.04]' => 73,
		'rotate-0' => 74,
		'cursor-pointer' => 75,
		'snap-x' => 76,
		'snap-mandatory' => 77,
		'grid-cols-1' => 78,
		'grid-cols-2' => 79,
		'grid-cols-3' => 80,
		'grid-cols-4' => 81,
		'grid-cols-[1.05fr_1fr]' => 82,
		'flex-col' => 83,
		'flex-row' => 84,
		'flex-wrap' => 85,
		'place-items-center' => 86,
		'items-center' => 87,
		'items-start' => 88,
		'justify-between' => 89,
		'justify-center' => 90,
		'gap-0' => 91,
		'gap-1' => 92,
		'gap-1.5' => 93,
		'gap-2' => 94,
		'gap-2.5' => 95,
		'gap-3' => 96,
		'gap-4' => 97,
		'gap-5' => 98,
		'gap-6' => 99,
		'gap-10' => 100,
		'gap-14' => 101,
		'gap-x-10' => 102,
		'divide-x' => 103,
		'divide-y' => 104,
		'divide-ara-border' => 105,
		'truncate' => 106,
		'overflow-hidden' => 107,
		'overflow-visible' => 108,
		'overflow-x-auto' => 109,
		'rounded-2xl' => 110,
		'rounded-full' => 111,
		'rounded-lg' => 112,
		'rounded-none' => 113,
		'rounded-xl' => 114,
		'border' => 115,
		'border-0' => 116,
		'border-x-0' => 117,
		'border-y' => 118,
		'border-y-0' => 119,
		'border-t-0' => 120,
		'border-r-0' => 121,
		'border-b' => 122,
		'border-b-0' => 123,
		'border-l' => 124,
		'border-l-0' => 125,
		'border-l-4' => 126,
		'border-l-[3px]' => 127,
		'border-[#1E3A5F]' => 128,
		'border-ara-border' => 129,
		'border-ara-green' => 130,
		'border-ara-insight-border' => 131,
		'border-ara-warning-border' => 132,
		'border-white' => 133,
		'bg-[var(--ara-row-hover)]' => 134,
		'bg-[var(--ara-tint)]' => 135,
		'bg-ara-green' => 136,
		'bg-ara-insight-bg' => 137,
		'bg-ara-navy' => 138,
		'bg-ara-surface' => 139,
		'bg-ara-tint' => 140,
		'bg-ara-warning-bg' => 141,
		'bg-white' => 142,
		'object-cover' => 143,
		'p-0' => 144,
		'p-4' => 145,
		'p-5' => 146,
		'p-6' => 147,
		'p-8' => 148,
		'px-0' => 149,
		'px-1' => 150,
		'px-2' => 151,
		'px-2.5' => 152,
		'px-3' => 153,
		'px-4' => 154,
		'px-5' => 155,
		'px-6' => 156,
		'px-7' => 157,
		'px-8' => 158,
		'py-0' => 159,
		'py-0.5' => 160,
		'py-1' => 161,
		'py-2' => 162,
		'py-3' => 163,
		'py-3.5' => 164,
		'py-4' => 165,
		'py-6' => 166,
		'py-8' => 167,
		'py-10' => 168,
		'py-12' => 169,
		'py-14' => 170,
		'py-16' => 171,
		'py-20' => 172,
		'py-24' => 173,
		'py-28' => 174,
		'pt-0' => 175,
		'pr-0' => 176,
		'pb-0' => 177,
		'pb-2' => 178,
		'pb-4' => 179,
		'pl-0' => 180,
		'pl-4' => 181,
		'text-center' => 182,
		'text-left' => 183,
		'text-base' => 184,
		'text-lg' => 185,
		'text-sm' => 186,
		'text-[10px]' => 187,
		'text-[11px]' => 188,
		'text-[12px]' => 189,
		'text-[13px]' => 190,
		'text-[14px]' => 191,
		'text-[15px]' => 192,
		'text-[16px]' => 193,
		'text-[17px]' => 194,
		'leading-[1.2]' => 195,
		'leading-[1.05]' => 196,
		'leading-none' => 197,
		'leading-relaxed' => 198,
		'leading-snug' => 199,
		'leading-tight' => 200,
		'font-bold' => 201,
		'font-extrabold' => 202,
		'font-semibold' => 203,
		'tracking-wider' => 204,
		'tracking-widest' => 205,
		'text-balance' => 206,
		'whitespace-nowrap' => 207,
		'text-ara-green' => 208,
		'text-ara-green-foreground' => 209,
		'text-ara-insight-border' => 210,
		'text-ara-muted-text' => 211,
		'text-ara-navy' => 212,
		'text-ara-warning-border' => 213,
		'text-foreground' => 214,
		'text-white' => 215,
		'uppercase' => 216,
		'no-underline' => 217,
		'underline' => 218,
		'opacity-0' => 219,
		'opacity-70' => 220,
		'opacity-80' => 221,
		'opacity-100' => 222,
		'shadow-[0_8px_24px_-8px_rgba(0' => 223,
		'shadow-[0_8px_24px_-8px_rgba(99' => 224,
		'shadow-[0_12px_32px_-16px_rgba(10' => 225,
		'shadow-[0_30px_80px_-40px_rgba(0' => 226,
		'shadow-none' => 227,
		'shadow-xl' => 228,
		'backdrop-blur' => 229,
		'transition' => 230,
		'transition-[grid-template-rows]' => 231,
		'transition-all' => 232,
		'transition-colors' => 233,
		'transition-opacity' => 234,
		'transition-transform' => 235,
		'transition-none' => 236,
		'duration-150' => 237,
		'duration-200' => 238,
		'duration-300' => 239,
		'duration-500' => 240,
		'duration-700' => 241,
		'ease-out' => 242,
	);

	/**
	 * Families where some members contribute a slot to a shared shorthand and
	 * others overwrite that shorthand outright.
	 *
	 * The contributors have to be emitted first, or a `contain-none` beside a
	 * `contain-layout` resolves to whichever the markup happened to list last.
	 * Both entries were read off the CLI's own output rather than reasoned
	 * about: masks compose across upstream lines 38332-91075 with the four
	 * composite keywords and `mask-none` after them, and `contain-*` composes
	 * across 112684-112706 with `contain-content`, `-none` and `-strict` at
	 * 112707-112712.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const SHORTHAND_OVERRIDES = array(
		'mask-'    => array( 'mask-none', 'mask-add', 'mask-subtract', 'mask-intersect', 'mask-exclude' ),
		'contain-' => array( 'contain-none', 'contain-content', 'contain-strict' ),
	);

	/**
	 * Families that share a CSS property with another family and are NOT
	 * emitted in the order a natural sort of the class name would give.
	 *
	 * Measured, not reasoned: for each property the reference build declares,
	 * the classes that declare it were taken in emission order and compared
	 * against a natural sort of their names. 232 of 265 property groups agree
	 * exactly, so natural sort is the general rule and this table is only the
	 * exceptions. The sequences below are read off that build:
	 *
	 *   line-height             text → text-base → leading
	 *   border-top-left-radius  rounded-t → rounded-l → rounded-tl
	 *   width / height          sr-only → not-sr-only → container → size → w
	 *   padding / margin        sr-only → not-sr-only → -m → m
	 *
	 * The first is the one that bites in practice: a font-size utility sets
	 * `line-height` as well, and upstream emits the whole font-size family
	 * before `leading-*`, which is why `text-lg leading-tight` leaves the
	 * explicit leading in charge. Natural sort puts `leading-tight` first and
	 * would hand the line-height back to `text-lg`.
	 *
	 * Longest prefix wins. A family absent from the table sorts between the
	 * keyword utilities and the rest, where natural sort decides.
	 *
	 * @var array<string, int>
	 */
	private const FAMILY_ORDER = array(
		'sr-only'     => 10,
		'not-sr-only' => 11,
		'container'   => 12,
		// `size-*` writes both axes and `w-*`/`h-*` write one, and upstream
		// emits `size` first, so the single-axis utility is the one that wins.
		'size'        => 40,
		'w'           => 41,
		'h'           => 41,
		// Every `text-*` shares one rank: the colours and the sizes write
		// different properties, so they never contend with each other, and
		// natural sort orders each set correctly on its own.
		'text'        => 50,
		'leading'     => 51,
		'rounded-t'   => 60,
		'rounded-r'   => 61,
		'rounded-b'   => 62,
		'rounded-l'   => 63,
		'rounded-tl'  => 64,
		'rounded-tr'  => 65,
		'rounded-br'  => 66,
		'rounded-bl'  => 67,
	);

	/** Where a family with no entry of its own sits. */
	private const FAMILY_DEFAULT = 30;

	/**
	 * The family rank for a base: the longest matching prefix in the table.
	 */
	private static function family_rank( string $base ): int {
		$name = ltrim( $base, '-' );
		$best = null;
		foreach ( self::FAMILY_ORDER as $prefix => $order ) {
			if ( $name !== $prefix && ! str_starts_with( $name, $prefix . '-' ) ) {
				continue;
			}
			if ( $best === null || strlen( $prefix ) > strlen( $best ) ) {
				$best = $prefix;
			}
		}

		return $best === null ? self::FAMILY_DEFAULT : self::FAMILY_ORDER[ $best ];
	}

	/**
	 * Compare two bases the way the reference build orders them: alphabetically,
	 * except that a run of digits compares as a number.
	 *
	 * Plain string order puts `amber-100` before `amber-50`, and the reference
	 * build does not: it emits the scale ascending. Nothing here depends on the
	 * digits being a spacing step rather than a shade — only on them being read
	 * as a quantity.
	 */
	private static function natural_compare( string $a, string $b ): int {
		$left  = preg_split( '/(\d+)/', $a, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY ) ?: array();
		$right = preg_split( '/(\d+)/', $b, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY ) ?: array();

		$count = max( count( $left ), count( $right ) );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! isset( $left[ $i ] ) ) {
				return -1;
			}
			if ( ! isset( $right[ $i ] ) ) {
				return 1;
			}

			$x = $left[ $i ];
			$y = $right[ $i ];
			if ( ctype_digit( $x ) && ctype_digit( $y ) ) {
				$diff = (int) $x <=> (int) $y;
				if ( $diff !== 0 ) {
					return $diff;
				}
				continue;
			}

			$diff = strcmp( $x, $y );
			if ( $diff !== 0 ) {
				return $diff;
			}
		}

		return 0;
	}

	/**
	 * Where each family starts in the table, for bases the table does not name.
	 *
	 * @return array<string, int>
	 */
	private static function family_starts(): array {
		static $starts = null;
		if ( $starts !== null ) {
			return $starts;
		}

		$starts = array();
		foreach ( self::UTILITY_ORDER as $base => $order ) {
			$parts = explode( '-', (string) $base );
			array_pop( $parts );
			while ( $parts !== array() ) {
				$prefix = implode( '-', $parts );
				if ( ! isset( $starts[ $prefix ] ) || $order < $starts[ $prefix ] ) {
					$starts[ $prefix ] = $order;
				}
				array_pop( $parts );
			}
		}

		return $starts;
	}

	/**
	 * Rank for cascade ordering.
	 *
	 * The table is read off the reference build and therefore names only what
	 * that build emits — every class on the DEFAULT scales. A design's own
	 * token is not on it, and sorting those after everything known meant a
	 * broad utility landed after a narrow one of the same family: a card with
	 * `p-gutter pt-0`, where `gutter` is the design's spacing token, got
	 * `padding: 1.75rem` emitted after `padding-top: 0` and the card had 28px
	 * of padding the design does not. So an unnamed base takes its FAMILY's
	 * position — `p-gutter` sits where `p-*` sits — and natural order settles
	 * it against its siblings from there.
	 */
	private static function rank( string $base ): int {
		if ( isset( self::UTILITY_ORDER[ $base ] ) ) {
			return self::UTILITY_ORDER[ $base ];
		}

		/*
		 * Two families write the same property from two directions, and both
		 * halves are unranked, so they tied here and the tie was broken by the
		 * order the classes happened to appear in the markup —
		 * `mask-b-from-20 mask-add` and `contain-none contain-layout` each came
		 * out backwards about half the time. Sorting the contributors first
		 * reproduces the order upstream emits them in.
		 */
		foreach ( self::SHORTHAND_OVERRIDES as $prefix => $overrides ) {
			if ( str_starts_with( $base, $prefix ) && ! in_array( $base, $overrides, true ) ) {
				return PHP_INT_MAX - 1;
			}
		}

		$starts = self::family_starts();
		$parts  = explode( '-', ltrim( $base, '-' ) );
		array_pop( $parts );
		while ( $parts !== array() ) {
			$prefix = implode( '-', $parts );
			if ( isset( $starts[ $prefix ] ) ) {
				return $starts[ $prefix ];
			}
			array_pop( $parts );
		}

		return PHP_INT_MAX;
	}

	/**
	 * The declarations one utility class stands for, for `@apply`.
	 *
	 * A variant is refused rather than flattened. `@apply hover:bg-accent` has
	 * to become a `&:hover` rule; returning its declarations unconditionally
	 * would paint the hover colour at rest, which is worse than not expanding
	 * it — the caller can leave the rule alone and say so.
	 *
	 * @return array<string, string>|null
	 */
	public function declarations_for( string $class ): ?array {
		$candidate = Candidate::parse( $class );
		if ( $candidate === null || $candidate->variants !== array() ) {
			return null;
		}

		return $this->declarations( $candidate );
	}

	/**
	 * @return array<string, string>|null
	 */
	private function declarations( Candidate $candidate ): ?array {
		foreach ( $this->modules as $module ) {
			$found = $module->resolve( $candidate, $this->theme );
			if ( $found !== null ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * A design's root classes sit on the scoped element itself while every
	 * other utility sits on a descendant, so both shapes are emitted.
	 *
	 * @param array{at_rules:array<int, string>, prefix:string, suffix:string, weight:int} $variant
	 */
	private function selector( string $scope, string $class, array $variant ): string {
		$escaped = '.' . Candidate::escape( $class ) . $variant['suffix']
			. self::child_combinator( $class, $this->theme->is_legacy() );
		$prefix  = trim( (string) $variant['prefix'] );

		if ( $prefix !== '' ) {
			return $scope . ' ' . $prefix . ' ' . $escaped;
		}

		return $scope . $escaped . ', ' . $scope . ' ' . $escaped;
	}

	/**
	 * Utilities that paint something other than the element itself.
	 *
	 * `space-*` and `divide-*` paint the container's children, and
	 * `placeholder-*` paints the input's placeholder text. In all three the
	 * target is part of the selector, not of the declarations, so a module
	 * cannot express it: it returns the declarations and the suffix is added
	 * here.
	 */
	private static function child_combinator( string $class, bool $legacy = false ): string {
		$candidate = Candidate::parse( $class );
		if ( ! $candidate instanceof Candidate ) {
			return '';
		}
		$base = $candidate->base;
		/*
		 * The utility form, not the `placeholder:` variant — that one is a
		 * variant and already carries its own `::placeholder` suffix out of
		 * Variants::PSEUDO_ELEMENTS. This is `placeholder-red-500`, which
		 * upstream emits as `.placeholder-red-500 { &::placeholder { … } }`.
		 */
		if ( str_starts_with( $base, 'placeholder-' ) ) {
			return '::placeholder';
		}
		if ( $base === 'space-x' || str_starts_with( $base, 'space-x-' ) || $base === 'space-y' || str_starts_with( $base, 'space-y-' ) ) {
			/*
			 * `:not(:last-child)`, as Tailwind 4.2 emits. The older
			 * `> :not([hidden]) ~ :not([hidden])` matches every child but the
			 * *first*, so the margin-block-end landed after the last child
			 * instead of between the children — where it collapses out of the
			 * container and the list came out one gap short.
			 *
			 * A v3 design gets v3's selector, which is that older form — and
			 * it is correct THERE because v3 pairs it with a leading margin
			 * rather than a trailing one. The two halves have to match: see
			 * Spacing::resolve(), which flips the declarations.
			 */
			if ( $legacy ) {
				return ' > :not([hidden]) ~ :not([hidden])';
			}

			return ' > :not(:last-child)';
		}
		if ( $base === 'divide' || str_starts_with( $base, 'divide-' ) ) {
			return ' > :not(:last-child)';
		}

		return '';
	}

	/**
	 * @param array<string, string> $declarations
	 */
	private static function declaration_block( array $declarations ): string {
		$out = '';
		foreach ( $declarations as $property => $value ) {
			$out .= $property . ':' . $value . ';';
		}

		return $out;
	}

	/**
	 * Emit in cascade order: unconditional rules first, then breakpoints from
	 * narrow to wide, and within a group the least-qualified variant first, so
	 * `md:p-8` still beats `p-6` the way Tailwind intends.
	 *
	 * @param array<int, array<string, mixed>> $rules
	 */
	private function emit( array $rules ): string {
		usort(
			$rules,
			static function ( array $a, array $b ): int {
				/*
				 * Two utilities writing the same property must land in the
				 * order the reference build emits them, or the one the markup
				 * happens to list last wins instead. `index` — markup order —
				 * used to be the tie-break, and with 243 of 13,700+ utilities
				 * ranked, almost everything tied: on three of seven designs an
				 * element carrying both `text-brand-green` and
				 * `text-muted-foreground` came out green where the design is
				 * muted, because we emitted the two in the opposite order. Ten
				 * nodes, and no geometry check can see a wrong colour.
				 *
				 * So: the hand-read table first, then the family, then a
				 * natural comparison of the base. `index` stays last only to
				 * keep the sort total.
				 */
				return $a['weight'] <=> $b['weight']
					?: $a['depth'] <=> $b['depth']
					?: self::rank( (string) $a['base'] ) <=> self::rank( (string) $b['base'] )
					?: self::family_rank( (string) $a['base'] ) <=> self::family_rank( (string) $b['base'] )
					?: self::natural_compare( (string) $a['base'], (string) $b['base'] )
					?: $a['index'] <=> $b['index'];
			}
		);

		$out     = '';
		$open    = array();
		foreach ( $rules as $rule ) {
			$at = is_array( $rule['at_rules'] ) ? $rule['at_rules'] : array();
			if ( $at !== $open ) {
				$out  .= str_repeat( "}\n", count( $open ) );
				$open  = $at;
				foreach ( $open as $at_rule ) {
					$out .= $at_rule . " {\n";
				}
			}
			$out .= $rule['selector'] . ' { ' . $rule['body'] . " }\n";
		}

		return $out . str_repeat( "}\n", count( $open ) );
	}

	private function emit_keyframes( string $scope ): string {
		$frames = $this->theme->keyframes();
		foreach ( $this->modules as $module ) {
			foreach ( $module->keyframes() as $name => $body ) {
				if ( ! isset( $frames[ $name ] ) ) {
					$frames[ $name ] = $body;
				}
			}
		}

		if ( $frames === array() ) {
			return '';
		}

		$out = "\n";
		foreach ( $frames as $name => $body ) {
			$body = trim( $body );
			if ( $body === '' ) {
				continue;
			}
			$out .= str_starts_with( $body, '@keyframes' ) ? $body . "\n" : '@keyframes ' . $name . " {\n" . $body . "\n}\n";
		}

		return $out;
	}

	/**
	 * Pull class tokens out of both the HTML attribute and the block-comment
	 * `className`, since a Gutenberg document carries each utility twice.
	 *
	 * @return array<int, string>
	 */
	public static function extract_classes( string $markup ): array {
		preg_match_all( '/class(?:Name)?="([^"]*)"/', $markup, $quoted );
		preg_match_all( '/"className"\s*:\s*"([^"]*)"/', $markup, $json );
		/*
		 * The classes a state change adds, which are used by the page as surely
		 * as the ones it starts with: `data-dxai-on` / `data-dxai-off` hold
		 * the two arms of a class ternary, and `dxaiData` carries the same
		 * pair through the block round trip. Read nowhere before this, so a
		 * utility that appears only in an arm got no rule and the projection
		 * was pruned for being unselectable — which is how a sticky header kept
		 * `bg-transparent` and never went solid.
		 */
		preg_match_all( '/data-dxai-o(?:n|ff)="([^"]*)"/', $markup, $projected );
		preg_match_all( '/"dxai-o(?:n|ff)"\s*:\s*"([^"]*)"/', $markup, $projected_json );

		$tokens = array();
		foreach ( array_merge( $quoted[1] ?? array(), $json[1] ?? array(), $projected[1] ?? array(), $projected_json[1] ?? array() ) as $chunk ) {
			$chunk = self::decode_class_chunk( (string) $chunk );
			foreach ( preg_split( '/\s+/', $chunk ) ?: array() as $token ) {
				$token = trim( $token );
				if ( $token === '' || str_starts_with( $token, 'wp-block' ) || str_starts_with( $token, 'is-layout' ) || str_starts_with( $token, 'dxai-ui' ) || $token === 'alignfull' ) {
					continue;
				}
				$tokens[ $token ] = true;
			}
		}

		return array_keys( $tokens );
	}

	private static function decode_class_chunk( string $chunk ): string {
		$chunk = html_entity_decode( str_replace( '\\/', '/', $chunk ), ENT_QUOTES, 'UTF-8' );

		return (string) preg_replace_callback(
			'/\\\\u([0-9a-fA-F]{4})/',
			static function ( array $matches ): string {
				$code = hexdec( $matches[1] );
				if ( function_exists( 'mb_chr' ) ) {
					return mb_chr( $code, 'UTF-8' );
				}

				return html_entity_decode( '&#' . $code . ';', ENT_QUOTES, 'UTF-8' );
			},
			$chunk
		);
	}
}

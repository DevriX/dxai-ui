<?php
/**
 * The DevriX theme utility classes, and the plugin's own, for converted blocks.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Support\Upload_Paths;

/**
 * A block's own CSS written as utility classes where one matches.
 *
 * DevriX themes (american-restoration: assets/src/sass/base/_utilities.scss)
 * ship ~9,300 single-class utility rules — `p-4`, `d-flex`, `gap-2-5`,
 * `max-w-640px` — and the people who maintain these sites know them. A
 * converted block whose CSS is `display:flex;align-items:center;gap:10px;
 * font-size:15px` is written as `d-flex items-center gap-2-5 text-15`
 * instead of four declarations in a dxs- rule. The theme is not installed
 * where the plugin runs, so the plugin carries the rules itself
 * (data/dx-utilities.php, built by bin/build-utilities.cjs from the theme's
 * own compiled CSS) and writes, per page, only the rules of the classes that
 * page uses, in the theme's cascade order (css_for_classes()).
 *
 * Where the theme has no class for a value, the plugin adds its own, named
 * the theme's way (family()): `fw-900` next to the theme's `fw-700`,
 * `text-14-5` next to `text-14`, `max-w-1280px` next to `max-w-1200px`,
 * `h-58px`, `rounded-5px`, `lh-1-6`, `ls-0-02em`, `whitespace-nowrap`,
 * `cursor-pointer`, `list-style-none`, and for the design's colour tokens
 * `text-dxai-ink` (color:var(--dxai-ink)), `bg-dxai-neutral-100` and
 * `shadow-dxai-shadow-2`. Same contract as the theme's — one class, one
 * declaration, `!important` — and each value family sits in the cascade right
 * after the theme's own base rules of that family, before its responsive
 * variants, so `md-text-18` still overrides `text-14-5` from 768px up as it
 * overrides `text-14`. What neither covers stays in the block's dxs- rule.
 *
 * The rules are the theme's, with three changes, each explained below: the
 * class a selector is filed under is written `:where(.x)` and scoped, and
 * rem lengths are written as px.
 *
 * Why a utility renders exactly as the declaration it replaces. The
 * declaration used to be an inline style; Style_Hoister moved it into a
 * `dxs-` rule with inline-style precedence ((3,0,0), see Style_Rules::rule()).
 * The plugin writes each utility rule with the class it is filed under as
 * `:where(.x)`: its `!important` declarations at specificity (0,0,0)
 * (lead()). That is where the inline style sat in the cascade:
 *
 *  - Every normal declaration, the design's scoped rules included, loses to
 *    both: to the inline style / dxs rule by specificity, to the utility by
 *    importance. Same winner.
 *  - Every `!important` declaration of any specificity beats both — the
 *    inline style because important beats it, the utility by specificity.
 *    The design's own are scoped by Css_Scoper (every selector starts
 *    `.dxai-ui.dxai-ui--{id}`, so each is at least (0,2,0); checked
 *    2026-09-25 against Semper Dry's pattern-93057.css — 161 in 95 rules,
 *    the lowest (0,3,0) — and every design sheet on the development site:
 *    1,514 files, 113,868 declarations, none outside a scope); core's own
 *    beat both as well: the constrained layout's `margin-left/right:auto
 *    !important` (0,1,0) still centres a block pasted into an ordinary page,
 *    and a preset colour class (`.has-x-color`) still sets the colour. A
 *    rule written as the theme writes it, (0,1,0), tied with those and won
 *    by coming later — measured: a pasted `m-0` paragraph lost its centring.
 *    Only an important rule of specificity (0,0,0) ties, and the later one
 *    wins. A design `@layer` rule marked important wins too — layered
 *    importance beats unlayered — as it did over the inline style.
 *  - Between two utilities nothing changes: exactly one class is taken out
 *    of every selector, so every difference between two of the theme's
 *    selectors (`:where(.x):hover` over `:where(.y)`, a twin over a class)
 *    is kept, and at equal specificity the theme's order decides as it does
 *    in the theme.
 *  - The only places the two differ, each handled rather than assumed away:
 *     1. A design declaration of the same element is still the design's
 *        (see the class names, below).
 *     2. A CSS animation beats a normal declaration but not an important
 *        one. Properties a page's @keyframes animate are never matched for
 *        that page (animated_properties()); nor are transform, opacity,
 *        max-height and grid-template-rows anywhere (RUNTIME_PROPS).
 *     3. A style the page's JavaScript writes inline (Motion_Runtime, the
 *        slider) beats a normal declaration but not an important one. The
 *        runtime writes transform, grid-template-rows, max-height and
 *        opacity on panels (RUNTIME_PROPS), and display/position/z-index/
 *        left/top/width/min-width only on Radix lists, dialogs, overlays
 *        and the method-rail bar — blocks Style_Hoister does not match at
 *        all (Style_Hoister::guarded()).
 *     4. Lengths: the theme writes rem, the design's inline CSS px. Rules
 *        are written with rem as px at 16px (px_lengths()) — what the
 *        theme's rem means under its own root size — so a host theme that
 *        sets `html{font-size:62.5%}` cannot shrink a header or footer the
 *        plugin prints on its pages. Media queries keep rem, which is always
 *        the browser's default size.
 *     5. Editing: the block's own CSS (the editor's Design CSS field) must
 *        keep the last word over its utility classes, as the inline style
 *        it replaces did over a class. The editor canvas therefore gets the
 *        rules in their editor form (css_for_classes( …, true )): normal
 *        declarations at (2,1,0), above every design rule (no design sheet
 *        on the site has more than one id in a selector) and below the dxs-
 *        rules (3,0,0), so an edit shows at once; and when a block's CSS and
 *        one of its utilities set the same property, Style_Rules restates
 *        that declaration as important on the page (Style_Rules::overrides()).
 *        A preset font size picked in the sidebar (core's important
 *        `.has-x-font-size`) wins over a utility, as over the inline style;
 *        spacing set in the sidebar (which core writes as an inline style)
 *        loses to a utility for the same property, as it does under the
 *        DevriX theme itself.
 *  - The class name itself must mean nothing else where the rule reaches:
 *     - on the page: a design that carries its own `p-4` (Tailwind) or state
 *       class `hidden` would otherwise pick up the theme's rule. match()
 *       never picks a name in its `avoid` list (the design's names,
 *       Style_Hoister::design_names()), and Style_Rules writes no utility
 *       rule for a name the page's design defines or uses;
 *     - around it: every rule is scoped with `:where(…)` (SCOPE, no added
 *       specificity) to a design scope element (`.dxai-ui` — a converted
 *       page's, a pattern's scope group, the footer's frame, a Widgets-screen
 *       area), to a block Style_Rules marks when it renders one outside any
 *       scope (MARK) and to the block editor. A theme's own markup — its
 *       Bootstrap `mb-5` (3rem, where the DevriX one is 1.25rem), core's
 *       `sticky` on a sticky post in the blog home — is never inside one, so
 *       a page that prints the footer's rules is not restyled by them.
 *
 * Matching is by value, exactly: declarations are reduced to physical
 * longhands (a shorthand only where its expansion is exact — margin, padding,
 * inset, gap, border-radius, overflow, border-width/-style/-color, and flex
 * and flex-flow when utilities replace them whole), rem to px at 16px, zeros
 * to `0`, and compared as text. A shorthand that cannot be expanded exactly
 * (background, border, font, a `var()` inside margin) is kept whole and
 * blocks every longhand it could set — unless one of the plugin's classes
 * declares exactly that shorthand and nothing else in the list reaches its
 * longhands (whole_class()); so does any `!important` declaration. No two
 * chosen classes set the same longhand, because between two utilities the
 * rule order wins, not the order of the classes.
 */
final class Utility_Classes {

	/** The generated catalogue, relative to the plugin root. */
	private const DATA_FILE = 'data/dx-utilities.php';

	/**
	 * The plugin's own classes (family()) — part of version(), so a change to
	 * them invalidates every cached stylesheet built with the old ones.
	 */
	private const LAYER_VERSION = 'l4';

	/**
	 * The class Style_Rules adds, on the front end, to a block that carries
	 * utility classes and renders outside any design scope — a block copied
	 * into an ordinary page, a footer widget a classic theme prints in its own
	 * sidebar — so the scoped rules reach it and nothing of the theme's.
	 */
	public const MARK = 'dxai-utilities';

	/**
	 * Where a rule applies (see the class comment): a design scope and what
	 * is inside it, a marked block, the block editor's content.
	 */
	private const SCOPE = ':where(.dxai-ui,.dxai-ui *,.dxai-utilities,.editor-styles-wrapper *)';

	/**
	 * While css_for_classes() writes rules for a part (a block whose wrapper is not the element the design styled —
	 * core/image's figure around the `img`): [ 'wrap' => the marker class on the wrapper, 'inner' => the selector
	 * of the element inside it ]. A rule then applies to that element when the wrapper carries the marker and the class.
	 *
	 * @var array{wrap:string, inner:string}|null
	 */
	private static ?array $part = null;

	/**
	 * The editor form's lift: (2,0,0), matched by the class alone (no element
	 * carries the id). Combined with the class itself, (2,1,0).
	 */
	private const LIFT = ':is(#dxai-h#dxai-h,.%s)';

	/**
	 * Longhands never moved to a utility, on any block: the runtime writes
	 * them inline on elements it animates or opens (Motion_Runtime reveal
	 * masks and applyPanel, assets/js/slider-view.js), and an important
	 * utility would pin the value the script changes.
	 */
	private const RUNTIME_PROPS = array( 'transform', 'translate', 'rotate', 'scale', 'grid-template-rows', 'max-height', 'opacity' );

	/** The four physical sides, in shorthand order. */
	private const SIDES = array( 'top', 'right', 'bottom', 'left' );

	/** The corners border-radius sets, in shorthand order. */
	private const CORNERS = array( 'border-top-left-radius', 'border-top-right-radius', 'border-bottom-right-radius', 'border-bottom-left-radius' );

	/** CSS-wide keywords: a shorthand set to one of these sets every longhand to it. */
	private const WIDE = array( 'inherit', 'initial', 'unset', 'revert', 'revert-layer' );

	/**
	 * The plugin's value families: name prefix => property, and the theme
	 * classes whose last base rule each family's rules follow in the cascade
	 * (null: after all of the theme's rules). Suffixes are the value with `.`
	 * written `-` (`text-14-5` is 14.5px), px families end in `px` as the
	 * theme's `max-w-640px` does.
	 */
	private const SIZE_FAMILIES = array(
		'max-w' => array( 'max-width', '/^max-w-\d+px$/' ),
		'min-h' => array( 'min-height', '/^min-h-/' ),
		'min-w' => array( 'min-width', null ),
		'w'     => array( 'width', '/^w-\d+px$/' ),
		'h'     => array( 'height', '/^h-\d+px$/' ),
	);

	/** Keyword classes: class => [ property, value ]. */
	private const KEYWORDS = array(
		'whitespace-normal'       => array( 'white-space', 'normal' ),
		'whitespace-nowrap'       => array( 'white-space', 'nowrap' ),
		'whitespace-pre'          => array( 'white-space', 'pre' ),
		'whitespace-pre-line'     => array( 'white-space', 'pre-line' ),
		'whitespace-pre-wrap'     => array( 'white-space', 'pre-wrap' ),
		'whitespace-break-spaces' => array( 'white-space', 'break-spaces' ),
		'list-style-none'         => array( 'list-style', 'none' ),
		'cursor-auto'             => array( 'cursor', 'auto' ),
		'cursor-default'          => array( 'cursor', 'default' ),
		'cursor-pointer'          => array( 'cursor', 'pointer' ),
		'cursor-text'             => array( 'cursor', 'text' ),
		'cursor-move'             => array( 'cursor', 'move' ),
		'cursor-grab'             => array( 'cursor', 'grab' ),
		'cursor-help'             => array( 'cursor', 'help' ),
		'cursor-wait'             => array( 'cursor', 'wait' ),
		'cursor-not-allowed'      => array( 'cursor', 'not-allowed' ),
		'italic'                  => array( 'font-style', 'italic' ),
		'not-italic'              => array( 'font-style', 'normal' ),
		'object-fit-cover'        => array( 'object-fit', 'cover' ),
		'object-fit-contain'      => array( 'object-fit', 'contain' ),
		'object-fit-fill'         => array( 'object-fit', 'fill' ),
		'object-fit-none'         => array( 'object-fit', 'none' ),
		'object-fit-scale-down'   => array( 'object-fit', 'scale-down' ),
		'text-overflow-ellipsis'  => array( 'text-overflow', 'ellipsis' ),
		'text-overflow-clip'      => array( 'text-overflow', 'clip' ),
		'pointer-events-none'     => array( 'pointer-events', 'none' ),
		'pointer-events-auto'     => array( 'pointer-events', 'auto' ),
		'rounded-circle'          => array( 'border-radius', '50%' ),
	);

	/**
	 * Declarations the plugin's classes replace whole — shorthands they
	 * declare exactly — rather than longhand by longhand (see match()).
	 */
	private const WHOLE_PROPS = array( 'white-space', 'list-style', 'background' );

	/** The families' order at one cascade position (see family()). */
	private const FAMILY_RANK = array( 'fw', 'text', 'lh', 'ls', 'rounded', 'max-w', 'min-h', 'min-w', 'w', 'h', 'kw', 'text-dxai', 'bg-dxai', 'shadow-dxai' );

	/** @var array<string, mixed>|null */
	private static ?array $data = null;

	/** @var array{list: array<int, array{class:string, pairs:array<string, string>}>, by_pair: array<string, array<int, int>>}|null */
	private static ?array $candidates = null;

	/** @var array<string, bool>|null Matchable theme classes, as a set. */
	private static ?array $matchable = null;

	/** @var array<string, array<string, mixed>|false> family() results. */
	private static array $families = array();

	/**
	 * The catalogue (see bin/build-utilities.cjs for its shape).
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		if ( self::$data === null ) {
			$file       = dirname( __DIR__, 2 ) . '/' . self::DATA_FILE;
			$loaded     = is_readable( $file ) ? include $file : array();
			self::$data = is_array( $loaded ) ? $loaded : array();
		}

		return self::$data;
	}

	/**
	 * The catalogue's version and the plugin layer's: changes whenever the
	 * rules do. Part of every cache key — so the catalogue's is read from the
	 * head of the data file, where the generator writes it first, rather than
	 * by loading the 0.5 MB catalogue on every page view just to learn that
	 * the cached rules still stand.
	 */
	public static function version(): string {
		static $version = null;
		if ( $version !== null ) {
			return $version;
		}
		if ( self::$data !== null ) {
			$version = (string) ( self::$data['version'] ?? '' ) . '-' . self::LAYER_VERSION;

			return $version;
		}
		$file    = dirname( __DIR__, 2 ) . '/' . self::DATA_FILE;
		$head    = is_readable( $file ) ? (string) file_get_contents( $file, false, null, 0, 2048 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own data file.
		$version = ( preg_match( "/'version'=>'([0-9a-f]+)'/", $head, $m ) === 1 ? $m[1] : (string) ( self::data()['version'] ?? '' ) ) . '-' . self::LAYER_VERSION;

		return $version;
	}

	/** Whether $class is a theme utility class or one of the plugin's. */
	public static function is_utility( string $class ): bool {
		return isset( self::data()['classes'][ $class ] ) || self::family( $class ) !== null;
	}

	/**
	 * Whether match() can place $class: a theme class it may choose, or one
	 * of the plugin's. A name that is not — a responsive `md-p-4`, a hover
	 * class — is only ever there because a person typed it or the design
	 * used it.
	 */
	public static function placeable( string $class ): bool {
		if ( self::$matchable === null ) {
			self::$matchable = array_fill_keys( array_map( 'strval', (array) ( self::data()['matchable'] ?? array() ) ), true );
		}

		return isset( self::$matchable[ $class ] ) || self::family( $class ) !== null;
	}

	/**
	 * The utility classes that can stand in for a declaration list, and what
	 * is left of it.
	 *
	 * Options:
	 *  - avoid:       class names never to choose (the page's design names).
	 *  - avoid_props: longhands never to move (what the page's @keyframes animate).
	 *  - root_px:     the root font size rem is read at (16).
	 *
	 * The remainder is the list with every covered longhand taken out and the
	 * rest left as written: untouched declarations keep their exact text and
	 * order, a shorthand with some sides covered is written as its other
	 * sides' longhands in its place. When nothing matches, the remainder is
	 * the input, byte for byte, so its dxs- hash does not move.
	 *
	 * @param array<string, mixed> $options
	 * @return array{classes: array<int, string>, remainder: string}
	 */
	public static function match( string $css, array $options = array() ): array {
		$none  = array(
			'classes'   => array(),
			'remainder' => $css,
		);
		$decls = self::declarations( $css );
		if ( $decls === array() ) {
			return $none;
		}
		$root  = isset( $options['root_px'] ) && is_numeric( $options['root_px'] ) && (float) $options['root_px'] > 0 ? (float) $options['root_px'] : 16.0;
		$avoid = array_fill_keys( array_map( 'strval', (array) ( $options['avoid'] ?? array() ) ), true );
		$skip  = array_fill_keys( array_merge( self::RUNTIME_PROPS, array_map( 'strval', (array) ( $options['avoid_props'] ?? array() ) ) ), true );

		// The value each longhand ends up with, and the longhands something
		// this code cannot reproduce with a class also sets.
		$effective = array();
		$source    = array();
		$blocked   = array();
		foreach ( $decls as $at => $decl ) {
			$prop = $decl['prop'];
			if ( $prop === '' || str_starts_with( $prop, '--' ) ) {
				continue;
			}
			$touched = self::touched( $prop );
			if ( $touched === array( '*' ) ) {
				// `all:` resets everything; leave such a list alone.
				return $none;
			}
			$sides = $decl['important'] ? null : self::expand( $prop, $decl['value'] );
			if ( $sides === null ) {
				foreach ( $touched as $longhand ) {
					$blocked[ $longhand ] = true;
				}
				continue;
			}
			foreach ( $sides as $longhand => $token ) {
				$effective[ $longhand ] = self::canonical_for( $longhand, $token, $root );
				$source[ $longhand ]    = $at;
			}
		}
		foreach ( array_keys( $effective ) as $longhand ) {
			if ( isset( $blocked[ $longhand ] ) || isset( $skip[ $longhand ] ) ) {
				unset( $effective[ $longhand ] );
			}
		}

		/*
		 * Whole declarations one of the plugin's classes declares exactly —
		 * `white-space:nowrap`, `list-style:none`, `background:var(--dxai-x)`.
		 * Each is a shorthand (white-space is white-space-collapse plus
		 * text-wrap-mode now), so its class is exact only while nothing else
		 * in the list reaches the same longhands: then no declaration order
		 * is lost by lifting it out.
		 */
		$whole = array();
		foreach ( $decls as $at => $decl ) {
			if ( $decl['important'] || ! in_array( $decl['prop'], self::WHOLE_PROPS, true ) ) {
				continue;
			}
			$class = self::whole_class( $decl['prop'], self::canonical( $decl['value'], $root ) );
			if ( $class === null || isset( $avoid[ $class ] ) ) {
				continue;
			}
			$reach = self::touched( $decl['prop'] );
			foreach ( $reach as $longhand ) {
				if ( isset( $skip[ $longhand ] ) ) {
					continue 2;
				}
			}
			foreach ( $decls as $other_at => $other ) {
				if ( $other_at !== $at && $other['prop'] !== '' && ! str_starts_with( $other['prop'], '--' ) && array_intersect( self::touched( $other['prop'] ), $reach ) !== array() ) {
					continue 2;
				}
			}
			$whole[ $at ] = $class;
		}
		if ( $effective === array() && $whole === array() ) {
			return $none;
		}

		// Every theme utility whose whole declaration set is in the effective map.
		$index = self::candidates();
		$base  = count( $index['list'] );
		$fit   = array();
		foreach ( $effective as $longhand => $value ) {
			foreach ( $index['by_pair'][ $longhand . ':' . $value ] ?? array() as $i ) {
				if ( isset( $fit[ $i ] ) ) {
					continue;
				}
				$cand = $index['list'][ $i ];
				if ( isset( $avoid[ $cand['class'] ] ) ) {
					continue;
				}
				$ok = true;
				foreach ( $cand['pairs'] as $l => $v ) {
					if ( ( $effective[ $l ] ?? null ) !== $v ) {
						$ok = false;
						break;
					}
				}
				if ( $ok ) {
					$fit[ $i ] = count( $cand['pairs'] );
				}
			}
		}
		// Then the plugin's own, for what no theme class covers: they sort
		// after every theme candidate of the same width.
		$extra = array();
		foreach ( self::family_candidates( $effective ) as $cand ) {
			if ( ! isset( $avoid[ $cand['class'] ] ) ) {
				$i           = $base + count( $extra );
				$extra[ $i ] = $cand;
				$fit[ $i ]   = count( $cand['pairs'] );
			}
		}
		$pairs_of = static fn( int $i ): array => $i < $base ? $index['list'][ $i ]['pairs'] : $extra[ $i ]['pairs'];
		// Widest first (p-4 before pt-4), then the catalogue's preference order.
		$order = static function ( array $fit ): array {
			uksort(
				$fit,
				static fn( $a, $b ) => ( $fit[ $b ] <=> $fit[ $a ] ) ?: ( $a <=> $b )
			);

			return array_keys( $fit );
		};
		$cover = static function ( array $ids ) use ( $pairs_of ): array {
			$covered = array();
			$chosen  = array();
			foreach ( $ids as $i ) {
				$pairs = $pairs_of( $i );
				if ( array_intersect_key( $pairs, $covered ) === array() ) {
					$covered += $pairs;
					$chosen[] = $i;
				}
			}

			return array( $chosen, $covered );
		};

		/*
		 * `flex` and `flex-flow` are replaced whole or not at all. Cutting
		 * `flex:1 1 420px` into `grow` plus `flex-shrink:1;flex-basis:420px`
		 * is exact but reads worse than what it replaces; `flex:none` as
		 * `flex-none`, `flex-flow:row wrap` as `flex-row flex-wrap` are the
		 * point. So their longhands are matched only when utilities that sit
		 * wholly inside the declaration cover all of it, and the declaration
		 * is still what sets each of them.
		 */
		foreach ( $decls as $at => $decl ) {
			if ( $decl['important'] || ( $decl['prop'] !== 'flex' && $decl['prop'] !== 'flex-flow' ) ) {
				continue;
			}
			$sides = self::expand( $decl['prop'], $decl['value'] );
			if ( $sides === null ) {
				continue;
			}
			$own    = true;
			$inside = array();
			foreach ( array_keys( $sides ) as $longhand ) {
				$own = $own && isset( $effective[ $longhand ] ) && ( $source[ $longhand ] ?? -1 ) === $at;
			}
			foreach ( $fit as $i => $size ) {
				if ( array_diff_key( $pairs_of( $i ), $sides ) === array() ) {
					$inside[ $i ] = $size;
				}
			}
			$whole_flex = $own && count( $cover( $order( $inside ) )[1] ) === count( $sides );
			if ( ! $whole_flex ) {
				foreach ( array_keys( $fit ) as $i ) {
					if ( array_intersect_key( $pairs_of( $i ), $sides ) !== array() ) {
						unset( $fit[ $i ] );
					}
				}
			}
		}
		if ( $fit === array() && $whole === array() ) {
			return $none;
		}
		list( $chosen, $covered ) = $fit === array() ? array( array(), array() ) : $cover( $order( $fit ) );
		$classes                  = array();
		foreach ( $chosen as $i ) {
			$classes[] = $i < $base ? $index['list'][ $i ]['class'] : $extra[ $i ]['class'];
		}
		foreach ( $whole as $class ) {
			$classes[] = $class;
		}

		// What is left, in the list's own order and spelling.
		$parts = array();
		foreach ( $decls as $at => $decl ) {
			if ( isset( $whole[ $at ] ) ) {
				continue;
			}
			$sides = ( $decl['prop'] === '' || str_starts_with( $decl['prop'], '--' ) || $decl['important'] ) ? null : self::expand( $decl['prop'], $decl['value'] );
			if ( $sides === null ) {
				$parts[] = $decl['raw'];
				continue;
			}
			$keep = array_diff_key( $sides, $covered );
			if ( count( $keep ) === count( $sides ) ) {
				$parts[] = $decl['raw'];
				continue;
			}
			foreach ( $keep as $longhand => $token ) {
				$parts[] = $longhand . ':' . $token;
			}
		}

		return array(
			'classes'   => $classes,
			'remainder' => implode( ';', $parts ),
		);
	}

	/**
	 * The longhands the rules filed under $classes set on the element that
	 * carries them, at any width and in any state (a block already carrying
	 * one of these must not get a second utility for the same longhand — see
	 * Style_Hoister::block_options()). A rule that reaches only the link
	 * inside a core/button wrapper (`.wp-block-button.d-flex>a{align-items:
	 * center}`, filed under d-flex) is not the element's.
	 *
	 * @param array<int, string> $classes
	 * @return array<int, string>
	 */
	public static function longhands( array $classes ): array {
		$data = self::data();
		$out  = array();
		foreach ( $classes as $class ) {
			$class  = (string) $class;
			$family = isset( $data['classes'][ $class ] ) ? null : self::family( $class );
			if ( $family !== null ) {
				$sides = self::expand( $family['prop'], $family['value'] );
				foreach ( $sides !== null ? array_keys( $sides ) : self::touched( $family['prop'] ) as $longhand ) {
					$out[ $longhand ] = true;
				}
				continue;
			}
			foreach ( (array) ( $data['classes'][ $class ] ?? array() ) as $idx ) {
				$rule = $data['rules'][ $idx ];
				if ( (int) $rule[3] === 2 && ! self::reaches_element( (string) $rule[1], $class ) ) {
					continue;
				}
				foreach ( self::declarations( (string) $data['decls'][ (int) $rule[2] ] ) as $decl ) {
					if ( $decl['prop'] === '' ) {
						continue;
					}
					$sides = self::expand( $decl['prop'], $decl['value'] );
					foreach ( $sides !== null ? array_keys( $sides ) : self::touched( $decl['prop'] ) as $longhand ) {
						$out[ $longhand ] = true;
					}
				}
			}
		}

		return array_map( 'strval', array_keys( $out ) );
	}

	/**
	 * The declarations a class sets on its own element outside any media
	 * query, as one declaration list: the theme's base rules that name the
	 * class itself (not a hover, a descendant or only a core/button link), in
	 * cascade order, or the plugin's own declaration — for code that needs a
	 * class's values rather than its rules (the Widgets screen paints a
	 * footer area from its frame's classes). Lengths as px, `!important` kept.
	 * '' for a name that is not a utility.
	 */
	public static function base_declarations( string $class ): string {
		$data = self::data();
		if ( ! isset( $data['classes'][ $class ] ) ) {
			$family = self::family( $class );

			return $family === null ? '' : $family['prop'] . ':' . $family['value'] . ' !important';
		}
		$out = array();
		foreach ( (array) $data['classes'][ $class ] as $idx ) {
			$rule = $data['rules'][ (int) $idx ];
			if ( (int) $rule[0] !== 0 || ! self::names_self( (string) $rule[1], (int) $rule[3], $class ) ) {
				continue;
			}
			$out[] = self::px_lengths( (string) $data['decls'][ (int) $rule[2] ] );
		}

		return implode( ';', $out );
	}

	/**
	 * The declarations of $css that set a longhand one of $classes also sets,
	 * as written — what Style_Rules restates as important so a block's own
	 * CSS keeps the last word over a utility class a person added to it (the
	 * converter never gives a block both, see Style_Hoister::block_options()).
	 *
	 * @param array<int, string> $classes
	 * @return array<int, string>
	 */
	public static function overlapping( string $css, array $classes ): array {
		if ( $classes === array() || trim( $css ) === '' ) {
			return array();
		}
		$taken = array_fill_keys( self::longhands( $classes ), true );
		if ( $taken === array() ) {
			return array();
		}
		$out = array();
		foreach ( self::declarations( $css ) as $decl ) {
			if ( $decl['prop'] === '' || str_starts_with( $decl['prop'], '--' ) ) {
				continue;
			}
			$sides = self::expand( $decl['prop'], $decl['value'] );
			foreach ( $sides !== null ? array_keys( $sides ) : self::touched( $decl['prop'] ) as $longhand ) {
				if ( isset( $taken[ $longhand ] ) || $longhand === '*' ) {
					$out[] = $decl['raw'];
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * The rules for $classes — every rule the theme writes for each of them,
	 * at every width it defines, and the plugin's rule for each of its own —
	 * in cascade order, consecutive rules under one media query grouped as the
	 * theme groups them.
	 *
	 * A rule's selector list is narrowed to the selectors whose utility
	 * classes are all in $classes: the theme pairs `.items-center` with
	 * `.wp-block-button.d-flex>a` in one rule, and writing that rule for a
	 * page that uses `d-flex` must not also style every `.items-center` —
	 * possibly the design's own Tailwind class. The class each selector is
	 * filed under is written by lead() — `:where(.x)` and the scope — and
	 * lengths are px (px_lengths()). $editor writes the editor form: the
	 * class with the lift instead of `!important` (see the class comment, 5.).
	 *
	 * @param array<int, string> $classes
	 */
	public static function css_for_classes( array $classes, bool $editor = false, ?array $part = null ): string {
		self::$part = $part;
		try {
			return self::build_css( $classes, $editor );
		} finally {
			self::$part = null;
		}
	}

	/**
	 * css_for_classes() body.
	 *
	 * @param array<int, string> $classes
	 */
	private static function build_css( array $classes, bool $editor ): string {
		$data   = self::data();
		$picked = array();
		$own    = array();
		foreach ( $classes as $class ) {
			$class = (string) $class;
			if ( isset( $data['classes'][ $class ] ) ) {
				foreach ( $data['classes'][ $class ] as $idx ) {
					$picked[ (int) $idx ] = true;
				}
			} else {
				$family = self::family( $class );
				if ( $family !== null ) {
					$own[ $class ] = $family;
				}
			}
		}
		if ( $picked === array() && $own === array() ) {
			return '';
		}
		$want = array();
		foreach ( $classes as $class ) {
			$want[ (string) $class ] = true;
		}

		// One sequence: the theme's rules by index, the plugin's at their
		// family's position (just after a theme rule index), ranked there.
		$seq = array();
		foreach ( array_keys( $picked ) as $idx ) {
			$seq[] = array( (float) $idx, 0, 0.0, '', $idx );
		}
		foreach ( $own as $class => $family ) {
			$seq[] = array( self::family_position( $family['group'] ) + 0.5, 1 + (int) array_search( $family['group'], self::FAMILY_RANK, true ), (float) $family['sort'], $class, -1 );
		}
		usort(
			$seq,
			static fn( $a, $b ) => ( $a[0] <=> $b[0] ) ?: ( $a[1] <=> $b[1] ) ?: ( $a[2] <=> $b[2] ) ?: strcmp( $a[3], $b[3] )
		);

		$out  = '';
		$open = null;
		foreach ( $seq as $item ) {
			if ( $item[4] >= 0 ) {
				$rule     = $data['rules'][ $item[4] ];
				$selector = self::selector_for( (string) $rule[1], (int) $rule[3], $want, $editor );
				$media    = (string) ( $data['media'][ (int) $rule[0] ] ?? '' );
				$decls    = self::px_lengths( (string) $data['decls'][ (int) $rule[2] ] );
			} else {
				$family   = $own[ $item[3] ];
				$selector = self::scoped( '.' . $item[3], $item[3], $editor );
				$media    = '';
				// A text colour on its own channel (Token_Styles::fg_channel()).
				$decls    = Token_Styles::fg_channel( $family['prop'] . ':' . $family['value'] ) . ' !important';
			}
			if ( $selector === '' ) {
				continue;
			}
			// The editor form leaves the cascade to specificity — except for a part, whose image the block editor
			// writes inline styles on (core/image sets `height:auto`), which only !important outranks.
			if ( $editor && self::$part === null ) {
				$decls = (string) preg_replace( '/\s*!\s*important/i', '', $decls );
			}
			if ( $media !== $open ) {
				if ( $open !== null && $open !== '' ) {
					$out .= '}';
				}
				if ( $media !== '' ) {
					$out .= '@media' . $media . '{';
				}
				$open = $media;
			}
			$out .= $selector . '{' . $decls . '}';
		}
		if ( $open !== null && $open !== '' ) {
			$out .= '}';
		}

		return $out;
	}

	/**
	 * The utility classes a piece of stored markup carries: in class
	 * attributes (the design's elements, rich-text runs, raw HTML) and in
	 * blocks' className attributes — so a class a person types into
	 * "Additional CSS class(es)" gets its rule as well. First-seen order.
	 *
	 * These are names only: a name the page's design also defines or uses is
	 * the design's (Style_Rules drops those before writing rules).
	 *
	 * @return array<int, string>
	 */
	public static function classes_in_markup( string $markup ): array {
		$out = array();
		foreach ( self::class_tokens( $markup ) as $token ) {
			if ( self::is_utility( $token ) ) {
				$out[ $token ] = true;
			}
		}

		return array_map( 'strval', array_keys( $out ) );
	}

	/**
	 * Whether any block in the markup names a utility class in its className
	 * — what Style_Rules looks for on an ordinary page, where a block whose
	 * CSS became classes entirely carries nothing else of this plugin's.
	 */
	public static function in_class_names( string $markup ): bool {
		if ( ! str_contains( $markup, '"className"' ) ) {
			return false;
		}
		if ( preg_match_all( '/"className"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $markup, $m ) ) {
			foreach ( $m[1] as $json ) {
				$value = json_decode( '"' . $json . '"' );
				foreach ( preg_split( '/\s+/', is_string( $value ) ? $value : '', -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $token ) {
					if ( self::is_utility( $token ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Every class name in stored markup, utility or not: class attributes and
	 * className block attributes.
	 *
	 * @return array<int, string>
	 */
	public static function class_tokens( string $markup ): array {
		$out = array();
		if ( ! str_contains( $markup, 'class' ) ) {
			return array();
		}
		if ( preg_match_all( '/\sclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $markup, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) {
				$value = html_entity_decode( ( $hit[1] ?? '' ) !== '' ? $hit[1] : ( $hit[2] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				foreach ( preg_split( '/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $token ) {
					$out[ $token ] = true;
				}
			}
		}
		if ( preg_match_all( '/"className"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $markup, $m ) ) {
			foreach ( $m[1] as $json ) {
				$value = json_decode( '"' . $json . '"' );
				foreach ( preg_split( '/\s+/', is_string( $value ) ? $value : '', -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $token ) {
					$out[ $token ] = true;
				}
			}
		}

		return array_map( 'strval', array_keys( $out ) );
	}

	/**
	 * The class names a design's runtime switches on and off — the lists in
	 * `data-dxai-on…` / `data-dxai-off…` attributes Motion_Runtime toggles
	 * (as HTML attributes or inside a block's dxaiData). They are the
	 * design's state, never a utility's, whatever they are called.
	 *
	 * @return array<int, string>
	 */
	public static function toggle_names( string $markup ): array {
		if ( ! str_contains( $markup, 'data-dxai-o' ) ) {
			return array();
		}
		$out = array();
		if ( preg_match_all( '/data-dxai-o(?:n|ff)[A-Za-z0-9_-]*(?:\\\\u0022|\\\\"|")?\s*[:=]\s*(?:\\\\u0022|\\\\"|")((?:(?!\\\\u0022|\\\\")[^"])*)/', $markup, $m ) ) {
			foreach ( $m[1] as $list ) {
				foreach ( preg_split( '/\s+/', html_entity_decode( (string) $list, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $token ) {
					$out[ $token ] = true;
				}
			}
		}

		return array_map( 'strval', array_keys( $out ) );
	}

	/**
	 * The class names a stylesheet's selectors use (the design's own
	 * vocabulary). Declaration blocks are skipped, so `0.5rem` or `a.png`
	 * inside a value is not read as a class; an escaped name (`md\:p-4`) is
	 * not a theme utility name and is left out.
	 *
	 * @return array<int, string>
	 */
	public static function design_classes( string $css ): array {
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$out = array();
		// Text before each `{` is a selector list or an at-rule prelude.
		$segments = preg_split( '/([{}])/', $css, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: array();
		$count    = count( $segments );
		for ( $i = 0; $i + 1 < $count; $i += 2 ) {
			if ( $segments[ $i + 1 ] !== '{' ) {
				continue;
			}
			if ( preg_match_all( '/\.(-?[A-Za-z_][A-Za-z0-9_-]*)(?![\\\\A-Za-z0-9_-])/', $segments[ $i ], $m ) ) {
				foreach ( $m[1] as $name ) {
					$out[ $name ] = true;
				}
			}
		}

		return array_map( 'strval', array_keys( $out ) );
	}

	/**
	 * The longhands a stylesheet's @keyframes animate.
	 *
	 * @return array<int, string>
	 */
	public static function animated_properties( string $css ): array {
		$out = array();
		if ( ! preg_match_all( '/@(?:-webkit-)?keyframes\b[^{]*\{/i', $css, $m, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		foreach ( $m[0] as $hit ) {
			$start = (int) $hit[1] + strlen( $hit[0] );
			$depth = 1;
			$len   = strlen( $css );
			$i     = $start;
			for ( ; $i < $len && $depth > 0; $i++ ) {
				if ( $css[ $i ] === '{' ) {
					++$depth;
				} elseif ( $css[ $i ] === '}' ) {
					--$depth;
				}
			}
			if ( preg_match_all( '/\{([^{}]*)\}/', substr( $css, $start, $i - $start ), $frames ) ) {
				foreach ( $frames[1] as $body ) {
					foreach ( self::declarations( $body ) as $decl ) {
						if ( $decl['prop'] !== '' && ! str_starts_with( $decl['prop'], 'animation' ) ) {
							foreach ( self::touched( $decl['prop'] ) as $longhand ) {
								$out[ $longhand ] = true;
							}
						}
					}
				}
			}
		}

		return array_map( 'strval', array_keys( $out ) );
	}

	/**
	 * The design stylesheets that apply to a post: its own and its design
	 * scope's (a crawled page renders under the home page's scope).
	 *
	 * @return array<int, string> Readable file paths.
	 */
	public static function design_sheets( int $post_id ): array {
		$out = array();
		if ( $post_id < 1 ) {
			return $out;
		}
		$scope = (int) get_post_meta( $post_id, Page_Scope::META, true );
		foreach ( array_unique( array_filter( array( $post_id, $scope ) ) ) as $id ) {
			$path = Upload_Paths::for_meta( (int) $id, '_dxai_ui_css_url' )['path'];
			if ( $path !== '' && is_readable( $path ) ) {
				$out[] = $path;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * What the page's design sheets say about matching: the design's own
	 * class names and the properties its @keyframes animate.
	 *
	 * @param array<int, string> $paths
	 * @return array{classes: array<int, string>, props: array<int, string>}
	 */
	public static function sheet_context( array $paths ): array {
		static $cache = array();
		$classes = array();
		$props   = array();
		foreach ( $paths as $path ) {
			$key = $path . '|' . (string) @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file is an empty sheet.
			if ( ! isset( $cache[ $key ] ) ) {
				$css           = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload.
				$cache[ $key ] = array(
					'classes' => self::design_classes( $css ),
					'props'   => self::animated_properties( $css ),
				);
			}
			$classes = array_merge( $classes, $cache[ $key ]['classes'] );
			$props   = array_merge( $props, $cache[ $key ]['props'] );
		}

		return array(
			'classes' => array_values( array_unique( $classes ) ),
			'props'   => array_values( array_unique( $props ) ),
		);
	}

	/**
	 * One of the plugin's classes: the declaration it stands for, or null.
	 *
	 * A name is the plugin's only when the theme has no class of that name —
	 * the theme's own `w-40px`, `text-14`, `fw-700` are always the theme's —
	 * and when it is the one name its value is written as (`text-14-50`,
	 * `h-058px` are not names of anything).
	 *
	 * @return array{prop:string, value:string, group:string, sort:float}|null
	 */
	public static function family( string $class ): ?array {
		if ( isset( self::$families[ $class ] ) ) {
			return self::$families[ $class ] === false ? null : self::$families[ $class ];
		}
		$found = null;
		if ( $class !== '' && preg_match( '/^[a-z][a-z0-9-]*$/', $class ) === 1 && ! isset( self::data()['classes'][ $class ] ) ) {
			$found = self::parse_family( $class );
			if ( $found !== null ) {
				$back = in_array( $found['prop'], self::WHOLE_PROPS, true )
					? self::whole_class( $found['prop'], self::canonical( $found['value'], 16.0 ) )
					: self::family_name( $found['prop'], self::canonical_for( $found['prop'], $found['value'], 16.0 ) );
				if ( $back !== $class ) {
					$found = null;
				}
			}
		}
		if ( count( self::$families ) > 5000 ) {
			self::$families = array();
		}
		self::$families[ $class ] = $found ?? false;

		return $found;
	}

	/**
	 * A family name read as its declaration, before the round-trip check.
	 *
	 * @return array{prop:string, value:string, group:string, sort:float}|null
	 */
	private static function parse_family( string $class ): ?array {
		$num = static fn( string $int, string $frac ): string => $frac === '' ? $int : $int . '.' . $frac;
		$out = static fn( string $prop, string $value, string $group, float $sort ): array => array(
			'prop'  => $prop,
			'value' => $value,
			'group' => $group,
			'sort'  => $sort,
		);
		if ( isset( self::KEYWORDS[ $class ] ) ) {
			$prop = self::KEYWORDS[ $class ][0];

			return $out( $prop, self::KEYWORDS[ $class ][1], $prop === 'border-radius' ? 'rounded' : 'kw', (float) array_search( $class, array_keys( self::KEYWORDS ), true ) );
		}
		if ( preg_match( '/^fw-([1-9]00)$/', $class, $m ) === 1 ) {
			return $out( 'font-weight', $m[1], 'fw', (float) $m[1] );
		}
		if ( preg_match( '/^text-(\d{1,3})(?:-(\d{1,4}))?$/', $class, $m ) === 1 ) {
			$v = $num( $m[1], $m[2] ?? '' );

			return $out( 'font-size', $v . 'px', 'text', (float) $v );
		}
		if ( preg_match( '/^lh-(\d{1,2})(?:-(\d{1,4}))?$/', $class, $m ) === 1 ) {
			$v = $num( $m[1], $m[2] ?? '' );

			return $out( 'line-height', $v, 'lh', (float) $v );
		}
		if ( preg_match( '/^ls-(\d{1,3})(?:-(\d{1,4}))?(em|px)$/', $class, $m ) === 1 ) {
			$v = $num( $m[1], $m[2] );

			return $out( 'letter-spacing', $v . $m[3], 'ls', (float) $v );
		}
		if ( preg_match( '/^rounded-(\d{1,4})px$/', $class, $m ) === 1 ) {
			return $out( 'border-radius', $m[1] . 'px', 'rounded', (float) $m[1] );
		}
		if ( preg_match( '/^(max-w|min-h|min-w|w|h)-(\d{1,5})px$/', $class, $m ) === 1 ) {
			return $out( self::SIZE_FAMILIES[ $m[1] ][0], $m[2] . 'px', $m[1], (float) $m[2] );
		}
		if ( preg_match( '/^(text|bg|shadow)-dxai-([a-z0-9]+(?:-[a-z0-9]+)*)$/', $class, $m ) === 1 ) {
			$prop = array(
				'text'   => 'color',
				'bg'     => 'background',
				'shadow' => 'box-shadow',
			)[ $m[1] ];

			return $out( $prop, 'var(--dxai-' . $m[2] . ')', $m[1] . '-dxai', 0.0 );
		}

		return null;
	}

	/**
	 * The plugin's class name for one longhand at one canonical value, or
	 * null (the reverse of parse_family()).
	 */
	private static function family_name( string $prop, string $value ): ?string {
		$dash = static fn( string $n ): string => str_replace( '.', '-', $n );
		switch ( $prop ) {
			case 'font-weight':
				return preg_match( '/^[1-9]00$/', $value ) === 1 ? 'fw-' . $value : null;
			case 'font-size':
				return preg_match( '/^(\d{1,3}(?:\.\d{1,4})?)px$/', $value, $m ) === 1 ? 'text-' . $dash( $m[1] ) : null;
			case 'line-height':
				return preg_match( '/^\d{1,2}(?:\.\d{1,4})?$/', $value ) === 1 ? 'lh-' . $dash( $value ) : null;
			case 'letter-spacing':
				return preg_match( '/^(\d{1,3}(?:\.\d{1,4})?)(em|px)$/', $value, $m ) === 1 ? 'ls-' . $dash( $m[1] ) . $m[2] : null;
			case 'border-radius':
				if ( $value === '50%' ) {
					return 'rounded-circle';
				}

				return preg_match( '/^(\d{1,4})px$/', $value, $m ) === 1 ? 'rounded-' . $m[1] . 'px' : null;
			case 'color':
			case 'box-shadow':
				return preg_match( '/^var\(--dxai-([a-z0-9]+(?:-[a-z0-9]+)*)\)$/', $value, $m ) === 1 ? ( $prop === 'color' ? 'text' : 'shadow' ) . '-dxai-' . $m[1] : null;
		}
		foreach ( self::SIZE_FAMILIES as $prefix => $family ) {
			if ( $family[0] === $prop ) {
				return preg_match( '/^(\d{1,5})px$/', $value, $m ) === 1 ? $prefix . '-' . $m[1] . 'px' : null;
			}
		}
		foreach ( self::KEYWORDS as $class => $pair ) {
			if ( $pair[0] === $prop && $pair[1] === $value && ! in_array( $prop, self::WHOLE_PROPS, true ) && $prop !== 'border-radius' ) {
				return $class;
			}
		}

		return null;
	}

	/**
	 * The plugin's class for a whole shorthand declaration (WHOLE_PROPS) at a
	 * canonical value, or null.
	 */
	private static function whole_class( string $prop, string $value ): ?string {
		if ( $prop === 'background' ) {
			return preg_match( '/^var\(--dxai-([a-z0-9]+(?:-[a-z0-9]+)*)\)$/', $value, $m ) === 1 ? 'bg-dxai-' . $m[1] : null;
		}
		foreach ( self::KEYWORDS as $class => $pair ) {
			if ( $pair[0] === $prop && $pair[1] === $value ) {
				return $class;
			}
		}

		return null;
	}

	/**
	 * The plugin's classes that fit an effective longhand map: one per
	 * longhand family can name, and `rounded-*` when all four corners agree.
	 * Each still has to be the plugin's (family()) — a value the theme names
	 * is the theme's candidate, not a second one.
	 *
	 * @param array<string, string> $effective
	 * @return array<int, array{class:string, pairs:array<string, string>}>
	 */
	private static function family_candidates( array $effective ): array {
		$out = array();
		foreach ( $effective as $longhand => $value ) {
			if ( in_array( $longhand, self::CORNERS, true ) ) {
				continue;
			}
			$name = self::family_name( $longhand, $value );
			if ( $name !== null && self::family( $name ) !== null ) {
				$out[] = array(
					'class' => $name,
					'pairs' => array( $longhand => $value ),
				);
			}
		}
		$corner = $effective[ self::CORNERS[0] ] ?? null;
		if ( $corner !== null ) {
			$same = true;
			foreach ( self::CORNERS as $longhand ) {
				$same = $same && ( $effective[ $longhand ] ?? null ) === $corner;
			}
			$name = $same ? self::family_name( 'border-radius', $corner ) : null;
			if ( $name !== null && self::family( $name ) !== null ) {
				$out[] = array(
					'class' => $name,
					'pairs' => array_fill_keys( self::CORNERS, $corner ),
				);
			}
		}

		return $out;
	}

	/**
	 * Where a family's rules sit in the theme's cascade: just after the last
	 * base (no media) rule of the theme classes it continues, else after all
	 * of the theme's rules.
	 */
	private static function family_position( string $group ): float {
		static $positions = array();
		if ( isset( $positions[ $group ] ) ) {
			return $positions[ $group ];
		}
		$data  = self::data();
		$regex = array(
			'fw'      => '/^fw-\d+$/',
			'text'    => '/^text-\d+$/',
			'lh'      => '/^line-height-/',
			'rounded' => '/^rounded(?:-|$)/',
		)[ $group ] ?? ( self::SIZE_FAMILIES[ $group ][1] ?? null );
		$at    = (float) count( (array) ( $data['rules'] ?? array() ) );
		if ( $regex !== null ) {
			$last = -1;
			foreach ( (array) ( $data['classes'] ?? array() ) as $class => $idx ) {
				if ( preg_match( $regex, (string) $class ) === 1 ) {
					foreach ( $idx as $i ) {
						if ( (int) $data['rules'][ $i ][0] === 0 && $i > $last ) {
							$last = (int) $i;
						}
					}
				}
			}
			if ( $last > -1 ) {
				$at = (float) $last;
			}
		}
		$positions[ $group ] = $at;

		return $at;
	}

	/**
	 * The selector list of one rule, narrowed to $want (see css_for_classes())
	 * and scoped.
	 *
	 * @param array<string, bool> $want
	 */
	private static function selector_for( string $selector, int $form, array $want, bool $editor ): string {
		if ( self::$part !== null && $form === 2 ) {
			// The theme's compound selectors (`.a:hover img`, `.x > .y`) name their own elements.
			return '';
		}
		if ( $form !== 2 ) {
			$keep = array();
			foreach ( explode( ' ', $selector ) as $class ) {
				if ( isset( $want[ $class ] ) ) {
					$keep[] = $class;
				}
			}
			if ( $keep === array() ) {
				return '';
			}
			$list = array_map( static fn( $c ) => self::scoped( '.' . $c, $c, $editor ), $keep );
			if ( $form === 1 && self::$part === null ) {
				foreach ( $keep as $class ) {
					$list[] = self::scoped( '.wp-block-button.' . $class, $class, $editor ) . '>a';
				}
			}

			return implode( ',', $list );
		}

		$data = self::data();
		$keep = array();
		foreach ( self::split_selectors( $selector ) as $one ) {
			preg_match_all( '/\.((?:\\\\.|[A-Za-z0-9_-])+)/', $one, $m, PREG_OFFSET_CAPTURE );
			$tokens = array_map( static fn( $t ) => stripslashes( $t[0] ), $m[1] );
			$lead_i = ( $tokens[0] ?? '' ) === 'wp-block-button' ? 1 : 0;
			$lead   = $tokens[ $lead_i ] ?? '';
			if ( ! isset( $want[ $lead ] ) ) {
				continue;
			}
			foreach ( $tokens as $token ) {
				if ( isset( $data['classes'][ $token ] ) && ! isset( $want[ $token ] ) ) {
					continue 2;
				}
			}
			// The class the selector is filed under, written by lead(), in
			// its own compound: `:where(.x):where(…):hover img`,
			// `.wp-block-button:where(.x):where(…)>a`.
			$start  = (int) $m[0][ $lead_i ][1];
			$end    = $start + strlen( $m[0][ $lead_i ][0] );
			$keep[] = substr( $one, 0, $start ) . self::lead( $m[1][ $lead_i ][0], $editor ) . substr( $one, $end );
		}

		return implode( ',', $keep );
	}

	/** A compound ending in the class it is filed under, written as lead() writes it. */
	private static function scoped( string $compound, string $class, bool $editor ): string {
		return substr( $compound, 0, strlen( $compound ) - strlen( '.' . $class ) ) . self::lead( $class, $editor );
	}

	/**
	 * The class a selector is filed under ($class as written in CSS), as it
	 * is written in the plugin's copy: `:where(.x)` and the scope — (0,0,0) —
	 * on the page; `.x` with the lift and the scope — (2,1,0) — in the
	 * editor form (see the class comment).
	 */
	private static function lead( string $class, bool $editor ): string {
		if ( self::$part !== null ) {
			$wrapped = self::$part['wrap'] . '.' . $class;

			return ( $editor ? '.' . $wrapped . sprintf( self::LIFT, $class ) : ':where(.' . $wrapped . ')' ) . self::SCOPE . self::$part['inner'];
		}

		return ( $editor ? '.' . $class . sprintf( self::LIFT, $class ) : ':where(.' . $class . ')' ) . self::SCOPE;
	}

	/**
	 * A declaration block with every rem length written as px at 16px
	 * (`.875rem` → `14px`, `calc(100% - .0625rem)` → `calc(100% - 1px)`).
	 */
	private static function px_lengths( string $decls ): string {
		if ( ! str_contains( $decls, 'rem' ) ) {
			return $decls;
		}

		return (string) preg_replace_callback(
			'/(?<![A-Za-z0-9_.-])(-?)(\d+\.?\d*|\.\d+)rem(?![A-Za-z0-9_-])/',
			static function ( array $m ): string {
				$n = (float) $m[2] * 16;
				if ( $n == 0 ) { // phpcs:ignore Universal.Operators.StrictComparisonOperator -- float zero.
					return '0';
				}

				return $m[1] . rtrim( rtrim( number_format( $n, 4, '.', '' ), '0' ), '.' ) . 'px';
			},
			$decls
		);
	}

	/**
	 * @return array<int, string>
	 */
	private static function split_selectors( string $list ): array {
		$out   = array();
		$depth = 0;
		$start = 0;
		$len   = strlen( $list );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $list[ $i ];
			if ( $ch === '\\' ) {
				++$i;
			} elseif ( $ch === '(' || $ch === '[' ) {
				++$depth;
			} elseif ( $ch === ')' || $ch === ']' ) {
				--$depth;
			} elseif ( $ch === ',' && $depth === 0 ) {
				$out[] = substr( $list, $start, $i - $start );
				$start = $i + 1;
			}
		}
		$out[] = substr( $list, $start );

		return $out;
	}

	/**
	 * Every matchable utility as canonical longhand pairs, plus an index from
	 * each pair to the utilities that set it. Built once per request, from
	 * the same canonical form match() reads block CSS in, so the two cannot
	 * disagree about what "equal" means.
	 *
	 * @return array{list: array<int, array{class:string, pairs:array<string, string>}>, by_pair: array<string, array<int, int>>}
	 */
	private static function candidates(): array {
		if ( self::$candidates !== null ) {
			return self::$candidates;
		}
		$data = self::data();
		$list = array();
		$by   = array();
		foreach ( (array) ( $data['matchable'] ?? array() ) as $class ) {
			/*
			 * What the class ends up setting on its element: its own rules
			 * in cascade order, longhand by longhand — a later declaration
			 * wins unless the earlier one is important and it is not (the
			 * theme's `.w-full{width:100%}` is overruled by its own later
			 * `!important` one). Every longhand must end up important, and
			 * every declaration must be one this class can expand exactly.
			 */
			$final = array();
			foreach ( (array) ( $data['classes'][ $class ] ?? array() ) as $idx ) {
				$rule = $data['rules'][ $idx ];
				if ( ! self::names_self( (string) $rule[1], (int) $rule[3], (string) $class ) ) {
					continue;
				}
				foreach ( self::declarations( (string) $data['decls'][ (int) $rule[2] ] ) as $decl ) {
					$sides = $decl['prop'] === '' ? null : self::expand( $decl['prop'], $decl['value'] );
					if ( $sides === null ) {
						continue 3;
					}
					foreach ( $sides as $longhand => $token ) {
						$had = $final[ $longhand ] ?? null;
						if ( $had === null || $decl['important'] || ! $had[1] ) {
							$final[ $longhand ] = array( self::canonical_for( $longhand, $token, 16.0 ), $decl['important'] );
						}
					}
				}
			}
			$pairs = array();
			foreach ( $final as $longhand => $set ) {
				if ( ! $set[1] ) {
					continue 2;
				}
				$pairs[ $longhand ] = $set[0];
			}
			if ( $pairs === array() ) {
				continue;
			}
			ksort( $pairs );
			/*
			 * Classes that declare the same thing (`max-w-640px` and the
			 * legacy `max-w-640`, `w-full` and its twins) all stay candidates,
			 * in the catalogue's preference order: match() takes the first
			 * one the page does not use as a design class, and cover() never
			 * takes a second one for the same longhands.
			 */
			$i      = count( $list );
			$list[] = array(
				'class' => (string) $class,
				'pairs' => $pairs,
			);
			foreach ( $pairs as $longhand => $value ) {
				$by[ $longhand . ':' . $value ][] = $i;
			}
		}
		self::$candidates = array(
			'list'    => $list,
			'by_pair' => $by,
		);

		return self::$candidates;
	}

	/** Whether a rule names $class as `.class` itself (not only in a twin or compound). */
	private static function names_self( string $selector, int $form, string $class ): bool {
		if ( $form !== 2 ) {
			return in_array( $class, explode( ' ', $selector ), true );
		}

		return in_array( '.' . $class, array_map( 'trim', self::split_selectors( $selector ) ), true );
	}

	/**
	 * Whether a raw (form 2) selector list has a selector filed under $class
	 * that is not a core/button twin — `.x`, `.x:hover`, `.x img`, anything
	 * that starts at an element carrying the class. Descendant targets count
	 * too: for a conflict check, erring towards "it may" is the safe side.
	 */
	private static function reaches_element( string $selector, string $class ): bool {
		foreach ( self::split_selectors( $selector ) as $one ) {
			$one = trim( $one );
			if ( str_starts_with( $one, '.' . $class ) && preg_match( '/^\.' . preg_quote( $class, '/' ) . '(?![A-Za-z0-9_-])/', $one ) === 1 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A declaration list as parts, in order: property (lower case), value
	 * (without `!important`), whether it was important, and the part as
	 * written. Split on the semicolons that end declarations — not those in
	 * parentheses or quotes (a data: URI has one).
	 *
	 * @return array<int, array{prop:string, value:string, important:bool, raw:string}>
	 */
	public static function declarations( string $css ): array {
		$out   = array();
		$depth = 0;
		$quote = '';
		$start = 0;
		$len   = strlen( $css );
		for ( $i = 0; $i <= $len; $i++ ) {
			$ch = $i < $len ? $css[ $i ] : ';';
			if ( $quote !== '' ) {
				if ( $ch === '\\' ) {
					++$i;
				} elseif ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( $ch === '"' || $ch === "'" ) {
				$quote = $ch;
			} elseif ( $ch === '(' ) {
				++$depth;
			} elseif ( $ch === ')' ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( $ch === ';' && $depth === 0 ) {
				$raw   = trim( substr( $css, $start, $i - $start ) );
				$start = $i + 1;
				if ( $raw === '' ) {
					continue;
				}
				$colon = strpos( $raw, ':' );
				if ( $colon === false ) {
					$out[] = array(
						'prop'      => '',
						'value'     => '',
						'important' => false,
						'raw'       => $raw,
					);
					continue;
				}
				$value     = trim( substr( $raw, $colon + 1 ) );
				$important = preg_match( '/!\s*important\s*$/i', $value ) === 1;
				if ( $important ) {
					$value = trim( (string) preg_replace( '/!\s*important\s*$/i', '', $value ) );
				}
				$out[] = array(
					'prop'      => strtolower( trim( substr( $raw, 0, $colon ) ) ),
					'value'     => $value,
					'important' => $important,
					'raw'       => $raw,
				);
			}
		}

		return $out;
	}

	/**
	 * A declaration as physical longhands => value tokens as written, or null
	 * when it cannot be expanded exactly. A longhand is itself.
	 *
	 * @return array<string, string>|null
	 */
	private static function expand( string $prop, string $value ): ?array {
		$value = trim( $value );
		if ( $value === '' ) {
			return null;
		}
		$shorthand = in_array( $prop, array( 'margin', 'padding', 'inset', 'border-width', 'border-style', 'border-color', 'gap', 'grid-gap', 'border-radius', 'overflow', 'flex-flow', 'flex' ), true );
		if ( ! $shorthand ) {
			if ( in_array( $prop, array( 'grid-row-gap', 'grid-column-gap' ), true ) ) {
				return array( substr( $prop, 5 ) => $value );
			}

			return self::touched( $prop ) === array( $prop ) ? array( $prop => $value ) : null;
		}
		// A variable can stand for several tokens; which sides get what is
		// then unknown until the page renders.
		if ( stripos( $value, 'var(' ) !== false || stripos( $value, 'env(' ) !== false || stripos( $value, 'attr(' ) !== false ) {
			return null;
		}
		$tokens = self::tokens( $value );
		$n      = count( $tokens );
		if ( $n === 1 && in_array( strtolower( $tokens[0] ), self::WIDE, true ) ) {
			return array_fill_keys( self::touched( $prop ), $tokens[0] );
		}
		switch ( $prop ) {
			case 'margin':
			case 'padding':
			case 'inset':
			case 'border-width':
			case 'border-style':
			case 'border-color':
				if ( $n < 1 || $n > 4 ) {
					return null;
				}
				$four = self::four( $tokens );
				$out  = array();
				foreach ( self::SIDES as $k => $side ) {
					$name         = $prop === 'inset' ? $side : ( str_starts_with( $prop, 'border-' ) ? 'border-' . $side . '-' . substr( $prop, 7 ) : $prop . '-' . $side );
					$out[ $name ] = $four[ $k ];
				}

				return $out;
			case 'gap':
			case 'grid-gap':
				if ( $n < 1 || $n > 2 ) {
					return null;
				}

				return array(
					'row-gap'    => $tokens[0],
					'column-gap' => $tokens[ $n - 1 ],
				);
			case 'border-radius':
				if ( $n < 1 || $n > 4 || str_contains( $value, '/' ) ) {
					return null;
				}
				$four = array(
					$tokens[0],
					$tokens[1] ?? $tokens[0],
					$tokens[2] ?? $tokens[0],
					$tokens[3] ?? ( $tokens[1] ?? $tokens[0] ),
				);

				return array_combine( self::CORNERS, $four );
			case 'overflow':
				if ( $n < 1 || $n > 2 ) {
					return null;
				}

				return array(
					'overflow-x' => $tokens[0],
					'overflow-y' => $tokens[ $n - 1 ],
				);
			case 'flex-flow':
				return self::expand_flex_flow( $tokens );
			case 'flex':
				return self::expand_flex( $tokens );
		}

		return null;
	}

	/**
	 * The four sides of a 1-4 value box shorthand, top right bottom left.
	 *
	 * @param array<int, string> $t
	 * @return array<int, string>
	 */
	private static function four( array $t ): array {
		$top    = $t[0];
		$right  = $t[1] ?? $top;
		$bottom = $t[2] ?? $top;
		$left   = $t[3] ?? $right;

		return array( $top, $right, $bottom, $left );
	}

	/**
	 * @param array<int, string> $t
	 * @return array<string, string>|null
	 */
	private static function expand_flex_flow( array $t ): ?array {
		$direction = array( 'row', 'row-reverse', 'column', 'column-reverse' );
		$wrap      = array( 'nowrap', 'wrap', 'wrap-reverse' );
		$out       = array(
			'flex-direction' => 'row',
			'flex-wrap'      => 'nowrap',
		);
		if ( count( $t ) < 1 || count( $t ) > 2 ) {
			return null;
		}
		$set = array();
		foreach ( $t as $token ) {
			$low = strtolower( $token );
			if ( in_array( $low, $direction, true ) && ! isset( $set['flex-direction'] ) ) {
				$set['flex-direction'] = $token;
			} elseif ( in_array( $low, $wrap, true ) && ! isset( $set['flex-wrap'] ) ) {
				$set['flex-wrap'] = $token;
			} else {
				return null;
			}
		}

		return array_merge( $out, $set );
	}

	/**
	 * `flex` as grow, shrink and basis — the forms the spec defines exactly:
	 * none, auto, initial, one number (`n 1 0%`), one basis (`1 1 basis`),
	 * two numbers, a number and a basis, all three.
	 *
	 * @param array<int, string> $t
	 * @return array<string, string>|null
	 */
	private static function expand_flex( array $t ): ?array {
		$num  = static fn( string $s ): bool => preg_match( '/^(?:\d+\.?\d*|\.\d+)$/', $s ) === 1;
		$make = static fn( string $g, string $s, string $b ): array => array(
			'flex-grow'   => $g,
			'flex-shrink' => $s,
			'flex-basis'  => $b,
		);
		$n    = count( $t );
		if ( $n === 1 ) {
			$low = strtolower( $t[0] );
			if ( $low === 'none' ) {
				return $make( '0', '0', 'auto' );
			}
			if ( $low === 'auto' ) {
				return $make( '1', '1', 'auto' );
			}
			if ( $low === 'initial' ) {
				return $make( '0', '1', 'auto' );
			}

			return $num( $t[0] ) ? $make( $t[0], '1', '0%' ) : $make( '1', '1', $t[0] );
		}
		if ( $n === 2 && $num( $t[0] ) ) {
			return $num( $t[1] ) ? $make( $t[0], $t[1], '0%' ) : $make( $t[0], '1', $t[1] );
		}
		if ( $n === 3 && $num( $t[0] ) && $num( $t[1] ) ) {
			return $make( $t[0], $t[1], $t[2] );
		}

		return null;
	}

	/**
	 * The longhands a declaration can set — itself for a longhand, every
	 * sub-property for a shorthand, `*` for `all`. Used to block matching on
	 * whatever a declaration this class does not reproduce could reach.
	 *
	 * @return array<int, string>
	 */
	private static function touched( string $prop ): array {
		$sides = static fn( string $pre, string $post = '' ): array => array_map( static fn( $s ) => $pre . $s . $post, self::SIDES );
		$box   = array_merge( $sides( 'border-', '-width' ), $sides( 'border-', '-style' ), $sides( 'border-', '-color' ) );
		switch ( $prop ) {
			case 'all':
				return array( '*' );
			case 'margin':
			case 'padding':
				return $sides( $prop . '-' );
			case 'inset':
				return self::SIDES;
			case 'gap':
			case 'grid-gap':
				return array( 'row-gap', 'column-gap' );
			case 'border-radius':
				return self::CORNERS;
			case 'overflow':
				return array( 'overflow-x', 'overflow-y' );
			case 'border-width':
			case 'border-style':
			case 'border-color':
				return $sides( 'border-', '-' . substr( $prop, 7 ) );
			case 'border':
				return array_merge( $box, array( 'border-image-source', 'border-image-slice', 'border-image-width', 'border-image-outset', 'border-image-repeat' ) );
			case 'border-top':
			case 'border-right':
			case 'border-bottom':
			case 'border-left':
				return array( $prop . '-width', $prop . '-style', $prop . '-color' );
			case 'flex':
				return array( 'flex-grow', 'flex-shrink', 'flex-basis' );
			case 'flex-flow':
				return array( 'flex-direction', 'flex-wrap' );
			case 'background':
				return array( 'background-color', 'background-image', 'background-position', 'background-position-x', 'background-position-y', 'background-size', 'background-repeat', 'background-attachment', 'background-origin', 'background-clip' );
			case 'font':
				return array( 'font-family', 'font-size', 'font-weight', 'font-style', 'font-variant', 'font-stretch', 'line-height', 'font-size-adjust', 'font-kerning', 'font-feature-settings', 'font-variation-settings', 'font-optical-sizing', 'font-variant-caps', 'font-variant-numeric', 'font-variant-ligatures', 'font-variant-east-asian', 'font-variant-position' );
			case 'grid':
			case 'grid-template':
				return array( 'grid-template-columns', 'grid-template-rows', 'grid-template-areas', 'grid-auto-columns', 'grid-auto-rows', 'grid-auto-flow' );
			case 'grid-area':
				return array( 'grid-row-start', 'grid-row-end', 'grid-column-start', 'grid-column-end' );
			case 'grid-row':
			case 'grid-column':
				return array( $prop . '-start', $prop . '-end' );
			case 'place-items':
				return array( 'align-items', 'justify-items' );
			case 'place-content':
				return array( 'align-content', 'justify-content' );
			case 'place-self':
				return array( 'align-self', 'justify-self' );
			case 'text-decoration':
				return array( 'text-decoration-line', 'text-decoration-color', 'text-decoration-style', 'text-decoration-thickness' );
			case 'list-style':
				return array( 'list-style-type', 'list-style-position', 'list-style-image' );
			case 'outline':
				return array( 'outline-width', 'outline-style', 'outline-color' );
			case 'columns':
				return array( 'column-width', 'column-count' );
			case 'white-space':
				// CSS Text 4: a shorthand of these two, and text-wrap shares one.
				return array( 'white-space-collapse', 'text-wrap-mode' );
			case 'text-wrap':
				return array( 'text-wrap-mode', 'text-wrap-style' );
			case 'inline-size':
			case 'block-size':
				return array( 'width', 'height' );
			case 'min-inline-size':
			case 'min-block-size':
				return array( 'min-width', 'min-height' );
			case 'max-inline-size':
			case 'max-block-size':
				return array( 'max-width', 'max-height' );
			case 'overflow-inline':
			case 'overflow-block':
				return array( 'overflow-x', 'overflow-y' );
		}
		// Logical box properties reach two physical sides, which ones depends
		// on the writing mode: block both.
		if ( preg_match( '/^(margin|padding|inset|scroll-margin|scroll-padding)-(inline|block)(?:-(?:start|end))?$/', $prop, $m ) === 1 ) {
			$pair = $m[2] === 'inline' ? array( 'left', 'right' ) : array( 'top', 'bottom' );
			$pre  = $m[1] === 'inset' ? '' : $m[1] . '-';

			return array_map( static fn( $s ) => $pre . $s, $pair );
		}
		if ( str_starts_with( $prop, 'border-block' ) || str_starts_with( $prop, 'border-inline' ) ) {
			return $box;
		}
		if ( preg_match( '/^border-(?:start|end)-(?:start|end)-radius$/', $prop ) === 1 ) {
			return self::CORNERS;
		}
		foreach ( array( 'transition', 'animation', 'mask', 'border-image', 'offset', 'text-emphasis', 'column-rule', 'container', 'contain-intrinsic-size' ) as $family ) {
			if ( $prop === $family ) {
				return array( $prop . '-*' );
			}
		}

		return array( $prop );
	}

	/**
	 * A value split on its top-level whitespace (not inside parentheses or
	 * quotes).
	 *
	 * @return array<int, string>
	 */
	private static function tokens( string $value ): array {
		$out   = array();
		$depth = 0;
		$quote = '';
		$cur   = '';
		$len   = strlen( $value );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $value[ $i ];
			if ( $quote !== '' ) {
				$cur .= $ch;
				if ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( $ch === '"' || $ch === "'" ) {
				$quote = $ch;
			} elseif ( $ch === '(' ) {
				++$depth;
			} elseif ( $ch === ')' ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( ctype_space( $ch ) && $depth === 0 ) {
				if ( $cur !== '' ) {
					$out[] = $cur;
				}
				$cur = '';
				continue;
			}
			$cur .= $ch;
		}
		if ( $cur !== '' ) {
			$out[] = $cur;
		}

		return $out;
	}

	/**
	 * canonical() for one longhand: font-weight's two keywords are the
	 * numbers they compute to (`bold` is 700, `normal` 400).
	 */
	private static function canonical_for( string $longhand, string $value, float $root ): string {
		$out = self::canonical( $value, $root );
		if ( $longhand === 'font-weight' ) {
			return array(
				'normal' => '400',
				'bold'   => '700',
			)[ $out ] ?? $out;
		}

		return $out;
	}

	/**
	 * One value in the form two equal values share: lower case outside
	 * quotes and custom property names (`var(--dxai-Brand)` is not
	 * `var(--dxai-brand)`), single spaces and none around `(`, `)` and `,`,
	 * rem as px at the root size, a zero length as `0`, numbers without
	 * redundant zeros, `transparent` as the `rgba(0,0,0,0)` Sass writes for it.
	 */
	private static function canonical( string $value, float $root ): string {
		$out   = '';
		$quote = '';
		$len   = strlen( $value );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $value[ $i ];
			if ( $quote !== '' ) {
				$out .= $ch;
				if ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( $ch === '"' || $ch === "'" ) {
				$quote = $ch;
			}
			// A custom property name keeps its case.
			if ( $ch === '-' && ( $value[ $i + 1 ] ?? '' ) === '-' && ( $i === 0 || preg_match( '/[A-Za-z0-9_-]/', $value[ $i - 1 ] ) !== 1 ) && preg_match( '/\G--[A-Za-z0-9_-]*/', $value, $name, 0, $i ) === 1 ) {
				$out .= $name[0];
				$i   += strlen( $name[0] ) - 1;
				continue;
			}
			$out .= $quote === '' ? strtolower( $ch ) : $ch;
		}
		$out = trim( (string) preg_replace( '/\s+/', ' ', $out ) );
		$out = (string) preg_replace( '/\s*([(),])\s*/', '$1', $out );
		/*
		 * Numbers: px and rem as px, a zero px/rem/unitless length as `0`;
		 * any other unit keeps its unit and its zero (`0fr` is a flexible
		 * track, not a zero length; `0%` and `0` differ as a flex-basis), only
		 * the number is written one way (`.02em` and `0.020em` are `0.02em`).
		 */
		$out = (string) preg_replace_callback(
			'/(?<![A-Za-z0-9#._-])(-?)(\d+\.?\d*|\.\d+)([a-z]+|%)?(?![A-Za-z0-9%._-])/',
			static function ( array $m ) use ( $root ): string {
				$unit = $m[3] ?? '';
				$n    = (float) $m[2];
				if ( $unit === 'rem' ) {
					$n *= $root;
				}
				$num = rtrim( rtrim( number_format( $n, 6, '.', '' ), '0' ), '.' );
				if ( $unit === '' || $unit === 'px' || $unit === 'rem' ) {
					if ( $n == 0 ) { // phpcs:ignore Universal.Operators.StrictComparisonOperator -- float zero, either sign.
						return '0';
					}

					return $m[1] . $num . ( $unit !== '' ? 'px' : '' );
				}

				return ( $n == 0 ? '' : $m[1] ) . $num . $unit; // phpcs:ignore Universal.Operators.StrictComparisonOperator -- float zero.
			},
			$out
		);

		return $out === 'transparent' ? 'rgba(0,0,0,0)' : str_replace( 'transparent', 'rgba(0,0,0,0)', $out );
	}
}

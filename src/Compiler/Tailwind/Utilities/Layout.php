<?php
/**
 * Layout utilities: display, position, offsets, overflow, object fit and more.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler\Tailwind\Utilities;

use DXAI_UI\Compiler\Tailwind\Candidate;
use DXAI_UI\Compiler\Tailwind\Theme;
use DXAI_UI\Compiler\Tailwind\Utility_Module;

/**
 * Everything Tailwind groups under "Layout": how a box participates in flow
 * (display, float, clear, break-*), where it sits (position, inset/top/right/
 * bottom/left, z-index), how it clips (overflow, overscroll, visibility), and
 * the intrinsic-size utilities (aspect-ratio, columns, object-fit).
 *
 * Sizing (`w-*`, `h-*`, `min-h-*`), the flex and grid child utilities and the
 * scroll-snap family belong to other namespaces and are deliberately not
 * claimed here.
 *
 * Last of the ten modules, so it also takes in the one-property families too
 * small to earn a module and with no closer owner: the table trio
 * (`table-layout`, `caption-side`, `border-collapse`), CSS containment,
 * `container-type`, `field-sizing`, `forced-color-adjust` and `color-scheme`.
 * Being last is why that is safe — a family placed here can only answer for
 * classes the nine narrower modules already declined.
 */
final class Layout implements Utility_Module {

	/**
	 * Utilities whose whole class name is the value.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const KEYWORDS = array(

		// Display.
		'inline'                    => array( 'display' => 'inline' ),
		'block'                     => array( 'display' => 'block' ),
		'inline-block'              => array( 'display' => 'inline-block' ),
		'flow-root'                 => array( 'display' => 'flow-root' ),
		'flex'                      => array( 'display' => 'flex' ),
		'inline-flex'               => array( 'display' => 'inline-flex' ),
		'grid'                      => array( 'display' => 'grid' ),
		'inline-grid'               => array( 'display' => 'inline-grid' ),
		'contents'                  => array( 'display' => 'contents' ),
		'list-item'                 => array( 'display' => 'list-item' ),
		'hidden'                    => array( 'display' => 'none' ),

		// Display: table parts.
		'table'                     => array( 'display' => 'table' ),
		'inline-table'              => array( 'display' => 'inline-table' ),
		'table-caption'             => array( 'display' => 'table-caption' ),
		'table-cell'                => array( 'display' => 'table-cell' ),
		'table-column'              => array( 'display' => 'table-column' ),
		'table-column-group'        => array( 'display' => 'table-column-group' ),
		'table-footer-group'        => array( 'display' => 'table-footer-group' ),
		'table-header-group'        => array( 'display' => 'table-header-group' ),
		'table-row-group'           => array( 'display' => 'table-row-group' ),
		'table-row'                 => array( 'display' => 'table-row' ),

		// Position.
		'static'                    => array( 'position' => 'static' ),
		'fixed'                     => array( 'position' => 'fixed' ),
		'absolute'                  => array( 'position' => 'absolute' ),
		'relative'                  => array( 'position' => 'relative' ),
		'sticky'                    => array( 'position' => 'sticky' ),

		// Visibility.
		'visible'                   => array( 'visibility' => 'visible' ),
		'invisible'                 => array( 'visibility' => 'hidden' ),
		'collapse'                  => array( 'visibility' => 'collapse' ),

		// Isolation.
		'isolate'                   => array( 'isolation' => 'isolate' ),
		'isolation-auto'            => array( 'isolation' => 'auto' ),

		// Box sizing.
		'box-border'                => array( 'box-sizing' => 'border-box' ),
		'box-content'               => array( 'box-sizing' => 'content-box' ),

		// Box decoration break.
		// Safari needs the prefix, so upstream emits both properties.
		'box-decoration-clone'      => array( '-webkit-box-decoration-break' => 'clone', 'box-decoration-break' => 'clone' ),
		'box-decoration-slice'      => array( '-webkit-box-decoration-break' => 'slice', 'box-decoration-break' => 'slice' ),

		// Overflow.
		'overflow-auto'             => array( 'overflow' => 'auto' ),
		'overflow-hidden'           => array( 'overflow' => 'hidden' ),
		'overflow-clip'             => array( 'overflow' => 'clip' ),
		'overflow-visible'          => array( 'overflow' => 'visible' ),
		'overflow-scroll'           => array( 'overflow' => 'scroll' ),
		'overflow-x-auto'           => array( 'overflow-x' => 'auto' ),
		'overflow-x-hidden'         => array( 'overflow-x' => 'hidden' ),
		'overflow-x-clip'           => array( 'overflow-x' => 'clip' ),
		'overflow-x-visible'        => array( 'overflow-x' => 'visible' ),
		'overflow-x-scroll'         => array( 'overflow-x' => 'scroll' ),
		'overflow-y-auto'           => array( 'overflow-y' => 'auto' ),
		'overflow-y-hidden'         => array( 'overflow-y' => 'hidden' ),
		'overflow-y-clip'           => array( 'overflow-y' => 'clip' ),
		'overflow-y-visible'        => array( 'overflow-y' => 'visible' ),
		'overflow-y-scroll'         => array( 'overflow-y' => 'scroll' ),

		// Overscroll behaviour.
		'overscroll-auto'           => array( 'overscroll-behavior' => 'auto' ),
		'overscroll-contain'        => array( 'overscroll-behavior' => 'contain' ),
		'overscroll-none'           => array( 'overscroll-behavior' => 'none' ),
		'overscroll-x-auto'         => array( 'overscroll-behavior-x' => 'auto' ),
		'overscroll-x-contain'      => array( 'overscroll-behavior-x' => 'contain' ),
		'overscroll-x-none'         => array( 'overscroll-behavior-x' => 'none' ),
		'overscroll-y-auto'         => array( 'overscroll-behavior-y' => 'auto' ),
		'overscroll-y-contain'      => array( 'overscroll-behavior-y' => 'contain' ),
		'overscroll-y-none'         => array( 'overscroll-behavior-y' => 'none' ),

		// Float.
		'float-start'               => array( 'float' => 'inline-start' ),
		'float-end'                 => array( 'float' => 'inline-end' ),
		'float-left'                => array( 'float' => 'left' ),
		'float-right'               => array( 'float' => 'right' ),
		'float-none'                => array( 'float' => 'none' ),

		// Clear.
		'clear-start'               => array( 'clear' => 'inline-start' ),
		'clear-end'                 => array( 'clear' => 'inline-end' ),
		'clear-left'                => array( 'clear' => 'left' ),
		'clear-right'               => array( 'clear' => 'right' ),
		'clear-both'                => array( 'clear' => 'both' ),
		'clear-none'                => array( 'clear' => 'none' ),

		// Break before.
		'break-before-auto'         => array( 'break-before' => 'auto' ),
		'break-before-avoid'        => array( 'break-before' => 'avoid' ),
		'break-before-all'          => array( 'break-before' => 'all' ),
		'break-before-avoid-page'   => array( 'break-before' => 'avoid-page' ),
		'break-before-page'         => array( 'break-before' => 'page' ),
		'break-before-left'         => array( 'break-before' => 'left' ),
		'break-before-right'        => array( 'break-before' => 'right' ),
		'break-before-column'       => array( 'break-before' => 'column' ),

		// Break after.
		'break-after-auto'          => array( 'break-after' => 'auto' ),
		'break-after-avoid'         => array( 'break-after' => 'avoid' ),
		'break-after-all'           => array( 'break-after' => 'all' ),
		'break-after-avoid-page'    => array( 'break-after' => 'avoid-page' ),
		'break-after-page'          => array( 'break-after' => 'page' ),
		'break-after-left'          => array( 'break-after' => 'left' ),
		'break-after-right'         => array( 'break-after' => 'right' ),
		'break-after-column'        => array( 'break-after' => 'column' ),

		// Break inside.
		'break-inside-auto'         => array( 'break-inside' => 'auto' ),
		'break-inside-avoid'        => array( 'break-inside' => 'avoid' ),
		'break-inside-avoid-page'   => array( 'break-inside' => 'avoid-page' ),
		'break-inside-avoid-column' => array( 'break-inside' => 'avoid-column' ),

		// Table layout and caption side, the pair that goes with the
		// `display: table-*` rows above.
		'table-auto'                => array( 'table-layout' => 'auto' ),
		'table-fixed'               => array( 'table-layout' => 'fixed' ),
		'caption-top'               => array( 'caption-side' => 'top' ),
		'caption-bottom'            => array( 'caption-side' => 'bottom' ),

		/*
		 * Named for the border namespace but a table property, so Border
		 * deliberately declines both and they land here with the rest of the
		 * table family. Border's doc comment already says so.
		 */
		'border-collapse'           => array( 'border-collapse' => 'collapse' ),
		'border-separate'           => array( 'border-collapse' => 'separate' ),

		/*
		 * The three containment keywords CSS forbids combining with anything
		 * else, so they write `contain` outright. The composable rest go
		 * through {@see self::CONTAIN_SLOTS}.
		 */
		'contain-none'              => array( 'contain' => 'none' ),
		'contain-content'           => array( 'contain' => 'content' ),
		'contain-strict'            => array( 'contain' => 'strict' ),

		// Field sizing.
		'field-sizing-content'      => array( 'field-sizing' => 'content' ),
		'field-sizing-fixed'        => array( 'field-sizing' => 'fixed' ),

		// Forced colour adjust.
		'forced-color-adjust-auto'  => array( 'forced-color-adjust' => 'auto' ),
		'forced-color-adjust-none'  => array( 'forced-color-adjust' => 'none' ),

		// Colour scheme.
		'scheme-normal'             => array( 'color-scheme' => 'normal' ),
		'scheme-dark'               => array( 'color-scheme' => 'dark' ),
		'scheme-light'              => array( 'color-scheme' => 'light' ),
		'scheme-light-dark'         => array( 'color-scheme' => 'light dark' ),
		'scheme-only-dark'          => array( 'color-scheme' => 'only dark' ),
		'scheme-only-light'         => array( 'color-scheme' => 'only light' ),
	);

	/**
	 * The `contain` slot each composable keyword writes, and the value in it.
	 *
	 * `contain-layout contain-paint` on one element has to come out as
	 * `contain: layout paint`, so each utility assigns only its own custom
	 * property and then restates the shorthand from all four. A shorthand
	 * built from the literal keyword would make whichever class the cascade
	 * applied last discard the other's containment. `size` and `inline-size`
	 * share a slot because CSS `contain` accepts only one of them.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const CONTAIN_SLOTS = array(
		'size'        => array( '--tw-contain-size', 'size' ),
		'inline-size' => array( '--tw-contain-size', 'inline-size' ),
		'layout'      => array( '--tw-contain-layout', 'layout' ),
		'paint'       => array( '--tw-contain-paint', 'paint' ),
		'style'       => array( '--tw-contain-style', 'style' ),
	);

	/**
	 * The four slots restated, in the order v4 writes them.
	 *
	 * Each `var()` carries an empty fallback so an unset slot contributes
	 * nothing instead of invalidating the whole shorthand.
	 */
	private const CONTAIN_SHORTHAND = 'var(--tw-contain-size,) var(--tw-contain-layout,) var(--tw-contain-paint,) var(--tw-contain-style,)';

	/**
	 * Offset utilities, longest prefix first: `resolve()` returns on the first
	 * prefix that matches, so every compound prefix has to precede the bare
	 * `inset` row or it would be read as `inset` with a token like `bs-4` that
	 * resolves to nothing. `inset-s` / `inset-e` are aliases of `start` /
	 * `end`: v4 emits the same inset-inline-start / inset-inline-end for both.
	 *
	 * @var array<string, string>
	 */
	private const OFFSETS = array(
		'inset-bs' => 'inset-block-start',
		'inset-be' => 'inset-block-end',
		'inset-x'  => 'inset-inline',
		'inset-y'  => 'inset-block',
		'inset-s'  => 'inset-inline-start',
		'inset-e'  => 'inset-inline-end',
		'inset'    => 'inset',
		'start'    => 'inset-inline-start',
		'end'      => 'inset-inline-end',
		'top'      => 'top',
		'right'    => 'right',
		'bottom'   => 'bottom',
		'left'     => 'left',
	);

	/** @var array<string, string> */
	private const OBJECT_FIT = array(
		'contain'    => 'contain',
		'cover'      => 'cover',
		'fill'       => 'fill',
		'none'       => 'none',
		'scale-down' => 'scale-down',
	);

	/**
	 * Both the v4.1 names and the older `object-left-top` spellings.
	 *
	 * @var array<string, string>
	 */
	private const OBJECT_POSITION = array(
		'bottom'       => 'bottom',
		'center'       => 'center',
		'left'         => 'left',
		'right'        => 'right',
		'top'          => 'top',
		'top-left'     => 'left top',
		'top-right'    => 'right top',
		'bottom-left'  => 'left bottom',
		'bottom-right' => 'right bottom',
		'left-top'     => 'left top',
		'left-bottom'  => 'left bottom',
		'right-top'    => 'right top',
		'right-bottom' => 'right bottom',
	);

	/**
	 * Ratios Tailwind ships even when the design supplies no `--aspect-*`.
	 *
	 * @var array<string, string>
	 */
	private const ASPECT_FALLBACKS = array(
		'auto'   => 'auto',
		'square' => '1 / 1',
		'video'  => '16 / 9',
	);

	/**
	 * Column widths named after the container scale, as v4 resolves them.
	 *
	 * @var array<int, string>
	 */
	private const COLUMN_SIZES = array(
		'3xs',
		'2xs',
		'xs',
		'sm',
		'md',
		'lg',
		'xl',
		'2xl',
		'3xl',
		'4xl',
		'5xl',
		'6xl',
		'7xl',
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
		'position',
		'any',
	);

	/**
	 * @return array<string, string>|null
	 */
	public function resolve( Candidate $candidate, Theme $theme ): ?array {
		$base = $candidate->base;

		if ( isset( self::KEYWORDS[ $base ] ) ) {
			// A keyword utility has no value to negate and takes no modifier.
			if ( $candidate->negative || $candidate->modifier !== '' ) {
				return null;
			}

			return self::KEYWORDS[ $base ];
		}

		if ( $base === 'sr-only' ) {
			if ( $candidate->negative || $candidate->modifier !== '' ) {
				return null;
			}

			return array(
				'position' => 'absolute',
				'width' => '1px',
				'height' => '1px',
				'padding' => '0',
				'margin' => '-1px',
				'overflow' => 'hidden',
				'clip-path' => 'inset(50%)',
				'white-space' => 'nowrap',
				'border-width' => '0',
			);
		}

		if ( $base === 'not-sr-only' ) {
			if ( $candidate->negative || $candidate->modifier !== '' ) {
				return null;
			}

			return array(
				'position' => 'static',
				'width' => 'auto',
				'height' => 'auto',
				'padding' => '0',
				'margin' => '0',
				'overflow' => 'visible',
				'clip-path' => 'none',
				'white-space' => 'normal',
			);
		}

		// The width only; the Engine adds the per-breakpoint max-widths, since
		// a module returns one declaration block and `container` needs several.
		if ( $base === 'container' ) {
			if ( $candidate->negative || $candidate->modifier !== '' ) {
				return null;
			}

			/*
			 * v4's container is width alone. v3's takes `center` and `padding`
			 * from the config, and the classic Lovable template sets both — so
			 * on such a design every `.container` section was full-bleed and
			 * flush to the viewport edge, which is the widest single geometry
			 * difference a legacy project had.
			 */
			$config      = $theme->container_config();
			$declarations = array( 'width' => '100%' );
			if ( ! empty( $config['center'] ) ) {
				$declarations['margin-inline'] = 'auto';
			}
			if ( ! empty( $config['padding'] ) ) {
				$declarations['padding-inline'] = (string) $config['padding'];
			}

			return $declarations;
		}

		/*
		 * Three families that read alike and are unrelated, kept together so
		 * the distinction stays visible: `container` above is the wrapper
		 * width, `@container` declares a container-query context, and
		 * `contain-*` is CSS containment. Neither test below is a prefix test
		 * on `contain`, which would swallow `container` whole.
		 */
		if ( $base === '@container' || str_starts_with( $base, '@container-' ) ) {
			return self::container_type( $base, $candidate );
		}

		$token = self::token( $candidate, 'contain' );
		if ( $token !== null ) {
			return self::contain( $token, $candidate->negative );
		}

		foreach ( self::OFFSETS as $prefix => $property ) {
			$token = self::token( $candidate, $prefix );
			if ( $token === null ) {
				continue;
			}

			$value = self::length( $token, $candidate->negative, $theme );

			return $value === null ? null : array( $property => $value );
		}

		$token = self::token( $candidate, 'z' );
		if ( $token !== null ) {
			return self::z_index( $token, $candidate->negative, $theme );
		}

		$token = self::token( $candidate, 'aspect' );
		if ( $token !== null ) {
			return self::aspect( $token, $candidate->negative, $theme );
		}

		$token = self::token( $candidate, 'columns' );
		if ( $token !== null ) {
			return self::columns( $token, $candidate->negative, $theme );
		}

		$token = self::token( $candidate, 'object' );
		if ( $token !== null ) {
			return self::object_value( $token, $candidate->negative );
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
	 * `@container`, its `normal` form, and an arbitrary `container-type`.
	 *
	 * Not a keyword row because the `/modifier` here is a container *name*
	 * rather than the opacity it means everywhere else: `@container/sidebar`
	 * has to name the context so an `@sidebar:` variant can query it.
	 *
	 * @return array<string, string>|null
	 */
	private static function container_type( string $base, Candidate $candidate ): ?array {
		if ( $candidate->negative ) {
			return null;
		}

		$token = $base === '@container' ? '' : substr( $base, strlen( '@container-' ) );

		/*
		 * Gated on the base, not on the token: a trailing hyphen leaves the
		 * token empty too, so testing the token gave `@container-` the bare
		 * utility's `inline-size` and emitted a rule for a class upstream does
		 * not recognise. The sibling `contain-` is rejected correctly, so the
		 * two halves of this dispatch disagreed with each other.
		 */
		if ( $base === '@container' ) {
			$type = 'inline-size';
		} elseif ( $token === 'normal' ) {
			$type = 'normal';
		} else {
			$type = self::literal( $token );
		}

		if ( $type === null || $type === '' ) {
			return null;
		}

		$out = array( 'container-type' => $type );

		if ( $candidate->modifier !== '' ) {
			$name = self::literal( $candidate->modifier );

			$out['container-name'] = $name === null || $name === '' ? $candidate->modifier : $name;
		}

		return $out;
	}

	/**
	 * `contain-*` for the composable keywords, plus an arbitrary value.
	 *
	 * The standalone keywords `none`, `content` and `strict` never reach here:
	 * they are keyword rows and answer before the dispatch that calls this.
	 *
	 * @return array<string, string>|null
	 */
	private static function contain( string $token, bool $negative ): ?array {
		if ( $negative ) {
			return null;
		}

		if ( isset( self::CONTAIN_SLOTS[ $token ] ) ) {
			list( $property, $value ) = self::CONTAIN_SLOTS[ $token ];

			return array(
				$property => $value,
				'contain' => self::CONTAIN_SHORTHAND,
			);
		}

		$value = self::literal( $token );

		return $value === null || $value === '' ? null : array( 'contain' => $value );
	}

	/**
	 * @return array<string, string>|null
	 */
	private static function z_index( string $token, bool $negative, Theme $theme ): ?array {
		if ( $token === 'auto' ) {
			return $negative ? null : array( 'z-index' => 'auto' );
		}

		$value = self::literal( $token );

		if ( $value === null || $value === '' ) {
			$value = $theme->value( 'z', $token );
		}

		if ( ( $value === null || $value === '' ) && self::is_integer( $token ) ) {
			$value = $token;
		}

		if ( $value === null || $value === '' ) {
			return null;
		}

		return array( 'z-index' => $negative ? self::negate( $value ) : $value );
	}

	/**
	 * @return array<string, string>|null
	 */
	private static function aspect( string $token, bool $negative, Theme $theme ): ?array {
		if ( $negative ) {
			return null;
		}

		$value = self::literal( $token );

		if ( $value === null || $value === '' ) {
			$value = $theme->value( 'aspect', $token );
		}

		if ( ( $value === null || $value === '' ) && isset( self::ASPECT_FALLBACKS[ $token ] ) ) {
			$value = self::ASPECT_FALLBACKS[ $token ];
		}

		// `aspect-3/2` reaches us as base `aspect-3` plus modifier `2`.
		if ( ( $value === null || $value === '' ) && preg_match( '#^\d+(?:\.\d+)?(?:/\d+(?:\.\d+)?)?$#', $token ) === 1 ) {
			$value = str_replace( '/', ' / ', $token );
		}

		if ( $value === null || $value === '' ) {
			return null;
		}

		return array( 'aspect-ratio' => $value );
	}

	/**
	 * @return array<string, string>|null
	 */
	private static function columns( string $token, bool $negative, Theme $theme ): ?array {
		if ( $negative ) {
			return null;
		}

		if ( $token === 'auto' ) {
			return array( 'columns' => 'auto' );
		}

		$value = self::literal( $token );

		if ( ( $value === null || $value === '' ) && self::is_integer( $token ) ) {
			$value = $token;
		}

		if ( $value === null || $value === '' ) {
			$value = $theme->value( 'columns', $token );
		}

		// `columns-md` reads the container scale, the way v4 does.
		if ( ( $value === null || $value === '' ) && in_array( $token, self::COLUMN_SIZES, true ) ) {
			$value = $theme->value( 'container', $token );
		}

		if ( $value === null || $value === '' ) {
			return null;
		}

		return array( 'columns' => $value );
	}

	/**
	 * `object-*` is object-fit for the five fit keywords and object-position for
	 * everything else, including arbitrary positions like `object-[25%_75%]`.
	 *
	 * @return array<string, string>|null
	 */
	private static function object_value( string $token, bool $negative ): ?array {
		if ( $negative ) {
			return null;
		}

		if ( isset( self::OBJECT_FIT[ $token ] ) ) {
			return array( 'object-fit' => self::OBJECT_FIT[ $token ] );
		}

		if ( isset( self::OBJECT_POSITION[ $token ] ) ) {
			return array( 'object-position' => self::OBJECT_POSITION[ $token ] );
		}

		$value = self::literal( $token );

		return $value === null || $value === '' ? null : array( 'object-position' => $value );
	}

	/**
	 * Resolve an offset value: arbitrary, custom property, fraction, `auto`, or
	 * a spacing-scale token.
	 */
	private static function length( string $token, bool $negative, Theme $theme ): ?string {
		$value = self::literal( $token );
		if ( $value !== null && $value !== '' ) {
			return $negative ? self::negate( $value ) : $value;
		}

		$fraction = self::fraction( $token, $negative );
		if ( $fraction !== null ) {
			return $fraction;
		}

		if ( $token === 'auto' ) {
			return $negative ? null : 'auto';
		}

		$spacing = $theme->spacing( $token );
		if ( ! is_string( $spacing ) || $spacing === '' ) {
			// `inset-0` must survive a theme that only knows named steps.
			$spacing = $token === '0' ? '0px' : null;
		}

		if ( $spacing === null ) {
			return null;
		}

		return $negative ? self::negate( $spacing ) : $spacing;
	}

	/**
	 * The value part of `<prefix>-<token>`, with a fraction denominator that the
	 * parser peeled off as a modifier stitched back on. Null when the class does
	 * not use this prefix, or carries a modifier that cannot belong to it.
	 */
	private static function token( Candidate $candidate, string $prefix ): ?string {
		$needle = $prefix . '-';
		if ( ! str_starts_with( $candidate->base, $needle ) ) {
			return null;
		}

		$token = substr( $candidate->base, strlen( $needle ) );
		if ( $token === '' ) {
			return null;
		}

		if ( $candidate->modifier === '' ) {
			return $token;
		}

		if ( self::is_integer( $token ) && self::is_integer( $candidate->modifier ) ) {
			return $token . '/' . $candidate->modifier;
		}

		return null;
	}

	/**
	 * An explicit value written as `[...]` or as the `(--var)` shorthand.
	 */
	private static function literal( string $token ): ?string {
		if ( str_starts_with( $token, '[' ) && str_ends_with( $token, ']' ) ) {
			$inner = self::strip_data_type( Candidate::decode_arbitrary( substr( $token, 1, -1 ) ) );

			return $inner === '' ? null : $inner;
		}

		if ( str_starts_with( $token, '(' ) && str_ends_with( $token, ')' ) ) {
			$inner = self::strip_data_type( Candidate::decode_arbitrary( substr( $token, 1, -1 ) ) );

			return str_starts_with( $inner, '--' ) ? 'var(' . $inner . ')' : null;
		}

		return null;
	}

	/**
	 * Tailwind allows `top-[length:var(--x)]`; the hint itself is not CSS.
	 */
	private static function strip_data_type( string $value ): string {
		if ( preg_match( '/^([a-z-]+):(.+)$/s', $value, $matches ) !== 1 ) {
			return $value;
		}

		return in_array( $matches[1], self::DATA_TYPES, true ) ? trim( $matches[2] ) : $value;
	}

	/**
	 * `top-1/2` becomes `calc(1/2 * 100%)`, matching v4's own output.
	 */
	private static function fraction( string $token, bool $negative ): ?string {
		if ( preg_match( '#^(\d+)/(\d+)$#', $token, $matches ) !== 1 ) {
			return null;
		}

		if ( (int) $matches[2] === 0 ) {
			return null;
		}

		return 'calc(' . $matches[1] . '/' . $matches[2] . ' * ' . ( $negative ? '-100%' : '100%' ) . ')';
	}

	/**
	 * Flip a resolved value so `-bottom-4` mirrors `bottom-4`.
	 */
	private static function negate( string $value ): string {
		if ( $value === '' ) {
			return $value;
		}

		if ( str_starts_with( $value, '-' ) ) {
			return trim( substr( $value, 1 ) );
		}

		if ( preg_match( '/^\d*\.?\d+(?:[a-z%]+)?$/i', $value ) === 1 ) {
			return '-' . $value;
		}

		return 'calc(' . $value . ' * -1)';
	}

	private static function is_integer( string $value ): bool {
		return preg_match( '/^\d+$/', $value ) === 1;
	}
}

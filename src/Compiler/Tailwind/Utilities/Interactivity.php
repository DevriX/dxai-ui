<?php
/**
 * Interactivity utilities: cursor, pointer-events, resize, scroll, select,
 * appearance, accent, caret, touch and snap.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler\Tailwind\Utilities;

use DXAI_UI\Compiler\Tailwind\Candidate;
use DXAI_UI\Compiler\Tailwind\Theme;
use DXAI_UI\Compiler\Tailwind\Utility_Module;

/**
 * Owns the Tailwind "Interactivity" namespace. Hover/focus *variants* live in
 * `Variants`; this module is the utilities those variants attach to
 * (`cursor-pointer`, `pointer-events-none`, `scroll-smooth`, …).
 *
 * Snap type utilities restated `scroll-snap-type` with
 * `var(--tw-scroll-snap-strictness, proximity)` so `snap-x` + `snap-mandatory`
 * compose the way they do in a real v4 build.
 */
final class Interactivity implements Utility_Module {

	private const SNAP_TYPE = 'var(--tw-scroll-snap-strictness, proximity)';

	/**
	 * The composable half of `touch-action`. Each slot falls back to nothing,
	 * so a class that sets only one still produces a valid shorthand.
	 */
	private const TOUCH_SLOTS = 'var(--tw-pan-x,) var(--tw-pan-y,) var(--tw-pinch-zoom,)';

	/**
	 * Keyword utilities whose whole class name is the value.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const KEYWORDS = array(
		'appearance-none'     => array( 'appearance' => 'none' ),
		'appearance-auto'     => array( 'appearance' => 'auto' ),

		/*
		 * `auto` is the initial `accent-color`, not a colour token, so the
		 * `accent-*` theme lookup in `theme_color()` can never produce it — v4
		 * registers `accent-auto` as a static utility ahead of its functional
		 * `accent` handler, and it belongs in this table for the same reason.
		 * `caret-auto` is deliberately absent: v4 registers no such spelling.
		 */
		'accent-auto'         => array( 'accent-color' => 'auto' ),

		'pointer-events-none' => array( 'pointer-events' => 'none' ),
		'pointer-events-auto' => array( 'pointer-events' => 'auto' ),

		'resize'              => array( 'resize' => 'both' ),
		'resize-none'         => array( 'resize' => 'none' ),
		'resize-y'            => array( 'resize' => 'vertical' ),
		'resize-x'            => array( 'resize' => 'horizontal' ),

		'scroll-auto'         => array( 'scroll-behavior' => 'auto' ),
		'scroll-smooth'       => array( 'scroll-behavior' => 'smooth' ),

		// Safari needs the prefix for user-select, so upstream emits both.
		'select-none'         => array( '-webkit-user-select' => 'none', 'user-select' => 'none' ),
		'select-text'         => array( '-webkit-user-select' => 'text', 'user-select' => 'text' ),
		'select-all'          => array( '-webkit-user-select' => 'all', 'user-select' => 'all' ),
		'select-auto'         => array( '-webkit-user-select' => 'auto', 'user-select' => 'auto' ),

		'touch-auto'          => array( 'touch-action' => 'auto' ),
		'touch-none'          => array( 'touch-action' => 'none' ),
		/*
		 * The pan and pinch utilities compose: `touch-pan-x touch-pinch-zoom`
		 * has to mean both, so each writes only its own slot and all of them
		 * restate the shorthand. Setting `touch-action` outright made the two
		 * classes fight, and whichever rule came second won.
		 */
		'touch-pan-x'         => array( '--tw-pan-x' => 'pan-x', 'touch-action' => self::TOUCH_SLOTS ),
		'touch-pan-left'      => array( '--tw-pan-x' => 'pan-left', 'touch-action' => self::TOUCH_SLOTS ),
		'touch-pan-right'     => array( '--tw-pan-x' => 'pan-right', 'touch-action' => self::TOUCH_SLOTS ),
		'touch-pan-y'         => array( '--tw-pan-y' => 'pan-y', 'touch-action' => self::TOUCH_SLOTS ),
		'touch-pan-up'        => array( '--tw-pan-y' => 'pan-up', 'touch-action' => self::TOUCH_SLOTS ),
		'touch-pan-down'      => array( '--tw-pan-y' => 'pan-down', 'touch-action' => self::TOUCH_SLOTS ),
		'touch-pinch-zoom'    => array( '--tw-pinch-zoom' => 'pinch-zoom', 'touch-action' => self::TOUCH_SLOTS ),
		'touch-manipulation'  => array( 'touch-action' => 'manipulation' ),

		'snap-start'          => array( 'scroll-snap-align' => 'start' ),
		'snap-end'            => array( 'scroll-snap-align' => 'end' ),
		'snap-center'         => array( 'scroll-snap-align' => 'center' ),
		'snap-align-none'     => array( 'scroll-snap-align' => 'none' ),
		'snap-normal'         => array( 'scroll-snap-stop' => 'normal' ),
		'snap-always'         => array( 'scroll-snap-stop' => 'always' ),
		'snap-none'           => array( 'scroll-snap-type' => 'none' ),
		'snap-x'              => array( 'scroll-snap-type' => 'x ' . self::SNAP_TYPE ),
		'snap-y'              => array( 'scroll-snap-type' => 'y ' . self::SNAP_TYPE ),
		'snap-both'           => array( 'scroll-snap-type' => 'both ' . self::SNAP_TYPE ),
		'snap-mandatory'      => array( '--tw-scroll-snap-strictness' => 'mandatory' ),
		'snap-proximity'     => array( '--tw-scroll-snap-strictness' => 'proximity' ),

		'cursor-auto'         => array( 'cursor' => 'auto' ),
		'cursor-default'      => array( 'cursor' => 'default' ),
		'cursor-pointer'      => array( 'cursor' => 'pointer' ),
		'cursor-wait'         => array( 'cursor' => 'wait' ),
		'cursor-text'         => array( 'cursor' => 'text' ),
		'cursor-move'         => array( 'cursor' => 'move' ),
		'cursor-help'         => array( 'cursor' => 'help' ),
		'cursor-not-allowed'  => array( 'cursor' => 'not-allowed' ),
		'cursor-none'         => array( 'cursor' => 'none' ),
		'cursor-context-menu' => array( 'cursor' => 'context-menu' ),
		'cursor-progress'     => array( 'cursor' => 'progress' ),
		'cursor-cell'         => array( 'cursor' => 'cell' ),
		'cursor-crosshair'    => array( 'cursor' => 'crosshair' ),
		'cursor-vertical-text'=> array( 'cursor' => 'vertical-text' ),
		'cursor-alias'        => array( 'cursor' => 'alias' ),
		'cursor-copy'         => array( 'cursor' => 'copy' ),
		'cursor-no-drop'      => array( 'cursor' => 'no-drop' ),
		'cursor-grab'         => array( 'cursor' => 'grab' ),
		'cursor-grabbing'     => array( 'cursor' => 'grabbing' ),
		'cursor-all-scroll'   => array( 'cursor' => 'all-scroll' ),
		'cursor-col-resize'   => array( 'cursor' => 'col-resize' ),
		'cursor-row-resize'   => array( 'cursor' => 'row-resize' ),
		'cursor-n-resize'     => array( 'cursor' => 'n-resize' ),
		'cursor-e-resize'     => array( 'cursor' => 'e-resize' ),
		'cursor-s-resize'     => array( 'cursor' => 's-resize' ),
		'cursor-w-resize'     => array( 'cursor' => 'w-resize' ),
		'cursor-ne-resize'    => array( 'cursor' => 'ne-resize' ),
		'cursor-nw-resize'    => array( 'cursor' => 'nw-resize' ),
		'cursor-se-resize'    => array( 'cursor' => 'se-resize' ),
		'cursor-sw-resize'    => array( 'cursor' => 'sw-resize' ),
		'cursor-ew-resize'    => array( 'cursor' => 'ew-resize' ),
		'cursor-ns-resize'    => array( 'cursor' => 'ns-resize' ),
		'cursor-nesw-resize'  => array( 'cursor' => 'nesw-resize' ),
		'cursor-nwse-resize'  => array( 'cursor' => 'nwse-resize' ),
		'cursor-zoom-in'      => array( 'cursor' => 'zoom-in' ),
		'cursor-zoom-out'     => array( 'cursor' => 'zoom-out' ),
	);

	/**
	 * Scroll margin / padding prefixes, grouped by family for reading.
	 *
	 * `scroll_box()` walks this table and stops at the first key that prefixes
	 * the class, but the needle it builds carries a trailing `-`, so the
	 * block-direction logical keys cannot collide with the two-letter physical
	 * ones they extend however the table is ordered: `scroll-mbs-4` does not
	 * start with `scroll-mb-`. Order is presentational here, not load-bearing —
	 * worth stating, because the obvious assumption is the opposite and a
	 * future edit might reorder these expecting it to matter.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const SCROLL_BOX = array(
		'scroll-mx'  => array( 'scroll-margin-inline' ),
		'scroll-my'  => array( 'scroll-margin-block' ),
		'scroll-ms'  => array( 'scroll-margin-inline-start' ),
		'scroll-me'  => array( 'scroll-margin-inline-end' ),
		'scroll-mt'  => array( 'scroll-margin-top' ),
		'scroll-mr'  => array( 'scroll-margin-right' ),
		'scroll-mbs' => array( 'scroll-margin-block-start' ),
		'scroll-mbe' => array( 'scroll-margin-block-end' ),
		'scroll-mb'  => array( 'scroll-margin-bottom' ),
		'scroll-ml'  => array( 'scroll-margin-left' ),
		'scroll-m'   => array( 'scroll-margin' ),
		'scroll-px'  => array( 'scroll-padding-inline' ),
		'scroll-py'  => array( 'scroll-padding-block' ),
		'scroll-ps'  => array( 'scroll-padding-inline-start' ),
		'scroll-pe'  => array( 'scroll-padding-inline-end' ),
		'scroll-pt'  => array( 'scroll-padding-top' ),
		'scroll-pr'  => array( 'scroll-padding-right' ),
		'scroll-pbs' => array( 'scroll-padding-block-start' ),
		'scroll-pbe' => array( 'scroll-padding-block-end' ),
		'scroll-pb'  => array( 'scroll-padding-bottom' ),
		'scroll-pl'  => array( 'scroll-padding-left' ),
		'scroll-p'   => array( 'scroll-padding' ),
	);

	/**
	 * @return array<string, string>|null
	 */
	public function resolve( Candidate $candidate, Theme $theme ): ?array {
		$base = $candidate->base;

		// Targets for the group-*/peer-* variants. They carry no declarations
		// of their own, but are owned here so they are not reported as gaps.
		if ( ( $base === 'group' || $base === 'peer' ) && $candidate->variants === array() ) {
			return array();
		}

		if ( isset( self::KEYWORDS[ $base ] ) ) {
			if ( $candidate->negative || $candidate->modifier !== '' ) {
				return null;
			}

			return self::KEYWORDS[ $base ];
		}

		$cursor = $this->cursor( $candidate );
		if ( $cursor !== null ) {
			return $cursor;
		}

		$accent = $this->theme_color( $candidate, 'accent', 'accent-color', $theme );
		if ( $accent !== null ) {
			return $accent;
		}

		$caret = $this->theme_color( $candidate, 'caret', 'caret-color', $theme );
		if ( $caret !== null ) {
			return $caret;
		}

		return $this->scroll_box( $candidate, $theme );
	}

	/**
	 * @return array<string, string>
	 */
	public function keyframes(): array {
		return array();
	}

	/**
	 * `cursor-[...]` and `cursor-(--var)`.
	 *
	 * @return array<string, string>|null
	 */
	private function cursor( Candidate $candidate ): ?array {
		if ( ! str_starts_with( $candidate->base, 'cursor-' ) ) {
			return null;
		}
		if ( $candidate->negative || $candidate->modifier !== '' ) {
			return null;
		}

		$token = substr( $candidate->base, strlen( 'cursor-' ) );
		$value = $this->literal( $token );

		return $value === null ? null : array( 'cursor' => $value );
	}

	/**
	 * @return array<string, string>|null
	 */
	private function theme_color( Candidate $candidate, string $prefix, string $property, Theme $theme ): ?array {
		$needle = $prefix . '-';
		if ( ! str_starts_with( $candidate->base, $needle ) ) {
			return null;
		}
		if ( $candidate->negative ) {
			return null;
		}

		$token = substr( $candidate->base, strlen( $needle ) );
		if ( $token === '' ) {
			return null;
		}

		$color = $this->literal( $token );
		if ( $color === null ) {
			$color = $theme->color( $token );
		}
		if ( ! is_string( $color ) || $color === '' ) {
			return null;
		}

		if ( $candidate->modifier !== '' ) {
			$color = $theme->with_alpha( $color, $candidate->modifier );
		}

		return array( $property => $color );
	}

	/**
	 * @return array<string, string>|null
	 */
	private function scroll_box( Candidate $candidate, Theme $theme ): ?array {
		foreach ( self::SCROLL_BOX as $prefix => $properties ) {
			$needle = $prefix . '-';
			if ( ! str_starts_with( $candidate->base, $needle ) ) {
				continue;
			}

			$token = substr( $candidate->base, strlen( $needle ) );
			if ( $token === '' ) {
				return null;
			}

			$value = $this->box_value( $token, $candidate->negative, $theme );
			if ( $value === null ) {
				return null;
			}

			$out = array();
			foreach ( $properties as $property ) {
				$out[ $property ] = $value;
			}

			return $out;
		}

		return null;
	}

	private function box_value( string $token, bool $negative, Theme $theme ): ?string {
		$literal = $this->literal( $token );
		if ( $literal !== null ) {
			return $negative ? $this->negate( $literal ) : $literal;
		}

		if ( $token === 'auto' || $token === 'px' ) {
			$value = $token === 'px' ? '1px' : 'auto';

			return $negative ? $this->negate( $value ) : $value;
		}

		$spacing = $theme->spacing( $token );
		if ( ! is_string( $spacing ) || $spacing === '' ) {
			$spacing = $token === '0' ? '0px' : null;
		}
		if ( $spacing === null ) {
			return null;
		}

		return $negative ? $this->negate( $spacing ) : $spacing;
	}

	private function literal( string $token ): ?string {
		if ( str_starts_with( $token, '[' ) && str_ends_with( $token, ']' ) ) {
			$inner = Candidate::decode_arbitrary( substr( $token, 1, -1 ) );

			return $inner === '' ? null : $inner;
		}

		if ( str_starts_with( $token, '(' ) && str_ends_with( $token, ')' ) ) {
			$inner = Candidate::decode_arbitrary( substr( $token, 1, -1 ) );
			if ( $inner === '' ) {
				return null;
			}
			if ( str_starts_with( $inner, '--' ) ) {
				return 'var(' . $inner . ')';
			}

			return $inner;
		}

		return null;
	}

	private function negate( string $value ): string {
		if ( $value === '' || $value === 'auto' ) {
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
}

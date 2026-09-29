<?php
/**
 * Flexbox and CSS grid utilities.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler\Tailwind\Utilities;

use DXAI_UI\Compiler\Tailwind\Candidate;
use DXAI_UI\Compiler\Tailwind\Theme;
use DXAI_UI\Compiler\Tailwind\Utility_Module;

/**
 * Owns the flex and grid namespaces: `flex-*`, `basis-*`, `grow`/`shrink`,
 * `order-*`, `grid-cols-*`, `grid-rows-*`, `grid-flow-*`, `col-*`, `row-*`,
 * `auto-cols-*`, `auto-rows-*`, `gap-*` and every alignment utility
 * (`justify-*`, `items-*`, `content-*`, `self-*`, `place-*`).
 *
 * Bare `flex`, `inline-flex`, `grid` and `contents` are display utilities and
 * belong to the layout module, so they are deliberately absent here. The same
 * goes for the `content` property utilities such as `content-none`: only the
 * documented `align-content` keywords are claimed.
 */
final class Flexgrid implements Utility_Module {

	/**
	 * Utilities whose value is fixed, keyed by base class name.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const STATIC_RULES = array(
		// flex shorthand.
		'flex-auto'                 => array( 'flex' => '1 1 auto' ),
		'flex-initial'              => array( 'flex' => '0 1 auto' ),
		'flex-none'                 => array( 'flex' => 'none' ),

		// flex-direction.
		'flex-row'                  => array( 'flex-direction' => 'row' ),
		'flex-row-reverse'          => array( 'flex-direction' => 'row-reverse' ),
		'flex-col'                  => array( 'flex-direction' => 'column' ),
		'flex-col-reverse'          => array( 'flex-direction' => 'column-reverse' ),

		// flex-wrap.
		'flex-wrap'                 => array( 'flex-wrap' => 'wrap' ),
		'flex-wrap-reverse'         => array( 'flex-wrap' => 'wrap-reverse' ),
		'flex-nowrap'               => array( 'flex-wrap' => 'nowrap' ),

		// flex-grow / flex-shrink shorthands.
		'grow'                      => array( 'flex-grow' => '1' ),
		'shrink'                    => array( 'flex-shrink' => '1' ),

		// order keywords.
		'order-first'               => array( 'order' => 'calc(-infinity)' ),
		'order-last'                => array( 'order' => 'calc(infinity)' ),
		'order-none'                => array( 'order' => '0' ),

		// grid-auto-flow.
		'grid-flow-row'             => array( 'grid-auto-flow' => 'row' ),
		'grid-flow-col'             => array( 'grid-auto-flow' => 'column' ),
		'grid-flow-dense'           => array( 'grid-auto-flow' => 'dense' ),
		'grid-flow-row-dense'       => array( 'grid-auto-flow' => 'row dense' ),
		'grid-flow-col-dense'       => array( 'grid-auto-flow' => 'column dense' ),

		// justify-content.
		'justify-normal'            => array( 'justify-content' => 'normal' ),
		'justify-start'             => array( 'justify-content' => 'flex-start' ),
		'justify-end'               => array( 'justify-content' => 'flex-end' ),
		'justify-end-safe'          => array( 'justify-content' => 'safe flex-end' ),
		'justify-center'            => array( 'justify-content' => 'center' ),
		'justify-center-safe'       => array( 'justify-content' => 'safe center' ),
		'justify-between'           => array( 'justify-content' => 'space-between' ),
		'justify-around'            => array( 'justify-content' => 'space-around' ),
		'justify-evenly'            => array( 'justify-content' => 'space-evenly' ),
		'justify-baseline'          => array( 'justify-content' => 'baseline' ),
		'justify-stretch'           => array( 'justify-content' => 'stretch' ),

		// justify-items.
		'justify-items-normal'      => array( 'justify-items' => 'normal' ),
		'justify-items-start'       => array( 'justify-items' => 'start' ),
		'justify-items-end'         => array( 'justify-items' => 'end' ),
		'justify-items-end-safe'    => array( 'justify-items' => 'safe end' ),
		'justify-items-center'      => array( 'justify-items' => 'center' ),
		'justify-items-center-safe' => array( 'justify-items' => 'safe center' ),
		'justify-items-stretch'     => array( 'justify-items' => 'stretch' ),

		// justify-self.
		'justify-self-auto'         => array( 'justify-self' => 'auto' ),
		'justify-self-start'        => array( 'justify-self' => 'start' ),
		'justify-self-end'          => array( 'justify-self' => 'end' ),
		'justify-self-end-safe'     => array( 'justify-self' => 'safe end' ),
		'justify-self-center'       => array( 'justify-self' => 'center' ),
		'justify-self-center-safe'  => array( 'justify-self' => 'safe center' ),
		'justify-self-stretch'      => array( 'justify-self' => 'stretch' ),

		// align-items.
		'items-start'               => array( 'align-items' => 'flex-start' ),
		'items-end'                 => array( 'align-items' => 'flex-end' ),
		'items-end-safe'            => array( 'align-items' => 'safe flex-end' ),
		'items-center'              => array( 'align-items' => 'center' ),
		'items-center-safe'         => array( 'align-items' => 'safe center' ),
		'items-baseline'            => array( 'align-items' => 'baseline' ),
		'items-baseline-last'       => array( 'align-items' => 'last baseline' ),
		'items-stretch'             => array( 'align-items' => 'stretch' ),

		// align-content.
		'content-normal'            => array( 'align-content' => 'normal' ),
		'content-center'            => array( 'align-content' => 'center' ),
		'content-center-safe'       => array( 'align-content' => 'safe center' ),
		'content-start'             => array( 'align-content' => 'flex-start' ),
		'content-end'               => array( 'align-content' => 'flex-end' ),
		'content-end-safe'          => array( 'align-content' => 'safe flex-end' ),
		'content-between'           => array( 'align-content' => 'space-between' ),
		'content-around'            => array( 'align-content' => 'space-around' ),
		'content-evenly'            => array( 'align-content' => 'space-evenly' ),
		'content-baseline'          => array( 'align-content' => 'baseline' ),
		'content-stretch'           => array( 'align-content' => 'stretch' ),

		// align-self.
		'self-auto'                 => array( 'align-self' => 'auto' ),
		'self-start'                => array( 'align-self' => 'flex-start' ),
		'self-end'                  => array( 'align-self' => 'flex-end' ),
		'self-end-safe'             => array( 'align-self' => 'safe flex-end' ),
		'self-center'               => array( 'align-self' => 'center' ),
		'self-center-safe'          => array( 'align-self' => 'safe center' ),
		'self-stretch'              => array( 'align-self' => 'stretch' ),
		'self-baseline'             => array( 'align-self' => 'baseline' ),
		'self-baseline-last'        => array( 'align-self' => 'last baseline' ),

		// place-content.
		'place-content-center'      => array( 'place-content' => 'center' ),
		'place-content-center-safe' => array( 'place-content' => 'safe center' ),
		'place-content-start'       => array( 'place-content' => 'start' ),
		'place-content-end'         => array( 'place-content' => 'end' ),
		'place-content-end-safe'    => array( 'place-content' => 'safe end' ),
		'place-content-between'     => array( 'place-content' => 'space-between' ),
		'place-content-around'      => array( 'place-content' => 'space-around' ),
		'place-content-evenly'      => array( 'place-content' => 'space-evenly' ),
		'place-content-baseline'    => array( 'place-content' => 'baseline' ),
		'place-content-stretch'     => array( 'place-content' => 'stretch' ),

		// place-items.
		'place-items-start'         => array( 'place-items' => 'start' ),
		'place-items-end'           => array( 'place-items' => 'end' ),
		'place-items-end-safe'      => array( 'place-items' => 'safe end' ),
		'place-items-center'        => array( 'place-items' => 'center' ),
		'place-items-center-safe'   => array( 'place-items' => 'safe center' ),
		'place-items-baseline'      => array( 'place-items' => 'baseline' ),
		'place-items-stretch'       => array( 'place-items' => 'stretch' ),

		// place-self.
		'place-self-auto'           => array( 'place-self' => 'auto' ),
		'place-self-start'          => array( 'place-self' => 'start' ),
		'place-self-end'            => array( 'place-self' => 'end' ),
		'place-self-end-safe'       => array( 'place-self' => 'safe end' ),
		'place-self-center'         => array( 'place-self' => 'center' ),
		'place-self-center-safe'    => array( 'place-self' => 'safe center' ),
		'place-self-stretch'        => array( 'place-self' => 'stretch' ),
	);

	/**
	 * Keyword tracks shared by `auto-cols-*` and `auto-rows-*`.
	 *
	 * @var array<string, string>
	 */
	private const AUTO_TRACKS = array(
		'auto' => 'auto',
		'min'  => 'min-content',
		'max'  => 'max-content',
		'fr'   => 'minmax(0, 1fr)',
	);

	/**
	 * @return array<string, string>|null
	 */
	public function resolve( Candidate $candidate, Theme $theme ): ?array {
		$base = $candidate->base;

		if ( isset( self::STATIC_RULES[ $base ] ) ) {
			if ( $candidate->negative || $candidate->modifier !== '' ) {
				return null;
			}

			return self::STATIC_RULES[ $base ];
		}

		if ( str_starts_with( $base, 'flex-' ) ) {
			return $this->flex( substr( $base, 5 ), $candidate );
		}

		if ( str_starts_with( $base, 'basis-' ) ) {
			return $this->basis( substr( $base, 6 ), $candidate, $theme );
		}

		if ( str_starts_with( $base, 'grow-' ) ) {
			return $this->factor( 'flex-grow', substr( $base, 5 ), $candidate );
		}

		if ( str_starts_with( $base, 'shrink-' ) ) {
			return $this->factor( 'flex-shrink', substr( $base, 7 ), $candidate );
		}

		if ( str_starts_with( $base, 'order-' ) ) {
			return $this->order( substr( $base, 6 ), $candidate, $theme );
		}

		if ( str_starts_with( $base, 'grid-cols-' ) ) {
			return $this->template( 'grid-template-columns', substr( $base, 10 ), $candidate );
		}

		if ( str_starts_with( $base, 'grid-rows-' ) ) {
			return $this->template( 'grid-template-rows', substr( $base, 10 ), $candidate );
		}

		if ( str_starts_with( $base, 'auto-cols-' ) ) {
			return $this->auto_track( 'grid-auto-columns', substr( $base, 10 ), $candidate );
		}

		if ( str_starts_with( $base, 'auto-rows-' ) ) {
			return $this->auto_track( 'grid-auto-rows', substr( $base, 10 ), $candidate );
		}

		if ( $base === 'col-auto' ) {
			return $candidate->negative || $candidate->modifier !== '' ? null : array( 'grid-column' => 'auto' );
		}

		if ( $base === 'row-auto' ) {
			return $candidate->negative || $candidate->modifier !== '' ? null : array( 'grid-row' => 'auto' );
		}

		if ( str_starts_with( $base, 'col-' ) ) {
			return $this->line( 'grid-column', substr( $base, 4 ), $candidate );
		}

		if ( str_starts_with( $base, 'row-' ) ) {
			return $this->line( 'grid-row', substr( $base, 4 ), $candidate );
		}

		if ( str_starts_with( $base, 'gap-' ) ) {
			return $this->gap( substr( $base, 4 ), $candidate, $theme );
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
	 * `flex-<number>`, `flex-<fraction>` and `flex-[...]`.
	 *
	 * @return array<string, string>|null
	 */
	private function flex( string $token, Candidate $candidate ): ?array {
		if ( $candidate->negative ) {
			return null;
		}

		$arbitrary = self::raw_value( $token );
		if ( $arbitrary !== null ) {
			return $candidate->modifier === '' ? array( 'flex' => $arbitrary ) : null;
		}

		if ( ! self::is_count( $token ) ) {
			return null;
		}

		if ( $candidate->modifier !== '' ) {
			$fraction = self::fraction( $token, $candidate->modifier );

			return $fraction === null ? null : array( 'flex' => $fraction );
		}

		return array( 'flex' => $token );
	}

	/**
	 * `basis-<spacing|fraction|container>`, `basis-auto`, `basis-full`, `basis-[...]`.
	 *
	 * @return array<string, string>|null
	 */
	private function basis( string $token, Candidate $candidate, Theme $theme ): ?array {
		if ( $candidate->negative ) {
			return null;
		}

		$arbitrary = self::raw_value( $token );
		if ( $arbitrary !== null ) {
			return $candidate->modifier === '' ? array( 'flex-basis' => $arbitrary ) : null;
		}

		if ( $candidate->modifier !== '' ) {
			$fraction = self::fraction( $token, $candidate->modifier );

			return $fraction === null ? null : array( 'flex-basis' => $fraction );
		}

		if ( $token === 'auto' ) {
			return array( 'flex-basis' => 'auto' );
		}

		if ( $token === 'full' ) {
			return array( 'flex-basis' => '100%' );
		}

		$spacing = self::spacing( $token, $theme );
		if ( $spacing !== null ) {
			return array( 'flex-basis' => $spacing );
		}

		$container = $theme->value( 'container', $token );
		if ( $container !== null && $container !== '' ) {
			return array( 'flex-basis' => $container );
		}

		return null;
	}

	/**
	 * `grow-<number>` / `shrink-<number>` and their arbitrary forms.
	 *
	 * @return array<string, string>|null
	 */
	private function factor( string $property, string $token, Candidate $candidate ): ?array {
		if ( $candidate->negative || $candidate->modifier !== '' ) {
			return null;
		}

		$arbitrary = self::raw_value( $token );
		if ( $arbitrary !== null ) {
			return array( $property => $arbitrary );
		}

		return self::is_count( $token ) ? array( $property => $token ) : null;
	}

	/**
	 * `order-<number>`, `-order-<number>` and `order-[...]`.
	 *
	 * @return array<string, string>|null
	 */
	private function order( string $token, Candidate $candidate, Theme $theme ): ?array {
		if ( $candidate->modifier !== '' ) {
			return null;
		}

		$value = self::raw_value( $token );

		if ( $value === null ) {
			$themed = $theme->value( 'order', $token );
			if ( $themed !== null && $themed !== '' ) {
				$value = $themed;
			} elseif ( self::is_count( $token ) ) {
				$value = $token;
			}
		}

		if ( $value === null ) {
			return null;
		}

		return array( 'order' => $candidate->negative ? self::negate( $value ) : $value );
	}

	/**
	 * `grid-cols-*` and `grid-rows-*`.
	 *
	 * @return array<string, string>|null
	 */
	private function template( string $property, string $token, Candidate $candidate ): ?array {
		if ( $candidate->negative || $candidate->modifier !== '' ) {
			return null;
		}

		$arbitrary = self::raw_value( $token );
		if ( $arbitrary !== null ) {
			return array( $property => $arbitrary );
		}

		if ( $token === 'none' || $token === 'subgrid' ) {
			return array( $property => $token );
		}

		if ( ! self::is_count( $token ) || (int) $token < 1 ) {
			return null;
		}

		return array( $property => 'repeat(' . $token . ', minmax(0, 1fr))' );
	}

	/**
	 * `auto-cols-*` and `auto-rows-*`.
	 *
	 * @return array<string, string>|null
	 */
	private function auto_track( string $property, string $token, Candidate $candidate ): ?array {
		if ( $candidate->negative || $candidate->modifier !== '' ) {
			return null;
		}

		$arbitrary = self::raw_value( $token );
		if ( $arbitrary !== null ) {
			return array( $property => $arbitrary );
		}

		return isset( self::AUTO_TRACKS[ $token ] ) ? array( $property => self::AUTO_TRACKS[ $token ] ) : null;
	}

	/**
	 * Grid placement: `col-span-*`, `col-start-*`, `col-end-*`, `col-<number>`
	 * and the matching `row-*` utilities.
	 *
	 * @return array<string, string>|null
	 */
	private function line( string $shorthand, string $token, Candidate $candidate ): ?array {
		if ( $candidate->modifier !== '' ) {
			return null;
		}

		if ( str_starts_with( $token, 'span-' ) ) {
			if ( $candidate->negative ) {
				return null;
			}

			$span = substr( $token, 5 );
			if ( $span === 'full' ) {
				return array( $shorthand => '1 / -1' );
			}

			$value = self::raw_value( $span );
			if ( $value === null ) {
				if ( ! self::is_count( $span ) ) {
					return null;
				}

				$value = $span;
			}

			return array( $shorthand => 'span ' . $value . ' / span ' . $value );
		}

		foreach ( array( 'start-' => '-start', 'end-' => '-end' ) as $prefix => $suffix ) {
			if ( ! str_starts_with( $token, $prefix ) ) {
				continue;
			}

			$rest     = substr( $token, strlen( $prefix ) );
			$property = $shorthand . $suffix;

			if ( $rest === 'auto' ) {
				return $candidate->negative ? null : array( $property => 'auto' );
			}

			$value = self::raw_value( $rest );
			if ( $value === null ) {
				if ( ! self::is_count( $rest ) ) {
					return null;
				}

				$value = $rest;
			}

			return array( $property => $candidate->negative ? self::negate( $value ) : $value );
		}

		$value = self::raw_value( $token );
		if ( $value === null ) {
			if ( ! self::is_count( $token ) ) {
				return null;
			}

			$value = $token;
		}

		return array( $shorthand => $candidate->negative ? self::negate( $value ) : $value );
	}

	/**
	 * `gap-*`, `gap-x-*` and `gap-y-*`.
	 *
	 * @return array<string, string>|null
	 */
	private function gap( string $token, Candidate $candidate, Theme $theme ): ?array {
		if ( $candidate->negative || $candidate->modifier !== '' ) {
			return null;
		}

		$property = 'gap';
		if ( str_starts_with( $token, 'x-' ) ) {
			$property = 'column-gap';
			$token    = substr( $token, 2 );
		} elseif ( str_starts_with( $token, 'y-' ) ) {
			$property = 'row-gap';
			$token    = substr( $token, 2 );
		}

		$arbitrary = self::raw_value( $token );
		if ( $arbitrary !== null ) {
			return array( $property => $arbitrary );
		}

		$spacing = self::spacing( $token, $theme );

		return $spacing === null ? null : array( $property => $spacing );
	}

	/**
	 * Value written as `[...]`, or with v4's `(--var)` shorthand. Null when the
	 * token carries no explicit value.
	 */
	private static function raw_value( string $token ): ?string {
		if ( str_starts_with( $token, '[' ) && str_ends_with( $token, ']' ) ) {
			$value = Candidate::decode_arbitrary( substr( $token, 1, -1 ) );

			// Drop a leading data-type hint such as `length:` or `number:`.
			if ( 1 === preg_match( '/^(?:length|number|percentage|integer|any):(.*)$/s', $value, $match ) ) {
				$value = trim( $match[1] );
			}

			if ( str_starts_with( $value, '--' ) ) {
				return 'var(' . $value . ')';
			}

			return $value === '' ? null : $value;
		}

		if ( str_starts_with( $token, '(' ) && str_ends_with( $token, ')' ) ) {
			$inner = Candidate::decode_arbitrary( substr( $token, 1, -1 ) );

			return str_starts_with( $inner, '--' ) ? 'var(' . $inner . ')' : null;
		}

		return null;
	}

	/**
	 * True for a bare non-negative integer such as `3`.
	 */
	private static function is_count( string $token ): bool {
		return $token !== '' && ctype_digit( $token );
	}

	/**
	 * Resolve a spacing-scale token, refusing anything off the numeric scale so
	 * that a non-existent class like `gap-full` stays unclaimed.
	 */
	private static function spacing( string $token, Theme $theme ): ?string {
		if ( $token !== 'px' && 1 !== preg_match( '/^\d+(?:\.\d+)?$/', $token ) ) {
			return null;
		}

		$value = $theme->spacing( $token );

		return ( $value === null || $value === '' ) ? null : $value;
	}

	/**
	 * `basis-1/2` and `flex-1/2` resolve to a percentage of the container.
	 */
	private static function fraction( string $numerator, string $denominator ): ?string {
		$denominator = Candidate::decode_arbitrary( $denominator );
		if ( ! self::is_count( $numerator ) || ! self::is_count( $denominator ) || $denominator === '0' ) {
			return null;
		}

		return 'calc(' . $numerator . '/' . $denominator . ' * 100%)';
	}

	/**
	 * Flip the sign of a resolved value, falling back to calc() for anything
	 * that is not a plain number or length.
	 */
	private static function negate( string $value ): string {
		if ( 1 === preg_match( '/^-?(?:\d+(?:\.\d+)?|\.\d+)(?:[a-z%]+)?$/i', $value ) ) {
			return str_starts_with( $value, '-' ) ? substr( $value, 1 ) : '-' . $value;
		}

		return 'calc(' . $value . ' * -1)';
	}
}

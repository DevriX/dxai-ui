<?php
/**
 * A row or a centred container written as the Group block's own layout.
 *
 * @package DXAI_UI\Blocks\Native
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks\Native;

use DXAI_UI\Compiler\Utility_Classes;

/**
 * The theme's pages set a group's arrangement in the block's Layout panel — Flex (direction, justification, wrapping)
 * or Constrained (content width) — not in classes. A design's rows and containers were written as classes and
 * CSS: `d-flex items-center justify-between gap-6`, `mx-auto max-w-1280px px-7`. Where the layout panel says exactly
 * the same thing, the group gets the panel's setting and loses those classes and declarations, so it is edited in
 * the Layout panel like every other group of the site.
 *
 * "Exactly the same" is checked, not assumed. WordPress writes more than the design did around a layout:
 *  - a Flex group zeroes its children's margins, a Constrained group zeroes their vertical margins and centres them
 *    with `margin: auto !important` and caps their width. A group whose children carry margins, a width of their
 *    own or a position stays as it is.
 *  - every value a layout leaves unset has a default of the theme (wrapping, alignment, the gap between items);
 *    they are all written out, from what the design said and its own defaults (no wrap, stretch, no gap).
 *  - a rule that changes at a breakpoint, or a container with a background, a border or a shadow, stays as it is.
 *
 * Only the classes and declarations the layout replaces are removed; everything else on the block stays.
 */
final class Layout extends Converter {

	/** What a Flex layout says. */
	private const FLEX = array( 'display', 'flex-direction', 'flex-wrap', 'align-items', 'justify-content', 'gap', 'row-gap', 'column-gap' );

	/** Properties of a flex or grid container that the layout panel cannot say. */
	private const FOREIGN = '/^(?:flex-flow|align-content|place-content|place-items|justify-items|grid-[a-z-]+|column-count|columns)$/';

	/** What a Constrained container may carry besides its width: nothing that paints or clips. */
	private const PLAIN = '/^(?:margin(?:-[a-z]+)?|padding(?:-[a-z]+)?|max-width|width|position|z-index|box-sizing|color|font(?:-[a-z]+)?|line-height|letter-spacing|text-[a-z-]+|word-spacing|white-space)$/';

	private const KNOWN = array( 'tagName', 'className', 'dxaiCss', 'anchor', 'ariaHidden', 'labelledBy', 'ariaLabel', 'role', 'dxaiData', 'metadata' );

	public function id(): string {
		return 'layout';
	}

	public function label(): string {
		return __( 'Rows and containers → Group layout', 'dxai-ui' );
	}

	public function source(): string {
		return 'core/group';
	}

	public function target(): string {
		return 'core/group';
	}

	public function available(): bool {
		return true;
	}

	public function convert( array $block, ?array $parent = null ): ?array {
		if ( ( $block['blockName'] ?? '' ) !== 'core/group' || ( $block['innerBlocks'] ?? array() ) === array() ) {
			return null;
		}
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		if ( isset( $attrs['layout'] ) || isset( $attrs['align'] ) || ! self::only( $attrs, self::KNOWN ) ) {
			return null;
		}
		$children = array_values( array_filter( (array) $block['innerBlocks'], 'is_array' ) );
		$plan     = self::flex( $attrs, $children );
		if ( $plan === null ) {
			$plan = self::constrained( $attrs, $children, $parent );
		}
		if ( $plan === null ) {
			return null;
		}
		$new = $attrs;
		unset( $new['className'], $new['dxaiCss'] );
		if ( $plan['classes'] !== '' ) {
			$new['className'] = $plan['classes'];
		}
		if ( $plan['css'] !== '' ) {
			$new['dxaiCss'] = $plan['css'];
		}
		$new['layout'] = $plan['layout'];
		$new['style']  = array( 'spacing' => array( 'blockGap' => $plan['gap'] ) );

		return Group::rewrite( $block, $attrs, $new );
	}

	/**
	 * A row of the design as a Flex layout.
	 *
	 * @param array<string, mixed>             $attrs
	 * @param array<int, array<string, mixed>> $children
	 * @return array{classes:string, css:string, layout:array<string, mixed>, gap:string|array<string, string>}|null
	 */
	private static function flex( array $attrs, array $children ): ?array {
		$read = self::read( $attrs, self::FLEX, '/^(?:d-|flex|items-|justify-|gap|content-|self-|grid|inline|hidden|block)/' );
		if ( $read === null || ( $read['props']['display'] ?? '' ) !== 'flex' || ! self::children_plain( $children, false ) ) {
			return null;
		}
		$p        = $read['props'];
		$vertical = ( $p['flex-direction'] ?? 'row' ) === 'column';
		if ( ! in_array( $p['flex-direction'] ?? 'row', array( 'row', 'column' ), true ) || ! in_array( $p['flex-wrap'] ?? 'nowrap', array( 'nowrap', 'wrap' ), true ) ) {
			return null;
		}
		$cross = array(
			'normal'     => 'stretch',
			'stretch'    => 'stretch',
			'flex-start' => 'start',
			'start'      => 'start',
			'center'     => 'center',
			'flex-end'   => 'end',
			'end'        => 'end',
		);
		$align = $cross[ $p['align-items'] ?? 'stretch' ] ?? null;
		$main  = null;
		if ( isset( $p['justify-content'] ) ) {
			$main = array(
				'normal'        => 'start',
				'flex-start'    => 'start',
				'start'         => 'start',
				'center'        => 'center',
				'flex-end'      => 'end',
				'end'           => 'end',
				'space-between' => 'space-between',
			)[ $p['justify-content'] ] ?? null;
			if ( $main === null ) {
				return null;
			}
		}
		if ( $align === null ) {
			return null;
		}
		$layout = array( 'type' => 'flex' );
		if ( $vertical ) {
			$layout['orientation']   = 'vertical';
			$layout['justifyContent'] = array( 'stretch' => 'stretch', 'start' => 'left', 'center' => 'center', 'end' => 'right' )[ $align ];
			if ( $main !== null && $main !== 'start' ) {
				$layout['verticalAlignment'] = array( 'center' => 'center', 'end' => 'bottom', 'space-between' => 'space-between' )[ $main ];
			} elseif ( $main === 'start' ) {
				$layout['verticalAlignment'] = 'top';
			}
		} else {
			$layout['orientation']       = 'horizontal';
			$layout['verticalAlignment'] = array( 'stretch' => 'stretch', 'start' => 'top', 'center' => 'center', 'end' => 'bottom' )[ $align ];
			if ( $main !== null ) {
				$layout['justifyContent'] = array( 'start' => 'left', 'center' => 'center', 'end' => 'right', 'space-between' => 'space-between' )[ $main ];
			}
		}
		$layout['flexWrap'] = ( $p['flex-wrap'] ?? 'nowrap' ) === 'wrap' ? 'wrap' : 'nowrap';
		$gap                = self::gap( $p );
		if ( $gap === null ) {
			return null;
		}

		return array(
			'classes' => $read['classes'],
			'css'     => $read['css'],
			'layout'  => $layout,
			'gap'     => $gap,
		);
	}

	/**
	 * A centred container of the design (`mx-auto max-w-1280px px-7`) as a Constrained layout: its children keep the
	 * width they had (the container's width less its side padding) and are centred by the layout.
	 *
	 * @param array<string, mixed>             $attrs
	 * @param array<int, array<string, mixed>> $children
	 * @param array<string, mixed>|null        $parent
	 * @return array{classes:string, css:string, layout:array<string, mixed>, gap:string|array<string, string>}|null
	 */
	private static function constrained( array $attrs, array $children, ?array $parent ): ?array {
		// A flex or grid parent sizes its items differently from a block parent.
		if ( $parent !== null && self::displays( $parent ) ) {
			return null;
		}
		$read = self::read( $attrs, array( 'max-width', 'margin-left', 'margin-right', 'margin-inline' ), '/^(?:d-|flex|grid|mx-|px-|pl-|pr-|max-w|w-|inline|hidden|block|container)/', true );
		if ( $read === null || self::displays( $attrs ) ) {
			return null;
		}
		$p = $read['props'];
		if ( ! isset( $p['max-width'] ) || preg_match( '/^([\d.]+)px$/', $p['max-width'], $m ) !== 1 ) {
			return null;
		}
		// Centred by auto margins on both sides, and nothing else about its sides.
		$auto = ( ( $p['margin-left'] ?? '' ) === 'auto' && ( $p['margin-right'] ?? '' ) === 'auto' ) || ( $p['margin-inline'] ?? '' ) === 'auto';
		if ( ! $auto ) {
			return null;
		}
		$pad = self::side_padding( $attrs );
		if ( $pad === null || ! self::children_plain( $children, true ) ) {
			return null;
		}
		$content = (float) $m[1] - $pad;
		if ( $content < 200 ) {
			return null;
		}

		return array(
			'classes' => $read['classes'],
			'css'     => $read['css'],
			'layout'  => array(
				'type'        => 'constrained',
				'contentSize' => rtrim( rtrim( number_format( $content, 2, '.', '' ), '0' ), '.' ) . 'px',
			),
			'gap'     => '0px',
		);
	}

	/**
	 * The block's classes and CSS without the ones that say $props, and the values they said.
	 *
	 * @param array<string, mixed> $attrs
	 * @param array<int, string>   $props   What the layout replaces.
	 * @param string               $guard   A responsive variant of a class that begins like this makes the block ineligible.
	 * @param bool                 $plain   Whether every other class and declaration must be a plain one (constrained).
	 * @return array{classes:string, css:string, props:array<string, string>}|null
	 */
	private static function read( array $attrs, array $props, string $guard, bool $plain = false ): ?array {
		$keep   = array();
		$values = array();
		foreach ( preg_split( '/\s+/', trim( (string) ( $attrs['className'] ?? '' ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $token ) {
			if ( preg_match( '/^(?:xs|sm|md|lg|xl)-(.+)$/', $token, $r ) === 1 ) {
				if ( preg_match( $guard, $r[1] ) === 1 ) {
					return null;
				}
				$keep[] = $token;
				continue;
			}
			$decls = self::decls( Utility_Classes::base_declarations( $token ) );
			if ( $decls === array() ) {
				$keep[] = $token;
				continue;
			}
			$inside = true;
			foreach ( $decls as $d ) {
				if ( preg_match( self::FOREIGN, $d['prop'] ) === 1 ) {
					return null;
				}
				$inside = $inside && self::in( $d['prop'], $props );
			}
			if ( $inside ) {
				foreach ( $decls as $d ) {
					if ( isset( $values[ $d['prop'] ] ) && $values[ $d['prop'] ] !== $d['value'] ) {
						return null;
					}
					$values[ $d['prop'] ] = $d['value'];
				}
				continue;
			}
			if ( $plain && ! self::plain_only( $decls ) ) {
				return null;
			}
			foreach ( $decls as $d ) {
				if ( self::in( $d['prop'], $props ) ) {
					return null;
				}
			}
			$keep[] = $token;
		}
		$css = array();
		foreach ( Utility_Classes::declarations( (string) ( $attrs['dxaiCss'] ?? '' ) ) as $d ) {
			if ( $d['prop'] === '' || preg_match( self::FOREIGN, $d['prop'] ) === 1 ) {
				return null;
			}
			$value = strtolower( trim( $d['value'] ) );
			if ( self::in( $d['prop'], $props ) ) {
				if ( isset( $values[ $d['prop'] ] ) && $values[ $d['prop'] ] !== $value ) {
					return null;
				}
				$values[ $d['prop'] ] = $value;
				continue;
			}
			if ( $plain && ! self::plain_only( array( array( 'prop' => $d['prop'], 'value' => $value ) ) ) ) {
				return null;
			}
			$css[] = $d['raw'];
		}

		return array(
			'classes' => implode( ' ', $keep ),
			'css'     => implode( ';', $css ),
			'props'   => $values,
		);
	}

	/** Whether a property is one the layout replaces (a `margin-inline` for `margin-left`/`margin-right` too). */
	private static function in( string $prop, array $props ): bool {
		return in_array( $prop, $props, true );
	}

	/**
	 * @param array<int, array{prop:string, value:string}> $decls
	 */
	private static function plain_only( array $decls ): bool {
		foreach ( $decls as $d ) {
			if ( preg_match( self::PLAIN, $d['prop'] ) !== 1 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A class's declarations as prop/value pairs (values lower case, without !important).
	 *
	 * @return array<int, array{prop:string, value:string}>
	 */
	private static function decls( string $css ): array {
		$out = array();
		foreach ( Utility_Classes::declarations( $css ) as $d ) {
			if ( $d['prop'] === '' ) {
				return array();
			}
			$out[] = array(
				'prop'  => $d['prop'],
				'value' => strtolower( trim( $d['value'] ) ),
			);
		}

		return $out;
	}

	/** Whether the block sets a display of its own (a flex or grid container, or hidden). */
	private static function displays( array $attrs ): bool {
		foreach ( preg_split( '/\s+/', trim( (string) ( $attrs['className'] ?? '' ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $token ) {
			foreach ( self::decls( Utility_Classes::base_declarations( $token ) ) as $d ) {
				if ( $d['prop'] === 'display' && $d['value'] !== 'block' ) {
					return true;
				}
			}
		}

		return preg_match( '/(?:^|;)\s*display\s*:\s*(?!block\b)/i', (string) ( $attrs['dxaiCss'] ?? '' ) ) === 1;
	}

	/**
	 * The gap of a flex container: one length, or row and column; '0px' when the design has none.
	 *
	 * @param array<string, string> $p
	 * @return string|array<string, string>|null
	 */
	private static function gap( array $p ) {
		$row = '0px';
		$col = '0px';
		if ( isset( $p['gap'] ) ) {
			$parts = preg_split( '/\s+/', trim( $p['gap'] ) ) ?: array();
			if ( count( $parts ) === 1 ) {
				$row = $col = $parts[0];
			} elseif ( count( $parts ) === 2 ) {
				$row = $parts[0];
				$col = $parts[1];
			} else {
				return null;
			}
		}
		$row = $p['row-gap'] ?? $row;
		$col = $p['column-gap'] ?? $col;
		foreach ( array( $row, $col ) as $length ) {
			if ( preg_match( '/^(?:0|[\d.]+(?:px|rem|em))$/', $length ) !== 1 ) {
				return null;
			}
		}
		$row = $row === '0' ? '0px' : $row;
		$col = $col === '0' ? '0px' : $col;

		return $row === $col ? $row : array( 'top' => $row, 'left' => $col );
	}

	/** The container's side padding in px (its padding-left and padding-right, from classes and CSS); null when it is not plain px. */
	private static function side_padding( array $attrs ): ?float {
		$left  = 0.0;
		$right = 0.0;
		$take  = static function ( string $prop, string $value ) use ( &$left, &$right ): bool {
			$len = static function ( string $v ): ?float {
				return preg_match( '/^(-?[\d.]+)(px|rem|em)?$/', $v, $m ) === 1 ? (float) $m[1] * ( ( $m[2] ?? '' ) === 'px' || ( $m[2] ?? '' ) === '' ? 1 : 16 ) : null;
			};
			if ( $prop === 'padding-left' || $prop === 'padding-right' || $prop === 'padding-inline' || $prop === 'padding' ) {
				$parts = preg_split( '/\s+/', $value ) ?: array();
				if ( $prop === 'padding' ) {
					$n = count( $parts );
					$l = $n === 1 ? $parts[0] : ( $n === 2 || $n === 3 ? $parts[1] : ( $n === 4 ? $parts[3] : '' ) );
					$r = $n === 1 ? $parts[0] : ( $n >= 2 && $n <= 4 ? $parts[1] : '' );
					$lv = $len( $l );
					$rv = $len( $r );
				} else {
					$lv = $len( $parts[0] );
					$rv = $len( $parts[1] ?? $parts[0] );
				}
				if ( $lv === null || $rv === null ) {
					return false;
				}
				if ( $prop !== 'padding-right' ) {
					$left = $lv;
				}
				if ( $prop !== 'padding-left' ) {
					$right = $rv;
				}
			}

			return true;
		};
		foreach ( preg_split( '/\s+/', trim( (string) ( $attrs['className'] ?? '' ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $token ) {
			if ( preg_match( '/^(?:xs|sm|md|lg|xl)-(?:px|pl|pr|p)-/', $token ) === 1 ) {
				return null;
			}
			foreach ( self::decls( Utility_Classes::base_declarations( $token ) ) as $d ) {
				if ( ! $take( $d['prop'], $d['value'] ) ) {
					return null;
				}
			}
		}
		foreach ( Utility_Classes::declarations( (string) ( $attrs['dxaiCss'] ?? '' ) ) as $d ) {
			if ( ! $take( $d['prop'], strtolower( trim( $d['value'] ) ) ) ) {
				return null;
			}
		}

		return $left + $right;
	}

	/**
	 * Whether the direct children of a group are ones WordPress's layout leaves as they are: no margin of their own
	 * (a flex layout zeroes them, a constrained one their vertical margins and centres the rest), no width of their
	 * own and no position. Blocks this cannot read (raw HTML, other plugins' blocks) make it false.
	 *
	 * @param array<int, array<string, mixed>> $children
	 */
	private static function children_plain( array $children, bool $sized ): bool {
		foreach ( $children as $child ) {
			$name = (string) ( $child['blockName'] ?? '' );
			if ( $name === '' ) {
				continue;
			}
			if ( ! str_starts_with( $name, 'core/' ) && ! str_starts_with( $name, 'dxai-ui/' ) && ! str_starts_with( $name, 'amr/' ) ) {
				return false;
			}
			if ( in_array( $name, array( 'core/html', 'core/shortcode', 'dxai-ui/html', 'dxai-ui/form' ), true ) ) {
				return false;
			}
			$attrs = is_array( $child['attrs'] ?? null ) ? $child['attrs'] : array();
			$decls = array();
			foreach ( preg_split( '/\s+/', trim( (string) ( $attrs['className'] ?? '' ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $token ) {
				if ( preg_match( '/^(?:xs|sm|md|lg|xl)-(?:m[trblxy]?-|w-|max-w|min-w|absolute|fixed|float|self-)/', $token ) === 1 ) {
					return false;
				}
				$decls = array_merge( $decls, self::decls( Utility_Classes::base_declarations( $token ) ) );
			}
			foreach ( Utility_Classes::declarations( (string) ( $attrs['dxaiCss'] ?? '' ) ) as $d ) {
				if ( $d['prop'] === '' ) {
					return false;
				}
				$decls[] = array(
					'prop'  => $d['prop'],
					'value' => strtolower( trim( $d['value'] ) ),
				);
			}
			// Layout the style panel may hold on the block itself (margins set in the Dimensions panel).
			if ( isset( $attrs['style']['spacing']['margin'] ) || isset( $attrs['align'] ) ) {
				return false;
			}
			foreach ( $decls as $d ) {
				if ( preg_match( '/^margin/', $d['prop'] ) === 1 && preg_match( '/^(?:0(?:px|rem|em)?\s*)+$/', $d['value'] ) !== 1 ) {
					return false;
				}
				if ( $d['prop'] === 'position' && in_array( $d['value'], array( 'absolute', 'fixed', 'sticky' ), true ) ) {
					return false;
				}
				if ( $d['prop'] === 'float' && $d['value'] !== 'none' ) {
					return false;
				}
				if ( $sized && in_array( $d['prop'], array( 'max-width', 'width', 'min-width' ), true ) && ! in_array( $d['value'], array( '100%', 'auto', 'none', '0' ), true ) ) {
					return false;
				}
			}
		}

		return true;
	}
}

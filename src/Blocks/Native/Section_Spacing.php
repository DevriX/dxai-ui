<?php
/**
 * The same space above and below the sections of a design's pages.
 *
 * @package DXAI_UI\Blocks\Native
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks\Native;

use DXAI_UI\Compiler\Utility_Classes;

/**
 * The sections of a page have the same padding above and below, as the Home's do: the padding most of the Home's sections have
 * (Style_Harmony). A design that puts 92px above one section and 130px above the next, or a page a model wrote with a few of its own,
 * comes out with one rhythm.
 *
 * The padding is the block's own (a `padding-top` / `padding-bottom` / `padding` in its Design CSS, or a `py-` / `pt-` / `pb-` class);
 * the sides of it are left as they are. A shorthand is written as its sides so only the top and the bottom change.
 *
 * Left alone: a page's first section when it holds the page's title (its hero), a section that sets padding on every side in one
 * class (`p-8`) or with a value this cannot tell (a variable, a percentage), one whose padding is much smaller or much larger than the
 * Home's (a thin bar, a hero: under 50% or over 160% of it), one with none of its own (its content is padded inside), one in the
 * header, the footer or the navigation, and a block whose stored tag is not what its attributes say. The new padding is kept within the
 * design system (Utility_Classes::fit_system()).
 */
final class Section_Spacing extends Style_Harmony {

	/** Below and above this share of the Home's padding a section is another kind (a thin bar, a hero), not one to even out. */
	private const BAND = array( 0.5, 1.6 );

	public function id(): string {
		return 'section_spacing';
	}

	public function label(): string {
		return __( 'The same spacing for the sections', 'dxai-ui' );
	}

	public function source(): string {
		return 'core/group';
	}

	public function target(): string {
		return 'core/group';
	}

	public function page( array $blocks ): ?array {
		$home = self::home();
		if ( $home < 1 ) {
			return null;
		}
		$dom = self::profile( $home );
		if ( $dom === null ) {
			return null;
		}
		$count = 0;
		$out   = self::map_sections(
			$blocks,
			static function ( array $block ) use ( $dom, &$count ): ?array {
				$sig = self::signature( $block );
				if ( $sig === null || $sig['key'] === $dom['key'] ) {
					return null;
				}
				if ( $sig['px'] < $dom['px'] * self::BAND[0] || $sig['px'] > $dom['px'] * self::BAND[1] ) {
					return null;
				}
				$rest = $sig['rest_css'];
				$own  = array();
				if ( $dom['top'] !== null ) {
					$own[] = 'padding-top:' . $dom['top'];
				}
				if ( $dom['bottom'] !== null ) {
					$own[] = 'padding-bottom:' . $dom['bottom'];
				}
				$class = implode( ' ', array_merge( $sig['rest_class'], $dom['mine_class'] ) );
				$css   = Utility_Classes::fit_system( implode( ';', array_merge( $rest, $own ) ) );
				$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
				if ( $class === trim( (string) ( $attrs['className'] ?? '' ) ) && $css === trim( (string) ( $attrs['dxaiCss'] ?? '' ) ) ) {
					return null;
				}
				$new = self::restyle( $block, $class, $css );
				if ( $new === null ) {
					return null;
				}
				++$count;

				return $new;
			}
		);

		return $count > 0 ? array(
			'blocks' => $out,
			'count'  => $count,
		) : null;
	}

	/**
	 * The padding most of a Home's sections have.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<string, mixed>|null
	 */
	protected static function profile_of( array $blocks ): ?array {
		$found = array();
		self::map_sections(
			$blocks,
			static function ( array $block ) use ( &$found ): ?array {
				$sig = self::signature( $block );
				if ( $sig !== null ) {
					$found[] = $sig;
				}

				return null;
			}
		);

		$dom = self::dominant( $found );
		if ( $dom !== null || count( $found ) < self::MIN_EVIDENCE ) {
			return $dom;
		}
		// No padding is used twice (a design that sets each section's by hand): the one in the middle is the Home's rhythm, the
		// sections above and below it are the ones that are bigger and smaller than most.
		usort( $found, static fn( array $a, array $b ): int => $a['px'] <=> $b['px'] );
		$mid = $found[ intdiv( count( $found ), 2 ) ];

		return $mid + array(
			'count' => 1,
			'of'    => count( $found ),
		);
	}

	/** Whether a class name sets the padding above or below (`py-24`, `pt-8`, `md:pb-12`, `sm-py-48`). */
	private static function is_vertical( string $token ): bool {
		return preg_match( '/^(?:py|pt|pb)-\S+$/', self::base( $token ) ) === 1;
	}

	/** A value list at its top level: `clamp(1px, 2vw, 3px) 4px` is two. @return array<int, string> */
	private static function values( string $value ): array {
		$out   = array();
		$depth = 0;
		$from  = 0;
		for ( $i = 0, $n = strlen( $value ); $i <= $n; $i++ ) {
			$ch = $i < $n ? $value[ $i ] : ' ';
			if ( $ch === '(' ) {
				++$depth;
			} elseif ( $ch === ')' ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( ( $ch === ' ' || $ch === "\t" ) && $depth === 0 ) {
				$piece = trim( substr( $value, $from, $i - $from ) );
				if ( $piece !== '' ) {
					$out[] = $piece;
				}
				$from = $i + 1;
			}
		}

		return $out;
	}

	/** The padding in px a `py-` / `pt-` / `pb-` class says (the spacing scale: a step is a quarter of a rem, `-5` a half step), or null. */
	private static function class_padding( string $token ): ?float {
		if ( preg_match( '/^(?:py|pt|pb)-(\d+)(?:-(\d+))?$/', self::base( $token ), $m ) === 1 ) {
			return ( (float) ( $m[1] . ( isset( $m[2] ) && $m[2] !== '' ? '.' . $m[2] : '' ) ) ) * 4.0;
		}

		return null;
	}

	/**
	 * What a section says about its padding above and below, or null for one these passes leave alone (see the class comment).
	 *
	 * @param array<string, mixed> $block
	 * @return array{key:string, mine_class:array<int, string>, rest_class:array<int, string>, rest_css:array<int, string>, top:?string, bottom:?string, px:float}|null
	 */
	private static function signature( array $block ): ?array {
		if ( ( $block['blockName'] ?? '' ) !== 'core/group' || self::holds_h1( $block ) ) {
			return null;
		}
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		if ( preg_match( '/hero|header|footer|nav/i', (string) ( $attrs['metadata']['name'] ?? '' ) ) === 1 ) {
			return null;
		}
		$own  = array();
		$rest = array();
		foreach ( self::tokens( (string) ( $attrs['className'] ?? '' ) ) as $token ) {
			if ( self::is_vertical( $token ) ) {
				$own[] = $token;
			} elseif ( preg_match( '/^p-\S+$/', self::base( $token ) ) === 1 ) {
				return null;
			} else {
				$rest[] = $token;
			}
		}
		$top    = null;
		$bottom = null;
		$css    = array();
		foreach ( Utility_Classes::declarations( (string) ( $attrs['dxaiCss'] ?? '' ) ) as $d ) {
			$prop = $d['prop'];
			if ( $prop === '' || ! str_starts_with( $prop, 'padding' ) ) {
				$css[] = $d['raw'];
				continue;
			}
			if ( $d['important'] ) {
				return null;
			}
			$v = self::values( $d['value'] );
			if ( $prop === 'padding-top' && count( $v ) === 1 ) {
				$top = $v[0];
			} elseif ( $prop === 'padding-bottom' && count( $v ) === 1 ) {
				$bottom = $v[0];
			} elseif ( $prop === 'padding-block' && ( count( $v ) === 1 || count( $v ) === 2 ) ) {
				$top    = $v[0];
				$bottom = $v[1] ?? $v[0];
			} elseif ( $prop === 'padding' && count( $v ) >= 1 && count( $v ) <= 4 ) {
				// The shorthand as its sides: the top and the bottom are this pass's, the left and the right stay.
				$sides  = array( $v[0], $v[1] ?? $v[0], $v[2] ?? $v[0], $v[3] ?? ( $v[1] ?? $v[0] ) );
				$top    = $sides[0];
				$bottom = $sides[2];
				$css[]  = 'padding-right:' . $sides[1];
				$css[]  = 'padding-left:' . $sides[3];
			} elseif ( in_array( $prop, array( 'padding-left', 'padding-right', 'padding-inline' ), true ) ) {
				$css[] = $d['raw'];
			} else {
				return null;
			}
		}
		if ( $own === array() && $top === null && $bottom === null ) {
			return null;
		}
		$above = array();
		$below = array();
		foreach ( $own as $token ) {
			$size = self::class_padding( $token );
			if ( $size === null ) {
				return null;
			}
			if ( preg_match( '/^(?:py|pt)-/', self::base( $token ) ) === 1 ) {
				$above[] = $size;
			}
			if ( preg_match( '/^(?:py|pb)-/', self::base( $token ) ) === 1 ) {
				$below[] = $size;
			}
		}
		foreach ( array( 'top' => $top, 'bottom' => $bottom ) as $side => $value ) {
			if ( $value === null ) {
				continue;
			}
			$size = self::px( $value );
			if ( $size === null ) {
				return null;
			}
			if ( $side === 'top' ) {
				$above[] = $size;
			} else {
				$below[] = $size;
			}
		}
		$px = ( $above === array() ? 0.0 : max( $above ) ) + ( $below === array() ? 0.0 : max( $below ) );
		if ( $px <= 0.0 ) {
			return null;
		}
		$key = $own;
		sort( $key );

		return array(
			'key'        => implode( ' ', $key ) . '|' . (string) $top . '/' . (string) $bottom,
			'mine_class' => $own,
			'rest_class' => $rest,
			'rest_css'   => $css,
			'top'        => $top,
			'bottom'     => $bottom,
			'px'         => $px,
		);
	}
}

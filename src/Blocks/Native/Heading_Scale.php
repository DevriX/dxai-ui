<?php
/**
 * The same style for the same kind of heading across a design's pages.
 *
 * @package DXAI_UI\Blocks\Native
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks\Native;

/**
 * Every title of a section (an H2) has the same size, weight, line height, spacing of its letters and font, on every page of the
 * design — and a page's own title (its H1) the same: the style most of the Home's have (Style_Harmony). A design that sets a title
 * at 46px in one section, 40px in the next and 44px on another page, or a page a model wrote with one more size of its own, comes out
 * with one.
 *
 * What is the heading's typography is read from what the block itself carries: the Design CSS declarations that are typography
 * (`font-size`, `font-weight`, `line-height`, `letter-spacing`, `text-transform`, `font-family`…), and the classes that are — a size
 * (`text-48`, `text-3xl`, `text-[44px]`), a weight (`fw-800`, `font-bold`), a line height (`lh-1-1`, `leading-tight`), a tracking, a
 * case — and the design's own style classes (`display display-lg`, `rv-h2`), which are what a design that styles its titles by class
 * is made of. Spacing, layout, colour and animation classes stay as the block has them. Both are replaced by the Home's.
 *
 * Left alone: a heading someone set in the block's Typography panel (a font size, a font), one in the header, the footer or the
 * navigation, a small heading used as a label (18px and under: the eyebrow above a title), one far from the Home's size (under 60% or
 * over 160%: a card's title written as an H2), the headings of a level the Home has too few of to say, and a block whose stored tag is
 * not what its attributes say (edited by hand). H3 and under are not touched: a card's title, a step's, a footer's column's are each
 * their own kind of heading, and the Home sets them apart on purpose.
 */
final class Heading_Scale extends Style_Harmony {

	/** A size in px or less is a label, not a title. */
	private const LABEL = 18.0;

	/** What the typography of a block is: class names for a size, a weight, a line height, a tracking, a case, a font. */
	private const TYPE_TOKEN = '/^(?:text-(?:xs|sm|base|lg|xl|[2-9]xl)|text-\d+(?:-\d+)?|text-\[[^\]]*(?:px|rem|em|vw|vh|%|clamp|calc)[^\]]*\]|fw-\d+|font-(?:thin|extralight|light|normal|medium|semibold|bold|extrabold|black)|lh-[\w-]+|leading-[\w.\[\]%-]+|ls-[\w-]+|tracking-[\w.\[\]-]+|uppercase|lowercase|capitalize|normal-case|italic|not-italic|font-(?:sans|serif|mono|display|heading|body)|text-balance|text-pretty|has-[\w-]+-font-size)$/';

	/** What it is not: spacing, layout, colour, effects and the classes the system writes. Anything else is a style class of the design's own. */
	private const OTHER_TOKEN = '/^(?:-?(?:m|p)[trblxyse]?-\S+|space-[xy]-\S+|gap(?:-[xy])?-\S+|(?:w|h|min-w|min-h|max-w|max-h|size|basis|grow|shrink|flex|order|col|row|grid|aspect|columns|inset|top|right|bottom|left|start|end|z|overflow|overscroll|object|float|clear|isolate)(?:-\S+)?|items-\S+|justify-\S+|content-\S+|self-\S+|place-\S+|d-\S+|block|inline|inline-block|inline-flex|flex-inline|hidden|contents|table|static|fixed|absolute|relative|sticky|(?:bg|border|rounded|shadow|ring|outline|opacity|blur|backdrop|fill|stroke|from|via|to|accent|caret|decoration|transition|duration|delay|ease|animate|transform|translate|rotate|scale|skew|origin|cursor|select|pointer-events|list|align|whitespace|break|truncate|underline|no-underline|line-through|divide|sr-only|not-sr-only|will-change|mix-blend|bg-blend)(?:-\S+)?|text-\S+|reveal|is-\S+|has-\S+|wp-\S+|align\S*|dxai-\S+|dxs-\S+|sc-\S+|mobile-\S+|desktop-\S+|container|lazy|loaded)$/';

	public function id(): string {
		return 'heading_scale';
	}

	public function label(): string {
		return __( 'The same style for the same kind of heading', 'dxai-ui' );
	}

	public function source(): string {
		return 'core/heading';
	}

	public function target(): string {
		return 'core/heading';
	}

	public function page( array $blocks ): ?array {
		$home = self::home();
		if ( $home < 1 ) {
			return null;
		}
		$profile = self::profile( $home );
		if ( $profile === null ) {
			return null;
		}
		$count = 0;
		$out   = self::map_blocks(
			$blocks,
			static function ( array $block ) use ( $profile, &$count ): ?array {
				$sig = self::signature( $block );
				if ( $sig === null || self::label_like( $sig['px'] ) ) {
					return null;
				}
				$dom = $profile[ $sig['level'] ] ?? null;
				if ( $dom === null || $dom['key'] === $sig['key'] ) {
					return null;
				}
				// Another kind of heading: a card's title written as an H2 is a quarter or a half of a section's.
				if ( $sig['px'] !== null && $dom['px'] !== null && ( $sig['px'] < $dom['px'] * 0.6 || $sig['px'] > $dom['px'] * 1.6 ) ) {
					return null;
				}
				$class = implode( ' ', array_merge( $sig['rest_class'], $dom['mine_class'] ) );
				$css   = implode( ';', array_merge( $sig['rest_css'], $dom['mine_css'] ) );
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
	 * The style most of a Home's titles have, for its page title (H1) and for its section titles (H2).
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>|null>|null
	 */
	protected static function profile_of( array $blocks ): ?array {
		$found = array(
			1 => array(),
			2 => array(),
		);
		self::map_blocks(
			$blocks,
			static function ( array $block ) use ( &$found ): ?array {
				$sig = self::signature( $block );
				if ( $sig !== null && ! self::label_like( $sig['px'] ) ) {
					$found[ $sig['level'] ][] = $sig;
				}

				return null;
			}
		);
		// A Home has one page title: it is the one every page's is made like. Section titles need a few to say what most of them are.
		$out = array(
			1 => self::dominant( $found[1], 1, true ),
			2 => self::dominant( $found[2] ),
		);

		return $out[1] === null && $out[2] === null ? null : $out;
	}

	/** Whether a size says a label (an eyebrow set as a heading), not a title. */
	private static function label_like( ?float $px ): bool {
		return $px !== null && $px <= self::LABEL;
	}

	/** Whether a class name is typography (see the class comment). */
	private static function is_type( string $token ): bool {
		$base = self::base( $token );
		// A state's own variant (hover:, focus:, dark:) is not what the title is.
		if ( str_contains( $base, ':' ) ) {
			return false;
		}
		if ( preg_match( self::TYPE_TOKEN, $base ) === 1 ) {
			return true;
		}
		if ( preg_match( self::OTHER_TOKEN, $base ) === 1 ) {
			return false;
		}

		// A class of the design's own: how a design that styles its titles by class says what a title is.
		return true;
	}

	/**
	 * What a heading block says about its typography, or null for one these passes leave alone.
	 *
	 * @param array<string, mixed> $block
	 * @return array{level:int, key:string, mine_class:array<int, string>, rest_class:array<int, string>, mine_css:array<int, string>, rest_css:array<int, string>, px:?float}|null
	 */
	private static function signature( array $block ): ?array {
		if ( ( $block['blockName'] ?? '' ) !== 'core/heading' ) {
			return null;
		}
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$level = (int) ( $attrs['level'] ?? 2 );
		// Set by hand in the Typography panel: the person's.
		if ( ( $level !== 1 && $level !== 2 ) || isset( $attrs['fontSize'] ) || isset( $attrs['fontFamily'] ) || isset( $attrs['style']['typography'] ) ) {
			return null;
		}
		$class = self::split_class( (string) ( $attrs['className'] ?? '' ), static fn( string $t ): bool => self::is_type( $t ) );
		$css   = self::split_css( (string) ( $attrs['dxaiCss'] ?? '' ), static fn( string $p ): bool => in_array( $p, self::TYPE_PROPS, true ) );
		$sizes = array();
		foreach ( $class['mine'] as $token ) {
			$size = self::class_size( $token );
			if ( $size !== null ) {
				$sizes[] = $size;
			}
		}
		foreach ( \DXAI_UI\Compiler\Utility_Classes::declarations( implode( ';', $css['mine'] ) ) as $d ) {
			if ( $d['prop'] === 'font-size' ) {
				$size = self::px( $d['value'] );
				if ( $size !== null ) {
					$sizes[] = $size;
				}
			}
		}
		$key_class = $class['mine'];
		$key_css   = array_map( static fn( string $d ): string => (string) preg_replace( '/\s+/', ' ', $d ), $css['mine'] );
		sort( $key_class );
		sort( $key_css );

		return array(
			'level'      => $level,
			'key'        => implode( ' ', $key_class ) . '|' . implode( ';', $key_css ),
			'mine_class' => $class['mine'],
			'rest_class' => $class['rest'],
			'mine_css'   => $css['mine'],
			'rest_css'   => $css['rest'],
			'px'         => $sizes === array() ? null : max( $sizes ),
		);
	}
}

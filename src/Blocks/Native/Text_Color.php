<?php
/**
 * A text colour set in the block's Colour panel instead of by a class.
 *
 * @package DXAI_UI\Blocks\Native
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks\Native;

use DXAI_UI\Compiler\Token_Styles;
use DXAI_UI\Theme\Theme_Binding;
use DXAI_UI\Theme\Theme_Class_Swap;

/**
 * A design's text colour is a class the plugin writes a rule for (`text-dxai-sky-38`, `text-dxai-muted`): a person in the block
 * editor does not see it in the Colour panel and cannot change it there. On the blocks that have a text colour setting it is
 * the setting instead:
 *
 *  - when the site's palette has the colour as a preset of its own (the brand design's tokens, registered by
 *    Design_Theme_Json, where the theme lets a design bring its palette): `textColor`, the preset the Colour panel shows selected;
 *  - otherwise the colour as the class had it, `var(--dxai-sky-38--fg,var(--dxai-sky-38))`: the same declaration the class's rule
 *    wrote (Utility_Classes), so the page is drawn as before, the colour still follows the theme where the design's colours do
 *    (Theme_Binding) and keeps its contrast check, and the Colour panel shows it as the block's custom colour.
 *
 * The class's rule is `!important` with no specificity, the setting is an inline style without it: they decide the same for every
 * rule of the design that is not `!important` itself (those win over both), so nothing moves.
 *
 * Declined, and left as the class: a block with another colour class of its own, one with a variant of a text class (`hover:text-…`,
 * `md:text-…`: a style attribute would beat the variant), one whose own CSS sets a colour, one with attributes this does not move,
 * one whose stored markup is not what its attributes say (someone edited it), and — on a design that follows its theme — a colour
 * that Theme_Class_Swap gives the theme's own class or colour setting.
 */
final class Text_Color extends Converter {

	/**
	 * The blocks with a text colour setting this reads: their tag (empty: it follows from the attributes) and the classes the block
	 * itself writes around the ones of the design.
	 *
	 * @var array<string, array{0:string, 1:array<int, string>}>
	 */
	private const BLOCKS = array(
		'core/paragraph' => array( 'p', array() ),
		'core/heading'   => array( '', array( 'wp-block-heading' ) ),
		'core/group'     => array( '', array( 'wp-block-group' ) ),
		'core/list'      => array( '', array( 'wp-block-list' ) ),
		'amr/span'       => array( 'span', array( 'wp-block-amr-span', 'amr-span' ) ),
	);

	/** The attributes it knows how to carry: nothing here changes the tag but the classes. */
	private const KNOWN = array( 'className', 'dxaiCss', 'anchor', 'metadata', 'level', 'tagName', 'ordered' );

	private const GROUP_TAGS = array( 'div', 'section', 'header', 'footer', 'main', 'article', 'aside', 'nav', 'figure' );

	/** @var array<int, array<string, string>> The design's own colours that are presets, by home: slug => colour. */
	private static array $design = array();

	/** @var array<int, bool> Whether the design's colours are the theme's, by home (the swap is read once). */
	private static array $swaps = array();

	/** @var array<string, string>|null The palette of the site, slug => colour, without core's own. */
	private static ?array $live = null;

	public function id(): string {
		return 'text-color';
	}

	public function label(): string {
		return __( 'Text colours set in the block\'s Colour panel', 'dxai-ui' );
	}

	public function source(): string {
		return 'core/paragraph';
	}

	public function sources(): array {
		return array_keys( self::BLOCKS );
	}

	public function target(): string {
		return 'core/paragraph';
	}

	public function available(): bool {
		return true;
	}

	public function convert( array $block, ?array $parent = null ): ?array {
		$name = (string) ( $block['blockName'] ?? '' );
		if ( ! isset( self::BLOCKS[ $name ] ) || ( $name === 'amr/span' && ! \WP_Block_Type_Registry::get_instance()->is_registered( 'amr/span' ) ) ) {
			return null;
		}
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		if ( ! self::only( $attrs, self::KNOWN ) ) {
			return null;
		}
		$tokens = preg_split( '/\s+/', trim( (string) ( $attrs['className'] ?? '' ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		$slug   = '';
		foreach ( $tokens as $token ) {
			// A variant of a text class (a state, a breakpoint) is stronger than a style attribute would let it be.
			if ( preg_match( '/^[^:\s]+:.*\btext-/', $token ) === 1 || preg_match( '/^(?:text-inherit|text-current|text-transparent|has-[a-z0-9-]+-color|has-text-color)$/', $token ) === 1 ) {
				return null;
			}
			if ( preg_match( '/^text-dxai-([a-z0-9]+(?:-[a-z0-9]+)*)$/', $token, $m ) === 1 ) {
				if ( $slug !== '' ) {
					return null;
				}
				$slug = $m[1];
			}
		}
		if ( $slug === '' || preg_match( '/(?:^|;)\s*color\s*:/i', (string) ( $attrs['dxaiCss'] ?? '' ) ) === 1 ) {
			return null;
		}
		$home = (int) ( self::$context['home'] ?? 0 );
		if ( $home > 0 && self::theme_handles( $home, $slug ) ) {
			return null;
		}

		$tag = self::tag( $name, $attrs );
		if ( $tag === '' ) {
			return null;
		}
		$preset = $home > 0 ? self::preset( $home, $slug ) : '';
		$extra  = $preset !== '' ? array( 'has-' . $preset . '-color', 'has-text-color' ) : array( 'has-text-color' );
		$style  = $preset !== '' ? '' : 'color:var(--dxai-' . $slug . '--fg,var(--dxai-' . $slug . '))';

		$new = $attrs;
		$kept = array_values( array_filter( $tokens, static fn( $t ) => $t !== 'text-dxai-' . $slug ) );
		if ( $kept === array() ) {
			unset( $new['className'] );
		} else {
			$new['className'] = implode( ' ', $kept );
		}
		if ( $preset !== '' ) {
			$new['textColor'] = $preset;
		} else {
			$new['style'] = array( 'color' => array( 'text' => 'var(--dxai-' . $slug . '--fg,var(--dxai-' . $slug . '))' ) );
		}

		return self::rewrite( $block, $tag, self::BLOCKS[ $name ][1], $attrs, $new, $extra, $style );
	}

	/**
	 * The tag the block writes, from what it is and what it says.
	 *
	 * @param array<string, mixed> $attrs
	 */
	private static function tag( string $name, array $attrs ): string {
		if ( self::BLOCKS[ $name ][0] !== '' ) {
			return self::BLOCKS[ $name ][0];
		}
		switch ( $name ) {
			case 'core/heading':
				$level = (int) ( $attrs['level'] ?? 2 );

				return $level >= 1 && $level <= 6 ? 'h' . $level : '';
			case 'core/list':
				return ! empty( $attrs['ordered'] ) ? 'ol' : 'ul';
			default:
				$tag = (string) ( $attrs['tagName'] ?? 'div' );

				return in_array( $tag, self::GROUP_TAGS, true ) ? $tag : '';
		}
	}

	/**
	 * The block with its opening tag written again from the new attributes: the colour class dropped from the design's, the
	 * block's own colour classes and its style attribute added. Null when the stored tag is not what the old attributes say.
	 *
	 * @param array<string, mixed> $block
	 * @param array<int, string>   $base  The classes the block writes itself.
	 * @param array<string, mixed> $old
	 * @param array<string, mixed> $new
	 * @param array<int, string>   $extra The classes the colour adds.
	 * @return array<string, mixed>|null
	 */
	private static function rewrite( array $block, string $tag, array $base, array $old, array $new, array $extra, string $style ): ?array {
		$content = is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array();
		if ( ! is_string( $content[0] ?? null ) || preg_match( '/^(\s*)(<' . preg_quote( $tag, '/' ) . '\b[^>]*>)(.*)$/s', $content[0], $m ) !== 1 ) {
			return null;
		}
		$have = self::tag_attributes( $m[2], $tag );
		if ( $have === null || array_diff( array_keys( $have ), array( 'id', 'class' ) ) !== array() || ( $have['id'] ?? null ) !== ( $old['anchor'] ?? null ) ) {
			return null;
		}
		$want = self::tokens( trim( implode( ' ', $base ) . ' ' . self::class_tail( $old ) ) );
		$got  = self::tokens( (string) ( $have['class'] ?? '' ) );
		if ( array_diff( $want, $got ) !== array() || array_diff( $got, $want ) !== array() ) {
			return null;
		}
		$classes = self::tokens( trim( implode( ' ', $base ) . ' ' . self::class_tail( $new ) . ' ' . implode( ' ', $extra ) ) );
		foreach ( array_merge( $classes, array( $style ) ) as $part ) {
			if ( self::hazard( $part ) ) {
				return null;
			}
		}
		$open = '<' . $tag;
		if ( isset( $have['id'] ) ) {
			$open .= ' id="' . esc_attr( $have['id'] ) . '"';
		}
		$open .= ' class="' . esc_attr( implode( ' ', $classes ) ) . '"';
		if ( $style !== '' ) {
			$open .= ' style="' . esc_attr( $style ) . '"';
		}
		$content[0]            = $m[1] . $open . '>' . $m[3];
		$block['attrs']        = $new;
		$block['innerContent'] = $content;
		$block['innerHTML']    = implode( '', array_filter( $content, 'is_string' ) );

		return $block;
	}

	/**
	 * @return array<int, string>
	 */
	private static function tokens( string $classes ): array {
		return array_values( array_unique( preg_split( '/\s+/', trim( $classes ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() ) );
	}

	/**
	 * Whether a colour is Theme_Class_Swap's: on a design that follows its theme, a token bound directly to a theme colour becomes
	 * that theme's class or colour setting there, from the class — which must still be there for it to find.
	 */
	private static function theme_handles( int $home, string $slug ): bool {
		if ( ! isset( self::$swaps[ $home ] ) ) {
			self::$swaps[ $home ] = Theme_Binding::follows( $home ) ? array_fill_keys( array_keys( Theme_Class_Swap::plan( $home ) ), true ) : array();
		}

		return isset( self::$swaps[ $home ][ $slug ] );
	}

	/**
	 * The slug of a preset of the site's palette that is this token of the design, or ''. A preset is only the design's own when it
	 * has the token's name and its colour, and only where the design does not follow a theme (there its colours are the theme's).
	 */
	private static function preset( int $home, string $slug ): string {
		if ( Theme_Binding::follows( $home ) ) {
			return '';
		}
		if ( ! isset( self::$design[ $home ] ) ) {
			$rows = array();
			$meta = get_post_meta( $home, Token_Styles::META, true );
			foreach ( is_array( $meta['colors'] ?? null ) ? $meta['colors'] : array() as $row ) {
				if ( is_array( $row ) && ! empty( $row['preset'] ) && isset( $row['slug'], $row['value'] ) ) {
					$rows[ (string) $row['slug'] ] = strtolower( (string) $row['value'] );
				}
			}
			self::$design[ $home ] = $rows;
		}
		$value = self::$design[ $home ][ $slug ] ?? '';
		if ( $value === '' ) {
			return '';
		}
		if ( self::$live === null ) {
			self::$live = array();
			foreach ( (array) wp_get_global_settings( array( 'color', 'palette' ) ) as $origin => $entries ) {
				if ( $origin === 'default' ) {
					continue;
				}
				foreach ( (array) $entries as $entry ) {
					if ( is_array( $entry ) && isset( $entry['slug'], $entry['color'] ) ) {
						self::$live[ (string) $entry['slug'] ] = strtolower( (string) $entry['color'] );
					}
				}
			}
		}

		return ( self::$live[ $slug ] ?? '' ) === $value ? $slug : '';
	}

	/** Forget what was read about the designs and the palette (a test changes them between calls). */
	public static function forget(): void {
		self::$design = array();
		self::$swaps  = array();
		self::$live   = null;
	}
}

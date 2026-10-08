<?php
/**
 * A background colour set in the block's Colour panel instead of by a class.
 *
 * @package DXAI_UI\Blocks\Native
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks\Native;

/**
 * The other half of Text_Color: a design's background colour is a class the plugin writes a rule for (`bg-dxai-neutral-96`,
 * `bg-dxai-brand`), which a person in the block editor does not see in the Colour panel. On the blocks that have a background
 * setting (a group, the columns and a column) it is the setting instead — the preset where the site's palette has the colour as a
 * preset of the design's own (Design_Theme_Json; the design's style variation, Design\Style_Variation), else the colour as the class
 * had it, `var(--dxai-neutral-96)`, as the block's custom colour: the page is drawn as before, the colour still follows the theme
 * where the design's colours do (Theme_Binding).
 *
 * The class's rule is `background:var(--dxai-x)` — the shorthand — with `!important` at no specificity; the setting is
 * `background-color` as an inline style without it, or the preset's class, whose rule is `background-color` with `!important` at
 * (0,1,0). A block with any other background of its own keeps its class: another `bg-` class (a gradient, a picture, a variant such
 * as `hover:bg-…` or `md:bg-…`), its own CSS naming a background, a `has-background` it already carries. Declined too, as Text_Color
 * declines: attributes this does not move, a stored tag that is not what its attributes say, and — on a design that follows its
 * theme — a colour Theme_Class_Swap gives the theme's own class or setting.
 */
final class Background_Color extends Text_Color {

	/**
	 * The blocks with a background setting this reads: their tag (empty: from the attributes) and the classes the block writes itself.
	 *
	 * @var array<string, array{0:string, 1:array<int, string>}>
	 */
	protected const BLOCKS = array(
		'core/group'   => array( '', array( 'wp-block-group' ) ),
		'core/columns' => array( 'div', array( 'wp-block-columns' ) ),
		'core/column'  => array( 'div', array( 'wp-block-column' ) ),
	);

	/** The attributes it knows how to carry: `layout` adds its classes at render, never to the stored tag. */
	protected const KNOWN = array( 'className', 'dxaiCss', 'anchor', 'metadata', 'tagName', 'layout', 'style', 'textColor' );

	public function id(): string {
		return 'background-color';
	}

	public function label(): string {
		return __( 'Background colours set in the block\'s Colour panel', 'dxai-ui' );
	}

	public function source(): string {
		return 'core/group';
	}

	public function sources(): array {
		return array_keys( self::BLOCKS );
	}

	public function target(): string {
		return 'core/group';
	}

	public function convert( array $block, ?array $parent = null ): ?array {
		$name = (string) ( $block['blockName'] ?? '' );
		if ( ! isset( self::BLOCKS[ $name ] ) ) {
			return null;
		}
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		// A text colour Text_Color set may be there; a background already set, or any other style, may not.
		if ( ! self::only( $attrs, self::KNOWN ) || ! self::settings_fit( $attrs, 'background' ) ) {
			return null;
		}
		$tokens = preg_split( '/\s+/', trim( (string) ( $attrs['className'] ?? '' ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		$slug   = '';
		foreach ( $tokens as $token ) {
			// Any other background — a variant, a gradient, a picture, a setting already there — is more than a colour setting carries.
			if ( preg_match( '/^[^:\s]+:.*\bbg-/', $token ) === 1 || preg_match( '/^(?:has-background|has-[a-z0-9-]+-background-color)$/', $token ) === 1 ) {
				return null;
			}
			if ( preg_match( '/^bg-dxai-([a-z0-9]+(?:-[a-z0-9]+)*)$/', $token, $m ) === 1 ) {
				if ( $slug !== '' ) {
					return null;
				}
				$slug = $m[1];
			} elseif ( str_starts_with( $token, 'bg-' ) ) {
				return null;
			}
		}
		if ( $slug === '' || preg_match( '/(?:^|;)\s*background(?:-[a-z]+)?\s*:/i', (string) ( $attrs['dxaiCss'] ?? '' ) ) === 1 ) {
			return null;
		}
		$home = (int) ( self::$context['home'] ?? 0 );
		if ( $home > 0 && self::theme_handles( $home, $slug ) ) {
			return null;
		}
		$tag = self::BLOCKS[ $name ][0] !== '' ? self::BLOCKS[ $name ][0] : self::tag( $name, $attrs );
		if ( $tag === '' ) {
			return null;
		}
		$preset = $home > 0 ? self::preset( $home, $slug ) : '';
		$extra  = $preset !== '' ? array( 'has-' . $preset . '-background-color', 'has-background' ) : array( 'has-background' );
		$style  = $preset !== '' ? '' : 'background-color:var(--dxai-' . $slug . ')';
		$new    = $attrs;
		$kept   = array_values( array_filter( $tokens, static fn( $t ) => $t !== 'bg-dxai-' . $slug ) );
		if ( $kept === array() ) {
			unset( $new['className'] );
		} else {
			$new['className'] = implode( ' ', $kept );
		}
		if ( $preset !== '' ) {
			$new['backgroundColor'] = $preset;
		} else {
			$new['style']['color']['background'] = 'var(--dxai-' . $slug . ')';
		}

		return self::rewrite( $block, $tag, self::BLOCKS[ $name ][1], $attrs, $new, $extra, $style );
	}
}

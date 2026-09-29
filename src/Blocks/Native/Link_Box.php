<?php
/**
 * A link around other blocks: the theme's Link box.
 *
 * @package DXAI_UI\Blocks\Native
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks\Native;

/**
 * `dxai-ui/link` holding inner blocks (a card that is one link, a logo with a
 * heading), or `dxai-ui/box` written as an `<a>`, → the theme's own
 * `amr/link-box` (american-restoration): "a box for any blocks, with an
 * optional link around the whole area". Its save() is one `<a>` with the inner
 * blocks in it, exactly what the custom block wrote, so the page keeps every
 * element and every class; the block gains the theme's own controls for the
 * link (address, new tab, ARIA label) and its hover styles when a person adds
 * them.
 *
 * Only a plain link converts — an address, a target, a rel, an ARIA label and
 * an anchor. A link carrying a role, a title, data attributes or expanded/
 * controls state is a control, not a card, and stays as it is.
 */
final class Link_Box extends Converter {

	private const KNOWN = array( 'url', 'className', 'dxaiCss', 'hasInner', 'target', 'rel', 'ariaLabel', 'anchor', 'tagName', 'attrOrder' );

	public function id(): string {
		return 'link-box';
	}

	public function label(): string {
		return __( 'Links around content → Link box', 'dxai-ui' );
	}

	public function source(): string {
		return 'dxai-ui/link';
	}

	public function sources(): array {
		return array( 'dxai-ui/link', 'dxai-ui/box' );
	}

	public function target(): string {
		return 'amr/link-box';
	}

	public function convert( array $block ): ?array {
		$name  = (string) ( $block['blockName'] ?? '' );
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$url   = (string) ( $attrs['url'] ?? '' );
		if ( ! in_array( $name, $this->sources(), true ) || $url === '' || ! self::only( $attrs, self::KNOWN ) || ( $block['innerBlocks'] ?? array() ) === array() ) {
			return null;
		}
		if ( $name === 'dxai-ui/link' ? empty( $attrs['hasInner'] ) || isset( $attrs['tagName'] ) : ( $attrs['tagName'] ?? '' ) !== 'a' ) {
			return null;
		}
		// The theme's Link box sets `display:block`, `color:inherit` and no underline at a weight that beats an ordinary
		// class rule. A design that states its own display and text colour with utilities (which win) is unaffected; one that
		// leaves them to a stylesheet rule or to the browser (an inline `<a>`) would change, so it keeps its block.
		if ( ! self::states_display( $attrs ) || ! self::states_color( $attrs ) ) {
			return null;
		}
		foreach ( array( 'url', 'target', 'rel', 'ariaLabel', 'anchor' ) as $key ) {
			$value = (string) ( $attrs[ $key ] ?? '' );
			if ( self::hazard( $value ) || ( $key !== 'url' && $value !== trim( $value ) ) ) {
				return null;
			}
		}
		$content = is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array();
		$last    = count( $content ) - 1;
		if ( $last < 1 || ! is_string( $content[0] ) || ! is_string( $content[ $last ] ) || trim( $content[ $last ] ) !== '</a>' ) {
			return null;
		}
		// The stored opening tag must be the one this block writes, and nothing else.
		$open = self::tag_attributes( trim( $content[0] ), 'a' );
		if ( $open === null || array_diff( array_keys( $open ), array( 'class', 'href', 'target', 'rel', 'aria-label', 'id' ) ) !== array() ) {
			return null;
		}
		$class = trim( ( $name === 'dxai-ui/link' ? 'wp-block-dxai-ui-link ' : '' ) . self::class_tail( $attrs ) );
		if ( ( $open['class'] ?? '' ) !== $class || html_entity_decode( (string) ( $open['href'] ?? '' ), ENT_QUOTES, 'UTF-8' ) !== html_entity_decode( esc_url( $url ), ENT_QUOTES, 'UTF-8' ) ) {
			return null;
		}
		foreach ( array(
			'target'     => 'target',
			'rel'        => 'rel',
			'aria-label' => 'ariaLabel',
			'id'         => 'anchor',
		) as $html => $key ) {
			if ( (string) ( $open[ $html ] ?? '' ) !== (string) ( $attrs[ $key ] ?? '' ) ) {
				return null;
			}
		}

		$new = array( 'url' => $url );
		if ( ! empty( $attrs['target'] ) ) {
			$new['linkTarget'] = (string) $attrs['target'];
		}
		foreach ( array( 'rel', 'ariaLabel', 'anchor', 'className', 'dxaiCss' ) as $key ) {
			if ( isset( $attrs[ $key ] ) && (string) $attrs[ $key ] !== '' ) {
				$new[ $key ] = $attrs[ $key ];
			}
		}

		// save(): the generated class, the block's own two, the custom classes, then the dxs- class.
		$tag = '<a class="' . esc_attr( trim( 'wp-block-amr-link-box amr-link-box amr-link-box--has-link ' . self::class_tail( $attrs ) ) ) . '"';
		if ( isset( $new['anchor'] ) ) {
			$tag .= ' id="' . esc_attr( $new['anchor'] ) . '"';
		}
		$tag .= ' href="' . esc_url( $url ) . '"';
		if ( isset( $new['linkTarget'] ) ) {
			$tag .= ' target="' . esc_attr( $new['linkTarget'] ) . '"';
		}
		if ( isset( $new['rel'] ) ) {
			$tag .= ' rel="' . esc_attr( $new['rel'] ) . '"';
		}
		if ( isset( $new['ariaLabel'] ) ) {
			$tag .= ' aria-label="' . esc_attr( $new['ariaLabel'] ) . '"';
		}
		$tag .= '>';

		$content[0]            = $tag;
		$block['blockName']    = $this->target();
		$block['attrs']        = $new;
		$block['innerContent'] = $content;
		$block['innerHTML']    = implode( '', array_filter( $content, 'is_string' ) );

		return $block;
	}
}

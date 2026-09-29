<?php
/**
 * A short inline label: the theme's Span.
 *
 * @package DXAI_UI\Blocks\Native
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks\Native;

/**
 * `dxai-ui/text` written as a `<span>` holding words → the theme's `amr/span`
 * (american-restoration): an inline span with colour, typography and spacing
 * controls, the block its own pages use for the small label above a heading.
 * Its save() is one `<span>` with the words in it — the element and its
 * classes are the ones the custom block wrote.
 *
 * Only a span with plain words (and line breaks, bold, italic) converts. A span
 * with markup inside it, data attributes, aria-hidden or any other attribute is
 * a piece of the design's behaviour and stays a custom block.
 */
final class Span extends Converter {

	private const KNOWN = array( 'className', 'dxaiCss', 'tagName' );

	public function id(): string {
		return 'span';
	}

	public function label(): string {
		return __( 'Text spans → Span', 'dxai-ui' );
	}

	public function source(): string {
		return 'dxai-ui/text';
	}

	public function target(): string {
		return 'amr/span';
	}

	public function convert( array $block ): ?array {
		if ( ( $block['blockName'] ?? '' ) !== $this->source() || ( $block['innerBlocks'] ?? array() ) !== array() ) {
			return null;
		}
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		if ( ! self::only( $attrs, self::KNOWN ) || ( $attrs['tagName'] ?? 'span' ) !== 'span' ) {
			return null;
		}
		$content = is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array();
		if ( count( $content ) !== 1 || ! is_string( $content[0] ) ) {
			return null;
		}
		if ( preg_match( '/^(\s*)(<span\b[^>]*>)(.*)<\/span>(\s*)$/s', $content[0], $m ) !== 1 ) {
			return null;
		}
		$open  = self::tag_attributes( $m[2], 'span' );
		$words = $m[3];
		// The tag carries the block's classes and nothing else.
		if ( $open === null || array_diff( array_keys( $open ), array( 'class' ) ) !== array() ) {
			return null;
		}
		if ( ( $open['class'] ?? '' ) !== self::class_tail( $attrs ) ) {
			return null;
		}
		// Words, entities and the inline tags RichText writes back unchanged.
		$bare = preg_replace( '/<(?:br|strong|em|b|i)\s*\/?>|<\/(?:strong|em|b|i)>/i', '', $words );
		if ( $words === '' || ! is_string( $bare ) || str_contains( $bare, '<' ) || str_contains( $bare, '>' ) ) {
			return null;
		}

		$new = array();
		if ( trim( (string) ( $attrs['className'] ?? '' ) ) !== '' ) {
			$new['className'] = $attrs['className'];
		}
		if ( ! empty( $attrs['dxaiCss'] ) ) {
			$new['dxaiCss'] = $attrs['dxaiCss'];
		}
		$tag = '<span class="' . esc_attr( trim( 'wp-block-amr-span amr-span ' . self::class_tail( $attrs ) ) ) . '">';

		$block['blockName']    = $this->target();
		$block['attrs']        = $new;
		$block['innerContent'] = array( $m[1] . $tag . $words . '</span>' . $m[4] );
		$block['innerHTML']    = $block['innerContent'][0];

		return $block;
	}
}

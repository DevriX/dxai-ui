<?php
/**
 * A plain container: core's Group.
 *
 * @package DXAI_UI\Blocks\Native
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks\Native;

/**
 * `dxai-ui/box` written as a `div`, `section`, `header`, `footer`, `main`,
 * `aside` or `article` → `core/group`. A group is one element of that tag with
 * the inner blocks in it, which is what the box wrote: the page keeps its
 * elements, classes and ARIA and gains the group's own controls (tag, layout,
 * colour, spacing) and the List View name.
 *
 * What a group cannot carry keeps the box: a control's expanded/controls state,
 * an `href`, a `src`, a `type`, a `tabindex` and the rest of an element's own
 * behaviour. Data attributes, an id, `aria-hidden`, `aria-labelledby`,
 * `aria-label` and `role` travel on the group as they do on the ones the
 * importer writes.
 */
final class Group extends Converter {

	private const TAGS = array( 'div', 'section', 'header', 'footer', 'main', 'aside', 'article' );

	private const KNOWN = array( 'tagName', 'className', 'dxaiCss', 'anchor', 'ariaHidden', 'labelledBy', 'ariaLabel', 'role', 'dxaiData', 'attrOrder' );

	public function id(): string {
		return 'group';
	}

	public function label(): string {
		return __( 'Containers → Group', 'dxai-ui' );
	}

	public function source(): string {
		return 'dxai-ui/box';
	}

	public function target(): string {
		return 'core/group';
	}

	/**
	 * save(): the id, then the generated class with the block's own, then role, aria-*, and the data attributes.
	 *
	 * @param array<string, mixed> $attrs The group's attributes.
	 */
	public static function open_tag( string $tag, array $attrs ): string {
		$html = '<' . $tag;
		if ( isset( $attrs['anchor'] ) ) {
			$html .= ' id="' . esc_attr( (string) $attrs['anchor'] ) . '"';
		}
		$html .= ' class="' . esc_attr( trim( 'wp-block-group ' . self::class_tail( $attrs ) ) ) . '"';
		foreach ( array(
			'role'            => 'role',
			'aria-hidden'     => 'ariaHidden',
			'aria-labelledby' => 'labelledBy',
			'aria-label'      => 'ariaLabel',
		) as $name => $key ) {
			if ( isset( $attrs[ $key ] ) ) {
				$html .= ' ' . $name . '="' . esc_attr( (string) $attrs[ $key ] ) . '"';
			}
		}
		foreach ( is_array( $attrs['dxaiData'] ?? null ) ? $attrs['dxaiData'] : array() as $name => $value ) {
			$html .= ' ' . $name . '="' . esc_attr( (string) $value ) . '"';
		}

		return $html . '>';
	}

	/**
	 * A stored core/group with new attributes: its opening tag written again from them. Null when the stored tag
	 * is not what $old says it should be (a group someone edited: its markup is theirs).
	 *
	 * @param array<string, mixed> $block
	 * @param array<string, mixed> $old   The attributes it has.
	 * @param array<string, mixed> $new   The attributes it gets.
	 * @return array<string, mixed>|null
	 */
	public static function rewrite( array $block, array $old, array $new ): ?array {
		$tag     = (string) ( $old['tagName'] ?? 'div' );
		$content = is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array();
		$last    = count( $content ) - 1;
		if ( $last < 1 || ! is_string( $content[0] ) || ! is_string( $content[ $last ] ) || trim( $content[ $last ] ) !== '</' . $tag . '>' ) {
			return null;
		}
		foreach ( array_slice( $content, 1, $last - 1 ) as $between ) {
			if ( is_string( $between ) && trim( $between ) !== '' ) {
				return null;
			}
		}
		$have = self::tag_attributes( trim( $content[0] ), $tag );
		$want = self::tag_attributes( self::open_tag( $tag, $old ), $tag );
		if ( $have === null || $want === null ) {
			return null;
		}
		ksort( $have );
		ksort( $want );
		if ( $have !== $want ) {
			return null;
		}
		$content[0]            = self::open_tag( $tag, $new );
		$block['attrs']        = $new;
		$block['innerContent'] = $content;
		$block['innerHTML']    = implode( '', array_filter( $content, 'is_string' ) );

		return $block;
	}

	public function convert( array $block, ?array $parent = null ): ?array {
		if ( ( $block['blockName'] ?? '' ) !== $this->source() ) {
			return null;
		}
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$tag   = (string) ( $attrs['tagName'] ?? 'div' );
		// An empty group opens in the editor as a layout picker ("Group blocks together. Select a layout"), so an empty
		// box — the design's decorative overlay, its dots and rules — stays a DX Box, which shows nothing to choose.
		if ( ( $block['innerBlocks'] ?? array() ) === array() || ! in_array( $tag, self::TAGS, true ) || ! self::only( $attrs, self::KNOWN ) ) {
			return null;
		}
		$data = is_array( $attrs['dxaiData'] ?? null ) ? $attrs['dxaiData'] : array();
		foreach ( $data as $name => $value ) {
			if ( ! is_string( $name ) || preg_match( '/^data-[a-z][a-z0-9-]*$/', $name ) !== 1 || ! is_string( $value ) || self::hazard( $value ) ) {
				return null;
			}
		}
		foreach ( array( 'anchor', 'ariaHidden', 'labelledBy', 'ariaLabel', 'role' ) as $key ) {
			$value = (string) ( $attrs[ $key ] ?? '' );
			if ( self::hazard( $value ) || $value !== trim( $value ) || ( isset( $attrs[ $key ] ) && $value === '' ) ) {
				return null;
			}
		}
		$content = is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array();
		// An opening tag, the blocks, and a closing tag with only whitespace between.
		$last = count( $content ) - 1;
		if ( $last < 1 || ! is_string( $content[0] ) || ! is_string( $content[ $last ] ) || trim( $content[ $last ] ) !== '</' . $tag . '>' ) {
			return null;
		}
		foreach ( array_slice( $content, 1, $last - 1 ) as $between ) {
			if ( is_string( $between ) && trim( $between ) !== '' ) {
				return null;
			}
		}
		$open_tag = trim( $content[0] );
		// The stored tag must carry exactly what the block's attributes say, and nothing else.
		$open = self::tag_attributes( $open_tag, $tag );
		if ( $open === null ) {
			return null;
		}
		$want = array();
		if ( self::class_tail( $attrs ) !== '' ) {
			$want['class'] = self::class_tail( $attrs );
		}
		foreach ( array(
			'id'              => 'anchor',
			'role'            => 'role',
			'aria-hidden'     => 'ariaHidden',
			'aria-labelledby' => 'labelledBy',
			'aria-label'      => 'ariaLabel',
		) as $html => $key ) {
			if ( isset( $attrs[ $key ] ) && (string) $attrs[ $key ] !== '' ) {
				$want[ $html ] = (string) $attrs[ $key ];
			}
		}
		foreach ( $data as $name => $value ) {
			$want[ $name ] = (string) $value;
		}
		ksort( $want );
		$have = $open;
		ksort( $have );
		if ( $want !== $have ) {
			return null;
		}

		$new = array();
		if ( $tag !== 'div' ) {
			$new['tagName'] = $tag;
		}
		foreach ( array( 'className', 'anchor', 'ariaHidden', 'labelledBy', 'ariaLabel', 'role', 'dxaiCss' ) as $key ) {
			if ( isset( $attrs[ $key ] ) && (string) $attrs[ $key ] !== '' ) {
				$new[ $key ] = $attrs[ $key ];
			}
		}
		if ( $data !== array() ) {
			$new['dxaiData'] = $data;
		}

		$content[0] = self::open_tag( $tag, $new );
		$block['blockName']    = $this->target();
		$block['attrs']        = $new;
		$block['innerContent'] = $content;
		$block['innerHTML']    = implode( '', array_filter( $content, 'is_string' ) );

		return $block;
	}
}

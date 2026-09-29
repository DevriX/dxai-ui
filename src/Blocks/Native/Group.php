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

	public function convert( array $block ): ?array {
		if ( ( $block['blockName'] ?? '' ) !== $this->source() ) {
			return null;
		}
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$tag   = (string) ( $attrs['tagName'] ?? 'div' );
		if ( ! in_array( $tag, self::TAGS, true ) || ! self::only( $attrs, self::KNOWN ) ) {
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
		$inner   = $block['innerBlocks'] ?? array();
		// One string (an empty box) or an opening tag, the blocks, and a closing tag with only whitespace between.
		if ( $inner === array() ) {
			if ( count( $content ) !== 1 || ! is_string( $content[0] ) || preg_match( '/^(\s*)(<' . $tag . '\b[^>]*>)<\/' . $tag . '>(\s*)$/s', $content[0], $m ) !== 1 ) {
				return null;
			}
			$open_tag = $m[2];
		} else {
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
		}
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

		// save(): the id, then the generated class with the block's own, then role, aria-*, and the data attributes.
		$tag_html = '<' . $tag;
		if ( isset( $new['anchor'] ) ) {
			$tag_html .= ' id="' . esc_attr( $new['anchor'] ) . '"';
		}
		$tag_html .= ' class="' . esc_attr( trim( 'wp-block-group ' . self::class_tail( $attrs ) ) ) . '"';
		foreach ( array(
			'role'            => 'role',
			'aria-hidden'     => 'ariaHidden',
			'aria-labelledby' => 'labelledBy',
			'aria-label'      => 'ariaLabel',
		) as $html => $key ) {
			if ( isset( $new[ $key ] ) ) {
				$tag_html .= ' ' . $html . '="' . esc_attr( (string) $new[ $key ] ) . '"';
			}
		}
		foreach ( $data as $name => $value ) {
			$tag_html .= ' ' . $name . '="' . esc_attr( (string) $value ) . '"';
		}
		$tag_html .= '>';

		if ( $inner === array() ) {
			$content = array( ( $m[1] ?? '' ) . $tag_html . '</' . $tag . '>' . ( $m[3] ?? '' ) );
		} else {
			$content[0] = $tag_html;
		}
		$block['blockName']    = $this->target();
		$block['attrs']        = $new;
		$block['innerContent'] = $content;
		$block['innerHTML']    = implode( '', array_filter( $content, 'is_string' ) );

		return $block;
	}
}

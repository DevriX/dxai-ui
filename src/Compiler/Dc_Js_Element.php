<?php
/**
 * A React element produced by `React.createElement()` in Claude Design logic.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

/**
 * The designs build their inline SVG icons with `React.createElement('svg',
 * {...}, React.createElement('path', {d}))` and interpolate the result into
 * text — `{{ iconPhone }}`. React DOM serialises that to markup; this does
 * the same, following React's rules where they show: `className` → `class`,
 * `htmlFor` → `for`, a style OBJECT joined with `px` on bare numbers except
 * the unitless properties, `true` → bare attribute, `false`/`null` → none,
 * `key` dropped, arrays of children flattened, strings escaped.
 */
final class Dc_Js_Element {

	private const VOID = array( 'img', 'br', 'hr', 'input', 'meta', 'link', 'source', 'track', 'wbr', 'area', 'base', 'col', 'embed' );

	/** React's unitless style properties, camelCase as the source writes them. */
	private const UNITLESS = array(
		'animationIterationCount', 'aspectRatio', 'borderImageOutset', 'borderImageSlice', 'borderImageWidth',
		'boxFlex', 'boxFlexGroup', 'boxOrdinalGroup', 'columnCount', 'columns', 'flex', 'flexGrow', 'flexPositive',
		'flexShrink', 'flexNegative', 'flexOrder', 'gridArea', 'gridRow', 'gridRowEnd', 'gridRowSpan', 'gridRowStart',
		'gridColumn', 'gridColumnEnd', 'gridColumnSpan', 'gridColumnStart', 'fontWeight', 'lineClamp', 'lineHeight',
		'opacity', 'order', 'orphans', 'scale', 'tabSize', 'widows', 'zIndex', 'zoom', 'fillOpacity', 'floodOpacity',
		'stopOpacity', 'strokeDasharray', 'strokeDashoffset', 'strokeMiterlimit', 'strokeOpacity', 'strokeWidth',
	);

	/**
	 * @param array<string, mixed> $props
	 * @param array<int, mixed>    $children
	 */
	public function __construct(
		public readonly string $tag,
		public readonly array $props,
		public readonly array $children,
	) {}

	public function to_html(): string {
		$tag  = strtolower( $this->tag ) === $this->tag ? $this->tag : strtolower( $this->tag );
		$html = '<' . $tag;
		foreach ( $this->props as $name => $value ) {
			if ( $name === 'key' || $name === 'ref' || $name === 'children' || $name === 'dangerouslySetInnerHTML' ) {
				continue;
			}
			if ( str_starts_with( $name, 'on' ) && $value instanceof Dc_Js_Fn ) {
				continue;
			}
			$attr = $name;
			if ( $name === 'className' ) {
				$attr = 'class';
			} elseif ( $name === 'htmlFor' ) {
				$attr = 'for';
			}
			if ( $name === 'style' && is_array( $value ) ) {
				$css = self::style_object( $value );
				if ( $css !== '' ) {
					$html .= ' style="' . esc_attr( $css ) . '"';
				}
				continue;
			}
			if ( $value === null || $value === false ) {
				continue;
			}
			if ( $value === true ) {
				$html .= ' ' . $attr . '=""';
				continue;
			}
			if ( is_array( $value ) || $value instanceof Dc_Js_Fn || $value instanceof Dc_Js_Element ) {
				continue;
			}
			$html .= ' ' . $attr . '="' . esc_attr( Dc_Js::to_string( $value ) ) . '"';
		}
		$html .= '>';
		if ( in_array( $tag, self::VOID, true ) ) {
			return $html;
		}
		foreach ( $this->children as $child ) {
			$html .= self::child_html( $child );
		}

		return $html . '</' . $tag . '>';
	}

	/** Serialise one child the way React DOM would render it. */
	public static function child_html( mixed $child ): string {
		if ( $child === null || is_bool( $child ) ) {
			return '';
		}
		if ( $child instanceof Dc_Js_Element ) {
			return $child->to_html();
		}
		if ( is_array( $child ) ) {
			$out = '';
			foreach ( $child as $item ) {
				$out .= self::child_html( $item );
			}
			return $out;
		}
		if ( $child instanceof Dc_Js_Fn ) {
			return '';
		}

		return esc_html( Dc_Js::to_string( $child ) );
	}

	/**
	 * React's style object → CSS text.
	 *
	 * @param array<string, mixed> $style
	 */
	public static function style_object( array $style ): string {
		$out = array();
		foreach ( $style as $prop => $value ) {
			if ( $value === null || $value === false || $value === '' ) {
				continue;
			}
			$name = str_starts_with( (string) $prop, '--' )
				? (string) $prop
				: strtolower( (string) preg_replace( '/([A-Z])/', '-$1', (string) $prop ) );
			if ( str_starts_with( $name, 'ms-' ) ) {
				$name = '-' . $name;
			}
			if ( is_int( $value ) || is_float( $value ) ) {
				$text = Dc_Js::to_string( $value );
				if ( $value !== 0 && ! in_array( (string) $prop, self::UNITLESS, true ) && ! str_starts_with( (string) $prop, '--' ) ) {
					$text .= 'px';
				}
			} else {
				$text = Dc_Js::to_string( $value );
			}
			$out[] = $name . ':' . $text;
		}

		return implode( ';', $out );
	}
}

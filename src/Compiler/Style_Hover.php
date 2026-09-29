<?php
/**
 * Convert Design.com `style-hover` attributes into real CSS :hover rules.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Style_Hover {

	private static int $seq = 0;

	/**
	 * Rewrite `style-hover="…"` / `styleHover="…"` into a class + collected CSS.
	 *
	 * @return array{0: string, 1: string} Rewritten HTML and CSS declarations.
	 */
	public static function apply( string $html ): array {
		$html = trim( $html );
		if ( $html === '' || ( ! str_contains( $html, 'style-hover' ) && ! str_contains( $html, 'styleHover' ) ) ) {
			return array( $html, '' );
		}

		$css = '';
		$out = preg_replace_callback(
			'/<([a-zA-Z][\w:-]*)(\s[^>]*?)\s(?:style-hover|styleHover)=("|\')(.*?)\3([^>]*)>/s',
			static function ( array $m ) use ( &$css ): string {
				$decls = self::sanitize_decls( $m[4] );
				if ( $decls === '' ) {
					return '<' . $m[1] . $m[2] . $m[5] . '>';
				}

				++self::$seq;
				$class = 'dxai-sh-' . self::$seq;
				$css  .= '.' . $class . ':hover{' . $decls . "}\n";

				$attrs = $m[2] . $m[5];
				if ( preg_match( '/\bclass=("|\')(.*?)\1/i', $attrs ) === 1 ) {
					$attrs = preg_replace(
						'/\bclass=("|\')(.*?)\1/i',
						'class=$1' . $class . ' $2$1',
						$attrs,
						1
					) ?? $attrs;
				} else {
					$attrs .= ' class="' . $class . '"';
				}

				return '<' . $m[1] . $attrs . '>';
			},
			$html
		);

		return array( is_string( $out ) ? $out : $html, $css );
	}

	/**
	 * Allow only CSS declaration text (property:value; …).
	 */
	private static function sanitize_decls( string $decls ): string {
		$decls = trim( html_entity_decode( $decls, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( $decls === '' ) {
			return '';
		}
		// Strip anything that could break out of a style block.
		$decls = str_replace( array( '{', '}', '<', '>' ), '', $decls );
		$decls = preg_replace( '/\s+/', ' ', $decls ) ?? $decls;
		$decls = rtrim( $decls, "; \t" );
		if ( $decls === '' ) {
			return '';
		}

		return $decls . ';';
	}
}

<?php
/**
 * Validates Gutenberg comment syntax.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Gutenberg_Mapper {

	public function validate( string $markup, bool $require_group = false ): string|\WP_Error {
		$markup = trim( $markup );
		if ( $markup === '' || ! str_contains( $markup, '<!-- wp:' ) ) {
			return new \WP_Error(
				'dxai_ui_markup',
				__( 'Generated markup is not valid Gutenberg — the compiler produced no `<!-- wp:` blocks.', 'dxai-ui' ),
				array( 'status' => 422 )
			);
		}

		if ( $require_group && ! str_contains( $markup, '<!-- wp:group' ) && ! str_contains( $markup, '<!-- wp:dxai-ui/' ) ) {
			$markup = "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\">\n{$markup}\n</div>\n<!-- /wp:group -->";
		}

		if ( function_exists( 'parse_blocks' ) ) {
			$blocks = parse_blocks( $markup );
			$has    = false;
			foreach ( $blocks as $block ) {
				if ( ! empty( $block['blockName'] ) ) {
					$has = true;
					break;
				}
			}
			if ( ! $has ) {
				return new \WP_Error( 'dxai_ui_markup', __( 'WordPress could not parse the generated blocks.', 'dxai-ui' ), array( 'status' => 422 ) );
			}
		}

		return $markup;
	}

	public function wrap_scope( string $markup, int $pattern_id, string $extra_class = '' ): string {
		$class  = trim( 'dxai-ui dxai-ui--' . $pattern_id . ' ' . $extra_class );
		$markup = trim( $markup );
		if ( $markup === '' ) {
			return $markup;
		}

		// Only skip when the ROOT group already carries this scope.
		// Inner body scopes must not prevent wrapping header/footer — otherwise
		// Design.com mobile/desktop rules (.dxai-ui--N .dxai-dc-mobile) never match.
		if ( preg_match( '/^<!--\s*wp:group\s+(\{.*?\})\s+-->/s', $markup, $m ) ) {
			$attrs = json_decode( $m[1], true );
			$cn    = is_array( $attrs ) ? (string) ( $attrs['className'] ?? '' ) : '';
			if ( str_contains( $cn, 'dxai-ui--' . $pattern_id ) ) {
				return $markup;
			}
		}

		$encoded = wp_json_encode(
			array(
				'align'     => 'full',
				'className' => $class,
			),
			JSON_UNESCAPED_SLASHES
		);

		return sprintf(
			'<!-- wp:group %1$s -->' . "\n" .
			'<div class="wp-block-group alignfull %2$s">%3$s</div>' . "\n" .
			'<!-- /wp:group -->',
			is_string( $encoded ) ? $encoded : '{"align":"full"}',
			esc_attr( $class ),
			$markup
		);
	}
}

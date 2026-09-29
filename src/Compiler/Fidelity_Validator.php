<?php
/**
 * Validate Pass-2 LLM refine output before merging.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Fidelity_Validator {

	/**
	 * @param array<string, mixed> $structure
	 */
	public static function structure( array $structure, string $source_html = '' ): true|\WP_Error {
		$markup = trim( (string) ( $structure['gutenberg_markup'] ?? '' ) );
		if ( $markup === '' || ! str_contains( $markup, '<!-- wp:' ) ) {
			return new \WP_Error(
				'dxai_ui_refine_markup',
				__( 'Refine response had no Gutenberg blocks.', 'dxai-ui' ),
				array( 'status' => 502 )
			);
		}

		$mapper = new Gutenberg_Mapper();
		$valid  = $mapper->validate( $markup, false );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( function_exists( 'parse_blocks' ) ) {
			$blocks = parse_blocks( $markup );
			$named  = 0;
			foreach ( $blocks as $block ) {
				if ( ! empty( $block['blockName'] ) ) {
					++$named;
				}
			}
			if ( $named < 1 ) {
				return new \WP_Error(
					'dxai_ui_refine_parse',
					__( 'Refine response could not be parsed as blocks.', 'dxai-ui' ),
					array( 'status' => 502 )
				);
			}
		}

		// Reject whole-page html dump for multi-section sources.
		if (
			$source_html !== ''
			&& strlen( $source_html ) > 2500
			&& preg_match_all( '/<!-- wp:([a-z0-9-]+(?:\/[a-z0-9-]+)?)/', $markup, $m ) === 1
			&& ( $m[1][0] ?? '' ) === 'html'
		) {
			return new \WP_Error(
				'dxai_ui_refine_blob',
				__( 'Refine collapsed the structure into a single HTML block.', 'dxai-ui' ),
				array( 'status' => 502 )
			);
		}

		if ( preg_match( '/,\s*\)\s*\}|className\s*=/', $markup ) ) {
			return new \WP_Error(
				'dxai_ui_refine_jsx',
				__( 'Refine response still contains JSX leftovers.', 'dxai-ui' ),
				array( 'status' => 502 )
			);
		}

		return true;
	}
}

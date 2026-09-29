<?php
/**
 * Shared JSON extraction for LLM responses.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Engines;

use DXAI_UI\Compiler\Generation_Parser;

abstract class Abstract_Engine implements LLM_Provider_Interface {

	/**
	 * @return array{
	 *   block_title:string,
	 *   gutenberg_markup:string,
	 *   structures:array<int, array<string, mixed>>,
	 *   custom_css:string,
	 *   custom_js:string,
	 *   required_media:array<int, string>
	 * }|\WP_Error
	 */
	protected function parse_generation( string $text ): array|\WP_Error {
		return Generation_Parser::parse( $text );
	}

	protected function http_error( int $code, string $body, string $fallback ): \WP_Error {
		$message = $fallback;
		$decoded = json_decode( $body, true );
		if ( is_array( $decoded ) ) {
			if ( isset( $decoded['error']['message'] ) ) {
				$message = (string) $decoded['error']['message'];
			} elseif ( isset( $decoded['error']['type'] ) ) {
				$message = (string) $decoded['error']['type'];
			} elseif ( isset( $decoded['message'] ) ) {
				$message = (string) $decoded['message'];
			}
		}

		return new \WP_Error(
			'dxai_ui_engine_http',
			sprintf(
				/* translators: 1: HTTP status, 2: error message */
				__( 'Provider error (%1$d): %2$s', 'dxai-ui' ),
				$code,
				sanitize_text_field( $message )
			),
			array( 'status' => $code >= 400 && $code < 600 ? $code : 502 )
		);
	}
}

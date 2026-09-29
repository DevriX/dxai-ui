<?php
/**
 * Generate Gutenberg in source-sized chunks so JSON stays valid.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

use DXAI_UI\Connectors\Source_Document;
use DXAI_UI\Engines\LLM_Provider_Interface;
use DXAI_UI\Support\Logger;

final class Chunked_Generator {

	public function __construct(
		private readonly LLM_Provider_Interface $engine,
	) {}

	/**
	 * @return array{
	 *   block_title:string,
	 *   gutenberg_markup:string,
	 *   structures:array<int, array<string, mixed>>,
	 *   custom_css:string,
	 *   custom_js:string,
	 *   required_media:array<int, string>,
	 *   chunk?:array{index:int, total:int, title:string}
	 * }|\WP_Error
	 */
	public function generate( Source_Document $source, string $notes = '', ?int $chunk_index = null ): array|\WP_Error {
		$chunks = is_array( $source->payload['chunks'] ?? null ) ? $source->payload['chunks'] : array();
		if ( $chunks === array() ) {
			return $this->engine->generate(
				Prompt_Builder::system(),
				Prompt_Builder::user( $source->to_array(), $notes ),
				array( 'max_tokens' => 8192 )
			);
		}

		$total = count( $chunks );
		if ( $chunk_index !== null ) {
			if ( ! isset( $chunks[ $chunk_index ] ) ) {
				return new \WP_Error( 'dxai_ui_chunk', __( 'Unknown generation chunk.', 'dxai-ui' ), array( 'status' => 400 ) );
			}

			$part = $this->generate_chunk( $source, $chunks[ $chunk_index ], $notes, $chunk_index, $total );
			if ( is_wp_error( $part ) ) {
				return $part;
			}
			$part['chunk'] = array(
				'index' => $chunk_index,
				'total' => $total,
				'title' => (string) ( $chunks[ $chunk_index ]['title'] ?? '' ),
			);

			return $this->attach_shared( $source, $part, $chunk_index === $total - 1 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		$parts = array();
		foreach ( $chunks as $index => $chunk ) {
			$part = $this->generate_chunk( $source, $chunk, $notes, $index, $total );
			if ( is_wp_error( $part ) ) {
				return $part;
			}
			$parts[] = $part;
		}

		$merged = Generation_Merger::merge( $parts, $source->title );
		return $this->attach_shared( $source, $merged, true );
	}

	/**
	 * @param array<string, mixed> $chunk
	 * @return array<string, mixed>|\WP_Error
	 */
	private function generate_chunk( Source_Document $source, array $chunk, string $notes, int $index, int $total ): array|\WP_Error {
		$system = Prompt_Builder::system();
		$user   = Prompt_Builder::user_chunk( $source->to_array(), $chunk, $notes, $index, $total );
		$result = $this->engine->generate( $system, $user, array( 'max_tokens' => 8192 ) );

		if ( is_wp_error( $result ) && in_array( $result->get_error_code(), array( 'dxai_ui_engine_parse', 'dxai_ui_engine_markup' ), true ) ) {
			Logger::store( array( Logger::entry( 'warning', sprintf( 'Chunk %d parse failed, retrying compact prompt.', $index + 1 ) ) ) );
			$result = $this->engine->generate(
				$system,
				Prompt_Builder::user_chunk_compact( $chunk, $index, $total ),
				array( 'max_tokens' => 8192 )
			);
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $result
	 * @return array<string, mixed>
	 */
	private function attach_shared( Source_Document $source, array $result, bool $final ): array {
		if ( ! $final ) {
			return $result;
		}

		$motion = Motion_Runtime::should_attach(
			(string) ( $result['gutenberg_markup'] ?? '' ) . wp_json_encode( $result['structures'] ?? array() ),
			(string) ( $source->payload['design_css'] ?? '' ) . (string) ( $result['custom_css'] ?? '' )
		);
		if ( $motion !== '' ) {
			$existing            = trim( (string) ( $result['custom_js'] ?? '' ) );
			$result['custom_js'] = trim( $existing . "\n" . $motion );
		}

		return $result;
	}
}

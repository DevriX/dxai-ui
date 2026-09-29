<?php
/**
 * Pass-2: refine weak Pass-1 structures via the configured LLM.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

use DXAI_UI\Engines\LLM_Provider_Interface;
use DXAI_UI\Support\Logger;

final class Fidelity_Refiner {

	public function __construct(
		private readonly LLM_Provider_Interface $engine,
	) {}

	/**
	 * @param array<string, mixed>                                                                                                                                   $result
	 * @param array{score:int, issues:array<int, string>, structures:array<int, array<string, mixed>>, needs_refine:array<int, int>} $assessment
	 * @return array<string, mixed>
	 */
	public function refine( array $result, array $assessment, string $mode = 'auto' ): array {
		$mode       = Fidelity_Gate::normalize_mode( $mode );
		$structures = is_array( $result['structures'] ?? null ) ? $result['structures'] : array();
		$indexes    = is_array( $assessment['needs_refine'] ?? null ) ? $assessment['needs_refine'] : array();
		$max        = Fidelity_Gate::max_structures( $mode );
		$indexes    = array_slice( array_values( array_unique( array_map( 'intval', $indexes ) ) ), 0, $max );

		$refined   = 0;
		$failed    = 0;
		$meta_rows = array();

		foreach ( $indexes as $index ) {
			if ( ! isset( $structures[ $index ] ) || ! is_array( $structures[ $index ] ) ) {
				continue;
			}
			$structure = $structures[ $index ];
			$issues    = array();
			foreach ( $assessment['structures'] ?? array() as $row ) {
				if ( is_array( $row ) && (int) ( $row['index'] ?? -1 ) === $index ) {
					$issues = is_array( $row['issues'] ?? null ) ? $row['issues'] : array();
					break;
				}
			}

			$attempt = $this->refine_one( $structure, $issues, $mode );
			if ( is_wp_error( $attempt ) ) {
				++$failed;
				$meta_rows[] = array(
					'index'  => $index,
					'status' => 'kept_pass1',
					'error'  => $attempt->get_error_message(),
				);
				Logger::store(
					array(
						Logger::entry(
							'warning',
							sprintf(
								'Fidelity refine failed for “%s” — keeping Pass 1 (%s).',
								(string) ( $structure['title'] ?? $structure['type'] ?? $index ),
								$attempt->get_error_message()
							)
						),
					)
				);
				continue;
			}

			$structures[ $index ] = $attempt;
			++$refined;
			$meta_rows[] = array(
				'index'  => $index,
				'status' => 'refined',
				'type'   => (string) ( $attempt['type'] ?? '' ),
			);
		}

		$result['structures'] = $structures;
		$result['gutenberg_markup'] = $this->body_markup( $structures );
		$result['compiler']         = 'source+refine';
		$result['fidelity']         = array(
			'mode'     => $mode,
			'pass1'    => $assessment,
			'refined'  => $refined,
			'failed'   => $failed,
			'details'  => $meta_rows,
			'engine'   => $this->engine->get_id(),
		);

		// Re-score after refine for the client.
		$result['fidelity_score'] = Fidelity_Confidence::assess( $result, $mode );

		return $result;
	}

	/**
	 * @param array<string, mixed> $structure
	 * @param array<int, string>   $issues
	 * @return array<string, mixed>|\WP_Error
	 */
	public function refine_one( array $structure, array $issues, string $mode = 'auto' ): array|\WP_Error {
		$system = Prompt_Builder::system_refine();
		$user   = Prompt_Builder::user_refine_structure( $structure, $issues, $mode );
		$out    = $this->engine->generate( $system, $user, array( 'max_tokens' => 8192 ) );

		if ( is_wp_error( $out ) ) {
			// One compact retry.
			$out = $this->engine->generate(
				$system,
				Prompt_Builder::user_refine_structure_compact( $structure, $issues ),
				array( 'max_tokens' => 8192 )
			);
		}
		if ( is_wp_error( $out ) ) {
			return $out;
		}

		$merged = $this->merge_llm_into_structure( $structure, $out );
		$check  = Fidelity_Validator::structure( $merged, (string) ( $structure['source_html'] ?? '' ) );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		return $merged;
	}

	/**
	 * @param array<string, mixed> $structure
	 * @param array<string, mixed> $llm
	 * @return array<string, mixed>
	 */
	private function merge_llm_into_structure( array $structure, array $llm ): array {
		$markup = (string) ( $llm['gutenberg_markup'] ?? '' );
		$from_structures = is_array( $llm['structures'] ?? null ) ? $llm['structures'] : array();
		if ( $from_structures !== array() && is_array( $from_structures[0] ?? null ) ) {
			$first = $from_structures[0];
			if ( ! empty( $first['gutenberg_markup'] ) ) {
				$markup = (string) $first['gutenberg_markup'];
			}
			if ( ! empty( $first['type'] ) ) {
				$structure['type'] = sanitize_key( (string) $first['type'] );
			}
			if ( ! empty( $first['menu_items'] ) && is_array( $first['menu_items'] ) ) {
				$structure['menu_items'] = $first['menu_items'];
			}
			if ( ! empty( $first['form_fields'] ) && is_array( $first['form_fields'] ) ) {
				$structure['form_fields'] = $first['form_fields'];
			}
		}

		$structure['gutenberg_markup'] = $markup;
		$structure['refined']          = true;

		return $structure;
	}

	/**
	 * @param array<int, array<string, mixed>> $structures
	 */
	private function body_markup( array $structures ): string {
		$parts = array();
		foreach ( $structures as $structure ) {
			if ( ! is_array( $structure ) ) {
				continue;
			}
			$type = (string) ( $structure['type'] ?? '' );
			if ( in_array( $type, array( 'header', 'footer', 'navigation' ), true ) ) {
				continue;
			}
			$chunk = trim( (string) ( $structure['gutenberg_markup'] ?? '' ) );
			if ( $chunk !== '' ) {
				$parts[] = $chunk;
			}
		}

		return implode( "\n\n", $parts );
	}
}

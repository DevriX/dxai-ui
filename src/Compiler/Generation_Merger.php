<?php
/**
 * Merge per-chunk LLM generation results.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Generation_Merger {

	/**
	 * @param array<int, array<string, mixed>> $parts
	 * @return array{
	 *   block_title:string,
	 *   gutenberg_markup:string,
	 *   structures:array<int, array<string, mixed>>,
	 *   custom_css:string,
	 *   custom_js:string,
	 *   required_media:array<int, string>
	 * }
	 */
	public static function merge( array $parts, string $title = '' ): array {
		$structures = array();
		$seen_type  = array();
		$body       = array();
		$css        = array();
		$js         = array();
		$media      = array();
		$first_title = $title;

		foreach ( $parts as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}
			if ( $first_title === '' && ! empty( $part['block_title'] ) ) {
				$first_title = (string) $part['block_title'];
			}
			if ( ! empty( $part['custom_css'] ) ) {
				$css[] = trim( (string) $part['custom_css'] );
			}
			if ( ! empty( $part['custom_js'] ) ) {
				$js[] = trim( (string) $part['custom_js'] );
			}
			if ( isset( $part['required_media'] ) && is_array( $part['required_media'] ) ) {
				foreach ( $part['required_media'] as $url ) {
					$url = esc_url_raw( (string) $url );
					if ( $url !== '' ) {
						$media[] = $url;
					}
				}
			}

			$rows = is_array( $part['structures'] ?? null ) ? $part['structures'] : array();
			foreach ( $rows as $structure ) {
				if ( ! is_array( $structure ) ) {
					continue;
				}
				$type = (string) ( $structure['type'] ?? 'section' );
				if ( in_array( $type, array( 'header', 'footer', 'navigation' ), true ) ) {
					if ( isset( $seen_type[ $type ] ) ) {
						continue;
					}
					$seen_type[ $type ] = true;
					$structures[]       = $structure;
					continue;
				}
				$structures[] = $structure;
				$markup       = (string) ( $structure['gutenberg_markup'] ?? '' );
				if ( $markup !== '' ) {
					$body[] = $markup;
				}
			}
		}

		$css = array_values( array_unique( array_filter( $css ) ) );
		$js  = array_values( array_unique( array_filter( $js ) ) );

		return array(
			'block_title'      => $first_title !== '' ? $first_title : __( 'DX UI Pattern', 'dxai-ui' ),
			'gutenberg_markup' => implode( "\n\n", $body ),
			'structures'       => $structures,
			'custom_css'       => implode( "\n\n", $css ),
			'custom_js'        => implode( "\n\n", $js ),
			'required_media'   => array_values( array_unique( $media ) ),
		);
	}
}

<?php
/**
 * Parse and normalize LLM generation JSON.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Generation_Parser {

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
	public static function parse( string $text ): array|\WP_Error {
		$json = Json_Repair::decode( $text );
		if ( ! is_array( $json ) ) {
			$snippet = self::snippet( $text );
			return new \WP_Error(
				'dxai_ui_engine_parse',
				sprintf(
					/* translators: 1: JSON error, 2: response snippet */
					__( 'The model returned invalid JSON (%1$s). %2$s', 'dxai-ui' ),
					Json_Repair::last_error(),
					$snippet !== '' ? $snippet : __( 'The response was empty or truncated.', 'dxai-ui' )
				),
				array(
					'status' => 502,
				)
			);
		}

		$title  = isset( $json['block_title'] ) ? sanitize_text_field( (string) $json['block_title'] ) : '';
		$markup = isset( $json['gutenberg_markup'] ) ? (string) $json['gutenberg_markup'] : '';
		$css    = isset( $json['custom_css'] ) ? (string) $json['custom_css'] : '';
		$js     = isset( $json['custom_js'] ) ? (string) $json['custom_js'] : '';

		$media = array();
		if ( isset( $json['required_media'] ) && is_array( $json['required_media'] ) ) {
			foreach ( $json['required_media'] as $url ) {
				$url = esc_url_raw( (string) $url );
				if ( $url !== '' ) {
					$media[] = $url;
				}
			}
		}

		$structures = Structure_Normalizer::from_json( $json['structures'] ?? array(), $markup, $title );

		if ( $markup === '' && $structures !== array() ) {
			$parts = array();
			foreach ( $structures as $structure ) {
				if ( ! in_array( $structure['type'], array( 'header', 'footer', 'navigation' ), true ) ) {
					$parts[] = $structure['gutenberg_markup'];
				}
			}
			$markup = implode( "\n\n", $parts );
		}

		if ( ( $markup === '' || ! str_contains( $markup, '<!-- wp:' ) ) && $structures === array() ) {
			return new \WP_Error( 'dxai_ui_engine_markup', __( 'The model did not return Gutenberg block markup.', 'dxai-ui' ), array( 'status' => 502 ) );
		}

		$rewriter   = new Dynamic_Rewriter();
		$structures = $rewriter->rewrite_all( $structures );

		if ( $markup === '' || ! str_contains( $markup, '<!-- wp:' ) ) {
			$markup = $structures[0]['gutenberg_markup'] ?? '';
		}

		$mapper = new Gutenberg_Mapper();
		$valid  = $mapper->validate( $markup, false );
		if ( is_wp_error( $valid ) ) {
			$combined = $markup;
			foreach ( $structures as $structure ) {
				$combined .= "\n" . (string) ( $structure['gutenberg_markup'] ?? '' );
			}
			$valid = $mapper->validate( $combined, false );
			if ( is_wp_error( $valid ) ) {
				$valid = $markup;
			}
		}

		return array(
			'block_title'      => $title !== '' ? $title : __( 'DX UI Pattern', 'dxai-ui' ),
			'gutenberg_markup' => is_string( $valid ) ? $valid : $markup,
			'structures'       => $structures,
			'custom_css'       => $css,
			'custom_js'        => $js,
			'required_media'   => $media,
		);
	}

	private static function snippet( string $text ): string {
		$text = trim( Json_Repair::normalize( $text ) );
		if ( $text === '' ) {
			return '';
		}
		$cut = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 160 ) : substr( $text, 0, 160 );
		return sanitize_text_field( str_replace( array( "\n", "\r", "\t" ), ' ', $cut ) );
	}
}

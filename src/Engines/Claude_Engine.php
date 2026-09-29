<?php
/**
 * Anthropic Claude engine.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Engines;

use DXAI_UI\Compiler\Schema;
use DXAI_UI\Http\Remote_Client;

final class Claude_Engine extends Abstract_Engine {

	public function __construct(
		private readonly string $api_key,
		private readonly string $model = 'claude-sonnet-5',
	) {}

	public function get_id(): string {
		return 'claude';
	}

	public function get_label(): string {
		return 'Anthropic Claude';
	}

	public function test_connection(): bool|\WP_Error {
		if ( $this->api_key === '' ) {
			return new \WP_Error( 'dxai_ui_missing_key', __( 'Anthropic API key is not configured.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		$response = Remote_Client::post_json(
			'https://api.anthropic.com/v1/messages',
			$this->headers(),
			array(
				'model'      => $this->model,
				'max_tokens' => 32,
				'messages'   => array(
					array(
						'role'    => 'user',
						'content' => 'Reply with the single word pong.',
					),
				),
			),
			30
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response['code'] < 200 || $response['code'] >= 300 ) {
			return $this->http_error( $response['code'], $response['body'], __( 'Anthropic connection failed.', 'dxai-ui' ) );
		}

		return true;
	}

	public function generate( string $system, string $user, array $args = array() ): array|\WP_Error {
		if ( $this->api_key === '' ) {
			return new \WP_Error( 'dxai_ui_missing_key', __( 'Anthropic API key is not configured.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		$payload = array(
			'model'      => $this->model,
			'max_tokens' => isset( $args['max_tokens'] ) ? (int) $args['max_tokens'] : 8192,
			'system'     => $system,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
			'output_config' => array(
				'format' => array(
					'type'   => 'json_schema',
					'schema' => $this->schema(),
				),
			),
		);

		$response = Remote_Client::post_json( 'https://api.anthropic.com/v1/messages', $this->headers(), $payload );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response['code'] === 400 && str_contains( $response['body'], 'output_config' ) ) {
			unset( $payload['output_config'] );
			$response = Remote_Client::post_json( 'https://api.anthropic.com/v1/messages', $this->headers(), $payload );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		if ( $response['code'] < 200 || $response['code'] >= 300 ) {
			return $this->http_error( $response['code'], $response['body'], __( 'Claude generation failed.', 'dxai-ui' ) );
		}

		$decoded = Remote_Client::decode_body( $response['body'] );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$text = '';
		if ( isset( $decoded['content'] ) && is_array( $decoded['content'] ) ) {
			foreach ( $decoded['content'] as $block ) {
				if ( is_array( $block ) && ( $block['type'] ?? '' ) === 'text' ) {
					$text .= (string) ( $block['text'] ?? '' );
				}
			}
		}

		return $this->parse_generation( $text );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function schema(): array {
		return Schema::generation();
	}

	/**
	 * @return array<string, string>
	 */
	private function headers(): array {
		return array(
			'x-api-key'         => $this->api_key,
			'anthropic-version' => '2023-06-01',
		);
	}
}

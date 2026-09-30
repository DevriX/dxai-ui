<?php
/**
 * xAI Grok engine.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Engines;

use DXAI_UI\Compiler\Schema;
use DXAI_UI\Http\Remote_Client;

final class Grok_Engine extends Abstract_Engine {

	public function __construct(
		private readonly string $api_key,
		private readonly string $model = 'grok-4.6',
	) {}

	public function get_id(): string {
		return 'grok';
	}

	public function get_label(): string {
		return 'xAI Grok';
	}

	public function test_connection(): bool|\WP_Error {
		if ( $this->api_key === '' ) {
			return new \WP_Error( 'dxai_ui_missing_key', __( 'xAI API key is not configured.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		$response = Remote_Client::post_json(
			'https://api.x.ai/v1/chat/completions',
			$this->headers(),
			array(
				'model'                  => $this->model,
				'messages'               => array( array( 'role' => 'user', 'content' => 'Reply with the single word pong.' ) ),
				'max_completion_tokens'  => 16,
			),
			30
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response['code'] < 200 || $response['code'] >= 300 ) {
			return $this->http_error( $response['code'], $response['body'], __( 'Grok connection failed.', 'dxai-ui' ) );
		}

		return true;
	}

	public function generate( string $system, string $user, array $args = array() ): array|\WP_Error {
		if ( $this->api_key === '' ) {
			return new \WP_Error( 'dxai_ui_missing_key', __( 'xAI API key is not configured.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		$payload = array(
			'model'                 => $this->model,
			'messages'              => array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user ),
			),
			'max_completion_tokens' => isset( $args['max_tokens'] ) ? (int) $args['max_tokens'] : 8192,
			'response_format'       => array(
				'type'        => 'json_schema',
				'json_schema' => array(
					'name'   => 'dxai_ui_block',
					'strict' => true,
					'schema' => Schema::generation(),
				),
			),
		);

		$response = Remote_Client::post_json( 'https://api.x.ai/v1/chat/completions', $this->headers(), $payload );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response['code'] === 400 ) {
			$payload['response_format'] = array( 'type' => 'json_object' );
			$response                   = Remote_Client::post_json( 'https://api.x.ai/v1/chat/completions', $this->headers(), $payload );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		if ( $response['code'] < 200 || $response['code'] >= 300 ) {
			return $this->http_error( $response['code'], $response['body'], __( 'Grok generation failed.', 'dxai-ui' ) );
		}

		$decoded = Remote_Client::decode_body( $response['body'] );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		return $this->parse_generation( (string) ( $decoded['choices'][0]['message']['content'] ?? '' ) );
	}

	public function complete( string $system, string $user, array $args = array() ): string|\WP_Error {
		if ( $this->api_key === '' ) {
			return new \WP_Error( 'dxai_ui_missing_key', __( 'xAI API key is not configured.', 'dxai-ui' ), array( 'status' => 400 ) );
		}
		$response = Remote_Client::post_json(
			'https://api.x.ai/v1/chat/completions',
			$this->headers(),
			array(
				'model'                 => $this->model,
				'messages'              => array(
					array( 'role' => 'system', 'content' => $system ),
					array( 'role' => 'user', 'content' => $user ),
				),
				'max_completion_tokens' => isset( $args['max_tokens'] ) ? (int) $args['max_tokens'] : 8192,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( $response['code'] < 200 || $response['code'] >= 300 ) {
			return $this->http_error( $response['code'], $response['body'], __( 'Grok did not answer.', 'dxai-ui' ) );
		}
		$decoded = Remote_Client::decode_body( $response['body'] );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		return (string) ( $decoded['choices'][0]['message']['content'] ?? '' );
	}

	/**
	 * @return array<string, string>
	 */
	private function headers(): array {
		return array(
			'Authorization' => 'Bearer ' . $this->api_key,
		);
	}
}

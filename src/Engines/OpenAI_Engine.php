<?php
/**
 * OpenAI engine (Responses API).
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Engines;

use DXAI_UI\Compiler\Schema;
use DXAI_UI\Http\Remote_Client;

final class OpenAI_Engine extends Abstract_Engine {

	public function __construct(
		private readonly string $api_key,
		private readonly string $model = 'gpt-5.6',
	) {}

	public function get_id(): string {
		return 'openai';
	}

	public function get_label(): string {
		return 'OpenAI';
	}

	public function test_connection(): bool|\WP_Error {
		if ( $this->api_key === '' ) {
			return new \WP_Error( 'dxai_ui_missing_key', __( 'OpenAI API key is not configured.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		$response = Remote_Client::post_json(
			'https://api.openai.com/v1/responses',
			$this->headers(),
			array(
				'model'              => $this->model,
				'input'              => 'Reply with the single word pong.',
				'max_output_tokens'  => 32,
				'store'              => false,
			),
			30
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response['code'] < 200 || $response['code'] >= 300 ) {
			$fallback = Remote_Client::post_json(
				'https://api.openai.com/v1/chat/completions',
				$this->headers(),
				array(
					'model'      => $this->resolve_chat_model(),
					'messages'   => array( array( 'role' => 'user', 'content' => 'Reply with the single word pong.' ) ),
					'max_tokens' => 16,
				),
				30
			);
			if ( is_wp_error( $fallback ) ) {
				return $this->http_error( $response['code'], $response['body'], __( 'OpenAI connection failed.', 'dxai-ui' ) );
			}
			if ( $fallback['code'] < 200 || $fallback['code'] >= 300 ) {
				return $this->http_error( $fallback['code'], $fallback['body'], __( 'OpenAI connection failed.', 'dxai-ui' ) );
			}
		}

		return true;
	}

	public function generate( string $system, string $user, array $args = array() ): array|\WP_Error {
		if ( $this->api_key === '' ) {
			return new \WP_Error( 'dxai_ui_missing_key', __( 'OpenAI API key is not configured.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		$schema = Schema::generation();

		$payload = array(
			'model'             => $this->model,
			'instructions'      => $system,
			'input'             => $user,
			'max_output_tokens' => isset( $args['max_tokens'] ) ? (int) $args['max_tokens'] : 8192,
			'store'             => false,
			'text'              => array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => 'dxai_ui_block',
					'strict' => true,
					'schema' => $schema,
				),
			),
		);

		$response = Remote_Client::post_json( 'https://api.openai.com/v1/responses', $this->headers(), $payload );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response['code'] < 200 || $response['code'] >= 300 ) {
			$chat = Remote_Client::post_json(
				'https://api.openai.com/v1/chat/completions',
				$this->headers(),
				array(
					'model'           => $this->resolve_chat_model(),
					'messages'        => array(
						array( 'role' => 'system', 'content' => $system ),
						array( 'role' => 'user', 'content' => $user ),
					),
					'response_format' => array( 'type' => 'json_object' ),
				)
			);
			if ( is_wp_error( $chat ) ) {
				return $chat;
			}
			if ( $chat['code'] < 200 || $chat['code'] >= 300 ) {
				return $this->http_error( $chat['code'], $chat['body'], __( 'OpenAI generation failed.', 'dxai-ui' ) );
			}
			$decoded = Remote_Client::decode_body( $chat['body'] );
			if ( is_wp_error( $decoded ) ) {
				return $decoded;
			}
			$text = (string) ( $decoded['choices'][0]['message']['content'] ?? '' );

			return $this->parse_generation( $text );
		}

		$decoded = Remote_Client::decode_body( $response['body'] );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$text = '';
		if ( ! empty( $decoded['output_text'] ) && is_string( $decoded['output_text'] ) ) {
			$text = $decoded['output_text'];
		} elseif ( isset( $decoded['output'] ) && is_array( $decoded['output'] ) ) {
			foreach ( $decoded['output'] as $item ) {
				if ( ! is_array( $item ) || ! isset( $item['content'] ) || ! is_array( $item['content'] ) ) {
					continue;
				}
				foreach ( $item['content'] as $part ) {
					if ( is_array( $part ) && isset( $part['text'] ) ) {
						$text .= (string) $part['text'];
					}
				}
			}
		}

		return $this->parse_generation( $text );
	}

	public function complete( string $system, string $user, array $args = array() ): string|\WP_Error {
		if ( $this->api_key === '' ) {
			return new \WP_Error( 'dxai_ui_missing_key', __( 'OpenAI API key is not configured.', 'dxai-ui' ), array( 'status' => 400 ) );
		}
		$response = Remote_Client::post_json(
			'https://api.openai.com/v1/chat/completions',
			$this->headers(),
			array(
				'model'    => $this->resolve_chat_model(),
				'messages' => array(
					array( 'role' => 'system', 'content' => $system ),
					array( 'role' => 'user', 'content' => $user ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( $response['code'] < 200 || $response['code'] >= 300 ) {
			return $this->http_error( $response['code'], $response['body'], __( 'OpenAI did not answer.', 'dxai-ui' ) );
		}
		$decoded = Remote_Client::decode_body( $response['body'] );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		return (string) ( $decoded['choices'][0]['message']['content'] ?? '' );
	}

	private function resolve_chat_model(): string {
		$map = array(
			'gpt-4o'      => 'gpt-4o',
			'gpt-4o-mini' => 'gpt-4o-mini',
			'gpt-5.6'     => 'gpt-5.6',
		);

		return $map[ $this->model ] ?? $this->model;
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

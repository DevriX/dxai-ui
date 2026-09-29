<?php
/**
 * DeepSeek engine.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Engines;

use DXAI_UI\Compiler\Schema;
use DXAI_UI\Http\Remote_Client;

final class DeepSeek_Engine extends Abstract_Engine {

	public function __construct(
		private readonly string $api_key,
		private readonly string $model = 'deepseek-v4-pro',
	) {}

	public function get_id(): string {
		return 'deepseek';
	}

	public function get_label(): string {
		return 'DeepSeek';
	}

	public function test_connection(): bool|\WP_Error {
		if ( $this->api_key === '' ) {
			return new \WP_Error( 'dxai_ui_missing_key', __( 'DeepSeek API key is not configured.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		$response = Remote_Client::post_json(
			'https://api.deepseek.com/chat/completions',
			$this->headers(),
			array(
				'model'    => $this->model,
				'messages' => array( array( 'role' => 'user', 'content' => 'Reply with the single word pong.' ) ),
				'thinking' => array( 'type' => 'disabled' ),
			),
			30
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response['code'] < 200 || $response['code'] >= 300 ) {
			return $this->http_error( $response['code'], $response['body'], __( 'DeepSeek connection failed.', 'dxai-ui' ) );
		}

		return true;
	}

	public function generate( string $system, string $user, array $args = array() ): array|\WP_Error {
		if ( $this->api_key === '' ) {
			return new \WP_Error( 'dxai_ui_missing_key', __( 'DeepSeek API key is not configured.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		$max     = isset( $args['max_tokens'] ) ? (int) $args['max_tokens'] : 16384;
		$payload = array(
			'model'           => $this->model,
			'messages'        => array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user . "\n\nReturn a JSON object only." ),
			),
			'max_tokens'      => $max > 0 ? $max : 16384,
			'thinking'        => array( 'type' => 'disabled' ),
			'response_format' => array(
				'type'        => 'json_schema',
				'json_schema' => array(
					'name'   => 'dxai_ui_block',
					'strict' => true,
					'schema' => Schema::generation(),
				),
			),
		);

		$response = Remote_Client::post_json( 'https://api.deepseek.com/chat/completions', $this->headers(), $payload );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response['code'] === 400 && ( str_contains( $response['body'], 'model' ) || str_contains( strtolower( $response['body'] ), 'not found' ) ) && $this->model !== 'deepseek-chat' ) {
			$payload['model'] = 'deepseek-chat';
			$response         = Remote_Client::post_json( 'https://api.deepseek.com/chat/completions', $this->headers(), $payload );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		if ( $response['code'] === 400 ) {
			$payload['response_format'] = array( 'type' => 'json_object' );
			$response                   = Remote_Client::post_json( 'https://api.deepseek.com/chat/completions', $this->headers(), $payload );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		if ( $response['code'] < 200 || $response['code'] >= 300 ) {
			return $this->http_error( $response['code'], $response['body'], __( 'DeepSeek generation failed.', 'dxai-ui' ) );
		}

		$decoded = Remote_Client::decode_body( $response['body'] );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$text   = $this->message_text( $decoded );
		$finish = (string) ( $decoded['choices'][0]['finish_reason'] ?? '' );
		$parsed = $this->parse_generation( $text );
		if ( ! is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( $finish === 'length' ) {
			$payload['messages'][] = array(
				'role'    => 'assistant',
				'content' => $text,
			);
			$payload['messages'][] = array(
				'role'    => 'user',
				'content' => 'Continue the JSON object from the exact character where it was truncated. Output only the remainder.',
			);
			$cont = Remote_Client::post_json( 'https://api.deepseek.com/chat/completions', $this->headers(), $payload );
			if ( ! is_wp_error( $cont ) && $cont['code'] >= 200 && $cont['code'] < 300 ) {
				$again = Remote_Client::decode_body( $cont['body'] );
				if ( ! is_wp_error( $again ) ) {
					$parsed = $this->parse_generation( $text . $this->message_text( $again ) );
					if ( ! is_wp_error( $parsed ) ) {
						return $parsed;
					}
				}
			}
			return new \WP_Error(
				'dxai_ui_engine_parse',
				sprintf(
					/* translators: %s: original parse error */
					__( 'The model hit the output token limit before finishing JSON. %s', 'dxai-ui' ),
					$parsed->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		return $parsed;
	}

	/**
	 * @param array<string, mixed> $decoded
	 */
	private function message_text( array $decoded ): string {
		$message = is_array( $decoded['choices'][0]['message'] ?? null ) ? $decoded['choices'][0]['message'] : array();
		$content = $message['content'] ?? '';
		if ( is_array( $content ) ) {
			$text = '';
			foreach ( $content as $block ) {
				if ( is_string( $block ) ) {
					$text .= $block;
					continue;
				}
				if ( is_array( $block ) ) {
					$text .= (string) ( $block['text'] ?? $block['content'] ?? '' );
				}
			}
			$content = $text;
		}

		$content = is_string( $content ) ? $content : '';
		if ( trim( $content ) === '' ) {
			$content = (string) ( $message['reasoning_content'] ?? '' );
		}

		return $content;
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

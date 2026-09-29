<?php
/**
 * Shared HTTP client for provider APIs.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Http;

final class Remote_Client {

	public const DEFAULT_TIMEOUT = 120;

	/**
	 * @param array<string, string> $headers
	 * @param array<string, mixed>  $args
	 * @return array{code:int, body:string, headers:array<string, mixed>}|\WP_Error
	 */
	public static function request( string $method, string $url, array $headers = array(), string $body = '', array $args = array() ): array|\WP_Error {
		$method = strtoupper( $method );

		$request = array(
			'method'      => $method,
			'timeout'     => isset( $args['timeout'] ) ? (int) $args['timeout'] : self::DEFAULT_TIMEOUT,
			'httpversion' => '1.1',
			'sslverify'   => true,
			'headers'     => $headers,
			'body'        => $body,
		);

		$response = wp_remote_request( $url, $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		$headers = wp_remote_retrieve_headers( $response );
		$all     = is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers;

		return array(
			'code'    => $code,
			'body'    => $raw,
			'headers' => $all,
		);
	}

	/**
	 * @param array<string, string> $headers
	 * @param array<string, mixed>  $payload
	 * @return array{code:int, body:string, headers:array<string, mixed>}|\WP_Error
	 */
	public static function post_json( string $url, array $headers, array $payload, int $timeout = self::DEFAULT_TIMEOUT ): array|\WP_Error {
		$headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json';
		$encoded                   = wp_json_encode( $payload );
		if ( false === $encoded ) {
			return new \WP_Error( 'dxai_ui_json', __( 'Unable to encode JSON payload.', 'dxai-ui' ), array( 'status' => 500 ) );
		}

		return self::request( 'POST', $url, $headers, $encoded, array( 'timeout' => $timeout ) );
	}

	/**
	 * @param array<string, mixed> $json
	 */
	public static function decode_body( string $body ): array|\WP_Error {
		$trimmed = trim( $body );
		$trimmed = ltrim( $trimmed, "\n\r\t " );
		$decoded = json_decode( $trimmed, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'dxai_ui_bad_json', __( 'Provider returned invalid JSON.', 'dxai-ui' ), array( 'status' => 502 ) );
		}

		return $decoded;
	}
}

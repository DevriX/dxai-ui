<?php
/**
 * Figma REST + ZIP connector.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Connectors;

use DXAI_UI\Http\Remote_Client;
use DXAI_UI\Media\Asset_Harvester;
use DXAI_UI\Media\Sideloader;
use DXAI_UI\Settings\Options;

final class Figma_Connector {

	public function test_token( string $token = '' ): bool|\WP_Error {
		$token = $token !== '' ? $token : Options::get_secret( 'figma_api_token' );
		if ( $token === '' ) {
			return new \WP_Error( 'dxai_ui_figma', __( 'Figma token is not configured.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		$response = Remote_Client::request(
			'GET',
			'https://api.figma.com/v1/me',
			array( 'X-Figma-Token' => $token ),
			'',
			array( 'timeout' => 20 )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response['code'] < 200 || $response['code'] >= 300 ) {
			return new \WP_Error( 'dxai_ui_figma', __( 'Figma rejected the access token.', 'dxai-ui' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * @return array{file_key:string, node_id:string}|\WP_Error
	 */
	public static function parse_url( string $url ): array|\WP_Error {
		$url = esc_url_raw( trim( $url ) );
		if ( $url === '' ) {
			return new \WP_Error( 'dxai_ui_figma', __( 'A Figma file URL is required.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$parts = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );

		$file_key = '';
		foreach ( array( 'design', 'file', 'proto', 'board' ) as $segment ) {
			$index = array_search( $segment, $parts, true );
			if ( false !== $index && isset( $parts[ $index + 1 ] ) ) {
				$file_key = $parts[ $index + 1 ];
				break;
			}
		}

		if ( $file_key === '' ) {
			return new \WP_Error( 'dxai_ui_figma', __( 'Could not parse a Figma file key from the URL.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		parse_str( $query, $params );
		$node = '';
		if ( isset( $params['node-id'] ) ) {
			$node = (string) $params['node-id'];
		} elseif ( isset( $params['node_id'] ) ) {
			$node = (string) $params['node_id'];
		}

		$node = rawurldecode( $node );
		$node = str_replace( '-', ':', $node );

		return array(
			'file_key' => sanitize_text_field( $file_key ),
			'node_id'  => sanitize_text_field( $node ),
		);
	}

	public function fetch_from_api( string $url, string $file_key = '', string $node_id = '' ): Source_Document|\WP_Error {
		$token = Options::get_secret( 'figma_api_token' );
		if ( $token === '' ) {
			return new \WP_Error( 'dxai_ui_figma', __( 'Figma token is not configured.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		if ( $file_key === '' || $node_id === '' ) {
			$parsed = self::parse_url( $url );
			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}
			$file_key = $parsed['file_key'];
			$node_id  = $parsed['node_id'];
		}

		$endpoint = sprintf(
			'https://api.figma.com/v1/files/%s/nodes?ids=%s&depth=8',
			rawurlencode( $file_key ),
			rawurlencode( $node_id )
		);

		$response = Remote_Client::request(
			'GET',
			$endpoint,
			array( 'X-Figma-Token' => $token ),
			'',
			array( 'timeout' => 60 )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response['code'] === 429 ) {
			$retry = $this->retry_after_seconds( is_array( $response['headers'] ) ? $response['headers'] : array() );

			return new \WP_Error(
				'dxai_ui_figma',
				sprintf(
					/* translators: %d: seconds to wait */
					__( 'Figma rate limit reached. Retry in %d seconds.', 'dxai-ui' ),
					$retry
				),
				array(
					'status'      => 429,
					'retry_after' => $retry,
				)
			);
		}

		if ( $response['code'] < 200 || $response['code'] >= 300 ) {
			return new \WP_Error( 'dxai_ui_figma', __( 'Figma file request failed. Check the token, file key, and sharing.', 'dxai-ui' ), array( 'status' => $response['code'] ) );
		}

		$decoded = Remote_Client::decode_body( $response['body'] );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$node_payload = $decoded['nodes'][ $node_id ] ?? null;
		if ( ! is_array( $node_payload ) ) {
			return new \WP_Error( 'dxai_ui_figma', __( 'Figma returned no node for that ID.', 'dxai-ui' ), array( 'status' => 404 ) );
		}

		$pruned  = $this->prune_node( $node_payload['document'] ?? array() );
		$images  = $this->collect_image_refs( $pruned );
		$assets  = $this->sideload_images( $file_key, $token, $images, $node_id );
		$harvest = ( new Asset_Harvester() )->from_figma( $pruned, $assets );
		$summary = is_array( $harvest['summary'] ?? null ) ? $harvest['summary'] : array();

		$title = isset( $decoded['name'] ) ? sanitize_text_field( (string) $decoded['name'] ) : 'Figma';

		return new Source_Document(
			'figma-api',
			$title,
			array(
				'file_key' => $file_key,
				'node_id'  => $node_id,
				'name'     => $title,
				'document' => $pruned,
				'assets'   => $assets,
				'harvest'  => Asset_Harvester::for_prompt( $harvest ),
			),
			$assets,
			array(
				'Fetched Figma node ' . $node_id,
				sprintf(
					'Harvested assets: %d images, %d fonts, %d links',
					(int) ( $summary['images'] ?? 0 ),
					(int) ( $summary['fonts'] ?? 0 ),
					(int) ( $summary['links'] ?? 0 )
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $file
	 */
	public function import_zip( array $file ): Source_Document|\WP_Error {
		$unpacked = Zip_Extractor::unpack( $file );
		if ( is_wp_error( $unpacked ) ) {
			return $unpacked;
		}

		$html    = array();
		$css     = array();
		$harvest = ( new Asset_Harvester() )->from_zip( $unpacked['dir'], $unpacked['files'] );
		$texts   = is_array( $harvest['texts'] ?? null ) ? $harvest['texts'] : array();
		$assets  = is_array( $harvest['assets'] ?? null ) ? $harvest['assets'] : array();
		$summary = is_array( $harvest['summary'] ?? null ) ? $harvest['summary'] : array();

		foreach ( $unpacked['files'] as $relative ) {
			$norm = str_replace( '\\', '/', $relative );
			$ext  = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
			$body = (string) ( $texts[ $norm ] ?? '' );
			if ( in_array( $ext, array( 'html', 'css' ), true ) ) {
				$content = $body !== '' ? $body : Zip_Extractor::read_text( $unpacked['dir'] . '/' . $relative );
				if ( $ext === 'html' ) {
					$html[ $relative ] = $content;
				} else {
					$css[ $relative ] = $content;
				}
			}
		}

		Zip_Extractor::cleanup( $unpacked['dir'] );

		return new Source_Document(
			'figma-zip',
			'Figma ZIP',
			array(
				'html'    => $html,
				'css'     => $css,
				'assets'  => $assets,
				'harvest' => Asset_Harvester::for_prompt( $harvest ),
			),
			$assets,
			array(
				'Unpacked ' . count( $unpacked['files'] ) . ' Figma export files',
				sprintf(
					'Harvested assets: %d images, %d videos, %d fonts, %d links',
					(int) ( $summary['images'] ?? 0 ),
					(int) ( $summary['videos'] ?? 0 ),
					(int) ( $summary['fonts'] ?? 0 ),
					(int) ( $summary['links'] ?? 0 )
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 */
	private function prune_node( mixed $node ): array {
		if ( ! is_array( $node ) ) {
			return array();
		}

		$keep = array(
			'id'                      => $node['id'] ?? '',
			'name'                    => $node['name'] ?? '',
			'type'                    => $node['type'] ?? '',
			'visible'                 => $node['visible'] ?? true,
			'opacity'                 => $node['opacity'] ?? 1,
			'absoluteBoundingBox'     => $node['absoluteBoundingBox'] ?? null,
			'layoutMode'              => $node['layoutMode'] ?? null,
			'layoutWrap'              => $node['layoutWrap'] ?? null,
			'itemSpacing'             => $node['itemSpacing'] ?? null,
			'paddingLeft'             => $node['paddingLeft'] ?? null,
			'paddingRight'            => $node['paddingRight'] ?? null,
			'paddingTop'              => $node['paddingTop'] ?? null,
			'paddingBottom'           => $node['paddingBottom'] ?? null,
			'primaryAxisAlignItems'   => $node['primaryAxisAlignItems'] ?? null,
			'counterAxisAlignItems'   => $node['counterAxisAlignItems'] ?? null,
			'clipsContent'            => $node['clipsContent'] ?? null,
			'cornerRadius'            => $node['cornerRadius'] ?? null,
			'characters'              => isset( $node['characters'] ) ? substr( (string) $node['characters'], 0, 4000 ) : null,
			'style'                   => $node['style'] ?? null,
			'fills'                   => $this->prune_fills( $node['fills'] ?? array() ),
			'strokes'                 => $node['strokes'] ?? null,
			'strokeWeight'            => $node['strokeWeight'] ?? null,
			'effects'                 => $node['effects'] ?? null,
			'hyperlink'               => $node['hyperlink'] ?? null,
			'constraints'             => $node['constraints'] ?? null,
			'layoutSizingHorizontal'  => $node['layoutSizingHorizontal'] ?? null,
			'layoutSizingVertical'    => $node['layoutSizingVertical'] ?? null,
			'minWidth'                => $node['minWidth'] ?? null,
			'maxWidth'                => $node['maxWidth'] ?? null,
			'minHeight'               => $node['minHeight'] ?? null,
			'maxHeight'               => $node['maxHeight'] ?? null,
			'rectangleCornerRadii'    => $node['rectangleCornerRadii'] ?? null,
		);

		$keep = array_filter(
			$keep,
			static fn( $value ) => null !== $value && $value !== ''
		);

		if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
			$children = array();
			foreach ( array_slice( $node['children'], 0, 120 ) as $child ) {
				$pruned = $this->prune_node( $child );
				if ( $pruned !== array() ) {
					$children[] = $pruned;
				}
			}
			$keep['children'] = $children;
		}

		return $keep;
	}

	/**
	 * @param mixed $fills
	 * @return array<int, array<string, mixed>>
	 */
	private function prune_fills( mixed $fills ): array {
		if ( ! is_array( $fills ) ) {
			return array();
		}

		$out = array();
		foreach ( $fills as $fill ) {
			if ( ! is_array( $fill ) ) {
				continue;
			}
			$out[] = array(
				'type'      => $fill['type'] ?? '',
				'opacity'   => $fill['opacity'] ?? 1,
				'color'     => $fill['color'] ?? null,
				'imageRef'  => $fill['imageRef'] ?? null,
				'videoRef'  => $fill['videoRef'] ?? null,
				'scaleMode' => $fill['scaleMode'] ?? null,
			);
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $node
	 * @return array<int, string>
	 */
	private function collect_image_refs( array $node ): array {
		$refs = array();
		if ( isset( $node['fills'] ) && is_array( $node['fills'] ) ) {
			foreach ( $node['fills'] as $fill ) {
				if ( is_array( $fill ) && ! empty( $fill['imageRef'] ) ) {
					$refs[] = (string) $fill['imageRef'];
				}
			}
		}
		if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
			foreach ( $node['children'] as $child ) {
				if ( is_array( $child ) ) {
					$refs = array_merge( $refs, $this->collect_image_refs( $child ) );
				}
			}
		}

		return array_values( array_unique( $refs ) );
	}

	/**
	 * @param array<int, string> $image_refs
	 * @return array<int, array{path:string, url:string}>
	 */
	private function sideload_images( string $file_key, string $token, array $image_refs, string $node_id ): array {
		$assets = array();

		$render = Remote_Client::request(
			'GET',
			sprintf( 'https://api.figma.com/v1/images/%s?ids=%s&format=png&scale=1', rawurlencode( $file_key ), rawurlencode( $node_id ) ),
			array( 'X-Figma-Token' => $token ),
			'',
			array( 'timeout' => 60 )
		);

		if ( ! is_wp_error( $render ) && $render['code'] >= 200 && $render['code'] < 300 ) {
			$decoded = json_decode( $render['body'], true );
			$url     = $decoded['images'][ $node_id ] ?? null;
			if ( is_string( $url ) && $url !== '' ) {
				$sideloaded = Sideloader::from_url( $url, $node_id . '.png' );
				if ( ! is_wp_error( $sideloaded ) ) {
					$assets[] = $sideloaded;
				}
			}
		}

		if ( $image_refs === array() ) {
			return $assets;
		}

		$fills = Remote_Client::request(
			'GET',
			sprintf( 'https://api.figma.com/v1/files/%s/images', rawurlencode( $file_key ) ),
			array( 'X-Figma-Token' => $token ),
			'',
			array( 'timeout' => 60 )
		);

		if ( is_wp_error( $fills ) || $fills['code'] < 200 || $fills['code'] >= 300 ) {
			return $assets;
		}

		$map = json_decode( $fills['body'], true );
		$urls = is_array( $map['images'] ?? null ) ? $map['images'] : array();
		foreach ( $image_refs as $ref ) {
			if ( empty( $urls[ $ref ] ) || ! is_string( $urls[ $ref ] ) ) {
				continue;
			}
			$sideloaded = Sideloader::from_url( $urls[ $ref ], $ref . '.png' );
			if ( ! is_wp_error( $sideloaded ) ) {
				$assets[] = $sideloaded;
			}
		}

		return $assets;
	}

	/**
	 * @param array<string, mixed> $headers
	 */
	private function retry_after_seconds( array $headers ): int {
		$raw = '';
		foreach ( $headers as $name => $value ) {
			if ( 'retry-after' !== strtolower( (string) $name ) ) {
				continue;
			}
			$raw = is_array( $value ) ? (string) ( $value[0] ?? '' ) : (string) $value;
			break;
		}

		$raw = trim( $raw );
		if ( $raw !== '' && is_numeric( $raw ) ) {
			return max( 1, (int) $raw );
		}

		if ( $raw !== '' ) {
			$ts = strtotime( $raw );
			if ( false !== $ts ) {
				return max( 1, $ts - time() );
			}
		}

		return 30;
	}
}

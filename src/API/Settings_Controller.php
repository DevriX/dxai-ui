<?php
/**
 * Settings REST controller.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\API;

use DXAI_UI\Connectors\Figma_Connector;
use DXAI_UI\Engines\Engine_Factory;
use DXAI_UI\Settings\Options;
use WP_REST_Request;
use WP_REST_Response;

final class Settings_Controller extends \WP_REST_Controller {

	public function __construct() {
		$this->namespace = DXAI_UI_REST_NAMESPACE;
		$this->rest_base = 'settings';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_provider' ),
				'permission_callback' => array( $this, 'permissions' ),
				'args'                => array(
					'provider' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	public function permissions(): bool {
		return current_user_can( 'manage_options' );
	}

	public function get_settings(): WP_REST_Response {
		return rest_ensure_response(
			array(
				'settings' => Options::get_public(),
				'catalog'  => Engine_Factory::catalog(),
			)
		);
	}

	public function save_settings( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		Options::save( is_array( $params ) ? $params : array() );

		$test = $request->get_param( 'test_provider' );
		$result = null;
		if ( is_string( $test ) && $test !== '' ) {
			$result = $this->run_test( $test );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return rest_ensure_response(
			array(
				'settings' => Options::get_public(),
				'test'     => $result,
			)
		);
	}

	public function test_provider( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$provider = (string) $request->get_param( 'provider' );
		$result   = $this->run_test( $provider );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'ok' => true, 'provider' => $provider ) );
	}

	private function run_test( string $provider ): true|\WP_Error {
		if ( $provider === 'figma' ) {
			return ( new Figma_Connector() )->test_token();
		}

		if ( $provider === 'lovable' ) {
			return new \WP_Error(
				'dxai_ui_lovable_api',
				__( 'Lovable has no public source API. Use ZIP or paste instead.', 'dxai-ui' ),
				array( 'status' => 501 )
			);
		}

		$engine = Engine_Factory::make( $provider );
		if ( is_wp_error( $engine ) ) {
			return $engine;
		}

		return $engine->test_connection();
	}
}

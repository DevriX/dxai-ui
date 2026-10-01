<?php
/**
 * REST: page speed of the imported designs.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\API;

use DXAI_UI\Structures\Design_Attach;
use DXAI_UI\Support\Speed;

/**
 * GET  /speed  per design: where its fonts come from (Speed::fonts()), and whether the theme's stylesheet is trimmed.
 * POST /speed  copy a design's fonts here (fonts-apply) or put them back (fonts-revert); keep its own fonts (fonts-own) or draw it
 *              in the theme's (fonts-theme); turn the trimming on or off
 *              (trim-on, trim-off).
 */
final class Speed_Controller {

	public function register_routes(): void {
		register_rest_route(
			DXAI_UI_REST_NAMESPACE,
			'/speed',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run' ),
					'permission_callback' => array( $this, 'permissions' ),
					'args'                => array(
						'action' => array(
							'type'     => 'string',
							'enum'     => array( 'fonts-apply', 'fonts-revert', 'fonts-own', 'fonts-theme', 'trim-on', 'trim-off' ),
							'required' => true,
						),
						'design' => array(
							'type'    => 'integer',
							'default' => 0,
						),
					),
				),
			)
		);
	}

	public function permissions(): bool|\WP_Error {
		return ( new Converter_Controller() )->publish_permissions();
	}

	public function index(): \WP_REST_Response {
		return new \WP_REST_Response( $this->report() );
	}

	public function run( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$action = (string) $request->get_param( 'action' );
		if ( str_starts_with( $action, 'trim-' ) ) {
			Speed::set_trim( $action === 'trim-on' );

			return new \WP_REST_Response( $this->report() );
		}
		$home = (int) $request->get_param( 'design' );
		if ( ! Design_Attach::is_design( $home ) || ! current_user_can( 'edit_post', $home ) ) {
			return new \WP_Error( 'dxai_ui_design', __( 'That is not an imported design.', 'dxai-ui' ), array( 'status' => 404 ) );
		}
		if ( $action === 'fonts-revert' ) {
			$done = array( 'changed' => Speed::revert_fonts( $home ), 'complete' => true, 'note' => '' );
		} elseif ( $action === 'fonts-own' ) {
			$done = Speed::own_fonts( $home );
		} elseif ( $action === 'fonts-theme' ) {
			$done = Speed::theme_fonts( $home );
		} else {
			$done = Speed::apply_fonts( $home );
		}

		return new \WP_REST_Response( $this->report() + array( 'done' => $done ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function report(): array {
		return array(
			'theme'   => wp_get_theme()->get( 'Name' ),
			'designs' => array_map(
				static fn( $page ) => array(
					'id'    => (int) $page->ID,
					'title' => get_the_title( $page ),
					'fonts' => Speed::fonts( (int) $page->ID ),
				),
				Design_Attach::designs()
			),
			'trim'    => Speed::trim(),
		);
	}
}

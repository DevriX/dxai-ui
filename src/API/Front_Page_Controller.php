<?php
/**
 * REST: the imported Home as the site's front page.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\API;

use DXAI_UI\Network\Front_Page;

/**
 * GET  /front-page?home=<id>   what the front page is now for that Home (0: before one is saved; Network\Front_Page::state()).
 * POST /front-page             `adopt` (the Home becomes the front page when the site has none of its own), `force` (over the
 *                              site's own), `restore` (the front page as it was).
 *
 * Asked by the import, which publishes a design: the same permission (manage_options and unfiltered_html).
 */
final class Front_Page_Controller {

	public function register_routes(): void {
		register_rest_route(
			DXAI_UI_REST_NAMESPACE,
			'/front-page',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'permissions' ),
					'args'                => array(
						// 0: what the front page is before a Home is saved.
						'home'    => array(
							'type'    => 'integer',
							'default' => 0,
						),
						// With home 0: the archive the Home comes from (the import updates a front page imported from it).
						'archive' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'change' ),
					'permission_callback' => array( $this, 'permissions' ),
					'args'                => array(
						'home'   => array(
							'type'    => 'integer',
							'default' => 0,
						),
						'action' => array(
							'type'     => 'string',
							'enum'     => array( 'adopt', 'force', 'restore' ),
							'required' => true,
						),
					),
				),
			)
		);
	}

	public function permissions(): bool|\WP_Error {
		return ( new Converter_Controller() )->publish_permissions();
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$home = (int) $request->get_param( 'home' );
		if ( $home !== 0 && get_post_type( $home ) !== 'page' ) {
			return new \WP_Error( 'dxai_front_home', __( 'There is no such page.', 'dxai-ui' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( Front_Page::state( $home, (string) $request->get_param( 'archive' ) ) );
	}

	public function change( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$action = (string) $request->get_param( 'action' );
		if ( $action === 'restore' ) {
			return Front_Page::restore()
				? rest_ensure_response( array( 'ok' => true ) )
				: new \WP_Error( 'dxai_front_restore', __( 'The front page was changed since; it is left as it is.', 'dxai-ui' ), array( 'status' => 409 ) );
		}
		$home = (int) $request->get_param( 'home' );
		if ( get_post_type( $home ) !== 'page' || get_post_status( $home ) !== 'publish' ) {
			return new \WP_Error( 'dxai_front_home', __( 'That page is not a published page.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( Front_Page::adopt( $home, $action === 'force' ) );
	}
}

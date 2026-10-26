<?php
/**
 * REST: the whole site seen before its pages go live.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\API;

use DXAI_UI\Network\Site_Review;
use DXAI_UI\Structures\Design_Attach;

/**
 * GET  /site-review?home=<id>   the Home and the pages of its design, each with its status, its preview and how it measures.
 * POST /site-review             `hold` (the pages an import makes from now on are drafts), `decide` (`publish` these, `drop`
 *                               those the import made; the hold ends), `release` (the hold ends, nothing else changes).
 *
 * Asked by the import, which publishes a design: the same permission (manage_options and unfiltered_html).
 */
final class Site_Review_Controller {

	public function register_routes(): void {
		register_rest_route(
			DXAI_UI_REST_NAMESPACE,
			'/site-review',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'permissions' ),
					'args'                => array(
						'home' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'change' ),
					'permission_callback' => array( $this, 'permissions' ),
					'args'                => array(
						'action'  => array(
							'type'     => 'string',
							'enum'     => array( 'hold', 'decide', 'release' ),
							'required' => true,
						),
						'home'    => array(
							'type'    => 'integer',
							'default' => 0,
						),
						'publish' => array(
							'type'    => 'array',
							'default' => array(),
						),
						'drop'    => array(
							'type'    => 'array',
							'default' => array(),
						),
						// With hold: the archive whose design's pages are held (none: every import's).
						'archive' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
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
		if ( ! Design_Attach::is_design( $home ) ) {
			return new \WP_Error( 'dxai_review_home', __( 'That is not an imported design.', 'dxai-ui' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( Site_Review::pages( $home ) );
	}

	public function change( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$action = (string) $request->get_param( 'action' );
		if ( $action === 'hold' ) {
			Site_Review::hold( (string) $request->get_param( 'archive' ) );

			return rest_ensure_response( array( 'holding' => true ) );
		}
		if ( $action === 'release' ) {
			Site_Review::release();

			return rest_ensure_response( array( 'holding' => false ) );
		}
		$home = (int) $request->get_param( 'home' );
		if ( ! Design_Attach::is_design( $home ) ) {
			return new \WP_Error( 'dxai_review_home', __( 'That is not an imported design.', 'dxai-ui' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( Site_Review::decide( $home, (array) $request->get_param( 'publish' ), (array) $request->get_param( 'drop' ) ) );
	}
}

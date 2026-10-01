<?php
/**
 * REST: native blocks in a design's pages.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\API;

use DXAI_UI\Blocks\Native\Native_Blocks;
use DXAI_UI\Structures\Design_Attach;

/**
 * GET  /native-blocks  per design: which of the plugin's own blocks have a native counterpart on this site, how many
 *                      the pages hold, and whether they were converted (Native_Blocks::summary()).
 * POST /native-blocks  convert a design's pages (apply) or put the plugin's own blocks back (revert).
 */
final class Native_Blocks_Controller {

	public function register_routes(): void {
		register_rest_route(
			DXAI_UI_REST_NAMESPACE,
			'/native-blocks',
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
						'design' => array(
							'type'     => 'integer',
							'required' => true,
						),
						'action' => array(
							'type'     => 'string',
							'enum'     => array( 'apply', 'revert' ),
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

	public function index(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'theme'   => wp_get_theme()->get( 'Name' ),
				'designs' => array_map( fn( $page ) => $this->report( (int) $page->ID ), Design_Attach::designs() ),
			)
		);
	}

	public function run( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$home = (int) $request->get_param( 'design' );
		if ( ! Design_Attach::is_design( $home ) || ! current_user_can( 'edit_post', $home ) ) {
			return new \WP_Error( 'dxai_ui_design', __( 'That is not an imported design.', 'dxai-ui' ), array( 'status' => 404 ) );
		}
		$done = $request->get_param( 'action' ) === 'revert' ? Native_Blocks::revert( $home ) : Native_Blocks::apply( $home );

		return new \WP_REST_Response( $this->report( $home ) + array( 'done' => $done ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function report( int $home ): array {
		return array(
			'id'    => $home,
			'title' => html_entity_decode( get_the_title( $home ), ENT_QUOTES, 'UTF-8' ),
		) + Native_Blocks::summary( $home );
	}
}

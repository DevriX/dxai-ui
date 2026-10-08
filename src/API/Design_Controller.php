<?php
/**
 * REST: a design's document (the Design IR).
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\API;

use DXAI_UI\Design\Document_Store;
use DXAI_UI\Structures\Design_Attach;

/**
 * GET  /design                 every design on the site: its Home, its title, and its document's summary (or that it has none, and why).
 * GET  /design/{id}            the document of the design whose Home (or any page) has that id, with its summary.
 * GET  /design/{id}?summary=1  the summary alone.
 * POST /design/{id}            rebuild: build the document again from the design's conversion snapshot (Document_Store::rebuild()),
 *                              for a design imported before documents were kept or whose document was lost.
 *
 * The reads are for someone who manages the site (manage_options): the document holds no markup, no CSS and no script of the
 * design, only its facts (docs/PLAN-ARCHITECTURE.md, section 4). The rebuild writes the design's Home, so it asks what the import
 * routes ask (Converter_Controller::publish_permissions()). 404 for a page that is no design's or a design that has no document.
 */
final class Design_Controller {

	public function register_routes(): void {
		register_rest_route(
			DXAI_UI_REST_NAMESPACE,
			'/design',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
			)
		);
		register_rest_route(
			DXAI_UI_REST_NAMESPACE,
			'/design/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'permissions' ),
					'args'                => array(
						'id'      => array(
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 1,
						),
						'summary' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run' ),
					'permission_callback' => array( $this, 'write_permissions' ),
					'args'                => array(
						'id'     => array(
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 1,
						),
						'action' => array(
							'type'     => 'string',
							'enum'     => array( 'rebuild' ),
							'required' => true,
						),
					),
				),
			)
		);
	}

	public function permissions(): bool|\WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new \WP_Error( 'dxai_ui_forbidden', __( 'You are not allowed to read designs on this site.', 'dxai-ui' ), array( 'status' => rest_authorization_required_code() ) );
	}

	public function write_permissions(): bool|\WP_Error {
		return ( new Converter_Controller() )->publish_permissions();
	}

	public function index(): \WP_REST_Response {
		$out = array();
		foreach ( Design_Attach::designs() as $post ) {
			$id  = (int) $post->ID;
			$doc = Document_Store::load( $id );
			$out[] = array(
				'id'       => $id,
				'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'edit'     => get_edit_post_link( $id, 'raw' ),
				'document' => $doc !== null,
				'summary'  => $doc?->summary(),
				'error'    => $doc === null ? Document_Store::error( $id ) : '',
			);
		}

		return new \WP_REST_Response( array( 'designs' => $out ) );
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id   = (int) $request->get_param( 'id' );
		$home = Document_Store::home_of( $id );
		if ( $home < 1 ) {
			return new \WP_Error( 'dxai_ui_no_design', __( 'No design has a page with this id.', 'dxai-ui' ), array( 'status' => 404 ) );
		}
		$doc = Document_Store::load( $home );
		if ( $doc === null ) {
			return $this->none( $home );
		}

		return new \WP_REST_Response( $this->answer( $home, $doc, ! filter_var( $request->get_param( 'summary' ), FILTER_VALIDATE_BOOLEAN ) ) );
	}

	public function run( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id   = (int) $request->get_param( 'id' );
		$home = Document_Store::home_of( $id );
		if ( $home < 1 ) {
			return new \WP_Error( 'dxai_ui_no_design', __( 'No design has a page with this id.', 'dxai-ui' ), array( 'status' => 404 ) );
		}
		$doc = Document_Store::rebuild( $home );
		if ( is_wp_error( $doc ) ) {
			$doc->add_data( array( 'status' => 'dxai_ui_no_snapshot' === $doc->get_error_code() ? 409 : 422 ) );

			return $doc;
		}

		return new \WP_REST_Response( $this->answer( $home, $doc, true ) + array( 'rebuilt' => true ) );
	}

	/** @return array<string, mixed> */
	private function answer( int $home, \DXAI_UI\Design\Document $doc, bool $whole ): array {
		$out = array(
			'design'  => $home,
			'title'   => html_entity_decode( get_the_title( $home ), ENT_QUOTES, 'UTF-8' ),
			'edit'    => get_edit_post_link( $home, 'raw' ),
			'summary' => $doc->summary(),
		);
		if ( $whole ) {
			$out['document'] = $doc->to_array();
		}

		return $out;
	}

	private function none( int $home ): \WP_Error {
		$why = Document_Store::error( $home );

		return new \WP_Error(
			'dxai_ui_no_document',
			$why !== ''
				/* translators: %s: what went wrong. */
				? sprintf( __( 'The design has no document: %s', 'dxai-ui' ), $why )
				: __( 'The design has no document yet: it was imported before documents were kept. Importing it again writes one, or rebuild it from its conversion snapshot.', 'dxai-ui' ),
			array( 'status' => 404 )
		);
	}
}

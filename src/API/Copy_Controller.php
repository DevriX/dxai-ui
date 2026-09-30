<?php
/**
 * REST: the prompt for the words of a page, and the words an AI writes from it.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\API;

use DXAI_UI\Pages\Copy_Prompt;
use DXAI_UI\Pages\Copy_Writer;
use DXAI_UI\Structures\Design_Attach;

/**
 * GET  /copy                  the designs, and the pages of one (`design`) with what their prompts would say.
 * GET  /copy/<page>           one page: its fields, the prompt, and whether words can be put back.
 * POST /copy/<page>           `save` the topic and the reference address, `generate` words with the engine chosen in
 *                             Settings (nothing is saved), `apply` some of them, `revert` the page's words.
 */
final class Copy_Controller {

	public function register_routes(): void {
		register_rest_route(
			DXAI_UI_REST_NAMESPACE,
			'/copy',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'permissions' ),
				'args'                => array(
					'design' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);
		register_rest_route(
			DXAI_UI_REST_NAMESPACE,
			'/copy/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'permissions' ),
					'args'                => array(
						'facts' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run' ),
					'permission_callback' => array( $this, 'permissions' ),
					'args'                => array(
						'action' => array(
							'type'     => 'string',
							'enum'     => array( 'save', 'generate', 'apply', 'revert' ),
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

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$designs = array_map(
			static fn( $page ) => array(
				'id'    => (int) $page->ID,
				'title' => html_entity_decode( get_the_title( $page ), ENT_QUOTES, 'UTF-8' ),
			),
			Design_Attach::designs()
		);
		$design = (int) $request->get_param( 'design' );
		if ( $design < 1 && $designs !== array() ) {
			// The design the site wears comes first; else the first one.
			$design = (int) $designs[0]['id'];
			foreach ( $designs as $d ) {
				if ( \DXAI_UI\Compiler\Token_Styles::is_brand( (int) $d['id'] ) ) {
					$design = (int) $d['id'];
					break;
				}
			}
		}

		return new \WP_REST_Response(
			array(
				'designs' => $designs,
				'design'  => $design,
				'pages'   => $design > 0 && Design_Attach::is_design( $design ) ? $this->rows( $design ) : array(),
				'engine'  => $this->engine_state(),
			)
		);
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = $this->page( $request );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return new \WP_REST_Response( $this->page_report( $id, (bool) $request->get_param( 'facts' ) ) );
	}

	public function run( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = $this->page( $request );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$action   = (string) $request->get_param( 'action' );
		$override = array(
			'topic'     => (string) $request->get_param( 'topic' ),
			'reference' => $request->get_param( 'reference' ) === null ? null : (string) $request->get_param( 'reference' ),
		);
		if ( $override['reference'] === null ) {
			unset( $override['reference'] );
		}
		$facts = (bool) $request->get_param( 'facts' );

		if ( $action === 'save' ) {
			Copy_Prompt::save( $id, $override );

			return new \WP_REST_Response( $this->page_report( $id, $facts ) );
		}
		if ( $action === 'generate' ) {
			$out = Copy_Writer::propose( $id, $override, $facts );
			if ( is_wp_error( $out ) ) {
				return $out;
			}

			return new \WP_REST_Response( $out );
		}
		if ( $action === 'apply' ) {
			$changes = array();
			foreach ( (array) $request->get_param( 'changes' ) as $key => $text ) {
				if ( is_string( $key ) && preg_match( '/^t\d+$/', $key ) === 1 && is_string( $text ) ) {
					$changes[ $key ] = $text;
				}
			}
			$done = Copy_Writer::apply( $id, $changes, (string) $request->get_param( 'fingerprint' ) );
			if ( is_wp_error( $done ) ) {
				return $done;
			}

			return new \WP_REST_Response( $this->page_report( $id, $facts ) + array( 'applied' => $done['applied'] ) );
		}

		$ok = Copy_Writer::revert( $id );
		if ( ! $ok ) {
			return new \WP_Error( 'dxai_ui_copy_revert', __( 'There is nothing to put back, or the page was edited after the words were applied.', 'dxai-ui' ), array( 'status' => 409 ) );
		}

		return new \WP_REST_Response( $this->page_report( $id, $facts ) + array( 'reverted' => true ) );
	}

	/**
	 * @return int|\WP_Error
	 */
	private function page( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( $id < 1 || get_post_type( $id ) !== 'page' || ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'dxai_ui_copy_page', __( 'That page cannot be edited.', 'dxai-ui' ), array( 'status' => 404 ) );
		}

		return $id;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function rows( int $design ): array {
		return array_map(
			static fn( $row ) => $row + array(
				'blocks'  => count( Copy_Writer::inventory( (int) $row['id'] ) ),
				'revert'  => Copy_Writer::can_revert( (int) $row['id'] ),
				'edit'    => (string) get_edit_post_link( (int) $row['id'], 'raw' ),
			),
			Copy_Prompt::design( $design )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function page_report( int $id, bool $facts ): array {
		$fields = Copy_Prompt::fields( $id );

		return $fields + array(
			'prompt'  => Copy_Prompt::prompt( $id, null, $facts ),
			'blocks'  => count( Copy_Writer::inventory( $id ) ),
			'revert'  => Copy_Writer::can_revert( $id ),
			'edit'    => (string) get_edit_post_link( $id, 'raw' ),
			'engine'  => $this->engine_state(),
		);
	}

	/**
	 * Whether an engine is ready to answer, and which.
	 *
	 * @return array{ready:bool, id:string, label:string, message:string}
	 */
	private function engine_state(): array {
		$engine = Copy_Writer::engine();
		if ( is_wp_error( $engine ) ) {
			return array(
				'ready'   => false,
				'id'      => '',
				'label'   => '',
				'message' => $engine->get_error_message(),
			);
		}

		return array(
			'ready'   => true,
			'id'      => $engine->get_id(),
			'label'   => $engine->get_label(),
			'message' => '',
		);
	}
}

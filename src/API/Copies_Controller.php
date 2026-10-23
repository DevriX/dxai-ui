<?php
/**
 * REST: "make the rest from this page".
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\API;

use DXAI_UI\Network\Network_Model;
use DXAI_UI\Network\Page_Copies;
use DXAI_UI\Pages\Copy_Writer;

/**
 * GET  /copies                    the pages of the site made from another page, with their originals.
 * GET  /copies/<page>             what the page is, its sections and what each is for the copies, the pages it would be
 *                                 made again for, and who writes their words (the AI chosen in Settings, or the person).
 * POST /copies/<page>             make one copy (`target`: an id, or a title and the menu's address for a new page): one per
 *                                 request, so a host that ends a request after a minute never cuts one in half.
 * POST /copies/<page>/give-back   a copy as it was before (a page the run made goes to the trash), unless it was edited since.
 *
 * Writing a copy writes a design's markup as it is (KSES would take its classes and attributes apart), so it asks what
 * publishing a design asks: manage_options and unfiltered_html.
 */
final class Copies_Controller {

	public function register_routes(): void {
		register_rest_route(
			DXAI_UI_REST_NAMESPACE,
			'/copies',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);
		register_rest_route(
			DXAI_UI_REST_NAMESPACE,
			'/copies/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'make' ),
					'permission_callback' => array( $this, 'permissions' ),
					'args'                => array(
						'target' => array(
							'type'     => 'object',
							'required' => true,
						),
						'words'  => array(
							'type'    => 'string',
							'enum'    => array( 'auto', 'outline' ),
							'default' => 'auto',
						),
					),
				),
			)
		);
		register_rest_route(
			DXAI_UI_REST_NAMESPACE,
			'/copies/(?P<id>\d+)/give-back',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'give_back' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);
	}

	public function permissions(): bool|\WP_Error {
		return ( new Converter_Controller() )->publish_permissions();
	}

	/**
	 * The page, or the error that says it is not one to make copies from.
	 */
	private static function page( int $id ): \WP_Post|\WP_Error {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || $post->post_type !== 'page' || ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'dxai_copies_page', __( 'There is no such page.', 'dxai-ui' ), array( 'status' => 404 ) );
		}

		return $post;
	}

	/**
	 * The pages of the site made from another page: each with the page it was made from, and whether it can be given back.
	 */
	public function index(): \WP_REST_Response {
		$ids = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts'      => 300,
				'fields'           => 'ids',
				'meta_key'         => Page_Copies::OF, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'suppress_filters' => true,
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$of    = (int) get_post_meta( (int) $id, Page_Copies::OF, true );
			$out[] = array(
				'id'        => (int) $id,
				'title'     => html_entity_decode( (string) get_the_title( (int) $id ), ENT_QUOTES, 'UTF-8' ),
				'of'        => $of,
				'of_title'  => html_entity_decode( (string) get_the_title( $of ), ENT_QUOTES, 'UTF-8' ),
				'give_back' => Page_Copies::can_give_back( (int) $id ),
			);
		}

		return rest_ensure_response( array( 'copies' => $out ) );
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id   = (int) $request['id'];
		$post = self::page( $id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$kind    = Page_Copies::kind( $id );
		$targets = Page_Copies::targets( $id );
		$plan    = in_array( $kind, array( 'service', 'location' ), true ) ? Page_Copies::sections( $id, array_column( array_filter( $targets, static fn( array $t ): bool => $t['state'] !== 'own' ), 'id' ) ) : array();
		$engine  = Copy_Writer::engine();

		return rest_ensure_response(
			array(
				'id'       => $id,
				'title'    => html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' ),
				'kind'     => $kind,
				'can'      => in_array( $kind, array( 'service', 'location' ), true ),
				'sections' => $plan,
				'targets'  => $targets,
				'words'    => array(
					'ai'     => ! is_wp_error( $engine ),
					'engine' => is_wp_error( $engine ) ? '' : $engine->get_label(),
					'why'    => is_wp_error( $engine ) ? $engine->get_error_message() : '',
				),
				'network'  => Network_Model::get() !== null,
				'copy_of'  => (int) get_post_meta( $id, Page_Copies::OF, true ),
				'copy_of_title' => html_entity_decode( (string) get_the_title( (int) get_post_meta( $id, Page_Copies::OF, true ) ), ENT_QUOTES, 'UTF-8' ),
				'give_back' => Page_Copies::can_give_back( $id ),
			)
		);
	}

	public function make( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id   = (int) $request['id'];
		$post = self::page( $id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! in_array( Page_Copies::kind( $id ), array( 'service', 'location' ), true ) ) {
			return new \WP_Error( 'dxai_copies_kind', __( 'Copies are made from a service page or a city page.', 'dxai-ui' ), array( 'status' => 400 ) );
		}
		$t      = (array) $request->get_param( 'target' );
		$target = array(
			'id'    => (int) ( $t['id'] ?? 0 ),
			'title' => sanitize_text_field( (string) ( $t['title'] ?? '' ) ),
			'link'  => esc_url_raw( (string) ( $t['link'] ?? '' ) ),
		);
		if ( $target['id'] > 0 && ! current_user_can( 'edit_post', $target['id'] ) ) {
			return new \WP_Error( 'dxai_copies_target', __( 'You cannot edit that page.', 'dxai-ui' ), array( 'status' => 403 ) );
		}
		$targets = Page_Copies::targets( $id );
		$plan    = Page_Copies::sections( $id, array_column( array_filter( $targets, static fn( array $x ): bool => $x['state'] !== 'own' ), 'id' ) );
		$out     = Page_Copies::make( $id, $target, $plan, array( 'words' => (string) $request->get_param( 'words' ) ) );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		// How the copy measures against the team's pages of its kind (N3).
		$out['quality'] = \DXAI_UI\Network\Page_Quality::of( (int) $out['id'] );

		return rest_ensure_response( $out );
	}

	public function give_back( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (int) $request['id'];
		if ( ! current_user_can( 'edit_post', $id ) || get_post_type( $id ) !== 'page' ) {
			return new \WP_Error( 'dxai_copies_page', __( 'There is no such page.', 'dxai-ui' ), array( 'status' => 404 ) );
		}
		$done = Page_Copies::give_back( $id );

		return is_wp_error( $done ) ? $done : rest_ensure_response( array( 'ok' => true ) );
	}
}

<?php
/**
 * REST: the old site's pages of an imported design (list), and building the chosen ones (start).
 *
 * Progress and the finishing pass run through the import's own crawl routes
 * (crawl-step / crawl-status): the job is a Crawl_Job like the import's.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\API;

use DXAI_UI\Pages\Site_Pages;
use WP_REST_Request;
use WP_REST_Response;

final class Site_Pages_Controller {

	private string $namespace = 'dxai-ui/v1';

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/site-pages/designs',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'designs' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/site-pages',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_pages' ),
					'permission_callback' => array( $this, 'permissions' ),
					'args'                => array(
						'home'   => array(
							'type'     => 'integer',
							'required' => true,
						),
						// The old site's address, when the person gives it (the links did not show it, or wrongly).
						'origin' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'start' ),
					'permission_callback' => array( $this, 'permissions' ),
					'args'                => array(
						'home'   => array(
							'type'     => 'integer',
							'required' => true,
						),
						'paths'  => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'string' ),
							'required' => true,
						),
						'engine' => array(
							'type'    => 'string',
							'enum'    => array( 'design', 'ai' ),
							'default' => 'design',
						),
						// Build again the pages edited since their import too (their edits are replaced).
						'rebuild_edited' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
	}

	/** Same rule as importing a design: the pages publish its blocks and CSS. */
	public function permissions(): bool|\WP_Error {
		return ( new Converter_Controller() )->publish_permissions();
	}

	/**
	 * The imported designs' Home pages (a converted page that is its own scope, not a page built from one).
	 */
	public function designs(): WP_REST_Response {
		$out = array();
		foreach (
			get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => array( 'publish', 'draft', 'private' ),
					'posts_per_page' => 100,
					'orderby'        => 'modified',
					'order'          => 'DESC',
					// Homes only: the pages built from an old site are generated too, and with enough of them the
					// Home fell outside the first results and the panel disappeared.
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'meta_query'     => array(
						array(
							'key'   => '_dxai_ui_generated_page',
							'value' => '1',
						),
						array(
							'key'     => '_dxai_ui_from_live_menu',
							'compare' => 'NOT EXISTS',
						),
					),
				)
			) as $page
		) {
			$scope = (int) get_post_meta( $page->ID, \DXAI_UI\Structures\Page_Scope::META, true );
			if ( $scope !== (int) $page->ID || get_post_meta( $page->ID, '_dxai_ui_from_live_menu', true ) ) {
				continue;
			}
			$out[] = array(
				'id'      => (int) $page->ID,
				'title'   => get_the_title( $page ),
				'view'    => (string) get_permalink( $page ),
				'archive' => (string) get_post_meta( $page->ID, '_dxai_ui_source_zip', true ),
			);
		}

		return rest_ensure_response( array( 'designs' => $out ) );
	}

	public function list_pages( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$home = (int) $request->get_param( 'home' );
		if ( get_post_type( $home ) !== 'page' ) {
			return new \WP_Error( 'dxai_ui_site_pages', __( 'That design page no longer exists.', 'dxai-ui' ), array( 'status' => 404 ) );
		}

		$origin = (string) $request->get_param( 'origin' );
		if ( $origin !== '' && \DXAI_UI\Connectors\Site_Origin::normalize( $origin ) === '' ) {
			return new \WP_Error( 'dxai_ui_site_pages', __( 'That is not a site address. Enter it like example.com or https://www.example.com.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( Site_Pages::listing( $home, $origin ) );
	}

	public function start( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$job = Site_Pages::start( (int) $request->get_param( 'home' ), (array) $request->get_param( 'paths' ), (string) $request->get_param( 'engine' ), (bool) $request->get_param( 'rebuild_edited' ) );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		return rest_ensure_response( array( 'job' => $job ) );
	}
}

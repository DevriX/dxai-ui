<?php
/**
 * REST: new pages for a design, in the team's style and the Home's look.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\API;

use DXAI_UI\Pages\Ai_Budget;
use DXAI_UI\Pages\Team_Pages;
use DXAI_UI\Structures\Design_Attach;
use DXAI_UI\Structures\Page_Scope;

/**
 * GET  /team-pages            the designs, and for one (`design`) what it could be asked for (the services and places its Home
 *                             names, the general pages) and the pages already made.
 * POST /team-pages            `plan` what would be made (the order of the sections of each page), `estimate` what an AI plan would cost (nothing is
 *                             asked; the price a person types is kept), or `build` the pages (with `ai` an AI plans each page: one request).
 */
final class Team_Pages_Controller {

	public function register_routes(): void {
		register_rest_route(
			DXAI_UI_REST_NAMESPACE,
			'/team-pages',
			array(
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
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run' ),
					'permission_callback' => array( $this, 'permissions' ),
					'args'                => array(
						'action' => array(
							'type'     => 'string',
							'enum'     => array( 'plan', 'build', 'estimate' ),
							'required' => true,
						),
						'design' => array(
							'type'     => 'integer',
							'required' => true,
						),
						'wanted' => array(
							'type'    => 'array',
							'default' => array(),
						),
						'force'  => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'ai'     => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'price_in'  => array( 'type' => 'number' ),
						'price_out' => array( 'type' => 'number' ),
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
			$design = (int) $designs[0]['id'];
			foreach ( $designs as $d ) {
				if ( \DXAI_UI\Compiler\Token_Styles::is_brand( (int) $d['id'] ) ) {
					$design = (int) $d['id'];
					break;
				}
			}
		}
		$ok = $design > 0 && Design_Attach::is_design( $design );

		return new \WP_REST_Response(
			array(
				'designs'     => $designs,
				'design'      => $design,
				'suggestions' => $ok ? Team_Pages::suggestions( $design ) : null,
				'existing'    => $ok ? $this->existing( $design ) : array(),
				'kinds'       => array_map( static fn( $k ) => $k['label'], Team_Pages::KINDS ),
				'ai'          => array(
					'prices' => Ai_Budget::prices(),
					'cap'    => Ai_Budget::cap(),
				),
			)
		);
	}

	public function run( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$design = (int) $request->get_param( 'design' );
		if ( ! Design_Attach::is_design( $design ) || ! current_user_can( 'edit_post', $design ) ) {
			return new \WP_Error( 'dxai_ui_team_design', __( 'That is not an imported design.', 'dxai-ui' ), array( 'status' => 404 ) );
		}
		$wanted = array();
		foreach ( (array) $request->get_param( 'wanted' ) as $row ) {
			if ( is_array( $row ) ) {
				$wanted[] = array(
					'type'  => (string) ( $row['type'] ?? '' ),
					'title' => (string) ( $row['title'] ?? '' ),
				);
			}
		}
		if ( (string) $request->get_param( 'action' ) === 'plan' ) {
			return new \WP_REST_Response( array( 'plan' => Team_Pages::plan( $design, $wanted ) ) );
		}
		if ( (string) $request->get_param( 'action' ) === 'estimate' ) {
			if ( $request->get_param( 'price_in' ) !== null || $request->get_param( 'price_out' ) !== null ) {
				Ai_Budget::save_prices( (float) $request->get_param( 'price_in' ), (float) $request->get_param( 'price_out' ) );
			}
			$est = Team_Pages::estimate( $design, $wanted );
			if ( is_wp_error( $est ) ) {
				return $est;
			}

			return new \WP_REST_Response(
				array(
					'estimate' => $est,
					'prices'   => Ai_Budget::prices(),
				)
			);
		}
		$out = Team_Pages::build( $design, $wanted, (bool) $request->get_param( 'force' ), array( 'ai' => (bool) $request->get_param( 'ai' ) ) );
		if ( is_wp_error( $out ) ) {
			return $out;
		}

		return new \WP_REST_Response( $out + array( 'existing' => $this->existing( $design ) ) );
	}

	/**
	 * The pages of this kind a design already has.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function existing( int $design ): array {
		$ids = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => Team_Pages::META,
						'compare' => 'EXISTS',
					),
					array(
						'key'   => Page_Scope::META,
						'value' => (string) $design,
					),
				),
				'fields'         => 'ids',
				'posts_per_page' => 100,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		return array_map(
			static function ( $id ): array {
				$kind = explode( '|', (string) get_post_meta( (int) $id, Team_Pages::META, true ) )[0];

				return array(
					'id'    => (int) $id,
					'title' => html_entity_decode( get_the_title( (int) $id ), ENT_QUOTES, 'UTF-8' ),
					'type'  => $kind,
					'edit'  => (string) get_edit_post_link( (int) $id, 'raw' ),
					'view'  => (string) get_permalink( (int) $id ),
				);
			},
			$ids
		);
	}
}

<?php
/**
 * REST: the theme colours a design follows.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\API;

use DXAI_UI\Structures\Design_Attach;
use DXAI_UI\Theme\Theme_Binding;
use DXAI_UI\Theme\Theme_Palette;

/**
 * GET  /theme-colors          the theme's palette and, per design, its binding (Theme_Binding::report()).
 * POST /theme-colors          save one design's mode (follow / keep) and its per-colour choices.
 * POST /theme-colors/propose  propose again for the current theme, dropping the per-colour choices.
 */
final class Theme_Colors_Controller {

	public function register_routes(): void {
		register_rest_route(
			DXAI_UI_REST_NAMESPACE,
			'/theme-colors',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => array( $this, 'permissions' ),
					'args'                => array(
						'design'    => array(
							'type'     => 'integer',
							'required' => true,
						),
						'mode'      => array(
							'type' => 'string',
							'enum' => array( 'follow', 'keep' ),
						),
						'overrides' => array( 'type' => 'object' ),
					),
				),
			)
		);
		register_rest_route(
			DXAI_UI_REST_NAMESPACE,
			'/theme-colors/propose',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'propose' ),
				'permission_callback' => array( $this, 'permissions' ),
				'args'                => array(
					'design' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
	}

	public function permissions(): bool|\WP_Error {
		return ( new Converter_Controller() )->publish_permissions();
	}

	public function index(): \WP_REST_Response {
		$palette = Theme_Palette::current();
		$theme   = wp_get_theme();

		return new \WP_REST_Response(
			array(
				'theme'   => array(
					'name'          => $theme->get( 'Name' ),
					'has_palette'   => $palette['has_palette'],
					'user_override' => $palette['user_override'],
					'entries'       => array_values(
						array_map(
							static fn( $e ) => array(
								'slug' => $e['slug'],
								'name' => $e['name'],
								'hex'  => $e['hex'],
							),
							$palette['entries']
						)
					),
					'anchors'       => $palette['anchors'],
				),
				'designs' => array_map( static fn( $page ) => Theme_Binding::report( (int) $page->ID ), Design_Attach::designs() ),
			)
		);
	}

	public function save( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$home = $this->design( $request );
		if ( $home instanceof \WP_Error ) {
			return $home;
		}
		$overrides = $request->get_param( 'overrides' );
		Theme_Binding::apply( $home, (string) ( $request->get_param( 'mode' ) ?: 'follow' ), is_array( $overrides ) ? array_map( 'strval', $overrides ) : null );

		return new \WP_REST_Response( Theme_Binding::report( $home ) );
	}

	public function propose( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$home = $this->design( $request );
		if ( $home instanceof \WP_Error ) {
			return $home;
		}
		$prior = Theme_Binding::get( $home );
		Theme_Binding::apply( $home, (string) ( $prior['mode'] ?? Theme_Binding::default_mode( $home ) ), array() );

		return new \WP_REST_Response( Theme_Binding::report( $home ) );
	}

	/** The design Home a request names, if it is one this person may change. */
	private function design( \WP_REST_Request $request ): int|\WP_Error {
		$home = (int) $request->get_param( 'design' );
		if ( ! Design_Attach::is_design( $home ) || ! current_user_can( 'edit_post', $home ) ) {
			return new \WP_Error( 'dxai_ui_design', __( 'That is not an imported design.', 'dxai-ui' ), array( 'status' => 404 ) );
		}

		return $home;
	}
}

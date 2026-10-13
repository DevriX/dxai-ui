<?php
/**
 * REST: the team libraries (templates) a site keeps — made from the team's exports, built here, trained with more.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\API;

use DXAI_UI\Pages\Library_Store;
use DXAI_UI\Pages\Template;

/**
 * GET    /libraries                   every template the site has (the built-in one, the stored ones), which is in use, the themes installed.
 * POST   /libraries                   a new library: `label`, `theme`, `extends` (a template slug or ''), and `file` (a WordPress export,
 *                                     or a file of block markup) — made, kept, built.
 * GET    /libraries/{slug}            one, with its layouts and its last report.
 * POST   /libraries/{slug}/sources    one more export (`file`): kept, and the library built again from all of them.
 * POST   /libraries/{slug}/build      built again.
 * DELETE /libraries/{slug}            gone, with everything in it.
 *
 * Administrators only: the files are another site's data, read as blocks and never run.
 */
final class Libraries_Controller {

	public function register_routes(): void {
		$slug = array(
			'slug' => array(
				'type'     => 'string',
				'required' => true,
				'pattern'  => '^[a-z0-9-]+$',
			),
		);
		register_rest_route(
			DXAI_UI_REST_NAMESPACE,
			'/libraries',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'permissions' ),
					'args'                => array(
						'label'   => array(
							'type'    => 'string',
							'default' => '',
						),
						'theme'   => array(
							'type'    => 'string',
							'default' => '',
						),
						'extends' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
			)
		);
		register_rest_route(
			DXAI_UI_REST_NAMESPACE,
			'/libraries/(?P<slug>[a-z0-9-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'permissions' ),
					'args'                => $slug,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove' ),
					'permission_callback' => array( $this, 'permissions' ),
					'args'                => $slug,
				),
			)
		);
		register_rest_route(
			DXAI_UI_REST_NAMESPACE,
			'/libraries/(?P<slug>[a-z0-9-]+)/sources',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add' ),
				'permission_callback' => array( $this, 'permissions' ),
				'args'                => $slug,
			)
		);
		register_rest_route(
			DXAI_UI_REST_NAMESPACE,
			'/libraries/(?P<slug>[a-z0-9-]+)/build',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'build' ),
				'permission_callback' => array( $this, 'permissions' ),
				'args'                => $slug,
			)
		);
	}

	/** @return bool|\WP_Error */
	public function permissions() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new \WP_Error( 'rest_forbidden', __( 'Only an administrator may keep the team libraries.', 'dxai-ui' ), array( 'status' => rest_authorization_required_code() ) );
	}

	public function index(): \WP_REST_Response {
		return new \WP_REST_Response( $this->overview() );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function show( \WP_REST_Request $request ) {
		$lib = Library_Store::get( (string) $request['slug'] );
		if ( $lib === null ) {
			return new \WP_Error( 'dxai_ui_library_none', __( 'There is no such library.', 'dxai-ui' ), array( 'status' => 404 ) );
		}

		return new \WP_REST_Response( $this->item( $lib, true ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function create( \WP_REST_Request $request ) {
		$file = $this->file( $request );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$lib = Library_Store::create( (string) $request['label'], array( (string) $request['theme'] ), (string) $request['extends'] );
		if ( is_wp_error( $lib ) ) {
			$lib->add_data( array( 'status' => 400 ) );

			return $lib;
		}
		$slug  = (string) $lib['slug'];
		$added = Library_Store::add_source( $slug, (string) $file['tmp_name'], (string) ( $file['name'] ?? '' ) );
		if ( is_wp_error( $added ) ) {
			// Nothing to keep: a library with no export in it.
			Library_Store::delete( $slug );
			$added->add_data( array( 'status' => 400 ) );

			return $added;
		}
		$built = Library_Store::build( $slug );
		if ( is_wp_error( $built ) ) {
			$built->add_data( array( 'status' => 500 ) );

			return $built;
		}

		return new \WP_REST_Response( $this->item( $built, true ) + array( 'overview' => $this->overview() ), 201 );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function add( \WP_REST_Request $request ) {
		$file = $this->file( $request );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$added = Library_Store::add_source( (string) $request['slug'], (string) $file['tmp_name'], (string) ( $file['name'] ?? '' ) );
		if ( is_wp_error( $added ) ) {
			$added->add_data( array( 'status' => $added->get_error_code() === 'dxai_ui_library_none' ? 404 : 400 ) );

			return $added;
		}

		return $this->build( $request );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function build( \WP_REST_Request $request ) {
		$built = Library_Store::build( (string) $request['slug'] );
		if ( is_wp_error( $built ) ) {
			$built->add_data( array( 'status' => $built->get_error_code() === 'dxai_ui_library_none' ? 404 : 400 ) );

			return $built;
		}

		return new \WP_REST_Response( $this->item( $built, true ) + array( 'overview' => $this->overview() ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function remove( \WP_REST_Request $request ) {
		$slug = (string) $request['slug'];
		if ( Library_Store::get( $slug ) === null ) {
			return new \WP_Error( 'dxai_ui_library_none', __( 'There is no such library.', 'dxai-ui' ), array( 'status' => 404 ) );
		}
		if ( ! Library_Store::delete( $slug ) ) {
			return new \WP_Error( 'dxai_ui_library_delete', __( 'The library could not be removed.', 'dxai-ui' ), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response(
			array(
				'deleted'  => $slug,
				'overview' => $this->overview(),
			)
		);
	}

	/**
	 * The uploaded file of a request (`file`), checked as an upload.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function file( \WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;
		if ( $file === null || empty( $file['tmp_name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return new \WP_Error( 'dxai_ui_library_file', __( 'No file was uploaded. Choose a WordPress export (.xml) or a file of block markup.', 'dxai-ui' ), array( 'status' => 400 ) );
		}
		if ( (int) ( $file['error'] ?? 0 ) !== UPLOAD_ERR_OK ) {
			return new \WP_Error( 'dxai_ui_library_file', __( 'The file did not arrive whole. Try again.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		return $file;
	}

	/**
	 * Every template, which is in use, the themes.
	 *
	 * @return array<string, mixed>
	 */
	private function overview(): array {
		$current = Template::current();
		$items   = array();
		foreach ( Template::all() as $t ) {
			$items[] = $this->item( $t, false );
		}
		$themes = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$themes[] = array(
				'slug' => (string) $stylesheet,
				'name' => (string) $theme->get( 'Name' ),
			);
		}

		return array(
			'current'   => (string) $current['slug'],
			'generic'   => ! empty( $current['generic'] ),
			'theme'     => get_stylesheet(),
			'themeName' => (string) wp_get_theme()->get( 'Name' ),
			'themes'    => $themes,
			'templates' => $items,
		);
	}

	/**
	 * A template as the client sees it (nothing of the disk), with its layouts when asked for.
	 *
	 * @param array<string, mixed> $t
	 * @return array<string, mixed>
	 */
	private function item( array $t, bool $full ): array {
		$slug   = (string) $t['slug'];
		$stored = ! empty( $t['stored'] );
		$out    = array(
			'slug'    => $slug,
			'label'   => (string) $t['label'],
			'themes'  => array_values( (array) ( $t['themes'] ?? array() ) ),
			'kinds'   => array_values( (array) ( $t['kinds'] ?? array() ) ),
			'extends' => (string) ( $t['extends'] ?? '' ),
			'stored'  => $stored,
			'inUse'   => (string) Template::current()['slug'] === $slug,
			'layouts' => (int) ( $t['layouts'] ?? 0 ),
			'byRole'  => (array) ( $t['by_role'] ?? array() ),
			'sources' => array_values( array_map( static fn( $s ) => array( 'name' => (string) ( $s['name'] ?? '' ), 'kind' => (string) ( $s['kind'] ?? '' ), 'pages' => (int) ( $s['pages'] ?? 0 ), 'added' => (string) ( $s['added'] ?? '' ) ), (array) ( $t['sources'] ?? array() ) ) ),
			'report'  => is_array( $t['report'] ?? null ) ? array(
				'made'   => (int) ( $t['report']['made'] ?? 0 ),
				'errors' => array_values( (array) ( $t['report']['errors'] ?? array() ) ),
				'pages'  => (int) ( $t['report']['pages'] ?? 0 ),
				'at'     => (string) ( $t['report']['at'] ?? '' ),
				'lines'  => $full ? array_values( (array) ( $t['report']['lines'] ?? array() ) ) : array(),
			) : null,
			'updated' => (string) ( $t['updated'] ?? '' ),
		);
		if ( $full && $stored ) {
			$out['layoutList'] = Library_Store::layouts( $slug );
		}

		return $out;
	}
}

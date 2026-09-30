<?php
/**
 * REST: export a converted page as a package, import a package.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\API;

use DXAI_UI\Connectors\Zip_Extractor;
use DXAI_UI\Transfer\Import_Job;
use DXAI_UI\Transfer\Package;
use DXAI_UI\Transfer\Page_Export;
use DXAI_UI\Transfer\Page_Import;
use DXAI_UI\Transfer\Transfer_Admin;
use WP_REST_Request;

/**
 * - GET  /dxai-ui/v1/transfer/pages           the converted pages, with their export links,
 *                                             and what the import panel asks about this site;
 * - GET  /dxai-ui/v1/transfer/export/{id}     the page's package, as a download
 *                                             (`?format=json`: its listing instead, nothing sent);
 * - POST /dxai-ui/v1/transfer/import          multipart `package` (+ `chrome` keep|install|''
 *                                             (automatic), `publish`, `dry_run`): imports it
 *                                             and answers what was made — or, when the media
 *                                             takes longer than one request may, 202 with
 *                                             status `continue` and a token;
 * - POST /dxai-ui/v1/transfer/import/continue `token`: the next step of that import.
 *
 * Every route has the import routes' permission (Converter_Controller::
 * publish_permissions(): manage_options and unfiltered_html), which says in
 * words why a user lacking unfiltered_html is refused.
 */
final class Transfer_Controller extends \WP_REST_Controller {

	public function __construct() {
		$this->namespace = DXAI_UI_REST_NAMESPACE;
		$this->rest_base = 'transfer';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/transfer/pages',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'pages' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/transfer/export/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export' ),
				'permission_callback' => array( $this, 'permissions' ),
				'args'                => array(
					'id'     => array(
						'type'     => 'integer',
						'required' => true,
					),
					'format' => array(
						'type'    => 'string',
						'enum'    => array( 'zip', 'json' ),
						'default' => 'zip',
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/transfer/import',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import' ),
				'permission_callback' => array( $this, 'permissions' ),
				'args'                => array(
					// Chrome_Choice's three answers; `auto` is the automatic one too.
					'chrome'  => array(
						'type'    => 'string',
						'enum'    => array( Page_Import::CHROME_AUTO, 'auto', Page_Import::CHROME_KEEP, Page_Import::CHROME_INSTALL ),
						'default' => Page_Import::CHROME_AUTO,
					),
					'publish' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'dry_run' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/transfer/import/continue',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resume' ),
				'permission_callback' => array( $this, 'permissions' ),
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'pattern'  => '^[a-f0-9]{32}$',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * The import routes' rule, with their message.
	 *
	 * @return bool|\WP_Error
	 */
	public function permissions() {
		return ( new Converter_Controller() )->publish_permissions();
	}

	/**
	 * @return \WP_REST_Response
	 */
	public function pages() {
		$query = new \WP_Query(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 100,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_key'       => '_dxai_ui_generated_page', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$out   = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$out[] = array(
				'id'         => (int) $post->ID,
				'title'      => get_the_title( $post ),
				'status'     => (string) $post->post_status,
				'modified'   => get_date_from_gmt( $post->post_modified_gmt, 'c' ),
				'view'       => (string) get_permalink( $post ),
				'edit'       => (string) get_edit_post_link( $post->ID, 'raw' ),
				'export_url' => Transfer_Admin::export_url( (int) $post->ID ),
				'imported'   => '' !== (string) get_post_meta( $post->ID, Package::KEY_META, true ),
			);
		}
		$theme = wp_get_theme();

		return rest_ensure_response(
			array(
				'pages'               => $out,
				'theme'               => array(
					'name'    => $theme->get( 'Name' ),
					'classic' => ! ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ),
				),
				'max_upload'          => (int) wp_max_upload_size(),
				'menus_url'           => admin_url( 'nav-menus.php' ),
				'widgets_url'         => admin_url( 'widgets.php' ),
				// Header menu locations a person's own menu holds: an install
				// leaves those as they are (Header_Menus::install()), so the
				// page's header draws that menu there.
				'menu_locations_kept' => self::person_locations(),
			) + self::chrome_info()
		);
	}

	/**
	 * What the header and footer question needs, in import-info's words
	 * (Converter_Controller::import_info_payload()): the choice an automatic
	 * import of a package makes here — a package is one page, so it keeps the
	 * site's (Chrome_Choice, scope `page`) — why, the sentence saying so,
	 * whether the theme draws the whole site's header and footer, and whose
	 * header and footer the site shows now.
	 *
	 * @return array<string, mixed>
	 */
	public static function chrome_info(): array {
		if ( ! class_exists( \DXAI_UI\Chrome\Chrome_Choice::class ) ) {
			$classic = ! ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() );

			return array(
				'chrome_default'         => Page_Import::CHROME_KEEP,
				'chrome_default_reason'  => $classic ? 'theme' : 'one_page',
				'chrome_default_message' => '',
				'theme_chrome'           => $classic,
				'theme_name'             => (string) wp_get_theme()->get( 'Name' ),
				'chrome_owner'           => array(
					'header' => array(),
					'footer' => array(),
				),
			);
		}
		$info                 = \DXAI_UI\Chrome\Chrome_Choice::info( '', 'page' );
		$info['chrome_owner'] = method_exists( Converter_Controller::class, 'chrome_owner' ) ? Converter_Controller::chrome_owner( $info['chrome_owner'] ) : $info['chrome_owner'];

		return $info;
	}

	/**
	 * The header menu locations a person's own menu holds — one no header
	 * install wrote (Navigation_Factory::HEADER_MENU_META) — each with its
	 * label and the menu's name.
	 *
	 * @return array<int, array{location:string, label:string, menu:string, menu_id:int}>
	 */
	private static function person_locations(): array {
		if ( ! class_exists( \DXAI_UI\Chrome\Header_Menus::class ) || ! defined( \DXAI_UI\Structures\Navigation_Factory::class . '::HEADER_MENU_META' ) ) {
			return array();
		}
		$assigned = get_nav_menu_locations();
		$labels   = get_registered_nav_menus();
		$out      = array();
		foreach ( \DXAI_UI\Chrome\Header_Menus::locations() as $location => $label ) {
			$id   = (int) ( $assigned[ $location ] ?? 0 );
			$term = $id > 0 ? wp_get_nav_menu_object( $id ) : false;
			if ( ! $term instanceof \WP_Term || '' !== (string) get_term_meta( $id, \DXAI_UI\Structures\Navigation_Factory::HEADER_MENU_META, true ) ) {
				continue;
			}
			$out[] = array(
				'location' => (string) $location,
				'label'    => (string) ( $labels[ $location ] ?? $label ),
				'menu'     => wp_specialchars_decode( $term->name, ENT_QUOTES ),
				'menu_id'  => $id,
			);
		}

		return $out;
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function export( WP_REST_Request $request ) {
		$built = ( new Page_Export() )->build( (int) $request['id'] );
		if ( is_wp_error( $built ) ) {
			return $built;
		}
		if ( 'json' === (string) $request['format'] ) {
			Package::remove_dir( (string) $built['dir'] );

			return rest_ensure_response(
				array(
					'filename' => $built['filename'],
					'bytes'    => $built['bytes'],
					'listing'  => $built['listing'],
					'warnings' => $built['warnings'],
					'counts'   => array(
						'media'       => count( (array) $built['manifest']['media'] ),
						'parts'       => count( (array) $built['manifest']['parts'] ),
						'patterns'    => count( (array) $built['manifest']['patterns'] ),
						'navigations' => count( (array) $built['manifest']['navigations'] ),
					),
					'chrome'   => array(
						'header' => (string) ( $built['manifest']['chrome']['header']['mode'] ?? 'none' ),
						'footer' => (string) ( $built['manifest']['chrome']['footer']['mode'] ?? 'none' ),
					),
					'download' => Transfer_Admin::export_url( (int) $request['id'] ),
				)
			);
		}
		// A file, not JSON: sent here and the request ends.
		Transfer_Admin::send( $built );

		return new \WP_Error( 'dxai_ui_transfer', __( 'The package could not be sent.', 'dxai-ui' ), array( 'status' => 500 ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$file  = isset( $files['package'] ) && is_array( $files['package'] ) ? $files['package'] : null;
		$error = Zip_Extractor::upload_error( $file );
		if ( null !== $error ) {
			return self::upload_refusal( $error );
		}
		if ( null === $file || empty( $file['tmp_name'] ) ) {
			return new \WP_Error( 'dxai_ui_transfer', __( 'No package was uploaded. Choose a .dxai.zip file exported by DX UI.', 'dxai-ui' ), array( 'status' => 400 ) );
		}
		$raised = Page_Import::unlimited();
		$result = ( new Page_Import() )->run(
			(string) $file['tmp_name'],
			(string) ( $file['name'] ?? '' ),
			array(
				'chrome'  => (string) $request['chrome'],
				'publish' => (bool) $request['publish'],
				'dry_run' => (bool) $request['dry_run'],
				'budget'  => Page_Import::step_budget( $raised ),
			)
		);

		return self::answer( $result );
	}

	/**
	 * The next step of an import that answered `continue`.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function resume( WP_REST_Request $request ) {
		$job = Import_Job::open( (string) $request['token'] );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		$raised  = Page_Import::unlimited();
		$options = (array) ( $job['options'] ?? array() );
		$result  = ( new Page_Import() )->run(
			(string) $job['zip'],
			(string) ( $job['name'] ?? '' ),
			array(
				'chrome'  => (string) ( $options['chrome'] ?? Page_Import::CHROME_AUTO ),
				'publish' => ! empty( $options['publish'] ),
				'budget'  => Page_Import::step_budget( $raised ),
				'job'     => $job,
			)
		);

		return self::answer( $result );
	}

	/**
	 * @param array<string, mixed>|\WP_Error $result
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function answer( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$response = rest_ensure_response( $result );
		if ( 'continue' === ( $result['status'] ?? '' ) ) {
			$response->set_status( 202 );
		}

		return $response;
	}

	/**
	 * An upload PHP refused, in a package's words: a package too large for
	 * this server's upload limit is imported from the command line, where
	 * there is none — the one way on a host whose limit cannot be raised.
	 */
	private static function upload_refusal( \WP_Error $error ): \WP_Error {
		$data   = (array) $error->get_error_data();
		$status = (int) ( $data['status'] ?? 400 );
		if ( 413 !== $status ) {
			return $error;
		}

		return new \WP_Error(
			'dxai_ui_transfer_too_large',
			sprintf(
				/* translators: 1: the largest upload this server accepts, e.g. 64 MB, 2: who to run it as. */
				__( 'This package is larger than this server accepts for an upload (%1$s). Import it from the command line instead, where no upload limit applies: copy the .dxai.zip file to the server (SFTP, or the host\'s file manager) and run `wp dxai-ui import <file> --user=%2$s`. On WP Engine, use its SSH Gateway.', 'dxai-ui' ),
				size_format( wp_max_upload_size() ),
				is_multisite() ? __( '<a Super Admin>', 'dxai-ui' ) : __( '<an administrator>', 'dxai-ui' )
			),
			array( 'status' => 413 )
		);
	}
}

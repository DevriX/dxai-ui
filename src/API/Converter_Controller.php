<?php
/**
 * Conversion REST controller.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\API;

use DXAI_UI\Compiler\Chunked_Generator;
use DXAI_UI\Compiler\Css_Scoper;
use DXAI_UI\Compiler\Design_Html;
use DXAI_UI\Compiler\Fidelity_Confidence;
use DXAI_UI\Compiler\Fidelity_Gate;
use DXAI_UI\Compiler\Fidelity_Refiner;
use DXAI_UI\Compiler\Gutenberg_Mapper;
use DXAI_UI\Compiler\Rewrite_Policy;
use DXAI_UI\Compiler\Source_Compiler;
use DXAI_UI\Compiler\Style_Hoister;
use DXAI_UI\Compiler\Tailwind_Purger;
use DXAI_UI\Connectors\Figma_Connector;
use DXAI_UI\Connectors\Lovable_Connector;
use DXAI_UI\Connectors\Site_Origin;
use DXAI_UI\Connectors\Source_Document;
use DXAI_UI\Connectors\Zip_Extractor;
use DXAI_UI\Connectors\Zip_Router;
use DXAI_UI\Engines\Engine_Factory;
use DXAI_UI\Patterns\Pattern_Repository;
use DXAI_UI\Settings\Options;
use DXAI_UI\Structures\Crawl_Job;
use DXAI_UI\Structures\Live_Page_Restyler;
use DXAI_UI\Structures\Structure_Repository;
use DXAI_UI\Support\Logger;
use DXAI_UI\Verification\Import_Audit;
use WP_REST_Request;
use WP_REST_Response;

final class Converter_Controller extends \WP_REST_Controller {

	public function __construct() {
		$this->namespace = DXAI_UI_REST_NAMESPACE;
		$this->rest_base = 'process-source';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/process-source',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'process_source' ),
				'permission_callback' => array( $this, 'publish_permissions' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/generate-block',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate_block' ),
				'permission_callback' => array( $this, 'publish_permissions' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/save-pattern',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_pattern' ),
				'permission_callback' => array( $this, 'publish_permissions' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => array( $this, 'publish_permissions' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/restore',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore' ),
				'permission_callback' => array( $this, 'publish_permissions' ),
				'args'                => array(
					'conversion_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'scope'         => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/refine-structure',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'refine_structure' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/patterns',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_patterns' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		/*
		 * The site-from-menu crawl, one small step per request — see
		 * Crawl_Job for why it no longer runs inside save-pattern.
		 */
		register_rest_route(
			$this->namespace,
			'/crawl-step',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'crawl_step' ),
				'permission_callback' => array( $this, 'publish_permissions' ),
				'args'                => array(
					'job' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/crawl-status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'crawl_status' ),
				'permission_callback' => array( $this, 'publish_permissions' ),
				'args'                => array(
					'job' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		/*
		 * How a save the browser lost track of ended — see save_pattern(). A
		 * proxy that stops waiting after 60–100 s answers 504 while WordPress
		 * carries on, so the wizard asks here before it offers to save again
		 * (a second save of a whole-site import starts a second crawl and
		 * spends the API credit twice). Read-only.
		 */
		register_rest_route(
			$this->namespace,
			'/save-status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'save_status' ),
				'permission_callback' => array( $this, 'publish_permissions' ),
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		/*
		 * What the import screen needs to describe an import before it runs
		 * it: the engine the menu crawl would use, the upload limit, and the
		 * admin screens a finished import links to. Read-only.
		 */
		register_rest_route(
			$this->namespace,
			'/import-info',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'import_info' ),
				'permission_callback' => array( $this, 'permissions' ),
				'args'                => array(
					// The archive about to be imported and its scope, so the
					// automatic header and footer choice is this import's.
					'archive' => array(
						'type'     => 'string',
						'required' => false,
					),
					'scope'   => array(
						'type'     => 'string',
						'required' => false,
						'enum'     => array( 'site', 'page' ),
					),
				),
			)
		);
	}

	public function import_info( ?WP_REST_Request $request = null ): WP_REST_Response {
		$archive = null !== $request ? wp_strip_all_tags( wp_basename( (string) $request->get_param( 'archive' ) ) ) : '';
		$scope   = null !== $request ? (string) $request->get_param( 'scope' ) : '';

		return rest_ensure_response( self::import_info_payload( $archive, $scope !== '' ? $scope : 'site' ) );
	}

	/**
	 * The import screen's facts about this site, also printed into the page
	 * by Dashboard::assets() so the first render needs no request.
	 *
	 * The header and footer choice the import asks about (Chrome_Choice):
	 * `chrome_default` is what an automatic save would do — for this archive
	 * and scope when they are given, else for a whole-site import of a design
	 * that is not the site's header and footer yet — with its reason and a
	 * sentence to show (`chrome_default_reason`, `chrome_default_message`);
	 * `theme_chrome` is whether the active theme draws its own header and
	 * footer from the menus and widget areas an install writes (a classic
	 * theme: installing changes every page of the site), `theme_name` names
	 * it; `chrome_owner` says whose header and footer the site shows now
	 * (title, archive, page_id, view and edit links, live), [] for none.
	 *
	 * @param string $archive The archive about to be imported ('' when not known yet).
	 * @param string $scope   'site' or 'page'.
	 * @return array<string, mixed>
	 */
	public static function import_info_payload( string $archive = '', string $scope = 'site' ): array {
		$can_import = current_user_can( 'unfiltered_html' );
		$choice     = \DXAI_UI\Chrome\Chrome_Choice::info( $archive, $scope );

		return array(
			'engine'         => self::crawl_engine(),
			// The web server's own limit — the CLI's php.ini is a different file.
			'max_upload'     => (int) wp_max_upload_size(),
			'max_pages'      => Site_Origin::MAX_PAGES,
			/*
			 * Whether this user may import at all. The import routes need
			 * unfiltered_html (publish_permissions()), which a multisite site
			 * admin and every account under DISALLOW_UNFILTERED_HTML lack —
			 * and without this they walked the whole wizard and uploaded the
			 * archive before process-source said no.
			 */
			'can_import'     => $can_import,
			'import_refusal' => $can_import ? '' : self::unfiltered_html_message(),
			// Where this server's save can put the header and footer — see chrome_built().
			'chrome'         => self::chrome_built(),
			// Install or keep the site's header and footer (see above): what
			// an automatic save does, why, the theme, and whose they are now.
			'chrome_default'         => $choice['chrome_default'],
			'chrome_default_reason'  => $choice['chrome_default_reason'],
			'chrome_default_message' => $choice['chrome_default_message'],
			'theme_chrome'           => $choice['theme_chrome'],
			'theme_name'             => $choice['theme_name'],
			'chrome_owner'           => self::chrome_owner( $choice['chrome_owner'] ),
			// The server's clock, so the screen can say how long ago a save started.
			'now'            => time(),
			'links'          => array(
				// Only screens WordPress will actually open on this theme; the
				// save response names them itself once it creates menus/widgets.
				'menus'    => current_theme_supports( 'menus' ) ? admin_url( 'nav-menus.php' ) : '',
				'widgets'  => current_theme_supports( 'widgets' ) ? admin_url( 'widgets.php' ) : '',
				'library'  => admin_url( 'admin.php?page=dxai-ui-library' ),
				'settings' => admin_url( 'admin.php?page=dxai-ui-settings' ),
			),
		);
	}

	/**
	 * Whether this server's save builds the header in Appearance > Menus and
	 * the footer in Appearance > Widgets, or still writes both as template
	 * parts.
	 *
	 * The wizard used to say "the header is built in Appearance > Menus" while
	 * Structure_Repository::save() still wrote template parts: a sentence
	 * about the import that was not true of it. What the save does is a fact
	 * about the save, so the save's own class declares it — a BUILDS_CHROME
	 * constant, array( 'menus' => true, 'widgets' => true ), added in the same
	 * change that makes save() call Header_Menus::install() and
	 * Footer_Widgets::install(). Until then both are false and the screen
	 * describes the template parts it is really going to write. A save that
	 * reports chrome without declaring it is still shown (the wizard reads
	 * the report); this only decides what the screen promises beforehand.
	 *
	 * @return array{menus:bool, widgets:bool}
	 */
	public static function chrome_built(): array {
		$constant = Structure_Repository::class . '::BUILDS_CHROME';
		$declared = defined( $constant ) ? constant( $constant ) : array();
		$declared = is_array( $declared ) ? $declared : array();

		return array(
			'menus'   => ! empty( $declared['menus'] ),
			'widgets' => ! empty( $declared['widgets'] ),
		);
	}

	/**
	 * The engine the site-from-menu crawl rebuilds pages with, and whether it
	 * can — never the key itself.
	 *
	 * Asked of Live_Page_Restyler::engine() rather than read from the
	 * active-engine setting, because the two can still differ. The restyler
	 * uses the engine chosen in Settings (or the one `dxai_ui_restyle_engine`
	 * names) whenever it has an API key. Only when it has none does the
	 * restyler fall back to DeepSeek, and only if a DeepSeek key is saved;
	 * otherwise no engine runs. The screen has to name the engine that will
	 * actually spend the credit, so it asks the code that will choose it.
	 *
	 * With no engine able to run, the answer is the active engine marked not
	 * ready, so the screen can say whose key is missing rather than that no
	 * engine exists.
	 *
	 * @return array<string, mixed>
	 */
	public static function crawl_engine(): array {
		$settings = Options::get_public();
		$active   = (string) ( $settings['active_engine'] ?? '' );
		$engine   = ( new Live_Page_Restyler() )->engine();
		$id       = is_wp_error( $engine ) ? $active : $engine->get_id();

		$crawl = self::describe_engine( $id, $settings );
		if ( is_wp_error( $engine ) ) {
			// Whatever Options says about its key, the restyler cannot run it.
			$crawl['ready'] = false;
		}
		// The same switch Site_From_Menu::prepare() asks; with no key the
		// restyle fails and every page falls back to the deterministic pass.
		$crawl['ai']        = $crawl['ready'] && (bool) apply_filters( 'dxai_ui_crawl_use_ai', true, 0 );
		$crawl['preferred'] = $id !== '' && $active !== '' && $id !== $active;
		$crawl['active']    = self::describe_engine( $active, $settings );
		// How the menu pages are built on this site ('design' unless a filter asks for 'ai').
		$crawl['pages']     = \DXAI_UI\Structures\Site_From_Menu::engine( array(), 0 );

		return $crawl;
	}

	/**
	 * @param array<string, mixed> $settings Options::get_public() — secrets masked.
	 * @return array{id:string, name:string, model:string, model_label:string, ready:bool}
	 */
	private static function describe_engine( string $id, array $settings ): array {
		$map = array(
			'claude'   => array( 'Claude', 'anthropic_api_key', 'anthropic_model' ),
			'openai'   => array( 'OpenAI', 'openai_api_key', 'openai_model' ),
			'grok'     => array( 'Grok', 'xai_api_key', 'grok_model' ),
			'deepseek' => array( 'DeepSeek', 'deepseek_api_key', 'deepseek_model' ),
		);
		if ( ! isset( $map[ $id ] ) ) {
			return array(
				'id'          => '',
				'name'        => '',
				'model'       => '',
				'model_label' => '',
				'ready'       => false,
			);
		}
		list( $name, $key_field, $model_field ) = $map[ $id ];

		$model = Engine_Factory::resolve_model( (string) ( $settings[ $model_field ] ?? '' ) );
		$label = $model;
		foreach ( Engine_Factory::catalog() as $provider ) {
			if ( ( $provider['id'] ?? '' ) !== $id ) {
				continue;
			}
			foreach ( (array) ( $provider['models'] ?? array() ) as $row ) {
				if ( is_array( $row ) && ( $row['id'] ?? '' ) === $model ) {
					// "Claude Sonnet 5 (recommended)" names the model; the
					// parenthesis is advice for the Settings picker.
					$label = trim( (string) preg_replace( '/\s*\([^)]*\)\s*$/', '', (string) ( $row['label'] ?? $model ) ) );
				}
			}
		}

		return array(
			'id'          => $id,
			'name'        => $name,
			'model'       => $model,
			'model_label' => $label !== '' ? $label : $model,
			'ready'       => ! empty( $settings[ $key_field . '_configured' ] ),
		);
	}

	public function crawl_step( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$progress = Crawl_Job::advance( (string) $request->get_param( 'job' ), Crawl_Job::REQUEST_BUDGET );
		if ( ( $progress['error'] ?? '' ) === 'missing' ) {
			return new \WP_Error( 'dxai_ui_crawl_job', __( 'This crawl is no longer available. Start the import again.', 'dxai-ui' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( self::client_progress( $progress ) );
	}

	public function crawl_status( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$state = Crawl_Job::load( (string) $request->get_param( 'job' ) );
		if ( $state === null ) {
			return new \WP_Error( 'dxai_ui_crawl_job', __( 'This crawl is no longer available. Start the import again.', 'dxai-ui' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( self::client_progress( Crawl_Job::progress( $state ) ) );
	}

	/**
	 * A crawl's progress as the wizard reads it: once the job is done, its
	 * result carries the same page links and header/footer report a save
	 * answers with, because the finishing pass is what rebuilds the header's
	 * menus with the new pages.
	 *
	 * @param array<string, mixed> $progress Crawl_Job::progress().
	 * @return array<string, mixed>
	 */
	private static function client_progress( array $progress ): array {
		if ( ! is_array( $progress['result'] ?? null ) ) {
			return $progress;
		}
		$result = $progress['result'];
		if ( is_array( $result['pages_created'] ?? null ) ) {
			$result['pages_created'] = self::page_links( $result['pages_created'] );
		}
		if ( is_array( $result['chrome'] ?? null ) ) {
			$result['chrome'] = self::chrome_report( $result['chrome'], is_array( $result['chrome_mode'] ?? null ) ? $result['chrome_mode'] : array() );
		}
		$progress['result'] = $result;

		return $progress;
	}

	/**
	 * Edit and view links for the pages a crawl created, so the summary links
	 * to each one without guessing the admin or permalink structure.
	 *
	 * @param array<int, mixed> $pages Rows of id, label, path.
	 * @return array<int, mixed>
	 */
	private static function page_links( array $pages ): array {
		foreach ( $pages as $i => $row ) {
			$id = is_array( $row ) ? (int) ( $row['id'] ?? 0 ) : 0;
			if ( $id < 1 || get_post( $id ) === null ) {
				continue;
			}
			$pages[ $i ]['edit'] = (string) get_edit_post_link( $id, 'raw' );
			$pages[ $i ]['view'] = (string) get_permalink( $id );
		}

		return $pages;
	}

	/**
	 * What an import did with the site's header and footer, in the one shape
	 * the wizard's "header menus" and "footer widgets" phases read.
	 *
	 * The header is built in Appearance > Menus (Header_Menus::install()) and
	 * the footer in Appearance > Widgets (Footer_Widgets::install()), and each
	 * answers in its own terms: menus as location => term id, areas as
	 * area id => widget ids. Read raw, a term id would show as an item count.
	 * So the save passes both answers here under `header` and `footer`, and
	 * this names each menu, counts its items, names each area as the Widgets
	 * screen does, and says how many of someone's widgets were moved to
	 * Inactive Widgets (they are never deleted).
	 *
	 * A part that is absent was not reported and the wizard says so; an empty
	 * list means the design had none to create. A report already in this
	 * shape (the crawl's finishing pass may hand one back) passes through.
	 *
	 * What changed for other designs is said too, in `notices` (one sentence
	 * each) and in fields of its own: the design whose header was the site's
	 * until now (`header_replaced`), a location that keeps a person's own
	 * menu, which the header then draws instead of the design's
	 * (`locations_kept`, and the menu row's location label says it), a
	 * one-page import that left the site's header as it was (`site_kept`),
	 * and the design whose footer stood in the widget areas until now and
	 * whose pages keep it as a template part (`footer_replaced`).
	 *
	 * The header and footer choice (Chrome_Choice) comes with it when given:
	 * `mode` (install or keep), `mode_reason` and `mode_message`. A kept
	 * header or footer is no error — the part stays, and Menus or Widgets
	 * were not touched: `menus_kept` / `widgets_kept` hold the sentence, and
	 * there is no `menus` / `widgets` list, since none was written.
	 *
	 * @param array<string, mixed> $raw  header => Header_Menus::install(), footer => Footer_Widgets::install(),
	 *                                   either a \WP_Error or an array with `error`, or absent.
	 * @param array<string, mixed> $mode Chrome_Choice::resolve() (save()'s chrome_mode), or [].
	 * @return array<string, mixed>
	 */
	public static function chrome_report( array $raw, array $mode = array() ): array {
		if ( array_key_exists( 'menus', $raw ) || array_key_exists( 'widgets', $raw ) ) {
			return $raw;
		}
		$out     = array(
			'menus_url'   => admin_url( 'nav-menus.php' ),
			'widgets_url' => admin_url( 'widgets.php' ),
		);
		if ( '' !== (string) ( $mode['mode'] ?? '' ) ) {
			$out['mode']         = (string) $mode['mode'];
			$out['mode_reason']  = (string) ( $mode['reason'] ?? '' );
			$out['mode_message'] = (string) ( $mode['message'] ?? '' );
		}
		$notices = array();
		$name_of = static function ( array $source ): string {
			$title = trim( (string) ( $source['title'] ?? '' ) );

			return $title !== '' ? $title : (string) preg_replace( '/\.zip$/i', '', (string) ( $source['source'] ?? $source['archive'] ?? '' ) );
		};

		if ( array_key_exists( 'header', $raw ) ) {
			$header = $raw['header'];
			if ( is_wp_error( $header ) ) {
				$out['menus_error'] = $header->get_error_message();
			} elseif ( is_array( $header ) && 'kept' === ( $header['error'] ?? '' ) ) {
				$out['menus_kept'] = (string) ( $header['message'] ?? '' );
			} elseif ( is_array( $header ) && is_string( $header['error'] ?? null ) && $header['error'] !== '' && $header['error'] !== 'no_header' ) {
				$out['menus_error'] = (string) ( $header['message'] ?? $header['error'] );
			} else {
				$out['menus'] = array();
				$labels       = get_registered_nav_menus();
				$kept         = is_array( $header ) ? array_map( 'intval', (array) ( $header['locations_kept'] ?? array() ) ) : array();
				$site_kept    = is_array( $header ) ? (array) ( $header['site_kept'] ?? array() ) : array();
				foreach ( is_array( $header ) ? (array) ( $header['menus'] ?? array() ) : array() as $location => $menu_id ) {
					$term = wp_get_nav_menu_object( (int) $menu_id );
					if ( ! $term instanceof \WP_Term ) {
						continue;
					}
					// The items themselves rather than the term's count, which a
					// write earlier in this request may have left stale in cache.
					$items = wp_get_nav_menu_items( $term->term_id, array( 'update_post_term_cache' => false ) );
					$label = (string) ( $labels[ $location ] ?? $location );
					$row   = array(
						'id'             => (int) $term->term_id,
						// Term names are stored entity-escaped ("A &amp; B").
						'name'           => wp_specialchars_decode( $term->name, ENT_QUOTES ),
						'location'       => (string) $location,
						'location_label' => $label,
						'items'          => is_array( $items ) ? count( $items ) : 0,
						'edit_url'       => admin_url( 'nav-menus.php?action=edit&menu=' . (int) $term->term_id ),
					);
					$theirs = isset( $kept[ $location ] ) ? wp_get_nav_menu_object( (int) $kept[ $location ] ) : false;
					if ( $theirs instanceof \WP_Term ) {
						// The location keeps a person's own menu, and the site's
						// header draws it: this design's page no longer shows
						// the design's items until the location is given this menu.
						$their_name            = wp_specialchars_decode( $theirs->name, ENT_QUOTES );
						$row['renders']        = $their_name;
						$row['location_label'] = sprintf(
							/* translators: 1: menu location name, 2: the menu assigned to it. */
							__( 'not in %1$s, which keeps “%2$s”', 'dxai-ui' ),
							$label,
							$their_name
						);
						$out['locations_kept'][] = array(
							'location'       => (string) $location,
							'location_label' => $label,
							'menu'           => $their_name,
							'menu_id'        => (int) $theirs->term_id,
						);
						$notices[] = sprintf(
							/* translators: 1: menu location name, 2: the person's menu, 3: the design's menu. */
							__( '%1$s keeps your menu “%2$s”, so the header shows it instead of the design\'s “%3$s”. Assign “%3$s” to %1$s in Appearance › Menus › Manage Locations to show the design\'s items.', 'dxai-ui' ),
							$label,
							$their_name,
							$row['name']
						);
					} elseif ( $site_kept !== array() ) {
						$row['location_label'] = __( 'this page\'s header', 'dxai-ui' );
					}
					$out['menus'][] = $row;
				}
				if ( is_array( $header ) && ! empty( $header['logo']['set'] ) ) {
					$out['logo_set'] = true;
				}
				foreach ( is_array( $header ) ? (array) ( $header['locations_released'] ?? array() ) : array() as $location => $menu_id ) {
					$term = wp_get_nav_menu_object( (int) $menu_id );
					$name = $term instanceof \WP_Term ? wp_specialchars_decode( $term->name, ENT_QUOTES ) : '#' . (int) $menu_id;
					$out['locations_released'][] = array(
						'location'       => (string) $location,
						'location_label' => (string) ( $labels[ $location ] ?? $location ),
						'menu'           => $name,
						'menu_id'        => (int) $menu_id,
					);
					$notices[] = sprintf(
						/* translators: 1: menu location name, 2: the menu that was assigned to it. */
						__( '%1$s no longer shows “%2$s”, the menu of the header installed before: this design has nothing there. The menu itself is kept in Appearance › Menus.', 'dxai-ui' ),
						(string) ( $labels[ $location ] ?? $location ),
						$name
					);
				}
				if ( is_array( $header ) && ! empty( $header['replaced'] ) && is_array( $header['replaced'] ) ) {
					$out['header_replaced'] = array(
						'title'   => $name_of( $header['replaced'] ),
						'page_id' => (int) ( $header['replaced']['page_id'] ?? 0 ),
					);
					$notices[] = sprintf(
						/* translators: %s: title of the design whose header was the site's. */
						__( 'The theme\'s menu locations showed the header menus of “%1$s” until now; they show this design\'s now. The pages of “%1$s” keep their own header.', 'dxai-ui' ),
						$out['header_replaced']['title']
					);
				}
				if ( $site_kept !== array() ) {
					$out['site_kept'] = array(
						'title'   => $name_of( $site_kept ),
						'page_id' => (int) ( $site_kept['page_id'] ?? 0 ),
					);
					$notices[] = sprintf(
						/* translators: %s: title of the design whose header is the site's. */
						__( 'This page\'s header menus were created for this page only; the site\'s header stays that of “%s”.', 'dxai-ui' ),
						$out['site_kept']['title']
					);
				}
			}
		}

		if ( array_key_exists( 'footer', $raw ) ) {
			global $wp_registered_sidebars;

			$footer = $raw['footer'];
			if ( is_wp_error( $footer ) ) {
				$out['widgets_error'] = $footer->get_error_message();
			} elseif ( is_array( $footer ) && 'kept' === ( $footer['error'] ?? '' ) ) {
				$out['widgets_kept'] = (string) ( $footer['message'] ?? '' );
			} elseif ( is_array( $footer ) && is_string( $footer['error'] ?? null ) && $footer['error'] !== '' && $footer['error'] !== 'no_footer' ) {
				$out['widgets_error'] = (string) ( $footer['message'] ?? $footer['error'] );
			} else {
				// `no_footer`: the design's footer had nothing to put in an area.
				$out['widgets'] = array();
				foreach ( is_array( $footer ) ? (array) ( $footer['areas'] ?? array() ) : array() as $area => $ids ) {
					$area             = (string) $area;
					$out['widgets'][] = array(
						'area'    => $area,
						'name'    => (string) ( $wp_registered_sidebars[ $area ]['name'] ?? $area ),
						'widgets' => count( (array) $ids ),
					);
				}
				if ( is_array( $footer ) ) {
					$out['widgets_moved'] = count( (array) ( $footer['inactive'] ?? array() ) );
				}
				if ( is_array( $footer ) && ! empty( $footer['replaced'] ) && is_array( $footer['replaced'] ) ) {
					$handed                 = is_array( $footer['handed_back'] ?? null ) ? $footer['handed_back'] : array();
					$out['footer_replaced'] = array(
						'title'       => $name_of( $footer['replaced'] ),
						'page_id'     => (int) ( $footer['replaced']['page_id'] ?? 0 ),
						// Its pages that keep its footer as a template part.
						'pages'       => array_map( 'intval', (array) ( $handed['pages'] ?? array() ) ),
						'part_id'     => (int) ( $handed['part_id'] ?? 0 ),
					);
					$notices[] = $out['footer_replaced']['pages'] !== array()
						? sprintf(
							/* translators: 1: title of the design whose footer stood in the widget areas, 2: number of its pages. */
							_n(
								'The widget areas held the footer of “%1$s” until now; its %2$d page keeps that footer as a template part (Appearance › Editor › Patterns).',
								'The widget areas held the footer of “%1$s” until now; its %2$d pages keep that footer as a template part (Appearance › Editor › Patterns).',
								count( $out['footer_replaced']['pages'] ),
								'dxai-ui'
							),
							$out['footer_replaced']['title'],
							count( $out['footer_replaced']['pages'] )
						)
						: sprintf(
							/* translators: %s: title of the design whose footer stood in the widget areas. */
							__( 'The widget areas held the footer of “%s” until now; no page shows it any more.', 'dxai-ui' ),
							$out['footer_replaced']['title']
						);
				}
			}
		}

		if ( $notices !== array() ) {
			$out['notices'] = $notices;
		}

		return $out;
	}

	/**
	 * Whose header and footer the site shows now, for the import screen to
	 * say before a save what it will change: the design installed last as
	 * the site's header (its menus hold the theme locations), and the design
	 * whose footer the widget areas hold — each with its title, archive,
	 * page_id, owner key, whether its page is still there (`live`) and the
	 * page's view and edit links (Chrome_Choice::owners()); the footer also
	 * with whether any of its pages shows it as the site footer block
	 * (`held`: installing another design's footer gives those pages theirs
	 * back as a template part). [] for an area no import installed.
	 *
	 * @param array{header:array<string, mixed>, footer:array<string, mixed>}|null $owners Chrome_Choice::owners(); null reads them.
	 * @return array{header:array<string, mixed>, footer:array<string, mixed>}
	 */
	public static function chrome_owner( ?array $owners = null ): array {
		$owners = $owners ?? \DXAI_UI\Chrome\Chrome_Choice::owners();
		$out    = array(
			'header' => (array) ( $owners['header'] ?? array() ),
			'footer' => (array) ( $owners['footer'] ?? array() ),
		);
		if ( $out['footer'] !== array() ) {
			$out['footer']['held'] = \DXAI_UI\Chrome\Footer_Widgets::holders() !== array();
		}

		return $out;
	}

	public function permissions(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * For the routes that import: they publish the design's HTML, CSS and
	 * JavaScript, so they need what WordPress itself requires for that —
	 * unfiltered_html, on top of manage_options.
	 *
	 * Without it, an import on a real site went wrong two ways at once. KSES
	 * runs on every post the import writes for a user who lacks the
	 * capability (a site admin on multisite, any site with
	 * DISALLOW_UNFILTERED_HTML), and removed the design's SVG, inputs,
	 * selects, iframes and a third of its CSS — measured, 21 of 21 pages
	 * changed. And the design's CSS and JavaScript FILES are not posts, so
	 * KSES never saw them: a user WordPress trusts with neither could publish
	 * script to every visitor. The error says which of the two is missing.
	 *
	 * @return true|\WP_Error
	 */
	public function publish_permissions(): bool|\WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			return new \WP_Error(
				'dxai_ui_unfiltered_html',
				self::unfiltered_html_message(),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Why this user may not import, in the words the import routes refuse
	 * with — the import screen shows the same sentence before anything is
	 * uploaded (import_info_payload()).
	 */
	public static function unfiltered_html_message(): string {
		return is_multisite()
			? __( 'Importing a design publishes its HTML, CSS and JavaScript, which on a multisite network only a Super Admin may do. Ask a Super Admin to run the import.', 'dxai-ui' )
			: __( 'Importing a design publishes its HTML, CSS and JavaScript, and this site does not allow your account to publish unfiltered HTML (DISALLOW_UNFILTERED_HTML may be set in wp-config.php).', 'dxai-ui' );
	}

	public function process_source( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$source = $this->resolve_source( $request );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$logs = array_map(
			static fn( string $message ) => Logger::entry( 'info', $message ),
			$source->logs
		);
		Logger::store( $logs );

		return rest_ensure_response(
			array(
				'source' => $this->client_source( $source ),
				'logs'   => $logs,
			)
		);
	}

	public function generate_block( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$source = $this->resolve_source( $request );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		$logs    = array();
		$chunk   = $request->get_param( 'chunk' );
		$index   = null;
		if ( $chunk !== null && $chunk !== '' ) {
			$index = absint( $chunk );
		}

		$compiler = new Source_Compiler();
		$native   = array();
		$fidelity = Fidelity_Gate::normalize_mode( (string) $request->get_param( 'fidelity' ) );
		if ( $index === null && $compiler->can_compile( $source ) ) {
			$native = $compiler->compile( $source );
			if ( ! empty( $native['structures'] ) ) {
				$logs[] = Logger::entry(
					'info',
					sprintf(
						'Compiled the ZIP to Gutenberg from source (%d sections).',
						count( $native['structures'] )
					)
				);
				$native = $this->maybe_refine_pass( $native, $fidelity, $logs );
			} else {
				return new \WP_Error(
					'dxai_ui_source_compile',
					__( 'This ZIP compiled to no Gutenberg blocks. Use a Lovable/Vite source archive with src/pages or src/routes — a dist-only export cannot be split into editable blocks.', 'dxai-ui' ),
					array( 'status' => 422 )
				);
			}
		}

		$result = $native;
		if ( empty( $result['structures'] ) ) {
			$engine = Engine_Factory::make();
			if ( is_wp_error( $engine ) ) {
				return $engine;
			}
			$logs[] = Logger::entry( 'info', 'Routing to ' . $engine->get_label() );
			$result = ( new Chunked_Generator( $engine ) )->generate(
				$source,
				(string) $request->get_param( 'notes' ),
				$index
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			// LLM-only path: still attach a confidence assessment for the UI.
			$result['fidelity_score'] = Fidelity_Confidence::assess( $result, $fidelity );
			$result['fidelity_mode']  = $fidelity;
		}

		$mapper   = new Gutenberg_Mapper();
		$combined = (string) ( $result['gutenberg_markup'] ?? '' );
		if ( $combined === '' || ! str_contains( $combined, '<!-- wp:' ) ) {
			foreach ( is_array( $result['structures'] ?? null ) ? $result['structures'] : array() as $structure ) {
				if ( is_array( $structure ) ) {
					$combined .= "\n" . (string) ( $structure['gutenberg_markup'] ?? '' );
				}
			}
		}
		$markup = $mapper->validate( $combined );
		if ( is_wp_error( $markup ) ) {
			return $markup;
		}

		if ( str_contains( (string) ( $result['gutenberg_markup'] ?? '' ), '<!-- wp:' ) ) {
			$result['gutenberg_markup'] = $markup;
		}

		$harvest  = is_array( $source->payload['harvest'] ?? null ) ? $source->payload['harvest'] : array();
		$is_final = empty( $result['chunk'] )
			|| (int) ( $result['chunk']['index'] ?? 0 ) === ( (int) ( $result['chunk']['total'] ?? 1 ) - 1 );
		if ( $is_final && ( $result['compiler'] ?? '' ) !== 'source' ) {
			$design = (string) ( $source->payload['design_css'] ?? '' );
			$sheet  = trim( $design . "\n" . (string) ( $harvest['css'] ?? '' ) );
			if ( $sheet !== '' ) {
				$result['custom_css'] = trim( $sheet . "\n" . (string) ( $result['custom_css'] ?? '' ) );
			}
		}
		$result['design_assets'] = $harvest;
		if ( ( $result['compiler'] ?? '' ) === 'source' && Tailwind_Purger::engine_available() ) {
			$raw_theme = (string) ( $result['design_css_raw'] ?? '' );
			$unresolved = ( new Tailwind_Purger() )->unresolved(
				$markup . ' ' . (string) ( $result['wrapper_class'] ?? '' ),
				$raw_theme
			);
			$result['unresolved_utilities'] = array_slice( $unresolved, 0, 80 );
			if ( $unresolved !== array() ) {
				$logs[] = Logger::entry(
					'warning',
					sprintf(
						'%d Tailwind utilities could not be compiled (first: %s).',
						count( $unresolved ),
						(string) $unresolved[0]
					)
				);
			}
		}
		$logs[] = Logger::entry(
			'info',
			isset( $result['chunk'] )
				? sprintf( 'Generated chunk %d/%d (%s)', (int) $result['chunk']['index'] + 1, (int) $result['chunk']['total'], (string) $result['chunk']['title'] )
				: 'Generated ' . $result['block_title']
		);
		Logger::store( $logs );

		return rest_ensure_response(
			array(
				'result' => $result,
				'source' => $this->client_source( $source ),
				'logs'   => $logs,
			)
		);
	}

	/** A save's status record lives this long — long enough for any save to end and be asked about. */
	private const SAVE_STATUS_TTL = HOUR_IN_SECONDS;

	/**
	 * Save an import, keeping a record of how the save is going.
	 *
	 * The save is one request, and on shared hosting it can outlive the proxy
	 * in front of PHP: nginx, Cloudflare (100 s) or LiteSpeed answers 502/504
	 * and the browser never hears the real answer, while PHP carries on and
	 * finishes. The wizard used to offer "Try again" at that point — and for a
	 * whole-site import the second save planned a second crawl of the same
	 * menu, which WP-Cron then ran next to the first: every menu page fetched,
	 * rebuilt with AI and saved twice. Observed on this site: two saves 4 s
	 * apart left two jobs for one page, and both ran to the end.
	 *
	 * So the wizard sends a random `import_token` with the save, and this
	 * keeps a per-user status record under it — running, then done with the
	 * whole answer, or failed with the error — that /save-status reads. A lost
	 * answer is then fetched instead of repeated, and the crawl the save
	 * planned is the one the wizard drives. The record is a transient, which
	 * every host has (the options table, or the object cache when there is
	 * one). A save without a token (the command line, an older screen) is
	 * saved exactly as before.
	 */
	public function save_pattern( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$token = self::save_token( (string) $request->get_param( 'import_token' ) );
		if ( $token === '' ) {
			return $this->save_now( $request );
		}
		$user = get_current_user_id();
		self::put_save_status(
			$token,
			$user,
			array(
				'state'   => 'running',
				'started' => time(),
			)
		);
		/*
		 * A proxy giving up closes the connection; PHP must not stop half-way
		 * through the writes when it notices, or the page would be saved and
		 * its crawl never planned. The same rule Crawl_Job::advance() follows.
		 */
		ignore_user_abort( true );
		// A fatal error (memory, max_execution_time) ends the request without
		// reaching the lines below; the record must still say it stopped.
		register_shutdown_function( array( self::class, 'save_interrupted' ), $token, $user );

		$response = $this->save_now( $request );
		if ( is_wp_error( $response ) ) {
			$data = $response->get_error_data();
			self::put_save_status(
				$token,
				$user,
				array(
					'state'    => 'failed',
					'finished' => time(),
					'code'     => (string) $response->get_error_code(),
					'message'  => $response->get_error_message(),
					'status'   => is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0,
				)
			);

			return $response;
		}
		self::put_save_status(
			$token,
			$user,
			array(
				'state'    => 'done',
				'finished' => time(),
				// The whole answer, so a wizard that lost it carries on as if
				// it had arrived: page links, audit, chrome report, crawl job.
				'payload'  => $response->get_data(),
			)
		);

		return $response;
	}

	/**
	 * /save-status: how the save with this token is going, for this user.
	 * `unknown` means WordPress has no record of it — the request never
	 * reached PHP (a proxy refused it, the connection dropped on the way) or
	 * came from someone else — so nothing was written under it.
	 */
	public function save_status( WP_REST_Request $request ): WP_REST_Response {
		$token  = self::save_token( (string) $request->get_param( 'token' ) );
		$record = $token === '' ? false : get_transient( self::save_status_key( $token, get_current_user_id() ) );
		if ( ! is_array( $record ) ) {
			return rest_ensure_response(
				array(
					'state' => 'unknown',
					'now'   => time(),
				)
			);
		}

		return rest_ensure_response( $record + array( 'now' => time() ) );
	}

	/**
	 * The shutdown half of save_pattern(): a record still `running` when the
	 * request ends belongs to a save PHP stopped — a fatal error, usually the
	 * memory or time limit — and says so, with PHP's own message.
	 */
	public static function save_interrupted( string $token, int $user ): void {
		$record = get_transient( self::save_status_key( $token, $user ) );
		if ( ! is_array( $record ) || ( $record['state'] ?? '' ) !== 'running' ) {
			return;
		}
		$last  = error_get_last();
		$fatal = is_array( $last ) && in_array( (int) $last['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true );
		// The message names a file; relative to the site root is enough to find it.
		$message = $fatal ? str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( (string) $last['message'] ) ) : '';
		self::put_save_status(
			$token,
			$user,
			array(
				'state'    => 'failed',
				'finished' => time(),
				'code'     => $fatal ? 'dxai_ui_save_fatal' : 'dxai_ui_save_stopped',
				'message'  => $fatal
					/* translators: %s: PHP's error message */
					? sprintf( __( 'PHP stopped the save: %s', 'dxai-ui' ), $message )
					: __( 'The save stopped before it finished, without an error message.', 'dxai-ui' ),
				'status'   => 500,
			)
		);
	}

	/** A token is 32 hex characters, made by the browser for one save attempt. */
	private static function save_token( string $token ): string {
		$token = strtolower( trim( $token ) );

		return preg_match( '/^[a-f0-9]{32}$/', $token ) ? $token : '';
	}

	/** Per user: nobody can read, or wait on, someone else's save. */
	private static function save_status_key( string $token, int $user ): string {
		return 'dxai_ui_save_' . md5( $user . ':' . $token );
	}

	/**
	 * @param array<string, mixed> $patch
	 */
	private static function put_save_status( string $token, int $user, array $patch ): void {
		$key    = self::save_status_key( $token, $user );
		$record = get_transient( $key );
		$record = is_array( $record ) ? $record : array();
		set_transient( $key, array_merge( $record, $patch ), self::SAVE_STATUS_TTL );
	}

	private function save_now( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$title  = sanitize_text_field( (string) $request->get_param( 'block_title' ) );
		$markup = (string) $request->get_param( 'gutenberg_markup' );
		$css    = (string) $request->get_param( 'custom_css' );
		$js     = (string) $request->get_param( 'custom_js' );
		$synced = filter_var( $request->get_param( 'synced' ), FILTER_VALIDATE_BOOLEAN );
		$raw    = $request->get_param( 'structures' );
		$structures = is_array( $raw ) ? $raw : array();
		$scope  = sanitize_key( (string) $request->get_param( 'scope' ) );
		$scope  = in_array( $scope, array( 'page', 'site' ), true ) ? $scope : 'site';
		$compiled = (string) $request->get_param( 'compiled_css' );

		if ( $title === '' ) {
			$title = __( 'DXAI-UI Pattern', 'dxai-ui' );
		}

		$payload = array();

		if ( $structures !== array() ) {
			$created = ( new Structure_Repository() )->save(
				array(
					'block_title'      => $title,
					'gutenberg_markup' => $markup,
					'compiler'         => sanitize_key( (string) $request->get_param( 'compiler' ) ),
					'custom_css'       => $css,
					'custom_js'        => $js,
					'compiled_css'     => $compiled,
					'scope'            => $scope,
					'structures'       => $structures,
					'design_assets'    => is_array( $request->get_param( 'design_assets' ) ) ? $request->get_param( 'design_assets' ) : array(),
					'pages'            => is_array( $request->get_param( 'pages' ) ) ? $request->get_param( 'pages' ) : array(),
					'wrapper_class'    => (string) $request->get_param( 'wrapper_class' ),
					'design_css_raw'   => (string) $request->get_param( 'design_css_raw' ),
					'slug'             => (string) $request->get_param( 'slug' ),
					/*
					 * Three fields Structure_Repository::save() reads that this
					 * endpoint used to drop, which is why the same ZIP imported
					 * through the admin came out different from the same ZIP
					 * imported on the command line.
					 *
					 * `static_html` is the Claude Design flag: without it the
					 * page takes Tailwind's Preflight and frontend-isolate.css,
					 * neither of which the design ever ran under, so its
					 * paragraph margins and box-sizing change.
					 * `tailwind_config` is a v3 design's whole theme — the
					 * Lovable template keeps its colours, radii, fonts and
					 * keyframes there, and the engine has none of them without
					 * it. `wrapper_style` is the inline style on the page root.
					 *
					 * The wizard already posts the compile result whole, so all
					 * three were arriving in the request and being ignored here.
					 */
					'static_html'      => filter_var( $request->get_param( 'static_html' ), FILTER_VALIDATE_BOOLEAN ),
					'tailwind_config'  => (string) $request->get_param( 'tailwind_config' ),
					'wrapper_style'    => (string) $request->get_param( 'wrapper_style' ),
					'source_html'      => (string) $request->get_param( 'source_html' ),
					/*
					 * What the conversion could not do, kept with the conversion.
					 *
					 * All four are computed during the compile, travel in the
					 * generate-block response, get rendered once by the wizard
					 * and were then dropped here — so a saved page carried no
					 * record of its own fidelity and the only way to ask was to
					 * convert the ZIP again. `unevaluated` is every expression
					 * the compiler gave up on, `islands` is the markup that
					 * stayed raw HTML by element, `unresolved_utilities` are
					 * Tailwind classes with no rule, and `fidelity_score` is the
					 * per-structure score. The command-line path already passes
					 * the whole compile result, so it stored them and the admin
					 * path did not: the same import produced two different
					 * records depending on where it was started.
					 */
					'unevaluated'      => is_array( $request->get_param( 'unevaluated' ) ) ? $request->get_param( 'unevaluated' ) : array(),
					'islands'          => is_array( $request->get_param( 'islands' ) ) ? $request->get_param( 'islands' ) : array(),
					'unresolved_utilities' => is_array( $request->get_param( 'unresolved_utilities' ) ) ? $request->get_param( 'unresolved_utilities' ) : array(),
					'fidelity_score'   => is_array( $request->get_param( 'fidelity_score' ) ) ? $request->get_param( 'fidelity_score' ) : null,
					/*
					 * And the counts only the design's own code could give.
					 *
					 * Design_Coverage reads `source_signals` to count lucide
					 * icons, JSX handlers, framer-motion elements, Radix
					 * components and scroll-driven state against what reached
					 * the page — and the archive is long gone by the time this
					 * request arrives, so the compiler counted them and put
					 * them in its result. Dropped here, five coverage rows read
					 * "unknown" on every admin import while the same ZIP on the
					 * command line reported them: the fifth field of exactly
					 * the same kind as the four above.
					 */
					'source_signals'   => is_array( $request->get_param( 'source_signals' ) ) ? $request->get_param( 'source_signals' ) : array(),
					// Per-request override of the copy/dynamic choice; absent,
					// the saved setting applies.
					'structure_mode'   => is_array( $request->get_param( 'structure_mode' ) )
						? Rewrite_Policy::sanitize( $request->get_param( 'structure_mode' ) )
						: null,
					'create_missing_pages' => filter_var( $request->get_param( 'create_missing_pages' ), FILTER_VALIDATE_BOOLEAN ),
					// The wizard drives the crawl through /crawl-step; an older
					// caller that does not ask gets the synchronous crawl.
					'defer_crawl'      => filter_var( $request->get_param( 'defer_crawl' ), FILTER_VALIDATE_BOOLEAN ),
					// How the menu pages are built: 'design' (the Home's own sections
					// with each old page's text and pictures, no AI) unless 'ai' is
					// asked for; and which of them, when the person chose (paths).
					'pages_engine'     => 'ai' === $request->get_param( 'pages_engine' ) ? 'ai' : 'design',
					'pages_only'       => array_values( array_filter( array_map( static fn( $p ) => \DXAI_UI\Connectors\Site_Origin::path_of( rawurldecode( (string) $p ) ), array_filter( (array) ( $request->get_param( 'pages_only' ) ?? array() ), 'is_scalar' ) ), static fn( $p ) => $p !== '' && $p !== '/' ) ),
					// The old site's address, when the person typed it (the design's links did not show it).
					'pages_origin'     => \DXAI_UI\Connectors\Site_Origin::normalize( (string) $request->get_param( 'pages_origin' ) ),
					/*
					 * Two more fields save() reads that this endpoint dropped.
					 *
					 * `source_name` is the uploaded archive's file name, and it is
					 * half of every page's identity (archive#slug) and the key of
					 * the design's header and footer. Without it an admin import —
					 * the only kind a real site has — fell back to identifying
					 * pages by slug and parts by title, so three designs with a
					 * `jobs.$slug` route overwrote one page, and seven designs
					 * titled "DevriX" shared one header. `seo_title` is the
					 * design's full <title>: without it the home page was named
					 * and titled differently than the same ZIP imported on the
					 * command line. Both arrive in the request already; the
					 * wizard posts the compile result whole.
					 */
					// wp_basename(), not basename(): it splits on "\" on every OS and
					// does not depend on the locale for a multibyte file name.
					'source_name'      => wp_strip_all_tags( wp_basename( (string) $request->get_param( 'source_name' ) ) ),
					'seo_title'        => sanitize_text_field( (string) $request->get_param( 'seo_title' ) ),
					/*
					 * The header and footer choice the import screen asks:
					 * `install` (Appearance > Menus and Widgets become this
					 * design's), `keep` (the site's stay untouched; the
					 * design's are template parts), or '' — automatic
					 * (Chrome_Choice::resolve()). Anything else is automatic.
					 */
					'chrome'           => \DXAI_UI\Chrome\Chrome_Choice::normalize( $request->get_param( 'chrome' ) ),
				)
			);
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$payload['structures'] = $created;
			$payload['scope']      = $scope;
			$payload['pages_created'] = is_array( $created['pages_created'] ?? null ) ? self::page_links( $created['pages_created'] ) : array();
			$payload['pages_skipped'] = is_array( $created['pages_skipped'] ?? null ) ? $created['pages_skipped'] : array();
			$payload['crawl_errors']  = is_array( $created['crawl_errors'] ?? null ) ? $created['crawl_errors'] : array();
			$payload['pages_restyled'] = (int) ( $created['pages_restyled'] ?? 0 );
			$payload['restyle_engine'] = (string) ( $created['restyle_engine'] ?? '' );
			// Set when the crawl was deferred: the wizard advances it through
			// /crawl-step until it reports done.
			$payload['crawl_job']      = (string) ( $created['crawl_job'] ?? '' );
			// What the import did with the header (Appearance > Menus) and the
			// footer (Appearance > Widgets), for the wizard's two phases —
			// save() reports the two install() answers as chrome.header and
			// chrome.footer. Absent, the wizard says it was not reported.
			// Which header and footer choice ran and why: mode (install or
			// keep), requested, reason, message, and whose they were before.
			$chrome_mode = is_array( $created['chrome_mode'] ?? null ) ? $created['chrome_mode'] : array();
			if ( $chrome_mode !== array() ) {
				$payload['chrome_mode'] = $chrome_mode;
			}
			if ( is_array( $created['chrome'] ?? null ) ) {
				$payload['chrome'] = self::chrome_report( $created['chrome'], $chrome_mode );
			}
			if ( ! empty( $created['page_id'] ) ) {
				$page_id         = (int) $created['page_id'];
				$payload['page'] = array(
					'id'          => $page_id,
					'edit'        => get_edit_post_link( $page_id, 'raw' ),
					'view'        => get_permalink( $page_id ),
					// Where this page's header and footer are edited: Appearance >
					// Menus and Widgets for the site header and footer blocks the
					// import put in the page, the template part for an area that
					// kept one (Part_Shortcuts::edit_links()).
					'edit_header' => \DXAI_UI\Admin\Part_Shortcuts::edit_url_for_page( $page_id, 'header', (int) ( $created['header_id'] ?? 0 ) ),
					'edit_footer' => \DXAI_UI\Admin\Part_Shortcuts::edit_url_for_page( $page_id, 'footer', (int) ( $created['footer_id'] ?? 0 ) ),
				);

				/*
				 * What the import checks found, and what the coverage report
				 * measured, so the response can say both. An import that
				 * "succeeded" used to mean only that the posts saved: an empty
				 * page, images with no source and raw component code rendered
				 * as copy all reported success and were found days later.
				 * These are the checks that fit in a request — the
				 * browser-dependent ones live in bin/verify-import.cjs, and the
				 * note says so.
				 *
				 * Structure_Repository::save() runs both now, for every page an
				 * import writes and on the command line as well as here, so
				 * this reads the results rather than measuring the page again.
				 * The fallback covers a save that produced a page without
				 * reporting one.
				 */
				$payload['audit'] = is_array( $created['audit'] ?? null )
					? $created['audit']
					: ( new Import_Audit() )->page( $page_id );
				if ( is_array( $created['coverage'] ?? null ) ) {
					$payload['coverage'] = $created['coverage'];
				}
			}
			if ( ! empty( $created['conversion_id'] ) ) {
				$payload['conversion'] = array(
					'id'   => (int) $created['conversion_id'],
					'edit' => get_edit_post_link( (int) $created['conversion_id'], 'raw' ),
				);
			}
		}

		if ( str_contains( $markup, '<!-- wp:' ) ) {
			/*
			 * Keyed by the page it came with, so importing the same design
			 * again updates this pattern instead of adding another copy of
			 * the whole page to the library each time.
			 */
			$page_key = isset( $payload['page']['id'] ) ? (string) get_post_meta( (int) $payload['page']['id'], '_dxai_ui_page_key', true ) : '';
			$saved    = ( new Pattern_Repository() )->save( $title, $markup, $css, $synced !== false, '', '', Pattern_Repository::key( $page_key, 'page', 1 ) );
			if ( is_wp_error( $saved ) ) {
				if ( $payload === array() ) {
					return $saved;
				}
			} else {
				// No style="" in stored markup, as for everything an import
				// writes (Structure_Repository hoists its own posts).
				if ( ! empty( $saved['id'] ) ) {
					Style_Hoister::hoist_import( array( (int) $saved['id'] ) );
				}
				$payload['pattern'] = $saved;
			}
		}

		if ( $payload === array() ) {
			return new \WP_Error( 'dxai_ui_save', __( 'Nothing to save: provide Gutenberg markup or structures.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( $payload );
	}

	public function preview( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$markup     = (string) $request->get_param( 'gutenberg_markup' );
		$css        = (string) $request->get_param( 'custom_css' );
		$compiled   = (string) $request->get_param( 'compiled_css' );
		$raw_css    = (string) $request->get_param( 'design_css_raw' );
		$wrapper    = $this->class_list( (string) $request->get_param( 'wrapper_class' ) );
		$structures = $request->get_param( 'structures' );

		if ( is_array( $structures ) ) {
			$chunks = array();
			foreach ( $structures as $structure ) {
				if ( ! is_array( $structure ) ) {
					continue;
				}
				if ( array_key_exists( 'included', $structure ) && false === $structure['included'] ) {
					continue;
				}
				$chunk = (string) ( $structure['gutenberg_markup'] ?? '' );
				if ( $chunk !== '' ) {
					$chunks[] = $chunk;
				}
			}
			if ( $chunks !== array() ) {
				$markup = implode( "\n", $chunks );
			}
		}

		if ( $markup === '' || ! str_contains( $markup, '<!-- wp:' ) ) {
			return new \WP_Error( 'dxai_ui_preview', __( 'No Gutenberg markup to preview.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		$html = do_blocks( $markup );
		$html = Design_Html::kses( $html );

		$isolate = '';
		$isolate_file = DXAI_UI_DIR . 'assets/css/frontend-isolate.css';
		if ( is_readable( $isolate_file ) ) {
			$isolate = (string) file_get_contents( $isolate_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		Css_Scoper::set_root_classes( $wrapper );
		$compile_src = '<div class="dxai-ui dxai-ui--0 ' . esc_attr( $wrapper ) . '">' . $html . '</div>';
		$sheet       = ( new Tailwind_Purger() )->compile( $compile_src, $css, 0, $compiled, $raw_css );
		$js          = (string) $request->get_param( 'custom_js' );
		if ( $js === '' && is_array( $request->get_param( 'result' ) ) ) {
			$js = (string) ( $request->get_param( 'result' )['custom_js'] ?? '' );
		}

		return rest_ensure_response(
			array(
				'html'          => $html,
				'css'           => $isolate . "\n" . $sheet,
				'js'            => $js,
				'wrapper_class' => $wrapper,
			)
		);
	}

	public function restore( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$id    = absint( $request->get_param( 'conversion_id' ) );
		$scope = sanitize_key( (string) $request->get_param( 'scope' ) );
		$saved = ( new Structure_Repository() )->restore( $id, $scope );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$payload = array(
			'structures' => $saved,
		);
		if ( ! empty( $saved['page_id'] ) ) {
			$page_id         = (int) $saved['page_id'];
			$payload['page'] = array(
				'id'   => $page_id,
				'edit' => get_edit_post_link( $page_id, 'raw' ),
				'view' => get_permalink( $page_id ),
			);
		}

		return rest_ensure_response( $payload );
	}

	public function list_patterns(): WP_REST_Response {
		$library = ( new Structure_Repository() )->library();
		$library['patterns'] = $library['patterns'] ?? ( new Pattern_Repository() )->list();

		return rest_ensure_response( $library );
	}

	/**
	 * Post-import / preview: refine one structure with the active LLM.
	 */
	public function refine_structure( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$structure = $request->get_param( 'structure' );
		if ( ! is_array( $structure ) ) {
			return new \WP_Error( 'dxai_ui_refine', __( 'Provide a structure object to refine.', 'dxai-ui' ), array( 'status' => 400 ) );
		}
		$issues = $request->get_param( 'issues' );
		$issues = is_array( $issues ) ? array_map( 'sanitize_key', $issues ) : array();
		$mode   = Fidelity_Gate::normalize_mode( (string) $request->get_param( 'fidelity' ) );
		if ( $issues === array() ) {
			$scored = Fidelity_Confidence::score_structure( $structure, 0 );
			$issues = $scored['issues'];
		}

		$engine = Engine_Factory::make();
		if ( is_wp_error( $engine ) ) {
			return $engine;
		}

		$refined = ( new Fidelity_Refiner( $engine ) )->refine_one( $structure, $issues, $mode );
		if ( is_wp_error( $refined ) ) {
			return $refined;
		}

		return rest_ensure_response(
			array(
				'structure' => $refined,
				'score'     => Fidelity_Confidence::score_structure( $refined, 0 ),
				'engine'    => $engine->get_id(),
			)
		);
	}

	/**
	 * Pass-2 fidelity refine after deterministic compile when the gate says so.
	 *
	 * @param array<string, mixed>      $native
	 * @param array<int, array<string, mixed>> $logs
	 * @return array<string, mixed>
	 */
	private function maybe_refine_pass( array $native, string $fidelity, array &$logs ): array {
		$assessment                 = Fidelity_Confidence::assess( $native, $fidelity );
		$native['fidelity_score']   = $assessment;
		$native['fidelity_mode']    = $fidelity;
		$logs[]                     = Logger::entry(
			'info',
			sprintf(
				'Fidelity score %d/100 (%d structure(s) flagged).',
				(int) $assessment['score'],
				count( $assessment['needs_refine'] )
			)
		);

		if ( ! Fidelity_Gate::should_refine( $assessment, $fidelity ) ) {
			return $native;
		}

		$engine = Engine_Factory::make();
		if ( is_wp_error( $engine ) ) {
			$logs[] = Logger::entry(
				'warning',
				$fidelity === 'strict'
					? __( 'Strict fidelity requested but no LLM key is configured — keeping Pass 1.', 'dxai-ui' )
					: __( 'Fidelity refine skipped (no LLM key).', 'dxai-ui' )
			);

			return $native;
		}

		$logs[] = Logger::entry(
			'info',
			sprintf(
				'Pass 2: refining %d structure(s) with %s (%s).',
				min( count( $assessment['needs_refine'] ), Fidelity_Gate::max_structures( $fidelity ) ),
				$engine->get_label(),
				$fidelity
			)
		);

		return ( new Fidelity_Refiner( $engine ) )->refine( $native, $assessment, $fidelity );
	}

	/**
	 * Strip large source blobs from the admin REST payload.
	 *
	 * @return array<string, mixed>
	 */
	private function client_source( Source_Document $source ): array {
		$arr = $source->to_array();
		$payload = is_array( $arr['payload'] ?? null ) ? $arr['payload'] : array();

		if ( isset( $payload['chunks'] ) && is_array( $payload['chunks'] ) ) {
			$slim = array();
			foreach ( $payload['chunks'] as $chunk ) {
				if ( ! is_array( $chunk ) ) {
					continue;
				}
				$slim[] = array(
					'id'    => (string) ( $chunk['id'] ?? '' ),
					'title' => (string) ( $chunk['title'] ?? '' ),
					'type'  => (string) ( $chunk['type'] ?? 'section' ),
					'page'  => is_array( $chunk['page'] ?? null ) ? $chunk['page'] : array(),
					'chars' => strlen( (string) ( $chunk['code'] ?? '' ) ),
				);
			}
			$payload['chunks'] = $slim;
		}

		if ( isset( $payload['components'] ) && is_array( $payload['components'] ) ) {
			$payload['components'] = array_map(
				static fn( $body ): int => is_string( $body ) ? strlen( $body ) : 0,
				$payload['components']
			);
		}

		if ( isset( $payload['styles'] ) && is_array( $payload['styles'] ) ) {
			$payload['styles'] = array_map(
				static fn( $body ): int => is_string( $body ) ? strlen( $body ) : 0,
				$payload['styles']
			);
		}

		unset( $payload['design_css'], $payload['design_css_raw'], $payload['sources'] );
		$arr['payload'] = $payload;

		return $arr;
	}

	private function resolve_source( WP_REST_Request $request ): Source_Document|\WP_Error {
		/*
		 * A body over post_max_size: PHP drops $_FILES and $_POST alike, so
		 * `source_type` is gone too and the request would read as a code
		 * paste with no code. Say what happened instead.
		 */
		if ( array() === $request->get_file_params() ) {
			$too_big = Zip_Extractor::upload_error( null );
			if ( null !== $too_big ) {
				return $too_big;
			}
		}

		$mode = sanitize_key( (string) $request->get_param( 'source_type' ) );
		if ( $mode === '' ) {
			$mode = 'lovable-code';
		}

		$files = $request->get_file_params();
		$zip   = $files['file'] ?? $files['zip'] ?? null;

		return match ( $mode ) {
			'figma-api'     => ( new Figma_Connector() )->fetch_from_api(
				(string) $request->get_param( 'figma_url' ),
				sanitize_text_field( (string) $request->get_param( 'file_key' ) ),
				sanitize_text_field( (string) $request->get_param( 'node_id' ) )
			),
			'figma-zip'     => is_array( $zip )
				? ( new Figma_Connector() )->import_zip( $zip )
				: new \WP_Error( 'dxai_ui_zip', __( 'Upload a Figma ZIP archive.', 'dxai-ui' ), array( 'status' => 400 ) ),
			'lovable-api'   => ( new Lovable_Connector() )->from_api(),
			/*
			 * Every archive route reads the archive.
			 *
			 * These modes used to pick a connector from the card the person
			 * clicked, so a React source export dropped on "Generated ZIP"
			 * compiled through the HTML connector and came out a different page
			 * than the same file on the command line. Zip_Router asks the file
			 * list instead, which is the one answer both paths can agree on.
			 * `auto-zip` is what the wizard sends now: no card, no
			 * classification asked of the person.
			 */
			'auto-zip', 'lovable-zip' => is_array( $zip )
				? Zip_Router::import( $zip )
				: new \WP_Error( 'dxai_ui_zip', __( 'Upload a design archive (.zip).', 'dxai-ui' ), array( 'status' => 400 ) ),
			'lovable-code'  => ( new Lovable_Connector() )->from_code(
				(string) $request->get_param( 'code' ),
				(string) $request->get_param( 'css' ),
				(string) $request->get_param( 'title' )
			),
			'generated-zip', 'claude-zip', 'dc-zip' => is_array( $zip )
				? Zip_Router::import( $zip )
				: new \WP_Error( 'dxai_ui_zip', __( 'Upload a generated design ZIP (HTML/CSS/JS, Vue, React, Next.js, or a Claude Design .dc.html export).', 'dxai-ui' ), array( 'status' => 400 ) ),
			default         => new \WP_Error( 'dxai_ui_source', __( 'Unknown source type.', 'dxai-ui' ), array( 'status' => 400 ) ),
		};
	}

	/**
	 * Tailwind class lists keep `%`, `[]` and `/` — `sanitize_text_field` strips them.
	 */
	private function class_list( string $classes ): string {
		$classes = wp_strip_all_tags( $classes );
		$classes = html_entity_decode( $classes, ENT_QUOTES );

		return trim( (string) preg_replace( '/\s+/', ' ', $classes ) );
	}
}

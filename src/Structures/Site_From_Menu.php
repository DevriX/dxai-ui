<?php
/**
 * Create WordPress pages from live same-host menu URLs, reusing Home chrome.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Structures;

use DXAI_UI\Compiler\Gutenberg_Mapper;
use DXAI_UI\Connectors\Live_Page_Fetcher;
use DXAI_UI\Connectors\Site_Origin;
use DXAI_UI\Media\Asset_Harvester;
use DXAI_UI\Media\Sideloader;
use DXAI_UI\Pages\Block_Tree;
use DXAI_UI\Pages\Content_Extractor;
use DXAI_UI\Pages\Image_Fit;
use DXAI_UI\Pages\Page_Composer;
use DXAI_UI\Pages\Section_Library;
use DXAI_UI\Theme\Blank_Template;

final class Site_From_Menu {

	/** Whether this run replaces pages someone edited since their import (the person asked: Site_Pages::start()). */
	private bool $rebuild_edited = false;

	/** The Home the pages are built from (page_for_path() finds the page built before for an old path by it). */
	private int $home_id = 0;

	/**
	 * Post meta on a crawled page: the page's own `post_modified_gmt` as it
	 * stood after the importer's LAST write to it.
	 *
	 * This is what tells a re-import whether a person has edited the page
	 * since. Before it existed, save_chrome_page() found the page by its key
	 * and wp_update_post()'d the new crawl over it unconditionally, so any
	 * edit made in the block editor went with the next import.
	 *
	 * The value is read back from the row, not taken from time(): both sides
	 * of the comparison in edited_since_import() are then the same MySQL GMT
	 * column at one-second resolution, with no PHP-vs-database clock or
	 * timezone between them.
	 *
	 * "Last write" is the hard part. save_chrome_page() writes the page twice
	 * (the insert/update, then the scope-wrapped content), and after create()
	 * returns, Structure_Repository hands every crawled page to
	 * Internal_Links::rewrite_posts(), which wp_update_post()s it a third time
	 * whenever an href changes (src/Compiler/Internal_Links.php:40). On the
	 * H2O import that third write lands minutes after the first page was
	 * saved — the crawl and restyle of the other eleven pages sit between
	 * them — so a stamp taken inside save_chrome_page() alone would already be
	 * older than post_modified when the import finished, and every page would
	 * read as "edited" on the next run. mark_imported() therefore also
	 * listens to `wp_insert_post` for the rest of the request and re-stamps
	 * any page it stamped earlier; see restamp().
	 */
	public const IMPORTED_AT = '_dxai_ui_imported_at';

	/**
	 * Crawled pages this request's importer has written, so its own later
	 * writes to them re-stamp IMPORTED_AT instead of reading as an edit.
	 *
	 * @var array<int, true>
	 */
	private static array $written = array();

	/**
	 * Crawl every page the menu links to, in one go.
	 *
	 * The synchronous composition of prepare(), step() and finish(), kept for
	 * callers that are not a web request with a time limit — WP-CLI and the
	 * verification suites. The admin wizard runs the same three through
	 * Crawl_Job instead, a few steps per request, because on a real host this
	 * loop outlives the request (13 minutes for H2O's 15 pages).
	 *
	 * @param array<string, mixed>                                           $context Home save context.
	 * @param array<int, array{label?:string, url?:string, children?:array}> $menu_tree Combined header+footer tree.
	 * @return array{
	 *   pages_created:array<int, array{id:int, label:string, path:string}>,
	 *   pages_skipped:array<int, array{label:string, path:string, reason:string, id?:int}>,
	 *   crawl_errors:array<int, array{label:string, url:string, message:string}>,
	 *   link_pages:array<int, array{id:int, paths:array<int, string>}>,
	 *   path_map:array<string, int>,
	 *   origin:string,
	 *   site_css_url:string
	 * }
	 */
	public function create( array $context, array $menu_tree ): array {
		$run = $this->prepare( $context, $menu_tree );
		while ( $this->step( $run ) ) {
			// Each step does one small piece of one page; see step().
		}

		return $this->finish( $run )['summary'];
	}

	/**
	 * Everything the crawl needs before its first page: the origin, the
	 * candidate pages, the Home's chrome and stylesheets, the restyle guide.
	 * Returns a plain array (a "run") that step() advances and finish()
	 * closes, and that Crawl_Job stores between requests — so it holds no
	 * objects, only values.
	 *
	 * @param array<string, mixed> $context   Home save context.
	 * @param array<int, mixed>    $menu_tree Combined header+footer tree.
	 * @return array<string, mixed>
	 */
	public function prepare( array $context, array $menu_tree ): array {
		$home_id = (int) ( $context['home_id'] ?? 0 );
		$summary = array(
			'pages_created'  => array(),
			'pages_skipped'  => array(),
			'crawl_errors'   => array(),
			'link_pages'     => array(),
			'path_map'       => array(),
			'origin'         => '',
			'site_css_url'   => '',
			'restyle_engine' => '',
			'pages_restyled' => 0,
		);
		$run     = array(
			'summary'    => $summary,
			'candidates' => array(),
			'index'      => 0,
			'phase'      => 'fetch',
			'current'    => array(),
			'setup'      => array(),
			// A run that stopped before its first page reports what it found
			// and nothing more — see finish().
			'settled'    => true,
		);

		// The old site's address as the person gave it, when they did: it wins over what the links suggest.
		$given = Site_Origin::normalize( (string) ( $context['origin'] ?? '' ) );
		if ( $home_id < 1 || ( $menu_tree === array() && $given === '' ) ) {
			return $run;
		}

		$origin = Site_Origin::from_menu_tree( $menu_tree );
		if ( $origin === '' ) {
			$origin = Site_Origin::from_html( (string) ( $context['source_html'] ?? '' ) );
		}
		if ( $given !== '' ) {
			if ( $origin !== '' && $origin !== $given ) {
				$menu_tree = Site_Origin::rehost( $menu_tree, $origin, $given );
			}
			$origin = $given;
		}
		$run['summary']['origin'] = $origin;
		if ( $origin === '' ) {
			$run['summary']['crawl_errors'][] = array(
				'label'   => '',
				'url'     => '',
				'message' => __( 'The design’s links do not show where the old site is. Enter its address to build its pages.', 'dxai-ui' ),
			);

			return $run;
		}

		// Every candidate first when the person chose pages (a page they ticked beyond the first 40 is kept), capped below.
		$chosen     = array_values( array_filter( array_map( static fn( $p ) => Site_Origin::path_of( (string) $p ), (array) ( $context['only_paths'] ?? array() ) ) ) );
		$cap        = $chosen !== array() ? PHP_INT_MAX : Site_Origin::MAX_PAGES;
		$candidates = Site_Origin::candidates( $menu_tree, $origin, $cap );
		// The design's menus lead to none of the old site's pages (placeholder links): its own navigation does.
		if ( $candidates === array() ) {
			$menu_tree  = array_merge( $menu_tree, Site_Origin::from_site( $origin ) );
			$candidates = Site_Origin::candidates( $menu_tree, $origin, $cap );
		}
		// Remembered on the Home: once the pages are built, the links that led to the old site lead to them
		// instead, and the Library's page list (Pages\Site_Pages) still has to know the old site and its pages.
		if ( $home_id > 0 && $candidates !== array() ) {
			update_post_meta( $home_id, \DXAI_UI\Pages\Site_Pages::ORIGIN_META, $origin );
			$known = (array) get_post_meta( $home_id, \DXAI_UI\Pages\Site_Pages::PAGES_META, true );
			$urls  = array_column( array_filter( $known, 'is_array' ), 'url' );
			foreach ( $candidates as $c ) {
				if ( ! in_array( (string) $c['url'], $urls, true ) ) {
					$known[] = array(
						'label' => (string) ( $c['label'] ?? '' ),
						'named' => (string) ( $c['label'] ?? '' ) !== '',
						'url'   => (string) $c['url'],
					);
				}
			}
			update_post_meta( $home_id, \DXAI_UI\Pages\Site_Pages::PAGES_META, array_values( $known ) );
		}
		// The pages the person chose (the Library's page list), when they chose; then the run's cap.
		if ( $chosen !== array() ) {
			$candidates = array_values( array_filter( $candidates, static fn( $c ) => in_array( Site_Origin::path_of( (string) ( $c['path'] ?? '' ) ), $chosen, true ) ) );
		}
		$candidates = array_slice( $candidates, 0, max( Site_Origin::MAX_PAGES, count( $chosen ) ) );
		if ( $candidates === array() ) {
			$run['summary']['crawl_errors'][] = array(
				'label'   => '',
				'url'     => $origin,
				'message' => __( 'Sorry — the menu had no same-host page URLs to crawl.', 'dxai-ui' ),
			);

			return $run;
		}

		$parts    = new Template_Part_Factory();
		$part_key = sanitize_title( trim( (string) ( $context['part_key'] ?? '' ), '/' ) );

		$home_css_url = (string) get_post_meta( $home_id, '_dxai_ui_css_url', true );
		$home_fonts   = get_post_meta( $home_id, '_dxai_ui_font_urls', true );

		$restyler   = new Live_Page_Restyler();
		$design_css = (string) ( $context['design_css'] ?? '' );
		if ( $design_css === '' && $home_css_url !== '' ) {
			$uploads = wp_upload_dir();
			$pattern = trailingslashit( (string) $uploads['basedir'] ) . 'dxai-ui/pattern-' . $home_id . '.css';
			if ( is_readable( $pattern ) ) {
				$design_css = (string) file_get_contents( $pattern );
			}
		}
		$structures = is_array( $context['structures'] ?? null ) ? $context['structures'] : array();
		if ( $structures === array() ) {
			$home_body = (string) get_post_field( 'post_content', $home_id );
			$home_body = preg_replace( '/<!--\s*wp:(?:template-part|dxai-ui\/site-header|dxai-ui\/site-footer)\b[^>]*-->/i', '', $home_body ) ?? $home_body;
			$structures = array(
				array(
					'type'        => 'section',
					'source_html' => substr( $home_body, 0, 18000 ),
				),
			);
		}
		/*
		 * The design's palette, computed once by Structure_Repository and shared
		 * with the final tokenising pass, so a colour the restyler writes as
		 * `var(--dxai-accent)` names the same token the rest of the design uses.
		 * Two separately computed palettes could name one colour differently,
		 * and a token that names nothing renders as no colour at all.
		 */
		$palette    = is_array( $context['palette'] ?? null ) ? $context['palette'] : array();
		$home_guide = Live_Page_Restyler::home_guide(
			$structures,
			$design_css,
			(string) ( $context['home_title'] ?? get_the_title( $home_id ) ),
			$palette
		);

		$site_css = Live_Content_Shell::persist_site_css( $home_id, $home_css_url );
		if ( ! is_wp_error( $site_css ) && is_string( $site_css ) && $site_css !== '' ) {
			$run['summary']['site_css_url'] = $site_css;
			// Never overwrite Home's stylesheet — child pages share a derived site sheet.
		} else {
			$site_css = $home_css_url;
		}

		$run['candidates'] = array_values( $candidates );
		$run['settled']    = false;
		$run['setup']      = array(
			'home_id'    => $home_id,
			'home_slug'  => Site_Origin::path_of( (string) ( $context['home_slug'] ?? '/' ) ),
			'origin'     => $origin,
			'header'     => (string) ( $context['chrome_markup']['header'] ?? $parts->reference_markup( 'header', $part_key ) ),
			'footer'     => (string) ( $context['chrome_markup']['footer'] ?? $parts->reference_markup( 'footer', $part_key ) ),
			'home_css'   => $home_css_url,
			/*
			 * How a page is built: 'design' composes it from the Home's own
			 * sections with the old page's words and pictures (software only,
			 * no model call); 'ai' restyles its HTML with the AI engine.
			 */
			'engine'     => self::engine( $context, $home_id ),
			'site_css'   => is_string( $site_css ) ? $site_css : $home_css_url,
			'home_fonts' => is_array( $home_fonts ) ? $home_fonts : array(),
			'home_js'    => (string) get_post_meta( $home_id, '_dxai_ui_js_url', true ),
			'home_wrap'  => $this->home_wrapper_class( $home_id, (string) ( $context['wrapper_class'] ?? '' ) ),
			'static'     => (bool) get_post_meta( $home_id, '_dxai_ui_static_html', true ),
			// Build again the pages someone edited since their import too — only when the person asked.
			'rebuild_edited' => ! empty( $context['rebuild_edited'] ),
			'archive'    => (string) ( $context['source_archive'] ?? '' ),
			'part_key'   => sanitize_title( trim( (string) ( $context['part_key'] ?? '' ), '/' ) ),
			'palette'    => $palette,
			'home_guide' => $home_guide,
			/*
			 * Whether pages are restyled by the AI engine or by the
			 * deterministic pass alone. Filterable, so a site can turn the
			 * paid calls off for the crawl, and so the crawl can be tested
			 * end to end without spending anyone's credit.
			 */
			'ai_ok'      => (bool) apply_filters( 'dxai_ui_crawl_use_ai', ! is_wp_error( $restyler->engine() ), $home_id ),
			/*
			 * Paths already compiled from the imported ZIP / design routes.
			 * When a menu candidate matches, keep that page — do not live-fetch.
			 *
			 * @var array<string, array{page_id:int, title:string, markup:string}>
			 */
			'zip_pages'  => is_array( $context['zip_pages'] ?? null ) ? $context['zip_pages'] : array(),
		);

		return $run;
	}

	/**
	 * The engine that builds the pages: 'design' unless the import asked for 'ai' (filterable).
	 *
	 * @param array<string, mixed> $context
	 */
	public static function engine( array $context, int $home_id ): string {
		$engine = (string) ( $context['pages_engine'] ?? 'design' );
		$engine = (string) apply_filters( 'dxai_ui_pages_engine', $engine === 'ai' ? 'ai' : 'design', $home_id );

		return $engine === 'ai' ? 'ai' : 'design';
	}

	/**
	 * The pages an earlier crawl of this design made for the pages this run
	 * will visit, as the run would record them: path_map (path => page id)
	 * and link_pages rows, plus which of them nobody edited since (unedited).
	 *
	 * Structure_Repository::save() puts them in its link and path maps when
	 * it defers the crawl, so a re-import keeps the Home's links, the
	 * header's menu items and the footer pointing at those pages while the
	 * new crawl runs — it updates them in place — rather than at the live
	 * site until it finishes, or for good when it never does. Read-only;
	 * found exactly as step() finds them (existing_page(): by the design's
	 * key, never another archive's page, never one in the trash).
	 *
	 * @param array<string, mixed> $run From prepare().
	 * @return array{path_map:array<string, int>, link_pages:array<int, array{id:int, paths:array<int, string>}>, unedited:array<int, int>}
	 */
	public static function known_pages( array $run ): array {
		$out   = array(
			'path_map'   => array(),
			'link_pages' => array(),
			'unedited'   => array(),
		);
		$setup = is_array( $run['setup'] ?? null ) ? $run['setup'] : array();
		if ( $setup === array() ) {
			return $out;
		}
		$self          = new self();
		$self->home_id = (int) ( $setup['home_id'] ?? 0 );
		$origin        = (string) ( $setup['origin'] ?? '' );
		$home   = (string) ( $setup['home_slug'] ?? '' );
		foreach ( (array) ( $run['candidates'] ?? array() ) as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$path = (string) ( $candidate['path'] ?? '' );
			if ( $path === '' || $path === '/' || $path === $home || isset( $out['path_map'][ $path ] ) ) {
				continue;
			}
			$page = $self->page_for_path( $path, sanitize_text_field( (string) ( $candidate['label'] ?? '' ) ), (string) ( $setup['archive'] ?? '' ) );
			if ( ! $page instanceof \WP_Post || 'publish' !== $page->post_status ) {
				continue;
			}
			$out['path_map'][ $path ] = (int) $page->ID;
			$out['link_pages'][]      = array(
				'id'    => (int) $page->ID,
				'paths' => $self->paths_for( $path, $origin ),
			);
			if ( ! self::edited_since_import( $page ) ) {
				$out['unedited'][] = (int) $page->ID;
			}
		}

		return $out;
	}

	/**
	 * Advance the run by one small piece of work on the current page, and
	 * say whether anything is left.
	 *
	 * A page goes through four phases, each short enough for one request on
	 * a host with a 60-second limit — except the restyle, which is a single
	 * AI call and can take longer (Crawl_Job lets that request finish on the
	 * server even when the browser stops waiting):
	 *  - fetch:   fetch the live page, keep its usable HTML and image list;
	 *  - images:  sideload its images, as many as fit in a few seconds, and
	 *             come back for the rest;
	 *  - restyle: turn it into blocks in the design's language (AI, or the
	 *             deterministic pass when no engine is configured or it fails);
	 *  - save:    write the page with the Home's chrome and stylesheets.
	 *
	 * @param array<string, mixed> $run From prepare(), advanced in place.
	 */
	public function step( array &$run ): bool {
		$this->rebuild_edited = ! empty( $run['setup']['rebuild_edited'] );
		$this->home_id        = (int) ( $run['setup']['home_id'] ?? 0 );
		$candidates = is_array( $run['candidates'] ?? null ) ? $run['candidates'] : array();
		$index      = (int) ( $run['index'] ?? 0 );
		if ( $index >= count( $candidates ) ) {
			return false;
		}
		$setup     = (array) $run['setup'];
		$candidate = (array) $candidates[ $index ];
		$path      = (string) ( $candidate['path'] ?? '' );
		$label     = sanitize_text_field( (string) ( $candidate['label'] ?? '' ) );
		$url       = (string) ( $candidate['url'] ?? '' );
		$origin    = (string) $setup['origin'];
		$phase     = (string) ( $run['phase'] ?? 'fetch' );
		$current   = is_array( $run['current'] ?? null ) ? $run['current'] : array();

		if ( $phase === 'fetch' ) {
			// An HTML design's own file names (about.html, ./contact.html, index.html) are the routes it was
			// compiled into (/about, /contact, /): matched here, they are kept, not fetched from the old site.
			$route = self::route_of( $path );
			if ( $path === '' || $path === $setup['home_slug'] || $path === '/' || $route === '/' ) {
				$run['summary']['pages_skipped'][] = array(
					'label'  => $label,
					'path'   => $path,
					'reason' => 'home',
				);

				return $this->next_page( $run );
			}

			/*
			 * A page a person has edited since the import that wrote it is
			 * theirs now: it is left exactly as it is and reported as skipped
			 * with reason 'edited'. It still goes into path_map and link_pages
			 * (keep_edited()), so the menus keep pointing at it and the other
			 * pages' links to it are still rewritten to its permalink.
			 *
			 * Asked here, before the fetch, and again in save_chrome_page():
			 * the fetch and the restyle behind it — an LLM call whenever an
			 * engine is configured — would otherwise be spent on a page whose
			 * result is then thrown away. save_chrome_page() keeps the check
			 * because it derives the slug from the fetched title when the path
			 * yields none, and it is the write that must never happen.
			 */
			$prior = $this->page_for_path( $path, $label, (string) $setup['archive'] );
			if ( $prior instanceof \WP_Post && ! $this->rebuild_edited && self::edited_since_import( $prior ) ) {
				$this->keep_edited( $run['summary'], $prior, $label, $path, $origin );

				return $this->next_page( $run );
			}

			/*
			 * Prefer the ZIP/design route already compiled and saved. Live scrape
			 * would overwrite a good page with foreign markup (or fail on SPA/WAF).
			 */
			$zip_hit = is_array( $setup['zip_pages'][ $path ] ?? null ) ? $setup['zip_pages'][ $path ] : ( is_array( $setup['zip_pages'][ $route ] ?? null ) ? $setup['zip_pages'][ $route ] : null );
			if ( is_array( $zip_hit ) && (int) ( $zip_hit['page_id'] ?? 0 ) > 0 ) {
				$this->keep_zip_route( $run['summary'], $zip_hit, $label, $path, $origin );

				return $this->next_page( $run );
			}

			// What the old site asks of crawlers (Crawl_Politeness): a path robots.txt disallows is not fetched, and
			// requests are spaced by its Crawl-delay. A wait longer than one step may take yields; the next step
			// comes back to this same page.
			$robots = \DXAI_UI\Http\Crawl_Politeness::rules( $url );
			if ( ! \DXAI_UI\Http\Crawl_Politeness::allowed( $url, $robots ) ) {
				$run['summary']['pages_skipped'][] = array(
					'label'  => $label,
					'path'   => $path,
					'reason' => 'robots',
				);

				return $this->next_page( $run );
			}
			if ( ! \DXAI_UI\Http\Crawl_Politeness::wait( $url, $robots['delay'] ) ) {
				return true;
			}

			// Pinned to the design's own origin: a menu href — or a redirect it
			// answers with — that leads anywhere else is refused, not crawled.
			$fetched = ( new Live_Page_Fetcher() )->fetch( $url, 45, $origin );
			$wait    = is_wp_error( $fetched ) && $fetched->get_error_code() === 'dxai_ui_live_rate_limited' ? (float) ( $fetched->get_error_data()['retry_after'] ?? 60 ) : 0.0;
			\DXAI_UI\Http\Crawl_Politeness::mark( $url, max( $robots['delay'], $wait ) );
			// Asked to slow down: this page is tried again after the wait, up to three times.
			if ( $wait > 0 && (int) ( $run['slowed'] ?? 0 ) < 3 ) {
				$run['slowed'] = (int) ( $run['slowed'] ?? 0 ) + 1;

				return true;
			}
			$run['slowed'] = 0;
			// Bot protection: the crawl stops here. The rest of the pages are reported, never fetched around it.
			if ( is_wp_error( $fetched ) && $fetched->get_error_code() === 'dxai_ui_live_blocked' ) {
				$run['summary']['crawl_errors'][] = array(
					'label'   => $label,
					'url'     => $url,
					'message' => $fetched->get_error_message() . ' ' . __( 'The page builder stopped: the old site does not allow automated requests. Allow this server in its firewall, or build the remaining pages by hand.', 'dxai-ui' ),
				);
				$total = count( $candidates );
				for ( $rest = $index + 1; $rest < $total; $rest++ ) {
					$run['summary']['pages_skipped'][] = array(
						'label'  => sanitize_text_field( (string) ( $candidates[ $rest ]['label'] ?? '' ) ),
						'path'   => (string) ( $candidates[ $rest ]['path'] ?? '' ),
						'reason' => 'blocked',
					);
				}
				$run['index']   = $total;
				$run['phase']   = 'fetch';
				$run['current'] = array();

				return false;
			}
			if ( is_wp_error( $fetched ) ) {
				$run['summary']['crawl_errors'][] = array(
					'label'   => $label,
					'url'     => $url,
					'message' => sprintf(
						/* translators: %s: error detail */
						__( 'Sorry — could not fetch this page: %s', 'dxai-ui' ),
						$fetched->get_error_message()
					),
				);

				return $this->next_page( $run );
			}

			if ( ( $setup['engine'] ?? 'design' ) === 'design' && (string) ( $fetched['raw'] ?? '' ) !== '' ) {
				$page_url = (string) ( $fetched['url'] ?? $url );
				// The stylesheets' background pictures, within what is left of this request's time after the fetch.
				$spent    = microtime( true ) - (float) ( $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$model    = ( new Content_Extractor() )->extract( (string) $fetched['raw'], $page_url, \DXAI_UI\Pages\Linked_Styles::background_rules( (string) $fetched['raw'], $page_url, max( 0.0, 30.0 - $spent ) ) );
				if ( $model['sections'] !== array() ) {
					$pending = array();
					foreach ( (array) $model['images'] as $img ) {
						$pending[] = (string) $img['src'];
					}
					$run['current'] = array(
						'model'    => $model,
						'title'    => sanitize_text_field( (string) ( $fetched['title'] ?? 'Page' ) ),
						'pending'  => array_values( array_unique( array_filter( $pending ) ) ),
						'rewrites' => array(),
						'images'   => array(),
						'html'     => '',
					);
					$run['phase']   = 'images';

					return true;
				}
			}

			$html = Live_Content_Shell::prepare_html( (string) ( $fetched['html'] ?? '' ) );
			if ( $html === '' ) {
				$run['summary']['crawl_errors'][] = array(
					'label'   => $label,
					'url'     => $url,
					'message' => __( 'Sorry — no usable text or images found on the live page.', 'dxai-ui' ),
				);

				return $this->next_page( $run );
			}

			$harvest      = ( new Asset_Harvester() )->from_text( $html );
			$harvest_urls = array();
			foreach ( (array) ( $harvest['images'] ?? array() ) as $img ) {
				if ( is_string( $img ) && $img !== '' ) {
					$harvest_urls[] = $img;
				} elseif ( is_array( $img ) && ! empty( $img['url'] ) ) {
					$harvest_urls[] = (string) $img['url'];
				}
			}
			$pending = array_values(
				array_unique(
					array_filter(
						array_merge(
							is_array( $fetched['images'] ?? null ) ? $fetched['images'] : array(),
							$harvest_urls
						),
						'is_string'
					)
				)
			);
			$run['current'] = array(
				'html'     => $html,
				'title'    => sanitize_text_field( (string) ( $fetched['title'] ?? 'Page' ) ),
				'pending'  => $pending,
				'rewrites' => is_array( $harvest['rewrites'] ?? null ) ? $harvest['rewrites'] : array(),
				'images'   => array(),
			);
			$run['phase']   = 'images';

			return true;
		}

		if ( $phase === 'images' ) {
			// A few seconds of downloads per step; the rest on the next one.
			$start = microtime( true );
			while ( ! empty( $current['pending'] ) && microtime( true ) - $start < 8.0 ) {
				$img_url = (string) array_shift( $current['pending'] );
				if ( isset( $current['rewrites'][ $img_url ] ) ) {
					$current['images'][] = (string) $current['rewrites'][ $img_url ];
					continue;
				}
				$ingested = Sideloader::from_url( $img_url );
				if ( ! is_wp_error( $ingested ) && ! empty( $ingested['url'] ) ) {
					$current['rewrites'][ $img_url ] = (string) $ingested['url'];
					$current['images'][]             = (string) $ingested['url'];
				}
			}
			if ( empty( $current['pending'] ) ) {
				foreach ( $current['rewrites'] as $from => $to ) {
					if ( is_string( $from ) && is_string( $to ) && $from !== '' && $to !== '' ) {
						$current['html'] = str_replace( $from, $to, $current['html'] );
					}
				}
				$run['phase'] = 'restyle';
			}
			$run['current'] = $current;

			return true;
		}

		$title = $label !== '' ? $label : (string) ( $current['title'] ?? '' );
		if ( $title === '' ) {
			$title = ucwords( str_replace( array( '-', '/' ), ' ', trim( $path, '/' ) ) );
		}

		if ( $phase === 'restyle' && ! empty( $current['model'] ) ) {
			$body = self::compose_body( (int) $setup['home_id'], (array) $current['model'], $run['summary'], $path );
			if ( $body === '' ) {
				$run['summary']['crawl_errors'][] = array(
					'label'   => $label,
					'url'     => $url,
					'message' => __( 'Not built — the old page has nothing but its title, or none of its content fits a section of the design.', 'dxai-ui' ),
				);

				return $this->next_page( $run );
			}
			++$run['summary']['pages_restyled'];
			$run['summary']['restyle_engine'] = 'design';
			$h1                               = (string) ( $current['model']['h1'][0] ?? '' );
			// A page built before keeps its name (the menu's then, or the one someone gave it since).
			$prior                            = $label === '' ? $this->page_for_path( $path, $label, (string) $setup['archive'] ) : null;
			$named                            = $prior instanceof \WP_Post ? html_entity_decode( (string) $prior->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
			$run['current']                   = array(
				// Named by its menu item, or else as it was named before, or else by the old page's own heading.
				'title'     => $label !== '' ? $label : ( $named !== '' ? $named : ( $h1 !== '' ? $h1 : (string) ( $current['title'] ?? '' ) ) ),
				'body'      => $body,
				'seo_title' => (string) ( $current['model']['seo']['title'] ?? '' ),
				'composed'  => true,
			);
			$run['phase']                     = 'save';

			return true;
		}

		if ( $phase === 'restyle' ) {
			$restyler = new Live_Page_Restyler();
			$body     = '';
			$restyled = false;
			if ( ! empty( $setup['ai_ok'] ) ) {
				$styled = $restyler->restyle(
					array(
						'title'  => $title,
						'path'   => $path,
						'html'   => (string) $current['html'],
						'images' => (array) $current['images'],
					),
					(array) $setup['home_guide']
				);
				if ( ! is_wp_error( $styled ) ) {
					$body     = (string) ( $styled['markup'] ?? '' );
					$restyled = ! empty( $styled['restyled'] );
					if ( ! empty( $styled['engine'] ) ) {
						$run['summary']['restyle_engine'] = (string) $styled['engine'];
					}
				}
			}
			/*
			 * Before pass1 (foreign layout + palette snap): try composing from
			 * the Home's section library with the scraped content model — same
			 * path as pages_engine=design. Prefer that over raw Html_To_Blocks.
			 */
			if ( trim( $body ) === '' ) {
				$html = (string) ( $current['html'] ?? '' );
				$hid  = (int) ( $setup['home_id'] ?? 0 );
				if ( $html !== '' && $hid > 0 ) {
					$model = ( new Content_Extractor() )->extract( $html, $url !== '' ? $url : home_url( $path ), array() );
					if ( ! empty( $model['sections'] ) && is_array( $model['sections'] ) ) {
						$composed = self::compose_body( $hid, $model, $run['summary'], $path );
						if ( trim( $composed ) !== '' ) {
							$body                                 = $composed;
							$restyled                             = true;
							$run['summary']['restyle_engine']     = 'design-fallback';
						}
					}
				}
			}
			if ( trim( $body ) === '' ) {
				$body = $restyler->pass1( (string) $current['html'], (array) $setup['palette'] );
				if ( trim( $body ) !== '' ) {
					$run['summary']['restyle_engine'] = 'pass1';
				}
			}
			if ( trim( $body ) === '' ) {
				$run['summary']['crawl_errors'][] = array(
					'label'   => $label,
					'url'     => $url,
					'message' => __( 'Sorry — content could not be converted to Gutenberg blocks.', 'dxai-ui' ),
				);

				return $this->next_page( $run );
			}
			if ( $restyled ) {
				++$run['summary']['pages_restyled'];
			}
			// The page's own HTML is no longer needed once it is blocks.
			$run['current'] = array( 'body' => $body );
			$run['phase']   = 'save';

			return true;
		}

		// save — body only; Page_Chrome injects header/footer on the front.
		$content = trim( (string) ( $current['body'] ?? '' ) );
		$content = \DXAI_UI\Chrome\Page_Chrome::strip_blocks( $content );

		$composed = ! empty( $current['composed'] );
		$page_id  = $this->save_chrome_page(
			$title,
			$content,
			$path,
			(int) $setup['home_id'],
			// A composed page is the Home's own sections: the Home's own sheet, as is.
			$composed && (string) ( $setup['home_css'] ?? '' ) !== '' ? (string) $setup['home_css'] : (string) $setup['site_css'],
			(array) $setup['home_fonts'],
			(string) $setup['home_js'],
			(string) $setup['home_wrap'],
			(bool) $setup['static'],
			(string) $setup['archive'],
			new Gutenberg_Mapper()
		);

		if ( is_wp_error( $page_id ) && 'dxai_ui_page_edited' === $page_id->get_error_code() ) {
			$edited_data = $page_id->get_error_data();
			$edited      = get_post( (int) ( is_array( $edited_data ) ? ( $edited_data['id'] ?? 0 ) : 0 ) );
			if ( $edited instanceof \WP_Post ) {
				$this->keep_edited( $run['summary'], $edited, $label, $path, $origin );

				return $this->next_page( $run );
			}
		}

		if ( is_wp_error( $page_id ) || $page_id < 1 ) {
			$run['summary']['crawl_errors'][] = array(
				'label'   => $label,
				'url'     => $url,
				'message' => is_wp_error( $page_id ) ? $page_id->get_error_message() : __( 'Page save failed.', 'dxai-ui' ),
			);

			return $this->next_page( $run );
		}

		if ( $composed && (string) ( $current['seo_title'] ?? '' ) !== '' ) {
			update_post_meta( $page_id, \DXAI_UI\Support\Page_Title::SEO_META, sanitize_text_field( (string) $current['seo_title'] ) );
		}
		$run['summary']['pages_created'][] = array(
			'id'    => $page_id,
			'label' => $title,
			'path'  => $path,
		);
		$run['summary']['path_map'][ $path ] = $page_id;
		$run['summary']['link_pages'][]      = array(
			'id'    => $page_id,
			'paths' => $this->paths_for( $path, $origin ),
		);

		return $this->next_page( $run );
	}

	/**
	 * A page's sections composed from the Home's (Pages\Page_Composer), as block markup; '' when none fit.
	 *
	 * @param array<string, mixed> $model   Content_Extractor::extract().
	 * @param array<string, mixed> $summary The run's summary: the composer's log goes to compose_log.
	 */
	private static function compose_body( int $home_id, array $model, array &$summary, string $path ): string {
		static $libraries = array();
		if ( ! isset( $libraries[ $home_id ] ) ) {
			$libraries[ $home_id ] = Section_Library::for_page( $home_id );
		}
		// The Home's stylesheet: the composer reads the column counts its responsive grids are made for.
		static $sheets = array();
		if ( ! isset( $sheets[ $home_id ] ) ) {
			$file                = \DXAI_UI\Support\Upload_Paths::for_meta( $home_id, '_dxai_ui_css_url' )['path'];
			$sheets[ $home_id ] = $file !== '' && is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		$out = ( new Page_Composer( $libraries[ $home_id ], array( Image_Fit::class, 'fit' ), $sheets[ $home_id ] ) )->compose( $model );
		$summary['compose_log'][ $path ] = $out['log'];
		if ( $out['sections'] === array() ) {
			return '';
		}
		// A page that is only its title (a sitemap its plugin fills in the browser): nothing to build.
		$units = (array) $out['units'];
		if ( count( $units ) <= 1 && empty( $units[0]['paras'] ) && empty( $units[0]['list'] ) && empty( $units[0]['items'] ) && empty( $units[0]['faq'] ) ) {
			$summary['compose_log'][ $path ][] = 'only a title on the old page — not built';
			return '';
		}
		$markup = Block_Tree::serialize_checked( $out['sections'] );

		return is_wp_error( $markup ) ? '' : trim( $markup );
	}

	/**
	 * The header or footer a crawled page opens or closes with: the Home's.
	 *
	 * The run was planned with the Home's template-part references. When the
	 * import has since built that area in Appearance > Menus or Widgets
	 * (Structure_Repository::install_chrome() puts the block in the Home and
	 * retires the part), the page gets the block instead — a crawl that runs
	 * after its import (Crawl_Job) must not reference a part that is gone.
	 * Asked of the Home as it is at save time, so a page gets what the Home
	 * has: the block, or the part it kept.
	 *
	 * @param array<string, mixed> $setup The run's setup (prepare()).
	 */
	private static function chrome_markup( array $setup, string $area ): string {
		static $seen = array();
		$planned = (string) ( $setup[ $area ] ?? '' );
		$home_id = (int) ( $setup['home_id'] ?? 0 );
		$block   = 'header' === $area ? \DXAI_UI\Chrome\Site_Header_Block::MARKUP : \DXAI_UI\Chrome\Site_Footer_Block::MARKUP;
		$needle  = 'header' === $area ? '<!-- wp:' . \DXAI_UI\Chrome\Site_Header_Block::NAME : '<!-- wp:' . \DXAI_UI\Chrome\Site_Footer_Block::NAME;
		if ( $home_id > 0 && str_contains( (string) get_post_field( 'post_content', $home_id ), $needle ) ) {
			return $block;
		}
		/*
		 * Otherwise exactly what the Home opens and closes with now: its template-part reference as it is, or
		 * the design's own header and footer blocks when the import kept them in the Home's content (a classic
		 * theme). The reference planned from the import's part key named a part the Home did not use on a
		 * classic theme, and those pages came out with no header or footer at all.
		 */
		if ( $home_id > 0 ) {
			// Read once per request and Home version: a run saves several pages in one request.
			$key = $home_id . '@' . (string) get_post_field( 'post_modified_gmt', $home_id, 'raw' );
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = \DXAI_UI\Pages\Section_Library::chrome_markup( $home_id );
			}
			$own = $seen[ $key ][ $area ];
			if ( $own !== '' ) {
				return $own;
			}
		}

		return $planned;
	}

	/**
	 * Close the run: the one check that needs every page's outcome.
	 *
	 * @param array<string, mixed> $run
	 * @return array<string, mixed>
	 */
	public function finish( array $run ): array {
		if ( ! empty( $run['settled'] ) ) {
			return $run;
		}
		// path_map holds every page this run created AND every edited page it
		// kept; a run that only kept edited pages did its job and is no error.
		if ( $run['summary']['path_map'] === array() && $run['summary']['crawl_errors'] === array() ) {
			$run['summary']['crawl_errors'][] = array(
				'label'   => '',
				'url'     => (string) ( $run['summary']['origin'] ?? '' ),
				'message' => __( 'Sorry — no additional pages were created from the live menu.', 'dxai-ui' ),
			);
		}
		$run['settled'] = true;

		return $run;
	}

	/**
	 * Move to the next candidate page; true when there is one.
	 *
	 * @param array<string, mixed> $run
	 */
	/** A path as a route: `.html`/`.htm` dropped, `index.html` as its folder (`/about.html` => `/about`, `/index.html` => `/`). */
	private static function route_of( string $path ): string {
		$route = (string) preg_replace( '#(^|/)index\.html?$#i', '$1', $path );
		$route = (string) preg_replace( '#\.html?$#i', '', $route );
		$route = '/' . trim( $route, '/' );

		return $route === '/' ? '/' : $route;
	}

	private function next_page( array &$run ): bool {
		++$run['index'];
		$run['phase']   = 'fetch';
		$run['current'] = array();

		return (int) $run['index'] < count( (array) $run['candidates'] );
	}

	/**
	 * Prefer the design root classes already on Home so child pages match chrome.
	 */
	private function home_wrapper_class( int $home_id, string $fallback ): string {
		$meta = trim( (string) get_post_meta( $home_id, '_dxai_ui_wrapper_class', true ) );
		if ( $meta !== '' ) {
			return $meta;
		}
		$content = (string) get_post_field( 'post_content', $home_id );
		if ( preg_match( '/class="[^"]*\bdxai-ui--' . $home_id . '\b([^"]*)"/', $content, $m ) ) {
			$rest = trim( preg_replace( '/\b(?:wp-block-group|alignfull|dxai-ui|dxai-ui--\d+)\b/', '', $m[1] ) ?? '' );

			return trim( $fallback . ' ' . $rest );
		}

		return $fallback;
	}

	/**
	 * @param array<int, string> $font_urls
	 */
	private function save_chrome_page(
		string $title,
		string $content,
		string $path,
		int $home_id,
		string $site_css,
		array $font_urls,
		string $home_js,
		string $home_wrap,
		bool $static,
		string $archive,
		Gutenberg_Mapper $mapper
	): int|\WP_Error {
		$slug = $this->page_slug( $path, $title );
		$key  = $archive === '' ? '' : $archive . '#' . $slug;

		$existing = $this->page_for_path( $path, $title, $archive );
		if ( $existing instanceof \WP_Post ) {
			// An earlier import's page keeps its key (it may carry the legacy slug).
			$held = (string) get_post_meta( (int) $existing->ID, '_dxai_ui_page_key', true );
			$key  = $held !== '' ? $held : $key;
		}
		// The update path used to run whatever the page held; a person's edit
		// is now the one thing it will not overwrite. create() turns this
		// error into a pages_skipped row with reason 'edited'.
		if ( $existing instanceof \WP_Post && ! $this->rebuild_edited && self::edited_since_import( $existing ) ) {
			return new \WP_Error(
				'dxai_ui_page_edited',
				__( 'Kept as it is — this page was edited after it was imported.', 'dxai-ui' ),
				array( 'id' => (int) $existing->ID )
			);
		}

		$data = array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => wp_slash( $title ),
			'post_name'    => $slug,
			'post_content' => wp_slash( $content ),
			'meta_input'   => array(
				'_dxai_ui_generated_page' => '1',
			),
		);
		if ( $key !== '' ) {
			$data['meta_input']['_dxai_ui_page_key']   = $key;
			$data['meta_input']['_dxai_ui_source_zip'] = $archive;
		}

		if ( $existing instanceof \WP_Post ) {
			$data['ID']        = $existing->ID;
			$data['post_name'] = $existing->post_name;
			$id                = wp_update_post( $data, true );
		} else {
			$id = wp_insert_post( $data, true );
		}

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$id = (int) $id;
		/*
		 * The sections stay top-level blocks, as on Home; the scope element —
		 * Home's scope class, so the shared site CSS (header utilities, prose)
		 * applies — is added around the rendered content by Page_Scope, which
		 * reads it from this meta.
		 */
		update_post_meta( $id, Page_Scope::META, $home_id );

		update_post_meta( $id, '_wp_page_template', Blank_Template::default_slug() );
		update_post_meta( $id, '_dxai_ui_wrapper_class', $home_wrap );
		update_post_meta( $id, '_dxai_ui_source_route', trim( $path, '/' ) );
		update_post_meta( $id, '_dxai_ui_from_live_menu', '1' );
		$part_key = sanitize_title( (string) get_post_meta( $home_id, \DXAI_UI\Chrome\Page_Chrome::PART_KEY_META, true ) );
		if ( $part_key === '' ) {
			$part_key = sanitize_title( (string) ( $this->home_id > 0 ? get_post_meta( $this->home_id, \DXAI_UI\Chrome\Page_Chrome::PART_KEY_META, true ) : '' ) );
		}
		if ( $part_key !== '' ) {
			update_post_meta( $id, \DXAI_UI\Chrome\Page_Chrome::PART_KEY_META, $part_key );
		}
		$mode = (string) get_post_meta( $home_id, \DXAI_UI\Chrome\Page_Chrome::MODE_META, true );
		if ( $mode !== '' ) {
			update_post_meta( $id, \DXAI_UI\Chrome\Page_Chrome::MODE_META, $mode );
		}
		// Relative to uploads, whichever form the Home's meta or
		// persist_site_css() handed over — see Upload_Paths.
		if ( $site_css !== '' ) {
			update_post_meta( $id, '_dxai_ui_css_url', \DXAI_UI\Support\Upload_Paths::for_storage( $site_css ) );
		}
		if ( $home_js !== '' ) {
			update_post_meta( $id, '_dxai_ui_js_url', \DXAI_UI\Support\Upload_Paths::for_storage( $home_js ) );
		}
		update_post_meta( $id, '_dxai_ui_font_urls', array_values( array_unique( array_map( 'strval', $font_urls ) ) ) );
		$home_tokens = get_post_meta( $home_id, \DXAI_UI\Compiler\Design_Tokens::META, true );
		if ( is_array( $home_tokens ) && $home_tokens !== array() ) {
			update_post_meta( $id, \DXAI_UI\Compiler\Design_Tokens::META, $home_tokens );
		}
		if ( $static ) {
			update_post_meta( $id, '_dxai_ui_static_html', '1' );
		} else {
			delete_post_meta( $id, '_dxai_ui_static_html' );
		}

		/*
		 * Stamped last, after the wrap_scope() wp_update_post() above — the
		 * later of this method's two writes to the row. The meta writes in
		 * between do not touch post_modified. Stamping after the first write
		 * instead would leave the wrap write newer than the stamp, and the
		 * importer's own second write would read as a person's edit.
		 */
		self::mark_imported( $id );

		return $id;
	}

	/**
	 * Record that the importer has just written this crawled page.
	 *
	 * Stamps IMPORTED_AT with the row's current post_modified_gmt and, for
	 * the rest of this request, re-stamps it after every further write to the
	 * same page (restamp() on `wp_insert_post`). That is how the link rewrite
	 * Structure_Repository runs after create() returns stays the importer's
	 * own write. A person cannot edit a page from inside the PHP request that
	 * is importing it, so every write to it in this request is the
	 * importer's — or a tool's running in the same process.
	 *
	 * Public for tools that rewrite crawled pages outside an import, such as
	 * bin/re-restyle-live-pages.php: a page one of them rewrites without
	 * calling this afterwards reads as edited, and later imports keep it.
	 */
	public static function mark_imported( int $page_id ): void {
		// Checked first: get_post( 0 ) falls back to the global $post.
		if ( $page_id < 1 ) {
			return;
		}
		$modified = (string) get_post_field( 'post_modified_gmt', $page_id, 'raw' );
		if ( $modified === '' ) {
			return;
		}
		update_post_meta( $page_id, self::IMPORTED_AT, $modified );

		self::$written[ $page_id ] = true;
		if ( false === has_action( 'wp_insert_post', array( self::class, 'restamp' ) ) ) {
			add_action( 'wp_insert_post', array( self::class, 'restamp' ), 10, 1 );
		}
	}

	/**
	 * `wp_insert_post` listener: a later write, in this request, to a page
	 * mark_imported() stamped. The action fires after the row is written and
	 * its cache cleared (wp-includes/post.php:5297), so the post_modified_gmt
	 * read back is the one this write set. Public only because a hook
	 * callback has to be.
	 *
	 * @param int $post_id Post just inserted or updated.
	 */
	public static function restamp( $post_id ): void {
		if ( isset( self::$written[ (int) $post_id ] ) ) {
			self::mark_imported( (int) $post_id );
		}
	}

	/**
	 * Whether a person has changed this crawled page since the importer last
	 * wrote it, so that a re-import must leave it alone.
	 *
	 * A page with an IMPORTED_AT stamp is edited when either
	 *
	 *   1. its post_modified_gmt is later than the stamp — every save a
	 *      person makes (block editor, Quick Edit, a revision restore, a status
	 *      change) goes through wp_update_post() and moves post_modified, while
	 *      the importer's own writes re-stamp (mark_imported()); or
	 *   2. it has a revision or autosave dated after the stamp. An autosave is
	 *      a person's unsaved work in the editor: it is a revision row, it
	 *      does NOT move the page's post_modified, and the block editor only
	 *      writes one when the post has changes. Measured 2026-09-24 on the
	 *      twelve H2O crawled pages: autosaves by user 1 dated 2026-09-24
	 *      08:47–09:00 on #88261, #88258 and #88056, after those pages' last
	 *      import write (2026-09-23 17:22–17:28).
	 *
	 * The obvious extra test — "a revision exists whose author is not the
	 * importing user" — is deliberately not used for stamped pages: the
	 * importer itself writes revisions under different users. On #88263 the
	 * revision authors, newest first, are 0, 0, 1, 1, 0, 1, 1 — user 0 for
	 * command-line runs, user 1 for wp-admin imports — so that test would call
	 * every page imported both ways "edited". What separates the importer's
	 * revisions from a person's is WHEN they were made relative to the stamp,
	 * and tests 1 and 2 already cover everything after it.
	 *
	 * A page imported before this stamp existed has none (all twelve H2O
	 * pages, as measured). For those, test 2 runs against the page's own
	 * post_modified_gmt, and one author test is used, on the newest
	 * non-autosave revision only — that revision is the content the page holds
	 * now. If it was made by a real user who is not the page's creator, a
	 * person other than whoever ran the import saved the current content.
	 * User 0 (no one logged in: a CLI run) and the creator are both treated as
	 * the importer, because nothing recorded can tell the creator's own edit
	 * from their import. Only the newest revision counts: an older one by
	 * another user was an edit a previous import already overwrote, and
	 * counting it would keep the page out of every future import.
	 */
	public static function edited_since_import( \WP_Post $page ): bool {
		global $wpdb;

		$page_id  = (int) $page->ID;
		$modified = (string) $page->post_modified_gmt;
		$stamp    = (string) get_post_meta( $page_id, self::IMPORTED_AT, true );

		if ( $stamp !== '' && $modified > $stamp ) {
			return true;
		}

		if ( $stamp === '' ) {
			$newest_author = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_author FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'revision' AND post_name NOT LIKE %s ORDER BY post_date_gmt DESC, ID DESC LIMIT 1",
					$page_id,
					'%-autosave-v1'
				)
			);
			if ( null !== $newest_author && ! in_array( (int) $newest_author, array( 0, (int) $page->post_author ), true ) ) {
				return true;
			}
		}

		// A revision's post_date_gmt is the page's post_modified_gmt when it
		// was made (_wp_post_revision_data()); an autosave that is updated in
		// place moves only its own post_modified_gmt. Either being later than
		// the stamp is a change the importer did not make.
		$since = $stamp !== '' ? $stamp : $modified;
		$newer = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'revision' AND ( post_date_gmt > %s OR post_modified_gmt > %s )",
				$page_id,
				$since,
				$since
			)
		);

		return $newer > 0;
	}

	/**
	 * Report an edited page as skipped, and keep it wired into the site.
	 *
	 * Left out of path_map, the menus would point that item back at the live
	 * origin; left out of link_pages, Home's links to it would no longer be
	 * rewritten to its permalink. Being in link_pages also means
	 * Internal_Links passes over its content, but it only replaces hrefs that
	 * still point at the design's origin — which the import that created the
	 * page already replaced — and it writes nothing when the markup comes out
	 * unchanged (src/Compiler/Internal_Links.php:37). If it does write, the
	 * page is not in self::$written, so it is not re-stamped and stays edited.
	 *
	 * @param array<string, mixed> $summary create()'s summary, by reference.
	 */
	private function keep_edited( array &$summary, \WP_Post $page, string $label, string $path, string $origin ): void {
		$summary['pages_skipped'][]   = array(
			'label'  => $label !== '' ? $label : (string) $page->post_title,
			'path'   => $path,
			'reason' => 'edited',
			'id'     => (int) $page->ID,
		);
		$summary['path_map'][ $path ] = (int) $page->ID;
		$summary['link_pages'][]      = array(
			'id'    => (int) $page->ID,
			'paths' => $this->paths_for( $path, $origin ),
		);
	}

	/**
	 * Keep a page already compiled from the design ZIP / route list.
	 *
	 * @param array<string, mixed>                $summary
	 * @param array{page_id:int, title?:string} $zip
	 */
	private function keep_zip_route( array &$summary, array $zip, string $label, string $path, string $origin ): void {
		$id = (int) ( $zip['page_id'] ?? 0 );
		if ( $id < 1 ) {
			return;
		}
		$title = $label !== '' ? $label : (string) ( $zip['title'] ?? get_the_title( $id ) );
		$summary['pages_skipped'][]   = array(
			'label'  => $title,
			'path'   => $path,
			'reason' => 'zip_route',
			'id'     => $id,
		);
		$summary['path_map'][ $path ] = $id;
		$summary['link_pages'][]      = array(
			'id'    => $id,
			'paths' => $this->paths_for( $path, $origin ),
		);
	}

	/**
	 * The crawled page's slug: its live path, dashed, under the `dxai-`
	 * prefix — or its title when the path yields nothing.
	 */
	private function page_slug( string $path, string $title ): string {
		$segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );
		$last     = sanitize_title( (string) end( $segments ) );
		if ( $last === '' ) {
			return $this->legacy_slug( $path, $title );
		}
		// The name is free, or already one of the import's own pages for THIS old page: the old URL's own name. A page
		// built from another old page with the same last segment (/residential/water-damage and /commercial/water-damage)
		// keeps its name; this one takes the whole path's.
		$taken = get_page_by_path( $last, OBJECT, 'page' );
		if ( ! $taken instanceof \WP_Post ) {
			return $last;
		}
		if ( (string) get_post_meta( $taken->ID, '_dxai_ui_generated_page', true ) === '1' ) {
			$route = trim( (string) get_post_meta( $taken->ID, '_dxai_ui_source_route', true ), '/' );
			if ( $route === '' || $route === trim( $path, '/' ) ) {
				return $last;
			}
		}

		return $this->legacy_slug( $path, $title );
	}

	/** The slug earlier imports gave a crawled page: "dxai-" and the whole path. */
	private function legacy_slug( string $path, string $title ): string {
		$slug_base = sanitize_title( str_replace( '/', '-', trim( $path, '/' ) ) );
		if ( $slug_base === '' ) {
			$slug_base = sanitize_title( $title );
		}

		return str_starts_with( $slug_base, 'dxai-' ) ? $slug_base : 'dxai-' . $slug_base;
	}

	/** The page an earlier import made for a path, under its current or its legacy slug. */
	private function page_for_path( string $path, string $title, string $archive ): ?\WP_Post {
		/*
		 * First the page built from this very old page for this Home, whatever its slug or its key: a page an
		 * earlier import made under another archive name, or renamed since, is still the one to update in place —
		 * never a second copy beside it.
		 */
		if ( $this->home_id > 0 ) {
			$built = \DXAI_UI\Pages\Site_Pages::page_for( $this->home_id, $path );
			if ( $built instanceof \WP_Post ) {
				return $built;
			}
		}

		return $this->existing_page( $this->page_slug( $path, $title ), $archive )
			?? $this->existing_page( $this->legacy_slug( $path, $title ), $archive );
	}

	/**
	 * The page an earlier import wrote for this slug: by its archive-qualified
	 * key first, then by slug among generated pages — a page keyed to another
	 * archive is that design's, and is never taken (the same rule as
	 * Structure_Repository::existing_page(); two designs with an "About" in
	 * their menus would otherwise share one crawled page). Pages in the trash
	 * are not found: one there was removed, and updating it would bring it
	 * back or, since trashing moved its modified date, keep it as "edited" —
	 * in the trash, with the menus pointing at it. Read-only.
	 */
	private function existing_page( string $slug, string $archive ): ?\WP_Post {
		$key      = $archive === '' ? '' : $archive . '#' . $slug;
		$statuses = array( 'publish', 'draft', 'pending', 'private', 'future' );

		$existing = null;
		if ( $key !== '' ) {
			$found = get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => $statuses,
					'posts_per_page' => 1,
					'meta_key'       => '_dxai_ui_page_key',
					'meta_value'     => $key,
				)
			);
			$existing = $found[0] ?? null;
		}
		if ( ! $existing instanceof \WP_Post ) {
			$found = get_posts(
				array(
					'post_type'      => 'page',
					'name'           => $slug,
					'post_status'    => $statuses,
					'posts_per_page' => 1,
					'meta_key'       => '_dxai_ui_generated_page',
					'meta_value'     => '1',
				)
			);
			$existing = $found[0] ?? null;
			if ( $existing instanceof \WP_Post && $key !== '' ) {
				$holder = (string) get_post_meta( (int) $existing->ID, '_dxai_ui_page_key', true );
				if ( $holder !== '' && $holder !== $key ) {
					$existing = null;
				}
			}
		}

		return $existing instanceof \WP_Post ? $existing : null;
	}

	/**
	 * @return array<int, string>
	 */
	private function paths_for( string $path, string $origin ): array {
		$path = Site_Origin::path_of( $path );
		$alts = array( $path );
		if ( $path !== '/' ) {
			$alts[] = $path . '/';
			$alts[] = rtrim( $path, '/' );
		}
		$origin = rtrim( $origin, '/' );
		if ( $origin !== '' ) {
			foreach ( array( $path, $path . '/' ) as $p ) {
				$alts[] = $origin . $p;
			}
		}

		return array_values( array_unique( $alts ) );
	}
}

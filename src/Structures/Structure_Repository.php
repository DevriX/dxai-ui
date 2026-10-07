<?php
/**
 * Persist generated structures as native WordPress objects (with revisions).
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Structures;

use DXAI_UI\Compiler\Css_Scoper;
use DXAI_UI\Compiler\Design_Html;
use DXAI_UI\Compiler\Dynamic_Rewriter;
use DXAI_UI\Compiler\Gutenberg_Mapper;
use DXAI_UI\Compiler\Internal_Links;
use DXAI_UI\Compiler\Rewrite_Policy;
use DXAI_UI\Compiler\Tailwind_Purger;
use DXAI_UI\Connectors\Site_Origin;
use DXAI_UI\Content\Content_Types;
use DXAI_UI\Patterns\Pattern_Repository;
use DXAI_UI\Theme\Blank_Template;
use DXAI_UI\Verification\Design_Coverage;
use DXAI_UI\Verification\Import_Audit;

final class Structure_Repository {

	/**
	 * What save() can build the site's chrome in: the header in Appearance >
	 * Menus (Header_Menus::install()), the footer in Appearance > Widgets
	 * (Footer_Widgets::install()). Converter_Controller::chrome_built() reads
	 * it, so the import screen promises exactly what this class does. Whether
	 * one import does is the person's choice (`chrome`: install, keep or
	 * automatic — Chrome_Choice); a kept header and footer, and one that
	 * cannot be built that way, stay template parts, and the save's `chrome`
	 * report and `chrome_mode` say so.
	 */
	public const BUILDS_CHROME = array(
		'menus'   => true,
		'widgets' => true,
	);

	/**
	 * @param array<string, mixed> $result LLM generation result.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function save( array $result ): array|\WP_Error {
		/*
		 * The same rule the REST routes apply (Converter_Controller::
		 * publish_permissions()), here too because not every caller is a REST
		 * request: a WP-CLI or cron run has no user unless one is set, and
		 * every page, part and pattern it wrote went through KSES — 16 pages
		 * lost their colours, an iframe and their form controls that way.
		 * Refused with a message saying how to run it instead.
		 */
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			return new \WP_Error(
				'dxai_ui_unfiltered_html',
				__( 'Importing a design publishes its HTML, CSS and JavaScript, so it must run as a user allowed to publish unfiltered HTML. From WP-CLI, add --user=<an administrator>.', 'dxai-ui' ),
				array( 'status' => 403 )
			);
		}
		$raw_title  = (string) ( $result['block_title'] ?? 'DX UI' );
		$seo_title  = trim( (string) ( $result['seo_title'] ?? '' ) );
		if ( $seo_title === '' && ( str_contains( $raw_title, '|' ) || strlen( $raw_title ) > 60 ) ) {
			$seo_title = $raw_title;
		}
		$route = trim( (string) ( $result['slug'] ?? '' ), '/' );
		$title = $route === ''
			? \DXAI_UI\Support\Page_Title::for_home( $seo_title !== '' ? $seo_title : $raw_title )
			: \DXAI_UI\Support\Page_Title::for_page( $raw_title, 'DXAI-UI' );
		$this->seo_title = $seo_title !== '' ? $seo_title : ( str_contains( $raw_title, '|' ) ? $raw_title : '' );
		$css        = (string) ( $result['custom_css'] ?? '' );
		$js         = (string) ( $result['custom_js'] ?? '' );
		$scope      = sanitize_key( (string) ( $result['scope'] ?? 'site' ) );
		$scope      = in_array( $scope, array( 'page', 'site' ), true ) ? $scope : 'site';
		$compiled   = (string) ( $result['compiled_css'] ?? '' );
		$structures = is_array( $result['structures'] ?? null ) ? $result['structures'] : array();
		$structures = $this->included_only( $structures );
		$policy           = Rewrite_Policy::from_result( $result );
		$this->inject_nav = $policy->is_dynamic( array(), 'navigation' );
		$structures       = ( new Dynamic_Rewriter( $policy ) )->rewrite_all( $structures );
		$design     = is_array( $result['design_assets'] ?? null ) ? $result['design_assets'] : array();
		$raw_css    = (string) ( $result['design_css_raw'] ?? '' );
		// The other half of a Tailwind v3 design's token declaration: its CSS
		// carries only raw HSL channels, and the config is what says which
		// utility reads which of them.
		$tw_config  = (string) ( $result['tailwind_config'] ?? '' );
		$wrap_style = (string) ( $result['wrapper_style'] ?? '' );
		// A design that never ran under Tailwind (a Claude Design export)
		// must not get Tailwind's preflight or the isolate reset either.
		$this->static_html = ! empty( $result['static_html'] );
		/*
		 * The archive, before anything is written: it is half of a page's
		 * identity, so save_page() needs it on the way in. Deliberately not
		 * sanitize_file_name()'d — that collapses whitespace to dashes, and
		 * bin/verify-import.cjs pairs a page with its design by comparing this
		 * to `path.basename(zip)` byte for byte.
		 */
		$this->source_archive = wp_strip_all_tags( wp_basename( (string) ( $result['source_name'] ?? '' ) ) );
		$wrapper_class = $this->class_list( (string) ( $result['wrapper_class'] ?? '' ) );
		// Design-authored root classes must keep matching the wrapper element
		// while its rules are being scoped, for patterns as well as the page.
		Css_Scoper::set_root_classes( $wrapper_class );
		/*
		 * The page this import owns and whether it existed before, read before
		 * anything is written. Its key (archive#slug, see existing_page()) is
		 * also who this design's menus belong to: they are found by it rather
		 * than by the design's title, which another archive can share.
		 */
		$home_slug             = (string) ( $result['slug'] ?? '/' );
		$previous_parts        = $this->previous_part_slugs( $home_slug, $title );
		list( , $owner )       = $this->page_identity( $home_slug, $title );
		$this->owner           = $owner;
		$this->adopt_legacy    = $this->previous_home_id > 0;
		$this->crawl_origin    = '';
		/*
		 * The site's header and footer: installed from this design (Appearance
		 * > Menus and Widgets) or kept as they are, with this design's own as
		 * template parts on its pages — as the person importing chose, or, for
		 * the automatic choice, as the site stands before anything below is
		 * written (Chrome_Choice::resolve()).
		 */
		$chrome_mode = \DXAI_UI\Chrome\Chrome_Choice::resolve(
			(string) ( $result['chrome'] ?? '' ),
			array(
				'scope'   => $scope,
				'owner'   => $owner,
				'archive' => $this->source_archive,
				'page_id' => $this->previous_home_id,
			)
		);
		$install_chrome = \DXAI_UI\Chrome\Chrome_Choice::INSTALL === $chrome_mode['mode'];
		$mapper     = new Gutenberg_Mapper();
		$nav        = $this->navigation_factory();
		$parts      = new Template_Part_Factory();
		$patterns   = new Pattern_Repository();

		$created = array(
			'conversion_id'  => 0,
			'page_id'        => 0,
			'header_id'      => 0,
			'footer_id'      => 0,
			'navigation_ids' => array(),
			'patterns'       => array(),
			// Which header and footer choice ran, and why (Chrome_Choice).
			'chrome_mode'    => $chrome_mode,
		);

		$header_markup = '';
		$footer_markup = '';
		$body_parts    = array();
		$primary_items = array();
		$footer_items  = array();
		$primary_tree  = array();
		$footer_tree   = array();
		$primary_nav   = 0;
		$source_chunks = array();
		/*
		 * When the site-from-menu pass will run, it saves both menus itself —
		 * as trees, with the crawled pages' permalinks — so a flat save here
		 * would only be overwritten a moment later: two writes of every menu
		 * item per import, and a window in which the menu pointed at the
		 * design's own URLs. The flat save still runs when nothing follows it,
		 * or when the header needs the menu injected (it needs the id now).
		 */
		$tree_follows = ! empty( $result['create_missing_pages'] ) && $scope === 'site' && ! $this->inject_nav && empty( $result['defer_crawl'] );
		/*
		 * The `{title} Primary` / `{title} Footer` wp_navigation posts are
		 * written only for an area that keeps its template part: once the
		 * header is built in Appearance > Menus and the footer in Widgets,
		 * nothing renders them, and Site Editor > Navigation listing them
		 * next to the menus that do render is how a person edits the wrong
		 * one. So unless the header needs the post's id now (inject_nav), they
		 * wait for install_chrome() — flat here, as trees after a crawl.
		 */
		$nav_pending = array();
		/*
		 * Each pattern this import saves has an identity — the page's own
		 * (archive#slug) plus its type and position — so a re-import updates
		 * it in place instead of inserting another wp_block and another sheet
		 * every time (this install had 966 of them for 36 pages, and 511
		 * orphaned sheets). No archive, no identity: a new pattern as before.
		 */
		list( , $pattern_context ) = $this->page_identity( (string) ( $result['slug'] ?? '/' ), $title );
		$pattern_slots             = array();

		foreach ( $structures as $structure ) {
			$type   = (string) ( $structure['type'] ?? 'section' );
			$markup = (string) ( $structure['gutenberg_markup'] ?? '' );
			$valid  = $mapper->validate( $markup );
			if ( is_wp_error( $valid ) ) {
				continue;
			}
			$markup = $valid;
			$src    = trim( (string) ( $structure['source_html'] ?? '' ) );
			if ( $src !== '' ) {
				$source_chunks[] = $src;
			}
			$markup = $this->scrub_artifacts( $markup );
			$pattern_slots[ $type ] = ( $pattern_slots[ $type ] ?? 0 ) + 1;
			$pattern_key            = Pattern_Repository::key( $pattern_context, $type, $pattern_slots[ $type ] );

			if ( 'header' === $type ) {
				$header_markup = $markup;
				if ( ! empty( $structure['menu_items'] ) ) {
					$primary_items = $structure['menu_items'];
				}
				if ( ! empty( $structure['menu_tree'] ) && is_array( $structure['menu_tree'] ) ) {
					$primary_tree = $structure['menu_tree'];
				}
				$saved = $patterns->save(
					(string) ( $structure['title'] ?? ( $title . ' Header' ) ),
					$markup,
					$css,
					true,
					$raw_css,
					$tw_config,
					$pattern_key
				);
				if ( ! is_wp_error( $saved ) ) {
					update_post_meta( (int) $saved['id'], '_dxai_ui_structure', 'header' );
					$created['patterns'][] = $saved;
				}
				continue;
			}
			if ( 'navigation' === $type ) {
				if ( ! empty( $structure['menu_items'] ) ) {
					$primary_items = $structure['menu_items'];
				}
				if ( ! empty( $structure['menu_tree'] ) && is_array( $structure['menu_tree'] ) ) {
					$primary_tree = $structure['menu_tree'];
				}
				$nav_id = 0;
				if ( $this->inject_nav ) {
					$nav_id = $tree_follows ? 0 : $nav->save( $title . ' Primary', $primary_items, 'dxai-primary' );
				} elseif ( ! $tree_follows ) {
					$nav_pending['header'] = true;
				}
				if ( $nav_id ) {
					$primary_nav                 = $nav_id;
					$created['navigation_ids'][] = $nav_id;
				}
				// Keep designed nav markup inside the header — never discard it.
				if ( $header_markup !== '' ) {
					$header_markup .= "\n" . $markup;
					$header_markup  = $this->inject_navigation( $header_markup, $nav_id );
				} else {
					$header_markup = $this->inject_navigation( $markup, $nav_id );
				}
				continue;
			}
			if ( 'footer' === $type ) {
				$footer_markup = $markup;
				if ( ! empty( $structure['menu_items'] ) ) {
					$footer_items = $structure['menu_items'];
					$nav_id       = 0;
					if ( $this->inject_nav ) {
						$nav_id = $tree_follows ? 0 : $nav->save( $title . ' Footer', $footer_items, 'dxai-footer' );
					} elseif ( ! $tree_follows ) {
						$nav_pending['footer'] = true;
					}
					if ( $nav_id ) {
						$created['navigation_ids'][] = $nav_id;
						$footer_markup               = $this->inject_navigation( $footer_markup, $nav_id );
					}
				}
				if ( ! empty( $structure['menu_tree'] ) && is_array( $structure['menu_tree'] ) ) {
					$footer_tree = $structure['menu_tree'];
				}
				$saved = $patterns->save(
					(string) ( $structure['title'] ?? ( $title . ' Footer' ) ),
					$footer_markup,
					$css,
					true,
					$raw_css,
					$tw_config,
					$pattern_key
				);
				if ( ! is_wp_error( $saved ) ) {
					update_post_meta( (int) $saved['id'], '_dxai_ui_structure', 'footer' );
					$created['patterns'][] = $saved;
				}
				continue;
			}

			$saved = $patterns->save(
				(string) ( $structure['title'] ?? ucfirst( $type ) ),
				$markup,
				$css,
				true,
				$raw_css,
				$tw_config,
				$pattern_key
			);
			if ( ! is_wp_error( $saved ) ) {
				update_post_meta( (int) $saved['id'], '_dxai_ui_structure', $type );
				$created['patterns'][] = $saved;
			}
			$body_parts[] = $markup;
		}

		if ( $primary_items !== array() && $primary_nav === 0 && ! $tree_follows ) {
			if ( ! $this->inject_nav ) {
				$nav_pending['header'] = true;
			} else {
				$nav_id = $nav->save( $title . ' Primary', $primary_items, 'dxai-primary' );
				if ( $nav_id ) {
					$primary_nav                 = $nav_id;
					$created['navigation_ids'][] = $nav_id;
					if ( $header_markup !== '' ) {
						$header_markup = $this->inject_navigation( $header_markup, $nav_id );
					}
				}
			}
		}

		$part_key  = self::part_key( $this->source_archive, $home_slug, $title );
		// The parts this design's page used until now ($previous_parts, read
		// above), so a part it no longer references can be retired once
		// everything is saved. A part only this page used moves to its new
		// name, keeping its id, its revisions and whatever was changed in it
		// in the Site Editor.
		$created['parts_renamed'] = $this->rename_own_parts( $previous_parts, $part_key );

		// Theme-global template parts (never theme header/footer slugs).
		// Each part records the archive it belongs to, as pages do.
		$part_meta = $this->source_archive === '' ? array() : array(
			'_dxai_ui_source_zip' => $this->source_archive,
			'_dxai_ui_part_key'   => $part_key,
		);
		// The Site Editor lists parts by title, and seven designs of one brand
		// would otherwise all read "DevriX Header".
		$part_label = $this->source_archive === '' ? $title : $title . ' (' . preg_replace( '/\.zip$/i', '', $this->source_archive ) . ')';
		/*
		 * The parts are written even when the header and footer end up in
		 * Menus and Widgets: the part is the post the link rewrite, the colour
		 * tokens and the style hoisting below all work on, so what the menus
		 * and widgets are built from (install_chrome()) is exactly what a
		 * part would have rendered. A part the previous import of this design
		 * retired that way is brought back first and updated in place, rather
		 * than a new part being written next to it on every import.
		 */
		if ( $header_markup !== '' ) {
			self::revive_part( 'header', $part_key );
			$created['header_id'] = $parts->save( 'header', $part_label . ' Header', $header_markup, $part_key, $part_meta );
		}
		if ( $footer_markup !== '' ) {
			self::revive_part( 'footer', $part_key );
			$created['footer_id'] = $parts->save( 'footer', $part_label . ' Footer', $footer_markup, $part_key, $part_meta );
		}

		// Pages hold body content only. Header/footer render via Page_Chrome
		// (Menus/Widgets stubs or template-part refs) around the_content.
		$page_parts   = $body_parts;
		$page_content = trim( implode( "\n", $page_parts ) );
		if ( $page_content === '' && ! empty( $result['gutenberg_markup'] ) ) {
			$page_content = (string) $result['gutenberg_markup'];
			// Strip chrome if the LLM/compiler dumped a whole-page blob.
			$page_content = \DXAI_UI\Chrome\Page_Chrome::strip_blocks( $page_content );
		}

		$full_source = trim( (string) ( $result['source_html'] ?? '' ) );
		if ( $full_source === '' && $source_chunks !== array() ) {
			$full_source = trim( implode( "\n", $source_chunks ) );
		}

		$page_id = $this->save_page( $title, $page_content, $css, $js, $design, $compiled, $wrapper_class, $raw_css, $home_slug, $full_source, $wrap_style, $tw_config );
		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}
		$created['page_id']        = $page_id;
		/*
		 * This design becomes the site's brand: its tokens go into the theme
		 * layer of theme.json, where they are presets every core block can use
		 * and a person can change once in Site Editor > Styles. save_page() has
		 * just resolved and stored them for this page. See Design_Theme_Json.
		 */
		\DXAI_UI\Theme\Design_Theme_Json::adopt( (int) $page_id, $this->source_archive );
		update_post_meta( (int) $page_id, \DXAI_UI\Chrome\Page_Chrome::PART_KEY_META, $part_key );
		update_post_meta(
			(int) $page_id,
			\DXAI_UI\Chrome\Page_Chrome::MODE_META,
			$install_chrome ? \DXAI_UI\Chrome\Chrome_Choice::INSTALL : \DXAI_UI\Chrome\Chrome_Choice::KEEP
		);
		// Island tag histogram from Pass-1 — Library / audit read this meta.
		$islands = is_array( $result['islands'] ?? null ) ? $result['islands'] : array();
		if ( $islands !== array() ) {
			arsort( $islands );
			$created['islands'] = $islands;
			update_post_meta( (int) $page_id, '_dxai_ui_islands', $islands );
		} else {
			delete_post_meta( (int) $page_id, '_dxai_ui_islands' );
		}
		$created['scope']          = $scope;
		$created['extra_page_ids'] = array();
		$created['pages_created']  = array();
		$created['pages_skipped']  = array();
		$created['crawl_errors']   = array();
		$created['pages_restyled'] = 0;
		$created['restyle_engine'] = '';

		$link_pages = array(
			array(
				'id'    => $page_id,
				'paths' => $this->link_paths( $home_slug, $title ),
			),
		);
		$path_map = array(
			'/' => $page_id,
		);

		foreach ( is_array( $result['pages'] ?? null ) ? $result['pages'] : array() as $extra ) {
			if ( ! is_array( $extra ) ) {
				continue;
			}
			$extra_markup = (string) ( $extra['gutenberg_markup'] ?? '' );
			$extra_raw    = sanitize_text_field( (string) ( $extra['block_title'] ?? '' ) );
			if ( $extra_markup === '' || $extra_raw === '' ) {
				continue;
			}
			/*
			 * A route page is named after itself and carries its own SEO title.
			 * It used to take its raw <title> as the page name ("Pricing |
			 * DevriX" in the page list and in every menu built from titles), and
			 * save_page() wrote the HOME page's SEO title onto it, so every route
			 * of a design printed the home page's <title>. The home value is put
			 * back afterwards for anything later in this import that reads it.
			 */
			$extra_title     = \DXAI_UI\Support\Page_Title::for_page( $extra_raw, $extra_raw );
			$extra_slug      = (string) ( $extra['slug'] ?? '' );
			$home_seo        = $this->seo_title;
			$this->seo_title = ( str_contains( $extra_raw, '|' ) || strlen( $extra_raw ) > 60 ) ? $extra_raw : '';
			$extra_id        = $this->save_page( $extra_title, $extra_markup, $css, $js, $design, $compiled, $wrapper_class, $raw_css, $extra_slug, '', '', $tw_config );
			$this->seo_title = $home_seo;
			if ( ! is_wp_error( $extra_id ) ) {
				$created['extra_page_ids'][] = $extra_id;
				update_post_meta( (int) $extra_id, \DXAI_UI\Chrome\Page_Chrome::PART_KEY_META, $part_key );
				update_post_meta(
					(int) $extra_id,
					\DXAI_UI\Chrome\Page_Chrome::MODE_META,
					$install_chrome ? \DXAI_UI\Chrome\Chrome_Choice::INSTALL : \DXAI_UI\Chrome\Chrome_Choice::KEEP
				);
				$link_pages[]                = array(
					'id'    => $extra_id,
					'paths' => $this->link_paths( $extra_slug, $extra_title ),
				);
				$extra_path = Site_Origin::path_of( $extra_slug );
				if ( $extra_path !== '' && $extra_path !== '/' ) {
					$path_map[ $extra_path ] = (int) $extra_id;
				}
			}
		}

		$path_map['/'] = $page_id;
		$home_path     = Site_Origin::path_of( $home_slug );
		if ( $home_path !== '' && $home_path !== '/' ) {
			$path_map[ $home_path ] = $page_id;
		}

		/*
		 * Routes already compiled from the ZIP (or Lovable pages[]): when the
		 * menu crawl later sees the same path, it must keep this page and not
		 * overwrite it with a live fetch.
		 */
		$zip_pages = array(
			'/' => array(
				'page_id' => (int) $page_id,
				'title'   => $title,
				'markup'  => '',
			),
		);
		if ( $home_path !== '' && $home_path !== '/' ) {
			$zip_pages[ $home_path ] = $zip_pages['/'];
		}
		foreach ( $path_map as $mapped_path => $mapped_id ) {
			$mapped_path = Site_Origin::path_of( (string) $mapped_path );
			if ( $mapped_path === '' || $mapped_path === '/' || (int) $mapped_id < 1 ) {
				continue;
			}
			$zip_pages[ $mapped_path ] = array(
				'page_id' => (int) $mapped_id,
				'title'   => (string) get_the_title( (int) $mapped_id ),
				'markup'  => '',
			);
		}

		/*
		 * The design's palette, computed ONCE, here — after the home page, its
		 * stylesheet, its route pages, sections and template parts are all
		 * written, and before any page is crawled. The same palette goes to the
		 * live restyler (so the colours it writes are this design's tokens) and
		 * to Token_Styles::tokenize_import() at the end. Computed twice, one
		 * colour could get two names, and a token naming nothing renders as no
		 * colour. Crawled pages are added to extra_page_ids further down, so
		 * this list is the design's own posts only.
		 */
		$design_posts = array_merge(
			array( (int) $page_id ),
			array_map( 'intval', (array) $created['extra_page_ids'] ),
			array_map(
				static fn( $p ): int => is_array( $p ) ? (int) ( $p['id'] ?? 0 ) : 0,
				(array) $created['patterns']
			),
			array( (int) $created['header_id'], (int) $created['footer_id'] )
		);
		$route_pages  = array_map( 'intval', (array) $created['extra_page_ids'] );
		$roles        = \DXAI_UI\Compiler\Design_Tokens::for_page( (int) $page_id );
		$palette      = \DXAI_UI\Compiler\Token_Styles::palette_for( $design_posts, (int) $page_id, $roles );
		$crawled      = array();

		$create_missing = ! empty( $result['create_missing_pages'] ) && $scope === 'site';
		$deferred       = $create_missing && ! empty( $result['defer_crawl'] );
		// Crawled pages of this design an earlier crawl made, which a deferred
		// crawl will update in place (see known_pages() below).
		$known_pages = array();
		$crawl_plan  = array();
		if ( $create_missing ) {
			$menu_tree = array_merge( $primary_tree, $footer_tree );
			if ( $menu_tree === array() && ( $primary_items !== array() || $footer_items !== array() ) ) {
				foreach ( array_merge( $primary_items, $footer_items ) as $flat ) {
					if ( ! is_array( $flat ) ) {
						continue;
					}
					$menu_tree[] = array(
						'label'    => (string) ( $flat['label'] ?? '' ),
						'url'      => (string) ( $flat['url'] ?? '' ),
						'children' => array(),
					);
				}
			}
			$crawl_context = array(
				'home_id'         => $page_id,
				'home_slug'       => $home_slug,
				'part_key'        => $part_key,
				'source_html'     => $full_source,
				'source_archive'  => $this->source_archive,
				'wrapper_class'   => $wrapper_class,
				'structures'      => $structures,
				'design_css'      => $raw_css !== '' ? $raw_css : $css,
				'home_title'      => $title,
				'palette'         => $palette,
				'pages_engine'    => (string) ( $result['pages_engine'] ?? 'design' ),
				'only_paths'      => array_values( array_map( 'strval', (array) ( $result['pages_only'] ?? array() ) ) ),
				'origin'          => (string) ( $result['pages_origin'] ?? '' ),
				'zip_pages'       => $zip_pages,
			);
			if ( $deferred ) {
				/*
				 * Deferred: the crawl is planned here and run by Crawl_Job, a
				 * few steps per request, driven by the admin wizard (or by cron
				 * if the tab is closed). In one request it outlived every real
				 * host's time limit. Everything below still runs now for the
				 * design's own pages; finish_crawl() repeats the parts that
				 * need the crawled pages — menus, links, tokens, audits — from
				 * what is stored here.
				 */
				$crawl_plan = ( new Site_From_Menu() )->prepare( $crawl_context, $menu_tree );
				/*
				 * A re-import of a design whose crawl finished before: its
				 * crawled pages exist, and the new crawl will update them in
				 * place. Until it has, the header's menu items, the footer and
				 * the Home keep pointing at them — not back at the live site,
				 * where they would stay if the new crawl never finished (a
				 * closed tab under DISABLE_WP_CRON). They join the link and
				 * path maps as the crawl will put them there.
				 */
				$known_pages = Site_From_Menu::known_pages( $crawl_plan );
				if ( $known_pages['path_map'] !== array() ) {
					$this->crawl_origin = (string) ( $crawl_plan['summary']['origin'] ?? '' );
					foreach ( $known_pages['path_map'] as $known_path => $known_id ) {
						if ( ! isset( $path_map[ (string) $known_path ] ) ) {
							$path_map[ (string) $known_path ] = (int) $known_id;
						}
					}
					foreach ( $known_pages['link_pages'] as $known_row ) {
						$link_pages[] = $known_row;
					}
					$link_pages[0]['paths'] = $this->home_paths( (array) ( $link_pages[0]['paths'] ?? array() ), $this->crawl_origin, $home_path );
				}
				$created['crawl_job'] = Crawl_Job::create(
					array(
						'crawl' => $crawl_plan,
						'tail'  => array(
							'page_id'       => (int) $page_id,
							'title'         => $title,
							'home_path'     => $home_path,
							'primary_tree'  => $primary_tree,
							'footer_tree'   => $footer_tree,
							'primary_items' => $primary_items,
							'footer_items'  => $footer_items,
							'link_pages'    => $link_pages,
							'path_map'      => $path_map,
							'design_posts'  => $design_posts,
							'route_pages'   => $route_pages,
							'roles'         => $roles,
							'palette'       => $palette,
							'header_id'     => (int) $created['header_id'],
							'footer_id'     => (int) $created['footer_id'],
							'patterns'      => $created['patterns'],
							'conversion'    => 0,
							// Whose menus (Navigation_Factory), and what
							// install_chrome() did — set once it has run.
							'owner'         => $this->owner,
							'archive'       => $this->source_archive,
							'part_key'      => $part_key,
							'chrome'        => array(),
							// Crawled pages of an earlier crawl, not edited
							// since: finish_crawl() re-stamps them before its
							// link rewrite, as it does the pages it wrote.
							'known_pages'   => $known_pages['unedited'] ?? array(),
						),
					)
				);
			} else {
				$from_menu = ( new Site_From_Menu() )->create( $crawl_context, $menu_tree );
				// The menus' wp_navigation posts wait for install_chrome():
				// only an area that keeps its part gets one (save_menu_trees()).
				$crawled = $this->apply_crawl( $from_menu, $created, $link_pages, $path_map, $home_path, $primary_tree, $footer_tree, $primary_items, $footer_items, $title, $this->inject_nav ? array() : array( 'header', 'footer' ) );
			}
		}

		/*
		 * A known crawled page (above) is written by the link rewrite and the
		 * token pass below only when something in it changes — still the
		 * importer's write, not a person's: re-stamped, as finish_crawl() does
		 * for the pages its crawl wrote, so the next crawl does not keep it as
		 * "edited".
		 */
		foreach ( (array) ( $known_pages['unedited'] ?? array() ) as $known_id ) {
			Site_From_Menu::mark_imported( (int) $known_id );
		}

		// Rewrite hrefs in Home, crawled pages, header/footer template parts,
		// and any synced header/footer patterns that still carry design URLs.
		$this->rewrite_links( $link_pages, $created );

		$created['parts_retired'] = $this->retire_parts(
			$previous_parts,
			array(
				Template_Part_Factory::slug( 'header', $part_key ),
				Template_Part_Factory::slug( 'footer', $part_key ),
			)
		);

		/*
		 * Every literal colour and shadow this design wrote becomes a token —
		 * `background:#F2A34A` becomes `background:var(--dxai-accent)` — across
		 * the page, its extra route pages, its sections and its header and
		 * footer, and in its compiled stylesheet. See Token_Styles.
		 *
		 * Here, at the end, because it needs the whole design at once: the
		 * header, footer and sections are saved before save_page() compiles the
		 * stylesheet, so the full palette is only knowable once all of them
		 * exist. And after the link rewrite, so the content it reads is the
		 * content that will be served.
		 *
		 * It uses the palette computed before the crawl, so the tokens the
		 * restyler wrote into crawled pages are the ones defined here. Crawled
		 * pages are rewritten too, but only as `derived` pages: they never add a
		 * colour to the palette, so nothing the restyler invented can become
		 * part of the brand.
		 */
		$created['tokens'] = \DXAI_UI\Compiler\Token_Styles::tokenize_import(
			$design_posts,
			(int) $page_id,
			$route_pages,
			$roles,
			$palette,
			$crawled
		);
		// The brand's presets are built from the palette just stored; the
		// theme.json cache has to forget the version built without it.
		\DXAI_UI\Theme\Design_Theme_Json::flush();

		/*
		 * And then no inline styles: every block's CSS (tokens and all) moves
		 * from style="" to a dxs- class and the block's dxaiCss attribute, and
		 * a text colour that is a site preset becomes core's colour setting.
		 * See Style_Hoister. After tokenising, so what moves is already
		 * written in tokens.
		 */
		$created['hoisted'] = \DXAI_UI\Compiler\Style_Hoister::hoist_import(
			array_merge( $design_posts, $crawled ),
			self::preset_slugs( (int) $page_id )
		);
		// The design's colours fitted to the active theme's, from its first view (Theme_Binding).
		\DXAI_UI\Theme\Theme_Binding::after_import( (int) $page_id );
		// Native blocks in place of the plugin's own, wherever the site has them (Native_Blocks).
		\DXAI_UI\Blocks\Native\Native_Blocks::after_import( (int) $page_id );
		\DXAI_UI\Media\Font_Host::after_import( (int) $page_id );

		/*
		 * Audit every page this import wrote, here rather than in the REST
		 * controller.
		 *
		 * The controller used to run Import_Audit itself, which meant a page
		 * imported on the command line was never audited at all and a
		 * multi-route import carried an audit only on its first page. Auditing
		 * where the pages are written is what makes the two import paths report
		 * the same thing — and the link rewrite above has already run, so what
		 * is measured is the page as it will be served.
		 */
		/*
		 * The archive each page came from is recorded by save_page() itself,
		 * atomically with the page, because it is half of that page's identity
		 * — see existing_page(). Before that it was written by nothing but
		 * bin/purge-and-import-real-zips.php, so a page imported through
		 * wp-admin could never be paired with its design and a corpus run
		 * reported eight pages as "no recorded source ZIP": honest, but it
		 * means those pages shipped unmeasured.
		 */
		$auditor = new Import_Audit();
		foreach ( $link_pages as $audited ) {
			$audit_id = (int) ( $audited['id'] ?? 0 );
			if ( $audit_id <= 0 ) {
				continue;
			}
			$audit = $auditor->page( $audit_id );
			update_post_meta( $audit_id, Import_Audit::META, $audit );
			if ( $audit_id === $page_id ) {
				$created['audit'] = $audit;
			}
		}

		/*
		 * And the coverage report: every dimension of the design counted on
		 * both sides — keyframes, animation declarations, design tokens, fonts,
		 * inline SVG and the shapes inside it, icons, images, the motion
		 * runtime's markers and its script.
		 *
		 * Here for the same reason as the audit: whichever way an archive was
		 * imported, the page it produced carries its own numbers, and the
		 * conversion record keeps them. It is measured against the primary page,
		 * which reads its template parts and the other routes too — the design
		 * side is the whole design, so the page side has to be as well.
		 */
		$coverage            = ( new Design_Coverage() )->measure( $result, $page_id );
		$created['coverage'] = $coverage;
		update_post_meta( $page_id, Design_Coverage::META, $coverage );

		/*
		 * The header into Appearance > Menus and the footer into Appearance >
		 * Widgets, built from the parts as they are now — linked, tokenised
		 * and hoisted like every other post of this design, so the menus'
		 * template and the widgets carry exactly the markup and utility
		 * classes the parts would have rendered. Last, after the audit and the
		 * coverage report: both read the parts a page references, and measure
		 * the same markup the blocks now render.
		 *
		 * Only when the choice is to install them. Kept, nothing of the
		 * site's is touched — no menu, location, widget or logo — and the
		 * pages keep the part references written above.
		 */
		$crawl_pages = array();
		foreach ( (array) $created['pages_created'] as $row ) {
			$crawl_pages[] = (int) ( is_array( $row ) ? ( $row['id'] ?? 0 ) : 0 );
		}
		// The parts' content as installed — kept with a deferred crawl, which
		// writes the part again from it if the part is gone by the time the
		// crawl finishes (finish_crawl()): nothing there reads the trash.
		$header_installed_from = (int) $created['header_id'] > 0 ? (string) get_post_field( 'post_content', (int) $created['header_id'] ) : '';
		$footer_installed_from = (int) $created['footer_id'] > 0 ? (string) get_post_field( 'post_content', (int) $created['footer_id'] ) : '';
		$created['chrome']     = $install_chrome
			? $this->install_chrome(
				$created,
				(int) $page_id,
				$title,
				$part_key,
				$crawl_pages,
				// Menu items are bound to pages only when there are crawled
				// pages to bind them to — this request's crawl, or the earlier
				// crawl a deferred one will update. Otherwise the header keeps
				// the design's own URLs, as its part had them.
				( $crawl_pages !== array() || ( $known_pages['path_map'] ?? array() ) !== array() ) ? $path_map : array(),
				$this->crawl_origin,
				$deferred
			)
			: self::kept_chrome( $created, $chrome_mode );

		// The skip link and the header spacer the Home opens with, kept so the DX template can draw them if the page loses them (Template_Chrome).
		\DXAI_UI\Chrome\Template_Chrome::remember_lead( (int) $page_id );

		// A design that becomes the site's template brings its fonts, hosted on this site (Template_Fonts). Never a failure of the import:
		// the answer says what was done, or why not.
		$created['fonts'] = \DXAI_UI\Theme\Template_Fonts::for_import( (int) $page_id, $chrome_mode );

		/*
		 * The wp_navigation posts, only for an area that kept its part (see
		 * $nav_pending): as trees bound to the crawled pages when a crawl ran
		 * in this request, flat otherwise; a deferred crawl writes its trees
		 * when it finishes.
		 */
		if ( ! $this->inject_nav ) {
			$kept_parts = array();
			foreach ( array( 'header', 'footer' ) as $area ) {
				if ( '' === (string) ( $created['chrome'][ $area ]['block_markup'] ?? '' ) ) {
					$kept_parts[] = $area;
				}
			}
			if ( $create_missing && ! $deferred ) {
				$this->save_menu_trees( $created, $title, $kept_parts, $primary_tree, $footer_tree, $primary_items, $footer_items, $path_map, $this->crawl_origin );
			} else {
				$this->save_menu_flat( $created, $title, array_values( array_intersect( $kept_parts, array_keys( $nav_pending ) ) ), $primary_items, $footer_items );
			}
		}

		$result['scope'] = $scope;
		$conversion = $this->save_conversion( $title, $result, $created );
		$created['conversion_id'] = $conversion;
		if ( ! empty( $created['crawl_job'] ) ) {
			/*
			 * What the crawl's finishing pass rebinds and re-links. The parts
			 * the blocks replaced are still published — they are retired once
			 * the crawl has re-linked them and the chrome is rebuilt from them
			 * (finish_crawl()); the footer's content as installed is kept too,
			 * in case its part is gone by then.
			 */
			Crawl_Job::update_tail(
				(string) $created['crawl_job'],
				array(
					'conversion' => (int) $conversion,
					'chrome'     => array(
						'mode'          => (string) $chrome_mode['mode'],
						'reason'        => (string) $chrome_mode['reason'],
						'header'        => ( $created['chrome']['header']['block_markup'] ?? '' ) !== '',
						'footer'        => ( $created['chrome']['footer']['block_markup'] ?? '' ) !== '',
						'header_part'   => (int) ( $created['chrome_parts']['header'] ?? 0 ),
						'footer_part'   => (int) ( $created['chrome_parts']['footer'] ?? 0 ),
						'header_markup' => ( $created['chrome']['header']['block_markup'] ?? '' ) !== '' ? $header_installed_from : '',
						'footer_markup' => ( $created['chrome']['footer']['block_markup'] ?? '' ) !== '' ? $footer_installed_from : '',
					),
					'header_id'  => (int) $created['header_id'],
					'footer_id'  => (int) $created['footer_id'],
				)
			);
		}

		return $created;
	}

	/**
	 * Build the header in Appearance > Menus and the footer in Appearance >
	 * Widgets from this import's parts, and put the two blocks where the
	 * parts were referenced.
	 *
	 * Each install runs on its own and can refuse: a header whose menu slots
	 * cannot be read so that the design's items render it exactly
	 * (`header_unreadable`, 6 of the 18 corpus headers), a design with no
	 * header or footer (`no_header` / `no_footer`), or anything thrown. That
	 * area then keeps its template part exactly as before. For an area that
	 * installed, the reference is replaced by the block in the home page and
	 * in every crawled page this import wrote — the header block where the
	 * header part was, first; the footer block last — and the part, which
	 * nothing references any more, goes to the trash (retire_part(): only a
	 * part this plugin generated, never deleted). A page that still
	 * references it (a crawled page a person edited, which the import leaves
	 * alone) keeps the part published. With a deferred crawl the parts are
	 * retired only when it finishes: its link rewrite and the chrome rebuilt
	 * from them need them (finish_crawl()).
	 *
	 * Runs only when the header and footer choice is to install them
	 * (Chrome_Choice): they become the site's — the theme locations, the
	 * Site Logo when the site has none, the widget areas — as the design's
	 * colours become the brand. The design they replace keeps its own on its
	 * pages: its header from its own menus (Header_Template::scoped()), its
	 * footer as a template part (install_site_footer()).
	 *
	 * @param array<string, mixed> $created  This import's record; header_id / footer_id become 0 for a
	 *                                       part that was retired, and chrome_parts records the ids of
	 *                                       the parts the blocks replaced.
	 * @param array<int, int>      $pages    Crawled pages this import wrote.
	 * @param array<string, int>   $path_map Design path => page id, for binding menu items; [] keeps the
	 *                                       design's own URLs.
	 * @param bool                 $deferred A crawl will run after this request (Crawl_Job).
	 * @return array{header?:array<string, mixed>, footer?:array<string, mixed>} install() answers.
	 */
	private function install_chrome( array &$created, int $page_id, string $title, string $part_key, array $pages, array $path_map, string $origin, bool $deferred ): array {
		$report = array();
		$blocks = array();
		$areas  = array(
			'header' => (int) ( $created['header_id'] ?? 0 ),
			'footer' => (int) ( $created['footer_id'] ?? 0 ),
		);
		foreach ( $areas as $area => $part_id ) {
			if ( $part_id < 1 ) {
				$report[ $area ] = array(
					'block_markup' => '',
					'error'        => 'header' === $area ? 'no_header' : 'no_footer',
				);
				continue;
			}
			$markup = (string) get_post_field( 'post_content', $part_id );
			try {
				$answer = 'header' === $area
					? \DXAI_UI\Chrome\Header_Menus::install(
						$markup,
						array(
							'title'        => $title,
							'archive'      => $this->source_archive,
							'page_id'      => $page_id,
							'scope_id'     => $page_id,
							'owner'        => $this->owner,
							'adopt_legacy' => $this->adopt_legacy,
							// Installed as the site's header: the person chose
							// it, or nobody else's is the site's (Chrome_Choice).
							'take_site'    => true,
							// Menu items bound to crawled pages (this request's
							// crawl, or an earlier one a deferred crawl will
							// update); with none, the design's own URLs, as the
							// part had them (a deferred crawl binds them when
							// it finishes: Header_Menus::rebind()).
							'path_map'     => $path_map,
							'origin'       => $path_map !== array() ? $origin : '',
						)
					)
					: self::install_site_footer(
						$markup,
						array(
							'page_id'  => $page_id,
							'title'    => $title,
							'source'   => $this->source_archive,
							'owner'    => $this->owner,
							'part_key' => $part_key,
						)
					);
			} catch ( \Throwable $e ) {
				$answer = array(
					'block_markup' => '',
					'error'        => 'exception',
					'message'      => sprintf(
						/* translators: 1: "header" or "footer", 2: error message. */
						__( 'The %1$s could not be built from the design, so it was kept as a template part: %2$s', 'dxai-ui' ),
						'header' === $area ? __( 'header', 'dxai-ui' ) : __( 'footer', 'dxai-ui' ),
						$e->getMessage()
					),
				);
			}
			$answer          = is_array( $answer ) ? $answer : array( 'block_markup' => '' );
			$report[ $area ] = $answer;
			if ( is_string( $answer['block_markup'] ?? null ) && $answer['block_markup'] !== '' ) {
				$blocks[ $area ] = $answer['block_markup'];
			}
		}
		if ( $blocks === array() ) {
			return $report;
		}

		$this->swap_chrome( array_merge( array( $page_id ), $pages ), $part_key, $blocks );
		// New pages are body-only; strip leftover chrome from re-imports / legacy.
		foreach ( array_merge( array( $page_id ), $pages ) as $pid ) {
			$pid = (int) $pid;
			if ( $pid < 1 ) {
				continue;
			}
			$was = (string) get_post_field( 'post_content', $pid );
			$now = \DXAI_UI\Chrome\Page_Chrome::strip_blocks( $was );
			// After swap, install mode may have left stubs — strip those too so Page_Chrome owns chrome.
			$now = \DXAI_UI\Chrome\Page_Chrome::strip_blocks( $now );
			if ( $now !== $was ) {
				wp_update_post(
					array(
						'ID'           => $pid,
						'post_content' => wp_slash( $now ),
					)
				);
			}
		}

		$created['chrome_parts'] = array();
		foreach ( array_keys( $blocks ) as $area ) {
			$created['chrome_parts'][ $area ] = (int) $areas[ $area ];
			if ( ! $deferred && $this->retire_part( (int) $areas[ $area ] ) ) {
				$created[ $area . '_id' ] = 0;
			}
		}

		return $report;
	}

	/**
	 * The footer into Appearance > Widgets as the site's footer — or not,
	 * and why.
	 *
	 * The widget areas hold one footer for the whole site. Installing one
	 * (the person chose to, or nobody else's is the site's: Chrome_Choice)
	 * makes it the site's, and whose it was until now decides the rest:
	 *  - nobody's, or this design's own (its page, its owner key): the areas
	 *    are rewritten in place;
	 *  - another design's that no page shows any more (its pages deleted, in
	 *    the trash, or holding its part again): the areas are free;
	 *  - another design's that its pages still show: those pages get their
	 *    footer back as a template part first (hand_back_footer()), so every
	 *    design keeps the footer it was imported with, exactly as it rendered.
	 *
	 * Public for the package import (Transfer\Page_Import), whose `install`
	 * means the same as a ZIP import's.
	 *
	 * @param string               $markup  The footer's block markup.
	 * @param array<string, mixed> $context page_id (the design's page), title, source (its archive),
	 *                                      owner (its page key, archive#slug) and part_key (the key
	 *                                      of its template part; derived from the archive when absent).
	 * @return array<string, mixed> Footer_Widgets::install()'s answer (with `handed_back` when a
	 *                              design's pages got their footer back), or block_markup '' with
	 *                              `error` and `message`.
	 */
	public static function install_site_footer( string $markup, array $context ): array {
		if ( \DXAI_UI\Chrome\Footer_Template::build( $markup )['slots'] === array() ) {
			return array(
				'block_markup' => '',
				'error'        => 'no_footer',
			);
		}
		$page_id  = (int) ( $context['page_id'] ?? 0 );
		$owner    = (string) ( $context['owner'] ?? '' );
		$source   = (string) ( $context['source'] ?? '' );
		$title    = (string) ( $context['title'] ?? '' );
		$part_key = (string) ( $context['part_key'] ?? '' );
		if ( $part_key === '' ) {
			$part_key = self::part_key( $source, (string) get_post_field( 'post_name', $page_id ), $title );
		}
		$record  = \DXAI_UI\Chrome\Footer_Template::get();
		$own     = $record === array()
			|| ( $page_id > 0 && (int) ( $record['scope'] ?? 0 ) === $page_id )
			|| ( $page_id > 0 && (int) ( $record['page_id'] ?? 0 ) === $page_id )
			|| ( $owner !== '' && (string) ( $record['owner'] ?? '' ) === $owner );
		$holders = $own ? array() : array_values( array_diff( \DXAI_UI\Chrome\Footer_Widgets::holders( $record ), array( $page_id ) ) );
		/**
		 * The pages of the site's current footer design that a design taking
		 * the widget areas gives their footer back to (as a template part).
		 * Nothing but those pages is written.
		 *
		 * @param array<int, int>      $holders Post ids (Footer_Widgets::holders()).
		 * @param array<string, mixed> $record  The stored footer they show.
		 * @param int                  $page_id The page of the design being imported.
		 */
		$holders = array_values( array_filter( array_map( 'intval', (array) apply_filters( 'dxai_ui_footer_holders', $holders, $record, $page_id ) ) ) );
		$held_by = \DXAI_UI\Chrome\Footer_Widgets::source_of( $record );
		$handed  = array();
		if ( $holders !== array() ) {
			$handed = self::hand_back_footer( $record, $holders );
			if ( is_wp_error( $handed ) ) {
				return array(
					'block_markup' => '',
					'error'        => 'footer_kept',
					'held_by'      => $held_by,
					'message'      => sprintf(
						/* translators: 1: title of the design whose footer is the site's, 2: error message. */
						__( 'The footer of “%1$s” in Appearance > Widgets could not be kept on its pages, so it stays there and this design keeps its footer as a template part: %2$s', 'dxai-ui' ),
						$held_by['title'] !== '' ? $held_by['title'] : $held_by['source'],
						$handed->get_error_message()
					),
				);
			}
		}
		$answer = \DXAI_UI\Chrome\Footer_Widgets::install(
			$markup,
			array(
				'page_id'  => $page_id,
				'scope'    => $page_id,
				'source'   => $source,
				'title'    => $title,
				'owner'    => $owner,
				'part_key' => $part_key,
			)
		);
		if ( $handed !== array() ) {
			$answer['handed_back'] = $handed;
		}

		return $answer;
	}

	/**
	 * What save() reports for a header and footer it kept (the choice was
	 * keep, Chrome_Choice): each stays the template part its pages reference,
	 * and nothing of the site's — menus, locations, widgets, logo — was
	 * written. An area the design has none of says so as install() would.
	 *
	 * @param array<string, mixed> $created This import's record (header_id, footer_id).
	 * @param array<string, mixed> $choice  Chrome_Choice::resolve().
	 * @return array{header:array<string, mixed>, footer:array<string, mixed>}
	 */
	private static function kept_chrome( array $created, array $choice ): array {
		$out = array();
		foreach ( array( 'header', 'footer' ) as $area ) {
			$part = (int) ( $created[ $area . '_id' ] ?? 0 );
			if ( $part < 1 ) {
				$out[ $area ] = array(
					'block_markup' => '',
					'error'        => 'header' === $area ? 'no_header' : 'no_footer',
				);
				continue;
			}
			$out[ $area ] = array(
				'block_markup' => '',
				'error'        => 'kept',
				'kept'         => true,
				'part_id'      => $part,
				'reason'       => (string) ( $choice['reason'] ?? '' ),
				'message'      => 'header' === $area
					? __( 'The header is a template part on this design\'s pages; Appearance › Menus was not changed.', 'dxai-ui' )
					: __( 'The footer is a template part on this design\'s pages; Appearance › Widgets was not changed.', 'dxai-ui' ),
			);
		}

		return $out;
	}

	/**
	 * Give the pages of the site's current footer design their footer back,
	 * as a template part, before another design takes the widget areas.
	 *
	 * The part holds the footer as those pages show it now — the stored frame
	 * with the block widgets of each area, a person's edits in Appearance >
	 * Widgets included (Footer_Template::assemble()) — under the key its
	 * design's import saved its part with, so a re-import of that design
	 * finds, updates and retires it like its own (the part the blocks
	 * replaced is brought back from the trash and updated in place when it
	 * is there). In each page only the footer block is replaced by the part
	 * reference; a crawled page that was the importer's as it stood stays so
	 * (re-stamped), one a person edited stays theirs.
	 *
	 * @param array<string, mixed> $record  The stored footer (Footer_Template::get()).
	 * @param array<int, int>      $holders Its pages (Footer_Widgets::holders()).
	 * @return array<string, mixed>|\WP_Error title, page_id, source, part_id, pages (the ids written).
	 */
	private static function hand_back_footer( array $record, array $holders ): array|\WP_Error {
		$markup = \DXAI_UI\Chrome\Footer_Template::assemble();
		if ( trim( $markup ) === '' ) {
			return new \WP_Error( 'dxai_ui_footer_hand_back', __( 'The footer in Appearance > Widgets could not be read back.', 'dxai-ui' ) );
		}
		$source = (string) ( $record['source'] ?? '' );
		$title  = (string) ( $record['title'] ?? '' );
		$page   = (int) ( $record['page_id'] ?? 0 );
		$key    = (string) ( $record['part_key'] ?? '' );
		if ( $key === '' ) {
			// A footer installed before the record kept its part's key: the
			// key its design's import computes (save()).
			$key = self::part_key( $source, (string) get_post_field( 'post_name', $page ), $title );
		}
		$meta  = $source === '' ? array() : array(
			'_dxai_ui_source_zip' => $source,
			'_dxai_ui_part_key'   => $key,
		);
		$label = ( $source === '' ? $title : $title . ' (' . preg_replace( '/\.zip$/i', '', $source ) . ')' ) . ' Footer';
		$parts = new Template_Part_Factory();
		self::revive_part( 'footer', $key );
		$part_id = $parts->save( 'footer', $label, $markup, $key, $meta );
		if ( $part_id < 1 ) {
			return new \WP_Error( 'dxai_ui_footer_hand_back', __( 'The footer\'s template part could not be written.', 'dxai-ui' ) );
		}
		$reference = $parts->reference_markup( 'footer', $key );
		$written   = array();
		foreach ( $holders as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$swapped = (string) preg_replace( '/<!--\s*wp:' . preg_quote( \DXAI_UI\Chrome\Site_Footer_Block::NAME, '/' ) . '(?:\s+\{.*?\})?\s*\/-->/', $reference, (string) $post->post_content );
			if ( $swapped === (string) $post->post_content ) {
				continue;
			}
			$stamped = '' !== (string) get_post_meta( $post->ID, Site_From_Menu::IMPORTED_AT, true ) && ! Site_From_Menu::edited_since_import( $post );
			wp_update_post(
				array(
					'ID'           => (int) $post->ID,
					'post_content' => wp_slash( $swapped ),
				)
			);
			if ( $stamped ) {
				Site_From_Menu::mark_imported( (int) $post->ID );
			}
			$written[] = (int) $post->ID;
		}

		return array(
			'title'   => $title,
			'page_id' => $page,
			'source'  => $source,
			'part_id' => $part_id,
			'pages'   => $written,
		);
	}

	/**
	 * Write a design's header or footer part again from the content its
	 * import installed (the crawl job keeps it), when the part is gone before
	 * the crawl finished: it is the post the finishing pass re-links, and
	 * builds the menus or widgets from. Under the part's own key — a part in
	 * the trash is brought back and overwritten, so no second one is added.
	 * The id, or 0 when there is nothing to write.
	 *
	 * @param array<string, mixed> $tail The crawl job's tail (title, part_key).
	 */
	private function restore_part( string $area, string $markup, array $tail ): int {
		$key = (string) ( $tail['part_key'] ?? '' );
		if ( trim( $markup ) === '' || $key === '' ) {
			return 0;
		}
		self::revive_part( $area, $key );
		$title = (string) ( $tail['title'] ?? '' );
		$label = $this->source_archive === '' ? $title : $title . ' (' . preg_replace( '/\.zip$/i', '', $this->source_archive ) . ')';
		$meta  = $this->source_archive === '' ? array() : array(
			'_dxai_ui_source_zip' => $this->source_archive,
			'_dxai_ui_part_key'   => $key,
		);

		return ( new Template_Part_Factory() )->save( $area, $label . ( 'header' === $area ? ' Header' : ' Footer' ), $markup, $key, $meta );
	}

	/**
	 * $id when it is a template part that still exists outside the trash,
	 * else 0 — a part is only read or re-linked while it is one.
	 */
	private static function live_part( int $id ): int {
		$part = $id > 0 ? get_post( $id ) : null;

		return $part instanceof \WP_Post && 'wp_template_part' === $part->post_type && 'trash' !== $part->post_status ? $id : 0;
	}

	/**
	 * Whether this site keeps a trash. With EMPTY_TRASH_DAYS set to 0,
	 * wp_trash_post() deletes the post for good (wp-includes/post.php), and
	 * the import never deletes anything: a part it would retire then stays
	 * published, unreferenced — the Site Editor lists it, nothing renders it.
	 */
	private static function can_trash(): bool {
		return ! defined( 'EMPTY_TRASH_DAYS' ) || (bool) EMPTY_TRASH_DAYS;
	}

	/**
	 * Retire one part the chrome blocks replaced: to the trash, when this
	 * plugin generated it, nothing references it any more, and the site has
	 * a trash (can_trash()). True when it went.
	 */
	private function retire_part( int $part_id ): bool {
		$part = $part_id > 0 ? get_post( $part_id ) : null;
		if ( ! $part instanceof \WP_Post || 'wp_template_part' !== $part->post_type || 'trash' === $part->post_status || ! self::can_trash() ) {
			return false;
		}
		if ( '1' !== (string) get_post_meta( $part_id, '_dxai_ui_generated', true ) || $this->part_referrers( (string) $part->post_name ) !== array() ) {
			return false;
		}

		return (bool) wp_trash_post( $part_id );
	}

	/**
	 * Replace this design's header and footer part references with the
	 * blocks install_chrome() got back, in each of these posts. Only the
	 * references to this design's own parts (by slug); the rest of the
	 * content is left byte for byte, and a post with no such reference is
	 * not written.
	 *
	 * @param array<int, int>       $post_ids
	 * @param array<string, string> $blocks area => block markup.
	 */
	private function swap_chrome( array $post_ids, string $part_key, array $blocks ): void {
		$slugs = array();
		foreach ( $blocks as $area => $block ) {
			$slugs[ Template_Part_Factory::slug( (string) $area, $part_key ) ] = (string) $block;
		}
		foreach ( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) as $id ) {
			$content = (string) get_post_field( 'post_content', $id );
			if ( $content === '' || ! str_contains( $content, 'wp:template-part' ) ) {
				continue;
			}
			$swapped = (string) preg_replace_callback(
				'/<!--\s*wp:template-part\s+(\{.*?\})\s*\/-->/',
				static function ( array $m ) use ( $slugs ): string {
					$attrs = json_decode( $m[1], true );
					$slug  = is_array( $attrs ) ? (string) ( $attrs['slug'] ?? '' ) : '';

					return isset( $slugs[ $slug ] ) ? $slugs[ $slug ] : $m[0];
				},
				$content
			);
			if ( $swapped !== $content ) {
				wp_update_post(
					array(
						'ID'           => $id,
						'post_content' => wp_slash( $swapped ),
					)
				);
			}
		}
	}

	/**
	 * @deprecated 0.4.0 Use Page_Chrome::strip_blocks() — kept so older crawl workers do not fatal.
	 */
	public static function strip_chrome_blocks( string $markup ): string {
		return \DXAI_UI\Chrome\Page_Chrome::strip_blocks( $markup );
	}

	/**
	 * Bring back the part the previous import of this design retired
	 * (install_chrome()), so it is updated in place — its id and revisions
	 * kept — instead of a second part being written beside the trashed one
	 * on every re-import. Only a part this plugin generated for this key,
	 * and only while no published part holds the slug.
	 */
	private static function revive_part( string $area, string $part_key ): void {
		$slug = Template_Part_Factory::slug( $area, $part_key );
		if ( get_posts( array( 'post_type' => 'wp_template_part', 'name' => $slug, 'post_status' => array( 'publish', 'draft', 'private' ), 'fields' => 'ids', 'posts_per_page' => 1 ) ) !== array() ) {
			return;
		}
		$trashed = get_posts(
			array(
				'post_type'      => 'wp_template_part',
				'post_status'    => 'trash',
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_wp_desired_post_slug',
						'value' => $slug,
					),
					array(
						'key'   => '_dxai_ui_generated',
						'value' => '1',
					),
				),
			)
		);
		if ( $trashed === array() ) {
			return;
		}
		$id = (int) $trashed[0];
		// Published again, as it was: core restores a trashed post as a
		// draft (5.6+), and Template_Part_Factory looks for a published part.
		$publish = static fn( $status, $post_id ) => (int) $post_id === $id ? 'publish' : $status;
		add_filter( 'wp_untrash_post_status', $publish, 10, 2 );
		wp_untrash_post( $id );
		remove_filter( 'wp_untrash_post_status', $publish, 10 );
	}

	/**
	 * Whether a `wp:navigation` block replaces the designed menu.
	 *
	 * The design's own nav — triggers, dropdown panels and all — is already in
	 * the markup, so under fidelity we register the menu object for the user
	 * but leave the header alone.
	 */
	private bool $inject_nav = false;

	/** Whether the design never ran under Tailwind — see save(). */
	private bool $static_html = false;

	/**
	 * The archive this import is reading — half of a page's identity.
	 *
	 * See existing_page(): without it, a page is identified by its route slug
	 * alone, and two designs that ship the same route name overwrite each
	 * other.
	 */
	private string $source_archive = '';

	/** The page this import is about to overwrite, if any — see previous_part_slugs(). */
	private int $previous_home_id = 0;

	/**
	 * Who this design's menus belong to: the home page's key (archive#slug,
	 * page_identity()), '' without an archive. See Navigation_Factory::OWNER_META.
	 */
	private string $owner = '';

	/**
	 * Whether a menu written by an older version — found by the design's
	 * title, with no owner record — may be taken over: only when this design's
	 * page existed before this import, so the menu of that title is its own.
	 */
	private bool $adopt_legacy = false;

	/** The live origin a synchronous crawl read (apply_crawl()), for the header's menu binding. */
	private string $crawl_origin = '';

	/**
	 * A Navigation_Factory writing this design's menus: found by its owner
	 * key, never another design's of the same title.
	 */
	private function navigation_factory(): Navigation_Factory {
		return new Navigation_Factory(
			$this->owner,
			(string) preg_replace( '/\.zip$/i', '', $this->source_archive ),
			$this->adopt_legacy
		);
	}

	/** Original Design.com / source SEO title (browser tab), not the WP page name. */
	private string $seo_title = '';

	private function inject_navigation( string $markup, int $nav_id ): string {
		if ( ! $this->inject_nav ) {
			return $markup;
		}

		if (
			preg_match( '/<nav\b/i', $markup )
			|| preg_match( '/<header\b/i', $markup )
			|| str_contains( $markup, 'wp:dxai-ui/link' )
			|| str_contains( $markup, 'wp:navigation' )
			|| str_contains( $markup, '"tagName":"header"' )
			|| str_contains( $markup, '"tagName":"nav"' )
		) {
			return $markup;
		}
		// Designed chrome already contains its own link tree — injecting
		// core/navigation flattens mega-menus into a single list.
		if ( preg_match_all( '/<a\b/i', $markup ) >= 3 ) {
			return $markup;
		}

		$nav = sprintf( '<!-- wp:navigation {"ref":%d} /-->', $nav_id );
		if ( str_contains( $markup, '<!-- /wp:group -->' ) ) {
			return preg_replace( '/<!-- \/wp:group -->/', $nav . "\n<!-- /wp:group -->", $markup, 1 ) ?? $markup;
		}

		return $nav . "\n" . $markup;
	}

	/**
	 * @param array<int, array<string, mixed>> $structures
	 * @return array<int, array<string, mixed>>
	 */
	private function included_only( array $structures ): array {
		$kept = array();
		foreach ( $structures as $structure ) {
			if ( ! is_array( $structure ) ) {
				continue;
			}
			if ( array_key_exists( 'included', $structure ) && false === $structure['included'] ) {
				continue;
			}
			$kept[] = $structure;
		}

		return $kept;
	}

	/**
	 * The colour slugs this design registered as theme.json presets — only
	 * when it is the site's brand, whose palette Design_Theme_Json registers.
	 *
	 * @return array<int, string>
	 */
	private static function preset_slugs( int $page_id ): array {
		if ( ! \DXAI_UI\Compiler\Token_Styles::is_brand( $page_id ) ) {
			return array();
		}
		$stored = get_post_meta( $page_id, \DXAI_UI\Compiler\Token_Styles::META, true );
		$slugs  = array();
		foreach ( is_array( $stored['colors'] ?? null ) ? $stored['colors'] : array() as $row ) {
			if ( is_array( $row ) && ! empty( $row['preset'] ) && ! empty( $row['slug'] ) ) {
				$slugs[] = (string) $row['slug'];
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Fold a finished crawl into the import: its pages join the link and path
	 * maps, the Home learns the live origin's absolute paths, and the menus
	 * are saved as trees that point at the crawled pages' permalinks.
	 *
	 * @param array<string, mixed>             $from_menu     Site_From_Menu summary.
	 * @param array<string, mixed>             $created       This import's record, extended in place.
	 * @param array<int, array<string, mixed>> $link_pages    Extended in place.
	 * @param array<string, int>               $path_map      Extended in place.
	 * @param array<int, mixed>                $primary_tree
	 * @param array<int, mixed>                $footer_tree
	 * @param array<int, mixed>                $primary_items
	 * @param array<int, mixed>                $footer_items
	 * @param array<int, string>               $skip_menus    'header' / 'footer': areas whose menu post is not
	 *                                                        written here (see save_menu_trees()).
	 * @return array<int, int> The crawled page ids.
	 */
	private function apply_crawl( array $from_menu, array &$created, array &$link_pages, array &$path_map, string $home_path, array $primary_tree, array $footer_tree, array $primary_items, array $footer_items, string $title, array $skip_menus = array() ): array {
		$crawled = array();
		foreach ( (array) ( $from_menu['pages_created'] ?? array() ) as $row ) {
			$crawled[] = (int) $row['id'];
		}
		$created['pages_created']  = (array) ( $from_menu['pages_created'] ?? array() );
		$created['pages_skipped']  = (array) ( $from_menu['pages_skipped'] ?? array() );
		$created['crawl_errors']   = (array) ( $from_menu['crawl_errors'] ?? array() );
		$created['pages_restyled'] = (int) ( $from_menu['pages_restyled'] ?? 0 );
		$created['restyle_engine'] = (string) ( $from_menu['restyle_engine'] ?? '' );
		foreach ( $crawled as $id ) {
			$created['extra_page_ids'][] = $id;
		}
		foreach ( (array) ( $from_menu['link_pages'] ?? array() ) as $lp ) {
			$link_pages[] = $lp;
		}
		foreach ( (array) ( $from_menu['path_map'] ?? array() ) as $p => $pid ) {
			$path_map[ (string) $p ] = (int) $pid;
		}

		$origin             = (string) ( $from_menu['origin'] ?? '' );
		$this->crawl_origin = $origin;
		// Home also needs absolute origin paths so header Contact → permalink.
		$link_pages[0]['paths'] = $this->home_paths( is_array( $link_pages[0]['paths'] ?? null ) ? $link_pages[0]['paths'] : array(), $origin, $home_path );
		$this->save_menu_trees( $created, $title, array_values( array_diff( array( 'header', 'footer' ), $skip_menus ) ), $primary_tree, $footer_tree, $primary_items, $footer_items, $path_map, $origin );

		return $crawled;
	}

	/**
	 * The Home's link paths plus the live origin's absolute URLs of it, so a
	 * link to the live home page is rewritten to the Home's permalink.
	 *
	 * @param array<int, string> $paths
	 * @return array<int, string>
	 */
	private function home_paths( array $paths, string $origin, string $home_path ): array {
		if ( $origin === '' ) {
			return $paths;
		}
		foreach ( array( '/', $home_path ) as $hp ) {
			if ( $hp === '' ) {
				continue;
			}
			$paths[] = rtrim( $origin, '/' ) . ( $hp === '/' ? '/' : $hp );
			$paths[] = rtrim( $origin, '/' ) . ( $hp === '/' ? '' : $hp );
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * The `{title} Primary` / `{title} Footer` wp_navigation posts as trees,
	 * bound to the crawled pages — for the areas in $areas ('header' for the
	 * Primary one, 'footer'), which are the areas that kept their template
	 * part: an area built in Menus or Widgets gets none (save()).
	 *
	 * @param array<string, mixed> $created Extended in place (navigation_ids).
	 * @param array<int, string>   $areas
	 * @param array<int, mixed>    $primary_tree
	 * @param array<int, mixed>    $footer_tree
	 * @param array<int, mixed>    $primary_items
	 * @param array<int, mixed>    $footer_items
	 * @param array<string, int>   $path_map
	 */
	private function save_menu_trees( array &$created, string $title, array $areas, array $primary_tree, array $footer_tree, array $primary_items, array $footer_items, array $path_map, string $origin ): void {
		$nav = $this->navigation_factory();
		if ( in_array( 'header', $areas, true ) && ( $primary_tree !== array() || $primary_items !== array() ) ) {
			$tree   = $primary_tree !== array() ? $primary_tree : $this->tree_from_flat( $primary_items );
			$nav_id = $nav->save_tree( $title . ' Primary', $tree, $path_map, 'dxai-primary', $origin );
			if ( $nav_id ) {
				$created['navigation_ids'][] = $nav_id;
			}
		}
		if ( in_array( 'footer', $areas, true ) && ( $footer_tree !== array() || $footer_items !== array() ) ) {
			$tree   = $footer_tree !== array() ? $footer_tree : $this->tree_from_flat( $footer_items );
			$nav_id = $nav->save_tree( $title . ' Footer', $tree, $path_map, 'dxai-footer', $origin );
			if ( $nav_id ) {
				$created['navigation_ids'][] = $nav_id;
			}
		}
	}

	/**
	 * The same posts from the design's flat menu items, when no crawl ran
	 * in this request (save()).
	 *
	 * @param array<string, mixed> $created Extended in place (navigation_ids).
	 * @param array<int, string>   $areas
	 * @param array<int, mixed>    $primary_items
	 * @param array<int, mixed>    $footer_items
	 */
	private function save_menu_flat( array &$created, string $title, array $areas, array $primary_items, array $footer_items ): void {
		$nav = $this->navigation_factory();
		if ( in_array( 'header', $areas, true ) ) {
			$nav_id = $nav->save( $title . ' Primary', $primary_items, 'dxai-primary' );
			if ( $nav_id ) {
				$created['navigation_ids'][] = $nav_id;
			}
		}
		if ( in_array( 'footer', $areas, true ) && $footer_items !== array() ) {
			$nav_id = $nav->save( $title . ' Footer', $footer_items, 'dxai-footer' );
			if ( $nav_id ) {
				$created['navigation_ids'][] = $nav_id;
			}
		}
	}

	/**
	 * Rewrite the design's own URLs to permalinks in every page this import
	 * wrote, its header and footer parts, and its synced header/footer/nav
	 * patterns.
	 *
	 * @param array<int, array<string, mixed>> $link_pages
	 * @param array<string, mixed>             $created
	 */
	private function rewrite_links( array $link_pages, array $created ): void {
		$rewrite_ids = array();
		foreach ( $link_pages as $lp ) {
			$id = (int) ( $lp['id'] ?? 0 );
			if ( $id > 0 ) {
				$rewrite_ids[ $id ] = true;
			}
		}
		foreach ( array( 'header_id', 'footer_id' ) as $key ) {
			$id = (int) ( $created[ $key ] ?? 0 );
			if ( $id > 0 ) {
				$rewrite_ids[ $id ] = true;
			}
		}
		foreach ( is_array( $created['patterns'] ?? null ) ? $created['patterns'] : array() as $pat ) {
			$pid = (int) ( is_array( $pat ) ? ( $pat['id'] ?? 0 ) : 0 );
			if ( $pid < 1 ) {
				continue;
			}
			$stype = (string) get_post_meta( $pid, '_dxai_ui_structure', true );
			if ( in_array( $stype, array( 'header', 'footer', 'navigation' ), true ) ) {
				$rewrite_ids[ $pid ] = true;
			}
		}
		$link_pages_for_rewrite = array_values( $link_pages );
		foreach ( array_keys( $rewrite_ids ) as $rid ) {
			$already = false;
			foreach ( $link_pages_for_rewrite as $lp ) {
				if ( (int) ( $lp['id'] ?? 0 ) === $rid ) {
					$already = true;
					break;
				}
			}
			if ( ! $already ) {
				$link_pages_for_rewrite[] = array(
					'id'    => $rid,
					'paths' => array(),
				);
			}
		}

		( new Internal_Links() )->rewrite_posts( $link_pages_for_rewrite );
	}

	/**
	 * The end of a deferred crawl (Crawl_Job): what save() does after the
	 * crawl, for the crawled pages — menus as trees, the link rewrite, the
	 * colour tokens for the pages the restyler wrote, their audits — from the
	 * state save() stored when it planned the crawl.
	 *
	 * @param array<string, mixed> $state The job: 'crawl' (the finished run) and 'tail'.
	 * @return array<string, mixed> The crawl's outcome, for the wizard.
	 */
	public function finish_crawl( array $state ): array {
		$tail       = (array) ( $state['tail'] ?? array() );
		$from_menu  = (array) ( $state['crawl']['summary'] ?? array() );
		$page_id    = (int) ( $tail['page_id'] ?? 0 );
		$link_pages = (array) ( $tail['link_pages'] ?? array() );
		$path_map   = (array) ( $tail['path_map'] ?? array() );
		$chrome     = (array) ( $tail['chrome'] ?? array() );
		// The design's menus, as save() wrote them (Navigation_Factory keys).
		$this->owner          = (string) ( $tail['owner'] ?? '' );
		$this->source_archive = (string) ( $tail['archive'] ?? '' );
		$this->adopt_legacy   = false;
		/*
		 * The parts the chrome blocks replaced are still published: save()
		 * leaves them for this pass (install_chrome()), which re-links them to
		 * the crawled pages below, rebuilds the header's menus and the footer's
		 * widgets from them, and only then retires them. Nothing here reads a
		 * post in the trash — on a host with EMPTY_TRASH_DAYS 0 there is none:
		 * a part that is gone by now (a person deleted it, or trashed it) is
		 * written again from the content save() installed, which the job keeps
		 * (restore_part()), so the link rewrite still has a post to work on.
		 */
		$header_part = ! empty( $chrome['header'] ) ? self::live_part( (int) ( $chrome['header_part'] ?? 0 ) ) : 0;
		$footer_part = ! empty( $chrome['footer'] ) ? self::live_part( (int) ( $chrome['footer_part'] ?? 0 ) ) : 0;
		if ( ! empty( $chrome['header'] ) && $header_part < 1 ) {
			$header_part = $this->restore_part( 'header', (string) ( $chrome['header_markup'] ?? '' ), $tail );
		}
		if ( ! empty( $chrome['footer'] ) && $footer_part < 1 ) {
			$footer_part = $this->restore_part( 'footer', (string) ( $chrome['footer_markup'] ?? '' ), $tail );
		}
		$created     = array(
			'header_id'      => ! empty( $chrome['header'] ) ? $header_part : self::live_part( (int) ( $tail['header_id'] ?? 0 ) ),
			'footer_id'      => ! empty( $chrome['footer'] ) ? $footer_part : self::live_part( (int) ( $tail['footer_id'] ?? 0 ) ),
			'patterns'       => (array) ( $tail['patterns'] ?? array() ),
			'navigation_ids' => array(),
			'extra_page_ids' => array(),
		);

		/*
		 * Each crawled page was written by an earlier request, so this one has
		 * not registered them for re-stamping: without this, the link rewrite
		 * and the token pass below would make every one of them read as edited
		 * by a person, and the next import would leave them alone. The same
		 * for the pages an earlier crawl made that save() put in the link map
		 * (known_pages) and this crawl did not write again.
		 */
		foreach ( (array) ( $from_menu['pages_created'] ?? array() ) as $row ) {
			Site_From_Menu::mark_imported( (int) ( $row['id'] ?? 0 ) );
		}
		foreach ( (array) ( $tail['known_pages'] ?? array() ) as $known_id ) {
			$known = get_post( (int) $known_id );
			if ( $known instanceof \WP_Post && ! Site_From_Menu::edited_since_import( $known ) ) {
				Site_From_Menu::mark_imported( (int) $known_id );
			}
		}

		// The menu posts only for an area that kept its part (save()).
		$crawled = $this->apply_crawl(
			$from_menu,
			$created,
			$link_pages,
			$path_map,
			(string) ( $tail['home_path'] ?? '' ),
			(array) ( $tail['primary_tree'] ?? array() ),
			(array) ( $tail['footer_tree'] ?? array() ),
			(array) ( $tail['primary_items'] ?? array() ),
			(array) ( $tail['footer_items'] ?? array() ),
			(string) ( $tail['title'] ?? '' ),
			array_keys( array_filter( array( 'header' => ! empty( $chrome['header'] ), 'footer' => ! empty( $chrome['footer'] ) ) ) )
		);
		$this->rewrite_links( $link_pages, $created );

		/*
		 * The header's menus pointed at the crawled pages, rebuilt from its
		 * part as re-linked just now — a link outside the menu slots then
		 * points at its crawled page too (Header_Menus::rebind(); it keeps the
		 * site's header only while this design still has it, and otherwise
		 * rebinds this design's own). The footer's widgets rebuilt the same
		 * way, while the widget areas still hold this design's footer: a crawl
		 * finishes minutes after its import, and a design installed in
		 * between may own them now (and gave this design's pages their footer
		 * back as its part — install_site_footer()).
		 */
		$chrome_out = array();
		if ( ! empty( $chrome['header'] ) ) {
			try {
				$chrome_out['header'] = \DXAI_UI\Chrome\Header_Menus::rebind(
					$path_map,
					(string) ( $from_menu['origin'] ?? '' ),
					$page_id,
					$header_part > 0 ? (string) get_post_field( 'post_content', $header_part ) : ''
				);
			} catch ( \Throwable $e ) {
				$chrome_out['header'] = array(
					'block_markup' => '',
					'error'        => 'exception',
					'message'      => $e->getMessage(),
				);
			}
			if ( $chrome_out['header'] === array() ) {
				unset( $chrome_out['header'] );
			}
		}
		$footer_from = $footer_part > 0 ? (string) get_post_field( 'post_content', $footer_part ) : (string) ( $chrome['footer_markup'] ?? '' );
		if ( ! empty( $chrome['footer'] ) && $footer_from !== '' && (int) ( \DXAI_UI\Chrome\Footer_Template::get()['scope'] ?? 0 ) === $page_id ) {
			try {
				$chrome_out['footer'] = \DXAI_UI\Chrome\Footer_Widgets::install(
					$footer_from,
					array(
						'page_id'  => $page_id,
						'scope'    => $page_id,
						'source'   => $this->source_archive,
						'title'    => (string) ( $tail['title'] ?? '' ),
						'owner'    => $this->owner,
						'part_key' => (string) ( $tail['part_key'] ?? '' ),
					)
				);
			} catch ( \Throwable $e ) {
				$chrome_out['footer'] = array(
					'block_markup' => '',
					'error'        => 'exception',
					'message'      => $e->getMessage(),
				);
			}
		}

		$tokens = \DXAI_UI\Compiler\Token_Styles::tokenize_import(
			(array) ( $tail['design_posts'] ?? array() ),
			$page_id,
			(array) ( $tail['route_pages'] ?? array() ),
			(array) ( $tail['roles'] ?? array() ),
			(array) ( $tail['palette'] ?? array() ),
			$crawled
		);
		\DXAI_UI\Theme\Design_Theme_Json::flush();
		/*
		 * Hoisted against the rest of the design: the crawled pages render
		 * inside the Home's scope, next to its header and footer, so the class
		 * names of those count as the design's too — as they do when the crawl
		 * runs inside save() and every post is hoisted at once.
		 */
		$chrome_markup = array( (string) get_post_field( 'post_content', $page_id ) );
		$header_spec   = \DXAI_UI\Chrome\Header_Template::scoped( $page_id );
		if ( null !== $header_spec ) {
			$chrome_markup[] = (string) ( $header_spec['markup'] ?? '' );
		}
		if ( (int) ( \DXAI_UI\Chrome\Footer_Template::get()['page_id'] ?? 0 ) === $page_id ) {
			$chrome_markup[] = \DXAI_UI\Chrome\Site_Footer_Block::markup();
		}
		\DXAI_UI\Compiler\Style_Hoister::hoist_import( $crawled, self::preset_slugs( $page_id ), array_values( array_filter( $chrome_markup ) ) );
		// The pages built from the old site count for the design's colour use too.
		\DXAI_UI\Theme\Theme_Binding::after_import( $page_id );
		\DXAI_UI\Blocks\Native\Native_Blocks::after_import( $page_id );
		\DXAI_UI\Media\Font_Host::after_import( $page_id );

		$auditor = new Import_Audit();
		foreach ( $crawled as $audit_id ) {
			update_post_meta( $audit_id, Import_Audit::META, $auditor->page( $audit_id ) );
		}

		// Nothing needs the parts the blocks replaced any more (install_chrome()).
		foreach ( array( $header_part, $footer_part ) as $replaced ) {
			$this->retire_part( $replaced );
		}

		$outcome = array(
			'pages_created'  => $created['pages_created'] ?? array(),
			'pages_skipped'  => $created['pages_skipped'] ?? array(),
			'crawl_errors'   => $created['crawl_errors'] ?? array(),
			'pages_restyled' => (int) ( $created['pages_restyled'] ?? 0 ),
			'restyle_engine' => (string) ( $created['restyle_engine'] ?? '' ),
			'navigation_ids' => $created['navigation_ids'],
			'tokens'         => $tokens,
		);
		if ( $chrome_out !== array() ) {
			// The wizard's done screen reads it as it reads the save's.
			$outcome['chrome'] = $chrome_out;
		}
		// The header and footer choice the import made (Chrome_Choice): a
		// kept header and footer were not touched by this pass either.
		if ( '' !== (string) ( $chrome['mode'] ?? '' ) ) {
			$outcome['chrome_mode'] = array(
				'mode'   => (string) $chrome['mode'],
				'reason' => (string) ( $chrome['reason'] ?? '' ),
			);
		}
		$conversion = (int) ( $tail['conversion'] ?? 0 );
		if ( $conversion > 0 ) {
			update_post_meta( $conversion, '_dxai_ui_crawl', $outcome );
		}

		return $outcome;
	}

	/**
	 * Restore a conversion snapshot into WordPress structures.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function restore( int $conversion_id, string $scope = '' ): array|\WP_Error {
		$post = get_post( $conversion_id );
		if ( ! $post instanceof \WP_Post || $post->post_type !== Content_Types::CONVERSION ) {
			return new \WP_Error( 'dxai_ui_restore', __( 'Conversion snapshot not found.', 'dxai-ui' ), array( 'status' => 404 ) );
		}

		$payload = json_decode( (string) $post->post_content, true );
		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'dxai_ui_restore', __( 'Conversion snapshot is not valid JSON.', 'dxai-ui' ), array( 'status' => 422 ) );
		}

		$result = is_array( $payload['result'] ?? null ) ? $payload['result'] : array();
		if ( $scope !== '' ) {
			$result['scope'] = $scope;
		} elseif ( empty( $result['scope'] ) ) {
			$result['scope'] = 'site';
		}

		return $this->save( $result );
	}

	/**
	 * The key a design's header and footer template parts are saved under
	 * (`dxai-header-{key}`, `dxai-footer-{key}`).
	 *
	 * The archive, like a page's identity (see existing_page()). This used to
	 * be the home slug or, for the usual home route `/`, the page title — and
	 * Page_Title::for_home() names a home page after its brand. The seven
	 * DevriX designs in the local corpus are all titled "DevriX", so they all
	 * wrote `dxai-header-devrix`, and each import replaced the header and
	 * footer of the six before it: those pages showed another design's chrome
	 * (20 nodes missing and 21 extra on each, per the design oracle). Any two
	 * designs whose title falls back to "Home" collided the same way.
	 *
	 * Re-importing the same archive keeps the same key, so its parts are
	 * updated in place; a different archive gets parts of its own. With no
	 * archive name (a caller that has none) the old derivation is kept.
	 *
	 * The key must come out byte-identical on every host, so it is built from
	 * nothing a host can change: not sanitize_title(), whose result depends on
	 * the site locale (remove_accents() has per-locale rules), on whether intl
	 * and mbstring are installed, and on any plugin filtering it — and which
	 * percent-encodes a Cyrillic or CJK name into up to 200 bytes, more than
	 * post_name holds once `dxai-header-` is prefixed. Instead: the ASCII
	 * letters and digits of the name (strtolower is locale-independent on PHP
	 * 8.2, which the plugin requires), at most 40 of them, plus 8 hex digits
	 * of the name's md5. The hash keeps apart names that fold to the same
	 * letters ("Brand Polish Pass.zip" / "brand-polish-pass.zip", or two
	 * Cyrillic names that fold to nothing), and the slug stays under 62 bytes.
	 */
	private static function part_key( string $archive, string $home_slug, string $title ): string {
		$archive = trim( $archive );
		if ( $archive === '' ) {
			$key = sanitize_title( trim( $home_slug, '/' ) );

			return $key !== '' ? $key : sanitize_title( $title );
		}

		$base  = (string) preg_replace( '/\.zip$/i', '', $archive );
		$ascii = strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '-', $base ) );
		$ascii = trim( substr( trim( $ascii, '-' ), 0, 40 ), '-' );

		return ( $ascii !== '' ? $ascii : 'design' ) . '-' . substr( md5( $archive ), 0, 8 );
	}

	/**
	 * Template-part slugs the page this import will overwrite references now.
	 *
	 * Read before anything is saved: once the page is rewritten, the old
	 * references are gone and nothing records which part it used to use.
	 *
	 * @return array<int, string>
	 */
	private function previous_part_slugs( string $home_slug, string $title ): array {
		list( $slug, $key ) = $this->page_identity( $home_slug, $title );
		$page               = $this->existing_page( $slug, $key );
		$this->previous_home_id = $page instanceof \WP_Post ? (int) $page->ID : 0;
		if ( ! $page instanceof \WP_Post ) {
			return array();
		}
		$slugs = array();
		foreach ( parse_blocks( (string) $page->post_content ) as $block ) {
			$this->collect_part_slugs( $block, $slugs );
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * @param array<string, mixed> $block
	 * @param array<int, string>   $slugs
	 */
	private function collect_part_slugs( array $block, array &$slugs ): void {
		if ( ( $block['blockName'] ?? '' ) === 'core/template-part' ) {
			$slug = (string) ( $block['attrs']['slug'] ?? '' );
			if ( str_starts_with( $slug, 'dxai-' ) ) {
				$slugs[] = $slug;
			}
		}
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $inner ) {
			if ( is_array( $inner ) ) {
				$this->collect_part_slugs( $inner, $slugs );
			}
		}
	}

	/**
	 * Rename a part in place when this design's page is the only thing using
	 * it and its new name is still free.
	 *
	 * The id stays, and with it the part's revisions and anything a person
	 * changed in the Site Editor; the import then updates it under its new
	 * name like any other re-import. A part other posts still use is left
	 * where it is — the new name gets a part of its own — and retire_parts()
	 * deals with it once nothing uses it any more.
	 *
	 * The rename is a post_name update and nothing else. wp_update_post()
	 * would re-save the content too, and on a site where the importing user
	 * has no unfiltered_html that re-save runs KSES over the whole part.
	 *
	 * @param array<int, string> $previous Slugs the page referenced before this import.
	 * @return array<string, string> Old slug => new slug.
	 */
	private function rename_own_parts( array $previous, string $part_key ): array {
		global $wpdb;

		$renamed = array();
		if ( $this->previous_home_id < 1 ) {
			return $renamed;
		}
		foreach ( array( 'header', 'footer' ) as $area ) {
			$new = Template_Part_Factory::slug( $area, $part_key );
			if ( get_posts( array( 'post_type' => 'wp_template_part', 'name' => $new, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1 ) ) !== array() ) {
				continue;
			}
			foreach ( $previous as $old ) {
				if ( $old === $new || ! str_starts_with( $old, 'dxai-' . $area . '-' ) ) {
					continue;
				}
				if ( array_diff( $this->part_referrers( $old ), array( $this->previous_home_id ) ) !== array() ) {
					continue;
				}
				$own = $this->generated_parts( $old );
				if ( count( $own ) !== 1 ) {
					continue;
				}
				$wpdb->update( $wpdb->posts, array( 'post_name' => $new ), array( 'ID' => $own[0]->ID ) );
				clean_post_cache( $own[0]->ID );
				$renamed[ $old ] = $new;
				break;
			}
		}

		return $renamed;
	}

	/**
	 * The template parts this plugin generated under one slug.
	 *
	 * @return array<int, \WP_Post>
	 */
	private function generated_parts( string $slug ): array {
		return array_values(
			array_filter(
				get_posts(
					array(
						'post_type'      => 'wp_template_part',
						'name'           => $slug,
						'post_status'    => array( 'publish', 'draft', 'private' ),
						'posts_per_page' => -1,
						'meta_key'       => '_dxai_ui_generated',
						'meta_value'     => '1',
					)
				),
				static fn( $p ): bool => $p instanceof \WP_Post
			)
		);
	}

	/**
	 * Ids of the posts whose content references a template part by slug —
	 * pages, templates, patterns; not revisions, trash or other parts.
	 *
	 * @return array<int, int>
	 */
	private function part_referrers( string $slug ): array {
		global $wpdb;

		// The block serialises its attributes in this order, and the JSON
		// escapes nothing in a slug made of [a-z0-9-].
		$needle = '%' . $wpdb->esc_like( '"slug":"' . $slug . '"' ) . '%';

		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_type NOT IN ('revision','wp_template_part') AND post_status NOT IN ('trash','auto-draft','inherit')",
					$needle
				)
			)
		);
	}

	/**
	 * Move the parts this design stopped using to the trash — only the ones
	 * this plugin generated, and only when no other post still references
	 * them.
	 *
	 * Another design's pages may still point at a part: while the DevriX
	 * designs are re-imported one by one, `dxai-header-devrix` stays in use
	 * until the last of them has moved to its own key, and only then goes.
	 * Trashed rather than deleted, so an edit someone made to it in the Site
	 * Editor can still be restored.
	 *
	 * @param array<int, string> $previous Slugs the page referenced before this import.
	 * @param array<int, string> $current  Slugs it references now.
	 * @return array<int, string> The slugs that were retired.
	 */
	private function retire_parts( array $previous, array $current ): array {
		$retired = array();
		// No trash on this site: wp_trash_post() would delete (can_trash()).
		if ( ! self::can_trash() ) {
			return $retired;
		}
		foreach ( array_diff( $previous, $current ) as $slug ) {
			$parts = $this->generated_parts( $slug );
			if ( $parts === array() || $this->part_referrers( $slug ) !== array() ) {
				continue;
			}
			foreach ( $parts as $part ) {
				if ( wp_trash_post( $part->ID ) ) {
					$retired[] = $slug;
				}
			}
		}

		return array_values( array_unique( $retired ) );
	}

	/**
	 * A page's computed slug and identity key, as save_page() derives them.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function page_identity( string $preferred_slug, string $title ): array {
		$trimmed        = trim( $preferred_slug, '/' );
		$from_preferred = sanitize_title( str_replace( '/', '-', $trimmed ) );
		$base           = $from_preferred !== '' ? $from_preferred : sanitize_title( $title );
		$slug           = str_starts_with( $base, 'dxai-' ) ? $base : 'dxai-' . $base;
		$key            = $this->source_archive === '' ? '' : $this->source_archive . '#' . $slug;

		return array( $slug, $key );
	}

	/**
	 * @param array<string, mixed> $design
	 */
	/**
	 * The page this import owns — its own, never another design's.
	 *
	 * Identity used to be the route slug alone: `dxai-` plus the route file's
	 * name. Three designs in this corpus ship a `jobs.$slug.tsx` route, so all
	 * three computed `dxai-jobs-slug`, and the second and third UPDATED the
	 * first design's page instead of creating their own. Careers Page Builder
	 * and Client Connect Hub ended up with no page at all — 18 archives in, 17
	 * pages out, every one of them reporting status=ok. Silent whole-design
	 * loss, and the only visible trace was three imports sharing a page_id.
	 *
	 * So a page belongs to (archive, slug). Re-importing the same archive still
	 * overwrites in place, which is what makes an import idempotent; a
	 * different archive gets a page of its own even when the route name is
	 * identical, and WordPress gives it a unique post_name.
	 *
	 * With no archive name — an older page, or a caller that has none — the
	 * slug lookup is still the answer, so nothing that worked before changes.
	 */
	private function existing_page( string $slug, string $key ): ?\WP_Post {
		if ( $key !== '' ) {
			$own = get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => array( 'publish', 'draft' ),
					'posts_per_page' => 1,
					'meta_key'       => '_dxai_ui_page_key',
					'meta_value'     => $key,
				)
			);
			if ( isset( $own[0] ) && $own[0] instanceof \WP_Post ) {
				return $own[0];
			}
		}

		$at_slug = get_posts(
			array(
				'post_type'      => 'page',
				'name'           => $slug,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 1,
			)
		);
		if ( ! isset( $at_slug[0] ) || ! $at_slug[0] instanceof \WP_Post ) {
			return null;
		}

		// A page made for a design's Home (the Library's "Pages in the team's style") is that design's, whatever its key says
		// or lacks: an import of another design with the same address (/contact-us) must not take it.
		if ( (string) get_post_meta( $at_slug[0]->ID, \DXAI_UI\Pages\Team_Pages::META, true ) !== '' ) {
			return null;
		}

		/*
		 * The slug is free to take only if nobody else has claimed it. A page
		 * already keyed to a different archive is another design's, and taking
		 * it is the bug above; a page with no key at all predates this and is
		 * adopted, so an existing install is migrated by its next import
		 * instead of growing a duplicate.
		 */
		if ( $key !== '' ) {
			$holder = (string) get_post_meta( $at_slug[0]->ID, '_dxai_ui_page_key', true );
			if ( $holder !== '' && $holder !== $key ) {
				return null;
			}
		}

		return $at_slug[0];
	}

	private function save_page( string $title, string $content, string $css, string $js, array $design = array(), string $compiled_css = '', string $wrapper_class = '', string $raw_css = '', string $preferred_slug = '', string $source_html = '', string $wrapper_style = '', string $tailwind_config = '' ): int|\WP_Error {
		$content = \DXAI_UI\Chrome\Page_Chrome::strip_blocks( $content );
		list( $slug, $key ) = $this->page_identity( $preferred_slug, $title );
		$existing           = $this->existing_page( $slug, $key );

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

		/*
		 * Identity, written with the page rather than after it.
		 *
		 * `_dxai_ui_page_key` is archive + computed slug. It is stable across
		 * re-imports because it is derived from what the import read, not from
		 * the post_name WordPress finally assigned — so a page that had to take
		 * a suffixed slug is still found next time instead of spawning
		 * `-3`, `-4`, … on every run.
		 */
		if ( $key !== '' ) {
			$data['meta_input']['_dxai_ui_page_key']   = $key;
			$data['meta_input']['_dxai_ui_source_zip'] = $this->source_archive;
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

		$id     = (int) $id;
		$mapper = new Gutenberg_Mapper();
		// The design's own root classes carry page-level background and type;
		// splitting the root into structures drops them, so re-apply them here.
		$extra = $wrapper_class !== '' ? $wrapper_class : ( str_contains( $css, '.rv-root' ) ? 'rv-root' : '' );
		/*
		 * The page's sections are stored as top-level blocks. They used to be
		 * wrapped in one core/group carrying the design's scope class
		 * (`dxai-ui dxai-ui--{id}`), so the editor showed the whole page as a
		 * single block to drill into. The scope element is now added around
		 * the rendered content instead (Page_Scope), with the same classes, so
		 * the front end is unchanged and the scoped stylesheet still matches.
		 * The wrapped form is still built — the stylesheet compiler reads the
		 * root utility classes from it — but it is not what is saved.
		 */
		$wrapped = $mapper->wrap_scope( $content, $id, $extra );
		update_post_meta( $id, '_wp_page_template', Blank_Template::default_slug() );
		update_post_meta( $id, '_dxai_ui_wrapper_class', $extra );
		update_post_meta( $id, Page_Scope::META, $id );
		/*
		 * Which route of the design this page is. A multi-route project makes
		 * several pages from one ZIP, and without this the only thing relating
		 * a page to a route was its title — so bin/design-oracle.php built its
		 * oracle from the design's FIRST route whatever page it was measuring,
		 * and the second and third pages could only be reported unmeasured.
		 */
		update_post_meta( $id, '_dxai_ui_source_route', trim( $preferred_slug, '/' ) );
		delete_post_meta( $id, '_dxai_ui_canvas_html' );

		$purger = new Tailwind_Purger();
		if ( $this->static_html ) {
			$purger->without_preflight();
			update_post_meta( $id, '_dxai_ui_static_html', '1' );
		} else {
			delete_post_meta( $id, '_dxai_ui_static_html' );
		}
		if ( $this->seo_title !== '' ) {
			update_post_meta( $id, \DXAI_UI\Support\Page_Title::SEO_META, $this->seo_title );
		} else {
			// Nothing left over from an earlier import (or from the home page's
			// title, which route pages used to inherit).
			delete_post_meta( $id, \DXAI_UI\Support\Page_Title::SEO_META );
		}
		// Compile against the full design HTML so every Tailwind token / utility
		// from header + body + footer is kept — not only classes left on the page.
		// The wrapped markup goes in too: the design's root utilities live on the
		// page group, and the source sections no longer carry that element.
		$compile_src = $source_html !== '' ? $source_html . "\n" . $wrapped : $wrapped;
		$sheet       = $purger->compile( $compile_src, $css, $id, $compiled_css, $raw_css, $wrapper_style, $tailwind_config );
		$url    = $purger->persist( $id, $sheet );
		// Stored relative to uploads (`dxai-ui/pattern-{id}.css`), never as the
		// absolute URL persist() returns — see Upload_Paths.
		if ( ! is_wp_error( $url ) ) {
			update_post_meta( $id, '_dxai_ui_css_url', \DXAI_UI\Support\Upload_Paths::for_storage( $url ) );
		}
		if ( $js !== '' ) {
			$js_url = $purger->persist_js( $id, $js );
			if ( ! is_wp_error( $js_url ) && is_string( $js_url ) ) {
				update_post_meta( $id, '_dxai_ui_js_url', \DXAI_UI\Support\Upload_Paths::for_storage( $js_url ) );
			}
		}

		/*
		 * `$font_url`, not `$sheet`. This loop used to reuse `$sheet`, which at
		 * this point holds the compiled page stylesheet — so after the last font
		 * it held that font's Google Fonts URL instead, and
		 * Design_Tokens::resolve() below read its colours out of a URL string.
		 * Every design that loads a font had its tokens resolved from nothing
		 * but the HTML; the ones that load none were unaffected, which is why
		 * the stored tokens differed only on pages with fonts.
		 */
		$font_urls = array();
		foreach ( $design['fonts'] ?? array() as $font ) {
			if ( ! is_array( $font ) ) {
				continue;
			}
			$font_url = esc_url_raw( (string) ( $font['stylesheet'] ?? '' ) );
			if ( $font_url !== '' ) {
				$font_urls[] = $font_url;
			}
		}
		update_post_meta( $id, '_dxai_ui_font_urls', array_values( array_unique( $font_urls ) ) );

		$tokens = \DXAI_UI\Compiler\Design_Tokens::resolve(
			$sheet,
			$source_html !== '' ? $source_html : $wrapped,
			is_array( $design['tokens'] ?? null ) ? $design['tokens'] : array()
		);
		\DXAI_UI\Compiler\Design_Tokens::persist( $id, $tokens );
		/*
		 * The `--dxai-*` definitions are no longer appended here. They used to
		 * be — nine role values scoped to `.dxai-ui.dxai-ui--{id}` — and that
		 * scope matched nothing on a crawled page, which is wrapped in its home
		 * page's scope rather than its own. Token_Styles::tokenize_import() now
		 * writes the whole palette into this sheet once the design is complete
		 * (at the end of save()), under a selector every converted page matches,
		 * with the role names kept as aliases so forms and prose still resolve.
		 */

		update_post_meta(
			$id,
			'_dxai_ui_design_assets',
			wp_json_encode(
				array(
					'summary' => $design['summary'] ?? array(),
					'links'   => array_slice( $design['links'] ?? array(), 0, 80 ),
					'tokens'  => $design['tokens'] ?? array(),
				)
			)
		);

		return $id;
	}

	/**
	 * @param array<string, mixed> $result
	 * @param array<string, mixed> $created
	 */
	private function save_conversion( string $title, array $result, array $created ): int {
		/*
		 * With <, >, &, ' and " escaped as \u00XX, the JSON holds no character
		 * KSES acts on — the flags core uses for the global-styles post. Where
		 * the importing user has no unfiltered_html, KSES runs on this post
		 * like any other, and it read the design's HTML inside the JSON strings
		 * as markup: measured, a 290 KB snapshot came back as 67 MB of invalid
		 * JSON after 70 seconds, and the next one exhausted a 1 GB memory
		 * limit — as the last step of an import, after every page was written.
		 */
		$payload = wp_json_encode(
			array(
				'result'  => $result,
				'created' => $created,
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);

		$page_id  = (int) ( $created['page_id'] ?? 0 );
		$existing = get_posts(
			array(
				'post_type'      => Content_Types::CONVERSION,
				'posts_per_page' => 1,
				'post_status'    => 'any',
				'meta_key'       => '_dxai_ui_page_id',
				'meta_value'     => (string) $page_id,
			)
		);

		$data = array(
			'post_type'    => Content_Types::CONVERSION,
			'post_status'  => 'publish',
			'post_title'   => wp_slash( $title . ' conversion' ),
			/*
			 * Slashed, like every other insert in this file.
			 *
			 * wp_insert_post() and wp_update_post() both call wp_unslash() on
			 * what they are given, so JSON handed over raw loses every
			 * backslash: `\n` became `n`, `\"` became `"`, `-` became
			 * `u002d`. Measured on a real import, the stored record was 867 KB
			 * that json_decode() refused outright — which means every
			 * conversion ever written was unreadable, and restore() (line 327)
			 * and anything else that reads the record got nothing back. The
			 * page itself was never affected: that insert was already slashed.
			 */
			'post_content' => wp_slash( is_string( $payload ) ? $payload : '{}' ),
			'meta_input'   => array(
				'_dxai_ui_generated' => '1',
				'_dxai_ui_page_id'   => $page_id,
			),
		);

		if ( isset( $existing[0] ) && $existing[0] instanceof \WP_Post ) {
			$data['ID'] = $existing[0]->ID;
			$id         = wp_update_post( $data, true );
		} else {
			$id = wp_insert_post( $data, true );
		}

		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function library(): array {
		return array(
			'patterns'       => ( new Pattern_Repository() )->list(),
			'conversions'    => $this->list_posts( Content_Types::CONVERSION ),
			'pages'          => $this->list_generated_pages(),
			'template_parts' => $this->list_posts( 'wp_template_part' ),
			'navigations'    => $this->list_posts( 'wp_navigation' ),
			'form_entries'   => $this->list_form_entries(),
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function list_posts( string $type ): array {
		$query = new \WP_Query(
			array(
				'post_type'      => $type,
				'post_status'    => 'publish',
				'posts_per_page' => 30,
				'meta_query'     => array(
					array(
						'key'   => '_dxai_ui_generated',
						'value' => '1',
					),
				),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$items[] = array(
				'id'    => $post->ID,
				'title' => $post->post_title,
				'type'  => $post->post_type,
				'edit'  => get_edit_post_link( $post->ID, 'raw' ),
				'view'  => get_permalink( $post->ID ),
				'date'  => get_date_from_gmt( $post->post_date_gmt, 'c' ),
			);
		}

		return $items;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function list_form_entries(): array {
		$query = new \WP_Query(
			array(
				'post_type'      => Content_Types::FORM_ENTRY,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$fields  = json_decode( (string) $post->post_content, true );
			$fields  = is_array( $fields ) ? $fields : array();
			$email   = isset( $fields['email'] ) ? (string) $fields['email'] : '';
			$message = '';
			foreach ( array( 'message', 'msg', 'comment', 'body' ) as $key ) {
				if ( ! empty( $fields[ $key ] ) && is_string( $fields[ $key ] ) ) {
					$message = $fields[ $key ];
					break;
				}
			}
			$items[] = array(
				'id'      => $post->ID,
				'title'   => $post->post_title,
				'type'    => $post->post_type,
				'edit'    => get_edit_post_link( $post->ID, 'raw' ),
				'view'    => '',
				'date'    => get_date_from_gmt( $post->post_date_gmt, 'c' ),
				'email'   => $email,
				'message' => $message,
				'page_id' => (int) get_post_meta( $post->ID, '_dxai_ui_page_id', true ),
				'fields'  => $fields,
			);
		}

		return $items;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function list_generated_pages(): array {
		$query = new \WP_Query(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 30,
				'meta_query'     => array(
					array(
						'key'   => '_dxai_ui_generated_page',
						'value' => '1',
					),
				),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$items[] = array(
				'id'    => $post->ID,
				'title' => $post->post_title,
				'type'  => 'page',
				'edit'  => get_edit_post_link( $post->ID, 'raw' ),
				'view'  => get_permalink( $post->ID ),
				'date'  => get_date_from_gmt( $post->post_date_gmt, 'c' ),
			);
		}

		return $items;
	}

	/**
	 * Tailwind class lists keep `%`, `[]` and `/` — `sanitize_text_field` strips them.
	 */
	private function class_list( string $classes ): string {
		$classes = wp_strip_all_tags( $classes );
		$classes = html_entity_decode( $classes, ENT_QUOTES );

		return trim( (string) preg_replace( '/\s+/', ' ', $classes ) );
	}

	/**
	 * Repair leaked JSX crumbs and `u00b7`-style unicode damage.
	 */
	private function scrub_artifacts( string $html ): string {
		$html = preg_replace( '/<!-- wp:paragraph[^>]*-->\s*<p[^>]*>\s*,\s*\)\}\s*<\/p>\s*<!-- \/wp:paragraph -->/i', '', $html ) ?? $html;
		$html = preg_replace( '/<p[^>]*>\s*,\s*\)\}\s*<\/p>/i', '', $html ) ?? $html;
		$html = preg_replace( '/>\s*,\s*\)\}\s*</', '><', $html ) ?? $html;
		$html = Design_Html::repair_unicode_artifacts( $html );

		return $html;
	}

	/**
	 * Design-side routes that should resolve to this WordPress page.
	 *
	 * @return array<int, string>
	 */
	private function link_paths( string $slug, string $title ): array {
		$paths = array();
		$slug  = trim( $slug );
		if ( $slug !== '' ) {
			$paths[] = $slug;
			$norm = Site_Origin::path_of( $slug );
			if ( $norm !== '' ) {
				$paths[] = $norm;
				if ( $norm !== '/' ) {
					$paths[] = $norm . '/';
				}
			}
		}
		$from_title = sanitize_title( $title );
		if ( $from_title !== '' ) {
			$paths[] = '/' . $from_title;
			$paths[] = '/dxai-' . $from_title;
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * @param array<int, array{label?:string, url?:string}> $items
	 * @return array<int, array{label:string, url:string, children:array}>
	 */
	private function tree_from_flat( array $items ): array {
		$tree = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $item['label'] ?? '' ) );
			if ( $label === '' ) {
				continue;
			}
			$tree[] = array(
				'label'    => $label,
				'url'      => (string) ( $item['url'] ?? '#' ),
				'children' => array(),
			);
		}

		return $tree;
	}
}

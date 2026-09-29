<?php
/**
 * The old site's pages for an imported design, at any time after the import.
 *
 * The pages come from where the design links to them: the site's menus (the
 * header built in Appearance > Menus), the footer's widgets and the Home's own
 * content — every link to the design's live site. Each is listed with what
 * exists for it already, and the chosen ones are built the way the import
 * builds them (Site_From_Menu with the design engine, through Crawl_Job, a few
 * steps per request), after which the menus, the footer and every page that
 * linked to the old URLs point at the new pages.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

use DXAI_UI\Compiler\Internal_Links;
use DXAI_UI\Connectors\Site_Origin;
use DXAI_UI\Structures\Crawl_Job;
use DXAI_UI\Structures\Site_From_Menu;

final class Site_Pages {

	/** Crawl_Job tail mode of a job this class started (finished by finish()). */
	public const MODE = 'site-pages';

	/** Home meta: the old site's address and its pages as first found (links to them change once they are built). */
	public const ORIGIN_META = '_dxai_ui_site_origin';
	public const PAGES_META  = '_dxai_ui_site_pages';

	/**
	 * Links to the old site: the menus in the theme's locations, the footer widgets and the Home's content.
	 *
	 * @return array<int, array{label:string, url:string, children:array}>
	 */
	public static function menu_tree( int $home_id ): array {
		$own   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$tree  = array();
		$seen  = array();
		// A menu item's label names the page; a link in the content only hints at it (the page's own heading names it).
		$add   = static function ( string $label, string $url, bool $named ) use ( &$tree, &$seen, $own ): void {
			$url  = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( $url === '' || $host === '' || $host === $own || ! preg_match( '#^https?://#i', $url ) ) {
				return;
			}
			$key = strtolower( rtrim( strtok( $url, '#?' ) ?: $url, '/' ) );
			if ( isset( $seen[ $key ] ) ) {
				$was = $seen[ $key ];
				// Only a menu item names a page; a link's own words in the content only hint at it.
				if ( ! $named || $label === '' ) {
					return;
				}
				// Menu items with different labels for one page (three services pointing at one list): none of them names it.
				$label_of = $was['named'] ? ( $was['label'] !== $label ? '' : $label ) : $label;
				foreach ( $tree as &$row ) {
					if ( strtolower( rtrim( strtok( $row['url'], '#?' ) ?: $row['url'], '/' ) ) === $key ) {
						$row['label'] = $label_of;
						if ( ! $was['named'] ) {
							$row['hint'] = $label;
						}
					}
				}
				unset( $row );
				$seen[ $key ] = array(
					'label' => $label_of,
					'named' => true,
				);
				return;
			}
			$seen[ $key ] = array(
				'label' => $label,
				'named' => $named,
			);
			$tree[]       = array(
				'label'    => $named ? $label : '',
				'hint'     => $label,
				'url'      => $url,
				'children' => array(),
			);
		};
		$origin = rtrim( (string) get_post_meta( $home_id, self::ORIGIN_META, true ), '/' );
		foreach ( array_unique( array_filter( array_map( 'intval', (array) get_nav_menu_locations() ) ) ) as $menu_id ) {
			foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $item ) {
				if ( ! is_object( $item ) ) {
					continue;
				}
				$label = html_entity_decode( wp_strip_all_tags( (string) $item->title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				// A menu item that opens a page built from the old site still names that old page.
				$built = (string) ( $item->type ?? '' ) === 'post_type' && (string) ( $item->object ?? '' ) === 'page' ? (int) $item->object_id : 0;
				$route = $built > 0 ? trim( (string) get_post_meta( $built, '_dxai_ui_source_route', true ), '/' ) : '';
				if ( $route !== '' && $origin !== '' && (int) get_post_meta( $built, \DXAI_UI\Structures\Page_Scope::META, true ) === $home_id ) {
					$add( $label, $origin . '/' . $route . '/', true );
					continue;
				}
				$add( $label, (string) $item->url, true );
			}
		}
		$html = (string) get_post_field( 'post_content', $home_id ) . "\n" . self::active_widgets_html();
		/*
		 * The design's own header and footer, when they stay with its pages as template parts (a classic theme,
		 * or "keep the site's header"): their links, and the menus (wp_navigation) they show. Those are the
		 * design's menus, so their labels name the pages.
		 */
		$chrome = '';
		foreach ( self::part_ids( $home_id ) as $part ) {
			$chrome .= "\n" . (string) get_post_field( 'post_content', $part );
		}
		if ( preg_match_all( '/<!-- wp:navigation\s+(\{.*?\})\s*\/?-->/s', $chrome, $navs ) ) {
			foreach ( $navs[1] as $json ) {
				$ref = (int) ( json_decode( $json, true )['ref'] ?? 0 );
				if ( $ref > 0 && get_post_type( $ref ) === 'wp_navigation' ) {
					$chrome .= "\n" . (string) get_post_field( 'post_content', $ref );
				}
			}
		}
		if ( preg_match_all( '/<!-- wp:navigation-(?:link|submenu)\s+(\{.*?\})\s*\/?-->/s', $chrome, $items ) ) {
			foreach ( $items[1] as $json ) {
				$attrs = json_decode( $json, true );
				if ( is_array( $attrs ) && ! empty( $attrs['url'] ) ) {
					$add( wp_strip_all_tags( html_entity_decode( (string) ( $attrs['label'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), (string) $attrs['url'], true );
				}
			}
		}
		$html .= $chrome;
		if ( preg_match_all( '#<a\b[^>]*\bhref="([^"]+)"[^>]*>(.*?)</a>#is', $html, $links, PREG_SET_ORDER ) ) {
			foreach ( $links as $l ) {
				$add( Content_Extractor::plain( $l[2] ), $l[1], false );
			}
		}
		// Pages found before (their links may point at the new pages by now).
		foreach ( (array) get_post_meta( $home_id, self::PAGES_META, true ) as $row ) {
			if ( is_array( $row ) && ! empty( $row['url'] ) ) {
				$add( (string) ( $row['label'] ?? '' ), (string) $row['url'], (bool) ( $row['named'] ?? false ) );
			}
		}

		return $tree;
	}

	/** Content of the block widgets placed in a sidebar (not the inactive ones). */
	private static function active_widgets_html(): string {
		$placed = array();
		foreach ( (array) wp_get_sidebars_widgets() as $area => $ids ) {
			if ( $area === 'wp_inactive_widgets' || ! is_array( $ids ) ) {
				continue;
			}
			foreach ( $ids as $id ) {
				if ( preg_match( '/^block-(\d+)$/', (string) $id, $m ) ) {
					$placed[ (int) $m[1] ] = true;
				}
			}
		}
		$html = '';
		foreach ( (array) get_option( 'widget_block', array() ) as $n => $widget ) {
			if ( isset( $placed[ (int) $n ] ) && is_array( $widget ) && isset( $widget['content'] ) ) {
				$html .= "\n" . (string) $widget['content'];
			}
		}

		return $html;
	}

	/**
	 * The old site's address: remembered, or the host most of the Home's and the menus' outside links go to.
	 *
	 * @param array<int, array<string, mixed>> $tree
	 */
	public static function origin( int $home_id, array $tree ): string {
		$known = (string) get_post_meta( $home_id, self::ORIGIN_META, true );
		if ( $known !== '' ) {
			return $known;
		}
		$origin = Site_Origin::from_menu_tree( $tree );
		if ( $origin !== '' ) {
			update_post_meta( $home_id, self::ORIGIN_META, $origin );
		}

		return $origin;
	}

	/**
	 * Remember the old site's pages (so the list survives the links being rewritten to the new pages).
	 *
	 * @param array<int, array<string, mixed>> $tree
	 */
	private static function remember( int $home_id, array $tree, string $origin ): void {
		$host  = strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) );
		$keep  = array();
		// What was known before stays known: a menu's name for a page is not forgotten because the menu now opens
		// the new page.
		$before = array();
		foreach ( (array) get_post_meta( $home_id, self::PAGES_META, true ) as $old ) {
			if ( is_array( $old ) && ! empty( $old['named'] ) && ! empty( $old['label'] ) ) {
				$before[ strtolower( rtrim( (string) $old['url'], '/' ) ) ] = (string) $old['label'];
			}
		}
		foreach ( $tree as $row ) {
			if ( strtolower( (string) wp_parse_url( (string) $row['url'], PHP_URL_HOST ) ) === $host ) {
				$was    = $before[ strtolower( rtrim( (string) $row['url'], '/' ) ) ] ?? '';
				$named  = (string) $row['label'] !== '' || $was !== '';
				$keep[] = array(
					'label' => (string) ( $row['label'] !== '' ? $row['label'] : ( $was !== '' ? $was : ( $row['hint'] ?? '' ) ) ),
					'named' => $named,
					'url'   => (string) $row['url'],
				);
			}
		}
		if ( $keep !== array() ) {
			update_post_meta( $home_id, self::PAGES_META, $keep );
		}
	}

	/**
	 * The old site's pages, each with the page built for it (if any).
	 *
	 * @return array{origin:string, home:array<string, mixed>, pages:array<int, array<string, mixed>>}
	 */
	public static function listing( int $home_id, string $given = '' ): array {
		// The old site's address as the person typed it replaces what the links suggested.
		$given = Site_Origin::normalize( $given );
		if ( $given !== '' ) {
			update_post_meta( $home_id, self::ORIGIN_META, $given );
		}
		$tree   = self::menu_tree( $home_id );
		$found  = Site_Origin::from_menu_tree( $tree );
		$origin = self::origin( $home_id, $tree );
		if ( $origin !== '' && $found !== '' && $found !== $origin ) {
			$tree = Site_Origin::rehost( $tree, $found, $origin );
		}
		// The design's links lead to none of the old site's pages: the old site's own navigation does.
		if ( $origin !== '' && Site_Origin::candidates( $tree, $origin, 1 ) === array() ) {
			$tree = array_merge( $tree, Site_Origin::from_site( $origin ) );
		}
		if ( $origin !== '' ) {
			// Pages built earlier are on the list whatever links to them now.
			$have = array_map( static fn( $r ) => Site_Origin::path_of( (string) $r['url'] ), $tree );
			foreach ( self::built( $home_id ) as $page ) {
				$route = trim( (string) get_post_meta( $page->ID, '_dxai_ui_source_route', true ), '/' );
				if ( $route !== '' && ! in_array( Site_Origin::path_of( '/' . $route ), $have, true ) ) {
					$tree[] = array(
						'label'    => '',
						'hint'     => html_entity_decode( get_the_title( $page ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
						'url'      => rtrim( $origin, '/' ) . '/' . $route . '/',
						'children' => array(),
					);
				}
			}
			self::remember( $home_id, $tree, $origin );
		}
		$pages  = array();
		$hints  = array();
		foreach ( $tree as $row ) {
			$hints[ Site_Origin::absolutize( (string) $row['url'], $origin ) ] = (string) ( $row['hint'] ?? '' );
		}
		foreach ( $origin === '' ? array() : Site_Origin::candidates( $tree, $origin, 80 ) as $c ) {
			$page    = self::page_for( $home_id, (string) $c['path'] );
			$pages[] = array(
				// The menu's name for the page; else the name of the page built for it; else a hint from a link.
				'label'   => html_entity_decode( (string) $c['label'] !== '' ? (string) $c['label'] : ( $page ? get_the_title( $page ) : ( $hints[ (string) $c['url'] ] ?? trim( (string) $c['path'], '/' ) ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'url'     => (string) $c['url'],
				'path'    => (string) $c['path'],
				'page_id' => $page ? (int) $page->ID : 0,
				'title'   => $page ? html_entity_decode( get_the_title( $page ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '',
				'status'  => $page ? ( Site_From_Menu::edited_since_import( $page ) ? 'edited' : 'imported' ) : 'new',
				'view'    => $page ? (string) get_permalink( $page ) : '',
				'edit'    => $page ? (string) get_edit_post_link( $page->ID, 'raw' ) : '',
			);
		}

		return array(
			'origin' => $origin,
			'home'   => array(
				'id'    => $home_id,
				'title' => get_the_title( $home_id ),
				'view'  => (string) get_permalink( $home_id ),
			),
			'pages'  => $pages,
		);
	}

	/**
	 * Pages built for this design from its old site.
	 *
	 * @return array<int, \WP_Post>
	 */
	private static function built( int $home_id ): array {
		return get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 200,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(
					array(
						'key'   => '_dxai_ui_from_live_menu',
						'value' => '1',
					),
					array(
						'key'   => \DXAI_UI\Structures\Page_Scope::META,
						'value' => (string) $home_id,
					),
				),
			)
		);
	}

	/** The page an import of this design built for an old path, if any (published or draft, not trashed). */
	public static function page_for( int $home_id, string $path ): ?\WP_Post {
		$route = trim( Site_Origin::path_of( $path ), '/' );
		$query = static fn( array $meta ): array => get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 1,
				// Never the Home itself (its own route can be the same path).
				'post__not_in'   => array( $home_id ),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array_merge(
					array(
						array(
							'key'   => '_dxai_ui_source_route',
							'value' => $route,
						),
						array(
							'key'   => '_dxai_ui_from_live_menu',
							'value' => '1',
						),
					),
					$meta
				),
			)
		);
		$found = $query(
			array(
				array(
					'key'   => \DXAI_UI\Structures\Page_Scope::META,
					'value' => (string) $home_id,
				),
			)
		);
		// A page that came over in an export package has its own scope; it is this design's page by its archive.
		$archive = (string) get_post_meta( $home_id, '_dxai_ui_source_zip', true );
		if ( $found === array() && $archive !== '' ) {
			$found = $query(
				array(
					array(
						'key'   => '_dxai_ui_source_zip',
						'value' => $archive,
					),
				)
			);
		}

		return $found[0] ?? null;
	}

	/**
	 * Start building the chosen pages: a Crawl_Job the caller drives (REST crawl-step) or cron finishes.
	 *
	 * @param array<int, string> $paths
	 * @return string|\WP_Error Job id.
	 */
	public static function start( int $home_id, array $paths, string $engine = 'design', bool $rebuild_edited = false ) {
		if ( get_post_type( $home_id ) !== 'page' ) {
			return new \WP_Error( 'dxai_ui_site_pages', __( 'That design page no longer exists.', 'dxai-ui' ), array( 'status' => 404 ) );
		}
		// The import of this design may still be building its pages (a crawl job of its own): starting here would
		// cancel it, and leave its menus and parts half done.
		foreach ( Crawl_Job::unfinished_for( $home_id ) as $job ) {
			if ( ( $job['tail']['mode'] ?? '' ) !== self::MODE ) {
				return new \WP_Error( 'dxai_ui_site_pages_busy', __( 'The import of this design is still building its pages. Let it finish (the import screen shows its progress), then build more pages here.', 'dxai-ui' ), array( 'status' => 409 ) );
			}
		}
		$tree   = self::menu_tree( $home_id );
		$origin = (string) get_post_meta( $home_id, self::ORIGIN_META, true );
		if ( $tree === array() && $origin === '' ) {
			return new \WP_Error( 'dxai_ui_site_pages', __( 'The design does not link to any page of an old site. Enter the old site’s address.', 'dxai-ui' ), array( 'status' => 400 ) );
		}
		$paths = array_values( array_filter( array_map( static fn( $p ) => Site_Origin::path_of( (string) $p ), $paths ) ) );
		if ( $paths === array() ) {
			return new \WP_Error( 'dxai_ui_site_pages', __( 'Choose at least one page.', 'dxai-ui' ), array( 'status' => 400 ) );
		}
		$context = array(
			'home_id'        => $home_id,
			'home_slug'      => '/',
			'part_key'       => '',
			'source_archive' => (string) get_post_meta( $home_id, '_dxai_ui_source_zip', true ),
			'wrapper_class'  => (string) get_post_meta( $home_id, '_dxai_ui_wrapper_class', true ),
			'home_title'     => get_the_title( $home_id ),
			'only_paths'     => $paths,
			'pages_engine'   => $engine,
			'chrome_markup'  => Section_Library::chrome_markup( $home_id ),
			// The old site, as found or as the person gave it (listing()).
			'origin'         => $origin,
			// Pages edited since their import are kept as they are, unless the person asked to build them again.
			'rebuild_edited' => $rebuild_edited,
		);
		$plan    = ( new Site_From_Menu() )->prepare( $context, $tree );
		if ( empty( $plan['candidates'] ) ) {
			return new \WP_Error( 'dxai_ui_site_pages', __( 'None of the chosen pages is on the old site the design links to.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		return Crawl_Job::create(
			array(
				'crawl' => $plan,
				'tail'  => array(
					'mode'    => self::MODE,
					'page_id' => $home_id,
				),
			)
		);
	}

	/**
	 * After the last page: every link to the old pages now opens the new ones — menu items, footer
	 * widgets, the Home, the design's header/footer parts and the pages themselves.
	 *
	 * @param array<string, mixed> $state The Crawl_Job state.
	 * @return array<string, mixed>
	 */
	public static function finish( array $state ): array {
		$summary = (array) ( $state['crawl']['summary'] ?? array() );
		$home_id = (int) ( $state['tail']['page_id'] ?? 0 );
		$origin  = (string) ( $summary['origin'] ?? '' );
		$rows    = array_values( array_filter( (array) ( $summary['link_pages'] ?? array() ), 'is_array' ) );

		// Pages built earlier from the same old site count too: links to them are fixed as well.
		foreach ( self::listing( $home_id )['pages'] as $p ) {
			if ( $p['page_id'] > 0 && ! in_array( (int) $p['page_id'], array_column( $rows, 'id' ), true ) ) {
				$rows[] = array(
					'id'    => (int) $p['page_id'],
					'paths' => self::paths( (string) $p['path'], $origin ),
				);
			}
		}
		$map = array();
		foreach ( $rows as $row ) {
			foreach ( (array) ( $row['paths'] ?? array() ) as $p ) {
				$map[ (string) $p ] = (int) $row['id'];
			}
		}

		// Posts: the Home, the design's parts, and the pages.
		$targets = $rows;
		foreach ( array_merge( array( $home_id ), self::part_ids( $home_id ) ) as $id ) {
			if ( $id > 0 ) {
				$targets[] = array(
					'id'    => $id,
					'paths' => array(),
				);
			}
		}
		/*
		 * The pages this run wrote (over several requests) and the earlier ones nobody edited since are the
		 * importer's: stamped again after the link rewrite below writes them, or the next list shows them all as
		 * edited and a rebuild keeps them.
		 */
		foreach ( (array) ( $summary['pages_created'] ?? array() ) as $row ) {
			Site_From_Menu::mark_imported( (int) ( $row['id'] ?? 0 ) );
		}
		foreach ( $rows as $row ) {
			$page = get_post( (int) ( $row['id'] ?? 0 ) );
			if ( $page instanceof \WP_Post && ! Site_From_Menu::edited_since_import( $page ) ) {
				Site_From_Menu::mark_imported( (int) $page->ID );
			}
		}
		$links = new Internal_Links();
		$links->rewrite_posts( $targets );

		// Menu items that are custom links to an old page become links to its new page; links to a section of the
		// Home ("#about") open it from every page, not only from the Home.
		$home_url = (string) get_permalink( $home_id );
		$menus    = 0;
		foreach ( wp_get_nav_menus() as $menu ) {
			foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
				if ( ! is_object( $item ) || $item->type !== 'custom' ) {
					continue;
				}
				// Every field a person may have set is carried over: wp_update_nav_menu_item() resets what it is not handed.
				$keep = array(
					'menu-item-title'       => wp_slash( (string) $item->title ),
					'menu-item-parent-id'   => (int) $item->menu_item_parent,
					'menu-item-position'    => (int) $item->menu_order,
					'menu-item-classes'     => implode( ' ', array_filter( (array) $item->classes ) ),
					'menu-item-target'      => (string) $item->target,
					'menu-item-description' => wp_slash( (string) $item->description ),
					'menu-item-attr-title'  => wp_slash( (string) $item->attr_title ),
					'menu-item-xfn'         => (string) $item->xfn,
					'menu-item-status'      => 'publish',
				);
				$url  = (string) $item->url;
				if ( preg_match( '/^#[A-Za-z][\w-]*$/', $url ) && $home_url !== '' && self::home_has_anchor( $home_id, substr( $url, 1 ) ) ) {
					wp_update_nav_menu_item( (int) $menu->term_id, (int) $item->ID, array_merge( $keep, array( 'menu-item-type' => 'custom', 'menu-item-url' => $home_url . $url ) ) );
					continue;
				}
				$page = self::match( $url, $origin, $rows );
				if ( $page < 1 ) {
					continue;
				}
				wp_update_nav_menu_item(
					(int) $menu->term_id,
					(int) $item->ID,
					array_merge(
						$keep,
						array(
							'menu-item-object-id' => $page,
							'menu-item-object'    => 'page',
							'menu-item-type'      => 'post_type',
						)
					)
				);
				/*
				 * WordPress stores no title for a page item whose label equals the page's title — the item then shows
				 * whatever the page is called later, and renaming the page renamed the menu. The menu keeps its own
				 * words: written onto the item itself.
				 */
				$label = (string) $item->title;
				if ( $label !== '' && (string) get_post_field( 'post_title', (int) $item->ID, 'raw' ) !== $label ) {
					wp_update_post(
						array(
							'ID'         => (int) $item->ID,
							'post_title' => wp_slash( $label ),
						)
					);
				}
				++$menus;
			}
		}

		// Footer widgets (block widgets): their links.
		$widgets = 0;
		$blocks  = get_option( 'widget_block', array() );
		if ( is_array( $blocks ) ) {
			foreach ( $blocks as $k => $widget ) {
				if ( ! is_array( $widget ) || ! isset( $widget['content'] ) ) {
					continue;
				}
				$new = $links->rewrite_html( (string) $widget['content'], $rows );
				$new = self::home_anchors( $new, $home_id, $home_url );
				if ( $new !== (string) $widget['content'] ) {
					$blocks[ $k ]['content'] = $new;
					++$widgets;
				}
			}
			if ( $widgets > 0 ) {
				update_option( 'widget_block', $blocks );
			}
		}

		$created = array();
		foreach ( (array) ( $summary['pages_created'] ?? array() ) as $row ) {
			$id        = (int) ( $row['id'] ?? 0 );
			$created[] = array(
				'id'    => $id,
				'label' => (string) ( $row['label'] ?? '' ),
				'path'  => (string) ( $row['path'] ?? '' ),
				'view'  => (string) get_permalink( $id ),
				'edit'  => (string) get_edit_post_link( $id, 'raw' ),
			);
		}

		// The new pages count for the design's colour use (Theme_Binding).
		\DXAI_UI\Theme\Theme_Binding::after_import( $home_id );

		return array(
			'mode'          => self::MODE,
			'pages_created' => $created,
			'pages_skipped' => (array) ( $summary['pages_skipped'] ?? array() ),
			'crawl_errors'  => (array) ( $summary['crawl_errors'] ?? array() ),
			'menus_updated' => $menus,
			'widgets'       => $widgets,
			'compose_log'   => (array) ( $summary['compose_log'] ?? array() ),
		);
	}

	/** The id of the new page an old URL points to, or 0. */
	/** Whether the Home has an element with this id (a section anchor). */
	private static function home_has_anchor( int $home_id, string $id ): bool {
		return (bool) preg_match( '/\bid="' . preg_quote( $id, '/' ) . '"|"anchor":"' . preg_quote( $id, '/' ) . '"/', (string) get_post_field( 'post_content', $home_id ) );
	}

	/** Links in markup to a section of the Home (href="#about") made to open it from any page. */
	public static function home_anchors( string $markup, int $home_id, string $home_url = '' ): string {
		$home_url = $home_url !== '' ? $home_url : (string) get_permalink( $home_id );
		if ( $home_url === '' || ! str_contains( $markup, 'href="#' ) ) {
			return $markup;
		}

		return (string) preg_replace_callback(
			'/href="#([A-Za-z][\w-]*)"/',
			static fn( $m ) => self::home_has_anchor( $home_id, $m[1] ) ? 'href="' . esc_url( $home_url . '#' . $m[1] ) . '"' : $m[0],
			$markup
		);
	}

	private static function match( string $url, string $origin, array $rows ): int {
		$host = Site_Origin::bare_host( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( $host === '' || $host !== Site_Origin::bare_host( (string) wp_parse_url( $origin, PHP_URL_HOST ) ) ) {
			return 0;
		}
		$path = Site_Origin::path_of( $url );
		foreach ( $rows as $row ) {
			foreach ( (array) ( $row['paths'] ?? array() ) as $p ) {
				if ( Site_Origin::path_of( (string) $p ) === $path ) {
					return (int) $row['id'];
				}
			}
		}

		return 0;
	}

	/** @return array<int, string> */
	private static function paths( string $path, string $origin ): array {
		$path = Site_Origin::path_of( $path );
		$out  = array( $path, rtrim( $path, '/' ) );
		// The old site under both of its names: with and without www.
		foreach ( Site_Origin::twins( $origin ) as $o ) {
			$out[] = rtrim( $o, '/' ) . $path;
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * The design's header/footer template parts the Home references.
	 *
	 * @return array<int, int>
	 */
	private static function part_ids( int $home_id ): array {
		$ids = array();
		foreach ( parse_blocks( (string) get_post_field( 'post_content', $home_id ) ) as $b ) {
			if ( ( $b['blockName'] ?? '' ) === 'core/template-part' ) {
				$slug  = (string) ( $b['attrs']['slug'] ?? '' );
				$theme = (string) ( $b['attrs']['theme'] ?? get_stylesheet() );
				$part  = get_posts(
					array(
						'post_type'      => 'wp_template_part',
						'name'           => $slug,
						'posts_per_page' => 1,
						'post_status'    => 'publish',
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						'tax_query'      => array(
							array(
								'taxonomy' => 'wp_theme',
								'field'    => 'name',
								'terms'    => $theme,
							),
						),
					)
				);
				if ( $part ) {
					$ids[] = (int) $part[0]->ID;
				}
			}
		}

		return $ids;
	}
}

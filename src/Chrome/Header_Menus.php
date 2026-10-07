<?php
/**
 * The header's menus: locations, the design's items, the Site Logo.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Chrome;

use DXAI_UI\Structures\Navigation_Factory;

/**
 * Where a converted header gets its content: Appearance > Menus, the way
 * DevriX themes build theirs, and the WordPress Site Logo.
 *
 * - `primary-navigation` — the main navigation, up to three levels. The
 *   DevriX theme's own location id, so a theme that registers it keeps its
 *   registration and the header reads the same menu the theme would.
 * - `header-top` — the trust / announcement strip: text items, a link when
 *   the item has a real URL.
 * - `header-actions` — the call-to-action buttons, tel: and mailto: links
 *   included; an item's description is the eyebrow line beside its button.
 *
 * install() is the one entry point an import calls: it reads the header
 * markup into a template, checks that the template renders the design's
 * header exactly (verify()), writes the design's items into the three menus
 * (in place, keeping what a person set — Navigation_Factory), assigns the
 * locations, gives the site the design's logo when it has none, and stores
 * the template. The design that was installed last is the site's header and
 * holds the locations, as the last import owns the brand; every other
 * design's pages keep their own header, filled from their own menus
 * (Header_Template::for_scope()).
 */
final class Header_Menus {

	/** Names of the menus install() writes, after the design's title. */
	private const SUFFIX = array(
		Header_Template::NAV     => 'Primary',
		Header_Template::TOP     => 'Header Top',
		Header_Template::ACTIONS => 'Header Buttons',
	);

	/** The columns Appearance > Menus hides until a person opens Screen Options. */
	private const CORE_HIDDEN = array( 'link-target', 'css-classes', 'xfn', 'description', 'title-attribute' );

	private static bool $registered = false;

	public function register(): void {
		add_action( 'init', array( self::class, 'register_locations' ) );
		add_filter( 'hidden_columns', array( $this, 'show_item_fields' ), 10, 2 );
		add_action( 'after_switch_theme', array( $this, 'reassign' ), 20 );
	}

	/**
	 * The locations and their names in Appearance > Menus.
	 *
	 * @return array<string, string>
	 */
	public static function locations(): array {
		return array(
			Header_Template::NAV     => __( 'Primary Navigation', 'dxai-ui' ),
			Header_Template::TOP     => __( 'Header top bar', 'dxai-ui' ),
			Header_Template::ACTIONS => __( 'Header buttons', 'dxai-ui' ),
		);
	}

	/**
	 * Register each location no theme registered already.
	 *
	 * Themes register on after_setup_theme, before init, so a theme's own
	 * `primary-navigation` (the DevriX theme's) is seen here and left as the
	 * theme named it. register_nav_menus() also adds theme support for
	 * menus, which is what puts Appearance > Menus on a block theme's menu.
	 */
	public static function register_locations(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		$have = get_registered_nav_menus();
		$add  = array();
		foreach ( self::locations() as $slug => $label ) {
			if ( ! isset( $have[ $slug ] ) ) {
				$add[ $slug ] = $label;
			}
		}
		if ( $add !== array() ) {
			register_nav_menus( $add );
		}
	}

	/**
	 * Install a design's header.
	 *
	 * @param string               $header_markup The header's block markup (what the header part held).
	 * @param array<string, mixed> $context       title (design title), archive (source ZIP name),
	 *                                            page_id and scope_id (the design's page and its scope),
	 *                                            path_map + origin (crawl: design path => page id),
	 *                                            logo_attachment_id (when the importer knows it),
	 *                                            take_site (default true: the header becomes the
	 *                                            site's; false keeps it its design's own — no
	 *                                            locations, no Site Logo, reported as site_kept),
	 *                                            owner and adopt_legacy (whose menus, see
	 *                                            Navigation_Factory).
	 *                                            menu_items / menu_tree are accepted and not needed:
	 *                                            the header markup itself is what renders, so the menus
	 *                                            are written from it.
	 * @return array<string, mixed> block_markup, menus (location => term id), locations_kept
	 *                              (location => the person's menu that keeps it),
	 *                              locations_released (location => the previous header menu
	 *                              taken off a location this design has no menu for), logo, slots,
	 *                              logos, option, scope_option, replaced (the source of the
	 *                              design that was the site's header until now, when it was
	 *                              another), site_kept (take_site false: the source of the
	 *                              site's header, which stays); or block_markup '' with `error` and a
	 *                              `message`: `no_header` when the markup holds no block,
	 *                              `header_unreadable` when its menu slots cannot be read so
	 *                              that the design's items render the design's header exactly
	 *                              — the importer keeps the header part then, and nothing
	 *                              (menus, locations, logo, template) was written.
	 */
	public static function install( string $header_markup, array $context = array() ): array {
		self::register_locations();

		/*
		 * What renders is this markup, unfiltered, on every page holding the
		 * block — so it is held to what the person importing may publish, as
		 * the header part it replaces was when wp_insert_post() saved it.
		 * Footer_Widgets::install() does the same for the footer.
		 */
		if ( is_user_logged_in() && ! current_user_can( 'unfiltered_html' ) ) {
			$header_markup = wp_kses_post( $header_markup );
		}
		$real = array_filter( parse_blocks( $header_markup ), static fn( array $b ): bool => Header_Template::kind( $b ) !== 'none' );
		if ( $real === array() ) {
			return array(
				'block_markup' => '',
				'error'        => 'no_header',
				'message'      => __( 'The design has no header.', 'dxai-ui' ),
			);
		}

		$spec  = Header_Template::build( $header_markup );
		$check = self::verify( $spec );
		if ( ! $check['ok'] ) {
			return array(
				'block_markup' => '',
				'error'        => 'header_unreadable',
				'reason'       => $check['reason'],
				'at'           => $check['at'],
				'message'      => self::unreadable_message( $check['reason'] ),
				'slots'        => self::slot_report( $spec ),
				'logos'        => count( (array) $spec['logos'] ),
			);
		}

		$title   = trim( wp_strip_all_tags( (string) ( $context['title'] ?? '' ) ) );
		$title   = $title !== '' ? $title : __( 'Site', 'dxai-ui' );
		$scope   = (int) ( $context['scope_id'] ?? ( $context['page_id'] ?? 0 ) );
		$archive = (string) ( $context['archive'] ?? '' );
		/*
		 * Whether this header becomes the site's: the theme locations, the
		 * Site Logo when the site has none, and the header every page of no
		 * installed design shows. An import that installs its header does
		 * (the person chose to, or no other design's is the site's —
		 * Chrome_Choice); a crawl finishing after another design was
		 * installed does not (rebind(), holds_site()): its menus are written
		 * and its own pages render them, and the site keeps its header.
		 * Without a scope there is nothing to keep it under but the site's.
		 */
		$take_site = $scope < 1 || ! array_key_exists( 'take_site', $context ) || ! empty( $context['take_site'] );
		/*
		 * Whose menus these are: the design's identity (its home page's page
		 * key), so a menu is found by who it was written for and not by its
		 * name — another archive with the same title gets menus of its own
		 * ("Semper Dry Primary (other-archive)") instead of rewriting these.
		 */
		$owner = (string) ( $context['owner'] ?? '' );
		$owner = $owner !== '' ? $owner : Navigation_Factory::owner_key( (int) ( $context['page_id'] ?? 0 ), $archive );

		// This design's header as installed before (a re-import), and the
		// site's header until now.
		$previous = Header_Template::scoped( $scope );
		$site     = Header_Template::stored();
		$assigned = get_nav_menu_locations();

		$nav   = new Navigation_Factory(
			$owner,
			(string) preg_replace( '/\.zip$/i', '', $archive ),
			// A menu with no owner record is taken by name only when this
			// design was imported before (the importer says so): an older
			// version named menus after the design's title alone.
			! empty( $context['adopt_legacy'] )
		);
		$menus = array();
		$kept  = array();
		foreach ( (array) $spec['trees'] as $location => $tree ) {
			if ( ! is_array( $tree ) || $tree === array() || ! isset( self::SUFFIX[ $location ] ) ) {
				continue;
			}
			/*
			 * The menu this design's header wrote last time, by id: a person
			 * may have renamed it in Appearance > Menus, and a lookup by name
			 * would then create a second "{title} Primary" and move the
			 * location to it, leaving theirs orphaned.
			 */
			$own    = (int) ( $previous['menus'][ $location ] ?? 0 );
			$own    = self::is_header_menu( $own, (string) $location ) ? $own : 0;
			$free   = self::takes_location( (string) $location, $assigned );
			$assign = $take_site && $free;
			$id     = $nav->header_menu(
				$title . ' ' . self::SUFFIX[ $location ],
				$tree,
				(string) $location,
				is_array( $context['path_map'] ?? null ) ? $context['path_map'] : array(),
				(string) ( $context['origin'] ?? '' ),
				$own,
				$assign
			);
			if ( $id > 0 ) {
				$menus[ $location ] = $id;
			}
			// A person's own menu holds the location: the site's header draws
			// it (menu_for()), and the import says so.
			if ( $take_site && ! $free ) {
				$kept[ $location ] = (int) $assigned[ $location ];
			}
		}

		/*
		 * A header location this design has nothing for (a design without a
		 * top bar) that still holds the header menu of the design installed
		 * before: released, so a theme drawing every location — a classic
		 * theme does, on every page — does not show the previous design's top
		 * bar under this design's navigation. A person's own menu keeps its
		 * location, and no menu is deleted (Appearance > Menus still lists it).
		 */
		$released = array();
		if ( $take_site ) {
			$locations = get_theme_mod( 'nav_menu_locations', array() );
			$locations = is_array( $locations ) ? $locations : array();
			foreach ( array_keys( self::SUFFIX ) as $location ) {
				$held = (int) ( $locations[ $location ] ?? 0 );
				if ( isset( $menus[ $location ] ) || $held < 1 ) {
					continue;
				}
				if ( '' !== (string) get_term_meta( $held, Navigation_Factory::HEADER_MENU_META, true ) ) {
					$released[ $location ] = $held;
					unset( $locations[ $location ] );
				}
			}
			if ( $released !== array() ) {
				set_theme_mod( 'nav_menu_locations', $locations );
			}
		}

		$spec['menus']     = $menus;
		$spec['logo']      = self::install_logo( $spec, $context + array( 'set_site_logo' => $take_site ) );
		$spec['source']    = array(
			'title'    => $title,
			'archive'  => $archive,
			'page_id'  => (int) ( $context['page_id'] ?? 0 ),
			'scope_id' => $scope,
			'owner'    => $owner,
		);
		$spec['installed'] = time();
		Header_Template::store( $spec, $take_site );

		return array(
			'block_markup'   => Site_Header_Block::MARKUP,
			'menus'          => $menus,
			'locations_kept' => $kept,
			// Locations this design has no menu for, taken from the header
			// menu that held them (location => that menu's id).
			'locations_released' => $released,
			'logo'           => $spec['logo'],
			'slots'          => self::slot_report( $spec ),
			'logos'          => count( (array) $spec['logos'] ),
			'option'         => $take_site ? Header_Template::OPTION : '',
			'scope_option'   => $scope > 0 ? Header_Template::SCOPE_OPTION . $scope : '',
			// Another design's header was the site's until now: its pages
			// keep it (their own), the theme locations follow this one.
			'replaced'       => $take_site && null !== $site && Header_Template::scope_of( $site ) !== $scope ? (array) ( $site['source'] ?? array() ) : array(),
			// Not made the site's header (take_site): the site keeps this one.
			'site_kept'      => ! $take_site && null !== $site ? (array) ( $site['source'] ?? array() ) : array(),
		);
	}

	/**
	 * Whether a design may take the site's header without taking it from
	 * another design: none is installed, it is this design's own ($scope),
	 * or the design it came from is gone (its page deleted or in the trash).
	 * A crawl that finishes after its import takes the site's header back
	 * only then (rebind()); an import that installs its header always takes
	 * it (Chrome_Choice decided so before it ran).
	 */
	public static function holds_site( int $scope ): bool {
		$site = Header_Template::stored();
		if ( null === $site ) {
			return true;
		}
		$held = Header_Template::scope_of( $site );
		if ( $held < 1 || $held === $scope ) {
			return true;
		}
		$page   = (int) ( $site['source']['page_id'] ?? 0 );
		$status = $page > 0 ? get_post_status( $page ) : false;

		return false === $status || 'trash' === $status;
	}

	/**
	 * Whether a spec renders its design's header exactly while the menus hold
	 * the design's own items: the template rendered with those items (as menu
	 * items, design_nodes()) against the header markup rendered as it is.
	 *
	 * Byte for byte, not approximately. The header block replaces the header
	 * part on the page; a reader that misplaced one item — a nav read as one
	 * link out of five, a mega menu read as buttons — would ship a header
	 * that differs from the design on every page. Such a design keeps its
	 * part instead, and its header is edited in the Site Editor as before.
	 *
	 * Rendering back is necessary, not enough: a reader that took a
	 * breadcrumb trail for the menu, or saw one nav item of five, renders the
	 * design back just as exactly. So the reader's checks come first
	 * (Header_Template::checks()): a navigation of at least two items that
	 * is not a trail, and no link in a <nav> left outside every slot.
	 *
	 * @param array<string, mixed> $spec Header_Template::build()'s result.
	 * @return array{ok:bool, reason:string, at:int} reason: `no_navigation` (no menu the
	 *                                               primary navigation could be: none, one
	 *                                               item, a breadcrumb trail), `nav_unread`
	 *                                               (links in a <nav> no slot holds) or
	 *                                               `not_identical` (at: the first differing byte).
	 */
	public static function verify( array $spec ): array {
		$trees  = (array) ( $spec['trees'] ?? array() );
		$checks = (array) ( $spec['checks'] ?? array() );
		if ( empty( $trees[ Header_Template::NAV ] ) || (int) ( $checks['nav_items'] ?? 0 ) < 2 || ! empty( $checks['breadcrumb'] ) ) {
			return array(
				'ok'     => false,
				'reason' => 'no_navigation',
				'at'     => -1,
			);
		}
		if ( (int) ( $checks['unread_links'] ?? 0 ) > 0 ) {
			return array(
				'ok'     => false,
				'reason' => 'nav_unread',
				'at'     => -1,
			);
		}
		$menus = array();
		foreach ( $trees as $location => $tree ) {
			$menus[ (string) $location ] = self::design_nodes( (array) $tree );
		}
		$want = do_blocks( (string) $spec['markup'] );
		$got  = ( new Header_Renderer( $spec, $menus, array() ) )->render();
		if ( $want === $got ) {
			return array(
				'ok'     => true,
				'reason' => '',
				'at'     => -1,
			);
		}
		$n = min( strlen( $want ), strlen( $got ) );
		$i = 0;
		while ( $i < $n && $want[ $i ] === $got[ $i ] ) {
			++$i;
		}

		return array(
			'ok'     => false,
			'reason' => 'not_identical',
			'at'     => $i,
		);
	}

	/**
	 * What the import tells a person about a header it kept as a part.
	 */
	private static function unreadable_message( string $reason ): string {
		if ( 'no_navigation' === $reason ) {
			return __( 'The header has no navigation menu to fill (none, a single link, or a breadcrumb trail), so it was kept as the design drew it.', 'dxai-ui' );
		}
		if ( 'nav_unread' === $reason ) {
			return __( 'Some of the header\'s navigation links could not be matched to menu items, so it was kept as the design drew it.', 'dxai-ui' );
		}

		return __( 'The header could not be rebuilt from menus exactly as the design drew it, so it was kept as the design drew it.', 'dxai-ui' );
	}

	/**
	 * A Header_Template tree as the nodes items_tree() returns, with menu
	 * item objects holding exactly the fields install() writes.
	 *
	 * @param array<int, array<string, mixed>> $tree
	 * @return array<int, array<string, mixed>>
	 */
	public static function design_nodes( array $tree ): array {
		$out = array();
		foreach ( $tree as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = array(
				'item'     => (object) array(
					'ID'               => 0,
					'menu_item_parent' => 0,
					'title'            => (string) ( $row['label'] ?? '' ),
					'url'              => (string) ( $row['url'] ?? '' ),
					'target'           => (string) ( $row['target'] ?? '' ),
					'xfn'              => (string) ( $row['xfn'] ?? '' ),
					'attr_title'       => (string) ( $row['attr_title'] ?? '' ),
					'description'      => (string) ( $row['description'] ?? '' ),
					'classes'          => array( '' ),
					'current'          => false,
				),
				'children' => self::design_nodes( (array) ( $row['children'] ?? array() ) ),
			);
		}

		return $out;
	}

	/**
	 * What install() reports of a spec's slots.
	 *
	 * @param array<string, mixed> $spec
	 * @return array<int, array{location:string, primary:bool, condensed:bool, items:int}>
	 */
	private static function slot_report( array $spec ): array {
		$slots = array();
		foreach ( (array) ( $spec['slots'] ?? array() ) as $slot ) {
			$slots[] = array(
				'location'  => (string) $slot['location'],
				'primary'   => (bool) $slot['primary'],
				'condensed' => (bool) $slot['condensed'],
				'items'     => count( (array) $slot['positions'] ),
			);
		}

		return $slots;
	}

	/**
	 * Whether $id is a header menu written for $location.
	 */
	private static function is_header_menu( int $id, string $location ): bool {
		return $id > 0
			&& get_term( $id, 'nav_menu' ) instanceof \WP_Term
			&& $location === (string) get_term_meta( $id, Navigation_Factory::HEADER_MENU_META, true );
	}

	/**
	 * Whether an install assigns $location to its menu: yes, unless a person
	 * assigned it a menu of their own — one no header install wrote. That
	 * choice stays; the site's header then draws their menu in the design's
	 * look. A location held by a header menu (this design's, or the design
	 * that was the site's header until now) follows the install.
	 *
	 * @param array<string, int> $assigned Current locations.
	 */
	private static function takes_location( string $location, array $assigned ): bool {
		$id = (int) ( $assigned[ $location ] ?? 0 );
		if ( $id < 1 || ! get_term( $id, 'nav_menu' ) instanceof \WP_Term ) {
			return true;
		}

		return '' !== (string) get_term_meta( $id, Navigation_Factory::HEADER_MENU_META, true );
	}

	/**
	 * Point the installed header's menu items at the pages a crawl created
	 * (site from menu): install() again from the stored header with the
	 * crawl's page map, so each item whose design path became a page links
	 * to that page — as Navigation_Factory::save_tree() did for the old
	 * `dxai-primary` menu, which it no longer writes once a header owns it.
	 * Everything a person changed on an item is kept, as on any re-import.
	 *
	 * The design whose header is rebound is the crawl's own ($scope): a
	 * crawl finishes minutes after its import, and another design may have
	 * been installed in between — the site's header is then that one, and
	 * rebinding it would point the other design's menus at this crawl's
	 * pages. The crawl's design keeps the site's header when it has it, and
	 * takes it only when it is free (holds_site()); otherwise only its own
	 * menus and its own pages' header are rebound, and the locations stay
	 * with the design installed since. With no scope, the site's header.
	 *
	 * $markup is the header as the import has it now — its template part
	 * after the crawl's link rewrite (Structure_Repository::finish_crawl()),
	 * so a link outside the menu slots (a call-to-action, the brand link)
	 * points at the crawled page too, as it does when the crawl runs inside
	 * the import. '' rebuilds from the stored header; so does a markup the
	 * reader cannot rebuild exactly any more.
	 *
	 * @param array<string, int> $path_map Design path => page id.
	 * @param string             $origin   The design's origin, for absolute design URLs.
	 * @param int                $scope    The design's scope (its home page id); 0: the site's header.
	 * @param string             $markup   The header's current block markup; '' for the stored one.
	 * @return array<string, mixed> install()'s result; [] when no header is installed.
	 */
	public static function rebind( array $path_map, string $origin = '', int $scope = 0, string $markup = '' ): array {
		$spec = $scope > 0 ? Header_Template::scoped( $scope ) : Header_Template::stored();
		if ( null === $spec || ( $path_map === array() && $markup === '' ) ) {
			return array();
		}
		$source  = is_array( $spec['source'] ?? null ) ? $spec['source'] : array();
		$context = array(
			'title'              => (string) ( $source['title'] ?? '' ),
			'archive'            => (string) ( $source['archive'] ?? '' ),
			'page_id'            => (int) ( $source['page_id'] ?? 0 ),
			'scope_id'           => (int) ( $source['scope_id'] ?? 0 ),
			'owner'              => (string) ( $source['owner'] ?? '' ),
			'logo_attachment_id' => (int) ( $spec['logo']['attachment_id'] ?? 0 ),
			'path_map'           => $path_map,
			'origin'             => $origin,
			'take_site'          => $scope < 1 || self::holds_site( $scope ),
		);
		if ( $markup !== '' && $markup !== (string) $spec['markup'] ) {
			$answer = self::install( $markup, $context );
			if ( '' !== (string) ( $answer['block_markup'] ?? '' ) ) {
				return $answer;
			}
		}

		return self::install( (string) $spec['markup'], $context );
	}

	/**
	 * The menu a location renders in a header.
	 *
	 * The site's header (the design installed last) reads the menu assigned
	 * to the location, else the one install() wrote for it: locations are
	 * theme mods, so a theme switch leaves a location unassigned until core
	 * maps it or a person assigns it again, and the header does not go empty
	 * in between. Another design's header — its pages keep it after a newer
	 * design took the locations — reads only the menus written for it
	 * ("{its title} Primary" …), which Appearance > Menus lists by name.
	 *
	 * A menu the install wrote for ONE location is the design's items for that
	 * slot of the header. Assigned to another location (the primary menu on
	 * "Header buttons", a slip in Appearance > Menus > Manage Locations) it is not
	 * read there: it would draw the navigation's items in the call-to-action
	 * buttons' look, over the logo. The slot keeps the menu written for it. A menu
	 * of a person's own (one no install wrote, or a copy of one) is read wherever
	 * it is assigned.
	 *
	 * @param array<string, mixed> $spec
	 */
	public static function menu_for( string $location, array $spec ): int {
		$ids = array( (int) ( $spec['menus'][ $location ] ?? 0 ) );
		if ( Header_Template::is_site( $spec ) ) {
			$assigned = get_nav_menu_locations();
			$mine     = (int) ( $assigned[ $location ] ?? 0 );
			if ( $mine > 0 && ! self::written_for_another( $mine, $location ) ) {
				array_unshift( $ids, $mine );
			}
		}
		foreach ( $ids as $id ) {
			if ( $id > 0 && get_term( $id, 'nav_menu' ) instanceof \WP_Term ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * Whether a menu is one a header install wrote for a location other than $location.
	 */
	private static function written_for_another( int $id, string $location ): bool {
		$for = (string) get_term_meta( $id, Navigation_Factory::HEADER_MENU_META, true );

		return '' !== $for && $location !== $for;
	}

	/**
	 * Every location's items as a tree, for Header_Renderer. A location with
	 * no menu at all is null: the header then shows the design's own items
	 * rather than nothing. An existing but empty menu is an empty list.
	 *
	 * @param array<string, mixed> $spec
	 * @return array<string, array<int, array<string, mixed>>|null>
	 */
	public static function trees( array $spec ): array {
		$out = array();
		foreach ( (array) ( $spec['slots'] ?? array() ) as $slot ) {
			$location = (string) ( $slot['location'] ?? '' );
			if ( $location === '' || array_key_exists( $location, $out ) ) {
				continue;
			}
			$menu             = self::menu_for( $location, $spec );
			$out[ $location ] = $menu > 0 ? self::items_tree( $menu ) : null;
		}

		return $out;
	}

	/**
	 * A menu's published items as nested nodes, three levels deep.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function items_tree( int $menu_id ): array {
		$items = wp_get_nav_menu_items( $menu_id, array( 'update_post_term_cache' => false ) );
		if ( ! is_array( $items ) ) {
			return array();
		}
		$nodes = array();
		$order = array();
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || ! empty( $item->_invalid ) ) {
				continue;
			}
			$item->current           = self::is_current( $item );
			$nodes[ (int) $item->ID ] = array(
				'item'     => $item,
				'children' => array(),
				'parent'   => (int) $item->menu_item_parent,
			);
			$order[]                 = (int) $item->ID;
		}

		/*
		 * Children attached deepest first, in menu order: a node is copied
		 * into its parent only once its own children are in it. An item whose
		 * parent is gone (unpublished, deleted) is shown at the top level,
		 * where core's walker shows an orphan too.
		 */
		$depth = array();
		foreach ( $order as $id ) {
			$d = 1;
			$p = $nodes[ $id ]['parent'];
			while ( $p > 0 && isset( $nodes[ $p ] ) && $d < 10 ) {
				++$d;
				$p = $nodes[ $p ]['parent'];
			}
			$depth[ $id ] = $d;
		}
		$ids = $order;
		usort( $ids, static fn( int $a, int $b ): int => $depth[ $b ] <=> $depth[ $a ] ?: array_search( $a, $order, true ) <=> array_search( $b, $order, true ) );
		$attached = array();
		foreach ( $ids as $id ) {
			$parent = $nodes[ $id ]['parent'];
			if ( $parent > 0 && isset( $nodes[ $parent ] ) ) {
				$attached[ $id ] = true;
			}
		}
		foreach ( $ids as $id ) {
			$parent = $nodes[ $id ]['parent'];
			if ( isset( $attached[ $id ] ) ) {
				$nodes[ $parent ]['children'][ $id ] = $nodes[ $id ];
			}
		}

		$tree = array();
		foreach ( $order as $id ) {
			if ( ! isset( $attached[ $id ] ) ) {
				$tree[] = self::strip( $nodes[ $id ], $order );
			}
		}

		return $tree;
	}

	/**
	 * A node with its children in menu order and its bookkeeping dropped.
	 *
	 * @param array<string, mixed> $node
	 * @param array<int, int>      $order
	 * @return array<string, mixed>
	 */
	private static function strip( array $node, array $order ): array {
		$kids = (array) $node['children'];
		uksort( $kids, static fn( $a, $b ): int => array_search( (int) $a, $order, true ) <=> array_search( (int) $b, $order, true ) );
		$out = array();
		foreach ( $kids as $kid ) {
			$out[] = self::strip( $kid, $order );
		}

		return array(
			'item'     => $node['item'],
			'children' => $out,
		);
	}

	/**
	 * Whether an item points at the page being viewed. Worked out here rather
	 * than with core's _wp_menu_item_classes_by_context(), which also writes
	 * `menu-item-type-custom` and friends into the item's classes, and the
	 * header puts a person's classes on the element.
	 *
	 * @param object $item
	 */
	private static function is_current( $item ): bool {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		$queried = (int) get_queried_object_id();
		if ( 'post_type' === ( $item->type ?? '' ) ) {
			return $queried > 0 && (int) $item->object_id === $queried && is_singular();
		}
		$url = (string) ( $item->url ?? '' );
		if ( $url === '' || str_starts_with( $url, '#' ) || ! preg_match( '#^https?://#i', $url ) ) {
			return false;
		}
		global $wp;
		$here = home_url( $wp instanceof \WP ? (string) $wp->request : '' );
		$norm = static fn( string $u ): string => untrailingslashit( (string) strtok( set_url_scheme( $u, 'http' ), '#?' ) );

		return $norm( $url ) === $norm( $here );
	}

	/**
	 * The Site Logo to draw, or [] for the design's own.
	 *
	 * The block editor previews a logo before it is saved by asking the
	 * block renderer with `dxai_logo`; honoured only in that REST request and
	 * only for someone who may change the logo.
	 *
	 * @return array{id?:int, url?:string, alt?:string}
	 */
	public static function logo(): array {
		$id = (int) get_theme_mod( 'custom_logo' );
		// The primary logo of Theme Global Settings, when the site has one: the theme draws it before the Site Logo (its site-branding part).
		$set = \DXAI_UI\Theme\Theme_Options::image_id( 'logos', 'primary_logo' );
		if ( $set > 0 ) {
			$id = $set;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only preview parameter, capability-checked.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && isset( $_GET['dxai_logo'] ) && current_user_can( 'edit_theme_options' ) ) {
			$id = absint( wp_unslash( $_GET['dxai_logo'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( $id < 1 || ! wp_attachment_is_image( $id ) ) {
			return array();
		}
		$url = wp_get_attachment_image_url( $id, 'full' );
		if ( ! is_string( $url ) || $url === '' ) {
			return array();
		}
		$alt = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );

		return array(
			'id'  => $id,
			'url' => $url,
			'alt' => $alt !== '' ? $alt : (string) get_bloginfo( 'name' ),
		);
	}

	/**
	 * Give the site the design's logo when it has none, and record which
	 * attachment that is — the renderer keeps the design's own <img> byte for
	 * byte while the Site Logo is still it.
	 *
	 * @param array<string, mixed> $spec
	 * @param array<string, mixed> $context
	 * @return array{attachment_id:int, src:string, set:bool}
	 */
	private static function install_logo( array $spec, array $context ): array {
		// The first logo that is an image (a wordmark is text).
		$src  = '';
		$kept = 0;
		foreach ( (array) ( $spec['logos'] ?? array() ) as $logo ) {
			if ( '' !== (string) ( $logo['src'] ?? '' ) ) {
				$src  = (string) $logo['src'];
				// A picture block names its attachment itself.
				$kept = (int) ( $logo['id'] ?? 0 );
				break;
			}
		}
		$id = (int) ( $context['logo_attachment_id'] ?? 0 );
		$id = $id > 0 ? $id : $kept;
		if ( $id < 1 && $src !== '' ) {
			$id = (int) attachment_url_to_postid( $src );
		}
		$set = false;
		// Only for the site's header (install()'s take_site): a one-page
		// import does not give the whole site its logo.
		$may = ! array_key_exists( 'set_site_logo', $context ) || ! empty( $context['set_site_logo'] );
		// A site with a logo already — the Site Logo, or the primary logo of Theme Global Settings — keeps it.
		if ( $may && $id > 0 && wp_attachment_is_image( $id ) && ! get_theme_mod( 'custom_logo' ) && \DXAI_UI\Theme\Theme_Options::image_id( 'logos', 'primary_logo' ) < 1 ) {
			/*
			 * Both stores: `site_logo` is what block themes and the Site
			 * Logo block read, the theme mod what a classic theme's
			 * the_custom_logo() reads. Core keeps them in step when it can
			 * (setting the mod updates the option), but only for the active
			 * theme's mods.
			 */
			set_theme_mod( 'custom_logo', $id );
			update_option( 'site_logo', $id );
			$set = true;
		}

		return array(
			'attachment_id' => $id,
			'src'           => $src,
			'set'           => $set,
		);
	}

	/**
	 * Show Description and CSS Classes in Appearance > Menus for anyone who
	 * never chose otherwise. The header reads both — the eyebrow above a
	 * button, a utility class on an item — and core hides them until a person
	 * finds Screen Options. A person who set their own columns keeps them.
	 *
	 * @param mixed      $hidden
	 * @param \WP_Screen $screen
	 * @return mixed
	 */
	public function show_item_fields( $hidden, $screen ) {
		if ( ! is_array( $hidden ) || ! $screen instanceof \WP_Screen || 'nav-menus' !== $screen->id ) {
			return $hidden;
		}
		if ( array_values( $hidden ) !== self::CORE_HIDDEN ) {
			return $hidden;
		}

		return array_values( array_diff( $hidden, array( 'description', 'css-classes' ) ) );
	}

	/**
	 * After a theme switch, give each header location its menu again when
	 * core did not map one over (the new theme's locations are its own theme
	 * mods). Runs after core's _wp_menus_changed().
	 */
	public function reassign(): void {
		$spec = Header_Template::stored();
		if ( null === $spec || empty( $spec['menus'] ) ) {
			return;
		}
		self::register_locations();
		$registered = get_registered_nav_menus();
		$locations  = get_theme_mod( 'nav_menu_locations', array() );
		$locations  = is_array( $locations ) ? $locations : array();
		$changed    = false;
		foreach ( (array) $spec['menus'] as $location => $id ) {
			if ( isset( $registered[ $location ] ) && empty( $locations[ $location ] ) && get_term( (int) $id, 'nav_menu' ) instanceof \WP_Term ) {
				$locations[ $location ] = (int) $id;
				$changed                = true;
			}
		}
		if ( $changed ) {
			set_theme_mod( 'nav_menu_locations', $locations );
		}
	}
}

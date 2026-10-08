<?php
/**
 * The pages a design's header menu names, as pages the team's panel can make at the menu's own addresses.
 *
 * @package DXAI_UI\Pages
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

use DXAI_UI\Chrome\Header_Template;

/**
 * A design's header is Appearance > Menus (Header_Menus): the menu of the site, with its dropdowns and the address of each page. It says
 * which pages the site has, what they are called and where they sit — more than the panel's detectors can read from a Home, which lists
 * its services as cards or as steps and its places in a sentence, while its menu names each page and gives its address. This reads
 * that menu into the pages Team_Pages can make, at the menu's own addresses, so that the menu opens them.
 *
 * What is read is the menus written for the design — the ones its header spec names (Header_Template::scoped()) — and not the menu a
 * theme location holds now, which may be another design's: a person's later edits to those menus in Appearance > Menus are the pages. A
 * design whose header is not installed as menus has none; the panel says so.
 *
 * Each item with an address of the site is a candidate. What kind of page it is, is told from the address (the first word of
 * /service/…, /service-area/…, and the single words /about/, /contact/, /faq/, /testimonials/), then from the dropdown it is in
 * ("Services", "Locations") and from its own words ("Revere, MA"). What the team's pages have no kind for (Careers, a policy), an
 * anchor on a page (#faq), a phone number, another site's address and a file are named and left out, each with why. The page above a
 * page that the menu does not name (/service/ over /service/water-damage/) is offered as the list of services or of places. Nothing here
 * makes a page or changes a menu.
 */
final class Menu_Pages {

	/** Words of an address (one segment of it) that say what the page is. */
	private const SEG_SERVICES = '/^(?:services?|our-services|what-we-do|solutions|treatments|specialt(?:y|ies)|practice-areas?|capabilities)$/';
	private const SEG_AREAS    = '/^(?:service-areas?|areas?|areas-we-serve|locations?|service-locations?|where-we-work|where-we-serve|communities|cities|towns|coverage|counties)$/';
	private const SEG_GENERAL  = array(
		'about'        => '/^(?:about|about-us|our-story|who-we-are|our-company|company|our-team|meet-the-team)$/',
		'contact'      => '/^(?:contact|contact-us|get-in-touch|request-(?:a-)?(?:quote|estimate|callback|service)|free-(?:estimate|quote)|get-(?:a-)?(?:quote|estimate))$/',
		'faq'          => '/^(?:faqs?|frequently-asked-questions|questions)$/',
		'testimonials' => '/^(?:testimonials|reviews|customer-reviews|client-reviews|what-our-customers-say)$/',
	);

	/** Words of a dropdown's own name (the top item the page sits under) that say what is under it. */
	private const GROUP_SERVICES = '/\bservices?\b|what we (?:do|offer|restore|treat)|solutions|treatments|specialt|practice areas|capabilit/i';
	private const GROUP_AREAS    = '/\blocations?\b|service areas?|\bareas?\b|where we (?:work|serve)|communit|cities|towns|coverage|counties/i';

	/** Words of an item (lower case, one space) that say what a general page is, when its address does not. */
	private const LABEL_GENERAL = array(
		'about'        => '/^(?:about(?: us)?|our story|who we are)$/',
		'contact'      => '/^(?:contact(?: us)?|get in touch|(?:get|request) an? (?:free )?(?:quote|estimate)|free (?:quote|estimate))$/',
		'faq'          => '/^(?:faqs?|frequently asked questions|questions)$/',
		'testimonials' => '/^(?:testimonials|reviews|customer reviews)$/',
	);

	/**
	 * What a design's header menu says about its pages, with what is there already.
	 *
	 * @param array{services?:array<int, string>, places?:array<int, string>}|null $hints What the Home calls its services and places, when the caller has read them already.
	 * @return array{source:string, menus:array<int, string>, pretty:bool, pages:array<int, array<string, mixed>>, lists:array<int, array<string, mixed>>, left_out:array<int, array<string, string>>}
	 */
	public static function for_design( int $home, ?array $hints = null ): array {
		$read = self::tree_of( $home );
		$out  = array(
			'source'   => $read['source'],
			'menus'    => $read['menus'],
			'pretty'   => (string) get_option( 'permalink_structure', '' ) !== '',
			'pages'    => array(),
			'lists'    => array(),
			'left_out' => array(),
		);
		if ( $read['tree'] === array() ) {
			return $out;
		}
		$ctx = array(
			'hosts'    => self::hosts(),
			'base'     => rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' ),
			'services' => $hints['services'] ?? Team_Pages::services_of_home( $home ),
			'places'   => $hints['places'] ?? Team_Pages::places_of_home( $home ),
		);
		$sorted = self::classify( $read['tree'], $ctx );
		$mark   = static function ( array $row ) use ( $home ): array {
			$id = Team_Pages::page_at( (string) $row['path'] );

			return $row + array(
				'exists' => $id,
				// Whether that page is one this design's panel made (a foreign page at the address is kept, not written over).
				'mine'   => $id > 0 && Team_Pages::team_page_at( $home, (string) $row['path'] ) === $id,
			);
		};
		$out['pages']    = array_map( $mark, $sorted['pages'] );
		$out['lists']    = array_map( $mark, $sorted['lists'] );
		$out['left_out'] = $sorted['left_out'];

		return $out;
	}

	/**
	 * The header menu of a design as a tree of items: from the menus written for it (Appearance > Menus, so a page a person adds
	 * there is one too), or — when the header was kept as a template part and no menu was written — from the navigation the
	 * design's document read out of its header (Design\Document, `regions.header.nav`; source `document`, no menu items to
	 * point anywhere).
	 *
	 * @param bool $document Whether the document may answer when there are no menus; the document's own builder asks with false.
	 * @return array{source:string, menus:array<int, string>, tree:array<int, array<string, mixed>>}
	 */
	public static function tree_of( int $home, bool $document = true ): array {
		$spec = Header_Template::scoped( $home );
		$out  = array(
			'source' => '',
			'menus'  => array(),
			'tree'   => array(),
		);
		$ids  = $spec === null ? array() : (array) ( $spec['menus'] ?? array() );
		foreach ( array( Header_Template::NAV, Header_Template::TOP, Header_Template::ACTIONS ) as $location ) {
			$menu = (int) ( $ids[ $location ] ?? 0 );
			$obj  = $menu > 0 ? wp_get_nav_menu_object( $menu ) : false;
			if ( ! $obj instanceof \WP_Term ) {
				continue;
			}
			$tree = self::menu_tree( $menu );
			if ( $tree !== array() ) {
				$out['tree']    = array_merge( $out['tree'], $tree );
				$out['menus'][] = html_entity_decode( (string) $obj->name, ENT_QUOTES, 'UTF-8' );
			}
		}
		if ( $out['tree'] !== array() ) {
			$out['source'] = 'menus';

			return $out;
		}
		if ( $document && $home > 0 ) {
			$doc = \DXAI_UI\Design\Document_Store::load( $home );
			$nav = $doc === null ? array() : (array) ( $doc->get( 'regions' )['header']['nav'] ?? array() );
			if ( $nav !== array() ) {
				$out['tree']   = self::document_tree( $nav );
				$out['source'] = 'document';
			}
		}

		return $out;
	}

	/**
	 * The document's navigation as this reader's tree: the same shape as a menu's, with no menu item behind the nodes.
	 *
	 * @param array<int, mixed> $nav
	 * @return array<int, array<string, mixed>>
	 */
	private static function document_tree( array $nav ): array {
		$out = array();
		foreach ( $nav as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$out[] = array(
				'item'        => 0,
				'label'       => (string) ( $node['label'] ?? '' ),
				'url'         => (string) ( $node['url'] ?? '' ),
				'description' => (string) ( $node['description'] ?? '' ),
				'children'    => self::document_tree( is_array( $node['children'] ?? null ) ? $node['children'] : array() ),
			);
		}

		return $out;
	}

	/** Whether a heading or a label speaks of a site's services (the words the menus use for that group). */
	public static function looks_like_services( string $text ): bool {
		return preg_match( self::GROUP_SERVICES, $text ) === 1;
	}

	/** Whether a heading or a label speaks of a site's places. */
	public static function looks_like_areas( string $text ): bool {
		return preg_match( self::GROUP_AREAS, $text ) === 1;
	}

	/**
	 * A menu's items as a tree: label, address, description, the item's id and its children.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function menu_tree( int $menu_id ): array {
		$items = wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'publish' ) );
		if ( ! is_array( $items ) ) {
			return array();
		}
		$kids = array();
		foreach ( $items as $item ) {
			$kids[ (int) $item->menu_item_parent ][] = $item;
		}
		$build = static function ( int $parent ) use ( &$build, $kids ): array {
			$out = array();
			foreach ( $kids[ $parent ] ?? array() as $item ) {
				$out[] = array(
					'item'        => (int) $item->ID,
					'label'       => (string) $item->title,
					'url'         => (string) $item->url,
					'description' => (string) $item->description,
					'children'    => $build( (int) $item->ID ),
				);
			}

			return $out;
		};

		return $build( 0 );
	}

	/**
	 * The hosts that are this site: its own, with and without www, and the ones a site says it also answers to.
	 *
	 * @return array<int, string>
	 */
	private static function hosts(): array {
		$host  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$hosts = array_filter( array( $host ) );
		/**
		 * Hosts a menu's address may have and still be a page of this site (the old address of a site that moved).
		 *
		 * @param array<int, string> $hosts
		 */
		$hosts = apply_filters( 'dxai_ui_menu_pages_hosts', $hosts );

		return array_values( array_unique( array_map( static fn( $h ) => (string) preg_replace( '/^www\./', '', strtolower( (string) $h ) ), (array) $hosts ) ) );
	}

	/**
	 * Sort the items of a menu into pages to make, list pages the addresses imply, and what is left out (with why).
	 *
	 * @param array<int, array<string, mixed>> $tree label, url, description, item, children.
	 * @param array<string, mixed>             $ctx  hosts (the site's), base (the folder the site is in), services and places (what the Home names: they help to tell a kind).
	 * @return array{pages:array<int, array<string, mixed>>, lists:array<int, array<string, mixed>>, left_out:array<int, array<string, string>>}
	 */
	public static function classify( array $tree, array $ctx = array() ): array {
		$hosts    = array_map( 'strval', (array) ( $ctx['hosts'] ?? array() ) );
		$base     = (string) ( $ctx['base'] ?? '' );
		$services = array_flip( array_map( array( self::class, 'norm' ), array_map( 'strval', (array) ( $ctx['services'] ?? array() ) ) ) );
		$places   = array_flip( array_map( array( self::class, 'norm' ), array_map( 'strval', (array) ( $ctx['places'] ?? array() ) ) ) );
		$pages    = array();
		$left     = array();
		$walk     = static function ( array $nodes, array $trail, string $group ) use ( &$walk, &$pages, &$left, $hosts, $base, $services, $places ): void {
			foreach ( $nodes as $node ) {
				$label    = self::clean( (string) ( $node['label'] ?? '' ) );
				$children = (array) ( $node['children'] ?? array() );
				$top      = $group !== '' ? $group : $label;
				$addr     = self::address( (string) ( $node['url'] ?? '' ), $hosts, $base );
				switch ( $addr['kind'] ) {
					case 'anchor':
						// A trigger of a dropdown is no page; a link to a place on a page is not one either.
						if ( $children === array() && $label !== '' ) {
							$left[] = array(
								'title' => $label,
								'url'   => '#' . $addr['fragment'],
								'why'   => 'anchor',
							);
						}
						break;
					case 'external':
						if ( $label !== '' ) {
							$left[] = array(
								'title' => $label,
								'url'   => (string) $node['url'],
								'why'   => 'external',
							);
						}
						break;
					case 'file':
						$left[] = array(
							'title' => $label,
							'url'   => $addr['path'],
							'why'   => 'file',
						);
						break;
					case 'page':
						if ( $addr['fragment'] !== '' ) {
							$left[] = array(
								'title' => $label,
								'url'   => $addr['path'] . '#' . $addr['fragment'],
								'why'   => 'anchor',
							);
							break;
						}
						$segments = explode( '/', trim( $addr['path'], '/' ) );
						$type     = self::kind_of( $segments, $label, $top, $services, $places );
						if ( $label === '' ) {
							break;
						}
						if ( $type === '' ) {
							$left[] = array(
								'title' => $label,
								'url'   => $addr['path'],
								'why'   => 'kind',
							);
							break;
						}
						if ( ! isset( $pages[ $addr['path'] ] ) ) {
							$pages[ $addr['path'] ] = array(
								'title'       => self::title_of( $label, $type, $segments ),
								'path'        => $addr['path'],
								'type'        => $type,
								'parent'      => self::parent_of( $addr['path'] ),
								'trail'       => implode( ' › ', $trail ),
								'description' => self::clean( (string) ( $node['description'] ?? '' ) ),
								'item'        => (int) ( $node['item'] ?? 0 ),
							);
						}
						break;
					default:
						// Nothing: a dropdown's trigger, a phone number, a button, the Home.
						break;
				}
				if ( $children !== array() ) {
					$walk( $children, array_merge( $trail, array( $label ) ), $top );
				}
			}
		};
		$walk( $tree, array(), '' );

		// The page above a page, when the menu names no page there: for services and places it is their list, whatever its address says
		// (/service/, /what-we-do/); for any other kind the page waits for a page there.
		$lists = array();
		foreach ( $pages as $page ) {
			$parent = (string) $page['parent'];
			if ( $parent === '' || isset( $pages[ $parent ] ) || isset( $lists[ $parent ] ) ) {
				continue;
			}
			$list = $page['type'] === 'service' ? 'services' : ( $page['type'] === 'location' ? 'areas' : '' );
			if ( $list !== '' && self::parent_of( $parent ) === '' ) {
				$lists[ $parent ] = array(
					'title'       => Team_Pages::KINDS[ $list ]['title'],
					'path'        => $parent,
					'type'        => $list,
					'parent'      => '',
					'trail'       => '',
					'description' => '',
					'item'        => 0,
					'implied'     => true,
				);
			}
		}
		// A page whose parent is neither in the menu nor a list page it can have is not offered: it would not sit where the menu says.
		$offered = array();
		foreach ( $pages as $path => $page ) {
			$parent = (string) $page['parent'];
			if ( $parent !== '' && ! isset( $pages[ $parent ] ) && ! isset( $lists[ $parent ] ) && Team_Pages::page_at( $parent ) < 1 ) {
				$left[] = array(
					'title' => (string) $page['title'],
					'url'   => (string) $path,
					'why'   => 'parent',
				);
				continue;
			}
			$offered[] = $page;
		}
		usort( $offered, static fn( $a, $b ) => strcmp( (string) $a['path'], (string) $b['path'] ) );
		$lists = array_values( $lists );
		usort( $lists, static fn( $a, $b ) => strcmp( (string) $a['path'], (string) $b['path'] ) );

		return array(
			'pages'    => $offered,
			'lists'    => $lists,
			'left_out' => $left,
		);
	}

	/**
	 * What a menu item's address is: a page of the site (its path), or a place on one, another site, a file, a phone number, nothing.
	 *
	 * @param array<int, string> $hosts The hosts that are this site.
	 * @param string             $base  The folder the site is in ('/site' for a site in a folder), taken off an address of the site.
	 * @return array{kind:string, path:string, fragment:string, host:string}
	 */
	public static function address( string $url, array $hosts, string $base = '' ): array {
		$none = array(
			'kind'     => 'none',
			'path'     => '',
			'fragment' => '',
			'host'     => '',
		);
		$url  = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( $url === '' || $url === '#' ) {
			return $none;
		}
		if ( $url[0] === '#' ) {
			return array( 'kind' => 'anchor' ) + array_merge( $none, array( 'fragment' => substr( $url, 1 ) ) );
		}
		if ( preg_match( '#^(?:tel|mailto|sms|callto|whatsapp|skype|javascript):#i', $url ) === 1 ) {
			return $none;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return $none;
		}
		$host = (string) preg_replace( '/^www\./', '', strtolower( (string) ( $parts['host'] ?? '' ) ) );
		if ( $host !== '' && ! in_array( $host, $hosts, true ) ) {
			return array( 'kind' => 'external' ) + array_merge( $none, array( 'host' => $host ) );
		}
		$path = '/' . trim( (string) ( $parts['path'] ?? '' ), '/' );
		if ( $host !== '' && $base !== '' && ( $path === $base || str_starts_with( $path, $base . '/' ) ) ) {
			$path = '/' . trim( substr( $path, strlen( $base ) ), '/' );
		}
		$fragment = (string) ( $parts['fragment'] ?? '' );
		if ( $path === '/' ) {
			// The Home, or a place on it.
			return $fragment !== '' ? array( 'kind' => 'anchor' ) + array_merge( $none, array( 'fragment' => $fragment ) ) : $none;
		}
		$clean = Team_Pages::clean_path( $path );
		if ( $clean === '' ) {
			return $none;
		}
		if ( preg_match( '#\.[a-z0-9]{2,5}$#i', rtrim( $path, '/' ) ) === 1 ) {
			return array( 'kind' => 'file' ) + array_merge( $none, array( 'path' => $clean ) );
		}

		return array( 'kind' => 'page' ) + array_merge( $none, array( 'path' => $clean, 'fragment' => $fragment ) );
	}

	/**
	 * The kind of page an item is: from its address, the dropdown it is in, its words.
	 *
	 * @param array<int, string> $segments The words of its address.
	 * @param array<string, int> $services What the Home's services are called (lower case) as keys.
	 * @param array<string, int> $places   The same for its places.
	 */
	private static function kind_of( array $segments, string $label, string $group, array $services, array $places ): string {
		$first = (string) ( $segments[0] ?? '' );
		$count = count( $segments );
		if ( $count === 1 ) {
			foreach ( self::SEG_GENERAL as $type => $re ) {
				if ( preg_match( $re, $first ) === 1 ) {
					return $type;
				}
			}
			if ( preg_match( self::SEG_SERVICES, $first ) === 1 ) {
				return 'services';
			}
			if ( preg_match( self::SEG_AREAS, $first ) === 1 ) {
				return 'areas';
			}
		} elseif ( $count > 1 ) {
			if ( preg_match( self::SEG_SERVICES, $first ) === 1 ) {
				return 'service';
			}
			if ( preg_match( self::SEG_AREAS, $first ) === 1 ) {
				return 'location';
			}
		}
		if ( $group !== '' ) {
			if ( preg_match( self::GROUP_SERVICES, $group ) === 1 ) {
				return 'service';
			}
			if ( preg_match( self::GROUP_AREAS, $group ) === 1 ) {
				return 'location';
			}
		}
		$words = self::norm( $label );
		if ( Team_Pages::is_place( $label ) || isset( $places[ $words ] ) ) {
			return 'location';
		}
		if ( isset( $services[ $words ] ) ) {
			return 'service';
		}
		foreach ( self::LABEL_GENERAL as $type => $re ) {
			if ( preg_match( $re, $words ) === 1 ) {
				return $type;
			}
		}

		return '';
	}

	/**
	 * What the page is called: the item's words; a place with its state in its address and not in its words ("College Place" over
	 * /service-area/college-place-wa/) is given it, "College Place, WA", as the places the panel makes are written.
	 *
	 * @param array<int, string> $segments
	 */
	private static function title_of( string $label, string $type, array $segments ): string {
		if ( $type === 'location' && ! Team_Pages::is_place( $label ) && ! str_contains( $label, ',' ) && preg_match( '/-([a-z]{2})$/', (string) end( $segments ), $m ) === 1 ) {
			$with = $label . ', ' . strtoupper( $m[1] );
			if ( Team_Pages::is_place( $with ) ) {
				return $with;
			}
		}

		return $label;
	}

	/** The address above an address ('/service/x/' → '/service/', '/x/' → ''). */
	public static function parent_of( string $path ): string {
		$segments = explode( '/', trim( $path, '/' ) );
		array_pop( $segments );

		return $segments === array() ? '' : '/' . implode( '/', $segments ) . '/';
	}

	/** An item's words as people read them: no tags, no entities, one space, no arrow of a dropdown. */
	private static function clean( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( '▼', '▾', '▲', '▴', '›', '»', '↓' ), ' ', $text );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/** Words compared: lower case, one space. */
	private static function norm( string $text ): string {
		return mb_strtolower( self::clean( $text ) );
	}
}

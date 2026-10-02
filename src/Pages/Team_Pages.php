<?php
/**
 * New pages of a design, made the way the team makes theirs, in the look of the design's Home.
 *
 * @package DXAI_UI\Pages
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Theme\Blank_Template;

/**
 * The team builds the pages of a site (a service, a place, About, Contact, the questions, the reviews) by hand, from
 * the same handful of sections, in an order that belongs to the site (Page_Recipes). This does the same, without
 * the old site: for each page wanted, the order of its sections is worked out from what the team's sites do
 * (Page_Recipes::pick), and each section is the Home's own section of that kind — so the page is in the Home's look
 * by construction, not an imitation of it — with the page's title for its heading, and the other pages of the site
 * in the cards that list them.
 *
 * The words of those sections are the Home's until they are written for the page (Copy_Prompt / Copy_Writer); the
 * plan can be seen before anything is made, and a page made again (same kind, same title) is updated in place unless
 * a person edited it since.
 */
final class Team_Pages {

	/** On each page: "<kind>|<slug>" of the page it is. */
	public const META = '_dxai_ui_team_page';

	/** On each page: the hash of the content this wrote (a later edit is not overwritten). */
	public const HASH = '_dxai_ui_team_hash';

	/**
	 * The key every page of an import has (archive#slug). A page made here has one too, "team#<home>#<kind>|<slug>", so that
	 * an import of another design with the same address (/contact-us) is told it is not free to take.
	 */
	public const KEY_META = '_dxai_ui_page_key';

	/** On each page: how each of its sections was varied (Section_Variants), one list of names per section, in page order. */
	public const OPS_META = '_dxai_ui_team_ops';

	/** Option: the version of the key stamping (upgrade()). */
	public const KEYS_DONE = 'dxai_ui_team_keys_done';

	/**
	 * The kinds of page that can be made, with the model they follow and the title a general page gets.
	 *
	 * @var array<string, array{label:string, model:string, title:string}>
	 */
	public const KINDS = array(
		'service'      => array( 'label' => 'Service', 'model' => 'service', 'title' => '' ),
		'location'     => array( 'label' => 'Location', 'model' => 'location', 'title' => '' ),
		'about'        => array( 'label' => 'About', 'model' => 'about', 'title' => 'About Us' ),
		'contact'      => array( 'label' => 'Contact', 'model' => 'contact', 'title' => 'Contact Us' ),
		'faq'          => array( 'label' => 'FAQ', 'model' => 'faq', 'title' => 'Frequently Asked Questions' ),
		'testimonials' => array( 'label' => 'Reviews', 'model' => 'testimonials', 'title' => 'Testimonials' ),
		'services'     => array( 'label' => 'Services list', 'model' => '', 'title' => 'Services' ),
		'areas'        => array( 'label' => 'Areas list', 'model' => '', 'title' => 'Service Areas' ),
	);

	/** The Home's section roles a recipe's role may be taken from when the Home has none of its own, in order. */
	private const FALLBACK = array(
		'process' => array( 'cards', 'two-col' ),
		'two-col' => array( 'text', 'content', 'cards' ),
		'cards'   => array( 'content', 'related', 'two-col', 'text' ),
		'related' => array( 'cards', 'content' ),
		'areas'   => array( 'cards', 'content', 'text' ),
		'content' => array( 'text', 'two-col', 'cards' ),
		'text'    => array( 'content', 'two-col', 'cards' ),
		'trust'   => array(),
		'reviews' => array(),
		'faq'     => array(),
		'cta'     => array(),
		'form'    => array(),
	);

	/**
	 * What a design can be asked for, before anything is chosen: the services its Home lists, and the general pages.
	 *
	 * @return array{services:array<int, string>, locations:array<int, string>, phrase:string, general:array<int, array{type:string, title:string}>}
	 */
	public static function suggestions( int $home ): array {
		$general = array();
		foreach ( array( 'about', 'contact', 'faq', 'testimonials', 'services', 'areas' ) as $type ) {
			$general[] = array(
				'type'  => $type,
				'title' => self::KINDS[ $type ]['title'],
			);
		}

		$services = self::services_of_home( $home );

		return array(
			'services'  => $services,
			'locations' => self::places_of_home( $home ),
			'phrase'    => $services[0] ?? '',
			'general'   => $general,
		);
	}

	/**
	 * The recipe and the sections each wanted page would get, without making anything.
	 *
	 * @param array<int, array{type:string, title:string}> $wanted
	 * @return array<int, array<string, mixed>>
	 */
	public static function plan( int $home, array $wanted ): array {
		$seed    = self::seed( $home );
		$library = Section_Library::for_page( $home );
		$roles   = self::home_roles( $library );
		$kit     = Home_Kit::of( $library );
		$reach   = count( Section_Blueprints::contact_rows( Home_Kit::facts( $home ) ) ) >= 2;
		$out     = array();
		foreach ( self::clean_wanted( $wanted ) as $item ) {
			$recipe = self::recipe( $item['type'], $seed, $roles, $item['slug'] );
			$parts  = array();
			$list   = $recipe['roles'];
			// A page without a list of the site's other pages gets one (compose()), as long as the Home has cards to make it in.
			if ( $kit['exemplars'] !== array() && ! in_array( 'related', $list, true ) ) {
				array_splice( $list, self::before_tail( $list ), 0, array( 'related' ) );
			}
			// …and the contact page the ways to reach the company, when the Home states two or more, after its form (or its hero).
			if ( $item['type'] === 'contact' && $reach && $kit['exemplars'] !== array() ) {
				$form = array_search( 'form', $list, true );
				$hero = array_search( 'hero', $list, true );
				array_splice( $list, $form !== false ? (int) $form + 1 : ( $hero !== false ? (int) $hero + 1 : 0 ), 0, array( 'contact' ) );
			}
			foreach ( $list as $role ) {
				// The pages of the site as cards are made in the Home's cards (Section_Blueprints), whether it has such a section or not.
				$source = $role === 'contact' || self::pool( $library, $roles, $role ) === array() ? ( in_array( $role, array( 'related', 'contact' ), true ) && $kit['exemplars'] !== array() ? 'new' : '' ) : 'home';
				$parts[] = array(
					'role'   => $role,
					'source' => $source,
				);
			}
			$existing = self::existing( $home, $item['type'], $item['slug'] );
			$out[]    = array(
				'type'      => $item['type'],
				'title'     => $item['title'],
				'slug'      => $item['slug'],
				'roles'     => $parts,
				'left_out'  => array_values( array_map( static fn( $p ) => $p['role'], array_filter( $parts, static fn( $p ) => $p['source'] === '' ) ) ),
				'edits'     => $recipe['edits'],
				'nearest'   => $recipe['nearest'],
				'exists'    => $existing > 0 ? $existing : 0,
				'edited'    => $existing > 0 && self::edited( $existing ),
			);
		}

		return $out;
	}

	/**
	 * Make the pages. A page that is there already is updated in place, unless a person edited it (then it is kept,
	 * and named in `kept`).
	 *
	 * @param array<int, array{type:string, title:string}> $wanted
	 * @return array{pages:array<int, array<string, mixed>>, kept:array<int, array<string, mixed>>, log:array<int, string>, menu:array{pages:int, links:int}, chrome:int}|\WP_Error
	 */
	public static function build( int $home, array $wanted, bool $force = false ) {
		if ( $home < 1 || ! \DXAI_UI\Structures\Design_Attach::is_design( $home ) ) {
			return new \WP_Error( 'dxai_ui_team_design', __( 'That is not an imported design.', 'dxai-ui' ), array( 'status' => 404 ) );
		}
		$items = self::clean_wanted( $wanted );
		// The pages that list others first, so the pages they list can sit under them.
		usort( $items, static fn( $x, $y ) => (int) in_array( $y['type'], array( 'services', 'areas' ), true ) <=> (int) in_array( $x['type'], array( 'services', 'areas' ), true ) );
		if ( $items === array() ) {
			return new \WP_Error( 'dxai_ui_team_none', __( 'Nothing was asked for.', 'dxai-ui' ), array( 'status' => 400 ) );
		}
		$library = Section_Library::for_page( $home );
		if ( $library === array() ) {
			return new \WP_Error( 'dxai_ui_team_library', __( 'The Home has no sections to build the pages from.', 'dxai-ui' ), array( 'status' => 422 ) );
		}
		$seed  = self::seed( $home );
		$roles = self::home_roles( $library );
		$log   = array();
		// What a section can be varied with: the Home's other pictures, and the classes the design styles by place.
		$vary  = array(
			'pool'       => Section_Variants::pool( $library ),
			'positional' => Section_Variants::positional_classes( $home ),
			'kit'        => Home_Kit::of( $library ),
			'facts'      => Home_Kit::facts( $home ),
		);

		// First every page exists, so the pages can link to each other.
		$ids  = array();
		$kept = array();
		$pend = array();
		foreach ( $items as $item ) {
			$id = self::existing( $home, $item['type'], $item['slug'] );
			if ( $id > 0 && ! $force && self::edited( $id ) ) {
				$kept[]  = array(
					'id'    => $id,
					'title' => $item['title'],
				);
				$ids[ $item['type'] . '|' . $item['slug'] ] = $id;
				continue;
			}
			if ( $id < 1 ) {
				$id = self::shell( $home, $item, $items );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
			}
			$ids[ $item['type'] . '|' . $item['slug'] ] = (int) $id;
			$pend[]                                      = $item;
		}

		$links = array();
		foreach ( $items as $item ) {
			$links[ $item['type'] . '|' . $item['slug'] ] = array(
				'title' => $item['title'],
				'url'   => (string) get_permalink( $ids[ $item['type'] . '|' . $item['slug'] ] ),
				'type'  => $item['type'],
			);
		}

		$done = array();
		foreach ( $pend as $item ) {
			$key    = $item['type'] . '|' . $item['slug'];
			$id     = $ids[ $key ];
			$recipe = self::recipe( $item['type'], $seed, $roles, $item['slug'] );
			$siblings = array_values( array_filter( $links, static fn( $l, $k ) => $k !== $key && $l['type'] === 'service', ARRAY_FILTER_USE_BOTH ) );
			$built  = self::compose( $library, $roles, $recipe['roles'], $item, $siblings, $seed . '|' . $key, array_merge( $vary, self::related_rows( $key, $links, (array) $vary['kit']['texts'] ) ) );
			$wrote = self::write( $id, $home, $built['sections'] );
			if ( is_wp_error( $wrote ) ) {
				$log[] = sprintf( '%s: the page did not round-trip (%s) — not written', $item['title'], $wrote->get_error_message() );
				continue;
			}
			update_post_meta( $id, self::OPS_META, wp_json_encode( $built['ops'] ) );
			$log   = array_merge( $log, array_map( static fn( $l ) => $item['title'] . ': ' . $l, $built['log'] ) );
			$done[] = array(
				'id'      => $id,
				'title'   => $item['title'],
				'type'    => $item['type'],
				'roles'   => $recipe['roles'],
				'sections' => count( $built['sections'] ),
				'edit'    => (string) get_edit_post_link( $id, 'raw' ),
				'view'    => (string) get_permalink( $id ),
			);
		}

		// The pages are in the design's menu: its header and footer links lead to them.
		$menu = Team_Menu::link( $home );
		// … and every page wears the Home's header and footer as the Home has them now, the menu included.
		$chrome = Team_Chrome::sync( $home );

		return array(
			'pages'  => $done,
			'kept'   => $kept,
			'log'    => $log,
			'menu'   => $menu,
			'chrome' => $chrome['pages'],
		);
	}

	/**
	 * The titles of the services the Home lists: the cards of the set whose section is headed like a list of services
	 * ("Restoration Services in …", "What we do"), else the largest set of cards that are not places.
	 *
	 * @return array<int, string>
	 */
	public static function services_of_home( int $home ): array {
		$best  = array();
		$score = -1.0;
		foreach ( Section_Library::for_page( $home ) as $component ) {
			$heading = '';
			foreach ( (array) $component['slots'] as $slot ) {
				if ( $slot['type'] === 'heading' ) {
					$heading = (string) $slot['text'];
					break;
				}
			}
			foreach ( (array) $component['repeats'] as $rep ) {
				if ( count( (array) $rep['items'] ) < 3 || ( $rep['kind'] ?? '' ) !== 'card' ) {
					continue;
				}
				$titles = array_values( array_unique( array_filter( self::item_titles( $component['block'], $rep ), static fn( $t ) => $t !== '' && mb_strlen( $t ) <= 60 ) ) );
				if ( count( $titles ) < 3 || count( array_filter( $titles, array( self::class, 'is_place' ) ) ) >= 2 ) {
					continue;
				}
				$s = ( preg_match( '/\bservices?\b|what we (?:do|offer|restore|treat)|solutions|treatments|specialt|capabilit|practice areas/i', $heading ) === 1 ? 10 : 0 ) + count( $titles ) / 10;
				if ( $s > $score ) {
					$score = $s;
					$best  = $titles;
				}
			}
		}

		return array_slice( $best, 0, 12 );
	}

	/**
	 * The places the Home names: the cards (or office entries) whose headings are "City, ST".
	 *
	 * @return array<int, string>
	 */
	public static function places_of_home( int $home ): array {
		$places = array();
		foreach ( Section_Library::for_page( $home ) as $component ) {
			foreach ( (array) $component['repeats'] as $rep ) {
				if ( count( (array) $rep['items'] ) < 2 || ! in_array( (string) ( $rep['kind'] ?? '' ), array( 'card', 'contact' ), true ) ) {
					continue;
				}
				$found = array_filter( self::item_titles( $component['block'], $rep ), array( self::class, 'is_place' ) );
				if ( count( $found ) >= 2 ) {
					$places = array_merge( $places, $found );
				}
			}
		}

		return array_slice( array_values( array_unique( $places ) ), 0, 12 );
	}

	/** Whether a heading is a place: "City, ST". */
	public static function is_place( string $title ): bool {
		return preg_match( '/^[A-Z][A-Za-z .\'\-]+,\s*[A-Z]{2}$/', trim( $title ) ) === 1;
	}


	/* ------------------------------------------------------------------ recipe */

	/**
	 * @return array{roles:array<int, string>, edits:array<int, string>, nearest:int}
	 */
	private static function recipe( string $type, string $seed, array $home_roles = array(), string $variant = '' ): array {
		$model = self::KINDS[ $type ]['model'] ?? '';
		if ( $model === '' ) {
			// A list of the pages under it: what the page opens with, the list, what people say, the call to action. The list of
			// service areas is the Home's own areas (its offices and the towns they serve) when it has them: cards of services are
			// not what that page is about.
			return array(
				'roles'   => $type === 'areas' && ! empty( $home_roles['areas'] ) ? array( 'hero', 'areas', 'reviews', 'cta' ) : array( 'hero', 'related', 'reviews', 'cta' ),
				'edits'   => array(),
				'nearest' => 0,
			);
		}
		$r = Page_Recipes::pick( $model, $seed, $variant );

		return array(
			'roles'   => self::must_have( $type, $r['roles'], $home_roles ),
			'edits'   => $r['edits'],
			'nearest' => $r['nearest'],
		);
	}

	/**
	 * A page that is not what its name says without one section has it, when the Home has one: the questions of the FAQ
	 * page (the team's own FAQ pages often hold them in the hero, which a Home's hero does not), the form of the contact
	 * page. The questions go before the closing call to action, the form right after the hero.
	 *
	 * @param array<int, string>             $roles
	 * @param array<string, array<int, int>> $home_roles
	 * @return array<int, string>
	 */
	private static function must_have( string $type, array $roles, array $home_roles ): array {
		$need = array( 'faq' => 'faq', 'contact' => 'form' )[ $type ] ?? '';
		if ( $need === '' || in_array( $need, $roles, true ) || empty( $home_roles[ $need ] ) ) {
			return $roles;
		}
		$roles = array_values( $roles );
		if ( $need === 'faq' ) {
			$at = array_search( 'cta', array_reverse( $roles, true ), true );
			array_splice( $roles, $at === false ? count( $roles ) : (int) $at, 0, array( 'faq' ) );

			return $roles;
		}
		$at = array_search( 'hero', $roles, true );
		array_splice( $roles, $at === false ? 0 : (int) $at + 1, 0, array( 'form' ) );

		return $roles;
	}

	/** What decides a design's recipes: the name of its Home, the same on every host. */
	private static function seed( int $home ): string {
		return trim( html_entity_decode( wp_strip_all_tags( get_the_title( $home ) ), ENT_QUOTES, 'UTF-8' ) );
	}

	/* ---------------------------------------------------------------- sections */

	/**
	 * The Home's sections by the role they play, in order.
	 *
	 * @param array<int, array<string, mixed>> $library
	 * @return array<string, array<int, int>> role => indexes into $library
	 */
	private static function home_roles( array $library ): array {
		$by = array();
		foreach ( array_values( $library ) as $i => $component ) {
			$by[ Section_Roles::of( $component['block'], $i === 0 ) ][] = $i;
		}

		return $by;
	}

	/**
	 * The Home's sections a role may be taken from: its own, then those of the roles that stand in for it. Each is
	 * given a rank (0 its own, 1 a stand-in) so a page that asks for a role more often than the Home has it does
	 * not repeat the same section.
	 *
	 * @param array<string, array<int, int>> $roles
	 * @return array<int, int> Indexes into the library => rank, as a map.
	 */
	private static function candidates( array $roles, string $role ): array {
		$out = array();
		foreach ( $roles[ $role ] ?? array() as $i ) {
			$out[ $i ] = 0;
		}
		foreach ( self::FALLBACK[ $role ] ?? array() as $other ) {
			foreach ( $roles[ $other ] ?? array() as $i ) {
				if ( ! isset( $out[ $i ] ) ) {
					$out[ $i ] = 1;
				}
			}
		}

		return $out;
	}

	/**
	 * The Home's sections a role may be taken from (candidates()). For the hero, when the Home has none, its first section
	 * if that has a heading to carry the title: a page without a hero shows its title nowhere (a job page's Home is a
	 * route whose heading is the job's, and its header band is in the chrome).
	 *
	 * @param array<int, array<string, mixed>> $library
	 * @param array<string, array<int, int>>   $roles
	 * @return array<int, int> Indexes into the library => rank.
	 */
	private static function pool( array $library, array $roles, string $role ): array {
		$found = self::candidates( $roles, $role );
		if ( $found !== array() ) {
			return $found;
		}
		$library = array_values( $library );
		// A Home with no reviews of its own that says it is rated (a bar of badges with "4.9 from 1,240 reviews") has that to show.
		if ( $role === 'reviews' ) {
			foreach ( $roles['trust'] ?? array() as $i ) {
				if ( isset( $library[ $i ]['block'] ) && preg_match( '/\breviews?\b|\brated\b|\bratings?\b|\x{2605}|\bstars?\b/iu', wp_strip_all_tags( serialize_block( (array) $library[ $i ]['block'] ) ) ) === 1 ) {
					$found[ $i ] = 1;
				}
			}

			return $found;
		}
		if ( $role !== 'hero' ) {
			return $found;
		}

		return isset( $library[0]['block'] ) && is_array( $library[0]['block'] ) && self::has_title_slot( $library[0]['block'] ) ? array( 0 => 1 ) : array();
	}

	/** Whether a block holds the heading title_hero() writes the title in. */
	private static function has_title_slot( array $block ): bool {
		if ( ( $block['blockName'] ?? '' ) === 'core/heading' && (int) ( $block['attrs']['level'] ?? 2 ) <= 2 ) {
			return true;
		}
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $child ) {
			if ( is_array( $child ) && self::has_title_slot( $child ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The sections of one page.
	 *
	 * @param array<int, array<string, mixed>>             $library
	 * @param array<string, array<int, int>>               $roles
	 * @param array<int, string>                           $recipe
	 * @param array{type:string, title:string, slug:string} $item
	 * @param array<int, array{title:string, url:string, type:string}> $siblings
	 * @param array{pool?:array<int, array<string, mixed>>, positional?:array<string, true>|null, kit?:array<string, mixed>, facts?:array<string, mixed>, rows?:array<int, array<string, string>>, heading?:string} $vary What a section can be varied with (Section_Variants), the kit of the Home (Home_Kit), and the pages this one links (related_rows()).
	 * @return array{sections:array<int, array<string, mixed>>, log:array<int, string>, ops:array<int, array<int, string>>}
	 */
	private static function compose( array $library, array $roles, array $recipe, array $item, array $siblings, string $seed, array $vary = array() ): array {
		$library  = array_values( $library );
		$used     = array();
		$sections = array();
		$log      = array();
		$ops      = array();
		$pictures = array();
		$topic    = self::topic( $item['title'] );
		$prev     = -1; // the Home section the page showed last
		// The other pages of the site as cards: the Home's cards with a card for each of them. Made before the other sections are
		// chosen, so the section it is poured into is not also shown as itself: the page lists the site's pages once.
		$recipe  = array_values( $recipe );
		// The ways to reach the company, as the Home states them, in the Home's cards: on the contact page, after its form (or its hero).
		$contact = null;
		$wanted  = array();
		foreach ( $recipe as $other ) {
			$wanted = array_merge( $wanted, array_keys( array_filter( self::candidates( $roles, (string) $other ), static fn( $rank ) => $rank === 0 ) ) );
		}
		$wanted = array_values( array_unique( $wanted ) );
		if ( $item['type'] === 'contact' && isset( $vary['kit'], $vary['facts'] ) ) {
			$rows = Section_Blueprints::contact_rows( (array) $vary['facts'] );
			if ( count( $rows ) >= 2 ) {
				$refused = array();
				$contact = Section_Blueprints::contact( (array) $vary['kit'], $library, $rows, __( 'Get in touch', 'dxai-ui' ), $seed . '|contact', $refused, $wanted );
				foreach ( $refused as $why ) {
					$log[] = 'contact: not made from ' . $why;
				}
				if ( $contact !== null ) {
					$form = array_search( 'form', $recipe, true );
					$hero = array_search( 'hero', $recipe, true );
					array_splice( $recipe, $form !== false ? (int) $form + 1 : ( $hero !== false ? (int) $hero + 1 : 0 ), 0, array( 'contact' ) );
					$used[ $contact['index'] ] = ( $used[ $contact['index'] ] ?? 0 ) + 1;
				}
			}
		}
		$related = null;
		$at      = array_search( 'related', $recipe, true );
		$added   = false;
		if ( $at === false && isset( $vary['kit'], $vary['rows'] ) && count( (array) $vary['rows'] ) >= 2 ) {
			// The team's pages end with the site's other pages, then the questions, then the call to action: this page does not
			// have them, and the Home does not need a section of its own for it (the other pages are the Home's cards).
			$at    = self::before_tail( $recipe );
			$added = true;
			array_splice( $recipe, $at, 0, array( 'related' ) );
		}
		if ( $at !== false && isset( $vary['kit'], $vary['rows'] ) && count( (array) $vary['rows'] ) >= 2 ) {
			$refused = array();
			// The Home's sections the page's other sections are taken from are left alone while another one will do.
			$others = array();
			foreach ( $recipe as $other ) {
				if ( ! in_array( $other, array( 'related', 'contact' ), true ) ) {
					$others = array_merge( $others, array_keys( array_filter( self::candidates( $roles, (string) $other ), static fn( $rank ) => $rank === 0 ) ) );
				}
			}
			$related = Section_Blueprints::related( (array) $vary['kit'], $library, (array) $vary['rows'], (string) $vary['heading'], $seed . '|related', $refused, array_values( array_unique( array_merge( $others, $contact !== null ? array( $contact['index'] ) : array() ) ) ) );
			foreach ( $refused as $why ) {
				$log[] = 'related: not made from ' . $why;
			}
			if ( $related !== null ) {
				$used[ $related['index'] ] = ( $used[ $related['index'] ] ?? 0 ) + 1;
			} elseif ( $added ) {
				// It was not asked for, and cannot be made: the page is as its recipe says.
				array_splice( $recipe, (int) $at, 1 );
			}
		}
		foreach ( $recipe as $place => $role ) {
			if ( $contact !== null && $role === 'contact' ) {
				$ops[]      = array( $contact['op'] );
				$sections[] = $contact['block'];
				$log[]      = sprintf( 'contact ← the cards of Home section %d, with what the Home says about reaching it', $contact['index'] + 1 );
				$prev       = -1;
				continue;
			}
			if ( $related !== null && $place === $at ) {
				$ops[]      = array( $related['op'] );
				$sections[] = $related['block'];
				$log[]      = sprintf( 'related ← the cards of Home section %d, with the pages of the site', $related['index'] + 1 );
				$prev       = -1;
				continue;
			}
			$cand = self::pool( $library, $roles, (string) $role );
			// A section with a control (a "show more" button) or a widget is not used twice: its script finds it by its id, which a second
			// copy does not have, so the copy would show less than the Home's does.
			foreach ( array_keys( $cand ) as $i ) {
				if ( ( $used[ $i ] ?? 0 ) > 0 && ( (array) $library[ $i ]['controls'] !== array() || (array) $library[ $i ]['widgets'] !== array() ) ) {
					unset( $cand[ $i ] );
				}
			}
			if ( $cand === array() ) {
				$log[] = sprintf( '%s: the Home has none — left out', $role );
				continue;
			}
			// A section the page has is not shown again (a call to action may be: it closes more than one stretch of a page).
			// …and never twice in a row.
			$cand = array_filter( $cand, static fn( $i ) => ( $used[ $i ] ?? 0 ) < ( $role === 'cta' ? 2 : 1 ) && $i !== $prev, ARRAY_FILTER_USE_KEY );
			if ( $cand === array() ) {
				$log[] = sprintf( '%s: the Home has no other section for it — left out', $role );
				continue;
			}
			// The one used least so far, a section of the role itself before a stand-in; among equals, a turn the seed decides.
			$cost = static fn( $i ) => ( $used[ $i ] ?? 0 ) * 10 + $cand[ $i ];
			$low  = min( array_map( $cost, array_keys( $cand ) ) );
			$least = array_values( array_filter( array_keys( $cand ), static fn( $i ) => $cost( $i ) === $low ) );
			// Of equals, the one that talks about what the page is about (a page for water damage takes the section that says water).
			if ( count( $least ) > 1 && $topic !== array() ) {
				$near = array_map( static fn( $i ) => self::affinity( $library[ $i ]['block'], $topic ), $least );
				$best = max( $near );
				if ( $best > 0 ) {
					$least = array_values( array_intersect_key( $least, array_filter( $near, static fn( $n ) => $n === $best ) ) );
				}
			}
			$pick  = $least[ (int) ( sprintf( '%u', crc32( $seed . '|' . $role . '|' . $place ) ) % count( $least ) ) ];
			$prev  = $pick;
			$component = $library[ $pick ];
			$block     = $component['block'];
			if ( ( $used[ $pick ] ?? 0 ) > 0 ) {
				// Two of the same section on a page: the second does not repeat the first one's anchor.
				Block_Tree::strip_anchors( $block );
			}
			$used[ $pick ] = ( $used[ $pick ] ?? 0 ) + 1;
			// The section, shown another way from what the Home's other pages have (the cards that list pages are filled below).
			$done = array();
			if ( $role !== 'related' && isset( $vary['pool'] ) ) {
				$v        = Section_Variants::apply(
					array_merge( $component, array( 'block' => $block ) ),
					array(
						'seed'       => $seed . '|' . $place . '|' . $pick,
						'positional' => $vary['positional'] ?? null,
						'pool'       => $vary['pool'],
						'used'       => $pictures,
						'index'      => $pick,
						'role'       => (string) $role,
					)
				);
				$block    = $v['block'];
				$done     = $v['ops'];
				$pictures = array_merge( $pictures, $v['images'] );
			}
			$ops[] = $done;
			if ( $role === 'hero' ) {
				self::title_hero( $block, $item['title'] );
			}
			if ( $role === 'related' && $siblings !== array() ) {
				$block = self::fill_related( $block, $component, $siblings, $item['title'], (array) ( $vary['kit']['texts'] ?? array() ) );
			}
			$sections[] = $block;
			$log[]      = sprintf( '%s ← Home section %d (%s)%s', $role, $pick + 1, $component['kind'], $done === array() ? '' : ' — ' . implode( ', ', $done ) );
		}

		return array(
			'sections' => $sections,
			'log'      => $log,
			'ops'      => $ops,
		);
	}

	/**
	 * Where the closing questions and call to action of a recipe begin: the place for the list of the site's other pages.
	 *
	 * @param array<int, string> $recipe
	 */
	private static function before_tail( array $recipe ): int {
		$at = count( $recipe );
		while ( $at > 1 && in_array( $recipe[ $at - 1 ], array( 'faq', 'cta' ), true ) ) {
			--$at;
		}

		return $at;
	}

	/**
	 * The pages a page links to as "related", and what the list is called: the other services (a short list is made up to a row with
	 * the page that lists the rest and the About page), the places for the page that lists them; each with the words the Home has
	 * for a card of that title.
	 *
	 * @param array<string, array{title:string, url:string, type:string}> $links All the pages made together, by key.
	 * @param array<string, string>                                       $texts The Home's card texts by title (Home_Kit).
	 * @return array{rows:array<int, array{title:string, text:string, url:string}>, heading:string}
	 */
	private static function related_rows( string $key, array $links, array $texts ): array {
		$type = (string) ( $links[ $key ]['type'] ?? '' );
		$of   = static fn( string $t ): array => array_values( array_filter( $links, static fn( $l, $k ) => $k !== $key && $l['type'] === $t, ARRAY_FILTER_USE_BOTH ) );
		if ( $type === 'areas' ) {
			$pages   = $of( 'location' );
			$heading = __( 'Where we work', 'dxai-ui' );
		} else {
			$pages = $of( 'service' );
			if ( $type !== 'services' ) {
				foreach ( array( 'services', 'about' ) as $t ) {
					if ( count( $pages ) < 3 ) {
						$pages = array_merge( $pages, $of( $t ) );
					}
				}
			}
			$heading = $type === 'service' ? __( 'Our other services', 'dxai-ui' ) : __( 'Our services', 'dxai-ui' );
		}
		$rows = array();
		foreach ( $pages as $p ) {
			$rows[] = array(
				'title' => $p['title'],
				'text'  => (string) ( $texts[ mb_strtolower( $p['title'] ) ] ?? '' ),
				'url'   => $p['url'],
			);
		}

		return array(
			'rows'    => $rows,
			'heading' => $heading,
		);
	}

	/**
	 * The words a page is about: the words of its title that name something (not "restoration", "services").
	 *
	 * @return array<int, string>
	 */
	private static function topic( string $title ): array {
		$skip = array( 'restoration', 'service', 'services', 'about', 'contact', 'frequently', 'asked', 'questions', 'testimonials', 'with', 'from', 'your', 'the', 'and' );
		$out  = array();
		foreach ( (array) preg_split( '/[^\p{L}]+/u', mb_strtolower( $title ), -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
			if ( mb_strlen( (string) $w ) >= 4 && ! in_array( $w, $skip, true ) ) {
				$out[] = (string) $w;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * How much a section says about the topic: how many times its words are the topic's.
	 *
	 * @param array<string, mixed> $block
	 * @param array<int, string>   $topic
	 */
	private static function affinity( array $block, array $topic ): int {
		$text = mb_strtolower( wp_strip_all_tags( serialize_block( $block ) ) );
		$n    = 0;
		foreach ( $topic as $w ) {
			$n += substr_count( $text, $w );
		}

		return $n;
	}

	/** The page's own title in the hero's heading. */
	private static function title_hero( array &$block, string $title ): void {
		$found = false;
		$walk  = static function ( array &$b ) use ( &$walk, &$found, $title ): void {
			if ( $found ) {
				return;
			}
			if ( ( $b['blockName'] ?? '' ) === 'core/heading' && (int) ( $b['attrs']['level'] ?? 2 ) <= 2 ) {
				Block_Tree::set_content( $b, esc_html( $title ) );
				$found = true;

				return;
			}
			foreach ( $b['innerBlocks'] as &$child ) {
				$walk( $child );
			}
		};
		$walk( $block );
	}

	/**
	 * The cards that list other pages, listing this site's services: each card's heading and link are a service's.
	 * A card without a service to show is taken out (two stay at least); the rest of the card is the Home's.
	 *
	 * @param array<string, mixed>                                  $block
	 * @param array<string, mixed>                                  $component
	 * @param array<int, array{title:string, url:string, type:string}> $siblings
	 * @return array<string, mixed>
	 */
	private static function fill_related( array $block, array $component, array $siblings, string $this_title, array $texts = array() ): array {
		$rep = null;
		foreach ( (array) $component['repeats'] as $r ) {
			if ( count( (array) $r['items'] ) >= 3 && ( $r['kind'] ?? '' ) === 'card' ) {
				$rep = $r;
				break;
			}
		}
		if ( $rep === null ) {
			return $block;
		}
		$siblings = array_values( array_filter( $siblings, static fn( $s ) => mb_strtolower( $s['title'] ) !== mb_strtolower( $this_title ) ) );
		if ( count( $siblings ) < 2 ) {
			return $block;
		}
		$items = array_values( (array) $rep['items'] );
		$keep  = min( count( $items ), count( $siblings ) );
		$slots = (array) ( $rep['shape']['slots'] ?? array() );
		$root_type = (string) ( $rep['shape']['root'] ?? '' );
		// A card with a picture of its own cannot be given another page's title and keep the picture: it is left as the Home has it
		// (the menu points a card at the page of its heading).
		foreach ( $slots as $slot ) {
			if ( $slot['type'] === 'image' && empty( $slot['decor'] ) ) {
				return $block;
			}
		}

		// Fill first, then take out the cards left over (the later ones, so the paths stay right).
		for ( $k = 0; $k < $keep; $k++ ) {
			$path = array_merge( (array) $rep['path'], array( (int) $items[ $k ] ) );
			$item = &Block_Tree::at( $block, $path );
			if ( in_array( $root_type, array( 'card', 'link' ), true ) ) {
				Block_Tree::set_link( $item, $siblings[ $k ]['url'] );
			}
			$titled = false;
			$texted = false;
			$gone   = array();
			foreach ( $slots as $slot ) {
				$target = &Block_Tree::at( $item, (array) $slot['path'] );
				if ( $slot['type'] === 'link' && ! in_array( $root_type, array( 'card' ), true ) ) {
					Block_Tree::set_link( $target, $siblings[ $k ]['url'] );
				}
				if ( $slot['type'] === 'heading' && ! $titled ) {
					Block_Tree::set_content( $target, esc_html( $siblings[ $k ]['title'] ) );
					$titled = true;
				}
				// The words under the title are the Home's for a card of that title; another card's words are not left under it.
				if ( $slot['type'] === 'text' && empty( $slot['decor'] ) && ! $texted ) {
					$words  = (string) ( $texts[ mb_strtolower( $siblings[ $k ]['title'] ) ] ?? '' );
					$texted = true;
					if ( $words !== '' ) {
						Block_Tree::set_content( $target, esc_html( $words ) );
					} else {
						$gone[] = array_map( 'intval', (array) $slot['path'] );
					}
				}
				unset( $target );
			}
			rsort( $gone );
			foreach ( $gone as $p ) {
				Block_Tree::remove( $item, $p );
			}
			unset( $item );
		}
		if ( $keep < count( $items ) ) {
			$parent = &Block_Tree::at( $block, (array) $rep['path'] );
			for ( $k = count( $items ) - 1; $k >= max( 2, $keep ); $k-- ) {
				Block_Tree::drop( $parent, (int) $items[ $k ] );
			}
			unset( $parent );
		}

		return $block;
	}

	/**
	 * The headings of the items of a repeated group.
	 *
	 * @param array<string, mixed> $block
	 * @param array<string, mixed> $rep
	 * @return array<int, string>
	 */
	private static function item_titles( array $block, array $rep ): array {
		$out   = array();
		$slots = (array) ( $rep['shape']['slots'] ?? array() );
		foreach ( (array) $rep['items'] as $index ) {
			$item = Block_Tree::at( $block, array_merge( (array) $rep['path'], array( (int) $index ) ) );
			foreach ( $slots as $slot ) {
				if ( $slot['type'] !== 'heading' ) {
					continue;
				}
				$target = Block_Tree::at( $item, (array) $slot['path'] );
				$out[]  = trim( html_entity_decode( wp_strip_all_tags( Block_Tree::own_text( $target ) ), ENT_QUOTES, 'UTF-8' ) );
				break;
			}
		}

		return $out;
	}

	/* ------------------------------------------------------------------- pages */

	/**
	 * @param array<int, array{type:string, title:string}> $wanted
	 * @return array<int, array{type:string, title:string, slug:string}>
	 */
	private static function clean_wanted( array $wanted ): array {
		$out  = array();
		$seen = array();
		foreach ( $wanted as $w ) {
			$type  = (string) ( $w['type'] ?? '' );
			$title = trim( sanitize_text_field( (string) ( $w['title'] ?? '' ) ) );
			if ( ! isset( self::KINDS[ $type ] ) ) {
				continue;
			}
			if ( $title === '' ) {
				$title = self::KINDS[ $type ]['title'];
			}
			$slug = sanitize_title( $title );
			$key  = $type . '|' . $slug;
			if ( $title === '' || $slug === '' || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'type'  => $type,
				'title' => $title,
				'slug'  => $slug,
			);
		}

		return $out;
	}

	/** The page this design already has for a kind and slug, or 0. */
	private static function existing( int $home, string $type, string $slug ): int {
		$found = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => self::META,
						'value' => $type . '|' . $slug,
					),
					array(
						'key'   => Page_Scope::META,
						'value' => (string) $home,
					),
				),
				'fields'         => 'ids',
				'posts_per_page' => 5,
				'no_found_rows'  => true,
			)
		);
		foreach ( $found as $id ) {
			// A page an import has taken since (its key is not ours) is that design's now: not to be written over.
			if ( ! self::taken( (int) $id ) ) {
				return (int) $id;
			}
		}

		return 0;
	}

	/** The key of a page made here. */
	public static function page_key( int $home, string $type, string $slug ): string {
		return 'team#' . $home . '#' . $type . '|' . $slug;
	}

	/** Whether a page carries the key of an import (an archive's), not of a page made here. */
	public static function taken( int $id ): bool {
		$key = (string) get_post_meta( $id, self::KEY_META, true );

		return $key !== '' && ! str_starts_with( $key, 'team#' );
	}

	/**
	 * Put the key on the pages made before it existed, and take the team marker off a page an import took (it is that
	 * design's page, and its marker would let this write over it). Once per version; a few hundred pages at most.
	 */
	public static function upgrade(): void {
		if ( (string) get_option( self::KEYS_DONE, '' ) === '1' || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::claim();
		update_option( self::KEYS_DONE, '1', false );
	}

	/**
	 * @return array{keyed:int, released:int}
	 */
	public static function claim(): array {
		$out = array( 'keyed' => 0, 'released' => 0 );
		$ids = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
				'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'fields'         => 'ids',
				'posts_per_page' => 2000,
				'no_found_rows'  => true,
			)
		);
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( self::taken( $id ) ) {
				delete_post_meta( $id, self::META );
				delete_post_meta( $id, self::HASH );
				++$out['released'];
				continue;
			}
			$home = (int) get_post_meta( $id, Page_Scope::META, true );
			if ( (string) get_post_meta( $id, self::KEY_META, true ) === '' && $home > 0 ) {
				[ $type, $slug ] = array_pad( explode( '|', (string) get_post_meta( $id, self::META, true ), 2 ), 2, '' );
				update_post_meta( $id, self::KEY_META, self::page_key( $home, $type, $slug ) );
				++$out['keyed'];
			}
		}

		return $out;
	}

	/** Whether a person edited the page after this wrote it. */
	private static function edited( int $id ): bool {
		$hash = (string) get_post_meta( $id, self::HASH, true );

		return $hash === '' || md5( (string) get_post_field( 'post_content', $id ) ) !== $hash;
	}

	/**
	 * A page with nothing in it yet, so its address exists.
	 *
	 * @param array{type:string, title:string, slug:string}         $item
	 * @param array<int, array{type:string, title:string, slug:string}> $all
	 * @return int|\WP_Error
	 */
	private static function shell( int $home, array $item, array $all ) {
		$parent = 0;
		// A service sits under the page that lists the services, a place under the page that lists the places.
		$list = array(
			'service'  => 'services',
			'location' => 'areas',
		)[ $item['type'] ] ?? '';
		if ( $list !== '' ) {
			foreach ( $all as $other ) {
				if ( $other['type'] === $list ) {
					$parent = self::existing( $home, $other['type'], $other['slug'] );
					break;
				}
			}
		}
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => wp_slash( $item['title'] ),
				'post_name'    => $item['slug'],
				'post_parent'  => $parent,
				'post_content' => '',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( (int) $id, self::META, $item['type'] . '|' . $item['slug'] );
		update_post_meta( (int) $id, self::KEY_META, self::page_key( $home, $item['type'], $item['slug'] ) );
		update_post_meta( (int) $id, Page_Scope::META, $home );

		return (int) $id;
	}

	/**
	 * Put the sections on the page, in the Home's frame (Page_Frame), with the Home's scope, styles, scripts and fonts, the way a
	 * page the importer makes has them.
	 *
	 * @param array<int, array<string, mixed>> $sections
	 * @return true|\WP_Error
	 */
	private static function write( int $id, int $home, array $sections ) {
		// The Home's header and footer when it keeps them in its own content (a page written as one group, a classic theme):
		// the page carries its own copy, as a page the importer makes does, so the rules for their blocks are written for it.
		// A template part or the site's header block is added around the page when it is shown.
		$chrome = Section_Library::chrome_markup( $home );
		$embed  = static fn( string $m ): bool => $m !== '' && ! str_contains( $m, '<!-- wp:template-part' ) && ! str_contains( $m, '<!-- wp:dxai-ui/site-' );
		$blocks = static fn( string $m ): array => array_values( array_filter( parse_blocks( $m ), static fn( $b ) => ! empty( $b['blockName'] ) ) );
		$markup = Block_Tree::serialize_checked( Page_Frame::build( $home, $embed( $chrome['header'] ) ? $blocks( $chrome['header'] ) : array(), $sections, $embed( $chrome['footer'] ) ? $blocks( $chrome['footer'] ) : array() ) );
		if ( is_wp_error( $markup ) ) {
			return $markup;
		}
		$markup = trim( $markup );
		global $wpdb;
		// The design's own markup is written as it is: wp_update_post() would run KSES for someone without unfiltered HTML.
		$wpdb->update( $wpdb->posts, array( 'post_content' => $markup ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		clean_post_cache( $id );
		// Written straight to the row: the rules cached for the page are for the content it had.
		\DXAI_UI\Blocks\Style_Rules::forget( $id );
		update_post_meta( $id, self::HASH, md5( $markup ) );
		update_post_meta( $id, '_wp_page_template', Blank_Template::SLUG );
		update_post_meta( $id, '_dxai_ui_generated_page', '1' );
		update_post_meta( $id, Page_Scope::META, $home );
		foreach ( array(
			'_dxai_ui_css_url',
			'_dxai_ui_js_url',
			'_dxai_ui_font_urls',
			'_dxai_ui_wrapper_class',
			'_dxai_ui_static_html',
			\DXAI_UI\Compiler\Design_Tokens::META,
			\DXAI_UI\Chrome\Page_Chrome::PART_KEY_META,
			\DXAI_UI\Chrome\Page_Chrome::MODE_META,
		) as $key ) {
			$value = get_post_meta( $home, $key, true );
			if ( $value === '' || $value === array() || $value === false ) {
				delete_post_meta( $id, $key );
			} else {
				update_post_meta( $id, $key, $value );
			}
		}

		return true;
	}
}

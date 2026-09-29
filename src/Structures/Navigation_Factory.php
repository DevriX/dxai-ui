<?php
/**
 * Create or update wp_navigation + classic menus.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Structures;

use DXAI_UI\Connectors\Site_Origin;

final class Navigation_Factory {

	/*
	 * Re-imports update both menus IN PLACE.
	 *
	 * They used to be rebuilt: classic_menu() hard-deleted every item of the
	 * menu it found by title (wp_delete_post( $id, true ) — no trash) and
	 * re-created the design's, and save() overwrote the wp_navigation post's
	 * content wholesale. A link a person added, a class or "open in new tab"
	 * they set on a generated item, went with every import.
	 *
	 * Now each design item is matched to an existing one by its target — the
	 * page it points at, or its URL (target_key()) — and that item is updated:
	 * label, order and parent from the design, everything else a person set on
	 * it kept. What the design adds is added. What the design dropped is
	 * removed only if this factory created it; an item it did not create is a
	 * person's, and is kept, re-seated only if the item it hung under was
	 * removed. Who created what is recorded (ITEM_META, NAV_TARGETS_META).
	 *
	 * A menu written before these records existed has none — measured
	 * 2026-09-24: 16 classic menus, every item unmarked, every menu with no
	 * term meta. It is matched against all of its items, so the first
	 * re-import updates them in place rather than duplicating them, but nothing
	 * in it is removed: with no record, a person's item cannot be told from a
	 * generated one. From that import on it carries the records.
	 */

	/**
	 * Post meta on a classic menu item this factory created. A person's item
	 * never carries it, so only items with it are ever deleted. Same key the
	 * plugin already uses for its other generated posts.
	 */
	private const ITEM_META = '_dxai_ui_generated';

	/**
	 * Term meta on a classic menu whose items carry ITEM_META. Without it the
	 * menu predates the marks, and an unmarked item is not evidence of a
	 * person (see classic_menu()).
	 */
	private const MENU_MARKED_META = '_dxai_ui_items_marked';

	/**
	 * Post meta on a wp_navigation post: [target key, label] of every link
	 * block this factory wrote into it, in document order. A block cannot
	 * carry post meta, and an attribute inside the markup would be copied by
	 * the editor's Duplicate along with the block — a person's copy, retargeted
	 * to their own page, would then read as generated and be removed. The
	 * record lives outside the markup, where copying a block cannot forge it.
	 */
	private const NAV_TARGETS_META = '_dxai_ui_nav_targets';

	/**
	 * Post meta on a classic menu item: the design's value of each field this
	 * factory wrote into it besides label and target — description (a
	 * header button's eyebrow), link target, rel, title attribute. On the
	 * next import a field that still holds what was written is the design's
	 * and follows the design; one that holds something else is a person's
	 * and is kept.
	 */
	private const WRITTEN_META = '_dxai_ui_written';

	/** Item fields a design may set, and the wp_update_nav_menu_item() argument for each. */
	private const DESIGN_FIELDS = array(
		'description' => 'menu-item-description',
		'target'      => 'menu-item-target',
		'xfn'         => 'menu-item-xfn',
		'attr_title'  => 'menu-item-attr-title',
	);

	/**
	 * Term meta on a classic menu header_menu() wrote: the header location it
	 * fills. The header's primary menu is named like the one save() has
	 * always written for the old `dxai-primary` location ("{title} Primary"),
	 * so the menu a person already edited is the one the header reads. From
	 * then on the header install is its only writer: save() and save_tree()
	 * still write their wp_navigation post but leave this menu alone. Two
	 * writers with different item trees — the compiler's flat menu_items, the
	 * header's three levels — would each trash the items the other added.
	 */
	public const HEADER_MENU_META = '_dxai_ui_header_location';

	/**
	 * Term meta on a header menu: the design identity (label path, see
	 * item_nodes()) of every design item the last import wrote or kept out
	 * of it. A design item listed here that is no longer in the menu was
	 * removed by a person — Appearance > Menus deletes a removed item outright
	 * — and a re-import leaves it out instead of adding it back.
	 */
	private const HEADER_ITEMS_META = '_dxai_ui_header_items';

	/**
	 * Term meta on a classic menu, post meta on a wp_navigation post: the
	 * design it was written for — the page key (`archive#slug`) of that
	 * design's home page, the identity Structure_Repository::existing_page()
	 * gives the page itself.
	 *
	 * Menus used to be found by the design's TITLE ("{title} Primary",
	 * "{title} Footer"), while pages are identified by archive and slug. A
	 * different archive with the same title therefore took over another
	 * design's menus: measured 2026-09-25, a test import of a second
	 * `semper-dry.zip` found Semper Dry's "Semper Dry Footer" menu by name,
	 * trashed its 17 items and overwrote both of its wp_navigation posts. Now
	 * a menu is this factory's to write for a design only when it carries that
	 * design's key (or is a legacy menu the stored headers attribute to it,
	 * see legacy_owner()); a menu of the same name that belongs to another
	 * design is left alone and the design gets a menu of its own.
	 */
	public const OWNER_META = '_dxai_ui_owner';

	/**
	 * Term meta on a classic menu save() wrote, post meta on a wp_navigation
	 * post: which of the design's menus it is (`dxai-primary`,
	 * `dxai-footer`), so an owner's menus are told apart without their names.
	 * A header menu says its location in HEADER_MENU_META instead.
	 */
	private const ROLE_META = '_dxai_ui_menu_role';

	/** The design identity menus are written for (OWNER_META); '' for none. */
	private string $owner;

	/** A short name of the design, told apart from another of the same title ("… (archive)"). */
	private string $label;

	/**
	 * Whether a menu that carries no owner at all, and that no stored header
	 * attributes to anyone, may be taken by name. Only when the caller knows
	 * the design was imported before under this identity — its page existed
	 * before this import — because such a menu was written by title by an
	 * older version for whichever design had that title.
	 */
	private bool $adopt_legacy;

	/**
	 * @param string $owner        The design identity (the home page's `_dxai_ui_page_key`); '' keeps
	 *                             the old by-name behaviour for callers that have none.
	 * @param string $label        What tells the design apart in a menu name (its archive, without .zip).
	 * @param bool   $adopt_legacy Whether an unowned legacy menu found by name may be taken.
	 */
	public function __construct( string $owner = '', string $label = '', bool $adopt_legacy = true ) {
		$this->owner        = trim( $owner );
		$this->label        = trim( wp_strip_all_tags( $label ) );
		$this->adopt_legacy = $adopt_legacy;
	}

	/**
	 * The owner key of a design: its home page's page key.
	 *
	 * @param int    $page_id The design's home page.
	 * @param string $archive Its archive, when the page carries no key (then `archive#`).
	 */
	public static function owner_key( int $page_id, string $archive = '' ): string {
		$key = $page_id > 0 ? (string) get_post_meta( $page_id, '_dxai_ui_page_key', true ) : '';
		if ( $key !== '' ) {
			return $key;
		}
		$archive = trim( $archive );

		return $archive !== '' ? $archive . '#' : '';
	}

	/**
	 * Who a legacy menu (no OWNER_META) belongs to, as far as the stored
	 * headers can tell: the owner key of the design whose header spec lists
	 * it among its menus (Header_Template's site option and per-design
	 * copies). '' when no spec lists it.
	 */
	private static function legacy_owner( int $term_id ): string {
		global $wpdb;

		$names = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name = %s OR option_name LIKE %s",
				'dxai_ui_site_header',
				$wpdb->esc_like( 'dxai_ui_site_header_' ) . '%'
			)
		);
		foreach ( $names as $name ) {
			$spec = get_option( (string) $name );
			if ( ! is_array( $spec ) || ! in_array( $term_id, array_map( 'intval', (array) ( $spec['menus'] ?? array() ) ), true ) ) {
				continue;
			}
			$source = is_array( $spec['source'] ?? null ) ? $spec['source'] : array();
			if ( ! empty( $source['owner'] ) ) {
				return (string) $source['owner'];
			}

			return self::owner_key( (int) ( $source['page_id'] ?? 0 ), (string) ( $source['archive'] ?? '' ) );
		}

		return '';
	}

	/**
	 * Whether an owner key someone else recorded is this factory's owner.
	 * `archive#` (a page that had no key) matches any page of that archive.
	 */
	private function is_mine( string $holder ): bool {
		if ( $holder === '' || $this->owner === '' ) {
			return false;
		}
		if ( $holder === $this->owner ) {
			return true;
		}
		$short = static fn( string $k ): bool => str_ends_with( $k, '#' );

		return ( $short( $holder ) && str_starts_with( $this->owner, $holder ) ) || ( $short( $this->owner ) && str_starts_with( $holder, $this->owner ) );
	}

	/**
	 * The classic menu this factory writes for $location, when there is one:
	 * by its owner record first, then by name — a menu of that name is taken
	 * only if it is this design's (its record, or the stored headers say so),
	 * or it has no record at all and legacy menus may be adopted. A header
	 * menu is never found for save()'s menus, which do not write them.
	 */
	private function find_menu( string $title, string $location, bool $header ): int {
		$role_key = $header ? self::HEADER_MENU_META : self::ROLE_META;
		if ( $this->owner !== '' ) {
			$found = get_terms(
				array(
					'taxonomy'   => 'nav_menu',
					'hide_empty' => false,
					'fields'     => 'ids',
					'number'     => 1,
					'orderby'    => 'term_id',
					'order'      => 'ASC',
					'meta_query' => array(
						array(
							'key'   => self::OWNER_META,
							'value' => $this->owner,
						),
						array(
							'key'   => $role_key,
							'value' => $location,
						),
					),
				)
			);
			if ( is_array( $found ) && $found !== array() ) {
				return (int) $found[0];
			}
		}

		$menu = get_term_by( 'name', $title, 'nav_menu' );
		if ( ! $menu instanceof \WP_Term ) {
			return 0;
		}
		$id = (int) $menu->term_id;
		if ( ! $header && '' !== (string) get_term_meta( $id, self::HEADER_MENU_META, true ) ) {
			return 0;
		}
		$holder = (string) get_term_meta( $id, self::OWNER_META, true );
		if ( $holder !== '' ) {
			return $this->is_mine( $holder ) ? $id : 0;
		}
		$claim = self::legacy_owner( $id );
		if ( $claim !== '' ) {
			return $this->owner === '' || $this->is_mine( $claim ) ? $id : 0;
		}

		return $this->owner === '' || $this->adopt_legacy ? $id : 0;
	}

	/**
	 * A name for a new menu or navigation post: $title, or when another
	 * design's already has it, "$title ({label})", then numbered.
	 *
	 * @param callable $taken Whether a name is in use: fn( string ): bool.
	 */
	private function free_name( string $title, callable $taken ): string {
		if ( ! $taken( $title ) ) {
			return $title;
		}
		$base = $this->label !== '' ? $title . ' (' . $this->label . ')' : $title;
		$name = $base;
		for ( $n = 2; $taken( $name ) && $n < 100; $n++ ) {
			$name = $base . ' ' . $n;
		}

		return $name;
	}

	/**
	 * Record who a classic menu was written for.
	 */
	private function stamp_menu( int $menu_id, string $location, bool $header ): void {
		if ( $this->owner !== '' ) {
			update_term_meta( $menu_id, self::OWNER_META, $this->owner );
		}
		if ( ! $header ) {
			update_term_meta( $menu_id, self::ROLE_META, $location );
		}
	}

	/**
	 * The wp_navigation post save() writes for $location: by its owner record
	 * first, then — the same rules as find_menu() — a generated one of that
	 * title that is this design's or, unowned, may be adopted.
	 */
	private function find_navigation( string $title, string $location ): ?\WP_Post {
		if ( $this->owner !== '' ) {
			$own = get_posts(
				array(
					'post_type'      => 'wp_navigation',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_query'     => array(
						array(
							'key'   => self::OWNER_META,
							'value' => $this->owner,
						),
						array(
							'key'   => self::ROLE_META,
							'value' => $location,
						),
					),
				)
			);
			if ( isset( $own[0] ) && $own[0] instanceof \WP_Post ) {
				return $own[0];
			}
		}
		$named = get_posts(
			array(
				'post_type'      => 'wp_navigation',
				'post_status'    => 'publish',
				'title'          => $title,
				'posts_per_page' => 5,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_key'       => '_dxai_ui_generated',
				'meta_value'     => '1',
			)
		);
		foreach ( $named as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$holder = (string) get_post_meta( (int) $post->ID, self::OWNER_META, true );
			if ( $holder !== '' ? $this->is_mine( $holder ) : ( $this->owner === '' || $this->adopt_legacy ) ) {
				return $post;
			}
		}

		return null;
	}

	/**
	 * Write a header menu: a classic menu only (no wp_navigation post), any
	 * depth, with text items (URL `#`), tel: / mailto: links and the fields
	 * a header item carries — description, target, rel, title attribute.
	 * Updated in place like every other menu this factory writes, marked as a
	 * header menu (HEADER_MENU_META), and assigned to $location when that
	 * location is registered and $assign allows it.
	 *
	 * A header menu is what a person edits the header in, so a re-import
	 * keeps more of it than of a legacy menu (see classic_write()): a label
	 * or a link a person changed on a design item stays theirs, and a design
	 * item they removed stays removed.
	 *
	 * @param array<int, array<string, mixed>> $tree     label, url, children, and optionally
	 *                                                   description / target / xfn / attr_title / page_id.
	 * @param array<string, int>               $path_map Crawl: design path => page id.
	 * @param int                              $menu_id  The menu this header wrote last time. Found by id,
	 *                                                   not by name, so a menu a person renamed is still
	 *                                                   the one written; 0 finds it by its owner record
	 *                                                   (find_menu()), and by name only when that menu is
	 *                                                   this design's.
	 * @param bool                             $assign   Whether to assign $location to it.
	 * @return int The menu's term id, 0 when it could not be written.
	 */
	public function header_menu( string $name, array $tree, string $location, array $path_map = array(), string $origin = '', int $menu_id = 0, bool $assign = true ): int {
		$items = $path_map !== array() ? $this->bind_pages( $tree, $path_map, $origin ) : $tree;
		$id    = $this->classic_menu( $name, $items, $location, $menu_id, $assign, true );
		if ( $id > 0 ) {
			update_term_meta( $id, self::HEADER_MENU_META, $location );
		}

		return $id;
	}

	/**
	 * @param array<int, array{label:string, url:string, children?:array, page_id?:int}> $items
	 */
	public function save( string $title, array $items, string $location = 'dxai-primary' ): int {
		// No items from the design: the one Home link the navigation has always
		// fallen back to. Not given to the classic menu, as before.
		$wanted = array_filter( $items, 'is_array' ) !== array()
			? $items
			: array(
				array(
					'label' => 'Home',
					'url'   => '/',
				),
			);

		/*
		 * The design's own post for this role (find_navigation()), never one
		 * of the same title another design wrote. A new one takes the title
		 * when it is free and "{title} ({archive})" when another design's
		 * post already has it.
		 */
		$post   = $this->find_navigation( $title, $location );
		$merged = $this->merge_navigation( $post, $wanted );
		$name   = $post instanceof \WP_Post
			? (string) $post->post_title
			: $this->free_name(
				$title,
				static fn( string $t ): bool => get_posts(
					array(
						'post_type'      => 'wp_navigation',
						'post_status'    => 'publish',
						'title'          => $t,
						'posts_per_page' => 1,
						'fields'         => 'ids',
					)
				) !== array()
			);

		$data = array(
			'post_type'    => 'wp_navigation',
			'post_status'  => 'publish',
			'post_title'   => $name,
			/*
			 * Slashed because wp_insert_post() / wp_update_post() unslash it.
			 * The content used to be generated here alone, in a format with no
			 * backslashes in it; it now carries a person's blocks through
			 * serialize_block(), whose attribute JSON escapes `<`, `&`, `"`
			 * as <, &, " — unslashed, those would lose their
			 * backslash and the person's block would be corrupted.
			 */
			'post_content' => wp_slash( $merged['content'] ),
			'meta_input'   => array( '_dxai_ui_generated' => '1' ),
		);

		if ( $post instanceof \WP_Post ) {
			$data['ID'] = $post->ID;
			$id         = wp_update_post( $data, true );
		} else {
			$id = wp_insert_post( $data, true );
		}

		$id = is_wp_error( $id ) ? 0 : (int) $id;
		if ( $id ) {
			// update_metadata() unslashes, recursively; a label or URL with a
			// backslash would otherwise be recorded without it and never match.
			update_post_meta( $id, self::NAV_TARGETS_META, wp_slash( $merged['targets'] ) );
			if ( $this->owner !== '' ) {
				update_post_meta( $id, self::OWNER_META, $this->owner );
			}
			update_post_meta( $id, self::ROLE_META, $location );
			/*
			 * A classic menu only for a location something registered, which
			 * is what renders one. The plugin's own `dxai-primary` and
			 * `dxai-footer` are not registered any more: the header is written
			 * into its own menus by Header_Menus::install() (header_menu()),
			 * the footer is widgets. The "{title} Primary" / "{title} Footer"
			 * classic menus this used to keep in step rendered nowhere, and
			 * updating them by name is how one design's import rewrote
			 * another's footer menu. Those menus stay as they are; nothing is
			 * deleted.
			 */
			if ( isset( get_registered_nav_menus()[ $location ] ) ) {
				$this->classic_menu( $title, $items, $location );
			}
		}

		return $id;
	}

	/**
	 * Rebuild a menu from a nested tree, wiring page IDs when paths match.
	 *
	 * @param array<int, array{label?:string, url?:string, children?:array}> $tree
	 * @param array<string, int>                                               $path_map path => page_id
	 */
	public function save_tree( string $title, array $tree, array $path_map, string $location = 'dxai-primary', string $origin = '' ): int {
		$items = $this->bind_pages( $tree, $path_map, $origin );

		return $this->save( $title, $items, $location );
	}

	/**
	 * @param array<int, array{label?:string, url?:string, children?:array}> $tree
	 * @param array<string, int>                                               $path_map
	 * @return array<int, array{label:string, url:string, children:array, page_id?:int}>
	 */
	private function bind_pages( array $tree, array $path_map, string $origin ): array {
		$out = array();
		foreach ( $tree as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $item['label'] ?? '' ) );
			$url   = (string) ( $item['url'] ?? '#' );
			if ( $label === '' ) {
				continue;
			}
			$row = array(
				'label'    => $label,
				'url'      => $url !== '' ? $url : '#',
				'children' => array(),
			);
			// A header item's own fields ride along (header_menu()).
			foreach ( array_keys( self::DESIGN_FIELDS ) as $field ) {
				if ( isset( $item[ $field ] ) ) {
					$row[ $field ] = (string) $item[ $field ];
				}
			}
			$path = $this->bound_path( $url, $origin );
			$page = $path !== '' && isset( $path_map[ $path ] ) ? (int) $path_map[ $path ] : self::page_at( $url, $path_map );
			if ( $page > 0 ) {
				$row['page_id'] = $page;
				$permalink      = get_permalink( $row['page_id'] );
				if ( is_string( $permalink ) && $permalink !== '' ) {
					$row['url'] = $permalink;
				}
			}
			$kids = is_array( $item['children'] ?? null ) ? $item['children'] : array();
			if ( $kids !== array() ) {
				$row['children'] = $this->bind_pages( $kids, $path_map, $origin );
			}
			$out[] = $row;
		}

		return $out;
	}

	/**
	 * The page of $path_map a URL already is the permalink of, or 0.
	 *
	 * A markup whose links the import has already rewritten (Internal_Links
	 * runs before the header is installed when the crawl ran in the same
	 * request) holds the crawled pages' permalinks, not the design's paths;
	 * those items are the same pages, bound the same way as after a deferred
	 * crawl (Header_Menus::rebind()), so a later permalink change follows.
	 *
	 * @param array<string, int> $path_map
	 */
	private static function page_at( string $url, array $path_map ): int {
		static $cache = array();
		$url = trim( $url );
		if ( $url === '' || $path_map === array() || str_contains( $url, '#' ) || str_contains( $url, '?' ) || preg_match( '#^https?://#i', $url ) !== 1 ) {
			return 0;
		}
		$key = md5( (string) wp_json_encode( $path_map ) );
		if ( ! isset( $cache[ $key ] ) ) {
			$cache[ $key ] = array();
			foreach ( array_unique( array_map( 'intval', $path_map ) ) as $page ) {
				$link = $page > 0 ? get_permalink( $page ) : '';
				if ( is_string( $link ) && $link !== '' ) {
					$cache[ $key ][ untrailingslashit( set_url_scheme( $link, 'http' ) ) ] = $page;
				}
			}
		}

		return (int) ( $cache[ $key ][ untrailingslashit( set_url_scheme( $url, 'http' ) ) ] ?? 0 );
	}

	/**
	 * The design path a menu URL points at, for binding it to the page made
	 * for that path — or '' when it points at no page of the design.
	 *
	 * Site_Origin::path_of() reads every URL without a path as `/`, so `#`,
	 * `#about` and an item with no link at all were all bound to the Home
	 * page: a header's text items (the trust strip's `#`) became links to the
	 * home page, and an in-page anchor lost its fragment — measured, four
	 * extra <a> in Semper Dry's top bar the moment a page map was passed. An
	 * absolute URL on another host (a social profile) matched `/` the same
	 * way. So a URL binds only when it names a page of the design: a path,
	 * relative or on the design's own host (www. aside), with no fragment or
	 * query a page link could not keep; not tel:, mailto: or anything else.
	 */
	private function bound_path( string $url, string $origin ): string {
		$url = trim( $url );
		if ( $url === '' || str_contains( $url, '#' ) || str_contains( $url, '?' ) || preg_match( '/^[a-z][a-z0-9+.-]*:(?!\/\/)/i', $url ) === 1 ) {
			return '';
		}
		if ( preg_match( '#^(?:https?:)?//#i', $url ) === 1 ) {
			$bare = static fn( $host ): string => strtolower( (string) preg_replace( '/^www\./i', '', (string) $host ) );
			$host = $bare( wp_parse_url( str_starts_with( $url, '//' ) ? 'https:' . $url : $url, PHP_URL_HOST ) );
			$own  = $origin !== '' ? $bare( wp_parse_url( $origin, PHP_URL_HOST ) ) : '';
			if ( $host === '' || $own === '' || $host !== $own ) {
				return '';
			}
		}

		return Site_Origin::path_of( $url );
	}

	/**
	 * The wp_navigation content for $items, merged into what $post holds.
	 *
	 * @param array<int, array{label:string, url:string, children?:array, page_id?:int}> $items
	 * @return array{content:string, targets:array<int, array{0:string, 1:string}>}
	 */
	private function merge_navigation( ?\WP_Post $post, array $items ): array {
		$existing = array();
		$roots    = array();
		$recorded = array();
		$legacy   = true;
		if ( $post instanceof \WP_Post ) {
			$roots  = $this->block_nodes( parse_blocks( (string) $post->post_content ), $existing );
			$legacy = ! metadata_exists( 'post', (int) $post->ID, self::NAV_TARGETS_META );
			$stored = $legacy ? array() : get_post_meta( (int) $post->ID, self::NAV_TARGETS_META, true );
			$recorded = is_array( $stored ) ? $stored : array();
		}

		/*
		 * Which link blocks this factory wrote: claimed against the record in
		 * two passes, the exact [target, label] pair first. A person who
		 * duplicates a generated link and relabels it leaves two blocks with
		 * one target; the pass by pair gives the record's entry to the
		 * original, so the copy stays theirs. The pass by target alone then
		 * claims a generated link a person relabeled.
		 */
		foreach ( array( true, false ) as $exact ) {
			foreach ( $existing as $e => $node ) {
				if ( null === $node['key'] || $node['owned'] ) {
					continue;
				}
				foreach ( $recorded as $r => $pair ) {
					if ( is_array( $pair ) && ( $pair[0] ?? null ) === $node['key'] && ( ! $exact || ( $pair[1] ?? null ) === $node['label'] ) ) {
						$existing[ $e ]['owned'] = true;
						unset( $recorded[ $r ] );
						break;
					}
				}
			}
		}
		foreach ( $existing as $e => $node ) {
			$existing[ $e ]['matchable'] = null !== $node['key'] && ( $legacy || $node['owned'] );
		}

		$desired = array();
		$top     = $this->item_nodes( $items, $desired );
		$d_to_e  = $this->match_nodes( $existing, $desired );
		$m       = array(
			'existing' => $existing,
			'desired'  => $desired,
			'd_to_e'   => $d_to_e,
			'e_to_d'   => array_flip( $d_to_e ),
		);

		$targets = array();
		$content = $this->tree_markup( $this->merge_level( $roots, $top, $m ), true, $m, $targets );

		return array(
			'content' => $content,
			'targets' => $targets,
		);
	}

	/**
	 * Flatten parse_blocks() output into merge nodes. Only a
	 * navigation-submenu is descended into; every other block — a search, a
	 * spacer, social links, a page list — is one opaque node, written back
	 * byte for byte by serialize_block().
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int, array<string, mixed>> $nodes  Receives the nodes.
	 * @return array<int, int> This level's node indexes, in document order.
	 */
	private function block_nodes( array $blocks, array &$nodes ): array {
		$ids = array();
		foreach ( $blocks as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( $name === '' && trim( (string) ( $block['innerHTML'] ?? '' ) ) === '' ) {
				continue; // The whitespace between blocks.
			}
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$link  = in_array( $name, array( 'core/navigation-link', 'core/navigation-submenu' ), true );

			$idx           = count( $nodes );
			$nodes[ $idx ] = array(
				'key'       => $link
					? $this->target_key( 'post-type' === ( $attrs['kind'] ?? '' ) ? (int) ( $attrs['id'] ?? 0 ) : 0, (string) ( $attrs['url'] ?? '' ) )
					: null,
				'label'     => $this->label_text( (string) ( $attrs['label'] ?? '' ) ),
				'children'  => array(),
				'owned'     => false,
				'matchable' => false,
				'ref'       => $block,
			);
			$ids[] = $idx;

			if ( 'core/navigation-submenu' === $name ) {
				$kids                      = $this->block_nodes( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array(), $nodes );
				$nodes[ $idx ]['children'] = $kids;
			}
		}

		return $ids;
	}

	/**
	 * Flatten the design's item tree into merge nodes.
	 *
	 * Each node's `path` is its design identity: its label under its
	 * parents' labels. It survives what a crawl changes (a `#about` link
	 * bound to the About page's permalink keeps its path), so a header menu
	 * can tell the design item a person relabeled or removed by it.
	 *
	 * @param array<int, mixed>                $items
	 * @param array<int, array<string, mixed>> $nodes Receives the nodes.
	 * @return array<int, int> This level's node indexes, in design order.
	 */
	private function item_nodes( array $items, array &$nodes, string $parent = '' ): array {
		$ids = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$idx           = count( $nodes );
			$label         = $this->label_text( (string) ( $item['label'] ?? '' ) );
			$nodes[ $idx ] = array(
				'key'      => $this->target_key( (int) ( $item['page_id'] ?? 0 ), (string) ( $item['url'] ?? '#' ) ),
				'label'    => $label,
				'path'     => $parent === '' ? $label : $parent . "\x1f" . $label,
				'children' => array(),
				'item'     => $item,
			);
			$ids[] = $idx;

			$kids = is_array( $item['children'] ?? null ) ? $item['children'] : array();
			if ( $kids !== array() ) {
				$kid_ids                   = $this->item_nodes( $kids, $nodes, $nodes[ $idx ]['path'] );
				$nodes[ $idx ]['children'] = $kid_ids;
			}
		}

		return $ids;
	}

	/**
	 * What a menu item points at: the post, when it points at one — a
	 * crawled page keeps its ID across re-imports while its permalink is
	 * WordPress's, not the design's — else its URL, run through the same
	 * esc_url_raw() wp_update_nav_menu_item() stores it with.
	 */
	private function target_key( int $post_id, string $url ): string {
		if ( $post_id > 0 ) {
			return 'post:' . $post_id;
		}
		$url = esc_url_raw( trim( $url ) );

		return 'url:' . ( $url !== '' ? $url : '#' );
	}

	/**
	 * A label as text. The block editor stores a label as HTML (`&amp;`), a
	 * classic item's title may be kses-normalised the same way depending on
	 * who ran the import, and the design gives plain text.
	 */
	private function label_text( string $label ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $label ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Pair each design node with at most one matchable existing node.
	 *
	 * Three passes, each only over what the earlier ones left: target and
	 * label; target alone (a label changed — in the design, or by a person on
	 * a generated item); label alone (the same item, retargeted). The last
	 * one matters within a single import: Structure_Repository saves each menu
	 * twice, first with the design's URLs and then, after the crawl, bound to
	 * the new pages' IDs, so between the two calls every crawled item changes
	 * target from `url:` to `post:`. Matching those by label keeps the item —
	 * its ID, and what a person set on it — instead of deleting and
	 * re-creating it on every import. Several items may share a target
	 * (three footer links to `#services` on Arcus Restoration, measured
	 * 2026-09-24); the passes pair them in order.
	 *
	 * @param array<int, array<string, mixed>> $existing
	 * @param array<int, array<string, mixed>> $desired
	 * @return array<int, int> desired index => existing index
	 */
	private function match_nodes( array $existing, array $desired ): array {
		$d_to_e = array();
		$taken  = array();
		/*
		 * A header menu's item first by the design identity it was written
		 * for (`record`, classic_plan()): a design item a person relabeled AND
		 * relinked — a call button given another number — is still that item,
		 * where target and label alone would have found nothing, removed it
		 * and added the design's again.
		 */
		foreach ( array( 'record', 'both', 'key', 'label' ) as $pass ) {
			foreach ( $desired as $d => $want ) {
				if ( isset( $d_to_e[ $d ] ) ) {
					continue;
				}
				foreach ( $existing as $e => $have ) {
					if ( isset( $taken[ $e ] ) || ! $have['matchable'] ) {
						continue;
					}
					$same_key    = $have['key'] === $want['key'];
					$same_label  = $have['label'] !== '' && $have['label'] === $want['label'];
					$same_record = isset( $have['record'], $want['path'] ) && $have['record'] === $want['path'];
					if ( ( 'record' === $pass && $same_record ) || ( 'both' === $pass && $same_key && $same_label ) || ( 'key' === $pass && $same_key ) || ( 'label' === $pass && $same_label ) ) {
						$d_to_e[ $d ] = $e;
						$taken[ $e ]  = true;
						break;
					}
				}
			}
		}

		return $d_to_e;
	}

	/**
	 * Merge one level: the design's items in the design's order, with each
	 * item a person added kept after the item it followed before.
	 *
	 * A person's item is anchored to the nearest generated item before it at
	 * this level that the design keeps here; one with none before it stays at
	 * the front. An item this factory created that the design dropped is
	 * left out, and whatever a person nested under it moves up into its place.
	 * A matched item is placed by the design, wherever that is, and takes the
	 * person's items nested under it along.
	 *
	 * @param array<int, int>      $e_list Existing nodes at this level, current order.
	 * @param array<int, int>      $d_list Design nodes at this level, design order.
	 * @param array<string, array> $m      existing, desired, d_to_e, e_to_d.
	 * @return array<int, array{d:?int, e:?int, children:array}>
	 */
	private function merge_level( array $e_list, array $d_list, array $m ): array {
		if ( ! empty( $m['skip'] ) ) {
			$d_list = $this->without_skipped( $d_list, $m );
		}
		$here   = array_flip( $d_list );
		$front  = array();
		$after  = array();
		$anchor = null;
		while ( $e_list !== array() ) {
			$e = array_shift( $e_list );
			if ( isset( $m['e_to_d'][ $e ] ) ) {
				if ( isset( $here[ $m['e_to_d'][ $e ] ] ) ) {
					$anchor = $m['e_to_d'][ $e ];
				}
				continue;
			}
			if ( $m['existing'][ $e ]['owned'] ) {
				$e_list = array_merge( $m['existing'][ $e ]['children'], $e_list );
				continue;
			}
			$node = array(
				'd'        => null,
				'e'        => $e,
				'children' => $this->merge_level( $m['existing'][ $e ]['children'], array(), $m ),
			);
			if ( null === $anchor ) {
				$front[] = $node;
			} else {
				$after[ $anchor ][] = $node;
			}
		}

		$out = $front;
		foreach ( $d_list as $d ) {
			$e     = $m['d_to_e'][ $d ] ?? null;
			$out[] = array(
				'd'        => $d,
				'e'        => $e,
				'children' => $this->merge_level( null !== $e ? $m['existing'][ $e ]['children'] : array(), $m['desired'][ $d ]['children'], $m ),
			);
			foreach ( $after[ $d ] ?? array() as $node ) {
				$out[] = $node;
			}
		}

		return $out;
	}

	/**
	 * A level's design nodes less the ones a person removed from a header
	 * menu (classic_plan()), each replaced by its own design children — where
	 * Appearance > Menus leaves the children of an item it removes.
	 *
	 * @param array<int, int>      $d_list
	 * @param array<string, array> $m
	 * @return array<int, int>
	 */
	private function without_skipped( array $d_list, array $m ): array {
		$out = array();
		foreach ( $d_list as $d ) {
			if ( isset( $m['skip'][ $d ] ) ) {
				$out = array_merge( $out, $this->without_skipped( (array) $m['desired'][ $d ]['children'], $m ) );
				continue;
			}
			$out[] = $d;
		}

		return $out;
	}

	/**
	 * Serialize a merged tree back to navigation block markup, recording the
	 * [target, label] of every generated link written.
	 *
	 * @param array<int, array{d:?int, e:?int, children:array}> $tree
	 * @param array<string, array>                               $m
	 * @param array<int, array{0:string, 1:string}>              $targets Receives the record.
	 */
	private function tree_markup( array $tree, bool $top, array $m, array &$targets ): string {
		$out = array();
		foreach ( $tree as $node ) {
			if ( null !== $node['d'] ) {
				$want      = $m['desired'][ $node['d'] ];
				$targets[] = array( (string) $want['key'], (string) $want['label'] );
				$base      = null !== $node['e'] ? ( $m['existing'][ $node['e'] ]['ref']['attrs'] ?? array() ) : array();
				$out[]     = $this->item_block(
					$want['item'],
					$top,
					$this->tree_markup( $node['children'], false, $m, $targets ),
					is_array( $base ) ? $base : array()
				);
				continue;
			}

			// A person's block: written back as it was. Only a submenu of
			// theirs is rebuilt, because what it holds may have changed.
			$block = $m['existing'][ $node['e'] ]['ref'];
			if ( 'core/navigation-submenu' !== ( $block['blockName'] ?? '' ) ) {
				$out[] = serialize_block( $block );
				continue;
			}
			$inner = $this->tree_markup( $node['children'], false, $m, $targets );
			$out[] = get_comment_delimited_block_content(
				'core/navigation-submenu',
				is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array(),
				$inner === '' ? '' : "\n" . $inner . "\n"
			);
		}

		return implode( "\n", $out );
	}

	/**
	 * One generated navigation link, or a submenu when blocks nest under it.
	 *
	 * A new block is written exactly as before (same attribute order, same
	 * JSON flags), so an import that changes nothing writes the same bytes.
	 *
	 * @param array{label:string, url:string, children?:array, page_id?:int} $item
	 * @param string               $inner Markup of the blocks nested under it.
	 * @param array<string, mixed> $base  Attributes of the block it updates:
	 *                                    what a person set on it (a class,
	 *                                    opensInNewTab, a description) is
	 *                                    kept; label and target are the
	 *                                    design's.
	 */
	private function item_block( array $item, bool $top, string $inner = '', array $base = array() ): string {
		$page_id = (int) ( $item['page_id'] ?? 0 );

		$attrs          = $base;
		$attrs['label'] = (string) ( $item['label'] ?? '' );
		$attrs['url']   = (string) ( $item['url'] ?? '#' );
		$attrs['kind']  = $page_id > 0 ? 'post-type' : 'custom';
		if ( $inner === '' ) {
			$attrs['isTopLevelLink'] = $top;
		} else {
			unset( $attrs['isTopLevelLink'] );
		}
		if ( $page_id > 0 ) {
			$attrs['type'] = 'page';
			$attrs['id']   = $page_id;
		} else {
			unset( $attrs['type'], $attrs['id'] );
		}
		$json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$json = is_string( $json ) ? $json : '{}';

		if ( $inner !== '' ) {
			return sprintf( "<!-- wp:navigation-submenu %s -->\n%s\n<!-- /wp:navigation-submenu -->", $json, $inner );
		}

		return sprintf( '<!-- wp:navigation-link %s /-->', $json );
	}

	/**
	 * @param array<int, array{label:string, url:string, children?:array, page_id?:int}> $items
	 * @param int  $menu_id The menu to write, when the caller knows it (header_menu()).
	 * @param bool $assign  Whether to assign $location to it.
	 * @param bool $header  A header menu (header_menu()): see classic_write().
	 * @return int The menu's term id, 0 when it could not be created.
	 */
	private function classic_menu( string $title, array $items, string $location, int $menu_id = 0, bool $assign = true, bool $header = false ): int {
		$known = $menu_id > 0 ? get_term( $menu_id, 'nav_menu' ) : null;
		if ( $known instanceof \WP_Term ) {
			$menu_id = (int) $known->term_id;
		} else {
			/*
			 * The design's own menu (find_menu()): by its owner record, else by
			 * NAME — never wp_get_nav_menu_object( $title ). That tries
			 * get_term( $title ) first, and get_term() casts its argument to an
			 * integer, so a design titled "24/7 Plumbing" or "208 Roofing" found
			 * the nav_menu term whose ID is 24 or 208 and merged its generated
			 * items into that menu — which can be one a person made, on every
			 * import. Found in review of the purge tool, which had copied this
			 * lookup. A menu of that name belonging to another design is not
			 * this one's: the design gets a menu of its own, named apart.
			 */
			$menu_id = $this->find_menu( $title, $location, $header );
			if ( $menu_id < 1 ) {
				$menu_id = wp_create_nav_menu( $this->free_name( $title, static fn( string $t ): bool => get_term_by( 'name', $t, 'nav_menu' ) instanceof \WP_Term ) );
				if ( is_wp_error( $menu_id ) ) {
					return 0;
				}
				$menu_id = (int) $menu_id;
			}
		}
		$this->stamp_menu( $menu_id, $location, $header );

		$plan = $this->classic_plan( $menu_id, $items, $header );
		$m    = $plan['m'];

		$position = 0;
		$this->classic_write( $menu_id, $plan['tree'], 0, $m, $position );

		/*
		 * Removed only now, once every item that stays has been re-parented
		 * away from them — and only items this factory created (owned). To the
		 * trash, not deleted: a menu only lists published items, so a trashed
		 * one leaves the menu the same way, and it is still there to restore
		 * until the trash is emptied.
		 */
		foreach ( $m['existing'] as $e => $node ) {
			if ( $node['owned'] && ! isset( $m['e_to_d'][ $e ] ) ) {
				wp_trash_post( (int) $node['ref']->ID );
			}
		}
		update_term_meta( $menu_id, self::MENU_MARKED_META, '1' );
		if ( $header ) {
			// Every design item, written or left out as removed: the next
			// import tells a removed one by it. update_metadata() unslashes.
			update_term_meta( $menu_id, self::HEADER_ITEMS_META, wp_slash( array_values( array_unique( array_map( static fn( array $d ): string => (string) $d['path'], $m['desired'] ) ) ) ) );
		}

		/*
		 * Assigned only to a location something registered. The plugin's own
		 * `dxai-primary` / `dxai-footer` are no longer registered (the header
		 * reads `primary-navigation`, the footer is widgets), and an import
		 * that still names them must not leave assignments to locations
		 * nothing renders — nor take over the header's.
		 */
		if ( $assign && isset( get_registered_nav_menus()[ $location ] ) ) {
			$locations              = get_theme_mod( 'nav_menu_locations', array() );
			$locations              = is_array( $locations ) ? $locations : array();
			$locations[ $location ] = $menu_id;
			set_theme_mod( 'nav_menu_locations', $locations );
		}

		return $menu_id;
	}

	/**
	 * Read a classic menu and merge the design's items into it. Writes
	 * nothing: classic_menu() carries the plan out.
	 *
	 * An item is owned — this factory created it, and may delete it — when it
	 * carries ITEM_META in a menu marked MENU_MARKED_META. In an unmarked
	 * menu nothing is owned, so nothing is deleted, but every item may be
	 * matched and updated: the menu was written by the old rebuild, so its
	 * items are the design's own unless a person added one since.
	 *
	 * A header menu also matches an owned item by the design identity it was
	 * written for (WRITTEN_META `path`), and leaves out a design item a
	 * person removed from it (HEADER_ITEMS_META).
	 *
	 * @param array<int, array{label:string, url:string, children?:array, page_id?:int}> $items
	 * @return array{tree:array<int, array{d:?int, e:?int, children:array}>, m:array<string, array>}
	 */
	private function classic_plan( int $menu_id, array $items, bool $header = false ): array {
		$marked = '1' === (string) get_term_meta( $menu_id, self::MENU_MARKED_META, true );
		$old    = self::menu_items( $menu_id );

		$existing = array();
		$by_id    = array();
		foreach ( $old as $i => $post ) {
			$owned                    = $marked && '1' === (string) get_post_meta( (int) $post->ID, self::ITEM_META, true );
			$record                   = $header && $owned ? get_post_meta( (int) $post->ID, self::WRITTEN_META, true ) : array();
			$by_id[ (int) $post->ID ] = $i;
			$existing[ $i ]           = array(
				'key'       => $this->target_key( 'post_type' === $post->type ? (int) $post->object_id : 0, (string) $post->url ),
				'label'     => $this->label_text( (string) $post->title ),
				'children'  => array(),
				'owned'     => $owned,
				'matchable' => $marked ? $owned : true,
				'record'    => is_array( $record ) && is_string( $record['path'] ?? null ) ? $record['path'] : null,
				'ref'       => $post,
			);
		}
		// wp_get_nav_menu_items() returns menu_order ascending, so each
		// level's children are collected in their current order.
		$roots = array();
		foreach ( $old as $i => $post ) {
			$parent = (int) $post->menu_item_parent;
			if ( $parent > 0 && isset( $by_id[ $parent ] ) ) {
				$existing[ $by_id[ $parent ] ]['children'][] = $i;
			} else {
				$roots[] = $i;
			}
		}

		$desired = array();
		$top     = $this->item_nodes( $items, $desired );
		$d_to_e  = $this->match_nodes( $existing, $desired );

		// A design item the last import wrote that the menu no longer holds
		// was removed by a person, and stays removed.
		$skip   = array();
		$listed = $header ? get_term_meta( $menu_id, self::HEADER_ITEMS_META, true ) : array();
		if ( is_array( $listed ) && $listed !== array() ) {
			$listed = array_fill_keys( array_map( 'strval', $listed ), true );
			foreach ( $desired as $d => $want ) {
				if ( ! isset( $d_to_e[ $d ] ) && isset( $listed[ (string) $want['path'] ] ) ) {
					$skip[ $d ] = true;
				}
			}
		}

		$m = array(
			'existing' => $existing,
			'desired'  => $desired,
			'd_to_e'   => $d_to_e,
			'e_to_d'   => array_flip( $d_to_e ),
			'skip'     => $skip,
			'header'   => $header,
		);

		return array(
			'tree' => $this->merge_level( $roots, $top, $m ),
			'm'    => $m,
		);
	}

	/**
	 * Every published item of a menu, in menu order — the invalid ones too.
	 *
	 * wp_get_nav_menu_items() leaves out, everywhere but wp-admin, an item
	 * whose page is no longer published (`_invalid`): outside wp-admin — an
	 * import from WP-CLI, a REST request, cron — the item of a crawled page
	 * that was since trashed did not exist for the merge. It was neither
	 * matched nor removed, the design's item was added again beside it, and
	 * every such re-import left one more invalid item in the menu. Read here
	 * the way core reads them before that filter, so the merge sees them:
	 * matched, an item is pointed at the design's target again.
	 *
	 * @return array<int, \WP_Post>
	 */
	private static function menu_items( int $menu_id ): array {
		$menu = get_term( $menu_id, 'nav_menu' );
		if ( ! $menu instanceof \WP_Term ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'              => 'nav_menu_item',
				'post_status'            => 'publish',
				'order'                  => 'ASC',
				'orderby'                => 'menu_order',
				'nopaging'               => true,
				'update_menu_item_cache' => true,
				'tax_query'              => array(
					array(
						'taxonomy' => 'nav_menu',
						'field'    => 'term_taxonomy_id',
						'terms'    => (int) $menu->term_taxonomy_id,
					),
				),
			)
		);

		return array_values( array_filter( array_map( 'wp_setup_nav_menu_item', $posts ), static fn( $p ): bool => $p instanceof \WP_Post ) );
	}

	/**
	 * Write a merged tree into a classic menu, depth first.
	 *
	 * Positions run 1..N across the whole menu in display order, the way
	 * nav-menus.php saves a menu. The rebuild numbered each level from 1,
	 * which renders the same but collides with a person's item as soon as a
	 * menu mixes the two; one sequence keeps the merged order unambiguous.
	 *
	 * @param array<int, array{d:?int, e:?int, children:array}> $tree
	 * @param array<string, array>                               $m
	 */
	private function classic_write( int $menu_id, array $tree, int $parent_id, array $m, int &$position ): void {
		foreach ( $tree as $node ) {
			++$position;
			$old = null !== $node['e'] ? $m['existing'][ $node['e'] ]['ref'] : null;

			if ( null === $node['d'] ) {
				// A person's item: never updated, only re-seated — a new parent
				// when the item it hung under was removed, a new position so
				// it keeps its place among the design's items.
				$id = (int) $old->ID;
				if ( (int) $old->menu_item_parent !== $parent_id ) {
					update_post_meta( $id, '_menu_item_menu_item_parent', (string) $parent_id );
				}
				if ( (int) $old->menu_order !== $position ) {
					wp_update_post(
						array(
							'ID'         => $id,
							'menu_order' => $position,
						)
					);
				}
				$this->classic_write( $menu_id, $node['children'], $id, $m, $position );
				continue;
			}

			$want    = $m['desired'][ $node['d'] ];
			$item    = $want['item'];
			$page_id = (int) ( $item['page_id'] ?? 0 );
			$args    = array(
				// Slashed: wp_insert_post() unslashes the title it is handed.
				'menu-item-title'     => wp_slash( (string) $item['label'] ),
				'menu-item-status'    => 'publish',
				'menu-item-position'  => $position,
				'menu-item-parent-id' => $parent_id,
			);
			if ( $old instanceof \WP_Post ) {
				/*
				 * wp_update_nav_menu_item() resets every field it is not handed
				 * to its default (wp-includes/nav-menu.php:437-456), so the
				 * fields a person edits in Appearance > Menus are carried
				 * forward. Description and attr-title are expected slashed
				 * (nav-menu.php:406) and are read back raw, hence wp_slash().
				 */
				$args['menu-item-description'] = wp_slash( (string) $old->description );
				$args['menu-item-attr-title']  = wp_slash( (string) $old->attr_title );
				$args['menu-item-target']      = (string) $old->target;
				$args['menu-item-classes']     = implode( ' ', array_filter( array_map( 'strval', (array) $old->classes ) ) );
				$args['menu-item-xfn']         = (string) $old->xfn;
			}
			if ( $page_id > 0 ) {
				$args['menu-item-type']      = 'post_type';
				$args['menu-item-object']    = 'page';
				$args['menu-item-object-id'] = $page_id;
			} else {
				$args['menu-item-type'] = 'custom';
				$args['menu-item-url']  = $item['url'];
			}
			$written = $this->design_fields( $item, $old, $args );
			if ( ! empty( $m['header'] ) ) {
				$written = $this->header_fields( $want, $old, $args ) + $written;
			}

			$saved = wp_update_nav_menu_item( $menu_id, $old instanceof \WP_Post ? (int) $old->ID : 0, $args );
			if ( ! is_wp_error( $saved ) && $saved ) {
				$id = (int) $saved;
				update_post_meta( $id, self::ITEM_META, '1' );
				if ( $written !== array() ) {
					// update_metadata() unslashes, recursively.
					update_post_meta( $id, self::WRITTEN_META, wp_slash( $written ) );
				}
			} else {
				$id = $old instanceof \WP_Post ? (int) $old->ID : 0;
			}
			// A new item that failed to save has no ID: what nests under it
			// goes one level up rather than being dropped.
			$this->classic_write( $menu_id, $node['children'], $id > 0 ? $id : $parent_id, $m, $position );
		}
	}

	/**
	 * A header menu item's label and link, three-way like design_fields():
	 * the design's when the item still holds what this factory wrote last
	 * time (or it is new, or it was written before that was recorded), the
	 * person's when they changed it in Appearance > Menus. The header is
	 * edited there; a re-import of its design restyles it without undoing
	 * the edits.
	 *
	 * @param array<string, mixed> $want The design node (item_nodes()).
	 * @param \WP_Post|null        $old  The menu item it updates.
	 * @param array<string, mixed> $args Receives the fields.
	 * @return array<string, string> What to record: title and target as written, and the design identity.
	 */
	private function header_fields( array $want, $old, array &$args ): array {
		$item    = (array) $want['item'];
		$written = array(
			'title' => (string) ( $item['label'] ?? '' ),
			'key'   => (string) $want['key'],
			'path'  => (string) $want['path'],
		);
		if ( ! $old instanceof \WP_Post ) {
			return $written;
		}
		$record = get_post_meta( (int) $old->ID, self::WRITTEN_META, true );
		$record = is_array( $record ) ? $record : array();

		// The stored title, compared as text: kses may have written `&` as
		// `&amp;` for an importer without unfiltered_html.
		if ( isset( $record['title'] ) && $this->label_text( (string) $old->post_title ) !== $this->label_text( (string) $record['title'] ) ) {
			$args['menu-item-title'] = wp_slash( (string) $old->post_title );
			$written['title']        = (string) $record['title'];
		}

		$have = $this->target_key( 'post_type' === $old->type ? (int) $old->object_id : 0, (string) $old->url );
		if ( isset( $record['key'] ) && $have !== (string) $record['key'] ) {
			// Retargeted by a person: every field of the target stays theirs
			// (wp_update_nav_menu_item() resets what it is not handed).
			unset( $args['menu-item-url'], $args['menu-item-object'], $args['menu-item-object-id'] );
			$args['menu-item-type'] = (string) $old->type;
			if ( 'custom' === $old->type ) {
				$args['menu-item-url'] = (string) $old->url;
			} else {
				$args['menu-item-object']    = (string) $old->object;
				$args['menu-item-object-id'] = (int) $old->object_id;
			}
			$written['key'] = (string) $record['key'];
		}

		return $written;
	}

	/**
	 * Put the design's own item fields into $args, three-way.
	 *
	 * A field the design item does not carry is left to what classic_write()
	 * carried forward, exactly as before (the old callers pass none). One it
	 * carries goes in when the item is new, when the item still holds what
	 * this factory wrote last time, or — for an item written before that was
	 * recorded — when the item holds nothing; otherwise a person changed it
	 * and it is kept.
	 *
	 * @param array<string, mixed>  $item The design item.
	 * @param \WP_Post|null         $old  The menu item it updates.
	 * @param array<string, mixed>  $args Receives the fields.
	 * @return array<string, string> What was written, to record.
	 */
	private function design_fields( array $item, $old, array &$args ): array {
		$written = array();
		$record  = $old instanceof \WP_Post ? get_post_meta( (int) $old->ID, self::WRITTEN_META, true ) : array();
		$record  = is_array( $record ) ? $record : array();
		foreach ( self::DESIGN_FIELDS as $field => $arg ) {
			if ( ! array_key_exists( $field, $item ) ) {
				continue;
			}
			$want = (string) $item[ $field ];
			if ( $old instanceof \WP_Post ) {
				$have = 'xfn' === $field ? (string) $old->xfn : (string) ( $old->{$field} ?? '' );
				if ( 'description' === $field ) {
					// ->description is trimmed to 200 words for display; the
					// stored field is the post content.
					$have = (string) $old->post_content;
				}
				$ours = array_key_exists( $field, $record ) ? $have === (string) $record[ $field ] : $have === '';
				if ( ! $ours ) {
					$written[ $field ] = (string) ( $record[ $field ] ?? '' );
					continue;
				}
			}
			// Description and title attribute are expected slashed
			// (nav-menu.php), the others are not.
			$args[ $arg ]      = in_array( $field, array( 'description', 'attr_title' ), true ) ? wp_slash( $want ) : $want;
			$written[ $field ] = $want;
		}

		return $written;
	}
}

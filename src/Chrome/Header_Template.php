<?php
/**
 * A converted design's header, read as a template with menu slots.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Chrome;

/**
 * The design supplies the header's structure and styling; its CONTENT comes
 * from Appearance > Menus. This reads the header's block markup — what
 * Structure_Repository saved as the header part, every element a block with
 * its dxaiCss — and finds the places a menu fills:
 *
 * - every navigation list (the desktop bar AND the mobile drawer; both are
 *   filled from `primary-navigation`), with each dropdown's trigger and
 *   panel, and deeper levels inside a panel;
 * - the call-to-action links outside the nav (`header-actions`), with the
 *   eyebrow line beside a button, which a menu item carries as its
 *   description;
 * - the text items of a strip above the bar (`header-top`);
 * - every logo, which the Site Logo fills.
 *
 * Each slot keeps its items as POSITIONAL prototypes: the menu's item i is
 * drawn with the markup the design gave its item i, and an item the design
 * did not have reuses the most common one. A design whose items differ — a
 * bolder first trust item, a last mobile link with no rule under it, a
 * rating with stars — keeps each look. Nothing here decides what anything
 * looks like; the renderer only ever clones what the design drew.
 *
 * The markup itself is kept byte for byte (Style_Rules collects the header's
 * dxs- rules from it), and slots are addressed by block path into
 * parse_blocks() of it: [] is a virtual root holding the top-level blocks,
 * [0, 2] the third child of the first block.
 */
final class Header_Template {

	/**
	 * Site option holding the site's header: the design installed last
	 * (Header_Menus::install()), whose menus the theme locations hold.
	 */
	public const OPTION = 'dxai_ui_site_header';

	/**
	 * Option prefix (plus the design's scope id) holding each installed
	 * design's own header.
	 *
	 * A converted page renders the header of ITS design, not the site's. Its
	 * stylesheet is scoped to its own page (`.dxai-ui--{scope}`, Page_Scope):
	 * the responsive show/hide of the desktop and mobile rows and the hover
	 * rules its header needs are in it, and another design's header markup
	 * matches none of them (`dxai-sh-16` is a different element in each
	 * design). So importing a second design must not swap the header of the
	 * first design's pages; each keeps its own, filled from its own menus,
	 * while the theme locations follow the brand (the last import).
	 * Not autoloaded: only that design's pages read it.
	 */
	public const SCOPE_OPTION = 'dxai_ui_site_header_';

	/**
	 * Shape of the stored spec. A spec stored by another version is read
	 * again from its own markup (stored()), so an update of this reader never
	 * leaves a site with a header it misreads or none at all.
	 */
	public const VERSION = 3;

	public const NAV     = 'primary-navigation';
	public const TOP     = 'header-top';
	public const ACTIONS = 'header-actions';

	/** Leaf kinds a navigation list, a top strip and an actions row hold. */
	private const NAV_ITEMS    = array( 'link', 'dropdown' );
	private const TOP_ITEMS    = array( 'text', 'link' );
	private const ACTION_ITEMS = array( 'link' );

	/** Kinds a search for containers does not descend into. */
	private const OPAQUE = array( 'link', 'dropdown', 'logo', 'text', 'button', 'sep', 'image' );

	/** @var array<string, mixed> The virtual root: the top-level blocks as its children. */
	private array $root = array();

	/**
	 * Read a header's block markup into a template spec.
	 *
	 * @return array{version:int, markup:string, hash:string, slots:array, logos:array, trees:array<string, array>}
	 */
	public static function build( string $markup ): array {
		return ( new self() )->read( $markup );
	}

	/**
	 * The site's header — the design installed last — or null when none is
	 * installed.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function stored(): ?array {
		return self::load( self::OPTION );
	}

	/**
	 * The header a design's pages render: that design's own, else the
	 * site's (a page of no installed design — an ordinary page someone put
	 * the block on, or a design converted before headers came from menus).
	 *
	 * @param int $scope The design's scope id (Page_Scope::META), 0 for none.
	 * @return array<string, mixed>|null
	 */
	public static function for_scope( int $scope ): ?array {
		return self::scoped( $scope ) ?? self::stored();
	}

	/**
	 * The header a post renders: its design scope's (for_scope()).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function for_post( int $post_id ): ?array {
		return self::for_scope( $post_id > 0 ? (int) get_post_meta( $post_id, \DXAI_UI\Structures\Page_Scope::META, true ) : 0 );
	}

	/**
	 * The header installed for exactly this design scope, or null — no
	 * fallback to the site's.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function scoped( int $scope ): ?array {
		if ( $scope < 1 ) {
			return null;
		}
		$site = self::stored();
		if ( null !== $site && self::scope_of( $site ) === $scope ) {
			return $site;
		}

		return self::load( self::SCOPE_OPTION . $scope );
	}

	/**
	 * Whether $spec is the site's header: the one the theme locations were
	 * assigned for, which reads a location's menu before its own.
	 *
	 * @param array<string, mixed> $spec
	 */
	public static function is_site( array $spec ): bool {
		$site = self::stored();

		return null !== $site && (string) ( $site['hash'] ?? '' ) === (string) ( $spec['hash'] ?? '' ) && self::scope_of( $site ) === self::scope_of( $spec );
	}

	/**
	 * The design scope a spec was installed for (0: none recorded).
	 *
	 * @param array<string, mixed> $spec
	 */
	public static function scope_of( array $spec ): int {
		return (int) ( $spec['source']['scope_id'] ?? 0 );
	}

	/**
	 * One stored spec, read again from its own markup when an earlier
	 * version of this reader wrote it (what install() recorded — menus,
	 * logo, source — is kept).
	 *
	 * @return array<string, mixed>|null
	 */
	private static function load( string $option ): ?array {
		/*
		 * The re-read is remembered for the request, keyed by site and option
		 * as well as by markup: under switch_to_blog() two sites can hold the
		 * same markup with different menus, logo and source.
		 */
		static $upgraded = array();
		$spec = get_option( $option );
		if ( ! is_array( $spec ) || ! is_string( $spec['markup'] ?? null ) || $spec['markup'] === '' ) {
			return null;
		}
		if ( (int) ( $spec['version'] ?? 0 ) === self::VERSION ) {
			return $spec;
		}
		$key = get_current_blog_id() . ':' . $option . ':' . md5( $spec['markup'] );
		if ( ! isset( $upgraded[ $key ] ) ) {
			$upgraded[ $key ] = array_merge( $spec, self::build( $spec['markup'] ) );
			self::save_option( $option, $upgraded[ $key ], self::OPTION === $option );
		}

		return $upgraded[ $key ];
	}

	/**
	 * Store a design's header: as the site's header, and as that design's own
	 * (SCOPE_OPTION), so its pages keep it after another design is installed.
	 *
	 * @param array<string, mixed> $spec
	 * @param bool                 $site Whether it becomes the site's header too; false
	 *                                   stores it as its design's own only (a one-page
	 *                                   import while another design is the site's header).
	 */
	public static function store( array $spec, bool $site = true ): void {
		if ( $site ) {
			self::save_option( self::OPTION, $spec, true );
		}
		$scope = self::scope_of( $spec );
		if ( $scope > 0 ) {
			self::save_option( self::SCOPE_OPTION . $scope, $spec, false );
		}
	}

	/**
	 * @param array<string, mixed> $spec
	 */
	private static function save_option( string $option, array $spec, bool $autoload ): void {
		/*
		 * The site's header is autoloaded: every page holding the header
		 * block reads it. update_option() neither unslashes nor strips, and the
		 * markup's own attribute JSON holds backslash escapes (`--`
		 * in every var(--…)) that must survive exactly, so the value goes in
		 * as it is.
		 */
		update_option( $option, $spec, $autoload );
	}

	/**
	 * The virtual root of a markup string: parse_blocks() output as the
	 * children of one block, so every real block has a path.
	 *
	 * @return array<string, mixed>
	 */
	public static function root_of( string $markup ): array {
		$blocks = parse_blocks( $markup );

		return array(
			'blockName'    => null,
			'attrs'        => array(),
			'innerBlocks'  => $blocks,
			'innerHTML'    => '',
			'innerContent' => array_fill( 0, count( $blocks ), null ),
		);
	}

	/**
	 * @return array{version:int, markup:string, hash:string, slots:array, logos:array, trees:array<string, array>}
	 */
	private function read( string $markup ): array {
		$this->root = self::root_of( $markup );

		// Every <header>, in document order: H2O Away draws its desktop and
		// its mobile header as two, and the mobile one holds the drawer.
		$headers = $this->find_all( array(), static fn( array $b ): bool => 'header' === ( self::root_tag( $b )['name'] ?? '' ) );
		$top     = array();
		$bar     = array();

		if ( $headers !== array() ) {
			// What precedes the first <header> along its ancestry is a strip
			// above the bar: Semper Dry's desktop and mobile trust bars sit
			// beside the <header> inside its sticky wrapper.
			$header = $headers[0];
			$depth  = count( $header );
			for ( $d = 0; $d < $depth; $d++ ) {
				$parent = array_slice( $header, 0, $d );
				$node   = $this->node( $parent );
				foreach ( self::kids( $node ) as $j => $kid ) {
					if ( $j >= $header[ $d ] ) {
						break;
					}
					if ( self::kind( $kid ) !== 'none' ) {
						$top[] = array_merge( $parent, array( $j ) );
					}
				}
			}
			foreach ( $headers as $header ) {
				$bar = array_merge( $bar, $this->regions( $header ) );
			}
		} else {
			$bar = $this->regions( array() );
		}

		// A leading region with no logo, nav or dropdown in it is a strip
		// above the bar, whichever element holds it.
		while ( $bar !== array() && ! $this->has_chrome( $bar[0] ) && $this->text_groups( $bar[0] ) !== array() ) {
			$top[] = array_shift( $bar );
		}

		$slots   = array();
		$navs    = array();
		$runs    = array();
		$actions = array();

		foreach ( $bar as $region ) {
			foreach ( $this->find_all( $region, static fn( array $b ): bool => 'nav' === ( self::root_tag( $b )['name'] ?? '' ) ) as $nav ) {
				$navs[] = $nav;
			}
		}
		$found = array();
		foreach ( $navs as $nav ) {
			$best = $this->best_run( $this->node( $nav ), self::NAV_ITEMS, true );
			if ( null !== $best ) {
				$found[] = array(
					'nav'  => $nav,
					'path' => array_merge( $nav, $best['path'] ),
					'run'  => $best['run'],
				);
			}
		}
		if ( $navs === array() ) {
			// No <nav>: the bar's longest run of links is the navigation.
			$longest = null;
			foreach ( $bar as $region ) {
				$best = $this->best_run( $this->node( $region ), self::NAV_ITEMS );
				if ( null !== $best && count( $best['run']['items'] ) >= 3 && ( null === $longest || count( $best['run']['items'] ) > count( $longest['run']['items'] ) ) ) {
					$longest = array(
						'nav'  => array(),
						'path' => array_merge( $region, $best['path'] ),
						'run'  => $best['run'],
					);
				}
			}
			if ( null !== $longest ) {
				$found[] = $longest;
			}
		}
		foreach ( $found as $run ) {
			$group = array(
				'path'  => $run['path'],
				'items' => $run['run']['items'],
				'seps'  => $run['run']['seps'],
				'prune' => false,
			);
			// A call button at the end of the link row (Arcus Restoration's
			// "Call Now: (615) 640-1075", H2O Away's drawer) is an action.
			$call = $this->split_calls( $group );
			if ( null !== $call ) {
				$actions[] = array(
					'region' => $run['path'],
					'groups' => array( $call ),
				);
			}
			$runs[]  = $run['path'];
			$slots[] = $this->slot( self::NAV, array( $group ), $this->root, $run['nav'] !== array() ? $run['nav'] : $run['path'] );
		}

		foreach ( $top as $region ) {
			$groups = $this->text_groups( $region );
			if ( $groups !== array() ) {
				$slots[] = $this->slot( self::TOP, $groups, $this->root, $region );
			}
		}

		/*
		 * Logos anywhere in the bar, a <nav> holding the whole bar included
		 * (Arcus Restoration's): a logo is never a menu item, so looking
		 * inside the nav cannot mistake one for the other.
		 */
		$logos = array();
		foreach ( $bar as $region ) {
			foreach ( $this->find_all( $region, static fn( array $b ): bool => self::kind( $b ) === 'logo' ) as $path ) {
				$node    = $this->node( $path );
				$img     = self::first_img( self::html( $node ) );
				$picture = null === $img ? self::picture_in( $node ) : null;
				$logos[] = array(
					'path' => $path,
					'src'  => $img['src'] ?? $picture['src'] ?? '',
					'alt'  => $img['alt'] ?? $picture['alt'] ?? '',
				) + ( (int) ( $picture['id'] ?? 0 ) > 0 ? array( 'id' => (int) $picture['id'] ) : array() );
			}
		}

		/*
		 * Call-to-action links outside the navigation's own link rows — in a
		 * <nav> that holds the whole bar too (Arcus Restoration's mobile
		 * "Call" sits there beside the logo). A <nav> with no logo in it is
		 * the menu and nothing else: a link in it the runs did not take is a
		 * nav item the reader could not see (checks()), never a button.
		 */
		$skip = $runs;
		foreach ( $navs as $nav ) {
			$holds_logo = false;
			foreach ( $logos as $logo ) {
				$holds_logo = $holds_logo || self::within( (array) $logo['path'], $nav );
			}
			if ( ! $holds_logo ) {
				$skip[] = $nav;
			}
		}
		foreach ( $bar as $region ) {
			$groups = $this->action_groups( $region, $skip );
			if ( $groups !== array() ) {
				$actions[] = array(
					'region' => $region,
					'groups' => $groups,
				);
			}
		}
		// In the order of their first buttons: the desktop button before the
		// mobile one wins a tie for the primary slot (rank()), so the menu
		// holds the design's full wording ("Call Now: (615) 640-1075", not
		// the mobile "Call").
		$first = static fn( array $a ): array => array_merge( (array) $a['groups'][0]['path'], array( (int) min( (array) $a['groups'][0]['items'] ) ) );
		usort( $actions, static fn( array $a, array $b ): int => self::path_cmp( $first( $a ), $first( $b ) ) );
		foreach ( $actions as $action ) {
			$slots[] = $this->slot( self::ACTIONS, $action['groups'], $this->root, $action['region'] );
		}

		$slots = $this->rank( $slots );
		$trees = array();
		foreach ( $slots as $slot ) {
			if ( $slot['primary'] ) {
				$trees[ $slot['location'] ] = self::tree( $slot['positions'], $slot['location'] );
			}
		}
		foreach ( $slots as $i => $slot ) {
			if ( ! empty( $slot['condensed'] ) || ! empty( $slot['diverged'] ) ) {
				$slots[ $i ]['design_sig'] = self::tree_sig( $trees[ $slot['location'] ] ?? array() );
			}
		}

		return array(
			'version' => self::VERSION,
			'markup'  => $markup,
			'hash'    => md5( $markup ),
			'slots'   => $slots,
			'logos'   => $logos,
			'trees'   => $trees,
			'checks'  => $this->checks( $slots, $navs, $logos ),
		);
	}

	/**
	 * The regions of a bar: the first level below $path (a <header>, or the
	 * whole markup) that splits into more than one block — a desktop row, a
	 * mobile row, a drawer.
	 *
	 * @param array<int, int> $path
	 * @return array<int, array<int, int>>
	 */
	private function regions( array $path ): array {
		while ( true ) {
			$real = $this->real_kids( $path );
			if ( count( $real ) === 1 && self::kind( $this->node( array_merge( $path, array( $real[0] ) ) ) ) === 'container' ) {
				$path[] = $real[0];
				continue;
			}
			break;
		}
		$out = array();
		foreach ( $this->real_kids( $path ) as $i ) {
			$out[] = array_merge( $path, array( $i ) );
		}

		return $out;
	}

	/**
	 * Take the tel: / mailto: links off the end of a navigation run, as an
	 * actions group of their own (with the line of text right after them as
	 * the eyebrow). A phone button in the link row is the header's call to
	 * action, and a person looks for it among the header buttons, not among
	 * the menu's pages. The run keeps at least one item.
	 *
	 * @param array<string, mixed> $group The run; its items and seps are trimmed in place.
	 * @return array<string, mixed>|null The actions group.
	 */
	private function split_calls( array &$group ): ?array {
		$kids  = self::kids( $this->node( (array) $group['path'] ) );
		$items = (array) $group['items'];
		$calls = array();
		while ( count( $items ) > 1 ) {
			$last = (int) end( $items );
			$href = strtolower( (string) ( self::root_tag( $kids[ $last ] )['attrs']['href'] ?? '' ) );
			if ( self::kind( $kids[ $last ] ) !== 'link' || ! preg_match( '/^(tel|mailto):/', $href ) ) {
				break;
			}
			array_unshift( $calls, array_pop( $items ) );
		}
		if ( $calls === array() ) {
			return null;
		}
		$keep            = max( $items );
		$group['items']  = $items;
		$group['seps']   = array_values( array_filter( (array) $group['seps'], static fn( int $s ): bool => $s < $keep ) );
		$call            = array(
			'path'  => $group['path'],
			'items' => $calls,
			'seps'  => array(),
			'prune' => false,
		);
		$next            = null;
		foreach ( $kids as $k => $kid ) {
			if ( $k > max( $calls ) && self::kind( $kid ) !== 'none' ) {
				$next = $k;
				break;
			}
		}
		if ( null !== $next && self::kind( $kids[ $next ] ) === 'text' ) {
			$call['eyebrow'] = array(
				'item'  => max( $calls ),
				'child' => $next,
			);
		}

		return $call;
	}

	/**
	 * What says whether the slots found ARE the header's content, beyond
	 * rendering it back byte for byte (Header_Menus::verify() reads them):
	 *
	 * - `nav_items`: top-level items of the primary navigation. Fewer than
	 *   two is a breadcrumb trail or a page hero's back link, not a menu.
	 * - `breadcrumb`: the navigation is a trail (an `ol`, a `breadcrumb`
	 *   label, » › / between its items) — a job page's "Careers / Role",
	 *   a portfolio page's "DevriX » Portfolio » …".
	 * - `unread_links`: links inside a <nav> that no slot and no logo holds.
	 *   A menu the reader did not see — nav items in a box each (Brand Polish
	 *   Pass), a mega menu saved as one list item's HTML (ARA Guide) — would
	 *   stay static while the menu beside it changed.
	 *
	 * @param array<int, array<string, mixed>> $slots
	 * @param array<int, array<int, int>>      $navs
	 * @param array<int, array<string, mixed>> $logos
	 * @return array{nav_items:int, breadcrumb:bool, nav_links:int, unread_links:int}
	 */
	private function checks( array $slots, array $navs, array $logos ): array {
		$main = null;
		foreach ( $slots as $slot ) {
			if ( $slot['location'] === self::NAV && $slot['primary'] ) {
				$main = $slot;
			}
		}

		$breadcrumb = false;
		if ( null !== $main ) {
			foreach ( (array) $main['groups'] as $group ) {
				$container = $this->node( (array) $group['path'] );
				$kids      = self::kids( $container );
				if ( 'ol' === ( self::root_tag( $container )['name'] ?? '' ) ) {
					$breadcrumb = true;
				}
				foreach ( array_merge( (array) $group['seps'], array_keys( $kids ) ) as $k ) {
					if ( isset( $kids[ $k ] ) && self::kind( $kids[ $k ] ) === 'sep' && preg_match( '/^[»›\/>→]$/u', Header_Html::visible_text( self::html( $kids[ $k ] ) ) ) ) {
						$breadcrumb = true;
					}
				}
			}
			$label = self::root_tag( $this->node( (array) $main['region'] ) );
			if ( preg_match( '/breadcrumb/i', (string) ( $label['attrs']['aria-label'] ?? '' ) . ' ' . (string) ( $label['attrs']['class'] ?? '' ) ) ) {
				$breadcrumb = true;
			}
		}

		$total   = 0;
		$covered = 0;
		foreach ( $navs as $nav ) {
			$total += self::link_tags( self::html( $this->node( $nav ) ) );
			foreach ( $slots as $slot ) {
				foreach ( (array) $slot['positions'] as $p ) {
					$path = array_merge( (array) $slot['groups'][ (int) $p['group'] ]['path'], array( (int) $p['child'] ) );
					if ( self::within( $path, $nav ) ) {
						$covered += self::link_tags( self::html( $this->node( $path ) ) );
					}
				}
			}
			foreach ( $logos as $logo ) {
				if ( self::within( (array) $logo['path'], $nav ) ) {
					$covered += self::link_tags( self::html( $this->node( (array) $logo['path'] ) ) );
				}
			}
		}

		return array(
			'nav_items'    => null === $main ? 0 : count( (array) $main['positions'] ),
			'breadcrumb'   => $breadcrumb,
			'nav_links'    => $total,
			'unread_links' => max( 0, $total - $covered ),
		);
	}

	/**
	 * How many links $html opens.
	 */
	private static function link_tags( string $html ): int {
		return (int) preg_match_all( '/<a[\s>]/i', $html );
	}

	/**
	 * Document order of two block paths.
	 *
	 * @param array<int, int> $a
	 * @param array<int, int> $b
	 */
	private static function path_cmp( array $a, array $b ): int {
		$n = min( count( $a ), count( $b ) );
		for ( $i = 0; $i < $n; $i++ ) {
			if ( $a[ $i ] !== $b[ $i ] ) {
				return $a[ $i ] <=> $b[ $i ];
			}
		}

		return count( $a ) <=> count( $b );
	}

	/**
	 * What a menu's items say, for telling whether a menu is still exactly
	 * the design's: each item's label and link, nested. The same for a
	 * Header_Template tree and for Header_Menus nodes (node_sig()).
	 *
	 * @param array<int, array<string, mixed>> $tree
	 */
	public static function tree_sig( array $tree ): string {
		$flat = static function ( array $rows ) use ( &$flat ): array {
			$out = array();
			foreach ( $rows as $row ) {
				// The URL as a menu item stores it (esc_url_raw()).
				$out[] = array( Header_Html::norm( (string) ( $row['label'] ?? '' ) ), esc_url_raw( trim( (string) ( $row['url'] ?? '' ) ) ), $flat( (array) ( $row['children'] ?? array() ) ) );
			}

			return $out;
		};

		return md5( (string) wp_json_encode( $flat( $tree ) ) );
	}

	/**
	 * tree_sig() of menu nodes (Header_Menus::items_tree()).
	 *
	 * @param array<int, array<string, mixed>> $nodes
	 */
	public static function node_sig( array $nodes ): string {
		$rows = static function ( array $nodes ) use ( &$rows ): array {
			$out = array();
			foreach ( $nodes as $node ) {
				$item  = $node['item'] ?? null;
				$out[] = array(
					'label'    => is_object( $item ) ? (string) ( $item->title ?? '' ) : '',
					'url'      => is_object( $item ) ? (string) ( $item->url ?? '' ) : '',
					'children' => $rows( (array) ( $node['children'] ?? array() ) ),
				);
			}

			return $out;
		};

		return self::tree_sig( $rows( $nodes ) );
	}

	/**
	 * One slot: its groups (the containers whose child runs are the items)
	 * and a position per item.
	 *
	 * @param array<int, array<string, mixed>> $groups
	 * @param array<string, mixed>             $base   Block the group paths start from.
	 * @param array<int, int>                  $region Where the slot lives (reported only).
	 * @return array<string, mixed>
	 */
	private function slot( string $location, array $groups, array $base, array $region ): array {
		$positions = array();
		foreach ( $groups as $g => $group ) {
			$container = self::node_in( $base, $group['path'] );
			$kids      = self::kids( $container );
			if ( ! isset( $group['sep'] ) ) {
				$groups[ $g ]['sep'] = $group['seps'][0] ?? null;
			}
			foreach ( $group['items'] as $child ) {
				$block    = $kids[ $child ];
				$position = self::kind( $block ) === 'dropdown' ? $this->dropdown_position( $block ) : self::leaf_position( $block );
				$position['group'] = $g;
				$position['child'] = $child;
				$position['eyebrow'] = null;
				if ( isset( $group['eyebrow'] ) && (int) $group['eyebrow']['item'] === $child ) {
					$position['eyebrow'] = array(
						'child' => (int) $group['eyebrow']['child'],
						'after' => (int) $group['eyebrow']['child'] > $child,
						'label' => Header_Html::visible_text( (string) Header_Html::inner( self::chunk( $kids[ (int) $group['eyebrow']['child'] ] ), 'root' ) ),
					);
					// The line beside a button is its description already.
					$position['desc']    = '';
					$position['desc_at'] = null;
				}
				$positions[] = $position;
			}
			unset( $groups[ $g ]['eyebrow'] );
		}
		/*
		 * In document order, which is the order a person reads the items in
		 * and the order the menu lists them. Groups are found container by
		 * container, outer first: Arcus Restoration's "A+ BBB Accredited"
		 * sits in the outer row after the box holding "350+ Reviews", and
		 * would otherwise come before it in the menu.
		 */
		usort(
			$positions,
			static fn( array $a, array $b ): int => self::path_cmp(
				array_merge( (array) $groups[ (int) $a['group'] ]['path'], array( (int) $a['child'] ) ),
				array_merge( (array) $groups[ (int) $b['group'] ]['path'], array( (int) $b['child'] ) )
			)
		);

		return array(
			'location'  => $location,
			'region'    => $region,
			'primary'   => false,
			'condensed' => false,
			'groups'    => $groups,
			'positions' => $positions,
		);
	}

	/**
	 * A link or text item: where its link and its label are, and what they
	 * hold in the design.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function leaf_position( array $block ): array {
		$kind  = self::kind( $block );
		$root  = self::root_tag( $block );
		$chunk = self::chunk( $block );
		$link  = null;
		$label = null;
		$desc  = null;
		$attrs = array();

		if ( $kind === 'link' ) {
			if ( 'a' === ( $root['name'] ?? '' ) ) {
				$link  = array(
					'path' => array(),
					'el'   => 'root',
				);
				$attrs = (array) ( $root['attrs'] ?? array() );
				$label = self::kids( $block ) === array() ? $link : self::label_block( $block );
				// A link of blocks keeps its description in the next one with words.
				$desc = null === $label || self::kids( $block ) === array() ? null : self::desc_block( $block, $label );
			} else {
				// A paragraph or list item around the one link that holds its text.
				$link  = array(
					'path' => array(),
					'el'   => 'a',
				);
				$label = $link;
				$el    = Header_Html::element( $chunk, 'a' );
				$attrs = null === $el ? array() : Header_Html::attributes( substr( $chunk, $el['open_start'], $el['open_end'] - $el['open_start'] ) );
			}
		} else {
			$label = array(
				'path' => array(),
				'el'   => 'root',
			);
		}

		$text   = '';
		$note   = '';
		$plain  = true;
		$glyphs = false;
		if ( null !== $label ) {
			$host  = self::node_in( $block, $label['path'] );
			$inner = Header_Html::inner( self::chunk( $host ), $label['el'] );
			$text  = null === $inner ? '' : Header_Html::label_text( $inner );
			foreach ( null === $inner ? array() : Header_Html::nodes( $inner ) as $node ) {
				$plain = $plain && ! ( $node['type'] === 'el' && $node['deco'] );
			}
			// A caret or an arrow written into the text is the item's own too.
			$glyphs = null !== $inner && Header_Html::has_glyphs( Header_Html::visible_text( $inner ) );
			$plain  = $plain && ! $glyphs;

			/*
			 * A link whose words are a stack of elements — a title and the line that
			 * describes it, in the link itself or in the one element of it that holds
			 * the words — is two things: the first is the label, the second the
			 * item's description (Description in Appearance > Menus). Each is
			 * written into its own element, so a new label never takes the
			 * description's words and the other way round.
			 */
			$stack = 'link' === $kind && null !== $inner && null === $desc ? Header_Html::stack( $inner ) : null;
			if ( null !== $stack ) {
				$parts  = $stack['parts'];
				$text   = $parts[0]['text'];
				$note   = $parts[1]['text'];
				$glyphs = Header_Html::has_glyphs( $parts[0]['visible'] );
				$plain  = $plain && ! $glyphs;
				$label  = $label + array(
					'part' => 0,
					'via'  => $stack['via'],
				);
				$desc   = array(
					'path' => $label['path'],
					'el'   => $label['el'],
					'part' => 1,
					'via'  => $stack['via'],
				);
			} elseif ( null !== $desc ) {
				$dhost = self::node_in( $block, $desc['path'] );
				$dtext = Header_Html::inner( self::chunk( $dhost ), $desc['el'] );
				$note  = null === $dtext ? '' : Header_Html::label_text( $dtext );
			}
		}
		if ( $text === '' && $kind === 'link' ) {
			// An icon link: its name is its aria-label, and that is what a
			// new label rewrites.
			$text  = Header_Html::norm( (string) ( $attrs['aria-label'] ?? '' ) );
			$label = null;
		}

		return array(
			'kind'     => $kind === 'link' ? 'link' : 'text',
			'link'     => $link,
			'label_at' => $label,
			'label'    => $text,
			'desc_at'  => $note !== '' ? $desc : null,
			'desc'     => $note,
			'url'      => $kind === 'link' ? (string) ( $attrs['href'] ?? '' ) : '#',
			'target'   => (string) ( $attrs['target'] ?? '' ),
			'rel'      => (string) ( $attrs['rel'] ?? '' ),
			'title'    => (string) ( $attrs['title'] ?? '' ),
			'sig'      => self::sig( $block ),
			// No caret, no stars: the look an item the design did not have
			// is best drawn with (Header_Renderer::common()).
			'plain'    => $plain,
			// The label's text starts or ends with a glyph of the design's.
			'glyphs'   => $glyphs,
		);
	}

	/**
	 * A dropdown: its trigger (a link, a button or a text), and its panel as
	 * a slot of its own, relative to the dropdown's block.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private function dropdown_position( array $block ): array {
		$parts = (array) self::dropdown_parts( $block );

		if ( $parts['trigger'] === array() ) {
			// A list item whose own link is the trigger and whose nested list
			// is the panel.
			$chunk    = self::chunk( $block );
			$el       = Header_Html::element( $chunk, 'a' );
			$attrs    = null === $el ? array() : Header_Html::attributes( substr( $chunk, $el['open_start'], $el['open_end'] - $el['open_start'] ) );
			$spec     = array(
				'path' => array(),
				'el'   => 'a',
			);
			$trigger  = array(
				'kind'     => 'link',
				'link'     => $spec,
				'label_at' => $spec,
				'label'    => Header_Html::label_text( (string) Header_Html::inner( $chunk, 'a' ) ),
				'glyphs'   => Header_Html::has_glyphs( Header_Html::visible_text( (string) Header_Html::inner( $chunk, 'a' ) ) ),
				'url'      => (string) ( $attrs['href'] ?? '' ),
				'target'   => (string) ( $attrs['target'] ?? '' ),
				'rel'      => (string) ( $attrs['rel'] ?? '' ),
				'title'    => (string) ( $attrs['title'] ?? '' ),
			);
		} else {
			$trigger_block = self::node_in( $block, $parts['trigger'] );
			if ( self::kind( $trigger_block ) === 'button' || self::kind( $trigger_block ) === 'text' ) {
				$trigger = array(
					'kind'     => 'text',
					'link'     => null,
					'label_at' => array(
						'path' => self::kids( $trigger_block ) === array() ? array() : (array) ( self::label_block( $trigger_block )['path'] ?? array() ),
						'el'   => 'root',
					),
					'label'    => Header_Html::label_text( self::html( $trigger_block ) ),
					'glyphs'   => Header_Html::has_glyphs( Header_Html::visible_text( self::html( $trigger_block ) ) ),
					'url'      => '#',
					'target'   => '',
					'rel'      => '',
					'title'    => '',
				);
			} else {
				$trigger = self::leaf_position( $trigger_block );
			}
		}

		$panel_block = self::node_in( $block, $parts['panel'] );
		$best        = $this->best_run( $panel_block, self::NAV_ITEMS );
		$panel       = null;
		if ( null !== $best ) {
			$panel = $this->slot(
				self::NAV,
				array(
					array(
						'path'  => array_merge( $parts['panel'], $best['path'] ),
						'items' => $best['run']['items'],
						'seps'  => $best['run']['seps'],
						'prune' => false,
					),
				),
				$block,
				$parts['panel']
			);
			foreach ( $panel['positions'] as $i => $p ) {
				$panel['positions'][ $i ]['design']      = $p['label'];
				$panel['positions'][ $i ]['design_desc'] = (string) ( $p['desc'] ?? '' );
			}
		}

		return array(
			'kind'       => 'dropdown',
			'trigger'    => $parts['trigger'],
			'link'       => $trigger['link'],
			'label_at'   => $trigger['label_at'],
			'label'      => $trigger['label'],
			'url'        => $trigger['url'],
			'target'     => $trigger['target'],
			'rel'        => $trigger['rel'],
			'title'      => $trigger['title'],
			'glyphs'     => ! empty( $trigger['glyphs'] ),
			'panel'      => $panel,
			'panel_root' => $parts['panel'],
			'sig'        => self::sig( $block ),
		);
	}

	/**
	 * Mark each location's primary slot — the one whose items the menu is
	 * written from — and give every position the label its menu item has in
	 * the design.
	 *
	 * The primary slot is the richest: the desktop bar with its dropdowns
	 * over the flat mobile drawer. A mirror slot compares a menu label with
	 * the PRIMARY slot's design label at the same position, so the mobile
	 * button that reads "Call Now" while the desktop one reads "Call Now:
	 * (540) 642-2446" keeps its own wording until someone renames the item.
	 * A mirror with fewer positions than its primary is a condensed version
	 * (one mobile trust line for four desktop items), one with more is its
	 * own list (H2O Away's drawer flattens "Resources" into "Financing" and
	 * "FAQs"): either is kept exactly as drawn while the whole menu is the
	 * design's (design_sig, set by read()) and follows the menu once it is
	 * not — a diverged one comparing each item with its own wording.
	 *
	 * The richest slot is the one with the most items, nested panels
	 * counted: the desktop bar's six items and fifteen dropdown links over a
	 * drawer's seven flat links.
	 *
	 * @param array<int, array<string, mixed>> $slots
	 * @return array<int, array<string, mixed>>
	 */
	private function rank( array $slots ): array {
		$primary = array();
		foreach ( $slots as $i => $slot ) {
			$weight = self::weight( $slot['positions'] ) * 1000 + count( $slot['positions'] );
			if ( ! isset( $primary[ $slot['location'] ] ) || $weight > $primary[ $slot['location'] ]['weight'] ) {
				$primary[ $slot['location'] ] = array(
					'index'  => $i,
					'weight' => $weight,
				);
			}
		}
		foreach ( $slots as $i => $slot ) {
			$main                     = $slots[ $primary[ $slot['location'] ]['index'] ];
			$is_primary               = $primary[ $slot['location'] ]['index'] === $i;
			$slots[ $i ]['primary']   = $is_primary;
			$slots[ $i ]['condensed'] = ! $is_primary && count( $slot['positions'] ) < count( $main['positions'] );
			$slots[ $i ]['diverged']  = ! $is_primary && count( $slot['positions'] ) > count( $main['positions'] );
			foreach ( $slot['positions'] as $p => $position ) {
				$twin = $slots[ $i ]['diverged'] ? null : ( $main['positions'][ $p ] ?? null );
				$slots[ $i ]['positions'][ $p ]['design']      = (string) ( $twin['label'] ?? $position['label'] );
				$slots[ $i ]['positions'][ $p ]['design_desc'] = (string) ( $twin['desc'] ?? $position['desc'] ?? '' );
				if ( is_array( $position['eyebrow'] ?? null ) ) {
					// The mobile eyebrow reads as the desktop one's while the
					// item's description is the design's.
					$slots[ $i ]['positions'][ $p ]['eyebrow']['design'] = (string) ( $twin['eyebrow']['label'] ?? $position['eyebrow']['label'] );
				}
			}
		}

		return $slots;
	}

	/**
	 * How many items a list of positions holds, nested panels included.
	 *
	 * @param array<int, array<string, mixed>> $positions
	 */
	private static function weight( array $positions ): int {
		$n = 0;
		foreach ( $positions as $p ) {
			++$n;
			if ( is_array( $p['panel'] ?? null ) ) {
				$n += self::weight( (array) $p['panel']['positions'] );
			}
		}

		return $n;
	}

	/**
	 * The menu a slot's design items make.
	 *
	 * @param array<int, array<string, mixed>> $positions
	 * @return array<int, array<string, mixed>>
	 */
	private static function tree( array $positions, string $location ): array {
		$out = array();
		foreach ( $positions as $p ) {
			$label = (string) $p['label'];
			if ( $label === '' ) {
				continue;
			}
			$row = array(
				'label'       => $label,
				'url'         => (string) $p['url'] !== '' ? (string) $p['url'] : '#',
				'target'      => (string) $p['target'],
				'xfn'         => (string) $p['rel'],
				'attr_title'  => (string) $p['title'],
				'description' => $location === self::ACTIONS && is_array( $p['eyebrow'] ?? null ) ? (string) $p['eyebrow']['label'] : (string) ( $p['desc'] ?? '' ),
				'children'    => is_array( $p['panel'] ?? null ) ? self::tree( (array) $p['panel']['positions'], $location ) : array(),
			);
			$out[] = $row;
		}

		return $out;
	}

	/**
	 * The text items of a strip: every run of text and links in it.
	 *
	 * @param array<int, int> $region
	 * @return array<int, array<string, mixed>>
	 */
	private function text_groups( array $region ): array {
		$node = $this->node( $region );
		if ( in_array( self::kind( $node ), self::TOP_ITEMS, true ) ) {
			// The region is one text item: its parent holds the run.
			$parent = array_slice( $region, 0, -1 );
			$child  = (int) end( $region );

			return array(
				array(
					'path'  => $parent,
					'items' => array( $child ),
					'seps'  => array(),
					'prune' => false,
				),
			);
		}
		$groups = array();
		foreach ( $this->containers( $region ) as $path ) {
			foreach ( self::runs( $this->node( $path ), self::TOP_ITEMS ) as $run ) {
				$groups[] = array(
					'path'  => $path,
					'items' => $run['items'],
					'seps'  => $run['seps'],
					'prune' => $path !== $region && $this->only_run( $path, $run ),
				);
			}
		}

		return $groups;
	}

	/**
	 * The call-to-action runs of one bar region: links outside the nav and
	 * not a logo, with an eyebrow line when a link shares a small container
	 * with one text.
	 *
	 * @param array<int, int>             $region
	 * @param array<int, array<int, int>> $skip The navigation's link rows, and the <nav>s that are only the menu.
	 * @return array<int, array<string, mixed>>
	 */
	private function action_groups( array $region, array $skip ): array {
		foreach ( $skip as $nav ) {
			if ( self::within( $region, $nav ) ) {
				return array();
			}
		}
		$node = $this->node( $region );
		if ( self::kind( $node ) === 'link' ) {
			return array(
				array(
					'path'  => array_slice( $region, 0, -1 ),
					'items' => array( (int) end( $region ) ),
					'seps'  => array(),
					'prune' => false,
				),
			);
		}

		$groups = array();
		foreach ( $this->containers( $region, $skip ) as $path ) {
			$container = $this->node( $path );
			$kids      = self::kids( $container );
			foreach ( self::runs( $container, self::ACTION_ITEMS ) as $run ) {
				$group = array(
					'path'  => $path,
					'items' => $run['items'],
					'seps'  => $run['seps'],
					'prune' => false,
				);
				$real  = array_values( array_filter( array_keys( $kids ), static fn( int $k ): bool => self::kind( $kids[ $k ] ) !== 'none' ) );
				$span  = array_merge( $run['items'], $run['seps'] );
				$rest  = array_values( array_diff( $real, $span ) );
				if ( count( $rest ) === 1 && self::kind( $kids[ $rest[0] ] ) === 'text' ) {
					$first = min( $run['items'] );
					$last  = max( $run['items'] );
					if ( $rest[0] === $last + 1 ) {
						$group['eyebrow'] = array(
							'item'  => $last,
							'child' => $rest[0],
						);
					} elseif ( $rest[0] === $first - 1 ) {
						$group['eyebrow'] = array(
							'item'  => $first,
							'child' => $rest[0],
						);
					}
				}
				$group['prune'] = $path !== $region && ( $rest === array() || isset( $group['eyebrow'] ) );
				$groups[]       = $group;
			}
		}

		return $groups;
	}

	/**
	 * Whether a container's real children are exactly one run.
	 *
	 * @param array<int, int>                          $path
	 * @param array{items:array<int, int>, seps:array<int, int>} $run
	 */
	private function only_run( array $path, array $run ): bool {
		return count( $this->real_kids( $path ) ) === count( $run['items'] ) + count( $run['seps'] );
	}

	/**
	 * Whether a region holds header chrome: a logo, a nav or a dropdown.
	 *
	 * @param array<int, int> $region
	 */
	private function has_chrome( array $region ): bool {
		return null !== $this->find(
			$region,
			static function ( array $b ): bool {
				$kind = self::kind( $b );

				return $kind === 'logo' || $kind === 'dropdown' || 'nav' === ( self::root_tag( $b )['name'] ?? '' );
			}
		);
	}

	/**
	 * The container under $block (itself included) with the longest run of
	 * items, as a path relative to $block.
	 *
	 * @param array<string, mixed> $block
	 * @param array<int, string>   $kinds
	 * @param bool                 $text_rows Whether a list item that is only words counts as an item (runs()).
	 * @return array{path:array<int, int>, run:array{items:array<int, int>, seps:array<int, int>}}|null
	 */
	private function best_run( array $block, array $kinds, bool $text_rows = false ): ?array {
		$best  = null;
		$stack = array( array( $block, array() ) );
		while ( $stack !== array() ) {
			list( $node, $path ) = array_shift( $stack );
			foreach ( self::runs( $node, $kinds, $text_rows ) as $run ) {
				if ( null === $best || count( $run['items'] ) > count( $best['run']['items'] ) ) {
					$best = array(
						'path' => $path,
						'run'  => $run,
					);
				}
			}
			foreach ( self::kids( $node ) as $i => $kid ) {
				if ( ! in_array( self::kind( $kid ), self::OPAQUE, true ) ) {
					$stack[] = array( $kid, array_merge( $path, array( $i ) ) );
				}
			}
		}

		return $best;
	}

	/**
	 * Runs of items among a container's children: consecutive items, with at
	 * most one separator between two of them (Semper Dry's 1px rule between
	 * two trust items). Whitespace between blocks is ignored.
	 *
	 * @param array<string, mixed> $container
	 * @param array<int, string>   $kinds
	 * @param bool                 $text_rows A list item that is only words is an item too: the bar's own
	 *                                        trigger with no panel, not a dropdown's bullet points.
	 * @return array<int, array{items:array<int, int>, seps:array<int, int>}>
	 */
	private static function runs( array $container, array $kinds, bool $text_rows = false ): array {
		$runs    = array();
		$current = null;
		$sep     = null;
		foreach ( self::kids( $container ) as $i => $kid ) {
			$kind = self::kind( $kid );
			if ( $kind === 'none' ) {
				continue;
			}
			/*
			 * A row of the bar that is only words — a trigger whose panel the
			 * design never drew (ARA Guide's "Locations": a button in a list item) —
			 * is still one of its items. Left out it ended the run, and the items
			 * after it were a second run no slot held. Only in the bar: in a
			 * dropdown's panel a run of words is a promo's bullet points.
			 */
			if ( $text_rows && 'text' === $kind && 'li' === ( self::root_tag( $kid )['name'] ?? '' ) ) {
				$kind = 'link';
			}
			if ( in_array( $kind, $kinds, true ) ) {
				if ( null === $current ) {
					$current = array(
						'items' => array(),
						'seps'  => array(),
					);
				} elseif ( null !== $sep ) {
					$current['seps'][] = $sep;
				}
				$current['items'][] = $i;
				$sep                = null;
				continue;
			}
			if ( $kind === 'sep' && null !== $current && null === $sep ) {
				$sep = $i;
				continue;
			}
			if ( null !== $current ) {
				$runs[] = $current;
			}
			$current = null;
			$sep     = null;
		}
		if ( null !== $current ) {
			$runs[] = $current;
		}

		return $runs;
	}

	/**
	 * Every container at or under $path, in document order, skipping leaves
	 * and anything under $skip.
	 *
	 * @param array<int, int>             $path
	 * @param array<int, array<int, int>> $skip
	 * @return array<int, array<int, int>>
	 */
	private function containers( array $path, array $skip = array() ): array {
		foreach ( $skip as $s ) {
			if ( self::within( $path, $s ) ) {
				return array();
			}
		}
		$node = $this->node( $path );
		if ( in_array( self::kind( $node ), self::OPAQUE, true ) || self::kids( $node ) === array() ) {
			return array();
		}
		$out = array( $path );
		foreach ( self::kids( $node ) as $i => $kid ) {
			$out = array_merge( $out, $this->containers( array_merge( $path, array( $i ) ), $skip ) );
		}

		return $out;
	}

	/**
	 * The first block at or under $path matching $test, depth first.
	 *
	 * @param array<int, int> $path
	 * @return array<int, int>|null
	 */
	private function find( array $path, callable $test ): ?array {
		$all = $this->find_all( $path, $test, array(), true );

		return $all[0] ?? null;
	}

	/**
	 * Every block at or under $path matching $test, not descending into a
	 * match or into anything under $skip.
	 *
	 * @param array<int, int>             $path
	 * @param array<int, array<int, int>> $skip
	 * @return array<int, array<int, int>>
	 */
	private function find_all( array $path, callable $test, array $skip = array(), bool $first = false ): array {
		foreach ( $skip as $s ) {
			if ( self::within( $path, $s ) ) {
				return array();
			}
		}
		$node = $this->node( $path );
		if ( $path !== array() && $test( $node ) ) {
			return array( $path );
		}
		$out = array();
		foreach ( self::kids( $node ) as $i => $kid ) {
			$out = array_merge( $out, $this->find_all( array_merge( $path, array( $i ) ), $test, $skip, $first ) );
			if ( $first && $out !== array() ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Whether $path is $ancestor or lies under it.
	 *
	 * @param array<int, int> $path
	 * @param array<int, int> $ancestor
	 */
	private static function within( array $path, array $ancestor ): bool {
		return array_slice( $path, 0, count( $ancestor ) ) === $ancestor;
	}

	/**
	 * Indexes of the children of $path that are blocks, not whitespace.
	 *
	 * @param array<int, int> $path
	 * @return array<int, int>
	 */
	private function real_kids( array $path ): array {
		$out = array();
		foreach ( self::kids( $this->node( $path ) ) as $i => $kid ) {
			if ( self::kind( $kid ) !== 'none' ) {
				$out[] = $i;
			}
		}

		return $out;
	}

	/**
	 * @param array<int, int> $path
	 * @return array<string, mixed>
	 */
	private function node( array $path ): array {
		return self::node_in( $this->root, $path );
	}

	/**
	 * The block at $path under $block ([] is $block itself).
	 *
	 * @param array<string, mixed> $block
	 * @param array<int, int>      $path
	 * @return array<string, mixed>
	 */
	public static function node_in( array $block, array $path ): array {
		foreach ( $path as $i ) {
			$kids  = self::kids( $block );
			$block = isset( $kids[ $i ] ) && is_array( $kids[ $i ] ) ? $kids[ $i ] : array();
		}

		return $block;
	}

	/**
	 * @param array<string, mixed> $block
	 * @return array<int, array<string, mixed>>
	 */
	public static function kids( array $block ): array {
		return is_array( $block['innerBlocks'] ?? null ) ? array_values( $block['innerBlocks'] ) : array();
	}

	/**
	 * The HTML a block saved, its inner blocks' HTML in place.
	 *
	 * @param array<string, mixed> $block
	 */
	public static function html( array $block ): string {
		$kids = self::kids( $block );
		$out  = '';
		$k    = 0;
		foreach ( (array) ( $block['innerContent'] ?? array( (string) ( $block['innerHTML'] ?? '' ) ) ) as $chunk ) {
			if ( is_string( $chunk ) ) {
				$out .= $chunk;
			} elseif ( isset( $kids[ $k ] ) ) {
				$out .= self::html( $kids[ $k++ ] );
			}
		}

		return $out;
	}

	/**
	 * The block's first HTML chunk: all of a leaf's HTML, a container's
	 * opening tag.
	 *
	 * @param array<string, mixed> $block
	 */
	public static function chunk( array $block ): string {
		foreach ( (array) ( $block['innerContent'] ?? array() ) as $chunk ) {
			if ( is_string( $chunk ) ) {
				return $chunk;
			}
			break;
		}

		return self::kids( $block ) === array() ? (string) ( $block['innerHTML'] ?? '' ) : '';
	}

	/**
	 * The block's own element: tag name and attributes.
	 *
	 * @param array<string, mixed> $block
	 * @return array{name:string, attrs:array<string, string>}|null
	 */
	public static function root_tag( array $block ): ?array {
		$chunk = self::chunk( $block );
		$el    = $chunk === '' ? null : Header_Html::element( $chunk, 'root' );
		if ( null === $el ) {
			return null;
		}

		return array(
			'name'  => $el['name'],
			'attrs' => Header_Html::attributes( substr( $chunk, $el['open_start'], $el['open_end'] - $el['open_start'] ) ),
		);
	}

	/**
	 * What a block is to a header.
	 *
	 * - `logo`: a link holding an image and no text
	 * - `link`: a link, or a paragraph / list item around the one link that
	 *   carries all its text
	 * - `dropdown`: a trigger plus a panel of links (Semper Dry's
	 *   `dxai-toggle-openMenu--services` box)
	 * - `text`: a leaf with words in it
	 * - `sep`: a leaf with no words — a rule, a dot, a bar
	 * - `button`: a button (a drawer toggle); structure, never an item
	 * - `container`, `image`, `none` (whitespace between blocks)
	 *
	 * @param array<string, mixed> $block
	 */
	public static function kind( array $block ): string {
		if ( empty( $block['blockName'] ) ) {
			// The virtual root, or freeform HTML between blocks.
			if ( self::kids( $block ) !== array() ) {
				return 'container';
			}

			return trim( wp_strip_all_tags( self::html( $block ) ) ) === '' ? 'none' : 'text';
		}
		$root = self::root_tag( $block );
		if ( null === $root ) {
			return self::kids( $block ) !== array() ? 'container' : 'none';
		}
		$html = self::html( $block );
		$kids = self::kids( $block );
		$tag  = $root['name'];

		if ( $tag === 'a' ) {
			return self::is_logo( $html, $root['attrs'], $block ) ? 'logo' : 'link';
		}
		if ( $kids !== array() && null !== self::dropdown_parts( $block ) ) {
			return 'dropdown';
		}
		if ( $tag === 'button' ) {
			return 'button';
		}
		if ( $kids !== array() ) {
			return 'container';
		}
		if ( in_array( $tag, array( 'img', 'svg', 'picture' ), true ) ) {
			return 'image';
		}
		// No letter and no digit: a rule, a dot, a bar, a row of stars
		// (Arcus Restoration's ★★★★★ beside "350+ Reviews") — never an item.
		$text = Header_Html::visible_text( $html );
		if ( $text === '' || ! preg_match( '/[\p{L}\p{N}]/u', $text ) ) {
			return 'sep';
		}
		if ( self::single_link( $html ) ) {
			return 'link';
		}

		return 'text';
	}

	/**
	 * Whether a link is a logo: an image and no words.
	 *
	 * @param array<string, string> $attrs
	 */
	private static function is_logo( string $html, array $attrs, array $block = array() ): bool {
		$inner = (string) Header_Html::inner( $html, 'root' );
		if ( Header_Html::visible_text( $inner ) !== '' ) {
			/*
			 * A wordmark: a link the design itself names as the brand
			 * (Global Market Launch's `rv-header-brand`, "OPERATOR/
			 * INTERNATIONAL GTM"). It is the logo, not a trust item or a
			 * button, and stays as drawn.
			 */
			foreach ( preg_split( '/\s+/', (string) ( $attrs['class'] ?? '' ) ) ?: array() as $class ) {
				// A class ending in the word; not a colour utility named after
				// the brand palette (`text-brand`, `bg-brand-green`), not a variant.
				if ( preg_match( '/^(?:[a-z0-9]+[-_])*(?:brand|logo|wordmark)$/i', $class ) && ! preg_match( '/^(?:text|bg|border|fill|stroke|from|via|to|ring|shadow|outline|decoration|accent|caret|divide|placeholder)-/i', $class ) ) {
					return true;
				}
			}

			return false;
		}
		if ( preg_match( '/<(img|picture)\b/i', $inner ) ) {
			return true;
		}
		/*
		 * A picture block draws its image when the page renders (DX Picture), so
		 * the HTML it saved holds no <img>: a link around one, with no words, is
		 * the logo all the same. Read as a link it became a menu item — the
		 * design's name, "home", in Appearance > Menus.
		 */
		if ( self::picture_in( $block ) !== null ) {
			return true;
		}
		// An inline-SVG mark that links home.
		$href = (string) ( $attrs['href'] ?? '' );

		return preg_match( '/<svg\b/i', $inner ) === 1
			&& ( in_array( $href, array( '/', '#', '' ), true ) || untrailingslashit( $href ) === untrailingslashit( home_url() ) || preg_match( '/\b(home|logo)\b/i', (string) ( $attrs['aria-label'] ?? '' ) ) === 1 );
	}

	/**
	 * The first picture block under $block — a theme's block that draws its image at render time, so the markup it saved holds no <img> —:
	 * its image, as the block's attributes hold it (Capabilities::picture_shape()).
	 *
	 * @param array<string, mixed> $block
	 * @return array{src:string, alt:string, id:int}|null
	 */
	private static function picture_in( array $block ): ?array {
		foreach ( self::kids( $block ) as $kid ) {
			$shape = \DXAI_UI\Theme\Capabilities::picture_shape( (string) ( $kid['blockName'] ?? '' ) );
			if ( $shape !== array() ) {
				$attrs = (array) ( $kid['attrs'] ?? array() );

				return array(
					'src' => (string) ( $attrs[ $shape['url'] ] ?? '' ),
					'alt' => (string) ( $attrs[ $shape['alt'] ] ?? '' ),
					'id'  => (int) ( $attrs[ $shape['id'] ] ?? 0 ),
				);
			}
			$found = self::picture_in( $kid );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Whether a leaf's words all sit in its one link.
	 */
	private static function single_link( string $html ): bool {
		if ( substr_count( strtolower( $html ), '<a ' ) + substr_count( strtolower( $html ), '<a>' ) !== 1 ) {
			return false;
		}
		$inner = Header_Html::inner( $html, 'a' );

		return null !== $inner && Header_Html::visible_text( $inner ) === Header_Html::visible_text( (string) Header_Html::inner( $html, 'root' ) );
	}

	/**
	 * A dropdown's trigger and panel, as paths relative to it, or null when
	 * the block is not one.
	 *
	 * Either the runtime marked it — a `dxai-toggle-*` / `data-dxai-hover`
	 * wrapper, a `dxai-on-*` or hidden panel — or it has the shape
	 * Menu_Tree reads as a dropdown: a trigger followed by one panel holding
	 * at least two links.
	 *
	 * @param array<string, mixed> $block
	 * @return array{trigger:array<int, int>, panel:array<int, int>}|null
	 */
	public static function dropdown_parts( array $block ): ?array {
		$kids = self::kids( $block );
		$root = self::root_tag( $block );
		if ( null === $root || $kids === array() || in_array( $root['name'], array( 'a', 'button', 'nav', 'header', 'ul', 'ol' ), true ) ) {
			return null;
		}

		// A list item: its own link is the trigger, its nested list the panel.
		if ( 'li' === $root['name'] && null !== Header_Html::element( self::chunk( $block ), 'a' ) ) {
			foreach ( $kids as $i => $kid ) {
				if ( self::link_count( $kid ) >= 1 ) {
					return array(
						'trigger' => array(),
						'panel'   => array( $i ),
					);
				}
			}

			return null;
		}

		$marked  = str_contains( (string) ( $root['attrs']['class'] ?? '' ), 'dxai-toggle-' ) || isset( $root['attrs']['data-dxai-hover'] );
		$trigger = null;
		$panel   = null;
		foreach ( $kids as $i => $kid ) {
			$kind = self::kind( $kid );
			if ( $kind === 'none' ) {
				continue;
			}
			if ( null === $trigger && in_array( $kind, array( 'link', 'button', 'text' ), true ) ) {
				$trigger = $i;
				continue;
			}
			if ( null === $trigger ) {
				return null;
			}
			if ( ( $kind === 'container' || $kind === 'dropdown' ) && self::link_count( $kid ) >= 1 && null === $panel ) {
				$panel = $i;
				continue;
			}

			return null;
		}
		if ( null === $trigger || null === $panel ) {
			return null;
		}
		$panel_root = self::root_tag( $kids[ $panel ] );
		$class      = ' ' . (string) ( $panel_root['attrs']['class'] ?? '' ) . ' ';
		$hidden     = str_contains( $class, ' hidden ' ) || str_contains( $class, 'dxai-on-' ) || isset( $panel_root['attrs']['hidden'] );
		if ( ! $marked && ! $hidden && self::link_count( $kids[ $panel ] ) < 2 ) {
			return null;
		}

		return array(
			'trigger' => array( $trigger ),
			'panel'   => array( $panel ),
		);
	}

	/**
	 * @param array<string, mixed> $block
	 */
	private static function link_count( array $block ): int {
		$root = self::root_tag( $block );
		if ( null !== $root && $root['name'] === 'a' ) {
			return 1;
		}
		if ( self::kids( $block ) === array() ) {
			return self::single_link( self::html( $block ) ) ? 1 : 0;
		}
		$n = 0;
		foreach ( self::kids( $block ) as $kid ) {
			$n += self::link_count( $kid );
		}

		return $n;
	}

	/**
	 * Where a link with child blocks keeps its words: the first descendant
	 * leaf that has some.
	 *
	 * @param array<string, mixed> $block
	 * @return array{path:array<int, int>, el:string}|null
	 */
	private static function label_block( array $block, array $path = array() ): ?array {
		foreach ( self::kids( $block ) as $i => $kid ) {
			$here = array_merge( $path, array( $i ) );
			if ( self::kids( $kid ) === array() ) {
				$inner = Header_Html::inner( self::chunk( $kid ), 'root' );
				if ( null !== $inner && Header_Html::visible_text( $inner ) !== '' ) {
					return array(
						'path' => $here,
						'el'   => 'root',
					);
				}
				continue;
			}
			$found = self::label_block( $kid, $here );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Every leaf under $block that holds words, as paths relative to it, in
	 * document order: the title and the line that describes it, in a link made
	 * of blocks.
	 *
	 * @param array<string, mixed> $block
	 * @param array<int, int>      $path
	 * @return array<int, array<int, int>>
	 */
	private static function word_leaves( array $block, array $path = array() ): array {
		$out = array();
		foreach ( self::kids( $block ) as $i => $kid ) {
			$here = array_merge( $path, array( $i ) );
			if ( self::kids( $kid ) === array() ) {
				$inner = Header_Html::inner( self::chunk( $kid ), 'root' );
				// Letters or digits: an arrow or a bullet is not a description.
				if ( null !== $inner && preg_match( '/[\p{L}\p{N}]/u', Header_Html::visible_text( $inner ) ) === 1 ) {
					$out[] = $here;
				}
				continue;
			}
			$out = array_merge( $out, self::word_leaves( $kid, $here ) );
		}

		return $out;
	}

	/**
	 * Where a link of blocks keeps the line that describes it: the leaf with
	 * words right after its label's.
	 *
	 * @param array<string, mixed>                   $block
	 * @param array{path:array<int, int>, el:string} $label label_block()'s answer.
	 * @return array{path:array<int, int>, el:string}|null
	 */
	private static function desc_block( array $block, array $label ): ?array {
		$leaves = self::word_leaves( $block );
		foreach ( $leaves as $n => $path ) {
			if ( $path === $label['path'] ) {
				return isset( $leaves[ $n + 1 ] )
					? array(
						'path' => $leaves[ $n + 1 ],
						'el'   => 'root',
					)
					: null;
			}
		}

		return null;
	}

	/**
	 * What makes two items look alike: the element and its classes, less
	 * the ones that differ per item without changing the look — a hover
	 * class numbered per element (`dxai-sh-16`), a state value
	 * (`--services`), and `hidden`.
	 *
	 * @param array<string, mixed> $block
	 */
	public static function sig( array $block ): string {
		$root    = self::root_tag( $block );
		$classes = preg_split( '/\s+/', trim( (string) ( $root['attrs']['class'] ?? '' ) ) );
		$keep    = array();
		foreach ( is_array( $classes ) ? $classes : array() as $class ) {
			if ( $class === '' || $class === 'hidden' || preg_match( '/^dxai-sh-\d+$/', $class ) ) {
				continue;
			}
			$keep[] = preg_replace( '/^(dxai-(?:toggle|on|off|cls)-[A-Za-z0-9_]+)--.+$/', '$1', $class ) ?? $class;
		}
		sort( $keep );

		return (string) ( $root['name'] ?? '' ) . '|' . implode( ' ', $keep );
	}

	/**
	 * The first image in $html: its src and alt.
	 *
	 * @return array{src:string, alt:string}|null
	 */
	private static function first_img( string $html ): ?array {
		$p = new \WP_HTML_Tag_Processor( $html );
		if ( ! $p->next_tag( array( 'tag_name' => 'img' ) ) ) {
			return null;
		}

		return array(
			'src' => (string) $p->get_attribute( 'src' ),
			'alt' => (string) $p->get_attribute( 'alt' ),
		);
	}
}

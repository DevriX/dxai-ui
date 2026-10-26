<?php
/**
 * "Make the rest from this page": a finished page made again for the other services or cities.
 *
 * @package DXAI_UI\Network
 */

declare(strict_types=1);

namespace DXAI_UI\Network;

use DXAI_UI\Blocks\Style_Rules;
use DXAI_UI\Chrome\Page_Chrome;
use DXAI_UI\Compiler\Design_Tokens;
use DXAI_UI\Connectors\Live_Page_Fetcher;
use DXAI_UI\Pages\Content_Extractor;
use DXAI_UI\Pages\Copy_Writer;
use DXAI_UI\Pages\Section_Library;
use DXAI_UI\Pages\Site_Pages;
use DXAI_UI\Structures\Page_Scope;

/**
 * The team designs one service page (or one city page) by hand, then copies it onto the other services and rewrites
 * every copy — 2 000 words each, new questions; the rewrite is most of the manual work (docs/PLAN-NETWORK-PAGES.md, A).
 * This makes the copies the way they mean them:
 *
 *  - the build and the look of the page are kept exactly, block by block, with its design records (template, scope, CSS);
 *  - the sections the site says the same way elsewhere — word for word on its Home or on another page (the reviews, the
 *    form, the trust bar, the call to action) — stay word for word, and the AI is not asked to touch them;
 *  - the list of the other services keeps its cards: on the copy for Water Damage the card that led to Water Damage leads
 *    back to the page it was made from;
 *  - everything else is the page's own: the service's (or the city's) name is put in its place in every heading and line
 *    ("Our Fire Damage Restoration Process" → "Our Water Damage Restoration Process"), and the words are written for it —
 *    by the AI chosen in Settings with the team's prompt (Copy_Writer), from the client's old page for the same service
 *    when the old site has one, with how the network writes such a page (Network_Model); without an AI the page's own
 *    lines are left empty with what the original says as the hint in the editor: nothing about another service is shown.
 *
 * A copy keeps what it was before (or that the run made it), and giving it back puts that back, unless the page was
 * edited since. A page the menu names that does not exist yet is made (published when its words were written, a draft
 * when they are not).
 */
final class Page_Copies {

	/** On a copy: the page it was made from. */
	public const OF = '_dxai_ui_copy_of';

	/** On a copy: its content before (compressed), when it had one. */
	public const BEFORE = '_dxai_ui_copies_before';

	/** On a copy: the hash of the content the run left (a later edit is not undone). */
	public const HASH = '_dxai_ui_copies_hash';

	/** On a copy: 1 when the run made the page. */
	public const MADE = '_dxai_ui_copies_made';

	/** The states, as the end of a city page's address. */
	private const STATES = 'al|ak|az|ar|ca|co|ct|de|fl|ga|hi|id|il|in|ia|ks|ky|la|me|md|ma|mi|mn|ms|mo|mt|ne|nv|nh|nj|nm|ny|nc|nd|oh|ok|or|pa|ri|sc|sd|tn|tx|ut|vt|va|wa|wv|wi|wy';

	/** The site's other pages a plan compares a page with: the ones changed last (a shared section is on most of them). */
	private const COMPARE = 60;

	/** @var array<string, array<int, string>> The words of the sections of the pages read in this request (texts()). */
	private static array $texts = array();

	/** A page with fewer words is empty: a skeleton waiting for its content. */
	private const EMPTY_WORDS = 40;

	/** The words in a page's title that say what kind of work it is, not which: left out of the short name. */
	private const GENERIC = '/\s+(restoration|services?|removal|remediation|clean[- ]?up|cleaning|repairs?|mitigation|recovery|solutions|experts?|company|specialists?)$/i';

	/**
	 * The design records a page carries: a copy has the original's.
	 *
	 * @return array<int, string>
	 */
	private static function design_meta(): array {
		return array( '_wp_page_template', Page_Scope::META, '_dxai_ui_generated_page', '_dxai_ui_css_url', '_dxai_ui_js_url', '_dxai_ui_font_urls', '_dxai_ui_wrapper_class', '_dxai_ui_static_html', Design_Tokens::META, Page_Chrome::PART_KEY_META, Page_Chrome::MODE_META );
	}

	/**
	 * The Home a page is measured against: the design's Home it belongs to, else the site's front page.
	 */
	public static function home_of( int $page_id ): int {
		$scope = (int) get_post_meta( $page_id, Page_Scope::META, true );
		if ( $scope > 0 && $scope !== $page_id ) {
			return $scope;
		}
		$front = get_option( 'show_on_front' ) === 'page' ? (int) get_option( 'page_on_front' ) : 0;

		return $front !== $page_id ? $front : 0;
	}

	/**
	 * A page's top-level blocks and where its sections are: one level down when the page is one group around them.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array{path: array<int, int>, list: array<int, int>}
	 */
	private static function layout( array $blocks ): array {
		$real = array_keys( array_filter( $blocks, static fn( $b ) => ! empty( $b['blockName'] ) ) );
		if ( count( $real ) === 1 ) {
			$inner = array_keys( array_filter( (array) ( $blocks[ $real[0] ]['innerBlocks'] ?? array() ), static fn( $b ) => ! empty( $b['blockName'] ) ) );
			if ( count( $inner ) >= 3 && empty( $blocks[ $real[0] ]['attrs']['metadata']['name'] ) ) {
				return array(
					'path' => array( $real[0] ),
					'list' => $inner,
				);
			}
		}

		return array(
			'path' => array(),
			'list' => $real,
		);
	}

	/**
	 * The sections of a page, as blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	private static function section_blocks( array $blocks ): array {
		$at  = self::layout( $blocks );
		$out = array();
		foreach ( $at['list'] as $i ) {
			$out[] = $at['path'] === array() ? $blocks[ $i ] : $blocks[ $at['path'][0] ]['innerBlocks'][ $i ];
		}

		return $out;
	}

	/**
	 * The words of every section of a page, by the hash of its words.
	 *
	 * @return array<int, string>
	 */
	private static function texts( int $page_id ): array {
		// Read once a request for a page as it is now: a plan compares a page with the same pages again and again.
		$key = $page_id . '|' . (string) get_post_field( 'post_modified_gmt', $page_id ) . '|' . md5( (string) get_post_field( 'post_content', $page_id ) );
		if ( isset( self::$texts[ $key ] ) ) {
			return self::$texts[ $key ];
		}
		$out = array();
		foreach ( self::section_blocks( parse_blocks( (string) get_post_field( 'post_content', $page_id ) ) ) as $i => $b ) {
			$r = Page_Reader::one( render_block( $b ), $i );
			if ( (string) ( $r['text_hash'] ?? '' ) !== '' && (int) ( $r['words'] ?? 0 ) >= 5 ) {
				$out[] = (string) $r['text_hash'];
			}
		}
		self::$texts[ $key ] = $out;

		return $out;
	}

	/**
	 * The sections of a page, each with what it is for the copies:
	 *
	 *  global   the site says it word for word on its Home ('home') or on another page ('pages'): kept as it is;
	 *  related  the list of the site's other pages: kept, its card for the copy's own page pointed back at this one;
	 *  still    no words to write (a picture, a bar of badges, a spacer): kept as it is;
	 *  chrome   the page's own header or footer: kept as it is;
	 *  page     the page's own words: written for each copy.
	 *
	 * @param array<int, int> $not Pages not to compare with (the copies: they say what this page says).
	 * @return array<int, array<string, mixed>>
	 */
	public static function sections( int $page_id, array $not = array() ): array {
		$blocks = parse_blocks( (string) get_post_field( 'post_content', $page_id ) );
		$chrome = array_merge( ...array_values( Section_Library::chrome_blocks( $blocks ) ) );
		$at     = self::layout( $blocks );
		$home   = self::home_of( $page_id );
		$on_home = $home > 0 ? array_fill_keys( self::texts( $home ), true ) : array();
		$on_page = array();
		foreach ( self::other_pages( $page_id, array_merge( $not, array( $home ) ) ) as $id ) {
			foreach ( array_unique( self::texts( $id ) ) as $h ) {
				$on_page[ $h ] = ( $on_page[ $h ] ?? 0 ) + 1;
			}
		}
		$kind = self::kind( $page_id );
		$out  = array();
		foreach ( self::section_blocks( $blocks ) as $n => $b ) {
			$html = render_block( $b );
			$r    = Page_Reader::one( $html, $n );
			$role = (string) ( $r['role'] ?? 'other' );
			$hash = (string) ( $r['text_hash'] ?? '' );
			$why  = '';
			if ( $at['path'] === array() && in_array( $at['list'][ $n ], $chrome, true ) ) {
				$what = 'chrome';
			} elseif ( (int) ( $r['words'] ?? 0 ) < 3 ) {
				$what = 'still';
			} elseif ( $role === 'hero' ) {
				$what = 'page';
			} elseif ( self::sisters_linked( $html, $page_id, $kind ) >= 2 ) {
				// The list of the site's other services (or cities): its cards are the site's, not the page's words.
				$what = 'related';
			} elseif ( $role === 'reviews' || preg_match( '/\breviews?\b|testimonials?|customers? say|clients? say|what (our )?\S+(\s\S+)? (customers|clients|neighbors|homeowners) say/i', (string) ( $r['head'] ?? '' ) ) === 1 ) {
				// What customers said is never written again.
				$what = 'global';
				$why  = 'reviews';
			} elseif ( isset( $on_home[ $hash ] ) ) {
				$what = 'global';
				$why  = 'home';
			} elseif ( ( $on_page[ $hash ] ?? 0 ) > 0 ) {
				$what = 'global';
				$why  = 'pages';
			} else {
				$what = 'page';
			}
			$out[] = array(
				'index' => $n,
				'name'  => (string) ( $b['attrs']['metadata']['name'] ?? '' ),
				'role'  => $role,
				'head'  => (string) ( $r['head'] ?? '' ),
				'words' => (int) ( $r['words'] ?? 0 ),
				'imgs'  => (int) ( $r['imgs'] ?? 0 ),
				'what'  => $what,
				'why'   => $why,
				'pages' => (int) ( $on_page[ $hash ] ?? 0 ),
			);
		}

		return $out;
	}

	/**
	 * How many of the site's other pages of the same kind a section links to (a service page's list of the other services).
	 */
	private static function sisters_linked( string $html, int $page_id, string $kind ): int {
		if ( preg_match_all( '/href="([^"#]+)"/i', $html, $m ) < 2 ) {
			return 0;
		}
		$home  = untrailingslashit( home_url() );
		$found = array();
		foreach ( array_unique( $m[1] ) as $href ) {
			$href = html_entity_decode( (string) $href, ENT_QUOTES, 'UTF-8' );
			if ( ! str_starts_with( $href, $home ) && ! str_starts_with( $href, '/' ) ) {
				continue;
			}
			$id = url_to_postid( $href );
			if ( $id > 0 && $id !== $page_id && get_post_type( $id ) === 'page' && self::kind( $id ) === $kind ) {
				$found[ $id ] = true;
			}
		}

		return count( $found );
	}

	/**
	 * The site's other published pages.
	 *
	 * @param array<int, int> $not
	 * @return array<int, int>
	 */
	private static function other_pages( int $page_id, array $not ): array {
		$query = array(
			'post_type'        => 'page',
			'post_status'      => 'publish',
			'numberposts'      => self::COMPARE,
			'fields'           => 'ids',
			'orderby'          => 'modified',
			'order'            => 'DESC',
			'suppress_filters' => true,
			'post__not_in'     => array_values( array_filter( array_map( 'intval', array_merge( $not, array( $page_id ) ) ) ) ),
		);
		// The pages of the page's own design, when it is one: a site can hold the pages of other designs too.
		$scope = (int) get_post_meta( $page_id, Page_Scope::META, true );
		if ( $scope > 0 ) {
			$query['meta_key']   = Page_Scope::META; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$query['meta_value'] = (string) $scope; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		}

		return array_map( 'intval', get_posts( $query ) );
	}

	/**
	 * The kind of a page that is not made yet, by its address and its title (a menu's item: "/service-areas/lacey-wa/", "Lacey"):
	 * the page it sits under is the address above it.
	 */
	public static function kind_of_address( string $path, string $title ): string {
		$parts  = array_values( array_filter( explode( '/', trim( $path, '/' ) ), static fn( string $p ): bool => $p !== '' ) );
		$slug   = sanitize_title( (string) ( $parts[ count( $parts ) - 1 ] ?? '' ) );
		$above  = count( $parts ) > 1 ? sanitize_title( (string) $parts[ count( $parts ) - 2 ] ) : '';
		$parent = array(
			'id'     => 2,
			'slug'   => $above,
			'title'  => ucwords( str_replace( '-', ' ', $above ) ),
			'parent' => 0,
		);
		$kind   = Network_Model::kind_of(
			array(
				'id'     => 1,
				'slug'   => $slug,
				'title'  => $title,
				'parent' => $above !== '' ? 2 : 0,
			),
			$above !== '' ? array( 2 => $parent ) : array()
		);
		if ( $kind === 'service' && preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)+-(?:' . self::STATES . ')$/', $slug ) === 1 ) {
			return 'location';
		}

		return $kind;
	}

	/**
	 * A page's kind, the way the network's pages are told apart (service, location, about…).
	 */
	public static function kind( int $page_id ): string {
		$post = get_post( $page_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}
		$parent = $post->post_parent > 0 ? get_post( $post->post_parent ) : null;
		$row    = static fn( \WP_Post $p ): array => array(
			'id'     => (int) $p->ID,
			'slug'   => (string) $p->post_name,
			'title'  => (string) $p->post_title,
			'parent' => (int) $p->post_parent,
		);

		$kind = Network_Model::kind_of( $row( $post ), $parent instanceof \WP_Post ? array( (int) $parent->ID => $row( $parent ) ) : array() );
		// "water-damage-lacey-wa": a service's words and a place with its state at the end of the address is a city's page.
		if ( $kind === 'service' && preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)+-(?:' . self::STATES . ')$/', (string) $post->post_name ) === 1 ) {
			return 'location';
		}

		return $kind;
	}

	/**
	 * The words of a page, counted.
	 */
	private static function words_of( string $content ): int {
		return Page_Reader::words( html_entity_decode( wp_strip_all_tags( $content ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * The build of a page, section by section: the blocks and their classes without the words.
	 *
	 * @return array<int, string>
	 */
	private static function builds( string $content ): array {
		$out = array();
		foreach ( self::section_blocks( parse_blocks( $content ) ) as $i => $b ) {
			$out[] = (string) ( Page_Reader::one( render_block( $b ), $i )['shape_hash'] ?? '' );
		}

		return $out;
	}

	/**
	 * The pages to make from a page: its sisters of the same kind (under the same parent), each with what it is now —
	 *
	 *  made   made from this page before;
	 *  empty  a skeleton with no content yet;
	 *  copy   a copy of this page by hand (built like it, section by section, and still saying this page's name);
	 *  own    a page with its own content (not chosen unless a person chooses it);
	 *  menu   named by the site's menu next to this page, with no page yet (it is made).
	 *
	 * @return array<int, array{id: int, title: string, link: string, state: string, chosen: bool, words: int}>
	 */
	public static function targets( int $source ): array {
		$post = get_post( $source );
		if ( ! $post instanceof \WP_Post || $post->post_type !== 'page' ) {
			return array();
		}
		$kind   = self::kind( $source );
		$mine   = self::builds( (string) $post->post_content );
		$name   = self::names( (string) $post->post_title, $kind );
		$out    = array();
		$taken  = array();
		// Pages at the top of the site are sisters only when they are the same kind and of the same design (a site can hold the
		// pages of more than one imported design, and hundreds of pages at its top).
		$scope = (int) get_post_meta( $source, Page_Scope::META, true );
		$query = array(
			'post_type'        => 'page',
			'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
			'post_parent'      => (int) $post->post_parent,
			'numberposts'      => 300,
			'orderby'          => 'menu_order title',
			'order'            => 'ASC',
			'suppress_filters' => true,
		);
		if ( $post->post_parent === 0 && $scope > 0 ) {
			$query['meta_key']   = Page_Scope::META; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$query['meta_value'] = (string) $scope; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		}
		$sister = get_posts( $query );
		foreach ( $sister as $p ) {
			if ( (int) $p->ID === $source || ( $post->post_parent === 0 && ( self::kind( (int) $p->ID ) !== $kind || (int) get_post_meta( (int) $p->ID, Page_Scope::META, true ) !== $scope || (int) $p->ID === $scope ) ) || ( $post->post_parent > 0 && ! in_array( self::kind( (int) $p->ID ), array( $kind, 'other' ), true ) ) ) {
				continue;
			}
			$content = (string) $p->post_content;
			$words   = self::words_of( $content );
			if ( (int) get_post_meta( (int) $p->ID, self::OF, true ) === $source ) {
				$state = 'made';
			} elseif ( $words < self::EMPTY_WORDS ) {
				$state = 'empty';
			} else {
				$theirs = self::builds( $content );
				$same   = count( array_intersect_assoc( $mine, $theirs ) );
				$state  = $mine !== array() && $same >= 0.8 * count( $mine ) && $name['full'] !== '' && stripos( wp_strip_all_tags( $content ), $name['full'] ) !== false ? 'copy' : 'own';
			}
			$taken[ untrailingslashit( (string) get_permalink( $p ) ) ] = true;
			$out[] = array(
				'id'     => (int) $p->ID,
				'title'  => html_entity_decode( (string) $p->post_title, ENT_QUOTES, 'UTF-8' ),
				'link'   => (string) get_permalink( $p ),
				'state'  => $state,
				'chosen' => $state !== 'own',
				'words'  => $words,
			);
		}
		foreach ( self::menu_sisters( $source ) as $item ) {
			if ( isset( $taken[ untrailingslashit( $item['href'] ) ] ) ) {
				continue;
			}
			$out[] = array(
				'id'     => 0,
				'title'  => $item['title'],
				'link'   => $item['href'],
				'state'  => 'menu',
				'chosen' => true,
				'words'  => 0,
			);
		}

		return $out;
	}

	/**
	 * The items of the site's menus next to the page's own item (the other services of its dropdown) that lead to no page of
	 * the site yet: an address on this site, under the same path as the page's.
	 *
	 * @return array<int, array{title: string, href: string}>
	 */
	private static function menu_sisters( int $source ): array {
		$here = untrailingslashit( (string) get_permalink( $source ) );
		// The page's place in the site's addresses, from its path (a site with plain permalinks has no path in its links).
		$up   = dirname( '/' . get_page_uri( $source ) );
		$base = trailingslashit( home_url( $up === '/' || $up === '\\' || $up === '.' ? '/' : $up . '/' ) );
		$out  = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$items = wp_get_nav_menu_items( (int) $menu->term_id );
			if ( ! is_array( $items ) ) {
				continue;
			}
			$mine = null;
			foreach ( $items as $it ) {
				if ( (int) $it->object_id === $source && $it->object === 'page' ) {
					$mine = $it;
					break;
				}
				if ( untrailingslashit( (string) $it->url ) === $here ) {
					$mine = $it;
				}
			}
			if ( $mine === null ) {
				continue;
			}
			foreach ( $items as $it ) {
				$url = (string) $it->url;
				if ( (int) $it->ID === (int) $mine->ID || (int) $it->menu_item_parent !== (int) $mine->menu_item_parent || $it->object === 'page' || ! str_starts_with( $url, $base ) || str_contains( $url, '#' ) ) {
					continue;
				}
				if ( url_to_postid( $url ) > 0 ) {
					continue;
				}
				$out[ untrailingslashit( $url ) ] = array(
					'title' => html_entity_decode( wp_strip_all_tags( (string) $it->title ), ENT_QUOTES, 'UTF-8' ),
					'href'  => $url,
				);
			}
		}

		return array_values( $out );
	}

	/**
	 * The name a page's words use for what it is about: the service (its title before "in …" and a dash) or the city, and
	 * the short form without the kind of work ("Water Damage" of "Water Damage Restoration").
	 *
	 * @return array{full: string, short: string}
	 */
	public static function names( string $title, string $kind ): array {
		$title = trim( html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES, 'UTF-8' ) );
		$t     = Network_Model::page_tokens(
			array(
				'title' => $title,
				'slug'  => '',
			)
		);
		$full  = $kind === 'location' ? ( $t['city'] !== '' ? $t['city'] : trim( (string) preg_replace( '/,\s*[A-Z]{2}$/', '', $title ) ) ) : ( $t['service'] !== '' ? $t['service'] : $title );
		$short = trim( (string) preg_replace( self::GENERIC, '', $full ) );

		return array(
			'full'  => $full,
			'short' => $short !== $full && mb_strlen( $short ) > 3 ? $short : '',
		);
	}

	/**
	 * What is put in place of what, in the words of a copy: the original's name by the copy's, the full one first.
	 *
	 * @return array<string, string>
	 */
	public static function swaps( string $from_title, string $to_title, string $kind ): array {
		$a = self::names( $from_title, $kind );
		$b = self::names( $to_title, $kind );
		if ( $a['full'] === '' || $b['full'] === '' || strcasecmp( $a['full'], $b['full'] ) === 0 ) {
			return array();
		}
		// The name with the kind of work, then its core: "Fire Damage Restoration" → "Water Damage Restoration", then
		// "Fire Damage" → "Water Damage". A title without the kind of work ("Fire & Smoke Damage") is a core itself, so its
		// lines that add the work ("Fire & Smoke Damage Restoration") get the other core and keep their own word.
		$from = $a['short'] !== '' ? $a['short'] : $a['full'];
		$to   = $b['short'] !== '' ? $b['short'] : $b['full'];
		$out  = array();
		if ( $a['short'] !== '' ) {
			$out[ $a['full'] ] = $b['full'];
		}
		$out[ $from ] = $to;
		// "Fire & Smoke Damage" is written "Fire Damage" too, and "Fire and Smoke Damage".
		if ( preg_match( '/^(\S+)\s*(?:&|and)\s+\S+\s+(.+)$/iu', $from, $m ) === 1 ) {
			$out[ $m[1] . ' ' . $m[2] ] = $to;
			$out[ (string) preg_replace( '/\s*&\s*/u', ' and ', $from ) ] = $to;
		}
		unset( $out[ $to ] );

		return $out;
	}

	/**
	 * The pairs as a page's markup writes them: an ampersand as &amp; or &#038;, an apostrophe as &#8217;.
	 *
	 * @param array<string, string> $pairs
	 * @return array<string, string>
	 */
	private static function markup_pairs( array $pairs ): array {
		$out = $pairs;
		foreach ( $pairs as $from => $to ) {
			foreach ( array( array( '&', '&amp;' ), array( '&', '&#038;' ), array( '’', '&#8217;' ), array( "'", '&#039;' ) ) as $e ) {
				if ( str_contains( $from, $e[0] ) ) {
					$out[ str_replace( $e[0], $e[1], $from ) ] = str_replace( $e[0], $e[1], $to );
				}
			}
		}
		uksort( $out, static fn( string $x, string $y ): int => mb_strlen( $y ) <=> mb_strlen( $x ) );

		return $out;
	}

	/**
	 * A name put in place of another in a line, in the case the line wrote it (Title Case, lower case, UPPER CASE).
	 *
	 * @param array<string, string> $pairs
	 */
	public static function swap_text( string $text, array $pairs ): string {
		foreach ( $pairs as $from => $to ) {
			$text = (string) preg_replace_callback(
				'/(?<![\p{L}\p{N}])' . preg_quote( $from, '/' ) . '(?![\p{L}\p{N}])/iu',
				static function ( array $m ) use ( $from, $to ): string {
					if ( $m[0] === mb_strtoupper( $m[0] ) && $m[0] !== mb_strtolower( $m[0] ) && mb_strlen( $m[0] ) > 1 ) {
						return mb_strtoupper( $to );
					}
					if ( $m[0] === mb_strtolower( $m[0] ) ) {
						return mb_strtolower( $to );
					}
					if ( $m[0] !== $from && mb_substr( $m[0], 0, 1 ) === mb_strtoupper( mb_substr( $m[0], 0, 1 ) ) && mb_substr( $m[0], 1 ) === mb_strtolower( mb_substr( $m[0], 1 ) ) ) {
						return mb_strtoupper( mb_substr( $to, 0, 1 ) ) . mb_strtolower( mb_substr( $to, 1 ) );
					}

					return $to;
				},
				$text
			);
		}

		return $text;
	}

	/**
	 * The names put in place in a block's markup: the words between its tags, the alt and title of its pictures and links, and
	 * the words its attributes keep (the List View name, a block that holds its text in its attributes). Addresses are not
	 * touched.
	 *
	 * @param array<string, mixed>  $b
	 * @param array<string, string> $pairs
	 * @return array<string, mixed>
	 */
	public static function swap_block( array $b, array $pairs ): array {
		if ( $pairs === array() ) {
			return $b;
		}
		$coded = self::markup_pairs( $pairs );
		$html  = static fn( string $s ): string => (string) preg_replace_callback(
			'/(>)([^<]+)(<)|(\s(?:alt|title|aria-label)=")([^"]*)(")/u',
			static fn( array $m ): string => $m[1] !== '' ? $m[1] . self::swap_text( $m[2], $coded ) . $m[3] : $m[4] . self::swap_text( $m[5], $coded ) . $m[6],
			$s
		);
		foreach ( (array) ( $b['innerContent'] ?? array() ) as $k => $part ) {
			if ( is_string( $part ) ) {
				$b['innerContent'][ $k ] = $html( $part );
			}
		}
		$b['innerHTML'] = $html( (string) ( $b['innerHTML'] ?? '' ) );
		$b['attrs']     = self::swap_attrs( (array) ( $b['attrs'] ?? array() ), $pairs );
		foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $k => $inner ) {
			$b['innerBlocks'][ $k ] = self::swap_block( $inner, $pairs );
		}

		return $b;
	}

	/**
	 * @param array<string, mixed>  $attrs
	 * @param array<string, string> $pairs
	 * @return array<string, mixed>
	 */
	private static function swap_attrs( array $attrs, array $pairs ): array {
		foreach ( $attrs as $k => $v ) {
			if ( is_array( $v ) ) {
				$attrs[ $k ] = self::swap_attrs( $v, $pairs );
			} elseif ( is_string( $v ) && in_array( (string) $k, array( 'name', 'content', 'text', 'label', 'title', 'heading', 'description', 'question', 'answer', 'alt', 'caption' ), true ) && ! preg_match( '#^(https?:|/|\#)#', $v ) ) {
				$attrs[ $k ] = self::swap_text( $v, $pairs );
			}
		}

		return $attrs;
	}

	/**
	 * The links of a block pointed from one address to another (the related services' card for the copy's own page, back to
	 * the original), its words with them.
	 *
	 * @param array<string, mixed> $b
	 * @return array<string, mixed>
	 */
	private static function relink( array $b, string $from, string $to ): array {
		if ( $from === '' || $to === '' ) {
			return $b;
		}
		$re  = '/href="' . preg_quote( untrailingslashit( $from ), '/' ) . '\/?"/';
		$put = 'href="' . esc_url( $to ) . '"';
		foreach ( (array) ( $b['innerContent'] ?? array() ) as $k => $part ) {
			if ( is_string( $part ) ) {
				$b['innerContent'][ $k ] = (string) preg_replace( $re, $put, $part );
			}
		}
		$b['innerHTML'] = (string) preg_replace( $re, $put, (string) ( $b['innerHTML'] ?? '' ) );
		foreach ( (array) ( $b['attrs'] ?? array() ) as $k => $v ) {
			if ( is_string( $v ) && untrailingslashit( $v ) === untrailingslashit( $from ) ) {
				$b['attrs'][ $k ] = $to;
			}
		}
		foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $k => $inner ) {
			$b['innerBlocks'][ $k ] = self::relink( $inner, $from, $to );
		}

		return $b;
	}

	/**
	 * Whether a line of the original is the original's own, to be written again for the copy: in a FAQ or in the steps of a
	 * process every line is; elsewhere a line of running text (12 words or more), and a line that speaks of the original's
	 * service (its name, or the words of its family — fire, smoke, soot). A short line that fits any service stays ("Free
	 * inspections", "24/7 live calls"), and so does a way to reach the company (a phone number, an e-mail address).
	 *
	 * @param array<string, string> $pairs
	 */
	private static function own_line( string $html, string $text, bool $strict, string $stale, array $pairs ): bool {
		if ( preg_match( '/href="(?:tel|mailto):/i', $html ) === 1 ) {
			return false;
		}
		if ( $strict || Page_Reader::words( $text ) >= 12 ) {
			return true;
		}
		if ( $stale !== '' && preg_match( $stale . 'i', $text ) === 1 ) {
			return true;
		}
		foreach ( array_keys( $pairs ) as $name ) {
			if ( mb_stripos( $text, $name ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A section's own lines emptied (own_line()), each with what the original says as the hint the editor shows (nothing is
	 * shown on the page): its paragraphs, the runs of the design's own text, the items of its lists, the headings under its
	 * title, the questions of its FAQ. Its title stays unless it still speaks of the original's service; its buttons stay.
	 *
	 * @param array<string, mixed>  $b
	 * @param array<string, string> $pairs
	 * @return array<string, mixed>
	 */
	public static function outline( array $b, string $for, int &$places, string $stale = '', array $pairs = array(), bool $strict = false ): array {
		$name = (string) ( $b['blockName'] ?? '' );
		$lvl  = (int) ( $b['attrs']['level'] ?? 2 );
		// A title that still speaks of the original's service once the names are in place ("Fire, Smoke & Soot Damage
		// Cleanup" on the storm page) waits for a person too.
		if ( $name === 'core/heading' && $lvl < 3 && $stale !== '' && is_string( $b['innerContent'][0] ?? null ) && preg_match( $stale . 'i', self::swap_text( html_entity_decode( wp_strip_all_tags( (string) $b['innerContent'][0] ), ENT_QUOTES, 'UTF-8' ), $pairs ) ) === 1 ) {
			$lvl    = 3;
			$strict = true;
		}
		// A question of a FAQ: its summary, the question, waits too (its answer is the blocks inside it).
		if ( $name === 'core/details' && is_string( $b['innerContent'][0] ?? null ) && preg_match( '/^(.*?<summary\b[^>]*>)(.*?)(<\/summary>.*)$/s', (string) $b['innerContent'][0], $m ) === 1 ) {
			$was = trim( html_entity_decode( wp_strip_all_tags( $m[2] ), ENT_QUOTES, 'UTF-8' ) );
			if ( preg_match( '/\p{L}{2}/u', $was ) === 1 ) {
				++$places;
				$opening = (string) $b['innerContent'][0];
				/* translators: 1: what the page is about, 2: the original's question */
				$b['attrs']['placeholder'] = sprintf( __( 'A question about %1$s. The original asks: %2$s', 'dxai-ui' ), $for, mb_strimwidth( $was, 0, 160, '…' ) );
				$b['innerContent'][0]      = $m[1] . $m[3];
				$b['innerHTML']            = str_replace( $opening, $m[1] . $m[3], (string) ( $b['innerHTML'] ?? '' ) );
				unset( $b['attrs']['summary'] );
			}
		}
		$open = $name === 'core/paragraph' || $name === 'core/list-item' || $name === 'dxai-ui/text' || ( $name === 'core/heading' && $lvl >= 3 );
		if ( $open && empty( $b['innerBlocks'] ) && is_string( $b['innerContent'][0] ?? null ) && preg_match( '/^(\s*<([a-z][a-z0-9]*)\b[^>]*>)(.*)(<\/\2>\s*)$/s', (string) $b['innerContent'][0], $m ) === 1 ) {
			$was = trim( html_entity_decode( wp_strip_all_tags( $m[3] ), ENT_QUOTES, 'UTF-8' ) );
			if ( preg_match( '/\p{L}{2}/u', $was ) === 1 && self::own_line( $m[3], $was, $strict, $stale, $pairs ) ) {
				++$places;
				/* translators: 1: what the page is about, 2: the original's words */
				$b['attrs']['placeholder'] = sprintf( __( 'Write for %1$s. The original says: %2$s', 'dxai-ui' ), $for, mb_strimwidth( $was, 0, 220, '…' ) );
				$b['innerContent'][0]      = $m[1] . $m[4];
				$b['innerHTML']            = $m[1] . $m[4];
				if ( $name === 'dxai-ui/text' && isset( $b['attrs']['content'] ) ) {
					$b['attrs']['content'] = '';
				}
			}
			return $b;
		}
		foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $k => $inner ) {
			$b['innerBlocks'][ $k ] = self::outline( $inner, $for, $places, $stale, $pairs, $strict );
		}

		return $b;
	}

	/**
	 * The copy's content: the original's blocks, its sections each as their part says.
	 *
	 * @param array<int, array<string, mixed>> $plan sections().
	 * @return array{content: string, keep: array<int, string>, kept: int, written: int, places: int, pictures: int}
	 */
	public static function build( int $source, string $to_title, string $to_link, array $plan, bool $outline ): array {
		$blocks = parse_blocks( (string) get_post_field( 'post_content', $source ) );
		$kind   = self::kind( $source );
		$pairs  = self::swaps( (string) get_the_title( $source ), $to_title, $kind );
		// The list of the other services says the original where it said the copy: its own names, not their variants.
		$back   = self::swaps( $to_title, (string) get_the_title( $source ), $kind );
		$at     = self::layout( $blocks );
		// The words of the original's family of services that must not stay on another family's page (fire|smoke|soot).
		$from_f = $kind === 'service' ? Network_Model::family( '', html_entity_decode( (string) get_the_title( $source ), ENT_QUOTES, 'UTF-8' ) ) : '';
		$to_f   = $kind === 'service' ? Network_Model::family( '', $to_title ) : '';
		$stale  = $from_f !== '' && $from_f !== $to_f ? Network_Model::family_pattern( $from_f ) : '';
		$keep   = array();
		$kept   = 0;
		$writ   = 0;
		$places = 0;
		$pics   = 0;
		$for    = self::names( $to_title, $kind )['full'];
		foreach ( $at['list'] as $n => $i ) {
			$b    = $at['path'] === array() ? $blocks[ $i ] : $blocks[ $at['path'][0] ]['innerBlocks'][ $i ];
			$what = (string) ( $plan[ $n ]['what'] ?? 'page' );
			if ( $what === 'page' ) {
				// The lines that wait for a person keep the original's words as their hint; the names go in place after.
				if ( $outline ) {
					$b = self::outline( $b, $for, $places, $stale, $pairs, in_array( (string) ( $plan[ $n ]['role'] ?? '' ), array( 'faq', 'process' ), true ) );
				}
				$b     = self::swap_block( $b, $pairs );
				$pics += (int) ( $plan[ $n ]['imgs'] ?? 0 );
				++$writ;
			} else {
				if ( $what === 'related' ) {
					// The card of the copy's own page becomes the card of the original.
					$b = self::relink( self::swap_block( $b, $back ), $to_link, (string) get_permalink( $source ) );
				}
				$keep[] = Copy_Writer::section_hash( $b );
				++$kept;
			}
			if ( $at['path'] === array() ) {
				$blocks[ $i ] = $b;
			} else {
				$blocks[ $at['path'][0] ]['innerBlocks'][ $i ] = $b;
			}
		}

		return array(
			'content'  => serialize_blocks( $blocks ),
			'keep'     => $keep,
			'kept'     => $kept,
			'written'  => $writ,
			'places'   => $places,
			'pictures' => $pics,
		);
	}

	/**
	 * The words of the client's old page for a copy, read from it (headings, lines, list items, questions), when the old
	 * site has a page at the copy's address.
	 *
	 * @return array{url: string, text: string}
	 */
	public static function old_page( int $page_id ): array {
		$home   = self::home_of( $page_id );
		$origin = $home > 0 ? untrailingslashit( (string) get_post_meta( $home, Site_Pages::ORIGIN_META, true ) ) : '';
		$route  = trim( (string) get_post_meta( $page_id, '_dxai_ui_source_route', true ), '/' );
		if ( $route === '' ) {
			$route = trim( (string) wp_parse_url( (string) get_permalink( $page_id ), PHP_URL_PATH ), '/' );
			$base  = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
			$route = $base !== '' && str_starts_with( $route, $base ) ? trim( substr( $route, strlen( $base ) ), '/' ) : $route;
		}
		if ( $origin === '' || $route === '' ) {
			return array(
				'url'  => '',
				'text' => '',
			);
		}
		$url = $origin . '/' . $route . '/';
		$got = apply_filters( 'dxai_ui_copies_old_page', null, $url );
		if ( ! is_array( $got ) ) {
			$got = ( new Live_Page_Fetcher() )->fetch( $url, 20, $origin );
		}
		if ( is_wp_error( $got ) || (string) ( $got['raw'] ?? $got['html'] ?? '' ) === '' ) {
			return array(
				'url'  => '',
				'text' => '',
			);
		}
		$model = ( new Content_Extractor() )->extract( (string) ( $got['raw'] ?? $got['html'] ), (string) ( $got['url'] ?? $url ) );
		$lines = array();
		foreach ( (array) $model['sections'] as $sec ) {
			foreach ( (array) $sec['blocks'] as $blk ) {
				$type = (string) ( $blk['type'] ?? '' );
				if ( $type === 'heading' ) {
					$lines[] = str_repeat( '#', max( 1, (int) $blk['level'] ) ) . ' ' . Content_Extractor::plain( (string) $blk['html'] );
				} elseif ( $type === 'paragraph' ) {
					$lines[] = Content_Extractor::plain( (string) $blk['html'] );
				} elseif ( $type === 'list' ) {
					foreach ( (array) $blk['items'] as $li ) {
						$lines[] = '- ' . Content_Extractor::plain( is_array( $li ) ? (string) ( $li['html'] ?? '' ) : (string) $li );
					}
				} elseif ( $type === 'faq' ) {
					$lines[] = 'Q: ' . (string) $blk['q'];
					$lines[] = 'A: ' . Content_Extractor::plain( implode( ' ', array_map( static fn( $a ): string => is_array( $a ) ? (string) ( $a['html'] ?? '' ) : (string) $a, (array) $blk['a'] ) ) );
				}
			}
		}
		$text = trim( implode( "\n", array_filter( array_map( 'trim', $lines ) ) ) );

		return array(
			'url'  => $text !== '' ? (string) ( $got['url'] ?? $url ) : '',
			'text' => mb_substr( $text, 0, 18000 ),
		);
	}

	/**
	 * How the network writes a page of this kind, for the AI: the length, the questions, the formulas of the headings, with
	 * the copy's own names put into them.
	 */
	public static function notes( string $kind, string $title ): string {
		$model = Network_Model::get();
		if ( $model === null || ! is_array( $model['kinds'][ $kind ] ?? null ) ) {
			return '';
		}
		$k     = $model['kinds'][ $kind ];
		$names = self::names( $title, $kind );
		$put   = static fn( string $f ): string => strtr(
			$f,
			array(
				'{Service}' => $kind === 'service' ? $names['full'] : '{Service}',
				'{City}'    => $kind === 'location' ? $names['full'] : '{City}',
				'{Co}'      => html_entity_decode( (string) get_option( 'blogname' ), ENT_QUOTES, 'UTF-8' ),
			)
		);
		$lines = array( sprintf( 'How the team\'s other sites write a %s page (for the length and the kind of content, never their words):', $kind === 'location' ? 'city' : $kind ) );
		if ( $k['words_design']['n'] > 0 ) {
			$lines[] = sprintf( '* about %d words in all; a page of this kind has %d sections', $k['words_design']['median'], $k['sections']['median'] );
		}
		if ( $k['faq']['questions']['n'] > 0 ) {
			$lines[] = sprintf( '* the FAQ answers %d questions (%d to %d), about %d words', $k['faq']['questions']['median'], $k['faq']['questions']['p25'], $k['faq']['questions']['p75'], $k['faq']['words']['median'] );
		}
		$heads = array_slice( array_map( static fn( array $f ): string => '"' . $put( ucfirst( (string) $f['formula'] ) ) . '"', (array) ( $model['formulas'][ $kind ] ?? array() ) ), 0, 6 );
		if ( $heads !== array() ) {
			$lines[] = '* headings such as ' . implode( ', ', $heads );
		}
		$family = Network_Model::family( '', $title );
		$asked  = array_slice( array_map( static fn( array $q ): string => $put( (string) $q['q'] ), (array) ( $model['questions'][ $family !== '' ? $family : 'other' ] ?? array() ) ), 0, 8 );
		if ( $kind === 'service' && $asked !== array() ) {
			$lines[] = '* questions people ask about this service: ' . implode( ' ', $asked );
		}

		return count( $lines ) > 1 ? implode( "\n", $lines ) : '';
	}

	/**
	 * Make one copy: the page given (or a new one, for a page the menu names) gets the original's content as its part says,
	 * then its words. Nothing else of the site changes.
	 *
	 * @param array{id?: int, title?: string, link?: string} $target
	 * @param array<int, array<string, mixed>>             $plan   sections() of the original.
	 * @param array{words?: string}                        $opts   words: 'auto' (the AI when there is one, else the outline), 'outline'.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function make( int $source, array $target, array $plan, array $opts = array() ) {
		$src = get_post( $source );
		if ( ! $src instanceof \WP_Post || $src->post_type !== 'page' ) {
			return new \WP_Error( 'dxai_copies_source', __( 'The page to make the copies from is not there.', 'dxai-ui' ), array( 'status' => 404 ) );
		}
		$id    = (int) ( $target['id'] ?? 0 );
		$made  = false;
		$title = trim( (string) ( $target['title'] ?? '' ) );
		if ( $id > 0 ) {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post || $post->post_type !== 'page' || $id === $source ) {
				return new \WP_Error( 'dxai_copies_target', __( 'That page cannot be made from this one.', 'dxai-ui' ), array( 'status' => 400 ) );
			}
			$title = html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' );
		} elseif ( $title === '' ) {
			return new \WP_Error( 'dxai_copies_target', __( 'A new page needs a title.', 'dxai-ui' ), array( 'status' => 400 ) );
		}
		$engine  = ( $opts['words'] ?? 'auto' ) === 'outline' ? null : Copy_Writer::engine();
		$use_ai  = $engine !== null && ! is_wp_error( $engine );
		if ( $id === 0 ) {
			$slug = sanitize_title( basename( untrailingslashit( (string) wp_parse_url( (string) ( $target['link'] ?? '' ), PHP_URL_PATH ) ) ) );
			$id   = wp_insert_post(
				array(
					'post_type'   => 'page',
					'post_status' => 'draft',
					'post_title'  => wp_slash( $title ),
					'post_name'   => $slug !== '' ? $slug : sanitize_title( $title ),
					'post_parent' => (int) $src->post_parent,
					'menu_order'  => (int) $src->menu_order,
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$id   = (int) $id;
			$made = true;
			update_post_meta( $id, self::MADE, '1' );
		}
		$before = (string) get_post_field( 'post_content', $id );
		$link   = (string) get_permalink( $id );
		$built  = self::build( $source, $title, $made ? (string) ( $target['link'] ?? $link ) : $link, $plan, ! $use_ai );
		if ( ! $made && (int) get_post_meta( $id, self::OF, true ) !== $source && get_post_meta( $id, self::BEFORE, true ) === '' && trim( $before ) !== '' ) {
			update_post_meta( $id, self::BEFORE, base64_encode( (string) gzcompress( $before ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the page's own content, kept to give it back.
		}
		self::write( $id, $source, $built['content'] );
		update_post_meta( $id, Copy_Writer::KEEP, $built['keep'] );
		delete_post_meta( $id, Copy_Writer::BEFORE );
		delete_post_meta( $id, Copy_Writer::HASH );

		$report = array(
			'id'       => $id,
			'title'    => $title,
			'link'     => (string) get_permalink( $id ),
			'edit'     => (string) get_edit_post_link( $id, 'raw' ),
			'made'     => $made,
			'kept'     => $built['kept'],
			'written'  => $built['written'],
			'pictures' => $built['pictures'],
			'words'    => 'outline',
			'places'   => $built['places'],
			'old_page' => '',
			'engine'   => '',
			'flags'    => array(),
			'error'    => '',
		);
		if ( $use_ai ) {
			$old   = self::old_page( $id );
			$kind  = self::kind( $source );
			$names = self::names( $title, $kind );
			$topic = $kind === 'location'
				/* translators: %s: a city */
				? sprintf( __( 'The company\'s services in %s: the same kind of page as the one it was made from, for this city.', 'dxai-ui' ), $names['full'] )
				/* translators: %s: a service */
				: sprintf( __( '%s: the same kind of page as the one it was made from, for this service.', 'dxai-ui' ), $names['full'] );
			$asked = Copy_Writer::propose(
				$id,
				array(
					'topic'          => $topic,
					'reference'      => $old['url'],
					'reference_text' => $old['text'],
					'notes'          => self::notes( $kind, $title ),
				),
				true
			);
			if ( is_wp_error( $asked ) ) {
				// No words for another service on the page: its own lines wait for a person.
				$report['error'] = $asked->get_error_message();
				$built           = self::build( $source, $title, $made ? (string) ( $target['link'] ?? $link ) : $link, $plan, true );
				self::write( $id, $source, $built['content'] );
				$report['places'] = $built['places'];
			} else {
				$changes = array();
				foreach ( $asked['blocks'] as $row ) {
					if ( ! empty( $row['changed'] ) ) {
						$changes[ (string) $row['id'] ] = (string) $row['after'];
					}
				}
				$done = Copy_Writer::apply( $id, $changes, (string) $asked['fingerprint'] );
				if ( is_wp_error( $done ) ) {
					$report['error'] = $done->get_error_message();
				} else {
					$report['words']  = 'ai';
					$report['places'] = 0;
					$report['engine'] = (string) $asked['engine'];
					$report['flags']  = (array) $asked['flags'];
					$report['old_page'] = $old['url'];
				}
			}
		}
		// A page the menu named is shown when its words were written; a page with lines to write stays a draft.
		if ( $made && $report['words'] === 'ai' && $report['error'] === '' && get_post_status( $source ) === 'publish' ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'publish',
				)
			);
		}
		update_post_meta( $id, self::OF, $source );
		update_post_meta( $id, self::HASH, md5( (string) get_post_field( 'post_content', $id ) ) );

		return $report;
	}

	/**
	 * A page made from the Home's own sections (Pages\Team_Pages) written for itself, so that no section says the Home's words
	 * on a page about something else (decision 2: no words that stand in): the sections of the roles the site says the same
	 * way everywhere (`$keep`: the reviews, the form, the trust bar, the call to action), the list of the other pages and the
	 * sections without words stay; every other section is written for the page by the AI chosen in Settings with the team's
	 * prompt (from the client's old page when there is one, and how the network writes such a page), or, without an AI, has
	 * its own lines left for a person with the Home's words as their hint.
	 *
	 * @param array<int, string> $keep  Roles whose words stay the Home's.
	 * @param string             $words 'auto' (the AI when there is one) or 'outline'.
	 * @return array{words: string, places: int, kept: int, written: int, engine: string, error: string}
	 */
	public static function write_own_words( int $page_id, array $keep, string $words = 'auto' ): array {
		$before = (string) get_post_field( 'post_content', $page_id );
		$kind   = self::kind( $page_id );
		$title  = html_entity_decode( (string) get_the_title( $page_id ), ENT_QUOTES, 'UTF-8' );
		$name   = self::names( $title, $kind )['full'];
		$engine = $words === 'outline' ? null : Copy_Writer::engine();
		$use_ai = $engine !== null && ! is_wp_error( $engine );
		$out    = array(
			'words'   => 'outline',
			'places'  => 0,
			'kept'    => 0,
			'written' => 0,
			'engine'  => '',
			'error'   => '',
		);
		$lay = function ( bool $outline ) use ( $before, $page_id, $keep, $kind, $name, &$out ): string {
			$blocks = parse_blocks( $before );
			$chrome = array_merge( ...array_values( Section_Library::chrome_blocks( $blocks ) ) );
			$at     = self::layout( $blocks );
			$hashes = array();
			$places = 0;
			$kept   = 0;
			$writ   = 0;
			foreach ( $at['list'] as $n => $i ) {
				$b    = $at['path'] === array() ? $blocks[ $i ] : $blocks[ $at['path'][0] ]['innerBlocks'][ $i ];
				$html = render_block( $b );
				$r    = Page_Reader::one( $html, $n );
				$role = (string) ( $r['role'] ?? '' );
				$own  = ! ( ( $at['path'] === array() && in_array( $i, $chrome, true ) ) || (int) ( $r['words'] ?? 0 ) < 3 || in_array( $role, $keep, true ) || self::sisters_linked( $html, $page_id, $kind ) >= 2 );
				if ( ! $own ) {
					$hashes[] = Copy_Writer::section_hash( $b );
					++$kept;
					continue;
				}
				++$writ;
				if ( $outline ) {
					$b = self::outline( $b, $name, $places, '', array(), in_array( $role, array( 'faq', 'process' ), true ) );
					if ( $at['path'] === array() ) {
						$blocks[ $i ] = $b;
					} else {
						$blocks[ $at['path'][0] ]['innerBlocks'][ $i ] = $b;
					}
				}
			}
			$out['kept']    = $kept;
			$out['written'] = $writ;
			$out['places']  = $places;
			update_post_meta( $page_id, Copy_Writer::KEEP, $hashes );

			return serialize_blocks( $blocks );
		};
		if ( $use_ai ) {
			$lay( false );
			$old   = self::old_page( $page_id );
			$asked = Copy_Writer::propose(
				$page_id,
				array(
					'topic'          => $kind === 'location'
						/* translators: %s: a city */
						? sprintf( __( 'The company\'s services in %s.', 'dxai-ui' ), $name )
						: $title,
					'reference'      => $old['url'],
					'reference_text' => $old['text'],
					'notes'          => self::notes( $kind, $title ),
				),
				true
			);
			if ( ! is_wp_error( $asked ) ) {
				$changes = array();
				foreach ( $asked['blocks'] as $row ) {
					if ( ! empty( $row['changed'] ) ) {
						$changes[ (string) $row['id'] ] = (string) $row['after'];
					}
				}
				$done = Copy_Writer::apply( $page_id, $changes, (string) $asked['fingerprint'] );
				if ( ! is_wp_error( $done ) ) {
					$out['words']  = 'ai';
					$out['engine'] = (string) $asked['engine'];

					return $out;
				}
				$asked = $done;
			}
			$out['error'] = $asked->get_error_message();
		}
		// No AI, or it did not answer: the page's own lines wait for a person.
		$content = $lay( true );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $page_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		clean_post_cache( $page_id );
		Style_Rules::forget( $page_id );

		return $out;
	}

	/**
	 * The content written as it is (the design's own markup: wp_update_post() would run KSES for someone without unfiltered
	 * HTML), with the original's design records.
	 */
	private static function write( int $id, int $source, string $content ): void {
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		clean_post_cache( $id );
		// Written straight to the row: the rules cached for the page are for the content it had.
		Style_Rules::forget( $id );
		foreach ( self::design_meta() as $key ) {
			$value = get_post_meta( $source, $key, true );
			if ( $value === '' || $value === array() || $value === false ) {
				delete_post_meta( $id, $key );
			} else {
				update_post_meta( $id, $key, $value );
			}
		}
	}

	/**
	 * Whether a copy can be given back: it is one, and it is as the run left it.
	 */
	public static function can_give_back( int $id ): bool {
		return (int) get_post_meta( $id, self::OF, true ) > 0 && md5( (string) get_post_field( 'post_content', $id ) ) === (string) get_post_meta( $id, self::HASH, true );
	}

	/**
	 * Give a copy back: the content it had (a page the run made goes to the trash). Not when it was edited since.
	 *
	 * @return true|\WP_Error
	 */
	public static function give_back( int $id ) {
		if ( (int) get_post_meta( $id, self::OF, true ) < 1 ) {
			return new \WP_Error( 'dxai_copies_none', __( 'This page was not made from another page.', 'dxai-ui' ), array( 'status' => 400 ) );
		}
		if ( ! self::can_give_back( $id ) ) {
			return new \WP_Error( 'dxai_copies_edited', __( 'This page was edited after it was made, so it is left as it is.', 'dxai-ui' ), array( 'status' => 409 ) );
		}
		$keys = array( self::OF, self::HASH, self::BEFORE, self::MADE, Copy_Writer::KEEP, Copy_Writer::BEFORE, Copy_Writer::HASH );
		if ( get_post_meta( $id, self::MADE, true ) === '1' ) {
			foreach ( $keys as $k ) {
				delete_post_meta( $id, $k );
			}
			if ( EMPTY_TRASH_DAYS > 0 ) {
				wp_trash_post( $id );
			} else {
				wp_update_post(
					array(
						'ID'          => $id,
						'post_status' => 'draft',
					)
				);
			}

			return true;
		}
		$packed = (string) get_post_meta( $id, self::BEFORE, true );
		$before = $packed !== '' ? gzuncompress( (string) base64_decode( $packed, true ) ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => is_string( $before ) ? $before : '' ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		clean_post_cache( $id );
		Style_Rules::forget( $id );
		foreach ( $keys as $k ) {
			delete_post_meta( $id, $k );
		}

		return true;
	}
}

<?php
/**
 * Where the pages of a team site are read from.
 *
 * @package DXAI_UI\Network
 */

declare(strict_types=1);

namespace DXAI_UI\Network;

/**
 * A site of the network as one record, whichever way it was read:
 *
 *  blog    a subsite of this multisite, read in place (switch_to_blog): its published pages, the front page, the blog's
 *          name, its header menu. Nothing is sent anywhere; the blocks are rendered with the blocks this request knows (the
 *          network shares its theme and plugins), and a page that cannot be rendered is read as saved. `current` reads
 *          the site this request is on the same way.
 *  rest    any WordPress site by its address, through its public REST API — the pages the site shows anyone, the way
 *          the research read 25 sites (GET only, one request at a time).
 *  folder  what .verify/network-research/crawl.cjs saved (pages.json, home.html, meta.json): the research's own data,
 *          read again by the plugin to prove it gives the same numbers.
 *
 * The record: key, url, name (the company), theme, front (the page id of the Home, 0 when the site shows posts), menu
 * (the header's links: text, href, depth), posts, pages (id, slug, link, title, parent, modified, html).
 */
final class Site_Source {

	/** Pages asked for in one REST request. */
	private const PER_PAGE = 50;

	/**
	 * Every site of the network but this one (and the archived, spam or deleted).
	 *
	 * @return array<int, int> Blog ids.
	 */
	public static function network_blogs(): array {
		if ( ! is_multisite() ) {
			return array();
		}
		$ids = get_sites(
			array(
				'fields'   => 'ids',
				'number'   => 500,
				'archived' => 0,
				'spam'     => 0,
				'deleted'  => 0,
			)
		);

		return array_values( array_filter( array_map( 'intval', (array) $ids ), static fn( int $id ): bool => $id !== get_current_blog_id() ) );
	}

	/**
	 * A subsite of this network, read in place.
	 *
	 * @return array<string, mixed>|null Null when the blog is not there.
	 */
	public static function blog( int $blog_id ): ?array {
		if ( ! is_multisite() || get_site( $blog_id ) === null ) {
			return null;
		}
		switch_to_blog( $blog_id );
		try {
			return self::current();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * The site this request is on, read the same way: its published pages (or the ones asked for, in any status).
	 *
	 * @param array<int, int> $only Page ids to read, all published pages when empty.
	 * @return array<string, mixed>
	 */
	public static function current( array $only = array() ): array {
		$url   = trailingslashit( (string) home_url( '/' ) );
		$posts = wp_count_posts( 'post' );
		$site  = array(
			'key'   => self::key( $url ),
			'url'   => $url,
			'name'  => html_entity_decode( (string) get_option( 'blogname' ), ENT_QUOTES, 'UTF-8' ),
			'theme' => (string) get_stylesheet(),
			'front' => get_option( 'show_on_front' ) === 'page' ? (int) get_option( 'page_on_front' ) : 0,
			'menu'  => self::blog_menu(),
			'posts' => (int) ( $posts->publish ?? 0 ),
			'pages' => array(),
		);
		$ids  = $only !== array() ? array_map( 'intval', $only ) : get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post instanceof \WP_Post || $post->post_type !== 'page' ) {
				continue;
			}
			$site['pages'][] = array(
				'id'       => (int) $post->ID,
				'slug'     => (string) $post->post_name,
				'link'     => (string) get_permalink( $post ),
				'title'    => (string) $post->post_title,
				'parent'   => (int) $post->post_parent,
				'modified' => (string) $post->post_modified,
				'html'     => self::render( (string) $post->post_content ),
			);
		}

		return $site;
	}

	/**
	 * The page's blocks as a visitor gets them, without the content filters (no paragraphs added, no shortcodes run).
	 */
	private static function render( string $content ): string {
		if ( ! has_blocks( $content ) ) {
			return $content;
		}
		ob_start();
		try {
			$html = do_blocks( $content );
		} catch ( \Throwable $e ) {
			$html = $content;
		}
		ob_end_clean();

		return $html;
	}

	/**
	 * The header's links of the blog read in place: the menu of the first location that has one, the primary first.
	 *
	 * @return array<int, array{text: string, href: string, depth: int}>
	 */
	private static function blog_menu(): array {
		$locations = (array) get_theme_mod( 'nav_menu_locations', array() );
		$order     = array_unique( array_merge( array_intersect( array( 'primary', 'primary-navigation', 'header', 'main', 'main-menu' ), array_keys( $locations ) ), array_keys( $locations ) ) );
		foreach ( $order as $loc ) {
			$items = wp_get_nav_menu_items( (int) $locations[ $loc ] );
			if ( ! is_array( $items ) || $items === array() ) {
				continue;
			}
			$parent = array();
			$out    = array();
			foreach ( $items as $item ) {
				$depth                       = isset( $parent[ (int) $item->menu_item_parent ] ) ? $parent[ (int) $item->menu_item_parent ] + 1 : 0;
				$parent[ (int) $item->ID ]   = $depth;
				$out[]                       = array(
					'text'  => html_entity_decode( wp_strip_all_tags( (string) $item->title ), ENT_QUOTES, 'UTF-8' ),
					'href'  => (string) $item->url,
					'depth' => $depth,
				);
			}

			return $out;
		}

		return array();
	}

	/**
	 * A site by its address, through its public REST API.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function rest( string $url ) {
		$url  = trailingslashit( esc_url_raw( $url ) );
		$home = self::get( $url, false );
		if ( is_wp_error( $home ) ) {
			return $home;
		}
		$pages = array();
		for ( $n = 1; $n <= 20; $n++ ) {
			$r = self::get( $url . 'wp-json/wp/v2/pages?per_page=' . self::PER_PAGE . '&page=' . $n . '&orderby=id&order=asc&_fields=id,slug,link,title,parent,menu_order,template,date,modified,content', true );
			if ( is_wp_error( $r ) ) {
				if ( $n === 1 ) {
					return $r;
				}
				break;
			}
			$rows = json_decode( $r['body'], true );
			if ( ! is_array( $rows ) || $rows === array() || ! isset( $rows[0] ) ) {
				break;
			}
			foreach ( $rows as $p ) {
				$pages[] = array(
					'id'       => (int) ( $p['id'] ?? 0 ),
					'slug'     => (string) ( $p['slug'] ?? '' ),
					'link'     => (string) ( $p['link'] ?? '' ),
					'title'    => (string) ( $p['title']['rendered'] ?? '' ),
					'parent'   => (int) ( $p['parent'] ?? 0 ),
					'modified' => (string) ( $p['modified'] ?? '' ),
					'html'     => (string) ( $p['content']['rendered'] ?? '' ),
				);
			}
			if ( count( $rows ) < self::PER_PAGE ) {
				break;
			}
		}
		$posts = self::get( $url . 'wp-json/wp/v2/posts?per_page=1&_fields=id', true );

		return self::from_home(
			$url,
			$home['body'],
			$pages,
			is_wp_error( $posts ) ? 0 : (int) $posts['total']
		);
	}

	/**
	 * One GET, the way a browser asks.
	 *
	 * @return array{body: string, total: int}|\WP_Error
	 */
	private static function get( string $url, bool $json ) {
		$r = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 3,
				'headers'     => array( 'Accept' => $json ? 'application/json' : 'text/html' ),
				'user-agent'  => 'Mozilla/5.0 (compatible; DXAI-UI network model; +' . home_url( '/' ) . ')',
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'dxai_network_http', sprintf( '%s answered %d.', $url, $code ) );
		}

		return array(
			'body'  => (string) wp_remote_retrieve_body( $r ),
			'total' => (int) wp_remote_retrieve_header( $r, 'x-wp-total' ),
		);
	}

	/**
	 * Every site crawl.cjs saved under a folder (one folder per site).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function folder( string $dir ): array {
		$out  = array();
		$dirs = glob( rtrim( $dir, '/\\' ) . '/*', GLOB_ONLYDIR );
		foreach ( is_array( $dirs ) ? $dirs : array() as $d ) {
			$site = self::crawled( $d );
			if ( $site !== null ) {
				$out[] = $site;
			}
		}
		usort( $out, static fn( array $a, array $b ): int => strcmp( (string) $a['key'], (string) $b['key'] ) );

		return $out;
	}

	/**
	 * One site crawl.cjs saved.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function crawled( string $dir ): ?array {
		$read = static function ( string $file ) use ( $dir ): ?string {
			$path = $dir . '/' . $file;
			return is_readable( $path ) ? (string) file_get_contents( $path ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		};
		$meta  = json_decode( (string) $read( 'meta.json' ), true );
		$pages = json_decode( (string) $read( 'pages.json' ), true );
		$home  = $read( 'home.html' );
		if ( ! is_array( $meta ) || ! is_array( $pages ) || $home === null ) {
			return null;
		}
		$rows = array();
		foreach ( $pages as $p ) {
			$rows[] = array(
				'id'       => (int) ( $p['id'] ?? 0 ),
				'slug'     => (string) ( $p['slug'] ?? '' ),
				'link'     => (string) ( $p['link'] ?? '' ),
				'title'    => (string) ( $p['title'] ?? '' ),
				'parent'   => (int) ( $p['parent'] ?? 0 ),
				'modified' => (string) ( $p['modified'] ?? '' ),
				'html'     => (string) ( $p['content'] ?? '' ),
			);
		}
		$site        = self::from_home( (string) ( $meta['site'] ?? '' ), $home, $rows, (int) ( $meta['posts_total'] ?? 0 ) );
		$site['key'] = (string) ( $meta['key'] ?? basename( $dir ) );

		return $site;
	}

	/**
	 * The record of a site read from outside: its Home's HTML says the company, the theme, the front page and the menu.
	 *
	 * @param array<int, array<string, mixed>> $pages
	 * @return array<string, mixed>
	 */
	private static function from_home( string $url, string $home, array $pages, int $posts ): array {
		$name = preg_match( '/<title>([^<|–—]+)/i', $home, $m ) === 1 ? $m[1] : '';
		$name = trim( (string) preg_replace( '/\s+-\s+.*$/', '', html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ) ) );

		return array(
			'key'   => self::key( $url ),
			'url'   => $url,
			'name'  => $name,
			'theme' => preg_match( '#wp-content/themes/([a-z0-9_-]+)/#i', $home, $t ) === 1 ? $t[1] : '',
			'front' => preg_match( '/<body[^>]*class="[^"]*\bpage-id-(\d+)/i', $home, $f ) === 1 ? (int) $f[1] : 0,
			'menu'  => self::menu_of( $home ),
			'posts' => $posts,
			'pages' => $pages,
		);
	}

	/**
	 * A site's short name: its host, and the path of a subsite.
	 */
	public static function key( string $url ): string {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );

		return (string) preg_replace( '/[^a-z0-9._-]+/i', '_', (string) preg_replace( '/^www\./', '', $host ) . ( $path !== '' ? '__' . $path : '' ) );
	}

	/**
	 * The header's links in a page's HTML: the links of its navigation, each once, with how deep it sits.
	 *
	 * @return array<int, array{text: string, href: string, depth: int}>
	 */
	public static function menu_of( string $html ): array {
		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT | LIBXML_PARSEHUGE );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$xp     = new \DOMXPath( $doc );
		$header = $xp->query( '(//header)[1]' );
		$root   = $header !== false && $header->length > 0 ? $header->item( 0 ) : $doc->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $root instanceof \DOMElement ) {
			return array();
		}
		$links = $xp->query( './/a[@href][ancestor::nav or ancestor::*[contains(concat(" ", normalize-space(@class), " "), " menu ") or contains(concat(" ", normalize-space(@class), " "), " main-navigation ") or contains(@class, "menu")]]', $root );
		$out   = array();
		$seen  = array();
		foreach ( $links === false ? array() : $links as $a ) {
			if ( ! $a instanceof \DOMElement ) {
				continue;
			}
			$href = (string) $a->getAttribute( 'href' );
			$text = Page_Reader::squash( (string) $a->textContent );
			if ( $text === '' || preg_match( '/^(tel|mailto):|^#/', $href ) === 1 || isset( $seen[ $href . '|' . $text ] ) ) {
				continue;
			}
			$seen[ $href . '|' . $text ] = true;
			$depth                       = 0;
			for ( $n = $a->parentNode; $n instanceof \DOMElement && ! $n->isSameNode( $root ); $n = $n->parentNode ) {
				$depth += strtolower( $n->tagName ) === 'ul' ? 1 : 0;
			}
			$out[] = array(
				'text'  => $text,
				'href'  => $href,
				'depth' => max( 0, $depth - 1 ),
			);
		}

		return $out;
	}
}

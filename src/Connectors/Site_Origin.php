<?php
/**
 * Infer the live site origin and crawl candidates from design menu URLs.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Connectors;

final class Site_Origin {

	public const MAX_PAGES = 40;

	/**
	 * Hosts a design links to that are never its old site: social profiles, maps, fonts, CDNs, XML namespaces.
	 * A design with placeholder menu links has only those as absolute links, and one of them was taken for the
	 * old site (www.w3.org from an SVG's xmlns, facebook.com from a footer icon).
	 */
	private const NOT_SITES = '/(^|\.)(facebook|fb|instagram|twitter|x|linkedin|youtube|youtu|tiktok|pinterest|yelp|google|googleapis|gstatic|goo|g|maps|apple|w3|schema|gravatar|wordpress|wp|jsdelivr|cloudflare|cdnjs|unpkg|bootstrapcdn|fontawesome|typekit|calendly|wa|whatsapp|t|telegram|vimeo|threads|bsky|bbb|trustindex|lovable|lovableproject|vercel|netlify)\.(com|org|net|app|me|be|gl|co|ly|io|dev)$/i';

	/** A host without its leading "www.", lower-case: the old site is one site under both names. */
	public static function bare_host( string $host ): string {
		return (string) preg_replace( '/^www\./', '', strtolower( trim( $host ) ) );
	}

	/**
	 * An origin under both of its names (https://x.com and https://www.x.com).
	 *
	 * @return array<int, string>
	 */
	public static function twins( string $origin ): array {
		$origin = rtrim( $origin, '/' );
		$parts  = wp_parse_url( $origin );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $origin === '' ? array() : array( $origin );
		}
		$bare = self::bare_host( (string) $parts['host'] );
		$tail = ! empty( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$out  = array();
		foreach ( array( 'https', 'http' ) as $scheme ) {
			$out[] = $scheme . '://' . $bare . $tail;
			$out[] = $scheme . '://www.' . $bare . $tail;
		}

		return array_values( array_unique( array_merge( array( $origin ), $out ) ) );
	}

	/** Whether a host can be an old site at all (not a social network, a map, a font or CDN host, this site). */
	public static function may_be_site( string $host ): bool {
		$host = self::bare_host( $host );

		return $host !== '' && ! preg_match( self::NOT_SITES, $host ) && $host !== self::bare_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * The origin most menu URLs lead to (most_linked()), or empty.
	 *
	 * @param array<int, array{label?:string, url?:string, children?:array}> $tree
	 */
	public static function from_menu_tree( array $tree ): string {
		return self::most_linked( self::collect_urls( $tree ) );
	}

	/**
	 * The origin most of these links lead to, among hosts that can be a site — or '' when that is not clear.
	 *
	 * A host linked once is no evidence of the old site: a menu of relative links (a Lovable design's routes) with
	 * one "Book now" to a booking service or a "Careers" link to a job board made that host the old site, every
	 * relative link was crawled from it, and it was remembered as the design's old site. Neither is a tie. An empty
	 * answer is safe — the person is asked for the address. Counted per site under both of its names (x.com and
	 * www.x.com), answered as it is most often written.
	 *
	 * @param array<int, string> $urls
	 */
	private static function most_linked( array $urls ): string {
		$sites     = array();
		$spellings = array();
		foreach ( $urls as $url ) {
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				continue;
			}
			$scheme = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || ! self::may_be_site( (string) $parts['host'] ) ) {
				continue;
			}
			$origin = $scheme . '://' . strtolower( (string) $parts['host'] );
			if ( ! empty( $parts['port'] ) ) {
				$origin .= ':' . (int) $parts['port'];
			}
			$site                          = self::bare_host( (string) $parts['host'] );
			$sites[ $site ]                = ( $sites[ $site ] ?? 0 ) + 1;
			$spellings[ $site ][ $origin ] = ( $spellings[ $site ][ $origin ] ?? 0 ) + 1;
		}
		if ( $sites === array() ) {
			return '';
		}
		arsort( $sites );
		$counts = array_values( $sites );
		if ( $counts[0] < 2 || ( $counts[1] ?? 0 ) === $counts[0] ) {
			return '';
		}
		$written = $spellings[ (string) array_key_first( $sites ) ];
		arsort( $written );

		return (string) array_key_first( $written );
	}

	/**
	 * Fallback: the origin the design's own <a href> links lead to (most_linked()); for a bare URL, its origin.
	 */
	public static function from_html( string $html ): string {
		/*
		 * Links only. Every absolute URL in the markup counted, and the first one won: an SVG's xmlns, a font
		 * preconnect, an image CDN or a picture already moved to this site's uploads — where a design loads things
		 * from, not where its old site is. With the obvious ones excluded (may_be_site()) the next CDN or script
		 * host still won, its pages were crawled and it was saved as the old site.
		 */
		if ( str_contains( $html, '<' ) ) {
			if ( ! preg_match_all( '#<a\b[^>]*?\shref\s*=\s*["\']?\s*(https?://[^\s"\'<>]+)#i', $html, $links ) ) {
				return '';
			}

			return self::most_linked( array_map( static fn( $u ) => html_entity_decode( (string) $u, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $links[1] ) );
		}
		// A URL (Live_Page_Fetcher's base): its own origin, when that host can be a site.
		if ( ! preg_match_all( '#https?://[a-z0-9.-]+(?::\d+)?#i', $html, $all ) ) {
			return '';
		}
		foreach ( $all[0] as $found ) {
			$parts = wp_parse_url( $found );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! self::may_be_site( (string) $parts['host'] ) ) {
				continue;
			}
			$scheme = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );

			return $scheme . '://' . strtolower( (string) $parts['host'] )
				. ( ! empty( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		}

		return '';
	}

	/**
	 * Unique crawl candidates: label + absolute URL + path, same host only.
	 *
	 * @param array<int, array{label?:string, url?:string, children?:array}> $tree
	 * @return array<int, array{label:string, url:string, path:string}>
	 */
	public static function candidates( array $tree, string $origin, int $max = self::MAX_PAGES ): array {
		$origin = rtrim( $origin, '/' );
		if ( $origin === '' ) {
			return array();
		}
		$origin_host = self::bare_host( (string) ( wp_parse_url( $origin, PHP_URL_HOST ) ?: '' ) );
		$seen        = array();
		$out         = array();

		foreach ( self::collect_items( $tree ) as $item ) {
			$url = self::absolutize( (string) ( $item['url'] ?? '' ), $origin );
			if ( $url === '' ) {
				continue;
			}
			$host = self::bare_host( (string) ( wp_parse_url( $url, PHP_URL_HOST ) ?: '' ) );
			if ( $host === '' || $host !== $origin_host ) {
				continue;
			}
			$path = self::path_of( $url );
			if ( $path === '' || isset( $seen[ $path ] ) ) {
				continue;
			}
			// Skip bare home — Home is already created by the ZIP import.
			if ( $path === '/' ) {
				continue;
			}
			// Feeds, uploads, admin, search, and file URLs are not restyle pages.
			if ( preg_match( '#/(wp-admin|wp-login|wp-content|feed|cdn-cgi|sitemap)(/|$)|\.(pdf|jpe?g|png|gif|webp|svg|zip|xml|txt|docx?|css|js)$#i', $path ) ) {
				continue;
			}
			$seen[ $path ] = true;
			$out[]         = array(
				'label' => (string) ( $item['label'] ?? '' ),
				'url'   => $url,
				'path'  => $path,
			);
			if ( count( $out ) >= $max ) {
				break;
			}
		}

		return $out;
	}

	public static function path_of( string $url ): string {
		$path = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?: '/' );
		$path = '/' . ltrim( $path, '/' );
		if ( $path !== '/' ) {
			$path = rtrim( $path, '/' );
		}

		return $path === '' ? '/' : $path;
	}

	/**
	 * Turn a menu href into an absolute same-site URL.
	 *
	 * Keeps path-safe query strings (e.g. ?page_id=12). Maps SPA hash routes
	 * like #/about or #!/services onto /about and /services. Accepts simple
	 * relative paths (about, ./about) against the origin.
	 */
	public static function absolutize( string $url, string $origin ): string {
		$url    = trim( $url );
		$origin = rtrim( $origin, '/' );
		if ( $url === '' || $url === '#' || str_starts_with( $url, 'tel:' ) || str_starts_with( $url, 'mailto:' ) || str_starts_with( $url, 'javascript:' ) ) {
			return '';
		}

		// SPA hash routers: "#/about", "#!/services", "https://x.com/#/about".
		if ( preg_match( '@^(?:https?://[^#]*)?#(!?/[\w./%-]*)@i', $url, $hm ) && isset( $hm[1] ) ) {
			$hash_path = $hm[1];
			if ( str_starts_with( $hash_path, '!' ) ) {
				$hash_path = substr( $hash_path, 1 );
			}
			if ( $hash_path !== '' && $hash_path !== '/' && $origin !== '' ) {
				return $origin . (string) strtok( $hash_path, '?' );
			}
		}

		if ( preg_match( '#^https?://#i', $url ) ) {
			return self::strip_fragment( $url );
		}
		if ( str_starts_with( $url, '//' ) ) {
			$scheme = (string) ( wp_parse_url( $origin, PHP_URL_SCHEME ) ?: 'https' );

			return self::strip_fragment( $scheme . ':' . $url );
		}
		if ( $origin === '' ) {
			return '';
		}
		if ( str_starts_with( $url, '/' ) ) {
			return self::strip_fragment( $origin . $url );
		}
		// Simple relative: "about", "./about", "../about" — not "foo:bar" schemes.
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) {
			return '';
		}
		$rel = preg_replace( '#^\./#', '', $url ) ?? $url;
		if ( $rel === '' || str_starts_with( $rel, '#' ) ) {
			return '';
		}

		return self::strip_fragment( $origin . '/' . ltrim( $rel, '/' ) );
	}

	/** Drop only the fragment; keep query strings needed for WP-style pages. */
	private static function strip_fragment( string $url ): string {
		$hash = strpos( $url, '#' );
		if ( $hash !== false ) {
			$url = substr( $url, 0, $hash );
		}

		return rtrim( $url, '?' );
	}

	/**
	 * An address someone typed for their old site, as an origin: "semperdrywr.com", "https://www.x.com/about/"
	 * → "https://semperdrywr.com", "https://www.x.com". Empty when it is not an http(s) site address.
	 */
	public static function normalize( string $input ): string {
		$input = trim( $input );
		if ( $input === '' ) {
			return '';
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $input ) ) {
			$input = 'https://' . ltrim( $input, '/' );
		}
		$parts  = wp_parse_url( $input );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || ! preg_match( '/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $host ) ) {
			return '';
		}

		return $scheme . '://' . $host . ( ! empty( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}

	/**
	 * The tree with its links to one site pointed at another (the old site's address as the person gave it):
	 * links to $from and relative links go to $to; links to anywhere else — social profiles, this site — stay.
	 *
	 * @param array<int, array<string, mixed>> $tree
	 * @return array<int, array<string, mixed>>
	 */
	public static function rehost( array $tree, string $from, string $to ): array {
		$from_host = self::bare_host( (string) wp_parse_url( $from, PHP_URL_HOST ) );
		$own       = self::bare_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		// Only a host that can be a site: never a social profile or a map moved onto the old site as a page.
		if ( ! self::may_be_site( $from_host ) ) {
			$from_host = '';
		}
		foreach ( $tree as &$row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$url  = (string) ( $row['url'] ?? '' );
			$host = self::bare_host( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( $host !== '' && $from_host !== '' && $host !== $own && $host === $from_host ) {
				$row['url'] = rtrim( $to, '/' ) . self::path_of( $url ) . ( self::path_of( $url ) === '/' ? '' : '/' );
			} elseif ( $host === '' && ( str_starts_with( $url, '/' ) || preg_match( '@^(\./)?[\w./%-]+@', $url ) ) && ! str_starts_with( $url, '//' ) ) {
				$abs = self::absolutize( $url, $to );
				if ( $abs !== '' ) {
					$row['url'] = $abs;
				}
			}
			if ( is_array( $row['children'] ?? null ) ) {
				$row['children'] = self::rehost( $row['children'], $from, $to );
			}
		}
		unset( $row );

		return $tree;
	}

	/**
	 * The old site's own navigation — its header, menus and footer links — as a menu tree, for a design whose
	 * menus do not lead to the old site's pages. One fetch of the old home page, pinned to its host, cached.
	 *
	 * @return array<int, array{label:string, url:string, children:array}>
	 */
	public static function from_site( string $origin ): array {
		$origin = self::normalize( $origin );
		if ( $origin === '' ) {
			return array();
		}
		$key    = 'dxai_ui_site_nav_' . md5( $origin );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$fetched = ( new Live_Page_Fetcher() )->fetch( $origin . '/', 30, $origin );
		$html    = is_wp_error( $fetched ) ? '' : (string) ( $fetched['raw'] ?? '' );
		$tree    = array();
		if ( $html !== '' ) {
			$dom  = new \DOMDocument( '1.0', 'UTF-8' );
			$prev = libxml_use_internal_errors( true );
			$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NODEFDTD | LIBXML_NOWARNING | LIBXML_NOERROR );
			libxml_clear_errors();
			libxml_use_internal_errors( $prev );
			$xp    = new \DOMXPath( $dom );
			$links = $xp->query( '//nav//a[@href] | //header//a[@href] | //footer//a[@href] | //*[contains(concat(" ", normalize-space(@class), " "), " menu ")]//a[@href]' );
			if ( ! $links || $links->length === 0 ) {
				$links = $xp->query( '//a[@href]' );
			}
			// The old site as it answered (it may have moved to its www. name, or off it).
			$landed = is_wp_error( $fetched ) ? '' : (string) ( $fetched['url'] ?? '' );
			$host   = self::bare_host( (string) wp_parse_url( $landed !== '' ? $landed : $origin, PHP_URL_HOST ) );
			$seen   = array();
			foreach ( $links ?: array() as $a ) {
				if ( ! $a instanceof \DOMElement ) {
					continue;
				}
				$url = self::absolutize( html_entity_decode( (string) $a->getAttribute( 'href' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $origin );
				if ( $url === '' || self::bare_host( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $host ) {
					continue;
				}
				$path = self::path_of( $url );
				// Pages only: not the home page, feeds, uploads, admin, search or files.
				if ( $path === '/' || isset( $seen[ $path ] ) || preg_match( '#/(wp-admin|wp-login|wp-content|feed|cdn-cgi)(/|$)|\.(pdf|jpe?g|png|gif|webp|svg|zip|xml|txt|docx?)$#i', $path ) ) {
					continue;
				}
				$seen[ $path ] = true;
				$tree[]        = array(
					'label'    => mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', (string) $a->textContent ) ), 0, 80 ),
					'url'      => $url,
					'children' => array(),
				);
				if ( count( $tree ) >= self::MAX_PAGES ) {
					break;
				}
			}
		}
		set_transient( $key, $tree, $tree === array() ? 5 * MINUTE_IN_SECONDS : 12 * HOUR_IN_SECONDS );

		return $tree;
	}

	/**
	 * @param array<int, array{label?:string, url?:string, children?:array}> $tree
	 * @return array<int, string>
	 */
	private static function collect_urls( array $tree ): array {
		$urls = array();
		foreach ( self::collect_items( $tree ) as $item ) {
			$urls[] = (string) ( $item['url'] ?? '' );
		}

		return $urls;
	}

	/**
	 * @param array<int, array{label?:string, url?:string, children?:array}> $tree
	 * @return array<int, array{label:string, url:string}>
	 */
	private static function collect_items( array $tree ): array {
		$out = array();
		foreach ( $tree as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$out[] = array(
				'label' => (string) ( $item['label'] ?? '' ),
				'url'   => (string) ( $item['url'] ?? '' ),
			);
			$kids = is_array( $item['children'] ?? null ) ? $item['children'] : array();
			foreach ( self::collect_items( $kids ) as $child ) {
				$out[] = $child;
			}
		}

		return $out;
	}
}

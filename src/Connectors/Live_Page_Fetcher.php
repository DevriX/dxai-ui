<?php
/**
 * Fetch a live page and extract main content + image URLs.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Connectors;

use DXAI_UI\Http\Browser_Headers;
use DXAI_UI\Media\Url_Guard;
use DXAI_UI\Pages\Html_Main;

final class Live_Page_Fetcher {

	/** Redirect hops followed by hand, each one re-validated. */
	private const MAX_REDIRECTS = 5;

	/** A page, not a download: anything larger is cut off rather than held in memory. */
	private const MAX_BYTES = 5 * 1024 * 1024;

	/**
	 * Fetch one page of the design's live site.
	 *
	 * This takes URLs out of an imported design's own menu, so the URL is
	 * attacker-influenced by construction: anyone who can hand over a ZIP chose
	 * every href in it. It used to go through a plain wp_remote_request(),
	 * which checks nothing and follows redirects on its own — a public host
	 * answering `302 Location: http://169.254.169.254/latest/meta-data/` or
	 * `http://127.0.0.1:…/` would have been fetched from inside the server and
	 * its body handed to the restyler. Url_Guard already existed for exactly
	 * this and the Sideloader already used it; this path did not.
	 *
	 * So every hop is checked, not just the first: redirects are followed by
	 * hand, and each Location is re-validated by Url_Guard (private, loopback
	 * and link-local addresses refused, via wp_http_validate_url()) and, when
	 * `$allowed_host` is given, must stay on that host. The request itself is
	 * wp_safe_remote_get(), which sets reject_unsafe_urls, and the body is
	 * capped at MAX_BYTES.
	 *
	 * @param string $allowed_host The design's own site — a bare host or an origin URL. When set, the
	 *                             page and every redirect must stay on it (a leading `www.` is ignored).
	 * @return array{html:string, images:array<int, string>, title:string, raw?:string, url?:string}|\WP_Error
	 */
	public function fetch( string $url, int $timeout = 45, string $allowed_host = '' ): array|\WP_Error {
		$url = esc_url_raw( $url );
		if ( $url === '' ) {
			return new \WP_Error( 'dxai_ui_live', __( 'Empty URL.', 'dxai-ui' ), array( 'status' => 400 ) );
		}
		$pin            = $allowed_host === '' ? '' : self::host( str_contains( $allowed_host, '://' ) ? $allowed_host : 'https://' . $allowed_host );
		$missing_location = false;

		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			$refused = self::refuse( $url, $pin );
			if ( $refused instanceof \WP_Error ) {
				return $refused;
			}

			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => $timeout,
					'redirection'         => 0,
					'limit_response_size' => self::MAX_BYTES,
					'headers'             => Browser_Headers::html(),
				)
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 300 && $code < 400 ) {
				$location = trim( (string) wp_remote_retrieve_header( $response, 'location' ) );
				if ( $location === '' ) {
					$missing_location = true;
					break;
				}
				$url = esc_url_raw( \WP_Http::make_absolute_url( $location, $url ) );
				continue;
			}

			$body = (string) wp_remote_retrieve_body( $response );
			// The site asks to slow down: the crawl waits (Crawl_Politeness), it does not retry at once.
			if ( $code === 429 ) {
				$retry = (int) wp_remote_retrieve_header( $response, 'retry-after' );

				return new \WP_Error(
					'dxai_ui_live_rate_limited',
					sprintf(
						/* translators: %s: URL */
						__( '%s asked to slow down (HTTP 429).', 'dxai-ui' ),
						$url
					),
					array(
						'status'      => 502,
						'retry_after' => $retry > 0 ? min( 600, $retry ) : 60,
					)
				);
			}
			// A bot-protection page, however it is served: the crawl stops (Site_From_Menu), it is never worked around.
			if ( ( $code === 403 || $code === 503 ) && ( (string) wp_remote_retrieve_header( $response, 'cf-mitigated' ) !== '' || Html_Main::is_challenge( $body ) ) ) {
				return new \WP_Error(
					'dxai_ui_live_blocked',
					sprintf(
						/* translators: %s: URL */
						__( 'Blocked by bot protection on %s — the page cannot be scraped from the server.', 'dxai-ui' ),
						$url
					),
					array( 'status' => 502 )
				);
			}
			if ( $code < 200 || $code >= 300 || $body === '' ) {
				return new \WP_Error(
					'dxai_ui_live',
					sprintf(
						/* translators: 1: HTTP status, 2: URL */
						__( 'Could not fetch %2$s (HTTP %1$d).', 'dxai-ui' ),
						$code,
						$url
					),
					array( 'status' => 502 )
				);
			}

			if ( Html_Main::is_challenge( $body ) ) {
				return new \WP_Error(
					'dxai_ui_live_blocked',
					sprintf(
						/* translators: %s: URL */
						__( 'Blocked by bot protection on %s — the page cannot be scraped from the server.', 'dxai-ui' ),
						$url
					),
					array( 'status' => 502 )
				);
			}

			if ( Html_Main::is_spa_shell( $body ) ) {
				return new \WP_Error(
					'dxai_ui_live_spa',
					sprintf(
						/* translators: %s: URL */
						__( 'Client-rendered page at %s has no usable server HTML — live scrape needs server-rendered content.', 'dxai-ui' ),
						$url
					),
					array( 'status' => 502 )
				);
			}

			// The whole document too: the page-from-design import reads its sections (Pages/Content_Extractor).
			return $this->extract( $body, $url ) + array(
				'raw' => $body,
				'url' => $url,
			);
		}

		if ( $missing_location ) {
			return new \WP_Error(
				'dxai_ui_live',
				sprintf(
					/* translators: %s: URL */
					__( 'Redirect from %s had no Location header.', 'dxai-ui' ),
					$url
				),
				array( 'status' => 502 )
			);
		}

		return new \WP_Error(
			'dxai_ui_live',
			sprintf(
				/* translators: %s: URL */
				__( 'Gave up on %s: too many redirects.', 'dxai-ui' ),
				$url
			),
			array( 'status' => 502 )
		);
	}

	/**
	 * Why this URL must not be fetched, or null when it may be.
	 */
	private static function refuse( string $url, string $pin ): ?\WP_Error {
		if ( ! Url_Guard::is_safe_http( $url ) ) {
			return new \WP_Error(
				'dxai_ui_live_unsafe',
				sprintf(
					/* translators: %s: URL */
					__( 'Refused to fetch %s: not a public web address.', 'dxai-ui' ),
					$url
				),
				array( 'status' => 400 )
			);
		}
		if ( $pin !== '' && self::host( $url ) !== $pin ) {
			return new \WP_Error(
				'dxai_ui_live_offsite',
				sprintf(
					/* translators: 1: URL, 2: the design's own host */
					__( 'Refused to fetch %1$s: it is not on %2$s.', 'dxai-ui' ),
					$url,
					$pin
				),
				array( 'status' => 400 )
			);
		}

		return null;
	}

	/** A URL's host, lower-cased, without a leading `www.`. */
	private static function host( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * @return array{html:string, images:array<int, string>, title:string}
	 */
	public function extract( string $html, string $base_url = '' ): array {
		$dom  = new \DOMDocument( '1.0', 'UTF-8' );
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$title = '';
		foreach ( $dom->getElementsByTagName( 'title' ) as $node ) {
			$title = trim( preg_replace( '/\s+/', ' ', $node->textContent ) ?? '' );
			break;
		}

		$main = Html_Main::find( $dom );
		$fragment = '';
		if ( $main instanceof \DOMElement ) {
			$before = strlen( trim( preg_replace( '/\s+/', ' ', $main->textContent ?? '' ) ?? '' ) );
			Html_Main::strip_chrome( $main );
			$after = strlen( trim( preg_replace( '/\s+/', ' ', $main->textContent ?? '' ) ?? '' ) );
			// If class-based strip wiped almost everything, re-find and strip only scripts/styles.
			if ( $before > 200 && $after < max( 40, (int) ( $before * 0.15 ) ) ) {
				$dom2 = new \DOMDocument( '1.0', 'UTF-8' );
				$prev = libxml_use_internal_errors( true );
				$dom2->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NODEFDTD );
				libxml_clear_errors();
				libxml_use_internal_errors( $prev );
				$main2 = Html_Main::find( $dom2 );
				if ( $main2 instanceof \DOMElement ) {
					self::strip_scripts_only( $main2 );
					$fragment = Html_Main::inner_html( $main2 );
				}
			} else {
				$fragment = Html_Main::inner_html( $main );
			}
		}

		$images = Html_Main::collect_images(
			$fragment !== '' ? $fragment : $html,
			$base_url,
			fn( string $src, string $base ): string => $this->absolutize( $src, $base )
		);

		return array(
			'html'   => $fragment,
			'images' => $images,
			'title'  => $title,
		);
	}

	private static function strip_scripts_only( \DOMElement $root ): void {
		$remove = array();
		foreach ( array( 'script', 'style', 'noscript', 'template' ) as $tag ) {
			foreach ( $root->getElementsByTagName( $tag ) as $node ) {
				if ( $node instanceof \DOMElement ) {
					$remove[] = $node;
				}
			}
		}
		foreach ( $remove as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}
	}

	private function absolutize( string $url, string $base ): string {
		$url = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( $url === '' || str_starts_with( $url, 'data:' ) ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return (string) strtok( $url, '#' );
		}
		if ( str_starts_with( $url, '//' ) ) {
			$scheme = (string) ( wp_parse_url( $base, PHP_URL_SCHEME ) ?: 'https' );

			return $scheme . ':' . (string) strtok( $url, '#' );
		}
		if ( $base === '' ) {
			return '';
		}
		$origin = '';
		$parts  = wp_parse_url( $base );
		if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
			$origin = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host']
				. ( ! empty( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		}
		if ( $origin === '' ) {
			return '';
		}
		if ( str_starts_with( $url, '/' ) ) {
			return rtrim( $origin, '/' ) . (string) strtok( $url, '#' );
		}

		$dir = (string) ( wp_parse_url( $base, PHP_URL_PATH ) ?: '/' );
		$dir = trailingslashit( dirname( $dir ) );

		return rtrim( $origin, '/' ) . $dir . (string) strtok( $url, '#' );
	}
}

<?php
/**
 * What an old site asks of the crawler that rebuilds its pages.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Http;

/**
 * The page builder fetches a client's old site page by page (Site_From_Menu).
 * It does that the way a well-behaved crawler does:
 *
 *  - robots.txt is read once per site (cached for 12 hours) and a path it
 *    disallows for this crawler or for every crawler is not fetched;
 *  - requests to one host are spaced by its Crawl-delay, at least one second
 *    apart, measured across requests (the crawl runs over many);
 *  - a 429's Retry-After is honoured by pushing the next request back.
 *
 * Nothing here gets around a site's protection: a challenge or a block stops
 * the crawl (Live_Page_Fetcher, Site_From_Menu).
 */
final class Crawl_Politeness {

	/** The shortest gap between two requests to one host, in seconds. */
	private const MIN_GAP = 1.0;

	/** The longest a single wait may take inside one request; longer waits yield and resume on the next step. */
	private const MAX_WAIT = 10.0;

	/** A Crawl-delay above this is capped (a misconfigured file must not stall a crawl for hours). */
	private const MAX_DELAY = 30.0;

	/**
	 * The rules robots.txt gives this crawler on a site.
	 *
	 * @return array{disallow: array<int, string>, allow: array<int, string>, delay: float}
	 */
	public static function rules( string $url ): array {
		$origin = self::origin( $url );
		$none   = array(
			'disallow' => array(),
			'allow'    => array(),
			'delay'    => self::MIN_GAP,
		);
		if ( $origin === '' ) {
			return $none;
		}
		$key    = 'dxai_ui_robots_' . md5( $origin );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['delay'] ) ) {
			return $cached;
		}
		$response = wp_safe_remote_get(
			$origin . '/robots.txt',
			array(
				'timeout'             => 8,
				'redirection'         => 2,
				'limit_response_size' => 256 * 1024,
				'headers'             => array( 'User-Agent' => Browser_Headers::USER_AGENT ),
			)
		);
		$code  = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$rules = $code === 200 ? self::parse( (string) wp_remote_retrieve_body( $response ) ) : $none;
		set_transient( $key, $rules, 12 * HOUR_IN_SECONDS );

		return $rules;
	}

	/**
	 * robots.txt read for this crawler: its own group when there is one (`dxai-ui`), else the `*` group.
	 *
	 * @return array{disallow: array<int, string>, allow: array<int, string>, delay: float}
	 */
	public static function parse( string $txt ): array {
		$groups  = array();
		$current = array();
		$open    = false;
		foreach ( preg_split( '/\r\n|\r|\n/', $txt ) ?: array() as $line ) {
			$line = trim( (string) preg_replace( '/#.*$/', '', $line ) );
			if ( ! preg_match( '/^([a-z-]+)\s*:\s*(.*)$/i', $line, $m ) ) {
				continue;
			}
			$field = strtolower( $m[1] );
			$value = trim( $m[2] );
			if ( $field === 'user-agent' ) {
				if ( ! $open ) {
					$current = array();
				}
				$current[] = strtolower( $value );
				$open      = true;
				foreach ( $current as $agent ) {
					$groups[ $agent ] = $groups[ $agent ] ?? array(
						'disallow' => array(),
						'allow'    => array(),
						'delay'    => 0.0,
					);
				}
				continue;
			}
			$open = false;
			foreach ( $current as $agent ) {
				if ( $field === 'disallow' && $value !== '' ) {
					$groups[ $agent ]['disallow'][] = $value;
				} elseif ( $field === 'allow' && $value !== '' ) {
					$groups[ $agent ]['allow'][] = $value;
				} elseif ( $field === 'crawl-delay' && is_numeric( $value ) ) {
					$groups[ $agent ]['delay'] = (float) $value;
				}
			}
		}
		$group = null;
		foreach ( $groups as $agent => $rules ) {
			// A group names a crawler by a token its name contains (`dxai`, `dxai-ui`).
			if ( $agent !== '*' && $agent !== '' && str_contains( 'dxai-ui', $agent ) ) {
				$group = $rules;
				break;
			}
		}
		$group = $group ?? ( $groups['*'] ?? array(
			'disallow' => array(),
			'allow'    => array(),
			'delay'    => 0.0,
		) );
		$group['delay'] = min( self::MAX_DELAY, max( self::MIN_GAP, (float) $group['delay'] ) );

		return $group;
	}

	/**
	 * Whether robots.txt lets this crawler fetch a URL: the longest matching rule wins, Allow on a tie.
	 *
	 * @param array{disallow: array<int, string>, allow: array<int, string>, delay: float} $rules
	 */
	public static function allowed( string $url, array $rules ): bool {
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$path  = ( $path === '' ? '/' : $path ) . ( $query !== '' ? '?' . $query : '' );
		$best  = -1;
		$ok    = true;
		foreach ( array( 'disallow' => false, 'allow' => true ) as $kind => $verdict ) {
			foreach ( $rules[ $kind ] as $pattern ) {
				if ( self::matches( $pattern, $path ) && ( strlen( $pattern ) > $best || ( strlen( $pattern ) === $best && $verdict ) ) ) {
					$best = strlen( $pattern );
					$ok   = $verdict;
				}
			}
		}

		return $ok;
	}

	/**
	 * Wait until this host may be asked again. Returns false, without waiting, when that is further away than one
	 * wait may take: the crawl step then yields and tries again on its next call.
	 */
	public static function wait( string $url, float $delay ): bool {
		$next = (float) get_transient( self::slot_key( $url ) );
		$left = $next - microtime( true );
		if ( $left <= 0 ) {
			return true;
		}
		if ( $left > self::MAX_WAIT ) {
			return false;
		}
		usleep( (int) ( $left * 1e6 ) );

		return true;
	}

	/** Note a request to this host: the next may go after $delay seconds (or later, after a 429's Retry-After). */
	public static function mark( string $url, float $delay ): void {
		set_transient( self::slot_key( $url ), microtime( true ) + max( self::MIN_GAP, $delay ), HOUR_IN_SECONDS );
	}

	private static function slot_key( string $url ): string {
		return 'dxai_ui_crawl_slot_' . md5( self::origin( $url ) );
	}

	private static function origin( string $url ): string {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$port   = wp_parse_url( $url, PHP_URL_PORT );
		if ( $host === '' || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		return $scheme . '://' . $host . ( $port ? ':' . (int) $port : '' );
	}

	/** A robots.txt path pattern (prefix, `*` any run, `$` end) against a path. */
	private static function matches( string $pattern, string $path ): bool {
		$re = '#^' . str_replace( array( '\*', '\$' ), array( '.*', '$' ), preg_quote( $pattern, '#' ) ) . '#';

		return preg_match( $re, $path ) === 1;
	}
}

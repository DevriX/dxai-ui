<?php
/**
 * The background pictures an old page sets in its linked stylesheets.
 *
 * Page builders put a section's background photo in CSS, not in the markup:
 * Elementor writes `.elementor-element-49f2e8e{background-image:url(…)}`
 * into the page's own stylesheet (post-119.css), and optimisers such as
 * NitroPack combine those into a few sheets on a CDN. Reading only the
 * page's <style> elements missed every one of them, so a section that was a
 * photo band on the old site came out plain. This reads the page's linked
 * stylesheets — a few, small, cached — and keeps only the rules that set a
 * background picture, for Content_Extractor::extract().
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

use DXAI_UI\Http\Browser_Headers;

final class Linked_Styles {

	/** At most this many stylesheets per page. */
	private const MAX_SHEETS = 8;

	/** And at most this many seconds reading them, however much of the request is left. */
	private const BUDGET = 12;

	/** A stylesheet bigger than this is not read. */
	private const MAX_BYTES = 3145728;

	/**
	 * The rules of the page's linked stylesheets that set a background picture.
	 *
	 * @return array<int, string> CSS text, one entry per stylesheet.
	 */
	public static function background_rules( string $html, string $page_url, float $budget = self::BUDGET ): array {
		$out    = array();
		$start  = microtime( true );
		$budget = min( (float) self::BUDGET, $budget );
		foreach ( array_slice( self::sheets( $html, $page_url ), 0, self::MAX_SHEETS ) as $url ) {
			$key    = 'dxai_ui_bgcss_' . md5( $url );
			$cached = get_transient( $key );
			if ( is_string( $cached ) ) {
				$out[] = $cached;
				continue;
			}
			$left = $budget - ( microtime( true ) - $start );
			if ( $left < 2 ) {
				break;
			}
			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => (int) min( 6, max( 2, floor( $left ) ) ),
					'limit_response_size' => self::MAX_BYTES,
					'headers'             => Browser_Headers::css(),
				)
			);
			if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
				continue;
			}
			$rules = self::picture_rules( (string) wp_remote_retrieve_body( $response ), $url );
			set_transient( $key, $rules, 12 * HOUR_IN_SECONDS );
			$out[] = $rules;
		}

		return array_values( array_filter( $out ) );
	}

	/**
	 * The page's stylesheet URLs, absolute, in document order.
	 *
	 * @return array<int, string>
	 */
	private static function sheets( string $html, string $page_url ): array {
		$urls = array();
		if ( ! preg_match_all( '#<link\b[^>]*>#i', $html, $tags ) ) {
			return $urls;
		}
		foreach ( $tags[0] as $tag ) {
			$rel = preg_match( '#\brel\s*=\s*["\']?([^"\'>]+)#i', $tag, $m ) ? strtolower( $m[1] ) : '';
			$as  = preg_match( '#\bas\s*=\s*["\']?([^"\'\s>]+)#i', $tag, $m ) ? strtolower( $m[1] ) : '';
			if ( ! str_contains( $rel, 'stylesheet' ) && ! ( str_contains( $rel, 'preload' ) && $as === 'style' ) ) {
				continue;
			}
			if ( ! preg_match( '#\bhref\s*=\s*["\']([^"\']+)#i', $tag, $m ) ) {
				continue;
			}
			$url = self::absolute( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $page_url );
			// Fonts and icon sets hold no section pictures.
			if ( $url === '' || preg_match( '#fonts\.googleapis|font-?awesome|dashicons|/fonts?/#i', $url ) ) {
				continue;
			}
			$urls[] = $url;
		}

		return array_values( array_unique( $urls ) );
	}

	/** The rules of a stylesheet that set a background picture, their url()s made absolute. */
	private static function picture_rules( string $css, string $sheet_url ): string {
		$css = preg_replace( '#/\*.*?\*/#s', '', $css ) ?? $css;
		if ( ! preg_match_all( '/[^{}]+\{[^{}]*background(?:-image)?\s*:[^;{}]*url\([^{}]*\}/i', $css, $m ) ) {
			return '';
		}
		$rules = implode( "\n", $m[0] );

		return (string) preg_replace_callback(
			'/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
			static fn( $u ) => str_starts_with( $u[2], 'data:' ) ? $u[0] : 'url("' . self::absolute( $u[2], $sheet_url ) . '")',
			$rules
		);
	}

	/** A URL relative to $base, made absolute (http and https only). */
	private static function absolute( string $url, string $base ): string {
		$url = trim( $url );
		if ( $url === '' ) {
			return '';
		}
		if ( str_starts_with( $url, '//' ) ) {
			return ( (string) wp_parse_url( $base, PHP_URL_SCHEME ) ?: 'https' ) . ':' . $url;
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) {
			return ''; // data:, javascript: and the like
		}
		$parts  = wp_parse_url( $base );
		$origin = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		if ( str_starts_with( $url, '/' ) ) {
			return $origin . $url;
		}
		$dir = preg_replace( '#/[^/]*$#', '/', (string) ( $parts['path'] ?? '/' ) ) ?? '/';

		return $origin . $dir . $url;
	}
}

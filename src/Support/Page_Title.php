<?php
/**
 * Turn source SEO <title> strings into short WordPress page titles.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Support;

final class Page_Title {

	public const SEO_META = '_dxai_ui_seo_title';

	/**
	 * Design.com / scraped pages ship long browser titles
	 * ("Service … | Brand (phone)"). Those belong in the document <title>,
	 * not as the editable WordPress page name in admin/menus.
	 *
	 * A route page is named after ITSELF: the segment before the first "|",
	 * which is where every "|" title in the local corpus (the Lovable route
	 * heads and both Claude Design <title>s) puts the page's subject ("ICP
	 * Segmentation | DevriX", "RevOps Audit & Maturity Assessment | DevriX",
	 * "Water Damage Restoration Olympia WA | … | H2O Away (phone)"). The full
	 * string is not lost — Structure_Repository::save() stores it as SEO_META
	 * and Plugin::document_title() prints it as the document <title>.
	 *
	 * This used to return the LAST segment, the brand, for route pages too —
	 * the brand preference belongs to for_home() only. Measured on the local
	 * install before the fix: page 89306, post_name `dxai-icp-segmentation`
	 * (ICP Activated (78).zip, src/routes/icp-segmentation.tsx, title "ICP
	 * Segmentation | DevriX"), was saved with post_title "DevriX" — the same
	 * name as the seven DevriX home pages, so the admin list and every menu
	 * built from page titles showed the brand where the page should be, and
	 * Structure_Repository::link_paths() registered `/devrix` and
	 * `/dxai-devrix` as paths of that route page.
	 */
	public static function for_page( string $source_title, string $fallback = 'Home' ): string {
		return self::shorten( $source_title, $fallback, false );
	}

	/**
	 * Home / index routes always use a stable short label.
	 *
	 * The home page is the one page that may carry the brand: for a title
	 * shaped "Subject | Brand (phone)" it takes the brand segment, so H2O's
	 * "Water Damage Restoration Olympia WA | 24/7 Cleanup & Repair | H2O Away
	 * (855) 560-7463" still names its home "H2O Away".
	 */
	public static function for_home( string $source_title = '' ): string {
		$brand = self::shorten( $source_title, 'Home', true );
		if ( $brand !== '' && strcasecmp( $brand, 'Home' ) !== 0 && strlen( $brand ) <= 32 ) {
			// Keep brand when it's clearly the site name (e.g. "H2O Away").
			return $brand;
		}

		return 'Home';
	}

	/**
	 * A source <title> cut down to an editable page name.
	 *
	 * @param bool $brand True for the home page: prefer the brand-ish last
	 *                    "|" segment. False for a route page: prefer the first
	 *                    segment, the page's own subject.
	 */
	private static function shorten( string $source_title, string $fallback, bool $brand ): string {
		$raw = trim( html_entity_decode( wp_strip_all_tags( $source_title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$raw = preg_replace( '/\s+/u', ' ', $raw ) ?? $raw;
		if ( $raw === '' ) {
			return $fallback;
		}

		// Already a short editorial title.
		if ( strlen( $raw ) <= 48 && ! str_contains( $raw, '|' ) ) {
			return sanitize_text_field( $raw );
		}

		$parts = array_values(
			array_filter(
				array_map( 'trim', explode( '|', $raw ) ),
				static fn( string $p ): bool => $p !== ''
			)
		);

		// Home only: prefer the brand-ish last segment, strip trailing phone numbers.
		if ( $brand && $parts !== array() ) {
			$last = (string) end( $parts );
			$last = preg_replace( '/\s*[\(\[]?\+?\d[\d\s().-]{6,}\)?\s*$/u', '', $last ) ?? $last;
			$last = trim( $last, " \t\n\r\0\x0B-|–—" );
			if ( $last !== '' && strlen( $last ) <= 40 ) {
				return sanitize_text_field( $last );
			}
		}

		// The page's own subject: what a route page is always named after, and
		// the home page's fallback when its brand segment is missing or long.
		if ( isset( $parts[0] ) && strlen( $parts[0] ) <= 48 ) {
			return sanitize_text_field( $parts[0] );
		}

		$cut = function_exists( 'mb_substr' ) ? mb_substr( $raw, 0, 48 ) : substr( $raw, 0, 48 );

		return sanitize_text_field( rtrim( $cut ) . '…' );
	}
}

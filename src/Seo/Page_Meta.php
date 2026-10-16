<?php
/**
 * A design's page described for search engines.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Seo;

use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Theme\Theme_Compat;

/**
 * The pages a design makes — its Home, the pages from its menu and from its old site — say what they are about to search engines
 * and to whoever shares them: `<meta name="description">`, from the page's excerpt when it has one, else its first paragraph that
 * says something (twelve words or more), cut at a word to 155 characters. Lighthouse's SEO audit counts a page without one (every
 * page of H2O Away scored 92 for it, docs/PLAN-PERFECT-PAGES.md, T5.4). Not when an SEO plugin writes one (Yoast, Rank Math, All in
 * One SEO, SEOPress, The SEO Framework, Slim SEO; `dxai_ui_seo_plugin` says otherwise): with one of them on, the head is read when it is
 * written and the page's description is added only where the head has none — Rank Math was on for Taylored Restoration's site on the
 * team's network and wrote nothing in the head of its pages, so they had no description at all (2026-10-16). `dxai_ui_meta_description`
 * changes it, or removes it with ''.
 */
final class Page_Meta {

	/** The longest description: what a search result shows before it cuts. */
	public const MAX = 155;

	/** The fewest words a paragraph has to say something. */
	private const WORDS = 12;

	/** Whether the head is being read for an SEO plugin's description (listen() … after_seo()). */
	private static bool $listening = false;

	public function register(): void {
		add_action( 'wp_head', array( self::class, 'print_description' ), 2 );
		// With an SEO plugin on, the head as it is written, from before the plugin's tags (Rank Math's, at 1) to after everything.
		add_action( 'wp_head', array( self::class, 'listen' ), 0 );
		add_action( 'wp_head', array( self::class, 'after_seo' ), PHP_INT_MAX );
	}

	/** The meta tag, on a design's page (with an SEO plugin on: after_seo()). */
	public static function print_description(): void {
		$id = self::page();
		if ( $id < 1 || self::seo_plugin() ) {
			return;
		}
		self::emit( $id );
	}

	/** With an SEO plugin on: read the head as it is written. */
	public static function listen(): void {
		if ( ! self::$listening && self::page() > 0 && self::seo_plugin() ) {
			self::$listening = ob_start();
		}
	}

	/** …and at its end, the page's description when nothing in it gave one. */
	public static function after_seo(): void {
		if ( ! self::$listening ) {
			return;
		}
		self::$listening = false;
		$head            = (string) ob_get_clean();
		echo $head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the head as WordPress and its plugins wrote it.
		if ( preg_match( '/<meta\s[^>]*\bname\s*=\s*["\']?description\b/i', $head ) !== 1 ) {
			self::emit( self::page() );
		}
	}

	/** The page being drawn, when it is a design's page; 0 otherwise. */
	private static function page(): int {
		$id = is_singular( 'page' ) ? (int) get_queried_object_id() : 0;

		return $id > 0 && self::is_design_page( $id ) ? $id : 0;
	}

	private static function emit( int $id ): void {
		$text = $id > 0 ? self::description( $id ) : '';
		if ( $text !== '' ) {
			echo '<meta name="description" content="' . esc_attr( $text ) . '" />' . "\n";
		}
	}

	/** Whether a page is a design's: one drawn by the design's template, or one in a design's scope (the pages made for it). */
	public static function is_design_page( int $id ): bool {
		return Theme_Compat::is_converted_page() || (int) get_post_meta( $id, Page_Scope::META, true ) > 0;
	}

	/** What a page is about: its excerpt, else its lead paragraph, cut to MAX. */
	public static function description( int $id ): string {
		$text = trim( (string) get_post_field( 'post_excerpt', $id ) );
		if ( $text === '' ) {
			$text = self::lead( (string) get_post_field( 'post_content', $id ) );
		}
		$text = self::cut( wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );

		/**
		 * The description of a design's page for search engines; '' prints none.
		 *
		 * @param string $text The excerpt, else the page's lead paragraph, cut to 155 characters.
		 * @param int    $id   The page.
		 */
		return trim( (string) apply_filters( 'dxai_ui_meta_description', $text, $id ) );
	}

	/** The first paragraph of a page's content that says something: twelve words or more. */
	public static function lead( string $content ): string {
		if ( preg_match_all( '#<p\b[^>]*>(.*?)</p>#is', $content, $m ) > 0 ) {
			foreach ( $m[1] as $p ) {
				$plain = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $p ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
				// Words by the spaces between them, so a page in any script counts.
				if ( count( array_filter( explode( ' ', $plain ) ) ) >= self::WORDS ) {
					return $plain;
				}
			}
		}

		return '';
	}

	/** Text cut to MAX characters at a word, with an ellipsis when it was cut. */
	public static function cut( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		if ( mb_strlen( $text ) <= self::MAX ) {
			return $text;
		}
		$short = mb_substr( $text, 0, self::MAX - 1 );
		$space = mb_strrpos( $short, ' ' );

		return rtrim( $space !== false && $space > 60 ? mb_substr( $short, 0, $space ) : $short, ' ,;:.-–—' ) . '…';
	}

	/** Whether an SEO plugin writes the pages' descriptions. */
	public static function seo_plugin(): bool {
		$on = defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ) || defined( 'THE_SEO_FRAMEWORK_VERSION' ) || defined( 'SLIM_SEO_VER' );

		/**
		 * Whether an SEO plugin may write the pages' descriptions: then the plugin's waits for the head, and is printed only when it has none.
		 *
		 * @param bool $on Whether one of the known SEO plugins is active.
		 */
		return (bool) apply_filters( 'dxai_ui_seo_plugin', $on );
	}
}

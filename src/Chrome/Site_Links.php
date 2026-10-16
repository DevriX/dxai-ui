<?php
/**
 * A design's links where the site is: under the site's own address, and to the Home's sections from the other pages.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Chrome;

use DXAI_UI\Seo\Page_Meta;
use DXAI_UI\Structures\Design_Attach;

/**
 * A design names its pages from the root of its host (`/careers/`, `/water-damage-restoration/`) and the Home's sections by their place
 * (`#why`, `#faqs`). A site at the root of its host takes them as they are. A site that is not does not: on a multisite network in folders
 * (the team's network: a site at …/taylored-restoration/), or WordPress in a folder, `/careers/` is the main site's address — 11 links on each page of
 * Taylored Restoration's subsite answered 404 (2026-10-16), though the subsite has a Careers page at /taylored-restoration/careers/. And a
 * link to a section of the Home (the footer's "Why Taylored", `#why`) leads nowhere from any other page.
 *
 * The links are read where they are drawn, not where they are stored: the pages and the widgets stay as the design made them and move
 * between sites as they are (Local → a network), and the pages converted before this are right as they are.
 *  - A link from the root of the host (`/x/`) goes under the site's address when the site is in a folder and the link is not under it.
 *  - A link to a place on the page (`#x`) that this page does not have and the design's Home does goes to that place on the Home. A skip
 *    link stays the page's own.
 * Where: the content of a design's pages (the DX template draws its header and footer through it too), the site's header and footer
 * blocks, the footer widgets the plugin installed (a theme draws them in its own footer: Footer_Widgets), and the menu items the plugin
 * wrote (Navigation_Factory). Links a person wrote anywhere else are not read. `dxai_ui_site_links` turns it off.
 */
final class Site_Links {

	/** Where a skip link goes: the page's own, never sent to the Home. */
	private const OWN = array( 'main', 'content', 'primary', 'top', 'maincontent', 'main-content', 'site-content', 'dxai-root' );

	/** @var array<int, string> The content of a page as stored, by its id, for the places it has (one read a request). */
	private static array $stored = array();

	public function register(): void {
		// After the blocks are rendered (9) and the shortcodes (11): the links as the page has them.
		add_filter( 'the_content', array( self::class, 'content' ), 20 );
		add_filter( 'render_block_' . Site_Header_Block::NAME, array( self::class, 'header' ), 20 );
		add_filter( 'render_block_' . Site_Footer_Block::NAME, array( self::class, 'footer' ), 20 );
		add_filter( 'widget_block_content', array( self::class, 'widget' ), 20, 3 );
		add_filter( 'nav_menu_link_attributes', array( self::class, 'menu_item' ), 20, 2 );
	}

	/**
	 * A design's page: its links, and those of the header and footer the DX template draws through the same filters.
	 *
	 * @param string|mixed $html
	 * @return string|mixed
	 */
	public static function content( $html ) {
		$page = (int) get_the_ID();
		if ( ! is_string( $html ) || $page < 1 || ! self::on() || ! Page_Meta::is_design_page( $page ) ) {
			return $html;
		}

		return self::links( $html, $page, Design_Attach::scope_for( $page ) );
	}

	/**
	 * The site's header block, wherever it is drawn.
	 *
	 * @param string|mixed $html
	 * @return string|mixed
	 */
	public static function header( $html ) {
		$spec = Header_Template::stored();

		return is_string( $html ) && self::on() ? self::links( $html, self::page(), self::home( null === $spec ? 0 : Header_Template::scope_of( $spec ) ) ) : $html;
	}

	/**
	 * The site's footer block, wherever it is drawn.
	 *
	 * @param string|mixed $html
	 * @return string|mixed
	 */
	public static function footer( $html ) {
		return is_string( $html ) && self::on() ? self::links( $html, self::page(), self::home( Site_Footer_Block::scope( Footer_Template::get() ) ) ) : $html;
	}

	/**
	 * A footer widget the plugin installed, drawn by a theme's footer (or by the footer block: the links are already right then).
	 *
	 * @param string|mixed $html
	 * @param mixed        $instance
	 * @param mixed        $widget
	 * @return string|mixed
	 */
	public static function widget( $html, $instance = null, $widget = null ) {
		if ( ! is_string( $html ) || ! $widget instanceof \WP_Widget || ! Footer_Widgets::owns( (string) $widget->id ) || ! self::on() ) {
			return $html;
		}

		return self::links( $html, self::page(), self::home( Site_Footer_Block::scope( Footer_Template::get() ) ) );
	}

	/**
	 * A menu item the plugin wrote (a theme draws the menus with wp_nav_menu()).
	 *
	 * @param array<string, mixed>|mixed $atts
	 * @param mixed                      $item
	 * @return array<string, mixed>|mixed
	 */
	public static function menu_item( $atts, $item = null ) {
		if ( ! is_array( $atts ) || ! is_string( $atts['href'] ?? null ) || ! $item instanceof \WP_Post || ! \DXAI_UI\Structures\Navigation_Factory::wrote( (int) $item->ID ) || ! self::on() ) {
			return $atts;
		}
		$spec          = Header_Template::stored();
		$atts['href'] = self::href( (string) $atts['href'], self::path(), self::page(), self::home( null === $spec ? 0 : Header_Template::scope_of( $spec ) ) );

		return $atts;
	}

	/**
	 * Every link of some markup as the site has it.
	 *
	 * @param int $page The page it is drawn on (0: not a page).
	 * @param int $home The design's Home, whose sections its links name (0: none).
	 */
	public static function links( string $html, int $page, int $home ): string {
		$path = self::path();
		if ( ( $path === '' && $home < 1 ) || stripos( $html, 'href' ) === false ) {
			return $html;
		}
		$p       = new \WP_HTML_Tag_Processor( $html );
		$changed = false;
		while ( $p->next_tag( array( 'tag_name' => 'A' ) ) ) {
			$href = $p->get_attribute( 'href' );
			if ( ! is_string( $href ) || $href === '' || preg_match( '/(?:^|\s)skip/i', (string) $p->get_attribute( 'class' ) ) === 1 ) {
				continue;
			}
			$new = self::href( $href, $path, $page, $home );
			if ( $new !== $href ) {
				$p->set_attribute( 'href', $new );
				$changed = true;
			}
		}

		return $changed ? $p->get_updated_html() : $html;
	}

	/**
	 * One link as the site has it (see the class).
	 *
	 * @param string $path The site's folder on its host, '' at the root ('/taylored-restoration').
	 */
	public static function href( string $href, string $path, int $page, int $home ): string {
		if ( str_starts_with( $href, '#' ) ) {
			return self::to_home( $href, $page, $home );
		}

		return self::rooted( $href, $path );
	}

	/** A link from the root of the host under the site's folder, unless it is under it already ('' path: as it is). */
	public static function rooted( string $href, string $path ): string {
		if ( $path === '' || ! str_starts_with( $href, '/' ) || str_starts_with( $href, '//' ) ) {
			return $href;
		}
		foreach ( array( '', '/', '?', '#' ) as $next ) {
			if ( $next === '' ? $href === $path : str_starts_with( $href, $path . $next ) ) {
				return $href;
			}
		}

		return $path . $href;
	}

	/** A link to a place this page does not have and the Home does: that place on the Home. */
	private static function to_home( string $href, int $page, int $home ): string {
		$id = rawurldecode( substr( $href, 1 ) );
		if ( $id === '' || $home < 1 || $page === $home || in_array( strtolower( $id ), self::OWN, true ) || self::has_place( $page, $id ) || ! self::has_place( $home, $id ) ) {
			return $href;
		}
		$url = get_permalink( $home );

		return is_string( $url ) && $url !== '' ? $url . $href : $href;
	}

	/** Whether a post's content (as stored) has a place of this name: an element's id, or a block's anchor. */
	public static function has_place( int $post, string $id ): bool {
		if ( $post < 1 ) {
			return false;
		}
		if ( ! isset( self::$stored[ $post ] ) ) {
			self::$stored[ $post ] = (string) get_post_field( 'post_content', $post );
		}
		$html = self::$stored[ $post ];

		return str_contains( $html, 'id="' . $id . '"' ) || str_contains( $html, '"anchor":"' . $id . '"' );
	}

	/** The site's folder on its host: '/taylored-restoration' on a network in folders, '' for a site at the root. */
	public static function path(): string {
		return untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
	}

	/** Forget what was read of the pages (a test, or a page written in this request). */
	public static function forget(): void {
		self::$stored = array();
	}

	/** The page being drawn, 0 on any other view (a blog, an archive). */
	private static function page(): int {
		return is_singular() ? (int) get_queried_object_id() : 0;
	}

	/** The Home of the design a page belongs to, else the Home the chrome was installed from. */
	private static function home( int $chrome ): int {
		$own = self::page() > 0 ? Design_Attach::scope_for( self::page() ) : 0;

		return $own > 0 ? $own : $chrome;
	}

	private static function on(): bool {
		/**
		 * Whether a design's links are read as the site has them: under its folder, and to the Home's sections from other pages.
		 *
		 * @param bool $on Default true.
		 */
		return (bool) apply_filters( 'dxai_ui_site_links', true );
	}
}

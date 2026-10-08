<?php
/**
 * The styles and the script of the team's own sections, on the pages that do not carry the theme's.
 *
 * @package DXAI_UI\Blocks
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks;

use DXAI_UI\Theme\Capabilities;
use DXAI_UI\Theme\Theme_Compat;

/**
 * A section of the library (Pages\Block_Library) is the team's block, made for a page of their theme: the theme's global styles lay it out
 * (a grid is a grid, the children of a container sit a block gap apart), its stylesheet draws the questions of a FAQ (and its script opens
 * them), and WordPress prints the CSS a block was given in its own settings. A converted page — the plugin's blank canvas, where the page
 * of a design is made — has none of that: its theme is detached from it (Theme_Compat) and WordPress's global styles are not on it.
 *
 * This brings those, for the library's sections and for nothing else: every rule is inside `.dxai-lib`, the class every section of the
 * library has, and a page that has no section of it prints none of it. So the page of a design (its Home above all) is as it was. It is
 * the same thing the YouTube facade does (Youtube_Facade_View): the plugin's own copy, where the theme's is not.
 *
 *  - assets/css/block-library.css: the layouts the global styles would have made, and the FAQ's accordion styles;
 *  - assets/js/block-library.js: the FAQ's questions;
 *  - the block's own custom CSS (the "Additional CSS" of a block settings panel), which WordPress prints from a handle that depends on the
 *    global styles a converted page does not have: the CSS of the library's blocks alone is printed with the stylesheet.
 *
 * On a page whose theme brings them (the American Restoration theme, on a page that is not a converted one) nothing is printed.
 */
final class Library_View {

	public const HANDLE = 'dxai-ui-block-library';

	/** The class every section of the library has (bin/build-block-library.php puts it on the section). */
	public const MARK = 'dxai-lib';

	/** @var array<string, true> The custom CSS rules already added to the stylesheet. */
	private static array $added = array();

	public function register(): void {
		add_action( 'init', array( $this, 'register_assets' ) );
		// In the head, for a page whose own content has a section: it is there before the section is.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_for_page' ), 30 );
		// …and wherever else a section is rendered (a template, a widget, a pattern, a page shown another way).
		add_filter( 'render_block_core/group', array( $this, 'on_render' ), 10, 1 );
	}

	public function register_assets(): void {
		wp_register_style( self::HANDLE, DXAI_UI_URL . 'assets/css/block-library.css', array(), DXAI_UI_VERSION );
		wp_register_script( self::HANDLE, DXAI_UI_URL . 'assets/js/block-library.js', array(), DXAI_UI_VERSION, true );
	}

	/**
	 * Whether the theme draws the section on this page: it brings the global styles and its own FAQ (Capabilities::brings(): the theme says
	 * so with the `dxai-block-library` support, or its adapter knows it does). Not on a converted page, whatever the theme (it is detached
	 * from it).
	 */
	public static function theme_handles(): bool {
		$can = ! Theme_Compat::is_converted_page() && Capabilities::brings( 'library-styles' );

		/**
		 * Whether the active theme brings what the library's sections need (global styles, the FAQ's styles and script) on this page. On, the
		 * plugin prints none of its own.
		 *
		 * @param bool $can The theme is known to, and the page is not a converted one.
		 */
		return (bool) apply_filters( 'dxai_ui_theme_has_block_library', $can );
	}

	/** Whether markup holds a section of the library (its class, not a longer one that starts with it). */
	public static function has_section( string $markup ): bool {
		return str_contains( $markup, self::MARK ) && preg_match( '/(?<![\w-])' . self::MARK . '(?![\w-])/', $markup ) === 1;
	}

	/** The page's own content, when it has a section. */
	public function enqueue_for_page(): void {
		if ( ! is_singular() ) {
			return;
		}
		$content = (string) get_post_field( 'post_content', (int) get_the_ID() );
		if ( self::has_section( $content ) ) {
			self::enqueue( $content );
		}
	}

	/**
	 * A rendered group that is a section of the library: what it needs is asked for (WordPress prints it in the footer).
	 *
	 * @param string $content The block's HTML.
	 */
	public function on_render( $content ): string {
		$content = (string) $content;
		if ( self::has_section( $content ) ) {
			self::enqueue( $content );
			self::custom_css( $content );
		}

		return $content;
	}

	/**
	 * @param string $markup What the page (or the section) holds.
	 */
	private static function enqueue( string $markup ): void {
		if ( self::theme_handles() ) {
			return;
		}
		if ( ! wp_style_is( self::HANDLE, 'enqueued' ) ) {
			wp_enqueue_style( self::HANDLE );
			// The theme's own block gap, for the section on a converted page (the canvas sets it to nothing for a design).
			$gap = function_exists( 'wp_get_global_styles' ) ? wp_get_global_styles( array( 'spacing', 'blockGap' ) ) : '';
			if ( is_string( $gap ) && preg_match( '/^[\d.]+(?:px|rem|em|%|vw|vh)$/', trim( $gap ) ) === 1 ) {
				wp_add_inline_style( self::HANDLE, 'body.dxai-ui-blank .dxai-ui .dxai-lib{--wp--style--block-gap:' . trim( $gap ) . ';}' );
			}
		}
		if ( str_contains( $markup, 'faq-answer' ) ) {
			wp_enqueue_script( self::HANDLE );
		}
	}

	/**
	 * The CSS the blocks of a section were given in their own settings. WordPress adds it to a handle that depends on the global styles,
	 * which a converted page does not print, so it never reaches the page: the library's own is added to the stylesheet here, and only
	 * the library's (the rules of the blocks inside this section, found by the class WordPress gave each).
	 *
	 * @param string $content The section as it is rendered.
	 */
	private static function custom_css( string $content ): void {
		if ( ! Theme_Compat::is_converted_page() || preg_match_all( '/wp-custom-css-[a-f0-9]+/', $content, $m ) < 1 ) {
			return;
		}
		$rules = wp_styles()->get_data( 'wp-block-custom-css', 'after' );
		if ( ! is_array( $rules ) ) {
			return;
		}
		foreach ( array_unique( $m[0] ) as $class ) {
			foreach ( $rules as $rule ) {
				if ( ! is_string( $rule ) || ! str_contains( $rule, '.' . $class ) || isset( self::$added[ md5( $rule ) ] ) ) {
					continue;
				}
				// Its own handle, printed after the content (the page's stylesheet is in the head, before there was a section to ask).
				if ( ! wp_style_is( self::HANDLE . '-custom', 'registered' ) ) {
					wp_register_style( self::HANDLE . '-custom', false, array(), DXAI_UI_VERSION );
					wp_enqueue_style( self::HANDLE . '-custom' );
				}
				self::$added[ md5( $rule ) ] = true;
				wp_add_inline_style( self::HANDLE . '-custom', $rule );
			}
		}
	}
}

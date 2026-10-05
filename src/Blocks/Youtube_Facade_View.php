<?php
/**
 * The script and styles of a YouTube facade, on the pages whose theme does not bring them.
 *
 * @package DXAI_UI\Blocks
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks;

use DXAI_UI\Theme\Theme_Compat;

/**
 * A YouTube video is added as a facade (Compiler\Video_Facade): a box with the video's thumbnail and a play button that becomes the player
 * when it is pressed. Drawing it and swapping the player in is the theme's job in the team's example — the American Restoration theme
 * has the script and the styles — but there are two kinds of page where the theme's are not there:
 *
 *  - a converted page (the plugin's blank template): the theme's scripts and styles are detached from it (Theme_Compat), the American
 *    Restoration theme's among them;
 *  - a site on another theme.
 *
 * On those the plugin's own copy (assets/js/youtube-facade.js and assets/css/youtube-facade.css) is printed, and only on a page that has a
 * facade (an HTML block with one, as it is rendered). On a page of a theme that has them it is not printed at all. The two work together
 * if both are loaded.
 */
final class Youtube_Facade_View {

	public const HANDLE = 'dxai-ui-youtube-facade';

	/** Themes known to bring the facade's script and styles on their own pages. */
	private const THEMES = array( 'american-restoration' );

	public function register(): void {
		add_action( 'init', array( $this, 'register_assets' ) );
		add_filter( 'render_block_core/html', array( $this, 'on_render' ), 10, 1 );
	}

	public function register_assets(): void {
		wp_register_style( self::HANDLE, DXAI_UI_URL . 'assets/css/youtube-facade.css', array(), DXAI_UI_VERSION );
		wp_register_script( self::HANDLE, DXAI_UI_URL . 'assets/js/youtube-facade.js', array(), DXAI_UI_VERSION, true );
	}

	/**
	 * Whether the theme draws the facade on this page. Not on a converted page, whatever the theme (it is detached from it).
	 */
	public static function theme_handles(): bool {
		$can = ! Theme_Compat::is_converted_page()
			&& ( current_theme_supports( 'dxai-youtube-facade' ) || in_array( get_template(), self::THEMES, true ) || in_array( get_stylesheet(), self::THEMES, true ) );

		/**
		 * Whether the active theme brings the facade's script and styles on this page. On, the plugin prints neither.
		 *
		 * @param bool $can The theme is known to, and the page is not a converted one.
		 */
		return (bool) apply_filters( 'dxai_ui_theme_has_youtube_facade', $can );
	}

	/**
	 * A rendered HTML block that holds a facade: the script and the styles are asked for (WordPress prints them in the footer).
	 *
	 * @param string $content The block's HTML.
	 */
	public function on_render( $content ): string {
		$content = (string) $content;
		if ( str_contains( $content, 'youtube-facade' ) && ! self::theme_handles() ) {
			wp_enqueue_style( self::HANDLE );
			wp_enqueue_script( self::HANDLE );
		}

		return $content;
	}
}

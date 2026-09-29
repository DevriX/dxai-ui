<?php
/**
 * What the design page gives its sections that an ordinary page does not.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks;

use DXAI_UI\Structures\Design_Attach;
use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Support\Upload_Paths;

/**
 * Sections copied from a design page into an ordinary page of the theme get
 * the design's sheet, script and rules there (Assets, Style_Rules), and the
 * theme's CSS is fenced off them (Theme_Fence). Four things the design page
 * has around its sections still differ, and this supplies them:
 *
 *  - what the sections inherit. On the design page their scope element sits in
 *    a body the theme never styled; here the theme's body sets the font, a
 *    24px line-height, a colour. The scope element starts from the browser's
 *    defaults again, at no specificity, so the design's own root rule wins.
 *  - the blank canvas's block resets (blank-canvas.css), which are written for
 *    `body.dxai-ui-blank` — the same rules, at the same weight, without it.
 *  - the theme.json tokens without its styling: the preset variables and the
 *    `.has-*` classes (Blank_Template does the same), since the fenced
 *    global-styles no longer reaches into the sections.
 *  - the web fonts. A DevriX theme's fonts module strips every
 *    fonts.googleapis.com stylesheet from its pages, the design's included, so
 *    the page is given a copy of Google's stylesheet from this site's uploads
 *    (the font files still come from Google's CDN). The copy is made when the
 *    page is saved, or by cron after the first view that needs it; until then
 *    the original link is printed.
 */
final class Attached_Styles {

	/** The handle the design sheet depends on, printed before it. */
	public const HANDLE = 'dxai-ui-attached';

	public function register(): void {
		add_action( 'dxai_ui_copy_font_css', array( self::class, 'copy_font_css' ) );
		add_action( 'save_post', array( self::class, 'warm' ), 20 );
	}

	/**
	 * Register the base styles for an ordinary page showing design $source; the handle to depend on.
	 */
	public static function base( int $source ): string {
		if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
			wp_register_style( self::HANDLE, false, array(), DXAI_UI_VERSION );
			wp_add_inline_style( self::HANDLE, self::base_css( $source ) );
		}

		return self::HANDLE;
	}

	/** The inherited-value reset, the canvas rules and the tokens. */
	public static function base_css( int $source ): string {
		$scope = (int) get_post_meta( $source, Page_Scope::META, true );
		$scope = $scope > 0 ? $scope : $source;
		$css   = ':where(.dxai-ui.dxai-ui--' . $scope . '){font:initial;font-size:medium;color:initial;letter-spacing:normal;word-spacing:normal;text-transform:none;text-align:start;text-indent:0;text-shadow:none;white-space:normal;font-feature-settings:normal;font-variation-settings:normal;text-rendering:auto;-webkit-font-smoothing:auto;-moz-osx-font-smoothing:auto;hyphens:manual;word-break:normal;overflow-wrap:normal;cursor:auto;visibility:visible}';
		$css  .= self::canvas_css( (bool) get_post_meta( $source, '_dxai_ui_static_html', true ) );
		if ( function_exists( 'wp_get_global_stylesheet' ) ) {
			$css .= wp_get_global_stylesheet( array( 'variables', 'presets' ) );
		}

		return $css;
	}

	/**
	 * blank-canvas.css for sections outside the blank canvas: the rules about `.dxai-ui` only, with
	 * `body.dxai-ui-blank` read as `html:not(.dxai-ui-x)` — (0,1,1) as before, so each rule keeps its place against
	 * the design's. A static design (a Claude Design export) keeps the browser's margins, as on its own page, where
	 * `body.dxai-ui-static` leaves those rules out.
	 */
	private static function canvas_css( bool $static ): string {
		$file = DXAI_UI_DIR . 'assets/css/blank-canvas.css';
		$css  = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$css  = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER ) ) {
			return '';
		}
		$out = '';
		foreach ( $m as $rule ) {
			$selector = trim( $rule[1] );
			if ( preg_match( '/\.dxai-ui(?![\w-])/', $selector ) !== 1 || str_contains( $selector, 'body.admin-bar' ) || str_contains( $selector, '.dxai-ui-root' ) ) {
				continue;
			}
			if ( $static && str_contains( $selector, ':not(.dxai-ui-static *)' ) ) {
				continue;
			}
			$out .= str_replace( 'body.dxai-ui-blank', 'html:not(.dxai-ui-x)', $selector ) . '{' . trim( $rule[2] ) . '}';
		}

		return $out;
	}

	/**
	 * A web-font stylesheet URL as this site serves it: its copy in uploads when there is one; otherwise the
	 * original, with the copy scheduled.
	 */
	public static function local_font_url( string $url ): string {
		if ( ! str_contains( $url, 'fonts.googleapis.com' ) ) {
			return $url;
		}
		$name = self::font_file( $url );
		$path = Upload_Paths::path( $name );
		if ( $path !== '' && is_readable( $path ) && filesize( $path ) > 0 ) {
			return Upload_Paths::url( $name );
		}
		if ( ! wp_next_scheduled( 'dxai_ui_copy_font_css', array( $url ) ) ) {
			wp_schedule_single_event( time(), 'dxai_ui_copy_font_css', array( $url ) );
		}

		return $url;
	}

	/** On save: the fonts of the design an ordinary page shows, copied now, so its first view already has them. */
	public static function warm( $post_id ): void {
		$post_id = (int) $post_id;
		if ( $post_id < 1 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		Design_Attach::forget( $post_id );
		if ( ! Design_Attach::attached( $post_id ) ) {
			return;
		}
		$fonts = get_post_meta( Design_Attach::source_for( $post_id ), '_dxai_ui_font_urls', true );
		foreach ( is_array( $fonts ) ? $fonts : array() as $url ) {
			self::copy_font_css( (string) $url );
		}
	}

	/** Google's stylesheet for a current browser, copied into uploads (cron, or right away from a save). */
	public static function copy_font_css( $url ): void {
		$url = (string) $url;
		if ( ! preg_match( '#^https://fonts\.googleapis\.com/#', $url ) ) {
			return;
		}
		$path = Upload_Paths::path( self::font_file( $url ) );
		if ( $path === '' || ( is_readable( $path ) && filesize( $path ) > 0 ) ) {
			return;
		}
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout' => 8,
				// Google answers woff2 @font-face rules only to a browser it knows.
				'headers' => array( 'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36' ),
			)
		);
		$css = is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ? '' : (string) wp_remote_retrieve_body( $response );
		// Only font faces pointing at Google's font CDN are kept: nothing else may ride along into a local file.
		if ( ! str_contains( $css, '@font-face' ) || preg_match( '#url\((?!\s*[\x22\x27]?https://fonts\.gstatic\.com/)#i', $css ) === 1 || preg_match( '/@import|<|expression\(/i', $css ) === 1 ) {
			return;
		}
		if ( wp_mkdir_p( dirname( $path ) ) ) {
			@file_put_contents( $path, $css ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/** Where the copy of one font stylesheet lives, relative to uploads. */
	private static function font_file( string $url ): string {
		return Upload_Paths::DIR . '/fonts/' . md5( $url ) . '.css';
	}
}

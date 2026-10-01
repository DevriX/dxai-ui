<?php
/**
 * What the design page gives its sections that an ordinary page does not.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks;

use DXAI_UI\Media\Font_Host;
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
		return Font_Host::link_url( $url );
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
		$source = Design_Attach::source_for( $post_id );
		$fonts  = get_post_meta( $source, '_dxai_ui_font_urls', true );
		foreach ( is_array( $fonts ) && ! \DXAI_UI\Theme\Theme_Fonts::adopts( $source ) ? $fonts : array() as $url ) {
			self::copy_font_css( (string) $url );
		}
		// The design's own sheet carries them too, so the page asks for its fonts once.
		Font_Host::localize_design( $source, 8 );
	}

	/** Google's stylesheet for these fonts, and the files it names, copied into uploads (cron, or right away from a save). */
	public static function copy_font_css( $url ): void {
		Font_Host::copy_now( $url );
	}
}

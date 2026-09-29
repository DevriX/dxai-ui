<?php
/**
 * Keeps a converted page intact on a theme that renders the site itself.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Theme;

/**
 * A converted page is rendered on the plugin's blank template: the design is
 * the whole page, and the theme contributes nothing to it. A classic theme —
 * the DevriX themes production runs, american-restoration among them — still
 * reaches into that page in four ways, each measured on that theme:
 *
 *  - Its stylesheet. american-restoration inlines its 1.1 MB master.css on a
 *    style handle with no src (`theme_style`), which Assets::drop_theme_styles
 *    cannot recognise by URL, and that sheet forces the theme's font on
 *    `body, button, input, p > span` and its heading sizes on phones with
 *    `!important`. Every wp_enqueue_scripts / wp_head callback the theme's own
 *    files registered is therefore removed on a converted page.
 *  - Its DelayScripts module holds every script back for visitors until the
 *    first interaction or 5 s, so the design's dropdowns, sticky header and
 *    reveals did nothing at first. `dx_delay_scripts_should_delay` answers
 *    false on a converted page.
 *  - Its LazyLoadImages module forces loading="lazy" on every <img>, the logo
 *    and the hero included. `dx_lazy_load_skip_image` keeps the design's own
 *    loading attributes on a converted page.
 *  - It declares no `title-tag` support (it prints its title in header.php,
 *    which the blank template never runs), so a converted page had no
 *    <title>. One is printed from wp_get_document_title() — which is what
 *    Rank Math and this plugin's own SEO title filter answer — whenever the
 *    theme does not declare the support.
 *
 * The same class decides whether an import may take over the site's header
 * and footer (may_install_chrome()): a theme that renders its own header and
 * footer from these menu locations and widget areas keeps them.
 */
final class Theme_Compat {

	public function register(): void {
		// After the main query, before any theme enqueue runs.
		add_action( 'template_redirect', array( $this, 'detach_theme' ), 0 );
		add_action( 'wp_head', array( $this, 'print_title' ), 1 );
		add_filter( 'dx_delay_scripts_should_delay', array( $this, 'no_delay' ), 10, 1 );
		add_filter( 'dx_lazy_load_skip_image', array( $this, 'skip_lazy' ), 10, 1 );
	}

	/**
	 * Whether an import may write the design's header into Appearance > Menus
	 * and its footer into Appearance > Widgets.
	 *
	 * Not on a classic theme: it renders the site's header and footer itself
	 * (header.php / footer.php) from the very locations and areas the import
	 * would write — `primary-navigation` and `footer-column-1…4` on a DevriX
	 * theme — so installing there would replace the live site's navigation and
	 * move its footer widgets to Inactive on every page. The design keeps its
	 * own header and footer as template parts on its pages instead, and the
	 * site's menus and widgets stay as they are. `dxai_ui_install_chrome`
	 * overrides the answer.
	 */
	public static function may_install_chrome(): bool {
		$default = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();

		return (bool) apply_filters( 'dxai_ui_install_chrome', $default );
	}

	/** Whether the current request renders a converted page. */
	public static function is_converted_page(): bool {
		return is_singular() && get_page_template_slug() === Blank_Template::SLUG;
	}

	/**
	 * Remove the theme's own enqueue and head callbacks on a converted page.
	 * Only callbacks defined in the active theme's (or its parent's) files;
	 * plugins keep theirs.
	 */
	public function detach_theme(): void {
		if ( ! self::is_converted_page() ) {
			return;
		}
		$dirs = array_unique(
			array(
				wp_normalize_path( get_stylesheet_directory() ),
				wp_normalize_path( get_template_directory() ),
			)
		);
		/*
		 * A classic theme also gets the theme.json global styles re-enqueued in
		 * the footer (core hooks wp_enqueue_global_styles to wp_footer for
		 * block styles loaded on demand), after Blank_Template dropped them from
		 * <head>: american-restoration's body line-height and link weight came
		 * back that way. The variables and presets stay (dxai-ui-tokens).
		 */
		remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );
		// Measured on american-restoration: its font-face and critical CSS printers
		// sit on wp_head/wp_footer/wp_print_styles, its sheets on wp_enqueue_scripts,
		// and its output buffers on template_redirect — the Fonts module's buffer
		// strips every fonts.googleapis.com stylesheet from the page, which took the
		// design's own web fonts with it (every text line 2-3px off). This callback
		// runs at template_redirect priority 0, so the theme's later ones are
		// removed before they run. No tracking or analytics code lives in these
		// callbacks of that theme.
		foreach ( array( 'template_redirect', 'wp_enqueue_scripts', 'wp_head', 'wp_footer', 'wp_print_styles' ) as $hook ) {
			global $wp_filter;
			if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof \WP_Hook ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$file = self::defined_in( $callback['function'] );
					if ( $file === '' ) {
						continue;
					}
					foreach ( $dirs as $dir ) {
						if ( str_starts_with( $file, trailingslashit( $dir ) ) ) {
							remove_action( $hook, $callback['function'], $priority );
							break;
						}
					}
				}
			}
		}
	}

	/**
	 * The file a hook callback is defined in, '' when it cannot be told.
	 *
	 * @param mixed $fn
	 */
	public static function defined_in( $fn ): string {
		try {
			if ( is_string( $fn ) && str_contains( $fn, '::' ) ) {
				$fn = explode( '::', $fn, 2 );
			}
			if ( is_array( $fn ) && count( $fn ) === 2 ) {
				$ref = new \ReflectionMethod( $fn[0], (string) $fn[1] );
			} elseif ( is_string( $fn ) || $fn instanceof \Closure ) {
				$ref = new \ReflectionFunction( $fn );
			} elseif ( is_object( $fn ) && method_exists( $fn, '__invoke' ) ) {
				$ref = new \ReflectionMethod( $fn, '__invoke' );
			} else {
				return '';
			}
		} catch ( \ReflectionException $e ) {
			return '';
		}
		$file = $ref->getFileName();

		return is_string( $file ) ? wp_normalize_path( $file ) : '';
	}

	/**
	 * A <title> for a converted page when nothing else prints one. Core prints
	 * it from wp_head for a theme with title-tag support (_wp_render_title_tag
	 * is always hooked, and returns early without the support) and for every
	 * block theme (_block_template_render_title_tag, which needs no declared
	 * support).
	 */
	public function print_title(): void {
		if ( ! self::is_converted_page() || current_theme_supports( 'title-tag' ) || has_action( 'wp_head', '_block_template_render_title_tag' ) ) {
			return;
		}
		echo '<title>' . esc_html( wp_get_document_title() ) . "</title>\n";
	}

	/**
	 * @param mixed $delay
	 * @return mixed
	 */
	public function no_delay( $delay ) {
		return self::is_converted_page() ? false : $delay;
	}

	/**
	 * @param mixed $skip
	 * @return mixed
	 */
	public function skip_lazy( $skip ) {
		return self::is_converted_page() ? true : $skip;
	}
}

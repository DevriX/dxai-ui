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
 *    false for the plugin's own scripts on a page that shows a design
 *    (own_script()); the rest — tag managers, analytics, review widgets,
 *    forms, the theme's bundle — waits as on every other page of the site.
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
 * footer from these menu locations and widget areas keeps them. A DX theme
 * (is_dx_theme()) is the one classic theme this plugin is built for, and
 * there Chrome_Choice installs by itself when the site has nothing of its
 * own in those places; may_install_chrome() stays false for it, because it
 * also says that the design's colours are not adopted and the theme's palette
 * is followed (Theme_Binding, Theme_Palette, Design_Theme_Json::adopt()).
 */
final class Theme_Compat {

	/**
	 * The DX themes: the themes the team builds its sites on — american-restoration, and any child theme of it. They draw the site's header
	 * and footer from the menu locations and widget areas an install writes (`primary-navigation`, `footer-column-1`…`4`,
	 * `footer-copyright`): the header is a menu and the footer is widgets, edited in Appearance, never inside a page.
	 * `dxai_ui_dx_themes` adds a theme to the list or takes one off it. DX Base, the theme this plugin carries for a site that has none
	 * of its own (Base_Theme), is on the list: it has the same locations and the same areas.
	 *
	 * @return array<int, string> Theme slugs.
	 */
	public static function dx_themes(): array {
		$themes = apply_filters( 'dxai_ui_dx_themes', array_merge( Adapters\Amr_Adapter::SLUGS, array( Base_Theme::SLUG ) ) );

		return array_values( array_unique( array_filter( array_map( 'strval', is_array( $themes ) ? $themes : array() ) ) ) );
	}

	/** Whether the active theme, or the theme it is a child of, is a DX theme (dx_themes()). */
	public static function is_dx_theme(): bool {
		$themes = self::dx_themes();

		return in_array( (string) get_stylesheet(), $themes, true ) || in_array( (string) get_template(), $themes, true );
	}

	/**
	 * Whether the active theme, or the theme it is a child of, is DX Base. It has no colours, fonts, header or footer of its own, so it lets
	 * a design be its brand (may_install_chrome() is true for it), and it draws the site's header and footer from the same places a DX
	 * theme does — which is why an import treats it as one (Chrome_Choice::theme_draws_chrome()).
	 */
	public static function is_base_theme(): bool {
		return Base_Theme::SLUG === (string) get_stylesheet() || Base_Theme::SLUG === (string) get_template();
	}

	public function register(): void {
		// After the main query, before any theme enqueue runs.
		add_action( 'template_redirect', array( $this, 'detach_theme' ), 0 );
		add_action( 'wp_head', array( $this, 'print_title' ), 1 );
		add_filter( 'dx_delay_scripts_should_delay', array( $this, 'no_delay' ), 10, 4 );
		add_filter( 'dx_lazy_load_skip_image', array( $this, 'skip_lazy' ), 10, 1 );
		add_action( 'wp_footer', array( $this, 'print_block_supports' ), 2 );
	}

	/**
	 * The styles the blocks on a converted page asked for: a group's layout (its gap, wrapping and alignment are a
	 * `wp-container-…` rule), an element's colour. WordPress prints them in <head> for a block theme, because it renders a
	 * block theme's template before the head; the blank template is a PHP file, its content is rendered after the head,
	 * and WordPress does not print them in the footer for a block theme — so the layout the blocks were saved with was
	 * lost. A classic theme gets them in the footer from core, so nothing is added there.
	 */
	public function print_block_supports(): void {
		if ( ! self::is_converted_page() || ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() || ! function_exists( 'wp_style_engine_get_stylesheet_from_context' ) ) {
			return;
		}
		$css = wp_style_engine_get_stylesheet_from_context( 'block-supports', array( 'prettify' => false ) );
		if ( ! is_string( $css ) || $css === '' ) {
			return;
		}
		wp_register_style( 'dxai-ui-block-supports', false );
		wp_add_inline_style( 'dxai-ui-block-supports', $css );
		wp_print_styles( 'dxai-ui-block-supports' );
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
		return is_singular() && Blank_Template::is_canvas( (int) get_queried_object_id() );
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
	 * The DelayScripts module asks this for every script of the page, with its src, its inline code and its whole tag. On a page that shows
	 * a design the plugin's own scripts run at once (the design's menus, sticky header, sliders, reveals), and so does what they need; every
	 * other script waits for the first interaction or the module's timer, as on every other page of the site. Answering false for every
	 * script there (up to 0.4.0-beta.46) ran the tag manager, the analytics and the review and form scripts during the load: Master
	 * Restoration's Home scored 65 on PageSpeed, its hero picture painted at 8.6 s; 94 in the lab once they waited. A module that asks
	 * without the script (an older theme) gets the old answer, false: there is nothing to tell apart, and nothing of the design waits.
	 *
	 * @param mixed $delay
	 * @param mixed $src
	 * @param mixed $inner
	 * @param mixed $tag
	 * @return mixed
	 */
	public function no_delay( $delay, $src = null, $inner = null, $tag = null ) {
		if ( ! self::shows_design() ) {
			return $delay;
		}
		if ( ! is_string( $src ) ) {
			return false;
		}

		return self::own_script( $src, is_string( $tag ) ? $tag : '' ) ? false : $delay;
	}

	/**
	 * Whether a script of the page is the plugin's or one the plugin's scripts need: a file in its folder or in its folder in uploads (a
	 * design's page script), a tag WordPress printed for a handle the plugin's scripts depend on (`<handle>-js` and its `-before`,
	 * `-after`, `-extra` and `-translations` data), or a tag of its own (an id that starts with dxai-ui: its handles, its modules, its
	 * inline scripts).
	 */
	public static function own_script( string $src, string $tag ): bool {
		if ( $src !== '' ) {
			// By the path alone: a network site on its own domain, or a CDN in front, changes the host and keeps the path.
			$path    = (string) wp_parse_url( $src, PHP_URL_PATH );
			$uploads = wp_upload_dir( null, false );
			foreach ( array( DXAI_UI_URL, trailingslashit( (string) $uploads['baseurl'] ) . \DXAI_UI\Support\Upload_Paths::DIR . '/' ) as $base ) {
				$own = (string) wp_parse_url( $base, PHP_URL_PATH );
				if ( strlen( $own ) > 1 && str_starts_with( $path, $own ) ) {
					return true;
				}
			}
		}
		if ( preg_match( '/\sid\s*=\s*(["\'])(.*?)\1/i', $tag, $m ) !== 1 ) {
			return false;
		}
		$id = (string) $m[2];
		if ( str_starts_with( $id, 'dxai-ui' ) ) {
			return true;
		}
		$handle = (string) preg_replace( '/-js(?:-(?:before|after|extra|translations))?$/', '', $id );

		return $handle !== $id && in_array( $handle, self::needed_handles(), true );
	}

	/**
	 * The script handles the plugin's own scripts on this page depend on, all the way down (its own not listed).
	 *
	 * @return array<int, string>
	 */
	private static function needed_handles(): array {
		$scripts = wp_scripts();
		$need    = array();
		$walk    = static function ( string $handle, int $depth ) use ( &$walk, &$need, $scripts ): void {
			$dep = $scripts->registered[ $handle ] ?? null;
			if ( ! $dep instanceof \_WP_Dependency || $depth > 8 ) {
				return;
			}
			foreach ( (array) $dep->deps as $name ) {
				$name = (string) $name;
				if ( ! in_array( $name, $need, true ) ) {
					$need[] = $name;
					$walk( $name, $depth + 1 );
				}
			}
		};
		foreach ( array_unique( array_merge( (array) $scripts->done, (array) $scripts->queue ) ) as $handle ) {
			$handle = (string) $handle;
			$dep    = $scripts->registered[ $handle ] ?? null;
			$src    = $dep instanceof \_WP_Dependency && is_string( $dep->src ) ? $dep->src : '';
			if ( str_starts_with( $handle, 'dxai-ui' ) || ( $src !== '' && self::own_script( $src, '' ) ) ) {
				$walk( $handle, 0 );
			}
		}

		return $need;
	}

	/**
	 * @param mixed $skip
	 * @return mixed
	 */
	public function skip_lazy( $skip ) {
		return self::shows_design() ? true : $skip;
	}

	/**
	 * Whether the current request shows a design's page as the design: on the canvas, or drawn by the theme with its sections fenced off
	 * the theme's CSS (Theme_Fence). The theme's modules that would change the design's own behaviour stand down on both: its scripts
	 * (menus, reveals, sliders) are not held back, its pictures keep their own loading (a slide out of view to the side was never loaded).
	 */
	public static function shows_design(): bool {
		if ( self::is_converted_page() ) {
			return true;
		}
		$id = is_singular() ? (int) get_queried_object_id() : 0;

		return $id > 0 && Theme_Fence::draws_own_design( $id ) && Theme_Fence::fences( $id );
	}
}

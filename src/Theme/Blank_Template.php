<?php
/**
 * The plugin's canvas templates, so generated designs are not wrapped in theme chrome.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Theme;

use DXAI_UI\Structures\Page_Scope;

/**
 * How a design's page is drawn: a blank canvas (the theme draws nothing around the page, `body.dxai-ui-blank`), with the design's header
 * and footer around it. Three templates draw one:
 *
 *  - the theme's Default template: what a design's pages are given at import, the way the team keeps every page of a site. Around a page
 *    of its own the theme draws its header and footer; around a design's page the plugin draws the design's, exactly as the DX template
 *    does (draws_default()): the header the design drew, filled from Appearance > Menus, and its footer around Appearance > Widgets. A theme
 *    draws its own header from the same menus, but in its own markup: on American Restoration the menus were the design's and the header
 *    was the theme's, which is not the design one to one.
 *  - `dxai-template.php`, the DX template: the same drawing, chosen by name. The pages of imports from 0.4.0-beta.32 to beta.44 carry it.
 *  - `dxai-blank.php`, DX Blank: the header and the footer are put into the page's content as it renders (Page_Chrome). Every site
 *    imported before the DX template has pages on it, and they render exactly as they did.
 */
final class Blank_Template {

	/** DX Blank: the chrome is added to the content as it renders (Page_Chrome). Kept for the pages of every earlier import. */
	public const SLUG = 'dxai-blank.php';

	/** The DX template: the chrome is drawn by the template (Template_Chrome). */
	public const TEMPLATE = 'dxai-template.php';

	/** The theme's Default template, as `_wp_page_template` stores it. A design's page on it is drawn as on the DX template. */
	public const DEFAULT = 'default';

	/**
	 * The two canvas template files.
	 *
	 * @return array<int, string>
	 */
	public static function slugs(): array {
		return array( self::SLUG, self::TEMPLATE );
	}

	/** Whether a template slug is one of the canvas template files. */
	public static function is_canvas_slug( string $slug ): bool {
		return in_array( $slug, self::slugs(), true );
	}

	/** Whether a page renders on a canvas: one of the canvas templates, or a design's page on the Default template. */
	public static function is_canvas( int $post_id ): bool {
		return $post_id > 0 && ( self::is_canvas_slug( (string) get_page_template_slug( $post_id ) ) || self::draws_default( $post_id ) );
	}

	/**
	 * Whether a page is a design's own: a page the import made (its Home or a page of the design's site), or one made from it later. A
	 * theme page that holds blocks copied from a design is not: it is the theme's, and the theme draws it (Design_Attach).
	 */
	public static function is_design_page( int $post_id ): bool {
		return $post_id > 0
			&& ( (int) get_post_meta( $post_id, Page_Scope::META, true ) > 0 || (string) get_post_meta( $post_id, '_dxai_ui_generated_page', true ) === '1' );
	}

	/**
	 * Whether a page on the theme's Default template is drawn as the DX template draws it: a design's page.
	 */
	public static function draws_default( int $post_id ): bool {
		if ( $post_id < 1 || 'page' !== get_post_type( $post_id ) || (string) get_page_template_slug( $post_id ) !== '' || ! self::is_design_page( $post_id ) ) {
			return false;
		}

		return self::default_draws( $post_id );
	}

	/**
	 * Whether the Default template draws a design's page with the design's header and footer (the default), or leaves it to the theme.
	 */
	private static function default_draws( int $post_id ): bool {
		/**
		 * Whether a design's page on the theme's Default template is drawn as the DX template draws it — the design's header from
		 * Appearance > Menus, its footer around Appearance > Widgets, the theme's own header and footer and its style sheets left out —
		 * or by the theme, as any page of its own. With false, a design's pages are given the DX template at import instead.
		 *
		 * @param bool $draws   Draw it as the design (default true).
		 * @param int  $post_id The page, 0 when the import asks which template to give a new page.
		 */
		return (bool) apply_filters( 'dxai_ui_default_template_draws_design', true, $post_id );
	}

	/**
	 * The template values that draw a page as the design: the canvas templates, and the Default template ('' and 'default', as the editor
	 * and the meta name it) for a design's page. The editor's canvas follows the template picked in the inspector with it.
	 *
	 * @return array<int, string>
	 */
	public static function drawn_slugs( int $post_id ): array {
		$slugs = self::slugs();
		if ( 'page' === get_post_type( $post_id ) && self::is_design_page( $post_id ) && self::default_draws( $post_id ) ) {
			$slugs[] = '';
			$slugs[] = self::DEFAULT;
		}

		return $slugs;
	}

	/**
	 * The template a design's pages are given when they are made: the theme's Default template. `dxai_ui_default_template` can name the
	 * DX template or DX Blank for a site that wants a page to say which template draws it; a site that keeps the Default template for
	 * the theme (`dxai_ui_default_template_draws_design` false) gets the DX template.
	 */
	public static function default_slug(): string {
		$fallback = self::default_draws( 0 ) ? self::DEFAULT : self::TEMPLATE;
		/**
		 * The template a design's pages are given when they are made.
		 *
		 * @param string $slug 'default' (the theme's Default template), 'dxai-template.php' or 'dxai-blank.php'.
		 */
		$slug = apply_filters( 'dxai_ui_default_template', $fallback );
		if ( self::DEFAULT === $slug && ! self::default_draws( 0 ) ) {
			return self::TEMPLATE;
		}

		// Whatever a filter returns: only one of the three is taken.
		return in_array( $slug, array( self::DEFAULT, self::SLUG, self::TEMPLATE ), true ) ? $slug : $fallback;
	}

	public function register(): void {
		add_filter( 'theme_page_templates', array( $this, 'register_template' ) );
		add_filter( 'template_include', array( $this, 'include_template' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_canvas' ), 100 );
	}

	/**
	 * @param array<string, string> $templates
	 * @return array<string, string>
	 */
	public function register_template( array $templates ): array {
		$templates[ self::TEMPLATE ] = __( 'DX Template (header from Menus, footer from Widgets)', 'dxai-ui' );
		$templates[ self::SLUG ]     = __( 'DX Blank (earlier imports)', 'dxai-ui' );

		return $templates;
	}

	public function include_template( string $template ): string {
		if ( ! is_singular() ) {
			return $template;
		}

		$post_id = (int) get_queried_object_id();
		$slug    = (string) get_page_template_slug( $post_id );
		if ( ! self::is_canvas_slug( $slug ) ) {
			if ( ! self::draws_default( $post_id ) ) {
				return $template;
			}
			// A design's page on the Default template: the DX template draws it.
			$slug = self::TEMPLATE;
		}

		$path = DXAI_UI_DIR . 'templates/' . $slug;

		return is_readable( $path ) ? $path : $template;
	}

	public function enqueue_canvas(): void {
		if ( ! is_singular() || ! self::is_canvas( (int) get_queried_object_id() ) ) {
			return;
		}

		wp_enqueue_style(
			'dxai-ui-blank-canvas',
			DXAI_UI_URL . 'assets/css/blank-canvas.css',
			array(),
			DXAI_UI_VERSION
		);

		foreach ( array( 'global-styles', 'classic-theme-styles' ) as $handle ) {
			wp_dequeue_style( $handle );
		}

		/*
		 * …and give back the one part of global styles a converted page needs:
		 * the TOKENS, without the theme's styling.
		 *
		 * Dropping 'global-styles' is what keeps Twenty Twenty-Five off these
		 * pages, and it has to stay: re-enabling that sheet measured +1,103px at
		 * 1280 and +2,055px at 390 on H2O Away, 1,026px of it from the theme's
		 * 1.2rem block gap alone, with the rest in body typography and heading
		 * line-height. But the same dequeue also took away every
		 * `--wp--preset--*` custom property and every `.has-*-color` class —
		 * which meant no design token set in theme.json or in Styles could ever
		 * reach a converted page, and changing a brand colour once was
		 * impossible by construction.
		 *
		 * 'variables' is exactly those custom properties (all `--wp--preset--*`
		 * — colour, font size, spacing, shadow, gradient, font family — and no
		 * `--wp--style--*`, so no block gap rides along), and 'presets' is the
		 * `.has-{slug}-color` / `-background-color` / `-font-size` classes. Both
		 * are inert until something uses them: a variable does nothing unless
		 * read, and a preset class matches nothing until a block carries it. So
		 * this is pixel-neutral for every page that exists today, and it is the
		 * channel every token-driven block will use.
		 */
		$tokens = wp_get_global_stylesheet( array( 'variables', 'presets' ) );
		if ( $tokens !== '' ) {
			wp_register_style( 'dxai-ui-tokens', false, array(), DXAI_UI_VERSION );
			wp_enqueue_style( 'dxai-ui-tokens' );
			wp_add_inline_style( 'dxai-ui-tokens', $tokens );
		}
	}
}

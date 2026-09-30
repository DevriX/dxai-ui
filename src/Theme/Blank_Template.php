<?php
/**
 * Blank canvas page template so generated designs are not wrapped in theme chrome.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Theme;

final class Blank_Template {

	public const SLUG = 'dxai-blank.php';

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
		$templates[ self::SLUG ] = __( 'DX Blank (pixel-perfect)', 'dxai-ui' );

		return $templates;
	}

	public function include_template( string $template ): string {
		if ( ! is_singular() ) {
			return $template;
		}

		$slug = get_page_template_slug();
		if ( $slug !== self::SLUG ) {
			return $template;
		}

		$path = DXAI_UI_DIR . 'templates/dxai-blank.php';

		return is_readable( $path ) ? $path : $template;
	}

	public function enqueue_canvas(): void {
		if ( ! is_singular() || get_page_template_slug() !== self::SLUG ) {
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

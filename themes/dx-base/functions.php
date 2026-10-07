<?php
/**
 * DX Base: the structure of a DX site. The header is Appearance > Menus, the footer is Appearance > Widgets.
 *
 * @package DX_Base
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DX_BASE_VERSION', '0.1.0' );

/**
 * What the theme supports and the locations and areas it names — the ids American Restoration uses, so a header and a footer installed by
 * the DX UI plugin land in the same places on either theme and a site can move between them.
 */
add_action(
	'after_setup_theme',
	static function (): void {
		load_theme_textdomain( 'dx-base', get_template_directory() . '/languages' );

		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'custom-logo', array( 'flex-width' => true, 'flex-height' => true ) );
		add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ) );
		add_theme_support( 'responsive-embeds' );
		add_theme_support( 'wp-block-styles' );
		add_theme_support( 'align-wide' );
		add_theme_support( 'automatic-feed-links' );

		register_nav_menus(
			array(
				'primary-navigation'   => __( 'Primary Navigation', 'dx-base' ),
				'footer'               => __( 'Footer', 'dx-base' ),
				'terms-and-conditions' => __( 'Terms and Conditions', 'dx-base' ),
			)
		);
	}
);

/**
 * This theme has no colours, no fonts and no header or footer of its own to keep: the design's header and footer are installed from the
 * import, and the design's colours are the site's (the plugin follows a theme's palette only where the theme has one).
 */
add_filter( 'dxai_ui_install_chrome', '__return_true' );

/**
 * The theme's own stylesheet: a plain reading layout for what the design does not draw (a post, an archive, the 404 page). The design's
 * fonts reach it through the two properties the plugin prints from the template's fonts.
 */
add_action(
	'wp_enqueue_scripts',
	static function (): void {
		wp_enqueue_style( 'dx-base', get_template_directory_uri() . '/assets/dx-base.css', array(), DX_BASE_VERSION );
	}
);

/**
 * The site's header: the design's own, built from Appearance > Menus (DX UI), else a plain one from the same menu.
 */
function dx_base_header(): void {
	if ( class_exists( '\DXAI_UI\Chrome\Template_Chrome' ) ) {
		$html = \DXAI_UI\Chrome\Template_Chrome::site_header();
		if ( '' !== $html ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the design's own markup, rendered by the plugin.

			return;
		}
	}
	?>
	<header class="dx-base-header">
		<a class="dx-base-header__brand" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a>
		<?php
		wp_nav_menu(
			array(
				'theme_location' => 'primary-navigation',
				'container'      => 'nav',
				'menu_class'     => 'dx-base-header__menu',
				'fallback_cb'    => false,
			)
		);
		?>
	</header>
	<?php
}

/**
 * The site's footer: the design's frame around the widgets of Appearance > Widgets (DX UI), else the widgets in a plain frame.
 */
function dx_base_footer(): void {
	if ( class_exists( '\DXAI_UI\Chrome\Template_Chrome' ) ) {
		$html = \DXAI_UI\Chrome\Template_Chrome::site_footer();
		if ( '' !== $html ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the design's own markup, rendered by the plugin.

			return;
		}
	}
	?>
	<footer class="dx-base-footer">
		<?php
		foreach ( array( 'footer-column-1', 'footer-column-2', 'footer-column-3', 'footer-column-4', 'footer-copyright' ) as $area ) {
			if ( is_active_sidebar( $area ) ) {
				echo '<div class="dx-base-footer__area">';
				dynamic_sidebar( $area );
				echo '</div>';
			}
		}
		?>
	</footer>
	<?php
}

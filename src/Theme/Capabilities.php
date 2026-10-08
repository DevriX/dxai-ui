<?php
/**
 * What the active theme can do for a design: the contract the plugin asks instead of the theme's name.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Theme;

use DXAI_UI\Chrome\Chrome_Choice;

/**
 * One place that answers what the site's theme offers a design (docs/PLAN-ARCHITECTURE.md, section 5): whether it is a DX theme
 * and draws the header and the footer itself, whether it has a theme.json (presets, global styles, a style variation), which
 * picture block it registers, which button styles it has, its palette, its fonts, its layout sizes, its menu locations and
 * widget areas, and whether a style variation can be written into its `styles/` folder.
 *
 * The answers come from the classes that have read the theme all along (Theme_Compat, Theme_Palette, Theme_Fonts, Theme_Buttons,
 * Base_Theme, Chrome_Choice); what is new is that a reader asks here, and nothing that asks here needs the theme's name. The
 * Design IR's style variation is the first reader; the plan moves the others over one by one (phase 2в).
 */
final class Capabilities {

	/** The picture block the theme registers, or core's. */
	public static function picture_block(): string {
		$registry = \WP_Block_Type_Registry::get_instance();

		return $registry->is_registered( 'dx/picture' ) ? 'dx/picture' : 'core/image';
	}

	public static function is_dx(): bool {
		return Theme_Compat::is_dx_theme();
	}

	public static function is_base(): bool {
		return Theme_Compat::is_base_theme();
	}

	/** Whether the theme has a theme.json: presets, global styles and a style variation mean something only then. */
	public static function has_theme_json(): bool {
		return function_exists( 'wp_theme_has_theme_json' ) && wp_theme_has_theme_json();
	}

	public static function is_block_theme(): bool {
		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	}

	/** Whether the theme draws the site's header and footer from Menus and Widgets on every page (a DX theme, DX Base). */
	public static function draws_chrome(): bool {
		return Chrome_Choice::theme_draws_chrome();
	}

	/** @return array<int, string> The button block styles the theme has (Theme_Buttons::KNOWN order). */
	public static function button_styles(): array {
		return Theme_Buttons::styles();
	}

	/** @return array<string, string> The theme's palette, slug => hex, '' when it has none of its own. */
	public static function palette(): array {
		$out = array();
		foreach ( Theme_Palette::current()['entries'] as $slug => $entry ) {
			$out[ (string) $slug ] = (string) ( $entry['hex'] ?? '' );
		}

		return $out;
	}

	/** @return array{id:string, heading:string, body:string, heading_slug:string, body_slug:string, faces:string}|null The fonts the theme lets the site choose, when it does. */
	public static function fonts(): ?array {
		return Theme_Fonts::current();
	}

	/** @return array{content:string, wide:string} The theme's content and wide widths, '' when it says none. */
	public static function layout_sizes(): array {
		$layout = function_exists( 'wp_get_global_settings' ) ? wp_get_global_settings( array( 'layout' ) ) : array();

		return array(
			'content' => is_array( $layout ) ? (string) ( $layout['contentSize'] ?? '' ) : '',
			'wide'    => is_array( $layout ) ? (string) ( $layout['wideSize'] ?? '' ) : '',
		);
	}

	/** @return array<string, string> The theme's menu locations, id => name. */
	public static function menu_locations(): array {
		return array_map( 'strval', (array) get_registered_nav_menus() );
	}

	/** @return array<int, string> The theme's widget areas, by id. */
	public static function widget_areas(): array {
		return array_values( array_map( 'strval', array_keys( (array) ( $GLOBALS['wp_registered_sidebars'] ?? array() ) ) ) );
	}

	/** The folder a style variation of the theme lives in. */
	public static function styles_dir(): string {
		return wp_normalize_path( get_stylesheet_directory() . '/styles' );
	}

	/**
	 * Whether the plugin may write a style variation into the theme's `styles/` folder: only into a theme that is its own (DX Base),
	 * when the site lets files be changed and the folder can be written. A team's theme is never written into.
	 */
	public static function styles_writable(): bool {
		if ( ! self::has_theme_json() || ! function_exists( 'wp_is_file_mod_allowed' ) || ! wp_is_file_mod_allowed( 'dxai_ui_style_variation' ) ) {
			return false;
		}
		$dir = wp_normalize_path( get_stylesheet_directory() );
		if ( ! Base_Theme::is_ours( $dir ) ) {
			return false;
		}
		$styles = self::styles_dir();

		return is_dir( $styles ) ? wp_is_writable( $styles ) : wp_is_writable( $dir );
	}

	/**
	 * The style variations the theme's `styles/` folder holds, file => title.
	 *
	 * @return array<string, string>
	 */
	public static function variations(): array {
		$out = array();
		$dir = self::styles_dir();
		if ( ! is_dir( $dir ) ) {
			return $out;
		}
		foreach ( (array) glob( $dir . '/*.json' ) as $file ) {
			if ( ! is_string( $file ) || ! is_readable( $file ) ) {
				continue;
			}
			$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$out[ basename( $file ) ] = is_array( $data ) ? (string) ( $data['title'] ?? '' ) : '';
		}

		return $out;
	}

	/**
	 * Everything above, for a screen or a command.
	 *
	 * @return array<string, mixed>
	 */
	public static function report(): array {
		$theme = wp_get_theme();

		return array(
			'theme'           => array(
				'slug'        => get_stylesheet(),
				'name'        => (string) $theme->get( 'Name' ),
				'dx'          => self::is_dx(),
				'base'        => self::is_base(),
				'block_theme' => self::is_block_theme(),
				'theme_json'  => self::has_theme_json(),
			),
			'draws_chrome'    => self::draws_chrome(),
			'picture_block'   => self::picture_block(),
			'button_styles'   => self::button_styles(),
			'palette'         => self::palette(),
			'fonts'           => self::fonts() !== null,
			'layout'          => self::layout_sizes(),
			'menu_locations'  => self::menu_locations(),
			'widget_areas'    => self::widget_areas(),
			'styles_dir'      => self::styles_dir(),
			'styles_writable' => self::styles_writable(),
			'variations'      => self::variations(),
		);
	}
}

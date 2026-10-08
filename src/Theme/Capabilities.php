<?php
/**
 * What the active theme can do for a design: the contract the plugin asks instead of the theme's name.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Theme;

use DXAI_UI\Chrome\Chrome_Choice;
use DXAI_UI\Theme\Adapters\Amr_Adapter;
use DXAI_UI\Theme\Adapters\Block_Theme_Adapter;
use DXAI_UI\Theme\Adapters\Classic_Adapter;
use DXAI_UI\Theme\Adapters\Dx_Base_Adapter;
use DXAI_UI\Theme\Adapters\Theme_Adapter;

/**
 * One place that answers what the site's theme offers a design (docs/PLAN-ARCHITECTURE.md, section 5): whether it is a DX theme
 * and draws the header and the footer itself, whether it has a theme.json (presets, global styles, a style variation), which
 * blocks of its own stand in for a design's pictures, link boxes and labels (and how they are written), what it brings on its own
 * pages, its button styles, its palette, its fonts, its layout sizes, its menu locations and widget areas, and whether a style
 * variation can be written into its `styles/` folder.
 *
 * The answers about the kind of theme come from its adapter (Adapters\Theme_Adapter: DX Base, a DX theme, a block theme, a classic
 * theme) — the one folder that spells a theme's name or its blocks' —, the rest from the classes that have read the theme all along
 * (Theme_Compat, Theme_Palette, Theme_Fonts, Theme_Buttons, Base_Theme, Chrome_Choice). Nothing that asks here needs the theme's
 * name; bin/verify-theme-contract.php holds the rest of the plugin to that.
 */
final class Capabilities {

	/** What a theme may bring on its own pages, and the theme support any theme declares to say it does. */
	public const FEATURES = array(
		'video-facade'   => 'dxai-youtube-facade',
		'library-styles' => 'dxai-block-library',
	);

	/** Core's block for a role, where the theme has none of its own. */
	public const CORE_BLOCKS = array(
		'picture' => 'core/image',
	);

	/** @var Theme_Adapter|null An adapter assumed in a test, in place of the active theme's. */
	private static ?Theme_Adapter $assumed = null;

	/**
	 * The adapters, in the order they are tried: a theme is read through the first that matches.
	 *
	 * @return array<int, Theme_Adapter>
	 */
	public static function adapters(): array {
		return array( new Dx_Base_Adapter(), new Amr_Adapter(), new Block_Theme_Adapter(), new Classic_Adapter() );
	}

	/** The adapter of the active theme. Not memoised: a suite switches themes within one request. */
	public static function adapter(): Theme_Adapter {
		if ( self::$assumed !== null ) {
			return self::$assumed;
		}
		foreach ( self::adapters() as $adapter ) {
			if ( $adapter->matches() ) {
				return $adapter;
			}
		}

		return new Classic_Adapter();
	}

	/** Tests only: read the theme through this adapter (null: the active theme's again). */
	public static function assume( ?Theme_Adapter $adapter ): void {
		self::$assumed = $adapter;
	}

	public static function has_block( string $name ): bool {
		return $name !== '' && \WP_Block_Type_Registry::get_instance()->is_registered( $name );
	}

	/**
	 * Every block any adapter knows for a role, the active theme's first: for recognising saved content, which may have been made on
	 * another theme.
	 *
	 * @return array<int, string>
	 */
	public static function blocks( string $role ): array {
		$out = array();
		foreach ( array_merge( array( self::adapter() ), self::adapters() ) as $adapter ) {
			$name = (string) ( $adapter->blocks()[ $role ] ?? '' );
			if ( $name !== '' && ! in_array( $name, $out, true ) ) {
				$out[] = $name;
			}
		}

		return $out;
	}

	/** Whether a block plays this role on any theme (blocks()). */
	public static function is_block( string $name, string $role ): bool {
		return $name !== '' && in_array( $name, self::blocks( $role ), true );
	}

	/** The block a theme names for a role — the active theme's, else the first any adapter names —, '' when none does. Registered or not. */
	public static function theme_block( string $role ): string {
		return (string) ( self::blocks( $role )[0] ?? '' );
	}

	/** The block to make for a role on this site: the first theme block for it that is registered, else core's (CORE_BLOCKS), else ''. */
	public static function block( string $role ): string {
		foreach ( self::blocks( $role ) as $name ) {
			if ( self::has_block( $name ) ) {
				return $name;
			}
		}

		return (string) ( self::CORE_BLOCKS[ $role ] ?? '' );
	}

	/** The picture block the theme registers, or core's. */
	public static function picture_block(): string {
		return self::block( 'picture' );
	}

	/**
	 * Every block that is a picture on this site: core's, the plugin's own, and the themes'.
	 *
	 * @return array<int, string>
	 */
	public static function image_blocks(): array {
		return array_merge( array( 'core/image', 'dxai-ui/image' ), self::blocks( 'picture' ) );
	}

	/**
	 * How a theme's block is written (Theme_Adapter::markup()), or null for a block no adapter knows.
	 *
	 * @return array{tag:string, classes:array<int, string>}|null
	 */
	public static function markup_of( string $block ): ?array {
		foreach ( array_merge( array( self::adapter() ), self::adapters() ) as $adapter ) {
			$markup = $adapter->markup()[ $block ] ?? null;
			if ( is_array( $markup ) ) {
				return array(
					'tag'     => (string) ( $markup['tag'] ?? '' ),
					'classes' => array_values( array_map( 'strval', (array) ( $markup['classes'] ?? array() ) ) ),
				);
			}
		}

		return null;
	}

	/**
	 * The attributes of the themes' blocks that hold an attachment id, every adapter's together.
	 *
	 * @return array<string, array<int, string>> block name => attribute names.
	 */
	public static function media_id_attrs(): array {
		$out = array();
		foreach ( self::adapters() as $adapter ) {
			foreach ( $adapter->media_id_attrs() as $block => $attrs ) {
				$out[ (string) $block ] = array_values( array_unique( array_merge( $out[ (string) $block ] ?? array(), array_map( 'strval', (array) $attrs ) ) ) );
			}
		}

		return $out;
	}

	/**
	 * The attributes of a theme's picture block (Theme_Adapter::picture_shape()); empty for a block that is no theme's picture.
	 *
	 * @return array{id:string, url:string, alt:string, width:string, height:string, mobile:array<int, string>}|array{}
	 */
	public static function picture_shape( string $block ): array {
		foreach ( array_merge( array( self::adapter() ), self::adapters() ) as $adapter ) {
			if ( ( $adapter->blocks()['picture'] ?? '' ) === $block && $adapter->picture_shape() !== array() ) {
				return $adapter->picture_shape();
			}
		}

		return array();
	}

	/** Whether the theme brings a feature on its own pages (FEATURES): it says so with a theme support, or its adapter knows it does. */
	public static function brings( string $feature ): bool {
		$support = (string) ( self::FEATURES[ $feature ] ?? '' );

		return ( $support !== '' && current_theme_supports( $support ) ) || in_array( $feature, self::adapter()->brings(), true );
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
		$theme   = wp_get_theme();
		$adapter = self::adapter();

		return array(
			'theme'           => array(
				'slug'        => get_stylesheet(),
				'name'        => (string) $theme->get( 'Name' ),
				'adapter'     => $adapter->id(),
				'dx'          => self::is_dx(),
				'base'        => self::is_base(),
				'block_theme' => self::is_block_theme(),
				'theme_json'  => self::has_theme_json(),
			),
			'draws_chrome'    => self::draws_chrome(),
			'picture_block'   => self::picture_block(),
			'blocks'          => $adapter->blocks(),
			'brings'          => array_values( array_filter( array_keys( self::FEATURES ), array( self::class, 'brings' ) ) ),
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

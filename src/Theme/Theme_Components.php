<?php
/**
 * The theme's own components, in the sections copied onto one of its pages.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Theme;

use DXAI_UI\Compiler\Utility_Classes;
use DXAI_UI\Support\Css_Trim;
use DXAI_UI\Support\Upload_Paths;

/**
 * A page of the theme that holds sections copied from a design page shows the theme's stylesheets fenced off them
 * (Theme_Fence): the theme's rules for headings, links and spacing would restyle a design. The blocks copied along
 * are not always the design's, though. The team builds parts of a page from the theme's own components — american-
 * restoration's FAQ is a group with `faq-question`, a paragraph with `faq-answer`, an image with `faq-arrow` — and
 * those are only styled by the theme's stylesheet: fenced, every answer is open and they run into each other. On
 * the page they were copied from, which carries the theme's stylesheet whole, they were fine.
 *
 * So a class that the page's blocks carry and only the theme defines — not the design's sheet, not a utility the
 * plugin supplies (Utility_Classes), not one of WordPress's — gets its rules from the theme back, for the elements
 * inside the sections: the same rules at the same weight, narrowed to `:where(.dxai-ui)` (Css_Trim::components()),
 * in one small sheet printed after the theme's, as it is on the page they were copied from. The states a script
 * adds to a component (`faq-question--expanded`) are found by their name: a class that starts with the component's
 * name and `--` or `__`.
 *
 * The rules are read from the theme's own compiled stylesheets, so nothing is named after a theme. The sheet is
 * made once for a set of classes and kept in uploads/dxai-ui/theme/; it is made again when the theme changes.
 */
final class Theme_Components {

	/** Classes that are WordPress's or the plugin's own, never a theme's component. */
	private const NOT_COMPONENTS = '/^(?:wp-|has-|is-layout-|is-content-|is-vertical$|is-nowrap$|is-resized$|is-style-[\w-]*button$|align|size-|screen-reader|admin-bar|current-|menu-|page-|post-|attachment-|dxai-|dxs-|sc-)/';

	/** @var array<string, array<string, true>> Design sheet path + time => its class names. */
	private static array $designs = array();

	/** @var array<string, true>|null */
	private static ?array $theme = null;

	/**
	 * Print the rules of the theme's components the page's content uses, after the theme's stylesheet.
	 *
	 * @param string $markup     The page's stored content.
	 * @param string $design_css Path of the design's stylesheet.
	 */
	public static function enqueue( string $markup, string $design_css ): void {
		$wanted = self::wanted( $markup, $design_css );
		if ( $wanted === array() ) {
			return;
		}
		$file = self::sheet( $wanted );
		if ( $file === '' ) {
			return;
		}
		$path = Upload_Paths::path( $file );
		$deps = wp_style_is( 'theme_style', 'registered' ) ? array( 'theme_style' ) : array();
		wp_enqueue_style( 'dxai-ui-theme-components', Upload_Paths::url( $file ), $deps, $path !== '' ? Upload_Paths::version( $path ) : DXAI_UI_VERSION );
	}

	/**
	 * The classes of the content that only the theme defines, and the states a script adds to them.
	 *
	 * @return array<int, string>
	 */
	public static function wanted( string $markup, string $design_css ): array {
		$theme = self::theme_classes();
		if ( $theme === array() ) {
			return array();
		}
		$design = self::design_classes( $design_css );
		$base   = array();
		foreach ( Utility_Classes::class_tokens( $markup ) as $token ) {
			if ( isset( $theme[ $token ] ) && ! isset( $design[ $token ] ) && preg_match( self::NOT_COMPONENTS, $token ) !== 1 && ! Utility_Classes::is_utility( $token ) ) {
				$base[ $token ] = true;
			}
		}
		if ( $base === array() ) {
			return array();
		}
		$out = $base;
		foreach ( $theme as $name => $_ ) {
			$name = (string) $name;
			foreach ( $base as $b => $__ ) {
				if ( str_starts_with( $name, $b . '--' ) || str_starts_with( $name, $b . '__' ) ) {
					$out[ $name ] = true;
					break;
				}
			}
		}
		/**
		 * The classes of the page's content whose theme rules are kept inside the design's sections.
		 *
		 * @param array<int, string> $classes The classes found.
		 * @param string             $markup  The page's content.
		 */
		$out = (array) apply_filters( 'dxai_ui_theme_components', array_map( 'strval', array_keys( $out ) ), $markup );
		sort( $out, SORT_STRING );

		return array_values( array_unique( array_map( 'strval', $out ) ) );
	}

	/**
	 * The stylesheet with those classes' rules, uploads-relative; made when missing. '' when the theme has none.
	 *
	 * @param array<int, string> $classes
	 */
	private static function sheet( array $classes ): string {
		$key  = self::theme_key();
		$file = Upload_Paths::DIR . '/theme/components-' . substr( sha1( $key . '|' . implode( ' ', $classes ) ), 0, 16 ) . '.css';
		$out  = Upload_Paths::path( $file );
		if ( $out === '' ) {
			return '';
		}
		if ( is_readable( $out ) ) {
			return filesize( $out ) > 0 ? $file : '';
		}
		$css = '';
		foreach ( Theme_Buttons::sheets() as $sheet ) {
			$text = (string) file_get_contents( $sheet ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$cut  = Css_Trim::components( $text, $classes, ':where(.dxai-ui)' );
			if ( $cut !== null && $cut !== '' ) {
				$css .= $cut;
			}
		}
		$head = '/* The theme\'s components on this page, inside the design\'s sections: ' . implode( ' ', array_slice( $classes, 0, 12 ) ) . ( count( $classes ) > 12 ? ' …' : '' ) . " (DXAI-UI). */\n";
		if ( ! wp_mkdir_p( dirname( $out ) ) || false === file_put_contents( $out, $css === '' ? $head : $head . $css ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return '';
		}

		return $css === '' ? '' : $file;
	}

	/**
	 * The class names the theme's compiled stylesheets define, kept until the theme changes.
	 *
	 * @return array<string, true>
	 */
	private static function theme_classes(): array {
		if ( self::$theme !== null ) {
			return self::$theme;
		}
		$file = Upload_Paths::path( Upload_Paths::DIR . '/theme/classes-' . substr( self::theme_key(), 0, 16 ) . '.json' );
		if ( $file !== '' && is_readable( $file ) ) {
			$known = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( is_array( $known ) ) {
				self::$theme = array_fill_keys( array_map( 'strval', $known ), true );

				return self::$theme;
			}
		}
		$names = array();
		foreach ( Theme_Buttons::sheets() as $sheet ) {
			foreach ( Utility_Classes::design_classes( (string) file_get_contents( $sheet ) ) as $name ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$names[ (string) $name ] = true;
			}
		}
		if ( $file !== '' && wp_mkdir_p( dirname( $file ) ) ) {
			file_put_contents( $file, (string) wp_json_encode( array_map( 'strval', array_keys( $names ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		self::$theme = $names;

		return self::$theme;
	}

	/**
	 * The class names the design's stylesheet defines.
	 *
	 * @return array<string, true>
	 */
	private static function design_classes( string $path ): array {
		if ( $path === '' || ! is_readable( $path ) ) {
			return array();
		}
		$key = $path . '|' . (int) filemtime( $path );
		if ( ! isset( self::$designs[ $key ] ) ) {
			self::$designs[ $key ] = array_fill_keys( array_map( 'strval', Utility_Classes::design_classes( (string) file_get_contents( $path ) ) ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		return self::$designs[ $key ];
	}

	/** Which theme, in which version, with which stylesheets: what the kept files are made from. */
	private static function theme_key(): string {
		return md5( get_stylesheet() . '|' . (string) wp_get_theme()->get( 'Version' ) . '|' . implode( ',', array_map( static fn( $f ) => $f . ':' . (int) filemtime( $f ), Theme_Buttons::sheets() ) ) );
	}
}

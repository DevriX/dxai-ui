<?php
/**
 * The button styles a theme gives core's Button block.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Theme;

use DXAI_UI\Support\Color_Math;
use DXAI_UI\Support\Upload_Paths;

/**
 * A DevriX theme styles core/button through block styles — `is-style-primary-button`, `secondary-button`,
 * `link-button`, `small-primary-button` — whose rules sit in the theme's one compiled stylesheet. A design page
 * shows none of that stylesheet (Theme_Compat drops it, Theme_Fence keeps it out of the design's sections), so a
 * button that wears the theme's style there would be a plain link. This finds which of those styles the active
 * theme has, and writes their rules — and only theirs — to one small sheet that the pages using them load.
 *
 * Nothing is named after a theme: the styles are found by reading the theme's own stylesheets for
 * `.wp-block-button.is-style-<name>` rules, so another theme with the same convention (or none) is read the same
 * way. The result is cached until the theme changes.
 */
final class Theme_Buttons {

	/** The block styles that mean a call to action, in the order a design's buttons are matched to them. */
	public const KNOWN = array( 'primary-button', 'secondary-button', 'link-button', 'small-primary-button' );

	private const OPTION = 'dxai_ui_theme_buttons';

	/** Theme stylesheets are read up to this size. */
	private const MAX_BYTES = 6291456;

	/** @var array{key:string, styles:array<int, string>, file:string}|null */
	private static ?array $memo = null;

	/**
	 * The button styles the theme has, from KNOWN.
	 *
	 * @return array<int, string>
	 */
	public static function styles(): array {
		return self::state()['styles'];
	}

	public static function has( string $style ): bool {
		return in_array( $style, self::styles(), true );
	}

	/** Whether stored markup wears one of the theme's button styles. */
	public static function used_in( string $markup ): bool {
		return str_contains( $markup, 'is-style-' ) && preg_match( '/\bis-style-(?:primary|secondary|link|small-primary)-button\b/', $markup ) === 1;
	}

	/** The stylesheet with the rules, or '' when the theme has no such styles. */
	public static function url(): string {
		$file = self::state()['file'];

		return $file === '' ? '' : Upload_Paths::url( $file );
	}

	/** Load the rules on this page. */
	public static function enqueue(): void {
		$url = self::url();
		if ( $url === '' || wp_style_is( 'dxai-ui-theme-buttons', 'enqueued' ) ) {
			return;
		}
		$path = Upload_Paths::path( self::state()['file'] );
		wp_enqueue_style( 'dxai-ui-theme-buttons', $url, array(), $path !== '' && is_readable( $path ) ? Upload_Paths::version( $path ) : DXAI_UI_VERSION );
	}

	/**
	 * The theme colour a button's text takes on the theme's filled style, when the theme's own default (white) would
	 * not read on its accent: the palette colour with the best contrast, the DevriX pages' choice (`primary-dark`)
	 * first. '' keeps the style's own text colour.
	 */
	public static function text_slug(): string {
		$palette = Theme_Palette::current();
		$accent  = Theme_Palette::hex( 'accent' );
		if ( ! $palette['has_palette'] || $accent === '' ) {
			return '';
		}
		$white = Theme_Palette::hex( 'white' ) !== '' ? Theme_Palette::hex( 'white' ) : '#ffffff';
		if ( Color_Math::contrast( $white, $accent ) >= 4.5 ) {
			return '';
		}
		$best = '';
		$top  = 0.0;
		foreach ( array( 'primary-dark', 'black', 'primary', 'secondary' ) as $slug ) {
			$hex = Theme_Palette::hex( $slug );
			if ( $hex === '' ) {
				continue;
			}
			$ratio = Color_Math::contrast( $hex, $accent );
			if ( $ratio >= 4.5 ) {
				return $slug;
			}
			if ( $ratio > $top ) {
				$top  = $ratio;
				$best = $slug;
			}
		}

		return $top > Color_Math::contrast( $white, $accent ) ? $best : '';
	}

	/**
	 * @return array{key:string, styles:array<int, string>, file:string}
	 */
	private static function state(): array {
		if ( self::$memo !== null ) {
			return self::$memo;
		}
		$files = self::sheets();
		$key   = md5( get_stylesheet() . '|' . (string) wp_get_theme()->get( 'Version' ) . '|' . implode( ',', array_map( static fn( $f ) => $f . ':' . (int) filemtime( $f ), $files ) ) );
		$saved = get_option( self::OPTION );
		if ( is_array( $saved ) && ( $saved['key'] ?? '' ) === $key && is_array( $saved['styles'] ?? null ) ) {
			$file = (string) ( $saved['file'] ?? '' );
			if ( $file === '' || ( Upload_Paths::path( $file ) !== '' && is_readable( Upload_Paths::path( $file ) ) ) ) {
				self::$memo = array(
					'key'    => $key,
					'styles' => array_values( array_map( 'strval', $saved['styles'] ) ),
					'file'   => $file,
				);

				return self::$memo;
			}
		}
		$rules  = '';
		$styles = array();
		foreach ( $files as $path ) {
			$css = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! str_contains( $css, 'wp-block-button' ) ) {
				continue;
			}
			$found  = self::extract( $css );
			$rules .= $found['css'];
			foreach ( self::KNOWN as $style ) {
				if ( in_array( $style, $found['styles'], true ) ) {
					$styles[ $style ] = true;
				}
			}
		}
		$file = '';
		if ( $styles !== array() && $rules !== '' ) {
			$file = Upload_Paths::DIR . '/theme/buttons-' . substr( $key, 0, 12 ) . '.css';
			$out  = Upload_Paths::path( $file );
			$head = '/* The button styles of ' . wp_get_theme()->get( 'Name' ) . ', for the pages that do not load its stylesheet (DXAI-UI). */' . "\n";
			if ( $out === '' || ! wp_mkdir_p( dirname( $out ) ) || false === file_put_contents( $out, $head . $rules ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$file   = '';
				$styles = array();
			}
		}
		self::$memo = array(
			'key'    => $key,
			'styles' => array_values( array_intersect( self::KNOWN, array_keys( $styles ) ) ),
			'file'   => $file,
		);
		update_option( self::OPTION, self::$memo, false );

		return self::$memo;
	}

	/**
	 * The theme's compiled stylesheets: a few files under its css folders, small enough to read.
	 *
	 * @return array<int, string>
	 */
	private static function sheets(): array {
		$out = array();
		foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $dir ) {
			foreach ( array( '/assets/dist/css/*.css', '/assets/css/*.css', '/dist/css/*.css', '/css/*.css', '/build/*.css', '/style.css' ) as $pattern ) {
				foreach ( (array) glob( $dir . $pattern ) as $file ) {
					if ( is_string( $file ) && is_readable( $file ) && filesize( $file ) <= self::MAX_BYTES && ! str_ends_with( $file, '.map' ) ) {
						$out[ wp_normalize_path( $file ) ] = wp_normalize_path( $file );
					}
				}
			}
		}

		return array_slice( array_values( $out ), 0, 12 );
	}

	/**
	 * The rules of a stylesheet that style the Button block's own styles (`.wp-block-button.is-style-…`,
	 * `.is-*-hover`, `.has-inverse-hover`), the @keyframes they use, and the block styles found.
	 *
	 * @return array{css:string, styles:array<int, string>}
	 */
	public static function extract( string $css ): array {
		$css    = (string) preg_replace( '~/\*.*?\*/~s', '', $css );
		$styles = array();
		$frames = array();
		$kept   = self::walk( $css, $styles, $frames );
		// The keyframes the kept rules animate with.
		$names = array();
		if ( preg_match_all( '/animation(?:-name)?\s*:\s*([a-z][\w-]*)/i', $kept, $m ) ) {
			$names = array_unique( $m[1] );
		}
		$anim = '';
		foreach ( $names as $name ) {
			if ( isset( $frames[ $name ] ) ) {
				$anim .= $frames[ $name ];
			}
		}

		return array(
			'css'    => $anim . $kept,
			'styles' => array_values( array_intersect( self::KNOWN, array_keys( $styles ) ) ),
		);
	}

	/**
	 * @param array<string, true>   $styles
	 * @param array<string, string> $frames
	 */
	private static function walk( string $css, array &$styles, array &$frames ): string {
		$out = '';
		$len = strlen( $css );
		$i   = 0;
		while ( $i < $len ) {
			$open = strpos( $css, '{', $i );
			$semi = strpos( $css, ';', $i );
			if ( $open === false ) {
				break;
			}
			if ( $semi !== false && $semi < $open ) {
				$i = $semi + 1;
				continue;
			}
			$prelude = trim( substr( $css, $i, $open - $i ) );
			$end     = self::block_end( $css, $open );
			$body    = substr( $css, $open + 1, $end - $open - 1 );
			$i       = $end + 1;
			if ( $prelude === '' ) {
				continue;
			}
			if ( preg_match( '/^@(?:-webkit-)?keyframes\s+([\w-]+)/i', $prelude, $m ) === 1 ) {
				$frames[ $m[1] ] = $prelude . '{' . $body . '}';
			} elseif ( preg_match( '/^@(media|supports|layer|container)\b/i', $prelude ) === 1 ) {
				$inner = self::walk( $body, $styles, $frames );
				if ( $inner !== '' ) {
					$out .= $prelude . '{' . $inner . '}';
				}
			} elseif ( $prelude[0] !== '@' && self::is_button_rule( $prelude ) ) {
				if ( preg_match_all( '/\.wp-block-button\.is-style-([\w-]+)/', $prelude, $found ) ) {
					foreach ( $found[1] as $name ) {
						$styles[ $name ] = true;
					}
				}
				$out .= $prelude . '{' . $body . '}';
			}
		}

		return $out;
	}

	/** A rule of the Button block's own styles: the selector names `.wp-block-button` together with a style or state class. */
	private static function is_button_rule( string $selectors ): bool {
		if ( ! str_contains( $selectors, 'wp-block-button' ) ) {
			return false;
		}

		return preg_match( '/\.wp-block-button(?:__link)?[^,{]*?(?:\.is-style-|\.is-[a-z-]*hover|\.has-inverse-hover|\.no-animation)/', $selectors ) === 1
			|| preg_match( '/\.is-style-[\w-]*button[^,{]*\.wp-block-button__link/', $selectors ) === 1;
	}

	/** The index of the brace that closes the block opened at $open. */
	private static function block_end( string $css, int $open ): int {
		$depth = 0;
		$len   = strlen( $css );
		$quote = '';
		for ( $i = $open; $i < $len; $i++ ) {
			$ch = $css[ $i ];
			if ( $quote !== '' ) {
				if ( $ch === '\\' ) {
					++$i;
				} elseif ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( $ch === '"' || $ch === "'" ) {
				$quote = $ch;
			} elseif ( $ch === '{' ) {
				++$depth;
			} elseif ( $ch === '}' ) {
				--$depth;
				if ( $depth === 0 ) {
					return $i;
				}
			}
		}

		return $len - 1;
	}
}

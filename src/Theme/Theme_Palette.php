<?php
/**
 * The active theme's colour settings.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Theme;

use DXAI_UI\Support\Color_Math;

/**
 * What colours the site's theme defines, read the way WordPress itself reads
 * them: `wp_get_global_settings( ['color','palette'] )`. That one call covers
 * a theme.json palette, a classic theme's `editor-color-palette` support,
 * style variations, colours a person set in Styles, and every filter on the
 * theme layer — american-restoration's sync from its ACF "Theme Global
 * Settings" included — and the palettes Astra, Kadence, GeneratePress and
 * Blocksy register for the editor.
 *
 * Only the theme's own colours count ('theme' and 'custom' origins). Core's
 * default palette (vivid-red and the rest) is always there and says nothing
 * about the theme; its white and black are kept as exact-match anchors only.
 * Colours this plugin registered itself (a design adopted as the site's brand,
 * Design_Theme_Json) are left out, so a design is never fitted to itself.
 *
 * theme.json is never read from disk: on a network american-restoration
 * rewrites that file from whichever site saved last.
 */
final class Theme_Palette {

	/** @var array<string, mixed>|null */
	private static ?array $memo = null;

	/**
	 * The palette, once per request.
	 *
	 * @return array{has_palette: bool, entries: array<string, array{slug:string, name:string, raw:string, hex:string, origin:string}>, anchors: array<string, string>, hash: string, user_override: bool}
	 */
	public static function current(): array {
		if ( self::$memo !== null ) {
			return self::$memo;
		}
		$empty = array(
			'has_palette'   => false,
			'entries'       => array(),
			'anchors'       => array(),
			'hash'          => '',
			'user_override' => false,
		);
		if ( ! function_exists( 'wp_get_global_settings' ) || ! did_action( 'after_setup_theme' ) ) {
			return $empty;
		}
		$origins = wp_get_global_settings( array( 'color', 'palette' ) );
		if ( ! is_array( $origins ) ) {
			return self::$memo = $empty;
		}
		$own     = self::plugin_slugs();
		$entries = array();
		$theme   = array();
		// The theme's entries, then the ones a person set in Styles over them.
		foreach ( array( 'theme', 'custom' ) as $origin ) {
			foreach ( (array) ( $origins[ $origin ] ?? array() ) as $row ) {
				$slug = sanitize_key( (string) ( $row['slug'] ?? '' ) );
				$raw  = trim( (string) ( $row['color'] ?? '' ) );
				// A colour the plugin registered itself has the design's value under the design's slug.
				if ( $slug === '' || $raw === '' || ( isset( $own[ $slug ] ) && strtolower( $own[ $slug ] ) === strtolower( $raw ) ) ) {
					continue;
				}
				if ( $origin === 'theme' ) {
					$theme[ $slug ] = $raw;
				}
				$entries[ $slug ] = array(
					'slug'   => $slug,
					'name'   => sanitize_text_field( (string) ( $row['name'] ?? $slug ) ),
					'raw'    => $raw,
					'hex'    => self::resolve( $raw ),
					'origin' => $origin,
				);
			}
		}
		$override = false;
		foreach ( $entries as $slug => $entry ) {
			if ( $entry['origin'] === 'custom' && isset( $theme[ $slug ] ) && strtolower( $theme[ $slug ] ) !== strtolower( $entry['raw'] ) ) {
				$override = true;
			}
		}
		$anchors = array();
		foreach ( (array) ( $origins['default'] ?? array() ) as $row ) {
			$slug = (string) ( $row['slug'] ?? '' );
			if ( in_array( $slug, array( 'white', 'black' ), true ) && ! isset( $entries[ $slug ] ) ) {
				$anchors[ $slug ] = self::resolve( (string) ( $row['color'] ?? '' ) );
			}
		}
		ksort( $entries );
		self::$memo = array(
			'has_palette'   => $entries !== array(),
			'entries'       => $entries,
			'anchors'       => array_filter( $anchors ),
			'hash'          => $entries === array() ? '' : sha1( (string) wp_json_encode( array_map( static fn( $e ) => $e['raw'], $entries ) ) ),
			'user_override' => $override,
		);

		return self::$memo;
	}

	/** Forget the palette read on this request (after a palette change, and in tests). */
	public static function reset(): void {
		self::$memo = null;
	}

	/** Whether a slug is one of the theme's colours right now (or white/black from core's defaults). */
	public static function has( string $slug ): bool {
		$palette = self::current();

		return isset( $palette['entries'][ $slug ] ) || isset( $palette['anchors'][ $slug ] );
	}

	/** A slug's colour as #rrggbb on this request ('' when it cannot be resolved to a plain colour). */
	public static function hex( string $slug ): string {
		$palette = self::current();

		return (string) ( $palette['entries'][ $slug ]['hex'] ?? $palette['anchors'][ $slug ] ?? '' );
	}

	/**
	 * A palette value as #rrggbb, for the matching and contrast maths only (the output always references the
	 * preset). `var(--x, #hex)` takes its fallback; a value built from other variables (`color-mix()`, a bare
	 * `var()`) cannot be resolved here and is ''.
	 */
	private static function resolve( string $raw ): string {
		$raw = trim( $raw );
		if ( preg_match( '/^var\(\s*--[\w-]+\s*,\s*(.+)\)$/', $raw, $m ) ) {
			$raw = trim( $m[1] );
		}

		return Color_Math::hex( $raw );
	}

	/**
	 * The slugs this plugin put into the theme layer itself: the colours of the design adopted as the site's brand
	 * (Design_Theme_Json registers them there), which are not the theme's.
	 *
	 * @return array<string, string> slug => the design's value
	 */
	private static function plugin_slugs(): array {
		$design = get_option( Design_Theme_Json::OPTION );
		$page   = is_array( $design ) ? (int) ( $design['page_id'] ?? 0 ) : 0;
		if ( $page < 1 || ! Theme_Compat::may_install_chrome() ) {
			return array();
		}
		$palette = get_post_meta( $page, \DXAI_UI\Compiler\Token_Styles::META, true );
		$out     = array();
		foreach ( array( 'colors', 'vars' ) as $key ) {
			foreach ( is_array( $palette[ $key ] ?? null ) ? $palette[ $key ] : array() as $row ) {
				$slug = sanitize_key( (string) ( is_array( $row ) ? ( $row['slug'] ?? '' ) : '' ) );
				if ( $slug !== '' ) {
					$out[ $slug ] = trim( (string) ( $row['value'] ?? '' ) );
				}
			}
		}
		return $out;
	}
}

<?php
/**
 * What the site's owner chose in the theme's "Theme Global Settings" (ACF options page), when they chose it.
 *
 * @package DXAI_UI\Theme
 */

declare(strict_types=1);

namespace DXAI_UI\Theme;

/**
 * The DevriX themes keep the site-wide choices — colours, fonts, logos, the radius of buttons and cards, the header and footer — in an ACF
 * options page, "Theme Global Settings". The plugin follows them where a design can: the fonts (Theme_Fonts) and the colours
 * (Theme_Palette, read from the theme layer the theme syncs them into) already, and with this the logo of the header and the radius of the
 * theme's buttons and cards.
 *
 * Nothing here needs the options: without ACF, on another theme, or with a field left empty, a value is null (or 0, or ''), and the caller
 * does what it always did. A value is never invented, and nothing is written.
 */
final class Theme_Options {

	/** The slug of the options page the theme registers. */
	public const PAGE = 'amr-theme-global-settings';

	/**
	 * Whether the options can be read at all: ACF is active.
	 */
	public static function available(): bool {
		return function_exists( 'get_field' );
	}

	/**
	 * One field of the options page, or null when there is none: ACF is not there, the field has no value, or it is empty. A field in a
	 * group is reached by the names down to it (`value( 'logos', 'primary_logo' )`).
	 *
	 * @param string ...$path The field's name, and the names of the fields inside it.
	 * @return mixed
	 */
	public static function value( string ...$path ) {
		$value = null;
		if ( self::available() && $path !== array() ) {
			$value = get_field( $path[0], 'option' );
			foreach ( array_slice( $path, 1 ) as $key ) {
				$value = is_array( $value ) ? ( $value[ $key ] ?? null ) : null;
			}
		}

		/**
		 * A value of Theme Global Settings, from somewhere else (a site that keeps them another way) or for a check.
		 *
		 * @param mixed                $value The value ACF has, or null.
		 * @param array<int, string>   $path  The names down to the field.
		 */
		$value = apply_filters( 'dxai_ui_theme_option', $value, $path );

		return $value === '' || $value === array() || $value === false ? null : $value;
	}

	/**
	 * The media-library image a field holds (whatever the field returns: the array, the id or the address), or 0.
	 *
	 * @param string ...$path
	 */
	public static function image_id( string ...$path ): int {
		$value = self::value( ...$path );
		$id    = 0;
		if ( is_array( $value ) ) {
			$id = (int) ( $value['ID'] ?? $value['id'] ?? 0 );
			if ( $id < 1 && is_string( $value['url'] ?? null ) ) {
				$id = (int) attachment_url_to_postid( (string) $value['url'] );
			}
		} elseif ( is_numeric( $value ) ) {
			$id = (int) $value;
		} elseif ( is_string( $value ) && $value !== '' ) {
			$id = (int) attachment_url_to_postid( $value );
		}

		return $id > 0 && wp_attachment_is_image( $id ) ? $id : 0;
	}

	/**
	 * The :root rule for the radius of the theme's buttons and cards, or '' when the options do not say (the theme's own rules carry their
	 * default: `var(--amr-button-border-radius, 0.5rem)`). The theme's own function writes it, so the names and the lengths are the theme's;
	 * on a converted page the theme's stylesheet — where it is printed on the theme's pages — is not loaded.
	 */
	public static function radius_css(): string {
		if ( self::value( 'border_radius_style' ) === null || ! function_exists( 'amr_build_button_theme_variables_css' ) ) {
			return '';
		}
		$css = (string) amr_build_button_theme_variables_css();

		return preg_match( '/^:root\{[a-z0-9:;.\-\s(),#%]+\}$/i', $css ) === 1 ? $css : '';
	}
}

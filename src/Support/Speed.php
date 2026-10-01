<?php
/**
 * What the plugin does for a design's page speed, and the controls for it.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Support;

use DXAI_UI\Media\Font_Host;
use DXAI_UI\Theme\Theme_Fonts;
use DXAI_UI\Theme\Theme_Trim;

/**
 * Two things cost a design's pages their first paint, both measured on the Semper Dry pages (mobile 83, with both
 * done 92-99):
 *
 *  - the fonts. A design's stylesheet asked Google for them, so the page waited for its stylesheet, which waited
 *    for Google's, which waited for the font host. Font_Host copies them here and puts the rules in the stylesheet.
 *  - the theme's stylesheet. A DevriX theme prints all of it (1.1 MB) inline in every page, and a page with a
 *    design on it uses about 2% of that. Theme_Trim prints only the part the page can use.
 *
 * The fonts are done when a design is imported and by cron for one that still asks Google; this is the account of
 * it for the Library and the command line, with a way to do it at once and to undo it. The trimming needs no
 * action: it follows each visitor's page, and the setting turns it off.
 */
final class Speed {

	/**
	 * One design's fonts.
	 *
	 * @return array{state:string, families:array<int, string>, files:int, bytes:int}
	 */
	public static function fonts( int $home ): array {
		$sheet  = Upload_Paths::for_meta( $home, '_dxai_ui_css_url' );
		$listed = get_post_meta( $home, '_dxai_ui_font_urls', true );
		$listed = is_array( $listed ) ? array_map( 'strval', $listed ) : array();
		$state  = Font_Host::state( $sheet['path'], $listed );
		$names  = array();
		foreach ( $listed as $url ) {
			$query = (string) wp_parse_url( Font_Host::normal( $url ), PHP_URL_QUERY );
			if ( preg_match_all( '/(?:^|&)family=([^:&]+)/', $query, $m ) ) {
				foreach ( $m[1] as $family ) {
					$names[] = str_replace( '+', ' ', rawurldecode( $family ) );
				}
			}
		}
		$files = array();
		$bytes = 0;
		if ( $state !== 'none' && $sheet['path'] !== '' && is_readable( $sheet['path'] ) && preg_match_all( '#url\(fonts/([0-9a-f]{20}\.[a-z0-9]+)\)#', (string) file_get_contents( $sheet['path'] ), $m ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			foreach ( array_unique( $m[1] ) as $file ) {
				$path = Upload_Paths::path( Upload_Paths::DIR . '/fonts/' . $file );
				if ( $path !== '' && is_file( $path ) ) {
					$files[] = $file;
					$bytes  += (int) filesize( $path );
				}
			}
		}

		$theme = Theme_Fonts::current();

		return array(
			'state'    => $state,
			'families' => array_values( array_unique( $names ) ),
			'files'    => count( $files ),
			'bytes'    => $bytes,
			// The theme lets the site choose its fonts: whether this design is drawn in them, and which they are.
			'managed'  => $theme !== null,
			'follows'  => $theme !== null && Theme_Fonts::follows( $home ),
			'theme'    => $theme === null ? array() : array(
				'heading' => ucwords( Theme_Fonts::first_named( $theme['heading'] ) ),
				'body'    => ucwords( Theme_Fonts::first_named( $theme['body'] ) ),
			),
		);
	}

	/** Copy a design's fonts here now. */
	public static function apply_fonts( int $home ): array {
		return Font_Host::localize_design( $home, 20 );
	}

	/** Put a design's stylesheet back the way it was imported (its fonts from Google again). */
	public static function revert_fonts( int $home ): bool {
		$sheet = Upload_Paths::for_meta( $home, '_dxai_ui_css_url' );
		if ( $sheet['path'] === '' ) {
			return false;
		}
		// Kept off for a day, so the page does not copy them again the moment it is viewed.
		set_transient( 'dxai_ui_fonts_wait_' . $home, 1, DAY_IN_SECONDS );
		// Put back as imported means its own fonts, even where the theme would draw it in its own (Theme_Fonts).
		if ( Theme_Fonts::managed() ) {
			update_post_meta( $home, Theme_Fonts::MODE_META, 'keep' );
			Theme_Fonts::reset();
		}

		return Font_Host::revert( $sheet['path'] );
	}

	/**
	 * The design keeps its own fonts (copied here from Google, as before) where the theme would draw it in its own.
	 *
	 * @return array{changed:bool, complete:bool, urls:array<int, string>, note:string}
	 */
	public static function own_fonts( int $home ): array {
		update_post_meta( $home, Theme_Fonts::MODE_META, 'keep' );
		Theme_Fonts::reset();
		delete_transient( 'dxai_ui_fonts_wait_' . $home );

		return Font_Host::localize_design( $home, 20 );
	}

	/**
	 * The design is drawn in the theme's fonts: its own leave the stylesheet, and the files nothing names go.
	 *
	 * @return array{changed:bool, complete:bool, urls:array<int, string>, note:string}
	 */
	public static function theme_fonts( int $home ): array {
		delete_post_meta( $home, Theme_Fonts::MODE_META );
		Theme_Fonts::reset();

		return Font_Host::localize_design( $home, 20 );
	}

	/**
	 * The theme's stylesheet as it is printed: whether it is trimmed, and what the last pages came to.
	 *
	 * @return array{enabled:bool, pages:array<int, array{id:int, title:string, before:int, after:int, at:int}>}
	 */
	public static function trim(): array {
		$stats = get_option( Theme_Trim::STATS, array() );
		$pages = array();
		foreach ( is_array( $stats ) ? $stats : array() as $id => $row ) {
			if ( ! is_array( $row ) || ! in_array( get_post_status( (int) $id ), array( 'publish', 'private', 'draft' ), true ) ) {
				continue;
			}
			$pages[] = array(
				'id'     => (int) $id,
				'title'  => html_entity_decode( get_the_title( (int) $id ), ENT_QUOTES, 'UTF-8' ),
				'before' => (int) ( $row['before'] ?? 0 ),
				'after'  => (int) ( $row['after'] ?? 0 ),
				'at'     => (int) ( $row['at'] ?? 0 ),
			);
		}
		usort( $pages, static fn( $a, $b ) => $b['at'] <=> $a['at'] );

		return array( 'enabled' => Theme_Trim::enabled(), 'pages' => array_slice( $pages, 0, 8 ) );
	}

	public static function set_trim( bool $on ): void {
		update_option( Theme_Trim::OPTION, $on ? '1' : '0', false );
	}
}

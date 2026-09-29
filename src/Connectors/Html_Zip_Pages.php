<?php
/**
 * Derive WP page routes from static HTML files in a design ZIP.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Connectors;

final class Html_Zip_Pages {

	/**
	 * Build Lovable-shaped pages[] from HTML sources in a ZIP.
	 *
	 * @param array<string, string> $sources path => HTML
	 * @return array<int, array{slug:string, title:string, file:string, code:string}>
	 */
	public static function from_sources( array $sources ): array {
		$pages = array();
		$files = array();
		foreach ( $sources as $path => $code ) {
			if ( ! is_string( $path ) || ! is_string( $code ) || $code === '' ) {
				continue;
			}
			$norm = str_replace( '\\', '/', $path );
			if ( ! preg_match( '/\.(?:dc\.)?html?$/i', $norm ) ) {
				continue;
			}
			// Folders that ship demo and documentation pages of fonts and libraries, not the site's pages.
			if ( preg_match( '#(?:^|/)(?:node_modules|\.git|partials?|includes?|components?|assets?|fonts?|vendor|libs?|icons?|images?|img|docs?|documentation|examples?|demos?)/#i', $norm ) ) {
				continue;
			}
			if ( ! self::is_page_file( $norm, $code ) ) {
				continue;
			}
			$files[ $norm ] = $code;
		}
		// An archive that holds its site in one folder (acme-site/index.html, acme-site/about.html): that folder
		// is the root, not a page path, so its index.html is the Home.
		$root = self::common_folder( array_keys( $files ) );
		foreach ( $files as $norm => $code ) {
			$slug = self::slug_from_path( $root !== '' ? substr( $norm, strlen( $root ) ) : $norm );
			$pages[] = array(
				'slug'  => $slug,
				'title' => self::title_from( $norm, $code ),
				'file'  => $norm,
				'code'  => $code,
			);
		}

		if ( $pages === array() ) {
			return array();
		}

		usort(
			$pages,
			static function ( array $a, array $b ): int {
				$ah = ( $a['slug'] ?? '' ) === '/' ? 0 : 1;
				$bh = ( $b['slug'] ?? '' ) === '/' ? 0 : 1;
				if ( $ah !== $bh ) {
					return $ah - $bh;
				}
				// For the Home, index.html before home/default/main.html (a web server serves index.html).
				$ai = preg_match( '#(?:^|/)index\.html?$#i', (string) ( $a['file'] ?? '' ) ) ? 0 : 1;
				$bi = preg_match( '#(?:^|/)index\.html?$#i', (string) ( $b['file'] ?? '' ) ) ? 0 : 1;
				if ( $ah === 0 && $ai !== $bi ) {
					return $ai - $bi;
				}
				$ad = substr_count( (string) ( $a['file'] ?? '' ), '/' );
				$bd = substr_count( (string) ( $b['file'] ?? '' ), '/' );

				return $ad === $bd
					? strcmp( (string) ( $a['file'] ?? '' ), (string) ( $b['file'] ?? '' ) )
					: $ad - $bd;
			}
		);

		// One page per slug — shallowest / home wins.
		$seen = array();
		$out  = array();
		foreach ( $pages as $page ) {
			$slug = Site_Origin::path_of( (string) ( $page['slug'] ?? '/' ) );
			if ( isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;
			$page['slug']  = $slug;
			$out[]         = $page;
		}

		return $out;
	}

	/**
	 * Whether an HTML file is a page of the site, not an error page, a search-console verification file, a
	 * backup or a fragment: those were each published as a page of their own.
	 */
	private static function is_page_file( string $path, string $code ): bool {
		$base = strtolower( (string) pathinfo( $path, PATHINFO_FILENAME ) );
		if ( preg_match( '/^(?:40[0-9]|50[0-9]|error|offline|maintenance|google[0-9a-f]{6,}|yandex_[0-9a-f]+|bingsiteauth|_.*|.*[-_.](?:old|bak|backup|copy|tmp))$/', $base ) ) {
			return false;
		}
		// A fragment (no document and no body) is a partial, included by other pages.
		if ( ! preg_match( '/<(?:html|body)\b/i', $code ) ) {
			return false;
		}

		return trim( wp_strip_all_tags( (string) preg_replace( '/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $code ) ) ) !== '';
	}

	/**
	 * The one folder every file is in (`acme-site/`), '' when they do not all share one.
	 *
	 * @param array<int, string> $paths
	 */
	private static function common_folder( array $paths ): string {
		$first = null;
		foreach ( $paths as $path ) {
			$seg = str_contains( $path, '/' ) ? strstr( $path, '/', true ) . '/' : '';
			if ( $seg === '' || ( $first !== null && $seg !== $first ) ) {
				return '';
			}
			$first = $seg;
		}

		return (string) $first;
	}

	/**
	 * about/index.html → /about; pricing.html → /pricing; index.html → /
	 */
	public static function slug_from_path( string $path ): string {
		$norm = str_replace( '\\', '/', $path );
		$norm = (string) preg_replace( '#^(?:\.?/)*(?:dist|public|out|build|html|www|site)/#i', '', $norm );
		$norm = ltrim( $norm, '/' );

		$dir  = str_replace( '\\', '/', (string) dirname( $norm ) );
		$base = strtolower( (string) pathinfo( $norm, PATHINFO_FILENAME ) );

		$segments = array();
		if ( $dir !== '.' && $dir !== '' ) {
			foreach ( explode( '/', $dir ) as $seg ) {
				$seg = sanitize_title( $seg );
				if ( $seg !== '' && ! in_array( $seg, array( 'dist', 'public', 'out', 'build', 'html', 'www', 'site' ), true ) ) {
					$segments[] = $seg;
				}
			}
		}

		if ( ! in_array( $base, array( 'index', 'home', 'default', 'main' ), true ) ) {
			$leaf = sanitize_title( $base );
			if ( $leaf !== '' ) {
				$segments[] = $leaf;
			}
		}

		if ( $segments === array() ) {
			return '/';
		}

		return '/' . implode( '/', $segments );
	}

	public static function title_from( string $path, string $code ): string {
		if ( preg_match( '/<title[^>]*>\s*([^<]+)/i', $code, $m ) ) {
			$title = sanitize_text_field( wp_strip_all_tags( $m[1] ) );
			// Strip "Site | Page" branding when the short leaf is clearer.
			if ( $title !== '' && ! preg_match( '/\s[\|\x{2013}\x{2014}-]\s/u', $title ) ) {
				return $title;
			}
			if ( $title !== '' && preg_match( '/^(.+?)\s[\|\x{2013}\x{2014}-]\s/u', $title, $tm ) ) {
				$short = sanitize_text_field( $tm[1] );
				if ( $short !== '' && strlen( $short ) < 60 ) {
					return $short;
				}
			}
			if ( $title !== '' ) {
				return $title;
			}
		}

		$slug = self::slug_from_path( $path );
		if ( $slug === '/' ) {
			return 'Home';
		}
		$leaf = basename( $slug );

		return ucwords( str_replace( array( '-', '_' ), ' ', $leaf ) );
	}

	/**
	 * Whether page code should compile as HTML (not JSX).
	 */
	public static function is_html_page( string $file, string $code ): bool {
		if ( preg_match( '/\.(?:dc\.)?html?$/i', $file ) ) {
			return true;
		}
		$trim = ltrim( $code );

		return $trim !== ''
			&& ( str_starts_with( $trim, '<!DOCTYPE' ) || str_starts_with( $trim, '<html' ) || str_starts_with( $trim, '<body' ) || str_starts_with( $trim, '<!--' ) )
			&& ! str_contains( $code, 'return (' );
	}
}

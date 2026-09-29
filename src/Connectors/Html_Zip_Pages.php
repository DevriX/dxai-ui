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
		foreach ( $sources as $path => $code ) {
			if ( ! is_string( $path ) || ! is_string( $code ) || $code === '' ) {
				continue;
			}
			$norm = str_replace( '\\', '/', $path );
			if ( ! preg_match( '/\.(?:dc\.)?html?$/i', $norm ) ) {
				continue;
			}
			if ( preg_match( '#(?:^|/)(?:node_modules|\.git|partials?|includes?|components?)/#i', $norm ) ) {
				continue;
			}
			$slug = self::slug_from_path( $norm );
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

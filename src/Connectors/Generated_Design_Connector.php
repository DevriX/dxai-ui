<?php
/**
 * Claude / generic frontend ZIP (HTML/CSS/JS, Vue, React, Next.js).
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Connectors;

use DXAI_UI\Media\Asset_Harvester;

final class Generated_Design_Connector {

	/**
	 * @param array<string, mixed> $file
	 */
	public function import_zip( array $file ): Source_Document|\WP_Error {
		$unpacked = Zip_Extractor::unpack( $file );
		if ( is_wp_error( $unpacked ) ) {
			return $unpacked;
		}

		$harvest = ( new Asset_Harvester() )->from_zip( $unpacked['dir'], $unpacked['files'] );
		$texts   = is_array( $harvest['texts'] ?? null ) ? $harvest['texts'] : array();
		$sources = array();
		$styles  = array();
		$scripts = array();
		$pkg     = '';

		foreach ( $unpacked['files'] as $relative ) {
			$norm = str_replace( '\\', '/', $relative );
			$ext  = strtolower( (string) pathinfo( $norm, PATHINFO_EXTENSION ) );
			$body = (string) ( $texts[ $norm ] ?? '' );

			if ( strtolower( basename( $norm ) ) === 'package.json' ) {
				$pkg = Zip_Extractor::read_text( $unpacked['dir'] . '/' . $relative, 40000 );
			}

			if ( in_array( $ext, array( 'html', 'vue', 'tsx', 'jsx', 'ts', 'js', 'mjs' ), true ) && $this->is_source_path( $norm, false ) ) {
				$sources[ $norm ] = $body !== '' ? $body : Zip_Extractor::read_text( $unpacked['dir'] . '/' . $relative, 180000 );
			}

			if ( in_array( $ext, array( 'js', 'mjs' ), true ) && $this->is_source_path( $norm, false ) && ! preg_match( '/\.(?:tsx|jsx|ts)$/i', $norm ) ) {
				$code = $body !== '' ? $body : Zip_Extractor::read_text( $unpacked['dir'] . '/' . $relative, 180000 );
				if ( $code !== '' && ! preg_match( '/(?:^|\n)\s*(?:import|export)\s/', $code ) ) {
					$scripts[ $norm ] = $code;
				}
			}

			if ( in_array( $ext, array( 'css', 'scss', 'less' ), true ) && $this->is_source_path( $norm, false ) ) {
				$styles[ $norm ] = $body !== '' ? $body : Zip_Extractor::read_text( $unpacked['dir'] . '/' . $relative, 120000 );
			}
		}

		if ( $sources === array() && $styles === array() ) {
			foreach ( $unpacked['files'] as $relative ) {
				$norm = str_replace( '\\', '/', $relative );
				$ext  = strtolower( (string) pathinfo( $norm, PATHINFO_EXTENSION ) );
				$body = (string) ( $texts[ $norm ] ?? '' );
				if ( in_array( $ext, array( 'html', 'vue', 'tsx', 'jsx', 'ts', 'js', 'mjs', 'css', 'scss', 'less' ), true ) && $this->is_source_path( $norm, true ) ) {
					if ( in_array( $ext, array( 'css', 'scss', 'less' ), true ) ) {
						$styles[ $norm ] = $body !== '' ? $body : Zip_Extractor::read_text( $unpacked['dir'] . '/' . $relative, 120000 );
					} else {
						$sources[ $norm ] = $body !== '' ? $body : Zip_Extractor::read_text( $unpacked['dir'] . '/' . $relative, 180000 );
					}
				}
			}
		}

		Zip_Extractor::cleanup( $unpacked['dir'] );

		if ( $sources === array() && $styles === array() ) {
			return new \WP_Error( 'dxai_ui_zip', __( 'No HTML/CSS/JS, Vue, React, or Next.js source was found in the ZIP.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		$stack   = $this->detect_stack( $pkg, array_keys( $sources ) );
		$summary = is_array( $harvest['summary'] ?? null ) ? $harvest['summary'] : array();
		$assets  = is_array( $harvest['assets'] ?? null ) ? $harvest['assets'] : array();

		$raw_css = implode( "\n", $styles );
		$html_css = $this->styles_from_html_sources( $sources );
		if ( $html_css !== '' ) {
			$raw_css = trim( $raw_css . "\n" . $html_css );
		}

		$limited_sources = $this->limit_map( $sources, 200 );
		$html_pages      = Html_Zip_Pages::from_sources( $limited_sources );

		return new Source_Document(
			'generated-zip',
			'Generated ' . $stack . ' design',
			array(
				'stack'          => $stack,
				'sources'        => $limited_sources,
				'styles'         => $this->limit_map( $styles, 24 ),
				'scripts'        => $this->limit_map( $scripts, 24 ),
				'pages'          => $html_pages,
				'assets'         => $assets,
				'harvest'        => Asset_Harvester::for_prompt( $harvest ),
				'package'        => $this->summarize_package( $pkg ),
				'design_css_raw' => $raw_css,
				// See Lovable_Connector: the archive name is what pairs a saved
				// page with the design it has to be measured against, byte for
				// byte — which is why it is not sanitize_file_name()'d.
				'source_name'    => wp_strip_all_tags( wp_basename( (string) ( $file['name'] ?? '' ) ) ),
				// A static HTML design keeps the browser's own margins (blank-canvas.css), unless it runs Tailwind from
				// its CDN: that page had Tailwind's preflight, so it gets the isolate reset like a Lovable build.
				'static_html'    => ( $html_pages !== array() || str_contains( $stack, 'html' ) ) && ! preg_match( '#cdn\.tailwindcss\.com|@tailwindcss/browser#i', implode( ' ', $limited_sources ) ),
			),
			$assets,
			array(
				'Unpacked generated frontend ZIP (' . count( $unpacked['files'] ) . ' files)',
				'Detected stack: ' . $stack,
				$html_pages !== array()
					? sprintf( 'HTML routes: %d page(s)', count( $html_pages ) )
					: 'No multi-page HTML routes detected',
				$this->harvest_log( $summary ),
			)
		);
	}

	/**
	 * Design.com / static HTML ZIPs often ship CSS only as <style> in the page.
	 *
	 * @param array<string, string> $sources
	 */
	private function styles_from_html_sources( array $sources ): string {
		$chunks = array();
		foreach ( $sources as $path => $code ) {
			if ( ! is_string( $code ) || $code === '' ) {
				continue;
			}
			$norm = str_replace( '\\', '/', (string) $path );
			if ( ! preg_match( '/\.(?:dc\.)?html?$/i', $norm ) ) {
				continue;
			}
			if ( preg_match_all( '/<style\b[^>]*>([\s\S]*?)<\/style>/i', $code, $m ) ) {
				foreach ( $m[1] as $css ) {
					$css = trim( (string) $css );
					if ( $css !== '' ) {
						$chunks[] = $css;
					}
				}
			}
			if ( preg_match_all( '/<link\b[^>]*rel=["\']stylesheet["\'][^>]*>/i', $code, $links ) ) {
				foreach ( $links[0] as $tag ) {
					if ( preg_match( '/href=["\']([^"\']+)["\']/i', (string) $tag, $hm ) ) {
						$href = trim( (string) $hm[1] );
						if ( $href !== '' && preg_match( '#^https?://#i', $href ) ) {
							$chunks[] = '@import url("' . str_replace( '"', '%22', $href ) . '");';
						}
					}
				}
			}
		}

		return trim( implode( "\n", $chunks ) );
	}

	/**
	 * @param array<string, int> $summary
	 */
	private function harvest_log( array $summary ): string {
		return sprintf(
			'Harvested assets: %d images, %d videos, %d fonts, %d links, %d other',
			(int) ( $summary['images'] ?? 0 ),
			(int) ( $summary['videos'] ?? 0 ),
			(int) ( $summary['fonts'] ?? 0 ),
			(int) ( $summary['links'] ?? 0 ),
			(int) ( $summary['other'] ?? 0 )
		);
	}

	/**
	 * @param array<int, string> $source_paths
	 */
	private function detect_stack( string $package_json, array $source_paths ): string {
		$joined = strtolower( $package_json . ' ' . implode( ' ', $source_paths ) );
		return match ( true ) {
			str_contains( $joined, '.dc.html' ) => 'designcom-xdc',
			str_contains( $joined, 'next' ) && ( str_contains( $package_json, '"next"' ) || str_contains( $joined, '/app/' ) ) => 'nextjs',
			str_contains( $joined, '.vue' ) || str_contains( $package_json, '"vue"' ) => 'vue',
			str_contains( $joined, '.tsx' ) || str_contains( $joined, '.jsx' ) || str_contains( $package_json, '"react"' ) => 'react',
			str_contains( $joined, '.html' ) => 'html-css-js',
			default => 'javascript',
		};
	}

	private function summarize_package( string $pkg ): string {
		if ( $pkg === '' ) {
			return '';
		}
		$decoded = json_decode( $pkg, true );
		if ( ! is_array( $decoded ) ) {
			return substr( $pkg, 0, 500 );
		}

		$deps = array_keys( (array) ( $decoded['dependencies'] ?? array() ) );
		return wp_json_encode(
			array(
				'name'         => $decoded['name'] ?? '',
				'dependencies' => array_slice( $deps, 0, 40 ),
			)
		) ?: '';
	}

	private function is_source_path( string $path, bool $allow_built = false ): bool {
		foreach ( array( 'node_modules/', '.next/', 'coverage/' ) as $skip ) {
			if ( str_contains( $path, $skip ) ) {
				return false;
			}
		}
		if ( ! $allow_built ) {
			foreach ( array( 'dist/', 'build/' ) as $skip ) {
				if ( str_contains( $path, $skip ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * @param array<string, string> $map
	 * @return array<string, string>
	 */
	private function limit_map( array $map, int $max ): array {
		if ( count( $map ) <= $max ) {
			return $map;
		}

		return array_slice( $map, 0, $max, true );
	}
}

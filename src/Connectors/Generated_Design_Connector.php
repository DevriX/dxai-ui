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
		$config  = '';

		foreach ( $unpacked['files'] as $relative ) {
			$norm = str_replace( '\\', '/', $relative );
			$ext  = strtolower( (string) pathinfo( $norm, PATHINFO_EXTENSION ) );
			$body = (string) ( $texts[ $norm ] ?? '' );

			if ( strtolower( basename( $norm ) ) === 'package.json' ) {
				$pkg = Zip_Extractor::read_text( $unpacked['dir'] . '/' . $relative, 40000 );
			}

			// A Tailwind CLI project's theme — see tailwind_config().
			if ( $config === '' && preg_match( '#(?:^|/)tailwind\.config\.(?:ts|js|cjs|mjs)$#i', $norm ) === 1 && $this->is_source_path( $norm, false ) ) {
				$config = $body !== '' ? $body : Zip_Extractor::read_text( $unpacked['dir'] . '/' . $relative, 120000 );
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
		$tailwind        = $this->tailwind_config( $sources, $config );

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
				// The rest of a Tailwind v3 design's theme, which no stylesheet in the archive declares: see tailwind_config().
				'tailwind_config' => $tailwind,
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
				...( $tailwind !== '' ? array( sprintf( 'Tailwind theme read from the design (%d bytes)', strlen( $tailwind ) ) ) : array() ),
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
	 * The design's Tailwind v3 theme, as the text Tailwind\Theme::from_css() reads, or ''.
	 *
	 * A page on the Play CDN (`cdn.tailwindcss.com`) declares its theme in a
	 * script — `tailwind.config = { theme: { extend: { colors: … } } }` — and
	 * the CDN builds `bg-brand-900` from it in the browser. Nothing in the
	 * archive's stylesheets says what `brand-900` is, so without the script
	 * every colour, font and size the design named compiled to no rule at all:
	 * Northlight Roofing's hero and call-to-action bands lost their
	 * backgrounds, and their white text sat on white. A project built with the
	 * Tailwind CLI keeps the same object in a `tailwind.config.*` file, which
	 * is read the way Lovable_Connector reads it.
	 *
	 * The script a page ran comes first: it is what the browser built the
	 * design from.
	 *
	 * @param array<string, string> $sources Archive path → text, every source of the design.
	 * @param string                $file    A `tailwind.config.*` file's text, or ''.
	 */
	private function tailwind_config( array $sources, string $file ): string {
		foreach ( $sources as $path => $code ) {
			if ( ! is_string( $code ) || ! str_contains( $code, 'tailwind.config' ) ) {
				continue;
			}
			$ext    = strtolower( (string) pathinfo( (string) $path, PATHINFO_EXTENSION ) );
			$chunks = array();
			if ( in_array( $ext, array( 'js', 'mjs' ), true ) ) {
				$chunks[] = $code;
			} elseif ( in_array( $ext, array( 'html', 'htm' ), true ) && preg_match_all( '/<script\b(?![^>]*\bsrc\s*=)[^>]*>([\s\S]*?)<\/script>/i', $code, $m ) ) {
				// Inline scripts only: a `src` one is the CDN itself, or a file of the archive that is a source of its own.
				$chunks = $m[1];
			}
			foreach ( $chunks as $chunk ) {
				$object = self::config_assignment( (string) $chunk );
				if ( $object !== '' ) {
					return $object;
				}
			}
		}

		return $file;
	}

	/**
	 * The object literal a script assigns to `tailwind.config`, or ''.
	 *
	 * Read up to the brace that closes it, with strings kept whole, so a `'}'`
	 * in a value cannot end it early, and comments left out: Tailwind\Theme
	 * reads the object as text, and a `// }` note would close a group there.
	 */
	private static function config_assignment( string $js ): string {
		if ( preg_match( '/\btailwind\.config\s*=\s*\{/', $js, $match, PREG_OFFSET_CAPTURE ) !== 1 ) {
			return '';
		}
		$length = strlen( $js );
		$depth  = 0;
		$object = '';

		for ( $i = (int) $match[0][1] + strlen( (string) $match[0][0] ) - 1; $i < $length; $i++ ) {
			$char = $js[ $i ];
			$next = $js[ $i + 1 ] ?? '';

			if ( $char === '"' || $char === "'" || $char === '`' ) {
				$start = $i;
				for ( ++$i; $i < $length && $js[ $i ] !== $char; $i++ ) {
					if ( $js[ $i ] === '\\' ) {
						++$i;
					}
				}
				$object .= substr( $js, $start, $i - $start + 1 );
				continue;
			}
			if ( $char === '/' && ( $next === '/' || $next === '*' ) ) {
				$end = $next === '/' ? strpos( $js, "\n", $i ) : strpos( $js, '*/', $i + 2 );
				if ( $end === false ) {
					return '';
				}
				// A line comment stops before its newline, which is kept.
				$i       = $next === '/' ? $end - 1 : $end + 1;
				$object .= ' ';
				continue;
			}

			$object .= $char;
			if ( $char === '{' ) {
				++$depth;
			} elseif ( $char === '}' ) {
				--$depth;
				if ( $depth === 0 ) {
					return $object;
				}
			}
		}

		return '';
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

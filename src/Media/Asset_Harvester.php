<?php
/**
 * Extract images, videos, fonts, links, tokens, and other design files
 * required for a pixel-perfect Gutenberg conversion.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Media;

use DXAI_UI\Connectors\Zip_Extractor;

final class Asset_Harvester {

	private const BINARY_EXTS = array(
		'png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'avif', 'ico', 'bmp',
		'mp4', 'webm', 'ogv', 'mov', 'm4v',
		'mp3', 'wav', 'ogg', 'm4a',
		'woff', 'woff2', 'ttf', 'otf', 'eot',
	);

	private const TEXT_EXTS = array(
		'html', 'css', 'scss', 'less', 'js', 'jsx', 'ts', 'tsx', 'vue', 'mjs', 'cjs', 'md', 'txt', 'svg',
	);

	/**
	 * @param array<int, string> $files Relative paths inside $dir.
	 * @return array<string, mixed>
	 */
	public function from_zip( string $dir, array $files ): array {
		$index     = array();
		$binaries  = array();
		$texts     = array();
		$rewrites  = array();
		$assets    = array();
		$seen      = array();

		foreach ( $files as $relative ) {
			$norm = str_replace( '\\', '/', $relative );
			$abs  = $dir . '/' . $relative;
			$ext  = strtolower( (string) pathinfo( $norm, PATHINFO_EXTENSION ) );
			$index[ $norm ]              = $abs;
			$index[ basename( $norm ) ]  = $abs;
			$index[ '/' . ltrim( $norm, '/' ) ] = $abs;

			if ( in_array( $ext, self::BINARY_EXTS, true ) || ( 'json' === $ext && $this->is_lottie_file( $abs ) ) ) {
				$binaries[ $norm ] = $abs;
			}
			if ( in_array( $ext, self::TEXT_EXTS, true ) || 'json' === $ext ) {
				/*
				 * Effectively uncapped (8 MB), and this is the governing cap:
				 * every connector takes the harvester's text first and only
				 * falls back to reading the file itself. A stylesheet cut
				 * anywhere loses layout rules silently — the page still renders
				 * and looks nearly right. A 180 KB cap cut one design's 188 KB
				 * stylesheet 8 KB from the end (a two-column section rendered
				 * as three, a carousel lost its layout); the 400 KB cap that
				 * replaced it cut Integration Hub's 585 KB styles.css after the
				 * tokens, so the page had the design's colours and none of its
				 * layout — 20671px of error over 245 boxes.
				 */
				$texts[ $norm ] = Zip_Extractor::read_text( $abs, 8000000 );
			}
		}

		/*
		 * Keyed by the exact path. It was lower-cased, which on a Linux host
		 * — case-sensitive — made `Logo.png` beside `logo.png` one file, and
		 * the second was rewritten to the first one's upload. Every lookup
		 * reaches here through $index, whose values are these same strings,
		 * so nothing needed the folding. The same bytes under two different
		 * paths are the Sideloader's to merge: it keeps this request's
		 * uploads by content hash, so the twin costs one read and returns
		 * the first upload's URL — while each path still gets its own asset
		 * row, filename and original, as the compiler's image map expects.
		 */
		/*
		 * Only files the design names. Every binary in the archive used to be
		 * uploaded to the media library, and a Claude Design export carries the
		 * designer's own source photos beside the assets the page uses: Semper
		 * Dry's `uploads/` folder is 100 MB of 6–15 MB camera JPEGs, none of
		 * them referenced, and making attachments and thumbnails for them took
		 * 88 of the import's 92 seconds — past a real host's request limit
		 * before any block was written. A file counts as named when its file
		 * name (as written or URL-encoded) appears in any of the design's text
		 * files; the `dxai_ui_harvest_unreferenced` filter brings back the old
		 * upload-everything behaviour for a design that builds paths at run time.
		 */
		$named = strtolower( implode( "\n", $texts ) );
		if ( ! (bool) apply_filters( 'dxai_ui_harvest_unreferenced', false ) ) {
			$binaries = array_filter(
				$binaries,
				static function ( $abs, $rel ) use ( $named ): bool {
					$base = strtolower( basename( (string) $rel ) );

					return $base !== '' && ( str_contains( $named, $base ) || str_contains( $named, strtolower( rawurlencode( $base ) ) ) || str_contains( $named, str_replace( ' ', '%20', $base ) ) );
				},
				ARRAY_FILTER_USE_BOTH
			);
		}
		unset( $named );

		foreach ( $binaries as $rel => $abs ) {
			$key = $abs;
			if ( isset( $seen[ $key ] ) ) {
				$rewrites = $this->alias_rewrites( $rewrites, $rel, $seen[ $key ] );
				continue;
			}
			$ingested = Sideloader::from_path( $abs, basename( $rel ), $rel );
			if ( is_wp_error( $ingested ) ) {
				continue;
			}
			$seen[ $key ] = $ingested['url'];
			$assets[]     = $ingested;
			$rewrites     = $this->alias_rewrites( $rewrites, $rel, $ingested['url'] );
		}

		foreach ( $texts as $rel => $content ) {
			if ( ! str_ends_with( strtolower( $rel ), '.json' ) ) {
				continue;
			}
			$decoded = json_decode( $content, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			if ( isset( $decoded['layers'] ) ) {
				continue;
			}
			$url = (string) ( $decoded['url'] ?? '' );
			if ( str_starts_with( $url, '/__l5e/' ) ) {
				$project = (string) ( $decoded['project_id'] ?? '' );
				if ( $project !== '' && preg_match( '/^[a-z0-9-]+$/i', $project ) ) {
					$url = 'https://id-preview--' . $project . '.lovable.app' . $url;
				}
			}
			if ( ! preg_match( '#^https?://#i', $url ) ) {
				continue;
			}
			if ( isset( $seen[ $url ] ) ) {
				$rewrites = $this->alias_rewrites( $rewrites, $rel, $seen[ $url ] );
				continue;
			}
			$filename = (string) ( $decoded['original_filename'] ?? basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
			$ingested = Sideloader::from_url( $url, $filename, $url );
			if ( is_wp_error( $ingested ) ) {
				continue;
			}
			$seen[ $url ] = $ingested['url'];
			$assets[]     = $ingested;
			$rewrites     = $this->alias_rewrites( $rewrites, $rel, $ingested['url'] );
			if ( $filename !== '' ) {
				$rewrites[ $filename ] = $ingested['url'];
			}
		}

		$blob = implode( "\n", $texts );
		$from_text = $this->parse_text( $blob, $index, $rewrites, $seen, $assets );
		$rewrites  = $from_text['rewrites'];
		$assets    = $from_text['assets'];

		$rewritten = array();
		foreach ( $texts as $rel => $content ) {
			$rewritten[ $rel ] = $this->apply_rewrites( $content, $rewrites );
		}

		$css_blob = '';
		foreach ( $rewritten as $rel => $content ) {
			if ( preg_match( '/\.(css|scss|less)$/i', $rel ) ) {
				$css_blob .= $content . "\n";
			}
		}

		$harvest = $this->assemble( $assets, $from_text['fonts'], $from_text['links'], $css_blob . "\n" . implode( "\n", $rewritten ) );
		$harvest['rewrites'] = $rewrites;
		$harvest['texts']    = $rewritten;
		$harvest['css']      = trim( (string) ( $harvest['css'] ?? '' ) . "\n" . $css_blob );

		return $harvest;
	}

	/**
	 * Harvest remote/local references from pasted HTML/CSS/JSX.
	 *
	 * @return array<string, mixed>
	 */
	public function from_text( string $code, string $css = '' ): array {
		$blob      = $code . "\n" . $css;
		$rewrites  = array();
		$assets    = array();
		$seen      = array();
		$parsed    = $this->parse_text( $blob, array(), $rewrites, $seen, $assets );
		$harvest   = $this->assemble( $parsed['assets'], $parsed['fonts'], $parsed['links'], $blob );
		$harvest['rewrites'] = $parsed['rewrites'];
		$harvest['texts']    = array(
			'pasted.tsx' => $this->apply_rewrites( $code, $parsed['rewrites'] ),
			'pasted.css' => $this->apply_rewrites( $css, $parsed['rewrites'] ),
		);

		return $harvest;
	}

	/**
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 */
	public function from_figma( array $node, array $sideloaded = array() ): array {
		$fonts  = array();
		$links  = array();
		$tokens = array(
			'colors'  => array(),
			'shadows' => array(),
			'radii'   => array(),
			'fonts'   => array(),
		);

		$this->walk_figma( $node, $fonts, $links, $tokens );

		$font_rows = array();
		foreach ( $fonts as $family => $weights ) {
			$weights = array_values( array_unique( array_map( 'intval', $weights ) ) );
			sort( $weights );
			$font_rows[] = array(
				'family'      => $family,
				'weights'     => $weights,
				'stylesheet'  => $this->google_font_url( $family, $weights ),
				'files'       => array(),
			);
		}

		$harvest = $this->assemble( $sideloaded, $font_rows, $links, '' );
		$harvest['tokens'] = $tokens;

		return $harvest;
	}

	/**
	 * Compact payload for the LLM (URLs only, no filesystem paths).
	 *
	 * @param array<string, mixed> $harvest
	 * @return array<string, mixed>
	 */
	public static function for_prompt( array $harvest ): array {
		$strip = static function ( array $item ): array {
			return array(
				'url'      => (string) ( $item['url'] ?? '' ),
				'kind'     => (string) ( $item['kind'] ?? '' ),
				'original' => (string) ( $item['original'] ?? '' ),
				'filename' => (string) ( $item['filename'] ?? '' ),
			);
		};

		return array(
			'summary'  => $harvest['summary'] ?? array(),
			'images'   => array_map( $strip, array_slice( $harvest['images'] ?? array(), 0, 200 ) ),
			'videos'   => array_map( $strip, array_slice( $harvest['videos'] ?? array(), 0, 40 ) ),
			'fonts'    => array_slice( $harvest['fonts'] ?? array(), 0, 40 ),
			'links'    => array_slice( $harvest['links'] ?? array(), 0, 80 ),
			'tokens'   => $harvest['tokens'] ?? array(),
			'css'      => (string) ( $harvest['css'] ?? '' ),
			'rewrites' => array_slice( $harvest['rewrites'] ?? array(), 0, 400, true ),
			'assets'   => array_map( $strip, array_slice( $harvest['assets'] ?? array(), 0, 200 ) ),
		);
	}

	/**
	 * @param array<string, string> $index  relative/basename => absolute path
	 * @param array<string, string> $rewrites
	 * @param array<string, string> $seen   abs/url => wp url
	 * @param array<int, array<string, mixed>> $assets
	 * @return array{rewrites:array<string,string>, assets:array<int, array<string, mixed>>, fonts:array<int, array<string, mixed>>, links:array<int, array<string, string>>}
	 */
	private function parse_text( string $blob, array $index, array $rewrites, array &$seen, array $assets ): array {
		$fonts = array();
		$links = array();
		$refs  = $this->extract_refs( $blob );

		foreach ( $refs['urls'] as $ref ) {
			$ref = trim( $ref );
			if ( $ref === '' || str_starts_with( $ref, 'data:' ) || str_starts_with( $ref, '#' ) ) {
				continue;
			}
			if ( str_starts_with( $ref, 'mailto:' ) || str_starts_with( $ref, 'tel:' ) || str_starts_with( $ref, 'javascript:' ) ) {
				continue;
			}

			if ( $this->is_font_stylesheet( $ref ) ) {
				$sheet = $this->preserve_google_font_url( $ref );
				if ( $sheet === '' ) {
					continue;
				}
				$fonts[] = array(
					'family'     => $this->family_from_google_url( $sheet ),
					'weights'    => array(),
					'stylesheet' => $sheet,
					'files'      => array(),
				);
				continue;
			}

			if ( preg_match( '#^https?://#i', $ref ) ) {
				$ext = strtolower( (string) pathinfo( (string) wp_parse_url( $ref, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
				if ( in_array( $ext, self::BINARY_EXTS, true ) || $this->looks_like_media_url( $ref ) ) {
					if ( isset( $seen[ $ref ] ) ) {
						$rewrites[ $ref ] = $seen[ $ref ];
						continue;
					}
					if ( count( $assets ) > 120 ) {
						continue;
					}
					$ingested = Sideloader::from_url( $ref, basename( (string) wp_parse_url( $ref, PHP_URL_PATH ) ), $ref );
					if ( is_wp_error( $ingested ) ) {
						continue;
					}
					$seen[ $ref ]     = $ingested['url'];
					$rewrites[ $ref ] = $ingested['url'];
					$assets[]         = $ingested;
					continue;
				}
				$links[] = array(
					'url'   => esc_url_raw( $ref ),
					'label' => '',
				);
				continue;
			}

			$resolved = $this->resolve_local( $ref, $index );
			if ( $resolved !== '' && is_readable( $resolved ) && $this->is_asset_file( $resolved ) ) {
				$key = $resolved; // Exact, as in from_zip(): paths are case-sensitive on Linux.
				if ( isset( $seen[ $key ] ) ) {
					$rewrites[ $ref ] = $seen[ $key ];
					continue;
				}
				$ingested = Sideloader::from_path( $resolved, basename( $resolved ), $ref );
				if ( is_wp_error( $ingested ) ) {
					continue;
				}
				$seen[ $key ]     = $ingested['url'];
				$rewrites[ $ref ] = $ingested['url'];
				$assets[]         = $ingested;
			}
		}

		foreach ( $refs['font_faces'] as $face ) {
			$fonts[] = $face;
		}

		return array(
			'rewrites' => $rewrites,
			'assets'   => $assets,
			'fonts'    => $this->unique_fonts( $fonts ),
			'links'    => $this->unique_links( $links ),
		);
	}

	/**
	 * @return array{urls:array<int,string>, font_faces:array<int, array<string, mixed>>}
	 */
	private function extract_refs( string $blob ): array {
		$urls  = array();
		$faces = array();

		if ( preg_match_all( '/url\(\s*[\'"]?([^\'"\)]+)[\'"]?\s*\)/i', $blob, $m ) ) {
			$urls = array_merge( $urls, $m[1] );
		}
		if ( preg_match_all( '/(?:src|href|poster|srcset)\s*=\s*[\'"]([^\'"]+)[\'"]/i', $blob, $m ) ) {
			foreach ( $m[1] as $value ) {
				foreach ( preg_split( '/\s*,\s*/', $value ) ?: array() as $part ) {
					$urls[] = trim( (string) preg_replace( '/\s+\d+[wx]$/', '', $part ) );
				}
			}
		}
		if ( preg_match_all( '/from\s+[\'"]([^\'"]+\.(?:png|jpe?g|webp|gif|svg|avif|mp4|webm|woff2?|ttf|otf))[\'"]/i', $blob, $m ) ) {
			$urls = array_merge( $urls, $m[1] );
		}
		if ( preg_match_all( '/(?:src|href|url)\s*[:=]\s*[\'"]([^\'"]+)[\'"]/i', $blob, $m ) ) {
			$urls = array_merge( $urls, $m[1] );
		}

		if ( preg_match_all( '/@font-face\s*\{(.*?)\}/is', $blob, $blocks ) ) {
			foreach ( $blocks[1] as $block ) {
				$family = '';
				if ( preg_match( '/font-family\s*:\s*[\'"]?([^\'";]+)/i', $block, $fm ) ) {
					$family = trim( $fm[1] );
				}
				$files = array();
				if ( preg_match_all( '/url\(\s*[\'"]?([^\'"\)]+)[\'"]?\s*\)/i', $block, $um ) ) {
					foreach ( $um[1] as $u ) {
						$files[] = array( 'url' => $u );
					}
				}
				if ( $family !== '' ) {
					$faces[] = array(
						'family'     => $family,
						'weights'    => array(),
						'stylesheet' => '',
						'files'      => $files,
					);
				}
			}
		}

		return array(
			'urls'       => array_values( array_unique( $urls ) ),
			'font_faces' => $faces,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $assets
	 * @param array<int, array<string, mixed>> $fonts
	 * @param array<int, array<string, string>> $links
	 * @return array<string, mixed>
	 */
	private function assemble( array $assets, array $fonts, array $links, string $css_source ): array {
		$images = array();
		$videos = array();
		$audio  = array();
		$other  = array();
		$font_files = array();

		foreach ( $assets as $asset ) {
			$kind = (string) ( $asset['kind'] ?? 'other' );
			if ( 'image' === $kind ) {
				$images[] = $asset;
			} elseif ( 'video' === $kind ) {
				$videos[] = $asset;
			} elseif ( 'audio' === $kind ) {
				$audio[] = $asset;
			} elseif ( 'font' === $kind ) {
				$font_files[] = $asset;
			} else {
				$other[] = $asset;
			}
		}

		$fonts = $this->attach_font_files( $fonts, $font_files );
		$css   = $this->font_css( $fonts );
		$tokens = $this->extract_tokens( $css_source );

		return array(
			'assets'  => $assets,
			'images'  => $images,
			'videos'  => $videos,
			'audio'   => $audio,
			'fonts'   => $fonts,
			'links'   => $this->unique_links( $links ),
			'tokens'  => $tokens,
			'css'     => $css,
			'summary' => array(
				'images' => count( $images ),
				'videos' => count( $videos ),
				'audio'  => count( $audio ),
				'fonts'  => count( $fonts ),
				'links'  => count( $this->unique_links( $links ) ),
				'other'  => count( $other ),
			),
		);
	}

	/**
	 * @param array<string, mixed> $node
	 * @param array<string, array<int, int|string>> $fonts
	 * @param array<int, array<string, string>> $links
	 * @param array<string, array<int, string>> $tokens
	 */
	private function walk_figma( array $node, array &$fonts, array &$links, array &$tokens ): void {
		$style = is_array( $node['style'] ?? null ) ? $node['style'] : array();
		$family = (string) ( $style['fontFamily'] ?? '' );
		if ( $family !== '' ) {
			$weight = (int) ( $style['fontWeight'] ?? 400 );
			$fonts[ $family ]   = $fonts[ $family ] ?? array();
			$fonts[ $family ][] = $weight ?: 400;
			$tokens['fonts'][]  = $family;
		}

		if ( isset( $node['hyperlink']['url'] ) ) {
			$url = esc_url_raw( (string) $node['hyperlink']['url'] );
			if ( $url !== '' ) {
				$links[] = array(
					'url'   => $url,
					'label' => sanitize_text_field( (string) ( $node['characters'] ?? $node['name'] ?? '' ) ),
				);
			}
		}

		if ( isset( $node['fills'] ) && is_array( $node['fills'] ) ) {
			foreach ( $node['fills'] as $fill ) {
				if ( is_array( $fill ) && isset( $fill['color'] ) && is_array( $fill['color'] ) ) {
					$tokens['colors'][] = $this->rgba( $fill['color'], $fill['opacity'] ?? 1 );
				}
			}
		}

		if ( isset( $node['effects'] ) && is_array( $node['effects'] ) ) {
			foreach ( $node['effects'] as $effect ) {
				if ( ! is_array( $effect ) ) {
					continue;
				}
				$type = (string) ( $effect['type'] ?? '' );
				if ( in_array( $type, array( 'DROP_SHADOW', 'INNER_SHADOW' ), true ) ) {
					$tokens['shadows'][] = $type;
				}
			}
		}

		if ( isset( $node['cornerRadius'] ) && is_numeric( $node['cornerRadius'] ) ) {
			$tokens['radii'][] = (string) $node['cornerRadius'];
		}

		foreach ( $node['children'] ?? array() as $child ) {
			if ( is_array( $child ) ) {
				$this->walk_figma( $child, $fonts, $links, $tokens );
			}
		}
	}

	/**
	 * @param array<string, mixed> $color
	 */
	private function rgba( array $color, mixed $opacity ): string {
		$r = (int) round( ( (float) ( $color['r'] ?? 0 ) ) * 255 );
		$g = (int) round( ( (float) ( $color['g'] ?? 0 ) ) * 255 );
		$b = (int) round( ( (float) ( $color['b'] ?? 0 ) ) * 255 );
		$a = (float) $opacity;
		if ( isset( $color['a'] ) ) {
			$a = (float) $color['a'];
		}
		return sprintf( 'rgba(%d,%d,%d,%s)', $r, $g, $b, rtrim( rtrim( sprintf( '%.3f', $a ), '0' ), '.' ) );
	}

	/**
	 * @param array<string, string> $rewrites
	 * @return array<string, string>
	 */
	private function alias_rewrites( array $rewrites, string $rel, string $url ): array {
		$norm = str_replace( '\\', '/', $rel );
		$base = basename( $norm );
		$rewrites[ $norm ]           = $url;
		$rewrites[ $base ]           = $url;
		$rewrites[ './' . $base ]    = $url;
		$rewrites[ '/' . $norm ]     = $url;
		$rewrites[ '../' . $base ]   = $url;

		/*
		 * The URL-encoded spelling. A file called `h2o away logo-white.png`
		 * is referenced from markup as `uploads/h2o%20away%20logo-white.png`,
		 * which matches none of the keys above and left the footer logo a 404
		 * rendered as its alt text — 272px of words where the design has a
		 * 149px image.
		 */
		$encoded = implode( '/', array_map( 'rawurlencode', explode( '/', $norm ) ) );
		if ( $encoded !== $norm ) {
			$rewrites[ $encoded ]                = $url;
			$rewrites[ '/' . $encoded ]          = $url;
			$rewrites[ rawurlencode( $base ) ]   = $url;
			$rewrites[ './' . rawurlencode( $base ) ] = $url;
		}

		/*
		 * The bundler alias, which is how source files actually spell these
		 * paths: `import hero from "@/assets/hero.png"`. Without it only the
		 * bare filename matched, so the substitution happened *inside* the
		 * specifier and left `"@/assets/http://host/uploads/hero.png"` — which
		 * resolves to nothing, so every such <img> was emitted with no src at
		 * all. One export spells its assets as `.asset.json` descriptors, and
		 * every raster image on that page came out empty.
		 */
		if ( str_starts_with( $norm, 'src/' ) ) {
			$tail = substr( $norm, 4 );
			$rewrites[ '@/' . $tail ] = $url;
			$rewrites[ '~/' . $tail ] = $url;
		}

		return $rewrites;
	}

	/**
	 * @param array<string, string> $rewrites
	 */
	private function apply_rewrites( string $content, array $rewrites ): string {
		if ( $content === '' || $rewrites === array() ) {
			return $content;
		}
		uksort(
			$rewrites,
			static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a )
		);
		/*
		 * One pass, not one str_replace per key. The map holds every spelling
		 * of a file — `assets/logo-white.png`, `logo-white.png`, `./logo-white.png`
		 * — and the uploaded URL ENDS in the same basename. Replacing key by
		 * key, the long form first turned `src="assets/logo-white.png"` into
		 * the URL, and the bare basename then matched inside that URL and
		 * prefixed it again: `…/uploads/2026/09/http://…/uploads/2026/09/logo-white.png`,
		 * a 404 that rendered every image on the Arcus page as its alt text.
		 * A single alternation, longest key first, replaces each occurrence
		 * once; the lookbehind keeps a basename from matching inside a path
		 * it did not start — `/x/logo-white.png` is not `logo-white.png`.
		 */
		$keys = array();
		foreach ( $rewrites as $from => $to ) {
			if ( $from === '' || $from === $to ) {
				continue;
			}
			$keys[] = preg_quote( (string) $from, '#' );
		}
		if ( $keys === array() ) {
			return $content;
		}
		$pattern = '#(?<![\w/])(?:' . implode( '|', $keys ) . ')#';
		$out     = preg_replace_callback(
			$pattern,
			static fn( array $m ): string => (string) ( $rewrites[ $m[0] ] ?? $m[0] ),
			$content
		);

		return is_string( $out ) ? $out : $content;
	}

	/**
	 * Whether a file the design names by a local path is one to upload: not
	 * one of the design's own pages or source files.
	 *
	 * Every local href/src/url() used to go to the media library, whatever it
	 * pointed at. For a person with unfiltered_html WordPress accepts .html
	 * (and Mime_Policy lets any JSON through), so a design's `about.html`
	 * link became a text/html attachment and the link was rewritten to that
	 * raw file in uploads — a page served unstyled from the site's own
	 * origin, and a link the import's own link rewrite could no longer map to
	 * the WordPress page. Media, fonts, Lottie JSON and documents (a linked
	 * PDF) still go; WordPress's own upload rules judge those.
	 */
	private function is_asset_file( string $path ): bool {
		$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( 'json' === $ext ) {
			return $this->is_lottie_file( $path );
		}
		if ( 'svg' === $ext ) {
			return true;
		}

		return ! in_array( $ext, array_merge( self::TEXT_EXTS, array( 'htm', 'xhtml', 'xml', 'map', 'webmanifest', 'php' ) ), true );
	}

	/**
	 * @param array<string, string> $index
	 */
	private function resolve_local( string $ref, array $index ): string {
		$clean = explode( '?', str_replace( '\\', '/', $ref ), 2 )[0];
		$clean = ltrim( $clean, './' );
		if ( isset( $index[ $clean ] ) ) {
			return $index[ $clean ];
		}
		$base = basename( $clean );
		return $index[ $base ] ?? '';
	}

	private function is_font_stylesheet( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( str_contains( $host, 'fonts.googleapis.com' ) ) {
			return str_contains( $path, '/css' );
		}
		if ( str_contains( $host, 'fonts.gstatic.com' ) ) {
			return false;
		}
		if ( str_contains( $host, 'use.typekit.net' )
			|| str_contains( $host, 'fonts.adobe.com' )
			|| str_contains( $host, 'fonts.bunny.net' ) ) {
			return true;
		}
		if ( str_ends_with( $path, '.css' ) && ( str_contains( $url, 'font' ) || str_contains( $url, 'typeface' ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Keep Google Fonts CSS2 weight axes (`wght@0,400;0,500`) intact.
	 * WordPress URL sanitizers historically truncate at `;`.
	 */
	private function preserve_google_font_url( string $url ): string {
		$url = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5 ) );
		if ( $url === '' || ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		if ( str_contains( $url, 'fonts.googleapis.com' ) ) {
			// Drop truncated leftovers such as `...family=X:ital` with no weights.
			if ( preg_match( '/family=[^&]*:(?:ital)?$/i', $url ) ) {
				return '';
			}
			return $url;
		}

		return esc_url_raw( $url );
	}

	private function looks_like_media_url( string $url ): bool {
		$path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		return (bool) preg_match( '/\.(png|jpe?g|webp|gif|svg|avif|mp4|webm|mov|woff2?|ttf|otf)(?:$|\?)/', $path );
	}

	private function family_from_google_url( string $url ): string {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		parse_str( $query, $params );
		$family = (string) ( $params['family'] ?? '' );
		$family = explode( ':', $family )[0];
		return str_replace( '+', ' ', $family );
	}

	/**
	 * Google Fonts stylesheet for a family harvested from CSS, or empty when
	 * the name is a system stack / CSS variable rather than a webfont.
	 *
	 * @param array<int, int> $weights
	 */
	public static function google_stylesheet( string $family, array $weights = array() ): string {
		$family = trim( $family, " \t\n\r\0\x0B\"'" );
		if ( $family === '' || str_starts_with( $family, 'var(' ) ) {
			return '';
		}
		$skip = array(
			'inherit', 'initial', 'unset', 'revert', 'serif', 'sans-serif', 'monospace',
			'cursive', 'fantasy', 'system-ui', 'ui-sans-serif', 'ui-serif', 'ui-monospace',
			'ui-rounded', 'emoji', 'math', 'fangsong', 'arial', 'helvetica', 'times',
			'times new roman', 'georgia', 'verdana', 'tahoma', 'courier', 'courier new',
			'menlo', 'monaco', 'consolas',
		);
		if ( in_array( strtolower( $family ), $skip, true ) ) {
			return '';
		}
		if ( 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9 \'-]{0,60}$/', $family ) ) {
			return '';
		}
		$weights = array_values( array_filter( array_map( 'intval', $weights ) ) );
		if ( $weights === array() ) {
			$weights = array( 400, 500, 600, 700, 800 );
		}

		return 'https://fonts.googleapis.com/css2?family=' . str_replace( ' ', '+', $family ) . ':wght@' . implode( ';', $weights ) . '&display=swap';
	}

	/**
	 * @param array<int, int> $weights
	 */
	private function google_font_url( string $family, array $weights ): string {
		return self::google_stylesheet( $family, $weights );
	}

	/**
	 * @param array<int, array<string, mixed>> $fonts
	 * @param array<int, array<string, mixed>> $files
	 * @return array<int, array<string, mixed>>
	 */
	private function attach_font_files( array $fonts, array $files ): array {
		foreach ( $files as $file ) {
			$fonts[] = array(
				'family'     => pathinfo( (string) ( $file['filename'] ?? 'custom' ), PATHINFO_FILENAME ),
				'weights'    => array(),
				'stylesheet' => '',
				'files'      => array(
					array(
						'url'    => (string) ( $file['url'] ?? '' ),
						'format' => $this->font_format( (string) ( $file['filename'] ?? '' ) ),
					),
				),
			);
		}

		return $this->unique_fonts( $fonts );
	}

	/**
	 * @param array<int, array<string, mixed>> $fonts
	 */
	private function font_css( array $fonts ): string {
		$out = '';
		foreach ( $fonts as $font ) {
			$sheet = (string) ( $font['stylesheet'] ?? '' );
			if ( $sheet !== '' ) {
				$out .= '@import url("' . str_replace( array( '"', ';' ), array( '%22', '%3B' ), $sheet ) . '");' . "\n";
			}
			foreach ( $font['files'] ?? array() as $file ) {
				if ( empty( $file['url'] ) ) {
					continue;
				}
				$family = (string) ( $font['family'] ?? 'DXAI Font' );
				$format = (string) ( $file['format'] ?? 'woff2' );
				$out   .= "@font-face { font-family: '{$family}'; src: url('{$file['url']}') format('{$format}'); font-display: swap; }\n";
			}
		}

		return $out;
	}

	private function font_format( string $filename ): string {
		$ext = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		return match ( $ext ) {
			'woff' => 'woff',
			'ttf'  => 'truetype',
			'otf'  => 'opentype',
			'eot'  => 'embedded-opentype',
			default => 'woff2',
		};
	}

	/**
	 * @param array<int, array<string, mixed>> $fonts
	 * @return array<int, array<string, mixed>>
	 */
	private function unique_fonts( array $fonts ): array {
		$out = array();
		foreach ( $fonts as $font ) {
			$key = strtolower( (string) ( $font['family'] ?? '' ) . '|' . (string) ( $font['stylesheet'] ?? '' ) );
			if ( $key === '|' ) {
				continue;
			}
			$out[ $key ] = $font;
		}

		return array_values( $out );
	}

	/**
	 * @param array<int, array<string, string>> $links
	 * @return array<int, array<string, string>>
	 */
	private function unique_links( array $links ): array {
		$out = array();
		foreach ( $links as $link ) {
			$url = (string) ( $link['url'] ?? '' );
			if ( $url === '' ) {
				continue;
			}
			$out[ $url ] = array(
				'url'   => $url,
				'label' => sanitize_text_field( (string) ( $link['label'] ?? '' ) ),
			);
		}

		return array_values( $out );
	}

	/**
	 * @return array{colors:array<int,string>, shadows:array<int,string>, radii:array<int,string>, fonts:array<int,string>}
	 */
	private function extract_tokens( string $css ): array {
		$colors = array();
		if ( preg_match_all( '/#(?:[0-9a-fA-F]{3,8})\b/', $css, $m ) ) {
			$colors = $m[0];
		}
		if ( preg_match_all( '/rgba?\([^)]+\)/', $css, $m ) ) {
			$colors = array_merge( $colors, $m[0] );
		}
		if ( preg_match_all( '/oklch\([^)]+\)/', $css, $m ) ) {
			$colors = array_merge( $colors, $m[0] );
		}

		$fonts = array();
		if ( preg_match_all( '/font-family\s*:\s*([^;]+);/i', $css, $m ) ) {
			foreach ( $m[1] as $stack ) {
				$first = trim( explode( ',', $stack )[0], " \t\n\r\0\x0B\"'" );
				if ( $first !== '' && ! in_array( strtolower( $first ), array( 'inherit', 'sans-serif', 'serif', 'monospace' ), true ) ) {
					$fonts[] = $first;
				}
			}
		}

		$vars = array();
		if ( preg_match_all( '/--([a-zA-Z0-9-]+)\s*:\s*([^;]+);/', $css, $m, PREG_SET_ORDER ) ) {
			foreach ( array_slice( $m, 0, 80 ) as $row ) {
				$vars[] = '--' . $row[1] . ': ' . trim( $row[2] );
			}
		}

		return array(
			'colors'     => array_values( array_unique( array_slice( $colors, 0, 40 ) ) ),
			'shadows'    => $vars,
			'variables'  => array_values( array_unique( array_slice( $vars, 0, 80 ) ) ),
			'radii'      => array(),
			'fonts'      => array_values( array_unique( array_slice( $fonts, 0, 20 ) ) ),
		);
	}

	private function is_lottie_file( string $path ): bool {
		$head = Zip_Extractor::read_text( $path, 4000 );
		return str_contains( $head, '"layers"' ) && ( str_contains( $head, '"v"' ) || str_contains( $head, '"nm"' ) );
	}
}

<?php
/**
 * Lovable ZIP / pasted code connector.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Connectors;

use DXAI_UI\Compiler\Design_Css;
use DXAI_UI\Compiler\Tsx_Section_Splitter;
use DXAI_UI\Media\Asset_Harvester;

final class Lovable_Connector {

	/**
	 * @param array<string, mixed> $file
	 */
	public function import_zip( array $file ): Source_Document|\WP_Error {
		$unpacked = Zip_Extractor::unpack( $file );
		if ( is_wp_error( $unpacked ) ) {
			return $unpacked;
		}

		$harvest    = ( new Asset_Harvester() )->from_zip( $unpacked['dir'], $unpacked['files'] );
		$texts      = is_array( $harvest['texts'] ?? null ) ? $harvest['texts'] : array();
		$components = array();
		$styles     = array();
		$assets     = is_array( $harvest['assets'] ?? null ) ? $harvest['assets'] : array();
		$summary    = is_array( $harvest['summary'] ?? null ) ? $harvest['summary'] : array();
		$tailwind   = '';

		foreach ( $unpacked['files'] as $relative ) {
			$norm = str_replace( '\\', '/', $relative );
			$ext  = strtolower( (string) pathinfo( $norm, PATHINFO_EXTENSION ) );
			$body = (string) ( $texts[ $norm ] ?? '' );

			/*
			 * A Tailwind v3 config, which is where the classic Lovable template
			 * (`vite_react_shadcn_ts`) keeps every design token: `bg-primary`
			 * is `hsl(var(--primary))` because this file says so, and the CSS
			 * declares only the raw HSL channels. It is not a component, so it
			 * stays out of `sources` — but dropping it entirely, which is what
			 * `is_junk_path()` still does for every other consumer, left the
			 * engine with no colours, no radius scale, no fonts and no
			 * keyframes on such a project.
			 */
			/*
			 * The harvester's read is authoritative (Asset_Harvester, 8 MB per
			 * text file); these fallbacks only run for a file it skipped, and
			 * they carry the same limit so no path cuts a source short. A
			 * 400 KB cut dropped every rule after Integration Hub's tokens —
			 * the page had the design's colours and none of its layout,
			 * 20671px of error over 245 boxes.
			 */
			if ( $tailwind === '' && preg_match( '#(?:^|/)tailwind\.config\.(?:ts|js|cjs|mjs)$#i', $norm ) === 1 ) {
				$tailwind = $body !== '' ? $body : Zip_Extractor::read_text( $unpacked['dir'] . '/' . $relative, 8000000 );
			}

			if ( in_array( $ext, array( 'tsx', 'jsx', 'ts', 'js' ), true ) && $this->is_source_path( $norm ) ) {
				$components[ $norm ] = $body !== '' ? $body : Zip_Extractor::read_text( $unpacked['dir'] . '/' . $relative, 8000000 );
			}

			if ( in_array( $ext, array( 'css', 'scss' ), true ) && ! $this->is_junk_path( $norm ) ) {
				$styles[ $norm ] = $body !== '' ? $body : Zip_Extractor::read_text( $unpacked['dir'] . '/' . $relative, 8000000 );
			}
		}

		Zip_Extractor::cleanup( $unpacked['dir'] );

		if ( $components === array() && $styles === array() ) {
			return new \WP_Error( 'dxai_ui_lovable', __( 'No React/Tailwind source files were found in the ZIP.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		$raw_css    = implode( "\n", $styles );
		$design_css = Design_Css::prepare( $raw_css );
		$harvest    = $this->with_design_css( $harvest, $design_css );
		$pages      = $this->build_pages( $components );
		$chunks     = $this->flatten_chunks( $pages );
		$prompt_components = $this->prompt_components( $components, $pages );
		$zip_title  = sanitize_text_field( preg_replace( '/\.zip$/i', '', (string) ( $file['name'] ?? '' ) ) ?? '' );
		$doc_title  = $this->title_from_pages( $pages );
		if ( in_array( $doc_title, array( 'Home', 'Lovable project', 'Lovable snippet' ), true ) && $zip_title !== '' ) {
			$doc_title = $zip_title;
			if ( isset( $pages[0] ) && is_array( $pages[0] ) ) {
				$pages[0]['title'] = $doc_title;
			}
		}

		$logs = array(
			'Unpacked Lovable archive with ' . count( $unpacked['files'] ) . ' files',
			sprintf(
				'Harvested assets: %d images, %d videos, %d fonts, %d links',
				(int) ( $summary['images'] ?? 0 ),
				(int) ( $summary['videos'] ?? 0 ),
				(int) ( $summary['fonts'] ?? 0 ),
				(int) ( $summary['links'] ?? 0 )
			),
			sprintf(
				'Prepared %d page(s) and %d generation chunk(s); skipped UI-kit files.',
				count( $pages ),
				count( $chunks )
			),
		);

		return new Source_Document(
			'lovable-zip',
			$doc_title,
			array(
				'components' => $prompt_components,
				// `components` is capped for the LLM prompt; the local compiler
				// has no token budget and needs every file a page imports, or
				// header and footer components resolve to nothing.
				'sources'    => $components,
				'styles'     => $this->limit_map( $styles, 6 ),
				'pages'      => $this->client_pages( $pages ),
				'chunks'     => $chunks,
				'design_css' => $design_css,
				'design_css_raw' => $raw_css,
				'tailwind_config' => $tailwind,
				'assets'     => $assets,
				'harvest'    => Asset_Harvester::for_prompt( $harvest ),
				/*
				 * The archive this came from, so every page the import writes
				 * can record it.
				 *
				 * bin/verify-import.cjs pairs a page with its design through
				 * `_dxai_ui_source_zip`, and only bin/purge-and-import-real-zips.php
				 * ever wrote that meta — so a page imported through wp-admin
				 * could never be measured against its own design, and the
				 * corpus run reported eight of them as "no recorded source
				 * ZIP". The name has to travel with the payload, because save()
				 * sees the compile result and nothing else.
				 *
				 * Deliberately NOT sanitize_file_name(): that collapses
				 * whitespace to dashes, so `DevriX Elevate.zip` would be stored
				 * as `DevriX-Elevate.zip` and never match again. The verifier
				 * compares `path.basename(zip) === page.source` byte for byte.
				 * basename() is what defeats a traversal attempt, and the value
				 * is only ever string-compared — it never reaches the
				 * filesystem.
				 */
				'source_name' => wp_strip_all_tags( wp_basename( (string) ( $file['name'] ?? '' ) ) ),
			),
			$assets,
			$logs
		);
	}

	public function from_code( string $code, string $css = '', string $title = '' ): Source_Document|\WP_Error {
		$code = trim( $code );
		if ( $code === '' ) {
			return new \WP_Error( 'dxai_ui_lovable', __( 'Paste JSX/TSX source code first.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		$harvest = ( new Asset_Harvester() )->from_text( $code, $css );
		$texts   = is_array( $harvest['texts'] ?? null ) ? $harvest['texts'] : array();
		$assets  = is_array( $harvest['assets'] ?? null ) ? $harvest['assets'] : array();
		$summary = is_array( $harvest['summary'] ?? null ) ? $harvest['summary'] : array();
		$tsx     = (string) ( $texts['pasted.tsx'] ?? $code );
		$css_out = (string) ( $texts['pasted.css'] ?? $css );
		$design  = Design_Css::prepare( $css_out );
		$harvest = $this->with_design_css( $harvest, $design );

		$page = array(
			'slug'     => '/',
			'title'    => $title !== '' ? sanitize_text_field( $title ) : 'Lovable snippet',
			'file'     => 'pasted.tsx',
			'code'     => $tsx,
			'sections' => Tsx_Section_Splitter::sections( $tsx ),
		);
		$chunks = $this->flatten_chunks( array( $page ) );

		return new Source_Document(
			'lovable-code',
			$page['title'],
			array(
				'components' => array( 'pasted.tsx' => $tsx ),
				'styles'     => $css_out !== '' ? array( 'pasted.css' => $css_out ) : array(),
				'pages'      => $this->client_pages( array( $page ) ),
				'chunks'     => $chunks,
				'design_css' => $design,
				'design_css_raw' => $css_out,
				'assets'     => $assets,
				'harvest'    => Asset_Harvester::for_prompt( $harvest ),
			),
			$assets,
			array(
				'Loaded pasted Lovable source',
				sprintf(
					'Harvested assets: %d images, %d videos, %d fonts, %d links',
					(int) ( $summary['images'] ?? 0 ),
					(int) ( $summary['videos'] ?? 0 ),
					(int) ( $summary['fonts'] ?? 0 ),
					(int) ( $summary['links'] ?? 0 )
				),
			)
		);
	}

	public function from_api(): \WP_Error {
		return new \WP_Error(
			'dxai_ui_lovable_api',
			__( 'Lovable does not offer a public source API. Upload a ZIP or paste TSX instead.', 'dxai-ui' ),
			array( 'status' => 501 )
		);
	}

	/**
	 * @param array<string, string> $components
	 * @return array<int, array<string, mixed>>
	 */
	private function build_pages( array $components ): array {
		$pages = array();
		foreach ( $this->rank_page_files( $components ) as $path ) {
			$body = (string) $components[ $path ];
			if ( $body === '' ) {
				continue;
			}
			$pages[] = array(
				'slug'     => $this->slug_from_path( $path ),
				'title'    => $this->title_from_source( $path, $body ),
				'file'     => $path,
				'code'     => $body,
				'sections' => Tsx_Section_Splitter::sections( $body ),
			);
		}

		if ( $pages === array() ) {
			foreach ( $this->rank_fallback_files( $components ) as $path ) {
				$body = (string) $components[ $path ];
				if ( $body === '' ) {
					continue;
				}
				$pages[] = array(
					'slug'     => $this->slug_from_path( $path ),
					'title'    => $this->title_from_source( $path, $body ),
					'file'     => $path,
					'code'     => $body,
					'sections' => Tsx_Section_Splitter::sections( $body ),
				);
			}
		}

		return $pages;
	}

	/**
	 * @param array<int, array<string, mixed>> $pages
	 * @return array<int, array<string, mixed>>
	 */
	private function flatten_chunks( array $pages ): array {
		$chunks = array();
		foreach ( $pages as $page ) {
			$sections = is_array( $page['sections'] ?? null ) ? $page['sections'] : array();
			foreach ( Tsx_Section_Splitter::chunks( $sections ) as $chunk ) {
				$chunk['page'] = array(
					'slug'  => (string) ( $page['slug'] ?? '/' ),
					'title' => (string) ( $page['title'] ?? 'Home' ),
					'file'  => (string) ( $page['file'] ?? '' ),
				);
				$chunks[] = $chunk;
			}
		}

		return $chunks;
	}

	/**
	 * @param array<int, array<string, mixed>> $pages
	 * @return array<int, array<string, mixed>>
	 */
	private function client_pages( array $pages ): array {
		$out = array();
		foreach ( $pages as $page ) {
			$sections = array();
			foreach ( is_array( $page['sections'] ?? null ) ? $page['sections'] : array() as $section ) {
				$sections[] = array(
					'id'    => (string) ( $section['id'] ?? '' ),
					'title' => (string) ( $section['title'] ?? '' ),
					'type'  => (string) ( $section['type'] ?? 'section' ),
					'chars' => strlen( (string) ( $section['code'] ?? '' ) ),
				);
			}
			$out[] = array(
				'slug'     => (string) ( $page['slug'] ?? '/' ),
				'title'    => (string) ( $page['title'] ?? '' ),
				'file'     => (string) ( $page['file'] ?? '' ),
				'code'     => (string) ( $page['code'] ?? '' ),
				'sections' => $sections,
			);
		}

		return $out;
	}

	/**
	 * @param array<string, string> $components
	 * @param array<int, array<string, mixed>> $pages
	 * @return array<string, string>
	 */
	private function prompt_components( array $components, array $pages ): array {
		$keep = array();
		foreach ( $pages as $page ) {
			$file = (string) ( $page['file'] ?? '' );
			if ( $file !== '' && isset( $components[ $file ] ) ) {
				$keep[ $file ] = $components[ $file ];
			}
		}

		if ( $keep === array() ) {
			// The kit is in `$components` now so the compiler can resolve the
			// page's imports; it must still never be what the prompt is shown.
			foreach ( $components as $path => $body ) {
				if ( ! $this->is_ui_kit( $path ) ) {
					$keep[ $path ] = $body;
				}
			}
			$keep = $this->limit_map( $keep, 4 );
		}

		return $this->limit_map( $keep, 8 );
	}

	/**
	 * @param array<string, string> $components
	 * @return array<int, string>
	 */
	private function rank_page_files( array $components ): array {
		$ranked = array();
		foreach ( array_keys( $components ) as $path ) {
			if ( $this->is_page_file( $path ) ) {
				$ranked[] = $path;
			}
		}

		/*
		 * A route can be a redirect and nothing else — Growth Story Hub's `/`
		 * is seven lines that throw `redirect({ to: "/about" })`, and the page
		 * itself is `routes/about/index.tsx`. Such a stub renders nothing, so
		 * it must never outrank a route that does, and where it names a target
		 * that target is the design's real entry point.
		 */
		$target = '';
		foreach ( $ranked as $path ) {
			$body = (string) ( $components[ $path ] ?? '' );
			if ( ! $this->renders_markup( $body )
				&& preg_match( '/redirect\(\s*\{[^}]*\bto:\s*["\']([^"\']+)["\']/', $body, $m ) ) {
				$target = trim( $m[1], '/' );
				break;
			}
		}

		$renders = array();
		$named   = array();
		foreach ( $ranked as $path ) {
			$renders[ $path ] = $this->renders_markup( (string) ( $components[ $path ] ?? '' ) );
			$norm             = str_replace( '\\', '/', strtolower( $path ) );
			$named[ $path ]   = $target !== ''
				&& (bool) preg_match( '#/' . preg_quote( $target, '#' ) . '(/index)?\.(tsx|jsx)$#', $norm );
		}

		usort(
			$ranked,
			static function ( string $a, string $b ) use ( $renders, $named ): int {
				return ( $renders[ $b ] <=> $renders[ $a ] )
					?: ( $named[ $b ] <=> $named[ $a ] )
					?: ( ( str_contains( strtolower( $a ), 'index' ) ? 0 : 1 ) <=> ( str_contains( strtolower( $b ), 'index' ) ? 0 : 1 ) )
					?: ( strlen( $a ) <=> strlen( $b ) );
			}
		);

		return $ranked;
	}

	/** Whether this module returns any markup, as opposed to being a stub. */
	private function renders_markup( string $body ): bool {
		return (bool) preg_match( '#<[A-Za-z][\w.:-]*[\s/>]#', $body );
	}

	/**
	 * @param array<string, string> $components
	 * @return array<int, string>
	 */
	private function rank_fallback_files( array $components ): array {
		$ranked = array();
		foreach ( $components as $path => $body ) {
			if ( $this->is_junk_path( $path ) || $this->is_ui_kit( $path ) ) {
				continue;
			}
			$ranked[ $path ] = strlen( $body );
		}
		arsort( $ranked );

		return array_slice( array_keys( $ranked ), 0, 6 );
	}

	private function is_page_file( string $path ): bool {
		$norm = str_replace( '\\', '/', strtolower( $path ) );
		if ( $this->is_junk_path( $norm ) || $this->is_ui_kit( $norm ) ) {
			return false;
		}
		if ( str_contains( $norm, 'routetree' ) || str_contains( $norm, '__root' ) ) {
			return false;
		}

		// File-based routers nest: `routes/about/index.tsx` is the /about page.
		// Matching only files directly under routes/ meant a design whose real
		// page lived one directory down was never a candidate at all, and the
		// import produced an empty page.
		return (bool) preg_match( '#/(routes|pages)/(.+/)?[^/]+\.(tsx|jsx)$#', $norm )
			|| (bool) preg_match( '#/(app|src)/(page|index)\.(tsx|jsx)$#', $norm );
	}

	/**
	 * Whether the local compiler needs this file.
	 *
	 * The shadcn/ui kit used to be excluded here, which read as reasonable —
	 * it is boilerplate, it is 44-46 files, and it is never the page. But it is
	 * what the page IMPORTS: `<Button>`, `<Card>`, `<Accordion>` are defined
	 * nowhere else, and with the definitions withheld the compiler rendered
	 * every one of them as a bare `<span>` with no classes, no `<button>` and
	 * no card. No design in the corpus used the kit, so no measurement ever
	 * said so; the first fixture that did lost three cards, an accordion and
	 * four buttons — 273px of page — against its own React build.
	 *
	 * The kit stays out of the LLM prompt and out of the page candidates, which
	 * is what `is_ui_kit()` is still for. This is the complete file map.
	 */
	private function is_source_path( string $path ): bool {
		if ( $this->is_junk_path( $path ) ) {
			return false;
		}

		return str_contains( $path, '/src/' )
			|| str_contains( $path, '/app/' )
			|| str_contains( $path, '/components/' )
			|| str_contains( $path, '/pages/' )
			|| str_contains( $path, '/routes/' )
			|| substr_count( $path, '/' ) < 3;
	}

	private function is_junk_path( string $path ): bool {
		$norm = str_replace( '\\', '/', strtolower( $path ) );
		$skip = array(
			'node_modules/',
			'dist/',
			'build/',
			'.git/',
			'/coverage/',
			'.next/',
			'routetree.gen',
			'.test.',
			'.spec.',
			'vite.config',
			'tailwind.config',
		);
		foreach ( $skip as $needle ) {
			if ( str_contains( $norm, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	private function is_ui_kit( string $path ): bool {
		$norm = str_replace( '\\', '/', strtolower( $path ) );
		return str_contains( $norm, '/components/ui/' ) || str_contains( $norm, '/ui/shadcn/' );
	}

	private function slug_from_path( string $path ): string {
		$base = strtolower( (string) pathinfo( $path, PATHINFO_FILENAME ) );
		if ( in_array( $base, array( 'index', 'page', 'home' ), true ) ) {
			return '/';
		}
		$slug = sanitize_title( $base );
		return $slug !== '' ? '/' . $slug : '/';
	}

	private function title_from_source( string $path, string $body ): string {
		if ( preg_match( '/head:\s*\(\)\s*=>\s*\(\{[\s\S]{0,800}?title:\s*[\'"]([^\'"]+)[\'"]/', $body, $m ) ) {
			return sanitize_text_field( $m[1] );
		}
		if ( preg_match( '/<title[^>]*>\s*([^<]+)/i', $body, $m ) ) {
			return sanitize_text_field( wp_strip_all_tags( $m[1] ) );
		}

		return $this->title_from_path( $path );
	}

	private function title_from_path( string $path ): string {
		$base = (string) pathinfo( $path, PATHINFO_FILENAME );
		if ( in_array( strtolower( $base ), array( 'index', 'page', 'home' ), true ) ) {
			return 'Home';
		}
		return ucwords( str_replace( array( '-', '_' ), ' ', $base ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $pages
	 */
	private function title_from_pages( array $pages ): string {
		if ( isset( $pages[0]['title'] ) && is_string( $pages[0]['title'] ) && $pages[0]['title'] !== '' ) {
			return $pages[0]['title'];
		}

		return 'Lovable project';
	}

	/**
	 * @param array<string, mixed> $harvest
	 * @return array<string, mixed>
	 */
	private function with_design_css( array $harvest, string $design_css ): array {
		$fonts = (string) ( $harvest['css'] ?? '' );
		$harvest['css'] = trim( $fonts . "\n" . $design_css );
		return $harvest;
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

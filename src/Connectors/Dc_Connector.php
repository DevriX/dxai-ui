<?php
/**
 * Claude Design (`<Name>.dc.html`) export connector.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Connectors;

use DXAI_UI\Media\Asset_Harvester;

/**
 * A Claude Design export is one HTML file plus its runtime: `<x-dc>` holds a
 * template with `{{ }}` bindings, `<sc-if>`/`<sc-for>` directives and inline
 * styles; `<helmet>` holds the head (title, meta, a Google Fonts link, one
 * `<style>`, JSON-LD); `<script type="text/x-dc" data-dc-script>` holds the
 * component logic — a class whose `renderVals()` computes everything the
 * template reads. `support.js` beside it is the browser runtime (React from
 * a CDN); images sit under `assets/` and `uploads/`.
 *
 * The connector splits the file into those parts and hands them to
 * Source_Compiler, which renders the template with Dc_Renderer. What is
 * different from the other connectors: there are no component files, no
 * Tailwind, no routes — one page, one stylesheet of a few rules, hundreds of
 * inline styles, and the runtime's own state machine to reproduce.
 */
final class Dc_Connector {

	/**
	 * Whether a ZIP's entry list is a Claude Design export.
	 *
	 * @param array<int, string> $entries
	 */
	public static function is_dc_zip( array $entries ): bool {
		foreach ( $entries as $entry ) {
			if ( preg_match( '/\.dc\.html$/i', (string) $entry ) === 1 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an HTML page is a Claude Design page whatever its file is called: its template (`<x-dc>`) and its component's script
	 * (`data-dc-script`). A handoff can name it index.html (Taylored Restoration); a template with no script of its own is not one.
	 */
	public static function is_dc_page( string $html ): bool {
		return str_contains( $html, '<x-dc' ) && str_contains( $html, 'data-dc-script' );
	}

	/** Whether an HTML source is a Claude Design template. */
	public static function is_dc_html( string $path, string $html ): bool {
		return str_ends_with( strtolower( $path ), '.dc.html' ) || str_contains( $html, '<x-dc' ) || str_contains( $html, 'data-dc-script' );
	}

	/**
	 * @param array<string, mixed> $file A `$_FILES`-shaped array, or `tmp_name` + `name` for a path.
	 */
	public function import_zip( array $file ): Source_Document|\WP_Error {
		$unpacked = Zip_Extractor::unpack( $file );
		if ( is_wp_error( $unpacked ) ) {
			return $unpacked;
		}

		$harvest = ( new Asset_Harvester() )->from_zip( $unpacked['dir'], $unpacked['files'] );
		$texts   = is_array( $harvest['texts'] ?? null ) ? $harvest['texts'] : array();

		// Every .dc.html: the pages, and the parts they import (Dc_Site). An export with none has its pages as .html files that
		// are Claude Design pages (is_dc_page(): a handoff's index.html); one with .dc.html files keeps to them, so its
		// "(standalone).html" bundle is not read as another page.
		$files = array();
		$html  = array();
		foreach ( $unpacked['files'] as $relative ) {
			$norm = str_replace( '\\', '/', $relative );
			$dc   = preg_match( '/\.dc\.html$/i', $norm ) === 1;
			if ( ! $dc && ( preg_match( '/\.html?$/i', $norm ) !== 1 || preg_match( '#(?:^|/)(?:node_modules|dist|build|\.next|\.git)/#i', $norm ) === 1 ) ) {
				continue;
			}
			$text = (string) ( $texts[ $norm ] ?? '' );
			if ( $text === '' ) {
				$text = Zip_Extractor::read_text( $unpacked['dir'] . '/' . $relative, 4000000 );
			}
			if ( $text === '' ) {
				continue;
			}
			if ( $dc ) {
				$files[ $norm ] = $text;
			} elseif ( self::is_dc_page( $text ) ) {
				$html[ $norm ] = $text;
			}
		}
		Zip_Extractor::cleanup( $unpacked['dir'] );
		if ( $files === array() ) {
			$files = $html;
		}

		if ( $files === array() ) {
			return new \WP_Error( 'dxai_ui_zip', __( 'No .dc.html page was found in the ZIP.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		$site  = Dc_Site::read( $files );
		$home  = $site['pages'][0];
		$parts = $home['parts'];
		$path  = $home['path'];
		$name  = $home['name'];
		$title = $parts['title'] !== '' ? $parts['title'] : $name;

		/*
		 * One stylesheet for the whole design, as the repository compiles every page of a design against it: the Home's
		 * helmet style, then each component's and each page's, each once.
		 */
		$styles = array( $parts['style'] );
		foreach ( $site['components'] as $component ) {
			$styles[] = (string) $component['style'];
		}
		foreach ( array_slice( $site['pages'], 1 ) as $page ) {
			$styles[] = (string) $page['parts']['style'];
		}
		$style = trim( implode( "\n", array_unique( array_filter( array_map( 'trim', $styles ), 'strlen' ) ) ) );

		$sources = array( $path => $files[ $path ] );
		foreach ( $files as $file_path => $text ) {
			$file_name = (string) preg_replace( '/(?:\.dc)?\.html?$/i', '', basename( $file_path ) );
			if ( isset( $site['components'][ $file_name ] ) || in_array( $file_name, array_column( $site['pages'], 'name' ), true ) ) {
				$sources[ $file_path ] = $text;
			}
		}

		$summary = is_array( $harvest['summary'] ?? null ) ? $harvest['summary'] : array();
		$assets  = is_array( $harvest['assets'] ?? null ) ? $harvest['assets'] : array();

		return new Source_Document(
			'dc-zip',
			$title,
			array(
				'stack'          => 'claude-design',
				'dc'             => self::dc_row( $name, $path, $parts ),
				// The parts the pages import (`<dc-import name="…">`), by that name: the header, the footer…
				'dc_components'  => array_map(
					static fn( array $c ): array => array(
						'template' => (string) $c['template'],
						'script'   => (string) $c['script'],
						'props'    => is_array( $c['props'] ) ? $c['props'] : array(),
					),
					$site['components']
				),
				// The design's other pages, each with the route its links were written to.
				'dc_pages'       => array_map(
					static fn( array $page ): array => self::dc_row( $page['name'], $page['path'], $page['parts'] ) + array(
						'route'      => $page['route'],
						'page_title' => $page['title'],
					),
					array_slice( $site['pages'], 1 )
				),
				'sources'        => $sources,
				'assets'         => $assets,
				'harvest'        => Asset_Harvester::for_prompt( $harvest ),
				// The helmets' <style>, verbatim: they are the design's whole stylesheet.
				'design_css_raw' => $style,
				'static_html'    => true,
				// See Lovable_Connector: the archive name is what pairs a saved
				// page with the design it has to be measured against, byte for
				// byte — which is why it is not sanitize_file_name()'d.
				'source_name'    => wp_strip_all_tags( wp_basename( (string) ( $file['name'] ?? '' ) ) ),
			),
			$assets,
			array(
				'Unpacked Claude Design export (' . count( $unpacked['files'] ) . ' files)',
				'Page: ' . $path,
				count( $site['pages'] ) > 1 ? sprintf( 'Pages: %d (%s)', count( $site['pages'] ), implode( ', ', array_column( $site['pages'], 'name' ) ) ) : 'Pages: 1',
				$site['components'] !== array() ? 'Imported parts: ' . implode( ', ', array_keys( $site['components'] ) ) : 'Imported parts: none',
				$site['skipped'] !== array() ? 'Left out as copies of other pages: ' . implode( ', ', $site['skipped'] ) : 'Left out: none',
				is_array( $summary ) && $summary !== array() ? 'Assets: ' . wp_json_encode( $summary ) : 'Assets: none',
			)
		);
	}

	/**
	 * A page's parts as the compiler reads them.
	 *
	 * @param array<string, mixed> $parts Dc_Connector::split().
	 * @return array<string, mixed>
	 */
	private static function dc_row( string $name, string $path, array $parts ): array {
		return array(
			'name'     => $name,
			'path'     => $path,
			'template' => $parts['template'],
			'script'   => $parts['script'],
			'props'    => $parts['props'],
			'title'    => $parts['title'],
			'style'    => $parts['style'],
			'links'    => $parts['links'],
			'meta'     => $parts['meta'],
			'ld_json'  => $parts['ld_json'],
		);
	}

	/**
	 * The parts of a .dc.html document.
	 *
	 * @return array{template: string, script: string, props: array<string, mixed>, title: string, style: string, links: array<int, string>, meta: array<int, string>, ld_json: array<int, string>}
	 */
	public static function split( string $html ): array {
		$out = array(
			'template' => '',
			'script'   => '',
			'props'    => array(),
			'title'    => '',
			'style'    => '',
			'links'    => array(),
			'meta'     => array(),
			'ld_json'  => array(),
		);

		// Template: between `<x-dc …>` and the LAST `</x-dc>`, as the runtime reads it.
		$open  = stripos( $html, '<x-dc' );
		$close = strripos( $html, '</x-dc>' );
		if ( $open !== false && $close !== false && $close > $open ) {
			$start = strpos( $html, '>', $open );
			if ( $start !== false ) {
				$out['template'] = substr( $html, $start + 1, $close - $start - 1 );
			}
		} elseif ( preg_match( '/<body[^>]*>([\s\S]*?)<\/body>/i', $html, $bm ) === 1 ) {
			$out['template'] = $bm[1];
		}

		if ( preg_match( '/<helmet\b[^>]*>([\s\S]*?)<\/helmet>/i', $out['template'], $hm ) === 1 ) {
			$helmet = $hm[1];
			if ( preg_match( '/<title>([\s\S]*?)<\/title>/i', $helmet, $tm ) === 1 ) {
				$out['title'] = trim( html_entity_decode( wp_strip_all_tags( $tm[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			}
			if ( preg_match_all( '/<style\b[^>]*>([\s\S]*?)<\/style>/i', $helmet, $sm ) ) {
				$out['style'] = trim( implode( "\n", $sm[1] ) );
			}
			if ( preg_match_all( '/<link\b[^>]*>/i', $helmet, $lm ) ) {
				$out['links'] = $lm[0];
			}
			if ( preg_match_all( '/<meta\b[^>]*>/i', $helmet, $mm ) ) {
				$out['meta'] = $mm[0];
			}
			if ( preg_match_all( '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>([\s\S]*?)<\/script>/i', $helmet, $jm ) ) {
				$out['ld_json'] = array_map( 'trim', $jm[1] );
			}
		}

		if ( preg_match( '/<script[^>]*data-dc-script([^>]*)>([\s\S]*?)<\/script>/i', $html, $m ) === 1 ) {
			$out['script'] = html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( preg_match( '/data-props=("|\')(.*?)\1/s', $m[1], $pm ) === 1 ) {
				$decoded = json_decode( html_entity_decode( $pm[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
				if ( is_array( $decoded ) ) {
					$out['props'] = $decoded;
				}
			}
		}

		return $out;
	}
}

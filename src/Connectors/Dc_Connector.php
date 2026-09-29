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

		$path = '';
		$html = '';
		foreach ( $unpacked['files'] as $relative ) {
			$norm = str_replace( '\\', '/', $relative );
			if ( preg_match( '/\.dc\.html$/i', $norm ) !== 1 ) {
				continue;
			}
			// The shallowest .dc.html is the page; helper HTML lives deeper.
			if ( $path === '' || substr_count( $norm, '/' ) < substr_count( $path, '/' ) ) {
				$path = $norm;
				$html = (string) ( $texts[ $norm ] ?? '' );
				if ( $html === '' ) {
					$html = Zip_Extractor::read_text( $unpacked['dir'] . '/' . $relative, 4000000 );
				}
			}
		}
		Zip_Extractor::cleanup( $unpacked['dir'] );

		if ( $html === '' ) {
			return new \WP_Error( 'dxai_ui_zip', __( 'No .dc.html page was found in the ZIP.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		$parts = self::split( $html );
		$name  = preg_replace( '/\.dc\.html$/i', '', basename( $path ) ) ?? basename( $path );
		$title = $parts['title'] !== '' ? $parts['title'] : $name;

		$summary = is_array( $harvest['summary'] ?? null ) ? $harvest['summary'] : array();
		$assets  = is_array( $harvest['assets'] ?? null ) ? $harvest['assets'] : array();

		return new Source_Document(
			'dc-zip',
			$title,
			array(
				'stack'          => 'claude-design',
				'dc'             => array(
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
				),
				'sources'        => array( $path => $html ),
				'assets'         => $assets,
				'harvest'        => Asset_Harvester::for_prompt( $harvest ),
				// The helmet's <style>, verbatim: it is the design's whole stylesheet.
				'design_css_raw' => $parts['style'],
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
				is_array( $summary ) && $summary !== array() ? 'Assets: ' . wp_json_encode( $summary ) : 'Assets: none',
			)
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

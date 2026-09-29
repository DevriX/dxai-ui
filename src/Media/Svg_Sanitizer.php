<?php
/**
 * Strip executable bits from SVG before storing (DOM allowlist).
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Media;

final class Svg_Sanitizer {

	/**
	 * @var array<int, string>
	 */
	private const ALLOWED_TAGS = array(
		'svg',
		'g',
		'path',
		'rect',
		'circle',
		'ellipse',
		'line',
		'polyline',
		'polygon',
		'text',
		'tspan',
		'defs',
		'clippath',
		'mask',
		'lineargradient',
		'radialgradient',
		'stop',
		'use',
		'symbol',
		'title',
		'desc',
		'metadata',
		'style',
		'image',
		'pattern',
		'filter',
		'fegaussianblur',
		'feoffset',
		'feblend',
		'fecolormatrix',
		'femerge',
		'femergenode',
		'fecomposite',
		'feflood',
		'marker',
	);

	/**
	 * @var array<int, string>
	 */
	private const ALLOWED_ATTRS = array(
		'id',
		'class',
		'viewbox',
		'xmlns',
		'xmlns:xlink',
		'width',
		'height',
		'x',
		'y',
		'x1',
		'y1',
		'x2',
		'y2',
		'cx',
		'cy',
		'r',
		'rx',
		'ry',
		'd',
		'fill',
		'stroke',
		'stroke-width',
		'stroke-linecap',
		'stroke-linejoin',
		'stroke-dasharray',
		'stroke-opacity',
		'fill-opacity',
		'fill-rule',
		'clip-rule',
		'opacity',
		'transform',
		'gradientunits',
		'gradienttransform',
		'offset',
		'stop-color',
		'stop-opacity',
		'spreadmethod',
		'href',
		'xlink:href',
		'preserveaspectratio',
		'points',
		'font-size',
		'font-family',
		'font-weight',
		'text-anchor',
		'dx',
		'dy',
		'clip-path',
		'mask',
		'filter',
		'style',
		'role',
		'aria-hidden',
		'aria-label',
		'focusable',
		'overflow',
		'stddeviation',
		'in',
		'in2',
		'result',
		'mode',
		'type',
		'values',
		'flood-color',
		'flood-opacity',
		'operator',
		'k1',
		'k2',
		'k3',
		'k4',
	);

	public static function clean( string $svg ): string {
		$svg = self::without_doctype( trim( $svg ) );
		/*
		 * An entity declaration that survived the DOCTYPE strip is refused
		 * outright. This file used to parse with LIBXML_NOENT, which SUBSTITUTES
		 * external entities: `<!ENTITY x SYSTEM "file:///…/wp-config.php">` and
		 * a `&x;` in a <text> put the file's contents into the SVG — and the
		 * SVG is then published to the media library. LIBXML_NONET only stops
		 * network fetches; file:// and php://filter still resolved. Two ways in:
		 * an SVG inside an uploaded ZIP, and an image on the live site the
		 * site-from-menu crawl fetches, whose host the design's links choose.
		 */
		if ( $svg === '' || preg_match( '/<!ENTITY|<!DOCTYPE/i', $svg ) ) {
			return '';
		}
		if ( ! class_exists( \DOMDocument::class ) ) {
			return self::regex_fallback( $svg );
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument();
		// No LIBXML_NOENT: entities are never expanded. HTML named entities
		// (&nbsp;, &copy; …) are not XML, so they are spelled numerically
		// first, or a hand-written icon would fail to parse.
		$wrapped = '<?xml version="1.0" encoding="UTF-8"?>' . self::numeric_entities( $svg );
		$ok      = $dom->loadXML( $wrapped, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		// Markup that does not parse cannot be scrubbed node by node, and the
		// regex fallback is no sanitizer for it. Refused.
		if ( ! $ok || ! $dom->documentElement ) {
			return '';
		}

		self::scrub_node( $dom->documentElement );

		$body = $dom->saveXML( $dom->documentElement );
		return is_string( $body ) ? $body : self::regex_fallback( $svg );
	}

	public static function clean_file( string $path ): bool {
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $raw ) ) {
			return false;
		}

		$clean = self::clean( $raw );
		// A refused SVG is emptied on disk and reported, so no caller can go
		// on to publish the unsafe original.
		$written = file_put_contents( $path, $clean ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return false !== $written && trim( $clean ) !== '';
	}

	private static function scrub_node( \DOMNode $node ): void {
		if ( $node instanceof \DOMElement ) {
			$tag = strtolower( $node->tagName );
			if ( ! in_array( $tag, self::ALLOWED_TAGS, true ) ) {
				$parent = $node->parentNode;
				if ( $parent ) {
					$parent->removeChild( $node );
				}
				return;
			}

			if ( $node->hasAttributes() ) {
				$remove = array();
				foreach ( iterator_to_array( $node->attributes ) as $attr ) {
					if ( ! $attr instanceof \DOMAttr ) {
						continue;
					}
					$name = strtolower( $attr->name );
					if ( str_starts_with( $name, 'on' ) || ! in_array( $name, self::ALLOWED_ATTRS, true ) ) {
						$remove[] = $attr->name;
						continue;
					}
					$value = $attr->value;
					if ( preg_match( '/javascript\s*:/i', $value ) || str_contains( strtolower( $value ), 'data:' ) ) {
						$remove[] = $attr->name;
					}
				}
				foreach ( $remove as $name ) {
					$node->removeAttribute( $name );
				}
			}

			if ( 'style' === $tag && preg_match( '/expression|javascript|url\s*\(\s*data:/i', $node->textContent ) ) {
				$node->textContent = '';
			}
		}

		if ( $node->hasChildNodes() ) {
			foreach ( iterator_to_array( $node->childNodes ) as $child ) {
				self::scrub_node( $child );
			}
		}
	}

	/**
	 * The markup without its prolog: a byte-order mark, the XML declaration
	 * and any other processing instruction (`<?xml-stylesheet href=…?>` can
	 * load a remote sheet), and the DOCTYPE with its internal subset.
	 *
	 * The declaration matters beyond safety: clean() prepends its own, and a
	 * file that already had one — 54 of the corpus's 60 SVG icons — did not
	 * parse as a second declaration and fell through to the regex fallback,
	 * so it was never scrubbed node by node at all.
	 */
	private static function without_doctype( string $svg ): string {
		$svg = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $svg );
		$svg = (string) preg_replace( '/<\?[\s\S]*?\?>/', '', $svg );

		return trim( (string) preg_replace( '/<!DOCTYPE\b[^\[>]*(?:\[[\s\S]*?\]\s*)?>/i', '', $svg ) );
	}

	/**
	 * HTML named entities as numeric character references. The five XML ones
	 * and anything already numeric are left alone; an unknown name is dropped.
	 */
	private static function numeric_entities( string $svg ): string {
		return (string) preg_replace_callback(
			'/&(?!(?:amp|lt|gt|quot|apos);)([a-z][a-z0-9]*);/i',
			static function ( array $m ): string {
				$char = html_entity_decode( $m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );

				return $char === $m[0] ? '' : '&#' . mb_ord( $char, 'UTF-8' ) . ';';
			},
			$svg
		);
	}

	private static function regex_fallback( string $svg ): string {
		$svg = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $svg ) ?? $svg;
		$svg = preg_replace( '#<foreignObject\b[^>]*>.*?</foreignObject>#is', '', $svg ) ?? $svg;
		$svg = preg_replace( '/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $svg ) ?? $svg;
		$svg = preg_replace( '#javascript\s*:#i', '', $svg ) ?? $svg;

		return $svg;
	}
}

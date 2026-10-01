<?php
/**
 * An inline icon as a media-library file.
 *
 * @package DXAI_UI\Media
 */

declare(strict_types=1);

namespace DXAI_UI\Media;

use DXAI_UI\Support\Upload_Paths;

/**
 * The team keeps its icons as SVG files in the media library, in Image blocks: swapped from the library, sized in the
 * block's panel. A design's inline SVG becomes the same when it is plain drawing — a few shapes with colours of their
 * own — and only then: an icon that takes its colour from the text around it (`currentColor`), from the design's
 * variables, from a class of its own, or that is animated or refers to another part of the page, is not a picture
 * and stays the design's inline icon.
 *
 * The file is built from an allow-list, not copied: the shapes and the attributes a drawing needs, values without a
 * way out (no URL, no script, no style, no variable). One file per drawing, found again by its hash, so an icon a
 * design uses thirty times is one file and one attachment.
 */
final class Svg_Files {

	/** On the attachment: the hash of the drawing it holds. */
	public const META = '_dxai_ui_svg_hash';

	/** The sub-folder of the plugin's uploads folder. */
	private const DIR = 'icons';

	private const SHAPES = array( 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon', 'g', 'title', 'desc' );

	private const ROOT = array( 'viewBox', 'width', 'height', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'preserveAspectRatio', 'xmlns' );

	private const DRAW = array( 'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'width', 'height', 'points', 'transform', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'fill-rule', 'clip-rule', 'opacity', 'fill-opacity', 'stroke-opacity' );

	/** The case an attribute is written in; the importer keeps them lower-case. */
	private const CASE = array(
		'viewbox'             => 'viewBox',
		'preserveaspectratio' => 'preserveAspectRatio',
	);

	public static function available(): bool {
		return class_exists( '\DOMDocument' ) && function_exists( 'wp_upload_dir' );
	}

	/**
	 * The drawing as an SVG document, or null when it is more than a drawing.
	 *
	 * @param array<string, string> $root  The root's attributes, names in any case.
	 * @param string                $inner The children.
	 */
	public static function document( array $root, string $inner ): ?string {
		if ( ! self::available() || stripos( $inner, '<!' ) !== false || stripos( $inner, '<?' ) !== false ) {
			return null;
		}
		$attrs = array( 'xmlns' => 'http://www.w3.org/2000/svg' );
		foreach ( $root as $name => $value ) {
			$name = self::CASE[ strtolower( $name ) ] ?? $name;
			if ( ! in_array( $name, self::ROOT, true ) || ! self::safe_value( (string) $value ) ) {
				return null;
			}
			$attrs[ $name ] = (string) $value;
		}
		$open = '<svg';
		foreach ( $attrs as $name => $value ) {
			$open .= ' ' . $name . '="' . esc_attr( $value ) . '"';
		}
		$dom  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$ok   = $dom->loadXML( $open . '>' . $inner . '</svg>', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok || ! $dom->documentElement instanceof \DOMElement || $dom->doctype !== null ) {
			return null;
		}
		if ( ! self::clean( $dom->documentElement ) ) {
			return null;
		}
		$xml = $dom->saveXML( $dom->documentElement );

		return is_string( $xml ) ? $xml : null;
	}

	/**
	 * The attachment that holds a drawing; made when there is none.
	 *
	 * @return array{id:int, url:string, width:int, height:int}|null
	 */
	public static function ensure( string $svg ): ?array {
		if ( ! self::available() ) {
			return null;
		}
		$hash = substr( sha1( $svg ), 0, 20 );
		$found = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'meta_key'       => self::META,
				'meta_value'     => $hash,
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);
		$size = self::size( $svg );
		if ( $found !== array() ) {
			$id  = (int) $found[0];
			$url = (string) wp_get_attachment_url( $id );
			$file = get_attached_file( $id );
			if ( $url !== '' && is_string( $file ) && is_readable( $file ) ) {
				return array(
					'id'     => $id,
					'url'    => $url,
					'width'  => $size['width'],
					'height' => $size['height'],
				);
			}
		}
		$up = wp_upload_dir();
		if ( ! empty( $up['error'] ) ) {
			return null;
		}
		$rel  = Upload_Paths::DIR . '/' . self::DIR . '/' . $hash . '.svg';
		$path = trailingslashit( $up['basedir'] ) . $rel;
		if ( ! wp_mkdir_p( dirname( $path ) ) || false === file_put_contents( $path, $svg ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return null;
		}
		$url = trailingslashit( $up['baseurl'] ) . $rel;
		$id  = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/svg+xml',
				'post_title'     => 'Icon ' . substr( $hash, 0, 8 ),
				'post_content'   => '',
				'post_status'    => 'inherit',
				'guid'           => $url,
			),
			$path
		);
		if ( ! is_int( $id ) || $id < 1 ) {
			return null;
		}
		update_attached_file( $id, $path );
		wp_update_attachment_metadata(
			$id,
			array(
				'width'  => $size['width'],
				'height' => $size['height'],
				'file'   => $rel,
				'sizes'  => array(),
			)
		);
		update_post_meta( $id, self::META, $hash );

		return array(
			'id'     => $id,
			'url'    => $url,
			'width'  => $size['width'],
			'height' => $size['height'],
		);
	}

	/**
	 * The size the drawing is drawn at: its width and height, else its view box.
	 *
	 * @return array{width:int, height:int}
	 */
	public static function size( string $svg ): array {
		$w = preg_match( '/<svg\b[^>]*\swidth="([\d.]+)(?:px)?"/', $svg, $mw ) === 1 ? (float) $mw[1] : 0.0;
		$h = preg_match( '/<svg\b[^>]*\sheight="([\d.]+)(?:px)?"/', $svg, $mh ) === 1 ? (float) $mh[1] : 0.0;
		if ( ( $w <= 0 || $h <= 0 ) && preg_match( '/<svg\b[^>]*\sviewBox="[-\d.]+[ ,]+[-\d.]+[ ,]+([\d.]+)[ ,]+([\d.]+)"/', $svg, $mv ) === 1 ) {
			$w = $w > 0 ? $w : (float) $mv[1];
			$h = $h > 0 ? $h : (float) $mv[2];
		}

		return array(
			'width'  => max( 1, (int) round( $w ) ),
			'height' => max( 1, (int) round( $h ) ),
		);
	}

	/** Whether a value has no way out of a drawing: no address, script, variable, or colour taken from the page. */
	private static function safe_value( string $value ): bool {
		return preg_match( '/^[\w\s.,#%+\-():\/]*$/', $value ) === 1 && preg_match( '/url\(|var\(|currentcolor|javascript|data:|expression|\/\//i', $value ) !== 1;
	}

	/**
	 * Take out of the tree what a drawing does not need; false when something would have to be guessed (a shape that
	 * is not on the list, an attribute that is not).
	 */
	private static function clean( \DOMElement $root ): bool {
		foreach ( iterator_to_array( $root->childNodes ) as $node ) {
			if ( $node instanceof \DOMText ) {
				if ( trim( $node->nodeValue ?? '' ) !== '' && ! in_array( $node->parentNode->nodeName ?? '', array( 'title', 'desc' ), true ) ) {
					return false;
				}
				continue;
			}
			if ( ! $node instanceof \DOMElement ) {
				$root->removeChild( $node );
				continue;
			}
			if ( ! in_array( $node->localName, self::SHAPES, true ) ) {
				return false;
			}
			foreach ( iterator_to_array( $node->attributes ) as $attr ) {
				if ( ! in_array( $attr->name, self::DRAW, true ) || ! self::safe_value( (string) $attr->value ) ) {
					return false;
				}
			}
			if ( ! self::clean( $node ) ) {
				return false;
			}
		}

		return true;
	}
}

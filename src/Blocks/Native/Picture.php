<?php
/**
 * A picture: the theme's DX Picture, where it has one.
 *
 * @package DXAI_UI\Blocks\Native
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks\Native;

use DXAI_UI\Theme\Capabilities;

/**
 * An image of a design is a block of the team's own, DX Picture (`dx/picture`: a server-rendered `<picture>` with the right file for the
 * screen, an LCP switch and a sidebar to replace the image), not one the plugin makes. The plugin's own image block (`dxai-ui/image`) and the
 * core Image it turns into when that says the same (Image) become it when this site has the block — the American Restoration theme registers
 * it — and the image is a media-library image, which the block needs (it prints nothing without an attachment).
 *
 * Where it cannot be one, the picture stays what it was: the core Image (Image runs first and is the fallback: a site without the block, an
 * image that is not in the library, an SVG), or the plugin's own block when even that could not keep what the image carries. A decline is
 * always safe. It declines when:
 *
 *  - the image has CSS of its own (`dxaiCss`: a `dxs-` rule that rides the block's class, which a server-rendered block cannot write), a
 *    link, a caption or a size of its own (a native image's `width`, `aspectRatio`, `scale`: the block has no such attribute);
 *  - the design's stylesheet or the parent reaches the image by its position (`> img`, `space-y-*`): the block puts a figure and a
 *    picture around it, as core's Image does (Image::sheet_allows(), Image::spaces_children()).
 *
 * The block is written the way the team writes it (`<!-- wp:dx/picture {"imageId":19,"imageUrl":"…","imageWidth":1200,"imageHeight":630} /-->`)
 * with what the design needs on top: the design's classes on the figure, marked `dxai-part-img` so the rules written for the image reach the
 * image inside it and the figure and the picture take no box (Style_Rules::part_base_css()), and the sizes: the block's own defaults crop the
 * picture to 4:5 on a phone (an LCP hero's choice), which would change the shape of every image of a design, so both screens get the
 * uncropped size (filter `dxai_ui_picture_sizes` to choose others).
 */
final class Picture extends Converter {

	/** What a native image (made by Image) carries that this block can keep. */
	private const KNOWN_CORE = array( 'id', 'sizeSlug', 'linkDestination', 'className' );

	/** What the plugin's own image carries that this block can keep or does not need (WordPress writes srcset, sizes and the rest itself). */
	private const KNOWN_OWN = array( 'url', 'alt', 'className', 'loading', 'decoding', 'width', 'height', 'srcset', 'sizes' );

	public const MARKER = 'dxai-part-img';

	public function id(): string {
		return 'picture';
	}

	public function label(): string {
		return __( 'Pictures → DX Picture', 'dxai-ui' );
	}

	public function source(): string {
		return 'dxai-ui/image';
	}

	public function sources(): array {
		return array( 'dxai-ui/image', 'core/image' );
	}

	public function target(): string {
		return Capabilities::theme_block( 'picture' );
	}

	public function available(): bool {
		/**
		 * Whether images become DX Picture where the site has the block. Off, they stay the core Image.
		 *
		 * @param bool $on The block is registered.
		 */
		return (bool) apply_filters( 'dxai_ui_use_dx_picture', parent::available() );
	}

	public function convert( array $block, ?array $parent = null ): ?array {
		$name  = (string) ( $block['blockName'] ?? '' );
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		if ( ! in_array( $name, $this->sources(), true ) || ( $block['innerBlocks'] ?? array() ) !== array() || ! empty( $attrs['dxaiCss'] ) ) {
			return null;
		}
		if ( Image::spaces_children( $parent ) || ! Image::sheet_allows() ) {
			return null;
		}
		$content = is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array();
		if ( count( $content ) !== 1 || ! is_string( $content[0] ) ) {
			return null;
		}
		$picture = $name === 'core/image' ? $this->from_core( $attrs, $content[0] ) : $this->from_own( $attrs, $content[0] );
		if ( $picture === null ) {
			return null;
		}
		$id = self::attachment( (string) $picture['url'] );
		if ( $id < 1 ) {
			return null;
		}
		if ( $name === 'core/image' && (int) ( $attrs['id'] ?? 0 ) !== $id ) {
			// The block says one attachment and its address is another's.
			return null;
		}
		$file = (string) wp_get_attachment_url( $id );
		if ( $file === '' ) {
			return null;
		}
		$meta = wp_get_attachment_metadata( $id );
		$w    = (int) ( $picture['width'] ?: ( is_array( $meta ) ? ( $meta['width'] ?? 0 ) : 0 ) );
		$h    = (int) ( $picture['height'] ?: ( is_array( $meta ) ? ( $meta['height'] ?? 0 ) : 0 ) );

		$new = array(
			'imageId'  => $id,
			'imageUrl' => $file,
		);
		if ( (string) $picture['alt'] !== '' ) {
			$new['imageAlt'] = (string) $picture['alt'];
		}
		if ( $w > 0 && $h > 0 ) {
			$new['imageWidth']  = $w;
			$new['imageHeight'] = $h;
		}
		$sizes = self::sizes( $id );
		if ( $sizes['desktop'] !== 'dx_pic_desktop_hero' ) {
			$new['desktopSize'] = $sizes['desktop'];
		}
		if ( $sizes['mobile'] !== 'dx_pic_mobile_4x5' ) {
			$new['mobileSize'] = $sizes['mobile'];
		}
		// A design that pins a picture as eager (its LCP image) keeps that: the block preloads it.
		if ( ! empty( $picture['eager'] ) ) {
			$new['priority'] = true;
		}
		$new['className'] = trim( self::MARKER . ' ' . str_replace( self::MARKER, '', (string) $picture['class'] ) );
		$new['className'] = (string) preg_replace( '/\s+/', ' ', $new['className'] );

		// Self-closing: the block is printed by the server.
		$block['blockName']    = $this->target();
		$block['attrs']        = $new;
		$block['innerBlocks']  = array();
		$block['innerContent'] = array();
		$block['innerHTML']    = '';

		return $block;
	}

	/**
	 * What a native image made by Image holds, or null when it holds anything else (a caption, a link, a size of its own).
	 *
	 * @param array<string, mixed> $attrs
	 * @return array{url:string, alt:string, class:string, width:int, height:int, eager:bool}|null
	 */
	private function from_core( array $attrs, string $html ): ?array {
		// Only the plugin's own (marked) pictures are changed: an image a person put in is not.
		if ( ! self::only( $attrs, self::KNOWN_CORE ) || ! str_contains( (string) ( $attrs['className'] ?? '' ), self::MARKER ) ) {
			return null;
		}
		if ( ! in_array( $attrs['sizeSlug'] ?? 'full', array( 'full', null ), true ) || ! in_array( $attrs['linkDestination'] ?? 'none', array( 'none', null ), true ) || (int) ( $attrs['id'] ?? 0 ) < 1 ) {
			return null;
		}
		if ( preg_match( '#^\s*<figure class="([^"]*)"><img src="([^"]*)" alt="([^"]*)"(?: class="wp-image-(\d+)")?\s*/?></figure>\s*$#', $html, $m ) !== 1 ) {
			return null;
		}
		$url = html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' );
		$alt = html_entity_decode( $m[3], ENT_QUOTES, 'UTF-8' );
		if ( self::hazard( $url ) || self::hazard( $alt ) || str_contains( $url, '&' ) ) {
			return null;
		}

		return array(
			'url'    => $url,
			'alt'    => $alt,
			'class'  => (string) ( $attrs['className'] ?? '' ),
			'width'  => 0,
			'height' => 0,
			'eager'  => false,
		);
	}

	/**
	 * What the plugin's own image holds (its `<img>` as the design wrote it), or null when it holds anything this block cannot.
	 *
	 * @param array<string, mixed> $attrs
	 * @return array{url:string, alt:string, class:string, width:int, height:int, eager:bool}|null
	 */
	private function from_own( array $attrs, string $html ): ?array {
		$url = (string) ( $attrs['url'] ?? '' );
		if ( $url === '' || ! self::only( $attrs, self::KNOWN_OWN ) ) {
			return null;
		}
		$alt = (string) ( $attrs['alt'] ?? '' );
		if ( self::hazard( $url ) || self::hazard( $alt ) || str_contains( $url, '&' ) ) {
			return null;
		}
		// The loading the design pinned: lazy is what WordPress writes anyway; eager is the design's LCP picture.
		$loading = (string) ( $attrs['loading'] ?? '' );
		if ( $loading !== '' && ! in_array( $loading, array( 'lazy', 'eager' ), true ) ) {
			return null;
		}
		if ( isset( $attrs['decoding'] ) && ! in_array( $attrs['decoding'], array( 'async', 'auto' ), true ) ) {
			return null;
		}
		if ( preg_match( '/^(\s*)(<img\b[^>]*>)(\s*)$/', $html, $m ) !== 1 ) {
			return null;
		}
		$img = self::tag_attributes( str_replace( '/>', '>', $m[2] ), 'img' );
		if ( $img === null || array_diff( array_keys( $img ), array( 'src', 'alt', 'class', 'loading', 'decoding', 'width', 'height', 'srcset', 'sizes' ) ) !== array() ) {
			return null;
		}
		if ( html_entity_decode( (string) ( $img['src'] ?? '' ), ENT_QUOTES, 'UTF-8' ) !== html_entity_decode( esc_url( $url ), ENT_QUOTES, 'UTF-8' )
			|| (string) ( $img['alt'] ?? '' ) !== $alt || (string) ( $img['class'] ?? '' ) !== self::class_tail( $attrs ) ) {
			return null;
		}
		$w = isset( $attrs['width'] ) && ctype_digit( (string) $attrs['width'] ) ? (int) $attrs['width'] : 0;
		$h = isset( $attrs['height'] ) && ctype_digit( (string) $attrs['height'] ) ? (int) $attrs['height'] : 0;
		if ( $w < 1 || $h < 1 ) {
			// A size the design gave as a length ("100%", "auto") is its own, and the block's width and height are numbers.
			if ( isset( $attrs['width'] ) || isset( $attrs['height'] ) ) {
				return null;
			}
			$w = 0;
			$h = 0;
		}

		return array(
			'url'    => $url,
			'alt'    => $alt,
			'class'  => (string) ( $attrs['className'] ?? '' ),
			'width'  => $w,
			'height' => $h,
			'eager'  => $loading === 'eager',
		);
	}

	/**
	 * The sizes both screens get: the same uncropped one, so the picture has the shape the design gave it on a phone too.
	 *
	 * @return array{desktop:string, mobile:string}
	 */
	public static function sizes( int $attachment_id ): array {
		/**
		 * The image sizes of the block a design's picture gets, for the desktop and for the phone. The block's own default for a phone is a
		 * 4:5 crop (made for a hero), which changes the shape of any other picture.
		 *
		 * @param array{desktop:string, mobile:string} $sizes         Defaults.
		 * @param int                                  $attachment_id The image.
		 */
		$sizes = (array) apply_filters(
			'dxai_ui_picture_sizes',
			array(
				'desktop' => 'dx_pic_desktop_hero',
				'mobile'  => 'dx_pic_desktop_hero',
			),
			$attachment_id
		);

		return array(
			'desktop' => (string) ( $sizes['desktop'] ?? 'dx_pic_desktop_hero' ),
			'mobile'  => (string) ( $sizes['mobile'] ?? 'dx_pic_desktop_hero' ),
		);
	}

	/** The media-library image a URL is, or 0: not an SVG (a drawing is not a picture to serve in several sizes), not a file from another site. */
	private static function attachment( string $url ): int {
		$id = (int) attachment_url_to_postid( $url );
		if ( $id < 1 || ! wp_attachment_is_image( $id ) || get_post_mime_type( $id ) === 'image/svg+xml' ) {
			return 0;
		}

		return $id;
	}
}

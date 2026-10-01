<?php
/**
 * A picture: core's Image.
 *
 * @package DXAI_UI\Blocks\Native
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks\Native;

use DXAI_UI\Support\Upload_Paths;

/**
 * `dxai-ui/image` → `core/image`, with the attachment it points to, so the
 * picture is a media-library image: replaced from the library, given a size,
 * a link and a caption in the sidebar, and served with WordPress's own
 * `srcset`, `sizes`, width, height and lazy loading (the custom block wrote a
 * bare `<img>` that got none of them).
 *
 * core/image is a `<figure>` around the `<img>`, and the design's classes and
 * CSS were written for the `<img>`. The block carries them on the figure (where
 * a person can see and edit them) with the marker `dxai-part-img`, and the
 * rules are written for the image inside it (Style_Rules::PARTS): on the page
 * the figure takes no box, so the picture is laid out exactly where the design
 * put it.
 *
 * That holds while nothing addresses the picture through its parent. A design
 * whose stylesheet selects an image by its position (`> img`, `img + p`,
 * `:first-child` on an image), a parent that spaces its children (`space-y-*`,
 * `divide-*`) and any image the block cannot describe in full (a `srcset`,
 * an explicit size or loading) keep the custom block.
 */
final class Image extends Converter {

	private const KNOWN = array( 'url', 'alt', 'className', 'dxaiCss', 'loading', 'decoding' );

	/** @var array<string, bool> Design stylesheet path + mtime => safe to wrap the pictures. */
	private static array $sheet_safe = array();

	public function id(): string {
		return 'image';
	}

	public function label(): string {
		return __( 'Pictures → Image', 'dxai-ui' );
	}

	public function source(): string {
		return 'dxai-ui/image';
	}

	public function target(): string {
		return 'core/image';
	}

	public function convert( array $block, ?array $parent = null ): ?array {
		if ( ( $block['blockName'] ?? '' ) !== $this->source() ) {
			return null;
		}
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$url   = (string) ( $attrs['url'] ?? '' );
		if ( $url === '' || ! self::only( $attrs, self::KNOWN ) || ( $block['innerBlocks'] ?? array() ) !== array() ) {
			return null;
		}
		// Lazy is what WordPress writes for a picture below the fold; an explicit "eager" is the design pinning one.
		if ( isset( $attrs['loading'] ) && $attrs['loading'] !== 'lazy' ) {
			return null;
		}
		if ( isset( $attrs['decoding'] ) && ! in_array( $attrs['decoding'], array( 'async', 'auto' ), true ) ) {
			return null;
		}
		$alt = (string) ( $attrs['alt'] ?? '' );
		if ( self::hazard( $url ) || self::hazard( $alt ) || str_contains( $url, '&' ) ) {
			return null;
		}
		if ( self::spaces_children( $parent ) || ! self::sheet_allows() ) {
			return null;
		}
		$content = is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array();
		if ( count( $content ) !== 1 || ! is_string( $content[0] ) || preg_match( '/^(\s*)(<img\b[^>]*>)(\s*)$/', $content[0], $m ) !== 1 ) {
			return null;
		}
		$img = self::tag_attributes( str_replace( '/>', '>', $m[2] ), 'img' );
		if ( $img === null || array_diff( array_keys( $img ), array( 'src', 'alt', 'class', 'loading', 'decoding' ) ) !== array() ) {
			return null;
		}
		if ( html_entity_decode( (string) ( $img['src'] ?? '' ), ENT_QUOTES, 'UTF-8' ) !== html_entity_decode( esc_url( $url ), ENT_QUOTES, 'UTF-8' )
			|| (string) ( $img['alt'] ?? '' ) !== $alt || (string) ( $img['class'] ?? '' ) !== self::class_tail( $attrs ) ) {
			return null;
		}

		$id = self::attachment( $url );
		$new = array();
		if ( $id > 0 ) {
			$new['id'] = $id;
		}
		$new['sizeSlug']        = 'full';
		$new['linkDestination'] = 'none';
		$new['className']       = trim( 'dxai-part-img ' . (string) ( $attrs['className'] ?? '' ) );
		if ( ! empty( $attrs['dxaiCss'] ) ) {
			$new['dxaiCss'] = $attrs['dxaiCss'];
		}

		// save(): figure with the generated classes, then the custom ones, then the dxs- class; the image with its id class.
		$html = '<figure class="' . esc_attr( trim( 'wp-block-image size-full ' . self::class_tail( $new ) ) ) . '">'
			. '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '"' . ( $id > 0 ? ' class="wp-image-' . $id . '"' : '' ) . '/></figure>';

		$block['blockName']    = $this->target();
		$block['attrs']        = $new;
		$block['innerContent'] = array( $m[1] . $html . $m[3] );
		$block['innerHTML']    = $block['innerContent'][0];

		return $block;
	}

	/** The media-library image a URL is, or 0 (a picture from another site is still a core/image, without the id). */
	private static function attachment( string $url ): int {
		$id = (int) attachment_url_to_postid( $url );
		if ( $id < 1 || ! wp_attachment_is_image( $id ) ) {
			return 0;
		}

		return $id;
	}

	/** Whether the parent spaces or divides its children by position (Tailwind's `space-*` and `divide-*`). */
	public static function spaces_children( ?array $parent ): bool {
		$class = (string) ( $parent['className'] ?? '' );

		return preg_match( '/(?:^|\s)(?:[a-z0-9]+:)?(?:space-[xy]-|divide-[xy])/', $class ) === 1;
	}

	/**
	 * Whether the design's stylesheet leaves pictures alone by position: no selector that ends in an image (`img`, `*`)
	 * behind a combinator or a structural pseudo-class. Read once per stylesheet version.
	 */
	public static function sheet_allows(): bool {
		$home = (int) ( self::$context['home'] ?? 0 );
		if ( $home < 1 ) {
			return false;
		}
		$sheet = Upload_Paths::for_meta( $home, '_dxai_ui_css_url' );
		$path  = (string) ( $sheet['path'] ?? '' );
		if ( $path === '' || ! is_readable( $path ) ) {
			// No stylesheet to read: nothing addresses a picture by position.
			return true;
		}
		$key = $path . '|' . (int) filemtime( $path );
		if ( ! isset( self::$sheet_safe[ $key ] ) ) {
			self::$sheet_safe[ $key ] = self::css_safe( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		return self::$sheet_safe[ $key ];
	}

	/** Whether no selector of the stylesheet has an image or `*` as its subject behind a combinator or structural pseudo-class. */
	public static function css_safe( string $css ): bool {
		$css = (string) preg_replace( '~/\*.*?\*/~s', '', $css );
		if ( preg_match_all( '/(?:^|[}\s;])([^{}@;]+)\{/', $css, $m ) === false ) {
			return false;
		}
		foreach ( $m[1] as $list ) {
			foreach ( preg_split( '/,(?![^()\[\]]*[\)\]])/', $list ) ?: array() as $selector ) {
				$selector = trim( $selector );
				// Attribute values and :not()/:where()/:is() contents are not combinators of this selector.
				$plain = (string) preg_replace( '/\[[^\]]*\]/', '', $selector );
				$plain = (string) preg_replace( '/:(?:not|where|is|has)\((?:[^()]|\([^()]*\))*\)/', '', $plain );
				$plain = trim( $plain );
				$parts = preg_split( '/\s*[>+~]\s*|\s+/', $plain ) ?: array();
				$last  = (string) end( $parts );
				$combinator = preg_match( '/[>+~]/', $plain ) === 1;
				$structural = preg_match( '/:(?:first|last|nth|only)-(?:child|of-type)|:empty/', $selector ) === 1;
				if ( ! $combinator && ! $structural ) {
					continue;
				}
				// Subject: the last compound. An image, or anything ("*", or only pseudo-classes).
				$subject = preg_replace( '/:[a-z-]+(?:\([^)]*\))?/', '', $last );
				if ( $subject === '' || $subject === '*' || preg_match( '/^img(?![\w-])/', (string) $subject ) === 1 ) {
					return false;
				}
			}
		}

		return true;
	}
}

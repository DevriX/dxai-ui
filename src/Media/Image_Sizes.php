<?php
/**
 * The pictures of the plugin's own blocks get their sizes from WordPress, as an Image block's do.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Media;

use DXAI_UI\Seo\Page_Meta;

/**
 * WordPress gives a picture its width, height, `srcset` and `sizes` when it knows which attachment it is: the `wp-image-<id>` class an
 * Image block writes (wp_filter_content_tags()). A design's pictures in the plugin's own blocks (`dxai-ui/box` written as an <img>: a
 * hero's full-width background, a card's photo, an icon) carry only their address, so a phone loaded the whole file: Five Star's hero,
 * 2400 px wide, on a 412 px screen — "Properly size images", 539 KiB (docs/PLAN-PERFECT-PAGES.md, T5.1). On a design's page, a picture
 * without that class and without a srcset of its own, whose address is an attachment of this site (or one of its sizes), gets the
 * class while the block renders; WordPress does the rest when it filters the content, as for any Image block — and the width and
 * height it writes keep the room of a picture that loads lazily. `dxai_ui_image_sizes` turns it off.
 */
final class Image_Sizes {

	/** @var array<string, int> Address => attachment, for this request. */
	private static array $ids = array();

	public function register(): void {
		add_filter( 'render_block', array( self::class, 'tag' ), 9, 2 );
	}

	/**
	 * A block's own pictures with the class that names their attachment.
	 *
	 * @param string|mixed               $html
	 * @param array<string, mixed>|mixed $block
	 * @return string|mixed
	 */
	public static function tag( $html, $block ) {
		// A block's own markup only: a block with inner blocks has their pictures in theirs, already seen.
		if ( ! is_string( $html ) || stripos( $html, '<img' ) === false || ! is_array( $block ) || ! empty( $block['innerBlocks'] ) || ! self::applies() ) {
			return $html;
		}
		$tags = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			if ( preg_match( '/(?:^|\s)wp-image-\d+(?:\s|$)/', (string) $tags->get_attribute( 'class' ) ) === 1 || $tags->get_attribute( 'srcset' ) !== null ) {
				continue;
			}
			$id = self::attachment( (string) $tags->get_attribute( 'src' ) );
			if ( $id > 0 ) {
				$tags->add_class( 'wp-image-' . $id );
			}
		}

		return $tags->get_updated_html();
	}

	/**
	 * The attachment an address in this site's uploads is, or one of its sizes is (`photo-768x512.webp` is `photo.webp`'s); 0 for any
	 * other address.
	 */
	public static function attachment( string $src ): int {
		$src = (string) strtok( trim( $src ), '?#' );
		if ( $src === '' ) {
			return 0;
		}
		if ( isset( self::$ids[ $src ] ) ) {
			return self::$ids[ $src ];
		}
		$uploads = wp_upload_dir( null, false );
		$base    = (string) preg_replace( '#^https?:#i', '', (string) $uploads['baseurl'] );
		$id      = 0;
		if ( $base !== '' && str_starts_with( (string) preg_replace( '#^https?:#i', '', $src ), $base . '/' ) ) {
			$id = (int) attachment_url_to_postid( $src );
			if ( $id < 1 ) {
				$full = (string) preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $src );
				$id   = $full !== $src ? (int) attachment_url_to_postid( $full ) : 0;
			}
			if ( $id > 0 && ! wp_attachment_is_image( $id ) ) {
				$id = 0;
			}
		}
		self::$ids[ $src ] = $id;

		return $id;
	}

	/** On the main content of a design's page, unless turned off. */
	private static function applies(): bool {
		if ( ! is_singular() || ! doing_filter( 'the_content' ) ) {
			return false;
		}
		$id = (int) get_queried_object_id();

		/**
		 * Whether the pictures of the plugin's own blocks are named after their attachment, so WordPress writes their width, height,
		 * srcset and sizes.
		 *
		 * @param bool $on Whether the page is a design's.
		 * @param int  $id The page.
		 */
		return (bool) apply_filters( 'dxai_ui_image_sizes', $id > 0 && Page_Meta::is_design_page( $id ), $id );
	}
}

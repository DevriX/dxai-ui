<?php
/**
 * The picture a design's page opens on, asked for first.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Media;

use DXAI_UI\Pages\Section_Library;
use DXAI_UI\Seo\Page_Meta;

/**
 * A design's hero is often a section with a photograph behind it, written as CSS (`background-image` in the block's own CSS, a
 * cover, a group's background). A browser finds such a picture only once it has read the stylesheet that names it, and on a phone
 * on slow 4G that is the page's Largest Contentful Paint: H2O Away's hero at 5.1–9.6 s (docs/PLAN-PERFECT-PAGES.md, T5.1). The page
 * says so in its head: `<link rel="preload" as="image" fetchpriority="high">` for the picture behind its first section, so the
 * browser asks for it with the HTML. Only on a design's page, only the first section's picture; `dxai_ui_lcp_preload` changes the
 * address or removes it with ''.
 */
final class Lcp_Preload {

	public function register(): void {
		add_action( 'wp_head', array( self::class, 'print_preload' ), 1 );
	}

	/** The preload, on a design's page that opens on a picture. */
	public static function print_preload(): void {
		$id = is_singular( 'page' ) ? (int) get_queried_object_id() : 0;
		if ( $id < 1 || ! Page_Meta::is_design_page( $id ) ) {
			return;
		}
		$url = self::hero_picture( (string) get_post_field( 'post_content', $id ) );

		/**
		 * The picture a design's page opens on, asked for first; '' asks for none.
		 *
		 * @param string $url The picture behind the page's first section, or ''.
		 * @param int    $id  The page.
		 */
		$url = (string) apply_filters( 'dxai_ui_lcp_preload', $url, $id );
		if ( $url !== '' ) {
			echo '<link rel="preload" as="image" href="' . esc_url( $url ) . '" fetchpriority="high" />' . "\n";
		}
	}

	/**
	 * The picture behind the first section of a page's content: its own CSS's `background-image`, a cover's picture, a group's
	 * background image — in the section or the two levels below it. A header (the page's own, on a page that carries it) is not
	 * the section the page opens on.
	 */
	public static function hero_picture( string $content ): string {
		foreach ( Section_Library::flatten( parse_blocks( $content ) ) as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$tag = strtolower( (string) ( $block['attrs']['tagName'] ?? '' ) );
			if ( in_array( $tag, array( 'header', 'nav' ), true ) || $block['blockName'] === 'core/template-part' ) {
				continue;
			}

			return self::picture_in( $block, 0 );
		}

		return '';
	}

	/** @param array<string, mixed> $block */
	private static function picture_in( array $block, int $depth ): string {
		$attrs = (array) ( $block['attrs'] ?? array() );
		if ( preg_match( '/background(?:-image)?\s*:[^;]*url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', (string) ( $attrs['dxaiCss'] ?? '' ), $m ) === 1 ) {
			return trim( $m[1] );
		}
		if ( ( $block['blockName'] ?? '' ) === 'core/cover' && ! empty( $attrs['url'] ) && ( $attrs['backgroundType'] ?? 'image' ) === 'image' ) {
			return (string) $attrs['url'];
		}
		$bg = $attrs['style']['background']['backgroundImage']['url'] ?? '';
		if ( is_string( $bg ) && $bg !== '' ) {
			return $bg;
		}
		if ( $depth < 2 ) {
			foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $child ) {
				$found = is_array( $child ) ? self::picture_in( $child, $depth + 1 ) : '';
				if ( $found !== '' ) {
					return $found;
				}
			}
		}

		return '';
	}
}

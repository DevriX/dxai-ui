<?php
/**
 * The imported Home as the site's front page.
 *
 * @package DXAI_UI\Network
 */

declare(strict_types=1);

namespace DXAI_UI\Network;

/**
 * A site imported from a design whose Home is not its front page shows the theme's list of posts, or WordPress's sample
 * page, at its address (docs/PLAN-NETWORK-PAGES.md, decision 8). The Home becomes the front page by itself when the site
 * has none of its own:
 *
 *  none     the front page shows the latest posts, or names a page that is not there (or not published);
 *  empty    the front page has no content (fewer than 40 words and no picture);
 *  default  the front page is WordPress's sample page, unchanged;
 *  own      a page of the site's own: it stays, and a person can choose the Home with one switch;
 *  this     the Home is the front page already.
 *
 * What the front page was is kept, and it can be put back.
 */
final class Front_Page {

	/** Option: what the front page was before a Home was made it ({show, page, home}). */
	public const BEFORE = 'dxai_ui_front_before';

	/**
	 * What the site's front page is now, for this Home. Before the Home is saved ($home 0), the archive it comes from says
	 * which it will be: a front page imported from the same archive is the one the import updates.
	 *
	 * @return array{state: string, id: int, title: string}
	 */
	public static function state( int $home, string $archive = '' ): array {
		$show = (string) get_option( 'show_on_front' );
		$id   = $show === 'page' ? (int) get_option( 'page_on_front' ) : 0;
		$post = $id > 0 ? get_post( $id ) : null;
		$out  = array(
			'id'    => $post instanceof \WP_Post ? (int) $post->ID : 0,
			'title' => $post instanceof \WP_Post ? html_entity_decode( (string) get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) : '',
		);
		if ( $id > 0 && ( $id === $home || ( $home === 0 && $archive !== '' && (string) get_post_meta( $id, '_dxai_ui_source_zip', true ) === $archive ) ) ) {
			return array( 'state' => 'this' ) + $out;
		}
		if ( ! $post instanceof \WP_Post || $post->post_status !== 'publish' ) {
			return array( 'state' => 'none' ) + $out;
		}
		$content = (string) $post->post_content;
		if ( self::is_sample( $post ) ) {
			return array( 'state' => 'default' ) + $out;
		}
		$words = Page_Reader::words( html_entity_decode( wp_strip_all_tags( $content ), ENT_QUOTES, 'UTF-8' ) );
		if ( $words < 40 && preg_match( '/<img\b|wp:image|wp:cover|wp:dx\/picture/i', $content ) !== 1 ) {
			return array( 'state' => 'empty' ) + $out;
		}

		return array( 'state' => 'own' ) + $out;
	}

	/**
	 * WordPress's sample page, as it is made with a site: its address and its first words.
	 */
	private static function is_sample( \WP_Post $post ): bool {
		return $post->post_name === 'sample-page' && str_contains( wp_strip_all_tags( (string) $post->post_content ), 'This is an example page.' );
	}

	/**
	 * The Home made the front page when the site has none of its own (none, empty, default); `force` makes it so over a page
	 * of the site's own. What the front page was is kept.
	 *
	 * @return array{state: string, made: bool, id: int, title: string}
	 */
	public static function adopt( int $home, bool $force = false ): array {
		$now = self::state( $home );
		if ( get_post_type( $home ) !== 'page' || get_post_status( $home ) !== 'publish' ) {
			return array( 'made' => false ) + $now;
		}
		if ( $now['state'] === 'this' || ( $now['state'] === 'own' && ! $force ) ) {
			return array( 'made' => false ) + $now;
		}
		update_option(
			self::BEFORE,
			array(
				'show' => (string) get_option( 'show_on_front' ),
				'page' => (int) get_option( 'page_on_front' ),
				'home' => $home,
			),
			false
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $home );

		return array( 'made' => true ) + $now;
	}

	/**
	 * The front page as it was before the Home was made it, while the Home still is the front page.
	 */
	public static function restore(): bool {
		$before = get_option( self::BEFORE );
		if ( ! is_array( $before ) || (int) get_option( 'page_on_front' ) !== (int) ( $before['home'] ?? 0 ) ) {
			return false;
		}
		update_option( 'show_on_front', (string) ( $before['show'] ?? 'posts' ) );
		update_option( 'page_on_front', (int) ( $before['page'] ?? 0 ) );
		delete_option( self::BEFORE );

		return true;
	}
}

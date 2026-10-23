<?php
/**
 * The whole site seen before its pages go live: the Home and every page the import made, and the choice of which to add.
 *
 * @package DXAI_UI\Network
 */

declare(strict_types=1);

namespace DXAI_UI\Network;

use DXAI_UI\Pages\Team_Pages;
use DXAI_UI\Structures\Page_Scope;

/**
 * A Home-only import makes the pages its menu names (from the old site, or from the Home's own sections). They are made
 * as drafts while a person looks at them (docs/PLAN-NETWORK-PAGES.md, section 11, point 6): the import asks for it
 * (hold()), and every page it makes new while that holds is a draft (the `dxai_ui_new_page_status` filter; a page that
 * was there already keeps its status). Then the person sees the Home and each page, with how each measures against the
 * team's pages (Page_Quality), and chooses: every page, only the Home, or the ones ticked. The chosen ones are published
 * (the menu, which leads to them already, shows them once they are), the others go to the trash — only pages the import
 * made; a page of the site's own is never touched.
 */
final class Site_Review {

	/** Option: until when the pages an import makes new are drafts (a timestamp). */
	public const HOLD = 'dxai_ui_hold_new_pages';

	/** How long a hold lasts when nothing releases it: a long import, with time to look. */
	private const HOLD_MINUTES = 180;

	public static function register(): void {
		add_filter( 'dxai_ui_new_page_status', array( self::class, 'status' ) );
		add_filter( 'page_link', array( self::class, 'draft_link' ), 10, 3 );
		add_filter( 'wp_insert_post_data', array( self::class, 'draft_slug' ), 10, 2 );
	}

	/**
	 * WordPress gives a draft its slug as asked, taken or not, and the unique one only when it is published: a draft the import
	 * makes while the review holds takes the one it will have then, so the address its links carry is the one it gets.
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $postarr
	 * @return array<string, mixed>
	 */
	public static function draft_slug( array $data, array $postarr ): array {
		if ( ( $data['post_type'] ?? '' ) !== 'page' || ( $data['post_status'] ?? '' ) !== 'draft' || (string) ( $data['post_name'] ?? '' ) === '' || ! self::holding() ) {
			return $data;
		}
		$data['post_name'] = wp_unique_post_slug( (string) $data['post_name'], (int) ( $postarr['ID'] ?? 0 ), 'publish', 'page', (int) ( $data['post_parent'] ?? 0 ) );

		return $data;
	}

	/**
	 * A draft's link is `?page_id=N` until it is published, and the import writes the links between its pages, the menu's
	 * items and the Home's buttons while the pages are drafts: they would all keep that form after the person chose (a
	 * redirect on every click). While the review holds, a draft the import made answers with the address it will have once
	 * published (WordPress's own sample link); decide() corrects the few a published slug changed.
	 */
	public static function draft_link( string $link, int $id, bool $sample = false ): string {
		if ( $sample || ! self::holding() ) {
			return $link;
		}
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || $post->post_status !== 'draft' || $post->post_name === '' || ! self::made_by_import( $id ) ) {
			return $link;
		}

		return get_page_link( $post, false, true );
	}

	/** The pages made from now on are drafts, until the person has chosen (or the hold runs out). */
	public static function hold(): void {
		update_option( self::HOLD, time() + self::HOLD_MINUTES * MINUTE_IN_SECONDS, false );
	}

	/** New pages are published again as they are made. */
	public static function release(): void {
		delete_option( self::HOLD );
	}

	public static function holding(): bool {
		return (int) get_option( self::HOLD, 0 ) > time();
	}

	/**
	 * The status of a page an import makes new: a draft while a person is to choose.
	 */
	public static function status( string $status ): string {
		return self::holding() ? 'draft' : $status;
	}

	/**
	 * Whether a page was made by an import of a design (the crawl of the old site, the pages from the Home's sections, a copy
	 * the menu named): only such a page may go to the trash here.
	 */
	public static function made_by_import( int $id ): bool {
		return get_post_meta( $id, '_dxai_ui_generated_page', true ) === '1'
			|| (string) get_post_meta( $id, Team_Pages::META, true ) !== ''
			|| get_post_meta( $id, Page_Copies::MADE, true ) === '1';
	}

	/**
	 * The Home and the pages of its design, the Home first: each with its status, where to see it (a draft's preview), how
	 * it measures, and whether it was made by the import.
	 *
	 * @return array{home: int, holding: bool, pages: array<int, array<string, mixed>>}
	 */
	public static function pages( int $home ): array {
		$ids = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts'      => 200,
				'fields'           => 'ids',
				'meta_key'         => Page_Scope::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => (string) $home, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'          => 'menu_order ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		$ids   = array_values( array_unique( array_merge( array( $home ), array_map( 'intval', $ids ) ) ) );
		$out   = array();
		$homes = self::pictures( (string) get_post_field( 'post_content', $home, 'raw' ) );
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post || $post->post_type !== 'page' ) {
				continue;
			}
			$quality = $id === $home ? null : Page_Quality::of( $id );
			$out[]   = array(
				'id'      => $id,
				'title'   => html_entity_decode( (string) get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'status'  => (string) $post->post_status,
				'home'    => $id === $home,
				'parent'  => (int) $post->post_parent,
				'kind'    => $id === $home ? 'home' : Page_Copies::kind( $id ),
				'made'    => $id !== $home && self::made_by_import( $id ),
				'view'    => $post->post_status === 'publish' ? (string) get_permalink( $post ) : (string) get_preview_post_link( $post ),
				'picture' => self::picture( (string) $post->post_content, $id === $home ? array() : $homes ),
				'edit'    => (string) get_edit_post_link( $id, 'raw' ),
				'quality' => $quality,
			);
		}

		return array(
			'home'    => $home,
			'holding' => self::holding(),
			'pages'   => $out,
		);
	}

	/**
	 * The pictures a page shows (photographs, not icons), in the order they come.
	 *
	 * @return array<int, string>
	 */
	private static function pictures( string $content ): array {
		$found = array();
		foreach ( array( '/<img\b[^>]*\ssrc="([^"]+)"/i', '/"url":"(https?:[^"]+\.(?:jpe?g|png|webp|avif|gif)[^"]*)"/i', '/background-image:\s*url\(\s*[\'"]?(https?:[^\'")]+)/i' ) as $re ) {
			if ( preg_match_all( $re, $content, $m, PREG_OFFSET_CAPTURE ) > 0 ) {
				foreach ( $m[1] as $hit ) {
					$url = str_replace( '\\/', '/', html_entity_decode( (string) $hit[0], ENT_QUOTES, 'UTF-8' ) );
					if ( ! str_starts_with( $url, 'data:' ) && preg_match( '/\.svg(?:$|[?#])/i', $url ) !== 1 ) {
						$found[ (int) $hit[1] ] = $url;
					}
				}
			}
		}
		ksort( $found );

		return array_values( array_unique( $found ) );
	}

	/**
	 * What stands for a page in the list (so the list does not draw every page live — one page is, beside it): its first picture
	 * of its own, not one the Home shows too (then every page of a site would be the Home's hero), else its first. '' when none.
	 *
	 * @param array<int, string> $home_pictures
	 */
	private static function picture( string $content, array $home_pictures = array() ): string {
		$all  = self::pictures( $content );
		$own  = array_values( array_diff( $all, $home_pictures ) );
		$pick = $own[0] ?? ( $all[0] ?? '' );

		return $pick === '' ? '' : esc_url_raw( $pick );
	}

	/**
	 * The person's choice: these pages published, those (made by the import) to the trash; the hold ends.
	 *
	 * @param array<int, int> $publish
	 * @param array<int, int> $drop
	 * @return array{published: array<int, int>, trashed: array<int, int>, kept: array<int, int>}
	 */
	public static function decide( int $home, array $publish, array $drop ): array {
		$mine = static fn( int $id ): bool => $id !== $home && get_post_type( $id ) === 'page' && (int) get_post_meta( $id, Page_Scope::META, true ) === $home;
		$out  = array(
			'published' => array(),
			'trashed'   => array(),
			'kept'      => array(),
		);
		$publish = array_values( array_filter( array_unique( array_map( 'intval', $publish ) ), $mine ) );
		// A page goes live under its parent's address: the parent of a chosen page is published with it.
		foreach ( $publish as $id ) {
			for ( $up = (int) wp_get_post_parent_id( $id ); $up > 0 && $up !== $home && $mine( $up ) && ! in_array( $up, $publish, true ); $up = (int) wp_get_post_parent_id( $up ) ) {
				$publish[] = $up;
			}
		}
		$moved = array();
		foreach ( $publish as $id ) {
			if ( get_post_status( $id ) === 'publish' || ! current_user_can( 'publish_post', $id ) ) {
				continue;
			}
			$before = get_page_link( $id, false, true );
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'publish',
				)
			);
			clean_post_cache( $id );
			$after = (string) get_permalink( $id );
			if ( $before !== '' && $after !== '' && $before !== $after ) {
				$moved[ $before ] = $after;
			}
			$out['published'][] = $id;
		}
		self::relink( $home, $moved );
		$origin = untrailingslashit( (string) get_post_meta( $home, '_dxai_ui_site_origin', true ) );
		$gone   = array();
		foreach ( array_unique( array_map( 'intval', $drop ) ) as $id ) {
			if ( in_array( $id, $publish, true ) ) {
				continue;
			}
			// A page a published one sits under stays (in the trash its address would change, and its children's with it).
			$parent_of_live = get_posts(
				array(
					'post_type'        => 'page',
					'post_status'      => 'publish',
					'post_parent'      => $id,
					'numberposts'      => 1,
					'fields'           => 'ids',
					'suppress_filters' => true,
				)
			) !== array();
			if ( $mine( $id ) && self::made_by_import( $id ) && ! $parent_of_live && current_user_can( 'delete_post', $id ) ) {
				// The links to it go back to where they led before the import made it: the old site's page it was made from.
				$route = trim( (string) get_post_meta( $id, '_dxai_ui_source_route', true ), '/' );
				if ( $route !== '' && preg_match( '~^https?://[^/\s?#]+$~i', $origin ) === 1 ) {
					$old = $origin . '/' . $route . '/';
					foreach ( array( get_page_link( $id, false, true ), home_url( '?page_id=' . $id ), (string) get_permalink( $id ) ) as $link ) {
						if ( $link !== '' ) {
							$gone[ $link ] = $old;
						}
					}
				}
				if ( EMPTY_TRASH_DAYS > 0 ) {
					wp_trash_post( $id );
				} else {
					wp_update_post(
						array(
							'ID'          => $id,
							'post_status' => 'draft',
						)
					);
				}
				$out['trashed'][] = $id;
			} else {
				$out['kept'][] = $id;
			}
		}
		self::relink( $home, $gone, true );
		self::release();

		return $out;
	}

	/**
	 * Where a page's published address differs from the one its draft answered with (its slug was taken by a page of the
	 * site's own), the links the import wrote to it follow: in the Home and the design's pages, and in the menus' links. A
	 * page's address leads the way to the pages under it, so a link to one of those follows too. With $whole, an address is
	 * changed only where it is the whole link (`?page_id=12` is not the start of `?page_id=123`): a page that went to the trash.
	 *
	 * @param array<string, string> $moved The draft's address => the published one (or where its links lead now).
	 */
	private static function relink( int $home, array $moved, bool $whole = false ): void {
		if ( $moved === array() ) {
			return;
		}
		uksort( $moved, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
		$ids = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts'      => 200,
				'fields'           => 'ids',
				'meta_key'         => Page_Scope::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => (string) $home, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'suppress_filters' => true,
			)
		);
		foreach ( array_unique( array_merge( array( $home ), array_map( 'intval', $ids ) ) ) as $id ) {
			$content = (string) get_post_field( 'post_content', $id, 'raw' );
			$updated = $whole
				? (string) preg_replace_callback(
					'#(?:' . implode( '|', array_map( static fn( string $u ): string => preg_quote( $u, '#' ), array_keys( $moved ) ) ) . ')(?![\w-])#',
					static fn( array $m ): string => $moved[ $m[0] ] ?? $m[0],
					$content
				)
				: strtr( $content, $moved );
			if ( $updated !== $content ) {
				wp_update_post(
					array(
						'ID'           => $id,
						'post_content' => wp_slash( $updated ),
					)
				);
			}
		}
		$items = get_posts(
			array(
				'post_type'        => 'nav_menu_item',
				'post_status'      => 'any',
				'numberposts'      => 500,
				'fields'           => 'ids',
				'meta_key'         => '_menu_item_url', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => array_keys( $moved ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare'     => 'IN',
				'suppress_filters' => true,
			)
		);
		foreach ( array_map( 'intval', $items ) as $item ) {
			$url = (string) get_post_meta( $item, '_menu_item_url', true );
			if ( isset( $moved[ $url ] ) ) {
				update_post_meta( $item, '_menu_item_url', esc_url_raw( $moved[ $url ] ) );
			}
		}
	}
}

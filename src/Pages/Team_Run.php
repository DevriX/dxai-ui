<?php
/**
 * What a run of Team_Pages changed, kept so that it can be given back.
 *
 * @package DXAI_UI\Pages
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

/**
 * Making the pages of a design writes more than the pages: it points the Home's menu at them and gives every page the Home's header
 * and footer as they are. Before a run starts, the content (and the records that tell a person's edit from the plugin's) of the posts it may
 * write is read; when it ends, the posts that did change are kept — the content as it was, compressed, with a hash of the content the run left —
 * on the Home (one run: the last).
 *
 * Giving the run back puts every post that is as the run left it back as it was; a page the run made is moved to the trash (never
 * deleted: where the trash is switched off it is only unpublished). A post that was changed since — a person edited the page, its words were
 * written — is left as it is and named: what was done after the run is not undone with it. A page that was there before can be given back by
 * itself; a page the run made cannot, because the Home's menu now opens it: it goes with the run.
 */
final class Team_Run {

	/** On the Home: {at, posts: {id: {created, after, content, meta}}}. */
	public const META = '_dxai_ui_team_run';

	/** The records of a team page that a run writes, besides its content. */
	private const KEYS = array( Team_Pages::HASH, Copy_Writer::HASH, Team_Pages::OPS_META, Team_Pages::AI_META, Team_Pages::ARR_META, Team_Chrome::HASH );

	/**
	 * The posts a run may write, as they are before it: the Home and the pages already made for it.
	 *
	 * @return array<int, array{content:string, meta:array<string, string|null>}>
	 */
	public static function begin( int $home ): array {
		$out = array();
		foreach ( array_merge( array( $home ), Team_Quality::page_ids( $home ) ) as $id ) {
			$id = (int) $id;
			$meta = array();
			foreach ( self::KEYS as $key ) {
				$meta[ $key ] = metadata_exists( 'post', $id, $key ) ? (string) get_post_meta( $id, $key, true ) : null;
			}
			$out[ $id ] = array(
				'content' => (string) get_post_field( 'post_content', $id ),
				'meta'    => $meta,
			);
		}

		return $out;
	}

	/**
	 * Keep what the run changed.
	 *
	 * @param array<int, array{content:string, meta:array<string, string|null>}> $before begin().
	 * @param array<int, int>                                                    $made   The pages the run made.
	 * @param array<int, string>                                                 $menus  The menu items' addresses before the run (Team_Menu::menu_urls()): the ones it pointed at a page are kept too.
	 * @return array{at:int, posts:array<int, array<string, mixed>>}
	 */
	public static function finish( int $home, array $before, array $made, array $menus = array() ): array {
		$posts = array();
		foreach ( $before as $id => $was ) {
			$now = (string) get_post_field( 'post_content', (int) $id );
			if ( $now === $was['content'] ) {
				continue;
			}
			$posts[ (int) $id ] = array(
				'created' => false,
				'after'   => md5( $now ),
				'content' => self::pack( $was['content'] ),
				'meta'    => $was['meta'],
			);
		}
		foreach ( $made as $id ) {
			$posts[ (int) $id ] = array(
				'created' => true,
				'after'   => md5( (string) get_post_field( 'post_content', (int) $id ) ),
			);
		}
		// The items of the header's menus the run pointed at a page: the address they had, and the one the run left.
		$items = array();
		foreach ( $menus as $id => $url ) {
			$now = (string) get_post_meta( (int) $id, '_menu_item_url', true );
			if ( $now !== $url ) {
				$items[ (int) $id ] = array(
					'was'   => $url,
					'after' => $now,
				);
			}
		}
		if ( $posts === array() && $items === array() ) {
			// The run changed nothing: what the last one did is still the last thing that was done.
			return self::summary( $home );
		}
		update_post_meta( $home, self::META, wp_slash( (string) wp_json_encode( array( 'at' => time(), 'posts' => $posts, 'items' => $items ), JSON_UNESCAPED_SLASHES ) ) );

		return self::summary( $home );
	}

	/**
	 * The last run, for a person to see: when, the pages it made and changed, and which of them can still be given back.
	 *
	 * @return array{at:int, pages:array<int, array<string, mixed>>, menu:bool}
	 */
	public static function summary( int $home ): array {
		$run = self::load( $home );
		if ( $run === null ) {
			return array( 'at' => 0, 'pages' => array(), 'menu' => false );
		}
		$pages = array();
		foreach ( $run['posts'] as $id => $p ) {
			if ( (int) $id === $home ) {
				continue;
			}
			$pages[] = array(
				'id'      => (int) $id,
				'title'   => trim( html_entity_decode( wp_strip_all_tags( get_the_title( (int) $id ) ), ENT_QUOTES, 'UTF-8' ) ),
				'created' => ! empty( $p['created'] ),
				'changed' => self::changed( (int) $id, $p ),
			);
		}

		return array(
			'at'    => (int) $run['at'],
			'pages' => $pages,
			'menu'  => isset( $run['posts'][ $home ] ) || $run['items'] !== array(),
		);
	}

	/**
	 * Give the last run back: every post that is as the run left it is put back as it was; the pages it made are moved to the trash.
	 *
	 * @return array{restored:array<int, int>, removed:array<int, int>, skipped:array<int, array{id:int, title:string}>}|\WP_Error
	 */
	public static function undo( int $home ) {
		$run = self::load( $home );
		if ( $run === null ) {
			return new \WP_Error( 'dxai_ui_team_run', __( 'There is nothing to give back: no pages were made here, or they were given back already.', 'dxai-ui' ), array( 'status' => 404 ) );
		}
		$out = array(
			'restored' => array(),
			'removed'  => array(),
			'skipped'  => array(),
		);
		// What was there is put back first, then what the run made goes: a page that is gone takes nothing with it.
		uasort( $run['posts'], static fn( $a, $b ) => (int) ! empty( $a['created'] ) <=> (int) ! empty( $b['created'] ) );
		foreach ( $run['posts'] as $id => $p ) {
			$id = (int) $id;
			if ( ! self::give_back( $home, $id, $p, $out ) ) {
				$out['skipped'][] = array(
					'id'    => $id,
					'title' => trim( html_entity_decode( wp_strip_all_tags( get_the_title( $id ) ), ENT_QUOTES, 'UTF-8' ) ),
				);
			}
		}
		// The menu items the run pointed at a page get their address back — unless somebody changed one since.
		foreach ( $run['items'] as $id => $p ) {
			if ( (string) get_post_meta( (int) $id, '_menu_item_url', true ) === (string) ( $p['after'] ?? '' ) ) {
				update_post_meta( (int) $id, '_menu_item_url', (string) ( $p['was'] ?? '' ) );
			}
		}
		delete_post_meta( $home, self::META );

		return $out;
	}

	/**
	 * Give one page back: a page that was there before the run is put back as it was.
	 *
	 * @return array{restored:array<int, int>, removed:array<int, int>, skipped:array<int, array{id:int, title:string}>}|\WP_Error
	 */
	public static function put_back( int $home, int $id ) {
		$run = self::load( $home );
		$p   = $run['posts'][ $id ] ?? null;
		if ( $run === null || $p === null || $id === $home ) {
			return new \WP_Error( 'dxai_ui_team_run', __( 'That page was not changed by the last run, so there is nothing to put back.', 'dxai-ui' ), array( 'status' => 404 ) );
		}
		if ( ! empty( $p['created'] ) ) {
			return new \WP_Error( 'dxai_ui_team_run_new', __( 'The run made this page, and the Home\'s menu opens it: give the whole run back to take it away.', 'dxai-ui' ), array( 'status' => 409 ) );
		}
		$out = array(
			'restored' => array(),
			'removed'  => array(),
			'skipped'  => array(),
		);
		if ( ! self::give_back( $home, $id, $p, $out ) ) {
			return new \WP_Error( 'dxai_ui_team_run_changed', __( 'The page was changed after it was made (its words were written, or someone edited it), so it is left as it is.', 'dxai-ui' ), array( 'status' => 409 ) );
		}
		unset( $run['posts'][ $id ] );
		if ( $run['posts'] === array() && $run['items'] === array() ) {
			delete_post_meta( $home, self::META );
		} else {
			update_post_meta( $home, self::META, wp_slash( (string) wp_json_encode( $run, JSON_UNESCAPED_SLASHES ) ) );
		}

		return $out;
	}

	/**
	 * @param array<string, mixed>                                                                                $p
	 * @param array{restored:array<int, int>, removed:array<int, int>, skipped:array<int, array<string, mixed>>} $out
	 */
	private static function give_back( int $home, int $id, array $p, array &$out ): bool {
		if ( get_post( $id ) === null || get_post_status( $id ) === 'trash' || self::changed( $id, $p ) ) {
			return false;
		}
		if ( ! empty( $p['created'] ) ) {
			// Never deleted: where the trash is off, the page is only unpublished. The page's content is the design's own markup:
			// it is not run through KSES on the way (wp_update_post does that for someone without unfiltered HTML).
			kses_remove_filters();
			if ( EMPTY_TRASH_DAYS > 0 ) {
				wp_trash_post( $id );
			} else {
				wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
			}
			kses_init();
			$out['removed'][] = $id;

			return true;
		}
		$was = self::unpack( (string) ( $p['content'] ?? '' ) );
		if ( $was === null ) {
			// What was kept cannot be read: the page is not written over with nothing.
			return false;
		}
		$now = (string) get_post_field( 'post_content', $id );
		Team_Menu::save( $id, $home, $now, $was );
		foreach ( (array) ( $p['meta'] ?? array() ) as $key => $value ) {
			if ( $value === null ) {
				delete_post_meta( $id, (string) $key );
			} else {
				update_post_meta( $id, (string) $key, wp_slash( (string) $value ) );
			}
		}
		$out['restored'][] = $id;

		return true;
	}

	/** Whether a post is no longer as the run left it. */
	private static function changed( int $id, array $p ): bool {
		return get_post( $id ) === null || md5( (string) get_post_field( 'post_content', $id ) ) !== (string) $p['after'];
	}

	/** @return array{at:int, posts:array<int, array<string, mixed>>, items:array<int, array<string, mixed>>}|null */
	private static function load( int $home ): ?array {
		$raw = json_decode( (string) get_post_meta( $home, self::META, true ), true );
		if ( ! is_array( $raw ) || ! is_array( $raw['posts'] ?? null ) || ( $raw['posts'] === array() && empty( $raw['items'] ) ) ) {
			return null;
		}
		$posts = array();
		foreach ( $raw['posts'] as $id => $p ) {
			if ( is_array( $p ) ) {
				$posts[ (int) $id ] = $p;
			}
		}
		$items = array();
		foreach ( (array) ( $raw['items'] ?? array() ) as $id => $p ) {
			if ( is_array( $p ) ) {
				$items[ (int) $id ] = $p;
			}
		}

		return array(
			'at'    => (int) ( $raw['at'] ?? 0 ),
			'posts' => $posts,
			'items' => $items,
		);
	}

	private static function pack( string $text ): string {
		if ( function_exists( 'gzcompress' ) ) {
			$z = gzcompress( $text, 6 );
			if ( $z !== false ) {
				return 'z:' . base64_encode( $z ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			}
		}

		return 'r:' . base64_encode( $text ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/** What was kept, or null when it cannot be read. */
	private static function unpack( string $text ): ?string {
		$raw = base64_decode( substr( $text, 2 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( $raw === false || ! in_array( substr( $text, 0, 2 ), array( 'z:', 'r:' ), true ) ) {
			return null;
		}
		if ( str_starts_with( $text, 'z:' ) ) {
			$plain = function_exists( 'gzuncompress' ) ? gzuncompress( $raw ) : false;

			return $plain === false ? null : $plain;
		}

		return $raw;
	}
}

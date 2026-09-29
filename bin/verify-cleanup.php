<?php
/**
 * Leave the site as the verify script found it.
 *
 * A check that seeds content and walks away is a check you cannot run twice
 * without reading past its own leavings. Four of these scripts between them
 * left 47 posts and 8 uploads behind on every run — a smoke page, its template
 * parts and navigations, eleven patterns, and the media three of them sideload
 * — so a clean import was only clean until the suite ran next.
 *
 * Include it, snapshot before the script does anything, clean up at the end:
 *
 *     require DXAI_UI_DIR . 'bin/verify-cleanup.php';
 *     $snapshot = dxai_verify_snapshot();
 *     … the check …
 *     dxai_verify_cleanup( $snapshot );
 *
 * The snapshot is every post id that existed, so cleanup removes exactly what
 * appeared in between whatever the script chose to create — no name matching to
 * keep in step with the fixtures.
 *
 * @package DXAI_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'dxai_verify_upload_files' ) ) {
	/**
	 * Every file under uploads, keyed by path.
	 *
	 * @return array<string, true>
	 */
	function dxai_verify_upload_files(): array {
		$base = trailingslashit( wp_upload_dir()['basedir'] );
		$out  = array();
		foreach ( array( $base . '*', $base . '*/*', $base . '*/*/*' ) as $pattern ) {
			foreach ( glob( $pattern ) ?: array() as $path ) {
				if ( is_file( $path ) ) {
					$out[ $path ] = true;
				}
			}
		}

		return $out;
	}
}

if ( ! function_exists( 'dxai_verify_snapshot' ) ) {
	/**
	 * What exists right now: every post id, and every file under uploads.
	 *
	 * The file list is there because Media\Sideloader has a fallback that
	 * writes an asset straight to disk when `media_handle_sideload()` refuses
	 * it — a `.css`, a `.woff2`, an `.svg` — and those have no attachment to
	 * delete. Four files leaked per suite run until the snapshot covered them.
	 *
	 * @return array{posts: array<int, true>, files: array<string, true>}
	 */
	function dxai_verify_snapshot(): array {
		global $wpdb;

		return array(
			'posts' => array_fill_keys(
				array_map( 'intval', (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts}" ) ),
				true
			),
			'files' => dxai_verify_upload_files(),
		);
	}
}

if ( ! function_exists( 'dxai_verify_cleanup' ) ) {
	/**
	 * Delete every post created since the snapshot, and any upload orphaned by
	 * doing so.
	 *
	 * @param array{posts: array<int, true>, files: array<string, true>} $snapshot
	 * @param bool                                                       $report Print a one-line summary.
	 */
	function dxai_verify_cleanup( array $snapshot, bool $report = true ): int {
		global $wpdb;

		$known_posts = is_array( $snapshot['posts'] ?? null ) ? $snapshot['posts'] : array();
		$known_files = is_array( $snapshot['files'] ?? null ) ? $snapshot['files'] : array();

		$removed = 0;
		// Newest first: a revision or menu item goes before its parent, so
		// deleting the parent does not strand it.
		foreach ( (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} ORDER BY ID DESC" ) as $id ) {
			$id = (int) $id;
			if ( isset( $known_posts[ $id ] ) ) {
				continue;
			}
			if ( wp_delete_post( $id, true ) ) {
				++$removed;
			}
		}

		/*
		 * Nav menus the checks wrote into theme locations. The menu posts are
		 * gone by now, but the location still names them, and a stale id there
		 * makes the next run's menu lookups behave oddly.
		 */
		$locations = get_theme_mod( 'nav_menu_locations', array() );
		$pruned    = false;
		foreach ( (array) $locations as $location => $menu_id ) {
			if ( ! isset( $known_posts[ (int) $menu_id ] ) && (int) $menu_id > 0 ) {
				unset( $locations[ $location ] );
				$pruned = true;
			}
		}
		if ( $pruned ) {
			set_theme_mod( 'nav_menu_locations', $locations );
		}

		/*
		 * Files that appeared during the run and that no surviving attachment
		 * owns: the raw-persisted assets above, and any generated size whose
		 * original was deleted before its metadata could be read. Scoped to
		 * what appeared, so a check can never remove media it did not create.
		 */
		$owned = array();
		foreach (
			get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => -1,
				)
			) as $attachment
		) {
			$file = get_attached_file( (int) $attachment->ID );
			if ( $file ) {
				$owned[ basename( $file ) ] = true;
			}
			$meta = wp_get_attachment_metadata( (int) $attachment->ID );
			foreach ( is_array( $meta ) && ! empty( $meta['sizes'] ) ? $meta['sizes'] : array() as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$owned[ (string) $size['file'] ] = true;
				}
			}
		}

		$files = 0;
		foreach ( array_keys( dxai_verify_upload_files() ) as $path ) {
			if ( isset( $known_files[ $path ] ) || isset( $owned[ basename( $path ) ] ) ) {
				continue;
			}
			wp_delete_file( $path );
			++$files;
		}

		if ( $report ) {
			echo 'cleanup_removed=' . $removed . ' files=' . $files . PHP_EOL;
		}

		return $removed;
	}
}

/*
 * Deliberately no orphaned-upload sweep here. `wp_delete_post( $id, true )`
 * already removes an attachment's file and its generated sizes, which is all
 * "leave the site as you found it" requires — and a sweep over the whole
 * uploads tree would let a check delete media it never created. A first draft
 * did exactly that: one script reported 197 files removed, none of them its
 * own. Wiping media that predates the run is the purge script's job, where
 * that is the stated intent.
 */

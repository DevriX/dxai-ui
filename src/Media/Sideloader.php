<?php
/**
 * Sideload remote or local design assets into the Media Library / uploads.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Media;

final class Sideloader {

	/**
	 * Post meta holding the SHA-1 of the bytes an attachment was made from —
	 * the file as handed to the media library, after an SVG's scrub, so a
	 * later image optimiser rewriting the stored file does not break reuse.
	 *
	 * Every import used to make a new attachment for every file. The wizard
	 * sends the same ZIP to process-source, to generate-block and again per
	 * chunk, and each request unpacks and harvests from scratch, while the
	 * harvester's own dedupe lasts one request. This install held 2666
	 * attachments tagged `_dxai_ui_asset_kind`; hashed, they are 332
	 * distinct files — 2334 redundant copies, 806 MB with their thumbnails,
	 * one logo 92 times — each a fresh upload plus resizes: disk quota,
	 * image-resize CPU and request time a shared host does not have. The key
	 * is the content, not the name: `public/favicon.png` is 57 attachments
	 * here, but several designs' different favicons among them.
	 */
	public const HASH_META = '_dxai_ui_asset_sha1';

	/**
	 * This request's stored assets by content, keyed `<blog id>:<sha1>`.
	 * The blog id keeps a request that switches sites on multisite from
	 * handing one site another site's attachment.
	 *
	 * @var array<string, array{path:string, url:string, id:int, kind:string, mime:string, original:string, filename:string}>
	 */
	private static array $by_hash = array();

	/**
	 * How long a download stands in for the next ones of the same URL.
	 *
	 * The content hash stops a remote file becoming a second attachment, but
	 * it can only be taken after the file is downloaded again — and every
	 * request of one import downloads every remote asset again: 930 of this
	 * install's 2666 plugin attachments came from 147 URLs, 715 of them from
	 * one crawled site. On a host that kills a request at 60-120 s, those
	 * round trips (90 s timeout each) are spent once in process-source, once
	 * in generate-block and once more per chunk, for bytes the site already
	 * holds. Fifteen minutes covers one import's requests. It is not
	 * refreshed on reuse, so a file that changes at its URL is served stale
	 * for at most fifteen minutes after the download it replaces.
	 */
	private const FETCH_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * @return array{path:string, url:string, id:int, kind:string, mime:string, original:string, filename:string}|\WP_Error
	 */
	public static function from_url( string $url, string $filename = '', string $original = '' ): array|\WP_Error {
		if ( ! Url_Guard::is_safe_http( $url ) ) {
			return new \WP_Error( 'dxai_ui_media', __( 'Remote asset URL is not allowed.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		$known = self::fetched( $url );
		if ( null !== $known ) {
			$name = sanitize_file_name( $filename !== '' ? $filename : wp_basename( (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?: 'dxai-asset.bin' ) ) );
			$name = '' === $name ? 'dxai-asset.bin' : $name;

			return array_merge(
				$known,
				array(
					'kind'     => self::kind( (string) pathinfo( $name, PATHINFO_EXTENSION ) ),
					'original' => $original !== '' ? $original : $url,
					'filename' => $name,
				)
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$tmp = download_url( $url, 90 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$size = filesize( $tmp );
		if ( false === $size || $size > self::max_bytes( $filename !== '' ? $filename : $url ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'dxai_ui_media', __( 'Remote asset is too large.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		if ( $filename === '' ) {
			$filename = wp_basename( (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?: 'dxai-asset.bin' ) );
		}
		if ( $filename === '' || $filename === '/' ) {
			$filename = 'dxai-asset.bin';
		}

		$stored = self::from_path( $tmp, $filename, $original !== '' ? $original : $url, true );
		if ( is_array( $stored ) && $stored['id'] > 0 ) {
			$hash = (string) get_post_meta( $stored['id'], self::HASH_META, true );
			if ( '' !== $hash ) {
				set_transient( self::fetch_key( $url ), array( 'id' => $stored['id'], 'hash' => $hash ), self::FETCH_TTL );
			}
		}

		return $stored;
	}

	/**
	 * @return array{path:string, url:string, id:int, kind:string, mime:string, original:string, filename:string}|\WP_Error
	 */
	public static function from_path( string $path, string $filename = '', string $original = '', bool $is_temp = false ): array|\WP_Error {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		if ( ! is_readable( $path ) ) {
			return new \WP_Error( 'dxai_ui_media', __( 'Asset file is not readable.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		if ( $filename === '' ) {
			$filename = basename( $path );
		}
		$filename = sanitize_file_name( $filename );
		$ext      = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		$kind     = self::kind( $ext );

		if ( 'svg' === $ext ) {
			$work = $is_temp ? $path : wp_tempnam( $filename );
			if ( ! $is_temp && ! copy( $path, $work ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
				return new \WP_Error( 'dxai_ui_media', __( 'Unable to copy SVG for sanitizing.', 'dxai-ui' ), array( 'status' => 500 ) );
			}
			if ( ! Svg_Sanitizer::clean_file( $work ) ) {
				wp_delete_file( $work );

				return new \WP_Error( 'dxai_ui_media', __( 'This SVG could not be sanitized, so it was not imported.', 'dxai-ui' ), array( 'status' => 415 ) );
			}
			$path    = $work;
			$is_temp = true;
		}

		/*
		 * Content before copy. A file this site already holds — made by an
		 * earlier request of the same import, a re-import, or another design
		 * shipping the same favicon — is returned as it is instead of being
		 * uploaded and resized again. Hashed before the temp copy, so a hit
		 * costs one read of the file and no disk write at all. The caller's
		 * own name and original are kept on the answer: the compiler maps
		 * images by both, and a stored `favicon-1.png` must still answer to
		 * `public/favicon.png`.
		 */
		$hash  = (string) sha1_file( $path );
		$known = '' !== $hash ? self::stored( $hash, $original ) : null;
		if ( null !== $known ) {
			if ( $is_temp ) {
				wp_delete_file( $path );
			}

			return array_merge(
				$known,
				array(
					'kind'     => $kind,
					'original' => $original,
					'filename' => $filename,
				)
			);
		}

		$tmp = $path;
		if ( ! $is_temp ) {
			$tmp = wp_tempnam( $filename );
			if ( ! copy( $path, $tmp ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
				return new \WP_Error( 'dxai_ui_media', __( 'Unable to copy asset for sideload.', 'dxai-ui' ), array( 'status' => 500 ) );
			}
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		$id = Mime_Policy::with_allowed(
			static function () use ( $file_array ) {
				return media_handle_sideload( $file_array, 0 );
			}
		);

		if ( is_wp_error( $id ) ) {
			$stored = self::persist_raw( $tmp, $filename, $kind );
			wp_delete_file( $tmp );
			if ( is_wp_error( $stored ) ) {
				return $id;
			}
			if ( '' !== $hash ) {
				self::$by_hash[ self::memo_key( $hash ) ] = $stored;
			}
			$stored['original'] = $original;
			return $stored;
		}

		$attachment = array(
			'id'       => (int) $id,
			'url'      => (string) wp_get_attachment_url( (int) $id ),
			'path'     => (string) get_attached_file( (int) $id ),
			'kind'     => $kind,
			'mime'     => (string) get_post_mime_type( (int) $id ),
			'original' => $original,
			'filename' => $filename,
		);
		update_post_meta( (int) $id, '_dxai_ui_asset_kind', $kind );
		if ( $original !== '' ) {
			update_post_meta( (int) $id, '_dxai_ui_asset_original', $original );
		}
		if ( '' !== $hash ) {
			update_post_meta( (int) $id, self::HASH_META, $hash );
			self::$by_hash[ self::memo_key( $hash ) ] = $attachment;
		}

		return $attachment;
	}

	/**
	 * @return array{path:string, url:string, id:int, kind:string, mime:string, original:string, filename:string}|\WP_Error
	 */
	public static function persist_raw( string $tmp, string $filename, string $kind = 'other' ): array|\WP_Error {
		/*
		 * Media only. This is the fallback for a file the media library
		 * refused, and it copied ANY file — every local href/src/url() a design
		 * references reaches it through Asset_Harvester — so a design's script
		 * sources and linked HTML pages were published from the site's own
		 * origin (this install: 166 .js and 140 .tsx files in uploads). That
		 * leaks the design's source, API endpoints included, and serves
		 * attacker-written HTML same-origin. Images, video, audio, fonts and
		 * Lottie JSON are what a page can use; an SVG is scrubbed first.
		 */
		$ext_raw = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( ! in_array( self::kind( $ext_raw ), array( 'image', 'video', 'audio', 'font', 'lottie' ), true ) ) {
			return new \WP_Error( 'dxai_ui_media', __( 'This kind of file is not stored as a design asset.', 'dxai-ui' ), array( 'status' => 415 ) );
		}
		if ( $ext_raw === 'svg' && ! Svg_Sanitizer::clean_file( $tmp ) ) {
			return new \WP_Error( 'dxai_ui_media', __( 'Unable to sanitize SVG.', 'dxai-ui' ), array( 'status' => 415 ) );
		}

		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'dxai_ui_media', (string) $upload['error'], array( 'status' => 500 ) );
		}

		$dir = trailingslashit( $upload['basedir'] ) . 'dxai-ui/assets';
		wp_mkdir_p( $dir );
		$safe = sanitize_file_name( $filename );
		$hash = sha1_file( $tmp );
		if ( ! is_string( $hash ) ) {
			return new \WP_Error( 'dxai_ui_media', __( 'Unable to read design asset.', 'dxai-ui' ), array( 'status' => 500 ) );
		}

		/*
		 * Named by content, not by time. The prefix was md5 of the temp path
		 * and microtime(), so every request stored another copy: this
		 * install's dxai-ui/assets holds 309 files, 11.2 MB, with 2 distinct
		 * contents among them (script sources from before the media-only
		 * rule above). Now the same bytes under the same name land on the
		 * same file, and a request that finds it there — its SHA-1 checked,
		 * not assumed from the name — copies nothing. The short prefix keeps
		 * URLs readable; in the astronomically unlikely case that another file
		 * already holds that name, the full hash is used rather than
		 * overwriting what a published page may point at.
		 */
		$name = '';
		foreach ( array( substr( $hash, 0, 12 ), $hash ) as $prefix ) {
			$candidate = $prefix . '-' . $safe;
			$dest      = $dir . '/' . $candidate;
			if ( is_file( $dest ) ) {
				if ( hash_equals( $hash, (string) sha1_file( $dest ) ) ) {
					$name = $candidate;
					break;
				}
				continue;
			}
			if ( ! self::write_whole( $tmp, $dest, $hash ) ) {
				return new \WP_Error( 'dxai_ui_media', __( 'Unable to store design asset.', 'dxai-ui' ), array( 'status' => 500 ) );
			}
			$name = $candidate;
			break;
		}
		if ( '' === $name ) {
			return new \WP_Error( 'dxai_ui_media', __( 'Unable to store design asset.', 'dxai-ui' ), array( 'status' => 500 ) );
		}
		$dest = $dir . '/' . $name;

		$ext  = strtolower( (string) pathinfo( $safe, PATHINFO_EXTENSION ) );
		$mime = Mime_Policy::extra()[ $ext ] ?? 'application/octet-stream';

		return array(
			'id'       => 0,
			'url'      => trailingslashit( $upload['baseurl'] ) . 'dxai-ui/assets/' . $name,
			'path'     => $dest,
			'kind'     => $kind,
			'mime'     => $mime,
			'original' => '',
			'filename' => $safe,
		);
	}

	public static function kind( string $ext ): string {
		$ext = strtolower( $ext );
		return match ( true ) {
			in_array( $ext, array( 'png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'avif', 'ico', 'bmp' ), true ) => 'image',
			in_array( $ext, array( 'mp4', 'webm', 'ogv', 'mov', 'm4v' ), true ) => 'video',
			in_array( $ext, array( 'mp3', 'wav', 'ogg', 'm4a' ), true ) => 'audio',
			in_array( $ext, array( 'woff', 'woff2', 'ttf', 'otf', 'eot' ), true ) => 'font',
			$ext === 'json' => 'lottie',
			default => 'other',
		};
	}

	/**
	 * Groups of this plugin's attachments that hold the same bytes, largest
	 * group first — for a cleanup tool to act on. Read-only: nothing is
	 * written, merged or deleted here.
	 *
	 * Attachments stamped with HASH_META are grouped from the meta alone.
	 * Ones made before the hash was stored are hashed from their original
	 * file, at most $max_files of them per call, oldest first after
	 * $after_id, so a web request on a shared host stays inside its time
	 * limit; page through with the highest id seen. A group whose members
	 * span the window only shows the members inside it.
	 *
	 * @return array<int, array{hash:string, ids:array<int, int>}>
	 */
	public static function duplicate_groups( int $max_files = 200, int $after_id = 0 ): array {
		global $wpdb;

		$by_hash = array();
		$stamped = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_type = 'attachment'",
				self::HASH_META
			)
		);
		foreach ( $stamped as $row ) {
			$by_hash[ (string) $row->meta_value ][] = (int) $row->post_id;
		}

		$legacy = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT k.post_id FROM {$wpdb->postmeta} k LEFT JOIN {$wpdb->postmeta} h ON h.post_id = k.post_id AND h.meta_key = %s
				WHERE k.meta_key = '_dxai_ui_asset_kind' AND h.meta_id IS NULL AND k.post_id > %d ORDER BY k.post_id ASC LIMIT %d",
				self::HASH_META,
				$after_id,
				max( 0, $max_files )
			)
		);
		foreach ( $legacy as $id ) {
			$file = self::source_file( (int) $id );
			$hash = '' !== $file && is_readable( $file ) ? sha1_file( $file ) : false;
			if ( is_string( $hash ) ) {
				$by_hash[ $hash ][] = (int) $id;
			}
		}

		$groups = array();
		foreach ( $by_hash as $hash => $ids ) {
			if ( count( $ids ) < 2 ) {
				continue;
			}
			sort( $ids );
			$groups[] = array(
				'hash' => (string) $hash,
				'ids'  => $ids,
			);
		}
		usort(
			$groups,
			static fn( array $a, array $b ): int => count( $b['ids'] ) <=> count( $a['ids'] )
		);

		return $groups;
	}

	/**
	 * The asset this site already stores for these bytes, or null.
	 *
	 * One indexed meta lookup per distinct file per request — ids only, one
	 * row, no row count — and the answer is kept for the rest of the request,
	 * so the second copy of a favicon inside the same ZIP costs nothing.
	 * Newest first: when an older match has lost its file, the replacement
	 * made just after it is the one found next time, not the dead one again.
	 *
	 * @return array{path:string, url:string, id:int, kind:string, mime:string, original:string, filename:string}|null
	 */
	private static function stored( string $hash, string $original ): ?array {
		$key = self::memo_key( $hash );
		if ( isset( self::$by_hash[ $key ] ) ) {
			return self::$by_hash[ $key ];
		}

		$query = array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'orderby'                => 'ID',
			'order'                  => 'DESC',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'suppress_filters'       => true,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => self::HASH_META,
					'value'   => $hash,
					'compare' => '=',
				),
			),
		);
		$ids = get_posts( $query );
		$id  = (int) ( $ids[0] ?? 0 );
		if ( $id > 0 && self::is_live( $id ) ) {
			self::$by_hash[ $key ] = self::describe( $id );

			return self::$by_hash[ $key ];
		}

		/*
		 * Attachments made before the hash was stored. Duplicates come from
		 * re-importing the same design, so the same original is where an
		 * unstamped twin is; the newest three are checked byte for byte and
		 * the first that matches is stamped and reused. Without this, the
		 * first import after the upgrade would add a 58th favicon beside the
		 * 57 that are already there.
		 */
		if ( '' === $original ) {
			return null;
		}
		$query['posts_per_page'] = 3;
		$query['meta_query']     = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array(
				'key'     => '_dxai_ui_asset_original',
				'value'   => $original,
				'compare' => '=',
			),
		);
		foreach ( get_posts( $query ) as $legacy ) {
			$legacy = (int) $legacy;
			if ( '' !== (string) get_post_meta( $legacy, self::HASH_META, true ) || ! self::is_live( $legacy ) ) {
				continue;
			}
			$file = self::source_file( $legacy );
			if ( '' === $file || ! is_readable( $file ) || ! hash_equals( $hash, (string) sha1_file( $file ) ) ) {
				continue;
			}
			update_post_meta( $legacy, self::HASH_META, $hash );
			self::$by_hash[ $key ] = self::describe( $legacy );

			return self::$by_hash[ $key ];
		}

		return null;
	}

	/**
	 * Whether an attachment can still be served. Its file on disk is the
	 * answer on most sites. With uploads offloaded to a bucket or CDN the
	 * local copy may be gone on purpose; the offload plugin then points
	 * wp_get_attachment_url() off the uploads URL, and that is taken as
	 * live. A file missing while the URL still points into local uploads is
	 * a 404, and the asset is uploaded again. Compared without the scheme,
	 * so a site moved from http to https still matches its own uploads.
	 */
	private static function is_live( int $id ): bool {
		$file = get_attached_file( $id );
		if ( is_string( $file ) && '' !== $file && file_exists( $file ) ) {
			return true;
		}

		$relative = (string) get_post_meta( $id, '_wp_attached_file', true );
		$url      = (string) wp_get_attachment_url( $id );
		if ( '' === $relative || path_is_absolute( $relative ) || '' === $url ) {
			return false;
		}
		$uploads = wp_upload_dir( null, false );
		$local   = (string) preg_replace( '#^https?:#i', '', trailingslashit( (string) $uploads['baseurl'] ) );

		return ! str_starts_with( (string) preg_replace( '#^https?:#i', '', $url ), $local );
	}

	/**
	 * The stored file holding the bytes that were uploaded. For a large or
	 * rotated image WordPress serves a `-scaled` / `-rotated` copy and keeps
	 * the upload as `original_image`; that one is what the hash describes.
	 */
	private static function source_file( int $id ): string {
		$file = wp_attachment_is_image( $id ) ? wp_get_original_image_path( $id ) : get_attached_file( $id );

		return is_string( $file ) ? $file : '';
	}

	/**
	 * @return array{path:string, url:string, id:int, kind:string, mime:string, original:string, filename:string}
	 */
	private static function describe( int $id ): array {
		return array(
			'id'       => $id,
			'url'      => (string) wp_get_attachment_url( $id ),
			'path'     => (string) get_attached_file( $id ),
			'kind'     => (string) get_post_meta( $id, '_dxai_ui_asset_kind', true ),
			'mime'     => (string) get_post_mime_type( $id ),
			'original' => '',
			'filename' => '',
		);
	}

	private static function memo_key( string $hash ): string {
		return get_current_blog_id() . ':' . $hash;
	}

	/**
	 * The attachment a recent download of this URL became, or null.
	 *
	 * Checked, not trusted: the attachment must still carry the hash that
	 * was recorded with it and still be servable, so one deleted, replaced
	 * or offloaded since is simply downloaded again. Attachments only —
	 * a raw copy has no row to check against and is rare enough to fetch.
	 * A transient, so it is per site on multisite and lives in the object
	 * cache where there is one.
	 *
	 * @return array{path:string, url:string, id:int, kind:string, mime:string, original:string, filename:string}|null
	 */
	private static function fetched( string $url ): ?array {
		$hit = get_transient( self::fetch_key( $url ) );
		if ( ! is_array( $hit ) ) {
			return null;
		}
		$id   = (int) ( $hit['id'] ?? 0 );
		$hash = (string) ( $hit['hash'] ?? '' );
		if ( $id < 1 || '' === $hash || ! hash_equals( $hash, (string) get_post_meta( $id, self::HASH_META, true ) ) || ! self::is_live( $id ) ) {
			return null;
		}
		// The same rule stored() applies: an attachment in the trash (with
		// MEDIA_TRASH on) still has its meta and its file, and handing it out
		// would break the page once the trash is emptied.
		if ( 'attachment' !== get_post_type( $id ) || 'inherit' !== get_post_status( $id ) ) {
			return null;
		}
		$key = self::memo_key( $hash );
		if ( ! isset( self::$by_hash[ $key ] ) ) {
			self::$by_hash[ $key ] = self::describe( $id );
		}

		return self::$by_hash[ $key ];
	}

	private static function fetch_key( string $url ): string {
		return 'dxai_ui_fetch_' . md5( $url );
	}

	/**
	 * Copy to a sibling temp name, then rename into place. Hosts kill a web
	 * request after 60-120 s; a copy cut off there used to leave a truncated
	 * font under its final name, and now that the name is reused rather than
	 * regenerated, a truncated file would be served for good. rename() in
	 * one directory is atomic, so the name holds the whole file or nothing.
	 */
	private static function write_whole( string $from, string $dest, string $hash ): bool {
		$part = $dest . '.part-' . wp_generate_password( 8, false );
		if ( ! copy( $from, $part ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			wp_delete_file( $part );

			return false;
		}
		if ( rename( $part, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			return true;
		}
		wp_delete_file( $part );

		// A parallel request of the same import may have won the rename.
		return is_file( $dest ) && hash_equals( $hash, (string) sha1_file( $dest ) );
	}

	private static function max_bytes( string $name ): int {
		$ext = strtolower( (string) pathinfo( wp_parse_url( $name, PHP_URL_PATH ) ?: $name, PATHINFO_EXTENSION ) );
		return match ( self::kind( $ext ) ) {
			'video' => 40 * 1024 * 1024,
			'font'  => 8 * 1024 * 1024,
			'audio' => 12 * 1024 * 1024,
			default => 15 * 1024 * 1024,
		};
	}
}

<?php
/**
 * The sizes WordPress makes of a picture, made after an import in steps rather than while it reads the archive.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Media;

/**
 * Every picture the media library takes is cut into each size the site registers — thirteen on American Restoration (WordPress's
 * six and the theme's seven `dx_pic_*`) — and those sizes, written as WebP, were 85% of the time an import spent reading its
 * archive. Charter Construction's 413 pictures took 20 minutes in the one request that reads the archive, and WP Engine ends a
 * request after 60 seconds. The imported pages do not wait for the sizes: they name the files themselves (the srcset WordPress
 * adds from the sizes is a lighter alternative, not the picture). So an import that runs in steps (Converter_Controller's
 * read in rounds) adds each picture without them (defer()), marks it (META), and the sizes are made afterwards, as many as fit
 * in a step: by the import screen once the pages are saved (run()), by cron otherwise (HOOK) — the same sizes, at the quality
 * Image_Quality fits them to, as if they had been made at once.
 */
final class Pending_Sizes {

	/** Post meta on an attachment added without its sizes. */
	public const META = '_dxai_ui_sizes_pending';

	/** Cron: the next step, when no screen is making them. */
	public const HOOK = 'dxai_ui_pending_sizes';

	/** Seconds one step may spend: a third of WP Engine's 60. */
	public const BUDGET = 20.0;

	/** Whether the pictures added now are added without their sizes. */
	private static bool $deferring = false;

	public function register(): void {
		add_action( self::HOOK, array( self::class, 'tick' ) );
	}

	/**
	 * Add pictures without their sizes while $work runs (the attachments it makes are marked by mark()).
	 *
	 * @template T
	 * @param callable(): T $work
	 * @return T
	 */
	public static function defer( callable $work ): mixed {
		$was             = self::$deferring;
		self::$deferring = true;
		add_filter( 'intermediate_image_sizes_advanced', array( self::class, 'none' ), 999 );
		try {
			return $work();
		} finally {
			self::$deferring = $was;
			if ( ! $was ) {
				remove_filter( 'intermediate_image_sizes_advanced', array( self::class, 'none' ), 999 );
			}
		}
	}

	/** Whether pictures are being added without their sizes now. */
	public static function deferring(): bool {
		return self::$deferring;
	}

	/**
	 * No sizes (the `intermediate_image_sizes_advanced` filter while defer() runs).
	 *
	 * @param mixed $sizes The sizes WordPress would make.
	 * @return array<string, mixed>
	 */
	public static function none( $sizes ): array {
		return array();
	}

	/**
	 * An attachment added while deferring: its sizes wait, and cron is asked to make them should nobody else. Only a picture
	 * WordPress cuts into sizes — an SVG is an image to wp_attachment_is_image() and has none.
	 */
	public static function mark( int $id ): void {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$file = $id > 0 ? (string) get_attached_file( $id ) : '';
		if ( '' === $file || ! file_is_displayable_image( $file ) ) {
			return;
		}
		update_post_meta( $id, self::META, '1' );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	/** Attachments still waiting for their sizes. */
	public static function count(): int {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);

		return count( $ids );
	}

	/**
	 * Make the sizes of the attachments waiting for them until $budget seconds are spent (at least one; 0: all of them).
	 *
	 * @return array{made:int, left:int} Attachments done in this step, and still waiting.
	 */
	public static function run( float $budget = self::BUDGET ): array {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$start = microtime( true );
		$made  = 0;
		do {
			$ids = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'fields'         => 'ids',
					'posts_per_page' => 20,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'no_found_rows'  => true,
					'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				)
			);
			foreach ( $ids as $id ) {
				self::make( (int) $id );
				++$made;
				if ( $budget > 0 && microtime( true ) - $start >= $budget ) {
					break 2;
				}
			}
		} while ( $ids !== array() );

		return array(
			'made' => $made,
			'left' => self::count(),
		);
	}

	/** Cron's step: as many as fit, and the next step scheduled while any are left. */
	public static function tick(): void {
		$done = self::run();
		if ( $done['left'] > 0 && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	/**
	 * One attachment's sizes, as the media library makes them (wp_update_image_subsizes(): the ones it lacks), fitted to the
	 * quality Image_Quality asks for. The mark goes first, so a picture whose sizes cannot be made is not tried at every step.
	 */
	private static function make( int $id ): void {
		delete_post_meta( $id, self::META );
		$file = (string) get_attached_file( $id );
		if ( '' === $file || ! is_readable( $file ) ) {
			return;
		}
		$meta = wp_update_image_subsizes( $id );
		if ( is_wp_error( $meta ) ) {
			return;
		}
		Image_Quality::fit_or_queue( $id );
	}
}

<?php
/**
 * Where a design's document is kept: on its Home.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Design;

use DXAI_UI\Structures\Design_Attach;
use DXAI_UI\Structures\Page_Scope;

/**
 * The document is one post meta on the design's Home, as JSON (a few tens of KB: sections are summaries, not markup). It is
 * written at the end of every save of the design (Structure_Repository::save() → record()) and read by the REST route, the
 * command and whatever reads the design instead of its blocks.
 *
 * record() never fails an import: a document that cannot be built leaves a note in META_ERROR and the import goes on. The
 * meta is the plugin's own state: uninstall leaves it with the page, as it leaves every page.
 */
final class Document_Store {

	public const META = '_dxai_ui_design_document';

	/** Why the last record() wrote nothing, when it did not. */
	public const META_ERROR = '_dxai_ui_design_document_error';

	/**
	 * Build the document of a saved design and keep it. Called by Structure_Repository::save() with its own answer; a caller
	 * that saves another way (a crawl's end, a restore) may call it the same way.
	 *
	 * @param array<string, mixed> $result
	 * @param array<string, mixed> $created
	 */
	public static function record( array $result, array $created ): ?Document {
		$home = (int) ( $created['page_id'] ?? 0 );
		if ( $home < 1 ) {
			return null;
		}
		try {
			$doc      = Document_Builder::from_result( $result, $created );
			$problems = Document::problems( $doc->to_array() );
			if ( $problems !== array() ) {
				update_post_meta( $home, self::META_ERROR, wp_slash( implode( '; ', array_slice( $problems, 0, 6 ) ) ) );

				return null;
			}
			self::save( $home, $doc );

			return $doc;
		} catch ( \Throwable $e ) {
			update_post_meta( $home, self::META_ERROR, wp_slash( get_class( $e ) . ': ' . $e->getMessage() ) );

			return null;
		}
	}

	/**
	 * Build the document of a design imported before documents were kept, from its conversion snapshot: every save keeps the
	 * compile result and its own answer (Structure_Repository::save_conversion()), which is what record() reads. The latest
	 * snapshot of the design's Home is used; a design that has none (made another way) cannot be rebuilt.
	 */
	public static function rebuild( int $page_id ): Document|\WP_Error {
		$home = self::home_of( $page_id );
		if ( $home < 1 ) {
			return new \WP_Error( 'dxai_ui_no_design', __( 'No design has a page with this id.', 'dxai-ui' ) );
		}
		$snapshots = get_posts(
			array(
				'post_type'      => \DXAI_UI\Content\Content_Types::CONVERSION,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'meta_key'       => '_dxai_ui_page_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $home, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$snapshot  = $snapshots[0] ?? null;
		$payload   = $snapshot instanceof \WP_Post ? json_decode( (string) $snapshot->post_content, true ) : null;
		if ( ! is_array( $payload ) || ! is_array( $payload['result'] ?? null ) ) {
			return new \WP_Error( 'dxai_ui_no_snapshot', __( 'The design has no conversion snapshot to build its document from: import it again to get one.', 'dxai-ui' ) );
		}
		$created            = is_array( $payload['created'] ?? null ) ? $payload['created'] : array();
		$created['page_id'] = $home;
		$doc                = self::record( $payload['result'], $created );
		if ( $doc === null ) {
			return new \WP_Error( 'dxai_ui_no_document', self::error( $home ) !== '' ? self::error( $home ) : __( 'The document could not be built.', 'dxai-ui' ) );
		}

		return $doc;
	}

	public static function save( int $home, Document $doc ): bool {
		if ( $home < 1 ) {
			return false;
		}
		delete_post_meta( $home, self::META_ERROR );
		$json = $doc->to_json();
		$kept = get_post_meta( $home, self::META, true );
		if ( is_string( $kept ) && $kept === $json ) {
			return true;
		}

		return (bool) update_post_meta( $home, self::META, wp_slash( $json ) );
	}

	/** The document of a design, by its Home or by any page of it; null when none is kept. */
	public static function load( int $page_id ): ?Document {
		$home = self::home_of( $page_id );
		if ( $home < 1 ) {
			return null;
		}
		$json = get_post_meta( $home, self::META, true );
		if ( ! is_string( $json ) || $json === '' ) {
			return null;
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || (int) ( $data['v'] ?? 0 ) !== Document::VERSION ) {
			return null;
		}

		return Document::from_array( self::with_ids( $data, $home ) );
	}

	/**
	 * The pages' post ids made true for this site: a document carried over in a transfer package names the posts of the site it
	 * came from, so each page's id is looked up again by its address among the pages of the design here (the ids are annotations,
	 * never the key; nothing is written back).
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private static function with_ids( array $data, int $home ): array {
		$pages = is_array( $data['pages'] ?? null ) ? $data['pages'] : array();
		if ( $pages === array() ) {
			return $data;
		}
		$by_path = null;
		foreach ( $pages as $i => $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$id   = (int) ( $page['id'] ?? 0 );
			$slug = (string) ( $page['slug'] ?? '' );
			$ok   = $id > 0 && get_post_type( $id ) === 'page' && get_post_status( $id ) !== false
				&& ( $id === $home || (int) get_post_meta( $id, Page_Scope::META, true ) === $home );
			if ( $ok ) {
				continue;
			}
			if ( $by_path === null ) {
				$by_path = array( (string) ( $data['home_slug'] ?? '/' ) => $home );
				$ids     = get_posts(
					array(
						'post_type'      => 'page',
						'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
						'fields'         => 'ids',
						'posts_per_page' => 200,
						'meta_key'       => Page_Scope::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_value'     => (string) $home, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					)
				);
				foreach ( $ids as $pid ) {
					if ( (int) $pid !== $home ) {
						$by_path[ '/' . trim( (string) get_page_uri( (int) $pid ), '/' ) . '/' ] = (int) $pid;
					}
				}
			}
			$data['pages'][ $i ]['id'] = (int) ( $by_path[ $slug ] ?? 0 );
		}

		return $data;
	}

	/** The note of the last record() that wrote nothing, '' when the last one wrote. */
	public static function error( int $page_id ): string {
		$home = self::home_of( $page_id );

		return $home > 0 ? (string) get_post_meta( $home, self::META_ERROR, true ) : '';
	}

	public static function forget( int $home ): void {
		foreach ( array( self::META, self::META_ERROR ) as $key ) {
			if ( $home > 0 ) {
				delete_post_meta( $home, $key );
			}
		}
	}

	/** The design's Home for a page of it (its scope), 0 when the page is no design's. */
	public static function home_of( int $page_id ): int {
		if ( $page_id < 1 ) {
			return 0;
		}
		$scope = (int) get_post_meta( $page_id, Page_Scope::META, true );
		$home  = $scope > 0 ? $scope : $page_id;

		return Design_Attach::is_design( $home ) ? $home : 0;
	}
}

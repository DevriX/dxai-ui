<?php
/**
 * Synced pattern persistence.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Patterns;

use DXAI_UI\Compiler\Gutenberg_Mapper;
use DXAI_UI\Compiler\Tailwind_Purger;
use DXAI_UI\Support\Upload_Paths;

final class Pattern_Repository {

	/** Which import slot a generated pattern belongs to; see key(). */
	public const KEY_META = '_dxai_ui_pattern_key';

	/**
	 * A generated pattern's identity, so a re-import updates it in place.
	 *
	 * Without one, save() inserted a new wp_block and wrote a new
	 * `pattern-{id}.css` on every run, and nothing ever removed the old ones:
	 * this install holds 966 generated patterns and 1478 pattern sheets, 511
	 * of which belong to no post at all. A re-import of the same archive is
	 * meant to be idempotent — Structure_Repository keys pages and template
	 * parts for exactly that — and its patterns were the part that was not.
	 *
	 * The identity is the page's own (Structure_Repository's
	 * `_dxai_ui_page_key`, archive + route slug, so two routes of one archive
	 * and two archives with the same route stay apart), the structure type,
	 * and the pattern's 1-based position among that page's structures of the
	 * type. Position rather than title: the compiler's titles repeat — most
	 * sections are titled after their type — and a title can change between
	 * runs of the same design where the order does not.
	 *
	 * @param string $context The page's identity; '' when there is none.
	 * @param string $type    Structure type: header, footer, hero, section, …
	 * @param int    $slot    1-based position among the page's structures of $type.
	 * @return string '' when there is no context, which makes save() insert as it always did.
	 */
	public static function key( string $context, string $type, int $slot ): string {
		$context = trim( $context );
		if ( $context === '' ) {
			return '';
		}

		return $context . '|' . sanitize_key( $type !== '' ? $type : 'section' ) . '-' . max( 1, $slot );
	}

	/**
	 * @param string $key Identity from key(). A pattern already saved under it
	 *                    is updated in place — same id, same sheet file, its
	 *                    publish status left as it is. '' inserts a new
	 *                    pattern, which is what every caller that passes only
	 *                    the first six arguments still gets.
	 */
	public function save( string $title, string $markup, string $css = '', bool $synced = true, string $raw_css = '', string $tailwind_config = '', string $key = '' ): array|\WP_Error {
		$mapper = new Gutenberg_Mapper();
		$valid  = $mapper->validate( $markup );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$meta = array(
			'_dxai_ui_generated' => '1',
		);
		if ( ! $synced ) {
			$meta['wp_pattern_sync_status'] = 'unsynced';
		}
		if ( $key !== '' ) {
			$meta[ self::KEY_META ] = $key;
		}

		$existing = $key !== '' ? $this->find( $key ) : 0;
		if ( $existing > 0 ) {
			// The id is known up front, so the scope is wrapped before the one
			// write instead of after an insert.
			$wrapped = $mapper->wrap_scope( $valid, $existing );
			$updated = wp_update_post(
				array(
					'ID'           => $existing,
					'post_title'   => wp_slash( sanitize_text_field( $title ) ),
					'post_content' => wp_slash( $wrapped ),
					'meta_input'   => $meta,
				),
				true
			);
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
			$id = $existing;
			if ( $synced ) {
				delete_post_meta( $id, 'wp_pattern_sync_status' );
			}
		} else {
			$id = wp_insert_post(
				array(
					'post_type'    => 'wp_block',
					'post_status'  => 'publish',
					'post_title'   => wp_slash( sanitize_text_field( $title ) ),
					'post_content' => wp_slash( $valid ),
					'meta_input'   => $meta,
				),
				true
			);

			if ( is_wp_error( $id ) ) {
				return $id;
			}

			$id      = (int) $id;
			$wrapped = $mapper->wrap_scope( $valid, $id );
			wp_update_post(
				array(
					'ID'           => $id,
					'post_content' => wp_slash( $wrapped ),
				)
			);
		}

		$purger = new Tailwind_Purger();
		$sheet  = $purger->compile( $wrapped, $css, $id, '', $raw_css, '', $tailwind_config );
		$url    = $purger->persist( $id, $sheet );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		// Relative to uploads (`dxai-ui/pattern-{id}.css`), not the absolute
		// URL persist() returns — see Upload_Paths.
		update_post_meta( $id, '_dxai_ui_css_url', Upload_Paths::for_storage( $url ) );

		return $this->prepare( $id );
	}

	/**
	 * The generated pattern saved under a key, or 0. Any status but trash: a
	 * pattern someone set to draft is still this slot's, while one in the
	 * trash was thrown away, and the import makes a fresh one.
	 */
	private function find( string $key ): int {
		$ids = get_posts(
			array(
				'post_type'      => 'wp_block',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_key'       => self::KEY_META,
				'meta_value'     => $key,
			)
		);

		return isset( $ids[0] ) ? (int) $ids[0] : 0;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list(): array {
		$query = new \WP_Query(
			array(
				'post_type'      => 'wp_block',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'meta_query'     => array(
					array(
						'key'   => '_dxai_ui_generated',
						'value' => '1',
					),
				),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$items[] = $this->prepare( $post->ID );
			}
		}

		return $items;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function prepare( int $id ): array {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		return array(
			'id'      => $id,
			'title'   => $post->post_title,
			'type'    => 'wp_block',
			'content' => $post->post_content,
			// Always a full URL for the REST client, whichever form is stored.
			'css'     => Upload_Paths::for_meta( $id, '_dxai_ui_css_url' )['url'],
			'date'    => get_date_from_gmt( $post->post_date_gmt, 'c' ),
			'edit'    => get_edit_post_link( $id, 'raw' ),
		);
	}
}

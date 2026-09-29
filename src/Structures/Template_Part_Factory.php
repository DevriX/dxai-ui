<?php
/**
 * Block-theme template parts (header / footer) with revisions.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Structures;

final class Template_Part_Factory {

	/**
	 * The slug a part of this area is saved and referenced under.
	 *
	 * Never a theme's own header/footer slug — always `dxai-` prefixed. One
	 * function for both save() and reference_markup(), and for
	 * Structure_Repository when it decides which old parts to retire, so the
	 * three cannot disagree about a name.
	 */
	public static function slug( string $area, string $key = '' ): string {
		$area = in_array( $area, array( 'header', 'footer' ), true ) ? $area : 'uncategorized';
		$slug = 'dxai-' . $area;
		// A key that is already a clean slug is used as it is: sanitize_title()
		// is filterable, and a plugin hooked to it must not be able to make
		// save() and reference_markup() disagree with an earlier import.
		$key = preg_match( '/^[a-z0-9-]+$/', $key ) === 1 ? $key : sanitize_title( $key );
		if ( $key !== '' && $key !== $slug ) {
			$slug .= '-' . $key;
		}

		return $slug;
	}

	/**
	 * @param array<string, string> $meta Extra post meta — the archive and key the part belongs to.
	 */
	public function save( string $area, string $title, string $markup, string $key = '', array $meta = array() ): int {
		$area  = in_array( $area, array( 'header', 'footer' ), true ) ? $area : 'uncategorized';
		$slug  = self::slug( $area, $key );
		$theme = get_stylesheet();

		$existing = get_posts(
			array(
				'post_type'      => 'wp_template_part',
				'name'           => $slug,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'tax_query'      => array(
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => $theme,
					),
				),
			)
		);
		/*
		 * A part this plugin wrote under another theme. The lookup above only
		 * sees the active theme's parts, so after a theme switch a re-import
		 * inserted a second post under the same slug — which WordPress renames
		 * with a suffix, and every reference then pointed at the stale one. It
		 * is adopted instead and re-tagged below.
		 */
		if ( ! isset( $existing[0] ) ) {
			$existing = get_posts(
				array(
					'post_type'      => 'wp_template_part',
					'name'           => $slug,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'meta_key'       => '_dxai_ui_generated',
					'meta_value'     => '1',
				)
			);
		}

		$data = array(
			'post_type'    => 'wp_template_part',
			'post_status'  => 'publish',
			'post_title'   => wp_slash( $title ),
			'post_name'    => $slug,
			'post_content' => wp_slash( $markup ),
			'meta_input'   => array_merge(
				array_map( 'strval', $meta ),
				array(
					'_dxai_ui_generated' => '1',
					'_dxai_ui_area'      => $area,
				)
			),
		);

		if ( isset( $existing[0] ) && $existing[0] instanceof \WP_Post ) {
			$data['ID'] = $existing[0]->ID;
			$id         = wp_update_post( $data, true );
		} else {
			$id = wp_insert_post( $data, true );
		}

		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}

		$id = (int) $id;
		if ( taxonomy_exists( 'wp_theme' ) ) {
			wp_set_post_terms( $id, $theme, 'wp_theme' );
		}
		if ( taxonomy_exists( 'wp_template_part_area' ) ) {
			wp_set_post_terms( $id, $area, 'wp_template_part_area' );
		}

		return $id;
	}

	public function reference_markup( string $area, string $key = '' ): string {
		$slug  = self::slug( $area, $key );
		$theme = get_stylesheet();
		$json  = wp_json_encode(
			array(
				'slug'  => $slug,
				'theme' => $theme,
				'area'  => $area,
			)
		);

		return sprintf( '<!-- wp:template-part %s /-->', is_string( $json ) ? $json : '{}' );
	}
}

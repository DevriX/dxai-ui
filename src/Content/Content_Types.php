<?php
/**
 * Conversion and form-entry post types (revisions on conversions).
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Content;

final class Content_Types {

	public const CONVERSION = 'dxai_conversion';
	public const FORM_ENTRY = 'dxai_form_entry';

	public function register(): void {
		add_action( 'init', array( $this, 'post_types' ) );
		add_action( 'init', array( $this, 'menus' ) );
	}

	/**
	 * Every capability of both post types, mapped to manage_options.
	 *
	 * A conversion holds a design's whole source and compile result, and
	 * restore() writes pages and JavaScript from it; a form entry holds what a
	 * visitor submitted. Both used capability_type 'post', so any Contributor
	 * could create conversions and read entries, and an Editor could edit
	 * other people's conversions — which restore() then fed straight into an
	 * import. Only the people who can run an import may touch either.
	 *
	 * @return array<string, string>
	 */
	private static function admin_only(): array {
		return array_fill_keys(
			array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'edit_private_posts', 'edit_published_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'create_posts' ),
			'manage_options'
		);
	}

	public function post_types(): void {
		register_post_type(
			self::CONVERSION,
			array(
				'labels'              => array(
					'name'          => __( 'DXAI Conversions', 'dxai-ui' ),
					'singular_name' => __( 'DXAI Conversion', 'dxai-ui' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => false,
				/*
				 * Not in the core REST API. It was, and core lets anyone read a
				 * published item of a REST post type: /wp-json/wp/v2/dxai_conversion
				 * listed every design snapshot to anonymous visitors, and asking
				 * for the content field ran multi-megabyte JSON through
				 * the_content until PHP ran out of memory. The plugin reads and
				 * restores conversions through its own routes.
				 */
				'show_in_rest'        => false,
				'capabilities'        => self::admin_only(),
				'map_meta_cap'        => false,
				// No revisions: each re-import stored another multi-megabyte copy.
				'supports'            => array( 'title', 'custom-fields' ),
				'rewrite'             => false,
			)
		);

		register_post_type(
			self::FORM_ENTRY,
			array(
				'labels'          => array(
					'name'          => __( 'Form entries', 'dxai-ui' ),
					'singular_name' => __( 'Form entry', 'dxai-ui' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'show_in_rest'    => false,
				'capabilities'    => self::admin_only(),
				'map_meta_cap'    => false,
				'supports'        => array( 'title', 'editor', 'custom-fields' ),
				'rewrite'         => false,
			)
		);
	}

	/**
	 * The menu locations a converted design's header reads.
	 *
	 * These were the plugin's own `dxai-primary` and `dxai-footer`, which no
	 * theme and no template rendered. The header is built from Appearance >
	 * Menus now, from the DevriX theme's `primary-navigation` plus a top bar
	 * and a buttons location (Header_Menus), and the footer lives in
	 * Appearance > Widgets. The menus written for the old locations stay
	 * where they are; nothing deletes them.
	 */
	public function menus(): void {
		\DXAI_UI\Chrome\Header_Menus::register_locations();
	}
}

<?php
/**
 * "Edit header" / "Edit footer" shortcuts for converted pages.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Admin;

/**
 * Where a converted page's header and footer are edited, one click from the
 * page: from the admin bar while viewing it, and from the import wizard's
 * last screen.
 *
 * An import builds the header in Appearance > Menus and the footer in
 * Appearance > Widgets (the page holds the dxai-ui/site-header and
 * dxai-ui/site-footer blocks), and the links go there. A design whose header
 * or footer could not be built that way keeps it as a block template part,
 * edited once and shown on every page that references it; that link opens
 * the part — in the Site Editor on a block theme, in the post editor on a
 * classic theme (no Site Editor).
 */
final class Part_Shortcuts {

	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 80 );
	}

	public function admin_bar( \WP_Admin_Bar $bar ): void {
		if ( is_admin() || ! is_singular() || ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		$page_id = (int) get_queried_object_id();
		if ( $page_id < 1 || ! get_post_meta( $page_id, '_dxai_ui_generated_page', true ) ) {
			return;
		}
		foreach ( self::edit_links( $page_id ) as $area => $link ) {
			$bar->add_node(
				array(
					'id'    => 'dxai-ui-edit-' . $area,
					'title' => 'header' === $area ? __( 'Edit header', 'dxai-ui' ) : __( 'Edit footer', 'dxai-ui' ),
					'href'  => $link['url'],
					'meta'  => array( 'title' => $link['title'] ),
				)
			);
		}
	}

	/**
	 * Where each of the page's header and footer is edited: Appearance >
	 * Menus for the site header block, Appearance > Widgets for the site
	 * footer block, else the template part it references.
	 *
	 * @return array<string, array{url:string, title:string}> area => link.
	 */
	public static function edit_links( int $page_id ): array {
		$out     = array();
		$content = (string) get_post_field( 'post_content', $page_id );
		if ( str_contains( $content, '<!-- wp:' . \DXAI_UI\Chrome\Site_Header_Block::NAME ) ) {
			$out['header'] = array(
				'url'   => admin_url( 'nav-menus.php' ),
				'title' => __( 'The header’s links are in Appearance > Menus', 'dxai-ui' ),
			);
		}
		if ( str_contains( $content, '<!-- wp:' . \DXAI_UI\Chrome\Site_Footer_Block::NAME ) ) {
			$out['footer'] = array(
				'url'   => admin_url( 'widgets.php' ),
				'title' => __( 'The footer’s content is in Appearance > Widgets', 'dxai-ui' ),
			);
		}
		foreach ( self::parts_of( $page_id ) as $area => $part ) {
			if ( ! isset( $out[ $area ] ) ) {
				$out[ $area ] = array(
					'url'   => self::edit_url( $part ),
					'title' => (string) $part->post_title,
				);
			}
		}

		return $out;
	}

	/**
	 * The import wizard's "Edit header" / "Edit footer" link for a page: its
	 * edit_links() entry, else the part the import reported.
	 */
	public static function edit_url_for_page( int $page_id, string $area, int $part_id = 0 ): string {
		$links = $page_id > 0 ? self::edit_links( $page_id ) : array();
		if ( isset( $links[ $area ] ) ) {
			return $links[ $area ]['url'];
		}

		return self::edit_url_for_id( $part_id );
	}

	/**
	 * The page's header and footer parts, by area.
	 *
	 * @return array<string, \WP_Post>
	 */
	public static function parts_of( int $page_id ): array {
		$out = array();
		foreach ( parse_blocks( (string) get_post_field( 'post_content', $page_id ) ) as $block ) {
			self::collect( $block, $out );
		}

		return $out;
	}

	/**
	 * @param array<string, mixed>    $block
	 * @param array<string, \WP_Post> $out
	 */
	private static function collect( array $block, array &$out ): void {
		if ( ( $block['blockName'] ?? '' ) === 'core/template-part' ) {
			$slug = (string) ( $block['attrs']['slug'] ?? '' );
			$area = str_starts_with( $slug, 'dxai-header' ) ? 'header' : ( str_starts_with( $slug, 'dxai-footer' ) ? 'footer' : '' );
			if ( $area !== '' && ! isset( $out[ $area ] ) ) {
				$found = get_posts(
					array(
						'post_type'      => 'wp_template_part',
						'name'           => $slug,
						'post_status'    => 'publish',
						'posts_per_page' => 1,
					)
				);
				if ( isset( $found[0] ) && $found[0] instanceof \WP_Post ) {
					$out[ $area ] = $found[0];
				}
			}
		}
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $inner ) {
			if ( is_array( $inner ) ) {
				self::collect( $inner, $out );
			}
		}
	}

	/**
	 * Where a template part is edited on this site.
	 */
	public static function edit_url( \WP_Post $part ): string {
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			return add_query_arg(
				array(
					'postType' => 'wp_template_part',
					'postId'   => get_stylesheet() . '//' . $part->post_name,
					'canvas'   => 'edit',
				),
				admin_url( 'site-editor.php' )
			);
		}

		return (string) get_edit_post_link( $part->ID, 'raw' );
	}

	public static function edit_url_for_id( int $part_id ): string {
		$part = $part_id > 0 ? get_post( $part_id ) : null;

		return $part instanceof \WP_Post && $part->post_type === 'wp_template_part' ? self::edit_url( $part ) : '';
	}
}

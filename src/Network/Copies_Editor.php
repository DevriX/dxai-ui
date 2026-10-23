<?php
/**
 * "Make the rest from this page" in the page editor and in the list of pages.
 *
 * @package DXAI_UI\Network
 */

declare(strict_types=1);

namespace DXAI_UI\Network;

/**
 * The panel that makes the copies is in the editor of the page a person has just finished (assets/src/admin/editor/
 * make-the-rest.js, built to assets/build/make-the-rest.js), and the list of pages has a link to it on every service and
 * city page. Both only for someone who may publish a design's markup (manage_options and unfiltered_html: a copy is
 * written as it is), like the routes behind them (API\Copies_Controller).
 */
final class Copies_Editor {

	public const HANDLE = 'dxai-ui-make-the-rest';

	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'assets' ) );
		add_filter( 'page_row_actions', array( $this, 'row_action' ), 10, 2 );
	}

	/** Whether the person may make copies. */
	public static function allowed(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'unfiltered_html' );
	}

	public function assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen || $screen->post_type !== 'page' || ! self::allowed() ) {
			return;
		}
		$asset_file = DXAI_UI_DIR . 'assets/build/make-the-rest.asset.php';
		$script     = DXAI_UI_DIR . 'assets/build/make-the-rest.js';
		// Built by `npm run build`; a copy without the build has no panel.
		if ( is_readable( $asset_file ) && is_readable( $script ) ) {
			$asset = include $asset_file;
			wp_enqueue_script(
				self::HANDLE,
				DXAI_UI_URL . 'assets/build/make-the-rest.js',
				is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array( 'wp-plugins', 'wp-editor', 'wp-data', 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
				is_string( $asset['version'] ?? null ) ? $asset['version'] : DXAI_UI_VERSION,
				true
			);
			wp_set_script_translations( self::HANDLE, 'dxai-ui', DXAI_UI_DIR . 'languages' );
		}
	}

	/**
	 * The link on a service or city page in the list of pages.
	 *
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public function row_action( array $actions, \WP_Post $post ): array {
		if ( ! self::allowed() || ! current_user_can( 'edit_post', $post->ID ) || ! in_array( Page_Copies::kind( (int) $post->ID ), array( 'service', 'location' ), true ) ) {
			return $actions;
		}
		$edit = get_edit_post_link( $post->ID, 'raw' );
		if ( ! is_string( $edit ) || $edit === '' ) {
			return $actions;
		}
		$actions['dxai_copies'] = '<a href="' . esc_url( add_query_arg( 'dxai-copies', '1', $edit ) ) . '">' . esc_html__( 'Make the rest from this page', 'dxai-ui' ) . '</a>';

		return $actions;
	}
}

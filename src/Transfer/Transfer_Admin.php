<?php
/**
 * Export / import in wp-admin: the Pages row action and the download.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Transfer;

/**
 * "Export with styles" on Pages > All Pages, for pages this plugin
 * converted, and the nonce-protected admin-post URL it links to, which
 * builds the package and sends it as a download. The same download is
 * offered by the REST route (Transfer_Controller) the Library screen uses;
 * WP-CLI gets `wp dxai-ui export` / `wp dxai-ui import` (Transfer_Cli).
 *
 * Who may: the import routes' rule (Converter_Controller::publish_permissions()
 * — manage_options and unfiltered_html). A package carries the page's design
 * CSS and script, which only such a user can publish, and the person who
 * moves pages between sites is the one who imports them.
 */
final class Transfer_Admin {

	public const ACTION = 'dxai_ui_export';

	public function register(): void {
		add_filter( 'page_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'download' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			Transfer_Cli::register();
			\DXAI_UI\Blocks\Native\Native_Cli::register();
			\DXAI_UI\Support\Speed_Cli::register();
			\DXAI_UI\Pages\Order_Cli::register();
		}
	}

	/**
	 * Whether the current user may export and import packages.
	 */
	public static function allowed(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'unfiltered_html' );
	}

	/**
	 * The admin URL that downloads a page's package.
	 */
	public static function export_url( int $page_id ): string {
		// Not wp_nonce_url(): it HTML-escapes the URL (`&amp;`), and this one
		// also travels as JSON to the Library screen, where it is used as is.
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'page_id'  => $page_id,
				'_wpnonce' => wp_create_nonce( self::ACTION . '_' . $page_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * @param array<string, string> $actions
	 * @param \WP_Post|mixed        $post
	 * @return array<string, string>
	 */
	public function row_action( $actions, $post ) {
		if ( ! is_array( $actions ) || ! $post instanceof \WP_Post || 'page' !== $post->post_type || 'trash' === $post->post_status ) {
			return $actions;
		}
		if ( ! get_post_meta( $post->ID, '_dxai_ui_generated_page', true ) || ! self::allowed() ) {
			return $actions;
		}
		$actions['dxai_ui_export'] = sprintf(
			'<a href="%1$s" aria-label="%2$s">%3$s</a>',
			esc_url( self::export_url( (int) $post->ID ) ),
			/* translators: %s: page title. */
			esc_attr( sprintf( __( 'Export “%s” with its styles as a DXAI-UI package', 'dxai-ui' ), get_the_title( $post ) ) ),
			esc_html__( 'Export with styles', 'dxai-ui' )
		);

		return $actions;
	}

	/**
	 * admin-post.php?action=dxai_ui_export&page_id=N&_wpnonce=…
	 */
	public function download(): void {
		$page_id = isset( $_GET['page_id'] ) ? absint( wp_unslash( $_GET['page_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified next.
		check_admin_referer( self::ACTION . '_' . $page_id );
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'You are not allowed to export pages with their styles.', 'dxai-ui' ), '', array( 'response' => 403 ) );
		}
		$built = ( new Page_Export() )->build( $page_id );
		if ( is_wp_error( $built ) ) {
			wp_die( esc_html( $built->get_error_message() ), esc_html__( 'Export failed', 'dxai-ui' ), array( 'response' => (int) ( $built->get_error_data()['status'] ?? 500 ), 'back_link' => true ) );
		}
		self::send( $built );
	}

	/**
	 * Send a built package as a download and remove it. Does not return.
	 *
	 * @param array<string, mixed> $built Page_Export::build()'s answer.
	 */
	public static function send( array $built ): void {
		$path     = (string) $built['path'];
		// Not sanitize_file_name(): it turns the `.dxai.zip` suffix into `.dxai_.zip`.
		$filename = trim( (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', (string) $built['filename'] ), '.-' );
		$filename = $filename !== '' ? $filename : 'page' . Package::SUFFIX;
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
			header( 'Content-Length: ' . (string) filesize( $path ) );
			header( 'X-Content-Type-Options: nosniff' );
		}
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		Package::remove_dir( (string) $built['dir'] );
		exit;
	}
}

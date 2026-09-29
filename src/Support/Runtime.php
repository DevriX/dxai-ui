<?php
/**
 * The must-use loader that keeps converted pages rendering while DXAI-UI is deactivated.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Support;

/**
 * Installs runtime/dxai-ui-runtime.php as wp-content/mu-plugins/dxai-ui-runtime.php
 * and keeps the per-site record the loader reads (dxai_ui_runtime: the
 * plugin's file and whether the site has converted pages). The loader boots
 * the plugin with DXAI_UI_RENDER_ONLY, which Plugin::boot() reads.
 *
 * Only this class writes the loader, and only while the plugin itself is
 * active (never from the loader's own render-only boot). uninstall.php
 * removes it, after checking it is this plugin's file.
 */
final class Runtime {

	public const OPTION = 'dxai_ui_runtime';

	public const FILE = 'dxai-ui-runtime.php';

	/** A header line only this loader carries; the file is never removed without it. */
	public const SIGNATURE = 'Plugin Name: DXAI-UI Runtime';

	public static function render_only(): bool {
		return defined( 'DXAI_UI_RENDER_ONLY' ) && DXAI_UI_RENDER_ONLY;
	}

	public function register(): void {
		if ( self::render_only() ) {
			return;
		}
		add_action( 'admin_init', array( self::class, 'ensure' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Keep the loader current and the site's record right. Cheap enough for
	 * every admin request: one indexed postmeta lookup, and writes only when
	 * something changed. Rewriting the record also clears a fatal error the
	 * loader recorded (`failed`), so activating the plugin again retries it.
	 *
	 * @param bool $activating True from Plugin::activate(). The request that
	 *                         activates the plugin has usually booted it
	 *                         render-only already (the loader ran at
	 *                         plugins_loaded while it was still inactive), and
	 *                         the loader must be brought up to date all the same.
	 */
	public static function ensure( bool $activating = false ): void {
		if ( self::render_only() && ! $activating ) {
			return;
		}
		$state = array(
			'file'    => plugin_basename( DXAI_UI_FILE ),
			'pages'   => self::has_pages(),
			'version' => DXAI_UI_VERSION,
		);
		if ( get_option( self::OPTION ) !== $state ) {
			update_option( self::OPTION, $state, true );
		}
		if ( self::installed_version() !== DXAI_UI_VERSION ) {
			self::install( $activating );
		}
		self::drop_bundle();
	}

	/**
	 * The copy uninstall.php left in mu-plugins/dxai-ui-runtime/ when the
	 * plugin was deleted is superseded once the plugin is back: the loader
	 * boots the installed plugin from then on. Only a folder that holds this
	 * plugin's main file is removed.
	 *
	 * On a network the one folder serves every site, and a site whose record
	 * still names a plugin file that is not there (blank, as uninstall.php
	 * used to write it, or another folder name) boots it from here. So every
	 * such record is pointed at this plugin first. That walks every site, up
	 * to the bound activate() uses; a network with more sites keeps the folder.
	 */
	private static function drop_bundle(): void {
		$bundle = wp_normalize_path( WPMU_PLUGIN_DIR ) . '/dxai-ui-runtime';
		$main   = $bundle . '/dxai-ui.php';
		if ( ! is_dir( $bundle ) || is_link( $bundle ) || ! is_readable( $main ) || ! str_contains( (string) file_get_contents( $main, false, null, 0, 1024 ), 'Plugin Name:       DXAI-UI' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return;
		}
		if ( is_multisite() ) {
			$bound = max( 1, (int) apply_filters( 'dxai_ui_network_activation_sites', 500 ) );
			if ( (int) get_sites( array( 'count' => true ) ) > $bound ) {
				return;
			}
			$file = plugin_basename( DXAI_UI_FILE );
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => $bound ) ) as $site_id ) {
				$state = get_blog_option( (int) $site_id, self::OPTION );
				$named = is_array( $state ) ? (string) ( $state['file'] ?? '' ) : '';
				if ( is_array( $state ) && ! empty( $state['pages'] ) && ( $named === '' || ! is_readable( WP_PLUGIN_DIR . '/' . $named ) ) ) {
					$state['file'] = $file;
					unset( $state['bundle'] );
					update_blog_option( (int) $site_id, self::OPTION, $state );
				}
			}
		}
		// Moved aside in one step first: a request never boots a half-removed copy.
		$old = $bundle . '-old-' . wp_generate_password( 6, false );
		if ( @rename( $bundle, $old ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			$bundle = $old;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $bundle, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			if ( $item->isLink() || $item->isFile() ) {
				wp_delete_file( $item->getPathname() );
			} elseif ( $item->isDir() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			}
		}
		@rmdir( $bundle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/** Whether this site has at least one converted page. */
	public static function has_pages(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one indexed existence check.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", '_dxai_ui_generated_page' ) );
	}

	public static function target(): string {
		return wp_normalize_path( WPMU_PLUGIN_DIR ) . '/' . self::FILE;
	}

	/** The Version header of the installed loader, '' when there is none of ours. */
	public static function installed_version(): string {
		$file = self::target();
		if ( ! is_readable( $file ) ) {
			return '';
		}
		$head = (string) file_get_contents( $file, false, null, 0, 2048 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! str_contains( $head, self::SIGNATURE ) || preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $head, $m ) !== 1 ) {
			return '';
		}

		return $m[1];
	}

	/** Write the loader; false when mu-plugins cannot be written (a notice says so). */
	public static function install( bool $activating = false ): bool {
		if ( self::render_only() && ! $activating ) {
			return false;
		}
		$source = DXAI_UI_DIR . 'runtime/' . self::FILE;
		$code   = is_readable( $source ) ? (string) file_get_contents( $source ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( $code === '' ) {
			return false;
		}
		$code = str_replace( '{{DXAI_UI_VERSION}}', DXAI_UI_VERSION, $code );
		$dir  = wp_normalize_path( WPMU_PLUGIN_DIR );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			update_option( 'dxai_ui_runtime_error', 'mkdir', false );
			return false;
		}
		// An existing file of another plugin under this name is never replaced.
		if ( file_exists( self::target() ) && self::installed_version() === '' ) {
			update_option( 'dxai_ui_runtime_error', 'foreign', false );
			return false;
		}
		// Written whole to a temporary name, then moved, so a request never
		// includes half a file.
		$tmp = $dir . '/.' . self::FILE . '.' . wp_generate_password( 8, false ) . '.tmp';
		if ( false === @file_put_contents( $tmp, $code ) || ! @rename( $tmp, self::target() ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			update_option( 'dxai_ui_runtime_error', 'write', false );
			return false;
		}
		delete_option( 'dxai_ui_runtime_error' );

		return true;
	}

	/**
	 * Tell an administrator when the loader could not be written, because
	 * deactivating the plugin would then leave converted pages unstyled.
	 */
	public function notice(): void {
		$error = get_option( 'dxai_ui_runtime_error' );
		if ( ! $error || ! current_user_can( 'activate_plugins' ) || ! self::has_pages() ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: the folder path. */
					__( 'DXAI-UI could not write its runtime into %s, so converted pages would lose their styles while DXAI-UI is deactivated. Make that folder writable, or keep DXAI-UI active.', 'dxai-ui' ),
					wp_normalize_path( WPMU_PLUGIN_DIR )
				)
			)
		);
	}
}

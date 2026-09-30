<?php
/**
 * Plugin Name: DX UI Runtime
 * Description: Keeps pages converted with DXAI-UI styled and working (styles, fonts, header, footer, interactions, forms) while DXAI-UI is deactivated or after it was deleted. Importing and the DXAI-UI screens stay off. Installed by DXAI-UI; "Remove runtime" on the Plugins screen removes it for good.
 * Version: {{DXAI_UI_VERSION}}
 * Author: DevriX
 * License: GPL-2.0-or-later
 *
 * @package DXAI_UI
 */

defined( 'ABSPATH' ) || exit;

/*
 * A converted page's look is not in its blocks (there is no style="" by
 * design): the plugin writes its rules, loads its stylesheet, fonts and
 * scripts, wraps it in the design's scope and renders its header and footer
 * when the page is requested. So without the plugin every converted page lost
 * its styles. This must-use loader — the one thing WordPress runs whatever
 * the plugin's state — boots DXAI-UI's rendering half:
 *   - from the plugin's own folder while the plugin is installed but not
 *     active on this site;
 *   - from the copy uninstall.php leaves in mu-plugins/dxai-ui-runtime/ after
 *     the plugin was deleted;
 * and only on a site that has converted pages (DXAI-UI records that in the
 * dxai_ui_runtime option). It does nothing while DXAI-UI itself is active,
 * and nothing when DXAI_UI_DISABLE_RUNTIME is defined as true (wp-config.php).
 * It also stands down by itself after a fatal error in that code (below).
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( defined( 'DXAI_UI_VERSION' ) || ( defined( 'DXAI_UI_DISABLE_RUNTIME' ) && DXAI_UI_DISABLE_RUNTIME ) ) {
			return;
		}
		$state = get_option( 'dxai_ui_runtime' );
		if ( ! is_array( $state ) || empty( $state['pages'] ) ) {
			return;
		}
		// Standing down: for an hour after a fatal error in its code (recorded
		// below) unless "Try again now" is used sooner, and in recovery mode,
		// which pauses only what is in the plugins folder and active.
		if ( time() - (int) ( $state['failed']['time'] ?? 0 ) < HOUR_IN_SECONDS || ( function_exists( 'wp_is_recovery_mode' ) && wp_is_recovery_mode() ) ) {
			return;
		}
		$clean  = static fn( $path ): string => ltrim( str_replace( array( '..', '\\' ), array( '', '/' ), (string) $path ), '/' );
		$main   = '';
		$bundle = false;
		if ( ! empty( $state['file'] ) && is_readable( WP_PLUGIN_DIR . '/' . $clean( $state['file'] ) ) ) {
			$main = WP_PLUGIN_DIR . '/' . $clean( $state['file'] );
		} elseif ( is_readable( WPMU_PLUGIN_DIR . '/dxai-ui-runtime/dxai-ui.php' ) ) {
			$main   = WPMU_PLUGIN_DIR . '/dxai-ui-runtime/dxai-ui.php';
			$bundle = true;
		}
		if ( $main === '' ) {
			return;
		}
		define( 'DXAI_UI_RENDER_ONLY', true );
		define( 'DXAI_UI_RUNTIME_BUNDLE', $bundle );
		/*
		 * Deactivating or deleting DXAI-UI does not stop this code, and
		 * WordPress's recovery mode cannot pause it, so a fatal error in it
		 * (a render filter after a PHP upgrade, say) failed every request
		 * with nothing in wp-admin to stop it. Such an error is recorded
		 * instead, and this site stands down (above): its converted pages
		 * show unstyled rather than not at all, and the notice below says
		 * so. An error counts when it was raised in this code or by a call it
		 * made (the top frame of the trace). Not during WordPress's
		 * maintenance mode, where half-copied files are expected; the hour's
		 * limit covers an update that ran without it.
		 */
		$dir = wp_normalize_path( dirname( $main ) ) . '/';
		register_shutdown_function(
			static function () use ( $dir ): void {
				$error = error_get_last();
				if ( ! is_array( $error ) || ! in_array( $error['type'] ?? 0, array( E_ERROR, E_PARSE, E_USER_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR ), true ) || wp_is_maintenance_mode() ) {
					return;
				}
				$message = (string) ( $error['message'] ?? '' );
				// A request that ran out of time or memory hit the host's limits, not a fault in this code: it does
				// not stand the runtime down (one slow request would leave every converted page unstyled for an hour).
				if ( preg_match( '/^(Maximum execution time|Allowed memory size)/', $message ) === 1 ) {
					return;
				}
				if ( ! str_starts_with( wp_normalize_path( (string) ( $error['file'] ?? '' ) ), $dir ) && ! str_contains( str_replace( '\\', '/', $message ), '#0 ' . $dir ) ) {
					return;
				}
				$state = get_option( 'dxai_ui_runtime' );
				if ( is_array( $state ) ) {
					$state['failed'] = array(
						'time'  => time(),
						'error' => mb_substr( explode( "\n", $message )[0], 0, 300 ),
					);
					update_option( 'dxai_ui_runtime', $state, true );
				}
			}
		);
		// A failure here must never take the site down with it: there is no
		// plugin to deactivate. It is logged and the pages render unstyled.
		try {
			require_once $main;
		} catch ( \Throwable $e ) {
			error_log( 'DXAI-UI Runtime could not start: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	},
	0
);

/*
 * While this site's runtime stands down after a fatal error, every admin
 * screen says so, with the error and a way to try again at once.
 */
add_action(
	'admin_notices',
	static function (): void {
		$state  = get_option( 'dxai_ui_runtime' );
		$failed = is_array( $state ) && is_array( $state['failed'] ?? null ) ? $state['failed'] : array();
		if ( $failed === array() || time() - (int) ( $failed['time'] ?? 0 ) >= HOUR_IN_SECONDS || ! current_user_can( is_multisite() ? 'manage_network_plugins' : 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p><p><code>%3$s</code></p><form method="post" action="%4$s"><input type="hidden" name="action" value="dxai_ui_retry_runtime" />%5$s<p><button type="submit" class="button">%6$s</button></p></form></div>',
			esc_html__( 'The DX UI Runtime stopped after a PHP error in its code.', 'dxai-ui' ),
			esc_html(
				sprintf(
					/* translators: %s: date and time of the error. */
					__( 'That was at %s; it tries again by itself an hour later. Until then, pages converted with DXAI-UI show without their design’s styles, header and footer; their content is not affected. Defining DXAI_UI_DISABLE_RUNTIME as true in wp-config.php keeps it off.', 'dxai-ui' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( $failed['time'] ?? 0 ) )
				)
			),
			esc_html( (string) ( $failed['error'] ?? '' ) ),
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'dxai_ui_retry_runtime', '_wpnonce', true, false ),
			esc_html__( 'Try again now', 'dxai-ui' )
		);
	}
);

add_action(
	'admin_post_dxai_ui_retry_runtime',
	static function (): void {
		if ( ! current_user_can( is_multisite() ? 'manage_network_plugins' : 'activate_plugins' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'dxai-ui' ), 403 );
		}
		check_admin_referer( 'dxai_ui_retry_runtime' );
		$state = get_option( 'dxai_ui_runtime' );
		if ( is_array( $state ) && isset( $state['failed'] ) ) {
			unset( $state['failed'] );
			update_option( 'dxai_ui_runtime', $state, true );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
);

/*
 * After DXAI-UI was deleted, the Plugins screen says what keeps the converted
 * pages styled and offers to remove it — the only way to remove a must-use
 * plugin from wp-admin. On a network that is Network Admin > Plugins too,
 * where plugins are deleted and must-use plugins listed (all_admin_notices
 * fires there; admin_notices does not).
 */
add_action(
	'all_admin_notices',
	static function (): void {
		global $pagenow;
		if ( 'plugins.php' !== $pagenow || ! current_user_can( is_multisite() ? 'manage_network_plugins' : 'activate_plugins' ) ) {
			return;
		}
		$copy = WPMU_PLUGIN_DIR . '/dxai-ui-runtime/dxai-ui.php';
		/*
		 * Where the copy booted, that says it. Where nothing booted — Network
		 * Admin runs the main site's record, which a network whose converted
		 * pages are all on other sites does not have, and a site standing down
		 * after an error — the copy being there with no DXAI-UI installed does.
		 */
		if ( defined( 'DXAI_UI_RUNTIME_BUNDLE' ) ) {
			$shown = DXAI_UI_RUNTIME_BUNDLE;
		} else {
			$shown = ! defined( 'DXAI_UI_VERSION' ) && is_readable( $copy ) && function_exists( 'get_plugins' ) && ! in_array( 'DXAI-UI', wp_list_pluck( get_plugins(), 'Name' ), true );
		}
		if ( ! $shown ) {
			return;
		}
		// Closed once by this person: not shown to them again (the runtime stays listed under Must-Use).
		if ( get_user_meta( get_current_user_id(), 'dxai_ui_runtime_notice_closed', true ) ) {
			return;
		}
		/*
		 * A warning a person can close, with the one irreversible action on it styled as dangerous: removing
		 * the runtime takes the design's styles off every converted page at once. Its own small stylesheet and
		 * script: a must-use plugin has no asset files to enqueue.
		 */
		printf(
			'<style>.dxai-ui-runtime-notice .dxai-ui-danger{background:#d63638;border-color:#d63638;color:#fff}.dxai-ui-runtime-notice .dxai-ui-danger:hover,.dxai-ui-runtime-notice .dxai-ui-danger:focus{background:#b32d2e;border-color:#b32d2e;color:#fff}.dxai-ui-runtime-notice .dxai-ui-danger:focus{box-shadow:0 0 0 1px #fff,0 0 0 3px #b32d2e}</style>'
			. '<div class="notice notice-warning is-dismissible dxai-ui-runtime-notice" data-nonce="%6$s"><p><strong>%7$s</strong> %1$s</p><form method="post" action="%2$s"><input type="hidden" name="action" value="dxai_ui_remove_runtime" />%3$s<p><button type="submit" class="button dxai-ui-danger" onclick="return confirm(%4$s);">%5$s</button></p></form></div>'
			. '<script>document.addEventListener("click",function(e){var b=e.target.closest(".dxai-ui-runtime-notice .notice-dismiss");if(!b){return;}var n=b.closest(".dxai-ui-runtime-notice"),d=new FormData();d.append("action","dxai_ui_close_runtime_notice");d.append("_wpnonce",n.getAttribute("data-nonce"));fetch(%8$s,{method:"POST",credentials:"same-origin",body:d});});</script>',
			esc_html(
				sprintf(
					/* translators: %s: the DXAI-UI version the runtime was copied from. */
					__( 'Pages converted with DX UI keep their styles, header, footer and forms through the DX UI Runtime (Must-Use plugins), a copy of DX UI %s that gets no updates. Reinstalling DX UI takes over from it automatically. Removing the runtime takes the design’s styles off every converted page at once; their content stays.', 'dxai-ui' ),
					(string) get_file_data( $copy, array( 'Version' => 'Version' ) )['Version']
				)
			),
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'dxai_ui_remove_runtime', '_wpnonce', true, false ),
			esc_attr( wp_json_encode( __( 'Every page converted with DX UI loses its styles, header and footer now. Their content stays. Remove the DX UI Runtime?', 'dxai-ui' ) ) ),
			esc_html__( 'Remove runtime', 'dxai-ui' ),
			esc_attr( wp_create_nonce( 'dxai_ui_close_runtime_notice' ) ),
			esc_html__( 'DX UI was deleted.', 'dxai-ui' ),
			wp_json_encode( admin_url( 'admin-ajax.php' ) )
		);
	}
);

// Closing the notice (its × button) is remembered for that person.
add_action(
	'wp_ajax_dxai_ui_close_runtime_notice',
	static function (): void {
		check_ajax_referer( 'dxai_ui_close_runtime_notice' );
		if ( ! current_user_can( is_multisite() ? 'manage_network_plugins' : 'activate_plugins' ) ) {
			wp_send_json_error( null, 403 );
		}
		update_user_meta( get_current_user_id(), 'dxai_ui_runtime_notice_closed', 1 );
		wp_send_json_success();
	}
);

add_action(
	'admin_post_dxai_ui_remove_runtime',
	static function (): void {
		if ( ! current_user_can( is_multisite() ? 'manage_network_plugins' : 'activate_plugins' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'dxai-ui' ), 403 );
		}
		check_admin_referer( 'dxai_ui_remove_runtime' );
		$bundle = wp_normalize_path( WPMU_PLUGIN_DIR . '/dxai-ui-runtime' );
		if ( is_dir( $bundle ) && ! is_link( $bundle ) && is_readable( $bundle . '/dxai-ui.php' ) ) {
			$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $bundle, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $items as $item ) {
				if ( $item->isLink() || $item->isFile() ) {
					wp_delete_file( $item->getPathname() );
				} elseif ( $item->isDir() ) {
					@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				}
			}
			@rmdir( $bundle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		delete_option( 'dxai_ui_runtime' );
		wp_delete_file( __FILE__ );
		// Must-Use plugins are listed only in Network Admin on a network.
		wp_safe_redirect( is_multisite() ? network_admin_url( 'plugins.php?plugin_status=mustuse' ) : admin_url( 'plugins.php?plugin_status=mustuse' ) );
		exit;
	}
);

<?php
/**
 * Uninstall DXAI-UI.
 *
 * Removes the plugin's OWN state only — its settings (API keys included),
 * caches, transients, crawl jobs and temporary import folders — and never the
 * site's content. It used to force-delete every converted page, pattern,
 * template part, navigation post, conversion record and form submission, and
 * the uploads/dxai-ui folder holding the pages' stylesheets and scripts. That
 * is what "Delete" in Plugins runs, and deleting the plugin is how many
 * people replace it with a newer ZIP: a site lost its pages for good that
 * way (2026-09-25). Converted pages are the site's content like any other;
 * they stay, and a reinstall brings their styles back. The header/footer
 * specs and the adopted design tokens stay for the same reason: the pages
 * render from them.
 *
 * The plugin is not loaded here (WordPress only deletes an inactive plugin),
 * so nothing from src/ is available: every name below is spelled out, and
 * each one is the value of the constant that owns it - Options::OPTION_KEY,
 * Part_Theme_Binding::OPTION, Style_Rules' site generation counter, Crawl_Job::PREFIX,
 * Crawl_Job::LOCK_PREFIX and Crawl_Job::CRON_HOOK, Logger::TRANSIENT,
 * Sideloader's `dxai_ui_fetch_` keys and Form_Controller's `dxai_ui_form_`
 * rate-limit keys.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * A development checkout is never uninstalled from wp-admin. On a dev site the
 * plugin folder is often a symlink to the source tree, and WordPress deletes a
 * plugin's folder recursively after this file runs — through the link, that is
 * the whole repository. Stopping here stops the deletion as well.
 */
$dxai_ui_folder = WP_PLUGIN_DIR . '/' . dirname( (string) WP_UNINSTALL_PLUGIN );
if ( is_link( $dxai_ui_folder ) || is_dir( __DIR__ . '/node_modules' ) || is_dir( __DIR__ . '/.verify' ) || file_exists( __DIR__ . '/bin/verify-import.cjs' ) ) {
	wp_die(
		esc_html__( 'This DX UI folder is a development checkout (a link to, or a copy of, the source tree), so it was not deleted. Remove it from disk yourself if you really mean to.', 'dxai-ui' ),
		esc_html__( 'DX UI was not deleted', 'dxai-ui' ),
		array( 'back_link' => true )
	);
}

/*
 * Every site of a network, not just the one the uninstall was started from:
 * the plugin's files serve all of them, so once they are gone every site's
 * rows are orphans. A single site runs the same function once.
 */
$dxai_ui_pages = false;
if ( is_multisite() ) {
	$dxai_ui_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $dxai_ui_sites as $dxai_ui_site ) {
		switch_to_blog( (int) $dxai_ui_site );
		$dxai_ui_pages = dxai_ui_uninstall_site() || $dxai_ui_pages;
		restore_current_blog();
	}
} else {
	$dxai_ui_pages = dxai_ui_uninstall_site();
}

dxai_ui_remove_work_dirs();
dxai_ui_keep_runtime( $dxai_ui_pages );

/**
 * Keep converted pages rendering after the plugin is gone.
 *
 * WordPress deletes this folder right after this file returns, and with it
 * the code that draws every converted page — their styles are written at
 * render time, not stored in the blocks. So when any site has converted
 * pages, the plugin's rendering code is copied to
 * mu-plugins/dxai-ui-runtime/, where the must-use loader
 * (mu-plugins/dxai-ui-runtime.php, runtime/dxai-ui-runtime.php here) boots it
 * render-only: no screens, no imports, only the pages, their header, footer
 * and forms. The Plugins screen then offers "Remove runtime", and a reinstalled
 * DXAI-UI takes over and removes the copy (Support\Runtime::ensure()). With no
 * converted pages anywhere, the loader is removed instead.
 */
function dxai_ui_keep_runtime( bool $has_pages ): void {
	$loader = wp_normalize_path( WPMU_PLUGIN_DIR ) . '/dxai-ui-runtime.php';
	$bundle = wp_normalize_path( WPMU_PLUGIN_DIR ) . '/dxai-ui-runtime';
	$ours   = static fn( string $file ): bool => is_readable( $file ) && preg_match( '/Plugin Name:\s+(?:DX UI|DXAI-UI)\b/', (string) file_get_contents( $file, false, null, 0, 2048 ) ) === 1; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	if ( ! $has_pages ) {
		if ( $ours( $loader ) ) {
			wp_delete_file( $loader );
		}
		if ( is_dir( $bundle ) && ! is_link( $bundle ) && $ours( $bundle . '/dxai-ui.php' ) ) {
			dxai_ui_rrmdir( $bundle );
		}
		return;
	}

	if ( ! is_dir( WPMU_PLUGIN_DIR ) && ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
		return;
	}
	// Copied whole into a new folder first, then swapped in, so the loader
	// never boots half a copy.
	$fresh = $bundle . '-new-' . wp_generate_password( 6, false );
	if ( ! dxai_ui_copy_tree( wp_normalize_path( __DIR__ ), $fresh ) || ! is_readable( $fresh . '/dxai-ui.php' ) ) {
		dxai_ui_rrmdir( $fresh );
		return;
	}
	if ( is_dir( $bundle ) && ! is_link( $bundle ) && $ours( $bundle . '/dxai-ui.php' ) ) {
		dxai_ui_rrmdir( $bundle );
	}
	if ( ! @rename( $fresh, $bundle ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
		dxai_ui_rrmdir( $fresh );
		return;
	}
	// The loader, written from this version whether or not one is there: an
	// older loader cannot boot the copy. Never over a file that is not ours.
	if ( $ours( $loader ) || ! file_exists( $loader ) ) {
		$code = is_readable( __DIR__ . '/runtime/dxai-ui-runtime.php' ) ? (string) file_get_contents( __DIR__ . '/runtime/dxai-ui-runtime.php' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		preg_match( "/define\( 'DXAI_UI_VERSION', '([^']+)' \)/", (string) file_get_contents( __DIR__ . '/dxai-ui.php' ), $m ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( $code !== '' ) {
			@file_put_contents( $loader, str_replace( '{{DXAI_UI_VERSION}}', $m[1] ?? '', $code ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}
}

/**
 * Copy the plugin's files for the runtime: everything but this file (the copy
 * must never uninstall anything) and the loader's template.
 */
function dxai_ui_copy_tree( string $from, string $to ): bool {
	if ( ! wp_mkdir_p( $to ) ) {
		return false;
	}
	$names = scandir( $from );
	if ( ! is_array( $names ) ) {
		return false;
	}
	foreach ( $names as $name ) {
		if ( '.' === $name || '..' === $name || ( $from === wp_normalize_path( __DIR__ ) && in_array( $name, array( 'uninstall.php', 'runtime' ), true ) ) ) {
			continue;
		}
		$src = $from . '/' . $name;
		$dst = $to . '/' . $name;
		if ( is_link( $src ) ) {
			continue;
		}
		if ( is_dir( $src ) ) {
			if ( ! dxai_ui_copy_tree( $src, $dst ) ) {
				return false;
			}
		} elseif ( ! @copy( $src, $dst ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}
	}

	return true;
}

/**
 * Remove the plugin's own state for the current site. Content — pages,
 * patterns, template parts, navigation, conversion records, form entries, the
 * uploads/dxai-ui assets, the header/footer specs (`dxai_ui_site_header*`,
 * `dxai_ui_site_footer`, `dxai_ui_footer_widgets`) and the adopted design
 * (`dxai_ui_site_design`) — is left exactly as it is, and so is the runtime's
 * record (`dxai_ui_runtime`).
 *
 * Style_Repair's progress (`dxai_ui_style_repair`, `dxai_ui_style_normalize`)
 * stays as well. It records that a one-time pass over the converted pages is
 * finished. Deleting it made a reinstall run the pass again, and by then the
 * pass mostly meets deliberate edits: a multisite site admin's unlinked word
 * or removed image, which it put back from an older revision
 * (Style_Repair::VERSION says why). Only a new VERSION starts it again.
 *
 * @return bool Whether this site has converted pages.
 */
function dxai_ui_uninstall_site(): bool {
	foreach ( array( 'dxai_ui_settings', 'dxai_ui_settings_encrypted', 'dxai_ui_parts_theme', 'dxai_ui_rules_site_generation', 'dxai_ui_runtime_error' ) as $option ) {
		delete_option( $option );
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one indexed existence check.
	$has_pages = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", '_dxai_ui_generated_page' ) );
	if ( $has_pages ) {
		/*
		 * The plugin's own file is kept in the record. Once WordPress removes
		 * the folder, the loader cannot read that file and boots the copy in
		 * mu-plugins/dxai-ui-runtime/. After a reinstall it boots the new
		 * plugin from its folder again, as for any deactivated install. With
		 * the file blanked, it kept booting the old copy: a reinstalled plugin
		 * was then activated on top of it (its activation hook never ran),
		 * and on a network every other site stopped at nothing once one
		 * site's activation removed the copy.
		 */
		update_option(
			'dxai_ui_runtime',
			array(
				'file'   => (string) WP_UNINSTALL_PLUGIN,
				'pages'  => true,
				'bundle' => true,
			),
			true
		);
	} else {
		delete_option( 'dxai_ui_runtime' );
	}
	dxai_ui_delete_crawl_jobs();
	dxai_ui_delete_transients();

	// Import work folders Zip_Extractor falls back to inside uploads/dxai-ui
	// (`tmp-` plus 8-32 letters or digits) are temporary; the rest of that
	// folder is the pages' assets and stays.
	$upload = wp_upload_dir( null, false );
	if ( ! empty( $upload['basedir'] ) ) {
		$assets = trailingslashit( $upload['basedir'] ) . 'dxai-ui';
		$names  = is_dir( $assets ) && ! is_link( $assets ) ? scandir( $assets ) : false;
		foreach ( is_array( $names ) ? $names : array() as $name ) {
			if ( preg_match( '/^tmp-[A-Za-z0-9]{8,32}$/', $name ) === 1 ) {
				dxai_ui_rrmdir( $assets . '/' . $name );
			}
		}
	}

	return $has_pages;
}

/**
 * Remove the current site's site-from-menu crawl jobs, their locks and their
 * cron fallback.
 *
 * A job is one non-autoloaded option named `dxai_ui_crawl_job_` plus an id,
 * its lock `dxai_ui_crawl_lock_` plus the same id, so they are found by
 * prefix. They are few (Crawl_Job drops a job two days after it was last
 * advanced), so each is removed through delete_option(), which also clears
 * a persistent object cache's copy - a raw DELETE would leave that behind.
 * The cron events carry the job id as their argument, so they are cleared
 * by hook name, whatever the argument.
 */
function dxai_ui_delete_crawl_jobs(): void {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall: a prefix lookup no API offers.
	$names = (array) $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'dxai_ui_crawl_job_' ) . '%',
			$wpdb->esc_like( 'dxai_ui_crawl_lock_' ) . '%'
		)
	);
	foreach ( $names as $name ) {
		delete_option( (string) $name );
	}

	wp_unschedule_hook( 'dxai_ui_crawl_tick' );
}

/**
 * Remove the current site's `dxai_ui_*` transients.
 *
 * The rate-limit keys are `dxai_ui_form_` plus a hash of the visitor's IP, so
 * they cannot be named one by one; in the options table they are matched by
 * prefix. With a persistent object cache they never reach that table - there
 * they expire on their own, within ten minutes for the rate limits and an
 * hour for the log, and the log is deleted by name as well.
 */
function dxai_ui_delete_transients(): void {
	global $wpdb;

	delete_transient( 'dxai_ui_last_log' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall: a one-off prefix delete no API offers.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_dxai_ui_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_dxai_ui_' ) . '%'
		)
	);
}

/**
 * Remove import work folders left in the temp directory by a request that
 * was killed before it could clean up (Zip_Extractor makes them there as
 * `dxai-ui-tmp-` plus 16 hex digits; the ones in uploads/dxai-ui are removed
 * per site above). Only this exact pattern, and only folders PHP may write,
 * so another account's on a shared /tmp is left alone.
 */
function dxai_ui_remove_work_dirs(): void {
	$root = untrailingslashit( (string) apply_filters( 'dxai_ui_zip_work_root', get_temp_dir() ) );
	if ( '' === $root || ! is_dir( $root ) ) {
		return;
	}
	$names = scandir( $root );
	if ( ! is_array( $names ) ) {
		return;
	}
	foreach ( $names as $name ) {
		$path = $root . '/' . $name;
		if ( preg_match( '/^dxai-ui-tmp-[a-f0-9]{16}$/', $name ) === 1 && ! is_link( $path ) && is_dir( $path ) && wp_is_writable( $path ) ) {
			dxai_ui_rrmdir( $path );
		}
	}
}

/**
 * Recursively remove a directory, never following a symlink out of it: a
 * link inside is removed as a link, and what it points to is left alone.
 */
function dxai_ui_rrmdir( string $dir ): void {
	if ( $dir === '' || is_link( $dir ) || ! is_dir( $dir ) ) {
		return;
	}

	$items = scandir( $dir );
	if ( ! is_array( $items ) ) {
		return;
	}

	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . DIRECTORY_SEPARATOR . $item;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			dxai_ui_rrmdir( $path );
			continue;
		}
		wp_delete_file( $path );
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	@rmdir( $dir );
}

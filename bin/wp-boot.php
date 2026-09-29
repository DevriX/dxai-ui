<?php
/**
 * Load WordPress for a CLI suite, without knowing where it lives.
 *
 * Eleven suites used to open by requiring one machine's own
 * `Local Sites/DXAI-UI/app/public/wp-load.php` by absolute path, so the whole
 * verification pack ran on exactly that machine and on no other. The site's
 * location is a fact about the checkout, not about the code.
 *
 * Resolved in this order, first hit wins:
 *
 *   1. `DXAI_WP_ROOT` in the environment — how CI and bin/wp-php.sh pass it.
 *   2. `.verify/wp-root` in the repo, one line holding the path — the local
 *      convention, and the file is untracked so nobody's path is committed.
 *   3. Walking up from this file, which finds it when the repo IS the plugin
 *      directory inside wp-content/plugins.
 *   4. Local by Flywheel's own site list, so a fresh checkout on a Local
 *      machine needs no setup at all.
 *
 * Nothing is guessed silently: a failure prints every place that was tried.
 *
 * Usage, in place of the old require:
 *
 *   require __DIR__ . '/wp-boot.php';
 *
 * @package DXAI_UI
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 2 );
}

if ( defined( 'ABSPATH' ) ) {
	// Already loaded — a suite run through `wp eval-file`.
	return;
}

/**
 * Candidate WordPress roots, in resolution order.
 *
 * @return array<int, array{where:string, path:string}>
 */
$dxai_wp_candidates = static function (): array {
	$out = array();

	$env = getenv( 'DXAI_WP_ROOT' );
	if ( is_string( $env ) && $env !== '' ) {
		$out[] = array(
			'where' => 'DXAI_WP_ROOT',
			'path'  => $env,
		);
	}

	$marker = dirname( __DIR__ ) . '/.verify/wp-root';
	if ( is_readable( $marker ) ) {
		$line = trim( (string) file_get_contents( $marker ) );
		if ( $line !== '' ) {
			$out[] = array(
				'where' => '.verify/wp-root',
				'path'  => $line,
			);
		}
	}

	// wp-content/plugins/<plugin>/bin → three levels up is the WordPress root.
	$here = dirname( __DIR__ );
	for ( $up = 0; $up < 5; $up++ ) {
		$here = dirname( $here );
		if ( $here === '' || $here === '.' || $here === dirname( $here ) ) {
			break;
		}
		$out[] = array(
			'where' => 'walking up from the plugin',
			'path'  => $here,
		);
	}

	/*
	 * Local by Flywheel keeps every site in one JSON file. Reading it means a
	 * fresh checkout on a Local machine needs no environment variable at all,
	 * and it is the same file bin/wp-php.sh already reads for the MySQL port.
	 */
	$appdata = getenv( 'APPDATA' );
	if ( is_string( $appdata ) && $appdata !== '' ) {
		$sites = rtrim( str_replace( '\\', '/', $appdata ), '/' ) . '/Local/sites.json';
		if ( is_readable( $sites ) ) {
			$json = json_decode( (string) file_get_contents( $sites ), true );
			foreach ( is_array( $json ) ? $json : array() as $site ) {
				if ( ! is_array( $site ) || ! isset( $site['path'] ) ) {
					continue;
				}
				$out[] = array(
					'where' => 'Local site "' . (string) ( $site['name'] ?? '?' ) . '"',
					'path'  => rtrim( str_replace( '\\', '/', (string) $site['path'] ), '/' ) . '/app/public',
				);
			}
		}
	}

	return $out;
};

$dxai_wp_tried = array();
foreach ( $dxai_wp_candidates() as $dxai_wp_candidate ) {
	$dxai_wp_root = rtrim( str_replace( '\\', '/', $dxai_wp_candidate['path'] ), '/' );
	$dxai_wp_load = $dxai_wp_root . '/wp-load.php';
	$dxai_wp_tried[] = $dxai_wp_candidate['where'] . ': ' . $dxai_wp_load;
	if ( is_readable( $dxai_wp_load ) ) {
		require $dxai_wp_load;
		/*
		 * Run as an administrator who may publish unfiltered HTML, chosen by
		 * role — not as nobody. A CLI run has no user, so every post it wrote
		 * went through KSES: that is how 16 converted pages lost their
		 * colours, a map iframe and their form controls on 2026-09-23, from
		 * scripts that looked the user up by the login "admin" and silently
		 * carried on as user 0 when it was not there. DXAI_CLI_USER picks a
		 * specific account; with no suitable one the script still runs, and
		 * Structure_Repository::save() refuses to import.
		 */
		if ( function_exists( 'get_current_user_id' ) && get_current_user_id() === 0 ) {
			$dxai_cli_user = getenv( 'DXAI_CLI_USER' );
			$dxai_cli_user = $dxai_cli_user ? get_user_by( is_numeric( $dxai_cli_user ) ? 'id' : 'login', $dxai_cli_user ) : null;
			if ( ! $dxai_cli_user ) {
				foreach ( get_users( array( 'role' => 'administrator', 'orderby' => 'ID', 'number' => 20 ) ) as $dxai_admin ) {
					if ( user_can( $dxai_admin, 'unfiltered_html' ) ) {
						$dxai_cli_user = $dxai_admin;
						break;
					}
				}
			}
			if ( $dxai_cli_user ) {
				wp_set_current_user( $dxai_cli_user->ID );
			}
		}

		return;
	}
}

fwrite(
	STDERR,
	"Could not find WordPress. Set DXAI_WP_ROOT, or write the site's public path into .verify/wp-root.\nTried:\n  "
	. implode( "\n  ", $dxai_wp_tried ) . "\n"
);
exit( 2 );

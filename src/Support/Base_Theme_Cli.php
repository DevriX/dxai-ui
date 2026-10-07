<?php
/**
 * WP-CLI: wp dxai-ui base-theme.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Support;

use DXAI_UI\Theme\Base_Theme;

/**
 * The DX Base theme from the command line:
 *
 *   wp dxai-ui base-theme status
 *   wp dxai-ui base-theme install     copy the theme into wp-content/themes (or, where that folder cannot be written, list it from the plugin)
 *   wp dxai-ui base-theme activate    switch the site to it
 *
 * Nothing here removes a theme: the plugin never deletes one.
 */
final class Base_Theme_Cli {

	public static function register(): void {
		\WP_CLI::add_command(
			'dxai-ui base-theme',
			array( self::class, 'run' ),
			array(
				'shortdesc' => 'The DX Base theme the plugin carries for a site without a DX theme (status, install, activate).',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'action',
						'options'  => array( 'status', 'install', 'activate' ),
						'optional' => false,
					),
				),
			)
		);
	}

	/**
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc
	 */
	public static function run( array $args, array $assoc ): void {
		unset( $assoc );
		$action = (string) ( $args[0] ?? 'status' );
		if ( 'install' === $action ) {
			$done = Base_Theme::install();
			if ( is_wp_error( $done ) ) {
				\WP_CLI::error( $done->get_error_message() );
			}
			\WP_CLI::success(
				'copied' === $done['mode']
					? sprintf( 'DX Base %s copied to %s.', $done['version'], $done['path'] )
					: sprintf( 'DX Base %s is listed from the plugin (%s): the themes folder cannot be written, so it needs the plugin to be active.', $done['version'], $done['path'] )
			);

			return;
		}
		if ( 'activate' === $action ) {
			$done = Base_Theme::activate();
			if ( is_wp_error( $done ) ) {
				\WP_CLI::error( $done->get_error_message() );
			}
			\WP_CLI::success( 'The site now runs DX Base.' );

			return;
		}
		$s = Base_Theme::status();
		\WP_CLI::line( sprintf( 'Bundled version: %s.', '' !== $s['bundled'] ? $s['bundled'] : 'none' ) );
		\WP_CLI::line( $s['installed'] ? sprintf( 'Installed: %s (%s)%s.', $s['version'], $s['mode'] !== '' ? $s['mode'] : 'copied by hand', $s['needs_plugin'] ? ', depends on the plugin' : '' ) : 'Not installed.' );
		\WP_CLI::line( $s['active'] ? 'Active.' : 'Not active.' );
		\WP_CLI::line( $s['writable'] ? 'The themes folder can be written.' : 'The themes folder cannot be written.' );
	}
}

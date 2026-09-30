<?php
/**
 * WP-CLI: wp dxai-ui speed.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Support;

use DXAI_UI\Structures\Design_Attach;

/**
 * The Library's "Speed" panel from the command line, for a network of sites: run it per site with the standard
 * `--url=<site>`, or over the whole network with `wp site list --field=url | xargs -I% wp --url=% ...`.
 *
 *   wp dxai-ui speed status
 *   wp dxai-ui speed apply  [--design=<home-id>]   copy the design's fonts here
 *   wp dxai-ui speed revert [--design=<home-id>]   put its stylesheet back the way it was imported
 *   wp dxai-ui speed trim-on | trim-off            print the theme's stylesheet trimmed to each page, or whole
 *
 * Without --design it works on every imported design of the site.
 */
final class Speed_Cli {

	public static function register(): void {
		\WP_CLI::add_command(
			'dxai-ui speed',
			array( self::class, 'run' ),
			array(
				'shortdesc' => 'The fonts of the designs from this site, and the theme\'s stylesheet trimmed to the page (status, apply, revert, trim-on, trim-off).',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'action',
						'options'  => array( 'status', 'apply', 'revert', 'trim-on', 'trim-off' ),
						'optional' => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'design',
						'optional'    => true,
						'description' => 'The design\'s Home page id (default: every imported design of the site).',
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
		$action = (string) ( $args[0] ?? 'status' );
		if ( str_starts_with( $action, 'trim-' ) ) {
			Speed::set_trim( $action === 'trim-on' );
			\WP_CLI::success( $action === 'trim-on' ? 'The theme\'s stylesheet is printed trimmed to the page.' : 'The theme\'s stylesheet is printed whole.' );

			return;
		}
		$homes = isset( $assoc['design'] ) ? array( (int) $assoc['design'] ) : array_map( static fn( $page ) => (int) $page->ID, Design_Attach::designs() );
		foreach ( $homes as $home ) {
			if ( ! Design_Attach::is_design( $home ) ) {
				\WP_CLI::warning( sprintf( '%d is not an imported design.', $home ) );
				continue;
			}
			$title = get_the_title( $home );
			if ( $action === 'apply' ) {
				$done = Speed::apply_fonts( $home );
				\WP_CLI::line( sprintf( '%d "%s": %s%s.', $home, $title, $done['changed'] ? 'fonts copied here' : 'nothing to change', $done['note'] !== '' ? ' (' . $done['note'] . ')' : ( $done['complete'] ? '' : ', some files still from Google' ) ) );
			} elseif ( $action === 'revert' ) {
				\WP_CLI::line( sprintf( '%d "%s": %s.', $home, $title, Speed::revert_fonts( $home ) ? 'stylesheet put back' : 'nothing to put back' ) );
			}
			$fonts = Speed::fonts( $home );
			if ( $action === 'status' || $action === 'apply' ) {
				\WP_CLI::line( sprintf( '%d "%s": fonts %s (%s), %d files, %d KB.', $home, $title, $fonts['state'], implode( ', ', $fonts['families'] ), $fonts['files'], (int) round( $fonts['bytes'] / 1024 ) ) );
			}
		}
		$trim = Speed::trim();
		if ( $action === 'status' ) {
			\WP_CLI::line( 'The theme\'s stylesheet is printed ' . ( $trim['enabled'] ? 'trimmed to the page.' : 'whole.' ) );
			foreach ( $trim['pages'] as $page ) {
				\WP_CLI::line( sprintf( '  %d "%s": %d KB -> %d KB', $page['id'], $page['title'], (int) round( $page['before'] / 1024 ), (int) round( $page['after'] / 1024 ) ) );
			}
		}
		\WP_CLI::success( 'Done.' );
	}
}

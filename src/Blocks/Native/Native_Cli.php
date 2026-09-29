<?php
/**
 * WP-CLI: wp dxai-ui native-blocks.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks\Native;

use DXAI_UI\Structures\Design_Attach;

/**
 * The Library's "Native blocks" panel from the command line, for a network of sites: run it per site with the
 * standard `--url=<site>`, or over the whole network with `wp site list --field=url | xargs -I% wp --url=% ...`.
 *
 *   wp dxai-ui native-blocks status
 *   wp dxai-ui native-blocks apply  [--design=<home-id>] [--dry-run]
 *   wp dxai-ui native-blocks revert [--design=<home-id>]
 *
 * Without --design it works on every imported design of the site. Each changed page keeps its content from before,
 * so `revert` puts it back (a page edited since is left as its editor made it).
 */
final class Native_Cli {

	public static function register(): void {
		\WP_CLI::add_command(
			'dxai-ui native-blocks',
			array( self::class, 'run' ),
			array(
				'shortdesc' => 'Use WordPress\'s and the theme\'s own blocks in place of the plugin\'s where they say the same thing (status, apply, revert).',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'action',
						'options'  => array( 'status', 'apply', 'revert' ),
						'optional' => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'design',
						'optional'    => true,
						'description' => 'The design\'s Home page id (default: every imported design of the site).',
					),
					array(
						'type'        => 'flag',
						'name'        => 'dry-run',
						'optional'    => true,
						'description' => 'With apply: only say what would change.',
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
		$homes  = isset( $assoc['design'] ) ? array( (int) $assoc['design'] ) : array_map( static fn( $page ) => (int) $page->ID, Design_Attach::designs() );
		if ( $homes === array() ) {
			\WP_CLI::success( 'No imported design on this site.' );

			return;
		}
		if ( count( self::converters() ) === 0 ) {
			\WP_CLI::warning( 'No native counterpart is available on this site (its theme registers none the plugin knows).' );
		}
		foreach ( $homes as $home ) {
			if ( ! Design_Attach::is_design( $home ) ) {
				\WP_CLI::warning( sprintf( '%d is not an imported design.', $home ) );
				continue;
			}
			$title = get_the_title( $home );
			if ( $action === 'status' || ( $action === 'apply' && ! empty( $assoc['dry-run'] ) ) ) {
				$plan = Native_Blocks::plan( $home );
				\WP_CLI::line( sprintf( '%d "%s": %s; %d blocks in %d pages can be replaced (%s).', $home, $title, Native_Blocks::applied( $home ) ? 'converted' : 'not converted', array_sum( $plan['counts'] ), $plan['posts'], wp_json_encode( $plan['counts'] ) ) );
			} elseif ( $action === 'apply' ) {
				$done = Native_Blocks::apply( $home );
				\WP_CLI::line( sprintf( '%d "%s": %d blocks converted in %d pages.', $home, $title, $done['blocks'], $done['posts'] ) );
			} elseif ( $action === 'revert' ) {
				$done = Native_Blocks::revert( $home );
				\WP_CLI::line( sprintf( '%d "%s": %d pages put back, %d left as edited.', $home, $title, $done['posts'], $done['edited'] ) );
			}
		}
		\WP_CLI::success( 'Done.' );
	}

	/** @return array<int, Converter> */
	private static function converters(): array {
		return Native_Blocks::converters();
	}
}

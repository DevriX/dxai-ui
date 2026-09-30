<?php
/**
 * WP-CLI: wp dxai-ui page-order.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

use DXAI_UI\Structures\Design_Attach;

/**
 * The order of the sections of a design's pages, as the team's pages have it (Page_Order).
 *
 *   wp dxai-ui page-order status  [--design=<home-id>]
 *   wp dxai-ui page-order apply   [--design=<home-id>]
 *   wp dxai-ui page-order revert  [--design=<home-id>]
 *
 * Without --design it works on every imported design of the site. `status` only says what would move.
 */
final class Order_Cli {

	private const LETTER = array(
		'hero'    => 'H',
		'trust'   => 'T',
		'process' => 'P',
		'two-col' => '2',
		'cards'   => 'C',
		'reviews' => 'R',
		'related' => 'S',
		'areas'   => 'M',
		'form'    => 'F',
		'faq'     => 'Q',
		'cta'     => 'A',
		'content' => 'c',
		'text'    => 't',
	);

	public static function register(): void {
		\WP_CLI::add_command(
			'dxai-ui page-order',
			array( self::class, 'run' ),
			array(
				'shortdesc' => 'Put the tail of a design\'s pages in the team\'s order: related services, questions, call to action (status, apply, revert).',
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
		foreach ( $homes as $home ) {
			if ( ! Design_Attach::is_design( $home ) ) {
				\WP_CLI::warning( sprintf( '%d is not an imported design.', $home ) );
				continue;
			}
			\WP_CLI::line( sprintf( '%d "%s"', $home, get_the_title( $home ) ) );
			foreach ( Page_Order::design( $home ) as $page ) {
				$now = implode( '', array_map( static fn( $r ) => self::LETTER[ $r ] ?? '?', $page['roles'] ) );
				if ( $action === 'apply' ) {
					$did = Page_Order::apply( $page['id'] );
					$msg = $did ? 'reordered' : ( $page['reason'] !== '' ? 'left as it is: ' . $page['reason'] : 'nothing to move' );
				} elseif ( $action === 'revert' ) {
					$msg = Page_Order::revert( $page['id'] ) ? 'put back' : ( $page['done'] ? 'edited since, left as it is' : 'not reordered' );
				} else {
					$msg = $page['moves'] !== array() ? 'would move ' . implode( ', ', array_map( static fn( $m ) => $m['role'] . ' ' . $m['from'] . '→' . $m['to'], $page['moves'] ) ) : ( $page['reason'] !== '' ? $page['reason'] : 'in order' );
				}
				\WP_CLI::line( sprintf( '  %-5d %-44s %-18s %s', $page['id'], mb_substr( $page['title'], 0, 44 ), $now, $msg ) );
			}
		}
		\WP_CLI::success( 'Done.' );
	}
}

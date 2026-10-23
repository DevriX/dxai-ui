<?php
/**
 * WP-CLI: wp dxai-ui copies.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Network;

/**
 * "Make the rest from this page" (Page_Copies).
 *
 *   wp dxai-ui copies plan <page-id>                                   its sections, and the pages it would be made again for
 *   wp dxai-ui copies make <page-id> [--targets=<ids>] [--words=auto|outline]
 *   wp dxai-ui copies give-back <page-id>                              a copy as it was before
 *
 * Without --targets it makes the pages `plan` marks as chosen (the empty ones, the copies by hand, the ones made before,
 * the ones the menu names). --targets takes page ids, and 0:<title> for a new page.
 */
final class Copies_Cli {

	public static function register(): void {
		\WP_CLI::add_command(
			'dxai-ui copies',
			array( self::class, 'run' ),
			array(
				'shortdesc' => 'Make the other services (or cities) from one finished page: its build and look, the site\'s shared sections word for word, the page\'s own words written for each.',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'action',
						'options'  => array( 'plan', 'make', 'give-back' ),
						'optional' => false,
					),
					array(
						'type'     => 'positional',
						'name'     => 'page',
						'optional' => false,
					),
					array(
						'type'     => 'assoc',
						'name'     => 'targets',
						'optional' => true,
					),
					array(
						'type'     => 'assoc',
						'name'     => 'words',
						'optional' => true,
						'options'  => array( 'auto', 'outline' ),
						'default'  => 'auto',
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
		$id = (int) ( $args[1] ?? 0 );
		switch ( $args[0] ?? '' ) {
			case 'give-back':
				$done = Page_Copies::give_back( $id );
				if ( is_wp_error( $done ) ) {
					\WP_CLI::error( $done->get_error_message() );
				}
				\WP_CLI::success( "Page $id is as it was before." );
				return;
			case 'plan':
				$targets = Page_Copies::targets( $id );
				$plan    = Page_Copies::sections( $id, array_column( array_filter( $targets, static fn( array $t ): bool => $t['state'] !== 'own' ), 'id' ) );
				\WP_CLI::log( sprintf( '%s (%s):', get_the_title( $id ), Page_Copies::kind( $id ) ) );
				\WP_CLI\Utils\format_items( 'table', array_map( static fn( array $s ): array => array( 'index' => $s['index'], 'role' => $s['role'], 'what' => $s['what'] . ( $s['why'] !== '' ? ' (' . $s['why'] . ')' : '' ), 'words' => $s['words'], 'head' => mb_strimwidth( $s['head'], 0, 60, '…' ) ), $plan ), array( 'index', 'role', 'what', 'words', 'head' ) );
				\WP_CLI::log( 'Made again for:' );
				\WP_CLI\Utils\format_items( 'table', array_map( static fn( array $t ): array => array( 'id' => $t['id'], 'title' => $t['title'], 'state' => $t['state'], 'chosen' => $t['chosen'] ? 'yes' : '', 'words' => $t['words'] ), $targets ), array( 'id', 'title', 'state', 'chosen', 'words' ) );
				return;
			case 'make':
				$all = Page_Copies::targets( $id );
				if ( isset( $assoc['targets'] ) ) {
					$want = array();
					foreach ( array_filter( array_map( 'trim', explode( ',', (string) $assoc['targets'] ) ) ) as $t ) {
						$want[] = str_starts_with( $t, '0:' ) ? array( 'id' => 0, 'title' => substr( $t, 2 ) ) : array( 'id' => (int) $t );
					}
				} else {
					$want = array_values( array_filter( $all, static fn( array $t ): bool => $t['chosen'] ) );
				}
				if ( $want === array() ) {
					\WP_CLI::error( 'No page to make.' );
				}
				$plan = Page_Copies::sections( $id, array_column( array_filter( $all, static fn( array $t ): bool => $t['state'] !== 'own' ), 'id' ) );
				foreach ( $want as $t ) {
					$r = Page_Copies::make( $id, $t, $plan, array( 'words' => (string) ( $assoc['words'] ?? 'auto' ) ) );
					if ( is_wp_error( $r ) ) {
						\WP_CLI::warning( ( $t['title'] ?? $t['id'] ) . ': ' . $r->get_error_message() );
						continue;
					}
					\WP_CLI::log( sprintf( '  %-40s #%d %s; kept %d, written %d (%s%s)%s%s', mb_strimwidth( (string) $r['title'], 0, 40, '…' ), $r['id'], $r['made'] ? 'made' : 'filled', $r['kept'], $r['written'], $r['words'] === 'ai' ? 'AI, ' . $r['engine'] : $r['places'] . ' lines to write', $r['old_page'] !== '' ? ', from ' . $r['old_page'] : '', $r['pictures'] > 0 ? '; ' . $r['pictures'] . ' pictures are the original\'s' : '', $r['error'] !== '' ? '; ' . $r['error'] : '' ) );
				}
				\WP_CLI::success( 'Done.' );
				return;
		}
	}
}

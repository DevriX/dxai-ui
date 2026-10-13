<?php
/**
 * WP-CLI: the team libraries (templates) a site keeps.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

/**
 *   wp dxai-ui library list                                        every template, which is in use
 *   wp dxai-ui library create <label> --theme=<slug> [--extends=<slug>]
 *   wp dxai-ui library add <slug> <file>                           one more export (a WordPress export, or block markup), and the library built again
 *   wp dxai-ui library build <slug>                                built again from its exports
 *   wp dxai-ui library delete <slug>                               gone, with everything in it
 */
final class Library_Cli {

	public static function register(): void {
		\WP_CLI::add_command(
			'dxai-ui library',
			array( self::class, 'run' ),
			array(
				'shortdesc' => 'The team libraries (templates) a site keeps: made from the team\'s exports, built here, trained with more.',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'action',
						'optional' => false,
						'options'  => array( 'list', 'create', 'add', 'build', 'delete' ),
					),
					array(
						'type'     => 'positional',
						'name'     => 'target',
						'optional' => true,
					),
					array(
						'type'     => 'positional',
						'name'     => 'file',
						'optional' => true,
					),
					array(
						'type'     => 'assoc',
						'name'     => 'theme',
						'optional' => true,
					),
					array(
						'type'     => 'assoc',
						'name'     => 'extends',
						'optional' => true,
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
		$action = (string) ( $args[0] ?? 'list' );
		$target = (string) ( $args[1] ?? '' );
		switch ( $action ) {
			case 'list':
				$current = (string) Template::current()['slug'];
				$rows    = array();
				foreach ( Template::all() as $t ) {
					$rows[] = array(
						'slug'    => (string) $t['slug'],
						'label'   => (string) $t['label'],
						'themes'  => implode( ',', (array) ( $t['themes'] ?? array() ) ) . ( in_array( 'dx', (array) ( $t['kinds'] ?? array() ), true ) ? ' (+ DX themes)' : '' ),
						'extends' => (string) ( $t['extends'] ?? '' ),
						'layouts' => (string) ( $t['layouts'] ?? ( empty( $t['stored'] ) ? 'built-in' : 0 ) ),
						'in use'  => (string) $t['slug'] === $current ? 'yes' : '',
					);
				}
				\WP_CLI\Utils\format_items( 'table', $rows, array( 'slug', 'label', 'themes', 'extends', 'layouts', 'in use' ) );
				if ( Template::is_generic() ) {
					\WP_CLI::line( 'This theme has no template of its own: the generic library is in use.' );
				}
				return;
			case 'create':
				$lib = Library_Store::create( $target, array( (string) ( $assoc['theme'] ?? get_stylesheet() ) ), (string) ( $assoc['extends'] ?? '' ) );
				if ( is_wp_error( $lib ) ) {
					\WP_CLI::error( $lib->get_error_message() );
				}
				\WP_CLI::success( sprintf( 'Made the library "%s" (%s) for %s. Add an export: wp dxai-ui library add %s <file>', $lib['label'], $lib['slug'], implode( ', ', $lib['themes'] ), $lib['slug'] ) );
				return;
			case 'add':
				$file = (string) ( $args[2] ?? '' );
				if ( $file === '' || ! is_readable( $file ) ) {
					\WP_CLI::error( 'Give the file to add: a WordPress export (.xml) or a file of block markup.' );
				}
				$lib = Library_Store::add_source( $target, $file, basename( $file ) );
				if ( is_wp_error( $lib ) ) {
					\WP_CLI::error( $lib->get_error_message() );
				}
				\WP_CLI::line( sprintf( 'Kept %s (%d sources now). Building…', basename( $file ), count( $lib['sources'] ) ) );
				self::build_and_say( $target );
				return;
			case 'build':
				self::build_and_say( $target );
				return;
			case 'delete':
				if ( Library_Store::get( $target ) === null ) {
					\WP_CLI::error( 'There is no such library.' );
				}
				if ( ! Library_Store::delete( $target ) ) {
					\WP_CLI::error( 'The library could not be removed.' );
				}
				\WP_CLI::success( sprintf( 'Removed the library "%s".', $target ) );
				return;
			default:
				\WP_CLI::error( 'Unknown action.' );
		}
	}

	private static function build_and_say( string $slug ): void {
		$lib = Library_Store::build( $slug );
		if ( is_wp_error( $lib ) ) {
			\WP_CLI::error( $lib->get_error_message() );
		}
		$report = (array) $lib['report'];
		foreach ( array_slice( (array) ( $report['lines'] ?? array() ), -12 ) as $line ) {
			\WP_CLI::line( $line );
		}
		\WP_CLI::success( sprintf( '%d layouts in the library "%s" (%s), from %d pages; %d not taken.', (int) $lib['layouts'], $lib['label'], implode( ', ', array_map( static fn( $r, $n ) => "$r $n", array_keys( $lib['by_role'] ), $lib['by_role'] ) ), (int) ( $report['pages'] ?? 0 ), count( (array) ( $report['errors'] ?? array() ) ) ) );
	}
}

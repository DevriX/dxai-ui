<?php
/**
 * WP-CLI: wp dxai-ui export / wp dxai-ui import.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Transfer;

/**
 * The same export and import the admin screens run, from the command line —
 * for moving a page between sites in a deploy script, or on a host where the
 * package is larger than PHP accepts as an upload. An import here runs in one
 * go, without the steps the admin screen splits a long one into.
 */
final class Transfer_Cli {

	public static function register(): void {
		\WP_CLI::add_command(
			'dxai-ui export',
			array( self::class, 'export' ),
			array(
				'shortdesc' => 'Export a converted page with its styles, media, parts, header and footer as a .dxai.zip package.',
				'synopsis'  => array(
					array(
						'type'        => 'positional',
						'name'        => 'page-id',
						'description' => 'The converted page to export.',
					),
					array(
						'type'        => 'assoc',
						'name'        => 'file',
						'optional'    => true,
						'description' => 'Where to write the package (default: <slug>.dxai.zip in the current folder).',
					),
					array(
						'type'        => 'assoc',
						'name'        => 'format',
						'optional'    => true,
						'default'     => 'table',
						'options'     => array( 'table', 'json' ),
						'description' => 'How to print the package listing.',
					),
				),
			)
		);
		\WP_CLI::add_command(
			'dxai-ui import',
			array( self::class, 'import' ),
			array(
				'shortdesc' => 'Import a .dxai.zip page package. Run as an administrator allowed unfiltered HTML (--user=<admin>; a Super Admin on multisite).',
				'synopsis'  => array(
					array(
						'type'        => 'positional',
						'name'        => 'file',
						'description' => 'The package to import.',
					),
					array(
						'type'        => 'assoc',
						'name'        => 'chrome',
						'optional'    => true,
						'default'     => 'auto',
						'options'     => array( 'auto', 'keep', 'install' ),
						'description' => 'keep: leave this site\'s menus and widgets alone; the page keeps its own header and footer as template parts. install: build the header in Appearance > Menus and the footer in Appearance > Widgets (on a classic theme, the whole site\'s header and footer). auto: what an import decides by itself for one page, which is keep.',
					),
					array(
						'type'        => 'flag',
						'name'        => 'publish',
						'optional'    => true,
						'description' => 'Publish a page this import creates, with the status it had on the source site. Without it a new page is a draft, to look at before it is public. A page the package made here before keeps its status.',
					),
					array(
						'type'        => 'flag',
						'name'        => 'dry-run',
						'optional'    => true,
						'description' => 'Check the package and say what it holds; write nothing.',
					),
					array(
						'type'        => 'assoc',
						'name'        => 'format',
						'optional'    => true,
						'default'     => 'summary',
						'options'     => array( 'summary', 'json' ),
						'description' => 'How to print the result.',
					),
				),
			)
		);
	}

	/**
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc
	 */
	public static function export( array $args, array $assoc ): void {
		$page_id = absint( $args[0] ?? 0 );
		$built   = ( new Page_Export() )->build( $page_id );
		if ( is_wp_error( $built ) ) {
			\WP_CLI::error( $built->get_error_message() );
		}
		$file = isset( $assoc['file'] ) && '' !== (string) $assoc['file'] ? (string) $assoc['file'] : getcwd() . DIRECTORY_SEPARATOR . $built['filename'];
		$ok   = copy( $built['path'], $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		Package::remove_dir( (string) $built['dir'] );
		if ( ! $ok ) {
			\WP_CLI::error( sprintf( 'The package could not be written to %s.', $file ) );
		}
		$rows = array();
		foreach ( $built['listing'] as $row ) {
			$rows[] = array(
				'path'  => $row['path'],
				'bytes' => $row['bytes'],
			);
		}
		if ( ( $assoc['format'] ?? 'table' ) === 'json' ) {
			\WP_CLI::line(
				(string) wp_json_encode(
					array(
						'file'     => $file,
						'bytes'    => (int) filesize( $file ),
						'sha1'     => (string) sha1_file( $file ),
						'listing'  => $rows,
						'warnings' => $built['warnings'],
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				)
			);
		} else {
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'path', 'bytes' ) );
			foreach ( $built['warnings'] as $warning ) {
				\WP_CLI::warning( (string) $warning );
			}
		}
		\WP_CLI::success( sprintf( 'Exported page %d to %s (%d entries, %d bytes).', $page_id, $file, count( $rows ), (int) filesize( $file ) ) );
	}

	/**
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc
	 */
	public static function import( array $args, array $assoc ): void {
		$file = (string) ( $args[0] ?? '' );
		if ( $file === '' || ! is_readable( $file ) ) {
			\WP_CLI::error( sprintf( 'Cannot read %s.', $file ) );
		}
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			\WP_CLI::error( Page_Import::refusal() . ' ' . ( is_multisite() ? 'Run it with --user=<a Super Admin>.' : 'Run it with --user=<an administrator>.' ) );
		}
		$result = ( new Page_Import() )->run(
			$file,
			wp_basename( $file ),
			array(
				'chrome'  => (string) ( $assoc['chrome'] ?? 'auto' ),
				'publish' => ! empty( $assoc['publish'] ),
				'dry_run' => ! empty( $assoc['dry-run'] ),
			)
		);
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		if ( ( $assoc['format'] ?? 'summary' ) === 'json' ) {
			\WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}
		if ( ! empty( $result['dry_run'] ) ) {
			\WP_CLI::success( sprintf( 'The package is valid: "%s" from %s, %d media, %d parts, %d patterns, %d navigation posts. Nothing was written.', $result['page']['title'], $result['source']['site_url'], $result['counts']['media'], $result['counts']['parts'], $result['counts']['patterns'], $result['counts']['navigations'] ) );

			return;
		}
		if ( ( $assoc['format'] ?? 'summary' ) !== 'json' ) {
			\WP_CLI::line( sprintf( 'Page:     %d %s (%s) %s', $result['page']['id'], $result['page']['created'] ? 'created' : 'updated', $result['page']['status'], $result['page']['view'] ) );
			\WP_CLI::line( sprintf( 'Media:    %d created, %d reused, %d failed', $result['media']['created'], $result['media']['reused'], $result['media']['failed'] ) );
			$mode = (array) ( $result['chrome_mode'] ?? array() );
			\WP_CLI::line( sprintf( 'Chrome:   %s (%s) %s', (string) ( $mode['mode'] ?? ( $result['chrome']['mode'] ?? '' ) ), (string) ( $mode['reason'] ?? '' ), (string) ( $mode['message'] ?? '' ) ) );
			foreach ( array( 'header', 'footer' ) as $area ) {
				$row = (array) $result['chrome'][ $area ];
				\WP_CLI::line( sprintf( '%-9s %s%s', ucfirst( $area ) . ':', (string) $row['mode'], isset( $row['part'] ) ? ' ' . $row['part'] : ( isset( $row['where'] ) ? ' (' . $row['where'] . ')' : '' ) ) );
			}
			foreach ( (array) ( $result['chrome_report']['notices'] ?? array() ) as $notice ) {
				\WP_CLI::line( '          ' . (string) $notice );
			}
			\WP_CLI::line( sprintf( 'Parts:    %d written, %d trashed, %d left published (the site keeps no trash)', count( $result['parts'] ), count( $result['parts_retired'] ), count( (array) ( $result['parts_kept'] ?? array() ) ) ) );
			\WP_CLI::line( sprintf( 'Patterns: %d   Navigation posts: %d', count( $result['patterns'] ), count( $result['navigations'] ) ) );
			\WP_CLI::line( sprintf( 'Brand:    %s', $result['brand']['action'] ) );
			foreach ( $result['warnings'] as $warning ) {
				\WP_CLI::warning( (string) $warning );
			}
		}
		if ( 'publish' !== $result['page']['status'] && ! empty( $result['page']['created'] ) ) {
			\WP_CLI::line( sprintf( 'The page is a %s: look at it (%s), then publish it, or import with --publish.', $result['page']['status'], $result['page']['view'] ) );
		}
		\WP_CLI::success( sprintf( 'Imported page %d.', $result['page']['id'] ) );
	}
}

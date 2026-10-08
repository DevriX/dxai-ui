<?php
/**
 * WP-CLI: wp dxai-ui design.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Support;

use DXAI_UI\Design\Document;
use DXAI_UI\Design\Document_Store;
use DXAI_UI\Structures\Design_Attach;

/**
 * A design's document from the command line:
 *
 *   wp dxai-ui design <id>                   the summary: what the design is, in numbers
 *   wp dxai-ui design <id> --format=json     the whole document
 *   wp dxai-ui design list                   every design on the site and whether it has a document
 *   wp dxai-ui design rebuild <id>|all       build the document of a design imported before documents were kept, from its
 *                                            conversion snapshot (every save keeps one); `all` does every design that has none
 *   wp dxai-ui design variation <id>         the design's theme.json style variation, as the file it would be
 *   wp dxai-ui design variation <id> --apply | --clear | --write
 *                                            make it the site's style / the theme's own again / write it into the theme's styles folder
 *
 * list, the summary and the variation read only; rebuild writes the document on the design's Home, --apply an option, --write a file
 * in the plugin's own theme, and nothing else.
 */
final class Design_Cli {

	public static function register(): void {
		\WP_CLI::add_command(
			'dxai-ui design',
			array( self::class, 'run' ),
			array(
				'shortdesc' => 'The document of a design (the Design IR): its pages, sections, header, tokens, breakpoints, behaviours and report.',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'id',
						'optional' => false,
					),
					array(
						'type'     => 'positional',
						'name'     => 'target',
						'optional' => true,
					),
					array(
						'type'     => 'assoc',
						'name'     => 'format',
						'options'  => array( 'summary', 'json' ),
						'optional' => true,
					),
					array(
						'type'     => 'flag',
						'name'     => 'force',
						'optional' => true,
					),
					array(
						'type'     => 'flag',
						'name'     => 'apply',
						'optional' => true,
					),
					array(
						'type'     => 'flag',
						'name'     => 'clear',
						'optional' => true,
					),
					array(
						'type'     => 'flag',
						'name'     => 'write',
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
		$what = (string) ( $args[0] ?? '' );
		if ( 'variation' === $what ) {
			$id   = (int) ( $args[1] ?? 0 );
			$home = Document_Store::home_of( $id );
			if ( $home < 1 ) {
				\WP_CLI::error( 'No design has a page with this id.' );
			}
			if ( ! empty( $assoc['apply'] ) ) {
				$done = \DXAI_UI\Design\Style_Variation::apply( $home );
				if ( is_wp_error( $done ) ) {
					\WP_CLI::error( $done->get_error_message() );
				}
				\WP_CLI::success( sprintf( 'The site\'s style is now the design\'s (#%d).', $home ) );

				return;
			}
			if ( ! empty( $assoc['clear'] ) ) {
				\WP_CLI::success( \DXAI_UI\Design\Style_Variation::clear() ? 'The site\'s style is the theme\'s own again.' : 'No design\'s style was applied.' );

				return;
			}
			if ( ! empty( $assoc['write'] ) ) {
				$done = \DXAI_UI\Design\Style_Variation::write( $home );
				if ( is_wp_error( $done ) ) {
					\WP_CLI::error( $done->get_error_message() );
				}
				\WP_CLI::success( sprintf( 'Written: %s (%d bytes).', $done['path'], $done['bytes'] ) );

				return;
			}
			$json = \DXAI_UI\Design\Style_Variation::json( $home );
			if ( is_wp_error( $json ) ) {
				\WP_CLI::error( $json->get_error_message() );
			}
			\WP_CLI::line( rtrim( $json ) );

			return;
		}
		if ( 'rebuild' === $what ) {
			$target = (string) ( $args[1] ?? '' );
			$ids    = 'all' === $target
				? array_map( static fn( \WP_Post $p ): int => (int) $p->ID, Design_Attach::designs() )
				: array( (int) $target );
			$done   = 0;
			foreach ( $ids as $id ) {
				if ( 'all' === $target && empty( $assoc['force'] ) && null !== Document_Store::load( $id ) ) {
					continue; // it has one; --force builds it again
				}
				$doc = Document_Store::rebuild( $id );
				if ( is_wp_error( $doc ) ) {
					\WP_CLI::warning( sprintf( '#%d: %s', $id, $doc->get_error_message() ) );
					continue;
				}
				++$done;
				$s = $doc->summary();
				\WP_CLI::line( sprintf( '#%d %s — %d page(s), %d section(s), header %s, tokens %s', $id, $s['title'], $s['pages'], $s['sections'], $s['header'], $s['tokens'] !== '' ? $s['tokens'] : 'none' ) );
			}
			\WP_CLI::success( sprintf( '%d document(s) built.', $done ) );

			return;
		}
		if ( 'list' === $what ) {
			foreach ( Design_Attach::designs() as $post ) {
				$doc = Document_Store::load( (int) $post->ID );
				\WP_CLI::line( sprintf( '#%d %s — %s', (int) $post->ID, $post->post_title, $doc ? 'document: ' . $doc->get( 'source' )['kind'] . ', ' . count( $doc->pages() ) . ' page(s)' : 'no document' ) );
			}

			return;
		}
		$id   = (int) $what;
		$home = Document_Store::home_of( $id );
		if ( $home < 1 ) {
			\WP_CLI::error( 'No design has a page with this id.' );
		}
		$doc = Document_Store::load( $home );
		if ( $doc === null ) {
			$why = Document_Store::error( $home );
			\WP_CLI::error( $why !== '' ? 'The design has no document: ' . $why : 'The design has no document yet: it was imported before documents were kept. Importing it again writes one.' );
		}
		\WP_CLI::line( self::render( $doc, (string) ( $assoc['format'] ?? 'summary' ) ) );
	}

	/** The document as the command prints it. */
	public static function render( Document $doc, string $format ): string {
		if ( 'json' === $format ) {
			$json = wp_json_encode( $doc->to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

			return is_string( $json ) ? $json : '{}';
		}
		$s     = $doc->summary();
		$lines = array(
			sprintf( '%s (%s)', $s['title'], $s['source'] ),
			sprintf( 'pages %d, sections %d, navigation items %d', $s['pages'], $s['sections'], $s['nav_items'] ),
			sprintf( 'header: %s, footer: %s', $s['header'], $s['footer'] ),
			sprintf( 'tokens: %s, fonts %d, breakpoints %s', $s['tokens'] !== '' ? $s['tokens'] : 'none', $s['fonts'], $s['breakpoints'] !== array() ? implode( ', ', array_map( static fn( $px ) => $px . 'px', $s['breakpoints'] ) ) : 'none' ),
			sprintf( 'images %d, behaviours %d (%d not carried), unevaluated %d', $s['images'], $s['behaviours'], $s['behaviours_left'], $s['unevaluated'] ),
			'fingerprint ' . $s['fingerprint'],
		);
		foreach ( $doc->pages() as $page ) {
			$roles   = array_map( static fn( array $sec ): string => (string) $sec['role'], is_array( $page['sections'] ?? null ) ? $page['sections'] : array() );
			$lines[] = sprintf( '  %s  %s%s — %s', str_pad( (string) $page['slug'], 32 ), (string) $page['title'], (int) ( $page['id'] ?? 0 ) > 0 ? ' (#' . (int) $page['id'] . ')' : '', $roles !== array() ? implode( ' › ', $roles ) : 'no sections' );
		}

		return implode( "\n", $lines );
	}
}

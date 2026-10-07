<?php
/**
 * WP-CLI: wp dxai-ui template-fonts.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Support;

use DXAI_UI\Theme\Template_Fonts;

/**
 * The fonts of the site's template (Template_Fonts) from the command line:
 *
 *   wp dxai-ui template-fonts status
 *   wp dxai-ui template-fonts install --design=<home-id>   host the design's fonts here and set them as the site's
 *   wp dxai-ui template-fonts remove                       stop using them and remove what was fetched: the theme's own choice is back
 *
 * An import that installs a design as the site's template does the same by itself (when the theme manages the site's fonts and nobody
 * chose them in the theme's settings).
 */
final class Template_Fonts_Cli {

	public static function register(): void {
		\WP_CLI::add_command(
			'dxai-ui template-fonts',
			array( self::class, 'run' ),
			array(
				'shortdesc' => 'The fonts of the site\'s template: the design\'s, hosted here and set as the site\'s (status, install, remove).',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'action',
						'options'  => array( 'status', 'install', 'remove' ),
						'optional' => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'design',
						'optional'    => true,
						'description' => 'With install: the design\'s Home page id.',
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
		if ( $action === 'install' ) {
			$home = (int) ( $assoc['design'] ?? 0 );
			if ( $home < 1 ) {
				\WP_CLI::error( 'Name the design: --design=<home-id>.' );
			}
			$chosen = Template_Fonts::choice();
			if ( $chosen !== array() ) {
				\WP_CLI::warning( 'The theme\'s settings choose the site\'s fonts (' . wp_json_encode( $chosen ) . '): they are used instead of the template\'s until they are cleared.' );
			}
			$done = Template_Fonts::install( $home );
			if ( is_wp_error( $done ) ) {
				\WP_CLI::error( $done->get_error_message() );
			}
			\WP_CLI::success( sprintf( 'Headline font %s, body font %s: %d faces, %d files, %d KB%s.', $done['heading'], $done['body'], $done['faces'], $done['files'], (int) round( $done['bytes'] / 1024 ), $done['complete'] ? '' : ' (some files could not be fetched)' ) );

			return;
		}
		if ( $action === 'remove' ) {
			$done = Template_Fonts::remove();
			\WP_CLI::success( $done['removed'] ? sprintf( 'The template\'s fonts are removed (%d files). The theme\'s own choice is back.', $done['files'] ) : 'There were no template fonts.' );

			return;
		}
		$s = Template_Fonts::status();
		if ( empty( $s['installed'] ) ) {
			\WP_CLI::line( 'No template fonts are installed.' );

			return;
		}
		\WP_CLI::line( sprintf( 'Headline font %s, body font %s, from %d "%s": %d files, %d KB%s.', $s['heading'], $s['body'], $s['design'], $s['title'], $s['files'], (int) round( $s['bytes'] / 1024 ), $s['complete'] ? '' : ', incomplete' ) );
		\WP_CLI::line( $s['live'] ? 'In use.' : 'Not in use: ' . ( $s['choice'] !== array() ? 'the theme\'s settings choose the site\'s fonts (' . wp_json_encode( $s['choice'] ) . ').' : 'a filter says so.' ) );
	}
}

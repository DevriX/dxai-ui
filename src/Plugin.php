<?php
/**
 * Plugin bootstrap.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI;

use DXAI_UI\Admin\Dashboard;
use DXAI_UI\API\Rest_Registrar;
use DXAI_UI\Blocks\Assets;
use DXAI_UI\Blocks\Design_Blocks;
use DXAI_UI\Blocks\Form_Block;
use DXAI_UI\Blocks\Pattern_Block;
use DXAI_UI\Blocks\Slider_Block;
use DXAI_UI\Content\Content_Types;
use DXAI_UI\Settings\Options;
use DXAI_UI\Theme\Blank_Template;
use DXAI_UI\Theme\Design_Theme_Json;
use DXAI_UI\Theme\Part_Theme_Binding;
use DXAI_UI\Support\Kses_Styles;
use DXAI_UI\Support\Style_Repair;

final class Plugin {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		load_plugin_textdomain( 'dxai-ui', false, dirname( plugin_basename( DXAI_UI_FILE ) ) . '/languages' );

		// Deactivated, but booted by the must-use runtime to keep converted pages
		// rendering (Support\Runtime): the admin screens, imports, the crawl, the
		// repair batch and export/import stay off; whatever draws a converted page
		// and keeps its forms working boots as usual.
		$render_only = \DXAI_UI\Support\Runtime::render_only();

		( new Options() )->register();
		( new Content_Types() )->register();
		if ( ! $render_only ) {
			( new Dashboard() )->register();
		}
		( new Rest_Registrar() )->register();
		( new Assets() )->register();
		( new Pattern_Block() )->register();
		( new Design_Blocks() )->register();
		( new Form_Block() )->register();
		( new Slider_Block() )->register();
		( new Blank_Template() )->register();
		// The imported design's tokens as editable global-styles presets.
		( new Design_Theme_Json() )->register();
		( new Part_Theme_Binding() )->register();
		( new Kses_Styles() )->register();
		if ( ! $render_only ) {
			( new Style_Repair() )->register();
		}
		// The site-from-menu crawl as a stepped job (and its cron fallback).
		if ( ! $render_only ) {
			( new \DXAI_UI\Structures\Crawl_Job() )->register();
		}
		// The design's scope element around a page's top-level sections.
		( new \DXAI_UI\Structures\Page_Scope() )->register();
		// The design an ordinary page shows when it holds blocks copied from a design page.
		( new \DXAI_UI\Structures\Design_Attach() )->register();
		// …and on such a page of the theme: the theme's CSS kept off the copied sections, and what their design
		// page gives them (the inherited-value reset, the canvas rules, tokens, the web fonts from uploads).
		( new \DXAI_UI\Theme\Theme_Fence() )->register();
		// A design's colours following the active theme's (the rule itself is applied in both modes, by Token_Styles).
		if ( ! $render_only ) {
			( new \DXAI_UI\Theme\Theme_Binding() )->register();
		}
		( new \DXAI_UI\Blocks\Attached_Styles() )->register();
		// "Edit header" / "Edit footer" in the admin bar on converted pages.
		if ( ! $render_only ) {
			( new \DXAI_UI\Admin\Part_Shortcuts() )->register();
		}
		// The rules for the dxs- classes that replace inline styles.
		( new \DXAI_UI\Blocks\Style_Rules() )->register();
		// Header from Appearance > Menus, footer from Appearance > Widgets.
		( new \DXAI_UI\Chrome\Chrome() )->register();
		// Converted pages on a classic theme (the DevriX themes): no theme CSS/JS, no script delay, a <title>.
		( new \DXAI_UI\Theme\Theme_Compat() )->register();
		// Export a page with its styles / import a package (Pages row action, admin-post, WP-CLI).
		if ( ! $render_only ) {
			( new \DXAI_UI\Transfer\Transfer_Admin() )->register();
		}
		// The must-use loader that does the above while the plugin is deactivated.
		( new \DXAI_UI\Support\Runtime() )->register();
		add_filter( 'pre_get_document_title', array( $this, 'document_title' ), 20 );
	}

	/**
	 * Prefer the original Design.com SEO <title> on the frontend tab,
	 * while the WordPress page name stays short for admin/menus.
	 */
	public function document_title( string $title ): string {
		if ( ! is_singular() ) {
			return $title;
		}
		$seo = (string) get_post_meta( (int) get_the_ID(), \DXAI_UI\Support\Page_Title::SEO_META, true );
		return $seo !== '' ? $seo : $title;
	}

	/**
	 * @param bool $network_wide Whether the plugin is being network-activated on a multisite.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( version_compare( PHP_VERSION, '8.2.0', '<' ) ) {
			self::refuse_activation( esc_html__( 'DXAI-UI requires PHP 8.2 or higher.', 'dxai-ui' ), $network_wide );
			return;
		}

		global $wp_version;
		// The oldest WordPress the plugin supports, as its header and readme say: 6.6 registers the
		// react-jsx-runtime script the admin screens are built against.
		if ( version_compare( (string) $wp_version, '6.6', '<' ) ) {
			self::refuse_activation( esc_html__( 'DXAI-UI requires WordPress 6.6 or higher.', 'dxai-ui' ), $network_wide );
			return;
		}

		$missing = self::missing_extensions();
		if ( array() !== $missing ) {
			self::refuse_activation(
				esc_html(
					sprintf(
						/* translators: %s: comma-separated PHP extension names. */
						__( 'DXAI-UI needs these PHP extensions, which this server does not load: %s. Ask your host to enable them, then activate the plugin again.', 'dxai-ui' ),
						implode( ', ', $missing )
					)
				),
				$network_wide
			);
			return;
		}

		/*
		 * Network activation sets up every site, up to a bound.
		 *
		 * Nothing here is a hard prerequisite of a site working, which is what
		 * makes the bound safe: Options::get() merges the defaults over
		 * whatever is stored, and Zip_Extractor writes uploads/dxai-ui/index.php
		 * itself before it first unpacks there. So a site past the bound, or
		 * one created after the network activation, behaves the same - it just
		 * has no stored defaults row until its settings are saved. The bound
		 * is there because activation runs in one web request, which hosts
		 * kill after 60 to 120 seconds.
		 *
		 * No per-site rewrite flush: switch_to_blog() does not swap the
		 * $wp_rewrite object, so flushing inside the loop writes the main
		 * site's rules into every site - and there is nothing to flush anyway,
		 * both post types register with 'public' => false and
		 * 'rewrite' => false (Content_Types::post_types()).
		 */
		if ( $network_wide && is_multisite() ) {
			$sites = get_sites(
				array(
					'fields'     => 'ids',
					'number'     => max( 1, (int) apply_filters( 'dxai_ui_network_activation_sites', 500 ) ),
					'network_id' => get_current_network_id(),
				)
			);
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install_site();
				\DXAI_UI\Support\Runtime::ensure( true );
				restore_current_blog();
			}
			return;
		}

		self::install_site();
		\DXAI_UI\Support\Runtime::ensure( true );

		$types = new Content_Types();
		$types->post_types();
		$types->menus();

		flush_rewrite_rules( false );
	}

	/**
	 * What activation does for one site: the default settings row, and the
	 * uploads folder with an index.php so a server that lists directories
	 * does not list it. The folder holds the served pattern and site
	 * stylesheets (Tailwind_Purger), so it gets no deny rule - only the
	 * temporary import folders inside it do (Zip_Extractor).
	 */
	private static function install_site(): void {
		Options::install_defaults();

		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return;
		}
		$dir = trailingslashit( $upload['basedir'] ) . \DXAI_UI\Support\Upload_Paths::DIR;
		wp_mkdir_p( $dir );
		if ( is_dir( $dir ) && ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * PHP extensions the plugin cannot run without, that this server lacks.
	 *
	 * Measured, not assumed: DOMDocument is used by eight classes, the
	 * HTML-to-blocks compiler and the SVG sanitizer among them, and seven of
	 * those call the libxml error functions around it. mbstring because Dc_Js calls mb_str_split(),
	 * mb_strpos() and mb_strrpos() unguarded, and WordPress polyfills only
	 * mb_chr, mb_ord, mb_substr and mb_strlen (wp-includes/compat.php) - so
	 * without it the first Claude Design import is a fatal error. For ZIPs,
	 * either the zip extension or zlib: without ZipArchive the archive is
	 * read through the PclZip in wp-admin, whose constructor calls die()
	 * when zlib is missing.
	 *
	 * @return array<int, string>
	 */
	private static function missing_extensions(): array {
		$missing = array();
		foreach ( array( 'dom', 'libxml', 'mbstring' ) as $extension ) {
			if ( ! extension_loaded( $extension ) ) {
				$missing[] = $extension;
			}
		}
		if ( ! class_exists( \ZipArchive::class ) && ! function_exists( 'gzopen' ) ) {
			$missing[] = 'zip';
		}

		return $missing;
	}

	/**
	 * Stop an activation, the way it has always been stopped here: the
	 * plugin is deactivated again (network-wide when that is how it was
	 * being activated) and wp_die() shows the reason, with a way back.
	 *
	 * @param string $message Already escaped.
	 */
	private static function refuse_activation( string $message, bool $network_wide ): void {
		// Loaded on every wp-admin and WP-CLI activation; not by code that
		// calls activate_plugin() from a front-end or cron context.
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		deactivate_plugins( plugin_basename( DXAI_UI_FILE ), false, $network_wide ? true : null );
		wp_die(
			$message, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller.
			esc_html__( 'DXAI-UI could not be activated', 'dxai-ui' ),
			array( 'back_link' => true )
		);
	}

	/**
	 * Undo what runs on its own: the rewrite rules, and the crawl job's cron
	 * fallback. Crawl_Job clears a job's `dxai_ui_crawl_tick` event when the
	 * job finishes or is abandoned, and neither can happen once the plugin is
	 * inactive - so on a host with DISABLE_WP_CRON and no system cron the
	 * event would sit in the autoloaded `cron` option for good. A job left
	 * mid-crawl is not lost: its option stays, and once the plugin is active
	 * again, reopening the wizard drives it on (rescheduling the fallback).
	 *
	 * A network deactivation clears every site's event, over the same sites
	 * activation walks: each site keeps its own `cron` option.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			$sites = get_sites(
				array(
					'fields'     => 'ids',
					'number'     => max( 1, (int) apply_filters( 'dxai_ui_network_activation_sites', 500 ) ),
					'network_id' => get_current_network_id(),
				)
			);
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				wp_unschedule_hook( \DXAI_UI\Structures\Crawl_Job::CRON_HOOK );
				restore_current_blog();
			}
		} else {
			wp_unschedule_hook( \DXAI_UI\Structures\Crawl_Job::CRON_HOOK );
		}
		flush_rewrite_rules( false );
	}
}

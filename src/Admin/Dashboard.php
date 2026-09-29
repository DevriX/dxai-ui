<?php
/**
 * React admin dashboard loader.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Admin;

final class Dashboard {

	public const SLUG          = 'dxai-ui';
	public const SLUG_LIBRARY  = 'dxai-ui-library';
	public const SLUG_FORMS    = 'dxai-ui-forms';
	public const SLUG_SETTINGS = 'dxai-ui-settings';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DXAI_UI_FILE ), array( $this, 'action_links' ) );
	}

	public function menu(): void {
		add_menu_page(
			__( 'DXAI Convert', 'dxai-ui' ),
			__( 'DXAI Convert', 'dxai-ui' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-art',
			58
		);

		add_submenu_page(
			self::SLUG,
			__( 'Convert', 'dxai-ui' ),
			__( 'Convert', 'dxai-ui' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Library', 'dxai-ui' ),
			__( 'Library', 'dxai-ui' ),
			'manage_options',
			self::SLUG_LIBRARY,
			array( $this, 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Form entries', 'dxai-ui' ),
			__( 'Form entries', 'dxai-ui' ),
			'manage_options',
			self::SLUG_FORMS,
			array( $this, 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Settings', 'dxai-ui' ),
			__( 'Settings', 'dxai-ui' ),
			'manage_options',
			self::SLUG_SETTINGS,
			array( $this, 'render' )
		);

		add_options_page(
			__( 'DXAI-UI', 'dxai-ui' ),
			__( 'DXAI-UI', 'dxai-ui' ),
			'manage_options',
			self::SLUG_SETTINGS,
			array( $this, 'render' )
		);
	}

	/**
	 * @param array<string, string> $links
	 * @return array<string, string>
	 */
	public function action_links( array $links ): array {
		$convert  = '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Convert', 'dxai-ui' ) . '</a>';
		$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG_SETTINGS ) ) . '">' . esc_html__( 'Settings', 'dxai-ui' ) . '</a>';
		array_unshift( $links, $convert, $settings );

		return $links;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'dxai-ui' ) );
		}

		$screen = $this->current_screen();
		$title  = $this->title_for( $screen );

		echo '<div class="wrap dxai-ui-wrap">';
		echo '<h1 class="screen-reader-text">' . esc_html( $title ) . '</h1>';
		echo '<div id="dxai-ui-app" class="dxai-ui-admin">';
		echo '<div class="dxai-ui-php-fallback notice notice-info"><p>';
		echo esc_html__( 'DXAI Convert is loading. If this message stays, JavaScript failed to start. Use the links below.', 'dxai-ui' );
		echo '</p><p>';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Convert', 'dxai-ui' ) . '</a> · ';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG_LIBRARY ) ) . '">' . esc_html__( 'Library', 'dxai-ui' ) . '</a> · ';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG_FORMS ) ) . '">' . esc_html__( 'Form entries', 'dxai-ui' ) . '</a> · ';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG_SETTINGS ) ) . '">' . esc_html__( 'Settings', 'dxai-ui' ) . '</a>';
		echo '</p></div>';
		echo '</div></div>';
	}

	public function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'dxai-ui' ) ) {
			return;
		}

		wp_enqueue_style( 'wp-components' );
		/*
		 * No web font. This screen used to load Instrument Sans and Serif from
		 * Google Fonts, which admin.css never uses (its stacks are Inter and
		 * the system faces) — a request to a third party with every admin's IP
		 * address on every visit, which plugin review and GDPR both object to.
		 */
		wp_enqueue_style(
			'dxai-ui-admin',
			DXAI_UI_URL . 'assets/css/admin.css',
			array( 'wp-components' ),
			DXAI_UI_VERSION
		);

		$asset_file = DXAI_UI_DIR . 'assets/build/index.asset.php';
		$script     = DXAI_UI_DIR . 'assets/build/index.js';
		$handle     = 'dxai-ui-admin';

		if ( is_readable( $asset_file ) && is_readable( $script ) ) {
			$asset = include $asset_file;
			$deps  = is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' );
			$ver   = is_string( $asset['version'] ?? null ) ? $asset['version'] : DXAI_UI_VERSION;

			wp_enqueue_script( $handle, DXAI_UI_URL . 'assets/build/index.js', $deps, $ver, true );

			$built_css = DXAI_UI_DIR . 'assets/build/index.css';
			if ( is_readable( $built_css ) ) {
				wp_enqueue_style(
					'dxai-ui-admin-build',
					DXAI_UI_URL . 'assets/build/index.css',
					array( 'wp-components', 'dxai-ui-admin' ),
					$ver
				);
			}
		} else {
			$legacy = DXAI_UI_DIR . 'assets/js/admin.js';
			wp_enqueue_script(
				$handle,
				DXAI_UI_URL . 'assets/js/admin.js',
				array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
				is_readable( $legacy ) ? (string) filemtime( $legacy ) : DXAI_UI_VERSION,
				true
			);
		}

		wp_set_script_translations( $handle, 'dxai-ui', DXAI_UI_DIR . 'languages' );

		wp_localize_script(
			$handle,
			'dxaiUI',
			array(
				'restUrl' => esc_url_raw( rest_url( DXAI_UI_REST_NAMESPACE . '/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'screen'  => $this->current_screen(),
				'version' => DXAI_UI_VERSION,
				// The icon runtime the preview uses, from the plugin itself
				// rather than a CDN a host's CSP or a firewall may block.
				'lucide'  => DXAI_UI_URL . 'assets/vendor/lucide.min.js',
				// Which engine and model an AI crawl would use, whether a key is
				// set (never the key), the upload limit and the admin links —
				// so the import screen can say all of it on its first render.
				'import'  => $this->import_info(),
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function import_info(): array {
		if ( $this->current_screen() !== 'convert' ) {
			return array();
		}
		try {
			return \DXAI_UI\API\Converter_Controller::import_info_payload();
		} catch ( \Throwable $e ) {
			// The screen asks /import-info again and degrades without it; a
			// fact it cannot state must never keep the admin page from loading.
			return array();
		}
	}

	public function body_class( string $classes ): string {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $page, array( self::SLUG, self::SLUG_LIBRARY, self::SLUG_FORMS, self::SLUG_SETTINGS ), true ) ) {
			$classes .= ' dxai-ui-admin-screen';
		}

		return $classes;
	}

	private function current_screen(): string {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : self::SLUG; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return match ( $page ) {
			self::SLUG_LIBRARY  => 'library',
			self::SLUG_FORMS    => 'forms',
			self::SLUG_SETTINGS => 'settings',
			default             => 'convert',
		};
	}

	private function title_for( string $screen ): string {
		return match ( $screen ) {
			'library'  => __( 'Library', 'dxai-ui' ),
			'forms'    => __( 'Form entries', 'dxai-ui' ),
			'settings' => __( 'DXAI-UI Settings', 'dxai-ui' ),
			default    => __( 'DXAI Convert', 'dxai-ui' ),
		};
	}
}

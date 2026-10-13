<?php
/**
 * The admin menu "DX" and the React app its screens are.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Admin;

/**
 * One top-level menu, DX, with five screens: Designs (the landing: every design on the site and, by a hash route, one
 * design's page with its tabs), Convert (the wizard), Site (the site's template, header, footer and what the imports
 * made for the whole site), Form entries and Settings. Each is one wp-admin page that mounts the same React app
 * (assets/src/admin), told which screen it is; WordPress draws the menu and the page title, the app draws the rest.
 *
 * The Library screen of earlier versions (dxai-ui-library) is the Designs screen now: its address is sent there.
 */
final class Dashboard {

	/** The landing screen: Designs. Also the menu's own slug. */
	public const SLUG          = 'dxai-ui';
	public const SLUG_CONVERT  = 'dxai-ui-convert';
	public const SLUG_SITE     = 'dxai-ui-site';
	public const SLUG_FORMS    = 'dxai-ui-forms';
	public const SLUG_SETTINGS = 'dxai-ui-settings';

	/** The screen of earlier versions whose panels are now a design's tabs and the Site screen; sent to Designs. */
	public const SLUG_LIBRARY = 'dxai-ui-library';

	/** Screen name => slug, in menu order. */
	public const SCREENS = array(
		'designs'  => self::SLUG,
		'convert'  => self::SLUG_CONVERT,
		'site'     => self::SLUG_SITE,
		'forms'    => self::SLUG_FORMS,
		'settings' => self::SLUG_SETTINGS,
	);

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DXAI_UI_FILE ), array( $this, 'action_links' ) );
	}

	public function menu(): void {
		// Here, on admin_menu: WordPress refuses an address no menu has (403) right after this action, before admin_init.
		$this->forward_library();

		add_menu_page(
			__( 'DX', 'dxai-ui' ),
			__( 'DX', 'dxai-ui' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-art',
			58
		);

		foreach ( self::labels() as $screen => $label ) {
			add_submenu_page(
				self::SLUG,
				$label,
				$label,
				'manage_options',
				self::SCREENS[ $screen ],
				array( $this, 'render' )
			);
		}

		add_options_page(
			__( 'DX UI', 'dxai-ui' ),
			__( 'DX UI', 'dxai-ui' ),
			'manage_options',
			self::SLUG_SETTINGS,
			array( $this, 'render' )
		);
	}

	/** The old Library address opens Designs, so a bookmark or a link in an older summary still lands somewhere. */
	public function forward_library(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $page === self::SLUG_LIBRARY && current_user_can( 'manage_options' ) ) {
			wp_safe_redirect( self::url( 'designs' ), 301 );
			exit;
		}
	}

	/** The admin URL of a screen. */
	public static function url( string $screen ): string {
		return admin_url( 'admin.php?page=' . ( self::SCREENS[ $screen ] ?? self::SLUG ) );
	}

	/**
	 * @return array<string, string> screen => its name in the menu.
	 */
	public static function labels(): array {
		return array(
			'designs'  => __( 'Designs', 'dxai-ui' ),
			'convert'  => __( 'Convert', 'dxai-ui' ),
			'site'     => __( 'Site', 'dxai-ui' ),
			'forms'    => __( 'Form entries', 'dxai-ui' ),
			'settings' => __( 'Settings', 'dxai-ui' ),
		);
	}

	/**
	 * @param array<string, string> $links
	 * @return array<string, string>
	 */
	public function action_links( array $links ): array {
		$designs  = '<a href="' . esc_url( self::url( 'designs' ) ) . '">' . esc_html__( 'Designs', 'dxai-ui' ) . '</a>';
		$settings = '<a href="' . esc_url( self::url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'dxai-ui' ) . '</a>';
		array_unshift( $links, $designs, $settings );

		return $links;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'dxai-ui' ) );
		}

		$screen = $this->current_screen();
		$title  = self::labels()[ $screen ] ?? __( 'DX', 'dxai-ui' );

		echo '<div class="wrap dxai-ui-wrap">';
		echo '<div id="dxai-ui-app" class="dxai-ui-admin" data-screen="' . esc_attr( $screen ) . '">';
		// Until the app mounts (and for good if its script never runs): the page's title and the way to every screen.
		echo '<h1 class="screen-reader-text">' . esc_html( $title ) . '</h1>';
		echo '<div class="dxai-ui-php-fallback notice notice-info"><p>';
		echo esc_html__( 'DX is loading. If this message stays, JavaScript failed to start. Use the links below.', 'dxai-ui' );
		echo '</p><p>';
		$links = array();
		foreach ( self::labels() as $name => $label ) {
			$links[] = '<a href="' . esc_url( self::url( $name ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo wp_kses_post( implode( ' · ', $links ) );
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
		 * Google Fonts, which admin.css never uses (its stacks are the system
		 * faces) — a request to a third party with every admin's IP address on
		 * every visit, which plugin review and GDPR both object to.
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

		$links = array();
		foreach ( array_keys( self::SCREENS ) as $name ) {
			$links[ $name ] = self::url( $name );
		}
		$links['menus']   = current_theme_supports( 'menus' ) ? admin_url( 'nav-menus.php' ) : '';
		$links['widgets'] = current_theme_supports( 'widgets' ) ? admin_url( 'widgets.php' ) : '';
		$links['themes']  = admin_url( 'themes.php' );

		wp_localize_script(
			$handle,
			'dxaiUI',
			array(
				'restUrl' => esc_url_raw( rest_url( DXAI_UI_REST_NAMESPACE . '/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'screen'  => $this->current_screen(),
				'labels'  => self::labels(),
				// Where each screen is, so the app never spells an admin address itself.
				'links'   => $links,
				'version' => DXAI_UI_VERSION,
				'theme'   => wp_get_theme()->get( 'Name' ),
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
		if ( in_array( $page, self::SCREENS, true ) ) {
			$classes .= ' dxai-ui-admin-screen';
		}

		return $classes;
	}

	private function current_screen(): string {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : self::SLUG; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$name = array_search( $page, self::SCREENS, true );

		return is_string( $name ) ? $name : 'designs';
	}
}

<?php
/**
 * REST: the site's template — its header, footer and fonts — and the DX Base theme.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\API;

use DXAI_UI\Chrome\Chrome_Choice;
use DXAI_UI\Theme\Base_Theme;
use DXAI_UI\Theme\Capabilities;
use DXAI_UI\Theme\Template_Fonts;
use DXAI_UI\Theme\Theme_Options;

/**
 * GET  /template  the active theme and whether it is a DX theme, the design whose header and footer are the site's now, the template's
 *                 fonts, and DX Base: whether it is installed, which version, and what this person may do about it.
 * POST /template  base-install: copy DX Base into the themes folder (or list it from the plugin's own folder where that cannot be
 *                 written), base-activate: switch the site to it. The two are separate steps and nothing here switches the theme by
 *                 itself; nothing deletes a theme.
 *
 * The routes ask what the import routes ask (manage_options and unfiltered_html); installing a theme asks install_themes as well and
 * switching to it switch_themes, which on a network belong to a Super Admin and to the sites a theme is enabled for.
 */
final class Template_Controller {

	public function register_routes(): void {
		register_rest_route(
			DXAI_UI_REST_NAMESPACE,
			'/template',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run' ),
					'permission_callback' => array( $this, 'permissions' ),
					'args'                => array(
						'action' => array(
							'type'     => 'string',
							'enum'     => array( 'base-install', 'base-activate' ),
							'required' => true,
						),
					),
				),
			)
		);
	}

	public function permissions(): bool|\WP_Error {
		return ( new Converter_Controller() )->publish_permissions();
	}

	public function index(): \WP_REST_Response {
		return new \WP_REST_Response( $this->report() );
	}

	public function run( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$action = (string) $request->get_param( 'action' );
		$done   = array();
		if ( 'base-install' === $action ) {
			if ( ! $this->may_install() ) {
				return new \WP_Error( 'dxai_ui_cannot_install_themes', $this->install_note(), array( 'status' => rest_authorization_required_code() ) );
			}
			$did = Base_Theme::install();
			if ( is_wp_error( $did ) ) {
				return new \WP_Error( $did->get_error_code(), $did->get_error_message(), array( 'status' => 'dxai_ui_base_theme_taken' === $did->get_error_code() ? 409 : 500 ) );
			}
			$done = $did;
		} else {
			if ( ! current_user_can( 'switch_themes' ) ) {
				return new \WP_Error( 'dxai_ui_cannot_switch_themes', $this->activate_note(), array( 'status' => rest_authorization_required_code() ) );
			}
			if ( ! wp_get_theme( Base_Theme::SLUG )->exists() ) {
				return new \WP_Error( 'dxai_ui_base_theme_missing', __( 'Install the DX Base theme first.', 'dxai-ui' ), array( 'status' => 409 ) );
			}
			if ( Base_Theme::status()['foreign'] ) {
				return new \WP_Error( 'dxai_ui_base_theme_taken', __( 'The theme in the dx-base folder is not DX Base, so the site was not switched to it.', 'dxai-ui' ), array( 'status' => 409 ) );
			}
			if ( is_multisite() && ! wp_get_theme( Base_Theme::SLUG )->is_allowed() ) {
				return new \WP_Error( 'dxai_ui_base_theme_not_allowed', $this->activate_note(), array( 'status' => 409 ) );
			}
			$did = Base_Theme::activate();
			if ( is_wp_error( $did ) ) {
				return new \WP_Error( $did->get_error_code(), $did->get_error_message(), array( 'status' => 409 ) );
			}
			$done = array( 'activated' => true );
		}

		return new \WP_REST_Response( $this->report() + array( 'done' => $done ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function report(): array {
		$theme  = wp_get_theme();
		$base   = Base_Theme::status();
		$owners = Chrome_Choice::owners();
		$fonts  = Template_Fonts::status();
		$ours   = wp_get_theme( Base_Theme::SLUG );

		$can_install  = $this->may_install();
		$can_activate = current_user_can( 'switch_themes' ) && $base['installed'] && ! $base['foreign'] && ( ! is_multisite() || $ours->is_allowed() );
		$primary      = Theme_Options::image_id( 'logos', 'primary_logo' );
		$logo         = $primary > 0 ? $primary : (int) get_theme_mod( 'custom_logo' );
		if ( $primary > 0 ) {
			// The theme's own settings (American Restoration with ACF) hold the logo the header uses.
			$logo_at = admin_url( 'admin.php?page=' . Theme_Options::PAGE );
		} elseif ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			$logo_at = admin_url( 'site-editor.php' );
		} else {
			$logo_at = admin_url( 'customize.php?autofocus[section]=title_tagline' );
		}

		return array(
			'theme'  => array(
				'name' => html_entity_decode( (string) $theme->get( 'Name' ), ENT_QUOTES, 'UTF-8' ),
				'slug' => (string) get_stylesheet(),
				'dx'   => Capabilities::is_dx(),
				'base' => Capabilities::is_base(),
			),
			'base'   => array(
				'name'          => 'DX Base',
				'bundled'       => $base['bundled'],
				'installed'     => $base['installed'],
				'foreign'       => $base['foreign'],
				'version'       => $base['version'],
				'mode'          => $base['mode'],
				'active'        => $base['active'],
				'writable'      => $base['writable'],
				'needs_plugin'  => $base['needs_plugin'],
				'update'        => $base['installed'] && $base['bundled'] !== '' && version_compare( $base['version'], $base['bundled'], '<' ),
				'can_install'   => $can_install,
				'can_activate'  => $can_activate,
				'install_note'  => $can_install ? '' : $this->install_note(),
				'activate_note' => $can_activate ? '' : $this->activate_note(),
			),
			'header' => $this->owner( (array) ( $owners['header'] ?? array() ) ),
			'footer' => $this->owner( (array) ( $owners['footer'] ?? array() ) ),
			'fonts'  => ! empty( $fonts['installed'] ) && ! empty( $fonts['live'] ) ? array(
				'heading' => (string) $fonts['heading'],
				'body'    => (string) $fonts['body'],
				'title'   => (string) $fonts['title'],
			) : null,
			'logo'   => $logo > 0 ? array(
				'id'  => $logo,
				'url' => (string) wp_get_attachment_image_url( $logo, 'medium' ),
				'alt' => (string) get_post_meta( $logo, '_wp_attachment_image_alt', true ),
			) : null,
			'links'  => array(
				'menus'   => admin_url( 'nav-menus.php?action=locations' ),
				'widgets' => admin_url( 'widgets.php' ),
				'themes'  => admin_url( 'themes.php' ),
				'logo'    => $logo_at,
			),
		);
	}

	/**
	 * Whether this person may install DX Base: they may install themes, or the site does not let plugins change its files
	 * (DISALLOW_FILE_MODS: install_themes is refused for everybody then) and the theme is only listed from this plugin's folder, where
	 * nothing is written and switching themes is the right to ask for. On a network a Super Admin installs.
	 */
	private function may_install(): bool {
		if ( current_user_can( 'install_themes' ) ) {
			return true;
		}

		return ! is_multisite() && ! wp_is_file_mod_allowed( 'dxai_ui_base_theme' ) && current_user_can( 'switch_themes' );
	}

	/**
	 * One of Chrome_Choice::owners() for the screen: whose it is and where to open it, or null when none is installed.
	 *
	 * @param array<string, mixed> $who
	 * @return array<string, mixed>|null
	 */
	private function owner( array $who ): ?array {
		if ( $who === array() ) {
			return null;
		}

		return array(
			'title' => html_entity_decode( (string) ( $who['title'] ?? '' ), ENT_QUOTES, 'UTF-8' ),
			'live'  => ! empty( $who['live'] ),
			'edit'  => (string) ( $who['edit'] ?? '' ),
			'view'  => (string) ( $who['view'] ?? '' ),
		);
	}

	private function install_note(): string {
		return is_multisite()
			? __( 'On a network a Super Admin installs themes. Ask them to install DX Base from this plugin’s folder or to run: wp dxai-ui base-theme install', 'dxai-ui' )
			: __( 'Your account cannot install themes. An administrator can install DX Base here, or run: wp dxai-ui base-theme install', 'dxai-ui' );
	}

	private function activate_note(): string {
		return is_multisite()
			? __( 'DX Base is not enabled for this site. A Super Admin enables it in Network Admin › Themes; then it can be switched to here.', 'dxai-ui' )
			: __( 'Your account cannot switch the site’s theme.', 'dxai-ui' );
	}
}

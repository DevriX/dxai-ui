<?php
/**
 * Plugin Name:       DXAI-UI
 * Plugin URI:        https://devrix.com/dxai-ui
 * Description:       Convert Figma, Lovable, and generated frontend ZIPs into native Gutenberg structures using Claude, ChatGPT, Grok, or DeepSeek.
 * Version:           0.4.0-beta.11
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Author:            DevriX
 * Author URI:        https://devrix.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dxai-ui
 * Domain Path:       /languages
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DXAI_UI_VERSION', '0.4.0-beta.11' );
define( 'DXAI_UI_FILE', __FILE__ );
define( 'DXAI_UI_DIR', plugin_dir_path( __FILE__ ) );
define( 'DXAI_UI_URL', plugin_dir_url( __FILE__ ) );
define( 'DXAI_UI_REST_NAMESPACE', 'dxai-ui/v1' );

require_once DXAI_UI_DIR . 'src/Autoloader.php';

DXAI_UI\Autoloader::register();

// `activate_{plugin}` passes whether this is a network activation; activate()
// sets up every site of the network when it is.
register_activation_hook( DXAI_UI_FILE, static function ( $network_wide = false ): void {
	DXAI_UI\Plugin::activate( (bool) $network_wide );
} );

register_deactivation_hook( DXAI_UI_FILE, static function ( $network_wide = false ): void {
	DXAI_UI\Plugin::deactivate( (bool) $network_wide );
} );

add_action(
	'plugins_loaded',
	static function (): void {
		DXAI_UI\Plugin::instance()->boot();
	}
);

<?php
/**
 * Plugin Name:       DX UI
 * Plugin URI:        https://devrix.com/dx-ui
 * Description:       Convert Figma, Lovable, and generated frontend ZIPs into native Gutenberg structures using Claude, ChatGPT, Grok, or DeepSeek.
 * Version:           0.5.0-beta.1
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Author:            DevriX
 * Author URI:        https://devrix.com
 * Update URI:        https://github.com/DevriX/dxai-ui
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dx-ui
 * Domain Path:       /languages
 *
 * @package DX_UI
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DX_UI_VERSION', '0.5.0-beta.1' );
define( 'DX_UI_FILE', __FILE__ );
define( 'DX_UI_DIR', plugin_dir_path( __FILE__ ) );
define( 'DX_UI_URL', plugin_dir_url( __FILE__ ) );
define( 'DX_UI_REST_NAMESPACE', 'dx-ui/v1' );

require_once DX_UI_DIR . 'src/Autoloader.php';

DX_UI\Autoloader::register();

// A site built before the rename (the plugin was dxai-ui): its old names are moved over on activation,
// and wp-config.php constants under the old names keep working (Support\Legacy_Migration).
DX_UI\Support\Legacy_Migration::register();

// `activate_{plugin}` passes whether this is a network activation; activate()
// sets up every site of the network when it is.
register_activation_hook( DX_UI_FILE, static function ( $network_wide = false ): void {
	DX_UI\Support\Legacy_Migration::activate();
	DX_UI\Plugin::activate( (bool) $network_wide );
} );

register_deactivation_hook( DX_UI_FILE, static function ( $network_wide = false ): void {
	DX_UI\Plugin::deactivate( (bool) $network_wide );
} );

add_action(
	'plugins_loaded',
	static function (): void {
		DX_UI\Plugin::instance()->boot();
	}
);

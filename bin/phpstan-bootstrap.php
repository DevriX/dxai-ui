<?php
/**
 * What PHPStan needs to know before it reads the plugin: the constants dxai-ui.php defines when WordPress loads it.
 * WordPress itself comes from the stubs (szepeviktor/phpstan-wordpress). Read by phpstan.neon.dist only.
 *
 * @package DXAI_UI
 */

define( 'DXAI_UI_VERSION', '0.0.0' );
define( 'DXAI_UI_FILE', dirname( __DIR__ ) . '/dxai-ui.php' );
define( 'DXAI_UI_DIR', dirname( __DIR__ ) . '/' );
define( 'DXAI_UI_URL', 'https://example.test/wp-content/plugins/dxai-ui/' );
define( 'DXAI_UI_REST_NAMESPACE', 'dxai-ui/v1' );

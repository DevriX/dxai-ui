<?php
/**
 * What PHPStan needs to know before it reads the plugin: the constants dx-ui.php defines when WordPress loads it.
 * WordPress itself comes from the stubs (szepeviktor/phpstan-wordpress). Read by phpstan.neon.dist only.
 *
 * @package DX_UI
 */

define( 'DX_UI_VERSION', '0.0.0' );
define( 'DX_UI_FILE', dirname( __DIR__ ) . '/dx-ui.php' );
define( 'DX_UI_DIR', dirname( __DIR__ ) . '/' );
define( 'DX_UI_URL', 'https://example.test/wp-content/plugins/dx-ui/' );
define( 'DX_UI_REST_NAMESPACE', 'dx-ui/v1' );

<?php
/**
 * Smoke: Interactivity utilities + @theme tokens compile.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

define( 'DXAI_UI_DIR', dirname( __DIR__ ) . '/' );
require DXAI_UI_DIR . 'src/Autoloader.php';
DXAI_UI\Autoloader::register();

$theme  = DXAI_UI\Compiler\Tailwind\Theme::from_css( '@theme { --color-background: #fff; --color-foreground: #111; }' );
$engine = new DXAI_UI\Compiler\Tailwind\Engine( $theme );
$css    = $engine->compile(
	'<div class="cursor-pointer pointer-events-none scroll-smooth min-h-screen bg-background text-foreground snap-x snap-mandatory">',
	'.dxai-ui'
);

echo $css . "\n---UNRESOLVED---\n" . implode( ',', $engine->unresolved() ) . "\n";

if ( ! str_contains( $css, 'cursor:pointer' ) ) {
	fwrite( STDERR, "missing cursor-pointer\n" );
	exit( 1 );
}
if ( ! str_contains( $css, 'pointer-events:none' ) ) {
	fwrite( STDERR, "missing pointer-events-none\n" );
	exit( 1 );
}
if ( ! str_contains( $css, 'var(--color-background)' ) ) {
	fwrite( STDERR, "missing @theme background token\n" );
	exit( 1 );
}
if ( ! class_exists( DXAI_UI\Compiler\Tailwind\Utilities\Interactivity::class ) ) {
	fwrite( STDERR, "Interactivity class missing\n" );
	exit( 1 );
}

echo "OK\n";

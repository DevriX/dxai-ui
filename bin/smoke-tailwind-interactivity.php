<?php
/**
 * Smoke: Interactivity utilities + @theme tokens compile.
 *
 * @package DX_UI
 */

declare(strict_types=1);

define( 'DX_UI_DIR', dirname( __DIR__ ) . '/' );
require DX_UI_DIR . 'src/Autoloader.php';
DX_UI\Autoloader::register();

$theme  = DX_UI\Compiler\Tailwind\Theme::from_css( '@theme { --color-background: #fff; --color-foreground: #111; }' );
$engine = new DX_UI\Compiler\Tailwind\Engine( $theme );
$css    = $engine->compile(
	'<div class="cursor-pointer pointer-events-none scroll-smooth min-h-screen bg-background text-foreground snap-x snap-mandatory">',
	'.dx-ui'
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
if ( ! class_exists( DX_UI\Compiler\Tailwind\Utilities\Interactivity::class ) ) {
	fwrite( STDERR, "Interactivity class missing\n" );
	exit( 1 );
}

echo "OK\n";

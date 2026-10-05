<?php
/**
 * A Lovable project compiled the way the importer does it, section by section, with no WordPress.
 *
 *   php bin/compile-tsx.php <project dir> <route file, relative to it>
 *
 * Prints one JSON object: the sections ({ name, html }), how many expressions the compiler could not evaluate, and the page script's
 * runtime (Motion_Runtime::javascript()) — what a test needs to load the compiled markup in a browser and press its controls.
 * The few WordPress functions the compiler calls are stood in for.
 *
 * @package DXAI_UI
 */

define( 'DXAI_UI_DIR', dirname( __DIR__ ) . '/' );
require DXAI_UI_DIR . 'src/Autoloader.php';
DXAI_UI\Autoloader::register();

$stubs = array(
	'esc_attr'            => static fn( $t ) => htmlspecialchars( (string) $t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ),
	'esc_html'            => static fn( $t ) => htmlspecialchars( (string) $t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ),
	'esc_url'             => static fn( $t ) => (string) $t,
	'sanitize_html_class' => static fn( $c ) => preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $c ) ?? '',
	'wp_strip_all_tags'   => static fn( $t ) => trim( strip_tags( (string) $t ) ),
	'wp_json_encode'      => static fn( $d, $f = 0 ) => json_encode( $d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | $f ),
	'sanitize_key'        => static fn( $k ) => strtolower( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $k ) ?? '' ),
	'apply_filters'       => static fn( $n, $v ) => $v,
	'__'                  => static fn( $t ) => $t,
);
foreach ( $stubs as $name => $fn ) {
	if ( ! function_exists( $name ) ) {
		$GLOBALS['dxai_stub'][ $name ] = $fn;
		eval( 'function ' . $name . '(...$args) { return ($GLOBALS["dxai_stub"]["' . $name . '"])(...$args); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
	}
}

$dir   = rtrim( (string) ( $argv[1] ?? '' ), '/\\' );
$route = (string) ( $argv[2] ?? '' );
if ( $dir === '' || $route === '' || ! is_dir( $dir ) ) {
	fwrite( STDERR, "usage: php bin/compile-tsx.php <project dir> <route file>\n" );
	exit( 2 );
}
$files = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	$rel = str_replace( '\\', '/', substr( (string) $file->getPathname(), strlen( $dir ) + 1 ) );
	if ( preg_match( '#\.(tsx?|jsx?)$#', $rel ) === 1 && ! str_contains( $rel, 'node_modules/' ) ) {
		$files[ $rel ] = (string) file_get_contents( (string) $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
}
if ( ! isset( $files[ $route ] ) ) {
	fwrite( STDERR, "route not found: $route\n" );
	exit( 1 );
}
$css      = is_readable( $dir . '/src/styles.css' ) ? (string) file_get_contents( $dir . '/src/styles.css' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$compiler = new DXAI_UI\Compiler\Jsx_Compiler();
$out      = array();
foreach ( $compiler->compile_file(
	$files[ $route ],
	array(
		'images' => array(),
		'css'    => $css,
	),
	$files
) as $section ) {
	$out[] = array(
		'name' => (string) $section['name'],
		'html' => (string) $section['html'],
	);
}
echo json_encode(
	array(
		'sections'    => $out,
		'unevaluated' => count( $compiler->unevaluated() ),
		'runtime'     => DXAI_UI\Compiler\Motion_Runtime::javascript(),
	),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

<?php
/**
 * Smoke-test DeepSeek live page restyle.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

$wp_root = $argv[1] ?? 'C:\\Users\\DevriX\\Local Sites\\DXAI-UI\\app\\public';
require rtrim( $wp_root, '/\\' ) . '/wp-load.php';

$r = new DXAI_UI\Structures\Live_Page_Restyler();
$e = $r->engine();
if ( is_wp_error( $e ) ) {
	echo 'engine_err=' . $e->get_error_message() . PHP_EOL;
	exit( 1 );
}
echo 'engine=' . $e->get_id() . PHP_EOL;

$out = $r->restyle(
	array(
		'title'  => 'Contact',
		'path'   => '/contact',
		'html'   => '<h1>Contact Us</h1><p>Call us day or night at (855) 426-2929. We answer within 24 hours.</p><p>Family owned restoration in Olympia, WA.</p>',
		'images' => array(),
	),
	array(
		'home_html'    => '<section class="bg-white text-slate-900 py-16"><div class="max-w-6xl mx-auto px-4"><h2 class="text-4xl font-bold tracking-tight">Water damage experts</h2><a class="inline-flex rounded-full bg-sky-600 text-white px-6 py-3" href="#">Call now</a></div></section>',
		'design_css'   => ':root{--brand:#0284c7}',
		'home_title'   => 'H2O',
		'class_sample' => 'bg-white text-slate-900 py-16 max-w-6xl mx-auto text-4xl font-bold rounded-full bg-sky-600',
	)
);

if ( is_wp_error( $out ) ) {
	echo 'restyle_err=' . $out->get_error_message() . PHP_EOL;
	exit( 2 );
}

echo 'restyled=' . ( ! empty( $out['restyled'] ) ? '1' : '0' ) . PHP_EOL;
echo 'markup_len=' . strlen( (string) ( $out['markup'] ?? '' ) ) . PHP_EOL;
echo substr( (string) ( $out['markup'] ?? '' ), 0, 500 ) . PHP_EOL;

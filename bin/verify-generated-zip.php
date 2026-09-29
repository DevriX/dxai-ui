<?php
/**
 * Unpack a generated HTML ZIP without an LLM.
 * Run: wp eval-file bin/verify-generated-zip.php
 *
 * @package DXAI_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dir = wp_upload_dir();
$path = trailingslashit( $dir['basedir'] ) . 'dxai-ui-test.zip';
$zip  = new ZipArchive();
if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	echo "zip_create=fail\n";
	return;
}
$zip->addFromString(
	'index.html',
	'<!DOCTYPE html><html><body><header><nav>Home</nav></header><section class="hero"><h1>Hello</h1></section><footer>Foot</footer></body></html>'
);
$zip->close();

$file = array(
	'tmp_name' => $path,
	'name'     => 'design.zip',
);
$doc = ( new DXAI_UI\Connectors\Generated_Design_Connector() )->import_zip( $file );
if ( is_wp_error( $doc ) ) {
	echo 'zip_error=' . $doc->get_error_message() . PHP_EOL;
	return;
}
echo 'zip_kind=' . $doc->kind . PHP_EOL;
echo 'zip_stack=' . ( $doc->payload['stack'] ?? '' ) . PHP_EOL;
echo 'zip_sources=' . count( $doc->payload['sources'] ?? array() ) . PHP_EOL;

<?php
/**
 * Harvest images/videos/fonts/links from a generated ZIP without an LLM.
 * Run: wp eval-file bin/verify-assets.php
 *
 * @package DXAI_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require DXAI_UI_DIR . 'bin/verify-cleanup.php';
$dxai_snapshot = dxai_verify_snapshot();

$upload = wp_upload_dir();
$dir    = trailingslashit( $upload['basedir'] ) . 'dxai-ui';
wp_mkdir_p( $dir );
$zip_path = $dir . '/verify-assets.zip';
if ( file_exists( $zip_path ) ) {
	unlink( $zip_path );
}

$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12"><rect width="12" height="12" fill="#0ea5e9"/></svg>';
$html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap">
<link rel="stylesheet" href="style.css">
</head>
<body>
<header><a href="https://example.com/about">About</a></header>
<img src="logo.png" alt="Logo">
<img src="icon.svg" alt="Icon">
<video src="hero.mp4" poster="logo.png"></video>
</body>
</html>
HTML;
$css = <<<'CSS'
:root { --brand: #0ea5e9; --radius: 12px; }
@font-face { font-family: 'BrandFace'; src: url('brand.woff2') format('woff2'); }
.hero { background-image: url(logo.png); font-family: Inter, sans-serif; }
CSS;

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
	echo "zip_create=fail\n";
	return;
}
$zip->addFromString( 'index.html', $html );
$zip->addFromString( 'style.css', $css );
$zip->addFromString( 'logo.png', $png );
$zip->addFromString( 'icon.svg', $svg );
$zip->addFromString( 'brand.woff2', 'wOFF2' );
$zip->addFromString( 'hero.mp4', 'ftypmp42' );
$zip->close();

$doc = ( new DXAI_UI\Connectors\Generated_Design_Connector() )->import_zip(
	array(
		'tmp_name' => $zip_path,
		'name'     => 'verify-assets.zip',
	)
);

if ( is_wp_error( $doc ) ) {
	echo 'zip_error=' . $doc->get_error_message() . PHP_EOL;
	return;
}

$harvest = $doc->payload['harvest'] ?? array();
$summary = $harvest['summary'] ?? array();
echo 'zip_kind=' . $doc->kind . PHP_EOL;
echo 'images=' . (int) ( $summary['images'] ?? 0 ) . PHP_EOL;
echo 'videos=' . (int) ( $summary['videos'] ?? 0 ) . PHP_EOL;
echo 'fonts=' . (int) ( $summary['fonts'] ?? 0 ) . PHP_EOL;
echo 'links=' . (int) ( $summary['links'] ?? 0 ) . PHP_EOL;
$css_out = (string) ( $harvest['css'] ?? '' );
echo 'has_font_face=' . ( str_contains( $css_out, '@font-face' ) || str_contains( $css_out, '@import' ) ? 'yes' : 'no' ) . PHP_EOL;
$rewritten = (string) ( $doc->payload['styles']['style.css'] ?? '' );
echo 'css_rewritten=' . ( str_contains( $rewritten, '/wp-content/uploads/' ) || str_contains( $rewritten, 'dxai-ui/assets' ) ? 'yes' : 'no' ) . PHP_EOL;

$figma = ( new DXAI_UI\Media\Asset_Harvester() )->from_figma(
	array(
		'name'       => 'Hero',
		'style'      => array(
			'fontFamily' => 'Inter',
			'fontWeight' => 700,
		),
		'hyperlink'  => array(
			'type' => 'URL',
			'url'  => 'https://example.com/pricing',
		),
		'cornerRadius' => 16,
		'fills'      => array(
			array(
				'type'  => 'SOLID',
				'color' => array(
					'r' => 0.05,
					'g' => 0.65,
					'b' => 0.91,
					'a' => 1,
				),
			),
		),
		'children'   => array(),
	)
);
echo 'figma_fonts=' . count( $figma['fonts'] ?? array() ) . PHP_EOL;
echo 'figma_links=' . count( $figma['links'] ?? array() ) . PHP_EOL;
echo 'figma_colors=' . count( $figma['tokens']['colors'] ?? array() ) . PHP_EOL;

$paste = ( new DXAI_UI\Media\Asset_Harvester() )->from_text(
	'<a href="https://example.com/jobs">Jobs</a><img src="https://example.com/x.png" />',
	"@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400&display=swap');\n.hero{color:#111}"
);
echo 'paste_fonts=' . (int) ( $paste['summary']['fonts'] ?? 0 ) . PHP_EOL;
echo 'paste_links=' . (int) ( $paste['summary']['links'] ?? 0 ) . PHP_EOL;
dxai_verify_cleanup( $dxai_snapshot );
echo 'ok=yes' . PHP_EOL;

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
	echo "  FAIL  a test archive can be written in the uploads folder\n";
	exit( 1 );
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
	echo '  FAIL  the test archive is read: ' . $doc->get_error_message() . PHP_EOL;
	dxai_verify_cleanup( $dxai_snapshot );
	exit( 1 );
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

// What the archive and the two other sources hold is known: each must come across.
$fail   = 0;
$pass   = 0;
$expect = static function ( string $what, bool $ok ) use ( &$fail, &$pass ): void {
	$ok ? ++$pass : ++$fail;
	echo ( $ok ? '  ok    ' : '  FAIL  ' ) . $what . "\n";
};
$expect( 'the archive is read as a generated design', 'generated-zip' === $doc->kind );
$expect( 'its two images, its video, its fonts (the hosted family and the @font-face) and its link are harvested', (int) ( $summary['images'] ?? 0 ) >= 2 && (int) ( $summary['videos'] ?? 0 ) >= 1 && (int) ( $summary['fonts'] ?? 0 ) >= 2 && (int) ( $summary['links'] ?? 0 ) >= 1 );
$expect( 'the font declarations reach the harvested CSS', str_contains( $css_out, '@font-face' ) || str_contains( $css_out, '@import' ) );
$expect( 'the stylesheet\'s url() points at the uploaded copies', str_contains( $rewritten, '/wp-content/uploads/' ) || str_contains( $rewritten, 'dxai-ui/assets' ) );
$expect( 'a Figma node gives its font, its link and its fill colour', count( $figma['fonts'] ?? array() ) >= 1 && count( $figma['links'] ?? array() ) >= 1 && count( $figma['tokens']['colors'] ?? array() ) >= 1 );
$expect( 'pasted code gives its imported font and its link', (int) ( $paste['summary']['fonts'] ?? 0 ) >= 1 && (int) ( $paste['summary']['links'] ?? 0 ) >= 1 );
/*
 * A large archive (Charter Construction: 520 files, three spellings each): one alternation of every spelling was more than
 * PCRE compiles, preg_replace_callback() failed with a warning and every picture kept its archive path. The rewrite must
 * take a map that size, on a page naming a few of the files and on a stylesheet naming hundreds, replace each spelling
 * once and warn about nothing.
 */
$map = array();
for ( $i = 0; $i < 1600; $i++ ) {
	$url = 'https://example.test/wp-content/uploads/2026/10/photo-' . $i . '.webp';
	$map[ '/wp-content/uploads/2025/06/photo-' . $i . '.jpg' ] = $url;
	$map[ 'wp-content/uploads/2025/06/photo-' . $i . '.jpg' ]  = $url;
	$map[ 'photo-' . $i . '.jpg' ]                          = $url;
}
$rewrite  = new ReflectionMethod( DXAI_UI\Media\Asset_Harvester::class, 'apply_rewrites' );
$warnings = 0;
set_error_handler(
	static function () use ( &$warnings ): bool {
		++$warnings;

		return true;
	}
);
$page = $rewrite->invoke( new DXAI_UI\Media\Asset_Harvester(), '<img src="/wp-content/uploads/2025/06/photo-7.jpg"><img src="photo-12.jpg">', $map );
$css  = '';
for ( $i = 0; $i < 600; $i++ ) {
	$css .= '.b' . $i . '{background:url(/wp-content/uploads/2025/06/photo-' . $i . '.jpg)}';
}
$sheet = $rewrite->invoke( new DXAI_UI\Media\Asset_Harvester(), $css, $map );
restore_error_handler();
$expect( 'a page naming 2 of 4800 spellings gets both uploaded URLs, each once', str_contains( (string) $page, 'src="https://example.test/wp-content/uploads/2026/10/photo-7.webp"' ) && str_contains( (string) $page, 'src="https://example.test/wp-content/uploads/2026/10/photo-12.webp"' ) && ! str_contains( (string) $page, '2026/10/https' ) );
$expect( 'a stylesheet naming 600 files gets all 600 uploaded URLs, none left behind', substr_count( (string) $sheet, 'https://example.test/wp-content/uploads/2026/10/photo-' ) === 600 && ! str_contains( (string) $sheet, '2025/06' ) );
$expect( 'and PHP warns about neither', 0 === $warnings );
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

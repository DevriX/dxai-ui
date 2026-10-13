<?php
/**
 * A generated design archive (plain HTML/CSS/JS, Vue, Next.js) read without an LLM: Generated_Design_Connector.
 *
 *   bash bin/wp-php.sh bin/verify-generated-zip.php        (or: wp eval-file bin/verify-generated-zip.php)
 *
 * Builds its archives in a temporary folder and removes them; writes nothing to the site. Exit code 1 when a check fails.
 *
 * Most of it is about a design's Tailwind theme. A page on the Tailwind Play CDN declares its theme in a script
 * (`tailwind.config = { theme: { extend: { colors: … } } }`), and the connector used to drop that script: the
 * design's own colours compiled to no rule at all, and Northlight Roofing's hero and call-to-action bands came out
 * with no background, white text on white.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Compiler\Source_Compiler;
use DXAI_UI\Compiler\Tailwind\Theme;
use DXAI_UI\Compiler\Tailwind_Purger;
use DXAI_UI\Connectors\Zip_Router;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail   = 0;
$pass   = 0;
$expect = static function ( string $what, bool $ok, string $detail = '' ) use ( &$fail, &$pass ): void {
	if ( $ok ) {
		++$pass;
		echo "  ok    $what\n";
	} else {
		++$fail;
		echo "  FAIL  $what" . ( $detail !== '' ? "  — $detail" : '' ) . "\n";
	}
};

$dir = trailingslashit( get_temp_dir() ) . 'dxai-ui-verify-generated-' . wp_generate_password( 8, false, false );
wp_mkdir_p( $dir );
$made = array();
register_shutdown_function(
	static function () use ( $dir, &$made ): void {
		foreach ( $made as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		if ( is_dir( $dir ) ) {
			rmdir( $dir );
		}
	}
);

/**
 * One archive of the given files, read the way the admin and the command line read every archive.
 *
 * @param array<string, string> $files Archive path → contents.
 */
$read = static function ( string $name, array $files ) use ( $dir, &$made ) {
	$path = $dir . '/' . $name . '.zip';
	$zip  = new ZipArchive();
	if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		return new WP_Error( 'zip', 'could not write ' . $path );
	}
	foreach ( $files as $file => $contents ) {
		$zip->addFromString( $file, $contents );
	}
	$zip->close();
	$made[] = $path;

	return Zip_Router::import( array( 'tmp_name' => $path, 'name' => $name . '.zip' ) );
};
$config = static fn( $doc ): string => is_wp_error( $doc ) ? '' : (string) ( $doc->payload['tailwind_config'] ?? '' );
$rule   = static function ( string $sheet, string $class ): string {
	return preg_match( '/\.' . preg_quote( $class, '/' ) . '[,\s][^{]*\{([^}]*)\}/', $sheet, $m ) === 1 ? trim( $m[1] ) : '';
};

echo "A plain HTML archive\n";
$doc = $read(
	'plain',
	array( 'index.html' => '<!DOCTYPE html><html><body><header><nav>Home</nav></header><section class="hero"><h1>Hello</h1></section><footer>Foot</footer></body></html>' )
);
$expect( 'it is read as a generated design', ! is_wp_error( $doc ) && $doc->kind === 'generated-zip', is_wp_error( $doc ) ? $doc->get_error_message() : $doc->kind );
$expect( 'of plain HTML, CSS and JS', ! is_wp_error( $doc ) && ( $doc->payload['stack'] ?? '' ) === 'html-css-js' );
$expect( 'with its one page as the one source', ! is_wp_error( $doc ) && count( $doc->payload['sources'] ?? array() ) === 1 );
$expect( 'which keeps the browser\'s own margins (no Tailwind preflight)', ! is_wp_error( $doc ) && ! empty( $doc->payload['static_html'] ) );
$expect( 'and hands over no Tailwind theme, having none', ! is_wp_error( $doc ) && $config( $doc ) === '' );

echo "\nA page on the Tailwind Play CDN, its theme in an inline script (Northlight Roofing's shape)\n";
// Northlight Roofing's own config, and two strings with braces in them: one balanced, one that would end the object.
$object   = "{ theme: { extend: { colors: { brand: { 50: '#eef6ff', 600: '#1f5fbf', 800: '#143c78', 900: '#0d2a55' }, flame: '#f28c28' }, fontFamily: { display: ['Poppins', 'sans-serif'] } } }, content: ['./*.{html,js}'], note: 'a } in a string' }";
$cdn_page = '<!DOCTYPE html><html><head>
<script src="https://cdn.tailwindcss.com"></script>
<script>
  tailwind.config = ' . $object . ';
  window.dxaiReady = { done: true };
</script>
</head><body class="bg-white text-slate-800">
<section class="bg-brand-900 text-white"><h1 class="font-display text-4xl">A roof that outlasts the next storm.</h1><a href="#quote" class="rounded-lg bg-flame px-6 py-3">Book a free inspection</a></section>
<section class="bg-brand-50"><span class="bg-brand-600 text-white">1</span><span>We inspect by drone.</span></section>
</body></html>';
$doc = $read( 'play-cdn', array( 'index.html' => $cdn_page ) );
$cfg = $config( $doc );
$expect( 'it is read as a generated design', ! is_wp_error( $doc ) && $doc->kind === 'generated-zip', is_wp_error( $doc ) ? $doc->get_error_message() : $doc->kind );
$expect( 'which ran under Tailwind, so it keeps the preflight reset', ! is_wp_error( $doc ) && empty( $doc->payload['static_html'] ) );
$expect( 'the theme the script assigns is handed over with the design', str_contains( $cfg, 'brand:' ) && str_contains( $cfg, 'fontFamily' ), $cfg === '' ? 'tailwind_config is empty' : substr( $cfg, 0, 120 ) );
$expect( 'exactly the object: a } in a string does not end it early, and the script after it is not read in', $cfg === $object, $cfg );
$theme = Theme::from_css( '', $cfg );
$expect( 'the engine resolves the design\'s colours from it', $theme->color( 'brand-900' ) === '#0d2a55' && $theme->color( 'brand-50' ) === '#eef6ff' && $theme->color( 'flame' ) === '#f28c28', wp_json_encode( array( $theme->color( 'brand-900' ), $theme->color( 'flame' ) ) ) );
$expect( 'and its fonts', $theme->value( 'font', 'display' ) === 'Poppins, sans-serif', (string) $theme->value( 'font', 'display' ) );

$compiler = new Source_Compiler();
$result   = ( ! is_wp_error( $doc ) && $compiler->can_compile( $doc ) ) ? $compiler->compile( $doc ) : array();
$expect( 'the compiler carries the theme to the save, beside the raw stylesheet', $cfg !== '' && (string) ( $result['tailwind_config'] ?? '' ) === $cfg );
$markup = (string) ( $result['source_html'] ?? '' ) . "\n" . (string) ( $result['gutenberg_markup'] ?? '' );
$purger = new Tailwind_Purger();
$sheet  = $purger->compile( $markup, (string) ( $result['custom_css'] ?? '' ), 1, '', (string) ( $result['design_css_raw'] ?? '' ), '', (string) ( $result['tailwind_config'] ?? '' ) );
$expect( 'and the page\'s stylesheet paints the hero and the call to action', str_contains( $rule( $sheet, 'bg-brand-900' ), '#0d2a55' ) && str_contains( $rule( $sheet, 'bg-flame' ), '#f28c28' ), $rule( $sheet, 'bg-brand-900' ) . ' | ' . $rule( $sheet, 'bg-flame' ) );
$expect( 'the light band and the step numbers', str_contains( $rule( $sheet, 'bg-brand-50' ), '#eef6ff' ) && str_contains( $rule( $sheet, 'bg-brand-600' ), '#1f5fbf' ), $rule( $sheet, 'bg-brand-50' ) . ' | ' . $rule( $sheet, 'bg-brand-600' ) );
$expect( 'and the display font', str_contains( $rule( $sheet, 'font-display' ), 'Poppins' ), $rule( $sheet, 'font-display' ) );
$bare = $purger->compile( $markup, (string) ( $result['custom_css'] ?? '' ), 1, '', (string) ( $result['design_css_raw'] ?? '' ), '', '' );
$expect( 'the same page without its theme compiles none of them (what Northlight Roofing got)', $rule( $bare, 'bg-brand-900' ) === '' && $rule( $bare, 'bg-flame' ) === '', $rule( $bare, 'bg-brand-900' ) );

echo "\nComments in the config\n";
$doc = $read(
	'play-cdn-comments',
	array(
		'index.html' => "<!DOCTYPE html><html><head><script src=\"https://cdn.tailwindcss.com\"></script><script>\n  tailwind.config = {\n    // The brand scale }\n    theme: { extend: { colors: { /* the sea } */ sea: '#0f5b6e', site: 'https://example.com/a' } } },\n  };\n</script></head><body><section class=\"bg-sea\"><h1>Harbor</h1></section></body></html>",
	)
);
$cfg = $config( $doc );
$expect( 'are left out, so Tailwind\\Theme never reads a } in a note', ! str_contains( $cfg, 'brand scale' ) && ! str_contains( $cfg, 'the sea' ) && Theme::from_css( '', $cfg )->color( 'sea' ) === '#0f5b6e', $cfg );
$expect( 'while a // inside a string is kept as text', str_contains( $cfg, "site: 'https://example.com/a'" ), $cfg );

echo "\nThe theme in a script file of its own\n";
$doc = $read(
	'play-cdn-file',
	array(
		'index.html' => '<!DOCTYPE html><html><head><script src="https://cdn.tailwindcss.com"></script><script src="js/tw.js"></script></head><body><section class="bg-sea text-white"><h1>Harbor</h1></section></body></html>',
		'js/tw.js'   => "tailwind.config = { theme: { extend: { colors: { sea: '#0f5b6e' } } } };\n",
	)
);
$expect( 'is read from that file', Theme::from_css( '', $config( $doc ) )->color( 'sea' ) === '#0f5b6e', $config( $doc ) );

echo "\nA Tailwind CLI project's config file\n";
$doc = $read(
	'cli-config',
	array(
		'index.html'         => '<!DOCTYPE html><html><head><link rel="stylesheet" href="css/out.css"></head><body><section class="bg-ink text-white"><h1>Ledger</h1></section></body></html>',
		'css/out.css'        => 'body{margin:0}',
		'tailwind.config.js' => "/** @type {import('tailwindcss').Config} */\nmodule.exports = { content: ['./*.html'], theme: { extend: { colors: { ink: '#14262e' } } } };\n",
	)
);
$expect( 'is handed over as it is', str_contains( $config( $doc ), 'module.exports' ) && Theme::from_css( '', $config( $doc ) )->color( 'ink' ) === '#14262e', $config( $doc ) );

echo "\nWhat is not a theme\n";
$doc = $read(
	'not-a-theme',
	array(
		'index.html' => '<!DOCTYPE html><html><head><script src="https://cdn.tailwindcss.com"></script><script>console.log( tailwind.config.theme );</script></head><body><p>Put tailwind.config = { theme: {} } in a script.</p></body></html>',
	)
);
$expect( 'a script reading the config, or page text naming it, hands over nothing', ! is_wp_error( $doc ) && $config( $doc ) === '', $config( $doc ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

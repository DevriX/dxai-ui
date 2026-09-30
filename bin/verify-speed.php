<?php
/**
 * The page-speed pieces against fixtures, offline: the stylesheet trim (Css_Trim), the theme sheet cut down in a
 * finished page (Theme_Trim::filter) and the fonts copied into a design's sheet (Font_Host), with Google answered by
 * a stand-in.
 *
 *   bash bin/wp-php.sh bin/verify-speed.php        (or: wp eval-file bin/verify-speed.php)
 *
 * Writes only under uploads/dxai-ui/ in files named for the test, and removes them again. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Media\Font_Host;
use DXAI_UI\Support\Css_Trim;
use DXAI_UI\Support\Upload_Paths;
use DXAI_UI\Theme\Theme_Trim;

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

// --- Css_Trim ------------------------------------------------------------------------------------------------------
echo "Css_Trim\n";
$css = '@charset "utf-8";'
	. '.a{color:red}.b{color:blue}.a:hover{color:green}.a .b{margin:0}.c .d{margin:1px}'
	. '.md\:flex{display:flex}.\31 0{top:1px}.is-open{display:block}.is-active{color:#000}.zed:not(.a){color:pink}'
	. 'ul li{margin:0}table td{margin:0}.a::after{content:"}"}'
	. 'html{color:#111}body.home .nothing{color:#222}:root{--x:1}'
	. '@media (min-width:600px){.b{color:1}.q{color:2}}@media (min-width:900px){.q{color:3}}'
	. '@font-face{font-family:F;src:url(f.woff2)}@keyframes k{from{top:0}to{top:1px}}'
	. '/* a } comment */.e{content:"{"}[data-x] .a{color:1}.a[class*="z"]{color:2}';
$tok = Css_Trim::tokens( '<html><body class="home"><div class="a md:flex 10"><ul><li class="x">y</li></ul><b class="b"></b></div></body></html>', array( 'el.classList.add("is-open"); var p = "on-" + x;' ) );
$out = (string) Css_Trim::trim( $css, $tok, $stats );
$has = static fn( string $needle ): bool => str_contains( $out, $needle );
$expect( 'a class on the page stays', $has( '.a{color:red}' ) && $has( '.b{color:blue}' ) );
$expect( 'its hover stays', $has( '.a:hover{color:green}' ) );
$expect( 'a descendant rule needs both classes', $has( '.a .b{margin:0}' ) && ! $has( '.c .d' ) );
$expect( 'an escaped class matches (`md:flex`, `10`)', $has( '.md\:flex{' ) && $has( '.\31 0{' ) );
$expect( 'a class a script adds stays', $has( '.is-open{' ) );
$expect( 'a class only a script prefix could build stays; another does not', $has( '.is-active{' ) === false && true );
$expect( ':not() requires nothing of its argument', ! $has( '.zed' ), 'a class the page lacks still decides' );
$expect( 'element names count: ul li stays, table td goes', $has( 'ul li{' ) && ! $has( 'table td' ) );
$expect( 'html, body and :root selectors always stay', $has( 'html{' ) && $has( 'body.home .nothing{' ) && $has( ':root{' ) );
$expect( 'media blocks are cut inside; an empty one goes', $has( '@media (min-width:600px){.b{color:1}}' ) && ! $has( '.q{' ) && ! $has( 'min-width:900px' ) );
$expect( '@font-face, @keyframes and @charset stay whole', $has( '@font-face{font-family:F;src:url(f.woff2)}' ) && $has( '@keyframes k{from{top:0}to{top:1px}}' ) && str_starts_with( $out, '@charset' ) );
$expect( 'a brace in a comment or a string does not break the walk', $has( '.a::after{content:"}"}' ) && ! $has( '.e{' ) && $has( '[data-x] .a{' ), $out );
$expect( 'attribute selectors need no state of the page', $has( '[data-x] .a{' ) && $has( '.a[class*="z"]{' ) );
$prefixed = (string) Css_Trim::trim( '.on-a{x:1}.on-b{x:2}.off-a{x:3}', Css_Trim::tokens( '<i class="x"></i>', array( 'el.className = "on-" + n;' ) ) );
$expect( 'a prefix a script builds classes from keeps the classes that start with it', str_contains( $prefixed, '.on-a{' ) && str_contains( $prefixed, '.on-b{' ) && ! str_contains( $prefixed, '.off-a' ), $prefixed );
$expect( 'CSS that never closes gives null (the caller keeps the original)', Css_Trim::trim( '.a{color:red', $tok ) === null );
$vocab = Css_Trim::vocabulary( $css );
$expect( 'the vocabulary holds the names the sheet spells, unescaped', isset( $vocab['v']['md:flex'], $vocab['v']['10'], $vocab['v']['is-open'], $vocab['v']['ul'] ) && ! isset( $vocab['v']['a1b2c3d4nonce'] ) );
$noisy = Css_Trim::tokens( '<html><body class="home a1b2c3d4nonce"><div class="a md:flex 10 r4nd0m-77"><ul><li class="x">y</li></ul><b class="b"></b></div></body></html>', array( 'el.classList.add("is-open"); var p = "on-" + x; var n = "9f8e7d6c5b";' ) );
$expect( 'names outside the vocabulary do not change what is kept', (string) Css_Trim::trim( $css, $noisy ) === $out );

// --- Css_Trim::components ------------------------------------------------------------------------------------------
echo "\nTheme components\n";
$theme_css = '.faq-answer{overflow:hidden}.faq-answer:not(.is-open){max-height:0;visibility:hidden}.faq-answer.is-open{max-height:unset !important}'
	. '.faq-question--expanded .faq-arrow,.faq-arrow.is-open{transform:rotate(180deg)}.card .faq-question:hover{color:red}.other{color:blue}'
	. 'body .faq-question{margin:0}.faq-note:not(.faq-answer){color:1}@media (max-width:600px){.faq-arrow{width:1px}.other{color:2}}'
	. '@keyframes fade{from{opacity:0}to{opacity:1}}.faq-question{animation:fade .3s}';
$cut = (string) Css_Trim::components( $theme_css, array( 'faq-answer', 'faq-arrow', 'faq-question' ), ':where(.dxai-ui)' );
$expect( 'the rules that name a component class come back narrowed to the scope', str_contains( $cut, ':where(.dxai-ui) .faq-answer{overflow:hidden}' ) && str_contains( $cut, ':where(.dxai-ui) .faq-answer:not(.is-open){max-height:0;visibility:hidden}' ) && str_contains( $cut, ':where(.dxai-ui) .faq-answer.is-open{max-height:unset !important}' ), $cut );
$expect( 'every selector of a list that names one is narrowed', str_contains( $cut, ':where(.dxai-ui) .faq-question--expanded .faq-arrow,:where(.dxai-ui) .faq-arrow.is-open{transform:rotate(180deg)}' ) && str_contains( $cut, ':where(.dxai-ui) .card .faq-question:hover{color:red}' ), $cut );
$expect( 'a class only inside :not() is not a mention; the document\'s own selectors are left out', ! str_contains( $cut, '.faq-note' ) && ! str_contains( $cut, 'body .faq-question' ) && ! str_contains( $cut, '.other{' ) );
$expect( 'media blocks are cut inside, and the keyframes a kept rule animates with come along', str_contains( $cut, '@media (max-width:600px){:where(.dxai-ui) .faq-arrow{width:1px}}' ) && str_contains( $cut, '@keyframes fade{' ) );
$expect( 'CSS that never closes gives null', Css_Trim::components( '.faq-answer{color:red', array( 'faq-answer' ), ':where(.dxai-ui)' ) === null );
if ( class_exists( 'DXAI_UI\Theme\Theme_Components' ) && isset( DXAI_UI\Compiler\Utility_Classes::design_classes( implode( '', array_map( static fn( $f ) => (string) file_get_contents( $f ), DXAI_UI\Theme\Theme_Buttons::sheets() ) ) )[0] ) && in_array( 'faq-answer', DXAI_UI\Compiler\Utility_Classes::design_classes( implode( '', array_map( static fn( $f ) => (string) file_get_contents( $f ), DXAI_UI\Theme\Theme_Buttons::sheets() ) ) ), true ) ) {
	$markup = '<!-- wp:group {"className":"faq-question mt-4 wp-block-x"} --><div class="wp-block-group faq-question is-layout-flex has-border-color"><p class="faq-answer faq-answer--default-open text-white">A</p><figure class="faq-arrow"></figure></div><!-- /wp:group -->';
	$wanted = DXAI_UI\Theme\Theme_Components::wanted( $markup, '' );
	$expect( 'the theme\'s FAQ classes in the content are the ones kept (WordPress\'s and utilities are not)', in_array( 'faq-question', $wanted, true ) && in_array( 'faq-answer', $wanted, true ) && in_array( 'faq-arrow', $wanted, true ) && ! in_array( 'wp-block-group', $wanted, true ) && ! in_array( 'is-layout-flex', $wanted, true ) && ! in_array( 'text-white', $wanted, true ) && ! in_array( 'has-border-color', $wanted, true ), implode( ' ', $wanted ) );
	$expect( 'the states a script adds come with them', in_array( 'faq-question--expanded', $wanted, true ), implode( ' ', $wanted ) );
	$own = Upload_Paths::path( Upload_Paths::DIR . '/vtx-design-fixture.css' );
	file_put_contents( $own, '.dxai-ui .faq-question{margin:0}' );
	$owned = DXAI_UI\Theme\Theme_Components::wanted( $markup, $own );
	$expect( 'a class the design\'s own sheet defines is the design\'s', ! in_array( 'faq-question', $owned, true ) && in_array( 'faq-answer', $owned, true ), implode( ' ', $owned ) );
	wp_delete_file( $own );
} else {
	echo "  skip  the active theme has no FAQ component\n";
}

// --- Theme_Trim::filter --------------------------------------------------------------------------------------------
echo "\nTheme_Trim\n";
add_filter( 'dxai_ui_trim_min_bytes', static fn() => 16384 );
$sheet = '';
for ( $i = 0; $i < 1500; $i++ ) {
	$sheet .= ".vtx-c{$i}{color:#" . str_pad( dechex( $i ), 6, '0', STR_PAD_LEFT ) . ";padding:{$i}px}";
}
$sheet .= '.vtx-c7:hover{color:red}@media (min-width:600px){.vtx-c9{margin:0}.vtx-c10{margin:1px}}';
$page = static fn( string $classes, string $script = 'var n = "x";' ): string => '<!doctype html><html><head><title>t</title><style id="theme_style-inline-css">' . $sheet . '</style><style id="small">.tiny{color:red}</style></head><body class="home"><div class="' . $classes . '">x</div><script>' . $script . '</script></body></html>';
$whole  = PHP_OUTPUT_HANDLER_START | PHP_OUTPUT_HANDLER_FINAL;
$dir    = Upload_Paths::path( Upload_Paths::DIR . '/trim/' . substr( md5( $sheet ), 0, 12 ) );
$rm     = static function ( string $d ): void {
	foreach ( (array) glob( $d . '/*' ) as $f ) {
		if ( is_string( $f ) && is_file( $f ) ) {
			wp_delete_file( $f );
		}
	}
	@rmdir( $d ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
};
$rm( $dir );
delete_transient( 'dxai_ui_trim_seen_0' );
$in  = $page( 'vtx-c7 vtx-c9' );
$a   = (string) Theme_Trim::filter( $in, $whole );
$expect( 'the big sheet is cut to the rules the page can use', strlen( $a ) < strlen( $in ) / 4 && str_contains( $a, '.vtx-c7{' ) && str_contains( $a, '.vtx-c7:hover{' ) && ! str_contains( $a, '.vtx-c8{' ) && str_contains( $a, '.vtx-c9{margin:0}' ) && ! str_contains( $a, '.vtx-c10' ), (string) strlen( $a ) );
$expect( 'the small sheets and the rest of the page are as they were', str_contains( $a, '<style id="small">.tiny{color:red}</style>' ) && str_contains( $a, '<div class="vtx-c7 vtx-c9">x</div>' ) && str_ends_with( $a, '</html>' ) );
$files = (array) glob( $dir . '/*.css' );
$expect( 'the result is cached beside the sheet', count( $files ) === 1 && is_file( $dir . '/vocabulary.json' ) );
$b = (string) Theme_Trim::filter( $page( 'vtx-c7 vtx-c9', 'var n = "8d3c1f2a9b";' ), $whole );
$expect( 'the same page with another nonce in its script is the same result, from the cache', $b !== '' && str_contains( $b, '.vtx-c7{' ) && count( (array) glob( $dir . '/*.css' ) ) === 1 );
$c = (string) Theme_Trim::filter( $page( 'vtx-c7 vtx-c9 vtx-c8' ), $whole );
$expect( 'a page that gains a class is trimmed again and gains its rule', str_contains( $c, '.vtx-c8{' ) && count( (array) glob( $dir . '/*.css' ) ) === 2 );
$d = (string) Theme_Trim::filter( $page( 'vtx-c7 x', 'el.className = "vtx-" + n;' ), $whole );
$expect( 'a class a script builds from a prefix keeps its rules', str_contains( $d, '.vtx-c1{' ) && str_contains( $d, '.vtx-c1' . '0{' ) && str_contains( $d, '.vtx-c800{' ) );
$expect( 'a document flushed in pieces is left as it is', Theme_Trim::filter( $in, PHP_OUTPUT_HANDLER_START ) === $in && Theme_Trim::filter( $in, PHP_OUTPUT_HANDLER_FINAL ) === $in );
$broken = str_replace( '.vtx-c7:hover{color:red}', '.vtx-c7:hover{color:red', $in );
$expect( 'a sheet that cannot be read to the end is left as it is', Theme_Trim::filter( $broken, $whole ) === $broken );
$rm( $dir );
$rm( Upload_Paths::path( Upload_Paths::DIR . '/trim/' . substr( md5( str_replace( '.vtx-c7:hover{color:red}', '.vtx-c7:hover{color:red', $sheet ) ), 0, 12 ) ) );
delete_transient( 'dxai_ui_trim_seen_0' );

// --- Font_Host -----------------------------------------------------------------------------------------------------
echo "\nFont_Host\n";
$google  = 'https://fonts.googleapis.com/css2?family=Vtx+Sans:wght@400;700&display=swap';
$google2 = 'https://fonts.googleapis.com/css2?family=Vtx+Serif:wght@400&display=swap';
$gcss    = static fn( string $family, array $weights ): string => implode(
	"\n",
	array_map(
		static fn( $w ) => "/* latin */\n@font-face {\n  font-family: '$family';\n  font-style: normal;\n  font-weight: $w;\n  font-display: swap;\n  src: url(https://fonts.gstatic.com/s/" . strtolower( str_replace( ' ', '', $family ) ) . "/v1/$w.woff2) format('woff2');\n  unicode-range: U+0000-00FF;\n}",
		$weights
	)
);
$broken_file = '';
$hits        = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( $gcss, &$broken_file, &$hits ) {
		if ( str_starts_with( $url, 'https://fonts.googleapis.com/' ) ) {
			$hits[] = $url;
			$css    = str_contains( $url, 'Vtx+Serif' ) ? $gcss( 'Vtx Serif', array( 400 ) ) : $gcss( 'Vtx Sans', array( 400, 700 ) );

			return array( 'headers' => array(), 'body' => $css, 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		}
		if ( str_starts_with( $url, 'https://fonts.gstatic.com/' ) ) {
			if ( $broken_file !== '' && str_contains( $url, $broken_file ) ) {
				return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 503, 'message' => 'no' ), 'cookies' => array(), 'filename' => null );
			}

			return array( 'headers' => array(), 'body' => 'wOF2' . str_repeat( 'x', 200 ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		}

		return $pre;
	},
	10,
	3
);
$path = Upload_Paths::path( Upload_Paths::DIR . '/pattern-vtx-speed.css' );
$orig = "@import url(\"https://fonts.googleapis.com/css2?family=Vtx+Sans:wght@400%3B700&display=swap\");\n.dxai-ui.dxai-ui--1 { max-width:100% }\n.x{font-family:'Vtx Sans'}\n";
wp_mkdir_p( dirname( $path ) );
file_put_contents( $path, $orig );
$mine = array( Upload_Paths::path( Upload_Paths::DIR . '/fonts/' . Font_Host::key( $google ) . '.css' ), Upload_Paths::path( Upload_Paths::DIR . '/fonts/' . Font_Host::key( $google2 ) . '.css' ) );
foreach ( array( 'https://fonts.gstatic.com/s/vtxsans/v1/400.woff2', 'https://fonts.gstatic.com/s/vtxsans/v1/700.woff2', 'https://fonts.gstatic.com/s/vtxserif/v1/400.woff2' ) as $u ) {
	$mine[] = Upload_Paths::path( Upload_Paths::DIR . '/fonts/' . substr( sha1( $u ), 0, 20 ) . '.woff2' );
}
foreach ( $mine as $f ) {
	if ( is_file( $f ) ) {
		wp_delete_file( $f );
	}
}
$expect( 'a sheet that imports Google is remote before', Font_Host::state( $path ) === 'remote' );
$r = Font_Host::localize_sheet( $path, array( $google2 ) );
$after = (string) file_get_contents( $path );
$expect( 'localising changes the sheet and finishes', $r['changed'] && $r['complete'], json_encode( $r ) );
$expect( 'no request to Google is left in it, and the rules are', ! str_contains( $after, 'fonts.googleapis.com/css2?family=Vtx+Sans:wght@400%3B700&display=swap");' ) && ! str_contains( $after, 'https://fonts.gstatic.com' ) && substr_count( $after, '@font-face' ) === 3, $after );
$expect( 'its font files are on this site, named in the sheet', preg_match_all( '#url\(fonts/([0-9a-f]{20})\.woff2\)#', $after, $m ) === 3 && is_file( Upload_Paths::path( Upload_Paths::DIR . '/fonts/' . $m[1][0] . '.woff2' ) ) );
$expect( 'the design\'s own rules follow, untouched', str_contains( $after, ".dxai-ui.dxai-ui--1 { max-width:100% }\n.x{font-family:'Vtx Sans'}\n" ) );
$expect( 'the sheet is local and says which stylesheets it carries', Font_Host::state( $path, array( $google, $google2 ) ) === 'local' && count( Font_Host::covered( $path ) ) === 2 );
$expect( 'a listed stylesheet the sheet does not import comes with it', str_contains( $after, "'Vtx Serif'" ) );
$hits_before = count( $hits );
$again       = Font_Host::localize_sheet( $path, array( $google2 ) );
$expect( 'a second run changes nothing and asks Google for nothing', ! $again['changed'] && count( $hits ) === $hits_before );
$expect( 'the export gets the sheet as it was imported', file_get_contents( Font_Host::portable( $path ) ) === $orig );
$expect( 'reverting gives the imported sheet back, byte for byte', Font_Host::revert( $path ) && file_get_contents( $path ) === $orig && Font_Host::state( $path ) === 'remote' );
// A file that cannot be fetched keeps Google's address: the copy is partial, and finished on a later run.
foreach ( $mine as $f ) {
	if ( is_file( $f ) ) {
		wp_delete_file( $f );
	}
}
$broken_file = '/700.woff2';
$half        = Font_Host::localize_sheet( $path );
$expect( 'a file that cannot be fetched keeps its address and the copy is partial', $half['changed'] && ! $half['complete'] && Font_Host::state( $path ) === 'partial' && str_contains( (string) file_get_contents( $path ), 'https://fonts.gstatic.com/s/vtxsans/v1/700.woff2' ) );
$broken_file = '';
$full        = Font_Host::localize_sheet( $path );
$expect( 'the next run finishes it', $full['changed'] && $full['complete'] && Font_Host::state( $path ) === 'local' && ! str_contains( (string) file_get_contents( $path ), 'https://fonts.gstatic.com' ) );
$expect( 'a sheet with no Google fonts is not touched', Font_Host::state( '' ) === 'none' && ! Font_Host::localize_sheet( DXAI_UI_DIR . 'index.php' )['changed'] );
Font_Host::revert( $path );
wp_delete_file( $path );
foreach ( $mine as $f ) {
	if ( is_file( $f ) ) {
		wp_delete_file( $f );
	}
}
delete_transient( 'dxai_ui_fonts_wait_' . Font_Host::key( $google ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

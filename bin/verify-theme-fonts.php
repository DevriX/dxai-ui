<?php
/**
 * A theme that lets the site choose its fonts draws the designs in them (Theme_Fonts): the roles of a design's fonts,
 * the rule printed with the page, a block's font-family bound to the theme's, the design's own fonts leaving its sheet
 * and coming back (Font_Host::adopt()), and the clean-up of the files nothing names. The theme is a stand-in given
 * through the filter `dxai_ui_theme_fonts`, Google is answered by a stand-in too.
 *
 *   bash bin/wp-php.sh bin/verify-theme-fonts.php        (or: wp eval-file bin/verify-theme-fonts.php --user=1)
 *
 * Writes under uploads/dxai-ui/ in files named for the test and removes them again; the pages it makes are trashed.
 * Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Media\Font_Host;
use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Support\Speed;
use DXAI_UI\Support\Upload_Paths;
use DXAI_UI\Theme\Theme_Fonts;

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

// --- the stand-ins -------------------------------------------------------------------------------------------------
$theme = array(
	'id'           => 'vtx-theme',
	'heading'      => '"Vtx Head", Georgia, serif',
	'body'         => '"Vtx Body", Arial, sans-serif',
	'heading_slug' => 'vtx-head',
	'body_slug'    => 'vtx-body',
	'faces'        => '@font-face{font-family:"Vtx Head";font-weight:700;src:url(/themefonts/vtx-head.woff2) format("woff2")}',
);
$provide = $theme; // array: what the theme gives; false: it manages nothing.
add_filter(
	'dxai_ui_theme_fonts',
	static function () use ( &$provide ) {
		return $provide;
	},
	99
);
$switch = static function ( $to ) use ( &$provide ): void {
	$provide = $to;
	Theme_Fonts::reset();
};

$google  = 'https://fonts.googleapis.com/css2?family=Vtx+Tfsans:wght@400;700&display=swap';
$google2 = 'https://fonts.googleapis.com/css2?family=Vtx+Tfserif:wght@400&display=swap';
$hits    = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$hits ) {
		$ok = static fn( string $body ): array => array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		if ( str_starts_with( $url, 'https://fonts.googleapis.com/' ) ) {
			$hits[] = $url;
			$name   = str_contains( $url, 'Vtx+Tfserif' ) ? 'Vtx Tfserif' : 'Vtx Tfsans';
			$weights = str_contains( $url, 'Vtx+Tfserif' ) ? array( 400 ) : array( 400, 700 );
			$css    = implode( "\n", array_map( static fn( $w ) => "/* latin */\n@font-face {\n  font-family: '$name';\n  font-style: normal;\n  font-weight: $w;\n  src: url(https://fonts.gstatic.com/s/" . strtolower( str_replace( ' ', '', $name ) ) . "/v1/$w.woff2) format('woff2');\n}", $weights ) );

			return $ok( $css );
		}
		if ( str_starts_with( $url, 'https://fonts.gstatic.com/' ) ) {
			$hits[] = $url;

			return $ok( 'wOF2' . str_repeat( 'x', 200 ) );
		}

		return $pre;
	},
	10,
	3
);

$made   = array();
$files  = array();
$design = static function ( string $css, array $meta = array() ) use ( &$made ): array {
	$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Vtx theme fonts', 'post_content' => '' ) );
	update_post_meta( $id, Page_Scope::META, $id );
	update_post_meta( $id, '_dxai_ui_generated_page', '1' );
	$rel = Upload_Paths::DIR . '/pattern-vtxtf' . $id . '.css';
	update_post_meta( $id, '_dxai_ui_css_url', $rel );
	foreach ( $meta as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	$path = Upload_Paths::path( $rel );
	wp_mkdir_p( dirname( $path ) );
	file_put_contents( $path, str_replace( '{ID}', (string) $id, $css ) );
	$made[] = array( $id, $path );

	return array( $id, $path );
};
$scope = '.dxai-ui.dxai-ui--{ID}';
$sheet = "@import url(\"https://fonts.googleapis.com/css2?family=Vtx+Tfsans:wght@400%3B700&display=swap\");\n"
	. "$scope { font-family: 'Vtx Tfsans', sans-serif; --font-display: 'Vtx Tfserif', serif; --font-sans: 'Vtx Tfsans', sans-serif; --font-mono: 'Vtx Tfmono', monospace; --font-size-base: 16px }\n"
	. "$scope .hero { font-family: 'Vtx Tfserif', serif }\n"
	. "$scope .lead { font-family: \"Vtx Tfsans\", Arial, sans-serif !important }\n"
	. "$scope .code { font-family: 'Vtx Tfmono', monospace }\n"
	. "$scope pre, $scope .snippet code { font-family: 'Vtx Tfmono', monospace }\n"
	. "$scope .ico { font-family: 'Material Icons' }\n"
	. "$scope .keep { font-family: var(--font-display) }\n"
	. "@media (min-width: 600px) { $scope .sub { font-family: 'Vtx Tfserif' } }\n";

// --- the words of a font stack -------------------------------------------------------------------------------------
echo "\nThe roles\n";
$expect( 'a stack is read into its families, quotes and case aside', Theme_Fonts::families( "'Vtx Sans', \"Inter\" , Sans-Serif" ) === array( 'vtx sans', 'inter', 'sans-serif' ) );
$expect( 'a comma inside a function does not split a family', Theme_Fonts::families( 'var(--a, "x y"), serif' ) === array( 'var(--a, x y)', 'serif' ) );
$expect( 'the first name that is not a generic family is the font', Theme_Fonts::first_named( 'system-ui, "Vtx Sans", serif' ) === 'vtx sans' && Theme_Fonts::first_named( 'serif, monospace' ) === '' );
$expect( 'an icon font is known, a text font is not one', Theme_Fonts::is_icon( 'material icons' ) && Theme_Fonts::is_icon( 'font awesome 6 free' ) && ! Theme_Fonts::is_icon( 'inter' ) );
$expect( 'the page root\'s font is the body font', Theme_Fonts::map_stack( "'Vtx Tfsans', sans-serif", 'vtx tfsans' ) === 'var(--dxai-theme-body)' );
$expect( 'any other font is a heading font', Theme_Fonts::map_stack( "'Vtx Tfserif', serif", 'vtx tfsans' ) === 'var(--dxai-theme-heading)' );
$expect( 'with no root font known a font is the body font', Theme_Fonts::map_stack( "'Vtx Tfserif', serif", '' ) === 'var(--dxai-theme-body)' );
$expect( 'a monospace web font is the body font: a mono label is text like any other', Theme_Fonts::map_stack( "'Vtx Tfmono', monospace", 'vtx tfsans' ) === 'var(--dxai-theme-body)' && Theme_Fonts::map_stack( "'JetBrains Mono'", 'inter' ) === 'var(--dxai-theme-body)' );
$expect( '… and the system\'s monospace in code', Theme_Fonts::map_stack( "'JetBrains Mono', monospace", 'inter', true ) === Theme_Fonts::SYSTEM_MONO );
$expect( 'an icon font, a variable and a keyword are left alone', Theme_Fonts::map_stack( "'Material Icons'", 'x' ) === null && Theme_Fonts::map_stack( "'Material Icons', sans-serif", 'x' ) === null && Theme_Fonts::map_stack( 'var(--font-display)', 'x' ) === null && Theme_Fonts::map_stack( 'inherit', 'x' ) === null && Theme_Fonts::map_stack( 'initial', 'x' ) === null && Theme_Fonts::map_stack( '', 'x' ) === null );
$expect( 'the browser\'s default font (generic families only) is the body font', Theme_Fonts::map_stack( 'sans-serif', 'inter' ) === 'var(--dxai-theme-body)' && Theme_Fonts::map_stack( 'system-ui, -apple-system, sans-serif', 'inter' ) === 'var(--dxai-theme-body)' && Theme_Fonts::map_stack( 'serif', 'inter' ) === 'var(--dxai-theme-body)' );
$expect( 'so is a font every computer has (Arial), where a display face (Georgia) is a choice', Theme_Fonts::map_stack( 'Arial, Helvetica, sans-serif', 'inter' ) === 'var(--dxai-theme-body)' && Theme_Fonts::map_stack( 'Georgia, serif', 'inter' ) === 'var(--dxai-theme-heading)' );
$expect( '!important does not change the role', Theme_Fonts::map_stack( "'Vtx Tfsans' !important", 'vtx tfsans' ) === 'var(--dxai-theme-body)' );

// --- the theme -----------------------------------------------------------------------------------------------------
echo "\nThe theme\n";
$switch( $theme );
$cur = Theme_Fonts::current();
$expect( 'a theme that gives two fonts manages them', Theme_Fonts::managed() && $cur !== null && $cur['heading_slug'] === 'vtx-head' );
$switch( false );
$expect( 'a theme that gives nothing manages nothing', ! Theme_Fonts::managed() && Theme_Fonts::current() === null );
$switch( array_merge( $theme, array( 'heading' => 'Vtx;}body{display:none' ) ) );
$expect( 'a stack that could end a rule is refused, the theme manages nothing', ! Theme_Fonts::managed() );
$switch( array_merge( $theme, array( 'faces' => '@font-face{font-family:x}</style><script>alert(1)</script>' ) ) );
$expect( 'font rules that could close the style element are dropped, the fonts stay', Theme_Fonts::managed() && Theme_Fonts::current()['faces'] === '' );
$switch( array_merge( $theme, array( 'faces' => '@import url(https://evil.example/x.css);' ) ) );
$expect( 'font rules that import are dropped', Theme_Fonts::current()['faces'] === '' );
$switch( $theme );

// --- a design follows it -------------------------------------------------------------------------------------------
echo "\nA design follows the theme\n";
[ $id, $path ] = $design( $sheet );
$expect( 'the fixture is a design', \DXAI_UI\Structures\Design_Attach::is_design( $id ) );
$expect( 'it follows the theme and its fonts can be replaced', Theme_Fonts::follows( $id ) && Theme_Fonts::adopts( $id ) );
$a = Theme_Fonts::analysis( $id );
$expect( 'the family its page is set in is its body font', $a['body'] === 'vtx tfsans', $a['body'] );
$expect( 'its font variables are told by their names', ( $a['vars']['--font-display'] ?? '' ) === 'heading' && ( $a['vars']['--font-sans'] ?? '' ) === 'body' && ( $a['vars']['--font-mono'] ?? '' ) === 'body' && ! isset( $a['vars']['--font-size-base'] ), json_encode( $a['vars'] ) );
$expect( 'the rules that name a font are found, the variable and icon ones are not', count( $a['rules'] ) === 6, json_encode( array_column( $a['rules'], 's' ) ) );
$css = Theme_Fonts::css( $id );
$expect( 'the theme\'s font rules are printed with the design', str_contains( $css, '@font-face{font-family:"Vtx Head"' ) );
$expect( 'its two stacks are given names the design cannot shadow', str_contains( $css, '--dxai-theme-heading:var(--font-heading,"Vtx Head", Georgia, serif)' ) && str_contains( $css, '--dxai-theme-body:var(--font-primary,"Vtx Body", Arial, sans-serif)' ) );
$expect( 'the design\'s font variables are pointed at them by role', str_contains( $css, '--font-display:var(--dxai-theme-heading)' ) && str_contains( $css, '--font-sans:var(--dxai-theme-body)' ) && str_contains( $css, '--font-mono:var(--dxai-theme-body)' ) );
$expect( 'its root takes the body font and its headings the heading font', str_contains( $css, '.dxai-ui.dxai-ui.dxai-ui{font-family:var(--dxai-theme-body)}' ) && str_contains( $css, ':is(h1,h2,h3,h4,h5,h6,.wp-block-heading){font-family:var(--dxai-theme-heading)}' ) );
$expect( 'a class that names a font is written again with its role', str_contains( $css, ' .hero{font-family:var(--dxai-theme-heading)}' ) && str_contains( $css, ' .lead{font-family:var(--dxai-theme-body) !important}' ) );
$expect( 'a class set in a monospace font gets the body font, not the system\'s monospace', str_contains( $css, ' .code{font-family:var(--dxai-theme-body)}' ) );
$expect( 'a rule whose selectors are all code gets the system\'s monospace', str_contains( $css, ' pre, .dxai-ui.dxai-ui--' . $id . ' .snippet code{font-family:' . Theme_Fonts::SYSTEM_MONO . '}' ) );
$expect( 'code stays monospace whatever the design\'s variable says', str_contains( $css, ':is(pre,code,kbd,samp){font-family:' . Theme_Fonts::SYSTEM_MONO . '}' ) );
$expect( 'an icon class and a variable are not touched', ! str_contains( $css, 'Material' ) && ! str_contains( $css, ' .keep{' ) );
$expect( 'a rule inside a media query stays inside it', str_contains( $css, '@media (min-width: 600px){' . str_replace( '{ID}', (string) $id, $scope ) . ' .sub{font-family:var(--dxai-theme-heading)}}' ) );
$expect( 'nothing in it can close a style element', ! str_contains( $css, '</' ) );

$prior = Theme_Fonts::use_design( $id );
$bound = Theme_Fonts::bind_css( "color:red;font-family:'Vtx Tfserif', serif;margin:0" );
$expect( 'a block\'s font-family is bound to the theme\'s role, the rest is as it was', $bound === 'color:red;font-family:var(--dxai-theme-heading);margin:0', $bound );
$expect( 'the design\'s root font in a block is the body font', Theme_Fonts::bind_css( "font-family:'Vtx Tfsans'" ) === 'font-family:var(--dxai-theme-body)' );
$expect( 'a block that reads a variable, or names no font, is not touched', Theme_Fonts::bind_css( 'font-family:var(--x)' ) === 'font-family:var(--x)' && Theme_Fonts::bind_css( 'color:red' ) === 'color:red' );
$expect( '!important is kept', str_contains( Theme_Fonts::bind_css( "font-family:'Vtx Tfserif' !important" ), 'var(--dxai-theme-heading) !important' ) );
Theme_Fonts::use_design( 0 );
$expect( 'with no design named, nothing is bound', Theme_Fonts::bind_css( "font-family:'Vtx Tfserif'" ) === "font-family:'Vtx Tfserif'" );
Theme_Fonts::use_design( $prior );
$sig = Theme_Fonts::signature( $id );

// --- a heading font with no bold -----------------------------------------------------------------------------------
echo "\nA heading font with no bold\n";
$face = static fn( string $family, string $weight ): string => '@font-face{font-family:"' . $family . '";font-weight:' . $weight . ';src:url(/themefonts/x.woff2) format("woff2")}';
$expect( 'a heading font with a bold of its own (700) is drawn as the design says', ! Theme_Fonts::heading_lacks_bold() && ! str_contains( Theme_Fonts::css( $id ), 'font-synthesis' ) );
$switch( array_merge( $theme, array( 'heading' => '"Vtx Head", Impact, sans-serif', 'faces' => $face( 'Vtx Head', '400' ) . $face( 'Vtx Body', '100 900' ) ) ) );
$expect( 'a display face that comes in one weight (400, like Impact or Archivo Black) has no bold', Theme_Fonts::heading_lacks_bold() );
$css = Theme_Fonts::css( $id );
$expect( '…so its headings are not drawn bold by the browser (smeared strokes, heavier than the theme\'s own)', str_contains( $css, ':is(h1,h2,h3,h4,h5,h6,.wp-block-heading){font-family:var(--dxai-theme-heading);font-synthesis-weight:none}' ), substr( $css, 0, 300 ) );
$expect( '…and so are the classes of the design that name a heading font', str_contains( $css, ' .hero{font-family:var(--dxai-theme-heading);font-synthesis-weight:none}' ) && str_contains( $css, ' .lead{font-family:var(--dxai-theme-body) !important}' ) );
$prior = Theme_Fonts::use_design( $id );
$expect( 'a block set in the heading font says so in its own CSS', Theme_Fonts::bind_css( "font-family:'Vtx Tfserif', serif;font-weight:800" ) === 'font-family:var(--dxai-theme-heading);font-synthesis-weight:none;font-weight:800' );
$expect( '…a block in the body font does not', Theme_Fonts::bind_css( "font-family:'Vtx Tfsans'" ) === 'font-family:var(--dxai-theme-body)' );
Theme_Fonts::use_design( $prior );
$expect( 'what the cached rules depend on changes with it', Theme_Fonts::signature( $id ) !== $sig );
$switch( array_merge( $theme, array( 'heading' => '"Vtx Head", sans-serif', 'faces' => $face( 'Vtx Head', '100 900' ) ) ) );
$expect( 'a variable font (100 to 900) has its bold', ! Theme_Fonts::heading_lacks_bold() );
$switch( array_merge( $theme, array( 'heading' => '"Vtx Head", sans-serif', 'faces' => $face( 'Vtx Head', '400' ) . $face( 'Vtx Head', 'bold' ) ) ) );
$expect( 'so has one with a bold face beside its regular', ! Theme_Fonts::heading_lacks_bold() );
$switch( array_merge( $theme, array( 'heading' => '"Vtx Head", sans-serif', 'faces' => $face( 'Vtx Body', '400' ) ) ) );
$expect( 'a font the theme\'s font rules do not describe is left as it is', ! Theme_Fonts::heading_lacks_bold() );
$switch( array_merge( $theme, array( 'heading' => '"Vtx Head", sans-serif', 'faces' => '' ) ) );
$expect( '…and so is a theme that gives no font rules', ! Theme_Fonts::heading_lacks_bold() );
$switch( $theme );
$expect( 'back to the first theme: nothing is said', ! Theme_Fonts::heading_lacks_bold() && Theme_Fonts::signature( $id ) === $sig );

// --- a design keeps its own ----------------------------------------------------------------------------------------
echo "\nA design keeps its own fonts\n";
update_post_meta( $id, Theme_Fonts::MODE_META, 'keep' );
Theme_Fonts::reset();
$expect( 'told to keep its own, it does not follow', ! Theme_Fonts::follows( $id ) && ! Theme_Fonts::adopts( $id ) );
$expect( 'nothing is printed for it and its blocks are not bound', Theme_Fonts::css( $id ) === '' && Theme_Fonts::signature( $id ) === '' );
Theme_Fonts::use_design( $id );
$expect( '… its blocks keep their font-family', Theme_Fonts::bind_css( "font-family:'Vtx Tfserif'" ) === "font-family:'Vtx Tfserif'" );
Theme_Fonts::use_design( 0 );
delete_post_meta( $id, Theme_Fonts::MODE_META );
Theme_Fonts::reset();
$expect( 'the cached rules of a block depend on whether the design follows', $sig !== '' && $sig === Theme_Fonts::signature( $id ) );
add_filter( 'dxai_ui_theme_fonts_follow', '__return_false' );
Theme_Fonts::reset();
$expect( 'the filter can keep every design\'s own fonts', ! Theme_Fonts::follows( $id ) && Theme_Fonts::css( $id ) === '' );
remove_filter( 'dxai_ui_theme_fonts_follow', '__return_false' );
Theme_Fonts::reset();
$switch( false );
$expect( 'a theme that manages no fonts leaves every design its own', ! Theme_Fonts::follows( $id ) && ! Theme_Fonts::adopts( $id ) && Theme_Fonts::css( $id ) === '' );
$switch( $theme );
$expect( 'a page that is not a design follows nothing', ! Theme_Fonts::adopts( 1 ) && ! Theme_Fonts::adopts( 0 ) && Theme_Fonts::signature( 0 ) === '' );

[ $icon_id, $icon_path ] = $design( $sheet . "@font-face { font-family: 'Material Icons'; src: url(fonts/aaaaaaaaaaaaaaaaaaaa.woff2) format('woff2') }\n" );
$expect( 'a design that loads an icon font keeps its own fonts files (the theme cannot stand in for it)', Theme_Fonts::follows( $icon_id ) && ! Theme_Fonts::adopts( $icon_id ) );
$icon_css = Theme_Fonts::css( $icon_id );
$expect( '… and its icon class is still not touched', ! str_contains( $icon_css, 'Material' ) );

// --- its own fonts leave the sheet ---------------------------------------------------------------------------------
echo "\nThe design's own fonts leave the sheet and come back\n";
$key_files = array();
foreach ( array( $google, $google2 ) as $g ) {
	$key_files[] = Upload_Paths::path( Upload_Paths::DIR . '/fonts/' . Font_Host::key( $g ) . '.css' );
}
foreach ( array( 'https://fonts.gstatic.com/s/vtxtfsans/v1/400.woff2', 'https://fonts.gstatic.com/s/vtxtfsans/v1/700.woff2', 'https://fonts.gstatic.com/s/vtxtfserif/v1/400.woff2' ) as $u ) {
	$key_files[] = Upload_Paths::path( Upload_Paths::DIR . '/fonts/' . substr( sha1( $u ), 0, 20 ) . '.woff2' );
}
$purge = static function () use ( &$key_files ): void {
	foreach ( $key_files as $f ) {
		if ( is_file( $f ) ) {
			wp_delete_file( $f );
		}
	}
};
$purge();
[ $fid, $fpath ] = $design( $sheet, array( '_dxai_ui_font_urls' => array( $google, $google2 ) ) );
$orig   = (string) file_get_contents( $fpath );
$before = count( $hits );
$r      = Font_Host::localize_design( $fid );
$after  = (string) file_get_contents( $fpath );
$expect( 'on a theme that manages the fonts, the design\'s are not copied', $r['changed'] && $r['complete'] && count( $hits ) === $before, json_encode( $r ) );
$expect( 'its sheet says it is drawn in the theme\'s fonts', Font_Host::design_state( $fid ) === 'theme' );
$expect( 'no font rule and no request to Google is left in it', ! str_contains( $after, '@font-face' ) && ! str_contains( substr( $after, (int) strpos( $after, '*/' ) ), 'fonts.googleapis.com' ), $after );
$expect( 'its own rules follow, untouched', str_contains( $after, ".hero { font-family: 'Vtx Tfserif', serif }" ) );
$expect( 'no font file was written for it', ! is_file( $key_files[2] ) && ! is_file( $key_files[3] ) );
$again = Font_Host::adopt_design( $fid );
$expect( 'a second run changes nothing', ! $again['changed'] );
$expect( 'the export gets the sheet as it was imported', file_get_contents( Font_Host::portable( $fpath ) ) === $orig );
$expect( 'the stylesheets it asked for are remembered', count( Font_Host::covered( $fpath ) ) === 2 );

// Its fonts come back: it keeps its own now.
$own = Speed::own_fonts( $fid );
$back = (string) file_get_contents( $fpath );
$expect( 'told to keep its own, the design\'s fonts are copied again', $own['changed'] && $own['complete'] && Font_Host::design_state( $fid ) === 'local' && substr_count( $back, '@font-face' ) === 3, json_encode( $own ) );
$expect( '… from the files on this site', is_file( $key_files[2] ) && is_file( $key_files[3] ) && is_file( $key_files[4] ) );
$expect( '… and the sheet is the imported one when reverted', Font_Host::revert( $fpath ) && file_get_contents( $fpath ) === $orig );
$mode = Speed::theme_fonts( $fid );
$expect( 'told to use the theme\'s again, they leave', $mode['changed'] && Font_Host::design_state( $fid ) === 'theme' && ! str_contains( (string) file_get_contents( $fpath ), '@font-face' ) );

// The theme stops managing fonts: the migration gives the design its fonts back.
$switch( false );
$expect( 'a design in the theme\'s fonts, on a theme that no longer manages them, is found', Font_Host::design_state( $fid ) === 'theme' && ! Theme_Fonts::adopts( $fid ) );
Theme_Fonts::migrate( $fid );
$expect( 'the migration copies its fonts back', Font_Host::design_state( $fid ) === 'local' && substr_count( (string) file_get_contents( $fpath ), '@font-face' ) === 3 );
$switch( $theme );
Theme_Fonts::migrate( $fid );
$expect( 'on a theme that manages them again, the migration takes them out', Font_Host::design_state( $fid ) === 'theme' && ! str_contains( (string) file_get_contents( $fpath ), '@font-face' ) );
Theme_Fonts::migrate( $fid );
$expect( 'the migration of a design that is in order changes nothing', Font_Host::design_state( $fid ) === 'theme' );

// A sheet that lists fonts it does not import, and a sheet no Google fonts.
[ $lid, $lpath ] = $design( "$scope { font-family: 'Vtx Tfsans' }\n", array( '_dxai_ui_font_urls' => array( $google ) ) );
$r = Font_Host::adopt( $lpath, array( $google ) );
$expect( 'a listed stylesheet the sheet does not import is recorded, the sheet drawn in the theme\'s', $r['changed'] && Font_Host::state( $lpath, array( $google ) ) === 'theme' );
[ $nid, $npath ] = $design( "$scope { font-family: 'Vtx Tfsans' }\n" );
$r = Font_Host::adopt( $npath );
$expect( 'a sheet with no Google fonts is not touched', ! $r['changed'] && $r['note'] === 'no Google fonts' && Font_Host::state( $npath ) === 'none' );
$expect( 'its own migration changes nothing', (function () use ( $nid, $npath ) { $b = file_get_contents( $npath ); Theme_Fonts::migrate( $nid ); return $b === file_get_contents( $npath ); })() );

// Fonts put in by hand (the interim fix of the Semper page): they leave, the record keeps them.
$hand = "/* Web fonts served from this site (fonts/), not from Google Fonts. */\n@font-face { font-family: 'Vtx Tfsans'; src: url(fonts/aaaaaaaaaaaaaaaaaaaa.woff2) format('woff2') }\n.keep-me{color:red}\n";
[ $hid, $hpath ] = $design( $hand, array( '_dxai_ui_font_urls' => array( $google ) ) );
$horig = (string) file_get_contents( $hpath );
$r     = Font_Host::adopt( $hpath, array( $google ) );
$hnow  = (string) file_get_contents( $hpath );
$outside = (string) preg_replace( '~/\*! dxai-ui local fonts .*?/\*! dxai-ui local fonts end \*/~s', '', $hnow );
$expect( 'the rules written by hand leave the sheet, the rest stays', $r['changed'] && ! str_contains( $outside, '@font-face' ) && str_contains( $outside, '.keep-me{color:red}' ), $hnow );
$expect( '… and reverting gives them back byte for byte', Font_Host::revert( $hpath ) && file_get_contents( $hpath ) === $horig );

// --- the files nothing names ---------------------------------------------------------------------------------------
echo "\nThe font files nothing names\n";
$dir     = Upload_Paths::path( Upload_Paths::DIR . '/fonts' );
$orphan  = $dir . '/bbbbbbbbbbbbbbbbbbbb.woff2';
$fresh   = $dir . '/cccccccccccccccccccc.woff2';
$named   = $dir . '/dddddddddddddddddddd.woff2';
$other_p = Upload_Paths::path( Upload_Paths::DIR . '/pattern-vtxtfkeep.css' );
foreach ( array( $orphan, $fresh, $named ) as $f ) {
	file_put_contents( $f, 'wOF2' . str_repeat( 'y', 64 ) );
}
touch( $orphan, time() - 3600 );
touch( $named, time() - 3600 );
file_put_contents( $other_p, "@font-face{font-family:'Vtx Tfkeep';src:url(fonts/dddddddddddddddddddd.woff2) format('woff2')}\n" );
$dry = Font_Host::clean_files( true );
$expect( 'a file no stylesheet names is found', in_array( 'bbbbbbbbbbbbbbbbbbbb.woff2', $dry['names'], true ) );
$expect( 'a file a stylesheet names is kept', ! in_array( 'dddddddddddddddddddd.woff2', $dry['names'], true ) );
$expect( 'a file written a moment ago is kept (a copy may be in progress)', ! in_array( 'cccccccccccccccccccc.woff2', $dry['names'], true ) );
$expect( 'a dry run removes nothing', is_file( $orphan ) && is_file( $fresh ) && is_file( $named ) );
// A file named only by the record (what the sheet used to say) is not a use.
[ $rid, $rpath ] = $design( $sheet, array( '_dxai_ui_font_urls' => array( $google, $google2 ) ) );
Font_Host::localize_sheet( $rpath, array( $google2 ) );
preg_match_all( '#url\(fonts/([0-9a-f]{20}\.woff2)\)#', (string) file_get_contents( $rpath ), $m );
$names_local = $m[1];
$age         = static function () use ( &$names_local, $dir ): void {
	foreach ( $names_local as $n ) {
		if ( is_file( $dir . '/' . $n ) ) {
			touch( $dir . '/' . $n, time() - 3600 );
		}
	}
};
$age();
$expect( 'the files a sheet draws its fonts from are kept', count( $names_local ) === 3 && ! array_intersect( $names_local, Font_Host::clean_files( true )['names'] ) );
Font_Host::adopt( $rpath );
$age();
$gone = Font_Host::clean_files( true )['names'];
$expect( 'once the sheet gave its fonts to the theme, the files it drew them from are found', array_diff( $names_local, $gone ) === array() );

// --- the block rules follow the mode at once ----------------------------------------------------------------------
echo "\nThe block rules follow the mode\n";
$expect( 'the rules of a block are cached under a signature that changes with the mode', Theme_Fonts::signature( $fid ) !== '' );
update_post_meta( $fid, Theme_Fonts::MODE_META, 'keep' );
Theme_Fonts::reset();
$expect( '… and that is empty once the design keeps its own', Theme_Fonts::signature( $fid ) === '' );
delete_post_meta( $fid, Theme_Fonts::MODE_META );
Theme_Fonts::reset();

// --- clean up ------------------------------------------------------------------------------------------------------
foreach ( $made as [ $pid, $ppath ] ) {
	Font_Host::revert( $ppath );
	wp_delete_file( $ppath );
	wp_trash_post( $pid );
	delete_transient( 'dxai_ui_fonts_wait_' . $pid );
}
$purge();
foreach ( array( $orphan, $fresh, $named, $other_p ) as $f ) {
	if ( is_file( $f ) ) {
		wp_delete_file( $f );
	}
}
foreach ( $names_local as $n ) {
	if ( is_file( $dir . '/' . $n ) ) {
		wp_delete_file( $dir . '/' . $n );
	}
}
foreach ( array( $google, $google2 ) as $g ) {
	delete_transient( 'dxai_ui_fonts_wait_' . Font_Host::key( $g ) );
}
Theme_Fonts::reset();

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

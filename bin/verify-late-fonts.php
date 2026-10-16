<?php
/**
 * A design's web fonts are asked for once its page has loaded and its first paint was shown; their fallback faces draw it until then.
 *
 *   bash bin/wp-php.sh bin/verify-late-fonts.php        (or: wp eval-file bin/verify-late-fonts.php --user=1)
 *
 * Lighthouse counts every request that starts before the page's first and largest paints; on H2O Away the design's five font files held
 * the phone's LCP at 2.7 s (2.0–2.2 s without them), and their bytes were not what counted (docs/PLAN-PERFECT-PAGES.md, T5.2). The user
 * chose to have them come late. Media\Late_Fonts takes the @font-face rules that load a file out of what the plugin prints (hold()) —
 * the page's brand CSS (Blocks\Assets: checked in verify-theme-fonts), the template's fonts on the theme's pages
 * (Template_Fonts::print_site()), a design's footer on a theme's page (Site_Footer_Block) — and prints them at the end of the page in a
 * style element for print, which a few lines switch on once the page has loaded and its first contentful paint was shown, or
 * BACKSTOP ms after it loaded; again in <noscript>. The fallback faces (local fonts) stay where they were. Not in the admin, nor when
 * `dxai_ui_late_fonts` says no.
 *
 * Writes nothing: a record of the template's fonts is given for the run (pre_option). Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Media\Late_Fonts;
use DXAI_UI\Theme\Template_Fonts;

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
// What print_late() prints now (and so, what was held), with the held faces let go.
$printed = static function (): string {
	ob_start();
	Late_Fonts::print_late();

	return (string) ob_get_clean();
};
$printed();

echo "What waits\n";
$web   = '@font-face{font-family:"Lf Body";font-style:normal;font-weight:400;font-display:swap;src:url(https://example.test/uploads/dxai-ui/template-fonts/a.woff2) format("woff2");unicode-range:U+0000-00FF}';
$both  = '@font-face{font-family:"Lf Head";font-weight:700;src:local("Lf Head Bold"),url(/fonts/head.woff2) format("woff2")}';
$local = '@font-face{font-family:"Lf Body Fallback";font-style:normal;font-weight:100 500;src:local("Arial"),local("ArialMT");size-adjust:104.2%;ascent-override:90%}';
$rest  = ':root{--font-primary:"Lf Body","Lf Body Fallback",sans-serif}';
$kept  = Late_Fonts::hold( $web . "\n" . $local . $rest . $both );
$expect( 'a face that loads a file is taken out (also one that names a local font before its file)', ! str_contains( $kept, 'a.woff2' ) && ! str_contains( $kept, 'head.woff2' ), $kept );
$expect( 'a fallback face (a local font) and the rest of the CSS stay where they were', str_contains( $kept, $local ) && str_contains( $kept, $rest ), $kept );
Late_Fonts::hold( $web );
$expect( 'the faces held are kept in order, each once', Late_Fonts::held() === $web . "\n" . $both, Late_Fonts::held() );
$out = $printed();
$expect( 'the end of the page has them in a style element for print', 1 === preg_match( '#<style id="' . Late_Fonts::ID . '" media="print">([^<]*)</style>#', $out, $m ) && $m[1] === $web . "\n" . $both, $out );
$expect( '…switched on once the page has loaded AND its first contentful paint was shown (the second of the two), or BACKSTOP ms after it loaded', str_contains( $out, "s.media='all'" ) && str_contains( $out, "++n===2" ) && str_contains( $out, "'first-contentful-paint'" ) && str_contains( $out, "observe({type:'paint',buffered:true})" ) && str_contains( $out, "addEventListener('load',loaded)" ) && str_contains( $out, 'readyState===\'complete\'' ) && str_contains( $out, ',' . Late_Fonts::BACKSTOP . ');' ), $out );
$expect( '…and in <noscript> for a browser without scripts', str_contains( $out, '<noscript><style>' . $web . "\n" . $both . '</style></noscript>' ), $out );
$expect( 'printed once: the next call prints nothing', '' === $printed() );
$expect( 'nothing at all on a page that held none', '' === $printed() && '' === Late_Fonts::held() );
Late_Fonts::hold( '@font-face{font-family:"x</style><script>";src:url(a.woff2)}' );
$expect( 'nothing held can close the style element', ! str_contains( $printed(), '</style><script>' ) );
$expect( 'CSS without faces is left as it is', 'a{color:red}' === Late_Fonts::hold( 'a{color:red}' ) && '' === Late_Fonts::held() );

echo "\nWhere it does not\n";
add_filter( 'dxai_ui_late_fonts', '__return_false' );
$off = Late_Fonts::hold( $web . $local );
remove_filter( 'dxai_ui_late_fonts', '__return_false' );
$expect( 'nothing when the filter says no: the faces print where they were', $off === $web . $local && '' === Late_Fonts::held() );
set_current_screen( 'dashboard' );
$admin = Late_Fonts::hold( $web );
$GLOBALS['current_screen'] = null;
$expect( 'nothing in the admin (the editor shows the fonts at once)', $admin === $web && '' === Late_Fonts::held(), $admin );

echo "\nThe template's fonts on the theme's pages\n";
$record = static fn() => array(
	'v'              => 1,
	'heading'        => '"Lf Head",sans-serif',
	'body'           => '"Lf Body",sans-serif',
	'heading_family' => 'Lf Head',
	'body_family'    => 'Lf Body',
	'faces'          => $web,
	'fallbacks'      => array(
		'Lf Body' => array(
			'kind'   => 'sans',
			'normal' => array( 'upm' => 1000, 'ascent' => 900, 'descent' => -300, 'gap' => 0, 'avg' => 480.0 ),
		),
	),
	'files'          => array( 'a.woff2' ),
	'preload'        => array( array( 'family' => 'Lf Body', 'url' => 'https://example.test/uploads/dxai-ui/template-fonts/a.woff2' ) ),
);
add_filter( 'pre_option_' . Template_Fonts::OPTION, $record );
add_filter( 'dxai_ui_template_fonts_choice', '__return_empty_array', 99 );
ob_start();
Template_Fonts::print_site();
$site = (string) ob_get_clean();
$held = Late_Fonts::held();
$printed();
$expect( 'the page prints the stacks and the fallback face at once, and the web face waits for the end of the page', str_contains( $site, 'id="dxai-template-fonts"' ) && str_contains( $site, '--font-primary:' ) && str_contains( $site, '@font-face{font-family:"Lf Body Fallback"' ) && ! str_contains( $site, 'a.woff2' ) && $held === $web, $site . ' | ' . $held );
$theme_dir = trailingslashit( get_stylesheet_directory_uri() ) . 'assets/src/fonts/';
$expect( 'and the theme is not asked to preload what waits', array( 'https://example.com/other.woff2' ) === Template_Fonts::preload( array( $theme_dir . 'x.woff2', 'https://example.com/other.woff2' ) ) );
remove_filter( 'dxai_ui_template_fonts_choice', '__return_empty_array', 99 );
remove_filter( 'pre_option_' . Template_Fonts::OPTION, $record );

echo "\nThe plugin prints it\n";
$expect( 'the end of every page prints what waits (wp_footer, after the footer the template draws)', 20 === has_action( 'wp_footer', array( Late_Fonts::class, 'print_late' ) ) );

echo "\n{$pass} passed, {$fail} failed\n";
if ( $fail > 0 ) {
	exit( 1 );
}

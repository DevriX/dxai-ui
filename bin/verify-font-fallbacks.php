<?php
/**
 * The template's fonts have fallback faces of their size, so nothing moves when they arrive.
 *
 *   bash bin/wp-php.sh bin/verify-font-fallbacks.php        (or: wp eval-file bin/verify-font-fallbacks.php --user=1)
 *
 * On H2O Away's Home the phone header was 69 px tall in the fallback font and 74 px in Hind, and the page moved 5 px down as the fonts
 * came: all of its layout shift (CLS 0.077; docs/PLAN-PERFECT-PAGES.md, T5.2). Each template font now has "<family> Fallback": a local
 * font with the web font's line metrics and average width (Media\Font_Metrics), right after it in the stacks (Theme\Template_Fonts).
 *
 * Nothing leaves the machine: the font is made here (a TrueType file with known numbers) and Google's answers too (pre_http_request).
 * The site's template fonts record, if any, is put back as it was. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Media\Font_Metrics;
use DXAI_UI\Theme\Template_Fonts;

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

// A TrueType file with known numbers: 1000 units per em, ascent 800, descent -200, line gap 100 (OS/2's typographic ones 900/-300/0,
// used only when $typo asks for them); lowercase letters 400 wide, capitals 800, the space 200, comma and full stop 300.
$font = static function ( bool $typo = false, int $lower = 400 ): string {
	$codes = array( 32 => 200, 44 => 300, 46 => 300 );
	foreach ( range( 65, 90 ) as $c ) {
		$codes[ $c ] = 800;
	}
	foreach ( range( 97, 122 ) as $c ) {
		$codes[ $c ] = $lower;
	}
	$glyph = 1;
	$gid   = array();
	$adv   = array( 0 );
	foreach ( $codes as $code => $width ) {
		$gid[ $code ] = $glyph++;
		$adv[]        = $width;
	}
	// cmap format 4: one segment per code here, then the 0xFFFF end segment.
	$segs  = array_keys( $codes );
	$n     = count( $segs ) + 1;
	$ends  = '';
	$start = '';
	$delta = '';
	$range = '';
	foreach ( $segs as $code ) {
		$ends  .= pack( 'n', $code );
		$start .= pack( 'n', $code );
		$delta .= pack( 'n', ( $gid[ $code ] - $code ) & 0xFFFF );
		$range .= pack( 'n', 0 );
	}
	$ends  .= pack( 'n', 0xFFFF );
	$start .= pack( 'n', 0xFFFF );
	$delta .= pack( 'n', 1 );
	$range .= pack( 'n', 0 );
	$sub    = pack( 'nnnnnnn', 4, 16 + 8 * $n, 0, 2 * $n, 0, 0, 0 ) . $ends . pack( 'n', 0 ) . $start . $delta . $range;
	$tables = array(
		'OS/2' => str_repeat( "\0", 62 ) . pack( 'n', $typo ? 0x80 : 0 ) . str_repeat( "\0", 4 ) . pack( 'nnn', 900, ( -300 ) & 0xFFFF, 0 ) . str_repeat( "\0", 10 ),
		'cmap' => pack( 'nnnnN', 0, 1, 3, 1, 12 ) . $sub,
		'head' => str_repeat( "\0", 18 ) . pack( 'n', 1000 ) . str_repeat( "\0", 34 ),
		'hhea' => str_repeat( "\0", 4 ) . pack( 'nnn', 800, ( -200 ) & 0xFFFF, 100 ) . str_repeat( "\0", 24 ) . pack( 'n', count( $adv ) ),
		'hmtx' => implode( '', array_map( static fn( $w ) => pack( 'nn', $w, 0 ), $adv ) ),
	);
	$out    = pack( 'Nnnnn', 0x00010000, count( $tables ), 0, 0, 0 );
	$offset = 12 + 16 * count( $tables );
	$body   = '';
	foreach ( $tables as $tag => $data ) {
		$out    .= $tag . pack( 'NNN', 0, $offset + strlen( $body ), strlen( $data ) );
		$body   .= $data . str_repeat( "\0", ( 4 - strlen( $data ) % 4 ) % 4 );
	}

	return $out . $body;
};

echo "Reading a font\n";
$m = Font_Metrics::read( $font() );
$expect( 'units per em, ascent, descent and line gap from head and hhea', is_array( $m ) && 1000 === $m['upm'] && 800 === $m['ascent'] && -200 === $m['descent'] && 100 === $m['gap'], wp_json_encode( $m ) );
// 0.75 of the text in lowercase (400), 0.06 in capitals (800), 0.17 spaces (200), 0.02 commas and full stops (300): 388.
$expect( 'the average width: each letter, capital, space and stop by how often it occurs (388 here)', is_array( $m ) && abs( $m['avg'] - 388 ) < 0.01, is_array( $m ) ? (string) $m['avg'] : '' );
$t = Font_Metrics::read( $font( true ) );
$expect( 'a font that asks for its typographic metrics (USE_TYPO_METRICS) is read with them', is_array( $t ) && 900 === $t['ascent'] && -300 === $t['descent'] && 0 === $t['gap'], wp_json_encode( $t ) );
$expect( 'not a font: nothing', null === Font_Metrics::read( 'wOF2' . str_repeat( 'x', 64 ) ) && null === Font_Metrics::read( '' ) );

echo "\nThe fallback face\n";
$css   = is_array( $m ) ? Font_Metrics::fallback_css( 'Tf Body', $m ) : '';
$arial = (array) Font_Metrics::local_metrics( 'sans', 'normal' );
$adj   = ( 388 / 1000 ) / ( (float) $arial['avg'] / (int) $arial['upm'] );
$pct   = static fn( float $v ): string => rtrim( rtrim( number_format( $v * 100, 2, '.', '' ), '0' ), '.' ) . '%';
$expect( 'named after the font, from the local Arial (or its metric twins)', str_contains( $css, 'font-family:"Tf Body Fallback"' ) && str_contains( $css, 'local("Arial")' ) && str_contains( $css, 'local("Liberation Sans")' ), $css );
$expect( 'scaled to the font\'s average width (size-adjust)', str_contains( $css, 'size-adjust:' . $pct( $adj ) . ';' ), $pct( $adj ) . ' in ' . $css );
$expect( 'with its line: ascent, descent and line gap in the scaled em', str_contains( $css, 'ascent-override:' . $pct( 0.8 / $adj ) . ';' ) && str_contains( $css, 'descent-override:' . $pct( 0.2 / $adj ) . ';' ) && str_contains( $css, 'line-gap-override:' . $pct( 0.1 / $adj ) . '}' ), $css );
$bold  = is_array( $m ) ? Font_Metrics::fallback_css( 'Tf Body', $m, 'sans', Font_Metrics::read( $font( false, 600 ) ) ) : '';
$abold = (array) Font_Metrics::local_metrics( 'sans', 'bold' );
$bavg  = ( 0.75 * 600 + 0.06 * 800 + 0.17 * 200 + 0.02 * 300 );
$expect( 'the bold weights from the local bold, scaled to the font\'s bold', str_contains( $bold, 'font-weight:600 900;src:local("Arial Bold")' ) && str_contains( $bold, 'size-adjust:' . $pct( ( $bavg / 1000 ) / ( (float) $abold['avg'] / (int) $abold['upm'] ) ) . ';' ), $bold );
$expect( 'a serif from Times New Roman', is_array( $m ) && str_contains( Font_Metrics::fallback_css( 'Tf Serif', $m, 'serif' ), 'local("Times New Roman")' ) );

echo "\nThe template's fonts\n";
$record = array(
	'v'              => 1,
	'design'         => 0,
	'source'         => '',
	'title'          => 'Fallback fixture',
	'heading'        => '"Tf Display", Impact, sans-serif',
	'body'           => "'Tf Body', system-ui, sans-serif",
	'heading_family' => 'Tf Display',
	'body_family'    => 'Tf Body',
	'faces'          => '@font-face{font-family:"Tf Display";font-style:normal;font-weight:400;font-display:swap;src:url(https://example.test/d.woff2) format("woff2")}' . "\n"
		. '@font-face{font-family:"Tf Body";font-style:normal;font-weight:400;font-display:swap;src:url(https://example.test/b4.woff2) format("woff2")}' . "\n"
		. '@font-face{font-family:"Tf Body";font-style:normal;font-weight:700;font-display:swap;src:url(https://example.test/b7.woff2) format("woff2")}',
	'files'          => array(),
	'urls'           => array(),
	'installed'      => time(),
	'complete'       => true,
);
// Google, as the measuring asks it: a stylesheet naming a TrueType file for each weight of Tf Body, and that file; Tf Display is unknown.
$asked   = array();
$answers = static function ( $pre, $args, $url ) use ( $font, &$asked ) {
	if ( ! is_string( $url ) || ( ! str_starts_with( $url, 'https://fonts.googleapis.com/' ) && ! str_starts_with( $url, 'https://fonts.gstatic.com/' ) ) ) {
		return $pre;
	}
	$asked[] = $url;
	$ok      = static fn( string $body ): array => array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => $body, 'headers' => array(), 'cookies' => array(), 'filename' => null );
	if ( preg_match( '#family=Tf\+Body:wght@(\d+)#', $url, $w ) === 1 ) {
		return $ok( "@font-face {\n  font-family: 'Tf Body';\n  font-weight: {$w[1]};\n  src: url(https://fonts.gstatic.com/s/tfbody/v1/w{$w[1]}.ttf) format('truetype');\n}\n" );
	}
	if ( str_starts_with( $url, 'https://fonts.gstatic.com/s/tfbody/v1/w' ) ) {
		return $ok( $font( false, str_contains( $url, 'w700' ) ? 500 : 400 ) );
	}

	return array( 'response' => array( 'code' => 400, 'message' => 'Bad Request' ), 'body' => '', 'headers' => array(), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $answers, 99, 3 );
$read = Template_Fonts::measure( $record );
$expect( 'measured from Google\'s TrueType file: Tf Body regular and bold (its 700), a sans', isset( $read['Tf Body'] ) && 'sans' === $read['Tf Body']['kind'] && abs( (float) $read['Tf Body']['normal']['avg'] - 388 ) < 0.01 && is_array( $read['Tf Body']['bold'] ) && abs( (float) $read['Tf Body']['bold']['avg'] - 463 ) < 0.01, wp_json_encode( $read ) );
$expect( 'a font Google does not answer for has none (Tf Display)', ! isset( $read['Tf Display'] ) );
$expect( 'nothing is asked of any other address', array() === array_filter( $asked, static fn( $u ) => ! preg_match( '#^https://fonts\.(googleapis|gstatic)\.com/#', $u ) ) );
$serif = Template_Fonts::measure( array_merge( $record, array( 'body' => "'Tf Body', Georgia, serif" ) ) );
$expect( 'a serif stack gets a serif fallback', 'serif' === ( $serif['Tf Body']['kind'] ?? '' ) );
remove_filter( 'pre_http_request', $answers, 99 );

$with = array_merge( $record, array( 'fallbacks' => $read ) );
$expect( 'the stack names the fallback face right after the font', '\'Tf Body\', "Tf Body Fallback", system-ui, sans-serif' === Template_Fonts::stack( $with, 'body' ), Template_Fonts::stack( $with, 'body' ) );
$expect( '…and a font without numbers is left as it is', '"Tf Display", Impact, sans-serif' === Template_Fonts::stack( $with, 'heading' ) && "'Tf Body', system-ui, sans-serif" === Template_Fonts::stack( $record, 'body' ) );
$expect( 'the faces carry the fallback face', str_contains( Template_Fonts::faces( $with ), 'font-family:"Tf Body Fallback"' ) && str_starts_with( Template_Fonts::faces( $with ), (string) $record['faces'] ) );
$expect( 'what the site prints: the faces and the variables with the fallback', str_contains( Template_Fonts::site_css( $with ), '--font-primary:\'Tf Body\', "Tf Body Fallback", system-ui, sans-serif;' ) && str_contains( Template_Fonts::site_css( $with ), '"Tf Body Fallback";font-style:normal' ) );
add_filter( 'dxai_ui_font_fallbacks', '__return_false' );
$expect( 'none of it when the filter says no', "'Tf Body', system-ui, sans-serif" === Template_Fonts::stack( $with, 'body' ) && ! str_contains( Template_Fonts::faces( $with ), 'Fallback' ) );
remove_filter( 'dxai_ui_font_fallbacks', '__return_false' );

echo "\nFonts installed before\n";
$before = get_option( Template_Fonts::OPTION, null );
$put    = static function () use ( $before ): void {
	if ( null === $before ) {
		delete_option( Template_Fonts::OPTION );
	} else {
		update_option( Template_Fonts::OPTION, $before, true );
	}
	wp_clear_scheduled_hook( Template_Fonts::MEASURE_HOOK );
};
register_shutdown_function( $put );
wp_clear_scheduled_hook( Template_Fonts::MEASURE_HOOK );
update_option( Template_Fonts::OPTION, $record, true );
Template_Fonts::maybe_measure();
$expect( 'a record without numbers gets them by cron, once', false !== wp_next_scheduled( Template_Fonts::MEASURE_HOOK ) );
add_filter( 'pre_http_request', $answers, 99, 3 );
$now = Template_Fonts::measure_now();
remove_filter( 'pre_http_request', $answers, 99 );
$kept = (array) get_option( Template_Fonts::OPTION, array() );
$expect( 'the event reads them and keeps them with the record', isset( $now['Tf Body'], $kept['fallbacks']['Tf Body'] ) && (int) ( $kept['measured'] ?? 0 ) > 0 && $kept['faces'] === $record['faces'] );
wp_clear_scheduled_hook( Template_Fonts::MEASURE_HOOK );
Template_Fonts::maybe_measure();
$expect( '…and a record with numbers is not measured again', false === wp_next_scheduled( Template_Fonts::MEASURE_HOOK ) );
update_option( Template_Fonts::OPTION, array_merge( $record, array( 'fallbacks' => array(), 'measured' => time() ) ), true );
Template_Fonts::maybe_measure();
$expect( 'one Google did not answer for is tried again a day later, not at once', false === wp_next_scheduled( Template_Fonts::MEASURE_HOOK ) );
$put();

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );

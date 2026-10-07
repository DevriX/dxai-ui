<?php
/**
 * The template's fonts: a design's fonts hosted on the site and set as the site's (Template_Fonts).
 *
 *   bash bin/wp-php.sh bin/verify-template-fonts.php        (or: wp eval-file bin/verify-template-fonts.php --user=1)
 *
 * Nothing leaves the machine: Google's answers are made here (pre_http_request), with a few subsets and two fonts, so that what is kept,
 * what is dropped and what is printed can be read off. It makes a design (a page with a stylesheet and the address of its fonts), a
 * stylesheet and some font files, and takes them away when it ends however it ends. A site that has template fonts installed is left alone
 * (the suite would replace them): run it on a test install.
 *
 * Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Media\Font_Host;
use DXAI_UI\Support\Upload_Paths;
use DXAI_UI\Theme\Template_Fonts;
use DXAI_UI\Theme\Theme_Compat;
use DXAI_UI\Theme\Theme_Fonts;

if ( Template_Fonts::get() !== array() ) {
	echo "This site has template fonts installed: the suite would replace them. Run it on a test install. (not run)\n";
	exit( 0 );
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

$run      = substr( md5( microtime( true ) . wp_rand() ), 0, 8 );
$dx       = function_exists( 'amr_get_font_presets' );
$google   = 'https://fonts.googleapis.com/css2?family=Tf+Display&family=Tf+Body:wght@400;700&display=swap&run=' . $run;
$bad_url  = 'https://fonts.googleapis.com/css2?family=Tf+Offline&display=swap&run=' . $run;
$remote   = static fn( string $part ): string => 'https://fonts.gstatic.com/s/tf' . $run . '/' . $part . '.woff2';
$name_of  = static fn( string $url ): string => substr( sha1( $url ), 0, 20 ) . '.woff2';
$gstatic  = array();
$face     = static function ( string $subset, string $family, string $weight, string $range, string $key ) use ( $remote, &$gstatic ): string {
	$gstatic[] = $remote( $key );

	return "/* $subset */\n@font-face {\n  font-family: '$family';\n  font-style: normal;\n  font-weight: $weight;\n  font-display: swap;\n  src: url(" . $remote( $key ) . ") format('woff2');\n  unicode-range: $range;\n}\n";
};
$css      = $face( 'vietnamese', 'Tf Display', '400', 'U+0102-0103, U+1EA0-1EF9', 'd-viet' )
	. $face( 'latin-ext', 'Tf Display', '400', 'U+0100-02BA, U+02BD-02C5', 'd-lext' )
	. $face( 'latin', 'Tf Display', '400', 'U+0000-00FF, U+0131', 'd-lat' )
	. $face( 'devanagari', 'Tf Body', '400', 'U+0900-097F', 'b4-dev' )
	. $face( 'latin-ext', 'Tf Body', '400', 'U+0100-02BA', 'b4-lext' )
	. $face( 'latin', 'Tf Body', '400', 'U+0000-00FF', 'b4-lat' )
	. $face( 'latin', 'Tf Body', '700', 'U+0000-00FF', 'b7-lat' )
	// Faces that must never be kept: a family that could end the rule, and a file that is not on Google's CDN.
	. "/* latin */\n@font-face { font-family: 'Evil}body{x'; src: url(" . $remote( 'evil' ) . ") format('woff2'); }\n";
$gstatic[] = $remote( 'evil' );

// What the network answers: Google's stylesheet for the address of the design, a font file for each address on its CDN, and a failure for one address.
$answers = static function ( $pre, $args, $url ) use ( $google, $bad_url, $css ) {
	if ( ! is_string( $url ) ) {
		return $pre;
	}
	if ( $url === $google ) {
		return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => $css, 'headers' => array(), 'cookies' => array(), 'filename' => null );
	}
	if ( $url === $bad_url ) {
		return new WP_Error( 'offline', 'no route' );
	}
	if ( str_starts_with( $url, 'https://fonts.gstatic.com/' ) ) {
		return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => 'wOF2' . str_repeat( 'x', 300 ), 'headers' => array(), 'cookies' => array(), 'filename' => null );
	}

	return $pre;
};
add_filter( 'pre_http_request', $answers, 99, 3 );

// The design: a page whose stylesheet sets its root in Tf Body and a display line in Tf Display, and that loads its fonts from Google.
$sheet_rel = Upload_Paths::DIR . '/tf-test-' . $run . '.css';
$sheet     = Upload_Paths::path( $sheet_rel );
wp_mkdir_p( dirname( $sheet ) );
$home = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Template fonts fixture ' . $run ), true );
file_put_contents( $sheet, ".dxai-ui.dxai-ui--{$home}{font-family:'Tf Body',system-ui,sans-serif}\n.dxai-ui .tf-display{font-family:'Tf Display',Impact,sans-serif}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
update_post_meta( $home, '_dxai_ui_css_url', $sheet_rel );
update_post_meta( $home, '_dxai_ui_scope_id', $home );
update_post_meta( $home, '_dxai_ui_font_urls', array( $google ) );
$plain = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Template fonts fixture without fonts ' . $run ), true );
file_put_contents( Upload_Paths::path( Upload_Paths::DIR . '/tf-test-plain-' . $run . '.css' ), '.dxai-ui{color:#000}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
update_post_meta( $plain, '_dxai_ui_css_url', Upload_Paths::DIR . '/tf-test-plain-' . $run . '.css' );
update_post_meta( $plain, '_dxai_ui_scope_id', $plain );
update_post_meta( $plain, '_dxai_ui_font_urls', array( 'https://example.com/fonts.css' ) );

$clean = static function () use ( &$home, &$plain, $sheet, $gstatic, $name_of, $google, $bad_url, $run ): void {
	remove_all_filters( 'pre_http_request', 99 );
	Template_Fonts::remove();
	foreach ( $gstatic as $url ) {
		foreach ( array( 'fonts', Template_Fonts::DIR ) as $dir ) {
			$file = Upload_Paths::path( Upload_Paths::DIR . '/' . $dir . '/' . $name_of( $url ) );
			if ( $file !== '' && is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}
	foreach ( array( $google, $bad_url ) as $url ) {
		$copy = Upload_Paths::path( Upload_Paths::DIR . '/fonts/' . Font_Host::key( $url ) . '.css' );
		if ( $copy !== '' && is_file( $copy ) ) {
			wp_delete_file( $copy );
		}
	}
	foreach ( array( $sheet, Upload_Paths::path( Upload_Paths::DIR . '/tf-test-plain-' . $run . '.css' ) ) as $file ) {
		if ( $file !== '' && is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}
	foreach ( array( $home, $plain ) as $id ) {
		if ( $id > 0 ) {
			wp_delete_post( $id, true );
		}
	}
	$home  = 0;
	$plain = 0;
	Theme_Fonts::reset();
};
register_shutdown_function( $clean );

echo "A design that loads its fonts from Google\n";
$expect( 'the fixture is a design', \DXAI_UI\Structures\Design_Attach::is_design( $home ) && \DXAI_UI\Structures\Design_Attach::is_design( $plain ) );
$done = Template_Fonts::install( $home );
$expect( 'its fonts are installed as the template\'s', is_array( $done ) && ! empty( $done['installed'] ), is_wp_error( $done ) ? $done->get_error_message() : wp_json_encode( $done ) );
$rec = Template_Fonts::get();
$expect( 'the page root\'s font is the body font, any other web font the headline font', ( $rec['body_family'] ?? '' ) === 'Tf Body' && ( $rec['heading_family'] ?? '' ) === 'Tf Display', wp_json_encode( array( $rec['heading_family'] ?? '', $rec['body_family'] ?? '' ) ) );
$expect( 'their stacks are the design\'s own, fallbacks included', ( $rec['heading'] ?? '' ) === "'Tf Display',Impact,sans-serif" && ( $rec['body'] ?? '' ) === "'Tf Body',system-ui,sans-serif", wp_json_encode( array( $rec['heading'] ?? '', $rec['body'] ?? '' ) ) );
$faces = (string) ( $rec['faces'] ?? '' );
$expect( 'only the Latin faces are kept: 1 + 1 + 1 + 1 + 1 (Latin and Latin Extended; Vietnamese and Devanagari are not text this site has)', 5 === substr_count( $faces, '@font-face' ), (string) substr_count( $faces, '@font-face' ) );
$expect( 'the weights and unicode ranges are kept as Google gave them', str_contains( $faces, 'font-weight:700' ) && str_contains( $faces, 'unicode-range:U+0000-00FF' ) && str_contains( $faces, 'unicode-range:U+0100-02BA' ) );
$expect( 'a face with a family that could end the rule is not kept', ! str_contains( $faces, 'Evil' ) && ! str_contains( $faces, 'body{x' ) );
$expect( 'the files are served from this site\'s uploads, in a folder of their own', str_contains( $faces, '/' . Upload_Paths::DIR . '/' . Template_Fonts::DIR . '/' ) && ! str_contains( $faces, 'fonts.gstatic.com' ) );
$files = (array) ( $rec['files'] ?? array() );
$all   = true;
foreach ( $files as $f ) {
	$all = $all && is_file( Upload_Paths::path( Upload_Paths::DIR . '/' . Template_Fonts::DIR . '/' . $f ) );
}
$expect( 'every file the record names is on the disk', count( $files ) === 5 && $all, (string) count( $files ) );
$expect( 'the answer says what was done', 'Tf Display' === ( $done['heading'] ?? '' ) && 'Tf Body' === ( $done['body'] ?? '' ) && 5 === ( $done['faces'] ?? 0 ) && true === ( $done['complete'] ?? false ) );

echo "\nWhat it feeds\n";
$cur = Theme_Fonts::current();
$expect( 'the theme\'s fonts, for every design that follows them, are the template\'s', null !== $cur && $cur['heading'] === "'Tf Display',Impact,sans-serif" && $cur['body'] === "'Tf Body',system-ui,sans-serif" && str_contains( $cur['faces'], 'Tf Body' ), wp_json_encode( $cur ) );
$expect( 'a design that follows them is drawn in them (the rule printed with its sheet)', str_contains( Theme_Fonts::css( $home ), "'Tf Display',Impact,sans-serif" ) && str_contains( Theme_Fonts::css( $home ), 'font-synthesis-weight:none' ), substr( Theme_Fonts::css( $home ), 0, 200 ) );
$expect( 'a display face with one weight is not drawn bold (the heading font has no bold)', Theme_Fonts::heading_lacks_bold() );
ob_start();
Template_Fonts::print_site();
$printed = ob_get_clean();
$expect( 'every page the theme draws gets the faces and the two variables the theme\'s stylesheet reads', str_contains( $printed, 'id="dxai-template-fonts"' ) && str_contains( $printed, '--font-heading:\'Tf Display\',Impact,sans-serif' ) && str_contains( $printed, '--font-primary:\'Tf Body\',system-ui,sans-serif' ) && ! str_contains( $printed, '</style></style>' ), $printed );
$ed = Template_Fonts::editor_settings( array( 'styles' => array( array( 'css' => 'a{}' ) ) ) );
$expect( 'the block editor gets them as a style of its canvas, after what it had', 2 === count( $ed['styles'] ) && 'a{}' === $ed['styles'][0]['css'] && str_contains( $ed['styles'][1]['css'], '--font-heading' ) );
$theme_dir = trailingslashit( get_stylesheet_directory_uri() ) . 'assets/src/fonts/';
$urls      = Template_Fonts::preload( array( $theme_dir . 'Inter-VariableFont_wght.woff2', 'https://example.com/other.woff2' ) );
$expect( 'the theme\'s fonts module preloads the template\'s two first-paint files and no longer the theme\'s own fonts', 3 === count( $urls ) && ! in_array( $theme_dir . 'Inter-VariableFont_wght.woff2', $urls, true ) && in_array( 'https://example.com/other.woff2', $urls, true ) && 2 === count( array_filter( $urls, static fn( $u ) => str_contains( $u, '/' . Template_Fonts::DIR . '/' ) ) ), wp_json_encode( $urls ) );
$st = Template_Fonts::status();
$expect( 'status says what is installed and that it is in use', ! empty( $st['installed'] ) && ! empty( $st['live'] ) && 'Tf Body' === $st['body'] && 5 === $st['files'] && $st['bytes'] > 0 );

echo "\nThe font files are the site's, not a stylesheet's\n";
// The clean-up leaves what was written in the last ten minutes (a copy in progress): the files are made old, as they will be, so it would take them if it looked at them.
foreach ( $files as $f ) {
	$path = Upload_Paths::path( Upload_Paths::DIR . '/' . Template_Fonts::DIR . '/' . $f );
	if ( $path !== '' && is_file( $path ) ) {
		touch( $path, time() - HOUR_IN_SECONDS );
	}
}
$gone = Font_Host::clean_files( true );
$hit  = array_intersect( $files, (array) $gone['names'] );
$expect( 'the clean-up of fonts no stylesheet names does not touch them', array() === $hit, wp_json_encode( array_values( $hit ) ) );

echo "\nA person's choice wins\n";
if ( $dx ) {
	add_filter( 'dxai_ui_template_fonts_choice', $chose = static fn() => array( 'headlines' => 'bitter' ), 99 );
	Theme_Fonts::reset();
	$cur = Theme_Fonts::current();
	$expect( 'a font chosen in Theme Global Settings is the theme\'s again: the template\'s are not used', null === Template_Fonts::live() && ( null === $cur || ! str_contains( $cur['heading'], 'Tf Display' ) ), wp_json_encode( $cur ) );
	ob_start();
	Template_Fonts::print_site();
	$expect( '…and nothing is printed for them', '' === ob_get_clean() );
	remove_filter( 'dxai_ui_template_fonts_choice', $chose, 99 );
	Theme_Fonts::reset();
	$expect( 'cleared, they are back', null !== Template_Fonts::live() );
	$import = Template_Fonts::for_import( $home, array( 'mode' => 'install' ) );
	$expect( 'an import that installs the design as the template installs the fonts (the theme manages them, nobody chose)', ! empty( $import['installed'] ), wp_json_encode( $import ) );
	add_filter( 'dxai_ui_template_fonts_choice', $chose, 99 );
	$import = Template_Fonts::for_import( $home, array( 'mode' => 'install' ) );
	$expect( 'but not over a font a person chose (reason chosen)', empty( $import['installed'] ) && 'chosen' === ( $import['reason'] ?? '' ), wp_json_encode( $import ) );
	remove_filter( 'dxai_ui_template_fonts_choice', $chose, 99 );
} else {
	echo "  (the theme has no font settings here: the cases about them are skipped)\n";
}
$kept = Template_Fonts::for_import( $home, array( 'mode' => 'keep' ) );
$expect( 'an import that keeps the site\'s header and footer leaves the fonts alone (reason kept)', empty( $kept['installed'] ) && 'kept' === ( $kept['reason'] ?? '' ) );
add_filter( 'dxai_ui_theme_fonts', $unmanaged = static fn() => false, 99 );
Theme_Fonts::reset();
$un = Template_Fonts::for_import( $home, array( 'mode' => 'install' ) );
$expect( 'on a theme that does not manage the site\'s fonts the design keeps its own (reason unmanaged)', empty( $un['installed'] ) && 'unmanaged' === ( $un['reason'] ?? '' ), wp_json_encode( $un ) );
remove_filter( 'dxai_ui_theme_fonts', $unmanaged, 99 );
Theme_Fonts::reset();
add_filter( 'dxai_ui_template_fonts_live', $off = '__return_false', 99 );
$expect( 'a filter can keep them out of use', null === Template_Fonts::live() );
remove_filter( 'dxai_ui_template_fonts_live', $off, 99 );

echo "\nWhat cannot be installed changes nothing\n";
$before = Template_Fonts::get();
$none   = Template_Fonts::install( $plain );
$expect( 'a design that loads no Google font has nothing to host', is_wp_error( $none ) && 'dxai_ui_fonts_none' === $none->get_error_code() );
update_post_meta( $plain, '_dxai_ui_font_urls', array( $bad_url ) );
$off_line = Template_Fonts::install( $plain );
$expect( 'Google out of reach: an error, and the record is as it was', is_wp_error( $off_line ) && 'dxai_ui_fonts_fetch' === $off_line->get_error_code() && Template_Fonts::get() === $before, is_wp_error( $off_line ) ? $off_line->get_error_code() : 'no error' );
$expect( 'something that is not a design is refused', is_wp_error( Template_Fonts::install( 999999 ) ) );
$answer = Template_Fonts::for_import( $plain, array( 'mode' => 'install' ) );
$expect( 'an import never fails over it: the answer says why not (reason ' . ( $answer['reason'] ?? '' ) . ')', empty( $answer['installed'] ) && '' !== ( $answer['reason'] ?? '' ) && '' !== ( $answer['message'] ?? '' ) );

echo "\nInstalling again, and taking them away\n";
$again  = Template_Fonts::install( $home );
$expect( 'installing the same design again is the same record and the same files', ! empty( $again['installed'] ) && Template_Fonts::get()['files'] === $before['files'] && $files === (array) Template_Fonts::get()['files'], wp_json_encode( Template_Fonts::get()['files'] ?? array() ) );
$removed = Template_Fonts::remove();
$expect( 'removing stops them and removes the files', true === $removed['removed'] && 5 === $removed['files'] && array() === Template_Fonts::get() && ! is_file( Upload_Paths::path( Upload_Paths::DIR . '/' . Template_Fonts::DIR . '/' . $files[0] ) ) );
$cur = Theme_Fonts::current();
$expect( 'and the theme\'s own choice is back', null === $cur || ! str_contains( $cur['faces'], 'Tf Body' ) );
$expect( 'removing nothing is not an error', false === Template_Fonts::remove()['removed'] );
$expect( 'the theme\'s own pages print nothing when none is installed', ( static function () { ob_start(); Template_Fonts::print_site(); return ob_get_clean(); } )() === '' );

$clean();
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

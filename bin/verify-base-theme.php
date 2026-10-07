<?php
/**
 * DX Base, the theme the plugin carries for a site that has no DX theme of its own, draws the site's header and footer on every page.
 *
 *   bash bin/wp-php.sh bin/verify-base-theme.php        (or: wp eval-file bin/verify-base-theme.php --user=1)
 *
 * A DX site has one structure: the header is Appearance > Menus, the footer is Appearance > Widgets, drawn in the design the site was
 * imported from, on every page. American Restoration is the theme the team builds that on; DX Base (`themes/dx-base`) has the same locations
 * and areas and a header.php and footer.php that draw the design's (Template_Chrome), so a site without it gets the same. This suite checks
 * what the plugin ships (the theme's files, its copy into the themes folder, the listing from the plugin's own folder where that cannot be
 * written, that nothing is ever deleted or switched by itself) and what a visitor gets with the theme active: a page of no design, a post,
 * an archive, the search and the 404 page, each in the design's header (with the skip link and the spacer under a fixed header) and footer,
 * and the design's own pages exactly as the DX template draws them on any theme.
 *
 * The live part switches the site to DX Base for a few requests and back — in a shutdown function as well, so a failure does not leave the
 * site on it — and calls the site over HTTP as a visitor, so it needs a site that can reach itself. Makes pages and a post and removes them.
 * Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Chrome\Footer_Template;
use DXAI_UI\Chrome\Header_Template;
use DXAI_UI\Theme\Base_Theme;
use DXAI_UI\Transfer\Package;

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

$made     = array();
$tmp      = '';
$original = (string) get_stylesheet();
$fin      = static function () use ( &$made, &$tmp, $original ): void {
	// Back to the theme the site had, whatever happened in between.
	if ( (string) get_stylesheet() !== $original && wp_get_theme( $original )->exists() ) {
		switch_theme( $original );
	}
	foreach ( $made as $id ) {
		wp_delete_post( (int) $id, true );
	}
	$made = array();
	if ( $tmp !== '' && is_dir( $tmp ) ) {
		Package::remove_dir( $tmp );
	}
};
register_shutdown_function( $fin );

$src   = Base_Theme::source();
$files = static function ( string $root ): array {
	$out = array();
	if ( ! is_dir( $root ) ) {
		return $out;
	}
	$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		$out[ ltrim( substr( wp_normalize_path( $file->getPathname() ), strlen( wp_normalize_path( $root ) ) ), '/' ) ] = md5_file( $file->getPathname() );
	}
	ksort( $out );

	return $out;
};

echo "The theme as the plugin carries it\n";
$need    = array( 'style.css', 'functions.php', 'header.php', 'footer.php', 'index.php', 'page.php', 'single.php', '404.php', 'theme.json', 'assets/dx-base.css' );
$missing = array_values( array_filter( $need, static fn( $f ) => ! is_readable( $src . '/' . $f ) ) );
$expect( 'it has what a classic theme needs: a style sheet, the templates, the header and the footer', array() === $missing, implode( ', ', $missing ) );
$code    = array();
foreach ( (array) glob( $src . '/*.php' ) as $file ) {
	$code[ basename( (string) $file ) ] = (string) file_get_contents( (string) $file );
}
$bad = array();
foreach ( $code as $name => $php ) {
	try {
		token_get_all( $php, TOKEN_PARSE );
	} catch ( \ParseError $e ) {
		$bad[] = $name . ': ' . $e->getMessage();
	}
}
$expect( 'every PHP file of it is valid', array() === $bad && count( $code ) >= 7, implode( '; ', $bad ) . ' (' . count( $code ) . ' files)' );
$open = array_values( array_filter( array_keys( $code ), static fn( $n ) => ! str_contains( $code[ $n ], "defined( 'ABSPATH' )" ) ) );
$expect( '…and every one stops when it is opened outside WordPress', array() === $open, implode( ', ', $open ) );
$acf = array_values( array_filter( array_keys( $code ), static fn( $n ) => 1 === preg_match( '/\b(get_field|get_fields|the_field|have_rows|the_row|get_sub_field|the_sub_field|acf_[a-z_]+)\s*\(/', $code[ $n ] ) ) );
$expect( 'it needs no ACF: no template reads a field', array() === $acf, implode( ', ', $acf ) );
$css     = (string) file_get_contents( $src . '/style.css' );
$version = Base_Theme::bundled_version();
preg_match( "/define\\(\\s*'DX_BASE_VERSION',\\s*'([^']+)'/", $code['functions.php'] ?? '', $m );
$expect( 'its version is the one in style.css, and the one its stylesheet is enqueued with', 1 === preg_match( '/^\d+\.\d+\.\d+/', $version ) && ( $m[1] ?? '' ) === $version, $version . ' vs ' . ( $m[1] ?? '' ) );
$expect( 'it is a theme of its own, named for what it is: no parent', 1 === preg_match( '/^\s*Theme Name:\s*DX Base\s*$/mi', $css ) && 1 === preg_match( '/^\s*Text Domain:\s*dx-base\s*$/mi', $css ) && 0 === preg_match( '/^\s*Template:/mi', $css ) );
// The ids register_nav_menus() is given, read from the code.
$menu_keys = static function ( string $php ): array {
	$t    = token_get_all( $php );
	$keys = array();
	$in   = false;
	$deep = 0;
	foreach ( $t as $i => $tok ) {
		if ( is_array( $tok ) && T_STRING === $tok[0] && 'register_nav_menus' === $tok[1] ) {
			$in = true;
			continue;
		}
		if ( ! $in ) {
			continue;
		}
		if ( '(' === $tok ) {
			++$deep;
		} elseif ( ')' === $tok ) {
			--$deep;
			if ( $deep < 1 ) {
				break;
			}
		} elseif ( 2 === $deep && is_array( $tok ) && T_CONSTANT_ENCAPSED_STRING === $tok[0] ) {
			$j = $i + 1;
			while ( isset( $t[ $j ] ) && is_array( $t[ $j ] ) && T_WHITESPACE === $t[ $j ][0] ) {
				++$j;
			}
			if ( isset( $t[ $j ] ) && is_array( $t[ $j ] ) && T_DOUBLE_ARROW === $t[ $j ][0] ) {
				$keys[] = trim( $tok[1], '\'"' );
			}
		}
	}
	sort( $keys );

	return $keys;
};
$expect( 'it registers the locations a DX theme has, so a header installed on either theme lands in the same places', array( 'footer', 'primary-navigation', 'terms-and-conditions' ) === $menu_keys( $code['functions.php'] ), wp_json_encode( $menu_keys( $code['functions.php'] ) ) );
$expect( 'its header.php and footer.php draw the site\'s header and footer, and every template goes through them', str_contains( $code['header.php'], 'dx_base_header()' ) && str_contains( $code['footer.php'], 'dx_base_footer()' ) && 4 === count( array_filter( array( 'index.php', 'page.php', 'single.php', '404.php' ), static fn( $n ) => str_contains( $code[ $n ], 'get_header()' ) && str_contains( $code[ $n ], 'get_footer()' ) ) ) );
$expect( 'the plugin draws them when it is there, and the theme draws a plain header and footer from the same menu and widgets when it is not', str_contains( $code['functions.php'], "class_exists( '\\DXAI_UI\\Chrome\\Template_Chrome' )" ) && str_contains( $code['functions.php'], 'wp_nav_menu(' ) && str_contains( $code['functions.php'], 'dynamic_sidebar(' ) );

echo "\nCopying it\n";
$up  = wp_upload_dir();
$tmp = trailingslashit( (string) $up['basedir'] ) . 'dx-base-suite-' . wp_generate_password( 8, false );
wp_mkdir_p( $tmp );
$want = $files( $src );
$expect( 'a folder that can be written gets the theme: every file, byte for byte', Base_Theme::copy_to( $tmp ) && $files( $tmp . '/dx-base' ) === $want, (string) count( $files( $tmp . '/dx-base' ) ) . ' of ' . count( $want ) );
$expect( '…and copying again is the same', Base_Theme::copy_to( $tmp ) && $files( $tmp . '/dx-base' ) === $want );
file_put_contents( $tmp . '/dx-base/style.css', "/*\nTheme Name: DX Base\nText Domain: dx-base\nVersion: 0.0.1\n*/\n" );
unlink( $tmp . '/dx-base/404.php' );
file_put_contents( $tmp . '/dx-base/mine.txt', 'a file of the person' );
$grown = $want + array( 'mine.txt' => md5( 'a file of the person' ) );
ksort( $grown );
$expect( 'an older copy is brought up to date', Base_Theme::copy_to( $tmp ) && $files( $tmp . '/dx-base' ) === $grown );
$expect( '…and what the person added to the folder stays: the copy deletes nothing', is_readable( $tmp . '/dx-base/mine.txt' ) );
$expect( 'a folder that is not there cannot be written: nothing is made, and it says so', false === Base_Theme::copy_to( $tmp . '/no-such-folder' ) && ! file_exists( $tmp . '/no-such-folder' ) );
// A folder called dx-base that holds somebody else's theme is theirs: nothing is copied into it.
wp_mkdir_p( $tmp . '/other/dx-base' );
file_put_contents( $tmp . '/other/dx-base/style.css', "/*\nTheme Name: Somebody's Theme\nText Domain: theirs\n*/\n" );
file_put_contents( $tmp . '/other/dx-base/functions.php', '<?php // theirs' );
$expect( 'a folder named dx-base that holds another theme is left as it is: nothing is copied into it', false === Base_Theme::copy_to( $tmp . '/other' ) && '<?php // theirs' === file_get_contents( $tmp . '/other/dx-base/functions.php' ) && ! file_exists( $tmp . '/other/dx-base/header.php' ) );
wp_mkdir_p( $tmp . '/empty/dx-base' );
$expect( '…an empty one is filled', Base_Theme::copy_to( $tmp . '/empty' ) && is_readable( $tmp . '/empty/dx-base/style.css' ) );
$expect( 'what is DX Base is told by its own style sheet header: the copy is, the other theme is not, and a folder with nothing in it is not', Base_Theme::is_ours( $tmp . '/dx-base' ) && ! Base_Theme::is_ours( $tmp . '/other/dx-base' ) && ! Base_Theme::is_ours( $tmp . '/no-such-folder/dx-base' ) );
$expect( 'the files are copied in an order that survives a stop: style.css first, then a folder before what is in it', array( 'style.css', 'a.php', 'assets', 'assets/x.css', 'b.php' ) === Base_Theme::ordered( array( 'a.php', 'assets/x.css', 'style.css', 'assets', 'b.php' ) ) );
// A copy that stops halfway (a folder where a file must go): it says so, what it copied is DX Base's, and the next copy finishes it.
wp_mkdir_p( $tmp . '/half/dx-base/theme.json' );
file_put_contents( $tmp . '/half/dx-base/style.css', "/*\nTheme Name: DX Base\nText Domain: dx-base\nVersion: 0.0.1\n*/\n" );
$expect( 'a copy that cannot be finished says so, and what it copied before is there', false === Base_Theme::copy_to( $tmp . '/half' ) && is_readable( $tmp . '/half/dx-base/functions.php' ) && is_readable( $tmp . '/half/dx-base/assets/dx-base.css' ) );
add_filter( 'theme_root', $half_root = static fn() => $tmp . '/half' );
$mode_was = get_option( Base_Theme::OPTION );
$r        = Base_Theme::install();
remove_filter( 'theme_root', $half_root );
$expect( 'install() says it when the copy stops in a folder that can be written (and does not list the theme beside the half copy)', is_wp_error( $r ) && 'dxai_ui_base_theme_copy' === $r->get_error_code() && get_option( Base_Theme::OPTION ) === $mode_was, is_wp_error( $r ) ? $r->get_error_code() : wp_json_encode( $r ) );
rmdir( $tmp . '/half/dx-base/theme.json' );
$expect( '…and once what was in the way is gone the next copy is whole', Base_Theme::copy_to( $tmp . '/half' ) && $files( $tmp . '/half/dx-base' ) === $want );

echo "\nMaking it available\n";
global $wp_theme_directories;
$dirs   = (array) $wp_theme_directories;
$was    = get_option( Base_Theme::OPTION );
$normal = static fn( array $list ): array => array_map( 'wp_normalize_path', array_map( 'untrailingslashit', $list ) );
$before = (string) get_stylesheet();
$r      = Base_Theme::install();
$expect( 'install() copies it into the themes folder', is_array( $r ) && 'copied' === $r['mode'] && $r['version'] === $version && is_readable( trailingslashit( get_theme_root() ) . 'dx-base/style.css' ), wp_json_encode( $r ) );
$expect( '…and the copy is the plugin\'s theme, byte for byte', array_intersect_key( $files( trailingslashit( get_theme_root() ) . 'dx-base' ), $want ) === $want );
$expect( '…and it does not switch the site to it: that is the person\'s choice', (string) get_stylesheet() === $before );
$rec = get_option( Base_Theme::OPTION );
$expect( 'what was done is recorded: how, and from which version', is_array( $rec ) && 'copied' === ( $rec['mode'] ?? '' ) && ( $rec['version'] ?? '' ) === $version );
$s = Base_Theme::status();
$expect( 'status() says it is installed, which version, how, and whether the themes folder can be written', true === $s['installed'] && $s['version'] === $version && $s['bundled'] === $version && 'copied' === $s['mode'] && true === $s['writable'] && false === $s['needs_plugin'], wp_json_encode( $s ) );
$expect( '…and that the theme is not the site\'s while another is', ( $s['active'] === ( 'dx-base' === $before ) ) );
file_put_contents( trailingslashit( get_theme_root() ) . 'dx-base/mine.txt', 'a file of the person' );
$again = Base_Theme::install();
$expect( 'installing again is the same, and a file the person put in the theme\'s folder is still there', is_array( $again ) && 'copied' === $again['mode'] && is_readable( trailingslashit( get_theme_root() ) . 'dx-base/mine.txt' ) );
unlink( trailingslashit( get_theme_root() ) . 'dx-base/mine.txt' );

// Where the themes folder cannot be written, the theme stays in the plugin and is listed from there.
add_filter( 'theme_root', $none = static fn() => $tmp . '/no-such-folder' );
$r = Base_Theme::install();
remove_filter( 'theme_root', $none );
$rec = get_option( Base_Theme::OPTION );
$expect( 'where the themes folder cannot be written it is listed from the plugin\'s own folder instead', is_array( $r ) && 'registered' === $r['mode'] && wp_normalize_path( $r['path'] ) === wp_normalize_path( $src ), wp_json_encode( $r ) );
$expect( '…and that is recorded, because the theme then needs the plugin', is_array( $rec ) && 'registered' === ( $rec['mode'] ?? '' ) && true === Base_Theme::status()['needs_plugin'] );
$wp_theme_directories = $dirs;
( new Base_Theme() )->register();
$expect( 'on every request after it the plugin\'s theme folder is registered, before the themes are looked up', in_array( wp_normalize_path( untrailingslashit( Base_Theme::directory() ) ), $normal( (array) $wp_theme_directories ), true ) );
$wp_theme_directories = $dirs;
update_option( Base_Theme::OPTION, array( 'mode' => 'copied', 'version' => $version, 'installed' => time() ), false );
( new Base_Theme() )->register();
$expect( '…and not when it was copied', ! in_array( wp_normalize_path( untrailingslashit( Base_Theme::directory() ) ), $normal( (array) $wp_theme_directories ), true ) );
$wp_theme_directories = $dirs;
Base_Theme::install();
$expect( 'the copy is put back as the record: copied', 'copied' === ( get_option( Base_Theme::OPTION )['mode'] ?? '' ) );
$both = (string) file_get_contents( DXAI_UI_DIR . 'src/Theme/Base_Theme.php' ) . (string) file_get_contents( DXAI_UI_DIR . 'src/Support/Base_Theme_Cli.php' );
$expect( 'nothing in it deletes: no file, no folder, no theme', 0 === preg_match( '/\b(unlink|rmdir|wp_delete_file|delete_theme|remove_dir|rrmdir|wp_delete_post|wp_delete_term)\s*\(/', $both ) );
$expect( '…and the site is switched to it in one place only: activate(), which the person asks for', 1 === substr_count( (string) file_get_contents( DXAI_UI_DIR . 'src/Theme/Base_Theme.php' ), 'switch_theme(' ) );
// Where the themes folder already holds another theme named dx-base, install() says so and touches nothing.
add_filter( 'theme_root', $other_root = static fn() => $tmp . '/other' );
$r = Base_Theme::install();
remove_filter( 'theme_root', $other_root );
$expect( 'install() into a folder that has another theme named dx-base refuses and says why, and changes nothing there', is_wp_error( $r ) && 'dxai_ui_base_theme_taken' === $r->get_error_code() && '<?php // theirs' === file_get_contents( $tmp . '/other/dx-base/functions.php' ) && 'copied' === ( get_option( Base_Theme::OPTION )['mode'] ?? '' ), is_wp_error( $r ) ? $r->get_error_code() : wp_json_encode( $r ) );
$expect( 'status() knows the theme of this site is DX Base, not somebody\'s', false === Base_Theme::status()['foreign'] );

echo "\nWhere the site does not let plugins change its files\n";
// DISALLOW_FILE_MODS: the theme is not copied, it is listed from the plugin's folder, and nothing is written.
add_filter( 'file_mod_allowed', '__return_false', 99 );
$expect( 'the themes folder is taken for one that cannot be written, whatever the filesystem says', false === Base_Theme::status()['writable'] && false === Base_Theme::copy_to( $tmp ) );
$r = Base_Theme::install();
$expect( 'install() lists it from the plugin instead of copying it', is_array( $r ) && 'registered' === $r['mode'], wp_json_encode( $r ) );
remove_filter( 'file_mod_allowed', '__return_false', 99 );
$wp_theme_directories = $dirs;
Base_Theme::install();
$expect( '…and when files may be changed again the next install copies it', 'copied' === ( get_option( Base_Theme::OPTION )['mode'] ?? '' ) );

echo "\nThe route of the Library screen (Library > Template)\n";
$ns   = DXAI_UI_REST_NAMESPACE;
$rest = static function ( string $method, array $params = array() ) use ( $ns ): array {
	$request = new WP_REST_Request( $method, '/' . $ns . '/template' );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$response = rest_do_request( $request );

	return array( 'status' => (int) $response->get_status(), 'data' => (array) $response->get_data() );
};
$here = $rest( 'GET' );
$d    = $here['data'];
$expect( 'it answers what the screen shows: the theme, DX Base, the header, the footer, the fonts, the logo and the links', 200 === $here['status'] && isset( $d['theme']['name'], $d['theme']['slug'], $d['base']['installed'], $d['base']['version'], $d['links']['menus'], $d['links']['widgets'], $d['links']['logo'] ) && array_key_exists( 'header', $d ) && array_key_exists( 'footer', $d ) && array_key_exists( 'fonts', $d ) && array_key_exists( 'logo', $d ), wp_json_encode( array_keys( $d ) ) );
$expect( '…the theme is the site\'s, and whether it is a DX theme', ( $d['theme']['slug'] ?? '' ) === (string) get_stylesheet() && ( $d['theme']['dx'] ?? null ) === \DXAI_UI\Theme\Theme_Compat::is_dx_theme() && false === ( $d['theme']['base'] ?? null ) );
$expect( '…DX Base is installed at the version the plugin carries, with nothing to update, and this person may install it and switch to it', true === ( $d['base']['installed'] ?? null ) && ( $d['base']['version'] ?? '' ) === $version && ( $d['base']['bundled'] ?? '' ) === $version && false === ( $d['base']['update'] ?? null ) && true === ( $d['base']['can_install'] ?? null ) && true === ( $d['base']['can_activate'] ?? null ) );
$site_h = null !== Header_Template::stored();
$site_f = Footer_Template::get() !== array();
$expect( '…the header and the footer are the design\'s the site shows now, or nothing', ( $site_h ? '' !== (string) ( $d['header']['title'] ?? '' ) : null === $d['header'] ) && ( $site_f ? '' !== (string) ( $d['footer']['title'] ?? '' ) : null === $d['footer'] ) );
$expect( '…the links go to Menus, to Widgets and to where the logo is changed', str_contains( (string) ( $d['links']['menus'] ?? '' ), 'nav-menus.php' ) && str_contains( (string) ( $d['links']['widgets'] ?? '' ), 'widgets.php' ) && '' !== (string) ( $d['links']['logo'] ?? '' ) );
$expect( 'an action nobody knows is refused before anything is done (400)', 400 === $rest( 'POST', array( 'action' => 'bogus' ) )['status'] );

$no_install = static fn( array $caps, string $cap ): array => 'install_themes' === $cap ? array( 'do_not_allow' ) : $caps;
$mode_was   = get_option( Base_Theme::OPTION );
add_filter( 'map_meta_cap', $no_install, 10, 2 );
$refused = $rest( 'POST', array( 'action' => 'base-install' ) );
$told    = $rest( 'GET' );
remove_filter( 'map_meta_cap', $no_install, 10 );
$expect( 'a person who may not install themes is refused (403) and nothing is changed', 403 === $refused['status'] && 'dxai_ui_cannot_install_themes' === ( $refused['data']['code'] ?? '' ) && get_option( Base_Theme::OPTION ) === $mode_was, wp_json_encode( array( $refused['status'], $refused['data']['code'] ?? '' ) ) );
$expect( '…and the screen is told so, with the reason, before the button is pressed', false === ( $told['data']['base']['can_install'] ?? null ) && '' !== (string) ( $told['data']['base']['install_note'] ?? '' ) );
$no_switch = static fn( array $caps, string $cap ): array => 'switch_themes' === $cap ? array( 'do_not_allow' ) : $caps;
add_filter( 'map_meta_cap', $no_switch, 10, 2 );
$refused = $rest( 'POST', array( 'action' => 'base-activate' ) );
remove_filter( 'map_meta_cap', $no_switch, 10 );
$expect( 'a person who may not switch themes is refused (403) and the site\'s theme stays', 403 === $refused['status'] && 'dxai_ui_cannot_switch_themes' === ( $refused['data']['code'] ?? '' ) && (string) get_stylesheet() === $before, wp_json_encode( array( $refused['status'], $refused['data']['code'] ?? '' ) ) );
add_filter( 'file_mod_allowed', '__return_false', 99 );
$listed = $rest( 'POST', array( 'action' => 'base-install' ) );
$told   = $rest( 'GET' );
remove_filter( 'file_mod_allowed', '__return_false', 99 );
$wp_theme_directories = $dirs;
$expect( 'where the site does not let plugins change its files the route lists it from the plugin: nothing is written, so switching themes is the right it asks for', 200 === $listed['status'] && 'registered' === ( $listed['data']['done']['mode'] ?? '' ) && true === ( $told['data']['base']['can_install'] ?? null ) && false === ( $told['data']['base']['writable'] ?? null ), wp_json_encode( array( $listed['status'], $listed['data']['done'] ?? null ) ) );
$copied = $rest( 'POST', array( 'action' => 'base-install' ) );
$expect( 'base-install copies it when files may be changed, says how, and does not switch the site', 200 === $copied['status'] && 'copied' === ( $copied['data']['done']['mode'] ?? '' ) && true === ( $copied['data']['base']['installed'] ?? null ) && (string) get_stylesheet() === $before, wp_json_encode( $copied['data']['done'] ?? null ) );
// An older copy: the screen offers the update, and installing brings the copy up to date.
$installed_css = trailingslashit( get_theme_root() ) . 'dx-base/style.css';
$good          = (string) file_get_contents( $installed_css );
file_put_contents( $installed_css, str_replace( 'Version: ' . $version, 'Version: 0.0.1', $good ) );
wp_clean_themes_cache();
$old = $rest( 'GET' );
$expect( 'an older copy of the theme is offered the update: the site has 0.0.1, the plugin carries ' . $version, true === ( $old['data']['base']['update'] ?? null ) && '0.0.1' === ( $old['data']['base']['version'] ?? '' ), wp_json_encode( $old['data']['base'] ?? null ) );
$new = $rest( 'POST', array( 'action' => 'base-install' ) );
$expect( '…and installing brings it up to date, the file as the plugin carries it', false === ( $new['data']['base']['update'] ?? null ) && $version === ( $new['data']['base']['version'] ?? '' ) && $good === (string) file_get_contents( $installed_css ) );

echo "\nThe structure\n";
$locations = \DXAI_UI\Chrome\Header_Menus::locations();
$expect( 'the header locations the plugin writes into are the ones a DX theme names', isset( $locations['primary-navigation'], $locations['header-top'], $locations['header-actions'] ), wp_json_encode( array_keys( $locations ) ) );
$expect( 'it is a DX theme: an import installs the design\'s header and footer by itself, and keeps what the site already has there', in_array( 'dx-base', \DXAI_UI\Theme\Theme_Compat::dx_themes(), true ) );
$expect( 'what the import screen is told of it: nothing on a DX theme, or when the plugin carries none; installed or available on any other', '' === Base_Theme::offer_for( true, true, true ) && '' === Base_Theme::offer_for( true, true, false ) && '' === Base_Theme::offer_for( false, false, false ) && 'available' === Base_Theme::offer_for( false, true, false ) && 'installed' === Base_Theme::offer_for( false, true, true ) );
$info = \DXAI_UI\API\Converter_Controller::import_info_payload();
$expect( '…import-info carries it: whether the theme is a DX theme, and the offer', ( $info['dx_theme'] ?? null ) === \DXAI_UI\Theme\Theme_Compat::is_dx_theme() && ( $info['base_theme'] ?? null ) === Base_Theme::offer(), wp_json_encode( array( $info['dx_theme'] ?? null, $info['base_theme'] ?? null ) ) );
add_filter( 'dxai_ui_dx_themes', $other_dx = static fn() => array( 'some-other-theme' ), 99 );
$info = \DXAI_UI\API\Converter_Controller::import_info_payload();
remove_filter( 'dxai_ui_dx_themes', $other_dx, 99 );
$expect( '…on a theme that is no DX theme, with DX Base installed, it says to switch to it', false === ( $info['dx_theme'] ?? null ) && 'installed' === ( $info['base_theme'] ?? null ), wp_json_encode( array( $info['dx_theme'] ?? null, $info['base_theme'] ?? null ) ) );

// Fixtures: a page that is not a design's and a post, with the site's header and footer as they are.
$plain = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'DX Base suite page', 'post_content' => '<!-- wp:paragraph --><p>Plain page text.</p><!-- /wp:paragraph -->' ) );
$post  = (int) wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'DX Base suite post', 'post_content' => '<!-- wp:paragraph --><p>Post text.</p><!-- /wp:paragraph -->' ) );
$made  = array( $plain, $post );
$spec  = Header_Template::stored();
$scope = null === $spec ? 0 : Header_Template::scope_of( $spec );
$has_h = null !== $spec;
$has_f = Footer_Template::get() !== array();
$lead  = $scope > 0 ? (string) get_post_meta( $scope, \DXAI_UI\Chrome\Template_Chrome::LEAD_META, true ) : '';
echo '  (this site: ' . ( $has_h ? 'a header is installed, from "' . get_the_title( $scope ) . '"' : 'no header installed' ) . '; ' . ( $has_f ? 'a footer is installed' : 'no footer installed' ) . '; ' . ( $lead !== '' ? 'the design kept its opening blocks' : 'no opening blocks kept' ) . ")\n";

$get = static function ( string $url ): array {
	$res = wp_remote_get( $url, array( 'timeout' => 90, 'sslverify' => false, 'redirection' => 0, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
	if ( is_wp_error( $res ) ) {
		return array( 'code' => 0, 'body' => '', 'error' => $res->get_error_message() );
	}

	return array( 'code' => (int) wp_remote_retrieve_response_code( $res ), 'body' => (string) wp_remote_retrieve_body( $res ), 'error' => '' );
};
$at = static fn( string $html, string $needle ) => strpos( $html, $needle );
// The design's own page, as the DX template draws it, on the theme the site has now: what it must be on DX Base as well.
$design_url  = $scope > 0 ? (string) get_permalink( $scope ) : '';
$design_was  = $design_url !== '' ? $get( $design_url ) : array( 'code' => 0, 'body' => '', 'error' => 'no design' );
// What a page is made of: its elements in order and its words. The pictures are left out of it: the theme the site has draws the images of
// pages imported on it with its own picture block (a dynamic block the theme registers), which another theme does not have.
$shape_of    = static function ( string $html ): string {
	$doc = new \DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
	libxml_clear_errors();
	$root = $doc->getElementById( 'dxai-root' );
	if ( ! $root instanceof \DOMElement ) {
		return '';
	}
	$tags = array();
	foreach ( $root->getElementsByTagName( '*' ) as $el ) {
		if ( ! in_array( $el->nodeName, array( 'figure', 'picture', 'source', 'img' ), true ) ) {
			$tags[] = $el->nodeName;
		}
	}
	// The words are the text nodes that hold some, one by one: the white space between elements is not part of them.
	$words = array();
	foreach ( ( new \DOMXPath( $doc ) )->query( './/text()', $root ) as $node ) {
		$text = trim( (string) preg_replace( '/\s+/', ' ', (string) $node->nodeValue ) );
		if ( $text !== '' ) {
			$words[] = $text;
		}
	}

	return count( $tags ) . ' elements ' . md5( implode( ' ', $tags ) ) . ', ' . count( $words ) . ' words ' . md5( implode( "\n", $words ) );
};

echo "\nWith the theme active\n";
$menus_before = (array) get_nav_menu_locations();
// The site is switched the way the screen does it: by asking the route, which is the one thing that switches it.
$switched = 'dx-base' !== (string) get_stylesheet() ? $rest( 'POST', array( 'action' => 'base-activate' ) ) : array( 'status' => 200, 'data' => array( 'theme' => array( 'base' => true ) ) );
$expect( 'the route switches the site to it when asked, and says it is DX Base now', 200 === $switched['status'] && 'dx-base' === (string) get_stylesheet() && true === ( $switched['data']['theme']['base'] ?? null ), (string) get_stylesheet() . ' ' . $switched['status'] );
$menus_after = (array) get_nav_menu_locations();
$expect( '…and the menus of the site are where they were: the same locations hold the same menus', array_intersect_key( $menus_after, $menus_before ) === $menus_before, wp_json_encode( $menus_before ) . ' vs ' . wp_json_encode( $menus_after ) );
$page_url = (string) get_permalink( $plain );
$r        = $get( $page_url );
$html     = $r['body'];
$expect( 'a page of no design is drawn: 200, in this theme', 200 === $r['code'] && str_contains( $html, 'wp-theme-dx-base' ), $r['code'] . ' ' . $r['error'] );
$expect( '…with its title as the heading and its text, between a header and a footer', str_contains( $html, '<h1 class="dx-base-title">DX Base suite page</h1>' ) && str_contains( $html, 'Plain page text.' ) && ( ! $has_h || false !== $at( $html, '<header' ) ) && ( ! $has_f || false !== $at( $html, '<footer' ) ) );
$main_at = (int) $at( $html, '<main id="main"' );
$expect( '…the header before the content and the footer after it', $main_at > 0 && ( ! $has_h || (int) $at( $html, '<header' ) < $main_at ) && ( ! $has_f || (int) strrpos( $html, '<footer' ) > (int) strpos( $html, '</main>' ) ), (string) $main_at );
if ( $has_h ) {
	$expect( '…the design\'s header is the one drawn, in the scope element of the design it came from, once', 1 === substr_count( $html, '<header' ) && str_contains( $html, 'dxai-ui--' . $scope ), (string) substr_count( $html, '<header' ) );
}
if ( $has_h && $lead !== '' ) {
	$space = (int) $at( $html, 'dxai-dc-1' );
	$expect( '…and under a fixed header the title is not hidden: the design\'s spacer is drawn before the content, and its skip link once', false !== $space && $space < $main_at && 1 === substr_count( $html, 'Skip to content' ), (string) $space );
	$expect( '…and the skip link is hidden by the design\'s own rule', str_contains( $html, 'left:-9999px' ) );
}
$expect( 'no warning, notice or fatal error is printed into the page', 0 === preg_match( '/(Warning|Notice|Fatal error|Deprecated|Parse error):/', $html ) );

$r2 = $get( (string) get_permalink( $post ) );
$expect( 'a post is drawn the same way: 200, its title as the heading, its text, the header and the footer', 200 === $r2['code'] && str_contains( $r2['body'], '>DX Base suite post</h1>' ) && str_contains( $r2['body'], 'Post text.' ) && ( ! $has_h || false !== $at( $r2['body'], '<header' ) ) && ( ! $has_f || false !== $at( $r2['body'], '<footer' ) ), $r2['code'] . ' ' . $r2['error'] );
$r3 = $get( (string) get_category_link( (int) get_option( 'default_category' ) ) );
$expect( 'an archive lists it, in the same header and footer', 200 === $r3['code'] && str_contains( $r3['body'], 'DX Base suite post' ) && ( ! $has_h || false !== $at( $r3['body'], '<header' ) ), $r3['code'] . ' ' . $r3['error'] );
$r4 = $get( home_url( '/?s=' . rawurlencode( 'suite' ) ) );
$expect( 'the search is drawn in them too, and finds the page', 200 === $r4['code'] && ( ! $has_h || false !== $at( $r4['body'], '<header' ) ) && str_contains( $r4['body'], 'DX Base suite' ), $r4['code'] . ' ' . $r4['error'] );
$r5 = $get( home_url( '/?p=99999999' ) );
$expect( 'a page that is not there is a 404 in the same header and footer', 404 === $r5['code'] && str_contains( $r5['body'], 'This page is not here' ) && ( ! $has_h || false !== $at( $r5['body'], '<header' ) ) && ( ! $has_f || false !== $at( $r5['body'], '<footer' ) ), $r5['code'] . ' ' . $r5['error'] );

if ( $design_url !== '' ) {
	$now = $get( $design_url );
	$was = $shape_of( $design_was['body'] );
	$is  = $shape_of( $now['body'] );
	$expect( 'the design\'s own page is drawn by the plugin, not by this theme: 200, its own document, no theme wrapper, none of the theme\'s style sheet', 200 === $now['code'] && ! str_contains( $now['body'], 'dx-base-main' ) && ! str_contains( $now['body'], 'dx-base.css' ) && ! str_contains( $now['body'], 'dx-base-css' ) && 1 === substr_count( $now['body'], '<main id="dxai-root"' ), $now['code'] . ' ' . $now['error'] );
	$expect( '…and it is made of the same elements and the same words as on the theme the site had (the pictures aside)', $was !== '' && $was === $is, $was . ' vs ' . $is );
}

echo "\nWithout the plugin's header and footer\n";
// The theme's own fallback, drawn when the plugin has no header or footer to give: the same menu and the same widgets, in a plain frame.
add_filter( 'pre_option_' . Header_Template::OPTION, static fn() => array(), 99 );
add_filter( 'pre_option_' . Footer_Template::OPTION, static fn() => array(), 99 );
\DXAI_UI\Chrome\Site_Header_Block::forget();
$menu = (int) wp_create_nav_menu( 'DX Base suite menu ' . wp_generate_password( 6, false ) );
wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'Suite item', 'menu-item-url' => home_url( '/suite-item/' ), 'menu-item-status' => 'publish' ) );
add_filter( 'theme_mod_nav_menu_locations', $loc = static fn( $v ) => array_merge( (array) $v, array( 'primary-navigation' => $menu ) ), 99 );
// The theme's functions are loaded here for the first time (the process started on another theme), unless the site started on DX Base.
$loaded_here = ! function_exists( 'dx_base_header' );
if ( $loaded_here ) {
	require_once $src . '/functions.php';
}
ob_start();
dx_base_header();
$plain_header = (string) ob_get_clean();
ob_start();
dx_base_footer();
$plain_footer = (string) ob_get_clean();
remove_filter( 'theme_mod_nav_menu_locations', $loc, 99 );
$expect( 'with no header installed it draws a plain one: the site\'s name and the menu of Primary Navigation', str_contains( $plain_header, 'class="dx-base-header"' ) && str_contains( $plain_header, 'Suite item' ) && str_contains( $plain_header, esc_url( home_url( '/' ) ) ), substr( $plain_header, 0, 200 ) );
$expect( 'with no footer installed it draws a plain one from the widgets of the footer areas, when there are any', ! is_active_sidebar( 'footer-column-1' ) && ! is_active_sidebar( 'footer-column-2' ) && ! is_active_sidebar( 'footer-column-3' ) && ! is_active_sidebar( 'footer-column-4' ) && ! is_active_sidebar( 'footer-copyright' ) ? str_contains( $plain_footer, 'class="dx-base-footer"' ) : ( str_contains( $plain_footer, 'class="dx-base-footer"' ) && str_contains( $plain_footer, 'dx-base-footer__area' ) ), substr( $plain_footer, 0, 200 ) );
remove_all_filters( 'pre_option_' . Header_Template::OPTION, 99 );
remove_all_filters( 'pre_option_' . Footer_Template::OPTION, 99 );
\DXAI_UI\Chrome\Site_Header_Block::forget();
if ( $loaded_here ) {
	// The theme's own filter, added by loading its functions: it is the active theme's only while the theme is active.
	remove_filter( 'dxai_ui_install_chrome', '__return_true' );
}
wp_delete_nav_menu( $menu );

$fin();
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

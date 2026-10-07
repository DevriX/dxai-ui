<?php
/**
 * What an import does with the site's header and footer when nobody says (Chrome_Choice::resolve(), automatic).
 *
 *   bash bin/wp-php.sh bin/verify-chrome-choice.php        (or: wp eval-file bin/verify-chrome-choice.php --user=1)
 *
 * A DX theme draws its header from a menu and its footer from widgets, which is how every DX site is built, so an import there installs
 * the design's by itself when the site has nothing of its own in those places; a site that has its own navigation or footer widgets
 * keeps them (installing would move the widgets to Inactive Widgets on every page) until the person chooses to install. Any other classic
 * theme keeps the site's, as before; a block theme installs, as before.
 *
 * The site is whatever it is (the test sites have a menu and footer widgets, a new one has none), so every state is made with filters and
 * taken away at the end; the only things made are a page, which a footer record points at, and two menus. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Chrome\Chrome_Choice;
use DXAI_UI\Structures\Navigation_Factory;
use DXAI_UI\Theme\Theme_Compat;

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

$was_classic = ! wp_is_block_theme();

// The page a footer record names (a footer is some design's while that design's page is there), and the menus a header location can hold.
// Named for this run, so one that was cut short cannot make the next one's menus clash (a menu name must be new), and taken away when the
// script ends, however it ends. A fixture that could not be made stops the run: a failed call is never read as an id.
$run         = substr( md5( microtime( true ) . wp_rand() ), 0, 8 );
$person_name = 'Chrome choice suite: a person\'s menu ' . $run;
$made        = array(
	'page'   => wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Chrome choice fixture design ' . $run ), true ),
	'person' => wp_create_nav_menu( $person_name ),
	'ours'   => wp_create_nav_menu( 'Chrome choice suite: a menu this plugin wrote ' . $run ),
);
$clean       = static function () use ( &$made ): void {
	foreach ( array( 'person', 'ours' ) as $k ) {
		if ( is_int( $made[ $k ] ?? null ) && $made[ $k ] > 0 ) {
			wp_delete_nav_menu( $made[ $k ] );
		}
		$made[ $k ] = 0;
	}
	if ( is_int( $made['page'] ?? null ) && $made['page'] > 0 ) {
		wp_delete_post( $made['page'], true );
	}
	$made['page'] = 0;
};
register_shutdown_function( $clean );
foreach ( $made as $what => $id ) {
	if ( is_wp_error( $id ) ) {
		echo "  FAIL  the fixture '$what' could not be made: " . $id->get_error_message() . "\n";
		$made = array_map( static fn( $v ) => is_int( $v ) ? $v : 0, $made );
		exit( 1 );
	}
}
list( $page, $person, $ours ) = array( (int) $made['page'], (int) $made['person'], (int) $made['ours'] );
update_term_meta( $ours, Navigation_Factory::HEADER_MENU_META, 'primary-navigation' );

// The state the filters answer. dx: the active theme is a DX theme; classic: it draws its own header and footer; menus: location => menu id;
// widgets: area => widget ids; written: the widget ids this plugin wrote; footer: the stored footer record (a design's, or none).
$state = array(
	'dx'      => true,
	'classic' => true,
	'menus'   => array(),
	'widgets' => array(),
	'written' => array(),
	'footer'  => array(),
);
// By reference: an arrow function takes the state as it is when it is made, and the checks change it.
$filters = array(
	array(
		'dxai_ui_dx_themes',
		static function () use ( &$state ) {
			return $state['dx'] ? array( get_stylesheet() ) : array( 'not-' . get_stylesheet() );
		},
	),
	array(
		'dxai_ui_install_chrome',
		static function () use ( &$state ) {
			return ! $state['classic'];
		},
	),
	array(
		'theme_mod_nav_menu_locations',
		static function () use ( &$state ) {
			return $state['menus'];
		},
	),
	array(
		'sidebars_widgets',
		static function () use ( &$state ) {
			return $state['widgets'];
		},
	),
	array(
		'pre_option_dxai_ui_footer_widgets',
		static function () use ( &$state ) {
			return $state['written'];
		},
	),
	array(
		'pre_option_dxai_ui_site_footer',
		static function () use ( &$state ) {
			return $state['footer'];
		},
	),
	// No header is installed, whatever the site has.
	array(
		'pre_option_dxai_ui_site_header',
		static function () {
			return array();
		},
	),
);
foreach ( $filters as $f ) {
	add_filter( $f[0], $f[1], 99 );
}
$reset = static function () use ( &$state ): void {
	$state = array(
		'dx'      => true,
		'classic' => true,
		'menus'   => array(),
		'widgets' => array(),
		'written' => array(),
		'footer'  => array(),
	);
};
$design = array(
	'scope'   => 'site',
	'archive' => 'dx-suite.zip',
	'owner'   => 'dx-suite.zip#home',
	'page_id' => 0,
);
$auto   = static fn( array $over = array() ): array => Chrome_Choice::resolve( '', array_merge( $design, $over ) );
$said   = static fn( array $r ): string => $r['mode'] . ' (' . $r['reason'] . ')';
// A footer that is some design's: the record Footer_Template stores, naming its page.
$footer_of = static fn( int $page_id, string $owner ): array => array(
	'template' => '<footer></footer>',
	'slots'    => array( 'footer-column-1' => array() ),
	'title'    => 'Some design',
	'page_id'  => $page_id,
	'scope'    => $page_id,
	'source'   => 'some-design.zip',
	'owner'    => $owner,
);

echo "A DX theme with nothing of the site's own in Menus and Widgets\n";
$r = $auto();
$expect( 'installs by itself (reason dx)', 'install' === $r['mode'] && 'dx' === $r['reason'], $said( $r ) );
$expect( '…and says it is a DX theme with Menus and Widgets empty', str_contains( $r['message'], 'DX theme' ) && str_contains( $r['message'], 'Menus' ) && str_contains( $r['message'], 'Widgets' ), $r['message'] );
$expect( '…and the theme is still said to draw the site\'s header and footer', true === $r['theme_draws'] );
$expect( 'site_chrome() finds nothing', array() === $r['site_chrome']['menus'] && array() === $r['site_chrome']['widgets'] );
$info = Chrome_Choice::info( 'dx-suite.zip', 'site' );
$expect( 'the import screen is offered install first, and why', 'install' === $info['chrome_default'] && 'dx' === $info['chrome_default_reason'] && true === $info['theme_chrome'], wp_json_encode( $info['chrome_default_reason'] ) );

echo "\nWhat the site has of its own stays\n";
$state['menus'] = array( 'primary-navigation' => $person );
$r              = $auto();
$expect( 'a person\'s menu in a header location: keep (reason dx_busy)', 'keep' === $r['mode'] && 'dx_busy' === $r['reason'], $said( $r ) );
$expect( '…site_chrome() names the menu and the location', ( $r['site_chrome']['menus']['primary-navigation'] ?? '' ) === $person_name && array() === $r['site_chrome']['widgets'], wp_json_encode( $r['site_chrome'] ) );
$expect( '…and the sentence says what is there and how to install anyway', str_contains( $r['message'], 'a person\'s menu' ) && str_contains( $r['message'], 'Choose to install' ), $r['message'] );
$expect( '…a person who asks for install gets it', 'install' === Chrome_Choice::resolve( 'install', $design )['mode'] && 'asked' === Chrome_Choice::resolve( 'install', $design )['reason'] );
$state['menus'] = array( 'header-top' => $person );
$expect( 'a person\'s menu in the top bar location counts too', 'dx_busy' === $auto()['reason'] );
$state['menus'] = array( 'primary-navigation' => $ours );
$r              = $auto();
$expect( 'a menu this plugin wrote is not the site\'s own: install', 'install' === $r['mode'] && 'dx' === $r['reason'], $said( $r ) );
$state['menus'] = array( 'primary-navigation' => 999999 );
$expect( 'a location that names a menu that is gone holds nothing', 'dx' === $auto()['reason'] );
$state['menus'] = array( 'some-other-location' => $person );
$expect( 'a menu in a location the header does not use is left out of it', 'dx' === $auto()['reason'] );

$state['menus']   = array();
$state['widgets'] = array( 'footer-column-1' => array( 'block-900' ) );
$r                = $auto();
$expect( 'a widget in a footer area that the plugin did not write: keep (dx_busy)', 'keep' === $r['mode'] && 'dx_busy' === $r['reason'] && 1 === ( $r['site_chrome']['widgets']['footer-column-1'] ?? 0 ), $said( $r ) . ' ' . wp_json_encode( $r['site_chrome'] ) );
$expect( '…the sentence counts the widgets', str_contains( $r['message'], '1 footer widget' ), $r['message'] );
$state['widgets'] = array( 'footer-copyright' => array( 'text-3', 'block-901' ) );
$expect( 'the copyright area counts, and so do widgets that are not blocks', 'dx_busy' === $auto()['reason'] && 2 === ( $auto()['site_chrome']['widgets']['footer-copyright'] ?? 0 ) );
$state['widgets'] = array( 'footer-column-1' => array( 'block-900' ) );
$state['written'] = array( 'block-900' => array( 'area' => 'footer-column-1', 'md5' => 'x' ) );
$r                = $auto();
$expect( 'a widget the plugin wrote is not the site\'s own: install', 'install' === $r['mode'] && 'dx' === $r['reason'], $said( $r ) );
$state['widgets'] = array( 'footer-column-1' => array( 'block-900', 'search-2' ) );
$expect( '…unless a person put another beside it', 'dx_busy' === $auto()['reason'] );
$state['written'] = array();
$state['widgets'] = array( 'sidebar-1' => array( 'search-2', 'block-5' ), 'wp_inactive_widgets' => array( 'block-6' ) );
$expect( 'widgets outside the footer\'s areas (a sidebar, Inactive Widgets) are not the footer\'s', 'dx' === $auto()['reason'] );

echo "\nThe design that is the site's chrome already\n";
$state['footer']  = $footer_of( $page, 'dx-suite.zip#home' );
$state['widgets'] = array( 'footer-column-1' => array( 'block-900' ), 'footer-copyright' => array( 'text-3' ) );
$state['menus']   = array( 'primary-navigation' => $person );
$r                = $auto( array( 'page_id' => $page ) );
$expect( 'its own re-import installs again, in place, even with widgets and a menu there (reason own)', 'install' === $r['mode'] && 'own' === $r['reason'], $said( $r ) );
$expect( '…by its archive when the page is not known', 'own' === $auto()['reason'] );
$r = $auto( array( 'archive' => 'another.zip', 'owner' => 'another.zip#home', 'page_id' => 0 ) );
$expect( 'another design while this one\'s footer is the site\'s: keep (reason owned), not dx_busy', 'keep' === $r['mode'] && 'owned' === $r['reason'], $said( $r ) );
wp_trash_post( $page );
$r = $auto( array( 'archive' => 'another.zip', 'owner' => 'another.zip#home' ) );
$expect( 'once that design\'s page is gone its footer is nobody\'s: the site\'s own things decide (dx_busy here)', 'dx_busy' === $r['reason'], $said( $r ) );
$state['menus'] = array();
$state['widgets'] = array();
$r = $auto( array( 'archive' => 'another.zip', 'owner' => 'another.zip#home' ) );
$expect( '…and with nothing of the site\'s own, install', 'install' === $r['mode'] && 'dx' === $r['reason'], $said( $r ) );
wp_untrash_post( $page );
wp_update_post( array( 'ID' => $page, 'post_status' => 'publish' ) );

echo "\nThe rest is as it was\n";
$reset();
$state['menus'] = array( 'primary-navigation' => $person );
$expect( 'a one-page import keeps the site\'s (reason one_page)', 'keep' === $auto( array( 'scope' => 'page' ) )['mode'] && 'one_page' === $auto( array( 'scope' => 'page' ) )['reason'] );
$state['menus'] = array();
$expect( '…also when the site has nothing of its own', 'one_page' === $auto( array( 'scope' => 'page' ) )['reason'] );
$expect( 'asked keep is kept, on an empty DX site too', 'keep' === Chrome_Choice::resolve( 'keep', $design )['mode'] && 'asked' === Chrome_Choice::resolve( 'keep', $design )['reason'] );
$state['dx'] = false;
$r           = $auto();
$expect( 'another classic theme keeps the site\'s whatever it has (reason theme)', 'keep' === $r['mode'] && 'theme' === $r['reason'], $said( $r ) );
$expect( '…and does not look at the site\'s menus and widgets', array() === $r['site_chrome']['menus'] && array() === $r['site_chrome']['widgets'] );
$state['dx']      = true;
$state['classic'] = false;
$r                = $auto();
$expect( 'a block theme installs, as before (reason free)', 'install' === $r['mode'] && 'free' === $r['reason'] && false === $r['theme_draws'], $said( $r ) );
$state['menus'] = array( 'primary-navigation' => $person );
$expect( '…even where a menu is assigned (a block theme does not draw it)', 'free' === $auto()['reason'] );
$reset();
add_filter( 'dxai_ui_chrome_mode', $force = static fn() => 'keep', 99 );
$r = $auto();
$expect( 'the filter dxai_ui_chrome_mode still has the last word', 'keep' === $r['mode'] && 'filter' === $r['reason'], $said( $r ) );
remove_filter( 'dxai_ui_chrome_mode', $force, 99 );

echo "\nDX Base, the theme the plugin carries\n";
// It has no header, footer or colours of its own, so it lets a design be its brand (install_chrome true, as its functions.php says), and it
// draws the site's header and footer from the same locations and areas as a DX theme: what a site has there is the person's.
$reset();
$state['classic'] = false;
add_filter( 'stylesheet', $as_base = static fn() => 'dx-base', 99 );
add_filter( 'template', $as_base_parent = static fn() => 'dx-base', 99 );
$r = $auto();
$expect( 'installs by itself when the site has nothing of its own in Menus and Widgets (reason dx)', 'install' === $r['mode'] && 'dx' === $r['reason'], $said( $r ) );
$expect( '…and is said to draw the site\'s header and footer, as a DX theme does', true === $r['theme_draws'] && Theme_Compat::is_base_theme() && Chrome_Choice::theme_draws_chrome() );
$state['menus'] = array( 'primary-navigation' => $person );
$r              = $auto();
$expect( 'a person\'s menu in a header location stays: keep (reason dx_busy)', 'keep' === $r['mode'] && 'dx_busy' === $r['reason'], $said( $r ) );
$state['menus']   = array();
$state['widgets'] = array( 'footer-column-1' => array( 'block-900' ) );
$expect( '…and so does a widget in a footer area', 'dx_busy' === $auto()['reason'] );
$expect( '…a person who asks for install gets it', 'install' === Chrome_Choice::resolve( 'install', $design )['mode'] );
$state['widgets'] = array();
$expect( 'its brand is the design\'s: may_install_chrome() is true', Theme_Compat::may_install_chrome() );
remove_filter( 'stylesheet', $as_base, 99 );
remove_filter( 'template', $as_base_parent, 99 );
$expect( 'and a theme that is not DX Base, with the same filter, is not taken for it: free', ! Theme_Compat::is_base_theme() && ! Chrome_Choice::theme_draws_chrome() && 'free' === $auto()['reason'], $said( $auto() ) );
$reset();

echo "\nWhat a DX theme is\n";
remove_filter( 'dxai_ui_dx_themes', $filters[0][1], 99 );
$expect( 'american-restoration is one', in_array( 'american-restoration', Theme_Compat::dx_themes(), true ) );
$expect( 'DX Base is one: it has the same locations and the same areas', in_array( 'dx-base', Theme_Compat::dx_themes(), true ) );
add_filter( 'dxai_ui_dx_themes', $more = static fn( $t ) => array_merge( (array) $t, array( 'another-dx-theme', '', 7 ) ), 99 );
$expect( 'the filter adds a theme, and a blank is dropped', in_array( 'another-dx-theme', Theme_Compat::dx_themes(), true ) && ! in_array( '', Theme_Compat::dx_themes(), true ) );
remove_filter( 'dxai_ui_dx_themes', $more, 99 );
add_filter( 'dxai_ui_dx_themes', $mine = static fn() => array( get_stylesheet() ), 99 );
$expect( 'the active theme is a DX theme when it is listed', Theme_Compat::is_dx_theme() );
remove_filter( 'dxai_ui_dx_themes', $mine, 99 );
add_filter( 'dxai_ui_dx_themes', $other = static fn() => array( 'not-' . get_stylesheet() ), 99 );
$expect( '…and is not when it is not', ! Theme_Compat::is_dx_theme() );
remove_filter( 'dxai_ui_dx_themes', $other, 99 );
add_filter( 'dxai_ui_dx_themes', $filters[0][1], 99 );
if ( $was_classic ) {
	remove_filter( $filters[1][0], $filters[1][1], 99 );
	$expect( 'a DX theme still does not let an import adopt its colours: may_install_chrome() is false on a classic theme (the colours follow the theme)', ! Theme_Compat::may_install_chrome() );
	add_filter( $filters[1][0], $filters[1][1], 99 );
}

foreach ( $filters as $f ) {
	remove_filter( $f[0], $f[1], 99 );
}
$clean();

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

<?php
/**
 * The admin menu, the screens and a design's status: what WordPress draws and what the app is told.
 *
 *   bash bin/wp-php.sh bin/verify-admin-ui.php --user=1        (or: wp eval-file bin/verify-admin-ui.php --user=1)
 *
 * The menu DX has five screens (Designs, Convert, Site, Form entries, Settings) and no other; the Library address of
 * earlier versions is sent to Designs; every screen mounts the app with its name and the app is told where every screen
 * is; the app's source names no admin address of its own. A design's status (Design_Status) has every key the screens
 * read, says what the design's records say, lists the design's pages and snapshots when asked, suggests at most three
 * things to do, each on a tab the design's page has, and notes an export; GET /design carries it and GET /design/{id}/next
 * answers it. Nothing is left changed: the export note written here is taken off again.
 *
 * Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Admin\Dashboard;
use DXAI_UI\Design\Design_Status;
use DXAI_UI\Structures\Design_Attach;

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

if ( ! current_user_can( 'manage_options' ) ) {
	echo "run it as an administrator: --user=1\n";
	exit( 1 );
}

// --- 1. The menu. -----------------------------------------------------------------------------------------------------
echo "\n1. The menu\n";
if ( ! isset( $GLOBALS['menu'] ) || ! is_array( $GLOBALS['menu'] ) || $GLOBALS['menu'] === array() ) {
	do_action( 'admin_menu' );
}
global $menu, $submenu;
$top = null;
foreach ( (array) $menu as $item ) {
	if ( (string) ( $item[2] ?? '' ) === Dashboard::SLUG ) {
		$top = $item;
	}
}
$expect( 'one top-level menu, DX, at the Designs slug', $top !== null && wp_strip_all_tags( (string) $top[0] ) === 'DX', $top === null ? 'no menu at ' . Dashboard::SLUG : wp_strip_all_tags( (string) $top[0] ) );
$subs  = array();
$order = array();
foreach ( (array) ( $submenu[ Dashboard::SLUG ] ?? array() ) as $sub ) {
	$subs[ (string) $sub[2] ] = wp_strip_all_tags( (string) $sub[0] );
	// As WordPress lists them, every entry: a first screen that is not the menu's own slug makes WordPress put a copy of the menu ("DX") before it.
	$order[] = wp_strip_all_tags( (string) $sub[0] ) . '|' . (string) $sub[2];
}
$want = array( 'dxai-ui' => 'Designs', 'dxai-ui-convert' => 'Convert', 'dxai-ui-site' => 'Site', 'dxai-ui-forms' => 'Form entries', 'dxai-ui-settings' => 'Settings' );
$expect( 'five screens under it, in this order: Designs, Convert, Site, Form entries, Settings', $subs === $want && $order === array_map( static fn( $slug, $label ) => "$label|$slug", array_keys( $want ), $want ), implode( ', ', $order ) );
$expect( 'no Library screen in the menu', ! isset( $subs['dxai-ui-library'] ) && ! in_array( 'Library', $subs, true ) );
$expect( 'the screens the Dashboard knows are the menu’s', array_values( Dashboard::SCREENS ) === array_keys( $want ) );
$options = array();
foreach ( (array) ( $submenu['options-general.php'] ?? array() ) as $sub ) {
	if ( str_contains( (string) $sub[2], 'dxai' ) ) {
		$options[] = (string) $sub[2];
	}
}
$expect( 'Settings › DX UI opens the same Settings screen', $options === array( Dashboard::SLUG_SETTINGS ), wp_json_encode( $options ) );
$expect( 'a screen’s URL is its admin.php address', Dashboard::url( 'site' ) === admin_url( 'admin.php?page=dxai-ui-site' ) && Dashboard::url( 'designs' ) === admin_url( 'admin.php?page=dxai-ui' ) );
$expect( 'a screen nobody has is sent to Designs', Dashboard::url( 'bogus' ) === admin_url( 'admin.php?page=dxai-ui' ) );

// --- 2. The Library address of earlier versions. ----------------------------------------------------------------------
echo "\n2. The old Library address\n";
$dash   = new Dashboard();
$caught = '';
$catch  = static function ( $location ) {
	throw new RuntimeException( (string) $location );
};
// Before WP-CLI's own wp_redirect filter (priority 10), which would warn that a redirect is attempted.
add_filter( 'wp_redirect', $catch, 0 );
$_GET['page'] = Dashboard::SLUG_LIBRARY;
try {
	$dash->forward_library();
} catch ( RuntimeException $e ) {
	$caught = $e->getMessage();
}
$expect( 'admin.php?page=dxai-ui-library is sent to Designs', str_ends_with( $caught, 'admin.php?page=dxai-ui' ), $caught === '' ? 'no redirect' : $caught );
$caught       = '';
$_GET['page'] = Dashboard::SLUG_SITE;
try {
	$dash->forward_library();
} catch ( RuntimeException $e ) {
	$caught = $e->getMessage();
}
$expect( 'another screen is not sent anywhere', $caught === '', $caught );
remove_filter( 'wp_redirect', $catch, 0 );

// --- 3. What each screen renders and tells the app. ---------------------------------------------------------------------
echo "\n3. The screens\n";
foreach ( Dashboard::SCREENS as $name => $slug ) {
	$_GET['page'] = $slug;
	ob_start();
	$dash->render();
	$html = (string) ob_get_clean();
	$expect( "$name: the app’s root, told which screen it is", str_contains( $html, 'id="dxai-ui-app"' ) && str_contains( $html, 'data-screen="' . $name . '"' ) );
	$expect( "$name: a title for a reader without the app", str_contains( $html, '<h1 class="screen-reader-text">' . esc_html( Dashboard::labels()[ $name ] ) . '</h1>' ) );
	$expect( "$name: the fallback links to the five screens and not to the Library", substr_count( $html, 'admin.php?page=dxai-ui' ) === 5 && ! str_contains( $html, 'dxai-ui-library' ) && ! str_contains( $html, 'Library' ) );
}
$_GET['page'] = Dashboard::SLUG;
$dash->assets( 'toplevel_page_dxai-ui' );
$scripts = wp_scripts();
$data    = (string) $scripts->get_data( 'dxai-ui-admin', 'data' );
$expect( 'the app’s script is enqueued on the screen', wp_script_is( 'dxai-ui-admin', 'enqueued' ) );
$expect( 'the app is told the screen', str_contains( $data, '"screen":"designs"' ) );
foreach ( array_keys( Dashboard::SCREENS ) as $name ) {
	$expect( "the app is told where $name is", preg_match( '#"' . $name . '":"[^"]*admin\.php\?page=' . preg_quote( Dashboard::SCREENS[ $name ], '#' ) . '"#', $data ) === 1 );
}
$expect( 'the app is told the screens’ names', str_contains( $data, '"labels":{' ) && str_contains( $data, '"designs":"Designs"' ) );
$expect( 'the app is told the theme', preg_match( '#"theme":"[^"]+"#', $data ) === 1 );
$expect( 'the plugin’s row links go to Designs and Settings', array_slice( apply_filters( 'plugin_action_links_' . plugin_basename( DXAI_UI_FILE ), array() ), 0, 2 ) === array( '<a href="' . esc_url( Dashboard::url( 'designs' ) ) . '">Designs</a>', '<a href="' . esc_url( Dashboard::url( 'settings' ) ) . '">Settings</a>' ) );

$src = DXAI_UI_DIR . 'assets/src/admin';
if ( is_dir( $src ) ) {
	$spelled = array();
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
		if ( preg_match( '/\.js$/', (string) $f ) === 1 && preg_match_all( '#admin\.php\?page=dxai-ui[a-z-]*#', (string) file_get_contents( (string) $f ), $m ) ) {
			foreach ( array_unique( $m[0] ) as $hit ) {
				$spelled[] = basename( (string) $f ) . ': ' . $hit;
			}
		}
	}
	// ChromeChoice.js links to Site by its address from inside the wizard, as a relative admin.php link WordPress resolves; nothing else may.
	$allowed = array( 'ChromeChoice.js: admin.php?page=dxai-ui-site' );
	$expect( 'the app spells no admin address of its own (it is told them)', array_diff( $spelled, $allowed ) === array(), implode( '; ', array_diff( $spelled, $allowed ) ) );
	$expect( 'the app has no second menu (Chrome.js) and no Library screen', ! file_exists( $src . '/Chrome.js' ) && ! file_exists( $src . '/screens/LibraryScreen.js' ) );
} else {
	echo "  skip  the admin app's source (assets/src/admin) is not here, as in an installed copy\n";
}

// --- 4. A design's status. ---------------------------------------------------------------------------------------------
echo "\n4. A design’s status\n";
$designs = Design_Attach::designs();
$home    = isset( $designs[0] ) ? (int) $designs[0]->ID : 0;
if ( $home < 1 ) {
	echo "  skip  no design on this site: import one to check its status\n";
} else {
	$status = Design_Status::of( $home );
	$expect( 'every key the screens read is there', array_diff( Design_Status::KEYS, array_keys( $status ) ) === array(), implode( ', ', array_diff( Design_Status::KEYS, array_keys( $status ) ) ) );
	$expect( 'the counts alone, until the pages are asked for', ! isset( $status['items'] ) && ! isset( $status['conversions'] ) );
	$expect( 'the design’s pages count its Home', $status['pages'] >= 1 && $status['pages'] >= $status['team_pages'] + $status['site_pages'] );
	$expect( 'imported at is a date', preg_match( '/^\d{4}-\d{2}-\d{2}T/', (string) $status['imported_at'] ) === 1, (string) $status['imported_at'] );
	$expect( 'colours say follow or keep', in_array( $status['colours'], array( 'follow', 'keep' ), true ) );
	$expect( 'fonts say theme, own or none', in_array( $status['fonts'], array( 'theme', 'own', 'none' ), true ) );
	$expect( 'fonts are none exactly when the theme does not manage them', ( $status['fonts'] === 'none' ) === ! $status['fonts_managed'] );
	$expect( 'the status says what the records say', $status['native'] === \DXAI_UI\Blocks\Native\Native_Blocks::applied( $home ) && ( $status['colours'] === 'follow' ) === \DXAI_UI\Theme\Theme_Binding::follows( $home ) && $status['interactivity'] === \DXAI_UI\Blocks\Interactivity::enabled() );
	$expect( 'the Home’s addresses are there', $status['home'] === get_permalink( $home ) && $status['edit'] === get_edit_post_link( $home, 'raw' ) );
	$expect( 'the export link is the admin download', str_contains( (string) $status['export'], 'admin-post.php' ) && str_contains( (string) $status['export'], 'page_id=' . $home ) );

	$whole = Design_Status::of( $home, true );
	$expect( 'asked for, the pages are listed, the Home first and once', isset( $whole['items'][0] ) && (int) $whole['items'][0]['id'] === $home && count( $whole['items'] ) === $whole['pages'] && count( array_unique( array_column( $whole['items'], 'id' ) ) ) === count( $whole['items'] ) );
	$expect( 'every page listed has its kind, status and addresses', array_filter( $whole['items'], static fn( $p ) => ! in_array( $p['kind'], array( 'home', 'team', 'site', 'own' ), true ) || $p['status'] === '' || $p['url'] === '' ) === array() );
	$expect( 'the snapshots are listed, newest first', isset( $whole['conversions'] ) && ( $whole['conversions'] === array() || ( (int) $whole['conversions'][0]['id'] >= (int) end( $whole['conversions'] )['id'] && $whole['conversions'][0]['date'] !== '' ) ) );

	$next = Design_Status::next( $home );
	$expect( 'at most three things to do', count( $next ) <= 3 );
	$tabs = array( 'overview', 'pages', 'look', 'blocks', 'words', 'speed', 'transfer', 'history' );
	$expect( 'each on a tab the design’s page has, with a title and a reason', array_filter( $next, static fn( $n ) => ! in_array( $n['tab'], $tabs, true ) || $n['title'] === '' || $n['why'] === '' || ! in_array( $n['key'], Design_Status::NEXT, true ) ) === array() );
	$expect( 'the suggestions come in their order', array_map( static fn( $n ) => $n['key'], $next ) === array_values( array_intersect( Design_Status::NEXT, array_map( static fn( $n ) => $n['key'], $next ) ) ) );
	$expect( 'export is suggested exactly when nothing was exported', in_array( 'export', array_map( static fn( $n ) => $n['key'], $next ), true ) === ( $status['exported_at'] === '' && count( $next ) < 3 || ( $status['exported_at'] === '' && count( $next ) === 3 && end( $next )['key'] === 'export' ) ) || ( $status['exported_at'] !== '' && ! in_array( 'export', array_map( static fn( $n ) => $n['key'], $next ), true ) ) );
	$expect( 'colours are suggested only where the theme has a palette and the design keeps its own', in_array( 'colours', array_map( static fn( $n ) => $n['key'], $next ), true ) === ( $status['palette'] && $status['colours'] === 'keep' && count( array_filter( $next, static fn( $n ) => in_array( $n['key'], array( 'document', 'native' ), true ) ) ) < 3 ) );

	// An export is noted on the Home, by any page of the design; the note is taken off again.
	$had = get_post_meta( $home, Design_Status::EXPORTED_META, true );
	$any = isset( $whole['items'][1] ) ? (int) $whole['items'][1]['id'] : $home;
	Design_Status::exported( $any );
	$after = Design_Status::of( $home );
	$expect( 'an export is noted on the Home, by any page of the design', (int) get_post_meta( $home, Design_Status::EXPORTED_META, true ) >= time() - 5 && preg_match( '/^\d{4}-\d{2}-\d{2}T/', (string) $after['exported_at'] ) === 1 );
	$expect( 'and export is no longer suggested', ! in_array( 'export', array_map( static fn( $n ) => $n['key'], Design_Status::next( $home ) ), true ) );
	if ( $had === '' || $had === false ) {
		delete_post_meta( $home, Design_Status::EXPORTED_META );
	} else {
		update_post_meta( $home, Design_Status::EXPORTED_META, $had );
	}
	$expect( 'the site is as it was', get_post_meta( $home, Design_Status::EXPORTED_META, true ) === $had );

	// --- 5. The routes. -------------------------------------------------------------------------------------------------
	echo "\n5. The routes\n";
	$get = static function ( string $route ): array {
		$res = rest_do_request( new WP_REST_Request( 'GET', '/' . DXAI_UI_REST_NAMESPACE . $route ) );

		return array( 'status' => $res->get_status(), 'data' => is_array( $res->get_data() ) ? $res->get_data() : array() );
	};
	$list = $get( '/design' );
	$row  = null;
	foreach ( (array) ( $list['data']['designs'] ?? array() ) as $r ) {
		if ( (int) ( $r['id'] ?? 0 ) === $home ) {
			$row = $r;
		}
	}
	$expect( 'GET /design lists the design with its status', $list['status'] === 200 && $row !== null && isset( $row['status'] ) && array_diff( Design_Status::KEYS, array_keys( (array) $row['status'] ) ) === array() );
	$expect( 'the list carries the counts, not the pages', $row !== null && ! isset( $row['status']['items'] ) );
	$one = $get( '/design/' . $home . '/next' );
	$expect( 'GET /design/{id}/next answers the suggestions and the whole status', $one['status'] === 200 && isset( $one['data']['next'] ) && is_array( $one['data']['next'] ) && isset( $one['data']['status']['items'] ) && (int) $one['data']['design'] === $home );
	$page = isset( $whole['items'][1] ) ? (int) $whole['items'][1]['id'] : 0;
	if ( $page > 0 ) {
		$by = $get( '/design/' . $page . '/next' );
		$expect( 'by any page of the design, the same design', $by['status'] === 200 && (int) $by['data']['design'] === $home );
	}
	$gone = $get( '/design/999999999/next' );
	$expect( 'a page that is no design’s: 404', $gone['status'] === 404 );
	$show = $get( '/design/' . $home . '?summary=1' );
	$expect( 'GET /design/{id} carries the status too', $show['status'] === 404 || ( $show['status'] === 200 && isset( $show['data']['status']['pages'] ) ) );
	// The design's Blocks tab asks for its own report alone: every design's reads every page of every design (seconds on a site with tens).
	$req    = new WP_REST_Request( 'GET', '/' . DXAI_UI_REST_NAMESPACE . '/native-blocks' );
	$req->set_query_params( array( 'design' => $home ) );
	$native = rest_do_request( $req );
	$rows   = is_array( $native->get_data() ) ? (array) ( $native->get_data()['designs'] ?? array() ) : array();
	$expect( 'GET /native-blocks?design={id} reports that design alone', $native->get_status() === 200 && count( $rows ) === 1 && (int) ( $rows[0]['id'] ?? 0 ) === $home, count( $rows ) . ' designs' );
}

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

<?php
/**
 * Updates from the plugin's GitHub releases (Update\Github_Updater), against releases made up for the purpose: GitHub's API
 * and wordpress.org's update check are answered by `pre_http_request`, nothing is fetched. Checks which release is offered on
 * the stable and the beta channel, what does not count (a draft, a tag that is no version, a release without its ZIP, an older
 * version), an API that fails, the cache, the switch, "View details", and WordPress' own update check through the header's
 * Update URI. The site's update transient is put back as it was.
 *
 *   wp eval-file bin/verify-updater.php --user=1
 *
 * Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Update\Github_Updater;

if ( ! function_exists( 'get_plugin_data' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
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

$file     = plugin_basename( DXAI_UI_FILE );
$was      = get_site_transient( 'update_plugins' );
$releases = array();
$api      = 200;
$calls    = array();
$asset    = static fn( string $v, string $name = '' ): array => array(
	'name'                 => $name !== '' ? $name : 'dxai-ui-' . $v . '.zip',
	'browser_download_url' => 'https://github.com/DevriX/dxai-ui/releases/download/v' . $v . '/' . ( $name !== '' ? $name : 'dxai-ui-' . $v . '.zip' ),
);
$release  = static fn( string $v, array $extra = array() ): array => array_merge(
	array(
		'tag_name'     => 'v' . $v,
		'draft'        => false,
		'prerelease'   => str_contains( $v, '-' ),
		'html_url'     => 'https://github.com/DevriX/dxai-ui/releases/tag/v' . $v,
		'body'         => 'Notes of ' . $v,
		'published_at' => '2026-10-13T10:00:00Z',
		'assets'       => array( $asset( $v ) ),
	),
	$extra
);
$fake = static function ( $pre, $args, $url ) use ( &$releases, &$api, &$calls ) {
	if ( str_contains( (string) $url, 'api.github.com/repos/' ) ) {
		$calls[] = array( 'url' => (string) $url, 'ua' => (string) ( $args['headers']['User-Agent'] ?? '' ) );
		return array(
			'headers'  => array(),
			'body'     => $api === 200 ? wp_json_encode( $releases ) : '{"message":"Server Error"}',
			'response' => array( 'code' => $api, 'message' => $api === 200 ? 'OK' : 'Error' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}
	if ( str_contains( (string) $url, 'api.wordpress.org/plugins/update-check' ) ) {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'plugins' => array(), 'translations' => array(), 'no_update' => array() ) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}
	return $pre;
};
add_filter( 'pre_http_request', $fake, 10, 3 );
// This copy is a development checkout (it has bin/), which is offered nothing: the offer is checked as for an installed ZIP.
$installed = static fn() => false;
$on_checkout = Github_Updater::is_checkout();
add_filter( 'dxai_ui_update_checkout', $installed );
$channel     = '';
$set_channel = static function ( $default ) use ( &$channel ) {
	return $channel !== '' ? $channel : $default;
};
add_filter( 'dxai_ui_update_channel', $set_channel );
// Every case reads the releases afresh unless it is about the cache.
$fresh = static function () use ( &$calls ): void {
	delete_site_transient( Github_Updater::CACHE );
	$calls = array();
};
$finish = static function () use ( $fake, $set_channel, $was, $installed ): void {
	remove_filter( 'pre_http_request', $fake, 10 );
	remove_filter( 'dxai_ui_update_checkout', $installed );
	remove_filter( 'dxai_ui_update_channel', $set_channel );
	delete_site_transient( Github_Updater::CACHE );
	if ( $was === false ) {
		delete_site_transient( 'update_plugins' );
	} else {
		set_site_transient( 'update_plugins', $was );
	}
};
register_shutdown_function( $finish );

echo "Which release is offered\n";
$releases = array( $release( '99.0.0' ) );
$channel  = 'stable';
$fresh();
$up = Github_Updater::update( false, array(), $file );
$expect( 'a newer release with its ZIP is offered: its version, its ZIP, its page', is_array( $up ) && $up['version'] === '99.0.0' && $up['package'] === 'https://github.com/DevriX/dxai-ui/releases/download/v99.0.0/dxai-ui-99.0.0.zip' && $up['slug'] === 'dxai-ui' && str_contains( (string) $up['url'], '/releases/tag/v99.0.0' ), wp_json_encode( $up ) );
$expect( 'GitHub is asked for this repository\'s releases, as DX', count( $calls ) === 1 && str_contains( $calls[0]['url'], 'https://api.github.com/repos/DevriX/dxai-ui/releases' ) && str_starts_with( $calls[0]['ua'], 'DX-UI/' ), wp_json_encode( $calls ) );
$expect( 'another plugin\'s question is left alone', Github_Updater::update( false, array(), 'akismet/akismet.php' ) === false && Github_Updater::update( array( 'version' => '1' ), array(), $file ) === array( 'version' => '1' ) );

$releases = array( $release( '99.1.0-beta.1' ) );
$fresh();
$expect( 'a pre-release is not offered on the stable channel', Github_Updater::latest() === null && Github_Updater::update( false, array(), $file ) === false );
$channel = 'beta';
$fresh();
$expect( 'and is on the beta channel', ( Github_Updater::latest()['version'] ?? '' ) === '99.1.0-beta.1' );

$releases = array( $release( '99.1.0-beta.1' ), $release( '99.0.0' ), $release( '98.0.0' ) );
$fresh();
$expect( 'the newest that counts wins: the beta on the beta channel', ( Github_Updater::latest()['version'] ?? '' ) === '99.1.0-beta.1' );
$channel = 'stable';
$fresh();
$expect( '…the newest release on the stable channel, whatever order GitHub lists them in', ( Github_Updater::latest()['version'] ?? '' ) === '99.0.0' );
$channel = '';
$fresh();
$expect( 'the default channel is the version running\'s: ' . ( str_contains( DXAI_UI_VERSION, '-' ) ? 'beta' : 'stable' ) . ' for ' . DXAI_UI_VERSION, Github_Updater::channel() === ( str_contains( DXAI_UI_VERSION, '-' ) ? 'beta' : 'stable' ) );
$channel = 'stable';

echo "\nWhat does not count\n";
$releases = array( $release( '99.0.0', array( 'draft' => true ) ) );
$fresh();
$expect( 'a draft', Github_Updater::latest() === null );
$releases = array( $release( '99.0.0', array( 'tag_name' => 'nightly' ) ) );
$fresh();
$expect( 'a tag that is not a version', Github_Updater::latest() === null );
$releases = array( $release( '99.0.0', array( 'assets' => array() ) ), $release( '98.0.0', array( 'assets' => array( $asset( '98.0.0', 'dxai-ui.zip' ) ) ) ) );
$fresh();
$expect( 'a release without its ZIP, or with a ZIP of another name', Github_Updater::latest() === null );
$releases = array( $release( '99.0.0', array( 'assets' => array( array( 'name' => 'dxai-ui-99.0.0.zip', 'browser_download_url' => 'http://example.com/dxai-ui-99.0.0.zip' ) ) ) ) );
$fresh();
$expect( 'a ZIP that is not on https', Github_Updater::latest() === null );

echo "\nWhen GitHub does not answer, and the cache\n";
$api = 500;
$fresh();
$expect( 'an API error offers nothing and breaks nothing', Github_Updater::latest() === null && Github_Updater::update( false, array(), $file ) === false );
$cached = get_site_transient( Github_Updater::CACHE );
$expect( 'and is remembered as not read, for an hour', is_array( $cached ) && $cached['read'] === false && count( $calls ) === 1 );
$api      = 200;
$releases = array( $release( '99.0.0' ) );
$fresh();
Github_Updater::latest();
Github_Updater::latest();
Github_Updater::update( false, array(), $file );
$expect( 'the releases are read once, then from the cache', count( $calls ) === 1, count( $calls ) . ' requests' );
$fresh();
$off = static fn() => '';
add_filter( 'dxai_ui_update_repository', $off );
$expect( 'dxai_ui_update_repository \'\' switches the updates off: nothing is asked', Github_Updater::update( false, array(), $file ) === false && $calls === array() );
remove_filter( 'dxai_ui_update_repository', $off );
$bad = static fn() => 'not a repository';
add_filter( 'dxai_ui_update_repository', $bad );
$expect( '…and so does a name that is not owner/name', Github_Updater::repository() === '' );
remove_filter( 'dxai_ui_update_repository', $bad );

echo "\nView details\n";
$releases = array( $release( '99.0.0', array( 'body' => "Fixed things.\n\n<script>alert(1)</script>" ) ) );
$fresh();
$info = Github_Updater::details( false, 'plugin_information', (object) array( 'slug' => 'dxai-ui' ) );
$expect( 'this plugin\'s details: the version, the ZIP, what it needs', is_object( $info ) && $info->version === '99.0.0' && $info->download_link === 'https://github.com/DevriX/dxai-ui/releases/download/v99.0.0/dxai-ui-99.0.0.zip' && $info->requires_php === '8.2' );
$expect( 'the release\'s notes, escaped', is_object( $info ) && str_contains( $info->sections['changelog'], 'Fixed things.' ) && ! str_contains( $info->sections['changelog'], '<script>' ) );
$expect( 'another plugin\'s details are left alone', Github_Updater::details( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) ) === false && Github_Updater::details( false, 'query_plugins', (object) array( 'slug' => 'dxai-ui' ) ) === false );

echo "\nWordPress' own update check, through the header's Update URI\n";
$data = get_plugin_data( DXAI_UI_FILE, false, false );
$expect( 'the header names the repository as the Update URI', ( $data['UpdateURI'] ?? '' ) === 'https://github.com/DevriX/dxai-ui', (string) ( $data['UpdateURI'] ?? '' ) );
$releases = array( $release( '99.0.0' ) );
$fresh();
delete_site_transient( 'update_plugins' );
wp_update_plugins();
$t   = get_site_transient( 'update_plugins' );
$got = is_object( $t ) ? ( $t->response[ $file ] ?? null ) : null;
$expect( 'Dashboard › Updates offers the release: the new version and its ZIP', is_object( $got ) && $got->new_version === '99.0.0' && $got->package === 'https://github.com/DevriX/dxai-ui/releases/download/v99.0.0/dxai-ui-99.0.0.zip', wp_json_encode( $got ) );
$releases = array( $release( '0.0.1' ) );
$fresh();
delete_site_transient( 'update_plugins' );
wp_update_plugins();
$t = get_site_transient( 'update_plugins' );
$expect( 'an older release is not an update: the plugin is listed as up to date', is_object( $t ) && ! isset( $t->response[ $file ] ) && isset( $t->no_update[ $file ] ) );

echo "\nA development checkout is offered nothing (WordPress would delete its folder: through a link, the repository)\n";
$expect( 'this copy is seen as a checkout (it carries the suites)', $on_checkout );
remove_filter( 'dxai_ui_update_checkout', $installed );
$releases = array( $release( '99.0.0' ) );
$fresh();
$expect( 'on it a newer release is not offered', Github_Updater::update( false, array(), $file ) === false );
delete_site_transient( 'update_plugins' );
wp_update_plugins();
$t = get_site_transient( 'update_plugins' );
$expect( '…and Dashboard › Updates has none for it', is_object( $t ) && ! isset( $t->response[ $file ] ) );
add_filter( 'dxai_ui_update_checkout', $installed );

$finish();
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

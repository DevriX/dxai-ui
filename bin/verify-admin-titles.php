<?php
/**
 * A design's title in the admin screens is plain text. WordPress texturizes a title when it prints it (an ampersand
 * becomes `&#038;`, a hyphen between spaces `&#8211;`); the React screens print what the REST routes give them, so
 * "T&T Cleaning" showed as `T&#038;T Cleaning` in every list that chooses a design.
 *
 *   bash bin/wp-php.sh bin/verify-admin-titles.php --user=1        (or: wp eval-file bin/verify-admin-titles.php --user=1)
 *
 * Makes one page named for the test and trashes it again. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Support\Upload_Paths;

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

echo "\nTitles in the lists that choose a design\n";
$title = 'Vtx Titles & Sons - "Test"';
$id    = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => '' ) );
update_post_meta( $id, Page_Scope::META, $id );
update_post_meta( $id, '_dxai_ui_generated_page', '1' );
update_post_meta( $id, '_dxai_ui_css_url', Upload_Paths::DIR . '/pattern-vtxtitles' . $id . '.css' );

$expect( 'the fixture is texturized when WordPress prints it', get_the_title( $id ) !== $title && str_contains( get_the_title( $id ), '&#' ), get_the_title( $id ) );
$plain = html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );

$get = static function ( string $route ): array {
	$res = rest_do_request( new WP_REST_Request( 'GET', '/' . DXAI_UI_REST_NAMESPACE . $route ) );

	return $res->get_status() === 200 && is_array( $res->get_data() ) ? $res->get_data() : array();
};
$find = static function ( array $rows ) use ( $id ): ?array {
	foreach ( $rows as $row ) {
		if ( is_array( $row ) && (int) ( $row['id'] ?? 0 ) === $id ) {
			return $row;
		}
	}

	return null;
};

$lists = array(
	'Page speed (fonts)'           => static fn() => $find( (array) ( $get( '/speed' )['designs'] ?? array() ) ),
	'Native blocks'                => static fn() => $find( (array) ( $get( '/native-blocks' )['designs'] ?? array() ) ),
	'Pages from your live site'    => static fn() => $find( (array) ( $get( '/site-pages/designs' )['designs'] ?? array() ) ),
	'Theme colours'                => static fn() => $find( (array) ( $get( '/theme-colors' )['designs'] ?? array() ) ),
	'Export and import (the page)' => static fn() => $find( (array) ( $get( '/transfer/pages' )['pages'] ?? array() ) ),
);
foreach ( $lists as $name => $row ) {
	$found = $row();
	if ( $found === null ) {
		$expect( "$name lists the design", false, 'not in the answer' );
		continue;
	}
	$expect( "$name: the title is plain text", (string) $found['title'] === $plain, (string) $found['title'] );
}

wp_trash_post( $id );
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

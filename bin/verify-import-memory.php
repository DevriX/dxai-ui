<?php
/**
 * What a whole-site import costs in memory: the conversion record it keeps, and the memory its requests get.
 *
 *   wp eval-file bin/verify-import-memory.php --user=1
 *
 * A 13-page Claude Design package (Gemini Restoration) stopped on a live site in its last step, after every page was written,
 * with "There has been a critical error on this website": PHP's "Allowed memory size of 268435456 bytes exhausted".
 *
 * Every import keeps its compile result and its answer as one conversion record (Structure_Repository::save_conversion()),
 * which restore() saves again and Document_Store::rebuild() reads. It used to be the JSON itself: 39 MB for that package, and
 * writing it took 352 MB more memory than the save already held. The record is now compressed (Conversion_Snapshot). This
 * suite checks the round trip (HTML, quotes, backslashes, Cyrillic), that the text holds nothing KSES or wp_slash() act on and
 * survives a write by a user without unfiltered_html, that records written before still read, that a damaged one reads as
 * none, that the two readers read the compressed form, and what a large record costs to write against the old way.
 *
 * The import's requests are REST requests, which get WP_MEMORY_LIMIT, not the admin's WP_MAX_MEMORY_LIMIT; even compressed,
 * that save needed 305 MB. So reading, compiling and saving ask for the admin's memory (wp_raise_memory_limit( 'admin' ))
 * before they read anything: the suite stops each at that point, so nothing is made.
 *
 * It makes one page and a few records of its own and deletes them when it ends, however it ends. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Content\Content_Types;
use DXAI_UI\Design\Document_Store;
use DXAI_UI\Structures\Conversion_Snapshot;
use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Structures\Structure_Repository;

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

$run  = substr( md5( microtime( true ) . wp_rand() ), 0, 8 );
$made = array();
$user = get_current_user_id();
register_shutdown_function(
	static function () use ( &$made, $user ): void {
		wp_set_current_user( $user );
		foreach ( $made as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}
);

// A save's record holds pages of markup: tags, both quotes, backslashes (JSON in block comments), entities, other scripts.
$section = '<!-- wp:group {"className":"hero \\"main\\"","style":{"spacing":{"padding":"2rem"}}} --><div class="wp-block-group hero" style="padding:2rem"><h2>Ремонт & <em>restoration</em> — it\'s "fast"</h2><script>var a = "<\\/script>";</script></div><!-- /wp:group -->';
$payload = array(
	'result'  => array(
		'block_title'      => 'Snapshot fixture ' . $run,
		'gutenberg_markup' => $section,
		'pages'            => array_fill( 0, 3, array( 'block_title' => 'Страница', 'gutenberg_markup' => str_repeat( $section, 4 ) ) ),
		'custom_css'       => '.hero::before{content:"\\201C";}',
		'number'           => 3.5,
		'flag'             => true,
		'none'             => null,
	),
	'created' => array( 'page_id' => 0, 'extra_page_ids' => array( 1, 2 ) ),
);

echo "Conversion_Snapshot\n";
$text = Conversion_Snapshot::encode( $payload );
$expect( 'a record is compressed where PHP has zlib (it starts with ' . Conversion_Snapshot::PREFIX . ')', ! function_exists( 'gzdeflate' ) || str_starts_with( $text, Conversion_Snapshot::PREFIX ), substr( $text, 0, 40 ) );
$expect( 'it reads back as the payload it was made from, every string and type the same', Conversion_Snapshot::decode( $text ) === $payload );
$expect( 'it holds none of the characters KSES or wp_slash() act on (< > & \' " \\)', strpbrk( $text, '<>&\'"\\' ) === false );
$hex = (string) wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
$expect( 'a record written before (the JSON, escaped) still reads', Conversion_Snapshot::decode( $hex ) === $payload );
$expect( 'so does plain JSON', Conversion_Snapshot::decode( (string) wp_json_encode( $payload ) ) === $payload );
$warned = array();
set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- the check is that decode() raises none.
	static function ( int $no, string $message ) use ( &$warned ): bool {
		// What @ silences is not shown on any site (PHP still calls the handler for it).
		if ( ( error_reporting() & $no ) !== 0 ) {
			$warned[] = $message;
		}
		return true;
	}
);
$damaged = array(
	Conversion_Snapshot::decode( Conversion_Snapshot::PREFIX . 'not-deflate' ),
	Conversion_Snapshot::decode( Conversion_Snapshot::PREFIX . base64_encode( 'plain' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	Conversion_Snapshot::decode( '' ),
	Conversion_Snapshot::decode( '[1' ),
);
restore_error_handler();
$expect( 'a damaged record reads as none, without a warning', $damaged === array( null, null, null, null ) && $warned === array(), implode( '; ', $warned ) );

// A large record: what the import of a whole site keeps (about 8 MB of markup here).
$big  = array(
	'result'  => array( 'pages' => array_fill( 0, 160, array( 'gutenberg_markup' => str_repeat( $section, 120 ) ) ) ),
	'created' => array(),
);
$json = (string) wp_json_encode( $big, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
$gz   = Conversion_Snapshot::encode( $big );
$expect( 'a large record is a small part of its JSON (' . round( strlen( $gz ) / 1024 ) . ' KB of ' . round( strlen( $json ) / 1048576, 1 ) . ' MB)', ! function_exists( 'gzdeflate' ) || strlen( $gz ) * 4 < strlen( $json ) );
unset( $json, $gz );

echo "\nThe record a save writes\n";
// A design's Home as Design_Attach::is_design() knows one: its own scope and a stylesheet (never written).
$home   = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Snapshot fixture ' . $run, 'post_content' => '' ) );
$made[] = $home;
update_post_meta( $home, Page_Scope::META, $home );
update_post_meta( $home, '_dxai_ui_css_url', 'dxai-ui/snapshot-fixture-' . $run . '.css' );
$expect( 'the fixture is a design Home (Document_Store reads its snapshots)', Document_Store::home_of( $home ) === $home );
$repo   = new Structure_Repository();
$write  = new ReflectionMethod( Structure_Repository::class, 'save_conversion' );
$write->setAccessible( true );
// As a user without unfiltered_html, so KSES runs on the record like on any post of theirs.
wp_set_current_user( 0 );
kses_init();
$id     = (int) $write->invoke( $repo, 'Snapshot fixture ' . $run, $payload['result'], array( 'page_id' => $home ) + $payload['created'] );
$made[] = $id;
wp_set_current_user( $user );
kses_init();
$stored = (string) get_post_field( 'post_content', $id );
$expect( 'save_conversion() writes the compressed record', $id > 0 && ( ! function_exists( 'gzdeflate' ) || str_starts_with( $stored, Conversion_Snapshot::PREFIX ) ), substr( $stored, 0, 40 ) );
$back = Conversion_Snapshot::decode( $stored );
$expect( 'and it comes back from the database whole, KSES on', is_array( $back ) && ( $back['result'] ?? null ) === $payload['result'] );

// The readers: restore() decodes before it saves (a user without unfiltered_html is then refused, so nothing is made), and
// Document_Store::rebuild() finds the snapshot (this one is too small to make a document of, which is a different error).
wp_set_current_user( 0 );
$restored = $repo->restore( $id );
wp_set_current_user( $user );
$expect( 'restore() reads the compressed record', is_wp_error( $restored ) && $restored->get_error_code() === 'dxai_ui_unfiltered_html', is_wp_error( $restored ) ? $restored->get_error_code() . ': ' . $restored->get_error_message() : 'saved' );
$rebuilt = Document_Store::rebuild( $home );
$expect( 'Document_Store::rebuild() finds the compressed snapshot', ! is_wp_error( $rebuilt ) || ! in_array( $rebuilt->get_error_code(), array( 'dxai_ui_no_design', 'dxai_ui_no_snapshot' ), true ), is_wp_error( $rebuilt ) ? $rebuilt->get_error_code() : '' );
$broken = (int) wp_insert_post( array( 'post_type' => Content_Types::CONVERSION, 'post_status' => 'publish', 'post_title' => 'Snapshot fixture broken ' . $run, 'post_content' => Conversion_Snapshot::PREFIX . 'AAAA', 'meta_input' => array( '_dxai_ui_page_id' => $home ) ) );
$made[] = $broken;
$bad    = $repo->restore( $broken );
$expect( 'a damaged record is refused by restore(), not saved', is_wp_error( $bad ) && $bad->get_error_code() === 'dxai_ui_restore' );
$expect( 'and the newest snapshot of a Home, damaged, gives rebuild() no snapshot', is_wp_error( Document_Store::rebuild( $home ) ) && Document_Store::rebuild( $home )->get_error_code() === 'dxai_ui_no_snapshot' );

echo "\nWhat a large record costs to write\n";
if ( function_exists( 'memory_reset_peak_usage' ) ) {
	$title = 'Snapshot fixture cost ' . $run;
	$cost  = static function ( callable $write ) use ( &$made ): int {
		gc_collect_cycles();
		memory_reset_peak_usage();
		$before = memory_get_usage();
		$made[] = (int) $write();
		return (int) ( memory_get_peak_usage() - $before );
	};
	$old   = $cost(
		static fn() => wp_insert_post(
			array(
				'post_type'    => Content_Types::CONVERSION,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => wp_slash( (string) wp_json_encode( array( 'result' => $big['result'], 'created' => array() ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) ),
			)
		)
	);
	$new   = $cost( static fn() => $write->invoke( $repo, $title, $big['result'], array( 'page_id' => -1 ) ) );
	$expect( 'writing it takes a small part of the memory the JSON took (' . round( $new / 1048576 ) . ' MB against ' . round( $old / 1048576 ) . ' MB)', ! function_exists( 'gzdeflate' ) || $new * 3 < $old );
} else {
	echo "  (memory_reset_peak_usage() needs PHP 8.2: the cost is not measured here)\n";
}

echo "\nThe import's requests ask for the admin's memory\n";
// wp_raise_memory_limit() does nothing where PHP has no limit (-1, as on some command lines): give it one for the test.
$limit = (string) ini_get( 'memory_limit' );
if ( wp_convert_hr_to_bytes( $limit ) === -1 ) {
	ini_set( 'memory_limit', '2048M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- put back below.
}
foreach ( array( 'process-source', 'generate-block', 'save-pattern' ) as $route ) {
	$asked = false;
	$stop  = static function () use ( &$asked ): void {
		$asked = true;
		// Stopped where the limit is raised: the request reads nothing and makes nothing.
		throw new RuntimeException( 'stop' );
	};
	add_filter( 'admin_memory_limit', $stop );
	$got = null;
	try {
		$got = rest_do_request( new WP_REST_Request( 'POST', '/' . DXAI_UI_REST_NAMESPACE . '/' . $route ) );
	} catch ( RuntimeException $e ) {
		unset( $e );
	}
	remove_filter( 'admin_memory_limit', $stop );
	$expect( "/$route asks for it before it reads anything", $asked, $got instanceof WP_REST_Response ? 'answered ' . $got->get_status() : '' );
}
ini_set( 'memory_limit', $limit ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

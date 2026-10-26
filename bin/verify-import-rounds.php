<?php
/**
 * An import in rounds (WP Engine ends a request after 60 seconds): the archive's files added a round at a time, the pictures'
 * smaller sizes made afterwards, the save a round at a time — the same site as an import at once — and the whole flow through the
 * REST routes the import screen calls.
 * Run: wp eval-file bin/verify-import-rounds.php
 *
 * Imports into the site and removes what it made (bin/verify-cleanup.php), also when it stops part-way. SKIP without GD.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Compiler\Source_Compiler;
use DXAI_UI\Connectors\Zip_Router;
use DXAI_UI\Media\Asset_Harvester;
use DXAI_UI\Media\Harvest_Paused;
use DXAI_UI\Media\Pending_Sizes;
use DXAI_UI\Structures\Structure_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! function_exists( 'imagecreatetruecolor' ) || ! class_exists( ZipArchive::class ) ) {
	echo "SKIP: needs PHP's GD and zip extensions\n";
	exit( 77 );
}

require DXAI_UI_DIR . 'bin/verify-cleanup.php';
$dxai_snapshot = dxai_verify_snapshot();
$dir           = trailingslashit( get_temp_dir() ) . 'dxai-ui-verify-rounds-' . wp_generate_password( 8, false, false );
wp_mkdir_p( $dir );
// Whatever happens below, the site is left as it was found.
register_shutdown_function(
	static function () use ( $dxai_snapshot, $dir ): void {
		dxai_verify_cleanup( $dxai_snapshot );
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			wp_delete_file( (string) $file );
		}
		if ( is_dir( $dir ) ) {
			rmdir( $dir );
		}
	}
);
$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) ( $admins[0] ?? 1 ) );

$fail   = 0;
$pass   = 0;
$expect = static function ( string $what, bool $ok, string $detail = '' ) use ( &$fail, &$pass ): void {
	$ok ? ++$pass : ++$fail;
	echo ( $ok ? '  ok    ' : '  FAIL  ' ) . $what . ( ! $ok && $detail !== '' ? '  — ' . $detail : '' ) . "\n";
};

// A picture of its own every run, large enough to be cut into WordPress's sizes.
$png = static function ( int $n ): string {
	$im = imagecreatetruecolor( 480, 320 );
	imagefilledrectangle( $im, 0, 0, 479, 319, (int) imagecolorallocate( $im, ( 40 * $n ) % 256, ( 90 * $n ) % 256, ( 150 * $n ) % 256 ) );
	imagestring( $im, 5, 12, 12, 'picture ' . $n . ' ' . wp_generate_password( 10, false, false ), (int) imagecolorallocate( $im, 255, 255, 255 ) );
	ob_start();
	imagepng( $im );

	return (string) ob_get_clean();
};
// A Claude Design site of three pages, a header and a footer, naming $pictures pictures.
$site = static function ( string $name, int $pictures ) use ( $png, $dir ): string {
	$dc    = static fn( string $body, string $style = '', string $title = '' ): string => '<!DOCTYPE html><html><head><meta charset="utf-8"><script src="./support.js"></script></head><body><x-dc>'
		. '<helmet>' . ( $title !== '' ? '<title>' . $title . '</title>' : '' ) . ( $style !== '' ? '<style>' . $style . '</style>' : '' ) . '</helmet>' . $body . '</x-dc></body></html>';
	$img   = static fn( int $n ): string => '<img src="assets/picture-' . $n . '.png" alt="Picture ' . $n . '" style="width:240px;height:160px">';
	$page  = static fn( string $inner, string $title = '' ): string => $dc(
		'<div style="width:100%;font-family:Poppins,sans-serif"><dc-import name="Rounds Nav"></dc-import>' . $inner . '<dc-import name="Rounds Footer"></dc-import></div>',
		'.card{border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,.2)}',
		$title
	);
	$half  = (int) ceil( $pictures / 2 );
	$files = array(
		'Rounds Home.dc.html'     => $page( '<div style="padding:80px;background:#0b3d91;color:#fff"><h1>Roofs that last</h1><a href="Rounds%20About.dc.html#team" style="color:#f2a34a">Our team</a>' . implode( '', array_map( $img, range( 1, $half ) ) ) . '</div>' ),
		'Rounds About.dc.html'    => $page( '<div style="padding:80px"><h1 style="color:#0b3d91">About</h1><p id="team" class="card">Our team.</p>' . implode( '', array_map( $img, range( $half + 1, $pictures ) ) ) . '</div>', 'About | Rounds' ),
		'Rounds Contact.dc.html'  => $page( '<div style="padding:80px;background:#f2a34a"><h1>Contact</h1><a href="Rounds%20Home.dc.html">Home</a></div>' ),
		'Rounds Nav.dc.html'      => $dc( '<div style="background:#fff"><a href="Rounds%20Home.dc.html"><img src="assets/logo.svg" alt="Rounds"></a><a href="Rounds%20About.dc.html">About</a><a href="Rounds%20Contact.dc.html">Contact</a></div>' ),
		'Rounds Footer.dc.html'   => $dc( '<div style="background:#222;color:#eee"><a href="Rounds%20Contact.dc.html">Write to us</a> © 2026</div>' ),
		'support.js'              => '/* runtime */',
		'assets/logo.svg'         => '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10" fill="#0b3d91"/></svg>',
	);
	for ( $n = 1; $n <= $pictures; $n++ ) {
		$files[ 'assets/picture-' . $n . '.png' ] = $png( $n );
	}
	$path = $dir . '/' . $name . '.zip';
	$zip  = new ZipArchive();
	$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	foreach ( $files as $file => $contents ) {
		$zip->addFromString( $file, $contents );
	}
	$zip->close();

	return $path;
};
$attachments = static fn(): array => array_map( 'intval', get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) ) );

// --- 1. The read in rounds --------------------------------------------------------------------------------------------------------
echo "1. Reading an archive in rounds\n";
$pictures = 6;
$zip      = $site( 'Rounds Design', $pictures );
$file     = array(
	'tmp_name' => $zip,
	'name'     => 'Rounds Design.zip',
);
$before   = $attachments();
$pauses   = array();
$doc      = null;
for ( $round = 1; $round <= 40 && null === $doc; $round++ ) {
	// A round far shorter than adding one picture takes: each stops after the first file it adds.
	Asset_Harvester::in_round( 0.000001 );
	try {
		$doc = Pending_Sizes::defer( static fn() => Zip_Router::import( $file ) );
	} catch ( Harvest_Paused $paused ) {
		$pauses[] = $paused->done . '/' . $paused->total;
	} finally {
		Asset_Harvester::in_round( 0 );
	}
}
$added = array_values( array_diff( $attachments(), $before ) );
$files = $pictures + 1;
$expect( 'the read is done after its rounds', $doc instanceof DXAI_UI\Connectors\Source_Document, is_wp_error( $doc ) ? $doc->get_error_message() : '' );
$expect( "each round added one file and stopped: $files files, $files rounds that stopped, then the one that read the design", count( $pauses ) === $files && end( $pauses ) === "$files/$files", implode( ' ', $pauses ) );
$expect( 'every file is in the media library once', count( $added ) === $files, count( $added ) . ' attachments' );
$waiting = array_values( array_filter( $added, static fn( int $id ): bool => '' !== (string) get_post_meta( $id, Pending_Sizes::META, true ) ) );
$expect( "the $pictures pictures wait for their smaller sizes, the SVG does not", count( $waiting ) === $pictures, count( $waiting ) . ' waiting' );
$expect( 'and none was cut into sizes yet', array() === array_filter( $waiting, static fn( int $id ): bool => ! empty( wp_get_attachment_metadata( $id )['sizes'] ?? array() ) ) );
$at_once = Zip_Router::import( $file );
$urls    = static fn( $d ): array => $d instanceof DXAI_UI\Connectors\Source_Document ? array_values( array_unique( array_map( 'strval', array_column( (array) ( $d->payload['harvest']['assets'] ?? $d->payload['harvest']['images'] ?? array() ), 'url' ) ) ) ) : array();
$expect( 'the same archive read at once finds every file and names the same uploads', $urls( $doc ) !== array() && $urls( $doc ) === $urls( $at_once ) && $attachments() === array_merge( $before, $added ), wp_json_encode( array( $urls( $doc ), $urls( $at_once ) ) ) );
$sizes = Pending_Sizes::run( 0 );
$expect( 'the sizes are made afterwards, all of them', $sizes['made'] === $pictures && $sizes['left'] === 0 && Pending_Sizes::count() === 0, wp_json_encode( $sizes ) );
$expect( 'each picture now has its smaller sizes', array() === array_filter( $waiting, static fn( int $id ): bool => empty( wp_get_attachment_metadata( $id )['sizes'] ?? array() ) ) );

// --- 2. The save in rounds --------------------------------------------------------------------------------------------------------
echo "\n2. Saving in rounds, the same as saving at once\n";
$result                         = ( new Source_Compiler() )->compile( $at_once );
$result['create_missing_pages'] = false;
$result['chrome']               = 'keep';
$result['source_name']          = 'Rounds Design.zip';
// As the import screen posts it, and as the rounds keep it between requests.
$input = json_decode( (string) wp_json_encode( $result ), true );
$ids   = static fn( array $c ): array => array_values( array_filter( array_map( 'intval', array_merge( array( $c['page_id'] ?? 0 ), (array) ( $c['extra_page_ids'] ?? array() ), array( $c['header_id'] ?? 0, $c['footer_id'] ?? 0 ), array_column( (array) ( $c['patterns'] ?? array() ), 'id' ) ) ) ) );
$sheet = new ReflectionMethod( DXAI_UI\Compiler\Token_Styles::class, 'sheet_path' );
$sheet->setAccessible( true );
// What a save leaves: each post of the design, its colour palette, its hoisted CSS, and each page's stylesheet.
$capture = static function ( array $created ) use ( $ids, $sheet ): array {
	$out = array();
	foreach ( $ids( $created ) as $id ) {
		$post       = get_post( $id );
		$path       = (string) $sheet->invoke( null, $id );
		$out[ $id ] = md5(
			(string) wp_json_encode(
				array(
					$post ? $post->post_title . '|' . $post->post_name . '|' . $post->post_content : '',
					get_post_meta( $id, DXAI_UI\Compiler\Token_Styles::META, true ),
					get_post_meta( $id, DXAI_UI\Compiler\Style_Hoister::INNER_META, true ),
					get_post_meta( $id, DXAI_UI\Compiler\Style_Hoister::DESIGN_NAMES_META, true ),
					$path !== '' && is_readable( $path ) ? md5_file( $path ) : '',
				)
			)
		);
	}

	return $out;
};
$first = ( new Structure_Repository() )->save( $input );
$again = ( new Structure_Repository() )->save( $input );
$expect( 'saved at once, twice (the second updates the first in place)', is_array( $first ) && is_array( $again ) && $ids( $first ) === $ids( $again ) && count( (array) $again['extra_page_ids'] ) === 2, is_wp_error( $again ) ? $again->get_error_message() : '' );
$once   = is_array( $again ) ? $capture( $again ) : array();
$state  = array();
$rounds = 0;
do {
	++$rounds;
	// A new repository each round, its state through JSON: what one request leaves the next.
	$step  = ( new Structure_Repository() )->save_round( $input, $state, 0.000001 );
	$state = is_array( $step ) ? (array) json_decode( (string) wp_json_encode( $step ), true ) : array();
} while ( is_array( $step ) && empty( $step['done'] ) && $rounds < 400 );
$by_rounds = is_array( $step ) && ! empty( $step['done'] ) ? (array) $step['created'] : array();
$expect( "saved again in $rounds rounds of one step each (the Home, each page, each step and post of the tail)", $rounds > 3 + 13 && $by_rounds !== array(), is_wp_error( $step ) ? $step->get_error_message() : '' );
$expect( 'the same pages, header, footer and sections', $by_rounds !== array() && $ids( $by_rounds ) === $ids( (array) $again ) );
$differ = array_keys( array_diff_assoc( $by_rounds !== array() ? $capture( $by_rounds ) : array(), $once ) );
$expect( 'and every one of them as the save at once left it: content, palette, hoisted CSS, design names, stylesheet', $once !== array() && $differ === array(), 'differ: ' . implode( ', ', $differ ) );
$expect( 'its answer reports the same pages, tokens and hoisting', $by_rounds !== array() && ( $by_rounds['tokens'] ?? null ) == ( $again['tokens'] ?? null ) && ( $by_rounds['hoisted'] ?? null ) == ( $again['hoisted'] ?? null ), wp_json_encode( array( $by_rounds['tokens'] ?? null, $again['tokens'] ?? null ) ) );

// --- 3. Through the REST routes ---------------------------------------------------------------------------------------------------
echo "\n3. The import screen's requests, in rounds\n";
$short = static fn(): float => 0.000001;
add_filter( 'dxai_ui_import_round_seconds', $short );
$call = static function ( string $route, array $params, array $files = array() ) {
	$request = new WP_REST_Request( 'POST', '/' . DXAI_UI_REST_NAMESPACE . '/' . $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	if ( $files !== array() ) {
		$request->set_file_params( $files );
	}
	$response = rest_do_request( $request );

	return array( $response->get_status(), (array) $response->get_data() );
};
$rest_zip = $site( 'Rounds Rest', 3 );
// The archive in three pieces, as the screen sends one over the host's upload limit (upload-chunk).
$bytes  = (string) file_get_contents( $rest_zip );
$piece  = (int) ceil( strlen( $bytes ) / 3 );
$send   = static function ( int $index, string $stage ) use ( $bytes, $piece, $dir, $call ): array {
	$path = $dir . '/piece-' . $index . '-' . wp_generate_password( 6, false, false ) . '.bin';
	file_put_contents( $path, substr( $bytes, $index * $piece, $piece ) );

	return $call( 'upload-chunk', array_filter( array( 'name' => 'Rounds Rest.zip', 'index' => $index, 'total' => 3, 'size' => strlen( $bytes ), 'stage' => $stage ), static fn( $v ): bool => $v !== '' ), array( 'chunk' => array( 'name' => 'Rounds Rest.zip', 'tmp_name' => $path, 'error' => 0, 'size' => (int) filesize( $path ) ) ) );
};
list( $s0, $p0 ) = $send( 0, '' );
$stage           = (string) ( $p0['stage'] ?? '' );
list( $s1, $p1 ) = $send( 1, $stage );
list( $s2, $p2 ) = $send( 2, $stage );
list( $s3, $p3 ) = $send( 1, $stage );
$expect( 'the archive arrives in pieces under one token, complete with the last', 200 === $s0 && preg_match( '/^[a-f0-9]{32}$/', $stage ) === 1 && false === $p0['complete'] && 200 === $s1 && 2 === $p1['received'] && 200 === $s2 && true === $p2['complete'], wp_json_encode( array( $p0, $p1, $p2 ) ) );
$job = DXAI_UI\Transfer\Import_Job::open( $stage );
$expect( 'a piece sent again is not added twice: the archive is the one sent', 200 === $s3 && true === ( $p3['complete'] ?? null ) && is_array( $job ) && md5_file( (string) $job['zip'] ) === md5( $bytes ) );
list( $status, $read ) = $call( 'process-source', array( 'source_type' => 'auto-zip', 'rounds' => '1', 'stage' => $stage ) );
$reads = 1;
$expect( 'the first round of the read goes on from the pieces and asks to go on', 200 === $status && 'continue' === ( $read['state'] ?? '' ) && $stage === ( $read['stage'] ?? '' ), wp_json_encode( $read ) );
while ( 'continue' === ( $read['state'] ?? '' ) && $reads < 30 ) {
	++$reads;
	list( $status, $read ) = $call( 'process-source', array( 'source_type' => 'auto-zip', 'rounds' => '1', 'stage' => $stage ) );
}
$expect( "the read goes on by the token alone, no second upload, and is done in $reads rounds", 200 === $status && 'done' === ( $read['state'] ?? '' ) && $stage === ( $read['stage'] ?? '' ) && $reads >= 4 && isset( $read['source'] ), wp_json_encode( array_intersect_key( $read, array_flip( array( 'state', 'stage', 'code', 'message' ) ) ) ) );
// The same archive again, as a new import: every file is in the media library, and the read still goes in rounds — each looks a
// file up and carries it to the next — instead of finding them all and reading every page in one request.
$again_zip = $dir . '/again.zip';
copy( $rest_zip, $again_zip );
$before_again = $attachments();
list( $status, $again ) = $call( 'process-source', array( 'source_type' => 'auto-zip', 'rounds' => '1' ), array( 'file' => array( 'name' => 'Rounds Rest.zip', 'tmp_name' => $again_zip, 'error' => 0, 'size' => (int) filesize( $again_zip ) ) ) );
$again_rounds = 1;
while ( 'continue' === ( $again['state'] ?? '' ) && $again_rounds < 30 ) {
	++$again_rounds;
	list( $status, $again ) = $call( 'process-source', array( 'source_type' => 'auto-zip', 'rounds' => '1', 'stage' => (string) $again['stage'] ) );
}
$expect( "an archive whose files are all here is read in rounds too ($again_rounds), and adds nothing", 'done' === ( $again['state'] ?? '' ) && $again_rounds >= 4 && $attachments() === $before_again, wp_json_encode( array_intersect_key( $again, array_flip( array( 'state', 'code', 'message' ) ) ) ) );
if ( isset( $again['stage'] ) && ! is_wp_error( DXAI_UI\Transfer\Import_Job::open( (string) $again['stage'] ) ) ) {
	DXAI_UI\Transfer\Import_Job::close( DXAI_UI\Transfer\Import_Job::open( (string) $again['stage'] ) );
}
list( $status, $compiled ) = $call( 'generate-block', array( 'source_type' => 'auto-zip', 'rounds' => '1', 'stage' => $stage, 'fidelity' => 'off' ) );
$compile = (array) ( $compiled['result'] ?? array() );
$expect( 'the compile reads the same kept archive', 200 === $status && ! empty( $compile['structures'] ) && 2 === count( (array) ( $compile['pages'] ?? array() ) ), wp_json_encode( array_intersect_key( $compiled, array_flip( array( 'code', 'message' ) ) ) ) );
$expect( 'and keeps the other pages on the server: the screen gets their names, not their blocks', 2 === (int) ( $compile['pages_kept'] ?? 0 ) && ! isset( $compile['pages'][0]['gutenberg_markup'] ) && '' !== (string) ( $compile['pages'][0]['block_title'] ?? '' ) );
$token = md5( wp_generate_password( 20, false, false ) );
$saves = 1;
list( $status, $saved ) = $call( 'save-pattern', array_merge( $compile, array( 'chrome' => 'keep', 'rounds' => true, 'stage' => $stage, 'import_token' => $token, 'synced' => true ) ) );
$expect( 'the save\'s first round answers continue, with the pages written so far', 200 === $status && 'continue' === ( $saved['state'] ?? '' ) && isset( $saved['pages']['written'] ), wp_json_encode( array_intersect_key( $saved, array_flip( array( 'state', 'pages', 'code', 'message' ) ) ) ) );
$record = get_transient( 'dxai_ui_save_' . md5( $token . '|' . get_current_user_id() ) );
$steps_seen = array();
while ( 'continue' === ( $saved['state'] ?? '' ) && $saves < 400 ) {
	++$saves;
	list( $status, $saved ) = $call( 'save-pattern', array( 'rounds' => true, 'stage' => $stage, 'save_round' => 'continue', 'import_token' => $token ) );
	if ( is_array( $saved['tail'] ?? null ) ) {
		$steps_seen[ (string) $saved['tail']['step'] ] = true;
	}
}
// Which steps a round ends before depends on the machine's speed (a fast one finishes the quick steps in the round before), so
// the check is that every step named is a finishing step, in the save's own order, and that they reach the last one.
$tail_steps = ( new ReflectionClassConstant( Structure_Repository::class, 'TAIL_STEPS' ) )->getValue();
$named      = array_keys( $steps_seen );
$expect( 'the save\'s answers name the finishing step that comes next, for the screen', count( $named ) >= 5 && array_values( array_intersect( $tail_steps, $named ) ) === $named && isset( $steps_seen['document'] ), implode( ',', $named ) );
$page = (int) ( $saved['page']['id'] ?? 0 );
$expect( "the save goes on by the token alone and answers as a save at once does after $saves rounds", 200 === $status && $page > 0 && ! isset( $saved['state'] ) && ! empty( $saved['structures']['extra_page_ids'] ), wp_json_encode( array_intersect_key( $saved, array_flip( array( 'state', 'code', 'message' ) ) ) ) );
$expect( 'its pages are there, linked to each other', $page > 0 && str_contains( (string) get_post_field( 'post_content', $page ), (string) get_permalink( (int) ( $saved['structures']['extra_page_ids'][0] ?? 0 ) ) ) );
$expect( 'the kept archive and the round lock are gone once it is done', is_wp_error( DXAI_UI\Transfer\Import_Job::open( $stage ) ) && false === get_option( 'dxai_ui_round_lock_' . $stage ) );
list( $status, $more ) = $call( 'save-pattern', array( 'rounds' => true, 'stage' => $stage, 'save_round' => 'continue', 'import_token' => $token ) );
$expect( 'a round asked for after the end is refused, not run again', $status >= 400 );
$made = 0;
for ( $i = 0; $i < 20; $i++ ) {
	list( $status, $sizes ) = $call( 'media-sizes', array() );
	$made += (int) ( $sizes['made'] ?? 0 );
	if ( 0 === (int) ( $sizes['left'] ?? 0 ) ) {
		break;
	}
}
$expect( 'the pictures\' sizes are made by /media-sizes, a round at a time, until none is left', 200 === $status && 3 === $made && 0 === (int) ( $sizes['left'] ?? -1 ), wp_json_encode( $sizes ) );
remove_filter( 'dxai_ui_import_round_seconds', $short );

// --- 5. Pages built from the Home's sections -----------------------------------------------------------------------------------
echo "\n5. A page a crawl writes from the Home's sections carries the Home's design names\n";
// Names an older import recorded on a crawled page: every utility the Home's hoist had placed in its sections, which then got no
// utility rule (H2O's menu pages lost relative, items-center, … — 167 px of sideways scroll at 390). DX › Site rebuilds it.
$names_meta = DXAI_UI\Compiler\Style_Hoister::DESIGN_NAMES_META;
$rebuilt    = (int) wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Rounds Rebuilt',
		'post_content' => '<!-- wp:paragraph --><p class="relative items-center">x</p><!-- /wp:paragraph -->',
	)
);
update_post_meta( $rebuilt, DXAI_UI\Structures\Page_Scope::META, $page );
update_post_meta( $rebuilt, $names_meta, array( 'items-center', 'relative', 'w-full' ) );
$home_names = array( 'rounds-own-card', 'rounds-own-hero' );
update_post_meta( $page, $names_meta, $home_names );
$crawl_state = array(
	'tail'  => array( 'page_id' => $page ),
	'crawl' => array(
		'summary' => array(
			'origin'        => '',
			'link_pages'    => array(),
			'pages_created' => array(
				array(
					'id'    => $rebuilt,
					'label' => 'Rounds Rebuilt',
					'path'  => '/rounds-rebuilt/',
				),
			),
		),
	),
);
DXAI_UI\Pages\Site_Pages::finish( $crawl_state );
$expect( 'DX › Site\'s crawl gives a page it rebuilt the Home\'s design names, not the ones an older import left', get_post_meta( $rebuilt, $names_meta, true ) === $home_names, wp_json_encode( get_post_meta( $rebuilt, $names_meta, true ) ) );
$expect( 'and leaves the Home\'s own as they were', get_post_meta( $page, $names_meta, true ) === $home_names );
delete_post_meta( $page, $names_meta );
update_post_meta( $rebuilt, $names_meta, array( 'relative' ) );
DXAI_UI\Pages\Site_Pages::finish( $crawl_state );
$expect( 'a Home with no design names leaves the page none either', '' === get_post_meta( $rebuilt, $names_meta, true ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

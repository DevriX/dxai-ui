<?php
/**
 * After a site moves to another address, what the plugin stored names the site's own files at the new one.
 *
 *   wp eval-file bin/verify-site-address.php --user=1
 *
 * Gemini Restoration was imported on a staging install (amrdevrixmulti.wpenginepowered.com/geminirestoration) and then given
 * its domain. The database was moved; the design's stylesheet in uploads was not, and loaded 91 fonts and pictures from the
 * staging address (the hero twice). Support\Site_Address brings every absolute address of a file this server has under
 * wp-content to where it is now. Checked here, on fixtures written with a staging address: a post's block CSS (and the class
 * that is its hash, also where another row names it), the md5 kept of that post, post meta (serialized, and JSON with `\/`), an
 * option, a file in uploads/dx-ui; left alone: an address of a file this server does not have, one already right, a revision;
 * the run starts by itself when the address it last saw is not the site's; a second run changes nothing; and a site whose
 * address in the database is not the one it answers on is not touched by itself.
 *
 * Makes a post, a revision, meta, an option and files, and removes them. Exit code 1 when a check fails.
 *
 * @package DX_UI
 */

use DX_UI\Compiler\Style_Hoister;
use DX_UI\Support\Site_Address;

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

global $wpdb;
$run   = strtolower( wp_generate_password( 6, false ) );
$up    = wp_upload_dir( null, false );
$dir   = untrailingslashit( wp_normalize_path( (string) $up['basedir'] ) ) . '/dx-ui/site-address-' . $run;
$rest  = ltrim( substr( $dir, strlen( untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) ) ), '/' );
$now   = Site_Address::current();
$old   = 'https://staging-' . $run . '.example/geminirestoration/wp-content';
$pic   = $rest . '/pic.png';
$font  = $rest . '/font.woff2';
$gone  = 'https://staging-' . $run . '.example/geminirestoration/wp-content/uploads/not-here-' . $run . '.png';
$other = 'https://elsewhere-' . $run . '.example/wp-content/uploads/elsewhere-' . $run . '.png';
$made  = array(
	'posts' => array(),
	'files' => array(),
);
$saved = get_option( Site_Address::OPTION );
$fin   = static function () use ( &$made, $saved, $dir, $run ): void {
	global $wpdb;
	foreach ( $made['posts'] as $id ) {
		$wpdb->delete( $wpdb->posts, array( 'ID' => (int) $id ) );
		$wpdb->delete( $wpdb->postmeta, array( 'post_id' => (int) $id ) );
		clean_post_cache( (int) $id );
	}
	foreach ( $made['files'] as $file ) {
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}
	if ( is_dir( $dir ) ) {
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
	delete_option( 'dx_ui_site_address_test_' . $run );
	if ( $saved === false ) {
		delete_option( Site_Address::OPTION );
	} else {
		update_option( Site_Address::OPTION, $saved, true );
	}
	$made = array(
		'posts' => array(),
		'files' => array(),
	);
};
register_shutdown_function( $fin );

wp_mkdir_p( $dir );
foreach ( array( 'pic.png', 'font.woff2' ) as $name ) {
	file_put_contents( $dir . '/' . $name, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$made['files'][] = $dir . '/' . $name;
}

echo "Rewriting a text\n";
$expect( 'a staging address of a file this server has becomes the current one', Site_Address::rewrite( 'url(' . $old . '/' . $pic . '?ver=1)' ) === 'url(' . $now . '/' . $pic . '?ver=1)' );
$expect( 'and so does its JSON form', Site_Address::rewrite( '"' . str_replace( '/', '\\/', $old . '/' . $pic ) . '"' ) === '"' . str_replace( '/', '\\/', $now . '/' . $pic ) . '"' );
$expect( 'an address of a file this server does not have stays', Site_Address::rewrite( 'url(' . $gone . ')' ) === 'url(' . $gone . ')' );
$expect( 'an address already right stays', Site_Address::rewrite( 'url(' . $now . '/' . $pic . ')' ) === 'url(' . $now . '/' . $pic . ')' );
$expect( 'a protocol-relative staging address becomes the current one', Site_Address::rewrite( 'url(//staging-' . $run . '.example/wp-content/' . $font . ')' ) === 'url(' . $now . '/' . $font . ')' );

echo "Fixtures with the staging address\n";
$css     = 'background-image:url(' . $old . '/' . $pic . ');background-size:cover';
$new_css = 'background-image:url(' . $now . '/' . $pic . ');background-size:cover';
$cls     = Style_Hoister::css_class( $css );
$new_cls = Style_Hoister::css_class( $new_css );
$content = serialize_block(
	array(
		'blockName'    => 'dx-ui/box',
		'attrs'        => array( Style_Hoister::ATTR => $css ),
		'innerBlocks'  => array(),
		'innerHTML'    => '<div class="hero ' . $cls . '"><img src="' . $old . '/' . $pic . '" alt=""/><img src="' . $other . '" alt=""/></div>',
		'innerContent' => array( '<div class="hero ' . $cls . '"><img src="' . $old . '/' . $pic . '" alt=""/><img src="' . $other . '" alt=""/></div>' ),
	)
);
$wpdb->insert(
	$wpdb->posts,
	array(
		'post_type'     => 'page',
		'post_status'   => 'publish',
		'post_title'    => 'Moved ' . $run,
		'post_name'     => 'moved-' . $run,
		'post_content'  => $content,
		'post_date'     => current_time( 'mysql' ),
		'post_date_gmt' => current_time( 'mysql', true ),
	)
);
$page            = (int) $wpdb->insert_id;
$made['posts'][] = $page;
$wpdb->insert(
	$wpdb->posts,
	array(
		'post_type'     => 'revision',
		'post_status'   => 'inherit',
		'post_parent'   => $page,
		'post_title'    => 'Moved ' . $run,
		'post_name'     => $page . '-revision-v1',
		'post_content'  => $content,
		'post_date'     => current_time( 'mysql' ),
		'post_date_gmt' => current_time( 'mysql', true ),
	)
);
$revision        = (int) $wpdb->insert_id;
$made['posts'][] = $revision;
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.DB.SlowDBQuery.slow_query_meta_key, WordPress.DB.SlowDBQuery.slow_query_meta_value
$meta = array(
	'_dx_ui_test_fonts'   => serialize(
		array(
			'faces'    => '@font-face{src:url(' . $old . '/' . $font . ')}',
			'painters' => array( '.bg-x', '.' . $cls ),
		)
	),
	'_dx_ui_test_json'    => (string) wp_json_encode( array( 'logo' => $old . '/' . $pic ) ),
	'_dx_ui_content_hash' => md5( $content ),
	'_dx_ui_test_painters' => serialize( array( '.' . $cls ) ),
);
foreach ( $meta as $k => $v ) {
	$wpdb->insert(
		$wpdb->postmeta,
		array(
			'post_id'    => $page,
			'meta_key'   => $k,
			'meta_value' => $v,
		)
	);
}
// phpcs:enable
add_option( 'dx_ui_site_address_test_' . $run, array( 'header' => '<img src="' . $old . '/' . $pic . '">' ), '', false );
$file = $dir . '/pattern.css';
file_put_contents( $file, '.a{background:url("' . $old . '/' . $pic . '")}.b{background:url(' . $gone . ')}@font-face{src:url(' . $old . '/' . $font . ') format("woff2")}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
$made['files'][] = $file;

echo "The site is seen to have moved\n";
update_option( Site_Address::OPTION, 'https://staging-' . $run . '.example/geminirestoration/wp-content', true );
wp_cache_flush();
$t = microtime( true );
Site_Address::maybe_repair();
printf( "  (%.1f s)\n", microtime( true ) - $t );
wp_cache_flush();

$c2 = (string) get_post_field( 'post_content', $page );
$expect( 'the block\'s CSS names the picture where it is now', str_contains( $c2, $now . '/' . $pic ) && ! str_contains( $c2, $old ), $c2 );
$expect( 'the block\'s class is the hash of its new CSS (' . $cls . ' to ' . $new_cls . ')', $cls !== $new_cls && str_contains( $c2, 'class="hero ' . $new_cls . '"' ) && ! str_contains( $c2, $cls ) );
$expect( 'another site\'s picture keeps its address', str_contains( $c2, $other ) );
$expect( 'the md5 kept of the page follows its content', get_post_meta( $page, '_dx_ui_content_hash', true ) === md5( $c2 ) );
$fonts = get_post_meta( $page, '_dx_ui_test_fonts', true );
$expect( 'serialized meta: the font is where it is now, and the class another row names follows', is_array( $fonts ) && $fonts['faces'] === '@font-face{src:url(' . $now . '/' . $font . ')}' && $fonts['painters'] === array( '.bg-x', '.' . $new_cls ), (string) wp_json_encode( $fonts ) );
$expect( 'a row that names only the class (no address) follows too', get_post_meta( $page, '_dx_ui_test_painters', true ) === array( '.' . $new_cls ) );
$expect( 'JSON meta keeps its form', get_post_meta( $page, '_dx_ui_test_json', true ) === (string) wp_json_encode( array( 'logo' => $now . '/' . $pic ) ) );
$expect( 'an option of the plugin follows', get_option( 'dx_ui_site_address_test_' . $run ) === array( 'header' => '<img src="' . $now . '/' . $pic . '">' ) );
$css_now = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$expect( 'a stylesheet in uploads/dx-ui is rewritten: picture and font where they are now, the missing file left', $css_now === '.a{background:url("' . $now . '/' . $pic . '")}.b{background:url(' . $gone . ')}@font-face{src:url(' . $now . '/' . $font . ') format("woff2")}', $css_now );
$expect( 'a revision keeps the address it was made with', str_contains( (string) get_post_field( 'post_content', $revision ), $old ) );
$expect( 'the address the site is at now is recorded', get_option( Site_Address::OPTION ) === $now );

echo "A second run\n";
$before = array( $c2, get_post_meta( $page ), get_option( 'dx_ui_site_address_test_' . $run ), $css_now );
update_option( Site_Address::OPTION, 'elsewhere', true );
Site_Address::maybe_repair();
wp_cache_flush();
$after = array( (string) get_post_field( 'post_content', $page ), get_post_meta( $page ), get_option( 'dx_ui_site_address_test_' . $run ), (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$expect( 'changes nothing', $before === $after );

echo "A site that answers on another name than its own\n";
$siteurl = (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'siteurl'" );
$wpdb->update( $wpdb->options, array( 'option_value' => 'https://other-' . $run . '.example' ), array( 'option_name' => 'siteurl' ) );
update_option( Site_Address::OPTION, 'elsewhere', true );
file_put_contents( $file, 'url(' . $old . '/' . $pic . ')' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
Site_Address::maybe_repair();
$wpdb->update( $wpdb->options, array( 'option_value' => $siteurl ), array( 'option_name' => 'siteurl' ) );
wp_cache_flush();
$expect( 'is not rewritten by itself', (string) file_get_contents( $file ) === 'url(' . $old . '/' . $pic . ')' && get_option( Site_Address::OPTION ) === 'elsewhere' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

$fin();
echo "\n" . ( $fail === 0 ? "PASS ({$pass} checks)" : "{$fail} of " . ( $pass + $fail ) . ' checks failed' ) . "\n";
exit( $fail === 0 ? 0 : 1 );

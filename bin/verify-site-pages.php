<?php
/**
 * Pages from a design's live site touch only what is the design's own in the site's shared menus and block widgets.
 *
 *   bash bin/wp-php.sh bin/verify-site-pages.php        (or: wp eval-file bin/verify-site-pages.php --user=1)
 *
 * When the pages of a design's old site are built (DX › Site › Pages from your live site), the links to the old pages are made links
 * to the new ones — in the design's Home and parts, in the menus, in the block widgets. The menus and widgets are the site's, shared
 * by every design on it: a link like "/about/" or "#reviews" in another design's footer is a page and a section of this site, not the
 * old site's. On a test site with two designs the run of H2O Away re-pointed three links of Five Star's footer (2026-10-14). Only a
 * link that names the old site by its address is the old site's everywhere; its bare paths and the Home's anchors are, in the
 * design's own menus and footer widgets only (Site_Pages::owned_places()).
 *
 * Against a Home, a page, a menu and a block widget made for the purpose (spx-…); removes them and puts the stored footer back,
 * also when a check dies half-way. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Chrome\Footer_Template;
use DXAI_UI\Compiler\Internal_Links;
use DXAI_UI\Pages\Site_Pages;

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

$run     = 'spx-' . substr( md5( (string) microtime( true ) ), 0, 6 );
$made    = array();
$menu_id = 0;
$widget  = 0;
$footer  = get_option( Footer_Template::OPTION, null );
// Whatever happens below, the site is left as it was found.
$clean = static function () use ( &$made, &$menu_id, &$widget, $footer ): void {
	foreach ( $made as $id ) {
		wp_delete_post( (int) $id, true );
	}
	$made = array();
	if ( $menu_id > 0 ) {
		wp_delete_nav_menu( $menu_id );
		$menu_id = 0;
	}
	if ( $widget > 0 ) {
		$all = get_option( 'widget_block', array() );
		unset( $all[ $widget ] );
		update_option( 'widget_block', $all );
		$widget = 0;
	}
	if ( null === $footer ) {
		delete_option( Footer_Template::OPTION );
	} else {
		update_option( Footer_Template::OPTION, $footer );
	}
};
register_shutdown_function( $clean );

// A Home with a section to link to, and the new page of an old one.
$home = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Site pages fixture Home ' . $run,
		'post_name'    => $run . '-home',
		'post_content' => '<!-- wp:group {"anchor":"' . $run . '-reviews"} --><div id="' . $run . '-reviews" class="wp-block-group"><!-- wp:paragraph --><p>Reviews</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
	),
	true
);
$page = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		'post_title'  => 'About (new) ' . $run,
		'post_name'   => $run . '-about-new',
	),
	true
);
if ( is_wp_error( $home ) || is_wp_error( $page ) ) {
	echo "SKIP: could not make the fixture pages\n";
	exit( 77 );
}
$made[] = (int) $home;
$made[] = (int) $page;
$new    = (string) get_permalink( (int) $page );
$hurl   = (string) get_permalink( (int) $home );
$old    = 'https://old-' . $run . '.example';
// The old /about/ of the design's old site, as the crawl gives it: its path and its address under both of the old host's names.
$rows = array(
	array(
		'id'    => (int) $page,
		'paths' => array( '/' . $run . '-about/', '/' . $run . '-about', $old . '/' . $run . '-about/', str_replace( 'https://', 'https://www.', $old ) . '/' . $run . '-about/' ),
	),
);

echo "\nWhich links name the old site\n";
$probe = '<a href="/' . $run . '-about/">a</a> <a href="' . $old . '/' . $run . '-about/">b</a> <a href="' . str_replace( 'https://', 'https://www.', $old ) . '/' . $run . '-about">c</a>';
$only  = ( new Internal_Links() )->rewrite_html( $probe, $rows, true );
$expect( 'with addresses only, a bare path stays and both of the old host\'s names open the new page', str_contains( $only, 'href="/' . $run . '-about/"' ) && 2 === substr_count( $only, 'href="' . $new . '"' ), $only );
$all_links = ( new Internal_Links() )->rewrite_html( $probe, $rows );
$expect( 'without it (the design\'s own markup) the bare path opens the new page too', 3 === substr_count( $all_links, 'href="' . $new . '"' ), $all_links );

echo "\nWhat is the design's own\n";
delete_option( Footer_Template::OPTION );
$expect( 'no stored footer: the block widgets are no design\'s', false === Site_Pages::owned_places( (int) $home )['widgets'] );
update_option( Footer_Template::OPTION, array( 'template' => '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->', 'slots' => array(), 'page_id' => (int) $home + 100000 ) );
$expect( 'another design\'s stored footer: the block widgets are not this design\'s', false === Site_Pages::owned_places( (int) $home )['widgets'] );
update_option( Footer_Template::OPTION, array( 'template' => '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->', 'slots' => array(), 'page_id' => (int) $home ) );
$expect( 'its own stored footer: the block widgets are this design\'s', true === Site_Pages::owned_places( (int) $home )['widgets'] );
$expect( 'a design with no header written as menus has no menus of its own', array() === Site_Pages::owned_places( (int) $home )['menus'] );

echo "\nBlock widgets\n";
$content = '<!-- wp:paragraph --><p><a href="/' . $run . '-about/">About</a> <a href="' . $old . '/' . $run . '-about/">About (old address)</a> <a href="#' . $run . '-reviews">Reviews</a></p><!-- /wp:paragraph -->';
$all     = get_option( 'widget_block', array() );
$all     = is_array( $all ) ? $all : array();
$widget  = max( array_merge( array( 900 ), array_filter( array_keys( $all ), 'is_int' ) ) ) + 1;
$all[ $widget ] = array( 'content' => $content );
update_option( 'widget_block', $all );
$read = static fn() => (string) ( get_option( 'widget_block', array() )[ $widget ]['content'] ?? '' );

// Another design's footer: its "/about/" and "#reviews" are this site's.
update_option( Footer_Template::OPTION, array( 'template' => '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->', 'slots' => array(), 'page_id' => (int) $home + 100000 ) );
Site_Pages::rewrite_widgets( (int) $home, $rows, $hurl );
$after = $read();
$expect( 'in another design\'s widget a bare path ("/about/") stays: it is a page of this site', str_contains( $after, 'href="/' . $run . '-about/"' ), $after );
$expect( 'in another design\'s widget a link to the old site by its address opens the new page', str_contains( $after, 'href="' . $new . '"' ) && ! str_contains( $after, $old . '/' ), $after );
$expect( 'in another design\'s widget a section link ("#reviews") stays', str_contains( $after, 'href="#' . $run . '-reviews"' ), $after );

// The design's own footer widget: every link to an old page, and the Home's sections.
$all            = get_option( 'widget_block', array() );
$all[ $widget ] = array( 'content' => $content );
update_option( 'widget_block', $all );
update_option( Footer_Template::OPTION, array( 'template' => '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->', 'slots' => array(), 'page_id' => (int) $home ) );
Site_Pages::rewrite_widgets( (int) $home, $rows, $hurl );
$after = $read();
$expect( 'in the design\'s own widget the bare path of an old page opens the new page', ! str_contains( $after, 'href="/' . $run . '-about/"' ) && substr_count( $after, 'href="' . $new . '"' ) === 2, $after );
$expect( 'in the design\'s own widget a section link opens the Home\'s section from any page', str_contains( $after, 'href="' . $hurl . '#' . $run . '-reviews"' ), $after );

echo "\nMenus\n";
$menu_id = (int) wp_create_nav_menu( 'Another design ' . $run );
$add     = static fn( string $title, string $url ): int => (int) wp_update_nav_menu_item(
	$menu_id,
	0,
	array(
		'menu-item-title'  => $title,
		'menu-item-url'    => $url,
		'menu-item-type'   => 'custom',
		'menu-item-status' => 'publish',
	)
);
$i_anchor = $add( 'Reviews', '#' . $run . '-reviews' );
$i_rel    = $add( 'About', '/' . $run . '-about/' );
$i_abs    = $add( 'About (old)', $old . '/' . $run . '-about/' );
Site_Pages::rewrite_menus( (int) $home, $old, $rows, $hurl );
$item = static fn( int $id ): array => array( 'type' => (string) get_post_meta( $id, '_menu_item_type', true ), 'url' => (string) get_post_meta( $id, '_menu_item_url', true ), 'object_id' => (int) get_post_meta( $id, '_menu_item_object_id', true ) );
$expect( 'in another design\'s menu a section link ("#reviews") stays', '#' . $run . '-reviews' === $item( $i_anchor )['url'] && 'custom' === $item( $i_anchor )['type'], wp_json_encode( $item( $i_anchor ) ) );
$expect( 'in another design\'s menu a bare path stays', '/' . $run . '-about/' === $item( $i_rel )['url'] && 'custom' === $item( $i_rel )['type'], wp_json_encode( $item( $i_rel ) ) );
$expect( 'in any menu a link to the old site by its address becomes the new page', 'post_type' === $item( $i_abs )['type'] && (int) $page === $item( $i_abs )['object_id'], wp_json_encode( $item( $i_abs ) ) );

echo "\nAfter the design is imported again\n";
// The Home as an import writes it from the archive: its links lead to the old site; one page was built from that site before.
$wp_home = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Relink fixture Home ' . $run,
		'post_name'    => $run . '-relink-home',
		'post_content' => '<!-- wp:paragraph --><p><a href="' . $old . '/' . $run . '-svc/">Our service</a> <a href="' . $old . '/">Home</a></p><!-- /wp:paragraph -->',
	),
	true
);
$built = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		'post_title'  => 'Service (built from the old site) ' . $run,
		'post_name'   => $run . '-svc-built',
	),
	true
);
if ( ! is_wp_error( $wp_home ) && ! is_wp_error( $built ) ) {
	$made[] = (int) $wp_home;
	$made[] = (int) $built;
	update_post_meta( (int) $wp_home, Site_Pages::ORIGIN_META, $old );
	$expect( 'a design with no pages built from its old site: nothing to lead', 0 === Site_Pages::relink( (int) $wp_home ) && str_contains( (string) get_post_field( 'post_content', (int) $wp_home ), $old . '/' ) );
	update_post_meta( (int) $built, '_dxai_ui_from_live_menu', '1' );
	update_post_meta( (int) $built, \DXAI_UI\Structures\Page_Scope::META, (string) $wp_home );
	update_post_meta( (int) $built, '_dxai_ui_source_route', $run . '-svc' );
	$led = Site_Pages::relink( (int) $wp_home );
	$now = (string) get_post_field( 'post_content', (int) $wp_home );
	$expect( 'the old site\'s own home leads to the design\'s Home', str_contains( $now, 'href="' . get_permalink( (int) $wp_home ) . '"' ), $now );
	$expect( 'the Home\'s link to the old page leads to the page built from it again', 1 === $led && str_contains( $now, 'href="' . get_permalink( (int) $built ) . '"' ) && ! str_contains( $now, $old . '/' ), $now );
	// The DX template draws the parts itself: they are the design's by its part key, not by a block in the Home.
	$part = wp_insert_post(
		array(
			'post_type'    => 'wp_template_part',
			'post_status'  => 'publish',
			'post_title'   => 'Header ' . $run,
			'post_name'    => 'dxai-header-' . $run . '-key',
			'post_content' => '<!-- wp:paragraph --><p><a href="' . $old . '/' . $run . '-svc/">Service</a></p><!-- /wp:paragraph -->',
		),
		true
	);
	if ( ! is_wp_error( $part ) ) {
		$made[] = (int) $part;
		update_post_meta( (int) $wp_home, '_dxai_ui_part_key', $run . '-key' );
		Site_Pages::relink( (int) $wp_home );
		$in_part = (string) get_post_field( 'post_content', (int) $part );
		$expect( 'the header part the DX template draws (named by the design\'s part key) leads to the built page too', str_contains( $in_part, 'href="' . get_permalink( (int) $built ) . '"' ), $in_part );
	}
	$expect( 'an import of a design calls it at its end (Structure_Repository::save())', str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/src/Structures/Structure_Repository.php' ), 'Site_Pages::relink( (int) $page_id )' ) );
}

$clean();
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );

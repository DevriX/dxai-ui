<?php
/**
 * The whole site seen before its pages go live (Network\Site_Review; docs/PLAN-NETWORK-PAGES.md, section 11, point 6).
 *
 *   bash bin/wp-php.sh bin/verify-site-review.php        (or: wp eval-file bin/verify-site-review.php --user=1)
 *
 * A design's Home is made, the review is held, and pages are made the two ways an import makes the pages its menu names:
 * from the old site (Site_From_Menu::save_chrome_page) and from the Home's own sections (Team_Pages::shell). While it holds,
 * a page made new is a draft — a page there already keeps its status — whose link is the one it will have once published
 * (with pretty permalinks, not `?page_id=N`), under the slug it will have then (one a page of the site's own has taken is
 * not its). The review lists the Home first and every page of the design with its status, its preview, whether the import
 * made it and how it measures. The choice publishes the chosen pages and the pages they sit under, and moves to the trash
 * only the ones the import made — not a page of the site's own, not one a published page sits under; a link to a page
 * whose published address differs from its draft's follows it; the hold ends. The route refuses a page that is not a
 * design, an action it does not know, and a visitor.
 *
 * Everything is deleted after, also when a check dies half-way, and the permalink structure and the hold are put back.
 * Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Compiler\Gutenberg_Mapper;
use DXAI_UI\Network\Site_Review;
use DXAI_UI\Pages\Team_Pages;
use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Structures\Site_From_Menu;

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

$run   = 'review-' . substr( md5( (string) microtime( true ) ), 0, 6 );
$made  = array();
$menu  = 0;
$keep  = array(
	'structure' => (string) get_option( 'permalink_structure' ),
	'hold'      => get_option( Site_Review::HOLD, null ),
);
$clean = static function () use ( &$made, &$menu, $keep, $run ): void {
	global $wp_rewrite;
	$wp_rewrite->set_permalink_structure( $keep['structure'] );
	if ( $keep['hold'] === null ) {
		delete_option( Site_Review::HOLD );
	} else {
		update_option( Site_Review::HOLD, $keep['hold'], false );
	}
	foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_dxai_review_suite', 'meta_value' => $run ) ) as $id ) {
		$made[] = (int) $id;
	}
	// Pages the import's own code made in this run carry the run's name in their title.
	foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'draft', 'trash', 'private', 'pending' ), 'numberposts' => -1, 'fields' => 'ids', 's' => $run ) ) as $id ) {
		$made[] = (int) $id;
	}
	foreach ( array_unique( $made ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
	$made = array();
	if ( $menu > 0 ) {
		wp_delete_nav_menu( $menu );
		$menu = 0;
	}
};
register_shutdown_function( $clean );

$page = static function ( string $title, string $status = 'publish', int $parent = 0, string $slug = '', string $content = '' ) use ( &$made, $run ): int {
	$id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => $status,
			'post_title'   => $title . ' ' . $run,
			'post_name'    => $slug !== '' ? $slug : sanitize_title( $title . '-' . $run ),
			'post_parent'  => $parent,
			'post_content' => $content !== '' ? $content : '<!-- wp:paragraph --><p>' . $title . '</p><!-- /wp:paragraph -->',
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		echo '  ERROR ' . $id->get_error_message() . "\n";
		exit( 1 );
	}
	$made[] = (int) $id;
	update_post_meta( (int) $id, '_dxai_review_suite', $run );

	return (int) $id;
};
// A page the import made for this design (as Site_From_Menu or Team_Pages leave it).
$mine = static function ( int $id, int $home ): int {
	update_post_meta( $id, Page_Scope::META, $home );
	update_post_meta( $id, '_dxai_ui_generated_page', '1' );

	return $id;
};

global $wp_rewrite;
$wp_rewrite->set_permalink_structure( '/%postname%/' );

$home = $page( 'Home' );
update_post_meta( $home, Page_Scope::META, $home );
update_post_meta( $home, '_dxai_ui_css_url', 'https://example.test/dxai-ui/' . $run . '.css' );
$taken = $page( 'Taken', 'publish', 0, 'review-taken-' . $run );

echo "The hold\n";
Site_Review::release();
$expect( 'without a hold a page an import makes is published', apply_filters( 'dxai_ui_new_page_status', 'publish', $home ) === 'publish' && ! Site_Review::holding() );
Site_Review::hold();
$expect( 'held, it is a draft', apply_filters( 'dxai_ui_new_page_status', 'publish', $home ) === 'draft' && Site_Review::holding() );
update_option( Site_Review::HOLD, time() - 1, false );
$expect( 'a hold that ran out holds nothing', ! Site_Review::holding() && apply_filters( 'dxai_ui_new_page_status', 'publish', $home ) === 'publish' );
// A hold for one archive: its design's pages are drafts, another import's are published (the screen that asked was closed).
update_post_meta( $home, '_dxai_ui_source_zip', 'Held ' . $run . '.zip' );
$other_home = $page( 'Other Home' );
update_post_meta( $other_home, '_dxai_ui_source_zip', 'Other ' . $run . '.zip' );
Site_Review::hold( 'Held ' . $run . '.zip' );
$expect( 'held for an archive, its design\'s new pages are drafts', apply_filters( 'dxai_ui_new_page_status', 'publish', $home ) === 'draft' );
$expect( '…and another design\'s, or a page of no design, are published', apply_filters( 'dxai_ui_new_page_status', 'publish', $other_home ) === 'publish' && apply_filters( 'dxai_ui_new_page_status', 'publish', 0 ) === 'publish' );
update_option( Site_Review::HOLD, time() + 600, false );
$expect( 'a hold written by the plugin before this (a bare time) still holds every design', apply_filters( 'dxai_ui_new_page_status', 'publish', $other_home ) === 'draft' );
Site_Review::release();

echo "Pages from the old site (Site_From_Menu)\n";
$crawl = new Site_From_Menu();
$save  = new ReflectionMethod( Site_From_Menu::class, 'save_chrome_page' );
$save->setAccessible( true );
$put = static function ( string $title, string $path ) use ( $crawl, $save, $home, $run ) {
	return $save->invoke( $crawl, $title . ' ' . $run, '<!-- wp:paragraph --><p>' . $title . '</p><!-- /wp:paragraph -->', $path, $home, '', array(), '', 'dxai-ui-' . $run, false, 'suite-' . $run . '.zip', new Gutenberg_Mapper() );
};
Site_Review::release();
$live = $put( 'Live Before', '/live-before-' . $run . '/' );
$expect( 'without a hold the page is published, as before', is_int( $live ) && get_post_status( $live ) === 'publish', is_wp_error( $live ) ? $live->get_error_message() : '' );
Site_Review::hold();
$crawled = $put( 'Water Damage', '/water-damage-' . $run . '/' );
$expect( 'held, a page made new is a draft', is_int( $crawled ) && get_post_status( $crawled ) === 'draft', is_wp_error( $crawled ) ? $crawled->get_error_message() : (string) get_post_status( (int) $crawled ) );
$again = $put( 'Water Damage', '/water-damage-' . $run . '/' );
$expect( '…and made again while it holds, it stays one (the same page)', $again === $crawled && get_post_status( $crawled ) === 'draft' );
$live_again = $put( 'Live Before', '/live-before-' . $run . '/' );
$expect( 'a published page made again while it holds stays published', $live_again === $live && get_post_status( $live ) === 'publish' );
$link = (string) get_permalink( $crawled );
$expect( 'a held draft answers with its address, not ?page_id', ! str_contains( $link, 'page_id=' ) && str_ends_with( untrailingslashit( $link ), (string) get_post_field( 'post_name', $crawled ) ), $link );
Site_Review::release();
$expect( 'without the hold the draft\'s link is WordPress\'s own again', str_contains( (string) get_permalink( $crawled ), 'page_id=' ) );
$put( 'Water Damage', '/water-damage-' . $run . '/' );
$expect( 'and a draft made again without a hold is published, as before', get_post_status( $crawled ) === 'publish' );
wp_update_post(
	array(
		'ID'          => $crawled,
		'post_status' => 'draft',
	)
);

echo "Pages from the Home's sections (Team_Pages)\n";
$shell = new ReflectionMethod( Team_Pages::class, 'shell' );
$shell->setAccessible( true );
Site_Review::hold();
$item  = array(
	'type'  => 'about',
	'slug'  => 'about',
	'title' => 'About ' . $run,
	'name'  => 'review-taken-' . $run,
	'path'  => '/review-taken-' . $run . '/',
);
$about = $shell->invoke( null, $home, $item, array( $item ) );
$expect( 'held, a page made from the Home\'s sections is a draft', is_int( $about ) && get_post_status( $about ) === 'draft', is_wp_error( $about ) ? $about->get_error_message() : '' );
if ( is_int( $about ) ) {
	$mine( $about, $home );
	$expect( '…whose slug is not the one a page of the site\'s own holds', get_post_field( 'post_name', $about ) !== 'review-taken-' . $run && str_starts_with( (string) get_post_field( 'post_name', $about ), 'review-taken-' . $run ), (string) get_post_field( 'post_name', $about ) );
	$held_link = (string) get_permalink( $about );
}
Site_Review::release();
$item2 = array(
	'type'  => 'contact',
	'slug'  => 'contact',
	'title' => 'Contact ' . $run,
	'name'  => 'contact-' . $run,
	'path'  => '/contact-' . $run . '/',
);
$contact = $shell->invoke( null, $home, $item2, array( $item2 ) );
$expect( 'without a hold it is published, as before', is_int( $contact ) && get_post_status( $contact ) === 'publish' );
if ( is_int( $contact ) ) {
	$mine( $contact, $home );
}

echo "The review\n";
Site_Review::hold();
$parent = $mine( $page( 'Services', 'draft' ), $home );
$child  = $mine( $page( 'Fire Damage', 'draft', $parent ), $home );
$solo   = $mine( $page( 'Sewage Cleanup', 'draft' ), $home );
$own    = $page( 'Own Draft', 'draft' );
update_post_meta( $own, Page_Scope::META, $home );
$under  = $mine( $page( 'Service Areas', 'draft' ), $home );
$lived  = $mine( $page( 'Lacey', 'publish', $under ), $home );
$other  = $mine( $page( 'Not This Design', 'draft' ), $taken );
// The Home's hero photograph is on every page of the design; what stands for a page is a picture of its own.
$hero = '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.test/hero.jpg" alt=""/></figure><!-- /wp:image -->';
wp_update_post( array( 'ID' => $home, 'post_content' => $hero ) );
wp_update_post( array( 'ID' => $child, 'post_content' => $hero . '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.test/icons/flame.svg" alt=""/></figure><!-- /wp:image --><!-- wp:image --><figure class="wp-block-image"><img src="https://example.test/fire-crew.jpg" alt=""/></figure><!-- /wp:image -->' ) );
wp_update_post( array( 'ID' => $solo, 'post_content' => $hero ) );
$view   = Site_Review::pages( $home );
$rows   = array();
foreach ( $view['pages'] as $row ) {
	$rows[ $row['id'] ] = $row;
}
$expect( 'the Home comes first', ( $view['pages'][0]['id'] ?? 0 ) === $home && $view['pages'][0]['home'] === true && $view['pages'][0]['quality'] === null );
$expect( 'every page of the design is listed, no other', isset( $rows[ $crawled ], $rows[ $parent ], $rows[ $child ], $rows[ $own ], $rows[ $lived ] ) && ! isset( $rows[ $other ] ) );
$expect( 'a draft the import made: status, made, a preview and a measure', ( $rows[ $child ]['status'] ?? '' ) === 'draft' && ( $rows[ $child ]['made'] ?? false ) === true && str_contains( (string) ( $rows[ $child ]['view'] ?? '' ), 'preview=true' ) && is_array( $rows[ $child ]['quality'] ?? null ) && isset( $rows[ $child ]['quality']['grade'] ) && ( $rows[ $child ]['parent'] ?? 0 ) === $parent );
$expect( 'a page of the site\'s own is not one the import made', ( $rows[ $own ]['made'] ?? true ) === false );
$expect( 'a page stands in the list by a picture of its own, not the Home\'s hero nor an icon', ( $rows[ $child ]['picture'] ?? '' ) === 'https://example.test/fire-crew.jpg' && ( $rows[ $home ]['picture'] ?? '' ) === 'https://example.test/hero.jpg', (string) ( $rows[ $child ]['picture'] ?? '' ) );
$expect( '…and by the Home\'s when it has none of its own', ( $rows[ $solo ]['picture'] ?? '' ) === 'https://example.test/hero.jpg' );
$expect( 'the review says it holds', $view['holding'] === true );

// The Home links to the page that will go: by the address it answers with, and by WordPress's own form of a draft's link.
update_post_meta( $home, '_dxai_ui_site_origin', 'https://old.example.test' );
update_post_meta( $solo, '_dxai_ui_source_route', 'services/sewage-cleanup' );
$solo_link = (string) get_permalink( $solo );
$longer    = home_url( '?page_id=' . $solo . '7' );
wp_update_post(
	array(
		'ID'           => $home,
		'post_content' => '<!-- wp:paragraph --><p><a href="' . esc_url( $solo_link ) . '">Sewage</a> <a href="' . esc_url( home_url( '?page_id=' . $solo ) ) . '">again</a> <a href="' . esc_url( $longer ) . '">another page</a></p><!-- /wp:paragraph -->',
	)
);
$out = Site_Review::decide( $home, array( $child, $other ), array( $solo, $own, $under, $parent ) );
$home_now = (string) get_post_field( 'post_content', $home );
$expect( 'the Home\'s links to a page that went lead to the old site\'s page it was made from again', substr_count( $home_now, 'https://old.example.test/services/sewage-cleanup/' ) === 2, $home_now );
$expect( '…and a link that only begins like one of them is left', str_contains( $home_now, esc_url( $longer ) ) );
$expect( 'a chosen page is published', get_post_status( $child ) === 'publish' && in_array( $child, $out['published'], true ) );
$expect( '…and the page it sits under with it, though it was not ticked (and not trashed though asked)', get_post_status( $parent ) === 'publish' && in_array( $parent, $out['published'], true ) );
$expect( 'a page of another design is not published', get_post_status( $other ) === 'draft' );
$expect( 'a page the import made, not chosen, goes to the trash', get_post_status( $solo ) === 'trash' && in_array( $solo, $out['trashed'], true ) );
$expect( 'a page of the site\'s own is never trashed', get_post_status( $own ) === 'draft' && in_array( $own, $out['kept'], true ) );
$expect( 'a page a published one sits under stays', get_post_status( $under ) === 'draft' && in_array( $under, $out['kept'], true ) );
$expect( 'the choice ends the hold', ! Site_Review::holding() );
if ( isset( $held_link ) && is_int( $about ) ) {
	Site_Review::decide( $home, array( $about ), array() );
	$expect( 'the address a held page answered with is the one it has published', (string) get_permalink( $about ) === $held_link, $held_link . ' vs ' . get_permalink( $about ) );
}

echo "Publishing is not a person's edit\n";
Site_Review::hold();
$kept_fresh  = $put( 'Storm Damage', '/storm-damage-' . $run . '/' );
$kept_edited = $put( 'Roof Tarping', '/roof-tarping-' . $run . '/' );
// A person saved the second one in the editor after the import wrote it: its last change is newer than the import's stamp.
global $wpdb;
update_post_meta( (int) $kept_edited, Site_From_Menu::IMPORTED_AT, '2000-01-01 00:00:00' );
$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s' ) ), array( 'ID' => (int) $kept_edited ) );
clean_post_cache( (int) $kept_edited );
// The choice is made in a request of its own: the import's own writes of this request are no longer being stamped.
$written = new ReflectionProperty( Site_From_Menu::class, 'written' );
$written->setAccessible( true );
$written->setValue( null, array() );
$fresh_before = is_int( $kept_fresh ) && ! Site_From_Menu::edited_since_import( get_post( $kept_fresh ) );
Site_Review::decide( $home, array( (int) $kept_fresh, (int) $kept_edited ), array() );
clean_post_cache( (int) $kept_fresh );
clean_post_cache( (int) $kept_edited );
$expect( 'a page nobody edited is still one a later import rewrites once it is published', $fresh_before && get_post_status( (int) $kept_fresh ) === 'publish' && ! Site_From_Menu::edited_since_import( get_post( (int) $kept_fresh ) ) );
$expect( '…and one a person edited is still theirs', get_post_status( (int) $kept_edited ) === 'publish' && Site_From_Menu::edited_since_import( get_post( (int) $kept_edited ) ) );

echo "Links follow a page whose published address is not its draft's\n";
// A draft made before the hold took slugs as WordPress gives drafts theirs: one the site's own page holds.
$late  = $mine( $page( 'Late', 'draft', 0, 'review-taken-' . $run ), $home );
$draft = get_page_link( $late, false, true );
wp_update_post(
	array(
		'ID'           => $home,
		'post_content' => '<!-- wp:paragraph --><p><a href="' . esc_url( $draft ) . '">Late</a></p><!-- /wp:paragraph -->',
	)
);
$menu = (int) wp_create_nav_menu( 'Review ' . $run );
$item_id = (int) wp_update_nav_menu_item(
	$menu,
	0,
	array(
		'menu-item-title'  => 'Late',
		'menu-item-url'    => $draft,
		'menu-item-type'   => 'custom',
		'menu-item-status' => 'publish',
	)
);
Site_Review::decide( $home, array( $late ), array() );
$now = (string) get_permalink( $late );
$expect( 'the published page took a free address', $now !== $draft, $now );
$expect( 'the Home\'s link to it follows', str_contains( (string) get_post_field( 'post_content', $home ), esc_url( $now ) ) && ! str_contains( (string) get_post_field( 'post_content', $home ), '"' . esc_url( $draft ) . '"' ) );
$expect( 'the menu\'s link to it follows', (string) get_post_meta( $item_id, '_menu_item_url', true ) === esc_url_raw( $now ), (string) get_post_meta( $item_id, '_menu_item_url', true ) );

echo "The route\n";
$ask = static function ( string $method, array $params ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/dxai-ui/v1/site-review' );
	if ( $method === 'GET' ) {
		$request->set_query_params( $params );
	} else {
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $params ) );
	}

	return rest_do_request( $request );
};
$expect( 'GET lists the design', $ask( 'GET', array( 'home' => $home ) )->get_status() === 200 && ( $ask( 'GET', array( 'home' => $home ) )->get_data()['home'] ?? 0 ) === $home );
$expect( 'a page that is not a design: 404', $ask( 'GET', array( 'home' => $other ) )->get_status() === 404 );
$expect( 'an action it does not know: 400', $ask( 'POST', array( 'action' => 'publish-everything' ) )->get_status() === 400 );
$expect( 'hold and release through the route', $ask( 'POST', array( 'action' => 'hold' ) )->get_status() === 200 && Site_Review::holding() && $ask( 'POST', array( 'action' => 'release' ) )->get_status() === 200 && ! Site_Review::holding() );
$ask( 'POST', array( 'action' => 'hold', 'archive' => 'Held ' . $run . '.zip' ) );
$expect( '…the route holds only the design of the archive it is given', Site_Review::holds_for( $home ) && ! Site_Review::holds_for( $other_home ) );
Site_Review::release();
$user = get_current_user_id();
wp_set_current_user( 0 );
$expect( 'a visitor may not look or choose', in_array( $ask( 'GET', array( 'home' => $home ) )->get_status(), array( 401, 403 ), true ) && in_array( $ask( 'POST', array( 'action' => 'hold' ) )->get_status(), array( 401, 403 ), true ) && ! Site_Review::holding() );
wp_set_current_user( $user );

$clean();
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

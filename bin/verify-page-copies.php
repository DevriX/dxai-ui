<?php
/**
 * "Make the rest from this page" (Network\Page_Copies; docs/PLAN-NETWORK-PAGES.md, N1).
 *
 *   bash bin/wp-php.sh bin/verify-page-copies.php        (or: wp eval-file bin/verify-page-copies.php --user=1)
 *
 * A small site is made — a Home; Services with a finished Fire Damage Restoration page, an empty Water Damage page, a
 * copy of the fire page by hand (Mold Remediation), a page with its own content (Storm Damage); a menu that also names a
 * Sewage Cleanup page that does not exist — and the fire page is made again for the others: which of its sections are the
 * site's shared ones (word for word on the Home) and stay so, which are the page's own; the names put in place in the
 * case each line writes them; the list of services pointing back at the original; the words written by an AI (a stub
 * here: nothing is asked of a provider) that is shown how the network writes such a page and the client's old page, and
 * never the shared sections; without an AI the page's own lines left empty with the original as the hint, nothing about
 * fire shown; a page the menu names made; and every copy given back, but not one a person edited since.
 *
 * Everything is deleted after, also when a check dies half-way, and the front page and the model are put back. Exit code
 * 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Network\Network_Model;
use DXAI_UI\Network\Page_Copies;
use DXAI_UI\Pages\Copy_Writer;
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

$run   = 'copies-' . substr( md5( (string) microtime( true ) ), 0, 6 );
$made  = array();
$menu  = 0;
$keep  = array(
	'show_on_front' => get_option( 'show_on_front' ),
	'page_on_front' => get_option( 'page_on_front' ),
	'model'         => is_multisite() ? get_site_option( Network_Model::OPTION, null ) : get_option( Network_Model::OPTION, null ),
	'front'         => get_option( DXAI_UI\Network\Front_Page::BEFORE, null ),
);
$clean = static function () use ( &$made, &$menu, $keep, $run ): void {
	update_option( 'show_on_front', $keep['show_on_front'] );
	update_option( 'page_on_front', $keep['page_on_front'] );
	if ( $keep['front'] === null ) {
		delete_option( DXAI_UI\Network\Front_Page::BEFORE );
	} else {
		update_option( DXAI_UI\Network\Front_Page::BEFORE, $keep['front'], false );
	}
	if ( $keep['model'] === null ) {
		is_multisite() ? delete_site_option( Network_Model::OPTION ) : delete_option( Network_Model::OPTION );
	} else {
		is_multisite() ? update_site_option( Network_Model::OPTION, $keep['model'] ) : update_option( Network_Model::OPTION, $keep['model'], false );
	}
	// The pages of this run, and any page it made (a copy the menu named) — found by the run's name in their address too.
	foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_dxai_copies_suite', 'meta_value' => $run ) ) as $id ) {
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

$page = static function ( string $title, string $content, int $parent = 0, string $slug = '' ) use ( &$made, $run ): int {
	$id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_name'    => $slug !== '' ? $slug : sanitize_title( $title ) . '-' . $run,
			'post_parent'  => $parent,
			'post_content' => $content,
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		echo '  ERROR ' . $id->get_error_message() . "\n";
		exit( 1 );
	}
	$made[] = (int) $id;
	update_post_meta( (int) $id, '_dxai_copies_suite', $run );

	return (int) $id;
};
$group = static fn( string $inner, string $name = '' ): string => '<!-- wp:group {"tagName":"section"' . ( $name !== '' ? ',"metadata":{"name":"' . $name . '"}' : '' ) . '} --><section class="wp-block-group">' . $inner . '</section><!-- /wp:group -->';
$h     = static fn( int $l, string $t ): string => '<!-- wp:heading' . ( $l !== 2 ? ' {"level":' . $l . '}' : '' ) . ' --><h' . $l . ' class="wp-block-heading">' . $t . '</h' . $l . '><!-- /wp:heading -->';
$p     = static fn( string $t ): string => '<!-- wp:paragraph --><p>' . $t . '</p><!-- /wp:paragraph -->';
$img   = '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.test/fire.jpg" alt="Fire Damage Restoration crew at work"/></figure><!-- /wp:image -->';
$list  = static fn( array $items ): string => '<!-- wp:list {"ordered":true} --><ol class="wp-block-list">' . implode( '', array_map( static fn( string $i ): string => '<!-- wp:list-item --><li>' . $i . '</li><!-- /wp:list-item -->', $items ) ) . '</ol><!-- /wp:list -->';
$faq   = static fn( array $qa ): string => implode( '', array_map( static fn( string $q, string $a ): string => '<!-- wp:details --><details class="wp-block-details"><summary>' . $q . '</summary>' . '<!-- wp:paragraph --><p>' . $a . '</p><!-- /wp:paragraph --></details><!-- /wp:details -->', array_keys( $qa ), $qa ) );

$reviews = $group( $h( 2, 'What our neighbors say' ) . $p( 'Polar Restoration came fast, dried our basement in three days and handled the insurance for us.' ), 'Reviews' );
$cta     = $group( $h( 2, 'Water won’t wait. Neither do we.' ) . $p( 'Call <a href="tel:5550100100">(555) 010-0100</a> any hour of the day.' ) . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="tel:5550100100">Call now</a></div><!-- /wp:button --></div><!-- /wp:buttons -->' );
$form    = $group( $h( 2, 'Request a free assessment' ) . $p( 'Tell us what happened and our crew calls you back within the hour.' ) );

$home = $page( 'Polar Restoration', $group( $h( 1, 'Restoration in Fairbanks, done right' ) . $p( 'Water, fire and mold: one crew from the first call to the last repair.' ) ) . $reviews . $cta . $form );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $home );
update_post_meta( $home, Site_Pages::ORIGIN_META, 'https://old.example' );
$services = $page( 'Services', '', 0, 'services-' . $run );
$water    = $page( 'Water Damage Restoration', '', $services );
$mold     = $page( 'Mold Remediation', '', $services );
$storm    = $page( 'Storm Damage', $group( $h( 1, 'Storm Damage Repair' ) . $p( str_repeat( 'Wind and hail tear roofs and siding; our crew tarps, dries and rebuilds what the storm broke. ', 4 ) ) ), $services );
$fire     = $page( 'Fire Damage Restoration', '', $services );

$fire_content = $group( $h( 1, '24/7 Fire Damage Restoration in Fairbanks' ) . $p( 'Our fire damage restoration crews arrive within the hour, day or night.' ), 'Hero' )
	. $group( '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column">' . $h( 2, 'How our fire damage restoration protects your home' ) . $p( 'Soot and smoke keep working after the flames are out: they stain walls, corrode metal and leave an odor that settles into every room. We stop it the same day.' ) . '</div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column">' . $img . '</div><!-- /wp:column --></div><!-- /wp:columns -->' )
	. $group( $h( 2, 'Our Fire Damage Restoration Process' ) . $list( array( 'Board up and secure the house', 'Remove soot from every surface', 'Clean the smoke out of the air', 'Rebuild what the FIRE DAMAGE took' ) ) )
	. $reviews
	. $group( $h( 2, 'Related services' ) . '<!-- wp:columns --><div class="wp-block-columns">' . implode( '', array_map( static fn( int $id ): string => '<!-- wp:column --><div class="wp-block-column">' . '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading"><a href="' . esc_url( (string) get_permalink( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a></h3><!-- /wp:heading --></div><!-- /wp:column -->', array( $water, $mold, $storm ) ) ) . '</div><!-- /wp:columns -->' )
	. $group( $h( 2, 'Fire Damage Restoration FAQs' ) . $faq( array( 'How long does fire damage restoration take?' => 'Most homes take one to three weeks.', 'Will the smoke smell come back?' => 'Not once the air and the surfaces are clean.' ) ) )
	. $cta
	. $form;
global $wpdb;
$wpdb->update( $wpdb->posts, array( 'post_content' => $fire_content ), array( 'ID' => $fire ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
clean_post_cache( $fire );
update_post_meta( $fire, '_wp_page_template', 'page-fixture.php' );
// Mold Remediation: the fire page copied by hand, still saying "Fire Damage Restoration".
$wpdb->update( $wpdb->posts, array( 'post_content' => $fire_content ), array( 'ID' => $mold ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
clean_post_cache( $mold );
update_post_meta( $mold, '_dxai_ui_source_route', 'mold-remediation' );

$menu = (int) wp_create_nav_menu( 'Copies suite ' . $run );
$top  = (int) wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'Services', 'menu-item-url' => (string) get_permalink( $services ), 'menu-item-status' => 'publish', 'menu-item-type' => 'custom' ) );
foreach ( array( $fire, $water, $mold, $storm ) as $id ) {
	wp_update_nav_menu_item( $menu, 0, array( 'menu-item-object-id' => $id, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-parent-id' => $top, 'menu-item-status' => 'publish' ) );
}
// The menu's address for a page there is not yet, under Services (the way the site writes it with pretty permalinks).
$sewage_url = home_url( '/' . get_page_uri( $services ) . '/sewage-cleanup-' . $run . '/' );
wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'Sewage Cleanup', 'menu-item-url' => $sewage_url, 'menu-item-status' => 'publish', 'menu-item-type' => 'custom', 'menu-item-parent-id' => $top ) );

echo "The original's sections\n";
$targets = Page_Copies::targets( $fire );
$by      = array_column( $targets, null, 'title' );
$plan    = Page_Copies::sections( $fire, array_column( array_filter( $targets, static fn( array $t ): bool => $t['state'] !== 'own' ), 'id' ) );
$what    = array_map( static fn( array $s ): string => $s['what'] . ( $s['why'] !== '' ? '/' . $s['why'] : '' ), $plan );
$expect( 'the hero, the intro, the process and the FAQ are the page\'s own; the reviews are never written again; the call to action and the form are the Home\'s; the list of the other services is the related one', $what === array( 'page', 'page', 'page', 'global/reviews', 'related', 'page', 'global/home', 'global/home' ), implode( ',', $what ) );
// Two pages of one kind of section each, read as the original would be (deleted with the rest).
$probe = static function ( string $markup ) use ( &$made ): array {
	$id     = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Copies probe', 'post_content' => $markup ) );
	$made[] = $id;

	return array_column( Page_Copies::sections( $id ), 'what' );
};
$long  = $group( $h( 2, 'Start' ) . $p( str_repeat( 'words ', 130 ) ) );
$got   = $probe( $long . $group( $h( 2, 'What Polar Restoration customers say' ) . $p( 'They were fast and kind, and the house smells like home again.' ) ) );
$expect( 'reviews under a heading of the site\'s own ("What Polar Restoration customers say") are kept too', $got === array( 'page', 'global' ), implode( ',', $got ) );
$got = $probe( $long . $group( $h( 2, 'Types of fire damage services we provide' ) . '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column">' . $h( 3, 'Kitchen fires' ) . $p( 'Grease and smoke in the kitchen.' ) . '</div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column">' . $h( 3, 'Electrical fires' ) . $p( 'Wiring, soot and smoke.' ) . '</div><!-- /wp:column --></div><!-- /wp:columns -->' ) );
$expect( 'a section that says "services we provide" and links to no other page is the page\'s own, not the list of services', $got === array( 'page', 'page' ), implode( ',', $got ) );
$expect( 'the page is a service', Page_Copies::kind( $fire ) === 'service', Page_Copies::kind( $fire ) );

echo "\nThe pages it is made again for\n";
$state = static fn( string $t ): string => (string) ( $by[ $t ]['state'] ?? '-' ) . ( ! empty( $by[ $t ]['chosen'] ) ? '+' : '' );
$expect( 'the empty page, the copy by hand and the page the menu names are chosen; the page with its own content is not', $state( 'Water Damage Restoration' ) === 'empty+' && $state( 'Mold Remediation' ) === 'copy+' && $state( 'Storm Damage' ) === 'own' && $state( 'Sewage Cleanup' ) === 'menu+', implode( ', ', array_map( static fn( array $t ): string => $t['title'] . ' ' . $t['state'] . ( $t['chosen'] ? '+' : '' ), $targets ) ) );
$expect( 'the original and the Home are not among them', ! isset( $by['Fire Damage Restoration'] ) && ! isset( $by['Polar Restoration'] ) );

echo "\nNames\n";
$pairs = Page_Copies::swaps( 'Fire Damage Restoration', 'Water Damage Restoration', 'service' );
$expect( 'the service, then its short form', $pairs === array( 'Fire Damage Restoration' => 'Water Damage Restoration', 'Fire Damage' => 'Water Damage' ), wp_json_encode( $pairs ) );
$line = Page_Copies::swap_text( 'Our Fire Damage Restoration Process: fire damage restoration in a day. FIRE DAMAGE help. Fire damage restoration crews. Firefighters stay.', $pairs );
$expect( 'each in the case the line writes it (Title Case, lower case, UPPER CASE, a sentence), never inside another word', $line === 'Our Water Damage Restoration Process: water damage restoration in a day. WATER DAMAGE help. Water damage restoration crews. Firefighters stay.', $line );
$pairs = Page_Copies::swaps( 'Fire &#038; Smoke Damage', 'Storm Damage Restoration', 'service' );
$expect( 'a title without the kind of work ("Fire & Smoke Damage") gives its core to the other\'s core, also as "Fire Damage" and "Fire and Smoke Damage"', $pairs === array( 'Fire & Smoke Damage' => 'Storm Damage', 'Fire Damage' => 'Storm Damage', 'Fire and Smoke Damage' => 'Storm Damage' ), wp_json_encode( $pairs ) );
$c =Page_Copies::swap_block( parse_blocks( $h( 1, 'Emergency Fire &amp; Smoke Damage Restoration' ) )[0], $pairs );
$expect( '…in the markup too, where the ampersand is &amp;: "Emergency Storm Damage Restoration", "Types of Storm Damage Restoration Services"', str_contains( serialize_block( $c ), '>Emergency Storm Damage Restoration<' ) && str_contains( serialize_block( Page_Copies::swap_block( parse_blocks( $h( 2, 'Types of Fire Damage Restoration Services' ) )[0], $pairs ) ), '>Types of Storm Damage Restoration Services<' ), serialize_block( $c ) );
$places = 0;
$stale  = Page_Copies::outline( parse_blocks( $h( 2, 'Fire, Smoke &amp; Soot Damage Cleanup' ) )[0], 'Storm Damage Restoration', $places, '/fire|smoke|soot/' );
$fine   = Page_Copies::outline( parse_blocks( $h( 2, 'Storm Damage Cleanup' ) )[0], 'Storm Damage Restoration', $places, '/fire|smoke|soot/' );
$expect( 'without an AI, a title that still speaks of fire once the names are in place waits for a person; one that does not, stays', ! str_contains( serialize_block( $stale ), 'Soot Damage Cleanup</h2>' ) && str_contains( (string) ( $stale['attrs']['placeholder'] ?? '' ), 'Fire, Smoke & Soot Damage Cleanup' ) && str_contains( serialize_block( $fine ), '>Storm Damage Cleanup</h2>' ) && $places === 1, serialize_block( $stale ) );
$places = 0;
$lines  = Page_Copies::outline( parse_blocks( $group( $p( 'Free inspections' ) . $p( 'Call <a href="tel:5550100100">(555) 010-0100</a> for a fire crew now' ) . $p( 'Soot cleanup in a day' ) . $p( 'Our crews answer every call within the hour, and they stay until the house is dry and safe again.' ) ) )[0], 'Water Damage Restoration', $places, '/fire|smoke|soot/', array( 'Fire Damage' => 'Water Damage' ) );
$kept   = serialize_block( $lines );
$expect( 'without an AI, a short line that fits any service stays, and so does the phone; a line of the original\'s service and a line of running text wait', str_contains( $kept, '>Free inspections<' ) && str_contains( $kept, '(555) 010-0100</a> for a fire crew now' ) && ! str_contains( $kept, '>Soot cleanup in a day<' ) && ! str_contains( $kept, 'safe again.</p>' ) && $places === 2, $kept );
$places = 0;
$steps  = Page_Copies::outline( parse_blocks( $group( $p( 'Free inspections' ) ) )[0], 'Water Damage Restoration', $places, '', array(), true );
$expect( '…in a FAQ or the steps of a process, every line waits', ! str_contains( serialize_block( $steps ), '>Free inspections<' ) && $places === 1 );
$places = 0;
$dx_text = Page_Copies::outline( parse_blocks( '<!-- wp:dxai-ui/text {"tagName":"p","className":"lead"} --><p class="lead">Smoke travels into rooms the fire never reached.</p><!-- /wp:dxai-ui/text -->' )[0], 'Water Damage Restoration', $places, '/fire|smoke|soot/' );
$expect( '…a run of the design\'s own text (DX Text) too, with the original as its hint', str_contains( serialize_block( $dx_text ), '<p class="lead"></p>' ) && str_contains( (string) ( $dx_text['attrs']['placeholder'] ?? '' ), 'The original says: Smoke travels' ) && $places === 1, serialize_block( $dx_text ) );
$city = $page( 'North Pole', '', 0, 'water-damage-north-pole-ak' );
$expect( 'a page whose address ends with a state is a city\'s ("water-damage-north-pole-ak")', Page_Copies::kind( $city ) === 'location', Page_Copies::kind( $city ) );
$expect( 'a city by a city',Page_Copies::swaps( 'Restoration in Fairbanks, AK', 'Restoration in North Pole, AK', 'location' ) === array( 'Fairbanks' => 'North Pole' ), wp_json_encode( Page_Copies::swaps( 'Restoration in Fairbanks, AK', 'Restoration in North Pole, AK', 'location' ) ) );

echo "\nWithout an AI: the page's own lines wait for a person\n";
$r    = Page_Copies::make( $fire, array( 'id' => $water ), $plan, array( 'words' => 'outline' ) );
$got  = is_wp_error( $r ) ? '' : (string) get_post_field( 'post_content', $water );
$secs = array_values( array_filter( parse_blocks( $got ), static fn( $b ) => ! empty( $b['blockName'] ) ) );
$src  = array_values( array_filter( parse_blocks( $fire_content ), static fn( $b ) => ! empty( $b['blockName'] ) ) );
$expect( 'made: 8 sections, 4 kept, 4 written, its own lines to write', ! is_wp_error( $r ) && count( $secs ) === 8 && $r['kept'] === 4 && $r['written'] === 4 && $r['places'] >= 6 && $r['words'] === 'outline', is_wp_error( $r ) ? $r->get_error_message() : wp_json_encode( $r ) );
$expect( 'the h1 and the section titles say Water Damage Restoration', str_contains( $got, '24/7 Water Damage Restoration in Fairbanks' ) && str_contains( $got, 'Our Water Damage Restoration Process' ) && str_contains( $got, 'Water Damage Restoration FAQs' ) && str_contains( $got, 'How our water damage restoration protects your home' ) );
$expect( 'the reviews, the call to action and the form are the original\'s blocks, word for word', count( $secs ) === 8 && serialize_block( $secs[3] ) === serialize_block( $src[3] ) && serialize_block( $secs[6] ) === serialize_block( $src[6] ) && serialize_block( $secs[7] ) === serialize_block( $src[7] ) );
$shown = '';
foreach ( array( 0, 1, 2, 5 ) as $i ) {
	$shown .= isset( $secs[ $i ] ) ? wp_strip_all_tags( render_block( $secs[ $i ] ) ) : '';
}
$expect( 'nothing of the page\'s own sections says fire, soot or smoke to a visitor', $shown !== '' && preg_match( '/fire|soot|smoke/i', $shown ) !== 1, mb_substr( $shown, 0, 200 ) );
$expect( '…the original\'s lines are the hints the editor shows ("Write for Water Damage Restoration. The original says: …")', str_contains( $got, 'Write for Water Damage Restoration. The original says: Soot and smoke keep working' ) );
$expect( '…in the FAQ and the steps of the process every line waits, a short one too ("Most homes take one to three weeks.", "Board up and secure the house")', ! str_contains( $got, '<p>Most homes take one to three weeks.</p>' ) && ! str_contains( $got, '<li>Board up and secure the house</li>' ) && str_contains( $got, 'The original says: Board up and secure the house' ) );
$expect( '…in the original\'s own words, its names not put in place in them ("Our fire damage restoration crews…")',str_contains( $got, 'The original says: Our fire damage restoration crews arrive within the hour' ) );
$expect( 'the picture\'s alt says the new service; the picture itself is the original\'s, and said so', str_contains( $got, 'alt="Water Damage Restoration crew at work"' ) && $r['pictures'] === 1 );
$expect( 'the list of services: the card that led to Water Damage leads back to Fire Damage, and says so', isset( $secs[4] ) && str_contains( serialize_block( $secs[4] ), 'href="' . esc_url( (string) get_permalink( $fire ) ) . '">Fire Damage Restoration</a>' ) && ! str_contains( serialize_block( $secs[4] ), (string) get_permalink( $water ) ) );
$expect( 'the copy has the original\'s design records and knows where it comes from', get_post_meta( $water, '_wp_page_template', true ) === 'page-fixture.php' && (int) get_post_meta( $water, Page_Copies::OF, true ) === $fire && count( (array) get_post_meta( $water, Copy_Writer::KEEP, true ) ) === 4 );
$expect( 'the AI would not be shown the shared sections: their words are not in the page\'s inventory', ! str_contains( (string) wp_json_encode( Copy_Writer::inventory( $water ) ), 'Polar Restoration came fast' ) && str_contains( (string) wp_json_encode( Copy_Writer::inventory( $water ) ), 'Our Water Damage Restoration Process' ) );
$expect( 'made again later, it is "made from this page"', array_column( Page_Copies::targets( $fire ), 'state', 'title' )['Water Damage Restoration'] === 'made' );
$q      = DXAI_UI\Network\Page_Quality::of( $water );
$states = array_column( $q['checks'], 'state', 'id' );
$expect( 'measured against the team\'s pages, it is one to look at: lines still to write, the original\'s picture; its name is in place and no other page\'s', $q['grade'] === 'check' && ( $states['to-write'] ?? '' ) === 'bad' && ( $states['pictures'] ?? '' ) === 'warn' && ( $states['name'] ?? '' ) === 'ok' && ( $states['foreign'] ?? '' ) === 'ok', wp_json_encode( $q ) );

echo "\nWith an AI\n";
$stub = new class() extends DXAI_UI\Engines\Abstract_Engine {
	public array $asked = array();
	public function get_id(): string { return 'stub'; }
	public function get_label(): string { return 'Stub'; }
	public function test_connection(): bool|\WP_Error { return true; }
	public function generate( string $system, string $user, array $args = array() ): array|\WP_Error { return new \WP_Error( 'x', 'no' ); }
	public function complete( string $system, string $user, array $args = array() ): string|\WP_Error {
		$this->asked[] = $user;
		$rows = json_decode( substr( $user, (int) strpos( $user, 'BLOCKS:' ) + 7 ), true );
		return (string) wp_json_encode( array( 'blocks' => array_map( static fn( array $b ): array => array( 'id' => $b['id'], 'text' => '[ai] ' . $b['text'] ), (array) $rows ), 'flags' => array( 'The hero picture shows a fire' ) ) );
	}
};
add_filter( 'dxai_ui_copy_engine', static fn() => $stub );
$old_url = '';
add_filter(
	'dxai_ui_copies_old_page',
	static function ( $got, string $url ) use ( &$old_url ) {
		$old_url = $url;
		return str_contains( $url, 'mold-remediation' ) ? array( 'raw' => '<html><body><main><section><h1>Mold Remediation in Fairbanks</h1><p>Black mold spreads behind drywall within 48 hours of a leak.</p></section></main></body></html>', 'url' => $url ) : new \WP_Error( 'x', '404' );
	},
	10,
	2
);
Network_Model::store(
	array(
		'version'   => Network_Model::VERSION,
		'kinds'     => array(
			'service' => array(
				'sections'     => array( 'median' => 11 ),
				'words_design' => array( 'n' => 60, 'median' => 2160 ),
				'faq'          => array( 'questions' => array( 'n' => 180, 'median' => 6, 'p25' => 5, 'p75' => 6 ), 'words' => array( 'median' => 268 ) ),
			),
		),
		'formulas'  => array( 'service' => array( array( 'role' => 'process', 'formula' => 'our {Service} process', 'pages' => 50, 'sites' => 10 ) ) ),
		'questions' => array( 'mold' => array( array( 'q' => 'Can I remove mold myself?', 'pages' => 3, 'sites' => 3 ) ) ),
	)
);
$before_mold = (string) get_post_field( 'post_content', $mold );
$r           = Page_Copies::make( $fire, array( 'id' => $mold ), $plan );
$asked       = implode( "\n", $stub->asked );
$got         = (string) get_post_field( 'post_content', $mold );
$expect( 'the words are written by the AI, the page\'s own lines all of them', ! is_wp_error( $r ) && $r['words'] === 'ai' && $r['engine'] === 'stub' && $r['places'] === 0 && str_contains( $got, '[ai] Our Mold Remediation Process' ) && str_contains( $got, '[ai] Black mold' ) === false, is_wp_error( $r ) ? $r->get_error_message() : wp_json_encode( $r ) );
$expect( '…asked with the team\'s prompt for this service, its names already in place', str_contains( $asked, 'Please inspect this staging page' ) && str_contains( $asked, 'Mold Remediation: the same kind of page' ) && str_contains( $asked, 'Our Mold Remediation Process' ) && ! str_contains( $asked, 'Our Fire Damage Restoration Process' ) );
$expect( '…shown how the network writes a service page (length, questions, formulas with the names in them)', str_contains( $asked, 'about 2160 words' ) && str_contains( $asked, 'the FAQ answers 6 questions' ) && str_contains( $asked, '"Our Mold Remediation process"' ) && str_contains( $asked, 'Can I remove mold myself?' ) );
$expect( '…and the client\'s old page for the same service, read from it', $old_url === 'https://old.example/mold-remediation/' && str_contains( $asked, 'Black mold spreads behind drywall' ) && $r['old_page'] === 'https://old.example/mold-remediation/', $old_url );
$expect( '…never the shared sections', ! str_contains( $asked, 'Polar Restoration came fast' ) && ! str_contains( $asked, 'Tell us what happened' ) );
$expect( 'the shared sections stay word for word, the AI\'s notes are reported', ! str_contains( $got, '[ai] What our neighbors say' ) && str_contains( $got, 'Polar Restoration came fast' ) && $r['flags'] === array( 'The hero picture shows a fire' ) );
$q      = DXAI_UI\Network\Page_Quality::of( $mold );
$states = array_column( $q['checks'], 'state', 'id' );
$expect( 'with its words: nothing left to write, its name in its h1 and headings, no other page\'s name in its own sections', ( $states['to-write'] ?? '' ) === 'ok' && ( $states['name'] ?? '' ) === 'ok' && ( $states['foreign'] ?? '' ) === 'ok', wp_json_encode( $q ) );
$expect( 'the questions of the FAQ (a details\' summary) are written too',str_contains( $got, '<summary>[ai] How long does mold remediation take?</summary>' ) && str_contains( $got, '<summary>[ai] Will the smoke smell come back?</summary>' ) );
$broken = new class() extends DXAI_UI\Engines\Abstract_Engine {
	public function get_id(): string { return 'broken'; }
	public function get_label(): string { return 'Broken'; }
	public function test_connection(): bool|\WP_Error { return true; }
	public function generate( string $system, string $user, array $args = array() ): array|\WP_Error { return new \WP_Error( 'x', 'no' ); }
	public function complete( string $system, string $user, array $args = array() ): string|\WP_Error { return new \WP_Error( 'dxai_test_down', 'The provider is down.' ); }
};
remove_all_filters( 'dxai_ui_copy_engine' );
add_filter( 'dxai_ui_copy_engine', static fn() => $broken );
$r     = Page_Copies::make( $fire, array( 'id' => $water ), $plan );
$shown = '';
foreach ( array_slice( array_values( array_filter( parse_blocks( (string) get_post_field( 'post_content', $water ) ), static fn( $b ) => ! empty( $b['blockName'] ) ) ), 0, 3 ) as $b ) {
	$shown .= wp_strip_all_tags( render_block( $b ) );
}
$expect( 'when the AI fails, the page is the outline (nothing about fire shown) and the failure is said', ! is_wp_error( $r ) && $r['error'] === 'The provider is down.' && $r['words'] === 'outline' && $r['places'] > 0 && $shown !== '' && preg_match( '/fire|soot|smoke/i', $shown ) !== 1, is_wp_error( $r ) ? $r->get_error_message() : wp_json_encode( array( $r['error'], $r['words'], $r['places'], mb_substr( $shown, 0, 120 ) ) ) );
remove_all_filters( 'dxai_ui_copy_engine' );
add_filter( 'dxai_ui_copy_engine', static fn() => $stub );

echo "\nA page the menu names\n";
$r      = Page_Copies::make( $fire, array( 'id' => 0, 'title' => 'Sewage Cleanup', 'link' => $sewage_url ), $plan );
$sewage = is_wp_error( $r ) ? 0 : (int) $r['id'];
if ( $sewage > 0 ) {
	$made[] = $sewage;
}
$expect( 'is made under Services at the menu\'s address, its words written, and published like the original', $sewage > 0 && $r['made'] && (int) wp_get_post_parent_id( $sewage ) === $services && get_post_field( 'post_name', $sewage ) === 'sewage-cleanup-' . $run && get_post_status( $sewage ) === 'publish' && str_contains( (string) get_post_field( 'post_content', $sewage ), '[ai] Our Sewage Cleanup Process' ), is_wp_error( $r ) ? $r->get_error_message() : wp_json_encode( array( $r['made'], get_post_status( $sewage ), get_post_field( 'post_name', $sewage ) ) ) );
remove_all_filters( 'dxai_ui_copy_engine' );
$r     = Page_Copies::make( $fire, array( 'id' => 0, 'title' => 'Biohazard Cleanup' ), $plan, array( 'words' => 'outline' ) );
$draft = is_wp_error( $r ) ? 0 : (int) $r['id'];
if ( $draft > 0 ) {
	$made[] = $draft;
}
$expect( '…and stays a draft when its lines are still to write', $draft > 0 && get_post_status( $draft ) === 'draft' );

echo "\nGiven back\n";
$expect( 'the copy by hand gets its content back', Page_Copies::give_back( $mold ) === true && (string) get_post_field( 'post_content', $mold ) === $before_mold && get_post_meta( $mold, Page_Copies::OF, true ) === '' && get_post_meta( $mold, Copy_Writer::KEEP, true ) === '' );
$expect( 'a page the run made goes to the trash', $sewage > 0 && Page_Copies::give_back( $sewage ) === true && get_post_status( $sewage ) === ( EMPTY_TRASH_DAYS > 0 ? 'trash' : 'draft' ) );
$wpdb->update( $wpdb->posts, array( 'post_content' => (string) get_post_field( 'post_content', $water ) . $p( 'A line a person wrote.' ) ), array( 'ID' => $water ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
clean_post_cache( $water );
$back = Page_Copies::give_back( $water );
$expect( 'a copy a person edited since is left as it is, and says why', is_wp_error( $back ) && $back->get_error_code() === 'dxai_copies_edited' && str_contains( (string) get_post_field( 'post_content', $water ), 'A line a person wrote.' ) );
$expect( 'a page that was not made from another says so', is_wp_error( Page_Copies::give_back( $storm ) ) );
// The copy by hand, back as it was (it still says Fire Damage Restoration), measured as a copy of the fire page.
update_post_meta( $mold, Page_Copies::OF, $fire );
$q = DXAI_UI\Network\Page_Quality::of( $mold );
delete_post_meta( $mold, Page_Copies::OF );
$expect( 'a copy that still says the original\'s service in its own sections is one to fix', ( array_column( $q['checks'], 'state', 'id' )['foreign'] ?? '' ) === 'bad' && $q['grade'] === 'check', wp_json_encode( $q ) );

echo "\nThe Home as the front page (decision 8)\n";
update_option( 'show_on_front', 'posts' );
$a = DXAI_UI\Network\Front_Page::adopt( $home );
$expect( 'a site that shows its posts gets the Home as its front page', $a['state'] === 'none' && $a['made'] && get_option( 'show_on_front' ) === 'page' && (int) get_option( 'page_on_front' ) === $home, wp_json_encode( $a ) );
$expect( '…which it then is', DXAI_UI\Network\Front_Page::state( $home )['state'] === 'this' && ! DXAI_UI\Network\Front_Page::adopt( $home )['made'] );
$expect( '…and what was there can be put back', DXAI_UI\Network\Front_Page::restore() && get_option( 'show_on_front' ) === 'posts' );
$blank = $page( 'Welcome', '' );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $blank );
$expect( 'an empty front page is not the site\'s own: the Home takes its place', DXAI_UI\Network\Front_Page::adopt( $home )['state'] === 'empty' && (int) get_option( 'page_on_front' ) === $home );
update_option( 'page_on_front', $storm );
$a = DXAI_UI\Network\Front_Page::adopt( $home );
$expect( 'a front page with content of its own stays, unless a person chooses the Home', $a['state'] === 'own' && ! $a['made'] && (int) get_option( 'page_on_front' ) === $storm && DXAI_UI\Network\Front_Page::adopt( $home, true )['made'] && (int) get_option( 'page_on_front' ) === $home, wp_json_encode( $a ) );
$expect( 'the front page is not given back once it was changed again', update_option( 'page_on_front', $blank ) && ! DXAI_UI\Network\Front_Page::restore() && (int) get_option( 'page_on_front' ) === $blank );
// Before a re-import is saved: a front page imported from the same archive is the Home the import updates, not the site's own.
update_option( 'page_on_front', $storm );
update_post_meta( $storm, '_dxai_ui_source_zip', 'Polar homepage ' . $run . '.zip' );
$expect( 'before the save, a front page from the same archive is this Home', DXAI_UI\Network\Front_Page::state( 0, 'Polar homepage ' . $run . '.zip' )['state'] === 'this' );
$expect( '…and one from another archive is the site\'s own', DXAI_UI\Network\Front_Page::state( 0, 'Other ' . $run . '.zip' )['state'] === 'own' && DXAI_UI\Network\Front_Page::state( 0 )['state'] === 'own' );

$clean();
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

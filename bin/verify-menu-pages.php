<?php
/**
 * The pages a design's header menu names are made at the menu's own addresses.
 *
 *   bash bin/wp-php.sh bin/verify-menu-pages.php        (or: wp eval-file bin/verify-menu-pages.php --user=1)
 *
 * A design's header is Appearance > Menus. Its menu says which pages the site has, what they are called and where they sit (Five Star: six
 * services under /service/, two offices at the top, ten towns under /service-area/, About, Testimonials), which the panel's detectors cannot
 * read from a Home that lists its services as steps. Menu_Pages reads the menu into pages (what each is, its address, the page above it,
 * what is left out and why), and Team_Pages makes a page at an address: under the page at the address above it, found again by the address,
 * a page that is somebody else's kept as it is, a page whose parent is not there waiting, and one page of a kind a site has one of. The items
 * of the header's menus that led to a section of the Home lead to the pages made, and giving the run back puts them back.
 *
 * Against a Home and menus made for the purpose, at addresses of their own (mfx-…); makes pages, menus and options and removes them (the
 * pages a run makes are put in the trash by giving the run back, and deleted at the end, being this suite's own). Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Chrome\Header_Template;
use DXAI_UI\Pages\Menu_Pages;
use DXAI_UI\Pages\Team_Pages;
use DXAI_UI\Pages\Team_Run;
use DXAI_UI\Structures\Navigation_Factory;
use DXAI_UI\Structures\Page_Scope;

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

$made    = array();
$menus   = array();
$options = array();
$design  = 0;
$fin     = static function () use ( &$made, &$menus, &$options, &$design ): void {
	// Everything made for the Home goes with it, whatever a bug made of a run: a page the suite did not know of would stay and be in the way of the next.
	if ( $design > 0 ) {
		foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ), 'fields' => 'ids', 'posts_per_page' => -1, 'meta_query' => array( array( 'key' => Page_Scope::META, 'value' => (string) $design ) ) ) ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}
	foreach ( array_reverse( $made ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
	foreach ( $menus as $id ) {
		wp_delete_nav_menu( (int) $id );
	}
	foreach ( $options as $name ) {
		delete_option( $name );
	}
	$made = $menus = $options = array();
};
register_shutdown_function( $fin );

// The menu of the Five Star design (Header_Menus built it as Appearance > Menus), as the reader gets it.
$item  = static fn( string $label, string $url = '#', array $children = array(), string $desc = '' ): array => array(
	'label'       => $label,
	'url'         => $url,
	'description' => $desc,
	'item'        => 0,
	'children'    => $children,
);
$five  = array(
	$item(
		'Services',
		'#',
		array(
			$item(
				'Emergency cleanup',
				'#',
				array(
					$item( 'Water & Flood Restoration', '/service/water-flood-restoration/', array(), 'Burst pipes' ),
					$item( 'Fire & Smoke Restoration', '/service/fire-smoke-restoration/' ),
					$item( 'Sewage Cleanup', '/service/sewage-cleanup/' ),
				)
			),
			$item(
				'Restore & rebuild',
				'#',
				array(
					$item( 'Mold Removal', '/service/mold-removal/' ),
					$item( 'Dry Ice Blasting', '/service/dry-ice-blasting/' ),
					$item( 'Construction & Remodeling', '/service/construction-remodeling/' ),
				)
			),
			$item( 'Not sure what you need?', 'tel:+15094040778', array(), '(509) 404-0778' ),
		)
	),
	$item(
		'Locations',
		'#',
		array(
			$item(
				'Walla Walla, WA',
				'/walla-walla-wa/',
				array(
					$item( 'College Place', '/service-area/college-place-wa/' ),
					$item( 'Dayton', '/service-area/dayton-wa/' ),
					$item( 'Pendleton, OR', '/service-area/pendleton-or/' ),
					$item( 'Milton-Freewater, OR', '/service-area/milton-freewater-or/' ),
				)
			),
			$item(
				'Kennewick & Tri-Cities',
				'/tri-cities/',
				array(
					$item( 'Kennewick', '/service-area/kennewick-wa/' ),
					$item( 'West Richland', '/service-area/west-richland-wa/' ),
				)
			),
		)
	),
	$item(
		'About',
		'#',
		array(
			$item( 'About Us', '/about/', array(), 'Our story since 2012' ),
			$item( 'Five Star Team', '#team' ),
			$item( 'Testimonials', '/testimonials/' ),
			$item( 'Careers', '/careers/' ),
		)
	),
	$item( 'Reviews', '#reviews' ),
	$item( 'FAQ', '#faq' ),
	$item( 'Request a Callback', '#contact' ),
);
$hosts = array( 'site.test' );
$ctx   = array(
	'hosts' => $hosts,
	'base'  => '',
);
$kinds   = static function ( array $rows ): array {
	$out = array();
	foreach ( $rows as $r ) {
		$out[ (string) $r['path'] ] = (string) $r['type'];
	}

	return $out;
};
$by_path = static function ( array $rows, string $path ): array {
	foreach ( $rows as $r ) {
		if ( $r['path'] === $path ) {
			return $r;
		}
	}

	return array();
};

echo "An address\n";
$addr = static fn( string $url, string $base = '' ): array => Menu_Pages::address( $url, $hosts, $base );
$expect( 'a path of the site is a page, one word at a time, with its slashes', 'page' === $addr( '/service/water-damage/' )['kind'] && '/service/water-damage/' === $addr( '/service/water-damage/' )['path'] && '/service/water-damage/' === $addr( 'service/water-damage' )['path'] && '/service/water-damage/' === $addr( '/Service/Water Damage' )['path'] );
$expect( 'the site\'s own address is the same page, with and without www, with its query left off', '/about/' === $addr( 'https://site.test/about/?x=1' )['path'] && '/about/' === $addr( 'http://www.site.test/about' )['path'] );
$expect( 'a site in a folder: the folder is not part of the address', '/about/' === $addr( 'http://site.test/shop/about/', '/shop' )['path'] && '/about/' === $addr( '/about/', '/shop' )['path'] );
$expect( 'another site is another site', 'external' === $addr( 'https://other.example/services/' )['kind'] && 'other.example' === $addr( 'https://other.example/services/' )['host'] );
$expect( 'a place on a page is not the page: #faq, /about/#team, and a place on the Home', 'anchor' === $addr( '#faq' )['kind'] && 'faq' === $addr( '#faq' )['fragment'] && 'team' === $addr( '/about/#team' )['fragment'] && 'page' === $addr( '/about/#team' )['kind'] && 'anchor' === $addr( '/#reviews' )['kind'] );
$expect( 'nothing is a page: no address, a dropdown\'s #, the Home, a phone, an e-mail, a script', array( 'none', 'none', 'none', 'none', 'none', 'none' ) === array_map( static fn( $u ) => $addr( $u )['kind'], array( '', '#', '/', 'tel:+15094040778', 'mailto:a@b.test', 'javascript:void(0)' ) ) );
$expect( 'a file is a file, and an address too deep to be a page is none', 'file' === $addr( '/downloads/brochure.pdf' )['kind'] && 'none' === $addr( '/a/b/c/d/e/' )['kind'] );
$expect( 'the address a page is kept at is one word at a time, with its slashes, or none', '/service/water-damage/' === Team_Pages::clean_path( '/Service/Water Damage' ) && '/a/b/' === Team_Pages::clean_path( 'http://x.test/a/b/?q=1' ) && '' === Team_Pages::clean_path( '' ) && '' === Team_Pages::clean_path( '/' ) && '' === Team_Pages::clean_path( '/a/b/c/d/e/' ) && 0 === Team_Pages::page_at( '' ) );

echo "\nThe pages of a menu\n";
$sorted = Menu_Pages::classify( $five, $ctx + array( 'services' => array(), 'places' => array() ) );
$pages  = $sorted['pages'];
$k      = $kinds( $pages );
$expect( 'the six services are services, under /service/', 6 === count( array_filter( $k, static fn( $t, $p ) => 'service' === $t && str_starts_with( $p, '/service/' ), ARRAY_FILTER_USE_BOTH ) ) && '/service/' === $by_path( $pages, '/service/mold-removal/' )['parent'], wp_json_encode( $k ) );
$expect( 'the offices are places at the top (the dropdown they are in says so), the towns are places under /service-area/ (the address says so)', 'location' === ( $k['/walla-walla-wa/'] ?? '' ) && 'location' === ( $k['/tri-cities/'] ?? '' ) && '' === $by_path( $pages, '/tri-cities/' )['parent'] && 'location' === ( $k['/service-area/dayton-wa/'] ?? '' ) && '/service-area/' === $by_path( $pages, '/service-area/dayton-wa/' )['parent'], wp_json_encode( $k ) );
$expect( 'About and Testimonials are told by their addresses, wherever they sit', 'about' === ( $k['/about/'] ?? '' ) && 'testimonials' === ( $k['/testimonials/'] ?? '' ) );
$expect( 'the page above the services, and the one above the towns, are the lists the addresses imply', array( '/service-area/', '/service/' ) === array_column( $sorted['lists'], 'path' ) && 'areas' === $by_path( $sorted['lists'], '/service-area/' )['type'] && 'Service Areas' === $by_path( $sorted['lists'], '/service-area/' )['title'] && 'services' === $by_path( $sorted['lists'], '/service/' )['type'] && true === $by_path( $sorted['lists'], '/service/' )['implied'] );
$expect( 'a place whose state is in its address and not in its words is written as the places are ("College Place, WA")', 'College Place, WA' === $by_path( $pages, '/service-area/college-place-wa/' )['title'] && 'Dayton, WA' === $by_path( $pages, '/service-area/dayton-wa/' )['title'] && 'Pendleton, OR' === $by_path( $pages, '/service-area/pendleton-or/' )['title'] && 'Kennewick & Tri-Cities' === $by_path( $pages, '/tri-cities/' )['title'] );
$expect( 'the title is the item\'s words, the description and the way down to it are kept', 'Water & Flood Restoration' === $by_path( $pages, '/service/water-flood-restoration/' )['title'] && 'Burst pipes' === $by_path( $pages, '/service/water-flood-restoration/' )['description'] && 'Services › Emergency cleanup' === $by_path( $pages, '/service/water-flood-restoration/' )['trail'] );
$left = array();
foreach ( $sorted['left_out'] as $l ) {
	$left[ $l['title'] ] = $l['why'];
}
$expect( 'what is left out says why: Careers has no kind of page, a place on a page and a section of the Home are not pages', 'kind' === ( $left['Careers'] ?? '' ) && 'anchor' === ( $left['Five Star Team'] ?? '' ) && 'anchor' === ( $left['FAQ'] ?? '' ) && 'anchor' === ( $left['Reviews'] ?? '' ) && 'anchor' === ( $left['Request a Callback'] ?? '' ), wp_json_encode( $left ) );
$expect( 'a dropdown\'s trigger and a phone number are no page and not left out either', ! isset( $left['Services'], $left['Locations'], $left['Not sure what you need?'], $left['Emergency cleanup'] ) );
$expect( 'every item with an address is a page or left out: 16 pages (6 services, 2 offices, 6 towns, About, Testimonials) and 5 left out', 16 === count( $pages ) && 5 === count( $sorted['left_out'] ), count( $pages ) . ' and ' . count( $sorted['left_out'] ) );

echo "\nWhat an item is, by its address, its dropdown and its words\n";
$one = static function ( array $tree, array $more = array() ) use ( $ctx ): array {
	return Menu_Pages::classify( $tree, array_merge( $ctx, $more ) );
};
$t = static fn( array $r ): string => (string) ( $r['pages'][0]['type'] ?? '' );
$expect( 'the single words of a site\'s general pages, in any of their usual spellings', array( 'contact', 'contact', 'faq', 'faq', 'about', 'testimonials', 'testimonials' ) === array_map( static fn( $path ) => $t( $one( array( $item( 'x', $path ) ) ) ), array( '/contact/', '/contact-us/', '/faq/', '/frequently-asked-questions/', '/our-story/', '/reviews/', '/customer-reviews/' ) ) );
$expect( 'the list of services and the list of places have a word of their own', 'services' === $t( $one( array( $item( 'x', '/services/' ) ) ) ) && 'areas' === $t( $one( array( $item( 'x', '/service-areas/' ) ) ) ) && 'areas' === $t( $one( array( $item( 'x', '/locations/' ) ) ) ) );
$expect( 'a site with flat addresses: a service is told by the dropdown it is in ("What we do"), a place by its words', 'service' === $t( $one( array( $item( 'What we do', '#', array( $item( 'Water Damage', '/water-damage/' ) ) ) ) ) ) && 'location' === $t( $one( array( $item( 'Revere, MA', '/revere/' ) ) ) ) );
$expect( 'what the Home calls a service or a place tells an item no address or dropdown does', 'service' === $t( $one( array( $item( 'Mold Remediation', '/mold/' ) ), array( 'services' => array( 'Mold Remediation' ) ) ) ) && 'location' === $t( $one( array( $item( 'Tyler', '/tyler/' ) ), array( 'places' => array( 'Tyler' ) ) ) ) );
$expect( 'a general page told by its words when its address says nothing ("Get a free quote")', 'contact' === $t( $one( array( $item( 'Get a free quote', '/start/' ) ) ) ) );
$expect( 'an item of no kind (Pricing) is left out as such, not made a page of', array() === $one( array( $item( 'Pricing', '/pricing/' ) ) )['pages'] && 'kind' === $one( array( $item( 'Pricing', '/pricing/' ) ) )['left_out'][0]['why'] );
$expect( 'another site\'s address, and a file, are left out with their reason', 'external' === $one( array( $item( 'Blog', 'https://blog.example/' ) ) )['left_out'][0]['why'] && 'file' === $one( array( $item( 'Brochure', '/files/brochure.pdf' ) ) )['left_out'][0]['why'] );
$flat = $one( array( $item( 'What we do', '#', array( $item( 'Water Damage', '/restoration/water-damage/' ), $item( 'Mold', '/restoration/mold/' ) ) ) ) );
$expect( 'the services of a site whose address for them is its own word have their list at that address too', array( '/restoration/' ) === array_column( $flat['lists'], 'path' ) && 'services' === $flat['lists'][0]['type'] && 2 === count( $flat['pages'] ), wp_json_encode( $flat['lists'] ) );
$under = $one( array( $item( 'Company', '#', array( $item( 'About Us', '/mfx-company/about-us/' ) ) ) ) );
$expect( 'a page of another kind under an address that is no page is not offered: it would not sit where the menu says', array() === $under['pages'] && 'parent' === ( $under['left_out'][0]['why'] ?? '' ), wp_json_encode( $under ) );
$twice = $one( array( $item( 'About', '/about/' ), $item( 'About us', '/about/' ) ) );
$expect( 'a link into a page (/about/#team) is a place on that page, not another page', array() === $one( array( $item( 'Team', '/about/#team' ) ) )['pages'] && 'anchor' === $one( array( $item( 'Team', '/about/#team' ) ) )['left_out'][0]['why'] );
$expect( 'the same address twice is one page, called what the first item called it', 1 === count( $twice['pages'] ) && 'About' === $twice['pages'][0]['title'] );
$expect( 'a service at an address under /service/, a place under /service-area/, is one wherever it sits in the menu — with no dropdown to say so', 'service' === $t( $one( array( $item( 'Water Damage', '/service/water-damage/' ) ) ) ) && 'location' === $t( $one( array( $item( 'Dayton', '/service-area/dayton-wa/' ) ) ) ) );

echo "\nThe menu of a design\n";
// A design Home made for the purpose, and the menus its header was built into (written by the plugin: tagged as header menus).
$sec = static fn( string $name, string $class, string $inner ): string => '<!-- wp:group {"metadata":{"name":"' . $name . '"},"className":"' . $class . '","tagName":"section"} --><section class="wp-block-group ' . $class . '">' . $inner . '</section><!-- /wp:group -->';
$h   = static fn( string $t, int $l = 2 ): string => '<!-- wp:heading {"level":' . $l . '} --><h' . $l . ' class="wp-block-heading">' . $t . '</h' . $l . '><!-- /wp:heading -->';
$p   = static fn( string $t ): string => '<!-- wp:paragraph --><p>' . $t . '</p><!-- /wp:paragraph -->';
$btn = static fn( string $t, string $url ): string => '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . $url . '">' . $t . '</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
$col = static fn( string $t ): string => '<!-- wp:column {"className":"svc-card"} --><div class="wp-block-column svc-card">' . $h( $t, 3 ) . $p( 'What we do.' ) . $btn( 'Learn more', '#' ) . '</div><!-- /wp:column -->';
$top = '<!-- wp:group {"tagName":"header","className":"site-bar"} --><header class="wp-block-group site-bar"><!-- wp:paragraph --><p>Call (555) 010-0101</p><!-- /wp:paragraph --></header><!-- /wp:group -->';
$bot = '<!-- wp:group {"tagName":"footer","className":"site-foot"} --><footer class="wp-block-group site-foot"><!-- wp:paragraph --><p>© Menu Fixture Co.</p><!-- /wp:paragraph --></footer><!-- /wp:group -->';
$body = $top
	. $sec( 'Hero', 'hero-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $h( 'Menu fixture', 1 ) . $p( 'Family run.' ) . $btn( 'Call now', 'tel:5550100101' ) . '</div><!-- /wp:group -->' )
	. $sec( 'Services', 'svc-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $h( 'Our services' ) . '<!-- wp:columns --><div class="wp-block-columns">' . $col( 'Water Damage' ) . $col( 'Fire Damage' ) . $col( 'Mold Removal' ) . '</div><!-- /wp:columns --></div><!-- /wp:group -->' )
	. $sec( 'Reviews', 'rev-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $h( 'What customers say' ) . $p( '"Fast and kind." — A customer' ) . '</div><!-- /wp:group -->' )
	. $sec( 'FAQ', 'faq-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $h( 'Frequently asked questions' ) . $p( 'How fast can you come?' ) . $p( 'Within the hour.' ) . '</div><!-- /wp:group -->' )
	. $sec( 'CTA', 'cta-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $h( 'Water where it should not be?' ) . $btn( 'Call (555) 010-0101', 'tel:5550100101' ) . '</div><!-- /wp:group -->' )
	. $bot;
$home = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Menu Fixture Restoration', 'post_content' => $body ) );
if ( $home < 1 ) {
	echo "  FAIL  a Home to test on\n";
	exit( 1 );
}
$made[] = $home;
$design = $home;
update_post_meta( $home, Page_Scope::META, $home );
update_post_meta( $home, '_dxai_ui_generated_page', '1' );
update_post_meta( $home, '_dxai_ui_css_url', 'dxai-ui/menu-fixture.css' );

$add = static function ( int $menu_id, string $title, string $url, int $parent = 0, string $desc = '' ): int {
	return (int) wp_update_nav_menu_item(
		$menu_id,
		0,
		array(
			'menu-item-title'       => $title,
			'menu-item-url'         => $url,
			'menu-item-description' => $desc,
			'menu-item-parent-id'   => $parent,
			'menu-item-status'      => 'publish',
		)
	);
};
$menu    = (int) wp_create_nav_menu( 'Menu fixture primary ' . wp_generate_password( 6, false ) );
$menus[] = $menu;
update_term_meta( $menu, Navigation_Factory::HEADER_MENU_META, 'primary-navigation' );
$svc  = $add( $menu, 'Services', '#' );
$grp  = $add( $menu, 'Emergency', '#', $svc );
$wd   = $add( $menu, 'Water Damage', '/mfx-service/water-damage/', $grp, 'Extraction and drying' );
$fd   = $add( $menu, 'Fire Damage', '/mfx-service/fire-damage/', $grp );
$loc  = $add( $menu, 'Locations', '#' );
$tx   = $add( $menu, 'Tyler', '/mfx-areas/tyler-tx/', $loc );
$abt  = $add( $menu, 'About Us', '/mfx-about/' );
$rev  = $add( $menu, 'Reviews', '#reviews' );
$faq  = $add( $menu, 'FAQ', '#faq' );
$call = $add( $menu, 'Call', 'tel:5550100101' );
// A person's own menu, named by the spec but not written by the plugin (no header-menu mark): it is theirs.
$mine    = (int) wp_create_nav_menu( 'Menu fixture person ' . wp_generate_password( 6, false ) );
$menus[] = $mine;
$pfaq    = $add( $mine, 'FAQ', '#faq' );

$spec = array_merge(
	Header_Template::build( '<!-- wp:group {"tagName":"header","className":"h-x"} --><header class="wp-block-group h-x"><!-- wp:group {"tagName":"nav"} --><nav class="wp-block-group"><!-- wp:dxai-ui/link {"url":"#a","text":"One"} --><a class="wp-block-dxai-ui-link" href="#a">One</a><!-- /wp:dxai-ui/link --></nav><!-- /wp:group --></header><!-- /wp:group -->' ),
	array(
		'menus'  => array(
			'primary-navigation' => $menu,
			'header-top'         => $mine,
		),
		'source' => array( 'scope_id' => $home ),
	)
);
Header_Template::store( $spec, false );
$options[] = Header_Template::SCOPE_OPTION . $home;

$read = Menu_Pages::tree_of( $home );
$flat = static function ( array $nodes ) use ( &$flat ): array {
	$out = array();
	foreach ( $nodes as $n ) {
		$out[] = $n['label'] . ' ' . $n['url'];
		$out   = array_merge( $out, $flat( (array) $n['children'] ) );
	}

	return $out;
};
$labels = $flat( $read['tree'] );
$expect( 'the menus written for the design are read, as menus: the design\'s own, not the one a theme location holds now', 'menus' === $read['source'] && in_array( 'Water Damage /mfx-service/water-damage/', $labels, true ) && in_array( 'Tyler /mfx-areas/tyler-tx/', $labels, true ), wp_json_encode( $labels ) );
$expect( '…with their dropdowns as children, a description kept, and the item each is', 'Emergency' === $read['tree'][0]['children'][0]['label'] && 'Water Damage' === $read['tree'][0]['children'][0]['children'][0]['label'] && 'Extraction and drying' === $read['tree'][0]['children'][0]['children'][0]['description'] && (int) $wd === $read['tree'][0]['children'][0]['children'][0]['item'] );
$expect( 'a design with no header installed has no menu to read', array( 'source' => '', 'menus' => array(), 'tree' => array() ) === Menu_Pages::tree_of( 999999999 ) );
$extra = $add( $menu, 'Mold Removal', '/mfx-service/mold-removal/', $grp );
$expect( 'a person\'s edit of the menu in Appearance > Menus is a page: one added there is read', in_array( 'Mold Removal /mfx-service/mold-removal/', $flat( Menu_Pages::tree_of( $home )['tree'] ), true ) );
wp_delete_post( $extra, true );

$report = Menu_Pages::for_design( $home );
$expect( 'what the panel is told: the pages, the lists, what is left out, where it was read from, whether the addresses will open', 'menus' === $report['source'] && 4 === count( $report['pages'] ) && 2 === count( $report['lists'] ) && array() !== $report['left_out'] && is_bool( $report['pretty'] ) && 2 === count( $report['menus'] ), wp_json_encode( array( count( $report['pages'] ), count( $report['lists'] ), count( $report['left_out'] ) ) ) );
$expect( 'the services and the places, each with the list the address above them implies', array( '/mfx-areas/', '/mfx-service/' ) === array_column( $report['lists'], 'path' ) && 'service' === $by_path( $report['pages'], '/mfx-service/water-damage/' )['type'] && 'location' === $by_path( $report['pages'], '/mfx-areas/tyler-tx/' )['type'] && 'about' === $by_path( $report['pages'], '/mfx-about/' )['type'], wp_json_encode( $kinds( $report['pages'] ) ) );
$expect( 'nothing is there yet: no page at those addresses', 0 === $by_path( $report['pages'], '/mfx-about/' )['exists'] && false === $by_path( $report['pages'], '/mfx-about/' )['mine'] && 0 === $by_path( $report['lists'], '/mfx-service/' )['exists'] );

echo "\nPages at their addresses\n";
$wanted = array(
	array( 'type' => 'service', 'title' => 'Water Damage', 'path' => '/mfx-service/water-damage/', 'item' => $wd ),
	array( 'type' => 'service', 'title' => 'Fire Damage', 'path' => '/mfx-service/fire-damage/', 'item' => $fd ),
	array( 'type' => 'location', 'title' => 'Tyler, TX', 'path' => '/mfx-areas/tyler-tx/', 'item' => $tx ),
	array( 'type' => 'location', 'title' => 'Dallas, TX', 'path' => '/mfx-dallas-tx/' ),
	array( 'type' => 'areas', 'title' => 'Service Areas', 'path' => '/mfx-areas/' ),
	array( 'type' => 'services', 'title' => 'Services', 'path' => '/mfx-service/' ),
	array( 'type' => 'about', 'title' => 'About Us', 'path' => '/mfx-about/', 'item' => $abt ),
	array( 'type' => 'about', 'title' => 'About Us' ),
	array( 'type' => 'testimonials', 'title' => 'MFX Testimonials' ),
	array( 'type' => 'faq', 'title' => 'MFX Questions' ),
);
$plan = Team_Pages::plan( $home, $wanted );
$water = $by_path( $plan, '/mfx-service/water-damage/' );
$expect( 'the plan has a page for each address, and one About where the menu and the general pages both ask: the one with the address', 9 === count( $plan ) && 1 === count( array_filter( $plan, static fn( $r ) => 'about' === $r['type'] ) ) && '/mfx-about/' === $by_path( $plan, '/mfx-about/' )['path'], (string) count( $plan ) );
$expect( 'each page of the plan says where it will be and the menu item it comes from, and that nothing is in its way', 'water-damage' === $water['slug'] && (int) $wd === $water['item'] && 0 === $water['conflict'] && 0 === $water['exists'] );
$expect( 'planning makes nothing', array() === get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 20, 'meta_query' => array( array( 'key' => Team_Pages::META, 'compare' => 'EXISTS' ), array( 'key' => Page_Scope::META, 'value' => (string) $home ) ) ) ) );

$out = Team_Pages::build( $home, $wanted );
if ( is_wp_error( $out ) ) {
	echo '  FAIL  the pages are made: ' . $out->get_error_message() . "\n";
	$fin();
	exit( 1 );
}
$by = array();
foreach ( $out['pages'] as $row ) {
	$made[]                                                       = (int) $row['id'];
	$by[ trim( (string) get_page_uri( (int) $row['id'] ), '/' ) ] = (int) $row['id'];
}
$expect( 'every page is made at its address, whatever the order it was asked for in (a service before the list that holds it, a place before the list of places)', 9 === count( $out['pages'] ) && isset( $by['mfx-service/water-damage'], $by['mfx-service/fire-damage'], $by['mfx-areas/tyler-tx'], $by['mfx-dallas-tx'], $by['mfx-areas'], $by['mfx-service'], $by['mfx-about'] ), wp_json_encode( array_keys( $by ) ) );
$expect( 'a page sits under the page at the address above it, and a page at the top sits at the top', (int) $by['mfx-service'] === (int) get_post_field( 'post_parent', $by['mfx-service/water-damage'] ) && (int) $by['mfx-areas'] === (int) get_post_field( 'post_parent', $by['mfx-areas/tyler-tx'] ) && 0 === (int) get_post_field( 'post_parent', $by['mfx-dallas-tx'] ) && 0 === (int) get_post_field( 'post_parent', $by['mfx-service'] ) );
$expect( 'a page is what it was made as: its kind and name, its design, the Home\'s template', 'service|water-damage' === get_post_meta( $by['mfx-service/water-damage'], Team_Pages::META, true ) && 'location|tyler-tx' === get_post_meta( $by['mfx-areas/tyler-tx'], Team_Pages::META, true ) && $home === (int) get_post_meta( $by['mfx-about'], Page_Scope::META, true ) && \DXAI_UI\Theme\Blank_Template::default_slug() === get_post_meta( $by['mfx-about'], '_wp_page_template', true ) );
$list = (string) get_post_field( 'post_content', $by['mfx-service'] );
$expect( 'the page that lists the services lists the services made, at their addresses', str_contains( $list, (string) get_permalink( $by['mfx-service/water-damage'] ) ) && str_contains( $list, (string) get_permalink( $by['mfx-service/fire-damage'] ) ) );
$abouts = get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 20, 'meta_query' => array( array( 'key' => Team_Pages::META, 'value' => 'about|', 'compare' => 'LIKE' ), array( 'key' => Page_Scope::META, 'value' => (string) $home ) ) ) );
$expect( 'one About: the menu\'s page and the general page asked for beside it are the same page', 1 === count( $abouts ), (string) count( $abouts ) );
$after = Menu_Pages::for_design( $home );
$expect( 'the menu now says what is there: the page at an address is this design\'s own, and the list above the services is no longer to be made', $by['mfx-about'] === $by_path( $after['pages'], '/mfx-about/' )['exists'] && true === $by_path( $after['pages'], '/mfx-about/' )['mine'] && $by['mfx-service'] === $by_path( $after['lists'], '/mfx-service/' )['exists'] );

echo "\nThe menu opens the pages\n";
$url_of  = static fn( int $id ): string => (string) get_post_meta( $id, '_menu_item_url', true );
$page_of = static function ( string $kind ) use ( $home ): int {
	$ids = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_query' => array( array( 'key' => Team_Pages::META, 'value' => $kind . '|', 'compare' => 'LIKE' ), array( 'key' => Page_Scope::META, 'value' => (string) $home ) ) ) );

	return (int) ( $ids[0] ?? 0 );
};
$expect( 'an item that already leads to a page of the site is left as it is: it opens the page made at its address', '/mfx-service/water-damage/' === $url_of( $wd ) && '/mfx-about/' === $url_of( $abt ) && '/mfx-areas/tyler-tx/' === $url_of( $tx ) );
$expect( 'a section of the Home that is a page now ("Reviews", "FAQ") leads to the page, as the links of a header made of blocks did', $page_of( 'testimonials' ) > 0 && (string) get_permalink( $page_of( 'testimonials' ) ) === $url_of( $rev ) && $page_of( 'faq' ) > 0 && (string) get_permalink( $page_of( 'faq' ) ) === $url_of( $faq ), $url_of( $rev ) . ' / ' . $url_of( $faq ) );
$expect( 'a dropdown\'s trigger and a phone number stay as they are', '#' === $url_of( $svc ) && '#' === $url_of( $loc ) && '#' === $url_of( $grp ) && 'tel:5550100101' === $url_of( $call ) );
$expect( 'a menu a person made is theirs: its item is not pointed anywhere', '#faq' === $url_of( $pfaq ) );
$run = Team_Run::summary( $home );
$expect( 'the run kept the pages it made and the menu it pointed, so it can be given back', 9 === count( array_filter( $run['pages'], static fn( $r ) => $r['created'] ) ) && true === $run['menu'] );

echo "\nGiving the run back\n";
$gave = Team_Run::undo( $home );
$expect( 'the pages the run made are in the trash, never deleted', ! is_wp_error( $gave ) && 9 === count( $gave['removed'] ) && 9 === count( array_filter( $gave['removed'], static fn( $id ) => 'trash' === get_post_status( (int) $id ) ) ), is_wp_error( $gave ) ? $gave->get_error_message() : wp_json_encode( $gave ) );
$expect( 'the items the run pointed at a page lead to their section of the Home again', '#reviews' === $url_of( $rev ) && '#faq' === $url_of( $faq ), $url_of( $rev ) . ' / ' . $url_of( $faq ) );
$expect( 'what the run did not point is as it was: the items with an address of the site, the triggers, a person\'s menu', '/mfx-service/water-damage/' === $url_of( $wd ) && '/mfx-about/' === $url_of( $abt ) && '#' === $url_of( $svc ) && '#faq' === $url_of( $pfaq ) );
$expect( 'and there is nothing left to give back', is_wp_error( Team_Run::undo( $home ) ) );

echo "\nMaking them again\n";
// The pages in the trash do not hold their addresses: the same pages are made again at them.
$out2 = Team_Pages::build( $home, $wanted );
$by2  = array();
foreach ( (array) ( $out2['pages'] ?? array() ) as $row ) {
	$made[]                                                        = (int) $row['id'];
	$by2[ trim( (string) get_page_uri( (int) $row['id'] ), '/' ) ] = (int) $row['id'];
}
$expect( 'made again at the same addresses, as new pages', ! is_wp_error( $out2 ) && 9 === count( $by2 ) && isset( $by2['mfx-service/water-damage'], $by2['mfx-about'] ) && array() === array_intersect( array_values( $by2 ), array_values( $by ) ), is_wp_error( $out2 ) ? $out2->get_error_message() : wp_json_encode( array_keys( $by2 ) ) );
$expect( 'and the menu leads to them again', str_contains( $url_of( $rev ), 'page_id=' . $page_of( 'testimonials' ) ) || (string) get_permalink( $page_of( 'testimonials' ) ) === $url_of( $rev ) );
$again = Team_Pages::build( $home, $wanted );
$expect( 'a third time updates the same pages in place, not twice', ! is_wp_error( $again ) && 9 === count( $again['pages'] ) && array() === array_diff( array_column( $again['pages'], 'id' ), array_values( $by2 ) ) );
$edited = $by2['mfx-service/fire-damage'];
global $wpdb;
$wpdb->update( $wpdb->posts, array( 'post_content' => (string) get_post_field( 'post_content', $edited ) . "\n<!-- wp:paragraph --><p>A person wrote this.</p><!-- /wp:paragraph -->" ), array( 'ID' => $edited ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
clean_post_cache( $edited );
$third = Team_Pages::build( $home, $wanted );
$kept  = array();
foreach ( (array) ( $third['kept'] ?? array() ) as $k2 ) {
	$kept[ (int) $k2['id'] ] = (string) $k2['why'];
}
$expect( 'a page a person edited is kept, and says so', ! is_wp_error( $third ) && 'edited' === ( $kept[ $edited ] ?? '' ) && str_contains( (string) get_post_field( 'post_content', $edited ), 'A person wrote this.' ), wp_json_encode( $kept ) );

echo "\nAn address that is somebody else's\n";
$foreign = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Contact (the person\'s own)', 'post_name' => 'mfx-contact', 'post_content' => '<!-- wp:paragraph --><p>The person\'s own contact page.</p><!-- /wp:paragraph -->' ) );
$made[]  = $foreign;
$fwant   = array( array( 'type' => 'contact', 'title' => 'Contact Us', 'path' => '/mfx-contact/' ), array( 'type' => 'about', 'title' => 'About Us', 'path' => '/mfx-about/' ) );
$fplan   = Team_Pages::plan( $home, $fwant );
$expect( 'the plan names the page that is in the way', $foreign === (int) $by_path( $fplan, '/mfx-contact/' )['conflict'] && 0 === (int) $by_path( $fplan, '/mfx-about/' )['conflict'], wp_json_encode( array_column( $fplan, 'conflict', 'path' ) ) );
$fout = Team_Pages::build( $home, $fwant );
$expect( 'it is kept as it is, not written over and not given a second page at "mfx-contact-2"', ! is_wp_error( $fout ) && str_contains( (string) get_post_field( 'post_content', $foreign ), 'The person\'s own contact page.' ) && 0 === Team_Pages::page_at( '/mfx-contact-2/' ) && 1 === count( array_filter( (array) $fout['kept'], static fn( $k3 ) => 'address' === $k3['why'] && $foreign === $k3['id'] ) ), is_wp_error( $fout ) ? $fout->get_error_message() : wp_json_encode( $fout['kept'] ) );

// A page another design's panel made is not this design's: it is in the way like any other.
$other_design = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Another design\'s page', 'post_name' => 'mfx-other-design', 'post_content' => '' ) );
$made[]       = $other_design;
update_post_meta( $other_design, Team_Pages::META, 'about|mfx-other-design' );
update_post_meta( $other_design, Team_Pages::KEY_META, Team_Pages::page_key( 999999, 'about', 'mfx-other-design' ) );
update_post_meta( $other_design, Page_Scope::META, 999999 );
$expect( 'a page the panel made for another design is not this design\'s: it is in the way, not taken for the page', 0 === Team_Pages::team_page_at( $home, '/mfx-other-design/' ) && $other_design === Team_Pages::page_at( '/mfx-other-design/' ) );

echo "\nA page whose parent is not there\n";
$orphan = array(
	array( 'type' => 'service', 'title' => 'Lost Service', 'path' => '/mfx-missing/lost-service/' ),
	array( 'type' => 'service', 'title' => 'Fine Service', 'path' => '/mfx-service/fine-service/' ),
);
$oout   = Team_Pages::build( $home, $orphan );
foreach ( (array) ( $oout['pages'] ?? array() ) as $row ) {
	$made[] = (int) $row['id'];
}
$expect( 'it waits, with the reason, and the others are made', ! is_wp_error( $oout ) && 0 === Team_Pages::page_at( '/mfx-missing/lost-service/' ) && 0 === Team_Pages::page_at( '/lost-service/' ) && Team_Pages::page_at( '/mfx-service/fine-service/' ) > 0 && 1 === count( array_filter( (array) $oout['kept'], static fn( $k4 ) => 'parent' === $k4['why'] ) ), is_wp_error( $oout ) ? $oout->get_error_message() : wp_json_encode( $oout['kept'] ) );

echo "\nA page this design made, found by its address\n";
// The pages of the main site were made by title ("Water Damage in Dayton, WA") and moved to the design's addresses: the address is the page.
$old = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Water Damage in Dayton, WA', 'post_name' => 'dayton-wa', 'post_parent' => $by2['mfx-areas'], 'post_content' => '' ) );
$made[] = $old;
update_post_meta( $old, Team_Pages::META, 'location|water-damage-in-dayton-wa' );
update_post_meta( $old, Team_Pages::KEY_META, Team_Pages::page_key( $home, 'location', 'water-damage-in-dayton-wa' ) );
update_post_meta( $old, Team_Pages::HASH, md5( '' ) );
update_post_meta( $old, Page_Scope::META, $home );
$aout = Team_Pages::build( $home, array( array( 'type' => 'location', 'title' => 'Dayton, WA', 'path' => '/mfx-areas/dayton-wa/' ) ) );
$expect( 'the page that is there is the page: written in place, its record as it was, no second page', ! is_wp_error( $aout ) && array( $old ) === array_map( 'intval', array_column( $aout['pages'], 'id' ) ) && 'location|water-damage-in-dayton-wa' === get_post_meta( $old, Team_Pages::META, true ) && 1 === count( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 20, 'name' => 'dayton-wa' ) ) ), is_wp_error( $aout ) ? $aout->get_error_message() : wp_json_encode( $aout['pages'] ?? null ) );

echo "\nThrough the panel's route\n";
$rest = static function ( string $method, array $params ): array {
	$request = new WP_REST_Request( $method, '/' . DXAI_UI_REST_NAMESPACE . '/team-pages' );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$res = rest_do_request( $request );

	return array( 'status' => (int) $res->get_status(), 'data' => (array) $res->get_data() );
};
$g = $rest( 'GET', array( 'design' => $home ) );
$gw = $by_path( (array) ( $g['data']['menu']['pages'] ?? array() ), '/mfx-service/water-damage/' );
$expect( 'the design\'s answer carries its menu: pages with their addresses, kinds and what is there, lists, what is left out', 200 === $g['status'] && 'menus' === ( $g['data']['menu']['source'] ?? '' ) && 'service' === ( $gw['type'] ?? '' ) && ( $gw['exists'] ?? 0 ) > 0 && true === ( $gw['mine'] ?? null ) && array() !== ( $g['data']['menu']['left_out'] ?? array() ), wp_json_encode( array_keys( (array) ( $g['data']['menu'] ?? array() ) ) ) );
$pl = $rest( 'POST', array( 'action' => 'plan', 'design' => $home, 'wanted' => array( array( 'type' => 'service', 'title' => 'Water Damage', 'path' => '/mfx-service/water-damage/', 'item' => $wd ) ) ) );
$expect( 'a page asked for at an address is planned there, with its item', 200 === $pl['status'] && '/mfx-service/water-damage/' === ( $pl['data']['plan'][0]['path'] ?? '' ) && (int) $wd === (int) ( $pl['data']['plan'][0]['item'] ?? 0 ), wp_json_encode( $pl['data']['plan'][0] ?? null ) );
$none = Menu_Pages::for_design( 999999999 );
$expect( 'a design that is not one has nothing', array() === $none['pages'] && '' === $none['source'] );

echo "\nA header kept as a template part: the design's document names the pages\n";
// A second Home of the fixture with no menus written for it, and a document (Design\Document) whose header navigation is the menu above.
$doc_home = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Menu Fixture Document Home', 'post_content' => $body ) );
$made[]   = $doc_home;
update_post_meta( $doc_home, Page_Scope::META, $doc_home );
update_post_meta( $doc_home, '_dxai_ui_generated_page', '1' );
update_post_meta( $doc_home, '_dxai_ui_css_url', 'dxai-ui/menu-fixture-doc.css' );
\DXAI_UI\Structures\Design_Attach::forget( $doc_home );
$nav_of = static function ( array $nodes ) use ( &$nav_of ): array {
	return array_map(
		static fn( array $n ): array => array(
			'label'       => (string) $n['label'],
			'url'         => (string) $n['url'],
			'description' => (string) ( $n['description'] ?? '' ),
			'children'    => $nav_of( (array) $n['children'] ),
		),
		$nodes
	);
};
$the_doc = \DXAI_UI\Design\Document::from_array(
	array(
		'v'         => \DXAI_UI\Design\Document::VERSION,
		'source'    => array( 'kind' => 'test', 'name' => 'menu-fixture.zip', 'stack' => '', 'static_html' => true, 'hash' => sha1( 'menu fixture' ) ),
		'title'     => 'Menu Fixture Document Home',
		'home_slug' => '/',
		'pages'     => array( array( 'slug' => '/', 'title' => 'Menu Fixture Document Home', 'file' => '', 'id' => $doc_home, 'words' => 10, 'sections' => array(), 'sections_source' => 'compiled', 'source_sections' => array(), 'forms' => 0, 'links' => array(), 'meta' => array( 'title' => '', 'description' => '' ) ) ),
		'regions'   => array(
			'header' => array( 'present' => true, 'placed' => 'part', 'nav' => $nav_of( Menu_Pages::tree_of( $home )['tree'] ), 'nav_source' => 'compiled', 'logo' => null, 'actions' => array() ),
			'footer' => null,
		),
	)
);
$expect( 'a document with no menus written has no menu to read until the document is kept', '' === Menu_Pages::tree_of( $doc_home )['source'] );
\DXAI_UI\Design\Document_Store::save( $doc_home, $the_doc );
$from_doc = Menu_Pages::tree_of( $doc_home );
$expect( 'the document\'s header navigation is read, as the document\'s, with no menu item behind the nodes', 'document' === $from_doc['source'] && array() === $from_doc['menus'] && $flat( $from_doc['tree'] ) === $flat( Menu_Pages::tree_of( $home )['tree'] ) && 0 === (int) ( $from_doc['tree'][0]['item'] ?? -1 ), wp_json_encode( $from_doc['source'] ) );
$expect( 'the menus written for a design still come first: the document does not speak for a design that has them', 'menus' === Menu_Pages::tree_of( $home )['source'] );
$expect( 'the document\'s own builder is never answered by the document', '' === Menu_Pages::tree_of( $doc_home, false )['source'] );
$doc_report = Menu_Pages::for_design( $doc_home );
$expect( 'the panel is told the same pages and lists from the document, and where they were read from', 'document' === $doc_report['source'] && count( $doc_report['pages'] ) === count( $report['pages'] ) && array_column( $doc_report['lists'], 'path' ) === array_column( $report['lists'], 'path' ), wp_json_encode( array( $doc_report['source'], count( $doc_report['pages'] ), count( $report['pages'] ) ) ) );
\DXAI_UI\Design\Document_Store::forget( $doc_home );

$fin();
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

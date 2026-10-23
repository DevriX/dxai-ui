<?php
/**
 * The network as a teacher (Network\Page_Reader, Site_Source, Network_Model; docs/PLAN-NETWORK-PAGES.md, N0).
 *
 *   bash bin/wp-php.sh bin/verify-network-model.php        (or: wp eval-file bin/verify-network-model.php --user=1)
 *
 * A page read as its sections (roles, words, questions, the build), what a template and a picture's source do to that, the
 * kind of a page by its address, a site's reuse of its Home, the formulas and questions two sites share (and not what one
 * site says), the model kept and read back, and the site this runs on read in place (two fixture pages, deleted after,
 * also when a check dies half-way).
 *
 * When the research's crawl is on this machine (.verify/network-research/data and report.json, gitignored) the plugin
 * reads its 25 sites again and must give the research's numbers page by page (DXAI_NETWORK_RESEARCH names another folder);
 * elsewhere that part says so and the rest runs.
 * Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Network\Network_Model;
use DXAI_UI\Network\Page_Reader;
use DXAI_UI\Network\Site_Source;

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

// A page of a team site, section by section (rendered HTML, the way the REST API gives it).
$words = static fn( int $n, string $w = 'water' ): string => implode( ' ', array_fill( 0, $n, $w ) );
$hero  = static fn( string $h1 ): string => '<section class="wp-block-group alignfull"><h1 class="wp-block-heading">' . $h1 . '</h1><p>We answer day and night.</p><div class="wp-block-buttons"><div class="wp-block-button"><a class="wp-block-button__link" href="tel:5550100">Call now</a></div></div></section>';
$trust = '<section class="wp-block-group"><img src="a.png" alt=""><img src="b.png" alt=""><img src="c.png" alt=""><img src="d.png" alt=""></section>';
$proc  = static fn( string $h, string $w ): string => '<section class="wp-block-group alignfull"><h2>' . $h . '</h2><ol><li>' . $w . ' one</li><li>' . $w . ' two</li><li>' . $w . ' three</li><li>' . $w . ' four</li></ol></section>';
$revs  = '<section class="wp-block-group"><h2>Trusted by our neighbors</h2><p>4.9 on Google</p><pre class="ti-widget" style="display:none"><template id="tw"><div class="ti-review-item">' . $words( 500, 'great' ) . '</div></template></pre></section>';
$faq   = static fn( string $h, array $qs ): string => '<section class="wp-block-group"><h2>' . $h . '</h2>' . implode( '', array_map( static fn( string $q ): string => '<details class="wp-block-details"><summary>' . $q . '</summary><p>An answer of a few words.</p></details>', $qs ) ) . '</section>';
$cta   = '<section class="wp-block-group has-primary-background-color"><h2>Water won’t wait. Neither do we.</h2><p><a href="tel:5550100">(555) 010-0</a></p></section>';
$form  = '<section class="wp-block-group"><h2>Request a free assessment</h2><form><input type="email" name="e"></form></section>';
$two   = static fn( string $h, string $w ): string => '<section class="wp-block-group"><div class="wp-block-columns"><div class="wp-block-column"><h2>' . $h . '</h2><p>' . $w . '</p></div><div class="wp-block-column"><figure class="wp-block-image"><picture><source srcset="x.webp" type="image/webp"><img src="x.jpg" alt=""></picture></figure></div></div></section>';
$cards = '<section class="wp-block-group"><h2>Why property owners call us</h2><div class="wp-block-columns"><div class="wp-block-column"><h3>Fast</h3><p>' . $words( 20, 'fast' ) . '</p></div><div class="wp-block-column"><h3>Certified</h3><p>' . $words( 20, 'iicrc' ) . '</p></div><div class="wp-block-column"><h3>Local</h3><p>' . $words( 20, 'local' ) . '</p></div></div></section>';
$map   = '<section class="wp-block-group"><h2>Find us</h2><iframe src="https://www.google.com/maps/embed?pb=1"></iframe></section>';

echo "A page read as its sections\n";
$questions = array( 'How long does Water Damage Restoration take?', 'Does insurance cover water damage?', 'Can water damage lead to mold?', 'Do I need to leave my home?', 'What should I do first?', 'How do you find hidden water?' );
$service   = '<div class="entry-content">' . $hero( 'Water Damage Restoration in Fairbanks' ) . $trust . $proc( 'Our Water Damage Restoration Process', $words( 12 ) ) . $revs . $two( 'How our water damage restoration protects your home', $words( 60 ) ) . $cards . $faq( 'Water Damage Restoration FAQs', $questions ) . $map . $cta . $form . '</div>';
$read      = Page_Reader::read( $service );
$roles     = array_column( $read['sections'], 'role' );
$expect( 'the wrapper is gone through and every section has its role', $roles === array( 'hero', 'trust', 'process', 'reviews', 'two-col', 'cards', 'faq', 'map', 'cta', 'form' ), implode( ',', $roles ) );
$expect( 'the h1 is the page\'s', $read['h1'] === 'Water Damage Restoration in Fairbanks', $read['h1'] );
$expect( 'a review widget\'s template is not read (a browser keeps it aside): the section has its own few words', $read['sections'][3]['words'] < 20, (string) $read['sections'][3]['words'] );
$expect( '…nor counted in the page\'s words', $read['words'] < 400, (string) $read['words'] );
$browser = 'section.wp-block-group{div.wp-block-columns{div.wp-block-column{h2,p},div.wp-block-column{figure.wp-block-image{picture{source,img}}}}}';
$expect( 'a picture\'s source holds nothing: the image is beside it, as a browser builds it', $read['sections'][4]['shape_hash'] === substr( sha1( $browser ), 0, 12 ) && $read['sections'][4]['shape_len'] === strlen( $browser ), (string) $read['sections'][4]['shape_len'] );
$expect( 'the questions of the FAQ are read, each once', $read['sections'][6]['asked'] === $questions && $read['sections'][6]['questions'] === 6, implode( ' | ', $read['sections'][6]['asked'] ) );
$expect( 'the team\'s FAQ group (a heading in .faq-question) is a question too', Page_Reader::read( '<section><h2>Questions</h2><div class="wp-block-group faq-question"><h3>Can wet drywall be dried in place?</h3><p>Often.</p></div><div class="faq-question"><h3>Do you bill insurance?</h3></div></section><section><h2>Next</h2></section>' )['sections'][0]['asked'] === array( 'Can wet drywall be dried in place?', 'Do you bill insurance?' ) );
$got = array_column( Page_Reader::read( '<section><h2>Start</h2><p>' . $words( 130 ) . '</p></section><section><h2>Kind words</h2><div class="testimonial-slider"><p>Great work, fast crew.</p></div></section><section><h2>Neighbors</h2><div class="trustindex"><p>Five on Google</p></div></section>' )['sections'], 'role' );
$expect( 'reviews by what holds them when the heading does not say (a testimonial slider, a Trustindex widget)', $got === array( 'text', 'reviews', 'reviews' ), implode( ',', $got ) );
$a = Page_Reader::read( $proc( 'Our Fire Damage Restoration Process', $words( 12, 'smoke' ) ) )['sections'][0];
$b = Page_Reader::read( $proc( 'Our Water Damage Restoration Process', $words( 12 ) ) )['sections'][0];
$expect( 'the same build with other words: one build, two texts', $a['shape_hash'] === $b['shape_hash'] && $a['text_hash'] !== $b['text_hash'] );
$expect( 'words are counted the research\'s way (no-break spaces; an apostrophe or an ampersand inside a word, an ampersand alone is none)', Page_Reader::words( "Fire\u{00A0}&\u{00A0}smoke won’t wait — T&T’s crew" ) === 6, (string) Page_Reader::words( "Fire\u{00A0}&\u{00A0}smoke won’t wait — T&T’s crew" ) );

echo "\nThe kind of a page, by its address and title\n";
$by   = array(
	1 => array( 'id' => 1, 'slug' => 'services', 'title' => 'Services', 'parent' => 0 ),
	2 => array( 'id' => 2, 'slug' => 'service-areas', 'title' => 'Service Areas', 'parent' => 0 ),
);
$kind = static fn( string $slug, string $title, int $parent = 0 ): string => Network_Model::kind_of( array( 'slug' => $slug, 'title' => $title, 'parent' => $parent ), $by );
$expect( 'a service, by its parent or its words', $kind( 'water-heater', 'Water Heater', 1 ) === 'service' && $kind( 'mold-remediation', 'Mold Remediation' ) === 'service' );
$expect( 'a city, by its parent, "in {City}" or ", ST"', $kind( 'fairbanks', 'Fairbanks', 2 ) === 'location' && $kind( 'x', 'Restoration in Fairbanks' ) === 'location' && $kind( 'y', 'Fairbanks, AK' ) === 'location' );
$expect( 'the pages every site has', $kind( 'about-us', 'About Us' ) === 'about' && $kind( 'contact', 'Contact' ) === 'contact' && $kind( 'faqs', 'FAQs' ) === 'faq' && $kind( 'testimonials', 'Testimonials' ) === 'reviews' && $kind( 'privacy-policy', 'Privacy Policy' ) === 'legal' && $kind( 'service-areas', 'Service Areas' ) === 'areas-index' && $kind( 'services', 'Services' ) === 'services-index' );
$expect( 'the family of a service', Network_Model::family( 'water-damage-restoration', '' ) === 'water' && Network_Model::family( 'smoke-damage', '' ) === 'fire' && Network_Model::family( 'mould-removal', '' ) === 'mold' && Network_Model::family( 'about', 'About' ) === '' );

echo "\nA site: its Home and the pages made from it\n";
$home_html = '<div>' . $hero( 'Restoration in Fairbanks' ) . $trust . $proc( 'How it works', $words( 12, 'home' ) ) . $revs . $cta . $form . '</div>';
$svc       = static fn( string $name, string $w ): string => '<div>' . $hero( $name . ' in Fairbanks' ) . $trust . $proc( 'Our ' . $name . ' Process', $words( 12, $w ) ) . $revs . $two( 'How our ' . $name . ' protects your home', $words( 60, $w ) ) . $faq( $name . ' FAQs', array( 'How long does ' . $name . ' take?', 'Will insurance pay for ' . $name . '?', 'A question only ' . $w . ' asks?' ) ) . $cta . $form . '</div>';
$site      = static fn( string $key, string $co, array $pages ): array => array(
	'key'   => $key,
	'url'   => 'https://' . $key . '/',
	'name'  => $co,
	'theme' => 'american-restoration',
	'front' => 10,
	'menu'  => array(),
	'posts' => 0,
	'pages' => array_merge(
		array(
			array( 'id' => 10, 'slug' => 'home', 'link' => 'https://' . $key . '/', 'title' => 'Home', 'parent' => 0, 'modified' => '', 'html' => '<div class="dxai-ui">' . substr( $home_html, 5 ) ),
			array( 'id' => 11, 'slug' => 'services', 'link' => 'https://' . $key . '/services/', 'title' => 'Services', 'parent' => 0, 'modified' => '', 'html' => '' ),
		),
		$pages
	),
);
$page      = static fn( int $id, string $slug, string $title, string $html ): array => array( 'id' => $id, 'slug' => $slug, 'link' => '', 'title' => $title, 'parent' => 11, 'modified' => '', 'html' => $html );
$one       = Network_Model::read_site(
	$site(
		'one.test',
		'Polar Restoration',
		array(
			$page( 20, 'water-damage', 'Water Damage', $svc( 'Water Damage', 'water' ) ),
			$page( 21, 'fire-damage', 'Fire Damage', $svc( 'Fire Damage', 'smoke' ) ),
			$page( 22, 'mold-remediation', 'Mold Remediation', $svc( 'Mold Remediation', 'spores' ) ),
		)
	)
);
$two_site  = Network_Model::read_site(
	$site(
		'two.test',
		'Arctic Dry',
		array(
			$page( 30, 'water-damage-restoration', 'Water Damage Restoration', $svc( 'Water Damage Restoration', 'flood' ) ),
			$page( 31, 'storm-damage', 'Storm Damage', $svc( 'Storm Damage', 'wind' ) ),
		)
	)
);
$water = $one['pages'][2];
$from  = array_column( $water['sections'], 'from_home' );
$expect( 'the Home is the front page and a design', $one['pages'][0]['kind'] === 'home' && $one['design_home'] );
$expect( 'on a service page: the Home\'s sections word for word, the Home\'s build with new words, and new sections', $from === array( 'shape', 'same', 'shape', 'same', '', '', 'same', 'same' ), implode( ',', $from ) );
$expect( 'the reviews are on every service page with the same words (2 other pages)', $water['sections'][3]['shared_pages'] === 2 && $water['sections'][4]['shared_pages'] === 0 );
$expect( '…and the intro beside a picture is built like the other pages\' (2 other pages)', $water['sections'][4]['shape_pages'] === 2 );
$share = Network_Model::home_shares( $one, 'service' );
$expect( 'the research\'s table for a site: the Home\'s build 2 of 8 sections, its words 4 of 8', abs( $share['home_build'] - 0.25 ) < 0.001 && abs( $share['home_words'] - 0.5 ) < 0.001 && $share['pages'] === 3, wp_json_encode( $share ) );

echo "\nWhat two sites say, and what one says\n";
$model = Network_Model::learn( array( $one, $two_site ) );
$f     = array_column( (array) ( $model['formulas']['service'] ?? array() ), null, 'formula' );
$expect( '"Our {Service} Process" is a formula of both sites (5 pages)', isset( $f['our {Service} process'] ) && $f['our {Service} process']['sites'] === 2 && $f['our {Service} process']['pages'] === 5, implode( ' | ', array_keys( $f ) ) );
$expect( '"{Service} FAQs" too', isset( $f['{Service} faqs'] ) && $f['{Service} faqs']['pages'] === 5 );
$expect( 'the company (or its first two words), the service and the city are tokens, a number is N', Network_Model::tokenize( 'Why choose Polar Restoration for Water Damage in Fairbanks, AK', 'Water Damage', array( 'Polar Restoration Services', 'Polar Restoration' ), 'Fairbanks' ) === 'Why choose {Co} for {Service} in {City}' && Network_Model::tokenize( '24/7 help', '', array(), '' ) === 'N/N help' );
$expect( 'a page\'s service is its title before "in …", its city after it', Network_Model::page_tokens( array( 'title' => 'Water Damage Restoration in Fairbanks, AK', 'slug' => 'x' ) ) === array( 'service' => 'Water Damage Restoration', 'city' => 'Fairbanks' ) );
$q = array_column( (array) ( $model['questions']['water'] ?? array() ), null, 'q' );
$expect( 'a question two sites ask about water is learned, with the service as a token', isset( $q['How long does {Service} take?'] ) && $q['How long does {Service} take?']['sites'] === 2, implode( ' | ', array_keys( $q ) ) );
$expect( 'a question only one site asks is not (a client\'s words stay theirs)', ! isset( $q['A question only water asks?'] ) && ! isset( $q['A question only flood asks?'] ) );
$expect( 'what one site says alone is neither a formula nor a question', Network_Model::formulas( array( $one ) ) === array() && Network_Model::questions( array( $one ) ) === array() );
$expect( 'the set of pages, the kinds, the budgets', $model['set']['services']['median'] === 3 && $model['kinds']['service']['pages'] === 5 && $model['kinds']['service']['sections']['median'] === 8 && $model['budgets']['service']['faq']['words'] > 0 );
$expect( 'the roles a site keeps word for word on its pages: the reviews and the call to action (on the site of 3 pages; 2 pages say nothing, a section of 4 words neither)', count( $model['global'] ) === 2 && ( $model['global']['reviews'] ?? 0 ) === 1 && ( $model['global']['cta'] ?? 0 ) === 1, wp_json_encode( $model['global'] ) );
$expect( 'pages made from one page are twins (3 of 3 on one site)', $model['twins']['one.test']['service']['twins'] === 3 && $model['twins']['one.test']['service']['shared'] === 100 );
$expect( 'the order of a new page is a real page\'s, the nearest to all the others', Network_Model::medoid( array( array( 'hero', 'faq', 'cta' ), array( 'hero', 'cards', 'faq', 'cta' ), array( 'hero', 'cards', 'faq', 'form' ), array( 'hero', 'trust', 'cards', 'faq', 'cta' ) ) ) === array( 'hero', 'cards', 'faq', 'cta' ) && Network_Model::distance( array( 'a', 'b', 'c' ), array( 'a', 'c' ) ) === 1 );
$expect( 'the median is the upper middle one, as the research counted',Network_Model::stats( array( 4, 1, 3, 2 ) )['median'] === 3 && Network_Model::stats( array() )['median'] === 0 );

echo "\nThe model kept\n";
$prev = is_multisite() ? get_site_option( Network_Model::OPTION, null ) : get_option( Network_Model::OPTION, null );
$put  = static function () use ( $prev ): void {
	if ( $prev === null ) {
		is_multisite() ? delete_site_option( Network_Model::OPTION ) : delete_option( Network_Model::OPTION );
		return;
	}
	is_multisite() ? update_site_option( Network_Model::OPTION, $prev ) : update_option( Network_Model::OPTION, $prev, false );
};
register_shutdown_function( $put );
Network_Model::store( $model );
$back = Network_Model::get();
$expect( 'what is kept is read back', is_array( $back ) && $back['formulas'] === $model['formulas'] && $back['kinds'] === $model['kinds'] && Network_Model::stored() === $back );
Network_Model::store( array( 'version' => 0 ) + $model );
$expect( 'a model of another version is not read', Network_Model::stored() === null && ( Network_Model::get()['learned'] ?? '' ) !== $model['learned'] );
$put();
if ( $prev === null ) {
	$shipped = Network_Model::get();
	$expect( 'with nothing learned here, the model the plugin ships is the one worked with (25 sites)', is_array( $shipped ) && (int) ( $shipped['sites'] ?? 0 ) === 25 && isset( $shipped['kinds']['service']['recipe'] ), is_array( $shipped ) ? (string) ( $shipped['sites'] ?? '' ) : 'none' );
	$text = (string) file_get_contents( DXAI_UI_DIR . Network_Model::SHIPPED ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$expect( '…and it names no site: no address, nothing site by site, no formula or question fewer than 3 sites use', preg_match( '#https?://|wpengine|\.com\b|\.net\b#i', $text ) !== 1 && ! isset( $shipped['sources'] ) && ! isset( $shipped['twins'] ) && array_filter( array_merge( ...array_values( (array) $shipped['formulas'] ) ), static fn( array $f ): bool => $f['sites'] < 3 ) === array() );
}
$share = Network_Model::shareable( $model );
$expect( 'what can be shipped leaves out the sites and what fewer than 3 of them say', ! isset( $share['sources'] ) && ! isset( $share['twins'] ) && $share['sites'] === 2 && ! isset( $share['formulas']['service'] ) && ! isset( $share['questions']['water'] ) );

echo "\nThe site this runs on, read in place\n";
$made  = array();
$clean = static function () use ( &$made ): void {
	foreach ( $made as $id ) {
		wp_delete_post( (int) $id, true );
	}
	$made = array();
};
register_shutdown_function( $clean );
$blocks = '<!-- wp:group {"tagName":"section","align":"full"} --><section class="wp-block-group alignfull"><!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Network model fixture</h1><!-- /wp:heading --></section><!-- /wp:group -->'
	. '<!-- wp:group {"tagName":"section"} --><section class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">Network model fixture FAQs</h2><!-- /wp:heading --><!-- wp:details --><details class="wp-block-details"><summary>First question?</summary><!-- wp:paragraph --><p>Yes.</p><!-- /wp:paragraph --></details><!-- /wp:details --><!-- wp:details --><details class="wp-block-details"><summary>Second question?</summary><!-- wp:paragraph --><p>No.</p><!-- /wp:paragraph --></details><!-- /wp:details --></section><!-- /wp:group -->';
$p1     = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Network model fixture water damage', 'post_name' => 'network-model-fixture-water-damage', 'post_content' => $blocks ), true );
$made   = is_wp_error( $p1 ) ? array() : array( (int) $p1 );
$here   = Site_Source::current( $made );
$got    = $here['pages'] !== array() ? Network_Model::read_site( $here )['pages'][0] : array();
$expect( 'a page of this site (its saved blocks rendered) reads as its sections: a hero and a FAQ of 2 questions', ! is_wp_error( $p1 ) && array_column( (array) ( $got['sections'] ?? array() ), 'role' ) === array( 'hero', 'faq' ) && ( $got['sections'][1]['asked'] ?? array() ) === array( 'First question?', 'Second question?' ), is_wp_error( $p1 ) ? $p1->get_error_message() : wp_json_encode( array_column( (array) ( $got['sections'] ?? array() ), 'role' ) ) );
$expect( '…a service page by its address, and the site has its name and address', ( $got['kind'] ?? '' ) === 'service' && $here['name'] === html_entity_decode( (string) get_option( 'blogname' ), ENT_QUOTES, 'UTF-8' ) && $here['url'] === trailingslashit( home_url( '/' ) ) );
$expect( 'a subsite is read only on a network (none here)', is_multisite() || ( Site_Source::blog( 1 ) === null && Site_Source::network_blogs() === array() ) );
$clean();

echo "\nThe research's 25 sites, read again by the plugin\n";
$dir    = (string) ( getenv( 'DXAI_NETWORK_RESEARCH' ) ?: dirname( __DIR__ ) . '/.verify/network-research' );
$report = is_readable( $dir . '/report.json' ) ? json_decode( (string) file_get_contents( $dir . '/report.json' ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
if ( ! is_array( $report ) || ! is_dir( $dir . '/data' ) ) {
	echo "  (the research's crawl is not on this machine: this part runs where .verify/network-research is)\n";
} else {
	$ref = array();
	foreach ( $report['sites'] as $s ) {
		foreach ( $s['pages'] as $p ) {
			$ref[ $s['key'] ][ $p['id'] ] = $p;
		}
	}
	$sites = array();
	$same  = array(
		'pages' => 0,
		'roles' => 0,
		'kind'  => 0,
		'home'  => 0,
		'words' => 0,
		'text'  => 0,
		'shape' => 0,
	);
	foreach ( Site_Source::folder( $dir . '/data' ) as $src ) {
		$read_site = Network_Model::read_site( $src );
		$sites[]   = $read_site;
		foreach ( $read_site['pages'] as $p ) {
			$r = $ref[ $read_site['key'] ][ $p['id'] ] ?? null;
			if ( ! is_array( $r ) ) {
				continue;
			}
			++$same['pages'];
			$same['roles'] += array_column( $p['sections'], 'role' ) === array_column( $r['sections'], 'role' ) ? 1 : 0;
			$same['kind']  += $p['kind'] === $r['kind'] ? 1 : 0;
			$same['home']  += array_column( $p['sections'], 'from_home' ) === array_column( $r['sections'], 'from_home' ) ? 1 : 0;
			$same['words'] += $p['words'] === $r['words'] ? 1 : 0;
			$same['text']  += array_column( $p['sections'], 'text_hash' ) === array_column( $r['sections'], 'text_hash' ) ? 1 : 0;
			$same['shape'] += array_column( $p['sections'], 'shape_hash' ) === array_column( $r['sections'], 'shape_hash' ) ? 1 : 0;
		}
	}
	$n = $same['pages'];
	$expect( "every page of the research is read ($n of 637)", $n === 637, (string) $n );
	$expect( 'every page: the same kind, the same sections with the same roles, the same relation to its Home', $same['kind'] === $n && $same['roles'] === $n && $same['home'] === $n, wp_json_encode( $same ) );
	$expect( 'every page: the same words, and each section the same words', $same['words'] === $n && $same['text'] === $n, wp_json_encode( $same ) );
	$expect( 'the builds, section by section, on 636 of 637 pages or more (one T&T page differs by 2 characters of 2 157)', $same['shape'] >= $n - 1, wp_json_encode( $same ) );
	$m   = Network_Model::learn( $sites );
	$svc = $m['kinds']['service'];
	$expect( 'a service page: 192 pages on 24 sites, 11 sections', $svc['pages'] === 192 && $svc['sites'] === 24 && $svc['sections']['median'] === 11, wp_json_encode( array( $svc['pages'], $svc['sites'], $svc['sections'] ) ) );
	$expect( '…hero 100 %, questions 98 %, cards 94 %, a call to action 93 %, the process 81 %', $svc['roles']['hero'] === 1.0 && round( $svc['roles']['faq'], 2 ) === 0.98 && round( $svc['roles']['cards'], 2 ) === 0.94 && round( $svc['roles']['cta'], 2 ) === 0.93 && round( $svc['roles']['process'], 2 ) === 0.81, wp_json_encode( $svc['roles'] ) );
	$expect( '…ends with a call to action (52 %) or the form (41 %); 6 questions in its FAQ', round( $svc['last']['cta'], 2 ) === 0.52 && round( $svc['last']['form'], 2 ) === 0.41 && $svc['faq']['questions']['median'] === 6, wp_json_encode( $svc['last'] ) );
	$expect( '…2 000–2 800 words on a site whose Home is a design', $svc['words_design']['median'] >= 2000 && $svc['words_design']['median'] <= 2800, (string) $svc['words_design']['median'] );
	$f = array_column( (array) $m['formulas']['service'], null, 'formula' );
	$expect( '"Our {Service} Process" on 50 pages, "{Service} FAQs" on 43', ( $f['our {Service} process']['pages'] ?? 0 ) === 50 && ( $f['{Service} faqs']['pages'] ?? 0 ) === 43, wp_json_encode( array_slice( $f, 0, 3 ) ) );
	$expect( 'builds: 1 166, of them 84 on two sites or more', $m['kit']['builds'] === 1166 && $m['kit']['shared'] === 84, wp_json_encode( $m['kit'] ) );
	$table = array();
	foreach ( $m['sources'] as $src ) {
		$table[ $src['key'] ] = array( (int) round( 100 * $src['service']['home_build'] ), (int) round( 100 * $src['service']['home_words'] ) );
	}
	$want = array(
		'amrdevrixmulti.wpenginepowered.com__rocky-restoration'    => array( 40, 18 ),
		'amrdevrixmulti.wpenginepowered.com__taylored-restoration' => array( 38, 15 ),
		'amrdevrixmulti.wpenginepowered.com__tcm-restoration'      => array( 55, 9 ),
		'fivestardisasterservices.net'                             => array( 40, 0 ),
		'semperdrywr.com'                                          => array( 58, 17 ),
	);
	$expect( 'the designed sites\' table: Rocky 40/18, Taylored 38/15, TCM 55/9, Five Star 40/0, Semper 58/17', array_intersect_key( $table, $want ) == $want, wp_json_encode( array_intersect_key( $table, $want ) ) ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- the order of the keys does not matter.
	$expect( 'the order of a service page on the sites whose Home is a design: the hero, the badges, the intro beside a picture, …, the questions, the form (a real page, the nearest to all)', $svc['recipe_from'] === 'design' && $svc['recipe'][0] === 'hero' && end( $svc['recipe'] ) === 'form' && in_array( 'process', $svc['recipe'], true ) && in_array( 'faq', $svc['recipe'], true ) && count( $svc['recipe'] ) >= 9, implode( ' > ', $svc['recipe'] ) );
	$expect( 'twins: Taylored 8 of 9 services, Camelot 26 of 28 cities',$m['twins']['amrdevrixmulti.wpenginepowered.com__taylored-restoration']['service']['twins'] === 8 && $m['twins']['camelotservice.com']['location']['twins'] === 26, wp_json_encode( array( $m['twins']['amrdevrixmulti.wpenginepowered.com__taylored-restoration'] ?? null, $m['twins']['camelotservice.com'] ?? null ) ) );
}

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

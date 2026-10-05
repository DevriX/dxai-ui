<?php
/**
 * Arranging the pages of a design (docs/PLAN-TEAM-PAGES.md, phase 6), against a Home made for the purpose: the plan as a strip of
 * sections (the role, where each is from), a shuffle that makes another page of the same site, a locked section that stays through a
 * shuffle, the three modes (only the Home's sections, also the sections made in its cards, an AI), the report after making, and giving
 * a page or the whole run back.
 *
 *   bash bin/wp-php.sh bin/verify-team-arrange.php        (or: wp eval-file bin/verify-team-arrange.php --user=1)
 *
 * Makes a Home and its pages and removes them again. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Pages\Copy_Writer;
use DXAI_UI\Pages\Team_Pages;
use DXAI_UI\Pages\Team_Run;
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

$heading = static fn( string $t, int $l = 2 ): string => '<!-- wp:heading {"level":' . $l . '} --><h' . $l . ' class="wp-block-heading">' . $t . '</h' . $l . '><!-- /wp:heading -->';
$para    = static fn( string $t ): string => '<!-- wp:paragraph --><p>' . $t . '</p><!-- /wp:paragraph -->';
$button  = static fn( string $t, string $url ): string => '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . $url . '">' . $t . '</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
$section = static fn( string $name, string $class, string $inner ): string => '<!-- wp:group {"metadata":{"name":"' . $name . '"},"className":"' . $class . '","tagName":"section"} --><section class="wp-block-group ' . $class . '">' . $inner . '</section><!-- /wp:group -->';
$card    = static fn( string $t, string $d ): string => '<!-- wp:column {"className":"svc-card"} --><div class="wp-block-column svc-card">' . $heading( $t, 3 ) . $para( $d ) . $button( 'Learn more', '#' ) . '</div><!-- /wp:column -->';
$place   = static fn( string $t ): string => '<!-- wp:group {"className":"place-card"} --><div class="wp-block-group place-card">' . $heading( $t, 3 ) . $para( 'Open 24/7' ) . '</div><!-- /wp:group -->';

$header = '<!-- wp:group {"tagName":"header","className":"site-bar"} --><header class="wp-block-group site-bar"><!-- wp:paragraph --><p>Call (555) 010-0101 · Menu Home About</p><!-- /wp:paragraph --></header><!-- /wp:group -->';
$footer = '<!-- wp:group {"tagName":"footer","className":"site-foot"} --><footer class="wp-block-group site-foot"><!-- wp:paragraph --><p>© Fixture Co. All rights reserved.</p><!-- /wp:paragraph --></footer><!-- /wp:group -->';
$home   = $header
	. $section( 'Hero Section', 'hero-x bg-dark', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'Restoration in Tyler', 1 ) . $para( 'Family run since 1983.' ) . $button( 'Call now', 'tel:5550100101' ) . '</div><!-- /wp:group -->' )
	. $section( 'Services grid', 'svc-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'Restoration Services We Provide' ) . $para( 'What we do.' ) . '<!-- wp:columns --><div class="wp-block-columns">' . $card( 'Water Damage', 'Extraction and drying.' ) . $card( 'Fire Damage', 'Soot and smoke.' ) . $card( 'Mold Removal', 'Containment.' ) . '</div><!-- /wp:columns -->' . '</div><!-- /wp:group -->' )
	. $section( 'Locations', 'loc-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'Where we work' ) . $place( 'Tyler, TX' ) . $place( 'Dallas, TX' ) . '</div><!-- /wp:group -->' )
	. $section( 'Reviews Section', 'rev-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'What customers say' ) . $para( '"Fast and kind." — A customer' ) . '</div><!-- /wp:group -->' )
	. $section( 'FAQ Section', 'faq-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'Frequently asked questions' ) . $para( 'How fast can you come?' ) . $para( 'Within the hour.' ) . '</div><!-- /wp:group -->' )
	. $section( 'CTA Banner', 'cta-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'Water where it should not be?' ) . $button( 'Call (555) 010-0101', 'tel:5550100101' ) . '</div><!-- /wp:group -->' )
	. $footer;

$home_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'draft',
		'post_title'   => 'Fixture Restoration',
		'post_content' => $home,
	)
);
$made = array( (int) $home_id );
$fin  = static function () use ( &$made ): void {
	foreach ( array_reverse( $made ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
};
if ( ! is_int( $home_id ) || $home_id < 1 ) {
	echo "  FAIL  a Home to test on\n";
	exit( 1 );
}
update_post_meta( $home_id, Page_Scope::META, $home_id );
update_post_meta( $home_id, '_dxai_ui_generated_page', '1' );
update_post_meta( $home_id, '_dxai_ui_css_url', 'dxai-ui/fixture-home.css' );

if ( ! current_user_can( 'manage_options' ) ) {
	wp_set_current_user( 1 );
}

/** One line for a page's sections: role and where it is from (the Home's section, or * for one made in its cards), the lock as !. */
$line = static fn( array $strip ): string => implode( ' ', array_map( static fn( $x ) => $x['role'] . ( $x['source'] === '' ? '(-)' : ( $x['source'] === 'new' ? '*' : '' ) . $x['home'] ) . ( $x['locked'] ? '!' : '' ) . ( $x['ops'] !== array() ? '[' . implode( ',', $x['ops'] ) . ']' : '' ), $strip ) );
/** The same without what was done to each section. */
$lay  = static fn( array $strip ): string => implode( ' ', array_map( static fn( $x ) => $x['role'] . '@' . $x['source'] . $x['home'], $strip ) );
$count_pages = static fn( int $home ): int => count( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 100, 'meta_query' => array( array( 'key' => Team_Pages::META, 'compare' => 'EXISTS' ), array( 'key' => Page_Scope::META, 'value' => (string) $home ) ) ) ) );

$wanted = array(
	array( 'type' => 'service', 'title' => 'Water Damage' ),
	array( 'type' => 'service', 'title' => 'Fire Damage' ),
	array( 'type' => 'service', 'title' => 'Mold Removal' ),
	array( 'type' => 'location', 'title' => 'Water Damage in Tyler, TX' ),
	array( 'type' => 'about', 'title' => '' ),
	array( 'type' => 'contact', 'title' => '' ),
	array( 'type' => 'services', 'title' => '' ),
);
$water = $wanted[0];
$with  = static fn( array $item, int $shuffle, int $recipe, array $locks = array() ): array => array_merge( $item, array( 'shuffle' => $shuffle, 'recipe' => $recipe, 'locks' => $locks ) );
$rest  = array_slice( $wanted, 1 );

echo "The plan as a strip of sections\n";
$plan = Team_Pages::plan( $home_id, $wanted );
$expect( 'a page for each thing asked for', count( $plan ) === 7, (string) count( $plan ) );
$ok = true;
foreach ( $plan as $p ) {
	foreach ( $p['strip'] as $x ) {
		$ok = $ok && isset( $x['place'], $x['role'], $x['source'], $x['home'], $x['n'], $x['ops'], $x['locked'] ) && in_array( $x['source'], array( 'home', 'new', '' ), true ) && ( $x['source'] === '' ? $x['home'] === 0 : $x['home'] >= 1 );
	}
}
$expect( 'each section says its role, where it is from (the Home, made in its cards, or none), the number of the Home section, and what was done to it', $ok );
$expect( 'the hero is the first section and is the Home\'s first', count( array_filter( $plan, static fn( $p ) => $p['strip'][0]['role'] !== 'hero' || $p['strip'][0]['home'] !== 1 ) ) === 0 );
$expect( 'the roles of the plan are the strip\'s', count( array_filter( $plan, static fn( $p ) => $p['roles'] !== array_map( static fn( $x ) => array( 'role' => $x['role'], 'source' => $x['source'] ), $p['strip'] ) ) ) === 0 );
$expect( 'the same request, the same plan', $plan === Team_Pages::plan( $home_id, $wanted ) );
$expect( 'a page that is not arranged has no shuffle and nothing locked', count( array_filter( $plan, static fn( $p ) => $p['arrangement'] !== array( 'shuffle' => 0, 'recipe' => 0, 'locks' => array() ) ) ) === 0 );
$expect( 'sections made in the Home\'s cards are said so: some page has one', count( array_filter( $plan, static fn( $p ) => count( array_filter( $p['strip'], static fn( $x ) => $x['source'] === 'new' ) ) > 0 ) ) > 0 );

echo "\nWhat is shown is what is made\n";
$out = Team_Pages::build( $home_id, $wanted );
$ids = array();
if ( ! is_wp_error( $out ) ) {
	foreach ( $out['pages'] as $p ) {
		$made[] = (int) $p['id'];
		$ids[ $p['type'] . '|' . sanitize_title( $p['title'] ) ] = (int) $p['id'];
	}
}
$expect( 'they are made', ! is_wp_error( $out ) && count( $out['pages'] ) === 7 );
if ( is_wp_error( $out ) ) {
	$fin();
	exit( 1 );
}
$same = true;
foreach ( $out['pages'] as $p ) {
	$pl   = array_values( array_filter( $plan, static fn( $q ) => $q['type'] === $p['type'] && $q['title'] === $p['title'] ) )[0] ?? null;
	$same = $same && $pl !== null && $lay( $pl['strip'] ) === $lay( $p['strip'] );
}
$expect( 'each page has the sections the plan showed, from the same Home sections', $same );
$wid = $ids['service|water-damage'] ?? 0;
$expect( 'and as many top-level blocks as sections, plus the header and the footer', count( array_filter( parse_blocks( (string) get_post_field( 'post_content', $wid ) ), static fn( $b ) => ! empty( $b['blockName'] ) ) ) >= count( array_filter( $out['pages'][0]['strip'], static fn( $x ) => $x['source'] !== '' ) ) );

echo "\nShuffle\n";
$strips = array();
$at3    = null;
foreach ( range( 0, 8 ) as $n ) {
	$pl           = Team_Pages::plan( $home_id, array_merge( array( $with( $water, $n, $n ) ), $rest ) );
	$strips[ $n ] = $line( $pl[0]['strip'] );
	if ( $n === 3 ) {
		$at3 = $pl[0];
	}
}
$expect( 'a shuffle makes another page of the same site: the sections or what was done to them change', count( array_unique( $strips ) ) >= 3, (string) count( array_unique( $strips ) ) . ' different of 9' );
$expect( 'the same shuffle makes the same page', $strips[3] === $line( Team_Pages::plan( $home_id, array_merge( array( $with( $water, 3, 3 ) ), $rest ) )[0]['strip'] ) );
$expect( 'shuffle 0 is the page that was not arranged', $strips[0] === $line( $plan[0]['strip'] ) );
$expect( 'the arrangement comes back with the plan, to be sent again', $at3['arrangement']['shuffle'] === 3 && $at3['arrangement']['recipe'] === 3 && $at3['arrangement']['locks'] === array() );
$expect( 'the other pages are not changed by it', array_slice( array_map( static fn( $p ) => $lay( $p['strip'] ), Team_Pages::plan( $home_id, array_merge( array( $with( $water, 3, 3 ) ), $rest ) ) ), 1 ) === array_slice( array_map( static fn( $p ) => $lay( $p['strip'] ), $plan ), 1 ) );

echo "\nLocked sections\n";
$lock_of  = static fn( array $x ): array => array( 'place' => $x['place'], 'role' => $x['role'], 'source' => $x['source'], 'home' => $x['home'], 'n' => $x['n'], 'ops' => $x['ops'] );
$lockable = array_values( array_filter( $at3['strip'], static fn( $x ) => $x['source'] !== '' ) );
$locks    = array( $lock_of( $lockable[0] ), $lock_of( $lockable[ count( $lockable ) - 1 ] ) );
$placed   = array_column( $locks, 'place' );
$kept     = true;
$seeds    = true;
$orders   = array();
$lines    = array();
foreach ( array( 4, 5, 6, 7, 8 ) as $n ) {
	$pl  = Team_Pages::plan( $home_id, array_merge( array( $with( $water, $n, 3, $locks ) ), $rest ) )[0];
	$was = array();
	foreach ( $pl['strip'] as $x ) {
		if ( in_array( $x['place'], $placed, true ) ) {
			$was[ $x['place'] ] = $x;
		}
	}
	foreach ( $locks as $l ) {
		$x    = $was[ $l['place'] ] ?? null;
		$kept = $kept && $x !== null && $x['locked'] && $x['role'] === $l['role'] && $x['home'] === $l['home'] && $x['ops'] === $l['ops'] && $x['source'] === $l['source'];
	}
	foreach ( $pl['strip'] as $x ) {
		$mine  = array_values( array_filter( $locks, static fn( $l ) => $l['place'] === $x['place'] ) )[0] ?? null;
		$seeds = $seeds && $x['n'] === ( $x['locked'] && $mine !== null ? $mine['n'] : $n );
	}
	$orders[ $n ] = implode( ' ', array_column( $pl['strip'], 'role' ) );
	$lines[ $n ]  = $line( $pl['strip'] );
}
$expect( 'a locked section comes back as it was through a shuffle: the same Home section, the same changes', $kept );
$expect( 'and the order of the page is held (the shuffle changes the others)', count( array_unique( $orders ) ) === 1 && $orders[4] === implode( ' ', array_column( $at3['strip'], 'role' ) ) );
$expect( 'the places that are not locked take the new shuffle; the locked keep the one they were made with', $seeds );
$pl = Team_Pages::plan( $home_id, array_merge( array( $with( $water, 4, 3, $locks ) ), $rest ) )[0];
$expect( 'the plan returns the locks that held, for the next request', $pl['arrangement']['locks'] === $locks && $pl['arrangement']['recipe'] === 3 && $pl['arrangement']['shuffle'] === 4, json_encode( $pl['arrangement']['locks'] ) );
$bad = array_merge( $locks, array( array( 'place' => 1, 'role' => 'faq', 'source' => 'home', 'home' => 99, 'n' => 0, 'ops' => array() ), array( 'place' => 2, 'role' => '<script>', 'home' => 1 ), 'x', array( 'place' => -1, 'role' => 'cta', 'home' => 1 ) ) );
$pb  = Team_Pages::plan( $home_id, array_merge( array( $with( $water, 4, 3, $bad ) ), $rest ) )[0];
$expect( 'a lock that does not fit the page (no such section, a role it does not have, rubbish) is dropped, the others hold', $pb['arrangement']['locks'] === $locks && $line( $pb['strip'] ) === $line( $pl['strip'] ) );
$locked_homes = array_map( static fn( $l ) => $l['home'], array_filter( $locks, static fn( $l ) => $l['source'] === 'home' ) );
$stolen       = array_filter( $pl['strip'], static fn( $x ) => $x['source'] === 'home' && ! $x['locked'] && $x['role'] !== 'cta' && in_array( $x['home'], $locked_homes, true ) );
$expect( 'a Home section a lock holds is not taken by another place of the page', $stolen === array(), json_encode( $stolen ) );

echo "\nThe three modes\n";
$made_in = static fn( array $pl ): int => count( array_filter( $pl, static fn( $p ) => count( array_filter( $p['strip'], static fn( $x ) => $x['source'] === 'new' ) ) > 0 ) );
$expect( 'only the Home\'s sections: none is made in its cards', $made_in( Team_Pages::plan( $home_id, $wanted, array( 'mode' => 'home' ) ) ) === 0 );
$expect( 'the usual mode makes some', $made_in( Team_Pages::plan( $home_id, $wanted, array( 'mode' => 'new' ) ) ) > 0 );
$expect( 'an AI\'s plan costs money and is made when the pages are: the plan shows the usual one', Team_Pages::plan( $home_id, $wanted, array( 'mode' => 'ai' ) ) === Team_Pages::plan( $home_id, $wanted, array( 'mode' => 'new' ) ) );
$expect( 'a mode that does not exist is the usual', array_column( Team_Pages::plan( $home_id, $wanted, array( 'mode' => 'nonsense' ) ), 'strip' ) === array_column( $plan, 'strip' ) );
$poured = static fn( array $pages ): int => count( array_filter( $pages, static fn( $p ) => (bool) preg_match( '/\b(related|list|contact):/', (string) get_post_meta( (int) $p['id'], Team_Pages::OPS_META, true ) ) ) );
$ho     = Team_Pages::build( $home_id, $wanted, true, array( 'mode' => 'home' ) );
$expect( 'making the pages in the Home\'s sections only: nothing is poured into cards', ! is_wp_error( $ho ) && count( $ho['pages'] ) === 7 && $poured( $ho['pages'] ) === 0 );
$bk = Team_Pages::build( $home_id, $wanted, true );
$expect( 'and in the usual mode the lists of pages are made again', ! is_wp_error( $bk ) && $poured( $bk['pages'] ) > 0 );

echo "\nThe arrangement is kept with the page\n";
$arr = Team_Pages::build( $home_id, array_merge( array( $with( $water, 4, 3, $locks ) ), $rest ), true );
$of_water = static fn( array $r ): array => array_values( array_filter( $r['pages'], static fn( $p ) => $p['title'] === 'Water Damage' ) )[0]['strip'] ?? array();
$expect( 'a page made shuffled and locked is that page', ! is_wp_error( $arr ) && $lay( $of_water( $arr ) ) === $lay( $pl['strip'] ), is_wp_error( $arr ) ? '' : $lay( $of_water( $arr ) ) . ' | ' . $lay( $pl['strip'] ) );
$meta = json_decode( (string) get_post_meta( $wid, Team_Pages::ARR_META, true ), true );
$expect( 'and says how it was arranged', is_array( $meta ) && $meta['shuffle'] === 4 && $meta['recipe'] === 3 && $meta['locks'] === $locks, json_encode( $meta ) );
$kept_c = (string) get_post_field( 'post_content', $wid );
$next   = Team_Pages::plan( $home_id, $wanted )[0];
$expect( 'planning it again without the arrangement shows the page as it was arranged', $lay( $next['strip'] ) === $lay( $pl['strip'] ) && $next['arrangement']['shuffle'] === 4 );
$rebuilt = Team_Pages::build( $home_id, $wanted, true );
$expect( 'making it again without the arrangement keeps it', ! is_wp_error( $rebuilt ) && (string) get_post_field( 'post_content', $wid ) === $kept_c );
$undone = Team_Pages::build( $home_id, array_merge( array( $with( $water, 0, 0, array() ) ), $rest ), true );
$expect( 'an arrangement can be put away: shuffle 0, nothing locked, the usual page', ! is_wp_error( $undone ) && (string) get_post_meta( $wid, Team_Pages::ARR_META, true ) === '' && $lay( $of_water( $undone ) ) === $lay( $plan[0]['strip'] ), is_wp_error( $undone ) ? '' : $lay( $of_water( $undone ) ) . ' | ' . $lay( $plan[0]['strip'] ) );

echo "\nThe report\n";
$rep = $out['report'];
$expect( 'making the pages reports each of them', count( $rep ) === 7 );
$gates = array_column( $rep[0]['gates'], 'gate' );
$expect( 'against the rules the database answers (header and footer, valid blocks, not copies, nothing foreign)', $gates === array( 'G1', 'G7', 'G8', 'G8W', 'G9' ), implode( ',', $gates ) );
$bad_g = array();
foreach ( $rep as $r ) {
	foreach ( $r['gates'] as $g ) {
		if ( in_array( $g['gate'], array( 'G1', 'G7', 'G9' ), true ) && ! $g['ok'] ) {
			$bad_g[] = $r['title'] . ' ' . $g['gate'] . ': ' . implode( '; ', $g['problems'] );
		}
	}
}
$expect( 'the pages are in the Home\'s frame, valid, and have nothing foreign', $bad_g === array(), implode( ' | ', $bad_g ) );
$expect( 'a gate is ok when it found nothing, and says what it found when it is red', count( array_filter( $rep[0]['gates'], static fn( $g ) => $g['ok'] === ( $g['problems'] === array() ) ) ) === 5 );
$expect( 'the rules that need a browser are named, not pretended', array_keys( \DXAI_UI\Pages\Team_Quality::BROWSER_GATES ) === array( 'G2', 'G3', 'G4', 'G5', 'G6', 'G10' ) );

echo "\nGiving it back\n";
$h2_html = str_replace( 'Restoration in Tyler', 'Restoration In Tyler', $home );
$h2      = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Fixture Give Back', 'post_content' => wp_slash( $h2_html ) ) );
$made[]  = (int) $h2;
update_post_meta( $h2, Page_Scope::META, $h2 );
update_post_meta( $h2, '_dxai_ui_generated_page', '1' );
update_post_meta( $h2, '_dxai_ui_css_url', 'dxai-ui/fixture-back.css' );
$h2_before = (string) get_post_field( 'post_content', $h2 );
$set       = array( array( 'type' => 'service', 'title' => 'Water Damage' ), array( 'type' => 'service', 'title' => 'Fire Damage' ), array( 'type' => 'about', 'title' => '' ) );
$r1        = Team_Pages::build( $h2, $set );
$r1ids     = ! is_wp_error( $r1 ) ? array_column( $r1['pages'], 'id' ) : array();
foreach ( $r1ids as $i ) {
	$made[] = (int) $i;
}
$expect( 'a run is kept: the pages it made, none given back yet', ! is_wp_error( $r1 ) && count( $r1['run']['pages'] ) === 3 && count( array_filter( $r1['run']['pages'], static fn( $p ) => $p['created'] && ! $p['changed'] ) ) === 3 && $r1['run']['at'] > 0 );
$expect( 'the pages are there', $count_pages( $h2 ) === 3 );
$gone = Team_Run::undo( $h2 );
$expect( 'giving the run back moves the pages it made to the trash', ! is_wp_error( $gone ) && count( $gone['removed'] ) === 3 && count( array_filter( $r1ids, static fn( $i ) => get_post_status( (int) $i ) === 'trash' ) ) === 3, is_wp_error( $gone ) ? $gone->get_error_message() : '' );
$expect( 'and never deletes them', count( array_filter( $r1ids, static fn( $i ) => get_post( (int) $i ) !== null ) ) === 3 );
$expect( 'the Home is as it was', (string) get_post_field( 'post_content', $h2 ) === $h2_before );
$expect( 'the run is gone: nothing to give back twice', is_wp_error( Team_Run::undo( $h2 ) ) && Team_Run::summary( $h2 )['pages'] === array() );
$expect( 'the pages in the trash are not "made here": making them again makes new ones', $count_pages( $h2 ) === 0 );

$r2    = Team_Pages::build( $h2, $set, false, array( 'mode' => 'home' ) );
$r2ids = ! is_wp_error( $r2 ) ? array_column( $r2['pages'], 'id' ) : array();
foreach ( $r2ids as $i ) {
	$made[] = (int) $i;
}
$expect( 'again: three pages, new ones in place of the trashed ones', ! is_wp_error( $r2 ) && $count_pages( $h2 ) === 3 && ! array_intersect( $r1ids, $r2ids ) );
$by2 = array();
foreach ( $r2['pages'] as $p ) {
	$by2[ $p['type'] . '|' . sanitize_title( $p['title'] ) ] = (int) $p['id'];
}
$wd_before = (string) get_post_field( 'post_content', $by2['service|water-damage'] );
// The pages were made in the Home's sections only; now the sections made in its cards are added: that changes the pages that list others.
$set2 = array_merge( $set, array( array( 'type' => 'faq', 'title' => '' ) ) );
$r3        = Team_Pages::build( $h2, $set2, false, array( 'mode' => 'new' ) );
$r3new     = ! is_wp_error( $r3 ) ? array_column( array_filter( $r3['pages'], static fn( $p ) => $p['type'] === 'faq' ), 'id' ) : array();
foreach ( $r3new as $i ) {
	$made[] = (int) $i;
}
$wd_after = (string) get_post_field( 'post_content', $by2['service|water-damage'] );
$expect( 'a run that changes pages that were there changes them', ! is_wp_error( $r3 ) && $wd_after !== $wd_before);
$summary = $r3['run']['pages'] ?? array();
$expect( 'and says which it made and which it only changed', count( array_filter( $summary, static fn( $p ) => $p['created'] ) ) === 1 && count( array_filter( $summary, static fn( $p ) => ! $p['created'] ) ) >= 1 );
$new_id  = (int) ( $r3new[0] ?? 0 );
$refused = Team_Run::put_back( $h2, $new_id );
$expect( 'a page the run made cannot be put back by itself: the Home\'s menu opens it, it goes with the run', is_wp_error( $refused ) && $refused->get_error_code() === 'dxai_ui_team_run_new' );
$back = Team_Run::put_back( $h2, $by2['service|water-damage'] );
$expect( 'a page that was there is put back as it was', ! is_wp_error( $back ) && (string) get_post_field( 'post_content', $by2['service|water-damage'] ) === $wd_before );
$expect( 'its records follow: it is not "edited", and has the arrangement it had', ! is_wp_error( $back ) && (string) get_post_meta( $by2['service|water-damage'], Team_Pages::HASH, true ) === md5( $wd_before ) && (string) get_post_meta( $by2['service|water-damage'], Team_Pages::ARR_META, true ) === '' );
$expect( 'it is out of the run', ! in_array( $by2['service|water-damage'], array_column( Team_Run::summary( $h2 )['pages'], 'id' ), true ) );
$expect( 'the same page is not put back twice', is_wp_error( Team_Run::put_back( $h2, $by2['service|water-damage'] ) ) );
wp_update_post( array( 'ID' => $by2['service|fire-damage'], 'post_content' => (string) get_post_field( 'post_content', $by2['service|fire-damage'] ) . "\n<!-- wp:paragraph --><p>Added by a person.</p><!-- /wp:paragraph -->" ) );
$stale = Team_Run::put_back( $h2, $by2['service|fire-damage'] );
$expect( 'a page changed after the run is left as it is', is_wp_error( $stale ) && $stale->get_error_code() === 'dxai_ui_team_run_changed' && str_contains( (string) get_post_field( 'post_content', $by2['service|fire-damage'] ), 'Added by a person.' ) );
$all = Team_Run::undo( $h2 );
$expect( 'giving the whole run back leaves it too, and says so', ! is_wp_error( $all ) && count( $all['skipped'] ) === 1 && $all['skipped'][0]['id'] === $by2['service|fire-damage'] && str_contains( (string) get_post_field( 'post_content', $by2['service|fire-damage'] ), 'Added by a person.' ) );
$expect( 'and the page the run made is in the trash', get_post_status( $new_id ) === 'trash' );
$expect( 'and the Home is as it was', (string) get_post_field( 'post_content', $h2 ) === $h2_before );

echo "\nThe request, through the REST route\n";
$call = static function ( array $params ) use ( $h2 ): array {
	$req = new WP_REST_Request( 'POST', '/' . DXAI_UI_REST_NAMESPACE . '/team-pages' );
	foreach ( $params + array( 'design' => $h2 ) as $k => $v ) {
		$req->set_param( $k, $v );
	}
	$res = rest_do_request( $req );

	return array( 'status' => $res->get_status(), 'data' => $res->get_data() );
};
$pl_r = $call( array( 'action' => 'plan', 'mode' => 'home', 'wanted' => array( $with( $water, 2, 2 ) ) ) );
$expect( 'plan takes a mode and an arrangement', $pl_r['status'] === 200 && ( $pl_r['data']['plan'][0]['arrangement']['shuffle'] ?? -1 ) === 2 );
$bad_r = $call( array( 'action' => 'plan', 'mode' => 'wild', 'wanted' => array( $water ) ) );
$expect( 'and refuses a mode that does not exist', $bad_r['status'] === 400 );
// The pages were last made in the Home's sections only; the usual mode adds the lists of pages, so the run changes them.
$b_r = $call( array( 'action' => 'build', 'wanted' => $set ) );
foreach ( (array) ( $b_r['data']['pages'] ?? array() ) as $p ) {
	$made[] = (int) $p['id'];
}
$expect( 'build returns the report and what the run did', $b_r['status'] === 200 && count( $b_r['data']['report'] ?? array() ) >= 1 && ( $b_r['data']['run']['at'] ?? 0 ) > 0 );
$ix_req = new WP_REST_Request( 'GET', '/' . DXAI_UI_REST_NAMESPACE . '/team-pages' );
$ix_req->set_param( 'design', $h2 );
$expect( 'the index carries the last run, for the panel to offer to undo it', isset( rest_do_request( $ix_req )->get_data()['run']['pages'] ) );
$u_r = $call( array( 'action' => 'undo' ) );
$expect( 'undo gives the run back', $u_r['status'] === 200 && count( $u_r['data']['restored'] ?? array() ) >= 1 );
$expect( 'and nothing to give back twice is a refusal, not a crash', $call( array( 'action' => 'undo' ) )['status'] === 404 );

$fin();

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

<?php
/**
 * The order of the sections of a new page, learned from the team's sites: the same design gets the same recipe,
 * different designs get different ones, none is a copy of a real page, and what every site agrees on is kept.
 *
 *   bash bin/wp-php.sh bin/verify-page-recipes.php        (or: wp eval-file bin/verify-page-recipes.php)
 *
 * Reads data/team-pages.json and nothing of the site. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Pages\Page_Recipes;

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

$model = Page_Recipes::model();
echo "The model\n";
$types = Page_Recipes::types();
$expect( 'it knows the kinds of page the team builds', array_diff( array( 'service', 'location', 'about', 'contact', 'faq', 'testimonials' ), $types ) === array(), implode( ',', $types ) );
$expect( 'and the sites they come from', (int) ( $model['types']['service']['sites'] ?? 0 ) === 11 );

echo "\nOne design\n";
$a = Page_Recipes::pick( 'service', 'Clean Joe' );
$b = Page_Recipes::pick( 'service', 'Clean Joe' );
$expect( 'the same design gets the same recipe every time', $a['roles'] === $b['roles'] );
$expect( 'the recipe is a list of the roles Section_Roles knows', array_diff( $a['roles'], \DXAI_UI\Pages\Section_Roles::ROLES ) === array(), implode( ',', $a['roles'] ) );

echo "\nMany designs\n";
$seeds  = array( 'Clean Joe', 'Semper Dry', 'H2O Away', 'Arcus Restoration', 'Angel Reyes', 'Global Market Launch', 'DevriX Elevate', 'Integration Hub', 'Messaging Architects', 'Zendesk HubSpot', 'Growth Story Hub', 'ICP Activated' );
$orders = array();
foreach ( $seeds as $s ) {
	$orders[ implode( ' ', Page_Recipes::pick( 'service', $s )['roles'] ) ] = true;
}
$expect( 'twelve designs do not all get the same order (at least 6 different)', count( $orders ) >= 6, (string) count( $orders ) );

echo "\nWhat every site agrees on, for every kind and many seeds\n";
$bad_hero = 0;
$bad_tail = 0;
$bad_req  = 0;
$copies   = 0;
$checked  = 0;
foreach ( array( 'service', 'location', 'about', 'contact', 'faq', 'testimonials' ) as $type ) {
	$need = array();
	foreach ( (array) $model['types'][ $type ]['roles'] as $role => $v ) {
		if ( (float) $v['share'] >= 0.95 ) {
			$need[] = (string) $role;
		}
	}
	for ( $i = 0; $i < 60; $i++ ) {
		$r = Page_Recipes::pick( $type, 'seed-' . $i );
		++$checked;
		$roles = $r['roles'];
		$bad_hero += $roles[0] === 'hero' ? 0 : 1;
		$bad_req  += array_diff( $need, $roles ) === array() ? 0 : 1;
		$bad_tail += Page_Recipes::tail( $roles ) === $roles ? 0 : 1;
		// A page of two or three sections (the FAQ page: a hero and a call to action) is the same on every site.
		if ( (float) $model['types'][ $type ]['length']['mean'] >= 7 ) {
			$copies += $r['nearest'] === 0 ? 1 : 0;
		}
	}
}
$expect( "the hero is first ($checked recipes)", $bad_hero === 0, (string) $bad_hero );
$expect( 'every role nearly all pages of the kind have is there', $bad_req === 0, (string) $bad_req );
$expect( 'the tail is related services, questions, the last call to action', $bad_tail === 0, (string) $bad_tail );
$expect( 'none of the longer pages is a copy of a real page', $copies === 0, (string) $copies );

echo "\nThe tail rule\n";
$expect( 'questions before related services and a call to action before the questions are put right', Page_Recipes::tail( array( 'hero', 'faq', 'cards', 'related', 'cta' ) ) === array( 'hero', 'cards', 'related', 'faq', 'cta' ) );
$expect( 'the first call to action stays where it is', Page_Recipes::tail( array( 'hero', 'cta', 'cards', 'faq', 'cta' ) ) === array( 'hero', 'cta', 'cards', 'faq', 'cta' ) );
$expect( 'the distance between two orders counts sections', Page_Recipes::distance( array( 'hero', 'cards', 'faq' ), array( 'hero', 'faq' ) ) === 1 && Page_Recipes::distance( array( 'a' ), array( 'a' ) ) === 0 );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

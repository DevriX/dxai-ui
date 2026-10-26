<?php
/**
 * The checks a page of a design passes before it goes live, against its Home (Network\Page_Quality::gates();
 * docs/PLAN-NETWORK-PAGES.md, 11.5).
 *
 *   bash bin/wp-php.sh bin/verify-page-gates.php        (or: wp eval-file bin/verify-page-gates.php --user=1)
 *
 * A Home of three sections; a page built from them as they are passes every check. Then one thing wrong at a time: a section of
 * another look (build), a class recorded as the design's own that the Home has not (rules: no rule is written for it and the page
 * breaks on a phone), a heading with no words and a picture with no file (empty), a section built like the Home's with three times
 * its words (measure). The Home itself is not measured against itself; a DX Picture with its file is not empty.
 *
 * Makes four pages and deletes them, also when a check dies half-way. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Compiler\Style_Hoister;
use DXAI_UI\Network\Page_Quality;
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

$made = array();
register_shutdown_function(
	static function () use ( &$made ): void {
		foreach ( $made as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}
);
$run  = substr( md5( (string) microtime( true ) ), 0, 6 );
$page = static function ( string $title, string $content ) use ( &$made, $run ): int {
	$id     = (int) wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => $title . ' ' . $run,
			'post_content' => wp_slash( $content ),
		)
	);
	$made[] = $id;

	return $id;
};

$group   = static fn( string $class, string $inner ): string => '<!-- wp:group {"tagName":"section","className":"' . $class . '"} --><section class="wp-block-group ' . $class . '">' . $inner . '</section><!-- /wp:group -->';
$h       = static fn( int $l, string $t ): string => '<!-- wp:heading {"level":' . $l . '} --><h' . $l . ' class="wp-block-heading">' . $t . '</h' . $l . '><!-- /wp:heading -->';
$p       = static fn( string $t ): string => '<!-- wp:paragraph --><p>' . $t . '</p><!-- /wp:paragraph -->';
$card    = static fn( string $t, string $words ): string => '<!-- wp:group {"className":"card"} --><div class="wp-block-group card">' . $h( 3, $t ) . $p( $words ) . '</div><!-- /wp:group -->';
$short   = 'Dried and checked by our own crews within a day of the call.';
$hero    = $group( 'hero relative', $h( 1, 'Restoration in Fairbanks' ) . $p( 'Water, fire and mold, day or night.' ) );
$cards   = static fn( string $words ): string => $group( 'services', $h( 2, 'What we restore' ) . '<!-- wp:group {"className":"grid"} --><div class="wp-block-group grid">' . $card( 'Water', $words ) . $card( 'Fire', $words ) . $card( 'Mold', $words ) . '</div><!-- /wp:group -->' );
$closing = $group( 'call bg-ink', $h( 2, 'Water damage? Call now.' ) . $p( 'We answer at night and send a crew within the hour.' ) );

$home = $page( 'Gates Home', $hero . $cards( $short ) . $closing );
update_post_meta( $home, Page_Scope::META, $home );
update_post_meta( $home, Style_Hoister::DESIGN_NAMES_META, array( 'hidden' ) );
$of = static function ( string $title, string $content, array $names = array( 'hidden' ) ) use ( $page, $home ): array {
	$id = $page( $title, $content );
	update_post_meta( $id, Page_Scope::META, $home );
	update_post_meta( $id, Style_Hoister::DESIGN_NAMES_META, $names );
	$q = Page_Quality::of( $id );

	return array_column( $q['checks'], null, 'id' );
};

echo "A page built from its Home's sections as they are\n";
$good = $of( 'Water Damage', $hero . $cards( $short ) . str_replace( 'call bg-ink', 'call bg-light', $closing ) );
$expect( 'passes every check (a ground of its own aside: the pages trade the Home\'s grounds)', ( $good['build']['state'] ?? '' ) === 'ok' && ( $good['rules']['state'] ?? '' ) === 'ok' && ( $good['empty']['state'] ?? '' ) === 'ok' && ( $good['measure']['state'] ?? '' ) === 'ok', wp_json_encode( array_intersect_key( $good, array_flip( array( 'build', 'rules', 'empty', 'measure' ) ) ) ) );

echo "One thing wrong at a time\n";
$other = $of( 'Fire Damage', $hero . $cards( $short ) . $group( 'team-banner skewed', $h( 2, 'A banner of another look' ) . $p( 'Its own words.' ) ) );
$expect( 'a section of another look: not the Home\'s build', ( $other['build']['state'] ?? '' ) === 'warn' && str_contains( (string) ( $other['build']['text'] ?? '' ), '1 of 3' ), (string) ( $other['build']['text'] ?? '' ) );
$names = $of( 'Mold Remediation', $hero . $cards( $short ) . $closing, array( 'hidden', 'relative', 'items-center' ) );
$expect( 'classes recorded as the design\'s own that the Home has not: no rule, and it says which', ( $names['rules']['state'] ?? '' ) === 'bad' && str_contains( (string) ( $names['rules']['text'] ?? '' ), 'relative' ), (string) ( $names['rules']['text'] ?? '' ) );
$empty = $of( 'Storm Damage', $hero . $group( 'services', $h( 2, '' ) . '<!-- wp:dx/picture {"imageId":0,"imageUrl":""} /-->' . '<!-- wp:dx/picture {"imageId":0,"imageUrl":"https://example.test/crew.jpg"} /-->' ) . $closing );
$expect( 'a heading with no words and a picture with no file: two places empty (a picture with its file is not one)', ( $empty['empty']['state'] ?? '' ) === 'warn' && str_contains( (string) ( $empty['empty']['text'] ?? '' ), '2 places' ), (string) ( $empty['empty']['text'] ?? '' ) );
$long = $of( 'Sewage Cleanup', $hero . $cards( str_repeat( 'Our own crews dry, clean and rebuild every room the water reached. ', 3 ) ) . $closing );
$expect( 'a section built like the Home\'s with three times its words: past the Home\'s length', ( $long['measure']['state'] ?? '' ) === 'warn', (string) ( $long['measure']['text'] ?? '' ) );

echo "The Home\n";
$home_checks = array_column( Page_Quality::of( $home )['checks'], null, 'id' );
$expect( 'is not measured against itself', ! isset( $home_checks['build'] ) && ! isset( $home_checks['rules'] ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

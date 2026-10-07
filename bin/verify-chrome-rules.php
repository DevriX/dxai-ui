<?php
/**
 * A page that borrows its Home's header and footer draws them with their rules.
 *
 *   bash bin/wp-php.sh bin/verify-chrome-rules.php        (or: wp eval-file bin/verify-chrome-rules.php --user=1)
 *
 * A page made for a design (a crawled page of the live site, a page of the team's) is stored as its body only; the header and the footer
 * are put around it as it renders (Page_Chrome), as its Home keeps them. They are not in the page's content, so the rules of their classes
 * (`dxs-` hashes of a block's own CSS, utility classes such as `h-58px`) were never written for the page: the logo came out at its own
 * size, the menu without its spacing, the skip link in view, the footer plain. Style_Rules takes what is added as part of the page now.
 *
 * A design that keeps its header as a template part (a header the menus cannot rebuild exactly: a mega menu) still has its skip link and the box
 * that holds the place of its fixed header in its pages, as the design wrote them before the header. Those travel with a header, they are not
 * one: the part is added after them, where the design had it (Five Star: a Home with no header at all, the skip link taken for it).
 *
 * Makes a Home and a few pages (and a template part) and removes them. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Blocks\Style_Rules;
use DXAI_UI\Chrome\Page_Chrome;
use DXAI_UI\Compiler\Style_Hoister;
use DXAI_UI\Pages\Section_Library;
use DXAI_UI\Structures\Template_Part_Factory;

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
$fin  = static function () use ( &$made ): void {
	foreach ( $made as $id ) {
		wp_delete_post( (int) $id, true );
	}
	$made = array();
};
// Also when the run is cut short (a fatal error in the middle leaves nothing behind).
register_shutdown_function( $fin );

$css_head = 'background:#102030;padding-top:13px';
$css_foot = 'background:#203040;padding-top:17px';
$css_body = 'padding-top:19px;background:#304050';
$para     = '<!-- wp:paragraph --><p>Words</p><!-- /wp:paragraph -->';
$group    = static fn( string $tag, string $class, string $css ): string => '<!-- wp:group {"tagName":"' . $tag . '","className":"' . $class . '","dxaiCss":"' . $css . '"} --><' . $tag . ' class="wp-block-group ' . $class . ' ' . Style_Hoister::css_class( $css ) . '">' . $para . '</' . $tag . '><!-- /wp:group -->';
$header   = $group( 'header', 'h-58px px-6', $css_head );
$footer   = $group( 'footer', 'mb-4-5 px-7', $css_foot );
$body     = $group( 'section', 'py-0', $css_body );

$page = static function ( string $title, string $content, int $scope, bool $blank = true ) use ( &$made ): int {
	$id = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => wp_slash( $content ) ) );
	$made[] = $id;
	if ( $blank ) {
		update_post_meta( $id, '_wp_page_template', 'dxai-blank.php' );
	}
	update_post_meta( $id, '_dxai_ui_scope_id', $scope > 0 ? $scope : $id );
	update_post_meta( $id, '_dxai_ui_generated_page', '1' );

	return $id;
};

// The Home keeps its header and footer in its content (the shape a Home has on a classic theme); the pages are the body only.
$home   = $page( 'Chrome rules fixture Home', $header . "\n\n" . $body . "\n\n" . $footer, 0 );
$bare   = $page( 'Chrome rules fixture page', $body, $home );
$own    = $page( 'Chrome rules fixture page with a header', $header . "\n\n" . $body, $home );
$plain  = $page( 'Chrome rules fixture ordinary page', $body, $home, false );

echo "What the page is given\n";
$with = Page_Chrome::with_chrome( $bare, $body );
$expect( 'the Home\'s header and footer are put around a page that has none', str_contains( $with, 'h-58px' ) && str_contains( $with, 'mb-4-5' ) );

echo "\nIts rules\n";
$rules = Style_Rules::css_for_post( $bare, false );
$expect( 'the rule of the header\'s own CSS is written for the page', str_contains( $rules, Style_Hoister::css_class( $css_head ) ) && str_contains( $rules, '#102030' ) );
$expect( '…and the footer\'s', str_contains( $rules, Style_Hoister::css_class( $css_foot ) ) && str_contains( $rules, '#203040' ) );
$expect( '…and the page\'s own, as before', str_contains( $rules, Style_Hoister::css_class( $css_body ) ) && str_contains( $rules, '#304050' ) );
$expect( 'the utility classes of the header and the footer have their rules (h-58px, mb-4-5)', str_contains( $rules, '.h-58px' ) && str_contains( $rules, '.mb-4-5' ), substr( $rules, 0, 200 ) );

echo "\nOnly what is added\n";
$added = Page_Chrome::rules_markup( array(), $own );
$expect( 'a page with a header of its own is given the footer only', count( $added ) === 1 && str_contains( $added[0], 'mb-4-5' ) && ! str_contains( $added[0], 'h-58px' ), (string) count( $added ) );
$both = Page_Chrome::rules_markup( array( 'x' ), $bare );
$expect( 'a page with neither is given both, after what was there', count( $both ) === 3 && $both[0] === 'x' && str_contains( $both[1], 'h-58px' ) && str_contains( $both[2], 'mb-4-5' ), (string) count( $both ) );
$expect( 'the Home itself is given nothing: it has them in its content', Page_Chrome::rules_markup( array(), $home ) === array() );
$expect( 'a page that is not on the blank template is left alone', Page_Chrome::rules_markup( array( 'x' ), $plain ) === array( 'x' ) );
$expect( 'a value that is not a list of markup comes back as it was', Page_Chrome::rules_markup( 'x', $bare ) === 'x' && Page_Chrome::rules_markup( array(), 0 ) === array() );

echo "\nA header kept as a template part, with the skip link and the box that holds its place left in the page\n";
// <a class="skip">, <div aria-hidden style="height:140px">, <header position:fixed>: the compiler hands the header to a part and leaves the other two in
// the body. Page_Chrome took the skip link for the header the page already had, so the page showed none.
$key    = 'chrome-rules-' . substr( md5( (string) wp_rand() ), 0, 8 );
$part   = ( new Template_Part_Factory() )->save( 'header', 'Chrome rules fixture header', $header, $key, array() );
$made[] = (int) $part;
$skip   = '<!-- wp:dxai-ui/link {"className":"skip"} --><a class="wp-block-dxai-ui-link skip" href="#main">Skip to content</a><!-- /wp:dxai-ui/link -->';
$space  = '<!-- wp:dxai-ui/box {"className":"dxai-dc-1"} --><div class="dxai-dc-1" aria-hidden="true"></div><!-- /wp:dxai-ui/box -->';
$lead   = $skip . "\n\n" . $space . "\n\n" . $body;
$kept   = $page( 'Chrome rules fixture kept-part Home', $lead, 0 );
update_post_meta( $kept, Page_Chrome::PART_KEY_META, $key );
update_post_meta( $kept, Page_Chrome::MODE_META, 'keep' );
$made_for = $page( 'Chrome rules fixture kept-part page', $lead, $kept );
$only     = $page( 'Chrome rules fixture skip-link-only page', $skip . "\n\n" . $body, $kept );
$bar      = $page( 'Chrome rules fixture skip link and header page', $skip . "\n\n" . $header . "\n\n" . $body, $kept );

$at   = static fn( string $html, string $needle ) => strpos( $html, $needle );
$read = static fn( int $id ): string => Page_Chrome::with_chrome( $id, (string) get_post_field( 'post_content', $id ) );
$with = $read( $kept );
$expect( 'a Home that keeps its header as a part is given it: a skip link and a box are not a header', str_contains( $with, 'wp:template-part' ) && str_contains( $with, $key ), substr( $with, 0, 200 ) );
$expect(
	'it goes where the design had it: the skip link first, then the box, then the header, then the sections',
	false !== $at( $with, 'wp-block-dxai-ui-link' ) && $at( $with, 'wp-block-dxai-ui-link' ) < $at( $with, 'dxai-dc-1' ) && $at( $with, 'dxai-dc-1' ) < $at( $with, 'wp:template-part' ) && $at( $with, 'wp:template-part' ) < $at( $with, 'py-0' ),
	wp_json_encode( array( $at( $with, 'wp-block-dxai-ui-link' ), $at( $with, 'dxai-dc-1' ), $at( $with, 'wp:template-part' ), $at( $with, 'py-0' ) ) )
);
$expect( 'nothing else changes: the skip link, the box and the sections are each there once', 1 === substr_count( $with, 'Skip to content' ) && 1 === substr_count( $with, '<!-- wp:dxai-ui/box ' ) && 1 === substr_count( $with, '"tagName":"section"' ) );

$with = $read( $made_for );
$expect( 'a page made for that Home (it carries the Home\'s skip link and box) is given the part, not a second skip link', str_contains( $with, 'wp:template-part' ) && 1 === substr_count( $with, 'Skip to content' ), (string) substr_count( $with, 'Skip to content' ) );
$expect( '…after them as well', $at( $with, 'dxai-dc-1' ) < $at( $with, 'wp:template-part' ) && $at( $with, 'wp:template-part' ) < $at( $with, 'py-0' ) );

$with = $read( $only );
$expect( 'a skip link alone: the part goes right after it', str_contains( $with, 'wp:template-part' ) && $at( $with, 'wp-block-dxai-ui-link' ) < $at( $with, 'wp:template-part' ) && $at( $with, 'wp:template-part' ) < $at( $with, 'py-0' ) );

$with = $read( $bar );
$expect( 'a page that has a header of its own after the skip link is not given a second', 1 === substr_count( $with, '"tagName":"header"' ) && ! str_contains( $with, 'wp:template-part' ), (string) substr_count( $with, '"tagName":"header"' ) );

$added = Page_Chrome::rules_markup( array(), $kept );
$expect( 'the rules of the part are collected for the page, as for any header it is given', 1 === count( $added ) && str_contains( (string) $added[0], 'wp:template-part' ), (string) count( $added ) );

echo "\nWhat is a header\n";
$flat = Section_Library::flatten( parse_blocks( $lead ) );
$run  = Section_Library::chrome_blocks( $flat )['header'];
$expect( 'the skip link and the box are still read as what travels with a header (the copy writer and the page order keep them where they are)', array( 0, 2 ) === $run, wp_json_encode( $run ) );
$expect( 'but they are not a header', ! Section_Library::holds_header( $flat, $run ) );
$flat = Section_Library::flatten( parse_blocks( $skip . "\n\n" . $space . "\n\n" . $header . "\n\n" . $body ) );
$expect( 'a header after them is', Section_Library::holds_header( $flat, Section_Library::chrome_blocks( $flat )['header'] ) );
$full = '<!-- wp:dxai-ui/box {"className":"dxai-dc-9"} --><div class="dxai-dc-9" aria-hidden="true"><!-- wp:paragraph --><p>Hidden words</p><!-- /wp:paragraph --></div><!-- /wp:dxai-ui/box -->';
list( $opening, $rest ) = Section_Library::split_lead( $skip . "\n\n" . $full . "\n\n" . $body );
$expect( 'a box with something in it is not the box that holds a place: it is not taken along with the skip link', str_contains( $opening, 'Skip to content' ) && ! str_contains( $opening, 'Hidden words' ) && str_contains( $rest, 'Hidden words' ) && str_contains( $rest, 'py-0' ), substr( $opening, 0, 120 ) );
list( $opening, $rest ) = Section_Library::split_lead( $body );
$expect( 'content that does not open with them is not split', '' === $opening && $body === $rest );

$fin();
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

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
 * Makes a Home and two pages and removes them. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Blocks\Style_Rules;
use DXAI_UI\Chrome\Page_Chrome;
use DXAI_UI\Compiler\Style_Hoister;

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
};

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

$fin();
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

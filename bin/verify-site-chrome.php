<?php
/**
 * Every page of a multi-page design draws the header from Appearance > Menus and the footer around Appearance > Widgets, as its Home does.
 *
 *   bash bin/wp-php.sh bin/verify-site-chrome.php        (or: wp eval-file bin/verify-site-chrome.php --user=1)
 *
 * Gemini Restoration (a Claude Design site made from an Elementor site) kept its header as the design drew it: its menu is Elementor's,
 * a list whose parents are an HTML block holding `<a href="#">` and the sub-menu after it, and the reader did not see those as dropdowns
 * (`nav_unread`). And each of its twelve other pages drew its own copy of the header and the footer, so a change in Menus reached the Home
 * only. Checked here: the reader takes such a list item for a dropdown and the header renders back from the menus byte for byte; a page's
 * copy of the installed header and footer is taken out (Page_Chrome::adopt_site_chrome()) when it is the same but for the numbers of its
 * interactive elements, and the page is then drawn with the site's, in its own numbers; a copy that differs stays; and a menu link by query
 * (`?page_id=12`) is the current page only on that page.
 *
 * Makes pages and removes them. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Chrome\Header_Menus;
use DXAI_UI\Chrome\Header_Template;
use DXAI_UI\Chrome\Page_Chrome;
use DXAI_UI\Chrome\Site_Footer_Block;
use DXAI_UI\Chrome\Site_Header_Block;

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
	remove_all_filters( 'pre_option_' . Header_Template::OPTION );
	Site_Header_Block::forget();
};
register_shutdown_function( $fin );

// Elementor's nav menu, as the Gemini package draws it: a parent is a list item holding an HTML block (its <a href="#"> and arrow) and
// the sub-menu; the phone's copy of the menu is a second <nav> in a panel the toggle opens.
$sub    = static fn( string $label, string $url ): string => '<!-- wp:list-item {"className":"menu-item"} --><li class="menu-item"><a class="gem-sub-item" href="' . $url . '">' . $label . '</a></li><!-- /wp:list-item -->';
$top    = static fn( string $label, string $url ): string => '<!-- wp:list-item {"className":"menu-item"} --><li class="menu-item"><a class="gem-item" href="' . $url . '">' . $label . '</a></li><!-- /wp:list-item -->';
$parent = static fn( string $label, string $subs, string $dc = '' ): string => '<!-- wp:dxai-ui/box {"tagName":"li","className":"menu-item menu-item-has-children"} --><li class="menu-item menu-item-has-children"><!-- wp:dxai-ui/html --><a class="gem-item has-submenu' . $dc . '" href="#" aria-haspopup="true">' . $label . '<span class="sub-arrow"><svg aria-hidden="true" viewBox="0 0 1 1"><path d="M0 0"></path></svg></span></a><!-- /wp:dxai-ui/html --><!-- wp:list {"className":"sub-menu"} --><ul class="wp-block-list sub-menu">' . $subs . '</ul><!-- /wp:list --></li><!-- /wp:dxai-ui/box -->';
$menu   = static fn( string $dc ): string => '<!-- wp:list {"className":"gem-nav-menu"} --><ul class="wp-block-list gem-nav-menu">' . $parent( 'Services', $sub( 'Water', 'https://example.test/water' ) . $sub( 'Fire', 'https://example.test/fire' ), $dc ) . $parent( 'Areas', $sub( 'New Jersey', 'https://example.test/nj' ), $dc ) . $top( 'About', 'https://example.test/about' ) . $top( 'Contact', 'https://example.test/contact' ) . '</ul><!-- /wp:list -->';
$header = static fn( string $n1, string $n2, string $extra = '' ): string => '<!-- wp:group {"tagName":"header","className":"gem gem-10 gem-location-header' . $extra . '"} --><header class="wp-block-group gem gem-10 gem-location-header' . $extra . '">'
	. '<!-- wp:group {"tagName":"nav","className":"gem-nav-menu--main","ariaLabel":"Menu"} --><nav aria-label="Menu" class="wp-block-group gem-nav-menu--main">' . $menu( '' ) . '</nav><!-- /wp:group -->'
	. '<!-- wp:dxai-ui/box {"className":"gem-menu-toggle dxai-toggle-menuOpen ' . $n1 . '"} --><div class="gem-menu-toggle dxai-toggle-menuOpen ' . $n1 . '">=</div><!-- /wp:dxai-ui/box -->'
	. '<!-- wp:dxai-ui/box {"tagName":"nav","className":"gem-nav-menu--dropdown ' . $n2 . ' dxai-cls-menuOpen","dxaiData":{"data-dxai-on-menuopen":"' . $n2 . '--menuOpen-on"}} --><nav class="gem-nav-menu--dropdown ' . $n2 . ' dxai-cls-menuOpen" data-dxai-on-menuopen="' . $n2 . '--menuOpen-on">' . $menu( ' ' . $n2 . '-sub' ) . '</nav><!-- /wp:dxai-ui/box -->'
	. '</header><!-- /wp:group -->';
$footer = static fn( string $words ): string => '<!-- wp:group {"tagName":"footer","className":"gem gem-66 gem-location-footer"} --><footer class="wp-block-group gem gem-66 gem-location-footer"><!-- wp:paragraph --><p>' . $words . '</p><!-- /wp:paragraph --></footer><!-- /wp:group -->';
$skip   = '<!-- wp:dxai-ui/link {"className":"skip-link","url":"#content","text":"Skip to content"} --><a class="wp-block-dxai-ui-link skip-link" href="#content">Skip to content</a><!-- /wp:dxai-ui/link -->';
$body   = '<!-- wp:group {"tagName":"main","className":"site-main"} --><main class="wp-block-group site-main"><!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph --></main><!-- /wp:group -->';

echo "Elementor's menu read from the header\n";
$home_header = $header( 'dxai-dc-1', 'dxai-dc-2' );
$spec        = Header_Template::build( $home_header );
$tree        = (array) ( $spec['trees'][ Header_Template::NAV ] ?? array() );
$expect( 'its parents are dropdowns, a parent of one sub-item too: four top-level items, two and one sub-items', count( $tree ) === 4 && ( $tree[0]['label'] ?? '' ) === 'Services' && count( (array) ( $tree[0]['children'] ?? array() ) ) === 2 && ( $tree[1]['label'] ?? '' ) === 'Areas' && count( (array) ( $tree[1]['children'] ?? array() ) ) === 1, wp_json_encode( $spec['checks'] ?? null ) );
$expect( 'no link of either menu is left unread (the phone\'s copy is a second place of the same menu)', 0 === (int) ( $spec['checks']['unread_links'] ?? -1 ), wp_json_encode( $spec['checks'] ?? null ) );
$verified = Header_Menus::verify( $spec );
$expect( 'the header rendered from the menus is the design\'s, byte for byte', ! empty( $verified['ok'] ), wp_json_encode( $verified ) );

echo "\nA page's own copy of the header and footer\n";
$page = static function ( string $title, string $content, int $home = 0 ) use ( &$made ): int {
	$id     = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => wp_slash( $content ) ) );
	$made[] = $id;
	update_post_meta( $id, '_dxai_ui_generated_page', '1' );
	update_post_meta( $id, '_dxai_ui_scope_id', $id );
	update_post_meta( $id, Page_Chrome::MODE_META, 'install' );

	return $id;
};
$home    = $page( 'Site chrome fixture Home', $skip . $body );
$same    = $page( 'Site chrome fixture page with the same header', $skip . $header( 'dxai-dc-11', 'dxai-dc-12' ) . $body . $footer( 'Footer words' ) );
$other   = $page( 'Site chrome fixture page with another header', $skip . $header( 'dxai-dc-21', 'dxai-dc-22', ' gem-transparent' ) . $body . $footer( 'Other footer' ) );
$sources = array(
	'header' => $home_header,
	'footer' => $footer( 'Footer words' ),
);
$taken = Page_Chrome::adopt_site_chrome( $same, $home, $sources );
$after = (string) get_post_field( 'post_content', $same );
$expect( 'the copy of the installed header and footer is taken out of the page', $taken === array( 'header', 'footer' ) && ! str_contains( $after, 'gem-location-header' ) && ! str_contains( $after, 'gem-location-footer' ) && str_contains( $after, 'Body' ) && str_contains( $after, 'skip-link' ), wp_json_encode( $taken ) );
$meta = get_post_meta( $same, Page_Chrome::SITE_CHROME_META, true );
$expect( '…and the page records its Home and its own numbers for the header\'s interactive elements', is_array( $meta ) && $home === (int) $meta['home'] && ( $meta['header'] ?? array() ) === array( 'dxai-dc-1' => 'dxai-dc-11', 'dxai-dc-2' => 'dxai-dc-12' ), wp_json_encode( $meta ) );
$expect( 'a page drawing another header (a modifier class) and another footer keeps its copies, and records nothing', Page_Chrome::adopt_site_chrome( $other, $home, $sources ) === array() && str_contains( (string) get_post_field( 'post_content', $other ), 'gem-transparent' ) && str_contains( (string) get_post_field( 'post_content', $other ), 'Other footer' ) && '' === get_post_meta( $other, Page_Chrome::SITE_CHROME_META, true ) );
$home_copy = $page( 'Site chrome fixture Home that keeps its header', $skip . $home_header . $body );
$expect( 'the Home itself is never taken from, even when its content holds the header', Page_Chrome::adopt_site_chrome( $home_copy, $home_copy, $sources ) === array() && str_contains( (string) get_post_field( 'post_content', $home_copy ), 'gem-location-header' ) );
$expect( 'the design\'s numbers are the page\'s own when its header is drawn', Page_Chrome::own_ids( $same, '<div class="dxai-dc-1 dxai-dc-10" data-on="dxai-dc-2--menuOpen-on"></div>' ) === '<div class="dxai-dc-11 dxai-dc-10" data-on="dxai-dc-12--menuOpen-on"></div>' );

echo "\nThe page is drawn with the site's header and footer\n";
$expect( 'with no header installed for its Home, the page is given none from it', Page_Chrome::header_markup( $same ) === '' || ! str_contains( Page_Chrome::header_markup( $same ), 'site-header' ) );
$stored                       = $spec;
$stored['source']['scope_id'] = $home;
add_filter( 'pre_option_' . Header_Template::SCOPE_OPTION . $home, static fn() => $stored );
// The site's header is another design's now: the page still draws its own Home's.
$elsewhere                       = $spec;
$elsewhere['source']['scope_id'] = $home + 100000;
add_filter( 'pre_option_' . Header_Template::OPTION, static fn() => $elsewhere );
Site_Header_Block::forget();
$expect( 'its Home\'s header installed from Menus, the page is given the site header block', Page_Chrome::header_markup( $same ) === Site_Header_Block::MARKUP );
$expect( '…which renders its Home\'s header for it', ( Header_Template::for_post( $same )['source']['scope_id'] ?? 0 ) === $home );
$expect( 'a page that kept its own copy is not given it', Page_Chrome::header_markup( $other ) !== Site_Header_Block::MARKUP );
add_filter( 'pre_option_' . \DXAI_UI\Chrome\Footer_Template::OPTION, static fn() => array( 'scope' => $home, 'slots' => array(), 'template' => '<!-- wp:paragraph --><p>Widgets</p><!-- /wp:paragraph -->' ) );
$expect( 'its Home\'s footer installed around Widgets, the page is given the site footer block', Page_Chrome::footer_markup( $same ) === Site_Footer_Block::MARKUP );
remove_all_filters( 'pre_option_' . \DXAI_UI\Chrome\Footer_Template::OPTION );
remove_all_filters( 'pre_option_' . Header_Template::SCOPE_OPTION . $home );
remove_all_filters( 'pre_option_' . Header_Template::OPTION );
Site_Header_Block::forget();

echo "\nThe current page in a menu of links by query\n";
$is_current = new ReflectionMethod( Header_Menus::class, 'is_current' );
$is_current->setAccessible( true );
$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $same ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$item                    = static fn( int $id ) => (object) array( 'type' => 'custom', 'url' => home_url( '/?page_id=' . $id ), 'object_id' => 0 );
$expect( 'a link to this page by its query is current on it', $is_current->invoke( null, $item( $same ) ) );
$expect( '…and a link to another page by query is not: the two share the site\'s path, not their page', ! $is_current->invoke( null, $item( $home ) ) );
wp_reset_query();

$fin();
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

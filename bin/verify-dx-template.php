<?php
/**
 * The DX template draws a design's header and footer itself, and the pages of every earlier import render as they did.
 *
 *   bash bin/wp-php.sh bin/verify-dx-template.php        (or: wp eval-file bin/verify-dx-template.php --user=1)
 *
 * Until now a design's pages were on DX Blank, which puts the header and the footer into the page's content as it renders
 * (Page_Chrome), where the page's scope element wraps them with the rest. The DX template (`templates/dxai-template.php`,
 * Template_Chrome) draws them as a template does — the header, the page, the footer — inside the same scope element and in the same
 * order, so the markup is the one DX Blank made and every rule scoped to the design, and the design's state machine, see the same page.
 * What the page stores is its body; a skip link or a header spacer that the content loses (the spacer is an empty block, and deleting it
 * is a click) is drawn from the import's copy, so the content never starts under a fixed header.
 *
 * Makes a Home and a few pages and removes them. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Chrome\Header_Template;
use DXAI_UI\Chrome\Page_Chrome;
use DXAI_UI\Chrome\Template_Chrome;
use DXAI_UI\Compiler\Style_Hoister;
use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Theme\Blank_Template;

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
register_shutdown_function( $fin );

$css_head = 'background:#102030;padding-top:13px';
$css_foot = 'background:#203040;padding-top:17px';
$css_body = 'padding-top:19px;background:#304050';
$para     = '<!-- wp:paragraph --><p>Words</p><!-- /wp:paragraph -->';
$group    = static fn( string $tag, string $class, string $css ): string => '<!-- wp:group {"tagName":"' . $tag . '","className":"' . $class . '","dxaiCss":"' . $css . '"} --><' . $tag . ' class="wp-block-group ' . $class . ' ' . Style_Hoister::css_class( $css ) . '">' . $para . '</' . $tag . '><!-- /wp:group -->';
$header   = $group( 'header', 'h-58px px-6', $css_head );
$footer   = $group( 'footer', 'mb-4-5 px-7', $css_foot );
$body     = $group( 'section', 'py-0', $css_body );
$skip     = '<!-- wp:dxai-ui/link {"className":"skip"} --><a class="wp-block-dxai-ui-link skip" href="#main">Skip to content</a><!-- /wp:dxai-ui/link -->';
$space    = '<!-- wp:dxai-ui/box {"className":"dxai-dc-1","ariaHidden":"true"} --><div class="dxai-dc-1" aria-hidden="true"></div><!-- /wp:dxai-ui/box -->';

$page = static function ( string $title, string $content, int $scope, string $template ) use ( &$made ): int {
	$id     = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => wp_slash( $content ) ) );
	$made[] = $id;
	if ( $template !== '' ) {
		update_post_meta( $id, '_wp_page_template', $template );
	}
	update_post_meta( $id, '_dxai_ui_scope_id', $scope > 0 ? $scope : $id );
	update_post_meta( $id, '_dxai_ui_generated_page', '1' );
	// A converted page carries its own design (Design_Attach::resolve()): without it a page that holds the plugin's blocks is an ordinary page
	// showing a design, which the scope element goes around block by block and not around the page.
	update_post_meta( $id, '_dxai_ui_css_url', 'https://example.test/dx-template-fixture.css' );
	\DXAI_UI\Structures\Design_Attach::forget( $id );

	return $id;
};

/** A page the way the front end reads it: the global post set, the content through the content filters. */
$through = static function ( int $id ): string {
	$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	setup_postdata( $GLOBALS['post'] );
	$out = (string) apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) );
	wp_reset_postdata();

	return $out;
};
/** …and the way the DX template draws it: open, the content, close. */
$drawn = static function ( int $id ): string {
	$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	setup_postdata( $GLOBALS['post'] );
	ob_start();
	Template_Chrome::open( $id );
	the_content();
	Template_Chrome::close( $id );
	$out = (string) ob_get_clean();
	wp_reset_postdata();

	return $out;
};
$norm = static fn( string $html ): string => trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/>\s+</', '><', $html ) ) );
$at   = static fn( string $html, string $needle ) => strpos( $html, $needle );
$same = static fn( string $html, int $id ): string => str_replace( 'dxai-ui--' . $id, 'dxai-ui--S', $html );

echo "The templates\n";
$templates = apply_filters( 'theme_page_templates', array(), null, null, 'page' );
$expect( 'both canvas templates are page templates', isset( $templates[ Blank_Template::TEMPLATE ], $templates[ Blank_Template::SLUG ] ), wp_json_encode( $templates ) );
$expect( 'DX Blank is named as what it is now: the template of earlier imports', str_contains( (string) $templates[ Blank_Template::SLUG ], 'earlier' ) );
$expect( 'a design\'s pages are given the DX template', Blank_Template::default_slug() === Blank_Template::TEMPLATE );
add_filter( 'dxai_ui_default_template', static fn() => Blank_Template::SLUG );
$expect( '…unless the site says DX Blank', Blank_Template::default_slug() === Blank_Template::SLUG );
remove_all_filters( 'dxai_ui_default_template' );
add_filter( 'dxai_ui_default_template', static fn() => 'page.php' );
$expect( '…and a name that is no canvas template is not taken', Blank_Template::default_slug() === Blank_Template::TEMPLATE );
remove_all_filters( 'dxai_ui_default_template' );
$expect( 'the template file ships', is_readable( DXAI_UI_DIR . 'templates/' . Blank_Template::TEMPLATE ) && is_readable( DXAI_UI_DIR . 'templates/' . Blank_Template::SLUG ) );
$expect( 'both slugs are canvas templates, no other is', Blank_Template::is_canvas_slug( Blank_Template::SLUG ) && Blank_Template::is_canvas_slug( Blank_Template::TEMPLATE ) && ! Blank_Template::is_canvas_slug( 'page.php' ) && ! Blank_Template::is_canvas_slug( '' ) );

// The Home keeps the skip link, the spacer, its header and its footer in its content (the shape a Home has on a classic theme); the
// pages made for it carry the skip link and the spacer and borrow the Home's header and footer (Page_Chrome::home_chrome()).
$lead_home  = $skip . "\n\n" . $space;
$home_blank = $page( 'DX template fixture Home (blank)', $header . "\n\n" . $body . "\n\n" . $footer, 0, Blank_Template::SLUG );
$home_tpl   = $page( 'DX template fixture Home', $header . "\n\n" . $body . "\n\n" . $footer, 0, Blank_Template::TEMPLATE );
$own_blank  = $page( 'DX template fixture Home with its own opening (blank)', $lead_home . "\n\n" . $header . "\n\n" . $body . "\n\n" . $footer, 0, Blank_Template::SLUG );
$own_tpl    = $page( 'DX template fixture Home with its own opening', $lead_home . "\n\n" . $header . "\n\n" . $body . "\n\n" . $footer, 0, Blank_Template::TEMPLATE );
$sub_blank  = $page( 'DX template fixture page (blank)', $lead_home . "\n\n" . $body, $home_blank, Blank_Template::SLUG );
$sub_tpl    = $page( 'DX template fixture page', $lead_home . "\n\n" . $body, $home_tpl, Blank_Template::TEMPLATE );

echo "\nThe pages of earlier imports (DX Blank)\n";
$legacy = $through( $sub_blank );
$expect( 'the chrome is still put into the content as it renders: the Home\'s header and footer around the page', str_contains( $legacy, 'h-58px' ) && str_contains( $legacy, 'mb-4-5' ) && str_contains( $legacy, 'py-0' ) );
$expect( '…inside one scope element', 1 === substr_count( $legacy, 'wp-block-group alignfull dxai-ui' ), (string) substr_count( $legacy, 'wp-block-group alignfull dxai-ui' ) );
$expect( 'Page_Chrome adds it to DX Blank pages, as it did', Page_Chrome::applies_to( $sub_blank ) && Page_Chrome::applies_to( $home_blank ) );

echo "\nThe DX template\n";
$expect( 'the template draws the chrome, so the content is not given it as it renders', ! Page_Chrome::applies_to( $sub_tpl ) && Page_Chrome::composes( $sub_tpl ) && Page_Chrome::composes( $sub_blank ) );
$outside = $through( $sub_tpl );
$expect( 'the same page read outside the template (a REST preview, a feed) is its body in its scope element, with no header or footer added', ! str_contains( $outside, 'h-58px' ) && ! str_contains( $outside, 'mb-4-5' ) && str_contains( $outside, 'dxai-ui--' . $home_tpl ) && 1 === substr_count( $outside, 'wp-block-group alignfull dxai-ui' ), substr( $outside, 0, 160 ) );
$expect( 'its stored content is the body: nothing of the chrome is in it', ! str_contains( (string) get_post_field( 'post_content', $sub_tpl ), 'h-58px' ) && ! str_contains( (string) get_post_field( 'post_content', $sub_tpl ), 'mb-4-5' ) );

$mine = $drawn( $sub_tpl );
$expect( 'drawn by the template, the page is the markup DX Blank made, byte for byte once the scope ids are the same', $norm( $same( $mine, $home_tpl ) ) === $norm( $same( $legacy, $home_blank ) ), strlen( $mine ) . ' vs ' . strlen( $legacy ) );
$expect( 'one scope element around it all', 1 === substr_count( $mine, 'wp-block-group alignfull dxai-ui' ) && str_ends_with( trim( $mine ), '</div>' ), (string) substr_count( $mine, 'wp-block-group alignfull dxai-ui' ) );
$order = array( $at( $mine, 'Skip to content' ), $at( $mine, 'dxai-dc-1' ), $at( $mine, 'h-58px' ), $at( $mine, 'py-0' ), $at( $mine, 'mb-4-5' ) );
$expect( 'in the order of the page: the skip link, the spacer, the header, the content, the footer', ! in_array( false, $order, true ) && $order[0] < $order[1] && $order[1] < $order[2] && $order[2] < $order[3] && $order[3] < $order[4], wp_json_encode( $order ) );
$expect( 'each of them once', 1 === substr_count( $mine, 'Skip to content' ) && 1 === substr_count( $mine, 'dxai-dc-1' ) && 1 === substr_count( $mine, 'h-58px' ) && 1 === substr_count( $mine, 'mb-4-5' ) );
$expect( 'after the template is done the page is read as before: the scope element is the content\'s again', ! Template_Chrome::wraps( $sub_tpl ) && 1 === substr_count( $through( $sub_tpl ), 'wp-block-group alignfull dxai-ui' ) );

$home_out = $drawn( $own_tpl );
$expect( 'a Home that carries its own header and footer is not given a second', 1 === substr_count( $home_out, 'h-58px' ) && 1 === substr_count( $home_out, 'mb-4-5' ) && 1 === substr_count( $home_out, 'Skip to content' ), $home_out === '' ? 'empty' : '' );
$expect( '…and is drawn as DX Blank drew it', $norm( $same( $home_out, $own_tpl ) ) === $norm( $same( $through( $own_blank ), $own_blank ) ) );

echo "\nWhat a page loses\n";
// The import keeps the Home's skip link and spacer; the spacer is an empty block and an editor deletes it.
$expect( 'the import keeps the blocks that open the Home', ( function () use ( $own_tpl ) {
	Template_Chrome::remember_lead( $own_tpl );
	$kept = (string) get_post_meta( $own_tpl, Template_Chrome::LEAD_META, true );

	return str_contains( $kept, 'Skip to content' ) && str_contains( $kept, 'dxai-dc-1' );
} )() );
Template_Chrome::remember_lead( $own_tpl );
update_post_meta( $home_tpl, Template_Chrome::LEAD_META, wp_slash( (string) get_post_meta( $own_tpl, Template_Chrome::LEAD_META, true ) ) );
$bare = $page( 'DX template fixture bare Home', $header . "\n\n" . $body . "\n\n" . $footer, 0, Blank_Template::TEMPLATE );
Template_Chrome::remember_lead( $bare );
$expect( 'a Home that does not open with them keeps nothing', '' === (string) get_post_meta( $bare, Template_Chrome::LEAD_META, true ) );

$no_space = $page( 'DX template fixture page without the spacer', $skip . "\n\n" . $body, $home_tpl, Blank_Template::TEMPLATE );
$out      = $drawn( $no_space );
$expect( 'a page that lost the spacer is drawn with it, between the skip link and the header', 1 === substr_count( $out, 'dxai-dc-1' ) && $at( $out, 'Skip to content' ) < $at( $out, 'dxai-dc-1' ) && $at( $out, 'dxai-dc-1' ) < $at( $out, 'h-58px' ), wp_json_encode( array( $at( $out, 'Skip to content' ), $at( $out, 'dxai-dc-1' ), $at( $out, 'h-58px' ) ) ) );
$expect( '…once, and the skip link it still had is its own', 1 === substr_count( $out, 'Skip to content' ) );
$none = $page( 'DX template fixture page with neither', $body, $home_tpl, Blank_Template::TEMPLATE );
$out  = $drawn( $none );
$expect( 'a page that lost both is drawn with both', 1 === substr_count( $out, 'Skip to content' ) && 1 === substr_count( $out, 'dxai-dc-1' ) && $at( $out, 'dxai-dc-1' ) < $at( $out, 'h-58px' ) );
$expect( 'the same page on DX Blank is not given what it lost: that template is as it was', ! str_contains( $through( $page( 'DX template fixture blank page without them', $body, $home_blank, Blank_Template::SLUG ) ), 'dxai-dc-1' ) );
$rules = Template_Chrome::rules_markup( array(), $no_space );
$expect( 'the rule of what is put back is collected with the page\'s', 1 === count( $rules ) && str_contains( (string) $rules[0], 'dxai-dc-1' ) && str_contains( (string) $rules[0], 'Skip to content' ), wp_json_encode( $rules ) );
$expect( 'a page that lost nothing adds no markup for its rules', array() === Template_Chrome::rules_markup( array(), $sub_tpl ) );
$expect( 'a value that is not a list comes back as it was, and a page of no design is left alone', 'x' === Template_Chrome::rules_markup( 'x', $sub_tpl ) && array( 'x' ) === Template_Chrome::rules_markup( array( 'x' ), 0 ) );

echo "\nA header built from Menus\n";
// The install path: the design's header is the site header block, filled from the menus, and the page is given it.
$stored = array_merge(
	Header_Template::build( '<!-- wp:group {"tagName":"header","className":"h-58px"} --><header class="wp-block-group h-58px"><!-- wp:group {"tagName":"nav"} --><nav class="wp-block-group"><!-- wp:dxai-ui/link {"url":"#a","text":"One"} --><a class="wp-block-dxai-ui-link" href="#a">One</a><!-- /wp:dxai-ui/link --><!-- wp:dxai-ui/link {"url":"#b","text":"Two"} --><a class="wp-block-dxai-ui-link" href="#b">Two</a><!-- /wp:dxai-ui/link --></nav><!-- /wp:group --></header><!-- /wp:group -->' ),
	array( 'source' => array( 'scope_id' => 0 ) )
);
$menu_home = $page( 'DX template fixture installed Home', $lead_home . "\n\n" . $body, 0, Blank_Template::TEMPLATE );
$menu_page = $page( 'DX template fixture installed page', $lead_home . "\n\n" . $body, $menu_home, Blank_Template::TEMPLATE );
$menu_blank = $page( 'DX template fixture installed page (blank)', $lead_home . "\n\n" . $body, $menu_home, Blank_Template::SLUG );
$stored['source']['scope_id'] = $menu_home;
$stored['version']            = Header_Template::VERSION;
add_filter( 'pre_option_' . Header_Template::OPTION, static fn() => $stored );
\DXAI_UI\Chrome\Site_Header_Block::forget();
update_post_meta( $menu_home, Page_Chrome::MODE_META, 'install' );
$expect( 'the page is given the site header block (built from the menus)', str_contains( Page_Chrome::header_markup( $menu_page ), 'dxai-ui/site-header' ) );
$with_menus = $drawn( $menu_page );
$blank_menus = $through( $menu_blank );
$expect( 'the header the template draws is the menus\' own, in the page\'s scope element, before the content', 1 === substr_count( $with_menus, '<header' ) && str_contains( $with_menus, '>One<' ) && str_contains( $with_menus, '>Two<' ) && $at( $with_menus, '<header' ) < $at( $with_menus, 'py-0' ) && 1 === substr_count( $with_menus, 'wp-block-group alignfull dxai-ui' ), substr( $with_menus, 0, 200 ) );
$expect( '…and the page DX Blank makes is the same markup', $norm( $same( $with_menus, $menu_home ) ) === $norm( $same( $blank_menus, $menu_home ) ) );
// The quality measure (Team_Quality, bin/team-quality.cjs) takes what opens a page off before it reads the sections: it is told how many elements
// that is. A header the template draws is one of them: left out of the count, it was read as a section, and a page whose title is a word of the
// header's menu (a page made from the menu) was paired with the header.
$q_menu = \DXAI_UI\Pages\Team_Quality::measure( $menu_home )['home']['chrome'];
$expect( 'the quality measure counts what opens a page the template draws: the Home\'s skip link and spacer, and the header the menus made after them', 3 === $q_menu['header'], wp_json_encode( $q_menu ) );
$q_own = \DXAI_UI\Pages\Team_Quality::measure( $own_tpl )['home']['chrome'];
$expect( '…and not one more for a Home whose content holds its header: the skip link, the spacer and the header, and its footer', 3 === $q_own['header'] && 1 === $q_own['footer'], wp_json_encode( $q_own ) );
remove_all_filters( 'pre_option_' . Header_Template::OPTION );
\DXAI_UI\Chrome\Site_Header_Block::forget();

echo "\nA page of no design on the template\n";
$plain_id = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'DX template fixture ordinary page', 'post_content' => wp_slash( $para ) ) );
$made[]   = $plain_id;
update_post_meta( $plain_id, '_wp_page_template', Blank_Template::TEMPLATE );
add_filter( 'pre_option_' . Header_Template::OPTION, static fn() => array() );
add_filter( 'pre_option_' . \DXAI_UI\Chrome\Footer_Template::OPTION, static fn() => array() );
\DXAI_UI\Chrome\Site_Header_Block::forget();
$plain_out = $drawn( $plain_id );
$expect( 'with no site header or footer installed it is its content and nothing else: no scope element, no header', ! str_contains( $plain_out, 'dxai-ui--' ) && ! str_contains( $plain_out, '<header' ) && str_contains( $plain_out, 'Words' ), substr( $plain_out, 0, 120 ) );
remove_all_filters( 'pre_option_' . Header_Template::OPTION );
remove_all_filters( 'pre_option_' . \DXAI_UI\Chrome\Footer_Template::OPTION );
\DXAI_UI\Chrome\Site_Header_Block::forget();
$stored['source']['scope_id'] = $menu_home;
add_filter( 'pre_option_' . Header_Template::OPTION, static fn() => $stored );
\DXAI_UI\Chrome\Site_Header_Block::forget();
$plain_out = $drawn( $plain_id );
$expect( 'with a site header installed it is drawn before the content, in the scope element of the design it came from', 1 === substr_count( $plain_out, '<header' ) && str_contains( $plain_out, 'dxai-ui--' . $menu_home ) && $at( $plain_out, '<header' ) < $at( $plain_out, 'Words' ), substr( $plain_out, 0, 200 ) );
$expect( '…and nothing else when the design kept no opening blocks: it has none to lend', ! str_contains( $plain_out, 'Skip to content' ) && ! str_contains( $plain_out, 'dxai-dc-1' ) );

// A fixed header leaves its place to a box at the top of the page, which a page of no design does not carry: the design the header came
// from lends the skip link and the spacer it kept, with their rules (the skip link is hidden by a rule of its own).
$skip_css  = 'position:absolute;left:-9999px';
$space_css = 'display:block;height:58px';
$lend      = '<!-- wp:dxai-ui/link {"className":"skip","dxaiCss":"' . $skip_css . '"} --><a class="wp-block-dxai-ui-link skip ' . Style_Hoister::css_class( $skip_css ) . '" href="#main">Skip to content</a><!-- /wp:dxai-ui/link -->'
	. "\n\n" . '<!-- wp:dxai-ui/box {"className":"dxai-dc-1","ariaHidden":"true","dxaiCss":"' . $space_css . '"} --><div class="dxai-dc-1 ' . Style_Hoister::css_class( $space_css ) . '" aria-hidden="true"></div><!-- /wp:dxai-ui/box -->';
update_post_meta( $menu_home, Template_Chrome::LEAD_META, wp_slash( $lend ) );
$inline       = static fn(): string => (string) implode( '', (array) ( wp_styles()->get_data( 'dxai-ui-rules', 'after' ) ?: array() ) );
$rules_before = $inline();
$plain_out    = $drawn( $plain_id );
$rules_after  = $inline();
$expect( 'a page of no design is given the skip link and the spacer of the design the header came from, before the header, once each', 1 === substr_count( $plain_out, 'Skip to content' ) && 1 === substr_count( $plain_out, 'dxai-dc-1' ) && 1 === substr_count( $plain_out, '<header' ) && $at( $plain_out, 'Skip to content' ) < $at( $plain_out, 'dxai-dc-1' ) && $at( $plain_out, 'dxai-dc-1' ) < $at( $plain_out, '<header' ) && $at( $plain_out, '<header' ) < $at( $plain_out, 'Words' ), wp_json_encode( array( $at( $plain_out, 'Skip to content' ), $at( $plain_out, 'dxai-dc-1' ), $at( $plain_out, '<header' ), $at( $plain_out, 'Words' ) ) ) );
$expect( '…in the scope element of that design, and the page\'s own words once', 1 === substr_count( $plain_out, 'dxai-ui--' . $menu_home ) && 1 === substr_count( $plain_out, 'Words' ), (string) substr_count( $plain_out, 'dxai-ui--' . $menu_home ) );
$expect( '…with their rules: the skip link is hidden and the spacer has its height', str_contains( $rules_after, 'left:-9999px' ) && str_contains( $rules_after, 'height:58px' ) && ! str_contains( $rules_before, 'left:-9999px' ), substr( $rules_after, 0, 160 ) );
$site_top = Template_Chrome::site_header();
$expect( 'a theme\'s header.php (DX Base) draws the same: the skip link and the spacer, then the header, in that design\'s scope element', 1 === substr_count( $site_top, 'Skip to content' ) && 1 === substr_count( $site_top, 'dxai-dc-1' ) && 1 === substr_count( $site_top, '<header' ) && $at( $site_top, 'dxai-dc-1' ) < $at( $site_top, '<header' ) && str_contains( $site_top, 'dxai-ui--' . $menu_home ), substr( $site_top, 0, 200 ) );
$expect( 'the page read outside the template (a REST preview, a feed) is its own content: nothing is lent to it', ! str_contains( $through( $plain_id ), 'Skip to content' ) && ! str_contains( $through( $plain_id ), '<header' ) );
// In wp-admin the editor has its own styles: nothing is written for the lent blocks there.
$GLOBALS['current_screen'] = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	public function in_admin(): bool {
		return true;
	}
};
$written = static fn(): int => substr_count( $inline(), 'left:-9999px' );
$grown   = $written();
Template_Chrome::site_header();
$expect( 'in wp-admin no rule is written for them', $written() === $grown, $written() . ' vs ' . $grown );
unset( $GLOBALS['current_screen'] );
Template_Chrome::site_header();
$expect( '…and on the front end the same call writes them', $written() === $grown + 1, $written() . ' vs ' . $grown );
remove_all_filters( 'pre_option_' . Header_Template::OPTION );
\DXAI_UI\Chrome\Site_Header_Block::forget();

echo "\nThe template only draws a page of its own\n";
$other = $page( 'DX template fixture page on the theme\'s template', $body, $home_tpl, 'page.php' );
ob_start();
Template_Chrome::open( $other );
$printed = (string) ob_get_clean();
$expect( 'a page on another template is left alone: nothing is printed for it, and the content is whole', '' === $printed && ! Template_Chrome::wraps( $other ) );
ob_start();
Template_Chrome::close( $other );
$expect( '…and closing a page that was not opened prints nothing', '' === (string) ob_get_clean() );
$expect( 'the scope element is not taken from a content filter run for another page while one is drawn', ( function () use ( $sub_tpl, $other ) {
	ob_start();
	Template_Chrome::open( $sub_tpl );
	$wraps_it    = Template_Chrome::wraps( $sub_tpl );
	$wraps_other = Template_Chrome::wraps( $other );
	Template_Chrome::close( $sub_tpl );
	ob_end_clean();

	return $wraps_it && ! $wraps_other;
} )() );

$fin();
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

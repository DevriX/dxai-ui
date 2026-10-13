<?php
/**
 * The team libraries (templates) a site keeps in its uploads (Pages\Library_Store, Pages\Template, API\Libraries_Controller): a library
 * made from an export of a fixture site, built into layouts, in use for its theme before the built-in template, extended with the built-in
 * one, removed again; the files refused; who may call the routes.
 *
 *   bash bin/wp-php.sh bin/verify-libraries.php        (or: wp eval-file bin/verify-libraries.php --user=1)
 *
 * Makes two libraries under uploads/dxai-ui/templates and removes them again, on a fatal too. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Pages\Block_Library;
use DXAI_UI\Pages\Library_Store;
use DXAI_UI\Pages\Template;

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
$slugs    = array();
$made_sub = 0; // a subscriber made for the routes' check when the site has none
$fin      = static function () use ( &$slugs, &$made_sub ): void {
	foreach ( $slugs as $slug ) {
		Library_Store::delete( $slug );
	}
	if ( $made_sub > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $made_sub );
		$made_sub = 0;
	}
	remove_all_filters( 'dxai_ui_template' );
};
register_shutdown_function( $fin );
foreach ( array( 'fixture-pro', 'fixture-pro-2', 'fixture-amr' ) as $old ) {
	if ( Library_Store::get( $old ) !== null ) {
		Library_Store::delete( $old );
	}
}

// A site the team built, as its export says it: a page with a card grid, questions and a call to action, with the site's own name,
// phone and place in the words — which must not reach a layout.
$heading = static fn( string $t, int $l = 2 ): string => '<!-- wp:heading {"level":' . $l . '} --><h' . $l . ' class="wp-block-heading">' . $t . '</h' . $l . '><!-- /wp:heading -->';
$para    = static fn( string $t ): string => '<!-- wp:paragraph --><p>' . $t . '</p><!-- /wp:paragraph -->';
$button  = static fn( string $t, string $url, string $style = 'is-style-primary-button' ): string => '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"className":"' . $style . '","url":"' . $url . '"} --><div class="wp-block-button ' . $style . '"><a class="wp-block-button__link wp-element-button" href="' . $url . '">' . $t . '</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
$section = static fn( string $name, string $class, string $inner ): string => '<!-- wp:group {"metadata":{"name":"' . $name . '"},"className":"' . $class . '","tagName":"section","style":{"spacing":{"padding":{"top":"64px","bottom":"64px"}}}} --><section class="wp-block-group ' . $class . '" style="padding-top:64px;padding-bottom:64px"><!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $inner . '</div><!-- /wp:group --></section><!-- /wp:group -->';
// A card with two links to its page, both drawn as links (the team's "Learn more" and "Get a quote").
$card    = static fn( string $t, string $d ): string => '<!-- wp:column {"className":"svc-card","style":{"color":{"background":"#ffffff"},"border":{"radius":"8px","width":"1px","color":"#dddddd"}}} --><div class="wp-block-column svc-card has-background has-border-color" style="border-color:#dddddd;border-width:1px;border-radius:8px;background-color:#ffffff">' . $heading( $t, 3 ) . $para( $d ) . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"className":"is-style-link-button","url":"https://fixturepro.example/' . sanitize_title( $t ) . '/"} --><div class="wp-block-button is-style-link-button"><a class="wp-block-button__link wp-element-button" href="https://fixturepro.example/' . sanitize_title( $t ) . '/">Learn more</a></div><!-- /wp:button --><!-- wp:button {"className":"is-style-link-button","url":"https://fixturepro.example/' . sanitize_title( $t ) . '/"} --><div class="wp-block-button is-style-link-button"><a class="wp-block-button__link wp-element-button" href="https://fixturepro.example/' . sanitize_title( $t ) . '/">Get a quote</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:column -->';
$qa      = static fn( string $q, string $a ): string => '<!-- wp:details --><details class="wp-block-details"><summary>' . $q . '</summary>' . $para( $a ) . '</details><!-- /wp:details -->';
$content = $section( 'Services grid', 'svc-x', $heading( 'What Fixture Pro Restoration does in Tyler, TX' ) . $para( 'Every job gets a written plan before the first truck rolls.' ) . '<!-- wp:columns --><div class="wp-block-columns">' . $card( 'Water Damage', 'Extraction, drying and monitoring of the loss.' ) . $card( 'Fire Damage', 'Soot, smoke and the rebuild after.' ) . $card( 'Mold Removal', 'Containment and safe removal.' ) . '</div><!-- /wp:columns -->' )
	. $section( 'FAQ Section', 'faq-x', $heading( 'Frequently Asked Questions' ) . $para( 'We know emergencies are stressful. Here are answers to the most common questions.' ) . $qa( 'How fast can you come?', 'Within the hour, day or night.' ) . $qa( 'Do you work with my insurance?', 'Yes. We document everything and talk to the adjuster.' ) . $qa( 'Is the equipment loud?', 'The dryers hum. Most people sleep fine.' ) )
	. $section( 'CTA Banner', 'cta-x', $heading( 'Call Fixture Pro Restoration today' ) . $para( 'A real person answers at (903) 555-0100, day or night, anywhere in Tyler, TX.' ) . $button( 'Call (903) 555-0100', 'tel:+19035550100' ) )
	// A reviews section whose reviews were a widget: a heading, a line and a picture laid under it, no rows.
	. $section( 'Testimonials', 'rev-x', $heading( 'What customers say about Fixture Pro' ) . $para( 'Read what the people of Tyler say.' ) . '<!-- wp:image {"className":"absolute inset-0 opacity-20"} --><figure class="wp-block-image absolute inset-0 opacity-20"><img src="https://fixturepro.example/wp-content/uploads/stars.jpg" alt=""/></figure><!-- /wp:image -->' );
$wxr = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
	. '<rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wfw="http://wellformedweb.org/CommentAPI/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/">' . "\n"
	. '<channel><title>Fixture Pro Restoration</title><link>https://fixturepro.example</link><description>Fixture</description><wp:wxr_version>1.2</wp:wxr_version>' . "\n"
	. '<item><title>DX Test Page</title><link>https://fixturepro.example/dx-test/</link><dc:creator><![CDATA[team]]></dc:creator><content:encoded><![CDATA[' . $content . ']]></content:encoded><wp:post_id>7</wp:post_id><wp:post_date><![CDATA[2026-10-01 00:00:00]]></wp:post_date><wp:post_modified><![CDATA[2026-10-01 00:00:00]]></wp:post_modified><wp:post_name><![CDATA[dx-test]]></wp:post_name><wp:status><![CDATA[draft]]></wp:status><wp:post_parent>0</wp:post_parent><wp:post_type><![CDATA[page]]></wp:post_type></item>' . "\n"
	. '<item><title>A post</title><content:encoded><![CDATA[<!-- wp:paragraph --><p>Not a page.</p><!-- /wp:paragraph -->]]></content:encoded><wp:post_id>8</wp:post_id><wp:post_name><![CDATA[a-post]]></wp:post_name><wp:status><![CDATA[publish]]></wp:status><wp:post_parent>0</wp:post_parent><wp:post_type><![CDATA[post]]></wp:post_type></item>' . "\n"
	. '</channel></rss>';
$tmp = wp_tempnam( 'fixture-export.xml' );
file_put_contents( $tmp, $wxr ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
$plain = wp_tempnam( 'not-blocks.txt' );
file_put_contents( $plain, "Just some words.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

echo "A library from an export\n";
$lib = Library_Store::create( 'Fixture Pro', array( 'twentytwentyfive' ) );
$expect( 'a library is made for a theme, with a slug of its own, a folder in the uploads and its template.json', is_array( $lib ) && $lib['slug'] === 'fixture-pro' && $lib['themes'] === array( 'twentytwentyfive' ) && is_dir( Library_Store::dir( 'fixture-pro' ) . '/exports' ) && is_readable( Library_Store::dir( 'fixture-pro' ) . '/template.json' ), is_wp_error( $lib ) ? $lib->get_error_message() : json_encode( $lib['slug'] ?? null ) );
if ( ! is_array( $lib ) ) {
	exit( 1 );
}
$slugs[] = 'fixture-pro';
$refused = Library_Store::add_source( 'fixture-pro', $plain, 'not-blocks.txt' );
$expect( 'a file that is neither an export nor block markup is refused', is_wp_error( $refused ) && $refused->get_error_code() === 'dxai_ui_library_kind', is_wp_error( $refused ) ? '' : 'taken' );
$added = Library_Store::add_source( 'fixture-pro', $tmp, 'fixture-export.xml' );
$expect( 'the export is read as one (its one page; its post is not a page), and kept as it came', is_array( $added ) && count( $added['sources'] ) === 1 && $added['sources'][0]['kind'] === 'wxr' && (int) $added['sources'][0]['pages'] === 1 && is_readable( Library_Store::dir( 'fixture-pro' ) . '/exports/' . $added['sources'][0]['file'] ), is_wp_error( $added ) ? $added->get_error_message() : json_encode( $added['sources'] ?? null ) );
$built = Library_Store::build( 'fixture-pro' );
$expect( 'built: a layout of each section (the cards, the questions, the call to action), named by the library, with a report', is_array( $built ) && (int) $built['layouts'] >= 3 && isset( $built['by_role']['cards'], $built['by_role']['faq'], $built['by_role']['cta'] ) && is_array( $built['report'] ) && (int) $built['report']['made'] >= 3 && is_readable( Library_Store::dir( 'fixture-pro' ) . '/library.json' ) && count( glob( Library_Store::dir( 'fixture-pro' ) . '/blocks/fixture-pro-*.html' ) ) >= 3, is_wp_error( $built ) ? $built->get_error_message() : json_encode( array( $built['by_role'] ?? null, $built['report']['errors'] ?? null ) ) );
$layouts = Library_Store::layouts( 'fixture-pro' );
$all_markup = '';
foreach ( $layouts as $l ) {
	$all_markup .= (string) file_get_contents( Library_Store::dir( 'fixture-pro' ) . '/blocks/' . $l['id'] . '.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
}
// The words of the layouts (the block comments hold the library's own label, which is no leak) and their links.
$all_words = wp_strip_all_tags( (string) preg_replace( '/<!--.*?-->/s', '', $all_markup ) ) . ' ' . implode( ' ', preg_match_all( '/href="([^"]*)"/', $all_markup, $hm ) ? $hm[1] : array() );
$expect( 'nothing of the fixture site is in its layouts: not its name, its phone, its place, its addresses', ! preg_match( '/Fixture Pro|903\) 555|Tyler|fixturepro\.example/', $all_words, $lm ), $lm[0] ?? '' );
$expect( 'the questions of its FAQ are rows of the page\'s list, not the fixture site\'s own', str_contains( $all_markup, '<summary>{{row:title}}</summary>' ) && ! str_contains( $all_markup, 'How fast can you come' ) && str_contains( $all_markup, '"dxaiRepeat":"faq"' ), '' );
$expect( 'the words every page can say stay as the slots\' first words, with the company as a token', str_contains( $all_markup, '{{t:h2-s|Frequently Asked Questions}}' ) && str_contains( $all_markup, '[[company]]' ), '' );
$cards_markup = (string) file_get_contents( Library_Store::dir( 'fixture-pro' ) . '/blocks/fixture-pro-cards-1.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$expect( 'a card\'s two links to its page are one button of the row, drawn as the link it was', substr_count( $cards_markup, '<!-- wp:button ' ) === 1 && substr_count( $cards_markup, '"url":"{{row:url}}"' ) === 1 && str_contains( $cards_markup, 'is-style-link-button' ), (string) substr_count( $cards_markup, '<!-- wp:button ' ) );
$expect( 'a reviews section whose reviews were a widget (a heading and a line, no rows) is no layout: left out, and the report says so', ! isset( $built['by_role']['reviews'] ) && preg_match( '/SKIPPED: a Reviews section with no rows/', implode( "\n", (array) $built['report']['lines'] ) ) === 1, json_encode( array_keys( (array) $built['by_role'] ) ) );

echo "\nIn use for its theme\n";
$expect( 'the site lists it among its templates, after the stored ones and before the built-in one', isset( Template::all()['fixture-pro'] ) && array_search( 'fixture-pro', array_keys( Template::all() ), true ) < array_search( Template::BUILT_IN, array_keys( Template::all() ), true ) );
$force = static fn() => 'fixture-pro';
add_filter( 'dxai_ui_template', $force );
Block_Library::forget();
$ids = array_keys( Block_Library::entries() );
$expect( 'asked for, the library\'s sections are the ones in use, and only its own (it extends nothing)', $ids !== array() && count( array_filter( $ids, static fn( $id ) => str_starts_with( $id, 'fixture-pro-' ) ) ) === count( $ids ) && Block_Library::markup( $ids[0] ) !== '' && Block_Library::has_layouts(), implode( ',', $ids ) );
$facts = array(
	'home'          => 0,
	'industry'      => 'restoration',
	'company'       => 'Smith & Sons Restoration',
	'company_short' => 'Smith & Sons',
	'phone'         => '(555) 010-2030',
	'phone_url'     => 'tel:+15550102030',
	'email'         => '',
	'address'       => '',
	'region'        => 'Longview, TX',
	'title'         => 'Water Damage',
	'kind'          => 'service',
	'contact_url'   => 'https://example.test/contact/',
	'site_url'      => 'https://example.test/',
	'places'        => array(),
	'services'      => array(),
	'dry'           => true,
	'role'          => 'cta',
	'slot_seed'     => '3',
	'about'         => '',
	'lists'         => array(
		'faq'   => array( array( 'title' => 'Question Alpha?', 'text' => 'The Alpha answer.', 'url' => '' ), array( 'title' => 'Question Bravo?', 'text' => 'The Bravo answer.', 'url' => '' ) ),
		'items' => array( array( 'title' => 'Item Alpha', 'text' => 'The Alpha item.', 'url' => 'https://example.test/alpha/' ), array( 'title' => 'Item Bravo', 'text' => 'The Bravo item.', 'url' => '' ) ),
	),
);
$why = '';
$cta = Block_Library::section( 'cta', $facts, 'seed', array(), '', $why );
$ctm = $cta !== null ? serialize_block( $cta['block'] ) : '';
$expect( 'a page is made with them: the call to action says this company and this phone, nothing of the fixture site (the layout is named by the library, which is no leak), no token left', $cta !== null && str_contains( $ctm, 'Smith &amp; Sons Restoration' ) && str_contains( $ctm, 'tel:+15550102030' ) && ! str_contains( wp_strip_all_tags( (string) preg_replace( '/<!--.*?-->/s', '', $ctm ) ), 'Fixture' ) && ! str_contains( $ctm, '{{' ) && ! str_contains( $ctm, '[[' ), $why . ' ' . substr( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $ctm ) ), 0, 160 ) );
$faq = Block_Library::section( 'faq', array( 'role' => 'faq' ) + $facts, 'seed', array(), '', $why );
$fqm = $faq !== null ? serialize_block( $faq['block'] ) : '';
$expect( 'a FAQ is made with its questions block: the page\'s questions and answers, as many as the page has, nothing of the fixture site\'s', $faq !== null && substr_count( $fqm, '<details' ) === 2 && str_contains( $fqm, '<summary>Question Alpha?</summary>' ) && str_contains( $fqm, 'The Bravo answer.' ) && ! str_contains( $fqm, 'How fast' ) && ! str_contains( $fqm, '{{' ), $why . ' ' . substr( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $fqm ) ), 0, 160 ) );
remove_filter( 'dxai_ui_template', $force );
Block_Library::forget();
if ( wp_get_theme( 'twentytwentyfive' )->exists() ) {
	$was = get_stylesheet();
	switch_theme( 'twentytwentyfive' );
	\DXAI_UI\Theme\Theme_Palette::reset();
	\DXAI_UI\Theme\Theme_Buttons::reset();
	$expect( 'on its theme it is the template in use, of its own (not the generic library)', ( Template::for_theme()['slug'] ?? '' ) === 'fixture-pro' && ! Template::is_generic() && Block_Library::template_ok(), json_encode( Template::current()['slug'] ?? null ) );
	switch_theme( $was );
	\DXAI_UI\Theme\Theme_Palette::reset();
	\DXAI_UI\Theme\Theme_Buttons::reset();
	$expect( 'and the theme is switched back', get_stylesheet() === $was );
}

echo "\nTrained with one more export, and one that extends the built-in template\n";
$more = Library_Store::add_source( 'fixture-pro', $tmp, 'fixture-export-again.xml' );
$again = is_array( $more ) ? Library_Store::build( 'fixture-pro' ) : $more;
$expect( 'one more export is kept beside the first, and the library built from both: the same arrangements counted together, not doubled', is_array( $again ) && count( $again['sources'] ) === 2 && (int) $again['layouts'] === (int) $built['layouts'], is_wp_error( $again ) ? $again->get_error_message() : json_encode( array( count( $again['sources'] ?? array() ), $again['layouts'] ?? null, $built['layouts'] ) ) );
$amr = Library_Store::create( 'Fixture AMR', array( 'american-restoration' ), Template::BUILT_IN );
$slugs[] = is_array( $amr ) ? (string) $amr['slug'] : 'fixture-amr';
$amr_added = is_array( $amr ) ? Library_Store::add_source( (string) $amr['slug'], $tmp, 'fixture-export.xml' ) : $amr;
$amr_built = is_array( $amr_added ) ? Library_Store::build( (string) $amr['slug'] ) : $amr_added;
$on_amr = get_stylesheet() === 'american-restoration' || get_template() === 'american-restoration';
// On American Restoration a stored library for it is the one in use (this one, or another the site keeps: the first stored one wins).
$expect( 'on American Restoration a stored library for it is the one in use, before the built-in template', ! $on_amr || ( ! empty( Template::for_theme()['stored'] ) && in_array( 'american-restoration', (array) Template::for_theme()['themes'], true ) ), json_encode( Template::for_theme()['slug'] ?? null ) );
$force_amr = static fn() => $amr['slug'];
add_filter( 'dxai_ui_template', $force_amr );
Block_Library::forget();
$amr_ids = array_keys( Block_Library::entries() );
$expect( 'a library that extends the built-in template has its own sections and the built-in\'s (the ones built by hand among them)', is_array( $amr_built ) && count( array_filter( $amr_ids, static fn( $id ) => str_starts_with( $id, 'fixture-amr-' ) ) ) >= 3 && in_array( 'cta-call', $amr_ids, true ) && in_array( 'layout-cards-1', $amr_ids, true ), ( is_wp_error( $amr_built ) ? $amr_built->get_error_message() : '' ) . ' ' . json_encode( array( Template::current()['slug'] ?? null, count( $amr_ids ) ) ) );
$expect( 'a built-in section\'s block is read from the plugin, a stored one\'s from the uploads', Block_Library::markup( 'cta-call' ) !== '' && Block_Library::markup( 'fixture-amr-cta-1' ) !== '' && str_contains( (string) ( Block_Library::entries()['fixture-amr-cta-1']['_dir'] ?? '' ), 'uploads' ) && ! str_contains( (string) ( Block_Library::entries()['cta-call']['_dir'] ?? '' ), 'uploads' ), '' );
remove_filter( 'dxai_ui_template', $force_amr );
Block_Library::forget();

echo "\nThe routes\n";
$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
$sub   = get_users( array( 'role' => 'subscriber', 'number' => 1, 'fields' => 'ids' ) );
if ( $sub === array() ) {
	$made_sub = wp_insert_user( array( 'user_login' => 'dxai-fixture-subscriber-' . wp_rand( 1000, 9999 ), 'user_pass' => wp_generate_password( 24 ), 'role' => 'subscriber' ) );
	$made_sub = is_wp_error( $made_sub ) ? 0 : (int) $made_sub;
	$sub      = $made_sub > 0 ? array( $made_sub ) : array();
}
$call  = static function ( string $method, string $route, int $user, array $params = array() ): array {
	wp_set_current_user( $user );
	$request = new WP_REST_Request( $method, '/' . DXAI_UI_REST_NAMESPACE . $route );
	foreach ( $params as $k => $v ) {
		$request->set_param( $k, $v );
	}
	$response = rest_get_server()->dispatch( $request );
	wp_set_current_user( 0 );

	return array( 'status' => $response->get_status(), 'data' => $response->get_data() );
};
if ( $admin !== array() ) {
	$list = $call( 'GET', '/libraries', (int) $admin[0] );
	$expect( 'an administrator lists the templates: the built-in one, the stored ones, which is in use, the themes', $list['status'] === 200 && in_array( 'fixture-pro', array_column( (array) $list['data']['templates'], 'slug' ), true ) && in_array( Template::BUILT_IN, array_column( (array) $list['data']['templates'], 'slug' ), true ) && isset( $list['data']['current'] ) && count( (array) $list['data']['themes'] ) >= 1, json_encode( $list['status'] ) );
	$rows = array_column( (array) $list['data']['templates'], null, 'slug' );
	$expect( 'each counted by its own layouts, not by the template in use (the built-in one keeps its count while a stored one extends it)', (int) ( $rows[ Template::BUILT_IN ]['layouts'] ?? 0 ) === (int) Block_Library::layouts_in( DXAI_UI_DIR . Block_Library::DIR )['layouts'] && (int) ( $rows[ Template::BUILT_IN ]['layouts'] ?? 0 ) > 0 && (int) ( $rows['fixture-pro']['layouts'] ?? 0 ) === (int) $built['layouts'] && (int) ( $rows[ $amr['slug'] ]['layouts'] ?? 0 ) === (int) $amr_built['layouts'], json_encode( array( $rows[ Template::BUILT_IN ]['layouts'] ?? null, $rows['fixture-pro']['layouts'] ?? null, $rows[ $amr['slug'] ]['layouts'] ?? null ) ) );
	$one = $call( 'GET', '/libraries/fixture-pro', (int) $admin[0] );
	$expect( 'and one, with its layouts and its report', $one['status'] === 200 && count( (array) ( $one['data']['layoutList'] ?? array() ) ) >= 3 && isset( $one['data']['report']['lines'] ), json_encode( $one['status'] ) );
	$no_file = $call( 'POST', '/libraries', (int) $admin[0], array( 'label' => 'No file', 'theme' => 'twentytwentyfive' ) );
	$expect( 'a new library without a file is refused, and none is made', $no_file['status'] === 400 && Library_Store::get( 'no-file' ) === null, json_encode( $no_file['status'] ) );
}
$denied = $sub !== array() ? $call( 'GET', '/libraries', (int) $sub[0] ) : array( 'status' => 0 );
$expect( 'a subscriber may not read them, let alone make one', $sub !== array() && $denied['status'] === 403 && $call( 'DELETE', '/libraries/fixture-pro', (int) $sub[0] )['status'] === 403 && Library_Store::get( 'fixture-pro' ) !== null, $sub === array() ? 'no subscriber to try with' : json_encode( $denied['status'] ) );
if ( $admin !== array() ) {
	$gone = $call( 'DELETE', '/libraries/fixture-pro', (int) $admin[0] );
	$expect( 'an administrator removes one: its folder goes, the site no longer lists it', $gone['status'] === 200 && Library_Store::get( 'fixture-pro' ) === null && ! is_dir( Library_Store::dir( 'fixture-pro' ) ) && ! isset( Template::all()['fixture-pro'] ), json_encode( $gone['status'] ) );
	$expect( 'the built-in template cannot be removed that way', $call( 'DELETE', '/libraries/' . Template::BUILT_IN, (int) $admin[0] )['status'] === 404 && is_readable( DXAI_UI_DIR . Block_Library::DIR . 'library.json' ) );
}
$expect( 'nor by the store itself, which leaves the built-in template and a library that is not there alone', Library_Store::delete( Template::BUILT_IN ) === false && Library_Store::delete( 'no-such-library' ) === false && is_readable( DXAI_UI_DIR . Block_Library::DIR . 'library.json' ) );

$fin();
Block_Library::forget();
unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
unlink( $plain ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

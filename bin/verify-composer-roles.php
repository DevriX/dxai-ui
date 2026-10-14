<?php
/**
 * A page of a design's old site is read in the roles its parts have, and each goes where the design shows that role.
 *
 *   bash bin/wp-php.sh bin/verify-composer-roles.php        (or: wp eval-file bin/verify-composer-roles.php --user=1)
 *
 * Pages from the live site (Content_Extractor → Page_Composer) put every part of an old page in the Home's section best made for
 * it. Measured on H2O Away (docs/PLAN-PERFECT-PAGES.md, T0.3): the American Restoration theme's questions (a .faq-question group,
 * its heading asking, .faq-answer answering) were read as cards and landed among the reviews; its figures (a big number and its
 * words) as text; and the Home's step form (one field in each HTML block, no <form>) was no form, so the old page's form was left
 * out and text was poured into the form. Here: an old page written that way, a Home with a text section, a call to action and
 * such a form but no section of questions or figures. The questions become core/details in a text section of the Home, the
 * figures its list or a line of its text, the form goes to the form, and no text goes there.
 *
 * Makes one page (the Home) and deletes it, also when a check dies half-way. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Pages\Block_Tree;
use DXAI_UI\Pages\Content_Extractor;
use DXAI_UI\Pages\Page_Composer;
use DXAI_UI\Pages\Section_Library;

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

$made  = array();
$clean = static function () use ( &$made ): void {
	foreach ( $made as $id ) {
		wp_delete_post( (int) $id, true );
	}
	$made = array();
};
register_shutdown_function( $clean );

$sec  = static fn( string $class, string $inner ): string => '<!-- wp:group {"className":"' . $class . '"} --><div class="wp-block-group ' . $class . '">' . $inner . '</div><!-- /wp:group -->';
$h    = static fn( string $t, int $l = 2 ): string => '<!-- wp:heading' . ( $l !== 2 ? ' {"level":' . $l . '}' : '' ) . ' --><h' . $l . ' class="wp-block-heading">' . $t . '</h' . $l . '><!-- /wp:heading -->';
$p    = static fn( string $t ): string => '<!-- wp:paragraph --><p>' . $t . '</p><!-- /wp:paragraph -->';
$home = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Composer roles fixture Home',
		'post_name'    => 'crx-home-' . substr( md5( (string) microtime( true ) ), 0, 6 ),
		'post_content' => $sec( 'crx-hero', $h( 'Fixture Restoration', 1 ) . $p( 'We dry what the water took, day and night.' ) )
			. $sec( 'crx-text', $h( 'Why families call us' ) . $p( 'A family business since 1990, on call at any hour of the day.' ) )
			. $sec( 'crx-cta', $h( 'Water in your home?' ) . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="tel:5550100">Call now</a></div><!-- /wp:button --></div><!-- /wp:buttons -->' )
			. $sec( 'crx-form', $h( 'Request a callback' ) . '<!-- wp:group {"className":"dxai-on-step1"} --><div class="wp-block-group dxai-on-step1"><!-- wp:html --><div class="crx-field"><input name="crx-name" placeholder="Name"></div><!-- /wp:html --></div><!-- /wp:group --><!-- wp:group {"className":"dxai-on-step2"} --><div class="wp-block-group dxai-on-step2"><!-- wp:html --><div class="crx-field"><input name="crx-phone" placeholder="Phone"></div><!-- /wp:html --></div><!-- /wp:group -->' ),
	),
	true
);
if ( is_wp_error( $home ) ) {
	echo "SKIP: could not make the fixture Home\n";
	exit( 77 );
}
$made[] = (int) $home;

echo "The Home's sections\n";
$library = Section_Library::for_page( (int) $home );
$kinds   = array();
foreach ( $library as $c ) {
	$kinds[ (int) $c['index'] ] = (string) $c['kind'];
}
$form_at = array_search( 'form', $kinds, true );
$expect( 'a step form (one field in each HTML block, no <form>) is the Home\'s form section', false !== $form_at, wp_json_encode( $kinds ) );
$expect( 'the text and the call to action are what they were', in_array( 'text', $kinds, true ) && in_array( 'hero', $kinds, true ), wp_json_encode( $kinds ) );

echo "\nThe old page, as the American Restoration theme writes it\n";
$html  = '<html><body><main>'
	. '<section><h1>Water Damage Restoration</h1><p>Our crew reaches flooded homes in under an hour, day or night.</p></section>'
	. '<section><h2>Water Damage FAQs</h2>'
	. '<div class="wp-block-group faq-question"><div class="wp-block-group"><h3>How long does drying take?</h3></div><p class="faq-answer">Three to five days with our equipment running.</p></div>'
	. '<div class="wp-block-group faq-question"><div class="wp-block-group"><h3>Will my insurance cover it?</h3></div><p class="faq-answer">Sudden water damage usually is, and we help with the claim.</p></div>'
	. '</section>'
	. '<section><div class="wp-block-group grid-4"><div class="wp-block-group"><p>40,000+</p><p>Properties Restored</p></div><div class="wp-block-group"><p>~60 min</p><p>Emergency Response</p></div><div class="wp-block-group"><p>24/7</p><p>Live dispatch</p></div></div></section>'
	. '<section><h2>How it works</h2><div class="wp-block-group"><p>01</p><p>Call us</p></div><p>We answer, ask what happened and send a crew to your door.</p></section>'
	. '<section><h2>Request a Callback</h2><form action="/send"><input name="name"><input name="phone"><button>Send</button></form></section>'
	. '<section><h2>Why choose us</h2><p>We are licensed and insured, and we bill your insurance company directly for the work.</p></section>'
	. '</main></body></html>';
$model = ( new Content_Extractor() )->extract( $html, 'https://old-crx.example/water-damage/' );
$parts = array();
foreach ( (array) $model['sections'] as $s ) {
	foreach ( (array) $s['blocks'] as $b ) {
		$parts[] = $b;
	}
}
$faqs  = array_values( array_filter( $parts, static fn( $b ) => ( $b['type'] ?? '' ) === 'faq' ) );
$stats = array_values( array_filter( $parts, static fn( $b ) => ( $b['type'] ?? '' ) === 'stat' ) );
$expect( 'its questions are questions: the .faq-question group\'s heading asks, .faq-answer answers', 2 === count( $faqs ) && 'How long does drying take?' === $faqs[0]['q'] && str_contains( wp_json_encode( $faqs[0]['a'] ), 'Three to five days' ), wp_json_encode( $faqs ) );
$expect( 'its figures are figures: a number on its own and what it counts ("40,000+", "~60 min", "24/7")', array( '40,000+', '~60 min', '24/7' ) === array_column( $stats, 'value' ) && 'Properties Restored' === $stats[0]['label'], wp_json_encode( $stats ) );
$expect( 'a step\'s number ("01 Call us") is no figure', ! in_array( '01', array_column( $stats, 'value' ), true ) );

$units = Page_Composer::units( $model );
$ukind = array_column( $units, 'kind' );
$expect( 'its parts are units of those roles: questions, figures, a form', in_array( 'faq', $ukind, true ) && in_array( 'stats', $ukind, true ) && in_array( 'form', $ukind, true ), wp_json_encode( $ukind ) );

echo "\nThe page made from it\n";
$composer = new Page_Composer( $library, static fn( string $src ) => null );
$out      = $composer->compose( $model );
$log      = implode( "\n", $out['log'] );
$markup   = implode( "\n", array_map( 'serialize_block', $out['sections'] ) );
$expect( 'the old page\'s form goes to the Home\'s form section', false !== $form_at && 1 === preg_match( '/form\s+→ #' . (int) $form_at . ' form/', $log ), $log );
$expect( 'the form section keeps its fields, steps and all (a step form shows them with dxai-on- groups)', 2 === preg_match_all( '#<input name="crx-(name|phone)"#', $markup ), $markup );
$expect( 'no text is poured into the form section', 0 === preg_match( '/\b(text|list|cta)\s+→ #' . (int) $form_at . '\b/', $log ), $log );
$expect( 'the questions the Home has no section for are core/details in one of its text sections', 2 === substr_count( $markup, '<!-- wp:details -->' ) && str_contains( $markup, '<summary>How long does drying take?</summary>' ) && str_contains( $markup, 'Three to five days' ), $log );
$expect( 'the figures the Home has no section for are shown in its text, not left out', str_contains( $markup, '40,000+ Properties Restored' ) && str_contains( $markup, '24/7 Live dispatch' ) && ! str_contains( $log, 'left out' ), $log );
$expect( 'every section of the page parses back to the same blocks', ! is_wp_error( Block_Tree::serialize_checked( $out['sections'] ) ) );

echo "\nLinks\n";
// Cloudflare's protected e-mail, as the live H2O Away writes it: a link to /cdn-cgi/l/email-protection whose words are the address,
// XOR-ed with the first byte.
$cf_hex = '2a' . implode( '', array_map( static fn( string $c ): string => sprintf( '%02x', ord( $c ) ^ 0x2a ), str_split( 'crew@old-crx.example' ) ) );
$cf_doc = ( new Content_Extractor() )->extract( '<html><body><main><section><h2>Contact us</h2><div class="wp-block-group"><p class="text-16"><a href="/cdn-cgi/l/email-protection#' . $cf_hex . '" data-type="mailto"><span class="__cf_email__" data-cfemail="' . $cf_hex . '">[email&#160;protected]</span></a></p></div></section></main></body></html>', 'https://old-crx.example/contact/' );
$cf_html = (string) wp_json_encode( $cf_doc['sections'] );
$expect( 'a protected e-mail is read as one link to the address, not a link in a link', 1 === substr_count( $cf_html, '<a ' ) && str_contains( $cf_html, 'mailto:crew@old-crx.example' ), $cf_html );
$expect( 'Block_Tree::unnest_links() keeps the outer link and the inner one\'s words', '<p><a href="mailto:a@b.c">a@b.c</a> and <a href="/x">x</a></p>' === Block_Tree::unnest_links( '<p><a href="mailto:a@b.c"><a href="mailto:a@b.c">a@b.c</a></a> and <a href="/x">x</a></p>' ) && '<abbr>a</abbr> <a href="/y">y</a>' === Block_Tree::unnest_links( '<abbr>a</abbr> <a href="/y">y</a>' ) );
// Whatever writes a link in a link, the composed page has none: an old page read as usual, then one of its paragraphs given one.
$nest_model = ( new Content_Extractor() )->extract( '<html><body><main><section><h1>Nested links</h1><p>We answer every message the same day, at any hour of the day or night.</p></section><section><h2>Write to us</h2><p>Our office answers every message the same day: NESTED and on the phone, at any hour.</p></section></main></body></html>', 'https://old-crx.example/nested/' );
foreach ( $nest_model['sections'] as &$nest_s ) {
	foreach ( $nest_s['blocks'] as &$nest_b ) {
		if ( isset( $nest_b['html'] ) && is_string( $nest_b['html'] ) ) {
			$nest_b['html'] = str_replace( 'NESTED', '<a href="mailto:crew@old-crx.example"><a href="mailto:crew@old-crx.example">crew@old-crx.example</a></a>', $nest_b['html'] );
		}
	}
	unset( $nest_b );
}
unset( $nest_s );
$nest_out = ( new Page_Composer( $library, static fn( string $src ) => null ) )->compose( $nest_model );
$nest_mk  = implode( "\n", array_map( 'serialize_block', $nest_out['sections'] ) );
$expect( 'the composed page holds no link in a link, and says it unwrapped one', str_contains( $nest_mk, 'crew@old-crx.example' ) && 0 === preg_match( '#<a\b[^>]*>(?:(?!</a>).)*<a\b#is', $nest_mk ) && in_array( 'links: a link inside a link unwrapped', $nest_out['log'], true ), implode( ' | ', $nest_out['log'] ) );

echo "\nA call button of the design\n";
// The Home's call to action: a heading, a line, and a call button written as a link that does not wrap.
$button = '<!-- wp:dxai-ui/text {"tagName":"a","url":"tel:5550100","className":"crx-btn whitespace-nowrap"} --><a class="wp-block-dxai-ui-text crx-btn whitespace-nowrap" href="tel:5550100">Call (555) 010-0100</a><!-- /wp:dxai-ui/text -->';
wp_update_post(
	array(
		'ID'           => (int) $home,
		'post_content' => (string) get_post_field( 'post_content', (int) $home ) . $sec( 'crx-call', $h( 'Need help now?' ) . $p( 'Our crew is on call at any hour.' ) . $button ),
	)
);
$call_lib = Section_Library::for_page( (int) $home );
$links    = array();
foreach ( $call_lib as $c ) {
	foreach ( (array) $c['slots'] as $slot ) {
		if ( str_contains( wp_json_encode( $slot ), 'crx-btn' ) || str_contains( (string) ( $slot['text'] ?? '' ), 'Call (555)' ) ) {
			$links[] = (string) $slot['type'];
		}
	}
}
$expect( 'a text block written as a link (a call button) is a link slot, not a text slot', in_array( 'link', $links, true ) && ! in_array( 'text', $links, true ), wp_json_encode( $links ) );
$call_html = '<html><body><main><section><h2>Call H2O Today</h2><p>We work with all insurance companies and bill them directly for the work we do.</p><div class="wp-block-buttons"><div class="wp-block-button"><a href="tel:5550100">Call Now</a></div></div></section></main></body></html>';
$call_out  = ( new Page_Composer( $call_lib, static fn( string $src ) => null ) )->compose( ( new Content_Extractor() )->extract( $call_html, 'https://old-crx.example/call/' ) );
$call_mk   = implode( "\n", array_map( 'serialize_block', $call_out['sections'] ) );
$in_button = preg_match( '#<a class="wp-block-dxai-ui-text crx-btn whitespace-nowrap"[^>]*>([^<]*)</a>#', $call_mk, $bm ) ? $bm[1] : '';
$expect( 'a sentence of the old page does not go in a call button that does not wrap', $in_button === '' || ! str_contains( $in_button, 'insurance companies' ), $in_button );

$clean();
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );

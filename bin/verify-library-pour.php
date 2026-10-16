<?php
/**
 * A section of the team's library poured into a page as the site has it: its words in colours whose class means only a colour, in
 * fonts the site has, with the rows the page has and no other, and a section's pictures kept when its ticks may go.
 *
 *   bash bin/wp-php.sh bin/verify-library-pour.php        (or: wp eval-file bin/verify-library-pour.php --user=1)
 *
 * Found on Taylored Restoration's site on the team's network (2026-10-16), where the pages were made from the Home and the library:
 *  - American Restoration's white is named "border" there. Words on a dark band took it (the colour for words on a dark ground), and
 *    `has-border-color` — the class of that text colour — is the class core's stylesheet draws a solid border round: a heading and its
 *    line stood in a 3px white frame, an eyebrow's red in a red one. Such a colour is the block's own colour now (Capabilities::text_slug_ok()).
 *  - The team's layouts set the fonts of their sites (Barlow Condensed, Inter): with none of them on the site the words fell back to
 *    the browser's (a heavy Arial heading, questions in Arial). A family the site lacks is taken off.
 *  - A layout held the source site's last step apart from its rows ("Phase 3"; in four others "Phase 5" with that site's own words): the
 *    page showed it after its own three steps, with an empty number. It goes with the list.
 *  - A section's list of badges was dropped as if it were the ticks under its heading: "Certified, Accredited & Accountable" over
 *    nothing. A list of pictures stays.
 *
 * The palette is put in place for its checks and given back; nothing is written. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Pages\Block_Library;
use DXAI_UI\Pages\Block_Tree;
use DXAI_UI\Pages\Section_Library;
use DXAI_UI\Pages\Section_Variants;
use DXAI_UI\Pages\Template;
use DXAI_UI\Theme\Capabilities;
use DXAI_UI\Theme\Theme_Palette;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
$one_line = static fn( string $s ): string => substr( (string) preg_replace( '/\s+/', ' ', $s ), 0, 300 );

// A palette as a theme has it, for the checks that read one (Theme_Palette keeps it for the request); given back at the end.
$memo    = new ReflectionProperty( Theme_Palette::class, 'memo' );
$memo->setAccessible( true );
$before  = $memo->getValue();
$palette = static function ( array $colours ) use ( $memo ): void {
	$entries = array();
	foreach ( $colours as $slug => $hex ) {
		$entries[ $slug ] = array( 'slug' => $slug, 'name' => ucfirst( $slug ), 'raw' => $hex, 'hex' => $hex, 'origin' => 'theme' );
	}
	$memo->setValue( null, array( 'has_palette' => true, 'entries' => $entries, 'anchors' => array(), 'hash' => md5( wp_json_encode( $colours ) ), 'user_override' => false ) );
};
register_shutdown_function( static fn() => $memo->setValue( null, $before ) );

echo "A colour whose class is WordPress's\n";
$expect( 'border, text and link are no text colour\'s class; the others are', ! Capabilities::text_slug_ok( 'border' ) && ! Capabilities::text_slug_ok( 'text' ) && ! Capabilities::text_slug_ok( 'link' ) && Capabilities::text_slug_ok( 'primary' ) && Capabilities::text_slug_ok( 'white' ) && Capabilities::text_slug_ok( 'border-light' ) );

// American Restoration on the team's network: its only white is "border", "secondary" is near black.
$palette( array( 'primary-dark' => '#8e251f', 'primary-light' => '#60737c', 'primary' => '#0c2341', 'secondary' => '#201d1d', 'accent' => '#8e251f', 'border' => '#ffffff' ) );
$roles = Template::palette_roles();
$expect( 'the palette of the network: the colour for words on a dark ground is "border" (the case)', 'border' === $roles['on_dark'], wp_json_encode( $roles ) );
$band = array(
	'blockName'    => 'core/group',
	'attrs'        => array( 'backgroundColor' => 'secondary', 'className' => 'dxai-lib' ),
	'innerBlocks'  => array(
		array(
			'blockName'    => 'core/heading',
			'attrs'        => array( 'textColor' => 'on-accent', 'style' => array( 'spacing' => array( 'margin' => array( 'bottom' => '8px' ) ) ) ),
			'innerBlocks'  => array(),
			'innerHTML'    => '<h2 class="wp-block-heading has-on-accent-color has-text-color" style="margin-bottom:8px">Damage won\'t wait. Neither do we.</h2>',
			'innerContent' => array( '<h2 class="wp-block-heading has-on-accent-color has-text-color" style="margin-bottom:8px">Damage won\'t wait. Neither do we.</h2>' ),
		),
		array(
			'blockName'    => 'core/paragraph',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '<p>Talk to a real person 24/7 and get help on the way today.</p>',
			'innerContent' => array( '<p>Talk to a real person 24/7 and get help on the way today.</p>' ),
		),
	),
	'innerHTML'    => '<div class="wp-block-group dxai-lib has-secondary-background-color has-background"></div>',
	'innerContent' => array( '<div class="wp-block-group dxai-lib has-secondary-background-color has-background">', null, null, '</div>' ),
);
$made   = Block_Library::legible( $band );
$markup = serialize_block( $made );
$html   = render_block( $made );
$head   = $made['innerBlocks'][0];
$para   = $made['innerBlocks'][1];
$expect( 'the words on the dark band take the theme\'s white, as the block\'s own colour (no textColor "border")', 'var:preset|color|border' === ( $head['attrs']['style']['color']['text'] ?? '' ) && ! isset( $head['attrs']['textColor'] ) && 'var:preset|color|border' === ( $para['attrs']['style']['color']['text'] ?? '' ), $one_line( wp_json_encode( array( $head['attrs'], $para['attrs'] ) ) ) );
$expect( 'no `has-border-color` anywhere: the words stand in no frame', ! str_contains( $markup, 'has-border-color' ) && ! str_contains( $html, 'has-border-color' ), $one_line( $html ) );
$expect( 'the markup says what save() says: the colour in the style, the text-colour flag, the block\'s other settings kept', str_contains( (string) $head['innerHTML'], 'style="color:var(--wp--preset--color--border);margin-bottom:8px"' ) && str_contains( (string) $head['innerHTML'], 'has-text-color' ) && ! str_contains( (string) $head['innerHTML'], 'has-on-accent-color' ) && str_contains( (string) $para['innerHTML'], '<p class="has-text-color" style="color:var(--wp--preset--color--border)">' ), $one_line( $head['innerHTML'] . ' ' . $para['innerHTML'] ) );
$expect( 'it parses back to the same blocks', ! is_wp_error( Block_Tree::serialize_checked( array( $made ) ) ) );
$again = Block_Library::legible( $made );
$expect( 'poured again, it is as it was (the colour set as the block\'s own is read as that colour)', serialize_block( $again ) === $markup, $one_line( serialize_block( $again ) ) );
// The same white, set as a block's own colour, on the light page: it does not read there, and goes (read as the white it is).
$light = array(
	'blockName'    => 'core/paragraph',
	'attrs'        => array( 'style' => array( 'color' => array( 'text' => 'var:preset|color|border' ) ) ),
	'innerBlocks'  => array(),
	'innerHTML'    => '<p class="has-text-color" style="color:var(--wp--preset--color--border)">On the light page.</p>',
	'innerContent' => array( '<p class="has-text-color" style="color:var(--wp--preset--color--border)">On the light page.</p>' ),
);
$read  = Block_Library::legible( $light );
$expect( 'the theme\'s white set as a block\'s own colour, on the light page: taken off (it would not read)', ! isset( $read['attrs']['style']['color']['text'] ) && ! str_contains( (string) $read['innerHTML'], 'color:var(--wp--preset--color--border)' ), $one_line( serialize_block( $read ) ) );

// The same theme where "border" is a dark line colour and the white has a name of its own: the white's class.
$palette( array( 'primary-dark' => '#173764', 'primary' => '#016bc4', 'secondary' => '#f5f7f9', 'accent' => '#e64804', 'border' => '#0f2d52', 'white' => '#ffffff' ) );
$dark                                = $band;
$dark['attrs']['backgroundColor']    = 'primary-dark';
$dark['innerHTML']                   = str_replace( 'has-secondary-background-color', 'has-primary-dark-background-color', (string) $dark['innerHTML'] );
$dark['innerContent'][0]             = str_replace( 'has-secondary-background-color', 'has-primary-dark-background-color', (string) $dark['innerContent'][0] );
$made = Block_Library::legible( $dark );
$expect( 'a white with a name of its own is the words\' class, as before', 'white' === ( $made['innerBlocks'][0]['attrs']['textColor'] ?? '' ) && str_contains( serialize_block( $made ), 'has-white-color' ), $one_line( wp_json_encode( $made['innerBlocks'][0]['attrs'] ) ) );
$memo->setValue( null, $before );

echo "\nAt the import: a design's colours on its own blocks\n";
// A design whose colour is named "border" (the colour of its lines) and is the theme's too: no text takes it as a class.
$design = new ReflectionProperty( \DXAI_UI\Blocks\Native\Text_Color::class, 'design' );
$live   = new ReflectionProperty( \DXAI_UI\Blocks\Native\Text_Color::class, 'live' );
$design->setAccessible( true );
$live->setAccessible( true );
$design->setValue( null, array( -5 => array( 'border' => '#ffffff', 'brand' => '#0c2341' ) ) );
$live->setValue( null, array( 'border' => '#ffffff', 'brand' => '#0c2341' ) );
$preset = new ReflectionMethod( \DXAI_UI\Blocks\Native\Text_Color::class, 'preset' );
$preset->setAccessible( true );
$expect( 'a design\'s text in its colour named "border" is no `has-border-color` (its other colours still are the theme\'s class)', '' === $preset->invoke( null, -5, 'border' ) && 'brand' === $preset->invoke( null, -5, 'brand' ) );
\DXAI_UI\Blocks\Native\Text_Color::forget();
// A theme whose white is named "border": a design's white words are not swapped into it.
$swap = new ReflectionMethod( \DXAI_UI\Theme\Theme_Class_Swap::class, 'swap_block' );
$swap->setAccessible( true );
$words = array(
	'blockName'    => 'core/paragraph',
	'attrs'        => array( 'className' => 'text-dxai-white' ),
	'innerBlocks'  => array(),
	'innerHTML'    => '<p class="text-dxai-white">Talk to a real person.</p>',
	'innerContent' => array( '<p class="text-dxai-white">Talk to a real person.</p>' ),
);
$n    = 0;
$to_border = $swap->invokeArgs( null, array( $words, array( 'white' => array( 'text_color' => 'border' ) ), &$n ) );
$n2   = 0;
$to_white  = $swap->invokeArgs( null, array( $words, array( 'white' => array( 'text_color' => 'white' ) ), &$n2 ) );
$expect( 'a design\'s white words on a theme whose white is "border": left the design\'s (no `has-border-color`); to a white of its own name: swapped', ! str_contains( serialize_block( $to_border ), 'has-border-color' ) && ! isset( $to_border['attrs']['textColor'] ) && 'white' === ( $to_white['attrs']['textColor'] ?? '' ), $one_line( serialize_block( $to_border ) . ' | ' . serialize_block( $to_white ) ) );

echo "\nThe team's layouts on this site\n";
$facts = array(
	'home'          => 0,
	'industry'      => 'restoration',
	'company'       => 'Taylored Restoration',
	'company_short' => 'Taylored',
	'phone'         => '(907) 344-1239',
	'phone_url'     => 'tel:9073441239',
	'email'         => '',
	'address'       => '',
	'region'        => 'Anchorage',
	'title'         => 'Water Damage Restoration',
	'kind'          => 'service',
	'contact_url'   => 'https://example.test/contact/',
	'site_url'      => 'https://example.test/',
	'places'        => array( array( 'title' => 'Anchorage, AK', 'url' => 'https://example.test/anchorage/' ), array( 'title' => 'Fairbanks, AK', 'url' => '' ) ),
	'services'      => array(),
	'dry'           => true,
	'role'          => 'process',
	'slot_seed'     => '7',
	'about'         => '',
	'topic'         => 'water damage',
	'lists'         => array(
		'steps' => array(
			array( 'title' => 'You call, a local answers', 'text' => 'Any hour. We send the nearest crew.', 'url' => '', 'eyebrow' => '01' ),
			array( 'title' => 'On site in ~60 minutes', 'text' => 'We stop the water and protect your belongings.', 'url' => '', 'eyebrow' => '02' ),
			array( 'title' => 'Dry, clean, document', 'text' => 'Daily readings and photos for your insurer.', 'url' => '', 'eyebrow' => '03' ),
		),
	),
);
$families = array();
foreach ( (array) wp_get_global_settings( array( 'typography', 'fontFamilies' ) ) as $rows ) {
	foreach ( (array) $rows as $row ) {
		$families[ (string) ( $row['slug'] ?? '' ) ] = true;
	}
}
$entries = Block_Library::entries();
// The layouts that hold an item of their list apart from its rows (found by bin's shape check, 2026-10-16).
$source_words = array( 'Phase 3', 'Phase 5', 'Repairs and Restoration', 'Final Review and Return to Operation', 'Drying, Repairs and Final Review', 'Final Walkthrough and Reopening' );
foreach ( array( 'layout-process-1', 'layout-process-4', 'layout-process-5', 'layout-process-7', 'layout-process-8' ) as $id ) {
	if ( ! isset( $entries[ $id ] ) ) {
		$expect( "$id: in the library", false );
		continue;
	}
	$why   = '';
	$block = Block_Library::fill( $id, $facts, $why );
	if ( $block === null ) {
		// (A layout made of a DX theme's blocks is not made on a site without them: nothing to look at here.)
		if ( ! str_contains( $why, 'not registered' ) ) {
			$expect( "$id: made from three steps", false, $why );
		}
		continue;
	}
	$text  = html_entity_decode( wp_strip_all_tags( serialize_block( $block ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$left  = array_values( array_filter( $source_words, static fn( $w ) => str_contains( $text, $w ) ) );
	$steps = substr_count( $text, 'You call, a local answers' ) + substr_count( $text, 'On site in ~60 minutes' ) + substr_count( $text, 'Dry, clean, document' );
	$expect( "$id: the page's three steps and no other (no step of the source site, no \"Phase\" label)", $left === array() && 3 === $steps, implode( ', ', $left ) . ' · steps ' . $steps );
}
$fonted = array();
foreach ( array_keys( $entries ) as $id ) {
	if ( preg_match( '/"fontFamily":"([a-z0-9-]+)"/', Block_Library::markup( (string) $id ), $fm ) === 1 && ! isset( $families[ $fm[1] ] ) ) {
		$fonted[ (string) $id ] = $fm[1];
	}
}
$expect( 'the library has layouts that set a family this site has not (the case)', count( $fonted ) >= 3, implode( ', ', array_keys( $fonted ) ) );
$checked = 0;
foreach ( $fonted as $id => $family ) {
	$why   = '';
	$block = Block_Library::fill( $id, array( 'role' => (string) ( $entries[ $id ]['role'] ?? '' ) ) + $facts, $why );
	if ( $block === null ) {
		continue;
	}
	++$checked;
	$markup = serialize_block( $block );
	$html   = render_block( $block );
	$expect( "$id: no family the site has not ($family) in its settings or its markup", ! preg_match( '/"fontFamily":"' . preg_quote( $family, '/' ) . '"|has-' . preg_quote( $family, '/' ) . '-font-family|--wp--preset--font-family--' . preg_quote( $family, '/' ) . '\b/', $markup . $html ), $one_line( $markup ) );
	$expect( "$id: it parses back to the same blocks", ! is_wp_error( Block_Tree::serialize_checked( array( $block ) ) ) );
}
$expect( 'some of them were made here to look at', $checked >= 2, (string) $checked );
// A family the site has stays: the first family the site has, set on a block of a layout made here.
$kept = array_key_first( array_filter( $families, static fn( $v, $k ) => $k !== '', ARRAY_FILTER_USE_BOTH ) );
if ( is_string( $kept ) && $kept !== '' ) {
	$fonts = new ReflectionMethod( Block_Library::class, 'fonts' );
	$fonts->setAccessible( true );
	$b = array(
		'blockName'    => 'core/heading',
		'attrs'        => array( 'fontFamily' => $kept ),
		'innerBlocks'  => array(),
		'innerHTML'    => '<h2 class="wp-block-heading has-' . $kept . '-font-family">Kept</h2>',
		'innerContent' => array( '<h2 class="wp-block-heading has-' . $kept . '-font-family">Kept</h2>' ),
	);
	$args = array( &$b );
	$fonts->invokeArgs( null, $args );
	$expect( "a family the site has ($kept) stays", $kept === ( $b['attrs']['fontFamily'] ?? '' ) && str_contains( (string) $b['innerHTML'], 'has-' . $kept . '-font-family' ) );

	// A family the theme names and does not draw (American Restoration names seventeen, Inter among them, and loads Lora and Poppins):
	// taken off, by its class too (`"fontFamily":"Inter"` writes `has-inter-font-family`). The same family as the theme's own font: kept.
	$named = static function ( $data ) {
		return $data->update_with( array( 'version' => 3, 'settings' => array( 'typography' => array( 'fontFamilies' => array( array( 'slug' => 'Inter', 'name' => 'Inter', 'fontFamily' => '"Inter", sans-serif' ) ) ) ) ) );
	};
	$theirs = static fn() => array( 'id' => 'test', 'heading' => '"Inter", sans-serif', 'body' => 'Poppins, sans-serif', 'heading_slug' => 'test-heading', 'body_slug' => 'test-body', 'faces' => '' );
	$fresh  = static function (): void {
		wp_clean_theme_json_cache();
		WP_Theme_JSON_Resolver::clean_cached_data();
		\DXAI_UI\Theme\Theme_Fonts::reset();
	};
	$inter = static function () use ( $fonts ): array {
		$b    = array(
			'blockName'    => 'core/heading',
			'attrs'        => array( 'fontFamily' => 'Inter', 'level' => 3 ),
			'innerBlocks'  => array(),
			'innerHTML'    => '<h3 class="wp-block-heading has-inter-font-family">What should I do if a pipe bursts?</h3>',
			'innerContent' => array( '<h3 class="wp-block-heading has-inter-font-family">What should I do if a pipe bursts?</h3>' ),
		);
		$args = array( &$b );
		$fonts->invokeArgs( null, $args );

		return $b;
	};
	add_filter( 'wp_theme_json_data_theme', $named );
	$fresh();
	$b = $inter();
	$expect( 'a family the theme names but the page does not draw (Inter on American Restoration): taken off, its class too', ! isset( $b['attrs']['fontFamily'] ) && ! str_contains( (string) $b['innerHTML'], 'font-family' ), $one_line( serialize_block( $b ) ) );
	add_filter( 'dxai_ui_theme_fonts', $theirs, PHP_INT_MAX );
	$fresh();
	$b = $inter();
	$expect( '…and kept where it is the theme\'s own heading font', 'Inter' === ( $b['attrs']['fontFamily'] ?? '' ) && str_contains( (string) $b['innerHTML'], 'has-inter-font-family' ), $one_line( serialize_block( $b ) ) );
	remove_filter( 'dxai_ui_theme_fonts', $theirs, PHP_INT_MAX );
	remove_filter( 'wp_theme_json_data_theme', $named );
	$fresh();
}

echo "\nThe words of a place's page\n";
$lib  = array( 'company' => 'Taylored Restoration', 'company_short' => 'Taylored', 'phone' => '(907) 344-1239', 'industry' => 'restoration', 'places' => array(), 'services' => array(), 'lists' => array(), 'places_home' => array( array( 'title' => 'Fairbanks, AK' ) ) );
$of   = static fn( string $title, string $type ): array => \DXAI_UI\Pages\Layouts::page_facts( $lib, array( 'title' => $title, 'type' => $type ), 'two-col', 'seed', array() );
$here = $of( 'Anchorage, AK', 'location' );
$expect( 'a place\'s own page is about the company\'s trade, in that place (its title is no service)', 'restoration' === $here['subject'] && 'Anchorage, AK' === $here['region'], wp_json_encode( array( $here['subject'], $here['region'] ) ) );
$said = '';
for ( $n = 0; $n < 12 && ! str_contains( $said, 'handles' ); $n++ ) {
	$said .= ' ' . \DXAI_UI\Pages\Layouts::words( 'p-l', array( 'slot_seed' => (string) $n ) + $here, null );
}
$expect( 'its words say what is done there, never the place as a service ("handles anchorage, ak")', str_contains( $said, 'Taylored Restoration handles restoration for homes and businesses in Anchorage, AK.' ) && ! str_contains( $said, 'anchorage, ak' ), $one_line( $said ) );
$expect( '…and its questions\' heading starts as a heading does', 'Restoration questions, answered' === \DXAI_UI\Pages\Layouts::words( 'h2-s', array( 'role' => 'faq' ) + $here, null ) );
$town = $of( 'Wasilla & Mat-Su Valley', 'location' );
$expect( 'a place without a state is the place too', 'restoration' === $town['subject'] && 'Wasilla & Mat-Su Valley' === $town['region'], wp_json_encode( array( $town['subject'], $town['region'] ) ) );
$in   = $of( 'Water Damage in Tyler, TX', 'location' );
$serv = $of( 'Water Damage Restoration', 'service' );
$expect( 'a service in a place, and a service, are about the service, as before', 'Water Damage' === $in['subject'] && 'Tyler, TX' === $in['region'] && 'Water Damage Restoration' === $serv['subject'] && 'Fairbanks, AK' === $serv['region'], wp_json_encode( array( $in['subject'], $in['region'], $serv['subject'], $serv['region'] ) ) );

echo "\nWhat a varied section can do without\n";
$optional = new ReflectionMethod( Section_Variants::class, 'optional' );
$optional->setAccessible( true );
$section = static function ( string $items ): array {
	$markup = '<!-- wp:group {"tagName":"section"} --><section class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">Certified, Accredited &amp; Accountable</h2><!-- /wp:heading --><!-- wp:paragraph --><p>The standards behind every job.</p><!-- /wp:paragraph --><!-- wp:list --><ul class="wp-block-list">' . $items . '</ul><!-- /wp:list --></section><!-- /wp:group -->';

	return parse_blocks( $markup )[0];
};
$badges = $section( '<!-- wp:list-item --><li><img src="https://example.test/bbb.png" alt=""> BBB Accredited</li><!-- /wp:list-item --><!-- wp:list-item --><li><img src="https://example.test/iicrc.png" alt=""> IICRC Certified Firm</li><!-- /wp:list-item --><!-- wp:list-item --><li><img src="https://example.test/epa.png" alt=""> EPA Lead-Safe</li><!-- /wp:list-item -->' );
$ticks  = $section( '<!-- wp:list-item --><li>24/7 line</li><!-- /wp:list-item --><!-- wp:list-item --><li>About 60 min arrival</li><!-- /wp:list-item --><!-- wp:list-item --><li>3 Alaska locations</li><!-- /wp:list-item -->' );
$expect( 'a list of badges (pictures) is what the section shows: not something it can do without', ! isset( $optional->invoke( null, Section_Library::analyze( $badges, 0 ), $badges )['list'] ) );
$expect( 'the ticks under a heading still are', isset( $optional->invoke( null, Section_Library::analyze( $ticks, 0 ), $ticks )['list'] ) );

echo "\n{$pass} passed, {$fail} failed\n";
if ( $fail > 0 ) {
	exit( 1 );
}

<?php
/**
 * The look of a design's Home on the team's sections a page is made of (Blocks\Native\Library_Style, Pages\Block_Library):
 *
 *   wp eval-file bin/verify-library-style.php --user=1
 *
 * A Home with a look of its own — its section titles at the left in one colour, a light ground between its white sections, a band for
 * its call to action, cards that are one box (eight of them, each with a hover class of its own), a strip of six small claims beside
 * them, questions with a line under each — and the team's layouts made for a page of it: their cards, grounds, heads, bands, questions
 * and badges in that look. Found on Taylored Restoration's menu pages, which looked unfinished beside their Home (2026-10-19).
 *
 * Needs the American Restoration theme: the team's layouts are made of its blocks. Exit 77 (SKIP) without it.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Blocks\Native\Library_Style;
use DXAI_UI\Pages\Block_Library;
use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Support\Color_Math;
use DXAI_UI\Theme\Capabilities;

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

if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'amr/span' ) ) {
	echo "SKIP: the team's layouts are made of the American Restoration theme's blocks, and this site has not them\n";
	exit( 77 );
}

$made = array();
register_shutdown_function(
	static function () use ( &$made ): void {
		foreach ( $made as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}
);

// The theme's colours the Home is drawn in: a dark one (white reads on it), a light one that is not white.
$palette = Capabilities::palette();
$dark    = '';
$light   = '';
foreach ( $palette as $slug => $hex ) {
	if ( ! is_string( $hex ) || Color_Math::parse( $hex ) === null ) {
		continue;
	}
	$is_dark = Color_Math::contrast( '#ffffff', $hex ) > Color_Math::contrast( '#000000', $hex );
	if ( $dark === '' && $is_dark && Color_Math::contrast( '#ffffff', $hex ) >= 4.5 ) {
		$dark = (string) $slug;
	} elseif ( $light === '' && ! $is_dark && Color_Math::distance( $hex, '#ffffff' ) >= 1.0 && Color_Math::distance( $hex, '#ffffff' ) < 8.0 ) {
		$light = (string) $slug;
	}
}
if ( $dark === '' || $light === '' ) {
	echo "  FAIL  the theme's palette has no dark colour and light tint to draw the Home in (" . wp_json_encode( $palette ) . ")\n";
	exit( 1 );
}

$run     = substr( md5( (string) microtime( true ) ), 0, 6 );
$heading = static fn( string $t, int $l = 2, string $color = '' ): string => '<!-- wp:heading {"level":' . $l . ( $color !== '' ? ',"textColor":"' . $color . '"' : '' ) . '} --><h' . $l . ' class="wp-block-heading' . ( $color !== '' ? ' has-' . $color . '-color has-text-color' : '' ) . '">' . $t . '</h' . $l . '><!-- /wp:heading -->';
$para    = static fn( string $t ): string => '<!-- wp:paragraph --><p>' . $t . '</p><!-- /wp:paragraph -->';
$eyebrow = static fn( string $t ): string => '<!-- wp:paragraph {"className":"text-13 uppercase fw-700 ls-0-16em"} --><p class="text-13 uppercase fw-700 ls-0-16em">' . $t . '</p><!-- /wp:paragraph -->';
$ground  = static fn( string $slug, string $inner ): string => '<!-- wp:group {"tagName":"section","backgroundColor":"' . $slug . '"} --><section class="wp-block-group has-' . $slug . '-background-color has-background">' . $inner . '</section><!-- /wp:group -->';
$plain   = static fn( string $inner ): string => '<!-- wp:group {"tagName":"section"} --><section class="wp-block-group">' . $inner . '</section><!-- /wp:group -->';
// The Home's cards: one box, each card with a hover class of its own (the importer writes one per element).
$svc  = static fn( int $n, string $t ) => '<!-- wp:group {"className":"dxai-sh-' . $n . ' rounded-sm px-6 pt-6 pb-5 bg-dxai-neutral-96","dxaiCss":"border:1px solid var(--dxai-neutral-91);border-bottom:3px solid var(--dxai-brand)"} --><div class="wp-block-group dxai-sh-' . $n . ' rounded-sm px-6 pt-6 pb-5 bg-dxai-neutral-96 dxs-svc">' . $heading( $t, 3 ) . $para( 'Extraction, drying and readings after a leak.' ) . '</div><!-- /wp:group -->';
// A strip of six claims under the hero: a little padding each, no box — more of them than of the cards.
$claim = static fn( string $t ) => '<!-- wp:group {"className":"px-1 py-0"} --><div class="wp-block-group px-1 py-0">' . $heading( $t, 3 ) . $para( 'Since 1972.' ) . '</div><!-- /wp:group -->';
// A question as the importer writes one (a box that is a <details>, its question a <summary>), a line under it.
$faq   = static fn( string $q ) => '<!-- wp:dxai-ui/box {"tagName":"details","dxaiCss":"border-bottom:1px solid var(--dxai-surface)"} --><details class="dxs-faq"><!-- wp:group {"tagName":"summary","className":"px-1 py-5 d-flex justify-between"} --><summary class="wp-block-group px-1 py-5 d-flex justify-between">' . $heading( $q, 3 ) . '</summary><!-- /wp:group -->' . $para( 'Within the hour, day or night.' ) . '</details><!-- /wp:dxai-ui/box -->';
// Three call cards in the hero, one box alike: more alike than any one of the four service cards, fewer than the four together.
$call  = static fn( string $t ) => '<!-- wp:group {"className":"rounded-sm px-5 pt-5 pb-5 shadow-dxai-shadow-1 bg-dxai-on-accent"} --><div class="wp-block-group rounded-sm px-5 pt-5 pb-5 shadow-dxai-shadow-1 bg-dxai-on-accent">' . $heading( $t, 3 ) . $para( 'Call the office.' ) . '</div><!-- /wp:group -->';
$home  = $ground( $dark, $heading( 'Restoration in Harborview', 1 ) . $para( 'Water, fire and mold, day or night.' ) . '<!-- wp:group {"className":"grid"} --><div class="wp-block-group grid">' . implode( '', array_map( $call, array( 'Harborview', 'Bayside', 'Northgate' ) ) ) . '</div><!-- /wp:group -->' )
	. $plain( '<!-- wp:group {"className":"grid"} --><div class="wp-block-group grid">' . implode( '', array_map( $claim, array( 'Fast', 'Local', 'Certified', 'Insured', 'Licensed', 'Trusted' ) ) ) . '</div><!-- /wp:group -->' )
	. $ground( $light, $eyebrow( 'Services' ) . $heading( 'What we restore', 2, $dark ) . $para( 'Every job by our own crews.' ) . '<!-- wp:group {"className":"grid"} --><div class="wp-block-group grid">' . implode( '', array_map( $svc, range( 1, 4 ), array( 'Water', 'Fire', 'Mold', 'Storm' ) ) ) . '</div><!-- /wp:group -->' )
	. $ground( $dark, $heading( 'Water damage? We are on site within the hour.' ) . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="tel:+15550100101">Call now</a></div><!-- /wp:button --></div><!-- /wp:buttons -->' )
	. $plain( $eyebrow( 'About' ) . $heading( 'Rooted in Harborview', 2, $dark ) . $para( 'Fifty years of the same family running the same crews in the same town, with the same phone number.' ) . $para( 'We answer at night.' ) )
	. $plain( $eyebrow( 'FAQ' ) . $heading( 'Your questions', 2, $dark ) . implode( '', array_map( $faq, array( 'How fast are you?', 'Do you bill insurance?', 'Do you rebuild?' ) ) ) );
$home_id = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Harborview Styled ' . $run, 'post_content' => wp_slash( $home ) ) );
$made[]  = $home_id;
update_post_meta( $home_id, Page_Scope::META, $home_id );
update_post_meta( $home_id, '_dxai_ui_generated_page', '1' );

echo "What the Home looks like (Library_Style::look())\n";
$look = Library_Style::look( $home_id );
$expect( 'its cards are the four boxes with a hover class each, not the six claims with a little padding nor the three call cards (a card is a box; a hover class of its own does not make four looks of one)', in_array( 'bg-dxai-neutral-96', (array) ( $look['card']['class'] ?? array() ), true ) && in_array( 'border:1px solid var(--dxai-neutral-91)', (array) ( $look['card']['css'] ?? array() ), true ), wp_json_encode( $look['card'] ?? null ) );
$expect( 'its light ground and the band of its call to action', ( $look['ground']['light']['css'] ?? null ) === array( 'background-color:var(--wp--preset--color--' . $light . ')' ) && ( $look['ground']['cta']['css'] ?? null ) === array( 'background-color:var(--wp--preset--color--' . $dark . ')' ), wp_json_encode( $look['ground'] ?? null ) );
$expect( 'its section titles stand at the left, in one colour', ( $look['head']['left'] ?? null ) === true && ( $look['head']['color'] ?? '' ) === $dark, wp_json_encode( $look['head'] ?? null ) );
$expect( 'its questions: a line under each, no box', ( $look['faq']['css'] ?? null ) === array( 'border-bottom:1px solid var(--dxai-surface)' ), wp_json_encode( $look['faq'] ?? null ) );

// A page of that Home: the team's layouts made for it.
$facts = array(
	'home'          => $home_id,
	'industry'      => 'restoration',
	'company'       => 'Harborview Restoration',
	'company_short' => 'Harborview',
	'phone'         => '(555) 010-0101',
	'phone_url'     => 'tel:+15550100101',
	'title'         => 'Water Damage',
	'kind'          => 'service',
	'contact_url'   => 'https://example.test/contact/',
	'site_url'      => 'https://example.test/',
	'places'        => array( array( 'title' => 'Harborview, AK', 'url' => '' ), array( 'title' => 'Bayside, AK', 'url' => '' ), array( 'title' => 'Northgate, AK', 'url' => '' ) ),
	'services'      => array(),
	'dry'           => true,
	'slot_seed'     => '3',
	'about'         => '',
	'topic'         => 'water damage',
	'lists'         => array(
		'faq'     => array( array( 'title' => 'How fast are you?', 'text' => 'Within the hour.', 'url' => '' ), array( 'title' => 'Do you bill insurance?', 'text' => 'We work with every insurer.', 'url' => '' ), array( 'title' => 'Do you rebuild?', 'text' => 'The same crew rebuilds.', 'url' => '' ) ),
		'related' => array( array( 'title' => 'Fire Damage', 'text' => 'Soot, smoke and the rebuild.', 'url' => 'https://example.test/fire/' ), array( 'title' => 'Mold Removal', 'text' => 'Containment and clearance.', 'url' => 'https://example.test/mold/' ), array( 'title' => 'Storm Damage', 'text' => 'Roof tarps and drying.', 'url' => 'https://example.test/storm/' ) ),
		'items'   => array( array( 'title' => 'Licensed and insured', 'text' => 'In every borough we work in.', 'url' => '' ), array( 'title' => 'Our own crews', 'text' => 'No subcontractors on your job.', 'url' => '' ), array( 'title' => 'Insurance help', 'text' => 'We talk to the adjuster.', 'url' => '' ) ),
		'stats'   => array( array( 'title' => '25,000+', 'text' => 'Homes restored', 'url' => '' ) ),
	),
	'image_url:1'   => 'https://example.test/wp-content/uploads/crew.jpg',
	'image_id:1'    => '0',
	'image_alt:1'   => 'The crew at work',
	'image_w:1'     => '1200',
	'image_h:1'     => '800',
);
$flat = static function ( array $b ) use ( &$flat ): array {
	$out = array( $b );
	foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $c ) {
		if ( is_array( $c ) && ! empty( $c['blockName'] ) ) {
			$out = array_merge( $out, $flat( $c ) );
		}
	}

	return $out;
};
$made_as = static function ( string $id, string $role, array $more = array() ) use ( $facts ): array {
	$why   = '';
	$block = Block_Library::fill( $id, array_merge( $facts, array( 'role' => $role ), $more ), $why );

	return array( $block, $why );
};
$colour_of = static function ( array $b ) use ( $palette ): string {
	$slug = (string) ( $b['attrs']['textColor'] ?? '' );
	if ( $slug === '' && preg_match( '/^var:preset\|color\|([a-z0-9-]+)$/', (string) ( $b['attrs']['style']['color']['text'] ?? '' ), $m ) === 1 ) {
		$slug = $m[1];
	}

	return (string) ( $palette[ $slug ] ?? '' );
};

echo "\nA call to action is the Home's band; a strip is a strip\n";
$band_hex = (string) $palette[ $dark ];
foreach ( array( 'layout-cta-7', 'layout-cta-6' ) as $id ) {
	list( $cta, $why ) = $made_as( $id, 'cta' );
	$title             = null;
	foreach ( $cta !== null ? $flat( $cta ) : array() as $b ) {
		if ( $b['blockName'] === 'core/heading' ) {
			$title = $b;
			break;
		}
	}
	$hex = $title !== null ? $colour_of( $title ) : '';
	$expect( "$id: on the Home's band, set in its Colour panel (the team's white, orange or navy ground off), and its title reads on it", $cta !== null && ( $cta['attrs']['backgroundColor'] ?? '' ) === $dark && ! isset( $cta['attrs']['style']['color']['background'] ) && ! str_contains( (string) ( $cta['attrs']['dxaiCss'] ?? '' ), 'background' ) && $hex !== '' && Color_Math::contrast( $hex, $band_hex ) >= 3.0, $why . ' ' . wp_json_encode( array( $cta['attrs'] ?? null, $hex ) ) );
}
// (Call to action · team layout 2: a strip of claims and a call, no title — on Taylored Restoration's pages a band with drawings that vanished.)
$strip = 'layout-cta-2';
if ( ! str_contains( Block_Library::markup( $strip ), 'wp:heading' ) ) {
	list( $bar, $why ) = $made_as( $strip, 'cta' );
	$expect( "a strip of claims and a call with no title ($strip) in a call to action's place is no band: its drawings in the Home's colours would vanish on it", $bar !== null && ( $bar['attrs']['backgroundColor'] ?? '' ) !== $dark, $why . ' ' . wp_json_encode( $bar['attrs'] ?? null ) );
}

echo "\nThe head of a section as the Home's\n";
$heads = 0;
$wrong = array();
foreach ( Block_Library::entries() as $eid => $entry ) {
	$src = Block_Library::markup( (string) $eid );
	if ( ( $entry['source'] ?? '' ) !== 'layout' || ! preg_match( '/"textAlign":"center"|has-text-align-center/', $src ) ) {
		continue;
	}
	list( $block, $why ) = $made_as( (string) $eid, (string) ( $entry['role'] ?? 'cards' ) );
	if ( $block === null ) {
		continue;
	}
	$on_band = ( $block['attrs']['backgroundColor'] ?? '' ) === $dark;
	foreach ( $flat( $block ) as $b ) {
		if ( $b['blockName'] === 'core/heading' && (int) ( $b['attrs']['level'] ?? 2 ) === 2 ) {
			++$heads;
			$centred = ( $b['attrs']['textAlign'] ?? '' ) === 'center' || ( $b['attrs']['style']['typography']['textAlign'] ?? '' ) === 'center' || preg_match( '/^\s*<h2[^>]*has-text-align-center/', (string) $b['innerHTML'] ) === 1;
			if ( $centred || ( ! $on_band && $colour_of( $b ) !== (string) $palette[ $dark ] ) ) {
				$wrong[] = "$eid: " . wp_json_encode( array( $b['attrs']['textColor'] ?? null, $centred ) );
			}
			break;
		}
	}
}
$expect( 'a centred title of the team\'s stands at the left, in the Home\'s titles\' colour (on a light ground)', $heads >= 5 && $wrong === array(), $heads . ' ' . implode( '; ', array_slice( $wrong, 0, 3 ) ) );
$badge = null;
foreach ( Block_Library::entries() as $eid => $entry ) {
	if ( ( $entry['source'] ?? '' ) === 'layout' && str_contains( Block_Library::markup( (string) $eid ), 'skewX' ) ) {
		list( $made_badge, $why ) = $made_as( (string) $eid, (string) ( $entry['role'] ?? 'cards' ) );
		if ( $made_badge !== null ) {
			$badge = array( (string) $eid, serialize_block( $made_badge ) );
			break;
		}
	}
}
$expect( 'the team\'s badge round the line over a title (a skewed box of colour) is the Home\'s plain eyebrow', $badge !== null && ! str_contains( $badge[1], 'skewX' ) && str_contains( $badge[1], 'ls-0-16em' ), $badge !== null ? $badge[0] : 'no layout with a badge was made' );

echo "\nCards, questions and pictures\n";
// (Related pages · team layout 2: a column, its link box painted white, a group in that painted white again.)
list( $related, $why ) = $made_as( 'layout-related-2', 'related' );
$boxes = 0;
$twice = array();
foreach ( $related !== null ? $flat( $related ) : array() as $b ) {
	if ( str_contains( (string) ( $b['attrs']['className'] ?? '' ), 'bg-dxai-neutral-96' ) ) {
		++$boxes;
		// Nothing inside the card paints a ground of its own (it was a white box in a grey one).
		foreach ( array_slice( $flat( $b ), 1 ) as $in ) {
			if ( in_array( (string) $in['blockName'], array( 'core/group', 'core/column', 'amr/link-box' ), true ) && ( isset( $in['attrs']['style']['color']['background'] ) || isset( $in['attrs']['backgroundColor'] ) ) ) {
				$twice[] = (string) $in['blockName'];
			}
		}
	}
}
$expect( 'a team\'s card that is boxes in boxes is one card in the Home\'s look: the box that paints takes it, the boxes in it paint no ground', $related !== null && $boxes >= 3 && $twice === array(), $why . ' ' . $boxes . ' ' . implode( ',', $twice ) );
list( $questions, $why ) = $made_as( 'layout-faq-1', 'faq' );
$lines = 0;
$boxed = array();
foreach ( $questions !== null ? $flat( $questions ) : array() as $b ) {
	if ( str_contains( (string) ( $b['attrs']['className'] ?? '' ), 'dxai-lib-card' ) ) {
		if ( str_contains( (string) ( $b['attrs']['dxaiCss'] ?? '' ), 'border-bottom:1px solid var(--dxai-surface)' ) && ! isset( $b['attrs']['style']['color']['background'] ) && ! isset( $b['attrs']['style']['border'] ) ) {
			++$lines;
		} else {
			$boxed[] = wp_json_encode( $b['attrs'] );
		}
	}
}
$expect( 'the team\'s questions take the Home\'s line under each, not its cards\' box, and keep their room', $questions !== null && $lines >= 3 && $boxed === array(), $why . ' ' . implode( '; ', array_slice( $boxed, 0, 1 ) ) );
list( $pictured, $why ) = $made_as( 'layout-two-col-2', 'two-col' );
$expect( 'a figure laid over a picture the layout has not (two columns · team layout 2) lies over one of the page\'s pictures', $pictured !== null && str_contains( serialize_block( $pictured ), 'crew.jpg' ), $why );
list( $bare, $why ) = $made_as( 'layout-two-col-2', 'two-col', array( 'image_url:1' => '' ) );
$expect( '…and with no picture for the page it is not made (no figure floats over an empty half of the page)', $bare === null && str_contains( $why, 'image_url:1' ), $why );

echo "\nOn a phone, and in capitals\n";
// (Cards · team layout 6: a box set full width inside the section, which takes the section's padding back with a negative margin.)
list( $six, $why ) = $made_as( 'layout-cards-6', 'cards' );
$room = null;
if ( $six !== null ) {
	$side = (array) ( $six['attrs']['style']['spacing']['padding'] ?? array() );
	foreach ( (array) $six['innerBlocks'] as $in ) {
		if ( is_array( $in ) && ( $in['attrs']['align'] ?? '' ) === 'full' ) {
			$room = array( (string) ( $in['attrs']['style']['spacing']['padding']['left'] ?? '' ), (string) ( $side['left'] ?? 'var(--wp--style--root--padding-left)' ), str_contains( (string) $in['innerHTML'], 'padding-left:' ) );
		}
	}
}
$expect( 'a box set full width inside a section, with nothing of its own at that width, takes the section\'s padding as its own (on a phone its title and cards stood on the edge of the screen)', $room !== null && $room[0] !== '' && $room[0] === $room[1] && $room[2], $why . ' ' . wp_json_encode( $room ) );
// (As a page is made: a sentence said once. The layout says its call twice, for a phone and for a screen: the second time the call is
// said, and a sentence of provisional words took its place, in capitals.)
list( $caps, $why ) = $made_as( 'layout-two-col-4', 'two-col', array( 'once' => true, 'title' => 'Capitals ' . $run ) );
$shout = array();
foreach ( $caps !== null ? $flat( $caps ) : array() as $b ) {
	if ( $b['blockName'] === 'core/paragraph' && ( ( $b['attrs']['style']['typography']['textTransform'] ?? '' ) === 'uppercase' || str_contains( (string) ( $b['attrs']['className'] ?? '' ), 'uppercase' ) ) && str_word_count( wp_strip_all_tags( (string) $b['innerHTML'] ) ) > 8 ) {
		$shout[] = trim( wp_strip_all_tags( (string) $b['innerHTML'] ) );
	}
}
$expect( 'a line set in capitals takes no sentence of provisional words (a label is not a sentence shouted)', $caps !== null && $shout === array(), $why . ' ' . implode( ' | ', $shout ) );
$mute = array();
$tels = 0;
foreach ( $caps !== null ? $flat( $caps ) : array() as $b ) {
	if ( Capabilities::is_block( (string) $b['blockName'], 'link_box' ) && str_starts_with( (string) ( $b['attrs']['url'] ?? '' ), 'tel:' ) ) {
		++$tels;
		if ( trim( wp_strip_all_tags( serialize_block( $b ) ) ) === '' ) {
			$mute[] = (string) ( $b['attrs']['className'] ?? '' );
		}
	}
}
$expect( '…and the call it says twice (for a phone and for a screen) is said both times: no link to the phone is left with no words', $tels >= 2 && $mute === array(), $tels . ' ' . implode( ' | ', $mute ) );

echo "A Home that draws its buttons as links (a design kept as it was)\n";
// Its calls to action are the design's links with a fill of its colours (bg-dxai-accent text-dxai-ink), and its colours follow the
// theme's: the library's buttons keep the theme's style and take the Home's fill and text in their Colour panel (H2O Away, 2026-10-23).
$ink = '';
foreach ( $palette as $slug => $hex ) {
	if ( (string) $slug !== $dark && Capabilities::text_slug_ok( (string) $slug ) && is_string( $hex ) && Color_Math::parse( $hex ) !== null ) {
		$ink = (string) $slug;
		break;
	}
}
$cta_link  = static fn( string $t ): string => '<!-- wp:dxai-ui/link {"url":"tel:+15550100101","text":"' . $t . '","className":"rounded-10px px-7 gap-2-5 items-center flex-inline text-dxai-ink fw-900 bg-dxai-accent"} /-->';
// (The second call is a text the design drew as a link — a call with its icon, as H2O Away's are.)
$cta_text  = static fn( string $t ): string => '<!-- wp:dxai-ui/text {"tagName":"a","url":"tel:+15550100101","className":"dxai-sh-71 rounded-10px px-6-5 gap-2-5 items-center flex-inline text-18 text-dxai-ink fw-900 bg-dxai-accent"} /-->';
$link_home = $ground( $light, $heading( 'Restoration in Harborview', 1 ) . $cta_link( 'Call now' ) ) . $plain( $heading( 'Water damage?' ) . $cta_text( 'Get help now' ) );
$binding   = static fn( string $mode ): array => array(
	get_stylesheet() => array(
		'schema' => DXAI_UI\Theme\Theme_Binding::SCHEMA,
		'mode'   => $mode,
		'tokens' => array(
			'accent' => array( 'to' => $dark ),
			'ink'    => array( 'to' => $ink ),
		),
	),
);
$link_id = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Harborview Links ' . $run, 'post_content' => wp_slash( $link_home ) ) );
$made[]  = $link_id;
update_post_meta( $link_id, Page_Scope::META, $link_id );
update_post_meta( $link_id, '_dxai_ui_generated_page', '1' );
update_post_meta( $link_id, DXAI_UI\Theme\Theme_Binding::META, $binding( 'follow' ) );
$look = Library_Style::look( $link_id );
$expect( 'its buttons are read from its links: the presets their fill and their text follow', ( $look['button']['primary']['fill'] ?? '' ) === $dark && ( $look['button']['primary']['text'] ?? '' ) === $ink, wp_json_encode( $look['button'] ?? null ) );
$band = parse_blocks(
	'<!-- wp:group {"tagName":"section"} --><section class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">Call us</h2><!-- /wp:heading -->'
	. '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"className":"is-style-primary-button"} --><div class="wp-block-button is-style-primary-button"><a class="wp-block-button__link wp-element-button" href="tel:+15550100101">Call now</a></div><!-- /wp:button -->'
	. '<!-- wp:button {"className":"is-style-secondary-button"} --><div class="wp-block-button is-style-secondary-button"><a class="wp-block-button__link wp-element-button" href="/contact/">Write to us</a></div><!-- /wp:button --></div><!-- /wp:buttons --></section><!-- /wp:group -->'
)[0];
$dressed = Library_Style::follow( $band, $link_id, 'cta' );
$buttons = array();
foreach ( $flat( $dressed ) as $b ) {
	if ( (string) $b['blockName'] === 'core/button' ) {
		$buttons[] = $b;
	}
}
$first = $buttons[0] ?? array();
$expect( 'the library\'s button keeps the theme\'s style and wears the Home\'s fill and text in its Colour panel', ( $first['attrs']['backgroundColor'] ?? '' ) === $dark && ( $first['attrs']['textColor'] ?? '' ) === $ink && str_contains( (string) ( $first['attrs']['className'] ?? '' ), 'is-style-primary-button' ), wp_json_encode( $first['attrs'] ?? null ) );
$expect( '…with the classes core\'s Button writes for them on its link, in core\'s order', str_contains( (string) ( $first['innerHTML'] ?? '' ), 'class="wp-block-button__link has-' . $ink . '-color has-' . $dark . '-background-color has-text-color has-background has-link-color wp-element-button"' ), (string) ( $first['innerHTML'] ?? '' ) );
$expect( 'the second button (an outline) keeps its own look', ! isset( $buttons[1]['attrs']['backgroundColor'] ) && ! str_contains( (string) ( $buttons[1]['innerHTML'] ?? '' ), 'has-background' ) );
update_post_meta( $link_id, DXAI_UI\Theme\Theme_Binding::META, $binding( 'keep' ) );
wp_update_post( array( 'ID' => $link_id, 'post_content' => wp_slash( $link_home . ' ' ) ) );
$expect( 'a Home that keeps its own colours (they follow no preset) says nothing about its buttons', ( Library_Style::look( $link_id )['button'] ?? null ) === null );
update_post_meta( $link_id, DXAI_UI\Theme\Theme_Binding::META, $binding( 'follow' ) );
wp_update_post( array( 'ID' => $link_id, 'post_content' => wp_slash( $ground( $light, $heading( 'Restoration in Harborview', 1 ) . $cta_link( 'Call now' ) ) ) ) );
$expect( '…nor does a Home with one such link', ( Library_Style::look( $link_id )['button'] ?? null ) === null );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

<?php
/**
 * Text that nearly reads on its backgrounds gets the nearest colour that does; the colour usage sees a card's own background.
 *
 *   bash bin/wp-php.sh bin/verify-legible-text.php        (or: wp eval-file bin/verify-legible-text.php --user=1)
 *
 * H2O Away's Home scored 95 in Lighthouse's accessibility audit: its muted grey #6a737d is 4.48:1 on the card colour the theme gives it
 * (#f5f7f9), and normal text needs 4.5 (docs/PLAN-PERFECT-PAGES.md, T5.5). Theme\Legible_Text gives such a colour — one that nearly
 * reads on every background it sits on — the nearest that does, on its text channel. And Color_Usage did not see that pair at all: the
 * card's colour is in the Colour panel (`style.color.background`, written by Native\Background_Color), which it did not read, so the text
 * was read against the white section under the card.
 *
 * And where one text colour cannot read on every background the theme gave it (Five Star: its yellow brand follows the theme's blue,
 * black text there is 3.9:1, the same black is on white elsewhere), Theme_Binding gives it a colour per background, on what paints
 * that background — Color_Usage records those painters with the text they paint under.
 *
 * Makes design pages and deletes them, also when a check dies half-way. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Compiler\Color_Usage;
use DXAI_UI\Compiler\Token_Styles;
use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Support\Color_Math;
use DXAI_UI\Theme\Legible_Text;

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

echo "Which text colours are moved, and how far\n";
$values = array(
	'muted' => '#6a737d',
	'card'  => '#f5f7f9',
	'white' => '#ffffff',
	'grey'  => '#999999',
	'ink'   => '#333333',
	'band'  => '#1b2330',
	'mist'  => '#7a8490',
	'mid'   => '#787878',
	'black' => '#000000',
	'pale'  => '#a3a3a3',
	'veil'  => 'rgba(128,128,128,0.12)',
	'dim'   => '#818181',
);
$pair  = static fn( string $fg, string $bg, bool $large = false ): array => array( $fg, $bg, 1, $large );
$fixed = Legible_Text::adjust( $values, array( $pair( 'muted', 'card' ), $pair( 'muted', 'white' ) ) );
$new   = $fixed['muted'] ?? '';
$expect( 'a colour 4.48:1 on its card is moved to read there and on white (4.5:1)', $new !== '' && Color_Math::contrast( $new, '#f5f7f9' ) >= 4.5 && Color_Math::contrast( $new, '#ffffff' ) >= 4.5, $new );
$expect( '…by the least step: the same colour to the eye (ΔE under 1.5) and darker', $new !== '' && Color_Math::distance( $new, '#6a737d' ) < 1.5 && Color_Math::luminance( (array) Color_Math::parse( $new ) ) < Color_Math::luminance( (array) Color_Math::parse( '#6a737d' ) ), $new . ' ΔE ' . ( $new !== '' ? round( Color_Math::distance( $new, '#6a737d' ), 2 ) : '' ) );
$expect( 'a colour that reads is left as it is', array() === Legible_Text::adjust( $values, array( $pair( 'ink', 'white' ), $pair( 'ink', 'card' ) ) ) );
$expect( 'a colour far off (2.8:1) is the design\'s decision: left as it is', array() === Legible_Text::adjust( $values, array( $pair( 'grey', 'white' ) ) ) );
$expect( 'large text needs 3:1: #999 at 2.85:1 is moved, and reads at 3:1', isset( Legible_Text::adjust( $values, array( $pair( 'grey', 'white', true ) ) )['grey'] ) && Color_Math::contrast( Legible_Text::adjust( $values, array( $pair( 'grey', 'white', true ) ) )['grey'], '#ffffff' ) >= 3.0 );
$light = Legible_Text::adjust( $values, array( $pair( 'mist', 'band' ) ) );
$expect( 'light text on a dark band nearly reading is moved lighter', isset( $light['mist'] ) && Color_Math::contrast( $light['mist'], '#1b2330' ) >= 4.5 && Color_Math::luminance( (array) Color_Math::parse( $light['mist'] ) ) > Color_Math::luminance( (array) Color_Math::parse( '#7a8490' ) ), wp_json_encode( $light ) . ' from ' . round( Color_Math::contrast( '#7a8490', '#1b2330' ), 2 ) );
$both = Legible_Text::adjust( $values, array( $pair( 'mid', 'white' ), $pair( 'mid', 'black' ) ) );
$expect( 'a colour on white (4.42:1) and on black (4.76:1) goes darker, away from white, and still reads on black', isset( $both['mid'] ) && Color_Math::contrast( $both['mid'], '#ffffff' ) >= 4.5 && Color_Math::contrast( $both['mid'], '#000000' ) >= 4.5, wp_json_encode( $both ) );
$expect( '3.9:1 is not nearly reading: the design\'s decision, left as it is', array() === Legible_Text::adjust( $values, array( $pair( 'dim', 'white' ) ) ) );
$expect( 'a translucent background (a grey veil at 12%, under which muted text is 4.3:1) is not what the text is read on', array() === Legible_Text::adjust( $values, array( $pair( 'muted', 'veil' ) ) ) );
$expect( 'a token named to be skipped is left as it is', array() === Legible_Text::adjust( $values, array( $pair( 'muted', 'card' ) ), array( 'muted' ) ) );
// The theme binding gave the ink's text a dark grey on the theme's orange (4.01:1, Arcus's ink on the theme's orange is 4.38:1): read in that grey, not the design's own white.
$bound = Legible_Text::adjust( array_merge( $values, array( 'ink' => '#ffffff', 'orange' => '#e64804' ) ), array( $pair( 'ink', 'orange' ) ), array(), array( 'ink' => '#222222' ) );
$expect( 'a text colour the theme binding chose is read as the page shows it (#222 on the orange, 4.01:1) and nudged darker, the least step, to read', isset( $bound['ink'] ) && Color_Math::contrast( $bound['ink'], '#e64804' ) >= 4.5 && Color_Math::luminance( (array) Color_Math::parse( $bound['ink'] ) ) < Color_Math::luminance( (array) Color_Math::parse( '#222222' ) ) && Color_Math::contrast( $bound['ink'], '#e64804' ) < 4.7, wp_json_encode( $bound ) . ' from ' . round( Color_Math::contrast( '#222222', '#e64804' ), 2 ) );

echo "\nA design's page\n";
$made  = array();
$clean = static function () use ( &$made ): void {
	foreach ( $made as $id ) {
		wp_delete_post( (int) $id, true );
	}
	$made = array();
};
register_shutdown_function( $clean );
// A white section with a card in the Colour panel's custom colour, and muted text in the card.
$content = '<!-- wp:group {"tagName":"section","className":"bg-dxai-white"} --><section class="wp-block-group bg-dxai-white">'
	. '<!-- wp:group {"style":{"color":{"background":"var(--dxai-card)"}}} --><div class="wp-block-group has-background" style="background-color:var(--dxai-card)">'
	. '<!-- wp:paragraph {"style":{"color":{"text":"var(--dxai-muted--fg,var(--dxai-muted))"}}} --><p class="has-text-color" style="color:var(--dxai-muted--fg,var(--dxai-muted))">Olympia, WA</p><!-- /wp:paragraph -->'
	. '</div><!-- /wp:group --></section><!-- /wp:group -->';
$home = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Legible text fixture',
		'post_content' => $content,
	),
	true
);
$home = is_wp_error( $home ) ? 0 : (int) $home;
if ( $home > 0 ) {
	$made[] = $home;
	update_post_meta( $home, Page_Scope::META, (string) $home );
	update_post_meta(
		$home,
		Token_Styles::META,
		array(
			'colors' => array(
				array( 'slug' => 'muted', 'value' => '#6a737d' ),
				array( 'slug' => 'card', 'value' => '#f5f7f9' ),
				array( 'slug' => 'white', 'value' => '#ffffff' ),
			),
		)
	);
}
$usage = $home > 0 ? Color_Usage::scan( $home ) : array();
$rows  = Color_Usage::pair_rows( (array) ( $usage['pairs'] ?? array() ) );
$seen  = array_map( static fn( $r ) => $r[0] . '|' . $r[1], $rows );
$expect( 'the colour usage reads the card\'s own background (the Colour panel\'s custom colour): muted text on the card, not on the section', in_array( 'muted|card', $seen, true ) && ! in_array( 'muted|white', $seen, true ), wp_json_encode( $seen ) );
$css = $home > 0 ? Legible_Text::css( $home ) : '';
$expect( 'the design\'s rule gives muted text its readable colour, on its text channel', 1 === preg_match( '/^\.dxai-ui\.dxai-ui--' . $home . '\{--dxai-muted--fg:(#[0-9a-f]{6})\}$/', $css, $m ) && Color_Math::contrast( $m[1], '#f5f7f9' ) >= 4.5, $css );
$expect( '…and the page prints it with its design\'s colours (Token_Styles::brand_css)', $css !== '' && str_contains( Token_Styles::brand_css( $home ), $css ) );
add_filter( 'dxai_ui_legible_text', '__return_false' );
$expect( 'nothing when the filter says no', '' === Legible_Text::css( $home ) );
remove_filter( 'dxai_ui_legible_text', '__return_false' );

echo "\nWhat paints each background\n";
$run_css  = 'background:var(--dxai-band);padding:8px';
$painting = '<!-- wp:group {"tagName":"section","className":"text-dxai-ink"} --><section class="wp-block-group text-dxai-ink">'
	. '<!-- wp:group {"className":"bg-dxai-band text-dxai-paper"} --><div class="wp-block-group bg-dxai-band text-dxai-paper"><!-- wp:paragraph --><p>On the band</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
	. '<!-- wp:paragraph {"dxaiInner":{"dxs-run9":"background:var(\u002d\u002ddxai-band);padding:0 4px"}} --><p>Step <span class="dxs-run9">01</span></p><!-- /wp:paragraph -->'
	. '<!-- wp:group {"className":"bg-dxai-band","dxaiCss":"color:#ffffff"} --><div class="wp-block-group bg-dxai-band"><!-- wp:paragraph --><p>White by a literal</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
	. '<!-- wp:group {"style":{"color":{"background":"var(\u002d\u002ddxai-band)"}}} --><div class="wp-block-group has-background" style="background-color:var(--dxai-band)"><!-- wp:paragraph --><p>Panel</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
	. '<!-- wp:group {"dxaiCss":"' . $run_css . '"} --><div class="wp-block-group"><!-- wp:paragraph --><p>Own rule</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
	. '</section><!-- /wp:group -->';
$painted = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Legible text fixture (painters)', 'post_content' => wp_slash( $painting ) ), true );
$painted = is_wp_error( $painted ) ? 0 : (int) $painted;
if ( $painted > 0 ) {
	$made[] = $painted;
	update_post_meta( $painted, Page_Scope::META, (string) $painted );
	update_post_meta( $painted, Token_Styles::META, array( 'colors' => array( array( 'slug' => 'ink', 'value' => '#111111' ), array( 'slug' => 'paper', 'value' => '#ffffff' ), array( 'slug' => 'band', 'value' => '#0b4f8a' ) ) ) );
}
$pu       = $painted > 0 ? Color_Usage::scan( $painted ) : array();
$painters = (array) ( $pu['painters'] ?? array() );
$ink_band = (array) ( $painters['band|ink'] ?? array() );
$expect( 'a background is painted by the importer\'s class, under the text it holds (band under paper)', in_array( '.bg-dxai-band', (array) ( $painters['band|paper'] ?? array() ), true ), wp_json_encode( $painters ) );
$expect( '…by an inline run of a block, by the Colour panel\'s style and by a block\'s own rule, under the text they inherit (ink)', in_array( '.dxs-run9', $ink_band, true ) && in_array( '[style*="background-color:var(--dxai-band)"]', $ink_band, true ) && in_array( '.' . \DXAI_UI\Compiler\Style_Hoister::css_class( $run_css ), $ink_band, true ), wp_json_encode( $ink_band ) );
$expect( 'a block that sets its text in a colour that is no token is no painter of the text above it, and its text is no token\'s', ! in_array( '.bg-dxai-band', $ink_band, true ), wp_json_encode( $ink_band ) );
$expect( 'the inline run is paired with its own background', in_array( 'ink|band', array_map( static fn( $r ) => $r[0] . '|' . $r[1], Color_Usage::pair_rows( (array) ( $pu['pairs'] ?? array() ) ) ), true ) );

echo "\nA colour for each background (Theme_Binding::scoped)\n";
$sd_design = array( 'body' => '#000000', 'brand' => '#fcb123', 'white' => '#ffffff', 'on' => '#111111' );
$sd_now    = array( 'body' => '#000000', 'brand' => '#016bc4', 'white' => '#ffffff', 'on' => '#111111' );
$sd_pairs  = array( array( 'body', 'brand', 8, false ), array( 'body', 'white', 30, false ), array( 'body', 'body', 2, false ) );
$sd_paint  = array( 'brand|body' => array( '.bg-dxai-brand', '.dxs-step' ), 'white|body' => array( '.bg-dxai-white' ), 'brand|on' => array( '.bg-dxai-brand' ), 'body|body' => array( '.dxs-icon' ) );
$sd_rules  = \DXAI_UI\Theme\Theme_Binding::scoped( $sd_pairs, $sd_design, $sd_now, array(), $sd_paint );
$sd_all    = implode( "\n", $sd_rules );
$expect( 'black text on the brand the theme turned blue (3.9:1) gets a colour that reads there, on what paints it', 1 === preg_match( '/:is\(\.bg-dxai-brand,\.dxs-step\)\{--dxai-body--fg:([^;}]+)\}/', $sd_all, $sm ) && Color_Math::contrast( (string) ( preg_match( '/#[0-9a-f]{6}/i', $sm[1], $sh ) ? $sh[0] : $sm[1] ), '#016bc4' ) >= 4.5, $sd_all );
$expect( '…and that colour as its text too where only it is painted that way (.dxs-step), not where the class paints other text too', str_contains( $sd_all, ':is(.dxs-step){color:' ) && ! preg_match( '/:is\([^)]*\.bg-dxai-brand[^)]*\)\{color:/', $sd_all ), $sd_all );
$expect( 'on what paints its other backgrounds, its usual colour again (a white card inside the band)', str_contains( $sd_all, ':is(.bg-dxai-white){--dxai-body--fg:var(--dxai-body)}' ) && str_contains( $sd_all, ':is(.bg-dxai-white){color:var(--dxai-body)}' ), $sd_all );
$expect( 'a pair that does not read in the design itself (black on black: an icon) makes no rule', ! str_contains( $sd_all, '.dxs-icon' ), $sd_all );
$expect( 'nothing when one colour reads everywhere', array() === \DXAI_UI\Theme\Theme_Binding::scoped( $sd_pairs, $sd_design, $sd_design, array(), $sd_paint ) );

$clean();
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );

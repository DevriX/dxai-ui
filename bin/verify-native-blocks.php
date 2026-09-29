<?php
/**
 * Native_Blocks converters against fixtures: what converts, what must not, and that a second pass changes nothing.
 *
 *   bash bin/wp-php.sh bin/verify-native-blocks.php        (or: wp eval-file bin/verify-native-blocks.php)
 *
 * Runs on a site whose theme registers the blocks a converter writes (american-restoration: amr/link-box, amr/span
 * and the button styles); a converter whose native block is missing here is reported as skipped, not failed. Exit
 * code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Blocks\Native\Native_Blocks;
use DXAI_UI\Blocks\Native\Converter;

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
$convert = static fn( string $markup ): array => Native_Blocks::convert_content( $markup, array( 'home' => 0, 'post' => 0 ) );
$names   = static fn( string $markup ): array => array_values( array_filter( array_map( static fn( $b ) => (string) $b['blockName'], parse_blocks( $markup ) ) ) );
$have    = array();
foreach ( Native_Blocks::converters() as $c ) {
	$have[ $c->id() ] = true;
}
echo "converters on this site: " . implode( ', ', array_keys( $have ) ) . "\n";

// --- Fixtures: the stored forms the importer writes. ---------------------------------------------------------------
$card = '<!-- wp:dxai-ui/link {"url":"https://example.org/a/","className":"rounded-md d-block text-dxai-ink bg-dxai-neutral-100","hasInner":true} -->'
	. '<a class="wp-block-dxai-ui-link rounded-md d-block text-dxai-ink bg-dxai-neutral-100" href="https://example.org/a/">'
	. '<!-- wp:paragraph --><p>Inside</p><!-- /wp:paragraph --></a><!-- /wp:dxai-ui/link -->';
$card_bare = str_replace( array( ' d-block', ' text-dxai-ink' ), '', $card );
$card_data = str_replace( '"hasInner":true', '"hasInner":true,"dxaiData":{"data-x":"1"}', $card );
$span      = '<!-- wp:dxai-ui/text {"className":"text-14 fw-900"} --><span class="text-14 fw-900">Learn more →</span><!-- /wp:dxai-ui/text -->';
$span_data = '<!-- wp:dxai-ui/text {"className":"x","ariaHidden":"true","dxaiData":{"data-plus":""},"attrOrder":["class"]} --><span aria-hidden="true" data-plus="" class="x">+</span><!-- /wp:dxai-ui/text -->';
$box       = '<!-- wp:dxai-ui/box {"className":"px-4 d-flex"} --><div class="px-4 d-flex"><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --></div><!-- /wp:dxai-ui/box -->';
$box_empty = '<!-- wp:dxai-ui/box {"className":"absolute inset-0","ariaHidden":"true"} --><div aria-hidden="true" class="absolute inset-0"></div><!-- /wp:dxai-ui/box -->';
$box_ctrl  = '<!-- wp:dxai-ui/box {"className":"t","ariaExpanded":"false"} --><div class="t" aria-expanded="false"><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --></div><!-- /wp:dxai-ui/box -->';
$cta       = static fn( string $cls, string $css, string $text ): string => '<!-- wp:dxai-ui/link {"url":"tel:5551234","text":"' . $text . '","className":"' . $cls . '"' . ( $css !== '' ? ',"dxaiCss":"' . $css . '"' : '' ) . '} -->'
	. '<a class="wp-block-dxai-ui-link ' . $cls . '" href="tel:5551234">' . $text . '</a><!-- /wp:dxai-ui/link -->';
$filled    = $cta( 'rounded-5px px-7 py-4 bg-dxai-accent text-dxai-ink fw-900', '', 'Call now' );
$ghost     = $cta( 'rounded-5px px-6 text-white', 'background:transparent;padding-top:17px;padding-bottom:17px;border:2px solid var(--dxai-neutral-100-a55)', 'Request a callback' );
$navlink   = '<!-- wp:dxai-ui/link {"url":"#about","text":"About","className":"px-3 fw-700","dxaiData":{"data-dxai-set":"null"}} --><a class="wp-block-dxai-ui-link px-3 fw-700" href="#about" data-dxai-set="null">About</a><!-- /wp:dxai-ui/link -->';
$skip      = $cta( 'px-4 py-3 absolute top-0 bg-dxai-accent', 'left:-9999px;z-index:999', 'Skip to content' );

echo "\nLink box\n";
if ( isset( $have['link-box'] ) ) {
	$r = $convert( $card );
	$expect( 'a link around content becomes amr/link-box', $names( $r['content'] ) === array( 'amr/link-box' ), implode( ',', $names( $r['content'] ) ) );
	$expect( 'its inner block is kept', str_contains( $r['content'], '<p>Inside</p>' ) );
	$expect( 'without display and colour of its own it stays a DX Link', $names( $convert( $card_bare )['content'] ) === array( 'dxai-ui/link' ) );
	$expect( 'with data attributes it stays a DX Link', $names( $convert( $card_data )['content'] ) === array( 'dxai-ui/link' ) );
} else {
	echo "  skip  amr/link-box is not registered here\n";
}

echo "\nSpan\n";
if ( isset( $have['span'] ) ) {
	$expect( 'a span of words becomes amr/span', $names( $convert( $span )['content'] ) === array( 'amr/span' ) );
	$expect( 'a span with aria-hidden and data attributes stays a DX Text', $names( $convert( $span_data )['content'] ) === array( 'dxai-ui/text' ) );
} else {
	echo "  skip  amr/span is not registered here\n";
}

echo "\nGroup\n";
$expect( 'a container with blocks becomes core/group', $names( $convert( $box )['content'] ) === array( 'core/group' ) );
$expect( 'an empty box stays a DX Box (an empty group opens as a layout picker)', $names( $convert( $box_empty )['content'] ) === array( 'dxai-ui/box' ) );
$expect( 'a control with aria-expanded stays a DX Box', $names( $convert( $box_ctrl )['content'] ) === array( 'dxai-ui/box' ) );

echo "\nButtons\n";
if ( isset( $have['button'] ) ) {
	$r = $convert( $filled );
	$expect( 'a filled CTA becomes core/buttons', $names( $r['content'] ) === array( 'core/buttons' ), implode( ',', $names( $r['content'] ) ) );
	$expect( 'in the theme\'s primary style', str_contains( $r['content'], 'is-style-primary-button' ) );
	$expect( 'an outlined CTA is the secondary style', str_contains( $convert( $ghost )['content'], 'is-style-secondary-button' ) );
	$r = $convert( $filled . "\n\n" . $ghost );
	$blocks = array_values( array_filter( parse_blocks( $r['content'] ), static fn( $b ) => ! empty( $b['blockName'] ) ) );
	$expect( 'two adjacent CTAs are one Buttons block', count( $blocks ) === 1 && $blocks[0]['blockName'] === 'core/buttons' && count( $blocks[0]['innerBlocks'] ) === 2 );
	$expect( 'a menu item (data attributes) stays a DX Link', $names( $convert( $navlink )['content'] ) === array( 'dxai-ui/link' ) );
	$expect( 'the skip link stays a DX Link', $names( $convert( $skip )['content'] ) === array( 'dxai-ui/link' ) );
	$r = $convert( $filled . "\n\n" . $navlink . "\n\n" . $ghost );
	$expect( 'a menu item between two CTAs keeps them apart', count( array_filter( $names( $r['content'] ), static fn( $n ) => $n === 'core/buttons' ) ) === 2 );
} else {
	echo "  skip  the theme has no button styles here\n";
}

echo "\nTwo passes, and a stable round trip\n";
foreach ( array( 'card' => $card, 'span' => $span, 'box' => $box, 'filled' => $filled, 'filled+ghost' => $filled . "\n\n" . $ghost ) as $label => $markup ) {
	$once  = $convert( $markup )['content'];
	$twice = $convert( $once );
	$expect( "$label: a second pass changes nothing", $twice['counts'] === array() && $twice['content'] === $once );
	$expect( "$label: parse and serialize give the same bytes", serialize_blocks( parse_blocks( $once ) ) === $once );
}

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

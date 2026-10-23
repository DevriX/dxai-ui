<?php
/**
 * A page composed from the Home's sections keeps the Home's icons.
 *
 *   bash bin/wp-php.sh bin/verify-composer-icons.php        (or: wp eval-file bin/verify-composer-icons.php --user=1)
 *
 * The live-site crawl composes a page from a Home's sections and the old page's words and pictures (Page_Composer). A section's icon is
 * an image block of the Home's (an SVG file, class `dxai-icon`); it was taken for a picture slot, so a photograph of the old page was
 * put where the icon was, with the icon's own CSS (`max-width:none`, no size): the photograph at its natural size, thousands of pixels
 * wide. The icon stays; a picture slot beside it is filled as before. Reads nothing from the site and writes nothing. Exit code 1 when
 * a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Pages\Block_Tree;
use DXAI_UI\Pages\Page_Composer;

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

$composer = new Page_Composer(
	array(),
	static fn( string $src ): array => array( 'url' => 'http://example.test/uploads/' . basename( $src ), 'id' => 0 )
);
$pool = new ReflectionProperty( $composer, 'pool' );
$pool->setAccessible( true );
$pool->setValue( $composer, array( 'p1' => array( 'src' => 'http://old.test/mold.webp', 'alt' => 'A photograph', 'ref' => 'p1' ) ) );
$fill = new ReflectionMethod( $composer, 'image' );
$fill->setAccessible( true );

$icon  = array(
	'blockName'    => 'core/image',
	'attrs'        => array( 'className' => 'dxai-part-img dxai-icon', 'dxaiCss' => 'display:inline;margin-bottom:14px;max-width:none', 'url' => 'http://example.test/icons/a.svg' ),
	'innerBlocks'  => array(),
	'innerHTML'    => '<figure class="wp-block-image dxai-part-img dxai-icon"><img src="http://example.test/icons/a.svg" alt=""/></figure>',
	'innerContent' => array( '<figure class="wp-block-image dxai-part-img dxai-icon"><img src="http://example.test/icons/a.svg" alt=""/></figure>' ),
);
$photo = array(
	'blockName'    => 'core/image',
	'attrs'        => array( 'className' => 'w-full', 'url' => 'http://example.test/uploads/home.jpg' ),
	'innerBlocks'  => array(),
	'innerHTML'    => '<figure class="wp-block-image w-full"><img src="http://example.test/uploads/home.jpg" alt=""/></figure>',
	'innerContent' => array( '<figure class="wp-block-image w-full"><img src="http://example.test/uploads/home.jpg" alt=""/></figure>' ),
);

echo "A section's icon\n";
$section = array( 'blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array( $icon, $photo ), 'innerHTML' => '', 'innerContent' => array( null, null ) );
$fill->invokeArgs( $composer, array( &$section, array( 0 ), 'p1' ) );
$expect( 'an old photograph is not put in its place', ( $section['innerBlocks'][0]['attrs']['url'] ?? '' ) === 'http://example.test/icons/a.svg' && empty( $section['innerBlocks'][0]['attrs']['__dxai_image'] ) );
$expect( '…and the icon is not dropped for having no picture to show', empty( $section['innerBlocks'][0]['attrs']['__dxai_drop'] ) );
$fill->invokeArgs( $composer, array( &$section, array( 0 ), '' ) );
$expect( '…not even when the old page has none', empty( $section['innerBlocks'][0]['attrs']['__dxai_drop'] ) );

echo "\nA picture slot beside it\n";
$fill->invokeArgs( $composer, array( &$section, array( 1 ), 'p1' ) );
$expect( 'is filled as before', ( $section['innerBlocks'][1]['attrs']['url'] ?? '' ) === 'http://example.test/uploads/mold.webp' && ! empty( $section['innerBlocks'][1]['attrs']['__dxai_image'] ), wp_json_encode( $section['innerBlocks'][1]['attrs'] ?? null ) );
$fill->invokeArgs( $composer, array( &$section, array( 1 ), '' ) );
$expect( 'and goes when the old page has no picture for it', ! empty( $section['innerBlocks'][1]['attrs']['__dxai_drop'] ) );

echo "A rating's stars drawn before a line (H2O Away's call band, 2026-10-23)\n";
// The Home's line: its stars as drawing (aria-hidden), then the words. The old page's words go in its place, line by line.
$set = new ReflectionMethod( $composer, 'set' );
$set->setAccessible( true );
$line  = static function (): array {
	$html = '<p class="m-0 text-17 fw-600"><span aria-hidden="true" class="dxs-2qibk1">★★★★★</span> 4.9 rating · 800+ Google reviews</p>';
	return array( 'blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array( array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => $html, 'innerContent' => array( $html ) ) ), 'innerHTML' => '', 'innerContent' => array( null ) );
};
$put   = static function ( string $words ) use ( $set, $composer, $line ): string {
	$b = $line();
	$set->invokeArgs( $composer, array( &$b, array( 0 ), $words ) );
	return (string) $b['innerBlocks'][0]['innerHTML'];
};
$other = $put( 'Skip the form. Call and a live person will dispatch the nearest crew.' );
$expect( 'before words that are not about the rating, the stars go', ! str_contains( $other, '★' ) && str_contains( $other, '<p class="m-0 text-17 fw-600">Skip the form.' ), $other );
$rated = $put( 'Rated 4.8 by 300 neighbours' );
$expect( 'before a line about the rating, they stay', str_contains( $rated, '<span aria-hidden="true" class="dxs-2qibk1">★★★★★</span> Rated 4.8' ), $rated );
$twice = $put( '★★★★★ Rated 5.0 in 300 Google reviews' );
$expect( 'before words with stars of their own, they go (no ★★★★★ ★★★★★)', substr_count( $twice, '★★★★★' ) === 1, $twice );
$tick = array( 'blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array( array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p><span aria-hidden="true" class="tick">✓</span> Licensed and insured</p>', 'innerContent' => array( '<p><span aria-hidden="true" class="tick">✓</span> Licensed and insured</p>' ) ) ), 'innerHTML' => '', 'innerContent' => array( null ) );
$set->invokeArgs( $composer, array( &$tick, array( 0 ), 'IICRC certified crews' ) );
$expect( 'an icon that is not stars (a tick) stays before any words', str_contains( (string) $tick['innerBlocks'][0]['innerHTML'], '<span aria-hidden="true" class="tick">✓</span> IICRC certified crews' ), (string) $tick['innerBlocks'][0]['innerHTML'] );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

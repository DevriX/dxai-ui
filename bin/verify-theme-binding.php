<?php
/**
 * Which theme colour a design's colours follow (Theme_Binding::propose()): the brand, by role, to the theme's primary — or to a
 * shade of it when the shade is practically the brand's own colour.
 *
 *   bash bin/wp-php.sh bin/verify-theme-binding.php        (or: wp eval-file bin/verify-theme-binding.php)
 *
 * Makes one draft page (a design's Home with a palette of its own) and removes it; stores no binding. Runs where the theme has
 * colour settings with a primary and its dark shade (american-restoration: Primary #016bc4, Primary Dark #173764); elsewhere
 * reported as skipped. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Compiler\Token_Styles;
use DXAI_UI\Support\Color_Math;
use DXAI_UI\Theme\Theme_Binding;
use DXAI_UI\Theme\Theme_Palette;

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

$primary = Theme_Palette::hex( 'primary' );
$dark    = Theme_Palette::hex( 'primary-dark' );
if ( ! Theme_Palette::current()['has_palette'] || $primary === '' || $dark === '' ) {
	echo "  skip  the theme has no colour settings with a primary and its dark shade here\n";
	echo "\n$pass passed, $fail failed\n";
	return;
}

$home = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Fixture theme binding' ) );
register_shutdown_function(
	static function () use ( &$home ): void {
		if ( $home > 0 ) {
			wp_delete_post( $home, true );
			$home = 0;
		}
	}
);

/** The theme colour a design whose brand is this colour (and accent orange) has its brand follow, with the distance. */
$brand_to = static function ( string $brand ) use ( $home ): array {
	update_post_meta(
		$home,
		Token_Styles::META,
		array(
			'colors' => array(
				$brand    => array( 'slug' => 'brand', 'name' => 'Brand', 'value' => $brand, 'uses' => 5, 'preset' => true ),
				'#f28c28' => array( 'slug' => 'accent', 'name' => 'Accent', 'value' => '#f28c28', 'uses' => 3, 'preset' => true ),
			),
			'roles'  => array( 'brand' => 'brand', 'accent' => 'accent' ),
		)
	);
	$token = Theme_Binding::propose( $home )['tokens']['brand'] ?? array();

	return array( (string) ( $token['to'] ?? '' ), (string) ( $token['tier'] ?? '' ), (float) ( $token['de'] ?? -1 ) );
};

echo "The brand\n";
$navy = $brand_to( '#0d2a55' );
$expect( 'a navy brand practically the theme\'s dark primary (Northlight Roofing\'s #0d2a55) follows Primary Dark, not the bright Primary', $navy[0] === 'primary-dark', wp_json_encode( $navy ) );
$expect( 'without review: it is that close', $navy[1] === 'auto' && $navy[2] <= 6.0, wp_json_encode( $navy ) );
$joe = $brand_to( '#022663' );
$expect( 'so does Clean Joe\'s navy #022663', $joe[0] === 'primary-dark', wp_json_encode( $joe ) );
$blue = $brand_to( '#2563eb' );
$expect( 'a blue brand follows the Primary, by role, as before', $blue[0] === 'primary', wp_json_encode( $blue ) );
foreach ( array( '#c51527' => 'red (Arcus)', '#fcb123' => 'yellow (Five Star)', '#64c040' => 'green (DevriX)' ) as $hex => $what ) {
	$to = $brand_to( $hex );
	$expect( "a $what brand still follows the Primary: no shade of it is anywhere near", $to[0] === 'primary', wp_json_encode( $to ) . ' — distance to Primary Dark ' . round( Color_Math::distance( $hex, $dark ), 1 ) );
}

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

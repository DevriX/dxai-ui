<?php
/**
 * @package DXAI_UI
 */

declare(strict_types=1);

$wp_root = $argv[1] ?? 'C:\\Users\\DevriX\\Local Sites\\DXAI-UI\\app\\public';
require rtrim( $wp_root, '/\\' ) . '/wp-load.php';

$home    = 87312;
$contact = 87553;
$hc      = (string) get_post_field( 'post_content', $home );
$cc      = (string) get_post_field( 'post_content', $contact );

echo 'same_css=' . ( get_post_meta( $home, '_dxai_ui_css_url', true ) === get_post_meta( $contact, '_dxai_ui_css_url', true ) ? '1' : '0' ) . PHP_EOL;

preg_match( '/"slug":"(dxai-header[^"]*)"/', $hc, $hm );
$slug = $hm[1] ?? '';
echo 'header_slug=' . $slug . PHP_EOL;

$part = $slug !== '' ? get_page_by_path( $slug, OBJECT, 'wp_template_part' ) : null;
if ( ! $part ) {
	$found = get_posts(
		array(
			'post_type'      => 'wp_template_part',
			'name'           => $slug,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
		)
	);
	$part = $found[0] ?? null;
}

if ( $part instanceof WP_Post ) {
	$c = (string) $part->post_content;
	echo 'header_id=' . $part->ID . ' bytes=' . strlen( $c ) . PHP_EOL;
	echo 'has_a=' . ( preg_match( '/<a\b/i', $c ) ? '1' : '0' ) . PHP_EOL;
	echo 'has_wp_nav=' . ( str_contains( $c, 'wp:navigation' ) ? '1' : '0' ) . PHP_EOL;
	echo 'still_h2oaway=' . ( str_contains( $c, 'h2oaway.com' ) ? '1' : '0' ) . PHP_EOL;
	echo 'has_dxai_permalink=' . ( str_contains( $c, '/dxai-' ) || str_contains( $c, 'page_id=' ) ? '1' : '0' ) . PHP_EOL;
	if ( preg_match_all( '/href="([^"]+)"/', $c, $m ) ) {
		$uniq = array_values( array_unique( $m[1] ) );
		foreach ( array_slice( $uniq, 0, 20 ) as $h ) {
			echo "href\t$h\n";
		}
	}
	$pos = stripos( $c, 'Contact' );
	if ( $pos !== false ) {
		echo 'contact_snip=' . preg_replace( '/\s+/', ' ', substr( $c, max( 0, $pos - 60 ), 200 ) ) . PHP_EOL;
	}
} else {
	echo "header_missing\n";
}

echo "contact_img_tags=" . substr_count( $cc, '<img' ) . PHP_EOL;
echo "contact_wp_image=" . substr_count( $cc, 'wp:image' ) . PHP_EOL;
echo "contact_html_island=" . substr_count( $cc, 'wp:html' ) . PHP_EOL;
echo "contact_dxai_box=" . substr_count( $cc, 'wp:dxai-ui/box' ) . PHP_EOL;
echo "contact_text_sample=" . substr( wp_strip_all_tags( $cc ), 0, 350 ) . PHP_EOL;

// First unique classes on contact (body-ish)
if ( preg_match_all( '/class="([^"]+)"/', $cc, $cm ) ) {
	$seen = array();
	foreach ( $cm[1] as $cl ) {
		if ( isset( $seen[ $cl ] ) ) {
			continue;
		}
		$seen[ $cl ] = true;
		if ( count( $seen ) > 20 ) {
			break;
		}
		echo "class\t$cl\n";
	}
}

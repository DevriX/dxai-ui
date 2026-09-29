<?php
/**
 * Inspect H2O site-from-menu results.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

$home    = 87312;
$contact = 87553;

echo 'HOME css=' . (string) get_post_meta( $home, '_dxai_ui_css_url', true ) . PHP_EOL;
echo 'CONTACT css=' . (string) get_post_meta( $contact, '_dxai_ui_css_url', true ) . PHP_EOL;
echo 'same_css=' . ( get_post_meta( $home, '_dxai_ui_css_url', true ) === get_post_meta( $contact, '_dxai_ui_css_url', true ) ? '1' : '0' ) . PHP_EOL;

$hc = (string) get_post_field( 'post_content', $home );
$cc = (string) get_post_field( 'post_content', $contact );
preg_match( '/"slug":"(dxai-header[^"]*)"/', $hc, $hm );
preg_match( '/"slug":"(dxai-header[^"]*)"/', $cc, $cm );
echo 'home_header_slug=' . ( $hm[1] ?? 'none' ) . PHP_EOL;
echo 'contact_header_slug=' . ( $cm[1] ?? 'none' ) . PHP_EOL;

$slug = $hm[1] ?? '';
if ( $slug !== '' ) {
	$parts = get_posts(
		array(
			'post_type'      => 'wp_template_part',
			'name'           => $slug,
			'posts_per_page' => 1,
			'post_status'    => 'publish',
		)
	);
	if ( isset( $parts[0] ) ) {
		$c = (string) $parts[0]->post_content;
		echo 'header_part_id=' . $parts[0]->ID . ' len=' . strlen( $c ) . PHP_EOL;
		echo 'header_has_wp_navigation=' . ( str_contains( $c, 'wp:navigation' ) ? '1' : '0' ) . PHP_EOL;
		echo 'header_has_a_href=' . ( str_contains( $c, '<a ' ) || str_contains( $c, 'href=' ) ? '1' : '0' ) . PHP_EOL;
		preg_match_all( '/href="([^"]+)"/', $c, $hs );
		foreach ( array_slice( $hs[1] ?? array(), 0, 12 ) as $h ) {
			echo '  href=' . $h . PHP_EOL;
		}
		// Show a Contact link context.
		if ( preg_match( '/.{0,80}Contact.{0,120}/is', $c, $ctx ) ) {
			echo 'contact_ctx=' . preg_replace( '/\s+/', ' ', $ctx[0] ) . PHP_EOL;
		}
	} else {
		echo "header_part_missing\n";
	}
}

echo "--- contact classes sample ---\n";
preg_match_all( '/class="([^"]{0,80})"/', $cc, $cls );
foreach ( array_slice( array_unique( $cls[1] ?? array() ), 0, 15 ) as $cl ) {
	echo '  class=' . $cl . PHP_EOL;
}

echo "--- contact text ---\n";
echo substr( wp_strip_all_tags( $cc ), 0, 500 ) . PHP_EOL;

$navs = get_posts(
	array(
		'post_type'      => 'wp_navigation',
		'posts_per_page' => 5,
		'post_status'    => 'any',
		's'              => 'H2O Away',
		'orderby'        => 'ID',
		'order'          => 'DESC',
	)
);
echo 'h2o_nav_search=' . count( $navs ) . PHP_EOL;
foreach ( $navs as $n ) {
	echo 'NAV #' . $n->ID . ' ' . $n->post_title . PHP_EOL;
	echo substr( $n->post_content, 0, 600 ) . PHP_EOL . "---\n";
}

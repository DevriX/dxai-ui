<?php
/**
 * Compile the GTM Strategy Hub TSX without an LLM.
 * Run: wp eval-file bin/verify-lovable-compile.php
 *
 * @package DXAI_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$user = get_user_by( 'login', 'admin' );
if ( $user instanceof WP_User ) {
	wp_set_current_user( $user->ID );
}

$tsx = DXAI_UI_DIR . '.tmp-gtm-hub/src/routes/index.tsx';
$css = DXAI_UI_DIR . '.tmp-gtm-hub/src/styles.css';
if ( ! is_readable( $tsx ) ) {
	echo "tsx_missing=1\n";
	return;
}

$code    = (string) file_get_contents( $tsx ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$styles  = is_readable( $css ) ? (string) file_get_contents( $css ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$order   = DXAI_UI\Compiler\Tsx_Section_Splitter::page_order( $code );
echo 'page_order=' . implode( ',', $order ) . PHP_EOL;

$harvest = array(
	'images' => array(),
	'css'    => $styles,
);
$sections = ( new DXAI_UI\Compiler\Jsx_Compiler() )->compile_file( $code, $harvest );
echo 'section_count=' . count( $sections ) . PHP_EOL;
foreach ( $sections as $section ) {
	$html = (string) ( $section['html'] ?? '' );
	echo sprintf(
		"section=%s type=%s html=%d has_rv=%d\n",
		$section['name'],
		$section['type'],
		strlen( $html ),
		str_contains( $html, 'rv-' ) ? 1 : 0
	);
}

$blob = implode( "\n", array_column( $sections, 'html' ) );
echo 'has_header=' . ( str_contains( $blob, 'rv-header' ) ? '1' : '0' ) . PHP_EOL;
echo 'has_hero=' . ( str_contains( $blob, 'rv-hero' ) ? '1' : '0' ) . PHP_EOL;
echo 'has_faq=' . ( str_contains( $blob, 'rv-faq' ) ? '1' : '0' ) . PHP_EOL;
echo 'has_details=' . ( str_contains( $blob, '<details' ) ? '1' : '0' ) . PHP_EOL;
echo 'has_footer=' . ( str_contains( $blob, 'rv-footer' ) ? '1' : '0' ) . PHP_EOL;
echo 'has_countup=' . ( str_contains( $blob, 'data-countup' ) ? '1' : '0' ) . PHP_EOL;
echo 'has_persona=' . ( str_contains( $blob, 'rv-persona' ) ? '1' : '0' ) . PHP_EOL;
echo 'persona_count=' . substr_count( $blob, 'class="rv-persona"' ) . PHP_EOL;
echo 'mbp_rows=' . substr_count( $blob, 'rv-mbp-row' ) . PHP_EOL;
echo 'has_quote=' . ( str_contains( $blob, 'We\'d approved' ) || str_contains( $blob, 'We’d approved' ) || str_contains( $blob, 'approved the GTM plan' ) ? '1' : '0' ) . PHP_EOL;
echo 'has_logo_img=' . ( str_contains( $blob, '<img' ) ? '1' : '0' ) . PHP_EOL;
echo 'hero_tabs=' . substr_count( $blob, 'rv-hero-tab' ) . PHP_EOL;
echo 'prepared_css=' . strlen( DXAI_UI\Compiler\Design_Css::prepare( $styles ) ) . PHP_EOL;
echo 'has_hero_full=' . ( str_contains( $blob, 'rv-hero-full' ) ? '1' : '0' ) . PHP_EOL;
echo 'has_hero_v3=' . ( str_contains( $blob, 'rv-hero-v3' ) ? '1' : '0' ) . PHP_EOL;

$scoped = DXAI_UI\Compiler\Css_Scoper::apply( '.dxai-ui.dxai-ui--1', '.rv-root { background: #1a1a1a; } .rv-root > section { margin: 0; }' );
echo 'scope_root_self=' . ( str_contains( $scoped, '.dxai-ui.dxai-ui--1.rv-root { background' ) ? '1' : '0' ) . PHP_EOL;
echo 'scope_root_child=' . ( str_contains( $scoped, '.dxai-ui.dxai-ui--1.rv-root > section' ) ? '1' : '0' ) . PHP_EOL;
echo 'scope_no_desc=' . ( str_contains( $scoped, '.dxai-ui.dxai-ui--1 .rv-root' ) ? '0' : '1' ) . PHP_EOL;

foreach ( $sections as $section ) {
	if ( in_array( $section['name'], array( 'WhoFor', 'Testimonial', 'Footer', 'Method', 'StickyCTA' ), true ) ) {
		echo "----- {$section['name']} -----\n";
		echo substr( (string) $section['html'], 0, 400 ) . "\n";
	}
}

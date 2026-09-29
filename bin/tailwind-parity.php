<?php
/**
 * Emit our engine's CSS for a design's class set, for comparison with upstream.
 *
 * Paired with bin/tailwind-parity.cjs, which runs the real Tailwind CLI over
 * the same classes and the same `@theme`, then diffs the two.
 *
 * Run: wp eval-file bin/tailwind-parity.php <zip-path> <out-dir>
 *
 * @package DXAI_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$zip  = isset( $args[0] ) ? (string) $args[0] : '';
$out  = isset( $args[1] ) ? rtrim( (string) $args[1], '/\\' ) : '';
$list = isset( $args[2] ) ? (string) $args[2] : '';

if ( $zip === '' || $out === '' || ! is_readable( $zip ) ) {
	echo "usage: wp eval-file bin/tailwind-parity.php <zip-path> <out-dir> [classes-file]\n";
	return;
}

$user = get_user_by( 'login', 'admin' );
if ( $user instanceof WP_User ) {
	wp_set_current_user( $user->ID );
}
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

wp_mkdir_p( $out );

$source = ( new DXAI_UI\Connectors\Lovable_Connector() )->import_zip(
	array(
		'name'     => basename( $zip ),
		'tmp_name' => $zip,
		'error'    => 0,
		'size'     => filesize( $zip ),
	)
);
if ( is_wp_error( $source ) ) {
	echo 'error=' . $source->get_error_message() . PHP_EOL;
	return;
}

$result = ( new DXAI_UI\Compiler\Source_Compiler() )->compile( $source );

// Every class the design actually uses, from the compiled markup and from the
// source HTML each structure kept — a class only present in a raw-HTML island
// still has to be emitted.
$markup = (string) ( $result['gutenberg_markup'] ?? '' );
foreach ( (array) ( $result['structures'] ?? array() ) as $structure ) {
	$markup .= "\n" . (string) ( $structure['source_html'] ?? '' );
}

$raw_css = (string) ( $result['design_css_raw'] ?? '' );

/*
 * With a class list, stand in synthetic markup for the design's own.
 *
 * A design exercises only the few hundred utilities it happens to use, so a
 * clean comparison says nothing about the rest of Tailwind. Handed the full
 * utility surface for this design's theme, the engine has to answer for all of
 * it, and a gap is found before a design that needs it arrives.
 */
if ( $list !== '' && is_readable( $list ) ) {
	$tokens = preg_split( '/\s+/', (string) file_get_contents( $list ) ) ?: array();
	$markup = '';
	foreach ( $tokens as $token ) {
		$token = trim( $token );
		if ( $token !== '' ) {
			$markup .= '<div class="' . htmlspecialchars( $token, ENT_QUOTES ) . '"></div>' . "\n";
		}
	}
}

/*
 * The stylesheet the plugin actually ships, from the class that assembles it,
 * so the comparison is against what a visitor loads rather than a re-assembly
 * of the pieces that happens to leave one out.
 *
 * Two earlier versions of this were unfair to us in different ways. Comparing
 * engine output alone reported all 36 `@property` registrations as missing —
 * they live in Preflight — and sent me looking for a divider bug that does not
 * exist. Adding the design CSS unscoped then changed nothing, because the
 * comparison drops any selector without the scope: upstream is handed the
 * design's stylesheet, so its output holds the design's own `.rv-case` and its
 * `@utility` expansions, and 222 of them read as classes we never emit.
 *
 * The pattern id is what the scope is built from; 0 makes it predictable.
 */
$scope = '.dxai-ui.dxai-ui--0';
$css   = ( new DXAI_UI\Compiler\Tailwind_Purger() )->compile( $markup, (string) ( $result['custom_css'] ?? '' ), 0, '', $raw_css );

$classes = array();
if ( preg_match_all( '/class(?:Name)?="([^"]*)"/i', $markup, $matches ) ) {
	foreach ( $matches[1] as $list ) {
		foreach ( preg_split( '/\s+/', $list ) ?: array() as $token ) {
			$token = trim( $token );
			if ( $token === '' || str_starts_with( $token, 'wp-' ) || str_starts_with( $token, 'dxai-' ) ) {
				continue;
			}
			$classes[ $token ] = true;
		}
	}
}
ksort( $classes );

file_put_contents( $out . '/ours.css', $css );
file_put_contents( $out . '/classes.txt', implode( "\n", array_keys( $classes ) ) . "\n" );
file_put_contents( $out . '/theme.css', $raw_css );

printf( "scope=%s classes=%d ours_bytes=%d\n", $scope, count( $classes ), strlen( $css ) );
printf( "out=%s\n", $out );

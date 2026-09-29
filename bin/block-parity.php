<?php
/**
 * Dump every block this plugin registers server-side, as JSON.
 *
 * Paired with bin/block-parity.cjs, which executes the editor bundle with a
 * stubbed `wp` and compares the two declarations.
 *
 * Read from WP_Block_Type_Registry rather than parsed out of the source,
 * because the registry is what Gutenberg actually consults. A regex over
 * Design_Blocks.php would agree with the file and could still disagree with
 * the thing being registered.
 *
 * Run: wp eval-file bin/block-parity.php [out.json]
 *
 * @package DXAI_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$out = isset( $args[0] ) ? (string) $args[0] : '';

/*
 * `init` is where the plugin registers. Under eval-file it has usually run
 * already, but firing nothing and reading an empty registry would look like a
 * plugin with no blocks — which is a pass, and wrong.
 */
if ( ! did_action( 'init' ) ) {
	do_action( 'init' );
}

$registered = WP_Block_Type_Registry::get_instance()->get_all_registered();
$blocks     = array();

foreach ( $registered as $name => $type ) {
	if ( ! str_starts_with( (string) $name, 'dxai-ui/' ) ) {
		continue;
	}

	$attributes = array();
	foreach ( (array) $type->attributes as $key => $spec ) {
		$attributes[ $key ] = array(
			'type'    => $spec['type'] ?? null,
			// A declared default of null and an absent default are different
			// facts to Gutenberg: the first serializes, the second does not.
			'default' => array_key_exists( 'default', (array) $spec ) ? $spec['default'] : '__none__',
			'source'  => $spec['source'] ?? null,
		);
	}
	ksort( $attributes );

	$supports = (array) ( $type->supports ?? array() );
	ksort( $supports );

	$blocks[ $name ] = array(
		'api_version' => (int) ( $type->api_version ?? 1 ),
		'attributes'  => $attributes,
		'supports'    => $supports,
		/*
		 * A dynamic block stores no markup, so save() never runs and an
		 * attribute mismatch cannot invalidate it. Reported so the comparison
		 * can tell "legitimately server-only" from "the editor half is
		 * missing".
		 */
		'dynamic'     => is_callable( $type->render_callback ),
	);
}

ksort( $blocks );

$json = wp_json_encode( $blocks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

if ( $out !== '' ) {
	file_put_contents( $out, $json );
	printf( "blocks=%d out=%s\n", count( $blocks ), $out );

	return;
}

echo $json . PHP_EOL;

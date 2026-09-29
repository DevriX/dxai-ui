<?php
/**
 * Two doc comments in Html_To_Blocks claim a divergence between the three
 * attribute escapers over five characters. What do they actually do?
 *
 * The three that decide whether PHP's save() and the editor's save() can write
 * the same bytes for an attribute value:
 *
 *   DOMDocument::saveHTML()      reads the design's own markup back out
 *   Html_To_Blocks::attr_value() the PHP emitter, a port of the editor's own
 *                                escapeAttribute()
 *   esc_attr()                   what the emitter used to be; kept in the
 *                                table because the difference is the point
 *   escapeAttribute() (JS)       the editor's emitter, run below out of the
 *                                real wp-includes/js/dist/escape-html.js
 *
 * usage: bin/wp-php.sh bin/escaper-parity.php, then node bin/escaper-parity.cjs
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 2 );
}

require __DIR__ . '/wp-boot.php';

$chars = array(
	'ampersand'      => 'a & b',
	'entity-looking' => 'a &amp; b',
	'double quote'   => 'a " b',
	'apostrophe'     => "a ' b",
	'less than'      => 'a < b',
	'greater than'   => 'a > b',
	'nbsp'           => "a\u{00a0}b",
);

$rows = array();
foreach ( $chars as $label => $value ) {
	// What saveHTML() writes for the same value in an attribute.
	$doc = new DOMDocument( '1.0', 'UTF-8' );
	$el  = $doc->createElement( 'span' );
	$el->setAttribute( 'title', $value );
	$doc->appendChild( $el );
	$saved = (string) $doc->saveHTML( $el );
	preg_match( '/title=(.*)>/U', $saved, $m );

	$rows[ $label ] = array(
		'value'    => $value,
		'saveHTML' => $m[1] ?? '(?)',
		'attr'     => \DXAI_UI\Compiler\Html_To_Blocks::attr_value( $value ),
		'esc_attr' => esc_attr( $value ),
		'esc_html' => esc_html( $value ),
	);
}

file_put_contents(
	sys_get_temp_dir() . '/dxai-escaper-parity.json',
	(string) wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE )
);

printf( "%-16s %-14s %-22s %-22s %s\n", 'character', 'input', 'saveHTML attr', 'attr_value', 'esc_attr' );
foreach ( $rows as $label => $row ) {
	printf(
		"%-16s %-14s %-22s %-22s %s\n",
		$label,
		json_encode( $row['value'] ),
		$row['saveHTML'],
		json_encode( $row['attr'] ),
		json_encode( $row['esc_attr'] )
	);
}

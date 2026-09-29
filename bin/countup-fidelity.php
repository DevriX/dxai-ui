<?php
/**
 * dxai-ui/countup must put back the element the design wrote, including the
 * screen-reader copy nested inside it.
 *
 * The visible figure is animated from 0 by the runtime, so a design pairs it
 * with a static copy for assistive technology:
 *
 *     <span class="rv-num" data-countup="62" data-suffix="%">62%<span
 *     class="sr-only">62%</span></span>
 *
 * countup() used to rebuild the element from `data-countup` and `data-suffix`
 * and write `value . suffix` as the whole of its content, dropping that copy
 * with no check to notice — the one leaf path in Html_To_Blocks with no
 * identity check. The design oracle counted four lost `span.sr-only` per width
 * on GTM Strategy Hub for exactly this.
 *
 * The cases below are the corpus shapes plus the shapes the emitter must
 * REFUSE rather than approximate, because a countup it cannot reproduce should
 * keep its markup and take the ordinary span route.
 *
 * usage: bin/wp-php.sh bin/countup-fidelity.php
 * exit:  0 every case behaves, 1 a countup was lost or approximated
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 2 );
}

require __DIR__ . '/wp-boot.php';

use DXAI_UI\Compiler\Html_To_Blocks;

/** [ label, source html, should become dxai-ui/countup ] */
$cases = array(
	// The two shapes the corpus actually ships.
	array(
		'empty suffix, sr copy',
		'<span class="rv-num" data-countup="62" data-suffix="">62<span class="sr-only">62</span></span>',
		true,
	),
	array(
		'percent suffix, sr copy',
		'<span class="rv-num" data-countup="62" data-suffix="%">62%<span class="sr-only">62%</span></span>',
		true,
	),
	// No copy at all: still a countup, and must not grow one.
	array(
		'no sr copy',
		'<span class="rv-num" data-countup="48" data-suffix="">48</span>',
		true,
	),
	// A design that spells the utility differently travels on the same block.
	array(
		'visually-hidden instead of sr-only',
		'<span class="rv-num" data-countup="3" data-suffix="x">3x<span class="visually-hidden">3x</span></span>',
		true,
	),
	// Shapes the emitter cannot reproduce, so it must refuse.
	array(
		'copy text differs from value+suffix',
		'<span class="rv-num" data-countup="62" data-suffix="%">62%<span class="sr-only">sixty two percent</span></span>',
		false,
	),
	array(
		'visible text differs from value+suffix',
		'<span class="rv-num" data-countup="62" data-suffix="%">up to 62%<span class="sr-only">62%</span></span>',
		false,
	),
	array(
		'copy carries more than a class',
		'<span class="rv-num" data-countup="62" data-suffix="%">62%<span class="sr-only" aria-hidden="true">62%</span></span>',
		false,
	),
	array(
		'two element children',
		'<span class="rv-num" data-countup="62" data-suffix="%">62%<span class="sr-only">62%</span><em>!</em></span>',
		false,
	),
	array(
		'copy is not a span',
		'<span class="rv-num" data-countup="62" data-suffix="%">62%<i class="sr-only">62%</i></span>',
		false,
	),
	array(
		'copy has no class',
		'<span class="rv-num" data-countup="62" data-suffix="%">62%<span>62%</span></span>',
		false,
	),
);

$fail = 0;
printf( "%-40s %-18s %s\n", 'case', 'block', 'verdict' );

foreach ( $cases as [ $label, $html, $want_countup ] ) {
	$markup = ( new Html_To_Blocks() )->convert( $html );
	$blocks = parse_blocks( $markup );
	$row    = $blocks[0] ?? array();
	$name   = (string) ( $row['blockName'] ?? '(none)' );
	$is     = $name === 'dxai-ui/countup';

	$notes = array();
	if ( $is !== $want_countup ) {
		++$fail;
		$notes[] = 'FAIL expected ' . ( $want_countup ? 'dxai-ui/countup' : 'a refusal' );
	}

	/*
	 * A countup is only correct if the stored markup still holds every node the
	 * design wrote. Comparing the element signatures would repeat the
	 * converter's own check, so compare what a browser would see instead: the
	 * tag names and text inside.
	 */
	if ( $is ) {
		$stored = (string) ( $row['innerHTML'] ?? '' );
		$before = substr_count( strtolower( $html ), '<span' );
		$after  = substr_count( strtolower( $stored ), '<span' );
		if ( $after !== $before ) {
			++$fail;
			$notes[] = sprintf( 'FAIL %d <span> in, %d out', $before, $after );
		}
		$want_text = trim( (string) preg_replace( '/<[^>]*>/', '', $html ) );
		$got_text  = trim( (string) preg_replace( '/<[^>]*>/', '', $stored ) );
		if ( $got_text !== $want_text ) {
			++$fail;
			$notes[] = 'FAIL text ' . var_export( $want_text, true ) . ' -> ' . var_export( $got_text, true );
		}
	}

	printf( "%-40s %-18s %s\n", $label, $name, $notes === array() ? 'ok' : implode( '; ', $notes ) );
}

printf( "\n%d of %d cases behave\n", count( $cases ) - $fail, count( $cases ) );

/*
 * `--json <file>` writes the attribute sets and the bytes THIS emitter wrote,
 * for bin/countup-parity.cjs to compare against the editor's save() through
 * the real @wordpress/element serializer. Two emitters that disagree by one
 * byte make the block invalid on load, and only the JS side can answer that.
 */
$json = false;
foreach ( $argv as $i => $arg ) {
	if ( $arg === '--json' && isset( $argv[ $i + 1 ] ) ) {
		$json = (string) $argv[ $i + 1 ];
	}
}
if ( $json !== false ) {
	$rows = array();
	foreach ( $cases as [ $label, $html, $want_countup ] ) {
		$blocks = parse_blocks( ( new Html_To_Blocks() )->convert( $html ) );
		$row    = $blocks[0] ?? array();
		if ( (string) ( $row['blockName'] ?? '' ) !== 'dxai-ui/countup' ) {
			continue;
		}
		$rows[] = array(
			'label'      => $label,
			'attributes' => is_array( $row['attrs'] ?? null ) ? $row['attrs'] : array(),
			'html'       => trim( (string) ( $row['innerHTML'] ?? '' ) ),
		);
	}
	// A srClass the corpus does not ship, so the parity run exercises the
	// second-child path on a value the compiler never chose for itself.
	$rows[] = array(
		'label'      => 'synthetic: screen-reader-text',
		'attributes' => array(
			'value'     => '1200',
			'suffix'    => '+',
			'className' => 'rv-num is-big',
			'srClass'   => 'screen-reader-text',
		),
		'html'       => Html_To_Blocks::countup_html(
			array(
				'value'     => '1200',
				'suffix'    => '+',
				'className' => 'rv-num is-big',
				'srClass'   => 'screen-reader-text',
			)
		),
	);
	file_put_contents( $json, (string) wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	printf( "wrote %d case(s) to %s\n", count( $rows ), $json );
}

exit( $fail === 0 ? 0 : 1 );

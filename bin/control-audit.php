<?php
/**
 * How many of a design's controls survive conversion, and why the rest do not.
 *
 * A control needs three things to work, and we were reliably producing only the
 * first two:
 *
 *   1. a TRIGGER  — something the runtime can recognise and bind a click to
 *   2. a TARGET   — an element the runtime can change
 *   3. a CLASS the design's own stylesheet actually selects on
 *
 * The third is the one that hides. A control can carry a perfectly correct
 * `aria-expanded` and still be inert, because not one of the seven designs in
 * this repo contains a single `[aria-expanded]`, `[aria-selected]`,
 * `[aria-pressed]` or `[aria-current]` selector — they key on `.is-active`,
 * `.is-done`, `.is-primary`, `.is-accent`, `.is-flagged`. When this was first
 * run the corpus had 390 triggers, 22 targets and 384 aria attributes, and the
 * behaviour probe found 38 of 158 controls inert on the live site.
 *
 * Deliberately NOT one of the `verify-*.php` suite files: it compiles every ZIP
 * from scratch, which takes minutes, and it needs its own WordPress bootstrap
 * rather than running under `wp eval-file`. It is an instrument to run when
 * changing the interactivity path, not a gate on every import.
 *
 * Run:
 *   php bin/control-audit.php --wp <wordpress-root> --zip "<a.zip>" --zip "<b.zip>"
 *
 * @package DXAI_UI
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

set_time_limit( 1800 );

$opts = array( 'zip' => array() );
$argvv = $argv;
array_shift( $argvv );
for ( $i = 0; $i < count( $argvv ); $i++ ) {
	$key  = ltrim( (string) $argvv[ $i ], '-' );
	$next = isset( $argvv[ $i + 1 ] ) && ! str_starts_with( (string) $argvv[ $i + 1 ], '--' ) ? (string) $argvv[ ++$i ] : '1';
	if ( $key === 'zip' ) {
		$opts['zip'][] = $next;
		continue;
	}
	$opts[ $key ] = $next;
}

if ( empty( $opts['wp'] ) || $opts['zip'] === array() ) {
	echo "usage: php bin/control-audit.php --wp <wordpress-root> --zip <design.zip> [--zip …]\n";
	exit( 2 );
}

require rtrim( (string) $opts['wp'], '/\\' ) . '/wp-load.php';

/**
 * Every state-ish class the design's own CSS actually selects on, with counts.
 *
 * @return array<string, int>
 */
function dxai_state_classes( string $css ): array {
	preg_match_all( '/\.(is-[a-z0-9-]+|has-[a-z0-9-]+|active|open|selected|current)\b/i', $css, $matches );
	$out = array();
	foreach ( $matches[1] ?? array() as $name ) {
		$name         = strtolower( $name );
		$out[ $name ] = ( $out[ $name ] ?? 0 ) + 1;
	}
	arsort( $out );

	return $out;
}

printf(
	"%-26s %8s %8s %8s %8s %8s\n",
	'design',
	'handlers',
	'triggers',
	'targets',
	'aria',
	'cssAria'
);

$totals = array( 'handlers' => 0, 'triggers' => 0, 'targets' => 0, 'aria' => 0, 'css_aria' => 0 );
$states = array();

foreach ( $opts['zip'] as $path ) {
	$name = basename( (string) $path );
	if ( ! is_readable( (string) $path ) ) {
		printf( "%-26s unreadable\n", substr( $name, 0, 26 ) );
		continue;
	}

	$source = ( new DXAI_UI\Connectors\Lovable_Connector() )->import_zip(
		array( 'name' => $name, 'tmp_name' => $path, 'error' => 0, 'size' => filesize( (string) $path ) )
	);
	if ( is_wp_error( $source ) ) {
		printf( "%-26s import error: %s\n", substr( $name, 0, 26 ), $source->get_error_message() );
		continue;
	}

	// What the design ASKS for: every handler prop its own source declares.
	$handlers = 0;
	$payload  = $source->payload;
	foreach ( array( 'components', 'sources' ) as $key ) {
		foreach ( (array) ( $payload[ $key ] ?? array() ) as $file => $code ) {
			if ( ! is_string( $code ) || preg_match( '/\.tsx?$/i', (string) $file ) !== 1 ) {
				continue;
			}
			$handlers += (int) preg_match_all( '/\bon(?:Click|Change|MouseEnter|MouseLeave|Focus|KeyDown)\s*=\s*\{/', $code );
		}
	}

	$result = ( new DXAI_UI\Compiler\Source_Compiler() )->compile( $source );
	$markup = (string) ( $result['gutenberg_markup'] ?? '' );
	foreach ( (array) ( $result['structures'] ?? array() ) as $structure ) {
		$markup .= "\n" . (string) ( $structure['source_html'] ?? '' );
	}
	$css = (string) ( $result['design_css_raw'] ?? '' );

	$triggers = (int) preg_match_all( '/dxai-toggle-[a-z0-9_-]+/i', $markup );
	$targets  = (int) preg_match_all( '/dxai-(?:on|off)-[a-z0-9_-]+/i', $markup );
	$aria     = (int) preg_match_all( '/aria-expanded=/i', $markup );
	$css_aria = (int) preg_match_all( '/\[aria-(?:expanded|selected|pressed|current)/i', $css );

	printf(
		"%-26s %8d %8d %8d %8d %8d\n",
		substr( $name, 0, 26 ),
		$handlers,
		$triggers,
		$targets,
		$aria,
		$css_aria
	);

	$totals['handlers'] += $handlers;
	$totals['triggers'] += $triggers;
	$totals['targets']  += $targets;
	$totals['aria']     += $aria;
	$totals['css_aria'] += $css_aria;

	foreach ( dxai_state_classes( $css ) as $class => $n ) {
		$states[ $class ] = ( $states[ $class ] ?? 0 ) + $n;
	}
}

printf(
	"\n%-26s %8d %8d %8d %8d %8d\n",
	'TOTAL',
	$totals['handlers'],
	$totals['triggers'],
	$totals['targets'],
	$totals['aria'],
	$totals['css_aria']
);

/*
 * The ratio that matters. A trigger with no target is a control that responds
 * to nothing, which is exactly what the behaviour probe reports as inert.
 */
if ( $totals['triggers'] > 0 ) {
	printf(
		"\ntargets per trigger: %.2f  (1.00 would mean every control has something to change)\n",
		$totals['targets'] / $totals['triggers']
	);
}

if ( $totals['aria'] > 0 && $totals['css_aria'] === 0 ) {
	printf(
		"\n%d aria-* state attributes emitted, and NOT ONE design selects on aria-*.\n",
		$totals['aria']
	);
	echo "Correct for assistive technology; invisible to the design. Not a projection.\n";
}

echo "\n=== state classes the designs' own CSS selects on ===\n";
arsort( $states );
$i = 0;
foreach ( $states as $class => $n ) {
	printf( "  .%-28s %5d selector(s)\n", $class, $n );
	if ( ++$i >= 20 ) {
		printf( "  … %d more\n", count( $states ) - $i );
		break;
	}
}

echo "\nhandlers  handler props the designs' own sources declare\n";
echo "triggers  dxai-toggle-* markers emitted (a control the runtime can bind)\n";
echo "targets   dxai-on-*/dxai-off-* markers (an element the runtime can change)\n";
echo "aria      aria-expanded attributes emitted\n";
echo "cssAria   selectors in the DESIGN's css keying on aria-* — if 0, ours are inert\n";

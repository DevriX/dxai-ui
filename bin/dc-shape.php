<?php
/**
 * What a Claude Design (.dc.html) export loses in conversion.
 *
 * The two real exports on hand ("Arcus", "H2O Away") are client work that
 * lives outside the repository, so nothing in the suite would cover the
 * shape once they are gone. fixtures/dc-minimal is a small page in the same
 * form — `<x-dc>` template with `{{ }}` bindings, `<sc-if>`/`<sc-for>`,
 * inline styles, a `<helmet>`, and a `class Component extends DCLogic`
 * whose `renderVals()` computes every style string; it carries the real
 * `support.js` runtime so bin/dc-oracle.cjs can render it as the design
 * side. This check packs it into the ZIP the rest of the suite imports
 * (`--zip-only` stops there) and then asserts the pieces the geometry gate
 * cannot see: that the interpreter reads the class the way the runtime
 * does, that the renderer publishes the runtime markers, and that the
 * connector → compiler wiring produces the structures.
 *
 *   bin/wp-php.sh bin/dc-shape.php [--zip-only]
 *
 * Exits 1 on any failed assertion.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

require __DIR__ . '/wp-boot.php';

use DXAI_UI\Compiler\Dc_Js_Element;
use DXAI_UI\Compiler\Dc_Renderer;
use DXAI_UI\Compiler\Dc_Script;
use DXAI_UI\Compiler\Source_Compiler;
use DXAI_UI\Connectors\Dc_Connector;

$root     = dirname( __DIR__ );
$project  = $root . '/fixtures/dc-minimal';
$page     = $project . '/Ledger Close.dc.html';
$zip_path = $root . '/.verify/dc-minimal.zip';
$zip_only = in_array( '--zip-only', $argv, true );

/** Pack the fixture the way a Claude Design export is packed: flat, forward slashes. */
function dxai_pack_dc_fixture( string $dir, string $out ): int {
	wp_mkdir_p( dirname( $out ) );
	if ( file_exists( $out ) ) {
		unlink( $out );
	}
	$zip = new ZipArchive();
	if ( $zip->open( $out, ZipArchive::CREATE ) !== true ) {
		fwrite( STDERR, "dc-shape: could not create {$out}\n" );
		exit( 2 );
	}
	$count    = 0;
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
			continue;
		}
		$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $dir ) + 1 ) );
		$zip->addFile( $file->getPathname(), $relative );
		++$count;
	}
	$zip->close();

	return $count;
}

$packed = dxai_pack_dc_fixture( $project, $zip_path );
echo 'zip=' . $zip_path . ' files=' . $packed . "\n";
if ( $zip_only ) {
	exit( 0 );
}

$failures = array();
$check    = static function ( bool $ok, string $what ) use ( &$failures ): void {
	if ( ! $ok ) {
		$failures[] = $what;
		echo 'FAIL ', $what, "\n";
	}
};

/* ------------------------------------------------------------ 1. split */
$html  = (string) file_get_contents( $page );
$parts = Dc_Connector::split( $html );
$check( $parts['template'] !== '' && str_contains( $parts['template'], '<header' ), 'split: template found' );
$check( str_contains( $parts['title'], 'Northwind Ledger' ), 'split: title from helmet' );
$check( str_contains( $parts['style'], 'box-sizing' ), 'split: helmet style' );
$check( count( $parts['links'] ) >= 1, 'split: helmet links' );
$check( str_contains( $parts['script'], 'renderVals' ), 'split: logic script' );
$check( Dc_Connector::is_dc_zip( array( 'x/Ledger Close.dc.html', 'support.js' ) ) && ! Dc_Connector::is_dc_zip( array( 'src/routes/index.tsx' ) ), 'detection by entry list' );

/* ------------------------------------------------------- 2. interpreter */
$logic = new Dc_Script( $parts['script'], $parts['props'] );
$state = $logic->initial_state();
$check( ( $state['day'] ?? null ) === 1 && ( $state['openFaq'] ?? null ) === 0 && ( $state['isMobile'] ?? null ) === false && array_key_exists( 'dropdown', $state ) && $state['dropdown'] === null, 'initial state' );
$vals = $logic->render_vals( $state );
$check( count( $vals ) >= 50, 'renderVals: ' . count( $vals ) . ' keys' );
$check( ( $vals['navHeight'] ?? '' ) === '84px' && ( $vals['dayLabel'] ?? '' ) === 'Day 1 of 3' && ( $vals['dayWidth'] ?? '' ) === '33%', 'renderVals: ternaries and concatenation' );
$check( ( $vals['isDesktop'] ?? false ) === true && ( $vals['productOpen'] ?? true ) === false && ( $vals['notSubmitted'] ?? false ) === true, 'renderVals: booleans' );
$check( is_string( $vals['dropItemLast'] ?? null ) && ! str_contains( (string) $vals['dropItemLast'], 'border-bottom' ) && str_contains( (string) $vals['dropItem'], 'border-bottom' ), 'renderVals: String.replace' );
$check( isset( $vals['faqs'] ) && is_array( $vals['faqs'] ) && count( $vals['faqs'] ) === 3 && ( $vals['faqs'][0]['sign'] ?? '' ) === '−' && ( $vals['faqs'][1]['sign'] ?? '' ) === '+', 'renderVals: map with index and per-item ternaries' );
$check( ( $vals['iconCheck'] ?? null ) instanceof Dc_Js_Element && str_starts_with( $vals['iconCheck']->to_html(), '<svg' ) && substr_count( $vals['iconArrow']->to_html(), '<path' ) === 2, 'renderVals: React.createElement icons' );
$check( str_contains( (string) ( $vals['tab1Style'] ?? '' ), 'background:#2f6b50' ) && str_contains( (string) ( $vals['tab2Style'] ?? '' ), 'background:#fff' ), 'renderVals: local arrow helper' );
$mobile = $logic->render_vals( array_merge( $state, array( 'isMobile' => true ) ) );
$check( ( $mobile['navHeight'] ?? '' ) === '64px' && ( $mobile['isDesktop'] ?? true ) === false, 'renderVals under the mobile state' );

$effects = $logic->mount_effects();
$check( ( $effects['media']['isMobile'] ?? '' ) === '(max-width: 900px)', 'mount: matchMedia key and query' );
$check( ( $effects['scroll']['scrolled'] ?? null ) === 24, 'mount: scrollY threshold' );
$check( in_array( 'dropdown', $effects['outside'], true ), 'mount: document click resets dropdown' );
$check( ( $effects['open'][0]['selector'] ?? '' ) === 'details[data-faq-first]' && ( $effects['open'][0]['attr'] ?? '' ) === 'open', 'mount: querySelector(...).open = true' );

$patch = static fn( string $key ) => $logic->patches( $vals[ $key ] );
$check( ( $patch( 'next' )['day'][0]['step'] ?? 0 ) === 1 && ( $patch( 'next' )['day'][0]['bound'] ?? 0 ) === 3, 'patches: Math.min step' );
$check( ( $patch( 'back' )['day'][0]['step'] ?? 0 ) === -1, 'patches: Math.max step' );
$check( ! empty( $patch( 'toggleMenu' )['menuOpen'][0]['flip'] ), 'patches: !s.flag flip' );
$product = $patch( 'onProductClick' )['dropdown'] ?? array();
$check( count( $product ) === 2 && in_array( null, array_column( $product, 'set' ), true ) && in_array( 'product', array_column( $product, 'set' ), true ), 'patches: ternary toggle to value' );
$faq1 = $logic->patches( $vals['faqs'][1]['toggle'] )['openFaq'] ?? array();
$check( in_array( 1, array_column( $faq1, 'set' ), true ) && in_array( -1, array_column( $faq1, 'set' ), true ), 'patches: closure index from map' );
$check( ( $patch( 'submit' )['submitted'][0]['set'] ?? null ) === true, 'patches: literal true' );
$check( $logic->unevaluated() === array() || $logic->unevaluated() === array( 'unbound e' ), 'interpreter: nothing unevaluated (' . implode( ', ', $logic->unevaluated() ) . ')' );

/* ----------------------------------------------------------- 3. render */
$renderer = new Dc_Renderer( $logic );
$out      = $renderer->render( $parts['template'] );
$body     = $out['html'];
$css      = $out['css'];
$count    = static fn( string $needle, string $in ) => substr_count( $in, $needle );

$check( ! str_contains( $body, '{{' ) && ! str_contains( $body, '<sc-' ) && ! str_contains( $body, 'onClick' ) && ! str_contains( $body, 'hint-placeholder' ), 'render: no template syntax left' );
$check( $renderer->breakpoint() === 900, 'render: breakpoint 900' );
$check( $count( 'dxai-dc-desktop', $body ) >= 1 && $count( 'dxai-dc-mobile', $body ) >= 3, 'render: media-gated branches' );
$check( str_contains( $css, '@media (max-width: 900px)' ) && str_contains( $css, '@media (min-width: 901px)' ), 'render: media queries at the breakpoint' );
$check( $count( 'dxai-toggle-', $body ) >= 14, 'render: toggles (' . $count( 'dxai-toggle-', $body ) . ')' );
$check( str_contains( $body, 'dxai-on-dropdown--product' ) && str_contains( $body, 'dxai-on-dropdown--pricing' ), 'render: dropdown panels' );
$check( str_contains( $body, 'dxai-toggle-dropdown--product' ) && str_contains( $body, 'data-dxai-mode="toggle"' ), 'render: dropdown trigger toggles its own value' );
$check( str_contains( $body, 'dxai-on-day--2' ) && str_contains( $body, 'dxai-on-day--3' ) && preg_match( '/class="[^"]*dxai-on-day--2[^"]*hidden/', $body ) === 1, 'render: day panels, 2 and 3 hidden at rest' );
$check( str_contains( $body, 'data-dxai-step="1"' ) && str_contains( $body, 'data-dxai-step="-1"' ) && str_contains( $body, 'data-dxai-clamp="1"' ), 'render: step triggers with clamp' );
$check( str_contains( $body, 'data-dxai-init="1"' ) && str_contains( $body, 'data-dxai-init="0"' ), 'render: initial values for day and openFaq' );
$check( str_contains( $body, 'dxai-toggle-openFaq--1' ) && str_contains( $body, 'dxai-cls-openFaq--1' ), 'render: FAQ toggle and per-item class projections' );
$check( preg_match( '/data-dxai-text-day="[^"]*Day 2 of 3/', $body ) === 1, 'render: text map for dayLabel' );
$check( preg_match( '/data-dxai-text-entitiesExpanded="[^"]*Show fewer/', $body ) === 1, 'render: text map for the show-more label' );
$check( str_contains( $body, 'dxai-on-entitiesExpanded' ) && str_contains( $body, 'Fabrikam' ), 'render: hidden extra entities rendered' );
$check( str_contains( $body, 'dxai-on-submitted' ) && str_contains( $body, 'dxai-off-submitted' ), 'render: submitted / not submitted branches' );
$check( str_contains( $body, 'dxai-cls-scrolled' ) && str_contains( $css, 'box-shadow:0 4px 20px' ), 'render: scrolled header projection' );
$check( preg_match( '/class="dxai-sh-\d+"/', $body ) === 1 && str_contains( $css, ':hover {' ) && str_contains( $css, '!important' ), 'render: style-hover to a class' );
$check( $count( '<svg', $body ) >= 3 && $count( '<path', $body ) >= 4, 'render: inline SVG icons' );
$check( $count( 'class="sc-interp"', $body ) + $count( 'class="sc-interp" data-', $body ) >= 12, 'render: sc-interp spans' );
$check( preg_match( '/<textarea[^>]*>\s*<\/textarea>/', $body ) === 1 && ! preg_match( '/<textarea[^>]*>\s*<span/', $body ), 'render: textarea keeps plain text' );
$check( str_contains( $body, 'value=""' ), 'render: controlled input value' );
$check( preg_match( '/<details[^>]*open=""/', $body ) === 1, 'render: mount-time open attribute' );
$check( ( $out['root_attrs']['data-dxai-outside'] ?? '' ) === 'dropdown' && ( $out['root_attrs']['data-dxai-scroll'] ?? '' ) === 'scrolled:24', 'render: root markers' );
$check( ! str_contains( $body, 'required=""' ) && str_contains( $body, 'aria-label="Open menu"' ), 'render: attributes' );

/* --------------------------------------------- 4. connector → compiler */
$doc = ( new Dc_Connector() )->import_zip( array( 'tmp_name' => $zip_path, 'name' => basename( $zip_path ) ) );
if ( is_wp_error( $doc ) ) {
	$check( false, 'connector: ' . $doc->get_error_message() );
} else {
	$check( $doc->kind === 'dc-zip' && ! empty( $doc->payload['dc']['template'] ) && ! empty( $doc->payload['static_html'] ), 'connector: payload' );
	$check( str_contains( (string) ( $doc->payload['dc']['template'] ?? '' ), 'wp-content/uploads' ), 'connector: asset URLs rewritten' );
	$result = ( new Source_Compiler() )->compile( $doc );
	$types  = array_map( static fn( $s ) => (string) ( $s['type'] ?? '' ), $result['structures'] ?? array() );
	$check( in_array( 'header', $types, true ) && in_array( 'footer', $types, true ) && count( $types ) >= 6, 'compiler: structures ' . implode( ',', $types ) );
	$check( ! empty( $result['static_html'] ), 'compiler: static_html flag' );
	$check( str_contains( (string) ( $result['custom_css'] ?? '' ), '@media (max-width: 900px)' ), 'compiler: renderer CSS carried' );
	$check( ! str_contains( (string) ( $result['gutenberg_markup'] ?? '' ), '{{' ), 'compiler: no template syntax in blocks' );
	$check( str_contains( (string) ( $result['gutenberg_markup'] ?? '' ), 'dxai-toggle-' ), 'compiler: runtime markers survive block conversion' );
	$check( str_contains( (string) ( $result['custom_js'] ?? '' ), 'dxai-toggle-' ) || str_contains( (string) ( $result['custom_js'] ?? '' ), 'projections' ), 'compiler: front-end runtime attached' );
	$check( count( $result['unevaluated'] ?? array() ) <= 1, 'compiler: unevaluated ' . wp_json_encode( $result['unevaluated'] ?? array() ) );
}

echo 'dc-shape: vals=', count( $vals ), ' toggles=', $count( 'dxai-toggle-', $body ), ' media_branches=', $count( 'dxai-dc-mobile', $body ) + $count( 'dxai-dc-desktop', $body ), ' text_maps=', $count( 'data-dxai-text-', $body ), ' css_bytes=', strlen( $css ), "\n";
if ( $failures !== array() ) {
	echo 'dc-shape: FAIL (', count( $failures ), ")\n";
	exit( 1 );
}
echo "dc-shape: ok\n";

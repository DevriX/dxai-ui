<?php
/**
 * Unpack a Lovable ZIP and verify page/chunk selection without an LLM.
 * Run: wp eval-file bin/verify-lovable-zip.php
 *
 * @package DXAI_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require DXAI_UI_DIR . 'bin/verify-cleanup.php';
$dxai_snapshot = dxai_verify_snapshot();

/**
 * Every "name=ok" / "name=fail" line this check prints is one check: count them, clean up, and exit by the pack's protocol.
 *
 * @param array<int, int> $snapshot The posts that were there before (bin/verify-cleanup.php).
 */
function dxai_lovable_finish( array $snapshot ): void {
	$out = (string) ob_get_clean();
	echo $out;
	dxai_verify_cleanup( $snapshot );
	$pass = preg_match_all( '/^[a-z_]+=ok\b/m', $out );
	$fail = preg_match_all( '/^[a-z_]+=fail\b/m', $out );
	echo "\n$pass passed, $fail failed\n";
	exit( $fail > 0 ? 1 : 0 );
}
ob_start();

/*
 * Where the design ZIPs live is a fact about the checkout. `DXAI_DESIGN_ZIPS`
 * names a directory to look in; `_inbox/` is the in-repo convention. Two
 * absolute Downloads paths used to be hard-coded here, which is why this suite
 * reported "zip not found" on every machine but one.
 */
$candidates = array( DXAI_UI_DIR . '_inbox/gtm-strategy-hub.zip' );
$zip_dir    = getenv( 'DXAI_DESIGN_ZIPS' );
if ( is_string( $zip_dir ) && $zip_dir !== '' ) {
	$zip_dir = rtrim( str_replace( '\\', '/', $zip_dir ), '/' );
	array_unshift(
		$candidates,
		$zip_dir . '/GTM Strategy Hub.zip',
		$zip_dir . '/gtm-strategy-hub.zip'
	);
}

$zip_path = '';
foreach ( $candidates as $candidate ) {
	if ( is_readable( $candidate ) ) {
		$zip_path = $candidate;
		break;
	}
}

$json = DXAI_UI\Compiler\Json_Repair::decode( "{\"block_title\":\"Hero\", \"gutenberg_markup\":\"<!-- wp:group -->\\n<div></div>\\n<!-- /wp:group -->\",}" );
echo 'json_repair=' . ( is_array( $json ) && ( $json['block_title'] ?? '' ) === 'Hero' ? 'ok' : 'fail' ) . PHP_EOL;

$truncated = '{"block_title":"Hero","gutenberg_markup":"<!-- wp:heading --><h2 class=\"rv-h2\">Hi</h2><!-- /wp:heading -->"';
$fields    = DXAI_UI\Compiler\Json_Repair::decode( $truncated );
echo 'json_truncated=' . ( is_array( $fields ) && str_contains( (string) ( $fields['gutenberg_markup'] ?? '' ), 'wp:heading' ) ? 'ok' : 'fail' ) . PHP_EOL;

$tsx = <<<'TSX'
const ITEMS = [{ title: "One" }];
function Header() {
  return <header className="rv-header"><nav>Home</nav></header>;
}
function Hero() {
  return <section className="rv-hero">{ITEMS.map((i) => <h1 key={i.title}>{i.title}</h1>)}</section>;
}
function GtmStrategyPage() {
  return (
    <div className="rv-root">
      <Header />
      <Hero />
      <Problem />
      <Cost />
    </div>
  );
}
TSX;

$sections = DXAI_UI\Compiler\Tsx_Section_Splitter::sections( $tsx );
$names    = array_map( static fn( array $row ): string => (string) $row['title'], $sections );
echo 'split_names=' . implode( ',', $names ) . PHP_EOL;
echo 'split_skips_composer=' . ( in_array( 'GtmStrategyPage', $names, true ) ? 'fail' : 'ok' ) . PHP_EOL;
echo 'split_has_hero=' . ( in_array( 'Hero', $names, true ) ? 'ok' : 'fail' ) . PHP_EOL;

$css = ':root { --rv-green: #00ff9c; } .rv-header { color: white; } .ignore { display:none; }';
$excerpt = DXAI_UI\Compiler\Design_Css::excerpt( $css );
echo 'design_css=' . ( str_contains( $excerpt, '.rv-header' ) && str_contains( $excerpt, '--rv-green' ) ? 'ok' : 'fail' ) . PHP_EOL;

if ( $zip_path === '' ) {
	// The archive half did not run: said here, and not counted as passed.
	echo "zip=not here (GTM Strategy Hub.zip is not on this machine: its four checks did not run)\n";
	dxai_lovable_finish( $dxai_snapshot );
}

$file = array(
	'tmp_name' => $zip_path,
	'name'     => 'gtm-strategy-hub.zip',
);
$doc = ( new DXAI_UI\Connectors\Lovable_Connector() )->import_zip( $file );
if ( is_wp_error( $doc ) ) {
	echo 'zip_read=fail (' . $doc->get_error_message() . ')' . PHP_EOL;
	dxai_lovable_finish( $dxai_snapshot );
}

$files = array_keys( $doc->payload['components'] ?? array() );
$joined = implode( ' ', $files );
echo 'zip_kind=' . $doc->kind . PHP_EOL;
echo 'zip_pages=' . count( $doc->payload['pages'] ?? array() ) . PHP_EOL;
echo 'zip_chunks=' . count( $doc->payload['chunks'] ?? array() ) . PHP_EOL;
echo 'zip_has_index=' . ( str_contains( $joined, 'routes/index.tsx' ) || str_contains( $joined, 'routes\\index.tsx' ) ? 'ok' : 'fail' ) . PHP_EOL;
echo 'zip_skips_ui=' . ( str_contains( $joined, 'components/ui' ) ? 'fail' : 'ok' ) . PHP_EOL;
echo 'zip_design_css=' . ( str_contains( (string) ( $doc->payload['design_css'] ?? '' ), '.rv-header' ) ? 'ok' : 'fail' ) . PHP_EOL;
echo 'zip_tokens=' . ( ! empty( $doc->payload['harvest']['tokens']['variables'] ) ? 'ok' : 'fail' ) . PHP_EOL;

dxai_lovable_finish( $dxai_snapshot );

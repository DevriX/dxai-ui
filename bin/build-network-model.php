<?php
/**
 * The network model the plugin ships (data/network-model.json), learned from the research's crawl of the team's sites.
 *
 *   wp eval-file bin/build-network-model.php [<crawl folder>]      (default: .verify/network-research/data)
 *
 * Reads the sites crawl.cjs saved (Site_Source::folder), learns the model (Network_Model::learn) and writes what can be
 * shipped in a public repository (Network_Model::shareable): no addresses and nothing site by site, the formulas and
 * questions only when three sites or more use them. A site of a network learns its own with `wp dxai-ui network learn`,
 * which wins over this one.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Network\Network_Model;
use DXAI_UI\Network\Site_Source;

$dir = (string) ( $args[0] ?? dirname( __DIR__ ) . '/.verify/network-research/data' );
if ( ! is_dir( $dir ) ) {
	echo "No crawl at $dir\n";
	exit( 1 );
}
$sites = array();
foreach ( Site_Source::folder( $dir ) as $src ) {
	$sites[] = Network_Model::read_site( $src );
}
$model = Network_Model::shareable( Network_Model::learn( $sites ) );
$json  = (string) wp_json_encode( $model, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
// Nothing that names a site: the check is on the written file, not on what was meant to be left out.
if ( preg_match( '#https?://|wpengine|\.com\b|\.net\b#i', $json ) === 1 ) {
	echo "The model names a site; not written.\n";
	exit( 1 );
}
file_put_contents( dirname( __DIR__ ) . '/' . Network_Model::SHIPPED, $json . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
printf( "%s: %d sites, %d bytes\n", Network_Model::SHIPPED, (int) $model['sites'], strlen( $json ) );

<?php
/**
 * Pack the tracked fixtures into the archives the suites import.
 *
 * `fixtures/dc-minimal` (a small Claude Design export kept in git) becomes
 * `.verify/dc-minimal.zip`, which verify-design-document, verify-interactivity,
 * verify-style-variation and the browser checks import. `.verify/` is not in
 * git, so a fresh checkout — CI — has no archive until this runs; the suite
 * runner runs it first:
 *
 *     wp eval-file bin/pack-fixtures.php
 *
 * bin/dc-shape.php packs the same way and takes the function from here.
 *
 * @package DX_UI
 */

if ( ! function_exists( 'dxui_pack_dc_fixture' ) ) {
	/**
	 * Pack a fixture folder the way a Claude Design export is packed: flat, forward slashes.
	 *
	 * @param string $dir The fixture folder.
	 * @param string $out The archive to write (replaced when it exists).
	 * @return int The number of files packed, or -1 when the archive could not be written.
	 */
	function dxui_pack_dc_fixture( string $dir, string $out ): int {
		if ( ! is_dir( dirname( $out ) ) && ! mkdir( dirname( $out ), 0777, true ) && ! is_dir( dirname( $out ) ) ) {
			return -1;
		}
		if ( file_exists( $out ) ) {
			unlink( $out );
		}
		$zip = new ZipArchive();
		if ( $zip->open( $out, ZipArchive::CREATE ) !== true ) {
			return -1;
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
}

// Required by another script for the function only: nothing to run.
if ( defined( 'DXUI_PACK_FIXTURES_LIBRARY' ) ) {
	return;
}

$dxui_root    = dirname( __DIR__ );
$dxui_fixture = $dxui_root . '/fixtures/dc-minimal';
$dxui_zip     = $dxui_root . '/.verify/dc-minimal.zip';
if ( ! is_dir( $dxui_fixture ) ) {
	echo "pack-fixtures: no fixtures/dc-minimal in {$dxui_root}\n";
	exit( 1 );
}
$dxui_packed = dxui_pack_dc_fixture( $dxui_fixture, $dxui_zip );
if ( $dxui_packed < 1 ) {
	echo "pack-fixtures: could not write {$dxui_zip}\n";
	exit( 1 );
}
echo "packed fixtures/dc-minimal -> .verify/dc-minimal.zip ({$dxui_packed} files)\n";

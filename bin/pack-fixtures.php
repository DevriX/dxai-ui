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
 * @package DXAI_UI
 */

if ( ! function_exists( 'dxai_pack_dc_fixture' ) ) {
	/**
	 * Pack a fixture folder the way a Claude Design export is packed: flat, forward slashes.
	 *
	 * @param string $dir The fixture folder.
	 * @param string $out The archive to write (replaced when it exists).
	 * @return int The number of files packed, or -1 when the archive could not be written.
	 */
	function dxai_pack_dc_fixture( string $dir, string $out ): int {
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
if ( defined( 'DXAI_PACK_FIXTURES_LIBRARY' ) ) {
	return;
}

$dxai_root    = dirname( __DIR__ );
$dxai_fixture = $dxai_root . '/fixtures/dc-minimal';
$dxai_zip     = $dxai_root . '/.verify/dc-minimal.zip';
if ( ! is_dir( $dxai_fixture ) ) {
	echo "pack-fixtures: no fixtures/dc-minimal in {$dxai_root}\n";
	exit( 1 );
}
$dxai_packed = dxai_pack_dc_fixture( $dxai_fixture, $dxai_zip );
if ( $dxai_packed < 1 ) {
	echo "pack-fixtures: could not write {$dxai_zip}\n";
	exit( 1 );
}
echo "packed fixtures/dc-minimal -> .verify/dc-minimal.zip ({$dxai_packed} files)\n";

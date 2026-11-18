<?php
/**
 * Zip a release snapshot and check the result — called by bin/release.cjs.
 *
 *   php -c <ini with zip> bin/release-zip.php <snapshot dir (…/dx-ui)> <out.zip> <version>
 *
 * Entry names are forward-slash paths under one `dx-ui/` root. After writing, the ZIP is opened again and every
 * entry is checked: no backslash, no absolute or `..` path, nothing outside `dx-ui/`, no development folder, and
 * the main file carries the version. Exit 1 on any failure.
 *
 * @package DX_UI
 */

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "the zip extension is not loaded (pass -c with an ini that enables it)\n" );
	exit( 1 );
}
list( , $root, $out, $version ) = array_pad( $argv, 4, '' );
if ( $root === '' || $out === '' || $version === '' || ! is_dir( $root ) ) {
	fwrite( STDERR, "usage: php bin/release-zip.php <snapshot dir> <out.zip> <version>\n" );
	exit( 1 );
}
$root = rtrim( str_replace( '\\', '/', realpath( $root ) ), '/' );

if ( file_exists( $out ) ) {
	unlink( $out );
}
$zip = new ZipArchive();
if ( true !== $zip->open( $out, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "cannot create $out\n" );
	exit( 1 );
}
$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
$files = 0;
$zip->addEmptyDir( 'dx-ui' );
foreach ( $it as $file ) {
	$rel = 'dx-ui/' . str_replace( '\\', '/', substr( str_replace( '\\', '/', $file->getPathname() ), strlen( $root ) + 1 ) );
	if ( $file->isDir() ) {
		$zip->addEmptyDir( $rel );
	} else {
		$zip->addFile( $file->getPathname(), $rel );
		++$files;
	}
}
$zip->close();

// Read it back the way an installer would.
$bad   = array();
$check = new ZipArchive();
if ( true !== $check->open( $out ) ) {
	fwrite( STDERR, "cannot reopen $out\n" );
	exit( 1 );
}
$main = '';
for ( $i = 0; $i < $check->numFiles; $i++ ) {
	$name = (string) $check->getNameIndex( $i );
	if ( strpos( $name, '\\' ) !== false ) {
		$bad[] = 'backslash in entry name: ' . $name;
	} elseif ( $name[0] === '/' || preg_match( '#(^|/)\.\.(/|$)#', $name ) ) {
		$bad[] = 'unsafe entry name: ' . $name;
	} elseif ( strpos( $name, 'dx-ui/' ) !== 0 ) {
		$bad[] = 'entry outside dx-ui/: ' . $name;
	} elseif ( preg_match( '#^dx-ui/(assets/src|bin|fixtures|tests|node_modules|\.verify|\.cursor|\.git)(/|$)#', $name ) ) {
		$bad[] = 'development file shipped: ' . $name;
	}
	if ( $name === 'dx-ui/dx-ui.php' ) {
		$main = (string) $check->getFromIndex( $i );
	}
}
$check->close();
if ( $main === '' ) {
	$bad[] = 'dx-ui/dx-ui.php is missing';
} elseif ( strpos( $main, "define( 'DX_UI_VERSION', '" . $version . "' );" ) === false ) {
	$bad[] = 'dx-ui/dx-ui.php does not carry version ' . $version;
}
if ( $bad !== array() ) {
	fwrite( STDERR, "ZIP check failed:\n - " . implode( "\n - ", array_slice( $bad, 0, 20 ) ) . "\n" );
	exit( 1 );
}
echo "zip: $out\nfiles: $files\nbytes: " . filesize( $out ) . "\nsha256: " . hash_file( 'sha256', $out ) . "\nzip check: every entry under dx-ui/, forward slashes, no dev files\n";

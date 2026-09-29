<?php
/**
 * ZIP unpacker: entry by entry, allow-listed, into a private work folder.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Connectors;

use DXAI_UI\Support\Upload_Paths;

/**
 * Unpacks a design archive so a connector can read it, and nothing more.
 *
 * It used to hand the whole archive to core unzip_file() inside
 * uploads/dxai-ui/tmp-XXXXXXXX and only afterwards walk the tree deleting
 * what it did not want. On a real host that went wrong four ways:
 *
 *  - every entry reached disk first, in a folder the web server serves: a
 *    `.php` was deleted a moment after it was written, and the design's
 *    `.html`, `.js` and `.svg` stayed reachable by URL for the whole request;
 *  - unzip_file() needs WP_Filesystem(), which cannot connect from a REST
 *    request on a host whose filesystem method is not 'direct' (PHP runs as
 *    another user than the one owning the files, and no FTP credentials are
 *    in wp-config.php) - so every import failed there, although plain PHP
 *    could write the temp folder, and uploads, perfectly well;
 *  - nothing removed the folder when the request died: this install still
 *    held tmp-mNRIxqHI (91 files, 5 of them .html) from 16 September and
 *    tmp-iqVskqxB from the 18th;
 *  - an upload over upload_max_filesize arrives with an error code and no
 *    file, and was reported as "No ZIP file was uploaded."
 *
 * Now every entry name is checked before anything is written, and only the
 * allow-listed entries are streamed out, with the byte limits counted on the
 * real decompressed stream, into a folder under the system temp directory -
 * which the web server does not serve - made by PHP with mode 0700. The
 * folder goes when the connector calls cleanup(), again at shutdown if it
 * did not, and at the next import if the process was killed before either.
 * Creating and deleting both use plain PHP file functions, so the owner that
 * made the files is always the one removing them.
 */
final class Zip_Extractor {

	private const ALLOWED_EXTENSIONS = array(
		'html',
		'css',
		'js',
		'jsx',
		'ts',
		'tsx',
		'svg',
		'png',
		'jpg',
		'jpeg',
		'webp',
		'gif',
		'avif',
		'ico',
		'bmp',
		'json',
		'md',
		'txt',
		'scss',
		'map',
		'vue',
		'mjs',
		'cjs',
		'less',
		'mp4',
		'webm',
		'ogv',
		'mov',
		'm4v',
		'mp3',
		'wav',
		'ogg',
		'm4a',
		'woff',
		'woff2',
		'ttf',
		'otf',
		'eot',
		'webmanifest',
	);

	/**
	 * Entries that refuse the whole archive instead of being skipped: nothing
	 * in a design export is server-side code, so an archive carrying some is
	 * not one - the same list, and the same refusal, the unpacker always had.
	 */
	private const BLOCKED_EXTENSIONS = array( 'php', 'phtml', 'phar', 'exe', 'bat', 'cmd', 'sh', 'dll', 'so' );

	/**
	 * Folders that hold other people's code or this design's build output,
	 * skipped as they always were - plus `__MACOSX/`, which core unzip_file()
	 * skipped for us: the AppleDouble `._logo.png` files in it are binary
	 * resource forks with image extensions, and would have been sideloaded
	 * as broken images.
	 */
	private const SKIPPED_DIRS = '#(^|/)(node_modules|\.next|dist|vendor|coverage|__MACOSX)(/|$)#';

	/**
	 * Default ceilings, each filterable through `dxai_ui_zip_limits`.
	 *
	 * Measured on the 20 archives of the local corpus: at most 106 entries,
	 * the largest single file 10.9 MB (a photo), the largest archive 82.7 MB
	 * unpacked, and no entry compressed better than 5.6 : 1. The defaults sit
	 * one to two orders of magnitude above that, so they only ever stop an
	 * archive that is not a design export - a zip bomb, or a project folder
	 * zipped together with its node_modules.
	 *
	 *  - entries:     entries in the archive, counted before any is read;
	 *  - file_bytes:  one file, counted on the decompressed stream (100 MB);
	 *  - total_bytes: everything written for one import (512 MB).
	 */
	private const LIMITS = array(
		'entries'     => 20000,
		'file_bytes'  => 104857600,
		'total_bytes' => 536870912,
	);

	/**
	 * A killed request's folder is swept by the next import once it is this
	 * old (24 hours). Nothing that is still running is older: web requests
	 * here are killed after 60 to 120 seconds.
	 */
	private const STALE_AFTER = 86400;

	/** Work folder names under the temp directory: the prefix plus 16 hex digits. */
	private const WORK_PREFIX = 'dxai-ui-tmp-';

	/**
	 * Work folder names in uploads/dxai-ui, the fallback. `tmp-` plus eight
	 * letters and digits is what the old unpacker named them, so the sweep
	 * matches that too and clears what it left behind.
	 */
	private const FALLBACK_PREFIX = 'tmp-';

	/** Denies web access to a fallback work folder on Apache and LiteSpeed. */
	private const DENY_RULES = "# DXAI-UI import work folder: private, removed when the request ends.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";

	/**
	 * Work folders this request made and has not removed yet.
	 *
	 * @var array<string, true>
	 */
	private static array $work = array();

	private static bool $shutdown_registered = false;

	/**
	 * The entry names of an archive, without extracting it — what a
	 * connector choice is made on.
	 *
	 * An empty list for an archive that cannot be read; read_entries() says
	 * why, and is what Zip_Router asks.
	 *
	 * @return array<int, string>
	 */
	public static function entries( string $path ): array {
		$entries = self::read_entries( $path );

		return is_wp_error( $entries ) ? array() : array_column( $entries, 'name' );
	}

	/**
	 * Every entry of an archive, as its central directory describes it.
	 *
	 * Without the zip extension this lists through the PclZip that ships in
	 * wp-admin, rather than answering with an empty list: Zip_Router picked a
	 * connector from that empty list, so on such a host a React export went
	 * to the HTML connector and failed with a message about the archive's
	 * contents, for what was a fact about the server.
	 *
	 * PclZip's constructor calls die() when zlib is missing, which is why
	 * that is tested first. It hands back names it has already reduced
	 * (`a/./b` to `a/b`), which is also the name core writes through it, so
	 * the checks in plan() see what would reach disk. It exposes no external
	 * attributes, so symlinks cannot be told apart there - and cannot be made
	 * either: PclZip writes every entry as a regular file.
	 *
	 * @return array<int, array{index:int, name:string, size:int, link:bool, encrypted:bool}>|\WP_Error
	 */
	public static function read_entries( string $path ): array|\WP_Error {
		if ( '' === $path || ! is_readable( $path ) ) {
			return new \WP_Error( 'dxai_ui_zip', __( 'The uploaded ZIP file could not be read.', 'dxai-ui' ), array( 'status' => 400 ) );
		}
		// PHP 8 deprecates opening an empty file as an archive; it is not one.
		if ( 0 === (int) filesize( $path ) ) {
			return self::not_a_zip();
		}

		$limit = self::limits()['entries'];

		if ( class_exists( \ZipArchive::class ) ) {
			$zip    = new \ZipArchive();
			$opened = $zip->open( $path );
			if ( true !== $opened ) {
				return self::not_a_zip( (int) $opened );
			}
			$count = (int) $zip->numFiles;
			if ( $count > $limit ) {
				$zip->close();
				return self::too_many( $count, $limit );
			}

			$out = array();
			for ( $i = 0; $i < $count; $i++ ) {
				$stat = $zip->statIndex( $i );
				if ( ! is_array( $stat ) ) {
					$zip->close();
					return self::not_a_zip();
				}
				$opsys = 0;
				$attr  = 0;
				$zip->getExternalAttributesIndex( $i, $opsys, $attr );
				$out[] = array(
					'index'     => $i,
					'name'      => (string) $stat['name'],
					'size'      => (int) $stat['size'],
					// S_IFLNK in the high word of the attributes of an entry made
					// on Unix or macOS (both store a Unix mode there).
					'link'      => in_array( $opsys, array( \ZipArchive::OPSYS_UNIX, \ZipArchive::OPSYS_OS_X ), true ) && 0120000 === ( ( $attr >> 16 ) & 0170000 ),
					'encrypted' => ! empty( $stat['encryption_method'] ),
				);
			}
			$zip->close();

			return $out;
		}

		if ( ! function_exists( 'gzopen' ) ) {
			return new \WP_Error(
				'dxai_ui_zip',
				__( 'This server cannot read ZIP archives: PHP has neither the zip extension nor zlib, which the PclZip fallback in WordPress needs. Ask your host to enable the PHP zip extension.', 'dxai-ui' ),
				array( 'status' => 500 )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$list = ( new \PclZip( $path ) )->listContent();
		if ( ! is_array( $list ) ) {
			return self::not_a_zip();
		}
		if ( count( $list ) > $limit ) {
			return self::too_many( count( $list ), $limit );
		}

		$out = array();
		foreach ( $list as $item ) {
			$name  = (string) ( $item['filename'] ?? '' );
			$out[] = array(
				'index'     => (int) ( $item['index'] ?? 0 ),
				'name'      => ! empty( $item['folder'] ) ? rtrim( $name, '/' ) . '/' : $name,
				'size'      => (int) ( $item['size'] ?? 0 ),
				'link'      => false,
				'encrypted' => false,
			);
		}

		return $out;
	}

	/**
	 * What went wrong with an upload before it reached this class, in words.
	 *
	 * PHP reports an upload it refused through `$_FILES[…]['error']` and an
	 * empty `tmp_name`; reading only the name made a file over
	 * upload_max_filesize say "No ZIP file was uploaded." A body over
	 * post_max_size is worse: PHP drops `$_FILES` altogether, so pass null
	 * for the missing file and the request's Content-Length is compared with
	 * the limit instead.
	 *
	 * @param array<string, mixed>|null $file A `$_FILES` entry, or null when there was none.
	 */
	public static function upload_error( ?array $file ): ?\WP_Error {
		if ( null === $file || array() === $file ) {
			$length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
			$max    = (int) wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );

			return ( $max > 0 && $length > $max ) ? self::too_large( UPLOAD_ERR_INI_SIZE ) : null;
		}

		if ( is_array( $file['tmp_name'] ?? null ) || is_array( $file['error'] ?? null ) ) {
			return new \WP_Error( 'dxai_ui_zip', __( 'Upload one .zip file at a time.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		$code = (int) ( $file['error'] ?? UPLOAD_ERR_OK );

		return match ( $code ) {
			UPLOAD_ERR_OK         => null,
			UPLOAD_ERR_INI_SIZE,
			UPLOAD_ERR_FORM_SIZE  => self::too_large( $code ),
			UPLOAD_ERR_PARTIAL    => new \WP_Error( 'dxai_ui_zip', __( 'The ZIP file was only partly uploaded. Try again; if it keeps happening, the connection or a proxy is cutting large uploads short.', 'dxai-ui' ), array( 'status' => 400 ) ),
			UPLOAD_ERR_NO_FILE    => new \WP_Error( 'dxai_ui_zip', __( 'No ZIP file was uploaded.', 'dxai-ui' ), array( 'status' => 400 ) ),
			UPLOAD_ERR_NO_TMP_DIR => new \WP_Error( 'dxai_ui_zip', __( 'The server has no temporary folder for uploads (PHP upload_tmp_dir). Ask your host to fix it.', 'dxai-ui' ), array( 'status' => 500 ) ),
			UPLOAD_ERR_CANT_WRITE => new \WP_Error( 'dxai_ui_zip', __( 'The server could not write the uploaded ZIP file to disk. The disk may be full; ask your host.', 'dxai-ui' ), array( 'status' => 500 ) ),
			UPLOAD_ERR_EXTENSION  => new \WP_Error( 'dxai_ui_zip', __( 'A PHP extension on the server stopped the upload. Ask your host which one.', 'dxai-ui' ), array( 'status' => 500 ) ),
			/* translators: %d: PHP upload error code. */
			default               => new \WP_Error( 'dxai_ui_zip', sprintf( __( 'The upload failed (PHP upload error %d).', 'dxai-ui' ), $code ), array( 'status' => 400 ) ),
		};
	}

	/**
	 * @param array<string, mixed> $file $_FILES-style array.
	 * @return array{dir:string, files:array<int, string>}|\WP_Error
	 */
	public static function unpack( array $file ): array|\WP_Error {
		$upload_error = self::upload_error( $file );
		if ( null !== $upload_error ) {
			return $upload_error;
		}

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			if ( empty( $file['tmp_name'] ) || ! is_readable( (string) $file['tmp_name'] ) ) {
				return new \WP_Error( 'dxai_ui_zip', __( 'No ZIP file was uploaded.', 'dxai-ui' ), array( 'status' => 400 ) );
			}
		}

		$name = (string) ( $file['name'] ?? '' );
		if ( ! str_ends_with( strtolower( $name ), '.zip' ) ) {
			return new \WP_Error( 'dxai_ui_zip', __( 'Only .zip archives are accepted.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		$archive = (string) $file['tmp_name'];
		$limits  = self::limits();
		$entries = self::read_entries( $archive );
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}

		// Every refusal is decided here, from names and declared sizes, so a
		// refused archive never gets as far as a work folder.
		$plan = self::plan( $entries, $limits );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( array() === $plan ) {
			return self::nothing_supported();
		}

		self::sweep();

		$dir = self::work_dir( (float) array_sum( array_column( $plan, 'size' ) ) );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$files = class_exists( \ZipArchive::class )
			? self::extract_with_ziparchive( $archive, $plan, $dir, $limits )
			: self::extract_with_core( $archive, $plan, $dir, $limits );
		if ( is_wp_error( $files ) ) {
			self::cleanup( $dir );
			return $files;
		}
		if ( array() === $files ) {
			self::cleanup( $dir );
			return self::nothing_supported();
		}

		return array(
			'dir'   => $dir,
			'files' => self::sorted( $files ),
		);
	}

	/**
	 * Remove a work folder unpack() returned.
	 *
	 * Only a folder this class made is ever touched - one this request
	 * registered, or one carrying its name pattern - so a wrong argument
	 * cannot delete anything else. Plain unlink()/rmdir(), because PHP made
	 * the folder: wp_delete_file() runs the `wp_delete_file` filter, which
	 * media-offload plugins hook to delete what they take for a bucket copy.
	 */
	public static function cleanup( string $dir ): void {
		$dir = untrailingslashit( wp_normalize_path( $dir ) );
		if ( '' === $dir ) {
			return;
		}
		if ( ! isset( self::$work[ $dir ] ) && ! self::is_own_folder( $dir ) ) {
			return;
		}

		self::remove_tree( $dir );
		unset( self::$work[ $dir ] );
	}

	/**
	 * Read a text file, capped.
	 *
	 * When the cap bites, the read stops at the last newline rather than at an
	 * arbitrary byte, so the result never ends in the middle of a line. A
	 * stylesheet cut mid-rule left a dangling `.foo:hover span {` that the
	 * scoper then gave up on, and the page kept rendering with part of its CSS
	 * quietly missing.
	 */
	public static function read_text( string $path, int $max_bytes = 200000 ): string {
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$size = filesize( $path );
		if ( false === $size || $size < 1 ) {
			return '';
		}

		$contents = file_get_contents( $path, false, null, 0, min( $size, $max_bytes ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $contents ) ) {
			return '';
		}
		if ( $size <= $max_bytes ) {
			return $contents;
		}

		// Cut back to a line break only when one lies near the end: on a
		// minified single-line file the last newline can be its first, and
		// the cut would keep one line of a multi-megabyte sheet.
		$break = strrpos( $contents, "\n" );
		if ( false === $break || $break < (int) ( $max_bytes * 0.95 ) ) {
			return $contents;
		}

		return substr( $contents, 0, $break + 1 );
	}

	/**
	 * Which entries to write, under which relative path - or why the archive
	 * is refused.
	 *
	 * The allow-list semantics are the old post-extraction walk's, moved in
	 * front of extraction: skipped folders first, then a blocked extension
	 * refuses the archive, then anything with an extension off the list is
	 * left out (an extensionless file like LICENSE is kept, as before).
	 *
	 * What changed is `..`: the walk refused any name containing the two
	 * characters, which caught `logo..png` but never a real traversal -
	 * core's validate_file() had already dropped `../` entries silently
	 * before the walk saw them. Now a `..` path SEGMENT refuses the archive,
	 * and so do an absolute path, a drive letter, a control character or
	 * NUL. A symlink entry is never written. A name that hides a PHP
	 * extension in the middle (`x.php.png`) is left out too: an Apache still
	 * carrying a legacy `AddHandler … .php` runs it.
	 *
	 * @param array<int, array{index:int, name:string, size:int, link:bool, encrypted:bool}> $entries
	 * @param array{entries:int, file_bytes:int, total_bytes:int}                            $limits
	 * @return array<int, array{index:int, path:string, size:int}>|\WP_Error
	 */
	private static function plan( array $entries, array $limits ): array|\WP_Error {
		$plan  = array();
		$seen  = array();
		$total = 0;

		foreach ( $entries as $entry ) {
			$path = self::safe_path( $entry['name'] );
			if ( false === $path ) {
				continue;
			}
			if ( null === $path ) {
				return new \WP_Error(
					'dxai_ui_zip',
					/* translators: %s: the entry name. */
					sprintf( __( 'The ZIP archive contains an unsafe path (%s), so nothing was unpacked.', 'dxai-ui' ), self::shown( $entry['name'] ) ),
					array( 'status' => 400 )
				);
			}
			if ( '' === $path || $entry['link'] ) {
				continue;
			}
			if ( preg_match( self::SKIPPED_DIRS, $path ) === 1 ) {
				continue;
			}

			$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, self::BLOCKED_EXTENSIONS, true ) ) {
				return new \WP_Error(
					'dxai_ui_zip',
					/* translators: %s: the entry name. */
					sprintf( __( 'Uploaded archives may not contain PHP or other executable files (%s).', 'dxai-ui' ), self::shown( $path ) ),
					array( 'status' => 400 )
				);
			}
			if ( '' !== $ext && ! in_array( $ext, self::ALLOWED_EXTENSIONS, true ) ) {
				continue;
			}
			if ( preg_match( '/\.(?:php\d?|phtml|phar|pht|phps)\./i', basename( $path ) ) === 1 ) {
				continue;
			}
			// `a\b.png` and `a/b.png` are one file once separators are normalised.
			if ( isset( $seen[ $path ] ) ) {
				continue;
			}

			if ( $entry['encrypted'] ) {
				return new \WP_Error(
					'dxai_ui_zip',
					/* translators: %s: the entry name. */
					sprintf( __( 'The ZIP archive is password-protected (%s). Export it again without a password.', 'dxai-ui' ), self::shown( $path ) ),
					array( 'status' => 400 )
				);
			}
			if ( $entry['size'] > $limits['file_bytes'] ) {
				return self::over_file_limit( $path, $limits['file_bytes'] );
			}
			$total += max( 0, $entry['size'] );
			if ( $total > $limits['total_bytes'] ) {
				return self::over_total_limit( $limits['total_bytes'] );
			}

			$seen[ $path ] = true;
			$plan[]        = array(
				'index' => $entry['index'],
				'path'  => $path,
				'size'  => max( 0, $entry['size'] ),
			);
		}

		return $plan;
	}

	/**
	 * An entry name as a relative path inside the work folder: backslashes
	 * turned into slashes, empty and `.` segments dropped. '' for a directory
	 * entry; null when the name could leave the folder (the whole archive is
	 * then refused); false for a name that is only unusable, which is left out.
	 *
	 * Left out: a control character other than NUL, and a segment ending in
	 * a dot or a space. Windows drops those when it writes the name, so
	 * `evil.php.` - no extension, so kept as an extensionless file - would
	 * land on an IIS or XAMPP host as `evil.php`. Neither can leave the
	 * folder, and archives made on a Mac carry them harmlessly: the
	 * custom-folder-icon file is `Icon\r`, with a `__MACOSX/._Icon\r` beside
	 * it, and `Assets /logo.png` is an easy folder name to type.
	 */
	private static function safe_path( string $name ): string|false|null {
		if ( '' === $name ) {
			return '';
		}
		if ( str_contains( $name, "\0" ) ) {
			return null;
		}
		$unusable = preg_match( '/[\x01-\x1F\x7F]/', $name ) === 1;

		$name = str_replace( '\\', '/', $name );
		if ( str_starts_with( $name, '/' ) || preg_match( '/^[A-Za-z]:/', $name ) === 1 ) {
			return null;
		}

		$parts = array();
		foreach ( explode( '/', $name ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				return null;
			}
			if ( rtrim( $segment, '. ' ) !== $segment ) {
				$unusable = true;
			}
			$parts[] = $segment;
		}
		if ( str_ends_with( $name, '/' ) ) {
			return '';
		}

		return $unusable ? false : implode( '/', $parts );
	}

	/**
	 * Stream the planned entries out of the archive.
	 *
	 * The byte limits are counted on what the stream actually yields, not on
	 * the size the central directory declares: a crafted entry can declare
	 * 100 bytes and inflate to gigabytes, and plan() only ever saw the
	 * declaration.
	 *
	 * @param array<int, array{index:int, path:string, size:int}> $plan
	 * @param array{entries:int, file_bytes:int, total_bytes:int} $limits
	 * @return array<int, string>|\WP_Error
	 */
	private static function extract_with_ziparchive( string $archive, array $plan, string $dir, array $limits ): array|\WP_Error {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $archive ) ) {
			return self::not_a_zip();
		}

		$files   = array();
		$written = 0;

		/*
		 * Plain PHP streams on purpose (see the class comment), and silenced:
		 * a failure is answered with a WP_Error, and a PHP warning printed
		 * into a REST response on a site with WP_DEBUG_DISPLAY breaks its
		 * JSON before that answer can arrive.
		 */
		// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged
		foreach ( $plan as $item ) {
			$target = $dir . '/' . $item['path'];
			$parent = dirname( $target );
			if ( ! is_dir( $parent ) && ! @mkdir( $parent, 0700, true ) && ! is_dir( $parent ) ) {
				$zip->close();
				return self::clashes( $dir, $item['path'] ) ? self::path_clash( $item['path'] ) : self::write_failed( $item['path'] );
			}

			$in = method_exists( $zip, 'getStreamIndex' )
				? $zip->getStreamIndex( $item['index'] )
				: $zip->getStream( (string) $zip->getNameIndex( $item['index'] ) );
			if ( ! is_resource( $in ) ) {
				$zip->close();
				return self::read_failed( $item['path'] );
			}
			$out = @fopen( $target, 'wb' );
			if ( false === $out ) {
				fclose( $in );
				$zip->close();
				return self::clashes( $dir, $item['path'] ) ? self::path_clash( $item['path'] ) : self::write_failed( $item['path'] );
			}

			$bytes = 0;
			$fault = null;
			while ( ! feof( $in ) ) {
				$chunk = @fread( $in, 65536 );
				if ( false === $chunk ) {
					$fault = self::read_failed( $item['path'] );
					break;
				}
				if ( '' === $chunk ) {
					break;
				}
				$length   = strlen( $chunk );
				$bytes   += $length;
				$written += $length;
				if ( $bytes > $limits['file_bytes'] ) {
					$fault = self::over_file_limit( $item['path'], $limits['file_bytes'] );
					break;
				}
				if ( $written > $limits['total_bytes'] ) {
					$fault = self::over_total_limit( $limits['total_bytes'] );
					break;
				}
				if ( @fwrite( $out, $chunk ) !== $length ) {
					$fault = self::write_failed( $item['path'] );
					break;
				}
			}
			fclose( $in );
			fclose( $out );

			/*
			 * Fewer (or more) bytes than the central directory declares. PHP's
			 * zip stream flags end-of-file on a short read, so libzip never
			 * gets to its CRC check: an entry with damaged deflate data came
			 * out a few bytes short, garbled, with no error. The declared size
			 * is what plan() budgeted, and a lying header is caught here too.
			 */
			if ( null === $fault && $bytes !== $item['size'] ) {
				$fault = self::read_failed( $item['path'] );
			}

			if ( null !== $fault ) {
				$zip->close();
				return $fault;
			}
			$files[] = $item['path'];
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged

		$zip->close();

		return $files;
	}

	/**
	 * Without the zip extension: core unzip_file(), which goes through PclZip.
	 *
	 * unzip_file() writes through WP_Filesystem. When that cannot start - or
	 * starts as FTP/SSH, which would leave the files owned by another account
	 * than the PHP that reads and removes them - the import stops with the
	 * reason, rather than failing further on with an empty file list.
	 *
	 * Core writes every entry (it cannot pick), so the ones plan() did not
	 * choose are removed straight after, and the guard files are written
	 * again in case the archive carried its own `.htaccess`. read_entries()
	 * and plan() have already refused anything unsafe before this runs.
	 *
	 * The byte limits are counted again on what reached disk. PclZip
	 * inflates each entry whole, whatever size its header declares, so the
	 * declared sizes plan() checked are all this path had: the bomb-lying
	 * fixture (1000 bytes declared, 30 MB inflated) went through with both
	 * limits lowered to 1 MB. Counting afterwards cannot spare the disk or
	 * PclZip's memory what one entry takes - core offers no streaming here -
	 * but the archive is refused and its folder removed, as on the
	 * ZipArchive path.
	 *
	 * @param array<int, array{index:int, path:string, size:int}> $plan
	 * @param array{entries:int, file_bytes:int, total_bytes:int} $limits
	 * @return array<int, string>|\WP_Error
	 */
	private static function extract_with_core( string $archive, array $plan, string $dir, array $limits ): array|\WP_Error {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		global $wp_filesystem;
		if ( ! WP_Filesystem() || ! is_object( $wp_filesystem ) ) {
			return new \WP_Error(
				'dxai_ui_zip',
				/* translators: %s: WordPress filesystem method, e.g. ftpext. */
				sprintf( __( 'This server has no PHP zip extension, and WordPress could not start its filesystem layer (method "%s") to unpack the archive another way. Ask your host to enable the PHP zip extension.', 'dxai-ui' ), (string) get_filesystem_method() ),
				array( 'status' => 500 )
			);
		}
		if ( 'direct' !== (string) ( $wp_filesystem->method ?? '' ) ) {
			return new \WP_Error(
				'dxai_ui_zip',
				/* translators: %s: WordPress filesystem method, e.g. ftpext. */
				sprintf( __( 'This server has no PHP zip extension, and WordPress writes files here through "%s" rather than directly, so an unpacked archive would belong to another account. Ask your host to enable the PHP zip extension.', 'dxai-ui' ), (string) ( $wp_filesystem->method ?? '' ) ),
				array( 'status' => 500 )
			);
		}

		$unzipped = unzip_file( $archive, $dir );
		if ( is_wp_error( $unzipped ) ) {
			return new \WP_Error(
				'dxai_ui_zip',
				/* translators: %s: the reason WordPress gave. */
				sprintf( __( 'Unable to unpack the ZIP archive: %s', 'dxai-ui' ), $unzipped->get_error_message() ),
				array( 'status' => 400 )
			);
		}

		$keep  = array_fill_keys( array_column( $plan, 'path' ), true );
		$files = array();
		self::prune( $dir, '', $keep, $files );
		self::guard( $dir );

		$total = 0;
		foreach ( $files as $relative ) {
			$size = (int) @filesize( $dir . '/' . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $size > $limits['file_bytes'] ) {
				return self::over_file_limit( $relative, $limits['file_bytes'] );
			}
			$total += $size;
			if ( $total > $limits['total_bytes'] ) {
				return self::over_total_limit( $limits['total_bytes'] );
			}
		}

		return $files;
	}

	/**
	 * Delete everything under $dir whose relative path is not in $keep, and
	 * collect the paths that are.
	 *
	 * @param array<string, true> $keep
	 * @param array<int, string>  $files
	 */
	private static function prune( string $dir, string $relative, array $keep, array &$files ): void {
		$names = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable folder is skipped; a warning would break the REST JSON.
		if ( ! is_array( $names ) ) {
			return;
		}
		foreach ( $names as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path = $dir . '/' . $name;
			$rel  = '' === $relative ? $name : $relative . '/' . $name;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::prune( $path, $rel, $keep, $files );
				continue;
			}
			if ( isset( $keep[ $rel ] ) && ! is_link( $path ) ) {
				$files[] = $rel;
				continue;
			}
			/*
			 * PclZip on Linux writes an entry named `sub\win.css` as one file
			 * with a backslash in its name, in the root; plan() read it as
			 * `sub/win.css`. Moved to where the plan put it rather than lost.
			 */
			$slashed = str_replace( '\\', '/', $rel );
			if ( $slashed !== $rel && isset( $keep[ $slashed ] ) && ! is_link( $path ) && ! in_array( $slashed, $files, true ) ) {
				$root   = substr( $dir, 0, strlen( $dir ) - strlen( $relative ) );
				$target = rtrim( $root, '/' ) . '/' . $slashed;
				if ( ( is_dir( dirname( $target ) ) || wp_mkdir_p( dirname( $target ) ) ) && @rename( $path, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- a failure leaves the file to be removed below.
					$files[] = $slashed;
					continue;
				}
			}
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- see cleanup().
		}
	}

	/**
	 * A new, empty folder for one import.
	 *
	 * Under the system temp directory when PHP can write there, which the web
	 * server does not serve; `dxai_ui_zip_work_root` moves it, for a host
	 * whose temp directory is unusable. Otherwise in uploads/dxai-ui, which it
	 * does serve. Either way the folder is made by PHP with mode 0700 and a
	 * 64-bit random name, directly in the root (never inside a shared
	 * subfolder another account could have pre-made on a shared /tmp), and
	 * gets an index.php and an .htaccess denying access before anything else
	 * is written into it. get_temp_dir() itself falls back to wp-content
	 * when nothing better is writable, and WP_TEMP_DIR can point anywhere, so
	 * the guard files are written wherever the folder lands rather than only
	 * in uploads.
	 *
	 * On nginx the .htaccess means nothing; there the 0700 mode keeps a web
	 * server running as another user out of the folder (php-fpm pools with
	 * their own user are the usual VPS setup), and the random name keeps it
	 * unguessable when it runs as the same user.
	 *
	 * A root without room for $need bytes (plus a tenth) is passed over: a
	 * small tmpfs /tmp or a nearly full cPanel securetmp is common, and the
	 * uploads fallback is usually on the larger disk. Only when no root has
	 * room is the import refused for space.
	 */
	private static function work_dir( float $need = 0.0 ): string|\WP_Error {
		$short = null;
		foreach ( self::roots() as $root ) {
			if ( $root['fallback'] ) {
				wp_mkdir_p( $root['dir'] );
				if ( is_dir( $root['dir'] ) && ! file_exists( $root['dir'] . '/index.php' ) ) {
					@file_put_contents( $root['dir'] . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would break the REST JSON; guard() checks the folder next.
				}
			}
			if ( ! is_dir( $root['dir'] ) || ! wp_is_writable( $root['dir'] ) ) {
				continue;
			}
			$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $root['dir'] ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir or a disabled stat answers false, which skips the check.
			if ( false !== $free && $free < $need * 1.1 ) {
				$short = null === $short ? (float) $free : max( $short, (float) $free );
				continue;
			}

			$dir = $root['dir'] . '/' . $root['prefix'] . bin2hex( random_bytes( 8 ) );
			if ( ! @mkdir( $dir, 0700 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- a failure moves on to the next root.
				continue;
			}
			self::track( $dir );
			if ( ! self::guard( $dir ) ) {
				self::cleanup( $dir );
				continue;
			}

			return $dir;
		}

		if ( null !== $short ) {
			return new \WP_Error(
				'dxai_ui_zip',
				/* translators: 1: bytes the archive needs, 2: bytes free. */
				sprintf( __( 'Not enough free disk space to unpack the archive: it needs %1$s and %2$s is free.', 'dxai-ui' ), size_format( $need ), size_format( $short ) ),
				array( 'status' => 507 )
			);
		}

		return new \WP_Error(
			'dxai_ui_zip',
			__( 'No folder could be written to unpack the archive: neither the system temp directory nor wp-content/uploads is writable by PHP.', 'dxai-ui' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Where work folders are made, in order of preference, with the name
	 * pattern that marks one as this class's own in each.
	 *
	 * @return array<int, array{dir:string, prefix:string, pattern:string, fallback:bool}>
	 */
	private static function roots(): array {
		$roots = array();

		$temp = (string) apply_filters( 'dxai_ui_zip_work_root', get_temp_dir() );
		if ( '' !== $temp ) {
			$roots[] = array(
				'dir'      => untrailingslashit( wp_normalize_path( $temp ) ),
				'prefix'   => self::WORK_PREFIX,
				'pattern'  => '/^' . preg_quote( self::WORK_PREFIX, '/' ) . '[a-f0-9]{16}$/',
				'fallback' => false,
			);
		}

		$upload = wp_upload_dir( null, false );
		if ( ! empty( $upload['basedir'] ) ) {
			$roots[] = array(
				'dir'      => untrailingslashit( wp_normalize_path( (string) $upload['basedir'] ) ) . '/' . Upload_Paths::DIR,
				'prefix'   => self::FALLBACK_PREFIX,
				'pattern'  => '/^' . preg_quote( self::FALLBACK_PREFIX, '/' ) . '[A-Za-z0-9]{8,32}$/',
				'fallback' => true,
			);
		}

		return $roots;
	}

	/**
	 * Whether a path is a work folder by its name: `dxai-ui-tmp-<hex>`
	 * anywhere, or `tmp-<letters and digits>` directly inside a `dxai-ui`
	 * uploads folder. A bare `tmp-…` elsewhere - in /tmp, say - is not ours.
	 */
	private static function is_own_folder( string $dir ): bool {
		$name = basename( $dir );
		if ( preg_match( '/^' . preg_quote( self::WORK_PREFIX, '/' ) . '[a-f0-9]{16}$/', $name ) === 1 ) {
			return true;
		}

		return basename( dirname( $dir ) ) === Upload_Paths::DIR
			&& preg_match( '/^' . preg_quote( self::FALLBACK_PREFIX, '/' ) . '[A-Za-z0-9]{8,32}$/', $name ) === 1;
	}

	/** Write the index.php and the deny rules into a work folder. */
	private static function guard( string $dir ): bool {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$index = file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
		$deny  = file_put_contents( $dir . '/.htaccess', self::DENY_RULES );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return false !== $index && false !== $deny;
	}

	/**
	 * Remember a work folder, and make sure it goes when the request ends.
	 *
	 * A shutdown function runs after a fatal error and after
	 * max_execution_time, which is how a long import used to leave its folder
	 * behind. It does not run when the process is killed outright (php-fpm's
	 * request_terminate_timeout, an OOM kill); sweep() covers that.
	 */
	private static function track( string $dir ): void {
		self::$work[ $dir ] = true;
		if ( self::$shutdown_registered ) {
			return;
		}
		self::$shutdown_registered = true;
		register_shutdown_function(
			static function (): void {
				foreach ( array_keys( self::$work ) as $dir ) {
					self::remove_tree( $dir );
				}
				self::$work = array();
			}
		);
	}

	/**
	 * Remove work folders a killed request left behind: this class's own
	 * name pattern, older than a day, writable by this PHP (so another
	 * account's folder on a shared /tmp is left to that account), and not in
	 * use by this request.
	 *
	 * The temp root is read one name at a time rather than with scandir():
	 * on a shared host it is often /tmp itself, which also holds every PHP
	 * session file when session.save_path is left at its default, and
	 * scandir() would build that whole list in memory on every import. A
	 * root that cannot be listed is skipped without a warning, for the same
	 * reason the extraction is silenced.
	 */
	private static function sweep(): void {
		$cutoff = time() - self::STALE_AFTER;
		foreach ( self::roots() as $root ) {
			$handle = is_dir( $root['dir'] ) ? @opendir( $root['dir'] ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $handle ) {
				continue;
			}
			$stale = array();
			while ( false !== ( $name = readdir( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				if ( preg_match( $root['pattern'], $name ) === 1 ) {
					$stale[] = $root['dir'] . '/' . $name;
				}
			}
			closedir( $handle );

			foreach ( $stale as $path ) {
				if ( isset( self::$work[ $path ] ) || is_link( $path ) || ! is_dir( $path ) ) {
					continue;
				}
				$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false === $mtime || $mtime > $cutoff || ! wp_is_writable( $path ) ) {
					continue;
				}
				self::remove_tree( $path );
			}
		}
	}

	/**
	 * Delete a folder and everything in it, never following a symlink out of
	 * it. Failures are silent: whatever is left is swept by a later import.
	 */
	private static function remove_tree( string $dir ): void {
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions -- see cleanup().
		if ( is_link( $dir ) ) {
			@unlink( $dir );
			return;
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$names = @scandir( $dir );
		if ( is_array( $names ) ) {
			foreach ( $names as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$path = $dir . '/' . $name;
				if ( is_dir( $path ) && ! is_link( $path ) ) {
					self::remove_tree( $path );
				} else {
					@unlink( $path );
				}
			}
		}
		@rmdir( $dir );
		// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions
	}

	/**
	 * The file list in one fixed order: each folder's names compared
	 * upper-cased, and a folder's contents before its later siblings.
	 *
	 * That is the order the old tree walk produced on the NTFS disk the
	 * verification pack runs on, so every connector still sees the files it
	 * was verified with in the order it was verified with. On a Linux host
	 * the same walk returned ext4's hash order, so which of two same-depth
	 * `.dc.html` files Dc_Connector took, or which of two identical images
	 * the harvester kept as the original, could differ from one server to
	 * the next. Archive order would have been deterministic too, but not the
	 * order anything was measured in.
	 *
	 * @param array<int, string> $files
	 * @return array<int, string>
	 */
	private static function sorted( array $files ): array {
		usort(
			$files,
			static function ( string $a, string $b ): int {
				$left  = explode( '/', $a );
				$right = explode( '/', $b );
				$depth = min( count( $left ), count( $right ) );
				for ( $i = 0; $i < $depth; $i++ ) {
					if ( $left[ $i ] === $right[ $i ] ) {
						continue;
					}
					$cmp = strcmp( strtoupper( $left[ $i ] ), strtoupper( $right[ $i ] ) );

					return 0 !== $cmp ? $cmp : strcmp( $left[ $i ], $right[ $i ] );
				}

				return count( $left ) <=> count( $right );
			}
		);

		return $files;
	}

	/**
	 * The ceilings, after `dxai_ui_zip_limits`; a missing, zero or negative
	 * value keeps the default rather than switching the guard off.
	 *
	 * @return array{entries:int, file_bytes:int, total_bytes:int}
	 */
	private static function limits(): array {
		$limits   = self::LIMITS;
		$filtered = apply_filters( 'dxai_ui_zip_limits', self::LIMITS );
		if ( is_array( $filtered ) ) {
			foreach ( self::LIMITS as $key => $default ) {
				$value = isset( $filtered[ $key ] ) ? (int) $filtered[ $key ] : 0;
				if ( $value > 0 ) {
					$limits[ $key ] = $value;
				}
			}
		}

		return $limits;
	}

	/**
	 * An entry name fit to show in an error: the admin renders it as text,
	 * but it came from the archive, so control characters and markup go and
	 * the length is capped.
	 */
	private static function shown( string $name ): string {
		if ( ! function_exists( 'mb_check_encoding' ) || ! mb_check_encoding( $name, 'UTF-8' ) ) {
			$name = (string) preg_replace( '/[^\x20-\x7E]/', '?', $name );
		}
		$name = (string) preg_replace( '/[\x00-\x1F\x7F]/u', '?', $name );

		return sanitize_text_field( mb_substr( $name, 0, 160 ) );
	}

	private static function not_a_zip( int $code = 0 ): \WP_Error {
		return new \WP_Error(
			'dxai_ui_zip',
			__( 'This file is not a ZIP archive, or it is damaged.', 'dxai-ui' ),
			array(
				'status'    => 400,
				'zip_error' => $code,
			)
		);
	}

	private static function too_many( int $count, int $limit ): \WP_Error {
		return new \WP_Error(
			'dxai_ui_zip',
			/* translators: 1: entries in the archive, 2: the limit. */
			sprintf( __( 'The ZIP archive holds %1$s entries, more than the %2$s this site accepts. Zip the project without node_modules, or raise the limit with the dxai_ui_zip_limits filter.', 'dxai-ui' ), number_format_i18n( $count ), number_format_i18n( $limit ) ),
			array( 'status' => 413 )
		);
	}

	private static function too_large( int $code ): \WP_Error {
		$max = size_format( wp_max_upload_size() );

		return new \WP_Error(
			'dxai_ui_zip',
			UPLOAD_ERR_FORM_SIZE === $code
				/* translators: %s: the largest upload this site accepts, e.g. 64 MB. */
				? sprintf( __( 'The ZIP file is larger than the upload form allows. This site accepts uploads up to %s.', 'dxai-ui' ), $max )
				/* translators: %s: the largest upload this site accepts, e.g. 64 MB. */
				: sprintf( __( 'The ZIP file is larger than this server accepts for an upload (%s). Upload a smaller archive, or ask your host to raise upload_max_filesize and post_max_size.', 'dxai-ui' ), $max ),
			array( 'status' => 413 )
		);
	}

	private static function over_file_limit( string $path, int $limit ): \WP_Error {
		return new \WP_Error(
			'dxai_ui_zip',
			/* translators: 1: the entry name, 2: the per-file limit. */
			sprintf( __( '%1$s in the ZIP archive unpacks to more than %2$s, the most one file may take. The archive was refused.', 'dxai-ui' ), self::shown( $path ), size_format( $limit ) ),
			array( 'status' => 413 )
		);
	}

	private static function over_total_limit( int $limit ): \WP_Error {
		return new \WP_Error(
			'dxai_ui_zip',
			/* translators: %s: the total limit. */
			sprintf( __( 'The ZIP archive unpacks to more than %s. Remove large media from it, or raise the limit with the dxai_ui_zip_limits filter.', 'dxai-ui' ), size_format( $limit ) ),
			array( 'status' => 413 )
		);
	}

	private static function read_failed( string $path ): \WP_Error {
		return new \WP_Error(
			'dxai_ui_zip',
			/* translators: %s: the entry name. */
			sprintf( __( '%s could not be read from the ZIP archive; the archive may be damaged.', 'dxai-ui' ), self::shown( $path ) ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Whether a planned path cannot be written because the archive also has
	 * a file where this path needs a folder, or a folder where it needs a
	 * file (entries `public` and `public/logo.png`).
	 */
	private static function clashes( string $dir, string $path ): bool {
		if ( is_dir( $dir . '/' . $path ) ) {
			return true;
		}
		$at = dirname( $path );
		while ( '.' !== $at && '' !== $at ) {
			if ( is_file( $dir . '/' . $at ) ) {
				return true;
			}
			$at = dirname( $at );
		}

		return false;
	}

	private static function path_clash( string $path ): \WP_Error {
		return new \WP_Error(
			'dxai_ui_zip',
			/* translators: %s: the entry name. */
			sprintf( __( 'The ZIP archive has a file and a folder with the same name (%s), so it cannot be unpacked. Export it again.', 'dxai-ui' ), self::shown( $path ) ),
			array( 'status' => 400 )
		);
	}

	private static function write_failed( string $path ): \WP_Error {
		return new \WP_Error(
			'dxai_ui_zip',
			/* translators: %s: the entry name. */
			sprintf( __( '%s could not be written to the work folder. The disk may be full.', 'dxai-ui' ), self::shown( $path ) ),
			array( 'status' => 500 )
		);
	}

	private static function nothing_supported(): \WP_Error {
		return new \WP_Error( 'dxai_ui_zip', __( 'The ZIP archive did not contain supported files.', 'dxai-ui' ), array( 'status' => 400 ) );
	}
}

<?php
/**
 * The page package format: one converted page with everything it needs.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Transfer;

use DXAI_UI\Media\Sideloader;

/**
 * A converted page does not carry its look in its blocks. The blocks hold
 * `dxaiCss` / `dxaiInner` and `dxs-` classes whose rules the plugin writes
 * when the page renders (Style_Rules), the design's stylesheet is a file in
 * uploads scoped to the page (`.dxai-ui.dxai-ui--{scope}`), its tokens,
 * fonts and runtime script are post meta, its images are attachments, and its
 * header and footer are template parts or the site header and footer blocks.
 * Copying the blocks from one editor into another site's editor carries none
 * of that, which is how a page pasted into a production site lost every
 * style.
 *
 * A package is a ZIP holding `manifest.json` and a `files/` folder:
 *
 * - manifest.json: the format and plugin version, the source site, the page
 *   (title, slug, content, template, every `_dxai_ui_*` meta), its scope, the
 *   template parts, synced patterns and navigation posts it references, the
 *   header and footer it renders (the header template and its menus' items,
 *   the footer frame and its widgets), whether it was the source's brand,
 *   and one entry per media file with its alt text, caption and title and
 *   every URL of it the page uses;
 * - files/design/: the page's design stylesheet and script;
 * - files/patterns/: each synced pattern's stylesheet;
 * - files/media/: the original of every attachment the page, its parts, its
 *   patterns, its header and its footer use, and any other uploads file they
 *   point at (a font stored as a design asset).
 *
 * Nothing in it is a secret or another person's data: no options but the
 * header and footer this page renders, no user, author or session, no API
 * keys. The same page exports to the same bytes: entries are sorted, JSON is
 * written with a fixed layout, and every entry carries the page's
 * modification time instead of the time of the export.
 *
 * This class is the format's one definition — names, limits, the checks a
 * path and a file type must pass — shared by Page_Export and Page_Import, and
 * the temporary folders both work in.
 */
final class Package {

	/** What `manifest.format` says for a page package. */
	public const FORMAT = 'dxai-ui-page';

	/** The newest manifest layout this code reads and the one it writes. */
	public const VERSION = 1;

	public const MANIFEST = 'manifest.json';

	/** A manifest larger than this is not read (32 MB). */
	public const MANIFEST_MAX = 33554432;

	/** What an exported package's file name ends in. */
	public const SUFFIX = '.dxai.zip';

	/**
	 * Post meta on everything an import writes: which package item it is
	 * (sha1 of the source site and the page's identity, plus the item's
	 * kind and source id for a part, pattern or navigation post). A second
	 * import of the same package finds it and updates it in place.
	 */
	public const KEY_META = '_dxai_ui_transfer_key';

	/** Post meta on an imported page: where it came from (site, page id, plugin version). */
	public const SOURCE_META = '_dxai_ui_transfer_source';

	/** At most this many template parts, patterns and navigation posts are followed. */
	public const MAX_ITEMS = 200;

	/** Template file names an imported page may be given. */
	public const TEMPLATES = array( 'dxai-blank.php', 'dxai-template.php' );

	/** Temporary folders made in this request, removed at shutdown if still there. */
	private static array $temp = array();

	/** @var array<string, array<int, string>> Files made in each of them (made()). */
	private static array $made = array();

	private static bool $shutdown = false;

	/**
	 * The identity of a package's page: the source site and the page's own
	 * key there. Two exports of one page — before and after an edit — are one
	 * identity, so the second import updates what the first created.
	 */
	public static function identity( string $site_url, string $page_key ): string {
		return sha1( self::site_key( $site_url ) . '|' . $page_key );
	}

	/**
	 * A site URL without its scheme, `www.`, case or trailing slash: the same
	 * site whether it was exported over http or https.
	 */
	public static function site_key( string $url ): string {
		$url  = strtolower( trim( $url ) );
		$url  = (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $url );
		$url  = (string) preg_replace( '#^www\.#', '', $url );

		return untrailingslashit( $url );
	}

	/**
	 * A package entry name as this format writes it: `files/` then segments
	 * of letters, digits, dot, dash and underscore, none of them `.` or
	 * `..`. Anything else is refused — the unpacker refuses traversal and
	 * absolute paths already, this also refuses names the format never
	 * writes.
	 */
	public static function safe_path( string $path ): bool {
		if ( preg_match( '#^files/(?:[A-Za-z0-9_][A-Za-z0-9._-]*/)*[A-Za-z0-9_][A-Za-z0-9._-]*$#', $path ) !== 1 ) {
			return false;
		}
		foreach ( explode( '/', $path ) as $segment ) {
			if ( $segment === '.' || $segment === '..' ) {
				return false;
			}
		}

		return strlen( $path ) <= 255;
	}

	/**
	 * Whether a media entry of this extension may be imported: the kinds the
	 * media sideloader stores (images, video, audio, fonts, Lottie JSON).
	 */
	public static function media_allowed( string $name ): bool {
		$ext = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		return in_array( Sideloader::kind( $ext ), array( 'image', 'video', 'audio', 'font', 'lottie' ), true );
	}

	/**
	 * A file name for inside the package: its basename made safe, with a
	 * prefix that keeps two files of one name apart.
	 */
	public static function file_name( string $prefix, string $name ): string {
		$base = sanitize_file_name( wp_basename( $name ) );
		$base = (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', $base );
		$base = trim( $base, '.-' );
		if ( $base === '' ) {
			$base = 'file';
		}
		if ( strlen( $base ) > 100 ) {
			$ext  = (string) pathinfo( $base, PATHINFO_EXTENSION );
			$base = substr( $base, 0, 90 ) . ( $ext !== '' ? '.' . substr( $ext, 0, 8 ) : '' );
		}

		return $prefix . '-' . $base;
	}

	/**
	 * JSON the way a package writes it: stable layout, slashes and Unicode
	 * as they are, so the manifest diffs cleanly between two exports.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function json( array $data ): string {
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return is_string( $json ) ? $json . "\n" : '';
	}

	/**
	 * A private temporary folder: under the system temp directory when PHP
	 * can write there (the web server does not serve it), else under
	 * uploads/dxai-ui behind a deny rule. Removed at shutdown if the caller
	 * did not remove it.
	 */
	public static function temp_dir( string $prefix ): string|\WP_Error {
		$roots = array();
		$sys   = get_temp_dir();
		if ( is_string( $sys ) && $sys !== '' ) {
			$roots[] = untrailingslashit( wp_normalize_path( $sys ) );
		}
		$uploads = wp_upload_dir();
		if ( empty( $uploads['error'] ) ) {
			$roots[] = untrailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) ) . '/' . \DXAI_UI\Support\Upload_Paths::DIR;
		}
		foreach ( $roots as $i => $root ) {
			if ( ! is_dir( $root ) ) {
				wp_mkdir_p( $root );
			}
			if ( ! is_dir( $root ) || ! wp_is_writable( $root ) || ! self::listable( $root ) ) {
				continue;
			}
			$dir = $root . '/dxai-ui-' . $prefix . '-' . bin2hex( random_bytes( 8 ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- private work folder.
			if ( ! @mkdir( $dir, 0700 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				continue;
			}
			self::track( $dir );
			if ( $i > 0 ) {
				// In uploads: never served.
				file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				self::made( $dir . '/.htaccess' );
				self::made( $dir . '/index.php' );
			}

			return $dir;
		}

		return new \WP_Error( 'dxai_ui_transfer', __( 'No writable temporary folder: neither the system temp directory nor wp-content/uploads can be written to.', 'dxai-ui' ), array( 'status' => 500 ) );
	}

	/**
	 * Whether PHP may list a folder. Some temp folders can be written but
	 * not listed — C:\Windows\Temp for the account a Windows web server runs
	 * PHP as, measured on this plugin's own test install: a folder made there
	 * could never be walked to remove it again. Such a root is passed over.
	 */
	public static function listable( string $dir ): bool {
		$handle = @opendir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $handle ) {
			return false;
		}
		closedir( $handle );

		return true;
	}

	/**
	 * Remove a folder temp_dir() made, and everything in it. Any other path
	 * is left alone.
	 */
	public static function remove_dir( string $dir ): void {
		$dir = untrailingslashit( wp_normalize_path( $dir ) );
		if ( $dir === '' || ! isset( self::$temp[ $dir ] ) ) {
			return;
		}
		unset( self::$temp[ $dir ] );
		self::remove_tree( $dir );
	}

	/**
	 * Record a file made inside a temp_dir() folder, so remove_dir() can
	 * delete it by name: a temp folder can be one PHP may write but not list
	 * (C:\Windows\Temp for a web server's service account), where a folder
	 * walk finds nothing to delete.
	 */
	public static function made( string $path ): void {
		$path = wp_normalize_path( $path );
		foreach ( array_keys( self::$temp ) as $dir ) {
			if ( str_starts_with( $path, $dir . '/' ) ) {
				self::$made[ $dir ][] = $path;

				return;
			}
		}
	}

	private static function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return;
		}
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions -- silent: a warning printed into a REST answer breaks its JSON.
		$made = self::$made[ $dir ] ?? array();
		unset( self::$made[ $dir ] );
		$dirs = array();
		foreach ( $made as $path ) {
			if ( is_file( $path ) ) {
				@unlink( $path );
			}
			for ( $up = dirname( $path ); strlen( $up ) > strlen( $dir ); $up = dirname( $up ) ) {
				$dirs[ $up ] = strlen( $up );
			}
		}
		arsort( $dirs );
		foreach ( array_keys( $dirs ) as $sub ) {
			@rmdir( $sub );
		}
		$items = @scandir( $dir );
		// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( $item === '.' || $item === '..' ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::remove_tree( $path );
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	private static function track( string $dir ): void {
		self::$temp[ $dir ] = true;
		if ( self::$shutdown ) {
			return;
		}
		self::$shutdown = true;
		register_shutdown_function(
			static function (): void {
				foreach ( array_keys( self::$temp ) as $dir ) {
					self::remove_tree( (string) $dir );
				}
				self::$temp = array();
			}
		);
	}

	/**
	 * Write a ZIP: ZipArchive when PHP has it, else the PclZip bundled with
	 * WordPress. Entries in the order given (the caller sorts them), each
	 * dated $mtime, so the same input is the same archive.
	 *
	 * @param array<string, string> $entries Entry name => absolute path of its content.
	 */
	public static function write_zip( string $zip_path, array $entries, int $mtime ): true|\WP_Error {
		$mtime = max( 315532800, $mtime ); // ZIP dates start in 1980.
		if ( class_exists( \ZipArchive::class ) ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				return new \WP_Error( 'dxai_ui_transfer', __( 'The package could not be created (ZipArchive refused to open it).', 'dxai-ui' ), array( 'status' => 500 ) );
			}
			foreach ( $entries as $name => $path ) {
				if ( ! $zip->addFile( $path, $name ) ) {
					$zip->close();
					/* translators: %s: entry name. */
					return new \WP_Error( 'dxai_ui_transfer', sprintf( __( 'The package could not be written (%s).', 'dxai-ui' ), $name ), array( 'status' => 500 ) );
				}
				if ( method_exists( $zip, 'setMtimeName' ) ) {
					$zip->setMtimeName( $name, $mtime );
				}
				// Media is compressed already; storing it saves the time.
				if ( method_exists( $zip, 'setCompressionName' ) && preg_match( '/\.(?:jpe?g|png|webp|gif|avif|mp4|webm|mov|m4v|mp3|m4a|ogg|woff2?)$/i', $name ) === 1 ) {
					$zip->setCompressionName( $name, \ZipArchive::CM_STORE );
				}
			}
			if ( ! $zip->close() ) {
				return new \WP_Error( 'dxai_ui_transfer', __( 'The package could not be written to disk.', 'dxai-ui' ), array( 'status' => 500 ) );
			}

			return true;
		}

		if ( ! function_exists( 'gzopen' ) ) {
			return new \WP_Error( 'dxai_ui_transfer', __( 'This server cannot write ZIP archives: PHP has neither the zip extension nor zlib. Ask your host to enable the PHP zip extension.', 'dxai-ui' ), array( 'status' => 500 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

		// PclZip stores files under their path: stage them in the layout the
		// package has, dated $mtime.
		$stage = self::temp_dir( 'zipstage' );
		if ( is_wp_error( $stage ) ) {
			return $stage;
		}
		$list = array();
		foreach ( $entries as $name => $path ) {
			$to = $stage . '/' . $name;
			wp_mkdir_p( dirname( $to ) );
			self::made( $to );
			if ( ! copy( $path, $to ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
				self::remove_dir( $stage );
				/* translators: %s: entry name. */
				return new \WP_Error( 'dxai_ui_transfer', sprintf( __( 'The package could not be written (%s).', 'dxai-ui' ), $name ), array( 'status' => 500 ) );
			}
			touch( $to, $mtime ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
			$list[] = $to;
		}
		$archive = new \PclZip( $zip_path );
		$made    = $archive->create( $list, PCLZIP_OPT_REMOVE_PATH, $stage );
		self::remove_dir( $stage );
		if ( 0 === $made ) {
			return new \WP_Error( 'dxai_ui_transfer', __( 'The package could not be written (PclZip).', 'dxai-ui' ), array( 'status' => 500 ) );
		}

		return true;
	}
}

<?php
/**
 * The team libraries (templates) a site keeps in its uploads: made from the team's exports, built here, trained with more.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

use DXAI_UI\Support\Upload_Paths;

/**
 * A stored library is a folder under uploads/dxai-ui/templates/<slug>/: `template.json` (what it is: label, themes, what it extends,
 * its sources, the last build's report), `exports/` (the files uploaded, kept as they came: the library is built from all of them,
 * again, every time), `library.json` and `blocks/` (what Layout_Builder made of them). It is a template (Template::all()): a site whose
 * theme it names takes its sections, before the built-in one's; one that extends the built-in template has its sections too.
 *
 * The uploaded files are another site's data: never run, only read as blocks (Layout_Builder keeps nothing of a form, a script, an
 * iframe, a name, a number, a picture), and only by an administrator.
 */
final class Library_Store {

	/** Under the uploads folder. */
	public const DIR = Upload_Paths::DIR . '/templates';

	/** The most an export may be. */
	public const MAX_BYTES = 25 * 1024 * 1024;

	/** @var array<string, array<string, mixed>>|null */
	private static ?array $memo = null;

	/** The folder of the libraries, or of one. */
	public static function dir( string $slug = '' ): string {
		return Upload_Paths::path( self::DIR . ( $slug !== '' ? '/' . $slug : '' ) );
	}

	/**
	 * Every stored library, by slug: its template.json, `dir` (with a trailing slash), `stored`, and what it holds (`layouts`, by role).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		if ( self::$memo !== null ) {
			return self::$memo;
		}
		$out  = array();
		$base = self::dir();
		foreach ( $base !== '' && is_dir( $base ) ? (array) glob( $base . '/*/template.json' ) : array() as $file ) {
			$meta = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$slug = sanitize_key( (string) ( $meta['slug'] ?? '' ) );
			if ( ! is_array( $meta ) || $slug === '' || $slug !== basename( dirname( $file ) ) ) {
				continue;
			}
			$dir  = wp_normalize_path( dirname( $file ) ) . '/';
			$doc  = is_readable( $dir . 'library.json' ) ? json_decode( (string) file_get_contents( $dir . 'library.json' ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		    $by_role = array();
			foreach ( is_array( $doc ) ? (array) ( $doc['entries'] ?? array() ) : array() as $row ) {
				$role             = (string) ( $row['role'] ?? '' );
				$by_role[ $role ] = ( $by_role[ $role ] ?? 0 ) + 1;
			}
			ksort( $by_role );
			$out[ $slug ] = array(
				'slug'    => $slug,
				'label'   => (string) ( $meta['label'] ?? $slug ),
				'themes'  => array_values( array_filter( array_map( 'sanitize_key', (array) ( $meta['themes'] ?? array() ) ) ) ),
				'kinds'   => array(),
				'extends' => sanitize_key( (string) ( $meta['extends'] ?? '' ) ),
				'dir'     => $dir,
				'generic' => false,
				'stored'  => true,
				'sources' => array_values( (array) ( $meta['sources'] ?? array() ) ),
				'report'  => is_array( $meta['report'] ?? null ) ? $meta['report'] : null,
				'created' => (string) ( $meta['created'] ?? '' ),
				'updated' => (string) ( $meta['updated'] ?? '' ),
				'layouts' => array_sum( $by_role ),
				'by_role' => $by_role,
			);
		}
		ksort( $out );

		return self::$memo = $out;
	}

	/** Forget what was read (after a change). */
	public static function reset(): void {
		self::$memo = null;
	}

	/** @return array<string, mixed>|null */
	public static function get( string $slug ): ?array {
		return self::all()[ sanitize_key( $slug ) ] ?? null;
	}

	/**
	 * A new library: its folder and its template.json. The slug is the label's, made unique; never the built-in's or the generic's.
	 *
	 * @param array<int, string> $themes Theme folder names it is for.
	 * @param string             $extends A template whose sections it has too (the built-in one, as a rule).
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function create( string $label, array $themes, string $extends = '', string $slug = '' ) {
		$label = sanitize_text_field( $label );
		if ( $label === '' ) {
			return new \WP_Error( 'dxai_ui_library_label', __( 'Give the library a name.', 'dxai-ui' ) );
		}
		$slug = sanitize_key( $slug !== '' ? $slug : sanitize_title( $label ) );
		if ( $slug === '' ) {
			$slug = 'library';
		}
		$taken = array_merge( array( Template::BUILT_IN, Template::GENERIC ), array_keys( self::all() ) );
		$base  = $slug;
		for ( $n = 2; in_array( $slug, $taken, true ); $n++ ) {
			$slug = $base . '-' . $n;
		}
		$themes = array_values( array_unique( array_filter( array_map( 'sanitize_key', $themes ) ) ) );
		if ( $themes === array() ) {
			return new \WP_Error( 'dxai_ui_library_theme', __( 'Say which theme the library is for.', 'dxai-ui' ) );
		}
		$extends = sanitize_key( $extends );
		if ( $extends !== '' && ! isset( Template::all()[ $extends ] ) ) {
			return new \WP_Error( 'dxai_ui_library_extends', __( 'There is no such template to extend.', 'dxai-ui' ) );
		}
		$dir = self::dir( $slug );
		if ( $dir === '' || ! wp_mkdir_p( $dir . '/exports' ) || ! wp_mkdir_p( $dir . '/blocks' ) ) {
			return new \WP_Error( 'dxai_ui_library_dir', __( 'The uploads folder cannot be written.', 'dxai-ui' ) );
		}
		$meta = array(
			'slug'    => $slug,
			'label'   => $label,
			'themes'  => $themes,
			'extends' => $extends,
			'sources' => array(),
			'report'  => null,
			'created' => gmdate( 'c' ),
			'updated' => gmdate( 'c' ),
		);
		self::write_meta( $slug, $meta );
		self::reset();

		return (array) self::get( $slug );
	}

	/**
	 * An export added to a library: a WordPress export (WXR) or a file of block markup (the editor's code view), read to see what it is,
	 * kept under exports/ as it came. Nothing is built here (build()).
	 *
	 * @param string $tmp  The file as uploaded (or anywhere on disk).
	 * @param string $name Its name, for the record and the kind.
	 * @return array<string, mixed>|\WP_Error The library.
	 */
	public static function add_source( string $slug, string $tmp, string $name ) {
		$lib = self::get( $slug );
		if ( $lib === null ) {
			return new \WP_Error( 'dxai_ui_library_none', __( 'There is no such library.', 'dxai-ui' ) );
		}
		if ( ! is_readable( $tmp ) || (int) filesize( $tmp ) < 1 ) {
			return new \WP_Error( 'dxai_ui_library_file', __( 'No file was uploaded.', 'dxai-ui' ) );
		}
		if ( (int) filesize( $tmp ) > self::MAX_BYTES ) {
			return new \WP_Error( 'dxai_ui_library_size', sprintf( /* translators: %s: a size. */ __( 'The file is larger than %s.', 'dxai-ui' ), size_format( self::MAX_BYTES ) ) );
		}
		$head  = (string) file_get_contents( $tmp, false, null, 0, 262144 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$kind  = '';
		$pages = 0;
		if ( str_contains( $head, '<rss' ) && str_contains( $head, 'wp:wxr_version' ) ) {
			$kind  = 'wxr';
			$pages = count( Layout_Builder::read_wxr( $tmp ) );
		} elseif ( str_contains( $head, '<!-- wp:' ) ) {
			$kind  = 'markup';
			$pages = 1;
		}
		if ( $kind === '' || $pages === 0 ) {
			return new \WP_Error( 'dxai_ui_library_kind', __( 'The file is neither a WordPress export with pages in it nor a file of block markup.', 'dxai-ui' ) );
		}
		$safe = sanitize_file_name( $name );
		$safe = $safe !== '' ? $safe : 'export.' . ( $kind === 'wxr' ? 'xml' : 'html' );
		$n    = count( (array) $lib['sources'] ) + 1;
		$file = sprintf( '%02d-%s', $n, $safe );
		$dest = self::dir( $slug ) . '/exports/' . $file;
		if ( ! copy( $tmp, $dest ) ) {
			return new \WP_Error( 'dxai_ui_library_copy', __( 'The file could not be kept.', 'dxai-ui' ) );
		}
		$meta              = self::read_meta( $slug );
		$meta['sources'][] = array(
			'file'  => $file,
			'name'  => $safe,
			'kind'  => $kind,
			'pages' => $pages,
			'bytes' => (int) filesize( $dest ),
			'added' => gmdate( 'c' ),
		);
		$meta['updated']   = gmdate( 'c' );
		self::write_meta( $slug, $meta );
		self::reset();

		return (array) self::get( $slug );
	}

	/**
	 * The library built from every export it has (Layout_Builder): its library.json and blocks written again, the report kept.
	 *
	 * @return array<string, mixed>|\WP_Error The library, with the report.
	 */
	public static function build( string $slug ) {
		$lib = self::get( $slug );
		if ( $lib === null ) {
			return new \WP_Error( 'dxai_ui_library_none', __( 'There is no such library.', 'dxai-ui' ) );
		}
		$pages = array();
		$names = array();
		foreach ( (array) $lib['sources'] as $source ) {
			$file = self::dir( $slug ) . '/exports/' . basename( (string) ( $source['file'] ?? '' ) );
			if ( ! is_readable( $file ) ) {
				continue;
			}
			if ( (string) ( $source['kind'] ?? '' ) === 'wxr' ) {
				foreach ( Layout_Builder::read_wxr( $file ) as $p ) {
					$pages[] = $p;
					// The site's own name, as the export says it: in a kept line it becomes the company's. The builder names a site by its
					// host without the ending (`site`), so the names are keyed that way.
					$host  = (string) $p['site'];
					$title = trim( (string) ( $p['site_title'] ?? '' ) );
					if ( $title !== '' ) {
						$names[ $host ] = array_values( array_unique( array_merge( (array) ( $names[ $host ] ?? array() ), array( $title ), preg_match( '/^(\w{4,})\b/u', $title, $m ) === 1 ? array( $m[1] ) : array() ) ) );
					}
				}
			} else {
				$pages[] = Layout_Builder::read_markup( $file, $slug, (string) ( $source['name'] ?? '' ) );
			}
		}
		if ( $pages === array() ) {
			return new \WP_Error( 'dxai_ui_library_empty', __( 'The library has no export to build from.', 'dxai-ui' ) );
		}
		$names[ $slug ] = array_values( array_unique( array_merge( (array) ( $names[ $slug ] ?? array() ), array( (string) $lib['label'] ) ) ) );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$result = Layout_Builder::build(
			$pages,
			array(
				'out_dir'       => self::dir( $slug ),
				'prefix'        => $slug,
				'label_prefix'  => (string) $lib['label'],
				'themes'        => (array) $lib['themes'],
				'company_names' => $names,
				'max'           => 8,
			)
		);
		$meta           = self::read_meta( $slug );
		$meta['report'] = array(
			'made'   => (int) $result['made'],
			'errors' => array_values( (array) $result['errors'] ),
			'pages'  => count( $pages ),
			'lines'  => array_values( array_slice( (array) $result['lines'], -60 ) ),
			'at'     => gmdate( 'c' ),
		);
		$meta['updated'] = gmdate( 'c' );
		self::write_meta( $slug, $meta );
		self::reset();
		Block_Library::forget();

		return (array) self::get( $slug );
	}

	/** A library and everything in it, gone. Only a stored one, only inside the libraries folder. */
	public static function delete( string $slug ): bool {
		$slug = sanitize_key( $slug );
		$dir  = self::dir( $slug );
		if ( $slug === '' || self::get( $slug ) === null || $dir === '' || ! str_starts_with( wp_normalize_path( $dir ), wp_normalize_path( self::dir() ) . '/' ) ) {
			return false;
		}
		self::rm( $dir );
		self::reset();
		Block_Library::forget();

		return ! is_dir( $dir );
	}

	/** The layouts a library holds: id, role, label, blocks, and how many of its slots keep the team's words. */
	public static function layouts( string $slug ): array {
		$lib = self::get( $slug );
		if ( $lib === null ) {
			return array();
		}
		$doc = is_readable( $lib['dir'] . 'library.json' ) ? json_decode( (string) file_get_contents( $lib['dir'] . 'library.json' ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$out = array();
		foreach ( is_array( $doc ) ? (array) ( $doc['entries'] ?? array() ) : array() as $row ) {
			$markup = is_readable( $lib['dir'] . 'blocks/' . (string) $row['id'] . '.html' ) ? (string) file_get_contents( $lib['dir'] . 'blocks/' . (string) $row['id'] . '.html' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$out[]  = array(
				'id'     => (string) $row['id'],
				'role'   => (string) ( $row['role'] ?? '' ),
				'label'  => (string) ( $row['label'] ?? '' ),
				'list'   => (string) ( $row['list'] ?? '' ),
				'blocks' => array_values( (array) ( $row['blocks'] ?? array() ) ),
				'slots'  => preg_match_all( '/\{\{t:(?!call)[a-z0-9-]+(?:\||\}\})/', $markup ),
				'kept'   => preg_match_all( '/\{\{t:(?!call)[a-z0-9-]+\|/', $markup ),
				'bytes'  => strlen( $markup ),
			);
		}

		return $out;
	}

	/** @return array<string, mixed> */
	private static function read_meta( string $slug ): array {
		$file = self::dir( $slug ) . '/template.json';
		$meta = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return is_array( $meta ) ? $meta : array( 'slug' => $slug, 'sources' => array() );
	}

	/** @param array<string, mixed> $meta */
	private static function write_meta( string $slug, array $meta ): void {
		file_put_contents( self::dir( $slug ) . '/template.json', wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/** A folder and everything under it, removed. */
	private static function rm( string $dir ): void {
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( $name === '.' || $name === '..' ) {
				continue;
			}
			$path = $dir . '/' . $name;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::rm( $path );
			} else {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}

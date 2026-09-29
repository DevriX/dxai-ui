<?php
/**
 * Where a file the plugin wrote under uploads lives, as a URL and on disk.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Support;

/**
 * One answer to "where is this page's stylesheet", whatever form it was
 * stored in.
 *
 * `_dxai_ui_css_url` and `_dxai_ui_js_url` used to hold ABSOLUTE URLs — on
 * this install all 1002 css rows and 35 js rows began
 * `http://dxai-ui.local/wp-content/uploads/` — and ten places turned such a
 * URL back into a file with `str_replace( baseurl, basedir, $url )`. That is
 * a guess that holds only while nothing about the site changes. Move it from
 * staging to production, from http to https, behind a multisite domain
 * mapping, or let a CDN/offload plugin filter `upload_dir`, and the stored
 * URL no longer starts with the current baseurl: str_replace() replaces
 * nothing, returns the URL unchanged, and `is_readable()` on a URL is false.
 * Nothing reports it. Token_Styles::tokenize_import() skips a sheet it cannot
 * find, so the design's colours silently vanish, and every filemtime()
 * cache-buster falls back to the plugin version, so a CDN keeps serving the
 * stale sheet after a re-import.
 *
 * So the plugin stores the part AFTER the uploads base — `dxai-ui/pattern-
 * 123.css` — and builds the URL and the path from whatever `wp_upload_dir()`
 * says right now. Values stored before this change are still absolute URLs;
 * relative() maps those too, including ones whose scheme and host belong to
 * the site's previous address.
 *
 * Pure: reads the upload directory, post meta and file times, and writes
 * nothing. `wp_get_upload_dir()` rather than `wp_upload_dir()` throughout —
 * the latter creates this month's `YYYY/MM` directory as a side effect of
 * being asked where uploads are.
 */
final class Upload_Paths {

	/** The plugin's own directory inside uploads. */
	public const DIR = 'dxai-ui';

	/**
	 * The location relative to the uploads base, e.g. `dxai-ui/pattern-123.css`.
	 *
	 * Accepts an absolute URL of any scheme and host (or protocol-relative), a
	 * root-relative URL path, a filesystem path, or a value that is already
	 * relative. A query string or fragment is dropped — older rows sometimes
	 * carried `?ver=`. Returns '' when the value names nothing under uploads,
	 * or when the result would climb out of it (a `..` segment).
	 *
	 * Meant for locations the plugin wrote itself. A foreign URL whose path
	 * happens to start with this site's uploads path maps too, to a file that
	 * need not exist; callers check the path before reading it.
	 */
	public static function relative( string $url_or_path ): string {
		return self::relative_in( $url_or_path, self::uploads() );
	}

	/**
	 * The public URL of an uploads-relative location, through set_url_scheme()
	 * so an https page never asks for an http stylesheet. Also accepts any form
	 * relative() does. '' when there is nothing to point at.
	 */
	public static function url( string $relative ): string {
		$uploads  = self::uploads();
		$relative = self::relative_in( $relative, $uploads );

		return $relative === '' || $uploads['baseurl'] === '' ? '' : self::scheme( $uploads['baseurl'] . '/' . $relative );
	}

	/**
	 * The file on this disk, normalised with wp_normalize_path() — on Windows
	 * `basedir` arrives with mixed separators (`...\app\public/wp-content/uploads`).
	 * Also accepts any form relative() does. '' when there is nothing to read.
	 */
	public static function path( string $relative ): string {
		$uploads  = self::uploads();
		$relative = self::relative_in( $relative, $uploads );

		return $relative === '' || $uploads['basedir'] === '' ? '' : wp_normalize_path( $uploads['basedir'] . '/' . $relative );
	}

	/**
	 * A stored location, in both forms, whichever form it was stored in.
	 *
	 * A value that maps to nothing under uploads but is an http(s) URL comes
	 * back as `url` with an empty `path`: an enqueue can still use it, and
	 * nothing on this disk is known to hold it.
	 *
	 * @return array{url:string, path:string, relative:string}
	 */
	public static function for_meta( int $post_id, string $key ): array {
		$stored   = $post_id > 0 ? trim( (string) get_post_meta( $post_id, $key, true ) ) : '';
		$uploads  = self::uploads();
		$relative = $stored === '' ? '' : self::relative_in( $stored, $uploads );
		if ( $relative === '' ) {
			return array(
				'url'      => preg_match( '#^https?://#i', $stored ) ? esc_url_raw( $stored ) : '',
				'path'     => '',
				'relative' => '',
			);
		}

		return array(
			'url'      => $uploads['baseurl'] === '' ? '' : self::scheme( $uploads['baseurl'] . '/' . $relative ),
			'path'     => $uploads['basedir'] === '' ? '' : wp_normalize_path( $uploads['basedir'] . '/' . $relative ),
			'relative' => $relative,
		);
	}

	/**
	 * What a writer stores: the relative form.
	 *
	 * An absolute URL relative() cannot place under uploads (a foreign host
	 * with no uploads path in it) keeps the old treatment, esc_url_raw(); a
	 * scheme-less value is taken as relative already. esc_url_raw() must never
	 * see the relative form: it reads `dxai-ui/pattern-1.css` as a host name
	 * and returns `http://dxai-ui/pattern-1.css`.
	 */
	public static function for_storage( string $url_or_path ): string {
		$relative = self::relative( $url_or_path );
		if ( $relative !== '' ) {
			return $relative;
		}

		return preg_match( '#^https?://#i', trim( $url_or_path ) ) ? esc_url_raw( trim( $url_or_path ) ) : '';
	}

	/**
	 * Cache-buster for a resolved file: its mtime, so a re-import changes the
	 * URL a CDN or browser has cached. The plugin version only when the file
	 * cannot be read here.
	 */
	public static function version( string $path ): string {
		$time = $path !== '' && is_readable( $path ) ? filemtime( $path ) : false;
		if ( $time !== false ) {
			return (string) $time;
		}

		return defined( 'DXAI_UI_VERSION' ) ? (string) DXAI_UI_VERSION : '';
	}

	/**
	 * The current upload directory, reduced to what the mapping compares
	 * against. Not memoised: `upload_dir` is filtered, and on a multisite
	 * switch_to_blog() changes the answer mid-request.
	 *
	 * @return array{basedir:string, baseurl:string, base_path:string, host:string}
	 */
	private static function uploads(): array {
		$dir     = wp_get_upload_dir();
		$basedir = (string) ( $dir['basedir'] ?? '' );
		$baseurl = untrailingslashit( (string) ( $dir['baseurl'] ?? '' ) );

		return array(
			'basedir'   => $basedir === '' ? '' : untrailingslashit( wp_normalize_path( $basedir ) ),
			'baseurl'   => $baseurl,
			'base_path' => untrailingslashit( (string) wp_parse_url( $baseurl, PHP_URL_PATH ) ),
			'host'      => strtolower( (string) wp_parse_url( $baseurl, PHP_URL_HOST ) ),
		);
	}

	/**
	 * @param array{basedir:string, baseurl:string, base_path:string, host:string} $uploads
	 */
	/**
	 * The URL on https when this request is, and otherwise as the uploads
	 * base spells it. set_url_scheme() without a scheme also DOWNGRADES an
	 * https base to http when is_ssl() is false — under WP-CLI, or behind a
	 * TLS-terminating proxy that does not pass HTTPS on — and a stored or
	 * printed http:// URL for an https site is mixed content.
	 */
	private static function scheme( string $url ): string {
		return is_ssl() ? set_url_scheme( $url, 'https' ) : $url;
	}

	private static function relative_in( string $value, array $uploads ): string {
		$value = trim( $value );
		if ( $value === '' ) {
			return '';
		}
		$is_url = (bool) preg_match( '#^(?:[a-z][a-z0-9+.-]*:)?//#i', $value );
		// A scheme without "//" — javascript:, data:, mailto: — is never a
		// location in uploads. (A Windows drive letter is a path, not a scheme.)
		if ( ! $is_url && preg_match( '#^[a-z][a-z0-9+.-]*:#i', $value ) && ! preg_match( '#^[A-Za-z]:[\\\\/]#', $value ) ) {
			return '';
		}

		/*
		 * A path on this disk, under this site's uploads. Tested before the
		 * query string is cut, because `?` and `#` are legal in a directory
		 * name and a basedir containing one must still match.
		 *
		 * A stream-wrapper basedir counts as a disk too: S3 Uploads and its
		 * kind filter `upload_dir` to `s3://bucket/uploads`, and a path under
		 * it looks like a URL to the test above.
		 */
		if ( $uploads['basedir'] !== '' && ( ! $is_url || wp_is_stream( $uploads['basedir'] ) ) ) {
			$as_path = wp_normalize_path( $value );
			if ( str_starts_with( $as_path, $uploads['basedir'] . '/' ) ) {
				return self::clean( substr( $as_path, strlen( $uploads['basedir'] ) + 1 ) );
			}
		}

		$value = (string) preg_replace( '/[?#].*$/s', '', $value );
		if ( $is_url ) {
			$path = (string) wp_parse_url( $value, PHP_URL_PATH );
			/*
			 * Uploads served from the root of their own host — a CDN baseurl
			 * such as `https://cdn.example.com` has no path to anchor on, so
			 * the host has to match instead.
			 */
			if ( $uploads['base_path'] === '' && $uploads['host'] !== '' && strtolower( (string) wp_parse_url( $value, PHP_URL_HOST ) ) === $uploads['host'] ) {
				return self::clean( $path );
			}

			$relative = self::from_url_path( $path, $uploads['base_path'] );
			/*
			 * The relative form after esc_url_raw() has been at it: esc_url_raw(
			 * 'dxai-ui/pattern-1.js' ) reads the directory as a host and returns
			 * `http://dxai-ui/pattern-1.js`. bin/re-restyle-live-pages.php and
			 * bin/fix-home-and-empty-pages.php copy a Home's meta onto its child
			 * pages exactly that way, and any other code that "sanitises" the
			 * value as a URL would too. Only when nothing above placed it, so a
			 * site that really is served from a host called `dxai-ui` still maps
			 * its own absolute URLs through the uploads anchor.
			 */
			if ( $relative === '' && strtolower( (string) wp_parse_url( $value, PHP_URL_HOST ) ) === self::DIR ) {
				$relative = self::clean( self::DIR . $path );
			}

			return $relative;
		}

		// A root-relative URL, or a filesystem path from a disk this site no
		// longer lives on (the host changed, the path did not follow).
		if ( str_starts_with( $value, '/' ) || str_starts_with( $value, '\\' ) || preg_match( '#^[A-Za-z]:[\\\\/]#', $value ) ) {
			return self::from_url_path( wp_normalize_path( $value ), $uploads['base_path'] );
		}

		return self::clean( $value );
	}

	/**
	 * The uploads-relative part of a URL path, ignoring its scheme and host.
	 *
	 * The plugin's own directory is the anchor tried first, and deliberately
	 * ahead of the current base path: a network subsite exported to a single
	 * site stored `/wp-content/uploads/sites/3/dxai-ui/…`, and migration tools
	 * flatten `sites/3/` away, so the base-path match would yield
	 * `sites/3/dxai-ui/…` — a file that is no longer there. Anchoring on
	 * `/uploads/[sites/N/]dxai-ui/` yields `dxai-ui/…` on either side of such
	 * a move, and cannot be fooled by a site installed in a directory that is
	 * itself called `dxai-ui`. Then the current base path, which covers a
	 * custom UPLOADS location for anything else. Then the last `/dxai-ui/` in
	 * the path, for a custom location on the previous host.
	 */
	private static function from_url_path( string $path, string $base_path ): string {
		$dir = preg_quote( self::DIR, '#' );
		if ( preg_match( '#/uploads/(?:sites/\d+/)?(' . $dir . '/.+)$#', $path, $m ) ) {
			return self::clean( $m[1] );
		}
		if ( $base_path !== '' && str_starts_with( $path, $base_path . '/' ) ) {
			return self::clean( substr( $path, strlen( $base_path ) + 1 ) );
		}
		$at = strrpos( $path, '/' . self::DIR . '/' );

		return $at === false ? '' : self::clean( substr( $path, $at + 1 ) );
	}

	/**
	 * Forward slashes, no leading `/` or `./`, and nothing that climbs out
	 * of uploads: path() concatenates this onto basedir, so a stored
	 * `../../wp-config.php` must come back empty rather than readable.
	 */
	private static function clean( string $relative ): string {
		$relative = (string) preg_replace( '#/{2,}#', '/', str_replace( '\\', '/', $relative ) );
		$relative = (string) preg_replace( '#^(?:\.?/)+#', '', $relative );
		if ( $relative === '' || str_contains( $relative, "\0" ) || in_array( '..', explode( '/', $relative ), true ) ) {
			return '';
		}

		return $relative;
	}
}

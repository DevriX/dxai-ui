<?php
/**
 * The theme's inline stylesheet, cut down to what the page can use.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Theme;

use DXAI_UI\Structures\Design_Attach;
use DXAI_UI\Support\Css_Trim;
use DXAI_UI\Support\Upload_Paths;

/**
 * A DevriX theme prints its whole stylesheet inline in every page's head (american-restoration: 1.1 MB, 1.9 MB once
 * Theme_Fence has fenced it), and a page with a design on it uses about 2% of that. The browser parses all of it
 * before the first paint: on a phone that was most of the first-paint time of the Semper Dry pages (83 in
 * PageSpeed's mobile score, 92-99 with the sheet cut down).
 *
 * The page is buffered, and the finished document decides: Css_Trim keeps the rules its classes, ids, element names
 * and scripts can match, and the block is replaced by that. What is kept is a subset of the sheet in its own order,
 * and nothing that needs a state (hover, focus, an open menu, a class a script adds) counts against a rule, so the
 * page renders as it did.
 *
 * The result depends only on the page's tokens that the sheet has a name for (Css_Trim::vocabulary()), so it is
 * cached by exactly those, in uploads/dxai-ui/trim/<sheet>/: a request that finds its file only reads the page for
 * its tokens, and a page whose markup changes (a new post in a list, a menu item) is trimmed again on its own.
 * Nothing here is content: the files are made again whenever they are missing. Every doubt serves the theme's
 * whole sheet as before — an unreadable sheet, a document flushed in pieces, a cache that cannot be written, a
 * page whose tokens keep changing.
 *
 * Only for a visitor who is not logged in (the admin bar and the editor's tools are not what a visitor sees), on a
 * page that shows a design and still carries the theme's stylesheet (a design page on the blank template carries
 * none). `dxai_ui_trim_theme_css` (bool, post id) and the setting `dxai_ui_trim_theme` turn it off.
 */
final class Theme_Trim {

	public const OPTION = 'dxai_ui_trim_theme';

	/** Where the last results are kept for the Library's account of it: page id => sizes. */
	public const STATS = 'dxai_ui_trim_stats';

	/** A style block is looked at from this size (bytes). */
	private const MIN_BYTES = 131072;

	/** Results kept per sheet. */
	private const KEEP = 24;

	/** More tokens sets than this for one page within an hour is a page that changes on every request: it is left alone. */
	private const CHURN = 6;

	public function register(): void {
		// Before the theme's own buffers start (its font module strips markup from the finished page), so the finished page reaches this one.
		add_action( 'template_redirect', array( self::class, 'start' ), -10 );
	}

	/** Whether the visitor's page is trimmed: on unless the setting turns it off. */
	public static function enabled(): bool {
		return (string) get_option( self::OPTION, '1' ) !== '0';
	}

	/** Whether this request is a page to trim. */
	public static function applies(): bool {
		if ( ! self::enabled() || is_admin() || ! is_singular() || is_user_logged_in() || is_preview() || is_customize_preview() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		if ( strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) !== 'GET' ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return false;
		}
		$id = (int) get_queried_object_id();
		if ( $id < 1 || Design_Attach::source_for( $id ) < 1 || Theme_Compat::is_converted_page() ) {
			return false;
		}

		return (bool) apply_filters( 'dxai_ui_trim_theme_css', true, $id );
	}

	public static function start(): void {
		if ( self::applies() ) {
			ob_start( array( self::class, 'filter' ) );
		}
	}

	/**
	 * The output callback: the finished document with its big inline stylesheet trimmed.
	 *
	 * @param mixed $html
	 * @param int   $phase
	 * @return mixed
	 */
	public static function filter( $html, $phase = 0 ) {
		// Only a document that arrives whole: a page flushed in pieces has its head out before its body is known.
		$whole = ( $phase & PHP_OUTPUT_HANDLER_START ) && ( $phase & PHP_OUTPUT_HANDLER_FINAL );
		$min   = max( 16384, (int) apply_filters( 'dxai_ui_trim_min_bytes', self::MIN_BYTES ) );
		if ( ! $whole || ! is_string( $html ) || strlen( $html ) < $min || stripos( $html, '<style' ) === false || stripos( $html, '</html>' ) === false ) {
			return $html;
		}
		try {
			$out = self::rewrite( $html, $min );
		} catch ( \Throwable $e ) {
			return $html;
		}

		return is_string( $out ) && $out !== '' ? $out : $html;
	}

	/** The document with each big inline stylesheet replaced by the part of it the page can use. */
	private static function rewrite( string $html, int $min ): ?string {
		$blocks = array();
		$pos    = 0;
		while ( ( $open = stripos( $html, '<style', $pos ) ) !== false ) {
			$gt    = strpos( $html, '>', $open );
			$close = $gt === false ? false : stripos( $html, '</style>', $gt );
			if ( $gt === false || $close === false ) {
				break;
			}
			if ( $close - $gt - 1 >= $min && preg_match( '/^<style(?:\s|>)/i', substr( $html, $open, 8 ) ) === 1 ) {
				$blocks[] = array( $gt + 1, $close - $gt - 1 );
			}
			$pos = $close + 8;
		}
		if ( $blocks === array() ) {
			return null;
		}
		// The page without its sheets and script bodies: the markup its tokens come from.
		$rest = $html;
		foreach ( array_reverse( $blocks ) as $b ) {
			$rest = substr_replace( $rest, '', $b[0], $b[1] );
		}
		$styles = array();
		$bare   = self::cut( $rest, 'style', $styles );
		$inline = array();
		$srcs   = array();
		if ( preg_match_all( '/<script\b[^>]*?\ssrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $bare, $m ) ) {
			$srcs = array_values( array_filter( array_merge( $m[1], $m[2] ) ) );
		}
		$bare  = self::cut( $bare, 'script', $inline );
		$files = array();
		foreach ( $srcs as $src ) {
			$path = self::script_path( html_entity_decode( $src, ENT_QUOTES | ENT_HTML5 ) );
			if ( $path !== '' ) {
				$files[ $path ] = true;
			}
		}
		$files = array_keys( $files );
		$page  = Css_Trim::tokens( $bare );

		$out = $html;
		foreach ( array_reverse( $blocks ) as $b ) {
			$css     = substr( $html, $b[0], $b[1] );
			$trimmed = self::trimmed( $css, $bare, $page, $inline, $files );
			if ( $trimmed !== null ) {
				$out = substr_replace( $out, $trimmed, $b[0], $b[1] );
			}
		}

		return $out === $html ? null : $out;
	}

	/**
	 * One sheet trimmed for this page: its cached result, or a new one; null to leave the sheet as it is.
	 *
	 * @param array<string, array<string, true>> $page   Css_Trim::tokens() of the markup.
	 * @param array<int, string>                 $inline Bodies of the page's inline scripts.
	 * @param array<int, string>                 $files  Paths of the scripts the page loads from this site.
	 */
	private static function trimmed( string $css, string $bare, array $page, array $inline, array $files ): ?string {
		$sheet = substr( md5( $css ), 0, 12 );
		$dir   = Upload_Paths::path( Upload_Paths::DIR . '/trim/' . $sheet );
		if ( $dir === '' || ! wp_mkdir_p( $dir ) ) {
			return null;
		}
		$vocab = self::vocabulary( $dir, $css );
		if ( $vocab === null ) {
			return null;
		}
		// What the page says that the sheet has a name for: the key of the result.
		$words = self::script_words( $inline, $files, $vocab, $dir );
		$named = self::named( $page, $words, $vocab );
		$key   = sha1( Css_Trim::VERSION . "\n" . implode( "\n", $named ) );
		$file  = $dir . '/' . $key . '.css';
		if ( is_readable( $file ) ) {
			$cached = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( is_string( $cached ) && $cached !== '' ) {
				return $cached;
			}
		}
		if ( self::churning( $key ) || ! self::room( strlen( $css ) ) ) {
			return null;
		}
		$full = Css_Trim::tokens( $bare, self::script_texts( $inline, $files ) );
		$cut  = Css_Trim::trim( $css, $full, $stats );
		if ( $cut === null || $cut === '' || strlen( $cut ) > strlen( $css ) * 0.9 ) {
			return null;
		}
		$cut = "\n/* DXAI-UI: the part of the theme's stylesheet this page can use (" . $stats['kept'] . ' of ' . $stats['rules'] . ' rules, ' . strlen( $cut ) . ' of ' . strlen( $css ) . " bytes) */\n" . $cut;
		if ( ! self::write( $file, $cut ) ) {
			return null;
		}
		self::remember( strlen( $css ), strlen( $cut ), $stats );
		self::tidy( $dir );

		return $cut;
	}

	/**
	 * The tokens of the page that the sheet has a name for, as one sorted list.
	 *
	 * @param array<string, array<string, true>>              $page
	 * @param array{w: array<int, string>, p: array<int, string>} $words
	 * @param array{v: array<string, true>, p: array<string, true>} $vocab
	 * @return array<int, string>
	 */
	private static function named( array $page, array $words, array $vocab ): array {
		$out = array();
		foreach ( array( 'classes' => 'c', 'ids' => 'i', 'tags' => 't', 'words' => 'w' ) as $kind => $tag ) {
			foreach ( $page[ $kind ] as $name => $_ ) {
				if ( isset( $vocab['v'][ (string) $name ] ) ) {
					$out[] = $tag . ':' . $name;
				}
			}
		}
		foreach ( $page['prefixes'] as $name => $_ ) {
			if ( isset( $vocab['p'][ (string) $name ] ) ) {
				$out[] = 'p:' . $name;
			}
		}
		foreach ( $words['w'] as $name ) {
			$out[] = 'w:' . $name;
		}
		foreach ( $words['p'] as $name ) {
			$out[] = 'p:' . $name;
		}
		$out = array_unique( $out );
		sort( $out, SORT_STRING );

		return $out;
	}

	/**
	 * The words of the page's scripts that the sheet has a name for, and the prefixes they build classes from that
	 * the sheet has classes with. A script file's are kept beside the results, by its size and time.
	 *
	 * @param array<int, string>                                    $inline
	 * @param array<int, string>                                    $files
	 * @param array{v: array<string, true>, p: array<string, true>} $vocab
	 * @return array{w: array<int, string>, p: array<int, string>}
	 */
	private static function script_words( array $inline, array $files, array $vocab, string $dir ): array {
		$w = array();
		$p = array();
		foreach ( $inline as $text ) {
			$found = self::named_in( (string) $text, $vocab );
			$w     = array_merge( $w, $found['w'] );
			$p     = array_merge( $p, $found['p'] );
		}
		foreach ( $files as $path ) {
			$note  = $dir . '/w-' . sha1( $path ) . '-' . (int) filesize( $path ) . '-' . (int) filemtime( $path ) . '.json';
			$known = is_readable( $note ) ? json_decode( (string) file_get_contents( $note ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! is_array( $known ) || ! isset( $known['w'], $known['p'] ) ) {
				$known = self::named_in( (string) file_get_contents( $path ), $vocab ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				self::write( $note, (string) wp_json_encode( $known ) );
			}
			$w = array_merge( $w, array_map( 'strval', (array) $known['w'] ) );
			$p = array_merge( $p, array_map( 'strval', (array) $known['p'] ) );
		}

		return array( 'w' => array_values( array_unique( $w ) ), 'p' => array_values( array_unique( $p ) ) );
	}

	/**
	 * The words of one script that the sheet has a name for, and its prefixes the sheet has classes with.
	 *
	 * @param array{v: array<string, true>, p: array<string, true>} $vocab
	 * @return array{w: array<int, string>, p: array<int, string>}
	 */
	private static function named_in( string $text, array $vocab ): array {
		$tok = Css_Trim::tokens( '', array( $text ) );
		$w   = array();
		$p   = array();
		foreach ( $tok['words'] as $word => $_ ) {
			if ( isset( $vocab['v'][ (string) $word ] ) ) {
				$w[] = (string) $word;
			}
		}
		foreach ( $tok['prefixes'] as $prefix => $_ ) {
			if ( isset( $vocab['p'][ (string) $prefix ] ) ) {
				$p[] = (string) $prefix;
			}
		}

		return array( 'w' => $w, 'p' => $p );
	}

	/**
	 * The text of the page's scripts, for a sheet that is trimmed now.
	 *
	 * @param array<int, string> $inline
	 * @param array<int, string> $files
	 * @return array<int, string>
	 */
	private static function script_texts( array $inline, array $files ): array {
		$texts = $inline;
		foreach ( $files as $path ) {
			$texts[] = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		return $texts;
	}

	/**
	 * The sheet's vocabulary, from its file beside the results, made once.
	 *
	 * @return array{v: array<string, true>, p: array<string, true>}|null
	 */
	private static function vocabulary( string $dir, string $css ): ?array {
		$file = $dir . '/vocabulary.json';
		if ( is_readable( $file ) ) {
			$known = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( is_array( $known ) && isset( $known['v'], $known['p'] ) ) {
				return array( 'v' => array_fill_keys( array_map( 'strval', $known['v'] ), true ), 'p' => array_fill_keys( array_map( 'strval', $known['p'] ), true ) );
			}
		}
		if ( ! self::room( strlen( $css ) ) ) {
			return null;
		}
		$vocab = Css_Trim::vocabulary( $css );
		if ( ! self::write( $file, (string) wp_json_encode( array( 'v' => array_map( 'strval', array_keys( $vocab['v'] ) ), 'p' => array_map( 'strval', array_keys( $vocab['p'] ) ) ) ) ) ) {
			return null;
		}

		return $vocab;
	}

	/**
	 * A tag's bodies taken out of the markup: the document without them, the bodies in $bodies.
	 *
	 * @param array<int, string> $bodies
	 */
	private static function cut( string $html, string $tag, array &$bodies ): string {
		$out = '';
		$pos = 0;
		while ( ( $open = stripos( $html, '<' . $tag, $pos ) ) !== false ) {
			$gt    = strpos( $html, '>', $open );
			$close = $gt === false ? false : stripos( $html, '</' . $tag . '>', $gt );
			if ( $gt === false || $close === false || preg_match( '/^<' . $tag . '(?:\s|>)/i', substr( $html, $open, strlen( $tag ) + 2 ) ) !== 1 ) {
				$out .= substr( $html, $pos, $open + 1 - $pos );
				$pos  = $open + 1;
				continue;
			}
			$out     .= substr( $html, $pos, $gt + 1 - $pos );
			$bodies[] = substr( $html, $gt + 1, $close - $gt - 1 );
			$pos      = $close;
		}

		return $out . substr( $html, $pos );
	}

	/** The file a script URL is served from: a .js file under this site's content or core directory; '' otherwise. */
	private static function script_path( string $url ): string {
		$bare = static fn( string $u ): string => (string) preg_replace( '#^[a-z]+:#i', '', (string) preg_replace( '#[?\#].*$#', '', $u ) );
		$url  = $bare( $url );
		if ( ! str_ends_with( strtolower( $url ), '.js' ) ) {
			return '';
		}
		$pairs = array(
			array( content_url(), WP_CONTENT_DIR ),
			array( includes_url(), ABSPATH . WPINC ),
			array( site_url(), ABSPATH ),
		);
		foreach ( $pairs as $pair ) {
			$base = untrailingslashit( $bare( (string) $pair[0] ) );
			if ( $base === '' || ! str_starts_with( $url, $base . '/' ) ) {
				continue;
			}
			$root = realpath( (string) $pair[1] );
			$file = realpath( (string) $pair[1] . substr( $url, strlen( $base ) ) );
			if ( $root === false || $file === false || ! is_readable( $file ) || filesize( $file ) > 8 * MB_IN_BYTES ) {
				return '';
			}
			$root = wp_normalize_path( $root );
			$file = wp_normalize_path( $file );

			return str_starts_with( $file, trailingslashit( $root ) ) ? $file : '';
		}

		return '';
	}

	/** Whether there is memory for a pass over a sheet of this size (the walk holds it, its copy and the result). */
	private static function room( int $bytes ): bool {
		$limit = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ) : 0;
		if ( $limit <= 0 ) {
			return true;
		}

		return memory_get_usage( true ) + $bytes * 6 < $limit;
	}

	/**
	 * Whether this page keeps producing new results: five within the hour and it is a page that changes on every
	 * request (a token that differs each time), for which trimming again each time costs more than it saves.
	 */
	private static function churning( string $key ): bool {
		$id    = (int) get_queried_object_id();
		$label = 'dxai_ui_trim_seen_' . $id;
		$seen  = get_transient( $label );
		$seen  = is_array( $seen ) ? $seen : array();
		if ( in_array( $key, $seen, true ) ) {
			return false;
		}
		if ( count( $seen ) >= self::CHURN ) {
			return true;
		}
		$seen[] = $key;
		set_transient( $label, $seen, HOUR_IN_SECONDS );

		return false;
	}

	/** Write a cache file whole or not at all. */
	private static function write( string $path, string $data ): bool {
		$tmp = $path . '.' . uniqid( '', true ) . '.tmp';
		if ( false === @file_put_contents( $tmp, $data ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return false;
		}
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			wp_delete_file( $tmp );

			return is_readable( $path );
		}

		return true;
	}

	/**
	 * Results kept per sheet stay bounded, and the folders of sheets no longer printed (a theme update) go after two
	 * weeks. Everything here is made again when it is missing.
	 */
	private static function tidy( string $dir ): void {
		$files = (array) glob( $dir . '/*.css' );
		if ( count( $files ) > self::KEEP ) {
			usort( $files, static fn( $a, $b ) => (int) filemtime( (string) $b ) <=> (int) filemtime( (string) $a ) );
			foreach ( array_slice( $files, self::KEEP ) as $old ) {
				wp_delete_file( (string) $old );
			}
		}
		if ( get_transient( 'dxai_ui_trim_tidied' ) ) {
			return;
		}
		set_transient( 'dxai_ui_trim_tidied', 1, DAY_IN_SECONDS );
		foreach ( (array) glob( dirname( $dir ) . '/*', GLOB_ONLYDIR ) as $other ) {
			if ( is_string( $other ) && $other !== $dir && filemtime( $other ) < time() - 2 * WEEK_IN_SECONDS ) {
				foreach ( (array) glob( $other . '/*' ) as $f ) {
					if ( is_string( $f ) && is_file( $f ) ) {
						wp_delete_file( $f );
					}
				}
				@rmdir( $other ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			}
		}
	}

	/**
	 * What the last results were, for the Library: this page's sizes.
	 *
	 * @param array{rules:int,kept:int} $stats
	 */
	private static function remember( int $before, int $after, array $stats ): void {
		$id   = (int) get_queried_object_id();
		$all  = get_option( self::STATS, array() );
		$all  = is_array( $all ) ? $all : array();
		$all[ $id ] = array(
			'before' => $before,
			'after'  => $after,
			'rules'  => (int) $stats['rules'],
			'kept'   => (int) $stats['kept'],
			'at'     => time(),
		);
		if ( count( $all ) > 40 ) {
			uasort( $all, static fn( $a, $b ) => (int) ( $b['at'] ?? 0 ) <=> (int) ( $a['at'] ?? 0 ) );
			$all = array_slice( $all, 0, 40, true );
		}
		update_option( self::STATS, $all, false );
	}
}

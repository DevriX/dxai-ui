<?php
/**
 * Web fonts served from this site's uploads instead of Google's.
 *
 * @package DXAI_UI\Media
 */

declare(strict_types=1);

namespace DXAI_UI\Media;

use DXAI_UI\Support\Upload_Paths;
use DXAI_UI\Theme\Theme_Fonts;

/**
 * A design that loads Google Fonts asks for them in a chain: the page, then its stylesheet, whose first line is an
 * `@import` of Google's stylesheet, then the font files on another host. Nothing paints until the whole chain has
 * answered — on a phone, more than two seconds of the first paint of the Semper Dry pages. A DevriX theme also strips
 * the `<link>` of Google's stylesheet from its pages, so the design's fonts came through the `@import` or not at all.
 *
 * This copies the fonts here, once. The font files go to uploads/dxai-ui/fonts/, Google's stylesheet for them (with
 * the files' local names) is kept beside them, and the design's own stylesheet gets the `@font-face` rules in place
 * of its `@import` — so the browser needs the page and one stylesheet, from this host, and asks for a font file only
 * when a glyph is drawn in it. The rules are Google's own, subset by subset (`unicode-range`, `font-display`), so the
 * text is set in the same faces as before.
 *
 * Only Google Fonts, and only what Google's stylesheet names (files on fonts.gstatic.com). A file that cannot be
 * fetched keeps its own address, so a half-finished copy still renders and is finished later. What was replaced is
 * kept in a comment at the head of the sheet — the statements as they were, and the stylesheets the rules stand for —
 * which is how the page knows not to load Google's link as well, how the copy is undone (revert()), and how an export
 * gets the sheet as it was (portable()). Nothing here runs while a visitor waits: the copy is made when a design is
 * imported, by cron after the first view of a page that still asks Google, from the Library, or by
 * `wp dxai-ui speed apply`.
 */
final class Font_Host {

	public const HOOK = 'dxai_ui_localize_fonts';

	/** Cron: the files no sheet names any more go (after a design took the theme's fonts). */
	public const CLEAN_HOOK = 'dxai_ui_clean_fonts';

	/** Where the sheet's record of the copy begins and ends. */
	private const OPEN  = '/*! dxai-ui local fonts ';
	private const CLOSE = '/*! dxai-ui local fonts end */';

	/** An address on Google's font CDN, in a stylesheet's url(). */
	private const REMOTE = '#url\(\s*[\'"]?https://fonts\.gstatic\.com/#i';

	/** The line a hand-made fix of the Semper Dry sheets wrote above the font rules it put there (page speed, 2026-09-29). */
	private const HAND_MADE = 'Web fonts served from this site (fonts/), not from Google Fonts.';

	/** Seconds one run may spend fetching. */
	private const BUDGET = 30;

	public function register(): void {
		add_action( self::HOOK, array( self::class, 'localize_design' ) );
		add_action( self::CLEAN_HOOK, array( self::class, 'clean_now' ) );
	}

	/** Whether this is a Google Fonts stylesheet address. */
	public static function is_google( string $url ): bool {
		return preg_match( '#^https://fonts\.googleapis\.com/css2?\?#i', self::normal( $url ) ) === 1;
	}

	/** An address as Google reads it: entities decoded, a `;` a sanitizer wrote as %3B put back. */
	public static function normal( string $url ): string {
		return str_replace( array( '%3B', '%3b', '&#038;', '&amp;' ), array( ';', ';', '&', '&' ), trim( $url ) );
	}

	/** The name a stylesheet's copy is kept under. */
	public static function key( string $url ): string {
		return md5( self::normal( $url ) );
	}

	/**
	 * Google's stylesheet for these fonts with the files' local names, from this site's uploads: made now when it
	 * is missing (the files are fetched), refreshed when an earlier version left Google's own addresses in it.
	 *
	 * @return array{css:string, complete:bool, url:string}|null Null when Google could not be reached and there is no copy.
	 */
	public static function copy( string $url, int $deadline = 0 ): ?array {
		$url = self::normal( $url );
		if ( ! self::is_google( $url ) ) {
			return null;
		}
		$name = Upload_Paths::DIR . '/fonts/' . self::key( $url ) . '.css';
		$path = Upload_Paths::path( $name );
		if ( $path === '' ) {
			return null;
		}
		$deadline = $deadline > 0 ? $deadline : time() + self::BUDGET;
		$css      = is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( $css === '' || preg_match( self::REMOTE, $css ) === 1 ) {
			$css = $css !== '' ? $css : self::fetch_css( $url );
			if ( $css === '' ) {
				return null;
			}
			$css = self::fetch_files( $css, dirname( $path ), $deadline );
			if ( ! self::write( $path, $css ) ) {
				return null;
			}
		}

		return array(
			'css'      => $css,
			'complete' => preg_match( self::REMOTE, $css ) !== 1,
			'url'      => Upload_Paths::url( $name ),
		);
	}

	/**
	 * The address a front-end `<link>` to a font stylesheet should have: its finished copy here, or — while there is
	 * none — the original, with the copy scheduled.
	 */
	public static function link_url( string $url ): string {
		if ( ! self::is_google( $url ) ) {
			return $url;
		}
		$name = Upload_Paths::DIR . '/fonts/' . self::key( $url ) . '.css';
		$path = Upload_Paths::path( $name );
		if ( $path !== '' && is_readable( $path ) && filesize( $path ) > 0 && preg_match( self::REMOTE, (string) file_get_contents( $path ) ) !== 1 ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return Upload_Paths::url( $name );
		}
		self::queue_copy( $url );

		return $url;
	}

	/** Copy one stylesheet's fonts by cron (the cron event the plugin has always used for it). */
	public static function queue_copy( string $url ): void {
		$url = self::normal( $url );
		if ( self::is_google( $url ) && ! get_transient( 'dxai_ui_fonts_wait_' . self::key( $url ) ) && ! wp_next_scheduled( 'dxai_ui_copy_font_css', array( $url ) ) ) {
			set_transient( 'dxai_ui_fonts_wait_' . self::key( $url ), 1, 10 * MINUTE_IN_SECONDS );
			wp_schedule_single_event( time(), 'dxai_ui_copy_font_css', array( $url ) );
		}
	}

	/**
	 * A design's sheet still asks Google for its fonts: have cron put them in the sheet (at most once every few
	 * hours while Google cannot be reached).
	 */
	public static function queue_design( int $post_id ): void {
		if ( $post_id > 0 && ! get_transient( 'dxai_ui_fonts_wait_' . $post_id ) && ! wp_next_scheduled( self::HOOK, array( $post_id ) ) ) {
			set_transient( 'dxai_ui_fonts_wait_' . $post_id, 1, 10 * MINUTE_IN_SECONDS );
			wp_schedule_single_event( time(), self::HOOK, array( $post_id ) );
		}
	}

	/** Whether a sheet's fonts still come, in whole or in part, from Google. */
	public static function needs_work( string $path, array $listed = array() ): bool {
		return in_array( self::state( $path, $listed ), array( 'remote', 'partial' ), true );
	}

	/**
	 * At the end of an import: a design that asks Google for its fonts gets them here, as far as a few seconds allow
	 * (an import must not wait on another host); cron finishes what is left, the first time a page is viewed.
	 */
	public static function after_import( int $home ): void {
		/**
		 * Whether a design fresh from an import gets its fonts copied here (the default).
		 *
		 * @param bool $on   Copy.
		 * @param int  $home The design's Home.
		 */
		if ( $home < 1 || ! apply_filters( 'dxai_ui_fonts_on_import', true, $home ) ) {
			return;
		}
		try {
			$done = self::localize_design( $home, 8 );
		} catch ( \Throwable $e ) {
			return;
		}
		if ( ! $done['complete'] ) {
			self::queue_design( $home );
		}
	}

	/** Cron: the copy of one stylesheet. */
	public static function copy_now( $url ): void {
		self::copy( (string) $url );
	}

	/**
	 * Put the fonts of a design into its stylesheet: the `@import`s of Google's stylesheet become the `@font-face`
	 * rules with local files, and the stylesheets the design lists (`_dxai_ui_font_urls`) that its sheet does not
	 * import come with them. What the rules replace is kept in the record at their head, so unlocalize() gives the
	 * sheet back byte for byte.
	 *
	 * @param array<int, string> $extra Google stylesheet addresses the sheet should carry.
	 * @return array{changed:bool, complete:bool, urls:array<int, string>, note:string}
	 */
	public static function localize_sheet( string $path, array $extra = array(), int $budget = self::BUDGET ): array {
		$out = array( 'changed' => false, 'complete' => true, 'urls' => array(), 'note' => '' );
		if ( $path === '' || ! is_file( $path ) || ! is_readable( $path ) ) {
			$out['note'] = 'no sheet';

			return $out;
		}
		$original = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$css      = self::unlocalize( $original );
		$imports  = self::google_imports( $css );
		$urls     = array();
		foreach ( $imports as $import ) {
			$urls[ self::key( $import['url'] ) ] = self::normal( $import['url'] );
		}
		foreach ( $extra as $url ) {
			if ( self::is_google( (string) $url ) ) {
				$urls[ self::key( (string) $url ) ] = self::normal( (string) $url );
			}
		}
		if ( $urls === array() ) {
			$out['note'] = 'no Google fonts';

			return $out;
		}
		// A sheet whose fonts were already put in by hand (its rules name files in fonts/): only recorded, nothing added.
		if ( $imports === array() && str_contains( substr( $css, 0, 4096 ), self::HAND_MADE ) ) {
			$head = self::head_length( $css );
			$new  = substr( $css, 0, $head ) . self::OPEN . wp_json_encode( array( 'u' => array_values( $urls ), 'o' => '', 'c' => 1 ), JSON_UNESCAPED_SLASHES ) . " */\n" . self::CLOSE . "\n" . substr( $css, $head );
			$out['urls'] = array_values( $urls );
			if ( $new !== $original && self::write( $path, $new ) ) {
				$out['changed'] = true;
			}

			return $out;
		}
		$deadline = time() + max( 1, $budget );
		$rules    = '';
		$done     = array();
		foreach ( $urls as $url ) {
			$copy = self::copy( $url, $deadline );
			if ( $copy === null ) {
				$out['complete'] = false;
				continue;
			}
			$out['complete'] = $out['complete'] && $copy['complete'];
			$done[]          = $url;
			$rules          .= (string) preg_replace( '#url\(\s*([\'"]?)([0-9a-f]{20}\.(?:woff2|woff|ttf|otf))\1\s*\)#i', 'url(fonts/$2)', $copy['css'] ) . "\n";
		}
		if ( $done === array() ) {
			$out['note'] = 'Google could not be reached';

			return $out;
		}
		// The imports that were copied leave the sheet: they are one run at its head, with nothing but space between.
		$gone  = array_values( array_filter( $imports, static fn( $i ) => in_array( self::normal( $i['url'] ), $done, true ) ) );
		$start = strlen( $css );
		$end   = 0;
		foreach ( $gone as $i ) {
			$start = min( $start, $i['offset'] );
			$end   = max( $end, $i['offset'] + strlen( $i['statement'] ) );
		}
		$old = '';
		if ( $gone !== array() ) {
			$old  = substr( $css, $start, $end - $start );
			$rest = $old;
			foreach ( $gone as $i ) {
				$rest = str_replace( $i['statement'], '', $rest );
			}
			if ( trim( $rest ) !== '' || str_contains( $old, '*/' ) ) {
				$out['note']     = 'imports are not one run';
				$out['complete'] = false;

				return $out;
			}
		} else {
			$start = $end = self::head_length( $css );
		}
		$record = self::OPEN . wp_json_encode( array( 'u' => $done, 'o' => $old, 'c' => $out['complete'] ? 1 : 0 ), JSON_UNESCAPED_SLASHES ) . ' */';
		$new    = substr( $css, 0, $start ) . $record . "\n" . trim( $rules ) . "\n" . self::CLOSE . "\n" . substr( $css, $end );
		$out['urls'] = $done;
		if ( $new === $original ) {
			return $out;
		}
		if ( ! self::write( $path, $new ) ) {
			$out['note']     = 'not writable';
			$out['complete'] = false;

			return $out;
		}
		$out['changed'] = true;

		return $out;
	}

	/** A design's sheet, from its post: cron and import. */
	public static function localize_design( $post_id, int $budget = self::BUDGET ): array {
		$post_id = (int) $post_id;
		// A theme that lets the site choose its fonts has them already: the design's own are not copied (Theme_Fonts).
		if ( Theme_Fonts::adopts( $post_id ) ) {
			return self::adopt_design( $post_id );
		}
		$sheet   = Upload_Paths::for_meta( $post_id, '_dxai_ui_css_url' );
		if ( $sheet['path'] === '' ) {
			return array( 'changed' => false, 'complete' => true, 'urls' => array(), 'note' => 'no sheet' );
		}
		$listed = get_post_meta( $post_id, '_dxai_ui_font_urls', true );
		$result = self::localize_sheet( $sheet['path'], is_array( $listed ) ? array_map( 'strval', $listed ) : array(), $budget );
		if ( $result['complete'] ) {
			delete_transient( 'dxai_ui_fonts_wait_' . $post_id );
		} elseif ( $result['note'] !== 'no Google fonts' ) {
			set_transient( 'dxai_ui_fonts_wait_' . $post_id, 1, 6 * HOUR_IN_SECONDS );
		}

		return $result;
	}

	/** The sheet as it was before localize_sheet(): what the rules replaced back, the rules gone. */
	public static function unlocalize( string $css ): string {
		$span  = self::record_span( $css );
		$start = $span === null ? false : $span['start'];
		$close = $start === false ? false : strpos( $css, self::CLOSE, $start );
		$json  = $span === null ? false : $span['end'] - 3;
		if ( $start === false || $close === false || $json === false || $json > $close ) {
			return $css;
		}
		$record = $span['data'];
		$stop   = $close + strlen( self::CLOSE );
		if ( substr( $css, $stop, 1 ) === "\n" ) {
			++$stop;
		}

		return substr( $css, 0, $start ) . ( is_array( $record ) ? (string) ( $record['o'] ?? '' ) : '' ) . substr( $css, $stop );
	}

	/** Put a design's sheet back the way it was imported. */
	public static function revert( string $path ): bool {
		if ( $path === '' || ! is_file( $path ) || ! is_writable( $path ) ) {
			return false;
		}
		$css = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$old = self::unlocalize( $css );

		return $old === $css || self::write( $path, $old );
	}

	/**
	 * The stylesheets whose fonts a sheet carries: key => address.
	 *
	 * @return array<string, string>
	 */
	public static function covered( string $path ): array {
		if ( $path === '' || ! is_readable( $path ) ) {
			return array();
		}
		$span = self::record_of( $path );
		if ( $span === null ) {
			return array();
		}
		$record = $span['data'];
		$out    = array();
		foreach ( is_array( $record ) ? (array) ( $record['u'] ?? array() ) : array() as $url ) {
			$out[ self::key( (string) $url ) ] = (string) $url;
		}

		return $out;
	}

	/**
	 * Where a sheet stands: none (no Google fonts in it), remote (its fonts come from Google), partial (some files
	 * are still Google's), local.
	 */
	public static function state( string $path, array $listed = array() ): string {
		if ( $path === '' || ! is_readable( $path ) ) {
			return 'none';
		}
		$head    = (string) file_get_contents( $path, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$covered = self::covered( $path );
		$span    = self::record_of( $path );
		// Not the record of what was replaced, which quotes the statements it took out.
		if ( $span !== null ) {
			$rest = substr( $head, 0, $span['start'] ) . substr( (string) file_get_contents( $path, false, null, 0, $span['end'] + 8192 ), $span['end'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		} else {
			$open = strpos( $head, self::OPEN );
			$rest = $open === false ? $head : substr( $head, 0, $open ) . substr( $head, (int) strpos( $head . ' */', ' */', $open ) + 3 );
		}
		$asks    = preg_match( '#@import[^;]*fonts\.googleapis\.com#i', $rest ) === 1;
		foreach ( $listed as $url ) {
			if ( self::is_google( (string) $url ) && ! isset( $covered[ self::key( (string) $url ) ] ) ) {
				$asks = true;
			}
		}
		// The design uses the theme's fonts: nothing of its own is left in the sheet (adopt()).
		if ( $span !== null && is_array( $span['data'] ) && ! empty( $span['data']['t'] ) ) {
			return $asks ? 'partial' : 'theme';
		}
		if ( $covered === array() ) {
			return $asks ? 'remote' : 'none';
		}
		if ( $asks ) {
			return 'partial';
		}
		$at = strpos( $head, self::OPEN );

		return $at !== false && preg_match( '/"c":0/', substr( $head, $at, 4096 ) ) === 1 ? 'partial' : 'local';
	}

	/**
	 * Where a record sits in a sheet: from its OPEN to just after its closing ` * /`, and what it says. The JSON may hold
	 * ` * /` of the CSS it quotes, so the end is the first one after which the text is whole JSON.
	 *
	 * @return array{start:int, end:int, data:array<string, mixed>|null}|null
	 */
	private static function record_span( string $css ): ?array {
		$start = strpos( $css, self::OPEN );
		if ( $start === false ) {
			return null;
		}
		$from  = $start + strlen( self::OPEN );
		$pos   = $from;
		$first = null;
		for ( $tries = 0; $tries < 64 && ( $at = strpos( $css, ' */', $pos ) ) !== false; $tries++ ) {
			$data = json_decode( substr( $css, $from, $at - $from ), true );
			if ( is_array( $data ) ) {
				return array( 'start' => $start, 'end' => $at + 3, 'data' => $data );
			}
			$first = $first ?? $at;
			$pos   = $at + 3;
		}

		return $first === null ? null : array( 'start' => $start, 'end' => $first + 3, 'data' => null );
	}

	/** The record at the head of a sheet on disk: the first 8 KB, more when the record is longer than that. */
	private static function record_of( string $path ): ?array {
		foreach ( array( 8192, 262144 ) as $length ) {
			$head = (string) file_get_contents( $path, false, null, 0, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! str_contains( $head, self::OPEN ) ) {
				return null;
			}
			$span = self::record_span( $head );
			if ( $span !== null && is_array( $span['data'] ) ) {
				return $span;
			}
		}

		return null;
	}

	/** The state of a design's sheet (state()). */
	public static function design_state( int $post_id ): string {
		$sheet  = Upload_Paths::for_meta( $post_id, '_dxai_ui_css_url' );
		$listed = get_post_meta( $post_id, '_dxai_ui_font_urls', true );

		return self::state( (string) $sheet['path'], is_array( $listed ) ? array_map( 'strval', $listed ) : array() );
	}

	/**
	 * Take a sheet's own web fonts out: the `@import`s of Google's stylesheet, or the `@font-face` rules a copy of
	 * them put there (or a person did, by hand), because the theme draws the page in its own fonts (Theme_Fonts). What
	 * leaves is kept in the record at the head, as localize_sheet() keeps what it replaces, so unlocalize() gives the
	 * sheet back byte for byte and an export gets it as it was imported. The font files stay until nothing names them
	 * (clean_files()).
	 *
	 * @param array<int, string> $extra Google stylesheet addresses the design lists.
	 * @return array{changed:bool, complete:bool, urls:array<int, string>, note:string}
	 */
	public static function adopt( string $path, array $extra = array() ): array {
		$out = array( 'changed' => false, 'complete' => true, 'urls' => array(), 'note' => '' );
		if ( $path === '' || ! is_file( $path ) || ! is_readable( $path ) ) {
			$out['note'] = 'no sheet';

			return $out;
		}
		$original = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$css      = self::unlocalize( $original );
		$imports  = self::google_imports( $css );
		$urls     = array();
		foreach ( array_merge( array_column( $imports, 'url' ), array_values( self::covered( $path ) ), $extra ) as $url ) {
			if ( self::is_google( (string) $url ) ) {
				$urls[ self::key( (string) $url ) ] = self::normal( (string) $url );
			}
		}
		$start = 0;
		$end   = 0;
		$old   = '';
		if ( $imports !== array() ) {
			$start = strlen( $css );
			foreach ( $imports as $i ) {
				$start = min( $start, $i['offset'] );
				$end   = max( $end, $i['offset'] + strlen( $i['statement'] ) );
			}
			$old  = substr( $css, $start, $end - $start );
			$rest = $old;
			foreach ( $imports as $i ) {
				$rest = str_replace( $i['statement'], '', $rest );
			}
			if ( trim( $rest ) !== '' || str_contains( $old, '*/' ) ) {
				$out['note']     = 'imports are not one run';
				$out['complete'] = false;

				return $out;
			}
		} elseif ( preg_match( '~/\*\s*' . preg_quote( self::HAND_MADE, '~' ) . '\s*\*/(?:\s*(?:/\*(?:(?!\*/).)*\*/\s*)?@font-face\s*\{[^{}]*\})+~s', $css, $m, PREG_OFFSET_CAPTURE ) === 1 ) {
			// The rules a person (or the interim fix of 2026-09-29) wrote into the sheet by hand.
			$start = (int) $m[0][1];
			$old   = (string) $m[0][0];
			$end   = $start + strlen( $old );
		} elseif ( $urls !== array() ) {
			// Fonts the design lists, or a copy that had nothing to replace: no statement to take out, the rules (if any) went with
			// unlocalize(); the record goes where the copy's did.
			$start = self::head_length( $css );
			$end   = $start;
		} else {
			$out['note'] = 'no Google fonts';

			return $out;
		}
		// Slashes are escaped: what the record quotes may hold a comment's end, which would cut the record short.
		$record      = self::OPEN . wp_json_encode( array( 'u' => array_values( $urls ), 'o' => $old, 'c' => 1, 't' => 1 ) ) . ' */';
		$new         = substr( $css, 0, $start ) . $record . "\n" . self::CLOSE . "\n" . substr( $css, $end );
		$out['urls'] = array_values( $urls );
		if ( $new === $original ) {
			return $out;
		}
		if ( ! self::write( $path, $new ) ) {
			$out['note']     = 'not writable';
			$out['complete'] = false;

			return $out;
		}
		$out['changed'] = true;

		return $out;
	}

	/**
	 * A design's sheet, from its post: its web fonts leave it for the theme's. When that cannot be done (imports that are
	 * not one run) the fonts are copied here as before, so the design still shows its fonts.
	 *
	 * @return array{changed:bool, complete:bool, urls:array<int, string>, note:string}
	 */
	public static function adopt_design( int $post_id ): array {
		$sheet  = Upload_Paths::for_meta( $post_id, '_dxai_ui_css_url' );
		$listed = get_post_meta( $post_id, '_dxai_ui_font_urls', true );
		$listed = is_array( $listed ) ? array_map( 'strval', $listed ) : array();
		$result = self::adopt( (string) $sheet['path'], $listed );
		if ( ! $result['complete'] ) {
			return self::localize_sheet( (string) $sheet['path'], $listed );
		}
		delete_transient( 'dxai_ui_fonts_wait_' . $post_id );
		if ( $result['changed'] && ! wp_next_scheduled( self::CLEAN_HOOK ) ) {
			// Some minutes later: a file fetched a moment ago is not looked at (clean_files()).
			wp_schedule_single_event( time() + 11 * MINUTE_IN_SECONDS, self::CLEAN_HOOK );
		}

		return $result;
	}

	/** Cron: remove the font files no sheet names, on a site whose theme draws the designs in its own fonts. */
	public static function clean_now(): void {
		if ( Theme_Fonts::managed() ) {
			self::clean_files();
		}
	}

	/**
	 * Remove the font files in uploads that no stylesheet names any more, and the copies of Google's stylesheets whose
	 * files went. Nothing is removed that a sheet names (outside its record), that was written in the last ten minutes
	 * (a copy in progress), or when no sheet could be read at all.
	 *
	 * @return array{removed:int, bytes:int, kept:int, names:array<int, string>, note:string}
	 */
	public static function clean_files( bool $dry = false ): array {
		$out   = array( 'removed' => 0, 'bytes' => 0, 'kept' => 0, 'names' => array(), 'note' => '' );
		$fonts = Upload_Paths::path( Upload_Paths::DIR . '/fonts' );
		if ( $fonts === '' || ! is_dir( $fonts ) ) {
			return $out;
		}
		$base   = dirname( $fonts );
		$sheets = array();
		foreach ( array( '/*.css', '/*/*.css' ) as $pattern ) {
			foreach ( (array) glob( $base . $pattern ) as $file ) {
				if ( is_string( $file ) && is_file( $file ) && ! str_starts_with( wp_normalize_path( $file ), wp_normalize_path( $fonts ) . '/' ) ) {
					$sheets[ wp_normalize_path( $file ) ] = true;
				}
			}
		}
		global $wpdb;
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", '_dxai_ui_css_url' ) ) as $stored ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$path = Upload_Paths::path( Upload_Paths::relative( (string) $stored ) );
			if ( $path !== '' && is_file( $path ) ) {
				$sheets[ wp_normalize_path( $path ) ] = true;
			}
		}
		if ( $sheets === array() ) {
			$out['note'] = 'no stylesheet could be read';

			return $out;
		}
		$named = array();
		foreach ( array_keys( $sheets ) as $file ) {
			$css = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			// The record quotes the rules it replaced: those are not a use.
			$span = self::record_span( $css );
			if ( $span !== null ) {
				$css = substr( $css, 0, $span['start'] ) . substr( $css, $span['end'] );
			}
			if ( preg_match_all( '#fonts/([A-Za-z0-9._-]+\.(?:woff2?|ttf|otf))#i', $css, $m ) ) {
				foreach ( $m[1] as $name ) {
					$named[ $name ] = true;
				}
			}
		}
		$fresh = time() - 10 * MINUTE_IN_SECONDS;
		$gone  = array();
		foreach ( (array) glob( $fonts . '/*' ) as $file ) {
			$name = basename( (string) $file );
			if ( ! is_file( $file ) || preg_match( '/\.(?:woff2?|ttf|otf)$/i', $name ) !== 1 ) {
				continue;
			}
			if ( isset( $named[ $name ] ) || (int) @filemtime( $file ) > $fresh ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				++$out['kept'];
				continue;
			}
			$out['bytes']   += (int) filesize( $file );
			$out['names'][]  = $name;
			$gone[ $name ]   = true;
			if ( ! $dry ) {
				wp_delete_file( $file );
			}
			++$out['removed'];
		}
		// A copy of Google's stylesheet that names a file which is gone would give a sheet rules for nothing.
		foreach ( (array) glob( $fonts . '/*.css' ) as $file ) {
			if ( ! is_file( $file ) || (int) @filemtime( $file ) > $fresh ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				continue;
			}
			$copy = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$lost = false;
			if ( preg_match_all( '#url\(\s*[\'"]?([0-9a-f]{20}\.(?:woff2?|ttf|otf))#i', $copy, $m ) ) {
				foreach ( $m[1] as $name ) {
					if ( isset( $gone[ $name ] ) || ( ! $dry && ! is_file( $fonts . '/' . $name ) ) ) {
						$lost = true;
						break;
					}
				}
			}
			if ( $lost ) {
				$out['bytes']   += (int) filesize( $file );
				$out['names'][]  = basename( (string) $file );
				if ( ! $dry ) {
					wp_delete_file( $file );
				}
				++$out['removed'];
			}
		}

		return $out;
	}

	/** The path to package for a sheet: a copy without the local rules when it has them, else the sheet itself. */
	public static function portable( string $path ): string {
		if ( $path === '' || ! is_readable( $path ) || substr( strtolower( $path ), -4 ) !== '.css' || self::covered( $path ) === array() ) {
			return $path;
		}
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = wp_tempnam( 'dxai-sheet.css' );
		if ( $tmp === '' || false === file_put_contents( $tmp, self::unlocalize( (string) file_get_contents( $path ) ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return $path;
		}
		add_action( 'shutdown', static function () use ( $tmp ): void {
			wp_delete_file( $tmp );
		} );

		return $tmp;
	}

	/**
	 * The `@import`s of Google's stylesheet at the head of a sheet.
	 *
	 * @return array<int, array{statement:string, url:string, offset:int}>
	 */
	private static function google_imports( string $css ): array {
		$out  = array();
		$head = substr( $css, 0, 4096 );
		if ( preg_match_all( '#@import\s+(?:url\(\s*)?([\'"]?)(https://fonts\.googleapis\.com/[^\'")\s]+)\1\s*\)?[^;]*;#i', $head, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m as $found ) {
				if ( self::is_google( $found[2][0] ) ) {
					$out[] = array( 'statement' => $found[0][0], 'url' => $found[2][0], 'offset' => (int) $found[0][1] );
				}
			}
		}

		return $out;
	}

	/** How much of the head is `@charset` and `@import` statements (and the comments between), which stay first. */
	private static function head_length( string $css ): int {
		return preg_match( '#^(?:\s|/\*(?!\!).*?\*/|@charset\s+[^;]*;|@import\s+[^;]*;)*#s', $css, $m ) === 1 ? strlen( $m[0] ) : 0;
	}

	/** Google's stylesheet for a current browser (woff2 faces); '' when it cannot be had or holds anything else. */
	private static function fetch_css( string $url ): string {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout' => 10,
				// Google answers woff2 @font-face rules only to a browser it knows.
				'headers' => array( 'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36' ),
			)
		);
		$css = is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ? '' : (string) wp_remote_retrieve_body( $response );
		// Only font faces pointing at Google's font CDN are kept: nothing else may ride along into a local file.
		if ( ! str_contains( $css, '@font-face' ) || preg_match( '#url\((?!\s*[\x22\x27]?https://fonts\.gstatic\.com/)#i', $css ) === 1 || preg_match( '/@import|<|expression\(/i', $css ) === 1 ) {
			return '';
		}

		return $css;
	}

	/**
	 * The font files a stylesheet names, fetched into $dir and named in it by their local names. A file that cannot be
	 * fetched (or once the time is up) keeps its address.
	 */
	private static function fetch_files( string $css, string $dir, int $deadline ): string {
		if ( ! preg_match_all( '#url\(\s*([\'"]?)(https://fonts\.gstatic\.com/[^\'")\s]+)\1\s*\)#i', $css, $m, PREG_SET_ORDER ) || ! wp_mkdir_p( $dir ) ) {
			return $css;
		}
		$done = array();
		foreach ( $m as $found ) {
			$remote = $found[2];
			if ( isset( $done[ $remote ] ) ) {
				continue;
			}
			$ext  = strtolower( (string) pathinfo( (string) wp_parse_url( $remote, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			$ext  = in_array( $ext, array( 'woff2', 'woff', 'ttf', 'otf' ), true ) ? $ext : 'woff2';
			$name = substr( sha1( $remote ), 0, 20 ) . '.' . $ext;
			$file = $dir . '/' . $name;
			if ( ! is_readable( $file ) || filesize( $file ) < 64 ) {
				if ( time() > $deadline ) {
					continue;
				}
				$response = wp_safe_remote_get( $remote, array( 'timeout' => 12, 'limit_response_size' => 2 * MB_IN_BYTES ) );
				$body     = is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ? '' : (string) wp_remote_retrieve_body( $response );
				if ( ! self::is_font( $body ) || ! self::write( $file, $body ) ) {
					continue;
				}
			}
			$done[ $remote ] = $name;
		}
		foreach ( $done as $remote => $name ) {
			$css = str_replace( $remote, $name, $css );
		}

		return $css;
	}

	/** Whether these bytes are a font file (WOFF2, WOFF, TrueType or OpenType). */
	private static function is_font( string $bytes ): bool {
		$magic = substr( $bytes, 0, 4 );

		return strlen( $bytes ) > 64 && in_array( $magic, array( 'wOF2', 'wOFF', "\x00\x01\x00\x00", 'OTTO', 'true' ), true );
	}

	/** Write a file whole or not at all. */
	private static function write( string $path, string $data ): bool {
		if ( ! wp_mkdir_p( dirname( $path ) ) ) {
			return false;
		}
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
}

<?php
/**
 * The template's fonts: the design's, hosted on this site and set as the site's.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Theme;

use DXAI_UI\Media\Font_Host;
use DXAI_UI\Structures\Design_Attach;
use DXAI_UI\Support\Upload_Paths;

/**
 * A DX theme lets the site choose its two fonts from a short list that lives inside the theme (Theme Global Settings > Fonts: a headline
 * font and a body font; the list is a literal array and the files are the theme's own), and a design brings fonts of its own that are not
 * in it: Five Star is drawn in Bebas Neue and Hind. Following the theme (Theme_Fonts) draws such a design in Inter, which is what the
 * theme falls back to, and no setting can name a font the theme does not ship. So a design that is installed as the site's template —
 * its header in Menus and its footer in Widgets (Chrome_Choice) — brings its fonts with it, as a template does:
 *
 *  - the faces are fetched once, from the address the design loads them from (Font_Host::copy(), Google Fonts), the Latin ones are kept
 *    (a Hind face for Devanagari is not text this site has), and the files are copied into uploads/dxai-ui/template-fonts, a folder
 *    Font_Host's clean-up does not look at: they belong to the site, not to a design's stylesheet;
 *  - the record says which family is the headline font and which the body font (the one the design's page root is set in is the body
 *    font, any other named web font is a headline font: the rule Theme_Fonts reads a design by), their stacks as the design wrote
 *    them, and the @font-face rules to print;
 *  - they stand in for the theme's choice (the filter `dxai_ui_theme_fonts`), so every design that follows the theme's fonts is drawn in
 *    them, and they are printed on every other page of the site with the two variables the theme draws its own pages from
 *    (`--font-heading`, `--font-primary`) and in the block editor;
 *  - what a person chose in Theme Global Settings wins: while either font is chosen there, the template's are not used.
 *
 * Nothing here changes the theme, its files or its settings, and nothing is rewritten in a design: a design installed later replaces
 * the record, remove() puts the theme's own choice back.
 */
final class Template_Fonts {

	/** Option: the record (autoloaded: every page of the site reads it). */
	public const OPTION = 'dxai_ui_template_fonts';

	/** The folder of the files, under uploads/dxai-ui. */
	public const DIR = 'template-fonts';

	/** The record's version. */
	private const VERSION = 1;

	/** Unicode subsets kept when Google's stylesheet names them (a face with no subset is kept). */
	private const SUBSETS = array( 'latin', 'latin-ext' );

	/** What can stand in a font stack: names, quotes, commas (the shape Theme_Fonts accepts). */
	private const STACK = '/^[A-Za-z0-9 ,\'"._-]{1,200}$/';

	public function register(): void {
		// After the theme's own answer (priority 10 would be the theme's, the default), before nothing else asks.
		add_filter( 'dxai_ui_theme_fonts', array( self::class, 'as_theme_fonts' ), 20 );
		add_action( 'wp_head', array( self::class, 'print_site' ), 99 );
		add_filter( 'block_editor_settings_all', array( self::class, 'editor_settings' ), 20 );
		add_filter( 'dx_fonts_preload_urls', array( self::class, 'preload' ), 20 );
	}

	/* ---------------------------------------------------------------------------------------------- the record */

	/**
	 * The record, or [] when none is installed.
	 *
	 * @return array{v:int, design:int, source:string, title:string, heading:string, body:string, heading_family:string, body_family:string, faces:string, files:array<int, string>, urls:array<int, string>, installed:int, complete:bool}|array{}
	 */
	public static function get(): array {
		$r = get_option( self::OPTION, array() );
		if ( ! is_array( $r ) || ! is_string( $r['faces'] ?? null ) || $r['faces'] === '' || self::safe_stack( (string) ( $r['heading'] ?? '' ) ) === '' || self::safe_stack( (string) ( $r['body'] ?? '' ) ) === '' ) {
			return array();
		}

		return $r;
	}

	/**
	 * What a person chose for the site's fonts in the theme's own settings (Theme Global Settings > Fonts): headline and body, each only
	 * when it is set. [] on a theme without those settings, and on a site where nothing was chosen.
	 *
	 * @return array<string, string>
	 */
	public static function choice(): array {
		$out = array();
		if ( function_exists( 'amr_get_acf_option_font_value' ) ) {
			foreach ( array( 'headlines' => 'headlines', 'body' => 'subheadlines_and_body_copy' ) as $role => $field ) {
				$value = trim( (string) amr_get_acf_option_font_value( $field ) );
				if ( $value !== '' ) {
					$out[ $role ] = $value;
				}
			}
		}
		/**
		 * What a person chose for the site's fonts. A theme with settings of its own says so here (headlines, body); anything in it keeps the
		 * template's fonts out of use.
		 *
		 * @param array<string, string> $out The choice read from the DevriX theme's settings.
		 */
		$out = apply_filters( 'dxai_ui_template_fonts_choice', $out );

		return array_filter( array_map( 'strval', is_array( $out ) ? $out : array() ), static fn( $v ) => $v !== '' );
	}

	/**
	 * The record when it is in use: installed, and nobody chose the site's fonts in the theme's settings since.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function live(): ?array {
		$r = self::get();
		if ( $r === array() || self::choice() !== array() ) {
			return null;
		}
		/**
		 * Whether the template's fonts are used. A site that wants the theme's own fonts back says no here.
		 *
		 * @param bool                 $live The default: installed, and no choice in the theme's settings.
		 * @param array<string, mixed> $r    The record.
		 */
		return (bool) apply_filters( 'dxai_ui_template_fonts_live', true, $r ) ? $r : null;
	}

	/** The files the record names, by name. @return array<int, string> */
	public static function file_names(): array {
		return array_values( array_filter( array_map( 'strval', (array) ( self::get()['files'] ?? array() ) ) ) );
	}

	/** What is installed and whether it is in use, for the command line and the reports. @return array<string, mixed> */
	public static function status(): array {
		$r = self::get();
		if ( $r === array() ) {
			return array( 'installed' => false );
		}
		$bytes = 0;
		foreach ( self::file_names() as $name ) {
			$path   = Upload_Paths::path( Upload_Paths::DIR . '/' . self::DIR . '/' . $name );
			$bytes += $path !== '' && is_file( $path ) ? (int) filesize( $path ) : 0;
		}

		return array(
			'installed' => true,
			'live'      => self::live() !== null,
			'choice'    => self::choice(),
			'heading'   => (string) $r['heading_family'],
			'body'      => (string) $r['body_family'],
			'files'     => count( self::file_names() ),
			'bytes'     => $bytes,
			'design'    => (int) $r['design'],
			'title'     => (string) $r['title'],
			'complete'  => ! empty( $r['complete'] ),
			'installed_at' => (int) $r['installed'],
		);
	}

	/* ---------------------------------------------------------------------------------------------- what it feeds */

	/**
	 * The template's fonts as the theme's choice, for Theme_Fonts: every design that follows the theme's fonts is drawn in them.
	 *
	 * @param mixed $found What the theme gave (Theme_Fonts::read_theme()), or null.
	 * @return mixed
	 */
	public static function as_theme_fonts( $found ) {
		$t = self::live();
		if ( $t === null ) {
			return $found;
		}

		return array(
			'id'           => get_stylesheet(),
			'heading'      => self::safe_stack( (string) $t['heading'] ),
			'body'         => self::safe_stack( (string) $t['body'] ),
			'heading_slug' => 'dx-template-heading',
			'body_slug'    => 'dx-template-body',
			'faces'        => (string) $t['faces'],
		);
	}

	/**
	 * What every other page of the site prints: the faces, and the two variables the theme's own stylesheet draws its fonts from.
	 *
	 * @param array<string, mixed> $t The record.
	 */
	public static function site_css( array $t ): string {
		$css = (string) $t['faces'] . "\n:root{--font-heading:" . self::safe_stack( (string) $t['heading'] ) . ';--font-primary:' . self::safe_stack( (string) $t['body'] ) . ';}';

		// Nothing in it can close a style element.
		return str_replace( '</', '<\\/', $css );
	}

	/** Print the template's fonts on a page the theme draws. A converted page prints them with its design (Theme_Fonts::css()). */
	public static function print_site(): void {
		$t = self::live();
		if ( $t === null || is_admin() || Theme_Compat::is_converted_page() ) {
			return;
		}
		echo '<style id="dxai-template-fonts">' . self::site_css( $t ) . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the faces and stacks are made here and validated when read.
	}

	/**
	 * The template's fonts in the block editor's canvas.
	 *
	 * @param mixed $settings The editor settings.
	 * @return mixed
	 */
	public static function editor_settings( $settings ) {
		$t = self::live();
		if ( $t === null || ! is_array( $settings ) ) {
			return $settings;
		}
		$settings['styles']   = is_array( $settings['styles'] ?? null ) ? $settings['styles'] : array();
		$settings['styles'][] = array( 'css' => self::site_css( $t ) );

		return $settings;
	}

	/**
	 * The files the theme's fonts module preloads: the theme's own fonts are not on the page any more, the template's are.
	 *
	 * @param mixed $urls
	 * @return mixed
	 */
	public static function preload( $urls ) {
		$t = self::live();
		if ( $t === null || ! is_array( $urls ) ) {
			return $urls;
		}
		$theme = trailingslashit( get_stylesheet_directory_uri() ) . 'assets/src/fonts/';
		$keep  = array_values( array_filter( $urls, static fn( $u ) => ! is_string( $u ) || ! str_starts_with( $u, $theme ) ) );
		// One file for each font, the Latin face in the regular weight: what the first paint of text needs.
		$ours = array();
		foreach ( array( 'heading_family', 'body_family' ) as $role ) {
			$family = (string) $t[ $role ];
			foreach ( (array) ( $t['preload'] ?? array() ) as $row ) {
				if ( is_array( $row ) && ( $row['family'] ?? '' ) === $family && is_string( $row['url'] ?? null ) ) {
					$ours[] = $row['url'];
					break;
				}
			}
		}

		return array_values( array_unique( array_merge( $keep, $ours ) ) );
	}

	/* ---------------------------------------------------------------------------------------------- installing */

	/**
	 * What an import does about the fonts, once it has decided about the header and footer: a design that becomes the site's template
	 * brings its fonts, and everything else is left as it is.
	 *
	 * @param array<string, mixed> $mode Chrome_Choice::resolve().
	 * @return array<string, mixed> installed, and why not when it is not.
	 */
	public static function for_import( int $home, array $mode ): array {
		if ( (string) ( $mode['mode'] ?? '' ) !== 'install' ) {
			return array( 'installed' => false, 'reason' => 'kept' );
		}
		// A theme that does not manage the site's fonts draws a design in its own, as it always did.
		if ( ! Theme_Fonts::managed() ) {
			return array( 'installed' => false, 'reason' => 'unmanaged' );
		}
		$chosen = self::choice();
		if ( $chosen !== array() ) {
			return array( 'installed' => false, 'reason' => 'chosen', 'choice' => $chosen );
		}
		try {
			$done = self::install( $home );
		} catch ( \Throwable $e ) {
			return array( 'installed' => false, 'reason' => 'error', 'message' => $e->getMessage() );
		}
		if ( is_wp_error( $done ) ) {
			return array( 'installed' => false, 'reason' => (string) $done->get_error_code(), 'message' => $done->get_error_message() );
		}

		return $done;
	}

	/**
	 * Make a design's fonts the template's.
	 *
	 * @return array<string, mixed>|\WP_Error installed, heading, body, faces, files, bytes, complete.
	 */
	public static function install( int $home ) {
		if ( $home < 1 || ! Design_Attach::is_design( $home ) ) {
			return new \WP_Error( 'dxai_ui_fonts_design', __( 'That is not an imported design.', 'dxai-ui' ) );
		}
		$urls = array();
		foreach ( (array) get_post_meta( $home, '_dxai_ui_font_urls', true ) as $url ) {
			if ( is_string( $url ) && Font_Host::is_google( $url ) ) {
				$urls[] = Font_Host::normal( $url );
			}
		}
		$urls = array_values( array_unique( $urls ) );
		if ( $urls === array() ) {
			return new \WP_Error( 'dxai_ui_fonts_none', __( 'The design loads no Google font, so there is nothing to host for the template.', 'dxai-ui' ) );
		}
		$faces    = array();
		$complete = true;
		foreach ( $urls as $url ) {
			$copy = Font_Host::copy( $url );
			if ( $copy === null ) {
				return new \WP_Error( 'dxai_ui_fonts_fetch', __( 'Google Fonts could not be reached, so the design\'s fonts were not copied. Nothing was changed.', 'dxai-ui' ) );
			}
			$complete = $complete && $copy['complete'];
			foreach ( self::faces_of( $copy['css'] ) as $face ) {
				$faces[] = $face;
			}
		}
		// The Latin faces; a stylesheet that names no subsets is kept whole.
		$labelled = array_filter( $faces, static fn( $f ) => $f['subset'] !== '' );
		if ( $labelled !== array() ) {
			$faces = array_values( array_filter( $faces, static fn( $f ) => in_array( $f['subset'], self::SUBSETS, true ) ) );
		}
		// Only what is on this disk: a file that could not be fetched keeps Google's address in the copy and is not a face here.
		$dir = Upload_Paths::path( Upload_Paths::DIR . '/' . self::DIR );
		if ( $dir === '' || ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'dxai_ui_fonts_dir', __( 'The uploads folder for the template\'s fonts could not be made.', 'dxai-ui' ) );
		}
		$rules   = array();
		$files   = array();
		$preload = array();
		$bytes   = 0;
		$names   = array();
		foreach ( $faces as $face ) {
			$from = Upload_Paths::path( Upload_Paths::DIR . '/fonts/' . $face['file'] );
			$to   = $dir . '/' . $face['file'];
			if ( $from === '' || ! is_file( $from ) || ( ! is_file( $to ) && ! @copy( $from, $to ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_copy
				$complete = false;
				continue;
			}
			$url           = Upload_Paths::url( Upload_Paths::DIR . '/' . self::DIR . '/' . $face['file'] );
			$rules[]       = '@font-face{font-family:"' . $face['family'] . '";font-style:' . $face['style'] . ';font-weight:' . $face['weight'] . ';font-display:' . $face['display'] . ';src:url(' . $url . ') format("' . self::format_of( $face['file'] ) . '")' . ( $face['range'] !== '' ? ';unicode-range:' . $face['range'] : '' ) . '}';
			$files[ $face['file'] ] = true;
			$bytes        += (int) filesize( $to );
			$names[ $face['family'] ] = true;
			// The first Latin face of a font in the regular weight is what its first paint needs.
			if ( ( $face['subset'] === 'latin' || $face['subset'] === '' ) && in_array( $face['weight'], array( '400', 'normal' ), true ) && $face['style'] === 'normal' ) {
				$preload[ $face['family'] ] = $preload[ $face['family'] ] ?? array( 'family' => $face['family'], 'url' => $url );
			}
		}
		if ( $rules === array() ) {
			return new \WP_Error( 'dxai_ui_fonts_faces', __( 'Google answered, but no font face of the design could be kept.', 'dxai-ui' ) );
		}
		// Which family is which: the design's page is set in the body font, any other named web font is a headline font.
		$families = array_keys( $names );
		$body_key = Theme_Fonts::body_of( $home );
		$body     = $families[0];
		foreach ( $families as $family ) {
			if ( strtolower( $family ) === $body_key ) {
				$body = $family;
			}
		}
		$heading = $body;
		foreach ( $families as $family ) {
			if ( $family !== $body && ! Theme_Fonts::is_icon( strtolower( $family ) ) ) {
				$heading = $family;
				break;
			}
		}
		$record = array(
			'v'              => self::VERSION,
			'design'         => $home,
			'source'         => (string) get_post_meta( $home, '_dxai_ui_source_zip', true ),
			'title'          => html_entity_decode( get_the_title( $home ), ENT_QUOTES, 'UTF-8' ),
			'heading'        => self::stack_of( $heading, $home, 'heading' ),
			'body'           => self::stack_of( $body, $home, 'body' ),
			'heading_family' => $heading,
			'body_family'    => $body,
			'faces'          => implode( "\n", $rules ),
			'files'          => array_keys( $files ),
			'urls'           => $urls,
			'preload'        => array_values( $preload ),
			'installed'      => time(),
			'complete'       => $complete,
		);
		$before = self::get();
		update_option( self::OPTION, $record, true );
		// The files the record before this one named and this one does not are not wanted any more.
		foreach ( array_diff( array_map( 'strval', (array) ( $before['files'] ?? array() ) ), array_keys( $files ) ) as $old ) {
			$path = $dir . '/' . basename( $old );
			if ( preg_match( '/^[0-9a-f]{20}\.(?:woff2?|ttf|otf)$/', basename( $old ) ) === 1 && is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		Theme_Fonts::reset();

		return array(
			'installed' => true,
			'heading'   => $heading,
			'body'      => $body,
			'faces'     => count( $rules ),
			'files'     => count( $files ),
			'bytes'     => $bytes,
			'complete'  => $complete,
		);
	}

	/**
	 * Stop using the template's fonts, and remove what was fetched for them: the theme's own choice is back.
	 *
	 * @return array{removed:bool, files:int}
	 */
	public static function remove(): array {
		$r     = self::get();
		$files = 0;
		$dir   = Upload_Paths::path( Upload_Paths::DIR . '/' . self::DIR );
		foreach ( self::file_names() as $name ) {
			$path = $dir . '/' . basename( $name );
			if ( $dir !== '' && preg_match( '/^[0-9a-f]{20}\.(?:woff2?|ttf|otf)$/', basename( $name ) ) === 1 && is_file( $path ) ) {
				wp_delete_file( $path );
				++$files;
			}
		}
		delete_option( self::OPTION );
		Theme_Fonts::reset();

		return array( 'removed' => $r !== array(), 'files' => $files );
	}

	/* ---------------------------------------------------------------------------------------------- reading Google's stylesheet */

	/**
	 * The font faces of a copy of Google's stylesheet (Font_Host::copy(): the files are named by their local names), each with the
	 * subset Google's comment before it names.
	 *
	 * @return array<int, array{subset:string, family:string, style:string, weight:string, display:string, file:string, range:string}>
	 */
	private static function faces_of( string $css ): array {
		$out = array();
		if ( ! preg_match_all( '#(?:/\*\s*([A-Za-z0-9-]+)\s*\*/\s*)?@font-face\s*\{([^}]*)\}#', $css, $blocks, PREG_SET_ORDER ) ) {
			return $out;
		}
		foreach ( $blocks as $block ) {
			$decl = array();
			foreach ( explode( ';', $block[2] ) as $line ) {
				$at = strpos( $line, ':' );
				if ( $at !== false ) {
					$decl[ strtolower( trim( substr( $line, 0, $at ) ) ) ] = trim( substr( $line, $at + 1 ) );
				}
			}
			$family = trim( (string) ( $decl['font-family'] ?? '' ), " \t\"'" );
			if ( $family === '' || preg_match( '/^[A-Za-z0-9 ._-]{1,80}$/', $family ) !== 1 || preg_match( '#url\(\s*[\'"]?([0-9a-f]{20}\.(?:woff2|woff|ttf|otf))[\'"]?\s*\)#i', (string) ( $decl['src'] ?? '' ), $file ) !== 1 ) {
				continue;
			}
			$style   = strtolower( (string) ( $decl['font-style'] ?? 'normal' ) );
			$weight  = strtolower( (string) ( $decl['font-weight'] ?? '400' ) );
			$display = strtolower( (string) ( $decl['font-display'] ?? 'swap' ) );
			$range   = (string) ( $decl['unicode-range'] ?? '' );
			$out[]   = array(
				'subset'  => strtolower( (string) ( $block[1] ?? '' ) ),
				'family'  => $family,
				'style'   => in_array( $style, array( 'normal', 'italic', 'oblique' ), true ) ? $style : 'normal',
				'weight'  => preg_match( '/^(?:normal|bold|\d{1,4}(?: \d{1,4})?)$/', $weight ) === 1 ? $weight : '400',
				'display' => in_array( $display, array( 'auto', 'block', 'swap', 'fallback', 'optional' ), true ) ? $display : 'swap',
				'file'    => strtolower( $file[1] ),
				'range'   => preg_match( '/^[Uu+0-9A-Fa-f,\s?-]+$/', $range ) === 1 ? $range : '',
			);
		}

		return $out;
	}

	/**
	 * A family's stack as the design wrote it (its fallbacks included), or a plain one made from its name.
	 */
	private static function stack_of( string $family, int $home, string $role ): string {
		$sheet = Upload_Paths::for_meta( $home, '_dxai_ui_css_url' );
		$path  = (string) ( $sheet['path'] ?? '' );
		if ( $path !== '' && is_readable( $path ) ) {
			$css = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( preg_match_all( '/font-family\s*:\s*([^;}]+)/i', $css, $found ) ) {
				foreach ( $found[1] as $value ) {
					$value = trim( (string) preg_replace( '/\s*!important\s*$/i', '', (string) $value ) );
					if ( strtolower( Theme_Fonts::first_named( $value ) ) === strtolower( $family ) && self::safe_stack( $value ) !== '' ) {
						return $value;
					}
				}
			}
		}

		return '"' . $family . '", ' . ( $role === 'heading' ? 'sans-serif' : 'system-ui, sans-serif' );
	}

	/** A font stack that cannot end a declaration or a rule, or ''. */
	private static function safe_stack( string $value ): string {
		$value = trim( $value );

		return preg_match( self::STACK, $value ) === 1 ? $value : '';
	}

	/** The format() name of a font file. */
	private static function format_of( string $file ): string {
		$ext = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );

		return array(
			'woff2' => 'woff2',
			'woff'  => 'woff',
			'ttf'   => 'truetype',
			'otf'   => 'opentype',
		)[ $ext ] ?? 'woff2';
	}
}

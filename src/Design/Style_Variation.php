<?php
/**
 * A design's tokens as a theme.json style variation: the design's look as the site's.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Design;

use DXAI_UI\Chrome\Page_Chrome;
use DXAI_UI\Compiler\Token_Styles;
use DXAI_UI\Theme\Capabilities;
use DXAI_UI\Theme\Design_Theme_Json;
use DXAI_UI\Theme\Template_Fonts;

/**
 * WordPress keeps a site's look in theme.json, and a theme's alternative looks as style variations: one JSON file each in its
 * `styles/` folder, with the presets (colours, fonts, sizes) and the styles (what buttons, links, headings and text are drawn with).
 * This writes one for a design, from its document (docs/PLAN-ARCHITECTURE.md, phase 2): its colours as presets under the slugs the
 * brand design's tokens already use (Design_Theme_Json: `brand`, `accent`, `on-accent`, `ink`, …, and the design's own palette), its
 * fonts as font families — with their faces when the site hosts them (Template_Fonts) —, its content width from what its sections
 * ask for, and the styles that make them the site's: body and headings in its fonts, buttons in its brand, links in its accent.
 *
 * Three ways it is used. (1) Applied: the chosen design's variation is merged into the theme layer of theme.json on every request
 * (the layer Design_Theme_Json uses, for the same reasons: a person's Styles still win, and "Reset" goes back to the design); the
 * design is the brand design too, so its pages refer to the presets. On the design's own pages the styles are left out and only the
 * presets are merged: those pages are drawn pixel for pixel by their own rules, and a global button colour would move them. (2) Read
 * as JSON, for a theme that lists variations in the Site Editor, or to carry to another site. (3) Written into the theme's `styles/`
 * folder, only when the theme is the plugin's own (DX Base) and files may be changed (Capabilities::styles_writable()); a team's
 * theme is never written into. Nothing here deletes a file or a theme.
 */
final class Style_Variation {

	/** The design whose variation is the site's: { design: int, at: string }. */
	public const OPTION = 'dxai_ui_style_variation';

	/** The slugs the brand design's tokens are registered under (Design_Theme_Json), token role => slug. */
	public const ROLE_SLUGS = array(
		'brand'     => 'brand',
		'accent'    => 'accent',
		'button_fg' => 'on-accent',
		'ink'       => 'ink',
		'body'      => 'body',
		'muted'     => 'muted',
		'surface'   => 'surface',
		'border'    => 'border',
		'danger'    => 'danger',
	);

	/** Tailwind's max-width scale, as the design's classes name it, in pixels. */
	private const WIDTHS = array(
		'xs'         => 320,
		'sm'         => 384,
		'md'         => 448,
		'lg'         => 512,
		'xl'         => 576,
		'2xl'        => 672,
		'3xl'        => 768,
		'4xl'        => 896,
		'5xl'        => 1024,
		'6xl'        => 1152,
		'7xl'        => 1280,
		'screen-sm'  => 640,
		'screen-md'  => 768,
		'screen-lg'  => 1024,
		'screen-xl'  => 1280,
		'screen-2xl' => 1536,
	);

	/** @var int|null A page assumed to be the one shown, for a check that cannot run a query; null asks WordPress. */
	private static ?int $assumed = null;

	public function register(): void {
		// Last of all: after Design_Theme_Json (10), whose brand presets are then in the data, and after a theme's own filters — American
		// Restoration rewrites the palette from its options at 100, and a merge replaces a palette whole, so anything added before it
		// is gone by the time theme.json is read. The variation carries what it finds and adds what is not there.
		add_filter( 'wp_theme_json_data_theme', array( $this, 'filter' ), PHP_INT_MAX );
		foreach ( array( 'add_option_', 'update_option_', 'delete_option_' ) as $hook ) {
			add_action( $hook . self::OPTION, array( Design_Theme_Json::class, 'flush' ) );
		}
		add_action( 'trashed_post', array( self::class, 'released' ) );
		add_action( 'deleted_post', array( self::class, 'released' ) );
	}

	/** The design whose variation is applied, 0 when none. */
	public static function applied(): int {
		$o = get_option( self::OPTION );

		return is_array( $o ) ? (int) ( $o['design'] ?? 0 ) : 0;
	}

	/** A design that is gone takes its variation with it. */
	public static function released( $post_id ): void {
		if ( (int) $post_id > 0 && (int) $post_id === self::applied() ) {
			delete_option( self::OPTION );
		}
	}

	/**
	 * The variation, as a theme.json (version 3) array: title, settings, styles.
	 *
	 * @param array<string, string> $theme_layout The theme's own layout sizes (contentSize, wideSize) when the caller has them — the
	 *                                            filter does, and must not ask the global settings for them while it runs.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function build( int $home, array $theme_layout = array() ): array|\WP_Error {
		$home = Document_Store::home_of( $home );
		$doc  = $home > 0 ? Document_Store::load( $home ) : null;
		if ( $doc === null ) {
			return new \WP_Error( 'dxai_ui_no_document', __( 'The design has no document to make a style variation from: build it first.', 'dxai-ui' ) );
		}
		$tokens  = $doc->get( 'tokens' );
		$roles   = is_array( $tokens['roles'] ?? null ) ? $tokens['roles'] : array();
		$palette = self::palette( $home, $roles );
		$fonts   = self::fonts( $home, $doc );
		$layout  = self::layout( $doc, $theme_layout );
		$radius  = (string) ( $roles['radius'] ?? '' );
		$slugs   = array_column( $palette, 'slug' );
		$families = array_column( $fonts, 'slug' );

		$settings = array(
			'color'      => array( 'palette' => $palette ),
			'typography' => array( 'fontFamilies' => $fonts ),
			'custom'     => array(
				'dxai' => array(
					'design' => $home,
					'radius' => $radius,
				),
			),
		);
		if ( $layout !== array() ) {
			$settings['layout'] = $layout;
		}
		$styles = array();
		if ( in_array( 'dxai-body', $families, true ) ) {
			$styles['typography'] = array( 'fontFamily' => 'var(--wp--preset--font-family--dxai-body)' );
		}
		if ( in_array( 'dxai-heading', $families, true ) ) {
			$styles['elements']['heading'] = array( 'typography' => array( 'fontFamily' => 'var(--wp--preset--font-family--dxai-heading)' ) );
		}
		if ( in_array( 'brand', $slugs, true ) ) {
			$button = array( 'color' => array( 'background' => 'var(--wp--preset--color--brand)' ) );
			if ( in_array( 'on-accent', $slugs, true ) ) {
				$button['color']['text'] = 'var(--wp--preset--color--on-accent)';
			}
			if ( preg_match( '/^\d+(\.\d+)?(px|rem|em|%)$/', $radius ) === 1 ) {
				$button['border'] = array( 'radius' => $radius );
			}
			$styles['elements']['button'] = $button;
		}
		if ( in_array( 'accent', $slugs, true ) ) {
			$styles['elements']['link'] = array( 'color' => array( 'text' => 'var(--wp--preset--color--accent)' ) );
		}

		return array(
			'$schema'  => 'https://schemas.wp.org/trunk/theme.json',
			'version'  => 3,
			'title'    => (string) $doc->get( 'title' ),
			'settings' => $settings,
			'styles'   => $styles,
		);
	}

	/** The variation as the file it would be. */
	public static function json( int $home ): string|\WP_Error {
		$v = self::build( $home );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		$json = wp_json_encode( $v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return is_string( $json ) ? $json . "\n" : new \WP_Error( 'dxai_ui_variation_json', __( 'The variation could not be written as JSON.', 'dxai-ui' ) );
	}

	/** The file a theme would list it from: `dxai-<design>.json`. */
	public static function file_name( int $home ): string {
		$slug = sanitize_title( (string) get_the_title( $home ) );

		return 'dxai-' . ( $slug !== '' ? $slug : (string) $home ) . '.json';
	}

	/**
	 * Make the design's variation the site's: kept as the option the filter reads, and the design made the brand design (its pages
	 * refer to the presets). Nothing is written to a file.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function apply( int $home ): array|\WP_Error {
		$home = Document_Store::home_of( $home );
		$v    = $home > 0 ? self::build( $home ) : new \WP_Error( 'dxai_ui_no_design', __( 'No design has a page with this id.', 'dxai-ui' ) );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		update_option(
			self::OPTION,
			array(
				'design' => $home,
				'at'     => gmdate( 'c' ),
			),
			false
		);
		// A person chose this: the design is the brand design whatever the theme would do on its own at import (Design_Theme_Json::adopt()
		// asks `dxai_ui_adopt_brand`, which a classic theme answers no to, so that an import does not take over its global styles unasked).
		$chosen = static fn(): bool => true;
		add_filter( 'dxai_ui_adopt_brand', $chosen, 99 );
		Design_Theme_Json::adopt( $home, (string) get_post_meta( $home, '_dxai_ui_source_zip', true ) );
		remove_filter( 'dxai_ui_adopt_brand', $chosen, 99 );
		Design_Theme_Json::flush();

		return self::status( $home );
	}

	/** The site's look is the theme's own again; the brand design stays what the import left it. */
	public static function clear(): bool {
		$had = self::applied() > 0;
		delete_option( self::OPTION );
		Design_Theme_Json::flush();

		return $had;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function status( int $home ): array {
		$dir  = Capabilities::styles_dir();
		$file = $dir . '/' . self::file_name( $home );

		return array(
			'design'    => $home,
			'applied'   => self::applied() === $home,
			'file_name' => self::file_name( $home ),
			'writable'  => Capabilities::styles_writable(),
			'written'   => is_file( $file ),
			'theme'     => get_stylesheet(),
		);
	}

	/**
	 * Write the variation into the theme's `styles/` folder, when that is allowed (Capabilities::styles_writable()).
	 *
	 * @return array{path:string, bytes:int}|\WP_Error
	 */
	public static function write( int $home ): array|\WP_Error {
		if ( ! Capabilities::styles_writable() ) {
			return new \WP_Error( 'dxai_ui_variation_not_ours', __( 'The theme\'s styles folder is not one the plugin may write into: only the plugin\'s own theme (DX Base) takes a variation file, and only when the site lets files be changed. Download the JSON instead.', 'dxai-ui' ) );
		}

		return self::write_to( $home, Capabilities::styles_dir() );
	}

	/**
	 * Write the variation into a folder, whatever it is (the writer; write() decides where it may go).
	 *
	 * @return array{path:string, bytes:int}|\WP_Error
	 */
	public static function write_to( int $home, string $dir ): array|\WP_Error {
		$json = self::json( $home );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$dir = wp_normalize_path( rtrim( $dir, '/' ) );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'dxai_ui_variation_dir', __( 'The styles folder could not be made.', 'dxai-ui' ) );
		}
		$path = $dir . '/' . self::file_name( $home );
		if ( false === file_put_contents( $path, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new \WP_Error( 'dxai_ui_variation_write', __( 'The variation file could not be written.', 'dxai-ui' ) );
		}

		return array(
			'path'  => $path,
			'bytes' => strlen( $json ),
		);
	}

	/**
	 * The applied variation merged into the theme layer of theme.json: its presets beside the theme's (never in their place — a merge
	 * replaces a palette whole, so the theme's entries are carried forward), its layout, and its styles everywhere but on the design's
	 * own pages.
	 *
	 * @param mixed $theme_json WP_Theme_JSON_Data.
	 * @return mixed
	 */
	public function filter( $theme_json ) {
		// Building the variation reads the theme's layout sizes through the global settings, which resolve theme.json, which runs this
		// filter again: the inner call gets the data as it is.
		static $busy = false;
		$home = self::applied();
		if ( $busy || $home < 1 || ! is_object( $theme_json ) || ! method_exists( $theme_json, 'update_with' ) ) {
			return $theme_json;
		}
		$busy    = true;
		$current = method_exists( $theme_json, 'get_data' ) ? (array) $theme_json->get_data() : array();
		try {
			$v = self::build( $home, array_map( 'strval', is_array( $current['settings']['layout'] ?? null ) ? $current['settings']['layout'] : array() ) );
		} finally {
			$busy = false;
		}
		if ( is_wp_error( $v ) ) {
			return $theme_json;
		}
		$fragment = array(
			'version'  => 3,
			'settings' => array(
				'color'      => array( 'palette' => self::carry( $current, array( 'settings', 'color', 'palette' ), $v['settings']['color']['palette'] ) ),
				'typography' => array( 'fontFamilies' => self::carry( $current, array( 'settings', 'typography', 'fontFamilies' ), $v['settings']['typography']['fontFamilies'] ) ),
				'custom'     => $v['settings']['custom'],
			),
		);
		// The design's own pages get the presets alone: the styles would recolour their buttons, and the content width would reflow every
		// constrained group of theirs (core's layout rules read it at render) — they are drawn by their own rules, pixel for pixel.
		$own = self::on_design_page();
		if ( isset( $v['settings']['layout'] ) && ! $own ) {
			$fragment['settings']['layout'] = $v['settings']['layout'];
		}
		if ( $v['styles'] !== array() && ! $own ) {
			$fragment['styles'] = $v['styles'];
		}

		return $theme_json->update_with( $fragment );
	}

	/** For a check that cannot run a query: the page the filter is to take as the one shown (0: none); null asks WordPress again. */
	public static function assume_page( ?int $id ): void {
		self::$assumed = $id;
	}

	/** Whether the page being shown is a design's own: its rules draw it, the variation's styles stay off it. */
	public static function on_design_page(): bool {
		$id = self::$assumed ?? ( is_singular() ? (int) get_queried_object_id() : 0 );

		return $id > 0 && Page_Chrome::composes( $id );
	}

	/**
	 * The entries the theme has at a path plus the design's that are not there yet, by slug.
	 *
	 * @param array<string, mixed>             $current
	 * @param array<int, string>               $path
	 * @param array<int, array<string, mixed>> $add
	 * @return array<int, array<string, mixed>>
	 */
	private static function carry( array $current, array $path, array $add ): array {
		$node = $current;
		foreach ( $path as $key ) {
			$node = is_array( $node ) && isset( $node[ $key ] ) ? $node[ $key ] : array();
		}
		// Presets read back from theme.json data are keyed by origin; the theme layer's own raw data is a flat list. Both are read.
		if ( is_array( $node ) && isset( $node['theme'] ) && is_array( $node['theme'] ) ) {
			$node = $node['theme'];
		}
		$out   = array_values( array_filter( is_array( $node ) ? $node : array(), static fn( $row ): bool => is_array( $row ) && isset( $row['slug'] ) && is_string( $row['slug'] ) ) );
		$taken = array_map( 'strval', array_column( $out, 'slug' ) );
		foreach ( $add as $entry ) {
			$slug = (string) ( $entry['slug'] ?? '' );
			if ( $slug !== '' && ! in_array( $slug, $taken, true ) ) {
				$taken[] = $slug;
				$out[]   = $entry;
			}
		}

		return $out;
	}

	/**
	 * The design's colours as palette entries: its token roles under the brand slugs, then its own palette (Token_Styles' record, the
	 * entries that are presets), each slug once.
	 *
	 * @param array<string, string> $roles
	 * @return array<int, array{slug:string, name:string, color:string}>
	 */
	private static function palette( int $home, array $roles ): array {
		$out   = array();
		$taken = array();
		$names = array(
			'brand'     => __( 'Brand', 'dxai-ui' ),
			'accent'    => __( 'Accent', 'dxai-ui' ),
			'button_fg' => __( 'Text on accent', 'dxai-ui' ),
			'ink'       => __( 'Ink', 'dxai-ui' ),
			'body'      => __( 'Body text', 'dxai-ui' ),
			'muted'     => __( 'Muted', 'dxai-ui' ),
			'surface'   => __( 'Surface', 'dxai-ui' ),
			'border'    => __( 'Border', 'dxai-ui' ),
			'danger'    => __( 'Danger', 'dxai-ui' ),
		);
		foreach ( self::ROLE_SLUGS as $role => $slug ) {
			$hex = sanitize_hex_color( (string) ( $roles[ $role ] ?? '' ) );
			if ( ! is_string( $hex ) || $hex === '' ) {
				continue;
			}
			$taken[] = $slug;
			$out[]   = array(
				'slug'  => $slug,
				'name'  => (string) $names[ $role ],
				'color' => strtoupper( $hex ),
			);
		}
		$record = get_post_meta( $home, Token_Styles::META, true );
		foreach ( is_array( $record['colors'] ?? null ) ? $record['colors'] : array() as $row ) {
			if ( ! is_array( $row ) || empty( $row['preset'] ) ) {
				continue;
			}
			$slug = sanitize_key( (string) ( $row['slug'] ?? '' ) );
			$hex  = sanitize_hex_color( (string) ( $row['value'] ?? '' ) );
			if ( $slug === '' || ! is_string( $hex ) || $hex === '' || in_array( $slug, $taken, true ) ) {
				continue;
			}
			$taken[] = $slug;
			$out[]   = array(
				'slug'  => $slug,
				'name'  => sanitize_text_field( (string) ( $row['name'] ?? $slug ) ),
				'color' => strtoupper( $hex ),
			);
		}

		return $out;
	}

	/**
	 * The design's fonts as font families: the heading's and the body's (`dxai-heading`, `dxai-body`), with their faces when the site
	 * hosts them for this design (Template_Fonts), else by name alone; then any other family the document lists.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function fonts( int $home, Document $doc ): array {
		$out    = array();
		$record = Template_Fonts::get();
		$hosted = (int) ( $record['design'] ?? 0 ) === $home;
		$faces  = $hosted ? self::faces( (string) ( $record['faces'] ?? '' ) ) : array();
		$listed = array();
		foreach ( (array) $doc->get( 'tokens' )['fonts'] as $f ) {
			$family = trim( (string) ( $f['family'] ?? '' ) );
			if ( $family !== '' ) {
				$listed[] = $family;
			}
		}
		$heading = $hosted ? (string) ( $record['heading_family'] ?? '' ) : (string) ( $listed[0] ?? '' );
		$body    = $hosted ? (string) ( $record['body_family'] ?? '' ) : (string) ( $listed[1] ?? $listed[0] ?? '' );
		$stack   = static fn( string $family, string $given ): string => $given !== '' ? $given : '"' . $family . '", sans-serif';
		if ( $heading !== '' ) {
			$out[] = array_filter(
				array(
					'slug'       => 'dxai-heading',
					'name'       => $heading,
					'fontFamily' => $stack( $heading, $hosted ? (string) ( $record['heading'] ?? '' ) : '' ),
					'fontFace'   => array_values( array_filter( $faces, static fn( array $face ): bool => strcasecmp( $face['fontFamily'], $heading ) === 0 ) ),
				),
				static fn( $v ): bool => $v !== array()
			);
		}
		if ( $body !== '' ) {
			$out[] = array_filter(
				array(
					'slug'       => 'dxai-body',
					'name'       => $body,
					'fontFamily' => $stack( $body, $hosted ? (string) ( $record['body'] ?? '' ) : '' ),
					'fontFace'   => array_values( array_filter( $faces, static fn( array $face ): bool => strcasecmp( $face['fontFamily'], $body ) === 0 ) ),
				),
				static fn( $v ): bool => $v !== array()
			);
		}
		$n = 0;
		foreach ( $listed as $family ) {
			if ( strcasecmp( $family, $heading ) === 0 || strcasecmp( $family, $body ) === 0 ) {
				continue;
			}
			$out[] = array(
				'slug'       => 'dxai-font-' . ++$n,
				'name'       => $family,
				'fontFamily' => $stack( $family, '' ),
			);
		}

		return $out;
	}

	/**
	 * The faces of the hosted fonts' @font-face rules (Template_Fonts' record), as theme.json writes them.
	 *
	 * @return array<int, array{fontFamily:string, fontWeight:string, fontStyle:string, src:array<int, string>}>
	 */
	private static function faces( string $css ): array {
		$out = array();
		if ( ! preg_match_all( '/@font-face\s*\{([^}]*)\}/i', $css, $blocks ) ) {
			return $out;
		}
		foreach ( $blocks[1] as $block ) {
			$decl = array();
			foreach ( explode( ';', $block ) as $line ) {
				$at = strpos( $line, ':' );
				if ( $at !== false ) {
					$decl[ strtolower( trim( substr( $line, 0, $at ) ) ) ] = trim( substr( $line, $at + 1 ) );
				}
			}
			$family = trim( (string) ( $decl['font-family'] ?? '' ), " \t\"'" );
			if ( $family === '' || preg_match_all( '/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', (string) ( $decl['src'] ?? '' ), $urls ) === 0 ) {
				continue;
			}
			$out[] = array(
				'fontFamily' => $family,
				'fontWeight' => (string) ( $decl['font-weight'] ?? '400' ),
				'fontStyle'  => (string) ( $decl['font-style'] ?? 'normal' ),
				'src'        => array_values( array_map( 'esc_url_raw', $urls[1] ) ),
			);
		}

		return $out;
	}

	/**
	 * The content width the design asks for: the widest its sections let their content be, most often (`max-w-7xl` → 1280px); the
	 * theme's own when the design says nothing. The wide size is the theme's, or the content's when the theme has none.
	 *
	 * @return array<string, string>
	 */
	private static function layout( Document $doc, array $theme_layout = array() ): array {
		$votes = array();
		foreach ( $doc->pages() as $page ) {
			foreach ( is_array( $page['sections'] ?? null ) ? $page['sections'] : array() as $s ) {
				$w = (string) ( $s['layout']['max_width'] ?? '' );
				if ( isset( self::WIDTHS[ $w ] ) ) {
					$votes[ $w ] = ( $votes[ $w ] ?? 0 ) + 1;
				}
			}
		}
		if ( $votes === array() ) {
			return array();
		}
		$wide_of_theme = (string) ( $theme_layout['wideSize'] ?? ( $theme_layout !== array() ? '' : Capabilities::layout_sizes()['wide'] ) );
		arsort( $votes );
		$content = self::WIDTHS[ (string) array_key_first( $votes ) ] . 'px';
		$wide    = $wide_of_theme !== '' && self::px( $wide_of_theme ) >= self::px( $content ) ? $wide_of_theme : $content;

		return array(
			'contentSize' => $content,
			'wideSize'    => $wide,
		);
	}

	private static function px( string $value ): int {
		return preg_match( '/^(\d+(?:\.\d+)?)(px|rem|em)?$/', trim( $value ), $m ) === 1 ? (int) round( (float) $m[1] * ( ( $m[2] ?? 'px' ) === 'px' ? 1 : 16 ) ) : 0;
	}
}

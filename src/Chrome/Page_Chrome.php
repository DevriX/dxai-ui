<?php
/**
 * Site chrome around converted page bodies (not stored in post_content).
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Chrome;

use DXAI_UI\Pages\Section_Library;
use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Structures\Template_Part_Factory;
use DXAI_UI\Theme\Blank_Template;

/**
 * Converted pages store body sections only. The header and footer are
 * rendered around that body on the front (and in REST previews), so the
 * page editor is not cluttered with chrome blocks, and Widgets & Menus remain
 * the source of truth.
 *
 * Pages saved before that carry their chrome in their content, in any of
 * three forms: the site header/footer blocks, template-part references, or
 * the design's own header and footer groups at the top and bottom of the
 * content (the shape a Home keeps on a classic theme, and every page built
 * from such a Home). Section_Library::chrome_blocks() recognises all three,
 * and only an area the page lacks is added, so nothing is shown twice.
 *
 * What is added, per area, in this order:
 *  1. For a page built from a design's Home (its scope is another page): the
 *     Home's own header or footer, exactly as the Home keeps it — the same
 *     blocks a page built before body-only storage carried.
 *  2. For the design's own chrome mode `install` (not `keep`): the site header
 *     or footer block, when the one installed from Menus / Widgets is THIS
 *     design's. The site footer is one for the whole site, so a page of an
 *     earlier design does not take a later design's footer.
 *  3. The design's own template part (kept chrome, or install handed back).
 */
final class Page_Chrome {

	public const PART_KEY_META = '_dxai_ui_part_key';
	public const MODE_META     = '_dxai_ui_chrome';

	/**
	 * On a page of a design's site whose own copy of the design's header or footer the import took out (adopt_site_chrome()): the
	 * design's Home, whose header (from Menus) and footer (around Widgets) the page is drawn with, and, per area, the page's own
	 * numbers for the design's interactive elements in it (dxai-dc-N for the page's dxai-dc-M), so its own state script and state
	 * rules drive the menu as they drove its copy.
	 */
	public const SITE_CHROME_META = '_dxai_ui_site_chrome';

	/** Where the page's own content is, between the header and footer added as it is drawn: DX Blank makes it the page's <main>. */
	public const MAIN_OPEN  = '<!--dxai-ui:main-->';
	public const MAIN_CLOSE = '<!--/dxai-ui:main-->';

	/** @var array<string, array{header: string, footer: string}> Home id@modified => its chrome markup. */
	private static array $home_chrome = array();

	/** @var array<string, array{header: string, footer: string}> post id|content fingerprint => what is added (missing()). */
	private static array $missing = array();

	public function register(): void {
		// Before do_blocks (9): inject block comments so they render inside Page_Scope (20).
		add_filter( 'the_content', array( self::class, 'inject' ), 8 );
		// What is added is part of the page for its styles: its dxs- and utility rules are written with the page's (Style_Rules).
		add_filter( 'dxai_ui_style_rules_markup', array( self::class, 'rules_markup' ), 10, 2 );
	}

	/**
	 * Style_Rules' extra markup for a page: the header and footer this adds to it at render. They are not in the page's content, so
	 * without this a page that borrows its Home's header and footer drew them without the rules of their classes (a logo at its own
	 * size, a menu with no spacing, the skip link in view).
	 *
	 * @param array<int, string>|mixed $markups
	 * @param int|mixed                $post_id
	 * @return array<int, string>|mixed
	 */
	public static function rules_markup( $markups, $post_id = 0 ) {
		$post_id = (int) $post_id;
		if ( ! is_array( $markups ) || $post_id < 1 || ! self::composes( $post_id ) ) {
			return $markups;
		}
		$added = self::missing( $post_id, (string) get_post_field( 'post_content', $post_id ) );
		foreach ( array( $added['header'], $added['footer'] ) as $markup ) {
			if ( $markup !== '' ) {
				$markups[] = $markup;
			}
		}

		return $markups;
	}

	/**
	 * @param string|mixed $content
	 * @return string|mixed
	 */
	public static function inject( $content ) {
		if ( ! is_string( $content ) ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post instanceof \WP_Post || ! self::applies_to( (int) $post->ID ) ) {
			return $content;
		}

		$whole = self::with_chrome( (int) $post->ID, $content, true );

		// (Given nothing, the content is marked all the same: its <main> is inside its scope element, as the DX template draws it.)
		return $whole === $content ? self::MAIN_OPEN . "\n" . $content . "\n" . self::MAIN_CLOSE : $whole;
	}

	/**
	 * The page's content with the header and footer it lacks added around it (see the class comment). Content that
	 * already carries both areas comes back unchanged. Used for rendering, for export, and to find where a page's
	 * header and footer are edited.
	 */
	public static function with_chrome( int $post_id, string $content, bool $mark = false ): string {
		if ( $post_id < 1 || ! self::composes( $post_id ) ) {
			return $content;
		}
		$added  = self::missing( $post_id, $content );
		$header = $added['header'];
		$footer = $added['footer'];
		if ( $header === '' && $footer === '' ) {
			return $content;
		}
		$lead = '';
		if ( $header !== '' ) {
			// Where the design had it: after the skip link and the box that holds the place of a fixed header, which a design whose header is a
			// template part keeps in its pages.
			list( $lead, $content ) = Section_Library::split_lead( $content );
		}

		// (Drawn, the content is marked where it is: the template makes it the page's <main>, Template_Chrome::in_main().)
		$body = $mark ? self::MAIN_OPEN . "\n" . $content . "\n" . self::MAIN_CLOSE : $content;

		return trim( ( $lead !== '' ? $lead . "\n" : '' ) . $header . "\n" . $body . "\n" . $footer );
	}

	/**
	 * The header and footer a page lacks and so is given (with_chrome()), once per request for the same content: the page's styles,
	 * its scripts and its markup all ask.
	 *
	 * @return array{header: string, footer: string}
	 */
	private static function missing( int $post_id, string $content ): array {
		$key = $post_id . '|' . strlen( $content ) . '|' . crc32( $content );
		if ( ! isset( self::$missing[ $key ] ) ) {
			// As the page is read everywhere else: the group around a design's header, main and footer is opened (a page in the Home's
			// frame, Page_Frame, has its header and footer inside it).
			$blocks = Section_Library::flatten( parse_blocks( $content ) );
			$have   = Section_Library::chrome_blocks( $blocks );

			self::$missing[ $key ] = array(
				// The skip link, the islands and the box that holds the place of a fixed header are not a header: a design whose header is a
				// template part keeps them in its pages, and the part is still to be added.
				'header' => Section_Library::holds_header( $blocks, $have['header'] ) ? '' : self::header_markup( $post_id ),
				'footer' => $have['footer'] === array() ? self::footer_markup( $post_id ) : '',
			);
		}

		return self::$missing[ $key ];
	}

	/**
	 * Whether the chrome is put into this page's content as it renders: a design's page on DX Blank. A page on the DX template has it drawn
	 * by the template instead (Template_Chrome), so the content is left as it is.
	 */
	public static function applies_to( int $post_id ): bool {
		return $post_id > 0 && (string) get_page_template_slug( $post_id ) === Blank_Template::SLUG && self::composes( $post_id );
	}

	/**
	 * Whether the page is a design's page on one of the canvas templates, whichever of them draws its chrome: what the page's styles, its
	 * assets and its export compose with it.
	 */
	public static function composes( int $post_id ): bool {
		if ( $post_id < 1 || ! Blank_Template::is_canvas( $post_id ) ) {
			return false;
		}
		$scope = (int) get_post_meta( $post_id, Page_Scope::META, true );

		return $scope > 0 || (string) get_post_meta( $post_id, '_dxai_ui_generated_page', true ) === '1';
	}

	/**
	 * The header and footer a page of the DX template is drawn with: what its content lacks (see the class comment), as block markup.
	 *
	 * @return array{header: string, footer: string}
	 */
	public static function added( int $post_id, string $content ): array {
		return self::composes( $post_id ) ? self::missing( $post_id, $content ) : array(
			'header' => '',
			'footer' => '',
		);
	}

	/** Whether content carries a header or a footer of its own, in any form (see the class comment). */
	public static function already_has_chrome( string $content ): bool {
		$have = Section_Library::chrome_blocks( Section_Library::flatten( parse_blocks( $content ) ) );

		return $have['header'] !== array() || $have['footer'] !== array();
	}

	public static function header_markup( int $post_id ): string {
		$home = self::home_chrome( $post_id );
		if ( $home['header'] !== '' && self::holds_header( $home['header'] ) ) {
			return $home['header'];
		}
		$scope = self::scope_of( $post_id );
		if ( self::mode_of( $post_id ) !== Chrome_Choice::KEEP && $scope > 0 && Header_Template::scoped( $scope ) !== null ) {
			return Site_Header_Block::MARKUP;
		}
		// A page of the design's site whose copy of the header the import took out: its Home's header, from Menus.
		$home = self::adopted( $post_id, 'header' );
		if ( $home > 0 && Header_Template::scoped( $home ) !== null ) {
			return Site_Header_Block::MARKUP;
		}

		return self::part_markup( 'header', $post_id );
	}

	public static function footer_markup( int $post_id ): string {
		$home = self::home_chrome( $post_id );
		if ( $home['footer'] !== '' ) {
			return $home['footer'];
		}
		$scope  = self::scope_of( $post_id );
		$footer = Footer_Template::get();
		if ( self::mode_of( $post_id ) !== Chrome_Choice::KEEP && $footer !== array() && $scope > 0 && Site_Footer_Block::scope( $footer ) === $scope ) {
			return Site_Footer_Block::MARKUP;
		}
		// A page of the design's site whose copy of the footer the import took out: its Home's footer, around Widgets.
		$home = self::adopted( $post_id, 'footer' );
		if ( $home > 0 && $footer !== array() && Site_Footer_Block::scope( $footer ) === $home ) {
			return Site_Footer_Block::MARKUP;
		}

		return self::part_markup( 'footer', $post_id );
	}

	/**
	 * The Home whose header or footer a page of its design's site is drawn with (SITE_CHROME_META), 0 when the page keeps its own.
	 */
	public static function adopted( int $post_id, string $area = '' ): int {
		$meta = $post_id > 0 ? get_post_meta( $post_id, self::SITE_CHROME_META, true ) : '';
		if ( ! is_array( $meta ) || ( $area !== '' && ! isset( $meta[ $area ] ) ) ) {
			return 0;
		}

		return max( 0, (int) ( $meta['home'] ?? 0 ) );
	}

	/**
	 * A page of a multi-page design draws the design's header and footer in its own content, as every page of the design did: a Claude
	 * Design site's pages each import the header and footer component (Gemini Restoration's 12 pages beside the Home). The import puts
	 * the Home's into Appearance > Menus and Widgets; a copy left in a page would keep the design's links and words there, and a change
	 * in Menus would reach the Home only. So a page's copy is taken out when it is the very header or footer installed from the Home —
	 * the same markup, but for the numbers of the design's interactive elements, which each page counts for itself — and the page is
	 * drawn with the site's, as the Home is (header_markup(), footer_markup()). A copy that differs (a page that draws another header)
	 * stays where it is, and nothing is drawn twice.
	 *
	 * @param array<string, string> $sources area => the markup the Home's header or footer was installed from.
	 * @return array<int, string> The areas taken out.
	 */
	public static function adopt_site_chrome( int $post_id, int $home, array $sources ): array {
		if ( $post_id < 1 || $home < 1 || $post_id === $home ) {
			return array();
		}
		$content = (string) get_post_field( 'post_content', $post_id );
		$blocks  = parse_blocks( $content );
		$have    = Section_Library::chrome_blocks( $blocks );
		$meta    = array( 'home' => $home );
		$drop    = array();
		foreach ( array( 'header', 'footer' ) as $area ) {
			$source = (string) ( $sources[ $area ] ?? '' );
			if ( $source === '' ) {
				continue;
			}
			// The area's own element among the blocks the reader found there (the skip link and the islands around a header stay).
			foreach ( $have[ $area ] as $i ) {
				$tag = strtolower( (string) ( $blocks[ $i ]['attrs']['tagName'] ?? '' ) );
				if ( $tag !== $area ) {
					continue;
				}
				$ids = self::same_chrome( serialize_block( $blocks[ $i ] ), $source );
				if ( null !== $ids ) {
					$meta[ $area ] = $ids;
					$drop[]        = $i;
				}
				break;
			}
		}
		if ( $drop === array() ) {
			return array();
		}
		$keep = array();
		foreach ( $blocks as $i => $block ) {
			if ( ! in_array( $i, $drop, true ) ) {
				$keep[] = $block;
			}
		}
		$kses = (bool) has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}
		try {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => wp_slash( trim( serialize_blocks( $keep ) ) ),
				)
			);
		} finally {
			if ( $kses ) {
				kses_init_filters();
			}
		}
		update_post_meta( $post_id, self::SITE_CHROME_META, $meta );

		return array_values( array_diff( array_keys( $meta ), array( 'home' ) ) );
	}

	/**
	 * Whether a page's header or footer is the one installed from the Home: the same saved markup once the block delimiters (a name the
	 * List View shows, a block the native pass turned into another of the same element) and the classes WordPress adds to a block's
	 * element are set aside, and the design's interactive elements renumbered. The page's numbers for the Home's, or null.
	 *
	 * @return array<string, string>|null
	 */
	private static function same_chrome( string $mine, string $source ): ?array {
		$a = self::chrome_shape( $mine );
		$b = self::chrome_shape( $source );
		if ( $a['shape'] === '' || $a['shape'] !== $b['shape'] || count( $a['ids'] ) !== count( $b['ids'] ) ) {
			return null;
		}
		$map = array();
		foreach ( $b['ids'] as $n => $id ) {
			if ( $id !== $a['ids'][ $n ] ) {
				$map[ $id ] = $a['ids'][ $n ];
			}
		}

		return $map;
	}

	/**
	 * @return array{shape:string, ids:array<int, string>}
	 */
	private static function chrome_shape( string $markup ): array {
		// A block with no saved markup (a picture drawn at render) is compared by its attributes: its self-closing delimiter stays.
		$html = (string) preg_replace( '#<!--\s+/?wp:[a-z0-9/_-]+(?:\s+\{(?:(?!-->).)*\})?\s+-->#s', '', $markup );
		$ids  = array();
		if ( preg_match_all( '/\bdxai-dc-\d+\b/', $html, $m ) ) {
			foreach ( $m[0] as $id ) {
				if ( ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
			}
		}
		$html = (string) preg_replace( '/\bdxai-dc-\d+\b/', 'dxai-dc-#', $html );
		$html = (string) preg_replace_callback(
			'/\bclass="([^"]*)"/',
			static function ( array $m ): string {
				$names = preg_split( '/\s+/', trim( $m[1] ) ) ?: array();
				$names = array_filter( $names, static fn( string $c ): bool => $c !== '' && preg_match( '/^(?:wp-block-[\w-]+|amr-span|is-layout-[\w-]+|wp-container-[\w-]+)$/', $c ) !== 1 );

				return 'class="' . implode( ' ', $names ) . '"';
			},
			$html
		);

		return array(
			'shape' => trim( (string) preg_replace( '/\s+/', ' ', $html ) ),
			'ids'   => $ids,
		);
	}

	/**
	 * The header or footer drawn on a page of the design's site, in the page's own numbers for the design's interactive elements
	 * (adopt_site_chrome()).
	 */
	public static function own_ids( int $post_id, string $html ): string {
		$meta = $post_id > 0 ? get_post_meta( $post_id, self::SITE_CHROME_META, true ) : '';
		if ( ! is_array( $meta ) ) {
			return $html;
		}
		$map = array();
		foreach ( array( 'header', 'footer' ) as $area ) {
			foreach ( is_array( $meta[ $area ] ?? null ) ? $meta[ $area ] : array() as $from => $to ) {
				$map[ (string) $from ] = (string) $to;
			}
		}
		if ( $map === array() ) {
			return $html;
		}
		$out = preg_replace_callback( '/\bdxai-dc-\d+\b/', static fn( array $m ): string => $map[ $m[0] ] ?? $m[0], $html );

		return is_string( $out ) ? $out : $html;
	}

	public static function part_key_for( int $post_id ): string {
		$key = sanitize_title( (string) get_post_meta( $post_id, self::PART_KEY_META, true ) );
		if ( $key !== '' ) {
			return $key;
		}
		$scope = (int) get_post_meta( $post_id, Page_Scope::META, true );
		if ( $scope > 0 && $scope !== $post_id ) {
			$key = sanitize_title( (string) get_post_meta( $scope, self::PART_KEY_META, true ) );
		}

		return $key;
	}

	/**
	 * Remove site chrome blocks from page body markup (header/footer render via inject()). A pattern that fails
	 * (PCRE limits on a very large page) leaves the markup as it was, never empty.
	 */
	public static function strip_blocks( string $markup ): string {
		$out = preg_replace( '/<!--\s*wp:dxai-ui\/site-header\b[^>]*\/-->\s*/i', '', $markup );
		$out = is_string( $out ) ? preg_replace( '/<!--\s*wp:dxai-ui\/site-footer\b[^>]*\/-->\s*/i', '', $out ) : null;
		$out = is_string( $out ) ? preg_replace_callback(
			'/<!--\s*wp:template-part\s+(\{.*?\})\s*\/-->\s*/s',
			static function ( array $m ): string {
				$attrs = json_decode( $m[1], true );
				$slug  = is_array( $attrs ) ? (string) ( $attrs['slug'] ?? '' ) : '';
				$area  = is_array( $attrs ) ? (string) ( $attrs['area'] ?? '' ) : '';
				if ( in_array( $area, array( 'header', 'footer' ), true ) || preg_match( '/^dxai-(?:header|footer)/', $slug ) ) {
					return '';
				}

				return $m[0];
			},
			$out
		) : null;

		return is_string( $out ) ? trim( $out ) : $markup;
	}

	/**
	 * The header and footer of the Home a page was built from, as that Home keeps them in its content ('' for an
	 * area the Home does not carry, and for the Home itself).
	 *
	 * @return array{header: string, footer: string}
	 */
	private static function home_chrome( int $post_id ): array {
		$scope = (int) get_post_meta( $post_id, Page_Scope::META, true );
		if ( $scope < 1 || $scope === $post_id || get_post_status( $scope ) === false ) {
			return array(
				'header' => '',
				'footer' => '',
			);
		}
		$key = $scope . '@' . (string) get_post_field( 'post_modified_gmt', $scope );
		if ( ! isset( self::$home_chrome[ $key ] ) ) {
			self::$home_chrome[ $key ] = Section_Library::chrome_markup( $scope );
		}

		return self::$home_chrome[ $key ];
	}

	/** Whether markup (the Home's blocks at the top of its content) holds a header, and not only a skip link and what travels with it. */
	private static function holds_header( string $markup ): bool {
		$blocks = array_values( array_filter( parse_blocks( $markup ), static fn( $b ) => ! empty( $b['blockName'] ) ) );

		return Section_Library::holds_header( $blocks, array_keys( $blocks ) );
	}

	/** The design scope a page renders in: its own id for a design's Home. */
	public static function scope_of( int $post_id ): int {
		$scope = (int) get_post_meta( $post_id, Page_Scope::META, true );

		return $scope > 0 ? $scope : $post_id;
	}

	/** The chrome mode chosen at import, on the page or on its design's Home; '' when none was recorded. */
	private static function mode_of( int $post_id ): string {
		$mode = (string) get_post_meta( $post_id, self::MODE_META, true );
		$home = self::scope_of( $post_id );
		if ( $mode === '' && $home !== $post_id ) {
			$mode = (string) get_post_meta( $home, self::MODE_META, true );
		}

		return $mode;
	}

	/** The reference to the design's own template part for an area, or '' when it has none. */
	private static function part_markup( string $area, int $post_id ): string {
		$key = self::part_key_for( $post_id );
		if ( $key === '' || ! self::part_exists( Template_Part_Factory::slug( $area, $key ) ) ) {
			return '';
		}

		return ( new Template_Part_Factory() )->reference_markup( $area, $key );
	}

	private static function part_exists( string $slug ): bool {
		$found = get_posts(
			array(
				'post_type'              => 'wp_template_part',
				'name'                   => $slug,
				'post_status'            => array( 'publish', 'draft', 'private' ),
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return $found !== array();
	}
}

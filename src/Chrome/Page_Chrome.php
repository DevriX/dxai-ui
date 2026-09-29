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

	/** @var array<string, array{header: string, footer: string}> Home id@modified => its chrome markup. */
	private static array $home_chrome = array();

	public function register(): void {
		// Before do_blocks (9): inject block comments so they render inside Page_Scope (20).
		add_filter( 'the_content', array( self::class, 'inject' ), 8 );
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

		return self::with_chrome( (int) $post->ID, $content );
	}

	/**
	 * The page's content with the header and footer it lacks added around it (see the class comment). Content that
	 * already carries both areas comes back unchanged. Used for rendering, for export, and to find where a page's
	 * header and footer are edited.
	 */
	public static function with_chrome( int $post_id, string $content ): string {
		if ( $post_id < 1 || ! self::applies_to( $post_id ) ) {
			return $content;
		}
		$have   = Section_Library::chrome_blocks( parse_blocks( $content ) );
		$header = $have['header'] === array() ? self::header_markup( $post_id ) : '';
		$footer = $have['footer'] === array() ? self::footer_markup( $post_id ) : '';
		if ( $header === '' && $footer === '' ) {
			return $content;
		}

		return trim( $header . "\n" . $content . "\n" . $footer );
	}

	public static function applies_to( int $post_id ): bool {
		if ( $post_id < 1 ) {
			return false;
		}
		if ( (string) get_page_template_slug( $post_id ) !== Blank_Template::SLUG ) {
			return false;
		}
		$scope = (int) get_post_meta( $post_id, Page_Scope::META, true );

		return $scope > 0 || (string) get_post_meta( $post_id, '_dxai_ui_generated_page', true ) === '1';
	}

	/** Whether content carries a header or a footer of its own, in any form (see the class comment). */
	public static function already_has_chrome( string $content ): bool {
		$have = Section_Library::chrome_blocks( parse_blocks( $content ) );

		return $have['header'] !== array() || $have['footer'] !== array();
	}

	public static function header_markup( int $post_id ): string {
		$home = self::home_chrome( $post_id );
		if ( $home['header'] !== '' ) {
			return $home['header'];
		}
		$scope = self::scope_of( $post_id );
		if ( self::mode_of( $post_id ) !== Chrome_Choice::KEEP && $scope > 0 && Header_Template::scoped( $scope ) !== null ) {
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

		return self::part_markup( 'footer', $post_id );
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

	/** The design scope a page renders in: its own id for a design's Home. */
	private static function scope_of( int $post_id ): int {
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

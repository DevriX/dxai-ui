<?php
/**
 * Site chrome around converted page bodies (not stored in post_content).
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Chrome;

use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Structures\Template_Part_Factory;
use DXAI_UI\Theme\Blank_Template;

/**
 * Converted pages store body sections only. Header and footer are rendered
 * around that body on the front (and in REST previews) from Appearance >
 * Menus / Widgets when installed, or from this design's template parts when
 * kept — so the page editor is not cluttered with chrome blocks and Widgets
 * & Menus remain the source of truth.
 */
final class Page_Chrome {

	public const PART_KEY_META = '_dxai_ui_part_key';
	public const MODE_META     = '_dxai_ui_chrome';

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
		if ( ! $post instanceof \WP_Post ) {
			return $content;
		}
		if ( ! self::applies_to( (int) $post->ID ) ) {
			return $content;
		}
		if ( self::already_has_chrome( $content ) ) {
			return $content;
		}

		$header = self::header_markup( (int) $post->ID );
		$footer = self::footer_markup( (int) $post->ID );
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

	public static function already_has_chrome( string $content ): bool {
		return (bool) preg_match(
			'/<!--\s*wp:(?:dxai-ui\/site-header|dxai-ui\/site-footer|template-part)\b/i',
			$content
		);
	}

	public static function header_markup( int $post_id ): string {
		if ( Header_Template::for_post( $post_id ) !== null ) {
			return Site_Header_Block::MARKUP;
		}
		$key = self::part_key_for( $post_id );
		if ( $key === '' ) {
			return '';
		}
		$slug = Template_Part_Factory::slug( 'header', $key );
		if ( ! self::part_exists( $slug ) ) {
			return '';
		}

		return ( new Template_Part_Factory() )->reference_markup( 'header', $key );
	}

	public static function footer_markup( int $post_id ): string {
		$footer = Footer_Template::get();
		if ( $footer !== array() ) {
			// Site footer from Widgets — same stub Site_Footer_Block expects.
			return Site_Footer_Block::MARKUP;
		}
		$key = self::part_key_for( $post_id );
		if ( $key === '' ) {
			return '';
		}
		$slug = Template_Part_Factory::slug( 'footer', $key );
		if ( ! self::part_exists( $slug ) ) {
			return '';
		}

		return ( new Template_Part_Factory() )->reference_markup( 'footer', $key );
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
	 * Remove site chrome blocks from page body markup (header/footer render via inject()).
	 */
	public static function strip_blocks( string $markup ): string {
		$markup = (string) preg_replace(
			'/<!--\s*wp:dxai-ui\/site-header\b[^>]*\/-->\s*/i',
			'',
			$markup
		);
		$markup = (string) preg_replace(
			'/<!--\s*wp:dxai-ui\/site-footer\b[^>]*\/-->\s*/i',
			'',
			$markup
		);
		$markup = (string) preg_replace_callback(
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
			$markup
		);

		return trim( $markup );
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

<?php
/**
 * The design a page shows: its own, or the one its copied blocks come from.
 *
 * A converted page carries its design with it as post meta — the design's
 * stylesheet (with its colour tokens), fonts, script and scope class. A block
 * copied from such a page into an ordinary page brings only its own CSS
 * (Style_Rules prints that for any page), so on the ordinary page it lost the
 * design's sheet, colours, fonts and the script that opens its FAQ. This
 * answers, for any page, whose design assets it shows: a converted page its
 * own; an ordinary page holding design blocks the design chosen for it
 * (self::META), or else the site's brand design, or the only design there is.
 * Assets, Page_Scope and the editor canvas ask it, and all of them run in the
 * rendering half the must-use runtime keeps booting, so such a page keeps its
 * look with the plugin deactivated or deleted as well.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Structures;

use DXAI_UI\Support\Upload_Paths;

final class Design_Attach {

	/** Post meta on an ordinary page: the design (its Home page id) to show; -1 for none; absent for automatic. */
	public const META = '_dxai_ui_design';

	/** @var array<int, int> Post id => the page whose design it shows (0: none). */
	private static array $cache = array();

	/** @var int|null The site's default design (brand, or the only one). */
	private static ?int $default = null;

	public function register(): void {
		register_post_meta(
			'page',
			self::META,
			array(
				'type'          => 'integer',
				'single'        => true,
				'show_in_rest'  => true,
				'default'       => 0,
				'auth_callback' => static fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', (int) $post_id ),
			)
		);
		// A page's content changes what it shows.
		add_action( 'save_post', static fn( $post_id ) => self::forget( (int) $post_id ) );
	}

	/**
	 * The page whose design (sheet, fonts, script, scope) a post shows, or 0 for none.
	 */
	public static function source_for( int $post_id ): int {
		if ( $post_id < 1 ) {
			return 0;
		}
		if ( ! array_key_exists( $post_id, self::$cache ) ) {
			self::$cache[ $post_id ] = self::resolve( $post_id );
		}

		return self::$cache[ $post_id ];
	}

	/** Whether the post shows a design it does not carry itself (an ordinary page with copied design blocks). */
	public static function attached( int $post_id ): bool {
		$source = self::source_for( $post_id );

		return $source > 0 && $source !== $post_id;
	}

	/** The scope id a post's content is wrapped in (Page_Scope): its own, or its attached design's. */
	public static function scope_for( int $post_id ): int {
		$own = (int) get_post_meta( $post_id, Page_Scope::META, true );
		if ( $own > 0 ) {
			return $own;
		}
		if ( ! self::attached( $post_id ) ) {
			return 0;
		}
		$source = self::source_for( $post_id );
		$scope  = (int) get_post_meta( $source, Page_Scope::META, true );

		return $scope > 0 ? $scope : $source;
	}

	public static function forget( int $post_id ): void {
		unset( self::$cache[ $post_id ] );
	}

	private static function resolve( int $post_id ): int {
		// A converted page carries its own design.
		if ( Upload_Paths::for_meta( $post_id, '_dxai_ui_css_url' )['url'] !== '' ) {
			return $post_id;
		}
		$chosen = (int) get_post_meta( $post_id, self::META, true );
		if ( $chosen < 0 ) {
			return 0;
		}
		if ( ! self::holds_design_blocks( (string) get_post_field( 'post_content', $post_id ) ) ) {
			return 0;
		}
		if ( $chosen > 0 && self::is_design( $chosen ) ) {
			return $chosen;
		}

		return self::default_design();
	}

	/** Whether content holds blocks of an imported design (this plugin's blocks, or blocks with their CSS). */
	public static function holds_design_blocks( string $content ): bool {
		return str_contains( $content, '<!-- wp:dxai-ui/' )
			|| str_contains( $content, '"dxaiCss"' )
			|| preg_match( '/class="[^"]*\bdxs-[a-z0-9]+\b/', $content ) === 1;
	}

	/** Whether a page is a design's Home (or one of its own pages): converted, its own scope, not built from an old site. */
	public static function is_design( int $page_id ): bool {
		return $page_id > 0
			&& get_post_type( $page_id ) === 'page'
			&& get_post_status( $page_id ) !== 'trash'
			&& Upload_Paths::for_meta( $page_id, '_dxai_ui_css_url' )['url'] !== ''
			&& (int) get_post_meta( $page_id, Page_Scope::META, true ) === $page_id
			&& ! get_post_meta( $page_id, '_dxai_ui_from_live_menu', true );
	}

	/**
	 * The designs on this site (their Home pages), newest first.
	 *
	 * @return array<int, \WP_Post>
	 */
	public static function designs(): array {
		$out = array();
		foreach (
			get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => array( 'publish', 'draft', 'private' ),
					'posts_per_page' => 100,
					'orderby'        => 'modified',
					'order'          => 'DESC',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'meta_query'     => array(
						array(
							'key'   => '_dxai_ui_generated_page',
							'value' => '1',
						),
						array(
							'key'     => '_dxai_ui_from_live_menu',
							'compare' => 'NOT EXISTS',
						),
					),
				)
			) as $page
		) {
			if ( self::is_design( (int) $page->ID ) ) {
				$out[] = $page;
			}
		}

		return $out;
	}

	/** The site's brand design (Design_Theme_Json), else the only design there is, else 0. */
	public static function default_design(): int {
		if ( self::$default !== null ) {
			return self::$default;
		}
		$designs = self::designs();
		$option  = get_option( \DXAI_UI\Theme\Design_Theme_Json::OPTION );
		$source  = is_array( $option ) ? (string) ( $option['source'] ?? '' ) : '';
		$pick    = 0;
		foreach ( $designs as $page ) {
			if ( $source !== '' && (string) get_post_meta( $page->ID, '_dxai_ui_source_zip', true ) === $source ) {
				$pick = (int) $page->ID;
				break;
			}
		}
		if ( $pick === 0 ) {
			// Without a brand: the design every design page belongs to, when there is just one design (one archive).
			$archives = array_unique( array_map( static fn( $p ) => (string) get_post_meta( $p->ID, '_dxai_ui_source_zip', true ), $designs ) );
			if ( count( $archives ) === 1 && $designs !== array() ) {
				$pick = (int) end( $designs )->ID; // the oldest page of it: its Home
			}
		}
		self::$default = $pick;

		return $pick;
	}
}

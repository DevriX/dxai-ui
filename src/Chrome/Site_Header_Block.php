<?php
/**
 * The `dxai-ui/site-header` block.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Chrome;

use DXAI_UI\Blocks\Style_Rules;
use DXAI_UI\Compiler\Style_Hoister;
use DXAI_UI\Structures\Page_Scope;

/**
 * The header as one dynamic block. It has no content of its own: it renders
 * the page's design header (Header_Template::for_post()) with the current
 * menus (Header_Menus) through Header_Renderer, so a page stores
 * `<!-- wp:dxai-ui/site-header /-->` and nothing a person could get out of
 * step with Appearance > Menus.
 *
 * Rendered where the header part was rendered — inside the page content,
 * inside Page_Scope's design scope — so the design's scoped stylesheet, its
 * dxs- rules and the runtime all reach it exactly as they reached the part.
 * Which design's header a page renders follows the page's scope: a page of
 * an earlier design keeps that design's header after a newer one became the
 * site's, because only its own header matches its scoped stylesheet.
 *
 * In the editor it is a live server-side render of the real header, with a
 * way to Appearance > Menus and a Site Logo picker (assets/js/site-header-editor.js).
 */
final class Site_Header_Block {

	public const NAME = 'dxai-ui/site-header';

	/** What a page stores for the header (Header_Menus::install() returns it). */
	public const MARKUP = '<!-- wp:dxai-ui/site-header /-->';

	private const SCRIPT = 'dxai-ui-site-header-editor';
	private const STYLE  = 'dxai-ui-site-header';
	private const RULES  = 'dxai-ui-site-header-rules';

	/** Style_Rules' filter for markup it should collect rules from beside the page's. */
	public const RULES_FILTER = 'dxai_ui_style_rules_markup';

	/** @var array<string, array{root:array<string, mixed>, flyout:bool, scope:int}> This request's filled headers, by key(). */
	private static array $filled = array();

	/** @var array<string, bool> Filled headers (by key()) whose rules are already on their way to the page. */
	private static array $rules_out = array();

	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
		add_filter( self::RULES_FILTER, array( $this, 'style_rules_markup' ), 10, 2 );
		// A menu saved in this request (the Customizer, a save that renders).
		foreach ( array( 'wp_update_nav_menu', 'wp_update_nav_menu_item', 'wp_delete_nav_menu', 'update_option_' . Header_Template::OPTION ) as $hook ) {
			add_action( $hook, array( self::class, 'forget' ) );
		}
		// After Style_Rules::front() (30), so it can tell whether that asked the filter.
		add_action( 'wp_enqueue_scripts', array( $this, 'front_assets' ), 31 );
		// After Design_Blocks::enqueue_editor_canvas() (10), for the same reason.
		add_action( 'enqueue_block_assets', array( $this, 'canvas_assets' ), 20 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_data' ) );
		add_filter( 'block_editor_rest_api_preload_paths', array( self::class, 'preload' ), 10, 2 );
	}

	public function register_block(): void {
		wp_register_script(
			self::SCRIPT,
			DXAI_UI_URL . 'assets/js/site-header-editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n', 'wp-data', 'wp-core-data' ),
			(string) filemtime( DXAI_UI_DIR . 'assets/js/site-header-editor.js' ),
			true
		);
		wp_register_style(
			self::STYLE,
			DXAI_UI_URL . 'assets/css/site-header-front.css',
			array(),
			(string) filemtime( DXAI_UI_DIR . 'assets/css/site-header-front.css' )
		);

		register_block_type(
			self::NAME,
			array(
				'api_version'     => 3,
				'title'           => __( 'DX Site Header', 'dxai-ui' ),
				'description'     => __( 'The site header: links from Appearance > Menus, the logo from the Site Logo, the look from the imported design.', 'dxai-ui' ),
				'category'        => 'dx-blocks',
				'icon'            => 'menu',
				'keywords'        => array( 'header', 'menu', 'navigation', 'logo' ),
				'editor_script'   => self::SCRIPT,
				'render_callback' => array( $this, 'render' ),
				'attributes'      => array(),
				// Which design's header: the scope of the post it renders in.
				'uses_context'    => array( 'postId' ),
				'supports'        => array(
					// Nothing to edit as HTML, one header per page, and no
					// class or wrapper the design did not draw.
					'html'            => false,
					'multiple'        => false,
					'reusable'        => false,
					'className'       => false,
					'customClassName' => false,
					/*
					 * Placed by the importer at the top of a converted page,
					 * not picked from the inserter: anywhere else there is no
					 * design scope and no scoped stylesheet around it, and the
					 * header renders with both its desktop and its mobile rows
					 * showing. A page that has it keeps it; the editor says so
					 * when it sits on a page of no design (the editor script).
					 */
					'inserter'        => false,
				),
			)
		);
	}

	/**
	 * The header's HTML.
	 *
	 * @param array<string, mixed> $attributes
	 * @param mixed                $block The WP_Block being rendered.
	 */
	public function render( $attributes = array(), string $content = '', $block = null ): string {
		unset( $attributes, $content );
		$post_id = $block instanceof \WP_Block ? (int) ( $block->context['postId'] ?? 0 ) : 0;
		$post_id = $post_id > 0 ? $post_id : (int) get_the_ID();
		$filled  = self::filled( $post_id );
		if ( null === $filled ) {
			return '';
		}
		if ( $filled['flyout'] ) {
			// Printed with the footer styles when <head> is gone; a flyout's
			// panel starts hidden, so nothing on screen waits for it.
			wp_enqueue_style( self::STYLE );
		}
		$rest = defined( 'REST_REQUEST' ) && REST_REQUEST;
		if ( ! isset( self::$rules_out[ $filled['key'] ] ) && ! is_admin() && ! $rest ) {
			/*
			 * Rendered where the page's content is not — a template part, a
			 * widget, another post's template — so no Style_Rules pass saw it:
			 * its rules come now rather than not at all, through the same
			 * path as the page's, so a utility class already printed for the
			 * page is not printed a second time (which would reorder it
			 * against the others). The page-content case never gets here.
			 */
			Style_Rules::attach_markup( Header_Renderer::markup( $filled['root'] ), $filled['scope'] );
			self::$rules_out[ $filled['key'] ] = true;
		}

		return Header_Renderer::html( $filled['root'] );
	}

	/**
	 * The header a post renders, filled from the current menus, once per
	 * request. Style_Rules asks for its markup in <head> and the block renders
	 * it in the content; both read this one tree, so the rules printed are
	 * the rules of exactly what renders, and the menus are read once.
	 *
	 * @return array{key:string, root:array<string, mixed>, flyout:bool, scope:int}|null
	 */
	private static function filled( int $post_id ): ?array {
		$spec = Header_Template::for_post( $post_id );
		if ( null === $spec ) {
			return null;
		}
		$logo = Header_Menus::logo();
		// The current item (aria-current) depends on the page being viewed.
		$key = (string) ( $spec['hash'] ?? '' ) . '|' . Header_Template::scope_of( $spec ) . '|' . (string) wp_json_encode( $logo ) . '|' . (int) get_queried_object_id();
		if ( ! isset( self::$filled[ $key ] ) ) {
			$renderer             = new Header_Renderer( $spec, Header_Menus::trees( $spec ), $logo );
			$root                 = $renderer->root();
			self::$filled[ $key ] = array(
				'root'   => $root,
				'flyout' => $renderer->drew_flyout(),
				'scope'  => Header_Template::scope_of( $spec ),
			);
		}

		return array( 'key' => $key ) + self::$filled[ $key ];
	}

	/**
	 * Drop this request's filled headers: a menu or the header changed.
	 */
	public static function forget(): void {
		self::$filled = array();
	}

	/**
	 * Hand Style_Rules the header for a page holding the block, so its rules
	 * print with the page's own: the header as filled from the menus, whose
	 * blocks carry the design's dxaiCss and dxaiInner, and whose items carry
	 * the classes a person gave them in Appearance > Menus — a theme utility
	 * class typed there gets its rule the way one typed into a block's
	 * Additional CSS class(es) does.
	 *
	 * @param mixed $markup
	 * @param mixed $post_id
	 * @return array<int, string>
	 */
	public function style_rules_markup( $markup, $post_id = 0 ): array {
		$markup = is_array( $markup ) ? $markup : array();
		if ( ! self::holds_block( (int) $post_id ) ) {
			return $markup;
		}
		$filled = self::filled( (int) $post_id );
		if ( null !== $filled ) {
			$markup[]                          = Header_Renderer::markup( $filled['root'] );
			self::$rules_out[ $filled['key'] ] = true;
		}

		return $markup;
	}

	/**
	 * Front end: the flyout sheet in <head> when the header draws a flyout,
	 * and the header's rules when no Style_Rules asked the filter above.
	 */
	public function front_assets(): void {
		$post_id = (int) get_queried_object_id();
		if ( ! is_singular() || ! self::holds_block( $post_id ) ) {
			return;
		}
		$filled = self::filled( $post_id );
		if ( null !== $filled && $filled['flyout'] ) {
			wp_enqueue_style( self::STYLE );
		}
		$this->rules_fallback( $post_id );
	}

	/**
	 * Editor canvas: the same, so the server-side preview is styled.
	 */
	public function canvas_assets(): void {
		if ( ! is_admin() ) {
			return;
		}
		$post_id = self::editing_post_id();
		if ( null === Header_Template::for_post( $post_id ) ) {
			return;
		}
		wp_enqueue_style( self::STYLE );
		$this->rules_fallback( $post_id );
	}

	/**
	 * The header's rules — dxs-, dxaiInner and the theme utility classes its
	 * blocks and menu items carry — printed here only when nothing asked the
	 * Style_Rules filter this request: a Style_Rules that reads it prints them
	 * itself, and printing both would only double the bytes.
	 *
	 * Through Style_Rules itself, read against the header's own design scope:
	 * on the front end attach_markup(), which leaves out a utility rule the
	 * request already printed (a second copy would reorder it against the
	 * others) and puts the rules in <head> while there still is one; in the
	 * editor canvas css_for_markup(), which writes the editor form of the
	 * utilities there. rules_css() alone wrote only the dxs- rules, so the
	 * header's utility classes had no rule on this path.
	 */
	private function rules_fallback( int $post_id ): void {
		if ( did_filter( self::RULES_FILTER ) > 0 ) {
			return;
		}
		$filled = self::filled( $post_id );
		if ( null === $filled ) {
			return;
		}
		$markup = Header_Renderer::markup( $filled['root'] );
		if ( is_admin() ) {
			Style_Rules::attach( self::RULES, Style_Rules::css_for_markup( $markup, $filled['scope'] ) );
		} else {
			Style_Rules::attach_markup( $markup, $filled['scope'] );
		}
		self::$rules_out[ $filled['key'] ] = true;
	}

	/**
	 * Preload the editor preview's render (block_editor_rest_api_preload_paths)
	 * for a post that holds the block, so the header shows with the canvas
	 * instead of seconds after it: ServerSideRender has nothing to draw until
	 * its first response (measured, the header stayed 0px tall for about 8 s
	 * after the editor was ready). The path is the one ServerSideRender asks
	 * for — context=edit and the post's id; apiFetch's preloading middleware
	 * compares paths with their query arguments sorted.
	 *
	 * @param mixed $paths
	 * @param mixed $context \WP_Block_Editor_Context.
	 * @return mixed
	 */
	public static function preload( $paths, $context = null ) {
		$post = $context instanceof \WP_Block_Editor_Context ? $context->post : null;
		if ( ! is_array( $paths ) || ! $post instanceof \WP_Post || ! has_block( self::NAME, $post ) || null === Header_Template::for_post( (int) $post->ID ) ) {
			return $paths;
		}
		$paths[] = '/wp/v2/block-renderer/' . self::NAME . '?context=edit&post_id=' . (int) $post->ID;

		return $paths;
	}

	/**
	 * The dxs- rules a markup string's blocks carry (dxaiCss, dxaiInner).
	 */
	public static function rules_css( string $markup ): string {
		$rules = array();
		$walk  = static function ( array $blocks ) use ( &$walk, &$rules ): void {
			foreach ( $blocks as $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}
				$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
				$css   = $attrs[ Style_Hoister::ATTR ] ?? '';
				if ( is_string( $css ) && trim( $css ) !== '' ) {
					$rules[ Style_Hoister::css_class( $css ) ] = $css;
				}
				foreach ( (array) ( $attrs[ Style_Hoister::INNER_ATTR ] ?? array() ) as $class => $declarations ) {
					if ( is_string( $class ) && is_string( $declarations ) ) {
						$rules[ $class ] = $declarations;
					}
				}
				$walk( (array) ( $block['innerBlocks'] ?? array() ) );
			}
		};
		$walk( parse_blocks( $markup ) );

		$out = '';
		foreach ( $rules as $class => $css ) {
			$out .= Style_Rules::rule( (string) $class, (string) $css );
		}

		return $out;
	}

	/**
	 * What the editor side shows: which design's header this page renders,
	 * the menus behind each of its locations and where to edit them, and
	 * the logos.
	 */
	public function editor_data(): void {
		$post_id = self::editing_post_id();
		$spec    = Header_Template::for_post( $post_id );
		$menus   = array();
		if ( null !== $spec ) {
			foreach ( Header_Menus::locations() as $location => $label ) {
				$has = false;
				foreach ( (array) $spec['slots'] as $slot ) {
					$has = $has || $slot['location'] === $location;
				}
				if ( ! $has ) {
					continue;
				}
				$id      = Header_Menus::menu_for( $location, $spec );
				$term    = $id > 0 ? get_term( $id, 'nav_menu' ) : null;
				$menus[] = array(
					'location' => $location,
					'label'    => $label,
					// Term names are stored entity-escaped ("A &amp; B").
					'menu'     => $term instanceof \WP_Term ? wp_specialchars_decode( $term->name, ENT_QUOTES ) : '',
					'editUrl'  => $id > 0 ? admin_url( 'nav-menus.php?action=edit&menu=' . $id ) : admin_url( 'nav-menus.php' ),
				);
			}
		}
		$logo = Header_Menus::logo();

		wp_localize_script(
			self::SCRIPT,
			'dxaiSiteHeader',
			array(
				'installed'     => null !== $spec,
				// A page of no converted design: no scoped stylesheet styles the header here.
				'scoped'        => $post_id > 0 && (int) get_post_meta( $post_id, Page_Scope::META, true ) > 0,
				// Whether this page's header is the site's (the theme locations
				// are its menus) or an earlier design's, read from its own menus.
				'siteHeader'    => null !== $spec && Header_Template::is_site( $spec ),
				'design'        => null !== $spec ? (string) ( $spec['source']['title'] ?? '' ) : '',
				// Menu levels the design draws (1: no dropdowns, 2, 3 with flyouts).
				'levels'        => null !== $spec ? Header_Renderer::levels( $spec ) : 0,
				'menus'         => $menus,
				'menusUrl'      => admin_url( 'nav-menus.php' ),
				'locationsUrl'  => admin_url( 'nav-menus.php?action=locations' ),
				'canEditMenus'  => current_user_can( 'edit_theme_options' ),
				'designLogo'    => array(
					'id'  => (int) ( $spec['logo']['attachment_id'] ?? 0 ),
					'url' => (string) ( $spec['logos'][0]['src'] ?? '' ),
				),
				'siteLogoUrl'   => (string) ( $logo['url'] ?? '' ),
				'customizerUrl' => current_theme_supports( 'custom-logo' ) ? admin_url( 'customize.php?autofocus[section]=title_tagline' ) : '',
			)
		);
	}

	/**
	 * The post the block editor is editing: its screen's `post`, else the
	 * global post.
	 */
	private static function editing_post_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which post the screen shows; nothing is written.
		$post_id = isset( $_GET['post'] ) && is_scalar( $_GET['post'] ) ? absint( wp_unslash( (string) $_GET['post'] ) ) : 0;
		if ( $post_id < 1 && isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof \WP_Post ) {
			$post_id = (int) $GLOBALS['post']->ID;
		}

		return $post_id;
	}

	/**
	 * Whether a post's content holds the block.
	 */
	private static function holds_block( int $post_id ): bool {
		return $post_id > 0 && has_block( self::NAME, $post_id );
	}
}

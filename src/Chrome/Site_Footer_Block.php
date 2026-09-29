<?php
/**
 * The site footer block: the design's frame around the footer's widgets.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Chrome;

use DXAI_UI\Blocks\Style_Rules;
use DXAI_UI\Compiler\Style_Hoister;
use DXAI_UI\Compiler\Token_Styles;
use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Support\Upload_Paths;

/**
 * `dxai-ui/site-footer`, a dynamic block with nothing stored in it: it renders
 * the stored footer frame (Footer_Template) with each slot replaced by what
 * its widget area renders (Footer_Widgets::render_area()). While the areas
 * hold exactly the design's blocks, the result is the design's footer markup
 * rendered — the same DOM do_blocks() makes of the footer's block markup —
 * and whatever a person changes in Appearance > Widgets is in the footer on
 * the next view. Nothing is cached, so no cache can serve a footer whose
 * blocks were never rendered: WordPress 6.9+ loads a core block's stylesheet
 * only when that block renders, and every block of the frame and of the
 * widgets renders here, on every view.
 *
 * The footer belongs to ONE design — the last import that had a footer — and
 * its frame and widgets are styled by that design: its scoped stylesheet
 * (`.dxai-ui.dxai-ui--{scope} …`), its `--dxai-*` tokens and its fonts.
 * Rendered in the content of a page of that design, inside Page_Scope's
 * scope element, the block is bare: exactly the design's markup, where the
 * design had it. Rendered anywhere else — a page of another converted design,
 * an ordinary page, a theme template — it brings its design along (frame()):
 * the footer inside the same scope element its own page gives it, the
 * design's stylesheet, tokens and fonts enqueued (design_assets()), and, in
 * another design's page, moved out of that page's scope element so the
 * other design's scoped rules cannot reach it (lift()). Measured on a page of
 * the other design on this site (Semper Dry's Lovable import, the brand):
 * 67 of 67 footer elements at the same box as on the footer's own page, at
 * 1280, 768 and 390; before, 66 of 67 moved (up to 216px) with no
 * background, black text and no Lato.
 *
 * The footer's `dxs-` rules and utility classes are not in the page's
 * content. For a page of the footer's design they go to the page's rule
 * collection (Style_Rules) through `dxai_ui_style_rules_markup`, on the front
 * end and in the editor canvas alike; for any other page they are written by
 * design_assets(), read against the footer's design (its class names are its
 * own, not theme utilities). Where neither runs — the block rendered after
 * <head> where Style_Rules did not look — they go out with the late styles
 * (late_rules()); in the editor preview of a post that does not hold the
 * block yet, inside the preview (preview_rules()).
 */
final class Site_Footer_Block {

	public const NAME   = 'dxai-ui/site-footer';
	public const MARKUP = '<!-- wp:dxai-ui/site-footer /-->';

	private const SCRIPT = 'dxai-ui-site-footer-editor';

	/** The rules of the element that carries the footer's design elsewhere (frame()). */
	private const FRONT_STYLE = 'dxai-ui-site-footer';

	/** The footer design's stylesheet, tokens and preset fallbacks, for a page of another design. */
	private const DESIGN_STYLE = 'dxai-ui-site-footer-design';

	/** Around a framed footer in a page's content, until lift() has moved it. */
	private const LIFT_OPEN  = '<!--dxai-ui:site-footer-->';
	private const LIFT_CLOSE = '<!--/dxai-ui:site-footer-->';

	/**
	 * How a design's stylesheet and the brand's override declare the tokens
	 * for every converted page (Token_Styles::definitions_css(), brand_css()).
	 */
	private const GENERIC_TOKENS = ':root,.dxai-ui.dxai-ui{';

	/** Nesting depth of render(): a footer inside its own widget areas renders nothing. */
	private static int $depth = 0;

	/** @var array<int, bool> Posts whose rules Style_Rules collected with the footer's. */
	private static array $covered = array();

	/** @var array<int, bool> Posts whose content rendered the bare footer in this request. */
	private static array $bare = array();

	/** Whether this request has written the footer's rules already. */
	private static bool $rules_out = false;

	/** Whether this request is the editor's ServerSideRender preview of the block. */
	private static bool $preview = false;

	/**
	 * @var array<int, int> The design scope of each post whose `the_content`
	 * is running, innermost last (0 for a post Page_Scope does not wrap).
	 */
	private static array $contexts = array();

	public function register(): void {
		add_action( 'init', array( self::class, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_data' ) );
		add_filter( 'dxai_ui_style_rules_markup', array( self::class, 'style_rules_markup' ), 10, 2 );
		add_filter( 'rest_request_before_callbacks', array( self::class, 'note_preview' ), 10, 3 );
		// Around every `the_content`: first of all, and after Page_Scope's
		// wrapper (20), so the footer can leave another design's scope.
		add_filter( 'the_content', array( self::class, 'enter_content' ), 0 );
		add_filter( 'the_content', array( self::class, 'leave_content' ), 21 );
		// After Style_Rules::front() (30), whose collection this completes.
		add_action( 'wp_enqueue_scripts', array( self::class, 'front_assets' ), 31 );
		// After Design_Blocks::enqueue_editor_canvas() (10).
		add_action( 'enqueue_block_assets', array( self::class, 'canvas_assets' ), 20 );
		add_filter( 'block_editor_rest_api_preload_paths', array( self::class, 'preload' ), 10, 2 );
	}

	/**
	 * Preload the editor preview's render for a post that holds the block
	 * (block_editor_rest_api_preload_paths), so the footer is in the canvas
	 * when the editor opens rather than after its first request (measured
	 * about 6 s later on this site). The path ServerSideRender asks for:
	 * context=edit and the post's id.
	 *
	 * @param mixed $paths
	 * @param mixed $context \WP_Block_Editor_Context.
	 * @return mixed
	 */
	public static function preload( $paths, $context = null ) {
		$post = $context instanceof \WP_Block_Editor_Context ? $context->post : null;
		if ( ! is_array( $paths ) || ! $post instanceof \WP_Post || ! has_block( self::NAME, $post ) || Footer_Template::get() === array() ) {
			return $paths;
		}
		$paths[] = '/wp/v2/block-renderer/' . self::NAME . '?context=edit&post_id=' . (int) $post->ID;

		return $paths;
	}

	public static function register_block(): void {
		$file = DXAI_UI_DIR . 'assets/js/site-footer-editor.js';
		wp_register_script(
			self::SCRIPT,
			DXAI_UI_URL . 'assets/js/site-footer-editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ),
			is_readable( $file ) ? (string) filemtime( $file ) : DXAI_UI_VERSION,
			true
		);
		$css = DXAI_UI_DIR . 'assets/css/site-footer-front.css';
		wp_register_style(
			self::FRONT_STYLE,
			DXAI_UI_URL . 'assets/css/site-footer-front.css',
			array(),
			is_readable( $css ) ? (string) filemtime( $css ) : DXAI_UI_VERSION
		);
		register_block_type(
			self::NAME,
			array(
				'api_version'           => 3,
				'title'                 => __( 'Site Footer', 'dxai-ui' ),
				'description'           => __( 'The footer of the imported design. Its content is edited in Appearance > Widgets.', 'dxai-ui' ),
				'category'              => 'theme',
				'keywords'              => array( 'footer', 'widgets' ),
				'editor_script_handles' => array( self::SCRIPT ),
				'render_callback'       => array( self::class, 'render' ),
				'supports'              => array(
					// Nothing of its own to store or style: no wrapper element
					// at all, so the frame's <footer> is where the design had it.
					'html'            => false,
					'className'       => false,
					'customClassName' => false,
					'reusable'        => false,
					'multiple'        => false,
				),
				'attributes'            => array(),
			)
		);
	}

	/**
	 * What the editor script needs: where the footer's content is edited.
	 */
	public static function editor_data(): void {
		wp_localize_script(
			self::SCRIPT,
			'dxaiUISiteFooter',
			array(
				'widgetsUrl' => admin_url( 'widgets.php' ),
				'canEdit'    => current_user_can( 'edit_theme_options' ),
				'hasFooter'  => Footer_Template::get() !== array(),
			)
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public static function render( $attributes = array(), $content = '', $block = null ): string {
		if ( self::$depth > 0 ) {
			return '';
		}
		$footer = Footer_Template::get();
		if ( $footer === array() ) {
			return '';
		}
		++self::$depth;
		add_filter( 'pre_render_block', array( self::class, 'render_slot' ), 10, 2 );
		try {
			$html = do_blocks( (string) $footer['template'] );
		} finally {
			remove_filter( 'pre_render_block', array( self::class, 'render_slot' ), 10 );
			--self::$depth;
		}

		$scope  = self::scope( $footer );
		$host   = self::host_scope();
		$framed = $scope > 0 && $host !== $scope;
		if ( $framed ) {
			$html = self::frame( $html, $footer );
			// The preview's canvas gets the design from canvas_assets(), or
			// inside the preview (preview_rules()).
			if ( ! self::$preview ) {
				self::design_assets( $footer );
			}
			if ( $host > 0 && self::$contexts !== array() ) {
				$html = self::LIFT_OPEN . $html . self::LIFT_CLOSE;
			}
		} else {
			self::late_rules( $footer );
		}

		return self::$preview ? $html . self::preview_rules( $footer, $framed ) : $html;
	}

	/**
	 * The footer's design scope: its page's, as stored at install.
	 *
	 * @param array<string, mixed> $footer
	 */
	public static function scope( array $footer ): int {
		return (int) ( $footer['scope'] ?? 0 );
	}

	/**
	 * The design scope the block is rendering in: that of the post whose
	 * content is being rendered — Page_Scope puts that post's scope element
	 * around it — or, in the editor's preview, of the post being edited,
	 * whose canvas carries its scope classes. 0 anywhere else: a template, a
	 * widget, an ordinary post.
	 */
	private static function host_scope(): int {
		if ( self::$contexts !== array() ) {
			return (int) end( self::$contexts );
		}
		if ( self::$preview ) {
			$post_id = (int) get_the_ID();

			return $post_id > 0 ? (int) get_post_meta( $post_id, Page_Scope::META, true ) : 0;
		}

		return 0;
	}

	/**
	 * `the_content`, first: note the scope this content renders in.
	 *
	 * @param mixed $content
	 * @return mixed
	 */
	public static function enter_content( $content ) {
		$post             = get_post();
		self::$contexts[] = $post instanceof \WP_Post ? (int) get_post_meta( $post->ID, Page_Scope::META, true ) : 0;

		return $content;
	}

	/**
	 * `the_content`, after Page_Scope has wrapped it: a footer framed in its
	 * own design is moved out of the page's scope element (lift()).
	 *
	 * @param mixed $content
	 * @return mixed
	 */
	public static function leave_content( $content ) {
		$scope = (int) array_pop( self::$contexts );
		if ( ! is_string( $content ) || ! str_contains( $content, self::LIFT_OPEN ) ) {
			return $content;
		}

		return self::lift( $content, $scope );
	}

	/**
	 * A footer of another design out of the page's scope element.
	 *
	 * A design's stylesheet is scoped to its page's scope element, and every
	 * rule in it reaches every element inside — the footer's too, wherever
	 * its own scope element sits. Semper Dry's Lovable import has a Tailwind
	 * reset (`.dxai-ui.dxai-ui--93777 :where(*) { margin: 0; padding: 0 … }`)
	 * that would take every margin out of the other Semper Dry's footer; the
	 * page's `frontend-isolate.css` turns every box border-box. The footer is
	 * the page's last block (the page layout puts it there), so what follows
	 * it is Page_Scope's closing tag: the footer goes after that tag instead,
	 * as the next child of the same parent, and nothing of the page's design
	 * is around it. Anywhere else in the content — someone moved the block
	 * up — it stays where it is, still in its own scope element.
	 */
	private static function lift( string $content, int $scope ): string {
		$open  = strrpos( $content, self::LIFT_OPEN );
		$close = strrpos( $content, self::LIFT_CLOSE );
		if ( $open === false || $close === false || $close < $open ) {
			return self::unmark( $content );
		}
		$footer = substr( $content, $open + strlen( self::LIFT_OPEN ), $close - $open - strlen( self::LIFT_OPEN ) );
		$before = substr( $content, 0, $open );
		$after  = substr( $content, $close + strlen( self::LIFT_CLOSE ) );
		if ( $scope > 0
			&& preg_match( '/^\s*<\/div>\s*$/', $after ) === 1
			&& preg_match( '/^\s*<div\b[^>]*\bclass="[^"]*\bdxai-ui--' . $scope . '\b/', $before ) === 1 ) {
			return self::unmark( $before . $after . $footer );
		}

		return self::unmark( $before . $footer . $after );
	}

	private static function unmark( string $content ): string {
		return str_replace( array( self::LIFT_OPEN, self::LIFT_CLOSE ), '', $content );
	}

	/**
	 * The footer inside the element its own page puts it in.
	 *
	 * The inner element is Page_Scope's scope element for the footer's page —
	 * same tag, same classes — so every rule of the design reaches the footer
	 * as on that page, the rules the design wrote for its `body` (moved onto
	 * the scope element by Css_Scoper) included. The outer one says which
	 * kind of design it is (`--static`: a Claude Design export, which never
	 * ran under Tailwind's reset; `--dynamic`: one that did), for the rules
	 * of site-footer-front.css that keep another page's resets off it or give
	 * it the ones its own page had; a static footer's also carries
	 * `dxai-ui-static`, the class blank-canvas.css reads to leave a static
	 * design's margins alone. `alignfull` on both: in a theme's constrained
	 * content the footer spans the page, as on its own.
	 *
	 * @param array<string, mixed> $footer
	 */
	private static function frame( string $html, array $footer ): string {
		$page  = (int) ( $footer['page_id'] ?? 0 );
		$scope = self::scope( $footer );
		$outer = 'dxai-ui-site-footer alignfull ' . ( self::is_static( $footer ) ? 'dxai-ui-site-footer--static dxai-ui-static' : 'dxai-ui-site-footer--dynamic' );
		$inner = $page > 0 ? Page_Scope::classes( $page, $scope ) : 'wp-block-group alignfull dxai-ui dxai-ui--' . $scope . ' is-layout-flow wp-block-group-is-layout-flow';

		return '<div class="' . esc_attr( $outer ) . '"><div class="' . esc_attr( $inner ) . '">' . $html . '</div></div>';
	}

	/**
	 * Whether the footer's design is a Claude Design export (its page is
	 * rendered without Tailwind's reset, `body.dxai-ui-static`).
	 *
	 * @param array<string, mixed> $footer
	 */
	private static function is_static( array $footer ): bool {
		$page = (int) ( $footer['page_id'] ?? 0 );

		return $page > 0 && (bool) get_post_meta( $page, '_dxai_ui_static_html', true );
	}

	/**
	 * The footer's design for a page of another design, once per request
	 * (per stylesheet collection: the editor collects the canvas's apart from
	 * its own document): the fonts its page loads, site-footer-front.css, the
	 * design's stylesheet with its tokens (design_css()), the preset colour
	 * fallbacks a design that is not the brand needs, and the rules of the
	 * frame and the widgets, read against the footer's design.
	 *
	 * @param array<string, mixed> $footer
	 */
	private static function design_assets( array $footer ): void {
		if ( wp_style_is( self::DESIGN_STYLE, 'enqueued' ) || wp_style_is( self::DESIGN_STYLE, 'done' ) ) {
			return;
		}
		$page  = (int) ( $footer['page_id'] ?? 0 );
		$scope = self::scope( $footer );
		foreach ( self::font_urls( $footer ) as $i => $url ) {
			wp_enqueue_style( 'dxai-ui-font-' . $page . '-' . $i, $url, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- a font CSS URL is versioned by its query.
		}
		if ( ! wp_style_is( self::DESIGN_STYLE, 'registered' ) ) {
			// dynamic.css: what every converted page loads under its sheet (Assets).
			wp_register_style( self::DESIGN_STYLE, false, array( self::FRONT_STYLE, 'dxai-ui-dynamic' ), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- inline only.
		}
		wp_enqueue_style( self::DESIGN_STYLE );
		$markup = self::markup();
		wp_add_inline_style( self::DESIGN_STYLE, self::design_css( $footer ) . self::preset_fallbacks( $markup, $page, $scope ) );
		Style_Rules::attach_markup( $markup, $scope );
		self::$rules_out = true;
	}

	/**
	 * The footer's page's font stylesheets.
	 *
	 * @param array<string, mixed> $footer
	 * @return array<int, string>
	 */
	private static function font_urls( array $footer ): array {
		$page  = (int) ( $footer['page_id'] ?? 0 );
		$fonts = $page > 0 ? get_post_meta( $page, '_dxai_ui_font_urls', true ) : array();
		$out   = array();
		foreach ( is_array( $fonts ) ? array_values( $fonts ) : array() as $i => $url ) {
			$url = esc_url_raw( (string) $url );
			if ( $url !== '' && preg_match( '#^https?://#i', $url ) === 1 ) {
				$out[ $i ] = $url;
			}
		}

		return $out;
	}

	/**
	 * The footer design's stylesheet, as its own page loads it, with the
	 * tokens kept to the footer.
	 *
	 * Every rule of a design's sheet is scoped to its page's scope element,
	 * save one: the token block Token_Styles appends, `:root,.dxai-ui.dxai-ui
	 * { --dxai-ink: … }`, and the brand's override of it, which are written
	 * for every converted page at once. Loaded on a page of another design as
	 * it is, that block would redefine `--dxai-ink` for the page too — and
	 * designs name their tokens alike (both Semper Dry imports have an `ink`).
	 * So the sheet is printed inline with those selectors narrowed to the
	 * footer's scope element: `.dxai-ui.dxai-ui--{scope}.dxai-ui--{scope}`,
	 * (0,3,0), which also outranks the other page's own token block where
	 * both reach the footer's scope element (`.dxai-ui.dxai-ui` matches it
	 * too), in whichever order the two are printed. Any relative url() is
	 * pointed back at the sheet's folder.
	 *
	 * @param array<string, mixed> $footer
	 */
	private static function design_css( array $footer ): string {
		static $memo = array();
		$page  = (int) ( $footer['page_id'] ?? 0 );
		$scope = self::scope( $footer );
		if ( $page < 1 || $scope < 1 ) {
			return '';
		}
		$sheet = Upload_Paths::for_meta( $page, '_dxai_ui_css_url' );
		$key   = $page . '|' . $scope . '|' . $sheet['path'] . '|' . ( $sheet['path'] !== '' ? (string) @filemtime( $sheet['path'] ) : '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing sheet reads as none.
		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}
		$css = '';
		if ( $sheet['path'] !== '' && is_readable( $sheet['path'] ) ) {
			$css = self::rebase( (string) file_get_contents( $sheet['path'] ), $sheet['url'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- this plugin's own generated sheet, on disk.
		}
		// The brand's override: its tokens and the design's own custom
		// properties pointed at the Styles presets (Assets adds it after the
		// sheet on the design's own page).
		$css .= Token_Styles::brand_css( $page );
		$own  = '.dxai-ui.dxai-ui--' . $scope . '.dxai-ui--' . $scope . '{';
		$css  = str_replace( self::GENERIC_TOKENS, $own, $css );
		// Printed in a <style> element: nothing in it may close that element.
		$memo[ $key ] = str_ireplace( '</style', '<\/style', $css );

		return $memo[ $key ];
	}

	/**
	 * Relative url()s of a stylesheet read from $sheet_url, made absolute.
	 */
	private static function rebase( string $css, string $sheet_url ): string {
		if ( $sheet_url === '' || ! str_contains( $css, 'url(' ) ) {
			return $css;
		}
		$base = trailingslashit( dirname( $sheet_url ) );

		return (string) preg_replace_callback(
			'/url\(\s*([\'"]?)(?![\'"]?(?:data:|[a-z][a-z0-9+.-]*:|\/|#|var\())([^\'")]+)\1\s*\)/i',
			static function ( array $m ) use ( $base ): string {
				return 'url(' . $m[1] . $base . $m[2] . $m[1] . ')';
			},
			$css
		);
	}

	/**
	 * `wp_enqueue_scripts`: the footer's design in <head> for a page of
	 * another design that holds the block, before anything is painted —
	 * rather than with the late styles once the block has rendered.
	 */
	public static function front_assets(): void {
		$footer = Footer_Template::get();
		if ( $footer === array() ) {
			return;
		}
		// A post whose content rendered the bare footer before this point (a
		// block theme renders its template before <head>) and whose rules no
		// Style_Rules pass collected.
		foreach ( array_keys( self::$bare ) as $post_id ) {
			if ( ! isset( self::$covered[ $post_id ] ) && ! self::$rules_out ) {
				Style_Rules::attach_markup( self::markup(), self::scope( $footer ) );
				self::$rules_out = true;
			}
		}
		if ( ! is_singular() ) {
			return;
		}
		$post_id = (int) get_queried_object_id();
		if ( $post_id < 1 || self::scope( $footer ) < 1 || ! has_block( self::NAME, $post_id ) || (int) get_post_meta( $post_id, Page_Scope::META, true ) === self::scope( $footer ) ) {
			return;
		}
		self::design_assets( $footer );
	}

	/**
	 * `enqueue_block_assets`, in the editor: the same for the canvas of a
	 * post of another design (or none) that holds the block. The canvas is
	 * collected for its iframe apart from the editor's own document, and
	 * design_assets() runs once for each collection.
	 */
	public static function canvas_assets(): void {
		if ( ! is_admin() ) {
			return;
		}
		$post_id = self::editing_post_id();
		if ( $post_id < 1 || ! has_block( self::NAME, $post_id ) ) {
			return;
		}
		$footer = Footer_Template::get();
		if ( $footer === array() || self::scope( $footer ) < 1 || (int) get_post_meta( $post_id, Page_Scope::META, true ) === self::scope( $footer ) ) {
			return;
		}
		self::design_assets( $footer );
	}

	/**
	 * The post the block editor is open on (as Design_Blocks reads it).
	 */
	private static function editing_post_id(): int {
		$post_id = 0;
		if ( isset( $_GET['post'] ) && is_scalar( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which post the editor shows; nothing is changed.
			$post_id = absint( wp_unslash( (string) $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( $post_id <= 0 && isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof \WP_Post ) {
			$post_id = (int) $GLOBALS['post']->ID;
		}

		return $post_id;
	}

	/**
	 * `rest_request_before_callbacks`: note the block-renderer request for
	 * this block, the one the editor's preview makes.
	 *
	 * @param mixed $response
	 * @param mixed $handler
	 * @param mixed $request
	 * @return mixed
	 */
	public static function note_preview( $response, $handler, $request ) {
		if ( $request instanceof \WP_REST_Request && str_starts_with( $request->get_route(), '/wp/v2/block-renderer/' . self::NAME ) ) {
			self::$preview = true;
		}

		return $response;
	}

	/**
	 * What the editor preview needs inside it, for a post that does not hold
	 * the block yet — it was just inserted, or put back. The canvas gets a
	 * post's rules from what is stored (Style_Rules::css_for_post(), through
	 * `dxai_ui_style_rules_markup`; canvas_assets()), so until such a post is
	 * saved its preview would show the frame and the widgets without their
	 * own CSS. The dxs- rules: each matches its own hashed class alone, so a
	 * copy of one later in the canvas changes nothing else, where a second
	 * copy of a theme utility rule would reorder it against the page's
	 * (Style_Rules::attach_post()). A framed footer (a post of another
	 * design) also brings its design: fonts, site-footer-front.css and the
	 * stylesheet with its tokens. A post that holds the block already has all
	 * of it, and gets nothing here.
	 *
	 * With them, the preset text colours pointed back at the design's tokens
	 * when the design is not the brand: `has-sky-74-color` names a brand
	 * preset the site may not define (measured: `--wp--preset--color--sky-74`
	 * empty in the canvas, and the footer's grey text came out in the
	 * inherited colour).
	 *
	 * @param array<string, mixed> $footer
	 */
	private static function preview_rules( array $footer, bool $framed ): string {
		$post_id = (int) get_the_ID();
		if ( $post_id > 0 && has_block( self::NAME, $post_id ) ) {
			return '';
		}
		$rules   = array();
		$presets = array();
		self::collect_rules( parse_blocks( self::markup() ), $rules, $presets );
		$links = '';
		$css   = '';
		if ( $framed ) {
			$front = wp_styles()->query( self::FRONT_STYLE );
			$urls  = self::font_urls( $footer );
			if ( $front instanceof \_WP_Dependency && is_string( $front->src ) ) {
				$urls[] = add_query_arg( 'ver', (string) $front->ver, $front->src );
			}
			foreach ( $urls as $url ) {
				$links .= '<link rel="stylesheet" href="' . esc_url( $url ) . '" />';
			}
			$css .= self::design_css( $footer );
		}
		foreach ( $rules as $class => $declarations ) {
			$css .= Style_Rules::rule( $class, $declarations );
		}
		$css .= self::fallback_css( $presets, (int) ( $footer['page_id'] ?? 0 ), self::scope( $footer ) );

		// rule() escapes every `<`, and design_css() every `</style`.
		return $links . ( $css === '' ? '' : '<style id="dxai-ui-site-footer-preview-rules">' . $css . '</style>' );
	}

	/**
	 * The preset text colours of some footer markup pointed back at the
	 * design's tokens, for a page whose design is no longer the brand — the
	 * rules Style_Rules::rules_css() writes for a page's own content. The
	 * brand is the last design imported (Design_Theme_Json); a footer stays
	 * until a design with a footer replaces it, so its design and the brand
	 * can differ, and then `has-sky-74-color` names a preset the site no
	 * longer defines (measured on the Widgets screen after another import:
	 * `--wp--preset--color--sky-74` empty, 10 of 37 footer texts in the
	 * admin's grey). Empty for the brand's own page.
	 *
	 * @param string $prefix Put before each selector (for more specificity).
	 */
	public static function preset_fallbacks( string $markup, int $page_id, int $scope, string $prefix = '' ): string {
		$rules   = array();
		$presets = array();
		self::collect_rules( parse_blocks( $markup ), $rules, $presets );

		return self::fallback_css( $presets, $page_id, $scope, $prefix );
	}

	/**
	 * @param array<string, bool> $presets Preset slugs.
	 */
	private static function fallback_css( array $presets, int $page_id, int $scope, string $prefix = '' ): string {
		if ( $presets === array() || $page_id < 1 || Token_Styles::is_brand( $page_id ) ) {
			return '';
		}
		$scope = $scope > 0 ? $scope : $page_id;
		$css   = '';
		foreach ( array_keys( $presets ) as $slug ) {
			$css .= $prefix . '.dxai-ui.dxai-ui--' . $scope . ' .has-' . $slug . '-color{color:var(--dxai-' . $slug . ') !important}';
		}

		return $css;
	}

	/**
	 * The dxaiCss and dxaiInner rules of some blocks, by class, and the
	 * preset text colours they use.
	 *
	 * @param array<int, mixed>     $blocks
	 * @param array<string, string> $rules   Filled in place.
	 * @param array<string, bool>   $presets Filled in place.
	 */
	private static function collect_rules( array $blocks, array &$rules, array &$presets ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$css   = $attrs[ Style_Hoister::ATTR ] ?? '';
			if ( is_string( $css ) && trim( $css ) !== '' ) {
				$rules[ Style_Hoister::css_class( $css ) ] = $css;
			}
			foreach ( is_array( $attrs[ Style_Hoister::INNER_ATTR ] ?? null ) ? $attrs[ Style_Hoister::INNER_ATTR ] : array() as $class => $declarations ) {
				if ( is_string( $class ) && is_string( $declarations ) ) {
					$rules[ $class ] = $declarations;
				}
			}
			if ( is_string( $attrs['textColor'] ?? null ) && $attrs['textColor'] !== '' ) {
				$presets[ sanitize_key( $attrs['textColor'] ) ] = true;
			}
			self::collect_rules( (array) ( $block['innerBlocks'] ?? array() ), $rules, $presets );
		}
	}

	/**
	 * Whether the footer block is rendering right now.
	 */
	public static function is_rendering(): bool {
		return self::$depth > 0;
	}

	/**
	 * A slot of the frame, rendered as its widget area.
	 *
	 * @param string|null          $pre
	 * @param array<string, mixed> $parsed
	 * @return string|null
	 */
	public static function render_slot( $pre, $parsed ) {
		if ( $pre !== null || ! is_array( $parsed ) || ( $parsed['blockName'] ?? '' ) !== Footer_Template::SLOT ) {
			return $pre;
		}
		$area = (string) ( $parsed['attrs']['area'] ?? '' );

		return $area === '' ? '' : Footer_Widgets::render_area( $area );
	}

	/**
	 * The footer's markup, frame and current widgets, for rule collection
	 * and asset scans. Empty when no footer was imported.
	 */
	public static function markup(): string {
		$footer = Footer_Template::get();
		if ( $footer === array() ) {
			return '';
		}
		$parts = array( (string) $footer['template'] );
		foreach ( Footer_Template::areas() as $area ) {
			foreach ( Footer_Widgets::block_contents( $area ) as $widget ) {
				$parts[] = $widget;
			}
		}

		return implode( "\n", $parts );
	}

	/**
	 * `dxai_ui_style_rules_markup`: the footer's markup for a post of the
	 * footer's design that holds the block, so Style_Rules writes its rules
	 * with the page's own. A post of another design frames the footer in its
	 * own design, and its rules are read against that design instead
	 * (design_assets()): a class name the footer's design defines must not be
	 * taken for a theme utility because the page's design does not.
	 *
	 * @param array<int, string>|mixed $markups
	 * @param int|mixed                $post_id
	 * @return array<int, string>
	 */
	public static function style_rules_markup( $markups, $post_id = 0 ): array {
		$markups = is_array( $markups ) ? $markups : array();
		$post_id = (int) $post_id;
		if ( $post_id < 1 || ! has_block( self::NAME, $post_id ) ) {
			return $markups;
		}
		$footer = Footer_Template::get();
		if ( $footer === array() || (int) get_post_meta( $post_id, Page_Scope::META, true ) !== self::scope( $footer ) ) {
			return $markups;
		}
		$markup = self::markup();
		if ( $markup !== '' ) {
			$markups[]                 = $markup;
			self::$covered[ $post_id ] = true;
		}

		return $markups;
	}

	/**
	 * The bare footer rendered where Style_Rules collected no rules with the
	 * footer's — another post's content of the same design printed into the
	 * page — the rules go out with the late styles, as core prints a style
	 * enqueued after <head>. Before `wp_enqueue_scripts` (a block theme
	 * renders its template first), front_assets() decides.
	 *
	 * @param array<string, mixed> $footer
	 */
	private static function late_rules( array $footer ): void {
		// The REST_REQUEST constant, not wp_is_serving_rest_request(): that
		// arrived in WordPress 6.5, and the plugin supports 6.4.
		if ( self::$rules_out || is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$post_id = (int) get_the_ID();
		if ( ! did_action( 'wp_enqueue_scripts' ) ) {
			self::$bare[ $post_id ] = true;

			return;
		}
		if ( $post_id > 0 && isset( self::$covered[ $post_id ] ) ) {
			return;
		}
		self::$rules_out = true;
		Style_Rules::attach_markup( self::markup(), self::scope( $footer ) );
	}

	/**
	 * The rules of some of the footer's markup — dxs-, dxaiInner and theme
	 * utility classes — read against the footer's design (its scope decides
	 * which class names are the design's own rather than utilities).
	 */
	public static function rules_css( string $markup, ?int $scope_id = null ): string {
		if ( $markup === '' ) {
			return '';
		}

		return Style_Rules::css_for_markup( $markup, $scope_id ?? (int) ( Footer_Template::get()['scope'] ?? 0 ) );
	}
}

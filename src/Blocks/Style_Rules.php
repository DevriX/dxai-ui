<?php
/**
 * The stylesheet a converted page's `dxs-` and utility classes point at.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks;

use DXAI_UI\Compiler\Style_Hoister;
use DXAI_UI\Compiler\Token_Styles;
use DXAI_UI\Compiler\Utility_Classes;
use DXAI_UI\Structures\Page_Scope;

/**
 * A converted page stores no `style=""` (Style_Hoister): each block keeps its
 * own CSS in its `dxaiCss` attribute and carries the class `dxs-{hash}`,
 * and the CSS of inline runs is kept on the block too, in `dxaiInner`
 * (older imports: the post's meta). This builds the
 * rules for those classes when the page is rendered — from the page, the
 * header and footer template parts it references and the synced patterns in
 * it — and adds them to the page as a stylesheet in <head>, the way core
 * prints its own block-support styles. Built from the stored blocks rather
 * than while they render, so the rules exist before the first element is
 * painted, whichever template renders the page. A block someone restyles in
 * the editor gets a new hash, and its rule comes with it on the next view.
 *
 * Every rule has the precedence an inline style had: the selector is
 * `:is(.dxs-…,#dxai-h)` three times over. `:is()` takes the specificity of
 * its most specific argument, so each counts as an id — (3,0,0) in all —
 * while matching by the class alone (no element has that id). That beats any
 * rule of the design's own stylesheet, as the inline style did, and still
 * loses to the design's `!important` rules (Semper Dry's media queries, for
 * one), as the inline style also did. A rule written with plain class
 * specificity would have lost to the scoped design rules and moved boxes.
 *
 * The same stylesheet carries the utility classes the page uses
 * (Utility_Classes — the DevriX theme's and the plugin's own): Style_Hoister
 * writes the part of a block's CSS a utility declares exactly as that class,
 * and a person may type one into "Additional CSS class(es)". The theme is not
 * installed, so the rules come from the plugin's copy — only those of the
 * classes found in the page and what it pulls in, in the theme's cascade
 * order. A class name that is the design's own — its stylesheet defines it
 * (a Tailwind `p-4`), its runtime toggles it (the `hidden` Motion_Runtime
 * switches), or it was recorded as the design's at import
 * (Style_Hoister::DESIGN_NAMES_META) — gets no utility rule. The rules are
 * scoped to design scope elements (Utility_Classes::SCOPE); a block with
 * utility classes that renders outside one — pasted into an ordinary page, a
 * footer widget in a classic theme's sidebar — is marked with
 * Utility_Classes::MARK as it renders (late()), so they reach it and never
 * the theme's own markup.
 *
 * When a block's own CSS and one of its utility classes set the same
 * property — a person added the class, or edited Design CSS after the import
 * — the block's CSS keeps the last word, as the inline style did over any
 * class: that declaration is restated as important for elements carrying
 * both (overrides()). The editor canvas gets the utility rules in their
 * editor form (Utility_Classes::css_for_classes( …, true )), which the
 * editor's live dxs- rules out-rank, plus the rules of every utility class
 * used anywhere on the site (site_utilities()), so a section copied from
 * another page or a pattern from the library looks right the moment it is
 * inserted.
 *
 * Markup that renders on the page without being stored in it — the site
 * header built from a menu, the footer's widgets — is added through the
 * `dxai_ui_style_rules_markup` filter, and its dxs- rules, dxaiInner rules
 * and utility classes are written with the page's. Screens that edit such
 * markup outside any post (the Widgets screen) use css_for_markup().
 *
 * The result is cached per post (a transient), keyed on everything it is
 * built from: the catalogue version, the content and modification time of
 * every post it read and the design names recorded on them, the design
 * sheets' modification times, the extra markup, the active theme and whether
 * the page's design is the brand. So a page view does not parse blocks or
 * load the utility catalogue unless something it depends on changed.
 * css_for_markup() is cached the same way, keyed on the markup itself.
 */
final class Style_Rules {

	private const HANDLE = 'dxai-ui-rules';

	/** Rules for posts rendered after <head> was printed (see late()). */
	private const LATE_HANDLE = 'dxai-ui-rules-late';

	/** Transient name prefix for a post's computed rules (plus the post id). */
	private const CACHE = 'dxai_ui_rules_';

	/**
	 * Option bumped whenever a template part or synced pattern is saved or
	 * removed: they are pulled into other posts' rules, and a new part can
	 * take over a slug another post references.
	 */
	private const GENERATION = 'dxai_ui_rules_generation';

	/** Option bumped whenever content holding this plugin's data changes (site_utilities()). */
	private const SITE_GENERATION = 'dxai_ui_rules_site_generation';

	/** Bumped when the shape of the cached data changes. */
	private const CACHE_SCHEMA = 11;

	/**
	 * Native blocks whose wrapper is not the element the design styled (Native_Blocks): the marker class the wrapper
	 * carries => the element inside it that holds the design's classes and CSS. A core/image converted from the
	 * plugin's own image is a `<figure>` around the `<img>` the design's classes were written for, so its rules are
	 * written for the image, and the figure takes no part in the layout (assets/css/dynamic.css).
	 */
	public const PARTS = array( 'dxai-part-img' => ' img' );

	/** How long a computed stylesheet may sit unread. Validated on every read. */
	private const CACHE_TTL = WEEK_IN_SECONDS;

	/** At most this many posts are read for site_utilities(). */
	private const SITE_SCAN = 1000;

	/**
	 * Theme utility names never written, even when a page carries them and
	 * its design sheet does not define them: Motion_Runtime toggles `hidden`
	 * as its panel state, and Tailwind pairs it with responsive classes
	 * (`hidden md:block`); the theme's `.hidden{display:none !important}`
	 * would pin those shut.
	 */
	private const NEVER_EMIT = array( 'hidden' );

	/** @var array<int, bool> Posts whose rules this request has already written. */
	private static array $written = array();

	/** @var array<string, bool> Utility classes whose rules this request has already written. */
	private static array $emitted = array();

	/** @var array<string, true> marker|class => the rules of that utility class written for the element inside a part (PARTS) have been printed. */
	private static array $emitted_part = array();

	/** Whether this request printed utility rules: blocks outside a scope get MARK (late()). */
	private static bool $marking = false;

	/** Whether the page being viewed prints its rules (front()), once the query is known. */
	private static ?bool $page_marks = null;

	/** @var array<int, bool> the_content renders in progress: whether each is a scoped post's. */
	private static array $contents = array();

	/** Widget areas (dynamic_sidebar()) rendering. */
	private static int $sidebars = 0;

	public function register(): void {
		// After Assets has enqueued the page's own stylesheet.
		add_action( 'wp_enqueue_scripts', array( $this, 'front' ), 30 );
		add_filter( 'render_block', array( $this, 'late' ), 10, 2 );
		// Around do_blocks (9): whether the blocks rendering are inside Page_Scope's element.
		add_filter( 'the_content', array( self::class, 'enter_content' ), 8 );
		add_filter( 'the_content', array( self::class, 'leave_content' ), 10 );
		add_action( 'dynamic_sidebar_before', array( self::class, 'enter_sidebar' ), 0 );
		add_action( 'dynamic_sidebar_after', array( self::class, 'leave_sidebar' ), PHP_INT_MAX );
		add_action( 'save_post', array( self::class, 'forget' ), 10, 2 );
		add_action( 'deleted_post', array( self::class, 'forget' ), 10, 2 );
		add_action( 'trashed_post', array( self::class, 'forget' ), 10, 1 );
		add_action( 'untrashed_post', array( self::class, 'forget' ), 10, 1 );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'forget_meta' ), 10, 3 );
		}
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/**
	 * The rules for the page being viewed: any page holding this plugin's
	 * blocks, not only a converted one — a generated pattern inserted into an
	 * ordinary page (synced, or copied in with its dxaiCss/dxaiInner), or a
	 * block whose CSS became utility classes entirely and carries nothing
	 * else of this plugin's, needs its rules there just the same.
	 */
	public function front(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post_id = (int) get_queried_object_id();
		if ( $post_id < 1 || ! self::holds_rules( $post_id ) ) {
			return;
		}
		self::attach_post( self::HANDLE, $post_id );
	}

	/** Whether a post's content (or what it pulls in) can need rules — what front() prints for. */
	private static function holds_rules( int $post_id ): bool {
		$content = (string) get_post_field( 'post_content', $post_id );

		return (bool) get_post_meta( $post_id, '_dxai_ui_generated_page', true ) || self::may_hold_rules( $content ) || Utility_Classes::in_class_names( $content );
	}

	/**
	 * Whether rendered blocks get MARK: once this request has printed
	 * utility rules, or when the page being viewed will (front()). A block
	 * theme renders its template before <head> is printed — before front()
	 * has run — so the page's answer is worked out when its first block
	 * renders, as soon as the main query is known.
	 */
	private static function marking(): bool {
		if ( self::$marking ) {
			return true;
		}
		if ( self::$page_marks === null && did_action( 'wp' ) ) {
			$post_id          = is_singular() ? (int) get_queried_object_id() : 0;
			self::$page_marks = $post_id > 0 && self::holds_rules( $post_id );
		}

		return (bool) self::$page_marks;
	}

	/**
	 * Every rendered block: marked when it carries utility classes and
	 * renders outside a design scope, in a post's content or a widget area
	 * (mark() — a theme's own template blocks are the theme's, never
	 * marked); and a synced pattern or template part rendered where front()
	 * did not look — a widget area, an archive, a template the theme renders
	 * around the content — gets its rules then; WordPress prints a style
	 * enqueued after <head> in the footer.
	 *
	 * @param string               $content
	 * @param array<string, mixed> $block
	 */
	public function late( $content, $block ) {
		if ( is_admin() || ! is_array( $block ) ) {
			return $content;
		}
		if ( is_string( $content ) && $content !== '' && ( self::$contents !== array() || self::$sidebars > 0 ) && ! in_array( true, self::$contents, true ) && self::marking() ) {
			$content = self::mark( $content, $block );
		}
		$name = (string) ( $block['blockName'] ?? '' );
		if ( $name !== 'core/block' && $name !== 'core/template-part' ) {
			return $content;
		}
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$ref   = $name === 'core/block' ? (int) ( $attrs['ref'] ?? 0 ) : self::part_id( (string) ( $attrs['slug'] ?? '' ) );
		if ( $ref < 1 || isset( self::$written[ $ref ] ) ) {
			return $content;
		}
		self::attach_post( did_action( 'wp_head' ) ? self::LATE_HANDLE : self::HANDLE, $ref );

		return $content;
	}

	/**
	 * A the_content render begins: of a post whose content Page_Scope wraps
	 * in its design scope, or not.
	 *
	 * @param mixed $content
	 * @return mixed
	 */
	public static function enter_content( $content ) {
		$post             = get_post();
		self::$contents[] = $post instanceof \WP_Post && (int) get_post_meta( $post->ID, Page_Scope::META, true ) > 0;

		return $content;
	}

	/**
	 * @param mixed $content
	 * @return mixed
	 */
	public static function leave_content( $content ) {
		array_pop( self::$contents );

		return $content;
	}

	/** A widget area begins rendering (its blocks may be ours, outside any scope). */
	public static function enter_sidebar(): void {
		++self::$sidebars;
	}

	public static function leave_sidebar(): void {
		self::$sidebars = max( 0, self::$sidebars - 1 );
	}

	/**
	 * A rendered block's HTML with MARK on its root element, when its
	 * className holds a utility class: outside a design scope the scoped
	 * utility rules reach only marked elements. Front end only, never
	 * stored; the rest is returned as it came.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function mark( string $html, array $block ): string {
		$class = $block['attrs']['className'] ?? '';
		if ( ! is_string( $class ) || trim( $class ) === '' ) {
			return $html;
		}
		$hit = false;
		foreach ( preg_split( '/\s+/', $class, -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $token ) {
			if ( Utility_Classes::is_utility( $token ) ) {
				$hit = true;
				break;
			}
		}
		if ( ! $hit || ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}
		$tags = new \WP_HTML_Tag_Processor( $html );
		if ( ! $tags->next_tag() ) {
			return $html;
		}
		$tags->add_class( Utility_Classes::MARK );

		return $tags->get_updated_html();
	}

	/**
	 * Drop a post's cached rules when it changes; when it is a template part
	 * or a synced pattern, every post's (they are pulled into other posts).
	 * Content that holds this plugin's data also moves the site's utility
	 * names (site_utilities()).
	 *
	 * @param int|mixed $post_id
	 * @param mixed     $post
	 */
	public static function forget( $post_id, $post = null ): void {
		$post_id = (int) $post_id;
		if ( $post_id < 1 || wp_is_post_revision( $post_id ) ) {
			return;
		}
		delete_transient( self::CACHE . $post_id );
		$type = $post instanceof \WP_Post ? $post->post_type : (string) get_post_type( $post_id );
		if ( $type === 'wp_template_part' || $type === 'wp_block' ) {
			update_option( self::GENERATION, (int) get_option( self::GENERATION, 0 ) + 1, false );
		}
		$content = $post instanceof \WP_Post ? $post->post_content : (string) get_post_field( 'post_content', $post_id );
		if ( $type !== 'nav_menu_item' && $type !== 'revision' && ( $content === '' || str_contains( $content, 'dxai' ) ) ) {
			update_option( self::SITE_GENERATION, (int) get_option( self::SITE_GENERATION, 0 ) + 1, false );
		}
	}

	/**
	 * Older imports keep inline-run CSS in post meta rather than on blocks;
	 * an import records the design's names in meta.
	 *
	 * @param mixed $meta_ids
	 * @param int   $post_id
	 * @param mixed $meta_key
	 */
	public static function forget_meta( $meta_ids, $post_id, $meta_key ): void {
		if ( $meta_key === Style_Hoister::INNER_META || $meta_key === Style_Hoister::DESIGN_NAMES_META ) {
			delete_transient( self::CACHE . (int) $post_id );
		}
	}

	/**
	 * The stored template part a `core/template-part` slug renders: the
	 * active theme's first (two themes can each keep a part with one slug),
	 * else any published one. 0 when there is none — a part the theme ships
	 * as a file holds none of this plugin's CSS.
	 */
	private static function part_id( string $slug ): int {
		static $cache = array();
		if ( $slug === '' ) {
			return 0;
		}
		if ( ! isset( $cache[ $slug ] ) ) {
			$query = array(
				'post_type'      => 'wp_template_part',
				'name'           => $slug,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			);
			$ids   = get_posts(
				$query + array(
					'tax_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one indexed term, once per slug per request.
						array(
							'taxonomy' => 'wp_theme',
							'field'    => 'name',
							'terms'    => get_stylesheet(),
						),
					),
				)
			);
			if ( ! isset( $ids[0] ) ) {
				$ids = get_posts( $query );
			}
			$cache[ $slug ] = isset( $ids[0] ) ? (int) $ids[0] : 0;
		}

		return $cache[ $slug ];
	}

	/**
	 * Whether stored content can carry dxs- rules: this plugin's CSS
	 * attributes, a synced pattern / template part that may, or the site
	 * header / footer blocks whose markup comes through the
	 * `dxai_ui_style_rules_markup` filter. (A block whose CSS is all utility
	 * classes has none of these: front() asks Utility_Classes::in_class_names().)
	 */
	private static function may_hold_rules( string $content ): bool {
		foreach ( array( '"' . Style_Hoister::ATTR . '"', '"' . Style_Hoister::INNER_ATTR . '"', '<!-- wp:block ', '<!-- wp:template-part ', '<!-- wp:dxai-ui/site-header', '<!-- wp:dxai-ui/site-footer' ) as $needle ) {
			if ( str_contains( $content, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Print $css in a stylesheet handle of its own (no file), in <head>.
	 */
	public static function attach( string $handle, string $css ): void {
		if ( $css === '' ) {
			return;
		}
		if ( ! wp_style_is( $handle, 'registered' ) ) {
			wp_register_style( $handle, false, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}
		wp_enqueue_style( $handle );
		wp_add_inline_style( $handle, $css );
	}

	/**
	 * A post's rules into $handle, each utility class's rules at most once
	 * per request — a second copy of a utility rule later in the document
	 * would reorder it against the others, and the order decides which
	 * utility wins.
	 */
	private static function attach_post( string $handle, int $post_id ): void {
		self::attach_parts( $handle, self::parts_for_post( $post_id ) );
	}

	/**
	 * The rules of markup that renders outside any post Style_Rules read — a
	 * footer's widget area a theme prints, the site header or footer block
	 * rendered by a template on an archive — printed into the page: in <head>
	 * while it is still open, else with the late styles in the footer. As
	 * css_for_markup(), but a utility class this request has already written
	 * is not written a second time (see attach_parts()).
	 *
	 * @param int $scope_id The converted page whose design the markup belongs to.
	 */
	public static function attach_markup( string $markup, int $scope_id = 0 ): void {
		if ( trim( $markup ) === '' ) {
			return;
		}
		self::attach_parts( did_action( 'wp_head' ) ? self::LATE_HANDLE : self::HANDLE, self::parts_for_markup( $markup, $scope_id ) );
	}

	/**
	 * Rules into $handle, each utility class's rules at most once per request.
	 *
	 * @param array{rules: string, utilities: array<int, string>, utility_css: string} $parts
	 */
	private static function attach_parts( string $handle, array $parts ): void {
		$new = array_values( array_filter( $parts['utilities'], static fn( $c ) => ! isset( self::$emitted[ $c ] ) ) );
		$css = count( $new ) === count( $parts['utilities'] ) ? $parts['utility_css'] : Utility_Classes::css_for_classes( $new );
		foreach ( $new as $class ) {
			self::$emitted[ $class ] = true;
		}
		// The rules of a utility class written for the element inside a part (an image the plugin turned into core/image) are
		// a different rule from the class's own: the class being printed already for another block does not print them.
		if ( count( $new ) !== count( $parts['utilities'] ) ) {
			foreach ( (array) ( $parts['part_utilities'] ?? array() ) as $marker => $classes ) {
				$fresh = array_values( array_filter( (array) $classes, static fn( $c ) => ! isset( self::$emitted_part[ $marker . '|' . $c ] ) ) );
				if ( $fresh === array() || ! isset( self::PARTS[ $marker ] ) ) {
					continue;
				}
				$css .= Utility_Classes::css_for_classes( $fresh, false, array( 'wrap' => (string) $marker, 'inner' => self::PARTS[ $marker ] ) );
				foreach ( $fresh as $class ) {
					self::$emitted_part[ $marker . '|' . $class ] = true;
				}
			}
		} else {
			foreach ( (array) ( $parts['part_utilities'] ?? array() ) as $marker => $classes ) {
				foreach ( (array) $classes as $class ) {
					self::$emitted_part[ $marker . '|' . $class ] = true;
				}
			}
		}
		if ( $parts['utilities'] !== array() ) {
			self::$marking = true;
		}
		self::attach( $handle, $css . $parts['rules'] );
	}

	/**
	 * The rules for a page and everything it pulls in: the utility classes it
	 * uses, then its dxs- rules. $editor (default: in wp-admin, where the
	 * only reader is the editor canvas, Design_Blocks::enqueue_editor_canvas())
	 * writes the utilities in their editor form and adds those of every
	 * utility class used on the site (see the class comment).
	 */
	public static function css_for_post( int $post_id, ?bool $editor = null ): string {
		$parts = self::parts_for_post( $post_id );
		if ( ! ( $editor ?? is_admin() ) ) {
			return $parts['utility_css'] . $parts['rules'];
		}

		return self::editor_utilities( $parts['utilities'], array( $post_id ), $parts['part_utilities'] ) . $parts['rules'];
	}

	/**
	 * The rules for markup that is not a post — a footer's widgets on the
	 * Widgets screen, a header built from a menu: its dxs- and dxaiInner
	 * rules, those of the synced patterns and template parts it references,
	 * and the utility classes it uses. $scope_id names the converted page
	 * whose design the markup belongs to, so the class names that design
	 * defines are left to it (as for a page). $editor as for css_for_post().
	 */
	public static function css_for_markup( string $markup, int $scope_id = 0, ?bool $editor = null ): string {
		$parts = self::parts_for_markup( $markup, $scope_id );
		if ( ! ( $editor ?? is_admin() ) ) {
			return $parts['utility_css'] . $parts['rules'];
		}

		return self::editor_utilities( $parts['utilities'], $scope_id > 0 ? array( $scope_id ) : array(), $parts['part_utilities'] ) . $parts['rules'];
	}

	/**
	 * The editor form of $utilities plus every utility class used on the
	 * site that the design of $posts does not claim.
	 *
	 * @param array<int, string> $utilities
	 * @param array<int, int>    $posts
	 */
	private static function editor_utilities( array $utilities, array $posts, array $part_utilities = array() ): string {
		$site = self::live_utilities( array_fill_keys( self::site_utilities(), true ), $posts, array() );

		return Utility_Classes::css_for_classes( array_values( array_unique( array_merge( $utilities, $site ) ) ), true ) . self::part_utility_css( $part_utilities, true );
	}

	/**
	 * Every utility class name used in content that holds this plugin's data
	 * — converted pages, their parts, the pattern library, a block copied
	 * from them into any post — less each post's recorded design names.
	 * Cached until such content changes (SITE_GENERATION). Read in wp-admin
	 * only; at most SITE_SCAN posts, the latest first.
	 *
	 * @return array<int, string>
	 */
	private static function site_utilities(): array {
		static $memo = null;
		if ( $memo !== null ) {
			return $memo;
		}
		$gen    = (int) get_option( self::SITE_GENERATION, 0 );
		$cached = get_transient( self::CACHE . 'site' );
		if ( is_array( $cached ) && ( $cached['gen'] ?? null ) === $gen && ( $cached['v'] ?? '' ) === Utility_Classes::version() . '.' . self::CACHE_SCHEMA && is_array( $cached['names'] ?? null ) ) {
			$memo = array_map( 'strval', $cached['names'] );

			return $memo;
		}
		global $wpdb;
		$names = array();
		// Content that renders as blocks: not the plugin's own conversion
		// records (the design's source, not anything a person edits), form
		// entries, menus or revisions.
		$skip = "'revision','nav_menu_item','attachment','customize_changeset','oembed_cache','user_request','" . esc_sql( \DXAI_UI\Content\Content_Types::CONVERSION ) . "','" . esc_sql( \DXAI_UI\Content\Content_Types::FORM_ENTRY ) . "'";
		for ( $offset = 0; $offset < self::SITE_SCAN; $offset += 50 ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a bounded scan, cached until content changes.
				$wpdb->prepare(
					"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type NOT IN ({$skip}) AND post_status NOT IN ('trash','auto-draft','inherit') AND post_content LIKE %s ORDER BY post_modified_gmt DESC, ID DESC LIMIT %d, 50", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $skip is constants, escaped.
					'%' . $wpdb->esc_like( 'dxai' ) . '%',
					$offset
				),
				ARRAY_A
			);
			if ( ! is_array( $rows ) || $rows === array() ) {
				break;
			}
			foreach ( $rows as $row ) {
				$design = array_fill_keys( array_map( 'strval', (array) get_post_meta( (int) $row['ID'], Style_Hoister::DESIGN_NAMES_META, true ) ), true );
				foreach ( Utility_Classes::classes_in_markup( (string) $row['post_content'] ) as $class ) {
					if ( ! isset( $design[ $class ] ) ) {
						$names[ $class ] = true;
					}
				}
			}
			if ( count( $rows ) < 50 ) {
				break;
			}
		}
		$memo = array_map( 'strval', array_keys( $names ) );
		set_transient(
			self::CACHE . 'site',
			array(
				'gen'   => $gen,
				'v'     => Utility_Classes::version() . '.' . self::CACHE_SCHEMA,
				'names' => $memo,
			),
			self::CACHE_TTL
		);

		return $memo;
	}

	/**
	 * GET dxai-ui/v1/utility-rules?classes=a,b&post=ID — the rules of utility
	 * classes the editor has not got yet: a class a person types into
	 * "Additional CSS class(es)" that no content on the site used before, in
	 * the editor form by default, with the longhands each class sets (what a
	 * Design CSS edit must take over, Utility_Classes::overlapping()).
	 */
	public static function routes(): void {
		if ( ! defined( 'DXAI_UI_REST_NAMESPACE' ) ) {
			return;
		}
		register_rest_route(
			(string) constant( 'DXAI_UI_REST_NAMESPACE' ),
			'/utility-rules',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'rest_rules' ),
				'permission_callback' => static function ( $request ): bool {
					$post = $request instanceof \WP_REST_Request ? (int) $request['post'] : 0;

					return $post > 0 ? current_user_can( 'edit_post', $post ) : current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'classes' => array(
						'type'     => 'string',
						'required' => true,
					),
					'post'    => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'editor'  => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public static function rest_rules( $request ) {
		$names  = array_slice( array_values( array_unique( preg_split( '/[\s,]+/', (string) $request['classes'], -1, PREG_SPLIT_NO_EMPTY ) ?: array() ) ), 0, 500 );
		$post   = (int) $request['post'];
		$live   = self::live_utilities( array_fill_keys( array_map( 'strval', $names ), true ), $post > 0 ? array( $post ) : array(), array() );
		$sets   = array();
		foreach ( $live as $class ) {
			$sets[ $class ] = Utility_Classes::longhands( array( $class ) );
		}

		return rest_ensure_response(
			array(
				'classes'   => $live,
				'css'       => Utility_Classes::css_for_classes( $live, (bool) $request['editor'] ),
				'longhands' => (object) $sets,
				'version'   => Utility_Classes::version(),
			)
		);
	}

	/**
	 * css_for_markup()'s parts, from the cache when nothing they are built
	 * from has changed — the markup itself is the cache key, and the posts it
	 * pulls in and the design sheets that apply are checked as for a post —
	 * so a template printing the footer on every archive view does not parse
	 * its blocks and load the utility catalogue each time.
	 *
	 * @return array{rules: string, utilities: array<int, string>, utility_css: string}
	 */
	private static function parts_for_markup( string $markup, int $scope_id ): array {
		static $memo = array();
		$empty = array(
			'rules'       => '',
			'utilities'   => array(),
			'utility_css' => '',
			'part_utilities' => array(),
		);
		if ( trim( $markup ) === '' ) {
			return $empty;
		}
		$scope_id = max( 0, $scope_id );
		$sig      = md5( $markup ) . '.' . $scope_id;
		if ( isset( $memo[ $sig ] ) ) {
			return $memo[ $sig ];
		}
		$name   = self::CACHE . 'm_' . md5( $sig );
		$cached = get_transient( $name );
		if ( is_array( $cached ) && isset( $cached['key'], $cached['deps'], $cached['data'] ) && is_array( $cached['deps'] ) && is_array( $cached['data'] )
			&& hash_equals( (string) $cached['key'], self::signature( $scope_id, $cached['deps'], $sig ) ) ) {
			foreach ( $cached['deps'] as $dep ) {
				self::$written[ (int) $dep ] = true;
			}
			$memo[ $sig ] = array_merge( $empty, $cached['data'] );

			return $memo[ $sig ];
		}

		$acc = self::accumulator();
		self::collect_markup( $markup, $acc, 0 );
		$posts     = array_values( array_unique( array_filter( array_merge( array( $scope_id ), array_map( 'intval', array_keys( $acc['seen'] ) ) ) ) ) );
		$utilities = self::live_utilities( $acc['tokens'], $posts, $acc['toggles'] );
		$part_util = self::part_utilities( $acc['part_tokens'], $posts, $acc['toggles'] );
		$out       = '';
		foreach ( $acc['rules'] as $class => $css ) {
			$out .= self::rule( $class, $css );
		}
		$data = array(
			'rules'          => self::part_base_css( $acc['parts'] ) . $out . self::part_rules_css( $acc['part_rules'] ) . self::overrides( $acc['own'], $utilities ),
			'utilities'      => $utilities,
			'part_utilities' => $part_util,
			'utility_css'    => Utility_Classes::css_for_classes( $utilities ) . self::part_utility_css( $part_util, false ),
		);
		set_transient(
			$name,
			array(
				'key'  => self::signature( $scope_id, $posts, $sig ),
				'deps' => $posts,
				'data' => $data,
			),
			self::CACHE_TTL
		);
		$memo[ $sig ] = $data;

		return $data;
	}

	/**
	 * A post's rules, from the cache when nothing they are built from has
	 * changed.
	 *
	 * @return array{rules: string, utilities: array<int, string>, utility_css: string}
	 */
	private static function parts_for_post( int $post_id ): array {
		$empty = array(
			'rules'       => '',
			'utilities'   => array(),
			'utility_css' => '',
			'part_utilities' => array(),
		);
		if ( $post_id < 1 ) {
			return $empty;
		}
		$extra     = self::extra_markup( $post_id );
		// The preset fallbacks are written for the design the post shows (preset_scope()): part of the key, so
		// a page that starts or stops showing a copied design gets its rules again.
		$extra_sig = md5( implode( "\0", $extra ) . "\0scope:" . self::preset_scope( $post_id ) );
		$cached    = get_transient( self::CACHE . $post_id );
		if ( is_array( $cached ) && isset( $cached['key'], $cached['deps'], $cached['data'] ) && is_array( $cached['deps'] ) && is_array( $cached['data'] )
			&& hash_equals( (string) $cached['key'], self::signature( $post_id, $cached['deps'], $extra_sig ) ) ) {
			foreach ( $cached['deps'] as $dep ) {
				self::$written[ (int) $dep ] = true;
			}

			return array_merge( $empty, $cached['data'] );
		}

		$acc = self::accumulator();
		self::collect_post( $post_id, $acc, 0 );
		foreach ( $extra as $markup ) {
			self::collect_markup( $markup, $acc, 1 );
		}
		$utilities = self::live_utilities( $acc['tokens'], array_keys( $acc['seen'] ), $acc['toggles'] );
		$part_util = self::part_utilities( $acc['part_tokens'], array_keys( $acc['seen'] ), $acc['toggles'] );
		$data      = array(
			'rules'          => self::part_base_css( $acc['parts'] ) . self::rules_css( $post_id, $acc['rules'], $acc['presets'] ) . self::part_rules_css( $acc['part_rules'] ) . self::overrides( $acc['own'], $utilities ),
			'utilities'      => $utilities,
			'part_utilities' => $part_util,
			'utility_css'    => Utility_Classes::css_for_classes( $utilities ) . self::part_utility_css( $part_util, false ),
		);
		$deps      = array_map( 'intval', array_keys( $acc['seen'] ) );
		set_transient(
			self::CACHE . $post_id,
			array(
				'key'  => self::signature( $post_id, $deps, $extra_sig ),
				'deps' => $deps,
				'data' => $data,
			),
			self::CACHE_TTL
		);

		return $data;
	}

	/**
	 * Everything a post's cached rules were built from, hashed.
	 *
	 * @param array<int, int|string> $deps Posts read to build them.
	 */
	private static function signature( int $post_id, array $deps, string $extra_sig ): string {
		$scope = (int) get_post_meta( $post_id, Page_Scope::META, true );
		$parts = array(
			self::CACHE_SCHEMA,
			Utility_Classes::version(),
			get_stylesheet(),
			(int) get_option( self::GENERATION, 0 ),
			$extra_sig,
			Token_Styles::is_brand( $post_id ) ? 1 : 0,
			$scope,
			$scope > 0 ? md5( (string) wp_json_encode( get_post_meta( $scope, Style_Hoister::DESIGN_NAMES_META, true ) ) ) : '',
		);
		foreach ( $deps as $dep ) {
			$dep     = (int) $dep;
			$parts[] = $dep . '@' . (string) get_post_field( 'post_modified_gmt', $dep ) . '#' . (string) get_post_status( $dep ) . '~' . md5( (string) wp_json_encode( get_post_meta( $dep, Style_Hoister::DESIGN_NAMES_META, true ) ) );
			foreach ( Utility_Classes::design_sheets( $dep ) as $path ) {
				$parts[] = $path . '@' . (string) @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a sheet removed since is a changed input.
			}
		}

		return md5( implode( '|', $parts ) );
	}

	/**
	 * Block markup that renders with the post without being stored in it.
	 *
	 * @return array<int, string>
	 */
	private static function extra_markup( int $post_id ): array {
		/**
		 * Filters the block markup whose styles are written with a page's.
		 *
		 * Markup the page renders that is not in its content or in a part or
		 * pattern it references — the site header built from a menu, the
		 * footer's widget contents. Each string's dxs- rules, dxaiInner rules
		 * and utility classes are written with the page's rules, front
		 * end and editor canvas. Part of the cache key, so return the same
		 * strings for the same content.
		 *
		 * @param array<int, string> $markup  Block markup strings.
		 * @param int                $post_id The page being styled.
		 */
		$extra = apply_filters( 'dxai_ui_style_rules_markup', array(), $post_id );
		$out   = array();
		foreach ( is_array( $extra ) ? $extra : array() as $markup ) {
			if ( is_string( $markup ) && trim( $markup ) !== '' ) {
				$out[] = $markup;
			}
		}

		return $out;
	}

	/**
	 * The utility classes to write for class names found in markup: the
	 * names that are utilities, minus the design's own — what the design
	 * sheets of the posts involved define, what was recorded as the design's
	 * at import on them and their scope page, what the design's runtime
	 * toggles — and NEVER_EMIT.
	 *
	 * @param array<string, bool> $tokens  Class names found.
	 * @param array<int, int>     $posts   Posts whose design applies.
	 * @param array<string, bool> $toggles Names the runtime toggles.
	 * @return array<int, string>
	 */
	private static function live_utilities( array $tokens, array $posts, array $toggles ): array {
		if ( $tokens === array() ) {
			return array();
		}
		$candidates = array();
		foreach ( array_keys( $tokens ) as $token ) {
			$token = (string) $token;
			if ( ! in_array( $token, self::NEVER_EMIT, true ) && ! isset( $toggles[ $token ] ) && Utility_Classes::is_utility( $token ) ) {
				$candidates[] = $token;
			}
		}
		if ( $candidates === array() ) {
			return array();
		}
		$sheets = array();
		$design = array();
		foreach ( array_unique( array_filter( array_map( 'intval', $posts ) ) ) as $id ) {
			$sheets = array_merge( $sheets, Utility_Classes::design_sheets( $id ) );
			foreach ( array_unique( array( $id, (int) get_post_meta( $id, Page_Scope::META, true ) ) ) as $owner ) {
				foreach ( $owner > 0 ? (array) get_post_meta( $owner, Style_Hoister::DESIGN_NAMES_META, true ) : array() as $name ) {
					if ( is_string( $name ) ) {
						$design[ $name ] = true;
					}
				}
			}
		}
		$design += array_fill_keys( Utility_Classes::sheet_context( array_values( array_unique( $sheets ) ) )['classes'], true );

		return array_values( array_filter( $candidates, static fn( $c ) => ! isset( $design[ $c ] ) ) );
	}

	/**
	 * The rules that give a block's own CSS the last word over a utility
	 * class it also carries (see the class comment): for each such block,
	 * the declarations that set a property one of those utilities sets,
	 * restated as important for elements carrying its dxs- class and one of
	 * them. `html :where(.dxs-…):where(.u)` is (0,0,1): above a utility's
	 * (0,0,0), below every other `!important` rule with a class in it — the
	 * design's (0,2,0 and up), core's layout margins and preset classes
	 * (0,1,0) — which beat the inline style too. The converter never gives a block both
	 * (Style_Hoister::block_options()), so a converted page has none of these
	 * until someone edits it.
	 *
	 * @param array<int, array{dxs:string, css:string, classes:array<int, string>}> $own
	 * @param array<int, string>                                                   $utilities Written.
	 */
	private static function overrides( array $own, array $utilities ): string {
		if ( $own === array() || $utilities === array() ) {
			return '';
		}
		$live = array_fill_keys( $utilities, true );
		$out  = array();
		foreach ( $own as $block ) {
			// Only the utilities the block's CSS overlaps name the elements.
			$with = array_values( array_filter( $block['classes'], static fn( $c ) => isset( $live[ $c ] ) && Utility_Classes::overlapping( $block['css'], array( $c ) ) !== array() ) );
			$take = Utility_Classes::overlapping( $block['css'], $with );
			if ( $take === array() ) {
				continue;
			}
			$decls = array();
			foreach ( $take as $decl ) {
				$decls[] = preg_match( '/!\s*important\s*$/i', $decl ) === 1 ? $decl : $decl . ' !important';
			}
			$css = self::safe( implode( ';', $decls ) );
			if ( $css === '' || preg_match( '/^dxs-[a-z0-9]+$/', $block['dxs'] ) !== 1 ) {
				continue;
			}
			sort( $with );
			$rule         = 'html :where(.' . $block['dxs'] . '):where(' . implode( ',', array_map( static fn( $c ) => '.' . $c, $with ) ) . ')' . (string) ( $block['inner'] ?? '' ) . '{' . $css . '}';
			$out[ $rule ] = true;
		}

		return implode( '', array_keys( $out ) );
	}

	/**
	 * The dxs- rules and the preset colour fallbacks of a post.
	 *
	 * @param array<string, string> $rules
	 * @param array<string, bool>   $presets
	 */
	private static function rules_css( int $post_id, array $rules, array $presets ): string {
		$out = '';
		foreach ( $rules as $class => $css ) {
			$out .= self::rule( $class, $css );
		}

		/*
		 * A preset text colour names the site's CURRENT brand palette. For a
		 * page whose design is not the brand (another design was imported
		 * after it), `has-accent-color` would take the new brand's accent; the
		 * page's own tokens are still defined in its stylesheet, so point the
		 * classes back at them, inside the page's scope only.
		 */
		$brand_of = \DXAI_UI\Structures\Design_Attach::attached( $post_id ) ? \DXAI_UI\Structures\Design_Attach::source_for( $post_id ) : $post_id;
		if ( $presets !== array() && ! Token_Styles::is_brand( $brand_of ) ) {
			$scope = self::preset_scope( $post_id );
			// Only the design's own colours have a token to point at. A theme colour a block was given (a design
			// fitted to its theme, Theme_Class_Swap) is the theme's preset, and a rule here would blank it.
			$palette = get_post_meta( $scope, Token_Styles::META, true );
			$own     = array();
			foreach ( is_array( $palette['colors'] ?? null ) ? $palette['colors'] : array() as $row ) {
				$own[ (string) ( is_array( $row ) ? ( $row['slug'] ?? '' ) : '' ) ] = true;
			}
			foreach ( array_keys( $presets ) as $slug ) {
				if ( $own !== array() && ! isset( $own[ $slug ] ) ) {
					continue;
				}
				$out .= '.dxai-ui.dxai-ui--' . $scope . ' .has-' . $slug . '-color{color:var(--dxai-' . $slug . '--fg,var(--dxai-' . $slug . ')) !important}';
			}
		}

		return $out;
	}

	/**
	 * One class's rule, with inline-style precedence (see the class comment).
	 */
	public static function rule( string $class, string $css, string $inner = '' ): string {
		// Text colours on their own channel (Token_Styles::fg_channel(), Theme_Binding): the stored attribute and
		// its hash are untouched, only the rule written from it.
		$css = self::safe( Token_Styles::fg_channel( $css ) );
		if ( $css === '' || preg_match( '/^dxs-[a-z0-9]+$/', $class ) !== 1 ) {
			return '';
		}
		$one = ':is(.' . $class . ',#dxai-h)';
		if ( $inner !== '' ) {
			// The element inside a part is one the block editor writes inline styles on (core/image: `height:auto`);
			// only !important keeps the design's declarations above them.
			$css = implode(
				';',
				array_map(
					static fn( $d ) => $d['important'] || $d['prop'] === '' ? $d['raw'] : $d['raw'] . ' !important',
					Utility_Classes::declarations( $css )
				)
			);
		}

		// $inner: the element inside the block that the CSS was written for (PARTS). The class is a hash of the CSS, so a
		// card and a picture that share their declarations share the class: without the marker on the same element the
		// picture's rule (`.dxs-x img`) would reach every image inside the card.
		$marker = $inner !== '' ? array_search( $inner, self::PARTS, true ) : false;

		return ( is_string( $marker ) ? '.' . $marker : '' ) . $one . $one . $one . $inner . '{' . $css . '}';
	}

	/**
	 * What makes the wrapper of a part (PARTS) stand aside for the element inside it. On the page the wrapper takes
	 * no box (`display:contents`), so the image is laid out exactly where the design put it: as the flex or grid item,
	 * the positioned layer, the child of its container. In the block editor the wrapper is the block the person
	 * selects, so it keeps a box, and the design's classes on it (which are written for the image) are neutralised.
	 *
	 * @param array<string, bool> $parts Markers on the page.
	 */
	private static function part_base_css( array $parts ): string {
		if ( ! isset( $parts['dxai-part-img'] ) ) {
			return '';
		}

		// The image core's own rule (`.wp-block-image img`) sets is bottom-aligned; the design's was on the baseline.
		// WordPress writes the file's own width and height on the image (against layout shift). The design's `<img>` had
		// none, so one that sets only its height (`h-10`) kept its proportions; with the attribute it takes the file's
		// width, is cut back to its container and comes out stretched. `width:auto` puts the design's rule back, and with
		// no weight at all it gives way to every width the design does set.
		return '.wp-block-image.dxai-part-img{display:contents !important}'
			. ':where(.wp-block-image.dxai-part-img img){width:auto}'
			. ':where(.dxai-ui) .wp-block-image.dxai-part-img img{vertical-align:baseline}'
			. '.editor-styles-wrapper .wp-block-image.dxai-part-img{display:block !important;position:static !important;inset:auto !important;margin:0 !important;padding:0 !important;border:0 !important;width:auto !important;height:auto !important;min-height:0 !important;max-width:none !important;max-height:none !important;float:none !important;transform:none !important}';
	}

	/**
	 * The dxs- rules of the blocks of a part, each written for the element inside the block.
	 *
	 * @param array<string, array{0:string, 1:string}> $part_rules
	 */
	private static function part_rules_css( array $part_rules ): string {
		$out = '';
		foreach ( $part_rules as $class => $pair ) {
			$out .= self::rule( (string) $class, $pair[0], $pair[1] );
		}

		return $out;
	}

	/**
	 * The utility classes the blocks of each part carry, that this page writes a rule for.
	 *
	 * @param array<string, array<string, bool>> $part_tokens
	 * @param array<int, int|string>             $posts
	 * @param array<string, bool>                $toggles
	 * @return array<string, array<int, string>> marker => classes
	 */
	private static function part_utilities( array $part_tokens, array $posts, array $toggles ): array {
		$out = array();
		foreach ( $part_tokens as $marker => $tokens ) {
			$live = self::live_utilities( $tokens, $posts, $toggles );
			if ( $live !== array() ) {
				$out[ (string) $marker ] = $live;
			}
		}

		return $out;
	}

	/**
	 * The rules for those classes, written for the element inside the block (Utility_Classes::css_for_classes()).
	 *
	 * @param array<string, array<int, string>> $part_utilities
	 */
	private static function part_utility_css( array $part_utilities, bool $editor ): string {
		$out = '';
		foreach ( $part_utilities as $marker => $classes ) {
			if ( isset( self::PARTS[ $marker ] ) ) {
				$out .= Utility_Classes::css_for_classes(
					$classes,
					$editor,
					array(
						'wrap'  => $marker,
						'inner' => self::PARTS[ $marker ],
					)
				);
			}
		}

		return $out;
	}

	/**
	 * The scope a post's preset-colour fallbacks are written for: its own design scope, or — an ordinary page
	 * holding blocks copied from a design page — that design's (Design_Attach). Written for the page's own id
	 * there, `.dxai-ui--{page}` matched nothing, and every `has-brand-color` heading of a copied section lost
	 * its colour.
	 */
	private static function preset_scope( int $post_id ): int {
		$scope = \DXAI_UI\Structures\Design_Attach::scope_for( $post_id );

		return $scope > 0 ? $scope : $post_id;
	}

	/**
	 * A declaration list that cannot end its rule or its <style> element.
	 *
	 * The CSS comes from the design (trusted at import) or from a block
	 * attribute someone edited. Braces and angle brackets are written as CSS
	 * escapes: inside a string or url() — where they legitimately occur, as
	 * in a `url("data:image/svg+xml,<svg …>")` background — the escape reads
	 * back as the same character, and anywhere else it is only part of a
	 * word, so no value can open a second rule or close the element.
	 */
	private static function safe( string $css ): string {
		return trim(
			self::closed(
				strtr(
					$css,
					array(
						'<' => '\3C ',
						'>' => '\3E ',
						'{' => '\7B ',
						'}' => '\7D ',
					)
				)
			)
		);
	}

	/**
	 * The declaration list without a declaration that never closes.
	 *
	 * Every rule is written on one line, and a CSS string ends only at a line
	 * break, so an unclosed quote — or an unclosed parenthesis or bracket, or
	 * a trailing backslash escaping the closing brace — would carry on past
	 * this rule's `}` and take the page's other rules with it. KSES leaves
	 * such fragments (safecss_filter_attr() splits a data: URI on its `;` and
	 * keeps the `base64,…")` half), and so can a hand edit. The list is cut
	 * back to the last declaration that closed; a browser would have ignored
	 * the rest.
	 */
	private static function closed( string $css ): string {
		$depth = 0;
		$quote = '';
		$keep  = 0;
		$len   = strlen( $css );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $css[ $i ];
			if ( $ch === '\\' ) {
				if ( $i + 1 >= $len ) {
					return substr( $css, 0, $keep );
				}
				++$i;
				continue;
			}
			if ( $quote !== '' ) {
				if ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( $ch === '"' || $ch === "'" ) {
				$quote = $ch;
			} elseif ( $ch === '(' || $ch === '[' ) {
				++$depth;
			} elseif ( ( $ch === ')' || $ch === ']' ) && $depth > 0 ) {
				--$depth;
			} elseif ( $ch === ';' && $depth === 0 ) {
				$keep = $i + 1;
			}
		}

		return $quote === '' && $depth === 0 ? $css : substr( $css, 0, $keep );
	}

	/**
	 * What a collection pass fills in.
	 *
	 * @return array{rules: array<string, string>, presets: array<string, bool>, seen: array<int, bool>, tokens: array<string, bool>, toggles: array<string, bool>, own: array<int, array{dxs:string, css:string, classes:array<int, string>}>}
	 */
	private static function accumulator(): array {
		return array(
			'rules'   => array(),
			'presets' => array(),
			'seen'    => array(),
			'tokens'  => array(),
			'toggles' => array(),
			'own'     => array(),
			// dxs- class => [ CSS, inner selector ] for the blocks of a part (PARTS), and their utility classes.
			'part_rules'  => array(),
			'part_tokens' => array(),
			'parts'       => array(),
		);
	}

	/**
	 * The marker (PARTS) a block's classes carry, or ''.
	 *
	 * @param array<string, mixed> $attrs
	 */
	private static function part_of( array $attrs ): string {
		$names = is_string( $attrs['className'] ?? null ) ? preg_split( '/\s+/', $attrs['className'], -1, PREG_SPLIT_NO_EMPTY ) : array();
		foreach ( is_array( $names ) ? $names : array() as $name ) {
			if ( isset( self::PARTS[ $name ] ) ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $acc Filled in place (accumulator()).
	 */
	private static function collect_post( int $post_id, array &$acc, int $depth ): void {
		if ( $post_id < 1 || isset( $acc['seen'][ $post_id ] ) || $depth > 3 ) {
			return;
		}
		$acc['seen'][ $post_id ]   = true;
		self::$written[ $post_id ] = true;
		$inner                     = get_post_meta( $post_id, Style_Hoister::INNER_META, true );
		if ( is_array( $inner ) ) {
			foreach ( $inner as $class => $css ) {
				if ( is_string( $class ) && is_string( $css ) ) {
					$acc['rules'][ $class ] = $css;
				}
			}
		}
		self::collect_markup( (string) get_post_field( 'post_content', $post_id ), $acc, $depth );
	}

	/**
	 * collect_post() for a markup string: its blocks, then the posts they
	 * reference.
	 *
	 * @param array<string, mixed> $acc
	 */
	private static function collect_markup( string $markup, array &$acc, int $depth ): void {
		if ( trim( $markup ) === '' ) {
			return;
		}
		foreach ( Utility_Classes::class_tokens( $markup ) as $token ) {
			$acc['tokens'][ $token ] = true;
		}
		foreach ( Utility_Classes::toggle_names( $markup ) as $token ) {
			$acc['toggles'][ $token ] = true;
		}
		$refs = array();
		foreach ( parse_blocks( $markup ) as $block ) {
			self::collect_block( $block, $acc, $refs );
		}
		foreach ( $refs as $ref ) {
			self::collect_post( $ref, $acc, $depth + 1 );
		}
	}

	/**
	 * @param array<string, mixed> $block
	 * @param array<string, mixed> $acc
	 * @param array<int, int>      $refs Posts this one pulls in (parts, synced patterns).
	 */
	private static function collect_block( array $block, array &$acc, array &$refs ): void {
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$css   = $attrs[ Style_Hoister::ATTR ] ?? '';
		$part  = self::part_of( $attrs );
		if ( $part !== '' ) {
			$acc['parts'][ $part ] = true;
			$names = is_string( $attrs['className'] ?? null ) ? preg_split( '/\s+/', $attrs['className'], -1, PREG_SPLIT_NO_EMPTY ) : array();
			foreach ( array_filter( $names ?: array(), array( Utility_Classes::class, 'is_utility' ) ) as $name ) {
				$acc['part_tokens'][ $part ][ $name ] = true;
			}
		}
		if ( is_string( $css ) && trim( $css ) !== '' ) {
			$class = Style_Hoister::css_class( $css );
			if ( $part !== '' ) {
				$acc['part_rules'][ $class ] = array( $css, self::PARTS[ $part ] );
			} else {
				$acc['rules'][ $class ] = $css;
			}
			$names = is_string( $attrs['className'] ?? null ) ? preg_split( '/\s+/', $attrs['className'], -1, PREG_SPLIT_NO_EMPTY ) : array();
			$names = array_values( array_filter( $names ?: array(), array( Utility_Classes::class, 'is_utility' ) ) );
			if ( $names !== array() ) {
				$acc['own'][] = array(
					'dxs'     => $class,
					'css'     => $css,
					'classes' => $names,
					'inner'   => $part !== '' ? self::PARTS[ $part ] : '',
				);
			}
		}
		$inner = $attrs[ Style_Hoister::INNER_ATTR ] ?? null;
		if ( is_array( $inner ) ) {
			foreach ( $inner as $class => $declarations ) {
				if ( is_string( $class ) && is_string( $declarations ) ) {
					$acc['rules'][ $class ] = $declarations;
				}
			}
		}
		if ( ! empty( $attrs['textColor'] ) && is_string( $attrs['textColor'] ) ) {
			$acc['presets'][ sanitize_key( $attrs['textColor'] ) ] = true;
		}
		$name = (string) ( $block['blockName'] ?? '' );
		if ( $name === 'core/template-part' && ! empty( $attrs['slug'] ) ) {
			$part = self::part_id( (string) $attrs['slug'] );
			if ( $part > 0 ) {
				$refs[] = $part;
			}
		} elseif ( $name === 'core/block' && ! empty( $attrs['ref'] ) ) {
			$refs[] = (int) $attrs['ref'];
		}
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $inner_block ) {
			if ( is_array( $inner_block ) ) {
				self::collect_block( $inner_block, $acc, $refs );
			}
		}
	}
}

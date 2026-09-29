<?php
/**
 * The footer's content, as widgets in Appearance > Widgets.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Chrome;

use DXAI_UI\Blocks\Style_Rules;

/**
 * A converted design's footer content lives where a DevriX theme keeps its
 * own: block widgets in the widget areas `footer-column-1`… and
 * `footer-copyright`, edited in Appearance > Widgets with core blocks. The
 * frame around them (the `<footer>`, its grid, the bottom bar) is
 * Footer_Template's, and Site_Footer_Block puts the two back together.
 *
 * The areas carry the DevriX theme's ids, so on such a theme they ARE the
 * theme's areas: an id the active theme registered is left as the theme
 * registered it, and only its wrappers are set aside while the footer block
 * renders (render_area()). An id nobody registered is registered here, with
 * no wrapper around a widget and no title wrapper, so a widget's blocks
 * render exactly as the design's markup. Registering is also what makes
 * Appearance > Widgets exist on a block theme — register_sidebar() adds the
 * theme support it is gated on.
 *
 * A block theme adds one case of its own. When a site switches from a
 * classic theme to a block theme, core keeps the classic theme's areas and
 * re-registers them on every request (_wp_block_theme_register_classic_sidebars,
 * theme mod `wp_classic_sidebars`), wrappers and all, so their widgets are not
 * lost. Such an area belongs to no active theme; it is registered here over
 * the leftover, so the footer's areas have one definition whichever theme
 * was active before.
 */
final class Footer_Widgets {

	/**
	 * The widget instances this plugin wrote: widget id => the area it was
	 * written to and the md5 of the content written, which tells an instance
	 * a person has since edited from one still as imported.
	 */
	public const OWNED = 'dxai_ui_footer_widgets';

	/** The title wrapper a legacy widget gets in the footer's areas. */
	private const LEGACY_TITLE = array( '<h2 class="widget-title">', '</h2>' );

	/** @var array<string, bool> Areas registered here in this request. */
	private static array $own = array();

	public function register(): void {
		// After the theme's own registrations (default priority 10) and after
		// core has re-registered a previous classic theme's areas (priority 1).
		add_action( 'widgets_init', array( self::class, 'register_areas' ), 20 );
		// The footer's widgets rendered by a theme instead of the footer block
		// (a DevriX theme's footer.php) still need their dxs- rules.
		add_action( 'dynamic_sidebar_before', array( self::class, 'rules_for_theme_area' ), 10, 2 );
		add_action( 'update_option_sidebars_widgets', array( self::class, 'sync_placement' ), 10, 0 );
	}

	/**
	 * Keep this request's copy of the widget placement current after it is
	 * saved, in a REST request.
	 *
	 * retrieve_widgets() works from the `$sidebars_widgets` global, and
	 * wp_set_sidebars_widgets() saves the option without updating it. After
	 * `POST /wp/v2/widgets` has put a new widget in its area, the response's
	 * Allow header is worked out by calling every handler's permission check
	 * on the route (rest_send_allow_header), and the GET handler's check
	 * runs retrieve_widgets() — on the snapshot taken before the widget was
	 * placed. The new widget is registered and in no area of that snapshot,
	 * so it is "lost", and the snapshot is saved with it in Inactive Widgets.
	 * Core's early return keeps this from happening on a site whose theme was
	 * never switched away from and back; on one that was (the theme mod
	 * `sidebars_widgets` exists), it happens on every create. Measured here
	 * over HTTP: a paragraph POSTed to footer-column-3, and one to the core
	 * area header-trust, both answered 201 with their area and were stored in
	 * Inactive Widgets. The Widgets screen sends each area's widget list after
	 * its widgets (edit-widgets' saveWidgetArea, PUT /wp/v2/sidebars/{id}),
	 * which puts the widget back; any other REST client — an import, a
	 * script, an agent — loses it. Core already refreshes the global itself
	 * before updating or deleting a widget (#53657); this does the same after
	 * every placement saved in a REST request.
	 */
	public static function sync_placement(): void {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && isset( $GLOBALS['sidebars_widgets'] ) ) {
			// wp_set_sidebars_widgets() has just cleared the cached copy, so
			// this reads the option as saved.
			wp_get_sidebars_widgets();
		}
	}

	/**
	 * Register the stored footer's areas that no theme registered. Nothing
	 * is registered before a footer is imported: areas that render nowhere
	 * would only clutter Appearance > Widgets.
	 */
	public static function register_areas(): void {
		global $wp_registered_sidebars;

		$footer = Footer_Template::get();
		// An area registered here earlier in this request that a newly
		// installed footer no longer has.
		foreach ( array_keys( self::$own ) as $area ) {
			if ( ! isset( $footer['slots'][ $area ] ) ) {
				unregister_sidebar( $area );
				unset( self::$own[ $area ] );
			}
		}
		if ( $footer === array() ) {
			return;
		}
		$classic = wp_is_block_theme() ? get_theme_mod( 'wp_classic_sidebars' ) : array();
		$classic = is_array( $classic ) ? $classic : array();
		foreach ( $footer['slots'] as $area => $slot ) {
			$area    = (string) $area;
			$current = $wp_registered_sidebars[ $area ] ?? null;
			$left    = is_array( $current ) && isset( $classic[ $area ] ) && $classic[ $area ] == $current; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- same array, key order aside.
			if ( is_array( $current ) && ! $left && empty( self::$own[ $area ] ) ) {
				continue;
			}
			register_sidebar( self::args( $area, is_array( $slot ) ? $slot : array(), (string) ( $footer['title'] ?? '' ) ) );
			self::$own[ $area ] = true;
		}
	}

	/**
	 * register_sidebar() arguments for one of the footer's areas.
	 *
	 * No wrapper around a widget: a block widget's blocks are the design's
	 * markup and render as nothing else (WP_Widget_Block has no title of its
	 * own). A legacy widget the Widgets screen still offers — Navigation Menu,
	 * a plugin's — prints its title between before_title and after_title, so
	 * those hold a plain heading; left empty, its title would be a bare text
	 * node run into its list.
	 *
	 * @param array<string, mixed> $slot
	 * @return array<string, mixed>
	 */
	private static function args( string $area, array $slot, string $title ): array {
		$copyright = ( $slot['kind'] ?? '' ) === 'copyright';
		$number    = (int) ( $slot['number'] ?? 0 );
		$design    = $title !== '' ? $title : __( 'the imported design', 'dxai-ui' );

		return array(
			'id'             => $area,
			'name'           => $copyright
				? __( 'Footer Copyright', 'dxai-ui' )
				/* translators: %d: footer column number. */
				: sprintf( __( 'Footer Column %d', 'dxai-ui' ), $number ),
			'description'    => $copyright
				/* translators: %s: design name. */
				? sprintf( __( 'The bottom bar of the footer of %s: copyright, legal and social links. The bar itself comes from the design.', 'dxai-ui' ), $design )
				/* translators: 1: footer column number, 2: design name. */
				: sprintf( __( 'Column %1$d of the footer of %2$s. Its blocks render inside that column; the column, the grid and the footer background come from the design.', 'dxai-ui' ), $number, $design ),
			'class'          => '',
			'before_widget'  => '',
			'after_widget'   => '',
			'before_title'   => self::LEGACY_TITLE[0],
			'after_title'    => self::LEGACY_TITLE[1],
			'before_sidebar' => '',
			'after_sidebar'  => '',
			'show_in_rest'   => true,
		);
	}

	/**
	 * Make a footer the site's footer: store its frame, and put each area's
	 * blocks into it as block widgets.
	 *
	 * Instances this plugin wrote before are rewritten in place — the same
	 * widget ids, in the same areas — so a re-import replaces the footer
	 * rather than stacking a second one under it. Anything else found in the
	 * footer's areas (core's default widgets, a person's own) is moved to
	 * Inactive Widgets, where Appearance > Widgets shows it and it can be put
	 * back. An instance the plugin wrote that the new footer no longer needs
	 * (the new design's footer has fewer blocks) is moved there too and is no
	 * longer the plugin's: nothing is deleted, whoever wrote it.
	 *
	 * Nor is anyone's edit. An instance the plugin wrote that a person has
	 * changed since (its content no longer has the md5 recorded when it was
	 * written) is not rewritten: widgets keep no revisions, so rewriting it
	 * would lose the change for good. It becomes the person's — listed under
	 * `edited`, and like any other widget of theirs in the footer's areas
	 * moved to Inactive Widgets as it is (`inactive`) — and the design's
	 * block goes into another instance in its place.
	 *
	 * The content is stored as given: block widgets are stored raw for users
	 * with unfiltered_html, which an import requires (Structure_Repository),
	 * and so is anything run from WP-CLI or cron. Called by a signed-in user
	 * without it, each widget goes through wp_kses_post() — exactly what
	 * WP_Widget_Block::update() does when that user saves one.
	 *
	 * @param string               $footer_markup The footer's block markup.
	 * @param array<string, mixed> $context       page_id (the design's page:
	 *                                            its scope, stylesheet and
	 *                                            fonts style the Widgets
	 *                                            screen), scope, source, title,
	 *                                            owner (the design's page key)
	 *                                            and part_key (its template
	 *                                            part's key), kept with the
	 *                                            footer for hand_back_footer().
	 * @return array<string, mixed> block_markup — what to put in a page where
	 *                              the footer goes — and what was written:
	 *                              columns, areas (area => widget ids),
	 *                              created and reused (counts), edited (ids
	 *                              of the plugin's widgets a person had
	 *                              changed: kept as they are, no longer the
	 *                              plugin's), inactive (ids moved from the
	 *                              footer's areas to Inactive Widgets now:
	 *                              anyone else's widgets, edited ones among
	 *                              them), released (the plugin's ids the new
	 *                              footer does not need, also in Inactive
	 *                              Widgets), registered, replaced (source_of()
	 *                              the footer of another design that stood in
	 *                              the areas until now; [] when none).
	 */
	public static function install( string $footer_markup, array $context = array() ): array {
		$built = Footer_Template::build( $footer_markup );
		if ( $built['slots'] === array() ) {
			return array(
				'block_markup' => '',
				'error'        => 'no_footer',
			);
		}
		if ( is_user_logged_in() && ! current_user_can( 'unfiltered_html' ) ) {
			$built['template'] = wp_kses_post( $built['template'] );
			foreach ( $built['slots'] as $area => $slot ) {
				$built['slots'][ $area ]['blocks'] = array_map( 'wp_kses_post', $slot['blocks'] );
			}
		}

		$before   = Footer_Template::get();
		$previous = Footer_Template::areas();
		$record   = Footer_Template::store( $built, $context );
		// This request's registry too: a column the new footer adds.
		self::register_areas();

		$contents = array();
		foreach ( $built['slots'] as $area => $slot ) {
			$contents[ (string) $area ] = $slot['blocks'];
		}
		$written = self::write( $contents, $previous );

		return array(
			'block_markup' => Site_Footer_Block::MARKUP,
			'columns'      => (int) $record['columns'],
			'areas'        => $written['areas'],
			'created'      => $written['created'],
			'reused'       => $written['reused'],
			'edited'       => $written['edited'],
			'inactive'     => $written['inactive'],
			'released'     => $written['released'],
			'registered'   => array_keys( self::$own ),
			// Another design's footer stood in the areas until now (as
			// Header_Menus::install() reports the header it replaced).
			'replaced'     => $before !== array() && (int) ( $before['scope'] ?? 0 ) !== (int) $record['scope'] ? self::source_of( $before ) : array(),
		);
	}

	/**
	 * Where a stored footer came from, as the import report names it.
	 *
	 * @param array<string, mixed> $record Footer_Template::get().
	 * @return array{title:string, page_id:int, scope:int, source:string, owner:string}
	 */
	public static function source_of( array $record ): array {
		return array(
			'title'   => (string) ( $record['title'] ?? '' ),
			'page_id' => (int) ( $record['page_id'] ?? 0 ),
			'scope'   => (int) ( $record['scope'] ?? 0 ),
			'source'  => (string) ( $record['source'] ?? '' ),
			'owner'   => (string) ( $record['owner'] ?? '' ),
		);
	}

	/**
	 * The posts of the stored footer's design that show it: its page and the
	 * pages rendered in its scope (crawled pages) whose content holds the
	 * footer block — not in the trash, not revisions. Nothing else ties a
	 * design to the widget areas, so when none is left the areas are free
	 * for the next design, and when some are, a design taking the areas owes
	 * those pages their footer (Structure_Repository::hand_back_footer()).
	 * An ordinary page or template someone put the block on is not the
	 * design's: it shows whichever footer the site has.
	 *
	 * @param array<string, mixed>|null $record Footer_Template::get(); null reads it.
	 * @return array<int, int> Post ids, ascending.
	 */
	public static function holders( ?array $record = null ): array {
		global $wpdb;

		$record = $record ?? Footer_Template::get();
		$page   = (int) ( $record['page_id'] ?? 0 );
		$scope  = (int) ( $record['scope'] ?? 0 );
		if ( $record === array() || ( $page < 1 && $scope < 1 ) ) {
			return array();
		}
		$ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_content LIKE %s
				AND p.post_type NOT IN ('revision','wp_template','wp_template_part','wp_block','wp_navigation','nav_menu_item')
				AND p.post_status IN ('publish','future','draft','pending','private')
				AND ( p.ID = %d OR m.meta_value = %s )
				ORDER BY p.ID ASC",
				\DXAI_UI\Structures\Page_Scope::META,
				'%' . $wpdb->esc_like( '<!-- wp:' . Site_Footer_Block::NAME ) . '%',
				$page,
				(string) $scope
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Write each area's widgets.
	 *
	 * @param array<string, array<int, string>> $contents area => block markup, one widget each.
	 * @param array<int, string>                $previous The previous footer's areas.
	 * @return array<string, mixed>
	 */
	private static function write( array $contents, array $previous ): array {
		$sidebars = get_option( 'sidebars_widgets', array() );
		$sidebars = is_array( $sidebars ) ? $sidebars : array();
		unset( $sidebars['array_version'] );
		$settings = get_option( 'widget_block', array() );
		$settings = is_array( $settings ) ? $settings : array();
		unset( $settings['_multiwidget'] );
		$owned   = self::owned();
		$managed = array_values( array_unique( array_merge( array_keys( $contents ), $previous ) ) );

		$where = array();
		foreach ( $sidebars as $sidebar => $ids ) {
			foreach ( (array) $ids as $id ) {
				$where[ (string) $id ] = (string) $sidebar;
			}
		}

		// The instances this plugin can rewrite, by the area they stand in
		// ('' for Inactive Widgets or none), in their order there.
		$pool     = array();
		$released = array();
		$edited   = array();
		foreach ( $owned as $id => $info ) {
			$number = self::number( $id );
			if ( $number < 1 || ! isset( $settings[ $number ] ) ) {
				// Deleted in Appearance > Widgets.
				unset( $owned[ $id ] );
				continue;
			}
			if ( md5( (string) ( $settings[ $number ]['content'] ?? '' ) ) !== $info['md5'] ) {
				// Changed by a person since it was written: theirs, kept as
				// it is (below, with whatever else stands in the areas).
				unset( $owned[ $id ] );
				$edited[] = $id;
				continue;
			}
			$at = $where[ $id ] ?? '';
			if ( $at !== '' && $at !== 'wp_inactive_widgets' && ! in_array( $at, $managed, true ) ) {
				// Moved by a person to an area the footer does not use: theirs.
				unset( $owned[ $id ] );
				$released[] = $id;
				continue;
			}
			$pool[ $at === 'wp_inactive_widgets' ? '' : $at ][] = $id;
		}
		foreach ( $pool as $at => $ids ) {
			if ( $at !== '' ) {
				$pool[ $at ] = array_values( array_intersect( (array) ( $sidebars[ $at ] ?? array() ), $ids ) );
			}
		}

		// Whatever else stands in the footer's areas goes to Inactive Widgets.
		$inactive = array_values( array_diff( (array) ( $sidebars['wp_inactive_widgets'] ?? array() ), array_keys( $owned ) ) );
		$moved    = array();
		foreach ( $managed as $area ) {
			foreach ( (array) ( $sidebars[ $area ] ?? array() ) as $id ) {
				if ( ! isset( $owned[ (string) $id ] ) ) {
					$moved[] = (string) $id;
				}
			}
			$sidebars[ $area ] = array();
		}

		// First each area takes back its own instances, in order; then what
		// is still missing comes from any instance left over, then new ones.
		$assigned = array();
		foreach ( $contents as $area => $blocks ) {
			$pool[ $area ] = $pool[ $area ] ?? array();
			foreach ( array_keys( $blocks ) as $i ) {
				$assigned[ $area ][ $i ] = (string) array_shift( $pool[ $area ] );
			}
		}
		$spare = array();
		foreach ( $pool as $ids ) {
			$spare = array_merge( $spare, $ids );
		}
		$numbers = array_filter( array_keys( $settings ), 'is_int' );
		$next    = max( 1, $numbers === array() ? 1 : max( $numbers ) ) + 1;
		$created = 0;
		$reused  = 0;
		$areas   = array();
		foreach ( $contents as $area => $blocks ) {
			foreach ( $blocks as $i => $content ) {
				$id = $assigned[ $area ][ $i ];
				if ( $id === '' ) {
					$id = (string) array_shift( $spare );
				}
				if ( $id === '' ) {
					$id = 'block-' . $next;
					++$next;
					++$created;
				} else {
					// Only instances still exactly as the plugin wrote them
					// get here (edited ones left the pool above).
					++$reused;
				}
				$settings[ self::number( $id ) ] = array( 'content' => $content );
				$sidebars[ $area ][]             = $id;
				$owned[ $id ]                    = array(
					'area' => $area,
					'md5'  => md5( $content ),
				);
				$areas[ $area ][]                = $id;
			}
		}

		// Instances the new footer does not need: to Inactive Widgets, and no
		// longer the plugin's, whether or not anyone edited them.
		foreach ( $spare as $id ) {
			$inactive[] = $id;
			$released[] = $id;
			unset( $owned[ $id ] );
		}

		$sidebars['wp_inactive_widgets'] = array_values( array_unique( array_merge( $inactive, $moved ) ) );
		foreach ( $sidebars as $sidebar => $ids ) {
			$sidebars[ $sidebar ] = array_values( (array) $ids );
		}
		ksort( $settings );
		$settings['_multiwidget'] = 1;

		// Content first, then placement: wp_set_sidebars_widgets() fires the
		// option-update hooks themes clear their footer caches on.
		update_option( 'widget_block', $settings );
		wp_set_sidebars_widgets( $sidebars );
		update_option( self::OWNED, $owned, false );
		self::keep_inactive( array_merge( $moved, $spare ) );
		self::refresh();

		return array(
			'areas'    => $areas,
			'created'  => $created,
			'reused'   => $reused,
			'edited'   => $edited,
			'inactive' => $moved,
			'released' => $released,
		);
	}

	/**
	 * Keep the widgets moved to Inactive Widgets there.
	 *
	 * On a theme that was active once before, core remembers where its
	 * widgets stood then (theme mod `sidebars_widgets`), and every
	 * retrieve_widgets() — each visit to Appearance > Widgets, each widgets
	 * REST request — takes any of them it finds in Inactive Widgets back to
	 * that old place (wp_map_sidebars_widgets()). On a block theme the old
	 * place is an area nobody registers, so the widgets would vanish from the
	 * screen that is supposed to offer them back. Measured on this install:
	 * Twenty Twenty-Five remembered core's five default widgets in
	 * `sidebar-1` and `sidebar-2`. The moved widgets are taken out of that
	 * memory; the widgets themselves are untouched.
	 *
	 * @param array<int, string> $moved
	 */
	private static function keep_inactive( array $moved ): void {
		if ( $moved === array() ) {
			return;
		}
		$memory = get_theme_mod( 'sidebars_widgets' );
		if ( ! is_array( $memory ) || ! is_array( $memory['data'] ?? null ) ) {
			return;
		}
		$changed = false;
		foreach ( $memory['data'] as $sidebar => $ids ) {
			if ( $sidebar === 'wp_inactive_widgets' || ! is_array( $ids ) ) {
				continue;
			}
			$kept = array_values( array_diff( $ids, $moved ) );
			if ( $kept !== array_values( $ids ) ) {
				$memory['data'][ $sidebar ] = $kept;
				$changed                    = true;
			}
		}
		if ( $changed ) {
			set_theme_mod( 'sidebars_widgets', $memory );
		}
	}

	/**
	 * This request's widget registry after a write. Block widget instances
	 * are registered per instance on widgets_init, so one written later in
	 * the same request would render as nothing until the next.
	 */
	private static function refresh(): void {
		global $wp_widget_factory;

		if ( $wp_widget_factory instanceof \WP_Widget_Factory && method_exists( $wp_widget_factory, 'get_widget_object' ) ) {
			$widget = $wp_widget_factory->get_widget_object( 'block' );
			if ( $widget instanceof \WP_Widget ) {
				$widget->_register();
			}
		}
		// Re-reads the option into the global retrieve_widgets() works from.
		wp_get_sidebars_widgets();
	}

	/**
	 * @return array<string, array{area:string, md5:string}>
	 */
	private static function owned(): array {
		$owned = get_option( self::OWNED, array() );
		$out   = array();
		foreach ( is_array( $owned ) ? $owned : array() as $id => $info ) {
			if ( is_string( $id ) && self::number( $id ) > 0 && is_array( $info ) ) {
				$out[ $id ] = array(
					'area' => (string) ( $info['area'] ?? '' ),
					'md5'  => (string) ( $info['md5'] ?? '' ),
				);
			}
		}

		return $out;
	}

	/**
	 * The instance number of a block widget id (`block-12` → 12); 0 for any
	 * other widget.
	 */
	private static function number( string $id ): int {
		return preg_match( '/^block-(\d+)$/', $id, $m ) === 1 ? (int) $m[1] : 0;
	}

	/**
	 * The block markup of an area's block widgets, in order — what its dxs-
	 * rules are collected from. Legacy widgets carry none.
	 *
	 * @return array<int, string>
	 */
	public static function block_contents( string $area ): array {
		$sidebars = wp_get_sidebars_widgets();
		$settings = get_option( 'widget_block', array() );
		$out      = array();
		foreach ( (array) ( $sidebars[ $area ] ?? array() ) as $id ) {
			$number = self::number( (string) $id );
			if ( $number > 0 && is_array( $settings ) && is_string( $settings[ $number ]['content'] ?? null ) ) {
				$out[] = $settings[ $number ]['content'];
			}
		}

		return $out;
	}

	/**
	 * An area's widgets, rendered as the footer's content.
	 *
	 * dynamic_sidebar() with nothing of its own around them: a theme's
	 * before/after-sidebar markup is blanked for this call only, and so are
	 * the widget and title wrappers of every block widget (an area registered
	 * here has none), and wp_filter_content_tags() is kept off the block
	 * widgets' content. That filter adds `decoding="async"` and may add
	 * dimensions to an image; the design's markup has neither, and every
	 * place this footer renders runs the whole output through the same filter
	 * afterwards (`the_content`, a block template) — the same way a template
	 * part's content is filtered by the content it sits in. What a block
	 * widget adds besides — its before_widget with the widget_block class —
	 * is in the blanked wrapper.
	 *
	 * A legacy widget (Navigation Menu, a plugin's) keeps the area's wrappers
	 * — a theme's, or none here — and always a title wrapper: blanked, its
	 * title came out as bare text before its list (measured with a Navigation
	 * Menu widget titled "QUICK LINKS" in footer-column-2).
	 */
	public static function render_area( string $area ): string {
		global $wp_registered_sidebars;

		if ( ! isset( $wp_registered_sidebars[ $area ] ) || ! is_array( $wp_registered_sidebars[ $area ] ) ) {
			return '';
		}
		$saved = $wp_registered_sidebars[ $area ];
		$wp_registered_sidebars[ $area ]['before_sidebar'] = '';
		$wp_registered_sidebars[ $area ]['after_sidebar']  = '';
		$params = static function ( $params ) use ( $area ) {
			if ( ! is_array( $params ) || ! is_array( $params[0] ?? null ) || ( $params[0]['id'] ?? '' ) !== $area ) {
				return $params;
			}
			if ( self::number( (string) ( $params[0]['widget_id'] ?? '' ) ) > 0 ) {
				foreach ( array( 'before_widget', 'after_widget', 'before_title', 'after_title' ) as $key ) {
					$params[0][ $key ] = '';
				}
			} elseif ( (string) ( $params[0]['before_title'] ?? '' ) === '' && (string) ( $params[0]['after_title'] ?? '' ) === '' ) {
				$params[0]['before_title'] = self::LEGACY_TITLE[0];
				$params[0]['after_title']  = self::LEGACY_TITLE[1];
			}

			return $params;
		};
		add_filter( 'dynamic_sidebar_params', $params, PHP_INT_MAX );
		$tags = has_filter( 'widget_block_content', 'wp_filter_content_tags' );
		if ( $tags !== false ) {
			remove_filter( 'widget_block_content', 'wp_filter_content_tags', (int) $tags );
		}
		ob_start();
		try {
			dynamic_sidebar( $area );
		} finally {
			$html = (string) ob_get_clean();
			if ( $tags !== false ) {
				add_filter( 'widget_block_content', 'wp_filter_content_tags', (int) $tags );
			}
			remove_filter( 'dynamic_sidebar_params', $params, PHP_INT_MAX );
			$wp_registered_sidebars[ $area ] = $saved;
		}

		return $html;
	}

	/**
	 * The dxs- rules of the footer's widgets, when a theme renders one of
	 * its areas rather than the footer block (Site_Footer_Block takes care
	 * of its own). Printed in the footer, as core prints a style enqueued
	 * after <head>; a theme utility class whose rule the page already has is
	 * not written again (Style_Rules::attach_markup()), which would reorder
	 * it against the others.
	 *
	 * @param int|string $index
	 */
	public static function rules_for_theme_area( $index, $has_widgets = true ): void {
		static $done = array();
		$area = (string) $index;
		if ( is_admin() || ! $has_widgets || Site_Footer_Block::is_rendering() || isset( $done[ $area ] ) || ! in_array( $area, Footer_Template::areas(), true ) ) {
			return;
		}
		$done[ $area ] = true;
		Style_Rules::attach_markup( implode( "\n", self::block_contents( $area ) ), (int) ( Footer_Template::get()['scope'] ?? 0 ) );
	}
}

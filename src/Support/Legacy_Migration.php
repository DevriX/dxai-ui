<?php
/**
 * Moves a site built with the plugin under its old "dxai" names onto the current "dx-ui" / "dxui" names.
 *
 * @package DX_UI
 */

declare(strict_types=1);

namespace DX_UI\Support;

/**
 * Before 0.5 the plugin's folder, classes, blocks, options, meta keys, CSS classes, uploads folder and page
 * slugs used the prefix "dxai". The plugin now lives in plugins/dx-ui/, and a site that was built with the
 * old one is moved over by the activation of the new one (and, for a site of a network that was not reached
 * then, by its first request):
 *
 *   - the old plugin is deactivated wherever it was active and the new one activated in its place;
 *   - the old must-use loader (and the copy uninstall.php leaves) is removed, so the old code never boots again;
 *   - every stored name and value is renamed with the same rule the source was renamed with (rename()):
 *     options, post/user/term/site meta, posts (content, slugs, post types), terms, cron events;
 *   - uploads/dxai-ui/ (the pages' stylesheets, scripts, fonts) is copied to uploads/dx-ui/ with the same
 *     names inside, as are the attachment files whose names carried the prefix;
 *   - the old plugin folder and the old uploads are kept for GRACE (pages a page cache still serves point at
 *     them; the old plugin is hidden from the Plugins screen meanwhile), then removed by cron;
 *   - a request for a page's old address (a slug that carried the prefix) is redirected to its new one.
 *
 * The legacy names below are spelled out on purpose: this class is the only place they still exist.
 */
final class Legacy_Migration {

	/** Per site and network-wide: 'done' or 'none', and whether one more sweep of the options is due. */
	public const OPTION = 'dx_ui_legacy_migration';

	/** Network-wide: what is removed when the grace period ends, and when. */
	public const CLEANUP_OPTION = 'dx_ui_legacy_cleanup';

	public const CLEANUP_HOOK = 'dx_ui_legacy_cleanup';

	public const GRACE = 7 * DAY_IN_SECONDS;

	public const OLD_PLUGIN = 'dxai-ui/dxai-ui.php';

	private const OLD_UPLOADS = 'dxai-ui';

	private const OLD_LOADER = 'dxai-ui-runtime.php';

	private const OLD_BUNDLE = 'dxai-ui-runtime';

	/** wp-config.php constants a site may define under the old names. */
	private const OLD_CONSTANTS = array(
		'DXAI_UI_SECRETS_KEY'     => 'DX_UI_SECRETS_KEY',
		'DXAI_UI_DISABLE_RUNTIME' => 'DX_UI_DISABLE_RUNTIME',
	);

	/** Options of WordPress itself that are handled on their own or never touched. */
	private const SKIP_OPTIONS = array( 'active_plugins', 'recently_activated', 'uninstall_plugins', 'cron', self::OPTION );

	/**
	 * A block's style class is the hash of its CSS (Style_Hoister::css_class()), and that CSS names the design's
	 * custom properties (`--dxai-brand`): once they are renamed the class is another one. Old class => new class,
	 * for the site being moved, collected from the stored blocks before anything is renamed.
	 *
	 * @var array<string, string>
	 */
	private static array $classes = array();

	/** Text files in uploads/dxai-ui/ whose contents carry the names; everything else is copied byte for byte. */
	private const TEXT_FILES = array( 'css', 'js', 'mjs', 'json', 'html', 'htm', 'svg', 'txt', 'php' );

	/**
	 * The renaming rule: `dxai-ui` / `dxai_ui` / `DXAI_UI` / `dxaiUI` become `dx-ui` / `dx_ui` / `DX_UI` / `dxUI`,
	 * any other `dxai` becomes `dxui`. Only where the name stands as a name: after a character that is not a
	 * letter or digit (or after a JSON `\u00XX` escape, as block attributes store quotes and dashes), and before
	 * `-`, `_` or a capital letter. A base64 blob or a hash never matches that.
	 */
	public static function rename( string $text ): string {
		if ( self::$classes !== array() && str_contains( $text, 'dxs-' ) ) {
			$text = (string) preg_replace_callback( '/\\bdxs-[a-z0-9]+\\b/', static fn( array $m ): string => self::$classes[ $m[0] ] ?? $m[0], $text );
		}
		if ( stripos( $text, 'dxai' ) === false ) {
			return $text;
		}
		$before = '(?:(?<![A-Za-z0-9+])|(?<=\\\\u00[0-9a-fA-F]{2}))';
		$text   = (string) preg_replace_callback(
			'/' . $before . '(dxai|DXAI|Dxai)(?=[-_]?(?:ui|UI|Ui)(?![a-z]))/',
			static fn( array $m ): string => array( 'dxai' => 'dx', 'DXAI' => 'DX', 'Dxai' => 'Dx' )[ $m[1] ],
			$text
		);

		return (string) preg_replace_callback(
			'/' . $before . '(dxai|DXAI|Dxai)(?=[-_A-Z\\\\])/',
			static fn( array $m ): string => array( 'dxai' => 'dxui', 'DXAI' => 'DXUI', 'Dxai' => 'Dxui' )[ $m[1] ],
			$text
		);
	}

	/**
	 * rename() through a stored value: serialized PHP is unserialized, renamed and serialized again (string
	 * lengths change), arrays are walked. A serialized value holding objects is left as it is.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public static function rename_value( $value ) {
		if ( is_string( $value ) && stripos( $value, 'dxai' ) === false && ( self::$classes === array() || ! str_contains( $value, 'dxs-' ) ) ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ is_string( $k ) ? self::rename( $k ) : $k ] = self::rename_value( $v );
			}
			return $out;
		}
		if ( ! is_string( $value ) ) {
			return $value;
		}
		if ( is_serialized( $value ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- no classes are allowed.
			$data = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $data === false && $value !== 'b:0;' ) {
				return $value;
			}
			if ( str_contains( $value, 'O:' ) && self::has_object( $data ) ) {
				return $value;
			}
			return serialize( self::rename_value( $data ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		}

		return self::rename( $value );
	}

	/** @param mixed $data */
	private static function has_object( $data ): bool {
		if ( is_object( $data ) ) {
			return true;
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $v ) {
				if ( self::has_object( $v ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Hooks: the lazy per-site move, the cleanup, the old plugin hidden, old addresses redirected. */
	public static function register(): void {
		self::bridge_constants();
		add_action( 'plugins_loaded', array( self::class, 'maybe_migrate' ), 1 );
		add_action( 'admin_init', array( self::class, 'sweep' ) );
		add_action( self::CLEANUP_HOOK, array( self::class, 'cleanup' ) );
		add_filter( 'all_plugins', array( self::class, 'hide_old_plugin' ) );
		// Before anything that turns a 404 into a page of its own (a theme's custom 404 page runs at 0).
		add_action( 'template_redirect', array( self::class, 'redirect' ), -100 );
	}

	/** A constant wp-config.php still defines under its old name counts under the new one. */
	public static function bridge_constants(): void {
		foreach ( self::OLD_CONSTANTS as $old => $new ) {
			if ( defined( $old ) && ! defined( $new ) ) {
				define( $new, constant( $old ) );
			}
		}
	}

	/**
	 * From the activation hook, before Plugin::activate(): the whole install is moved, every site of a network
	 * that fits in the request's time; the rest move on their first request (maybe_migrate()).
	 */
	public static function activate(): void {
		if ( ! self::legacy_present() ) {
			return;
		}
		self::raise_limits();
		self::migrate_install();
		$start = microtime( true );
		if ( is_multisite() ) {
			$current = get_current_blog_id();
			self::migrate_current_site();
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				if ( (int) $site_id === $current || microtime( true ) - $start > 30 ) {
					continue;
				}
				switch_to_blog( (int) $site_id );
				self::migrate_current_site();
				restore_current_blog();
			}
		} else {
			self::migrate_current_site();
		}
	}

	/** On a request: this site's move, when it has not happened yet. One autoloaded option read otherwise. */
	public static function maybe_migrate(): void {
		if ( get_option( self::OPTION ) !== false ) {
			return;
		}
		if ( is_multisite() && get_site_option( self::OPTION ) === false && self::legacy_present() ) {
			self::raise_limits();
			self::migrate_install();
		}
		// One request at a time; another one meanwhile renders as it is.
		if ( ! add_option( 'dx_ui_legacy_migrating', time(), '', false ) ) {
			if ( time() - (int) get_option( 'dx_ui_legacy_migrating' ) < 10 * MINUTE_IN_SECONDS ) {
				return;
			}
			update_option( 'dx_ui_legacy_migrating', time(), false );
		}
		self::raise_limits();
		self::migrate_current_site();
		delete_option( 'dx_ui_legacy_migrating' );
	}

	/**
	 * The request that activated the plugin still ran the old one, which can write an option or two after the
	 * move: the first admin request afterwards renames those too. And the grace period ends here when cron
	 * does not run.
	 */
	public static function sweep(): void {
		$state = get_option( self::OPTION );
		if ( is_array( $state ) && ! empty( $state['sweep'] ) ) {
			self::migrate_options();
			$state['sweep'] = false;
			update_option( self::OPTION, $state, true );
		}
		$cleanup = get_site_option( self::CLEANUP_OPTION );
		if ( is_array( $cleanup ) && (int) ( $cleanup['at'] ?? 0 ) <= time() && current_user_can( 'activate_plugins' ) ) {
			self::cleanup();
		}
	}

	/** Whether anything of the old plugin is on this install. */
	public static function legacy_present(): bool {
		global $wpdb;
		if ( is_readable( WP_PLUGIN_DIR . '/' . self::OLD_PLUGIN ) || is_readable( wp_normalize_path( WPMU_PLUGIN_DIR ) . '/' . self::OLD_LOADER ) ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1", $wpdb->esc_like( 'dxai_' ) . '%' ) )
			|| (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key LIKE %s LIMIT 1", $wpdb->esc_like( '_dxai_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** What the install shares: the plugin's activation, the must-use loader, the network's options. */
	private static function migrate_install(): void {
		$new = plugin_basename( DX_UI_FILE );

		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', array() );
			if ( isset( $network[ self::OLD_PLUGIN ] ) ) {
				unset( $network[ self::OLD_PLUGIN ] );
				$network[ $new ] = time();
				update_site_option( 'active_sitewide_plugins', $network );
			}
			// Every site the old plugin was active on runs the new one from its next request (and moves then).
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::swap_active_plugin( $new );
				restore_current_blog();
			}
			self::migrate_meta_table( 'sitemeta', 'meta_id', array( 'active_sitewide_plugins', self::OPTION, self::CLEANUP_OPTION ) );
			update_site_option( self::OPTION, 'done' );
		} else {
			self::swap_active_plugin( $new );
		}

		self::remove_old_loader();

		// The old plugin folder stays for the grace period (a cached page may load a script from it), hidden.
		$cleanup = get_site_option( self::CLEANUP_OPTION );
		$cleanup = is_array( $cleanup ) ? $cleanup : array( 'paths' => array() );
		if ( is_dir( WP_PLUGIN_DIR . '/' . dirname( self::OLD_PLUGIN ) ) ) {
			$cleanup['paths'][] = WP_PLUGIN_DIR . '/' . dirname( self::OLD_PLUGIN );
		}
		$cleanup['at']    = time() + self::GRACE;
		$cleanup['paths'] = array_values( array_unique( $cleanup['paths'] ) );
		update_site_option( self::CLEANUP_OPTION, $cleanup );
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_single_event( $cleanup['at'] + 60, self::CLEANUP_HOOK );
		}
	}

	private static function swap_active_plugin( string $new ): void {
		$active = (array) get_option( 'active_plugins', array() );
		$index  = array_search( self::OLD_PLUGIN, $active, true );
		if ( false === $index ) {
			return;
		}
		unset( $active[ $index ] );
		if ( ! in_array( $new, $active, true ) ) {
			$active[] = $new;
		}
		sort( $active );
		update_option( 'active_plugins', array_values( $active ) );
	}

	/** The old loader would boot the old plugin from its folder (or its bundled copy) on every request. */
	private static function remove_old_loader(): void {
		$dir    = wp_normalize_path( WPMU_PLUGIN_DIR );
		$loader = $dir . '/' . self::OLD_LOADER;
		if ( is_file( $loader ) && str_contains( (string) file_get_contents( $loader, false, null, 0, 2048 ), 'Plugin Name: DX' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			wp_delete_file( $loader );
		}
		$bundle = $dir . '/' . self::OLD_BUNDLE;
		if ( is_dir( $bundle ) && ! is_link( $bundle ) && is_readable( $bundle . '/dxai-ui.php' ) ) {
			self::delete_tree( $bundle );
		}
	}

	/** One site: its database rows, its cron events, its uploads. */
	private static function migrate_current_site(): void {
		global $wpdb;
		if ( get_option( self::OPTION ) !== false ) {
			return;
		}
		if ( ! self::site_has_legacy() ) {
			update_option( self::OPTION, array( 'state' => 'none' ), true );
			return;
		}

		self::swap_active_plugin( plugin_basename( DX_UI_FILE ) );
		self::$classes = self::style_classes();
		$paths         = self::copy_uploads();
		self::migrate_options();
		self::migrate_cron();
		self::migrate_posts();
		self::migrate_meta_table( 'postmeta', 'meta_id' );
		self::migrate_meta_table( 'termmeta', 'meta_id' );
		self::migrate_terms();
		if ( ! is_multisite() || is_main_site() ) {
			self::migrate_meta_table( 'usermeta', 'umeta_id', array( 'session_tokens' ) );
		}
		self::refresh_base_theme();

		if ( $paths !== array() ) {
			$cleanup          = get_site_option( self::CLEANUP_OPTION );
			$cleanup          = is_array( $cleanup ) ? $cleanup : array( 'paths' => array(), 'at' => time() + self::GRACE );
			$cleanup['paths'] = array_values( array_unique( array_merge( (array) $cleanup['paths'], $paths ) ) );
			update_site_option( self::CLEANUP_OPTION, $cleanup );
		}

		self::$classes = array();
		wp_cache_flush();
		update_option( self::OPTION, array( 'state' => 'done', 'sweep' => true, 'time' => time() ), true );
		unset( $wpdb );
	}

	/**
	 * Old style class => the class of the same CSS once renamed: from every block's `dxaiCss` (and the inner runs'
	 * `dxaiInner`, class => declarations), in posts and block widgets, and the inner CSS older imports kept in meta.
	 *
	 * @return array<string, string>
	 */
	private static function style_classes(): array {
		global $wpdb;
		$map  = array();
		$pair = static function ( string $css, ?string $class = null ) use ( &$map ): void {
			$old = \DX_UI\Compiler\Style_Hoister::css_class( $css );
			if ( $class !== null && $class !== $old ) {
				return;
			}
			$new = \DX_UI\Compiler\Style_Hoister::css_class( self::rename( $css ) );
			if ( $new !== $old ) {
				$map[ $old ] = $new;
			}
		};
		$walk = static function ( array $blocks ) use ( &$walk, $pair ): void {
			foreach ( $blocks as $block ) {
				$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
				if ( is_string( $attrs['dxaiCss'] ?? null ) && stripos( $attrs['dxaiCss'], 'dxai' ) !== false ) {
					$pair( $attrs['dxaiCss'] );
				}
				foreach ( (array) ( $attrs['dxaiInner'] ?? array() ) as $class => $css ) {
					if ( is_string( $class ) && is_string( $css ) && stripos( $css, 'dxai' ) !== false ) {
						$pair( $css, $class );
					}
				}
				$walk( (array) ( $block['innerBlocks'] ?? array() ) );
			}
		};
		$like = '%' . $wpdb->esc_like( 'dxai' ) . '%';
		$last = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE ID > %d AND post_content LIKE %s AND post_content LIKE %s ORDER BY ID LIMIT 50", $last, '%' . $wpdb->esc_like( 'dxai' ) . 'Css%', $like ) );
			foreach ( (array) $rows as $row ) {
				$last = (int) $row->ID;
				$walk( parse_blocks( (string) $row->post_content ) );
			}
		} while ( count( (array) $rows ) === 50 );
		foreach ( (array) get_option( 'widget_block', array() ) as $widget ) {
			if ( is_array( $widget ) && is_string( $widget['content'] ?? null ) ) {
				$walk( parse_blocks( $widget['content'] ) );
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE %s AND meta_value LIKE %s", '%dxs-%', $like ) ) as $value ) {
			$data = maybe_unserialize( $value );
			if ( ! is_array( $data ) ) {
				continue;
			}
			array_walk_recursive(
				$data,
				static function ( $css, $class ) use ( $pair ): void {
					if ( is_string( $class ) && str_starts_with( $class, 'dxs-' ) && is_string( $css ) ) {
						$pair( $css, $class );
					}
				}
			);
		}

		return $map;
	}

	private static function site_has_legacy(): bool {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( 'dxai' ) . '%';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1", $like ) )
			|| (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key LIKE %s LIMIT 1", $like ) )
			|| (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->posts} WHERE post_type LIKE %s OR post_content LIKE %s LIMIT 1", $like, $like ) )
			|| is_dir( wp_get_upload_dir()['basedir'] . '/' . self::OLD_UPLOADS );
		// phpcs:enable
	}

	/** Options named with the prefix are renamed; values that carry it are rewritten; caches are dropped. */
	private static function migrate_options(): void {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( 'dxai' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_value LIKE %s", $like, $like ) );
		foreach ( (array) $rows as $row ) {
			$name = (string) $row->option_name;
			if ( in_array( $name, self::SKIP_OPTIONS, true ) ) {
				continue;
			}
			if ( stripos( $name, 'dxai' ) !== false && preg_match( '/^_(site_)?transient_/', $name ) ) {
				$wpdb->delete( $wpdb->options, array( 'option_id' => $row->option_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				continue;
			}
			$new_name  = self::rename( $name );
			$new_value = self::rename_value( (string) $row->option_value );
			if ( $new_name !== $name ) {
				// A row the new plugin already wrote under the new name gives way to the site's own.
				$wpdb->delete( $wpdb->options, array( 'option_name' => $new_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			if ( $new_name !== $name || $new_value !== $row->option_value ) {
				$wpdb->update( $wpdb->options, array( 'option_name' => $new_name, 'option_value' => $new_value ), array( 'option_id' => $row->option_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/** Scheduled events of the old hooks, rescheduled under the new ones with their arguments renamed. */
	private static function migrate_cron(): void {
		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) {
			return;
		}
		$changed = false;
		foreach ( $crons as $time => $hooks ) {
			foreach ( (array) $hooks as $hook => $events ) {
				if ( stripos( (string) $hook, 'dxai' ) === false ) {
					continue;
				}
				$new_hook = self::rename( (string) $hook );
				foreach ( (array) $events as $event ) {
					$args = (array) self::rename_value( (array) ( $event['args'] ?? array() ) );
					$crons[ $time ][ $new_hook ][ md5( serialize( $args ) ) ] = array_merge( $event, array( 'args' => $args ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
				}
				unset( $crons[ $time ][ $hook ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			_set_cron_array( $crons );
		}
	}

	/** Posts: content, title, excerpt, slug, type, guid. In batches: a conversion record holds megabytes. */
	private static function migrate_posts(): void {
		global $wpdb;
		$like    = '%' . $wpdb->esc_like( 'dxai' ) . '%';
		$columns = array( 'post_content', 'post_title', 'post_excerpt', 'post_name', 'post_type', 'guid', 'post_content_filtered' );
		$last    = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND ( post_content LIKE %s OR post_title LIKE %s OR post_excerpt LIKE %s OR post_name LIKE %s OR post_type LIKE %s OR guid LIKE %s OR post_content_filtered LIKE %s ) ORDER BY ID LIMIT 50", $last, $like, $like, $like, $like, $like, $like, $like ) );
			foreach ( $ids as $id ) {
				$last = (int) $id;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_content, post_title, post_excerpt, post_name, post_type, guid, post_content_filtered FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );
				if ( ! is_array( $row ) ) {
					continue;
				}
				$set = array();
				foreach ( $columns as $column ) {
					$value = self::rename( (string) $row[ $column ] );
					if ( $value !== $row[ $column ] ) {
						$set[ $column ] = $value;
					}
				}
				if ( isset( $set['post_name'] ) && in_array( $row['post_type'], array( 'post', 'page' ), true ) ) {
					add_post_meta( (int) $id, '_wp_old_slug', $row['post_name'] );
				}
				if ( $set !== array() ) {
					$wpdb->update( $wpdb->posts, $set, array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					clean_post_cache( (int) $id );
				}
			}
		} while ( count( $ids ) === 50 );
	}

	/**
	 * A meta table (or the network's sitemeta): keys and values renamed, row by row.
	 *
	 * @param string[] $skip Keys left as they are.
	 */
	private static function migrate_meta_table( string $table, string $id_column, array $skip = array() ): void {
		global $wpdb;
		$name = $wpdb->$table;
		$like = '%' . $wpdb->esc_like( 'dxai' ) . '%';
		$last = 0;
		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$id_column} AS id, meta_key, meta_value FROM {$name} WHERE {$id_column} > %d AND ( meta_key LIKE %s OR meta_value LIKE %s ) ORDER BY {$id_column} LIMIT 200", $last, $like, $like ) );
			foreach ( (array) $rows as $row ) {
				$last = (int) $row->id;
				$key  = (string) $row->meta_key;
				if ( in_array( $key, $skip, true ) ) {
					continue;
				}
				if ( $table === 'sitemeta' && stripos( $key, 'dxai' ) !== false && str_starts_with( $key, '_site_transient_' ) ) {
					$wpdb->delete( $name, array( $id_column => $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					continue;
				}
				$new_key   = self::rename( $key );
				$new_value = self::rename_value( (string) $row->meta_value );
				if ( $new_key !== $key || $new_value !== $row->meta_value ) {
					$wpdb->update( $name, array( 'meta_key' => $new_key, 'meta_value' => $new_value ), array( $id_column => $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
			}
		} while ( count( (array) $rows ) === 200 );
	}

	private static function migrate_terms(): void {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( 'dxai' ) . '%';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT term_id, name, slug FROM {$wpdb->terms} WHERE name LIKE %s OR slug LIKE %s", $like, $like ) ) as $term ) {
			$wpdb->update( $wpdb->terms, array( 'name' => self::rename( (string) $term->name ), 'slug' => self::rename( (string) $term->slug ) ), array( 'term_id' => $term->term_id ) );
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT term_taxonomy_id, taxonomy, description FROM {$wpdb->term_taxonomy} WHERE taxonomy LIKE %s OR description LIKE %s", $like, $like ) ) as $tt ) {
			$wpdb->update( $wpdb->term_taxonomy, array( 'taxonomy' => self::rename( (string) $tt->taxonomy ), 'description' => self::rename( (string) $tt->description ) ), array( 'term_taxonomy_id' => $tt->term_taxonomy_id ) );
		}
		// phpcs:enable
	}

	/**
	 * uploads/dxai-ui/ copied to uploads/dx-ui/ (names inside the text files renamed), and the attachment files
	 * whose names carried the prefix copied under their new names, before the meta that names them is renamed.
	 *
	 * @return string[] The old paths, removed when the grace period ends.
	 */
	private static function copy_uploads(): array {
		global $wpdb;
		$base  = wp_normalize_path( wp_get_upload_dir()['basedir'] );
		$old   = $base . '/' . self::OLD_UPLOADS;
		$paths = array();
		if ( is_dir( $old ) && ! is_link( $old ) ) {
			$new   = $base . '/' . self::rename( self::OLD_UPLOADS );
			$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $old, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
			wp_mkdir_p( $new );
			foreach ( $items as $item ) {
				$relative = substr( wp_normalize_path( $item->getPathname() ), strlen( $old ) + 1 );
				$target   = $new . '/' . self::rename( $relative );
				if ( $item->isDir() ) {
					wp_mkdir_p( $target );
					continue;
				}
				if ( file_exists( $target ) ) {
					continue;
				}
				if ( in_array( strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ), self::TEXT_FILES, true ) ) {
					file_put_contents( $target, self::rename( (string) file_get_contents( $item->getPathname() ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				} else {
					copy( $item->getPathname(), $target );
				}
			}
			$paths[] = $old;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$files = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s", '%' . $wpdb->esc_like( 'dxai' ) . '%' ) );
		foreach ( (array) $files as $file ) {
			$names = array( (string) $file->meta_value );
			$meta  = wp_get_attachment_metadata( (int) $file->post_id );
			$dir   = dirname( (string) $file->meta_value );
			foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$names[] = ( $dir === '.' ? '' : $dir . '/' ) . $size['file'];
				}
			}
			if ( ! empty( $meta['original_image'] ) ) {
				$names[] = ( $dir === '.' ? '' : $dir . '/' ) . $meta['original_image'];
			}
			foreach ( $names as $name ) {
				$to = self::rename( $name );
				if ( $to !== $name && is_file( $base . '/' . $name ) && ! file_exists( $base . '/' . $to ) && ! str_starts_with( $name, self::OLD_UPLOADS . '/' ) ) {
					wp_mkdir_p( dirname( $base . '/' . $to ) );
					copy( $base . '/' . $name, $base . '/' . $to );
					$paths[] = $base . '/' . $name;
				}
			}
		}

		return $paths;
	}

	/** The DX Base theme copied into the themes folder by an older version names the old classes: the new copy replaces it. */
	private static function refresh_base_theme(): void {
		if ( ! class_exists( \DX_UI\Theme\Base_Theme::class ) ) {
			return;
		}
		$installed = get_theme_root() . '/' . \DX_UI\Theme\Base_Theme::SLUG;
		if ( is_dir( $installed ) && \DX_UI\Theme\Base_Theme::is_ours( $installed ) && str_contains( (string) @file_get_contents( $installed . '/functions.php' ), 'DXAI_UI' ) ) { // phpcs:ignore
			\DX_UI\Theme\Base_Theme::copy_to( get_theme_root() );
		}
	}

	/** After the grace period: the old plugin folder and the old uploads go. */
	public static function cleanup(): void {
		$cleanup = get_site_option( self::CLEANUP_OPTION );
		if ( ! is_array( $cleanup ) || (int) ( $cleanup['at'] ?? 0 ) > time() ) {
			return;
		}
		foreach ( (array) ( $cleanup['paths'] ?? array() ) as $path ) {
			$path = wp_normalize_path( (string) $path );
			if ( ! str_starts_with( $path, wp_normalize_path( WP_CONTENT_DIR ) . '/' ) || is_link( $path ) ) {
				continue;
			}
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			} elseif ( is_dir( $path ) && ! file_exists( $path . '/.git' ) && ! is_dir( $path . '/node_modules' ) ) {
				// The plugin folder only when it is a released copy of the old plugin, never a checkout.
				if ( str_starts_with( $path, wp_normalize_path( WP_PLUGIN_DIR ) . '/' ) && ! is_readable( $path . '/dxai-ui.php' ) ) {
					continue;
				}
				self::delete_tree( $path );
			}
		}
		delete_site_option( self::CLEANUP_OPTION );
	}

	/**
	 * The old plugin is out of the Plugins screen while its folder waits for the cleanup.
	 *
	 * @param array<string, mixed> $plugins
	 * @return array<string, mixed>
	 */
	public static function hide_old_plugin( $plugins ) {
		if ( is_array( $plugins ) && is_array( get_site_option( self::CLEANUP_OPTION ) ) ) {
			unset( $plugins[ self::OLD_PLUGIN ] );
		}
		return $plugins;
	}

	/** A page whose slug carried the prefix answers at its old address with a redirect to its new one. */
	public static function redirect(): void {
		if ( ! is_404() ) {
			return;
		}
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( stripos( $path, 'dxai' ) === false ) {
			return;
		}
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$rel  = trim( substr( $path, strlen( rtrim( $home, '/' ) ) ), '/' );
		$page = get_page_by_path( self::rename( $rel ), OBJECT, array( 'page', 'post' ) );
		if ( $page instanceof \WP_Post && $page->post_status === 'publish' ) {
			wp_safe_redirect( (string) get_permalink( $page ), 301 );
			exit;
		}
	}

	private static function delete_tree( string $dir ): void {
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}
		@rmdir( $dir ); // phpcs:ignore
	}

	private static function raise_limits(): void {
		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore
		}
	}
}

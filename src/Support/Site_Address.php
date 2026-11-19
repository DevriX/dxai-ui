<?php
/**
 * After a site moves to another address, the plugin's files and stored styles name its own files at the new one.
 *
 * @package DX_UI
 */

declare(strict_types=1);

namespace DX_UI\Support;

use DX_UI\Compiler\Style_Hoister;

/**
 * An import writes the addresses of the site's own files into what it makes: a design's stylesheet in uploads
 * (`dx-ui/pattern-N.css`: its fonts, its background pictures), a block's own CSS, the header's and footer's markup. They are
 * written with the address the site had then. A site built on a staging install and then given the client's domain kept them
 * there: geminirestoration.com loaded 91 fonts and pictures from amrdevrixmulti.wpenginepowered.com/geminirestoration — the
 * hero picture twice (preloaded from the domain, drawn from the staging copy), and every font over a second connection. Moving
 * a site rewrites its database (WP Engine's tool, `wp search-replace`); the files in uploads it never reads.
 *
 * So the first request after the site's address changed (and the first after this version arrives) brings them along: every
 * absolute address of a file under wp-content that exists on this server, written with another origin or path before
 * `/wp-content/`, becomes the address it has now. In the plugin's files in uploads, its options and post meta, and the
 * content of posts. A block whose own CSS changes that way gets the class of its new CSS (Style_Hoister::css_class() hashes the
 * text), and so does every other place that names that class. An address of a file this server does not have (another site's
 * picture) is left as it is. One autoloaded option read on every other request; `wp dx-ui site-address` runs it at once.
 */
final class Site_Address {

	/** The content URL (wp-content) the stored addresses were last brought to. */
	public const OPTION = 'dx_ui_site_address';

	private const LOCK = 'dx_ui_site_address_lock';

	/** Text files the plugin writes in uploads. */
	private const TEXT = '/\.(css|js|mjs|json|html?|svg|txt|xml)$/i';

	/** Post types whose content is never drawn or edited (revisions keep the address they were made with). */
	private const SKIP_TYPES = array( 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request' );

	/** @var array<string, string> Old address => new, of the URLs looked at on this request. */
	private static array $seen = array();

	/** @var array<string, string> Old `dxs-` class => new, of the block CSS this run changed. */
	private static array $classes = array();

	public static function register(): void {
		add_action( 'init', array( self::class, 'maybe_repair' ), 1 );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'dx-ui site-address', array( self::class, 'cli' ) );
		}
	}

	/**
	 * Where wp-content is now, without a trailing slash. A site switched to on a network (`--network`) is not the one
	 * WP_CONTENT_URL was set for: its own address is.
	 */
	public static function current(): string {
		if ( is_multisite() && ms_is_switched() ) {
			return untrailingslashit( set_url_scheme( (string) get_option( 'siteurl' ) ) ) . '/wp-content';
		}

		return untrailingslashit( content_url() );
	}

	/** On a request: nothing when the site has not moved since the last run. */
	public static function maybe_repair(): void {
		if ( ( function_exists( 'wp_installing' ) && wp_installing() ) || (string) get_option( self::OPTION ) === self::current() ) {
			return;
		}
		/*
		 * Only where the address is the site's own: a wp-config.php that makes it up from the request's host (one install
		 * answering on two names) would turn every request on the other name into a move. The site's address as the database
		 * holds it must be the host the files are served from; `wp dx-ui site-address` runs regardless.
		 */
		global $wpdb;
		$stored = (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'siteurl'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( strtolower( (string) wp_parse_url( $stored, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( self::current(), PHP_URL_HOST ) ) ) {
			return;
		}
		// One request at a time; another one meanwhile draws the page as it is.
		if ( ! add_option( self::LOCK, (string) time(), '', false ) ) {
			if ( time() - (int) get_option( self::LOCK ) < 5 * MINUTE_IN_SECONDS ) {
				return;
			}
			update_option( self::LOCK, (string) time(), false );
		}
		try {
			self::repair();
			update_option( self::OPTION, self::current(), true );
		} finally {
			delete_option( self::LOCK );
		}
	}

	/**
	 * Bring every stored address of this site's own files to where they are now.
	 *
	 * @return array{posts:int, meta:int, options:int, files:int}
	 */
	public static function repair(): array {
		self::$seen    = array();
		self::$classes = array();
		$done          = array(
			'posts'   => self::posts(),
			'meta'    => 0,
			'options' => 0,
			'files'   => 0,
		);
		// After the posts: what they renamed (a block's class) is renamed where options and meta name it too.
		$done['meta']    = self::meta();
		$done['options'] = self::options();
		$done['files']   = self::files();
		wp_cache_flush();

		return $done;
	}

	/**
	 * `wp dx-ui site-address [--network]` — bring the stored addresses of this site (every site with --network) to where the
	 * site is now.
	 *
	 * @param list<string>          $args  None.
	 * @param array<string, string> $assoc Flags.
	 */
	public static function cli( array $args, array $assoc ): void {
		$sites = is_multisite() && isset( $assoc['network'] ) ? get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) : array( get_current_blog_id() );
		foreach ( $sites as $site ) {
			$switched = is_multisite() && (int) $site !== get_current_blog_id();
			if ( $switched ) {
				switch_to_blog( (int) $site );
			}
			$done = self::repair();
			update_option( self::OPTION, self::current(), true );
			\WP_CLI::log( sprintf( 'site %d (%s): %d posts, %d meta, %d options, %d files', $site, self::current(), $done['posts'], $done['meta'], $done['options'], $done['files'] ) );
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * The text with every absolute address of a file of this site's wp-content at its current address. The JSON form
	 * (`https:\/\/…`) is kept JSON.
	 */
	public static function rewrite( string $text ): string {
		if ( ! str_contains( $text, 'wp-content' ) ) {
			return $text;
		}

		return (string) preg_replace_callback(
			'#(?:https?:)?(?:\\\\?/){2}[a-z0-9.\-]+(?::\d+)?(?:\\\\?/[A-Za-z0-9._~%!$*+,;=:@\-]*)*?\\\\?/wp-content((?:\\\\?/[A-Za-z0-9._~%!$*+,;=:@\-]+)+)#i',
			static function ( array $m ): string {
				return self::address( $m[0], $m[1] );
			},
			$text
		);
	}

	/**
	 * One address: where its file is now, or itself.
	 *
	 * @param string $raw  The address as written.
	 * @param string $tail What follows `/wp-content`, as written.
	 */
	private static function address( string $raw, string $tail ): string {
		if ( isset( self::$seen[ $raw ] ) ) {
			return self::$seen[ $raw ];
		}
		$escaped = str_contains( $raw, '\\/' );
		$url     = str_replace( '\\/', '/', $raw );
		$rest    = ltrim( str_replace( '\\/', '/', $tail ), '/' );
		$now     = self::current() . '/' . $rest;
		$out     = $raw;
		if ( $url !== $now && $rest !== '' && ! str_contains( $rest, '..' ) && is_file( wp_normalize_path( WP_CONTENT_DIR ) . '/' . rawurldecode( $rest ) ) ) {
			$out = $escaped ? str_replace( '/', '\\/', $now ) : $now;
		}
		self::$seen[ $raw ] = $out;

		return $out;
	}

	/**
	 * A text with its addresses brought along, and the classes of the block CSS that changed with them.
	 */
	private static function text( string $text ): string {
		$out = self::rewrite( $text );
		if ( $out !== $text && str_contains( $text, '"' . Style_Hoister::ATTR . '"' ) ) {
			self::$classes = self::css_classes( $text ) + self::$classes;
		}
		if ( self::$classes !== array() && str_contains( $out, 'dxs-' ) ) {
			$classes = self::$classes;
			$out     = (string) preg_replace_callback(
				'/(?<![A-Za-z0-9_-])dxs-[0-9a-z]+(?![A-Za-z0-9_-])/',
				static fn( array $m ): string => $classes[ $m[0] ] ?? $m[0],
				$out
			);
		}

		return $out;
	}

	/**
	 * Old class => new, for every block CSS in a text that rewrite() changes: the class is the hash of that CSS.
	 *
	 * @return array<string, string>
	 */
	private static function css_classes( string $text ): array {
		$map = array();
		if ( preg_match_all( '/"' . Style_Hoister::ATTR . '"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $text, $m ) ) {
			foreach ( $m[1] as $raw ) {
				$css = json_decode( '"' . $raw . '"' );
				if ( ! is_string( $css ) || $css === '' ) {
					continue;
				}
				$new = self::rewrite( $css );
				if ( $new !== $css ) {
					$map[ Style_Hoister::css_class( $css ) ] = Style_Hoister::css_class( $new );
				}
			}
		}

		return $map;
	}

	/**
	 * A stored value: a string, a serialized value (serialized again, so its lengths follow) or an array.
	 *
	 * @param mixed $value The value.
	 * @return mixed
	 */
	private static function value( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'value' ), $value );
		}
		if ( ! is_string( $value ) ) {
			return $value;
		}
		if ( is_serialized( $value ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- this site's own row; objects are refused.
			$inner = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ( $inner === false && $value !== 'b:0;' ) || is_object( $inner ) ) {
				return $value;
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			return serialize( self::value( $inner ) );
		}

		return self::text( $value );
	}

	/** Posts: content and excerpt, row by row (the classes their block CSS gets are what meta and options follow). */
	private static function posts(): int {
		global $wpdb;
		$skip = "'" . implode( "','", array_map( 'esc_sql', self::SKIP_TYPES ) ) . "'";
		$like = '%' . $wpdb->esc_like( '/wp-content/' ) . '%';
		$n    = 0;
		$last = 0;
		$hash = array();
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a fixed list.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content, post_excerpt FROM {$wpdb->posts} WHERE ID > %d AND post_type NOT IN ({$skip}) AND ( post_content LIKE %s OR post_excerpt LIKE %s ) ORDER BY ID LIMIT 50", $last, $like, $like ), ARRAY_A );
			foreach ( $rows as $row ) {
				$last    = (int) $row['ID'];
				$content = (string) $row['post_content'];
				$excerpt = (string) $row['post_excerpt'];
				$c2      = self::text( $content );
				$e2      = self::text( $excerpt );
				if ( $c2 === $content && $e2 === $excerpt ) {
					continue;
				}
				$hash[ md5( $content ) ] = md5( $c2 );
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->posts,
					array(
						'post_content' => $c2,
						'post_excerpt' => $e2,
					),
					array( 'ID' => $last )
				);
				clean_post_cache( $last );
				++$n;
			}
		} while ( count( $rows ) === 50 );
		// A page's content was renamed: the md5 the plugin keeps of it follows (else it is taken for edited by hand).
		foreach ( $hash as $old => $new ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_query_meta_value, WordPress.DB.SlowDBQuery.slow_query_meta_key
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_key LIKE %s AND meta_value = %s", $new, $wpdb->esc_like( '_dx_ui_' ) . '%', $old ) );
		}

		return $n;
	}

	/** The plugin's post meta: stored CSS, markup, records of where a design's files are. */
	private static function meta(): int {
		global $wpdb;
		$n    = 0;
		$last = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_id > %d AND meta_key LIKE %s AND ( meta_value LIKE %s OR meta_value LIKE %s ) ORDER BY meta_id LIMIT 100", $last, $wpdb->esc_like( '_dx_ui_' ) . '%', '%wp-content%', self::$classes === array() ? '%wp-content%' : '%dxs-%' ), ARRAY_A );
			foreach ( $rows as $row ) {
				$last  = (int) $row['meta_id'];
				$value = (string) $row['meta_value'];
				$fresh = self::value( $value );
				if ( $fresh !== $value ) {
					$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $fresh ), array( 'meta_id' => $last ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_query_meta_value
					wp_cache_delete( (int) $row['post_id'], 'post_meta' );
					++$n;
				}
			}
		} while ( count( $rows ) === 100 );

		return $n;
	}

	/** The plugin's options, and the block widgets (a footer's markup). */
	private static function options(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE ( option_name LIKE %s OR option_name = 'widget_block' ) AND option_name NOT IN (%s, %s) AND ( option_value LIKE %s OR option_value LIKE %s )", $wpdb->esc_like( 'dx_ui_' ) . '%', self::OPTION, self::LOCK, '%wp-content%', self::$classes === array() ? '%wp-content%' : '%dxs-%' ), ARRAY_A );
		$n    = 0;
		foreach ( $rows as $row ) {
			$value = (string) $row['option_value'];
			$fresh = self::value( $value );
			if ( $fresh !== $value ) {
				$wpdb->update( $wpdb->options, array( 'option_value' => $fresh ), array( 'option_id' => (int) $row['option_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				++$n;
			}
		}

		return $n;
	}

	/** The plugin's folder in uploads: every text file, rewritten in place. */
	private static function files(): int {
		$up   = wp_upload_dir( null, false );
		$base = empty( $up['basedir'] ) ? '' : untrailingslashit( wp_normalize_path( (string) $up['basedir'] ) ) . '/dx-ui';
		if ( $base === '' || ! is_dir( $base ) || is_link( $base ) ) {
			return 0;
		}
		$n = 0;
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ) ) as $item ) {
			if ( $item->isLink() || ! $item->isFile() || preg_match( self::TEXT, $item->getFilename() ) !== 1 ) {
				continue;
			}
			$text = (string) file_get_contents( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$new  = self::text( $text );
			if ( $new !== $text && false !== file_put_contents( $item->getPathname(), $new ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				++$n;
			}
		}

		return $n;
	}
}

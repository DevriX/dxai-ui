<?php
/**
 * The theme's stylesheets stop at the design's sections on an ordinary page.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Theme;

use DXAI_UI\Structures\Design_Attach;
use DXAI_UI\Support\Css_Fence;
use DXAI_UI\Support\Upload_Paths;

/**
 * A page of the theme that holds sections copied from a design page
 * (Design_Attach) shows the theme around them — header, footer, the page's
 * other blocks — so the theme's CSS has to stay. On the design's own pages it
 * is not there at all (Assets::drop_theme_styles(), Theme_Compat), and inside
 * the copied sections it restyled the design: american-restoration sets every
 * heading's line-height with !important (a 46px FAQ title became 55px), its
 * heading font replaced the design's, its link weight made card text bold, and
 * its theme.json block gap put 20px between the FAQ rows.
 *
 * So on such a page each of the theme's stylesheets is served fenced
 * (Css_Fence): the same rules, none of them reaching inside `.dxai-ui`. Which
 * sheets are the theme's is recorded, not guessed: every style the theme's own
 * callbacks register or enqueue, and every inline block they add to another
 * handle (a DevriX theme prints its whole stylesheet inline, under a handle
 * with no file), plus any sheet served from the theme's directory, theme.json's
 * `global-styles` and core's `classic-theme-styles` — what the design page goes
 * without. A file is fenced once into uploads and served from there; a large
 * inline block is fenced once and read back. Anything that cannot be fenced is
 * left as it was, so the page is never worse than without this.
 *
 * A copy looks like the page it was copied from. On a design page of the blank canvas the theme's CSS is not there
 * at all, so the copies keep it off (as above). When the design's own pages are pages of the theme — its Home
 * is on the theme's template, printed with the theme's stylesheet as any page of it — its sections were built
 * and checked with that stylesheet on them, the native blocks the team adds among them (the theme's FAQ, blocks
 * with a flex or constrained layout) depend on it, and a copy is left unfenced: the same CSS, the same result,
 * pixel for pixel (fences() decides by the template of the design's source page).
 *
 * The custom field `_dxai_ui_fence_theme` (1 or 0) of a page, or the filter `dxai_ui_fence_theme` (bool, post id), sets it for a page.
 */
final class Theme_Fence {

	/** Where theme callbacks run that register, enqueue or print styles. */
	private const HOOKS = array( 'wp_enqueue_scripts', 'enqueue_block_assets', 'wp_head', 'wp_print_styles', 'wp_footer', 'wp_print_footer_scripts' );

	/** Always the theme's: theme.json's styles, and core's defaults for a classic theme. */
	private const THEME_HANDLES = array( 'global-styles', 'classic-theme-styles' );

	/** An inline block larger than this is fenced once and kept in uploads. */
	private const CACHE_OVER = 8192;

	private static bool $on = false;

	/** @var array<string, true> Handles the theme registered or enqueued. */
	private static array $owned = array();

	/** @var array<string, array<int, true>> Handle => the inline blocks the theme added to it. */
	private static array $chunks = array();

	/** @var array<string, array<int, true>> Handle => inline blocks already looked at. */
	private static array $seen = array();

	/** @var array<string, true> Handles whose file was already looked at. */
	private static array $moved = array();

	public function register(): void {
		// After the main query, before any theme enqueue runs (as Theme_Compat::detach_theme()).
		add_action( 'template_redirect', array( self::class, 'watch' ), 1 );
		// Just before <head>'s styles print, and before the late ones in the footer.
		add_action( 'wp_print_styles', array( self::class, 'apply' ), PHP_INT_MAX );
		add_action( 'wp_print_footer_scripts', array( self::class, 'apply' ), 9 );
	}

	/** Whether this request is an ordinary page showing a design it does not carry itself, from a design page of the blank canvas. */
	public static function applies(): bool {
		if ( ! is_singular() || Theme_Compat::is_converted_page() ) {
			return false;
		}

		return self::fences( (int) get_queried_object_id() );
	}

	/**
	 * Whether a page's copied sections are fenced off the theme's CSS: they are when the design they come from is
	 * shown without it (its source page is on the blank canvas), and are not when its source page is a page of the
	 * theme, which the copies then match.
	 */
	public static function fences( int $post_id ): bool {
		if ( $post_id < 1 || ! Design_Attach::attached( $post_id ) ) {
			return false;
		}
		// A page can say so itself (custom field _dxai_ui_fence_theme: 1 fences, 0 does not).
		$own = (string) get_post_meta( $post_id, '_dxai_ui_fence_theme', true );
		if ( $own === '1' || $own === '0' ) {
			return (bool) apply_filters( 'dxai_ui_fence_theme', $own === '1', $post_id );
		}
		$source  = Design_Attach::source_for( $post_id );
		$default = Blank_Template::is_canvas( $source );

		return (bool) apply_filters( 'dxai_ui_fence_theme', $default, $post_id );
	}

	/** Wrap each of the theme's callbacks on the style hooks, to record what it registers and adds. */
	public static function watch(): void {
		if ( ! self::applies() ) {
			return;
		}
		self::$on = true;
		$dirs     = self::theme_dirs();
		global $wp_filter;
		foreach ( self::HOOKS as $hook ) {
			if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof \WP_Hook ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $key => $callback ) {
					$file = Theme_Compat::defined_in( $callback['function'] );
					if ( $file === '' || ! self::under( $file, $dirs ) ) {
						continue;
					}
					$fn = $callback['function'];
					// Same key and priority, so a later remove_action() of the theme's callback still finds it.
					$wp_filter[ $hook ]->callbacks[ $priority ][ $key ]['function'] = static function ( ...$args ) use ( $fn ) {
						$before = self::snapshot();
						$result = call_user_func_array( $fn, $args );
						self::note( $before );

						return $result;
					};
				}
			}
		}
	}

	/** Fence the theme's styles that are about to print. */
	public static function apply(): void {
		if ( ! self::$on ) {
			return;
		}
		$styles = wp_styles();
		$roots  = array_unique( array_filter( array( (string) get_stylesheet_directory_uri(), (string) get_template_directory_uri() ) ) );
		foreach ( self::needed( $styles ) as $handle ) {
			$style = $styles->registered[ $handle ] ?? null;
			if ( ! $style instanceof \_WP_Dependency || str_starts_with( $handle, 'dxai-ui' ) ) {
				continue;
			}
			$src   = is_string( $style->src ) ? $style->src : '';
			$whole = isset( self::$owned[ $handle ] ) || in_array( $handle, self::THEME_HANDLES, true ) || self::from_theme( $src, $roots );
			if ( ! $whole && ! isset( self::$chunks[ $handle ] ) ) {
				continue;
			}
			if ( $whole && $src !== '' && ! isset( self::$moved[ $handle ] ) ) {
				self::$moved[ $handle ] = true;
				$fenced                 = self::fenced_file( $src, $styles );
				if ( $fenced !== '' ) {
					$style->src = $fenced;
				}
			}
			if ( ! isset( $style->extra['after'] ) || ! is_array( $style->extra['after'] ) ) {
				continue;
			}
			foreach ( $style->extra['after'] as $i => $css ) {
				if ( isset( self::$seen[ $handle ][ $i ] ) ) {
					continue;
				}
				self::$seen[ $handle ][ $i ] = true;
				if ( $whole || isset( self::$chunks[ $handle ][ $i ] ) ) {
					$style->extra['after'][ $i ] = self::fenced_text( (string) $css );
				}
			}
		}
	}

	/**
	 * The handles that will print: the queue and everything it depends on.
	 *
	 * @return array<int, string>
	 */
	private static function needed( \WP_Styles $styles ): array {
		$out  = array();
		$todo = array_values( $styles->queue );
		while ( $todo !== array() ) {
			$handle = (string) array_pop( $todo );
			if ( isset( $out[ $handle ] ) || ! isset( $styles->registered[ $handle ] ) ) {
				continue;
			}
			$out[ $handle ] = true;
			foreach ( (array) $styles->registered[ $handle ]->deps as $dep ) {
				$todo[] = (string) $dep;
			}
		}

		return array_map( 'strval', array_keys( $out ) );
	}

	/**
	 * What the style registry holds right now: each handle's inline block count, and the queue.
	 *
	 * @return array{after: array<string, int>, queue: array<string, int>}
	 */
	private static function snapshot(): array {
		$styles = wp_styles();
		$after  = array();
		foreach ( $styles->registered as $handle => $style ) {
			$after[ (string) $handle ] = isset( $style->extra['after'] ) && is_array( $style->extra['after'] ) ? count( $style->extra['after'] ) : 0;
		}

		return array(
			'after' => $after,
			'queue' => array_flip( array_map( 'strval', $styles->queue ) ),
		);
	}

	/**
	 * What a theme callback changed since $before.
	 *
	 * @param array{after: array<string, int>, queue: array<string, int>} $before
	 */
	private static function note( array $before ): void {
		$now = self::snapshot();
		foreach ( $now['after'] as $handle => $count ) {
			if ( ! isset( $before['after'][ $handle ] ) ) {
				self::$owned[ $handle ] = true;
				continue;
			}
			for ( $i = $before['after'][ $handle ]; $i < $count; $i++ ) {
				self::$chunks[ $handle ][ $i ] = true;
			}
		}
		foreach ( array_keys( $now['queue'] ) as $handle ) {
			if ( ! isset( $before['queue'][ $handle ] ) ) {
				self::$owned[ (string) $handle ] = true;
			}
		}
	}

	/** A stylesheet file's fenced copy in uploads, made when missing; '' when the file cannot be read or written. */
	private static function fenced_file( string $src, \WP_Styles $styles ): string {
		$url  = self::absolute( $src, $styles );
		$path = self::local_path( $url );
		if ( $path === '' ) {
			return '';
		}
		$name = Upload_Paths::DIR . '/fence/' . md5( $path . '|' . filemtime( $path ) . '|' . filesize( $path ) . '|' . Css_Fence::VERSION ) . '.css';
		$out  = Upload_Paths::path( $name );
		if ( $out === '' ) {
			return '';
		}
		if ( ! is_readable( $out ) && ! self::write( $out, Css_Fence::fence( (string) file_get_contents( $path ), $url ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return '';
		}

		return Upload_Paths::url( $name );
	}

	/** An inline block fenced; a large one made once and read back from uploads. */
	private static function fenced_text( string $css ): string {
		if ( strlen( $css ) <= self::CACHE_OVER ) {
			return Css_Fence::fence( $css );
		}
		$path = Upload_Paths::path( Upload_Paths::DIR . '/fence/inline-' . md5( $css . '|' . Css_Fence::VERSION ) . '.css' );
		if ( $path !== '' && is_readable( $path ) ) {
			$cached = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( is_string( $cached ) && $cached !== '' ) {
				return $cached;
			}
		}
		$fenced = Css_Fence::fence( $css );
		if ( $path !== '' ) {
			self::write( $path, $fenced );
		}

		return $fenced;
	}

	/** Write a cache file whole or not at all; copies no longer used for a month go when a new one is made. */
	private static function write( string $path, string $css ): bool {
		$dir = dirname( $path );
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$tmp = $path . '.' . uniqid( '', true ) . '.tmp';
		if ( false === @file_put_contents( $tmp, $css ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return false;
		}
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			wp_delete_file( $tmp );
			return is_readable( $path );
		}
		foreach ( (array) glob( $dir . '/*.css' ) as $old ) {
			if ( is_string( $old ) && $old !== $path && filemtime( $old ) < time() - MONTH_IN_SECONDS ) {
				wp_delete_file( $old );
			}
		}

		return true;
	}

	/** A registered src as a full URL (core registers its own sheets site-relative). */
	private static function absolute( string $src, \WP_Styles $styles ): string {
		if ( str_starts_with( $src, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $src;
		}
		if ( str_starts_with( $src, '/' ) ) {
			return rtrim( (string) $styles->base_url, '/' ) . $src;
		}

		return $src;
	}

	/** The file a stylesheet URL is served from: a .css file under the themes, content or core directory; '' otherwise. */
	private static function local_path( string $url ): string {
		$bare = static fn( string $u ): string => (string) preg_replace( '#^[a-z]+:#i', '', (string) preg_replace( '#[?\#].*$#', '', $u ) );
		$url  = $bare( $url );
		if ( ! str_ends_with( strtolower( $url ), '.css' ) ) {
			return '';
		}
		$pairs = array(
			array( get_theme_root_uri(), get_theme_root() ),
			array( content_url(), WP_CONTENT_DIR ),
			array( includes_url(), ABSPATH . WPINC ),
			array( site_url(), ABSPATH ),
		);
		foreach ( $pairs as $pair ) {
			$base = untrailingslashit( $bare( (string) $pair[0] ) );
			if ( $base === '' || ! str_starts_with( $url, $base . '/' ) ) {
				continue;
			}
			$root = realpath( (string) $pair[1] );
			$file = realpath( (string) $pair[1] . substr( $url, strlen( $base ) ) );
			if ( $root === false || $file === false || ! is_readable( $file ) ) {
				return '';
			}
			$root = wp_normalize_path( $root );
			$file = wp_normalize_path( $file );

			return str_starts_with( $file, trailingslashit( $root ) ) ? $file : '';
		}

		return '';
	}

	/** @param array<int, string> $roots */
	private static function from_theme( string $src, array $roots ): bool {
		foreach ( $roots as $root ) {
			if ( $src !== '' && str_starts_with( $src, $root . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/** @return array<int, string> */
	private static function theme_dirs(): array {
		return array_unique( array( wp_normalize_path( get_stylesheet_directory() ), wp_normalize_path( get_template_directory() ) ) );
	}

	/** @param array<int, string> $dirs */
	private static function under( string $file, array $dirs ): bool {
		foreach ( $dirs as $dir ) {
			if ( str_starts_with( $file, trailingslashit( $dir ) ) ) {
				return true;
			}
		}

		return false;
	}
}

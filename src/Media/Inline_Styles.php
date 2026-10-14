<?php
/**
 * The plugin's small stylesheets written into the page, the way WordPress writes its own.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Media;

/**
 * A design's page linked four small stylesheets of the plugin in its head — the blank canvas's resets, the rules every converted page
 * shares, the page's own sheet and the theme's buttons, 10 KiB together — and each one was a request the first paint waited for: 350 ms
 * apiece in Lighthouse on H2O Away (docs/PLAN-PERFECT-PAGES.md, T5.3). WordPress writes small stylesheets into the page itself when
 * they say where their file is (`wp_maybe_inline_styles()`, smallest first, up to `styles_inline_size_limit`: 40 KB since 6.9); the
 * plugin's stylesheets now say so. Only the plugin's own (its folder, and its folder in uploads), never the theme's or another
 * plugin's; a large one stays a file the browser caches. `dxai_ui_inline_styles` turns it off.
 */
final class Inline_Styles {

	public function register(): void {
		// After every stylesheet of the head is queued (wp_enqueue_scripts runs inside wp_head at priority 1) and before WordPress decides
		// (wp_maybe_inline_styles, added after it at the same priority); again for what was queued while the page rendered.
		add_action( 'wp_enqueue_scripts', array( self::class, 'mark' ), PHP_INT_MAX );
		add_action( 'wp_footer', array( self::class, 'mark' ), 0 );
	}

	/** Give each of the plugin's queued stylesheets, and those they need, the file WordPress may write into the page. */
	public static function mark(): void {
		if ( is_admin() ) {
			return;
		}
		/**
		 * Whether the plugin's small stylesheets are written into the page (WordPress decides which, by size).
		 *
		 * @param bool $on Default true.
		 */
		if ( ! (bool) apply_filters( 'dxai_ui_inline_styles', true ) ) {
			return;
		}
		$styles = wp_styles();
		foreach ( array_values( $styles->queue ) as $handle ) {
			foreach ( self::with_deps( (string) $handle ) as $name ) {
				$path = self::path_of( $name );
				if ( $path === '' ) {
					continue;
				}
				$styles->add_data( $name, 'path', $path );
				// WordPress looks at the queue only: a sheet that is only another's dependency is queued too (it prints in the same place).
				if ( ! in_array( $name, $styles->queue, true ) && ! in_array( $name, $styles->done, true ) ) {
					wp_enqueue_style( $name );
				}
			}
		}
	}

	/**
	 * The local file of one of the plugin's stylesheets, '' for any other: under the plugin's folder or its folder in uploads, a .css
	 * file that exists.
	 */
	public static function path_of( string $handle ): string {
		$styles = wp_styles();
		$dep    = $styles->registered[ $handle ] ?? null;
		if ( ! $dep instanceof \_WP_Dependency || ! is_string( $dep->src ) || $dep->src === '' || $styles->get_data( $handle, 'path' ) ) {
			return '';
		}
		// A sheet for print or a narrow screen is not what the first paint waits for.
		if ( ! in_array( (string) ( $dep->args ?? 'all' ), array( '', 'all', 'screen' ), true ) || $styles->get_data( $handle, 'conditional' ) ) {
			return '';
		}
		$src     = (string) strtok( $dep->src, '?#' );
		$uploads = wp_upload_dir( null, false );
		$roots   = array(
			untrailingslashit( DXAI_UI_URL ) => untrailingslashit( DXAI_UI_DIR ),
			untrailingslashit( (string) $uploads['baseurl'] ) . '/dxai-ui' => untrailingslashit( (string) $uploads['basedir'] ) . '/dxai-ui',
		);
		foreach ( $roots as $url => $dir ) {
			$rest = self::under( $src, $url );
			if ( $rest === null || ! str_ends_with( strtolower( $rest ), '.css' ) || str_contains( $rest, '..' ) ) {
				continue;
			}
			$file = wp_normalize_path( $dir . $rest );
			$real = realpath( $file );
			$base = realpath( $dir );

			return $real !== false && $base !== false && str_starts_with( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $base ) ) ) && is_file( $real ) ? $real : '';
		}

		return '';
	}

	/**
	 * A handle with the handles it needs, the plugin's or not (path_of() decides), each once.
	 *
	 * @return array<int, string>
	 */
	private static function with_deps( string $handle, int $depth = 0 ): array {
		$dep = wp_styles()->registered[ $handle ] ?? null;
		if ( ! $dep instanceof \_WP_Dependency || $depth > 5 ) {
			return array();
		}
		$out = array( $handle );
		foreach ( (array) $dep->deps as $name ) {
			$out = array_merge( $out, self::with_deps( (string) $name, $depth + 1 ) );
		}

		return array_values( array_unique( $out ) );
	}

	/** The part of $src after $base (its scheme ignored: a site moved to https still holds the files), or null when it is not under it. */
	private static function under( string $src, string $base ): ?string {
		$strip = static fn( string $u ): string => (string) preg_replace( '#^(?:https?:)?//#i', '//', $u );
		$src   = $strip( $src );
		$base  = $strip( $base );

		return str_starts_with( $src, $base . '/' ) ? substr( $src, strlen( $base ) ) : null;
	}
}

<?php
/**
 * DX Base: the theme the plugin brings for a site that has no DX theme of its own.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Theme;

/**
 * A DX site has one structure: the header is Appearance > Menus, the footer is Appearance > Widgets, drawn in the design the site was
 * imported from, on every page. American Restoration is the theme the team builds that on; a site without it gets DX Base, a small
 * classic theme the plugin carries (`themes/dx-base`) with the same locations, the same areas and header.php and footer.php that draw
 * the design's header and footer (Template_Chrome). The design's own pages use the plugin's DX template on any theme.
 *
 * Installed in two ways, in this order:
 *  - copied into `wp-content/themes/dx-base`, where WordPress lists it like any theme and it stands without the plugin's folder;
 *  - when the themes folder cannot be written (no filesystem access, a locked-down host), registered in place: the plugin's own
 *    `themes` folder is added as a theme directory, so the theme is listed and can be activated, and depends on the plugin being active.
 *
 * Nothing here activates the theme or removes it: switching the site's theme is the person's choice (activate()), and the plugin never
 * deletes a theme, a page, a menu or a file. A folder called `dx-base` that holds another theme is somebody's: nothing is copied into it
 * (fillable()), and the theme is not listed beside it.
 */
final class Base_Theme {

	public const SLUG = 'dx-base';

	/** What is recorded of an installation: `mode` (copied | registered) and the version it was made from. */
	public const OPTION = 'dxai_ui_base_theme';

	public function register(): void {
		// A theme directory has to be registered on every request, before the themes are looked up.
		if ( self::recorded()['mode'] === 'registered' ) {
			self::register_directory();
		}
	}

	/** The theme as the plugin carries it. */
	public static function source(): string {
		return DXAI_UI_DIR . 'themes/' . self::SLUG;
	}

	/** The folder that holds the theme's folder, as a theme directory. */
	public static function directory(): string {
		return DXAI_UI_DIR . 'themes';
	}

	/** The version of the theme the plugin carries, read from its style.css. */
	public static function bundled_version(): string {
		$css = self::source() . '/style.css';
		if ( ! is_readable( $css ) ) {
			return '';
		}
		$head = (string) file_get_contents( $css, false, null, 0, 4096 );

		return preg_match( '/^[ \t\/*#@]*Version:\s*(.+)$/mi', $head, $m ) === 1 ? trim( $m[1] ) : '';
	}

	/**
	 * Where the theme stands on this site.
	 *
	 * @return array{bundled:string, installed:bool, foreign:bool, version:string, mode:string, active:bool, writable:bool, needs_plugin:bool}
	 */
	public static function status(): array {
		$theme = wp_get_theme( self::SLUG );
		$rec   = self::recorded();

		return array(
			'bundled'      => self::bundled_version(),
			'installed'    => $theme->exists(),
			// A theme with this folder name that is not DX Base: somebody's, and not touched.
			'foreign'      => $theme->exists() && ! self::is_ours( (string) $theme->get_stylesheet_directory() ),
			'version'      => $theme->exists() ? (string) $theme->get( 'Version' ) : '',
			'mode'         => $theme->exists() ? $rec['mode'] : '',
			'active'       => get_stylesheet() === self::SLUG || get_template() === self::SLUG,
			'writable'     => self::writable( get_theme_root() ),
			'needs_plugin' => $rec['mode'] === 'registered',
		);
	}

	/**
	 * What the import screen says of DX Base to a site: '' when the theme is a DX theme already (or the plugin carries none), else
	 * `installed` (ready to switch to) or `available` (can be installed).
	 */
	public static function offer(): string {
		return self::offer_for( Theme_Compat::is_dx_theme(), self::bundled_version() !== '', wp_get_theme( self::SLUG )->exists() );
	}

	/** offer(), for the three facts it is made of. */
	public static function offer_for( bool $dx_theme, bool $carried, bool $installed ): string {
		if ( $dx_theme || ! $carried ) {
			return '';
		}

		return $installed ? 'installed' : 'available';
	}

	/**
	 * Make the theme available: copied into the themes folder, else registered in place. Idempotent; a copy of an older version is
	 * brought up to date.
	 *
	 * @return array{mode:string, version:string, path:string}|\WP_Error
	 */
	public static function install() {
		if ( ! is_readable( self::source() . '/style.css' ) ) {
			return new \WP_Error( 'dxai_ui_base_theme_source', __( 'The plugin does not carry the DX Base theme.', 'dxai-ui' ) );
		}
		$root   = get_theme_root();
		$target = trailingslashit( $root ) . self::SLUG;
		if ( ! self::fillable( $target ) ) {
			return new \WP_Error(
				'dxai_ui_base_theme_taken',
				/* translators: %s: the folder name. */
				sprintf( __( 'The themes folder already has a folder named %s that is not DX Base, so it was not touched.', 'dxai-ui' ), self::SLUG )
			);
		}
		$copied = self::copy_to( $root );
		if ( ! $copied && self::writable( $root ) ) {
			// The folder can be written and the copy stopped anyway (a file in the way, a full disk): say so, do not list the theme from
			// the plugin beside a half copy. style.css went first, so the next install finishes it.
			return new \WP_Error(
				'dxai_ui_base_theme_copy',
				/* translators: %s: the theme's folder. */
				sprintf( __( 'The DX Base theme could not be copied completely into %s. Check that the folder and the files in it can be written and install again: the copy is finished, nothing is lost.', 'dxai-ui' ), $target )
			);
		}
		if ( true === $copied ) {
			update_option(
				self::OPTION,
				array(
					'mode'      => 'copied',
					'version'   => self::bundled_version(),
					'installed' => time(),
				),
				false
			);
			wp_clean_themes_cache();

			return array(
				'mode'    => 'copied',
				'version' => self::bundled_version(),
				'path'    => $target,
			);
		}
		// The themes folder cannot be written: the theme stays in the plugin and is listed from there.
		update_option(
			self::OPTION,
			array(
				'mode'      => 'registered',
				'version'   => self::bundled_version(),
				'installed' => time(),
			),
			false
		);
		self::register_directory();
		wp_clean_themes_cache();
		if ( ! wp_get_theme( self::SLUG )->exists() ) {
			return new \WP_Error( 'dxai_ui_base_theme_install', __( 'The DX Base theme could not be installed: the themes folder cannot be written and the plugin\'s own folder cannot be registered.', 'dxai-ui' ) );
		}

		return array(
			'mode'    => 'registered',
			'version' => self::bundled_version(),
			'path'    => self::source(),
		);
	}

	/**
	 * Copy the theme into a themes folder. True when every file is there; false when the folder cannot be written. Public so the copy can be
	 * proved on a folder of its own.
	 */
	public static function copy_to( string $root ): bool {
		$root = untrailingslashit( wp_normalize_path( $root ) );
		if ( ! self::writable( $root ) ) {
			return false;
		}
		$dest = $root . '/' . self::SLUG;
		if ( ! self::fillable( $dest ) ) {
			return false;
		}
		if ( ! wp_mkdir_p( $dest ) ) {
			return false;
		}
		$source = self::source();
		$norm   = wp_normalize_path( $source );
		$items  = array();
		$files  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $source, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $files as $file ) {
			$items[] = ltrim( substr( wp_normalize_path( $file->getPathname() ), strlen( $norm ) ), '/' );
		}
		foreach ( self::ordered( $items ) as $relative ) {
			$from = $source . '/' . $relative;
			$to   = $dest . '/' . $relative;
			if ( is_dir( $from ) ) {
				if ( ! wp_mkdir_p( $to ) ) {
					return false;
				}
				continue;
			}
			// copy() reports a failure as false; a file already there is replaced (an update of the same theme).
			if ( ! @copy( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failure is the answer, and install() tells the person.
				return false;
			}
		}

		return true;
	}

	/**
	 * The order the files are copied in: style.css first — from that file on the folder is recognisably DX Base's (is_ours()), so a copy
	 * that stops halfway is finished by the next one instead of being taken for somebody's theme — then the rest by path, a folder before
	 * what is in it.
	 *
	 * @param array<int, string> $paths Paths relative to the theme's folder.
	 * @return array<int, string>
	 */
	public static function ordered( array $paths ): array {
		usort(
			$paths,
			static fn( string $a, string $b ): int => ( 'style.css' === $b ) <=> ( 'style.css' === $a ) ?: strcmp( $a, $b )
		);

		return array_values( $paths );
	}

	/**
	 * Whether a folder holds DX Base: the theme header of its style.css names it. A folder called `dx-base` that holds another theme
	 * is somebody's.
	 */
	public static function is_ours( string $dir ): bool {
		$css = trailingslashit( $dir ) . 'style.css';
		if ( ! is_readable( $css ) ) {
			return false;
		}
		$head = (string) file_get_contents( $css, false, null, 0, 4096 );

		return 1 === preg_match( '/^[ \t\/*#@]*Theme Name:\s*DX Base\s*$/mi', $head ) && 1 === preg_match( '/^[ \t\/*#@]*Text Domain:\s*dx-base\s*$/mi', $head );
	}

	/** Whether the theme may be copied to this path: nothing is there, an empty folder is, or DX Base is (an update). */
	private static function fillable( string $dir ): bool {
		if ( ! file_exists( $dir ) ) {
			return true;
		}
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		$names = scandir( $dir );

		return ( is_array( $names ) && count( $names ) <= 2 ) || self::is_ours( $dir );
	}

	/**
	 * Switch the site to the theme. Only when it is installed; the person asked for it.
	 *
	 * @return true|\WP_Error
	 */
	public static function activate() {
		if ( ! wp_get_theme( self::SLUG )->exists() ) {
			return new \WP_Error( 'dxai_ui_base_theme_missing', __( 'Install the DX Base theme first.', 'dxai-ui' ) );
		}
		switch_theme( self::SLUG );

		return true;
	}

	/** @return array{mode:string, version:string, installed:int} */
	private static function recorded(): array {
		$rec = get_option( self::OPTION );
		$rec = is_array( $rec ) ? $rec : array();

		return array(
			'mode'      => in_array( ( $rec['mode'] ?? '' ), array( 'copied', 'registered' ), true ) ? (string) $rec['mode'] : '',
			'version'   => (string) ( $rec['version'] ?? '' ),
			'installed' => (int) ( $rec['installed'] ?? 0 ),
		);
	}

	private static function register_directory(): void {
		if ( is_dir( self::directory() ) ) {
			register_theme_directory( self::directory() );
		}
	}

	/**
	 * Whether a folder can be written, the way the filesystem API would find it, and the site lets plugins change its files: with
	 * DISALLOW_FILE_MODS (or the `file_mod_allowed` filter) the theme is not copied, it is listed from the plugin's folder instead.
	 */
	private static function writable( string $dir ): bool {
		return is_dir( $dir ) && wp_is_writable( $dir ) && wp_is_file_mod_allowed( 'dxai_ui_base_theme' );
	}
}

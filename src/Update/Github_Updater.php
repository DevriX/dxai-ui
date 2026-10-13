<?php
/**
 * Updates from the plugin's GitHub releases.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Update;

use DXAI_UI\Http\Remote_Client;

/**
 * Dashboard › Updates offers a new DX the way it offers a plugin from wordpress.org, from the releases of the plugin's GitHub
 * repository (bin/release.cjs builds the ZIP, .github/workflows/release.yml attaches it to the release of its tag).
 *
 * The plugin's header says `Update URI: https://github.com/DevriX/dxai-ui`, so WordPress does not look for it on wordpress.org
 * (where another plugin could have the same folder name) and asks `update_plugins_github.com` instead; WordPress compares the
 * versions itself. A release counts when it is published (not a draft), its tag is a version (v0.4.0-beta.33) and it carries the
 * ZIP `dxai-ui-<version>.zip`. A beta — a version with a "-", or a GitHub pre-release — is offered on the beta channel only: the
 * channel of the version running, unless `dxai_ui_update_channel` says otherwise. The releases are read at most once in six hours
 * (once an hour after a failure), in a site transient; "View details" shows the release's notes (plugins_api).
 */
final class Github_Updater {

	/** The repository the plugin's header names. */
	public const REPOSITORY = 'DevriX/dxai-ui';

	/** The plugin's folder, its slug in the updates screens. */
	public const SLUG = 'dxai-ui';

	/** The site transient that holds the releases read last. */
	public const CACHE = 'dxai_ui_github_releases';

	private const TTL = 6 * HOUR_IN_SECONDS;

	private const RETRY = HOUR_IN_SECONDS;

	public function register(): void {
		add_filter( 'update_plugins_github.com', array( self::class, 'update' ), 10, 3 );
		add_filter( 'plugins_api', array( self::class, 'details' ), 10, 3 );
	}

	/** The repository the updates come from ("owner/name"), or '' when they are switched off. */
	public static function repository(): string {
		// `dxai_ui_update_repository`: the GitHub repository ("owner/name") DX updates from; '' switches the updates off.
		$repo = (string) apply_filters( 'dxai_ui_update_repository', self::REPOSITORY );

		return preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) === 1 ? $repo : '';
	}

	/**
	 * Whether this copy of the plugin is a development checkout: a link to the source tree, or the tree itself (git, node_modules,
	 * .verify, the import suite — the release ZIP has none of them). WordPress replaces a plugin by deleting its folder first, and
	 * through a link that is the repository, uncommitted work and all; so no update is offered to one, as uninstall.php will not
	 * delete one. `dxai_ui_update_checkout` says otherwise.
	 */
	public static function is_checkout(): bool {
		$dir      = untrailingslashit( (string) DXAI_UI_DIR );
		$folder   = WP_PLUGIN_DIR . '/' . dirname( plugin_basename( DXAI_UI_FILE ) );
		$checkout = is_link( $folder ) || file_exists( $dir . '/.git' ) || is_dir( $dir . '/node_modules' ) || is_dir( $dir . '/.verify' ) || file_exists( $dir . '/bin/verify-import.cjs' );

		// `dxai_ui_update_checkout`: whether this copy is a development checkout, which is offered no update.
		return (bool) apply_filters( 'dxai_ui_update_checkout', $checkout );
	}

	/** 'beta' (pre-releases offered too) or 'stable'. */
	public static function channel(): string {
		$default = str_contains( (string) DXAI_UI_VERSION, '-' ) ? 'beta' : 'stable';
		// `dxai_ui_update_channel`: 'stable' (releases only) or 'beta' (pre-releases too); by default the channel of the version running.
		$channel = (string) apply_filters( 'dxai_ui_update_channel', $default );

		return $channel === 'beta' ? 'beta' : 'stable';
	}

	/**
	 * The newest release this site may update to (whatever version it runs), or null when there is none or none could be read.
	 *
	 * @return array{version:string, package:string, url:string, notes:string, published:string}|null
	 */
	public static function latest(): ?array {
		$repo = self::repository();
		if ( $repo === '' ) {
			return null;
		}
		$beta = self::channel() === 'beta';
		$best = null;
		foreach ( self::releases( $repo ) as $release ) {
			if ( ! is_array( $release ) || ! empty( $release['draft'] ) ) {
				continue;
			}
			$version = ltrim( (string) ( $release['tag_name'] ?? '' ), 'vV' );
			if ( preg_match( '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/', $version ) !== 1 ) {
				continue;
			}
			if ( ( ! empty( $release['prerelease'] ) || str_contains( $version, '-' ) ) && ! $beta ) {
				continue;
			}
			$package = '';
			foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
				if ( is_array( $asset ) && ( $asset['name'] ?? '' ) === 'dxai-ui-' . $version . '.zip' ) {
					$package = (string) ( $asset['browser_download_url'] ?? '' );
				}
			}
			if ( ! str_starts_with( $package, 'https://' ) ) {
				continue;
			}
			if ( $best === null || version_compare( $version, $best['version'], '>' ) ) {
				$best = array(
					'version'   => $version,
					'package'   => $package,
					'url'       => (string) ( $release['html_url'] ?? 'https://github.com/' . $repo . '/releases' ),
					'notes'     => (string) ( $release['body'] ?? '' ),
					'published' => (string) ( $release['published_at'] ?? '' ),
				);
			}
		}

		return $best;
	}

	/**
	 * WordPress asks what the newest version of a plugin whose Update URI is on github.com is (update_plugins_{$hostname}).
	 *
	 * @param array<string, mixed>|false $update      What another filter said, or false.
	 * @param array<string, mixed>       $plugin_data The plugin's header.
	 * @param string                     $plugin_file The plugin's file, relative to the plugins folder.
	 * @return array<string, mixed>|false
	 */
	public static function update( $update, $plugin_data = array(), $plugin_file = '' ) {
		if ( $plugin_file !== plugin_basename( DXAI_UI_FILE ) || is_array( $update ) || self::is_checkout() ) {
			return $update;
		}
		$latest = self::latest();
		if ( $latest === null ) {
			return $update;
		}

		return array(
			'id'           => 'github.com/' . self::repository(),
			'slug'         => self::SLUG,
			'version'      => $latest['version'],
			'url'          => $latest['url'],
			'package'      => $latest['package'],
			'requires_php' => '8.2',
		);
	}

	/**
	 * "View details" in the plugins and updates screens: the release's notes and what it needs.
	 *
	 * @param false|object|array<string, mixed> $result What another filter said.
	 * @param string                            $action The plugins_api action.
	 * @param object|array<string, mixed>|null  $args   Its arguments: the slug.
	 * @return false|object|array<string, mixed>
	 */
	public static function details( $result, $action = '', $args = null ) {
		$slug = is_object( $args ) ? (string) ( $args->slug ?? '' ) : ( is_array( $args ) ? (string) ( $args['slug'] ?? '' ) : '' );
		if ( $action !== 'plugin_information' || $slug !== self::SLUG ) {
			return $result;
		}
		$latest = self::latest();
		if ( $latest === null ) {
			return $result;
		}

		return (object) array(
			'name'          => 'DX UI',
			'slug'          => self::SLUG,
			'version'       => $latest['version'],
			'author'        => '<a href="https://devrix.com">DevriX</a>',
			'homepage'      => 'https://github.com/' . self::repository(),
			'requires'      => '6.6',
			'requires_php'  => '8.2',
			'last_updated'  => $latest['published'],
			'download_link' => $latest['package'],
			'sections'      => array(
				'changelog' => wpautop( esc_html( $latest['notes'] !== '' ? $latest['notes'] : __( 'This release has no notes.', 'dxai-ui' ) ) ),
			),
		);
	}

	/**
	 * The repository's releases, newest first as GitHub lists them: from the transient, else read once.
	 *
	 * @return array<int, mixed>
	 */
	private static function releases( string $repo ): array {
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) && ( $cached['repo'] ?? '' ) === $repo && is_array( $cached['releases'] ?? null ) ) {
			return $cached['releases'];
		}
		$response = Remote_Client::request(
			'GET',
			'https://api.github.com/repos/' . $repo . '/releases?per_page=20',
			array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'DX-UI/' . DXAI_UI_VERSION,
			),
			'',
			array( 'timeout' => 10 )
		);
		$list = null;
		if ( ! is_wp_error( $response ) && 200 === (int) $response['code'] ) {
			$decoded = json_decode( (string) $response['body'], true );
			$list    = is_array( $decoded ) && array_is_list( $decoded ) ? $decoded : null;
		}
		set_site_transient(
			self::CACHE,
			array(
				'repo'     => $repo,
				'releases' => $list ?? array(),
				'read'     => $list !== null,
			),
			$list !== null ? self::TTL : self::RETRY
		);

		return $list ?? array();
	}
}

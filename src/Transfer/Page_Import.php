<?php
/**
 * Import a page package: the page, its design and what it renders with.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Transfer;

use DXAI_UI\Chrome\Footer_Widgets;
use DXAI_UI\Chrome\Header_Menus;
use DXAI_UI\Chrome\Header_Template;
use DXAI_UI\Chrome\Site_Footer_Block;
use DXAI_UI\Chrome\Site_Header_Block;
use DXAI_UI\Connectors\Zip_Extractor;
use DXAI_UI\Media\Sideloader;
use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Support\Upload_Paths;
use DXAI_UI\Theme\Design_Theme_Json;

/**
 * Reads a package Page_Export wrote and makes the page exist here, looking
 * as it did on the source site.
 *
 * Refused before anything is written: an archive the unpacker refuses (a
 * `..` or absolute path, server-side code, a zip bomb — Zip_Extractor's own
 * checks and limits), a missing or foreign manifest, a format this version
 * cannot read, a file the manifest names that is missing, of a type the
 * format does not carry, or whose SHA-1 differs, and a user without
 * unfiltered_html (the page, its CSS and its script are published as they
 * are, which WordPress allows only to such a user — Structure_Repository::
 * save() refuses the same way).
 *
 * Then, in this order:
 *
 * 1. media: every file sideloaded through Sideloader, which returns the
 *    attachment this site already holds for the same bytes instead of a copy;
 *    a new attachment gets the source's title, alt text, caption and
 *    description, a reused one only an alt text it lacked;
 * 2. ids: the page, its synced patterns and navigation posts get their ids
 *    here — the ones a previous import of this package made (Package::KEY_META),
 *    else new ones;
 * 3. the rewrite, of the page, every part, pattern, header and footer, the
 *    design stylesheet and script and the meta: every uploads URL the source
 *    used to the URL of that attachment (and size) here; the page's own URLs
 *    there to its URL here; `dxai-ui--{old scope}` to `dxai-ui--{new id}`
 *    wherever it is a whole class token (the design sheet is scoped to it);
 *    pattern, navigation and attachment ids in block attributes and
 *    `wp-image-N`; template-part references to this site's theme; a link
 *    to another page of the source site to that page here, or to its path
 *    here when this site has none yet (rebase());
 * 4. the header and footer, the way the person importing chose — `install`,
 *    `keep`, or automatic, resolved by Chrome_Choice exactly as a ZIP import
 *    resolves them (a package is a one-page import, so automatic keeps):
 *    - `install`: the header into Appearance > Menus (Header_Menus::install(),
 *      taking the theme locations) and the footer into Appearance > Widgets
 *      (Structure_Repository::install_site_footer(): the design whose footer
 *      the widget areas held gets it back on its pages as a template part
 *      first), from the packaged markup, and the page shows the site header
 *      and footer blocks. A part install() refuses (a header whose menus
 *      cannot render it exactly) stays a template part, as on a ZIP import,
 *      and the result says so. A location a person's own menu holds keeps
 *      it, as on a ZIP import — the result names it;
 *    - `keep`: the site's menus and widgets are not touched; the page keeps
 *      its own header and footer as template parts (a packaged site header
 *      or footer block becomes one, from its markup — the header filled from
 *      the packaged menus, as the source showed it);
 * 5. the page itself, with its template and meta, and its stylesheet and
 *    script written to uploads/dxai-ui/pattern-{id}.{css,js} (the script on
 *    this site's motion runtime). A page this package creates is a draft
 *    unless the importer asked to publish it: a package is someone's HTML,
 *    CSS and JavaScript, looked at before it is public. A page it updates
 *    keeps the status it has here;
 * 6. the brand: this design becomes the site's (Design_Theme_Json::adopt())
 *    only when the site has none;
 * 7. what is derived: the import audit is measured again; Style_Rules' caches
 *    are keyed on the content, so a written post has none stale; the
 *    must-use runtime learns the site has a converted page (Runtime::ensure()).
 *
 * From the admin screen the import runs in steps (Import_Job) when the media
 * would take longer than one request may; from WP-CLI in one go.
 *
 * The same package imported again updates the same page, parts, patterns,
 * menus and widgets in place. Nothing on the site is ever deleted: a part the
 * page stops using goes to the trash only when it is one this package made,
 * nothing references it and the site keeps a trash (with EMPTY_TRASH_DAYS 0
 * wp_trash_post() deletes, so the part stays published instead), and widgets
 * install() displaces go to Inactive Widgets.
 */
final class Page_Import {

	public const CHROME_KEEP    = 'keep';
	public const CHROME_INSTALL = 'install';
	/** Automatic: Chrome_Choice decides (keep, for a one-page import). */
	public const CHROME_AUTO = '';

	/** On a page this package created until it is written in full; the next import finishes it as a new page. */
	private const PENDING_META = '_dxai_ui_transfer_pending';

	/** Meta never copied from a package: this site's own bookkeeping, or measured again here. */
	private const SKIP_META = array( Package::KEY_META, Package::SOURCE_META, self::PENDING_META, '_dxai_ui_audit', '_dxai_ui_canvas_html', '_dxai_ui_crawl', '_dxai_ui_page_id' );

	/** Page statuses kept from the source; anything else is published. */
	private const STATUSES = array( 'publish', 'draft', 'pending', 'private' );

	/**
	 * Hosts a packaged font stylesheet may be loaded from: the font services
	 * the converter reads designs from (Asset_Harvester), besides this site.
	 */
	private const FONT_HOSTS = array( 'fonts.googleapis.com', 'fonts.gstatic.com', 'fonts.bunny.net', 'use.typekit.net', 'p.typekit.net', 'fonts.adobe.com', 'api.fontshare.com' );

	/** @var array<int, string> */
	private array $warnings = array();

	/** @var array<string, string> Source URL => URL here, looked up whole (url_lookup()). */
	private array $urls = array();

	/** @var array<int, int> Old scope / pattern id => new id, for `dxai-ui--N`. */
	private array $scopes = array();

	/** @var array<int, int> Source attachment id => attachment id here. */
	private array $attachments = array();

	/** @var array<int, int> Source pattern id => pattern id here. */
	private array $patterns = array();

	/** @var array<int, int> Source navigation post id => id here. */
	private array $navigations = array();

	/** @var array<string, string> Source part slug => slug here. */
	private array $part_slugs = array();

	private string $identity = '';

	/** The page key the imported page carries (page_key()). */
	private string $page_key = '';

	/** @var array{host:string, path:string, hosts:array<int, string>} The source's uploads, for finding its URLs. */
	private array $source = array(
		'host'  => '',
		'path'  => '',
		'hosts' => array(),
	);

	/** @var array{host:string, path:string} The source site's address, for rebasing links to its other pages (rebase()). */
	private array $site = array(
		'host' => '',
		'path' => '',
	);

	/** @var array<string, string> Paths rebase() found no page here for => the URL they point at now. */
	private array $unmatched = array();

	/** @var array<string, int> Attachment ids this import created, across its steps (id => 1). */
	private array $made_media = array();

	/**
	 * Why this user may not import, in the words the import routes use
	 * (a Super Admin on multisite, DISALLOW_UNFILTERED_HTML otherwise).
	 */
	public static function refusal(): string {
		return method_exists( \DXAI_UI\API\Converter_Controller::class, 'unfiltered_html_message' )
			? \DXAI_UI\API\Converter_Controller::unfiltered_html_message()
			: __( 'Importing a page package publishes its HTML, CSS and JavaScript, so it must run as a user allowed to publish unfiltered HTML.', 'dxai-ui' );
	}

	/**
	 * A header and footer choice as keep, install or automatic (''), or null
	 * for a value that is none of them. `auto` is automatic.
	 *
	 * @param mixed $value
	 */
	public static function chrome_choice( $value ): ?string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : ( null === $value ? '' : null );
		if ( null === $value ) {
			return null;
		}
		if ( $value === 'auto' ) {
			return self::CHROME_AUTO;
		}

		return in_array( $value, array( self::CHROME_KEEP, self::CHROME_INSTALL, self::CHROME_AUTO ), true ) ? $value : null;
	}

	/**
	 * How long one step of an import from the admin screen may add media:
	 * 20 seconds — a third of WP Engine's 60 s request limit, and less when
	 * PHP's own limit is lower and cannot be raised. Filterable
	 * (`dxai_ui_transfer_step_seconds`); 0 imports in one request.
	 */
	public static function step_budget( bool $raised = true ): float {
		$budget = 20.0;
		$limit  = (int) ini_get( 'max_execution_time' );
		if ( ! $raised && $limit > 0 ) {
			$budget = min( $budget, max( 3.0, $limit * 0.4 ) );
		}

		return max( 0.0, (float) apply_filters( 'dxai_ui_transfer_step_seconds', $budget ) );
	}

	/**
	 * No time limit, and no stop when the browser gives up waiting: an import
	 * cut off between writes leaves a page half written. The same as the ZIP
	 * import's save. True when PHP's limit could be lifted.
	 */
	public static function unlimited(): bool {
		ignore_user_abort( true );
		if ( ! function_exists( 'set_time_limit' ) ) {
			return false;
		}

		return (bool) @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disabled on some hosts.
	}

	/**
	 * Import a package.
	 *
	 * @param string               $zip_path Path of the uploaded or local package.
	 * @param string               $name     Its file name (must end in .zip).
	 * @param array<string, mixed> $options  chrome: keep|install|'' (automatic, the default);
	 *                                       publish: a page this import creates gets the
	 *                                       source's status (else it is a draft); dry_run:
	 *                                       validate only; budget: seconds one step may add
	 *                                       media for (0, the default: all in this call); job:
	 *                                       the Import_Job this call goes on with.
	 * @return array<string, mixed>|\WP_Error What was imported — or, when the budget ran out,
	 *                                        status `continue` with the job's token and the
	 *                                        media counts so far.
	 */
	public function run( string $zip_path, string $name = '', array $options = array() ): array|\WP_Error {
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			return new \WP_Error( 'dxai_ui_unfiltered_html', self::refusal(), array( 'status' => 403 ) );
		}
		$chrome = self::chrome_choice( $options['chrome'] ?? self::CHROME_AUTO );
		if ( null === $chrome ) {
			/* translators: %s: the value given. */
			return new \WP_Error( 'dxai_ui_transfer_chrome', sprintf( __( 'Unknown header and footer choice "%s": use keep, install or auto.', 'dxai-ui' ), is_scalar( $options['chrome'] ?? null ) ? (string) $options['chrome'] : '' ), array( 'status' => 400 ) );
		}
		$name    = $name !== '' ? $name : wp_basename( $zip_path );
		$job     = is_array( $options['job'] ?? null ) ? $options['job'] : null;
		$budget  = max( 0.0, (float) ( $options['budget'] ?? 0 ) );
		$started = microtime( true );
		if ( empty( $options['dry_run'] ) ) {
			self::unlimited();
		}

		/*
		 * Unpacked where the work folder can be removed again: a system temp
		 * folder PHP may write but not list (Package::listable()) would keep
		 * every unpacked package, so the uploads fallback is used then.
		 */
		$root = static fn( $dir ) => is_string( $dir ) && $dir !== '' && Package::listable( $dir ) ? $dir : '';
		add_filter( 'dxai_ui_zip_work_root', $root, 99 );
		try {
			$unpacked = Zip_Extractor::unpack(
				array(
					'name'     => $name,
					'tmp_name' => $zip_path,
					'error'    => UPLOAD_ERR_OK,
					'size'     => is_readable( $zip_path ) ? (int) filesize( $zip_path ) : 0,
				)
			);
		} finally {
			remove_filter( 'dxai_ui_zip_work_root', $root, 99 );
		}
		if ( is_wp_error( $unpacked ) ) {
			return new \WP_Error(
				'dxai_ui_transfer_refused',
				/* translators: %s: why the archive was refused. */
				sprintf( __( 'The package was refused: %s', 'dxai-ui' ), $unpacked->get_error_message() ),
				array( 'status' => 400 )
			);
		}

		try {
			$manifest = $this->validate( (string) $unpacked['dir'], (array) $unpacked['files'] );
			if ( is_wp_error( $manifest ) ) {
				return $manifest;
			}
			if ( ! empty( $options['dry_run'] ) ) {
				return array(
					'ok'      => true,
					'dry_run' => true,
					'page'    => array( 'title' => (string) $manifest['page']['title'] ),
					'source'  => $this->source_summary( $manifest ),
					'counts'  => array(
						'media'       => count( (array) $manifest['media'] ),
						'parts'       => count( (array) $manifest['parts'] ),
						'patterns'    => count( (array) $manifest['patterns'] ),
						'navigations' => count( (array) $manifest['navigations'] ),
					),
					'chrome'  => array(
						'header' => (string) ( $manifest['chrome']['header']['mode'] ?? 'none' ),
						'footer' => (string) ( $manifest['chrome']['footer']['mode'] ?? 'none' ),
					),
				);
			}

			try {
				// 1. Media, until the budget is spent (the rest in the next step).
				$state   = $job ? (array) ( $job['state'] ?? array() ) : array();
				$results = (array) ( $state['media'] ?? array() );
				$this->made_media = array_fill_keys( array_map( 'intval', (array) ( $state['made'] ?? array() ) ), 1 );
				$deadline         = $budget > 0 ? $started + $budget : 0.0;
				$items            = array_values( (array) $manifest['media'] );
				$complete         = $this->sideload_media( (string) $unpacked['dir'], $items, $results, $deadline );
				if ( ! $complete || ( $deadline > 0 && $items !== array() && microtime( true ) >= $deadline ) ) {
					$paused = $this->pause( $job, $zip_path, $name, $options, $results, count( $items ) );
					if ( null !== $paused ) {
						return $paused;
					}
					// Nowhere to wait: the rest in this request.
					$this->sideload_media( (string) $unpacked['dir'], $items, $results, 0.0 );
				}

				// 2. The rest, in this request.
				$report = $this->apply( $manifest, (string) $unpacked['dir'], $chrome, $items, $results, ! empty( $options['publish'] ) );
				if ( $job && ! is_wp_error( $report ) ) {
					Import_Job::close( $job );
				}

				return $report;
			} catch ( \Throwable $e ) {
				return new \WP_Error(
					'dxai_ui_transfer_failed',
					/* translators: %s: error message. */
					sprintf( __( 'The import stopped part way: %s. What it wrote so far is kept (a page it creates stays private until it is written in full); importing the package again continues from there.', 'dxai-ui' ), $e->getMessage() ),
					array( 'status' => 500 )
				);
			}
		} finally {
			Zip_Extractor::cleanup( (string) $unpacked['dir'] );
		}
	}

	/**
	 * The budget ran out while media was added: the package waits for the
	 * next step (Import_Job), and the answer says how far it got. When no
	 * folder can hold it, the import goes on in this request instead, as
	 * one without steps would (null).
	 *
	 * @param array<string, mixed>|null        $job
	 * @param array<string, mixed>             $options
	 * @param array<int, array<string, mixed>> $results sideload_media()'s.
	 * @return array<string, mixed>|null
	 */
	private function pause( ?array $job, string $zip_path, string $name, array $options, array $results, int $total ): ?array {
		$state = array(
			'media' => $results,
			'made'  => array_keys( $this->made_media ),
		);
		if ( $job ) {
			$job['state'] = $state;
			Import_Job::save( $job );
		} else {
			$job = Import_Job::create( $zip_path, $name, array_intersect_key( $options, array_flip( array( 'chrome', 'publish', 'budget' ) ) ), $state );
			if ( is_wp_error( $job ) ) {
				return null;
			}
		}
		$done = count( $results );

		return array(
			'ok'      => true,
			'status'  => 'continue',
			'token'   => (string) $job['token'],
			'media'   => $this->media_counts( $results ) + array(
				'done'  => $done,
				'total' => $total,
			),
			'message' => sprintf(
				/* translators: 1: files done, 2: files in the package. */
				__( '%1$d of %2$d files are in the media library; the import goes on in the next step.', 'dxai-ui' ),
				$done,
				$total
			),
		);
	}

	/**
	 * The manifest, when the package is one this version can import.
	 *
	 * @param array<int, string> $files Unpacked entries, relative.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function validate( string $dir, array $files ): array|\WP_Error {
		$have = array_flip( array_map( 'strval', $files ) );
		$not  = static fn( string $why ): \WP_Error => new \WP_Error(
			'dxai_ui_transfer_manifest',
			/* translators: %s: what is wrong. */
			sprintf( __( 'This is not a DX UI page package: %s', 'dxai-ui' ), $why ),
			array( 'status' => 400 )
		);
		if ( ! isset( $have[ Package::MANIFEST ] ) ) {
			return $not( __( 'it has no manifest.json at its root.', 'dxai-ui' ) );
		}
		$path = $dir . '/' . Package::MANIFEST;
		$size = (int) filesize( $path );
		if ( $size < 2 || $size > Package::MANIFEST_MAX ) {
			return $not( __( 'its manifest.json is empty or too large.', 'dxai-ui' ) );
		}
		$manifest = json_decode( (string) file_get_contents( $path ), true, 128 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $manifest ) ) {
			return $not( __( 'its manifest.json is not valid JSON.', 'dxai-ui' ) );
		}
		if ( ( $manifest['format'] ?? null ) !== Package::FORMAT ) {
			/* translators: %s: format name. */
			return $not( sprintf( __( 'its manifest says format "%s".', 'dxai-ui' ), is_scalar( $manifest['format'] ?? null ) ? (string) $manifest['format'] : '' ) );
		}
		$version = $manifest['format_version'] ?? null;
		if ( ! is_int( $version ) || $version < 1 ) {
			return $not( __( 'its manifest has no valid format version.', 'dxai-ui' ) );
		}
		if ( $version > Package::VERSION ) {
			return new \WP_Error(
				'dxai_ui_transfer_version',
				/* translators: 1: package format version, 2: plugin version, 3: newest readable format version. */
				sprintf( __( 'This package was made by a newer DX UI (package format %1$d, plugin %2$s); this site reads format %3$d. Update the plugin here first.', 'dxai-ui' ), $version, is_scalar( $manifest['plugin_version'] ?? null ) ? (string) $manifest['plugin_version'] : '?', Package::VERSION ),
				array( 'status' => 400 )
			);
		}
		$page   = $manifest['page'] ?? null;
		$source = $manifest['source'] ?? null;
		if ( ! is_array( $page ) || ! is_string( $page['content'] ?? null ) || ! is_string( $page['title'] ?? null ) || ! is_array( $page['meta'] ?? array() ) ) {
			return $not( __( 'its manifest has no page.', 'dxai-ui' ) );
		}
		if ( ! is_array( $source ) || ! is_string( $source['site_url'] ?? null ) || '' === trim( (string) $source['site_url'] ) || ! is_string( $source['page_key'] ?? null ) || '' === (string) $source['page_key'] ) {
			return $not( __( 'its manifest does not say which site and page it came from.', 'dxai-ui' ) );
		}
		foreach ( array( 'parts', 'patterns', 'navigations', 'media', 'files' ) as $list ) {
			$manifest[ $list ] = $manifest[ $list ] ?? array();
			if ( ! is_array( $manifest[ $list ] ) || array_filter( $manifest[ $list ], static fn( $row ): bool => ! is_array( $row ) ) !== array() ) {
				/* translators: %s: manifest key. */
				return $not( sprintf( __( 'its "%s" list is not a list of entries.', 'dxai-ui' ), $list ) );
			}
		}
		if ( count( $manifest['parts'] ) + count( $manifest['patterns'] ) + count( $manifest['navigations'] ) > Package::MAX_ITEMS || count( $manifest['media'] ) > 5000 ) {
			return $not( __( 'it lists more parts, patterns or media than a page package can hold.', 'dxai-ui' ) );
		}
		$manifest['design'] = is_array( $manifest['design'] ?? null ) ? $manifest['design'] : array();
		$manifest['chrome'] = is_array( $manifest['chrome'] ?? null ) ? $manifest['chrome'] : array();

		// Every file the package lists: a path the format writes, present,
		// with the bytes it was exported with.
		$listed = array();
		foreach ( $manifest['files'] as $file ) {
			$rel = (string) ( $file['path'] ?? '' );
			if ( ! Package::safe_path( $rel ) ) {
				/* translators: %s: path. */
				return $not( sprintf( __( 'it lists a file at an unsafe path (%s).', 'dxai-ui' ), $rel ) );
			}
			if ( ! isset( $have[ $rel ] ) ) {
				/* translators: %s: path. */
				return $not( sprintf( __( 'the file %s it lists is missing or of a type that is never imported.', 'dxai-ui' ), $rel ) );
			}
			$sha1 = (string) ( $file['sha1'] ?? '' );
			if ( $sha1 === '' || ! hash_equals( $sha1, (string) sha1_file( $dir . '/' . $rel ) ) ) {
				/* translators: %s: path. */
				return $not( sprintf( __( 'the file %s is not the one it was exported with (checksum mismatch).', 'dxai-ui' ), $rel ) );
			}
			$listed[ $rel ] = true;
		}

		// And each reference to a file names a listed one of the right kind.
		$refs = array();
		foreach ( array( 'css' => 'css', 'js' => 'js' ) as $key => $ext ) {
			$refs[] = array( (string) ( $manifest['design'][ $key ] ?? '' ), $ext );
		}
		foreach ( $manifest['patterns'] as $row ) {
			$refs[] = array( (string) ( $row['css'] ?? '' ), 'css' );
		}
		foreach ( $manifest['media'] as $row ) {
			$file = (string) ( $row['file'] ?? '' );
			if ( $file === '' ) {
				return $not( __( 'a media entry names no file.', 'dxai-ui' ) );
			}
			$refs[] = array( $file, 'media' );
			if ( ! Package::media_allowed( (string) ( $row['name'] ?? $file ) ) ) {
				/* translators: %s: file name. */
				return $not( sprintf( __( 'it carries %s, which is not an image, video, audio or font file.', 'dxai-ui' ), (string) ( $row['name'] ?? $file ) ) );
			}
		}
		foreach ( $refs as $ref ) {
			list( $rel, $kind ) = $ref;
			if ( $rel === '' ) {
				continue;
			}
			$ext = strtolower( (string) pathinfo( $rel, PATHINFO_EXTENSION ) );
			$ok  = $kind === 'media' ? Package::media_allowed( $rel ) : $ext === $kind;
			if ( ! isset( $listed[ $rel ] ) || ! $ok ) {
				/* translators: %s: path. */
				return $not( sprintf( __( 'it names %s, which it does not list or which is of the wrong type.', 'dxai-ui' ), $rel ) );
			}
		}

		return $manifest;
	}

	/**
	 * @param array<string, mixed>             $manifest
	 * @param array<int, array<string, mixed>> $items   The manifest's media entries.
	 * @param array<int, array<string, mixed>> $results What each became (sideload_media()).
	 * @return array<string, mixed>|\WP_Error
	 */
	private function apply( array $manifest, string $dir, string $chrome, array $items, array $results, bool $publish ): array|\WP_Error {
		$source         = (array) $manifest['source'];
		$page           = (array) $manifest['page'];
		$archive        = (string) ( $page['meta']['_dxai_ui_source_zip'] ?? '' );
		$this->identity = Package::identity( (string) $source['site_url'], (string) $source['page_key'] );
		$this->source_hosts( $manifest );

		$media    = $this->map_media( $items, $results );
		$reserved = $this->reserve_page( $page );
		if ( is_wp_error( $reserved ) ) {
			return $reserved;
		}
		$created        = $reserved['created'];
		$page_id        = $reserved['id'];
		$this->current  = (string) get_post_field( 'post_content', $page_id );
		$this->page_key = $this->page_key( (string) ( $page['meta']['_dxai_ui_page_key'] ?? '' ), $page_id, (string) $source['site_url'] );

		/*
		 * The header and footer choice, resolved the way a ZIP import resolves
		 * it (Chrome_Choice), before anything of the site's is written: as a
		 * one-page import, so automatic keeps the site's.
		 */
		$choice = $this->resolve_chrome( $chrome, $archive, $page_id );

		$old_page  = (int) ( $source['page_id'] ?? 0 );
		$old_scope = (int) ( $source['scope_id'] ?? 0 );
		foreach ( array( $old_page, $old_scope ) as $old ) {
			if ( $old > 0 ) {
				$this->scopes[ $old ] = $page_id;
			}
		}

		$pattern_rows = $this->reserve( (array) $manifest['patterns'], 'wp_block', 'pattern' );
		$nav_rows     = $this->reserve( (array) $manifest['navigations'], 'wp_navigation', 'navigation' );
		foreach ( $pattern_rows as $old => $row ) {
			$this->patterns[ $old ] = $row['id'];
			$this->scopes[ $old ]   = $row['id'];
		}
		foreach ( $nav_rows as $old => $row ) {
			$this->navigations[ $old ] = $row['id'];
		}
		$this->plan_parts( (array) $manifest['parts'] );
		$this->map_page_links( (array) ( $source['links'] ?? array() ), (string) ( $source['permalink'] ?? '' ), $page_id );
		$this->map_site_root( (string) $source['site_url'] );

		$patterns    = $this->write_patterns( (array) $manifest['patterns'], $pattern_rows, $dir );
		$navigations = $this->write_navigations( (array) $manifest['navigations'], $nav_rows );

		$content    = $this->rewrite( (string) $page['content'] );
		$chrome_out = $this->chrome( $manifest, $content, $page_id, $choice['mode'] );
		$content    = $chrome_out['content'];
		$parts      = $this->write_parts( (array) $manifest['parts'], array_merge( array( $content ), array_column( $patterns, 'content' ) ), $chrome_out['part_content'] );
		$retired    = $this->retire_parts( $page_id, array_column( $parts, 'id' ) );

		$written = $this->write_page( $page_id, $page, $content, $manifest, $dir, $created, $publish );
		$created = $created || $written['created'];
		$brand   = $this->brand( $page_id, $archive );
		if ( $this->unmatched !== array() ) {
			ksort( $this->unmatched, SORT_STRING );
			$this->warnings[] = sprintf(
				/* translators: %s: list of URLs. */
				__( 'Links to other pages of the source site now point at the same paths on this site, but this site has no page there yet: %s. They work once those pages exist here.', 'dxai-ui' ),
				implode( ', ', array_values( $this->unmatched ) )
			);
		}

		if ( class_exists( \DXAI_UI\Verification\Import_Audit::class ) ) {
			update_post_meta( $page_id, \DXAI_UI\Verification\Import_Audit::META, ( new \DXAI_UI\Verification\Import_Audit() )->page( $page_id ) );
		}
		if ( class_exists( Site_Header_Block::class ) ) {
			Site_Header_Block::forget();
		}
		// The must-use runtime renders converted pages only on a site its
		// record says has one; activation and admin_init keep that record,
		// and a WP-CLI import runs neither.
		if ( class_exists( \DXAI_UI\Support\Runtime::class ) && ! \DXAI_UI\Support\Runtime::render_only() ) {
			\DXAI_UI\Support\Runtime::ensure();
		}

		// What the chrome report reads: install()'s answers, or what keeping did.
		$raw = array();
		foreach ( array( 'header', 'footer' ) as $area ) {
			$row = (array) $chrome_out[ $area ];
			if ( ( $row['mode'] ?? '' ) === 'none' ) {
				continue;
			}
			if ( is_array( $row['answer'] ?? null ) ) {
				$raw[ $area ] = $row['answer'];
				continue;
			}
			$part_id = 0;
			foreach ( $parts as $written_part ) {
				if ( (string) $written_part['slug'] === (string) ( $row['part'] ?? '' ) ) {
					$part_id = (int) $written_part['id'];
				}
			}
			$raw[ $area ] = array(
				'block_markup' => '',
				'error'        => 'kept',
				'kept'         => true,
				'part_id'      => $part_id,
				'reason'       => $choice['reason'],
				'message'      => 'header' === $area
					? __( 'The header is a template part of this page; Appearance › Menus was not changed.', 'dxai-ui' )
					: __( 'The footer is a template part of this page; Appearance › Widgets was not changed.', 'dxai-ui' ),
			);
		}
		$classic = $this->classic_warning( $chrome_out );
		if ( $classic !== '' ) {
			$this->warnings[] = $classic;
		}
		if ( $retired['kept'] !== array() ) {
			$this->warnings[] = sprintf(
				/* translators: %s: template part ids. */
				__( 'This site keeps no trash (EMPTY_TRASH_DAYS is 0), where wp_trash_post() deletes for good, so the template parts this page no longer uses stay published instead (ids %s): nothing renders them, and Appearance › Editor › Patterns lists them.', 'dxai-ui' ),
				implode( ', ', array_map( 'intval', $retired['kept'] ) )
			);
		}

		$status = (string) get_post_status( $page_id );
		$report = array(
			'ok'            => true,
			'page'          => array(
				'id'      => $page_id,
				'title'   => get_the_title( $page_id ),
				'status'  => $status,
				'created' => $created,
				// A draft is looked at through its preview.
				'view'    => 'publish' === $status ? (string) get_permalink( $page_id ) : (string) get_preview_post_link( $page_id ),
				'edit'    => (string) get_edit_post_link( $page_id, 'raw' ),
			),
			'source'        => $this->source_summary( $manifest ),
			'chrome'        => array(
				'mode'   => $choice['mode'],
				'header' => $chrome_out['header'],
				'footer' => $chrome_out['footer'],
			),
			'chrome_mode'   => $choice,
			'media'         => $media,
			'parts'         => $parts,
			'parts_retired' => $retired['retired'],
			'parts_kept'    => $retired['kept'],
			'patterns'      => array_map(
				static fn( array $p ): array => array_diff_key( $p, array( 'content' => true ) ),
				$patterns
			),
			'navigations'   => $navigations,
			'design'        => array_diff_key( $written, array( 'created' => true ) ),
			'brand'         => $brand,
			'warnings'      => array_values( array_unique( array_merge( array_map( 'strval', (array) ( $manifest['warnings'] ?? array() ) ), $this->warnings ) ) ),
		);
		if ( method_exists( \DXAI_UI\API\Converter_Controller::class, 'chrome_report' ) ) {
			$report['chrome_report'] = \DXAI_UI\API\Converter_Controller::chrome_report( $raw, $choice );
		}
		foreach ( array( 'header', 'footer' ) as $area ) {
			unset( $report['chrome'][ $area ]['answer'] );
		}

		return $report;
	}

	/**
	 * The header and footer choice for this import (Chrome_Choice::resolve()):
	 * mode install or keep, what was asked, why, and the sentence saying so.
	 *
	 * @return array{mode:string, requested:string, reason:string, message:string}
	 */
	private function resolve_chrome( string $requested, string $archive, int $page_id ): array {
		if ( ! class_exists( \DXAI_UI\Chrome\Chrome_Choice::class ) ) {
			return array(
				'mode'      => self::CHROME_INSTALL === $requested ? self::CHROME_INSTALL : self::CHROME_KEEP,
				'requested' => $requested,
				'reason'    => self::CHROME_AUTO === $requested ? 'one_page' : 'asked',
				'message'   => '',
			);
		}
		$choice = \DXAI_UI\Chrome\Chrome_Choice::resolve(
			$requested,
			array(
				'scope'   => 'page',
				'owner'   => $this->page_key,
				'archive' => $archive,
				'page_id' => $page_id,
			)
		);

		return array(
			'mode'      => (string) $choice['mode'],
			'requested' => (string) $choice['requested'],
			'reason'    => (string) $choice['reason'],
			'message'   => (string) $choice['message'],
		);
	}

	/**
	 * On a theme that draws the whole site's header and footer from the menu
	 * locations and widget areas an install writes (a classic theme), what
	 * this install changed for every page of the site — only what it did
	 * change: a location a person's own menu keeps is not among them. '' when
	 * nothing of the site's changed, or the theme draws neither.
	 *
	 * @param array<string, mixed> $chrome_out chrome()'s answer.
	 */
	private function classic_warning( array $chrome_out ): string {
		if ( ! class_exists( \DXAI_UI\Theme\Theme_Compat::class ) || ! method_exists( \DXAI_UI\Theme\Theme_Compat::class, 'may_install_chrome' ) || \DXAI_UI\Theme\Theme_Compat::may_install_chrome() ) {
			return '';
		}
		$changed = array();
		$header  = (array) ( $chrome_out['header'] ?? array() );
		if ( ( $header['mode'] ?? '' ) === 'installed' ) {
			$labels = get_registered_nav_menus();
			$kept   = (array) ( $header['locations_kept'] ?? array() );
			$names  = array();
			foreach ( array_keys( (array) ( $header['menus'] ?? array() ) ) as $location ) {
				if ( ! isset( $kept[ $location ] ) ) {
					$names[] = (string) ( $labels[ $location ] ?? $location );
				}
			}
			if ( $names !== array() ) {
				/* translators: %s: menu location names. */
				$changed[] = sprintf( __( 'the menu locations %s now show the design\'s menus', 'dxai-ui' ), implode( ', ', $names ) );
			}
			if ( ! empty( $header['logo_set'] ) ) {
				$changed[] = __( 'the design\'s logo is now the Site Logo', 'dxai-ui' );
			}
		}
		$footer = (array) ( $chrome_out['footer'] ?? array() );
		if ( ( $footer['mode'] ?? '' ) === 'installed' && (array) ( $footer['areas'] ?? array() ) !== array() ) {
			global $wp_registered_sidebars;
			$names = array();
			foreach ( array_keys( (array) $footer['areas'] ) as $area ) {
				$names[] = (string) ( $wp_registered_sidebars[ $area ]['name'] ?? $area );
			}
			/* translators: %s: widget area names. */
			$changed[] = sprintf( __( 'the widget areas %s now hold the design\'s footer widgets', 'dxai-ui' ), implode( ', ', $names ) );
		}
		if ( $changed === array() ) {
			return '';
		}

		return sprintf(
			/* translators: %s: what changed, as a list. */
			__( 'This theme draws the header and footer of every page of the site from the same menu locations and widget areas, and on every page of the site %s. To give the site its own back: Appearance › Menus › Manage Locations, and the earlier widgets are under Inactive Widgets in Appearance › Widgets. Importing with "keep" leaves both alone.', 'dxai-ui' ),
			implode( '; ', $changed )
		);
	}

	/**
	 * @param array<string, mixed> $manifest
	 * @return array<string, mixed>
	 */
	private function source_summary( array $manifest ): array {
		$source = (array) ( $manifest['source'] ?? array() );

		return array(
			'site_url'       => (string) ( $source['site_url'] ?? '' ),
			'page_id'        => (int) ( $source['page_id'] ?? 0 ),
			'page_key'       => (string) ( $source['page_key'] ?? '' ),
			'plugin_version' => (string) ( $manifest['plugin_version'] ?? '' ),
			'format_version' => (int) ( $manifest['format_version'] ?? 0 ),
		);
	}

	/**
	 * The hosts and uploads path the source's URLs are written with.
	 *
	 * @param array<string, mixed> $manifest
	 */
	private function source_hosts( array $manifest ): void {
		$uploads = (string) ( $manifest['source']['uploads'] ?? '' );
		$site    = (string) ( $manifest['source']['site_url'] ?? '' );
		$hosts   = array();
		foreach ( array( $uploads, $site ) as $url ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$port = wp_parse_url( $url, PHP_URL_PORT );
			if ( $host !== '' ) {
				$hosts[] = $host . ( $port ? ':' . (int) $port : '' );
			}
		}
		$this->source = array(
			'host'  => (string) ( $hosts[0] ?? '' ),
			'path'  => untrailingslashit( (string) wp_parse_url( $uploads, PHP_URL_PATH ) ),
			'hosts' => array_values( array_unique( $hosts ) ),
		);
	}

	/**
	 * Sideload the media entries not done yet, until the deadline (0: none):
	 * each through Sideloader, which returns the attachment this site already
	 * holds for the same bytes instead of a copy. What each became is kept in
	 * $results (index => id, url, name, error), which an Import_Job carries to
	 * the next step; an attachment made in an earlier step and deleted since is
	 * made again. At least one entry is done per call, so a step always gets
	 * further. True when every entry is done.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @param array<int, array<string, mixed>> $results
	 */
	private function sideload_media( string $dir, array $items, array &$results, float $deadline ): bool {
		global $wpdb;

		$worked = false;
		foreach ( $items as $i => $item ) {
			$item = (array) $item;
			if ( isset( $results[ $i ] ) && is_array( $results[ $i ] ) ) {
				$id = (int) ( $results[ $i ]['id'] ?? 0 );
				if ( $id < 1 || 'attachment' === get_post_type( $id ) ) {
					continue;
				}
				unset( $results[ $i ] );
			}
			if ( $deadline > 0 && $worked && microtime( true ) >= $deadline ) {
				return false;
			}
			$worked = true;
			$file   = $dir . '/' . (string) ( $item['file'] ?? '' );
			$name   = sanitize_file_name( (string) ( $item['name'] ?? wp_basename( $file ) ) );
			$name   = $name !== '' ? $name : wp_basename( $file );
			$before = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$got    = Sideloader::from_path( $file, $name, '' );
			if ( is_wp_error( $got ) || ( (int) ( $got['id'] ?? 0 ) < 1 && (string) ( $got['url'] ?? '' ) === '' ) ) {
				$results[ $i ] = array(
					'id'    => 0,
					'url'   => '',
					'name'  => $name,
					'error' => is_wp_error( $got ) ? $got->get_error_message() : __( 'no file was stored', 'dxai-ui' ),
				);
				continue;
			}
			$id = (int) ( $got['id'] ?? 0 );
			if ( $id > 0 ) {
				$new = $id > $before;
				if ( $new ) {
					$this->made_media[ $id ] = 1;
				}
				$this->describe( $id, $item, $new );
			}
			$results[ $i ] = array(
				'id'    => $id,
				'url'   => (string) ( $got['url'] ?? '' ),
				'name'  => $name,
				'error' => '',
			);
		}

		return true;
	}

	/**
	 * How many media entries were added, reused and refused so far.
	 *
	 * @param array<int, array<string, mixed>> $results
	 * @return array{created:int, reused:int, failed:int}
	 */
	private function media_counts( array $results ): array {
		$out = array(
			'created' => 0,
			'reused'  => 0,
			'failed'  => 0,
		);
		foreach ( $results as $row ) {
			$row = (array) $row;
			$id  = (int) ( $row['id'] ?? 0 );
			if ( '' !== (string) ( $row['error'] ?? '' ) ) {
				++$out['failed'];
			} elseif ( $id > 0 && ! isset( $this->made_media[ $id ] ) ) {
				++$out['reused'];
			} else {
				// Made by this import — or a file stored as a design asset (a font): no attachment.
				++$out['created'];
			}
		}

		return $out;
	}

	/**
	 * Map each media entry's source URLs and id to what it became here, and
	 * say which could not be added.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @param array<int, array<string, mixed>> $results sideload_media()'s.
	 * @return array{created:int, reused:int, failed:int}
	 */
	private function map_media( array $items, array $results ): array {
		foreach ( $items as $i => $item ) {
			$item = (array) $item;
			$got  = isset( $results[ $i ] ) && is_array( $results[ $i ] ) ? $results[ $i ] : array( 'error' => __( 'it was not imported', 'dxai-ui' ) );
			if ( '' !== (string) ( $got['error'] ?? '' ) ) {
				$this->warnings[] = sprintf(
					/* translators: 1: file name, 2: error. */
					__( '%1$s could not be added to the media library: %2$s', 'dxai-ui' ),
					(string) ( $got['name'] ?? ( $item['name'] ?? '' ) ),
					(string) $got['error']
				);
				continue;
			}
			$id = (int) ( $got['id'] ?? 0 );
			if ( $id > 0 ) {
				$old = (int) ( $item['source_id'] ?? 0 );
				if ( $old > 0 ) {
					$this->attachments[ $old ] = $id;
				}
			}
			foreach ( (array) ( $item['urls'] ?? array() ) as $row ) {
				$from = is_array( $row ) ? (string) ( $row['url'] ?? '' ) : '';
				if ( $from === '' ) {
					continue;
				}
				$to = $id > 0 ? self::size_url( $id, (string) ( $row['size'] ?? 'full' ), (int) ( $row['width'] ?? 0 ), (int) ( $row['height'] ?? 0 ) ) : (string) ( $got['url'] ?? '' );
				if ( $to !== '' ) {
					$this->map_url( $from, $to );
				}
			}
		}

		return $this->media_counts( array_intersect_key( $results, $items ) );
	}

	/**
	 * Give an attachment the source's title, caption, description and alt
	 * text: all of them when it was made just now, only a missing alt text
	 * when it already existed here (someone may have written their own).
	 *
	 * @param array<string, mixed> $item
	 */
	private function describe( int $id, array $item, bool $new ): void {
		$alt = trim( (string) ( $item['alt'] ?? '' ) );
		if ( $alt !== '' && ( $new || '' === trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $alt ) ) );
		}
		if ( ! $new ) {
			return;
		}
		$fields = array( 'ID' => $id );
		foreach ( array(
			'post_title'   => 'title',
			'post_excerpt' => 'caption',
			'post_content' => 'description',
		) as $field => $key ) {
			$value = (string) ( $item[ $key ] ?? '' );
			if ( $value !== '' ) {
				$fields[ $field ] = wp_slash( wp_kses_post( $value ) );
			}
		}
		if ( count( $fields ) > 1 ) {
			wp_update_post( $fields );
		}
	}

	/**
	 * The URL here of the same file of an attachment a source URL named: the
	 * size of that name, else of those dimensions, else the full file.
	 */
	private static function size_url( int $id, string $size, int $width, int $height ): string {
		$full = (string) wp_get_attachment_url( $id );
		if ( $full === '' || $size === 'full' || $size === '' ) {
			return $full;
		}
		if ( $size === 'original' ) {
			$original = function_exists( 'wp_get_original_image_url' ) ? wp_get_original_image_url( $id ) : false;

			return is_string( $original ) && $original !== '' ? $original : $full;
		}
		$meta  = wp_get_attachment_metadata( $id );
		$sizes = is_array( $meta ) && is_array( $meta['sizes'] ?? null ) ? $meta['sizes'] : array();
		$file  = (string) ( $sizes[ $size ]['file'] ?? '' );
		if ( $file === '' && $width > 0 && $height > 0 ) {
			foreach ( $sizes as $row ) {
				if ( is_array( $row ) && (int) ( $row['width'] ?? 0 ) === $width && (int) ( $row['height'] ?? 0 ) === $height ) {
					$file = (string) ( $row['file'] ?? '' );
					break;
				}
			}
		}

		return $file !== '' ? trailingslashit( dirname( $full ) ) . $file : $full;
	}

	/**
	 * A source URL and its scheme variants, mapped to one URL here.
	 */
	private function map_url( string $from, string $to ): void {
		$from = trim( $from );
		if ( $from === '' ) {
			return;
		}
		$this->urls[ $from ] = $to;
		if ( preg_match( '#^(?:https?:)?//(.+)$#i', $from, $m ) === 1 ) {
			foreach ( array( 'http://', 'https://', '//' ) as $scheme ) {
				$this->urls[ $scheme . $m[1] ] = $to;
			}
		}
	}

	/**
	 * The page's own URLs on the source site, to its URL here.
	 *
	 * @param array<int, mixed> $links
	 */
	private function map_page_links( array $links, string $permalink, int $page_id ): void {
		$to = (string) get_permalink( $page_id );
		if ( $to === '' ) {
			return;
		}
		foreach ( array_merge( $links, array( $permalink ) ) as $link ) {
			$link = is_string( $link ) ? trim( $link ) : '';
			if ( $link === '' || preg_match( '#^https?://#i', $link ) !== 1 ) {
				continue;
			}
			$this->map_url( $link, $to );
			// With and without the trailing slash of a pretty permalink.
			if ( ! str_contains( $link, '?' ) ) {
				$this->map_url( str_ends_with( $link, '/' ) ? untrailingslashit( $link ) : trailingslashit( $link ), $to );
			}
		}
	}

	/**
	 * The source site's own address, to this site's: a Home or logo link to
	 * the root of the site the page was built on points at the root here
	 * after an import (Page_Export does not warn about it, for that reason).
	 * With and without the trailing slash; map_url() adds the schemes.
	 */
	private function map_site_root( string $site_url ): void {
		$site_url = trim( $site_url );
		if ( preg_match( '#^https?://#i', $site_url ) !== 1 ) {
			return;
		}
		$home = home_url( '/' );
		$this->map_url( trailingslashit( $site_url ), $home );
		$this->map_url( untrailingslashit( $site_url ), untrailingslashit( $home ) );
		$port       = wp_parse_url( $site_url, PHP_URL_PORT );
		$this->site = array(
			'host' => strtolower( (string) wp_parse_url( $site_url, PHP_URL_HOST ) ) . ( $port ? ':' . (int) $port : '' ),
			'path' => trailingslashit( (string) wp_parse_url( $site_url, PHP_URL_PATH ) ),
		);
	}

	/**
	 * A link to another page of the source site, on this site: the page here
	 * at the same path when there is one, else this site's URL for that path
	 * (the page may be imported after this one, and the link then works). ''
	 * for anything else — another host, a path outside the source site, a
	 * query (`?page_id=` ids differ between sites), a file or a WordPress
	 * path (wp-content, wp-admin, …).
	 *
	 * Only the page's own URLs, its media and the site root used to be
	 * mapped; every other link kept the source host. For the Local → prod
	 * move that is `http://semper.local/services/` on the live site — in the
	 * page, its parts and the header menus an install writes, on every page
	 * of a classic theme.
	 */
	private function rebase( string $url ): string {
		if ( $this->site['host'] === '' || str_contains( $url, '?' ) ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) ) {
			return '';
		}
		$host = strtolower( (string) $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		$path = (string) ( $parts['path'] ?? '/' );
		if ( $host !== $this->site['host'] || ! str_starts_with( trailingslashit( $path ), $this->site['path'] ) ) {
			return '';
		}
		$relative = ltrim( substr( $path, strlen( $this->site['path'] ) ), '/' );
		if ( $relative === '' || preg_match( '#^wp-(?:content|includes|admin|json)(?:/|$)|\.[a-z0-9]{1,5}/?$#i', $relative ) === 1 ) {
			return '';
		}
		$page = get_page_by_path( untrailingslashit( $relative ), OBJECT, array( 'page' ) );
		if ( $page instanceof \WP_Post ) {
			return (string) get_permalink( $page );
		}
		$to                           = home_url( '/' . $relative );
		$this->unmatched[ $relative ] = $to;

		return $to;
	}

	/**
	 * The page this package made before (by its identity), else a new one.
	 * A new page is created private, so it is never public half-written; it
	 * gets its status when it is written in full. Private rather than draft:
	 * a draft has no permalink yet, and the rewrite needs the one it will
	 * have. Until then it carries PENDING_META, so an import that stopped
	 * before it was written leaves a page the next import finishes as a new
	 * one (write_page()).
	 *
	 * @param array<string, mixed> $page
	 * @return array{id:int, created:bool}|\WP_Error
	 */
	private function reserve_page( array $page ): array|\WP_Error {
		$found = self::owned( 'page', $this->identity );
		if ( $found > 0 ) {
			return array(
				'id'      => $found,
				'created' => '' !== (string) get_post_meta( $found, self::PENDING_META, true ),
			);
		}
		$slug = sanitize_title( (string) ( $page['slug'] ?? '' ) );
		$id   = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'private',
				'post_title'   => wp_slash( (string) $page['title'] ),
				'post_name'    => $slug,
				'post_content' => '',
				'meta_input'   => array(
					Package::KEY_META         => $this->identity,
					'_dxai_ui_generated_page' => '1',
					self::PENDING_META        => '1',
				),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return array(
			'id'      => (int) $id,
			'created' => true,
		);
	}

	/**
	 * The post a package item became here, by its key: any status but the
	 * trash (a trashed one is the person's decision, and a new one is made).
	 */
	private static function owned( string $type, string $key ): int {
		$found = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'meta_key'         => Package::KEY_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'suppress_filters' => true,
			)
		);

		return isset( $found[0] ) ? (int) $found[0] : 0;
	}

	/**
	 * Ids for patterns or navigation posts: this package's from before, or new.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, array{id:int, created:bool, key:string}> Source id => id here.
	 */
	private function reserve( array $rows, string $type, string $kind ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$old = (int) ( $row['source_id'] ?? 0 );
			if ( $old < 1 || isset( $out[ $old ] ) ) {
				continue;
			}
			$key   = $this->identity . ':' . $kind . ':' . $old;
			$found = self::owned( $type, $key );
			if ( $found > 0 ) {
				$out[ $old ] = array(
					'id'      => $found,
					'created' => false,
					'key'     => $key,
				);
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'    => $type,
					'post_status'  => 'draft',
					'post_title'   => wp_slash( '' !== (string) ( $row['title'] ?? '' ) ? (string) $row['title'] : ucfirst( $kind ) . ' ' . $old ),
					'post_content' => '',
					'meta_input'   => array( Package::KEY_META => $key ),
				),
				true
			);
			if ( is_wp_error( $id ) || ! $id ) {
				/* translators: 1: kind, 2: title. */
				$this->warnings[] = sprintf( __( 'The %1$s "%2$s" could not be created.', 'dxai-ui' ), $kind, (string) ( $row['title'] ?? '' ) );
				continue;
			}
			$out[ $old ] = array(
				'id'      => (int) $id,
				'created' => true,
				'key'     => $key,
			);
		}

		return $out;
	}

	/**
	 * The slug each packaged template part gets here: the one this package
	 * gave it before, its own when that is free, else one with a suffix of
	 * the package identity (never another design's part, rewritten).
	 *
	 * @param array<int, array<string, mixed>> $parts
	 */
	private function plan_parts( array $parts ): void {
		foreach ( $parts as $part ) {
			$slug = (string) ( $part['slug'] ?? '' );
			if ( ! str_starts_with( $slug, 'dxai-' ) || preg_match( '/^[a-z0-9-]+$/', $slug ) !== 1 ) {
				continue;
			}
			$mine = self::owned( 'wp_template_part', $this->identity . ':part:' . $slug );
			if ( $mine > 0 ) {
				$this->part_slugs[ $slug ] = (string) get_post_field( 'post_name', $mine );
				continue;
			}
			if ( self::adoptable( $slug, $this->current ) > 0 ) {
				$this->part_slugs[ $slug ] = $slug;
				continue;
			}
			$taken = get_posts(
				array(
					'post_type'      => 'wp_template_part',
					'name'           => $slug,
					'post_status'    => array( 'publish', 'draft', 'private' ),
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			// A trashed part is renamed `{slug}__trashed`; it gets its slug back
			// when its own import brings it back (revive()), so it holds it still.
			$trashed = $taken !== array() ? array() : get_posts(
				array(
					'post_type'        => 'wp_template_part',
					'post_status'      => 'trash',
					'posts_per_page'   => 1,
					'fields'           => 'ids',
					'suppress_filters' => true,
					'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'   => '_wp_desired_post_slug',
							'value' => $slug,
						),
					),
				)
			);
			$theirs = array_filter(
				array_merge( $taken, $trashed ),
				fn( $id ): bool => ! str_starts_with( (string) get_post_meta( (int) $id, Package::KEY_META, true ), $this->identity . ':' )
			);
			$this->part_slugs[ $slug ] = $theirs === array() ? $slug : substr( $slug, 0, 180 ) . '-t' . substr( $this->identity, 0, 6 );
		}
	}

	/**
	 * Rewrite a piece of the package for this site (see the class comment).
	 */
	public function rewrite( string $text ): string {
		if ( $text === '' ) {
			return $text;
		}
		$text = $this->rewrite_urls( $text );
		if ( $this->scopes !== array() ) {
			$scopes = $this->scopes;
			$text   = (string) preg_replace_callback(
				'/(?<![A-Za-z0-9_-])dxai-ui--(\d+)(?![A-Za-z0-9_-])/',
				static fn( array $m ): string => isset( $scopes[ (int) $m[1] ] ) ? 'dxai-ui--' . $scopes[ (int) $m[1] ] : $m[0],
				$text
			);
		}
		if ( str_contains( $text, '<!--' ) ) {
			$text = (string) preg_replace_callback(
				'/<!--\s+wp:((?:[a-z][a-z0-9_-]*\/)?[a-z][a-z0-9_-]*)\s+(\{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?})\s+(\/)?-->/s',
				array( $this, 'rewrite_block' ),
				$text
			);
		}
		if ( $this->attachments !== array() ) {
			$ids  = $this->attachments;
			$text = (string) preg_replace_callback(
				'/(?<![A-Za-z0-9_-])wp-image-(\d+)(?![A-Za-z0-9_-])/',
				static fn( array $m ): string => isset( $ids[ (int) $m[1] ] ) ? 'wp-image-' . $ids[ (int) $m[1] ] : $m[0],
				$text
			);
			$text = (string) preg_replace_callback(
				'/\bdata-id="(\d+)"/',
				static fn( array $m ): string => isset( $ids[ (int) $m[1] ] ) ? 'data-id="' . $ids[ (int) $m[1] ] . '"' : $m[0],
				$text
			);
		}

		return $text;
	}

	/**
	 * One block comment delimiter, its ids and slugs mapped. Serialised
	 * again only when something changed; every other delimiter is left
	 * byte for byte.
	 *
	 * @param array<int, string> $m
	 */
	public function rewrite_block( array $m ): string {
		$name  = str_contains( $m[1], '/' ) ? $m[1] : 'core/' . $m[1];
		$attrs = json_decode( $m[2], true );
		if ( ! is_array( $attrs ) ) {
			return $m[0];
		}
		$changed = false;
		$swap    = static function ( array &$attrs, string $key, array $map ) use ( &$changed ): void {
			if ( isset( $attrs[ $key ] ) && is_numeric( $attrs[ $key ] ) && isset( $map[ (int) $attrs[ $key ] ] ) ) {
				$attrs[ $key ] = $map[ (int) $attrs[ $key ] ];
				$changed       = true;
			}
		};
		if ( $name === 'core/block' ) {
			$swap( $attrs, 'ref', $this->patterns );
		} elseif ( $name === 'dxai-ui/pattern' ) {
			$swap( $attrs, 'patternId', $this->patterns );
		} elseif ( $name === 'core/navigation' ) {
			$swap( $attrs, 'ref', $this->navigations );
		} elseif ( $name === 'core/template-part' ) {
			$slug = (string) ( $attrs['slug'] ?? '' );
			if ( str_starts_with( $slug, 'dxai-' ) ) {
				if ( isset( $this->part_slugs[ $slug ] ) && $this->part_slugs[ $slug ] !== $slug ) {
					$attrs['slug'] = $this->part_slugs[ $slug ];
					$changed       = true;
				}
				if ( ( $attrs['theme'] ?? '' ) !== get_stylesheet() ) {
					$attrs['theme'] = get_stylesheet();
					$changed        = true;
				}
			}
		}
		foreach ( Page_Export::MEDIA_ID_ATTRS[ $name ] ?? array() as $key ) {
			if ( $key === 'ids' && is_array( $attrs['ids'] ?? null ) ) {
				foreach ( $attrs['ids'] as $i => $id ) {
					if ( is_numeric( $id ) && isset( $this->attachments[ (int) $id ] ) ) {
						$attrs['ids'][ $i ] = $this->attachments[ (int) $id ];
						$changed            = true;
					}
				}
				continue;
			}
			$swap( $attrs, $key, $this->attachments );
		}
		if ( ! $changed ) {
			return $m[0];
		}

		return '<!-- wp:' . $m[1] . ' ' . serialize_block_attributes( $attrs ) . ' ' . ( ( $m[3] ?? '' ) === '/' ? '/' : '' ) . '-->';
	}

	/**
	 * Every source URL in a text, looked up whole — so a URL is never
	 * rewritten as the prefix of a longer one. Absolute (any scheme or none),
	 * JSON-escaped, and root-relative uploads paths.
	 */
	private function rewrite_urls( string $text ): string {
		if ( $this->urls === array() ) {
			return $text;
		}
		$chars = '[^\s"\'<>()\\\\?#,;&*]';
		foreach ( $this->source['hosts'] as $host ) {
			$h = preg_quote( $host, '~' );
			if ( ! str_contains( strtolower( $text ), strtolower( $host ) ) ) {
				continue;
			}
			// Plain: scheme, host, path, and a query (a ?page_id= link).
			$text = (string) preg_replace_callback(
				'~(?:https?:)?//' . $h . '(?:/' . $chars . '*)*(?:\?[^\s"\'<>()\\\\#]*)?~i',
				fn( array $m ): string => $this->url_lookup( $m[0], false ),
				$text
			);
			// JSON-escaped: `http:\/\/host\/path`.
			$text = (string) preg_replace_callback(
				'~(?:https?:)?\\\\/\\\\/' . $h . '(?:\\\\/' . $chars . '*)*~i',
				fn( array $m ): string => $this->url_lookup( $m[0], true ),
				$text
			);
		}
		if ( $this->source['path'] !== '' && str_contains( $text, $this->source['path'] . '/' ) ) {
			$text = (string) preg_replace_callback(
				'~(?<![A-Za-z0-9_.:/\\\\-])' . preg_quote( $this->source['path'], '~' ) . '/' . $chars . '+~',
				fn( array $m ): string => $this->url_lookup( $m[0], false ),
				$text
			);
		}

		return $text;
	}

	/**
	 * The URL here for one source URL found in a text, or the URL as it was.
	 * A trailing dot (end of a sentence) and a query string (a cache buster)
	 * are kept around the mapped URL.
	 */
	private function url_lookup( string $found, bool $escaped ): string {
		$plain = $escaped ? str_replace( '\\/', '/', $found ) : $found;
		$tail  = '';
		while ( str_ends_with( $plain, '.' ) ) {
			$plain = substr( $plain, 0, -1 );
			$tail .= '.';
		}
		$query = '';
		if ( ! isset( $this->urls[ $plain ] ) && str_contains( $plain, '?' ) ) {
			$at    = (int) strpos( $plain, '?' );
			$query = substr( $plain, $at );
			$plain = substr( $plain, 0, $at );
		}
		if ( ! isset( $this->urls[ $plain ] ) ) {
			$rebased = $query === '' ? $this->rebase( $plain ) : '';
			if ( $rebased === '' ) {
				return $found;
			}
			// Remembered whole, like every other mapping: the same link is in
			// the page, its parts and the menus.
			$this->urls[ $plain ] = $rebased;
		}
		$to = $this->urls[ $plain ] . $query . $tail;

		return $escaped ? str_replace( '/', '\\/', $to ) : $to;
	}

	/**
	 * Rewrite a meta value, strings inside arrays included.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private function rewrite_value( $value ) {
		if ( is_string( $value ) ) {
			return $this->rewrite( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = $this->rewrite_value( $v );
			}
		}

		return $value;
	}

	/**
	 * The meta a package may set on a post: `_dxai_ui_*` keys (and the extra
	 * ones named), rewritten; nothing else — a package cannot write
	 * `_wp_attached_file`, capabilities or any other plugin's data.
	 *
	 * @param mixed              $meta
	 * @param array<int, string> $extra
	 * @return array<string, mixed>
	 */
	private function allowed_meta( $meta, array $extra = array() ): array {
		$out = array();
		foreach ( is_array( $meta ) ? $meta : array() as $key => $value ) {
			$key = (string) $key;
			if ( in_array( $key, self::SKIP_META, true ) ) {
				continue;
			}
			if ( preg_match( '/^_dxai_ui_[a-z0-9_]+$/', $key ) !== 1 && ! in_array( $key, $extra, true ) ) {
				continue;
			}
			$out[ $key ] = $this->rewrite_value( $value );
			if ( '_dxai_ui_font_urls' === $key ) {
				$out[ $key ] = $this->font_urls( $out[ $key ] );
			}
		}

		return $out;
	}

	/**
	 * The font stylesheets a package may make its page load: each is linked
	 * from every view of the page, so only one on a font service the
	 * converter reads designs from, or on this site (a font stored as a
	 * design asset, its URL mapped here). Anything else is left out and named
	 * in the result, never loaded silently — a package from someone else
	 * could make the page call any server on every visit. More hosts can be
	 * allowed with the `dxai_ui_transfer_font_hosts` filter.
	 *
	 * @param mixed $urls
	 * @return array<int, string>
	 */
	private function font_urls( $urls ): array {
		/**
		 * Hosts a packaged page may load font stylesheets from.
		 *
		 * @param array<int, string> $hosts
		 */
		$hosts = array_map( 'strtolower', (array) apply_filters( 'dxai_ui_transfer_font_hosts', self::FONT_HOSTS ) );
		foreach ( array( home_url( '/' ), site_url( '/' ), (string) ( wp_upload_dir( null, false )['baseurl'] ?? '' ) ) as $own ) {
			$host = strtolower( (string) wp_parse_url( $own, PHP_URL_HOST ) );
			if ( $host !== '' ) {
				$hosts[] = $host;
			}
		}
		$out = array();
		foreach ( is_array( $urls ) ? $urls : array() as $url ) {
			$url  = is_string( $url ) ? trim( $url ) : '';
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( $url === '' ) {
				continue;
			}
			if ( preg_match( '#^https?://#i', $url ) === 1 && in_array( $host, $hosts, true ) ) {
				$out[] = $url;
				continue;
			}
			$this->warnings[] = sprintf(
				/* translators: 1: stylesheet URL, 2: host. */
				__( 'The page asked to load the font stylesheet %1$s, from %2$s, which is not a font service this plugin knows; it is not loaded. If you trust it, allow the host with the dxai_ui_transfer_font_hosts filter and import again.', 'dxai-ui' ),
				esc_url_raw( $url ),
				$host !== '' ? $host : __( 'no host', 'dxai-ui' )
			);
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Set a post's meta to exactly these `_dxai_ui_*` keys: each written, and
	 * one the post carries that the package no longer has removed (a flag the
	 * source dropped since the last import, like the static-HTML one, would
	 * otherwise keep changing how the page renders). Other keys are untouched.
	 *
	 * @param array<string, mixed> $meta
	 * @param array<int, string>   $keep Keys set elsewhere, left alone.
	 */
	private static function set_meta( int $post_id, array $meta, array $keep = array() ): void {
		foreach ( array_keys( (array) get_post_meta( $post_id ) ) as $key ) {
			$key = (string) $key;
			if ( str_starts_with( $key, '_dxai_ui_' ) && ! array_key_exists( $key, $meta ) && ! in_array( $key, $keep, true ) && ! in_array( $key, self::SKIP_META, true ) ) {
				delete_post_meta( $post_id, $key );
			}
		}
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}
	}

	/**
	 * Write the synced patterns: content, meta and their own stylesheet.
	 *
	 * @param array<int, array<string, mixed>>                           $rows
	 * @param array<int, array{id:int, created:bool, key:string}>        $ids
	 * @return array<int, array<string, mixed>>
	 */
	private function write_patterns( array $rows, array $ids, string $dir ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$old = (int) ( $row['source_id'] ?? 0 );
			if ( ! isset( $ids[ $old ] ) ) {
				continue;
			}
			$id      = $ids[ $old ]['id'];
			$content = $this->rewrite( (string) ( $row['content'] ?? '' ) );
			$status  = in_array( (string) ( $row['status'] ?? '' ), self::STATUSES, true ) ? (string) $row['status'] : 'publish';
			wp_update_post(
				array(
					'ID'           => $id,
					'post_title'   => wp_slash( (string) ( $row['title'] ?? '' ) ),
					'post_content' => wp_slash( $content ),
					'post_status'  => $status,
				)
			);
			$meta                               = $this->allowed_meta( $row['meta'] ?? array(), array( 'wp_pattern_sync_status' ) );
			$meta['_dxai_ui_generated']         = '1';
			$meta[ Package::KEY_META ]          = $ids[ $old ]['key'];
			unset( $meta['_dxai_ui_css_url'] );
			self::set_meta( $id, $meta, array( '_dxai_ui_css_url' ) );
			if ( ! array_key_exists( 'wp_pattern_sync_status', $meta ) ) {
				delete_post_meta( $id, 'wp_pattern_sync_status' );
			}

			$css = (string) ( $row['css'] ?? '' );
			if ( $css !== '' ) {
				$url = ( new \DXAI_UI\Compiler\Tailwind_Purger() )->persist( $id, $this->rewrite( (string) file_get_contents( $dir . '/' . $css ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( ! is_wp_error( $url ) ) {
					update_post_meta( $id, '_dxai_ui_css_url', Upload_Paths::for_storage( $url ) );
				}
			} else {
				delete_post_meta( $id, '_dxai_ui_css_url' );
			}
			$out[] = array(
				'id'        => $id,
				'source_id' => $old,
				'title'     => (string) ( $row['title'] ?? '' ),
				'created'   => $ids[ $old ]['created'],
				'content'   => $content,
			);
		}

		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>>                    $rows
	 * @param array<int, array{id:int, created:bool, key:string}> $ids
	 * @return array<int, array<string, mixed>>
	 */
	private function write_navigations( array $rows, array $ids ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$old = (int) ( $row['source_id'] ?? 0 );
			if ( ! isset( $ids[ $old ] ) ) {
				continue;
			}
			$id = $ids[ $old ]['id'];
			wp_update_post(
				array(
					'ID'           => $id,
					'post_title'   => wp_slash( (string) ( $row['title'] ?? '' ) ),
					'post_content' => wp_slash( $this->rewrite( (string) ( $row['content'] ?? '' ) ) ),
					'post_status'  => 'publish',
				)
			);
			$meta                      = $this->allowed_meta( $row['meta'] ?? array() );
			$meta[ Package::KEY_META ] = $ids[ $old ]['key'];
			self::set_meta( $id, $meta );
			$out[] = array(
				'id'        => $id,
				'source_id' => $old,
				'title'     => (string) ( $row['title'] ?? '' ),
				'created'   => $ids[ $old ]['created'],
			);
		}

		return $out;
	}

	/**
	 * The header and footer, as chosen (see the class comment).
	 *
	 * @param array<string, mixed> $manifest
	 * @return array{content:string, header:array<string, mixed>, footer:array<string, mixed>, part_content:array<string, string>}
	 */
	private function chrome( array $manifest, string $content, int $page_id, string $mode ): array {
		$parts = array();
		foreach ( (array) $manifest['parts'] as $part ) {
			$parts[ (string) ( $part['slug'] ?? '' ) ] = $part;
		}
		$page    = (array) $manifest['page'];
		$title   = (string) $page['title'];
		$archive = (string) ( $page['meta']['_dxai_ui_source_zip'] ?? '' );
		$extra   = array();
		$out     = array(
			'content'      => $content,
			'part_content' => array(),
		);

		foreach ( array( 'header', 'footer' ) as $area ) {
			$spec  = is_array( $manifest['chrome'][ $area ] ?? null ) ? $manifest['chrome'][ $area ] : array( 'mode' => 'none' );
			$kind  = (string) ( $spec['mode'] ?? 'none' );
			$slug  = '';
			if ( $kind === 'part' ) {
				$slug   = (string) ( $spec['slug'] ?? '' );
				$markup = isset( $parts[ $slug ] ) ? $this->rewrite( (string) ( $parts[ $slug ]['content'] ?? '' ) ) : '';
			} elseif ( $kind === 'block' ) {
				$markup = $this->rewrite( (string) ( $spec['markup'] ?? '' ) );
			} else {
				$out[ $area ] = array(
					'mode' => 'none',
					'kept' => '',
				);
				continue;
			}
			if ( $markup === '' ) {
				/* translators: %s: header or footer. */
				$this->warnings[] = sprintf( __( 'The package names a %s but carries no markup for it.', 'dxai-ui' ), $area );
				$out[ $area ]     = array(
					'mode' => 'none',
					'kept' => '',
				);
				continue;
			}

			$answer = null;
			if ( $mode === self::CHROME_INSTALL ) {
				// The part this page keeps the area in when it is not installed:
				// the footer's is the one a later design's install hands back.
				$part_slug = 'part' === $kind ? (string) ( $this->part_slugs[ $slug ] ?? $slug ) : $this->block_part_slug( $area, $archive );
				$answer    = $this->install( $area, $markup, $page_id, $title, $archive, $spec, $part_slug );
				if ( is_string( $answer['block_markup'] ?? null ) && $answer['block_markup'] !== '' ) {
					if ( $kind === 'part' ) {
						$out['content'] = $this->swap_part( $out['content'], $this->part_slugs[ $slug ] ?? $slug, (string) $answer['block_markup'] );
					}
					$out[ $area ] = array(
						'mode'   => 'installed',
						'where'  => 'header' === $area ? 'menus' : 'widgets',
						'answer' => $answer,
					) + self::answer_summary( $area, $answer );
					continue;
				}
				$message          = (string) ( $answer['message'] ?? ( $answer['error'] ?? __( 'it was refused', 'dxai-ui' ) ) );
				$this->warnings[] = sprintf(
					/* translators: 1: header or footer, 2: reason. */
					__( 'The %1$s was not installed into Appearance and stays a template part of the page: %2$s', 'dxai-ui' ),
					'header' === $area ? __( 'header', 'dxai-ui' ) : __( 'footer', 'dxai-ui' ),
					$message
				);
			}

			// Kept: the page's own header or footer, as a template part.
			if ( $kind === 'block' ) {
				if ( 'header' === $area && is_array( $spec['menus'] ?? null ) ) {
					$markup = $this->header_as_shown( $markup, (array) $spec['menus'] );
				}
				$slug                               = $this->block_part_slug( $area, $archive );
				$this->part_slugs[ $slug ]          = $slug;
				$out['part_content'][ $slug ]       = $markup;
				$extra[ $slug ]                     = array(
					'slug'  => $slug,
					'area'  => $area,
					'title' => $title . ' ' . ( 'header' === $area ? __( 'Header', 'dxai-ui' ) : __( 'Footer', 'dxai-ui' ) ),
					'meta'  => array( '_dxai_ui_area' => $area ),
				);
				$ref                                = sprintf(
					'<!-- wp:template-part %s /-->',
					(string) wp_json_encode(
						array(
							'slug'  => $slug,
							'theme' => get_stylesheet(),
							'area'  => $area,
						)
					)
				);
				$name           = 'header' === $area ? Site_Header_Block::NAME : Site_Footer_Block::NAME;
				$out['content'] = (string) preg_replace( '/<!--\s+wp:' . preg_quote( $name, '/' ) . '(?:\s+\{.*?\})?\s+\/-->/s', $ref, $out['content'] );
			}
			$out[ $area ] = array(
				'mode'   => 'part',
				'part'   => $this->part_slugs[ $slug ] ?? $slug,
				'reason' => $mode === self::CHROME_INSTALL ? 'refused' : 'keep',
				'answer' => $answer,
			);
		}
		$this->extra_parts = $extra;

		return $out;
	}

	/** @var array<string, array<string, mixed>> Parts made here from a packaged header/footer block (keep). */
	private array $extra_parts = array();

	/** The page's content here before this import writes it ('' for a new page). */
	private string $current = '';

	/**
	 * A part a later design's install wrote for this page when it gave the
	 * page its footer back (Structure_Repository::hand_back_footer()): under
	 * this page's own part slug, generated by this plugin, carrying no
	 * package key, and referenced by this page now. Adopted, so a re-import
	 * updates it in place rather than writing a second part beside it. A part
	 * of the same slug that this page does not reference — a ZIP import's of
	 * the same design — is never taken.
	 */
	private static function adoptable( string $slug, string $current ): int {
		if ( $slug === '' || ! str_contains( $current, '"slug":"' . $slug . '"' ) ) {
			return 0;
		}
		$found = get_posts(
			array(
				'post_type'        => 'wp_template_part',
				'name'             => $slug,
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => '_dxai_ui_generated',
						'value' => '1',
					),
					array(
						'key'     => Package::KEY_META,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		return isset( $found[0] ) ? (int) $found[0] : 0;
	}

	/**
	 * What a header/footer install() answered, in a few fields for the result.
	 *
	 * @param array<string, mixed> $answer
	 * @return array<string, mixed>
	 */
	private static function answer_summary( string $area, array $answer ): array {
		if ( 'header' === $area ) {
			$menus = array();
			foreach ( (array) ( $answer['menus'] ?? array() ) as $location => $id ) {
				$term               = wp_get_nav_menu_object( (int) $id );
				$menus[ $location ] = array(
					'id'   => (int) $id,
					'name' => $term instanceof \WP_Term ? wp_specialchars_decode( $term->name, ENT_QUOTES ) : '',
				);
			}

			return array(
				'menus'          => $menus,
				'locations_kept' => (array) ( $answer['locations_kept'] ?? array() ),
				'logo_set'       => ! empty( $answer['logo']['set'] ),
				'replaced'       => (array) ( $answer['replaced'] ?? array() ),
			);
		}

		return array(
			'areas'       => (array) ( $answer['areas'] ?? array() ),
			'created'     => (int) ( $answer['created'] ?? 0 ),
			'reused'      => (int) ( $answer['reused'] ?? 0 ),
			'inactive'    => (array) ( $answer['inactive'] ?? array() ),
			'replaced'    => (array) ( $answer['replaced'] ?? array() ),
			// The pages of the design whose footer the areas held, which keep it as a template part.
			'handed_back' => array_map( 'intval', (array) ( $answer['handed_back']['pages'] ?? array() ) ),
		);
	}

	/**
	 * Install a header into Menus or a footer into Widgets, through the
	 * Chrome APIs, never letting one throw the import away — the way a ZIP
	 * import installs them (Structure_Repository::install_chrome()): the
	 * header takes the site's menu locations (take_site), and the footer goes
	 * through Structure_Repository::install_site_footer(), which first gives
	 * the pages of the design whose footer the widget areas hold their footer
	 * back as a template part, and records whose the footer is now (owner,
	 * part key) for the next design that takes the areas.
	 *
	 * @param array<string, mixed> $spec      The packaged chrome entry.
	 * @param string               $part_slug The template part this page keeps the area in otherwise.
	 * @return array<string, mixed>
	 */
	private function install( string $area, string $markup, int $page_id, string $title, string $archive, array $spec, string $part_slug ): array {
		try {
			if ( 'header' === $area ) {
				$owner  = $this->page_key;
				$logo   = (string) ( $spec['logo'] ?? '' );
				$answer = Header_Menus::install(
					$markup,
					array(
						'title'              => $title,
						'archive'            => $archive,
						'page_id'            => $page_id,
						'scope_id'           => $page_id,
						'owner'              => $owner,
						'adopt_legacy'       => false,
						'take_site'          => true,
						'logo_attachment_id' => $logo !== '' && preg_match( '/^a(\d+)$/', $logo, $m ) === 1 ? (int) ( $this->attachments[ (int) $m[1] ] ?? 0 ) : 0,
					)
				);
				if ( is_array( $answer ) && ( $answer['block_markup'] ?? '' ) !== '' && is_array( $spec['menus'] ?? null ) ) {
					$this->apply_menus( $answer, (array) $spec['menus'], $markup, $owner, $archive );
				}

				return is_array( $answer ) ? $answer : array( 'block_markup' => '' );
			}
			// The key Template_Part_Factory names this page's footer part by
			// (dxai-footer-{key}): a hand-back writes that same part.
			$prefix  = 'dxai-footer-';
			$context = array(
				'page_id'  => $page_id,
				'title'    => $title,
				'source'   => $archive,
				'owner'    => $this->page_key,
				'part_key' => str_starts_with( $part_slug, $prefix ) ? substr( $part_slug, strlen( $prefix ) ) : '',
			);
			$answer  = method_exists( \DXAI_UI\Structures\Structure_Repository::class, 'install_site_footer' )
				? \DXAI_UI\Structures\Structure_Repository::install_site_footer( $markup, $context )
				: Footer_Widgets::install( $markup, $context + array( 'scope' => $page_id ) );

			return is_array( $answer ) ? $answer : array( 'block_markup' => '' );
		} catch ( \Throwable $e ) {
			return array(
				'block_markup' => '',
				'error'        => 'exception',
				'message'      => $e->getMessage(),
			);
		}
	}

	/**
	 * The menu items the source site's header showed, when they are not the
	 * design's own (someone edited the menus there): written into the menus
	 * install() just wrote, in place, the way a re-import writes them.
	 *
	 * @param array<string, mixed> $answer   Header_Menus::install()'s answer.
	 * @param array<string, mixed> $packaged location => {name, items}.
	 */
	private function apply_menus( array $answer, array $packaged, string $markup, string $owner, string $archive ): void {
		if ( ! class_exists( \DXAI_UI\Structures\Navigation_Factory::class ) || ! method_exists( \DXAI_UI\Structures\Navigation_Factory::class, 'header_menu' ) ) {
			return;
		}
		$design = (array) ( Header_Template::build( $markup )['trees'] ?? array() );
		foreach ( $packaged as $location => $menu ) {
			$location = (string) $location;
			$menu_id  = (int) ( $answer['menus'][ $location ] ?? 0 );
			$items    = is_array( $menu ) && is_array( $menu['items'] ?? null ) ? $this->menu_tree( $menu['items'] ) : null;
			if ( $menu_id < 1 || null === $items ) {
				continue;
			}
			if ( Header_Template::tree_sig( $items ) === Header_Template::tree_sig( (array) ( $design[ $location ] ?? array() ) ) ) {
				continue;
			}
			$term = wp_get_nav_menu_object( $menu_id );
			( new \DXAI_UI\Structures\Navigation_Factory( $owner, (string) preg_replace( '/\.zip$/i', '', $archive ), false ) )->header_menu(
				$term instanceof \WP_Term ? (string) $term->name : (string) ( $menu['name'] ?? '' ),
				$items,
				$location,
				array(),
				'',
				$menu_id,
				false
			);
		}
	}

	/**
	 * Packaged menu rows, their URLs rewritten, in the shape
	 * Navigation_Factory::header_menu() reads.
	 *
	 * @param array<int, mixed> $rows
	 * @return array<int, array<string, mixed>>
	 */
	private function menu_tree( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = array(
				'label'       => (string) ( $row['label'] ?? '' ),
				'url'         => $this->rewrite_urls( (string) ( $row['url'] ?? '' ) ),
				'target'      => (string) ( $row['target'] ?? '' ),
				'xfn'         => (string) ( $row['xfn'] ?? '' ),
				'attr_title'  => (string) ( $row['attr_title'] ?? '' ),
				'description' => (string) ( $row['description'] ?? '' ),
				'children'    => $this->menu_tree( (array) ( $row['children'] ?? array() ) ),
			);
		}

		return $out;
	}

	/**
	 * A packaged site header as the source site showed it, for the template
	 * part a kept header becomes: its template filled from the packaged menus,
	 * the way Site_Header_Block renders it there. The template alone holds
	 * the design's own items, so the menus someone edited on the source
	 * (pages added, a CTA changed) travelled only with an install
	 * (apply_menus()), while a kept footer is packaged as it renders
	 * (Page_Export::footer()). A menu still exactly the design's leaves the
	 * markup byte for byte.
	 *
	 * @param array<string, mixed> $packaged location => {name, items}.
	 */
	private function header_as_shown( string $markup, array $packaged ): string {
		if ( ! class_exists( \DXAI_UI\Chrome\Header_Renderer::class ) ) {
			return $markup;
		}
		$spec   = Header_Template::build( $markup );
		$design = (array) ( $spec['trees'] ?? array() );
		$menus  = array();
		foreach ( $packaged as $location => $menu ) {
			$rows = is_array( $menu ) && is_array( $menu['items'] ?? null ) ? $menu['items'] : null;
			if ( null === $rows || Header_Template::tree_sig( $this->menu_tree( $rows ) ) === Header_Template::tree_sig( (array) ( $design[ (string) $location ] ?? array() ) ) ) {
				continue;
			}
			$menus[ (string) $location ] = $this->menu_nodes( $rows );
		}
		if ( $menus === array() ) {
			return $markup;
		}

		return \DXAI_UI\Chrome\Header_Renderer::markup( ( new \DXAI_UI\Chrome\Header_Renderer( $spec, $menus ) )->root() );
	}

	/**
	 * Packaged menu rows as the nodes Header_Renderer draws (Header_Menus::
	 * items_tree()'s shape): an item object with the fields it reads.
	 *
	 * @param array<int, mixed> $rows
	 * @return array<int, array<string, mixed>>
	 */
	private function menu_nodes( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = array(
				'item'     => (object) array(
					'title'       => (string) ( $row['label'] ?? '' ),
					'url'         => $this->rewrite_urls( (string) ( $row['url'] ?? '' ) ),
					'target'      => (string) ( $row['target'] ?? '' ),
					'xfn'         => (string) ( $row['xfn'] ?? '' ),
					'attr_title'  => (string) ( $row['attr_title'] ?? '' ),
					'description' => (string) ( $row['description'] ?? '' ),
					'classes'     => preg_split( '/\s+/', trim( (string) ( $row['classes'] ?? '' ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: array(),
				),
				'children' => $this->menu_nodes( (array) ( $row['children'] ?? array() ) ),
			);
		}

		return $out;
	}

	/**
	 * Replace the reference to one template part with a block.
	 */
	private function swap_part( string $content, string $slug, string $block ): string {
		return (string) preg_replace_callback(
			'/<!--\s*wp:template-part\s+(\{.*?\})\s*\/-->/s',
			static function ( array $m ) use ( $slug, $block ): string {
				$attrs = json_decode( $m[1], true );

				return is_array( $attrs ) && (string) ( $attrs['slug'] ?? '' ) === $slug ? $block : $m[0];
			},
			$content
		);
	}

	/**
	 * The slug of the part a packaged site header or footer block becomes
	 * when the site's own are kept: named after the design's archive the
	 * way an import names its parts, so it is the same part every time.
	 */
	private function block_part_slug( string $area, string $archive ): string {
		$base  = (string) preg_replace( '/\.zip$/i', '', trim( $archive ) );
		$ascii = strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '-', $base ) );
		$ascii = trim( substr( trim( $ascii, '-' ), 0, 40 ), '-' );
		$key   = ( $ascii !== '' ? $ascii : 'page' ) . '-t' . substr( $this->identity, 0, 8 );

		return 'dxai-' . $area . '-' . $key;
	}

	/**
	 * Write the template parts the page (or its patterns, or another part)
	 * still references, bound to this site's theme.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @param array<int, string>               $referrers Rewritten content that may reference them.
	 * @param array<string, string>            $made      Slug => markup of parts made here (keep).
	 * @return array<int, array<string, mixed>>
	 */
	private function write_parts( array $rows, array $referrers, array $made ): array {
		$todo = array();
		foreach ( $rows as $row ) {
			$slug = (string) ( $row['slug'] ?? '' );
			if ( isset( $this->part_slugs[ $slug ] ) ) {
				$todo[ $this->part_slugs[ $slug ] ] = array(
					'row'     => $row,
					'content' => $this->rewrite( (string) ( $row['content'] ?? '' ) ),
				);
			}
		}
		foreach ( $this->extra_parts as $slug => $row ) {
			$todo[ $slug ] = array(
				'row'     => $row,
				'content' => (string) ( $made[ $slug ] ?? '' ),
			);
		}

		// Only what is referenced: a part the header install replaced is not
		// written at all. Parts reference parts, so until nothing changes.
		$write = array();
		$texts = implode( "\n", $referrers );
		do {
			$added = false;
			foreach ( $todo as $slug => $item ) {
				if ( ! isset( $write[ $slug ] ) && str_contains( $texts, '"slug":"' . $slug . '"' ) ) {
					$write[ $slug ] = $item;
					$texts         .= "\n" . $item['content'];
					$added          = true;
				}
			}
		} while ( $added );

		$out = array();
		foreach ( $write as $slug => $item ) {
			$row  = (array) $item['row'];
			$area = in_array( (string) ( $row['area'] ?? '' ), array( 'header', 'footer' ), true ) ? (string) $row['area'] : 'uncategorized';
			$old  = (string) ( $row['slug'] ?? $slug );
			$key  = $this->identity . ':part:' . $old;
			$id   = self::owned( 'wp_template_part', $key );
			$id   = $id > 0 ? $id : self::revive( $key );
			$id   = $id > 0 ? $id : self::adoptable( $slug, $this->current );
			$data = array(
				'post_type'    => 'wp_template_part',
				'post_status'  => 'publish',
				'post_title'   => wp_slash( (string) ( $row['title'] ?? $slug ) ),
				'post_name'    => $slug,
				'post_content' => wp_slash( (string) $item['content'] ),
			);
			$created = $id < 1;
			if ( ! $created ) {
				$data['ID'] = $id;
				$id         = wp_update_post( $data, true );
			} else {
				$id = wp_insert_post( $data, true );
			}
			if ( is_wp_error( $id ) || ! $id ) {
				/* translators: %s: part slug. */
				$this->warnings[] = sprintf( __( 'The template part %s could not be written.', 'dxai-ui' ), $slug );
				continue;
			}
			$id                          = (int) $id;
			$meta                        = $this->allowed_meta( $row['meta'] ?? array() );
			$meta['_dxai_ui_generated']  = '1';
			$meta['_dxai_ui_area']       = $area;
			$meta[ Package::KEY_META ]   = $key;
			self::set_meta( $id, $meta );
			if ( taxonomy_exists( 'wp_theme' ) ) {
				wp_set_object_terms( $id, get_stylesheet(), 'wp_theme' );
			}
			if ( taxonomy_exists( 'wp_template_part_area' ) ) {
				wp_set_object_terms( $id, $area, 'wp_template_part_area' );
			}
			$out[] = array(
				'id'      => $id,
				'slug'    => $slug,
				'area'    => $area,
				'created' => $created,
			);
		}

		return $out;
	}

	/**
	 * The part an earlier import of this package moved to the trash (its
	 * header went into Menus then), brought back to be updated in place — its
	 * id and revisions kept — rather than a second part written beside it.
	 */
	private static function revive( string $key ): int {
		$trashed = get_posts(
			array(
				'post_type'        => 'wp_template_part',
				'post_status'      => 'trash',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'DESC',
				'meta_key'         => Package::KEY_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'suppress_filters' => true,
			)
		);
		if ( ! isset( $trashed[0] ) ) {
			return 0;
		}
		$id = (int) $trashed[0];
		// Published again, as it was: core restores a trashed post as a draft.
		$publish = static fn( $status, $post_id ) => (int) $post_id === $id ? 'publish' : $status;
		add_filter( 'wp_untrash_post_status', $publish, 10, 2 );
		$done = wp_untrash_post( $id );
		remove_filter( 'wp_untrash_post_status', $publish, 10 );

		return $done ? $id : 0;
	}

	/**
	 * Parts an earlier import of this package made that nothing references
	 * any more (the header went into Menus this time): to the trash — only
	 * this package's own, and only when the site keeps a trash. With
	 * EMPTY_TRASH_DAYS set to 0, wp_trash_post() deletes the post for good
	 * (and with it any edit a person made to it in the Site Editor), so the
	 * part stays published instead, unreferenced — the same rule as a ZIP
	 * import (Structure_Repository::can_trash()). Nothing here depends on the
	 * trash: a part left published is found by its key and updated in place
	 * by the next import, as a trashed one is brought back.
	 *
	 * @param array<int, int> $current Parts written by this import.
	 * @return array{retired:array<int, int>, kept:array<int, int>} Trashed part ids, and those left published for want of a trash.
	 */
	private function retire_parts( int $page_id, array $current ): array {
		global $wpdb;

		$like = $wpdb->esc_like( $this->identity . ':part:' ) . '%';
		$ids  = array_map(
			'intval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE m.meta_value LIKE %s AND p.post_type = 'wp_template_part' AND p.post_status NOT IN ('trash','auto-draft','inherit')",
					Package::KEY_META,
					$like
				)
			)
		);
		$out = array(
			'retired' => array(),
			'kept'    => array(),
		);
		foreach ( array_diff( $ids, array_map( 'intval', $current ) ) as $id ) {
			$slug   = (string) get_post_field( 'post_name', $id );
			$needle = '%' . $wpdb->esc_like( '"slug":"' . $slug . '"' ) . '%';
			$users  = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s AND ID <> %d AND post_type NOT IN ('revision','wp_template_part') AND post_status NOT IN ('trash','auto-draft','inherit')",
					$needle,
					$page_id
				)
			);
			if ( $users !== array() ) {
				continue;
			}
			if ( ! self::can_trash() ) {
				$out['kept'][] = $id;
				continue;
			}
			if ( wp_trash_post( $id ) ) {
				$out['retired'][] = $id;
			}
		}

		return $out;
	}

	/**
	 * Whether this site keeps a trash (see retire_parts()).
	 */
	private static function can_trash(): bool {
		return ! defined( 'EMPTY_TRASH_DAYS' ) || (bool) EMPTY_TRASH_DAYS;
	}

	/**
	 * Write the page: content, template, meta, stylesheet, script — and last
	 * its status, so it is never public before its stylesheet and script are.
	 *
	 * The status: a page this package creates (now, or in an import that
	 * stopped before this point) is a draft, unless the importer asked to
	 * publish it — then it gets the source's. A package is someone's HTML, CSS
	 * and JavaScript; a draft is looked at (its preview) before it is public.
	 * A page it updates keeps the status it has here, which is this site's
	 * decision (published after the first import, say).
	 *
	 * The script is put on this site's motion runtime (Motion_Runtime::
	 * refresh()): the runtime inside it is the exporting plugin's, and this
	 * site's refresh of stored scripts has already run for its own version —
	 * unless the package comes from a newer plugin than this one.
	 *
	 * @param array<string, mixed> $page
	 * @param array<string, mixed> $manifest
	 * @return array<string, mixed> What was written for the design (css, js), and created: whether the page is new.
	 */
	private function write_page( int $page_id, array $page, string $content, array $manifest, string $dir, bool $created, bool $publish ): array {
		$new    = $created || '' !== (string) get_post_meta( $page_id, self::PENDING_META, true );
		$source = in_array( (string) ( $page['status'] ?? '' ), self::STATUSES, true ) ? (string) $page['status'] : 'publish';
		$status = $new ? ( $publish ? $source : 'draft' ) : (string) get_post_status( $page_id );
		wp_update_post(
			array(
				'ID'           => $page_id,
				'post_title'   => wp_slash( (string) $page['title'] ),
				'post_content' => wp_slash( $content ),
				'post_excerpt' => wp_slash( (string) ( $page['excerpt'] ?? '' ) ),
				'menu_order'   => (int) ( $page['menu_order'] ?? 0 ),
			)
		);

		$meta = $this->allowed_meta( $page['meta'] ?? array() );
		unset( $meta['_dxai_ui_css_url'], $meta['_dxai_ui_js_url'] );
		$meta['_dxai_ui_generated_page'] = '1';
		$meta[ Page_Scope::META ]        = $page_id;
		$meta[ Package::KEY_META ]       = $this->identity;
		$meta[ Package::SOURCE_META ]    = array(
			'site_url'       => (string) ( $manifest['source']['site_url'] ?? '' ),
			'page_id'        => (int) ( $manifest['source']['page_id'] ?? 0 ),
			'plugin_version' => (string) ( $manifest['plugin_version'] ?? '' ),
			'modified_gmt'   => (string) ( $page['modified_gmt'] ?? '' ),
		);
		if ( $this->page_key !== '' ) {
			$meta['_dxai_ui_page_key'] = $this->page_key;
		}
		self::set_meta( $page_id, $meta, array( '_dxai_ui_css_url', '_dxai_ui_js_url', '_dxai_ui_audit' ) );

		$template = (string) ( $page['template'] ?? '' );
		if ( in_array( $template, Package::TEMPLATES, true ) ) {
			update_post_meta( $page_id, '_wp_page_template', $template );
		} elseif ( $template !== '' && $template !== 'default' ) {
			/* translators: %s: template file. */
			$this->warnings[] = sprintf( __( 'The page used the template %s on the source site, which is not part of DX UI; it uses the default template here.', 'dxai-ui' ), $template );
			delete_post_meta( $page_id, '_wp_page_template' );
		}

		$design = (array) ( $manifest['design'] ?? array() );
		$purger = new \DXAI_UI\Compiler\Tailwind_Purger();
		$out    = array(
			'css'     => '',
			'js'      => '',
			'created' => $new,
		);
		$css    = (string) ( $design['css'] ?? '' );
		if ( $css !== '' ) {
			$url = $purger->persist( $page_id, $this->rewrite( (string) file_get_contents( $dir . '/' . $css ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( is_wp_error( $url ) ) {
				$this->warnings[] = $url->get_error_message();
			} else {
				update_post_meta( $page_id, '_dxai_ui_css_url', Upload_Paths::for_storage( $url ) );
				$out['css'] = Upload_Paths::for_storage( $url );
			}
		} else {
			delete_post_meta( $page_id, '_dxai_ui_css_url' );
		}
		$js = (string) ( $design['js'] ?? '' );
		if ( $js !== '' ) {
			$code  = $this->rewrite( (string) file_get_contents( $dir . '/' . $js ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$from  = (string) ( $manifest['plugin_version'] ?? '' );
			$newer = $from !== '' && defined( 'DXAI_UI_VERSION' ) && version_compare( $from, (string) DXAI_UI_VERSION, '>' );
			if ( ! $newer && class_exists( \DXAI_UI\Compiler\Motion_Runtime::class ) ) {
				$code = \DXAI_UI\Compiler\Motion_Runtime::refresh( $code );
			}
			$url = $purger->persist_js( $page_id, $code );
			if ( is_wp_error( $url ) ) {
				$this->warnings[] = $url->get_error_message();
			} else {
				update_post_meta( $page_id, '_dxai_ui_js_url', Upload_Paths::for_storage( (string) $url ) );
				$out['js'] = Upload_Paths::for_storage( (string) $url );
			}
		} else {
			delete_post_meta( $page_id, '_dxai_ui_js_url' );
		}

		// Written in full: its status now, and no longer pending.
		if ( (string) get_post_status( $page_id ) !== $status ) {
			wp_update_post(
				array(
					'ID'          => $page_id,
					'post_status' => $status,
				)
			);
		}
		delete_post_meta( $page_id, self::PENDING_META );
		clean_post_cache( $page_id );

		return $out;
	}

	/**
	 * The page key the imported page carries: the source's, which is also
	 * who its header menus belong to (Navigation_Factory) — unless another
	 * page here already holds it (the same design imported here from its
	 * ZIP), which keeps its own and this one gets `{key}@{source site}`.
	 * Decided once, before the header is installed, so the menus and the
	 * page agree on the owner.
	 */
	private function page_key( string $key, int $page_id, string $site_url ): string {
		$current = (string) get_post_meta( $page_id, '_dxai_ui_page_key', true );
		if ( $key === '' ) {
			return $current;
		}
		$suffixed = $key . '@' . Package::site_key( $site_url );
		if ( $current === $suffixed ) {
			return $current;
		}
		if ( self::key_holder( $key, $page_id ) > 0 ) {
			/* translators: %s: page key. */
			$this->warnings[] = sprintf( __( 'Another page here already belongs to the same design (%s); the imported page keeps an identity of its own, so importing that design ZIP here later updates the other page, not this one.', 'dxai-ui' ), $key );

			return $suffixed;
		}

		return $key;
	}

	/**
	 * Another page (not $page_id) holding a page key, or 0.
	 */
	private static function key_holder( string $key, int $page_id ): int {
		$found = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'post__not_in'   => array( $page_id ),
				'meta_key'       => '_dxai_ui_page_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return isset( $found[0] ) ? (int) $found[0] : 0;
	}

	/**
	 * The site's brand: this design only when the site has none (no brand
	 * recorded, or its page is gone).
	 *
	 * @return array{action:string, page_id:int}
	 */
	private function brand( int $page_id, string $source ): array {
		$design = get_option( Design_Theme_Json::OPTION );
		$holder = is_array( $design ) ? (int) ( $design['page_id'] ?? 0 ) : 0;
		if ( $holder === $page_id ) {
			// This page is the brand already: its tokens may have changed.
			Design_Theme_Json::adopt( $page_id, $source );
			Design_Theme_Json::flush();

			return array(
				'action'  => 'updated',
				'page_id' => $page_id,
			);
		}
		if ( $holder > 0 && get_post_status( $holder ) === 'publish' ) {
			return array(
				'action'  => 'unchanged',
				'page_id' => $holder,
			);
		}
		Design_Theme_Json::adopt( $page_id, $source );
		Design_Theme_Json::flush();
		$now = get_option( Design_Theme_Json::OPTION );

		return array(
			// Not adopted: the site has no brand and keeps none — on a classic
			// theme the palette is the theme's (Design_Theme_Json::adopt()); the
			// page renders from its own stylesheet's colours either way.
			'action'  => is_array( $now ) && (int) ( $now['page_id'] ?? 0 ) === $page_id ? 'adopted' : 'theme',
			'page_id' => is_array( $now ) ? (int) ( $now['page_id'] ?? 0 ) : 0,
		);
	}
}

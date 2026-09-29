<?php
/**
 * Purge all DXAI-UI generated content and re-import ZIPs.
 *
 * Usage:
 *   php bin/purge-and-import-real-zips.php <wp-root> <zip1> <zip2>
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$wp_root = $argv[1] ?? '';
$zips    = array_slice( $argv, 2 );

if ( $wp_root === '' || count( $zips ) < 1 ) {
	fwrite( STDERR, "usage: php bin/purge-and-import-real-zips.php <wp-root> <zip1> [zip2...]\n" );
	exit( 1 );
}

$wp_load = rtrim( $wp_root, "/\\" ) . DIRECTORY_SEPARATOR . 'wp-load.php';
if ( ! is_readable( $wp_load ) ) {
	fwrite( STDERR, "wp-load missing: {$wp_load}\n" );
	exit( 1 );
}

require $wp_load;

if ( function_exists( 'get_user_by' ) ) {
	$user = get_user_by( 'login', 'admin' );
	if ( $user instanceof WP_User ) {
		wp_set_current_user( $user->ID );
	}
}

/**
 * @param \WP_Query $q
 * @return int
 */
function dxai_delete_posts_from_query( \WP_Query $q ): int {
	$deleted = 0;
	foreach ( $q->posts as $post ) {
		if ( ! $post instanceof WP_Post ) {
			continue;
		}
		// Force delete to keep "delete everything" true.
		if ( wp_delete_post( (int) $post->ID, true ) ) {
			++$deleted;
		}
	}

	return $deleted;
}

/**
 * Every post of one type that the plugin generated.
 *
 * `post_status => 'any'` silently omits trash and auto-draft, and the old
 * `posts_per_page => 200` silently stopped short — the site had 868 generated
 * patterns, so a "purge everything" run left 668 of them behind. Both are
 * spelled out here so a purge means what it says.
 *
 * @param string      $type     Post type.
 * @param string|null $meta_key Marker meta required, or null for a type the
 *                              plugin owns outright.
 */
function dxai_purge_type( string $type, ?string $meta_key ): int {
	$args = array(
		'post_type'              => $type,
		'post_status'            => array_keys( get_post_stati() ),
		'posts_per_page'         => -1,
		'fields'                 => 'all',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	);
	if ( $meta_key !== null ) {
		$args['meta_query'] = array(
			array(
				'key'     => $meta_key,
				'compare' => 'EXISTS',
			),
		);
	}

	return dxai_delete_posts_from_query( new WP_Query( $args ) );
}

$deleted = array();

// Types the plugin owns outright.
$deleted['dxai_conversion'] = dxai_purge_type( DXAI_UI\Content\Content_Types::CONVERSION, null );
$deleted['dxai_form_entry'] = dxai_purge_type( DXAI_UI\Content\Content_Types::FORM_ENTRY, null );

// Shared types: only what carries the generated marker.
$deleted['wp_block']         = dxai_purge_type( 'wp_block', '_dxai_ui_generated' );
$deleted['wp_template_part'] = dxai_purge_type( 'wp_template_part', '_dxai_ui_generated' );
$deleted['wp_navigation']    = dxai_purge_type( 'wp_navigation', '_dxai_ui_generated' );
$deleted['page']             = dxai_purge_type( 'page', '_dxai_ui_generated_page' );

// Demo blog posts the plugin seeds so a design's blog section has content.
$deleted['post'] = dxai_purge_type( 'post', '_dxai_ui_seeded' );

/*
 * Sideloaded media. Sideloader::store() stamps every attachment it creates
 * with _dxai_ui_asset_kind, so this reaches the plugin's uploads and nothing
 * a person put there by hand. Force delete, so the files leave the disk too —
 * repeated import runs had grown wp-content/uploads to 1.5 GB.
 */
$deleted['attachment'] = dxai_purge_type( 'attachment', '_dxai_ui_asset_kind' );

/*
 * Generated stylesheets and scripts. One pattern-<id>.css is written per
 * structure per import and nothing ever removed them: the site had accumulated
 * 2,451 of them, 118 MB — more than every image put together. Keyed by post id,
 * so anything whose post is gone can go with it.
 */
$assets  = trailingslashit( wp_upload_dir()['basedir'] ) . 'dxai-ui/';
$stale   = 0;
$freed   = 0;
foreach ( glob( $assets . 'pattern-*.{css,js}', GLOB_BRACE ) ?: array() as $file ) {
	if ( ! preg_match( '/pattern-(\d+)\.(css|js)$/', $file, $m ) ) {
		continue;
	}
	if ( get_post( (int) $m[1] ) instanceof WP_Post ) {
		continue;
	}
	$freed += (int) filesize( $file );
	wp_delete_file( $file );
	++$stale;
}
/*
 * Upload files no attachment owns. Deleting an attachment removes its
 * generated sizes only while its metadata is still readable, so a run
 * interrupted — or an earlier version of this script, which capped itself at
 * 200 rows and silently left the rest — strands the `-1024x139` variants
 * forever. 2,112 of them, 79 MB, had accumulated over a day of import cycles.
 */
$owned = array();
foreach (
	get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
		)
	) as $attachment
) {
	$file = get_attached_file( (int) $attachment->ID );
	if ( $file ) {
		$owned[ basename( $file ) ] = true;
	}
	$meta = wp_get_attachment_metadata( (int) $attachment->ID );
	if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
		foreach ( $meta['sizes'] as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$owned[ (string) $size['file'] ] = true;
			}
		}
	}
}

$basedir = trailingslashit( wp_upload_dir()['basedir'] );
foreach ( glob( $basedir . '*/*/*.{jpg,jpeg,png,gif,webp,avif,svg,ico,mp4,webm,woff,woff2,ttf,otf}', GLOB_BRACE ) ?: array() as $file ) {
	if ( isset( $owned[ basename( $file ) ] ) ) {
		continue;
	}
	$freed += (int) filesize( $file );
	wp_delete_file( $file );
	++$stale;
}

echo 'stale_assets_removed=' . $stale . ' bytes_freed=' . $freed . PHP_EOL;

// 6) Classic nav menus that DXAI wrote into theme locations.
$locations = get_theme_mod( 'nav_menu_locations', array() );
$locations = is_array( $locations ) ? $locations : array();
foreach ( array( 'dxai-primary', 'dxai-footer' ) as $loc ) {
	if ( isset( $locations[ $loc ] ) ) {
		$menu_id = (int) $locations[ $loc ];
		if ( $menu_id > 0 ) {
			wp_delete_nav_menu( $menu_id );
		}
		unset( $locations[ $loc ] );
	}
}
/*
 * The header's locations (Header_Menus): unassigned when an import's header
 * menu holds them, so the next import assigns its own. The menus themselves
 * stay — they are what a person edits the header in — and a location a
 * person gave a menu of their own is left as they set it.
 */
foreach ( array( 'primary-navigation', 'header-top', 'header-actions' ) as $loc ) {
	$menu_id = (int) ( $locations[ $loc ] ?? 0 );
	if ( $menu_id > 0 && '' !== (string) get_term_meta( $menu_id, DXAI_UI\Structures\Navigation_Factory::HEADER_MENU_META, true ) ) {
		unset( $locations[ $loc ] );
	}
}
set_theme_mod( 'nav_menu_locations', $locations );
// The stored headers point at pages this purge just removed.
delete_option( DXAI_UI\Chrome\Header_Template::OPTION );
foreach ( (array) $GLOBALS['wpdb']->get_col( $GLOBALS['wpdb']->prepare( "SELECT option_name FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE %s", $GLOBALS['wpdb']->esc_like( DXAI_UI\Chrome\Header_Template::SCOPE_OPTION ) . '%' ) ) as $option ) {
	delete_option( (string) $option );
}

echo "purge_deleted=" . wp_json_encode( $deleted ) . PHP_EOL;

// Re-import.
$php = new ZipArchive();

$compiler   = new DXAI_UI\Compiler\Source_Compiler();
$structures = new DXAI_UI\Structures\Structure_Repository();

/** @var array<int, string> Archives that reported status=ok, checked for page ownership at the end. */
$imported = array();

foreach ( $zips as $zip_path ) {
	$zip_path = (string) $zip_path;
	$name     = basename( $zip_path );

	if ( ! is_readable( $zip_path ) ) {
		echo "import_zip={$name} status=missing_zip" . PHP_EOL;
		continue;
	}

	$file = array(
		'tmp_name' => $zip_path,
		'name'     => $name,
	);

	/*
	 * The same sniffer the REST endpoint uses.
	 *
	 * This loop used to be a second, slightly different heuristic — it read
	 * `node_modules/` and `dist/` as source and its `break` made the answer
	 * depend on entry order — which is how a ZIP could compile one way here and
	 * another way through wp-admin. Whatever Zip_Router decides, both paths now
	 * decide together.
	 */
	$doc = DXAI_UI\Connectors\Zip_Router::import( $file );

	if ( is_wp_error( $doc ) ) {
		echo "import_zip={$name} status=import_error message=" . $doc->get_error_message() . PHP_EOL;
		continue;
	}

	// Compile -> persist.
	if ( ! $compiler->can_compile( $doc ) ) {
		echo "import_zip={$name} status=can_compile=0" . PHP_EOL;
		continue;
	}

	$result = $compiler->compile( $doc );
	if ( ! empty( $result['structures'] ) ) {
		// Make sure it's going through the native Gutenberg split.
	}

	$saved = $structures->save( $result );
	if ( is_wp_error( $saved ) ) {
		echo "import_zip={$name} status=save_error message=" . $saved->get_error_message() . PHP_EOL;
		continue;
	}

	/*
	 * Which upload produced this page. A design's title comes from its own
	 * content, not its filename, so nothing in the result relates the two —
	 * and bin/verify-import.cjs needs the pairing to build the right oracle.
	 * Guessing from the title matched four designs of seven and quietly
	 * reported the other three as passing.
	 */
	$page_id = (int) ( $saved['page_id'] ?? 0 );
	/*
	 * Every page the import made, not just the first. A multi-route project
	 * produces one page per route, and recording the ZIP on the primary alone
	 * left the others with nothing to measure against — bin/verify-import.cjs
	 * reported them "no recorded source ZIP", which is honest but means a
	 * second and third page ship unverified.
	 */
	$page_ids = array_merge(
		$page_id > 0 ? array( $page_id ) : array(),
		array_map( 'intval', is_array( $saved['extra_page_ids'] ?? null ) ? $saved['extra_page_ids'] : array() )
	);
	foreach ( $page_ids as $one ) {
		if ( $one > 0 ) {
			update_post_meta( $one, '_dxai_ui_source_zip', $name );
		}
	}

	/*
	 * The same audit wp-admin shows, printed here.
	 *
	 * Structure_Repository::save() runs Import_Audit for every page it writes
	 * now, so the command line has the findings too — it just never said so,
	 * which meant a corpus run could report `status=ok` for a page with images
	 * that have no source.
	 */
	$audit    = is_array( $saved['audit'] ?? null ) ? $saved['audit'] : array();
	$findings = is_array( $audit['findings'] ?? null ) ? $audit['findings'] : array();
	$notices  = is_array( $audit['notices'] ?? null ) ? $audit['notices'] : array();
	$verdict  = $findings === array()
		? 'audit=' . (int) ( $audit['checked'] ?? 0 ) . '/' . (int) ( $audit['checked'] ?? 0 )
		: 'audit=FOUND(' . implode( ',', array_map( static fn( $f ): string => (string) ( $f['check'] ?? '?' ), $findings ) ) . ')';
	if ( $notices !== array() ) {
		$verdict .= ' notices=' . count( $notices );
	}

	echo "import_zip={$name} status=ok conversion_id=" . (int) ( $saved['conversion_id'] ?? 0 ) . " page_id=" . $page_id . ' ' . $verdict . PHP_EOL;
	$imported[] = $name;
}

/*
 * Did every archive that imported end up owning a page?
 *
 * This is the check that was missing. Three designs in this corpus ship a
 * `jobs.$slug.tsx` route, and a page used to be identified by its route slug
 * alone — so Careers Page Builder, Client Connect Hub and Growth Engine Hub all
 * computed `dxai-jobs-slug`, and each import UPDATED the previous design's page
 * instead of making its own. Eighteen archives imported, seventeen pages
 * existed, and every line above said status=ok: the only visible trace was
 * three of them sharing a page_id, which nothing read.
 *
 * Asked of the database rather than of what save() returned, because the bug
 * was that save() returned a perfectly valid page id — just not a page of this
 * design's own. Ownership is `_dxai_ui_page_key`, which is the archive name
 * plus the computed slug; see Structure_Repository::existing_page().
 */
$unowned = array();
foreach ( $imported as $name ) {
	$owned = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => '_dxai_ui_source_zip',
			'meta_value'     => $name,
		)
	);
	if ( $owned === array() ) {
		$unowned[] = $name;
		continue;
	}
	echo 'owns_pages=' . $name . ' count=' . count( $owned ) . PHP_EOL;
}

echo 'imported=' . count( $imported ) . ' archives_owning_pages=' . ( count( $imported ) - count( $unowned ) ) . PHP_EOL;
if ( $unowned !== array() ) {
	fwrite( STDERR, 'LOST: ' . count( $unowned ) . " archive(s) imported but own no page — another design overwrote them:\n" );
	foreach ( $unowned as $name ) {
		fwrite( STDERR, "  {$name}\n" );
	}
	exit( 1 );
}


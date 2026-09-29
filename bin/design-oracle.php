<?php
/**
 * Build the design oracle: the compiled DOM plus a Tailwind entry stylesheet.
 *
 * The oracle is the closest thing to the original app we can hold still — the
 * design's own DOM rendered with output from the real Tailwind CLI, and none of
 * the plugin's own resets. Comparing a converted page against it is the only
 * way to tell whether a change moved us toward the design or away from it; a
 * previous converted page is not a trustworthy baseline, because it can be
 * lossy in ways nobody has noticed yet.
 *
 * Run: wp eval-file bin/design-oracle.php <zip-path> <out-dir> [route-slug]
 *
 * `route-slug` picks which route of a multi-route design to build; without it
 * the design's first page is used, which is the only page a single-route
 * project has.
 * Then: node bin/design-oracle.cjs --dir <out-dir> --url <live-page-url>
 *
 * @package DXAI_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$zip = isset( $args[0] ) ? (string) $args[0] : '';
$out = isset( $args[1] ) ? rtrim( (string) $args[1], '/\\' ) : '';

if ( $zip === '' || $out === '' ) {
	echo "usage: wp eval-file bin/design-oracle.php <zip-path> <out-dir>\n";
	return;
}
if ( ! is_readable( $zip ) ) {
	echo "error=zip_unreadable\n";
	return;
}

$user = get_user_by( 'login', 'admin' );
if ( $user instanceof WP_User ) {
	wp_set_current_user( $user->ID );
}
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

wp_mkdir_p( $out );
$project = $out . '/project';
wp_mkdir_p( $project );

// Keep the extracted sources: the Tailwind CLI has to scan them for classes.
$archive = new ZipArchive();
if ( true !== $archive->open( $zip ) ) {
	echo "error=zip_open_failed\n";
	return;
}
for ( $i = 0; $i < $archive->numFiles; $i++ ) {
	$name = (string) $archive->getNameIndex( $i );
	if ( $name === '' || str_contains( $name, '..' ) || str_starts_with( $name, 'node_modules/' ) ) {
		continue;
	}
	$archive->extractTo( $project, $name );
}
$archive->close();

/*
 * Importing uploads every image in the ZIP to the media library, and this
 * script is meant to be run on every change. Note what is there beforehand so
 * the run can put the library back afterwards — otherwise measuring the page
 * ten times leaves ninety orphaned attachments behind.
 */
$before = get_posts(
	array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

$source = ( new DXAI_UI\Connectors\Lovable_Connector() )->import_zip(
	array(
		'name'     => basename( $zip ),
		'tmp_name' => $zip,
		'error'    => 0,
		'size'     => filesize( $zip ),
	)
);
if ( is_wp_error( $source ) ) {
	echo 'error=' . $source->get_error_message() . PHP_EOL;
	return;
}

$payload = $source->payload;

/*
 * The same file set Source_Compiler hands the compiler: `components` merged
 * over `sources`. Passing only `sources` left some component files out, so the
 * compiler could not resolve `<SiteHeader/>` and rendered a fallback instead —
 * the oracle then disagreed with the page about things the conversion had got
 * right, which is the one failure mode an oracle must not have.
 */
$files = array();
foreach ( array( 'components', 'sources' ) as $key ) {
	foreach ( is_array( $payload[ $key ] ?? null ) ? $payload[ $key ] : array() as $path => $code ) {
		if ( is_string( $code ) && $code !== '' ) {
			$files[ (string) $path ] = $code;
		}
	}
}

/*
 * Take the route the connector picked, not `routes/index.tsx` by name. A file
 * router lets `/` be a redirect stub with the real page a directory down, and
 * hardcoding the name gave that design an empty oracle — an instrument that
 * would have reported the page as entirely wrong.
 */
$pages = is_array( $payload['pages'] ?? null ) ? $payload['pages'] : array();

/*
 * Which route to build, for a design that has several.
 *
 * Taking `pages[0]` unconditionally meant every page of a multi-route project
 * was measured against the project's FIRST route. On a single-route design
 * that is the same thing and nothing was wrong; on a design with a /pricing
 * page it would have compared two unrelated documents, so those pages were
 * refused instead — which is safe and also means they were never verified.
 */
$want  = isset( $args[2] ) ? trim( (string) $args[2], '/' ) : '';
$first = is_array( $pages ) ? reset( $pages ) : null;
if ( $want !== '' ) {
	foreach ( $pages as $candidate ) {
		if ( ! is_array( $candidate ) ) {
			continue;
		}
		if ( trim( (string) ( $candidate['slug'] ?? '' ), '/' ) === $want ) {
			$first = $candidate;
			break;
		}
	}
}

$code  = is_array( $first ) ? (string) ( $first['code'] ?? '' ) : '';
$route = is_array( $first ) ? (string) ( $first['file'] ?? '' ) : '';

if ( $code === '' ) {
	foreach ( $files as $path => $body ) {
		if ( is_string( $body ) && preg_match( '#routes/index\.(tsx|jsx)$#', (string) $path ) ) {
			$code  = $body;
			$route = (string) $path;
			break;
		}
	}
}
if ( $code === '' ) {
	echo "error=no_route_file\n";
	return;
}
echo 'route=' . $route . PHP_EOL;

$compiler = new DXAI_UI\Compiler\Jsx_Compiler();
$sections = $compiler->compile_file( $code, is_array( $payload['harvest'] ?? null ) ? $payload['harvest'] : array(), $files );
$html     = implode( "\n", array_column( $sections, 'html' ) );

/*
 * Point the pictures back at the extracted sources. Beyond keeping the media
 * library clean, it makes the oracle self-contained: it renders from the ZIP,
 * not from whatever the last import happened to leave on the site.
 */
$local = array();
foreach ( ( $payload['harvest']['rewrites'] ?? array() ) as $path => $url ) {
	$rel = ltrim( (string) $path, './' );
	// Only real media. Some exports ship assets as `.asset.json` descriptors,
	// and pointing an <img> back at the descriptor renders nothing — the oracle
	// would then show a broken image where the page shows the right one.
	if ( ! preg_match( '/\.(?:png|jpe?g|gif|webp|avif|svg|mp4|webm|ico)$/i', $rel ) ) {
		continue;
	}
	if ( is_readable( $project . '/' . $rel ) && ! isset( $local[ (string) $url ] ) ) {
		$local[ (string) $url ] = 'project/' . $rel;
	}
}

/*
 * Anything still pointing at an upload gets a copy beside the oracle. Some
 * exports ship assets as descriptors naming a remote file, so the bytes only
 * ever existed in the media library — and this script reverts its own uploads
 * at the end, which left those <img> elements aimed at a deleted file and
 * measuring 0px. The oracle has to render from its own directory.
 */
wp_mkdir_p( $out . '/media' );
$uploads = wp_upload_dir();
foreach ( ( $payload['harvest']['assets'] ?? array() ) as $asset ) {
	$url = (string) ( $asset['url'] ?? '' );
	if ( $url === '' || isset( $local[ $url ] ) || ! str_starts_with( $url, (string) $uploads['baseurl'] ) ) {
		continue;
	}
	$src = $uploads['basedir'] . substr( $url, strlen( (string) $uploads['baseurl'] ) );
	if ( ! is_readable( $src ) ) {
		continue;
	}
	$name = basename( $src );
	if ( copy( $src, $out . '/media/' . $name ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		$local[ $url ] = 'media/' . $name;
	}
}
$html = strtr( $html, $local );

file_put_contents(
	$out . '/oracle-dom.html',
	'<div class="dxai-ui dxai-ui--oracle ' . esc_attr( $compiler->wrapper_class() ) . '"'
	. ( $compiler->wrapper_style() !== '' ? ' style="' . esc_attr( $compiler->wrapper_style() ) . '"' : '' )
	. ">\n" . $html . "\n</div>\n"
);

// A Tailwind entry that keeps the design's own @theme and scans the sources.
$styles = '';
foreach ( array( 'src/styles.css', 'src/index.css', 'src/app.css' ) as $candidate ) {
	if ( is_readable( $project . '/' . $candidate ) ) {
		$styles = (string) file_get_contents( $project . '/' . $candidate );
		break;
	}
}
/*
 * Which Tailwind this design was built with.
 *
 * The classic Lovable template is Tailwind v3: its tokens live in
 * `tailwind.config.ts` and its CSS opens with `@tailwind base;`. Prepending
 * v4's `@import "tailwindcss"` to that and running the v4 CLI over it produced
 * an oracle with none of the design's colours, radii, fonts or keyframes —
 * which is to say an oracle that agreed with a broken conversion. The version
 * is read from the project rather than assumed.
 */
$config_file = '';
foreach ( array( 'tailwind.config.ts', 'tailwind.config.js', 'tailwind.config.cjs', 'tailwind.config.mjs' ) as $candidate ) {
	if ( is_readable( $project . '/' . $candidate ) ) {
		$config_file = $candidate;
		break;
	}
}
$is_v3 = $config_file !== '' || preg_match( '/^\s*@tailwind\s+(?:base|utilities)\s*;/m', $styles ) === 1;

if ( $is_v3 ) {
	/*
	 * Verbatim. A v3 entry already carries its three `@tailwind` directives and
	 * its `@layer base` token blocks, and the CLI is pointed at the project's
	 * own config, so nothing has to be synthesised — which is the point of an
	 * oracle.
	 */
	file_put_contents( $out . '/tailwind-input.css', $styles );
} else {
	$styles = (string) preg_replace( '/^@import\s+["\'][^"\']+["\'][^;]*;\s*$/m', '', $styles );
	$styles = (string) preg_replace( '/^@source\s+[^;]+;\s*$/m', '', $styles );

	file_put_contents(
		$out . '/tailwind-input.css',
		"@import \"tailwindcss\" source(none);\n@source \"./project/src\";\n@custom-variant dark (&:is(.dark *));\n" . $styles
	);
}

// Read by bin/design-oracle.cjs, which installs and runs the matching CLI.
file_put_contents(
	$out . '/oracle-tailwind.json',
	(string) wp_json_encode(
		array(
			'major'  => $is_v3 ? 3 : 4,
			'config' => $config_file,
			// v3 resolves `content` globs against the working directory, so the
			// CLI has to run where the config expects to be.
			'cwd'    => $is_v3 ? 'project' : '',
		)
	)
);
echo 'tailwind_major=' . ( $is_v3 ? 3 : 4 ) . ( $config_file !== '' ? ' config=' . $config_file : '' ) . "\n";

/*
 * Web fonts, scraped straight from the extracted sources rather than taken from
 * our own harvest. The oracle has to be able to catch us dropping a font, so it
 * must not inherit the pipeline's opinion about which fonts the design uses.
 *
 * Text metrics depend on this completely: without the real faces, headings fall
 * back to whatever the machine has installed and wrap at different points, and
 * every heading height in the comparison becomes noise.
 */
$links = array();
$iter  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $project, FilesystemIterator::SKIP_DOTS ) );
foreach ( $iter as $file ) {
	if ( ! $file->isFile() || ! preg_match( '/\.(tsx?|jsx?|css|html)$/i', $file->getFilename() ) ) {
		continue;
	}
	if ( preg_match_all( '#https://fonts\.googleapis\.com/css2\?[^"\'\s)]+#', (string) file_get_contents( $file->getPathname() ), $m ) ) {
		foreach ( $m[0] as $url ) {
			$links[ html_entity_decode( $url ) ] = true;
		}
	}
}
/*
 * Mirror the faces locally. Measuring against a stylesheet fetched over the
 * network makes the oracle non-deterministic — a slow or blocked request
 * silently swaps in a fallback font and every heading height moves — so the
 * reference build has to be offline once it is built.
 */
wp_mkdir_p( $out . '/fonts' );
$face_css = '';
$agent    = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

foreach ( array_keys( $links ) as $url ) {
	$sheet = wp_remote_get( $url, array( 'timeout' => 30, 'user-agent' => $agent ) );
	if ( is_wp_error( $sheet ) || 200 !== wp_remote_retrieve_response_code( $sheet ) ) {
		echo 'font_fetch_failed=' . $url . PHP_EOL;
		continue;
	}
	$css = (string) wp_remote_retrieve_body( $sheet );

	preg_match_all( '#url\((https://[^)]+)\)#', $css, $m );
	foreach ( array_unique( $m[1] ) as $remote ) {
		$name  = md5( $remote ) . '.' . ( pathinfo( wp_parse_url( $remote, PHP_URL_PATH ), PATHINFO_EXTENSION ) ?: 'woff2' );
		$local = $out . '/fonts/' . $name;
		if ( ! is_readable( $local ) ) {
			$bin = wp_remote_get( $remote, array( 'timeout' => 30, 'user-agent' => $agent ) );
			if ( is_wp_error( $bin ) || 200 !== wp_remote_retrieve_response_code( $bin ) ) {
				echo 'font_file_failed=' . $remote . PHP_EOL;
				continue;
			}
			file_put_contents( $local, wp_remote_retrieve_body( $bin ) );
		}
		$css = str_replace( $remote, 'fonts/' . $name, $css );
	}
	$face_css .= $css . "\n";
}

file_put_contents( $out . '/oracle-fonts.css', $face_css );
echo 'font_faces=' . substr_count( $face_css, '@font-face' ) . PHP_EOL;

/*
 * The icon runtime, from the same source the page uses. Icons are markers in
 * the compiled DOM (`<i data-lucide>`) that a script swaps for real SVGs; if
 * only one side runs it, every icon in the comparison is a size mismatch.
 */
$lucide_src = DXAI_UI\Blocks\Assets::lucide_src();
/*
 * Cached one directory up, which is shared by every oracle of a run.
 *
 * No copy of lucide is vendored in the plugin, so `lucide_src()` returns a CDN
 * URL and this used to fetch 373KB per oracle — eleven times in a full run,
 * any one of which can fail. One did: that page's oracle had no icon runtime,
 * so its eleven `<i data-lucide>` stayed `<i>` while the converted page turned
 * them into real `<svg>`, and the run reported 33 paint differences per width
 * that were entirely the instrument's. Fetch once, reuse.
 */
$cache = dirname( $out ) . '/lucide.min.js';
if ( ! is_readable( $out . '/lucide.js' ) && is_readable( $cache ) && filesize( $cache ) > 10000 ) {
	copy( $cache, $out . '/lucide.js' );
}
if ( ! is_readable( $out . '/lucide.js' ) ) {
	$body = str_starts_with( $lucide_src, 'http' )
		? ( ( $r = wp_remote_get( $lucide_src, array( 'timeout' => 30 ) ) ) && ! is_wp_error( $r ) ? wp_remote_retrieve_body( $r ) : '' )
		: (string) file_get_contents( str_replace( DXAI_UI_URL, DXAI_UI_DIR, $lucide_src ) );
	if ( $body !== '' ) {
		file_put_contents( $cache, $body );
	}
	if ( $body === '' ) {
		echo 'lucide_fetch_failed=1' . PHP_EOL;
	} else {
		file_put_contents( $out . '/lucide.js', $body );
	}
}
echo 'lucide_bytes=' . ( is_readable( $out . '/lucide.js' ) ? filesize( $out . '/lucide.js' ) : 0 ) . PHP_EOL;

$ids = array();
foreach ( $sections as $section ) {
	if ( preg_match( '/\bid="([^"]+)"/', (string) $section['html'], $m ) ) {
		$ids[] = $m[1];
	}
}

// Put the media library back the way it was.
$after   = get_posts(
	array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
$removed = 0;
foreach ( array_diff( $after, $before ) as $id ) {
	if ( wp_delete_attachment( (int) $id, true ) ) {
		++$removed;
	}
}
echo 'uploads_reverted=' . $removed . PHP_EOL;

echo 'sections=' . count( $sections ) . PHP_EOL;
echo 'dom_bytes=' . strlen( $html ) . PHP_EOL;
echo 'wrapper_class=' . $compiler->wrapper_class() . PHP_EOL;
echo 'font_links=' . count( $links ) . PHP_EOL;
echo 'section_ids=' . implode( ',', $ids ) . PHP_EOL;
echo 'out=' . $out . PHP_EOL;

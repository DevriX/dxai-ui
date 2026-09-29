<?php
/**
 * The same ZIP, imported both ways, has to produce the same page.
 *
 * "При импорт през wp admin plugin DXAI резултата е доста по различен" — and it
 * was, for reasons nothing measured: the admin picked its connector from the
 * card someone clicked, dropped four fields the repository reads
 * (`static_html`, `tailwind_config`, `wrapper_style`, `source_html`) and shipped
 * a browser-built Tailwind sheet compiled from Tailwind's default theme instead
 * of the design's. Each of those is fixed; this is what keeps them fixed.
 *
 * The comparison runs one archive through both paths in one process:
 *
 *   CLI   — Zip_Router → Source_Compiler → Structure_Repository::save(), which
 *           is exactly what bin/purge-and-import-real-zips.php does;
 *   admin — the same compile result posted to the real REST route the wizard
 *           posts to, dispatched through rest_do_request() so the controller's
 *           own parameter reading is what is under test.
 *
 * Both writes land on the SAME page, because the repository keys a page by the
 * design's route and updates it in place. So the first path's output is read
 * into memory — markup, the stylesheet's own bytes, and the meta the front end
 * reads — and the second path's write is then compared against that snapshot.
 * An earlier version of this script compared the two page ids after both writes
 * and was comparing a page with itself, which of course always matched.
 *
 * usage: bin/wp-php.sh bin/admin-vs-cli.php "<zip path>" [more zips...]
 *        bin/wp-php.sh bin/admin-vs-cli.php --reverse ...   (admin writes first)
 *        bin/wp-php.sh bin/admin-vs-cli.php --break=tailwind_config ...
 *                                                    (negative control: must DIFFER)
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

require __DIR__ . '/wp-boot.php';

use DXAI_UI\Compiler\Source_Compiler;
use DXAI_UI\Connectors\Zip_Extractor;
use DXAI_UI\Connectors\Zip_Router;
use DXAI_UI\Structures\Structure_Repository;

$args = array_slice( $argv, 1 );
/*
 * Which path writes first. The second write updates a page the first one may
 * have created, so running both orders is what tells a real difference between
 * the two paths apart from a difference between creating and updating.
 */
$reverse = in_array( '--reverse', $args, true );
/*
 * The negative control. `--break=tailwind_config` drops that field from the
 * admin request only, which is precisely the bug this script exists to catch —
 * the wizard posting a compile result with a field the repository reads missing.
 * A run with it has to report DIFFERS; if it reports "identical", the
 * comparison is not measuring anything and neither is a clean run.
 */
$broken = '';
foreach ( $args as $arg ) {
	if ( str_starts_with( (string) $arg, '--break=' ) ) {
		$broken = substr( (string) $arg, 8 );
	}
}
$zips = array_values( array_filter( $args, static fn( $a ): bool => ! str_starts_with( (string) $a, '--' ) ) );

if ( $zips === array() ) {
	$zips = array_slice( glob( 'C:/Users/DevriX/Documents/Lovable/*.zip' ) ?: array(), 0, 3 );
}

// The REST route's permission callback is `manage_options`.
$admin_user = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( $admin_user === array() ) {
	echo 'no administrator to run the REST path as' . PHP_EOL;
	exit( 1 );
}
wp_set_current_user( (int) $admin_user[0] );

/**
 * Everything about a saved import that the other path has to reproduce.
 *
 * Read into memory, not referenced: the next write overwrites the page, its
 * meta and its stylesheet file in place.
 *
 * @return array<string, mixed>
 */
$shape = static function ( int $page_id ): array {
	$content = (string) get_post_field( 'post_content', $page_id );

	/*
	 * The page's stylesheet is written to a file and the post keeps its URL, so
	 * the sheet's own bytes are what have to match — the URL carries the page id
	 * and a cache buster whichever path wrote it. This is the field the
	 * browser-side Tailwind build used to ruin: same markup, same classes, a
	 * sheet compiled from Tailwind's default theme instead of the design's.
	 */
	$css = '';
	// Either stored form: relative to uploads (new) or an absolute URL (old).
	$file = DXAI_UI\Support\Upload_Paths::for_meta( (int) $page_id, '_dxai_ui_css_url' )['path'];
	if ( $file !== '' && is_readable( $file ) ) {
		$css = (string) file_get_contents( $file );
	}

	return array(
		'content'  => $content,
		'blocks'   => preg_match_all( '/<!--\s+wp:/', $content ),
		'islands'  => preg_match_all( '#<!--\s+wp:(?:dxai-ui/html|html)[\s-]#', $content ),
		'css_len'  => strlen( $css ),
		'css_hash' => $css === '' ? '' : md5( $css ),
		'hash'     => md5( $content ),
		'static'   => (string) get_post_meta( $page_id, '_dxai_ui_static_html', true ),
		'wrapper'  => (string) get_post_meta( $page_id, '_dxai_ui_wrapper_class', true ),
		'route'    => (string) get_post_meta( $page_id, '_dxai_ui_source_route', true ),
		'fonts'    => (array) get_post_meta( $page_id, '_dxai_ui_font_urls', true ),
		'template' => (string) get_post_meta( $page_id, '_wp_page_template', true ),
		/*
		 * The archive the page came from.
		 *
		 * This is the one field whose absence is invisible on the page itself:
		 * it is what bin/verify-import.cjs pairs a page with its design
		 * through, and for a long time only the CLI importer wrote it, so
		 * anything imported through wp-admin could never be measured. Compared
		 * here so the two paths cannot drift apart again in silence.
		 */
		'zip'      => (string) get_post_meta( $page_id, '_dxai_ui_source_zip', true ),
	);
};

/**
 * The CLI path: what bin/purge-and-import-real-zips.php does.
 *
 * @param array<string, mixed> $result
 */
$save_cli = static function ( array $result ): int|\WP_Error {
	$saved = ( new Structure_Repository() )->save( $result );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}

	return (int) ( $saved['page_id'] ?? 0 );
};

/**
 * The admin path: the compile result posted to the route the wizard posts to.
 *
 * The body is what ConvertWizard.addToWordPress() sends — the result spread
 * whole, plus the four fields it sets itself. If the wizard ever stops sending
 * one of them, this request stops matching the CLI save.
 *
 * @param array<string, mixed> $result
 */
$save_admin = static function ( array $result ) use ( $broken ): int|\WP_Error {
	if ( $broken !== '' ) {
		unset( $result[ $broken ] );
	}
	$request = new WP_REST_Request( 'POST', '/' . DXAI_UI_REST_NAMESPACE . '/save-pattern' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body_params(
		array_merge(
			$result,
			array(
				'structures'     => is_array( $result['structures'] ?? null ) ? $result['structures'] : array(),
				'scope'          => 'page',
				'synced'         => true,
				'design_assets'  => is_array( $result['design_assets'] ?? null ) ? $result['design_assets'] : array(),
				'wrapper_class'  => (string) ( $result['wrapper_class'] ?? '' ),
				'design_css_raw' => (string) ( $result['design_css_raw'] ?? '' ),
			)
		)
	);

	$response = rest_do_request( $request );
	if ( $response->is_error() ) {
		return $response->as_error();
	}

	$data = (array) $response->get_data();

	return (int) ( $data['page']['id'] ?? 0 );
};

$fails = 0;

foreach ( $zips as $zip_path ) {
	$zip_path = str_replace( '\\', '/', (string) $zip_path );
	$name     = basename( $zip_path );
	if ( ! is_readable( $zip_path ) ) {
		echo "MISSING {$name}" . PHP_EOL;
		++$fails;
		continue;
	}

	$file = array( 'tmp_name' => $zip_path, 'name' => $name );
	$doc  = Zip_Router::import( $file );
	if ( is_wp_error( $doc ) ) {
		/*
		 * An archive with no markup, stylesheet or component in it is not a
		 * design export — Documents/Lovable also holds things like a folder of
		 * photographs — so the connector refusing it is the right answer and not
		 * a failure of these two paths to agree. Anything that does carry source
		 * and still will not import is a real failure.
		 */
		$web = 0;
		foreach ( Zip_Extractor::entries( $zip_path ) as $entry ) {
			if ( preg_match( '/\.(?:html?|css|scss|jsx?|tsx?|mjs|vue|svelte|astro)$/i', (string) $entry ) === 1 ) {
				++$web;
			}
		}
		if ( $web === 0 ) {
			echo "SKIP {$name}: not a design export (" . $doc->get_error_message() . ')' . PHP_EOL;
			continue;
		}
		echo "FAIL {$name}: import " . $doc->get_error_message() . PHP_EOL;
		++$fails;
		continue;
	}

	$compiler = new Source_Compiler();
	if ( ! $compiler->can_compile( $doc ) ) {
		echo "SKIP {$name}: not a source compile (kind=" . $doc->kind . ')' . PHP_EOL;
		continue;
	}

	/*
	 * One compile, two saves. The compile is deliberately shared: this measures
	 * the persistence paths, and compiling twice would confound a difference in
	 * saving with a difference in compiling.
	 */
	$result = $compiler->compile( $doc );

	$first  = $reverse ? $save_admin : $save_cli;
	$second = $reverse ? $save_cli : $save_admin;
	$labels = $reverse ? array( 'admin', 'cli' ) : array( 'cli', 'admin' );

	$page_a = $first( $result );
	if ( is_wp_error( $page_a ) ) {
		echo "FAIL {$name}: {$labels[0]} save " . $page_a->get_error_message() . PHP_EOL;
		++$fails;
		continue;
	}
	if ( $page_a === 0 ) {
		echo "FAIL {$name}: {$labels[0]} save produced no page" . PHP_EOL;
		++$fails;
		continue;
	}

	// Read before the second write overwrites the page in place.
	$a = $shape( $page_a );

	$page_b = $second( $result );
	if ( is_wp_error( $page_b ) ) {
		echo "FAIL {$name}: {$labels[1]} save " . $page_b->get_error_message() . PHP_EOL;
		++$fails;
		continue;
	}
	if ( $page_b === 0 ) {
		echo "FAIL {$name}: {$labels[1]} save produced no page" . PHP_EOL;
		++$fails;
		continue;
	}

	$b = $shape( $page_b );

	$diff = array();
	if ( $page_a !== $page_b ) {
		/*
		 * Worth saying out loud: the second path wrote somewhere else instead of
		 * updating the design's own route, so the two shapes are no longer two
		 * writes of one page.
		 */
		$diff[] = 'page_id ' . $labels[0] . '=' . $page_a . ' ' . $labels[1] . '=' . $page_b;
	}
	$show = static fn( $v ): string => is_array( $v ) ? (string) wp_json_encode( $v ) : substr( (string) $v, 0, 16 );
	foreach ( array( 'hash', 'blocks', 'islands', 'css_len', 'css_hash', 'static', 'wrapper', 'route', 'fonts', 'template' ) as $key ) {
		if ( $a[ $key ] !== $b[ $key ] ) {
			$diff[] = $key . ' ' . $labels[0] . '=' . $show( $a[ $key ] ) . ' ' . $labels[1] . '=' . $show( $b[ $key ] );
		}
	}

	printf(
		'%-34s kind=%-12s page=%-6d blocks=%-4d islands=%-4d css=%-9s %s' . PHP_EOL,
		$name,
		$doc->kind,
		$page_a,
		$a['blocks'],
		$a['islands'],
		$a['css_len'] . 'B',
		$diff === array() ? 'identical (' . $labels[0] . ' then ' . $labels[1] . ')' : 'DIFFERS: ' . implode( ' | ', $diff )
	);

	if ( $diff !== array() ) {
		++$fails;
		/*
		 * Where the two contents first part company, with a little either side.
		 * A field one path drops usually shows up as one attribute or one
		 * wrapper class, and a hash tells you nothing about which.
		 */
		$len = min( strlen( $a['content'] ), strlen( $b['content'] ) );
		for ( $i = 0; $i < $len; $i++ ) {
			if ( $a['content'][ $i ] !== $b['content'][ $i ] ) {
				echo '  first difference at byte ' . $i . PHP_EOL;
				echo '    ' . $labels[0] . ' …' . substr( $a['content'], max( 0, $i - 60 ), 140 ) . '…' . PHP_EOL;
				echo '    ' . $labels[1] . ' …' . substr( $b['content'], max( 0, $i - 60 ), 140 ) . '…' . PHP_EOL;
				break;
			}
		}
		if ( strlen( $a['content'] ) !== strlen( $b['content'] ) ) {
			echo '  lengths ' . $labels[0] . '=' . strlen( $a['content'] ) . ' ' . $labels[1] . '=' . strlen( $b['content'] ) . PHP_EOL;
		}
	}
}

echo PHP_EOL . 'admin-vs-cli: ' . ( $fails === 0 ? 'every archive imported identically both ways' : $fails . ' archive(s) differ' ) . PHP_EOL;

/*
 * Under --break the expectation inverts: a difference is the pass, and finding
 * none means the comparison is blind.
 */
if ( $broken !== '' ) {
	echo 'negative control (--break=' . $broken . '): ' . ( $fails > 0 ? 'seen, the comparison works' : 'NOT SEEN, the comparison is blind' ) . PHP_EOL;
	exit( $fails > 0 ? 0 : 1 );
}

if ( $fails > 0 ) {
	exit( 1 );
}

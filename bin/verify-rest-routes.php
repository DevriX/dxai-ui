<?php
/**
 * Every REST route of the plugin, called the way the admin screens and the public form call it.
 *
 *   bash bin/wp-php.sh bin/verify-rest-routes.php        (or: wp eval-file bin/verify-rest-routes.php)
 *
 * Why it exists: the admin wizard's `generate-block` answered a PHP fatal on every call for three releases (a class name mangled on one
 * line that no command-line path reaches) and no suite had ever called the route. The routes are the surface nothing else exercises
 * the way a browser does, so this does, in seven parts:
 *
 *  1. who may call what: the permission callback of every route, run as a visitor, a subscriber, an editor, an administrator and an
 *     administrator without unfiltered_html, against a table of the tier each route belongs to. A route that is not in the table fails
 *     (a new route is classified on purpose), and so does one registered without a permission callback;
 *  2. the same through the server: every route that is not public refuses a visitor with 401 or 403;
 *  3. bad input: every route that writes or spends something is called with nothing, with nonsense and with an id that is not there,
 *     and answers a 4xx (never a fatal), and no post is written;
 *  4. the wizard's chain, process-source, generate-block, preview, on a pasted component with a YouTube iframe: each answers 200, the
 *     compile has sections, and the video comes out as the team's facade, not a live player;
 *  5. an administrator without unfiltered_html is told so by the import routes, and by import-info before anything is uploaded;
 *  6. GET settings never carries an API key, only that there is one;
 *  7. the screens and the routes agree: every route path the admin app (assets/src/admin) calls is registered, with the method it
 *     uses.
 *
 * Each of the checks that decide something is run once against a made-up case that must fail (a route open to everyone, one with no
 * permission callback, a path the server does not have), so a check that cannot fail shows here.
 *
 * Nothing leaves the machine (every HTTP request is refused and counted), nothing is saved, the settings are not written and no
 * engine is asked (fidelity off). Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\API\Converter_Controller;
use DXAI_UI\Security\Secret_Store;
use DXAI_UI\Settings\Options;

$fail   = 0;
$pass   = 0;
$expect = static function ( string $what, bool $ok, string $detail = '' ) use ( &$fail, &$pass ): void {
	if ( $ok ) {
		++$pass;
		echo "  ok    $what\n";
	} else {
		++$fail;
		echo "  FAIL  $what" . ( $detail !== '' ? "  — $detail" : '' ) . "\n";
	}
};

$ns       = DXAI_UI_REST_NAMESPACE;
$original = get_current_user_id();

// Nothing leaves the machine: every request is refused and written down.
$http = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$http ) {
		$http[] = (string) $url;

		return new WP_Error( 'dxai_test_no_network', 'Refused by bin/verify-rest-routes.php' );
	},
	1,
	3
);

// An administrator who may publish (the import routes ask for unfiltered_html as well as manage_options).
$admin = 0;
foreach ( get_users( array( 'role' => 'administrator', 'number' => 10, 'fields' => 'ID' ) ) as $candidate ) {
	if ( user_can( (int) $candidate, 'manage_options' ) && user_can( (int) $candidate, 'unfiltered_html' ) ) {
		$admin = (int) $candidate;
		break;
	}
}
if ( $admin === 0 ) {
	echo "  skip  this site has no administrator who may publish unfiltered HTML (multisite site admin, DISALLOW_UNFILTERED_HTML)\n";
	exit( 0 );
}

/** Act as one of five people. 'subscriber' and 'editor' are made up in memory: nothing is written to the users table. */
$act_as = static function ( string $who ) use ( $admin ): void {
	global $current_user;
	wp_set_current_user( 0 );
	if ( $who === 'visitor' ) {
		return;
	}
	if ( $who === 'subscriber' || $who === 'editor' ) {
		$role           = wp_roles()->get_role( $who );
		$user           = new WP_User( 0 );
		$user->ID       = $who === 'editor' ? 999002 : 999001;
		$user->roles    = array( $who );
		$user->caps     = array( $who => true );
		$user->allcaps  = array_merge( $role ? (array) $role->capabilities : array(), array( $who => true ) );
		$current_user   = $user;

		return;
	}
	wp_set_current_user( $admin );
};
// An administrator on a site that does not let them publish HTML (multisite site admin, DISALLOW_UNFILTERED_HTML).
$no_html = static fn( array $caps, string $cap ): array => $cap === 'unfiltered_html' ? array( 'do_not_allow' ) : $caps;

/** @return array{status:int, data:mixed, thrown:string} */
$call = static function ( string $method, string $path, array $params = array(), ?array $files = null ) use ( $ns ): array {
	$request = new WP_REST_Request( $method, '/' . $ns . $path );
	foreach ( $params as $key => $value ) {
		$request->set_param( (string) $key, $value );
	}
	if ( $files !== null ) {
		$request->set_file_params( $files );
	}
	try {
		$response = rest_do_request( $request );

		return array(
			'status' => (int) $response->get_status(),
			'data'   => $response->get_data(),
			'thrown' => '',
		);
	} catch ( Throwable $e ) {
		return array(
			'status' => 0,
			'data'   => null,
			'thrown' => get_class( $e ) . ': ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')',
		);
	}
};
$code_of = static fn( array $r ): string => is_array( $r['data'] ) && isset( $r['data']['code'] ) ? (string) $r['data']['code'] : '';
$posts   = static function (): int {
	global $wpdb;

	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
};

// --- The routes, as the server has them. ------------------------------------------------------------------------------------------
$table = array(); // 'METHOD /path' => array( route regex, path, method, handler )
foreach ( rest_get_server()->get_routes( $ns ) as $route => $handlers ) {
	$path = substr( (string) $route, strlen( '/' . $ns ) );
	foreach ( $handlers as $handler ) {
		foreach ( array_keys( (array) $handler['methods'] ) as $method ) {
			$table[ $method . ' ' . $path ] = array(
				'route'   => (string) $route,
				'path'    => $path,
				'method'  => (string) $method,
				'handler' => $handler,
			);
		}
	}
}
echo count( $table ) . " routes of $ns\n";

// --- 1. Who may call what. --------------------------------------------------------------------------------------------------------
echo "\n1. Who may call what\n";
/*
 * The tiers. 'public': anybody (the namespace index WordPress makes, and the form a visitor submits). 'editor': edit_posts. 'manage':
 * manage_options. Every other route imports, converts or publishes a design's HTML, CSS and JavaScript and asks for manage_options
 * and unfiltered_html (Converter_Controller::publish_permissions()): that is the default, so a route added without being listed
 * here is held to the strictest rule, and this says so.
 */
$tiers = array(
	'public' => array( 'GET ', 'POST /forms/submit' ),
	'editor' => array( 'GET /utility-rules' ),
	'manage' => array( 'GET /settings', 'POST /settings', 'POST /settings/test', 'GET /patterns', 'GET /import-info', 'POST /refine-structure', 'GET /forms/entries', 'GET /forms/export', 'GET /design', 'GET /design/(?P<id>\d+)', 'GET /design/(?P<id>\d+)/variation' ),
);
$tier_of = static function ( string $key ) use ( $tiers ): string {
	foreach ( $tiers as $tier => $keys ) {
		if ( in_array( $key, $keys, true ) ) {
			return $tier;
		}
	}

	return 'publish';
};
// Who is let in, by tier: visitor, subscriber, editor, administrator, administrator without unfiltered_html.
$let_in = array(
	'public'  => array( true, true, true, true, true ),
	'editor'  => array( false, false, true, true, true ),
	'manage'  => array( false, false, false, true, true ),
	'publish' => array( false, false, false, true, false ),
);
$people = array( 'visitor', 'subscriber', 'editor', 'administrator', 'administrator without unfiltered_html' );

/**
 * What is wrong with one route's permission handling, as a list of sentences (empty: nothing).
 *
 * @param array<string, mixed> $handler
 * @return array<int, string>
 */
$audit = static function ( string $key, array $handler ) use ( $tier_of, $let_in, $people, $act_as, $no_html ): array {
	$problems = array();
	$tier     = $tier_of( $key );
	if ( $key !== 'GET ' && ( ! array_key_exists( 'permission_callback', $handler ) || $handler['permission_callback'] === null ) ) {
		return array( 'it has no permission callback (WordPress would let anybody in)' );
	}
	$request = new WP_REST_Request( strtok( $key, ' ' ), '/x' );
	foreach ( $people as $i => $who ) {
		$act_as( $i < 4 ? $who : 'administrator' );
		if ( $i === 4 ) {
			add_filter( 'map_meta_cap', $no_html, 10, 2 );
		}
		$callback = $handler['permission_callback'] ?? '__return_true';
		$answer   = call_user_func( $callback, $request ) === true;
		if ( $i === 4 ) {
			remove_filter( 'map_meta_cap', $no_html, 10 );
		}
		if ( $answer !== $let_in[ $tier ][ $i ] ) {
			$problems[] = ( $answer ? 'lets in ' : 'refuses ' ) . $who . ' (a ' . $tier . ' route ' . ( $let_in[ $tier ][ $i ] ? 'lets them in' : 'refuses them' ) . ')';
		}
	}

	return $problems;
};
$listed = array();
foreach ( $tiers as $keys ) {
	$listed = array_merge( $listed, $keys );
}
$unknown = array_values( array_diff( $listed, array_keys( $table ) ) );
$expect( 'every route the table above names is registered (no stale entry)', $unknown === array(), implode( ', ', $unknown ) );
$bad = array();
foreach ( $table as $key => $row ) {
	foreach ( $audit( $key, $row['handler'] ) as $problem ) {
		$bad[] = "$key: $problem";
	}
}
$expect( 'every route lets in the people its tier says, and nobody else', $bad === array(), implode( '; ', array_slice( $bad, 0, 6 ) ) );
$publish = array_filter( array_keys( $table ), static fn( $k ) => $tier_of( $k ) === 'publish' );
$expect( 'the routes that import, convert and publish are the strictest tier (' . count( $publish ) . ' of ' . count( $table ) . ')', count( $publish ) >= 20, (string) count( $publish ) );
// The checks above must be able to fail: a route open to everyone, and one with no callback.
$open = $audit( 'POST /made-up', array( 'methods' => array( 'POST' => true ), 'permission_callback' => '__return_true' ) );
$none = $audit( 'POST /made-up-too', array( 'methods' => array( 'POST' => true ) ) );
$expect( 'a route made open to everyone is reported (the check is able to catch one)', count( $open ) >= 4, implode( '; ', $open ) );
$expect( 'a route with no permission callback is reported (the check is able to catch one)', count( $none ) === 1, implode( '; ', $none ) );

// --- 2. The same, through the server. ---------------------------------------------------------------------------------------------
echo "\n2. A visitor, through the server\n";
$concrete = static fn( string $route ): string => (string) preg_replace( '/\(\?P<\w+>[^)]*\)/', '1', $route );
$required = static function ( array $handler ): array {
	$params = array();
	foreach ( (array) ( $handler['args'] ?? array() ) as $name => $spec ) {
		if ( empty( $spec['required'] ) ) {
			continue;
		}
		$type  = (string) ( $spec['type'] ?? 'string' );
		$value = isset( $spec['enum'][0] ) ? $spec['enum'][0] : ( 'integer' === $type ? 1 : ( 'boolean' === $type ? true : ( in_array( $type, array( 'array', 'object' ), true ) ? array() : 'x' ) ) );
		// A string that must look like something (a token of 32 hex digits) is given one that does: the server checks the arguments
		// before it asks who is calling, and an argument that is refused first hides the answer this is after.
		if ( ! empty( $spec['pattern'] ) ) {
			foreach ( array( 'x', str_repeat( 'a', 32 ), str_repeat( 'a', 8 ), '1', 'a' ) as $candidate ) {
				if ( preg_match( '#' . str_replace( '#', '\#', (string) $spec['pattern'] ) . '#', $candidate ) === 1 ) {
					$value = $candidate;
					break;
				}
			}
		}
		$params[ $name ] = $value;
	}

	return $params;
};
$act_as( 'visitor' );
$wrong = array();
$asked = 0;
foreach ( $table as $key => $row ) {
	if ( $tier_of( $key ) === 'public' ) {
		continue;
	}
	++$asked;
	$request = new WP_REST_Request( $row['method'], $concrete( $row['route'] ) );
	foreach ( $required( $row['handler'] ) as $name => $value ) {
		$request->set_param( $name, $value );
	}
	try {
		$status = (int) rest_do_request( $request )->get_status();
	} catch ( Throwable $e ) {
		$status = -1;
	}
	if ( $status !== 401 && $status !== 403 ) {
		$wrong[] = "$key answered $status";
	}
}
$expect( "a visitor is refused (401 or 403) by all $asked routes that are not public", $wrong === array(), implode( '; ', array_slice( $wrong, 0, 6 ) ) );

// --- 3. Bad input. ----------------------------------------------------------------------------------------------------------------
echo "\n3. Bad input, as an administrator\n";
$act_as( 'administrator' );
$not_there = 999999999;
$text_file = wp_tempnam( 'dxai-route-test.zip' );
file_put_contents( $text_file, 'this is not a zip archive' );
$upload = static fn( array $over = array() ): array => array(
	'file' => $over + array(
		'name'     => 'design.zip',
		'type'     => 'application/zip',
		'tmp_name' => $text_file,
		'error'    => 0,
		'size'     => (int) filesize( $text_file ),
	),
);
$before_posts = $posts();
// label => array( method, path, params, files, what is expected: 'error' (a 4xx with a code) or 'ok' (200) )
$cases = array(
	'process-source: nothing pasted'                    => array( 'POST', '/process-source', array(), null, 'error' ),
	'process-source: a source type nobody knows'        => array( 'POST', '/process-source', array( 'source_type' => 'bogus' ), null, 'error' ),
	'process-source: an archive was meant, none came'   => array( 'POST', '/process-source', array( 'source_type' => 'auto-zip' ), null, 'error' ),
	'process-source: a file that is not a ZIP'          => array( 'POST', '/process-source', array( 'source_type' => 'auto-zip' ), $upload(), 'error' ),
	'process-source: an upload PHP refused'             => array( 'POST', '/process-source', array( 'source_type' => 'auto-zip' ), $upload( array( 'error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '' ) ), 'error' ),
	'generate-block: nothing pasted'                    => array( 'POST', '/generate-block', array( 'fidelity' => 'off' ), null, 'error' ),
	'generate-block: a source type nobody knows'        => array( 'POST', '/generate-block', array( 'source_type' => 'bogus', 'fidelity' => 'off' ), null, 'error' ),
	'generate-block: a file that is not a ZIP'          => array( 'POST', '/generate-block', array( 'source_type' => 'auto-zip', 'fidelity' => 'off' ), $upload(), 'error' ),
	'save-pattern: nothing to save'                     => array( 'POST', '/save-pattern', array(), null, 'error' ),
	'save-pattern: text that is not block markup'       => array( 'POST', '/save-pattern', array( 'gutenberg_markup' => 'no blocks here', 'block_title' => 'x' ), null, 'error' ),
	'preview: nothing to show'                          => array( 'POST', '/preview', array(), null, 'error' ),
	'preview: text that is not block markup'            => array( 'POST', '/preview', array( 'gutenberg_markup' => 'no blocks here' ), null, 'error' ),
	'restore: a snapshot that is not there'             => array( 'POST', '/restore', array( 'conversion_id' => $not_there, 'scope' => 'site' ), null, 'error' ),
	'refine-structure: no structure'                    => array( 'POST', '/refine-structure', array(), null, 'error' ),
	'refine-structure: a structure that is a string'    => array( 'POST', '/refine-structure', array( 'structure' => 'x' ), null, 'error' ),
	'crawl-step: a job nobody started'                  => array( 'POST', '/crawl-step', array( 'job' => 'nope' ), null, 'error' ),
	'crawl-status: a job nobody started'                => array( 'GET', '/crawl-status', array( 'job' => 'nope' ), null, 'error' ),
	'save-status: a token nobody used'                  => array( 'GET', '/save-status', array( 'token' => 'nope' ), null, 'ok' ),
	'settings/test: an engine nobody has'               => array( 'POST', '/settings/test', array( 'provider' => 'nonexistent' ), null, 'error' ),
	'settings/test: Lovable, which has no API'          => array( 'POST', '/settings/test', array( 'provider' => 'lovable' ), null, 'error' ),
	'forms/submit: the trap field filled (a bot)'       => array( 'POST', '/forms/submit', array( DXAI_UI\Blocks\Form_Block::HONEYPOT => 'x', 'email' => 'a@example.org' ), null, 'ok' ),
	'forms/submit: the old trap field filled'           => array( 'POST', '/forms/submit', array( 'website' => 'x', 'email' => 'a@example.org' ), null, 'ok' ),
	'transfer/import: no package'                       => array( 'POST', '/transfer/import', array(), null, 'error' ),
	'transfer/import: a file that is not a package'     => array( 'POST', '/transfer/import', array( 'dry_run' => true ), array( 'package' => $upload()['file'] ), 'error' ),
	'transfer/import/continue: a token nobody has'      => array( 'POST', '/transfer/import/continue', array( 'token' => str_repeat( '0', 32 ) ), null, 'error' ),
	'transfer/export: a page that is not there'         => array( 'GET', '/transfer/export/' . $not_there, array( 'format' => 'json' ), null, 'error' ),
	'site-pages (list): a design that is not there'     => array( 'GET', '/site-pages', array( 'home' => 0 ), null, 'error' ),
	'site-pages (start): a design that is not there'    => array( 'POST', '/site-pages', array( 'home' => 0, 'paths' => array( '/' ) ), null, 'error' ),
	'theme-colors: a design that is not there'          => array( 'POST', '/theme-colors', array( 'design' => 0, 'mode' => 'follow' ), null, 'error' ),
	'theme-colors/classes: a design that is not there'  => array( 'POST', '/theme-colors/classes', array( 'design' => 0, 'action' => 'apply' ), null, 'error' ),
	'theme-colors/propose: a design that is not there'  => array( 'POST', '/theme-colors/propose', array( 'design' => 0 ), null, 'error' ),
	'native-blocks (apply): a design that is not there' => array( 'POST', '/native-blocks', array( 'design' => 0, 'action' => 'apply' ), null, 'error' ),
	'native-blocks (revert): a design that is not there' => array( 'POST', '/native-blocks', array( 'design' => 0, 'action' => 'revert' ), null, 'error' ),
	'speed: a design that is not there'                 => array( 'POST', '/speed', array( 'design' => 0, 'action' => 'fonts-own' ), null, 'error' ),
	'copy (read): a page that is not there'             => array( 'GET', '/copy/' . $not_there, array(), null, 'error' ),
	'copy (write): a page that is not there'            => array( 'POST', '/copy/' . $not_there, array( 'action' => 'save' ), null, 'error' ),
	'team-pages (plan): a design that is not there'     => array( 'POST', '/team-pages', array( 'design' => 0, 'action' => 'plan' ), null, 'error' ),
	'team-pages (make): a design that is not there'     => array( 'POST', '/team-pages', array( 'design' => 0, 'action' => 'make' ), null, 'error' ),
	'template: an action nobody knows'                  => array( 'POST', '/template', array( 'action' => 'bogus' ), null, 'error' ),
	'template: no action'                               => array( 'POST', '/template', array(), null, 'error' ),
	'design: rebuild of a page that is no design\'s'    => array( 'POST', '/design/' . $not_there, array( 'action' => 'rebuild' ), null, 'error' ),
	'design: an action nobody knows'                    => array( 'POST', '/design/' . $not_there, array( 'action' => 'bogus' ), null, 'error' ),
	'variation: of a page that is no design\'s'         => array( 'POST', '/design/' . $not_there . '/variation', array( 'action' => 'apply' ), null, 'error' ),
	'variation: an action nobody knows'                 => array( 'POST', '/design/' . $not_there . '/variation', array( 'action' => 'bogus' ), null, 'error' ),
);
$wrong = array();
foreach ( $cases as $label => $case ) {
	list( $method, $path, $params, $files, $want ) = $case;
	$r  = $call( $method, $path, $params, $files );
	// A refusal is a 4xx, or the 501 a route that is not there to be used answers (Lovable has no API); a 500 is a fault.
	$ok = $r['thrown'] === '' && ( 'ok' === $want ? $r['status'] === 200 : ( ( ( $r['status'] >= 400 && $r['status'] < 500 ) || $r['status'] === 501 ) && $code_of( $r ) !== '' ) );
	if ( ! $ok ) {
		$wrong[] = "$label → " . ( $r['thrown'] !== '' ? 'THROWN ' . $r['thrown'] : $r['status'] . ' ' . $code_of( $r ) );
	}
}
$expect( count( $cases ) . ' cases of bad input each get an answer, never a fatal (4xx with a code, or 200 where nothing is wrong)', $wrong === array(), implode( ' | ', $wrong ) );
$expect( 'and the trap field of the public form stores nothing and still says ok', ( $call( 'POST', '/forms/submit', array( DXAI_UI\Blocks\Form_Block::HONEYPOT => 'x' ) )['data']['ok'] ?? false ) === true );
$expect( 'and no post was written by any of them', $posts() === $before_posts, $before_posts . ' → ' . $posts() );
// The check above must be able to fail: a call that does throw is reported as such.
$boom = $call( 'GET', '/forms/entries', array() );
$expect( 'a successful call is not mistaken for an error answer (the check can tell them apart)', $boom['status'] === 200 && $code_of( $boom ) === '' );
@unlink( $text_file );

// --- 4. The wizard's chain. -------------------------------------------------------------------------------------------------------
echo "\n4. The wizard's chain: process-source, generate-block, preview\n";
$component = <<<'TSX'
export default function Page() {
	return (
		<main className="px-6 py-16">
			<section className="mx-auto max-w-3xl">
				<h1 className="text-4xl font-bold">Route test</h1>
				<p className="mt-4 text-lg">A section with a video in it.</p>
				<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" title="Demo video" width="560" height="315"></iframe>
			</section>
		</main>
	);
}
TSX;
$body  = array(
	'source_type' => 'lovable-code',
	'title'       => 'Route test',
	'code'        => $component,
	'css'         => '',
	'fidelity'    => 'off',
);
$posts_before = $posts();
$read         = $call( 'POST', '/process-source', $body );
$expect( 'process-source reads the paste', $read['status'] === 200 && isset( $read['data']['source'] ), $read['thrown'] . ' ' . $read['status'] . ' ' . $code_of( $read ) );
$made = $call( 'POST', '/generate-block', $body );
$expect( 'generate-block answers 200, not a fatal', $made['status'] === 200 && $made['thrown'] === '', $made['thrown'] . ' ' . $made['status'] . ' ' . $code_of( $made ) );
$result     = is_array( $made['data'] ) && is_array( $made['data']['result'] ?? null ) ? $made['data']['result'] : array();
$structures = is_array( $result['structures'] ?? null ) ? $result['structures'] : array();
$markup     = (string) ( $result['gutenberg_markup'] ?? '' );
$all        = $markup;
foreach ( $structures as $structure ) {
	$all .= "\n" . (string) ( $structure['gutenberg_markup'] ?? '' );
}
$expect( 'the paste is compiled from its source into sections', ( $result['compiler'] ?? '' ) === 'source' && count( $structures ) >= 1, (string) ( $result['compiler'] ?? '?' ) . ' / ' . count( $structures ) );
$expect( 'as block markup', str_contains( $markup, '<!-- wp:' ) );
$expect( 'the YouTube iframe is the team\'s facade for that video, in the answer', str_contains( $markup, 'class="youtube-facade"' ) && str_contains( $markup, 'data-video-id="dQw4w9WgXcQ"' ) );
$expect( 'and nowhere a live player', ! str_contains( $all, '<iframe' ) );
$page_blocks = array();
foreach ( $structures as $i => $structure ) {
	$page_blocks[] = $structure + array( 'included' => true, 'key' => (string) $i );
}
$shown = $call(
	'POST',
	'/preview',
	array(
		'gutenberg_markup' => $markup,
		'custom_css'       => (string) ( $result['custom_css'] ?? '' ),
		'custom_js'        => (string) ( $result['custom_js'] ?? '' ),
		'structures'       => $page_blocks,
		'design_css_raw'   => (string) ( $result['design_css_raw'] ?? '' ),
		'wrapper_class'    => (string) ( $result['wrapper_class'] ?? '' ),
	)
);
$expect( 'preview renders it', $shown['status'] === 200 && is_array( $shown['data'] ) && (string) ( $shown['data']['html'] ?? '' ) !== '', $shown['thrown'] . ' ' . $shown['status'] . ' ' . $code_of( $shown ) );
$expect( 'reading, compiling and previewing write no post', $posts() === $posts_before, $posts_before . ' → ' . $posts() );

// --- 5. An administrator who may not publish HTML. --------------------------------------------------------------------------------
echo "\n5. An administrator without unfiltered_html\n";
$act_as( 'administrator' );
add_filter( 'map_meta_cap', $no_html, 10, 2 );
$refused = $call( 'POST', '/generate-block', $body );
$info    = $call( 'GET', '/import-info' );
remove_filter( 'map_meta_cap', $no_html, 10 );
$expect( 'generate-block says why it refuses (403, with the sentence the import screen shows)', $refused['status'] === 403 && $code_of( $refused ) === 'dxai_ui_unfiltered_html' && is_array( $refused['data'] ) && ( $refused['data']['message'] ?? '' ) === Converter_Controller::unfiltered_html_message(), $refused['status'] . ' ' . $code_of( $refused ) );
$expect( 'import-info tells the screen before anything is uploaded', $info['status'] === 200 && ( $info['data']['can_import'] ?? null ) === false && (string) ( $info['data']['import_refusal'] ?? '' ) !== '', $info['status'] . ' ' . wp_json_encode( array( $info['data']['can_import'] ?? null ) ) );

// --- 6. The key never comes back. -------------------------------------------------------------------------------------------------
echo "\n6. GET settings never carries an API key\n";
$secret   = 'sk-test-' . bin2hex( random_bytes( 12 ) );
$fake     = Options::defaults();
$fake['anthropic_api_key'] = Secret_Store::encrypt( $secret );
$set_fake = static fn() => $fake;
add_filter( 'pre_option_' . Options::OPTION_KEY, $set_fake );
$act_as( 'administrator' );
$read_settings = $call( 'GET', '/settings' );
remove_filter( 'pre_option_' . Options::OPTION_KEY, $set_fake );
$json = (string) wp_json_encode( $read_settings['data'] );
$expect( 'the answer says a key is configured', $read_settings['status'] === 200 && ( $read_settings['data']['settings']['anthropic_api_key_configured'] ?? false ) === true, $read_settings['status'] . '' );
$expect( 'and carries neither the key nor its middle', ! str_contains( $json, $secret ) && ! str_contains( $json, substr( $secret, 6, 18 ) ) );
$expect( 'the field that held it is empty', ( $read_settings['data']['settings']['anthropic_api_key'] ?? 'x' ) === '' );

// --- 7. The screens and the routes agree. -----------------------------------------------------------------------------------------
echo "\n7. The admin app and the routes agree\n";
/**
 * The route problems of one script of the admin app: a path it calls that no route has, or a method no handler of the route has.
 *
 * @param array<string, array<int, string>> $routes route regex => its methods.
 * @return array<int, string>
 */
$contract = static function ( string $js, array $routes ) use ( $ns ): array {
	$problems = array();
	$has      = static function ( string $path ) use ( $routes ): ?array {
		foreach ( $routes as $regex => $methods ) {
			if ( preg_match( '@^' . $regex . '$@i', $path ) === 1 ) {
				return $methods;
			}
		}

		return null;
	};
	if ( preg_match_all( '#[\'"](/' . preg_quote( $ns, '#' ) . '/[A-Za-z0-9_\-/]*)(?:\?[^\'"]*)?[\'"]#', $js, $m ) ) {
		foreach ( array_unique( $m[1] ) as $path ) {
			$path = rtrim( $path, '/' );
			if ( $path === '/' . $ns ) {
				continue;
			}
			if ( $has( $path ) === null ) {
				$problems[] = "calls $path, which is not a route";
			}
		}
	}
	if ( preg_match_all( '#postForm\(\s*[\'"]([A-Za-z0-9_\-/]+)[\'"]#', $js, $m ) ) {
		foreach ( array_unique( $m[1] ) as $name ) {
			$methods = $has( '/' . $ns . '/' . $name );
			if ( $methods === null || ! in_array( 'POST', $methods, true ) ) {
				$problems[] = "posts to /$name, which is not a POST route";
			}
		}
	}
	if ( preg_match_all( '#path:\s*[\'"](/' . preg_quote( $ns, '#' ) . '/[A-Za-z0-9_\-/]+)[^\'"]*[\'"][\s,]*method:\s*[\'"]([A-Z]+)[\'"]#', $js, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $hit ) {
			$methods = $has( rtrim( $hit[1], '/' ) );
			if ( $methods !== null && ! in_array( $hit[2], $methods, true ) ) {
				$problems[] = 'uses ' . $hit[2] . ' on ' . $hit[1] . ', which is ' . implode( '/', $methods );
			}
		}
	}

	return $problems;
};
$routes = array();
foreach ( $table as $row ) {
	$routes[ $row['route'] ][] = $row['method'];
}
$dir   = DXAI_UI_DIR . 'assets/src/admin';
$files = array();
if ( is_dir( $dir ) ) {
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
		if ( preg_match( '/\.(js|jsx)$/', (string) $f ) === 1 ) {
			$files[] = (string) $f;
		}
	}
}
if ( $files === array() ) {
	echo "  skip  the admin app's source (assets/src/admin) is not here, as in an installed copy\n";
} else {
	$bad = array();
	$n   = 0;
	foreach ( $files as $file ) {
		$js = (string) file_get_contents( $file );
		$n += preg_match_all( '#/' . preg_quote( $ns, '#' ) . '/#', $js );
		foreach ( $contract( $js, $routes ) as $problem ) {
			$bad[] = str_replace( '\\', '/', substr( $file, strlen( $dir ) + 1 ) ) . ' ' . $problem;
		}
	}
	$expect( 'all ' . count( $files ) . ' scripts of the admin app call only routes that exist, with the methods they have (' . $n . ' route strings)', $bad === array() && $n > 20, implode( '; ', $bad ) . ( $n > 20 ? '' : " (only $n route strings found: is the pattern still right?)" ) );
	$made_up = "apiFetch( { path: '/dxai-ui/v1/does-not-exist' } ); postForm( 'also-not-there', body ); apiFetch( { path: '/dxai-ui/v1/settings', method: 'DELETE' } );";
	$expect( 'a path, a form post and a method the server does not have are each reported (the check is able to catch them)', count( $contract( $made_up, $routes ) ) === 3, implode( '; ', $contract( $made_up, $routes ) ) );
}

// --- Leave things as they were. ---------------------------------------------------------------------------------------------------
wp_set_current_user( $original );
$expect( 'no request left the machine', $http === array(), implode( ', ', $http ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

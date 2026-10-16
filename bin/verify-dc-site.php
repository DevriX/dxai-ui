<?php
/**
 * A Claude Design export that is a whole site: every page, and the header and footer they import (Dc_Site, Dc_Renderer).
 *
 *   bash bin/wp-php.sh bin/verify-dc-site.php        (or: wp eval-file bin/verify-dc-site.php)
 *
 * Claude Design keeps a site as one `.dc.html` per page, and the parts every page shares — the header, the footer — as files of
 * their own that the pages pull in with `<dc-import name="…">`. The connector read the first file as THE page: the other pages
 * were dropped, an import drew nothing (no header, no footer), and links between pages named files (`WTS%20Why.dc.html`).
 *
 * Builds its archives in a temporary folder and removes them; writes nothing to the site. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Compiler\Dc_Renderer;
use DXAI_UI\Compiler\Dc_Script;
use DXAI_UI\Compiler\Source_Compiler;
use DXAI_UI\Connectors\Zip_Router;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

$dir = trailingslashit( get_temp_dir() ) . 'dxai-ui-verify-dc-site-' . wp_generate_password( 8, false, false );
wp_mkdir_p( $dir );
$made = array();
register_shutdown_function(
	static function () use ( $dir, &$made ): void {
		foreach ( $made as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		if ( is_dir( $dir ) ) {
			rmdir( $dir );
		}
	}
);
/**
 * One archive of the given files, read the way the admin and the command line read every archive.
 *
 * @param array<string, string> $files Archive path → contents.
 */
$read = static function ( string $name, array $files ) use ( $dir, &$made ) {
	$path = $dir . '/' . $name . '.zip';
	$zip  = new ZipArchive();
	if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		return new WP_Error( 'zip', 'could not write ' . $path );
	}
	foreach ( $files as $file => $contents ) {
		$zip->addFromString( $file, $contents );
	}
	$zip->close();
	$made[] = $path;

	return Zip_Router::import( array( 'tmp_name' => $path, 'name' => $name . '.zip' ) );
};
// A .dc.html file: its helmet (title, style) and its template.
$dc = static fn( string $body, string $style = '', string $title = '' ): string => '<!DOCTYPE html><html><head><meta charset="utf-8"><script src="./support.js"></script></head><body><x-dc>'
	. '<helmet>' . ( $title !== '' ? '<title>' . $title . '</title>' : '' ) . ( $style !== '' ? '<style>' . $style . '</style>' : '' ) . '</helmet>' . $body . '</x-dc></body></html>';

$nav = $dc(
	'<div style="width:100%;background:#fff"><a href="Acme%20Home.dc.html"><img src="assets/logo.svg" alt="Acme"></a>'
	. '<a href="Acme%20About.dc.html" style="color:#333" style-hover="color:#c00">About us</a>'
	. '<a href="Acme%20Services.dc.html" style="color:#333" style-hover="color:#0c0">Services<span class="caret">▼</span></a>'
	. '<a href="Acme%20Contact.dc.html">Talk to us</a></div>',
	'.acme-menu{position:absolute;top:100%}'
);
$footer = $dc( '<div style="background:#222;color:#eee"><a href="Acme%20About.dc.html#team">Our team</a> <a href="Acme%20Contact.dc.html">Contact</a> <a href="Acme%20Pricing.dc.html">See plans</a> <a href="Acme%20Pricing.dc.html#faq">Pricing FAQ</a> © 2026 Acme</div>' );
$page   = static fn( string $inner, string $title = '' ) => $dc(
	'<div style="width:100%;font-family:Poppins,sans-serif"><dc-import name="Acme Nav" hint-size="100%,80px"></dc-import>' . $inner . '<dc-import name="Acme Footer"></dc-import></div>',
	'',
	$title
);
$site = array(
	'Acme Home.dc.html'              => $page( '<div style="padding:80px"><h1>Acme builds roofs</h1><a href="Acme%20Services.dc.html#storm" style="color:#000" style-hover="color:#00f">Storm repair</a></div><div style="padding:40px"><p>Since 1990.</p></div>' ),
	'Acme About.dc.html'             => $page( '<div style="padding:80px"><h1>About Acme</h1><p id="team">Our team.</p></div>' ),
	'Acme Services.dc.html'          => $page( '<div style="padding:80px"><h1>Services</h1><a href="Acme%20Home.dc.html" style-hover="color:#f0f">Home</a></div>', 'Roofing Services | Acme' ),
	'Acme Contact.dc.html'           => $page( '<div style="padding:80px"><h1>Contact</h1></div>' ),
	'Acme Pricing.dc.html'           => $page( '<div style="padding:80px"><h1>Plans</h1><p id="faq">Questions</p></div>' ),
	'Acme Nav.dc.html'               => $nav,
	'Acme Footer.dc.html'            => $footer,
	'Acme About -export-.dc.html'    => $dc( '<div><h1>About Acme</h1></div>' ),
	'support.js'                     => '/* runtime */',
	'assets/logo.svg'                => '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>',
);

echo "Reading a site\n";
$doc = $read( 'acme-site', $site );
$p   = is_wp_error( $doc ) ? array() : $doc->payload;
$expect( 'it is read as a Claude Design export', ! is_wp_error( $doc ) && $doc->kind === 'dc-zip', is_wp_error( $doc ) ? $doc->get_error_message() : $doc->kind );
$expect( 'the Home is the page named for it', ( $p['dc']['name'] ?? '' ) === 'Acme Home', (string) ( $p['dc']['name'] ?? '' ) );
$routes = array_column( $p['dc_pages'] ?? array(), 'route', 'name' );
$expect( 'every other page is read, each once', array_keys( $routes ) === array( 'Acme About', 'Acme Contact', 'Acme Pricing', 'Acme Services' ), wp_json_encode( array_keys( $routes ) ) );
$expect( 'what the pages import is a part, not a page', array_keys( $p['dc_components'] ?? array() ) === array( 'Acme Footer', 'Acme Nav' ), wp_json_encode( array_keys( $p['dc_components'] ?? array() ) ) );
$expect( 'a copy of a page (… -export-) is left out', ! isset( $routes['Acme About -export-'] ) && str_contains( implode( ' ', $doc->logs ?? array() ), 'Acme About -export-' ) );
$expect( 'each page has a route from its own name, without the words every name starts with', $routes === array( 'Acme About' => '/about', 'Acme Contact' => '/contact', 'Acme Pricing' => '/pricing', 'Acme Services' => '/services' ), wp_json_encode( $routes ) );
$titles = array_column( $p['dc_pages'] ?? array(), 'page_title', 'name' );
$expect( 'a page is titled by its <title> without the site\'s name after it', ( $titles['Acme Services'] ?? '' ) === 'Roofing Services', (string) ( $titles['Acme Services'] ?? '' ) );
$expect( 'else by the label of a link to it that shares most of its name, the caret not part of it', ( $titles['Acme About'] ?? '' ) === 'About us' && ( $titles['Acme Contact'] ?? '' ) === 'Contact', wp_json_encode( $titles ) );
$expect( 'a link to a part of a page (#faq) does not name the page, and with no label that fits it is named after itself', ( $titles['Acme Pricing'] ?? '' ) === 'Pricing', wp_json_encode( $titles ) );
$templates = (string) ( $p['dc']['template'] ?? '' ) . implode( '', array_column( $p['dc_pages'] ?? array(), 'template' ) ) . implode( '', array_column( $p['dc_components'] ?? array(), 'template' ) );
$expect( 'every link to a page names its route, the fragment kept', str_contains( $templates, 'href="/services#storm"' ) && str_contains( $templates, 'href="/about#team"' ) && str_contains( $templates, 'href="/"' ) && ! str_contains( $templates, '.dc.html' ), $templates === '' ? 'no templates' : '' );
$expect( 'the stylesheet holds the parts\' styles too', str_contains( (string) ( $p['design_css_raw'] ?? '' ), '.acme-menu' ) );

echo "\nDrawing an import\n";
$components = $p['dc_components'] ?? array();
$render     = static fn( string $template, array $parts ): array => ( new Dc_Renderer( new Dc_Script( '', array() ) ) )->with_components( $parts )->render( $template );
$out        = $render( (string) ( $p['dc']['template'] ?? '' ), $components );
$html       = $out['html'];
$expect( 'the page\'s first import is drawn as its header, its last as its footer', preg_match( '/^\s*<div[^>]*>\s*<header\b/', $html ) === 1 && preg_match( '/<\/footer>\s*<\/div>\s*$/', $html ) === 1, substr( $html, 0, 160 ) );
$expect( 'the part\'s own root becomes the landmark: no extra level', substr_count( $html, '<header' ) === 1 && str_contains( $html, '<header style="width:100%;background:#fff"' ), substr( $html, 0, 200 ) );
$expect( 'with the part\'s content in it', str_contains( $html, 'About us' ) && str_contains( $html, '© 2026 Acme' ) );
preg_match_all( '/dxai-sh-(\d+)/', $html, $ids );
preg_match_all( '/\.dxai-sh-(\d+):hover/', $out['css'], $rules );
$expect( 'the part\'s classes and the page\'s are numbered as one: each class is one rule', count( array_unique( $ids[1] ) ) === 3 && count( $rules[1] ) === count( array_unique( $rules[1] ) ) && count( $rules[1] ) === 3, wp_json_encode( array( $ids[1], $rules[1] ) ) );
$none = $render( '<div><dc-import name="Missing"></dc-import><p>x</p></div>', $components );
$expect( 'an import of a part the export does not hold draws nothing, as before', ! str_contains( $none['html'], 'dc-import' ) && str_contains( $none['html'], '<p>x</p>' ) );
$loop = $render( '<div><dc-import name="Loop"></dc-import></div>', array( 'Loop' => array( 'template' => '<div><b>once</b><dc-import name="Loop"></dc-import></div>', 'script' => '', 'props' => array() ) ) );
$expect( 'a part that imports itself is drawn once', substr_count( $loop['html'], '<b>once</b>' ) === 1, $loop['html'] );
$mid = $render( '<div><p>a</p><dc-import name="Acme Footer"></dc-import><p>b</p></div>', $components );
$expect( 'an import in the middle of a page is drawn where it stands, as no landmark', ! str_contains( $mid['html'], '<footer' ) && ! str_contains( $mid['html'], '<header' ) && str_contains( $mid['html'], '© 2026 Acme' ) );

echo "\nCompiling the site\n";
$result = is_wp_error( $doc ) ? array() : ( new Source_Compiler() )->compile( $doc );
$types  = static fn( array $s ): array => array_values( array_map( static fn( $x ) => (string) $x['type'], $s ) );
$home   = $types( $result['structures'] ?? array() );
$expect( 'the Home has the site\'s header and footer', ( $home[0] ?? '' ) === 'header' && ( $home[ count( $home ) - 1 ] ?? '' ) === 'footer', wp_json_encode( $home ) );
$pages = array_column( $result['pages'] ?? array(), null, 'slug' );
$expect( 'every other page is compiled at its route', array_keys( $pages ) === array( '/about', '/contact', '/pricing', '/services' ), wp_json_encode( array_keys( $pages ) ) );
$expect( 'under its own title', ( $pages['/about']['block_title'] ?? '' ) === 'About us' && ( $pages['/services']['block_title'] ?? '' ) === 'Roofing Services', wp_json_encode( array_column( $result['pages'] ?? array(), 'block_title' ) ) );
$expect( 'each with the header and the footer too', ( $types( $pages['/contact']['structures'] ?? array() )[0] ?? '' ) === 'header' && in_array( 'footer', $types( $pages['/contact']['structures'] ?? array() ), true ) );
$header = array_values( array_filter( $result['structures'] ?? array(), static fn( $s ) => $s['type'] === 'header' ) )[0] ?? array();
$expect( 'the header\'s menu is read from the part, its links at the routes', in_array( '/about', array_column( $header['menu_items'] ?? array(), 'url' ), true ) && in_array( '/services', array_column( $header['menu_items'] ?? array(), 'url' ), true ), wp_json_encode( $header['menu_items'] ?? null ) );
$css = (string) ( $result['custom_css'] ?? '' );
preg_match_all( '/\.dxai-sh-(\d+):hover/', $css, $all );
$expect( 'the hover rules of every page reach the stylesheet, each class once', in_array( 'color:#f0f !important', array_map( 'trim', explode( ';', $css ) ), true ) || str_contains( $css, 'color:#f0f' ), substr( $css, 0, 200 ) );
$expect( '…and no two pages share a class number', count( $all[1] ) === count( array_unique( $all[1] ) ) && count( $all[1] ) >= 5, wp_json_encode( $all[1] ) );
$expect( 'no link names a file any more', ! str_contains( (string) ( $result['gutenberg_markup'] ?? '' ) . implode( '', array_column( $result['pages'] ?? array(), 'gutenberg_markup' ) ), '.dc.html' ) );

echo "\nLinks to a part of a page, once the pages exist\n";
$some = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids' ) );
$pid  = (int) ( $some[0] ?? 0 );
if ( $pid < 1 ) {
	echo "  skip  this site has no published page to point at\n";
} else {
	$link = (string) get_permalink( $pid );
	$html = ( new DXAI_UI\Compiler\Internal_Links() )->rewrite_html(
		'<a href="/about">a</a><a href="/about#careers">b</a><!-- wp:x {"url":"/about?tab=2"} --><a href="/about-us#x">c</a><a href="#about">d</a>',
		array( array( 'id' => $pid, 'paths' => array( '/about' ) ) )
	);
	$expect( 'a link to a part of a page, or with a query, gets the page\'s address with the part kept', str_contains( $html, 'href="' . $link . '#careers"' ) && str_contains( $html, '"url":"' . $link . '?tab=2"' ) && str_contains( $html, 'href="' . $link . '"' ), $html );
	$expect( 'a longer path and an anchor of the page itself are left alone', str_contains( $html, 'href="/about-us#x"' ) && str_contains( $html, 'href="#about"' ), $html );
	$bind = new ReflectionMethod( DXAI_UI\Structures\Navigation_Factory::class, 'bind_pages' );
	$bind->setAccessible( true );
	$menu = $bind->invoke(
		new DXAI_UI\Structures\Navigation_Factory( 'verify-dc-site', 'verify' ),
		array(
			array( 'label' => 'About', 'url' => '/about' ),
			array( 'label' => 'Careers', 'url' => '/about#careers' ),
			array( 'label' => 'Top', 'url' => '#top' ),
			array( 'label' => 'Elsewhere', 'url' => 'https://example.org/about#x' ),
		),
		array( '/about' => $pid ),
		''
	);
	$urls = array_column( $menu, 'url', 'label' );
	$expect( 'a menu item to a part of a page gets the page\'s address with the part kept, as a link', ( $urls['Careers'] ?? '' ) === $link . '#careers' && ! isset( $menu[1]['page_id'] ) && ( $menu[0]['page_id'] ?? 0 ) === $pid, wp_json_encode( $menu ) );
	$expect( 'an anchor with no page in front of it, and another site\'s page, are left as they are', ( $urls['Top'] ?? '' ) === '#top' && ( $urls['Elsewhere'] ?? '' ) === 'https://example.org/about#x', wp_json_encode( $urls ) );
}

echo "\nA site whose pages carry the header and footer written in\n";
$head   = '<header style="background:#fff"><a href="Inline%20Home.dc.html"><img src="assets/logo.svg" alt="Acme"></a><a href="Inline%20About.dc.html">About us</a></header>';
$foot   = '<footer style="background:#222"><a href="Inline%20About.dc.html">About</a> © 2026</footer>';
$inline = static fn( string $inner ) => $dc( '<div style="width:100%">' . $head . $inner . $foot . '</div>' );
$doc2   = $read(
	'inline-site',
	array(
		'Inline Home.dc.html'   => $inline( '<div style="padding:80px"><h1>Home</h1></div>' ),
		'Inline About.dc.html'  => $inline( '<div style="padding:80px"><h1>About</h1></div>' ),
		'Inline Nav.dc.html'    => $dc( $head, '.menu{display:flex}' ),
		'Inline Footer.dc.html' => $dc( $foot ),
	)
);
$p2 = is_wp_error( $doc2 ) ? array() : $doc2->payload;
$expect( 'a file that is nothing but a header or a footer is a part, not a page, though no page imports it', array_column( $p2['dc_pages'] ?? array(), 'name' ) === array( 'Inline About' ) && array_keys( $p2['dc_components'] ?? array() ) === array( 'Inline Footer', 'Inline Nav' ), wp_json_encode( array( array_column( $p2['dc_pages'] ?? array(), 'name' ), array_keys( $p2['dc_components'] ?? array() ) ) ) );
$r2 = is_wp_error( $doc2 ) ? array() : ( new Source_Compiler() )->compile( $doc2 );
$t2 = $types( $r2['structures'] ?? array() );
$expect( 'and the pages\' own header and footer are the site\'s', ( $t2[0] ?? '' ) === 'header' && ( $t2[ count( $t2 ) - 1 ] ?? '' ) === 'footer' && count( $r2['pages'] ?? array() ) === 1, wp_json_encode( $t2 ) );
$solo_head = $read( 'only-header', array( 'Banner.dc.html' => $dc( $head ) ) );
$expect( 'a header that is the export\'s only file is still its page', ! is_wp_error( $solo_head ) && ( $solo_head->payload['dc']['name'] ?? '' ) === 'Banner' );

echo "\nA one-page export reads as before\n";
$one = $read( 'acme-one', array( 'Solo.dc.html' => $dc( '<div><h1>Solo</h1><dc-import name="Elsewhere"></dc-import></div>', 'h1{color:red}', 'Solo page' ) ) );
$q   = is_wp_error( $one ) ? array() : $one->payload;
$expect( 'its page is the page, with no other pages and no parts', ( $q['dc']['name'] ?? '' ) === 'Solo' && ( $q['dc_pages'] ?? null ) === array() && ( $q['dc_components'] ?? null ) === array() );
$expect( 'its stylesheet and sources are its own, as before', ( $q['design_css_raw'] ?? '' ) === 'h1{color:red}' && array_keys( $q['sources'] ?? array() ) === array( 'Solo.dc.html' ), wp_json_encode( array( $q['design_css_raw'] ?? null, array_keys( $q['sources'] ?? array() ) ) ) );
$solo = is_wp_error( $one ) ? array() : ( new Source_Compiler() )->compile( $one );
$expect( 'and an import it cannot draw is still reported', in_array( 'unsupported <dc-import>', array_column( $solo['unevaluated'] ?? array(), 'source' ), true ), wp_json_encode( $solo['unevaluated'] ?? null ) );

echo "\nA handoff whose page is index.html\n";
// Taylored Restoration's handoff: index.html with the template and the component's script, support.js, README, assets — no .dc.html.
// Read as a generated design its states were never run: both dropdowns open, a banner that is off shown.
// Its pictures go through the resource helper the script declares before its component, each declared in the helmet too.
$script  = '<script type="text/x-dc" data-dc-script data-props="{&quot;banner&quot;:{&quot;editor&quot;:&quot;boolean&quot;,&quot;default&quot;:false,&quot;tsType&quot;:&quot;boolean&quot;}}">'
	. "const R=(id,p)=>(window.__resources&&window.__resources[id])||p;\nclass Component extends DCLogic { state = { open: false }; crew = [ { img: R('crew1','assets/crew-photo.png'), alt: 'Our crew' } ]; renderVals() { return { crew: this.crew }; } }</script>";
$handoff = '<!DOCTYPE html><html><head><meta charset="utf-8"><script src="./support.js"></script></head><body><x-dc><helmet><title>Handoff Home</title><meta name="ext-resource-dependency" content="assets/crew-photo.png" data-resource-id="crew1" /></helmet>'
	. '<div data-root><header><a href="#main">Logo</a></header><main id="main"><section><h1>Handoff builds decks</h1>'
	. '<sc-if value="{{ banner }}"><p>Storm alert banner</p></sc-if><p>Since 1972.</p><sc-for list="{{ crew }}" as="c"><img src="{{ c.img }}" alt="{{ c.alt }}"></sc-for></section></main><footer><p>Handoff Inc.</p></footer></div></x-dc>'
	. $script . '</body></html>';
$png = static function (): string {
	$im = imagecreatetruecolor( 40, 30 );
	imagefill( $im, 0, 0, imagecolorallocate( $im, 200, 40, 30 ) );
	ob_start();
	imagepng( $im );

	return (string) ob_get_clean();
};
$h  = $read( 'handoff', array( 'Handoff/index.html' => $handoff, 'Handoff/support.js' => '/* runtime */', 'Handoff/README.md' => '# Handoff', 'Handoff/assets/crew-photo.png' => $png() ) );
$hp = is_wp_error( $h ) ? array() : $h->payload;
$expect( 'an export with no .dc.html whose index.html holds the template and the component\'s script is a Claude Design export', ! is_wp_error( $h ) && 'dc-zip' === $h->kind, is_wp_error( $h ) ? $h->get_error_message() : $h->kind );
$expect( '…its page is the Home, named without .html', 'index' === ( $hp['dc']['name'] ?? '' ) && array() === ( $hp['dc_pages'] ?? null ), wp_json_encode( array( $hp['dc']['name'] ?? null, $hp['dc_pages'] ?? null ) ) );
$hr    = is_wp_error( $h ) ? array() : ( new Source_Compiler() )->compile( $h );
$htypes = array_column( $hr['structures'] ?? array(), 'type' );
$hhtml  = implode( '', array_column( $hr['structures'] ?? array(), 'source_html' ) );
$expect( 'a picture its script names through the resource helper (R(\'crew1\', \'assets/…\'), declared in the helmet) is the uploaded file — not "[object Object]", not the archive path', 1 === preg_match( '#<img[^>]*src="([^"]+)"[^>]*alt="Our crew"#', $hhtml, $cm ) && str_contains( $cm[1], '/uploads/' ) && str_contains( $cm[1], 'crew-photo' ), $cm[1] ?? substr( $hhtml, 0, 300 ) );
$expect( '…and its component runs: a banner that is off by default is not drawn, and its header and footer are the site\'s', ! str_contains( $hhtml, 'Storm alert banner' ) && str_contains( $hhtml, 'Since 1972.' ) && 'header' === ( $htypes[0] ?? '' ) && 'footer' === end( $htypes ), wp_json_encode( $htypes ) );
$standalone = $site;
$standalone['index.html'] = $handoff;
$st = $read( 'acme-standalone', $standalone );
$sp = is_wp_error( $st ) ? array() : $st->payload;
$expect( 'an export with .dc.html pages keeps to them: an index.html beside them (a bundle of the Home) is no page of its own', ! is_wp_error( $st ) && 'Acme Home' === ( $sp['dc']['name'] ?? '' ) && array_keys( $routes ) === array_column( $sp['dc_pages'] ?? array(), 'name' ), wp_json_encode( array_column( $sp['dc_pages'] ?? array(), 'name' ) ) );
$plain = $read( 'xdc-plain', array( 'index.html' => '<!DOCTYPE html><html><body><x-dc><div><h1>Template only</h1></div></x-dc></body></html>' ) );
$expect( 'an HTML page with an <x-dc> template and no component script stays a generated design', ! is_wp_error( $plain ) && 'dc-zip' !== $plain->kind, is_wp_error( $plain ) ? $plain->get_error_message() : $plain->kind );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

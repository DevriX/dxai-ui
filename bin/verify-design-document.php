<?php
/**
 * The Design IR: one document that says what a design is, whatever it came from.
 *
 *   bash bin/wp-php.sh bin/verify-design-document.php        (or: wp eval-file bin/verify-design-document.php --user=1)
 *
 * A design's pages, sections, header and footer, navigation, tokens, fonts, breakpoints, assets, behaviours and the compiler's
 * report, as one record (Design\Document, docs/PLAN-ARCHITECTURE.md section 4): built from a compile result before anything is
 * saved (Document_Builder), the same from what the import saved (Structure_Repository::save() records it on the Home), kept on
 * the Home (Document_Store), read by the REST route and the command.
 *
 * Against the small Claude Design fixture (.verify/dc-minimal.zip) and, when one is on this machine, a Lovable archive of the
 * corpus. The saved round imports the fixture under a name of its own and removes everything it made (pages, parts, menus,
 * patterns, the conversion snapshot, the files it sideloaded and the stylesheet) when it ends, however it ends. Exit code 1 when
 * a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Compiler\Source_Compiler;
use DXAI_UI\Connectors\Dc_Connector;
use DXAI_UI\Connectors\Lovable_Connector;
use DXAI_UI\Content\Content_Types;
use DXAI_UI\Design\Behaviour_Reader;
use DXAI_UI\Design\Document;
use DXAI_UI\Design\Document_Builder;
use DXAI_UI\Design\Document_Store;
use DXAI_UI\Pages\Section_Library;
use DXAI_UI\Pages\Section_Roles;
use DXAI_UI\Structures\Design_Attach;
use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Structures\Structure_Repository;
use DXAI_UI\Support\Design_Cli;
use DXAI_UI\Support\Upload_Paths;

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

$run     = substr( md5( microtime( true ) . wp_rand() ), 0, 8 );
$made    = array(); // posts this suite made itself
$saved   = array(); // what the saved round made (Structure_Repository::save()'s answer)
$max_att = (int) $GLOBALS['wpdb']->get_var( "SELECT MAX(ID) FROM {$GLOBALS['wpdb']->posts} WHERE post_type = 'attachment'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$fin     = static function () use ( &$made, &$saved, $max_att ): void {
	global $wpdb;
	$home = (int) ( $saved['page_id'] ?? 0 );
	if ( $home > 0 ) {
		$file = Upload_Paths::for_meta( $home, '_dxai_ui_css_url' )['path'] ?? '';
		if ( is_string( $file ) && $file !== '' && is_file( $file ) ) {
			wp_delete_file( $file );
		}
		foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ), 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => Page_Scope::META, 'meta_value' => $home ) ) as $id ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			wp_delete_post( (int) $id, true );
		}
		foreach ( get_posts( array( 'post_type' => Content_Types::CONVERSION, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => '_dxai_ui_page_id', 'meta_value' => $home ) ) as $id ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			wp_delete_post( (int) $id, true );
		}
		$ids = array_merge(
			array( $home, (int) ( $saved['header_id'] ?? 0 ), (int) ( $saved['footer_id'] ?? 0 ) ),
			array_map( 'intval', (array) ( $saved['extra_page_ids'] ?? array() ) ),
			array_map( 'intval', (array) ( $saved['navigation_ids'] ?? array() ) ),
			array_map( static fn( $p ) => (int) ( is_array( $p ) ? ( $p['id'] ?? 0 ) : $p ), (array) ( $saved['patterns'] ?? array() ) )
		);
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
		// The pictures the save sideloaded for the fixture: attachments made after this suite began, marked as the plugin's own.
		$new = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_dxai_ui_asset_kind' WHERE p.post_type = 'attachment' AND p.ID > %d", $max_att ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $new as $id ) {
			wp_delete_attachment( (int) $id, true );
		}
		$saved = array();
	}
	foreach ( array_reverse( $made ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
	$made = array();
};
register_shutdown_function( $fin );

$root = dirname( __DIR__ );
$zip  = $root . '/.verify/dc-minimal.zip';
if ( ! is_file( $zip ) ) {
	echo "  FAIL  the fixture .verify/dc-minimal.zip is not here\n";
	exit( 1 );
}

echo "A Claude Design export, before anything is saved\n";
$source = ( new Dc_Connector() )->import_zip( array( 'tmp_name' => $zip, 'name' => 'dc-minimal.zip' ) );
if ( is_wp_error( $source ) ) {
	echo '  FAIL  the connector: ' . $source->get_error_message() . "\n";
	exit( 1 );
}
$native = ( new Source_Compiler() )->compile( $source );
$expect( 'the compiler says which connector read the design, and hashes its files', 'dc-zip' === ( $native['source_kind'] ?? '' ) && preg_match( '/^[0-9a-f]{40}$/', (string) ( $native['source_hash'] ?? '' ) ) === 1, wp_json_encode( array( $native['source_kind'] ?? null, $native['source_hash'] ?? null ) ) );
$expect( 'and the width a Claude Design component switches at', (int) ( $native['dc_breakpoint'] ?? 0 ) > 0, (string) ( $native['dc_breakpoint'] ?? 'none' ) );
$again = ( new Source_Compiler() )->compile( $source );
$expect( 'the same archive compiled again has the same hash', ( $native['source_hash'] ?? 'a' ) === ( $again['source_hash'] ?? 'b' ) );

$doc  = Document_Builder::from_result( $native );
$data = $doc->to_array();
$expect( 'a document is built: version 1, the design\'s title, one page at /', Document::VERSION === $data['v'] && '' !== $data['title'] && '/' === $data['home_slug'] && 1 === count( $data['pages'] ) && '/' === $data['pages'][0]['slug'], wp_json_encode( array( $data['title'], $data['home_slug'], count( $data['pages'] ) ) ) );
$expect( 'the source is the connector\'s: its kind, the archive\'s name, static HTML, the hash', 'dc-zip' === $data['source']['kind'] && 'dc-minimal.zip' === $data['source']['name'] && true === $data['source']['static_html'] && $data['source']['hash'] === $native['source_hash'] );
$secs = $data['pages'][0]['sections'];
$roles = array_map( static fn( array $s ): string => $s['role'], $secs );
$expect( 'the page\'s sections are read from the compiled blocks: the fixture\'s five, the first a hero, every role one the pages know', count( $secs ) >= 4 && 'hero' === ( $roles[0] ?? '' ) && array() === array_diff( $roles, Section_Roles::ROLES ) && 'compiled' === $data['pages'][0]['sections_source'], implode( ',', $roles ) );
$expect( 'each section says what it holds: a heading, its words, whether it has a picture, a form, a button', isset( $secs[0]['heading'], $secs[0]['words'], $secs[0]['has']['image'], $secs[0]['has']['form'], $secs[0]['has']['button'], $secs[0]['sig'] ) && '' !== $secs[0]['heading'] && $secs[0]['words'] > 0, wp_json_encode( $secs[0] ?? null ) );
$expect( 'each section knows the structure the compiler made it from (the Claude Design screen labels), the sections being the structures one to one', true === $data['pages'][0]['sections_match'] && 'Top' === ( $secs[0]['source_name'] ?? '' ) && 'Close' === ( $secs[1]['source_name'] ?? '' ), wp_json_encode( array( $data['pages'][0]['sections_match'], array_column( $secs, 'source_name' ) ) ) );
$expect( 'each section\'s layout is what its classes say, or nothing: the four facts, or null', array() === array_filter( $secs, static fn( array $s ): bool => ! ( null === $s['layout'] || ( is_array( $s['layout'] ) && isset( $s['layout']['columns'], $s['layout']['direction'], $s['layout']['centered'], $s['layout']['max_width'] ) ) ) ), wp_json_encode( array_column( $secs, 'layout' ) ) );
$expect( 'the fixture links to no site of its own', '' === $data['source']['origin'] );
$old_site = Document_Builder::from_result(
	array(
		'block_title'    => 'Old site design',
		'slug'           => '/',
		'gutenberg_markup' => '<!-- wp:paragraph --><p>Words</p><!-- /wp:paragraph -->',
		'source_html'    => '<nav><a href="https://www.old.example/service/water/">Water</a><a href="https://old.example/about/">About</a><a href="https://old.example/contact/">Contact</a><a href="https://facebook.com/x">FB</a></nav>',
		'design_css_raw' => '',
		'structures'     => array( array( 'type' => 'header', 'title' => 'Header', 'gutenberg_markup' => '', 'source_html' => '<nav><a href="https://www.old.example/service/water/">Water</a><a href="https://old.example/about/">About</a><a href="https://old.example/contact/">Contact</a></nav>', 'menu_tree' => array() ) ),
	)
)->to_array();
$expect( 'a design whose header links to its own old site says which host that is, under one name', 'old.example' === $old_site['source']['origin'], wp_json_encode( $old_site['source'] ) );
$this_site = Document_Builder::from_result( array( 'block_title' => 'This site', 'slug' => '/', 'gutenberg_markup' => '<!-- wp:paragraph --><p>Words</p><!-- /wp:paragraph -->', 'source_html' => '<a href="' . home_url( '/a/' ) . '">A</a><a href="' . home_url( '/b/' ) . '">B</a>', 'design_css_raw' => '', 'structures' => array(), 'pages_origin' => home_url() ) )->to_array();
$expect( '…and a design whose crawl was this site (a re-import of a site onto itself) has no origin of its own', '' === $this_site['source']['origin'], wp_json_encode( $this_site['source'] ) );
$with_items = array_values( array_filter( $secs, static fn( array $s ): bool => count( $s['items'] ?? array() ) >= 2 ) );
$expect( 'a section that repeats items says what they are called (the fixture\'s cards and questions)', count( $with_items ) >= 1 && count( $with_items[0]['items'] ) === $with_items[0]['repeats'], wp_json_encode( array_map( static fn( array $s ): array => array( $s['heading'], $s['repeats'], $s['items'] ), $secs ) ) );
$expect( 'the page counts its words and lists its links', $data['pages'][0]['words'] > 50 && is_array( $data['pages'][0]['links'] ), (string) $data['pages'][0]['words'] );
$hdr = $data['regions']['header'];
$expect( 'the header is there, in the page\'s content before a save, with the navigation read from the compiled markup', is_array( $hdr ) && true === $hdr['present'] && 'content' === $hdr['placed'] && count( $hdr['nav'] ) >= 1 && 'compiled' === $hdr['nav_source'] && isset( $hdr['nav'][0]['label'], $hdr['nav'][0]['url'], $hdr['nav'][0]['children'] ), wp_json_encode( $hdr ) );
$expect( '…and the document\'s navigation is the header\'s', $data['nav'] === ( $hdr['nav'] ?? null ) );
$expect( 'the footer is there too', is_array( $data['regions']['footer'] ) && true === $data['regions']['footer']['present'] );
$tk = $data['tokens'];
$expect( 'the tokens: a brand and an accent as hex colours, where they came from, the design\'s palette', preg_match( '/^#[0-9A-Fa-f]{6}$/', (string) ( $tk['roles']['brand'] ?? '' ) ) === 1 && preg_match( '/^#[0-9A-Fa-f]{6}$/', (string) ( $tk['roles']['accent'] ?? '' ) ) === 1 && '' !== $tk['source'] && count( $tk['palette'] ) >= 1, wp_json_encode( array( $tk['roles'] ?? null, $tk['source'] ?? null, count( $tk['palette'] ?? array() ) ) ) );
$expect( 'the fonts the design loads, by family', count( $tk['fonts'] ) >= 1 && '' !== (string) ( $tk['fonts'][0]['family'] ?? '' ), wp_json_encode( $tk['fonts'] ) );
$expect( 'a Claude Design export has no Tailwind screens: none are claimed', array() === $tk['screens'] && array() === $tk['screens_declared'] );
$expect( 'its breakpoint is the width the component switches at', in_array( (int) $native['dc_breakpoint'], $data['breakpoints'], true ), wp_json_encode( $data['breakpoints'] ) );
$kinds    = array_column( $data['behaviours'], 'kind' );
$declared = array_filter( $data['behaviours'], static fn( array $b ): bool => ! array_key_exists( 'interactivity', $b ) );
$expect( 'what the design does: a layout that changes with the width is one of its behaviours, each of a kind the document knows, none of what its code declares yet said to be carried', in_array( 'media-query', $kinds, true ) && array() === array_diff( $kinds, Document::BEHAVIOURS ) && $declared !== array() && array() === array_filter( $declared, static fn( array $b ): bool => null !== $b['compiled'] ), implode( ',', $kinds ) );
$touched = array_filter( $data['behaviours'], static fn( array $b ): bool => array_key_exists( 'interactivity', $b ) );
$expect( 'what the compiled page does when touched is read back from its markup, each row saying what the Interactivity API could do about it (the fixture: sections that reveal, at least)', $touched !== array() && in_array( 'reveal', array_column( $touched, 'kind' ), true ) && array() === array_filter( $touched, static fn( array $b ): bool => ! in_array( $b['interactivity'], Behaviour_Reader::LEVELS, true ) || true !== $b['compiled'] ), wp_json_encode( array_map( static fn( array $b ): string => $b['kind'] . ( isset( $b['name'] ) ? ':' . $b['name'] : '' ) . '=' . $b['interactivity'], $touched ) ) );
$expect( 'the assets: the fixture\'s pictures are counted and listed', $data['assets']['images'] >= 1 && count( $data['assets']['list'] ) === $data['assets']['images'] && isset( $data['assets']['list'][0]['url'] ), wp_json_encode( $data['assets'] ) );
$expect( 'the report carries the compiler\'s own list of what it could not evaluate, and no coverage before a save', is_array( $data['report']['unevaluated'] ) && array() === $data['report']['coverage'] );
// Before a save the colours are still literals (Token_Styles writes the tokens and their classes as it saves): the measure is there, at zero.
$expect( 'the report measures the residual CSS and how the blocks carry their colours (as presets, as custom colours, as classes)', isset( $data['report']['css']['bytes'], $data['report']['css']['rules'], $data['report']['bound']['text']['class'], $data['report']['bound']['background']['class'] ) && $data['report']['css']['bytes'] > 0 && $data['report']['css']['literals'] > 0, wp_json_encode( array( $data['report']['css'] ?? null, $data['report']['bound'] ?? null ) ) );
$expect( 'the layout tree is not claimed in version 1', null === $data['layout'] );
$expect( 'built by this plugin, by the source compiler, at a time', DXAI_UI_VERSION === $data['built']['plugin'] && 'source' === $data['built']['compiler'] && '' !== $data['built']['at'] );
$expect( 'nothing is wrong with it', array() === Document::problems( $data ), implode( '; ', Document::problems( $data ) ) );
$expect( 'the array form comes back the same through from_array()', Document::from_array( $data )->to_array() === $data );
$twice = Document_Builder::from_result( $native );
$expect( 'the same result built twice has the same fingerprint, though it was built at another time', $doc->fingerprint() === $twice->fingerprint() && $doc->to_array()['built']['at'] !== '' );
$expect( 'another title is another fingerprint', $doc->with( array( 'title' => 'Another design' ) )->fingerprint() !== $doc->fingerprint() );
$expect( 'a page\'s post id is not part of the fingerprint: the design is the same on any site', ( static function () use ( $data, $doc ): bool {
	$d                   = $data;
	$d['pages'][0]['id'] = 4242;
	$d['regions']['header']['placed'] = 'menus';

	return Document::from_array( $d )->fingerprint() === $doc->fingerprint();
} )() );
$expect( 'the summary counts it', ( static function () use ( $doc, $data ): bool {
	$s = $doc->summary();

	return $s['pages'] === 1 && $s['sections'] === count( $data['pages'][0]['sections'] ) && $s['nav_items'] === count( $data['nav'] ) && 'content' === $s['header'] && $s['fingerprint'] === $doc->fingerprint();
} )() );
$expect( 'home() is the page at the home address', ( $doc->home()['slug'] ?? '' ) === '/' );

echo "\nWhat the compiled page does when touched (Behaviour_Reader)\n";
// A header whose menu opens on click and closes on a click elsewhere, turns solid past 24px (a derived flag), a FAQ with three
// questions (an index with a text map), a carousel moved by steps, a Radix accordion and tabs, a dialog and a popover, a revealing
// section, a counting number, a facade.
$markup = '<!-- wp:dxai-ui/box --><header class="sticky dxai-cls-dark" data-dxai-on="bg-paper/95 backdrop-blur-md" data-dxai-off="bg-transparent" data-dxai-any="dark:solid,open" data-dxai-scroll="solid:24" data-dxai-outside="open">'
	. '<button class="dxai-toggle-open" aria-expanded="false">Menu</button><nav class="hidden dxai-on-open"><a href="#a">A</a></nav>'
	. '<div class="relative"><button class="dxai-toggle-open--services" data-dxai-hover="enter" data-dxai-mode="toggle" aria-expanded="false">Services</button><div class="hidden dxai-on-open--services">panel</div></div>'
	. '</header><section class="faq"><button class="dxai-toggle-openFaq--0" data-dxai-mode="toggle" aria-expanded="true"><span data-dxai-text-openFaq="{&quot;0&quot;:&quot;−&quot;,&quot;null&quot;:&quot;+&quot;}">−</span>Q1</button><div class="dxai-on-openFaq--0">A1</div>'
	. '<button class="dxai-toggle-openFaq--1" data-dxai-mode="toggle" aria-expanded="false">Q2</button><div class="hidden dxai-on-openFaq--1">A2</div>'
	. '<button class="dxai-toggle-openFaq--2" data-dxai-mode="toggle" aria-expanded="false">Q3</button><div class="hidden dxai-on-openFaq--2">A3</div></section>'
	. '<section class="rail"><div class="flex overflow-x-auto snap-x"><div class="dxai-cls-slide--0 is-on" data-dxai-on="is-on">1</div><div class="dxai-cls-slide--1" data-dxai-on="is-on">2</div></div>'
	. '<button class="dxai-toggle-slide" data-dxai-step="-1" data-dxai-init="0" data-dxai-disabled="slide:0" aria-label="Previous" disabled>‹</button><button class="dxai-toggle-slide" data-dxai-step="1" aria-label="Next">›</button></section>'
	. '<section><button id="dxai-rx-1-trigger-a" aria-expanded="false">Acc</button><div role="region" aria-labelledby="dxai-rx-1-trigger-a" hidden>…</div>'
	. '<button role="tab" id="dxai-rx-2-trigger-a" aria-selected="true">Tab</button><div role="tabpanel" aria-labelledby="dxai-rx-2-trigger-a">…</div>'
	. '<button aria-haspopup="dialog" aria-controls="dxai-rx-3-content-1" aria-expanded="false">Open</button><div id="dxai-rx-3-content-1" role="dialog" style="display:none" data-dxai-portal="1">…</div>'
	. '<button aria-haspopup="dialog" aria-controls="dxai-rx-4-content-1" aria-expanded="false">Pop</button><div id="dxai-rx-4-content-1" role="dialog" data-side="bottom" style="display:none">…</div>'
	. '<span class="rv-num" data-to="62" data-suffix="%">0%</span><i data-lucide="star"></i><div class="youtube-facade" data-video-id="x"></div></section><!-- /wp:dxai-ui/box -->';
$read   = Behaviour_Reader::from_markup( $markup );
$by     = array();
foreach ( $read['states'] as $row ) {
	$by[ $row['name'] ] = $row;
}
$expect( 'every state the compiler marked is read: the menu, its submenu value, the derived flag and its scroll member, the FAQ index, the slide', array( 'dark', 'open', 'openFaq', 'slide', 'solid' ) === array_keys( array_filter( $by ) ) || array() === array_diff( array( 'dark', 'open', 'openFaq', 'slide', 'solid' ), array_keys( $by ) ), implode( ',', array_keys( $by ) ) );
$expect( 'the menu: a value state (open or a submenu), driven by click, hover and a click elsewhere, projecting panels and aria', isset( $by['open'] ) && 'value' === $by['open']['type'] && array( 'services' ) === $by['open']['values'] && array( 'click', 'hover', 'outside' ) === $by['open']['drivers'] && 2 === $by['open']['projections']['panels'] && 2 === $by['open']['projections']['aria'] && null === $by['open']['initial'], wp_json_encode( $by['open'] ?? null ) );
$expect( 'the solid flag is driven by the scroll past 24px; the dark flag is derived from it and the menu, and projects classes', isset( $by['solid'], $by['dark'] ) && array( 'scroll' ) === $by['solid']['drivers'] && 24 === $by['solid']['threshold'] && array( 'derived' ) === $by['dark']['drivers'] && array( 'solid', 'open' ) === $by['dark']['members'] && 1 === $by['dark']['projections']['classes'] && false === $by['dark']['initial'], wp_json_encode( array( $by['solid'] ?? null, $by['dark'] ?? null ) ) );
$expect( 'the FAQ: an index with three values, starting at the open one, with a text map on its sign', isset( $by['openFaq'] ) && 'index' === $by['openFaq']['type'] && array( '0', '1', '2' ) === $by['openFaq']['values'] && '0' === $by['openFaq']['initial'] && 1 === $by['openFaq']['projections']['text'] && 3 === $by['openFaq']['projections']['panels'], wp_json_encode( $by['openFaq'] ?? null ) );
$expect( 'the carousel: an index moved by steps from its authored start, a button disabled at the first', isset( $by['slide'] ) && 'index' === $by['slide']['type'] && array( 'step' ) === $by['slide']['drivers'] && '0' === $by['slide']['initial'] && 1 === $by['slide']['projections']['disabled'] && 2 === $by['slide']['projections']['classes'], wp_json_encode( $by['slide'] ?? null ) );
$expect( 'every state is one the Interactivity API expresses with directives, and says with which', array() === array_filter( $read['states'], static fn( array $s ): bool => 'full' !== $s['interactivity'] || '' === $s['why'] || true !== $s['compiled'] ) );
$runtime = array_column( $read['runtime'], null, 'kind' );
$expect( 'the rest the runtime does is counted by kind: the Radix accordion, tabs, a dialog told from a popover by its side, a revealing section, a counting number, an icon, a facade, a rail, a portal', 1 === ( $runtime['radix-accordion']['count'] ?? 0 ) && 1 === ( $runtime['radix-tabs']['count'] ?? 0 ) && 1 === ( $runtime['radix-dialog']['count'] ?? 0 ) && 1 === ( $runtime['radix-popover']['count'] ?? 0 ) && 4 === ( $runtime['reveal']['count'] ?? 0 ) && 1 === ( $runtime['countup']['count'] ?? 0 ) && 1 === ( $runtime['lucide']['count'] ?? 0 ) && 1 === ( $runtime['video-facade']['count'] ?? 0 ) && 1 === ( $runtime['rail']['count'] ?? 0 ) && 1 === ( $runtime['portal']['count'] ?? 0 ), wp_json_encode( array_map( static fn( array $r ): int => $r['count'], $runtime ) ) );
$expect( 'each says what the Interactivity API could do: tabs with directives, a reveal and an accordion with a callback, a dialog, a popover, a portal, a rail, icons and the facade not without the runtime — and why', 'full' === ( $runtime['radix-tabs']['interactivity'] ?? '' ) && 'partial' === ( $runtime['reveal']['interactivity'] ?? '' ) && 'partial' === ( $runtime['radix-accordion']['interactivity'] ?? '' ) && array() === array_filter( array( 'radix-dialog', 'radix-popover', 'portal', 'rail', 'lucide', 'video-facade' ), static fn( string $k ): bool => 'none' !== ( $runtime[ $k ]['interactivity'] ?? '' ) || '' === ( $runtime[ $k ]['why'] ?? '' ) ) );
$expect( 'a page with none of it reads as nothing', array( 'states' => array(), 'runtime' => array() ) === Behaviour_Reader::from_markup( '<!-- wp:paragraph --><p>Words</p><!-- /wp:paragraph -->' ) );
// What the fixture's code declares, plus the rows read from the markup above (the fixture's own rows read from its markup set aside).
$with_states = $doc->with( array( 'behaviours' => array_merge( array_values( $declared ), $read['states'], $read['runtime'] ) ) );
$expect( 'the summary counts what the Interactivity API could take over, by how much', ( static function () use ( $with_states ): bool {
	$i = $with_states->summary()['interactivity'] ?? array();

	// Five states and the tabs with directives; the reveal, the number and the accordion with a callback; six that keep the runtime.
	return array( 'full' => 6, 'partial' => 3, 'none' => 6 ) === $i;
} )(), wp_json_encode( $with_states->summary()['interactivity'] ?? null ) );
$expect( 'a state is a fact of the design: its name and its kind are in the fingerprint, a page\'s id is not', ( static function () use ( $with_states ): bool {
	$renamed = $with_states->to_array();
	foreach ( $renamed['behaviours'] as $i => $b ) {
		if ( ( $b['name'] ?? '' ) === 'openFaq' ) {
			$renamed['behaviours'][ $i ]['name'] = 'openQuestion';
		}
	}
	$moved                   = $with_states->to_array();
	$moved['pages'][0]['id'] = 4243;

	return Document::from_array( $renamed )->fingerprint() !== $with_states->fingerprint() && Document::from_array( $moved )->fingerprint() === $with_states->fingerprint();
} )() );
$expect( 'nothing is wrong with a document that carries them', array() === Document::problems( $with_states->to_array() ), implode( '; ', Document::problems( $with_states->to_array() ) ) );

echo "\nWhat problems() catches\n";
$broken = $data;
$broken['pages'][0]['sections'][0]['role'] = 'banana';
$expect( 'a section with a role the pages do not know', count( array_filter( Document::problems( $broken ), static fn( string $p ): bool => str_contains( $p, 'banana' ) ) ) === 1 );
$broken = $data;
$broken['pages'] = array();
$expect( 'a design with no pages', in_array( 'the design has no pages', Document::problems( $broken ), true ) );
$broken = $data;
$broken['source']['hash'] = 'nope';
$expect( 'a hash that is not a sha1', in_array( 'the source hash is not a sha1', Document::problems( $broken ), true ) );
$broken = $data;
$broken['regions']['header']['placed'] = 'attic';
$expect( 'a header placed somewhere the document does not know', count( array_filter( Document::problems( $broken ), static fn( string $p ): bool => str_contains( $p, 'header says it is placed' ) ) ) === 1 );
$broken = $data;
$broken['behaviours'][] = array( 'kind' => 'teleport', 'count' => 1, 'compiled' => null, 'note' => '' );
$expect( 'a behaviour of a kind the document does not know', count( array_filter( Document::problems( $broken ), static fn( string $p ): bool => str_contains( $p, 'teleport' ) ) ) === 1 );
$broken = $data;
$broken['behaviours'][] = array( 'kind' => 'reveal', 'count' => 1, 'compiled' => true, 'note' => '', 'interactivity' => 'maybe', 'why' => '' );
$broken['behaviours'][] = array( 'kind' => 'state', 'count' => 1, 'compiled' => true, 'note' => '', 'name' => '', 'interactivity' => 'full', 'why' => '' );
$expect( 'a behaviour the Interactivity API could "maybe" do, and a state with no name', count( array_filter( Document::problems( $broken ), static fn( string $p ): bool => str_contains( $p, 'maybe' ) ) ) === 1 && in_array( 'a state has no name', Document::problems( $broken ), true ) );
$broken = $data;
$broken['breakpoints'] = array( 12 );
$expect( 'a breakpoint that is no screen', count( array_filter( Document::problems( $broken ), static fn( string $p ): bool => str_contains( $p, 'breakpoint' ) ) ) === 1 );

echo "\nA Lovable archive of the corpus\n";
$lovable = '';
foreach ( array( 'Brand Polish Pass.zip', 'Careers Page Builder.zip', 'ARA Redesign Studio.zip', 'GTM Strategy Hub.zip' ) as $name ) {
	$try = 'C:/Users/DevriX/Documents/Lovable/' . $name;
	if ( is_file( $try ) ) {
		$lovable = $try;
		break;
	}
}
if ( $lovable === '' ) {
	echo "  (no Lovable archive on this machine: skipped, not failed)\n";
} else {
	$lsrc = ( new Lovable_Connector() )->import_zip( array( 'tmp_name' => $lovable, 'name' => basename( $lovable ) ) );
	if ( is_wp_error( $lsrc ) ) {
		$expect( 'the Lovable connector reads ' . basename( $lovable ), false, $lsrc->get_error_message() );
	} else {
		$lres = ( new Source_Compiler() )->compile( $lsrc );
		$ldoc = Document_Builder::from_result( $lres )->to_array();
		$expect( 'a Lovable design: its kind, its stack, a hash, not static HTML', 'lovable-zip' === $ldoc['source']['kind'] && preg_match( '/^[0-9a-f]{40}$/', $ldoc['source']['hash'] ) === 1 && false === $ldoc['source']['static_html'], wp_json_encode( $ldoc['source'] ) );
		$expect( 'its pages, the home first, each with sections', count( $ldoc['pages'] ) >= 1 && '/' === $ldoc['pages'][0]['slug'] && count( $ldoc['pages'][0]['sections'] ) >= 2, wp_json_encode( array_map( static fn( array $p ): array => array( $p['slug'], count( $p['sections'] ) ), $ldoc['pages'] ) ) );
		$with_source = array_filter( $ldoc['pages'], static fn( array $p ): bool => $p['source_sections'] !== array() );
		$expect( 'the source\'s own split of the pages travels with them (the sections as the TSX names them)', count( $with_source ) >= 1, wp_json_encode( array_map( static fn( array $p ): array => $p['source_sections'], $ldoc['pages'] ) ) );
		$expect( 'the Tailwind screens are known, and only the ones the design declares count as its breakpoints', isset( $ldoc['tokens']['screens']['md'] ) && $ldoc['tokens']['screens']['md'] === 768 && array() === array_diff( $ldoc['breakpoints'], array_merge( array_values( array_intersect_key( $ldoc['tokens']['screens'], array_flip( $ldoc['tokens']['screens_declared'] ) ) ), $ldoc['breakpoints'] ) ), wp_json_encode( array( $ldoc['tokens']['screens'], $ldoc['tokens']['screens_declared'], $ldoc['breakpoints'] ) ) );
		$expect( 'its tokens say where they came from, and its behaviours are what its code declares', '' !== $ldoc['tokens']['source'] && array() === array_diff( array_column( $ldoc['behaviours'], 'kind' ), Document::BEHAVIOURS ), wp_json_encode( array( $ldoc['tokens']['source'], array_column( $ldoc['behaviours'], 'kind' ) ) ) );
		$expect( 'nothing is wrong with it', array() === Document::problems( $ldoc ), implode( '; ', Document::problems( $ldoc ) ) );
		$laid = array();
		foreach ( $ldoc['pages'] as $lp ) {
			foreach ( $lp['sections'] as $ls ) {
				if ( is_array( $ls['layout'] ?? null ) ) {
					$laid[] = $ls['layout'];
				}
			}
		}
		$expect( 'what a Lovable design\'s own classes say of its layout is read: grids with their columns by breakpoint, flex rows, centred text', count( $laid ) >= 1 && count( array_filter( $laid, static fn( array $l ): bool => $l['columns'] !== array() ) ) >= 1, wp_json_encode( array_slice( $laid, 0, 3 ) ) );
		$expect( 'each of its sections knows the component it came from when they are the structures one to one', ( static function () use ( $ldoc ): bool {
			foreach ( $ldoc['pages'] as $lp ) {
				if ( $lp['sections_match'] && array() === array_filter( array_column( $lp['sections'], 'source_name' ), 'strlen' ) ) {
					return false;
				}
			}

			return true;
		} )(), wp_json_encode( array_map( static fn( array $lp ): array => array( $lp['sections_match'], array_column( $lp['sections'], 'source_name' ) ), $ldoc['pages'] ) ) );
	}
}
$expect( 'the layout reader: columns by breakpoint, a column that becomes a row, centred text, the widest content; nothing from markup that says nothing', ( static function (): bool {
	$l = Document_Builder::layout_of( '<section class="py-20 text-center"><div class="max-w-6xl mx-auto grid grid-cols-1 md:grid-cols-3 gap-8"><div class="flex flex-col md:flex-row">x</div></div></section>' );

	return is_array( $l ) && array( 'base' => 1, 'md' => 3 ) === $l['columns'] && 'column-then-row' === $l['direction'] && true === $l['centered'] && '6xl' === $l['max_width'] && null === Document_Builder::layout_of( '<section><p>Words</p></section>' );
} )() );

echo "\nThe store, the route and the command, on a Home made for the purpose\n";
$page = static function ( string $title, int $scope = 0 ) use ( &$made ): int {
	$id     = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => '<!-- wp:paragraph --><p>Fixture.</p><!-- /wp:paragraph -->' ) );
	$made[] = $id;
	update_post_meta( $id, Page_Scope::META, $scope > 0 ? $scope : $id );
	update_post_meta( $id, '_dxai_ui_generated_page', '1' );
	if ( $scope < 1 ) {
		update_post_meta( $id, '_dxai_ui_css_url', 'https://example.test/design-document-fixture.css' );
	}
	Design_Attach::forget( $id );

	return $id;
};
$home  = $page( 'Design document fixture ' . $run );
$child = $page( 'Design document fixture child ' . $run, $home );
$plain = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Design document fixture plain ' . $run, 'post_content' => '' ) );
$made[] = $plain;
$expect( 'a design\'s Home is its own; a page of the design points at it; a plain page is nobody\'s', Document_Store::home_of( $home ) === $home && Document_Store::home_of( $child ) === $home && 0 === Document_Store::home_of( $plain ) && 0 === Document_Store::home_of( 0 ) );
$expect( 'nothing is kept yet', null === Document_Store::load( $home ) );
$expect( 'the document is kept on the Home and comes back the same', Document_Store::save( $home, $doc ) && null !== Document_Store::load( $home ) && Document_Store::load( $home )->fingerprint() === $doc->fingerprint() && Document_Store::load( $child )?->fingerprint() === $doc->fingerprint() );
$expect( 'kept once: writing the same document again changes nothing and is still true; read back, the home page\'s id is this Home\'s', Document_Store::save( $home, $doc ) && Document_Store::load( $home )->fingerprint() === $doc->fingerprint() && ( Document_Store::load( $home )->pages()[0]['id'] ?? 0 ) === $home );
$expect( 'a document with a problem is not kept; the problem is', ( static function () use ( $home, $data ): bool {
	$bad = $data;
	$bad['pages'] = array();
	Document_Store::forget( $home );
	$kept = Document_Store::record( array(), array( 'page_id' => $home ) ); // a result that holds nothing: a home page row with no title
	unset( $bad );

	return null === $kept && null === Document_Store::load( $home ) && str_contains( Document_Store::error( $home ), 'no title' );
} )(), Document_Store::error( $home ) );
Document_Store::save( $home, $doc );
$expect( '…and the note goes when a document is kept', '' === Document_Store::error( $home ) );

$ns   = DXAI_UI_REST_NAMESPACE;
$rest = static function ( int $id, array $params = array() ) use ( $ns ): array {
	$request = new WP_REST_Request( 'GET', '/' . $ns . '/design/' . $id );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$response = rest_do_request( $request );
	$d        = $response->get_data();

	return array( 'status' => (int) $response->get_status(), 'data' => is_array( $d ) ? $d : array() );
};
$admin = (int) get_current_user_id();
$r     = $rest( $home );
$expect( 'GET /design/{id} answers an administrator with the design, its summary and its document', 200 === $r['status'] && ( $r['data']['design'] ?? 0 ) === $home && ( $r['data']['summary']['fingerprint'] ?? '' ) === $doc->fingerprint() && ( $r['data']['document']['v'] ?? 0 ) === Document::VERSION, wp_json_encode( array( $r['status'], array_keys( $r['data'] ) ) ) );
$r = $rest( $child, array( 'summary' => true ) );
$expect( '…by any page of the design, and the summary alone when asked', 200 === $r['status'] && ( $r['data']['design'] ?? 0 ) === $home && ! isset( $r['data']['document'] ) && isset( $r['data']['summary']['pages'] ) );
$r = $rest( $plain );
$expect( 'a page that is no design\'s is 404', 404 === $r['status'] && 'dxai_ui_no_design' === ( $r['data']['code'] ?? '' ), wp_json_encode( $r ) );
Document_Store::forget( $home );
$r = $rest( $home );
$expect( 'a design with no document yet is 404, and says why', 404 === $r['status'] && 'dxai_ui_no_document' === ( $r['data']['code'] ?? '' ) );
Document_Store::save( $home, $doc );
wp_set_current_user( 0 );
$r = $rest( $home );
wp_set_current_user( $admin );
$expect( 'a visitor is refused', in_array( $r['status'], array( 401, 403 ), true ), (string) $r['status'] );
$sub = (int) wp_insert_user( array( 'user_login' => 'ddoc-sub-' . $run, 'user_pass' => wp_generate_password( 24 ), 'role' => 'subscriber' ) );
if ( $sub > 0 ) {
	wp_set_current_user( $sub );
	$r = $rest( $home );
	wp_set_current_user( $admin );
	wp_delete_user( $sub );
	$expect( 'a subscriber is refused', 403 === $r['status'], (string) $r['status'] );
}
$lst = ( static function () use ( $ns ): array {
	$response = rest_do_request( new WP_REST_Request( 'GET', '/' . $ns . '/design' ) );
	$d        = $response->get_data();

	return array( 'status' => (int) $response->get_status(), 'data' => is_array( $d ) ? $d : array() );
} )();
$mine = array_values( array_filter( $lst['data']['designs'] ?? array(), static fn( array $d ): bool => (int) $d['id'] === $home ) );
$expect( 'GET /design lists the designs with whether each has a document and its summary', 200 === $lst['status'] && 1 === count( $mine ) && true === $mine[0]['document'] && ( $mine[0]['summary']['fingerprint'] ?? '' ) === $doc->fingerprint() && '' !== (string) $mine[0]['edit'], wp_json_encode( array( $lst['status'], $mine ) ) );
$expect( 'a page of the design is not listed as a design of its own', array() === array_filter( $lst['data']['designs'] ?? array(), static fn( array $d ): bool => (int) $d['id'] === $child ) );
// A document carried over in a transfer package names the posts of another site: the ids are made true again by address.
$stale = Document_Store::load( $home )->to_array();
$stale['pages'][0]['id'] = 987654321;
update_post_meta( $home, Document_Store::META, wp_slash( (string) wp_json_encode( $stale ) ) );
$expect( 'a page id that is not this site\'s is looked up again by the page\'s address when the document is read', ( Document_Store::load( $home )->pages()[0]['id'] ?? 0 ) === $home );
Document_Store::save( $home, $doc );
$text = Design_Cli::render( $doc, 'summary' );
$expect( 'the command prints the summary: the title, the pages with their roles, the fingerprint', str_contains( $text, $data['title'] ) && str_contains( $text, '/' ) && str_contains( $text, 'hero' ) && str_contains( $text, $doc->fingerprint() ), substr( $text, 0, 200 ) );
$json = json_decode( Design_Cli::render( $doc, 'json' ), true );
$expect( '…and the whole document as JSON', is_array( $json ) && $json === $data );

echo "\nThe import keeps the document: a saved round of the fixture, under a name of its own\n";
$fixture                = $native;
$fixture['block_title'] = 'Design document saved fixture ' . $run;
$fixture['slug']        = '/ddoc-fixture-' . $run . '/';
$fixture['source_name'] = 'ddoc-fixture-' . $run . '.zip';
$fixture['chrome']      = \DXAI_UI\Chrome\Chrome_Choice::KEEP;
$created = ( new Structure_Repository() )->save( $fixture );
if ( is_wp_error( $created ) ) {
	$expect( 'the fixture saves', false, $created->get_error_message() );
} else {
	$saved = $created;
	$hid   = (int) ( $created['page_id'] ?? 0 );
	$kept  = $hid > 0 ? Document_Store::load( $hid ) : null;
	$expect( 'the save writes the Home, and the document is on it', $hid > 0 && null !== $kept, wp_json_encode( array( $hid, Document_Store::error( $hid ) ) ) );
	if ( $kept !== null ) {
		$k = $kept->to_array();
		$expect( 'the document knows the Home\'s post', ( $k['pages'][0]['id'] ?? 0 ) === $hid );
		$expect( 'it is the same design as before the save: the same fingerprint', $kept->fingerprint() === Document_Builder::from_result( $fixture )->fingerprint(), wp_json_encode( array( $kept->summary(), Document_Builder::from_result( $fixture )->summary() ) ) );
		$expect( 'its sections are the ones the team pages read from the saved Home, and the document says they were read there', count( $k['pages'][0]['sections'] ) === count( Section_Library::for_page( $hid ) ) && 'saved' === ( $k['pages'][0]['sections_source'] ?? '' ), count( $k['pages'][0]['sections'] ) . ' vs ' . count( Section_Library::for_page( $hid ) ) . ' ' . ( $k['pages'][0]['sections_source'] ?? '' ) );
		$expect( 'the header kept as the site\'s own went into a template part, and the document says so', 'part' === ( $k['regions']['header']['placed'] ?? '' ) && (int) ( $created['header_id'] ?? 0 ) > 0, wp_json_encode( array( $k['regions']['header']['placed'] ?? null, $created['header_id'] ?? null ) ) );
		$expect( 'the coverage of the saved page is in the report', count( $k['report']['coverage'] ) >= 5 && isset( $k['report']['coverage'][0]['dimension'], $k['report']['coverage'][0]['short'] ), (string) count( $k['report']['coverage'] ) );
		$bound = $k['report']['bound'];
		$expect( 'saved, the colours are tokens: the report counts the blocks that carry them as classes, and no literal is left', $bound['text']['class'] + $bound['background']['class'] > 0 && 0 === (int) $k['report']['css']['literals'], wp_json_encode( array( $bound, $k['report']['css'] ) ) );
		$expect( 'the tokens are the ones the Home keeps', $k['tokens']['roles'] === array_diff_key( \DXAI_UI\Compiler\Design_Tokens::for_page( $hid ), array( 'source' => 1 ) ) );
		$r = $rest( $hid );
		$expect( 'the route serves it', 200 === $r['status'] && ( $r['data']['summary']['fingerprint'] ?? '' ) === $kept->fingerprint() );
		$expect( 'the save\'s answer carries the document\'s summary for the import summary', ( $created['document']['fingerprint'] ?? '' ) === $kept->fingerprint() && ( $created['document']['sections'] ?? -1 ) === count( $k['pages'][0]['sections'] ) );
		// A design imported before documents were kept: its conversion snapshot holds what the builder reads.
		Document_Store::forget( $hid );
		$rebuilt = Document_Store::rebuild( $hid );
		$expect( 'a design with no document gets one again from its conversion snapshot, the same design', ! is_wp_error( $rebuilt ) && $rebuilt->fingerprint() === $kept->fingerprint() && null !== Document_Store::load( $hid ), is_wp_error( $rebuilt ) ? $rebuilt->get_error_message() : '' );
		Document_Store::forget( $hid );
		$post_rebuild = ( static function () use ( $ns, $hid ): array {
			$request = new WP_REST_Request( 'POST', '/' . $ns . '/design/' . $hid );
			$request->set_param( 'action', 'rebuild' );
			$response = rest_do_request( $request );
			$d        = $response->get_data();

			return array( 'status' => (int) $response->get_status(), 'data' => is_array( $d ) ? $d : array() );
		} )();
		$expect( 'POST /design/{id} rebuilds it for the Library card and answers with the document', 200 === $post_rebuild['status'] && true === ( $post_rebuild['data']['rebuilt'] ?? false ) && ( $post_rebuild['data']['summary']['fingerprint'] ?? '' ) === $kept->fingerprint() && null !== Document_Store::load( $hid ), wp_json_encode( array( $post_rebuild['status'], array_keys( $post_rebuild['data'] ) ) ) );
		// The panel's suggestions from the document: a Home whose services are not cards gives the detectors nothing; the document's
		// section that speaks of services names them.
		$svc_doc = $kept->to_array();
		$svc_doc['pages'][0]['sections'][] = array( 'index' => 99, 'role' => 'cards', 'kind' => 'cards', 'name' => '', 'heading' => 'Our services', 'words' => 20, 'repeats' => 3, 'items' => array( 'Water Damage', 'Fire Damage', 'Mold Removal' ), 'has' => array( 'image' => false, 'form' => false, 'button' => false, 'video' => false ), 'sig' => md5( 'x' ) );
		$svc_doc['pages'][0]['sections'][] = array( 'index' => 100, 'role' => 'areas', 'kind' => 'cards', 'name' => '', 'heading' => 'Where we work', 'words' => 20, 'repeats' => 2, 'items' => array( 'Tyler, TX', 'Longview, TX' ), 'has' => array( 'image' => false, 'form' => false, 'button' => false, 'video' => false ), 'sig' => md5( 'y' ) );
		Document_Store::save( $hid, Document::from_array( $svc_doc ) );
		$sugg = \DXAI_UI\Pages\Team_Pages::suggestions( $hid );
		$expect( 'the panel\'s suggestions fall back to the document when the Home\'s cards name no services and no places', array( 'Water Damage', 'Fire Damage', 'Mold Removal' ) === $sugg['services'] && array( 'Tyler, TX', 'Longview, TX' ) === $sugg['locations'] && 'document' === ( $sugg['from']['services'] ?? '' ) && 'document' === ( $sugg['from']['places'] ?? '' ), wp_json_encode( $sugg ) );
		Document_Store::save( $hid, $kept );
		$sugg = \DXAI_UI\Pages\Team_Pages::suggestions( $hid );
		$expect( '…and offer nothing when the document names none either', array() === $sugg['services'] && '' === ( $sugg['from']['services'] ?? 'x' ), wp_json_encode( $sugg['services'] ) );
	}
}
$no_snap = Document_Store::rebuild( $home );
$expect( 'a design that has no snapshot cannot be rebuilt, and says so', is_wp_error( $no_snap ) && 'dxai_ui_no_snapshot' === $no_snap->get_error_code() );
$no_design = Document_Store::rebuild( $plain );
$expect( 'nor can a page that is no design\'s', is_wp_error( $no_design ) && 'dxai_ui_no_design' === $no_design->get_error_code() );

$fin();
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

<?php
/**
 * A design's states as Interactivity API directives, where the site asks for it.
 *
 *   bash bin/wp-php.sh bin/verify-interactivity.php        (or: wp eval-file bin/verify-interactivity.php --user=1)
 *
 * Phase 3б of docs/PLAN-ARCHITECTURE.md. With the `interactivity` setting on, every mark the compiler wrote for the page runtime gets
 * the directive that says the same thing as the page renders (Blocks\Interactivity): a trigger its click (and hover) handlers and its
 * aria, a projection and a panel their class binding (and the panel's style bindings where it animates), a disabled arrow its binding;
 * the scope element becomes the interactive region with the store's init and the document and window listeners; the store module is
 * asked for on a page that has states, before the head prints the import map; the page runtime stands down for the states of such a
 * region. Off, nothing of it is on the page. The behaviour itself — the same outcome of every click, hover and scroll with the setting on
 * and off — is measured in a browser on the test install (the plan's gate), not here.
 *
 * Against the small Claude Design fixture (.verify/dc-minimal.zip), imported under a name of its own and removed when the suite ends,
 * however it ends; the setting is put back as it was. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Blocks\Interactivity;
use DXAI_UI\Compiler\Motion_Runtime;
use DXAI_UI\Compiler\Source_Compiler;
use DXAI_UI\Connectors\Dc_Connector;
use DXAI_UI\Content\Content_Types;
use DXAI_UI\Settings\Options;
use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Structures\Structure_Repository;
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

$run      = substr( md5( microtime( true ) . wp_rand() ), 0, 8 );
$saved    = array();
$max_att  = (int) $GLOBALS['wpdb']->get_var( "SELECT MAX(ID) FROM {$GLOBALS['wpdb']->posts} WHERE post_type = 'attachment'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$settings = get_option( Options::OPTION_KEY );
$force    = null;
$filter   = static function ( $on ) use ( &$force ) {
	return $force === null ? $on : $force;
};
add_filter( 'dxai_ui_interactivity', $filter );
$fin = static function () use ( &$saved, $max_att, $settings, $filter ): void {
	global $wpdb;
	remove_filter( 'dxai_ui_interactivity', $filter );
	if ( is_array( $settings ) ) {
		update_option( Options::OPTION_KEY, $settings, false );
	} else {
		delete_option( Options::OPTION_KEY );
	}
	$home = (int) ( $saved['page_id'] ?? 0 );
	if ( $home > 0 ) {
		$file = Upload_Paths::for_meta( $home, '_dxai_ui_css_url' )['path'] ?? '';
		if ( is_string( $file ) && $file !== '' && is_file( $file ) ) {
			wp_delete_file( $file );
		}
		$js = Upload_Paths::for_meta( $home, '_dxai_ui_js_url' )['path'] ?? '';
		if ( is_string( $js ) && $js !== '' && is_file( $js ) ) {
			wp_delete_file( $js );
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
		$new = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_dxai_ui_asset_kind' WHERE p.post_type = 'attachment' AND p.ID > %d", $max_att ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $new as $id ) {
			wp_delete_attachment( (int) $id, true );
		}
		$saved = array();
	}
};
register_shutdown_function( $fin );

echo "The directives, mark for mark\n";
$force = true;
$html  = '<div class="hero dxai-cls-dark" data-dxai-on="bg-paper/95 backdrop-blur-md" data-dxai-off="bg-transparent">'
	. '<button class="btn dxai-toggle-open" aria-expanded="false">Menu</button>'
	. '<button class="dxai-toggle-open--services" data-dxai-hover="enter" data-dxai-mode="toggle" aria-expanded="false">Services</button>'
	. '<a class="dxai-toggle-open" data-dxai-hover="leave" data-dxai-set="null" href="#x">Close</a>'
	. '<button class="dxai-toggle-slide" data-dxai-step="1" aria-label="Next">›</button>'
	. '<button class="dxai-toggle-tab--a" role="tab" aria-selected="true">Tab</button>'
	. '<nav class="hidden dxai-on-open">panel</nav>'
	. '<div class="dxai-on-openFaq--0" style="grid-template-rows:0fr" data-open-rows="1fr">answer</div>'
	. '<div class="hidden dxai-off-open" data-open-max="300px">other</div>'
	. '<button data-dxai-disabled="slide:0" disabled>‹</button>'
	. '<span data-dxai-text-openFaq="{&quot;0&quot;:&quot;−&quot;}">−</span>'
	. '<p class="plain">words</p><img src="a.jpg" class="dxai-cls-x" data-dxai-on="seen" />'
	. '<button class="dxai-toggle-open" data-wp-on--click="actions.other">mine</button></div>';
$out   = Interactivity::directives( $html );
$tag   = static function ( string $marker ) use ( $out ): string {
	return preg_match( '/<[a-z]+\b[^>]*' . preg_quote( $marker, '/' ) . '[^>]*>/', $out, $m ) === 1 ? $m[0] : '';
};
$expect( 'a projection binds its whole class list (the design\'s classes hold / and :, which an attribute name cannot)', str_contains( $tag( 'dxai-cls-dark' ), 'data-wp-bind--class="state.cls"' ) && ! str_contains( $tag( 'dxai-cls-dark' ), 'data-wp-class--' ), $tag( 'dxai-cls-dark' ) );
$expect( 'a click trigger gets its click and its aria', str_contains( $tag( 'btn dxai-toggle-open' ), 'data-wp-on--click="actions.fire"' ) && str_contains( $tag( 'btn dxai-toggle-open' ), 'data-wp-bind--aria-expanded="state.expanded"' ), $tag( 'btn dxai-toggle-open' ) );
$expect( 'a hover trigger gets mouseenter and focusin too; a leave trigger mouseleave, focusout and blur, and no aria (it sets a literal)', str_contains( $tag( 'dxai-toggle-open--services' ), 'data-wp-on--mouseenter="actions.hoverOn"' ) && str_contains( $tag( 'dxai-toggle-open--services' ), 'data-wp-on--focusin="actions.focusOn"' ) && str_contains( $tag( 'data-dxai-hover="leave"' ), 'data-wp-on--mouseleave="actions.hoverOff"' ) && str_contains( $tag( 'data-dxai-hover="leave"' ), 'data-wp-on--focusout="actions.focusOut"' ) && str_contains( $tag( 'data-dxai-hover="leave"' ), 'data-wp-on--blur="actions.hoverOff"' ) && ! str_contains( $tag( 'data-dxai-hover="leave"' ), 'aria-expanded="state' ), $tag( 'data-dxai-hover="leave"' ) );
$expect( 'a step trigger gets no aria (it moves along the values); a tab binds aria-selected as well', ! str_contains( $tag( 'data-dxai-step="1"' ), 'data-wp-bind--aria' ) && str_contains( $tag( 'data-dxai-step="1"' ), 'actions.fire' ) && str_contains( $tag( 'role="tab"' ), 'data-wp-bind--aria-selected="state.selected"' ), $tag( 'data-dxai-step="1"' ) );
$expect( 'a panel binds its class list; one that animates its rows or its height binds those styles too', str_contains( $tag( 'dxai-on-open"' ), 'data-wp-bind--class="state.cls"' ) && ! str_contains( $tag( 'dxai-on-open"' ), 'data-wp-style' ) && str_contains( $tag( 'data-open-rows' ), 'data-wp-style--grid-template-rows="state.rows"' ) && str_contains( $tag( 'data-open-max' ), 'data-wp-style--max-height="state.maxHeight"' ) && str_contains( $tag( 'data-open-max' ), 'data-wp-style--opacity="state.opacity"' ), $tag( 'data-open-rows' ) );
$expect( 'a control disabled for some values binds disabled', str_contains( $tag( 'data-dxai-disabled' ), 'data-wp-bind--disabled="state.off"' ) );
$expect( 'words that change with a state get no directive (the store writes them: a text binding could not know the first reading)', ! str_contains( $tag( 'data-dxai-text-openFaq' ), 'data-wp-' ) );
$expect( 'an element with no mark, an element with a directive of its own, and a void element are left as they are', str_contains( $out, '<p class="plain">words</p>' ) && str_contains( $out, 'data-wp-on--click="actions.other">mine' ) && ! str_contains( $tag( 'actions.other' ), 'actions.fire' ) && str_contains( $out, 'data-dxai-on="seen" data-wp-bind--class="state.cls" />' ), substr( $out, -200 ) );
$expect( 'the same markup gives the same answer, and markup with no mark comes back untouched', Interactivity::directives( $html ) === $out && Interactivity::directives( '<div class="hero"><p>words</p></div>' ) === '<div class="hero"><p>words</p></div>' );
$expect( 'the region\'s attributes: the store, an empty context the init callback seeds, the document and window listeners', str_contains( Interactivity::root_attrs(), 'data-wp-interactive="dxai-ui"' ) && str_contains( Interactivity::root_attrs(), "data-wp-context='" . Interactivity::CONTEXT . "'" ) && str_contains( Interactivity::root_attrs(), 'data-wp-init="callbacks.init"' ) && str_contains( Interactivity::root_attrs(), 'data-wp-on-document--click="actions.outside"' ) && str_contains( Interactivity::root_attrs(), 'data-wp-on-window--scroll="actions.scroll"' ) && str_contains( Interactivity::root_attrs(), 'data-wp-on-document--keydown="actions.escape"' ) && null !== json_decode( Interactivity::CONTEXT, true ), Interactivity::root_attrs() );
$expect( 'the scope element opens with them', str_contains( Page_Scope::open( 1, 1 ), 'dxai-ui dxai-ui--1' ) && str_contains( Page_Scope::open( 1, 1 ), 'data-wp-interactive="dxai-ui"' ) );
$expect( 'markup with a state is told from markup without one', Interactivity::has_states( '<div class="x dxai-toggle-open">' ) && Interactivity::has_states( '<!-- wp:dxai-ui/box {"className":"dxai-cls-dark"} -->' ) && Interactivity::has_states( '<span data-dxai-text-openFaq="{}">' ) && ! Interactivity::has_states( '<div class="dxai-ui dxai-online"><p>dxai-on</p></div>' ) );
$force = false;
$expect( 'off: no directives on the content, no region on the scope element', Interactivity::enrich( $html ) === $html && '' === Interactivity::root_attrs() && ! str_contains( Page_Scope::open( 1, 1 ), 'data-wp-' ) );
$force = null;
$expect( 'the setting: on unless turned off (phase 3в); saved as a boolean, whatever the form sends', true === Options::defaults()['interactivity'] && true === Options::save( array( 'interactivity' => 'true' ) )['interactivity'] && false === Options::save( array( 'interactivity' => 'false' ) )['interactivity'] && true === Options::save( array( 'interactivity' => '1' ) )['interactivity'] && false === Options::save( array( 'interactivity' => '0' ) )['interactivity'] );
$expect( 'the runtime stands down for the states of a region the API drives, and no more', str_contains( Motion_Runtime::javascript(), 'var wpi = !!(root.hasAttribute && root.hasAttribute("data-wp-interactive"));' ) && str_contains( Motion_Runtime::javascript(), 'if (!wpi && (projections.length || panels.length))' ) && substr_count( Motion_Runtime::javascript(), 'if (wpi) { return; }' ) === 2 && str_contains( Motion_Runtime::javascript(), 'if (wpi || !btn || !root.contains(btn))' ) );
$expect( 'the store is a script module on @wordpress/interactivity', ( static function (): bool {
	ob_start();
	wp_script_modules()->print_import_map();
	$map = (string) ob_get_clean();
	// Registered, not enqueued: the import map is written for what is enqueued; the registration is what enqueue() needs.
	return function_exists( 'wp_register_script_module' ) && is_file( DXAI_UI_DIR . 'assets/js/interactivity.js' ) && str_contains( (string) file_get_contents( DXAI_UI_DIR . 'assets/js/interactivity.js' ), "from '@wordpress/interactivity'" ) && is_string( $map ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
} )() );

echo "\nOn a page of the fixture\n";
$zip = dirname( __DIR__ ) . '/.verify/dc-minimal.zip';
if ( ! is_file( $zip ) ) {
	// The pack makes the fixture first (bin/pack-fixtures.php); without it this half did not run, which is not a pass.
	$expect( 'the fixture .verify/dc-minimal.zip is here (wp eval-file bin/pack-fixtures.php makes it)', false );
} else {
	$src  = ( new Dc_Connector() )->import_zip( array( 'tmp_name' => $zip, 'name' => 'dc-minimal.zip' ) );
	$nat  = ( new Source_Compiler() )->compile( $src );
	$nat['block_title'] = 'Interactivity fixture ' . $run;
	$nat['slug']        = '/interactivity-fixture-' . $run . '/';
	$nat['source_name'] = 'interactivity-fixture-' . $run . '.zip';
	$nat['chrome']      = 'keep';
	$saved              = ( new Structure_Repository() )->save( $nat );
	$home               = (int) ( $saved['page_id'] ?? 0 );
	$fetch              = static function ( int $id ): string {
		$res = wp_remote_get( (string) get_permalink( $id ), array( 'timeout' => 60, 'sslverify' => false ) );

		return is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_body( $res );
	};
	$content = (string) get_post_field( 'post_content', $home );
	$expect( 'the fixture\'s page has states the compiler marked', $home > 0 && Interactivity::has_states( $content ) );
	// The setting as the site reads it in another request.
	$stored = is_array( $settings ) ? $settings : array();
	update_option( Options::OPTION_KEY, array_merge( $stored, array( 'interactivity' => true ) ), false );
	$on = $fetch( $home );
	update_option( Options::OPTION_KEY, array_merge( $stored, array( 'interactivity' => false ) ), false );
	$off = $fetch( $home );
	$expect( 'on: the page\'s scope element is the region, the marks carry their directives', preg_match( '/<div class="[^"]*dxai-ui--' . $home . '[^"]*"[^>]*data-wp-interactive="dxai-ui"/', $on ) === 1 && substr_count( $on, 'data-wp-on--click="actions.fire"' ) >= 1 && substr_count( $on, 'data-wp-bind--class="state.cls"' ) >= 1, (string) substr_count( $on, 'data-wp-' ) );
	$expect( '…the store module is on the page, with the import map before it', str_contains( $on, 'assets/js/interactivity.js' ) && str_contains( $on, 'type="importmap"' ) && str_contains( $on, '@wordpress/interactivity' ) && strpos( $on, 'type="importmap"' ) < strpos( $on, 'assets/js/interactivity.js' ) );
	$expect( 'off: none of it', ! str_contains( $off, 'data-wp-' ) && ! str_contains( $off, 'assets/js/interactivity.js' ) );
	// WordPress writes the module tag as `<script id="…" src="…" type="module">` and the import map as `<script id="wp-importmap" type="importmap">`.
	$same  = (string) preg_replace( array( '/ data-wp-[a-z-]+=(?:"[^"]*"|\'[^\']*\')/', '/<script[^>]*type="module"[^>]*><\/script>\s*/', '/<link[^>]*modulepreload[^>]*>\s*/', '/<script[^>]*type="importmap"[^>]*>.*?<\/script>\s*/s', '/[?&]ver=[0-9a-z.\-]+/i' ), '', $on );
	$plain = (string) preg_replace( array( '/<script[^>]*type="module"[^>]*><\/script>\s*/', '/<link[^>]*modulepreload[^>]*>\s*/', '/<script[^>]*type="importmap"[^>]*>.*?<\/script>\s*/s', '/[?&]ver=[0-9a-z.\-]+/i' ), '', $off );
	$expect( '…and with the directives and the module taken out of the one, the two pages are the same bytes', $same === $plain, strlen( $same ) . ' vs ' . strlen( $plain ) );
}

$fin();
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

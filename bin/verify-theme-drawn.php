<?php
/**
 * A design's page moved to the theme's own template shows the theme's header and footer, and the design's sections as on the canvas.
 *
 *   bash bin/wp-php.sh bin/verify-theme-drawn.php        (or: wp eval-file bin/verify-theme-drawn.php --user=1)
 *
 * The team keeps every page of a site on the theme's Default template: the theme draws its header from Appearance > Menus and its footer
 * from Appearance > Widgets. Gemini Restoration's pages, moved there for that, lost the design's look (the theme's 20px block gap under
 * every block, 22px text at 16px, the page 465px taller), and 0.4.0-beta.45 made the Default template draw the design's own header
 * instead, which is not what the team moved them there for. Checked here: the Default template is the theme's (beta.45's drawing only on
 * a site that asks for it); a design's own page the theme draws is fenced off the theme's CSS when it was made for the canvas (a static
 * design, or a page the import saved), and keeps the theme's stylesheet for the header and footer; a page the team built under the theme's
 * CSS keeps it; a copy of a design page follows its source; and a re-import leaves a page on the template someone moved it to. On such a
 * page the theme's DelayScripts module holds back every script but the plugin's own and what they need, as on any page of the site (the
 * tag manager and analytics ran during the load there up to 0.4.0-beta.46: Master Restoration's Home scored 65 on PageSpeed).
 *
 * Makes pages (and imports the small Claude Design fixture under a name of its own) and removes them. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Compiler\Source_Compiler;
use DXAI_UI\Connectors\Dc_Connector;
use DXAI_UI\Content\Content_Types;
use DXAI_UI\Structures\Design_Attach;
use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Structures\Structure_Repository;
use DXAI_UI\Support\Upload_Paths;
use DXAI_UI\Theme\Blank_Template;
use DXAI_UI\Theme\Theme_Fence;

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
$made    = array();
$saved   = array();
$max_att = (int) $GLOBALS['wpdb']->get_var( "SELECT MAX(ID) FROM {$GLOBALS['wpdb']->posts} WHERE post_type = 'attachment'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$fin     = static function () use ( &$made, &$saved, $max_att ): void {
	global $wpdb;
	foreach ( $made as $id ) {
		wp_delete_post( (int) $id, true );
	}
	$made = array();
	$home = (int) ( $saved['page_id'] ?? 0 );
	if ( $home > 0 ) {
		foreach ( array( '_dxai_ui_css_url', '_dxai_ui_js_url' ) as $key ) {
			$file = Upload_Paths::for_meta( $home, $key )['path'] ?? '';
			if ( is_string( $file ) && $file !== '' && is_file( $file ) ) {
				wp_delete_file( $file );
			}
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
	wp_reset_query();
};
register_shutdown_function( $fin );

/** A design's page, as the import leaves it: generated, its own scope and sheet, on $template. */
$page = static function ( string $title, string $template, array $meta = array() ) use ( &$made ): int {
	$id     = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => '<!-- wp:paragraph --><p>Words</p><!-- /wp:paragraph -->' ) );
	$made[] = $id;
	update_post_meta( $id, '_wp_page_template', $template );
	update_post_meta( $id, '_dxai_ui_generated_page', '1' );
	update_post_meta( $id, Page_Scope::META, $id );
	update_post_meta( $id, '_dxai_ui_css_url', 'dxai-ui/theme-drawn-fixture.css' );
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	Design_Attach::forget( $id );

	return $id;
};
$visit = static function ( int $id ): void {
	$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $id ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$GLOBALS['post']         = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
};

echo "The Default template is the theme's\n";
$expect( 'a design\'s pages are given the DX template at import', Blank_Template::default_slug() === Blank_Template::TEMPLATE );
add_filter( 'dxai_ui_default_template', static fn() => Blank_Template::DEFAULT );
$expect( '…or the Default template, where a site asks for it', Blank_Template::default_slug() === Blank_Template::DEFAULT );
remove_all_filters( 'dxai_ui_default_template' );
$static = $page( 'Theme drawn fixture static page', Blank_Template::DEFAULT, array( '_dxai_ui_static_html' => '1' ) );
$visit( $static );
$expect( 'a design\'s page on the Default template is not a canvas page: the theme draws it', ! Blank_Template::is_canvas( $static ) && ( new Blank_Template() )->include_template( '/theme/page.php' ) === '/theme/page.php' );
add_filter( 'dxai_ui_default_template_draws_design', '__return_true' );
$expect( '…unless the site asks for the design\'s own header and footer there (beta.45\'s drawing)', Blank_Template::is_canvas( $static ) );
remove_all_filters( 'dxai_ui_default_template_draws_design' );

echo "\nIts sections are kept off the theme's CSS when it was made for the canvas\n";
$theme_tpl = $page( 'Theme drawn fixture page on the theme\'s named template', 'page.php', array( '_dxai_ui_static_html' => '1' ) );
$imported  = $page( 'Theme drawn fixture imported page', Blank_Template::DEFAULT, array( '_dxai_ui_fence_theme' => '1' ) );
$team      = $page( 'Theme drawn fixture page the team built under the theme', Blank_Template::DEFAULT );
$canvas    = $page( 'Theme drawn fixture canvas page', Blank_Template::TEMPLATE, array( '_dxai_ui_static_html' => '1' ) );
$expect( 'a design\'s own page on the Default template, or on a template of the theme\'s by name, is drawn by the theme', Theme_Fence::draws_own_design( $static ) && Theme_Fence::draws_own_design( $theme_tpl ) );
$expect( '…a page on the canvas is not', ! Theme_Fence::draws_own_design( $canvas ) );
$expect( 'a static design is fenced there (it never ran under a theme\'s stylesheet)', Theme_Fence::fences( $static ) && Theme_Fence::fences( $theme_tpl ) );
$expect( 'a page the import saved says so itself', Theme_Fence::fences( $imported ) );
$expect( 'a page the team built under the theme\'s CSS keeps it (not fenced)', ! Theme_Fence::fences( $team ) );
update_post_meta( $static, '_dxai_ui_fence_theme', '0' );
$expect( 'the page\'s own custom field decides', ! Theme_Fence::fences( $static ) );
update_post_meta( $static, '_dxai_ui_fence_theme', '' );
$visit( $static );
$expect( 'the request fences the theme\'s styles there', Theme_Fence::applies() );
$expect( '…and a DX theme\'s modules stand down as on the canvas: the design\'s scripts are not held back, its pictures keep their own loading', false === apply_filters( 'dx_delay_scripts_should_delay', true ) && true === apply_filters( 'dx_lazy_load_skip_image', false, '<img src="x.jpg">' ) );
// DelayScripts asks for each script with its src, its inline code and its whole tag (dx-modules/src/Modules/DelayScripts/Module.php).
$delay  = static fn( string $src, string $tag, string $inner = '' ): bool => true === apply_filters( 'dx_delay_scripts_should_delay', true, $src, $inner, $tag );
$own_js = Upload_Paths::url( Upload_Paths::DIR . '/pattern-theme-drawn-fixture.js' );
$gtm    = "(function(w,d,s,l,i){w[l]=w[l]||[];var j=d.createElement(s);j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i;d.head.appendChild(j);})(window,document,'script','dataLayer','GTM-FIXTURE');";
$expect( '…the design\'s page script, its file in the plugin\'s uploads folder, runs at once', ! $delay( $own_js, '<script defer src="' . esc_url( $own_js ) . '"></script>' ) );
$expect( '…so does a file in the plugin\'s folder', ! $delay( DXAI_UI_URL . 'assets/js/rail.js', '<script src="' . esc_url( DXAI_UI_URL . 'assets/js/rail.js' ) . '"></script>' ) );
$sibling = (string) preg_replace( '#/[^/]+/?$#', '/' . basename( untrailingslashit( DXAI_UI_URL ) ) . '-addon/', untrailingslashit( DXAI_UI_URL ) ) . 'view.js';
$expect( '…but not a file of another plugin whose folder only starts with the same name', $delay( $sibling, '<script src="' . esc_url( $sibling ) . '"></script>' ), $sibling );
$expect( '…nor a file elsewhere that only has the plugin\'s name in its address', $delay( 'https://cdn.example.test/assets/dxai-ui/widget.js', '<script src="https://cdn.example.test/assets/dxai-ui/widget.js"></script>' ) );
$expect( '…and the plugin\'s inline scripts run at once (its handles\' data, its own printed scripts)', ! $delay( '', '<script id="dxai-ui-backgrounds">', '(function(){})();' ) && ! $delay( '', '<script id="dxai-ui-page-1-js-before">', 'window.dxaiLucideUrl="x";' ) );
wp_register_script( 'theme-drawn-fixture-need', 'https://example.test/need.js', array(), '1', true );
wp_register_script( 'dxai-ui-theme-drawn-fixture', DXAI_UI_URL . 'assets/js/rail.js', array( 'theme-drawn-fixture-need' ), '1', true );
wp_enqueue_script( 'dxai-ui-theme-drawn-fixture' );
$expect( '…and so does a script one of the plugin\'s scripts depends on, with its data', ! $delay( 'https://example.test/need.js', '<script id="theme-drawn-fixture-need-js" src="https://example.test/need.js"></script>' ) && ! $delay( '', '<script id="theme-drawn-fixture-need-js-extra">', 'var need = {};' ) );
wp_dequeue_script( 'dxai-ui-theme-drawn-fixture' );
wp_deregister_script( 'dxai-ui-theme-drawn-fixture' );
wp_deregister_script( 'theme-drawn-fixture-need' );
$expect( '…once nothing of the plugin\'s needs it, that script waits like the others', $delay( 'https://example.test/need.js', '<script id="theme-drawn-fixture-need-js" src="https://example.test/need.js"></script>' ) );
$expect( 'the tag manager waits for the first interaction, as on every page of the site', $delay( '', '<script>', $gtm ) && $delay( 'https://www.googletagmanager.com/gtm.js?id=GTM-FIXTURE', '<script async src="https://www.googletagmanager.com/gtm.js?id=GTM-FIXTURE"></script>' ) );
$expect( '…and so do another plugin\'s scripts and jQuery', $delay( 'https://example.test/wp-content/plugins/gravityforms/js/gravityforms.min.js', '<script id="gform_gravityforms-js" defer src="https://example.test/wp-content/plugins/gravityforms/js/gravityforms.min.js"></script>' ) && $delay( includes_url( 'js/jquery/jquery.min.js' ), '<script id="jquery-core-js" src="' . esc_url( includes_url( 'js/jquery/jquery.min.js' ) ) . '"></script>' ) );
$expect( 'a module that asks without the script (an older theme) holds nothing back there, as before', false === apply_filters( 'dx_delay_scripts_should_delay', true ) );
$visit( $team );
$expect( '…while on a page the team built under the theme they work as on any of its pages', true === apply_filters( 'dx_delay_scripts_should_delay', true ) && $delay( $own_js, '' ) && $delay( '', '<script>', $gtm ) );
$visit( $canvas );
$expect( '…and not on a canvas page, which goes without them', ! Theme_Fence::applies() );

echo "\nThe theme's stylesheet stays for its header and footer\n";
$theme_src = trailingslashit( (string) get_template_directory_uri() ) . 'theme-drawn-fixture.css';
wp_register_style( 'theme-drawn-fixture', $theme_src, array(), '1' );
$assets = new \DXAI_UI\Blocks\Assets();
$check  = static function ( int $id ) use ( $assets, $visit ): bool {
	$visit( $id );
	wp_enqueue_style( 'theme-drawn-fixture' );
	$assets->drop_theme_styles();
	$kept = wp_style_is( 'theme-drawn-fixture', 'enqueued' );
	wp_dequeue_style( 'theme-drawn-fixture' );

	return $kept;
};
$expect( 'on a design\'s page the theme draws, fenced, the theme\'s sheets are kept', $check( $static ) );
$expect( 'on a canvas page they are taken off, as before', ! $check( $canvas ) );
$expect( 'on a page the team built under the theme, unfenced, they go as before', ! $check( $team ) );
wp_deregister_style( 'theme-drawn-fixture' );

echo "\nA copy follows its source page\n";
$copy = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Theme drawn fixture copy', 'post_content' => '<!-- wp:dxai-ui/box {"className":"copied"} --><div class="copied">Copy</div><!-- /wp:dxai-ui/box -->' ) );
$made[] = $copy;
update_post_meta( $copy, Design_Attach::META, $static );
Design_Attach::forget( $copy );
$expect( 'a copy of a fenced design page the theme draws is fenced', Theme_Fence::fences( $copy ) );
update_post_meta( $copy, Design_Attach::META, $team );
Design_Attach::forget( $copy );
$expect( '…and a copy of a page the team built under the theme is not', ! Theme_Fence::fences( $copy ) );

echo "\nThe import\n";
$zip = dirname( __DIR__ ) . '/.verify/dc-minimal.zip';
if ( ! is_file( $zip ) ) {
	$expect( 'the fixture .verify/dc-minimal.zip is here (wp eval-file bin/pack-fixtures.php makes it)', false );
} else {
	$import = static function () use ( $zip, $run ): array {
		$src                = ( new Dc_Connector() )->import_zip( array( 'tmp_name' => $zip, 'name' => 'dc-minimal.zip' ) );
		$nat                = ( new Source_Compiler() )->compile( $src );
		$nat['block_title'] = 'Theme drawn fixture ' . $run;
		$nat['slug']        = '/theme-drawn-fixture-' . $run . '/';
		$nat['source_name'] = 'theme-drawn-fixture-' . $run . '.zip';
		$nat['chrome']      = 'keep';

		return (array) ( new Structure_Repository() )->save( $nat );
	};
	$saved = $import();
	$home  = (int) ( $saved['page_id'] ?? 0 );
	$expect( 'a page the import saves is on the DX template and says it was made for the canvas', $home > 0 && get_page_template_slug( $home ) === Blank_Template::TEMPLATE && get_post_meta( $home, '_dxai_ui_fence_theme', true ) === '1', (string) get_page_template_slug( $home ) );
	update_post_meta( $home, '_wp_page_template', Blank_Template::DEFAULT );
	$again = $import();
	$expect( 're-imported, a page someone moved to the theme\'s Default template stays on it', (int) ( $again['page_id'] ?? 0 ) === $home && get_post_meta( $home, '_wp_page_template', true ) === Blank_Template::DEFAULT, (string) get_post_meta( $home, '_wp_page_template', true ) );
	update_post_meta( $home, '_wp_page_template', Blank_Template::SLUG );
	$import();
	$expect( '…and one on a canvas template is given the default', get_post_meta( $home, '_wp_page_template', true ) === Blank_Template::TEMPLATE );
}

$fin();
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

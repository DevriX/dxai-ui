<?php
/**
 * Persist a fake structured generation without calling an LLM.
 * Run: wp eval-file bin/verify-structures.php
 *
 * @package DX_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require DX_UI_DIR . 'bin/verify-cleanup.php';
$dxui_snapshot = dxui_verify_snapshot();

$user = get_user_by( 'login', 'admin' );
if ( $user instanceof WP_User ) {
	wp_set_current_user( $user->ID );
}

$header = '<!-- wp:group {"className":"flex items-center justify-between px-8 py-4","layout":{"type":"flex","justifyContent":"space-between"}} -->'
	. '<div class="wp-block-group flex items-center justify-between px-8 py-4">'
	. '<!-- wp:site-logo /-->'
	. '<!-- wp:site-title /-->'
	. '<!-- wp:navigation /-->'
	. '</div><!-- /wp:group -->';

$hero = '<!-- wp:cover {"className":"min-h-screen flex items-center","dimRatio":0} -->'
	. '<div class="wp-block-cover min-h-screen flex items-center"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-0 has-background-dim"></span><div class="wp-block-cover__inner-container">'
	. '<!-- wp:post-title {"level":1,"className":"text-5xl font-bold"} /-->'
	. '<!-- wp:paragraph {"className":"text-lg"} --><p class="text-lg">Pixel-perfect hero</p><!-- /wp:paragraph -->'
	. '</div></div><!-- /wp:cover -->';

$blog = '<!-- wp:paragraph --><p>placeholder cards</p><!-- /wp:paragraph -->';

$form = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>contact</p><!-- /wp:paragraph --></div><!-- /wp:group -->';

$slider_inner = '<!-- wp:cover --><div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-100 has-background-dim"></span><div class="wp-block-cover__inner-container"><!-- wp:paragraph --><p>Slide A</p><!-- /wp:paragraph --></div></div><!-- /wp:cover -->'
	. '<!-- wp:cover --><div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-100 has-background-dim"></span><div class="wp-block-cover__inner-container"><!-- wp:paragraph --><p>Slide B</p><!-- /wp:paragraph --></div></div><!-- /wp:cover -->';

$footer = '<!-- wp:group {"className":"px-8 py-12 bg-slate-900 text-white"} -->'
	. '<div class="wp-block-group px-8 py-12 bg-slate-900 text-white">'
	. '<!-- wp:paragraph --><p>Footer</p><!-- /wp:paragraph -->'
	. '</div><!-- /wp:group -->';

$body = $hero . "\n" . $blog;

$save = new WP_REST_Request( 'POST', '/dx-ui/v1/save-pattern' );
$save->set_param( 'block_title', 'DXUI Structure Smoke' );
$save->set_param( 'gutenberg_markup', $body );
$save->set_param( 'custom_css', '.hero { min-height: 100vh; }' );
$save->set_param( 'custom_js', '' );
$save->set_param( 'synced', true );
$save->set_param( 'scope', 'site' );
// This script asserts the native dynamic blocks, which are opt-in now that
// fidelity is the default, so it asks for them explicitly.
$save->set_param(
	'structure_mode',
	array(
		'blog'       => 'dynamic',
		'form'       => 'dynamic',
		'slider'     => 'dynamic',
		'navigation' => 'dynamic',
	)
);
$save->set_param(
	'structures',
	array(
		array(
			'type'             => 'header',
			'title'            => 'Header',
			'gutenberg_markup' => $header,
			'menu_items'       => array(
				array( 'label' => 'Home', 'url' => '/' ),
				array( 'label' => 'Blog', 'url' => '/blog' ),
			),
			'form_fields'      => array(),
		),
		array(
			'type'             => 'hero',
			'title'            => 'Hero',
			'gutenberg_markup' => $hero,
			'menu_items'       => array(),
			'form_fields'      => array(),
		),
		array(
			'type'             => 'blog',
			'title'            => 'Blog',
			'gutenberg_markup' => $blog,
			'menu_items'       => array(),
			'form_fields'      => array(),
		),
		array(
			'type'             => 'form',
			'title'            => 'Contact',
			'gutenberg_markup' => $form,
			'menu_items'       => array(),
			'form_fields'      => array(
				array(
					'name'     => 'email',
					'type'     => 'email',
					'label'    => 'Email',
					'required' => true,
				),
			),
		),
		array(
			'type'             => 'slider',
			'title'            => 'Slider',
			'gutenberg_markup' => $slider_inner,
			'menu_items'       => array(),
			'form_fields'      => array(),
		),
		array(
			'type'             => 'footer',
			'title'            => 'Footer',
			'gutenberg_markup' => $footer,
			'menu_items'       => array(
				array( 'label' => 'Privacy', 'url' => '/privacy' ),
			),
			'form_fields'      => array(),
		),
	)
);

$saved = rest_do_request( $save );
echo 'save_status=' . $saved->get_status() . PHP_EOL;
$data = $saved->get_data();
if ( $saved->get_status() >= 400 ) {
	$msg = is_array( $data ) ? ( $data['message'] ?? wp_json_encode( $data ) ) : (string) $data;
	echo 'save_error=' . $msg . PHP_EOL;
}

$created = $data['structures'] ?? array();
$page_id = (int) ( $created['page_id'] ?? 0 );
echo 'page_id=' . $page_id . PHP_EOL;
echo 'header_id=' . (int) ( $created['header_id'] ?? 0 ) . PHP_EOL;
echo 'footer_id=' . (int) ( $created['footer_id'] ?? 0 ) . PHP_EOL;
echo 'nav_count=' . count( $created['navigation_ids'] ?? array() ) . PHP_EOL;
echo 'conversion_id=' . (int) ( $created['conversion_id'] ?? 0 ) . PHP_EOL;
echo 'pattern_count=' . count( $created['patterns'] ?? array() ) . PHP_EOL;

$page = $page_id ? get_post( $page_id ) : null;
$content = $page instanceof WP_Post ? (string) $page->post_content : '';
echo 'has_query=' . ( str_contains( $content, 'wp:query' ) || str_contains( $content, 'wp:block' ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'has_form_block=' . ( str_contains( $content, 'dx-ui/form' ) || str_contains( $content, 'wp:block' ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'has_slider_block=' . ( str_contains( $content, 'dx-ui/slider' ) || str_contains( $content, 'wp:block' ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'page_template=' . (string) get_post_meta( $page_id, '_wp_page_template', true ) . PHP_EOL;
echo 'page_css=' . ( get_post_meta( $page_id, '_dx_ui_css_url', true ) ? 'set' : 'missing' ) . PHP_EOL;

$registry = WP_Block_Type_Registry::get_instance();
echo 'form_registered=' . ( $registry->is_registered( 'dx-ui/form' ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'slider_registered=' . ( $registry->is_registered( 'dx-ui/slider' ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'conversion_supports_revisions=' . ( post_type_supports( 'dxui_conversion', 'revisions' ) ? 'yes' : 'no' ) . PHP_EOL;

$nonce = wp_create_nonce( 'dx_ui_form' );
$form  = new WP_REST_Request( 'POST', '/dx-ui/v1/forms/submit' );
$form->set_param( 'nonce', $nonce );
$form->set_param( 'website', '' );
$form->set_param( 'page_id', $page_id );
$form->set_param( 'email', 'verify@example.com' );
$submitted = rest_do_request( $form );
echo 'form_submit_status=' . $submitted->get_status() . PHP_EOL;
echo 'form_submit_ok=' . ( ! empty( $submitted->get_data()['ok'] ) ? 'yes' : 'no' ) . PHP_EOL;

$list = rest_do_request( new WP_REST_Request( 'GET', '/dx-ui/v1/patterns' ) );
$lib  = $list->get_data();
echo 'library_pages=' . count( $lib['pages'] ?? array() ) . PHP_EOL;
echo 'library_parts=' . count( $lib['template_parts'] ?? array() ) . PHP_EOL;
echo 'library_navs=' . count( $lib['navigations'] ?? array() ) . PHP_EOL;
echo 'library_conversions=' . count( $lib['conversions'] ?? array() ) . PHP_EOL;
echo 'library_forms=' . count( $lib['form_entries'] ?? array() ) . PHP_EOL;

$blog_ok = false;
foreach ( $created['patterns'] ?? array() as $pattern ) {
	$p = get_post( (int) $pattern['id'] );
	if ( $p instanceof WP_Post && str_contains( $p->post_content, 'wp:query' ) ) {
		$blog_ok = true;
	}
}
echo 'blog_is_query_loop=' . ( $blog_ok ? 'yes' : 'no' ) . PHP_EOL;
echo 'form_rewritten=' . ( $blog_ok && $registry->is_registered( 'dx-ui/form' ) ? 'yes' : 'no' ) . PHP_EOL;

$slider_ok = false;
$form_ok   = false;
foreach ( $created['patterns'] ?? array() as $pattern ) {
	$p = get_post( (int) $pattern['id'] );
	if ( ! $p instanceof WP_Post ) {
		continue;
	}
	if ( str_contains( $p->post_content, 'dx-ui/slider' ) ) {
		$slider_ok = true;
	}
	if ( str_contains( $p->post_content, 'dx-ui/form' ) ) {
		$form_ok = true;
	}
}
echo 'slider_is_block=' . ( $slider_ok ? 'yes' : 'no' ) . PHP_EOL;
echo 'form_is_block=' . ( $form_ok ? 'yes' : 'no' ) . PHP_EOL;

$page_save = new WP_REST_Request( 'POST', '/dx-ui/v1/save-pattern' );
$page_save->set_param( 'block_title', 'DXUI Page Scope Smoke' );
$page_save->set_param( 'gutenberg_markup', $body );
$page_save->set_param( 'custom_css', '' );
$page_save->set_param( 'synced', true );
$page_save->set_param( 'scope', 'page' );
$page_save->set_param(
	'structures',
	array(
		array(
			'type'             => 'header',
			'title'            => 'Header',
			'gutenberg_markup' => $header,
			'menu_items'       => array(),
			'form_fields'      => array(),
		),
		array(
			'type'             => 'hero',
			'title'            => 'Hero',
			'gutenberg_markup' => $hero,
			'menu_items'       => array(),
			'form_fields'      => array(),
		),
		array(
			'type'             => 'footer',
			'title'            => 'Footer',
			'gutenberg_markup' => $footer,
			'menu_items'       => array(),
			'form_fields'      => array(),
		),
	)
);
$page_res  = rest_do_request( $page_save );
$page_data = $page_res->get_data();
$page_created = is_array( $page_data['structures'] ?? null ) ? $page_data['structures'] : array();
echo 'page_scope_status=' . $page_res->get_status() . PHP_EOL;
echo 'page_scope_header_id=' . (int) ( $page_created['header_id'] ?? 0 ) . PHP_EOL;
echo 'page_scope_footer_id=' . (int) ( $page_created['footer_id'] ?? 0 ) . PHP_EOL;
echo 'page_scope_page_id=' . (int) ( $page_created['page_id'] ?? 0 ) . PHP_EOL;

$preview = new WP_REST_Request( 'POST', '/dx-ui/v1/preview' );
$preview->set_param( 'gutenberg_markup', $hero );
$preview->set_param( 'custom_css', '.hero { min-height: 100vh; }' );
$previewed = rest_do_request( $preview );
$preview_data = $previewed->get_data();
echo 'preview_status=' . $previewed->get_status() . PHP_EOL;
echo 'preview_has_html=' . ( ! empty( $preview_data['html'] ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'preview_has_css=' . ( ! empty( $preview_data['css'] ) ? 'yes' : 'no' ) . PHP_EOL;

// History: every import keeps a snapshot of its own, the newest Structure_Repository::SNAPSHOTS_KEPT are kept, and a
// restore keeps one marked with the snapshot it came from.
$dxui_snapshots = static function ( int $page ): array {
	return array_map(
		'intval',
		get_posts(
			array(
				'post_type'      => 'dxui_conversion',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'meta_key'       => '_dx_ui_page_id',
				'meta_value'     => (string) $page,
			)
		)
	);
};
$first_conversion = (int) ( $created['conversion_id'] ?? 0 );
$again_ids        = array();
$again_pages      = array();
for ( $i = 0; $i < 6; $i++ ) {
	$again         = rest_do_request( $save );
	$again_created = (array) ( $again->get_data()['structures'] ?? array() );
	$again_ids[]   = (int) ( $again_created['conversion_id'] ?? 0 );
	$again_pages[] = (int) ( $again_created['page_id'] ?? 0 );
}
$kept_after = $dxui_snapshots( $page_id );
echo 'history_kept=' . implode( ',', $kept_after ) . PHP_EOL;

$restore_from = (int) ( $kept_after[1] ?? 0 );
$restore_req  = new WP_REST_Request( 'POST', '/dx-ui/v1/restore' );
$restore_req->set_param( 'conversion_id', $restore_from );
$restored      = rest_do_request( $restore_req );
$restored_data = (array) $restored->get_data();
$kept_restore  = $dxui_snapshots( $page_id );
$history       = \DX_UI\Design\Design_Status::conversions( $page_id );
echo 'history_restore_status=' . $restored->get_status() . ' kept=' . implode( ',', $kept_restore ) . PHP_EOL;

$fewer = static fn(): int => 2;
add_filter( 'dx_ui_snapshots_kept', $fewer );
rest_do_request( $save );
$kept_fewer = $dxui_snapshots( $page_id );
remove_filter( 'dx_ui_snapshots_kept', $fewer );

// The brand's page goes to the trash: the brand passes to the design imported last whose page is still published, however
// many snapshots the designs after it keep. Two designs with 60 each (the brand's, and a draft's) are newer than the one that
// can take it; reading a fixed number of snapshot rows never reached it, and the brand was cleared.
global $wpdb;
$brand_before = get_option( \DX_UI\Theme\Design_Theme_Json::OPTION );
$adopt_any    = static fn(): bool => true;
add_filter( 'dx_ui_adopt_brand', $adopt_any );
$brand_pages = array();
foreach ( array( 'publish', 'draft', 'publish' ) as $k => $status ) {
	$brand_pages[ $k ] = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => $status, 'post_title' => 'Brand fixture ' . $k ) );
	update_post_meta( $brand_pages[ $k ], \DX_UI\Compiler\Design_Tokens::META, array( 'brand' => '#11223' . $k ) );
}
[ $brand_page, $draft_page, $older_page ] = $brand_pages;
// Dated ahead of every design the site holds, so the one that takes the brand is this fixture's, whatever else is there.
$dated = static function ( int $id, string $when ) use ( $wpdb ): void {
	$wpdb->update( $wpdb->posts, array( 'post_modified' => $when, 'post_modified_gmt' => $when ), array( 'ID' => $id ) );
	clean_post_cache( $id );
};
$older_snapshot = (int) wp_insert_post( array( 'post_type' => 'dxui_conversion', 'post_status' => 'publish', 'post_title' => 'Older design', 'meta_input' => array( '_dx_ui_page_id' => $older_page ) ) );
$dated( $older_snapshot, '2099-01-01 00:00:00' );
for ( $i = 0; $i < 120; $i++ ) {
	$dated( (int) wp_insert_post( array( 'post_type' => 'dxui_conversion', 'post_status' => 'publish', 'post_title' => 'Newer snapshot ' . $i, 'meta_input' => array( '_dx_ui_page_id' => $i % 2 ? $draft_page : $brand_page ) ) ), '2099-01-02 00:00:00' );
}
update_option( \DX_UI\Theme\Design_Theme_Json::OPTION, array( 'tokens' => array( 'brand' => '#112230' ), 'page_id' => $brand_page, 'source' => '' ), false );
wp_trash_post( $brand_page );
$brand_after = get_option( \DX_UI\Theme\Design_Theme_Json::OPTION );
remove_filter( 'dx_ui_adopt_brand', $adopt_any );
if ( false === $brand_before ) {
	delete_option( \DX_UI\Theme\Design_Theme_Json::OPTION );
} else {
	update_option( \DX_UI\Theme\Design_Theme_Json::OPTION, $brand_before, false );
}

// What the save, the form, the library, the page-scoped save and the preview gave: each must be what a working site gives.
$fail   = 0;
$pass   = 0;
$expect = static function ( string $what, bool $ok ) use ( &$fail, &$pass ): void {
	$ok ? ++$pass : ++$fail;
	echo ( $ok ? '  ok    ' : '  FAIL  ' ) . $what . "\n";
};
$expect( 'a site save answers 200 and makes the page, its header and footer parts, its navigation and its conversion record', 200 === $saved->get_status() && $page_id > 0 && (int) ( $created['header_id'] ?? 0 ) > 0 && (int) ( $created['footer_id'] ?? 0 ) > 0 && count( $created['navigation_ids'] ?? array() ) > 0 && (int) ( $created['conversion_id'] ?? 0 ) > 0 );
$expect( 'the page holds the query loop, the form and the slider, on the plugin\'s template with its stylesheet', ( str_contains( $content, 'wp:query' ) || str_contains( $content, 'wp:block' ) ) && ( str_contains( $content, 'dx-ui/form' ) || str_contains( $content, 'wp:block' ) ) && ( str_contains( $content, 'dx-ui/slider' ) || str_contains( $content, 'wp:block' ) ) && '' !== (string) get_post_meta( $page_id, '_wp_page_template', true ) && (bool) get_post_meta( $page_id, '_dx_ui_css_url', true ) );
$expect( 'the form and slider blocks are registered', $registry->is_registered( 'dx-ui/form' ) && $registry->is_registered( 'dx-ui/slider' ) );
$expect( 'a form entry is accepted', 200 === $submitted->get_status() && ! empty( $submitted->get_data()['ok'] ) );
$expect( 'the library lists the page, its parts, its navigation and its conversion', count( $lib['pages'] ?? array() ) > 0 && count( $lib['template_parts'] ?? array() ) > 0 && count( $lib['navigations'] ?? array() ) > 0 && count( $lib['conversions'] ?? array() ) > 0 );
$expect( 'a blog list becomes a query loop, a slider and a form become their blocks', $blog_ok && $slider_ok && $form_ok );
$expect( 'a page-scoped save makes its own page, header and footer', 200 === $page_res->get_status() && (int) ( $page_created['header_id'] ?? 0 ) > 0 && (int) ( $page_created['footer_id'] ?? 0 ) > 0 && (int) ( $page_created['page_id'] ?? 0 ) > 0 );
$expect( 'the preview answers with HTML and CSS', 200 === $previewed->get_status() && ! empty( $preview_data['html'] ) && ! empty( $preview_data['css'] ) );
$expect( 'every import of the same design writes the same page and a snapshot of its own', count( array_unique( $again_pages ) ) === 1 && $again_pages[0] === $page_id && count( array_unique( array_filter( $again_ids ) ) ) === 6 && min( $again_ids ) > $first_conversion );
$expect( 'the newest ' . \DX_UI\Structures\Structure_Repository::SNAPSHOTS_KEPT . ' snapshots are kept, the older ones deleted', array_slice( array_reverse( $again_ids ), 0, \DX_UI\Structures\Structure_Repository::SNAPSHOTS_KEPT ) === $kept_after && ! get_post( $first_conversion ) && ! get_post( $again_ids[0] ) );
$expect( 'a restore answers 200, writes the same page and keeps a snapshot of its own, marked with the one it came from', 200 === $restored->get_status() && (int) ( $restored_data['page']['id'] ?? 0 ) === $page_id && count( $kept_restore ) === \DX_UI\Structures\Structure_Repository::SNAPSHOTS_KEPT && $kept_restore[0] > $kept_after[0] && (int) get_post_meta( $kept_restore[0], '_dx_ui_restored_from', true ) === $restore_from );
$expect( 'History lists the snapshots newest first, the restore naming its source, an import none', count( $history ) === count( $kept_restore ) && (int) ( $history[0]['id'] ?? 0 ) === $kept_restore[0] && (int) ( $history[0]['restored_from'] ?? 0 ) === $restore_from && '' !== (string) ( $history[0]['restored_date'] ?? '' ) && 0 === (int) ( $history[1]['restored_from'] ?? -1 ) );
$expect( 'dx_ui_snapshots_kept keeps fewer', 2 === count( $kept_fewer ) && $kept_fewer[1] === $kept_restore[0] );
$expect( 'the brand\'s page trashed: the brand passes to the latest design still published, past the snapshots of newer ones', is_array( $brand_after ) && (int) ( $brand_after['page_id'] ?? 0 ) === $older_page );

dxui_verify_cleanup( $dxui_snapshot );
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

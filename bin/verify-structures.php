<?php
/**
 * Persist a fake structured generation without calling an LLM.
 * Run: wp eval-file bin/verify-structures.php
 *
 * @package DXAI_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require DXAI_UI_DIR . 'bin/verify-cleanup.php';
$dxai_snapshot = dxai_verify_snapshot();

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

$save = new WP_REST_Request( 'POST', '/dxai-ui/v1/save-pattern' );
$save->set_param( 'block_title', 'DXAI Structure Smoke' );
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
echo 'has_form_block=' . ( str_contains( $content, 'dxai-ui/form' ) || str_contains( $content, 'wp:block' ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'has_slider_block=' . ( str_contains( $content, 'dxai-ui/slider' ) || str_contains( $content, 'wp:block' ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'page_template=' . (string) get_post_meta( $page_id, '_wp_page_template', true ) . PHP_EOL;
echo 'page_css=' . ( get_post_meta( $page_id, '_dxai_ui_css_url', true ) ? 'set' : 'missing' ) . PHP_EOL;

$registry = WP_Block_Type_Registry::get_instance();
echo 'form_registered=' . ( $registry->is_registered( 'dxai-ui/form' ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'slider_registered=' . ( $registry->is_registered( 'dxai-ui/slider' ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'conversion_supports_revisions=' . ( post_type_supports( 'dxai_conversion', 'revisions' ) ? 'yes' : 'no' ) . PHP_EOL;

$nonce = wp_create_nonce( 'dxai_ui_form' );
$form  = new WP_REST_Request( 'POST', '/dxai-ui/v1/forms/submit' );
$form->set_param( 'nonce', $nonce );
$form->set_param( 'website', '' );
$form->set_param( 'page_id', $page_id );
$form->set_param( 'email', 'verify@example.com' );
$submitted = rest_do_request( $form );
echo 'form_submit_status=' . $submitted->get_status() . PHP_EOL;
echo 'form_submit_ok=' . ( ! empty( $submitted->get_data()['ok'] ) ? 'yes' : 'no' ) . PHP_EOL;

$list = rest_do_request( new WP_REST_Request( 'GET', '/dxai-ui/v1/patterns' ) );
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
echo 'form_rewritten=' . ( $blog_ok && $registry->is_registered( 'dxai-ui/form' ) ? 'yes' : 'no' ) . PHP_EOL;

$slider_ok = false;
$form_ok   = false;
foreach ( $created['patterns'] ?? array() as $pattern ) {
	$p = get_post( (int) $pattern['id'] );
	if ( ! $p instanceof WP_Post ) {
		continue;
	}
	if ( str_contains( $p->post_content, 'dxai-ui/slider' ) ) {
		$slider_ok = true;
	}
	if ( str_contains( $p->post_content, 'dxai-ui/form' ) ) {
		$form_ok = true;
	}
}
echo 'slider_is_block=' . ( $slider_ok ? 'yes' : 'no' ) . PHP_EOL;
echo 'form_is_block=' . ( $form_ok ? 'yes' : 'no' ) . PHP_EOL;

$page_save = new WP_REST_Request( 'POST', '/dxai-ui/v1/save-pattern' );
$page_save->set_param( 'block_title', 'DXAI Page Scope Smoke' );
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

$preview = new WP_REST_Request( 'POST', '/dxai-ui/v1/preview' );
$preview->set_param( 'gutenberg_markup', $hero );
$preview->set_param( 'custom_css', '.hero { min-height: 100vh; }' );
$previewed = rest_do_request( $preview );
$preview_data = $previewed->get_data();
echo 'preview_status=' . $previewed->get_status() . PHP_EOL;
echo 'preview_has_html=' . ( ! empty( $preview_data['html'] ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'preview_has_css=' . ( ! empty( $preview_data['css'] ) ? 'yes' : 'no' ) . PHP_EOL;

dxai_verify_cleanup( $dxai_snapshot );

<?php
declare(strict_types=1);

require rtrim( $argv[1] ?? '', "/\\" ) . DIRECTORY_SEPARATOR . 'wp-load.php';
$page_id = (int) ( $argv[2] ?? 0 );
$post = get_post( $page_id );
if ( ! $post ) { exit(1); }

$css_url = (string) get_post_meta( $page_id, '_dxai_ui_css_url', true );
$css_len = 0;
if ( $css_url !== '' ) {
	$path = str_replace( home_url( '/' ), ABSPATH, $css_url );
	if ( ! is_readable( $path ) ) {
		$upload = wp_upload_dir();
		$path = $upload['basedir'] . '/dxai-ui/' . basename( $css_url );
	}
	if ( is_readable( $path ) ) {
		$css_len = strlen( (string) file_get_contents( $path ) );
	}
}

$content = $post->post_content;
echo 'page_id=' . $page_id . PHP_EOL;
echo 'content_len=' . strlen( $content ) . PHP_EOL;
echo 'wp_group_count=' . substr_count( $content, 'wp-block-group' ) . PHP_EOL;
echo 'wp_html_count=' . substr_count( $content, '<!-- wp:html -->' ) . PHP_EOL;
echo 'style_tag_count=' . substr_count( $content, '<style' ) . PHP_EOL;
echo 'inline_style_count=' . preg_match_all( '/\sstyle="/', $content ) . PHP_EOL;
echo 'data_lucide_count=' . substr_count( $content, 'data-lucide' ) . PHP_EOL;
echo 'compiled_css_bytes=' . $css_len . PHP_EOL;
echo 'css_url=' . $css_url . PHP_EOL;

$conv = get_posts( array(
	'post_type' => DXAI_UI\Content\Content_Types::CONVERSION,
	'meta_key' => '_dxai_ui_page_id',
	'meta_value' => (string) $page_id,
	'posts_per_page' => 1,
) );
if ( $conv ) {
	$payload = json_decode( $conv[0]->post_content, true );
	$r = is_array( $payload['result'] ?? null ) ? $payload['result'] : array();
	echo 'structures=' . count( $r['structures'] ?? array() ) . PHP_EOL;
	echo 'raw_css_len=' . strlen( (string) ( $r['design_css_raw'] ?? '' ) ) . PHP_EOL;
	echo 'custom_css_len=' . strlen( (string) ( $r['custom_css'] ?? '' ) ) . PHP_EOL;
}

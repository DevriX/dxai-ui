<?php
declare(strict_types=1);

$wp_root = $argv[1] ?? '';
$zip     = $argv[2] ?? '';
$page_id = (int) ( $argv[3] ?? 0 );

require rtrim( $wp_root, "/\\" ) . DIRECTORY_SEPARATOR . 'wp-load.php';

$file = array( 'tmp_name' => $zip, 'name' => basename( $zip ) );
$doc  = ( new DXAI_UI\Connectors\Lovable_Connector() )->import_zip( $file );
$compiler = new DXAI_UI\Compiler\Source_Compiler();
$result   = $compiler->compile( $doc );

$payload = $doc->payload;
$sources = is_array( $payload['sources'] ?? null ) ? $payload['sources'] : array();
$page_src = '';
foreach ( $sources as $path => $code ) {
	if ( is_string( $path ) && str_contains( $path, 'routes/index.tsx' ) ) {
		$page_src = (string) $code;
		break;
	}
}

$jsx = new DXAI_UI\Compiler\Jsx_Compiler();
$sections = $jsx->compile_file( $page_src, is_array( $payload['harvest'] ?? null ) ? $payload['harvest'] : array(), $sources );

$html_blob = '';
foreach ( $sections as $s ) {
	$html_blob .= (string) ( $s['html'] ?? '' ) . "\n";
}

echo 'source_chars=' . strlen( $page_src ) . PHP_EOL;
echo 'compiled_html_chars=' . strlen( $html_blob ) . PHP_EOL;
echo 'sections=' . count( $sections ) . PHP_EOL;
echo 'structures=' . count( $result['structures'] ?? array() ) . PHP_EOL;
echo 'custom_css=' . strlen( (string) ( $result['custom_css'] ?? '' ) ) . PHP_EOL;
echo 'wrapper=' . (string) ( $result['wrapper_class'] ?? '' ) . PHP_EOL;

$checks = array(
	'MobileSectionNav' => 'mobile-section-nav',
	'DesktopNav'       => 'DesktopNav',
	'SiteFooter'       => 'SiteFooter',
	'TopicLinkList'    => 'topic-row',
	'FadeUpSection'    => 'dxai-reveal',
	'data-lucide'      => 'data-lucide',
	'inline style tag' => '<style>',
	'ara-navy class'   => 'text-ara-navy',
	'css var font'     => 'var(--font-display)',
	'hero image'       => 'hero-car-accident',
);

foreach ( $checks as $label => $needle ) {
	echo 'check_' . sanitize_key( $label ) . '=' . ( str_contains( $html_blob, $needle ) ? '1' : '0' ) . PHP_EOL;
}

if ( $page_id > 0 ) {
	$post = get_post( $page_id );
	if ( $post instanceof WP_Post ) {
		echo 'saved_page_chars=' . strlen( $post->post_content ) . PHP_EOL;
		echo 'saved_has_wp_block=' . ( str_contains( $post->post_content, '<!-- wp:' ) ? '1' : '0' ) . PHP_EOL;
		echo 'saved_has_html_block=' . ( str_contains( $post->post_content, '<!-- wp:html -->' ) ? '1' : '0' ) . PHP_EOL;
	}
}

echo 'section_names=' . implode( ',', array_map( static fn( $s ) => (string) ( $s['name'] ?? '' ), $sections ) ) . PHP_EOL;

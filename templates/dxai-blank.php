<?php
/**
 * Template Name: DX Blank (pixel-perfect)
 *
 * Blank canvas: theme chrome is skipped. Converted pages store body sections
 * only. The header and footer are injected around the content by Page_Chrome
 * from Appearance > Menus (dxai-ui/site-header) and Appearance > Widgets
 * (dxai-ui/site-footer), or from dxai-* template parts when chrome was kept.
 *
 * @package DXAI_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'dxai-ui-blank' . ( get_post_meta( (int) get_the_ID(), '_dxai_ui_static_html', true ) ? ' dxai-ui-static' : '' ) ); ?>>
<?php wp_body_open(); ?>
<div id="dxai-root" class="dxai-ui-root">
<?php
while ( have_posts() ) {
	the_post();
	// The header and footer Page_Chrome adds stand outside the content's <main>; content it adds none to is the page's <main> itself.
	ob_start();
	the_content();
	$dxai_ui_html = (string) ob_get_clean();
	echo \DXAI_UI\Chrome\Template_Chrome::in_main( (int) get_the_ID(), $dxai_ui_html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the page's content, rendered.
}
?>
</div>
<?php wp_footer(); ?>
</body>
</html>

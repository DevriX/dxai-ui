<?php
/**
 * Template Name: DXAI Blank (pixel-perfect)
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
<main id="dxai-root" class="dxai-ui-root">
<?php
while ( have_posts() ) {
	the_post();
	the_content();
}
?>
</main>
<?php wp_footer(); ?>
</body>
</html>

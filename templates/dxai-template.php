<?php
/**
 * Template Name: DX Template (header from Menus, footer from Widgets)
 *
 * A blank canvas that draws the design's header and footer itself, around the page. The header is the design's own, filled from
 * Appearance > Menus; the footer is the design's frame around the widgets of Appearance > Widgets. The page stores its body only: the
 * content below is the page's, nothing of the chrome is in it (Template_Chrome).
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
<body <?php body_class( 'dxai-ui-blank dxai-ui-template' . ( get_post_meta( (int) get_the_ID(), '_dxai_ui_static_html', true ) ? ' dxai-ui-static' : '' ) ); ?>>
<?php wp_body_open(); ?>
<main id="dxai-root" class="dxai-ui-root">
<?php
while ( have_posts() ) {
	the_post();
	\DXAI_UI\Chrome\Template_Chrome::open( (int) get_the_ID() );
	the_content();
	\DXAI_UI\Chrome\Template_Chrome::close( (int) get_the_ID() );
}
?>
</main>
<?php wp_footer(); ?>
</body>
</html>

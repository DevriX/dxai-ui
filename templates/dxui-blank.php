<?php
/**
 * Template Name: DX Blank (pixel-perfect)
 *
 * Blank canvas: theme chrome is skipped. Converted pages store body sections
 * only. The header and footer are injected around the content by Page_Chrome
 * from Appearance > Menus (dx-ui/site-header) and Appearance > Widgets
 * (dx-ui/site-footer), or from dxui-* template parts when chrome was kept.
 *
 * @package DX_UI
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
<body <?php body_class( 'dx-ui-blank' . ( get_post_meta( (int) get_the_ID(), '_dx_ui_static_html', true ) ? ' dx-ui-static' : '' ) ); ?>>
<?php wp_body_open(); ?>
<div id="dxui-root" class="dx-ui-root">
<?php
while ( have_posts() ) {
	the_post();
	// The header and footer Page_Chrome adds stand outside the content's <main>; content it adds none to is the page's <main> itself.
	ob_start();
	the_content();
	$dx_ui_html = (string) ob_get_clean();
	echo \DX_UI\Chrome\Template_Chrome::in_main( (int) get_the_ID(), $dx_ui_html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the page's content, rendered.
}
?>
</div>
<?php wp_footer(); ?>
</body>
</html>

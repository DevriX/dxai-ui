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
<div id="dxai-root" class="dxai-ui-root">
<?php
// The header, then the page's content in <main>, then the footer (Template_Chrome): each landmark is what it is.
while ( have_posts() ) {
	the_post();
	// (A page the template does not draw — on another template — is drawn as it is, as the page's <main>.)
	$dxai_ui_drawn = \DXAI_UI\Theme\Blank_Template::is_canvas( (int) get_the_ID() );
	echo $dxai_ui_drawn ? '' : \DXAI_UI\Chrome\Template_Chrome::main_open( (string) get_post_field( 'post_content', (int) get_the_ID() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed tag.
	\DXAI_UI\Chrome\Template_Chrome::open( (int) get_the_ID() );
	the_content();
	\DXAI_UI\Chrome\Template_Chrome::close( (int) get_the_ID() );
	echo $dxai_ui_drawn ? '' : '</main>';
}
?>
</div>
<?php wp_footer(); ?>
</body>
</html>

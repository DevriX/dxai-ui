<?php
/**
 * The page that is not there.
 *
 * @package DX_Base
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<div class="dx-base-wrap">
	<h1 class="dx-base-title"><?php esc_html_e( 'This page is not here', 'dx-base' ); ?></h1>
	<p><?php esc_html_e( 'It may have moved. Try the menu or a search.', 'dx-base' ); ?></p>
	<?php get_search_form(); ?>
</div>
<?php
get_footer();

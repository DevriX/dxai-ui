<?php
/**
 * A page that is not a design's: its title and its content, between the site's header and footer. A design's own pages use the plugin's
 * DX template instead, which draws the header and the footer itself around the design.
 *
 * @package DX_Base
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

while ( have_posts() ) {
	the_post();
	?>
	<div class="dx-base-wrap">
		<article id="post-<?php the_ID(); ?>" <?php post_class( 'dx-base-entry dx-base-entry--page' ); ?>>
			<h1 class="dx-base-title"><?php the_title(); ?></h1>
			<div class="dx-base-entry__content"><?php the_content(); ?></div>
		</article>
	</div>
	<?php
}

get_footer();

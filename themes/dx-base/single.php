<?php
/**
 * A post: its title, its date, its content, between the site's header and footer.
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
		<article id="post-<?php the_ID(); ?>" <?php post_class( 'dx-base-entry' ); ?>>
			<h1 class="dx-base-title"><?php the_title(); ?></h1>
			<p class="dx-base-entry__meta"><?php echo esc_html( get_the_date() ); ?></p>
			<div class="dx-base-entry__content"><?php the_content(); ?></div>
		</article>
		<?php
		if ( comments_open() || get_comments_number() ) {
			comments_template();
		}
		?>
	</div>
	<?php
}

get_footer();

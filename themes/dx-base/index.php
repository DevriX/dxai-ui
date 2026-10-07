<?php
/**
 * The fallback template, and the blog: a list of entries, drawn between the site's header and footer.
 *
 * @package DX_Base
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<div class="dx-base-wrap">
	<?php if ( have_posts() ) : ?>
		<?php if ( is_home() && ! is_front_page() ) : ?>
			<h1 class="dx-base-title"><?php single_post_title(); ?></h1>
		<?php elseif ( is_archive() ) : ?>
			<h1 class="dx-base-title"><?php the_archive_title(); ?></h1>
		<?php elseif ( is_search() ) : ?>
			<h1 class="dx-base-title">
				<?php
				/* translators: %s: the search query. */
				printf( esc_html__( 'Search results for: %s', 'dx-base' ), '<span>' . esc_html( get_search_query() ) . '</span>' );
				?>
			</h1>
		<?php endif; ?>
		<?php
		while ( have_posts() ) :
			the_post();
			?>
			<article id="post-<?php the_ID(); ?>" <?php post_class( 'dx-base-entry' ); ?>>
				<h2 class="dx-base-entry__title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
				<div class="dx-base-entry__content"><?php the_excerpt(); ?></div>
			</article>
		<?php endwhile; ?>
		<?php the_posts_pagination(); ?>
	<?php else : ?>
		<h1 class="dx-base-title"><?php esc_html_e( 'Nothing found', 'dx-base' ); ?></h1>
		<p><?php esc_html_e( 'Try a search, or use the menu.', 'dx-base' ); ?></p>
		<?php get_search_form(); ?>
	<?php endif; ?>
</div>
<?php
get_footer();

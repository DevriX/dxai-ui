<?php
/**
 * Turn static design article cards into core/query + seeded WP posts.
 *
 * Lovable/ZIP pages ship hard-coded "Related articles" cards. Pixel-perfect
 * migration keeps the card chrome (classes/markup) as a post-template while
 * the listing itself becomes a dynamic Query Loop filtered by a category
 * created at import time.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Blog_Hydrator {

	/**
	 * @return array{markup:string, seeded:array<int, array<string, mixed>>}
	 */
	public function hydrate( string $markup, string $title_hint = '' ): array {
		$unchanged = array(
			'markup' => $markup,
			'seeded' => array(),
		);

		/*
		 * Raw islands: the Query Loop goes into the one island that holds the
		 * cards, and everything else in the markup stays byte for byte. This
		 * used to rebuild the whole markup from the islands' contents alone, so
		 * every native block beside them was dropped — on a full page, all of
		 * it but the islands.
		 */
		if ( preg_match_all( Design_Html::raw_block_pattern(), $markup, $blocks, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $blocks as $block ) {
				$hydrated = $this->hydrate_html( trim( (string) $block[1][0] ), $title_hint );
				if ( null === $hydrated ) {
					continue;
				}

				return array(
					'markup' => substr_replace( $markup, $this->split_html_block( $hydrated['html'] ), (int) $block[0][1], strlen( (string) $block[0][0] ) ),
					'seeded' => $hydrated['seeded'],
				);
			}

			return $unchanged;
		}

		/*
		 * A native block tree is flattened to raw HTML around the Query Loop.
		 * A self-closing block has no HTML to flatten — a site header, a form,
		 * a navigation — and would vanish, so such a tree is left alone.
		 */
		if ( preg_match( '/<!--\s*wp:[^>]*?\/-->/', $markup ) ) {
			return $unchanged;
		}
		$hydrated = $this->hydrate_html( $this->plain_html( $markup ), $title_hint );
		if ( null === $hydrated ) {
			return $unchanged;
		}

		return array(
			'markup' => $this->split_html_block( $hydrated['html'] ),
			'seeded' => $hydrated['seeded'],
		);
	}

	/**
	 * One piece of design HTML with its card grid swapped for a Query Loop,
	 * and the posts that loop lists; null when nothing was done.
	 *
	 * Nothing is written unless the swap will be used: the grid must be one
	 * replace_card_grid() can swap, and no card may name a post this plugin
	 * did not seed. A card whose title is a real post's slug means the design
	 * lists the site's own articles, and those are never re-filed or given a
	 * featured image here (seed_post()) — the grid stays the design's, with
	 * the design's links, rather than becoming a partial loop.
	 *
	 * @return array{html:string, seeded:array<int, array<string, mixed>>}|null
	 */
	private function hydrate_html( string $html, string $title_hint ): ?array {
		$cards = $this->extract_cards( $html );
		if ( $cards === array() || $this->replace_card_grid( $html, '<!-- wp:query /-->' ) === $html ) {
			return null;
		}
		foreach ( $cards as $card ) {
			if ( $this->foreign_post( sanitize_title( $card['title'] ) ) ) {
				return null;
			}
		}

		$category = $this->ensure_category( $title_hint !== '' ? $title_hint : 'DX Imported' );
		$seeded   = array();
		foreach ( $cards as $card ) {
			$post_id = $this->seed_post( $card, (int) $category['term_id'] );
			if ( $post_id > 0 ) {
				$seeded[] = array_merge( $card, array( 'post_id' => $post_id ) );
			}
		}
		if ( $seeded === array() ) {
			return null;
		}

		$query = $this->query_block( $cards[0], (int) $category['term_id'], count( $seeded ) );
		$out   = $this->replace_card_grid( $html, $query );
		if ( $out === $html ) {
			return null;
		}

		return array(
			'html'   => $out,
			'seeded' => $seeded,
		);
	}

	/**
	 * Strip Gutenberg comments so card regexes see the rendered HTML tree.
	 */
	private function plain_html( string $markup ): string {
		$plain = preg_replace( '/<!--\s*\/?wp:[\s\S]*?-->/', '', $markup ) ?? $markup;

		return trim( $plain );
	}

	/**
	 * Splice a Query Loop between two raw HTML blocks so it is a real Gutenberg
	 * sibling — a nested `<!-- wp:query -->` inside a raw block would not
	 * execute. The raw blocks are this plugin's own (Design_Html::RAW_BLOCK),
	 * so the editor never offers to "convert" them and strip their classes.
	 */
	private function split_html_block( string $replaced_html ): string {
		$pos = strpos( $replaced_html, '<!-- wp:query' );
		if ( $pos === false ) {
			return Design_Html::document( array( $replaced_html ) );
		}
		$end = strpos( $replaced_html, '<!-- /wp:query -->', $pos );
		if ( $end === false ) {
			return Design_Html::document( array( $replaced_html ) );
		}
		$end      += strlen( '<!-- /wp:query -->' );
		$before    = trim( substr( $replaced_html, 0, $pos ) );
		$after     = trim( substr( $replaced_html, $end ) );
		$query_bit = trim( substr( $replaced_html, $pos, $end - $pos ) );

		$parts = array();
		if ( $before !== '' ) {
			$parts[] = Design_Html::document( array( $before ) );
		}
		$parts[] = $query_bit;
		if ( $after !== '' ) {
			$parts[] = Design_Html::document( array( $after ) );
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * @return array<int, array{title:string, image:string, meta:string, href:string, excerpt:string, html:string}>
	 */
	private function extract_cards( string $markup ): array {
		$section = $this->article_section( $markup );
		if ( $section === '' ) {
			return array();
		}

		$cards = array();
		if ( preg_match_all( '/<a\b[^>]*class="[^"]*group[^"]*"[^>]*>[\s\S]*?<\/a>/i', $section, $m ) ) {
			foreach ( $m[0] as $html ) {
				$card = $this->parse_card( $html );
				if ( $card !== null ) {
					$cards[] = $card;
				}
			}
		}

		return $cards;
	}

	private function article_section( string $markup ): string {
		foreach ( array( 'Related articles', 'Keep Reading', 'from our legal team', 'Latest from the blog' ) as $needle ) {
			$pos = stripos( $markup, $needle );
			if ( $pos === false ) {
				continue;
			}
			// Prefer the nearest wrapping section/div that contains the card grid.
			$start = $pos;
			for ( $i = $pos; $i >= 0 && $i > $pos - 2500; $i-- ) {
				if ( strtolower( substr( $markup, $i, 8 ) ) === '<section' || ( substr( $markup, $i, 4 ) === '<div' && str_contains( substr( $markup, $i, 80 ), 'mt-8' ) ) ) {
					$start = $i;
					break;
				}
			}
			$end = strpos( $markup, '</section>', $pos );
			if ( $end === false ) {
				$end = min( strlen( $markup ), $pos + 12000 );
			} else {
				$end += strlen( '</section>' );
			}

			return substr( $markup, $start, $end - $start );
		}

		return '';
	}

	/**
	 * @return array{title:string, image:string, meta:string, href:string, excerpt:string, html:string}|null
	 */
	private function parse_card( string $html ): ?array {
		$title = '';
		if ( preg_match( '/<h[1-6][^>]*>([\s\S]*?)<\/h[1-6]>/i', $html, $m ) ) {
			$title = trim( wp_strip_all_tags( $m[1] ) );
		}
		if ( $title === '' ) {
			return null;
		}
		$image = '';
		if ( preg_match( '/<img[^>]+src="([^"]+)"/i', $html, $m ) ) {
			$image = esc_url_raw( $m[1] );
		}
		$href = '#';
		if ( preg_match( '/<a[^>]+href="([^"]*)"/i', $html, $m ) ) {
			$href = $m[1] !== '' ? $m[1] : '#';
		}
		$meta = '';
		if ( preg_match( '/<(?:p|span)[^>]*>([^<]*(?:Article|min read|min)[^<]*)<\/(?:p|span)>/i', $html, $m ) ) {
			$meta = trim( wp_strip_all_tags( $m[1] ) );
		}
		$excerpt = $meta !== '' ? $meta : '';

		return array(
			'title'   => $title,
			'image'   => $image,
			'meta'    => $meta,
			'href'    => $href,
			'excerpt' => $excerpt,
			'html'    => $html,
		);
	}

	/**
	 * @return array{term_id:int, name:string, slug:string}
	 */
	private function ensure_category( string $title_hint ): array {
		$name = trim( $title_hint );
		if ( $name === '' ) {
			$name = 'DX Imported';
		}
		// Prefer a stable category for related-article seeds.
		$name = 'DX Articles';
		$slug = 'dxai-articles';
		$existing = get_term_by( 'slug', $slug, 'category' );
		if ( $existing instanceof \WP_Term ) {
			return array(
				'term_id' => (int) $existing->term_id,
				'name'    => $existing->name,
				'slug'    => $existing->slug,
			);
		}
		$created = wp_insert_term(
			$name,
			'category',
			array(
				'slug'        => $slug,
				'description' => 'Seeded from DX UI ZIP imports so Query Loops stay dynamic.',
			)
		);
		if ( is_wp_error( $created ) ) {
			$fallback = get_category_by_slug( 'uncategorized' );
			return array(
				'term_id' => $fallback ? (int) $fallback->term_id : 1,
				'name'    => $name,
				'slug'    => $slug,
			);
		}

		return array(
			'term_id' => (int) $created['term_id'],
			'name'    => $name,
			'slug'    => $slug,
		);
	}

	/**
	 * @param array{title:string, image:string, meta:string, href:string, excerpt:string, html:string} $card
	 */
	private function seed_post( array $card, int $category_id ): int {
		$slug = sanitize_title( $card['title'] );
		/*
		 * Only a post this plugin seeded is ever reused. This used to take any
		 * post whose slug matched a card title — a real article on the live
		 * blog — and replace its categories with this one and give it the
		 * design's image, on a click of Convert, with no record of what it
		 * had. A slug held by a post of the site's own is refused before
		 * anything is written (hydrate_html()); here it is refused again.
		 */
		$post_id = $this->seeded_post( $slug );
		if ( $post_id < 1 ) {
			if ( $this->foreign_post( $slug ) ) {
				return 0;
			}
			$post_id = wp_insert_post(
				array(
					// Slashed: wp_insert_post() unslashes, and a title or excerpt
					// holding a backslash lost it.
					'post_title'    => wp_slash( $card['title'] ),
					'post_name'     => $slug,
					'post_status'   => 'publish',
					'post_type'     => 'post',
					'post_content'  => wp_slash(
						$card['excerpt'] !== ''
							? $card['excerpt']
							: sprintf( '<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph -->', esc_html( $card['title'] ) )
					),
					'post_excerpt'  => wp_slash( $card['excerpt'] ),
					// Filed here from the start, so it is never in the default
					// category as well (the card lists its categories).
					'post_category' => array( $category_id ),
					'meta_input'    => array(
						'_dxai_ui_seeded' => '1',
						'_dxai_ui_source' => $card['href'],
					),
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				return 0;
			}
			$post_id = (int) $post_id;
		}

		// Appended: a person may have filed a seeded post under more since.
		wp_set_post_categories( $post_id, array( $category_id ), true );

		if ( $card['image'] !== '' && ! has_post_thumbnail( $post_id ) ) {
			$att = $this->sideload_image( $card['image'], $post_id, $card['title'] );
			if ( $att > 0 ) {
				set_post_thumbnail( $post_id, $att );
			}
		}

		return $post_id;
	}

	/**
	 * The post this plugin seeded under a slug (any status but the trash),
	 * or 0.
	 */
	private function seeded_post( string $slug ): int {
		if ( $slug === '' ) {
			return 0;
		}
		$found = get_posts(
			array(
				'post_type'        => 'post',
				'name'             => $slug,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'meta_key'         => '_dxai_ui_seeded', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'suppress_filters' => true,
			)
		);

		return isset( $found[0] ) ? (int) $found[0] : 0;
	}

	/**
	 * Whether a post this plugin did not seed holds the slug, in any status.
	 * The type goes to get_page_by_path() as a list: given as a string, it
	 * searches attachments as well.
	 */
	private function foreign_post( string $slug ): bool {
		return $this->seeded_post( $slug ) < 1 && get_page_by_path( $slug, OBJECT, array( 'post' ) ) instanceof \WP_Post;
	}

	private function sideload_image( string $url, int $post_id, string $title ): int {
		if ( str_starts_with( $url, home_url( '/' ) ) || str_contains( $url, '/wp-content/uploads/' ) ) {
			$att = attachment_url_to_postid( $url );
			return $att > 0 ? $att : 0;
		}
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$id = media_sideload_image( $url, $post_id, $title, 'id' );

		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * Build a Query Loop whose post-template mirrors the first card's design.
	 *
	 * @param array{title:string, image:string, meta:string, href:string, excerpt:string, html:string} $sample
	 */
	private function query_block( array $sample, int $category_id, int $per_page ): string {
		$card_class = 'group flex flex-col overflow-hidden rounded-xl border border-ara-border bg-white transition-all hover:-translate-y-0.5 hover:border-ara-green hover:shadow-[0_12px_32px_-16px_rgba(10,22,40,0.18)]';
		if ( preg_match( '/class="([^"]+)"/', $sample['html'], $m ) ) {
			$card_class = html_entity_decode( $m[1], ENT_QUOTES );
		}
		$grid_class = 'mc-track -mx-4 flex snap-x snap-mandatory gap-4 overflow-x-auto px-4 pb-2 md:mx-0 md:overflow-visible md:px-0 md:pb-0 md:grid md:grid-cols-3 md:gap-6';
		$query_json = wp_json_encode(
			array(
				'queryId'   => 21,
				'query'     => array(
					'perPage'  => max( 1, $per_page ),
					'pages'    => 0,
					'offset'   => 0,
					'postType' => 'post',
					'order'    => 'desc',
					'orderBy'  => 'date',
					'author'   => '',
					'search'   => '',
					'exclude'  => array(),
					'sticky'   => '',
					'inherit'  => false,
					'taxQuery' => array(
						'category' => array( $category_id ),
					),
				),
				'className' => $grid_class,
			)
		);
		if ( ! is_string( $query_json ) ) {
			$query_json = '{}';
		}
		// The design's class list, decoded above, goes back out encoded for
		// each place it lands: a quote or `--` in it would end the attribute
		// or the block comment.
		$card_attrs = serialize_block_attributes(
			array(
				'tagName'   => 'article',
				'className' => $card_class,
			)
		);
		$card_class = esc_attr( $card_class );

		/*
		 * The title's display face is the `font-display` utility (the theme's
		 * `--font-display`), not a `style.typography.fontFamily` attribute,
		 * which core renders as an inline style.
		 */
		return <<<HTML
<!-- wp:query {$query_json} -->
<div class="wp-block-query {$grid_class}">
	<!-- wp:post-template {"className":"contents","layout":{"type":"default"}} -->
		<!-- wp:group {$card_attrs} -->
		<article class="wp-block-group {$card_class}">
			<!-- wp:post-featured-image {"isLink":true,"className":"relative aspect-[16/10] overflow-hidden bg-ara-tint [&_img]:h-full [&_img]:w-full [&_img]:object-cover"} /-->
			<!-- wp:group {"className":"flex flex-1 flex-col p-5"} -->
			<div class="wp-block-group flex flex-1 flex-col p-5">
				<!-- wp:post-terms {"term":"category","className":"text-[11px] font-semibold uppercase tracking-widest text-ara-green"} /-->
				<!-- wp:post-title {"isLink":true,"level":3,"className":"mt-2 font-display text-[16px] font-bold leading-snug text-ara-navy transition-colors group-hover:text-ara-green"} /-->
				<!-- wp:paragraph {"className":"mt-4 inline-flex items-center gap-1 text-[13px] font-semibold text-ara-green"} -->
				<p class="mt-4 inline-flex items-center gap-1 text-[13px] font-semibold text-ara-green">Read article →</p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</article>
		<!-- /wp:group -->
	<!-- /wp:post-template -->
</div>
<!-- /wp:query -->
HTML;
	}

	private function replace_card_grid( string $markup, string $query_block ): string {
		$section = $this->article_section( $markup );
		if ( $section === '' ) {
			return $markup;
		}
		// Replace the carousel/grid that holds the static <a class="group…"> cards.
		// Through callbacks: the block's attribute JSON holds backslashes,
		// which a replacement string would read as references.
		$replaced = preg_replace_callback(
			'/<div class="mt-8">[\s\S]*?<div class="mc-track[\s\S]*?<\/div>\s*<\/div>/i',
			static fn(): string => '<div class="mt-8">' . "\n" . $query_block . "\n" . '</div>',
			$section,
			1
		);
		if ( ! is_string( $replaced ) || $replaced === $section ) {
			// Fallback: swap the first contiguous run of group cards.
			$replaced = preg_replace_callback(
				'/(?:<a\b[^>]*class="[^"]*group[^"]*"[^>]*>[\s\S]*?<\/a>\s*){2,}/i',
				static fn(): string => $query_block,
				$section,
				1
			);
		}
		if ( ! is_string( $replaced ) ) {
			return $markup;
		}

		return str_replace( $section, $replaced, $markup );
	}
}

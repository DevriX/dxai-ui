<?php
/**
 * The order of a page's sections, as the team's pages have it.
 *
 * @package DXAI_UI\Pages
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

use DXAI_UI\Compiler\Color_Usage;

/**
 * What the eleven sites the team has built agree on (283 pages, measured): a page opens with its hero and closes with
 * the questions and then the call to action — the related services stand before the questions, and the reviews
 * somewhere in the middle. Where the sites differ is everything between (the process steps, the blocks of values,
 * the picture beside a text, how many banners), and that is left as the page has it.
 *
 * A page composed from an old page's content follows the reading order of that page, so its questions may come
 * before its related services or its call to action, and its last section is whatever the old page ended with.
 * This puts the tail in the team's order: related services, questions, call to action, the last one at the end. Only
 * that, and only where it is safe:
 *  - nothing else moves, and no section changes;
 *  - a section that leans on its neighbour (a negative margin, a stacking offset) keeps the page as it is;
 *  - a page that ends in a form (contact, about) keeps its ending;
 *  - the design's Home is never touched, and neither are the header and the footer.
 *
 * Each page keeps its content from before; reverting puts it back, except for a page edited since. `arrange()` is
 * the same rule for a list of sections that are about to be saved as a page (Page_Composer).
 */
final class Page_Order {

	/** On each reordered page: its content before. */
	public const BEFORE = '_dxai_ui_before_order';

	/** On each reordered page: the hash of the content the reorder wrote (a later edit is not undone). */
	public const HASH = '_dxai_ui_order_hash';

	/**
	 * What would change on a page.
	 *
	 * @return array{roles:array<int, string>, moves:array<int, array{role:string, from:int, to:int}>, reason:string}
	 */
	public static function plan( int $post_id ): array {
		$blocks = parse_blocks( (string) get_post_field( 'post_content', $post_id ) );
		$out    = self::order( $blocks );

		return array(
			'roles'  => $out['roles'],
			'moves'  => $out['moves'],
			'reason' => $out['reason'],
		);
	}

	/**
	 * A list of sections in the team's order.
	 *
	 * @param array<int, array<string, mixed>> $sections
	 * @return array<int, array<string, mixed>>
	 */
	public static function arrange( array $sections ): array {
		if ( ! apply_filters( 'dxai_ui_arrange_sections', true ) ) {
			return $sections;
		}
		$sections = array_values( $sections );
		try {
			$order = self::sequence( $sections );
		} catch ( \Throwable $e ) {
			return $sections;
		}
		if ( $order === null ) {
			return $sections;
		}

		return array_map( static fn( $i ) => $sections[ $i ], $order );
	}

	/**
	 * Reorder a page. False when there is nothing to do or it is not safe.
	 */
	public static function apply( int $post_id ): bool {
		$content = (string) get_post_field( 'post_content', $post_id );
		$blocks  = parse_blocks( $content );
		$out     = self::order( $blocks );
		if ( $out['moves'] === array() ) {
			return false;
		}
		$new = serialize_blocks( $out['blocks'] );
		if ( $new === $content ) {
			return false;
		}
		if ( get_post_meta( $post_id, self::BEFORE, true ) === '' ) {
			update_post_meta( $post_id, self::BEFORE, wp_slash( $content ) );
		}
		// wp_update_post() runs KSES for someone without unfiltered HTML; the design's own content is written as it is.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		clean_post_cache( $post_id );
		update_post_meta( $post_id, self::HASH, md5( $new ) );

		return true;
	}

	/**
	 * Put the page's sections back. False when there is nothing to put back, or the page was edited since.
	 */
	public static function revert( int $post_id ): bool {
		$before = get_post_meta( $post_id, self::BEFORE, true );
		if ( ! is_string( $before ) || $before === '' ) {
			return false;
		}
		$now = (string) get_post_field( 'post_content', $post_id );
		if ( md5( $now ) !== (string) get_post_meta( $post_id, self::HASH, true ) ) {
			return false;
		}
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => $before ), array( 'ID' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		clean_post_cache( $post_id );
		delete_post_meta( $post_id, self::BEFORE );
		delete_post_meta( $post_id, self::HASH );

		return true;
	}

	/**
	 * The pages of a design (not its Home), with what a reorder would do to each.
	 *
	 * @return array<int, array{id:int, title:string, roles:array<int, string>, moves:array<int, array{role:string, from:int, to:int}>, reason:string, done:bool}>
	 */
	public static function design( int $home ): array {
		$out = array();
		foreach ( Color_Usage::posts( $home ) as $id ) {
			if ( $id === $home || get_post_type( $id ) !== 'page' ) {
				continue;
			}
			$plan  = self::plan( $id );
			$out[] = array(
				'id'     => $id,
				'title'  => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'roles'  => $plan['roles'],
				'moves'  => $plan['moves'],
				'reason' => $plan['reason'],
				'done'   => is_string( get_post_meta( $id, self::BEFORE, true ) ) && get_post_meta( $id, self::BEFORE, true ) !== '',
			);
		}

		return $out;
	}

	/**
	 * A page's blocks with the tail in order.
	 *
	 * @param array<int, array<string, mixed>> $blocks parse_blocks() of the page.
	 * @return array{blocks:array<int, array<string, mixed>>, roles:array<int, string>, moves:array<int, array{role:string, from:int, to:int}>, reason:string}
	 */
	private static function order( array $blocks ): array {
		$chrome = Section_Library::chrome_blocks( $blocks );
		$skip   = array_merge( $chrome['header'], $chrome['footer'] );
		$where  = array();
		$list   = array();
		foreach ( $blocks as $k => $b ) {
			if ( ! empty( $b['blockName'] ) && ! in_array( $k, $skip, true ) ) {
				$where[] = $k;
				$list[]  = $b;
			}
		}
		$roles  = array();
		$reason = '';
		$order  = self::sequence( $list, $roles, $reason );
		$moves  = array();
		if ( $order !== null ) {
			foreach ( $order as $to => $from ) {
				if ( $from !== $to ) {
					$moves[] = array(
						'role' => $roles[ $from ],
						'from' => $from,
						'to'   => $to,
					);
				}
			}
			// The freeform gaps stay where they are: only the sections trade places.
			$new = $blocks;
			foreach ( $order as $to => $from ) {
				$new[ $where[ $to ] ] = $list[ $from ];
			}
			$blocks = $new;
		}

		return array(
			'blocks' => $blocks,
			'roles'  => $roles,
			'moves'  => $moves,
			'reason' => $reason,
		);
	}

	/**
	 * The new order of a list of sections as the indexes of the old, or null to leave it.
	 *
	 * @param array<int, array<string, mixed>> $sections
	 * @param array<int, string>               $roles   Filled in: the role of each section.
	 * @return array<int, int>|null
	 */
	private static function sequence( array $sections, array &$roles = array(), string &$reason = '' ): ?array {
		$roles = array();
		foreach ( $sections as $i => $b ) {
			$roles[ $i ] = Section_Roles::of( $b, $i === 0 );
		}
		$n = count( $sections );
		if ( $n < 3 || $roles[0] !== 'hero' ) {
			$reason = $n < 3 ? 'too few sections' : 'no hero to open the page';

			return null;
		}
		if ( in_array( 'faq', $roles, true ) === false ) {
			$reason = 'no questions';

			return null;
		}
		if ( $roles[ $n - 1 ] === 'form' ) {
			$reason = 'ends in a form';

			return null;
		}
		foreach ( $sections as $b ) {
			if ( self::leans( $b ) ) {
				$reason = 'a section leans on its neighbour';

				return null;
			}
		}
		// The tail: related services, the questions, the last call to action. Everything else keeps its order.
		$tail = array();
		foreach ( array( 'related', 'faq' ) as $role ) {
			foreach ( $roles as $i => $r ) {
				if ( $r === $role && $i > 0 ) {
					$tail[] = $i;
				}
			}
		}
		$last_cta = null;
		foreach ( $roles as $i => $r ) {
			if ( $r === 'cta' && $i > 0 ) {
				$last_cta = $i;
			}
		}
		if ( $last_cta !== null ) {
			$tail[] = $last_cta;
		}
		$head = array_values( array_diff( array_keys( $sections ), $tail ) );
		$new  = array_merge( $head, $tail );
		if ( $new === array_keys( $sections ) ) {
			$reason = 'already in order';

			return null;
		}
		return $new;
	}

	/**
	 * Whether a section is placed relative to its neighbour: a negative top margin (a card over the hero's edge), or a
	 * position that reaches out of it.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function leans( array $block ): bool {
		$css = Block_Tree::css_of( $block );
		$cls = ' ' . (string) ( $block['attrs']['className'] ?? '' ) . ' ';

		return preg_match( '/margin(?:-top)?:\s*-|top:\s*-|translatey\(-/', $css ) === 1 || preg_match( '/\s-m[tb]-|\s(?:sm|md|lg)-\-m[tb]-/', $cls ) === 1 || str_contains( $css, 'position:sticky' );
	}
}

<?php
/**
 * Native blocks in place of the plugin's own, in pages already imported and in new ones.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks\Native;

use DXAI_UI\Compiler\Color_Usage;

/**
 * The plugin adds a block of its own only where nothing else says the same
 * thing (see Design_Blocks). This puts that into practice on the pages: every
 * stored block that has a native counterpart — a core block, or one the active
 * theme registers — becomes it, and only when the counterpart keeps everything
 * the block carries (a Converter declines otherwise, and the block stays).
 *
 * It works on stored content, so a design imported by an earlier version is
 * converted the way a new import is. Nothing is rewritten in place without a
 * way back: each changed post keeps its content from before, and reverting puts
 * it back as long as the post was not edited since (an edited post is left
 * exactly as its editor left it, and the summary says so).
 *
 * Block delimiters are only ever written by serialize_blocks(); the converters
 * change one block's name, attributes and its own tags.
 */
final class Native_Blocks {

	/** On the design's Home: what was converted, per post. */
	public const META = '_dxai_ui_native_blocks';

	/** On each converted post: its content from before. */
	public const BEFORE = '_dxai_ui_before_native_blocks';

	/**
	 * The converters usable on this site, in the order they run.
	 *
	 * @return array<int, Converter>
	 */
	public static function converters(): array {
		$all = array(
			new Link_Box(),
			new Group(),
			new Span(),
		);
		/**
		 * Converters, in the order they run.
		 *
		 * @param array<int, Converter> $all
		 */
		$all = apply_filters( 'dxai_ui_native_converters', $all );

		return array_values( array_filter( $all, static fn( $c ) => $c instanceof Converter && $c->available() ) );
	}

	/**
	 * Converted content and what changed: converter id => number of blocks.
	 *
	 * @return array{content:string, counts:array<string, int>}
	 */
	public static function convert_content( string $content ): array {
		$converters = self::converters();
		if ( $converters === array() || ! str_contains( $content, '<!-- wp:dxai-ui/' ) ) {
			return array(
				'content' => $content,
				'counts'  => array(),
			);
		}
		$counts = array();
		$blocks = parse_blocks( $content );
		foreach ( $blocks as $i => $block ) {
			$blocks[ $i ] = self::convert_block( $block, $converters, $counts );
		}

		return array(
			'content' => $counts === array() ? $content : serialize_blocks( $blocks ),
			'counts'  => $counts,
		);
	}

	/**
	 * @param array<string, mixed>   $block
	 * @param array<int, Converter>  $converters
	 * @param array<string, int>     $counts
	 * @return array<string, mixed>
	 */
	private static function convert_block( array $block, array $converters, array &$counts ): array {
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $i => $child ) {
			$block['innerBlocks'][ $i ] = self::convert_block( (array) $child, $converters, $counts );
		}
		foreach ( $converters as $converter ) {
			if ( ! in_array( $block['blockName'] ?? '', $converter->sources(), true ) ) {
				continue;
			}
			$converted = $converter->convert( $block );
			if ( $converted !== null ) {
				$counts[ $converter->id() ] = ( $counts[ $converter->id() ] ?? 0 ) + 1;

				return $converted;
			}
		}

		return $block;
	}

	/** Whether a design's pages carry native blocks from this. */
	public static function applied( int $home ): bool {
		$meta = get_post_meta( $home, self::META, true );

		return is_array( $meta ) && ! empty( $meta['posts'] );
	}

	/**
	 * What converting would change, without changing anything.
	 *
	 * @return array{posts:int, counts:array<string, int>}
	 */
	public static function plan( int $home ): array {
		$counts = array();
		$posts  = 0;
		foreach ( Color_Usage::posts( $home ) as $post_id ) {
			$result = self::convert_content( (string) get_post_field( 'post_content', $post_id ) );
			if ( $result['counts'] !== array() ) {
				++$posts;
				foreach ( $result['counts'] as $id => $n ) {
					$counts[ $id ] = ( $counts[ $id ] ?? 0 ) + $n;
				}
			}
		}

		return array(
			'posts'  => $posts,
			'counts' => $counts,
		);
	}

	/**
	 * Convert every post of the design.
	 *
	 * @return array{posts:int, blocks:int}
	 */
	public static function apply( int $home ): array {
		$meta = get_post_meta( $home, self::META, true );
		$meta = is_array( $meta ) ? $meta : array( 'posts' => array() );
		$sum  = array(
			'posts'  => 0,
			'blocks' => 0,
		);
		foreach ( Color_Usage::posts( $home ) as $post_id ) {
			$before = (string) get_post_field( 'post_content', $post_id );
			$result = self::convert_content( $before );
			if ( $result['counts'] === array() || $result['content'] === $before ) {
				continue;
			}
			// The content before the first conversion — or, when the post changed since the last one (a new import,
			// an edit), before this one: an older copy would put back content the post no longer has.
			if ( get_post_meta( $post_id, self::BEFORE, true ) === '' || md5( $before ) !== ( $meta['posts'][ $post_id ] ?? '' ) ) {
				update_post_meta( $post_id, self::BEFORE, wp_slash( $before ) );
			}
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => wp_slash( $result['content'] ),
				)
			);
			$meta['posts'][ $post_id ] = md5( (string) get_post_field( 'post_content', $post_id ) );
			++$sum['posts'];
			$sum['blocks'] += array_sum( $result['counts'] );
		}
		$meta['applied_gmt'] = gmdate( 'c' );
		update_post_meta( $home, self::META, $meta );

		return $sum;
	}

	/**
	 * Put the plugin's own blocks back: each post's content from before, where the post was not edited since.
	 *
	 * @return array{posts:int, edited:int}
	 */
	public static function revert( int $home ): array {
		$meta = get_post_meta( $home, self::META, true );
		$sum  = array(
			'posts'  => 0,
			'edited' => 0,
		);
		if ( ! is_array( $meta ) ) {
			return $sum;
		}
		$left = array();
		foreach ( (array) ( $meta['posts'] ?? array() ) as $post_id => $hash ) {
			$post_id = (int) $post_id;
			$now     = (string) get_post_field( 'post_content', $post_id );
			$before  = (string) get_post_meta( $post_id, self::BEFORE, true );
			if ( $before === '' || md5( $now ) !== $hash ) {
				// Edited since (or the copy is gone): what the editor made stays.
				++$sum['edited'];
				$left[ $post_id ] = $hash;
				continue;
			}
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => wp_slash( $before ),
				)
			);
			delete_post_meta( $post_id, self::BEFORE );
			++$sum['posts'];
		}
		if ( $left === array() ) {
			delete_post_meta( $home, self::META );
		} else {
			$meta['posts'] = $left;
			update_post_meta( $home, self::META, $meta );
		}

		return $sum;
	}

	/**
	 * For the Library: which blocks would change, or did.
	 *
	 * @return array{applied:bool, converters:array<int, array{id:string, label:string, count:int}>, blocks:int, posts:int}
	 */
	public static function summary( int $home ): array {
		$plan = self::plan( $home );
		$rows = array();
		foreach ( self::converters() as $converter ) {
			$rows[] = array(
				'id'    => $converter->id(),
				'label' => $converter->label(),
				'count' => (int) ( $plan['counts'][ $converter->id() ] ?? 0 ),
			);
		}

		return array(
			'applied'    => self::applied( $home ),
			'converters' => $rows,
			'blocks'     => array_sum( $plan['counts'] ),
			'posts'      => $plan['posts'],
		);
	}

	/**
	 * At the end of an import: a design fresh from a ZIP gets native blocks; pages added to it later follow its lead.
	 */
	public static function after_import( int $home, bool $fresh = true ): void {
		if ( $home < 1 || ! ( $fresh || self::applied( $home ) ) ) {
			return;
		}
		/**
		 * Whether a design fresh from an import gets native blocks (the default), or keeps the plugin's own until the
		 * Library converts it.
		 *
		 * @param bool $on   Convert.
		 * @param int  $home The design's Home.
		 */
		if ( $fresh && ! self::applied( $home ) && ! apply_filters( 'dxai_ui_native_blocks_on_import', true, $home ) ) {
			return;
		}
		self::apply( $home );
	}
}

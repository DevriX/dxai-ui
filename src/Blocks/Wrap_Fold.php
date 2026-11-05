<?php
/**
 * A design's page in the editor without its wrapper groups.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks;

use DXAI_UI\Theme\Blank_Template;

/**
 * A design converted to blocks keeps every element it had, and most of a design's elements are wrappers: Elementor puts a heading in a
 * widget in a column in a container in a section, and each became a Group. Gemini Restoration's Home is 664 blocks, 403 of them Groups,
 * 288 of those around one block only, 18 levels deep — the team found it next to impossible to find anything in the List View.
 * Taking a wrapper out of the page changes the page: the design's CSS selects on it (`.gem-element-7a3257c .gem-heading-title`), it
 * holds padding, it is a flex item.
 *
 * So the page keeps them, and the editor does not show them. When the editor asks for a design's page (the REST API, `context=edit`),
 * a wrapper of one block — a Group or a Box with nothing of its own but its element, classes, design CSS, id, ARIA and data
 * attributes — is folded into the block it holds: its block comment and its two tags go in that block's `metadata.dxaiWrap`, outermost
 * first, and the List View shows the heading, the image, the button. `metadata` is the attribute core gives every block (block names,
 * bindings) and keeps as it is, so the fold travels through the editor with the block: it moves, duplicates, copies and is deleted
 * with it. The canvas draws the wrappers around it again (blocks-editor.js), so the design's CSS reaches it as on the front end. When
 * the editor saves, the page is unfolded before it is written (`rest_pre_insert_page`): what is stored, rendered and read by every other
 * part of the plugin is the page as it was, byte for byte.
 *
 * A wrapper with a setting of the editor's own — a colour, spacing, a layout, a lock, a binding — is a block someone edits, and stays
 * one. So does any wrapper of two blocks or more.
 */
final class Wrap_Fold {

	/** Where a folded block keeps its wrappers, in its metadata. */
	public const KEY = 'dxaiWrap';

	/** Where a section lifted to the top of the page keeps the page's frame around it, in its metadata (lift_frame()). */
	public const FRAME = 'dxaiFrame';

	/** The List View name the editor gave a section (name_sections()), in its metadata. */
	public const NAMED = 'dxaiNamed';

	/** The blocks a design's wrappers are. */
	private const WRAPPERS = array( 'core/group', 'dxai-ui/box' );

	/** What a wrapper may carry and still be folded: its element and what the design gave it, nothing the editor sets. */
	private const STRUCTURAL = array( 'tagName', 'className', 'dxaiCss', 'anchor', 'ariaHidden', 'labelledBy', 'ariaLabel', 'role', 'dxaiData', 'attrOrder', 'metadata' );

	public function register(): void {
		add_filter( 'rest_prepare_page', array( self::class, 'prepare' ), 10, 3 );
		add_filter( 'rest_pre_insert_page', array( self::class, 'pre_insert' ), 10, 2 );
	}

	/**
	 * Whether a page's wrappers are folded for the editor: a design's page.
	 */
	public static function applies( int $post_id ): bool {
		/**
		 * Whether a design's page opens in the editor with its wrapper groups folded into the blocks they hold (the default), or with
		 * every wrapper as a block of its own.
		 *
		 * @param bool $fold    Fold them (default true for a design's page).
		 * @param int  $post_id The page.
		 */
		return (bool) apply_filters( 'dxai_ui_fold_wrappers', Blank_Template::is_design_page( $post_id ), $post_id );
	}

	/**
	 * The page the editor opens: folded.
	 *
	 * @param mixed $response
	 * @param mixed $post
	 * @param mixed $request
	 * @return mixed
	 */
	public static function prepare( $response, $post, $request ) {
		if ( ! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		if ( 'edit' !== $request['context'] || ! self::applies( (int) $post->ID ) ) {
			return $response;
		}
		$data = $response->get_data();
		$raw  = is_array( $data ) && is_array( $data['content'] ?? null ) ? ( $data['content']['raw'] ?? null ) : null;
		if ( ! is_string( $raw ) ) {
			return $response;
		}
		$folded = self::fold( $raw );
		if ( $folded !== $raw ) {
			$data['content']['raw'] = $folded;
			$response->set_data( $data );
		}

		return $response;
	}

	/**
	 * The page the editor saves (an update, an autosave): unfolded before it is written.
	 *
	 * @param mixed $prepared
	 * @param mixed $request
	 * @return mixed
	 */
	public static function pre_insert( $prepared, $request ) {
		if ( is_object( $prepared ) && isset( $prepared->post_content ) && is_string( $prepared->post_content ) && str_contains( $prepared->post_content, '"' . self::KEY . '"' ) ) {
			$prepared->post_content = self::unfold( $prepared->post_content );
		}

		return $prepared;
	}

	/**
	 * Content with every wrapper of one block folded into that block.
	 */
	public static function fold( string $content ): string {
		if ( ! str_contains( $content, '<!-- wp:' ) ) {
			return $content;
		}
		$changed = false;
		$blocks  = self::fold_list( parse_blocks( $content ), $changed );
		$blocks  = self::lift_frame( $blocks, $changed );
		$blocks  = self::name_sections( $blocks, $changed );

		return $changed ? serialize_blocks( $blocks ) : $content;
	}

	/**
	 * Content with every folded wrapper put back around its block, as it was before fold().
	 */
	public static function unfold( string $content ): string {
		if ( ! str_contains( $content, '"' . self::KEY . '"' ) && ! str_contains( $content, '"' . self::FRAME . '"' ) && ! str_contains( $content, '"' . self::NAMED . '"' ) ) {
			return $content;
		}

		return serialize_blocks( self::unfold_list( self::drop_frames( parse_blocks( $content ) ) ) );
	}

	/**
	 * The page's sections at the top of the List View. A design's page is often one wrapper around all its sections — the page's
	 * <main>, its content box, the design's page root (Gemini Restoration: main > .page-content > .gem-138, its seven sections in it) —
	 * so every section sat a level or three down, under a group that was not one. When the only group at the top of the page holds two
	 * blocks or more and nothing else of its own, it is lifted: each block it holds stands at the top, carrying the wrapper (with the
	 * ones folded into it) in `metadata.dxaiFrame`, and unfold() puts the blocks that follow one another under the same frame back in
	 * it — with any block someone added among them or after them, which is what a section added to the page would have been.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	private static function lift_frame( array $blocks, bool &$changed ): array {
		$at = null;
		foreach ( $blocks as $i => $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( in_array( $name, array( 'core/group', 'dxai-ui/box' ), true ) ) {
				if ( null !== $at ) {
					return $blocks;
				}
				$at = $i;
			} elseif ( $name !== '' && null !== $at ) {
				// Something after the wrapper: it is not the page's frame.
				return $blocks;
			}
		}
		if ( null === $at || ! self::frame_ok( $blocks[ $at ] ) ) {
			return $blocks;
		}
		$frame   = $blocks[ $at ];
		$content = array_values( (array) $frame['innerContent'] );
		// What stands between one block and the next ('' for nothing), so the frame is written back as it was.
		$seps = array();
		for ( $n = 1; $n < count( $content ) - 2; $n++ ) {
			if ( null === $content[ $n ] ) {
				$seps[] = is_string( $content[ $n + 1 ] ) ? $content[ $n + 1 ] : '';
			}
		}
		// The frame itself as a wrapper: its first and its last tag (record() reads a wrapper of one block, whose close is the third chunk).
		$own          = self::record( $frame );
		$own['close'] = (string) end( $content );
		$record       = array(
			'wraps' => array_merge( self::wraps_of( $frame ), array( $own ) ),
			'seps'  => $seps,
		);
		$record['id'] = substr( md5( (string) wp_json_encode( $record ) ), 0, 12 );
		$lifted       = array();
		foreach ( (array) $frame['innerBlocks'] as $child ) {
			$attrs                            = is_array( $child['attrs'] ?? null ) ? $child['attrs'] : array();
			$attrs['metadata']                = is_array( $attrs['metadata'] ?? null ) ? $attrs['metadata'] : array();
			$attrs['metadata'][ self::FRAME ] = $record;
			$child['attrs']                   = $attrs;
			$lifted[]                         = $child;
		}
		array_splice( $blocks, $at, 1, $lifted );
		$changed = true;

		return $blocks;
	}

	/**
	 * Whether the one group at the top of a page is a frame to lift: a wrapper (as only_child() reads one) of two blocks or more, with
	 * nothing but white space between them.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function frame_ok( array $block ): bool {
		$inner   = (array) ( $block['innerBlocks'] ?? array() );
		$content = array_values( (array) ( $block['innerContent'] ?? array() ) );
		$last    = count( $content ) - 1;
		if ( count( $inner ) < 2 || $last < 2 || ! self::is_open( $content[0] ) || ! self::is_close( $content[ $last ] ) || count( array_filter( $content, 'is_null' ) ) !== count( $inner ) ) {
			return false;
		}
		// Between the blocks, white space only (or nothing), as the parser leaves it: never two strings in a row.
		for ( $n = 1; $n < $last; $n++ ) {
			if ( is_string( $content[ $n ] ) && ( trim( $content[ $n ] ) !== '' || is_string( $content[ $n - 1 ] ) || is_string( $content[ $n + 1 ] ) ) ) {
				return false;
			}
		}
		$attrs = (array) ( $block['attrs'] ?? array() );
		$meta  = $attrs['metadata'] ?? array();
		if ( array_diff( array_keys( $attrs ), self::STRUCTURAL ) !== array() || ! is_array( $meta ) || array_diff( array_keys( $meta ), array( 'name', self::KEY ) ) !== array() ) {
			return false;
		}
		foreach ( $inner as $child ) {
			$name = is_array( $child ) ? (string) ( $child['blockName'] ?? '' ) : '';
			if ( $name === '' || ! \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The frames lifted by lift_frame(), put back around the blocks that carry them.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	private static function drop_frames( array $blocks ): array {
		$out  = array();
		$run  = null;
		$gaps = array();
		foreach ( $blocks as $block ) {
			$frame = $block['attrs']['metadata'][ self::FRAME ] ?? null;
			if ( null !== $frame && ! self::frame_valid( $frame ) ) {
				// Not a frame lift_frame() wrote: nothing of it is written.
				unset( $block['attrs']['metadata'][ self::FRAME ] );
				if ( $block['attrs']['metadata'] === array() ) {
					unset( $block['attrs']['metadata'] );
				}
				$frame = null;
			}
			if ( is_array( $frame ) ) {
				unset( $block['attrs']['metadata'][ self::FRAME ] );
				if ( $block['attrs']['metadata'] === array() ) {
					unset( $block['attrs']['metadata'] );
				}
				$id = (string) ( $frame['id'] ?? '' );
				if ( null !== $run && $run['id'] === $id ) {
					$run['blocks'][] = $block;
					$gaps            = array();
					continue;
				}
				if ( null !== $run ) {
					$out[] = self::frame_block( $run );
					$out   = array_merge( $out, $gaps );
					$gaps  = array();
				}
				$run = array(
					'id'     => $id,
					'frame'  => $frame,
					'blocks' => array( $block ),
				);
				continue;
			}
			if ( null !== $run ) {
				// A block added among the framed ones, or after them, is in the frame. The white space between them is the frame's own;
				// what follows the last of them stays after the frame.
				if ( empty( $block['blockName'] ) ) {
					$gaps[] = $block;
					continue;
				}
				$run['blocks'][] = $block;
				$gaps            = array();
				continue;
			}
			$out[] = $block;
		}
		if ( null !== $run ) {
			$out[] = self::frame_block( $run );
			$out   = array_merge( $out, $gaps );
		}

		return $out;
	}

	/**
	 * Whether a frame is one lift_frame() writes: wrappers that are each a group or a box, with one opening and one closing tag.
	 *
	 * @param mixed $frame
	 */
	private static function frame_valid( $frame ): bool {
		if ( ! is_array( $frame ) || ! is_array( $frame['wraps'] ?? null ) || $frame['wraps'] === array() ) {
			return false;
		}
		foreach ( $frame['wraps'] as $wrap ) {
			if ( ! is_array( $wrap ) || ! in_array( (string) ( $wrap['name'] ?? '' ), self::WRAPPERS, true ) || ! self::is_open( $wrap['open'] ?? null ) || ! self::is_close( $wrap['close'] ?? null ) ) {
				return false;
			}
		}
		foreach ( (array) ( $frame['seps'] ?? array() ) as $sep ) {
			if ( ! is_string( $sep ) || trim( $sep ) !== '' ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array{id:string, frame:array<string, mixed>, blocks:array<int, array<string, mixed>>} $run
	 * @return array<string, mixed>
	 */
	private static function frame_block( array $run ): array {
		$wraps   = array_values( (array) $run['frame']['wraps'] );
		$own     = (array) array_pop( $wraps );
		$seps    = array_values( (array) ( $run['frame']['seps'] ?? array() ) );
		$content = array( (string) ( $own['open'] ?? '' ) );
		foreach ( $run['blocks'] as $n => $block ) {
			$sep = $n > 0 ? (string) ( $seps[ $n - 1 ] ?? ( $seps[0] ?? '' ) ) : '';
			if ( $sep !== '' ) {
				$content[] = $sep;
			}
			$content[] = null;
		}
		$content[] = (string) ( $own['close'] ?? '' );
		$attrs     = is_array( $own['attrs'] ?? null ) ? $own['attrs'] : array();
		if ( $wraps !== array() ) {
			$attrs['metadata']              = is_array( $attrs['metadata'] ?? null ) ? $attrs['metadata'] : array();
			$attrs['metadata'][ self::KEY ] = $wraps;
		}

		return array(
			'blockName'    => (string) ( $own['name'] ?? 'core/group' ),
			'attrs'        => $attrs,
			'innerBlocks'  => $run['blocks'],
			'innerHTML'    => implode( '', array_filter( $content, 'is_string' ) ),
			'innerContent' => $content,
		);
	}

	/**
	 * The sections at the top of the page named in the List View when they have no name (Section_Names reads what each holds: the
	 * hero, a heading, a form). The name is the editor's only (NAMED): unfold() takes it out again unless someone renamed the section.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	private static function name_sections( array $blocks, bool &$changed ): array {
		foreach ( $blocks as $i => $block ) {
			if ( ! empty( $block['attrs']['metadata']['name'] ) ) {
				continue;
			}
			$name = \DXAI_UI\Blocks\Native\Section_Names::for_section( $block );
			if ( $name === '' ) {
				continue;
			}
			$attrs                            = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$attrs['metadata']                = is_array( $attrs['metadata'] ?? null ) ? $attrs['metadata'] : array();
			$attrs['metadata']['name']        = $name;
			$attrs['metadata'][ self::NAMED ] = $name;
			$blocks[ $i ]['attrs']            = $attrs;
			$changed                          = true;
		}

		return $blocks;
	}

	/**
	 * How many blocks the editor shows of a content, and how deep they go, folded and not (the suite and the report read it).
	 *
	 * @return array{blocks:int, depth:int}
	 */
	public static function measure( string $content ): array {
		$count = 0;
		$depth = 0;
		$walk  = static function ( array $blocks, int $d ) use ( &$walk, &$count, &$depth ): void {
			foreach ( $blocks as $block ) {
				if ( empty( $block['blockName'] ) ) {
					continue;
				}
				++$count;
				$depth = max( $depth, $d );
				$walk( (array) ( $block['innerBlocks'] ?? array() ), $d + 1 );
			}
		};
		$walk( parse_blocks( $content ), 1 );

		return array(
			'blocks' => $count,
			'depth'  => $depth,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	private static function fold_list( array $blocks, bool &$changed ): array {
		foreach ( $blocks as $i => $block ) {
			$blocks[ $i ] = self::fold_block( $block, $changed );
		}

		return $blocks;
	}

	/**
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function fold_block( array $block, bool &$changed ): array {
		$child = self::only_child( $block );
		while ( null !== $child ) {
			$wraps   = array_merge( self::wraps_of( $block ), array( self::record( $block ) ), self::wraps_of( $child ) );
			$block   = self::with_wraps( $child, $wraps );
			$changed = true;
			$child   = self::only_child( $block );
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = self::fold_list( (array) $block['innerBlocks'], $changed );
		}

		return $block;
	}

	/**
	 * The one block a wrapper holds, when the wrapper may be folded into it; null otherwise.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>|null
	 */
	private static function only_child( array $block ): ?array {
		if ( ! in_array( (string) ( $block['blockName'] ?? '' ), self::WRAPPERS, true ) ) {
			return null;
		}
		$inner   = (array) ( $block['innerBlocks'] ?? array() );
		$content = array_values( (array) ( $block['innerContent'] ?? array() ) );
		if ( count( $inner ) !== 1 || count( $content ) !== 3 || null !== $content[1] || ! self::is_open( $content[0] ) || ! self::is_close( $content[2] ) ) {
			return null;
		}
		$attrs = (array) ( $block['attrs'] ?? array() );
		if ( array_diff( array_keys( $attrs ), self::STRUCTURAL ) !== array() ) {
			return null;
		}
		// A name, and folds of its own: anything else in metadata (a binding) is the editor's.
		$meta = $attrs['metadata'] ?? array();
		if ( ! is_array( $meta ) || array_diff( array_keys( $meta ), array( 'name', self::KEY ) ) !== array() ) {
			return null;
		}
		$child = $inner[0];
		$name  = is_array( $child ) ? (string) ( $child['blockName'] ?? '' ) : '';
		if ( $name === '' || ! \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
			return null;
		}

		return $child;
	}

	/**
	 * A wrapper as its fold keeps it: the block, its attributes (less the folds it carried), its two tags.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function record( array $block ): array {
		$attrs   = (array) ( $block['attrs'] ?? array() );
		$content = array_values( (array) $block['innerContent'] );
		if ( isset( $attrs['metadata'] ) && is_array( $attrs['metadata'] ) ) {
			// A name it was given for one of its own wrappers stays that wrapper's.
			foreach ( self::wraps_of( $block ) as $wrap ) {
				if ( ! empty( $wrap['named'] ) && ( $attrs['metadata']['name'] ?? null ) === self::record_name( $wrap ) ) {
					unset( $attrs['metadata']['name'] );
				}
			}
			unset( $attrs['metadata'][ self::KEY ] );
			if ( $attrs['metadata'] === array() ) {
				unset( $attrs['metadata'] );
			}
		}

		return array(
			'name'  => (string) $block['blockName'],
			'attrs' => (object) $attrs,
			'open'  => (string) $content[0],
			'close' => (string) $content[2],
		);
	}

	/**
	 * @param array<string, mixed> $block
	 * @return array<int, array<string, mixed>>
	 */
	private static function wraps_of( array $block ): array {
		$meta = $block['attrs']['metadata'] ?? null;

		// Only records: what the editor sends back is read as data, and anything else in the list is not a wrapper.
		return is_array( $meta ) && is_array( $meta[ self::KEY ] ?? null ) ? array_values( array_filter( $meta[ self::KEY ], 'is_array' ) ) : array();
	}

	/**
	 * A block given its wrappers. A wrapper's name (a section the List View names) goes to the block that now stands for it, when the
	 * block has none, and comes back to the wrapper when the page is unfolded.
	 *
	 * @param array<string, mixed>             $block
	 * @param array<int, array<string, mixed>> $wraps
	 * @return array<string, mixed>
	 */
	private static function with_wraps( array $block, array $wraps ): array {
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$meta  = is_array( $attrs['metadata'] ?? null ) ? $attrs['metadata'] : array();
		if ( ! isset( $meta['name'] ) ) {
			foreach ( array_reverse( array_keys( $wraps ) ) as $i ) {
				$name = self::record_name( $wraps[ $i ] );
				if ( $name !== '' ) {
					$meta['name']         = $name;
					$wraps[ $i ]['named'] = true;
					break;
				}
			}
		}
		$meta[ self::KEY ] = array_values( $wraps );
		$attrs['metadata'] = $meta;
		$block['attrs']    = $attrs;

		return $block;
	}

	/**
	 * @param array<string, mixed> $record
	 */
	private static function record_name( array $record ): string {
		$attrs = (array) ( $record['attrs'] ?? array() );
		$meta  = $attrs['metadata'] ?? null;

		return is_array( $meta ) && is_string( $meta['name'] ?? null ) ? $meta['name'] : '';
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	private static function unfold_list( array $blocks ): array {
		foreach ( $blocks as $i => $block ) {
			$blocks[ $i ] = self::unfold_block( $block );
		}

		return $blocks;
	}

	/**
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function unfold_block( array $block ): array {
		if ( ! empty( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = self::unfold_list( (array) $block['innerBlocks'] );
		}
		// The List View name the editor gave a section goes, unless someone renamed it.
		$given = $block['attrs']['metadata'][ self::NAMED ] ?? null;
		if ( null !== $given ) {
			if ( ( $block['attrs']['metadata']['name'] ?? null ) === $given ) {
				unset( $block['attrs']['metadata']['name'] );
			}
			unset( $block['attrs']['metadata'][ self::NAMED ] );
			if ( $block['attrs']['metadata'] === array() ) {
				unset( $block['attrs']['metadata'] );
			}
		}
		$wraps = self::wraps_of( $block );
		if ( $wraps === array() ) {
			return $block;
		}
		$meta = (array) $block['attrs']['metadata'];
		unset( $meta[ self::KEY ] );
		foreach ( $wraps as $wrap ) {
			// The name it was given for a wrapper, unless someone renamed it since.
			if ( ! empty( $wrap['named'] ) && ( $meta['name'] ?? null ) === self::record_name( $wrap ) ) {
				unset( $meta['name'] );
			}
		}
		if ( $meta === array() ) {
			unset( $block['attrs']['metadata'] );
		} else {
			$block['attrs']['metadata'] = $meta;
		}
		for ( $i = count( $wraps ) - 1; $i >= 0; $i-- ) {
			$wrap = $wraps[ $i ];
			if ( ! in_array( (string) ( $wrap['name'] ?? '' ), self::WRAPPERS, true ) ) {
				continue;
			}
			$open  = (string) ( $wrap['open'] ?? '' );
			$close = (string) ( $wrap['close'] ?? '' );
			if ( ! self::is_open( $open ) || ! self::is_close( $close ) ) {
				continue;
			}
			$block = array(
				'blockName'    => (string) $wrap['name'],
				'attrs'        => is_array( $wrap['attrs'] ?? null ) ? $wrap['attrs'] : array(),
				'innerBlocks'  => array( $block ),
				'innerHTML'    => $open . $close,
				'innerContent' => array( $open, null, $close ),
			);
		}

		return $block;
	}

	/**
	 * @param mixed $chunk
	 */
	private static function is_open( $chunk ): bool {
		return is_string( $chunk ) && preg_match( '/^\s*<[a-z][a-z0-9-]*(?:\s[^<>]*)?>\s*$/i', $chunk ) === 1;
	}

	/**
	 * @param mixed $chunk
	 */
	private static function is_close( $chunk ): bool {
		return is_string( $chunk ) && preg_match( '/^\s*<\/[a-z][a-z0-9-]*>\s*$/i', $chunk ) === 1;
	}
}

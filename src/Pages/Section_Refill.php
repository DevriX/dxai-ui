<?php
/**
 * A Home's section of cards, with other cards in it.
 *
 * @package DXAI_UI\Pages
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

/**
 * The cards of one of the Home's sections are taken as the pattern (Home_Kit): the section keeps its wrapper, its heading and its
 * cards exactly as the Home wrote them, with their classes, and says something else — a heading of its own, a card for each of
 * the rows it is given (a title, a few words, an address). Nothing about how it looks is made up, so it looks as the Home does.
 */
final class Section_Refill {

	/**
	 * The section, poured with the rows.
	 *
	 * @param array<string, mixed>                                      $component Section_Library::analyze() of the Home's section.
	 * @param array<string, mixed>                                      $rep       Its set of cards (one of its repeats).
	 * @param array<int, array{title:string, text:string, url:string, label?:string}> $rows What each card says; the section shows as many as it has cards, no more. A row with no
	 *        address has a card that leads nowhere (an address to visit), which a card that is a link as a whole cannot be; "label" is what a link inside the card says.
	 * @return array<string, mixed>|null The section as a block, or null when it cannot hold them (fewer than two rows, a shape this does not know).
	 */
	public static function cards( array $component, array $rep, string $heading, array $rows, string $name = '' ): ?array {
		$items = array_values( (array) $rep['items'] );
		$count = count( $items );
		$n     = min( $count, count( $rows ) );
		if ( $n < 2 || trim( $heading ) === '' ) {
			return null;
		}
		$rows  = array_slice( array_values( $rows ), 0, $n );
		$block = (array) $component['block'];
		$path  = array_values( array_map( 'intval', (array) $rep['path'] ) );

		try {
			// Fewer cards than the Home has: the later ones go, last first, so the others keep their places.
			if ( $n < $count ) {
				$parent = &Block_Tree::at( $block, $path );
				for ( $k = $count - 1; $k >= $n; $k-- ) {
					Block_Tree::drop( $parent, (int) $items[ $k ] );
				}
				unset( $parent );
			}

			// The section as it is now: its cards, and where each says what.
			$now = Section_Library::analyze( $block, (int) $component['index'] );
			$set = null;
			foreach ( (array) $now['repeats'] as $r ) {
				if ( array_map( 'intval', (array) $r['path'] ) === $path && ( $r['kind'] ?? '' ) === 'card' ) {
					$set = $r;
				}
			}
			if ( $set === null || count( (array) $set['items'] ) !== $n || (array) $now['panels'] === array() ) {
				return null;
			}
			$slots = (array) $set['shape']['slots'];
			$root  = (string) ( $set['shape']['root'] ?? '' );
			if ( in_array( $root, array( 'card', 'link' ), true ) && array_filter( $rows, static fn( $r ) => (string) $r['url'] === '' ) ) {
				return null;
			}
			// The heading is this section's; the words the Home had around the cards were about something else. Where they are is
			// read now, while the cards are alike: a card without words has another shape, and would no longer be taken for a card.
			$head = array_map( 'intval', (array) $now['panels'][0]['heading'] );
			$gone = array();
			foreach ( (array) $now['slots'] as $slot ) {
				if ( array_map( 'intval', (array) $slot['path'] ) === $head || ! empty( $slot['decor'] ) || ! in_array( $slot['type'], array( 'text', 'list', 'link', 'heading' ), true ) ) {
					continue;
				}
				$gone[] = array_map( 'intval', (array) $slot['path'] );
			}
			foreach ( $rows as $k => $row ) {
				self::fill( $block, array_merge( $path, array( (int) $set['items'][ $k ] ) ), $slots, $root, $row );
			}
			$h = &Block_Tree::at( $block, $head );
			Block_Tree::set_content( $h, esc_html( $heading ) );
			unset( $h );
			self::remove_all( $block, $gone );
		} catch ( \Throwable $e ) {
			return null;
		}
		// A second copy of a section does not repeat the Home's anchor, and the editor lists it by what it is.
		Block_Tree::strip_anchors( $block );
		if ( $name !== '' ) {
			$block['attrs']['metadata']['name'] = $name;
		}

		return $block;
	}

	/**
	 * One card: its title, its words, and where it leads.
	 *
	 * @param array<string, mixed>                                $block
	 * @param array<int, int>                                     $at    Path of the card from the section.
	 * @param array<int, array<string, mixed>>                    $slots The slots of a card (Section_Library::item_shape()).
	 * @param array{title:string, text:string, url:string, label?:string} $row
	 */
	private static function fill( array &$block, array $at, array $slots, string $root, array $row ): void {
		$item   = &Block_Tree::at( $block, $at );
		$linked = false;
		$titled = null;
		$texted = false;
		$gone   = array();
		$lists  = array(); // the lists of the card: what is in them is theirs, and goes with them
		$url    = (string) $row['url'];
		if ( $url !== '' && in_array( $root, array( 'card', 'link' ), true ) ) {
			// The whole card is the link.
			Block_Tree::set_link( $item, $url );
			$linked = true;
		}
		foreach ( $slots as $slot ) {
			$path = array_map( 'intval', (array) $slot['path'] );
			if ( ! empty( $slot['decor'] ) ) {
				continue;
			}
			foreach ( $lists as $list ) {
				if ( count( $path ) > count( $list ) && array_slice( $path, 0, count( $list ) ) === $list ) {
					continue 2;
				}
			}
			$target = &Block_Tree::at( $item, $path );
			switch ( $slot['type'] ) {
				case 'heading':
					if ( $titled === null ) {
						Block_Tree::set_content( $target, esc_html( $row['title'] ) );
						$titled = $path;
					} else {
						$gone[] = $path;
					}
					break;
				case 'text':
					// The words under the title: a line before it (a number, a tag) was the Home's own label and goes.
					if ( $titled !== null && ! $texted && $row['text'] !== '' ) {
						Block_Tree::set_content( $target, esc_html( $row['text'] ) );
						$texted = true;
					} else {
						$gone[] = $path;
					}
					break;
				case 'link':
					if ( $root === 'card' || $url === '' ) {
						$gone[] = $path; // a link inside a link, or a link to nowhere
						break;
					}
					Block_Tree::set_link( $target, $url, self::label( (string) $slot['text'], (string) ( $row['label'] ?? '' ) ) );
					$linked = true;
					break;
				case 'list':
					$gone[]  = $path;
					$lists[] = $path;
					break;
			}
			unset( $target );
		}
		// A card with nowhere to go: its title is the link.
		if ( ! $linked && $titled !== null && $url !== '' ) {
			$target = &Block_Tree::at( $item, $titled );
			Block_Tree::set_content( $target, '<a href="' . esc_url( $url ) . '">' . esc_html( $row['title'] ) . '</a>' );
			unset( $target );
		}
		self::remove_all( $item, $gone );
		unset( $item );
	}

	/** What a link says: what it was given or "Learn more", with the arrow the Home's link has. */
	private static function label( string $was, string $given = '' ): string {
		$label = $given !== '' ? $given : __( 'Learn more', 'dxai-ui' );
		if ( preg_match( '/\s*([→›»]+)\s*$/u', $was, $m ) === 1 ) {
			$label .= ' ' . $m[1];
		}

		return $label;
	}

	/**
	 * Take out the blocks at these paths: later ones first, so the others keep their places, and none whose container is going.
	 *
	 * @param array<string, mixed>             $block
	 * @param array<int, array<int, int>>      $paths
	 */
	private static function remove_all( array &$block, array $paths ): void {
		$keep = array();
		foreach ( $paths as $p ) {
			$inside = false;
			foreach ( $paths as $q ) {
				if ( $q !== $p && count( $q ) < count( $p ) && array_slice( $p, 0, count( $q ) ) === $q ) {
					$inside = true;
					break;
				}
			}
			if ( ! $inside ) {
				$keep[] = $p;
			}
		}
		// Tree order, reversed: where two paths part, the one that goes on to the higher index comes first.
		usort(
			$keep,
			static function ( array $a, array $b ): int {
				for ( $i = 0, $n = min( count( $a ), count( $b ) ); $i < $n; $i++ ) {
					if ( $a[ $i ] !== $b[ $i ] ) {
						return $b[ $i ] <=> $a[ $i ];
					}
				}

				return 0;
			}
		);
		foreach ( $keep as $p ) {
			Block_Tree::remove( $block, $p );
		}
	}
}

<?php
/**
 * The frame a design's pages have: the group around its header, main and footer, when it has one.
 *
 * @package DXAI_UI\Pages
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

/**
 * A design written as one group around everything (a Claude Design export, Clean Joe) puts rules on that group: the Home's is
 * `overflow-x: clip`, so that a badge wider than a phone's screen is cut at the edge instead of making the page scroll sideways.
 * A page made from the Home's sections that has no such group lacks them, and scrolls sideways at 360 and 320 px where the Home
 * does not. The frame of a page is therefore the Home's own: the same group around the same kind of parts —
 *
 *     wrapper ( header blocks, main ( sections ), footer blocks )
 *
 * — with the wrapper and the `main` as the Home has them (their attributes and classes, not their children). A Home that has no
 * wrapper has pages that are the header blocks, the sections and the footer blocks one after the other.
 *
 * build() makes the blocks of a page, split() reads one back into its parts, whichever frame it was made in.
 */
final class Page_Frame {

	/**
	 * The wrapper and the `main` of a Home, empty, or null for each it does not have.
	 *
	 * @return array{wrapper:array<string, mixed>|null, main:array<string, mixed>|null}
	 */
	public static function shells( int $home ): array {
		$top = self::real( parse_blocks( (string) get_post_field( 'post_content', $home ) ) );
		if ( count( $top ) !== 1 || ! Section_Library::wraps_sections( $top[0] ) ) {
			return array( 'wrapper' => null, 'main' => null );
		}
		$main = null;
		foreach ( self::real( (array) $top[0]['innerBlocks'] ) as $child ) {
			if ( strtolower( (string) ( $child['attrs']['tagName'] ?? '' ) ) === 'main' && Section_Library::wraps_sections( $child ) ) {
				$main = self::shell( $child );
				break;
			}
		}

		return array( 'wrapper' => self::shell( $top[0] ), 'main' => $main );
	}

	/**
	 * The blocks of a page: the header, the sections and the footer, in the Home's frame.
	 *
	 * @param array<int, array<string, mixed>> $head   The header blocks (the skip link, the header bar).
	 * @param array<int, array<string, mixed>> $middle The sections.
	 * @param array<int, array<string, mixed>> $tail   The footer blocks.
	 * @return array<int, array<string, mixed>> Top-level blocks.
	 */
	public static function build( int $home, array $head, array $middle, array $tail ): array {
		$shells = self::shells( $home );
		if ( $shells['wrapper'] === null ) {
			return array_merge( $head, $middle, $tail );
		}
		$inner = $head;
		if ( $shells['main'] !== null && $middle !== array() ) {
			$inner[] = self::fill( $shells['main'], $middle );
		} else {
			$inner = array_merge( $inner, $middle );
		}

		return array( self::fill( $shells['wrapper'], array_merge( $inner, $tail ) ) );
	}

	/**
	 * A page's blocks read back: the wrapper and the `main` are opened (when the Home has them), the first $heads blocks are the
	 * header, the last $tails the footer, the rest the sections.
	 *
	 * @param array<int, array<string, mixed>> $blocks parse_blocks() of the page.
	 * @return array{head:array<int, array<string, mixed>>, middle:array<int, array<string, mixed>>, tail:array<int, array<string, mixed>>, wrapped:bool}
	 */
	public static function split( array $blocks, int $heads, int $tails ): array {
		$top     = self::real( $blocks );
		$wrapped = false;
		if ( count( $top ) === 1 && Section_Library::wraps_sections( $top[0] ) ) {
			$top     = self::real( (array) $top[0]['innerBlocks'] );
			$wrapped = true;
		}
		$flat = array();
		foreach ( $top as $b ) {
			if ( strtolower( (string) ( $b['attrs']['tagName'] ?? '' ) ) === 'main' && Section_Library::wraps_sections( $b ) ) {
				array_push( $flat, ...self::real( (array) $b['innerBlocks'] ) );
				continue;
			}
			$flat[] = $b;
		}
		$n = count( $flat );

		return array(
			'head'    => array_slice( $flat, 0, $heads ),
			'middle'  => array_slice( $flat, $heads, max( 0, $n - $heads - $tails ) ),
			'tail'    => $tails > 0 ? array_slice( $flat, -$tails ) : array(),
			'wrapped' => $wrapped,
		);
	}

	/**
	 * What the wrapper of a page is, as one string: its attributes and its opening markup. Empty for a page that has none. Two pages
	 * (or a page and the Home) are in one frame when these are equal.
	 *
	 * @param array<int, array<string, mixed>> $blocks parse_blocks() of the page.
	 */
	public static function frame_key( array $blocks ): string {
		$top = self::real( $blocks );
		if ( count( $top ) !== 1 || ! Section_Library::wraps_sections( $top[0] ) ) {
			return '';
		}

		return md5( serialize_block( self::shell( $top[0] ) ) );
	}

	/**
	 * A group with the children given, laid out as WordPress lays out a group's: the opening markup, each child with a blank
	 * line between, the closing markup.
	 *
	 * @param array<string, mixed>             $shell
	 * @param array<int, array<string, mixed>> $children
	 * @return array<string, mixed>
	 */
	private static function fill( array $shell, array $children ): array {
		$content = array( $shell['innerContent'][0] );
		foreach ( array_values( $children ) as $i => $child ) {
			if ( $i > 0 ) {
				$content[] = "\n\n";
			}
			$content[] = null;
		}
		$content[]              = $shell['innerContent'][1];
		$shell['innerBlocks']   = array_values( $children );
		$shell['innerContent']  = $content;

		return $shell;
	}

	/**
	 * A block without its children: its opening and closing markup.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function shell( array $block ): array {
		$strings = array_values( array_filter( (array) $block['innerContent'], 'is_string' ) );
		$open    = (string) ( $strings[0] ?? '' );
		$close   = count( $strings ) > 1 ? (string) end( $strings ) : '';
		$block['innerBlocks']  = array();
		$block['innerContent'] = array( $open, $close );
		$block['innerHTML']    = $open . $close;

		return $block;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	private static function real( array $blocks ): array {
		return array_values( array_filter( $blocks, static fn( $b ) => ! empty( $b['blockName'] ) ) );
	}
}

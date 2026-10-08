<?php
/**
 * The sections of a page's block markup, as the Design IR records them.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Design;

use DXAI_UI\Pages\Section_Library;
use DXAI_UI\Pages\Section_Roles;

/**
 * Reads the top-level sections of a converted page out of its block markup, the way the team pages read a Home
 * (Section_Library::for_page(), Section_Roles::of()), so the document and the pages agree on what a section is. The header,
 * the footer and the skip link are not sections (Section_Library::chrome_blocks()).
 *
 * Version 1 of the document reads sections from the compiled blocks (`sections_source: compiled`): they are what the pixel
 * oracle has measured. Reading them from the source itself (the JSX tree, Figma's nodes) is phase 1б of the plan.
 */
final class Section_Reader {

	/** Blocks that are the site's chrome wherever they stand, never a section. */
	private const CHROME = array( 'core/template-part', 'dxai-ui/site-header', 'dxai-ui/site-footer', 'core/navigation' );

	/**
	 * @return array<int, array{index:int, role:string, kind:string, name:string, heading:string, words:int, repeats:int, has:array{image:bool, form:bool, button:bool, video:bool}, sig:string}>
	 */
	public static function from_markup( string $markup ): array {
		if ( trim( $markup ) === '' ) {
			return array();
		}
		$blocks = Section_Library::flatten( parse_blocks( $markup ) );
		$chrome = Section_Library::chrome_blocks( $blocks );
		$skip   = array_merge( $chrome['header'], $chrome['footer'] );
		$out    = array();
		foreach ( $blocks as $i => $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( $name === '' || in_array( $name, self::CHROME, true ) || in_array( $i, $skip, true ) ) {
				continue;
			}
			if ( $name === 'dxai-ui/link' && empty( $block['innerBlocks'] ) ) {
				continue; // the skip link
			}
			$component = Section_Library::analyze( $block, (int) $i );
			if ( ( $component['kind'] ?? '' ) === 'skip' ) {
				continue;
			}
			$html  = serialize_block( $block );
			$out[] = array(
				'index'   => count( $out ),
				'role'    => Section_Roles::of( $block, $out === array() ),
				'kind'    => (string) ( $component['kind'] ?? '' ),
				'name'    => self::name( $block ),
				'heading' => self::heading( $html ),
				'words'   => self::words( $html ),
				'repeats' => self::repeats( $component ),
				'items'   => self::items( $block, $component ),
				'has'     => array(
					'image'  => (bool) preg_match( '#<img\b|wp:image\b|wp:dx/picture\b|wp:dxai-ui/image\b#', $html ),
					'form'   => Section_Library::is_form_block( $block ) || (bool) preg_match( '#<form\b#i', $html ),
					'button' => (bool) preg_match( '#wp-block-button\b|wp-element-button\b|<button\b#', $html ),
					'video'  => (bool) preg_match( '#<video\b|<iframe\b|wp:embed\b|youtube#i', $html ),
				),
				'sig'     => md5( Section_Library::signature( $block ) ),
			);
		}

		return $out;
	}

	/** The words of a piece of markup, counted after the tags are gone. */
	public static function words( string $html ): int {
		$text = html_entity_decode( wp_strip_all_tags( (string) preg_replace( '/<!--.*?-->/s', '', $html ) ), ENT_QUOTES, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return $text === '' ? 0 : count( preg_split( '/\s+/u', $text ) ?: array() );
	}

	/** @param array<string, mixed> $block */
	private static function name( array $block ): string {
		$meta = $block['attrs']['metadata']['name'] ?? '';
		if ( is_string( $meta ) && trim( $meta ) !== '' ) {
			return trim( $meta );
		}
		$class = (string) ( $block['attrs']['className'] ?? '' );

		return trim( (string) strtok( $class, ' ' ) );
	}

	/** The first heading of the section, as words. */
	private static function heading( string $html ): string {
		if ( preg_match( '#<h[1-3]\b[^>]*>(.*?)</h[1-3]>#is', $html, $m ) !== 1 ) {
			return '';
		}
		$text = html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * How many items the section repeats (its cards, its steps, its questions): the largest repeated group.
	 *
	 * @param array<string, mixed> $component
	 */
	private static function repeats( array $component ): int {
		$largest = self::largest( $component );

		return $largest === null ? 0 : count( (array) $largest['items'] );
	}

	/**
	 * What the section's repeated items are called (the heading of each card, step or question), as the team pages read them
	 * (Team_Pages::item_titles()): the names of a design's services and places live here.
	 *
	 * @param array<string, mixed> $block
	 * @param array<string, mixed> $component
	 * @return array<int, string>
	 */
	private static function items( array $block, array $component ): array {
		$largest = self::largest( $component );
		if ( $largest === null ) {
			return array();
		}
		$titles = array();
		$add    = static function ( string $title ) use ( &$titles ): void {
			$title = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) ) );
			// A glyph glued to the name (an arrow after a town, a star before a rating) is the design's decoration, not the name.
			$title = trim( (string) preg_replace( '/^[^\p{L}\p{N}(\'"$]+|[^\p{L}\p{N})\'"!?%.]+$/u', '', $title ) );
			if ( $title !== '' && mb_strlen( $title ) <= 80 && ! in_array( $title, $titles, true ) ) {
				$titles[] = $title;
			}
		};
		foreach ( isset( $largest['shape'] ) ? \DXAI_UI\Pages\Team_Pages::item_titles( $block, $largest ) : array() as $title ) {
			$add( $title );
		}
		if ( $titles === array() ) {
			// Items whose title is not a heading block (a design's own text block with a heading tag, a strong line): the first heading
			// of each item's markup, else its first short line.
			$parent = $block;
			foreach ( (array) $largest['path'] as $i ) {
				$parent = $parent['innerBlocks'][ (int) $i ] ?? array();
			}
			foreach ( (array) $largest['items'] as $i ) {
				$item = $parent['innerBlocks'][ (int) $i ] ?? null;
				if ( ! is_array( $item ) ) {
					continue;
				}
				$html = serialize_block( $item );
				if ( preg_match( '#<(h[1-6]|strong|b|dt|summary)\b[^>]*>(.*?)</\1>#is', $html, $m ) === 1 ) {
					$add( wp_strip_all_tags( $m[2] ) );
					continue;
				}
				$text = trim( html_entity_decode( wp_strip_all_tags( (string) preg_replace( '/<!--.*?-->/s', '', $html ) ), ENT_QUOTES, 'UTF-8' ) );
				$line = trim( (string) strtok( $text, "\n" ) );
				if ( $line !== '' && mb_strlen( $line ) <= 80 ) {
					$add( $line );
				}
			}
		}

		return array_slice( $titles, 0, 24 );
	}

	/**
	 * @param array<string, mixed> $component
	 * @return array<string, mixed>|null
	 */
	private static function largest( array $component ): ?array {
		$best = null;
		foreach ( is_array( $component['repeats'] ?? null ) ? $component['repeats'] : array() as $r ) {
			$n = is_array( $r ) && isset( $r['items'] ) && is_array( $r['items'] ) ? count( $r['items'] ) : 0;
			if ( $n > 0 && ( $best === null || $n > count( (array) $best['items'] ) ) ) {
				$best = $r;
			}
		}

		return $best;
	}
}

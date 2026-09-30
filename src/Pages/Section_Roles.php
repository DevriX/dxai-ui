<?php
/**
 * The part a section plays on a page of a restoration site.
 *
 * @package DXAI_UI\Pages
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

/**
 * The team's sites (eleven of them, read from their databases: 283 pages) build every page from the same handful of
 * kinds of section, and what differs from a site to a site is which of them a page has and where. This names a
 * top-level section by what it holds, the way those pages are read, so a page can be compared with them and its
 * sections placed:
 *
 *  hero      the first section: the page's h1 or a picture behind the words
 *  trust     a bar of badges, certifications or figures
 *  process   how it works, in steps
 *  two-col   text beside a picture
 *  cards     a set of blocks (values, services, benefits)
 *  reviews   what customers say (a review widget or a slider of quotes)
 *  related   the other services or pages, as links
 *  areas     where the company works (a map, a list of places)
 *  form      a form (contact, estimate)
 *  faq       questions and answers
 *  cta       a call to action: a heading and buttons
 *  content   long running text
 *  text      anything else
 *
 * Software decides, from names, classes and the shape of the blocks; a person's own name for a section
 * (its List View name), a heading and the anchor are read too, so "FAQ Section" is a faq wherever it stands.
 */
final class Section_Roles {

	public const ROLES = array( 'hero', 'trust', 'process', 'two-col', 'cards', 'reviews', 'related', 'areas', 'form', 'faq', 'cta', 'content', 'text' );

	/**
	 * The role of a top-level section.
	 *
	 * @param array<string, mixed> $block A parse_blocks() block.
	 * @param bool                 $first Whether it opens the page (after the header).
	 */
	public static function of( array $block, bool $first = false ): string {
		$s     = self::scan( $block );
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$name  = strtolower( (string) ( $attrs['metadata']['name'] ?? '' ) . ' ' . (string) ( $attrs['anchor'] ?? '' ) . ' ' . $s['classes'] );
		$head  = strtolower( implode( ' | ', array_slice( $s['headings'], 0, 2 ) ) );
		$len   = strlen( trim( $s['text'] ) );
		$has   = static fn( string $n ): bool => isset( $s['names'][ $n ] );
		if ( $first && ( $s['h1'] || $has( 'dx/picture' ) || str_contains( $name, 'hero' ) ) ) {
			return 'hero';
		}
		if ( $s['faq'] > 0 || preg_match( '/\bfaqs?\b|frequently asked/', $name . ' ' . $head ) === 1 ) {
			return 'faq';
		}
		if ( $s['form'] ) {
			return 'form';
		}
		if ( $s['trust'] || preg_match( '/testimonial|reviews?\b|what (our )?(customers|clients)|customer stories/', $name . ' ' . $head ) === 1 ) {
			return 'reviews';
		}
		if ( $s['iframe'] || preg_match( '/service areas?|areas we serve|locations? we|where we (work|serve)|\bmap\b/', $name . ' ' . $head ) === 1 ) {
			return 'areas';
		}
		if ( preg_match( '/related|other services|more services|our services|services we|what we (do|offer)/', $name . ' ' . $head ) === 1 && ( $s['links'] >= 3 || $s['columns'] >= 3 ) ) {
			return 'related';
		}
		if ( preg_match( '/process|how it works|our approach|what to expect|\bsteps\b/', $name . ' ' . $head ) === 1 ) {
			return 'process';
		}
		if ( ( $len < 260 && $s['images'] >= 2 && $s['buttons'] === 0 ) || preg_match( '/\bstats?\b|numbers|trust|badge|certif|\blogos?\b|partner|insurance/', $name ) === 1 ) {
			return 'trust';
		}
		if ( $s['buttons'] > 0 && $len < 520 && count( $s['headings'] ) <= 2 ) {
			return 'cta';
		}
		if ( $s['links'] >= 3 || $s['columns'] >= 3 ) {
			return 'cards';
		}
		if ( $s['columns'] === 2 || ( $s['images'] >= 1 && $len > 200 ) ) {
			return 'two-col';
		}

		return $len > 600 ? 'content' : 'text';
	}

	/**
	 * What a section holds.
	 *
	 * @param array<string, mixed> $block
	 * @return array{names:array<string, int>, classes:string, text:string, headings:array<int, string>, h1:bool, buttons:int, faq:int, columns:int, images:int, links:int, iframe:bool, trust:bool, form:bool}
	 */
	private static function scan( array $block ): array {
		$out  = array(
			'names'    => array(),
			'classes'  => '',
			'text'     => '',
			'headings' => array(),
			'h1'       => false,
			'buttons'  => 0,
			'faq'      => 0,
			'columns'  => 0,
			'images'   => 0,
			'links'    => 0,
			'iframe'   => false,
			'trust'    => false,
			'form'     => false,
		);
		$walk = static function ( array $b ) use ( &$walk, &$out ): void {
			$n = (string) ( $b['blockName'] ?? '' );
			if ( $n === '' ) {
				return;
			}
			$out['names'][ $n ] = ( $out['names'][ $n ] ?? 0 ) + 1;
			$a                  = is_array( $b['attrs'] ?? null ) ? $b['attrs'] : array();
			$cl                 = (string) ( $a['className'] ?? '' );
			$out['classes']    .= ' ' . $cl;
			$html               = (string) ( $b['innerHTML'] ?? '' );
			if ( $n === 'core/heading' ) {
				$out['headings'][] = trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
				if ( (int) ( $a['level'] ?? 2 ) === 1 ) {
					$out['h1'] = true;
				}
			}
			if ( in_array( $n, array( 'core/paragraph', 'core/list-item', 'core/heading', 'dxai-ui/text' ), true ) ) {
				$out['text'] .= ' ' . wp_strip_all_tags( $html );
			}
			if ( $n === 'core/button' ) {
				++$out['buttons'];
			}
			if ( $n === 'dxai-ui/link' && ! empty( $b['innerBlocks'] ) === false && preg_match( '/\bbtn|button|bg-dxai-accent/', $cl ) === 1 ) {
				++$out['buttons'];
			}
			if ( $n === 'core/columns' ) {
				$out['columns'] = max( $out['columns'], count( (array) ( $b['innerBlocks'] ?? array() ) ) );
			}
			if ( in_array( $n, array( 'core/image', 'dx/picture', 'dxai-ui/image' ), true ) ) {
				++$out['images'];
			}
			if ( in_array( $n, array( 'amr/link-box', 'dxai-ui/link' ), true ) && ! empty( $b['innerBlocks'] ) ) {
				++$out['links'];
			}
			if ( str_contains( $cl, 'faq-question' ) ) {
				++$out['faq'];
			}
			$blob = $html . wp_json_encode( $a );
			if ( preg_match( '/<iframe|maps\.google|google\.com\/maps/i', $blob ) === 1 ) {
				$out['iframe'] = true;
			}
			if ( stripos( $blob, 'trustindex' ) !== false ) {
				$out['trust'] = true;
			}
			if ( Section_Library::is_form_block( $b ) ) {
				$out['form'] = true;
			}
			foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $child ) {
				if ( is_array( $child ) ) {
					$walk( $child );
				}
			}
		};
		$walk( $block );

		return $out;
	}
}

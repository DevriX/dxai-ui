<?php
/**
 * What a design's Home is made of, read once, for the sections that are not the Home's own.
 *
 * @package DXAI_UI\Pages
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

/**
 * A page made from a Home takes the Home's sections as they are (Team_Pages). Some sections a page needs the Home does not have
 * (a list of the site's other pages, most of all), and a new section must still look like the Home's: the same classes, the same
 * colours, the same spacing. So nothing is drawn from nothing. The kit holds what the Home has, read once from its sections:
 *
 *  - exemplars: its sets of cards that a new set can be poured into (a heading, and cards with a title and a few words, no
 *    picture of their own, no control or widget), each with the part its section plays;
 *  - texts: what each of its cards says, by the card's title, so a card for a page that is a card of the Home's keeps its words;
 *  - vocab: every class and every style value its blocks use, which is the whole of what a new section may wear (guard()).
 *
 * The kit has no look of its own: a section made from it is the Home's section with other cards in it.
 */
final class Home_Kit {

	/** The block attributes that decide how a block looks (the colour, the spacing, the type): a new section may use only values the Home does. */
	private const LOOK = array( 'className', 'dxaiCss', 'style', 'textColor', 'backgroundColor', 'gradient', 'borderColor', 'fontSize', 'fontFamily' );

	/**
	 * @param array<int, array<string, mixed>> $library Section_Library::for_page() of the Home.
	 * @return array{texts:array<string, string>, exemplars:array<int, array{index:int, rep:int, count:int, role:string}>, vocab:array<string, array<string, true>>}
	 */
	public static function of( array $library ): array {
		$library = array_values( $library );

		return array(
			'texts'     => self::texts( $library ),
			'exemplars' => self::exemplars( $library ),
			'vocab'     => self::vocab( $library ),
		);
	}

	/**
	 * What the Home states about how to reach the company, from the whole Home (its header and footer too): the phone number it links
	 * most, the first e-mail address it links, the first street address it writes. What it does not state is empty: nothing is guessed.
	 *
	 * @return array{phone:array{text:string, url:string}|null, email:string, address:string}
	 */
	public static function facts( int $home ): array {
		$html = (string) get_post_field( 'post_content', $home );
		// A header and a footer kept as the design's template parts are the Home's too: the footer is where an address is.
		$key = \DXAI_UI\Chrome\Page_Chrome::part_key_for( $home );
		if ( $key !== '' ) {
			foreach ( array( 'header', 'footer' ) as $area ) {
				$part = get_posts(
					array(
						'post_type'      => 'wp_template_part',
						'name'           => \DXAI_UI\Structures\Template_Part_Factory::slug( $area, $key ),
						'post_status'    => array( 'publish', 'draft', 'private' ),
						'posts_per_page' => 1,
						'no_found_rows'  => true,
					)
				);
				if ( $part !== array() ) {
					$html .= "\n" . (string) $part[0]->post_content;
				}
			}
		}

		return self::facts_of( $html );
	}

	/**
	 * facts() of some markup.
	 *
	 * @return array{phone:array{text:string, url:string}|null, email:string, address:string}
	 */
	public static function facts_of( string $html ): array {
		$out = array(
			'phone'   => null,
			'email'   => '',
			'address' => '',
		);
		// The phone: the number linked most (a header, a button and a footer link the same one), as the Home writes it.
		$count = array();
		$shown = array();
		$href  = array();
		if ( preg_match_all( '#href="tel:([^"]+)"[^>]*>(.*?)</a>#is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$digits = (string) preg_replace( '/\D+/', '', rawurldecode( $x[1] ) );
				if ( strlen( $digits ) < 7 ) {
					continue;
				}
				$count[ $digits ] = ( $count[ $digits ] ?? 0 ) + 1;
				$href[ $digits ]  = $href[ $digits ] ?? rawurldecode( $x[1] );
				$text             = trim( html_entity_decode( wp_strip_all_tags( $x[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				if ( ! isset( $shown[ $digits ] ) && preg_match( '/\+?\(?\d[\d\s().\-]{5,}\d/', $text, $n ) === 1 ) {
					$shown[ $digits ] = trim( $n[0] );
				}
			}
		}
		if ( $count !== array() ) {
			arsort( $count );
			$digits       = (string) array_key_first( $count );
			$out['phone'] = array(
				'text' => $shown[ $digits ] ?? ( strlen( $digits ) === 10 ? sprintf( '(%s) %s-%s', substr( $digits, 0, 3 ), substr( $digits, 3, 3 ), substr( $digits, 6 ) ) : $digits ),
				'url'  => 'tel:' . preg_replace( '/[^\d+]/', '', $href[ $digits ] ),
			);
		}
		if ( preg_match( '#href="mailto:([^"?]+)#i', $html, $m ) === 1 && is_email( rawurldecode( $m[1] ) ) ) {
			$out['email'] = rawurldecode( $m[1] );
		}
		// The address: a street address, as written in the page's words.
		$plain = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( str_replace( '<', ' <', $html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		if ( preg_match( '/\b\d{1,5}\s+(?:[A-Z][\w.\'\-]*\s+){1,4}(?:St|Street|Ave|Avenue|Rd|Road|Blvd|Boulevard|Dr|Drive|Ln|Lane|Way|Ct|Court|Pkwy|Parkway|Hwy|Highway|Pl|Place|Cir|Circle)\b\.?(?:,\s*(?:Suite|Ste|Unit|Apt|#)\s*\w+)?(?:,\s*[A-Z][A-Za-z.\'\-]*(?:\s[A-Z][A-Za-z.\'\-]*){0,2})?(?:,\s*[A-Z]{2}\b)?(?:\s+\d{5}(?:-\d{4})?)?/u', $plain, $m ) === 1 ) {
			$out['address'] = trim( $m[0], " ,\t" );
		}

		return $out;
	}

	/**
	 * The title and the words of each item of a set of cards.
	 *
	 * @param array<string, mixed> $component Section_Library::analyze().
	 * @param array<string, mixed> $rep       One of its repeats.
	 * @return array<int, array{title:string, text:string}>
	 */
	public static function rows( array $component, array $rep ): array {
		$out   = array();
		$slots = (array) ( $rep['shape']['slots'] ?? array() );
		foreach ( (array) $rep['items'] as $index ) {
			$item  = Block_Tree::at( $component['block'], array_merge( (array) $rep['path'], array( (int) $index ) ) );
			$title = '';
			$text  = '';
			foreach ( $slots as $slot ) {
				if ( ! empty( $slot['decor'] ) ) {
					continue;
				}
				$words = Block_Tree::own_text( Block_Tree::at( $item, (array) $slot['path'] ) );
				if ( $slot['type'] === 'heading' && $title === '' ) {
					$title = $words;
				} elseif ( $slot['type'] === 'text' && $text === '' && $title !== '' ) {
					$text = $words;
				}
			}
			$out[] = array(
				'title' => $title,
				'text'  => $text,
			);
		}

		return $out;
	}

	/**
	 * What each card of the Home says, by its title (lower case): the first words found for a title are the Home's.
	 *
	 * @param array<int, array<string, mixed>> $library
	 * @return array<string, string>
	 */
	private static function texts( array $library ): array {
		$out = array();
		foreach ( $library as $component ) {
			foreach ( (array) $component['repeats'] as $rep ) {
				if ( ( $rep['kind'] ?? '' ) !== 'card' ) {
					continue;
				}
				foreach ( self::rows( $component, $rep ) as $row ) {
					$key = mb_strtolower( $row['title'] );
					if ( $key !== '' && $row['text'] !== '' && ! isset( $out[ $key ] ) ) {
						$out[ $key ] = $row['text'];
					}
				}
			}
		}

		return $out;
	}

	/**
	 * The sets of cards a new set can be poured into, the Home's own list of pages first, then its cards.
	 *
	 * @param array<int, array<string, mixed>> $library
	 * @return array<int, array{index:int, rep:int, count:int, role:string}>
	 */
	private static function exemplars( array $library ): array {
		$out = array();
		foreach ( $library as $i => $component ) {
			foreach ( array_values( (array) $component['repeats'] ) as $r => $rep ) {
				if ( ! self::usable( $component, $rep ) ) {
					continue;
				}
				$out[] = array(
					'index' => (int) $i,
					'rep'   => (int) $r,
					'count' => count( (array) $rep['items'] ),
					'role'  => Section_Roles::of( $component['block'], $i === 0 ),
				);
			}
		}

		return $out;
	}

	/**
	 * Whether a set of cards can hold other cards: more than one, cards with a title (and words), no picture of their own, a
	 * section with nothing but a heading (and what it says) around them, and nothing that works by script.
	 *
	 * @param array<string, mixed> $component
	 * @param array<string, mixed> $rep
	 */
	private static function usable( array $component, array $rep ): bool {
		if ( ( $rep['kind'] ?? '' ) !== 'card' || count( (array) $rep['items'] ) < 2 ) {
			return false;
		}
		if ( (array) $component['controls'] !== array() || (array) $component['widgets'] !== array() || (array) $component['panels'] === array() ) {
			return false;
		}
		// One heading for the section; any other heading outside the cards is a smaller one (a note under them), which goes with its words.
		$top = (int) $component['panels'][0]['level'];
		foreach ( array_slice( array_values( (array) $component['panels'] ), 1 ) as $panel ) {
			if ( (int) $panel['level'] <= $top ) {
				return false;
			}
		}
		// Only one set of cards in it: a section with two is not one section to pour into.
		if ( count( array_filter( (array) $component['repeats'], static fn( $r ) => ( $r['kind'] ?? '' ) === 'card' ) ) !== 1 ) {
			return false;
		}
		foreach ( (array) $component['slots'] as $slot ) {
			if ( $slot['type'] === 'image' && empty( $slot['decor'] ) ) {
				return false;
			}
		}
		$has_title = false;
		foreach ( (array) ( $rep['shape']['slots'] ?? array() ) as $slot ) {
			if ( $slot['type'] === 'heading' ) {
				$has_title = true;
			}
			if ( ( $slot['type'] === 'image' && empty( $slot['decor'] ) ) || in_array( $slot['type'], array( 'widget', 'control' ), true ) || ! empty( $slot['flags'] ) ) {
				return false;
			}
		}
		if ( ! $has_title ) {
			return false;
		}
		// The design shows some of its cards on one size of screen only (data-mob, data-desk): not a set to rebuild.
		$parent = Block_Tree::at( $component['block'], (array) $rep['path'] );
		foreach ( (array) $rep['items'] as $index ) {
			$data = (array) ( $parent['innerBlocks'][ $index ]['attrs']['dxaiData'] ?? array() );
			if ( array_intersect( array( 'data-mob', 'data-desk' ), array_keys( $data ) ) !== array() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Every value the Home's blocks use for how they look: attribute => set of values (class names one by one, the rest as JSON).
	 *
	 * @param array<int, array<string, mixed>> $library
	 * @return array<string, array<string, true>>
	 */
	private static function vocab( array $library ): array {
		$out  = array_fill_keys( array_merge( self::LOOK, array( 'inline' ) ), array() );
		$walk = static function ( array $b ) use ( &$walk, &$out ): void {
			foreach ( self::look( $b ) as $key => $values ) {
				foreach ( $values as $v ) {
					$out[ $key ][ $v ] = true;
				}
			}
			foreach ( self::inline_styles( $b ) as $v ) {
				$out['inline'][ $v ] = true;
			}
			foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $child ) {
				if ( is_array( $child ) ) {
					$walk( $child );
				}
			}
		};
		foreach ( $library as $component ) {
			$walk( (array) $component['block'] );
		}

		return $out;
	}

	/**
	 * The look-attributes of one block: attribute => values.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, array<int, string>>
	 */
	private static function look( array $block ): array {
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$out   = array();
		foreach ( self::LOOK as $key ) {
			if ( ! isset( $attrs[ $key ] ) || $attrs[ $key ] === '' || $attrs[ $key ] === array() ) {
				continue;
			}
			$out[ $key ] = $key === 'className'
				? array_values( array_filter( (array) preg_split( '/\s+/', (string) $attrs[ $key ] ), 'strlen' ) )
				: array( is_string( $attrs[ $key ] ) ? $attrs[ $key ] : (string) wp_json_encode( $attrs[ $key ] ) );
		}

		return $out;
	}

	/**
	 * The inline styles in a block's own markup (style="…"), the values only.
	 *
	 * @param array<string, mixed> $block
	 * @return array<int, string>
	 */
	private static function inline_styles( array $block ): array {
		preg_match_all( '/\sstyle="([^"]*)"/', (string) ( $block['innerHTML'] ?? '' ), $m );

		return array_values( array_map( 'strval', $m[1] ) );
	}

	/**
	 * What in a section is not the Home's look: a class, a CSS rule, a colour or a spacing the Home's blocks do not use, or an
	 * inline style. A section made from the kit is allowed to say nothing of its own about how it looks; one that does is not used.
	 *
	 * @param array<string, mixed>                    $block
	 * @param array<string, array<string, true>>      $vocab The kit's vocab.
	 * @return array<int, string> What was found, empty when the section is clean.
	 */
	public static function foreign( array $block, array $vocab ): array {
		$found = array();
		$walk  = static function ( array $b ) use ( &$walk, &$found, $vocab ): void {
			foreach ( self::look( $b ) as $key => $values ) {
				foreach ( $values as $v ) {
					if ( ! isset( $vocab[ $key ][ $v ] ) ) {
						$found[] = $key . ': ' . mb_substr( $v, 0, 60 );
					}
				}
			}
			foreach ( self::inline_styles( $b ) as $v ) {
				if ( ! isset( $vocab['inline'][ $v ] ) ) {
					$found[] = 'inline style: ' . mb_substr( $v, 0, 60 );
				}
			}
			foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $child ) {
				if ( is_array( $child ) ) {
					$walk( $child );
				}
			}
		};
		$walk( $block );

		return array_values( array_unique( $found ) );
	}
}

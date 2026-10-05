<?php
/**
 * The sections a page needs that the Home does not have, made from what the Home has.
 *
 * @package DXAI_UI\Pages
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

/**
 * A blueprint turns the kit (Home_Kit) and what is known — the pages of the site, the facts the Home states — into a section. It
 * answers with a section or with nothing: a page is never given a section that is not the Home's look (Home_Kit::foreign()), and
 * never one with words made up for it.
 *
 *  related  the other pages of the site, as the Home's own cards: a card for each page, with the words the Home has for it
 *  contact  the ways to reach the company, as the Home's own cards: its phone, its e-mail, its address, as the Home states them
 *
 * Each blueprint says what it did in a name (`related:3f9a1c`) that is kept with the page (Team_Pages::OPS_META); the name holds
 * what makes the section this page's, so two pages with different links are not the same section.
 */
final class Section_Blueprints {

	/** The most cards of a list of pages (the services, the places): twelve, four rows of three. */
	public const LIST_MAX = 12;

	/**
	 * A set of cards linking the other pages of the site, in the Home's cards: its list of pages if it has one, else its cards.
	 *
	 * @param array<string, mixed>                                     $kit     Home_Kit::of().
	 * @param array<int, array<string, mixed>>                         $library Section_Library::for_page() of the Home.
	 * @param array<int, array{title:string, text:string, url:string}> $rows    The pages, in the order to show them.
	 * @param array<int, string>                                       $refused What was wrong with each exemplar that was not used.
	 * @param array<int, int>                                          $avoid   Home sections (indexes) the page needs for something else: used only when no other will do.
	 * @param bool                                                     $list    The page is the list of the pages (of the services, of the places): it shows all of them, not as many as the Home's section has cards.
	 * @param int|null                                                 $only    Poured into this Home section and no other (a section a person locked), or none.
	 * @return array{block:array<string, mixed>, op:string, index:int}|null
	 */
	public static function related( array $kit, array $library, array $rows, string $heading, string $seed, array &$refused = array(), array $avoid = array(), bool $list = false, ?int $only = null ): ?array {
		return self::pour( $kit, $library, $rows, $heading, $seed, $list ? __( 'List of pages', 'dxai-ui' ) : __( 'Related pages', 'dxai-ui' ), $list ? 'list' : 'related', array_column( $rows, 'url' ), $refused, $avoid, $list ? self::LIST_MAX : 0, $only );
	}

	/**
	 * What the Home states about how to reach the company, as the rows of a set of cards: the phone, the e-mail, the address. Each is
	 * there only when the Home has it; nothing is made up.
	 *
	 * @param array{phone:array{text:string, url:string}|null, email:string, address:string} $facts Home_Kit::facts().
	 * @return array<int, array{title:string, text:string, url:string, label:string}>
	 */
	public static function contact_rows( array $facts ): array {
		$rows = array();
		if ( ! empty( $facts['phone']['text'] ) ) {
			$rows[] = array(
				'title' => __( 'Call us', 'dxai-ui' ),
				'text'  => (string) $facts['phone']['text'],
				'url'   => (string) $facts['phone']['url'],
				'label' => __( 'Call now', 'dxai-ui' ),
			);
		}
		if ( ! empty( $facts['email'] ) ) {
			$rows[] = array(
				'title' => __( 'Email us', 'dxai-ui' ),
				'text'  => (string) $facts['email'],
				'url'   => 'mailto:' . $facts['email'],
				'label' => __( 'Send an email', 'dxai-ui' ),
			);
		}
		if ( ! empty( $facts['address'] ) ) {
			$rows[] = array(
				'title' => __( 'Visit us', 'dxai-ui' ),
				'text'  => (string) $facts['address'],
				'url'   => '',
				'label' => '',
			);
		}

		return $rows;
	}

	/**
	 * The ways to reach the company, in the Home's cards.
	 *
	 * @param array<string, mixed>                                                       $kit     Home_Kit::of().
	 * @param array<int, array<string, mixed>>                                           $library Section_Library::for_page() of the Home.
	 * @param array<int, array{title:string, text:string, url:string, label:string}>     $rows    contact_rows().
	 * @param array<int, string>                                                         $refused What was wrong with each exemplar that was not used.
	 * @param array<int, int>                                                            $avoid   Home sections (indexes) the page needs for something else.
	 * @param int|null                                                                   $only    Poured into this Home section and no other (a section a person locked), or none.
	 * @return array{block:array<string, mixed>, op:string, index:int}|null
	 */
	public static function contact( array $kit, array $library, array $rows, string $heading, string $seed, array &$refused = array(), array $avoid = array(), ?int $only = null ): ?array {
		return self::pour( $kit, $library, $rows, $heading, $seed, __( 'Contact details', 'dxai-ui' ), 'contact', array_column( $rows, 'text' ), $refused, $avoid, 0, $only );
	}

	/**
	 * Rows poured into the best of the Home's sets of cards: the Home's own list of pages first, then the set the rows fill, then
	 * the seed's; the first that gives a section that is the Home's look all through, and blocks that come back the same.
	 *
	 * @param array<string, mixed>                $kit
	 * @param array<int, array<string, mixed>>    $library
	 * @param array<int, array<string, string>>   $rows
	 * @param array<int, string>                  $identity What makes the section this page's (it is in the name).
	 * @param array<int, string>                  $refused
	 * @param array<int, int>                     $avoid
	 * @param int                                 $grow     How many cards a list may have (0: as many as the Home's section has).
	 * @param int|null                            $only     Only this Home section is tried.
	 * @return array{block:array<string, mixed>, op:string, index:int}|null
	 */
	private static function pour( array $kit, array $library, array $rows, string $heading, string $seed, string $name, string $op, array $identity, array &$refused, array $avoid, int $grow = 0, ?int $only = null ): ?array {
		$library = array_values( $library );
		if ( count( $rows ) < 2 ) {
			return null;
		}
		$rank  = array(
			'related' => 0,
			'cards'   => 1,
		);
		$order = array_values( (array) $kit['exemplars'] );
		if ( $only !== null ) {
			$order = array_values( array_filter( $order, static fn( $e ) => (int) $e['index'] === $only ) );
		}
		usort(
			$order,
			static function ( array $a, array $b ) use ( $rank, $rows, $seed, $avoid, $grow ): int {
				// What the page does not need for something else first; its own kind; then the set that the rows fill (a row of three is not left with a gap); then the seed.
				$by = array(
					(int) in_array( $a['index'], $avoid, true ) <=> (int) in_array( $b['index'], $avoid, true ),
					( $rank[ $a['role'] ] ?? 2 ) <=> ( $rank[ $b['role'] ] ?? 2 ),
					$grow > 0 ? 0 : max( 0, $a['count'] - count( $rows ) ) <=> max( 0, $b['count'] - count( $rows ) ),
					sprintf( '%u', crc32( $seed . '|' . $a['index'] . '|' . $a['rep'] ) ) <=> sprintf( '%u', crc32( $seed . '|' . $b['index'] . '|' . $b['rep'] ) ),
				);
				foreach ( $by as $c ) {
					if ( $c !== 0 ) {
						return $c;
					}
				}

				return 0;
			}
		);
		foreach ( $order as $ex ) {
			$component = $library[ $ex['index'] ] ?? null;
			if ( $component === null ) {
				continue;
			}
			$reps  = array_values( (array) $component['repeats'] );
			$block = Section_Refill::cards( $component, (array) $reps[ $ex['rep'] ], $heading, $rows, $name, $grow );
			if ( $block === null ) {
				continue;
			}
			$foreign = Home_Kit::foreign( $block, (array) $kit['vocab'] );
			if ( $foreign !== array() ) {
				$refused[] = sprintf( 'Home section %d: %s', $ex['index'] + 1, implode( '; ', array_slice( $foreign, 0, 3 ) ) );
				continue;
			}
			if ( is_wp_error( Block_Tree::serialize_checked( array( $block ) ) ) ) {
				$refused[] = sprintf( 'Home section %d: does not parse back', $ex['index'] + 1 );
				continue;
			}

			return array(
				'block' => $block,
				'op'    => $op . ':' . substr( md5( implode( '|', array_slice( $identity, 0, $grow > 0 ? $grow : $ex['count'] ) ) ), 0, 6 ),
				'index' => (int) $ex['index'],
			);
		}

		return null;
	}
}

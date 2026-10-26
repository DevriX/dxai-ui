<?php
/**
 * The sections a page of the network has whatever it is about, taken from its Home as they are.
 *
 * @package DXAI_UI\Pages
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

use DXAI_UI\Network\Network_Model;
use DXAI_UI\Network\Page_Reader;

/**
 * The team's pages of a kind are built in the same order (Network_Model::recipe(): a service is hero › badges › a text beside a
 * picture › cards › the process › a call › cards › reviews › the related services › questions › the form), and some of their
 * sections are the site's, the same on every page: the badges, the process, the reviews, the call to action, the form
 * (docs/PLAN-NETWORK-PAGES.md, 11.1). A page built from an old site's page has only what the old page had — the Lacey page of
 * H2O Away was a title and a paragraph.
 *
 * This adds the Home's own section for each of those the page's kind has and the page has not, word for word (they are the
 * company's: its certifications, its steps, its reviews, its number, its form), where the network's pages of that kind have it:
 * before the first of the page's sections that comes after it there. The page's own sections keep their order. Sections are
 * read as the network's pages were (Page_Reader), so the recipe and the page speak of the same roles.
 */
final class Home_Frame {

	/** The sections that are the site's, the same on every page: added from the Home when a page of its kind has them. */
	public const SHARED = array( 'trust', 'process', 'reviews', 'cta', 'form' );

	/** @var array<string, array<string, array<string, mixed>>> The Home's section for each role, by Home. */
	private static array $homes = array();

	/**
	 * The page's sections with the Home's shared ones its kind has and it has not.
	 *
	 * @param array<int, array<string, mixed>> $sections A page's sections, in order (the first its hero).
	 * @param array<int, array<string, mixed>> $library  Section_Library::for_page() of its Home.
	 * @param string                           $kind     The page's kind (Network_Model::kind_of()).
	 * @param array<int, string>               $log      What was added is said here.
	 * @return array<int, array<string, mixed>>
	 */
	public static function complete( array $sections, array $library, string $kind, array &$log = array() ): array {
		$recipe = $kind !== '' ? Network_Model::recipe( $kind ) : null;
		if ( $recipe === null || $sections === array() || $library === array() ) {
			return $sections;
		}
		$order = array_values( array_map( 'strval', (array) $recipe['roles'] ) );
		$first = array();
		foreach ( $order as $k => $role ) {
			$first[ $role ] = $first[ $role ] ?? (int) $k;
		}
		$sections = array_values( $sections );
		$roles    = array();
		foreach ( $sections as $i => $section ) {
			$roles[] = self::role( $section, (int) $i );
		}
		// Where the page's own sections stand in the recipe: cards that link the other pages are its cards there, a text of the
		// page's own is its text beside a picture (the recipe's words for them).
		$place = static fn( string $role ): string => array(
			'related' => 'cards',
			'text'    => 'two-col',
			'other'   => 'two-col',
			'content' => 'two-col',
		)[ $role ] ?? $role;
		$home = self::home( $library );
		foreach ( $order as $k => $want ) {
			if ( ! in_array( $want, self::SHARED, true ) || in_array( $want, $roles, true ) || ! isset( $home[ $want ] ) ) {
				continue;
			}
			// Before the first of the page's sections the network's pages have after it; at the end when none is.
			$at = count( $sections );
			foreach ( $roles as $i => $role ) {
				if ( $i > 0 && isset( $first[ $place( $role ) ] ) && $first[ $place( $role ) ] > $k ) {
					$at = (int) $i;
					break;
				}
			}
			array_splice( $sections, $at, 0, array( $home[ $want ] ) );
			array_splice( $roles, $at, 0, array( $want ) );
			$log[] = sprintf( 'frame: the Home\'s %s section added at %d (the network\'s %s pages have one)', $want, $at, $kind );
		}

		return $sections;
	}

	/**
	 * A section's role as the network's pages were read: a map is the place's areas, like the recipe says.
	 *
	 * @param array<string, mixed> $section
	 */
	public static function role( array $section, int $index ): string {
		$role = (string) ( Page_Reader::one( render_block( $section ), $index + 1 )['role'] ?? '' );

		return $role === 'map' ? 'areas' : $role;
	}

	/**
	 * The Home's first section of each role (its hero aside).
	 *
	 * @param array<int, array<string, mixed>> $library
	 * @return array<string, array<string, mixed>>
	 */
	private static function home( array $library ): array {
		$key = md5( (string) wp_json_encode( array_map( static fn( array $c ): string => (string) $c['index'] . ':' . md5( serialize_block( (array) $c['block'] ) ), $library ) ) );
		if ( ! isset( self::$homes[ $key ] ) ) {
			$out = array();
			foreach ( array_values( $library ) as $n => $component ) {
				if ( 0 === (int) $n ) {
					continue;
				}
				$role = self::role( (array) $component['block'], (int) $n );
				if ( $role !== '' && ! isset( $out[ $role ] ) ) {
					$out[ $role ] = (array) $component['block'];
				}
			}
			self::$homes[ $key ] = $out;
		}

		return self::$homes[ $key ];
	}
}

<?php
/**
 * The order of the sections of a new page, learned from the team's live sites.
 *
 * @package DXAI_UI\Pages
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

/**
 * data/team-pages.json holds what 283 pages of eleven live sites say about the order of sections (bin/build-team-model.cjs):
 * for each kind of page, the orders each site uses (a site repeats one or two; sites differ from each other), how
 * often a role appears, where it stands, and which role follows which.
 *
 * A recipe for a page is made from that, for a design (its seed):
 *  - the orders of one site are taken as the design's family, which site decided by the seed, and one of them chosen
 *    by how often that site used it: the pages of a design then look like the pages of one site, not a mixture;
 *  - one or two small changes are made to it — a role the sites sometimes have is added where the learned
 *    transitions say it belongs, or one they sometimes leave out is left out — so it is similar to what the team
 *    builds and still not a copy of any one site;
 *  - what every site agrees on is kept: the hero first, every role nearly all pages have, and the tail in the order
 *    related services, questions, the last call to action;
 *  - it is checked against every real page of that kind: a recipe equal to one of them is changed again, and the
 *    distance to the nearest (in sections) is reported.
 *
 * The same seed gives the same recipe, so a design's pages stay as they were when the pages are made again.
 */
final class Page_Recipes {

	/** The roles nearly every page of a kind has are kept (share at least this). */
	private const REQUIRED = 0.95;

	/** @var array<string, mixed>|null */
	private static ?array $model = null;

	/**
	 * The learned model.
	 *
	 * @return array<string, mixed>
	 */
	public static function model(): array {
		if ( self::$model === null ) {
			$file        = DXAI_UI_DIR . 'data/team-pages.json';
			$data        = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			self::$model = is_array( $data ) && is_array( $data['types'] ?? null ) ? $data : array( 'types' => array() );
		}

		return self::$model;
	}

	/**
	 * The kinds of page there is a model for.
	 *
	 * @return array<int, string>
	 */
	public static function types(): array {
		return array_keys( (array) self::model()['types'] );
	}

	/**
	 * A recipe for one kind of page.
	 *
	 * @return array{roles:array<int, string>, family:int, edits:array<int, string>, nearest:int}
	 */
	public static function pick( string $type, string $seed, string $variant = '' ): array {
		$types = (array) self::model()['types'];
		$t     = $types[ $type ] ?? ( $types['service'] ?? null );
		if ( ! is_array( $t ) || ! is_array( $t['families'] ?? null ) || $t['families'] === array() ) {
			return array(
				'roles'   => array( 'hero', 'cards', 'reviews', 'faq', 'cta' ),
				'family'  => -1,
				'edits'   => array(),
				'nearest' => 0,
			);
		}
		$next     = self::rng( (int) sprintf( '%u', crc32( $seed . '|' . $type ) ) );
		$families = $t['families'];
		ksort( $families, SORT_NUMERIC );
		$keys   = array_keys( $families );
		$family = (int) $keys[ (int) floor( $next() * count( $keys ) ) ];
		$orders = (array) $families[ $family ];
		$total  = array_sum( array_map( static fn( $o ) => (int) $o['n'], $orders ) );
		$at     = $next() * max( 1, $total );
		$base   = (array) $orders[0]['roles'];
		foreach ( $orders as $o ) {
			$at -= (int) $o['n'];
			if ( $at <= 0 ) {
				$base = (array) $o['roles'];
				break;
			}
		}
		$base  = self::keep_agreed( array_values( array_map( 'strval', $base ) ), $t, $next );
		$roles = $base;
		$edits = array();
		$real  = self::real_orders( $t );
		// The order and the family are the site's (the seed); the small changes are the page's too (the variant), so two pages of a
		// kind on one site are not the same page. With no variant the page is the site's alone.
		if ( $variant !== '' ) {
			$next = self::rng( (int) sprintf( '%u', crc32( $seed . '|' . $type . '|' . $variant ) ) );
		}
		// One or two small changes, then more until the recipe is not any real page.
		$want = 1 + (int) floor( $next() * 2 );
		for ( $n = 0; $n < $want; $n++ ) {
			$edit = self::edit( $roles, $t, $next );
			if ( $edit !== null ) {
				$roles   = $edit['roles'];
				$edits[] = $edit['what'];
			}
		}
		for ( $n = 0; $n < 6 && self::nearest( $roles, $real ) === 0; $n++ ) {
			$edit = self::edit( $roles, $t, $next );
			if ( $edit === null ) {
				break;
			}
			$roles   = $edit['roles'];
			$edits[] = $edit['what'];
		}

		return array(
			'roles'   => $roles,
			'family'  => $family,
			'edits'   => $edits,
			'nearest' => self::nearest( $roles, $real ),
		);
	}

	/**
	 * The tail of a list of roles in the order every site has it: related services, questions, the last call to action.
	 * Nothing else moves.
	 *
	 * @param array<int, string> $roles
	 * @return array<int, string>
	 */
	public static function tail( array $roles ): array {
		$tail = array();
		foreach ( array( 'related', 'faq' ) as $role ) {
			foreach ( $roles as $i => $r ) {
				if ( $r === $role && $i > 0 ) {
					$tail[] = $i;
				}
			}
		}
		$last = null;
		foreach ( $roles as $i => $r ) {
			if ( $r === 'cta' && $i > 0 ) {
				$last = $i;
			}
		}
		if ( $last !== null ) {
			$tail[] = $last;
		}
		$head = array_values( array_diff( array_keys( $roles ), $tail ) );

		return array_values( array_map( static fn( $i ) => $roles[ $i ], array_merge( $head, $tail ) ) );
	}

	/**
	 * The fewest sections to add, drop or change to turn one list of roles into another.
	 *
	 * @param array<int, string> $a
	 * @param array<int, string> $b
	 */
	public static function distance( array $a, array $b ): int {
		$a = array_values( $a );
		$b = array_values( $b );
		$prev = range( 0, count( $b ) );
		foreach ( $a as $i => $x ) {
			$row = array( $i + 1 );
			foreach ( $b as $j => $y ) {
				$row[ $j + 1 ] = min( $prev[ $j + 1 ] + 1, $row[ $j ] + 1, $prev[ $j ] + ( $x === $y ? 0 : 1 ) );
			}
			$prev = $row;
		}

		return (int) end( $prev );
	}

	/**
	 * What every site agrees on: the hero first (when nearly all pages have one), and every role nearly all pages of
	 * the kind have. A family that lacks one is given it, where the learned transitions put it.
	 *
	 * @param array<int, string>   $roles
	 * @param array<string, mixed> $t     The kind's model.
	 * @return array<int, string>
	 */
	private static function keep_agreed( array $roles, array $t, callable $next ): array {
		$hero = (float) ( $t['roles']['hero']['share'] ?? 0.0 );
		if ( $hero >= 0.85 && ( $roles[0] ?? '' ) !== 'hero' ) {
			$roles = array_values( array_filter( $roles, static fn( $r ) => $r !== 'hero' ) );
			array_unshift( $roles, 'hero' );
		}
		foreach ( (array) $t['roles'] as $role => $v ) {
			if ( (float) $v['share'] >= self::REQUIRED && ! in_array( (string) $role, $roles, true ) ) {
				array_splice( $roles, self::place( $roles, (string) $role, (array) $t['next'], $next ), 0, array( (string) $role ) );
			}
		}

		return self::tail( $roles );
	}

	/**
	 * One change: a role the sites sometimes have goes in where the transitions put it, or one they sometimes leave
	 * out comes out. Null when nothing can change.
	 *
	 * @param array<int, string>   $roles
	 * @param array<string, mixed> $t     The kind's model.
	 * @return array{roles:array<int, string>, what:string}|null
	 */
	private static function edit( array $roles, array $t, callable $next ): ?array {
		$share = array();
		foreach ( (array) $t['roles'] as $r => $v ) {
			$share[ (string) $r ] = (float) $v['share'];
		}
		// Drop: a role that is not in every page, not the hero, and not the last call to action.
		$drop = array();
		foreach ( $roles as $i => $r ) {
			if ( $i > 0 && ( $share[ $r ] ?? 0.0 ) < self::REQUIRED && ! ( $r === 'cta' && $i === count( $roles ) - 1 ) && count( $roles ) > 4 ) {
				$drop[] = $i;
			}
		}
		// Add: a role the sites have on a fair share of their pages, that this one does not have.
		$add = array();
		foreach ( $share as $r => $s ) {
			if ( $s >= 0.25 && $s < self::REQUIRED && $r !== 'hero' && ! in_array( $r, $roles, true ) ) {
				$add[] = $r;
			}
		}
		$can_add  = $add !== array() && count( $roles ) < (int) ( $t['length']['max'] ?? 12 );
		$can_drop = $drop !== array();
		if ( ! $can_add && ! $can_drop ) {
			return null;
		}
		if ( $can_add && ( ! $can_drop || $next() < 0.5 ) ) {
			$role = $add[ (int) floor( $next() * count( $add ) ) ];
			$pos  = self::place( $roles, $role, (array) $t['next'], $next );
			array_splice( $roles, $pos, 0, array( $role ) );

			return array(
				'roles' => self::tail( self::keep_agreed( $roles, $t, $next ) ),
				'what'  => 'added ' . $role,
			);
		}
		$i    = $drop[ (int) floor( $next() * count( $drop ) ) ];
		$what = 'left out ' . $roles[ $i ];
		array_splice( $roles, $i, 1 );

		return array(
			'roles' => self::tail( $roles ),
			'what'  => $what,
		);
	}

	/**
	 * Where a role goes: the gap before the tail where the learned transitions (what follows what) make it likeliest.
	 *
	 * @param array<int, string>                $roles
	 * @param array<string, array<string, int>> $next  role => { following role => count }
	 */
	private static function place( array $roles, string $role, array $next, callable $rand ): int {
		$limit = count( $roles );
		foreach ( $roles as $i => $r ) {
			if ( $i > 0 && in_array( $r, array( 'related', 'faq' ), true ) ) {
				$limit = $i;
				break;
			}
		}
		$prob = static function ( string $from, string $to ) use ( $next ): float {
			$row   = (array) ( $next[ $from ] ?? array() );
			$total = array_sum( array_map( 'intval', $row ) );

			return ( ( (int) ( $row[ $to ] ?? 0 ) ) + 0.5 ) / ( $total + 5 );
		};
		$scores = array();
		for ( $p = 1; $p <= max( 1, $limit ); $p++ ) {
			$before = $roles[ $p - 1 ] ?? '^';
			$after  = $roles[ $p ] ?? '$';
			$scores[ $p ] = $prob( $before, $role ) * $prob( $role, $after );
		}
		arsort( $scores );
		$top = array_slice( $scores, 0, 3, true );
		$sum = array_sum( $top );
		$at  = $rand() * $sum;
		foreach ( $top as $p => $s ) {
			$at -= $s;
			if ( $at <= 0 ) {
				return (int) $p;
			}
		}

		return (int) array_key_first( $top );
	}

	/**
	 * Every order a real page of the kind has, once.
	 *
	 * @param array<string, mixed> $t
	 * @return array<int, array<int, string>>
	 */
	private static function real_orders( array $t ): array {
		$out = array();
		foreach ( (array) $t['families'] as $orders ) {
			foreach ( (array) $orders as $o ) {
				$out[ implode( ' ', (array) $o['roles'] ) ] = array_values( array_map( 'strval', (array) $o['roles'] ) );
			}
		}

		return array_values( $out );
	}

	/**
	 * @param array<int, string>               $roles
	 * @param array<int, array<int, string>>   $real
	 */
	private static function nearest( array $roles, array $real ): int {
		$best = PHP_INT_MAX;
		foreach ( $real as $order ) {
			$best = min( $best, self::distance( $roles, $order ) );
		}

		return $best === PHP_INT_MAX ? 0 : $best;
	}

	/** A small seeded generator (xorshift32): the same seed, the same numbers, on every host. */
	private static function rng( int $seed ): callable {
		$x = $seed !== 0 ? ( $seed & 0xFFFFFFFF ) : 0x9E3779B9;

		return static function () use ( &$x ): float {
			$x ^= ( $x << 13 ) & 0xFFFFFFFF;
			$x ^= $x >> 17;
			$x ^= ( $x << 5 ) & 0xFFFFFFFF;

			return $x / 4294967296;
		};
	}
}

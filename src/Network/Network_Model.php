<?php
/**
 * What the team's sites say about making the pages after the Home.
 *
 * @package DXAI_UI\Network
 */

declare(strict_types=1);

namespace DXAI_UI\Network;

/**
 * The network as a teacher (docs/PLAN-NETWORK-PAGES.md, N0). Every site of the network is read (Site_Source), every page
 * as its sections (Page_Reader), and what the sites have in common is kept:
 *
 *  set        which kinds of page a site has, how many services and cities;
 *  kinds      for each kind of page: how many sections and words, which roles and in what order, what the last section
 *             is, how many of its sections are the Home's (word for word, or its build with new words) on a site whose
 *             Home is an imported design, and the questions of its FAQ;
 *  budgets    the words of each role on each kind of page;
 *  formulas   the headings as formulas ("Our {Service} Process", "{Service} FAQs"), seen on two sites or more;
 *  questions  the FAQ questions of service pages by family of service, seen on two sites or more;
 *  global     which roles a site keeps word for word on half its pages or more (reviews, the form, the call to action);
 *  twins      how often a page is the build of another page of its kind (made from it), and the builds sites share.
 *
 * Only what two sites or more say goes into the formulas and the questions: they are the team's way, not a client's words.
 * Nothing of the look is learned — the look of a site's pages is always its own Home's.
 */
final class Network_Model {

	public const VERSION = 1;

	/** Where the model is kept: a network option on a multisite, so every site of it reads the same. */
	public const OPTION = 'dxai_ui_network_model';

	/** The roles in one letter each, for the orders. */
	public const LETTER = array(
		'hero'    => 'H',
		'cards'   => 'C',
		'related' => 'S',
		'reviews' => 'R',
		'cta'     => 'A',
		'faq'     => 'Q',
		'process' => 'P',
		'two-col' => '2',
		'areas'   => 'M',
		'form'    => 'F',
		'trust'   => 'T',
		'text'    => 'X',
		'map'     => 'G',
		'video'   => 'V',
		'other'   => 'o',
	);

	/** Families of services, by the words of the page's address and title. */
	private const FAMILIES = array(
		'water'          => '/water|flood|dry(ing)?\b|moisture|leak|burst|frozen/',
		'fire'           => '/fire|smoke|soot/',
		'mold'           => '/mou?ld/',
		'storm'          => '/storm|wind|hail|tornado|hurricane|tree/',
		'sewage'         => '/sewage|sewer|backup|back-up/',
		'biohazard'      => '/biohazard|trauma|crime|hoard|unattended|bio-/',
		'reconstruction' => '/reconstruct|rebuild|construction|remodel|repair/',
		'asbestos'       => '/asbestos|lead/',
		'contents'       => '/contents|pack-?out/',
		'plumbing'       => '/plumb|drain|pipe|water-heater|heater|sewer-line|repipe/',
		'odor'           => '/odou?r|deodor/',
		'commercial'     => '/commercial|business/',
	);

	/**
	 * What a page is, by its address and title (and its parent's): the research's rules.
	 *
	 * @param array<string, mixed>              $page
	 * @param array<int, array<string, mixed>> $by_id The site's pages by id.
	 */
	public static function kind_of( array $page, array $by_id ): string {
		$parent = $by_id[ (int) ( $page['parent'] ?? 0 ) ] ?? null;
		$title  = (string) ( $page['title'] ?? '' );
		$t      = strtolower( (string) preg_replace( '/&#\d+;|&[a-z]+;/', ' ', $title ) );
		$s      = strtolower( (string) ( $page['slug'] ?? '' ) );
		$ps     = is_array( $parent ) ? strtolower( (string) ( $parent['slug'] ?? '' ) ) : '';
		$st     = $s . $t;
		$rules  = array(
			'legal'               => preg_match( '/privacy|terms|cookie|accessibility|disclaimer|sitemap/', $s ),
			'thank-you'           => preg_match( '/thank/', $s ),
			'blog-index'          => preg_match( '/^(blog|news|resources|articles)$/', $s ),
			'contact'             => preg_match( '/contact/', $s ),
			'about'               => preg_match( '/^(about|about-us|our-story|our-team|team|who-we-are|meet-the-team|company)$/', $s ) || preg_match( '/about us|our team|our story/', $t ),
			'faq'                 => preg_match( '/faq|frequently/', $st ),
			'reviews'             => preg_match( '/review|testimonial/', $st ),
			'careers'             => preg_match( '/career|job|hiring|employment/', $s ),
			'financing-insurance' => preg_match( '/financ|payment|pay-online|insurance|claims/', $s ),
			'gallery'             => preg_match( '/gallery|portfolio|our-work|projects|before-after/', $s ),
			'areas-index'         => preg_match( '/^(service-areas?|areas?-we-serve|locations|areas|cities|where-we-serve|service-area)$/', $s ),
			'services-index'      => preg_match( '/^(services|our-services|what-we-do)$/', $s ),
			'location'            => preg_match( '/service-areas?|locations|areas-we-serve|cities/', $ps ) || preg_match( '/-(in|near)-[a-z-]+$/', $s ) || preg_match( '/\b(in|near)\s+[A-Z][a-z]+/', $title ) || preg_match( '/,\s*[A-Z]{2}\b/', $title ),
			'service'             => preg_match( '/services?|what-we-do/', $ps ) || preg_match( '/restoration|damage|removal|remediation|cleanup|clean-up|cleaning|mitigation|repair|reconstruction|mold|fire|smoke|water|flood|storm|sewage|biohazard|asbestos|trauma|odor|contents|roof|plumb|drain|leak|hvac|board-up|pack-out|dry/', $s ),
			'audience'            => preg_match( '/emergency|commercial|residential|industries|property-managers|homeowners/', $s ),
		);
		foreach ( $rules as $kind => $hit ) {
			if ( (bool) $hit ) {
				return $kind;
			}
		}

		return 'other';
	}

	/**
	 * The family of a service page ('' when none says).
	 */
	public static function family( string $slug, string $title ): string {
		$hay = strtolower( $slug . ' ' . $title );
		foreach ( self::FAMILIES as $family => $re ) {
			if ( preg_match( $re, $hay ) === 1 ) {
				return $family;
			}
		}

		return '';
	}

	/**
	 * The words that say a family of services ('' for none): "fire|smoke|soot" for fire.
	 */
	public static function family_pattern( string $family ): string {
		return self::FAMILIES[ $family ] ?? '';
	}

	/**
	 * A site read: every page's kind and sections, and how each section is reused on the site — the Home's own words
	 * ('same') or its build with other words ('shape'), the other pages with the same words, the other pages with the
	 * same build.
	 *
	 * @param array<string, mixed> $source A Site_Source record.
	 * @return array<string, mixed>
	 */
	public static function read_site( array $source ): array {
		$pages = (array) ( $source['pages'] ?? array() );
		$by_id = array();
		foreach ( $pages as $p ) {
			$by_id[ (int) $p['id'] ] = $p;
		}
		$menu  = (array) ( $source['menu'] ?? array() );
		$front = (int) ( $source['front'] ?? 0 );
		$site  = array(
			'key'   => (string) ( $source['key'] ?? '' ),
			'url'   => (string) ( $source['url'] ?? '' ),
			'name'  => (string) ( $source['name'] ?? '' ),
			'theme' => (string) ( $source['theme'] ?? '' ),
			'front' => $front,
			'menu'  => count( $menu ),
			'posts' => (int) ( $source['posts'] ?? 0 ),
			'pages' => array(),
		);
		foreach ( $pages as $p ) {
			$read            = Page_Reader::read( (string) ( $p['html'] ?? '' ) );
			$link            = untrailingslashit( (string) ( $p['link'] ?? '' ) );
			$site['pages'][] = array(
				'id'       => (int) $p['id'],
				'slug'     => (string) $p['slug'],
				'link'     => (string) $p['link'],
				'title'    => html_entity_decode( (string) $p['title'], ENT_QUOTES, 'UTF-8' ),
				'parent'   => (int) $p['parent'],
				'modified' => (string) $p['modified'],
				'kind'     => (int) $p['id'] === $front ? 'home' : self::kind_of( $p, $by_id ),
				'in_menu'  => array_filter( $menu, static fn( array $m ): bool => untrailingslashit( (string) $m['href'] ) === $link ) !== array(),
			) + $read;
		}

		// Reuse inside the site.
		$home       = null;
		$text_pages = array();
		$shape_page = array();
		foreach ( $site['pages'] as $p ) {
			if ( $p['kind'] === 'home' ) {
				$home = $p;
				continue;
			}
			foreach ( $p['sections'] as $s ) {
				$text_pages[ $s['text_hash'] ][ $p['id'] ]  = true;
				$shape_page[ $s['shape_hash'] ][ $p['id'] ] = true;
			}
		}
		$home_text  = array();
		$home_shape = array();
		foreach ( is_array( $home ) ? $home['sections'] : array() as $s ) {
			$home_text[ $s['text_hash'] ]   = true;
			$home_shape[ $s['shape_hash'] ] = true;
		}
		foreach ( $site['pages'] as &$p ) {
			$inner = $p['kind'] !== 'home';
			foreach ( $p['sections'] as &$s ) {
				$s['from_home']    = $inner && isset( $home_text[ $s['text_hash'] ] ) ? 'same' : ( $inner && isset( $home_shape[ $s['shape_hash'] ] ) ? 'shape' : '' );
				$s['shared_pages'] = $inner ? count( $text_pages[ $s['text_hash'] ] ?? array() ) - 1 : 0;
				$s['shape_pages']  = $inner ? count( $shape_page[ $s['shape_hash'] ] ?? array() ) - 1 : 0;
			}
			unset( $s );
		}
		unset( $p );
		$site['design_home'] = is_array( $home ) && (bool) $home['design'];

		return $site;
	}

	/**
	 * The model, from the sites read.
	 *
	 * @param array<int, array<string, mixed>> $sites read_site() records.
	 * @return array<string, mixed>
	 */
	public static function learn( array $sites ): array {
		// The builds sites share (the team's kit): a build longer than a bare wrapper, on how many sites.
		$kit = array();
		foreach ( $sites as $site ) {
			foreach ( $site['pages'] as $p ) {
				foreach ( $p['sections'] as $s ) {
					if ( $s['shape_len'] > 60 ) {
						$kit[ $s['shape_hash'] ][ $site['key'] ] = true;
					}
				}
			}
		}
		$all = array();
		foreach ( $sites as $site ) {
			foreach ( $site['pages'] as $p ) {
				$p['site']        = $site['key'];
				$p['design_home'] = (bool) $site['design_home'];
				$all[]            = $p;
			}
		}
		$inner = array_values( array_filter( $all, static fn( array $p ): bool => $p['kind'] !== 'home' && $p['sections'] !== array() ) );

		$model = array(
			'version'  => self::VERSION,
			'learned'  => gmdate( 'c' ),
			'sources'  => array(),
			'set'      => self::set( $sites ),
			'kinds'    => array(),
			'budgets'  => array(),
			'formulas' => self::formulas( $sites ),
			'questions' => self::questions( $sites ),
			'global'   => self::global_roles( $sites ),
			'twins'    => self::twins( $sites ),
			'kit'      => array(
				'builds' => count( $kit ),
				'shared' => count( array_filter( $kit, static fn( array $on ): bool => count( $on ) > 1 ) ),
			),
		);
		foreach ( $sites as $site ) {
			$kinds = array();
			foreach ( $site['pages'] as $p ) {
				$kinds[ $p['kind'] ] = ( $kinds[ $p['kind'] ] ?? 0 ) + 1;
			}
			arsort( $kinds );
			$model['sources'][] = array(
				'key'         => $site['key'],
				'url'         => $site['url'],
				'pages'       => count( $site['pages'] ),
				'design_home' => (bool) $site['design_home'],
				'kinds'       => $kinds,
				'service'     => self::home_shares( $site, 'service' ),
			);
		}

		$by_kind = array();
		foreach ( $inner as $p ) {
			$by_kind[ $p['kind'] ][] = $p;
		}
		uasort( $by_kind, static fn( array $a, array $b ): int => count( $b ) <=> count( $a ) );
		foreach ( $by_kind as $kind => $ps ) {
			$model['kinds'][ $kind ] = self::kind( $ps );
			$model['budgets'][ $kind ] = self::budgets( $ps );
		}

		return $model;
	}

	/**
	 * One kind of page across the network.
	 *
	 * @param array<int, array<string, mixed>> $ps
	 * @return array<string, mixed>
	 */
	private static function kind( array $ps ): array {
		$n      = count( $ps );
		$roles  = array();
		$orders = array();
		$last   = array();
		$secs   = array();
		$faq_q  = array();
		$faq_w  = array();
		foreach ( $ps as $p ) {
			foreach ( array_unique( array_column( $p['sections'], 'role' ) ) as $r ) {
				$roles[ $r ] = ( $roles[ $r ] ?? 0 ) + 1;
			}
			$q            = implode( '', array_map( static fn( array $s ): string => self::LETTER[ $s['role'] ] ?? '?', $p['sections'] ) );
			$orders[ $q ] = ( $orders[ $q ] ?? 0 ) + 1;
			$end          = (string) end( $p['sections'] )['role'];
			$last[ $end ] = ( $last[ $end ] ?? 0 ) + 1;
			if ( $p['design_home'] ) {
				foreach ( $p['sections'] as $s ) {
					$secs[] = $s;
				}
			}
			foreach ( $p['sections'] as $s ) {
				if ( $s['role'] === 'faq' && $s['questions'] > 0 ) {
					$faq_q[] = (int) $s['questions'];
					$faq_w[] = (int) $s['words'];
				}
			}
		}
		arsort( $roles );
		arsort( $orders );
		arsort( $last );
		$share = static fn( int $a ): float => $n > 0 ? round( $a / $n, 3 ) : 0.0;
		$home  = array(
			'same'  => 0,
			'shape' => 0,
			'new'   => 0,
		);
		foreach ( $secs as $s ) {
			++$home[ $s['from_home'] === '' ? 'new' : $s['from_home'] ];
		}
		$m = count( $secs );
		// The order the pages of this kind have on the sites whose Home is a design (else on all): the page nearest to all the
		// others, a real page and not an average; and, role by role, how often its words are the Home's, its build the Home's.
		$designed = array_values( array_filter( $ps, static fn( array $p ): bool => (bool) $p['design_home'] ) );
		$from     = count( $designed ) >= 3 ? $designed : $ps;
		$by_role  = array();
		foreach ( $designed as $p ) {
			foreach ( $p['sections'] as $s ) {
				$by_role[ $s['role'] ][ $s['from_home'] === '' ? 'new' : $s['from_home'] ] = ( $by_role[ $s['role'] ][ $s['from_home'] === '' ? 'new' : $s['from_home'] ] ?? 0 ) + 1;
			}
		}
		foreach ( $by_role as $role => $c ) {
			$all              = array_sum( $c );
			$by_role[ $role ] = array(
				'same'  => round( ( $c['same'] ?? 0 ) / $all, 3 ),
				'shape' => round( ( $c['shape'] ?? 0 ) / $all, 3 ),
				'new'   => round( ( $c['new'] ?? 0 ) / $all, 3 ),
				'n'     => $all,
			);
		}
		ksort( $by_role );

		return array(
			'recipe'       => self::medoid( array_map( static fn( array $p ): array => array_column( $p['sections'], 'role' ), $from ) ),
			'recipe_from'  => $from === $designed ? 'design' : 'all',
			'by_role'      => $by_role,
			'pages'        => $n,
			'sites'        => count( array_unique( array_column( $ps, 'site' ) ) ),
			'sections'     => self::stats( array_map( static fn( array $p ): int => count( $p['sections'] ), $ps ) ),
			'words'        => self::stats( array_column( $ps, 'words' ) ),
			'words_design' => self::stats( array_column( array_values( array_filter( $ps, static fn( array $p ): bool => (bool) $p['design_home'] ) ), 'words' ) ),
			'design'       => $share( count( array_filter( $ps, static fn( array $p ): bool => (bool) $p['design'] ) ) ),
			'roles'        => array_map( $share, $roles ),
			'orders'       => array_slice( $orders, 0, 6, true ),
			'last'         => array_map( $share, array_slice( $last, 0, 5, true ) ),
			'home'         => $m > 0 ? array_map( static fn( int $c ): float => round( $c / $m, 3 ), $home ) : $home,
			'faq'          => array(
				'questions' => self::stats( $faq_q ),
				'words'     => self::stats( $faq_w ),
			),
		);
	}

	/**
	 * The words of each role on a kind of page: on the sites with a designed Home when they have enough of it, else on all.
	 *
	 * @param array<int, array<string, mixed>> $ps
	 * @return array<string, array{words: int, n: int}>
	 */
	private static function budgets( array $ps ): array {
		$by = array();
		$dz = array();
		foreach ( $ps as $p ) {
			foreach ( $p['sections'] as $s ) {
				$by[ $s['role'] ][] = (int) $s['words'];
				if ( $p['design_home'] ) {
					$dz[ $s['role'] ][] = (int) $s['words'];
				}
			}
		}
		$out = array();
		foreach ( $by as $role => $w ) {
			$use          = count( $dz[ $role ] ?? array() ) >= 12 ? $dz[ $role ] : $w;
			$out[ $role ] = array(
				'words' => self::stats( $use )['median'],
				'n'     => count( $use ),
			);
		}
		ksort( $out );

		return $out;
	}

	/**
	 * On one site, of its pages of a kind: the share of sections that are the Home's build with new words, and the share
	 * that keep the Home's words — the research's table of the designed sites.
	 *
	 * @param array<string, mixed> $site
	 * @return array{pages: int, home_build: float, home_words: float, words: int}
	 */
	public static function home_shares( array $site, string $kind ): array {
		$ps    = array_values( array_filter( $site['pages'], static fn( array $p ): bool => $p['kind'] === $kind && $p['sections'] !== array() ) );
		$secs  = array_merge( array(), ...array_map( static fn( array $p ): array => $p['sections'], $ps ) );
		$n     = count( $secs );
		$count = static fn( string $v ): int => count( array_filter( $secs, static fn( array $s ): bool => $s['from_home'] === $v ) );

		return array(
			'pages'      => count( $ps ),
			'home_build' => $n > 0 ? round( $count( 'shape' ) / $n, 3 ) : 0.0,
			'home_words' => $n > 0 ? round( $count( 'same' ) / $n, 3 ) : 0.0,
			'words'      => $ps !== array() ? (int) round( array_sum( array_column( $ps, 'words' ) ) / count( $ps ) ) : 0,
		);
	}

	/**
	 * The set of pages: the share of sites with a page of each kind, and how many services and cities a site has.
	 *
	 * @param array<int, array<string, mixed>> $sites
	 * @return array<string, mixed>
	 */
	private static function set( array $sites ): array {
		$has = array();
		$svc = array();
		$loc = array();
		$n   = 0;
		foreach ( $sites as $site ) {
			if ( count( $site['pages'] ) < 3 ) {
				continue;
			}
			++$n;
			$kinds = array_count_values( array_column( $site['pages'], 'kind' ) );
			foreach ( array_keys( $kinds ) as $k ) {
				$has[ $k ] = ( $has[ $k ] ?? 0 ) + 1;
			}
			$svc[] = (int) ( $kinds['service'] ?? 0 );
			$loc[] = (int) ( $kinds['location'] ?? 0 );
		}
		arsort( $has );

		return array(
			'sites'     => $n,
			'kinds'     => array_map( static fn( int $c ): float => $n > 0 ? round( $c / $n, 3 ) : 0.0, $has ),
			'services'  => self::stats( $svc ),
			'locations' => self::stats( $loc ),
		);
	}

	/**
	 * The headings of service and city pages as formulas: the page's service, the company and the city put back as tokens,
	 * the words between them lowered. Kept when two sites or more use one.
	 *
	 * @param array<int, array<string, mixed>> $sites
	 * @return array<string, array<int, array{role: string, formula: string, pages: int, sites: int}>>
	 */
	public static function formulas( array $sites ): array {
		$pat = array();
		foreach ( $sites as $site ) {
			$tokens = self::site_tokens( $site );
			foreach ( $site['pages'] as $p ) {
				if ( ! in_array( $p['kind'], array( 'service', 'location' ), true ) || $p['sections'] === array() ) {
					continue;
				}
				$page = self::page_tokens( $p );
				$seen = array();
				foreach ( $p['sections'] as $s ) {
					$h = (string) $s['head'];
					if ( mb_strlen( $h ) < 8 ) {
						continue;
					}
					$f = self::tokenize( $h, $p['kind'] === 'service' ? $page['service'] : '', $tokens, $page['city'] );
					if ( ! str_contains( $f, '{' ) ) {
						continue;
					}
					$f   = (string) preg_replace_callback( '/(^|\})([^{]*)/', static fn( array $m ): string => $m[1] . mb_strtolower( $m[2] ), $f );
					$key = $p['kind'] . '|' . $s['role'] . '|' . $f;
					if ( isset( $seen[ $key ] ) ) {
						continue;
					}
					$seen[ $key ]                     = true;
					$pat[ $key ]['pages']             = ( $pat[ $key ]['pages'] ?? 0 ) + 1;
					$pat[ $key ]['sites'][ $site['key'] ] = true;
				}
			}
		}
		$out = array();
		foreach ( $pat as $key => $v ) {
			$on = count( $v['sites'] );
			if ( $on < 2 ) {
				continue;
			}
			list( $kind, $role, $formula ) = explode( '|', $key, 3 );
			$out[ $kind ][] = array(
				'role'    => $role,
				'formula' => $formula,
				'pages'   => (int) $v['pages'],
				'sites'   => $on,
			);
		}
		foreach ( $out as &$list ) {
			usort( $list, static fn( array $a, array $b ): int => $b['pages'] <=> $a['pages'] ?: $b['sites'] <=> $a['sites'] ?: strcmp( $a['formula'], $b['formula'] ) );
			$list = array_slice( $list, 0, 60 );
		}
		unset( $list );
		ksort( $out );

		return $out;
	}

	/**
	 * The questions of the FAQs of service pages, by family of service: the service, the company and the city put back as
	 * tokens. Kept when two sites or more ask one.
	 *
	 * @param array<int, array<string, mixed>> $sites
	 * @return array<string, array<int, array{q: string, pages: int, sites: int}>>
	 */
	public static function questions( array $sites ): array {
		$pat = array();
		foreach ( $sites as $site ) {
			$tokens = self::site_tokens( $site );
			foreach ( $site['pages'] as $p ) {
				if ( $p['kind'] !== 'service' ) {
					continue;
				}
				$page   = self::page_tokens( $p );
				$family = self::family( (string) $p['slug'], (string) $p['title'] );
				$family = $family === '' ? 'other' : $family;
				foreach ( $p['sections'] as $s ) {
					foreach ( (array) ( $s['asked'] ?? array() ) as $q ) {
						$t   = self::tokenize( (string) $q, $page['service'], $tokens, $page['city'] );
						$key = mb_strtolower( (string) preg_replace( '/[?.!\s]+$/u', '', $t ) );
						if ( $key === '' ) {
							continue;
						}
						$pat[ $family ][ $key ]['q']                 = $pat[ $family ][ $key ]['q'] ?? rtrim( $t );
						$pat[ $family ][ $key ]['pages']             = ( $pat[ $family ][ $key ]['pages'] ?? 0 ) + 1;
						$pat[ $family ][ $key ]['sites'][ $site['key'] ] = true;
					}
				}
			}
		}
		$out = array();
		foreach ( $pat as $family => $qs ) {
			foreach ( $qs as $v ) {
				$on = count( $v['sites'] );
				if ( $on < 2 ) {
					continue;
				}
				$out[ $family ][] = array(
					'q'     => (string) $v['q'],
					'pages' => (int) $v['pages'],
					'sites' => $on,
				);
			}
		}
		foreach ( $out as &$list ) {
			usort( $list, static fn( array $a, array $b ): int => $b['sites'] <=> $a['sites'] ?: $b['pages'] <=> $a['pages'] ?: strcmp( $a['q'], $b['q'] ) );
			$list = array_slice( $list, 0, 40 );
		}
		unset( $list );
		ksort( $out );

		return $out;
	}

	/**
	 * The company's name in a site's headings: as the site names itself, and its first two words.
	 *
	 * @param array<string, mixed> $site
	 * @return array<int, string>
	 */
	private static function site_tokens( array $site ): array {
		$co    = trim( (string) $site['name'] );
		$short = implode( ' ', array_slice( (array) preg_split( '/\s+/', $co ), 0, 2 ) );

		return array_values( array_filter( array_unique( array( $co, $short ) ), static fn( string $t ): bool => mb_strlen( $t ) > 3 ) );
	}

	/**
	 * A page's service (its title before "in …" and before a dash) and its city (after "in", or in its address).
	 *
	 * @param array<string, mixed> $p
	 * @return array{service: string, city: string}
	 */
	public static function page_tokens( array $p ): array {
		$title   = str_replace( array( '–', '—' ), '-', (string) $p['title'] );
		$service = trim( (string) preg_replace( '/\s*[-|].*$/', '', (string) preg_replace( '/\s+in\s+.*$/i', '', $title ) ) );
		$city    = preg_match( '/\bin\s+([A-Z][A-Za-z .]+?)(?:,\s*[A-Z]{2})?$/', $title, $m ) === 1
			? $m[1]
			: ( preg_match( '/^([a-z-]+?)-[a-z]{2}-restoration/', (string) $p['slug'], $s ) === 1 ? str_replace( '-', ' ', $s[1] ) : '' );

		return array(
			'service' => mb_strlen( $service ) > 3 ? $service : '',
			'city'    => mb_strlen( trim( $city ) ) > 2 ? trim( $city ) : '',
		);
	}

	/**
	 * A heading or a question with the service, the company and the city as tokens, numbers as N.
	 *
	 * @param array<int, string> $company
	 */
	public static function tokenize( string $text, string $service, array $company, string $city ): string {
		$q = static fn( string $s ): string => '/' . preg_quote( $s, '/' ) . '/iu';
		if ( $service !== '' ) {
			$text = (string) preg_replace( $q( $service ), '{Service}', $text );
		}
		foreach ( $company as $co ) {
			$text = (string) preg_replace( $q( $co ), '{Co}', $text );
		}
		if ( $city !== '' ) {
			$text = (string) preg_replace( $q( $city ), '{City}', $text );
		}
		$text = (string) preg_replace( '/\{City\},?\s*[A-Z]{2}\b/', '{City}', $text );
		$text = (string) preg_replace( '/\d[\d,+]*/', 'N', $text );

		return Page_Reader::squash( $text );
	}

	/**
	 * The roles a site keeps word for word on half its inner pages or more (at least three): on how many sites.
	 *
	 * @param array<int, array<string, mixed>> $sites
	 * @return array<string, int>
	 */
	private static function global_roles( array $sites ): array {
		$out = array();
		foreach ( $sites as $site ) {
			$ps = array_values( array_filter( $site['pages'], static fn( array $p ): bool => $p['kind'] !== 'home' && $p['sections'] !== array() ) );
			$by = array();
			foreach ( $ps as $p ) {
				foreach ( $p['sections'] as $s ) {
					if ( $s['words'] < 5 ) {
						continue;
					}
					$by[ $s['text_hash'] ]['n']    = ( $by[ $s['text_hash'] ]['n'] ?? 0 ) + 1;
					$by[ $s['text_hash'] ]['role'] = $s['role'];
				}
			}
			$roles = array();
			foreach ( $by as $v ) {
				if ( $v['n'] >= max( 3, count( $ps ) / 2 ) ) {
					$roles[ $v['role'] ] = true;
				}
			}
			foreach ( array_keys( $roles ) as $r ) {
				$out[ $r ] = ( $out[ $r ] ?? 0 ) + 1;
			}
		}
		arsort( $out );

		return $out;
	}

	/**
	 * Pages of one kind made from one page: the share of a site's pages whose build, section by section, is another
	 * page's (whole-page twins), for services and cities.
	 *
	 * @param array<int, array<string, mixed>> $sites
	 * @return array<string, array<string, array{pages: int, twins: int, shared: int}>>
	 */
	public static function twins( array $sites ): array {
		$out = array();
		foreach ( $sites as $site ) {
			foreach ( array( 'service', 'location' ) as $kind ) {
				$ps = array_values( array_filter( $site['pages'], static fn( array $p ): bool => $p['kind'] === $kind && $p['sections'] !== array() ) );
				if ( count( $ps ) < 2 ) {
					continue;
				}
				$sig    = static fn( array $p ): string => implode( '>', array_column( $p['sections'], 'shape_hash' ) );
				$counts = array_count_values( array_map( $sig, $ps ) );
				$twins  = count( array_filter( $ps, static fn( array $p ): bool => $counts[ $sig( $p ) ] > 1 ) );
				$shared = array();
				foreach ( $ps as $i => $p ) {
					$others = array();
					foreach ( $ps as $j => $o ) {
						if ( $j !== $i ) {
							foreach ( $o['sections'] as $s ) {
								$others[ $s['shape_hash'] ] = true;
							}
						}
					}
					$hit      = count( array_filter( $p['sections'], static fn( array $s ): bool => isset( $others[ $s['shape_hash'] ] ) ) );
					$shared[] = (int) round( 100 * $hit / count( $p['sections'] ) );
				}
				$out[ $site['key'] ][ $kind ] = array(
					'pages'  => count( $ps ),
					'twins'  => $twins,
					'shared' => (int) round( array_sum( $shared ) / count( $shared ) ),
				);
			}
		}

		return $out;
	}

	/**
	 * Of some orders of sections, the one nearest to all the others (the fewest sections added, left out or changed to turn
	 * it into each of them); the first such one when two are as near.
	 *
	 * @param array<int, array<int, string>> $orders
	 * @return array<int, string>
	 */
	public static function medoid( array $orders ): array {
		$orders = array_values( array_filter( $orders, static fn( array $o ): bool => count( $o ) > 1 ) );
		$best   = array();
		$score  = PHP_INT_MAX;
		foreach ( $orders as $i => $a ) {
			$sum = 0;
			foreach ( $orders as $j => $b ) {
				if ( $i !== $j ) {
					$sum += self::distance( $a, $b );
					if ( $sum >= $score ) {
						break;
					}
				}
			}
			if ( $sum < $score ) {
				$score = $sum;
				$best  = $a;
			}
		}

		return $best;
	}

	/**
	 * How many sections are added, left out or changed to turn one order into another.
	 *
	 * @param array<int, string> $a
	 * @param array<int, string> $b
	 */
	public static function distance( array $a, array $b ): int {
		$prev = range( 0, count( $b ) );
		foreach ( $a as $i => $x ) {
			$cur = array( $i + 1 );
			foreach ( $b as $j => $y ) {
				$cur[] = min( $prev[ $j + 1 ] + 1, $cur[ $j ] + 1, $prev[ $j ] + ( $x === $y ? 0 : 1 ) );
			}
			$prev = $cur;
		}

		return (int) end( $prev );
	}

	/**
	 * The order of the sections of a new page of a kind as the network builds it, in the roles a page of the plugin is made of
	 * (Pages\Section_Roles), and the roles whose words a site keeps as its Home says them; null when nothing is learned.
	 *
	 * @return array{roles: array<int, string>, keep: array<int, string>, from: string}|null
	 */
	public static function recipe( string $kind ): ?array {
		$model = self::get();
		$k     = is_array( $model ) ? ( $model['kinds'][ $kind ] ?? null ) : null;
		if ( ! is_array( $k ) || empty( $k['recipe'] ) ) {
			return null;
		}
		// The research's roles in the plugin's: a map is the place's areas; a video or a section of no kind is left out.
		$map   = array(
			'map'   => 'areas',
			'video' => '',
			'other' => '',
		);
		$roles = array();
		foreach ( (array) $k['recipe'] as $role ) {
			$r = array_key_exists( (string) $role, $map ) ? $map[ (string) $role ] : (string) $role;
			if ( $r !== '' && ( $roles === array() || end( $roles ) !== $r ) ) {
				$roles[] = $r;
			}
		}
		$keep = array();
		foreach ( (array) ( $k['by_role'] ?? array() ) as $role => $v ) {
			if ( (float) $v['same'] >= 0.5 && (int) $v['n'] >= 5 ) {
				$keep[] = (string) $role;
			}
		}

		return array(
			'roles' => $roles,
			'keep'  => $keep,
			'from'  => (string) ( $k['recipe_from'] ?? '' ),
		);
	}

	/**
	 * Numbers as the research gave them: the median is the upper middle one.
	 *
	 * @param array<int, int|float> $values
	 * @return array{n: int, min: int, p25: int, median: int, p75: int, max: int}
	 */
	public static function stats( array $values ): array {
		$v = array_values( array_map( 'intval', $values ) );
		sort( $v );
		$n  = count( $v );
		$at = static fn( float $f ): int => $n > 0 ? $v[ min( $n - 1, (int) floor( $n * $f ) ) ] : 0;

		return array(
			'n'      => $n,
			'min'    => $n > 0 ? $v[0] : 0,
			'p25'    => $at( 0.25 ),
			'median' => $at( 0.5 ),
			'p75'    => $at( 0.75 ),
			'max'    => $n > 0 ? $v[ $n - 1 ] : 0,
		);
	}

	/** The model the plugin ships, learned from the team's sites (bin/build-network-model.php). */
	public const SHIPPED = 'data/network-model.json';

	/** @var array<string, mixed>|false|null The shipped model once read (false: there is none). */
	private static $shipped = null;

	/**
	 * The model learned on this site's network (`wp dxai-ui network learn`), or null when it has not been read.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function stored(): ?array {
		$m = is_multisite() ? get_site_option( self::OPTION ) : get_option( self::OPTION );

		return is_array( $m ) && (int) ( $m['version'] ?? 0 ) === self::VERSION ? $m : null;
	}

	/**
	 * The model to work with: the one learned on this network, else the one the plugin ships; null when neither is there.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get(): ?array {
		$m = self::stored();
		if ( $m !== null ) {
			return $m;
		}
		if ( self::$shipped === null ) {
			$file          = DXAI_UI_DIR . self::SHIPPED;
			$data          = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			self::$shipped = is_array( $data ) && (int) ( $data['version'] ?? 0 ) === self::VERSION ? $data : false;
		}

		return is_array( self::$shipped ) ? self::$shipped : null;
	}

	/**
	 * The model as the plugin can ship it in a public repository: what the sites have in common and nothing that names one —
	 * no addresses, no site by site numbers, and only the formulas and questions three sites or more use.
	 *
	 * @param array<string, mixed> $model
	 * @return array<string, mixed>
	 */
	public static function shareable( array $model ): array {
		$out = $model;
		unset( $out['sources'], $out['twins'] );
		$out['sites'] = count( (array) ( $model['sources'] ?? array() ) );
		foreach ( array( 'formulas', 'questions' ) as $key ) {
			foreach ( (array) ( $out[ $key ] ?? array() ) as $group => $list ) {
				$out[ $key ][ $group ] = array_values( array_filter( (array) $list, static fn( array $f ): bool => (int) $f['sites'] >= 3 ) );
				if ( $out[ $key ][ $group ] === array() ) {
					unset( $out[ $key ][ $group ] );
				}
			}
		}

		return $out;
	}

	/**
	 * Keep the model.
	 *
	 * @param array<string, mixed> $model
	 */
	public static function store( array $model ): void {
		if ( is_multisite() ) {
			update_site_option( self::OPTION, $model );
			return;
		}
		update_option( self::OPTION, $model, false );
	}
}

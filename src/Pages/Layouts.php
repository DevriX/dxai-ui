<?php
/**
 * The team's layouts on a page of a design: what they are filled with.
 *
 * @package DXAI_UI\Pages
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

/**
 * A layout (bin/build-layouts.php: a section of one of the team's pages with its words, pictures and links taken out) is a section
 * of the library (Block_Library) whose slots are filled here:
 *
 *  - the lists: what a Home says that a layout repeats — its steps, its reviews, its questions, its trust logos, its figures, its
 *    points, the other pages of the site, its places and services — read once from the Home's own sections (lists());
 *  - the pictures: the Home's photographs, by the page's topic first, so a service page shows the Home's picture of that service
 *    where the Home has one (page_facts());
 *  - the words: a heading, a paragraph, a label or a button where the team's own words were — provisional words made of what the
 *    Home states (its name, its phone, its places, what it says about the service), never a claim of their own, until the words are
 *    written for the page (Copy_Writer: the team's prompt, with an AI) (words()).
 *
 * Nothing here draws anything: the layout is the team's, the colours the theme's names for them, the words and pictures the Home's.
 */
final class Layouts {

	/** How many items a list shows on a page, so a layout keeps its shape (three reviews, not eleven). */
	public const MAX = array( 'steps' => 5, 'reviews' => 3, 'faq' => 6, 'logos' => 6, 'stats' => 4, 'items' => 6, 'related' => 3, 'places' => 8, 'services' => 6 );

	/** @var array<string, int> The Home's pictures a page shows so far, by page (pictured()). */
	private static array $pictures = array();

	/** @var array<string, array<string, int>> The rows of each list a page shows so far, by page (shown()). */
	private static array $shown = array();

	/** @var array<string, array<string, bool>> The lines of words a page says so far, by page (said()). */
	private static array $said = array();

	/** The leaf blocks that hold words. */
	private const TEXT = array( 'core/heading', 'core/paragraph', 'core/list-item', 'dxai-ui/text', 'dxai-ui/html', 'core/button', 'core/details' );

	/** Words that are a mark, not a sentence (a row of stars, a tick). */
	private const MARKS = '/^[★☆✔✓•·\-\s]+$/u';

	/**
	 * The lists a Home gives the layouts, from its own sections (Section_Library::for_page()), by what each section is.
	 *
	 * @param array<int, array<string, mixed>> $library
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function lists( int $home, array $library ): array {
		$out = array( 'steps' => array(), 'reviews' => array(), 'faq' => array(), 'logos' => array(), 'stats' => array(), 'items' => array() );
		foreach ( array_values( $library ) as $i => $component ) {
			$block = (array) $component['block'];
			$role  = Section_Roles::of( $block, $i === 0 );
			$rows  = self::rows( $component );
			// Cards numbered one, two, three… in order (or under a heading that says it is the process) are the steps of the work,
			// whatever the section is drawn as.
			if ( ! in_array( $role, array( 'hero', 'faq', 'reviews', 'process', 'form' ), true ) && self::numbered( $rows, $block ) ) {
				$role = 'process';
			}
			// Headed lines ("✓ On site in about 60 minutes. When water is spreading…" with the head in bold): points with their words,
			// wherever the Home says them in one block.
			if ( in_array( $role, array( 'two-col', 'content', 'cards', 'text', 'trust' ), true ) ) {
				foreach ( self::headed_lines( $block ) as $r ) {
					$out['items'][] = $r + array( 'meta' => '', 'url' => '', 'icon' => self::icon_for( (string) $r['title'] ) );
				}
			}
			switch ( $role ) {
				case 'process':
					foreach ( $rows as $n => $r ) {
						$r['eyebrow']   = (string) ( $r['eyebrow'] ?? '' ) !== '' ? (string) $r['eyebrow'] : sprintf( /* translators: %d: the number of the step. */ __( 'Step %d', 'dxai-ui' ), $n + 1 );
						$r['icon']      = 'check';
						$out['steps'][] = $r;
					}
					break;
				case 'reviews':
					foreach ( $rows as $r ) {
						// A review: the longest words are what was said, the shortest who said it — unless the shortest is where they are
						// ("Olympia, WA"): then the name is the next shortest, and the place goes with it.
						$texts = array_values( array_filter( array( $r['title'], $r['text'], $r['meta'] ), static fn( $t ) => $t !== '' ) );
						usort( $texts, static fn( $a, $b ) => mb_strlen( $b ) <=> mb_strlen( $a ) );
						$said  = (string) ( $texts[0] ?? '' );
						$short = array_slice( $texts, 1 );
						$who   = '';
						foreach ( array_reverse( $short ) as $t ) {
							if ( ! Team_Pages::is_place( $t ) && preg_match( '/,\s*[A-Z]{2}$/', $t ) !== 1 ) {
								$who = $t;
								break;
							}
						}
						if ( $who === '' ) {
							$who = (string) ( $short[ count( $short ) - 1 ] ?? __( 'A customer', 'dxai-ui' ) );
						}
						$rest = array_values( array_filter( $short, static fn( $t ) => $t !== $who ) );
						$out['reviews'][] = array( 'title' => $who, 'text' => $said, 'meta' => (string) ( $rest[ count( $rest ) - 1 ] ?? '' ), 'url' => '', 'icon' => 'star' ) + $r;
					}
					break;
				case 'faq':
					foreach ( $rows as $r ) {
						$q = str_ends_with( $r['title'], '?' ) ? $r['title'] : ( str_ends_with( $r['text'], '?' ) ? $r['text'] : $r['title'] );
						$a = $q === $r['title'] ? $r['text'] : $r['title'];
						if ( $q !== '' && $a !== '' ) {
							$out['faq'][] = array( 'title' => $q, 'text' => $a, 'meta' => '', 'url' => '', 'icon' => 'chevron' );
						}
					}
					break;
				case 'two-col':
				case 'content':
					// What the Home says about itself in headed lines ("Veteran founded — …"): points with their words.
					foreach ( $rows as $r ) {
						if ( (string) $r['title'] !== '' && (string) $r['text'] !== '' && str_word_count( (string) $r['title'] ) <= 10 ) {
							$out['items'][] = array( 'title' => (string) $r['title'], 'text' => (string) $r['text'], 'meta' => '', 'url' => '', 'icon' => self::icon_for( (string) $r['title'] ) );
						}
					}
					break;
				case 'trust':
					foreach ( self::images( $block, (array) $component['images'] ) as $img ) {
						// A logo, not a drawing: the plugin's own icon files and anything tiny are not what a trust row shows.
						if ( str_contains( $img['url'], '/dxai-ui/icons/' ) || preg_match( '/\.svg(\?|$)/i', $img['url'] ) === 1 ) {
							continue;
						}
						$meta = $img['id'] > 0 ? wp_get_attachment_metadata( $img['id'] ) : null;
						if ( is_array( $meta ) && (int) ( $meta['width'] ?? 0 ) > 0 && (int) $meta['width'] < 100 ) {
							continue;
						}
						$out['logos'][] = array( 'title' => $img['alt'] !== '' ? $img['alt'] : __( 'Logo', 'dxai-ui' ), 'text' => '', 'url' => '', 'image_url' => $img['url'], 'image_id' => (string) $img['id'], 'image_alt' => $img['alt'], 'icon' => 'award' );
					}
					break;
				default:
					break;
			}
			// The figures and the points: wherever the Home states them.
			foreach ( self::figures( $block ) as $f ) {
				$out['stats'][] = $f;
			}
			if ( in_array( $role, array( 'hero', 'trust', 'two-col', 'cards', 'cta' ), true ) ) {
				foreach ( self::points( $block ) as $p ) {
					$out['items'][] = array( 'title' => $p, 'text' => '', 'meta' => '', 'url' => '', 'icon' => self::icon_for( $p ) );
				}
			}
		}
		// The points with words of their own first: a card with a slot for a row's words shows those before the bare ones.
		usort( $out['items'], static fn( array $a, array $b ): int => ( (string) ( $b['text'] ?? '' ) !== '' ) <=> ( (string) ( $a['text'] ?? '' ) !== '' ) );
		foreach ( $out as $name => $rows ) {
			$seen = array();
			$out[ $name ] = array_values( array_filter( $rows, static function ( array $r ) use ( &$seen ): bool {
				// A row is told apart by its title and its words (three reviews may be signed alike); a placeholder the design left for
				// someone to fill in ("Paste a verbatim review here", "Reviewer Name", lorem ipsum) is not what the Home says.
				$k = mb_strtolower( (string) $r['title'] . '|' . (string) ( $r['text'] ?? '' ) );
				if ( (string) $r['title'] === '' || isset( $seen[ $k ] ) || self::placeholder( (string) $r['title'] . ' ' . (string) ( $r['text'] ?? '' ) ) ) {
					return false;
				}
				$seen[ $k ] = true;

				return true;
			} ) );
		}

		return $out;
	}

	/**
	 * The facts of one place on one page: the role the layout plays, the page's words, the pictures it may show, its lists cut to size.
	 *
	 * @param array<string, mixed> $lib  Block_Library::facts() of the run, with `lists` (lists()).
	 * @param array<string, mixed> $item The page being made (type, title, slug).
	 * @param array<string, mixed> $vary What the page is made with: `pool` (the Home's pictures), `rows` (the other pages), `kit`.
	 * @return array<string, mixed>
	 */
	public static function page_facts( array $lib, array $item, string $role, string $seed, array $vary ): array {
		$title = (string) ( $item['title'] ?? '' );
		$facts = $lib;
		$facts['role']  = $role;
		$facts['title'] = $title;
		$facts['kind']  = (string) ( $item['type'] ?? $facts['kind'] ?? '' );
		$facts['topic'] = implode( ' ', self::topic( $title ) );
		// What the words are about: the service, for a service page and for a place's page ("Water Damage in Tyler, TX" is about water
		// damage, in Tyler); the company's trade for the pages about the company (About, Contact, the questions, the reviews).
		$subject = $title;
		$region  = '';
		$ph      = (array) ( $lib['places_home'] ?? array() );
		$first   = reset( $ph );
		if ( $first !== false ) {
			$region = is_array( $first ) ? (string) ( $first['title'] ?? '' ) : (string) $first;
		}
		if ( $facts['kind'] === 'location' && preg_match( '/^(.*\S)\s+in\s+(.+\S)\s*$/iu', $title, $m ) === 1 && Team_Pages::is_place( $m[2] ) ) {
			$subject = $m[1];
			$region  = $m[2];
		} elseif ( $facts['kind'] === 'location' && $title !== '' && preg_match( '/\sin\s/iu', $title ) !== 1 ) {
			// A place's own page ("Anchorage, AK", "Wasilla & Mat-Su Valley"): the company's trade, in that place. Its title is no service:
			// "Taylored Restoration handles anchorage, ak for homes and businesses in Anchorage, AK" (the team's network, 2026-10-16).
			$subject = __( 'restoration', 'dxai-ui' );
			$region  = $title;
		} elseif ( ! in_array( $facts['kind'], array( 'service', 'location', '' ), true ) ) {
			$subject = __( 'restoration', 'dxai-ui' );
		}
		$facts['subject'] = $subject;
		// Where on the page this section is (its seed): two sections of one role start their words at different lines.
		$facts['slot_seed'] = (string) sprintf( '%u', crc32( $seed ) );
		$texts          = (array) ( $vary['kit']['texts'] ?? array() );
		$facts['about'] = (string) ( $texts[ mb_strtolower( $title ) ] ?? '' );
		// The region: the Home's first place; else the page's first place when it is one (a page's title is not a place).
		$places = (array) ( $facts['places'] ?? array() );
		if ( $region === '' && Team_Pages::is_place( (string) ( $places[0]['title'] ?? '' ) ) ) {
			$region = (string) $places[0]['title'];
		}
		$facts['region'] = $region !== '' ? $region : (string) ( $facts['region'] ?? '' );
		if ( (string) ( $facts['contact_url'] ?? '' ) === '' ) {
			$facts['contact_url'] = (string) ( $facts['phone_url'] ?? '' ) !== '' ? (string) $facts['phone_url'] : (string) ( $facts['site_url'] ?? home_url( '/' ) );
		}
		$lists = (array) ( $lib['lists'] ?? array() );
		// A related page the Home says nothing about (the page of all services, the page about the company) gets a line of its own, so
		// its card is not a title in an empty box.
		$short_name       = (string) ( $lib['company_short'] ?? $lib['company'] ?? '' );
		$lists['related'] = array_values( array_map( static function ( $r ) use ( $short_name, $region ): array {
			$t    = (string) ( $r['title'] ?? '' );
			$text = (string) ( $r['text'] ?? '' );
			if ( trim( $text ) === '' && $t !== '' ) {
				$text = $region !== ''
					/* translators: 1: a page's title, 2: the company's name, 3: a place. */
					? sprintf( __( '%1$s from %2$s, for homes and businesses in %3$s.', 'dxai-ui' ), $t, $short_name !== '' ? $short_name : __( 'our team', 'dxai-ui' ), $region )
					/* translators: 1: a page's title, 2: the company's name. */
					: sprintf( __( '%1$s from %2$s, for homes and businesses.', 'dxai-ui' ), $t, $short_name !== '' ? $short_name : __( 'our team', 'dxai-ui' ) );
			}

			return array( 'title' => $t, 'text' => $text, 'url' => (string) ( $r['url'] ?? '' ), 'icon' => self::icon_for( $t ) );
		}, (array) ( $vary['rows'] ?? array() ) ) );
		// The page of questions shows every question the Home has; any other page a few.
		$caps = self::MAX;
		if ( $facts['kind'] === 'faq' ) {
			$caps['faq'] = 12;
		}
		foreach ( $lists as $name => $rows ) {
			$rows = array_values( (array) $rows );
			// Every row leads somewhere a card can go: the row's own page, else the page's call.
			foreach ( $rows as &$r ) {
				if ( (string) ( $r['url'] ?? '' ) === '' ) {
					$r['url'] = (string) $facts['contact_url'];
				}
				if ( ! isset( $r['icon'] ) ) {
					$r['icon'] = self::icon_for( (string) ( $r['title'] ?? '' ) );
				}
			}
			unset( $r );
			// From the first row the page has not shown yet.
			$lists[ $name ] = array_slice( $rows, self::shown( $facts, (string) $name ), $caps[ $name ] ?? 6 );
		}
		$facts['lists'] = $lists;
		// A page made from a Home says a line once (said()).
		$facts['once'] = true;
		// The pictures: the Home's, the ones about the page's topic first, and not the same one at every place of the page.
		$pool  = array_values( (array) ( $vary['pool'] ?? array() ) );
		$topic = self::topic( $title );
		$score = static function ( array $p ) use ( $topic ): int {
			$hay = mb_strtolower( (string) $p['alt'] . ' ' . basename( (string) $p['url'] ) );
			$n   = 0;
			foreach ( $topic as $w ) {
				$n += str_contains( $hay, $w ) ? 1 : 0;
			}

			return $n;
		};
		usort( $pool, static fn( $a, $b ) => $score( $b ) <=> $score( $a ) ?: (int) $a['id'] <=> (int) $b['id'] );
		$start = $pool === array() ? 0 : (int) ( sprintf( '%u', crc32( $seed . '|pictures' ) ) % count( $pool ) );
		$best  = $pool !== array() && $score( $pool[0] ) > 0;
		// The Home's pictures go to four sections of a page at most (the hero's is the Home's own): the rest say it in words and drawings.
		if ( self::pictured( $facts ) >= 4 ) {
			$pool = array();
		}
		foreach ( array( 1, 2, 3 ) as $n ) {
			unset( $facts[ 'image_url:' . $n ], $facts[ 'image_id:' . $n ], $facts[ 'image_alt:' . $n ], $facts[ 'image_w:' . $n ], $facts[ 'image_h:' . $n ] );
		}
		// The drawings in the colour the site's primary is (a drawing shown as a picture takes no colour from the page).
		$facts['icon_color'] = self::icon_color();
		for ( $n = 1; $n <= 3; $n++ ) {
			$pick = $pool === array() ? null : $pool[ ( ( $best && $n === 1 ) ? 0 : ( $start + $n ) % count( $pool ) ) ];
			if ( $pick === null ) {
				break;
			}
			$meta = wp_get_attachment_metadata( (int) $pick['id'] );
			$facts[ 'image_url:' . $n ] = (string) $pick['url'];
			$facts[ 'image_id:' . $n ]  = (string) (int) $pick['id'];
			$facts[ 'image_alt:' . $n ] = (string) $pick['alt'];
			$facts[ 'image_w:' . $n ]   = (string) (int) ( $meta['width'] ?? 0 );
			$facts[ 'image_h:' . $n ]   = (string) (int) ( $meta['height'] ?? 0 );
		}

		return $facts;
	}

	/**
	 * Provisional words for a slot of a layout: what the Home states, in a sentence that fits the place. `$slot` is the slot's kind
	 * and length (`h2-s`, `p-m`, `li-s`, `label-s`, `button`, `call`).
	 *
	 * @param array<string, mixed>      $facts page_facts().
	 * @param array<string, mixed>|null $row   The row a list copy is made for.
	 */
	/**
	 * How many sections of a page show one of the Home's pictures so far (counted as they are made: Block_Library::fill), and one more.
	 *
	 * @param array<string, mixed> $facts
	 */
	public static function pictured( array $facts, int $more = 0 ): int {
		$page = self::page_key( $facts );
		self::$pictures[ $page ] = ( self::$pictures[ $page ] ?? 0 ) + $more;

		return self::$pictures[ $page ];
	}

	/**
	 * How many rows of a list the sections of a page have shown so far (counted as they are made: Block_Library::fill), and more. The
	 * next section that shows the list goes on from there (two sections of a page that list the Home's points do not show the same ones).
	 *
	 * @param array<string, mixed> $facts
	 */
	public static function shown( array $facts, string $list, int $more = 0 ): int {
		$page = self::page_key( $facts );
		self::$shown[ $page ][ $list ] = ( self::$shown[ $page ][ $list ] ?? 0 ) + $more;

		return self::$shown[ $page ][ $list ];
	}

	/** A page begins: none of the Home's pictures, none of its lists' rows and none of its lines shown on it yet. */
	public static function begin( string $title, string $kind ): void {
		$head = $title . '|';
		foreach ( array_keys( self::$pictures + self::$shown + self::$said ) as $key ) {
			if ( str_starts_with( (string) $key, $head ) && str_ends_with( (string) $key, '|' . $kind ) ) {
				unset( self::$pictures[ $key ], self::$shown[ $key ], self::$said[ $key ] );
			}
		}
	}

	/**
	 * Whether a page has said a line already (a heading, a label, a paragraph: compared as words, case and spacing aside); `$record`
	 * notes it as said. A page made from a Home (page_facts() marks its facts `once`) says a line once: two sections do not open with
	 * the same heading, and a call to action of the library does not repeat the Home's.
	 *
	 * @param array<string, mixed> $facts
	 */
	public static function said( array $facts, string $line, bool $record = true ): bool {
		$k = mb_strtolower( trim( (string) preg_replace( '/[\s\p{P}]+/u', ' ', html_entity_decode( wp_strip_all_tags( $line ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) );
		if ( $k === '' || mb_strlen( $k ) < 6 ) {
			return false;
		}
		$page = self::page_key( $facts );
		$was  = isset( self::$said[ $page ][ $k ] );
		if ( $record ) {
			self::$said[ $page ][ $k ] = true;
		}

		return $was;
	}

	/**
	 * The lines of a section the page shows as the Home has it (its headings, labels and paragraphs), noted as said.
	 *
	 * @param array<string, mixed> $facts
	 * @param array<string, mixed> $block
	 */
	public static function hear( array $facts, array $block ): void {
		if ( in_array( (string) ( $block['blockName'] ?? '' ), array( 'core/heading', 'core/paragraph', 'dxai-ui/text' ), true ) || \DXAI_UI\Theme\Capabilities::is_block( (string) ( $block['blockName'] ?? '' ), 'span' ) ) {
			self::said( $facts, Block_Tree::own_text( $block ) );
		}
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $c ) {
			if ( is_array( $c ) ) {
				self::hear( $facts, $c );
			}
		}
	}

	/** @param array<string, mixed> $facts */
	private static function page_key( array $facts ): string {
		return (string) ( $facts['title'] ?? '' ) . '|' . (string) ( $facts['home'] ?? '' ) . '|' . (string) ( $facts['kind'] ?? '' );
	}

	/** The site's primary colour as a hex, for the drawings; empty when the palette says it another way (oklch, a variable). */
	public static function icon_color(): string {
		$settings = wp_get_global_settings();
		foreach ( array( 'theme', 'custom', 'default' ) as $origin ) {
			foreach ( (array) ( $settings['color']['palette'][ $origin ] ?? array() ) as $c ) {
				if ( (string) ( $c['slug'] ?? '' ) === 'primary' && preg_match( '/^#[0-9a-f]{3,8}$/i', (string) ( $c['color'] ?? '' ) ) === 1 ) {
					return (string) $c['color'];
				}
			}
		}

		return '';
	}

	public static function words( string $slot, array $facts, ?array $row ): string {
		[ $kind, $len ] = array_pad( explode( '-', $slot, 2 ), 2, 'm' );
		$role    = (string) ( $facts['role'] ?? '' );
		$company = (string) ( $facts['company'] ?? '' );
		$short   = (string) ( $facts['company_short'] ?? $company );
		$phone   = (string) ( $facts['phone'] ?? '' );
		$title   = (string) ( $facts['title'] ?? '' );
		$region  = (string) ( $facts['region'] ?? '' );
		$about   = trim( (string) ( $facts['about'] ?? '' ) );
		$topic   = (string) ( $facts['subject'] ?? '' );
		if ( $topic === '' ) {
			$topic = $title !== '' ? $title : __( 'restoration', 'dxai-ui' );
		}
		// Headings and labels are counted by their role (two headings of one role differ); paragraphs and points by the page (they rotate through the page).
		$nth     = (int) ( (int) ( $facts['slot_seed'] ?? 0 ) % 5 ) + self::nth( $facts, (string) ( $facts['slot_seed'] ?? '' ) . ( in_array( $kind, array( 'p', 'li' ), true ) ? $kind : $kind . $role ) );
		$pick    = static fn( array $lines ): string => $lines[ $nth % count( $lines ) ];
		$where   = $region !== '' ? sprintf( /* translators: %s: a place. */ __( ' in %s', 'dxai-ui' ), $region ) : '';
		switch ( $kind ) {
			case 'call':
				return $phone !== '' ? sprintf( /* translators: %s: a phone number. */ __( 'Call %s', 'dxai-ui' ), $phone ) : __( 'Call us', 'dxai-ui' );
			case 'button':
				return $pick( match ( $role ) {
					'cta'     => array( __( 'Get help now', 'dxai-ui' ), __( 'Request a callback', 'dxai-ui' ) ),
					'related' => array( __( 'See all services', 'dxai-ui' ) ),
					'areas'   => array( __( 'Find your area', 'dxai-ui' ) ),
					default   => array( __( 'Contact us', 'dxai-ui' ), __( 'Get in touch', 'dxai-ui' ) ),
				} );
			case 'label':
				return $pick( match ( $role ) {
					'process' => array( __( 'How it works', 'dxai-ui' ), __( 'What to expect', 'dxai-ui' ) ),
					'two-col' => array( __( 'Why choose us', 'dxai-ui' ), __( 'Our promise', 'dxai-ui' ) ),
					'cards'   => array( __( 'What we do', 'dxai-ui' ), __( 'What sets us apart', 'dxai-ui' ) ),
					'reviews' => array( __( 'Reviews', 'dxai-ui' ) ),
					'faq'     => array( __( 'Questions', 'dxai-ui' ) ),
					'cta'     => array( __( 'Here to help', 'dxai-ui' ) ),
					'trust'   => array( __( 'Trusted', 'dxai-ui' ) ),
					'areas'   => array( __( 'Where we work', 'dxai-ui' ) ),
					'related' => array( __( 'More services', 'dxai-ui' ) ),
					default   => array( __( 'Overview', 'dxai-ui' ) ),
				} );
			case 'h1':
			case 'h2':
				return $pick( match ( $role ) {
					'process' => array( sprintf( /* translators: %s: the page's topic. */ __( 'How %s work moves forward', 'dxai-ui' ), mb_strtolower( $topic ) ), sprintf( /* translators: %s: the page's topic. */ __( 'What to expect from %s', 'dxai-ui' ), mb_strtolower( $topic ) ), __( 'From the first call to the last walk-through', 'dxai-ui' ) ),
					'two-col' => array( sprintf( /* translators: 1: a place, 2: the company. */ __( 'Why property owners%1$s choose %2$s', 'dxai-ui' ), $where, $short ), sprintf( /* translators: %s: the company. */ __( 'What sets %s apart', 'dxai-ui' ), $short ), sprintf( /* translators: %s: the page's topic. */ __( 'The %s work, done the way it should be', 'dxai-ui' ), mb_strtolower( $topic ) ), __( 'Help with the claim, not just the damage', 'dxai-ui' ) ),
					'cards'   => array( sprintf( /* translators: %s: the page's topic. */ __( 'What %s includes', 'dxai-ui' ), mb_strtolower( $topic ) ), sprintf( /* translators: %s: the company. */ __( 'What %s brings to the job', 'dxai-ui' ), $short ), sprintf( /* translators: %s: the page's topic. */ __( 'Signs you need %s help', 'dxai-ui' ), mb_strtolower( $topic ) ), __( 'What our customers count on', 'dxai-ui' ) ),
					'reviews' => array( sprintf( /* translators: 1: a place, 2: the company. */ __( 'Property owners%1$s trust %2$s', 'dxai-ui' ), $where, $short ) ),
					'faq'     => array( sprintf( /* translators: %s: the page's topic. */ __( '%s questions, answered', 'dxai-ui' ), mb_strtoupper( mb_substr( $topic, 0, 1 ) ) . mb_substr( $topic, 1 ) ) ),
					'cta'     => array( sprintf( /* translators: %s: the page's topic. */ __( 'Need %s help now?', 'dxai-ui' ), mb_strtolower( $topic ) ), __( 'Talk to a real person', 'dxai-ui' ) ),
					'trust'   => array( __( 'Trusted in the restoration industry', 'dxai-ui' ) ),
					'areas'   => array( $region !== '' ? sprintf( /* translators: %s: a place. */ __( 'Serving %s and the surrounding area', 'dxai-ui' ), $region ) : __( 'Where we work', 'dxai-ui' ) ),
					'related' => array( __( 'Related services', 'dxai-ui' ) ),
					default   => array( sprintf( /* translators: 1: the page's topic, 2: the company. */ __( '%1$s with %2$s', 'dxai-ui' ), $topic, $short ) ),
				} );
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				return $pick( array( __( 'What to expect', 'dxai-ui' ), __( 'Our promise', 'dxai-ui' ), __( 'Fast response', 'dxai-ui' ), __( 'Clear communication', 'dxai-ui' ) ) );
			case 'li':
				$items = (array) ( $facts['lists']['items'] ?? array() );
				if ( $items !== array() ) {
					return (string) $items[ $nth % count( $items ) ]['title'];
				}

				return $pick( array( sprintf( /* translators: %s: the page's topic. */ __( 'Assessment of the %s', 'dxai-ui' ), mb_strtolower( $topic ) ), __( 'A written plan before work starts', 'dxai-ui' ), __( 'Documentation for your claim', 'dxai-ui' ), __( 'One crew from start to finish', 'dxai-ui' ) ) );
			default:
				$lines = array();
				if ( $about !== '' ) {
					$lines[] = $about;
				}
				$lines[] = sprintf( /* translators: 1: the company, 2: the page's topic, 3: a place. */ __( '%1$s handles %2$s for homes and businesses%3$s.', 'dxai-ui' ), $company, mb_strtolower( $topic ), $where );
				if ( $phone !== '' ) {
					$lines[] = sprintf( /* translators: 1: a phone number, 2: the company. */ __( 'Call %1$s to talk to the %2$s team about what you are seeing.', 'dxai-ui' ), $phone, $short );
				}
				$lines[] = __( 'You will know what is being done, why, and what comes next, before anything starts.', 'dxai-ui' );
				$lines[] = sprintf( /* translators: %s: the page's topic. */ __( 'The scope of the %s work is written down and agreed before it begins, and the site is walked with you when it ends.', 'dxai-ui' ), mb_strtolower( $topic ) );
				$lines[] = sprintf( /* translators: %s: the company. */ __( 'One %s crew stays with the job from the first visit to the last, so nothing is lost between hands.', 'dxai-ui' ), $short );
				$lines[] = __( 'What was found, what was done and what it cost are documented for you and for anyone else who needs to see it.', 'dxai-ui' );
				$take    = $len === 's' ? 1 : ( $len === 'm' ? 2 : 3 );
				$from    = $nth % count( $lines );
				$out     = array();
				for ( $n = 0; $n < $take; $n++ ) {
					$out[] = $lines[ ( $from + $n ) % count( $lines ) ];
				}

				return implode( ' ', array_unique( $out ) );
		}
	}

	/**
	 * A layout filled: the attributes a token wrote as text back as the numbers they are, so the editor reads them as the block declares.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	public static function finish( array $block ): array {
		foreach ( array( 'id', 'imageId', 'imageWidth', 'imageHeight', 'mobileImageId', 'mobileImageWidth', 'mobileImageHeight', 'mediaId' ) as $k ) {
			if ( isset( $block['attrs'][ $k ] ) && is_string( $block['attrs'][ $k ] ) && preg_match( '/^\d+$/', $block['attrs'][ $k ] ) === 1 ) {
				$block['attrs'][ $k ] = (int) $block['attrs'][ $k ];
			}
		}
		foreach ( (array) $block['innerBlocks'] as $i => $child ) {
			if ( is_array( $child ) ) {
				$block['innerBlocks'][ $i ] = self::finish( $child );
			}
		}

		return $block;
	}

	/**
	 * The words a page is about: the words of its title that name something.
	 *
	 * @return array<int, string>
	 */
	public static function topic( string $title ): array {
		$skip = array( 'restoration', 'service', 'services', 'about', 'contact', 'frequently', 'asked', 'questions', 'testimonials', 'with', 'from', 'your', 'the', 'and', 'near', 'you', 'area', 'areas' );
		$out  = array();
		foreach ( (array) preg_split( '/[^\p{L}]+/u', mb_strtolower( $title ), -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
			if ( mb_strlen( (string) $w ) >= 4 && ! in_array( $w, $skip, true ) ) {
				$out[] = (string) $w;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/** A drawing for words: what they are about, by a word in them. */
	public static function icon_for( string $text ): string {
		$t     = mb_strtolower( $text );
		$table = array( 'water' => 'droplets', 'flood' => 'droplets', 'sewage' => 'droplets', 'pipe' => 'droplets', 'fire' => 'flame', 'smoke' => 'flame', 'mold' => 'leaf', 'storm' => 'wind', 'wind' => 'wind', 'roof' => 'house', 'home' => 'house', 'commercial' => 'house', 'insur' => 'shield', 'claim' => 'file-check', 'licen' => 'award', 'certif' => 'award', 'estimate' => 'dollar-sign', 'free' => 'dollar-sign', 'minute' => 'clock', '24/7' => 'clock', 'hour' => 'clock', 'response' => 'clock', 'call' => 'phone', 'phone' => 'phone', 'veteran' => 'users', 'team' => 'users', 'family' => 'heart-handshake', 'inspect' => 'search', 'repair' => 'hammer', 'rebuild' => 'hammer', 'truck' => 'truck' );
		foreach ( $table as $word => $icon ) {
			if ( str_contains( $t, $word ) ) {
				return $icon;
			}
		}

		return 'check';
	}

	/** How many times a kind of slot has been asked for on this page, so two headings of one role are not the same words. */
	private static function nth( array $facts, string $key ): int {
		static $counts = array();
		$page = (string) ( $facts['title'] ?? '' ) . '|' . (string) ( $facts['home'] ?? '' );
		$n    = $counts[ $page ][ $key ] ?? 0;
		$counts[ $page ][ $key ] = $n + 1;

		return $n;
	}

	/**
	 * The rows of a section's main list: for each item its title (a heading, or its first short text), its longest text, the rest.
	 *
	 * @param array<string, mixed> $component Section_Library::analyze().
	 * @return array<int, array{title:string, text:string, meta:string, url:string, image_url?:string, image_id?:string, image_alt?:string}>
	 */
	private static function rows( array $component ): array {
		$repeats = array_values( (array) ( $component['repeats'] ?? array() ) );
		if ( $repeats === array() ) {
			return array();
		}
		$block = (array) $component['block'];
		// The list with the most words in it is the section's list (a row of stars under each review is a list too, with none).
		$weigh = static function ( array $r ) use ( $block ): int {
			try {
				$parent = Block_Tree::at( $block, (array) $r['path'] );
			} catch ( \RuntimeException $e ) {
				return 0;
			}
			$n = 0;
			foreach ( (array) $r['items'] as $i ) {
				foreach ( self::segments( (array) ( $parent['innerBlocks'][ $i ] ?? array() ) ) as $s ) {
					$n += str_word_count( $s['text'] );
				}
			}

			return $n;
		};
		usort( $repeats, static fn( $a, $b ) => $weigh( $b ) <=> $weigh( $a ) );
		$repeat = $repeats[0];
		try {
			$parent = Block_Tree::at( $block, (array) $repeat['path'] );
		} catch ( \RuntimeException $e ) {
			return array();
		}
		$out = array();
		foreach ( (array) $repeat['items'] as $i ) {
			$item = $parent['innerBlocks'][ $i ] ?? null;
			if ( ! is_array( $item ) ) {
				continue;
			}
			$segments = self::segments( $item );
			$texts    = array_values( array_map( static fn( $s ) => $s['text'], array_filter( $segments, static fn( $s ) => $s['kind'] !== 'number' ) ) );
			if ( $texts === array() ) {
				continue;
			}
			$number = '';
			foreach ( $segments as $s ) {
				if ( $s['kind'] === 'number' ) {
					$number = $s['text'];
					break;
				}
			}
			// A step's number drawn in a badge is often hidden from screen readers (the words say it is a step): it is still its number.
			if ( $number === '' && preg_match( '/<[a-z][a-z0-9]*\b[^>]*\baria-hidden="true"[^>]*>\s*(\d{1,2})\s*<\//i', serialize_block( $item ), $nm ) === 1 ) {
				$number = $nm[1];
			}
			$title = '';
			foreach ( $segments as $s ) {
				if ( $s['kind'] === 'heading' ) {
					$title = $s['text'];
					break;
				}
			}
			if ( $title === '' ) {
				foreach ( $texts as $t ) {
					if ( str_word_count( $t ) <= 10 ) {
						$title = $t;
						break;
					}
				}
			}
			$rest = array_values( array_filter( $texts, static fn( $t ) => $t !== $title ) );
			usort( $rest, static fn( $a, $b ) => mb_strlen( $b ) <=> mb_strlen( $a ) );
			$image = self::first_image( $item );
			$url   = self::first_link( $item );
			$row   = array(
				'title'   => $title !== '' ? $title : (string) $texts[0],
				'text'    => (string) ( $rest[0] ?? '' ),
				'meta'    => implode( ' · ', array_slice( $rest, 1, 2 ) ),
				'eyebrow' => $number,
				'url'     => preg_match( '/^(https?:|tel:|mailto:|\/)/i', $url ) ? $url : '',
			);
			if ( $image !== null ) {
				$row['image_url'] = $image['url'];
				$row['image_id']  = (string) $image['id'];
				$row['image_alt'] = $image['alt'];
			}
			$out[] = $row;
		}

		return $out;
	}

	/**
	 * The words of an item, in reading order, each with what it is: a heading (a heading block, or a heading element inside a list item), a
	 * number (a step's "01"), or text. A block whose own markup holds several elements (a list item with a title and a line under it) gives
	 * one segment per element. Marks (a row of stars, a tick) are left out.
	 *
	 * @param array<string, mixed> $block
	 * @return array<int, array{kind:string, text:string}>
	 */
	private static function segments( array $block ): array {
		$out  = array();
		$walk = static function ( array $b ) use ( &$walk, &$out ): void {
			$name = (string) ( $b['blockName'] ?? '' );
			if ( in_array( $name, self::TEXT, true ) || \DXAI_UI\Theme\Capabilities::is_block( $name, 'span' ) ) {
				$html = '';
				foreach ( (array) ( $b['innerContent'] ?? array() ) as $part ) {
					if ( is_string( $part ) ) {
						$html .= $part;
					}
				}
				$html = (string) preg_replace( '#<(\w+)\b[^>]*\baria-hidden="true"[^>]*>.*?</\1>#s', ' ', $html );
				$html = (string) preg_replace( '#<br\s*/?>#i', "\n", $html );
				// The block's own element first (the list item, the paragraph), so the elements inside it are read one by one.
				$html = (string) preg_replace( '#^\s*<(\w+)\b[^>]*>(.*)</\1>\s*$#s', '$2', $html );
				// The elements inside the block's own markup, each its own words; what is outside them is words too.
				$inner = preg_replace_callback(
					'#<(h[1-6]|p|div|li|summary)\b[^>]*>(.*?)</\1>#is',
					static function ( array $m ) use ( &$out ): string {
						$t = Block_Tree::clean( html_entity_decode( wp_strip_all_tags( $m[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
						if ( $t !== '' && ! preg_match( self::MARKS, $t ) ) {
							$out[] = array( 'kind' => preg_match( '/^(h[1-6]|summary)$/i', $m[1] ) ? 'heading' : ( preg_match( '/^\d{1,2}$/', $t ) ? 'number' : 'text' ), 'text' => $t );
						}

						return "\n";
					},
					$html
				);
				foreach ( preg_split( '/\n+/', (string) $inner ) as $line ) {
					$t = Block_Tree::clean( html_entity_decode( wp_strip_all_tags( (string) $line ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
					if ( $t !== '' && ! preg_match( self::MARKS, $t ) ) {
						$out[] = array( 'kind' => $name === 'core/heading' ? 'heading' : ( preg_match( '/^\d{1,2}$/', $t ) ? 'number' : 'text' ), 'text' => $t );
					}
				}
			}
			foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $c ) {
				if ( is_array( $c ) ) {
					$walk( $c );
				}
			}
		};
		$walk( $block );

		return $out;
	}

	/**
	 * The picture a block holds, by the theme's picture block or the core one.
	 *
	 * @param array<string, mixed> $block
	 * @return array{id:int, url:string, alt:string}|null
	 */
	private static function picture_of( array $block ): ?array {
		$name  = (string) ( $block['blockName'] ?? '' );
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$shape = \DXAI_UI\Theme\Capabilities::picture_shape( $name );
		if ( $shape !== array() ) {
			$id  = (int) ( $attrs[ $shape['id'] ] ?? 0 );
			$url = (string) ( $attrs[ $shape['url'] ] ?? '' );
			if ( $url === '' && $id > 0 ) {
				$url = (string) wp_get_attachment_url( $id );
			}

			return $url === '' ? null : array( 'id' => $id, 'url' => $url, 'alt' => (string) ( $attrs[ $shape['alt'] ] ?? $attrs['alt'] ?? '' ) );
		}
		if ( $name === 'core/image' ) {
			$id  = Block_Tree::image_id( $block );
			$url = $id > 0 ? (string) wp_get_attachment_url( $id ) : '';
			$alt = $id > 0 ? (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) : '';
			if ( preg_match( '/<img\b[^>]*\ssrc="([^"]+)"/i', (string) ( $block['innerHTML'] ?? '' ), $m ) ) {
				$url = $url !== '' ? $url : $m[1];
			}
			if ( $alt === '' && preg_match( '/<img\b[^>]*\salt="([^"]*)"/i', (string) ( $block['innerHTML'] ?? '' ), $m ) ) {
				$alt = $m[1];
			}

			return $url === '' ? null : array( 'id' => $id, 'url' => $url, 'alt' => $alt );
		}

		return null;
	}

	/**
	 * The first picture in a block, and all of them.
	 *
	 * @param array<string, mixed> $block
	 * @return array{id:int, url:string, alt:string}|null
	 */
	/** Whether words are a placeholder a design left to be filled in, not something it says. */
	public static function placeholder( string $text ): bool {
		return preg_match( '/\blorem ipsum\b|\bpaste (?:a|the|your)\b|\breviewer name\b|\bcustomer name\b|\byour (?:name|review|testimonial|text) here\b|\b(?:review|testimonial|text|copy|quote) goes here\b|\bplaceholder text\b/i', $text ) === 1;
	}

	/**
	 * Whether the rows of a section are numbered steps: each row has a number of its own (a badge drawn beside it, even one hidden from
	 * screen readers), in order from one, three of them at least — or the section's heading says it is the process ("How it works", "What
	 * happens when you call", "Our process").
	 *
	 * @param array<int, array<string, string>> $rows
	 * @param array<string, mixed>               $block
	 */
	private static function numbered( array $rows, array $block ): bool {
		if ( count( $rows ) < 3 ) {
			return false;
		}
		$n = array_map( static fn( $r ) => (string) ( $r['eyebrow'] ?? '' ), $rows );
		if ( $n === array_map( 'strval', range( 1, count( $rows ) ) ) || $n === array_map( static fn( $i ) => sprintf( '%02d', $i ), range( 1, count( $rows ) ) ) ) {
			return true;
		}
		$heading = '';
		$find    = static function ( array $b ) use ( &$find, &$heading ): void {
			if ( $heading !== '' ) {
				return;
			}
			if ( (string) ( $b['blockName'] ?? '' ) === 'core/heading' ) {
				$heading = trim( wp_strip_all_tags( (string) $b['innerHTML'] ) );

				return;
			}
			foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $c ) {
				if ( is_array( $c ) ) {
					$find( $c );
				}
			}
		};
		$find( $block );

		return preg_match( '/\b(?:how it works|what happens|our process|the process|step[- ]by[- ]step|what to expect|how we work|from first call|\d+ (?:simple )?steps)\b/i', $heading ) === 1;
	}

	/**
	 * The headed lines of a section: an element whose words open with a bold run (after a tick or a drawing, if any) and go on after it —
	 * "✓ **On site in about 60 minutes.** When water is spreading…" — as rows with a title (the bold run) and words (the rest). Read from
	 * the section's markup, wherever the lines are (one block with many lines, or a list).
	 *
	 * @param array<string, mixed> $block
	 * @return array<int, array{title:string, text:string}>
	 */
	private static function headed_lines( array $block ): array {
		$html = serialize_block( $block );
		if ( ! preg_match( '/<(?:strong|b)\b/i', $html ) || ! class_exists( '\DOMDocument' ) ) {
			return array();
		}
		$doc = new \DOMDocument();
		$was = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $was );
		$out   = array();
		$xpath = new \DOMXPath( $doc );
		foreach ( $xpath->query( '//strong | //b' ) ?: array() as $bold ) {
			$parent = $bold->parentNode;
			if ( ! $parent instanceof \DOMElement || in_array( strtolower( $parent->nodeName ), array( 'a', 'button', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'summary', 'label' ), true ) ) {
				continue;
			}
			// Nothing but a tick, a drawing or a hidden mark before the bold run.
			$before = '';
			for ( $n = $bold->previousSibling; $n !== null; $n = $n->previousSibling ) {
				if ( $n instanceof \DOMElement && ( $n->getAttribute( 'aria-hidden' ) === 'true' || in_array( strtolower( $n->nodeName ), array( 'svg', 'img', 'i' ), true ) ) ) {
					continue;
				}
				$before .= $n->textContent;
			}
			if ( trim( (string) preg_replace( '/[✓✔✅•▪►➜→\-\s]+/u', '', $before ) ) !== '' ) {
				continue;
			}
			$title = trim( (string) preg_replace( '/\s+/u', ' ', $bold->textContent ) );
			$title = rtrim( $title, " .:—–-" );
			$rest  = '';
			for ( $n = $bold->nextSibling; $n !== null; $n = $n->nextSibling ) {
				$rest .= $n->textContent;
			}
			$rest = trim( (string) preg_replace( '/^[\s.:—–-]+/u', '', (string) preg_replace( '/\s+/u', ' ', $rest ) ) );
			$tw   = str_word_count( $title );
			if ( $tw < 2 || $tw > 10 || str_word_count( $rest ) < 3 || self::placeholder( $title . ' ' . $rest ) ) {
				continue;
			}
			$out[] = array( 'title' => $title, 'text' => $rest );
		}

		return $out;
	}

	private static function first_image( array $block ): ?array {
		$all = self::all_images( $block );

		return $all[0] ?? null;
	}

	/**
	 * @param array<string, mixed> $block
	 * @return array<int, array{id:int, url:string, alt:string}>
	 */
	private static function all_images( array $block ): array {
		$out  = array();
		$walk = static function ( array $b ) use ( &$walk, &$out ): void {
			$p = self::picture_of( $b );
			if ( $p !== null ) {
				$out[ $p['url'] ] = $p;
			}
			foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $c ) {
				if ( is_array( $c ) ) {
					$walk( $c );
				}
			}
		};
		$walk( $block );

		return array_values( $out );
	}

	/**
	 * The first address a block leads to.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function first_link( array $block ): string {
		$url  = '';
		$walk = static function ( array $b ) use ( &$walk, &$url ): void {
			if ( $url !== '' ) {
				return;
			}
			if ( isset( $b['attrs']['url'] ) && is_string( $b['attrs']['url'] ) && $b['attrs']['url'] !== '' ) {
				$url = $b['attrs']['url'];

				return;
			}
			if ( preg_match( '/<a\b[^>]*\shref="([^"]+)"/i', (string) ( $b['innerHTML'] ?? '' ), $m ) ) {
				$url = $m[1];

				return;
			}
			foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $c ) {
				if ( is_array( $c ) ) {
					$walk( $c );
				}
			}
		};
		$walk( $block );

		return $url;
	}

	/**
	 * The pictures of a section, as rows.
	 *
	 * @param array<string, mixed>             $block
	 * @param array<int, array<string, mixed>> $slots The section's image slots (Section_Library).
	 * @return array<int, array{id:int, url:string, alt:string}>
	 */
	private static function images( array $block, array $slots ): array {
		// The section's own pictures, whichever block holds them (the theme's picture block is not a slot Section_Library knows).
		return self::all_images( $block );
	}

	/**
	 * The figures a section states (a number and the words under it: "17,000+ homes restored").
	 *
	 * @param array<string, mixed> $block
	 * @return array<int, array{title:string, text:string, meta:string, url:string, icon:string}>
	 */
	private static function figures( array $block ): array {
		$leaves = array();
		$walk   = static function ( array $b ) use ( &$walk, &$leaves ): void {
			$name = (string) ( $b['blockName'] ?? '' );
			if ( in_array( $name, array( 'core/heading', 'core/paragraph', 'dxai-ui/text' ), true ) || \DXAI_UI\Theme\Capabilities::is_block( $name, 'span' ) ) {
				$t = Block_Tree::own_text( $b );
				if ( $t !== '' ) {
					$leaves[] = $t;
				}
			}
			foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $c ) {
				if ( is_array( $c ) ) {
					$walk( $c );
				}
			}
		};
		$walk( $block );
		$out = array();
		// A street address ("497 Lendall Ln, Suite 104") is a number and words, and no figure.
		$street = '/\b(?:st|street|ave|avenue|rd|road|ln|lane|blvd|boulevard|dr|drive|hwy|highway|pkwy|parkway|ct|court|way|pl|place|suite|ste|unit|floor)\b\.?(?:,|\s|$)/i';
		foreach ( $leaves as $i => $t ) {
			if ( preg_match( $street, $t ) === 1 ) {
				continue;
			}
			// A figure with its words in one line ("17,000+ Virginia homes restored")…
			if ( preg_match( '/^([\$]?\d[\d,.]*\s*(?:\+|%|k|\/7|hrs?|min|yrs?)?)\s+([^\d].{1,48})$/iu', $t, $m ) === 1 && str_word_count( $m[2] ) <= 7 ) {
				$out[] = array( 'title' => trim( $m[1] ), 'text' => trim( $m[2] ), 'meta' => '', 'url' => '', 'icon' => self::icon_for( $m[2] ) );
				continue;
			}
			// …or the figure alone, with its words in the line under it. One digit alone is the number of a step, not a figure.
			if ( preg_match( '/^[\$]?\d[\d,.]*\s*(\+|%|k|\/7|hrs?|min|yrs?)?$/i', $t ) !== 1 || preg_match( '/^\d$/', $t ) === 1 ) {
				continue;
			}
			$label = (string) ( $leaves[ $i + 1 ] ?? '' );
			if ( $label === '' || str_word_count( $label ) > 7 || preg_match( '/^[\$]?\d/', $label ) ) {
				continue;
			}
			$out[] = array( 'title' => $t, 'text' => $label, 'meta' => '', 'url' => '', 'icon' => self::icon_for( $label ) );
		}

		return $out;
	}

	/**
	 * The points a section makes in a few words (a tick list, a row of promises): short texts that are not headings.
	 *
	 * @param array<string, mixed> $block
	 * @return array<int, string>
	 */
	private static function points( array $block ): array {
		$out  = array();
		$walk = static function ( array $b ) use ( &$walk, &$out ): void {
			$name = (string) ( $b['blockName'] ?? '' );
			// A point is an item of a list, or a line that opens with a tick: the pills over a hero ("24/7 Emergency Response · Olympia"),
			// its buttons ("Call now: (855) 560-7463"), the labels under its figures ("800+ Google reviews") and a label block are not points.
			if ( in_array( $name, array( 'core/list-item', 'core/paragraph', 'dxai-ui/text' ), true ) ) {
				$t      = Block_Tree::own_text( $b );
				$ticked = preg_match( '/^[✓✔✅•▪►➜→-]\s*/u', $t ) === 1;
				$t      = trim( (string) preg_replace( '/^[✓✔✅•▪►➜→-]\s*/u', '', $t ) );
				$n      = str_word_count( $t );
				if ( $name !== 'core/list-item' && ! $ticked ) {
					$t = '';
				}
				if ( $t !== '' && preg_match( '/\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4}|[·|→★]|https?:\/\/|^(?:call|phone|tel|email)\b|:\s*$/iu', $t ) === 1 ) {
					$t = '';
				}
				if ( $t !== '' && $n >= 2 && $n <= 10 && ! preg_match( '/^[\$]?\d[\d,.]*\s*(\+|%)?$/', $t ) && ! str_ends_with( $t, '.' ) ) {
					$out[] = $t;
				}
			}
			foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $c ) {
				if ( is_array( $c ) ) {
					$walk( $c );
				}
			}
		};
		$walk( $block );

		return array_values( array_unique( $out ) );
	}
}

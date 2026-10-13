<?php
/**
 * The builder of the team's layouts: a site's pages in, the layouts of their sections out (data/block-library, or a template's folder).
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

/**
 * A layout is a section of a team page with every word taken out and a slot left in its place: a heading, a paragraph, a list item,
 * a label and a button each become a token the page maker fills ({{t:h2-m}}: a heading of middle length; {{row:title}} in a repeated
 * card), every picture a slot for one of the Home's pictures ({{image_url:1}}), every small drawing a marker for one of the plugin's own
 * (dxaiIcon), a set of cards, steps, reviews or questions one card marked to be repeated (dxaiRepeat), a phone link the Home's phone,
 * any other link the page the maker names. The blocks, their classes, their spacing, their colours (as the theme's names for them) and
 * their alignment stay as the team set them: that is what makes the section the team's. A line that says nothing of one site stays in
 * its slot as its first words. Nothing else of the site it came from is kept — not a name, a number, an address, a picture, a form, a
 * widget — and a check at the end leaves out a section it finds one in.
 *
 * Which sections: for each role a section plays (the two columns, the cards, the steps, the reviews, the questions, the call to action,
 * the related pages, the trust row, the places), the arrangement each site uses most on its pages of the kind, one or two per site,
 * from as many sites as there are — and, from a page marked as the team's own showcase, every arrangement once. bin/build-layouts.php
 * runs it from the command line; a template uploaded in the admin runs it on its exports (Template_Store).
 */
final class Layout_Builder {

	/**
	 * The pages of a WordPress export (WXR): site, host, id, title, slug, parent, modified, content — every page of it, marked as the
	 * team's showcase (every arrangement of it counts once).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function read_wxr( string $file ): array {
		$xml = simplexml_load_string( (string) file_get_contents( $file ), 'SimpleXMLElement', LIBXML_NOCDATA ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( $xml === false || ! isset( $xml->channel ) ) {
			return array();
		}
		$host = strtolower( (string) preg_replace( '/^www\./', '', (string) wp_parse_url( (string) ( $xml->channel->link ?? '' ), PHP_URL_HOST ) ) );
		$site = (string) preg_replace( '/\.[a-z]+$/', '', $host );
		$out  = array();
		foreach ( $xml->channel->item as $item ) {
			$wp      = $item->children( 'wp', true );
			$content = $item->children( 'content', true );
			if ( (string) $wp->post_type !== 'page' ) {
				continue;
			}
			$out[] = array(
				'site'       => $site,
				'host'       => $host,
				'site_title' => trim( (string) ( $xml->channel->title ?? '' ) ),
				'id'         => (int) $wp->post_id,
				'title'    => (string) $item->title,
				'slug'     => (string) $wp->post_name,
				'parent'   => (int) $wp->post_parent,
				'modified' => (string) $wp->post_modified,
				'content'  => (string) $content->encoded,
				'showcase' => true,
			);
		}

		return $out;

	}

	/**
	 * One page from a file of block markup (the editor's code view): site and title as given.
	 *
	 * @return array<string, mixed>
	 */
	public static function read_markup( string $file, string $site, string $title = '' ): array {
		return array(
			'site'     => $site,
			'host'     => $site,
			'id'       => (int) sprintf( '%u', crc32( basename( $file ) ) ) % 1000000,
			'title'    => $title !== '' ? $title : preg_replace( '/\.[a-z0-9]+$/i', '', basename( $file ) ),
			'slug'     => sanitize_title( basename( $file ) ),
			'parent'   => 0,
			'modified' => '',
			'content'  => (string) file_get_contents( $file ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			'showcase' => true,
		);
	}

	/**
	 * The layouts of the pages given, written to a library folder.
	 *
	 * @param array<int, array<string, mixed>> $pages Each: site, host, id, title, slug, content, and showcase (bool).
	 * @param array<string, mixed>             $opts  out_dir (the library folder: its library.json and blocks/ are written; the sections
	 *                                                built by hand in it stay), team (the sites to read, '' = all), hosts (site => host),
	 *                                                company_names (host => the names the site calls itself by), themes (for a new
	 *                                                library.json), max (per role, 8), only (a role), dry (plan only).
	 * @return array{made:int, errors:array<int, string>, lines:array<int, string>, written:bool, index:array<int, array<string, mixed>>}
	 */
	public static function build( array $pages, array $opts ): array {
		// A page as a dump or an export gives it, with every field the kind the rest expects.
		foreach ( $pages as $k => $p ) {
			$pages[ $k ] = array(
				'site'     => (string) ( $p['site'] ?? '' ),
				'host'     => (string) ( $p['host'] ?? '' ),
				'id'       => (int) ( $p['id'] ?? 0 ),
				'title'    => (string) ( $p['title'] ?? '' ),
				'slug'     => (string) ( $p['slug'] ?? '' ),
				'parent'   => (int) ( $p['parent'] ?? 0 ),
				'modified' => (string) ( $p['modified'] ?? '' ),
				'content'  => (string) ( $p['content'] ?? '' ),
				'showcase' => ! empty( $p['showcase'] ),
			);
		}
		$dry   = ! empty( $opts['dry'] );
		$only  = (string) ( $opts['only'] ?? '' );
		$max   = max( 1, (int) ( $opts['max'] ?? 8 ) );
		// What the layouts are named by: 'layout' for the built-in library, a stored library's slug for its own (no two libraries name alike).
		$prefix       = sanitize_key( (string) ( $opts['prefix'] ?? 'layout' ) ) ?: 'layout';
		$label_prefix = sanitize_text_field( (string) ( $opts['label_prefix'] ?? 'team' ) ) ?: 'team';
		$lines = array();
		$say   = static function ( string $line ) use ( &$lines ): void {
			foreach ( explode( "\n", rtrim( $line, "\n" ) ) as $l ) {
				$lines[] = $l;
			}
		};
		// The team's sites in the dump (the others are the plugin's own test sites), and the pages a layout may come from.
		$team  = array_values( array_map( 'strval', (array) ( $opts['team'] ?? array() ) ) );
		$hosts = array( '3' => 'americanrestorationnm', '4' => 'floodrescueok', '7' => 'completerestoration', '8' => 'pacesga', '9' => 'ajrestores', '11' => 'archerrestorations', '12' => '1stcallrestoration', '13' => 'procraftrestore', '14' => 'calludk', '15' => 'iddk', '16' => 'h2oaway' );
		$hosts = (array) ( $opts['hosts'] ?? array() ) + $hosts;
		// The names each site calls itself by: in the words of a section kept as a layout's first words, they become the company's (the full
		// name first, the short forms after it).
		$company_names = array(
			'americanrestorationnm' => array( 'American Restoration' ),
			'floodrescueok'         => array( 'Flood Rescue' ),
			'completerestoration'   => array( 'Complete Restoration' ),
			'pacesga'               => array( 'Paces Restoration', 'Paces' ),
			'ajrestores'            => array( 'A&J Restoration', 'A & J Restoration', 'AJ Restoration', 'A&J', 'A & J' ),
			'archerrestorations'    => array( 'Archer Restorations', 'Archer Restoration', 'Archer' ),
			'1stcallrestoration'    => array( '1st Call Restoration', 'First Call Restoration', '1st Call', 'First Call' ),
			'procraftrestore'       => array( 'ProCraft Restoration', 'Pro-Craft Restoration', 'ProCraft', 'Pro-Craft' ),
			'calludk'               => array( 'Utah Disaster Kleenup', 'Disaster Kleenup', 'UDK' ),
			'iddk'                  => array( 'Idaho Disaster Kleenup', 'Disaster Kleenup', 'IDDK' ),
			'h2oaway'               => array( 'H2O Away' ),
			'arcusrestoration'      => array( 'Arcus Restoration', 'Arcus' ),
		);
		$company_names = (array) ( $opts['company_names'] ?? array() ) + $company_names;
		$states = array( 'Alabama', 'Alaska', 'Arizona', 'Arkansas', 'California', 'Colorado', 'Connecticut', 'Delaware', 'Florida', 'Georgia', 'Hawaii', 'Idaho', 'Illinois', 'Indiana', 'Iowa', 'Kansas', 'Kentucky', 'Louisiana', 'Maine', 'Maryland', 'Massachusetts', 'Michigan', 'Minnesota', 'Mississippi', 'Missouri', 'Montana', 'Nebraska', 'Nevada', 'New Hampshire', 'New Jersey', 'New Mexico', 'New York', 'North Carolina', 'North Dakota', 'Ohio', 'Oklahoma', 'Oregon', 'Pennsylvania', 'Rhode Island', 'South Carolina', 'South Dakota', 'Tennessee', 'Texas', 'Utah', 'Vermont', 'Virginia', 'Washington', 'West Virginia', 'Wisconsin', 'Wyoming', 'District of Columbia', 'Washington DC', 'Washington, DC' );
		$places_seen = array(); // the places the sites' pages are for, read from their titles ("… in Draper, Utah")
		$kinds = array( 'service', 'location', 'about', 'contact', 'testimonials', 'faq', 'services', 'areas' );
		// The roles a layout is made for, with the list a repeated item of it is: the pages of a site, its places, or what the maker lists for the role.
		$roles = array(
			'two-col' => 'items',
			'cards'   => 'items',
			'process' => 'steps',
			'reviews' => 'reviews',
			'faq'     => 'faq',
			'cta'     => 'items',
			'related' => 'related',
			'trust'   => 'logos',
			'areas'   => 'places',
			'content' => 'items',
		);
		$banned_blocks = array( 'core/shortcode', 'core/html', 'core/freeform', 'core/embed', 'core/code', 'core/video', 'core/audio', 'core/file', 'core/table', 'core/query', 'core/post-content', 'core/template-part', 'core/navigation', 'core/social-links' );
		$leaf_text     = array( 'core/heading', 'core/paragraph', 'core/list-item', 'amr/span', 'core/button', 'dxai-ui/text', 'core/details' );

		// --- 1. The sections, by role and structure. -----------------------------------------------------------------------------------

		/** The shape of a block tree without its words: what is drawn, in what arrangement (the same as scratchpad/tpl/team_sections.php). */
		$shape = static function ( array $b, int $depth = 0 ) use ( &$shape ): string {
			$a    = is_array( $b['attrs'] ?? null ) ? $b['attrs'] : array();
			$bits = array( (string) $b['blockName'] );
			foreach ( array( 'align', 'backgroundColor', 'textColor', 'level', 'width', 'verticalAlignment', 'isStackedOnMobile', 'columns' ) as $k ) {
				if ( isset( $a[ $k ] ) && is_scalar( $a[ $k ] ) ) {
					$bits[] = $k . '=' . $a[ $k ];
				}
			}
			if ( isset( $a['layout']['type'] ) ) {
				$bits[] = 'layout=' . $a['layout']['type'] . ( isset( $a['layout']['orientation'] ) ? '/' . $a['layout']['orientation'] : '' );
			}
			if ( isset( $a['className'] ) ) {
				// A state a script sets (the open question, the active tab) is not a shape of its own: the item is the same item.
				$cls = array_filter( explode( ' ', (string) $a['className'] ), static fn( $c ) => $c !== '' && ! preg_match( '/^(reveal|has-custom-css|wp-|is-style|is-open|is-active|active|open)$|^(reveal|has-custom-css|wp-|is-style)|--(expanded|open|active|default-open|current)$/', $c ) );
				sort( $cls );
				$bits[] = 'c:' . implode( '.', array_slice( $cls, 0, 6 ) );
			}
			$kids = array();
			foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $c ) {
				if ( ! empty( $c['blockName'] ) && $depth < 6 ) {
					$kids[] = $shape( $c, $depth + 1 );
				}
			}

			return implode( '|', $bits ) . ( $kids ? '{' . implode( ',', $kids ) . '}' : '' );
		};
		$names_of = static function ( array $b ) use ( &$names_of ): array {
			$out = array( (string) $b['blockName'] );
			foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $c ) {
				if ( ! empty( $c['blockName'] ) ) {
					$out = array_merge( $out, $names_of( $c ) );
				}
			}

			return $out;
		};

		// The sections the library already holds by hand (bin/block-library-specs.php, from the same showcase pages): not taken twice.
		$specs_from = ( static function (): array {
			$specs     = array();
			$forbidden = array();
			$file      = DXAI_UI_DIR . 'bin/block-library-specs.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
			$sites = array( 'arcus' => 'arcusrestoration', 'archer' => 'archerrestorations' );
			$out   = array();
			foreach ( (array) $specs as $spec ) {
				if ( isset( $spec['from'][0], $spec['from'][1] ) ) {
					$out[] = ( $sites[ (string) $spec['from'][0] ] ?? (string) $spec['from'][0] ) . '#' . (int) $spec['from'][1];
				}
			}

			return $out;
		} )();
		$showcase = array(); // site => true, for the team's own exports
		foreach ( $pages as $p ) {
			if ( ! empty( $p['showcase'] ) ) {
				$showcase[ (string) $p['site'] ] = true;
			}
		}
		$team  = array_values( array_unique( array_merge( $team, $team === array() ? array() : array_keys( $showcase ) ) ) );
		$found = array(); // role => sig => array( site => array( of candidates ) )
		foreach ( $pages as $p ) {
			if ( $team !== array() && ! in_array( (string) $p['site'], $team, true ) ) {
				continue;
			}
			if ( preg_match( '/\bin ([A-Z][\w.\'-]+(?:[ ,]+[A-Z][\w.\'-]+)*)\s*$/u', html_entity_decode( (string) $p['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $pm ) === 1 ) {
				foreach ( preg_split( '/\s*,\s*/', trim( $pm[1] ) ) as $place ) {
					if ( $place !== '' ) {
						$places_seen[] = $place;
					}
				}
			}
			$kind = ! empty( $p['showcase'] ) ? 'showcase' : Copy_Prompt::kind( (string) $p['title'], (string) $p['slug'] );
			if ( $kind !== 'showcase' && ! in_array( $kind, $kinds, true ) ) {
				continue;
			}
			$blocks = array_values( array_filter( parse_blocks( (string) $p['content'] ), static fn( $b ) => ! empty( $b['blockName'] ) ) );
			foreach ( $blocks as $i => $b ) {
				$role = Section_Roles::of( $b, $i === 0 );
				if ( ! isset( $roles[ $role ] ) || ( $only !== '' && $role !== $only ) ) {
					continue;
				}
				if ( in_array( (string) $p['site'] . '#' . $i, $specs_from, true ) ) {
					continue;
				}
				$names  = $names_of( $b );
				$markup = serialize_block( $b );
				if ( array_intersect( $names, $banned_blocks ) !== array() || preg_match( '/^gravityforms\//m', implode( "\n", $names ) ) || preg_match( '/<iframe|<form|<script|\[[a-z_-]+[^\]]*\]/i', $markup ) ) {
					continue;
				}
				if ( count( $names ) < 4 || count( $names ) > 70 || array_intersect( $names, $leaf_text ) === array() ) {
					continue;
				}
				$sig = substr( sha1( $shape( $b ) ), 0, 10 );
				$found[ $role ][ $sig ][ (string) $p['site'] ][] = array(
					'site'  => (string) $p['site'],
					'page'  => (int) $p['id'],
					'kind'  => $kind,
					'index' => $i,
					'block' => $b,
					'names' => $names,
					'title' => (string) $p['title'],
				);
			}
		}

		// --- 2. Which to take: per role, each site's most used arrangement (and its second where the site has one), from as many sites as there are. ---
		$chosen = array();
		foreach ( $found as $role => $sigs ) {
			$per_site = array(); // site => array( sig => uses )
			foreach ( $sigs as $sig => $sites ) {
				foreach ( $sites as $site => $cands ) {
					$per_site[ $site ][ $sig ] = count( $cands );
				}
			}
			$pick = array();
			foreach ( array( 0, 1, 2, 3 ) as $rank ) {
				foreach ( $per_site as $site => $uses ) {
					$show = isset( $showcase[ $site ] );
					// A site's first two ways; the team's own showcase gives up to four of a role.
					if ( $rank > 1 && ! $show ) {
						continue;
					}
					arsort( $uses );
					$sig = array_keys( $uses )[ $rank ] ?? null;
					// An arrangement a site used once is a page of its own, not the site's way — unless the page is the team's own showcase.
					if ( $sig === null || ( $uses[ $sig ] < 2 && ! $show ) ) {
						continue;
					}
					$pick[] = array( 'sig' => $sig, 'site' => (string) $site, 'uses' => $uses[ $sig ], 'showcase' => $show, 'kinds' => array_count_values( array_column( $found[ $role ][ $sig ][ $site ], 'kind' ) ) );
				}
			}
			// Up to half the places of a role for the team's showcase, the rest for the ways the live sites use most.
			usort( $pick, static fn( $a, $b ) => $b['uses'] <=> $a['uses'] );
			$show_picks = array_values( array_filter( $pick, static fn( $p ) => $p['showcase'] ) );
			$live_picks = array_values( array_filter( $pick, static fn( $p ) => ! $p['showcase'] ) );
			$show_picks = array_slice( $show_picks, 0, (int) ceil( $max / 2 ) );
			$chosen[ $role ] = array_merge( $show_picks, array_slice( $live_picks, 0, $max - count( $show_picks ) ) );
		}

		// --- 3. The layout: the section with its words, pictures and links taken out and slots left. --------------------------------------

		$errors = array();
		$walk   = static function ( array &$block, callable $fn, string $path = '0' ) use ( &$walk ): void {
			$fn( $block, $path );
			foreach ( $block['innerBlocks'] as $i => &$child ) {
				if ( ! empty( $child['blockName'] ) ) {
					$walk( $child, $fn, $path . '.' . $i );
				}
			}
			unset( $child );
		};
		$words = static fn( string $html ): int => str_word_count( wp_strip_all_tags( html_entity_decode( $html, ENT_QUOTES, 'UTF-8' ) ) );
		$len   = static fn( int $n ): string => $n <= 6 ? 's' : ( $n <= 28 ? 'm' : 'l' );

		/** The words of a leaf block replaced by a token, the element and its attributes kept. */
		$set_text = static function ( array &$b, string $token, ?string $href = null, ?string $label = null ): bool {
			$html = (string) $b['innerHTML'];
			$name = (string) $b['blockName'];
			// A question block's words are its summary; its answer is the block inside it, slotted on its own.
			if ( $name === 'core/details' ) {
				if ( preg_match( '/<summary\b/i', $html ) !== 1 ) {
					return false;
				}
				$sum            = static fn( string $s ): string => (string) preg_replace( '/(<summary\b[^>]*>)(.*?)(<\/summary>)/is', '$1' . str_replace( '$', '\$', $token ) . '$3', $s, 1 );
				$b['innerHTML'] = $sum( $html );
				foreach ( $b['innerContent'] as $k => $part ) {
					if ( is_string( $part ) && preg_match( '/<summary\b/i', $part ) === 1 ) {
						$b['innerContent'][ $k ] = $sum( $part );
						break;
					}
				}

				return true;
			}
			if ( $name === 'core/button' ) {
				$new = preg_replace_callback(
					'/(<a\b[^>]*>)(.*?)(<\/a>)/is',
					static function ( array $m ) use ( $token, $href ): string {
						$open = $m[1];
						if ( $href !== null ) {
							$open = preg_match( '/\shref="/', $open ) ? (string) preg_replace( '/\shref="[^"]*"/', ' href="' . $href . '"', $open, 1 ) : (string) preg_replace( '/^<a\b/', '<a href="' . $href . '"', $open, 1 );
						}

						return $open . $token . $m[3];
					},
					$html,
					1,
					$n
				);
				if ( ! $n ) {
					return false;
				}
				if ( $href !== null ) {
					$b['attrs']['url'] = $href;
				}
			} else {
				$new = preg_replace( '/^(\s*<([a-z][a-z0-9]*)\b[^>]*>)(.*)(<\/\2>\s*)$/is', '$1' . str_replace( '$', '\$', $token ) . '$4', $html, 1, $n );
				if ( ! $n ) {
					return false;
				}
			}
			$b['innerHTML'] = (string) $new;
			foreach ( $b['innerContent'] as $k => $part ) {
				if ( is_string( $part ) ) {
					$b['innerContent'][ $k ] = (string) $new;
					break;
				}
			}
			// A list item that holds a nested list has more than one chunk; the first chunk is the words.
			return true;
		};
		/** An image block as a slot: the picture a page is given, or the drawing it names. */
		$icon_name = static function ( string $src, string $alt ): string {
			$key   = strtolower( $src . ' ' . $alt );
			$table = array(
				'phone' => 'phone', 'call' => 'phone', 'clock' => 'clock', '24' => 'clock', 'shield' => 'shield', 'insur' => 'shield', 'cert' => 'award', 'award' => 'award', 'badge' => 'award', 'star' => 'star', 'review' => 'star',
				'water' => 'droplets', 'flood' => 'droplets', 'drop' => 'droplets', 'fire' => 'flame', 'smoke' => 'flame', 'mold' => 'leaf', 'storm' => 'wind', 'wind' => 'wind', 'home' => 'house', 'house' => 'house', 'build' => 'house',
				'map' => 'map-pin', 'pin' => 'map-pin', 'location' => 'map-pin', 'truck' => 'truck', 'van' => 'truck', 'tool' => 'wrench', 'wrench' => 'wrench', 'hammer' => 'hammer', 'repair' => 'hammer', 'team' => 'users', 'people' => 'users',
				'check' => 'check', 'tick' => 'check', 'search' => 'search', 'inspect' => 'search', 'doc' => 'file-check', 'claim' => 'file-check', 'dollar' => 'dollar-sign', 'price' => 'dollar-sign', 'calendar' => 'calendar', 'heart' => 'heart-handshake',
			);
			foreach ( $table as $word => $icon ) {
				// (A key that is all digits, '24', is an integer key to PHP.)
				if ( str_contains( $key, (string) $word ) ) {
					return $icon;
				}
			}

			return 'check';
		};

		// --- The team's words, kept as a slot's first words. ------------------------------------------------------------------------------
		// A line of a section says something the team wrote for every page ("We work with all insurance companies", "Call us any time"): it
		// stays in the slot, after the bar, with the site's own facts made the company's ([[company]], [[phone]], [[subject]], [[region|…]]),
		// so a page says it until the words are written for it. A line that still says something of one site (a figure, a street, a tool, a
		// site, a widget, a name the list knows) is not kept: the slot gets provisional words instead.
		$specs_forbidden = ( static function (): array {
			$forbidden = array();
			$file      = DXAI_UI_DIR . 'bin/block-library-specs.php';
			if ( is_readable( $file ) ) {
				require $file;
			}

			return $forbidden;
		} )();
		$place_names = array_values( array_unique( array_merge( $places_seen, $states ) ) );
		usort( $place_names, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
		$place_re = '/\b(?:(?:East|West|North|South|Central|Northern|Southern|Eastern|Western|Greater|Metro|Downtown)\s+)?(?:' . implode( '|', array_map( static fn( string $p ): string => preg_quote( $p, '/' ), $place_names ) ) . ')\b/u';
		$generic  = static function ( string $html, string $host, string $title ) use ( $company_names, $place_re, $specs_forbidden ): ?string {
			$text = trim( (string) preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
			if ( $text === '' || str_word_count( $text ) > 70 || preg_match( '/[{}|]/', $text ) === 1 ) {
				return null;
			}
			// The page's own title: the service it is for, and the place. A line that goes on about that service in other words (a page for
			// commercial reconstruction says what reconstruction is) is that page's, not every page's.
			$t       = trim( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			$subject = $t;
			if ( $t !== '' && str_word_count( $t ) >= 2 ) {
				if ( preg_match( '/^(.*\S)\s+in\s+(.+\S)\s*$/iu', $t, $tm ) === 1 ) {
					$text    = str_ireplace( $t, '[[title]]', $text );
					$text    = str_ireplace( $tm[1], '[[subject]]', $text );
					$subject = $tm[1];
				} else {
					$text = str_ireplace( $t, '[[subject]]', $text );
				}
			}
			if ( $subject !== '' ) {
				$generic_words = array( 'restoration', 'restorations', 'services', 'service', 'damage', 'repair', 'repairs', 'cleanup', 'removal', 'remediation', 'emergency', 'company', 'home', 'homes', 'business', 'property', 'professional', 'residential', 'commercial', 'near', 'your', 'with', 'from', 'that', 'this', 'what', 'when', 'where', 'about', 'contact', 'free', 'local', 'expert', 'experts', 'mitigation', 'cleaning' );
				foreach ( preg_split( '/[^a-z]+/', strtolower( $subject ) ) ?: array() as $w ) {
					if ( strlen( $w ) >= 4 && ! in_array( $w, $generic_words, true ) && preg_match( '/\b' . preg_quote( $w, '/' ) . '/i', (string) preg_replace( '/\[\[[^\]]*\]\]/', '', $text ) ) === 1 ) {
						return null;
					}
				}
			}
			// A line about one or two kinds of damage, or that opens with one ("After a fire, …"), is a page's about them, not every page's; a
			// line that lists several further on is about the trade.
			$bare_text = (string) preg_replace( '/\[\[[^\]]*\]\]/', '', $text );
			$kind_re   = '(water|flood|fire|smoke|soot|mold|mould|mildew|sewage|sewer|storm|wind|hail|biohazard|asbestos|reconstruction|rebuild|contents|roof|basement|crawlspace|odor|commercial|residential)';
			preg_match_all( '/\b' . $kind_re . '\b/i', $bare_text, $sm );
			$kinds_named = count( array_unique( array_map( 'strtolower', $sm[1] ) ) );
			if ( ( $kinds_named >= 1 && $kinds_named <= 2 ) || preg_match( '/^(?:\S+\s+){0,4}' . $kind_re . '\b/i', $bare_text ) === 1 ) {
				return null;
			}
			// A place the list does not know, after a word that puts something there ("Near Farmers Branch").
			if ( preg_match( '/\b(?i:in|near|across|around|serving|throughout)\s+(?:the\s+)?[A-Z][a-z]+(?:\s+[A-Z][a-z]+)*/', $bare_text ) === 1 ) {
				return null;
			}
			// A platform, a body or a tool that is one site's to claim.
			if ( preg_match( '/\b(?:google|yelp|facebook|instagram|bbb|angi|angie|homeadvisor|thumbtack|nextdoor|iicrc|epa|osha|rsa|matterport|trustindex|xactimate|servpro|servicemaster)\b/i', $text ) === 1 ) {
				return null;
			}
			foreach ( (array) ( $company_names[ $host ] ?? array() ) as $i => $n ) {
				$text = (string) preg_replace( '/(?<![\w&])' . preg_quote( $n, '/' ) . '(?![\w&])/i', $i === 0 ? '[[company]]' : '[[company_short]]', $text );
			}
			$text = (string) preg_replace( '/\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4}/', '[[phone]]', $text );
			$text = (string) preg_replace( '/[\w.\-]+@[\w\-]+\.[a-z]{2,}/i', '[[email]]', $text );
			$text = (string) preg_replace( $place_re, '[[region|the area]]', $text );
			$text = (string) preg_replace( '/\[\[region\|the area\]\](?:,?\s*(?:[A-Z]{2}\b|(?:&|and|,)\s*\[\[region\|the area\]\]|\[\[region\|the area\]\]))+/', '[[region|the area]]', $text );
			foreach ( $specs_forbidden as $re ) {
				if ( preg_match( $re, $text ) === 1 ) {
					return null;
				}
			}
			if ( preg_match( '/\b\d[\d,]*\s*\+|\b\d{2,3},\d{3}\b|\b\d+\s*(?:years?|yrs?|minutes?|mins?|hours?|hrs?|days?|%)\b|\$\s?\d|\bsince \d{4}\b|\b(?:19|20)\d{2}\b|\b\d+\s*(?:-|to)\s*\d+\b/i', $text ) === 1 ) {
				return null;
			}
			if ( substr_count( $text, '[[' ) > 3 || str_word_count( (string) preg_replace( '/\[\[[^\]]*\]\]/', '', $text ) ) < 1 ) {
				return null;
			}

			return $text;
		};

		$generalize = static function ( array $block, string $role, string $list, string $label, array &$notes, string $host = '', string $title = '' ) use ( &$walk, $words, $len, $set_text, $icon_name, $leaf_text, $shape, $generic ): array {
			$images  = 0;
			$slots   = array( 'text' => 0, 'image' => 0, 'icon' => 0, 'repeat' => 0, 'button' => 0, 'kept' => 0, 'dropped' => 0, 'tiles' => 0 );
			$needs   = array();
			$root    = $block;
			$in_rows = array(); // paths of the repeated items, so their words become row tokens

			// What an item holds: its words and its pictures, and whether it reads as a figure (a number and a word under it).
			$tally = static function ( array $x ) use ( &$tally ): array {
				$n = array( 'texts' => 0, 'pics' => 0, 'words' => 0, 'figure' => false );
				if ( in_array( (string) $x['blockName'], array( 'core/heading', 'core/paragraph', 'core/list-item', 'amr/span', 'core/button', 'core/details' ), true ) ) {
					++$n['texts'];
					$t           = trim( wp_strip_all_tags( (string) $x['innerHTML'] ) );
					$n['words'] += str_word_count( $t );
					$n['figure'] = $n['figure'] || preg_match( '/^[\$]?\d[\d,.]*\s*(\+|%|k|\/7|hr|min)?$/i', $t ) === 1;
				}
				if ( in_array( (string) $x['blockName'], array( 'core/image', 'dx/picture' ), true ) ) {
					++$n['pics'];
				}
				foreach ( (array) $x['innerBlocks'] as $y ) {
					$m = $tally( $y );
					$n['texts']  += $m['texts'];
					$n['pics']   += $m['pics'];
					$n['words']  += $m['words'];
					$n['figure']  = $n['figure'] || $m['figure'];
				}

				return $n;
			};
			/** A box's shape for grouping: its blocks and their nesting alone — two cards that differ by a utility class (the last without a border) are one kind of card. */
			$loose = static function ( array $b, int $depth = 0 ) use ( &$loose ): string {
				$kids = array();
				foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $c ) {
					if ( ! empty( $c['blockName'] ) && $depth < 6 ) {
						$kids[] = $loose( $c, $depth + 1 );
					}
				}

				return (string) $b['blockName'] . ( $kids ? '{' . implode( ',', $kids ) . '}' : '' );
			};
			/** The groups of children of one shape: shape => indexes (only children that are boxes: a card, a column, a list item, a link box). */
			$groups_of = static function ( array $b ) use ( $loose ): array {
				$groups = array();
				foreach ( $b['innerBlocks'] as $i => $c ) {
					if ( ! empty( $c['blockName'] ) && in_array( (string) $c['blockName'], array( 'core/group', 'core/column', 'core/list-item', 'amr/link-box', 'core/details' ), true ) ) {
						$groups[ $loose( $c ) ][] = $i;
					}
				}

				return array_filter( $groups, static fn( $idx ) => count( $idx ) >= 2 );
			};
			// Repeats: a parent whose children share one shape is a list, its first child the item. Columns that each hold the same cards are
			// one list of cards (a column for each), not a list of columns each with every card.
			/** The text blocks of a box, counted by kind: how its words are laid out, whatever boxes they sit in. */
			$leaf_kinds = static function ( array $x ) use ( &$leaf_kinds ): array {
				$out = array();
				if ( in_array( (string) ( $x['blockName'] ?? '' ), array( 'core/heading', 'core/paragraph', 'core/list-item', 'amr/span', 'core/button', 'dxai-ui/text', 'core/details' ), true ) ) {
					$out[ (string) $x['blockName'] ] = 1;
				}
				foreach ( (array) ( $x['innerBlocks'] ?? array() ) as $c ) {
					foreach ( is_array( $c ) ? $leaf_kinds( $c ) : array() as $k => $n ) {
						$out[ $k ] = ( $out[ $k ] ?? 0 ) + $n;
					}
				}
				ksort( $out );

				return $out;
			};
			$repeat = static function ( array &$b, string $path ) use ( &$repeat, $groups_of, $tally, $list, &$slots, &$in_rows, &$notes, $leaf_kinds ): void {
				$groups = $groups_of( $b );
				$done   = false;
				foreach ( $groups as $s => $idx ) {
					if ( $done ) {
						continue;
					}
					$item = $b['innerBlocks'][ $idx[0] ];
					$n    = $tally( $item );
					$name = $list;
					if ( $n['texts'] === 0 && $n['pics'] > 0 ) {
						$name = 'logos';
					} elseif ( $n['texts'] <= 2 && $n['words'] <= 10 && $n['figure'] ) {
						$name = 'stats';
					}
					// Columns that each hold the same cards: the columns stay as they are (the grid is theirs), and the cards are one list dealt
					// across them — the first column takes the first, fourth, seventh row, the second the second, fifth, eighth, and so on.
					if ( (string) $item['blockName'] === 'core/column' && $groups_of( $item ) !== array() ) {
						$cols  = count( $idx );
						$total = 0;
						foreach ( $idx as $k => $ci ) {
							$col   = &$b['innerBlocks'][ $ci ];
							$inner = $groups_of( $col );
							$iidx  = reset( $inner );
							$total += count( $iidx );
							for ( $j = count( $iidx ) - 1; $j >= 1; $j-- ) {
								Block_Tree::drop( $col, $iidx[ $j ] );
							}
							$col['innerBlocks'][ $iidx[0] ]['attrs']['dxaiRepeat'] = $name;
							$col['innerBlocks'][ $iidx[0] ]['attrs']['dxaiSlice']  = array( $k, $cols );
							$in_rows[ $path . '.' . $ci . '.' . $iidx[0] ]          = $name;
							unset( $col );
						}
						// As many cards as the team's grid held.
						foreach ( $idx as $ci ) {
							foreach ( $b['innerBlocks'][ $ci ]['innerBlocks'] as &$card ) {
								if ( isset( $card['attrs']['dxaiRepeat'] ) ) {
									$card['attrs']['dxaiCount'] = min( 12, $total );
								}
							}
							unset( $card );
						}
						++$slots['repeat'];
						$notes[] = sprintf( '%s: %d columns of cards → one list "%s" dealt across them (%d cards)', $path, $cols, $name, $total );
						$done    = true;
						continue;
					}
					// Rows of the same list drawn a little another way (the last phases of a process in one more box): their words are laid
					// out as the item's are — the same text blocks — so they are rows of the list, not words of the section, and go with
					// the others (left behind, their slots were filled with provisional words beside the list).
					$item_words = $leaf_kinds( $b['innerBlocks'][ $idx[0] ] );
					$also       = array();
					foreach ( $b['innerBlocks'] as $j => $sib ) {
						if ( ! in_array( $j, $idx, true ) && ! empty( $sib['blockName'] ) && in_array( (string) $sib['blockName'], array( 'core/group', 'core/column', 'amr/link-box' ), true ) && $item_words !== array() && $leaf_kinds( $sib ) === $item_words ) {
							$also[] = $j;
						}
					}
					$b['innerBlocks'][ $idx[0] ]['attrs']['dxaiRepeat'] = $name;
					// As many items as the team's section held, so the row keeps its shape.
					$b['innerBlocks'][ $idx[0] ]['attrs']['dxaiCount']  = min( 12, count( $idx ) + count( $also ) );
					if ( $also !== array() ) {
						foreach ( array_reverse( $also ) as $j ) {
							Block_Tree::drop( $b, $j );
						}
						// The items' places moved where a row of another shape stood before them.
						$idx = array_values( array_map( static fn( $i ) => $i - count( array_filter( $also, static fn( $j ) => $j < $i ) ), $idx ) );
						$notes[] = sprintf( '%s: %d more rows of the list in another shape went with it', $path, count( $also ) );
					}
					$in_rows[ $path . '.' . $idx[0] ]                   = $name;
					// A logo is drawn at a logo's size wherever the layout puts it (the theme sized the team's by its own rules): the item says it is one.
					if ( $name === 'logos' ) {
						$logo = &$b['innerBlocks'][ $idx[0] ];
						$logo['attrs']['className'] = trim( (string) ( $logo['attrs']['className'] ?? '' ) . ' dxai-logo' );
						$mark = static fn( string $s ): string => preg_match( '/^(\s*<[a-z][a-z0-9]*\b[^>]*\sclass=")/i', $s ) ? (string) preg_replace( '/^(\s*<[a-z][a-z0-9]*\b[^>]*\sclass=")/i', '$1dxai-logo ', $s, 1 ) : (string) preg_replace( '/^(\s*<[a-z][a-z0-9]*)\b/i', '$1 class="dxai-logo"', $s, 1 );
						$logo['innerHTML']          = $mark( (string) $logo['innerHTML'] );
						foreach ( $logo['innerContent'] as $k => $part ) {
							if ( is_string( $part ) && trim( $part ) !== '' ) {
								$logo['innerContent'][ $k ] = $mark( $part );
								break;
							}
						}
						unset( $logo );
					}
					++$slots['repeat'];
					for ( $k = count( $idx ) - 1; $k >= 1; $k-- ) {
						Block_Tree::drop( $b, $idx[ $k ] );
					}
					$notes[] = sprintf( '%s: %d items of one shape at %s → a list "%s"', $path, count( $idx ), $path . '.' . $idx[0], $name );
					$done    = true;
				}
				foreach ( $b['innerBlocks'] as $i => &$c ) {
					if ( empty( $c['blockName'] ) ) {
						continue;
					}
					// Inside an item nothing is repeated again (its ticks are its own), except a list of ticks, which is the item's list of points.
					if ( isset( $c['attrs']['dxaiRepeat'] ) ) {
						continue;
					}
					$repeat( $c, $path . '.' . $i );
				}
				unset( $c );
			};
			$repeat( $root, '0' );
			// Rows of cards side by side (three column blocks, two cards each): the lists in them are one list dealt across them, so the six
			// cards are six and not the same two three times. Each row takes its share by turn.
			$deal = static function ( array &$b ) use ( &$deal ): void {
				$holders = array(); // list name => array( child index => path to the marked block inside it )
				$find    = static function ( array $x, array $path ) use ( &$find ): ?array {
					if ( isset( $x['attrs']['dxaiRepeat'] ) ) {
						return array( (string) $x['attrs']['dxaiRepeat'], $path );
					}
					foreach ( (array) ( $x['innerBlocks'] ?? array() ) as $i => $c ) {
						if ( ! empty( $c['blockName'] ) ) {
							$hit = $find( $c, array_merge( $path, array( $i ) ) );
							if ( $hit !== null ) {
								return $hit;
							}
						}
					}

					return null;
				};
				foreach ( $b['innerBlocks'] as $i => $c ) {
					if ( empty( $c['blockName'] ) || isset( $c['attrs']['dxaiRepeat'] ) ) {
						continue;
					}
					$hit = $find( $c, array( $i ) );
					if ( $hit !== null && ! isset( $hit[1] ) ) {
						continue;
					}
					if ( $hit !== null ) {
						$holders[ $hit[0] ][ $i ] = $hit[1];
					}
				}
				foreach ( $holders as $name => $paths ) {
					if ( count( $paths ) < 2 ) {
						continue;
					}
					$n = count( $paths );
					$k = 0;
					foreach ( $paths as $path ) {
						$marked = &Block_Tree::at( $b, $path );
						if ( ! isset( $marked['attrs']['dxaiSlice'] ) ) {
							$marked['attrs']['dxaiSlice'] = array( $k, $n );
						}
						unset( $marked );
						++$k;
					}
				}
				foreach ( $b['innerBlocks'] as &$c ) {
					if ( ! empty( $c['blockName'] ) && ! isset( $c['attrs']['dxaiRepeat'] ) ) {
						$deal( $c );
					}
				}
				unset( $c );
			};
			$deal( $root );

			$row_of = static function ( string $path ) use ( &$in_rows ): array {
				foreach ( $in_rows as $p => $name ) {
					if ( $path === $p || str_starts_with( $path, $p . '.' ) ) {
						return array( (string) $name, (string) $p );
					}
				}

				return array( '', '' );
			};
			// The words of a repeated item, named as a row is read from a Home (Layouts::rows): its heading is the title (without one, its
			// shortest line of up to ten words); a number or a label before the title is the eyebrow; the longest of the rest is the text,
			// the others the meta; the points of a list are its points. Named before anything is replaced, from the whole item.
			$row_plan = array(); // leaf path => the row token for its words
			$numeric  = '/^(\d{1,2}|0\d|step\s*\d+|#\d+|\d{1,2}\.)$/i';
			foreach ( array_keys( $in_rows ) as $item_path ) {
				$leaves = array();
				$walk(
					$root,
					static function ( array &$b, string $path ) use ( &$leaves, $item_path, $leaf_text, $words ): void {
						$name = (string) $b['blockName'];
						if ( ( $path === $item_path || str_starts_with( $path, $item_path . '.' ) ) && in_array( $name, $leaf_text, true ) && $name !== 'core/button' ) {
							$leaves[ $path ] = array(
								'name'  => $name,
								'words' => $words( (string) $b['innerHTML'] ),
								'text'  => trim( wp_strip_all_tags( html_entity_decode( (string) $b['innerHTML'], ENT_QUOTES, 'UTF-8' ) ) ),
							);
						}
					}
				);
				$title = '';
				foreach ( $leaves as $p => $l ) {
					if ( $l['name'] === 'core/heading' || $l['name'] === 'core/details' ) {
						$title = $p;
						break;
					}
				}
				if ( $title === '' ) {
					$best = 0;
					foreach ( $leaves as $p => $l ) {
						if ( $l['name'] !== 'core/list-item' && $l['words'] >= 1 && $l['words'] <= 10 && preg_match( $numeric, $l['text'] ) !== 1 && ( $title === '' || $l['words'] < $best ) ) {
							$title = $p;
							$best  = $l['words'];
						}
					}
				}
				// Without a short line either, the first line is the title (a point of a list is one line: the row's title is all of it).
				if ( $title === '' ) {
					foreach ( $leaves as $p => $l ) {
						if ( $l['name'] !== 'core/list-item' && $l['words'] >= 1 && preg_match( $numeric, $l['text'] ) !== 1 ) {
							$title = $p;
							break;
						}
					}
				}
				$before  = true;
				$eyebrow = false;
				$points  = 0;
				$rest    = array();
				foreach ( $leaves as $p => $l ) {
					if ( $p === $title ) {
						$row_plan[ $p ] = '{{row:title}}';
						$before         = false;
						continue;
					}
					if ( $l['name'] === 'core/list-item' ) {
						$row_plan[ $p ] = '{{row:point' . ++$points . '|}}';
						continue;
					}
					if ( $before && ! $eyebrow && ( $l['name'] === 'amr/span' || $l['words'] <= 3 || preg_match( $numeric, $l['text'] ) === 1 ) ) {
						$row_plan[ $p ] = '{{row:eyebrow|}}';
						$eyebrow        = true;
						continue;
					}
					$rest[ $p ] = $l['words'];
				}
				arsort( $rest );
				foreach ( array_keys( $rest ) as $n => $p ) {
					$row_plan[ $p ] = $n === 0 ? '{{row:text|}}' : '{{row:meta|}}';
				}
			}
			// A short line right before a heading ("What we do"), outside the rows, is the section's eyebrow: it gets the eyebrow slot and the mark
			// the Home's eyebrow look goes on (Library_Style).
			$mark_eyebrows = static function ( array &$b ) use ( &$mark_eyebrows, $words ): void {
				$kids = $b['innerBlocks'];
				foreach ( $kids as $i => $c ) {
					$next = $kids[ $i + 1 ] ?? null;
					$n    = in_array( (string) ( $c['blockName'] ?? '' ), array( 'core/paragraph', 'amr/span', 'dxai-ui/text' ), true ) ? $words( (string) $c['innerHTML'] ) : 0;
					if ( $n >= 1 && $n <= 4 && is_array( $next ) && (string) ( $next['blockName'] ?? '' ) === 'core/heading' ) {
						$b['innerBlocks'][ $i ]['attrs']['dxaiEyebrow'] = true;
					}
					if ( ! empty( $c['blockName'] ) ) {
						$mark_eyebrows( $b['innerBlocks'][ $i ] );
					}
				}
			};
			$mark_eyebrows( $root );
			$bg_at = array(); // path => the ground a block stands on ('dark' or 'light'), its own or the nearest above it
			$walk(
				$root,
				static function ( array &$b, string $path ) use ( &$slots, &$needs, &$images, $words, $len, $set_text, $icon_name, $leaf_text, $row_of, $row_plan, &$notes, &$bg_at, &$in_rows, $generic, $host, $title ): void {
					$name = (string) $b['blockName'];
					$a    = is_array( $b['attrs'] ?? null ) ? $b['attrs'] : array();
					unset( $b['attrs']['anchor'] );
					// Classes that are a script's (the theme reveals a section as it scrolls into view, slides a row), one site's own (a brand
					// gradient, a shadow named for a company) or a tool's: they go, the block keeps the rest of its classes.
					if ( isset( $b['attrs']['className'] ) ) {
						// …and a state a script set on the page it was read from (the open question, the active tab): the layout starts at rest.
						$kept = array_values( array_filter( preg_split( '/\s+/', (string) $b['attrs']['className'] ), static fn( $c ) => $c !== '' && preg_match( '/^(reveal(-delay-\d+)?|in|mobile-slider|clean-style|secondary-new|aj-[\w-]+|cr-[\w-]+|has-custom-css|[\w-]*\[[^\]]*\]|is-open|is-active|active|open|[\w-]+--(?:expanded|open|active|default-open|current))$/', $c ) !== 1 ) );
						$gone = array_diff( preg_split( '/\s+/', (string) $b['attrs']['className'] ), $kept );
						if ( $gone !== array() ) {
							$b['attrs']['className'] = implode( ' ', $kept );
							$strip                   = static fn( string $s ): string => (string) preg_replace_callback( '/^(\s*<[a-z][a-z0-9]*\b[^>]*\sclass=")([^"]*)(")/i', static fn( array $m ): string => $m[1] . trim( (string) preg_replace( '/\s+/', ' ', implode( ' ', array_diff( preg_split( '/\s+/', $m[2] ), $gone ) ) ) ) . $m[3], $s, 1 );
							$b['innerHTML']          = $strip( (string) $b['innerHTML'] );
							foreach ( $b['innerContent'] as $k => $part ) {
								if ( is_string( $part ) && trim( $part ) !== '' ) {
									$b['innerContent'][ $k ] = $strip( $part );
									break;
								}
							}
							if ( $b['attrs']['className'] === '' ) {
								unset( $b['attrs']['className'] );
							}
						}
					}
					// A picture behind a block is the team's: the colour behind stays, the picture does not (a box that was a photograph counts
					// as a picture dropped: three of them make a gallery, not a layout).
					if ( isset( $b['attrs']['style']['background'] ) ) {
						if ( isset( $b['attrs']['style']['background']['backgroundImage'] ) ) {
							++$slots['dropped'];
						}
						unset( $b['attrs']['style']['background'] );
						$b['innerHTML'] = (string) preg_replace( '/background-image:[^;"]*;?/i', '', (string) $b['innerHTML'] );
						foreach ( $b['innerContent'] as $k => $part ) {
							if ( is_string( $part ) ) {
								$b['innerContent'][ $k ] = (string) preg_replace( '/background-image:[^;"]*;?/i', '', $part );
							}
						}
					}
					// A colour as a number is one brand's: the theme's named colours stay (the classes say them), the number goes. White, black
					// and the greys between them (with or without transparency) are nobody's brand: they stay, as the team set them.
					// The first colour in a value (a colour, or the first stop of a gradient), as red, green, blue; null when there is none.
					$rgb_of = static function ( string $value ): ?array {
						$v = strtolower( trim( $value ) );
						if ( preg_match( '/#([0-9a-f]{3,8})\b/', $v, $m ) ) {
							$h = $m[1];
							if ( strlen( $h ) === 3 || strlen( $h ) === 4 ) {
								$h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
							}

							return array( (int) hexdec( substr( $h, 0, 2 ) ), (int) hexdec( substr( $h, 2, 2 ) ), (int) hexdec( substr( $h, 4, 2 ) ) );
						}
						if ( preg_match( '/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $v, $m ) ) {
							return array( (int) $m[1], (int) $m[2], (int) $m[3] );
						}

						return null;
					};
					$branded = static function ( string $value ) use ( $rgb_of ): bool {
						$v = strtolower( trim( $value ) );
						if ( str_contains( $v, 'gradient(' ) || str_contains( $v, 'url(' ) ) {
							return true;
						}
						$rgb = $rgb_of( $v );
						if ( $rgb === null ) {
							return true;
						}

						// A grey: the channels are the same, or as near as makes no tint (a site's own off-white, #f4f5fb, is a tint of its blue).
						return max( $rgb ) - min( $rgb ) > 3;
					};
					$is_colour = static fn( string $value ): bool => preg_match( '/#[0-9a-f]{3,8}|rgba?\(|hsla?\(|gradient\(|url\(/i', $value ) === 1;
					// A class on the block's own element (its first tag), for a background given as the theme's preset.
					$add_class = static function ( array &$b, string $classes ): void {
						$put = static function ( string $html ) use ( $classes ): string {
							$new = (string) preg_replace( '/^(\s*<[a-z][a-z0-9]*\b[^>]*?\sclass=")/i', '$1' . $classes . ' ', $html, 1, $n );

							return $n > 0 ? $new : (string) preg_replace( '/^(\s*<[a-z][a-z0-9]*)/i', '$1 class="' . $classes . '"', $html, 1 );
						};
						$b['innerHTML'] = $put( (string) $b['innerHTML'] );
						foreach ( $b['innerContent'] as $kk => $part ) {
							if ( is_string( $part ) ) {
								$b['innerContent'][ $kk ] = $put( $part );
								break;
							}
						}
					};
					// A class of the block's own (className), written in its tag too, as the editor writes it.
					$add_own_class = static function ( array &$b, string $class ) use ( $add_class ): void {
						$have = (string) ( $b['attrs']['className'] ?? '' );
						if ( preg_match( '/(^|\s)' . preg_quote( $class, '/' ) . '(\s|$)/', $have ) === 1 ) {
							return;
						}
						$b['attrs']['className'] = trim( $have . ' ' . $class );
						$add_class( $b, $class );
					};
					// A block's own CSS (the "Additional CSS" setting) that paints a colour, a gradient or a picture is one site's: it goes.
					if ( isset( $b['attrs']['style']['css'] ) && $is_colour( (string) $b['attrs']['style']['css'] ) ) {
						unset( $b['attrs']['style']['css'] );
						if ( $b['attrs']['style'] === array() ) {
							unset( $b['attrs']['style'] );
						}
					}
					if ( isset( $b['attrs']['style']['color'] ) ) {
						foreach ( array( 'background', 'text', 'gradient' ) as $k ) {
							if ( isset( $b['attrs']['style']['color'][ $k ] ) && $is_colour( (string) $b['attrs']['style']['color'][ $k ] ) && $branded( (string) $b['attrs']['style']['color'][ $k ] ) ) {
								$value = (string) $b['attrs']['style']['color'][ $k ];
								unset( $b['attrs']['style']['color'][ $k ] );
								$prop = $k === 'text' ? 'color' : 'background(?:-color|-image)?';
								// The whole declaration, to its end (a gradient holds colours in brackets of its own).
								$re   = '/(?<![\w-])' . $prop . ':[^;"]*;?/i';
								$b['innerHTML'] = (string) preg_replace( $re, '', (string) $b['innerHTML'], 1 );
								foreach ( $b['innerContent'] as $kk => $part ) {
									if ( is_string( $part ) ) {
										$b['innerContent'][ $kk ] = (string) preg_replace( $re, '', $part, 1 );
										break;
									}
								}
								// A dark band of one brand is a dark band of the theme (its words are light, and stay readable); a light tint just goes.
								$rgb = $k !== 'text' ? $rgb_of( $value ) : null;
								if ( $rgb !== null && ( 0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2] ) / 255 < 0.5 && ! isset( $b['attrs']['backgroundColor'] ) ) {
									$b['attrs']['backgroundColor'] = 'primary-dark';
									$add_class( $b, 'has-primary-dark-background-color' . ( str_contains( (string) $b['innerHTML'], 'has-background' ) ? '' : ' has-background' ) );
								}
							}
						}
						if ( $b['attrs']['style']['color'] === array() ) {
							unset( $b['attrs']['style']['color'] );
						}
					}
					// A border in a brand's colour: the colour goes (the border stays, in the colour the theme gives a border).
					if ( isset( $b['attrs']['style']['border'] ) && is_array( $b['attrs']['style']['border'] ) ) {
						$gone = false;
						if ( isset( $b['attrs']['style']['border']['color'] ) && $is_colour( (string) $b['attrs']['style']['border']['color'] ) && $branded( (string) $b['attrs']['style']['border']['color'] ) ) {
							unset( $b['attrs']['style']['border']['color'] );
							$gone = true;
						}
						foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
							if ( isset( $b['attrs']['style']['border'][ $side ]['color'] ) && $is_colour( (string) $b['attrs']['style']['border'][ $side ]['color'] ) && $branded( (string) $b['attrs']['style']['border'][ $side ]['color'] ) ) {
								unset( $b['attrs']['style']['border'][ $side ]['color'] );
								$gone = true;
							}
						}
						if ( $gone ) {
							$strip = static fn( string $s ): string => (string) preg_replace( array( '/(?<![\w-])border(?:-(?:top|right|bottom|left))?-color:[^;"]*;?/i', '/\bhas-border-color\b\s?/' ), '', $s );
							$b['innerHTML'] = $strip( (string) $b['innerHTML'] );
							foreach ( $b['innerContent'] as $kk => $part ) {
								if ( is_string( $part ) ) {
									$b['innerContent'][ $kk ] = $strip( $part );
									break;
								}
							}
						}
					}
					// The colours of a block's elements (its links, its headings, its buttons): a brand's go the same way; the theme's presets stay.
					if ( isset( $b['attrs']['style']['elements'] ) && is_array( $b['attrs']['style']['elements'] ) ) {
						foreach ( $b['attrs']['style']['elements'] as $el => $spec ) {
							foreach ( array( 'text', 'background', 'gradient' ) as $k ) {
								if ( isset( $spec['color'][ $k ] ) && $is_colour( (string) $spec['color'][ $k ] ) && $branded( (string) $spec['color'][ $k ] ) ) {
									unset( $b['attrs']['style']['elements'][ $el ]['color'][ $k ] );
								}
							}
							if ( isset( $b['attrs']['style']['elements'][ $el ]['color'] ) && $b['attrs']['style']['elements'][ $el ]['color'] === array() ) {
								unset( $b['attrs']['style']['elements'][ $el ]['color'] );
							}
							if ( $b['attrs']['style']['elements'][ $el ] === array() ) {
								unset( $b['attrs']['style']['elements'][ $el ] );
							}
						}
						if ( $b['attrs']['style']['elements'] === array() ) {
							unset( $b['attrs']['style']['elements'] );
						}
					}
					// The ground the block stands on: its own background (a dark preset, a cover, a dark custom colour), else the nearest one above
					// it; the page itself is light. A theme's colour means a different thing on every site (secondary is a dark grey on one site and
					// an off-white on another), so words in such a colour take the colour that reads on their ground: the theme's own text on a light
					// ground, the colour made for a dark one ("on-accent") on a dark ground. White words on a light ground, and dark words on a dark
					// ground, go the same way.
					$ground    = '';
					$lum       = static fn( array $rgb ): float => ( 0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2] ) / 255;
					$bg_slug   = (string) ( $b['attrs']['backgroundColor'] ?? '' );
					$bg_custom = (string) ( $b['attrs']['style']['color']['background'] ?? '' );
					if ( $name === 'core/cover' ) {
						$ground = 'dark';
					} elseif ( $bg_slug !== '' ) {
						$ground = in_array( $bg_slug, array( 'primary-dark', 'primary', 'accent', 'black', 'ink', 'foreground' ), true ) ? 'dark' : 'light';
					} elseif ( $bg_custom !== '' && $rgb_of( $bg_custom ) !== null ) {
						$ground = $lum( (array) $rgb_of( $bg_custom ) ) < 0.5 ? 'dark' : 'light';
					}
					if ( $ground === '' ) {
						$ground = 'light';
						for ( $p = $path; ( $cut = strrpos( $p, '.' ) ) !== false; ) {
							$p = substr( $p, 0, $cut );
							if ( isset( $bg_at[ $p ] ) ) {
								$ground = $bg_at[ $p ];
								break;
							}
						}
					}
					$bg_at[ $path ] = $ground;
					$text_slug = static function ( array &$b, string $old, string $new ): void {
						unset( $b['attrs']['textColor'] );
						if ( $new !== '' ) {
							$b['attrs']['textColor'] = $new;
						}
						$swap = static function ( string $html ) use ( $old, $new ): string {
							if ( $old !== '' ) {
								$html = (string) preg_replace( '/\bhas-' . preg_quote( $old, '/' ) . '-color\b\s?/', $new !== '' ? 'has-' . $new . '-color ' : '', $html, 1 );
							}
							if ( $new === '' ) {
								$html = (string) preg_replace( '/\bhas-text-color\b\s?/', '', $html, 1 );
							} elseif ( ! str_contains( $html, 'has-text-color' ) ) {
								$html = (string) preg_replace( '/^(\s*<[a-z][a-z0-9]*\b[^>]*?\sclass=")/i', '$1has-text-color ', $html, 1 );
							}

							return $html;
						};
						$b['innerHTML'] = $swap( (string) $b['innerHTML'] );
						foreach ( $b['innerContent'] as $kk => $part ) {
							if ( is_string( $part ) ) {
								$b['innerContent'][ $kk ] = $swap( $part );
								break;
							}
						}
					};
					$slug = (string) ( $b['attrs']['textColor'] ?? '' );
					if ( $slug !== '' ) {
						$light_slug = in_array( $slug, array( 'on-accent', 'white', 'background' ), true );
						$dark_slug  = in_array( $slug, array( 'primary-dark', 'black', 'ink', 'foreground' ), true );
						$safe       = $light_slug || $dark_slug || in_array( $slug, array( 'primary', 'accent' ), true );
						if ( ! $safe ) {
							$text_slug( $b, $slug, $ground === 'dark' ? 'on-accent' : '' );
						} elseif ( $light_slug && $ground !== 'dark' ) {
							$text_slug( $b, $slug, '' );
						} elseif ( $dark_slug && $ground === 'dark' ) {
							$text_slug( $b, $slug, 'on-accent' );
						}
					}
					$custom_text = (string) ( $b['attrs']['style']['color']['text'] ?? '' );
					if ( $custom_text !== '' && $rgb_of( $custom_text ) !== null ) {
						$l = $lum( (array) $rgb_of( $custom_text ) );
						if ( ( $ground === 'dark' && $l < 0.4 ) || ( $ground !== 'dark' && $l > 0.6 ) ) {
							unset( $b['attrs']['style']['color']['text'] );
							if ( $b['attrs']['style']['color'] === array() ) {
								unset( $b['attrs']['style']['color'] );
							}
							$re = '/(?<![\w-])color:[^;"]*;?/i';
							$b['innerHTML'] = (string) preg_replace( $re, '', (string) $b['innerHTML'], 1 );
							foreach ( $b['innerContent'] as $kk => $part ) {
								if ( is_string( $part ) ) {
									$b['innerContent'][ $kk ] = (string) preg_replace( $re, '', $part, 1 );
									break;
								}
							}
							$text_slug( $b, '', $ground === 'dark' ? 'on-accent' : '' );
						}
					}
					[ $in_list, $item ] = $row_of( $path );
					$in_row             = $in_list !== '';
					// The root of a repeated item that is a box (a background, a border, a shadow, rounded corners) is a card: the Home's card
					// look goes on it (Library_Style).
					if ( $in_row && $path === $item && in_array( $name, array( 'core/group', 'core/column', 'amr/link-box' ), true ) ) {
						$st = (array) ( $b['attrs']['style'] ?? array() );
						if ( isset( $b['attrs']['backgroundColor'] ) || isset( $st['color']['background'] ) || isset( $st['border'] ) || isset( $st['shadow'] ) || preg_match( '/(^|\s)(bg-|shadow|rounded|border)/', (string) ( $b['attrs']['className'] ?? '' ) ) === 1 ) {
							$add_own_class( $b, 'dxai-lib-card' );
						}
					}
					// Inside a grid of repeated items but not in an item (the site's featured first card, say): a picture there is the site's.
					$in_grid = false;
					if ( ! $in_row ) {
						foreach ( array_keys( $in_rows ) as $ip ) {
							$cut = strrpos( $ip, '.' );
							if ( $cut !== false && str_starts_with( $path, substr( $ip, 0, $cut ) . '.' ) ) {
								$in_grid = true;
								break;
							}
						}
					}
					// A picture laid under a section (positioned, faded, filling it, or named a background) is a decoration of the site, not a slot.
					// A picture that fills its box and is not faded is a tile of photographs (a crew, a gallery): the box is nothing without it.
					$tile  = static fn( array $attrs ): bool => preg_match( '/(^|\s)inset-0(\s|$)/', (string) ( $attrs['className'] ?? '' ) ) === 1 && preg_match( '/opacity-\d+|z-negative/', (string) ( $attrs['className'] ?? '' ) ) !== 1;
					$decor = static fn( array $attrs ): bool => preg_match( '/(^|\s)(absolute|z-negative|inset-0|full-w-figure|opacity-\d+)(\s|$)/', (string) ( $attrs['className'] ?? '' ) ) === 1 || preg_match( '/background/i', (string) ( $attrs['metadata']['name'] ?? '' ) ) === 1;
					// Words.
					if ( in_array( $name, $leaf_text, true ) ) {
						$n    = $words( (string) $b['innerHTML'] );
						$kind = match ( $name ) {
							'core/heading'   => 'h' . (int) ( $a['level'] ?? 2 ),
							'core/paragraph' => 'p',
							'core/list-item' => 'li',
							'amr/span'       => 'label',
							'dxai-ui/text'   => 'p',
							'core/details'   => 'h3',
							default          => 'button',
						};
						$token = '{{t:' . $kind . '-' . $len( $n ) . '}}';
						$href  = null;
						// The section's eyebrow: the label slot, and the mark for the Home's eyebrow look.
						$eyebrow = ! empty( $b['attrs']['dxaiEyebrow'] );
						unset( $b['attrs']['dxaiEyebrow'] );
						if ( ! $in_row && ( $eyebrow || $kind === 'label' ) ) {
							$token = '{{t:label-s}}';
							$add_own_class( $b, 'dxai-lib-eyebrow' );
						}
						if ( $name === 'core/button' ) {
							$url = (string) ( $a['url'] ?? '' );
							if ( preg_match( '/^tel:/i', $url ) || preg_match( '/\d{3}[\s.\-]\d{3}[\s.\-]\d{4}/', wp_strip_all_tags( (string) $b['innerHTML'] ) ) ) {
								$href    = '{{phone_url}}';
								$token   = '{{t:call}}';
								$needs[] = 'phone';
							} elseif ( preg_match( '/^mailto:/i', $url ) ) {
								$href    = 'mailto:{{email}}';
								$token   = '{{email}}';
								$needs[] = 'email';
							} else {
								$href  = $in_row ? '{{row:url}}' : '{{contact_url}}';
								$token = $in_row ? '{{row:label|Learn more}}' : '{{t:button}}';
							}
							++$slots['button'];
						} elseif ( $in_row ) {
							$token = $row_plan[ $path ] ?? '{{row:text|}}';
						} else {
							++$slots['text'];
						}
						// The team's words of the slot, kept as its first words where they say nothing of one site.
						if ( ! $in_row && str_starts_with( $token, '{{t:' ) && ! str_contains( $token, '|' ) && $token !== '{{t:call}}' ) {
							$kept = $generic( (string) $b['innerHTML'], $host, $title );
							if ( $kept !== null ) {
								$token = substr( $token, 0, -2 ) . '|' . $kept . '}}';
								++$slots['kept'];
							}
						}
						if ( ! $set_text( $b, $token, $href ) ) {
							$notes[] = 'could not slot the words of ' . $name . ' at ' . $path;
						}
						// A link in the words went with them; a link box around them is handled by its own attribute.
					}
					if ( $name === 'core/list-item' && ! empty( $b['innerBlocks'] ) ) {
						// A nested list inside an item: its items are words of their own, slotted as they come.
						$notes[] = 'nested list at ' . $path;
					}
					// A box that is a link: to the row's page in a list, else to the page the maker names.
					if ( $name === 'amr/link-box' && isset( $a['url'] ) ) {
						$to = $in_row ? '{{row:url}}' : ( preg_match( '/^tel:/i', (string) $a['url'] ) ? '{{phone_url}}' : '{{contact_url}}' );
						if ( $to === '{{phone_url}}' ) {
							$needs[] = 'phone';
						}
						$b['attrs']['url'] = $to;
						$b['innerHTML']    = (string) preg_replace( '/\shref="[^"]*"/', ' href="' . $to . '"', (string) $b['innerHTML'], 1 );
						foreach ( $b['innerContent'] as $k => $part ) {
							if ( is_string( $part ) && str_contains( $part, 'href=' ) ) {
								$b['innerContent'][ $k ] = (string) preg_replace( '/\shref="[^"]*"/', ' href="' . $to . '"', $part, 1 );
								break;
							}
						}
					}
					// Pictures: a small one is a drawing (the plugin's own), a large one a slot for the Home's.
					if ( $name === 'core/image' ) {
						// A size the block was given (a logo 60px tall) is written into the picture itself, where the editor would have written it.
						$size = array();
						foreach ( array( 'width', 'height' ) as $dim ) {
							if ( isset( $a[ $dim ] ) && is_string( $a[ $dim ] ) && preg_match( '/^(auto|\d+(?:px|%|rem|em|vw))$/', $a[ $dim ] ) === 1 && ! preg_match( '/\b' . $dim . '\s*:/', (string) $b['innerHTML'] ) ) {
								$size[] = $dim . ':' . $a[ $dim ];
							}
						}
						if ( $size !== array() ) {
							$put = static fn( string $s ): string => preg_match( '/<img\b[^>]*\sstyle="/', $s ) ? (string) preg_replace( '/(<img\b[^>]*\sstyle=")([^"]*)(")/i', '$1$2;' . implode( ';', $size ) . '$3', $s, 1 ) : (string) preg_replace( '/<img\b/i', '<img style="' . implode( ';', $size ) . '"', $s, 1 );
							$b['innerHTML'] = $put( (string) $b['innerHTML'] );
							foreach ( $b['innerContent'] as $k => $part ) {
								if ( is_string( $part ) ) {
									$b['innerContent'][ $k ] = $put( $part );
								}
							}
						}
						$w     = (int) ( $a['width'] ?? 0 );
						$src   = '';
						$alt   = '';
						if ( preg_match( '/<img\b[^>]*\ssrc="([^"]*)"/i', (string) $b['innerHTML'], $m ) ) {
							$src = $m[1];
						}
						if ( preg_match( '/<img\b[^>]*\salt="([^"]*)"/i', (string) $b['innerHTML'], $m ) ) {
							$alt = $m[1];
						}
						$small = ( $w > 0 && $w <= 96 ) || preg_match( '/\.svg(\?|$)/i', $src ) || preg_match( '/\bicon\b/i', $src . ' ' . (string) ( $a['className'] ?? '' ) );
						if ( $in_list === 'logos' ) {
							// A row of logos: each is the row's picture (the Home's trust logos).
							$swap = static fn( string $s ): string => (string) preg_replace( array( '/\ssrc="[^"]*"/i', '/\salt="[^"]*"/i', '/\bwp-image-\d+/', '/\ssrcset="[^"]*"/i', '/\ssizes="[^"]*"/i' ), array( ' src="{{row:image_url}}"', ' alt="{{row:image_alt|}}"', 'wp-image-{{row:image_id|0}}', '', '' ), $s );
							$b['innerHTML'] = $swap( (string) $b['innerHTML'] );
							foreach ( $b['innerContent'] as $k => $part ) {
								if ( is_string( $part ) ) {
									$b['innerContent'][ $k ] = $swap( $part );
								}
							}
							$b['attrs']['id'] = '{{row:image_id|0}}';
							unset( $b['attrs']['linkDestination'], $b['attrs']['href'] );
						} elseif ( $small ) {
							$icon = $in_row ? '{{row:icon|' . $icon_name( $src, $alt ) . '}}' : $icon_name( $src, $alt );
							$b['attrs']['dxaiIcon'] = $icon;
							$swap = static fn( string $s ): string => (string) preg_replace( array( '/\ssrc="[^"]*"/i', '/\salt="[^"]*"/i', '/\bwp-image-\d+/', '/\ssrcset="[^"]*"/i', '/\ssizes="[^"]*"/i' ), array( ' src="{{icon_url:' . $icon . '}}"', ' alt=""', 'wp-image-{{icon_id:' . $icon . '}}', '', '' ), $s );
							$b['innerHTML'] = $swap( (string) $b['innerHTML'] );
							foreach ( $b['innerContent'] as $k => $part ) {
								if ( is_string( $part ) ) {
									$b['innerContent'][ $k ] = $swap( $part );
								}
							}
							unset( $b['attrs']['id'] );
							++$slots['icon'];
						} elseif ( $in_row || $in_grid || $decor( $a ) ) {
							// A photograph in every card is the site's, and so is one in a featured card beside them, and a picture laid under a
							// section (positioned, faded, named a background): the card and the section do without.
							$b['attrs']['dxaiDrop'] = true;
							++$slots['dropped'];
							if ( ! $in_row && $tile( $a ) ) {
								++$slots['tiles'];
							}
						} else {
							++$images;
							$n2   = $images;
							$swap = static fn( string $s ): string => (string) preg_replace( array( '/\ssrc="[^"]*"/i', '/\salt="[^"]*"/i', '/\bwp-image-\d+/', '/\ssrcset="[^"]*"/i', '/\ssizes="[^"]*"/i' ), array( ' src="{{image_url:' . $n2 . '}}"', ' alt="{{image_alt:' . $n2 . '|}}"', 'wp-image-{{image_id:' . $n2 . '}}', '', '' ), $s );
							$b['innerHTML'] = $swap( (string) $b['innerHTML'] );
							foreach ( $b['innerContent'] as $k => $part ) {
								if ( is_string( $part ) ) {
									$b['innerContent'][ $k ] = $swap( $part );
								}
							}
							$b['attrs']['id']     = '{{image_id:' . $n2 . '}}';
							$b['attrs']['dxaiIf'] = array( 'image_url:' . $n2 );
							unset( $b['attrs']['linkDestination'], $b['attrs']['href'] );
							++$slots['image'];
						}
					}
					if ( $name === 'dx/picture' ) {
						if ( $in_list === 'logos' ) {
							$b['attrs']['imageUrl'] = '{{row:image_url}}';
							$b['attrs']['imageId']  = '{{row:image_id|0}}';
							$b['attrs']['alt']      = '{{row:image_alt|}}';
							foreach ( array( 'mobileImageId', 'mobileImageUrl', 'mobileImageWidth', 'mobileImageHeight', 'priority' ) as $k ) {
								unset( $b['attrs'][ $k ] );
							}
						} elseif ( $in_row || $in_grid || $decor( $a ) ) {
							$b['attrs']['dxaiDrop'] = true;
							++$slots['dropped'];
							if ( ! $in_row && $tile( $a ) ) {
								++$slots['tiles'];
							}
						} else {
							++$images;
							$n2 = $images;
							$b['attrs']['imageUrl']    = '{{image_url:' . $n2 . '}}';
							$b['attrs']['imageId']     = '{{image_id:' . $n2 . '}}';
							$b['attrs']['imageWidth']  = '{{image_w:' . $n2 . '}}';
							$b['attrs']['imageHeight'] = '{{image_h:' . $n2 . '}}';
							$b['attrs']['imageAlt']    = '{{image_alt:' . $n2 . '|}}';
							unset( $b['attrs']['alt'] );
							foreach ( array( 'mobileImageId', 'mobileImageUrl', 'mobileImageWidth', 'mobileImageHeight', 'priority' ) as $k ) {
								unset( $b['attrs'][ $k ] );
							}
							$b['attrs']['dxaiIf'] = array( 'image_url:' . $n2 );
							++$slots['image'];
						}
					}
				}
			);
			// The cards' own photographs, dropped (marked above, so paths stayed true while walking).
			$drop = static function ( array &$b ) use ( &$drop ): void {
				for ( $i = count( $b['innerBlocks'] ) - 1; $i >= 0; $i-- ) {
					if ( ! empty( $b['innerBlocks'][ $i ]['attrs']['dxaiDrop'] ) ) {
						Block_Tree::drop( $b, $i );
						continue;
					}
					$drop( $b['innerBlocks'][ $i ] );
				}
			};
			$drop( $root );
			// A box left with nothing in it (a card that held only the site's own photograph, a frame of a decoration) goes too: an empty
			// white card with a shadow is not a layout. The section itself stays whatever it holds.
			$prune = static function ( array &$b ) use ( &$prune ): void {
				for ( $i = count( $b['innerBlocks'] ) - 1; $i >= 0; $i-- ) {
					$prune( $b['innerBlocks'][ $i ] );
					$child = $b['innerBlocks'][ $i ];
					if ( in_array( (string) $child['blockName'], array( 'core/group', 'core/column', 'core/columns', 'core/cover', 'dxai-ui/box' ), true ) && $child['innerBlocks'] === array() && trim( wp_strip_all_tags( (string) $child['innerHTML'] ) ) === '' ) {
						Block_Tree::drop( $b, $i );
					}
				}
			};
			$prune( $root );
			// A column that held only a picture that is now a slot: the column goes with the picture when the page has none.
			$walk(
				$root,
				static function ( array &$b ): void {
					if ( (string) $b['blockName'] === 'core/column' && count( $b['innerBlocks'] ) === 1 && isset( $b['innerBlocks'][0]['attrs']['dxaiIf'] ) && in_array( (string) $b['innerBlocks'][0]['blockName'], array( 'core/image', 'dx/picture' ), true ) ) {
						$b['attrs']['dxaiIf'] = $b['innerBlocks'][0]['attrs']['dxaiIf'];
						unset( $b['innerBlocks'][0]['attrs']['dxaiIf'] );
					}
				}
			);
			// An attribute left empty (a side of a border, a style with nothing in it) is not written: `[]` is not what the editor wrote.
			$prune = static function ( array &$b ) use ( &$prune ): void {
				$clean = static function ( &$v ) use ( &$clean ): void {
					if ( ! is_array( $v ) ) {
						return;
					}
					foreach ( $v as $k => &$x ) {
						$clean( $x );
						if ( is_array( $x ) && $x === array() && ! in_array( (string) $k, array( 'dxaiIf' ), true ) ) {
							unset( $v[ $k ] );
						}
					}
					unset( $x );
				};
				$clean( $b['attrs'] );
				foreach ( $b['innerBlocks'] as &$c ) {
					$prune( $c );
				}
				unset( $c );
			};
			$prune( $root );
			// The section itself: the library's mark, and the name a person sees in the editor.
			$cls = trim( (string) ( $root['attrs']['className'] ?? '' ) );
			if ( ! preg_match( '/\bdxai-lib\b/', $cls ) ) {
				$root['attrs']['className'] = trim( $cls . ' dxai-lib' );
				$root['innerHTML']          = (string) preg_replace( '/^(\s*<[a-z][a-z0-9]*\b[^>]*\sclass=")/i', '$1dxai-lib ', (string) $root['innerHTML'], 1 );
				foreach ( $root['innerContent'] as $k => $part ) {
					if ( is_string( $part ) && trim( $part ) !== '' ) {
						$root['innerContent'][ $k ] = (string) preg_replace( '/^(\s*<[a-z][a-z0-9]*\b[^>]*\sclass=")/i', '$1dxai-lib ', $part, 1 );
						break;
					}
				}
			}
			$root['attrs']['metadata'] = array( 'name' => $label );
			// Two buttons of one box to the same slot (a card's two links to its page, a band's two buttons to the contact page) are one
			// button: a row has one page, and the section one way to it. The first stays.
			$walk(
				$root,
				static function ( array &$b ) use ( &$slots ): void {
					if ( (string) $b['blockName'] !== 'core/buttons' ) {
						return;
					}
					$seen = array();
					$gone = array();
					foreach ( $b['innerBlocks'] as $i => $c ) {
						$url = (string) ( $c['attrs']['url'] ?? '' );
						if ( (string) $c['blockName'] === 'core/button' && str_starts_with( $url, '{{' ) ) {
							if ( isset( $seen[ $url ] ) ) {
								$gone[] = $i;
							}
							$seen[ $url ] = true;
						}
					}
					foreach ( array_reverse( $gone ) as $i ) {
						Block_Tree::drop( $b, $i );
						$slots['button'] = max( 0, $slots['button'] - 1 );
					}
				}
			);

			return array( 'block' => $root, 'slots' => $slots, 'needs' => array_values( array_unique( $needs ) ) );
		};

		// --- 4. Write. -------------------------------------------------------------------------------------------------------------------
		$out_dir = rtrim( (string) ( $opts['out_dir'] ?? DXAI_UI_DIR . 'data/block-library' ), '/\\' );
		$index   = array();
		$made    = 0;
		$taken   = array(); // role => how many layouts of it were written (a gallery left out does not take a number)
		$forbid  = array(
			'an address of a site'   => '/https?:\/\/(?!\{\{)/i',
			'a phone number'         => '/\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4}/',
			'an e-mail address'      => '/[\w.\-]+@[\w\-]+\.[a-z]{2,}/i',
			'words left'             => '/<(?:h[1-6]|p|li|span|a)\b[^>]*>(?:(?!\{\{)[^<])*[a-z]{3,}[^<]*<\/(?:h[1-6]|p|li|span|a)>/i',
			'a picture of the site'  => '/wp-content\/uploads/i',
		);
		$labels = array(
			'two-col' => 'Two columns', 'cards' => 'Cards', 'process' => 'Steps', 'reviews' => 'Reviews', 'faq' => 'Questions', 'cta' => 'Call to action', 'related' => 'Related pages', 'trust' => 'Trust row', 'areas' => 'Places', 'content' => 'Text',
		);
		$say( sprintf( "%s\n", $dry ? 'DRY: nothing is written' : 'Writing to ' . $out_dir ) );
		foreach ( $chosen as $role => $picks ) {
			foreach ( $picks as $n => $pick ) {
				$cands = $found[ $role ][ $pick['sig'] ][ $pick['site'] ];
				$cand  = $cands[0];
				// Named by its role and a number, not by the site it was read from: nothing of that site reaches a page, not even its name in the editor.
				$id    = sprintf( '%s-%s-%d', $prefix, $role, ( $taken[ $role ] ?? 0 ) + 1 );
				$notes = array();
				$label = sprintf( '%s · %s layout %d', $labels[ $role ] ?? ucfirst( $role ), $label_prefix, ( $taken[ $role ] ?? 0 ) + 1 );
				$lay   = $generalize( $cand['block'], $role, $roles[ $role ], $label, $notes, (string) ( $hosts[ $pick['site'] ] ?? $pick['site'] ), in_array( (string) ( $cand['kind'] ?? '' ), array( 'service', 'location' ), true ) ? (string) $cand['title'] : '' );
				$block = $lay['block'];
				$html  = serialize_block( $block );
				$back  = array_values( array_filter( parse_blocks( $html ), static fn( $b ) => ! empty( $b['blockName'] ) ) );
				$bad   = array();
				if ( count( $back ) !== 1 ) {
					$bad[] = 'does not round-trip as one block';
				}
				foreach ( $forbid as $what => $re ) {
					if ( preg_match( $re, $html, $m ) ) {
						$bad[] = $what . ': ' . mb_substr( (string) $m[0], 0, 60 );
					}
				}
				// A section whose pictures were the point (a gallery, the crew in photographs): without them it is boxes, and it is left out
				// quietly (the next of the role takes its number).
				if ( ( $lay['slots']['dropped'] >= 3 || $lay['slots']['tiles'] >= 2 ) && $lay['slots']['image'] === 0 ) {
					$say( sprintf( "\n%-32s %-10s site %-20s SKIPPED: a gallery of the site's pictures (%d dropped)\n", $id, $role, $hosts[ $pick['site'] ] ?? $pick['site'], $lay['slots']['dropped'] ) );
					continue;
				}
				// A section of a list (the reviews, the steps, the questions, the badges, the places, the related pages) whose rows were a
				// widget, a shortcode or a picture is a heading and a line without them: not a layout of its role, and left out quietly.
				// (The rows must be in the layout as written: a list marked and then pruned with its box — a widget's column — is no list.)
				if ( in_array( $role, array( 'reviews', 'process', 'faq', 'trust', 'areas', 'related' ), true ) && ( $lay['slots']['repeat'] === 0 || ! str_contains( $html, '"dxaiRepeat"' ) ) ) {
					$say( sprintf( "\n%-32s %-10s site %-20s SKIPPED: a %s section with no rows of its own (%d dropped)\n", $id, $role, $hosts[ $pick['site'] ] ?? $pick['site'], $labels[ $role ] ?? $role, $lay['slots']['dropped'] ) );
					continue;
				}
				if ( $lay['slots']['text'] + $lay['slots']['repeat'] === 0 ) {
					$bad[] = 'no words to fill';
				}
				$say( sprintf( "\n%-32s %-10s site %-20s used %2d× on %-24s %s\n", $id, $role, $hosts[ $pick['site'] ] ?? $pick['site'], $pick['uses'], implode( ',', array_map( static fn( $k, $v ) => "$k:$v", array_keys( $pick['kinds'] ), $pick['kinds'] ) ), mb_substr( $cand['title'], 0, 30 ) . '#' . $cand['index'] ) );
				$say( sprintf( "   slots: %s; blocks: %d; needs: %s; %s\n", implode( ', ', array_map( static fn( $k, $v ) => "$k $v", array_keys( $lay['slots'] ), $lay['slots'] ) ), count( $cand['names'] ), implode( ',', $lay['needs'] ) ?: '-', implode( '; ', array_slice( $notes, 0, 4 ) ) ) );
				if ( $bad !== array() ) {
					$say( sprintf( "   NOT TAKEN: %s\n", implode( '; ', $bad ) ) );
					$errors[] = $id . ': ' . implode( '; ', $bad );
					continue;
				}
				// A layout suits any kind of page: what the section is (its role) decides where it goes, not the page it was read from.
				$pages = array();
				// The blocks it is made of now (a picture of the team's row went; an icon stayed), not the ones the team's section had.
				$names = array_values( array_unique( $names_of( $back[0] ?? array( 'blockName' => '', 'innerBlocks' => array() ) ) ) );
				sort( $names );
				preg_match_all( '/\sclass="([^"]*)"/', $html, $cm );
				$classes = array_values( array_unique( array_filter( preg_split( '/\s+/', implode( ' ', $cm[1] ) ), static fn( $c ) => $c !== '' && ! str_contains( $c, '{{' ) ) ) );
				sort( $classes );
				preg_match_all( '/\{\{\s*([a-z_]+(?::[a-z0-9_\-]+)?)/i', $html, $tm );
				$tokens = array_values( array_unique( array_map( 'strval', $tm[1] ) ) );
				sort( $tokens );
				$entry = array(
					'id'       => $id,
					'role'     => $role,
					'label'    => $label,
					'from'     => 'team#' . $pick['site'] . '-' . $cand['page'] . '#' . $cand['index'],
					'source'   => 'layout',
					'builder'  => true,
					// Read from restoration companies' sites, with a restoration company's provisional words (Layouts::words): not for another kind.
					'industry' => 'restoration',
					'needs'    => $lay['needs'],
					'pages'    => $pages,
					'seen'     => array_keys( $pick['kinds'] ),
					'list'     => $roles[ $role ],
					'slots'    => $lay['slots'],
					'tokens'   => $tokens,
					'blocks'   => $names,
					'classes'  => $classes,
					'bytes'    => strlen( $html ),
				);
				$index[] = $entry;
				if ( ! $dry ) {
					file_put_contents( $out_dir . '/blocks/' . $id . '.html', $html ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				}
				++$made;
				$taken[ $role ] = ( $taken[ $role ] ?? 0 ) + 1;
			}
		}
		if ( ! $dry ) {
			$file = $out_dir . '/library.json';
			$doc  = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$doc  = is_array( $doc ) ? $doc : array( 'version' => 1, 'themes' => array(), 'entries' => array() );
			if ( ! empty( $opts['themes'] ) ) {
				$doc['themes'] = array_values( array_map( 'strval', (array) $opts['themes'] ) );
			}
			// The layouts written before go; the sections built by hand (bin/build-block-library.php) stay.
			$kept = array_values( array_filter( (array) $doc['entries'], static fn( $e ) => ( $e['source'] ?? '' ) !== 'layout' ) );
			foreach ( glob( $out_dir . '/blocks/*.html' ) as $old ) {
				$oid = basename( $old, '.html' );
				if ( ! in_array( $oid, array_column( $kept, 'id' ), true ) && ! in_array( $oid, array_column( $index, 'id' ), true ) ) {
					unlink( $old ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			}
			$doc['entries'] = array_merge( $kept, $index );
			file_put_contents( $file, wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$say( sprintf( "\n%d layouts %s, %d not taken\n", $made, $dry ? 'planned' : 'written', count( $errors ) ) );
		return array(
			'made'    => $made,
			'errors'  => $errors,
			'lines'   => $lines,
			'written' => ! $dry,
			'index'   => $index ?? array(),
		);

	}
}

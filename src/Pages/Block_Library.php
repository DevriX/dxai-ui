<?php
/**
 * The team's own sections, kept in the plugin, for the pages made from a Home.
 *
 * @package DXAI_UI\Pages
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

use DXAI_UI\Media\Svg_Files;
use DXAI_UI\Support\Color_Math;
use DXAI_UI\Theme\Capabilities;
use DXAI_UI\Theme\Theme_Palette;

/**
 * A page made from a design's Home takes the Home's own sections (Team_Pages). Where the Home has none of a kind (the questions, a
 * call to action, how the work goes, where the company works), the page used to go without. The team has those sections already: the
 * pages of its sites are built from the same handful of them (Section_Roles), written in the theme's blocks, classes and settings.
 * This keeps a set of them — data/block-library, made by bin/build-block-library.php from two of the team's finished sites — and
 * fills one for the site it is used on.
 *
 * What a section keeps is what makes it the team's: its blocks, its classes, its spacing and its type as the block settings wrote
 * them. What it does not keep is what made it one company's. That is a token the page is made with from what the Home says
 * (`{{company}}`, `{{phone}}`, …), or a marker the library reads when it fills the section:
 *
 *   dxaiIf       the block is there only when the Home says what it names (a phone, an address);
 *   dxaiRepeat   the block is the item of a list the page fills (the places of the site): one copy for each;
 *   dxaiIcon     the image is a small drawing the library ships (a chevron, a pin): a file in the media library.
 *
 * A section is used only where it can look as it was made to: the theme is one the library is for (american-restoration), the
 * blocks it is made of are registered, and what it needs (a phone, places) is known. Otherwise it is not used, and the page goes
 * without it as before.
 */
final class Block_Library {

	/** Where the data is, from the plugin's folder. */
	public const DIR = 'data/block-library/';

	/** `{{name}}`, `{{name:arg}}`, `{{name|default}}`. */
	private const TOKEN = '/\{\{\s*([a-z_]+)(?::([a-z0-9_\-]+))?(?:\|([^{}]*))?\s*\}\}/i';

	/** @var array<string, array<string, array<string, mixed>>> The sections, by the template they were read for. */
	private static array $index = array();

	/** @var array<string, array<string, mixed>> The index files read, by folder. */
	private static array $docs = array();

	/** @var array<string, string> */
	private static array $markup = array();

	/** @var array<string, int> The rows of each list the section being made shows (repeats()), for the page's count (Layouts::shown). */
	private static array $rows_used = array();

	/**
	 * Every section of the library, by id.
	 *
	 * @return array<string, array<string, mixed>> id, role, label, from, builder, needs, pages, tokens, blocks, classes.
	 */
	public static function entries(): array {
		// The template in use (Template::current()): its own sections, and the ones of the template it extends (the built-in one, as a
		// rule) after them. Each entry knows the folder its block is in.
		$template = Template::current();
		$key      = (string) $template['slug'];
		if ( isset( self::$index[ $key ] ) ) {
			return self::$index[ $key ];
		}
		$dirs = array( (string) $template['dir'] );
		$base = (string) ( $template['extends'] ?? '' );
		if ( $base !== '' && isset( Template::all()[ $base ] ) ) {
			$dirs[] = (string) Template::all()[ $base ]['dir'];
		}
		$out = array();
		foreach ( $dirs as $dir ) {
			foreach ( (array) ( self::doc( $dir )['entries'] ?? array() ) as $row ) {
				if ( is_array( $row ) && isset( $row['id'] ) && is_string( $row['id'] ) && preg_match( '/^[a-z0-9-]{2,60}$/', $row['id'] ) === 1 && ! isset( $out[ $row['id'] ] ) ) {
					$row['_dir']         = $dir;
					$out[ $row['id'] ] = $row;
				}
			}
		}
		self::$index[ $key ] = $out;

		return $out;
	}

	/** Forget the sections read (a library was built or removed). */
	public static function forget(): void {
		self::$index  = array();
		self::$markup = array();
	}

	/**
	 * A library's index file, read once.
	 *
	 * @return array<string, mixed>
	 */
	private static function doc( string $dir ): array {
		$dir = rtrim( $dir, '/\\' ) . '/';
		if ( ! isset( self::$docs[ $dir ] ) ) {
			$file = $dir . 'library.json';
			$doc  = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			self::$docs[ $dir ] = is_array( $doc ) ? $doc : array();
		}

		return self::$docs[ $dir ];
	}

	/**
	 * How many layouts a library folder holds, and how many of each role — the folder's own, not the template in use.
	 *
	 * @return array{layouts: int, by_role: array<string, int>}
	 */
	public static function layouts_in( string $dir ): array {
		$by_role = array();
		foreach ( (array) ( self::doc( $dir )['entries'] ?? array() ) as $e ) {
			if ( ( $e['source'] ?? '' ) === 'layout' ) {
				$role             = (string) ( $e['role'] ?? '' );
				$by_role[ $role ] = ( $by_role[ $role ] ?? 0 ) + 1;
			}
		}
		ksort( $by_role );

		return array( 'layouts' => array_sum( $by_role ), 'by_role' => $by_role );
	}

	/** Whether the library holds the team's layouts (bin/build-layouts.php): the page maker lays out every section but the hero with them. */
	public static function has_layouts(): bool {
		foreach ( self::entries() as $e ) {
			if ( ( $e['source'] ?? '' ) === 'layout' ) {
				return true;
			}
		}

		return false;
	}

	/** @return array<string, mixed>|null */
	public static function entry( string $id ): ?array {
		return self::entries()[ $id ] ?? null;
	}

	/**
	 * The ids of the sections of a role.
	 *
	 * @param bool $builder Only those the page maker may use as they are (the ones that are made of facts it has).
	 * @return array<int, string>
	 */
	public static function ids_for_role( string $role, bool $builder = true ): array {
		$out = array();
		foreach ( self::entries() as $id => $e ) {
			if ( (string) $e['role'] === $role && ( ! $builder || ! empty( $e['builder'] ) ) ) {
				$out[] = (string) $id;
			}
		}

		return $out;
	}

	/** The section as the library keeps it, tokens and markers in it. */
	public static function markup( string $id ): string {
		$entry = self::entry( $id );
		$key   = (string) ( $entry['_dir'] ?? '' ) . '|' . $id;
		if ( ! isset( self::$markup[ $key ] ) ) {
			$file                 = rtrim( (string) ( $entry['_dir'] ?? DXAI_UI_DIR . self::DIR ), '/\\' ) . '/blocks/' . $id . '.html';
			self::$markup[ $key ] = $entry !== null && is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		return self::$markup[ $key ];
	}

	/**
	 * The themes the library is for: the sections are made of what the team's theme has (its classes, its components, its blocks).
	 *
	 * @return array<int, string>
	 */
	public static function themes(): array {
		// The built-in library's own (its index names them): what the built-in template is for.
		$themes = array_values( array_filter( array_map( 'strval', (array) ( self::doc( DXAI_UI_DIR . self::DIR )['themes'] ?? array() ) ) ) );

		/**
		 * The themes whose pages may take the library's sections.
		 *
		 * @param array<int, string> $themes Theme folder names (the child's or the parent's).
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'dxai_ui_block_library_themes', $themes ) ) ) );
	}

	/** Whether the active theme (or its parent) is one the library is for. */
	public static function theme_ok(): bool {
		// A theme with a template of its own, or any other: the generic library (Template) makes the sections for it in core blocks.
		return Template::current() !== array();
	}

	/** Whether the active theme has a template of its own (the built-in one is American Restoration's, and the DX themes'). */
	public static function template_ok(): bool {
		return Template::for_theme() !== null;
	}

	/**
	 * Whether the library can be used for a design's pages, and when it cannot, which of the things it needs this site does not have:
	 * the theme its sections are made for, a Home that is the kind of company their words are for, the blocks they are made of.
	 *
	 * @return array{code:string, theme:string, sections:int, usable:int}
	 *         code: `ok`, `theme`, `industry`, `blocks` or `none` (the library has no sections); theme: the name of the active theme;
	 *         sections: how many the library has; usable: how many of them can be made for this Home now.
	 */
	public static function availability( int $home ): array {
		$template = Template::current();
		$out      = array(
			'code'           => 'none',
			'theme'          => (string) wp_get_theme()->get( 'Name' ),
			'sections'       => count( self::entries() ),
			'usable'         => 0,
			// The template the sections are made in: the theme's own, or the generic library (core blocks, the theme's colours).
			'template'       => (string) $template['slug'],
			'template_label' => (string) $template['label'],
			'generic'        => ! empty( $template['generic'] ),
		);
		if ( $out['sections'] === 0 ) {
			return $out;
		}
		if ( ! self::theme_ok() ) {
			$out['code'] = 'theme';

			return $out;
		}
		$facts = self::facts( $home, Home_Kit::facts( $home ) );
		if ( (string) $facts['industry'] === '' ) {
			$out['code'] = 'industry';

			return $out;
		}
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( self::entries() as $entry ) {
			if ( self::usable( $entry, $facts )['ok'] ) {
				++$out['usable'];
			}
		}
		$missing = false;
		foreach ( self::entries() as $entry ) {
			foreach ( (array) $entry['blocks'] as $name ) {
				$missing = $missing || ! $registry->is_registered( (string) $name );
			}
		}
		// Some sections wait for a fact the Home does not say (a phone, places): the library is there for the ones that do not.
		$out['code'] = $out['usable'] > 0 ? 'ok' : ( $missing ? 'blocks' : 'none' );

		return $out;
	}

	/**
	 * Whether a section can be made on this site with these facts, and why not.
	 *
	 * @param array<string, mixed> $entry
	 * @param array<string, mixed> $facts facts().
	 * @return array{ok:bool, why:string}
	 */
	public static function usable( array $entry, array $facts ): array {
		if ( ! self::theme_ok() ) {
			return array( 'ok' => false, 'why' => 'the theme is not one the library is for' );
		}
		// The words are the team's, for the kind of company it makes sites for: a Home that is another kind has no use for them.
		if ( (string) ( $entry['industry'] ?? '' ) !== '' && (string) ( $facts['industry'] ?? '' ) !== (string) $entry['industry'] ) {
			return array( 'ok' => false, 'why' => 'its words are for a ' . (string) $entry['industry'] . ' company, and the Home is not one' );
		}
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( (array) ( $entry['blocks'] ?? array() ) as $name ) {
			// In the generic library a theme's block of a role the contract knows is written as core's (generic()): it needs no registering.
			if ( ! $registry->is_registered( (string) $name ) && ! ( Template::is_generic() && self::role_of( (string) $name ) !== '' ) ) {
				return array( 'ok' => false, 'why' => (string) $name . ' is not registered here' );
			}
		}
		foreach ( (array) ( $entry['needs'] ?? array() ) as $need ) {
			if ( ! self::has( $facts, (string) $need ) ) {
				return array( 'ok' => false, 'why' => 'the Home does not say ' . str_replace( '|', ' or ', (string) $need ) );
			}
		}

		return array( 'ok' => true, 'why' => '' );
	}

	/**
	 * Whether the facts have what a need names: `phone`, or `phone|contact_url` for either of them.
	 *
	 * @param array<string, mixed> $facts
	 */
	private static function has( array $facts, string $need ): bool {
		foreach ( explode( '|', $need ) as $name ) {
			$have = $facts[ trim( $name ) ] ?? '';
			if ( $have !== '' && $have !== array() && $have !== null ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * What a section is filled with, for a page of a Home.
	 *
	 * @param array{phone:array{text:string, url:string}|null, email:string, address:string} $kit Home_Kit::facts().
	 * @param array{title?:string, kind?:string, contact_url?:string, places?:array<int, array{title:string, url:string}>, services?:array<int, array{title:string, url:string}>} $page The page being made.
	 * @return array<string, mixed>
	 */
	public static function facts( int $home, array $kit, array $page = array() ): array {
		$company = self::company( $home );
		$facts   = array(
			'home'          => $home,
			'industry'      => self::industry( $home ),
			'company'       => $company,
			'company_short' => self::short( $company ),
			'phone'         => (string) ( $kit['phone']['text'] ?? '' ),
			'phone_url'     => (string) ( $kit['phone']['url'] ?? '' ),
			'email'         => (string) ( $kit['email'] ?? '' ),
			'address'       => (string) ( $kit['address'] ?? '' ),
			// What the Home does not say stays empty: a section says "the surrounding area" where it has no region to name.
			'region'        => '',
			'title'         => (string) ( $page['title'] ?? '' ),
			'kind'          => (string) ( $page['kind'] ?? '' ),
			'contact_url'   => (string) ( $page['contact_url'] ?? '' ),
			'site_url'      => home_url( '/' ),
			'places'        => array_values( (array) ( $page['places'] ?? array() ) ),
			'services'      => array_values( (array) ( $page['services'] ?? array() ) ),
		);

		/**
		 * What the library's sections are filled with.
		 *
		 * @param array<string, mixed> $facts
		 * @param int                  $home  The design's Home.
		 */
		return (array) apply_filters( 'dxai_ui_block_library_facts', $facts, $home );
	}

	/**
	 * A section as an editor pattern, made for the site it is inserted on: its company's name, and its phone and places where the site's
	 * brand design says them; what the site does not say is a placeholder a person edits (nothing is made up). It is the team's block as it
	 * was written: no Home's look is put on it, since it goes on a page of the theme.
	 */
	public static function pattern( string $id ): string {
		$why   = '';
		$block = self::fill( $id, self::pattern_facts( $id ), $why );

		return $block === null ? '' : serialize_block( $block );
	}

	/**
	 * What a pattern is filled with.
	 *
	 * @return array<string, mixed>
	 */
	private static function pattern_facts( string $id ): array {
		$design = get_option( \DXAI_UI\Theme\Design_Theme_Json::OPTION );
		$home   = is_array( $design ) ? (int) ( $design['page_id'] ?? 0 ) : 0;
		$home   = $home > 0 && get_post_status( $home ) === 'publish' ? $home : 0;
		$kit    = $home > 0 ? Home_Kit::facts( $home ) : array(
			'phone'   => null,
			'email'   => '',
			'address' => '',
		);
		$facts  = self::facts( $home, $kit, array() );
		// Not the Home's look (a pattern is for a page of the theme), and not gated on the kind of company or page (a person chose it).
		$facts['home']     = 0;
		$facts['industry'] = (string) ( self::entry( $id )['industry'] ?? '' );
		$facts['kind']     = '';
		if ( $facts['phone'] === '' ) {
			$facts['phone']     = __( 'Your phone number', 'dxai-ui' );
			$facts['phone_url'] = '#';
		}
		if ( $facts['contact_url'] === '' ) {
			$facts['contact_url'] = '#';
		}
		$places = array();
		foreach ( $home > 0 ? \DXAI_UI\Pages\Team_Pages::places_of_home( $home ) : array() as $place ) {
			$places[] = array(
				'title' => (string) $place,
				'url'   => '',
			);
		}
		for ( $n = count( $places ); $n < 6; $n++ ) {
			$places[] = array(
				'title' => __( 'City, ST', 'dxai-ui' ),
				'url'   => '',
			);
		}
		$facts['places'] = $places;

		return $facts;
	}

	/**
	 * What kind of company the Home is for, as far as the library has sections for: "restoration" when the Home talks like a restoration
	 * company (water, fire, mold and storm damage, mitigation, reconstruction, insurance claims), else an empty string.
	 */
	public static function industry( int $home ): string {
		static $seen = array();
		if ( ! isset( $seen[ $home ] ) ) {
			// A space where one element ends and the next begins (a heading against a paragraph), so the words stay words.
			$text = html_entity_decode( wp_strip_all_tags( str_replace( '>', '> ', (string) get_post_field( 'post_content', $home ) ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$hits = preg_match_all( '/\b(?:water damage|fire damage|smoke damage|fire and smoke|mold|mildew|flood(?:ing)?|restoration|remediation|mitigation|storm damage|sewage|sewer backup|reconstruction|water extraction|dry-?out|insurance claims?|biohazard|asbestos)\b/i', $text, $m );
			// A word or two (a "remediation" of data, a "restoration" of a record) does not make a restoration company: it says it over and again.
			$seen[ $home ] = $hits !== false && $hits >= 8 && count( array_unique( array_map( 'strtolower', $m[0] ) ) ) >= 3 ? 'restoration' : '';
		}

		return $seen[ $home ];
	}

	/** The company's name: the Home's title, else the site's. */
	private static function company( int $home ): string {
		// (get_the_title( 0 ) is the title of the page being shown: no Home is the site's own name.)
		$title = $home > 0 ? trim( html_entity_decode( wp_strip_all_tags( (string) get_the_title( $home ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) : '';
		if ( $title === '' || preg_match( '/^(?:home|homepage|home page|welcome|main|index)$/i', $title ) === 1 ) {
			$title = trim( html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}

		return $home > 0 ? self::name_in( $title, (string) get_post_field( 'post_content', $home ) ) : $title;
	}

	/**
	 * The company's name as its Home's title says it, without what is not the name at either end of the title: a code someone put before
	 * it ("BR12 H2O Away" is "H2O Away"), a page's word ("Home | Acme Dry", "Acme Dry – Homepage"). A word is taken off only when the
	 * Home's own words never say it, so a name the page writes in full stays whole, and a word of the name the page does not repeat
	 * ("Harborview Water and Fire" on a page that says "Harborview Co.") is never cut.
	 */
	public static function name_in( string $title, string $content ): string {
		$words = preg_split( '/\s+/u', trim( (string) preg_replace( '/\s*(?:[|–—:]+|\s-\s)\s*/u', ' | ', $title ) ) ) ?: array();
		$words = array_values( array_filter( $words, static fn( $w ) => $w !== '' ) );
		if ( count( $words ) < 2 ) {
			return $title;
		}
		$text = mb_strtolower( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $content ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		$said = static fn( string $w ): bool => $text !== '' && preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( mb_strtolower( $w ), '/' ) . '(?![\p{L}\p{N}])/u', $text ) === 1;
		// Not the name: a separator, a page's word, or a code (letters and digits, in capitals) the page never says.
		$off = static fn( string $w ): bool => $w === '|' || preg_match( '/^(?:home|homepage|home-page|page|main|welcome|index|landing|draft|copy|new|test|v\d+)$/i', $w ) === 1 || ( preg_match( '/^[A-Z]{1,4}\d{1,4}$/', $w ) === 1 && ! $said( $w ) );
		while ( $words !== array() && $off( (string) $words[0] ) ) {
			array_shift( $words );
		}
		while ( $words !== array() && $off( (string) $words[ count( $words ) - 1 ] ) ) {
			array_pop( $words );
		}
		// What is left of a title with a separator in the middle is the part that is the name: the one the page says, else the first.
		if ( in_array( '|', $words, true ) ) {
			$parts = array_values( array_filter( array_map( 'trim', explode( '|', implode( ' ', $words ) ) ), static fn( $p ) => $p !== '' && ! $off( $p ) ) );
			$named = array_values( array_filter( $parts, $said ) );
			$words = explode( ' ', (string) ( $named[0] ?? $parts[0] ?? '' ) );
		}
		$name = trim( implode( ' ', $words ) );

		return mb_strlen( $name ) >= 2 ? $name : $title;
	}

	/** The name without what every company of the kind is called ("Arcus Restoration" is "Arcus"), as people say it. */
	public static function short( string $company ): string {
		$short = $company;
		do {
			$was   = $short;
			$short = trim( (string) preg_replace( '/\s+(?:Restorations?|Services?|Remediation|Inc\.?|LLC|L\.L\.C\.|Co\.?|Company|Group)\s*$/i', '', $short ) );
		} while ( $short !== $was && $short !== '' );

		return strlen( $short ) >= 3 ? $short : $company;
	}

	/**
	 * A section of a role, made for a page: of the sections the site can use, the seed picks one (the same seed picks the same one), and
	 * the next is tried when that one cannot be made.
	 *
	 * @param array<string, mixed> $facts facts().
	 * @param array<int, string>   $avoid Ids not to take (what the page has already).
	 * @param string               $only  An id: nothing else is made (a place that was locked to it).
	 * @return array{block:array<string, mixed>, id:string, label:string}|null
	 */
	public static function section( string $role, array $facts, string $seed, array $avoid = array(), string $only = '', ?string &$why = null ): ?array {
		$why  = '';
		$cant = array();
		$ids  = self::candidates( $role, $facts, $avoid, $only, $cant );
		sort( $ids, SORT_STRING );
		// A list whose rows are titles alone (the Home's points) is shown by a section that asks for no more: one with a slot for a row's
		// words would show empty cards. Those come last, for when nothing else can be made.
		$bare = static function ( string $id ) use ( $facts ): bool {
			$entry = (array) self::entry( $id );
			$list  = (string) ( $entry['list'] ?? '' );
			if ( $list === '' || ! in_array( 'row:text', (array) ( $entry['tokens'] ?? array() ), true ) ) {
				return false;
			}
			$rows = (array) ( $facts['lists'][ $list ] ?? ( $list === 'places' ? ( $facts['places'] ?? array() ) : ( $list === 'services' ? ( $facts['services'] ?? array() ) : array() ) ) );
			foreach ( $rows as $r ) {
				if ( is_array( $r ) && trim( (string) ( $r['text'] ?? '' ) ) !== '' ) {
					return false;
				}
			}

			return $rows !== array();
		};
		$groups = array(
			array_values( array_filter( $ids, static fn( string $id ): bool => ! $bare( $id ) ) ),
			array_values( array_filter( $ids, $bare ) ),
		);
		foreach ( $groups as $group ) {
			$start = $group === array() ? 0 : (int) ( sprintf( '%u', crc32( $seed . '|' . $role ) ) % count( $group ) );
			foreach ( array_keys( $group ) as $k ) {
				$id    = $group[ ( $start + $k ) % count( $group ) ];
				$block = self::fill( $id, $facts, $reason );
				if ( $block !== null ) {
					return array(
						'block' => $block,
						'id'    => $id,
						'label' => (string) self::entry( $id )['label'],
					);
				}
				$cant[] = $id . ': ' . $reason;
			}
		}
		$why = $cant === array() ? 'the library has none' : implode( '; ', $cant );

		return null;
	}

	/**
	 * The sections of a role that can be made for a page: for its kind, for a theme and a company they are made for, and with what the Home says.
	 *
	 * @param array<string, mixed> $facts facts().
	 * @param array<int, string>   $avoid Ids not to take.
	 * @param array<int, string>   $cant  Why the others are not, one line for each.
	 * @return array<int, string>
	 */
	private static function candidates( string $role, array $facts, array $avoid, string $only, array &$cant ): array {
		$ids = array();
		foreach ( self::ids_for_role( $role ) as $id ) {
			if ( in_array( $id, $avoid, true ) || ( $only !== '' && $id !== $only ) ) {
				continue;
			}
			// A section suits some kinds of page (a page of questions is not a service's): what the page is is in the facts.
			$suits = array_map( 'strval', (array) ( self::entry( $id )['pages'] ?? array() ) );
			if ( $suits !== array() && (string) ( $facts['kind'] ?? '' ) !== '' && ! in_array( (string) $facts['kind'], $suits, true ) ) {
				$cant[] = $id . ': it is not for a page of that kind';
				continue;
			}
			$can = self::usable( (array) self::entry( $id ), $facts );
			if ( $can['ok'] ) {
				$ids[] = $id;
			} else {
				$cant[] = $id . ': ' . $can['why'];
			}
		}
		// When the Home says its own questions (or reviews, or steps), the sections that list them come before the ones that carry the
		// team's own (a page of a company that answers its customers' questions shows those answers).
		$own = array( 'faq' => 'faq', 'reviews' => 'reviews', 'process' => 'steps' );
		if ( isset( $own[ $role ] ) && count( (array) ( $facts['lists'][ $own[ $role ] ] ?? array() ) ) >= 2 ) {
			$listing = array_values( array_filter( $ids, static fn( $id ) => str_contains( self::markup( (string) $id ), '"dxaiRepeat":"' . $own[ $role ] . '"' ) ) );
			if ( $listing !== array() ) {
				foreach ( array_diff( $ids, $listing ) as $id ) {
					$cant[] = $id . ': the Home has its own';
				}
				$ids = $listing;
			}
		}

		return $ids;
	}

	/**
	 * Whether a section of a role can be made for a page.
	 *
	 * @param array<string, mixed> $facts facts().
	 */
	public static function can( string $role, array $facts ): bool {
		$cant = array();

		return self::candidates( $role, $facts, array(), '', $cant ) !== array();
	}

	/**
	 * The roles the library can make a section of for a page: what a page that lacks a section of the Home's may be made of.
	 *
	 * @param array<string, mixed> $facts facts().
	 * @return array<int, string>
	 */
	public static function roles( array $facts ): array {
		$out = array();
		foreach ( array_unique( array_map( static fn( $e ) => (string) $e['role'], array_filter( self::entries(), static fn( $e ) => ! empty( $e['builder'] ) ) ) ) as $role ) {
			if ( self::can( $role, $facts ) ) {
				$out[] = $role;
			}
		}

		return $out;
	}

	/**
	 * A section filled for a site: its tokens, the blocks that need a fact, the list of places, the icons. Null when it cannot be filled.
	 *
	 * @param array<string, mixed> $facts facts(); `places` fills a `places` list.
	 * @return array<string, mixed>|null A parse_blocks() block.
	 */
	public static function fill( string $id, array $facts, ?string &$why = null ): ?array {
		$why   = '';
		$entry = self::entry( $id );
		if ( $entry === null ) {
			$why = 'no such section';

			return null;
		}
		$can = self::usable( $entry, $facts );
		if ( ! $can['ok'] ) {
			$why = $can['why'];

			return null;
		}
		$blocks = array_values( array_filter( parse_blocks( self::markup( $id ) ), static fn( $b ) => ! empty( $b['blockName'] ) ) );
		if ( count( $blocks ) !== 1 ) {
			$why = 'the section is not one block';

			return null;
		}
		$root = $blocks[0];
		self::conditions( $root, $facts );
		$missing = array();
		// The lists: the places and the services, and what a layout (Layouts) is given to repeat — its steps, reviews, questions, logos, figures, points.
		$lists = array( 'places' => (array) $facts['places'], 'services' => (array) $facts['services'] ) + (array) ( $facts['lists'] ?? array() );
		// What the section lists, for the team's words of its headings (tokens(): a heading that names services is not kept over points).
		preg_match_all( '/"dxaiRepeat":"([a-z]+)"/', self::markup( $id ), $lm );
		$facts['section_lists'] = array_values( array_unique( $lm[1] ) );
		self::$rows_used = array();
		if ( ! self::repeats( $root, $lists, $facts, $missing ) ) {
			$why = 'it has nothing to list';

			return null;
		}
		// The drawings after the lists: a card of a list may name its own drawing (a row token, resolved as the copy was made). (A plan makes no file: it is `dry`.)
		self::icons( $root, ! empty( $facts['dry'] ), (string) ( $facts['icon_color'] ?? '' ) );
		self::tokens( $root, $facts, null, $missing );
		if ( $missing !== array() ) {
			$why = 'it needs ' . implode( ', ', array_unique( $missing ) );

			return null;
		}
		// Provisional words say the same few things in other words: a section carries one paragraph of them, not three in a row.
		$kept = false;
		self::thin( $root, $kept );
		self::markers( $root );
		if ( str_contains( serialize_block( $root ), '{{' ) ) {
			$why = 'a token was left';

			return null;
		}
		// A line left with no words (a row that has a title and nothing more: the slot for its words is empty) is not left on the page.
		self::prune_empty( $root );
		$root = Layouts::finish( $root );
		// On a theme with no template of its own: core blocks, and the colours this theme has for the roles.
		if ( Template::is_generic() ) {
			$root = self::generic( $root );
		}
		// A section that shows one of the Home's pictures counts towards the page's share of them (Layouts::page_facts gives no more after four).
		if ( (string) ( $facts['image_url:1'] ?? '' ) !== '' && str_contains( serialize_block( $root ), (string) $facts['image_url:1'] ) ) {
			Layouts::pictured( $facts, 1 );
		}
		// …and the rows of the Home's lists it shows count towards the page's: the next section that lists them goes on from there.
		foreach ( isset( $facts['lists'] ) ? self::$rows_used : array() as $list => $n ) {
			if ( isset( $facts['lists'][ $list ] ) ) {
				Layouts::shown( $facts, (string) $list, (int) $n );
			}
		}

		// For a page of a design (a converted page); a pattern goes on a page of the theme, which has these styles itself.
		if ( (int) ( $facts['home'] ?? 0 ) > 0 ) {
			self::layouts( $root );
		}

		// Last: every line readable on what it stands on, in this theme's colours (the Home's look may have changed what a card is).
		return self::legible( self::follow( $root, (int) ( $facts['home'] ?? 0 ) ), (int) ( $facts['home'] ?? 0 ) );
	}

	/**
	 * The theme's page layout, written into the section. A converted page is the design on its own: the theme's global layout styles (how
	 * wide a "constrained" container's content is, the padding at the sides of the page) are not on it, so a container the team left to
	 * take them from the theme says them itself, as block settings (the Layout panel's content and wide width, the Dimensions panel's
	 * padding). The theme's own numbers are used, as its Styles say them now, so a page of the theme looks as the section was made to.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function layouts( array &$block, bool $root = true ): void {
		static $global = null;
		if ( $global === null ) {
			$layout = wp_get_global_settings( array( 'layout' ) );
			$pad    = wp_get_global_styles( array( 'spacing', 'padding' ) );
			$global = array(
				'content' => is_array( $layout ) ? (string) ( $layout['contentSize'] ?? '' ) : '',
				'wide'    => is_array( $layout ) ? (string) ( $layout['wideSize'] ?? '' ) : '',
				'left'    => is_array( $pad ) ? (string) ( $pad['left'] ?? '' ) : '',
				'right'   => is_array( $pad ) ? (string) ( $pad['right'] ?? '' ) : '',
			);
		}
		if ( ( $block['blockName'] ?? '' ) === 'core/group' ) {
			$layout = $block['attrs']['layout'] ?? null;
			$wide   = false;
			foreach ( (array) $block['innerBlocks'] as $child ) {
				$wide = $wide || in_array( $child['attrs']['align'] ?? '', array( 'wide', 'full' ), true );
			}
			// Neither size said: the theme's are the container's (a size said is the team's, and stays). Only where the width is the point: the
			// section, and a container whose content is aligned wide. A row inside a card is laid out by its own classes (the theme's rule
			// for the content of a constrained container is one they set aside), and a rule made for it here would come after them.
			if ( is_array( $layout ) && ( $layout['type'] ?? '' ) === 'constrained' && empty( $layout['contentSize'] ) && empty( $layout['wideSize'] ) && ( $root || $wide ) ) {
				if ( $global['content'] !== '' ) {
					$block['attrs']['layout']['contentSize'] = $global['content'];
				}
				if ( $global['wide'] !== '' ) {
					$block['attrs']['layout']['wideSize'] = $global['wide'];
				}
			}
			// The section itself keeps off the edge of the screen as the theme's pages do.
			if ( $root ) {
				$put = array();
				foreach ( array( 'left', 'right' ) as $side ) {
					if ( ! isset( $block['attrs']['style']['spacing']['padding'][ $side ] ) && $global[ $side ] !== '' ) {
						$block['attrs']['style']['spacing']['padding'][ $side ] = $global[ $side ];
						$put[ 'padding-' . $side ]                              = $global[ $side ];
					}
				}
				if ( $put !== array() ) {
					self::put_style( $block, $put );
				}
			}
		}
		foreach ( $block['innerBlocks'] as &$child ) {
			self::layouts( $child, false );
		}
		unset( $child );
	}

	/**
	 * Declarations in a block's own opening tag, over the ones it has (what the block's settings write there).
	 *
	 * @param array<string, mixed>  $block
	 * @param array<string, string> $put   property => value
	 */
	private static function put_style( array &$block, array $put ): void {
		$decl = implode( ';', array_map( static fn( string $p, string $v ): string => $p . ':' . $v, array_keys( $put ), $put ) );
		foreach ( (array) $block['innerContent'] as $k => $chunk ) {
			if ( ! is_string( $chunk ) || trim( $chunk ) === '' ) {
				continue;
			}
			$block['innerContent'][ $k ] = (string) preg_replace_callback(
				'/^(\s*<[a-z][a-z0-9]*\b)([^>]*)(>)/i',
				static function ( array $m ) use ( $put, $decl ): string {
					if ( preg_match( '/\sstyle="([^"]*)"/', $m[2], $s ) !== 1 ) {
						return $m[1] . $m[2] . ' style="' . esc_attr( $decl ) . '"' . $m[3];
					}
					$css = $s[1];
					foreach ( array_keys( $put ) as $prop ) {
						$css = (string) preg_replace( '/(?:^|;)\s*' . preg_quote( $prop, '/' ) . '\s*:[^;"]*/i', ';', $css );
					}
					$css = trim( (string) preg_replace( '/;{2,}/', ';', $css ), '; ' );

					return $m[1] . str_replace( $s[0], ' style="' . ( $css === '' ? '' : $css . ';' ) . esc_attr( $decl ) . '"', $m[2] ) . $m[3];
				},
				$chunk,
				1
			);
			break;
		}
		$block['innerHTML'] = implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) );
	}

	/**
	 * The section in the look of the design it is made for: its section titles as the Home's are set, and its padding above and below as
	 * the Home's sections have it, in place of the team's own sizes. For no Home (a pattern in the editor) it is as the team wrote it.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function follow( array $block, int $home ): array {
		if ( $home < 1 ) {
			return $block;
		}

		return \DXAI_UI\Blocks\Native\Library_Style::follow( $block, $home );
	}

	/** The role a theme's block plays in the sections ('span', 'link_box', 'picture'), or '' for a block that is not a theme's for one. */
	private static function role_of( string $name ): string {
		foreach ( array( 'span', 'link_box', 'picture' ) as $role ) {
			if ( Capabilities::is_block( $name, $role ) && (string) ( Capabilities::CORE_BLOCKS[ $role ] ?? '' ) !== $name ) {
				return $role;
			}
		}

		return '';
	}

	/**
	 * A section for a theme with no template of its own (Template::is_generic()): the template's theme blocks as core's (a label as a
	 * paragraph, a box that is a link as a group whose title is the link, a picture as core's picture), its colours as the ones this
	 * theme's palette has for the same roles (Template::slug_map(); a colour the palette has no role for is dropped), its font sizes as
	 * the theme's or none, its button styles as the theme's or core's.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function generic( array $block ): array {
		$name = (string) ( $block['blockName'] ?? '' );
		$role = self::role_of( $name );
		if ( $role === 'span' ) {
			$block = self::retag( $block, 'core/paragraph', 'p', array() );
		} elseif ( $role === 'link_box' ) {
			$url   = (string) ( $block['attrs']['url'] ?? '' );
			$block = self::retag( $block, 'core/group', 'div', array( 'wp-block-group' ) );
			unset( $block['attrs']['url'] );
			if ( $url !== '' ) {
				$block = self::link_title( $block, $url );
			}
		} elseif ( $role === 'picture' ) {
			$block = self::core_image( $block );
		}
		// The colours: the template's slugs as this theme's; one the palette has no role for goes.
		$map  = Template::slug_map();
		$swap = static function ( string $s ) use ( $map ): string {
			$keys = Template::built_in_slugs();
			usort( $keys, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
			foreach ( $keys as $old ) {
				$new = $map[ $old ] ?? '';
				if ( $new === $old ) {
					continue;
				}
				$s = (string) preg_replace( '/\bhas-' . preg_quote( $old, '/' ) . '-(background-color|border-color|color)\b\s?/', $new === '' ? '' : 'has-' . $new . '-$1 ', $s );
				$s = str_replace( 'var(--wp--preset--color--' . $old . ')', $new === '' ? 'inherit' : 'var(--wp--preset--color--' . $new . ')', $s );
				$s = str_replace( 'var:preset|color|' . $old, $new === '' ? '' : 'var:preset|color|' . $new, $s );
			}

			return $s;
		};
		foreach ( array( 'backgroundColor', 'textColor', 'borderColor' ) as $k ) {
			$old = (string) ( $block['attrs'][ $k ] ?? '' );
			if ( $old !== '' && in_array( $old, Template::built_in_slugs(), true ) ) {
				if ( ( $map[ $old ] ?? '' ) === '' ) {
					unset( $block['attrs'][ $k ] );
				} else {
					$block['attrs'][ $k ] = $map[ $old ];
				}
			}
		}
		$walk = static function ( &$v ) use ( &$walk, $swap ): void {
			if ( is_string( $v ) ) {
				$v = $swap( $v );
			} elseif ( is_array( $v ) ) {
				foreach ( $v as $k => &$x ) {
					$walk( $x );
					if ( $x === '' ) {
						unset( $v[ $k ] );
					}
				}
				unset( $x );
			}
		};
		if ( isset( $block['attrs']['style'] ) ) {
			$walk( $block['attrs']['style'] );
			if ( $block['attrs']['style'] === array() ) {
				unset( $block['attrs']['style'] );
			}
		}
		// A font size the theme does not have, a button style it does not have.
		$sizes = array_map( static fn( $s ) => (string) ( $s['slug'] ?? '' ), (array) ( wp_get_global_settings( array( 'typography', 'fontSizes' ) )['theme'] ?? array() ) );
		$size  = (string) ( $block['attrs']['fontSize'] ?? '' );
		if ( $size !== '' && ! in_array( $size, $sizes, true ) ) {
			unset( $block['attrs']['fontSize'] );
			$swap_size = static fn( string $s ): string => (string) preg_replace( '/\bhas-' . preg_quote( $size, '/' ) . '-font-size\b\s?/', '', $s );
		}
		if ( $name === 'core/button' ) {
			$styles = Capabilities::button_styles();
			$class  = (string) ( $block['attrs']['className'] ?? '' );
			$new    = (string) preg_replace_callback(
				'/(^|\s)is-style-([a-z0-9-]+)(?=\s|$)/',
				static function ( array $m ) use ( $styles ): string {
					if ( in_array( $m[2], $styles, true ) || in_array( $m[2], array( 'fill', 'outline' ), true ) ) {
						return $m[0];
					}

					return str_contains( $m[2], 'secondary' ) || str_contains( $m[2], 'outline' ) ? $m[1] . 'is-style-outline' : $m[1];
				},
				$class
			);
			$new = trim( (string) preg_replace( '/\s+/', ' ', $new ) );
			if ( $new !== $class ) {
				$from = array_values( array_filter( explode( ' ', $class ), static fn( $t ) => str_starts_with( $t, 'is-style-' ) ) );
				$to   = array_values( array_filter( explode( ' ', $new ), static fn( $t ) => str_starts_with( $t, 'is-style-' ) ) );
				$block['attrs']['className'] = $new;
				if ( $new === '' ) {
					unset( $block['attrs']['className'] );
				}
				$swap_button = static fn( string $s ): string => (string) preg_replace( '/\s+/', ' ', str_replace( $from, array_pad( $to, count( $from ), '' ), $s ) );
			}
		}
		$block['innerHTML'] = $swap( (string) $block['innerHTML'] );
		foreach ( $block['innerContent'] as $k => $part ) {
			if ( is_string( $part ) ) {
				$block['innerContent'][ $k ] = $swap( $part );
			}
		}
		foreach ( array( 'swap_size', 'swap_button' ) as $fn ) {
			if ( isset( $$fn ) ) {
				$block['innerHTML'] = ( $$fn )( (string) $block['innerHTML'] );
				foreach ( $block['innerContent'] as $k => $part ) {
					if ( is_string( $part ) ) {
						$block['innerContent'][ $k ] = ( $$fn )( $part );
					}
				}
			}
		}
		for ( $i = count( $block['innerBlocks'] ) - 1; $i >= 0; $i-- ) {
			$child = $block['innerBlocks'][ $i ];
			if ( ! is_array( $child ) ) {
				continue;
			}
			// A theme's block that plays no role the sections need (a wave between sections, a break) has no core counterpart: it goes.
			$cn = (string) ( $child['blockName'] ?? '' );
			if ( preg_match( '#^(?:amr|dx)/#', $cn ) === 1 && self::role_of( $cn ) === '' ) {
				Block_Tree::drop( $block, $i );
				continue;
			}
			$block['innerBlocks'][ $i ] = self::generic( $child );
		}

		return $block;
	}

	/**
	 * A theme's block written as another block: its name, its own element's tag (the first tag of its markup, and the last closing one),
	 * the classes the theme's block generates taken off and the new block's put on, a link's address taken off the tag.
	 *
	 * @param array<string, mixed> $block
	 * @param array<int, string>   $classes
	 * @return array<string, mixed>
	 */
	private static function retag( array $block, string $name, string $tag, array $classes ): array {
		$old   = Capabilities::markup_of( (string) ( $block['blockName'] ?? '' ) );
		// The classes the theme's block generates (the adapter spells them), and the one the editor gives every block by its name.
		$drop  = array_merge( (array) ( $old['classes'] ?? array() ), array( 'wp-block-' . str_replace( '/', '-', (string) ( $block['blockName'] ?? '' ) ) ) );
		$first = null;
		$last  = null;
		foreach ( $block['innerContent'] as $k => $part ) {
			if ( is_string( $part ) && trim( $part ) !== '' ) {
				$first ??= $k;
				$last    = $k;
			}
		}
		if ( $first !== null ) {
			$block['innerContent'][ $first ] = (string) preg_replace_callback(
				'/^(\s*)<([a-z][a-z0-9]*)\b([^>]*)>/i',
				static function ( array $m ) use ( $tag, $drop, $classes ): string {
					$attr = (string) preg_replace( '/\shref="[^"]*"/', '', $m[3] );
					$attr = (string) preg_replace_callback(
						'/\sclass="([^"]*)"/',
						static function ( array $c ) use ( $drop, $classes ): string {
							$kept = array_values( array_filter( preg_split( '/\s+/', trim( $c[1] ) ) ?: array(), static fn( string $t ): bool => $t !== '' && ! in_array( $t, $drop, true ) && ! in_array( $t, $classes, true ) ) );
							$all  = array_merge( $classes, $kept );

							return $all === array() ? '' : ' class="' . esc_attr( implode( ' ', $all ) ) . '"';
						},
						$attr,
						1,
						$n
					);
					if ( $n === 0 && $classes !== array() ) {
						$attr = ' class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $attr;
					}

					return $m[1] . '<' . $tag . $attr . '>';
				},
				(string) $block['innerContent'][ $first ],
				1
			);
		}
		if ( $last !== null ) {
			$block['innerContent'][ $last ] = (string) preg_replace( '/<\/[a-z][a-z0-9]*>(\s*)$/i', '</' . $tag . '>$1', (string) $block['innerContent'][ $last ], 1 );
		}
		$block['blockName'] = $name;
		$block['innerHTML'] = implode( '', array_filter( $block['innerContent'], 'is_string' ) );

		return $block;
	}

	/**
	 * A group that was a box with a link: its first title (or line) becomes the link.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function link_title( array $block, string $url ): array {
		$done = false;
		$walk = static function ( array &$b ) use ( &$walk, &$done, $url ): void {
			if ( $done ) {
				return;
			}
			if ( in_array( (string) ( $b['blockName'] ?? '' ), array( 'core/heading', 'core/paragraph' ), true ) ) {
				foreach ( $b['innerContent'] as $k => $part ) {
					if ( is_string( $part ) && preg_match( '/^(\s*<(h[1-6]|p)\b[^>]*>)(.*)(<\/\2>\s*)$/is', $part, $m ) === 1 && trim( wp_strip_all_tags( $m[3] ) ) !== '' && ! str_contains( $m[3], '<a ' ) ) {
						$b['innerContent'][ $k ] = $m[1] . '<a href="' . esc_url( $url ) . '">' . $m[3] . '</a>' . $m[4];
						$b['innerHTML']          = implode( '', array_filter( $b['innerContent'], 'is_string' ) );
						$done                    = true;

						return;
					}
				}
			}
			foreach ( $b['innerBlocks'] as &$c ) {
				$walk( $c );
			}
			unset( $c );
		};
		$walk( $block );

		return $block;
	}

	/**
	 * A theme's picture block as core's picture.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function core_image( array $block ): array {
		$a   = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$id  = (int) ( $a['imageId'] ?? $a['id'] ?? 0 );
		$url = (string) ( $a['imageUrl'] ?? '' );
		if ( $url === '' && preg_match( '/<img[^>]*\ssrc="([^"]*)"/', (string) $block['innerHTML'], $m ) === 1 ) {
			$url = $m[1];
		}
		$alt   = (string) ( $a['imageAlt'] ?? $a['alt'] ?? '' );
		$w     = (int) ( $a['imageWidth'] ?? 0 );
		$h     = (int) ( $a['imageHeight'] ?? 0 );
		$class = trim( (string) ( $a['className'] ?? '' ) );
		$attrs = array( 'sizeSlug' => 'full', 'linkDestination' => 'none' );
		if ( $id > 0 ) {
			$attrs = array( 'id' => $id ) + $attrs;
		}
		if ( $class !== '' ) {
			$attrs['className'] = $class;
		}
		$html = '<figure class="wp-block-image size-full' . ( $class !== '' ? ' ' . esc_attr( $class ) : '' ) . '"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '"' . ( $id > 0 ? ' class="wp-image-' . $id . '"' : '' ) . ( $w > 0 && $h > 0 ? ' width="' . $w . '" height="' . $h . '"' : '' ) . '/></figure>';

		return array(
			'blockName'    => 'core/image',
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		);
	}

	/**
	 * Blocks that are there only when the Home says what they name.
	 *
	 * @param array<string, mixed> $block
	 * @param array<string, mixed> $facts
	 */
	private static function conditions( array &$block, array $facts ): void {
		for ( $i = count( $block['innerBlocks'] ) - 1; $i >= 0; $i-- ) {
			$child = &$block['innerBlocks'][ $i ];
			if ( isset( $child['attrs']['dxaiIf'] ) ) {
				$ok = true;
				foreach ( (array) $child['attrs']['dxaiIf'] as $need ) {
					$ok = $ok && self::has( $facts, (string) $need );
				}
				unset( $child['attrs']['dxaiIf'] );
				if ( ! $ok ) {
					unset( $child );
					Block_Tree::drop( $block, $i );
					continue;
				}
			}
			self::conditions( $child, $facts );
			// A row of buttons whose buttons all went has nothing to show.
			if ( ( $child['blockName'] ?? '' ) === 'core/buttons' && empty( $child['innerBlocks'] ) ) {
				unset( $child );
				Block_Tree::drop( $block, $i );
				continue;
			}
			unset( $child );
		}
	}

	/**
	 * Lists: the block marked `dxaiRepeat` is the first item, and each row of the list is one copy of it.
	 *
	 * @param array<string, mixed>                         $block
	 * @param array<string, array<int, array<string, string>>> $lists name => rows
	 * @param array<string, mixed>                         $facts
	 * @param array<int, string>                           $missing
	 * @return bool False when a list has no row to show.
	 */
	private static function repeats( array &$block, array $lists, array $facts, array &$missing ): bool {
		for ( $i = 0; $i < count( $block['innerBlocks'] ); $i++ ) {
			$child = $block['innerBlocks'][ $i ];
			if ( isset( $child['attrs']['dxaiRepeat'] ) ) {
				$name = (string) $child['attrs']['dxaiRepeat'];
				$rows = array_values( array_filter( (array) ( $lists[ $name ] ?? array() ), static fn( $r ) => is_array( $r ) && trim( (string) ( $r['title'] ?? '' ) ) !== '' ) );
				// A layout's list: as many rows as the team's section held (dxaiCount), and, in a grid of columns, this column's share of
				// them (dxaiSlice: which column of how many — the rows are dealt across the columns in turn).
				$slice = $child['attrs']['dxaiSlice'] ?? null;
				if ( is_array( $slice ) && count( $slice ) === 2 && (int) $slice[1] > 1 ) {
					$rows = array_values( array_filter( $rows, static fn( $i ) => $i % (int) $slice[1] === (int) $slice[0], ARRAY_FILTER_USE_KEY ) );
				}
				$count = (int) ( $child['attrs']['dxaiCount'] ?? 0 );
				if ( $count > 0 ) {
					$rows = array_slice( $rows, 0, $count );
				}
				self::$rows_used[ $name ] = ( self::$rows_used[ $name ] ?? 0 ) + count( $rows );
				if ( $rows === array() ) {
					// A column with no row of its own (fewer rows than columns) shows nothing; a list with none at all cannot be shown.
					if ( is_array( $slice ) && (int) ( $slice[0] ?? 0 ) > 0 ) {
						Block_Tree::drop( $block, $i );
						--$i;
						continue;
					}

					return false;
				}
				unset( $child['attrs']['dxaiRepeat'], $child['attrs']['dxaiCount'], $child['attrs']['dxaiSlice'] );
				foreach ( $rows as $n => $row ) {
					$copy = $child;
					// The row as it came (a layout's rows say more: a picture, a drawing, a line above the title), its three words tidied.
					$row  = array_merge(
						array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : '', $row ),
						array(
							'title' => trim( (string) $row['title'] ),
							'url'   => (string) ( $row['url'] ?? '' ),
							'text'  => (string) ( $row['text'] ?? '' ),
						)
					);
					if ( $row['url'] === '' ) {
						self::unlink( $copy );
					}
					self::tokens( $copy, $facts, $row, $missing );
					Block_Tree::insert( $block, $i + 1 + $n, $copy );
				}
				Block_Tree::drop( $block, $i );
				$i += count( $rows ) - 1;
				continue;
			}
			if ( ! self::repeats( $block['innerBlocks'][ $i ], $lists, $facts, $missing ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A link box of an item that has no address to go to is a box, not a link (a link without an href is not one).
	 *
	 * @param array<string, mixed> $block
	 */
	private static function unlink( array &$block ): void {
		if ( Capabilities::is_block( (string) ( $block['blockName'] ?? '' ), 'link_box' ) && isset( $block['attrs']['url'] ) ) {
			// What the block saves with no address: a div, with the class a link has taken out.
			unset( $block['attrs']['url'] );
			$open = static function ( string $s ): string {
				$s = (string) preg_replace( '/^(\s*)<a\b([^>]*?)\s+href="[^"]*"([^>]*)>/', '$1<div$2$3>', $s, 1 );

				return (string) preg_replace_callback(
					'/^(\s*<div\b[^>]*?\sclass=")([^"]*)(")/',
					static fn( array $m ): string => $m[1] . trim( (string) preg_replace( '/\s+/', ' ', str_replace( 'amr-link-box--has-link', '', $m[2] ) ) ) . $m[3],
					$s,
					1
				);
			};
			foreach ( $block['innerContent'] as $k => $part ) {
				if ( is_string( $part ) ) {
					$block['innerContent'][ $k ] = $open( $part );
					break;
				}
			}
			$last = array_key_last( $block['innerContent'] );
			if ( $last !== null && is_string( $block['innerContent'][ $last ] ) ) {
				$block['innerContent'][ $last ] = (string) preg_replace( '#</a>(\s*)$#', '</div>$1', $block['innerContent'][ $last ], 1 );
			}
			$block['innerHTML'] = implode( '', array_filter( $block['innerContent'], 'is_string' ) );

			return;
		}
		// A button to the row's page, when the row has none, is not shown (a button that goes nowhere is not one); a row of buttons left
		// with none goes with it.
		for ( $i = count( $block['innerBlocks'] ) - 1; $i >= 0; $i-- ) {
			$child = $block['innerBlocks'][ $i ];
			if ( (string) ( $child['blockName'] ?? '' ) === 'core/button' && (string) ( $child['attrs']['url'] ?? '' ) === '{{row:url}}' ) {
				Block_Tree::drop( $block, $i );
				continue;
			}
			self::unlink( $block['innerBlocks'][ $i ] );
			if ( (string) ( $block['innerBlocks'][ $i ]['blockName'] ?? '' ) === 'core/buttons' && (array) $block['innerBlocks'][ $i ]['innerBlocks'] === array() ) {
				Block_Tree::drop( $block, $i );
			}
		}
	}

	/**
	 * The small drawings: each is a file in the media library (one for each drawing, found again by its hash).
	 *
	 * @param array<string, mixed> $block
	 */
	private static function icons( array &$block, bool $dry = false, string $colour = '' ): void {
		for ( $i = count( $block['innerBlocks'] ) - 1; $i >= 0; $i-- ) {
			$child = &$block['innerBlocks'][ $i ];
			if ( isset( $child['attrs']['dxaiIcon'] ) ) {
				$name = (string) $child['attrs']['dxaiIcon'];
				$file = self::icon( $name, $dry, $colour );
				if ( $file === null ) {
					// No file to point at: the drawing is left out, not a broken picture.
					unset( $child );
					Block_Tree::drop( $block, $i );
					continue;
				}
				$swap = static fn( string $s ): string => str_replace( array( '{{icon_url:' . $name . '}}', '{{icon_id:' . $name . '}}' ), array( esc_url( $file['url'] ), (string) $file['id'] ), $s );
				$child['innerHTML'] = $swap( (string) $child['innerHTML'] );
				foreach ( $child['innerContent'] as $k => $part ) {
					if ( is_string( $part ) ) {
						$child['innerContent'][ $k ] = $swap( $part );
					}
				}
				$child['attrs']['id'] = $file['id'];
				unset( $child['attrs']['dxaiIcon'] );
			}
			self::icons( $child, $dry, $colour );
			unset( $child );
		}
	}

	/**
	 * The attachment of a drawing the library ships.
	 *
	 * @param bool $dry Only say it is there: no file is made, and the drawing has no address (a plan).
	 * @return array{id:int, url:string}|null
	 */
	public static function icon( string $name, bool $dry = false, string $colour = '' ): ?array {
		if ( preg_match( '/^[a-z0-9-]{2,40}$/', $name ) !== 1 ) {
			return null;
		}
		$path = DXAI_UI_DIR . self::DIR . 'icons/' . $name . '.svg';
		if ( ! is_readable( $path ) ) {
			return null;
		}
		if ( $dry ) {
			return array(
				'id'  => 0,
				'url' => '',
			);
		}
		$svg = trim( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		// Drawn in a colour (the Home's icons', the site's primary) where one is given: a drawing shown as a picture cannot take a colour from the page.
		if ( preg_match( '/^#[0-9a-f]{3,8}$/i', $colour ) === 1 ) {
			$svg = str_replace( 'currentColor', $colour, $svg );
		}
		$made = Svg_Files::ensure( $svg );

		return $made === null ? null : array(
			'id'  => (int) $made['id'],
			'url' => (string) $made['url'],
		);
	}

	/**
	 * The tokens, in the attributes (as they are) and in the markup (as text, escaped).
	 *
	 * @param array<string, mixed>              $block
	 * @param array<string, mixed>              $facts
	 * @param array<string, string>|null        $row     The item a list copy is made for.
	 * @param array<int, string>                $missing What a token asked for and was not given.
	 */
	private static function tokens( array &$block, array $facts, ?array $row, array &$missing ): void {
		// A slot for words is resolved once per block: the block's markup is in its innerHTML and again in its innerContent, and the
		// words it is given rotate with each slot asked for (Layouts::words), so the two copies would get different words.
		$cache       = array();
		$provisional = false;
		$sub         = static function ( string $s, bool $html ) use ( $facts, $row, &$missing, &$cache, &$provisional ): string {
			if ( ! str_contains( $s, '{{' ) ) {
				return $s;
			}
			$seen = array();

			return (string) preg_replace_callback(
				self::TOKEN,
				static function ( array $m ) use ( $facts, $row, $html, &$missing, &$cache, &$seen, &$provisional ): string {
					$name    = strtolower( $m[1] );
					$arg     = strtolower( $m[2] ?? '' );
					// A default is there when the token has a bar, even an empty one ({{row:eyebrow|}}: nothing where the row says nothing).
					$has_default = array_key_exists( 3, $m );
					$default     = trim( (string) ( $m[3] ?? '' ) );
					$value       = null;
					if ( $name === 'row' ) {
						$value = $row !== null && isset( $row[ $arg ] ) && (string) $row[ $arg ] !== '' ? (string) $row[ $arg ] : null;
					} elseif ( $name === 't' ) {
						// A layout's slot for words: the team's own words of it where the layout kept them (after the bar, with the page's facts
						// in them), else provisional words of what the Home states (Layouts) — until they are written for the page.
						$seen[ $arg ] = ( $seen[ $arg ] ?? 0 ) + 1;
						$key          = $arg . '#' . $seen[ $arg ];
						if ( ! array_key_exists( $key, $cache ) ) {
							$team          = $has_default && $default !== '' && self::says_what_it_lists( $arg, $default, $facts ) ? self::team_words( $default, $facts ) : '';
							$once          = ! empty( $facts['once'] );
							// A page says a line once: the team's line a section of the page (or the Home's own) already said gives way to
							// provisional words, and provisional words said already to the next of theirs.
							if ( $once && $team !== '' && Layouts::said( $facts, $team, false ) ) {
								$team = '';
							}
							$cache[ $key ] = $team !== '' ? $team : Layouts::words( $arg, $facts, $row );
							if ( $once && $team === '' && Layouts::said( $facts, $cache[ $key ], false ) ) {
								$again = Layouts::words( $arg, $facts, $row );
								if ( $again !== '' && ! Layouts::said( $facts, $again, false ) ) {
									$cache[ $key ] = $again;
								}
							}
							if ( $once ) {
								Layouts::said( $facts, $cache[ $key ] );
							}
							// A paragraph of provisional words (not the team's): thin() keeps one of them in a section.
							if ( $team === '' && str_starts_with( $arg, 'p-' ) ) {
								$provisional = true;
							}
						}
						$has_default = false;
						$value       = $cache[ $key ] === '' ? null : $cache[ $key ];
					} elseif ( $arg !== '' && isset( $facts[ $name . ':' . $arg ] ) && is_scalar( $facts[ $name . ':' . $arg ] ) && (string) $facts[ $name . ':' . $arg ] !== '' ) {
						// A fact of a numbered slot (a layout's picture: image_url:1).
						$value = (string) $facts[ $name . ':' . $arg ];
					} elseif ( $name !== 'icon_url' && $name !== 'icon_id' && isset( $facts[ $name ] ) && is_string( $facts[ $name ] ) && $facts[ $name ] !== '' ) {
						$value = $facts[ $name ];
					}
					// A token with a bar takes what is after it when the fact is not there — nothing, when nothing is ({{image_alt:1|}}).
					if ( $value === null && $has_default ) {
						$value = $default;
					}
					if ( $value === null ) {
						// A drawing's address is written by icons(), after the lists: not a token that is missing.
						if ( $name !== 'icon_url' && $name !== 'icon_id' ) {
							$missing[] = $name . ( $arg !== '' ? ':' . $arg : '' );
						}

						return $m[0];
					}

					return $html ? esc_html( $value ) : $value;
				},
				$s
			);
		};
		$walk = static function ( &$v ) use ( &$walk, $sub ): void {
			if ( is_string( $v ) ) {
				$v = $sub( $v, false );
			} elseif ( is_array( $v ) ) {
				foreach ( $v as &$x ) {
					$walk( $x );
				}
			}
		};
		$walk( $block['attrs'] );
		$block['innerHTML'] = $sub( (string) $block['innerHTML'], true );
		foreach ( $block['innerContent'] as $k => $part ) {
			if ( is_string( $part ) ) {
				$block['innerContent'][ $k ] = $sub( $part, true );
			}
		}
		// (A row's words are the row's, not provisional: only a section's own slots are marked.)
		if ( $provisional && $row === null ) {
			$block['attrs']['dxaiProvisional'] = true;
		}
		foreach ( $block['innerBlocks'] as &$child ) {
			self::tokens( $child, $facts, $row, $missing );
		}
		unset( $child );
	}

	/**
	 * Whether the team's words of a heading or a label name what the section lists: "Our Restoration Services" over a list of services (or
	 * of related pages), "Areas we serve" over places, and so on. The same layout is filled with other lists on other pages (a grid of
	 * services on the team's site shows the Home's points here), and a heading that names something else is not kept.
	 *
	 * @param array<string, mixed> $facts
	 */
	public static function says_what_it_lists( string $slot, string $words, array $facts ): bool {
		if ( preg_match( '/^(?:h[1-4]|label)-/', $slot ) !== 1 || ! isset( $facts['section_lists'] ) ) {
			return true;
		}
		$lists = (array) $facts['section_lists'];
		// "Service areas" are places, not services.
		$words = (string) preg_replace( '/\bservice\s+areas?\b/i', 'areas', $words );
		$names = array(
			'/\bservices?\b/i'                                          => array( 'services', 'related' ),
			'/\b(?:cities|areas?|locations?|communities|counties)\b/i' => array( 'places' ),
			'/\b(?:reviews?|testimonials?|customers say)\b/i'           => array( 'reviews' ),
			'/\b(?:steps?|process|how it works)\b/i'                    => array( 'steps' ),
			'/\b(?:questions|faqs?)\b/i'                                => array( 'faq' ),
		);
		foreach ( $names as $re => $wants ) {
			if ( preg_match( $re, $words ) === 1 && array_intersect( $wants, $lists ) === array() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The team's words of a slot with the page's facts in them: `[[company]]`, `[[company_short]]`, `[[phone]]`, `[[email]]`, `[[title]]`,
	 * `[[subject]]` (what the page is about), `[[region|the area]]` (a fallback after the bar). Empty when a fact the words need is not
	 * there and has no fallback: the slot then takes the provisional words instead.
	 *
	 * @param array<string, mixed> $facts
	 */
	public static function team_words( string $text, array $facts ): string {
		$missing = false;
		$out     = (string) preg_replace_callback(
			'/\[\[([a-z_]+)(?:\|([^\]]*))?\]\]/i',
			static function ( array $m ) use ( $facts, &$missing ): string {
				$name  = strtolower( $m[1] );
				$value = (string) ( $facts[ $name ] ?? '' );
				if ( $name === 'subject' && $value === '' ) {
					$value = (string) ( $facts['title'] ?? '' );
				}
				if ( $value === '' && isset( $m[2] ) ) {
					$value = trim( $m[2] );
				}
				if ( $value === '' ) {
					$missing = true;
				}

				return $value;
			},
			$text
		);

		return $missing ? '' : trim( (string) preg_replace( '/\s+/', ' ', $out ) );
	}

	/**
	 * The lines of words that have none (a paragraph, a heading, a label, a list item whose slot was filled with nothing) are taken out.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function prune_empty( array &$block ): void {
		for ( $i = count( $block['innerBlocks'] ) - 1; $i >= 0; $i-- ) {
			$child = $block['innerBlocks'][ $i ];
			$name  = (string) ( $child['blockName'] ?? '' );
			if ( in_array( $name, array( 'core/paragraph', 'core/heading', 'core/list-item', 'amr/span', 'dxai-ui/text' ), true ) && (array) ( $child['innerBlocks'] ?? array() ) === array() && trim( wp_strip_all_tags( (string) $child['innerHTML'] ) ) === '' && ! str_contains( (string) $child['innerHTML'], '<img' ) ) {
				Block_Tree::drop( $block, $i );
				continue;
			}
			self::prune_empty( $block['innerBlocks'][ $i ] );
			// A list left with no items goes too.
			if ( (string) ( $block['innerBlocks'][ $i ]['blockName'] ?? '' ) === 'core/list' && (array) $block['innerBlocks'][ $i ]['innerBlocks'] === array() ) {
				Block_Tree::drop( $block, $i );
			}
		}
	}

	/**
	 * The paragraphs of provisional words outside the lists (tokens() marks them): the first stays, the others go. The team's own words
	 * and a list's rows are not provisional and are not counted.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function thin( array &$block, bool &$kept ): void {
		for ( $i = 0; $i < count( $block['innerBlocks'] ); $i++ ) {
			$child = $block['innerBlocks'][ $i ];
			if ( ! empty( $child['attrs']['dxaiProvisional'] ) && (string) ( $child['blockName'] ?? '' ) === 'core/paragraph' ) {
				if ( $kept ) {
					Block_Tree::drop( $block, $i );
					--$i;
					continue;
				}
				$kept = true;
			}
			self::thin( $child, $kept );
			$block['innerBlocks'][ $i ] = $child;
		}
	}

	/**
	 * Every line of words readable on what it stands on, in the colours this site's theme has (Capabilities::palette(), read by role:
	 * Template::palette_roles()), so it holds on any theme and any template:
	 *  - words with no colour of their own on a dark ground take the theme's colour for words on a dark ground;
	 *  - words (or a box's words) in a colour that does not read on their ground, or that the theme does not have, lose it: on a light
	 *    ground the words are the theme's text colour then, on a dark one its colour for a dark ground;
	 *  - a card is not a slab of a loud colour: a bright, saturated background in a card (a slot that is a light tint on one site and a
	 *    vivid brand colour on another) gives way to the theme's lightest colour on the card itself, and to the card's own surface inside it.
	 * A ground the blocks do not say (a class of the design, a picture) is taken as the one around it; the page is light.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	public static function legible( array $block, int $home = 0 ): array {
		$colours = Capabilities::palette();
		foreach ( (array) ( Theme_Palette::current()['anchors'] ?? array() ) as $slug => $hex ) {
			if ( ! isset( $colours[ $slug ] ) ) {
				$colours[ (string) $slug ] = (string) $hex;
			}
		}
		$colours = array_filter( $colours, static fn( $hex ) => Color_Math::parse( (string) $hex ) !== null );
		if ( $colours === array() ) {
			return $block;
		}
		// The colours a slug is drawn in, where the blocks are: on a page of a design, its own colour of the same name takes the class
		// inside the design's scope (`.dxai-ui--N .has-accent-color` is the design's accent, not the theme's).
		$candidates = array_keys( $colours );
		if ( $home > 0 ) {
			foreach ( (array) ( get_post_meta( $home, '_dxai_ui_palette', true )['colors'] ?? array() ) as $row ) {
				$slug = sanitize_key( (string) ( is_array( $row ) ? ( $row['slug'] ?? '' ) : '' ) );
				$hex  = Color_Math::hex( (string) ( is_array( $row ) ? ( $row['value'] ?? '' ) : '' ) );
				if ( $slug !== '' && $hex !== '' ) {
					$colours[ $slug ] = $hex;
				}
			}
		}
		$roles = Template::palette_roles();
		$ctx   = array(
			'colours'    => $colours,
			// A colour put on a block is always one of the theme's (its class is printed on every page).
			'candidates' => $candidates,
			'text'       => (string) ( $roles['text'] ?? '' ),
			'on_dark'    => (string) ( $roles['on_dark'] ?? '' ),
			'light'      => (string) ( $roles['light'] ?? '' ),
		);

		return self::legible_walk( $block, '#ffffff', '', false, $ctx );
	}

	/**
	 * @param array<string, mixed> $b
	 * @param string               $ground  The colour the block stands on, #rrggbb.
	 * @param string               $inherit The colour its words inherit from a box above it (#rrggbb), '' when the design says it.
	 * @param array<string, mixed> $ctx     legible()'s colours and roles.
	 * @return array<string, mixed>
	 */
	private static function legible_walk( array $b, string $ground, string $inherit, bool $in_card, array $ctx ): array {
		$a       = is_array( $b['attrs'] ?? null ) ? $b['attrs'] : array();
		$name    = (string) ( $b['blockName'] ?? '' );
		$colours = (array) $ctx['colours'];
		$is_card = preg_match( '/(?:^|\s)dxai-lib-card(?:\s|$)/', (string) ( $a['className'] ?? '' ) ) === 1;
		// The block's own ground: a preset of the theme, a colour of its own (laid over the ground: a veil of white on a dark band is dark).
		$own = '';
		$bg  = (string) ( $a['backgroundColor'] ?? '' );
		if ( $bg !== '' ) {
			$own = (string) ( $colours[ $bg ] ?? '' );
		} elseif ( is_string( $a['style']['color']['background'] ?? null ) ) {
			$own = self::over( (string) $a['style']['color']['background'], $ground );
		} elseif ( $name === 'core/cover' ) {
			$own = (string) ( $colours[ (string) ( $a['overlayColor'] ?? '' ) ] ?? '#222222' );
		}
		if ( $own !== '' && ( $in_card || $is_card ) && $name !== 'core/button' && self::loud( $own ) ) {
			$light = (string) $ctx['light'];
			if ( $is_card && $light !== '' && isset( $colours[ $light ] ) && ! self::loud( (string) $colours[ $light ] ) ) {
				$b   = self::set_colour( $b, 'background', $light );
				$own = (string) $colours[ $light ];
			} else {
				$b   = self::set_colour( $b, 'background', '' );
				$own = '';
			}
		}
		$here  = $own !== '' ? $own : $ground;
		$dark  = Color_Math::contrast( '#ffffff', $here ) > Color_Math::contrast( '#000000', $here );
		$pick  = array_intersect_key( $colours, array_flip( (array) $ctx['candidates'] ) );
		$want  = self::fit( $dark ? (string) $ctx['on_dark'] : (string) $ctx['text'], $dark ? '#ffffff' : '#000000', $here, $pick );
		// The words' colour: their own, a box's above them, or the design's.
		$tc     = (string) ( $a['textColor'] ?? '' );
		$custom = is_string( $a['style']['color']['text'] ?? null ) ? (string) $a['style']['color']['text'] : '';
		$mine   = $tc !== '' ? (string) ( $colours[ $tc ] ?? '?' ) : ( $custom !== '' ? self::over( $custom, $here ) : '' );
		$heir_reads = $inherit !== '' && Color_Math::contrast( $inherit, $here ) >= 3.0;
		// (A button is judged by the colours set on it — its words on its own ground; one drawn by its style alone is the theme's.)
		if ( $name !== 'core/buttons' && $name !== 'core/image' && ( $name !== 'core/button' || $mine !== '' ) ) {
			if ( $mine === '?' || ( $mine !== '' && Color_Math::contrast( $mine, $here ) < 3.0 ) ) {
				// A colour that does not read here (or that the theme does not have): on a light ground the colour around it when that
				// reads (the design's, or a box's), else — and on a dark ground — the theme's colour for this ground.
				$to = ! $dark && ( $inherit === '' || $heir_reads ) ? '' : $want;
				// A colour that was a colour (an accent, a brand's), not a grey: a colour of the theme that reads here keeps it one.
				$rgb = $mine !== '?' ? Color_Math::parse( $mine ) : null;
				if ( $rgb !== null && Color_Math::oklch( $rgb )[1] > 0.08 ) {
					$best = '';
					$sat  = 0.08;
					foreach ( $pick as $s => $hex ) {
						$c = Color_Math::parse( (string) $hex );
						if ( $c !== null && Color_Math::oklch( $c )[1] > $sat && Color_Math::contrast( (string) $hex, $here ) >= 4.5 ) {
							$sat  = Color_Math::oklch( $c )[1];
							$best = (string) $s;
						}
					}
					$to = $best !== '' ? $best : $to;
				}
				$b    = self::set_colour( $b, 'text', $to );
				$mine = $to !== '' ? (string) ( $colours[ $to ] ?? '' ) : '';
			} elseif ( $mine === '' && in_array( $name, array( 'core/heading', 'core/paragraph', 'core/list', 'amr/span', 'core/details', 'core/quote', 'dxai-ui/text' ), true ) ) {
				// Words with no colour of their own: on a dark ground unless a box above gave them one that reads, and on a light ground
				// under a box's colour that does not read here, the theme's colour for this ground.
				if ( ( $dark && ! $heir_reads ) || ( ! $dark && $inherit !== '' && ! $heir_reads ) ) {
					$b    = self::set_colour( $b, 'text', $want );
					$mine = (string) ( $colours[ $want ] ?? '' );
				}
			}
		}
		$pass = $mine !== '' && $mine !== '?' ? $mine : $inherit;
		foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $i => $child ) {
			if ( is_array( $child ) && ! empty( $child['blockName'] ) ) {
				$b['innerBlocks'][ $i ] = self::legible_walk( $child, $here, $pass, $in_card || $is_card, $ctx );
			}
		}

		return $b;
	}

	/** The slug a role names when it reads on the ground (contrast 4.5), else the theme's colour that reads best there; '' when none does. */
	private static function fit( string $slug, string $fallback, string $ground, array $colours ): string {
		if ( $slug !== '' && isset( $colours[ $slug ] ) && Color_Math::contrast( (string) $colours[ $slug ], $ground ) >= 4.5 ) {
			return $slug;
		}
		$best  = '';
		$score = 0.0;
		foreach ( $colours as $s => $hex ) {
			$c = Color_Math::contrast( (string) $hex, $ground );
			if ( $c > $score ) {
				$score = $c;
				$best  = (string) $s;
			}
		}

		return $score >= 3.0 ? $best : '';
	}

	/** A colour laid over the ground it stands on, as #rrggbb; '' when it cannot be read (a variable, a gradient). */
	private static function over( string $css, string $ground ): string {
		$top   = Color_Math::parse( $css );
		$under = Color_Math::parse( $ground );
		if ( $top === null || $under === null ) {
			return '';
		}
		$c = Color_Math::over( $top, $under );

		return sprintf( '#%02x%02x%02x', (int) round( $c[0] ), (int) round( $c[1] ), (int) round( $c[2] ) );
	}

	/** Whether a colour is loud for a card: bright (neither a dark ground nor a light one) and saturated. */
	private static function loud( string $hex ): bool {
		$rgb = Color_Math::parse( $hex );
		if ( $rgb === null ) {
			return false;
		}
		$l = Color_Math::luminance( $rgb );

		return $l > 0.3 && $l < 0.85 && Color_Math::oklch( $rgb )[1] > 0.1;
	}

	/**
	 * A block with its text or background colour set to a preset of the theme ('' takes it off): the setting, and what the setting wrote
	 * in the block's own tag (its classes and its declaration).
	 *
	 * @param array<string, mixed> $b
	 * @return array<string, mixed>
	 */
	private static function set_colour( array $b, string $kind, string $slug ): array {
		$attr = $kind === 'text' ? 'textColor' : 'backgroundColor';
		$old  = (string) ( $b['attrs'][ $attr ] ?? '' );
		unset( $b['attrs'][ $attr ], $b['attrs']['style']['color'][ $kind ] );
		if ( isset( $b['attrs']['style']['color'] ) && $b['attrs']['style']['color'] === array() ) {
			unset( $b['attrs']['style']['color'] );
		}
		if ( isset( $b['attrs']['style'] ) && $b['attrs']['style'] === array() ) {
			unset( $b['attrs']['style'] );
		}
		if ( $slug !== '' ) {
			$b['attrs'][ $attr ] = $slug;
		}
		$suffix = $kind === 'text' ? 'color' : 'background-color';
		$flag   = $kind === 'text' ? 'has-text-color' : 'has-background';
		$prop   = $kind === 'text' ? '(?<![\w-])color' : 'background(?:-color)?';
		// A button's settings are written on its link, not on the box around it.
		$open   = (string) ( $b['blockName'] ?? '' ) === 'core/button' ? '/(<a\b)([^>]*)(>)/i' : '/^(\s*<[a-z][a-z0-9]*\b)([^>]*)(>)/i';
		$tag    = static function ( string $html ) use ( $old, $slug, $suffix, $flag, $prop, $open ): string {
			return (string) preg_replace_callback(
				$open,
				static function ( array $m ) use ( $old, $slug, $suffix, $flag, $prop ): string {
					$attrs = (string) preg_replace( '/(\sstyle="[^"]*?)' . $prop . ':[^;"]*;?/i', '$1', $m[2] );
					$attrs = (string) preg_replace( '/\sstyle=""/', '', $attrs );
					$attrs = (string) preg_replace_callback(
						'/\sclass="([^"]*)"/',
						static function ( array $c ) use ( $old, $slug, $suffix, $flag ): string {
							// Only what this setting wrote: its flag and its preset's class (a background's classes stay when the words change).
							$list = array_values( array_filter( preg_split( '/\s+/', $c[1] ) ?: array(), static fn( $t ) => $t !== '' && $t !== $flag && ( $old === '' || $t !== 'has-' . $old . '-' . $suffix ) ) );
							if ( $slug !== '' ) {
								$list[] = 'has-' . $slug . '-' . $suffix;
								$list[] = $flag;
							}

							return $list === array() ? '' : ' class="' . implode( ' ', $list ) . '"';
						},
						$attrs,
						1,
						$n
					);
					if ( ! $n && $slug !== '' ) {
						$attrs .= ' class="has-' . $slug . '-' . $suffix . ' ' . $flag . '"';
					}

					return $m[1] . $attrs . $m[3];
				},
				$html,
				1
			);
		};
		$b['innerHTML'] = $tag( (string) ( $b['innerHTML'] ?? '' ) );
		foreach ( (array) ( $b['innerContent'] ?? array() ) as $k => $part ) {
			if ( is_string( $part ) && trim( $part ) !== '' ) {
				$b['innerContent'][ $k ] = $tag( $part );
				break;
			}
		}

		return $b;
	}

	/**
	 * What the library keeps for itself is not left in the page.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function markers( array &$block ): void {
		unset( $block['attrs']['dxaiIf'], $block['attrs']['dxaiRepeat'], $block['attrs']['dxaiIcon'], $block['attrs']['dxaiCount'], $block['attrs']['dxaiSlice'], $block['attrs']['dxaiProvisional'] );
		foreach ( $block['innerBlocks'] as &$child ) {
			self::markers( $child );
		}
		unset( $child );
	}
}

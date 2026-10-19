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
use DXAI_UI\Blocks\Native\Button;
use DXAI_UI\Theme\Capabilities;
use DXAI_UI\Theme\Theme_Buttons;
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
	 * The library's sections this site can make for a design's pages, each with its id, its role and what it is (the panel offers them
	 * to add to a page by hand). None where the library cannot be used (availability()).
	 *
	 * @return array<int, array{id:string, role:string, label:string}>
	 */
	public static function choices( int $home ): array {
		if ( ! self::theme_ok() ) {
			return array();
		}
		$facts = self::facts( $home, Home_Kit::facts( $home ) );
		$out   = array();
		foreach ( self::entries() as $id => $entry ) {
			if ( self::usable( $entry, $facts )['ok'] ) {
				$out[] = array(
					'id'    => (string) ( $entry['id'] ?? $id ),
					'role'  => (string) $entry['role'],
					'label' => (string) $entry['label'],
				);
			}
		}

		return $out;
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
		// A box laid over a picture the layout has not (a figure in a corner of an empty column) lies over one of the Home's: a slot for
		// the page's picture goes under it, and with no picture left for the page the layout is not made (tokens(): it needs one).
		self::picture_under( $root );
		// Cards with a slot for their words and nothing of the page's to put in it: a box with a title and buttons is no card.
		if ( self::bare_cards( $root, $lists ) ) {
			$why = 'its cards would hold a title alone';

			return null;
		}
		// A figure the layout shows as a figure takes one of the Home's (figures()).
		$stats = (array) ( $lists['stats'] ?? array() );
		self::figures( $root, $stats );
		// The lines the page has said, given back when this layout is not used (a layout given up takes none of them with it).
		$said = Layouts::said_state( $facts );
		if ( ! self::repeats( $root, $lists, $facts, $missing ) ) {
			$why = 'it has nothing to list';
			Layouts::said_back( $facts, $said );

			return null;
		}
		// The drawings after the lists: a card of a list may name its own drawing (a row token, resolved as the copy was made). (A plan makes no file: it is `dry`.)
		self::icons( $root, ! empty( $facts['dry'] ), (string) ( $facts['icon_color'] ?? '' ) );
		self::tokens( $root, $facts, null, $missing );
		if ( $missing !== array() ) {
			$why = 'it needs ' . implode( ', ', array_unique( $missing ) );
			Layouts::said_back( $facts, $said );

			return null;
		}
		// Provisional words say the same few things in other words: a section carries one paragraph of them, not three in a row.
		$kept = false;
		self::thin( $root, $kept );
		self::markers( $root );
		if ( str_contains( serialize_block( $root ), '{{' ) ) {
			$why = 'a token was left';
			Layouts::said_back( $facts, $said );

			return null;
		}
		// A line left with no words (a row that has a title and nothing more: the slot for its words is empty) is not left on the page.
		self::prune_empty( $root );
		// A wave over the section's edge is the colour of a neighbour on the team's site, not this page's (edges()).
		self::edges( $root );
		$root = Layouts::finish( $root );
		// The words in the page's own font where the theme has not the team's (fonts()).
		self::fonts( $root );
		// On a theme with no template of its own: core blocks, and the colours this theme has for the roles.
		if ( Template::is_generic() ) {
			$root = self::generic( $root );
		}
		// A section that shows one of the Home's pictures counts towards the page's share of them (Layouts::page_facts gives no more after four).
		if ( (string) ( $facts['image_url:1'] ?? '' ) !== '' && str_contains( serialize_block( $root ), (string) $facts['image_url:1'] ) ) {
			Layouts::pictured( $facts, 1 );
		}
		// …and each picture it shows is one the page has shown.
		foreach ( array( 1, 2, 3 ) as $n ) {
			if ( (string) ( $facts[ 'image_url:' . $n ] ?? '' ) !== '' && str_contains( serialize_block( $root ), (string) $facts[ 'image_url:' . $n ] ) ) {
				Layouts::saw( $facts, (string) $facts[ 'image_url:' . $n ] );
			}
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
		return self::legible( self::follow( $root, (int) ( $facts['home'] ?? 0 ), (string) ( $facts['role'] ?? '' ) ), (int) ( $facts['home'] ?? 0 ) );
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
	private static function follow( array $block, int $home, string $role = '' ): array {
		if ( $home < 1 ) {
			return $block;
		}

		return \DXAI_UI\Blocks\Native\Library_Style::follow( $block, $home, $role );
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
		// One pass, each slug read whole: a slug replaced is never read again (on Twenty Twenty-Five `primary` becomes `accent-3`,
		// which one replacement after another turned into `accent-1-3` through the template's `accent`).
		$slugs = Template::built_in_slugs();
		$swap  = static function ( string $s ) use ( $map, $slugs ): string {
			$to = static fn( string $old ): ?string => in_array( $old, $slugs, true ) && ( $map[ $old ] ?? '' ) !== $old ? (string) ( $map[ $old ] ?? '' ) : null;
			$s  = (string) preg_replace_callback(
				'/\bhas-([a-z0-9-]+?)-(background-color|border-color|color)\b\s?/',
				static function ( array $m ) use ( $to ): string {
					$new = $to( $m[1] );
					return $new === null ? $m[0] : ( $new === '' ? '' : 'has-' . $new . '-' . $m[2] . ' ' );
				},
				$s
			);
			$s  = (string) preg_replace_callback(
				'/var\(--wp--preset--color--([a-z0-9-]+)\)/',
				static function ( array $m ) use ( $to ): string {
					$new = $to( $m[1] );
					return $new === null ? $m[0] : ( $new === '' ? 'inherit' : 'var(--wp--preset--color--' . $new . ')' );
				},
				$s
			);

			return (string) preg_replace_callback(
				'/var:preset\|color\|([a-z0-9-]+)/',
				static function ( array $m ) use ( $to ): string {
					$new = $to( $m[1] );
					return $new === null ? $m[0] : ( $new === '' ? '' : 'var:preset|color|' . $new );
				},
				$s
			);
		};
		// The class names the block keeps in its comment are the ones its HTML has: the same colours.
		if ( isset( $block['attrs']['className'] ) ) {
			$block['attrs']['className'] = trim( (string) preg_replace( '/\s+/', ' ', $swap( (string) $block['attrs']['className'] ) ) );
			if ( $block['attrs']['className'] === '' ) {
				unset( $block['attrs']['className'] );
			}
		}
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
				// An item of the list the team's section held apart from its rows (the source's last step written another way: "Phase 3", or
				// "Phase 5 · Final Walkthrough and Reopening" in the source site's own words) goes with the list: the page lists its own rows.
				// It has the row's shape and its words are still the layout's (its tokens are not filled yet; a row made here has none left).
				$shape = self::shape( $child );
				for ( $j = count( $block['innerBlocks'] ) - 1; $j >= 0; $j-- ) {
					$other = $block['innerBlocks'][ $j ];
					if ( $j !== $i && ! isset( $other['attrs']['dxaiRepeat'] ) && self::shape( $other ) === $shape && str_contains( serialize_block( $other ), '{{t:' ) ) {
						Block_Tree::drop( $block, $j );
						if ( $j < $i ) {
							--$i;
						}
					}
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
	 * Whether a list of cards would show its rows' titles alone: the row has a slot for its words, no picture and no drawing, and none of
	 * the rows the page has says anything but its title (the Home's points: "About 60 min arrival", "In-house crews"). Made of such a
	 * layout a section was a grid of boxes with a title and two buttons in each ("Call / Learn more", Taylored Restoration's pages).
	 *
	 * @param array<string, mixed>                          $block
	 * @param array<string, array<int, array<string, mixed>>> $lists
	 */
	private static function bare_cards( array $block, array $lists ): bool {
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			if ( isset( $child['attrs']['dxaiRepeat'] ) ) {
				$tpl  = serialize_block( $child );
				$rows = array_values( array_filter( (array) ( $lists[ (string) $child['attrs']['dxaiRepeat'] ] ?? array() ), static fn( array $r ) => trim( (string) ( $r['title'] ?? '' ) ) !== '' ) );
				if ( $rows !== array() && str_contains( $tpl, '{{row:text' ) && preg_match( '/\{\{row:(?:icon|image)|dxaiIcon|\{\{icon/', $tpl ) !== 1
					&& array_filter( $rows, static fn( $r ) => trim( (string) ( $r['text'] ?? '' ) ) !== '' ) === array() ) {
					return true;
				}
				continue;
			}
			if ( self::bare_cards( $child, $lists ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A column whose blocks are all laid over it (`absolute`: a figure's badge in its corner) has no picture of its own for them to lie on:
	 * on the team's site the picture was the column's background, which the library does not keep (two columns · team layout 2, where a
	 * badge floated over an empty half of the page). It gets a slot for the page's picture, under them: the theme's picture block where
	 * the theme has one (as the library's own layouts write it), else core's.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function picture_under( array &$block ): void {
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $i => $child ) {
			if ( ! is_array( $child ) || empty( $child['blockName'] ) ) {
				continue;
			}
			if ( $child['blockName'] === 'core/column' ) {
				$kids = array_values( array_filter( (array) $child['innerBlocks'], static fn( $c ): bool => is_array( $c ) && ! empty( $c['blockName'] ) ) );
				$over = array_filter( $kids, static fn( array $c ): bool => preg_match( '/(?:^|\s)absolute(?:\s|$)/', (string) ( $c['attrs']['className'] ?? '' ) ) === 1 );
				if ( $kids !== array() && count( $over ) === count( $kids ) && preg_match( '/<img\b|wp:(?:core\/)?(?:image|cover|video)\b|wp:dx\/picture\b|background-image|"backgroundImage"/', serialize_block( $child ) ) !== 1 ) {
					$theirs = Capabilities::picture_block();
					$slot   = $theirs !== Capabilities::CORE_BLOCKS['picture']
						? '<!-- wp:' . $theirs . ' {"imageId":"{{image_id:1}}","imageUrl":"{{image_url:1}}","imageAlt":"{{image_alt:1|}}","imageWidth":"{{image_w:1}}","imageHeight":"{{image_h:1}}","className":"w-full overflow-hidden"} /-->'
						: '<!-- wp:image {"sizeSlug":"large","className":"w-full"} --><figure class="wp-block-image size-large w-full"><img src="{{image_url:1}}" alt="{{image_alt:1|}}"/></figure><!-- /wp:image -->';
					Block_Tree::insert( $block['innerBlocks'][ $i ], 0, parse_blocks( $slot )[0] );
				}
				continue;
			}
			self::picture_under( $block['innerBlocks'][ $i ] );
		}
	}

	/**
	 * A figure the team's layout shows as one — a number set large over its words in a badge ("10,000+ / properties restored") — written
	 * as the Home states its own figure ("25,000+ / Alaska homes restored"), one figure a badge. The layout keeps no figure of its site:
	 * its slots are for words, and words were what filled them (a sentence set at 38px, over "Properties restored"). With no figure of
	 * the Home's left, the badge goes.
	 *
	 * @param array<string, mixed>             $block
	 * @param array<int, array<string, mixed>> $stats The Home's figures, in its order; those used are taken off.
	 */
	private static function figures( array &$block, array &$stats ): void {
		$token = '/\{\{t:[a-z0-9-]+(?:\|[^{}]*)?\}\}/';
		$only  = static function ( array $b ) use ( $token ): bool {
			return in_array( (string) ( $b['blockName'] ?? '' ), array( 'core/paragraph', 'core/heading' ), true ) && preg_match( '/^\s*<[^>]+>\s*' . trim( $token, '/' ) . '\s*<\/[a-z0-9]+>\s*$/i', (string) ( $b['innerHTML'] ?? '' ) ) === 1;
		};
		$large = static function ( array $b ): bool {
			$size = (string) ( $b['attrs']['style']['typography']['fontSize'] ?? '' );

			return ( preg_match( '/^(\d+(?:\.\d+)?)px$/', $size, $m ) === 1 && (float) $m[1] >= 28 ) || preg_match( '/(?:^|-)(?:x{1,3}-?large|huge|gigantic|[4-9]xl)$/', (string) ( $b['attrs']['fontSize'] ?? '' ) ) === 1;
		};
		for ( $i = 0; $i < count( (array) ( $block['innerBlocks'] ?? array() ) ); $i++ ) {
			$child = $block['innerBlocks'][ $i ];
			if ( ! is_array( $child ) ) {
				continue;
			}
			$kids = array_values( array_filter( (array) ( $child['innerBlocks'] ?? array() ), static fn( $c ) => is_array( $c ) && ! empty( $c['blockName'] ) ) );
			// (A paragraph for a few words set large: a section's heading over its line, which is set large too, is no figure.)
			if ( ! isset( $child['attrs']['dxaiRepeat'] ) && count( $kids ) === 2 && $only( $kids[0] ) && $only( $kids[1] ) && $large( $kids[0] ) && ! $large( $kids[1] )
				&& $kids[0]['blockName'] === 'core/paragraph' && str_contains( (string) $kids[0]['innerHTML'], '{{t:p-s' ) ) {
				$figure = null;
				while ( $stats !== array() && $figure === null ) {
					$s = array_shift( $stats );
					// A figure with words of its own (not "60 / min"); a step's number ("01") is none.
					if ( preg_match( '/\d/', (string) ( $s['title'] ?? '' ) ) === 1 && preg_match( '/^0\d$/', trim( (string) $s['title'] ) ) !== 1 && str_word_count( (string) ( $s['text'] ?? '' ) ) >= 2 ) {
						$figure = $s;
					}
				}
				if ( $figure === null ) {
					Block_Tree::drop( $block, $i );
					--$i;
					continue;
				}
				$n = 0;
				foreach ( (array) $child['innerBlocks'] as $k => $kid ) {
					if ( ! is_array( $kid ) || empty( $kid['blockName'] ) ) {
						continue;
					}
					$text = esc_html( trim( (string) ( $n === 0 ? $figure['title'] : $figure['text'] ) ) );
					$put  = static fn( string $s ): string => (string) preg_replace_callback( $token, static fn() => $text, $s, 1 );
					$kid['innerHTML'] = $put( (string) $kid['innerHTML'] );
					foreach ( (array) $kid['innerContent'] as $c => $part ) {
						if ( is_string( $part ) ) {
							$kid['innerContent'][ $c ] = $put( $part );
						}
					}
					$child['innerBlocks'][ $k ] = $kid;
					++$n;
				}
				$block['innerBlocks'][ $i ] = $child;
				continue;
			}
			self::figures( $child, $stats );
			$block['innerBlocks'][ $i ] = $child;
		}
	}

	/**
	 * What a block is made of: its name and its blocks' names, nested (`core/group(core/group(core/paragraph()),core/heading())`).
	 *
	 * @param array<string, mixed> $block
	 */
	private static function shape( array $block ): string {
		$kids = array_values( array_filter( (array) ( $block['innerBlocks'] ?? array() ), static fn( $c ) => is_array( $c ) && ! empty( $c['blockName'] ) ) );
		// A group around one group is the group inside it: a layout wrote its last step's number in one box more than its rows' ("Phase 4").
		if ( ( $block['blockName'] ?? '' ) === 'core/group' && count( $kids ) === 1 && $kids[0]['blockName'] === 'core/group' ) {
			return self::shape( $kids[0] );
		}

		return (string) ( $block['blockName'] ?? '' ) . '(' . implode( ',', array_map( array( self::class, 'shape' ), $kids ) ) . ')';
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
		// A caption (small words beside a button: the team's "Available 24/7", a claim of their site) has no words of the page's to say: a
		// sentence of provisional words set at 13px under a call to action's button is not one. Without the team's own words it is left out.
		$size        = (string) ( $block['attrs']['style']['typography']['fontSize'] ?? '' );
		// (A line set in capitals is a label too: a sentence in capitals in a strip's corner read as shouting.)
		$caption     = preg_match( '/(?:^|\s)(?:text-1[0-3]|uppercase)(?:\s|$)/', (string) ( $block['attrs']['className'] ?? '' ) ) === 1
			|| ( $block['attrs']['style']['typography']['textTransform'] ?? '' ) === 'uppercase'
			|| in_array( (string) ( $block['attrs']['fontSize'] ?? '' ), array( 'small', 'x-small', 'tiny' ), true )
			|| ( preg_match( '/^(\d+(?:\.\d+)?)px$/', $size, $px ) === 1 && (float) $px[1] <= 13 );
		$sub         = static function ( string $s, bool $html ) use ( $facts, $row, &$missing, &$cache, &$provisional, $caption ): string {
			if ( ! str_contains( $s, '{{' ) ) {
				return $s;
			}
			$seen = array();

			return (string) preg_replace_callback(
				self::TOKEN,
				static function ( array $m ) use ( $facts, $row, $html, &$missing, &$cache, &$seen, &$provisional, $caption ): string {
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
							// (A call — the team's words with the page's phone in them — is no sentence: a layout says it twice, for a phone and for
							// a screen, and the second, said already, left a link to the phone with no words.)
							$call = (string) ( $facts['phone'] ?? '' ) !== '' && str_contains( $team, (string) $facts['phone'] );
							if ( $once && $team !== '' && ! $call && Layouts::said( $facts, $team, false ) ) {
								$team = '';
							}
							$cache[ $key ] = $team !== '' ? $team : ( $caption && str_starts_with( $arg, 'p-' ) ? '' : Layouts::words( $arg, $facts, $row ) );
							if ( $once && $team === '' && $cache[ $key ] !== '' && Layouts::said( $facts, $cache[ $key ], false ) ) {
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
						// (A paragraph the page has no words left for is empty, and goes: prune_empty().)
						$value       = $cache[ $key ] === '' ? ( str_starts_with( $arg, 'p-' ) ? '' : null ) : $cache[ $key ];
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
			// "Contact us" over a section that lists no way to reach the company is another section's label.
			'/\b(?:contact(?:\s+us)?|get\s+in\s+touch|reach\s+us)\b/i'     => array( 'contact' ),
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
	 * Font families the page does not draw, taken off the section's blocks. The team's layouts come from sites with families of their
	 * own (Barlow Condensed, Inter, Montserrat, Source Sans 3): where the page has no such font the setting names one no stylesheet gives,
	 * and the words fell back to the browser's own (Taylored Restoration's site on the network: a call to action's heading in a heavy
	 * Arial, its questions in Arial, 2026-10-16). A family's name in the theme's settings is not enough: American Restoration names
	 * seventeen, Inter among them, and loads two. What the page draws: the theme's heading and body fonts when it manages them
	 * (Theme_Fonts: Theme Global Settings, or a design's installed with its template), a family whose faces WordPress prints
	 * (`fontFace`), a system stack. The words take the page's font instead.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function fonts( array &$block ): void {
		$first = static fn( string $stack ): string => strtolower( trim( (string) strtok( $stack, ',' ), " \t\n\r\0\x0B\"'" ) );
		$drawn = array();
		$theme = Capabilities::fonts();
		foreach ( null === $theme ? array() : array( $theme['heading'], $theme['body'] ) as $stack ) {
			if ( $first( (string) $stack ) !== '' ) {
				$drawn[ $first( (string) $stack ) ] = true;
			}
		}
		$have = array();
		foreach ( (array) wp_get_global_settings( array( 'typography', 'fontFamilies' ) ) as $rows ) {
			foreach ( (array) $rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['slug'] ) ) {
					continue;
				}
				$stack = (string) ( $row['fontFamily'] ?? '' );
				if ( ! empty( $row['fontFace'] ) || isset( $drawn[ $first( $stack ) ] ) || preg_match( '/^\s*(?:-apple-system|system-ui|ui-sans-serif|ui-serif|ui-monospace|sans-serif|serif|monospace)\b/i', $stack ) === 1 ) {
					$have[ _wp_to_kebab_case( (string) $row['slug'] ) ] = true;
				}
			}
		}
		self::fonts_walk( $block, $have );
	}

	/**
	 * @param array<string, mixed> $block
	 * @param array<string, true>  $have The families the page draws, by slug (kebab-case, as their classes have them).
	 */
	private static function fonts_walk( array &$block, array $have ): void {
		$gone  = array();
		$slug  = _wp_to_kebab_case( (string) ( $block['attrs']['fontFamily'] ?? '' ) );
		if ( $slug !== '' && ! isset( $have[ $slug ] ) ) {
			unset( $block['attrs']['fontFamily'] );
			$gone[] = '/\s?(?<![\w-])has-' . preg_quote( $slug, '/' ) . '-font-family(?![\w-])/';
		}
		$own = $block['attrs']['style']['typography']['fontFamily'] ?? null;
		if ( is_string( $own ) && preg_match( '/^var:preset\|font-family\|([a-z0-9-]+)$/', $own, $m ) === 1 && ! isset( $have[ _wp_to_kebab_case( $m[1] ) ] ) ) {
			unset( $block['attrs']['style']['typography']['fontFamily'] );
			if ( $block['attrs']['style']['typography'] === array() ) {
				unset( $block['attrs']['style']['typography'] );
			}
			if ( $block['attrs']['style'] === array() ) {
				unset( $block['attrs']['style'] );
			}
			$gone[] = '/(?<![\w-])font-family:\s*var\(--wp--preset--font-family--' . preg_quote( $m[1], '/' ) . '\);?/';
		}
		if ( $gone !== array() ) {
			// What the setting wrote, in the block's own markup (a button writes it on its link, not on the box around it).
			$tag = static function ( string $html ) use ( $gone ): string {
				return (string) preg_replace_callback(
					'/<[a-z][a-z0-9]*\b[^>]*>/i',
					static fn( array $t ): string => (string) preg_replace( array( '/\sstyle="\s*"/', '/\sclass="\s*"/' ), '', (string) preg_replace( $gone, '', $t[0] ) ),
					$html
				);
			};
			$block['innerHTML'] = $tag( (string) ( $block['innerHTML'] ?? '' ) );
			foreach ( (array) ( $block['innerContent'] ?? array() ) as $k => $part ) {
				if ( is_string( $part ) ) {
					$block['innerContent'][ $k ] = $tag( $part );
				}
			}
		}
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $i => $child ) {
			if ( is_array( $child ) ) {
				self::fonts_walk( $block['innerBlocks'][ $i ], $have );
			}
		}
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
			if ( ( in_array( $name, array( 'core/paragraph', 'core/heading', 'core/list-item', 'dxai-ui/text' ), true ) || Capabilities::is_block( $name, 'span' ) ) &&(array) ( $child['innerBlocks'] ?? array() ) === array() && trim( wp_strip_all_tags( (string) $child['innerHTML'] ) ) === '' && ! str_contains( (string) $child['innerHTML'], '<img' ) ) {
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
	 * A wave laid over a section's edge (a transition break set `absolute` at its bottom) is drawn in the colour of the section that
	 * followed it on the team's site. On a page made here another section follows, so the wave is not drawn: on Taylored Restoration's
	 * pages a navy wave a third of the page wide hung over a white section (an absolute box with no width is as wide as its drawing).
	 * A break in the flow of a section, between its own parts, stays.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function edges( array &$block ): void {
		for ( $i = count( $block['innerBlocks'] ) - 1; $i >= 0; $i-- ) {
			$child = $block['innerBlocks'][ $i ];
			if ( (string) ( $child['blockName'] ?? '' ) === 'dx/transition-break' && preg_match( '/(?:^|\s)absolute(?:\s|$)/', (string) ( $child['attrs']['className'] ?? '' ) ) === 1 ) {
				Block_Tree::drop( $block, $i );
				continue;
			}
			self::edges( $block['innerBlocks'][ $i ] );
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
		// inside the design's scope (`.dxai-ui--N .has-accent-color` is the design's accent, not the theme's). A design that follows the
		// theme's colours (Theme_Binding) draws the theme's own in a class the theme has — the accent band of a team's layout is the
		// theme's accent there — and its own only in a class the theme has not (its `on-accent`, which the plugin draws).
		$candidates = array_keys( $colours );
		if ( $home > 0 ) {
			$follows = \DXAI_UI\Theme\Theme_Binding::follows( $home );
			foreach ( (array) ( get_post_meta( $home, '_dxai_ui_palette', true )['colors'] ?? array() ) as $row ) {
				$slug = sanitize_key( (string) ( is_array( $row ) ? ( $row['slug'] ?? '' ) : '' ) );
				$hex  = Color_Math::hex( (string) ( is_array( $row ) ? ( $row['value'] ?? '' ) : '' ) );
				if ( $slug !== '' && $hex !== '' && ( ! $follows || ! isset( $colours[ $slug ] ) ) ) {
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
			'home'       => $home,
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
		} elseif ( (int) ( $ctx['home'] ?? 0 ) > 0 && ( preg_match( '/(?:^|\s)bg-/', (string) ( $a['className'] ?? '' ) ) === 1 || str_contains( (string) ( $a['dxaiCss'] ?? '' ), 'background' ) ) ) {
			// A ground of the Home's, by its class or its own CSS (the band of its call to action, put on a section by Library_Style).
			$own = \DXAI_UI\Blocks\Native\Button::ground_hex( $a, (int) $ctx['home'] );
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
		// A button drawn by the theme's style alone, in the colour of the band it stands on, is not seen (on_band()).
		if ( $name === 'core/button' && $own === '' ) {
			$b = self::on_band( $b, $here );
		}
		$dark  = Color_Math::contrast( '#ffffff', $here ) > Color_Math::contrast( '#000000', $here );
		$pick  = array_intersect_key( $colours, array_flip( (array) $ctx['candidates'] ) );
		$want  = self::fit( $dark ? (string) $ctx['on_dark'] : (string) $ctx['text'], $dark ? '#ffffff' : '#000000', $here, $pick );
		// The words' colour: their own, a box's above them, or the design's.
		$tc     = (string) ( $a['textColor'] ?? '' );
		$custom = is_string( $a['style']['color']['text'] ?? null ) ? (string) $a['style']['color']['text'] : '';
		// (A colour of the theme set as the block's own, `var:preset|color|{slug}`, is that colour.)
		$preset = preg_match( '/^var:preset\|color\|([a-z0-9-]+)$/', $custom, $pm ) === 1 ? (string) ( $colours[ $pm[1] ] ?? '?' ) : '';
		$mine   = $tc !== '' ? (string) ( $colours[ $tc ] ?? '?' ) : ( $preset !== '' ? $preset : ( $custom !== '' ? self::over( $custom, $here ) : '' ) );
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
			} elseif ( $mine === '' && ( in_array( $name, array( 'core/heading', 'core/paragraph', 'core/list', 'core/details', 'core/quote', 'dxai-ui/text' ), true ) || Capabilities::is_block( $name, 'span' ) ) ) {
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

	/**
	 * A button the theme's filled style draws in the colour of the band it stands on — a team's layout set on the accent, its button the
	 * theme's primary, which is the accent on american-restoration — is a word with no button around it (Taylored Restoration's "Need
	 * restoration help now?"): it wears the theme's other style when that one stands apart, as an import's button does
	 * (Native\Button::on_band()). A button with a colour of its own is judged by its own colours, and is left as it is here.
	 *
	 * @param array<string, mixed> $b      A core/button.
	 * @param string               $ground What it stands on, #rrggbb.
	 * @return array<string, mixed>
	 */
	private static function on_band( array $b, string $ground ): array {
		$class = (string) ( $b['attrs']['className'] ?? '' );
		if ( preg_match( '/(?:^|\s)is-style-((?:small-)?primary-button)(?=\s|$)/', $class, $m ) !== 1 ) {
			return $b;
		}
		$fill  = Theme_Buttons::fill( $m[1] );
		$other = Theme_Buttons::has( 'secondary-button' ) ? Theme_Buttons::fill( 'secondary-button' ) : '';
		if ( $fill === '' || $other === '' || Color_Math::distance( $fill, $ground ) >= Button::APART || Color_Math::distance( $other, $ground ) < Button::APART ) {
			return $b;
		}
		$swap                    = static fn( string $s ): string => (string) preg_replace( '/(?<=[\s"])is-style-' . preg_quote( $m[1], '/' ) . '(?=[\s"])/', 'is-style-secondary-button', $s, 1 );
		$b['attrs']['className'] = trim( $swap( ' ' . $class . ' ' ) );
		$b['innerHTML']          = $swap( (string) $b['innerHTML'] );
		foreach ( (array) $b['innerContent'] as $k => $part ) {
			if ( is_string( $part ) ) {
				$b['innerContent'][ $k ] = $swap( $part );
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
	public static function set_colour( array $b, string $kind, string $slug ): array {
		$attr = $kind === 'text' ? 'textColor' : 'backgroundColor';
		$old  = (string) ( $b['attrs'][ $attr ] ?? '' );
		unset( $b['attrs'][ $attr ], $b['attrs']['style']['color'][ $kind ] );
		if ( isset( $b['attrs']['style']['color'] ) && $b['attrs']['style']['color'] === array() ) {
			unset( $b['attrs']['style']['color'] );
		}
		if ( isset( $b['attrs']['style'] ) && $b['attrs']['style'] === array() ) {
			unset( $b['attrs']['style'] );
		}
		// A text colour whose class WordPress gives a meaning of its own (Capabilities::text_slug_ok()) is the block's own colour, from the theme.
		$own = $kind === 'text' && $slug !== '' && ! Capabilities::text_slug_ok( $slug );
		if ( $own ) {
			$b['attrs']['style']['color']['text'] = 'var:preset|color|' . $slug;
		} elseif ( $slug !== '' ) {
			$b['attrs'][ $attr ] = $slug;
		}
		$suffix = $kind === 'text' ? 'color' : 'background-color';
		$flag   = $kind === 'text' ? 'has-text-color' : 'has-background';
		$prop   = $kind === 'text' ? '(?<![\w-])color' : 'background(?:-color)?';
		$decl   = $own ? 'color:var(--wp--preset--color--' . $slug . ')' : '';
		// A button's settings are written on its link, not on the box around it.
		$open   = (string) ( $b['blockName'] ?? '' ) === 'core/button' ? '/(<a\b)([^>]*)(>)/i' : '/^(\s*<[a-z][a-z0-9]*\b)([^>]*)(>)/i';
		$tag    = static function ( string $html ) use ( $old, $slug, $suffix, $flag, $prop, $open, $own, $decl ): string {
			return (string) preg_replace_callback(
				$open,
				static function ( array $m ) use ( $old, $slug, $suffix, $flag, $prop, $own, $decl ): string {
					$attrs = (string) preg_replace( '/(\sstyle="[^"]*?)' . $prop . ':[^;"]*;?/i', '$1', $m[2] );
					$attrs = (string) preg_replace( '/\sstyle=""/', '', $attrs );
					$attrs = (string) preg_replace_callback(
						'/\sclass="([^"]*)"/',
						static function ( array $c ) use ( $old, $slug, $suffix, $flag, $own ): string {
							// Only what this setting wrote: its flag and its preset's class (a background's classes stay when the words change).
							$list = array_values( array_filter( preg_split( '/\s+/', $c[1] ) ?: array(), static fn( $t ) => $t !== '' && $t !== $flag && ( $old === '' || $t !== 'has-' . $old . '-' . $suffix ) ) );
							if ( $slug !== '' ) {
								if ( ! $own ) {
									$list[] = 'has-' . $slug . '-' . $suffix;
								}
								$list[] = $flag;
							}

							return $list === array() ? '' : ' class="' . implode( ' ', $list ) . '"';
						},
						$attrs,
						1,
						$n
					);
					if ( ! $n && $slug !== '' ) {
						$attrs .= ' class="' . ( $own ? '' : 'has-' . $slug . '-' . $suffix . ' ' ) . $flag . '"';
					}
					// The block's own colour is written as save() writes it: a declaration in its style.
					if ( $decl !== '' ) {
						$attrs = preg_match( '/\sstyle="[^"]*"/', $attrs ) === 1
							? (string) preg_replace_callback( '/\sstyle="([^"]*)"/', static fn( array $s ): string => ' style="' . $decl . ( trim( $s[1] ) !== '' ? ';' . rtrim( trim( $s[1] ), ';' ) : '' ) . '"', $attrs, 1 )
							: $attrs . ' style="' . $decl . '"';
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

<?php
/**
 * The team's own sections, kept in the plugin, for the pages made from a Home.
 *
 * @package DXAI_UI\Pages
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

use DXAI_UI\Media\Svg_Files;
use DXAI_UI\Theme\Capabilities;

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

	/** @var array<string, array<string, mixed>>|null */
	private static ?array $index = null;

	/** @var array<int, string> */
	private static array $themes = array();

	/** @var array<string, string> */
	private static array $markup = array();

	/**
	 * Every section of the library, by id.
	 *
	 * @return array<string, array<string, mixed>> id, role, label, from, builder, needs, pages, tokens, blocks, classes.
	 */
	public static function entries(): array {
		if ( self::$index !== null ) {
			return self::$index;
		}
		self::$index = array();
		$file        = DXAI_UI_DIR . self::DIR . 'library.json';
		$doc         = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $doc ) ) {
			return self::$index;
		}
		self::$themes = array_values( array_filter( array_map( 'strval', (array) ( $doc['themes'] ?? array() ) ) ) );
		foreach ( (array) ( $doc['entries'] ?? array() ) as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) && is_string( $row['id'] ) && preg_match( '/^[a-z0-9-]{2,60}$/', $row['id'] ) === 1 ) {
				self::$index[ $row['id'] ] = $row;
			}
		}

		return self::$index;
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
		if ( ! isset( self::$markup[ $id ] ) ) {
			$file                = DXAI_UI_DIR . self::DIR . 'blocks/' . $id . '.html';
			self::$markup[ $id ] = self::entry( $id ) !== null && is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		return self::$markup[ $id ];
	}

	/**
	 * The themes the library is for: the sections are made of what the team's theme has (its classes, its components, its blocks).
	 *
	 * @return array<int, string>
	 */
	public static function themes(): array {
		self::entries();

		/**
		 * The themes whose pages may take the library's sections.
		 *
		 * @param array<int, string> $themes Theme folder names (the child's or the parent's).
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'dxai_ui_block_library_themes', self::$themes ) ) ) );
	}

	/** Whether the active theme (or its parent) is one the library is for. */
	public static function theme_ok(): bool {
		return array_intersect( array( get_stylesheet(), get_template() ), self::themes() ) !== array();
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
		$out = array(
			'code'     => 'none',
			'theme'    => (string) wp_get_theme()->get( 'Name' ),
			'sections' => count( self::entries() ),
			'usable'   => 0,
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
			if ( ! $registry->is_registered( (string) $name ) ) {
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
			$text = html_entity_decode( wp_strip_all_tags( (string) get_post_field( 'post_content', $home ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
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

		return $title;
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
		$start = $ids === array() ? 0 : (int) ( sprintf( '%u', crc32( $seed . '|' . $role ) ) % count( $ids ) );
		foreach ( array_keys( $ids ) as $k ) {
			$id    = $ids[ ( $start + $k ) % count( $ids ) ];
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
		// The drawings first: a list's item is copied once for each row, with its drawing already in it. (A plan makes no file: it is `dry`.)
		self::icons( $root, ! empty( $facts['dry'] ) );
		$missing = array();
		if ( ! self::repeats( $root, array( 'places' => (array) $facts['places'], 'services' => (array) $facts['services'] ), $facts, $missing ) ) {
			$why = 'it has nothing to list';

			return null;
		}
		self::tokens( $root, $facts, null, $missing );
		if ( $missing !== array() ) {
			$why = 'it needs ' . implode( ', ', array_unique( $missing ) );

			return null;
		}
		self::markers( $root );
		if ( str_contains( serialize_block( $root ), '{{' ) ) {
			$why = 'a token was left';

			return null;
		}

		// For a page of a design (a converted page); a pattern goes on a page of the theme, which has these styles itself.
		if ( (int) ( $facts['home'] ?? 0 ) > 0 ) {
			self::layouts( $root );
		}

		return self::follow( $root, (int) ( $facts['home'] ?? 0 ) );
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
				if ( $rows === array() ) {
					return false;
				}
				unset( $child['attrs']['dxaiRepeat'] );
				foreach ( $rows as $n => $row ) {
					$copy = $child;
					$row  = array(
						'title' => trim( (string) $row['title'] ),
						'url'   => (string) ( $row['url'] ?? '' ),
						'text'  => (string) ( $row['text'] ?? '' ),
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
		foreach ( $block['innerBlocks'] as &$child ) {
			self::unlink( $child );
		}
		unset( $child );
	}

	/**
	 * The small drawings: each is a file in the media library (one for each drawing, found again by its hash).
	 *
	 * @param array<string, mixed> $block
	 */
	private static function icons( array &$block, bool $dry = false ): void {
		for ( $i = count( $block['innerBlocks'] ) - 1; $i >= 0; $i-- ) {
			$child = &$block['innerBlocks'][ $i ];
			if ( isset( $child['attrs']['dxaiIcon'] ) ) {
				$name = (string) $child['attrs']['dxaiIcon'];
				$file = self::icon( $name, $dry );
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
			self::icons( $child, $dry );
			unset( $child );
		}
	}

	/**
	 * The attachment of a drawing the library ships.
	 *
	 * @param bool $dry Only say it is there: no file is made, and the drawing has no address (a plan).
	 * @return array{id:int, url:string}|null
	 */
	public static function icon( string $name, bool $dry = false ): ?array {
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
		$made = Svg_Files::ensure( trim( (string) file_get_contents( $path ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

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
		$sub = static function ( string $s, bool $html ) use ( $facts, $row, &$missing ): string {
			if ( ! str_contains( $s, '{{' ) ) {
				return $s;
			}

			return (string) preg_replace_callback(
				self::TOKEN,
				static function ( array $m ) use ( $facts, $row, $html, &$missing ): string {
					$name    = strtolower( $m[1] );
					$arg     = strtolower( $m[2] ?? '' );
					$default = trim( (string) ( $m[3] ?? '' ) );
					$value   = null;
					if ( $name === 'row' ) {
						$value = $row !== null && isset( $row[ $arg ] ) ? (string) $row[ $arg ] : null;
					} elseif ( $name !== 'icon_url' && $name !== 'icon_id' && isset( $facts[ $name ] ) && is_string( $facts[ $name ] ) && $facts[ $name ] !== '' ) {
						$value = $facts[ $name ];
					}
					if ( $value === null && $default !== '' && $name !== 'row' ) {
						$value = $default;
					}
					if ( $value === null ) {
						$missing[] = $name . ( $arg !== '' ? ':' . $arg : '' );

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
		foreach ( $block['innerBlocks'] as &$child ) {
			self::tokens( $child, $facts, $row, $missing );
		}
		unset( $child );
	}

	/**
	 * What the library keeps for itself is not left in the page.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function markers( array &$block ): void {
		unset( $block['attrs']['dxaiIf'], $block['attrs']['dxaiRepeat'], $block['attrs']['dxaiIcon'] );
		foreach ( $block['innerBlocks'] as &$child ) {
			self::markers( $child );
		}
		unset( $child );
	}
}

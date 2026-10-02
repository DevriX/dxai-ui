<?php
/**
 * The pages made for a design, measured against its Home.
 *
 * @package DXAI_UI\Pages
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

use DXAI_UI\Chrome\Page_Chrome;
use DXAI_UI\Structures\Design_Attach;
use DXAI_UI\Structures\Page_Scope;

/**
 * The rules the pages of a design are held to (docs/PLAN-TEAM-PAGES.md, section 5), the ones the database can answer:
 *
 *  G1  the header and the footer of every page are the Home's own (its first and its last blocks, byte for byte)
 *  G7  the blocks are valid: they parse back to the same markup, and there are no more inline styles, HTML blocks or
 *      unregistered blocks than the Home has
 *  G8  the pages are not copies: of the Home (sections of one structure, long texts word for word) and of each other
 *  G9  nothing foreign: no phone number, e-mail or address of another site, no name of another design, no placeholder
 *      text that the Home does not have
 *
 * The rules that need a browser (colours, fonts, the frame, pixels of reused sections, mobile) are measured by
 * bin/team-quality.cjs from the sections this reports. Reads only.
 */
final class Team_Quality {

	/** What each gate says. */
	public const GATES = array(
		'G1' => "header and footer are the Home's",
		'G7' => 'blocks are valid',
		'G8'  => 'pages are not copies (sections)',
		'G8W' => 'pages are not copies (words)',
		'G9' => 'nothing foreign',
	);

	/** Sections of one structure two pages of a kind may share. */
	public const MAX_SHARED_WITH_PAGE = 0.5;

	/** Sections of one structure a page and the Home may share. */
	public const MAX_SHARED_WITH_HOME = 0.6;

	/** Long texts a page may have word for word from the Home (G8W: the words are written by the Words panel, or by hand). */
	public const MAX_TEXT_FROM_HOME = 0.6;

	private const PLACEHOLDERS = '/lorem ipsum|dolor sit amet|goes here|placeholder|your (?:company|business) name|coming soon|\btodo\b/i';

	/**
	 * Everything measured for a design: its sections and, for every page made for it, its sections and what each gate found.
	 *
	 * @return array{home:array<string, mixed>, tokens:array<string, string>, pages:array<int, array<string, mixed>>, gates:array<string, array<int, array<int, string>>>}
	 */
	public static function measure( int $home ): array {
		$home_markup = (string) get_post_field( 'post_content', $home );
		$chrome      = Section_Library::chrome_markup( $home );
		$hb          = self::top( (string) $chrome['header'] );
		$fb          = self::top( (string) $chrome['footer'] );
		$header_in   = self::embedded( (string) $chrome['header'] );
		$footer_in   = self::embedded( (string) $chrome['footer'] );
		$home_plain  = html_entity_decode( wp_strip_all_tags( $home_markup ), ENT_QUOTES, 'UTF-8' );

		$home_secs    = array();
		$home_sigs    = array();
		$home_classes = array();
		foreach ( array_values( Section_Library::for_page( $home ) ) as $i => $c ) {
			$sig                = Section_Library::signature( $c['block'] );
			$home_sigs[ $sig ]  = ( $home_sigs[ $sig ] ?? 0 ) + 1;
			$home_secs[]        = array(
				'index' => $i,
				'role'  => Section_Roles::of( $c['block'], $i === 0 ),
				'kind'  => (string) $c['kind'],
				'hash'  => self::hash( $c['block'] ),
				'sig'   => md5( $sig ),
				'root'  => self::root_key( $c['block'] ),
			);
			$home_classes[] = self::classes( $c['block'] );
		}
		$home_pool = array();
		foreach ( $home_sigs as $sig => $n ) {
			$home_pool[ md5( $sig . '|' ) ] = $n;
		}
		$home_text  = array_flip( self::long_texts( $home_markup ) );
		$home_facts = self::facts( $home_markup );
		$site_host  = strtolower( (string) preg_replace( '/^www\./i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		$home_title = trim( html_entity_decode( wp_strip_all_tags( get_the_title( $home ) ), ENT_QUOTES, 'UTF-8' ) );
		$others     = self::other_designs( $home, $home_title );

		$home_frame = Page_Frame::frame_key( self::top( $home_markup ) );
		$gates   = array( 'G1' => array(), 'G7' => array(), 'G8' => array(), 'G8W' => array(), 'G9' => array() );
		$pages   = array();
		$by_kind = array();
		foreach ( self::page_ids( $home ) as $id ) {
			$kind   = explode( '|', (string) get_post_meta( $id, Team_Pages::META, true ) )[0];
			$markup = (string) get_post_field( 'post_content', $id );
			$blocks = self::top( $markup );

			// G1 · the header and the footer.
			$bad   = array();
			$parts = Page_Frame::split( $blocks, $header_in ? count( $hb ) : 0, $footer_in ? count( $fb ) : 0 );
			if ( $header_in || $footer_in ) {
				if ( count( $parts['head'] ) < ( $header_in ? count( $hb ) : 0 ) || count( $parts['tail'] ) < ( $footer_in ? count( $fb ) : 0 ) ) {
					$bad[] = "the page is shorter than the Home's header and footer";
				} else {
					if ( $header_in && self::join( $parts['head'] ) !== self::join( $hb ) ) {
						$bad[] = 'header differs';
					}
					if ( $footer_in && self::join( $parts['tail'] ) !== self::join( $fb ) ) {
						$bad[] = 'footer differs';
					}
				}
			}
			// The frame: the Home's wrapper (what it clips, how it lays out) is the page's.
			if ( $home_frame !== '' && Page_Frame::frame_key( $blocks ) !== $home_frame ) {
				$bad[] = "the page is not in the Home's frame (the group around its header, main and footer)";
			}
			foreach ( array( Page_Chrome::PART_KEY_META, Page_Chrome::MODE_META ) as $k ) {
				if ( (string) get_post_meta( $id, $k, true ) !== (string) get_post_meta( $home, $k, true ) ) {
					$bad[] = $k . " differs from the Home's";
				}
			}
			$gates['G1'][ $id ] = $bad;

			// G7 · valid blocks.
			$bad = array();
			if ( is_wp_error( Block_Tree::serialize_checked( $blocks ) ) ) {
				$bad[] = 'does not parse back to the same markup';
			}
			$mine = array( substr_count( $markup, ' style="' ), substr_count( $markup, '<!-- wp:html' ) + substr_count( $markup, '<!-- wp:freeform' ) );
			$base = array( substr_count( $home_markup, ' style="' ), substr_count( $home_markup, '<!-- wp:html' ) + substr_count( $home_markup, '<!-- wp:freeform' ) );
			if ( $mine[0] > $base[0] ) {
				$bad[] = sprintf( '%d inline styles (the Home has %d)', $mine[0], $base[0] );
			}
			if ( $mine[1] > $base[1] ) {
				$bad[] = 'more HTML blocks than the Home';
			}
			$unknown = self::unregistered( $blocks );
			if ( $unknown !== array() ) {
				$bad[] = 'unregistered blocks: ' . implode( ', ', $unknown );
			}
			$gates['G7'][ $id ] = $bad;

			// The sections: what each is, and whether the Home has one of that structure.
			$sections = array();
			$sigs     = array();
			$varied   = json_decode( (string) get_post_meta( $id, Team_Pages::OPS_META, true ), true );
			$varied   = is_array( $varied ) ? $varied : array();
			foreach ( array_values( Section_Library::for_page( $id ) ) as $i => $c ) {
				$sig    = Section_Library::signature( $c['block'] );
				$hash   = self::hash( $c['block'] );
				$struct = array_keys( array_filter( $home_secs, static fn( $h ) => $h['sig'] === md5( $sig ) ) );
				$root   = self::root_key( $c['block'] );
				$mine   = self::classes( $c['block'] );
				$kin    = $root === '' ? array() : array_keys( array_filter( $home_secs, static fn( $h ) => $h['root'] === $root ) );
				if ( $kin === array() && $struct === array() ) {
					// Most of its classes are one Home section's: the Home's section with cards taken out or put in.
					$best = 0.0;
					foreach ( $home_classes as $hi => $theirs ) {
						$j = self::jaccard( $mine, $theirs );
						if ( $j >= 0.6 && $j > $best ) {
							$best = $j;
							$kin  = array( $hi );
						}
					}
				}
				$did    = array_values( array_map( 'strval', (array) ( $varied[ $i ] ?? array() ) ) );
				// A section is the same as another when its structure is, and so is what was done to it.
				$sigs[] = md5( $sig . '|' . implode( ',', $did ) );
				$sections[] = array(
					'index'      => $i,
					'role'       => Section_Roles::of( $c['block'], $i === 0 ),
					// home: a Home section's structure; derived: a Home section with cards taken out or put in; new: neither.
					'origin'     => $struct !== array() ? 'home' : ( $kin !== array() ? 'derived' : 'new' ),
					'identical'  => array_filter( $home_secs, static fn( $h ) => $h['hash'] === $hash ) !== array(),
					'home_index' => self::closest( $struct !== array() ? $struct : $kin, $hash, $mine, $home_secs, $home_classes ),
					'sig'        => md5( $sig ),
					'ops'        => $did,
				);
			}
			$long   = self::long_texts( $markup );
			$copied = count( array_filter( $long, static fn( $t ) => isset( $home_text[ $t ] ) ) );

			// G9 · nothing foreign.
			$bad = array();
			$f   = self::facts( $markup );
			foreach ( array( 'phones', 'mails' ) as $k ) {
				$extra = array_diff( $f[ $k ], $home_facts[ $k ] );
				if ( $extra !== array() ) {
					$bad[] = $k . ' the Home does not have: ' . implode( ', ', array_slice( $extra, 0, 3 ) );
				}
			}
			$hosts = array_diff( $f['hosts'], $home_facts['hosts'], array( $site_host ) );
			if ( $hosts !== array() ) {
				$bad[] = 'addresses of other sites: ' . implode( ', ', array_slice( $hosts, 0, 3 ) );
			}
			$plain = html_entity_decode( wp_strip_all_tags( $markup ), ENT_QUOTES, 'UTF-8' );
			if ( preg_match( self::PLACEHOLDERS, $plain, $pm ) === 1 && preg_match( self::PLACEHOLDERS, $home_plain ) !== 1 ) {
				$bad[] = 'placeholder text: ' . $pm[0];
			}
			foreach ( $others as $low => $name ) {
				if ( str_contains( mb_strtolower( $plain ), $low ) && ! str_contains( mb_strtolower( $home_plain ), $low ) ) {
					$bad[] = 'the name of another design: ' . $name;
				}
			}
			$gates['G9'][ $id ] = $bad;

			$pages[ $id ] = array(
				'id'       => $id,
				'url'      => (string) get_permalink( $id ),
				'title'    => trim( html_entity_decode( wp_strip_all_tags( get_the_title( $id ) ), ENT_QUOTES, 'UTF-8' ) ),
				'kind'     => $kind,
				'sections' => $sections,
				'sigs'     => $sigs,
				'texts'    => count( $long ),
				'copied'   => $copied,
			);
			$by_kind[ $kind ][] = $id;
		}

		// G8 · variety.
		foreach ( $pages as $id => $p ) {
			$bad       = array();
			$n         = max( 1, count( $p['sigs'] ) );
			$with_home = self::take( $p['sigs'], $home_pool ) / $n;
			if ( $with_home > self::MAX_SHARED_WITH_HOME ) {
				$bad[] = sprintf( '%d%% of its sections have the structure of a Home section (at most %d%%)', round( 100 * $with_home ), 100 * self::MAX_SHARED_WITH_HOME );
			}
			$worst = 0.0;
			$with  = 0;
			foreach ( $by_kind[ $p['kind'] ] as $other ) {
				if ( $other === $id ) {
					continue;
				}
				$share = self::shared_multiset( $p['sigs'], $pages[ $other ]['sigs'] ) / $n;
				if ( $share > $worst ) {
					$worst = $share;
					$with  = $other;
				}
			}
			if ( $worst > self::MAX_SHARED_WITH_PAGE ) {
				$bad[] = sprintf( '%d%% of its sections have the structure of those of #%d, a page of the same kind (at most %d%%)', round( 100 * $worst ), $with, 100 * self::MAX_SHARED_WITH_PAGE );
			}
			$from_home = $p['texts'] > 0 ? $p['copied'] / $p['texts'] : 0.0;
			if ( $from_home > self::MAX_TEXT_FROM_HOME ) {
				$gates['G8W'][ $id ] = array( sprintf( "%d%% of its long texts are the Home's word for word (at most %d%%)", round( 100 * $from_home ), 100 * self::MAX_TEXT_FROM_HOME ) );
			}
			$pages[ $id ]['shared_with_home'] = round( $with_home, 2 );
			$pages[ $id ]['shared_with_page'] = round( $worst, 2 );
			$pages[ $id ]['text_from_home']   = round( $from_home, 2 );
			$gates['G8'][ $id ]               = $bad;
		}

		return array(
			'home'   => array(
				'id'       => $home,
				'url'      => (string) get_permalink( $home ),
				'title'    => $home_title,
				'sections' => $home_secs,
				// How many top-level blocks of the page are the Home's header and footer (0 when they are template parts).
				'chrome'   => array( 'header' => $header_in ? count( $hb ) : 0, 'footer' => $footer_in ? count( $fb ) : 0 ),
			),
			'tokens' => array_map( 'strval', \DXAI_UI\Compiler\Design_Tokens::for_page( $home ) ),
			'pages'  => array_values( $pages ),
			'gates'  => array_map( static fn( $g ) => array_filter( $g ), $gates ),
		);
	}

	/**
	 * The pages made for a design (Team_Pages), oldest first.
	 *
	 * @return array<int, int>
	 */
	public static function page_ids( int $home ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => array( 'publish', 'draft', 'private' ),
					'meta_query'     => array(
						array( 'key' => Page_Scope::META, 'value' => (string) $home ),
						array( 'key' => Team_Pages::META, 'compare' => 'EXISTS' ),
					),
					'fields'         => 'ids',
					'posts_per_page' => 500,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			)
		);
	}

	/** Whether a part of the Home's chrome is in its content (not a template part or the site's header block). */
	private static function embedded( string $markup ): bool {
		return $markup !== '' && ! str_contains( $markup, '<!-- wp:template-part' ) && ! str_contains( $markup, '<!-- wp:dxai-ui/site-' );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function top( string $markup ): array {
		return array_values( array_filter( parse_blocks( $markup ), static fn( $b ) => trim( (string) ( $b['blockName'] ?? '' ) ) !== '' ) );
	}

	/** @param array<int, array<string, mixed>> $blocks */
	private static function join( array $blocks ): string {
		return implode( '', array_map( 'serialize_block', $blocks ) );
	}

	/**
	 * What a section is called in the Home: its anchor and the classes of its root block (a copy keeps them; a section with
	 * cards taken out has another structure and the same root).
	 *
	 * @param array<string, mixed> $block
	 */
	private static function root_key( array $block ): string {
		$class = trim( (string) ( $block['attrs']['className'] ?? '' ) );
		$name  = (string) ( $block['attrs']['metadata']['name'] ?? '' );
		$key   = $class . '|' . (string) ( $block['attrs']['anchor'] ?? '' ) . '|' . $name;

		return $class === '' ? '' : $key;
	}

	/**
	 * Of the Home's sections a section may be (several can share a structure: a page has four calls to action), the one it is:
	 * the one it is a copy of, else the one whose classes it shares most.
	 *
	 * @param array<int, int>                 $candidates Indexes into the Home's sections.
	 * @param array<int, array<string, mixed>> $home_secs
	 * @param array<string, true>             $mine       The section's class names.
	 * @param array<int, array<string, true>> $home_classes
	 */
	private static function closest( array $candidates, string $hash, array $mine, array $home_secs, array $home_classes ): ?int {
		$best  = null;
		$score = -1.0;
		foreach ( $candidates as $i ) {
			$j = self::jaccard( $mine, $home_classes[ $i ] ?? array() ) + ( $home_secs[ $i ]['hash'] === $hash ? 10.0 : 0.0 );
			if ( $j > $score ) {
				$score = $j;
				$best  = (int) $i;
			}
		}

		return $best;
	}

	/**
	 * The class names used anywhere in a block.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, true>
	 */
	private static function classes( array $block ): array {
		$out  = array();
		$walk = static function ( array $b ) use ( &$walk, &$out ): void {
			foreach ( preg_split( '/\s+/', trim( (string) ( $b['attrs']['className'] ?? '' ) ) ) ?: array() as $c ) {
				if ( $c !== '' ) {
					$out[ $c ] = true;
				}
			}
			foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $child ) {
				$walk( (array) $child );
			}
		};
		$walk( $block );

		return $out;
	}

	/**
	 * @param array<string, true> $a
	 * @param array<string, true> $b
	 */
	private static function jaccard( array $a, array $b ): float {
		if ( $a === array() || $b === array() ) {
			return 0.0;
		}
		$both = count( array_intersect_key( $a, $b ) );

		return $both / max( 1, count( $a ) + count( $b ) - $both );
	}

	/** A section's markup without its anchors (a second copy on a page does not repeat them). @param array<string, mixed> $block */
	private static function hash( array $block ): string {
		Block_Tree::strip_anchors( $block );

		return md5( serialize_block( $block ) );
	}

	/**
	 * The texts of a page's blocks that are longer than a line (lower case).
	 *
	 * @return array<int, string>
	 */
	private static function long_texts( string $markup ): array {
		$out = array();
		if ( preg_match_all( '#<(p|h[1-6]|li|a|span|button|summary|figcaption)\b[^>]*>(.*?)</\1>#is', $markup, $m ) ) {
			foreach ( $m[2] as $t ) {
				$t = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( (string) $t, ENT_QUOTES, 'UTF-8' ) ) ) );
				if ( mb_strlen( $t ) > 24 ) {
					$out[ mb_strtolower( $t ) ] = true;
				}
			}
		}

		return array_keys( $out );
	}

	/**
	 * The phone numbers, e-mail addresses and hosts a page says.
	 *
	 * @return array{phones:array<int, string>, mails:array<int, string>, hosts:array<int, string>}
	 */
	private static function facts( string $markup ): array {
		$plain  = html_entity_decode( wp_strip_all_tags( $markup ), ENT_QUOTES, 'UTF-8' );
		$phones = array();
		if ( preg_match_all( '/\(?\b(\d{3})\)?[ .-]?(\d{3})[ .-]?(\d{4})\b/', $plain . ' ' . $markup, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$phones[ $x[1] . $x[2] . $x[3] ] = true;
			}
		}
		$mails = array();
		if ( preg_match_all( '/[\w.+-]+@[\w-]+\.[\w.-]+/', $markup, $m ) ) {
			foreach ( $m[0] as $x ) {
				$mails[ strtolower( $x ) ] = true;
			}
		}
		$hosts = array();
		if ( preg_match_all( '#https?://([a-z0-9.-]+)#i', $markup, $m ) ) {
			foreach ( $m[1] as $x ) {
				$hosts[ strtolower( (string) preg_replace( '/^www\./i', '', $x ) ) ] = true;
			}
		}

		return array( 'phones' => array_keys( $phones ), 'mails' => array_keys( $mails ), 'hosts' => array_keys( $hosts ) );
	}

	/**
	 * The names of the other designs on the site (lower case => name), at least six letters.
	 *
	 * @return array<string, string>
	 */
	private static function other_designs( int $home, string $home_title ): array {
		$out = array();
		foreach ( Design_Attach::home_ids( 1000 ) as $other ) {
			if ( (int) $other === $home || ! Design_Attach::is_design( (int) $other ) ) {
				continue;
			}
			$name = trim( html_entity_decode( wp_strip_all_tags( get_the_title( (int) $other ) ), ENT_QUOTES, 'UTF-8' ) );
			if ( mb_strlen( $name ) >= 6 && mb_strtolower( $name ) !== mb_strtolower( $home_title ) ) {
				$out[ mb_strtolower( $name ) ] = $name;
			}
		}

		return $out;
	}

	/**
	 * Block names that WordPress does not know.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, string>
	 */
	private static function unregistered( array $blocks ): array {
		$found = array();
		$walk  = static function ( array $bs ) use ( &$walk, &$found ): void {
			foreach ( $bs as $b ) {
				$n = (string) ( $b['blockName'] ?? '' );
				if ( $n !== '' && ! \WP_Block_Type_Registry::get_instance()->is_registered( $n ) ) {
					$found[ $n ] = true;
				}
				$walk( (array) ( $b['innerBlocks'] ?? array() ) );
			}
		};
		$walk( $blocks );

		return array_keys( $found );
	}

	/**
	 * @param array<int, string> $a
	 * @param array<int, string> $b
	 */
	private static function shared_multiset( array $a, array $b ): int {
		return self::take( $a, array_count_values( $b ) );
	}

	/**
	 * @param array<int, string> $own
	 * @param array<string, int> $pool
	 */
	private static function take( array $own, array $pool ): int {
		$n = 0;
		foreach ( $own as $s ) {
			if ( ( $pool[ $s ] ?? 0 ) > 0 ) {
				--$pool[ $s ];
				++$n;
			}
		}

		return $n;
	}
}

<?php
/**
 * Shared ground of the passes that give the same kind of element the same style.
 *
 * @package DXAI_UI\Blocks\Native
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks\Native;

use DXAI_UI\Compiler\Style_Hoister;
use DXAI_UI\Compiler\Utility_Classes;
use DXAI_UI\Pages\Section_Library;

/**
 * A design written by hand or by a model is not even with itself: its sections' titles are 46px in one section and 40px in the
 * next, 44px on another page; one section has 92px of padding above and another 130px. The team wants pages that look like one
 * site, so the same kind of element has the same style, whatever the design did, and a page made later follows the Home's.
 *
 * What is "the same" is read from the Home, the page the design is judged by: of the style a section title has on it, the one most
 * of them have is the style of every section title (Harmony: the profile of a Home). It is written into each block's own CSS and
 * classes — where a person finds it, edits it, and where the editor draws it — not forced on top by a rule: the title's size is the
 * `font-size` in its Design CSS (or its class), the same one on every page, so a block edited by hand is still the person's.
 *
 * Both passes are whole-page ones (Converter::page()): they need the headings and the sections of the page together, and the
 * header, the footer and the navigation are not either. They are the Native blocks engine's, so they run on an import, on a page
 * made later for a design that has had them, in the Library, and they can be put back.
 *
 * `dxai_ui_harmony` (bool, the design's Home) switches them off.
 */
abstract class Style_Harmony extends Converter {

	/** How many of the Home's elements of a kind there must be before they say what the style of that kind is. */
	protected const MIN_EVIDENCE = 3;

	/** The properties that make the typography of a text. */
	protected const TYPE_PROPS = array( 'font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing', 'text-transform', 'text-wrap' );

	/** What the font of a Tailwind size is, in px (its desktop size). */
	private const TAILWIND_SIZES = array(
		'xs'  => 12,
		'sm'  => 14,
		'base' => 16,
		'lg'  => 18,
		'xl'  => 20,
		'2xl' => 24,
		'3xl' => 30,
		'4xl' => 36,
		'5xl' => 48,
		'6xl' => 60,
		'7xl' => 72,
		'8xl' => 96,
		'9xl' => 128,
	);

	/** @var array<string, array<string, mixed>|null> Profiles of Homes, by pass, Home and its last change. */
	private static array $profiles = array();

	public function convert( array $block, ?array $parent = null ): ?array {
		return null;
	}

	public function available(): bool {
		return true;
	}

	/** Whether the design's Home has these passes on (the filter), and there is a Home to judge by. */
	protected static function home(): int {
		$home = (int) ( self::$context['home'] ?? 0 );
		if ( $home < 1 || get_post_status( $home ) === false ) {
			return 0;
		}

		/**
		 * Whether the same kind of element gets the same style across a design's pages (the Home's).
		 *
		 * @param bool $on   On by default.
		 * @param int  $home The design's Home.
		 */
		return (bool) apply_filters( 'dxai_ui_harmony', true, $home ) ? $home : 0;
	}

	/**
	 * What the Home says about this kind of element (profile_of()), once per Home and change of it.
	 *
	 * @return array<string, mixed>|null
	 */
	protected static function profile( int $home ): ?array {
		$content = (string) get_post_field( 'post_content', $home );
		$key     = static::class . '@' . $home . '@' . md5( $content );
		if ( ! array_key_exists( $key, self::$profiles ) ) {
			self::$profiles[ $key ] = static::profile_of( parse_blocks( $content ) );
		}

		return self::$profiles[ $key ];
	}

	/**
	 * The profile of a Home's blocks: what most of its elements of the kind have.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<string, mixed>|null
	 */
	abstract protected static function profile_of( array $blocks ): ?array;

	/* ------------------------------------------------------------------------------------------- reading */

	/** @return array<int, string> */
	protected static function tokens( string $class ): array {
		return preg_split( '/\s+/', trim( $class ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
	}

	/** A class name without its responsive variant: `md:text-5xl` and `md-text-48` are `text-5xl` and `text-48`. */
	protected static function base( string $token ): string {
		return (string) preg_replace( '/^(?:(?:sm|md|lg|xl|2xl)[:-])+/', '', $token );
	}

	/**
	 * A class list in two: the tokens $mine says are of the kind this pass is about, and the rest.
	 *
	 * @return array{mine: array<int, string>, rest: array<int, string>}
	 */
	protected static function split_class( string $class, callable $mine ): array {
		$own  = array();
		$rest = array();
		foreach ( self::tokens( $class ) as $token ) {
			if ( $mine( $token ) ) {
				$own[] = $token;
			} else {
				$rest[] = $token;
			}
		}

		return array(
			'mine' => $own,
			'rest' => $rest,
		);
	}

	/**
	 * A declaration list in two: the declarations whose property $mine says is of the kind, and the rest (as written).
	 *
	 * @return array{mine: array<int, string>, rest: array<int, string>}
	 */
	protected static function split_css( string $css, callable $mine ): array {
		$own  = array();
		$rest = array();
		foreach ( Utility_Classes::declarations( $css ) as $d ) {
			if ( $d['prop'] !== '' && $mine( $d['prop'] ) ) {
				$own[] = $d['raw'];
			} else {
				$rest[] = $d['raw'];
			}
		}

		return array(
			'mine' => $own,
			'rest' => $rest,
		);
	}

	/**
	 * A length in px: a number of px, of rem, or the largest a clamp() reaches. Null for anything it cannot tell (a variable, a %, vw alone).
	 */
	protected static function px( string $value ): ?float {
		$value = strtolower( trim( $value ) );
		if ( preg_match( '/^clamp\(\s*[^,]+,\s*[^,]+,\s*([^)]+)\)$/', $value, $m ) === 1 ) {
			$value = trim( $m[1] );
		}
		if ( preg_match( '/^(\d+(?:\.\d+)?)px$/', $value, $m ) === 1 ) {
			return (float) $m[1];
		}
		if ( preg_match( '/^(\d+(?:\.\d+)?)rem$/', $value, $m ) === 1 ) {
			return (float) $m[1] * 16.0;
		}
		if ( preg_match( '/^0(?:px|rem|em)?$/', $value ) === 1 ) {
			return 0.0;
		}

		return null;
	}

	/** The font size a class says, in px (`text-48`, `text-13-5`, `text-3xl`, `text-[44px]`), or null for a class that is not a size. */
	protected static function class_size( string $token ): ?float {
		$base = self::base( $token );
		if ( preg_match( '/^text-(\d+)(?:-(\d+))?$/', $base, $m ) === 1 ) {
			return (float) ( $m[1] . ( isset( $m[2] ) && $m[2] !== '' ? '.' . $m[2] : '' ) );
		}
		if ( preg_match( '/^text-(xs|sm|base|lg|xl|[2-9]xl)$/', $base, $m ) === 1 ) {
			return (float) self::TAILWIND_SIZES[ $m[1] ];
		}
		if ( preg_match( '/^text-\[([^\]]+)\]$/', $base, $m ) === 1 ) {
			return self::px( $m[1] );
		}

		return null;
	}

	/* ------------------------------------------------------------------------------------------- walking */

	/** Whether a block is the page's own header, footer or navigation (or the template part for one): not what these passes are about. */
	protected static function is_chrome( array $block ): bool {
		$tag  = strtolower( (string) ( $block['attrs']['tagName'] ?? '' ) );
		$name = (string) ( $block['blockName'] ?? '' );

		return in_array( $tag, array( 'header', 'footer', 'nav' ), true )
			|| in_array( $name, array( 'core/navigation', 'core/template-part', 'dxai-ui/site-header', 'dxai-ui/site-footer' ), true );
	}

	/**
	 * Every block of the tree outside the header, the footer and the navigation given to $fn, which returns the block changed or null;
	 * the tree comes back.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	protected static function map_blocks( array $blocks, callable $fn, bool $chrome = false ): array {
		foreach ( $blocks as $i => $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$inside = $chrome || self::is_chrome( $block );
			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::map_blocks( (array) $block['innerBlocks'], $fn, $inside );
			}
			if ( ! $inside ) {
				$changed = $fn( $block );
				if ( $changed !== null ) {
					$block = $changed;
				}
			}
			$blocks[ $i ] = $block;
		}

		return $blocks;
	}

	/**
	 * The page's sections given to $fn, which returns the section changed or null: the blocks the page is made of, read as
	 * Section_Library does (a group around everything, a main around sections, are opened), without its header and footer.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	protected static function map_sections( array $blocks, callable $fn ): array {
		$real = count( array_filter( $blocks, static fn( $b ) => ! empty( $b['blockName'] ) ) );
		foreach ( $blocks as $i => $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$tag = strtolower( (string) ( $block['attrs']['tagName'] ?? '' ) );
			if ( ( $real === 1 || $tag === 'main' ) && Section_Library::wraps_sections( $block ) ) {
				$block['innerBlocks'] = self::map_sections( (array) $block['innerBlocks'], $fn );
				$blocks[ $i ]         = $block;
				continue;
			}
			if ( self::is_chrome( $block ) ) {
				continue;
			}
			$changed = $fn( $block );
			if ( $changed !== null ) {
				$blocks[ $i ] = $changed;
			}
		}

		return $blocks;
	}

	/** Whether a block holds a heading of level 1 somewhere in it (the title of a page: its first section is its hero). */
	protected static function holds_h1( array $block ): bool {
		if ( ( $block['blockName'] ?? '' ) === 'core/heading' && (int) ( $block['attrs']['level'] ?? 2 ) === 1 ) {
			return true;
		}
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $child ) {
			if ( is_array( $child ) && self::holds_h1( $child ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The most common of the things counted, the one found first when they tie; null when there are fewer than $min of them, and — unless
	 * $any — when no one of them is found twice (nothing is the style of most: a guess would be the first that came).
	 *
	 * @param array<int, array<string, mixed>> $found Each with a 'key'.
	 * @return array<string, mixed>|null The first found with the key most found, plus how many had it ('count') and how many were looked at ('of').
	 */
	protected static function dominant( array $found, int $min = self::MIN_EVIDENCE, bool $any = false ): ?array {
		if ( count( $found ) < $min ) {
			return null;
		}
		$count = array();
		foreach ( $found as $item ) {
			$count[ (string) $item['key'] ] = ( $count[ (string) $item['key'] ] ?? 0 ) + 1;
		}
		arsort( $count );
		if ( ! $any && (int) reset( $count ) < 2 ) {
			return null;
		}
		$key = (string) array_key_first( $count );
		foreach ( $found as $item ) {
			if ( (string) $item['key'] === $key ) {
				return $item + array(
					'count' => $count[ $key ],
					'of'    => count( $found ),
				);
			}
		}

		return null;
	}

	/* ------------------------------------------------------------------------------------------- writing */

	/**
	 * The block with its own classes and its own CSS (className, dxaiCss) as given, and the tag as the editor would write it: the
	 * generated classes first, then the block's, then the `dxs-` class that is the hash of its CSS. Null when the tag is not the one the
	 * block's attributes say (someone edited it): a decline is always safe.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>|null
	 */
	protected static function restyle( array $block, string $class, string $css ): ?array {
		$attrs     = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$old_class = trim( (string) ( $attrs['className'] ?? '' ) );
		$old_css   = trim( (string) ( $attrs['dxaiCss'] ?? '' ) );
		$class     = trim( (string) preg_replace( '/\s+/', ' ', $class ) );
		$css       = trim( $css );
		if ( self::hazard( $class ) ) {
			return null;
		}
		$content = is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array();
		$at      = null;
		foreach ( $content as $i => $chunk ) {
			if ( is_string( $chunk ) && preg_match( '/^\s*<([a-z][a-z0-9]*)\b([^>]*)>/i', $chunk ) === 1 ) {
				$at = $i;
				break;
			}
			if ( is_string( $chunk ) && trim( $chunk ) !== '' ) {
				return null;
			}
		}
		if ( $at === null ) {
			return null;
		}
		preg_match( '/^(\s*)(<([a-z][a-z0-9]*)\b)([^>]*)(>)/i', (string) $content[ $at ], $m );
		$has = preg_match( '/\sclass="([^"]*)"/', $m[4], $c ) === 1;
		$tag = $has ? self::tokens( html_entity_decode( $c[1], ENT_QUOTES, 'UTF-8' ) ) : array();
		// The tag says what the attributes say: the block's classes and the hash of its CSS are in it, among the classes the block
		// generates itself (before them, or — a colour set in the Colour panel — after).
		$old_tail = array_merge( self::tokens( $old_class ), $old_css !== '' ? array( Style_Hoister::css_class( $old_css ) ) : array() );
		foreach ( $old_tail as $token ) {
			if ( ! in_array( $token, $tag, true ) ) {
				return null;
			}
		}
		$others = array();
		$where  = null;
		foreach ( $tag as $token ) {
			if ( in_array( $token, $old_tail, true ) ) {
				$where ??= count( $others );
				continue;
			}
			$others[] = $token;
		}
		$where    = $where ?? count( $others );
		$new_tail = array_merge( self::tokens( $class ), $css !== '' ? array( Style_Hoister::css_class( $css ) ) : array() );
		// The new ones take the place of the old ones.
		$all = array_merge( array_slice( $others, 0, $where ), $new_tail, array_slice( $others, $where ) );

		if ( $has ) {
			$attr = $all === array() ? '' : ' class="' . esc_attr( implode( ' ', $all ) ) . '"';
			$open = str_replace( $c[0], $attr, $m[4] );
		} else {
			$open = $all === array() ? $m[4] : ' class="' . esc_attr( implode( ' ', $all ) ) . '"' . $m[4];
		}
		$next = $attrs;
		unset( $next['className'], $next['dxaiCss'] );
		if ( $class !== '' ) {
			$next['className'] = $class;
		}
		if ( $css !== '' ) {
			$next['dxaiCss'] = $css;
		}

		$content[ $at ]        = $m[1] . $m[2] . $open . $m[5] . substr( (string) $content[ $at ], strlen( $m[0] ) );
		$block['attrs']        = $next;
		$block['innerContent'] = $content;
		$block['innerHTML']    = implode( '', array_filter( $content, 'is_string' ) );

		return $block;
	}
}

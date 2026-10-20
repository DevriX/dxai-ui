<?php
/**
 * The picture a design's page opens on, asked for first.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Media;

use DXAI_UI\Pages\Section_Library;
use DXAI_UI\Seo\Page_Meta;

/**
 * A design's hero is often a section with a photograph behind it, written as CSS (`background-image` in the block's own CSS, a
 * cover, a group's background). A browser finds such a picture only once it has read the stylesheet that names it, and on a phone
 * on slow 4G that is the page's Largest Contentful Paint: H2O Away's hero at 5.1–9.6 s (docs/PLAN-PERFECT-PAGES.md, T5.1). The page
 * says so in its head: `<link rel="preload" as="image" fetchpriority="high">` for the picture behind its first section, so the
 * browser asks for it with the HTML. Only on a design's page, only the first section's picture; `dxai_ui_lcp_preload` changes the
 * address or removes it with ''.
 */
final class Lcp_Preload {

	public function register(): void {
		add_action( 'wp_head', array( self::class, 'print_preload' ), 1 );
	}

	/** Post meta: the pictures read from the page's stylesheet, with the sheet they were read from. */
	public const SHEET_META = '_dxai_ui_lcp_sheet';

	/** The preload, on a design's page that opens on a picture. */
	public static function print_preload(): void {
		$id = is_singular( 'page' ) ? (int) get_queried_object_id() : 0;
		if ( $id < 1 || ! Page_Meta::is_design_page( $id ) ) {
			return;
		}
		$content = (string) get_post_field( 'post_content', $id );
		$url     = self::hero_picture( $content );
		// Its picture named by the page's stylesheet instead, per screen width when the sheet changes it there.
		$sized = $url === '' ? self::sheet_pictures( $id, $content ) : array();
		$base  = '';
		foreach ( $sized as $picture ) {
			$base = $picture['media'] === '' || $base === '' ? $picture['url'] : $base;
		}

		/**
		 * The picture a design's page opens on, asked for first; '' asks for none.
		 *
		 * @param string $url The picture behind the page's first section, or '' (with its stylesheet's pictures per screen width,
		 *                    the one of the widest screen).
		 * @param int    $id  The page.
		 */
		$filtered = (string) apply_filters( 'dxai_ui_lcp_preload', $url !== '' ? $url : $base, $id );
		if ( $filtered === '' ) {
			return;
		}
		if ( $url !== '' || $sized === array() || $filtered !== $base ) {
			echo '<link rel="preload" as="image" href="' . esc_url( $filtered ) . '" fetchpriority="high" />' . "\n";

			return;
		}
		foreach ( $sized as $picture ) {
			$media = $picture['media'] !== '' ? ' media="' . esc_attr( $picture['media'] ) . '"' : '';
			echo '<link rel="preload" as="image" href="' . esc_url( $picture['url'] ) . '"' . $media . ' fetchpriority="high" />' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}
	}

	/**
	 * The picture behind the page's first section when its stylesheet paints it there: an Elementor site's hero is
	 * `.elementor-element-3b8d3e30 { background-image: url(…) }` in the page's CSS, with other pictures for a tablet or a phone
	 * under `@media (max-width: …)` (Gemini Restoration's hero, its Largest Contentful Paint). One entry per range of screen widths
	 * with its media query ('' for every width); none when a rule under another condition could change it, so a preload never asks
	 * for a picture the page does not show. Cached on the page with the sheet's size and time.
	 *
	 * @return array<int, array{url: string, media: string}>
	 */
	public static function sheet_pictures( int $id, string $content ): array {
		$sheet = \DXAI_UI\Support\Upload_Paths::for_meta( $id, '_dxai_ui_css_url' );
		$path  = $sheet['path'];
		if ( $path === '' || ! is_readable( $path ) ) {
			return array();
		}
		$key    = md5( $path . '|' . (string) filemtime( $path ) . '|' . (string) filesize( $path ) . '|' . md5( $content ) );
		$cached = get_post_meta( $id, self::SHEET_META, true );
		if ( is_array( $cached ) && ( $cached['key'] ?? '' ) === $key && is_array( $cached['pictures'] ?? null ) ) {
			return $cached['pictures'];
		}
		$sections = self::sections( parse_blocks( $content ) );
		$pictures = $sections === array() ? array() : self::from_sheet( (string) file_get_contents( $path ), self::own_names( $sections[0] ), array_slice( $sections, 1 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		update_post_meta(
			$id,
			self::SHEET_META,
			array(
				'key'      => $key,
				'pictures' => $pictures,
			)
		);

		return $pictures;
	}

	/**
	 * The classes and id of a block's own element: its className and anchor, and the first tag of its markup.
	 *
	 * @param array<string, mixed> $block
	 * @return array{class: array<int, string>, id: string}
	 */
	private static function own_names( array $block ): array {
		$classes = preg_split( '/\s+/', (string) ( $block['attrs']['className'] ?? '' ) ) ?: array();
		$id      = (string) ( $block['attrs']['anchor'] ?? '' );
		$tags    = new \WP_HTML_Tag_Processor( (string) ( $block['innerHTML'] ?? '' ) );
		if ( $tags->next_tag() ) {
			$classes = array_merge( $classes, preg_split( '/\s+/', (string) $tags->get_attribute( 'class' ) ) ?: array() );
			$id      = $id !== '' ? $id : (string) $tags->get_attribute( 'id' );
		}

		return array(
			'class' => array_values( array_unique( array_filter( $classes, 'strlen' ) ) ),
			'id'    => $id,
		);
	}

	/**
	 * The section's background pictures by screen width, from the rules whose last compound selector names the section itself:
	 * one of its own classes no later section has (or its id), and nothing it lacks.
	 *
	 * @param array{class: array<int, string>, id: string} $own
	 * @param array<int, array<string, mixed>>             $others The sections after it.
	 * @return array<int, array{url: string, media: string}>
	 */
	public static function from_sheet( string $css, array $own, array $others ): array {
		$shared = array();
		foreach ( $others as $other ) {
			if ( is_array( $other ) ) {
				foreach ( self::own_names( $other )['class'] as $class ) {
					$shared[ $class ] = true;
				}
			}
		}
		$mine = array_flip( $own['class'] );
		$tell = array_values( array_filter( $own['class'], static fn( $c ) => ! isset( $shared[ $c ] ) ) );
		if ( $tell === array() && $own['id'] === '' ) {
			return array();
		}
		// Max width of the range (0 = no condition) => the picture there ('' = none).
		$by = array();
		foreach ( \DXAI_UI\Compiler\Page_Css_Trim::rules( $css ) as $rule ) {
			if ( preg_match( '/background(?:-image)?\s*:([^;]*)/i', $rule['body'], $bg ) !== 1 || ! self::names_section( $rule['selector'], $mine, $tell, $own['id'] ) ) {
				continue;
			}
			// No url() (`background-image: none`, a gradient, the shorthand with a colour) is no picture there.
			$url = preg_match( '/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $bg[1], $u ) === 1 ? trim( $u[1] ) : '';
			if ( $rule['at'] === array() ) {
				$by[0] = $url;
				continue;
			}
			if ( count( $rule['at'] ) !== 1 || preg_match( '/^@media\s+(?:(?:only\s+)?screen\s+and\s+)?\(\s*max-width\s*:\s*(\d+(?:\.\d+)?)px\s*\)$/i', trim( $rule['at'][0] ), $mq ) !== 1 ) {
				return array(); // A condition this cannot place: no preload rather than a wrong one.
			}
			$by[ (string) (float) $mq[1] ] = $url;
		}
		if ( ! isset( $by[0] ) ) {
			return array();
		}
		$widths = array_map( 'floatval', array_filter( array_keys( $by ), static fn( $w ) => (float) $w > 0 ) );
		sort( $widths );
		$out  = array();
		$from = 0.0;
		foreach ( $widths as $w ) {
			$media = ( $from > 0 ? '(min-width: ' . self::px( $from + 0.02 ) . ') and ' : '' ) . '(max-width: ' . self::px( $w ) . ')';
			$out[] = array(
				'url'   => (string) $by[ (string) $w ],
				'media' => $media,
			);
			$from  = $w;
		}
		$out[] = array(
			'url'   => (string) $by[0],
			'media' => $from > 0 ? '(min-width: ' . self::px( $from + 0.02 ) . ')' : '',
		);
		// One picture everywhere is one preload; a range with none asks for nothing.
		$urls = array_unique( array_column( $out, 'url' ) );
		if ( count( $urls ) === 1 ) {
			return $urls[0] === '' ? array() : array(
				array(
					'url'   => (string) $urls[0],
					'media' => '',
				),
			);
		}

		return array_values( array_filter( $out, static fn( $p ) => $p['url'] !== '' ) );
	}

	private static function px( float $n ): string {
		return rtrim( rtrim( number_format( $n, 2, '.', '' ), '0' ), '.' ) . 'px';
	}

	/**
	 * Whether one selector of the list ends on the section: its last compound names one of the section's telling classes (or its
	 * id) and no class or id the section lacks, with no pseudo-element and no state (:hover) that is not always true.
	 *
	 * @param array<string, int>  $mine Every class of the section.
	 * @param array<int, string> $tell Its classes no later section has.
	 */
	private static function names_section( string $list, array $mine, array $tell, string $id ): bool {
		foreach ( explode( ',', $list ) as $selector ) {
			$parts = preg_split( '/\s*[\s>+~]\s*(?![^(]*\))/', trim( $selector ) ) ?: array();
			$last  = (string) end( $parts );
			// :not(…) asks for an absence: the section may well lack what it names.
			$last = (string) preg_replace( '/:not\((?:[^()]|\([^()]*\))*\)/', '', $last );
			if ( $last === '' || preg_match( '/::|:(?:hover|focus|active|visited|checked|target|focus-within|focus-visible|before|after)\b/i', $last ) === 1 ) {
				continue;
			}
			preg_match_all( '/\.([\w-]+)/', $last, $c );
			preg_match_all( '/#([\w-]+)/', $last, $h );
			$ids = $h[1];
			if ( array_diff( $c[1], array_keys( $mine ) ) !== array() || ( $ids !== array() && ( count( $ids ) > 1 || $ids[0] !== $id ) ) ) {
				continue;
			}
			if ( array_intersect( $c[1], $tell ) !== array() || ( $id !== '' && $ids === array( $id ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The picture behind the first section of a page's content: its own CSS's `background-image`, a cover's picture, a group's
	 * background image — in the section or the two levels below it. A header (the page's own, on a page that carries it) is not
	 * the section the page opens on.
	 */
	public static function hero_picture( string $content ): string {
		$sections = self::sections( parse_blocks( $content ) );

		return $sections === array() ? '' : self::picture_in( $sections[0], 0 );
	}

	/**
	 * The page's sections, the one it opens on first: past a skip link and a header, and inside what only wraps them — a <main>,
	 * the one block around the whole page, a lone container in either (an Elementor site's `<main>` around its page `<div>` around
	 * its sections, Gemini Restoration's).
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	public static function sections( array $blocks ): array {
		$blocks = Section_Library::flatten( $blocks );
		for ( $round = 0; $round < 4; $round++ ) {
			$real = array_values(
				array_filter(
					$blocks,
					static function ( $b ): bool {
						if ( ! is_array( $b ) || empty( $b['blockName'] ) || $b['blockName'] === 'core/template-part' || Section_Library::is_lead_chrome( $b ) ) {
							return false;
						}

						return ! in_array( strtolower( (string) ( $b['attrs']['tagName'] ?? '' ) ), array( 'header', 'nav' ), true );
					}
				)
			);
			$first = $real[0] ?? null;
			if ( ! is_array( $first ) || ! in_array( (string) $first['blockName'], array( 'core/group', 'dxai-ui/box' ), true ) ) {
				return $real;
			}
			$kids = array_values( array_filter( (array) ( $first['innerBlocks'] ?? array() ), static fn( $b ) => is_array( $b ) && ! empty( $b['blockName'] ) ) );
			$main = strtolower( (string) ( $first['attrs']['tagName'] ?? '' ) ) === 'main';
			$only = count( $real ) === 1 || $main;
			if ( ! $only || $kids === array() || ( count( $kids ) > 1 && ! $main && ! Section_Library::wraps_sections( $first ) ) ) {
				return $real;
			}
			// A <main> or the lone block holding one container or the sections: its children are the page's.
			$blocks = array_merge( $kids, array_slice( $real, 1 ) );
		}

		return $blocks;
	}

	/** @param array<string, mixed> $block */
	private static function picture_in( array $block, int $depth ): string {
		$attrs = (array) ( $block['attrs'] ?? array() );
		if ( preg_match( '/background(?:-image)?\s*:[^;]*url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', (string) ( $attrs['dxaiCss'] ?? '' ), $m ) === 1 ) {
			return trim( $m[1] );
		}
		if ( ( $block['blockName'] ?? '' ) === 'core/cover' && ! empty( $attrs['url'] ) && ( $attrs['backgroundType'] ?? 'image' ) === 'image' ) {
			return (string) $attrs['url'];
		}
		$bg = $attrs['style']['background']['backgroundImage']['url'] ?? '';
		if ( is_string( $bg ) && $bg !== '' ) {
			return $bg;
		}
		if ( $depth < 2 ) {
			foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $child ) {
				$found = is_array( $child ) ? self::picture_in( $child, $depth + 1 ) : '';
				if ( $found !== '' ) {
					return $found;
				}
			}
		}

		return '';
	}
}

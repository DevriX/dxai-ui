<?php
/**
 * A design's markup as assistive technology reads it: no attribute an element may not carry, nothing reachable that is hidden, and
 * names that say what is written.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Seo;

/**
 * Lighthouse's accessibility audit, on the corpus (docs/PLAN-PERFECT-PAGES.md, T5.5), found in what the import wrote:
 *
 *  - `ref="[object Object]"` (Five Star): a React ref the Claude Design export left as an attribute — removed (no element has one);
 *  - `aria-expanded` on a <header> (DevriX Elevate: the element holding the menu's open state) — an element whose own role cannot be
 *    expanded (header, footer, main, nav, section, article, aside, form, lists, headings, paragraphs, pictures) loses it, unless an
 *    explicit role allows it; a <div> or <span> with no role keeps it (the audit reads no role there, and a reader still hears it);
 *  - links in a layer hidden from readers (`aria-hidden="true"`: a ticker's second copy, a hero's decorative duplicate) that the Tab
 *    key still reaches (Elevate) — they get `tabindex="-1"`;
 *  - a link or button whose `aria-label` does not hold its visible words (Five Star's "(509) 404-0778" named "Call Five Star…",
 *    Semper Dry's Instagram link): the visible words go first in the name ("(509) 404-0778, Call Five Star…"), as WCAG 2.5.3 asks;
 *  - a reference to an id no element in the page has (Five Star's menu button, `aria-controls="mobile-menu"`): the whole document is
 *    needed to know, so this one is made where WordPress hands it over (6.9+: `wp_template_enhancement_output_buffer`).
 *
 * On the content of a design's page and the header and footer the DX template draws, when it is served; nothing stored changes.
 * `dxai_ui_accessible_markup` turns it off.
 */
final class Accessible_Markup {

	/** Elements whose own role cannot be expanded. */
	private const NOT_EXPANDABLE = array( 'HEADER', 'FOOTER', 'MAIN', 'NAV', 'SECTION', 'ARTICLE', 'ASIDE', 'FORM', 'UL', 'OL', 'LI', 'P', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'IMG', 'FIGURE', 'TABLE' );

	/** Roles that can be expanded. */
	private const EXPANDABLE_ROLES = array( 'application', 'button', 'checkbox', 'combobox', 'gridcell', 'link', 'listbox', 'menuitem', 'menuitemcheckbox', 'menuitemradio', 'row', 'rowheader', 'columnheader', 'switch', 'tab', 'treeitem' );

	/** Attributes that name other elements by their id. */
	private const REFERENCES = array( 'aria-controls', 'aria-labelledby', 'aria-describedby', 'aria-owns', 'aria-flowto', 'aria-details', 'aria-errormessage', 'aria-activedescendant' );

	public function register(): void {
		// After the blocks are rendered and WordPress has written its picture attributes.
		add_filter( 'the_content', array( self::class, 'filter' ), 20 );
		add_action( 'template_redirect', array( self::class, 'watch' ) );
	}

	/**
	 * On a design's page, where WordPress can hand over the whole document (6.9+): references to ids are checked against it.
	 */
	public static function watch(): void {
		if ( self::applies() ) {
			add_filter( 'wp_template_enhancement_output_buffer', array( self::class, 'document' ) );
		}
	}

	/**
	 * The whole page, its references to elements that are not in it removed.
	 *
	 * @param string|mixed $html
	 * @return string|mixed
	 */
	public static function document( $html ) {
		return is_string( $html ) ? self::references( $html ) : $html;
	}

	/**
	 * A reference to an element by an id no element in the page has (Five Star's menu button, `aria-controls="mobile-menu"`, with no
	 * mobile-menu in the page) names nothing: the ids that are there are kept, the attribute goes when none is. Only a whole document
	 * can say an id is not there — the header and the content are drawn apart.
	 */
	public static function references( string $html ): string {
		if ( preg_match( '#\saria-(?:' . implode( '|', array_map( static fn( $r ) => preg_quote( substr( $r, 5 ), '#' ), self::REFERENCES ) ) . ')=#i', $html ) !== 1 ) {
			return $html;
		}
		preg_match_all( '#\sid\s*=\s*["\']?([^"\'\s>]+)#i', $html, $ids );
		$have = array_flip( $ids[1] );
		$tags = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			foreach ( self::REFERENCES as $attr ) {
				$value = $tags->get_attribute( $attr );
				if ( ! is_string( $value ) || trim( $value ) === '' ) {
					continue;
				}
				$named = preg_split( '/\s+/', trim( $value ) );
				$kept  = array_values( array_filter( is_array( $named ) ? $named : array(), static fn( $id ) => isset( $have[ $id ] ) ) );
				if ( $kept === array() ) {
					$tags->remove_attribute( $attr );
				} elseif ( count( $kept ) !== count( (array) $named ) ) {
					$tags->set_attribute( $attr, implode( ' ', $kept ) );
				}
			}
		}

		return $tags->get_updated_html();
	}

	/**
	 * @param string|mixed $html
	 * @return string|mixed
	 */
	public static function filter( $html ) {
		if ( ! is_string( $html ) || $html === '' || ! self::applies() ) {
			return $html;
		}

		return self::fix( $html );
	}

	/** Markup with the four repairs made. */
	public static function fix( string $html ): string {
		$html = self::attributes( $html );
		$html = self::hidden_focus( $html );

		return self::names( $html );
	}

	/** No `ref`, and no `aria-expanded` where the element's role cannot be expanded. */
	private static function attributes( string $html ): string {
		if ( stripos( $html, ' ref=' ) === false && stripos( $html, 'aria-expanded' ) === false && stripos( $html, 'aria-hidden' ) === false ) {
			return $html;
		}
		$tags = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			if ( $tags->get_attribute( 'ref' ) !== null ) {
				$tags->remove_attribute( 'ref' );
			}
			// A link hidden from readers itself is out of the Tab order too.
			if ( $tags->get_attribute( 'aria-hidden' ) === 'true' && $tags->get_tag() === 'A' && $tags->get_attribute( 'href' ) !== null && $tags->get_attribute( 'tabindex' ) === null ) {
				$tags->set_attribute( 'tabindex', '-1' );
			}
			if ( $tags->get_attribute( 'aria-expanded' ) === null || ! in_array( (string) $tags->get_tag(), self::NOT_EXPANDABLE, true ) ) {
				continue;
			}
			if ( in_array( strtolower( trim( (string) $tags->get_attribute( 'role' ) ) ), self::EXPANDABLE_ROLES, true ) ) {
				continue;
			}
			$tags->remove_attribute( 'aria-expanded' );
			$tags->remove_attribute( 'data-wp-bind--aria-expanded' );
		}

		return $tags->get_updated_html();
	}

	/** What the Tab key reaches inside a layer hidden from readers is taken out of the order. */
	private static function hidden_focus( string $html ): string {
		if ( stripos( $html, 'aria-hidden="true"' ) === false ) {
			return $html;
		}
		$offset = 0;
		while ( preg_match( '#<(div|span|section|ul|ol|li|p|figure|aside|header|footer|nav|article)\b[^>]*\saria-hidden="true"[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE, $offset ) === 1 ) {
			$start = (int) $m[0][1] + strlen( $m[0][0] );
			$end   = self::closing( $html, strtolower( $m[1][0] ), $start );
			if ( $end < 0 ) {
				$offset = $start;
				continue;
			}
			$inner  = substr( $html, $start, $end - $start );
			$fixed  = self::out_of_order( $inner );
			$html   = substr( $html, 0, $start ) . $fixed . substr( $html, $end );
			$offset = $start + strlen( $fixed );
		}

		return $html;
	}

	/** Where the element of this name opened just before $from closes (the start of its end tag), -1 when it does not. */
	private static function closing( string $html, string $tag, int $from ): int {
		$depth = 1;
		$pos   = $from;
		while ( preg_match( '#<(/?)' . preg_quote( $tag, '#' ) . '\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE, $pos ) === 1 ) {
			$pos    = (int) $m[0][1] + strlen( $m[0][0] );
			$depth += $m[1][0] === '/' ? -1 : 1;
			if ( $depth === 0 ) {
				return (int) $m[0][1];
			}
		}

		return -1;
	}

	/** Every element in $html the Tab key would reach, with tabindex="-1". */
	private static function out_of_order( string $html ): string {
		$tags = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			$name  = (string) $tags->get_tag();
			$index = $tags->get_attribute( 'tabindex' );
			$reach = ( $name === 'A' && $tags->get_attribute( 'href' ) !== null ) || in_array( $name, array( 'BUTTON', 'SELECT', 'TEXTAREA', 'IFRAME', 'SUMMARY' ), true )
				|| ( $name === 'INPUT' && strtolower( (string) $tags->get_attribute( 'type' ) ) !== 'hidden' ) || ( $index !== null && (int) $index >= 0 );
			if ( $reach && ( $index === null || (int) $index >= 0 ) ) {
				$tags->set_attribute( 'tabindex', '-1' );
			}
		}

		return $tags->get_updated_html();
	}

	/** A link or button named by an aria-label that does not hold its visible words: the words first. */
	private static function names( string $html ): string {
		if ( stripos( $html, 'aria-label=' ) === false ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'#<(a|button)\b([^>]*)>(.*?)</\1>#is',
			static function ( array $m ): string {
				if ( preg_match( '#\saria-label="([^"]*)"#i', $m[2], $label ) !== 1 ) {
					return $m[0];
				}
				$name    = html_entity_decode( $label[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$visible = preg_replace( '#<([a-z][a-z0-9]*)\b[^>]*\saria-hidden="true"[^>]*>.*?</\1>#is', '', $m[3] );
				$visible = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $visible ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
				if ( $visible === '' || mb_stripos( $name, $visible ) !== false ) {
					return $m[0];
				}
				$attrs = str_replace( $label[0], ' aria-label="' . esc_attr( $visible . ( trim( $name ) !== '' ? ', ' . trim( $name ) : '' ) ) . '"', $m[2] );

				return '<' . $m[1] . $attrs . '>' . $m[3] . '</' . $m[1] . '>';
			},
			$html
		);
	}

	/** On a design's page, unless turned off. */
	private static function applies(): bool {
		if ( is_admin() || ! is_singular() ) {
			return false;
		}
		$id = (int) get_queried_object_id();

		/**
		 * Whether a design's markup is repaired for assistive technology as it is served (ref, aria-expanded, hidden links, names).
		 *
		 * @param bool $on Whether the page is a design's.
		 * @param int  $id The page.
		 */
		return (bool) apply_filters( 'dxai_ui_accessible_markup', $id > 0 && Page_Meta::is_design_page( $id ), $id );
	}
}

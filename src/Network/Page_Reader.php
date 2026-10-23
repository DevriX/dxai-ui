<?php
/**
 * One page of a team site, read as its sections.
 *
 * @package DXAI_UI\Network
 */

declare(strict_types=1);

namespace DXAI_UI\Network;

/**
 * What a page of a site in the network is made of, the way the research of 2026-10-22 read 637 pages of 25 team sites
 * (docs/PLAN-NETWORK-PAGES.md, .verify/network-research/analyze.cjs): the page's sections are the children of the element
 * that holds its full-width blocks; each section has a role (hero, cards, process, reviews, faq, cta, form, areas, map,
 * two-col, trust, related, video, text, other), a heading, a number of words, its questions, and two fingerprints:
 *
 *  text   its words, lowered — the same on two pages means the section was kept word for word (a shared section);
 *  shape  its build without the words: the elements, the block names and the layout classes, to a depth — the same on two
 *         pages means one was made from the other.
 *
 * It reads HTML: a page's rendered content (the REST API's content.rendered) or its saved block markup (post_content, the
 * comments do not matter: they are not elements). The rules are the research's, so the model learned here gives its numbers.
 */
final class Page_Reader {

	/** Elements that are never a section, nor part of a build. */
	private const NOT_SECTION = array( 'script', 'style', 'link', 'meta', 'noscript', 'br' );

	/** Inline elements that are part of a build only when they hold other elements. */
	private const INLINE = array( 'script', 'style', 'br', 'span', 'strong', 'em', 'b', 'i', 'a' );

	/** Classes that are part of a section's build. */
	private const SHAPE_CLASS = '/^(wp-block-[a-z-]+|amr-[a-z-]+|is-layout-[a-z]+|alignfull|alignwide|has-[a-z-]+-background-color|is-style-[a-z-]+|wp-block-columns|columns-\d)$/';

	/** JavaScript's \s: the research measured with it, and pages are full of no-break spaces. */
	private const SPACE = '/[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u';

	/** A question of a FAQ: the elements the team's FAQ blocks and the SEO plugins use. */
	private const QUESTION_XPATH = './/details | .//*[contains(concat(" ", normalize-space(@class), " "), " faq-question ")] | .//*[contains(concat(" ", normalize-space(@class), " "), " rank-math-question ")] | .//*[contains(concat(" ", normalize-space(@class), " "), " schema-faq-question ")]';

	/**
	 * The page: its words, its h1, whether it is an imported design, and its sections.
	 *
	 * @return array{words: int, h1: string, design: bool, bytes: int, sections: array<int, array<string, mixed>>}
	 */
	public static function read( string $html ): array {
		$doc  = self::document( $html );
		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		$out  = array(
			'words'    => 0,
			'h1'       => '',
			'design'   => str_contains( $html, 'dxai-ui' ),
			'bytes'    => strlen( $html ),
			'sections' => array(),
		);
		if ( ! $body instanceof \DOMElement ) {
			return $out;
		}
		$xp           = new \DOMXPath( $doc );
		$out['words'] = self::words( (string) $body->textContent );
		$h1           = $xp->query( '(.//h1)[1]', $body );
		if ( $h1 !== false && $h1->length > 0 ) {
			$out['h1'] = self::squash( (string) $h1->item( 0 )->textContent );
		}
		$list = self::sections_of( $body );
		foreach ( $list as $i => $el ) {
			$out['sections'][] = self::section( $xp, $el, $i );
		}

		return $out;
	}

	/**
	 * One section, given as its own HTML (a block rendered by itself): the same record a section of read() has. The
	 * section's role is read as the section at that place on its page (the first one is the hero when it is short).
	 *
	 * @return array<string, mixed>
	 */
	public static function one( string $html, int $index = 1 ): array {
		$doc  = self::document( '<div class="dxai-network-one">' . $html . '</div>' );
		$xp   = new \DOMXPath( $doc );
		$list = $xp->query( '//body/div[@class="dxai-network-one"]' );
		$root = $list !== false && $list->length > 0 ? $list->item( 0 ) : null;
		if ( ! $root instanceof \DOMElement ) {
			return array();
		}
		$kids = self::children( $root );
		$el   = count( $kids ) === 1 ? $kids[0] : $root;

		return self::section( $xp, $el, $index );
	}

	/**
	 * The words of a text, counted the research's way.
	 */
	public static function words( string $text ): int {
		return (int) preg_match_all( "/[A-Za-z0-9][A-Za-z0-9'’&-]*/u", $text );
	}

	/**
	 * Spaces of any kind made one, at the ends none.
	 */
	public static function squash( string $text ): string {
		return trim( (string) preg_replace( self::SPACE, ' ', $text ) );
	}

	private static function document( string $html ): \DOMDocument {
		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT | LIBXML_PARSEHUGE );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		// A template's content is not part of the page until a script uses it (a browser keeps it aside): a review widget
		// keeps its 750 words of reviews there.
		foreach ( iterator_to_array( $doc->getElementsByTagName( 'template' ) ) as $tpl ) {
			while ( $tpl->firstChild !== null ) {
				$tpl->removeChild( $tpl->firstChild );
			}
		}
		// HTML5's empty elements libxml does not know: it puts what follows a <source> inside it (a picture's img).
		foreach ( array( 'source', 'track', 'wbr' ) as $tag ) {
			foreach ( iterator_to_array( $doc->getElementsByTagName( $tag ) ) as $el ) {
				while ( $el->lastChild !== null && $el->parentNode !== null ) {
					$el->parentNode->insertBefore( $el->lastChild, $el->nextSibling );
				}
			}
		}

		return $doc;
	}

	/**
	 * The element children of a node.
	 *
	 * @return array<int, \DOMElement>
	 */
	private static function children( \DOMNode $node ): array {
		$out = array();
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMElement ) {
				$out[] = $child;
			}
		}

		return $out;
	}

	/**
	 * The page's sections: going down while the content is one wrapper with more inside it.
	 *
	 * @return array<int, \DOMElement>
	 */
	private static function sections_of( \DOMElement $body ): array {
		$root = $body;
		for ( $i = 0; $i < 6; $i++ ) {
			$kids = array_values( array_filter( self::children( $root ), static fn( \DOMElement $k ): bool => ! in_array( strtolower( $k->tagName ), array( 'script', 'style', 'link', 'meta', 'noscript' ), true ) ) );
			if ( count( $kids ) === 1 && count( self::children( $kids[0] ) ) > 1 ) {
				$root = $kids[0];
				continue;
			}
			break;
		}
		$out = array();
		foreach ( self::children( $root ) as $k ) {
			if ( in_array( strtolower( $k->tagName ), self::NOT_SECTION, true ) ) {
				continue;
			}
			if ( trim( (string) $k->textContent ) === '' && ! self::has( $k, array( 'img', 'iframe', 'video', 'form' ) ) ) {
				continue;
			}
			$out[] = $k;
		}

		return $out;
	}

	/**
	 * Whether an element holds one of these elements.
	 *
	 * @param array<int, string> $tags
	 */
	private static function has( \DOMElement $el, array $tags ): bool {
		foreach ( $tags as $tag ) {
			if ( $el->getElementsByTagName( $tag )->length > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The classes of an element, each once, in order.
	 *
	 * @return array<int, string>
	 */
	private static function classes( \DOMElement $el ): array {
		$raw = trim( (string) $el->getAttribute( 'class' ) );

		return $raw === '' ? array() : array_values( array_unique( (array) preg_split( '/[\t\n\f\r ]+/', $raw ) ) );
	}

	/**
	 * A section's build without its words.
	 */
	public static function shape( \DOMElement $el, int $depth = 0 ): string {
		if ( $depth > 7 ) {
			return '';
		}
		$keep = array_values( array_filter( self::classes( $el ), static fn( string $c ): bool => preg_match( self::SHAPE_CLASS, $c ) === 1 ) );
		sort( $keep, SORT_STRING );
		$kids = array();
		foreach ( self::children( $el ) as $k ) {
			if ( in_array( strtolower( $k->tagName ), self::INLINE, true ) && count( self::children( $k ) ) === 0 ) {
				continue;
			}
			$s = self::shape( $k, $depth + 1 );
			if ( $s !== '' ) {
				$kids[] = $s;
			}
		}

		return strtolower( $el->tagName ) . ( $keep !== array() ? '.' . implode( '.', $keep ) : '' ) . ( $kids !== array() ? '{' . implode( ',', $kids ) . '}' : '' );
	}

	private static function count( \DOMXPath $xp, string $query, \DOMElement $el ): int {
		$r = $xp->query( $query, $el );

		return $r === false ? 0 : $r->length;
	}

	private static function first_text( \DOMXPath $xp, string $query, \DOMElement $el ): ?string {
		$r = $xp->query( $query, $el );

		return $r !== false && $r->length > 0 ? (string) $r->item( 0 )->textContent : null;
	}

	/**
	 * The sources of the frames inside a section.
	 *
	 * @return array<int, string>
	 */
	private static function frames( \DOMElement $el ): array {
		$out = array();
		foreach ( $el->getElementsByTagName( 'iframe' ) as $f ) {
			$src   = (string) $f->getAttribute( 'src' );
			$out[] = $src !== '' ? $src : (string) $f->getAttribute( 'data-src' );
		}

		return $out;
	}

	private static function cls( string $name ): string {
		return 'contains(concat(" ", normalize-space(@class), " "), " ' . $name . ' ")';
	}

	/**
	 * One section: its role, heading, words, questions and fingerprints.
	 *
	 * @return array<string, mixed>
	 */
	private static function section( \DOMXPath $xp, \DOMElement $el, int $index ): array {
		$raw    = (string) $el->textContent;
		$text   = mb_strtolower( self::squash( $raw ) );
		$shape  = self::shape( $el );
		$head   = self::first_text( $xp, '(.//*[self::h1 or self::h2 or self::h3])[1]', $el );
		$frames = self::frames( $el );
		$kinds  = array();
		foreach ( $frames as $src ) {
			$kinds[] = preg_match( '/youtube|vimeo|google|maps|wistia|calendly|form/', $src, $m ) === 1 ? $m[0] : 'other';
		}
		$keep = array_values( array_filter( self::classes( $el ), static fn( string $c ): bool => preg_match( '/^(wp-block-[a-z-]+|amr-[a-z-]+|alignfull|has-[a-z-]+-background-color|dxai-[a-z-]+)$/', $c ) === 1 ) );

		return array(
			'role'       => self::role( $xp, $el, $index, $raw, $head, $frames ),
			'head'       => mb_substr( self::squash( (string) $head ), 0, 90 ),
			'words'      => self::words( $text ),
			'imgs'       => $el->getElementsByTagName( 'img' )->length,
			'forms'      => self::count( $xp, './/form | .//*[' . self::cls( 'gform_wrapper' ) . ']', $el ),
			'iframes'    => $kinds,
			'questions'  => self::count( $xp, self::QUESTION_XPATH, $el ),
			'asked'      => self::questions( $xp, $el ),
			'text_hash'  => $text !== '' ? substr( sha1( $text ), 0, 12 ) : '',
			'shape_hash' => substr( sha1( $shape ), 0, 12 ),
			'shape_len'  => strlen( $shape ),
			'cls'        => implode( ' ', array_slice( $keep, 0, 6 ) ),
		);
	}

	/**
	 * The words of each question of a FAQ section: a details' summary, or the heading (or the first strong words, or the
	 * button) of the team's FAQ group.
	 *
	 * @return array<int, string>
	 */
	private static function questions( \DOMXPath $xp, \DOMElement $el ): array {
		$out = array();
		$qs  = $xp->query( self::QUESTION_XPATH, $el );
		if ( $qs === false ) {
			return $out;
		}
		foreach ( $qs as $q ) {
			if ( ! $q instanceof \DOMElement ) {
				continue;
			}
			$t = strtolower( $q->tagName ) === 'details'
				? self::first_text( $xp, './summary', $q )
				: self::first_text( $xp, '(.//*[self::summary or self::h2 or self::h3 or self::h4 or self::h5 or self::strong or self::button or self::dt])[1]', $q );
			$t = self::squash( (string) ( $t ?? $q->textContent ) );
			if ( $t !== '' && mb_strlen( $t ) <= 220 ) {
				$out[] = $t;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * What a section is for, by what it holds and what its heading says.
	 *
	 * @param array<int, string> $frames
	 */
	private static function role( \DOMXPath $xp, \DOMElement $el, int $index, string $raw, ?string $head, array $frames ): string {
		$w       = self::words( self::squash( $raw ) );
		$hl      = mb_strtolower( (string) $head );
		$imgs    = $el->getElementsByTagName( 'img' )->length;
		$tel     = self::count( $xp, './/a[starts-with(@href, "tel:")]', $el );
		$buttons = self::count( $xp, './/*[' . self::cls( 'wp-block-button' ) . ' or (self::a and ' . self::cls( 'button' ) . ') or ' . self::cls( 'btn' ) . ' or self::button]', $el );
		$forms   = self::count( $xp, './/form | .//*[' . self::cls( 'gform_wrapper' ) . ' or ' . self::cls( 'wpforms-container' ) . ' or ' . self::cls( 'wpcf7' ) . '] | .//input[@type="email" or @type="tel"]', $el );
		$qs      = self::count( $xp, self::QUESTION_XPATH . ' | .//*[contains(@itemtype, "Question")]', $el );
		$h3s     = self::count( $xp, './/h3 | .//h4', $el );
		$frame   = static fn( string $re ): bool => array_filter( $frames, static fn( string $s ): bool => preg_match( $re, $s ) === 1 ) !== array();

		if ( $el->getElementsByTagName( 'h1' )->length > 0 || ( $index === 0 && $w < 120 ) ) {
			return 'hero';
		}
		if ( $forms > 0 ) {
			return 'form';
		}
		if ( $qs >= 2 || preg_match( '/\bfaq|frequently asked|questions\b/u', $hl ) === 1 ) {
			return 'faq';
		}
		if ( preg_match( '/review|testimonial|what (our )?(customers|clients|neighbors|homeowners) (say|are saying)|★|stars?\b/u', $hl ) === 1 || self::count( $xp, './/*[' . self::cls( 'trustindex' ) . ' or contains(@class, "review") or contains(@class, "testimonial") or ' . self::cls( 'ti-widget' ) . ']', $el ) > 0 ) {
			return 'reviews';
		}
		if ( $frame( '/google\.[a-z.]+\/maps|maps\.google/' ) && $w < 200 ) {
			return 'map';
		}
		if ( preg_match( '/process|how it works|step|what to expect|what happens|our approach/u', $hl ) === 1 || self::count( $xp, './/ol/li', $el ) >= 3 ) {
			return 'process';
		}
		if ( preg_match( '/areas?\b|cities|communities|locations|serving|service area|where we (work|serve)|counties/u', $hl ) === 1 ) {
			return 'areas';
		}
		if ( $frame( '/youtube|vimeo|wistia/' ) || $el->getElementsByTagName( 'video' )->length > 0 ) {
			return 'video';
		}
		if ( $imgs >= 3 && $w < 40 ) {
			return 'trust';
		}
		if ( $w <= 70 && ( $tel > 0 || $buttons > 0 ) && $head !== null && $head !== '' ) {
			return 'cta';
		}
		if ( preg_match( '/related|other services|our services|services we (offer|provide)|what we do|more services/u', $hl ) === 1 && $h3s >= 2 ) {
			return 'related';
		}
		if ( $h3s >= 3 ) {
			return 'cards';
		}
		if ( $imgs >= 1 && $w >= 40 ) {
			return 'two-col';
		}

		return $w >= 40 ? 'text' : 'other';
	}
}

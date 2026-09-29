<?php
/**
 * Nested menu trees from designed header/footer HTML.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Menu_Tree {

	/**
	 * Flat items for Navigation_Factory / Schema (backward compatible).
	 *
	 * @return array<int, array{label:string, url:string}>
	 */
	public static function flatten( array $tree ): array {
		$out = array();
		foreach ( $tree as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $item['label'] ?? '' ) );
			$url   = (string) ( $item['url'] ?? '' );
			if ( $label !== '' ) {
				$out[] = array(
					'label' => $label,
					'url'   => $url !== '' ? $url : '#',
				);
			}
			$kids = is_array( $item['children'] ?? null ) ? $item['children'] : array();
			foreach ( self::flatten( $kids ) as $child ) {
				$out[] = $child;
			}
		}

		return $out;
	}

	/**
	 * Parse a header/footer HTML fragment into a nested menu tree.
	 *
	 * Top-level items are the links under `<nav>` (or the fragment root).
	 * Dropdown children are anchors inside a sibling panel after a top-level link
	 * (Design.com / mega-menu markup).
	 *
	 * The logo (see is_logo()) is never a menu item, and neither it nor any
	 * other link home is ever a parent (see dropdown_trigger()). Until this
	 * fix every element under the scope that was not itself an <a> was read
	 * as a dropdown — its first link the parent, every other link a child —
	 * so any wrapper folded. Measured with the pre-fix code on synthetic
	 * fragments (2026-09-24):
	 *   - no <nav>, text logo to "/" + four links → "Acme Plumbing" with
	 *     About/Services/Blog/Contact as its children;
	 *   - no <nav>, image-only logo (the H2O shape) → an EMPTY tree: the logo's
	 *     label is "", item_from_anchor() rejects it, and the `continue` that
	 *     followed threw the whole header away with it;
	 *   - `<nav><ul><li>…` → the first item became the parent of the others;
	 *   - a <nav> that is the header root and holds the logo (Lovable's
	 *     nav-as-header) → folded under the logo like the no-<nav> case.
	 * The tree reaches Navigation_Factory::save_tree(), which writes a parent
	 * with children as a wp:navigation-submenu, so the brand became a submenu
	 * holding the whole menu. H2O Away's desktop header — whose <nav> holds
	 * only items and dropdown wrappers — was already right and is unchanged
	 * (Services 5 children, Service Areas 7, Resources 3).
	 *
	 * @return array<int, array{label:string, url:string, children:array<int, array{label:string, url:string, children:array}>}>
	 */
	public static function from_html( string $html ): array {
		$html = trim( $html );
		if ( $html === '' || ! str_contains( $html, '<a' ) ) {
			return array();
		}

		$dom  = new \DOMDocument( '1.0', 'UTF-8' );
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?><div id="dxai-menu-root">' . $html . '</div>', LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$root = $dom->getElementById( 'dxai-menu-root' );
		if ( ! $root instanceof \DOMElement ) {
			return array();
		}

		$nav = null;
		foreach ( $root->getElementsByTagName( 'nav' ) as $candidate ) {
			if ( $candidate instanceof \DOMElement ) {
				$nav = $candidate;
				break;
			}
		}
		$scope = $nav instanceof \DOMElement ? $nav : $root;

		/*
		 * The brand position: the first real link of the whole fragment, not
		 * just of the scope. That is the logo in the headers checked — H2O
		 * Away's `<a href="https://h2oaway.com/"><img …></a>`, DevriX Elevate's
		 * `<a href="#top"><img alt="DevriX"></a>`, Arcus's `<a href="#top"><img>`
		 * inside its <nav>. When it sits outside the <nav>, as in the first two,
		 * it is never inside the scope and nothing below changes.
		 */
		$first = null;
		foreach ( $root->getElementsByTagName( 'a' ) as $a ) {
			if ( $a instanceof \DOMElement && ! preg_match( '/^(tel|mailto|javascript):/i', trim( $a->getAttribute( 'href' ) ) ) ) {
				$first = $a;
				break;
			}
		}

		return self::items_from_container( $scope, $first );
	}

	/**
	 * @return array<int, array{label:string, url:string, children:array}>
	 */
	private static function items_from_container( \DOMElement $scope, ?\DOMElement $first ): array {
		$items = array();
		foreach ( $scope->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			$tag = strtolower( $child->tagName );
			if ( $tag === 'a' ) {
				$item = self::item_from_anchor( $child );
				if ( $item !== null && ! self::is_logo( $child, $item['label'], $first ) ) {
					$items[] = $item;
				}
				continue;
			}
			$groups = self::link_groups( $child );
			if ( $groups === array() ) {
				continue;
			}
			// Dropdown wrapper (Design.com's relative trigger + panel): the
			// trigger is the parent, every link in the panel a child.
			$trigger = self::dropdown_trigger( $groups, $first );
			$parent  = $trigger instanceof \DOMElement ? self::item_from_anchor( $trigger ) : null;
			if ( $parent !== null ) {
				$kids = array();
				foreach ( $groups[1]->getElementsByTagName( 'a' ) as $a ) {
					$kid = $a instanceof \DOMElement ? self::item_from_anchor( $a ) : null;
					if ( $kid !== null ) {
						$kids[] = $kid;
					}
				}
				$parent['children'] = $kids;
				$items[]            = $parent;
				continue;
			}
			// Anything else is layout — a header row, a link list, a <ul>/<li>:
			// its links are items of THIS level, found by descending into it.
			foreach ( self::items_from_container( $child, $first ) as $item ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * The element children of $el that are links or hold links, in order.
	 *
	 * @return array<int, \DOMElement>
	 */
	private static function link_groups( \DOMElement $el ): array {
		$groups = array();
		foreach ( $el->childNodes as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}
			if ( strtolower( $node->tagName ) === 'a' || $node->getElementsByTagName( 'a' )->length > 0 ) {
				$groups[] = $node;
			}
		}

		return $groups;
	}

	/**
	 * The trigger link when a wrapper has the dropdown shape, else null.
	 *
	 * The shape is exactly two link-bearing children: a trigger (an <a>, or a
	 * box holding exactly one) and a panel that is not itself a link — H2O's
	 * `<div style="position:relative"><a>Services ▾</a><sc-if><div>…5 links`.
	 * A header row "logo + link list" has the very same shape, which is how
	 * the brand used to become the parent of the whole menu; it is told apart
	 * by its trigger being the fragment's first link or a link home.
	 *
	 * A boxed trigger also needs a panel of two links or more that is not a
	 * list item: two sibling `<li>`s are a list. Without that the rendered
	 * DevriX Portfolio Archive footer column `<ul><li><a>Privacy and Cookies
	 * Policy</a></li><li><a>Security Statement</a></li></ul>` read as a
	 * dropdown — the first link the parent of the second.
	 *
	 * @param array<int, \DOMElement> $groups link_groups() of the wrapper.
	 */
	private static function dropdown_trigger( array $groups, ?\DOMElement $first ): ?\DOMElement {
		if ( count( $groups ) !== 2 || strtolower( $groups[1]->tagName ) === 'a' ) {
			return null;
		}
		$head = $groups[0];
		if ( strtolower( $head->tagName ) !== 'a' ) {
			$inner = $head->getElementsByTagName( 'a' );
			if (
				$inner->length !== 1
				|| strtolower( $groups[1]->tagName ) === 'li'
				|| $groups[1]->getElementsByTagName( 'a' )->length < 2
			) {
				return null;
			}
			$head = $inner->item( 0 );
		}
		if ( ! $head instanceof \DOMElement ) {
			return null;
		}
		/*
		 * The logo is never a parent, and neither is a link home. It used to be
		 * "the fragment's first link is never a parent", which is the same
		 * thing only when the fragment starts with the logo: H2O's desktop
		 * <nav> on its own starts with the Services trigger, so Services lost
		 * its five children. is_logo() is the test the item pass already uses.
		 */
		if ( self::is_logo( $head, trim( (string) $head->textContent ), $first ) || self::home_fragment( $head ) !== null ) {
			return null;
		}

		return $head;
	}

	/**
	 * Whether a top-level link is the header's logo rather than a menu link.
	 *
	 * Only the fragment's first link can be the logo, and only when it is
	 * drawn with an image (which catches Lovable's placeholder `href="#"`
	 * brand), or is a text link to the front page itself ("/",
	 * "https://h2oaway.com/") standing BESIDE a box of links rather than in a
	 * run of them — the header row "logo + menu". An image-only logo never
	 * gets here: its label is "" and item_from_anchor() rejects it.
	 *
	 * Every other link home stays a menu item, measured on the rendered
	 * pages: H2O Away's footer logo is an unlinked <img>, so its first link is
	 * "Water Damage Restoration" at `https://h2oaway.com/` (the home page IS
	 * that service), one of five sibling links in <nav aria-label="Services">;
	 * DevriX Elevate's footer points "Privacy" and "Terms" at the placeholder
	 * "#top"; Global Market Launch's footer opens with a "Top" link to "#top".
	 * None of them is a logo. They still never become a parent: see
	 * dropdown_trigger().
	 */
	private static function is_logo( \DOMElement $a, string $label, ?\DOMElement $first ): bool {
		if ( $first === null || ! $a->isSameNode( $first ) || preg_match( '/^home(\s*page)?$/i', $label ) ) {
			return false;
		}
		if ( $a->getElementsByTagName( 'img' )->length > 0 || $a->getElementsByTagName( 'picture' )->length > 0 ) {
			return true;
		}
		// An inline-SVG logo; a decorative caret (DevriX Elevate's dropdown
		// arrow) is aria-hidden and does not count.
		foreach ( $a->getElementsByTagName( 'svg' ) as $svg ) {
			if ( $svg instanceof \DOMElement && ( ! $svg->hasAttribute( 'aria-hidden' ) || strtolower( $svg->getAttribute( 'aria-hidden' ) ) === 'false' ) ) {
				return true;
			}
		}

		return self::home_fragment( $a ) === '' && self::beside_links( $a );
	}

	/**
	 * Whether a link stands beside a box of links rather than in a run of
	 * single links: climbing out of its own wrappers, the first ancestor that
	 * holds other links also holds a box of two or more. The header row
	 * `<a href="/">Acme</a><div><a>About</a><a>Blog</a>…</div>` is beside;
	 * H2O's footer `<nav><span>Services</span><a>…</a><a>…</a>…` is a run.
	 */
	private static function beside_links( \DOMElement $a ): bool {
		$unit = $a;
		for ( $parent = $a->parentNode; $parent instanceof \DOMElement; $unit = $parent, $parent = $parent->parentNode ) {
			$groups = self::link_groups( $parent );
			if ( count( $groups ) < 2 ) {
				continue;
			}
			foreach ( $groups as $group ) {
				if ( ! $group->isSameNode( $unit ) && strtolower( $group->tagName ) !== 'a' && $group->getElementsByTagName( 'a' )->length > 1 ) {
					return true;
				}
			}

			return false;
		}

		return false;
	}

	/**
	 * For a link to a site's front page, its fragment: "" for the page itself
	 * ("/", "./", "index.html", "https://h2oaway.com/"), "top" or "home" for
	 * the in-page top anchors ("#top", "/#home"). Null for any other link.
	 * "" and "#" are placeholders, not destinations, and give null.
	 */
	private static function home_fragment( \DOMElement $a ): ?string {
		$href = trim( $a->getAttribute( 'href' ) );
		if ( $href === '' || $href === '#' ) {
			return null;
		}
		$parts = wp_parse_url( $href );
		if ( ! is_array( $parts ) || isset( $parts['query'] ) ) {
			return null;
		}
		if ( isset( $parts['scheme'] ) && ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return null;
		}
		$path     = (string) ( $parts['path'] ?? '' );
		$fragment = strtolower( (string) ( $parts['fragment'] ?? '' ) );
		if ( ! in_array( $path, array( '', '/', './', 'index.html', '/index.html' ), true ) ) {
			return null;
		}

		return in_array( $fragment, array( '', 'top', 'home' ), true ) ? $fragment : null;
	}

	/**
	 * @return array{label:string, url:string, children:array}|null
	 */
	private static function item_from_anchor( \DOMElement $a ): ?array {
		$label = trim( preg_replace( '/\s+/', ' ', $a->textContent ) ?? '' );
		$label = html_entity_decode( $label, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Strip decorative caret glyphs Design.com leaves as "?" or "▾".
		$label = trim( (string) preg_replace( '/[\x{25BE}\x{25BC}\x{25B8}\?\x{2026}]+$/u', '', $label ) );
		$label = sanitize_text_field( $label );
		if ( $label === '' || mb_strlen( $label ) > 80 ) {
			return null;
		}
		$url = trim( $a->getAttribute( 'href' ) );
		if ( str_starts_with( $url, 'tel:' ) || str_starts_with( $url, 'mailto:' ) || str_starts_with( $url, 'javascript:' ) ) {
			return null;
		}

		return array(
			'label'    => $label,
			'url'      => $url !== '' ? $url : '#',
			'children' => array(),
		);
	}
}

<?php
/**
 * Builder-agnostic main-content finder and chrome strip for live HTML.
 *
 * Shared by Live_Page_Fetcher and Content_Extractor so WordPress, Webflow,
 * Shopify, and plain SSR pages use the same landmarks — without wiping
 * content that lives in a semantic <header> or in-page <nav>.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

final class Html_Main {

	/** Class/id tokens that mark site chrome (header, footer, cookies), not page content. */
	private const CHROME_CLASS = '/\b(elementor-location-(header|footer)|site-header|site-footer|site-navigation|main-navigation|navbar|nav-bar|cookie|cmplz|gdpr|consent|screen-reader-text|skip-link|visually-hidden|sr-only|wpadminbar|announcement-bar|top-bar|utility-bar)\b/i';

	private const CHROME_ID = '/^(masthead|colophon|site-header|site-footer|site-navigation|cookie|wpadminbar|header|footer|nav|navigation|menu-primary)$/i';

	/**
	 * Cloudflare / bot challenge page that returned HTTP 200.
	 */
	public static function is_challenge( string $html ): bool {
		$sample = strtolower( substr( $html, 0, 12000 ) );

		return str_contains( $sample, 'cf-browser-verification' )
			|| str_contains( $sample, 'cf-challenge' )
			|| str_contains( $sample, 'attention required' )
			|| str_contains( $sample, 'just a moment' )
			|| str_contains( $sample, 'checking your browser' )
			|| str_contains( $sample, 'enable javascript and cookies' )
			|| ( str_contains( $sample, 'challenge-platform' ) && str_contains( $sample, 'cloudflare' ) );
	}

	/**
	 * Client-rendered shell with essentially no server HTML content.
	 */
	public static function is_spa_shell( string $html ): bool {
		$sample = strtolower( $html );
		$markers = array(
			'id="root"',
			"id='root'",
			'id="__next"',
			"id='__next'",
			'id="app"',
			"id='app'",
			'data-reactroot',
			'__next_data__',
			'ng-version=',
		);
		$hit = false;
		foreach ( $markers as $m ) {
			if ( str_contains( $sample, $m ) ) {
				$hit = true;
				break;
			}
		}
		if ( ! $hit ) {
			return false;
		}

		// Strip scripts/styles and measure remaining visible text.
		$plain = preg_replace( '/<(script|style|noscript)\b[^>]*>.*?<\/\1>/is', '', $html ) ?? $html;
		$plain = trim( wp_strip_all_tags( $plain ) );

		return strlen( $plain ) < 80;
	}

	/**
	 * Best content root for a full page document.
	 */
	public static function find( \DOMDocument $dom ): ?\DOMElement {
		$xpath = new \DOMXPath( $dom );

		foreach ( $dom->getElementsByTagName( 'main' ) as $node ) {
			if ( $node instanceof \DOMElement && ! self::is_chrome_element( $node ) ) {
				return self::unwrap( $node );
			}
		}

		$role = $xpath->query( '//*[@role="main"]' );
		if ( $role instanceof \DOMNodeList && $role->length > 0 ) {
			$node = $role->item( 0 );
			if ( $node instanceof \DOMElement ) {
				return self::unwrap( $node );
			}
		}

		$queries = array(
			'//*[@id="MainContent" or @id="main-content" or @id="mainContent" or @id="content" or @id="primary" or @id="__next" or @id="app"]',
			'//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]',
			'//*[contains(concat(" ", normalize-space(@class), " "), " site-content ")]',
			'//*[contains(concat(" ", normalize-space(@class), " "), " w-main ")]',
			'//*[contains(concat(" ", normalize-space(@class), " "), " Main-content ")]',
			'//*[contains(concat(" ", normalize-space(@class), " "), " page-content ")]',
			'//article[not(ancestor::article)]',
		);
		foreach ( $queries as $query ) {
			$list = $xpath->query( $query );
			if ( ! $list instanceof \DOMNodeList || $list->length < 1 ) {
				continue;
			}
			$node = $list->item( 0 );
			if ( $node instanceof \DOMElement && ! self::is_chrome_element( $node ) ) {
				return self::unwrap( $node );
			}
		}

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body instanceof \DOMElement ) {
			return null;
		}

		return self::best_descendant( $body );
	}

	/**
	 * Remove scripts and site chrome; keep content headers/navs that carry real copy.
	 */
	public static function strip_chrome( \DOMElement $root ): void {
		$remove = array();
		foreach ( array( 'script', 'style', 'noscript', 'template' ) as $tag ) {
			foreach ( $root->getElementsByTagName( $tag ) as $node ) {
				if ( $node instanceof \DOMElement ) {
					$remove[] = $node;
				}
			}
		}
		foreach ( array( 'header', 'footer', 'nav', 'aside', 'iframe' ) as $tag ) {
			foreach ( $root->getElementsByTagName( $tag ) as $node ) {
				if ( ! $node instanceof \DOMElement ) {
					continue;
				}
				if ( self::is_chrome_element( $node ) || self::is_empty_chrome_tag( $node ) ) {
					$remove[] = $node;
				}
			}
		}
		// Class/id chrome even on plain divs.
		$xpath = new \DOMXPath( $root->ownerDocument );
		$list  = $xpath->query( './/*[@class or @id]', $root );
		if ( $list instanceof \DOMNodeList ) {
			foreach ( $list as $node ) {
				if ( $node instanceof \DOMElement && self::is_chrome_element( $node ) ) {
					$remove[] = $node;
				}
			}
		}

		$seen = array();
		foreach ( $remove as $node ) {
			$id = spl_object_id( $node );
			if ( isset( $seen[ $id ] ) || ! $node->parentNode ) {
				continue;
			}
			$seen[ $id ] = true;
			$node->parentNode->removeChild( $node );
		}
	}

	/**
	 * Whether an element is site chrome (not page content).
	 */
	public static function is_chrome_element( \DOMElement $el ): bool {
		$name = strtolower( $el->nodeName );
		if ( in_array( $name, array( 'script', 'style', 'noscript', 'template', 'link', 'meta' ), true ) ) {
			return true;
		}
		$class = ' ' . strtolower( (string) $el->getAttribute( 'class' ) ) . ' ';
		$id    = strtolower( (string) $el->getAttribute( 'id' ) );
		$role  = strtolower( (string) $el->getAttribute( 'role' ) );
		if ( preg_match( self::CHROME_CLASS, $class ) ) {
			return true;
		}
		if ( $id !== '' && preg_match( self::CHROME_ID, $id ) ) {
			return true;
		}
		if ( in_array( $role, array( 'banner', 'navigation', 'contentinfo', 'complementary' ), true ) ) {
			// Keep if it still looks like a content band (hero with headings).
			if ( ! self::looks_like_content( $el ) ) {
				return true;
			}
		}
		if ( preg_match( '/\b(widget_|sidebar|elementor-location-popup)\b/i', $class ) ) {
			return true;
		}
		if ( strtolower( (string) $el->getAttribute( 'aria-hidden' ) ) === 'true' ) {
			return true;
		}

		return false;
	}

	/**
	 * Inner HTML of an element.
	 */
	public static function inner_html( \DOMElement $el ): string {
		$html = '';
		foreach ( $el->childNodes as $child ) {
			$html .= $el->ownerDocument->saveHTML( $child );
		}

		return trim( $html );
	}

	/**
	 * Collect absolute image URLs including lazy-load and data-bg attributes.
	 *
	 * @return array<int, string>
	 */
	public static function collect_images( string $html, string $base_url, callable $absolutize ): array {
		$urls = array();

		if ( preg_match_all( '/<img\b[^>]*>/i', $html, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				$src = '';
				foreach ( array( 'nitro-lazy-src', 'data-src', 'data-lazy-src', 'data-original', 'src' ) as $attr ) {
					if ( preg_match( '/\b' . preg_quote( $attr, '/' ) . '=["\']([^"\']+)["\']/i', $tag, $m ) ) {
						$cand = $m[1];
						if ( ! str_starts_with( $cand, 'data:' ) ) {
							$src = $cand;
							break;
						}
					}
				}
				$set = '';
				foreach ( array( 'nitro-lazy-srcset', 'data-srcset', 'data-lazy-srcset', 'srcset' ) as $attr ) {
					if ( preg_match( '/\b' . preg_quote( $attr, '/' ) . '=["\']([^"\']+)["\']/i', $tag, $m ) ) {
						$set = $m[1];
						break;
					}
				}
				if ( $set !== '' ) {
					$best = 0;
					foreach ( preg_split( '/\s*,\s*/', $set ) ?: array() as $cand ) {
						$cand = trim( $cand );
						if ( preg_match( '/^(\S+)\s+(\d+)w$/', $cand, $wm ) && (int) $wm[2] >= $best ) {
							$best = (int) $wm[2];
							$src  = $wm[1];
						} elseif ( $src === '' ) {
							$src = trim( (string) strtok( $cand, ' ' ) );
						}
					}
				}
				$abs = (string) $absolutize( $src, $base_url );
				if ( $abs !== '' ) {
					$urls[] = $abs;
				}
			}
		}

		foreach ( array( 'data-bg', 'data-background', 'data-bg-image', 'nitro-lazy-bg' ) as $attr ) {
			if ( preg_match_all( '/\b' . preg_quote( $attr, '/' ) . '=["\']([^"\']+)["\']/i', $html, $bm ) ) {
				foreach ( $bm[1] as $raw ) {
					$raw = preg_replace( '/^url\(\s*[\'"]?|[\'"]?\s*\)$/i', '', trim( $raw ) ) ?? $raw;
					$abs = (string) $absolutize( $raw, $base_url );
					if ( $abs !== '' ) {
						$urls[] = $abs;
					}
				}
			}
		}

		if ( preg_match_all( '/background(?:-image)?\s*:\s*[^;]*url\(\s*[\'"]?([^\'")]+)/i', $html, $sm ) ) {
			foreach ( $sm[1] as $raw ) {
				$abs = (string) $absolutize( trim( $raw ), $base_url );
				if ( $abs !== '' && ! str_starts_with( $abs, 'data:' ) ) {
					$urls[] = $abs;
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Descend through single wrappers so scoring starts at the real content tree.
	 */
	private static function unwrap( \DOMElement $el ): \DOMElement {
		$main = $el;
		for ( $depth = 0; $depth < 6; $depth++ ) {
			$kids = array();
			foreach ( $main->childNodes as $child ) {
				if ( $child instanceof \DOMElement && ! self::is_chrome_element( $child ) ) {
					$tag = strtolower( $child->tagName );
					if ( ! in_array( $tag, array( 'script', 'style', 'noscript' ), true ) ) {
						$kids[] = $child;
					}
				}
			}
			if ( count( $kids ) !== 1 || preg_match( '/^h[1-6]$/', strtolower( $kids[0]->tagName ) ) ) {
				break;
			}
			$main = $kids[0];
		}

		return $main;
	}

	/**
	 * Score descendants of body for content density.
	 */
	private static function best_descendant( \DOMElement $body ): ?\DOMElement {
		$best     = null;
		$best_score = 0;
		$queue    = array( $body );
		$depth    = 0;

		while ( $queue !== array() && $depth < 4 ) {
			$next = array();
			foreach ( $queue as $node ) {
				foreach ( $node->childNodes as $child ) {
					if ( ! $child instanceof \DOMElement ) {
						continue;
					}
					$tag = strtolower( $child->tagName );
					if ( in_array( $tag, array( 'script', 'style', 'noscript', 'svg' ), true ) ) {
						continue;
					}
					if ( self::is_chrome_element( $child ) ) {
						continue;
					}
					$score = self::content_score( $child );
					if ( $score > $best_score ) {
						$best_score = $score;
						$best       = $child;
					}
					$next[] = $child;
				}
			}
			$queue = $next;
			++$depth;
		}

		return $best;
	}

	private static function content_score( \DOMElement $el ): int {
		$text = strlen( trim( preg_replace( '/\s+/', ' ', $el->textContent ?? '' ) ?? '' ) );
		$imgs = $el->getElementsByTagName( 'img' )->length;
		$heads = 0;
		foreach ( array( 'h1', 'h2', 'h3' ) as $h ) {
			$heads += $el->getElementsByTagName( $h )->length;
		}
		$paras = $el->getElementsByTagName( 'p' )->length;

		return $text + ( $imgs * 80 ) + ( $heads * 120 ) + ( $paras * 40 );
	}

	private static function looks_like_content( \DOMElement $el ): bool {
		$heads = $el->getElementsByTagName( 'h1' )->length
			+ $el->getElementsByTagName( 'h2' )->length
			+ $el->getElementsByTagName( 'h3' )->length;
		$text = strlen( trim( $el->textContent ?? '' ) );

		return $heads > 0 || $text > 200;
	}

	/**
	 * Bare header/nav/footer with almost no content → safe to strip.
	 */
	private static function is_empty_chrome_tag( \DOMElement $el ): bool {
		$name = strtolower( $el->nodeName );
		if ( ! in_array( $name, array( 'header', 'footer', 'nav', 'aside' ), true ) ) {
			return false;
		}
		if ( self::looks_like_content( $el ) ) {
			return false;
		}
		// Navs / footers with only links are chrome.
		$text = trim( preg_replace( '/\s+/', ' ', $el->textContent ?? '' ) ?? '' );

		return strlen( $text ) < 120;
	}
}

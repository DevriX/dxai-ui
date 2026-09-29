<?php
/**
 * A live page's content as an ordered model: sections of headings,
 * paragraphs, lists, buttons, images, questions, reviews and cards.
 *
 * Built for the page-from-design import: what a visitor reads and sees on the
 * old page, without its layout or styling. Works on server-rendered HTML from
 * any builder — Elementor (widgets and containers), Gutenberg (block
 * classes), or plain markup (sections split at headings) — and ignores the
 * site's own header, footer, navigation, scripts, cookie bars and widgets
 * that only duplicate a mobile or desktop variant.
 *
 * Images are the real files: lazy-loading attributes (data-src,
 * data-lazy-src, nitro-lazy-src, srcset candidates) win over placeholders,
 * and background images are read from inline styles, data attributes and the
 * page's own stylesheets (Elementor writes section backgrounds there).
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

final class Content_Extractor {

	private const BLOCK_TAGS = array( 'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'table', 'blockquote', 'figure', 'form', 'iframe', 'details', 'hr', 'section', 'article', 'header', 'footer', 'nav', 'aside', 'img', 'picture', 'video', 'dl', 'pre', 'address', 'main' );
	private const INLINE    = array( 'strong' => 'strong', 'b' => 'strong', 'em' => 'em', 'i' => 'em', 'u' => 'u', 'sup' => 'sup', 'sub' => 'sub', 'mark' => 'mark', 's' => 's' );
	private const SKIP      = array( 'script', 'style', 'noscript', 'template', 'svg', 'iframe', 'button', 'input', 'select', 'textarea', 'img', 'picture', 'video', 'canvas', 'form' );

	private \DOMDocument $dom;
	private \DOMXPath $xp;
	private string $base = '';
	/** @var array<string, string> CSS class/id => background image URL. */
	private array $bg = array();
	/** @var array<int, array<string, mixed>> */
	private array $images = array();
	/** @var array<string, string> */
	private array $image_refs = array();
	private int $section = -1;

	/**
	 * @param string                $html Full page HTML.
	 * @param string                $url  The page's URL (for relative links and images).
	 * @param array<int, string>    $css  Extra stylesheet text (the page's own linked sheets), for backgrounds.
	 * @return array<string, mixed>
	 */
	public function extract( string $html, string $url, array $css = array() ): array {
		$this->base = $url;
		$dom        = new \DOMDocument( '1.0', 'UTF-8' );
		$prev       = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NODEFDTD | LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$this->dom = $dom;
		$this->xp  = new \DOMXPath( $dom );

		foreach ( $this->xp->query( '//style' ) as $style ) {
			$css[] = (string) $style->textContent;
		}
		$this->bg = self::backgrounds( implode( "\n", $css ) );

		$seo = array(
			'title'       => self::clean( (string) $this->first_text( '//title' ) ),
			'description' => (string) $this->meta( 'description' ),
			'canonical'   => (string) $this->attr( '//link[@rel="canonical"]', 'href' ),
			'og_image'    => (string) $this->meta_property( 'og:image' ),
		);

		$sections = array();
		foreach ( $this->section_roots() as $i => $root ) {
			$this->section = count( $sections );
			$blocks        = array();
			$bg            = $this->background_of( $root );
			if ( $bg !== '' ) {
				$bg = $this->image_ref( $bg, '', 'background' );
			}
			$this->walk( $root, $blocks );
			$blocks = array_values( array_filter( $blocks ) );
			if ( $blocks === array() && $bg === '' ) {
				continue;
			}
			$sections[] = array(
				'index'      => $this->section,
				'blocks'     => $blocks,
				'background' => $bg,
			);
		}

		return array(
			'url'      => $url,
			'seo'      => $seo,
			'h1'       => array_values( array_filter( array_map( static fn( $n ) => self::clean( $n->textContent ), iterator_to_array( $this->xp->query( '//h1' ) ) ) ) ),
			'sections' => $sections,
			'images'   => $this->images,
		);
	}

	/**
	 * The page's content sections, in order: the page builder's top-level
	 * containers when there are any, otherwise the main element's children.
	 *
	 * @return array<int, \DOMElement>
	 */
	private function section_roots(): array {
		// Elementor: the page document (not header/footer/popup templates), its top-level containers.
		$docs = $this->xp->query( '//*[@data-elementor-type and not(@data-elementor-type="header") and not(@data-elementor-type="footer") and not(@data-elementor-type="popup") and not(ancestor::*[@data-elementor-type])]' );
		$out  = array();
		foreach ( $docs as $doc ) {
			foreach ( $doc->childNodes as $child ) {
				if ( $child instanceof \DOMElement && ! $this->ignored( $child ) ) {
					$out[] = $child;
				}
			}
		}
		if ( $out !== array() ) {
			return $out;
		}
		$main = Html_Main::find( $this->dom );
		if ( ! $main instanceof \DOMElement ) {
			$main = $this->xp->query( '//body' )->item( 0 );
		}
		if ( ! $main instanceof \DOMElement ) {
			return array();
		}
		// Descend through single wrappers to where the sections are.
		for ( $depth = 0; $depth < 6; $depth++ ) {
			$kids = array_values( array_filter( iterator_to_array( $main->childNodes ), fn( $n ) => $n instanceof \DOMElement && ! $this->ignored( $n ) ) );
			if ( count( $kids ) === 1 && ! preg_match( '/^h[1-6]$/', $kids[0]->nodeName ) ) {
				$main = $kids[0];
				continue;
			}
			return $kids;
		}

		return array( $main );
	}

	/** Site chrome and machinery that is never page content. */
	private function ignored( \DOMElement $el ): bool {
		$name = strtolower( $el->nodeName );
		if ( in_array( $name, array( 'script', 'style', 'noscript', 'template', 'link', 'meta' ), true ) ) {
			return true;
		}
		// Bare header/nav/footer only when they look like chrome — keep hero <header> with headings.
		if ( in_array( $name, array( 'nav', 'header', 'footer', 'aside' ), true ) ) {
			return Html_Main::is_chrome_element( $el )
				|| ( strlen( trim( preg_replace( '/\s+/', ' ', $el->textContent ?? '' ) ?? '' ) ) < 120
					&& $el->getElementsByTagName( 'h1' )->length
					+ $el->getElementsByTagName( 'h2' )->length === 0 );
		}

		return Html_Main::is_chrome_element( $el );
	}

	/**
	 * @param array<int, array<string, mixed>|null> $out
	 */
	private function walk( \DOMElement $el, array &$out ): void {
		if ( $this->ignored( $el ) ) {
			return;
		}
		$class = ' ' . (string) $el->getAttribute( 'class' ) . ' ';
		// Elementor widgets carry their type.
		if ( str_contains( $class, ' elementor-widget ' ) || $el->hasAttribute( 'data-widget_type' ) ) {
			$this->widget( $el, $out );
			return;
		}
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMElement ) {
				$bg = $this->background_of( $child );
				if ( $bg !== '' ) {
					$out[] = array( 'type' => 'bg', 'ref' => $this->image_ref( $bg, '', 'background' ) );
				}
				$this->rich_node( $child, $out );
			} elseif ( $child instanceof \DOMText && self::clean( $child->textContent ) !== '' ) {
				$out[] = array( 'type' => 'paragraph', 'html' => esc_html( self::clean( $child->textContent ) ) );
			}
		}
	}

	/**
	 * One Elementor widget.
	 *
	 * @param array<int, array<string, mixed>|null> $out
	 */
	private function widget( \DOMElement $w, array &$out ): void {
		$type = (string) strtok( (string) $w->getAttribute( 'data-widget_type' ), '.' );
		if ( $type === '' && preg_match( '/elementor-widget-([\w-]+)/', (string) $w->getAttribute( 'class' ), $m ) ) {
			$type = $m[1];
		}
		$q = fn( string $rel ) => $this->xp->query( $rel, $w );
		switch ( $type ) {
			case 'heading':
			case 'theme-post-title':
			case 'theme-page-title':
			case 'animated-headline':
				$h = $q( './/*[contains(@class,"elementor-heading-title")] | .//h1 | .//h2 | .//h3 | .//h4 | .//h5 | .//h6' )->item( 0 );
				if ( $h instanceof \DOMElement ) {
					$level = preg_match( '/^h([1-6])$/', strtolower( $h->nodeName ), $lm ) ? (int) $lm[1] : 0;
					$this->push_heading( $out, $level, $this->inline_html( $h ) );
				}
				return;
			case 'button':
				$a = $q( './/a' )->item( 0 );
				$t = $q( './/*[contains(@class,"elementor-button-text")]' )->item( 0 );
				$label = self::clean( ( $t ?? $a ?? $w )->textContent );
				if ( $label !== '' ) {
					$out[] = array( 'type' => 'button', 'label' => $label, 'href' => $a instanceof \DOMElement ? $this->href( (string) $a->getAttribute( 'href' ) ) : '' );
				}
				return;
			case 'icon-list':
				$items = array();
				foreach ( $q( './/*[contains(concat(" ", normalize-space(@class), " "), " elementor-icon-list-item ")]' ) as $li ) {
					$text = $this->xp->query( './/*[contains(@class,"elementor-icon-list-text")]', $li )->item( 0 );
					$html = $this->inline_html( $text instanceof \DOMElement ? $text : $li );
					if ( self::plain( $html ) !== '' ) {
						$items[] = $html;
					}
				}
				if ( $items ) {
					$out[] = array( 'type' => 'list', 'ordered' => false, 'items' => $items );
				}
				return;
			case 'image':
			case 'theme-post-featured-image':
				$img = $q( './/img' )->item( 0 );
				if ( $img instanceof \DOMElement ) {
					$ref = $this->image_from( $img );
					if ( $ref !== '' ) {
						$out[] = array( 'type' => 'image', 'ref' => $ref );
					}
				}
				return;
			case 'theme-site-logo':
			case 'site-logo':
			case 'nav-menu':
			case 'search-form':
			case 'spacer':
			case 'divider':
			case 'social-icons':
			case 'breadcrumbs':
				return;
			case 'image-box':
			case 'icon-box':
				$title = $q( './/*[contains(@class,"-box-title")]' )->item( 0 );
				$desc  = $q( './/*[contains(@class,"-box-description")]' )->item( 0 );
				$a     = $q( './/a' )->item( 0 );
				$img   = $q( './/img' )->item( 0 );
				$out[] = array(
					'type'  => 'box',
					'title' => $title instanceof \DOMElement ? self::plain( $this->inline_html( $title ) ) : '',
					'html'  => $desc instanceof \DOMElement ? $this->inline_html( $desc ) : '',
					'href'  => $a instanceof \DOMElement ? $this->href( (string) $a->getAttribute( 'href' ) ) : '',
					'image' => $img instanceof \DOMElement && $type === 'image-box' ? $this->image_from( $img ) : '',
				);
				return;
			case 'counter':
				$num   = $q( './/*[contains(@class,"elementor-counter-number")]' )->item( 0 );
				$value = $num instanceof \DOMElement ? ( (string) $num->getAttribute( 'data-to-value' ) ?: self::clean( $num->textContent ) ) : '';
				$pre   = self::clean( (string) ( $q( './/*[contains(@class,"counter-number-prefix")]' )->item( 0 )->textContent ?? '' ) );
				$suf   = self::clean( (string) ( $q( './/*[contains(@class,"counter-number-suffix")]' )->item( 0 )->textContent ?? '' ) );
				$label = self::clean( (string) ( $q( './/*[contains(@class,"elementor-counter-title")]' )->item( 0 )->textContent ?? '' ) );
				$out[] = array( 'type' => 'stat', 'value' => $pre . $value . $suf, 'label' => $label );
				return;
			case 'testimonial':
				$out[] = array(
					'type'  => 'review',
					'quote' => $this->inline_html( $q( './/*[contains(@class,"elementor-testimonial-content")]' )->item( 0 ) ),
					'name'  => self::clean( (string) ( $q( './/*[contains(@class,"elementor-testimonial-name")]' )->item( 0 )->textContent ?? '' ) ),
				);
				return;
			case 'accordion':
			case 'toggle':
			case 'nested-accordion':
				foreach ( $q( './/*[contains(@class,"elementor-accordion-item") or contains(@class,"elementor-toggle-item")] | .//details' ) as $item ) {
					$title = $this->xp->query( './/*[contains(@class,"-title")] | .//summary', $item )->item( 0 );
					$body  = $this->xp->query( './/*[contains(@class,"elementor-tab-content")] | .//*[contains(@class,"e-n-accordion-item-content")]', $item )->item( 0 );
					$answer = array();
					if ( $body instanceof \DOMElement ) {
						$this->rich_children( $body, $answer );
					}
					$out[] = array( 'type' => 'faq', 'q' => self::clean( (string) ( $title->textContent ?? '' ) ), 'a' => array_values( array_filter( $answer ) ) );
				}
				return;
			case 'posts':
			case 'loop-grid':
			case 'archive-posts':
				foreach ( $q( './/article | .//*[contains(@class,"e-loop-item")]' ) as $art ) {
					$t   = $this->xp->query( './/*[contains(@class,"elementor-post__title")] | .//h2 | .//h3 | .//h4', $art )->item( 0 );
					$a   = $this->xp->query( './/a[@href]', $art )->item( 0 );
					$ex  = $this->xp->query( './/*[contains(@class,"elementor-post__excerpt")]', $art )->item( 0 );
					$img = $this->xp->query( './/img', $art )->item( 0 );
					// The card's own "Read More" button: its words go where the design's card has its "Learn more".
					$more  = $this->xp->query( './/*[contains(@class,"read-more")] | .//a[contains(@class,"elementor-button")]', $art )->item( 0 );
					$out[] = array(
						'type'  => 'box',
						'title' => self::clean( (string) ( $t->textContent ?? '' ) ),
						'html'  => $ex instanceof \DOMElement ? esc_html( self::clean( $ex->textContent ) ) : '',
						'href'  => $a instanceof \DOMElement ? $this->href( (string) $a->getAttribute( 'href' ) ) : '',
						'image' => $img instanceof \DOMElement ? $this->image_from( $img ) : '',
						'label' => $more instanceof \DOMElement ? mb_substr( self::clean( $more->textContent ), 0, 40 ) : '',
					);
				}
				return;
			case 'form':
				$out[] = array( 'type' => 'form' );
				return;
			case 'google_maps':
				$out[] = array( 'type' => 'map' );
				return;
		}
		// Anything else (text editor, custom widgets): its rich content — trust widgets get special treatment.
		if ( $q( './/*[contains(@class,"ti-widget")]' )->length > 0 ) {
			$this->reviews_widget( $w, $out );
			return;
		}
		$container = $q( './*[contains(@class,"elementor-widget-container")]' )->item( 0 );
		$this->rich_children( $container instanceof \DOMElement ? $container : $w, $out );
	}

	/**
	 * Trustindex review widgets (Google reviews shown on WordPress sites).
	 *
	 * @param array<int, array<string, mixed>|null> $out
	 */
	private function reviews_widget( \DOMElement $w, array &$out ): void {
		$seen = array();
		foreach ( $this->xp->query( './/*[contains(@class,"ti-review-item")]', $w ) as $it ) {
			$name = self::clean( (string) ( $this->xp->query( './/*[contains(@class,"ti-name")]', $it )->item( 0 )->textContent ?? '' ) );
			$body = $this->xp->query( './/*[contains(@class,"ti-review-content")] | .//*[contains(@class,"ti-review-text-container")]', $it )->item( 0 );
			$text = preg_replace( '/\s*Read more$/', '', self::clean( (string) ( $body->textContent ?? '' ) ) ) ?? '';
			$key  = $name . '|' . mb_substr( $text, 0, 40 );
			if ( $text === '' || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$stars        = $this->xp->query( './/*[contains(@class,"ti-stars")]//*[contains(@class,"ti-star") and (contains(@src,"/f.svg") or contains(@class," f"))]', $it )->length;
			$out[]        = array( 'type' => 'review', 'quote' => esc_html( $text ), 'name' => $name, 'rating' => $stars ?: 5 );
		}
	}

	/**
	 * @param array<int, array<string, mixed>|null> $out
	 */
	private function rich_children( \DOMElement $el, array &$out ): void {
		$buf   = '';
		$flush = function () use ( &$buf, &$out ): void {
			$html = self::tidy( $buf );
			if ( self::plain( $html ) !== '' ) {
				$out[] = array( 'type' => 'paragraph', 'html' => $html );
			}
			$buf = '';
		};
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMText ) {
				$buf .= esc_html( $child->textContent );
				continue;
			}
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			$tag = strtolower( $child->nodeName );
			if ( ! in_array( $tag, self::BLOCK_TAGS, true ) ) {
				if ( $this->xp->query( './/img', $child )->length > 0 && self::plain( $this->inline_html( $child ) ) === '' ) {
					$flush();
					$this->images_in( $child, $out );
					continue;
				}
				$buf .= $this->inline_html_node( $child );
				continue;
			}
			$flush();
			$this->rich_node( $child, $out );
		}
		$flush();
	}

	/**
	 * One block-level element of rich content.
	 *
	 * @param array<int, array<string, mixed>|null> $out
	 */
	private function rich_node( \DOMElement $c, array &$out ): void {
		if ( $this->ignored( $c ) ) {
			return;
		}
		$tag   = strtolower( $c->nodeName );
		$class = ' ' . (string) $c->getAttribute( 'class' ) . ' ';
		if ( str_contains( $class, ' elementor-widget ' ) || $c->hasAttribute( 'data-widget_type' ) ) {
			$this->widget( $c, $out );
			return;
		}
		if ( str_contains( $class, ' faq-item ' ) || str_contains( $class, 'rank-math-faq-item' ) || str_contains( $class, 'schema-faq-section' ) || $tag === 'details' ) {
			$qn     = $this->xp->query( './/*[contains(@class,"faq-question") or contains(@class,"rank-math-question") or contains(@class,"schema-faq-question")] | .//summary', $c )->item( 0 );
			$an     = $this->xp->query( './/*[contains(@class,"faq-answer") or contains(@class,"rank-math-answer") or contains(@class,"schema-faq-answer")]', $c )->item( 0 );
			$answer = array();
			if ( $an instanceof \DOMElement ) {
				$this->rich_children( $an, $answer );
			} elseif ( $tag === 'details' ) {
				foreach ( $c->childNodes as $n ) {
					if ( $n instanceof \DOMElement && strtolower( $n->nodeName ) !== 'summary' ) {
						$this->rich_node( $n, $answer );
					}
				}
			}
			$q = $qn instanceof \DOMElement ? self::clean( preg_replace( '/[+−–-]\s*$/u', '', $qn->textContent ) ?? '' ) : '';
			if ( $q !== '' ) {
				$out[] = array( 'type' => 'faq', 'q' => $q, 'a' => array_values( array_filter( $answer ) ) );
			}
			return;
		}
		if ( str_contains( $class, 'wp-block-button' ) || str_contains( $class, 'wp-block-buttons' ) ) {
			foreach ( $this->xp->query( './/a', $c ) as $a ) {
				$out[] = array( 'type' => 'button', 'label' => self::clean( $a->textContent ), 'href' => $this->href( (string) $a->getAttribute( 'href' ) ) );
			}
			return;
		}
		if ( preg_match( '/^h([1-6])$/', $tag, $m ) ) {
			$this->push_heading( $out, (int) $m[1], $this->inline_html( $c ) );
			return;
		}
		switch ( $tag ) {
			case 'p':
			case 'address':
			case 'pre':
				$this->images_in( $c, $out );
				$html = $this->inline_html( $c );
				if ( self::plain( $html ) !== '' ) {
					$out[] = array( 'type' => 'paragraph', 'html' => $html );
				}
				return;
			case 'ul':
			case 'ol':
				$items = array();
				foreach ( $c->childNodes as $li ) {
					if ( $li instanceof \DOMElement && strtolower( $li->nodeName ) === 'li' ) {
						$html = $this->inline_html( $li );
						if ( self::plain( $html ) !== '' ) {
							$items[] = $html;
						}
					}
				}
				if ( $items ) {
					$out[] = array( 'type' => 'list', 'ordered' => $tag === 'ol', 'items' => $items );
				}
				return;
			case 'blockquote':
				$out[] = array( 'type' => 'review', 'quote' => $this->inline_html( $c ), 'name' => '' );
				return;
			case 'img':
				$ref = $this->image_from( $c );
				if ( $ref !== '' ) {
					$out[] = array( 'type' => 'image', 'ref' => $ref );
				}
				return;
			case 'picture':
			case 'figure':
				$this->images_in( $c, $out );
				return;
			case 'form':
				$out[] = array( 'type' => 'form' );
				return;
			case 'iframe':
				if ( preg_match( '/google\.[a-z.]+\/maps|maps\.google/', (string) $c->getAttribute( 'src' ) ) ) {
					$out[] = array( 'type' => 'map' );
				}
				return;
			case 'hr':
			case 'table':
				return;
		}
		$this->walk( $c, $out );
	}

	/**
	 * @param array<int, array<string, mixed>|null> $out
	 */
	private function push_heading( array &$out, int $level, string $html ): void {
		if ( self::plain( $html ) !== '' ) {
			$out[] = array( 'type' => 'heading', 'level' => $level, 'html' => $html );
		}
	}

	/**
	 * @param array<int, array<string, mixed>|null> $out
	 */
	private function images_in( \DOMElement $el, array &$out ): void {
		foreach ( $this->xp->query( './/img', $el ) as $img ) {
			$ref = $this->image_from( $img );
			if ( $ref !== '' ) {
				$out[] = array( 'type' => 'image', 'ref' => $ref );
			}
		}
	}

	/** The real file behind an <img>, registered; its ref, or '' for icons, pixels and trackers. */
	private function image_from( \DOMElement $img ): string {
		$attr = static fn( string $n ) => trim( (string) $img->getAttribute( $n ) );
		$src  = $attr( 'nitro-lazy-src' ) ?: $attr( 'data-src' ) ?: $attr( 'data-lazy-src' ) ?: $attr( 'src' );
		if ( str_starts_with( $src, 'data:' ) ) {
			$src = '';
		}
		$set  = $attr( 'nitro-lazy-srcset' ) ?: $attr( 'data-srcset' ) ?: $attr( 'data-lazy-srcset' ) ?: $attr( 'srcset' );
		$best = 0;
		foreach ( preg_split( '/,\s*(?=\S+\s+\d+w)/', $set ) ?: array() as $cand ) {
			if ( preg_match( '/^(\S+)\s+(\d+)w$/', trim( $cand ), $m ) && (int) $m[2] > $best ) {
				$best = (int) $m[2];
				$src  = $m[1];
			}
		}
		$src = self::nitro_origin( $src );
		if ( $src === '' || preg_match( '/\.(svg|gif)(\?|$)/i', $src ) || preg_match( '/(pixel|tracking|trustindex|gravatar)/i', $src ) ) {
			return '';
		}
		$w = (int) $attr( 'width' );
		$h = (int) $attr( 'height' );
		if ( ( $w > 0 && $w < 80 ) || ( $h > 0 && $h < 80 ) ) {
			return ''; // an icon
		}

		return $this->image_ref( $src, $attr( 'alt' ), 'content', $w, $h );
	}

	/** The WordPress original behind a resized upload (-1024x683) and a NitroPack CDN copy. */
	private static function nitro_origin( string $src ): string {
		if ( preg_match( '#/assets/images/[^/]+/rev-[^/]+/(.+)$#', $src, $m ) ) {
			$src = 'https://' . $m[1];
		}

		return $src;
	}

	private function image_ref( string $src, string $alt, string $role, int $w = 0, int $h = 0 ): string {
		$abs = $this->absolute( self::nitro_origin( $src ) );
		if ( $abs === '' ) {
			return '';
		}
		// The full-size WordPress original when the page shows a resized copy.
		$original = preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $abs ) ?? $abs;
		$key      = $original;
		if ( ! isset( $this->image_refs[ $key ] ) ) {
			$ref                      = 'i' . ( count( $this->images ) + 1 );
			$this->image_refs[ $key ] = $ref;
			$this->images[]           = array(
				'ref'      => $ref,
				'src'      => $original,
				'fallback' => $original !== $abs ? $abs : '',
				'alt'      => $alt,
				'role'     => $role,
				'w'        => $w,
				'h'        => $h,
				'section'  => $this->section,
			);
		}

		return $this->image_refs[ $key ];
	}

	/** Background image of an element: inline style, data attributes, or a stylesheet rule for its id/class. */
	private function background_of( \DOMElement $el ): string {
		$style = (string) $el->getAttribute( 'style' );
		if ( preg_match( '/background(?:-image)?\s*:[^;]*url\(\s*[\'"]?([^\'")]+)/i', $style, $m ) ) {
			return $m[1];
		}
		foreach ( array( 'data-bg', 'data-background', 'data-bg-image', 'nitro-lazy-bg' ) as $a ) {
			$v = trim( (string) $el->getAttribute( $a ) );
			if ( $v !== '' ) {
				return preg_replace( '/^url\(\s*[\'"]?|[\'"]?\s*\)$/', '', $v ) ?? $v;
			}
		}
		$id = (string) $el->getAttribute( 'data-id' );
		if ( $id !== '' && isset( $this->bg[ 'elementor-element-' . $id ] ) ) {
			return $this->bg[ 'elementor-element-' . $id ];
		}
		foreach ( preg_split( '/\s+/', (string) $el->getAttribute( 'class' ) ) ?: array() as $c ) {
			if ( $c !== '' && isset( $this->bg[ $c ] ) && str_starts_with( $c, 'elementor-element-' ) ) {
				return $this->bg[ $c ];
			}
		}

		return '';
	}

	/**
	 * Class name => background image URL, from rules that set one (the first, the desktop one, wins).
	 *
	 * @return array<string, string>
	 */
	private static function backgrounds( string $css ): array {
		$map = array();
		if ( $css === '' ) {
			return $map;
		}
		$css = preg_replace( '#/\*.*?\*/#s', '', $css ) ?? $css;
		if ( preg_match_all( '/([^{}]+)\{([^{}]*background(?:-image)?\s*:[^;{}]*url\(\s*[\'"]?([^\'")]+)[^{}]*)\}/i', $css, $rules, PREG_SET_ORDER ) ) {
			foreach ( $rules as $r ) {
				if ( preg_match_all( '/\.(elementor-element-[a-z0-9]+)/i', $r[1], $cls ) ) {
					foreach ( $cls[1] as $c ) {
						$map[ $c ] = $map[ $c ] ?? $r[3];
					}
				}
			}
		}

		return $map;
	}

	/** Inline HTML of an element: text with strong/em/links/br kept. */
	private function inline_html( ?\DOMNode $el ): string {
		if ( ! $el instanceof \DOMNode ) {
			return '';
		}
		$html = '';
		foreach ( $el->childNodes as $child ) {
			$html .= $this->inline_html_node( $child );
		}

		return self::tidy( $html );
	}

	private function inline_html_node( \DOMNode $n ): string {
		if ( $n instanceof \DOMText ) {
			return esc_html( $n->textContent );
		}
		if ( ! $n instanceof \DOMElement ) {
			return '';
		}
		$tag = strtolower( $n->nodeName );
		if ( in_array( $tag, self::SKIP, true ) || strtolower( (string) $n->getAttribute( 'aria-hidden' ) ) === 'true' || $this->ignored( $n ) ) {
			return '';
		}
		if ( $tag === 'br' ) {
			return '<br>';
		}
		// Cloudflare's hidden e-mail address ("[email protected]" until its script runs): the address itself.
		$cf = (string) $n->getAttribute( 'data-cfemail' );
		if ( $cf !== '' && preg_match( '/^[0-9a-f]+$/i', $cf ) ) {
			$email = self::cf_email( $cf );
			if ( is_email( $email ) ) {
				return '<a href="' . esc_attr( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>';
			}
		}
		$inner = '';
		foreach ( $n->childNodes as $c ) {
			$inner .= $this->inline_html_node( $c );
		}
		if ( $tag === 'a' ) {
			$href = $this->href( (string) $n->getAttribute( 'href' ) );
			return $href !== '' && self::plain( $inner ) !== '' ? '<a href="' . esc_attr( $href ) . '">' . $inner . '</a>' : $inner;
		}
		if ( isset( self::INLINE[ $tag ] ) ) {
			return self::plain( $inner ) !== '' ? '<' . self::INLINE[ $tag ] . '>' . $inner . '</' . self::INLINE[ $tag ] . '>' : $inner;
		}
		if ( in_array( $tag, array( 'p', 'div', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
			return ' ' . $inner . ' ';
		}

		return $inner;
	}

	private function href( string $href ): string {
		$href = trim( $href );
		if ( $href === '' || str_starts_with( $href, 'javascript:' ) ) {
			return '';
		}
		if ( preg_match( '/^(tel|mailto|sms):/i', $href ) || str_starts_with( $href, '#' ) ) {
			return $href;
		}
		if ( str_contains( $href, '/cdn-cgi/l/email-protection#' ) ) {
			return 'mailto:' . self::cf_email( (string) substr( $href, (int) strpos( $href, '#' ) + 1 ) );
		}

		return $this->absolute( $href );
	}

	/** Cloudflare's obfuscated e-mail addresses. */
	private static function cf_email( string $hex ): string {
		if ( strlen( $hex ) < 4 ) {
			return '';
		}
		$key = hexdec( substr( $hex, 0, 2 ) );
		$out = '';
		for ( $i = 2, $n = strlen( $hex ); $i < $n; $i += 2 ) {
			$out .= chr( hexdec( substr( $hex, $i, 2 ) ) ^ $key );
		}

		return $out;
	}

	private function absolute( string $url ): string {
		$url = html_entity_decode( trim( $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( $url === '' || str_starts_with( $url, 'data:' ) ) {
			return '';
		}
		if ( str_starts_with( $url, '//' ) ) {
			return 'https:' . $url;
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}

		return \WP_Http::make_absolute_url( $url, $this->base );
	}

	private function first_text( string $query ): ?string {
		$n = $this->xp->query( $query )->item( 0 );

		return $n ? (string) $n->textContent : null;
	}

	private function attr( string $query, string $name ): ?string {
		$n = $this->xp->query( $query )->item( 0 );

		return $n instanceof \DOMElement ? (string) $n->getAttribute( $name ) : null;
	}

	private function meta( string $name ): ?string {
		return $this->attr( '//meta[@name="' . $name . '"]', 'content' );
	}

	private function meta_property( string $name ): ?string {
		return $this->attr( '//meta[@property="' . $name . '"]', 'content' );
	}

	public static function clean( string $text ): string {
		return trim( preg_replace( '/[\s\x{00a0}\x{200b}\x{fffc}]+/u', ' ', $text ) ?? $text );
	}

	/** Inline HTML with whitespace collapsed, and no empty emphasis or leading/trailing breaks. */
	private static function tidy( string $html ): string {
		$html = preg_replace( '/[\s\x{00a0}\x{200b}\x{fffc}]+/u', ' ', $html ) ?? $html;
		$html = preg_replace( '/\s*<br>\s*/', '<br>', $html ) ?? $html;
		$html = preg_replace( '/^(<br>)+|(<br>)+$/', '', trim( $html ) ) ?? $html;
		$html = preg_replace( '#<(strong|em)>\s*</\1>#', '', $html ) ?? $html;

		return trim( $html );
	}

	/** Visible text of inline HTML. */
	public static function plain( string $html ): string {
		return self::clean( html_entity_decode( wp_strip_all_tags( str_replace( '<br>', ' ', $html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}

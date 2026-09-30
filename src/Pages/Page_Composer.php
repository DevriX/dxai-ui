<?php
/**
 * A page of the imported design, composed from an old page's content.
 *
 * The old page (Content_Extractor) is read as units of meaning — the hero,
 * a text block, a set of cards, questions, reviews, figures, a list, a call
 * to action, contact details, a form — in the order the visitor met them.
 * Each unit is placed in the design section (Section_Library) best made for
 * it, and the section is filled with the unit's own words and images: its
 * repeated items are copied or removed to the unit's count, text slots are
 * duplicated or removed to fit, links point where the old ones did.
 *
 * Rules the result always keeps:
 *  - the blocks, classes and CSS are the design's; nothing is restyled;
 *  - every word and image on the page comes from the old page — a design
 *    element the unit has nothing for is removed, never left with the Home's
 *    text or picture (a final sweep enforces it);
 *  - images are cropped to the design slot's shape, so layouts do not shift.
 *
 * Software decides; nothing here calls an AI model.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

final class Page_Composer {

	/** Which design section kinds can show a unit kind, best first. */
	private const FITS = array(
		'hero'         => array( 'hero' ),
		'text'         => array( 'text', 'cta', 'contact' ),
		'cards'        => array( 'cards', 'steps' ),
		'faq'          => array( 'faq' ),
		'testimonials' => array( 'testimonials' ),
		'stats'        => array( 'stats' ),
		'logos'        => array( 'logos' ),
		'list'         => array( 'text' ),
		'contact'      => array( 'contact', 'text' ),
		'cta'          => array( 'cta', 'text' ),
		'form'         => array( 'form' ),
	);

	/** A link inside the old page's text: the text's own colour, underlined (inline_links()). */
	private const LINK_CSS = 'color:inherit;text-decoration:underline;text-underline-offset:.15em';

	/** Blocks whose inline runs can carry CSS of their own (Style_Hoister::CONTENT_BLOCKS). */
	private const INLINE_CSS_BLOCKS = array( 'core/paragraph', 'core/heading', 'core/list-item', 'dxai-ui/text' );

	/** The space below a picture added at the top of a card that had no icon there. */
	private const CARD_IMAGE_GAP = '18px';

	/** @var array<int, array<string, mixed>> */
	private array $library;
	/** @var callable(string, float, bool): ?array */
	private $images;
	/** @var array<string, array<string, mixed>> Old images by ref. */
	private array $pool = array();
	/** @var array<string, true> */
	private array $used_images = array();
	/** @var array<string, true> Pictures a later unit of the page will show itself. */
	private array $reserved = array();
	/** @var array<int, int> Component index => times used. */
	private array $used = array();
	/** @var array<string, true> Normalized texts this page may show. */
	private array $allowed = array();
	/** @var array<int, string> */
	private array $log = array();
	/** Whether the cards being filled show their old pictures in their icon's place (fill()). */
	private bool $card_images = false;
	/** The design's stylesheet (the Home's), read for the column counts its responsive grids are made for. */
	private string $design_css;
	/** @var array<string, mixed>|null|false The library's plainest picture block, once looked up (false: none). */
	private $picture = null;
	/** The width the design keeps its running text to (a paragraph's max-width), once looked up. */
	private ?string $measure = null;

	/**
	 * @param array<int, array<string, mixed>> $library    Section_Library::for_page().
	 * @param callable                         $images     fn( string $src, float $aspect, bool $crop ): ?array{url:string, alt?:string, id?:int}
	 * @param string                           $design_css The Home's stylesheet, when there is one.
	 */
	public function __construct( array $library, callable $images, string $design_css = '' ) {
		$this->library    = $library;
		$this->images     = $images;
		$this->design_css = $design_css;
	}

	/**
	 * @param array<string, mixed> $model Content_Extractor::extract().
	 * @return array{sections: array<int, array<string, mixed>>, units: array<int, array<string, mixed>>, log: array<int, string>}
	 */
	public function compose( array $model ): array {
		foreach ( (array) $model['images'] as $img ) {
			$this->pool[ (string) $img['ref'] ] = $img;
		}
		$units    = self::without_self_links( self::units( $model ), (string) ( $model['url'] ?? '' ) );
		$sections = array();
		foreach ( $units as $n => $unit ) {
			$this->reserved = array();
			foreach ( array_slice( $units, $n + 1 ) as $later ) {
				foreach ( self::unit_images( $later ) as $ref ) {
					$this->reserved[ $ref ] = true;
				}
			}
			$target = $this->choose( $unit, $n === 0 );
			if ( $target === null ) {
				$this->log[] = sprintf( 'unit %d (%s "%s"): no design section fits — left out', $n, $unit['kind'], mb_substr( Content_Extractor::plain( (string) $unit['heading'] ), 0, 40 ) );
				continue;
			}
			try {
				$block = $this->fill( $target['component'], $target['panel'], $unit );
			} catch ( \Throwable $e ) {
				$this->log[] = sprintf( 'unit %d (%s) into #%d failed: %s — left out', $n, $unit['kind'], $target['component']['index'], $e->getMessage() );
				continue;
			}
			if ( $block !== null ) {
				$sections[]  = $block;
				$this->log[] = sprintf( 'unit %d %-12s → #%d %s%s', $n, $unit['kind'], $target['component']['index'], $target['component']['kind'], $target['component']['anchor'] !== '' ? ' #' . $target['component']['anchor'] : '' );
			}
		}

		// The tail in the team's order (related services, questions, the call to action last), whatever order the old page had.
		$composed = $sections;
		$sections = Page_Order::arrange( $sections );
		if ( $sections !== $composed ) {
			$this->log[] = 'sections: tail put in the team\'s order';
		}

		return array(
			'sections' => $sections,
			'units'    => $units,
			'log'      => $this->log,
		);
	}

	/* ------------------------------------------------------------------ units */

	/**
	 * The page as units of meaning, in reading order.
	 *
	 * @param array<string, mixed> $model
	 * @return array<int, array<string, mixed>>
	 */
	public static function units( array $model ): array {
		$units = array();
		foreach ( (array) $model['sections'] as $s ) {
			$chunk = array();
			$bg    = (string) ( $s['background'] ?? '' );
			foreach ( self::normalize( (array) $s['blocks'] ) as $b ) {
				if ( ( $b['type'] ?? '' ) === 'heading' && (int) $b['level'] <= 2 && $chunk !== array() && self::has_heading( $chunk ) ) {
					array_push( $units, ...self::unit( $chunk, $bg ) );
					$chunk = array();
					$bg    = '';
				}
				$chunk[] = $b;
			}
			if ( $chunk !== array() ) {
				array_push( $units, ...self::unit( $chunk, $bg ) );
			}
		}
		$units = array_values( array_filter( $units ) );
		if ( $units !== array() && (int) $units[0]['level'] === 1 ) {
			$units[0]['kind'] = 'hero';
		}

		$out     = array();
		$pending = array(); // headings with nothing under them: labels of what follows
		foreach ( $units as $u ) {
			if ( $u['kind'] === 'label' ) {
				$pending[] = $u['heading'];
				continue;
			}
			if ( $pending !== array() && $u['kind'] !== 'hero' ) {
				$first = array_shift( $pending );
				if ( $u['eyebrow'] === '' && mb_strlen( Content_Extractor::plain( $first ) ) <= 40 ) {
					$u['eyebrow'] = $first;
				} else {
					array_unshift( $pending, $first );
				}
				// Further labels read as a lead line above the text.
				foreach ( array_reverse( $pending ) as $label ) {
					array_unshift( $u['paras'], '<strong>' . $label . '</strong>' );
				}
				$pending = array();
			}
			$n    = count( $out );
			$prev = $n ? $out[ $n - 1 ] : null;
			// A set of cards without a heading completes the short text (heading + intro) just before it — or
			// the one before that, when a call to action sits between them.
			if ( $u['kind'] === 'cards' && $u['heading'] === '' ) {
				foreach ( array( 1, 2 ) as $back ) {
					$host = $out[ $n - $back ] ?? null;
					if ( $host === null || ( $back === 2 && ( $prev['kind'] ?? '' ) !== 'cta' ) ) {
						break;
					}
					if ( $host['kind'] === 'text' && ! $host['images'] && ! $host['list'] && count( $host['paras'] ) <= 2 ) {
						$u['heading'] = $host['heading'];
						$u['level']   = $host['level'];
						$u['eyebrow'] = $host['eyebrow'];
						$u['paras']   = $host['paras'];
						$out[ $n - $back ] = $u;
						continue 2;
					}
				}
			}
			// Pictures on their own belong to the unit before them.
			if ( $prev && $u['kind'] === 'images' ) {
				$out[ $n - 1 ]['images'] = array_merge( $prev['images'], $u['images'] );
				continue;
			}
			// A heading with only a picture under it (a tagline beside a photo) completes the short text just before
			// it: one section of text and photo, as the old page showed them, not a thin band of its own.
			if ( $prev && $u['kind'] === 'text' && $u['heading'] !== '' && $u['paras'] === array() && $u['list'] === array() && $u['buttons'] === array() && $u['items'] === array() && $u['images'] !== array() && $u['bg'] === ''
				&& $prev['kind'] === 'text' && $prev['images'] === array() && $prev['bg'] === '' && $prev['items'] === array() && count( $prev['paras'] ) <= 3 ) {
				$out[ $n - 1 ]['paras'][] = '<strong>' . $u['heading'] . '</strong>';
				$out[ $n - 1 ]['images']  = $u['images'];
				continue;
			}
			$out[] = $u;
		}
		foreach ( $pending as $label ) {
			$out[] = array_merge( self::blank(), array( 'kind' => 'text', 'heading' => $label, 'level' => 2 ) );
		}
		$out = self::directories( array_values( array_filter( $out, static fn( $u ) => $u['kind'] !== 'images' ) ) );

		// A list with no heading of its own (an index of towns) right after a short text is that text's list: one
		// section, the heading and its intro over the items, not an intro band and a headless list band.
		$joined = array();
		foreach ( $out as $u ) {
			$n    = count( $joined );
			$prev = $n ? $joined[ $n - 1 ] : null;
			if ( $prev && $u['kind'] === 'list' && $u['heading'] === '' && $u['eyebrow'] === '' && $u['paras'] === array() && $u['images'] === array()
				&& $prev['kind'] === 'text' && $prev['list'] === array() && $prev['items'] === array() && $prev['images'] === array() && $prev['bg'] === '' && count( $prev['paras'] ) <= 2 ) {
				$joined[ $n - 1 ]['list']    = $u['list'];
				$joined[ $n - 1 ]['ordered'] = $u['ordered'];
				$joined[ $n - 1 ]['kind']    = 'list';
				continue;
			}
			$joined[] = $u;
		}

		return $joined;
	}

	/**
	 * A set of cards of other pages (related services) leaves out the card of this very page: on the old site it
	 * was a widget listing every service, and here it repeated the page's own opening and linked back to itself.
	 * Only from a set of three or more, so a set never ends up empty.
	 *
	 * @param array<int, array<string, mixed>> $units
	 * @return array<int, array<string, mixed>>
	 */
	private static function without_self_links( array $units, string $page_url ): array {
		$norm = static function ( string $url ): string {
			$host = strtolower( (string) preg_replace( '/^www\./i', '', (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
			$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
			return $host === '' ? '' : $host . '/' . $path;
		};
		$self = $norm( $page_url );
		if ( $self === '' ) {
			return $units;
		}
		foreach ( $units as &$u ) {
			if ( count( $u['items'] ) < 3 ) {
				continue;
			}
			$kept = array_values( array_filter( $u['items'], static fn( $i ) => ( $i['href'] ?? '' ) === '' || $norm( (string) $i['href'] ) !== $self ) );
			if ( count( $kept ) >= 2 ) {
				$u['items'] = $kept;
			}
		}
		unset( $u );

		return $units;
	}

	/**
	 * Three or more small units in a row that are entries of one index (an A–Z of towns: a letter and a
	 * link each, or a short name and a line) are one list of those entries.
	 *
	 * @param array<int, array<string, mixed>> $units
	 * @return array<int, array<string, mixed>>
	 */
	private static function directories( array $units ): array {
		$small = static function ( array $u ): bool {
			if ( ! in_array( $u['kind'], array( 'cta', 'text', 'label', 'list' ), true ) || $u['items'] || $u['images'] ) {
				return false;
			}
			$head  = Content_Extractor::plain( (string) $u['heading'] );
			$words = str_word_count( Content_Extractor::plain( implode( ' ', $u['paras'] ) ) );
			if ( $u['kind'] === 'list' ) {
				return $head === '' || mb_strlen( $head ) <= 3; // a run of an index's entries
			}
			if ( mb_strlen( $head ) <= 3 && $head !== '' ) {
				return $words <= 12; // a letter of an A–Z, however many links under it
			}

			return count( $u['buttons'] ) <= 2 && mb_strlen( $head ) <= 25 && $words <= 12 && ( $u['buttons'] || $words > 0 );
		};
		$out = array();
		$run = array();
		$flush = static function ( string $tail = '' ) use ( &$run, &$out ): bool {
			// An index: letters as headings, or every entry just a name and its link.
			$letters = count( array_filter( $run, static fn( $u ) => Content_Extractor::plain( (string) $u['heading'] ) !== '' && mb_strlen( Content_Extractor::plain( (string) $u['heading'] ) ) <= 3 ) );
			$links   = count( array_filter( $run, static fn( $u ) => $u['buttons'] && ! $u['paras'] ) );
			$index   = count( $run ) >= 3 && ( $letters >= 2 || $links === count( $run ) );
			if ( $index ) {
				$entries = array();
				foreach ( $run as $u ) {
					// An entry with no link of its own (a plain town name) was read as the label of the next one.
					if ( mb_strlen( Content_Extractor::plain( (string) $u['eyebrow'] ) ) > 3 ) {
						$entries[] = (string) $u['eyebrow'];
					}
					// A plain name heading the unit is an entry too, before the links that follow it — unless it is
					// one of them.
					$head   = Content_Extractor::plain( (string) $u['heading'] );
					$labels = array_map( static fn( $b ) => mb_strtolower( (string) $b['label'] ), $u['buttons'] );
					if ( mb_strlen( $head ) > 3 && ! in_array( mb_strtolower( $head ), $labels, true ) ) {
						$entries[] = (string) $u['heading'];
					}
					foreach ( $u['buttons'] as $b ) {
						$entries[] = $b['href'] !== '' && $b['href'] !== '#' ? '<a href="' . esc_attr( $b['href'] ) . '">' . esc_html( $b['label'] ) . '</a>' : esc_html( $b['label'] );
					}
					foreach ( $u['paras'] as $p ) {
						if ( mb_strlen( Content_Extractor::plain( (string) $p ) ) > 1 ) {
							$entries[] = (string) $p;
						}
					}
					foreach ( $u['list'] as $li ) {
						$entries[] = (string) $li;
					}
				}
				if ( $tail !== '' ) {
					$entries[] = $tail;
				}
				$out[] = array_merge( self::blank(), array( 'kind' => 'list', 'list' => $entries ) );
			} else {
				array_push( $out, ...$run );
			}
			$run = array();

			return $index && $tail !== '';
		};
		foreach ( $units as $u ) {
			if ( $small( $u ) ) {
				$run[] = $u;
				continue;
			}
			// The index's last entry, a name with no link, was read as the label of the section after it.
			$tail = $run !== array() && mb_strlen( Content_Extractor::plain( (string) $u['eyebrow'] ) ) > 3 && mb_strlen( Content_Extractor::plain( (string) $u['eyebrow'] ) ) <= 25 ? (string) $u['eyebrow'] : '';
			if ( $flush( $tail ) ) {
				$u['eyebrow'] = '';
			}
			$out[] = $u;
		}
		$flush();

		return $out;
	}

	/**
	 * Blocks made plain: a heading that is only a link is a button (short) or a paragraph (long); groups of
	 * short label/value boxes (Address / Call Us / Email) under a sub-heading are the fields of one item.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $b ) {
			if ( ( $b['type'] ?? '' ) === 'heading' && preg_match( '#^\s*<a href="([^"]+)">(.*)</a>\s*$#s', (string) $b['html'], $m ) ) {
				$label = Content_Extractor::plain( $m[2] );
				$out[] = mb_strlen( $label ) <= 40
					? array( 'type' => 'button', 'label' => $label, 'href' => html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) )
					: array( 'type' => 'paragraph', 'html' => (string) $b['html'] );
				continue;
			}
			if ( ( $b['type'] ?? '' ) === 'box' && mb_strlen( Content_Extractor::plain( (string) $b['title'] ) ) <= 15 && Content_Extractor::plain( (string) $b['html'] ) !== '' ) {
				$out[] = array( 'type' => 'field', 'label' => Content_Extractor::plain( (string) $b['title'] ), 'html' => (string) $b['html'], 'href' => (string) $b['href'] );
				continue;
			}
			$out[] = $b;
		}

		return $out;
	}

	/** @return array<string, mixed> */
	private static function blank(): array {
		return array(
			'kind'    => 'text',
			'heading' => '',
			'level'   => 0,
			'eyebrow' => '',
			'paras'   => array(),
			'list'    => array(),
			'ordered' => false,
			'buttons' => array(),
			'images'  => array(),
			'bg'      => '',
			'items'   => array(),
			'faq'     => array(),
			'reviews' => array(),
			'stats'   => array(),
			'form'    => false,
			'map'     => false,
		);
	}

	/**
	 * Every picture a unit shows itself.
	 *
	 * @param array<string, mixed> $u
	 * @return array<int, string>
	 */
	private static function unit_images( array $u ): array {
		$refs = array_merge( $u['bg'] !== '' ? array( $u['bg'] ) : array(), $u['images'] );
		foreach ( $u['items'] as $item ) {
			if ( ( $item['image'] ?? '' ) !== '' ) {
				$refs[] = (string) $item['image'];
			}
		}

		return array_values( array_unique( array_filter( $refs ) ) );
	}

	/** @param array<int, array<string, mixed>> $blocks */
	private static function has_heading( array $blocks ): bool {
		foreach ( $blocks as $b ) {
			if ( ( $b['type'] ?? '' ) === 'heading' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The unit(s) of a run of blocks that starts at a heading (or at the section start). A text with a long
	 * list is two units: the text, and the list.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	private static function unit( array $blocks, string $bg ): array {
		$u         = self::blank();
		$u['bg']   = $bg;
		$item      = null;
		$subs      = count( array_filter( $blocks, static fn( $b ) => ( $b['type'] ?? '' ) === 'heading' && (int) $b['level'] >= 3 ) );
		$first     = current( array_filter( $blocks, static fn( $b ) => ( $b['type'] ?? '' ) === 'heading' ) );
		$titleless = $first && (int) $first['level'] >= 3 && $subs >= 2;
		$new_item  = static fn( string $heading ) => array( 'heading' => $heading, 'paras' => array(), 'list' => array(), 'image' => '', 'href' => '', 'label' => '', 'fields' => array() );
		foreach ( $blocks as $b ) {
			switch ( $b['type'] ?? '' ) {
				case 'heading':
					if ( ! $titleless && $u['heading'] === '' && $item === null && ( (int) $b['level'] <= 2 || $u['paras'] === array() ) ) {
						$u['heading'] = (string) $b['html'];
						$u['level']   = (int) $b['level'];
						break;
					}
					if ( $item !== null ) {
						$u['items'][] = $item;
					}
					$item = $new_item( (string) $b['html'] );
					break;
				case 'paragraph':
					if ( $item !== null ) {
						$item['paras'][] = (string) $b['html'];
					} else {
						$u['paras'][] = (string) $b['html'];
					}
					break;
				case 'list':
					if ( $item !== null ) {
						$item['list'] = array_merge( $item['list'], array_map( 'strval', $b['items'] ) );
					} elseif ( $u['list'] === array() ) {
						$u['list']    = array_map( 'strval', $b['items'] );
						$u['ordered'] = (bool) $b['ordered'];
					} else {
						$u['list'] = array_merge( $u['list'], array_map( 'strval', $b['items'] ) );
					}
					break;
				case 'field':
					if ( $item === null ) {
						$item = $new_item( '' );
					}
					$item['fields'][] = array( 'label' => (string) $b['label'], 'html' => (string) $b['html'], 'href' => (string) $b['href'] );
					break;
				case 'button':
					if ( $b['label'] !== '' ) {
						$u['buttons'][] = array( 'label' => (string) $b['label'], 'href' => (string) $b['href'] );
					}
					break;
				case 'image':
					if ( $item !== null && $item['image'] === '' ) {
						$item['image'] = (string) $b['ref'];
					} else {
						$u['images'][] = (string) $b['ref'];
					}
					break;
				case 'bg':
					if ( $u['bg'] === '' ) {
						$u['bg'] = (string) $b['ref'];
					}
					break;
				case 'box':
					if ( $item !== null ) {
						$u['items'][] = $item;
						$item         = null;
					}
					$u['items'][] = array_merge(
						$new_item( esc_html( (string) $b['title'] ) ),
						array(
							'paras' => $b['html'] !== '' ? array( (string) $b['html'] ) : array(),
							'image' => (string) $b['image'],
							'href'  => (string) $b['href'],
							'label' => esc_html( (string) ( $b['label'] ?? '' ) ),
						)
					);
					break;
				case 'faq':
					$u['faq'][] = array( 'q' => (string) $b['q'], 'a' => implode( ' ', array_map( static fn( $p ) => Content_Extractor::plain( (string) ( $p['html'] ?? '' ) ), (array) $b['a'] ) ) );
					break;
				case 'review':
					$u['reviews'][] = array( 'quote' => (string) $b['quote'], 'name' => (string) $b['name'], 'rating' => (int) ( $b['rating'] ?? 5 ) );
					break;
				case 'stat':
					$u['stats'][] = array( 'value' => (string) $b['value'], 'label' => (string) $b['label'] );
					break;
				case 'form':
					$u['form'] = true;
					break;
				case 'map':
					$u['map'] = true;
					break;
			}
		}
		if ( $item !== null ) {
			$u['items'][] = $item;
		}
		// A single sub-heading is part of the text, not a set of cards: its heading a lead line, its list the unit's.
		if ( count( $u['items'] ) === 1 && $u['items'][0]['href'] === '' && $u['items'][0]['fields'] === array() ) {
			$one        = $u['items'][0];
			$u['items'] = array();
			if ( $one['list'] !== array() && $u['list'] === array() ) {
				$u['paras'] = array_merge( $u['paras'], $one['paras'] );
				$u['kind']  = self::kind_of( $u );
				$list       = array_merge( self::blank(), array( 'kind' => 'list', 'heading' => $one['heading'], 'level' => 3, 'list' => $one['list'] ) );
				return $u['heading'] === '' && $u['paras'] === array() ? array( $list ) : array( $u, $list );
			}
			$u['paras'] = array_merge( $u['paras'], $one['heading'] !== '' ? array( '<strong>' . $one['heading'] . '</strong>' ) : array(), $one['paras'] );
			if ( $one['image'] !== '' ) {
				$u['images'][] = $one['image'];
			}
		}
		$u['kind'] = self::kind_of( $u );
		if ( $u['kind'] === 'empty' ) {
			return array();
		}
		// The old page's form in a unit that is something else too (offices and their form under one heading):
		// the form becomes a unit of its own right after it, so it goes where the design has its form.
		if ( $u['form'] && $u['kind'] !== 'form' ) {
			$u['form'] = false;
			$form      = array_merge( self::blank(), array( 'kind' => 'form', 'form' => true ) );
			$rest      = $u['kind'] === 'text' && count( $u['list'] ) >= 3 && $u['paras'] !== array() ? self::split_list( $u ) : array( $u );
			return array_merge( $rest, array( $form ) );
		}
		// Text followed by a long list. A short intro stays with its list — one section, the heading and its text
		// over the items, as the design shows a list; a longer text and its list are two sections.
		if ( $u['kind'] === 'text' && count( $u['list'] ) >= 3 && $u['paras'] !== array() ) {
			if ( count( $u['paras'] ) <= 2 && str_word_count( Content_Extractor::plain( implode( ' ', $u['paras'] ) ) ) <= 80 ) {
				$u['kind'] = 'list';
				return array( $u );
			}
			return self::split_list( $u );
		}

		return array( $u );
	}

	/**
	 * A text unit with a long list, as two units: the text, then the list.
	 *
	 * @param array<string, mixed> $u
	 * @return array<int, array<string, mixed>>
	 */
	private static function split_list( array $u ): array {
		$list      = array_merge( self::blank(), array( 'kind' => 'list', 'heading' => '', 'level' => 3, 'list' => $u['list'] ) );
		$u['list'] = array();

		return array( $u, $list );
	}

	/** @param array<string, mixed> $u */
	private static function kind_of( array $u ): string {
		$words = str_word_count( Content_Extractor::plain( implode( ' ', $u['paras'] ) ) );
		if ( $u['faq'] ) {
			return 'faq';
		}
		if ( $u['reviews'] ) {
			return 'testimonials';
		}
		if ( count( $u['stats'] ) >= 2 ) {
			return 'stats';
		}
		if ( count( $u['items'] ) >= 2 ) {
			$fields = count( array_filter( $u['items'], static fn( $i ) => $i['fields'] !== array() ) );
			return $fields >= 2 ? 'contact' : 'cards';
		}
		if ( $u['form'] ) {
			return 'form';
		}
		if ( preg_match( '/\b\d{2,6}\s+[A-Z][\w.]*(\s+\w+){0,4}\s+(Ave|Avenue|St|Street|Rd|Road|Ln|Lane|Blvd|Boulevard|Dr|Drive|Way|Hwy|Pike|Ct|Court)\b/', Content_Extractor::plain( implode( ' ', $u['paras'] ) ) ) && $words <= 40 ) {
			return 'contact';
		}
		if ( count( $u['list'] ) >= 3 && $u['paras'] === array() ) {
			return 'list';
		}
		if ( $u['heading'] !== '' && $u['paras'] === array() && $u['list'] === array() && $u['images'] === array() && $u['buttons'] === array() && $u['items'] === array() ) {
			return 'label';
		}
		if ( $u['heading'] === '' && $u['paras'] === array() && $u['list'] === array() ) {
			return $u['images'] || $u['bg'] ? 'images' : ( $u['buttons'] ? 'cta' : 'empty' );
		}
		if ( $u['buttons'] && $words <= 60 && ! $u['images'] ) {
			return 'cta';
		}

		return 'text';
	}

	/* ---------------------------------------------------------------- choose */

	/**
	 * The design section (and its panel) that best shows a unit.
	 *
	 * @param array<string, mixed> $unit
	 * @return array{component: array<string, mixed>, panel: int}|null
	 */
	private function choose( array $unit, bool $first ): ?array {
		$fits = self::FITS[ $unit['kind'] ] ?? array( 'text' );
		$best = null;
		$top  = -INF;
		foreach ( $this->library as $c ) {
			$rank = array_search( $c['kind'], $fits, true );
			if ( $rank === false ) {
				continue;
			}
			$score = 10 - 3 * (int) $rank;
			$score -= 4 * ( $this->used[ $c['index'] ] ?? 0 );
			$panel  = 0;
			if ( $unit['kind'] === 'list' ) {
				$panel = self::panel_with_list( $c );
				if ( $panel < 0 && $c['kind'] !== 'steps' ) {
					continue;
				}
				$panel = max( 0, $panel );
			}
			$slots = self::image_slots( $c );
			// The unit's pictures, its items' included: a set of cards with photos wants a section that shows photos.
			$have  = count( self::unit_images( $unit ) );
			if ( $slots > 0 && $have === 0 ) {
				$score -= 2;
			}
			// Pictures of the old page with no place to show them are lost: a section that shows none of them
			// (no picture of its own, no photo behind it, no card picture) costs more than one with a place.
			if ( $slots === 0 && $have > 0 && $unit['kind'] !== 'cta' ) {
				$shows = self::has_photo_backdrop( $c );
				foreach ( $c['repeats'] as $r ) {
					$shows = $shows || ( $unit['items'] !== array() && ( self::shows_picture( $r['shape'] ) || self::leading_icon( $r['shape'] ) ) );
				}
				$score -= $shows ? 1 : 4;
			}
			// A section that was a photo band on the old site goes where the design has a picture behind the text.
			if ( $unit['bg'] !== '' ) {
				$score += self::has_backdrop( $c ) ? 4 : -1;
			}
			$items = count( $unit['items'] );
			$rep   = self::main_repeat( $c, $unit['kind'] );
			if ( $items > 0 && $rep !== null ) {
				$linked = count( array_filter( $unit['items'], static fn( $i ) => $i['href'] !== '' ) ) > 0;
				$score += ( $rep['shape']['root'] === 'card' ) === $linked ? 2 : -2;
				$score -= abs( count( $rep['items'] ) - $items ) > 3 ? 1 : 0;
			}
			// Text with nowhere to go is lost: a section without a text slot costs, less when one can be added
			// in the style of its own item text (add_text_slot()).
			if ( $unit['paras'] && ! self::has_text_slot( $c, $panel ) ) {
				$score -= self::text_repeat( $c, $unit['kind'] ) !== null ? 1 : 5;
			}
			// Cards that all have a photo, in a section whose cards can show one (an image, or an icon at the top).
			if ( $items > 0 && $rep !== null && count( array_filter( $unit['items'], static fn( $i ) => ( $i['image'] ?? '' ) !== '' ) ) === $items ) {
				$score += self::shows_picture( $rep['shape'] ) || self::leading_icon( $rep['shape'] ) ? 2 : 0;
			}
			if ( $c['kind'] === 'cta' && str_word_count( Content_Extractor::plain( implode( ' ', $unit['paras'] ) ) ) > 45 ) {
				$score -= 6;
			}
			if ( $score > $top ) {
				$top  = $score;
				$best = array( 'component' => $c, 'panel' => $panel );
			}
		}
		if ( $best !== null ) {
			$this->used[ $best['component']['index'] ] = ( $this->used[ $best['component']['index'] ] ?? 0 ) + 1;
		}

		return $best;
	}

	/** @param array<string, mixed> $c */
	private static function panel_with_list( array $c ): int {
		foreach ( $c['panels'] as $i => $p ) {
			if ( $p['lists'] ) {
				return (int) $i;
			}
		}

		return -1;
	}

	/** @param array<string, mixed> $c */
	private static function has_text_slot( array $c, int $panel ): bool {
		return isset( $c['panels'][ $panel ] ) && count( $c['panels'][ $panel ]['texts'] ) > 0;
	}

	/** @param array<string, mixed> $c Whether the section has a photo behind all of its content (is_photo_backdrop()). */
	private static function has_photo_backdrop( array $c ): bool {
		foreach ( $c['images'] as $s ) {
			$node = Block_Tree::at( $c['block'], $s['path'] );
			if ( self::is_backdrop( $node, (bool) $s['decor'] ) && self::is_photo_backdrop( $node ) ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string, mixed> $c Whether the section has a picture behind its content. */
	private static function has_backdrop( array $c ): bool {
		foreach ( $c['images'] as $s ) {
			if ( self::is_backdrop( Block_Tree::at( $c['block'], $s['path'] ), (bool) $s['decor'] ) ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string, mixed> $c */
	private static function image_slots( array $c ): int {
		$n = count( array_filter( $c['images'], static fn( $s ) => ! $s['decor'] ) );
		foreach ( $c['repeats'] as $r ) {
			if ( in_array( $r['kind'], array( 'gallery', 'logo' ), true ) ) {
				$n += count( $r['items'] );
			}
		}

		return $n;
	}

	/**
	 * The repeated group that holds a unit's items.
	 *
	 * @param array<string, mixed> $c
	 * @return array<string, mixed>|null
	 */
	private static function main_repeat( array $c, string $unit_kind ): ?array {
		$want = array(
			'cards'        => array( 'card', 'step' ),
			'faq'          => array( 'faq' ),
			'testimonials' => array( 'testimonial' ),
			'stats'        => array( 'stat' ),
			'logos'        => array( 'logo', 'gallery' ),
			'contact'      => array( 'contact' ),
			'list'         => array( 'row' ),
			'hero'         => array( 'row' ),
		)[ $unit_kind ] ?? array();
		foreach ( $c['repeats'] as $r ) {
			if ( in_array( $r['kind'], $want, true ) ) {
				return $r;
			}
		}

		return null;
	}

	/* ------------------------------------------------------------------ fill */

	/**
	 * The component's block filled with the unit, or null when nothing of it can show the unit.
	 *
	 * @param array<string, mixed> $c
	 * @param array<string, mixed> $unit
	 * @return array<string, mixed>|null
	 */
	private function fill( array $c, int $panel, array $unit ): ?array {
		$block = $c['block'];
		if ( ( $this->used[ $c['index'] ] ?? 1 ) > 1 ) {
			Block_Tree::strip_anchors( $block );
		}
		// The boxes that are empty in the design itself (overlays, rules, accent shapes) — they stay; boxes the
		// removals below leave empty do not (is_drawing()).
		self::mark_drawings( $block );

		// 0. Every design copy of the item the unit's items go in — also those in "show more" boxes and the
		//    breakpoint-only duplicates the next step removes — so each item can take the copy that fits it.
		$templates = self::templates( $c, $unit['kind'] );

		// 1. The design's machinery that has no content of its own: "show more" boxes, their toggles, breakpoint duplicates.
		$block = $this->prune( $block, static fn( $b ) => str_contains( (string) ( $b['attrs']['className'] ?? '' ), 'dxai-on-' )
			|| str_contains( (string) ( $b['attrs']['className'] ?? '' ), 'dxai-toggle-' )
			|| ( ( $b['blockName'] ?? '' ) === 'dxai-ui/box' && strtolower( (string) ( $b['attrs']['tagName'] ?? '' ) ) === 'button' )
			|| array_intersect( array( 'data-mob', 'data-desk' ), array_keys( (array) ( $b['attrs']['dxaiData'] ?? array() ) ) ) );
		$a = Section_Library::analyze( $block, (int) $c['index'] );

		// 2. Panels other than the chosen one go, with their container.
		$keep = $a['panels'][ $panel ] ?? ( $a['panels'][0] ?? null );
		foreach ( array_reverse( $a['panels'], true ) as $i => $p ) {
			if ( $keep !== null && $p['heading'] === $keep['heading'] ) {
				continue;
			}
			$box = self::panel_box( $a, $p, $keep );
			if ( $box !== null ) {
				self::drop_at( $block, $box );
			}
		}
		$a    = Section_Library::analyze( $block, (int) $c['index'] );
		$keep = null;
		foreach ( $a['panels'] as $p ) {
			$keep = $p; // the only one left
			break;
		}

		// 3. Repeated items: to the unit's count.
		$rep   = self::main_repeat( $a, $unit['kind'] );
		$items = $this->items_for( $unit, $rep );
		if ( $rep !== null ) {
			$this->resize_matched( $block, $rep, $items, $templates );
			$this->fit_grid_hooks( $block, $rep['path'], count( $items ) );
			$a   = Section_Library::analyze( $block, (int) $c['index'] );
			$rep = self::main_repeat( $a, $unit['kind'] );
		}

		// 4. Buttons: the link group (a repeat of links) or the panel's links.
		$btn_rep = null;
		foreach ( $a['repeats'] as $r ) {
			if ( $r['kind'] === 'button' ) {
				$btn_rep = $r;
			}
		}
		if ( $btn_rep !== null ) {
			$this->resize( $block, $btn_rep, count( $unit['buttons'] ) );
			$a = Section_Library::analyze( $block, (int) $c['index'] );
			foreach ( $a['repeats'] as $r ) {
				if ( $r['kind'] === 'button' ) {
					$btn_rep = $r;
				}
			}
		}

		// 5. Text slots of the panel: as many as the unit has paragraphs.
		$keep = $a['panels'][0] ?? null;
		if ( $unit['eyebrow'] !== '' && ( $keep === null || $keep['eyebrow'] === null ) ) {
			array_unshift( $unit['paras'], '<strong>' . $unit['eyebrow'] . '</strong>' );
			$unit['eyebrow'] = '';
		}
		if ( $keep !== null && $keep['texts'] === array() && $unit['paras'] !== array() && self::add_text_slot( $block, $keep, self::text_repeat( $a, $unit['kind'] ) ) ) {
			// The design's heading has no text under it: the unit's text is not dropped, it takes the style of the
			// section's own item text (same background, same colours) — see add_text_slot().
			$a    = Section_Library::analyze( $block, (int) $c['index'] );
			$keep = $a['panels'][0] ?? null;
		}
		if ( $keep !== null ) {
			$this->fit_texts( $block, $keep, count( $unit['paras'] ), $unit['paras'] );
			$a    = Section_Library::analyze( $block, (int) $c['index'] );
			$keep = $a['panels'][0] ?? null;
		}
		// Paths moved with the text slots: look the groups up again.
		$rep     = $rep !== null ? self::main_repeat( $a, $unit['kind'] ) : null;
		$btn_rep = null;
		foreach ( $a['repeats'] as $r ) {
			if ( $r['kind'] === 'button' ) {
				$btn_rep = $r;
			}
		}

		// 6. Write the content.
		if ( $keep !== null ) {
			$this->set( $block, $keep['heading'], $unit['heading'] !== '' ? $unit['heading'] : null );
			if ( $keep['eyebrow'] !== null ) {
				// The old page's label, or none: the design's own label goes (sweep).
				$this->set( $block, $keep['eyebrow'], $unit['eyebrow'] !== '' ? $unit['eyebrow'] : null );
			}
			foreach ( $keep['texts'] as $i => $path ) {
				$this->set( $block, $path, $unit['paras'][ $i ] ?? null );
			}
			foreach ( $keep['lists'] as $path ) {
				$this->fill_list( $block, $path, $unit['list'] );
			}
			foreach ( $keep['links'] as $i => $path ) {
				$btn = $unit['buttons'][ $i ] ?? null;
				$this->link( $block, $path, $btn );
			}
		}
		if ( $btn_rep !== null ) {
			foreach ( $btn_rep['items'] as $k => $idx ) {
				$this->link( $block, array_merge( $btn_rep['path'], array( $idx ) ), $unit['buttons'][ $k ] ?? null );
			}
		}
		if ( $rep !== null ) {
			// Cards show their old pictures where the design's cards have an icon — only when every card has one,
			// so the set stays one kind of card.
			$pictured          = count( array_filter( $items, static fn( $i ) => ( $i['image'] ?? '' ) !== '' ) );
			$this->card_images = $items !== array() && $pictured === count( $items ) && in_array( $rep['kind'], array( 'card', 'step', 'contact' ), true );
			foreach ( $rep['items'] as $k => $idx ) {
				$this->fill_item( $block, array_merge( $rep['path'], array( $idx ) ), $rep['kind'], $items[ $k ] ?? array() );
			}
			$this->card_images = false;
		}
		$this->fill_images( $block, $a, $unit );

		// 7. Nothing of the design's own content may stay. The old page's form, wherever its unit went, stays.
		self::mark_grids( $block );
		$block = $this->sweep( $block, $unit['kind'] === 'form' || ! empty( $unit['form'] ) );
		if ( self::is_empty( $block ) ) {
			return null;
		}
		// 8. A grid that lost its picture column keeps its text to the design's reading width.
		$this->keep_measure( $block );
		self::clear_marks( $block );

		return $block;
	}

	/**
	 * The repeated group whose item text an intro may borrow: the unit's own group, else any with item text.
	 *
	 * @param array<string, mixed> $a
	 * @return array<string, mixed>|null
	 */
	private static function text_repeat( array $a, string $unit_kind ): ?array {
		$own = self::main_repeat( $a, $unit_kind );
		if ( $own !== null && self::item_text_slot( $own ) !== null ) {
			return $own;
		}
		foreach ( $a['repeats'] as $r ) {
			if ( self::item_text_slot( $r ) !== null ) {
				return $r;
			}
		}

		return null;
	}

	/**
	 * Where an item of a repeated group keeps its text: the path of its first plain text slot, inside the item.
	 *
	 * @param array<string, mixed>|null $rep
	 * @return array<int, int>|null
	 */
	private static function item_text_slot( ?array $rep ): ?array {
		foreach ( (array) ( $rep['shape']['slots'] ?? array() ) as $s ) {
			if ( $s['type'] === 'text' && ! $s['decor'] && in_array( (string) $s['name'], array( 'core/paragraph', 'dxai-ui/text' ), true ) && ! in_array( strtolower( (string) $s['tag'] ), array( 'address', 'summary', 'figcaption', 'cite', 'blockquote' ), true ) ) {
				return (array) $s['path'];
			}
		}

		return null;
	}

	/**
	 * Give a panel that has a heading and no text a text slot: a copy of its section's item text, right after
	 * the heading, keeping the space the heading kept below it. False when the section has no such text.
	 *
	 * @param array<string, mixed>      $block
	 * @param array<string, mixed>      $panel
	 * @param array<string, mixed>|null $rep
	 */
	private static function add_text_slot( array &$block, array $panel, ?array $rep ): bool {
		$slot = self::item_text_slot( $rep );
		$head = (array) ( $panel['heading'] ?? array() );
		if ( $slot === null || $head === array() || ! isset( $rep['items'][0] ) ) {
			return false;
		}
		// Only the text of an item without a background of its own: a card's text colour was made for the card, and
		// under the section's heading it could be light text on a light ground.
		$node = Block_Tree::at( $block, array_merge( $rep['path'], array( $rep['items'][0] ) ) );
		foreach ( array_merge( array( array() ), array_map( static fn( $n ) => array_slice( $slot, 0, $n ), range( 1, count( $slot ) ) ) ) as $sub ) {
			$css = Block_Tree::css_of( Block_Tree::at( $node, $sub ) );
			if ( preg_match( '/(^|;)background(-color)?:(?!none|transparent|initial|inherit)/', $css ) ) {
				return false;
			}
		}
		$text = Block_Tree::at( $block, array_merge( $rep['path'], array( $rep['items'][0] ), $slot ) );
		$gap  = self::bottom_gap( Block_Tree::at( $block, $head ) );
		Block_Tree::set_css( $text, Block_Tree::with_css( (string) ( $text['attrs']['dxaiCss'] ?? '' ), 'margin-bottom', $gap ) );
		$parent = &Block_Tree::at( $block, array_slice( $head, 0, -1 ) );
		Block_Tree::insert( $parent, $head[ count( $head ) - 1 ] + 1, $text );
		unset( $parent );

		return true;
	}

	/**
	 * The container of a panel to remove: the highest ancestor of its heading that does not hold the kept panel.
	 *
	 * @param array<string, mixed>      $a
	 * @param array<string, mixed>      $p
	 * @param array<string, mixed>|null $keep
	 * @return array<int, int>|null
	 */
	private static function panel_box( array $a, array $p, ?array $keep ): ?array {
		$h    = $p['heading'];
		$best = null;
		for ( $len = count( $h ) - 1; $len >= 1; $len-- ) {
			$prefix = array_slice( $h, 0, $len );
			if ( $keep !== null && array_slice( $keep['heading'], 0, $len ) === $prefix ) {
				break;
			}
			$best = $prefix;
		}

		return $best ?? $h;
	}

	/**
	 * The unit's items for a repeated group, in the shape the group takes.
	 *
	 * @param array<string, mixed>      $unit
	 * @param array<string, mixed>|null $rep
	 * @return array<int, array<string, mixed>>
	 */
	private function items_for( array $unit, ?array $rep ): array {
		if ( $rep === null ) {
			return array();
		}
		switch ( $rep['kind'] ) {
			case 'faq':
				return array_map( static fn( $f ) => array( 'q' => $f['q'], 'a' => $f['a'] ), $unit['faq'] );
			case 'testimonial':
				return $unit['reviews'];
			case 'stat':
				return $unit['stats'];
			case 'logo':
			case 'gallery':
				return array_map( static fn( $r ) => array( 'image' => $r ), $unit['images'] );
			case 'row':
				return array_map( static fn( $t ) => array( 'text' => $t ), $unit['list'] );
			default:
				return $unit['items'];
		}
	}

	/**
	 * Make a repeated group hold one design item per content item, each a copy of the design item whose own
	 * words best match the content item's (so a card keeps the icon that means what it says), in order.
	 *
	 * @param array<string, mixed>             $block
	 * @param array<string, mixed>             $rep
	 * @param array<int, array<string, mixed>> $items
	 */
	private function resize_matched( array &$block, array $rep, array $items, array $templates = array() ): void {
		$parent = &Block_Tree::at( $block, $rep['path'] );
		$idxs   = $rep['items'];
		if ( $idxs === array() ) {
			return;
		}
		$designs = array();
		foreach ( $idxs as $i ) {
			$node      = $parent['innerBlocks'][ $i ];
			$designs[] = array( 'block' => $node, 'words' => self::words( self::all_text( $node ) ) );
		}
		$sig = Section_Library::signature( $parent['innerBlocks'][ $idxs[0] ] );
		foreach ( $templates as $node ) {
			if ( Section_Library::signature( $node ) === $sig ) {
				$designs[] = array( 'block' => $node, 'words' => self::words( self::all_text( $node ) ) );
			}
		}
		$chosen = array();
		$taken  = array();
		foreach ( array_values( $items ) as $k => $item ) {
			$want = self::words( Content_Extractor::plain( (string) ( $item['heading'] ?? '' ) . ' ' . implode( ' ', (array) ( $item['paras'] ?? array() ) ) . ' ' . (string) ( $item['q'] ?? '' ) ) );
			$best = null;
			$top  = 0;
			foreach ( $designs as $d => $design ) {
				$score = count( array_intersect( $want, $design['words'] ) ) - ( isset( $taken[ $d ] ) ? 0.5 : 0 );
				if ( $score > $top ) {
					$top  = $score;
					$best = $d;
				}
			}
			if ( $best === null ) {
				$best = min( $k, count( $designs ) - 1 );
			}
			$taken[ $best ] = true;
			$chosen[]       = $designs[ $best ]['block'];
		}
		foreach ( array_reverse( $idxs ) as $i ) {
			Block_Tree::drop( $parent, $i );
		}
		foreach ( $chosen as $k => $node ) {
			Block_Tree::insert( $parent, $idxs[0] + $k, $node );
		}
	}

	/**
	 * Every copy of the component's main repeated item, anywhere in it, with breakpoint flags removed.
	 *
	 * @param array<string, mixed> $c
	 * @return array<int, array<string, mixed>>
	 */
	private static function templates( array $c, string $unit_kind ): array {
		$rep = self::main_repeat( $c, $unit_kind );
		if ( $rep === null ) {
			return array();
		}
		$first = Block_Tree::at( $c['block'], array_merge( $rep['path'], array( $rep['items'][0] ) ) );
		$sig   = Section_Library::signature( $first );
		$out   = array();
		$walk  = static function ( array $b ) use ( &$walk, &$out, $sig ): void {
			if ( Section_Library::signature( $b ) === $sig ) {
				$out[] = self::unflag( $b );
				return;
			}
			foreach ( (array) $b['innerBlocks'] as $inner ) {
				$walk( $inner );
			}
		};
		$walk( $c['block'] );

		return $out;
	}

	/**
	 * A block without its breakpoint flag (data-mob / data-desk), in its attributes and its opening tag.
	 *
	 * @param array<string, mixed> $b
	 * @return array<string, mixed>
	 */
	private static function unflag( array $b ): array {
		$data = (array) ( $b['attrs']['dxaiData'] ?? array() );
		$gone = array_intersect( array( 'data-mob', 'data-desk' ), array_keys( $data ) );
		if ( ! $gone ) {
			return $b;
		}
		foreach ( $gone as $key ) {
			unset( $data[ $key ] );
		}
		if ( $data === array() ) {
			unset( $b['attrs']['dxaiData'] );
		} else {
			$b['attrs']['dxaiData'] = $data;
		}
		$strip = static function ( $part ) use ( $gone ) {
			if ( ! is_string( $part ) ) {
				return $part;
			}
			foreach ( $gone as $key ) {
				$part = preg_replace( '/\s' . preg_quote( $key, '/' ) . '(="[^"]*")?(?=[\s>\/])/', '', $part, 1 ) ?? $part;
			}
			return $part;
		};
		$b['innerHTML'] = $strip( (string) $b['innerHTML'] );
		foreach ( $b['innerContent'] as $k => $part ) {
			if ( is_string( $part ) ) {
				$b['innerContent'][ $k ] = $strip( $part );
				break;
			}
		}
		if ( isset( $b['attrs']['attrOrder'] ) && is_array( $b['attrs']['attrOrder'] ) ) {
			$b['attrs']['attrOrder'] = array_values( array_diff( $b['attrs']['attrOrder'], $gone ) );
		}

		return $b;
	}

	/**
	 * Distinct meaningful words (4+ letters, not the most common ones).
	 *
	 * @return array<int, string>
	 */
	private static function words( string $text ): array {
		$stop  = array( 'with', 'your', 'from', 'that', 'this', 'have', 'will', 'more', 'they', 'their', 'when', 'what', 'about', 'services', 'service', 'into', 'over', 'every', 'after', 'just', 'than', 'then', 'them', 'were', 'been', 'also', 'here', 'home', 'homes' );
		$words = preg_split( '/[^\p{L}]+/u', mb_strtolower( $text ) ) ?: array();
		$words = array_filter( $words, static fn( $w ) => mb_strlen( $w ) >= 4 && ! in_array( $w, $stop, true ) );
		// Crude stems so "sewage"/"sewer", "remediation"/"remediate" meet.
		$words = array_map( static fn( $w ) => mb_substr( $w, 0, 5 ), $words );

		return array_values( array_unique( $words ) );
	}

	/**
	 * All text in a block and below it.
	 *
	 * @param array<string, mixed> $b
	 */
	private static function all_text( array $b ): string {
		$text = Block_Tree::own_text( $b );
		foreach ( (array) $b['innerBlocks'] as $inner ) {
			$text .= ' ' . self::all_text( $inner );
		}

		return $text;
	}

	/**
	 * Make a repeated group hold $count items: copies of its first item, or trailing items removed.
	 *
	 * @param array<string, mixed> $block
	 * @param array<string, mixed> $rep
	 */
	private function resize( array &$block, array $rep, int $count ): void {
		$parent = &Block_Tree::at( $block, $rep['path'] );
		$items  = $rep['items'];
		if ( $count <= 0 ) {
			foreach ( array_reverse( $items ) as $idx ) {
				Block_Tree::drop( $parent, $idx );
			}
			return;
		}
		$have = count( $items );
		if ( $have === 0 ) {
			return; // nothing to copy from
		}
		for ( $i = $have - 1; $i >= $count; $i-- ) {
			Block_Tree::drop( $parent, $items[ $i ] );
		}
		$template = $parent['innerBlocks'][ $items[ min( $have, $count ) - 1 ] ];
		// A copy of the first item carries the first item's extras (FAQ open state): copy the last one kept.
		for ( $i = $have; $i < $count; $i++ ) {
			Block_Tree::insert( $parent, $items[0] + $i, $template );
		}
	}

	/**
	 * Keep as many text slots after the heading as there are paragraphs: copy the last, or remove the rest.
	 *
	 * @param array<string, mixed> $block
	 * @param array<string, mixed> $panel
	 */
	private function fit_texts( array &$block, array $panel, int $count, array $paras = array() ): void {
		$texts = $panel['texts'];
		if ( $texts === array() ) {
			return;
		}
		/*
		 * The design's first text is often a lead — one short sentence set larger and bold above its body text. A
		 * unit with fewer paragraphs than the slots, whose first paragraph is long, fills body slots: its lead slot
		 * goes first instead of the last body one, or 75 words come out as a wall of bold.
		 */
		if ( $count > 0 && $count < count( $texts ) && isset( $texts[1] ) && str_word_count( Content_Extractor::plain( (string) ( $paras[0] ?? '' ) ) ) > 40 && self::is_lead( Block_Tree::at( $block, $texts[0] ), Block_Tree::at( $block, $texts[1] ) ) ) {
			$gone  = $texts[0];
			$depth = count( $gone ) - 1;
			self::drop_at( $block, $gone );
			array_shift( $texts );
			// Every later slot under the same parent (a sibling, or inside one) moved up by one.
			foreach ( $texts as &$t ) {
				if ( count( $t ) > $depth && array_slice( $t, 0, $depth ) === array_slice( $gone, 0, $depth ) && $t[ $depth ] > $gone[ $depth ] ) {
					--$t[ $depth ];
				}
			}
			unset( $t );
		}
		for ( $i = count( $texts ) - 1; $i >= max( 0, $count ); $i-- ) {
			if ( $i === 0 && $count === 0 ) {
				self::drop_at( $block, $texts[0] );
				break;
			}
			if ( $i >= $count ) {
				self::drop_at( $block, $texts[ $i ] );
			}
		}
		if ( $count > count( $texts ) ) {
			$last   = $texts[ count( $texts ) - 1 ];
			$ppath  = array_slice( $last, 0, -1 );
			$idx    = $last[ count( $last ) - 1 ];
			$parent = &Block_Tree::at( $block, $ppath );
			$copy   = $parent['innerBlocks'][ $idx ];
			/*
			 * The design's last text keeps no space below it (nothing followed it there); its copies do follow one
			 * another, so every one but the new last keeps the gap the design puts between its texts — or they
			 * run together into one block.
			 */
			$gap    = count( $texts ) >= 2 ? self::bottom_gap( Block_Tree::at( $block, $texts[ count( $texts ) - 2 ] ) ) : '16px';
			$spaced = $copy;
			if ( ! self::has_bottom_space( $copy ) ) {
				Block_Tree::set_css( $spaced, Block_Tree::with_css( (string) ( $spaced['attrs']['dxaiCss'] ?? '' ), 'margin-bottom', $gap ) );
				$parent['innerBlocks'][ $idx ] = $spaced;
			}
			for ( $i = count( $texts ); $i < $count; $i++ ) {
				Block_Tree::insert( $parent, $idx + 1 + ( $i - count( $texts ) ), $i === $count - 1 ? $copy : $spaced );
			}
		}
	}

	/**
	 * Whether the block at $path, or one of its ancestors, is itself a link.
	 *
	 * @param array<string, mixed> $block
	 * @param array<int, int>      $path
	 */
	private static function inside_link( array $block, array $path ): bool {
		$node = $block;
		for ( $i = 0; $i <= count( $path ); $i++ ) {
			$name = (string) ( $node['blockName'] ?? '' );
			$tag  = strtolower( (string) ( $node['attrs']['tagName'] ?? '' ) );
			if ( in_array( $name, array( 'dxai-ui/link', 'core/button' ), true ) || ( in_array( $name, array( 'dxai-ui/box', 'dxai-ui/text' ), true ) && $tag === 'a' ) ) {
				return true;
			}
			if ( $i === count( $path ) || ! isset( $node['innerBlocks'][ $path[ $i ] ] ) ) {
				break;
			}
			$node = $node['innerBlocks'][ $path[ $i ] ];
		}

		return false;
	}

	/**
	 * Whether a block keeps some space below itself (its own margin-bottom, or the bottom of its margin).
	 *
	 * @param array<string, mixed> $b
	 */
	private static function has_bottom_space( array $b ): bool {
		$bottom = '';
		foreach ( array_filter( array_map( 'trim', explode( ';', Block_Tree::css_of( $b ) ) ), 'strlen' ) as $decl ) {
			if ( preg_match( '/^margin-bottom:(.+)$/', $decl, $m ) ) {
				$bottom = trim( $m[1] );
			} elseif ( preg_match( '/^margin:(.+)$/', $decl, $m ) ) {
				$parts  = preg_split( '/\s+/', trim( $m[1] ) ) ?: array();
				$bottom = (string) ( $parts[2] ?? ( $parts[0] ?? '' ) );
			}
		}

		return $bottom !== '' && ! preg_match( '/^-?0(px|em|rem|%)?$/', $bottom );
	}

	/**
	 * Whether a text slot is a lead above the next one: bolder, or set larger.
	 *
	 * @param array<string, mixed> $first
	 * @param array<string, mixed> $next
	 */
	private static function is_lead( array $first, array $next ): bool {
		$weight = static fn( string $css ): int => preg_match( '/(^|;)font-weight:(\d{3}|bold)/', $css, $m ) ? ( $m[2] === 'bold' ? 700 : (int) $m[2] ) : 400;
		$size   = static fn( string $css ): float => preg_match( '/(^|;)font-size:([\d.]+)px/', $css, $m ) ? (float) $m[2] : 0.0;
		$a      = Block_Tree::css_of( $first );
		$b      = Block_Tree::css_of( $next );

		return ( $weight( $a ) >= 600 && $weight( $b ) < 600 ) || ( $size( $a ) > 0 && $size( $b ) > 0 && $size( $a ) >= $size( $b ) + 1.5 );
	}

	/**
	 * @param array<string, mixed> $block
	 * @param array<int, int>      $path
	 */
	private function set( array &$block, array $path, ?string $html ): void {
		if ( $html === null || Content_Extractor::plain( $html ) === '' ) {
			return; // left as it is: the sweep removes it, since its text is not the page's
		}
		// Inside a link already (a card that is one link, a button): the old text's own links are words only — a link
		// in a link is invalid HTML and browsers break the card apart.
		if ( stripos( $html, '<a' ) !== false && self::inside_link( $block, $path ) ) {
			$html = (string) preg_replace( '#</?a\b[^>]*>#i', '', $html );
		}
		$node = &Block_Tree::at( $block, $path );
		$html = self::inline_links( $node, $html );
		try {
			Block_Tree::set_content( $node, $html );
			$this->allow( $html );
		} catch ( \RuntimeException $e ) {
			$this->log[] = 'could not write ' . implode( '.', $path ) . ': ' . $e->getMessage();
		}
	}

	/**
	 * Links inside the old page's text take the colour of the text around them, underlined.
	 *
	 * The design's text slots hold no links of their own, so nothing in its stylesheet colours one there, and
	 * the theme's link colour wins instead — the site's blue on the design's blue band. The rule is the block's
	 * own inline-run CSS (Style_Hoister::INNER_ATTR), written with its other rules by Style_Rules, so it holds
	 * in the editor and with the plugin deactivated (the runtime writes the same rules).
	 *
	 * @param array<string, mixed> $node
	 */
	private static function inline_links( array &$node, string $html ): string {
		if ( stripos( $html, '<a ' ) === false || ! in_array( (string) ( $node['blockName'] ?? '' ), self::INLINE_CSS_BLOCKS, true ) ) {
			return $html;
		}
		$class = \DXAI_UI\Compiler\Style_Hoister::css_class( self::LINK_CSS );
		$html  = (string) preg_replace_callback(
			'/<a\b([^>]*)>/i',
			static fn( $m ) => preg_match( '/\sclass\s*=/i', $m[1] ) ? $m[0] : '<a class="' . $class . '"' . $m[1] . '>',
			$html
		);
		$inner                  = is_array( $node['attrs'][ \DXAI_UI\Compiler\Style_Hoister::INNER_ATTR ] ?? null ) ? $node['attrs'][ \DXAI_UI\Compiler\Style_Hoister::INNER_ATTR ] : array();
		$inner[ $class ]        = self::LINK_CSS;
		$node['attrs'][ \DXAI_UI\Compiler\Style_Hoister::INNER_ATTR ] = $inner;

		return $html;
	}

	/**
	 * @param array<string, mixed>      $block
	 * @param array<int, int>           $path
	 * @param array<string, string>|null $btn
	 */
	private function link( array &$block, array $path, ?array $btn ): void {
		if ( $btn === null ) {
			return;
		}
		$node = &Block_Tree::at( $block, $path );
		Block_Tree::set_link( $node, $btn['href'] !== '' ? $btn['href'] : '#', empty( $node['innerBlocks'] ) ? $btn['label'] : null );
		$this->allow( $btn['label'] );
	}

	/**
	 * @param array<string, mixed> $block
	 * @param array<int, int>      $path
	 * @param array<int, string>   $items
	 */
	private function fill_list( array &$block, array $path, array $items ): void {
		$list = Block_Tree::at( $block, $path );
		$rep  = array(
			'path'  => $path,
			'items' => array_keys( (array) $list['innerBlocks'] ),
		);
		$this->resize( $block, $rep, count( $items ) );
		foreach ( $items as $i => $html ) {
			$this->set( $block, array_merge( $path, array( $i ) ), $html );
		}
	}

	/**
	 * One repeated item.
	 *
	 * @param array<string, mixed> $block
	 * @param array<int, int>      $path
	 * @param array<string, mixed> $item
	 */
	private function fill_item( array &$block, array $path, string $kind, array $item ): void {
		$node  = Block_Tree::at( $block, $path );
		$slots = array();
		$none  = array();
		$shape = Section_Library::analyze( $node, 0 );
		$texts = array_values( array_filter( $shape['slots'], static fn( $s ) => in_array( $s['type'], array( 'text', 'heading' ), true ) && ! $s['decor'] ) );
		$abs   = static fn( array $p ) => array_merge( $path, $p );
		switch ( $kind ) {
			case 'faq':
				foreach ( $texts as $k => $s ) {
					$this->set( $block, $abs( $s['path'] ), $k === 0 ? esc_html( (string) ( $item['q'] ?? '' ) ) : esc_html( (string) ( $item['a'] ?? '' ) ) );
				}
				break;
			case 'testimonial':
				foreach ( $texts as $k => $s ) {
					$value = null;
					if ( preg_match( '/^[\s★☆✩✪⭐]+$/u', (string) $s['text'] ) ) {
						$value = str_repeat( '★', max( 1, min( 5, (int) ( $item['rating'] ?? 5 ) ) ) );
					} elseif ( strtolower( (string) $s['tag'] ) === 'figcaption' ) {
						$value = esc_html( (string) ( $item['name'] ?? '' ) );
					} elseif ( $value === null && ! isset( $quoted ) ) {
						$value  = (string) ( $item['quote'] ?? '' );
						$quoted = true;
					}
					$this->set( $block, $abs( $s['path'] ), $value );
				}
				unset( $quoted );
				break;
			case 'stat':
				foreach ( $texts as $k => $s ) {
					$this->set( $block, $abs( $s['path'] ), esc_html( (string) ( $k === 0 ? ( $item['value'] ?? '' ) : ( $item['label'] ?? '' ) ) ) );
				}
				break;
			case 'contact':
				if ( $this->card_images && ( $item['image'] ?? '' ) !== '' && ! self::shows_picture( $shape ) && $this->icon_to_picture( $block, $path, $shape ) ) {
					$node  = Block_Tree::at( $block, $path );
					$shape = Section_Library::analyze( $node, 0 );
					$texts = array_values( array_filter( $shape['slots'], static fn( $s ) => in_array( $s['type'], array( 'text', 'heading' ), true ) && ! $s['decor'] ) );
				}
				$fields  = (array) ( $item['fields'] ?? array() );
				$pick    = static function ( string $pattern ) use ( &$fields ): ?array {
					foreach ( $fields as $i => $fl ) {
						if ( preg_match( $pattern, (string) $fl['label'] . ' ' . (string) $fl['href'] ) ) {
							unset( $fields[ $i ] );
							return $fl;
						}
					}
					return null;
				};
				$address = $pick( '/address|location|office|visit/i' );
				$phone   = $pick( '/call|phone|tel:|mobile/i' );
				$rest    = array_merge( array_map( static fn( $fl ) => (string) $fl['html'], array_values( $fields ) ), (array) ( $item['paras'] ?? array() ) );
				foreach ( $texts as $s ) {
					if ( $s['type'] === 'heading' ) {
						$this->set( $block, $abs( $s['path'] ), (string) ( $item['heading'] ?? '' ) );
					} elseif ( strtolower( (string) $s['tag'] ) === 'address' ) {
						$this->set( $block, $abs( $s['path'] ), $address ? (string) $address['html'] : null );
					} else {
						$next = array_shift( $rest );
						$this->set( $block, $abs( $s['path'] ), $next );
					}
				}
				foreach ( $shape['slots'] as $s ) {
					if ( $s['type'] === 'link' ) {
						$this->link( $block, $abs( $s['path'] ), $phone ? array( 'label' => Content_Extractor::plain( (string) $phone['html'] ), 'href' => $phone['href'] !== '' ? (string) $phone['href'] : 'tel:' . preg_replace( '/[^\d+]/', '', Content_Extractor::plain( (string) $phone['html'] ) ) ) : null );
					}
				}
				foreach ( $shape['slots'] as $s ) {
					if ( $s['type'] === 'image' && ! $s['decor'] ) {
						$this->image( $block, $abs( $s['path'] ), (string) ( $item['image'] ?? '' ) );
					}
				}
				break;
			case 'row':
				// The list item itself.
				$this->set( $block, $path, (string) ( $item['text'] ?? '' ) );
				break;
			case 'logo':
			case 'gallery':
				foreach ( $shape['slots'] as $s ) {
					if ( $s['type'] === 'image' ) {
						$this->image( $block, $abs( $s['path'] ), (string) ( $item['image'] ?? '' ) );
					}
				}
				break;
			default: // card, step, contact
				if ( $this->card_images && ( $item['image'] ?? '' ) !== '' && ! self::shows_picture( $shape ) && $this->icon_to_picture( $block, $path, $shape ) ) {
					$node  = Block_Tree::at( $block, $path );
					$shape = Section_Library::analyze( $node, 0 );
					$texts = array_values( array_filter( $shape['slots'], static fn( $s ) => in_array( $s['type'], array( 'text', 'heading' ), true ) && ! $s['decor'] ) );
				}
				$heading = null;
				$paras   = array_values( (array) ( $item['paras'] ?? array() ) );
				$body    = implode( ' ', $paras );
				$used    = false;
				foreach ( $texts as $s ) {
					if ( $s['type'] === 'heading' && $heading === null ) {
						$heading = true;
						$this->set( $block, $abs( $s['path'] ), (string) ( $item['heading'] ?? '' ) );
						continue;
					}
					if ( ! $used ) {
						$this->set( $block, $abs( $s['path'] ), $body !== '' ? $body : null );
						$used = true;
						continue;
					}
					// Further text slots (a "learn more" line): the old card's link label, if it had one.
					$this->set( $block, $abs( $s['path'] ), ( $item['label'] ?? '' ) !== '' ? esc_html( (string) $item['label'] ) : null );
				}
				if ( ( $node['blockName'] ?? '' ) === 'dxai-ui/link' || ( $shape['slots'][0]['type'] ?? '' ) === 'card' ) {
					$target = &Block_Tree::at( $block, $path );
					if ( ( $target['blockName'] ?? '' ) === 'dxai-ui/link' ) {
						Block_Tree::set_link( $target, ( $item['href'] ?? '' ) !== '' ? (string) $item['href'] : '#' );
					}
					unset( $target );
				}
				foreach ( $shape['slots'] as $s ) {
					if ( $s['type'] === 'link' ) {
						$this->link( $block, $abs( $s['path'] ), ( $item['href'] ?? '' ) !== '' ? array( 'label' => (string) ( $item['label'] ?? '' ), 'href' => (string) $item['href'] ) : null );
					}
					if ( $s['type'] === 'image' && ! $s['decor'] ) {
						$this->image( $block, $abs( $s['path'] ), (string) ( $item['image'] ?? '' ) );
					}
				}
		}
	}

	/** @param array<string, mixed> $shape An item's shape: an icon before its heading and text. */
	private static function leading_icon( array $shape ): bool {
		foreach ( (array) ( $shape['slots'] ?? array() ) as $s ) {
			if ( $s['type'] === 'icon' ) {
				return true;
			}
			if ( in_array( $s['type'], array( 'heading', 'text' ), true ) ) {
				return false;
			}
		}

		return false;
	}

	/** @param array<string, mixed> $shape An item's Section_Library::analyze(). */
	private static function shows_picture( array $shape ): bool {
		foreach ( $shape['slots'] as $s ) {
			if ( $s['type'] === 'image' && ! $s['decor'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Put a picture where a card shows its leading icon: the design's own picture block (the plainest one in the
	 * library — full width, its own height, not laid over anything), keeping the gap the icon kept below it. The
	 * image slot is then filled like any other (image()), cropped to that picture's shape so the cards line up.
	 *
	 * @param array<string, mixed> $block
	 * @param array<int, int>      $path  The card.
	 * @param array<string, mixed> $shape The card's Section_Library::analyze().
	 */
	private function icon_to_picture( array &$block, array $path, array $shape ): bool {
		$picture = $this->picture_block();
		if ( $picture === null ) {
			return false;
		}
		$icon = null;
		foreach ( $shape['slots'] as $s ) {
			if ( $s['type'] === 'icon' ) {
				$icon = $s['path'];
				break;
			}
			if ( in_array( $s['type'], array( 'heading', 'text' ), true ) ) {
				break; // an icon after the text is not the card's picture place
			}
		}
		$card = &Block_Tree::at( $block, $path );
		// The icon's own wrappers (a tinted square holding only it) go with it.
		while ( $icon !== null && count( $icon ) > 1 && count( (array) Block_Tree::at( $card, array_slice( $icon, 0, -1 ) )['innerBlocks'] ) === 1 ) {
			$icon = array_slice( $icon, 0, -1 );
		}
		// Only an icon of its own row at the top of the card is replaced; an icon beside the heading stays, and a
		// card that is a box of its own (background, border or shadow) gets the picture above its first row.
		if ( $icon === null || count( $icon ) !== 1 ) {
			$box = Block_Tree::css_of( $card );
			if ( ! preg_match( '/(^|;)(background(-color)?|border(-width)?|box-shadow):(?!none|0(;|$)|transparent)/', $box ) || empty( $card['innerBlocks'] ) ) {
				unset( $card );
				return false;
			}
			Block_Tree::set_css( $picture, Block_Tree::with_css( (string) ( $picture['attrs']['dxaiCss'] ?? '' ), 'margin-bottom', self::CARD_IMAGE_GAP ) );
			Block_Tree::insert( $card, 0, $picture );
			unset( $card );

			return true;
		}
		$gap = self::bottom_gap( Block_Tree::at( $card, $icon ) );
		Block_Tree::set_css( $picture, Block_Tree::with_css( (string) ( $picture['attrs']['dxaiCss'] ?? '' ), 'margin-bottom', $gap ) );
		$parent = &Block_Tree::at( $card, array_slice( $icon, 0, -1 ) );
		$at     = $icon[ count( $icon ) - 1 ];
		Block_Tree::drop( $parent, $at );
		Block_Tree::insert( $parent, $at, $picture );
		unset( $parent, $card );

		return true;
	}

	/**
	 * The library's plainest picture block, to show a card's picture in.
	 *
	 * @return array<string, mixed>|null
	 */
	private function picture_block(): ?array {
		if ( $this->picture !== null ) {
			return $this->picture === false ? null : $this->picture;
		}
		$best = null;
		$rank = -1;
		foreach ( $this->library as $c ) {
			// A hero's pictures are its backdrop, however its stylesheet lays them over it.
			if ( $c['kind'] === 'hero' ) {
				continue;
			}
			foreach ( self::shown_pictures( $c['block'] ) as $b ) {
				$css = Block_Tree::css_of( $b );
				// Never a picture laid over its box (a background): in a card it would cover the page.
				if ( self::is_backdrop( $b, false ) || str_contains( $css, 'position:absolute' ) || str_contains( $css, 'position:fixed' ) ) {
					continue;
				}
				// Full width at its own height (a picture in a column), not a band of a fixed height across a section,
				// nor a logo (capped height, own width), nor a faded or filtered one.
				$score = ( str_contains( $css, 'width:100%' ) ? 2 : 0 ) + ( str_contains( $css, 'height:auto' ) ? 2 : 0 ) + ( str_contains( $css, 'border-radius' ) ? 1 : 0 )
					- ( preg_match( '/min-height:(\d+)/', $css, $m ) && (int) $m[1] > 240 ? 3 : 0 )
					- ( preg_match( '/(^|;)height:(clamp|calc|\d)/', $css ) ? 2 : 0 )
					- ( preg_match( '/(^|;)max-height:/', $css ) ? 3 : 0 )
					- ( preg_match( '/(^|;)width:auto/', $css ) ? 2 : 0 )
					- ( preg_match( '/(^|;)(opacity:0?\.|filter:(?!none)|mix-blend-mode:|transform:(?!none))/', $css ) ? 3 : 0 );
				if ( $score > $rank ) {
					$rank = $score;
					$best = $b;
				}
			}
		}
		$this->picture = $best ?? false;

		return $best;
	}

	/**
	 * The dxai-ui/image blocks of a section a visitor always sees as pictures of their own: not in a box shown on one
	 * screen width only or hidden until a toggle, not decorative, not beside an overlay (a backdrop under a scrim).
	 *
	 * @param array<string, mixed> $b
	 * @return array<int, array<string, mixed>>
	 */
	private static function shown_pictures( array $b ): array {
		$class = ' ' . (string) ( $b['attrs']['className'] ?? '' ) . ' ';
		if ( str_contains( $class, 'dxai-on-' ) || str_contains( $class, ' hidden ' ) || array_intersect( array( 'data-mob', 'data-desk' ), array_keys( (array) ( $b['attrs']['dxaiData'] ?? array() ) ) ) ) {
			return array();
		}
		if ( (string) ( $b['blockName'] ?? '' ) === 'dxai-ui/image' ) {
			$decor = ( $b['attrs']['ariaHidden'] ?? '' ) === 'true' || preg_match( '#^\s*<[^>]*\baria-hidden="true"#', (string) $b['innerHTML'] ) === 1;
			return $decor ? array() : array( $b );
		}
		$kids = (array) ( $b['innerBlocks'] ?? array() );
		$veil = false;
		foreach ( $kids as $k ) {
			$veil = $veil || ( empty( $k['innerBlocks'] ) && ( ( $k['attrs']['ariaHidden'] ?? '' ) === 'true' || preg_match( '#^\s*<[^>]*\baria-hidden="true"#', (string) ( $k['innerHTML'] ?? '' ) ) === 1 ) && (string) ( $k['blockName'] ?? '' ) === 'dxai-ui/box' );
		}
		$out = array();
		foreach ( $kids as $k ) {
			if ( $veil && (string) ( $k['blockName'] ?? '' ) === 'dxai-ui/image' ) {
				continue; // a picture beside an overlay is that box's backdrop
			}
			array_push( $out, ...self::shown_pictures( $k ) );
		}

		return $out;
	}

	/**
	 * The space a block keeps below it (its own CSS or its inline runs'), 16px when it states none.
	 *
	 * @param array<string, mixed> $b
	 */
	private static function bottom_gap( array $b ): string {
		// The block's own declarations first, in order (the last margin-bottom or margin wins), then its inline runs'.
		foreach ( array( Block_Tree::css_of( $b ), strtolower( implode( ';', array_map( 'strval', (array) ( $b['attrs']['dxaiInner'] ?? array() ) ) ) ) ) as $css ) {
			$gap = '';
			foreach ( array_filter( array_map( 'trim', explode( ';', $css ) ), 'strlen' ) as $decl ) {
				if ( preg_match( '/^margin-bottom:(.+)$/', $decl, $m ) ) {
					$gap = trim( $m[1] );
				} elseif ( preg_match( '/^margin:(.+)$/', $decl, $m ) ) {
					$parts = preg_split( '/\s+/', trim( $m[1] ) ) ?: array();
					$gap   = (string) ( $parts[2] ?? ( $parts[0] ?? '' ) );
				}
			}
			if ( $gap !== '' && $gap !== '0' && $gap !== '0px' && $gap !== 'auto' && ! str_contains( $gap, 'auto' ) ) {
				return $gap;
			}
		}

		return '16px';
	}

	/**
	 * The section's own pictures (not inside items): the unit's, then the page's unused ones; decorative
	 * backgrounds take the unit's background.
	 *
	 * @param array<string, mixed> $block
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $unit
	 */
	private function fill_images( array &$block, array $a, array $unit ): void {
		$fresh  = fn( $r ) => $r !== '' && ! isset( $this->used_images[ $r ] );
		// The unit's pictures, and its items' when the items have no picture of their own to show them in.
		$own    = $unit['images'];
		foreach ( $unit['items'] as $item ) {
			$own[] = (string) ( $item['image'] ?? '' );
		}
		$queue  = array_values( array_unique( array_filter( $own, $fresh ) ) );
		$bg     = $fresh( (string) $unit['bg'] ) ? (string) $unit['bg'] : '';
		$reused = ( $this->used[ (int) $a['index'] ] ?? 1 ) > 1;
		$head   = (array) ( $a['panels'][0]['heading'] ?? array() );

		$backdrops = array();
		$pictures  = array();
		foreach ( $a['images'] as $s ) {
			if ( self::is_backdrop( Block_Tree::at( $block, $s['path'] ), (bool) $s['decor'] ) ) {
				$backdrops[] = $s;
				continue;
			}
			// How close the picture sits to the text: beside it (in the same grid) before a band across the section.
			$near = 0;
			while ( $near < min( count( $head ), count( $s['path'] ) ) && $head[ $near ] === $s['path'][ $near ] ) {
				++$near;
			}
			$s['near']  = $near;
			$pictures[] = $s;
		}
		usort( $pictures, static fn( $x, $y ) => $y['near'] <=> $x['near'] );

		foreach ( $backdrops as $k => $s ) {
			$ref = '';
			if ( $k === 0 && $bg !== '' ) {
				$ref = $bg;
				$bg  = '';
			} elseif ( $k === 0 && $pictures === array() && $queue !== array() && self::is_photo_backdrop( Block_Tree::at( $block, $s['path'] ) ) ) {
				// A section whose only picture is a photo behind it (full cover, under an overlay) shows the unit's
				// picture there. Not a texture (a faded map at one side): a map screenshot or a photo there ran
				// through the text.
				$ref = (string) array_shift( $queue );
			}
			if ( $ref !== '' ) {
				$this->image( $block, $s['path'], $ref );
				continue;
			}
			// Nothing of the old page's for it: the design's own background stays — it is how the section looks,
			// the overlay and the text colours over it were made for it.
			$node                          = &Block_Tree::at( $block, $s['path'] );
			$node['attrs']['__dxai_image'] = true;
			unset( $node );
		}
		if ( $bg !== '' ) {
			array_unshift( $queue, $bg );
		}
		foreach ( $pictures as $s ) {
			// A band across the whole section takes only a picture made for it: wide and large. A small snapshot
			// stretched edge to edge came out soft and cropped through people's heads; it waits for another place.
			if ( $s['near'] === 0 && $queue !== array() && self::is_band( Block_Tree::at( $block, $s['path'] ) ) && ! $this->fits_band( (string) $queue[0] ) ) {
				$this->image( $block, $s['path'], '' );
				continue;
			}
			$ref = (string) ( array_shift( $queue ) ?? '' );
			// Another old picture of the page may fill a picture beside the text; a band across the section stays
			// empty (and goes) rather than show a picture that belongs nowhere near it.
			if ( $ref === '' && ! $reused && $s['near'] > 0 ) {
				$ref = $this->spare_image();
			}
			$this->image( $block, $s['path'], $ref );
		}
		foreach ( $a['repeats'] as $r ) {
			if ( $r['kind'] === 'gallery' ) {
				// A picture grid shows as many of the old pictures as there are (the spares counted are pictures other
				// than the queue's own — a queued picture is also unused, and counted twice it left a cell empty); the
				// cells left over go, and the grid closes up around the rest (keep_measure()).
				$spare = $reused ? array() : array_values( array_diff( $this->spare_images( count( $r['items'] ) + count( $queue ) ), $queue ) );
				$fill  = min( count( $r['items'] ), count( $queue ) + count( $spare ) );
				foreach ( array_slice( $r['items'], $fill ) as $idx ) {
					$node                         = &Block_Tree::at( $block, array_merge( $r['path'], array( $idx ) ) );
					$node['attrs']['__dxai_drop'] = true;
					unset( $node );
				}
				$r['items'] = array_slice( $r['items'], 0, $fill );
				if ( $fill === 0 ) {
					continue;
				}
				foreach ( $r['items'] as $idx ) {
					$item = Block_Tree::at( $block, array_merge( $r['path'], array( $idx ) ) );
					$ref  = (string) ( array_shift( $queue ) ?? ( $reused ? '' : $this->spare_image() ) );
					$sh   = Section_Library::analyze( $item, 0 );
					foreach ( $sh['slots'] as $s ) {
						if ( $s['type'] === 'image' ) {
							// A picture that cannot be shown (its download failed) goes with its cell; the grid closes up.
							$this->image( $block, array_merge( $r['path'], array( $idx ), $s['path'] ), $ref );
						}
					}
				}
			}
		}
	}

	/**
	 * A picture behind the section's content (decorative, or laid over the whole box under the text).
	 *
	 * @param array<string, mixed> $node
	 */
	private static function is_backdrop( array $node, bool $decor ): bool {
		if ( $decor ) {
			return true;
		}
		$css = Block_Tree::css_of( $node );
		if ( ! str_contains( $css, 'position:absolute' ) && ! str_contains( $css, 'position:fixed' ) ) {
			return false;
		}

		// Laid over the whole box: inset 0, or pinned to its top and left edge, or its full height.
		return (bool) preg_match( '/(^|;)inset:0(px)?(;|$)/', $css ) || ( preg_match( '/(^|;)top:0(px)?(;|$)/', $css ) && preg_match( '/(^|;)left:0(px)?(;|$)/', $css ) ) || (bool) preg_match( '/(^|;)height:100%/', $css );
	}

	/**
	 * A picture slot that is a band across its section: full width at a height of its own, cropped to fit.
	 *
	 * @param array<string, mixed> $node
	 */
	private static function is_band( array $node ): bool {
		$css = Block_Tree::css_of( $node );

		return str_contains( $css, 'width:100%' ) && preg_match( '/(^|;)(height|min-height):(clamp|calc|\d)/', $css ) && ! str_contains( $css, 'position:absolute' );
	}

	/** Whether an old picture is large and wide enough for a band (unknown sizes are given the benefit). */
	private function fits_band( string $ref ): bool {
		$w = (int) ( $this->pool[ $ref ]['w'] ?? 0 );
		$h = (int) ( $this->pool[ $ref ]['h'] ?? 0 );
		if ( $w <= 0 ) {
			return true;
		}

		return $w >= 1000 && ( $h <= 0 || $w / $h >= 1.5 );
	}

	/**
	 * A photo behind the whole section (full cover), as opposed to a texture or shape at one side of it.
	 *
	 * @param array<string, mixed> $node
	 */
	private static function is_photo_backdrop( array $node ): bool {
		$css = Block_Tree::css_of( $node );
		if ( ! str_contains( $css, 'object-fit:cover' ) && ! str_contains( $css, 'background-size:cover' ) ) {
			return false;
		}

		return (bool) preg_match( '/(^|;)inset:0(px)?(;|$)/', $css ) || ( str_contains( $css, 'width:100%' ) && str_contains( $css, 'height:100%' ) );
	}

	/**
	 * Up to $n spare pictures (without taking them).
	 *
	 * @return array<int, string>
	 */
	private function spare_images( int $n ): array {
		$out = array();
		foreach ( $this->pool as $ref => $img ) {
			if ( count( $out ) >= $n ) {
				break;
			}
			if ( ! isset( $this->used_images[ $ref ] ) && ! isset( $this->reserved[ $ref ] ) && ( $img['role'] ?? '' ) === 'content' ) {
				$out[] = (string) $ref;
			}
		}

		return $out;
	}

	/** An old image of this page nothing has used yet, content pictures first. */
	private function spare_image(): string {
		foreach ( $this->pool as $ref => $img ) {
			if ( ! isset( $this->used_images[ $ref ] ) && ! isset( $this->reserved[ $ref ] ) && ( $img['role'] ?? '' ) === 'content' ) {
				return (string) $ref;
			}
		}

		return '';
	}

	/**
	 * Show an old image in an image slot, cropped to the slot's shape; an empty ref marks the slot for removal.
	 *
	 * @param array<string, mixed> $block
	 * @param array<int, int>      $path
	 */
	private function image( array &$block, array $path, string $ref ): void {
		$node = &Block_Tree::at( $block, $path );
		if ( $ref === '' || ! isset( $this->pool[ $ref ] ) ) {
			$node['attrs']['__dxai_drop'] = true;
			return;
		}
		$img    = $this->pool[ $ref ];
		$aspect = self::slot_aspect( $node );
		$cover  = str_contains( Block_Tree::css_of( $node ), 'object-fit:cover' );
		$got    = ( $this->images )( (string) $img['src'], $aspect, ! $cover && $aspect > 0 );
		if ( ! is_array( $got ) || empty( $got['url'] ) ) {
			$node['attrs']['__dxai_drop'] = true;
			return;
		}
		Block_Tree::set_image( $node, (string) $got['url'], (string) ( $img['alt'] ?? '' ), (int) ( $got['id'] ?? 0 ) );
		$node['attrs']['__dxai_image'] = true;
		$this->used_images[ $ref ]     = true;
	}

	/**
	 * Width/height of the picture a design image slot shows now (0 when unknown).
	 *
	 * @param array<string, mixed> $node
	 */
	private static function slot_aspect( array $node ): float {
		$url = (string) ( $node['attrs']['url'] ?? $node['attrs']['src'] ?? '' );
		if ( $url === '' ) {
			return 0.0;
		}
		$uploads = wp_get_upload_dir();
		$base    = (string) $uploads['baseurl'];
		$path    = '';
		if ( str_starts_with( $url, $base ) ) {
			$path = (string) $uploads['basedir'] . substr( $url, strlen( $base ) );
		}
		if ( $path !== '' && is_readable( $path ) ) {
			$size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $size ) && $size[1] > 0 ) {
				return $size[0] / $size[1];
			}
		}

		return 0.0;
	}

	/** @param string $html */
	private function allow( string $html ): void {
		$text = Content_Extractor::plain( $html );
		if ( $text !== '' ) {
			$this->allowed[ mb_strtolower( $text ) ] = true;
		}
	}

	/**
	 * Remove blocks the predicate matches (anywhere below $block).
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private function prune( array $block, callable $drop ): array {
		foreach ( array_reverse( array_keys( (array) $block['innerBlocks'] ) ) as $i ) {
			if ( $drop( $block['innerBlocks'][ $i ] ) ) {
				Block_Tree::drop( $block, (int) $i );
				continue;
			}
			$block['innerBlocks'][ $i ] = $this->prune( $block['innerBlocks'][ $i ], $drop );
		}

		return $block;
	}

	/**
	 * Remove every block that still shows the design's own text or picture, and containers left empty.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private function sweep( array $block, bool $keep_widgets ): array {
		$foreign = function ( array $b ) use ( $keep_widgets ): bool {
			$name = (string) ( $b['blockName'] ?? '' );
			if ( ! empty( $b['attrs']['__dxai_drop'] ) ) {
				return true;
			}
			if ( in_array( $name, array( 'dxai-ui/image', 'core/image' ), true ) || ( $name === 'dxai-ui/box' && strtolower( (string) ( $b['attrs']['tagName'] ?? '' ) ) === 'img' ) ) {
				return empty( $b['attrs']['__dxai_image'] );
			}
			if ( $name === 'dxai-ui/html' && ! $keep_widgets && preg_match( '#<(form|input|select|textarea|button)\b#i', (string) $b['innerHTML'] ) ) {
				return true;
			}
			// A form of any kind (a form plugin's block or shortcode) stays only in the section the old page's form
			// goes in — and there it stays as the Home has it, whatever its shortcode reads like.
			if ( Section_Library::is_form_block( $b ) ) {
				return ! $keep_widgets;
			}
			if ( ! empty( $b['innerBlocks'] ) ) {
				return false;
			}
			$text = mb_strtolower( Block_Tree::own_text( $b ) );
			if ( $text === '' || preg_match( '/^[\s★☆✓✔✗+−–·•|→←↑↓\-\d%]+$/u', $text ) ) {
				return false;
			}
			if ( $name === 'dxai-ui/html' && $keep_widgets ) {
				return false;
			}

			return ! isset( $this->allowed[ $text ] );
		};
		$block = $this->prune( $block, $foreign );
		// Containers the removals left empty go; a box that was empty in the design itself — the overlay over a
		// photo, a rule, an accent shape — is drawing, not an empty slot, and stays.
		$block = $this->prune( $block, static fn( $b ) => self::is_empty( $b ) && ! in_array( (string) ( $b['blockName'] ?? '' ), array( 'dxai-ui/svg' ), true ) && self::is_container( $b ) && ! self::is_drawing( $b ) );
		self::clear_marks( $block, array( '__dxai_drop', '__dxai_image' ) );

		return $block;
	}

	/**
	 * A box with no content of its own and a look of its own: an overlay, a divider, a decorative shape.
	 *
	 * @param array<string, mixed> $b
	 */
	private static function is_drawing( array $b ): bool {
		return ! empty( $b['attrs']['__dxai_drawing'] ) && empty( $b['innerBlocks'] );
	}

	/**
	 * Mark the boxes that are empty in the design as it is — before anything is removed — so that only those count
	 * as drawing (an overlay, a rule, an accent shape) and not a box the removals emptied.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function mark_drawings( array &$block ): void {
		if ( empty( $block['innerBlocks'] ) ) {
			if ( (string) ( $block['blockName'] ?? '' ) === 'dxai-ui/box' && strtolower( (string) ( $block['attrs']['tagName'] ?? '' ) ) !== 'img' && Block_Tree::own_text( $block ) === ''
				&& ( trim( (string) ( $block['attrs']['dxaiCss'] ?? '' ) ) !== '' || trim( (string) ( $block['attrs']['className'] ?? '' ) ) !== '' ) ) {
				$block['attrs']['__dxai_drawing'] = true;
			}
			return;
		}
		foreach ( $block['innerBlocks'] as &$inner ) {
			self::mark_drawings( $inner );
		}
		unset( $inner );
	}

	/**
	 * Mark every grid with how many columns (children) it has before the sweep, for keep_measure().
	 *
	 * @param array<string, mixed> $block
	 */
	private static function mark_grids( array &$block ): void {
		$kids = count( (array) ( $block['innerBlocks'] ?? array() ) );
		if ( $kids >= 2 && preg_match( '/(^|;)display:(inline-)?grid(;|$)/', Block_Tree::css_of( $block ) ) ) {
			$block['attrs']['__dxai_cols'] = $kids;
		}
		foreach ( $block['innerBlocks'] as &$inner ) {
			self::mark_grids( $inner );
		}
		unset( $inner );
	}

	/**
	 * A grid of text and picture whose picture went (nothing of the old page's for it) is left with its text column
	 * alone, which then runs the whole section width — lines of 180 characters. That column keeps the width the design
	 * gives its running text elsewhere (measure()), and the grid becomes one column.
	 *
	 * @param array<string, mixed> $block
	 */
	private function keep_measure( array &$block ): void {
		$was = (int) ( $block['attrs']['__dxai_cols'] ?? 0 );
		$now = count( (array) $block['innerBlocks'] );
		if ( $was >= 2 && $now >= 1 && $now < $was ) {
			$grid = Block_Tree::css_of( $block );
			// A fixed column count closes up to what is left; a grid that fits its columns to its items (auto-fit /
			// auto-fill) does so by itself.
			if ( ! preg_match( '/grid-template-columns:repeat\(auto-(fit|fill)/', $grid ) ) {
				$cols = $now === 1 ? '1fr' : 'repeat(' . $now . ',minmax(0,1fr))';
				Block_Tree::set_css( $block, Block_Tree::with_css( (string) ( $block['attrs']['dxaiCss'] ?? '' ), 'grid-template-columns', $cols ) );
			}
			if ( $now === 1 ) {
				$only = &$block['innerBlocks'][0];
				if ( ! str_contains( serialize_block( $only ), '<img' ) && ! preg_match( '/(^|;)max-width:/', Block_Tree::css_of( $only ) ) ) {
					Block_Tree::set_css( $only, Block_Tree::with_css( (string) ( $only['attrs']['dxaiCss'] ?? '' ), 'max-width', $this->measure() ) );
				}
				unset( $only );
			}
		}
		foreach ( $block['innerBlocks'] as &$inner ) {
			$this->keep_measure( $inner );
		}
		unset( $inner );
	}

	/** The design's reading width: the max-width it gives its paragraphs most often (560–900px), else 760px. */
	private function measure(): string {
		if ( $this->measure !== null ) {
			return $this->measure;
		}
		$seen = array();
		$walk = static function ( array $b ) use ( &$walk, &$seen ): void {
			if ( in_array( (string) ( $b['blockName'] ?? '' ), array( 'core/paragraph', 'dxai-ui/text', 'core/group' ), true ) && preg_match( '/(^|;)max-width:(\d{3})px/', Block_Tree::css_of( $b ), $m ) && (int) $m[2] >= 560 && (int) $m[2] <= 900 ) {
				$seen[ $m[2] ] = ( $seen[ $m[2] ] ?? 0 ) + 1;
			}
			foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $inner ) {
				$walk( $inner );
			}
		};
		foreach ( $this->library as $c ) {
			$walk( $c['block'] );
		}
		arsort( $seen );
		$this->measure = $seen !== array() ? array_key_first( $seen ) . 'px' : '760px';

		return $this->measure;
	}

	/**
	 * A grid the design lays out in a fixed number of columns at some screen width (its stylesheet targets the grid
	 * by a data attribute: `[data-svc]{grid-template-columns:repeat(2,…)}`) was made for its own item count: four cards
	 * in two rows of two. With a count that leaves a row half full — three cards, the third alone beside an empty cell —
	 * the grid loses that attribute and keeps its ordinary layout (one card under the other on a phone).
	 *
	 * @param array<string, mixed> $block
	 * @param array<int, int>      $path The grid.
	 */
	private function fit_grid_hooks( array &$block, array $path, int $count ): void {
		if ( $this->design_css === '' || $count < 2 ) {
			return;
		}
		$grid = &Block_Tree::at( $block, $path );
		foreach ( array_keys( (array) ( $grid['attrs']['dxaiData'] ?? array() ) ) as $attr ) {
			if ( ! preg_match( '/^data-[a-z0-9-]+$/i', (string) $attr ) || ! preg_match_all( '/\[' . preg_quote( (string) $attr, '/' ) . '(?:[=~|^$*][^\]]*)?\][^{]*\{[^}]*grid-template-columns\s*:\s*repeat\(\s*(\d+)\s*,/i', $this->design_css, $m ) ) {
				continue;
			}
			foreach ( $m[1] as $cols ) {
				if ( (int) $cols > 1 && $count % (int) $cols !== 0 ) {
					unset( $grid['attrs']['dxaiData'][ $attr ] );
					if ( $grid['attrs']['dxaiData'] === array() ) {
						unset( $grid['attrs']['dxaiData'] );
					}
					$strip = static fn( $part ) => is_string( $part ) ? (string) preg_replace( '/^(\s*<[a-z][a-z0-9-]*\b[^>]*?)\s' . preg_quote( (string) $attr, '/' ) . '(="[^"]*")?/i', '$1', $part, 1 ) : $part;
					$grid['innerHTML'] = $strip( (string) $grid['innerHTML'] );
					foreach ( $grid['innerContent'] as $k => $part ) {
						if ( is_string( $part ) && trim( $part ) !== '' ) {
							$grid['innerContent'][ $k ] = $strip( $part );
							break;
						}
					}
					$this->log[] = sprintf( 'grid %s: %d items do not fill its %d-column rows — its %s layout is left out', implode( '.', $path ), $count, (int) $cols, $attr );
					break;
				}
			}
		}
		unset( $grid );
	}

	/** @param array<string, mixed> $b */
	private static function is_container( array $b ): bool {
		return in_array( (string) ( $b['blockName'] ?? '' ), array( 'core/group', 'dxai-ui/box', 'core/list', 'core/columns', 'core/column', 'dxai-ui/link' ), true )
			&& strtolower( (string) ( $b['attrs']['tagName'] ?? '' ) ) !== 'img';
	}

	/**
	 * Whether a block shows nothing: no text, no image, no widget, only empty containers and icons.
	 *
	 * @param array<string, mixed> $b
	 */
	private static function is_empty( array $b ): bool {
		$name = (string) ( $b['blockName'] ?? '' );
		if ( in_array( $name, array( 'dxai-ui/image', 'core/image', 'dxai-ui/html' ), true ) || ( $name === 'dxai-ui/box' && strtolower( (string) ( $b['attrs']['tagName'] ?? '' ) ) === 'img' ) ) {
			return false;
		}
		if ( empty( $b['innerBlocks'] ) ) {
			return $name === 'dxai-ui/svg' ? true : Block_Tree::own_text( $b ) === '' && self::is_container( $b );
		}
		foreach ( $b['innerBlocks'] as $inner ) {
			if ( ! self::is_empty( $inner ) ) {
				return false;
			}
		}

		return true;
	}

	/** @param array<string, mixed> $block */
	private static function clear_marks( array &$block, array $keys = array( '__dxai_drop', '__dxai_image', '__dxai_drawing', '__dxai_cols' ) ): void {
		foreach ( $keys as $key ) {
			unset( $block['attrs'][ $key ] );
		}
		foreach ( $block['innerBlocks'] as &$inner ) {
			self::clear_marks( $inner, $keys );
		}
		unset( $inner );
	}

	/**
	 * @param array<string, mixed> $block
	 * @param array<int, int>      $path
	 */
	private static function drop_at( array &$block, array $path ): void {
		if ( $path === array() ) {
			return;
		}
		$parent = &Block_Tree::at( $block, array_slice( $path, 0, -1 ) );
		Block_Tree::drop( $parent, $path[ count( $path ) - 1 ] );
	}
}

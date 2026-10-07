<?php
/**
 * The imported Home page as a library of section components.
 *
 * Every top-level section of the Home is read for what it can hold, from its
 * block structure alone — no design-specific rules: its headings (and the
 * short label, the eyebrow, above one), paragraphs, lists, links, images, and
 * the groups of repeated items (cards, questions, reviews, figures, list
 * rows) with the slots each item has. A section is then classified by what it
 * holds (hero, cards, faq, testimonials, stats, logos, list, contact, form,
 * cta, text), which is how the composer chooses one for a piece of content.
 *
 * Paths are arrays of inner-block indexes from the section block.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

final class Section_Library {

	/**
	 * The components of a converted page, in page order. Chrome (header/footer
	 * parts or blocks) and the skip link are not components.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_page( int $page_id ): array {
		$out    = array();
		$blocks = self::flatten( parse_blocks( (string) get_post_field( 'post_content', $page_id ) ) );
		$chrome = self::chrome_blocks( $blocks );
		$skip   = array_merge( $chrome['header'], $chrome['footer'] );
		foreach ( $blocks as $i => $block ) {
			// The Home's header and footer are the site's, not sections to fill (chrome_blocks()).
			if ( empty( $block['blockName'] ) || self::is_chrome( $block ) || in_array( $i, $skip, true ) ) {
				continue;
			}
			if ( ( $block['blockName'] ?? '' ) === 'dxai-ui/link' && empty( $block['innerBlocks'] ) ) {
				continue; // the skip link
			}
			$component = self::analyze( $block, (int) $i );
			if ( $component['kind'] !== 'skip' ) {
				$out[] = $component;
			}
		}

		return $out;
	}

	/** @param array<string, mixed> $block */
	/**
	 * A block that shows a form: a form plugin's own block (Gravity Forms, WPForms, Contact Form 7, Ninja Forms,
	 * Formidable, Fluent Forms, Forminator, Jetpack), its shortcode, or raw HTML with form fields. A form is the
	 * site's working machinery, never content to copy into a section that is not a form (it came out beside
	 * every text of a page, once per section).
	 *
	 * @param array<string, mixed> $block
	 */
	public static function is_form_block( array $block ): bool {
		$name = strtolower( (string) ( $block['blockName'] ?? '' ) );
		if ( $name === '' ) {
			return false;
		}
		// This plugin's own form block, a form plugin's blocks (by namespace), and the few form blocks of bigger suites.
		if ( in_array( $name, array( 'dxai-ui/form', 'jetpack/contact-form', 'kadence/form', 'kadence/advanced-form', 'core/form' ), true )
			|| preg_match( '#^(gravityforms|wpforms|contact-form-7|ninja-forms|formidable|fluentfom|fluentform|forminator|mailchimp-for-wp|mc4wp|leadin|hubspot|srfm|sureforms|everest-forms|happyforms|weforms|metform)(/|$)#', $name ) ) {
			return true;
		}
		if ( ! in_array( $name, array( 'core/shortcode', 'core/html', 'core/freeform', 'dxai-ui/html' ), true ) ) {
			return false;
		}
		$html = (string) $block['innerHTML'] . ' ' . wp_json_encode( $block['attrs'] ?? array() );
		if ( preg_match( '#<form\b|type=[\x22\x27]submit#i', $html ) || preg_match( '/\[(gravityforms?|wpforms|contact-form-7|ninja_forms?|formidable|fluentform|forminator_form|mc4wp_form)[\s\]]/i', $html ) || preg_match( '#hbspt\.forms|jotform|typeform\.com/to/#i', $html ) ) {
			return true;
		}

		// Fields without a form element: a form only with two or more named fields, not a lone switch or select.
		return preg_match_all( '#<(input|select|textarea)\b[^>]*\bname=#i', $html ) >= 2;
	}

	/**
	 * A page written as one group around everything (its header, a `main`, its sections) holds its sections one or two
	 * levels down — a Claude Design export is written that way. They are read as the top-level blocks they are: the
	 * wrapper and a `main` around sections are opened, nothing else. A hero is not opened, nor a card: a container is
	 * opened only when at least three of its children are containers with something in them.
	 *
	 * @param array<int, array<string, mixed>> $blocks parse_blocks().
	 * @return array<int, array<string, mixed>>
	 */
	public static function flatten( array $blocks ): array {
		for ( $round = 0; $round < 3; $round++ ) {
			$real = array_values( array_filter( $blocks, static fn( $b ) => ! empty( $b['blockName'] ) ) );
			$out  = array();
			$open = false;
			foreach ( $real as $b ) {
				if ( ( count( $real ) === 1 || strtolower( (string) ( $b['attrs']['tagName'] ?? '' ) ) === 'main' ) && self::wraps_sections( $b ) ) {
					foreach ( $b['innerBlocks'] as $child ) {
						if ( ! empty( $child['blockName'] ) ) {
							$out[] = $child;
						}
					}
					$open = true;
					continue;
				}
				$out[] = $b;
			}
			if ( ! $open ) {
				break;
			}
			$blocks = $out;
		}

		return $blocks;
	}

	/** @param array<string, mixed> $b A container with at least three containers in it: a wrapper around sections. */
	public static function wraps_sections( array $b ): bool {
		if ( ! in_array( (string) ( $b['blockName'] ?? '' ), array( 'core/group', 'dxai-ui/box' ), true ) ) {
			return false;
		}
		$n = 0;
		foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $child ) {
			if ( in_array( (string) ( $child['blockName'] ?? '' ), array( 'core/group', 'dxai-ui/box' ), true ) && ! empty( $child['innerBlocks'] ) ) {
				++$n;
			}
		}

		return $n >= 3;
	}

	/**
	 * The Home's header and footer, however it keeps them: template parts, the site header/footer blocks, or the
	 * design's own blocks in its content — a leading bar (sticky, or a header or nav element) with the skip link,
	 * and a closing footer element. Returns the indexes (of parse_blocks()) of each.
	 *
	 * @param array<int, array<string, mixed>> $blocks parse_blocks() of the Home.
	 * @return array{header: array<int, int>, footer: array<int, int>}
	 */
	public static function chrome_blocks( array $blocks ): array {
		$out  = array(
			'header' => array(),
			'footer' => array(),
		);
		$real = array_keys( array_filter( $blocks, static fn( $b ) => ! empty( $b['blockName'] ) ) );
		// Leading: the header, the skip link, and the markup islands around them (an SVG sprite, a style block).
		$found = false;
		$run   = array();
		foreach ( $real as $i ) {
			$b      = $blocks[ $i ];
			$header = ( self::is_chrome( $b ) && $b['blockName'] !== 'dxai-ui/site-footer' && ! self::is_footer_part( $b ) )
				|| self::is_skip_link( $b )
				|| self::is_header_bar( $b );
			if ( ! $header && ! self::is_island( $b ) && ! self::is_header_spacer( $b ) ) {
				break;
			}
			$found = $found || $header;
			$run[] = $i;
		}
		$out['header'] = $found ? $run : array();
		// Trailing: the footer, and what floats over every page after it (a fixed call bar, a back-to-top button)
		// or supports it (markup islands).
		$found = false;
		$run   = array();
		foreach ( array_reverse( $real ) as $i ) {
			if ( in_array( $i, $out['header'], true ) ) {
				break;
			}
			$b      = $blocks[ $i ];
			$tag    = strtolower( (string) ( $b['attrs']['tagName'] ?? '' ) );
			$footer = $b['blockName'] === 'dxai-ui/site-footer' || self::is_footer_part( $b ) || $tag === 'footer' || $b['blockName'] === 'core/template-part';
			if ( ! $footer && ! self::is_island( $b ) && ! self::is_floating( $b ) ) {
				break;
			}
			$found = $found || $footer;
			$run[] = $i;
		}
		$out['footer'] = $found ? array_reverse( $run ) : array();

		return $out;
	}

	/**
	 * The Home's header and footer as markup, to open and close a page built from it: exactly the blocks the
	 * Home has (chrome_blocks()), so every page shows the Home's own header and footer, in the same form.
	 *
	 * @return array{header: string, footer: string}
	 */
	public static function chrome_markup( int $home_id ): array {
		$blocks = self::flatten( parse_blocks( (string) get_post_field( 'post_content', $home_id ) ) );
		$chrome = self::chrome_blocks( $blocks );
		$out    = array();
		foreach ( array( 'header', 'footer' ) as $area ) {
			// Links to a section of the Home ("#about") open it from any page; the skip link keeps its own target.
			$out[ $area ] = trim(
				implode(
					"\n\n",
					array_map(
						static fn( $i ) => self::is_skip_link( $blocks[ $i ] ) ? serialize_block( $blocks[ $i ] ) : Site_Pages::home_anchors( serialize_block( $blocks[ $i ] ), $home_id ),
						$chrome[ $area ]
					)
				)
			);
		}

		return $out;
	}

	/**
	 * Whether the blocks chrome_blocks() found for the header include a header: not only the skip link, the markup islands and the box that
	 * holds the place of a fixed header, which travel with one. A design whose header is a template part keeps those in its pages (the skip
	 * link is not inside its <header>), and a page that has only those has no header yet.
	 *
	 * @param array<int, array<string, mixed>> $blocks The blocks chrome_blocks() was given.
	 * @param array<int, int>                  $run    Its header indexes.
	 */
	public static function holds_header( array $blocks, array $run ): bool {
		foreach ( $run as $i ) {
			$b = $blocks[ $i ] ?? null;
			if ( is_array( $b ) && ! self::is_skip_link( $b ) && ! self::is_island( $b ) && ! self::is_header_spacer( $b ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A page's content split after the blocks that open it and travel with a header: the skip link, markup islands and the box that holds the
	 * place of a fixed header. A header added to the page goes between the two halves, where the design had it, so the skip link stays the
	 * first thing a keyboard reaches. The content comes back whole (the first half empty) when it does not open with any of them.
	 *
	 * @return array{0:string, 1:string} The opening blocks as serialized, and the rest.
	 */
	public static function split_lead( string $content ): array {
		$top  = parse_blocks( $content );
		$lead = 0;
		foreach ( $top as $i => $b ) {
			if ( empty( $b['blockName'] ) ) {
				continue;
			}
			if ( ! self::is_skip_link( $b ) && ! self::is_island( $b ) && ! self::is_header_spacer( $b ) ) {
				break;
			}
			$lead = (int) $i + 1;
		}
		if ( $lead < 1 ) {
			return array( '', $content );
		}
		$write = static fn( array $blocks ): string => trim( implode( "\n\n", array_map( 'serialize_block', array_values( array_filter( $blocks, static fn( $b ) => ! empty( $b['blockName'] ) ) ) ) ) );

		return array( $write( array_slice( $top, 0, $lead ) ), $write( array_slice( $top, $lead ) ) );
	}

	/**
	 * The opening blocks of a page (what split_lead() takes) by kind, each as serialized, in their order: `skip` (the skip link), `spacer`
	 * (the box that holds the place of a fixed header) and `other` (markup islands).
	 *
	 * @return array<int, array{kind:string, markup:string}>
	 */
	public static function lead_blocks( string $lead ): array {
		$out = array();
		foreach ( parse_blocks( $lead ) as $b ) {
			if ( empty( $b['blockName'] ) ) {
				continue;
			}
			$out[] = array(
				'kind'   => self::is_skip_link( $b ) ? 'skip' : ( self::is_header_spacer( $b ) ? 'spacer' : 'other' ),
				'markup' => serialize_block( $b ),
			);
		}

		return $out;
	}

	/** @param array<string, mixed> $b A template part of the footer area (by its area, or its slug). */
	private static function is_footer_part( array $b ): bool {
		if ( ( $b['blockName'] ?? '' ) !== 'core/template-part' ) {
			return false;
		}
		$area = strtolower( (string) ( $b['attrs']['area'] ?? '' ) );

		return $area === 'footer' || ( $area === '' && str_contains( strtolower( (string) ( $b['attrs']['slug'] ?? '' ) ), 'footer' ) );
	}

	/** @param array<string, mixed> $b The skip link: a lone link to the main content. */
	private static function is_skip_link( array $b ): bool {
		return ( $b['blockName'] ?? '' ) === 'dxai-ui/link' && empty( $b['innerBlocks'] ) && preg_match( '#href="\#[^"]*"#', (string) $b['innerHTML'] ) === 1;
	}

	/** @param array<string, mixed> $b The design's own header bar in the content: a header or nav element, or a bar stuck to the top. */
	private static function is_header_bar( array $b ): bool {
		// Never a block with a page heading: a hero written as <header>, or a sticky parallax hero, is content.
		$markup = serialize_block( $b );
		if ( preg_match( '#<h[12]\b#i', $markup ) ) {
			return false;
		}
		$tag = strtolower( (string) ( $b['attrs']['tagName'] ?? '' ) );
		if ( in_array( $tag, array( 'header', 'nav' ), true ) ) {
			return true;
		}
		if ( ! in_array( (string) ( $b['blockName'] ?? '' ), array( 'core/group', 'dxai-ui/box' ), true ) || $tag === 'section' ) {
			return false;
		}
		$css = Block_Tree::css_of( $b );
		if ( str_contains( $css, 'position:sticky' ) || str_contains( $css, 'position:fixed' ) ) {
			return true;
		}

		// A bar holding the site's navigation (a nav element or a menu block).
		return (bool) preg_match( '#<nav\b|<!-- wp:navigation\b|<!-- wp:dxai-ui/site-header#i', $markup );
	}

	/** @param array<string, mixed> $b Markup that shows nothing of its own: a style or script block, an SVG sprite. */
	private static function is_island( array $b ): bool {
		if ( ! in_array( (string) ( $b['blockName'] ?? '' ), array( 'dxai-ui/html', 'core/html' ), true ) ) {
			return false;
		}
		$html = trim( (string) preg_replace( '#<(style|script)\b[\s\S]*?</\1>|<svg\b[^>]*>\s*(<defs\b[\s\S]*?</defs>|<symbol\b[\s\S]*?</symbol>\s*)+\s*</svg>#i', '', (string) $b['innerHTML'] ) );

		return $html === '' || wp_strip_all_tags( $html ) === '' && ! preg_match( '#<(img|video|iframe|a)\b#i', $html );
	}

	/** @param array<string, mixed> $b An empty box that holds the place of a fixed header (a <div aria-hidden="true" style="height:140px">): hidden from readers, nothing in it. */
	private static function is_header_spacer( array $b ): bool {
		return in_array( (string) ( $b['blockName'] ?? '' ), array( 'dxai-ui/box', 'core/group', 'core/spacer' ), true )
			&& empty( $b['innerBlocks'] )
			&& preg_match( '#aria-hidden="true"#i', (string) ( $b['innerHTML'] ?? '' ) ) === 1
			&& trim( wp_strip_all_tags( (string) ( $b['innerHTML'] ?? '' ) ) ) === '';
	}

	/** @param array<string, mixed> $b A box fixed over the page (a call bar, a back-to-top button) with no page heading. */
	private static function is_floating( array $b ): bool {
		return in_array( (string) ( $b['blockName'] ?? '' ), array( 'core/group', 'dxai-ui/box', 'dxai-ui/link' ), true )
			&& str_contains( Block_Tree::css_of( $b ), 'position:fixed' )
			&& ! preg_match( '#<h[12]\b#i', serialize_block( $b ) );
	}

	private static function is_chrome( array $block ): bool {
		$name = (string) $block['blockName'];

		return in_array( $name, array( 'core/template-part', 'dxai-ui/site-header', 'dxai-ui/site-footer', 'core/navigation' ), true );
	}

	/**
	 * One section: its slots, repeated groups, panels and kind.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	public static function analyze( array $block, int $index ): array {
		$slots   = array();
		$repeats = array();
		self::walk( $block, array(), $slots, $repeats, false );

		// Slots inside a repeated item belong to the item, not the section.
		$in_item = static function ( array $path ) use ( $repeats ): bool {
			foreach ( $repeats as $r ) {
				if ( count( $path ) > count( $r['path'] ) && array_slice( $path, 0, count( $r['path'] ) ) === $r['path'] && in_array( $path[ count( $r['path'] ) ], $r['items'], true ) ) {
					return true;
				}
			}
			return false;
		};
		$own = array_values( array_filter( $slots, static fn( $s ) => ! $in_item( $s['path'] ) ) );

		foreach ( $repeats as &$r ) {
			$r['shape'] = self::item_shape( $block, $r );
			$r['kind']  = self::item_kind( $r['shape'] );
		}
		unset( $r );

		$panels = self::panels( $own );
		$kind   = self::kind( $own, $repeats, $panels );

		return array(
			'index'    => $index,
			'anchor'   => (string) ( $block['attrs']['anchor'] ?? '' ),
			'kind'     => $kind,
			'block'    => $block,
			'slots'    => $own,
			'panels'   => $panels,
			'repeats'  => array_values( array_filter( $repeats, static fn( $r ) => ! $r['disclosure'] ) ),
			// What a "show more" button reveals: the items that continue a list shown above it.
			'hidden'   => array_values( array_filter( $repeats, static fn( $r ) => $r['disclosure'] ) ),
			'controls' => array_values( array_map( static fn( $s ) => $s['path'], array_filter( $own, static fn( $s ) => $s['type'] === 'control' ) ) ),
			'images'   => array_values( array_filter( $own, static fn( $s ) => $s['type'] === 'image' ) ),
			'widgets'  => array_values( array_filter( $own, static fn( $s ) => $s['type'] === 'widget' ) ),
		);
	}

	/**
	 * Collect slots and repeated groups below $block.
	 *
	 * @param array<string, mixed>              $block
	 * @param array<int, int>                   $path
	 * @param array<int, array<string, mixed>>  $slots
	 * @param array<int, array<string, mixed>>  $repeats
	 */
	private static function walk( array $block, array $path, array &$slots, array &$repeats, bool $inside_item ): void {
		$type = self::slot_type( $block );
		if ( $type !== null ) {
			$slots[] = array(
				'path'  => $path,
				'type'  => $type,
				'name'  => (string) $block['blockName'],
				'level' => self::heading_level( $block ),
				'tag'   => (string) ( $block['attrs']['tagName'] ?? '' ),
				'text'  => Block_Tree::own_text( $block ),
				'decor' => self::is_decor( $block ),
				'flags' => self::flags( $block ),
				'parts' => $type === 'text' ? self::parts( (string) $block['innerHTML'] ) : array(),
			);
			if ( $type !== 'card' && $type !== 'list' ) {
				return;
			}
		}
		$inner = (array) ( $block['innerBlocks'] ?? array() );
		if ( count( $inner ) >= 2 ) {
			foreach ( self::runs( $inner, (string) $block['blockName'] === 'core/list' ) as $run ) {
				$repeats[] = array(
					'path'       => $path,
					'items'      => $run,
					'disclosure' => self::is_disclosure( $block ),
				);
			}
		}
		foreach ( $inner as $i => $child ) {
			self::walk( $child, array_merge( $path, array( (int) $i ) ), $slots, $repeats, $inside_item );
		}
	}

	/**
	 * What kind of slot a block is, or null for a plain container.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function slot_type( array $block ): ?string {
		if ( self::is_form_block( $block ) ) {
			return 'widget';
		}
		$name  = (string) $block['blockName'];
		$attrs = (array) $block['attrs'];
		$tag   = strtolower( (string) ( $attrs['tagName'] ?? '' ) );
		$class = (string) ( $attrs['className'] ?? '' );
		switch ( $name ) {
			case 'core/heading':
				return 'heading';
			case 'core/paragraph':
			case 'core/list-item':
				return 'text';
			case 'core/list':
				return 'list';
			case 'core/button':
				return 'link';
			case 'core/image':
			case 'dxai-ui/image':
			case 'dx/picture':
				return 'image';
			case 'dxai-ui/svg':
				return 'icon';
			case 'dxai-ui/link':
				return empty( $block['innerBlocks'] ) ? 'link' : 'card';
			case 'dxai-ui/text':
				return preg_match( '/^h[1-6]$/', $tag ) ? 'heading' : 'text';
			case 'dxai-ui/html':
				$html = (string) $block['innerHTML'];
				// A form was caught above; fields or buttons alone (a play button, a price switch) are controls, and
				// never make their section a form section.
				if ( preg_match( '#<(input|select|textarea|button)\b#i', $html ) ) {
					return 'control';
				}
				return preg_match( '#^\s*<(blockquote|p|span|q|cite)\b#i', $html ) ? 'text' : 'widget';
			case 'dxai-ui/box':
				if ( $tag === 'img' ) {
					return 'image';
				}
				if ( $tag === 'button' || str_contains( $class, 'dxai-toggle-' ) ) {
					return 'control';
				}
				return null;
		}

		return null;
	}

	/**
	 * The tags of the element children inside a text block's own element (li > p, h3, p), decorations excluded.
	 *
	 * @return array<int, string>
	 */
	private static function parts( string $html ): array {
		if ( preg_match( '#^\s*<([a-z][\w-]*)\b[^>]*>(.*)</\1>\s*$#is', $html, $m ) !== 1 ) {
			return array();
		}
		preg_match_all( '#<(p|h[1-6]|div|span|strong)\b(?![^>]*aria-hidden)[^>]*>#i', $m[2], $tags );

		return array_map( 'strtolower', $tags[1] );
	}

	/** @param array<string, mixed> $block */
	private static function heading_level( array $block ): int {
		if ( ( $block['blockName'] ?? '' ) === 'core/heading' ) {
			return (int) ( $block['attrs']['level'] ?? 2 );
		}
		if ( preg_match( '/^h([1-6])$/', strtolower( (string) ( $block['attrs']['tagName'] ?? '' ) ), $m ) ) {
			return (int) $m[1];
		}

		return 0;
	}

	/** @param array<string, mixed> $block */
	private static function is_decor( array $block ): bool {
		$attrs = (array) $block['attrs'];
		if ( ( $attrs['ariaHidden'] ?? '' ) === 'true' ) {
			return true;
		}

		return (bool) preg_match( '#^\s*<[^>]*\baria-hidden="true"#', (string) $block['innerHTML'] );
	}

	/**
	 * Responsive duplicates the design shows on one breakpoint only (data-mob / data-desk).
	 *
	 * @param array<string, mixed> $block
	 * @return array<int, string>
	 */
	private static function flags( array $block ): array {
		$data = (array) ( $block['attrs']['dxaiData'] ?? array() );

		return array_values( array_intersect( array( 'data-mob', 'data-desk' ), array_keys( $data ) ) );
	}

	/**
	 * A box revealed by a toggle button (the design's "show more"): its items
	 * continue a list shown elsewhere.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function is_disclosure( array $block ): bool {
		return str_contains( (string) ( $block['attrs']['className'] ?? '' ), 'dxai-on-' );
	}

	/**
	 * Runs of 2+ consecutive siblings with the same structure.
	 *
	 * @param array<int, array<string, mixed>> $inner
	 * @return array<int, array<int, int>>
	 */
	private static function runs( array $inner, bool $list = false ): array {
		$leaf = array( 'core/paragraph', 'core/heading', 'dxai-ui/text', 'dxai-ui/html' );
		$sigs = array_map(
			static fn( $b ) => ! $list && in_array( (string) ( $b['blockName'] ?? '' ), $leaf, true ) ? '' : self::signature( $b ),
			$inner
		);
		$runs = array();
		$cur  = array( 0 );
		for ( $i = 1, $n = count( $sigs ); $i <= $n; $i++ ) {
			if ( $i < $n && $sigs[ $i ] === $sigs[ $cur[0] ] && $sigs[ $i ] !== '' ) {
				$cur[] = $i;
				continue;
			}
			if ( count( $cur ) >= 2 ) {
				$runs[] = $cur;
			}
			$cur = array( $i );
		}

		return $runs;
	}

	/**
	 * A block's structure: names and tags, recursively, without text or styling.
	 *
	 * @param array<string, mixed> $block
	 */
	public static function signature( array $block ): string {
		$name = (string) ( $block['blockName'] ?? '' );
		if ( $name === '' ) {
			return '';
		}
		$tag  = (string) ( $block['attrs']['tagName'] ?? '' );
		$kids = array_map( array( self::class, 'signature' ), (array) ( $block['innerBlocks'] ?? array() ) );

		return $name . ( $tag !== '' ? '[' . $tag . ']' : '' ) . ( $kids ? '(' . implode( ',', $kids ) . ')' : '' );
	}

	/**
	 * The slots of a repeated item, from its first plain copy.
	 *
	 * @param array<string, mixed> $section
	 * @param array<string, mixed> $repeat
	 * @return array<string, mixed>
	 */
	private static function item_shape( array $section, array $repeat ): array {
		$parent   = Block_Tree::at( $section, $repeat['path'] );
		$template = $repeat['items'][0];
		foreach ( $repeat['items'] as $i ) {
			if ( self::flags( $parent['innerBlocks'][ $i ] ) === array() ) {
				$template = $i;
				break;
			}
		}
		$item  = $parent['innerBlocks'][ $template ];
		$slots = array();
		$none  = array();
		self::walk( $item, array(), $slots, $none, true );

		return array(
			'count'    => count( $repeat['items'] ),
			'template' => $template,
			'root'     => self::slot_type( $item ),
			'tag'      => (string) ( $item['attrs']['tagName'] ?? '' ),
			'slots'    => $slots,
		);
	}

	/** @param array<string, mixed> $shape */
	private static function item_kind( array $shape ): string {
		$types = array_column( $shape['slots'], 'type' );
		$tags  = array_map( 'strtolower', array_column( $shape['slots'], 'tag' ) );
		$texts = array_values( array_filter( $shape['slots'], static fn( $s ) => in_array( $s['type'], array( 'text', 'heading' ), true ) && ! $s['decor'] ) );
		if ( $shape['tag'] === 'details' || in_array( 'summary', $tags, true ) ) {
			return 'faq';
		}
		if ( $shape['tag'] === 'figure' || in_array( 'figcaption', $tags, true ) ) {
			return 'testimonial';
		}
		if ( in_array( 'address', $tags, true ) ) {
			return 'contact';
		}
		if ( ( $shape['root'] ?? '' ) === 'text' ) {
			$parts = (array) ( $shape['slots'][0]['parts'] ?? array() );
			return preg_grep( '/^h[1-6]$/', $parts ) ? 'step' : 'row'; // a list item
		}
		if ( $texts === array() && in_array( 'image', $types, true ) ) {
			return ( $shape['count'] ?? 0 ) >= 3 ? 'logo' : 'gallery';
		}
		if ( ( $shape['root'] ?? '' ) === 'link' ) {
			return 'button';
		}
		if ( in_array( 'heading', $types, true ) ) {
			return 'card';
		}
		if ( count( $texts ) >= 2 && preg_match( '/\d/', (string) $texts[0]['text'] ) && mb_strlen( (string) $texts[0]['text'] ) <= 14 ) {
			return 'stat';
		}
		if ( count( $texts ) >= 1 ) {
			return 'card';
		}

		return 'other';
	}

	/**
	 * Headings outside repeated items, each with its label and the text after it.
	 *
	 * @param array<int, array<string, mixed>> $own
	 * @return array<int, array<string, mixed>>
	 */
	private static function panels( array $own ): array {
		$panels = array();
		$last   = null;
		foreach ( $own as $i => $s ) {
			if ( $s['type'] === 'heading' ) {
				$eyebrow = null;
				// A short line right before the heading is its label (eyebrow).
				for ( $j = $i - 1; $j >= 0; $j-- ) {
					$p = $own[ $j ];
					if ( $p['type'] === 'icon' || $p['decor'] ) {
						continue;
					}
					if ( $p['type'] === 'text' && mb_strlen( (string) $p['text'] ) <= 60 && ( $last === null || $j > $last['at'] ) ) {
						$eyebrow = $p['path'];
					}
					break;
				}
				$panels[] = array(
					'at'      => $i,
					'heading' => $s['path'],
					'level'   => $s['level'],
					'eyebrow' => $eyebrow,
					'texts'   => array(),
					'lists'   => array(),
					'links'   => array(),
					'images'  => array(),
				);
				$last     = &$panels[ count( $panels ) - 1 ];
				continue;
			}
			if ( $last === null ) {
				// Before the first heading: only a label candidate; the rest belongs to panel 0 later.
				continue;
			}
			if ( $last['eyebrow'] !== null && $s['path'] === $last['eyebrow'] ) {
				continue;
			}
			switch ( $s['type'] ) {
				case 'text':
					if ( ! $s['decor'] && ! self::is_eyebrow_of_next( $own, $i ) ) {
						$last['texts'][] = $s['path'];
					}
					break;
				case 'list':
					$last['lists'][] = $s['path'];
					break;
				case 'link':
					$last['links'][] = $s['path'];
					break;
				case 'image':
					if ( ! $s['decor'] ) {
						$last['images'][] = $s['path'];
					}
					break;
			}
		}
		unset( $last );

		return $panels;
	}

	/**
	 * Whether the text slot at $i is the eyebrow of a heading that follows it.
	 *
	 * @param array<int, array<string, mixed>> $own
	 */
	private static function is_eyebrow_of_next( array $own, int $i ): bool {
		for ( $j = $i + 1, $n = count( $own ); $j < $n; $j++ ) {
			if ( $own[ $j ]['type'] === 'icon' || $own[ $j ]['decor'] ) {
				continue;
			}
			return $own[ $j ]['type'] === 'heading' && mb_strlen( (string) $own[ $i ]['text'] ) <= 60;
		}

		return false;
	}

	/**
	 * @param array<int, array<string, mixed>> $own
	 * @param array<int, array<string, mixed>> $repeats
	 * @param array<int, array<string, mixed>> $panels
	 */
	private static function kind( array $own, array $repeats, array $panels ): string {
		$levels = array_column( array_filter( $own, static fn( $s ) => $s['type'] === 'heading' ), 'level' );
		if ( in_array( 1, $levels, true ) ) {
			return 'hero';
		}
		$main = array_values( array_filter( $repeats, static fn( $r ) => ! $r['disclosure'] ) );
		$kinds = array_column( $main, 'kind' );
		foreach ( array( 'faq', 'testimonial', 'contact', 'card', 'step', 'stat', 'logo' ) as $k ) {
			if ( in_array( $k, $kinds, true ) ) {
				return array( 'faq' => 'faq', 'testimonial' => 'testimonials', 'contact' => 'contact', 'card' => 'cards', 'step' => 'steps', 'stat' => 'stats', 'logo' => 'logos' )[ $k ];
			}
		}
		if ( array_filter( $own, static fn( $s ) => $s['type'] === 'widget' ) ) {
			return 'form';
		}
		if ( $panels === array() ) {
			if ( array_filter( $own, static fn( $s ) => $s['type'] === 'link' ) ) {
				return 'cta';
			}

			return self::is_badges( $own ) ? 'badges' : 'skip';
		}
		$texts = array_sum( array_map( static fn( $p ) => count( $p['texts'] ), $panels ) );
		$links = array_sum( array_map( static fn( $p ) => count( $p['links'] ), $panels ) );
		$imgs  = count( array_filter( $own, static fn( $s ) => $s['type'] === 'image' && ! $s['decor'] ) );
		if ( in_array( 'row', $kinds, true ) && $texts <= 2 && $imgs === 0 ) {
			return 'list';
		}
		if ( $links >= 1 && $texts <= 2 && $imgs === 0 ) {
			return 'cta';
		}

		return 'text';
	}

	/**
	 * A row of short claims with no heading and no link (a bar of badges: "Licensed", "Same-day service", "4.9 from 1,240 reviews"):
	 * a run of at least three spans in one text, or at least three short texts of their own.
	 *
	 * @param array<int, array<string, mixed>> $own
	 */
	private static function is_badges( array $own ): bool {
		$texts = array_values( array_filter( $own, static fn( $s ) => $s['type'] === 'text' && empty( $s['decor'] ) && trim( (string) $s['text'] ) !== '' ) );
		if ( $texts === array() || array_filter( $own, static fn( $s ) => in_array( $s['type'], array( 'heading', 'image', 'list', 'widget', 'control' ), true ) && empty( $s['decor'] ) ) ) {
			return false;
		}
		if ( array_sum( array_map( static fn( $s ) => mb_strlen( (string) $s['text'] ), $texts ) ) > 260 ) {
			return false;
		}
		foreach ( $texts as $s ) {
			if ( count( array_filter( (array) $s['parts'], static fn( $p ) => $p === 'span' ) ) >= 3 ) {
				return true;
			}
		}

		return count( array_filter( $texts, static fn( $s ) => mb_strlen( (string) $s['text'] ) <= 48 ) ) >= 3 && count( array_filter( $texts, static fn( $s ) => mb_strlen( (string) $s['text'] ) > 48 ) ) === 0;
	}

	/**
	 * A one-line description per component, for the CLI and the logs.
	 *
	 * @param array<string, mixed> $c
	 */
	public static function describe( array $c ): string {
		$reps = array_map( static fn( $r ) => $r['kind'] . '×' . count( $r['items'] ), $c['repeats'] );
		$pan  = array_map( static fn( $p ) => 'h' . $p['level'] . ( $p['eyebrow'] ? '+eyebrow' : '' ) . ' t' . count( $p['texts'] ) . ( $p['lists'] ? ' list' : '' ) . ( $p['links'] ? ' l' . count( $p['links'] ) : '' ) . ( $p['images'] ? ' i' . count( $p['images'] ) : '' ), $c['panels'] );
		$img  = count( $c['images'] );

		return sprintf( '#%d %-12s %-14s panels[%s] repeats[%s] images %d%s', $c['index'], $c['kind'], $c['anchor'] !== '' ? '#' . $c['anchor'] : '', implode( ' | ', $pan ), implode( ', ', $reps ), $img, $c['widgets'] ? ' widget' : '' );
	}
}

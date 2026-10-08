<?php
/**
 * The Design IR: one document that says what a design is, whatever it came from.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Design;

use DXAI_UI\Pages\Section_Roles;

/**
 * Everything the plugin knows about a design, as one record (docs/PLAN-ARCHITECTURE.md, section 4): where it came from, its pages
 * and their sections, its header and footer and the navigation in them, its tokens and fonts, its breakpoints, its assets, what it
 * does (behaviours) and what the conversion could not carry (report). The connectors and the compiler fill it; everything after them
 * is meant to read it instead of reading the compiled HTML and the blocks again, each for itself.
 *
 * Three rules. (1) It holds facts about the DESIGN, not about the site: a page's post id is an annotation (`pages[].id`), not a key,
 * and the fingerprint leaves it out. (2) Every part says where it came from (`source`: `design` read from the source itself,
 * `compiled` derived from the compiled HTML or blocks, `heuristic` guessed, `menus` read from Appearance > Menus), so the document
 * never claims more than it knows. (3) A document that cannot be built never fails an import (Document_Store::record()).
 *
 * The shape, version 1:
 *
 *   v            1
 *   source       { kind, name, stack, static_html, hash, origin (the host of the design's own site, '' when it has none or it is this site) }
 *   title, home_slug
 *   pages[]      { slug, title, file, id, words, sections[], sections_source: compiled|saved, sections_match (as many as the compiler's
 *                  structures, so each knows which it came from), source_sections[], forms, links[], meta }
 *     sections[] { index, role, kind, name, heading, words, repeats, items[] (what the repeated items are called), has: { image, form, button, video },
 *                  sig, source_name (the component or structure the compiler made it from), layout: { columns: { base|sm|md|lg|xl|2xl: n },
 *                  direction, centered, max_width } | null (read off the design's own classes; null when it has none that say) }
 *     links[]    { url, internal }
 *     meta       { title, description }
 *   regions      { header: Region|null, footer: Region|null }
 *     Region     { present, placed: content|part|menus|widgets|none, nav[], nav_source, logo: { src, alt }|null, actions[] { label, url } }
 *     nav[]      { label, url, description, children[] }
 *   tokens       { source: theme|css-vars|heuristic|default|…, roles: { brand, accent, ink, body, muted, surface, border, radius, font },
 *                  palette[], fonts[] { family, weights[], url, files }, screens: { name: px }, screens_declared[] }
 *   breakpoints[] px
 *   assets       { images, svg, videos, fonts, files, list[] { url, alt } }
 *   behaviours[] { kind, count, compiled: true|false|null, note }
 *   layout       null (version 1; the layout tree is phase 1б of the plan)
 *   report       { unevaluated[] { kind, source, count }, notes[], coverage[] { dimension, source, page, short, informational },
 *                  css: { bytes, rules, literals } (the design's own stylesheet — what the presets have not taken over yet — and how many
 *                  colours its blocks still write as literals; the budget the plan's phase 2 measures),
 *                  bound: { text: { preset, custom, class }, background: { preset, custom, class } } (how the pages' blocks carry their
 *                  colours: as a preset of the site, as the block's custom colour, or still as a class the design's stylesheet rules) }
 *   built        { plugin, compiler, at }
 */
final class Document {

	public const VERSION = 1;

	/** Where a header or a footer of the design ended up on the site. */
	public const PLACED = array( 'content', 'part', 'menus', 'widgets', 'none' );

	/** What a design does, as the kinds the compiler and the coverage count. */
	public const BEHAVIOURS = array( 'handlers', 'motion', 'radix', 'scroll', 'lucide', 'media-query', 'countup', 'form' );

	/** The keys of the design part of the document, in the order they are written. */
	private const KEYS = array( 'v', 'source', 'title', 'home_slug', 'pages', 'regions', 'nav', 'tokens', 'breakpoints', 'assets', 'behaviours', 'layout', 'report', 'built' );

	/** @param array<string, mixed> $data */
	private function __construct( private array $data ) {}

	/**
	 * A document from its array form, with every part present (empty where it was not given). Nothing is checked here: problems()
	 * says what is wrong with one.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function from_array( array $data ): self {
		$out = array(
			'v'           => (int) ( $data['v'] ?? self::VERSION ),
			'source'      => array_merge(
				array(
					'kind'        => '',
					'name'        => '',
					'stack'       => '',
					'static_html' => false,
					'hash'        => '',
					'origin'      => '',
				),
				is_array( $data['source'] ?? null ) ? $data['source'] : array()
			),
			'title'       => (string) ( $data['title'] ?? '' ),
			'home_slug'   => (string) ( $data['home_slug'] ?? '/' ),
			'pages'       => array_values( array_filter( is_array( $data['pages'] ?? null ) ? $data['pages'] : array(), 'is_array' ) ),
			'regions'     => array(
				'header' => is_array( $data['regions']['header'] ?? null ) ? $data['regions']['header'] : null,
				'footer' => is_array( $data['regions']['footer'] ?? null ) ? $data['regions']['footer'] : null,
			),
			'nav'         => array_values( is_array( $data['nav'] ?? null ) ? $data['nav'] : array() ),
			'tokens'      => array_merge(
				array(
					'source'           => '',
					'roles'            => array(),
					'palette'          => array(),
					'fonts'            => array(),
					'screens'          => array(),
					'screens_declared' => array(),
				),
				is_array( $data['tokens'] ?? null ) ? $data['tokens'] : array()
			),
			'breakpoints' => array_values( array_map( 'intval', is_array( $data['breakpoints'] ?? null ) ? $data['breakpoints'] : array() ) ),
			'assets'      => array_merge(
				array(
					'images' => 0,
					'svg'    => 0,
					'videos' => 0,
					'fonts'  => 0,
					'files'  => 0,
					'list'   => array(),
				),
				is_array( $data['assets'] ?? null ) ? $data['assets'] : array()
			),
			'behaviours'  => array_values( array_filter( is_array( $data['behaviours'] ?? null ) ? $data['behaviours'] : array(), 'is_array' ) ),
			'layout'      => is_array( $data['layout'] ?? null ) ? $data['layout'] : null,
			'report'      => array_merge(
				array(
					'unevaluated' => array(),
					'notes'       => array(),
					'coverage'    => array(),
					'css'         => array(
						'bytes'    => 0,
						'rules'    => 0,
						'literals' => 0,
					),
					'bound'       => array(
						'text'       => array( 'preset' => 0, 'custom' => 0, 'class' => 0 ),
						'background' => array( 'preset' => 0, 'custom' => 0, 'class' => 0 ),
					),
				),
				is_array( $data['report'] ?? null ) ? $data['report'] : array()
			),
			'built'       => array_merge(
				array(
					'plugin'   => '',
					'compiler' => '',
					'at'       => '',
				),
				is_array( $data['built'] ?? null ) ? $data['built'] : array()
			),
		);

		return new self( $out );
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		$out = array();
		foreach ( self::KEYS as $key ) {
			$out[ $key ] = $this->data[ $key ];
		}

		return $out;
	}

	public function to_json(): string {
		$json = wp_json_encode( $this->to_array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * The same document with a few parts changed (a shallow merge of the top-level keys given).
	 *
	 * @param array<string, mixed> $patch
	 */
	public function with( array $patch ): self {
		return self::from_array( array_merge( $this->data, $patch ) );
	}

	/**
	 * What is wrong with a document's array form, as sentences; nothing wrong is an empty list. Read by the store before it writes and
	 * by the suite; a document the builder made passes it.
	 *
	 * @param array<string, mixed> $data
	 * @return array<int, string>
	 */
	public static function problems( array $data ): array {
		$out = array();
		if ( (int) ( $data['v'] ?? 0 ) !== self::VERSION ) {
			$out[] = sprintf( 'the version is %s, this plugin writes %d', (string) ( $data['v'] ?? 'none' ), self::VERSION );
		}
		if ( trim( (string) ( $data['title'] ?? '' ) ) === '' ) {
			$out[] = 'the design has no title';
		}
		$kind = (string) ( $data['source']['kind'] ?? '' );
		if ( $kind === '' ) {
			$out[] = 'the source has no kind';
		}
		$hash = (string) ( $data['source']['hash'] ?? '' );
		if ( preg_match( '/^[0-9a-f]{40}$/', $hash ) !== 1 ) {
			$out[] = 'the source hash is not a sha1';
		}
		$pages = is_array( $data['pages'] ?? null ) ? $data['pages'] : array();
		if ( $pages === array() ) {
			$out[] = 'the design has no pages';
		}
		$slugs = array();
		foreach ( $pages as $i => $page ) {
			if ( ! is_array( $page ) ) {
				$out[] = "page $i is not a record";
				continue;
			}
			$slug = (string) ( $page['slug'] ?? '' );
			if ( $slug === '' || $slug[0] !== '/' ) {
				$out[] = "page $i has no address starting with /";
			}
			if ( isset( $slugs[ $slug ] ) ) {
				$out[] = "two pages are at $slug";
			}
			$slugs[ $slug ] = true;
			foreach ( is_array( $page['sections'] ?? null ) ? $page['sections'] : array() as $j => $s ) {
				if ( ! is_array( $s ) || ! in_array( (string) ( $s['role'] ?? '' ), Section_Roles::ROLES, true ) ) {
					$out[] = sprintf( 'section %s of %s has no role the pages know (%s)', (string) $j, $slug, is_array( $s ) ? (string) ( $s['role'] ?? '' ) : 'not a record' );
				}
			}
		}
		if ( ! isset( $slugs[ (string) ( $data['home_slug'] ?? '/' ) ] ) && $pages !== array() ) {
			$out[] = 'the home address is not one of the pages';
		}
		foreach ( array( 'header', 'footer' ) as $area ) {
			$region = $data['regions'][ $area ] ?? null;
			if ( $region === null ) {
				continue;
			}
			if ( ! is_array( $region ) || ! in_array( (string) ( $region['placed'] ?? '' ), self::PLACED, true ) ) {
				$out[] = "the $area says it is placed somewhere the document does not know";
			}
		}
		foreach ( is_array( $data['behaviours'] ?? null ) ? $data['behaviours'] : array() as $b ) {
			if ( ! is_array( $b ) || ! in_array( (string) ( $b['kind'] ?? '' ), self::BEHAVIOURS, true ) ) {
				$out[] = 'a behaviour has a kind the document does not know: ' . ( is_array( $b ) ? (string) ( $b['kind'] ?? '' ) : 'not a record' );
			}
		}
		$roles = is_array( $data['tokens']['roles'] ?? null ) ? $data['tokens']['roles'] : array();
		foreach ( array( 'brand', 'accent', 'ink', 'body' ) as $role ) {
			if ( isset( $roles[ $role ] ) && preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $roles[ $role ] ) !== 1 ) {
				$out[] = "the $role colour is not a hex colour";
			}
		}
		foreach ( is_array( $data['breakpoints'] ?? null ) ? $data['breakpoints'] : array() as $px ) {
			if ( (int) $px < 200 || (int) $px > 4000 ) {
				$out[] = 'a breakpoint is not a width of a screen: ' . (string) $px;
			}
		}

		return $out;
	}

	/**
	 * What the design IS, hashed: the same design built twice gives the same fingerprint, whatever site it was imported on, before
	 * or after a save, and whenever. It is the hash of identity(): the facts of the design alone.
	 */
	public function fingerprint(): string {
		$json = wp_json_encode( $this->identity(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return sha1( is_string( $json ) ? $json : '' );
	}

	/**
	 * The facts of the design alone, in a fixed shape: its source hash and title, its pages (address, title, each section's role,
	 * kind, heading, words, how many items it repeats and what they are called, the forms, how many links), its navigation (labels and addresses), its
	 * tokens (roles, fonts, declared screens), its breakpoints, how many assets of each kind, and what it does (kind and count).
	 *
	 * Left out, being the site's or the conversion's and not the design's: the posts' ids, where the header and the footer were
	 * put and where the navigation was read from, the names the import gives sections and their block signatures (the import
	 * rewrites blocks: a link becomes a button, a box a group), whether a behaviour reached the page, the coverage, the notes, and
	 * when and by what the document was built.
	 *
	 * @return array<string, mixed>
	 */
	public function identity(): array {
		$pages = array();
		foreach ( $this->data['pages'] as $page ) {
			$sections = array();
			foreach ( is_array( $page['sections'] ?? null ) ? $page['sections'] : array() as $s ) {
				$sections[] = array(
					'role'    => (string) ( $s['role'] ?? '' ),
					'kind'    => (string) ( $s['kind'] ?? '' ),
					'heading' => (string) ( $s['heading'] ?? '' ),
					'words'   => (int) ( $s['words'] ?? 0 ),
					'repeats' => (int) ( $s['repeats'] ?? 0 ),
					'items'   => array_values( array_map( 'strval', is_array( $s['items'] ?? null ) ? $s['items'] : array() ) ),
				);
			}
			$pages[] = array(
				'slug'     => (string) ( $page['slug'] ?? '' ),
				'title'    => (string) ( $page['title'] ?? '' ),
				'sections' => $sections,
				'forms'    => (int) ( $page['forms'] ?? 0 ),
				'links'    => count( is_array( $page['links'] ?? null ) ? $page['links'] : array() ),
			);
		}
		$nav = static function ( array $items ) use ( &$nav ): array {
			$out = array();
			foreach ( $items as $item ) {
				$out[] = array(
					'label'    => (string) ( $item['label'] ?? '' ),
					'url'      => (string) ( $item['url'] ?? '' ),
					'children' => $nav( is_array( $item['children'] ?? null ) ? $item['children'] : array() ),
				);
			}

			return $out;
		};
		$behaviours = array();
		foreach ( $this->data['behaviours'] as $b ) {
			$behaviours[ (string) ( $b['kind'] ?? '' ) ] = (int) ( $b['count'] ?? 0 );
		}
		ksort( $behaviours );
		$assets = $this->data['assets'];
		unset( $assets['list'] );

		return array(
			'hash'        => (string) ( $this->data['source']['hash'] ?? '' ),
			'origin'      => (string) ( $this->data['source']['origin'] ?? '' ),
			'title'       => $this->data['title'],
			'home_slug'   => $this->data['home_slug'],
			'pages'       => $pages,
			'nav'         => $nav( $this->data['nav'] ),
			'tokens'      => array(
				'roles'   => $this->data['tokens']['roles'],
				'fonts'   => array_map( static fn( array $f ): string => (string) ( $f['family'] ?? '' ), $this->data['tokens']['fonts'] ),
				'screens' => $this->data['tokens']['screens_declared'],
			),
			'breakpoints' => $this->data['breakpoints'],
			'assets'      => $assets,
			'behaviours'  => $behaviours,
		);
	}

	/**
	 * One part of the document, by key.
	 *
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed {
		return $this->data[ $key ] ?? $default;
	}

	/** @return array<int, array<string, mixed>> */
	public function pages(): array {
		return $this->data['pages'];
	}

	/** @return array<string, mixed>|null The page at the home address. */
	public function home(): ?array {
		foreach ( $this->data['pages'] as $page ) {
			if ( (string) ( $page['slug'] ?? '' ) === $this->data['home_slug'] ) {
				return $page;
			}
		}

		return $this->data['pages'][0] ?? null;
	}

	/**
	 * The document in numbers, for a list or a line of a command.
	 *
	 * @return array<string, mixed>
	 */
	public function summary(): array {
		$sections = 0;
		foreach ( $this->data['pages'] as $page ) {
			$sections += count( is_array( $page['sections'] ?? null ) ? $page['sections'] : array() );
		}
		$left = 0;
		foreach ( $this->data['behaviours'] as $b ) {
			if ( ( $b['compiled'] ?? null ) === false ) {
				++$left;
			}
		}

		return array(
			'title'           => $this->data['title'],
			'source'          => $this->data['source']['kind'],
			'pages'           => count( $this->data['pages'] ),
			'sections'        => $sections,
			'nav_items'       => count( $this->data['nav'] ),
			'header'          => $this->data['regions']['header']['placed'] ?? 'none',
			'footer'          => $this->data['regions']['footer']['placed'] ?? 'none',
			'tokens'          => $this->data['tokens']['source'],
			'fonts'           => count( $this->data['tokens']['fonts'] ),
			'breakpoints'     => $this->data['breakpoints'],
			'images'          => (int) $this->data['assets']['images'],
			'behaviours'      => count( $this->data['behaviours'] ),
			'behaviours_left' => $left,
			'unevaluated'     => count( $this->data['report']['unevaluated'] ),
			'fingerprint'     => $this->fingerprint(),
		);
	}
}

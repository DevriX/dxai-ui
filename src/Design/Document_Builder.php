<?php
/**
 * Builds a design's document from what the compiler produced and what the import saved.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Design;

use DXAI_UI\Chrome\Header_Template;
use DXAI_UI\Compiler\Design_Tokens;
use DXAI_UI\Compiler\Menu_Tree;
use DXAI_UI\Compiler\Tailwind\Theme;
use DXAI_UI\Pages\Menu_Pages;
use DXAI_UI\Verification\Design_Coverage;

/**
 * One builder for every source. It reads the compile result (Source_Compiler::compile(): the pages, their structures and their
 * compiled markup, the design's CSS, the harvest, the compiler's own report) and, when the import has been saved, what was saved
 * (Structure_Repository::save(): the posts' ids, where the header and the footer went, the menus). Each part of the document says
 * where it came from — see Document.
 *
 * Nothing here reads the source archive: by the time the admin import saves, it is gone (the browser sent the compile result
 * back). What only the source can say travels in the result: `source_kind`, `source_hash`, `source_sections` (Lovable's split
 * of a page file), `dc_breakpoint` (Claude Design), `source_signals` (what the design's code declares).
 */
final class Document_Builder {

	/** Which coverage dimension answers whether a behaviour reached the page (Design_Coverage). */
	private const COVERAGE_OF = array(
		'handlers' => 'behaviour-markers',
		'scroll'   => 'scroll-state',
		'lucide'   => 'inline-svg',
		'motion'   => 'keyframes',
	);

	/**
	 * @param array<string, mixed> $result  Source_Compiler::compile(), as Structure_Repository::save() receives it.
	 * @param array<string, mixed> $created Structure_Repository::save()'s answer; empty before a save.
	 */
	public static function from_result( array $result, array $created = array() ): Document {
		$home  = (int) ( $created['page_id'] ?? 0 );
		$notes = array();
		$pages = self::pages( $result, $created, $notes );
		$slug  = self::path( (string) ( $result['slug'] ?? '/' ) );

		$coverage   = $home > 0 ? self::coverage( $result, $home, $notes ) : array();
		$structures = is_array( $result['structures'] ?? null ) ? $result['structures'] : array();
		$regions    = array(
			'header' => self::region( 'header', $structures, $created, $home, $notes ),
			'footer' => self::region( 'footer', $structures, $created, $home, $notes ),
		);

		return Document::from_array(
			array(
				'v'           => Document::VERSION,
				'source'      => self::source( $result ),
				'title'       => trim( (string) ( $result['block_title'] ?? '' ) ),
				'home_slug'   => $slug,
				'pages'       => $pages,
				'regions'     => $regions,
				'nav'         => is_array( $regions['header']['nav'] ?? null ) ? $regions['header']['nav'] : array(),
				'tokens'      => self::tokens( $result, $home ),
				'breakpoints' => self::breakpoints( $result ),
				'assets'      => self::assets( $result ),
				'behaviours'  => self::behaviours( $result, $coverage ),
				'layout'      => null,
				'report'      => array(
					'unevaluated' => array_values( array_filter( is_array( $result['unevaluated'] ?? null ) ? $result['unevaluated'] : array(), 'is_array' ) ),
					'notes'       => array_values( array_unique( array_merge( array_map( 'strval', is_array( $result['compiler_notes'] ?? null ) ? $result['compiler_notes'] : array() ), $notes ) ) ),
					'coverage'    => $coverage,
				),
				'built'       => array(
					'plugin'   => defined( 'DXAI_UI_VERSION' ) ? (string) DXAI_UI_VERSION : '',
					'compiler' => (string) ( $result['compiler'] ?? '' ),
					'at'       => gmdate( 'c' ),
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $result
	 * @return array<string, mixed>
	 */
	private static function source( array $result ): array {
		$hash = (string) ( $result['source_hash'] ?? '' );
		if ( preg_match( '/^[0-9a-f]{40}$/', $hash ) !== 1 ) {
			// An older result, or one saved by a caller that did not carry the hash: the compiled design stands in for the source.
			$hash = sha1( (string) ( $result['source_html'] ?? '' ) . "\n" . (string) ( $result['design_css_raw'] ?? '' ) );
		}
		// A result saved before the compiler recorded its connector (a conversion snapshot of an older import) cannot say which it was.
		$kind = sanitize_key( (string) ( $result['source_kind'] ?? '' ) );
		if ( $kind === '' ) {
			$kind = 'unknown';
		}

		return array(
			'kind'        => $kind,
			'name'        => wp_strip_all_tags( wp_basename( (string) ( $result['source_name'] ?? '' ) ) ),
			'stack'       => sanitize_text_field( (string) ( $result['source_stack'] ?? '' ) ),
			'static_html' => ! empty( $result['static_html'] ),
			'hash'        => $hash,
		);
	}

	/**
	 * The home page and the other pages the compiler made, in that order.
	 *
	 * @param array<string, mixed> $result
	 * @param array<string, mixed> $created
	 * @param array<int, string>   $notes
	 * @return array<int, array<string, mixed>>
	 */
	private static function pages( array $result, array $created, array &$notes ): array {
		$home_id = (int) ( $created['page_id'] ?? 0 );
		$extra   = array_map( 'intval', is_array( $created['extra_page_ids'] ?? null ) ? $created['extra_page_ids'] : array() );
		$by_path = array();
		foreach ( $extra as $id ) {
			if ( $id > 0 ) {
				$by_path[ self::path( (string) get_page_uri( $id ) ) ] = $id;
			}
		}
		$rows   = array();
		$rows[] = self::page(
			(string) ( $result['slug'] ?? '/' ),
			(string) ( $result['block_title'] ?? '' ),
			(string) ( $result['source_file'] ?? '' ),
			(string) ( $result['gutenberg_markup'] ?? '' ),
			(string) ( $result['source_html'] ?? '' ),
			is_array( $result['structures'] ?? null ) ? $result['structures'] : array(),
			is_array( $result['source_sections'] ?? null ) ? $result['source_sections'] : array(),
			$home_id,
			(string) ( $result['seo_title'] ?? '' )
		);
		foreach ( is_array( $result['pages'] ?? null ) ? $result['pages'] : array() as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$slug   = self::path( (string) ( $page['slug'] ?? '' ) );
			$rows[] = self::page(
				$slug,
				(string) ( $page['block_title'] ?? $page['title'] ?? '' ),
				(string) ( $page['file'] ?? '' ),
				(string) ( $page['gutenberg_markup'] ?? '' ),
				(string) ( $page['source_html'] ?? '' ),
				is_array( $page['structures'] ?? null ) ? $page['structures'] : array(),
				is_array( $page['source_sections'] ?? null ) ? $page['source_sections'] : array(),
				(int) ( $by_path[ $slug ] ?? 0 ),
				''
			);
		}
		if ( $home_id > 0 && count( $by_path ) !== count( $extra ) ) {
			$notes[] = 'a page the import made has no address the document knows';
		}

		return $rows;
	}

	/**
	 * @param array<int, array<string, mixed>> $structures
	 * @param array<int, mixed>                $source_sections
	 * @return array<string, mixed>
	 */
	private static function page( string $slug, string $title, string $file, string $markup, string $source_html, array $structures, array $source_sections, int $id, string $seo_title ): array {
		// The page's own blocks: the compiler's structures without the ones it typed as the header, the footer or the navigation
		// (the import takes those out of the page the same way), so a footer followed by a bar fixed to the screen is not read as a
		// section the way a markup-only reading would read it. A result with no structures is read from its markup.
		$parts = array();
		foreach ( $structures as $s ) {
			if ( is_array( $s ) && isset( $s['gutenberg_markup'] ) && ! in_array( (string) ( $s['type'] ?? '' ), array( 'header', 'footer', 'navigation' ), true ) ) {
				$parts[] = (string) $s['gutenberg_markup'];
			}
		}
		$body = $parts !== array() ? implode( "\n\n", $parts ) : trim( $markup );
		$from = 'compiled';
		// Once the page is saved, its sections are read from the saved post — the reading the team pages and the quality gates use
		// (Section_Library::for_page()), after whatever the import did to the blocks (native blocks open the design's wrappers) —
		// so the document and the pages agree on what a section is. Before a save, from the compiler's structures.
		if ( $id > 0 ) {
			$saved = (string) get_post_field( 'post_content', $id );
			if ( trim( $saved ) !== '' ) {
				$body = $saved;
				$from = 'saved';
			}
		}
		$sections = Section_Reader::from_markup( $body );
		$markup   = $body;
		$forms    = 0;
		foreach ( $structures as $s ) {
			if ( is_array( $s ) && (string) ( $s['type'] ?? '' ) === 'form' ) {
				++$forms;
			}
		}
		$from_source = array();
		foreach ( $source_sections as $s ) {
			$label = is_array( $s ) ? (string) ( $s['title'] ?? $s['id'] ?? '' ) : (string) $s;
			if ( trim( $label ) !== '' ) {
				$from_source[] = sanitize_text_field( $label );
			}
		}

		return array(
			'slug'            => self::path( $slug ),
			'title'           => sanitize_text_field( $title ),
			'file'            => sanitize_text_field( $file ),
			'id'              => $id,
			'words'           => Section_Reader::words( $markup ),
			'sections'        => $sections,
			'sections_source' => $from,
			'source_sections' => $from_source,
			'forms'           => $forms,
			'links'           => self::links( $source_html !== '' ? $source_html : $markup ),
			'meta'            => array(
				'title'       => sanitize_text_field( $seo_title ),
				'description' => '',
			),
		);
	}

	/**
	 * The header or the footer: whether the design has one, where the import put it, and the navigation in it.
	 *
	 * @param array<int, array<string, mixed>> $structures
	 * @param array<string, mixed>             $created
	 * @param array<int, string>               $notes
	 * @return array<string, mixed>|null
	 */
	private static function region( string $area, array $structures, array $created, int $home, array &$notes ): ?array {
		$own = null;
		foreach ( $structures as $s ) {
			if ( is_array( $s ) && (string) ( $s['type'] ?? '' ) === $area ) {
				$own = $s;
				break;
			}
		}
		$installed = '' !== (string) ( $created[ 'chrome' ][ $area ]['block_markup'] ?? '' );
		$part      = (int) ( $created['chrome_parts'][ $area ] ?? 0 ) > 0 || (int) ( $created[ $area . '_id' ] ?? 0 ) > 0;
		if ( $own === null && ! $installed && ! $part ) {
			return null;
		}
		$html = (string) ( $own['source_html'] ?? '' );
		$nav  = array();
		$from = '';
		if ( $area === 'header' && $home > 0 && Header_Template::scoped( $home ) !== null ) {
			$menus = Menu_Pages::tree_of( $home, false ); // never the document itself: that is what is being built
			if ( ( $menus['tree'] ?? array() ) !== array() ) {
				$nav  = self::nav_items( $menus['tree'] );
				$from = 'menus';
			}
		}
		if ( $nav === array() && $own !== null ) {
			$tree = is_array( $own['menu_tree'] ?? null ) ? $own['menu_tree'] : array();
			if ( $tree === array() && $html !== '' ) {
				$tree = Menu_Tree::from_html( $html );
			}
			$nav  = self::nav_items( $tree );
			$from = $nav === array() ? '' : 'compiled';
		}
		if ( $area === 'header' && $nav === array() ) {
			$notes[] = 'the header has no navigation the document could read';
		}
		$placed = 'content';
		if ( $installed ) {
			$placed = $area === 'header' ? 'menus' : 'widgets';
		} elseif ( $part ) {
			$placed = 'part';
		}

		return array(
			'present'    => true,
			'placed'     => $placed,
			'nav'        => $nav,
			'nav_source' => $from,
			'logo'       => self::logo( $html ),
			'actions'    => self::actions( $html, $nav ),
		);
	}

	/**
	 * A navigation tree in the document's shape, whatever reader made it.
	 *
	 * @param array<int, mixed> $tree
	 * @return array<int, array{label:string, url:string, description:string, children:array<int, mixed>}>
	 */
	private static function nav_items( array $tree, int $depth = 0 ): array {
		$out = array();
		foreach ( $tree as $node ) {
			if ( ! is_array( $node ) || $depth > 4 ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $node['label'] ?? $node['title'] ?? '' ) );
			$url   = trim( (string) ( $node['url'] ?? '' ) );
			if ( $label === '' && $url === '' ) {
				continue;
			}
			$out[] = array(
				'label'       => $label,
				'url'         => $url,
				'description' => sanitize_text_field( (string) ( $node['description'] ?? '' ) ),
				'children'    => self::nav_items( is_array( $node['children'] ?? null ) ? $node['children'] : array(), $depth + 1 ),
			);
		}

		return $out;
	}

	/** @return array{src:string, alt:string}|null The first picture of the header: its logo, most of the time. */
	private static function logo( string $html ): ?array {
		if ( $html === '' || preg_match( '#<img\b([^>]*)>#i', $html, $m ) !== 1 ) {
			return null;
		}
		preg_match( '#\bsrc="([^"]*)"#i', $m[1], $src );
		preg_match( '#\balt="([^"]*)"#i', $m[1], $alt );

		return array(
			'src' => esc_url_raw( (string) ( $src[1] ?? '' ) ),
			'alt' => sanitize_text_field( html_entity_decode( (string) ( $alt[1] ?? '' ), ENT_QUOTES, 'UTF-8' ) ),
		);
	}

	/**
	 * The links of a header that are not its navigation: a phone number, a call to action.
	 *
	 * @param array<int, array<string, mixed>> $nav
	 * @return array<int, array{label:string, url:string}>
	 */
	private static function actions( string $html, array $nav ): array {
		if ( $html === '' ) {
			return array();
		}
		$in_nav = array();
		$walk   = static function ( array $items ) use ( &$walk, &$in_nav ): void {
			foreach ( $items as $item ) {
				$in_nav[ strtolower( trim( (string) ( $item['url'] ?? '' ) ) ) . '|' . strtolower( (string) ( $item['label'] ?? '' ) ) ] = true;
				$walk( is_array( $item['children'] ?? null ) ? $item['children'] : array() );
			}
		};
		$walk( $nav );
		$out = array();
		if ( preg_match_all( '#<a\b([^>]*)>(.*?)</a>#is', $html, $links, PREG_SET_ORDER ) ) {
			foreach ( $links as $link ) {
				preg_match( '#\bhref="([^"]*)"#i', $link[1], $href );
				$url   = trim( (string) ( $href[1] ?? '' ) );
				$label = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $link[2] ), ENT_QUOTES, 'UTF-8' ) ) );
				$key   = strtolower( $url ) . '|' . strtolower( $label );
				$cta   = preg_match( '#^(tel:|mailto:|sms:)#i', $url ) === 1 || preg_match( '#\b(btn|button|cta)\b#i', $link[1] ) === 1;
				if ( $label === '' || isset( $in_nav[ $key ] ) || ! $cta || count( $out ) >= 6 ) {
					continue;
				}
				$out[] = array(
					'label' => sanitize_text_field( $label ),
					'url'   => $url,
				);
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $result
	 * @return array<string, mixed>
	 */
	private static function tokens( array $result, int $home ): array {
		$raw     = (string) ( $result['design_css_raw'] ?? '' );
		$harvest = is_array( $result['design_assets'] ?? null ) ? $result['design_assets'] : array();
		$roles   = $home > 0
			? Design_Tokens::for_page( $home )
			: Design_Tokens::resolve( $raw, (string) ( $result['source_html'] ?? '' ), is_array( $harvest['tokens'] ?? null ) ? $harvest['tokens'] : array() );
		$source  = (string) ( $roles['source'] ?? '' );
		unset( $roles['source'] );
		$roles = array_map( 'strval', $roles );

		$palette = array();
		if ( preg_match_all( '/#([0-9a-fA-F]{6})\b/', $raw, $m ) ) {
			$seen = array_count_values( array_map( 'strtoupper', $m[1] ) );
			arsort( $seen );
			foreach ( array_slice( array_keys( $seen ), 0, 32 ) as $hex ) {
				$palette[] = '#' . $hex;
			}
		}

		// One row a family: the harvest lists a family once per stylesheet that loads it (Hind twice for two weights).
		$fonts = array();
		foreach ( is_array( $harvest['fonts'] ?? null ) ? $harvest['fonts'] : array() as $font ) {
			if ( ! is_array( $font ) ) {
				continue;
			}
			$family = sanitize_text_field( (string) ( $font['family'] ?? '' ) );
			if ( $family === '' ) {
				continue;
			}
			$weights = array_values( array_map( 'strval', is_array( $font['weights'] ?? null ) ? $font['weights'] : array() ) );
			$files   = count( is_array( $font['files'] ?? null ) ? $font['files'] : array() );
			$url     = esc_url_raw( (string) ( $font['url'] ?? $font['stylesheet'] ?? '' ) );
			if ( isset( $fonts[ $family ] ) ) {
				$fonts[ $family ]['weights'] = array_values( array_unique( array_merge( $fonts[ $family ]['weights'], $weights ) ) );
				$fonts[ $family ]['files']  += $files;
				if ( $fonts[ $family ]['url'] === '' ) {
					$fonts[ $family ]['url'] = $url;
				}
				continue;
			}
			$fonts[ $family ] = array(
				'family'  => $family,
				'weights' => $weights,
				'url'     => $url,
				'files'   => $files,
			);
		}
		$fonts = array_values( $fonts );

		$screens  = array();
		$declared = array();
		if ( empty( $result['static_html'] ) && ( $raw !== '' || (string) ( $result['tailwind_config'] ?? '' ) !== '' ) ) {
			$theme = Theme::from_css( $raw, (string) ( $result['tailwind_config'] ?? '' ) );
			foreach ( $theme->screens() as $name => $value ) {
				$px = self::px( (string) $value );
				if ( $px > 0 ) {
					$screens[ (string) $name ] = $px;
					if ( $theme->declares( 'breakpoint', (string) $name ) ) {
						$declared[] = (string) $name;
					}
				}
			}
		}

		return array(
			'source'           => $source,
			'roles'            => $roles,
			'palette'          => $palette,
			'fonts'            => $fonts,
			'screens'          => $screens,
			'screens_declared' => $declared,
		);
	}

	/**
	 * The widths the design changes its layout at: the Tailwind screens it declares (not the scale's defaults, which every Tailwind
	 * design "has"), a Claude Design component's `matchMedia()` width, and the `@media` widths its own CSS names.
	 *
	 * @param array<string, mixed> $result
	 * @return array<int, int>
	 */
	private static function breakpoints( array $result ): array {
		$out = array();
		$dc  = (int) ( $result['dc_breakpoint'] ?? 0 );
		if ( $dc > 0 ) {
			$out[] = $dc;
		}
		$raw = (string) ( $result['design_css_raw'] ?? '' );
		if ( empty( $result['static_html'] ) && $raw !== '' ) {
			$theme = Theme::from_css( $raw, (string) ( $result['tailwind_config'] ?? '' ) );
			foreach ( $theme->screens() as $name => $value ) {
				if ( $theme->declares( 'breakpoint', (string) $name ) ) {
					$out[] = self::px( (string) $value );
				}
			}
		}
		if ( preg_match_all( '/@media[^{]*\((?:min|max)-width:\s*([0-9.]+)(px|rem|em)\)/i', $raw, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $q ) {
				$out[] = self::px( $q[1] . $q[2] );
			}
		}
		$out = array_values( array_unique( array_filter( array_map( 'intval', $out ), static fn( int $px ): bool => $px >= 200 && $px <= 4000 ) ) );
		sort( $out );

		return $out;
	}

	/**
	 * @param array<string, mixed> $result
	 * @return array<string, mixed>
	 */
	private static function assets( array $result ): array {
		$harvest = is_array( $result['design_assets'] ?? null ) ? $result['design_assets'] : array();
		$images  = is_array( $harvest['images'] ?? null ) ? $harvest['images'] : array();
		$list    = array();
		foreach ( array_slice( $images, 0, 200 ) as $image ) {
			if ( ! is_array( $image ) ) {
				continue;
			}
			$url = (string) ( $image['url'] ?? $image['path'] ?? $image['original'] ?? '' );
			if ( $url === '' ) {
				continue;
			}
			$list[] = array(
				'url' => $url,
				'alt' => sanitize_text_field( (string) ( $image['alt'] ?? '' ) ),
			);
		}
		$html = (string) ( $result['source_html'] ?? '' );

		return array(
			'images' => count( $images ),
			'svg'    => substr_count( $html, '<svg' ),
			'videos' => count( is_array( $harvest['videos'] ?? null ) ? $harvest['videos'] : array() ),
			'fonts'  => count( is_array( $harvest['fonts'] ?? null ) ? $harvest['fonts'] : array() ),
			'files'  => count( is_array( $result['required_media'] ?? null ) ? $result['required_media'] : array() ),
			'list'   => $list,
		);
	}

	/**
	 * What the design does, counted where its code declares it (source_signals) and where the compiler made it, and — once the
	 * pages are saved — whether it reached them (the coverage row that answers for it).
	 *
	 * @param array<string, mixed>             $result
	 * @param array<int, array<string, mixed>> $coverage
	 * @return array<int, array{kind:string, count:int, compiled:bool|null, note:string}>
	 */
	private static function behaviours( array $result, array $coverage ): array {
		$signals = is_array( $result['source_signals'] ?? null ) ? $result['source_signals'] : array();
		$rows    = array();
		foreach ( array( 'handlers', 'motion', 'radix', 'scroll', 'lucide' ) as $kind ) {
			$count = (int) ( $signals[ $kind ] ?? 0 );
			if ( $count > 0 ) {
				$rows[ $kind ] = array( $count, 'declared by the design\'s code' );
			}
		}
		$raw   = (string) ( $result['design_css_raw'] ?? '' );
		$media = (int) ( $result['dc_breakpoint'] ?? 0 ) > 0 ? 1 : 0;
		$media += preg_match_all( '/@media\b/i', $raw );
		if ( $media > 0 ) {
			$rows['media-query'] = array( $media, 'a layout that changes with the width' );
		}
		$markup = (string) ( $result['gutenberg_markup'] ?? '' );
		foreach ( is_array( $result['pages'] ?? null ) ? $result['pages'] : array() as $page ) {
			$markup .= "\n" . (string) ( is_array( $page ) ? ( $page['gutenberg_markup'] ?? '' ) : '' );
		}
		$countups = substr_count( $markup, 'wp:dxai-ui/countup' );
		if ( $countups > 0 ) {
			$rows['countup'] = array( $countups, 'a number that counts up' );
		}
		$forms = 0;
		foreach ( is_array( $result['structures'] ?? null ) ? $result['structures'] : array() as $s ) {
			if ( is_array( $s ) && (string) ( $s['type'] ?? '' ) === 'form' ) {
				++$forms;
			}
		}
		if ( $forms > 0 ) {
			$rows['form'] = array( $forms, 'a form the design draws' );
		}
		$by_dimension = array();
		foreach ( $coverage as $row ) {
			if ( is_array( $row ) && isset( $row['dimension'] ) ) {
				$by_dimension[ (string) $row['dimension'] ] = $row;
			}
		}
		$out = array();
		foreach ( $rows as $kind => $pair ) {
			$compiled  = null;
			$dimension = self::COVERAGE_OF[ $kind ] ?? '';
			if ( $dimension !== '' && isset( $by_dimension[ $dimension ] ) && empty( $by_dimension[ $dimension ]['unknown'] ) ) {
				$compiled = empty( $by_dimension[ $dimension ]['short'] );
			}
			$out[] = array(
				'kind'     => (string) $kind,
				'count'    => (int) $pair[0],
				'compiled' => $compiled,
				'note'     => (string) $pair[1],
			);
		}

		return $out;
	}

	/**
	 * Design_Coverage's rows for the saved home, kept small: what each counted on both sides and whether the page is short.
	 *
	 * @param array<string, mixed> $result
	 * @param array<int, string>   $notes
	 * @return array<int, array{dimension:string, source:int|null, page:int, short:bool, informational:bool}>
	 */
	private static function coverage( array $result, int $home, array &$notes ): array {
		try {
			$measured = ( new Design_Coverage() )->measure( $result, $home );
		} catch ( \Throwable $e ) {
			$notes[] = 'the coverage could not be measured: ' . $e->getMessage();

			return array();
		}
		$out = array();
		foreach ( is_array( $measured['rows'] ?? null ) ? $measured['rows'] : array() as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['dimension'] ) ) {
				continue;
			}
			$out[] = array(
				'dimension'     => (string) $row['dimension'],
				'source'        => isset( $row['source'] ) && $row['source'] !== null ? (int) $row['source'] : null,
				'page'          => (int) ( $row['page'] ?? 0 ),
				'short'         => ! empty( $row['short'] ),
				'informational' => ! empty( $row['informational'] ),
				'unknown'       => ! empty( $row['unknown'] ),
			);
		}

		return $out;
	}

	/**
	 * The links a page makes, and whether each stays on the design's own site (relative, or a place on the page).
	 *
	 * @return array<int, array{url:string, internal:bool}>
	 */
	private static function links( string $html ): array {
		$out  = array();
		$seen = array();
		if ( preg_match_all( '#<a\b[^>]*\bhref="([^"]+)"#i', $html, $m ) ) {
			foreach ( $m[1] as $url ) {
				$url = trim( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );
				if ( $url === '' || isset( $seen[ $url ] ) || count( $out ) >= 200 ) {
					continue;
				}
				$seen[ $url ] = true;
				$internal     = $url[0] === '/' || $url[0] === '#' || preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) !== 1;
				$out[]        = array(
					'url'      => $url,
					'internal' => $internal,
				);
			}
		}

		return $out;
	}

	/** A page address as the document writes it: one leading slash, one trailing, the home as `/`. */
	private static function path( string $slug ): string {
		$slug = trim( $slug );
		$slug = trim( (string) wp_parse_url( $slug, PHP_URL_PATH ) ?: $slug, '/' );

		return $slug === '' ? '/' : '/' . $slug . '/';
	}

	/** A CSS length in pixels (rem and em at 16px), 0 when it is not one. */
	private static function px( string $value ): int {
		if ( preg_match( '/^\s*([0-9.]+)\s*(px|rem|em)?\s*$/i', $value, $m ) !== 1 ) {
			return 0;
		}
		$n = (float) $m[1];

		return (int) round( strtolower( (string) ( $m[2] ?? 'px' ) ) === 'px' ? $n : $n * 16 );
	}
}

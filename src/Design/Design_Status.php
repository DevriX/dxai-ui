<?php
/**
 * Where a design is, in one record: the facts the Designs list and a design's page show as chips, and what to do next.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Design;

use DXAI_UI\Blocks\Interactivity;
use DXAI_UI\Blocks\Native\Native_Blocks;
use DXAI_UI\Content\Content_Types;
use DXAI_UI\Pages\Menu_Pages;
use DXAI_UI\Pages\Team_Pages;
use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Theme\Theme_Binding;
use DXAI_UI\Theme\Theme_Fonts;
use DXAI_UI\Theme\Theme_Palette;
use DXAI_UI\Transfer\Transfer_Admin;

/**
 * The status is read, never stored: each fact comes from the record that owns it (the native pass, the theme binding, the
 * theme fonts, the design's document, the pages of the design, the Interactivity setting), so a panel that changes one of
 * them changes the status with it. The one record of its own is the time a design's package was last downloaded
 * (EXPORTED_META on its Home), written where the file leaves (the admin download, the REST export, the CLI).
 *
 * The vocabulary is the panel's one glossary (docs/PLAN-ADMIN-UX.md, section 7): imported (when, by which version),
 * native (applied or not), colours (follow or keep), fonts (theme or own), interactivity (the site's setting and the
 * design's states), exported (when). next() turns the same facts into at most three things to do, each naming the tab
 * where it is done.
 */
final class Design_Status {

	/** On a design's Home: when its package was last downloaded (a Unix time). */
	public const EXPORTED_META = '_dxai_ui_exported_at';

	/** The keys of()'s answer always has, so a screen can rely on them. */
	public const KEYS = array( 'document', 'source', 'archive', 'imported_at', 'built_with', 'sections', 'native', 'colours', 'palette', 'fonts', 'fonts_managed', 'states', 'interactivity', 'exported_at', 'pages', 'team_pages', 'site_pages', 'home', 'edit', 'export' );

	/** The suggestions next() can make, in the order they are made. */
	public const NEXT = array( 'document', 'native', 'colours', 'fonts', 'menu-pages', 'export' );

	/**
	 * Where a design is.
	 *
	 * @param int  $home  The design's Home page id.
	 * @param bool $items With the pages of the design listed (the design's page), or their counts alone (the list of designs).
	 * @return array<string, mixed>
	 */
	public static function of( int $home, bool $items = false ): array {
		$doc    = Document_Store::load( $home );
		$pages  = self::pages( $home );
		$source = $doc !== null && is_array( $doc->get( 'source' ) ) ? $doc->get( 'source' ) : array();
		$built  = $doc !== null && is_array( $doc->get( 'built' ) ) ? $doc->get( 'built' ) : array();
		$states = 0;
		foreach ( $doc !== null && is_array( $doc->get( 'behaviours' ) ) ? $doc->get( 'behaviours' ) : array() as $b ) {
			if ( is_array( $b ) && ( $b['kind'] ?? '' ) === 'state' ) {
				++$states;
			}
		}
		$exported = (int) get_post_meta( $home, self::EXPORTED_META, true );
		$out      = array(
			'document'      => $doc !== null,
			'source'        => (string) ( $source['kind'] ?? '' ),
			'archive'       => (string) get_post_meta( $home, '_dxai_ui_source_zip', true ),
			'imported_at'   => self::imported_at( $home ),
			'built_with'    => (string) ( $built['plugin'] ?? '' ),
			'sections'      => $doc !== null ? (int) ( $doc->summary()['sections'] ?? 0 ) : 0,
			'native'        => Native_Blocks::applied( $home ),
			'colours'       => Theme_Binding::follows( $home ) ? 'follow' : 'keep',
			'palette'       => (bool) Theme_Palette::current()['has_palette'],
			'fonts'         => Theme_Fonts::managed() ? ( Theme_Fonts::follows( $home ) ? 'theme' : 'own' ) : 'none',
			'fonts_managed' => Theme_Fonts::managed(),
			'states'        => $states,
			'interactivity' => Interactivity::enabled(),
			'exported_at'   => $exported > 0 ? gmdate( 'c', $exported ) : '',
			'pages'         => count( $pages ),
			'team_pages'    => count( array_filter( $pages, static fn( array $p ): bool => $p['kind'] === 'team' ) ),
			'site_pages'    => count( array_filter( $pages, static fn( array $p ): bool => $p['kind'] === 'site' ) ),
			'home'          => (string) get_permalink( $home ),
			'edit'          => (string) get_edit_post_link( $home, 'raw' ),
			'export'        => Transfer_Admin::allowed() ? Transfer_Admin::export_url( $home ) : '',
		);
		if ( $items ) {
			$out['items']       = $pages;
			$out['conversions'] = self::conversions( $home );
		}

		return $out;
	}

	/**
	 * The conversion snapshots of a design's Home, newest first: every import keeps one (Structure_Repository::save_conversion()).
	 *
	 * @return array<int, array{id:int, title:string, date:string}>
	 */
	public static function conversions( int $home ): array {
		$out = array();
		foreach ( get_posts(
			array(
				'post_type'      => Content_Types::CONVERSION,
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'meta_key'       => '_dxai_ui_page_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $home, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		) as $post ) {
			$date  = get_post_time( 'c', true, $post );
			$out[] = array(
				'id'    => (int) $post->ID,
				'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'date'  => is_string( $date ) ? $date : '',
			);
		}

		return $out;
	}

	/**
	 * What to do next with a design, at most three things, each saying where: nothing when the design is where it can be.
	 *
	 * The rules, in order: a design with no document gets one (the report and the pages from the menu read it); blocks the
	 * theme has its own for become them; a theme with a palette gives the design its colours; a theme that manages the fonts
	 * gives the design its fonts; a header that names pages gets them made; and a design that is ready goes to the live site.
	 * A suggestion is made only where the thing is not done and can be done here (a theme without a palette is never asked
	 * to give its colours).
	 *
	 * @return array<int, array{key:string, title:string, why:string, tab:string}>
	 */
	public static function next( int $home ): array {
		$status = self::of( $home );
		$out    = array();
		if ( ! $status['document'] ) {
			$out[] = array(
				'key'   => 'document',
				'title' => __( 'Build the design’s document', 'dxai-ui' ),
				'why'   => __( 'The design was imported before documents were kept. The report, the pages from its menu and the style variation read it.', 'dxai-ui' ),
				'tab'   => 'overview',
			);
		}
		if ( ! $status['native'] ) {
			$summary = Native_Blocks::summary( $home );
			if ( (int) ( $summary['blocks'] ?? 0 ) > 0 ) {
				$out[] = array(
					'key'   => 'native',
					'title' => __( 'Apply native blocks', 'dxai-ui' ),
					'why'   => sprintf(
						/* translators: %d: number of blocks. */
						_n( '%d block of the design can be the theme’s own block, editable with the theme’s settings.', '%d blocks of the design can be the theme’s own blocks, editable with the theme’s settings.', (int) $summary['blocks'], 'dxai-ui' ),
						(int) $summary['blocks']
					),
					'tab'   => 'blocks',
				);
			}
		}
		if ( $status['palette'] && $status['colours'] === 'keep' ) {
			$out[] = array(
				'key'   => 'colours',
				'title' => __( 'Follow the theme’s colours', 'dxai-ui' ),
				'why'   => __( 'The theme has a palette; the design keeps its own colours until it is bound to it.', 'dxai-ui' ),
				'tab'   => 'look',
			);
		}
		if ( $status['fonts'] === 'own' ) {
			$out[] = array(
				'key'   => 'fonts',
				'title' => __( 'Use the theme’s fonts', 'dxai-ui' ),
				'why'   => __( 'The theme manages the site’s fonts; the design loads its own.', 'dxai-ui' ),
				'tab'   => 'speed',
			);
		}
		if ( $status['document'] && $status['team_pages'] === 0 && self::menu_names_pages( $home ) ) {
			$out[] = array(
				'key'   => 'menu-pages',
				'title' => __( 'Make the pages the menu names', 'dxai-ui' ),
				'why'   => __( 'The design’s header links to pages that are not there yet. They are made in the Home’s look, at the menu’s own addresses.', 'dxai-ui' ),
				'tab'   => 'pages',
			);
		}
		if ( $status['exported_at'] === '' && $status['export'] !== '' ) {
			$out[] = array(
				'key'   => 'export',
				'title' => __( 'Export for the live site', 'dxai-ui' ),
				'why'   => __( 'Nothing of this design has been packaged yet. A package carries the page with its styles, pictures, header and footer; the live site’s DX imports it in one step.', 'dxai-ui' ),
				'tab'   => 'transfer',
			);
		}

		return array_slice( $out, 0, 3 );
	}

	/** Note that a design's package left the site, so the list says when. By a page of the design or its Home. */
	public static function exported( int $page_id ): void {
		$home = Document_Store::home_of( $page_id );
		if ( $home > 0 ) {
			update_post_meta( $home, self::EXPORTED_META, time() );
		}
	}

	/**
	 * The pages of a design: its Home and every page made for it (in its scope), with what each one is.
	 *
	 * @return array<int, array{id:int, title:string, kind:string, status:string, url:string, edit:string}>
	 */
	public static function pages( int $home ): array {
		$ids = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_key'       => Page_Scope::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $home, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$ids = array_values( array_unique( array_merge( array( $home ), array_map( 'intval', is_array( $ids ) ? $ids : array() ) ) ) );
		$out = array();
		foreach ( $ids as $id ) {
			if ( get_post_status( $id ) === false ) {
				continue;
			}
			$kind = 'own';
			if ( $id === $home ) {
				$kind = 'home';
			} elseif ( get_post_meta( $id, '_dxai_ui_from_live_menu', true ) ) {
				$kind = 'site';
			} elseif ( (string) get_post_meta( $id, Team_Pages::META, true ) !== '' ) {
				$kind = 'team';
			}
			$out[] = array(
				'id'     => $id,
				'title'  => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'kind'   => $kind,
				'status' => (string) get_post_status( $id ),
				'url'    => (string) get_permalink( $id ),
				'edit'   => (string) get_edit_post_link( $id, 'raw' ),
			);
		}

		return $out;
	}

	/** When the design was last imported (the newest conversion snapshot of its Home, else the Home itself), as ISO 8601 in UTC. */
	private static function imported_at( int $home ): string {
		$snapshots = get_posts(
			array(
				'post_type'      => Content_Types::CONVERSION,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'meta_key'       => '_dxai_ui_page_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $home, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$post      = is_array( $snapshots ) && isset( $snapshots[0] ) ? (int) $snapshots[0] : $home;
		$time      = get_post_time( 'c', true, $post );

		return is_string( $time ) ? $time : '';
	}

	/** Whether the design's header names pages that are not there yet (Menu_Pages reads the header and what exists at its addresses). */
	private static function menu_names_pages( int $home ): bool {
		try {
			$menu = Menu_Pages::for_design( $home );
		} catch ( \Throwable $e ) {
			return false;
		}
		foreach ( array_merge( (array) ( $menu['pages'] ?? array() ), (array) ( $menu['lists'] ?? array() ) ) as $row ) {
			if ( is_array( $row ) && empty( $row['exists'] ) ) {
				return true;
			}
		}

		return false;
	}
}

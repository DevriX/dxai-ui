<?php
/**
 * The pages made for a design wear the design's header and footer as its Home has them now.
 *
 * @package DXAI_UI\Pages
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

use DXAI_UI\Structures\Design_Attach;

/**
 * A design that keeps its header and footer in its Home's content (a page written as one group, a classic theme) hands each
 * page made for it a copy at the moment the page is made (Team_Pages::write). The Home goes on: its menu is pointed at the
 * new pages (Team_Menu), a person edits its header in the editor. The pages' copies stay as they were, and then a page
 * shows another header than the Home. The rule is that they never differ, so this puts the Home's header and footer on every
 * page made for the design whenever they changed:
 *
 *  - after pages are made (Team_Pages::build),
 *  - when the Home is saved and its header or footer is not what it was (the hook),
 *  - once after an update, for the pages made before this existed (upgrade()).
 *
 * Only the first blocks and the last blocks of a page are replaced, and only when they are the same kind of thing (the same
 * structure: a header's blocks for a header's blocks). A page whose beginning is not a header — a person took it out — is left
 * alone and named by the quality check (Team_Quality, G1). A design whose header and footer are template parts is not touched:
 * its pages show the part itself.
 */
final class Team_Chrome {

	/** On a Home: the hash of the header and footer last put on its pages. */
	public const HASH = '_dxai_ui_chrome_hash';

	/** Option: the version of the one-off sync (upgrade()). */
	public const DONE = 'dxai_ui_team_chrome_done';

	public function register(): void {
		add_action( 'save_post_page', array( self::class, 'on_save' ), 30, 2 );
		add_action( 'admin_init', array( self::class, 'upgrade' ), 43 );
	}

	/** A Home was saved: its pages follow when its header or footer changed. @param \WP_Post|mixed $post */
	public static function on_save( int $post_id, $post = null ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! Design_Attach::is_design( $post_id ) ) {
			return;
		}
		$chrome = self::chrome( $post_id );
		if ( $chrome === null ) {
			return;
		}
		$hash = md5( $chrome['header_markup'] . '|' . $chrome['footer_markup'] );
		if ( (string) get_post_meta( $post_id, self::HASH, true ) === $hash ) {
			return;
		}
		self::sync( $post_id );
	}

	/** Once per version, every design that has pages made for it. */
	public static function upgrade(): void {
		if ( (string) get_option( self::DONE, '' ) === '1' || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		foreach ( Design_Attach::home_ids( 1000 ) as $home ) {
			if ( Design_Attach::is_design( (int) $home ) && Team_Quality::page_ids( (int) $home ) !== array() ) {
				self::sync( (int) $home );
			}
		}
		update_option( self::DONE, '1', false );
	}

	/**
	 * Put the Home's header and footer on every page made for the design.
	 *
	 * @return array{pages:int, skipped:array<int, int>} Pages changed; pages whose beginning or end is not a header or footer.
	 */
	public static function sync( int $home ): array {
		$out    = array( 'pages' => 0, 'skipped' => array() );
		$chrome = self::chrome( $home );
		if ( $chrome === null ) {
			return $out;
		}
		$hb = $chrome['header'];
		$fb = $chrome['footer'];
		foreach ( Team_Quality::page_ids( $home ) as $id ) {
			$content = (string) get_post_field( 'post_content', $id );
			$blocks  = array_values( array_filter( parse_blocks( $content ), static fn( $b ) => trim( (string) ( $b['blockName'] ?? '' ) ) !== '' ) );
			if ( count( $blocks ) < count( $hb ) + count( $fb ) ) {
				$out['skipped'][] = $id;
				continue;
			}
			$head = array_slice( $blocks, 0, count( $hb ) );
			$tail = $fb === array() ? array() : array_slice( $blocks, -count( $fb ) );
			// The same kind of thing: a header's structure for a header's, a footer's for a footer's.
			if ( self::structure( $head ) !== self::structure( $hb ) || self::structure( $tail ) !== self::structure( $fb ) ) {
				$out['skipped'][] = $id;
				continue;
			}
			$middle = array_slice( $blocks, count( $hb ), count( $blocks ) - count( $hb ) - count( $fb ) );
			$new    = trim( implode( "\n\n", array_map( 'serialize_block', array_merge( $hb, $middle, $fb ) ) ) );
			$old    = trim( implode( "\n\n", array_map( 'serialize_block', $blocks ) ) );
			if ( $new === $old ) {
				continue;
			}
			// The page as it is stored is what the records (the hash of what was written) are compared with.
			Team_Menu::save( $id, $home, $content, $new );
			++$out['pages'];
		}
		update_post_meta( $home, self::HASH, md5( $chrome['header_markup'] . '|' . $chrome['footer_markup'] ) );

		return $out;
	}

	/**
	 * The Home's header and footer as blocks, when they are in its content; null when they are template parts (the pages show
	 * the part) or there are none.
	 *
	 * @return array{header:array<int, array<string, mixed>>, footer:array<int, array<string, mixed>>, header_markup:string, footer_markup:string}|null
	 */
	private static function chrome( int $home ): ?array {
		$chrome = Section_Library::chrome_markup( $home );
		$in     = static fn( string $m ): bool => $m !== '' && ! str_contains( $m, '<!-- wp:template-part' ) && ! str_contains( $m, '<!-- wp:dxai-ui/site-' );
		$top    = static fn( string $m ): array => array_values( array_filter( parse_blocks( $m ), static fn( $b ) => trim( (string) ( $b['blockName'] ?? '' ) ) !== '' ) );
		$header = $in( (string) $chrome['header'] ) ? (string) $chrome['header'] : '';
		$footer = $in( (string) $chrome['footer'] ) ? (string) $chrome['footer'] : '';
		if ( $header === '' && $footer === '' ) {
			return null;
		}

		return array(
			'header'        => $top( $header ),
			'footer'        => $top( $footer ),
			'header_markup' => $header,
			'footer_markup' => $footer,
		);
	}

	/**
	 * The structure of a run of blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 */
	private static function structure( array $blocks ): string {
		return implode( ',', array_map( array( Section_Library::class, 'signature' ), $blocks ) );
	}
}

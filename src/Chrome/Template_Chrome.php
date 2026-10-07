<?php
/**
 * What the DX template draws around a page: the design's header, its footer.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Chrome;

use DXAI_UI\Pages\Section_Library;
use DXAI_UI\Structures\Design_Attach;
use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Theme\Blank_Template;

/**
 * The DX template (`templates/dxai-template.php`) draws the page as a template does: the header, then the page's content, then the footer.
 * The header is the design's own, filled from Appearance > Menus (Site_Header_Block); the footer is the design's frame around the
 * widgets of Appearance > Widgets (Site_Footer_Block). A page stores its body only — nothing of the chrome is in its content, so nothing
 * of it can be edited, moved or deleted by editing the page.
 *
 * It is the same markup, in the same element, as DX Blank's. DX Blank puts the chrome into the content as it renders (Page_Chrome),
 * where the page's scope element (Page_Scope) wraps it with the rest; here the template opens that element, draws the chrome and the
 * content inside it, and closes it, so every rule scoped to the design, and the design's state machine, which reads one scope element,
 * see the same page. The two cases of a page:
 *
 *  - a design's page (its Home, or a page made from it): one scope element around the skip link, the header, the content and the
 *    footer. What the page lacks of them is what it is drawn with (Page_Chrome::added()); a page that carries its own header or footer
 *    in its content (the shape a Home has on a classic theme) keeps it, and nothing is drawn twice.
 *  - any other page given the template, and every page a theme draws with the site's header and footer (DX Base's header.php and
 *    footer.php call site_header() and site_footer()): the site's header and footer, each in the scope element of the design they were
 *    installed from, around the content as it is. A fixed header leaves its place to a box the page does not carry, so the skip link
 *    and the box the design kept (site_lead()) are drawn before the header, with their rules.
 *
 * The skip link and the box that holds the place of a fixed header open a design's Home, before its header. They travel with it, so the
 * template draws them first; when a page's content has lost them (the box is an empty block, and an editor deletes it) the import's
 * copy (LEAD_META, on the design's page) takes their place, so the content never starts under a fixed header.
 */
final class Template_Chrome {

	/** On the design's page: the skip link and the header spacer as the import found them at the top of its Home. */
	public const LEAD_META = '_dxai_ui_chrome_lead';

	/** The page being drawn, 0 outside open() … close(). */
	private static int $post = 0;

	/** Whether the template opened the scope element, so Page_Scope leaves the content alone. */
	private static bool $wrapped = false;

	/** The page's content as stored, and what remains of it once the opening blocks are drawn before the header. */
	private static string $raw  = '';
	private static string $body = '';

	/** The footer's markup, drawn at close(), and the design scope it belongs to (site chrome only). */
	private static string $footer       = '';
	private static int $footer_scope    = 0;

	public function register(): void {
		// The content the template asks for is the page's, less the blocks open() has drawn before the header. Before do_blocks (9).
		add_filter( 'the_content', array( self::class, 'body' ), 7 );
		// The skip link a page lost and the template draws from the import's copy needs its rule like any other block.
		add_filter( 'dxai_ui_style_rules_markup', array( self::class, 'rules_markup' ), 10, 2 );
	}

	/**
	 * Whether the template draws this page, and so opened the scope element around it: Page_Scope leaves its content alone then.
	 */
	public static function wraps( int $post_id ): bool {
		return self::$wrapped && self::$post === $post_id;
	}

	/**
	 * Draw what comes before the page's content: the scope element, the skip link, the header.
	 */
	public static function open( int $post_id ): void {
		self::reset();
		if ( ! Blank_Template::is_canvas( $post_id ) ) {
			return;
		}
		self::$post = $post_id;
		self::$raw  = (string) get_post_field( 'post_content', $post_id );
		$scope      = Design_Attach::scope_for( $post_id );
		$design     = $scope > 0 && ! Design_Attach::attached( $post_id );

		if ( Page_Chrome::composes( $post_id ) ) {
			$added = Page_Chrome::added( $post_id, self::$raw );
			$home  = $scope;
		} else {
			$added = self::site_chrome();
			$home  = 0;
		}
		self::$wrapped = $design;
		self::$body    = self::$raw;
		$lead          = '';
		if ( $added['header'] !== '' ) {
			// The skip link and the header spacer stay where the design had them: before the header.
			list( $lead, self::$body ) = Section_Library::split_lead( self::$raw );
			$lead                      = self::with_kept( $lead, $home > 0 ? self::kept_lead( $post_id ) : self::site_lead() );
			if ( $home < 1 ) {
				self::rules_for( $lead, self::header_scope( 0 ) );
			}
		}
		self::$footer = $added['footer'];

		if ( $design ) {
			echo '<div class="' . esc_attr( Page_Scope::classes( $post_id, $scope ) ) . '">';
		}
		$top = trim( $lead . "\n" . $added['header'] );
		if ( $top !== '' ) {
			echo self::drawn( $top, $design ? 0 : self::header_scope( $home ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the design's own block markup, rendered.
		}
		self::$footer_scope = $design ? 0 : self::footer_scope( $home );
	}

	/**
	 * Draw what comes after the page's content: the footer, and the end of the scope element.
	 */
	public static function close( int $post_id ): void {
		if ( self::$post < 1 || self::$post !== $post_id ) {
			return;
		}
		if ( self::$footer !== '' ) {
			echo self::drawn( self::$footer, self::$footer_scope ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the design's own block markup, rendered.
		}
		if ( self::$wrapped ) {
			echo '</div>';
		}
		self::reset();
	}

	/**
	 * The page's content for `the_content`, less the blocks that open it when they were drawn before the header.
	 *
	 * @param string|mixed $content
	 * @return string|mixed
	 */
	public static function body( $content ) {
		if ( self::$post < 1 || ! is_string( $content ) || $content !== self::$raw || self::$body === self::$raw ) {
			return $content;
		}

		return self::$body;
	}

	/**
	 * Style_Rules' extra markup for a page of the DX template whose opening blocks are not in its content any more: the import's copy
	 * is drawn instead, and its rules are written with the page's.
	 *
	 * @param array<int, string>|mixed $markups
	 * @param int|mixed                $post_id
	 * @return array<int, string>|mixed
	 */
	public static function rules_markup( $markups, $post_id = 0 ) {
		$post_id = (int) $post_id;
		if ( ! is_array( $markups ) || $post_id < 1 || ! Blank_Template::is_canvas( $post_id ) || ! Page_Chrome::composes( $post_id ) ) {
			return $markups;
		}
		$raw = (string) get_post_field( 'post_content', $post_id );
		if ( Page_Chrome::added( $post_id, $raw )['header'] === '' ) {
			return $markups;
		}
		list( $lead ) = Section_Library::split_lead( $raw );
		$whole        = self::with_kept( $lead, self::kept_lead( $post_id ) );
		if ( $whole !== $lead ) {
			$markups[] = $whole;
		}

		return $markups;
	}

	/**
	 * The site's header as a theme's header.php draws it (DX Base): the one installed from Appearance > Menus, in the scope element of
	 * the design it came from, as the content filters make it. '' when the site has none installed.
	 */
	public static function site_header(): string {
		$markup = self::site_chrome()['header'];
		if ( $markup === '' ) {
			return '';
		}
		// A fixed header leaves its place to a box in the page, which a page of no design does not carry: the design's copy of it, and of the
		// skip link, goes first.
		$lead = self::site_lead();
		self::rules_for( $lead, self::header_scope( 0 ) );

		return self::drawn( trim( $lead . "\n" . $markup ), self::header_scope( 0 ) );
	}

	/**
	 * The site's footer as a theme's footer.php draws it: the design's frame around the widgets of Appearance > Widgets, in the scope
	 * element of the design it came from. '' when the site has none installed.
	 */
	public static function site_footer(): string {
		$markup = self::site_chrome()['footer'];

		return $markup === '' ? '' : self::drawn( $markup, self::footer_scope( 0 ) );
	}

	/**
	 * Keep the skip link and the header spacer a design's Home opens with, as the import found them, so a Home that loses them — the
	 * spacer is an empty block, and deleting it is a click — is drawn with them still. Called by the import, after the chrome is written.
	 */
	public static function remember_lead( int $home ): void {
		if ( $home < 1 ) {
			return;
		}
		list( $lead ) = Section_Library::split_lead( (string) get_post_field( 'post_content', $home ) );
		if ( $lead !== '' ) {
			update_post_meta( $home, self::LEAD_META, wp_slash( $lead ) );
		}
	}

	/**
	 * The page's opening blocks with the ones it lost put back: the import's copy supplies the skip link, or the header spacer, that the
	 * page no longer has, in the order the design had them, and the blocks the page has are its own.
	 */
	private static function with_kept( string $lead, string $kept ): string {
		if ( $kept === '' ) {
			return $lead;
		}
		$own  = Section_Library::lead_blocks( $lead );
		$have = array_column( $own, 'kind' );
		$out  = array();
		// Islands the page has come first; the skip link and the spacer follow in the order of the import's copy.
		foreach ( $own as $block ) {
			if ( $block['kind'] === 'other' ) {
				$out[] = $block['markup'];
			}
		}
		foreach ( Section_Library::lead_blocks( $kept ) as $block ) {
			if ( $block['kind'] === 'other' ) {
				continue;
			}
			$mine = null;
			foreach ( $own as $candidate ) {
				if ( $candidate['kind'] === $block['kind'] ) {
					$mine = $candidate['markup'];
					break;
				}
			}
			$out[] = in_array( $block['kind'], $have, true ) && null !== $mine ? $mine : $block['markup'];
		}
		// A skip link or a spacer the page has and the copy lacks stays, after the others.
		$kept_kinds = array_column( Section_Library::lead_blocks( $kept ), 'kind' );
		foreach ( $own as $block ) {
			if ( $block['kind'] !== 'other' && ! in_array( $block['kind'], $kept_kinds, true ) ) {
				$out[] = $block['markup'];
			}
		}

		return trim( implode( "\n\n", $out ) );
	}

	/** The opening blocks of the design the site's header came from, for a page of no design: its skip link and its header spacer. */
	private static function site_lead(): string {
		$scope = self::header_scope( 0 );

		return $scope > 0 ? (string) get_post_meta( $scope, self::LEAD_META, true ) : '';
	}

	/** The rules of blocks the template draws that no page's content carries: written with the page's styles, in the design's scope. */
	private static function rules_for( string $markup, int $scope ): void {
		if ( $markup !== '' && ! is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			\DXAI_UI\Blocks\Style_Rules::attach_markup( $markup, $scope );
		}
	}

	/** The copy the import kept of the blocks that open a design's Home, '' when it kept none. */
	private static function kept_lead( int $post_id ): string {
		return (string) get_post_meta( Page_Chrome::scope_of( $post_id ), self::LEAD_META, true );
	}

	/**
	 * The site's own header and footer, for a page of no design: the ones installed from Appearance > Menus and Appearance > Widgets.
	 *
	 * @return array{header: string, footer: string}
	 */
	private static function site_chrome(): array {
		return array(
			'header' => null !== Header_Template::stored() ? Site_Header_Block::MARKUP : '',
			'footer' => Footer_Template::get() !== array() ? Site_Footer_Block::MARKUP : '',
		);
	}

	/** The design the site's header was installed from: its scope page. */
	private static function header_scope( int $home ): int {
		if ( $home > 0 ) {
			return 0;
		}
		$spec = Header_Template::stored();

		return null === $spec ? 0 : Header_Template::scope_of( $spec );
	}

	/** The design the site's footer was installed from: its scope page. */
	private static function footer_scope( int $home ): int {
		if ( $home > 0 ) {
			return 0;
		}

		return Site_Footer_Block::scope( Footer_Template::get() );
	}

	/**
	 * Markup as the content filters make it, in its own scope element when it is not drawn inside the page's: the same filters that
	 * made the header and the footer part of the content under DX Blank (wptexturize, the image attributes), in the same order.
	 */
	private static function drawn( string $markup, int $scope ): string {
		$html = (string) apply_filters( 'the_content', $markup );
		if ( $scope < 1 ) {
			return $html;
		}

		return '<div class="' . esc_attr( Page_Scope::classes( $scope, $scope ) ) . '">' . $html . '</div>';
	}

	private static function reset(): void {
		self::$post         = 0;
		self::$wrapped      = false;
		self::$raw          = '';
		self::$body         = '';
		self::$footer       = '';
		self::$footer_scope = 0;
	}
}

<?php
/**
 * What is below a design's first screen waits; what opens the page does not.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Media;

use DXAI_UI\Seo\Page_Meta;

/**
 * WordPress makes the first pictures of a page's content eager and gives the first big one `fetchpriority="high"`, taking it for
 * the page's Largest Contentful Paint. A design's page often opens on a picture written as CSS (Lcp_Preload), and its first <img>
 * is further down: on H2O Away's pages no picture was lazy (407 KiB loaded with the page), a photograph three screens down had the
 * high priority, and a map's frame loaded its script at once (docs/PLAN-PERFECT-PAGES.md, T5.1). On a design's page, the pictures
 * and frames of every top-level block after the first that is content (a header before it is not) load lazily, and none of them has
 * the high priority; the blocks the page opens on are left to WordPress. So do the ones of a panel hidden until the page's runtime opens
 * it (`hidden` with a `dxai-on-` state mark: a header's dropdown, whose five icons were 231 KiB loaded with every page of H2O), and
 * the ones of the footer the DX template draws after the page (Template_Chrome). `dxai_ui_lazy_below` turns it off.
 *
 * A map's frame there waits for more: Chrome loads a lazy frame once it is about 1250 px from the screen, and H2O Away's Contact page
 * has its Google map 1229 px below the first screen — 400 KiB of the map's script loaded with the page, 730 ms of blocking time. Its
 * address goes to `data-dxai-src` and a few lines at the end of the page load it when it is 300 px from the screen (FRAME_MARGIN).
 */
final class Image_Loading {

	/** The attribute a top-level block below the first screen is marked with while it renders. */
	private const MARK = '__dxai_below';

	/** How near the screen a waiting frame is loaded. */
	public const FRAME_MARGIN = '300px';

	/** Whether this page has a frame waiting for the screen (the loader is printed at its end). */
	private static bool $waiting = false;

	/** Whether the page's content has had its first block of content (not a header) yet. */
	private static bool $content = false;

	/** How many blocks are rendering now, one inside another. */
	private static int $depth = 0;

	/** The depth the page's content started at: its own blocks are rendered there. */
	private static int $base = 0;

	public function register(): void {
		add_filter( 'the_content', array( self::class, 'reset' ), 1 );
		add_filter( 'render_block_data', array( self::class, 'enter' ), 10, 3 );
		add_filter( 'render_block', array( self::class, 'leave' ), 10, 2 );
		add_action( 'wp_footer', array( self::class, 'print_loader' ), 20 );
	}

	/**
	 * A page's content starts at its first block.
	 *
	 * @param string $content
	 * @return string
	 */
	public static function reset( $content ) {
		self::$content = false;
		self::$base    = self::$depth;

		return $content;
	}

	/**
	 * Before a block renders: one of the page's own (top-level) blocks after its first block of content is below the first screen.
	 * Top-level is counted, not read from the parent WordPress passes: a block that renders blocks itself (the site's header and
	 * footer, a synced pattern) has them rendered with no parent, and they are its, not the page's.
	 *
	 * @param array<string, mixed>|mixed $block
	 * @param array<string, mixed>|mixed $source
	 * @param mixed                      $parent
	 * @return array<string, mixed>|mixed
	 */
	public static function enter( $block, $source = array(), $parent = null ) {
		$top = self::$depth === self::$base;
		++self::$depth;
		if ( ! is_array( $block ) || empty( $block['blockName'] ) || ! self::applies() ) {
			return $block;
		}
		if ( $top ) {
			if ( self::$content ) {
				$block['attrs'][ self::MARK ] = true;
			} elseif ( ! self::is_header( $block ) ) {
				self::$content = true;
			}
		}
		if ( self::waits( $block ) ) {
			$block['attrs'][ self::MARK ] = true;
		}

		return $block;
	}

	/**
	 * The footer the DX template draws after a page's content: below its first screen, so its pictures and frames load lazily.
	 */
	public static function below( string $html ): string {
		if ( $html === '' || ! is_singular() || ( stripos( $html, '<img' ) === false && stripos( $html, '<iframe' ) === false ) ) {
			return $html;
		}
		$id = (int) get_queried_object_id();

		/** For the footer the DX template draws: true on any page. */
		return (bool) apply_filters( 'dxai_ui_lazy_below', true, $id ) ? self::lazy( $html ) : $html;
	}

	/**
	 * After a block renders: a top-level block below the first screen has its pictures and frames lazy, and none high priority.
	 *
	 * @param string|mixed               $html
	 * @param array<string, mixed>|mixed $block
	 * @return string|mixed
	 */
	public static function leave( $html, $block ) {
		if ( self::$depth > 0 ) {
			--self::$depth;
		}
		if ( ! is_string( $html ) || ! is_array( $block ) || empty( $block['attrs'][ self::MARK ] ) || ( stripos( $html, '<img' ) === false && stripos( $html, '<iframe' ) === false ) ) {
			return $html;
		}

		return self::lazy( $html );
	}

	/** Markup with its pictures and frames lazy, and no high priority on them. */
	public static function lazy( string $html ): string {
		$tags = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			$name = $tags->get_tag();
			if ( $name !== 'IMG' && $name !== 'IFRAME' ) {
				continue;
			}
			if ( $tags->get_attribute( 'loading' ) === null ) {
				$tags->set_attribute( 'loading', 'lazy' );
			}
			if ( $name === 'IMG' && strtolower( (string) $tags->get_attribute( 'fetchpriority' ) ) === 'high' ) {
				$tags->remove_attribute( 'fetchpriority' );
			}
			$src = (string) $tags->get_attribute( 'src' );
			if ( $name === 'IFRAME' && self::is_map( $src ) ) {
				$tags->set_attribute( 'data-dxai-src', $src );
				// No address until it is near the screen (WordPress refuses about:blank: not a protocol esc_url() allows).
				$tags->remove_attribute( 'src' );
				self::$waiting = true;
			}
		}

		return $tags->get_updated_html();
	}

	/** Whether a frame's address is a Google map (an embed, or maps.google.* with a query). */
	public static function is_map( string $src ): bool {
		return preg_match( '#^https?://(?:(?:www\.)?google\.[a-z.]{2,6}/maps\b|maps\.google\.[a-z.]{2,6}/)#i', $src ) === 1;
	}

	/** At the end of a page with a waiting frame: the lines that load it near the screen (at once without IntersectionObserver). */
	public static function print_loader(): void {
		if ( ! self::$waiting ) {
			return;
		}
		self::$waiting = false;
		echo "<script id=\"dxai-ui-frames\">(function(){var f=document.querySelectorAll('iframe[data-dxai-src]');function go(i){var s=i.getAttribute('data-dxai-src');if(s){i.removeAttribute('data-dxai-src');i.src=s;}}if(!('IntersectionObserver' in window)){for(var k=0;k<f.length;k++){go(f[k]);}return;}var io=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){io.unobserve(e.target);go(e.target);}});},{rootMargin:'" . esc_js( self::FRAME_MARGIN ) . " 0px'});for(var j=0;j<f.length;j++){io.observe(f[j]);}})();</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed script; its one value is escaped.
	}

	/** On the main content of a design's page, unless turned off. */
	private static function applies(): bool {
		if ( ! is_singular() || ! doing_filter( 'the_content' ) ) {
			return false;
		}
		$id = (int) get_queried_object_id();

		/**
		 * Whether the pictures and frames below a design's first screen load lazily.
		 *
		 * @param bool $on Whether the page is a design's (for the content; the footer the DX template draws passes true).
		 * @param int  $id The page.
		 */
		return (bool) apply_filters( 'dxai_ui_lazy_below', $id > 0 && Page_Meta::is_design_page( $id ), $id );
	}

	/**
	 * A panel hidden until the page's runtime opens it: `hidden` with a state mark of the compiler (`dxai-on-<state>`). `hidden` alone
	 * is not one, as a design may show it again at a wider screen.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function waits( array $block ): bool {
		$classes = preg_split( '/\s+/', (string) ( $block['attrs']['className'] ?? '' ) );

		return is_array( $classes ) && in_array( 'hidden', $classes, true ) && array() !== preg_grep( '/^dxai-on-/', $classes );
	}

	/**
	 * What a page may open on before its first content: a header (the site's one too), a skip link, an empty box (a fixed header's
	 * spacer).
	 *
	 * @param array<string, mixed> $block
	 */
	private static function is_header( array $block ): bool {
		$tag  = strtolower( (string) ( $block['attrs']['tagName'] ?? '' ) );
		$name = (string) ( $block['blockName'] ?? '' );
		$html = (string) ( $block['innerHTML'] ?? '' );

		return in_array( $tag, array( 'header', 'nav' ), true ) || in_array( $name, array( 'core/template-part', 'dxai-ui/site-header' ), true ) ||
			str_contains( (string) ( $block['attrs']['className'] ?? '' ), 'skip-link' ) ||
			( empty( $block['innerBlocks'] ) && trim( $html ) !== '' && trim( wp_strip_all_tags( $html ) ) === '' && ! preg_match( '/<(?:img|iframe|svg|video|picture)\b/i', $html ) );
	}
}

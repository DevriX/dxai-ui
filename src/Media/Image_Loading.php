<?php
/**
 * What is below a design's first screen waits; what opens the page does not.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Media;

use DXAI_UI\Pages\Section_Library;
use DXAI_UI\Seo\Page_Meta;

/**
 * WordPress makes the first pictures of a page's content eager and gives the first big one `fetchpriority="high"`, taking it for
 * the page's Largest Contentful Paint. A design's page often opens on a picture written as CSS (Lcp_Preload), and its first <img>
 * is further down: on H2O Away's pages no picture was lazy (407 KiB loaded with the page), a photograph three screens down had the
 * high priority, and a map's frame loaded its script at once (docs/PLAN-PERFECT-PAGES.md, T5.1). On a design's page, the pictures
 * and frames of every top-level block after the first that is content (a header before it is not) load lazily, and none of them has
 * the high priority; the blocks the page opens on are left to WordPress. So do the ones of a panel hidden until the page's runtime opens
 * it (`hidden` with a `dxai-on-` state mark: a header's dropdown, whose five icons were 231 KiB loaded with every page of H2O), and
 * the ones of the footer the DX template draws after the page (Template_Chrome). `dxai_ui_lazy_below` turns it off. A group around
 * the header is the header (Semper Dry's hero picture, its Largest Contentful Paint, went lazy when the group was taken for the first
 * content), and a group around the whole page is opened: its children are the page's sections (Clean Joe's never were below).
 *
 * A map's frame there waits for more: Chrome loads a lazy frame once it is about 1250 px from the screen, and H2O Away's Contact page
 * has its Google map 1229 px below the first screen — 400 KiB of the map's script loaded with the page, 730 ms of blocking time. Its
 * address goes to `data-dxai-src` and a few lines at the end of the page load it when it is 300 px from the screen (FRAME_MARGIN).
 *
 * A picture written as CSS (a block's own `background-image`) cannot be lazy: the browser asks for it once the block is styled,
 * wherever it is. Arcus's two photographs six and ten screens down (323 KiB) shared the phone's bandwidth with its hero: LCP 4.7 s,
 * 3.9 s without them. A block below the first screen whose own CSS paints a file behind it gets BG_CLASS; while scripts run (the
 * head marks <html> `dxai-js`), a rule with the precedence of four ids (one more than a block's own rule, Style_Rules) keeps its
 * background unpainted, and the lines at the end of the page take the class off 600 px from the screen (BG_MARGIN). Without scripts
 * it paints at once. A background in an inline style (a group's background image in the editor) is not covered.
 */
final class Image_Loading {

	/** The attribute a top-level block below the first screen is marked with while it renders. */
	private const MARK = '__dxai_below';

	/** How near the screen a waiting frame is loaded. */
	public const FRAME_MARGIN = '300px';

	/** The class of a block whose background picture waits for the screen. */
	public const BG_CLASS = 'dxai-lazy-bg';

	/** How near the screen a waiting background is painted. */
	public const BG_MARGIN = '600px';

	/** The attribute a block below the first screen with a background picture is marked with while it renders. */
	private const LAZY_BG = '__dxai_lazy_bg';

	/** Whether this page has a frame waiting for the screen (the loader is printed at its end). */
	private static bool $waiting = false;

	/** Whether this page has a background waiting for the screen. */
	private static bool $backgrounds = false;

	/** The depth of the top-level block below the first screen that is rendering now; 0 outside one. */
	private static int $below_at = 0;

	/** Whether the page's content has had its first block of content (not a header) yet. */
	private static bool $content = false;

	/** How many blocks are rendering now, one inside another. */
	private static int $depth = 0;

	/** The depth the page's content started at: its own blocks are rendered there. */
	private static int $base = 0;

	/**
	 * The depths at which the page's sections are, innermost last: the page's own blocks, and the children of a block that wraps
	 * the whole page (Clean Joe: one group around its header, a <main> of thirteen sections and its footer).
	 *
	 * @var array<int, int>
	 */
	private static array $levels = array();

	/** Whether the wrapper just opened holds a single block, which may wrap the sections in turn. */
	private static bool $lone = false;

	/** The attribute a wrapper is marked with while it renders, so its level ends with it. */
	private const WRAP = '__dxai_wrap';

	public function register(): void {
		add_filter( 'the_content', array( self::class, 'reset' ), 1 );
		add_filter( 'render_block_data', array( self::class, 'enter' ), 10, 3 );
		add_filter( 'render_block', array( self::class, 'leave' ), 10, 2 );
		add_action( 'wp_footer', array( self::class, 'print_loader' ), 20 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_background_rule' ) );
		add_filter( 'dx_lazy_load_skip_image', array( self::class, 'first_screen' ), 10, 2 );
	}

	/**
	 * The DX themes' LazyLoadImages module (American Restoration) makes every <img> lazy with `fetchpriority="low"`, the first
	 * screen's too, and leaves telling it apart to whatever writes the picture (`dx_lazy_load_skip_image`, `data-dx-no-lazy`). A
	 * picture of a design's page rendered before its first section below the first screen — its header, the section it opens
	 * on — is skipped, so it loads as WordPress decided; below, the module's lazy loading stands.
	 *
	 * @param bool|mixed   $skip Whether the module leaves the picture as it is.
	 * @param string|mixed $tag  The <img> tag.
	 * @return bool|mixed
	 */
	public static function first_screen( $skip, $tag = '' ) {
		if ( $skip || ! is_string( $tag ) ) {
			return $skip;
		}
		// A picture its design wrote eager stays so, wherever it is.
		if ( preg_match( '/\sloading\s*=\s*(["\']?)eager\1/i', $tag ) === 1 ) {
			return true;
		}
		if ( preg_match( '/\ssrc\s*=\s*(["\'])(.*?)\1/i', $tag, $m ) !== 1 ) {
			return $skip;
		}

		// By its address: the module asks for each block and again for the whole content, after WordPress has added to the tag.
		return isset( self::$first[ html_entity_decode( $m[2] ) ] ) ? true : $skip;
	}

	/**
	 * The pictures of the page's first screen (its header, the section it opens on), by address.
	 *
	 * @var array<string, true>
	 */
	private static array $first = array();

	/**
	 * A page's content starts at its first block.
	 *
	 * @param string $content
	 * @return string
	 */
	public static function reset( $content ) {
		self::$content  = false;
		self::$below_at = 0;
		self::$base     = self::$depth;
		self::$levels   = array( self::$depth );
		self::$lone     = false;
		self::$first    = array();

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
		$top = self::$depth === ( self::$levels === array() ? self::$base : (int) end( self::$levels ) );
		++self::$depth;
		if ( ! is_array( $block ) || empty( $block['blockName'] ) || ! self::applies() ) {
			return $block;
		}
		$lone       = self::$lone;
		self::$lone = $top ? false : self::$lone;
		$box = in_array( (string) $block['blockName'], array( 'core/group', 'dxai-ui/box' ), true );
		if ( $top && ! self::$content && ( self::is_wrapper( $block ) || ( $lone && $box && ( \DXAI_UI\Pages\Section_Library::wraps_sections( $block ) || self::kids( $block ) === 1 ) ) ) ) {
			// Its children are the page's sections: the next level down is the page's.
			self::$levels[]              = self::$depth;
			$block['attrs'][ self::WRAP ] = true;
			// A <main> around one container (an Elementor site's <main> around its page <div> around its sections): opened too.
			self::$lone = self::kids( $block ) === 1;
		} elseif ( $top ) {
			if ( self::$content ) {
				$block['attrs'][ self::MARK ] = true;
				self::$below_at                = self::$depth;
			} elseif ( ! self::is_header( $block ) ) {
				self::$content = true;
			}
		}
		if ( self::$below_at > 0 && self::$depth >= self::$below_at && self::has_background( $block ) ) {
			$block['attrs'][ self::LAZY_BG ] = true;
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
		if ( self::$below_at > 0 && self::$depth < self::$below_at ) {
			self::$below_at = 0;
		}
		if ( is_array( $block ) && ! empty( $block['attrs'][ self::WRAP ] ) && count( self::$levels ) > 1 ) {
			array_pop( self::$levels );
		}
		if ( ! is_string( $html ) || ! is_array( $block ) ) {
			return $html;
		}
		if ( ! empty( $block['attrs'][ self::LAZY_BG ] ) ) {
			$html = self::wait_background( $html );
		}
		if ( self::$below_at === 0 && empty( $block['attrs'][ self::MARK ] ) && stripos( $html, '<img' ) !== false && self::applies()
			&& preg_match_all( '/<img\b[^>]*?\ssrc\s*=\s*(["\'])(.*?)\1/i', $html, $found ) ) {
			foreach ( $found[2] as $src ) {
				self::$first[ html_entity_decode( (string) $src ) ] = true;
			}
		}
		if ( empty( $block['attrs'][ self::MARK ] ) || ( stripos( $html, '<img' ) === false && stripos( $html, '<iframe' ) === false ) ) {
			return $html;
		}

		return self::lazy( $html );
	}

	/** A block's markup whose own element keeps its background picture until it is near the screen. */
	private static function wait_background( string $html ): string {
		$tags = new \WP_HTML_Tag_Processor( $html );
		if ( ! $tags->next_tag() ) {
			return $html;
		}
		$tags->add_class( self::BG_CLASS );
		self::$backgrounds = true;

		return $tags->get_updated_html();
	}

	/**
	 * Whether a block's own CSS paints a file behind it (not a data: picture written into the CSS, which costs no request).
	 *
	 * @param array<string, mixed> $block
	 */
	private static function has_background( array $block ): bool {
		return preg_match( '/background(?:-image)?\s*:[^;]*url\(\s*[\'"]?(?!data:)/i', (string) ( $block['attrs']['dxaiCss'] ?? '' ) ) === 1;
	}

	/**
	 * On a design's page that may have one: what keeps a waiting background unpainted while scripts run — <html> marked `dxai-js`
	 * in the head, and the rule (four ids, one more than a block's own rule). Without scripts the background paints at once.
	 */
	public static function enqueue_background_rule(): void {
		$id = is_singular() ? (int) get_queried_object_id() : 0;
		if ( $id < 1 || ! Page_Meta::is_design_page( $id ) || ! str_contains( (string) get_post_field( 'post_content', $id ), 'url(' ) ) {
			return;
		}
		/** For a waiting background on a design's page: the same switch as its pictures'. */
		if ( ! (bool) apply_filters( 'dxai_ui_lazy_below', true, $id ) ) {
			return;
		}
		wp_register_script( 'dxai-ui-js', false, array(), DXAI_UI_VERSION, false );
		wp_enqueue_script( 'dxai-ui-js' );
		wp_add_inline_script( 'dxai-ui-js', 'document.documentElement.classList.add("dxai-js");' );
		wp_register_style( 'dxai-ui-lazy-bg', false, array(), DXAI_UI_VERSION );
		wp_enqueue_style( 'dxai-ui-lazy-bg' );
		wp_add_inline_style( 'dxai-ui-lazy-bg', '.dxai-js ' . str_repeat( ':is(.' . self::BG_CLASS . ',#dxai-h)', 4 ) . '{background-image:none}' );
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

	/**
	 * At the end of a page with a waiting frame or background: the lines that load it near the screen (at once without
	 * IntersectionObserver).
	 */
	public static function print_loader(): void {
		if ( self::$backgrounds ) {
			self::$backgrounds = false;
			echo "<script id=\"dxai-ui-backgrounds\">(function(){var b=document.querySelectorAll('." . esc_js( self::BG_CLASS ) . "');function go(e){e.classList.remove('" . esc_js( self::BG_CLASS ) . "');}if(!('IntersectionObserver' in window)){for(var k=0;k<b.length;k++){go(b[k]);}return;}var io=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){io.unobserve(e.target);go(e.target);}});},{rootMargin:'" . esc_js( self::BG_MARGIN ) . " 0px'});for(var j=0;j<b.length;j++){io.observe(b[j]);}})();</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed script; its values are escaped.
		}
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

		// What the import reads as the page's opening (a skip link written as a link to #main, a header bar stuck to the top…).
		if ( Section_Library::is_lead_chrome( $block ) ) {
			return true;
		}

		return in_array( $tag, array( 'header', 'nav' ), true ) || in_array( $name, array( 'core/template-part', 'dxai-ui/site-header' ), true ) ||
			str_contains( (string) ( $block['attrs']['className'] ?? '' ), 'skip-link' ) ||
			( empty( $block['innerBlocks'] ) && trim( $html ) !== '' && trim( wp_strip_all_tags( $html ) ) === '' && ! preg_match( '/<(?:img|iframe|svg|video|picture)\b/i', $html ) ) ||
			// A group around the header (Semper Dry's: a sticky group with the <header> and the phone menu in it): holds a header or a
			// menu and none of the page's content — no <main>, no section, no main heading.
			( ! in_array( $tag, array( 'main', 'section', 'article' ), true ) && self::holds( $block, array( 'header', 'nav' ), 3 ) && ! self::holds( $block, array( 'main', 'section', 'article', 'h1' ), 3 ) );
	}

	/**
	 * A block that wraps the whole page: a <main>, or a group whose children are a <main>, or at least two of the page's parts
	 * (header, section, footer).
	 *
	 * @param array<string, mixed> $block
	 */
	/** @param array<string, mixed> $block How many blocks it holds. */
	private static function kids( array $block ): int {
		return count( array_filter( (array) ( $block['innerBlocks'] ?? array() ), static fn( $b ) => is_array( $b ) && ! empty( $b['blockName'] ) ) );
	}

	private static function is_wrapper( array $block ): bool {
		$kids = array_values( array_filter( (array) ( $block['innerBlocks'] ?? array() ), static fn( $b ) => is_array( $b ) && ! empty( $b['blockName'] ) ) );
		// A <main> wraps the page's content, also when that is one block (which may wrap the sections in turn).
		if ( strtolower( (string) ( $block['attrs']['tagName'] ?? '' ) ) === 'main' && $kids !== array() ) {
			return true;
		}
		if ( count( $kids ) < 2 ) {
			return false;
		}
		$parts = 0;
		foreach ( $kids as $kid ) {
			$tag = strtolower( (string) ( $kid['attrs']['tagName'] ?? '' ) );
			if ( $tag === 'main' ) {
				return true;
			}
			$parts += in_array( $tag, array( 'section', 'footer', 'header' ), true ) ? 1 : 0;
		}

		return $parts >= 2;
	}

	/**
	 * Whether a block's inner blocks, down to $depth levels, include one written as one of these tags (`h1`: a heading of level 1),
	 * or WordPress's navigation for `nav`.
	 *
	 * @param array<string, mixed> $block
	 * @param array<int, string>   $tags
	 */
	private static function holds( array $block, array $tags, int $depth ): bool {
		if ( $depth < 1 ) {
			return false;
		}
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $kid ) {
			if ( ! is_array( $kid ) || empty( $kid['blockName'] ) ) {
				continue;
			}
			$tag = strtolower( (string) ( $kid['attrs']['tagName'] ?? '' ) );
			if ( $kid['blockName'] === 'core/heading' && (int) ( $kid['attrs']['level'] ?? 2 ) === 1 ) {
				$tag = 'h1';
			} elseif ( $kid['blockName'] === 'core/navigation' ) {
				$tag = 'nav';
			}
			if ( in_array( $tag, $tags, true ) || self::holds( $kid, $tags, $depth - 1 ) ) {
				return true;
			}
		}

		return false;
	}
}

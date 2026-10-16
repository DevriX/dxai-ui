<?php
/**
 * A design's web fonts asked for once the page has loaded; their fallback faces draw it until then.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Media;

/**
 * Lighthouse counts every request that starts before a page's first paint and its largest one, and a web font starts as soon as the
 * page is laid out: on H2O Away the design's five files (Bebas Neue and Hind) held the phone's LCP at 2.7 s and FCP at 1.67 s; without
 * them 2.0–2.2 s and 1.2–1.3 s, Performance 0.94–0.96 → 0.98–0.99. Their bytes are not what counts — Google's subset of the same faces,
 * a third smaller, measured the same (docs/PLAN-PERFECT-PAGES.md, T5.2). The user chose (2026-10-15) to have them come after the first
 * paint: the page is drawn in their fallback faces, which have their size (Font_Metrics: nothing moves when they arrive), and the
 * design's fonts take over when the page has loaded.
 *
 * So on the site's pages, the @font-face rules the plugin prints that load a file (`src:url(…)`) are held back (hold()) and printed at
 * the end of the page in a style element for print, which a few lines switch to every medium once the page has loaded AND its first
 * contentful paint has been shown — whichever is later: a first frame shown late (H2O in Lighthouse here: shown at 1.2 s, loaded at
 * 0.3 s) left fonts asked for at `load` among what was counted, LCP 2.7 s; after both, 2.0–2.2 s. A page whose first paint is not
 * reported (opened in a hidden tab) gets them BACKSTOP ms after it loaded. A browser without scripts gets them at once (<noscript>). The faces that name a local font (the fallback faces) and everything else are printed where they were.
 * Not in the block editor, the admin or a REST request: those show the fonts at once. The theme's preload of the files is dropped
 * while they wait (Template_Fonts::preload()). `dxai_ui_late_fonts` turns it off.
 */
final class Late_Fonts {

	/** The style element that holds the faces until the page has loaded. */
	public const ID = 'dxai-ui-late-fonts';

	/** Milliseconds after the load event the faces come anyway, when no first paint is reported (a page opened in a hidden tab). */
	public const BACKSTOP = 2000;

	/** @var array<string, true> The faces held back on this page, in order, once each. */
	private static array $faces = array();

	public function register(): void {
		add_action( 'wp_footer', array( self::class, 'print_late' ), 20 );
	}

	/** Whether the design's fonts wait for the page to load here: on the site's pages, unless turned off. */
	public static function on(): bool {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() || is_embed() ) {
			return false;
		}

		/**
		 * Whether a design's web fonts are asked for once the page has loaded (drawn in their fallback faces until then), so they are
		 * not among what Lighthouse counts before its first and largest paints.
		 *
		 * @param bool $on Default true.
		 */
		return (bool) apply_filters( 'dxai_ui_late_fonts', true );
	}

	/**
	 * CSS with its web font faces taken out and kept for the end of the page; the rest as it was. CSS as it was when the fonts do not
	 * wait here.
	 */
	public static function hold( string $css ): string {
		if ( $css === '' || stripos( $css, '@font-face' ) === false || ! self::on() ) {
			return $css;
		}

		return (string) preg_replace_callback(
			'/@font-face\s*\{[^{}]*\}/i',
			static function ( array $m ): string {
				if ( preg_match( '/\bsrc\s*:[^;}]*url\(/i', $m[0] ) !== 1 ) {
					return $m[0];
				}
				self::$faces[ $m[0] ] = true;

				return '';
			},
			$css
		);
	}

	/** The faces held back on this page (for a check; print_late() prints them). */
	public static function held(): string {
		return implode( "\n", array_keys( self::$faces ) );
	}

	/**
	 * At the end of a page with faces held back: them in a style element for print, the lines that make it every medium's once the
	 * page has loaded, and them again for a browser without scripts.
	 */
	public static function print_late(): void {
		if ( self::$faces === array() ) {
			return;
		}
		// Made of rules this plugin wrote; nothing in them may close a style element.
		$css         = str_ireplace( '</', '<\\/', self::held() );
		self::$faces = array();
		$id          = esc_attr( self::ID );
		echo '<style id="' . $id . '" media="print">' . $css . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own @font-face rules.
		echo "<script id=\"" . $id . "-on-load\">(function(){var s=document.getElementById('" . esc_js( self::ID ) . "');var n=0;function on(){if(++n===2&&s){s.media='all';}}var shown=function(){shown=function(){};on();};try{if(performance.getEntriesByName('first-contentful-paint').length){shown();}else{var o=new PerformanceObserver(function(l){if(l.getEntriesByName('first-contentful-paint').length){o.disconnect();shown();}});o.observe({type:'paint',buffered:true});}}catch(e){shown();}function loaded(){on();setTimeout(function(){shown();}," . (int) self::BACKSTOP . ");}if(document.readyState==='complete'){loaded();}else{window.addEventListener('load',loaded);}})();</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed script; its values are escaped or integers.
		echo '<noscript><style>' . $css . "</style></noscript>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own @font-face rules.
	}
}

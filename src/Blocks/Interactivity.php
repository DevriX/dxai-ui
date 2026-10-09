<?php
/**
 * The design's states as Interactivity API directives, where the site asks for it.
 *
 * @package DXAI_UI\Blocks
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks;

use DXAI_UI\Settings\Options;

/**
 * A converted page's states — a menu that opens, a header that turns solid on scroll, a FAQ that unfolds, a carousel moved by steps —
 * are marks the compiler wrote into the markup (`dxai-toggle-<state>`, `dxai-cls-…`, `dxai-on-…`, `data-dxai-*`), and the page runtime
 * (Compiler\Motion_Runtime) drives them with a state machine of its own. With the `interactivity` setting on (on unless turned off, since
 * phase 3в measured every design with states the same either way), the same marks are driven by WordPress's Interactivity API instead
 * (phase 3б of docs/PLAN-ARCHITECTURE.md): as the page renders, every mark gets the directive that says the same thing —
 *
 *   a trigger          data-wp-on--click="actions.fire" (and mouseenter/focusin, or mouseleave/focusout/blur, for a hover trigger),
 *                      data-wp-bind--aria-expanded="state.expanded" (data-wp-bind--aria-selected="state.selected" too on a tab);
 *   a projection       data-wp-bind--class="state.cls" — the design's own classes hold `/` and `:` (bg-paper/95, md:flex), which an
 *                      attribute name cannot, so the class list is bound whole, from the element's live classes and its marks;
 *   a panel            the same, with data-wp-style--grid-template-rows / max-height / opacity where the panel animates;
 *   a disabled arrow   data-wp-bind--disabled="state.off";
 *
 * and the scope element becomes the interactive region: `data-wp-interactive="dxai-ui"`, an empty context the store's init callback
 * seeds from the markup the way the runtime seeds its machine (so nothing moves on load), and the document and window listeners
 * (a click elsewhere, the scroll, Escape). The store is assets/js/interactivity.js, a script module on `@wordpress/interactivity`,
 * loaded only on a page that has states. The runtime then leaves the states to it and keeps the rest (the reveal on approach, the
 * counting numbers, the Radix parts, the rails). Words that change with a state (`data-dxai-text-<state>`) the store writes itself:
 * a `data-wp-text` could not know the reading the page was compiled at before the machine is seeded.
 *
 * Nothing stored changes: the directives are added at render time and gone when the setting is off, so the two can be compared on
 * the same page (bin/verify-interactivity.php).
 */
final class Interactivity {

	public const MODULE = 'dxai-ui/interactivity';

	/** The store's namespace, as the directives name it. */
	public const STORE = 'dxai-ui';

	/** The context the root starts with; callbacks.init fills it from the markup (the keys exist so their signals do). */
	public const CONTEXT = '{"m":{},"o":{},"d":[],"sc":[],"out":[],"t":{}}';

	public function register(): void {
		add_action( 'init', array( $this, 'register_module' ) );
		// After the scope element is around the content (Page_Scope::wrap at 20) and a lifted footer is out of it (21).
		add_filter( 'the_content', array( self::class, 'enrich' ), 30 );
	}

	/** Whether the site drives a design's states with the Interactivity API: the setting, or `dxai_ui_interactivity`. */
	public static function enabled(): bool {
		return (bool) apply_filters( 'dxai_ui_interactivity', ! empty( Options::get()['interactivity'] ) );
	}

	/** Whether markup holds a state the compiler marked (a trigger, a projection, a panel, a word map, a disabled control). */
	public static function has_states( string $markup ): bool {
		return preg_match( '/(?:^|[\s"])dxai-(?:toggle|cls|on|off)-[A-Za-z_$]/', $markup ) === 1
			|| str_contains( $markup, 'data-dxai-text-' ) || str_contains( $markup, 'data-dxai-disabled' );
	}

	public function register_module(): void {
		if ( ! function_exists( 'wp_register_script_module' ) ) {
			return;
		}
		wp_register_script_module( self::MODULE, DXAI_UI_URL . 'assets/js/interactivity.js', array( '@wordpress/interactivity' ), DXAI_UI_VERSION );
	}

	/** Ask for the store on this page. Before the head is printed: the import map is written there. */
	public static function enqueue(): void {
		if ( self::enabled() && function_exists( 'wp_enqueue_script_module' ) ) {
			wp_enqueue_script_module( self::MODULE );
		}
	}

	/** The attributes that make a scope element the interactive region ('' when the setting is off). */
	public static function root_attrs(): string {
		if ( ! self::enabled() ) {
			return '';
		}

		return ' data-wp-interactive="' . self::STORE . '" data-wp-context=\'' . self::CONTEXT . '\' data-wp-init="callbacks.init"'
			. ' data-wp-on-document--click="actions.outside" data-wp-on-window--scroll="actions.scroll" data-wp-on-document--keydown="actions.escape"';
	}

	/**
	 * `the_content`: the marks' directives, when the setting is on.
	 *
	 * @param string|mixed $content
	 * @return string|mixed
	 */
	public static function enrich( $content ) {
		if ( ! is_string( $content ) || ! str_contains( $content, 'dxai-' ) || ! self::enabled() ) {
			return $content;
		}

		return self::directives( $content );
	}

	/**
	 * Markup with a directive on every element that carries a mark of a state. Pure: the same markup gives the same answer, and an
	 * element that has a directive already is left alone.
	 */
	public static function directives( string $html ): string {
		return (string) preg_replace_callback(
			'/<([a-zA-Z][\w-]*)\b([^>]*?)(\/?)>/',
			static function ( array $m ): string {
				$attrs = $m[2];
				if ( ! str_contains( $attrs, 'dxai-' ) || str_contains( $attrs, 'data-wp-' ) ) {
					return $m[0];
				}
				$add = self::for_tag( $attrs );
				if ( $add === array() ) {
					return $m[0];
				}

				// The tag's own attributes byte for byte, one space and the directives before any whitespace the tag ended with: taking
				// ` data-wp-…` out gives the tag back as it was.
				$kept = rtrim( $attrs );
				$tail = substr( $attrs, strlen( $kept ) );

				return '<' . $m[1] . $kept . ' ' . implode( ' ', $add ) . $tail . ( $m[3] !== '' ? '/' : '' ) . '>';
			},
			$html
		);
	}

	/**
	 * The directives one element's marks ask for.
	 *
	 * @return array<int, string>
	 */
	private static function for_tag( string $attrs ): array {
		$class = preg_match( '/\sclass="([^"]*)"/', $attrs, $c ) === 1 ? ' ' . html_entity_decode( $c[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . ' ' : ' ';
		$mark  = static fn( string $kind ): bool => preg_match( '/\sdxai-' . $kind . '-[A-Za-z_$][\w$]*(?:--\S+)?\s/', $class ) === 1;
		$has   = static fn( string $name ): bool => preg_match( '/\s' . preg_quote( $name, '/' ) . '(?:=|\s|$)/', ' ' . $attrs ) === 1;
		$value = static function ( string $name ) use ( $attrs ): string {
			return preg_match( '/\s' . preg_quote( $name, '/' ) . '="([^"]*)"/', ' ' . $attrs, $v ) === 1 ? html_entity_decode( $v[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
		};
		$out = array();
		if ( $mark( 'toggle' ) ) {
			$out[] = 'data-wp-on--click="actions.fire"';
			$hover = $value( 'data-dxai-hover' );
			if ( $hover === 'enter' ) {
				$out[] = 'data-wp-on--mouseenter="actions.hoverOn"';
				$out[] = 'data-wp-on--focusin="actions.focusOn"';
			} elseif ( $hover === 'leave' ) {
				$out[] = 'data-wp-on--mouseleave="actions.hoverOff"';
				$out[] = 'data-wp-on--focusout="actions.focusOut"';
				$out[] = 'data-wp-on--blur="actions.hoverOff"';
			}
			if ( ! $has( 'data-dxai-step' ) && ! $has( 'data-dxai-set' ) ) {
				$out[] = 'data-wp-bind--aria-expanded="state.expanded"';
				if ( $value( 'role' ) === 'tab' ) {
					$out[] = 'data-wp-bind--aria-selected="state.selected"';
				}
			}
		}
		$panel = $mark( 'on' ) || $mark( 'off' );
		if ( $mark( 'cls' ) || $panel ) {
			$out[] = 'data-wp-bind--class="state.cls"';
		}
		if ( $panel ) {
			$style = $value( 'style' );
			if ( $has( 'data-open-rows' ) || preg_match( '/grid-template-rows/i', $style ) === 1 ) {
				$out[] = 'data-wp-style--grid-template-rows="state.rows"';
			}
			if ( $has( 'data-open-max' ) || preg_match( '/max-height/i', $style ) === 1 ) {
				$out[] = 'data-wp-style--max-height="state.maxHeight"';
				$out[] = 'data-wp-style--opacity="state.opacity"';
			}
		}
		if ( $has( 'data-dxai-disabled' ) ) {
			$out[] = 'data-wp-bind--disabled="state.off"';
		}

		return $out;
	}
}

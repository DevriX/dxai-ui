<?php
/**
 * Tailwind v4 Preflight, rewritten to live under one scope selector.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler\Tailwind;

/**
 * Tailwind ships Preflight as bare `html`, `body` and `*` rules. A converted
 * pattern is a fragment inside somebody else's theme, so every one of those
 * rules is re-hung under the pattern's scope selector and nothing outside it is
 * touched.
 *
 * Two shapes are produced. A design that already carries its own reset (the
 * `.rv-*` service pages ship one) only gets the handful of rules that cannot
 * disagree with it, matching what `Tailwind_Purger::scoped_preflight()` has
 * always emitted. Everything else gets the full Preflight.
 *
 * Both shapes carry the `--tw-*` defaults, because those are private custom
 * properties: no design reset can collide with them, and without them the
 * transform, filter, shadow and ring utilities compose out of unset variables.
 */
final class Preflight {

	/**
	 * Preflight for $scope.
	 */
	/**
	 * @param string $scope    Selector everything is nested under.
	 * @param string $used_css CSS that will accompany this preflight, when the
	 *                         caller has it. Given, the `@property` table is
	 *                         narrowed to the variables that CSS actually
	 *                         mentions. Omitted, every registration is emitted.
	 */
	public static function css( string $scope, string $used_css = '' ): string {
		$scope = trim( $scope );
		if ( $scope === '' ) {
			return '';
		}

		/*
		 * Always the full set. A design that ships its own reset still writes
		 * `@import "tailwindcss"`, so the real build applies preflight on top
		 * of that reset — trimming ours to a "non-conflicting subset" left the
		 * page without `line-height: 1.5`, and every line of text came out 2 to
		 * 3px short. On the two designs this affected that was 176 nodes each.
		 *
		 * The subset existed so our preflight could not fight the design's own
		 * rules. render() now puts every selector inside `:where()`, so it
		 * cannot: anything the design says wins on specificity alone.
		 */
		$properties = self::needed_properties( $used_css );

		$rules   = self::full_rules();
		$rules[] = self::variable_rule( $properties );

		// Registered first so the guaranteed-invalid properties exist as such
		// before any utility reads them through a var() fallback.
		$out = self::property_rules( $properties );
		foreach ( $rules as $rule ) {
			$out .= self::render( $scope, $rule['selectors'], $rule['declarations'] );
		}

		return $out;
	}


	/**
	 * Full Preflight. `html, :host` becomes the scope element itself, and the
	 * inherited typography there falls back to `inherit` so a host theme's font
	 * survives when the design defines no font tokens of its own.
	 *
	 * @return array<int, array{selectors: string, declarations: array<string, string>}>
	 */
	private static function full_rules(): array {
		return array(
			array(
				'selectors'    => '&, *, ::before, ::after, ::backdrop, ::file-selector-button',
				'declarations' => array(
					'box-sizing'   => 'border-box',
					'margin'       => '0',
					'padding'      => '0',
					'border-width' => '0',
					'border-style' => 'solid',
					'border-color' => 'var(--color-border, currentColor)',
				),
			),
			array(
				'selectors'    => '&',
				'declarations' => array(
					'line-height'                 => '1.5',
					'-webkit-text-size-adjust'    => '100%',
					'-moz-tab-size'               => '4',
					'tab-size'                    => '4',
					'-webkit-tap-highlight-color' => 'transparent',
					'font-family'                 => 'var(--default-font-family, var(--font-sans, inherit))',
					'font-feature-settings'       => 'var(--default-font-feature-settings, normal)',
					'font-variation-settings'     => 'var(--default-font-variation-settings, normal)',
				),
			),
			array(
				'selectors'    => 'hr',
				'declarations' => array(
					'height'           => '0',
					'color'            => 'inherit',
					'border-top-width' => '1px',
				),
			),
			array(
				'selectors'    => 'abbr:where([title])',
				'declarations' => array(
					'-webkit-text-decoration' => 'underline dotted',
					'text-decoration'         => 'underline dotted',
				),
			),
			array(
				'selectors'    => 'h1, h2, h3, h4, h5, h6',
				'declarations' => array(
					'font-size'   => 'inherit',
					'font-weight' => 'inherit',
				),
			),
			array(
				'selectors'    => 'a',
				'declarations' => array(
					'color'                   => 'inherit',
					'-webkit-text-decoration' => 'inherit',
					'text-decoration'         => 'inherit',
				),
			),
			array(
				'selectors'    => 'b, strong',
				'declarations' => array( 'font-weight' => 'bolder' ),
			),
			array(
				'selectors'    => 'code, kbd, samp, pre',
				'declarations' => array(
					'font-family'             => 'var(--default-mono-font-family, var(--font-mono, ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace))',
					'font-feature-settings'   => 'var(--default-mono-font-feature-settings, normal)',
					'font-variation-settings' => 'var(--default-mono-font-variation-settings, normal)',
					'font-size'               => '1em',
				),
			),
			array(
				'selectors'    => 'small',
				'declarations' => array( 'font-size' => '80%' ),
			),
			array(
				'selectors'    => 'sub, sup',
				'declarations' => array(
					'font-size'      => '75%',
					'line-height'    => '0',
					'position'       => 'relative',
					'vertical-align' => 'baseline',
				),
			),
			array(
				'selectors'    => 'sub',
				'declarations' => array( 'bottom' => '-0.25em' ),
			),
			array(
				'selectors'    => 'sup',
				'declarations' => array( 'top' => '-0.5em' ),
			),
			array(
				'selectors'    => 'table',
				'declarations' => array(
					'text-indent'     => '0',
					'border-color'    => 'inherit',
					'border-collapse' => 'collapse',
				),
			),
			array(
				'selectors'    => ':-moz-focusring',
				'declarations' => array( 'outline' => 'auto' ),
			),
			array(
				'selectors'    => 'progress',
				'declarations' => array( 'vertical-align' => 'baseline' ),
			),
			array(
				'selectors'    => 'summary',
				'declarations' => array( 'display' => 'list-item' ),
			),
			array(
				'selectors'    => 'ol, ul, menu',
				'declarations' => array( 'list-style' => 'none' ),
			),
			array(
				'selectors'    => 'dialog',
				'declarations' => array( 'margin' => 'auto' ),
			),
			array(
				'selectors'    => 'img, svg, video, canvas, audio, iframe, embed, object',
				'declarations' => array(
					'display'        => 'block',
					'vertical-align' => 'middle',
				),
			),
			array(
				'selectors'    => 'img, video',
				'declarations' => array(
					'max-width' => '100%',
					'height'    => 'auto',
				),
			),
			array(
				'selectors'    => 'button, input, select, optgroup, textarea, ::file-selector-button',
				'declarations' => array(
					'font'                    => 'inherit',
					'font-feature-settings'   => 'inherit',
					'font-variation-settings' => 'inherit',
					'letter-spacing'          => 'inherit',
					'color'                   => 'inherit',
					'border-radius'           => '0',
					'background-color'        => 'transparent',
					'opacity'                 => '1',
				),
			),
			array(
				'selectors'    => 'button, [role="button"]',
				'declarations' => array( 'cursor' => 'pointer' ),
			),
			array(
				'selectors'    => ':where(select:is([multiple], [size])) optgroup',
				'declarations' => array( 'font-weight' => 'bolder' ),
			),
			array(
				'selectors'    => ':where(select:is([multiple], [size])) optgroup option',
				'declarations' => array( 'padding-inline-start' => '20px' ),
			),
			array(
				'selectors'    => '::file-selector-button',
				'declarations' => array( 'margin-inline-end' => '4px' ),
			),
			array(
				'selectors'    => '::placeholder',
				'declarations' => array(
					'opacity' => '1',
					'color'   => 'color-mix(in oklab, currentColor 50%, transparent)',
				),
			),
			array(
				'selectors'    => 'textarea',
				'declarations' => array( 'resize' => 'vertical' ),
			),
			array(
				'selectors'    => '::-webkit-search-decoration',
				'declarations' => array( '-webkit-appearance' => 'none' ),
			),
			array(
				'selectors'    => '::-webkit-date-and-time-value',
				'declarations' => array(
					'min-height' => '1lh',
					'text-align' => 'inherit',
				),
			),
			array(
				'selectors'    => '::-webkit-datetime-edit',
				'declarations' => array( 'display' => 'inline-flex' ),
			),
			array(
				'selectors'    => '::-webkit-datetime-edit-fields-wrapper',
				'declarations' => array( 'padding' => '0' ),
			),
			array(
				'selectors'    => '::-webkit-datetime-edit, ::-webkit-datetime-edit-year-field, ::-webkit-datetime-edit-month-field, ::-webkit-datetime-edit-day-field, ::-webkit-datetime-edit-hour-field, ::-webkit-datetime-edit-minute-field, ::-webkit-datetime-edit-second-field, ::-webkit-datetime-edit-millisecond-field, ::-webkit-datetime-edit-meridiem-field',
				'declarations' => array( 'padding-block' => '0' ),
			),
			array(
				'selectors'    => ':-moz-ui-invalid',
				'declarations' => array( 'box-shadow' => 'none' ),
			),
			array(
				'selectors'    => 'button, input:where([type="button"], [type="reset"], [type="submit"]), ::file-selector-button',
				'declarations' => array(
					'-webkit-appearance' => 'button',
					'appearance'         => 'button',
				),
			),
			array(
				'selectors'    => '::-webkit-inner-spin-button, ::-webkit-outer-spin-button',
				'declarations' => array( 'height' => 'auto' ),
			),
			array(
				'selectors'    => '[hidden]:where(:not([hidden="until-found"]))',
				'declarations' => array( 'display' => 'none !important' ),
			),
		);
	}

	/**
	 * The custom properties v4 registers with `@property`, verbatim.
	 *
	 * A property v4 leaves without an initial value is guaranteed-invalid on
	 * purpose: that is what makes `var(--tw-duration, <fallback>)` fall back.
	 * Declaring it with an empty value instead resolves to the empty value and
	 * silently kills every fallback — transitions computed to 0s that way.
	 *
	 * @var array<string, array{syntax: string, inherits: bool, initial: string|null}>
	 */
	private const TW_PROPERTIES = array(
		/*
		 * `scale-y-0` emits only `--tw-scale-y` and then `scale: var(--tw-scale-x)
		 * var(--tw-scale-y)`. Without these registrations `--tw-scale-x` resolves
		 * to nothing, the shorthand is invalid, and `scale` falls back to `none`
		 * — so an element the design collapsed to zero height rendered at full
		 * size. On one design that was a dark curtain painted over a whole card.
		 */
		'--tw-scale-x' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '1' ),
		'--tw-scale-y' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '1' ),
		'--tw-scale-z' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '1' ),
		'--tw-translate-x' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '0' ),
		'--tw-translate-y' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '0' ),
		'--tw-translate-z' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '0' ),
		'--tw-rotate-x' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-rotate-y' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-rotate-z' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-skew-x' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-skew-y' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		// The composable halves of `touch-action`.
		'--tw-pan-x' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-pan-y' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-pinch-zoom' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-scroll-snap-strictness' =>array( 'syntax' => '*', 'inherits' => false, 'initial' => 'proximity' ),
		'--tw-space-y-reverse' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '0' ),
		'--tw-space-x-reverse' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '0' ),
		'--tw-divide-x-reverse' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '0' ),
		'--tw-border-style' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'solid' ),
		'--tw-divide-y-reverse' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '0' ),
		'--tw-leading' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-font-weight' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-tracking' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-ordinal' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-slashed-zero' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-numeric-figure' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-numeric-spacing' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-numeric-fraction' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-shadow' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '0 0 #0000' ),
		'--tw-shadow-color' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-shadow-alpha' => array( 'syntax' => '<percentage>', 'inherits' => false, 'initial' => '100%' ),
		'--tw-inset-shadow' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '0 0 #0000' ),
		'--tw-inset-shadow-color' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-inset-shadow-alpha' => array( 'syntax' => '<percentage>', 'inherits' => false, 'initial' => '100%' ),
		'--tw-ring-color' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-ring-shadow' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '0 0 #0000' ),
		'--tw-inset-ring-color' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-inset-ring-shadow' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '0 0 #0000' ),
		'--tw-ring-inset' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-ring-offset-width' => array( 'syntax' => '<length>', 'inherits' => false, 'initial' => '0px' ),
		'--tw-ring-offset-color' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '#fff' ),
		'--tw-ring-offset-shadow' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '0 0 #0000' ),
		'--tw-outline-style' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'solid' ),
		'--tw-blur' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-brightness' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-contrast' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-grayscale' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-hue-rotate' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-invert' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-opacity' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-saturate' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-sepia' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-drop-shadow' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-drop-shadow-color' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-drop-shadow-alpha' => array( 'syntax' => '<percentage>', 'inherits' => false, 'initial' => '100%' ),
		'--tw-drop-shadow-size' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-backdrop-blur' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-backdrop-brightness' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-backdrop-contrast' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-backdrop-grayscale' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-backdrop-hue-rotate' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-backdrop-invert' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-backdrop-opacity' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-backdrop-saturate' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-backdrop-sepia' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		/*
		 * Masks. Every one of these carries an initial value, and that is the
		 * whole mechanism: `mask-b-from-20` sets only
		 * `--tw-mask-bottom-from-position`, and the gradient it builds reads
		 * three more slots that nothing else declares. Unregistered, those
		 * resolve to nothing, the `linear-gradient()` is invalid, and the mask
		 * silently does not apply.
		 */
		'--tw-mask-linear' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'linear-gradient(#fff, #fff)' ),
		'--tw-mask-radial' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'linear-gradient(#fff, #fff)' ),
		'--tw-mask-conic' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'linear-gradient(#fff, #fff)' ),
		'--tw-mask-top' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'linear-gradient(#fff, #fff)' ),
		'--tw-mask-right' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'linear-gradient(#fff, #fff)' ),
		'--tw-mask-bottom' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'linear-gradient(#fff, #fff)' ),
		'--tw-mask-left' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'linear-gradient(#fff, #fff)' ),
		'--tw-mask-top-from-position' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '0%' ),
		'--tw-mask-top-to-position' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '100%' ),
		'--tw-mask-top-from-color' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'black' ),
		'--tw-mask-top-to-color' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'transparent' ),
		'--tw-mask-right-from-position' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '0%' ),
		'--tw-mask-right-to-position' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '100%' ),
		'--tw-mask-right-from-color' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'black' ),
		'--tw-mask-right-to-color' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'transparent' ),
		'--tw-mask-bottom-from-position' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '0%' ),
		'--tw-mask-bottom-to-position' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '100%' ),
		'--tw-mask-bottom-from-color' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'black' ),
		'--tw-mask-bottom-to-color' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'transparent' ),
		'--tw-mask-left-from-position' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '0%' ),
		'--tw-mask-left-to-position' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '100%' ),
		'--tw-mask-left-from-color' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'black' ),
		'--tw-mask-left-to-color' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'transparent' ),
		'--tw-mask-linear-position' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '0deg' ),
		'--tw-mask-linear-from-position' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '0%' ),
		'--tw-mask-linear-to-position' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '100%' ),
		'--tw-mask-linear-from-color' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'black' ),
		'--tw-mask-linear-to-color' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'transparent' ),
		'--tw-mask-radial-from-position' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '0%' ),
		'--tw-mask-radial-to-position' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '100%' ),
		'--tw-mask-radial-from-color' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'black' ),
		'--tw-mask-radial-to-color' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'transparent' ),
		'--tw-mask-radial-shape' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'ellipse' ),
		'--tw-mask-radial-size' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'farthest-corner' ),
		'--tw-mask-radial-position' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'center' ),
		'--tw-mask-conic-position' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '0deg' ),
		'--tw-mask-conic-from-position' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '0%' ),
		'--tw-mask-conic-to-position' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '100%' ),
		'--tw-mask-conic-from-color' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'black' ),
		'--tw-mask-conic-to-color' => array( 'syntax' => '*', 'inherits' => false, 'initial' => 'transparent' ),

		/*
		 * Containment. No initial value, as upstream: the composable
		 * `contain-*` utilities each write one slot and restate
		 * `contain: var(--tw-contain-size,) var(--tw-contain-layout,) …`, and
		 * the empty fallback covers an unset slot. What it does not cover is an
		 * *inherited* one — a custom property inherits unless a registration
		 * says otherwise, so without these four a `contain-paint` nested inside
		 * a `contain-layout` inherited the ancestor's slot and computed
		 * `layout paint` where upstream computes `paint`. `layout` establishes
		 * a containing block and a stacking context, so that is a layout
		 * difference, not a cosmetic one.
		 */
		'--tw-contain-size' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-contain-layout' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-contain-paint' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-contain-style' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),

		/*
		 * Text shadows. The colour has no initial value on purpose — upstream
		 * declares none, and every size utility reads it as
		 * `var(--tw-text-shadow-color, <its own colour>)`, so an unset colour
		 * has to fall through to that fallback rather than resolve to
		 * something. Registering it with an initial value would make every
		 * `text-shadow-lg` paint that value instead of its own.
		 */
		'--tw-text-shadow-color' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-text-shadow-alpha' => array( 'syntax' => '<percentage>', 'inherits' => false, 'initial' => '100%' ),

		// Table cell spacing, kept in two axes so `border-spacing-x` and
		// `border-spacing-y` compose instead of overwriting one another.
		'--tw-border-spacing-x' => array( 'syntax' => '<length>', 'inherits' => false, 'initial' => '0' ),
		'--tw-border-spacing-y' => array( 'syntax' => '<length>', 'inherits' => false, 'initial' => '0' ),

		// Gradients. Our utilities write an inline fallback on every one of
		// these, so a gradient resolves without the registrations — but
		// `background-image: linear-gradient(var(--tw-gradient-stops))` reads
		// that one bare, and the parity harness flagged it as the only pair
		// upstream registers that we did not.
		'--tw-gradient-position' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-gradient-from' => array( 'syntax' => '<color>', 'inherits' => false, 'initial' => '#0000' ),
		'--tw-gradient-via' => array( 'syntax' => '<color>', 'inherits' => false, 'initial' => '#0000' ),
		'--tw-gradient-to' => array( 'syntax' => '<color>', 'inherits' => false, 'initial' => '#0000' ),
		'--tw-gradient-stops' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-gradient-via-stops' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-gradient-from-position' => array( 'syntax' => '<length-percentage>', 'inherits' => false, 'initial' => '0%' ),
		'--tw-gradient-via-position' => array( 'syntax' => '<length-percentage>', 'inherits' => false, 'initial' => '50%' ),
		'--tw-gradient-to-position' => array( 'syntax' => '<length-percentage>', 'inherits' => false, 'initial' => '100%' ),
		'--tw-duration' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-ease' => array( 'syntax' => '*', 'inherits' => false, 'initial' => null ),
		'--tw-content' => array( 'syntax' => '*', 'inherits' => false, 'initial' => '""' ),
	);

	/**
	 * `@property` cannot be nested under a scope, so these are emitted at top
	 * level. The names are v4-private, and a page that already loads Tailwind
	 * registers them identically.
	 */
	/**
	 * The registrations this page needs, keyed as in TW_PROPERTIES.
	 *
	 * The table is Tailwind's whole private variable surface — 119 entries. A
	 * design uses a fraction of them, and every unused one costs bytes on every
	 * page: adding the 42 mask registrations alone took one design's stylesheet
	 * from 54,403 to 59,963 bytes without changing a single rule it renders.
	 *
	 * A variable is needed when the accompanying CSS mentions it at all —
	 * whether it is read bare, read with a fallback, or assigned. That is
	 * deliberately generous: a name that appears anywhere is kept, so the only
	 * registrations dropped are ones nothing can possibly reference.
	 *
	 * @return array<string, array{syntax:string, inherits:bool, initial:?string}>
	 */
	private static function needed_properties( string $used_css ): array {
		if ( trim( $used_css ) === '' ) {
			return self::TW_PROPERTIES;
		}

		$out = array();
		foreach ( self::TW_PROPERTIES as $name => $spec ) {
			if ( str_contains( $used_css, $name ) ) {
				$out[ $name ] = $spec;
			}
		}

		return $out;
	}

	/**
	 * @param array<string, array{syntax:string, inherits:bool, initial:?string}> $properties
	 */
	private static function property_rules( array $properties ): string {
		$out = '';
		foreach ( $properties as $name => $spec ) {
			$out .= "@property {$name} { syntax: \"" . $spec['syntax'] . '"; inherits: ' . ( $spec['inherits'] ? 'true' : 'false' ) . ';';
			if ( null !== $spec['initial'] ) {
				$out .= ' initial-value: ' . $spec['initial'] . ';';
			}
			$out .= " }
";
		}

		return $out;
	}

	/**
	 * Initial values re-stated as ordinary declarations, so they still apply
	 * where `@property` is unsupported. Guaranteed-invalid properties are
	 * deliberately absent.
	 *
	 * @return array{selectors: string, declarations: array<string, string>}
	 */
	private static function variable_rule( array $properties ): array {
		$declarations = array();
		foreach ( $properties as $name => $spec ) {
			if ( null !== $spec['initial'] ) {
				$declarations[ $name ] = (string) $spec['initial'];
			}
		}

		return array(
			'selectors'    => '&, *, ::before, ::after, ::backdrop',
			'declarations' => $declarations,
		);
	}


	/**
	 * One rule, with every selector in the list re-hung under $scope. `&` means
	 * the scope element itself; anything else becomes a descendant.
	 *
	 * Descendants go inside `:where()` so the rule carries no specificity of
	 * its own. Preflight is Tailwind's base layer and must lose to anything a
	 * utility says, and real `@layer` is not usable here — unlayered theme CSS
	 * would then outrank every layer we emit. Without this, preflight's
	 * `h1..h6 { font-size: inherit }` at (0,2,1) beat a design's own
	 * `@utility eyebrow { font-size: .75rem }`, and every heading carrying a
	 * custom utility rendered at the inherited size.
	 *
	 * @param array<string, string> $declarations
	 */
	private static function render( string $scope, string $selectors, array $declarations ): string {
		if ( $declarations === array() ) {
			return '';
		}

		$scoped = array();
		foreach ( Candidate::split_top_level( $selectors, ',' ) as $selector ) {
			$selector = trim( $selector );
			if ( $selector === '' ) {
				continue;
			}
			$scoped[] = '&' === $selector ? $scope : $scope . ' :where(' . $selector . ')';
		}

		if ( $scoped === array() ) {
			return '';
		}

		$body = '';
		foreach ( $declarations as $property => $value ) {
			$body .= $property . ': ' . $value . '; ';
		}

		return implode( ', ', $scoped ) . ' { ' . rtrim( $body ) . " }\n";
	}
}

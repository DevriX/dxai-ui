<?php
/**
 * Isolates Tailwind utilities under .dxai-ui without a global Preflight.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Tailwind_Purger {

	/**
	 * Compile a scoped stylesheet from generated markup + model CSS.
	 *
	 * $browser_css is optional Tailwind output from the admin compiler.
	 * PHP utility mapping remains the WP-CLI / host fallback.
	 */
	/**
	 * @param string $raw_design_css Design stylesheet before `Design_Css::prepare()`
	 *                               removed the `@theme` blocks, which are the
	 *                               only place a v4 design declares its tokens.
	 * @param string $tailwind_config A v3 `tailwind.config.ts`, where the classic
	 *                               Lovable template declares its tokens instead.
	 */
	/**
	 * Whether Tailwind's preflight is emitted.
	 *
	 * Every Lovable design runs on Tailwind and therefore under preflight, so
	 * the page sheet always carried it. A Claude Design export never had
	 * Tailwind: its markup is inline styles over the browser's defaults plus
	 * the few rules of its own reset, and preflight's `h1{font-size:inherit}`,
	 * `p{margin:0}`, `ul{list-style:none}` change what its author saw.
	 */
	private bool $preflight = true;

	/** For a design that never ran under Tailwind. */
	public function without_preflight(): void {
		$this->preflight = false;
	}

	public function compile( string $markup, string $custom_css, int $pattern_id, string $browser_css = '', string $raw_design_css = '', string $wrapper_style = '', string $tailwind_config = '' ): string {
		$scope = '.dxai-ui.dxai-ui--' . $pattern_id;
		$parts = $this->split_global_css( $custom_css );

		// @import and @font-face cannot be nested under a scope.
		$css = $parts['global'];

		// Inline declarations the design put on its root element. Emitted as a
		// rule rather than kept on the block, whose attribute schema has no
		// slot for arbitrary CSS.
		$wrapper_style = trim( $wrapper_style );
		if ( $wrapper_style !== '' ) {
			$css .= $scope . ' { ' . rtrim( $wrapper_style, '; ' ) . " }\n";
		}

		/*
		 * A design that never ran under Tailwind sizes its boxes the browser's
		 * way, content-box, and core's block stylesheets say `box-sizing:
		 * border-box` on `.wp-block-group` and friends. On the H2O export a
		 * `max-width:900px; padding:… 24px` container is 948px wide in the
		 * design and became 900px on the page. `:where()` keeps this at the
		 * scope's own specificity, so a design that DOES declare `* {
		 * box-sizing: border-box }` (Arcus) still wins, its rule being
		 * authored and emitted later.
		 *
		 * Form controls are left out. The browser does not size them the
		 * content-box way — its own sheet makes a <button> (and select, and the
		 * button-like inputs) border-box — so the design measured them
		 * border-box, and forcing content-box on a `wp-block-dxai-ui-link`
		 * button added its padding and border on top: H2O's 46px menu button
		 * rendered 60×50, and a `min-height:48px` submit button 78px tall
		 * instead of 52px. Leaving them out restores whatever the browser gives
		 * each control type, which is what the design had.
		 */
		if ( ! $this->preflight ) {
			$css .= $scope . ' :where([class*="wp-block-"]:not(button, input, select, textarea)) { box-sizing: content-box; }' . "\n";
		}

		if ( ! self::engine_available() ) {
			$css .= $this->preflight ? $this->scoped_preflight( $scope, $parts['scoped'] ) : '';
			if ( $browser_css !== '' ) {
				$css .= $this->scope_browser_sheet( $scope, $browser_css );
			} else {
				$css .= $this->utilities_from_classes( $scope, $this->extract_classes( $markup ) );
			}
			$css .= Motion_Runtime::css( $scope );

			return $css . $this->scope_custom_css( $scope, $parts['scoped'] );
		}

		$theme = Tailwind\Theme::from_css( $raw_design_css !== '' ? $raw_design_css : $custom_css, $tailwind_config );

		// Theme tokens live on the wrapper so utilities resolving to
		// var(--color-*) paint, and :root overrides in the design still win.
		$properties = trim( $theme->custom_properties() );
		$has_font_body = str_contains( $raw_design_css !== '' ? $raw_design_css : $custom_css, '--font-body' )
			|| str_contains( $properties, '--font-body' );
		if ( $properties !== '' ) {
			$css .= $scope . " {\n" . $properties . "\n}\n";
		}
		// Point Preflight's font stack at the design body face before it runs.
		if ( $has_font_body ) {
			$css .= $scope . " {\n"
				. "  --font-sans: var(--font-body);\n"
				. "  --default-font-family: var(--font-body);\n"
				. "}\n";
		}

		/*
		 * Everything that follows preflight is built first, so preflight can be
		 * told which `@property` registrations this page actually references.
		 * Only the computation moves — the emitted order is unchanged, and it
		 * has to be: preflight's reset must precede the utilities it underlies.
		 */
		$utilities = $browser_css !== ''
			? $this->scope_browser_sheet( $scope, $browser_css )
			: ( new Tailwind\Engine( $theme ) )->compile( $markup, $scope );

		$motion = Motion_Runtime::css( $scope );

		// Authored design CSS last: in the source project it is unlayered and
		// therefore beats the utility layer on equal specificity.
		$authored = $this->scope_custom_css( $scope, self::expand_apply( $parts['scoped'], $theme ) );

		if ( $this->preflight ) {
			$css .= Tailwind\Preflight::css( $scope, $utilities . $motion . $authored );
		}

		// Preflight sets font-family via --font-sans; re-assert body face after
		// it so the scope never falls through to the theme/browser serif.
		if ( $has_font_body ) {
			$css .= $scope . " { font-family: var(--font-body, inherit); }\n";
		}

		return $css . $utilities . $motion . $authored;
	}

	/**
	 * Replace every `@apply` with the declarations it stands for.
	 *
	 * Tailwind v3 projects lean on it for the things that set the look of the
	 * whole page. The classic Lovable template's `src/index.css` is
	 *
	 *     * { @apply border-border; }
	 *     body { @apply bg-background text-foreground; }
	 *
	 * and nothing downstream of us understands the directive — a browser drops
	 * the declaration on the floor, so the page rendered on the browser's white
	 * with the browser's default text colour and no default border colour at
	 * all, while every measurement of the utility layer looked perfect.
	 *
	 * Runs here rather than in `Design_Css::prepare()` because expanding a
	 * utility needs the theme, and `prepare()` is static and has none.
	 */
	private static function expand_apply( string $css, Tailwind\Theme $theme ): string {
		if ( ! str_contains( $css, '@apply' ) ) {
			return $css;
		}

		$engine = new Tailwind\Engine( $theme );

		return (string) preg_replace_callback(
			'/@apply\s+([^;{}]+);/',
			static function ( array $matches ) use ( $engine ): string {
				$declarations = array();
				$skipped      = array();

				foreach ( preg_split( '/\s+/', trim( (string) $matches[1] ) ) ?: array() as $class ) {
					$class = trim( $class );
					if ( $class === '' || $class === '!important' ) {
						continue;
					}
					$found = $engine->declarations_for( $class );
					if ( $found === null ) {
						$skipped[] = $class;
						continue;
					}
					foreach ( $found as $property => $value ) {
						$declarations[ $property ] = $value;
					}
				}

				$out = '';
				foreach ( $declarations as $property => $value ) {
					$out .= $property . ': ' . $value . '; ';
				}
				// Named, not dropped: a class we could not expand is a real
				// hole in the page's styling and has to be findable in the
				// sheet rather than inferred from what is missing.
				if ( $skipped !== array() ) {
					$out .= '/* @apply unresolved: ' . implode( ' ', $skipped ) . ' */';
				}

				return trim( $out );
			},
			$css
		);
	}

	/**
	 * The generator is only usable once its core classes are all loadable.
	 *
	 * Falling back to the legacy per-class map keeps a partially deployed
	 * engine from taking the whole conversion down with a fatal.
	 */
	public static function engine_available(): bool {
		foreach ( array( Tailwind\Theme::class, Tailwind\Variants::class, Tailwind\Preflight::class, Tailwind\Engine::class ) as $class ) {
			if ( ! class_exists( $class ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Classes the generator could not resolve, for diagnostics and tests.
	 *
	 * @return array<int, string>
	 */
	public function unresolved( string $markup, string $raw_design_css ): array {
		$engine = new Tailwind\Engine( Tailwind\Theme::from_css( $raw_design_css ) );
		$engine->compile( $markup, '.dxai-ui' );
		$authored = self::authored_classes( $raw_design_css );
		$out      = array();
		foreach ( $engine->unresolved() as $class ) {
			if ( isset( $authored[ $class ] ) ) {
				continue;
			}
			if ( ! Tailwind\Engine::looks_like_utility( $class ) ) {
				continue;
			}
			$out[] = $class;
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Class selectors declared in the design stylesheet (not Tailwind utilities).
	 *
	 * @return array<string, true>
	 */
	private static function authored_classes( string $css ): array {
		if ( $css === '' ) {
			return array();
		}
		preg_match_all( '/\.(-?[_a-zA-Z]+[_a-zA-Z0-9-]*)/', $css, $matches );
		$out = array();
		foreach ( $matches[1] ?? array() as $name ) {
			if ( Tailwind\Engine::looks_like_utility( $name ) ) {
				continue;
			}
			$out[ $name ] = true;
		}

		return $out;
	}

	/**
	 * Isolate browser-compiled Tailwind with CSS nesting so utilities stay under .dxai-ui.
	 */
	private function scope_browser_sheet( string $scope, string $sheet ): string {
		$sheet = trim( self::closes_no_style( $sheet ) );
		if ( $sheet === '' ) {
			return '';
		}

		return "\n/* browser Tailwind */\n{$scope} {\n{$sheet}\n}\n";
	}

	/**
	 * Write the sheet to uploads/dxai-ui/pattern-{id}.css.
	 *
	 * Returns the sheet's absolute URL as it resolves right now, for callers
	 * that print or fetch it (bin/recompile-gtm-css.php echoes it). That URL
	 * is not what gets stored: a writer passes it through
	 * Upload_Paths::for_storage() and keeps `dxai-ui/pattern-{id}.css`, so the
	 * page still finds its sheet after the site changes address. See
	 * Upload_Paths for what the stored absolute URL used to break.
	 */
	public function persist( int $pattern_id, string $css ): string|\WP_Error {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'dxai_ui_css', (string) $upload['error'], array( 'status' => 500 ) );
		}

		$dir = trailingslashit( $upload['basedir'] ) . \DXAI_UI\Support\Upload_Paths::DIR;
		wp_mkdir_p( $dir );
		$path = $dir . '/pattern-' . $pattern_id . '.css';

		$written = file_put_contents( $path, $css ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $written ) {
			return new \WP_Error( 'dxai_ui_css', __( 'Unable to write isolated CSS.', 'dxai-ui' ), array( 'status' => 500 ) );
		}

		return \DXAI_UI\Support\Upload_Paths::url( \DXAI_UI\Support\Upload_Paths::DIR . '/pattern-' . $pattern_id . '.css' );
	}

	/**
	 * Write the behaviour script to uploads/dxai-ui/pattern-{id}.js. Returns
	 * its current absolute URL; writers store Upload_Paths::for_storage() of
	 * it, for the reason given on persist().
	 */
	public function persist_js( int $pattern_id, string $js ): string|\WP_Error {
		$js = preg_replace( '#</script#i', '<\\/script', $js ) ?? $js;
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'dxai_ui_js', (string) $upload['error'], array( 'status' => 500 ) );
		}

		$dir = trailingslashit( $upload['basedir'] ) . \DXAI_UI\Support\Upload_Paths::DIR;
		wp_mkdir_p( $dir );
		$path = $dir . '/pattern-' . $pattern_id . '.js';
		$written = file_put_contents( $path, $js ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $written ) {
			return new \WP_Error( 'dxai_ui_js', __( 'Unable to write isolated JavaScript.', 'dxai-ui' ), array( 'status' => 500 ) );
		}

		return \DXAI_UI\Support\Upload_Paths::url( \DXAI_UI\Support\Upload_Paths::DIR . '/pattern-' . $pattern_id . '.js' );
	}

	/**
	 * @return array<int, string>
	 */
	private function extract_classes( string $markup ): array {
		preg_match_all( '/class(?:Name)?="([^"]+)"/', $markup, $quoted );
		preg_match_all( '/"className":"([^"]+)"/', $markup, $json );
		// The two arms of a class ternary, for the reason given in
		// Tailwind\Engine::extract_classes(): a utility only a state change
		// adds still has to have a rule.
		preg_match_all( '/data-dxai-o(?:n|ff)="([^"]+)"/', $markup, $projected );
		preg_match_all( '/"dxai-o(?:n|ff)":"([^"]+)"/', $markup, $projected_json );

		$tokens = array();
		foreach ( array_merge( $quoted[1] ?? array(), $json[1] ?? array(), $projected[1] ?? array(), $projected_json[1] ?? array() ) as $chunk ) {
			foreach ( preg_split( '/\s+/', html_entity_decode( $chunk, ENT_QUOTES ) ) as $token ) {
				$token = trim( $token );
				if ( $token === '' || str_starts_with( $token, 'wp-block' ) || str_starts_with( $token, 'dxai-ui' ) ) {
					continue;
				}
				$tokens[] = $token;
			}
		}

		return array_values( array_unique( $tokens ) );
	}

	/**
	 * The reset, scoped to the design's wrapper.
	 *
	 * `svg` deliberately does NOT take `max-width` or `height`, and that split
	 * is Tailwind's, not an opinion of ours. Its preflight emits two rules with
	 * two different element lists (verified against the real CLI output, see
	 * bin/tailwind-parity.cjs and any oracle's oracle.css):
	 *
	 *     img,svg,video,canvas,audio,iframe,embed,object{vertical-align:middle;display:block}
	 *     img,video{max-width:100%;height:auto}
	 *
	 * Both branches below used to fold `svg` into the max-width rule, and it
	 * cost real geometry. A lucide icon arrives as `<i data-lucide="chevron-
	 * down" width="14" height="14">` and the runtime turns it into an `<svg>`
	 * carrying those attributes. Give that SVG `max-width: 100%` inside a flex
	 * button and it lays out 0px wide — the attribute width is not a used
	 * width, so the percentage resolves against a containing block whose own
	 * width depends on its children. ARA's practice-areas chevron measured
	 * `w 9→0` against the design, each nav item came out 14px narrower, and the
	 * freed space widened the logo from 74px to 118px: 22 box differences at
	 * 768px, none of which named a cause.
	 *
	 * The design is authored against Tailwind's preflight, and the oracle is
	 * built with the real Tailwind CLI, so matching it is what makes the two
	 * sides comparable at all.
	 */
	private function scoped_preflight( string $scope, string $design_css = '' ): string {
		// Design CSS already ships its own resets (.rv-root *). Do not fight img
		// heights or borders — so this branch keeps to box-sizing, the
		// max-width pair and links, and leaves `display` to the design.
		if ( str_contains( $design_css, '.rv-root' ) || str_contains( $design_css, '.rv-' ) ) {
			return <<<CSS
{$scope}, {$scope} * { box-sizing: border-box; }
{$scope} img, {$scope} video { max-width: 100%; }
{$scope} a { color: inherit; text-decoration: inherit; }

CSS;
		}

		return <<<CSS
{$scope}, {$scope} * { box-sizing: border-box; border-width: 0; border-style: solid; }
{$scope} img, {$scope} svg, {$scope} video, {$scope} canvas, {$scope} audio, {$scope} iframe, {$scope} embed, {$scope} object { vertical-align: middle; display: block; }
{$scope} img, {$scope} video { max-width: 100%; height: auto; }
{$scope} h1, {$scope} h2, {$scope} h3, {$scope} h4, {$scope} h5, {$scope} h6 { font-size: inherit; font-weight: inherit; }
{$scope} a { color: inherit; text-decoration: inherit; }
{$scope} ol, {$scope} ul { list-style: none; margin: 0; padding: 0; }

CSS;
	}

	/**
	 * Best-effort utility reconstruction so Tailwind class names still paint
	 * when a full JIT compiler is unavailable on the host.
	 *
	 * @param array<int, string> $classes
	 */
	private function utilities_from_classes( string $scope, array $classes ): string {
		$out = '';
		foreach ( $classes as $class ) {
			$pseudo = '';
			$token  = $class;
			if ( preg_match( '/^hover:(.+)$/', $token, $hover ) ) {
				$pseudo = ':hover';
				$token  = $hover[1];
			}
			$decl = $this->map_utility( $token );
			if ( $decl === '' ) {
				continue;
			}
			$escaped = $this->escape_class( $class );
			$out    .= "{$scope} .{$escaped}{$pseudo} { {$decl} }\n";
		}

		return $out;
	}

	private function map_utility( string $class ): string {
		$responsive = '';
		if ( preg_match( '/^(sm|md|lg|xl|2xl):(.+)$/', $class, $m ) ) {
			$responsive = $m[1];
			$class      = $m[2];
		}

		$decl = match ( true ) {
			$class === 'flex' => 'display:flex;',
			$class === 'inline-flex' => 'display:inline-flex;',
			$class === 'grid' => 'display:grid;',
			$class === 'hidden' => 'display:none;',
			$class === 'block' => 'display:block;',
			$class === 'inline-block' => 'display:inline-block;',
			$class === 'flex-col' => 'flex-direction:column;',
			$class === 'flex-row' => 'flex-direction:row;',
			$class === 'flex-wrap' => 'flex-wrap:wrap;',
			$class === 'items-center' => 'align-items:center;',
			$class === 'items-start' => 'align-items:flex-start;',
			$class === 'items-end' => 'align-items:flex-end;',
			$class === 'justify-center' => 'justify-content:center;',
			$class === 'justify-between' => 'justify-content:space-between;',
			$class === 'justify-end' => 'justify-content:flex-end;',
			$class === 'text-center' => 'text-align:center;',
			$class === 'text-left' => 'text-align:left;',
			$class === 'text-right' => 'text-align:right;',
			$class === 'font-bold' => 'font-weight:700;',
			$class === 'font-semibold' => 'font-weight:600;',
			$class === 'font-medium' => 'font-weight:500;',
			$class === 'uppercase' => 'text-transform:uppercase;',
			$class === 'truncate' => 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;',
			$class === 'w-full' => 'width:100%;',
			$class === 'h-full' => 'height:100%;',
			$class === 'min-h-screen' => 'min-height:100vh;',
			$class === 'rounded' => 'border-radius:0.25rem;',
			$class === 'rounded-md' => 'border-radius:0.375rem;',
			$class === 'rounded-lg' => 'border-radius:0.5rem;',
			$class === 'rounded-full' => 'border-radius:9999px;',
			$class === 'shadow' => 'box-shadow:0 1px 3px rgb(0 0 0 / 0.1);',
			$class === 'shadow-lg' => 'box-shadow:0 10px 15px rgb(0 0 0 / 0.1);',
			$class === 'relative' => 'position:relative;',
			$class === 'absolute' => 'position:absolute;',
			$class === 'overflow-hidden' => 'overflow:hidden;',
			preg_match( '/^gap-(\d+)$/', $class, $m ) === 1 => 'gap:' . $this->space( (int) $m[1] ) . ';',
			preg_match( '/^p-(\d+)$/', $class, $m ) === 1 => 'padding:' . $this->space( (int) $m[1] ) . ';',
			preg_match( '/^px-(\d+)$/', $class, $m ) === 1 => 'padding-left:' . $this->space( (int) $m[1] ) . ';padding-right:' . $this->space( (int) $m[1] ) . ';',
			preg_match( '/^py-(\d+)$/', $class, $m ) === 1 => 'padding-top:' . $this->space( (int) $m[1] ) . ';padding-bottom:' . $this->space( (int) $m[1] ) . ';',
			preg_match( '/^pt-(\d+)$/', $class, $m ) === 1 => 'padding-top:' . $this->space( (int) $m[1] ) . ';',
			preg_match( '/^pb-(\d+)$/', $class, $m ) === 1 => 'padding-bottom:' . $this->space( (int) $m[1] ) . ';',
			preg_match( '/^pl-(\d+)$/', $class, $m ) === 1 => 'padding-left:' . $this->space( (int) $m[1] ) . ';',
			preg_match( '/^pr-(\d+)$/', $class, $m ) === 1 => 'padding-right:' . $this->space( (int) $m[1] ) . ';',
			preg_match( '/^m-(\d+)$/', $class, $m ) === 1 => 'margin:' . $this->space( (int) $m[1] ) . ';',
			preg_match( '/^mx-auto$/', $class ) === 1 => 'margin-left:auto;margin-right:auto;',
			preg_match( '/^mt-(\d+)$/', $class, $m ) === 1 => 'margin-top:' . $this->space( (int) $m[1] ) . ';',
			preg_match( '/^mb-(\d+)$/', $class, $m ) === 1 => 'margin-bottom:' . $this->space( (int) $m[1] ) . ';',
			preg_match( '/^text-(\d+xl|xs|sm|base|lg|xl)$/', $class, $m ) === 1 => 'font-size:' . $this->font_size( $m[1] ) . ';',
			preg_match( '/^leading-(tight|snug|normal|relaxed|loose)$/', $class, $m ) === 1 => 'line-height:' . $this->leading( $m[1] ) . ';',
			preg_match( '/^bg-\[(.+)\]$/', $class, $m ) === 1 => 'background-color:' . $this->arbitrary( $m[1] ) . ';',
			preg_match( '/^text-\[(.+)\]$/', $class, $m ) === 1 => 'color:' . $this->arbitrary( $m[1] ) . ';',
			preg_match( '/^w-\[(.+)\]$/', $class, $m ) === 1 => 'width:' . $this->arbitrary( $m[1] ) . ';',
			preg_match( '/^max-w-\[(.+)\]$/', $class, $m ) === 1 => 'max-width:' . $this->arbitrary( $m[1] ) . ';',
			preg_match( '/^grid-cols-(\d+)$/', $class, $m ) === 1 => 'grid-template-columns:repeat(' . (int) $m[1] . ',minmax(0,1fr));',
			preg_match( '/^col-span-(\d+)$/', $class, $m ) === 1 => 'grid-column:span ' . (int) $m[1] . ' / span ' . (int) $m[1] . ';',
			preg_match( '/^bg-(slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-(\d{2,3})$/', $class, $m ) === 1
				=> 'background-color:' . $this->palette( $m[1], (int) $m[2] ) . ';',
			preg_match( '/^text-(slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-(\d{2,3})$/', $class, $m ) === 1
				=> 'color:' . $this->palette( $m[1], (int) $m[2] ) . ';',
			preg_match( '/^border-(slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-(\d{2,3})$/', $class, $m ) === 1
				=> 'border-color:' . $this->palette( $m[1], (int) $m[2] ) . ';',
			$class === 'border' => 'border-width:1px;',
			default => '',
		};

		if ( $decl === '' ) {
			return '';
		}

		return match ( $responsive ) {
			'sm' => $decl, // host CSS cannot easily wrap per-class media; keep base
			'md', 'lg', 'xl', '2xl' => $decl,
			default => $decl,
		};
	}

	private function space( int $n ): string {
		return ( 0.25 * $n ) . 'rem';
	}

	private function font_size( string $token ): string {
		return match ( $token ) {
			'xs' => '0.75rem',
			'sm' => '0.875rem',
			'base' => '1rem',
			'lg' => '1.125rem',
			'xl' => '1.25rem',
			'2xl' => '1.5rem',
			'3xl' => '1.875rem',
			'4xl' => '2.25rem',
			'5xl' => '3rem',
			'6xl' => '3.75rem',
			default => '1rem',
		};
	}

	private function leading( string $token ): string {
		return match ( $token ) {
			'tight' => '1.25',
			'snug' => '1.375',
			'normal' => '1.5',
			'relaxed' => '1.625',
			'loose' => '2',
			default => '1.5',
		};
	}

	private function arbitrary( string $value ): string {
		$value = str_replace( '_', ' ', rawurldecode( $value ) );
		return preg_replace( '/[^#a-zA-Z0-9.%(),\s\/-]/', '', $value ) ?? '';
	}

	private function palette( string $hue, int $shade ): string {
		$colors = array(
			'slate' => array( 50 => '#f8fafc', 100 => '#f1f5f9', 200 => '#e2e8f0', 300 => '#cbd5e1', 400 => '#94a3b8', 500 => '#64748b', 600 => '#475569', 700 => '#334155', 800 => '#1e293b', 900 => '#0f172a' ),
			'gray'  => array( 50 => '#f9fafb', 100 => '#f3f4f6', 500 => '#6b7280', 700 => '#374151', 900 => '#111827' ),
			'zinc'  => array( 50 => '#fafafa', 500 => '#71717a', 800 => '#27272a', 900 => '#18181b' ),
			'red'   => array( 500 => '#ef4444', 600 => '#dc2626' ),
			'orange'=> array( 500 => '#f97316' ),
			'amber' => array( 500 => '#f59e0b' ),
			'yellow'=> array( 400 => '#facc15' ),
			'green' => array( 500 => '#22c55e', 600 => '#16a34a' ),
			'emerald'=> array( 500 => '#10b981' ),
			'teal'  => array( 500 => '#14b8a6' ),
			'cyan'  => array( 500 => '#06b6d4' ),
			'sky'   => array( 500 => '#0ea5e9' ),
			'blue'  => array( 500 => '#3b82f6', 600 => '#2563eb', 700 => '#1d4ed8' ),
			'indigo'=> array( 500 => '#6366f1' ),
			'violet'=> array( 500 => '#8b5cf6' ),
			'purple'=> array( 500 => '#a855f7' ),
			'pink'  => array( 500 => '#ec4899' ),
			'rose'  => array( 500 => '#f43f5e' ),
			'white' => array( 0 => '#fff' ),
			'black' => array( 0 => '#000' ),
		);

		return $colors[ $hue ][ $shade ] ?? $colors[ $hue ][ 500 ] ?? 'inherit';
	}

	private function escape_class( string $class ): string {
		return preg_replace( '/([^a-zA-Z0-9_-])/', '\\\\$1', $class ) ?? $class;
	}

	/**
	 * CSS with every `</style` written `<\/style`, so a sheet printed inside a
	 * <style> element (the preview response, a caller that inlines it) cannot
	 * end that element. The escape reads back as the same text in a string.
	 *
	 * This used to be wp_strip_all_tags(), which reads CSS as HTML: everything
	 * from a `<` to the next `>` went, so `@property --g { syntax: "<length>" }`
	 * became `syntax: ""` (an invalid registration: RevOps' animated glow
	 * stepped instead of easing), and a raw SVG data URI or a
	 * `@media (width<768px)` lost its text up to the next child combinator.
	 */
	private static function closes_no_style( string $css ): string {
		return str_ireplace( '</style', '<\/style', $css );
	}

	/**
	 * @return array{global:string, scoped:string}
	 */
	private function split_global_css( string $css ): array {
		$css    = self::closes_no_style( $css );
		$global = '';
		$scoped = '';
		$len    = strlen( $css );
		$i      = 0;
		while ( $i < $len ) {
			if ( preg_match( '/\G@(?:import|font-face)\b/i', $css, $m, 0, $i ) ) {
				$start = $i;
				$i    += strlen( $m[0] );
				$quote = '';
				$depth = 0;
				// Inside an unquoted url(…) a `;` is part of the URL
				// (`…wght@400;700&display=swap`), not the end of the @import.
				$paren = 0;
				while ( $i < $len ) {
					$ch = $css[ $i ];
					if ( $quote !== '' ) {
						if ( $ch === '\\' && $i + 1 < $len ) {
							$i += 2;
							continue;
						}
						if ( $ch === $quote ) {
							$quote = '';
						}
						++$i;
						continue;
					}
					if ( $ch === '"' || $ch === "'" ) {
						$quote = $ch;
						++$i;
						continue;
					}
					if ( $ch === '(' ) {
						++$paren;
					} elseif ( $ch === ')' ) {
						$paren = max( 0, $paren - 1 );
					}
					if ( $ch === '{' ) {
						++$depth;
						++$i;
						continue;
					}
					if ( $ch === '}' ) {
						--$depth;
						++$i;
						if ( $depth <= 0 ) {
							break;
						}
						continue;
					}
					if ( $ch === ';' && $depth === 0 && $paren === 0 ) {
						++$i;
						break;
					}
					++$i;
				}
				$global .= trim( substr( $css, $start, $i - $start ) ) . "\n";
				continue;
			}
			$scoped .= $css[ $i ];
			++$i;
		}

		return array(
			'global' => $global,
			'scoped' => $scoped,
		);
	}

	private function scope_custom_css( string $scope, string $css ): string {
		$css = Design_Css::prepare( $css );
		if ( trim( $css ) === '' ) {
			return '';
		}

		return "\n/* design CSS (scoped) */\n" . Css_Scoper::apply( $scope, $css ) . "\n";
	}
}

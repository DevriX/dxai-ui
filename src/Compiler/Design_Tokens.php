<?php
/**
 * Canonical design tokens derived from an imported template (any brand).
 *
 * Dynamic UI (forms, live prose, CTAs) must consume these CSS variables —
 * never hard-code a client palette like H2O orange/blue.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Design_Tokens {

	public const META = '_dxai_ui_design_tokens';

	/**
	 * Neutral fallback — not a client brand. Returned only when the design
	 * paints no chromatic colour at all (source "default").
	 */
	private const DEFAULT_BRAND = '#2563EB';

	/**
	 * OKLCH chroma below which a colour reads as a neutral (ink, grey,
	 * near-black navy), not as a brand hue.
	 *
	 * Measured on the corpus: every brand/accent the designs paint sits at
	 * 0.14–0.21 (#2F7FD1 0.147, #F2A34A 0.139, #C51527 0.203, --rv-green
	 * #64c040 0.186, --brand-green 0.181), while the neutrals that must not
	 * win sit at or under 0.046: shadcn's untouched slate-900 `--primary`
	 * 0.042, its slate-500 `--muted-foreground` 0.046, H2O's body grey
	 * #3A424B 0.019.
	 */
	private const MIN_CHROMA = 0.06;

	/**
	 * OKLCH hue distance (degrees) under which two colours are one hue family.
	 * Arcus paints #C51527 and its shade #8E0C19 (Δh 0.1°) — one family — and
	 * #FCC931 at Δh 64° from the red: a distinct accent.
	 */
	private const MIN_HUE_GAP = 30.0;

	/**
	 * Custom-property names that declare a brand, most specific first.
	 *
	 * `--blue` (brand) and `--orange` (accent) used to be on these lists. They
	 * are H2O's palette written into the resolver, and a hue name says nothing
	 * about a role: through the fuzzy pass `--blue` matched `--rv-blue`, so ICP
	 * Activated and Market Insights Hub came out with brand #2A7DE1 — their
	 * secondary blue — while `--rv-green` is the colour they paint most.
	 */
	private const BRAND_NAMES = array( '--brand', '--brand-color', '--color-brand', '--primary', '--color-primary', '--primary-color', '--accent-primary' );

	/**
	 * @param array<string, mixed> $harvest_tokens Optional Asset_Harvester tokens.
	 * @return array{
	 *   brand:string,
	 *   accent:string,
	 *   ink:string,
	 *   body:string,
	 *   muted:string,
	 *   surface:string,
	 *   border:string,
	 *   radius:string,
	 *   button_fg:string,
	 *   danger:string,
	 *   font:string,
	 *   source:string
	 * }
	 */
	public static function resolve( string $css, string $html = '', array $harvest_tokens = array() ): array {
		/*
		 * Never read our own output back. Structure_Repository appends
		 * to_css_vars() to the compiled sheet on disk, and for_page() and
		 * Live_Content_Shell resolve from that file again. `--dxai-brand` was
		 * first on the brand list, so the second pass returned whatever the
		 * first pass guessed: Arcus's pattern-88821.css declares no custom
		 * property but that block, and came out source=css-vars with the
		 * neutral #2563EB it had fallen back to the first time (15 of the 36
		 * imported pages resolved #2563EB this way). The literal hexes in the
		 * block also fed heuristic_palette() — Arcus's muted #5F5F5F was its
		 * own earlier muted. So a to_css_vars() block is dropped before
		 * anything reads the sheet, and no `--dxai-*` name is ever a
		 * candidate in a name lookup (pick_color / pick_var); `--dxai-*`
		 * values stay available only to var() expansion — see
		 * strip_own_tokens() for why.
		 */
		$css     = self::strip_own_tokens( $css );
		$classes = self::class_counts( $html );
		$sheet   = self::parse_css( $css, $classes );
		$inline  = self::parse_html_styles( $html, $classes );
		$vars    = $sheet['vars'];
		foreach ( $inline['vars'] as $name => $value ) {
			if ( ! isset( $vars[ $name ] ) ) {
				$vars[ $name ] = $value;
			}
		}
		foreach ( is_array( $harvest_tokens['variables'] ?? null ) ? $harvest_tokens['variables'] : array() as $decl ) {
			if ( ! is_string( $decl ) || ! str_contains( $decl, ':' ) ) {
				continue;
			}
			[ $name, $value ] = array_map( 'trim', explode( ':', $decl, 2 ) );
			$name             = strtolower( $name );
			if ( $name !== '' && $value !== '' && ! isset( $vars[ $name ] ) && ! self::is_ignored_var( $name ) && ! self::is_own_var( $name ) ) {
				$vars[ $name ] = $value;
			}
		}

		$decls = array_merge( $sheet['decls'], $inline['decls'] );
		$used  = self::used_vars( $decls, $vars );
		$tally = self::colour_tally( $decls, $vars );

		/*
		 * Brand: a declared brand variable, but only one the design paints
		 * with and only a hue. 16 of the 17 Lovable stylesheets declare
		 * `--primary`, only Growth Story Hub paints with it (no rule of the
		 * other 15 references var(--color-primary)), and 12 leave it at
		 * shadcn's untouched slate-900 default oklch(0.208 0.042 265.755)
		 * while painting --rv-green / --brand-green everywhere. Next, the
		 * chromatic colour the design paints most as text colour at rest —
		 * the role --dxai-brand is painted in downstream (links, eyebrows,
		 * focus borders in dynamic.css). H2O sets #2F7FD1 as text 24 times
		 * against #F2A34A's 11 and fills the orange 16 times against the
		 * blue's 8: blue brand, orange CTA. Counted as raw hexes in the file
		 * the orange wins, 127 to 60, most of it :focus outlines. The old
		 * warm/cool guess only got H2O right because its brand happens to be
		 * blue, and found nothing in Arcus's red/yellow.
		 */
		$source = 'css-vars';
		$brand  = self::pick_color( $vars, self::BRAND_NAMES, $used, true );
		if ( $brand === '' ) {
			$brand = self::rank_brand( $tally );
			if ( $brand !== '' && ! self::is_var_colour( $brand, $vars ) ) {
				$source = 'heuristic';
			}
		}
		if ( $brand === '' ) {
			// A monochrome design: its own (neutral) primary beats a foreign blue.
			$brand = self::pick_color( $vars, self::BRAND_NAMES, $used );
		}
		if ( $brand === '' ) {
			$brand  = self::DEFAULT_BRAND;
			$source = 'default';
		}

		/*
		 * Accent: a declared accent hue, else the chromatic colour the design
		 * uses most as a fill (--dxai-accent paints CTA backgrounds) in a hue
		 * family other than the brand's. Arcus fills #C51527 35 times and
		 * #FCC931 11 times; the red is already the brand, so the accent is the
		 * yellow. With no second hue anywhere the accent is the brand, as before.
		 */
		$accent = self::pick_color( $vars, array( '--accent', '--color-accent', '--secondary', '--cta', '--button', '--highlight' ), $used, true );
		if ( $accent === '' || $accent === $brand ) {
			$accent = self::rank_accent( $tally, $brand );
		}
		if ( $accent === '' ) {
			$accent = $brand;
		}

		// Inline chrome from the design HTML (Design.com / static exports).
		$html_chrome = self::from_html( $html );
		$heuristic   = self::heuristic_palette( $css . "\n" . $html );

		$ink = self::pick_color( $vars, array( '--foreground', '--text', '--color-text', '--ink', '--black' ) );
		if ( $ink === '' ) {
			$ink = $html_chrome['ink'] !== '' ? $html_chrome['ink'] : $heuristic['ink'];
		}
		// Muted is text (Live_Content_Shell paints `p, li` with it): dark enough to read, ≥ 2:1 on white.
		$muted   = self::pick_color( $vars, array( '--muted-foreground', '--muted', '--color-muted', '--text-muted', '--gray' ), null, false, array( 0.0, 0.475 ) ) ?: $heuristic['muted'];
		// Surface is a light panel (luminance ≥ 0.6, ≈ heuristic_palette()'s "every channel > 210"), never text.
		$surface = self::pick_color( $vars, array( '--surface', '--background', '--bg', '--color-bg', '--card' ), null, false, array( 0.6, 1.0 ) ) ?: $heuristic['surface'];
		$border  = self::pick_color( $vars, array( '--border', '--color-border', '--line' ) );
		if ( $border === '' ) {
			$border = $html_chrome['border'] !== '' ? $html_chrome['border'] : $heuristic['border'];
		}
		$danger = self::pick_color( $vars, array( '--danger', '--error', '--destructive', '--red' ) ) ?: '#DC2626';

		/*
		 * Body copy colour, distinct from ink (the heading / strong colour).
		 * H2O sets #111111 on its headings and #3A424B on 206 runs of body
		 * copy; ink alone cannot carry both. Order: a declared body variable
		 * (RevOps Insight Engine's --dx-body #56605b), then the neutral the
		 * design paints most as text (H2O's #3A424B), then the colour the
		 * page root sets (what un-styled text inherits — Arcus's
		 * `color:#1a1a1a`), then ink for a design with one text colour.
		 * Usage goes before the root colour because H2O's root sets
		 * `color:#111111` — its heading ink — while every paragraph overrides
		 * it inline. Inherited text is invisible to the tally, so a design
		 * whose paragraphs carry no colour of their own but whose labels do
		 * can read a label colour as body; rank_body()'s readability bar keeps
		 * the light caption greys out of that race.
		 */
		$body = self::pick_color( $vars, array( '--body', '--body-color', '--color-body', '--text-body', '--body-text' ), null, false, array( 0.0, 0.1833 ) );
		if ( $body === '' ) {
			$body = self::rank_body( $tally );
		}
		if ( $body === '' ) {
			$body = self::root_color( $decls, $vars );
		}
		if ( $body === '' ) {
			$body = $ink;
		}

		// Prefer control radius (inputs ~8–16px) over pill CTAs (999px).
		$radius = self::pick_var( $vars, array( '--radius', '--border-radius', '--rounded' ) );
		if ( $radius === '' && $html_chrome['radius'] !== '' ) {
			$radius = $html_chrome['radius'];
		}
		$radius = self::as_length( $radius !== '' ? $radius : $html_chrome['radius'] );
		if ( $radius === '' || self::is_pill_radius( $radius ) ) {
			$radius = self::as_length( $html_chrome['radius'] );
		}
		if ( $radius === '' || self::is_pill_radius( $radius ) ) {
			$radius = '0.625rem';
		}

		$font = sanitize_text_field( self::pick_font( $decls, $vars ) );

		$button_fg = self::contrast_ink( $accent );

		return array(
			'brand'     => $brand,
			'accent'    => $accent,
			'ink'       => $ink,
			'body'      => $body,
			'muted'     => $muted,
			'surface'   => $surface,
			'border'    => $border,
			'radius'    => $radius,
			'button_fg' => $button_fg,
			'danger'    => $danger,
			'font'      => $font,
			'source'    => $source,
		);
	}

	/**
	 * @param array<string, string> $tokens
	 */
	public static function to_css_vars( array $tokens, int $page_id ): string {
		$scope = '.dxai-ui.dxai-ui--' . $page_id;
		$map   = array(
			'brand'     => '--dxai-brand',
			'accent'    => '--dxai-accent',
			'ink'       => '--dxai-ink',
			'body'      => '--dxai-body',
			'muted'     => '--dxai-muted',
			'surface'   => '--dxai-surface',
			'border'    => '--dxai-border',
			'radius'    => '--dxai-radius',
			'button_fg' => '--dxai-button-fg',
			'danger'    => '--dxai-danger',
			'font'      => '--dxai-font',
		);
		$lines = array();
		foreach ( $map as $key => $var ) {
			if ( empty( $tokens[ $key ] ) || ! is_string( $tokens[ $key ] ) ) {
				continue;
			}
			$lines[] = '  ' . $var . ': ' . $tokens[ $key ] . ';';
		}
		if ( $lines === array() ) {
			return '';
		}

		return $scope . " {\n" . implode( "\n", $lines ) . "\n}\n";
	}

	/**
	 * @param array<string, string> $tokens
	 */
	public static function persist( int $page_id, array $tokens ): void {
		update_post_meta( $page_id, self::META, self::with_body( $tokens ) );
	}

	/**
	 * @return array<string, string>
	 */
	public static function for_page( int $page_id ): array {
		$stored = get_post_meta( $page_id, self::META, true );
		if ( is_array( $stored ) && ! empty( $stored['brand'] ) ) {
			return self::with_body( array_map( 'strval', $stored ) );
		}

		// Child live pages inherit Home tokens.
		$home = (int) get_post_meta( $page_id, '_dxai_ui_from_live_menu', true ) === 1
			? self::home_id_from_css_url( (string) get_post_meta( $page_id, '_dxai_ui_css_url', true ) )
			: 0;
		if ( $home > 0 && $home !== $page_id ) {
			$stored = get_post_meta( $home, self::META, true );
			if ( is_array( $stored ) && ! empty( $stored['brand'] ) ) {
				return self::with_body( array_map( 'strval', $stored ) );
			}
		}

		$css_url = (string) get_post_meta( $page_id, '_dxai_ui_css_url', true );
		$css     = self::read_css_url( $css_url );
		$html    = (string) get_post_field( 'post_content', $page_id );

		return self::resolve( $css, $html );
	}

	/**
	 * Tokens stored before the body role existed carry no `body`; their body
	 * copy was painted in ink, so ink is the faithful stand-in until the page
	 * is re-imported.
	 *
	 * @param array<string, string> $tokens
	 * @return array<string, string>
	 */
	private static function with_body( array $tokens ): array {
		if ( ( $tokens['body'] ?? '' ) === '' && ( $tokens['ink'] ?? '' ) !== '' ) {
			$tokens['body'] = $tokens['ink'];
		}

		return $tokens;
	}

	/**
	 * Drop every rule block that holds nothing but to_css_vars() role
	 * declarations: the block Structure_Repository appends to the compiled
	 * sheet ("DXAI design tokens") and the one Live_Content_Shell::prose_css()
	 * prefixes to site-{id}.css. Those are an earlier resolve() speaking.
	 *
	 * Only those blocks. A tokenised sheet (pattern-88579.css as rewritten
	 * on 2026-09-24) declares the design's own colours as a `--dxai-*`
	 * palette — `:root,.dxai-ui.dxai-ui{--dxai-neutral-97:#f4f7fa;
	 * --dxai-accent:#f2a34a;…}` — and its rules paint through them (83
	 * `var(--dxai-accent)`), so dropping every `--dxai-*` declaration
	 * would leave that sheet with no colour at all (it resolved to the
	 * #2563EB default that way).
	 */
	private static function strip_own_tokens( string $css ): string {
		return preg_replace( '/(?:^|(?<=[{}]))[^{}]*+\{\s*(?:--dxai-(?:brand|accent|ink|body|muted|surface|border|radius|button-fg|danger|font)\s*:[^;{}]*;?\s*)+\}/i', '', $css ) ?? $css;
	}

	/**
	 * `--tw-*` is Tailwind's per-element state (ring, shadow, gradient
	 * stops), never a design token.
	 */
	private static function is_ignored_var( string $name ): bool {
		return str_starts_with( $name, '--tw-' );
	}

	/**
	 * A name this plugin writes. Never a candidate in a name lookup: its
	 * value is either an earlier resolve() or the tokeniser's renaming of a
	 * colour, and in both cases the name, not the paint, would decide.
	 */
	private static function is_own_var( string $name ): bool {
		return str_starts_with( $name, '--dxai-' );
	}

	/**
	 * Custom properties and ordinary declarations of a stylesheet.
	 *
	 * This used to be one regex over the raw text, `(--[\w-]+)\s*:\s*([^;]+);`,
	 * which read selectors as declarations: H2O's compiled sheet has no custom
	 * property at all, but `.dxai-ui--88022 .dxai-sh-134:focus, … { outline:3px
	 * solid #F2A34A !important;` parsed as a variable `--88022`, so every H2O
	 * page reported source=css-vars. Declarations are now read only from rule
	 * bodies.
	 *
	 * Which value a variable has follows the cascade: the last declaration on
	 * the page root (`:root`, `:host`, `html`, `body`, `@theme`, or the
	 * `.dxai-ui.dxai-ui--{id}` scope the compiler rewrites them to) wins.
	 * First-wins read Growth Story Hub's --primary as shadcn's slate default
	 * (pattern-89173.css:347) although the design overrides it with its acid
	 * green on the same scope at :417. Dark-mode scopes (`.dark`,
	 * `[data-theme=dark]`, `prefers-color-scheme: dark`) are skipped — the
	 * tokens describe the light page (:382 is the `.dark` --primary). A
	 * variable declared only on a component scope keeps its first value.
	 *
	 * @param array<string, int> $classes class_counts() of the markup the sheet styles.
	 * @return array{vars: array<string, string>, decls: array<int, array{0:string, 1:string, 2:bool, 3:bool, 4:int}>}
	 */
	private static function parse_css( string $css, array $classes = array() ): array {
		$root  = array();
		$other = array();
		$decls = array();
		$css   = preg_replace( '#/\*.*?\*/#s', '', $css ) ?? $css;
		$css   = preg_replace( '/@media[^{]*prefers-color-scheme\s*:\s*dark[^{]*(\{(?:[^{}]++|(?1))*+\})/i', '', $css ) ?? $css;
		// Innermost blocks; a prelude starts at the file start or after a brace (keeps the scan linear).
		if ( ! preg_match_all( '/(?:^|(?<=[{}]))([^{}]*+)\{([^{}]*+)\}/', $css, $blocks, PREG_SET_ORDER ) ) {
			return array(
				'vars'  => array(),
				'decls' => array(),
			);
		}
		foreach ( $blocks as $block ) {
			$prelude = $block[1];
			$semi    = strrpos( $prelude, ';' );
			if ( $semi !== false ) {
				$prelude = substr( $prelude, $semi + 1 );
			}
			$prelude = trim( $prelude );
			if ( preg_match( '/\.dark(?![\w-])|\[data-(?:theme|mode|color-scheme)\s*[~|^$*]?=\s*["\']?dark/i', $prelude ) ) {
				continue;
			}
			// @font-face / @property / @page descriptors are not styles on the page.
			if ( str_starts_with( $prelude, '@' ) && ! str_starts_with( strtolower( $prelude ), '@theme' ) ) {
				continue;
			}
			/*
			 * A state rule (every selector :hover / :focus / :active /
			 * :visited) is feedback, not the page at rest, and is kept out
			 * of the colour tally. Every blue or orange text colour in H2O's
			 * stylesheet is a :hover (`.dxai-sh-2:hover { color:#2F7FD1 }`,
			 * `.dxai-sh-109:hover { color:#F2A34A }`), and 71 orange uses are
			 * :focus outlines; counted, they made the orange the brand of
			 * every H2O live page, whose own markup paints almost nothing.
			 */
			$is_root  = $prelude !== '';
			$is_state = $prelude !== '';
			$weight   = 1;
			foreach ( explode( ',', $prelude ) as $selector ) {
				$selector = trim( $selector );
				if ( ! preg_match( '/^(?::root|:host|html|body|\.dxai-ui(?:\.dxai-ui--\d+)?|@theme(?:\s+[a-z-]+)*)$/i', $selector ) ) {
					$is_root = false;
				}
				if ( ! preg_match( '/:(?:hover|focus(?:-visible|-within)?|active|visited)(?![\w-])/i', $selector ) ) {
					$is_state = false;
				}
				// Paint weight: the elements that carry the rule's subject class (see class_counts()).
				if ( $classes !== array() && preg_match( '/\.((?:\\\\.|[\w-])+)(?:::?[\w-]+(?:\([^)]*\))?)*$/', $selector, $cm ) ) {
					$weight = max( $weight, $classes[ stripslashes( $cm[1] ) ] ?? 1 );
				}
			}
			foreach ( self::split_declarations( $block[2] ) as [ $prop, $value ] ) {
				if ( ! str_starts_with( $prop, '--' ) ) {
					$decls[] = array( $prop, $value, $is_root, $is_state, $weight );
					continue;
				}
				// `--font-body: var(--font-body)` is Tailwind v4's @theme inline alias: no value.
				if ( self::is_ignored_var( $prop ) || strtolower( $value ) === 'var(' . $prop . ')' ) {
					continue;
				}
				if ( $is_root ) {
					$root[ $prop ] = $value;
				} elseif ( ! isset( $other[ $prop ] ) ) {
					$other[ $prop ] = $value;
				}
			}
		}

		return array(
			'vars'  => $root + $other,
			'decls' => $decls,
		);
	}

	/**
	 * `<style>` blocks and `style=""` attributes of the design HTML. Inline
	 * styles are where a Claude Design export paints: H2O's body grey #3A424B
	 * appears 206 times in its markup and never in its stylesheet.
	 *
	 * @param array<string, int> $classes class_counts() of the same markup.
	 * @return array{vars: array<string, string>, decls: array<int, array{0:string, 1:string, 2:bool, 3:bool, 4:int}>}
	 */
	private static function parse_html_styles( string $html, array $classes = array() ): array {
		$out = array(
			'vars'  => array(),
			'decls' => array(),
		);
		if ( $html === '' ) {
			return $out;
		}
		if ( preg_match_all( '#<style\b[^>]*>(.*?)</style>#is', $html, $sm ) ) {
			$out = self::parse_css( self::strip_own_tokens( implode( "\n", $sm[1] ) ), $classes );
		}
		if ( preg_match_all( '/\sstyle\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $am, PREG_SET_ORDER ) ) {
			foreach ( $am as $row ) {
				$style = html_entity_decode( $row[1] !== '' ? $row[1] : ( $row[2] ?? '' ), ENT_QUOTES | ENT_HTML5 );
				foreach ( self::split_declarations( $style ) as [ $prop, $value ] ) {
					if ( ! str_starts_with( $prop, '--' ) ) {
						$out['decls'][] = array( $prop, $value, false, false, 1 );
					} elseif ( ! self::is_ignored_var( $prop ) && ! isset( $out['vars'][ $prop ] ) ) {
						$out['vars'][ $prop ] = $value;
					}
				}
			}
		}

		return $out;
	}

	/**
	 * How many elements carry each class in the markup.
	 *
	 * A utility rule is written once however many elements use it, so an
	 * unweighted tally measures how many utilities mention a colour, not how
	 * much of the page it paints: Brand Polish Pass paints its yellow through
	 * one utility, `.border-brand-yellow`, on two elements, and counted once
	 * against the five utilities of its green it fell under rank_accent()'s
	 * floor. A rule now counts once per element carrying its subject class
	 * (the last class of the selector), and at least once.
	 *
	 * @return array<string, int>
	 */
	private static function class_counts( string $html ): array {
		$counts = array();
		if ( ! preg_match_all( '/\sclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $m, PREG_SET_ORDER ) ) {
			return $counts;
		}
		foreach ( $m as $row ) {
			$list = html_entity_decode( $row[1] !== '' ? $row[1] : ( $row[2] ?? '' ), ENT_QUOTES | ENT_HTML5 );
			foreach ( preg_split( '/\s+/', trim( $list ) ) ?: array() as $class ) {
				if ( $class !== '' ) {
					$counts[ $class ] = ( $counts[ $class ] ?? 0 ) + 1;
				}
			}
		}

		return $counts;
	}

	/**
	 * @return array<int, array{0:string, 1:string}>
	 */
	private static function split_declarations( string $body ): array {
		$out = array();
		foreach ( explode( ';', $body ) as $decl ) {
			$colon = strpos( $decl, ':' );
			if ( $colon === false ) {
				continue;
			}
			$prop  = strtolower( trim( substr( $decl, 0, $colon ) ) );
			$value = trim( (string) preg_replace( '/\s*!important\s*$/i', '', substr( $decl, $colon + 1 ) ) );
			if ( $value !== '' && preg_match( '/^-{0,2}[a-z0-9_][\w-]*$/', $prop ) ) {
				$out[] = array( $prop, $value );
			}
		}

		return $out;
	}

	/**
	 * Substitute `var(--x, fallback)` references, the way the browser would.
	 * An undefined variable takes its fallback, and so does one in a
	 * reference cycle (CSS treats a cyclic variable as invalid).
	 *
	 * @param array<string, string> $vars
	 * @param array<string, bool>   $seen Variables being expanded (cycle guard).
	 */
	private static function expand_vars( string $value, array $vars, array $seen = array() ): string {
		if ( stripos( $value, 'var(' ) === false ) {
			return $value;
		}
		$out = '';
		$at  = 0;
		$len = strlen( $value );
		while ( ( $open = stripos( $value, 'var(', $at ) ) !== false ) {
			$out  .= substr( $value, $at, $open - $at );
			$depth = 0;
			$comma = -1;
			for ( $i = $open + 3; $i < $len; $i++ ) {
				$ch = $value[ $i ];
				if ( $ch === '(' ) {
					++$depth;
				} elseif ( $ch === ')' && --$depth === 0 ) {
					break;
				} elseif ( $ch === ',' && $depth === 1 && $comma < 0 ) {
					$comma = $i;
				}
			}
			if ( $i >= $len ) {
				return $out . substr( $value, $open );
			}
			$name     = strtolower( trim( substr( $value, $open + 4, ( $comma < 0 ? $i : $comma ) - $open - 4 ) ) );
			$fallback = $comma < 0 ? '' : trim( substr( $value, $comma + 1, $i - $comma - 1 ) );
			if ( isset( $vars[ $name ] ) && ! isset( $seen[ $name ] ) ) {
				$out .= self::expand_vars( $vars[ $name ], $vars, $seen + array( $name => true ) );
			} else {
				$out .= self::expand_vars( $fallback, $vars, $seen );
			}
			$at = $i + 1;
		}

		return $out . substr( $value, $at );
	}

	/**
	 * Variables the design actually paints with: referenced from a rule or an
	 * inline style, directly or through an alias (`--color-primary:
	 * var(--primary)` makes --primary used once .bg-primary is). Null when the
	 * input references no variable at all — then use cannot be judged and a
	 * declaration is taken at its word.
	 *
	 * @param array<int, array{0:string, 1:string, 2:bool, 3:bool, 4:int}> $decls
	 * @param array<string, string>                                        $vars
	 * @return array<string, bool>|null
	 */
	private static function used_vars( array $decls, array $vars ): ?array {
		$used = array();
		foreach ( $decls as $decl ) {
			if ( preg_match_all( '/var\(\s*(--[\w-]+)/i', $decl[1], $m ) ) {
				foreach ( $m[1] as $name ) {
					$used[ strtolower( $name ) ] = true;
				}
			}
		}
		if ( $used === array() ) {
			return null;
		}
		$refs = array();
		foreach ( $vars as $name => $value ) {
			if ( preg_match_all( '/var\(\s*(--[\w-]+)/i', $value, $m ) ) {
				$refs[ $name ] = array_map( 'strtolower', $m[1] );
			}
		}
		do {
			$grew = false;
			foreach ( $refs as $name => $targets ) {
				if ( ! isset( $used[ $name ] ) ) {
					continue;
				}
				foreach ( $targets as $target ) {
					if ( ! isset( $used[ $target ] ) ) {
						$used[ $target ] = true;
						$grew            = true;
					}
				}
			}
		} while ( $grew );

		return $used;
	}

	/**
	 * How often each solid colour is painted, by role: `text` (color),
	 * `fill` (background*), and `any` (every colour-bearing property). A
	 * variable counts as the colour it expands to, and a tint such as
	 * `color-mix(in oklab, var(--color-acid) 50%, transparent)` counts as its
	 * base colour. Translucent literals do not count (see as_color()).
	 *
	 * @param array<int, array{0:string, 1:string, 2:bool, 3:bool, 4:int}> $decls
	 * @param array<string, string>                                        $vars
	 * @return array{text: array<string, int>, fill: array<string, int>, any: array<string, int>}
	 */
	private static function colour_tally( array $decls, array $vars ): array {
		$tally = array(
			'text' => array(),
			'fill' => array(),
			'any'  => array(),
		);
		foreach ( $decls as [ $prop, $value, , $is_state, $weight ] ) {
			if ( $is_state ) {
				continue;
			}
			if ( $prop === 'color' ) {
				$role = 'text';
			} elseif ( str_starts_with( $prop, 'background' ) ) {
				$role = 'fill';
			} elseif ( preg_match( '/^(?:border|outline|fill|stroke|box-shadow|text-shadow|text-decoration|caret-color|accent-color|column-rule)/', $prop ) ) {
				$role = 'any';
			} else {
				continue;
			}
			$value = self::expand_vars( $value, $vars );
			if ( ! preg_match_all( '/(?<![\w&#-])#[0-9a-f]{3,8}(?![\w-])|\b(?:rgba?|hsla?|oklch|oklab)\([^()]*\)/i', $value, $m ) ) {
				continue;
			}
			foreach ( $m[0] as $literal ) {
				$hex = self::as_color( $literal );
				if ( $hex === '' ) {
					continue;
				}
				$tally['any'][ $hex ] = ( $tally['any'][ $hex ] ?? 0 ) + $weight;
				if ( $role !== 'any' ) {
					$tally[ $role ][ $hex ] = ( $tally[ $role ][ $hex ] ?? 0 ) + $weight;
				}
			}
		}

		return $tally;
	}

	/**
	 * @param array{text: array<string, int>, fill: array<string, int>, any: array<string, int>} $tally
	 */
	private static function rank_brand( array $tally ): string {
		$best = '';
		$top  = array( -1, -1 );
		foreach ( $tally['any'] as $hex => $count ) {
			$hex = (string) $hex;
			if ( self::is_near_white( $hex ) || ! self::is_chromatic( $hex ) ) {
				continue;
			}
			$key = array( $tally['text'][ $hex ] ?? 0, $count );
			if ( $key > $top ) {
				$top  = $key;
				$best = $hex;
			}
		}

		return $best;
	}

	/**
	 * A second hue must be painted at least a tenth as often as the brand
	 * (and more than once) to be the accent. GTM Strategy Hub and Market
	 * Insights Hub paint --rv-green 178 / 193 times and a coral #FF6A6A 4
	 * times; without the bar that coral became the accent of two designs
	 * whose fills are all green. Arcus's yellow (22 vs 73) and H2O's orange
	 * (27 vs 32) clear it easily.
	 *
	 * @param array{text: array<string, int>, fill: array<string, int>, any: array<string, int>} $tally
	 */
	private static function rank_accent( array $tally, string $brand ): string {
		$brand_hue = self::is_chromatic( $brand ) ? self::oklch( $brand )[2] : null;
		$floor     = max( 2, (int) ceil( ( $tally['any'][ $brand ] ?? 0 ) / 10 ) );
		$best      = '';
		$top       = array( -1, -1 );
		foreach ( $tally['any'] as $hex => $count ) {
			$hex = (string) $hex;
			if ( $count < $floor || $hex === $brand || self::is_near_white( $hex ) || ! self::is_chromatic( $hex ) ) {
				continue;
			}
			if ( $brand_hue !== null ) {
				$gap = abs( self::oklch( $hex )[2] - $brand_hue );
				if ( min( $gap, 360 - $gap ) < self::MIN_HUE_GAP ) {
					continue;
				}
			}
			$key = array( $tally['fill'][ $hex ] ?? 0, $count );
			if ( $key > $top ) {
				$top  = $key;
				$best = $hex;
			}
		}

		return $best;
	}

	/**
	 * The neutral painted most often as text colour, among those readable as
	 * body copy: WCAG AA 4.5:1 on white, i.e. relative luminance ≤ 0.1833.
	 * Lighter greys are secondary text: RevOps Insight Engine paints its
	 * dimmed `--dx-dim` #8A938E (3.2:1) on 506 elements against 139 for
	 * #0E1411, and without the bar the dim grey would rank as body (its
	 * --dx-body #56605b wins before the tally is asked).
	 *
	 * @param array{text: array<string, int>, fill: array<string, int>, any: array<string, int>} $tally
	 */
	private static function rank_body( array $tally ): string {
		$best = '';
		$top  = 0;
		foreach ( $tally['text'] as $hex => $count ) {
			$hex = (string) $hex;
			if ( $count > $top && ! self::is_chromatic( $hex ) && self::luminance( $hex ) <= 0.1833 ) {
				$top  = $count;
				$best = $hex;
			}
		}

		return $best;
	}

	/**
	 * WCAG 2 relative luminance of `#RRGGBB`.
	 */
	private static function luminance( string $hex ): float {
		$rgba = self::parse_color( $hex ) ?? array( 0.0, 0.0, 0.0, 1.0 );
		$lin  = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$c     = $rgba[ $i ] / 255;
			$lin[] = $c <= 0.04045 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
		}

		return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
	}

	/**
	 * The text colour the page root sets, last declaration winning. A white
	 * root colour (a dark page) is skipped like every near-white token here.
	 *
	 * @param array<int, array{0:string, 1:string, 2:bool, 3:bool, 4:int}> $decls
	 * @param array<string, string>                                        $vars
	 */
	private static function root_color( array $decls, array $vars ): string {
		$found = '';
		foreach ( $decls as [ $prop, $value, $is_root ] ) {
			if ( ! $is_root || $prop !== 'color' ) {
				continue;
			}
			$hex = self::as_color( self::expand_vars( $value, $vars ) );
			if ( $hex !== '' && ! self::is_near_white( $hex ) ) {
				$found = $hex;
			}
		}

		return $found;
	}

	/**
	 * The page's body font stack.
	 *
	 * The old fallback took the first `font-family` in the blob and cut it at
	 * the first quote or comma (`[^'";,]+`). In a Tailwind v4 sheet the first
	 * font-family is Preflight's `html { font-family:
	 * var(--default-font-family, var(--font-sans, inherit)) }`, so ICP
	 * Activated and Market Insights Hub resolved to the half-call
	 * `var(--default-font-family` — the fallback fired because those sheets
	 * declare no --font-sans (Tailwind v4 emits only the theme variables a
	 * class uses) and set Inter in a later base rule on the same root.
	 * "First in the file" is also why Integration Hub and Messaging
	 * Architects got JetBrains Mono, the font of their labels. The same cut
	 * turned H2O's `'Barlow',system-ui,sans-serif` into a bare `Barlow`,
	 * dropping its fallbacks.
	 *
	 * Now: the font-family the page root ends up with (cascade: last valid
	 * root declaration, variables expanded), then a declared font variable,
	 * then the first valid font-family anywhere; the whole stack is kept.
	 *
	 * @param array<int, array{0:string, 1:string, 2:bool, 3:bool, 4:int}> $decls
	 * @param array<string, string>                                        $vars
	 */
	private static function pick_font( array $decls, array $vars ): string {
		$root  = '';
		$first = '';
		foreach ( $decls as [ $prop, $value, $is_root ] ) {
			if ( $prop !== 'font-family' ) {
				continue;
			}
			$stack = self::font_stack( self::expand_vars( $value, $vars ) );
			if ( $stack === '' ) {
				continue;
			}
			if ( $first === '' ) {
				$first = $stack;
			}
			if ( $is_root ) {
				$root = $stack;
			}
		}
		if ( $root !== '' ) {
			return $root;
		}
		foreach ( array( '--font-body', '--font-sans', '--default-font-family', '--font-family', '--font-base', '--font-text', '--font' ) as $name ) {
			$stack = isset( $vars[ $name ] ) ? self::font_stack( self::expand_vars( $vars[ $name ], $vars ) ) : '';
			if ( $stack !== '' ) {
				return $stack;
			}
		}

		return $first !== '' ? $first : 'inherit';
	}

	/**
	 * A normalised `font-family` list, or '' for a keyword (inherit, …), an
	 * unresolved function, or a non-family value such as a weight.
	 */
	private static function font_stack( string $value ): string {
		if ( str_contains( $value, '(' ) ) {
			return '';
		}
		$families = array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' ) );
		if ( $families === array() || ! preg_match( '/^["\']?[a-z]/i', $families[0] ) ) {
			return '';
		}
		if ( preg_match( '/^(?:inherit|initial|unset|revert|revert-layer)$/i', $families[0] ) ) {
			return '';
		}

		return implode( ', ', $families );
	}

	/**
	 * First named variable (exact names, then a fuzzy name match) that
	 * expands to a solid, not near-white colour.
	 *
	 * `$luminance` is the WCAG relative-luminance window the role can live
	 * in. Before oklch() parsed, every shadcn token fell through to the
	 * heuristic, so a wrong-role match never surfaced; once it parsed, the
	 * surface came out dark in all 17 Lovable designs (`--card-foreground`,
	 * #020618 — text on a card — in 14 of them) and muted landed on
	 * shadcn's `--muted`, a background tint (#E6E6E3 in DevriX Elevate),
	 * instead of muted text.
	 *
	 * @param array<string, string>    $vars
	 * @param array<int, string>       $names
	 * @param array<string, bool>|null $used      When given, the variable must be painted with.
	 * @param bool                     $chromatic When true, the colour must be a hue, not a neutral.
	 * @param array{0:float, 1:float}  $luminance Accepted relative-luminance range.
	 */
	private static function pick_color( array $vars, array $names, ?array $used = null, bool $chromatic = false, array $luminance = array( 0.0, 1.0 ) ): string {
		$accept = static function ( string $key ) use ( $vars, $used, $chromatic, $luminance ): string {
			if ( self::is_own_var( $key ) || ( $used !== null && ! isset( $used[ $key ] ) ) ) {
				return '';
			}
			$hex = self::as_color( self::expand_vars( $vars[ $key ], $vars ) );
			if ( $hex === '' || self::is_near_white( $hex ) || ( $chromatic && ! self::is_chromatic( $hex ) ) ) {
				return '';
			}
			$lum = self::luminance( $hex );
			if ( $lum < $luminance[0] || $lum > $luminance[1] ) {
				return '';
			}

			return $hex;
		};
		foreach ( $names as $name ) {
			$key = strtolower( $name );
			if ( isset( $vars[ $key ] ) ) {
				$hex = $accept( $key );
				if ( $hex !== '' ) {
					return $hex;
				}
			}
		}
		/*
		 * Fuzzy: exact-ish name contains needle, skip *-50 / surface / muted
		 * tints. A brand or accent must also be the base swatch, not a shade,
		 * a state or the text set on it: Integration Hub's first used
		 * "accent" variable is `--rv-accent-deep: #8a6a00`, a dark gold it
		 * uses only as a text colour (.rv-cost-state, .rv-persona-owns).
		 * `-foreground` / `-fg` names are text set on another colour
		 * (`--card-foreground`, `--accent-foreground`) and never match
		 * fuzzily for any role; the one that is a role — shadcn's muted text
		 * `--muted-foreground` — is named exactly in the muted lookup.
		 */
		foreach ( $names as $name ) {
			$needle = ltrim( strtolower( $name ), '-' );
			foreach ( array_keys( $vars ) as $key ) {
				$key = (string) $key;
				if ( ! str_contains( $key, $needle ) || preg_match( '/-(?:50|100|200|surface|bg|background|muted|subtle|foreground|fg)$/', $key ) ) {
					continue;
				}
				if ( $chromatic && preg_match( '/-(?:text|ink|deep|dark|darker|light|lighter|soft|tint|wash|hover|active|border)$/', $key ) ) {
					continue;
				}
				$hex = $accept( $key );
				if ( $hex !== '' ) {
					return $hex;
				}
			}
		}

		return '';
	}

	/**
	 * @param array<string, string> $vars
	 */
	private static function is_var_colour( string $hex, array $vars ): bool {
		foreach ( $vars as $name => $value ) {
			if ( ! self::is_own_var( (string) $name ) && self::as_color( self::expand_vars( $value, $vars ) ) === $hex ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * First named variable with a non-empty, not near-white value (raw).
	 * Colours go through pick_color(); this serves the radius lookup.
	 *
	 * @param array<string, string> $vars
	 * @param array<int, string>    $names
	 */
	private static function pick_var( array $vars, array $names ): string {
		foreach ( $names as $name ) {
			$key = strtolower( $name );
			if ( isset( $vars[ $key ] ) && $vars[ $key ] !== '' ) {
				$color = self::as_color( $vars[ $key ] );
				if ( $color !== '' && self::is_near_white( $color ) ) {
					continue;
				}
				return $vars[ $key ];
			}
		}
		// Fuzzy: exact-ish name contains needle, skip *-50 / surface / muted tints.
		foreach ( $names as $name ) {
			$needle = ltrim( strtolower( $name ), '-' );
			foreach ( $vars as $key => $value ) {
				if ( ! str_contains( (string) $key, $needle ) || $value === '' || self::is_own_var( (string) $key ) ) {
					continue;
				}
				if ( preg_match( '/-(?:50|100|200|surface|bg|background|muted|subtle)$/', (string) $key ) ) {
					continue;
				}
				$color = self::as_color( $value );
				if ( $color !== '' && self::is_near_white( $color ) ) {
					continue;
				}
				return $value;
			}
		}

		return '';
	}

	/**
	 * Border, control radius and ink from inline chrome.
	 *
	 * This also used to guess brand (first cool inline background) and accent
	 * (first warm one). colour_tally() now reads the same inline styles by
	 * role and frequency, and the warm/cool split was H2O's blue/orange
	 * written into the resolver: it found nothing in Arcus's red/yellow.
	 *
	 * @return array{ink:string, border:string, radius:string}
	 */
	private static function from_html( string $html ): array {
		$out = array(
			'ink'    => '',
			'border' => '',
			'radius' => '',
		);
		if ( $html === '' ) {
			return $out;
		}

		// Prefer input/textarea radii for form chrome.
		if ( preg_match_all( '/<(?:input|textarea)[^>]*style="([^"]*)"/i', $html, $im ) ) {
			foreach ( $im[1] as $style ) {
				if ( preg_match( '/border-radius:\s*([0-9.]+(?:px|rem|em))/i', $style, $rm ) ) {
					$len = self::as_length( $rm[1] );
					if ( $len !== '' && ! self::is_pill_radius( $len ) ) {
						$out['radius'] = $len;
						break;
					}
				}
				if ( $out['border'] === '' && preg_match( '/border(?:-color)?:\s*(?:1px solid\s*)?(#[0-9a-fA-F]{3,8})/i', $style, $bm ) ) {
					$out['border'] = self::as_color( $bm[1] );
				}
			}
		}

		if ( $out['border'] === '' && preg_match( '/border:\s*1px solid\s*(#[0-9a-fA-F]{3,8})/i', $html, $bm ) ) {
			$out['border'] = self::as_color( $bm[1] );
		}

		if ( $out['radius'] === '' && preg_match_all( '/border-radius:\s*([0-9.]+(?:px|rem|em))/i', $html, $rms ) ) {
			foreach ( $rms[1] as $cand ) {
				$len = self::as_length( $cand );
				if ( $len !== '' && ! self::is_pill_radius( $len ) ) {
					$out['radius'] = $len;
					break;
				}
			}
		}

		if ( preg_match( '/color:\s*(#111111|#000000|#0[a-f0-9]{5})\b/i', $html, $im ) ) {
			$out['ink'] = self::as_color( $im[1] );
		}

		return $out;
	}

	private static function is_near_white( string $hex ): bool {
		$hex = ltrim( strtoupper( self::as_color( $hex ) ), '#' );
		if ( strlen( $hex ) !== 6 ) {
			return false;
		}
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );

		return ( ( $r + $g + $b ) / 3 ) > 230;
	}

	private static function is_chromatic( string $hex ): bool {
		return self::as_color( $hex ) !== '' && self::oklch( $hex )[1] >= self::MIN_CHROMA;
	}

	private static function is_pill_radius( string $len ): bool {
		if ( preg_match( '/^([0-9.]+)px$/i', $len, $m ) ) {
			return (float) $m[1] >= 40;
		}
		if ( preg_match( '/^([0-9.]+)rem$/i', $len, $m ) ) {
			return (float) $m[1] >= 2.5;
		}

		return false;
	}

	/**
	 * Neutral fallbacks for ink / muted / surface / border from the literal
	 * hexes in the blob. Brand, accent and font are no longer guessed here:
	 * see rank_brand(), rank_accent() and pick_font().
	 *
	 * @return array{ink:string, muted:string, surface:string, border:string}
	 */
	private static function heuristic_palette( string $blob ): array {
		$counts = array();
		if ( preg_match_all( '/#([0-9a-fA-F]{6})\b/', $blob, $m ) ) {
			foreach ( $m[1] as $hex ) {
				$hex = strtoupper( $hex );
				if ( in_array( $hex, array( 'FFFFFF', '000000', 'EEEEEE', 'F5F5F5', 'FAFAFA' ), true ) ) {
					continue;
				}
				$counts[ $hex ] = ( $counts[ $hex ] ?? 0 ) + 1;
			}
		}
		arsort( $counts );
		$top = array_map( 'strval', array_keys( $counts ) );

		$surface = '';
		$muted   = '';
		$ink     = isset( $counts['111111'] ) || isset( $counts[111111] ) ? '#111111' : '#111827';
		$border  = '#D0D5DD';

		foreach ( $top as $hex ) {
			$hex = strtoupper( str_pad( (string) $hex, 6, '0', STR_PAD_LEFT ) );
			if ( strlen( $hex ) !== 6 ) {
				continue;
			}
			$full = '#' . $hex;
			if ( self::is_near_white( $full ) ) {
				continue;
			}
			$r = hexdec( substr( $hex, 0, 2 ) );
			$g = hexdec( substr( $hex, 2, 2 ) );
			$b = hexdec( substr( $hex, 4, 2 ) );
			if ( $surface === '' && $r > 210 && $g > 210 && $b > 210 ) {
				$surface = $full;
			}
			$avg = ( $r + $g + $b ) / 3;
			if ( $muted === '' && $avg > 70 && $avg < 160 && abs( $r - $g ) < 40 ) {
				$muted = $full;
			}
			if ( $border === '#D0D5DD' && $avg > 180 && $avg < 230 && abs( $r - $g ) < 25 ) {
				$border = $full;
			}
		}

		return array(
			'ink'     => $ink,
			'muted'   => $muted !== '' ? $muted : '#4B5563',
			'surface' => $surface !== '' ? $surface : '#F8FAFC',
			'border'  => $border,
		);
	}

	/**
	 * Any CSS colour this resolver understands, as `#RRGGBB`; '' otherwise.
	 *
	 * 16 of the 17 Lovable (Tailwind v4 / shadcn) sheets write their theme
	 * tokens as oklch(), and this returned '' for every one of them, so
	 * each of those designs fell through to the heuristic. It also returned
	 * rgb()/rgba() strings unconverted, which is_near_white(), contrast_ink()
	 * and the warm/cool tests then could not read.
	 *
	 * Alpha: a colour with alpha below 1 is not a solid token and returns ''.
	 * What it paints depends on what lies under it — the corpus's translucent
	 * values are hairlines and glows (shadcn's dark `--border: oklch(1 0 0 /
	 * 10%)`, --rv-hair rgba(255,255,255,0.14), Arcus's rgba(252,201,49,0)
	 * keyframe end) — and a token such as --dxai-brand is painted on its own.
	 * The caller then moves on to the next candidate. `#RRGGBBAA` used to be
	 * truncated to its RGB (so #FFFFFF80 read as solid white); it follows the
	 * same rule now.
	 */
	private static function as_color( string $value ): string {
		$rgba = self::parse_color( $value );
		if ( $rgba === null || $rgba[3] < 0.999 ) {
			return '';
		}

		return sprintf( '#%02X%02X%02X', (int) round( $rgba[0] ), (int) round( $rgba[1] ), (int) round( $rgba[2] ) );
	}

	/**
	 * Hex, rgb()/rgba(), hsl()/hsla(), oklch(), oklab(), white/black, and the
	 * bare `H S% L%` channels Tailwind v3 / shadcn declare for `hsl(var(--x))`.
	 * Legacy comma and modern space / slash syntax both parse.
	 *
	 * @return array{0:float, 1:float, 2:float, 3:float}|null sRGB 0–255 per channel, alpha 0–1.
	 */
	private static function parse_color( string $value ): ?array {
		$v = strtolower( trim( $value ) );
		if ( preg_match( '/^#([0-9a-f]{3,8})$/', $v, $m ) ) {
			$h = $m[1];
			$n = strlen( $h );
			if ( $n === 3 || $n === 4 ) {
				$h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2] . ( $n === 4 ? $h[3] . $h[3] : '' );
			} elseif ( $n !== 6 && $n !== 8 ) {
				return null;
			}
			return array(
				(float) hexdec( substr( $h, 0, 2 ) ),
				(float) hexdec( substr( $h, 2, 2 ) ),
				(float) hexdec( substr( $h, 4, 2 ) ),
				strlen( $h ) === 8 ? hexdec( substr( $h, 6, 2 ) ) / 255 : 1.0,
			);
		}
		if ( $v === 'white' || $v === 'black' ) {
			$c = $v === 'white' ? 255.0 : 0.0;
			return array( $c, $c, $c, 1.0 );
		}
		if ( preg_match( '/^[\d.]+(?:deg)?\s+[\d.]+%\s+[\d.]+%(?:\s*\/\s*[\d.]+%?)?$/', $v ) ) {
			$v = 'hsl(' . $v . ')';
		}
		if ( ! preg_match( '/^(rgba?|hsla?|oklch|oklab)\(\s*([^()]*)\)$/', $v, $m ) ) {
			return null;
		}
		$parts = explode( '/', str_replace( ',', ' ', $m[2] ) );
		$ch    = preg_split( '/\s+/', trim( $parts[0] ) );
		if ( ! is_array( $ch ) || count( $parts ) > 2 || count( $ch ) < 3 || count( $ch ) > 4 || ( count( $ch ) === 4 && isset( $parts[1] ) ) ) {
			return null;
		}
		$alpha = self::channel( isset( $parts[1] ) ? trim( $parts[1] ) : ( $ch[3] ?? '1' ), 1.0 );
		if ( $alpha === null ) {
			return null;
		}
		$alpha = max( 0.0, min( 1.0, $alpha ) );

		switch ( $m[1] ) {
			case 'rgb':
			case 'rgba':
				$r = self::channel( $ch[0], 255.0 );
				$g = self::channel( $ch[1], 255.0 );
				$b = self::channel( $ch[2], 255.0 );
				if ( $r === null || $g === null || $b === null ) {
					return null;
				}
				return array( max( 0.0, min( 255.0, $r ) ), max( 0.0, min( 255.0, $g ) ), max( 0.0, min( 255.0, $b ) ), $alpha );
			case 'hsl':
			case 'hsla':
				$h = self::angle( $ch[0] );
				$s = self::channel( rtrim( $ch[1], '%' ), 1.0 );
				$l = self::channel( rtrim( $ch[2], '%' ), 1.0 );
				if ( $h === null || $s === null || $l === null ) {
					return null;
				}
				return array_merge( self::hsl_to_rgb( $h, max( 0.0, min( 1.0, $s / 100 ) ), max( 0.0, min( 1.0, $l / 100 ) ) ), array( $alpha ) );
			case 'oklch':
				$l = self::channel( $ch[0], 1.0 );
				$c = self::channel( $ch[1], 0.4 );
				$h = self::angle( $ch[2] );
				if ( $l === null || $c === null || $h === null ) {
					return null;
				}
				$rad = deg2rad( $h );
				return array_merge( self::oklab_to_rgb( $l, $c * cos( $rad ), $c * sin( $rad ) ), array( $alpha ) );
			default: // oklab
				$l = self::channel( $ch[0], 1.0 );
				$a = self::channel( $ch[1], 0.4 );
				$b = self::channel( $ch[2], 0.4 );
				if ( $l === null || $a === null || $b === null ) {
					return null;
				}
				return array_merge( self::oklab_to_rgb( $l, $a, $b ), array( $alpha ) );
		}
	}

	/**
	 * A number or percentage channel; `$full` is what 100% means for it
	 * (255 for rgb, 1 for alpha and OK lightness, 0.4 for OK chroma / a / b,
	 * per CSS Color 4). `none` is 0.
	 */
	private static function channel( string $raw, float $full ): ?float {
		if ( $raw === 'none' ) {
			return 0.0;
		}
		if ( preg_match( '/^([-+]?(?:\d+\.?\d*|\.\d+)(?:e[-+]?\d+)?)(%?)$/', $raw, $m ) ) {
			return $m[2] === '%' ? (float) $m[1] / 100 * $full : (float) $m[1];
		}

		return null;
	}

	private static function angle( string $raw ): ?float {
		if ( $raw === 'none' ) {
			return 0.0;
		}
		if ( ! preg_match( '/^([-+]?(?:\d+\.?\d*|\.\d+)(?:e[-+]?\d+)?)(deg|grad|rad|turn)?$/', $raw, $m ) ) {
			return null;
		}
		$units = array(
			''     => 1.0,
			'deg'  => 1.0,
			'grad' => 0.9,
			'rad'  => 180 / M_PI,
			'turn' => 360.0,
		);

		return (float) $m[1] * $units[ $m[2] ?? '' ];
	}

	/**
	 * @return array{0:float, 1:float, 2:float}
	 */
	private static function hsl_to_rgb( float $h, float $s, float $l ): array {
		$h   = fmod( fmod( $h, 360 ) + 360, 360 );
		$amp = $s * min( $l, 1 - $l );
		$out = array();
		foreach ( array( 0, 8, 4 ) as $n ) {
			$k     = fmod( $n + $h / 30, 12 );
			$out[] = 255 * ( $l - $amp * max( -1, min( $k - 3, 9 - $k, 1 ) ) );
		}

		return $out;
	}

	/**
	 * OKLab → linear sRGB → gamma-encoded sRGB (Björn Ottosson's published
	 * matrices, the ones CSS Color 4 uses), then gamut clipping: each linear
	 * channel is clamped to [0, 1]. CSS Color 4 recommends reducing chroma
	 * instead; clamping was kept because it reproduces the sRGB values
	 * Tailwind v4 publishes for its oklch palette. 14 of the 73 distinct
	 * oklch() values in the corpus fall outside sRGB (largest overshoot
	 * 0.063, a purple chart colour), and clipped each lands on Tailwind's
	 * hex: red-600 #E7000B, orange-600 #F54900, amber-400 #FFB900,
	 * purple-500 #AD46FF, emerald-500 #00BC7D, rose-500 #FF2056. ARA
	 * Guide's brand --ara-green oklch(0.74 0.21 150) is one of the 14 and
	 * clips to #00CD5C.
	 *
	 * @return array{0:float, 1:float, 2:float}
	 */
	private static function oklab_to_rgb( float $l, float $a, float $b ): array {
		$l_ = ( $l + 0.3963377774 * $a + 0.2158037573 * $b ) ** 3;
		$m_ = ( $l - 0.1055613458 * $a - 0.0638541728 * $b ) ** 3;
		$s_ = ( $l - 0.0894841775 * $a - 1.2914855480 * $b ) ** 3;
		$linear = array(
			4.0767416621 * $l_ - 3.3077115913 * $m_ + 0.2309699292 * $s_,
			-1.2684380046 * $l_ + 2.6097574011 * $m_ - 0.3413193965 * $s_,
			-0.0041960863 * $l_ - 0.7034186147 * $m_ + 1.7076147010 * $s_,
		);
		$out = array();
		foreach ( $linear as $c ) {
			$c     = max( 0.0, min( 1.0, $c ) );
			$c     = $c <= 0.0031308 ? 12.92 * $c : 1.055 * ( $c ** ( 1 / 2.4 ) ) - 0.055;
			$out[] = 255 * $c;
		}

		return $out;
	}

	/**
	 * `#RRGGBB` → OKLCH (lightness 0–1, chroma, hue in degrees 0–360), the
	 * inverse of oklab_to_rgb(). Used to tell a hue from a neutral and one
	 * hue family from another, which sRGB channel arithmetic does badly
	 * (#FCC931 has g > 200 and failed the old is_warm()).
	 *
	 * @return array{0:float, 1:float, 2:float}
	 */
	private static function oklch( string $hex ): array {
		$rgba = self::parse_color( $hex ) ?? array( 0.0, 0.0, 0.0, 1.0 );
		$lin  = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$c     = $rgba[ $i ] / 255;
			$lin[] = $c <= 0.04045 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
		}
		$l = ( 0.4122214708 * $lin[0] + 0.5363325363 * $lin[1] + 0.0514459929 * $lin[2] ) ** ( 1 / 3 );
		$m = ( 0.2119034982 * $lin[0] + 0.6806995451 * $lin[1] + 0.1073969566 * $lin[2] ) ** ( 1 / 3 );
		$s = ( 0.0883024619 * $lin[0] + 0.2817188376 * $lin[1] + 0.6299787005 * $lin[2] ) ** ( 1 / 3 );
		$ok_a = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
		$ok_b = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;
		$hue  = rad2deg( atan2( $ok_b, $ok_a ) );

		return array(
			0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
			sqrt( $ok_a * $ok_a + $ok_b * $ok_b ),
			$hue < 0 ? $hue + 360 : $hue,
		);
	}

	private static function as_length( string $value ): string {
		$value = trim( $value );
		if ( preg_match( '/^[0-9.]+(?:px|rem|em|%)$/', $value ) ) {
			return $value;
		}

		return '';
	}

	private static function contrast_ink( string $bg ): string {
		$hex = ltrim( strtoupper( self::as_color( $bg ) ), '#' );
		if ( strlen( $hex ) !== 6 ) {
			return '#FFFFFF';
		}
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		// Relative luminance.
		$luma = ( 0.2126 * $r + 0.7152 * $g + 0.0722 * $b ) / 255;

		return $luma > 0.55 ? '#111111' : '#FFFFFF';
	}

	private static function home_id_from_css_url( string $css_url ): int {
		if ( preg_match( '/(?:pattern|site)-(\d+)\.css/', $css_url, $m ) ) {
			return (int) $m[1];
		}

		return 0;
	}

	private static function read_css_url( string $css_url ): string {
		if ( $css_url === '' ) {
			return '';
		}
		// Either stored form: relative to uploads, or an absolute URL that may
		// carry the site's previous scheme and host. See Upload_Paths.
		$path = \DXAI_UI\Support\Upload_Paths::path( $css_url );

		return $path !== '' && is_readable( $path ) ? (string) file_get_contents( $path ) : '';
	}
}

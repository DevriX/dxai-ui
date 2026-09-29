<?php
/**
 * Replace a converted design's literal colours and shadows with tokens.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

use DXAI_UI\Theme\Design_Theme_Json;

/**
 * Turns `background:#F2A34A` into `background:var(--dxai-accent)` — in the
 * page's block markup, in its template parts and sections, and in its compiled
 * stylesheet — and defines those tokens.
 *
 * This is what makes a brand value changeable in one place. Converted content
 * carried every colour as a literal: H2O Away's page alone held 834 of them.
 * Design_Theme_Json now registers the design's palette as theme.json presets,
 * but a preset changes nothing on a page that never refers to it. After this,
 * the page refers to a token, and for the design that is the site's brand the
 * token refers to the preset — so changing Accent in Site Editor > Styles
 * recolours every CTA on every page of that design.
 *
 * WHY `--dxai-*` in the content and not `--wp--preset--*` directly. A site can
 * hold more than one imported design — this development site holds nineteen —
 * and only one of them is the brand. Content that pointed straight at
 * `--wp--preset--color--accent` would repaint every other design in the
 * brand's accent the moment it was adopted. So the content always names the
 * design's OWN token, and each page defines it: as its own literal for every
 * design, and — for the brand design only — as the preset, through a
 * render-time override (brand_css()). Re-branding the site later can never
 * leave a page pointing at somebody else's colour.
 *
 * PIXEL-NEUTRAL by construction: every token is defined as exactly the literal
 * it replaced, so the computed colour does not change. Nothing is rounded,
 * merged into a neighbour or snapped to a scale.
 *
 * BLOCK VALIDITY is the constraint that decides the shape of this class.
 * WordPress re-runs a block's save() and compares its output with the stored
 * HTML byte for byte. Measured on H2O, colours live in exactly two attributes —
 * `dxaiStyle` (dxai-ui/box, dxai-ui/text, dxai-ui/image, core/paragraph,
 * core/group, core/heading) and the string `style` of dxai-ui/link — and every
 * one of those save()s writes the attribute verbatim into the element's
 * `style=""`. So the same function rewrites the attribute and the rendered
 * `style=""`, and save() reproduces the stored HTML. Everything else sourced
 * from the markup (dxai-ui/text's content, dxai-ui/html's payload) is rewritten
 * in the markup itself, which is where save() reads it from.
 *
 * dxai-ui/svg is left alone: SVG presentation attributes (`fill="#fff"`) do not
 * accept var(), and its payload travels in an attribute of its own.
 */
final class Token_Styles {

	/** Post meta holding the palette a page's content was tokenised against. */
	public const META = '_dxai_ui_palette';

	/** Blocks whose markup must never be touched. See the class comment. */
	private const SKIP_BLOCKS = array( 'dxai-ui/svg' );

	/** Attributes that carry a CSS declaration list as a string. */
	private const STYLE_ATTRS = array( 'dxaiStyle', 'style', 'dxaiCss' );

	/**
	 * Tokenise everything one import wrote: pages, patterns, template parts,
	 * and the compiled stylesheet.
	 *
	 * @param array<int, int> $post_ids  Posts whose content belongs to this design.
	 * @param int             $page_id   The design's primary page (palette is stored on it).
	 * @param array<int, int> $pages     Every page of the design (they all get the palette meta).
	 * @param array<string, mixed> $roles Design_Tokens::resolve() output for this design.
	 * @return array{colors:int, shadows:int, literals_before:int, literals_after:int}
	 */
	public static function tokenize_import( array $post_ids, int $page_id, array $pages, array $roles, array $palette = array(), array $derived = array() ): array {
		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
		$derived  = array_values( array_diff( array_unique( array_filter( array_map( 'intval', $derived ) ) ), $post_ids ) );

		if ( ! isset( $palette['colors'] ) ) {
			$palette = self::palette_for( $post_ids, $page_id, $roles );
		}
		$summary = array(
			'colors'          => count( $palette['colors'] ),
			'shadows'         => count( $palette['shadows'] ),
			'literals_before' => 0,
			'literals_after'  => 0,
		);
		if ( $palette['colors'] === array() && $palette['shadows'] === array() ) {
			return $summary;
		}

		/*
		 * The design's own posts AND the pages derived from it (crawled pages).
		 * Both are rewritten; only the former defined the palette — see
		 * palette_for(). A derived page's markup comes from the restyler, which
		 * has already written tokens and snapped anything invented, so this
		 * pass catches only a literal that happens to match exactly.
		 */
		foreach ( array_merge( $post_ids, $derived ) as $id ) {
			$content = (string) get_post_field( 'post_content', $id );
			$summary['literals_before'] += self::count_literals( $content );
			$rewritten = self::rewrite_markup( $content, $palette );
			$summary['literals_after'] += self::count_literals( $rewritten );
			if ( $rewritten !== $content ) {
				wp_update_post(
					array(
						'ID'           => $id,
						'post_content' => wp_slash( $rewritten ),
					)
				);
			}
		}

		/*
		 * Every stylesheet a page of this design loads, not just the home
		 * page's. A crawled page loads `site-{home}.css`, which
		 * Live_Content_Shell copies from the home sheet DURING the crawl — before
		 * this pass — so it still held literals and no token definitions. The
		 * crawled page's content references tokens, so on any design that is not
		 * the site brand those references would have resolved to nothing and
		 * the page would have lost its colours.
		 */
		$sheets = array();
		foreach ( array_merge( array( $page_id ), array_map( 'intval', $pages ), $derived ) as $id ) {
			$path = self::sheet_path( $id );
			if ( $path !== '' ) {
				$sheets[ $path ] = true;
			}
		}
		$definitions = self::definitions_css( $palette, $roles, false );
		foreach ( array_keys( $sheets ) as $path ) {
			if ( ! is_readable( $path ) || ! is_writable( $path ) ) {
				continue;
			}
			$sheet = (string) file_get_contents( $path );
			$css   = self::rewrite_css( $sheet, $palette );
			// The literal definitions travel WITH the sheet, so the file is
			// complete on its own: whatever loads it — the page, the editor
			// canvas, a coverage check reading it off disk — resolves every
			// token it reads. Marked, so a sheet already carrying them is not
			// given a second copy.
			if ( ! str_contains( $css, self::DEFINITIONS_MARK ) ) {
				$css .= "\n" . self::DEFINITIONS_MARK . "\n" . $definitions;
			}
			if ( $css !== $sheet ) {
				file_put_contents( $path, $css );
			}
		}

		$stored = array(
			'colors'  => $palette['colors'],
			'shadows' => $palette['shadows'],
			// The design's own colour tokens (Design_Palette::design_vars()),
			// each with the preset slug brand_css() re-points it at.
			'vars'    => is_array( $palette['vars'] ?? null ) ? $palette['vars'] : array(),
			'roles'   => self::role_slugs( $palette, $roles ),
		);
		foreach ( array_unique( array_merge( array( $page_id ), array_map( 'intval', $pages ), $derived ) ) as $id ) {
			if ( $id > 0 ) {
				update_post_meta( $id, self::META, $stored );
			}
		}

		return $summary;
	}

	/** Marks the token definitions appended to a stylesheet. */
	private const DEFINITIONS_MARK = '/* dxai-ui: design tokens */';

	/**
	 * The palette of one design, from its OWN posts and its home stylesheet.
	 *
	 * Computed once per import and shared: Structure_Repository hands the same
	 * palette to the live restyler (so the tokens it writes are the design's)
	 * and to tokenize_import() at the end. Two palettes computed separately
	 * could name one colour differently, and a token that names nothing renders
	 * as no colour at all.
	 *
	 * Crawled pages are deliberately NOT a source. Their markup is the
	 * restyler's, and a colour it invented must never become part of the brand.
	 *
	 * @param array<int, int>      $post_ids
	 * @param array<string, mixed> $roles
	 * @return array{colors: array<string, array<string, mixed>>, shadows: array<string, array<string, mixed>>}
	 */
	public static function palette_for( array $post_ids, int $page_id, array $roles ): array {
		$contents = array();
		foreach ( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) as $id ) {
			$contents[] = (string) get_post_field( 'post_content', $id );
		}
		$path  = self::sheet_path( $page_id );
		$sheet = $path !== '' && is_readable( $path ) ? (string) file_get_contents( $path ) : '';

		$palette         = Design_Palette::extract( $contents, $sheet, $roles );
		$palette['vars'] = Design_Palette::design_vars( $sheet );

		/*
		 * One swatch per brand colour. A design that declares
		 * `--rv-green: #64c040` and ALSO writes `#64c040` literally in a rule
		 * would otherwise get two presets for one colour, and changing it in
		 * Styles would take two edits with half the page following each.
		 *
		 * So the design's token SHARES the literal's preset: `--rv-green` is
		 * re-pointed at the same `--wp--preset--color--{slug}` the literal's
		 * `--dxai-{slug}` is. The literal's slug wins because it carries the
		 * role name ("Accent", "Brand") a person recognises in Styles, and
		 * because the content already written in its name stays exactly as it
		 * was verified. Exact matches only, on a hex/rgb token — an `oklch()`
		 * token has no exact hex spelling, and a near match would change the
		 * colour.
		 *
		 * Every other design token gets a preset of its own, under a slug that
		 * collides with nothing: not the palette's, not Twenty Twenty-Five's,
		 * not core's defaults (which merge() would drop in silence).
		 */
		$taken = array_fill_keys( array( 'base', 'contrast', 'accent-1', 'accent-2', 'accent-3', 'accent-4', 'accent-5', 'accent-6', 'black', 'white', 'cyan-bluish-gray', 'pale-pink', 'vivid-red', 'luminous-vivid-orange', 'luminous-vivid-amber', 'light-green-cyan', 'vivid-green-cyan', 'pale-cyan-blue', 'vivid-cyan-blue', 'vivid-purple' ), true );
		foreach ( $palette['colors'] as $row ) {
			$taken[ (string) $row['slug'] ] = true;
		}
		foreach ( $palette['shadows'] as $row ) {
			$taken[ (string) $row['slug'] ] = true;
		}
		foreach ( $palette['vars'] as $name => $var ) {
			$key = Design_Palette::color_key( (string) $var['value'] );
			if ( $key !== '' && isset( $palette['colors'][ $key ]['slug'] ) ) {
				$palette['vars'][ $name ]['slug']   = (string) $palette['colors'][ $key ]['slug'];
				$palette['vars'][ $name ]['shared'] = true;
				continue;
			}
			$base = (string) $var['slug'];
			$slug = $base;
			for ( $n = 2; isset( $taken[ $slug ] ); $n++ ) {
				$slug = $base . '-' . $n;
			}
			$taken[ $slug ]                      = true;
			$palette['vars'][ $name ]['slug']   = $slug;
			$palette['vars'][ $name ]['shared'] = false;
		}

		return $palette;
	}

	/**
	 * The CSS to add to a page at render time when its design is the site's
	 * brand: every token re-pointed at the theme.json preset it was registered
	 * as. Empty for any other design, whose literal definitions already live in
	 * its stylesheet.
	 */
	public static function brand_css( int $post_id ): string {
		if ( ! self::is_brand( $post_id ) ) {
			return '';
		}
		$stored = get_post_meta( $post_id, self::META, true );
		if ( ! is_array( $stored ) || ! is_array( $stored['colors'] ?? null ) ) {
			return '';
		}
		$tokens = \DXAI_UI\Compiler\Design_Tokens::for_page( $post_id );
		$css    = self::definitions_css(
			array(
				'colors'  => $stored['colors'],
				'shadows' => is_array( $stored['shadows'] ?? null ) ? $stored['shadows'] : array(),
			),
			is_array( $tokens ) ? $tokens : array(),
			true
		);

		/*
		 * The design's OWN tokens, re-pointed at their presets: a Lovable
		 * design's `--brand-green`, a Tailwind v4 `--primary`. Every class that
		 * reads them — `.bg-primary` through `--color-primary` included — then
		 * follows Styles, with nothing in the markup rewritten.
		 *
		 * `.dxai-ui.dxai-ui` is (0,2,0), the same as the `.dxai-ui.dxai-ui--{id}`
		 * the scoper writes in place of the design's `:root`, and this rule is
		 * added after the design's sheet, so it wins. A `.dark` variant is
		 * (0,3,0) and keeps the design's own dark values.
		 */
		$lines = array();
		foreach ( is_array( $stored['vars'] ?? null ) ? $stored['vars'] : array() as $name => $var ) {
			$slug = sanitize_key( (string) ( $var['slug'] ?? '' ) );
			if ( $slug === '' || preg_match( '/^--[a-z0-9_-]+$/i', (string) $name ) !== 1 ) {
				continue;
			}
			$lines[] = $name . ':var(--wp--preset--color--' . $slug . ')';
		}
		if ( $lines !== array() ) {
			$css .= ':root,.dxai-ui.dxai-ui{' . implode( ';', $lines ) . '}';
		}

		return $css;
	}

	/**
	 * Whether this page's design is the one adopted as the site brand.
	 */
	public static function is_brand( int $post_id ): bool {
		$design = get_option( Design_Theme_Json::OPTION );
		$source = is_array( $design ) ? (string) ( $design['source'] ?? '' ) : '';

		return $source !== '' && (string) get_post_meta( $post_id, '_dxai_ui_source_zip', true ) === $source;
	}

	/**
	 * `:root, .dxai-ui.dxai-ui { --dxai-…: … }` for a palette.
	 *
	 * `.dxai-ui.dxai-ui` repeats the class on purpose. It matches every
	 * converted page's wrapper — including a crawled page, which is wrapped in
	 * its HOME page's scope, so a selector naming the page's own id matched
	 * nothing there — and at (0,2,0) it is as specific as the older
	 * `.dxai-ui.dxai-ui--{id}` block Design_Tokens used to bake into sheets, so
	 * a definition emitted after it wins until a re-import removes the old one.
	 *
	 * @param array{colors:array<string, array<string, mixed>>, shadows:array<string, array<string, mixed>>} $palette
	 * @param array<string, mixed> $roles
	 * @param bool                 $presets Point at theme.json presets (the brand) instead of literals.
	 */
	public static function definitions_css( array $palette, array $roles, bool $presets ): string {
		$lines = array();
		$slugs = array();
		foreach ( $palette['colors'] as $row ) {
			$slug = (string) ( $row['slug'] ?? '' );
			if ( $slug === '' ) {
				continue;
			}
			$slugs[ $slug ] = true;
			$value          = $presets && ! empty( $row['preset'] ) ? 'var(--wp--preset--color--' . $slug . ')' : (string) $row['value'];
			$lines[]        = '--dxai-' . $slug . ':' . $value;
		}
		foreach ( $palette['shadows'] as $row ) {
			$slug = (string) ( $row['slug'] ?? '' );
			if ( $slug === '' ) {
				continue;
			}
			$slugs[ $slug ] = true;
			$value          = $presets ? 'var(--wp--preset--shadow--' . $slug . ')' : (string) $row['value'];
			$lines[]        = '--dxai-' . $slug . ':' . $value;
		}

		/*
		 * The role names Design_Tokens has always emitted, which dynamic.css
		 * (forms) and Live_Content_Shell (crawled-page prose) read:
		 * --dxai-brand, --dxai-accent, --dxai-ink, --dxai-muted, --dxai-surface,
		 * --dxai-border, --dxai-danger, --dxai-button-fg. Each is an ALIAS of the
		 * palette token holding that colour, so a form follows the same token
		 * the page does. One whose own name IS a palette slug is already
		 * defined above — aliasing it to itself would be a cycle, and a cycle
		 * makes the property invalid.
		 */
		$legacy = array(
			'brand'     => 'brand',
			'accent'    => 'accent',
			'ink'       => 'ink',
			'body'      => 'body',
			'muted'     => 'muted',
			'surface'   => 'surface',
			'border'    => 'border',
			'danger'    => 'danger',
			'button_fg' => 'button-fg',
		);
		foreach ( $legacy as $role => $name ) {
			if ( isset( $slugs[ $name ] ) ) {
				continue;
			}
			$key = Design_Palette::color_key( (string) ( $roles[ $role ] ?? '' ) );
			if ( $key === '' ) {
				continue;
			}
			$lines[] = isset( $palette['colors'][ $key ]['slug'] )
				? '--dxai-' . $name . ':var(--dxai-' . $palette['colors'][ $key ]['slug'] . ')'
				: '--dxai-' . $name . ':' . $key;
		}

		$radius = (string) ( $roles['radius'] ?? '' );
		if ( preg_match( '/^\d+(?:\.\d+)?(?:px|rem|em|%)$/', $radius ) === 1 ) {
			$lines[] = '--dxai-radius:' . ( $presets ? 'var(--wp--custom--radius--base,' . $radius . ')' : $radius );
		}
		$font = trim( (string) ( $roles['font'] ?? '' ) );
		if ( $font !== '' && strtolower( $font ) !== 'inherit' && preg_match( '/^[A-Za-z0-9 ,\'"._-]{1,120}$/', $font ) === 1 ) {
			$lines[] = '--dxai-font:' . ( $presets ? 'var(--wp--preset--font-family--brand,' . $font . ')' : $font );
		}

		if ( $lines === array() ) {
			return '';
		}

		return ':root,.dxai-ui.dxai-ui{' . implode( ';', $lines ) . '}';
	}

	/**
	 * Tokenise markup a model wrote, and snap every colour it INVENTED to the
	 * nearest token in the design's palette.
	 *
	 * rewrite_markup() only replaces exact matches, which is right for the
	 * design's own content: every value there is the design's, and a token is
	 * defined as exactly the literal it replaced. A restyled page is different.
	 * The model was told to use the design's tokens, and a colour it wrote
	 * anyway is by definition not part of the brand — keeping it literal would
	 * leave an off-brand value that no Styles change can ever reach. So it is
	 * snapped to the closest solid palette colour and written as that token.
	 *
	 * This replaces Live_Page_Restyler::clamp_colors(), which did the same
	 * snapping in hex and ran its pattern over the WHOLE markup — so an anchor
	 * like `href="#facade"`, every letter of which is a hex digit, was turned
	 * into a colour and the link broke. Only style contexts are touched here.
	 */
	public static function snap_markup( string $markup, array $palette ): string {
		$palette['snap'] = true;

		return self::rewrite_markup( $markup, $palette );
	}

	/**
	 * The nearest SOLID palette colour to a literal, as its slug — or '' when
	 * the palette has none. Distance is weighted Euclidean in sRGB (the
	 * "redmean" approximation), which tracks perceived difference well enough
	 * to pick a neighbour and costs nothing.
	 */
	private static function nearest_slug( string $key, array $palette ): string {
		if ( preg_match( '/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/', $key, $m ) !== 1 ) {
			return '';
		}
		list( $r, $g, $b ) = array( hexdec( $m[1] ), hexdec( $m[2] ), hexdec( $m[3] ) );
		$best  = '';
		$bestd = PHP_FLOAT_MAX;
		foreach ( $palette['colors'] as $pkey => $row ) {
			if ( empty( $row['preset'] ) || preg_match( '/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/', (string) $pkey, $p ) !== 1 ) {
				continue;
			}
			$pr = hexdec( $p[1] );
			$rm = ( $r + $pr ) / 2;
			$dr = $r - $pr;
			$dg = $g - hexdec( $p[2] );
			$db = $b - hexdec( $p[3] );
			$d  = ( 2 + $rm / 256 ) * $dr * $dr + 4 * $dg * $dg + ( 2 + ( 255 - $rm ) / 256 ) * $db * $db;
			if ( $d < $bestd ) {
				$bestd = $d;
				$best  = (string) $row['slug'];
			}
		}

		return $best;
	}

	/**
	 * Tokenise one block document.
	 *
	 * @param array{colors:array<string, array<string, mixed>>, shadows:array<string, array<string, mixed>>} $palette
	 */
	public static function rewrite_markup( string $markup, array $palette ): string {
		if ( $markup === '' || ( $palette['colors'] === array() && $palette['shadows'] === array() ) ) {
			return $markup;
		}
		if ( ! str_contains( $markup, '<!-- wp:' ) ) {
			return self::rewrite_html( $markup, $palette );
		}

		$changed = false;
		$blocks  = self::rewrite_blocks( parse_blocks( $markup ), $palette, $changed );

		// Untouched documents are returned byte for byte, never re-serialised.
		return $changed ? serialize_blocks( $blocks ) : $markup;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	private static function rewrite_blocks( array $blocks, array $palette, bool &$changed ): array {
		foreach ( $blocks as $i => $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( in_array( $name, self::SKIP_BLOCKS, true ) ) {
				continue;
			}

			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			foreach ( self::STYLE_ATTRS as $key ) {
				if ( isset( $attrs[ $key ] ) && is_string( $attrs[ $key ] ) ) {
					$new = self::rewrite_style( $attrs[ $key ], $palette );
					if ( $new !== $attrs[ $key ] ) {
						$attrs[ $key ] = $new;
						$changed       = true;
					}
				}
			}
			$blocks[ $i ]['attrs'] = $attrs;

			foreach ( (array) ( $block['innerContent'] ?? array() ) as $j => $chunk ) {
				if ( is_string( $chunk ) ) {
					$new = self::rewrite_html( $chunk, $palette );
					if ( $new !== $chunk ) {
						$blocks[ $i ]['innerContent'][ $j ] = $new;
						$changed                            = true;
					}
				}
			}
			if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
				$blocks[ $i ]['innerHTML'] = self::rewrite_html( $block['innerHTML'], $palette );
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$blocks[ $i ]['innerBlocks'] = self::rewrite_blocks( $block['innerBlocks'], $palette, $changed );
			}
		}

		return $blocks;
	}

	/**
	 * Tokenise every `style="…"` in a fragment of HTML, and nothing else.
	 */
	public static function rewrite_html( string $html, array $palette ): string {
		if ( ! str_contains( $html, 'style=' ) ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'/(\sstyle=)("([^"]*)"|\'([^\']*)\')/i',
			static function ( array $m ) use ( $palette ): string {
				$double = $m[2][0] === '"';
				$value  = $double ? $m[3] : ( $m[4] ?? '' );
				$new    = self::rewrite_style( $value, $palette );

				return $m[1] . ( $double ? '"' . $new . '"' : "'" . $new . "'" );
			},
			$html
		);
	}

	/**
	 * Tokenise one declaration list, changing only the values that hold a
	 * colour or a shadow the palette knows. Separators, spacing and every other
	 * declaration are kept byte for byte.
	 *
	 * @param array{colors:array<string, array<string, mixed>>, shadows:array<string, array<string, mixed>>} $palette
	 */
	public static function rewrite_style( string $style, array $palette, bool $skip_custom = false ): string {
		$spans = self::value_spans( $style );
		if ( $spans === array() ) {
			return $style;
		}
		// Right to left, so earlier offsets stay valid.
		foreach ( array_reverse( $spans ) as $span ) {
			list( $property, $start, $length ) = $span;
			/*
			 * In a stylesheet, a custom property's DEFINITION is the design's own
			 * token (`--rv-green: #64c040`) and stays a literal value: it is what
			 * brand_css() re-points at a preset for the brand, and what every
			 * other design keeps. Rewriting it into `var(--dxai-…)` would chain
			 * one token through another for nothing.
			 */
			if ( $skip_custom && str_starts_with( $property, '--' ) ) {
				continue;
			}
			$value = substr( $style, $start, $length );
			$new   = self::rewrite_value( $property, $value, $palette );
			if ( $new !== $value ) {
				$style = substr( $style, 0, $start ) . $new . substr( $style, $start + $length );
			}
		}

		return $style;
	}

	private static function rewrite_value( string $property, string $value, array $palette ): string {
		// A custom property of the plugin's own, or core's, is a definition —
		// rewriting `--dxai-accent:#F2A34A` into a reference to itself would be
		// a cycle, which invalidates it.
		if ( str_starts_with( $property, '--dxai-' ) || str_starts_with( $property, '--wp--' ) ) {
			return $value;
		}
		if ( Design_Palette::is_shadow_property( $property ) ) {
			$important = Design_Palette::is_important( $value );
			$bare      = Design_Palette::without_important( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5 ) );
			$key       = Design_Palette::shadow_key( $bare );
			if ( $key !== '' && isset( $palette['shadows'][ $key ]['slug'] ) ) {
				$lead = preg_match( '/^\s*/', $value, $ws ) === 1 ? $ws[0] : '';

				return $lead . 'var(--dxai-' . $palette['shadows'][ $key ]['slug'] . ')' . ( $important ? ' !important' : '' );
			}

			return $value;
		}

		// Colours, outside any url(…) — a data URI's SVG may spell a colour too.
		$parts = preg_split( '/(url\([^)]*\))/i', $value, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return $value;
		}
		foreach ( $parts as $n => $part ) {
			if ( $n % 2 === 1 ) {
				continue;
			}
			$parts[ $n ] = (string) preg_replace_callback(
				Design_Palette::COLOR_PATTERN,
				static function ( array $m ) use ( $palette ): string {
					$key = Design_Palette::color_key( $m[0] );
					if ( isset( $palette['colors'][ $key ]['slug'] ) ) {
						return 'var(--dxai-' . $palette['colors'][ $key ]['slug'] . ')';
					}
					// snap_markup(): an invented solid colour becomes its nearest
					// brand token. A translucent one stays as written — its alpha
					// cannot be carried by a plain token — and is counted by
					// count_literals() like any other leftover.
					if ( ! empty( $palette['snap'] ) ) {
						$slug = self::nearest_slug( $key, $palette );
						if ( $slug !== '' ) {
							return 'var(--dxai-' . $slug . ')';
						}
					}

					return $m[0];
				},
				$part
			);
		}

		return implode( '', $parts );
	}

	/**
	 * Where each declaration's value sits in the string: [ property, offset,
	 * length ]. Semicolons inside parentheses, quotes and HTML entities
	 * (`&quot;` ends in one) do not end a declaration.
	 *
	 * @return array<int, array{0:string, 1:int, 2:int}>
	 */
	private static function value_spans( string $style ): array {
		$spans = array();
		$len   = strlen( $style );
		$depth = 0;
		$quote = '';
		$start = 0;
		for ( $i = 0; $i <= $len; $i++ ) {
			$ch = $i < $len ? $style[ $i ] : ';';
			if ( $quote !== '' ) {
				if ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( $ch === '&' && preg_match( '/\G&(?:[a-zA-Z][a-zA-Z0-9]*|#\d+|#x[0-9a-fA-F]+);/', $style, $e, 0, $i ) === 1 ) {
				$i += strlen( $e[0] ) - 1;
				continue;
			}
			if ( $ch === '"' || $ch === "'" ) {
				$quote = $ch;
			} elseif ( $ch === '(' ) {
				++$depth;
			} elseif ( $ch === ')' ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( $ch === ';' && $depth === 0 ) {
				$colon = strpos( $style, ':', $start );
				if ( $colon !== false && $colon < $i ) {
					$property = strtolower( trim( substr( $style, $start, $colon - $start ) ) );
					if ( $property !== '' ) {
						$spans[] = array( $property, $colon + 1, $i - $colon - 1 );
					}
				}
				$start = $i + 1;
			}
		}

		return $spans;
	}

	/**
	 * Tokenise a stylesheet: every declaration in every rule body, except
	 * inside `@keyframes` (see Design_Palette::without_keyframes()) and
	 * `@property`.
	 *
	 * An `@property` block holds descriptors, not declarations, and its
	 * `initial-value` must be computationally independent: Tailwind v4's
	 * `--tw-shadow { syntax:"*"; initial-value: 0 0 #0000 }` written as
	 * `0 0 var(--dxai-…)` makes the browser drop the whole registration, and
	 * every `shadow-*`, `ring-*` and gradient utility built on it with it.
	 */
	public static function rewrite_css( string $css, array $palette ): string {
		$out    = '';
		$offset = 0;
		// Keyframe and @property blocks are copied through untouched.
		while ( preg_match( '/@(?:-webkit-|-moz-)?keyframes\b[^{]*\{|@property\b[^{]*\{/i', $css, $m, PREG_OFFSET_CAPTURE, $offset ) === 1 ) {
			$start = (int) $m[0][1];
			$out  .= self::rewrite_rule_bodies( substr( $css, $offset, $start - $offset ), $palette );
			$depth = 0;
			$len   = strlen( $css );
			$i     = $start + strlen( $m[0][0] ) - 1;
			for ( ; $i < $len; $i++ ) {
				if ( $css[ $i ] === '{' ) {
					++$depth;
				} elseif ( $css[ $i ] === '}' ) {
					--$depth;
					if ( $depth === 0 ) {
						break;
					}
				}
			}
			$out   .= substr( $css, $start, $i - $start + 1 );
			$offset = $i + 1;
		}

		return $out . self::rewrite_rule_bodies( substr( $css, $offset ), $palette );
	}

	private static function rewrite_rule_bodies( string $css, array $palette ): string {
		return (string) preg_replace_callback(
			'/\{([^{}]*)\}/',
			static fn( array $m ): string => '{' . self::rewrite_style( $m[1], $palette, true ) . '}',
			$css
		);
	}

	/**
	 * Role => palette slug, for every role whose colour the palette holds.
	 *
	 * @return array<string, string>
	 */
	private static function role_slugs( array $palette, array $roles ): array {
		$out = array();
		foreach ( array_keys( Design_Palette::ROLES ) as $role ) {
			$key = Design_Palette::color_key( (string) ( $roles[ $role ] ?? '' ) );
			if ( $key !== '' && isset( $palette['colors'][ $key ]['slug'] ) ) {
				$out[ $role ] = (string) $palette['colors'][ $key ]['slug'];
			}
		}

		return $out;
	}

	/**
	 * Colour literals left inside style contexts — the measure of how much of
	 * a page is still hard-coded.
	 */
	public static function count_literals( string $markup ): int {
		$n = 0;
		foreach ( Design_Palette::style_values( $markup ) as $value ) {
			$n += count( Design_Palette::colors_in( $value ) );
		}
		if ( preg_match_all( '/"(?:dxaiStyle|style)":"((?:[^"\\\\]|\\\\.)*)"/', $markup, $m ) ) {
			foreach ( $m[1] as $value ) {
				$n += count( Design_Palette::colors_in( (string) json_decode( '"' . $value . '"' ) ) );
			}
		}

		return $n;
	}

	/**
	 * The page's sheet on disk, from either stored form of its location.
	 *
	 * This was `str_replace( baseurl, basedir, $url )`, and on a site that had
	 * changed address since the import it replaced nothing: the "path" came
	 * back as the URL, tokenize_import() found no such file and skipped the
	 * sheet, and the design lost its colours without a word. See Upload_Paths.
	 */
	private static function sheet_path( int $page_id ): string {
		return \DXAI_UI\Support\Upload_Paths::for_meta( $page_id, '_dxai_ui_css_url' )['path'];
	}
}

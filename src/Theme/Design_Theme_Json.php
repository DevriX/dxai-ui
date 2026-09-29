<?php
/**
 * The imported design's tokens as theme.json presets, editable in Styles.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Theme;

use DXAI_UI\Compiler\Design_Tokens;

/**
 * Puts one design's tokens into the theme layer of theme.json.
 *
 * Until this existed a brand value lived in two places and was editable in
 * neither: every converted page carried its colours as literal hex in inline
 * styles (H2O Away: 832 literals, `#F2A34A` alone 44 times), and
 * Design_Tokens wrote a copy of them as `--dxai-*` variables scoped to one page
 * (`.dxai-ui.dxai-ui--{id}`), which only the form, the slider and the live-page
 * prose ever read. Changing a button colour meant editing every page.
 *
 * WordPress already has the place a brand value belongs: global styles. A
 * preset registered here surfaces as `--wp--preset--color--accent`, appears in
 * every core colour picker, and can be changed once in Site Editor > Styles.
 *
 * Why the THEME layer, of the four:
 *  - `wp_theme_json_data_default` and `_blocks` sit underneath the theme, so
 *    Twenty Twenty-Five's own values would hide anything set there.
 *  - `wp_theme_json_data_user` runs AFTER a person's saved Styles are decoded
 *    and merges over them on every request — it would silently undo their
 *    edits, and since the REST endpoint reads the saved post directly, the
 *    editor and the front end would disagree about what the colour is.
 *  - The theme layer sits above Twenty Twenty-Five and below the user's
 *    `wp_global_styles` post. So whatever someone changes in Styles wins, and
 *    "Reset" in Styles returns to the imported design rather than to TT5.
 *
 * Additive, deliberately. WP_Theme_JSON::merge() REPLACES a palette or a
 * font-family list per origin rather than appending to it (see its comment on
 * color.palette / typography.fontFamilies), so handing over only the design's
 * colours would have wiped Twenty Twenty-Five's `base`, `contrast` and
 * `accent-1…6` — which every non-converted page on the site is built from.
 * The theme's own entries are carried forward and the design's are added
 * beside them under slugs that collide with neither the theme's nor core's
 * default palette. Stripping TT5 to make the design the whole site is a later
 * step, and one that has to be A/B-measured; this one changes nothing that is
 * on screen today, because no converted page references these presets yet.
 */
final class Design_Theme_Json {

	/** The design chosen as the site's brand: its tokens, and where they came from. */
	public const OPTION = 'dxai_ui_site_design';

	/**
	 * Token key => [ palette slug, label ].
	 *
	 * Slugs are chosen not to collide with Twenty Twenty-Five (`base`,
	 * `contrast`, `accent-1…6`) or with core's default palette (`black`,
	 * `white`, `vivid-red`, …): merge() drops a theme preset whose slug matches
	 * a default one, so a collision would lose the design's value in silence.
	 *
	 * @var array<string, array{0:string, 1:string}>
	 */
	private const PALETTE = array(
		'brand'     => array( 'brand', 'Brand' ),
		'accent'    => array( 'accent', 'Accent' ),
		'button_fg' => array( 'on-accent', 'Text on accent' ),
		'ink'       => array( 'ink', 'Ink' ),
		'body'      => array( 'body', 'Body text' ),
		'muted'     => array( 'muted', 'Muted' ),
		'surface'   => array( 'surface', 'Surface' ),
		'border'    => array( 'border', 'Border' ),
		'danger'    => array( 'danger', 'Danger' ),
	);

	public function register(): void {
		add_filter( 'wp_theme_json_data_theme', array( $this, 'filter' ) );

		// theme.json is cached per request and across requests; a changed brand
		// has to be visible on the next page load, not whenever the cache
		// happens to expire.
		foreach ( array( 'add_option_', 'update_option_', 'delete_option_' ) as $hook ) {
			add_action( $hook . self::OPTION, array( self::class, 'flush' ) );
		}

		add_action( 'trashed_post', array( self::class, 'released' ) );
		add_action( 'deleted_post', array( self::class, 'released' ) );
	}

	/**
	 * The brand's page went to the trash or was deleted: the brand passes to
	 * the most recently imported design whose page is still published, and
	 * is cleared when there is none. Left naming a page that is gone, the
	 * palette would belong to no design on the site, and the design still
	 * here would be styled as a foreign one on its own site (its text-colour
	 * presets pointed back at page-scoped tokens, see Style_Rules).
	 *
	 * @param int|string $post_id
	 */
	public static function released( $post_id ): void {
		$post_id = (int) $post_id;
		$design  = get_option( self::OPTION );
		if ( $post_id < 1 || ! is_array( $design ) || (int) ( $design['page_id'] ?? 0 ) !== $post_id ) {
			return;
		}
		$conversions = get_posts(
			array(
				'post_type'      => \DXAI_UI\Content\Content_Types::CONVERSION,
				'post_status'    => 'publish',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $conversions as $conversion ) {
			$page = (int) get_post_meta( (int) $conversion, '_dxai_ui_page_id', true );
			if ( $page < 1 || $page === $post_id || get_post_status( $page ) !== 'publish' ) {
				continue;
			}
			self::adopt( $page, (string) get_post_meta( $page, '_dxai_ui_source_zip', true ) );
			$now = get_option( self::OPTION );
			if ( is_array( $now ) && (int) ( $now['page_id'] ?? 0 ) === $page ) {
				return;
			}
		}
		delete_option( self::OPTION );
	}

	public static function flush(): void {
		if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
			wp_clean_theme_json_cache();
		}
	}

	/**
	 * Make the design whose primary page this is the site's brand.
	 *
	 * The most recent import wins. On a production site there is one design and
	 * this is simply it; on a site holding several, the one imported last is
	 * the one a person is working on. Recorded with its archive name and page,
	 * so which design owns the palette is never a guess.
	 */
	public static function adopt( int $page_id, string $source = '' ): void {
		if ( $page_id <= 0 ) {
			return;
		}
		/*
		 * Not on a theme that renders the site itself (a classic theme, the
		 * DevriX themes): its palette is the live site's, and it can rewrite
		 * theme.json after this filter — american-restoration re-syncs its
		 * ACF palette at priority 100 — so the design's presets vanished and
		 * every `--dxai-*` token pointing at them turned black, while the
		 * design's `accent` and `border` resolved to the theme's own. The page
		 * then keeps its own literal colours from its stylesheet, and the
		 * site's global styles are left as they are. `dxai_ui_adopt_brand`
		 * overrides this.
		 */
		if ( ! (bool) apply_filters( 'dxai_ui_adopt_brand', \DXAI_UI\Theme\Theme_Compat::may_install_chrome(), $page_id ) ) {
			return;
		}
		$tokens = self::clean( Design_Tokens::for_page( $page_id ) );
		if ( $tokens === array() ) {
			return;
		}

		update_option(
			self::OPTION,
			array(
				'tokens'  => $tokens,
				'page_id' => $page_id,
				'source'  => $source,
			),
			false
		);
	}

	/**
	 * @param \WP_Theme_JSON_Data $theme_json
	 * @return \WP_Theme_JSON_Data
	 */
	public function filter( $theme_json ) {
		$design = get_option( self::OPTION );
		$tokens = is_array( $design ) && is_array( $design['tokens'] ?? null ) ? self::clean( $design['tokens'] ) : array();
		if ( $tokens === array() || ! is_object( $theme_json ) || ! method_exists( $theme_json, 'update_with' ) ) {
			return $theme_json;
		}

		$current = method_exists( $theme_json, 'get_data' ) ? (array) $theme_json->get_data() : array();
		// The design's whole palette, read from its page at filter time so it
		// is always the palette the content was tokenised against — see
		// Token_Styles. Absent on a page imported before tokenising existed,
		// in which case the nine roles are registered on their own.
		$palette  = get_post_meta( (int) ( $design['page_id'] ?? 0 ), \DXAI_UI\Compiler\Token_Styles::META, true );
		$fragment = self::fragment( $tokens, $current, is_array( $palette ) ? $palette : array() );

		return $fragment === array() ? $theme_json : $theme_json->update_with( $fragment );
	}

	/**
	 * The theme.json fragment for these tokens, with the theme's own presets
	 * carried forward (see the class comment on why that is required).
	 *
	 * @param array<string, string> $tokens
	 * @param array<string, mixed>  $current The theme layer as it stands.
	 * @param array<string, mixed>  $design_palette Token_Styles::META for the brand page: colors, shadows.
	 * @return array<string, mixed>
	 */
	public static function fragment( array $tokens, array $current, array $design_palette = array() ): array {
		$settings = array();

		$palette = self::existing( $current, array( 'settings', 'color', 'palette' ) );
		$taken   = array_column( $palette, 'slug' );

		/*
		 * Every solid colour the design uses, under the slug its content was
		 * tokenised against — Token_Styles::brand_css() points
		 * `--dxai-{slug}` at `--wp--preset--color--{slug}`, so a slug missing
		 * here would leave that token pointing at nothing. Translucent colours
		 * are not offered as swatches (Design_Palette marks them preset=false).
		 */
		$colors = is_array( $design_palette['colors'] ?? null ) ? $design_palette['colors'] : array();
		foreach ( $colors as $row ) {
			if ( ! is_array( $row ) || empty( $row['preset'] ) ) {
				continue;
			}
			$slug = sanitize_key( (string) ( $row['slug'] ?? '' ) );
			$hex  = sanitize_hex_color( (string) ( $row['value'] ?? '' ) );
			if ( $slug === '' || ! is_string( $hex ) || $hex === '' || in_array( $slug, $taken, true ) ) {
				continue;
			}
			$taken[]   = $slug;
			$palette[] = array(
				'slug'  => $slug,
				'name'  => sanitize_text_field( (string) ( $row['name'] ?? $slug ) ),
				'color' => strtoupper( $hex ),
			);
		}

		/*
		 * The design's OWN colour tokens — a Lovable design's `--brand-green`,
		 * a Tailwind v4 `--primary` — each as a preset its token is re-pointed
		 * at (Token_Styles::brand_css()). A token that shares a literal's preset
		 * is already registered above under the role name.
		 *
		 * The value is stored exactly as the design wrote it, `oklch(…)`
		 * included, so the brand design computes the same colour it did; a hex
		 * conversion would serialise differently and clip out-of-gamut colours.
		 * It is untrusted input headed for the global stylesheet, so only a
		 * colour function or a hex, with nothing that could end the declaration.
		 */
		$vars = is_array( $design_palette['vars'] ?? null ) ? $design_palette['vars'] : array();
		foreach ( $vars as $var ) {
			if ( ! is_array( $var ) || ! empty( $var['shared'] ) ) {
				continue;
			}
			$slug  = sanitize_key( (string) ( $var['slug'] ?? '' ) );
			$value = trim( (string) ( $var['value'] ?? '' ) );
			if ( $slug === '' || in_array( $slug, $taken, true )
				|| preg_match( '/^[a-z0-9#(),.%\/\s-]+$/i', $value ) !== 1
				|| ! \DXAI_UI\Compiler\Design_Palette::is_solid_color_value( $value )
			) {
				continue;
			}
			$taken[]   = $slug;
			$colors[]  = $var;
			$palette[] = array(
				'slug'  => $slug,
				'name'  => sanitize_text_field( (string) ( $var['name'] ?? $slug ) ),
				'color' => $value,
			);
		}

		// A design imported before tokenising: its nine roles on their own.
		if ( $colors === array() ) {
			foreach ( self::PALETTE as $key => $meta ) {
				$hex = (string) ( $tokens[ $key ] ?? '' );
				if ( $hex === '' || in_array( $meta[0], $taken, true ) ) {
					continue;
				}
				$taken[]   = $meta[0];
				$palette[] = array(
					'slug'  => $meta[0],
					'name'  => $meta[1],
					'color' => $hex,
				);
			}
		}
		if ( $palette !== array() ) {
			$settings['color']['palette'] = $palette;
		}

		/*
		 * Shadows, carried forward the same way palettes are (merge() replaces a
		 * preset list per origin). Registered because brand_css() points
		 * `--dxai-shadow-N` at `--wp--preset--shadow--shadow-N`.
		 */
		$shadows = is_array( $design_palette['shadows'] ?? null ) ? $design_palette['shadows'] : array();
		if ( $shadows !== array() ) {
			$presets = self::existing( $current, array( 'settings', 'shadow', 'presets' ) );
			$held    = array_column( $presets, 'slug' );
			foreach ( $shadows as $row ) {
				$slug  = sanitize_key( (string) ( is_array( $row ) ? ( $row['slug'] ?? '' ) : '' ) );
				$value = trim( (string) ( is_array( $row ) ? ( $row['value'] ?? '' ) : '' ) );
				// A shadow is untrusted design input headed for the global
				// stylesheet: nothing that could end the declaration or the rule.
				if ( $slug === '' || $value === '' || preg_match( '/[;{}<>\\\\]/', $value ) === 1 || in_array( $slug, $held, true ) ) {
					continue;
				}
				$held[]    = $slug;
				$presets[] = array(
					'slug'   => $slug,
					'name'   => sanitize_text_field( (string) ( $row['name'] ?? $slug ) ),
					'shadow' => $value,
				);
			}
			$settings['shadow']['presets'] = $presets;
		}

		$font = (string) ( $tokens['font'] ?? '' );
		if ( $font !== '' ) {
			$families = self::existing( $current, array( 'settings', 'typography', 'fontFamilies' ) );
			if ( ! in_array( 'brand', array_column( $families, 'slug' ), true ) ) {
				$families[] = array(
					'slug'       => 'brand',
					'name'       => $font,
					'fontFamily' => $font,
				);
			}
			$settings['typography']['fontFamilies'] = $families;
		}

		// Custom values merge recursively in theme.json, so nothing needs to be
		// carried forward here; this becomes --wp--custom--radius--base.
		$radius = (string) ( $tokens['radius'] ?? '' );
		if ( $radius !== '' ) {
			$settings['custom']['radius']['base'] = $radius;
		}

		return $settings === array() ? array() : array(
			'version'  => 3,
			'settings' => $settings,
		);
	}

	/**
	 * A preset list already present at a path, keeping only well-formed entries.
	 *
	 * @param array<string, mixed>  $data
	 * @param array<int, string>    $path
	 * @return array<int, array<string, string>>
	 */
	private static function existing( array $data, array $path ): array {
		foreach ( $path as $key ) {
			if ( ! is_array( $data ) || ! array_key_exists( $key, $data ) ) {
				return array();
			}
			$data = $data[ $key ];
		}
		if ( ! is_array( $data ) ) {
			return array();
		}
		// A palette read back from resolved data is keyed by origin; the theme
		// layer's own raw data is a flat list. Accept both.
		if ( isset( $data['theme'] ) && is_array( $data['theme'] ) ) {
			$data = $data['theme'];
		}

		return array_values(
			array_filter(
				$data,
				static fn( $row ): bool => is_array( $row ) && isset( $row['slug'] ) && is_string( $row['slug'] )
			)
		);
	}

	/**
	 * Only values that are safe to put in a stylesheet: hex colours, a font
	 * family with nothing that could close the declaration, a CSS length.
	 *
	 * Tokens are derived from an imported design, which is untrusted input,
	 * and they end up inside the global stylesheet every page loads.
	 *
	 * @param mixed $tokens
	 * @return array<string, string>
	 */
	private static function clean( $tokens ): array {
		if ( ! is_array( $tokens ) ) {
			return array();
		}

		$out = array();
		foreach ( array_keys( self::PALETTE ) as $key ) {
			$hex = sanitize_hex_color( (string) ( $tokens[ $key ] ?? '' ) );
			if ( is_string( $hex ) && $hex !== '' ) {
				$out[ $key ] = strtoupper( $hex );
			}
		}

		$font = trim( (string) ( $tokens['font'] ?? '' ) );
		if ( $font !== '' && strtolower( $font ) !== 'inherit' && preg_match( '/^[A-Za-z0-9 ,\'"._-]{1,120}$/', $font ) === 1 ) {
			$out['font'] = $font;
		}

		$radius = trim( (string) ( $tokens['radius'] ?? '' ) );
		if ( preg_match( '/^\d+(?:\.\d+)?(?:px|rem|em|%)$/', $radius ) === 1 ) {
			$out['radius'] = $radius;
		}

		return $out;
	}
}

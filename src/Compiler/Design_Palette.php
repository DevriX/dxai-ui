<?php
/**
 * Every colour and shadow a design uses, as named tokens.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

/**
 * The design's whole palette, not just its named roles.
 *
 * Design_Tokens resolves nine ROLES — brand, accent, ink, muted, surface and so
 * on — and that is the right vocabulary for a person choosing a colour. But
 * roles alone cannot take a page's hard-coded colours out of its content: on
 * H2O Away they account for 341 of 834 literals, 40.9%. The two most used
 * colours on that page are not roles at all — `#ffffff` (221 uses) and the body
 * copy's `#3a424b` (206) — and the page uses 20 distinct colours in total.
 *
 * So every colour the design writes becomes a token. A colour that equals a
 * role keeps the role's name, which is what someone editing Styles will
 * recognise; the rest are named deterministically from the colour itself
 * ("Neutral 97", "Blue 94"), so re-importing the same design always produces
 * the same slugs and a person's edits in Styles stay attached to the same
 * swatch.
 *
 * Shadows are tokens too, for a reason beyond "change once": WordPress's KSES
 * filter strips a `box-shadow` whose value contains `rgba()` (measured:
 * `box-shadow:0 12px 32px rgba(17,17,17,.08)` comes back empty), so every
 * literal shadow in converted content is destroyed the first time an editor
 * without `unfiltered_html` saves the page. `box-shadow:var(--dxai-shadow-1)`
 * survives it.
 *
 * Only exact values become tokens. A token's definition is the literal it
 * replaced, so a page that swaps the literal for the reference renders the same
 * computed colour — nothing is rounded or merged into a neighbour, because
 * merging near-identical values is exactly what moved pixels when it was tried.
 */
final class Design_Palette {

	/**
	 * Role => slug, in the order that decides a tie. `#111111` is both ink and
	 * the text on accent buttons on H2O; it is named once, and the other role
	 * becomes an alias of it (see Token_Styles::definitions_css()).
	 *
	 * The slugs avoid Twenty Twenty-Five's (`base`, `contrast`, `accent-1…6`)
	 * and core's default palette (`black`, `white`, `vivid-red`, …), because
	 * WP_Theme_JSON::merge() drops a theme preset whose slug matches a default.
	 *
	 * @var array<string, string>
	 */
	public const ROLES = array(
		'brand'     => 'brand',
		'accent'    => 'accent',
		'ink'       => 'ink',
		'body'      => 'body',
		'muted'     => 'muted',
		'surface'   => 'surface',
		'border'    => 'border',
		'danger'    => 'danger',
		'button_fg' => 'on-accent',
	);

	/** Chroma below this reads as grey, not as a hue. */
	private const NEUTRAL_CHROMA = 0.06;

	/**
	 * @param array<int, string>    $markups Block markup the design produced (page, header, footer, sections).
	 * @param string                $css     The compiled design stylesheet.
	 * @param array<string, mixed>  $roles   Design_Tokens::resolve() output.
	 * @return array{colors: array<string, array{slug:string, name:string, value:string, uses:int}>, shadows: array<string, array{slug:string, name:string, value:string, uses:int}>}
	 *         Keyed by the normalised value, so a rewriter can look a literal up directly.
	 */
	public static function extract( array $markups, string $css, array $roles ): array {
		$colors  = array();
		$shadows = array();

		$declarations = array();
		foreach ( $markups as $markup ) {
			foreach ( self::style_values( (string) $markup ) as $value ) {
				array_push( $declarations, ...self::declarations( $value ) );
			}
		}
		foreach ( self::css_declarations( $css ) as $pair ) {
			$declarations[] = $pair;
		}

		foreach ( $declarations as $pair ) {
			list( $property, $value ) = $pair;
			if ( self::is_shadow_property( $property ) ) {
				// `!important` belongs to the declaration, not the value: a
				// custom property holding it is invalid, and Token_Styles puts
				// it back after the var() reference.
				$bare = self::without_important( $value );
				$key  = self::shadow_key( $bare );
				if ( $key !== '' ) {
					$shadows[ $key ] = array(
						'value' => $shadows[ $key ]['value'] ?? $bare,
						'uses'  => ( $shadows[ $key ]['uses'] ?? 0 ) + 1,
					);
				}
				continue;
			}
			foreach ( self::colors_in( $value ) as $literal ) {
				$key = self::color_key( $literal );
				if ( $key === '' ) {
					continue;
				}
				$colors[ $key ] = array(
					'value' => $colors[ $key ]['value'] ?? $key,
					'uses'  => ( $colors[ $key ]['uses'] ?? 0 ) + 1,
				);
			}
		}

		return array(
			'colors'  => self::name_colors( $colors, $roles ),
			'shadows' => self::name_shadows( $shadows ),
		);
	}

	/**
	 * Every `style="…"` value in a piece of block markup, plus the CSS carried
	 * in `dxaiStyle` and in dxai-ui/link's string `style` attribute.
	 *
	 * @return array<int, string>
	 */
	public static function style_values( string $markup ): array {
		$out = array();
		if ( preg_match_all( '/\sstyle="([^"]*)"/i', $markup, $m ) ) {
			foreach ( $m[1] as $v ) {
				$out[] = html_entity_decode( $v, ENT_QUOTES | ENT_HTML5 );
			}
		}
		if ( preg_match_all( '/\sstyle=\'([^\']*)\'/i', $markup, $m ) ) {
			foreach ( $m[1] as $v ) {
				$out[] = html_entity_decode( $v, ENT_QUOTES | ENT_HTML5 );
			}
		}

		return $out;
	}

	/**
	 * Split a declaration list on the semicolons that end declarations — not
	 * the ones inside `url(data:…;base64,…)` or a quoted string.
	 *
	 * @return array<int, array{0:string, 1:string}> [ property, value ]
	 */
	public static function declarations( string $style ): array {
		$out   = array();
		$depth = 0;
		$quote = '';
		$start = 0;
		$len   = strlen( $style );
		for ( $i = 0; $i <= $len; $i++ ) {
			$ch = $i < $len ? $style[ $i ] : ';';
			if ( $quote !== '' ) {
				if ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( $ch === '"' || $ch === "'" ) {
				$quote = $ch;
			} elseif ( $ch === '(' ) {
				++$depth;
			} elseif ( $ch === ')' ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( $ch === ';' && $depth === 0 ) {
				$decl = trim( substr( $style, $start, $i - $start ) );
				$start = $i + 1;
				$colon = strpos( $decl, ':' );
				if ( $decl === '' || $colon === false ) {
					continue;
				}
				$out[] = array( strtolower( trim( substr( $decl, 0, $colon ) ) ), trim( substr( $decl, $colon + 1 ) ) );
			}
		}

		return $out;
	}

	/**
	 * Declarations in a stylesheet — every `prop: value` inside a rule body.
	 *
	 * @return array<int, array{0:string, 1:string}>
	 */
	private static function css_declarations( string $css ): array {
		$out = array();
		$css = self::without_keyframes( (string) preg_replace( '#/\*.*?\*/#s', '', $css ) );
		if ( preg_match_all( '/\{([^{}]*)\}/', $css, $m ) ) {
			foreach ( $m[1] as $body ) {
				foreach ( self::declarations( $body ) as $pair ) {
					// A custom property's own definition is not a use. The design's
					// `--rv-green: #64c040` is already a token of its own.
					if ( str_starts_with( $pair[0], '--' ) ) {
						continue;
					}
					$out[] = $pair;
				}
			}
		}

		return $out;
	}

	public static function is_shadow_property( string $property ): bool {
		return $property === 'box-shadow' || $property === 'text-shadow';
	}

	/**
	 * The design's OWN colour tokens: root custom properties such as
	 * `--brand-green: oklch(0.723 0.181 138.5)` that the page actually reads.
	 *
	 * A Lovable design is token-driven already — Brand Polish Pass declares 53
	 * colour custom properties and writes not one literal colour into its
	 * markup — so rewriting literals does nothing for it, and on its own the
	 * design's brand stayed unreachable from Styles. What connects it is
	 * re-pointing those properties at theme.json presets for the brand design
	 * (Token_Styles::brand_css()), and this is the list of them.
	 *
	 * Only what the page REACHES. Tailwind v4 reads a base token through an
	 * alias — `.bg-primary` uses `var(--color-primary)`, which is defined as
	 * `var(--primary)` — so usage is followed transitively from the ordinary
	 * declarations that read tokens. shadcn's defaults (popover, sidebar,
	 * chart-1…5) a design never uses would otherwise bury its real palette.
	 *
	 * The value is kept exactly as written, `oklch(…)` included. A browser
	 * serialises `oklch(…)` and `rgb(…)` differently and clips an out-of-gamut
	 * colour, so storing a hex conversion would have changed what the page
	 * computes; storing the original keeps the brand design pixel-identical.
	 *
	 * Root scope only (`:root`, `:host`, `html`, or the wrapper the scoper turns
	 * `:root` into), never a `.dark` / `[data-theme]` variant, and only solid
	 * colours: an alias (`var(--x)`) is covered by re-pointing its base, and a
	 * translucent value is not a swatch.
	 *
	 * @return array<string, array{slug:string, name:string, value:string}> Keyed by property name, e.g. `--brand-green`.
	 */
	public static function design_vars( string $css ): array {
		// A `:root` inside `@media (prefers-color-scheme: dark)` is a dark
		// value; read as a root definition it would win (last one wins) and the
		// dark colour would be registered as the brand's.
		$css  = self::without_at_blocks(
			self::without_keyframes( (string) preg_replace( '#/\*.*?\*/#s', '', $css ) ),
			'/@media\b[^{]*prefers-color-scheme\s*:\s*dark[^{]*\{/i'
		);
		$defs = array();
		$refs = array();
		$read = array();

		if ( preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $blocks, PREG_SET_ORDER ) ) {
			foreach ( $blocks as $block ) {
				$selector = trim( (string) $block[1] );
				$is_root  = self::is_root_selector( $selector );
				foreach ( self::declarations( (string) $block[2] ) as $pair ) {
					list( $property, $value ) = $pair;
					preg_match_all( '/var\(\s*(--[\w-]+)/', $value, $used );
					if ( str_starts_with( $property, '--' ) ) {
						/*
						 * The design's own tokens only. `--dxai-*` is this plugin's —
						 * Token_Styles appends those definitions to the very sheet read
						 * here, under a `:root` selector — and `--wp--*` is core's.
						 * Taking either for the design's would register the plugin's
						 * token as a brand preset and re-point it at itself.
						 */
						if ( $is_root && ! str_starts_with( $property, '--dxai-' ) && ! str_starts_with( $property, '--wp--' ) ) {
							$defs[ $property ] = $value;
							$refs[ $property ] = $used[1];
						}
						continue;
					}
					foreach ( $used[1] as $name ) {
						$read[ $name ] = true;
					}
				}
			}
		}

		// Everything reachable from an ordinary declaration, through aliases.
		$queue = array_keys( $read );
		while ( $queue !== array() ) {
			$name = array_pop( $queue );
			foreach ( $refs[ $name ] ?? array() as $next ) {
				if ( ! isset( $read[ $next ] ) ) {
					$read[ $next ] = true;
					$queue[]       = $next;
				}
			}
		}

		$out = array();
		foreach ( $defs as $name => $value ) {
			$value = trim( $value );
			if ( ! isset( $read[ $name ] ) || str_starts_with( $name, '--tw-' ) || ! self::is_solid_color_value( $value ) ) {
				continue;
			}
			$bare = (string) preg_replace( '/^--(?:color-)?/', '', $name );
			$out[ $name ] = array(
				'slug'  => sanitize_key( $bare ),
				'name'  => ucfirst( str_replace( array( '-', '_' ), ' ', $bare ) ),
				'value' => $value,
			);
		}

		return $out;
	}

	/**
	 * `:root`, `:host`, `html`, or the page wrapper Css_Scoper writes in place
	 * of `:root` (`.dxai-ui.dxai-ui--88876`) — alone or in a selector list, and
	 * never a dark-mode or theme variant of it.
	 */
	private static function is_root_selector( string $selector ): bool {
		if ( preg_match( '/\.dark\b|\[data-theme|\[data-mode|prefers-color-scheme/i', $selector ) === 1 ) {
			return false;
		}
		foreach ( explode( ',', $selector ) as $part ) {
			$part = trim( $part );
			if ( in_array( $part, array( ':root', ':host', 'html' ), true ) || preg_match( '/^\.dxai-ui\.dxai-ui--\d+$/', $part ) === 1 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * An opaque colour written as a literal: hex, rgb(), hsl(), oklch() or
	 * oklab(). Not an alias, not a keyword, not translucent.
	 */
	public static function is_solid_color_value( string $value ): bool {
		$value = strtolower( trim( $value ) );
		if ( $value === '' || str_contains( $value, 'var(' ) ) {
			return false;
		}
		if ( preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $value ) === 1 ) {
			return true;
		}
		if ( preg_match( '/^(?:oklch|oklab|hsla?|rgba?|lab|lch)\(([^()]*)\)$/', $value, $m ) !== 1 ) {
			return false;
		}
		// An alpha after a slash, or as a fourth comma argument, must be 1/100%.
		if ( preg_match( '/\/\s*([\d.]+)(%?)\s*$/', $m[1], $a ) === 1 || preg_match( '/^[^,]*,[^,]*,[^,]*,\s*([\d.]+)(%?)\s*$/', $m[1], $a ) === 1 ) {
			$alpha = (float) $a[1] / ( $a[2] === '%' ? 100 : 1 );

			return $alpha >= 1.0;
		}

		return true;
	}

	/** A value with a trailing `!important` removed. */
	public static function without_important( string $value ): string {
		return trim( (string) preg_replace( '/\s*!\s*important\s*$/i', '', $value ) );
	}

	/** Whether a value ends in `!important`. */
	public static function is_important( string $value ): bool {
		return preg_match( '/!\s*important\s*$/i', $value ) === 1;
	}

	/**
	 * A stylesheet with its `@keyframes` blocks removed.
	 *
	 * An animation's frames are not the design's palette: H2O's CTA pulse
	 * alone contributes three shadows (`0 0 0 0 rgba(242,163,74,.65)`, then
	 * `…16px…0`) that are steps of one effect, not three looks a person would
	 * choose between. They are left literal, in the palette and on the page.
	 */
	public static function without_keyframes( string $css ): string {
		return self::without_at_blocks( $css, '/@(?:-webkit-|-moz-)?keyframes\b[^{]*\{/i' );
	}

	/**
	 * A stylesheet with every block whose opening matches $opener removed,
	 * braces balanced — an at-rule's nested rules go with it.
	 */
	public static function without_at_blocks( string $css, string $opener ): string {
		$out    = '';
		$offset = 0;
		while ( preg_match( $opener, $css, $m, PREG_OFFSET_CAPTURE, $offset ) === 1 ) {
			$start = (int) $m[0][1];
			$out  .= substr( $css, $offset, $start - $offset );
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
			$offset = $i + 1;
		}

		return $out . substr( $css, $offset );
	}

	/**
	 * The colour literals in one value, outside any `url(…)`.
	 *
	 * @return array<int, string>
	 */
	public static function colors_in( string $value ): array {
		$value = (string) preg_replace( '/url\([^)]*\)/i', ' ', $value );
		if ( ! preg_match_all( self::COLOR_PATTERN, $value, $m ) ) {
			return array();
		}

		return $m[0];
	}

	/**
	 * `#rgb`, `#rgba`, `#rrggbb`, `#rrggbbaa`, and `rgb()`/`rgba()` in either
	 * comma or space syntax. Anchored on a non-word boundary so `#fab` inside
	 * a longer token is not taken for a colour — and never after `&`, so a
	 * numeric character reference is not one either: esc_attr() writes a
	 * quote as `&#039;`, whose `#039` read as the colour #003399, and a
	 * `font-family:'Inter'` in style="" came out as `&var(--dxai-…);Inter…`
	 * while the block attribute (holding a plain quote) stayed as it was.
	 */
	public const COLOR_PATTERN = '/(?<![\w&#-])#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{4}|[0-9a-fA-F]{3})(?![\w-])|\brgba?\(\s*\d{1,3}\s*[,\s]\s*\d{1,3}\s*[,\s]\s*\d{1,3}\s*(?:[,\/]\s*(?:\d*\.?\d+%?)\s*)?\)/i';

	/**
	 * One canonical spelling per colour: `#rrggbb` when opaque, `rgba(r,g,b,a)`
	 * when not. `#FFF`, `#ffffff` and `rgb(255,255,255)` are one token.
	 */
	public static function color_key( string $literal ): string {
		$rgba = self::to_rgba( $literal );
		if ( $rgba === null ) {
			return '';
		}
		list( $r, $g, $b, $a ) = $rgba;
		if ( $a >= 1.0 ) {
			return sprintf( '#%02x%02x%02x', $r, $g, $b );
		}

		return sprintf( 'rgba(%d,%d,%d,%s)', $r, $g, $b, rtrim( rtrim( sprintf( '%.3f', $a ), '0' ), '.' ) );
	}

	/**
	 * @return array{0:int, 1:int, 2:int, 3:float}|null
	 */
	private static function to_rgba( string $literal ): ?array {
		$literal = strtolower( trim( $literal ) );
		if ( $literal !== '' && $literal[0] === '#' ) {
			$hex = substr( $literal, 1 );
			if ( strlen( $hex ) === 3 || strlen( $hex ) === 4 ) {
				$hex = implode( '', array_map( static fn( string $c ): string => $c . $c, str_split( $hex ) ) );
			}
			if ( strlen( $hex ) !== 6 && strlen( $hex ) !== 8 ) {
				return null;
			}
			$a = strlen( $hex ) === 8 ? hexdec( substr( $hex, 6, 2 ) ) / 255 : 1.0;

			return array( (int) hexdec( substr( $hex, 0, 2 ) ), (int) hexdec( substr( $hex, 2, 2 ) ), (int) hexdec( substr( $hex, 4, 2 ) ), (float) $a );
		}
		if ( preg_match( '/^rgba?\(\s*(\d{1,3})\s*[,\s]\s*(\d{1,3})\s*[,\s]\s*(\d{1,3})\s*(?:[,\/]\s*(\d*\.?\d+)(%?)\s*)?\)$/', $literal, $m ) !== 1 ) {
			return null;
		}
		$a = 1.0;
		if ( isset( $m[4] ) && $m[4] !== '' ) {
			$a = (float) $m[4];
			if ( ( $m[5] ?? '' ) === '%' ) {
				$a /= 100;
			}
		}

		return array( min( 255, (int) $m[1] ), min( 255, (int) $m[2] ), min( 255, (int) $m[3] ), max( 0.0, min( 1.0, $a ) ) );
	}

	/**
	 * A shadow value with its whitespace and colours made canonical, so the
	 * same shadow written twice is one token.
	 */
	public static function shadow_key( string $value ): string {
		$value = trim( strtolower( $value ) );
		if ( $value === '' || $value === 'none' || str_contains( $value, 'var(' ) ) {
			return '';
		}
		$value = (string) preg_replace_callback(
			self::COLOR_PATTERN,
			static fn( array $m ): string => self::color_key( $m[0] ),
			$value
		);

		return (string) preg_replace( '/\s+/', ' ', $value );
	}

	/**
	 * @param array<string, array{value:string, uses:int}> $colors
	 * @param array<string, mixed>                        $roles
	 * @return array<string, array{slug:string, name:string, value:string, uses:int}>
	 */
	private static function name_colors( array $colors, array $roles ): array {
		uasort( $colors, static fn( array $a, array $b ): int => $b['uses'] <=> $a['uses'] );

		$by_role = array();
		foreach ( self::ROLES as $role => $slug ) {
			$key = self::color_key( (string) ( $roles[ $role ] ?? '' ) );
			if ( $key !== '' && ! isset( $by_role[ $key ] ) ) {
				$by_role[ $key ] = array( $slug, self::role_label( $role ) );
			}
		}

		$out   = array();
		$taken = array();
		foreach ( $colors as $key => $row ) {
			list( $slug, $name ) = $by_role[ $key ] ?? self::describe( $key );
			$base = $slug;
			for ( $n = 2; isset( $taken[ $slug ] ); $n++ ) {
				$slug = $base . '-' . $n;
			}
			$taken[ $slug ] = true;
			$out[ $key ]    = array(
				'slug'   => $slug,
				'name'   => $slug === $base ? $name : $name . ' ' . substr( $slug, strlen( $base ) + 1 ),
				'value'  => $key,
				'uses'   => $row['uses'],
				/*
				 * Whether this becomes a swatch in Styles. Solid colours do;
				 * translucent ones do not. H2O alone has fifteen of those —
				 * the brand at 22%, 16%, 10%… — and fifteen near-identical
				 * swatches would bury the nine a person actually means to
				 * change. They are still tokens on the page (KSES strips an
				 * `rgba()` background too, so the literal was never safe
				 * there), just not presets.
				 */
				'preset' => ! str_starts_with( $key, 'rgba(' ),
			);
		}

		return $out;
	}

	/**
	 * @param array<string, array{value:string, uses:int}> $shadows
	 * @return array<string, array{slug:string, name:string, value:string, uses:int}>
	 */
	private static function name_shadows( array $shadows ): array {
		uasort( $shadows, static fn( array $a, array $b ): int => $b['uses'] <=> $a['uses'] );
		$out = array();
		$n   = 0;
		foreach ( $shadows as $key => $row ) {
			++$n;
			$out[ $key ] = array(
				'slug'  => 'shadow-' . $n,
				'name'  => 'Shadow ' . $n,
				'value' => $row['value'],
				'uses'  => $row['uses'],
			);
		}

		return $out;
	}

	private static function role_label( string $role ): string {
		$labels = array(
			'brand'     => 'Brand',
			'accent'    => 'Accent',
			'ink'       => 'Ink',
			'body'      => 'Body text',
			'muted'     => 'Muted',
			'surface'   => 'Surface',
			'border'    => 'Border',
			'danger'    => 'Danger',
			'button_fg' => 'Text on accent',
		);

		return $labels[ $role ] ?? ucfirst( $role );
	}

	/**
	 * A stable name for a colour no role claims: its hue family and lightness,
	 * e.g. `neutral-97` / "Neutral 97", `blue-94` / "Blue 94", and for a
	 * translucent one the opacity too, `neutral-7-a50` / "Neutral 7, 50%".
	 *
	 * @return array{0:string, 1:string}
	 */
	private static function describe( string $key ): array {
		$rgba = self::to_rgba( $key ) ?? array( 0, 0, 0, 1.0 );
		list( $r, $g, $b, $a ) = $rgba;
		$max    = max( $r, $g, $b ) / 255;
		$min    = min( $r, $g, $b ) / 255;
		$light  = (int) round( ( $max + $min ) / 2 * 100 );
		$chroma = $max - $min;

		$family = 'neutral';
		if ( $chroma >= self::NEUTRAL_CHROMA ) {
			$hue    = self::hue( $r / 255, $g / 255, $b / 255, $max, $chroma );
			$family = self::family( $hue );
		}

		$slug = $family . '-' . $light;
		$name = ucfirst( $family ) . ' ' . $light;
		if ( $a < 1.0 ) {
			$pct   = (int) round( $a * 100 );
			$slug .= '-a' . $pct;
			$name .= ', ' . $pct . '%';
		}

		return array( $slug, $name );
	}

	private static function hue( float $r, float $g, float $b, float $max, float $chroma ): float {
		if ( $chroma <= 0.0 ) {
			return 0.0;
		}
		if ( $max === $r ) {
			$h = fmod( ( $g - $b ) / $chroma, 6.0 );
		} elseif ( $max === $g ) {
			$h = ( $b - $r ) / $chroma + 2.0;
		} else {
			$h = ( $r - $g ) / $chroma + 4.0;
		}
		$h *= 60.0;

		return $h < 0 ? $h + 360.0 : $h;
	}

	private static function family( float $hue ): string {
		$bands = array(
			15  => 'red',
			40  => 'orange',
			55  => 'amber',
			70  => 'yellow',
			95  => 'lime',
			150 => 'green',
			175 => 'teal',
			195 => 'cyan',
			215 => 'sky',
			245 => 'blue',
			265 => 'indigo',
			285 => 'violet',
			315 => 'purple',
			345 => 'pink',
		);
		foreach ( $bands as $upper => $name ) {
			if ( $hue < $upper ) {
				return $name;
			}
		}

		return 'red';
	}
}

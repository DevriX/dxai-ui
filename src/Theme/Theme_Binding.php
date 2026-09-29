<?php
/**
 * A design's colours following the active theme's.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Theme;

use DXAI_UI\Compiler\Color_Usage;
use DXAI_UI\Compiler\Token_Styles;
use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Support\Color_Math;
use DXAI_UI\Support\Upload_Paths;

/**
 * An imported design keeps its own colours in its own tokens (`--dxai-brand`,
 * `text-dxai-ink` …), and its content never names a theme colour, so it renders
 * on any theme. On a theme with colour settings (Theme_Palette) the design is
 * fitted to them by a BINDING: which token follows which theme colour. It is
 * applied when the page is shown, as one rule of references —
 *
 *   --dxai-brand: var(--wp--preset--color--primary, #016bc4);
 *
 * — so a later change of the theme's colours (american-restoration's Theme
 * Global Settings, Styles, theme.json) reaches every converted page on the next
 * request, with no re-import and nothing rewritten.
 *
 * What is bound (propose()), per the choices made for DevriX sites:
 *  - by role: the design's brand, accent and ink to the theme colour with that
 *    role — close ones (auto), and bigger changes too (review: applied, and
 *    shown for review in the Library);
 *  - neutrals and every other colour only when the theme has practically the
 *    same one (a white, a light grey);
 *  - tints, shades and translucent versions of a bound colour follow it,
 *    relative to it (the design's blue-greys that merely lean towards its
 *    brand are not tints, and keep their values).
 *
 * Text colours have a channel of their own (Token_Styles::fg_channel()), and
 * each is chosen on every request against the backgrounds it sits on (the
 * pairs Color_Usage found): the theme colour when it reads, else the design's
 * own, else black or white. A new WCAG AA failure is never served: Semper's
 * yellow headings on its dark bands stay yellow while its yellow buttons turn
 * the theme's orange, and their dark text turns black where navy would not
 * read on orange. Colours that sit on a bound background (a button's text) are
 * chosen the same way.
 *
 * Stored per theme (`get_stylesheet()`) on the design's Home, so a theme
 * switch shows the design's own colours until a binding exists for the new
 * theme. Mode `keep` switches it off. The rule is computed from the stored
 * binding and the palette read on this request, memoised by both.
 */
final class Theme_Binding {

	public const META = '_dxai_ui_theme_binding';

	/** Bumped when the stored shape or the emitted CSS changes. */
	public const SCHEMA = 1;

	private const TEXT_MIN  = 4.5;
	private const LARGE_MIN = 3.0;

	/** Distance (OKLab × 100) under which two colours count as the same one. */
	private const SAME = 2.0;

	/** @var array<string, string> memo: key => css */
	private static array $css = array();

	public function register(): void {
		add_action( 'admin_init', array( self::class, 'upgrade' ), 30 );
	}

	/**
	 * The design Home a post shows: its own scope root, or the design an ordinary page with copied blocks shows.
	 */
	public static function home_of( int $post_id ): int {
		$scope = (int) get_post_meta( $post_id, Page_Scope::META, true );
		if ( $scope < 1 ) {
			$scope = \DXAI_UI\Structures\Design_Attach::scope_for( $post_id );
		}

		return $scope > 0 ? $scope : $post_id;
	}

	/**
	 * The binding stored for the current theme, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( int $home ): ?array {
		$all = get_post_meta( $home, self::META, true );
		$one = is_array( $all ) ? ( $all[ get_stylesheet() ] ?? null ) : null;

		return is_array( $one ) && (int) ( $one['schema'] ?? 0 ) === self::SCHEMA ? $one : null;
	}

	/** Whether the design's colours follow the current theme. */
	public static function follows( int $home ): bool {
		$binding = self::get( $home );

		return $binding !== null && ( $binding['mode'] ?? '' ) === 'follow' && Theme_Palette::current()['has_palette'];
	}

	/**
	 * The mode a design gets when nothing was chosen: follow on a theme with colour settings whose chrome the
	 * plugin does not own (a classic theme such as american-restoration); keep elsewhere, where a block theme
	 * takes the design's colours as its own presets instead (Design_Theme_Json).
	 */
	public static function default_mode( int $home ): string {
		$mode = Theme_Palette::current()['has_palette'] && ! Theme_Compat::may_install_chrome() ? 'follow' : 'keep';

		return (string) apply_filters( 'dxai_ui_theme_color_mode', $mode, $home, Theme_Palette::current() );
	}

	/**
	 * Propose and store a binding for the current theme, and make the design's sheets ready for it. Keeps a
	 * person's own choices (overrides and mode) when there are any. Admin, REST, cron or CLI only.
	 *
	 * @param array<string, string|null> $overrides token => theme slug, or '' for "keep the design's colour".
	 * @return array<string, mixed> The stored binding.
	 */
	public static function apply( int $home, ?string $mode = null, ?array $overrides = null ): array {
		$prior = self::get( $home );
		$mode  = $mode ?? (string) ( $prior['mode'] ?? self::default_mode( $home ) );
		$over  = $overrides ?? (array) ( $prior['overrides'] ?? array() );
		Color_Usage::scan( $home );
		$binding = self::propose( $home );
		foreach ( $over as $token => $slug ) {
			$token = sanitize_key( (string) $token );
			$slug  = sanitize_key( (string) $slug );
			if ( ! isset( $binding['design'][ $token ] ) ) {
				continue;
			}
			if ( $slug === '' ) {
				unset( $binding['tokens'][ $token ] );
			} elseif ( Theme_Palette::has( $slug ) ) {
				$binding['tokens'][ $token ] = array(
					'to'   => $slug,
					'tier' => 'chosen',
				);
			}
		}
		$binding['mode']      = in_array( $mode, array( 'follow', 'keep' ), true ) ? $mode : 'keep';
		$binding['overrides'] = $over;
		if ( $binding['mode'] === 'follow' ) {
			self::channelize( $home );
		}
		$all = get_post_meta( $home, self::META, true );
		$all = is_array( $all ) ? $all : array();
		$all[ get_stylesheet() ] = $binding;
		update_post_meta( $home, self::META, $all );
		self::$css = array();
		do_action( 'dxai_ui_theme_colors_changed', $home );

		return $binding;
	}

	/**
	 * Which design token follows which theme colour. Nothing is written.
	 *
	 * @return array<string, mixed>
	 */
	public static function propose( int $home ): array {
		$palette = Theme_Palette::current();
		$design  = self::design_colors( $home );
		$roles   = self::design_roles( $home );
		$usage   = get_post_meta( $home, Color_Usage::META, true );
		$usage   = is_array( $usage ) ? $usage : array();
		$theme   = self::theme_candidates( $palette );
		$tokens  = array();
		$taken   = array();

		// The design's roles, each to the theme colour with the same role.
		$wanted = array(
			'brand'  => array( 'brand' ),
			'accent' => array( 'accent' ),
			'ink'    => array( 'ink' ),
		);
		foreach ( $wanted as $role => $accept ) {
			$slug = (string) ( $roles[ $role ] ?? '' );
			if ( $slug === '' || ! isset( $design[ $slug ] ) || isset( $tokens[ $slug ] ) || $design[ $slug ]['alpha'] < 1 ) {
				continue;
			}
			$best = null;
			foreach ( $theme as $tslug => $t ) {
				if ( ! in_array( $t['role'], $accept, true ) || isset( $taken[ $tslug ] ) ) {
					continue;
				}
				$d = Color_Math::distance( $design[ $slug ]['value'], $t['hex'] );
				if ( $best === null || $d < $best[1] ) {
					$best = array( $tslug, $d );
				}
			}
			if ( $best === null ) {
				continue;
			}
			$tokens[ $slug ]    = array(
				'to'   => $best[0],
				'tier' => $best[1] <= 6.0 ? 'auto' : 'review',
				'role' => $role,
				'de'   => round( $best[1], 1 ),
			);
			$taken[ $best[0] ] = true;
		}

		// Everything else only where the theme has practically the same colour.
		foreach ( $design as $slug => $d ) {
			if ( isset( $tokens[ $slug ] ) || $d['alpha'] < 1 ) {
				continue;
			}
			$best = null;
			foreach ( $theme as $tslug => $t ) {
				// Core's white and black only on an exact match.
				$limit = $t['origin'] === 'anchor' ? 1.0 : self::SAME;
				$dist  = Color_Math::distance( $d['value'], $t['hex'] );
				if ( $dist <= $limit && ( $best === null || $dist < $best[1] ) && self::compatible( $d, $t ) ) {
					$best = array( $tslug, $dist );
				}
			}
			if ( $best !== null ) {
				$tokens[ $slug ] = array(
					'to'   => $best[0],
					'tier' => 'auto',
					'role' => 'same',
					'de'   => round( $best[1], 1 ),
				);
			}
		}

		return array(
			'schema'       => self::SCHEMA,
			'mode'         => 'follow',
			'palette_hash' => $palette['hash'],
			'palette_seen' => array_map( static fn( $t ) => $t['hex'], $theme ),
			'created_gmt'  => gmdate( 'c' ),
			'design'       => array_map( static fn( $d ) => $d['value'], $design ),
			'tokens'       => $tokens,
			'overrides'    => array(),
			'usage'        => array(
				'tokens' => (array) ( $usage['tokens'] ?? array() ),
				'pairs'  => (array) ( $usage['pairs'] ?? array() ),
			),
		);
	}

	/**
	 * The rule that applies a post's design binding on this request ('' when there is none, the design keeps its
	 * colours, or the theme has no palette).
	 */
	public static function css( int $post_id ): string {
		$home    = self::home_of( $post_id );
		$binding = self::get( $home );
		if ( $binding === null || ( $binding['mode'] ?? '' ) !== 'follow' ) {
			return '';
		}
		$palette = Theme_Palette::current();
		if ( ! $palette['has_palette'] ) {
			return '';
		}
		$key = md5( (string) wp_json_encode( array( $home, $binding['tokens'] ?? array(), $binding['created_gmt'] ?? '', $palette['hash'], self::SCHEMA ) ) );
		if ( isset( self::$css[ $key ] ) ) {
			return self::$css[ $key ];
		}
		$cached = get_transient( 'dxai_ui_binding_' . $key );
		if ( is_string( $cached ) ) {
			return self::$css[ $key ] = $cached;
		}
		$css = self::build_css( $home, $binding );
		set_transient( 'dxai_ui_binding_' . $key, $css, DAY_IN_SECONDS );

		return self::$css[ $key ] = $css;
	}

	/**
	 * What the Library shows for a design: the theme's colours, the binding and, per token, the value its text
	 * gets on this request (where the contrast check chose something else).
	 *
	 * @return array<string, mixed>
	 */
	public static function report( int $home ): array {
		$binding = self::get( $home );
		$design  = $binding !== null ? array_map( 'strval', (array) ( $binding['design'] ?? array() ) ) : array_map( static fn( $d ) => $d['value'], self::design_colors( $home ) );
		$text    = array();
		if ( $binding !== null && ( $binding['mode'] ?? '' ) === 'follow' && preg_match_all( '/--dxai-([a-z0-9-]+?)--fg:([^;}]+)/', self::css( $home ), $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) {
				$value            = trim( $hit[2] );
				$text[ $hit[1] ] = preg_match( '/,\s*(#[0-9a-f]{3,8})\)$/i', $value, $hex ) ? $hex[1] : $value;
			}
		}
		$usage = (array) ( $binding['usage']['tokens'] ?? array() );
		$rows  = array();
		foreach ( $design as $token => $value ) {
			$row    = (array) ( $binding['tokens'][ $token ] ?? array() );
			$rows[] = array(
				'token'  => $token,
				'design' => $value,
				'to'     => (string) ( $row['to'] ?? '' ),
				'tier'   => (string) ( $row['tier'] ?? '' ),
				'role'   => (string) ( $row['role'] ?? '' ),
				'text'   => (string) ( $text[ $token ] ?? '' ),
				'uses'   => array_sum( array_map( 'intval', (array) ( $usage[ $token ] ?? array() ) ) ),
			);
		}
		usort( $rows, static fn( $a, $b ) => ( $b['to'] !== '' ) <=> ( $a['to'] !== '' ) ?: $b['uses'] <=> $a['uses'] );

		return array(
			'id'      => $home,
			'title'   => get_the_title( $home ),
			'mode'    => (string) ( $binding['mode'] ?? '' ),
			'default' => self::default_mode( $home ),
			'rows'    => $rows,
			'review'  => count( array_filter( $rows, static fn( $r ) => $r['tier'] === 'review' ) ),
		);
	}

	/** What changes a post's binding output (for the rules cache and the like). */
	public static function signature( int $post_id ): string {
		$binding = self::get( self::home_of( $post_id ) );

		return $binding === null ? '' : md5( (string) wp_json_encode( array( $binding['mode'] ?? '', $binding['tokens'] ?? array(), Theme_Palette::current()['hash'] ) ) );
	}

	/**
	 * The live values of a binding and the rule that sets them.
	 *
	 * @param array<string, mixed> $binding
	 */
	private static function build_css( int $home, array $binding ): string {
		$design = array_map( 'strval', (array) ( $binding['design'] ?? array() ) );
		$bound  = array();
		foreach ( (array) ( $binding['tokens'] ?? array() ) as $token => $row ) {
			$slug = (string) ( $row['to'] ?? '' );
			$hex  = Theme_Palette::hex( $slug );
			if ( $slug !== '' && Theme_Palette::has( $slug ) && $hex !== '' && isset( $design[ $token ] ) ) {
				$bound[ $token ] = array(
					'slug' => $slug,
					'hex'  => $hex,
				);
			}
		}
		if ( $bound === array() ) {
			return '';
		}
		// Every token's value on this request: bound ones the theme's, derived ones relative to their anchor.
		$now     = $design;
		$derived = self::derived( $design, $bound );
		foreach ( $bound as $token => $b ) {
			$now[ $token ] = $b['hex'];
		}
		foreach ( $derived as $token => $d ) {
			$now[ $token ] = $d['hex'];
		}
		$decls = array();
		foreach ( $bound as $token => $b ) {
			$decls[] = '--dxai-' . $token . ':var(--wp--preset--color--' . $b['slug'] . ',' . $b['hex'] . ')';
		}
		$relative = array();
		foreach ( $derived as $token => $d ) {
			$decls[]    = '--dxai-' . $token . ':' . $d['hex'];
			$relative[] = '--dxai-' . $token . ':' . $d['css'];
		}
		// Text colours: each chosen against the backgrounds it sits on.
		foreach ( self::text_channels( $binding, $design, $now, $bound ) as $token => $value ) {
			$decls[] = '--dxai-' . $token . '--fg:' . $value;
		}
		$scope = '.dxai-ui.dxai-ui--' . $home;
		$out   = $scope . '{' . implode( ';', $decls ) . '}';
		if ( $relative !== array() ) {
			// Live in the editor and in Styles: the tints recomputed from the theme colour itself where supported.
			$out .= '@supports (color:oklch(from red l c h)){' . $scope . '{' . implode( ';', $relative ) . '}}';
		}

		return $out;
	}

	/**
	 * Tints, shades and translucent versions of a bound colour, relative to it.
	 *
	 * @param array<string, string>                          $design
	 * @param array<string, array{slug:string, hex:string}>  $bound
	 * @return array<string, array{hex:string, css:string}>
	 */
	private static function derived( array $design, array $bound ): array {
		$out = array();
		foreach ( $design as $token => $value ) {
			if ( isset( $bound[ $token ] ) ) {
				continue;
			}
			$rgba = Color_Math::parse( $value );
			if ( $rgba === null ) {
				continue;
			}
			$lch = Color_Math::oklch( $rgba );
			foreach ( $bound as $anchor => $b ) {
				$arg = Color_Math::parse( $design[ $anchor ] );
				// An anchor bound to its own value (the design's white to the theme's white) moves nothing.
				if ( $arg === null || Color_Math::distance( $b['hex'], $design[ $anchor ] ) < 0.1 ) {
					continue;
				}
				// A translucent version of the anchor itself.
				if ( $rgba[3] < 1 && Color_Math::distance( self::opaque( $rgba ), $design[ $anchor ] ) <= 1.0 ) {
					$live = Color_Math::parse( $b['hex'] );
					$out[ $token ] = array(
						'hex' => sprintf( 'rgba(%d,%d,%d,%s)', (int) round( $live[0] ), (int) round( $live[1] ), (int) round( $live[2] ), rtrim( rtrim( number_format( $rgba[3], 3, '.', '' ), '0' ), '.' ) ),
						'css' => 'color-mix(in srgb,var(--dxai-' . $anchor . ') ' . round( $rgba[3] * 100, 1 ) . '%,transparent)',
					);
					continue 2;
				}
				$alc = Color_Math::oklch( $arg );
				// A tint or shade: the anchor's hue (within 8°), a real colour (not a grey leaning towards it).
				$dh = abs( $lch[2] - $alc[2] );
				$dh = min( $dh, 360 - $dh );
				if ( $rgba[3] >= 1 && $lch[1] >= 0.05 && $alc[1] >= 0.05 && $dh <= 8 && $lch[1] / $alc[1] >= 0.3 && $lch[1] / $alc[1] <= 1.6 ) {
					$live  = Color_Math::parse( $b['hex'] );
					$llc   = Color_Math::oklch( $live );
					$dl    = $lch[0] - $alc[0];
					$ratio = $lch[1] / $alc[1];
					$out[ $token ] = array(
						'hex' => self::oklch_hex( max( 0.0, min( 1.0, $llc[0] + $dl ) ), $llc[1] * $ratio, $llc[2] + ( $lch[2] - $alc[2] ) ),
						'css' => 'oklch(from var(--dxai-' . $anchor . ') calc(l ' . ( $dl >= 0 ? '+ ' : '- ' ) . round( abs( $dl ), 3 ) . ') calc(c * ' . round( $ratio, 3 ) . ') h)',
					);
					continue 2;
				}
			}
		}

		return $out;
	}

	/**
	 * The text channel of every token whose text needs a value of its own on this request: a bound token's text
	 * that would not read on its backgrounds, and any token's text that sits on a background that changed.
	 *
	 * @param array<string, mixed>                          $binding
	 * @param array<string, string>                         $design
	 * @param array<string, string>                         $now
	 * @param array<string, array{slug:string, hex:string}> $bound
	 * @return array<string, string> token => CSS value
	 */
	private static function text_channels( array $binding, array $design, array $now, array $bound ): array {
		$pairs = Color_Usage::pair_rows( (array) ( $binding['usage']['pairs'] ?? array() ) );
		$by_fg = array();
		foreach ( $pairs as $pair ) {
			if ( isset( $design[ $pair[0] ], $design[ $pair[1] ] ) ) {
				$by_fg[ $pair[0] ][] = $pair;
			}
		}
		$out = array();
		foreach ( $by_fg as $token => $list ) {
			$changed = isset( $bound[ $token ] ) || isset( $now[ $token ] ) && $now[ $token ] !== $design[ $token ];
			foreach ( $list as $pair ) {
				$changed = $changed || $now[ $pair[1] ] !== $design[ $pair[1] ];
			}
			if ( ! $changed ) {
				continue;
			}
			// Candidates, in order of preference: what the binding gives, the design's own, then black or white.
			$candidates = array( array( '', $now[ $token ] ) );
			if ( $now[ $token ] !== $design[ $token ] ) {
				$candidates[] = array( $design[ $token ], $design[ $token ] );
			}
			foreach ( array( 'black' => '#000000', 'white' => '#ffffff' ) as $slug => $hex ) {
				$live         = Theme_Palette::hex( $slug );
				$candidates[] = array( Theme_Palette::has( $slug ) ? 'var(--wp--preset--color--' . $slug . ',' . ( $live !== '' ? $live : $hex ) . ')' : $hex, $live !== '' ? $live : $hex );
			}
			$pick = null;
			foreach ( $candidates as $candidate ) {
				if ( self::reads( $candidate[1], $list, $design, $now ) ) {
					$pick = $candidate;
					break;
				}
			}
			if ( $pick === null ) {
				// Nothing reads everywhere: the design's own colour, as the design has it.
				$pick = array( $now[ $token ] !== $design[ $token ] ? $design[ $token ] : '', $design[ $token ] );
			}
			if ( $pick[0] !== '' ) {
				$out[ $token ] = $pick[0];
			}
		}

		return $out;
	}

	/**
	 * Whether a text colour reads on every background it sits on. A pair that reads in the design must still read
	 * (4.5:1, 3:1 for large text). A pair that already fell short in the design is not held to more than it had,
	 * but must stay legible as large text (3:1) — Semper's yellow on its brand blue is 4.06:1 in the design, and
	 * refusing any loss there turned every yellow heading white.
	 *
	 * @param array<int, array{0:string, 1:string, 2:int, 3:bool}> $pairs
	 * @param array<string, string>                                $design
	 * @param array<string, string>                                $now
	 */
	private static function reads( string $fg, array $pairs, array $design, array $now ): bool {
		foreach ( $pairs as $pair ) {
			$min    = $pair[3] ? self::LARGE_MIN : self::TEXT_MIN;
			$before = Color_Math::contrast( $design[ $pair[0] ], $design[ $pair[1] ] );
			$after  = Color_Math::contrast( $fg, $now[ $pair[1] ] );
			$need   = $before >= $min ? $min : min( $before, self::LARGE_MIN );
			if ( $after < $need - 0.01 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Channel the design's sheets (Token_Styles::channelize_css()) so their text colours can follow a text
	 * channel of their own. Once per sheet; pixel-neutral until a channel is set.
	 */
	private static function channelize( int $home ): void {
		$paths = array( Upload_Paths::for_meta( $home, '_dxai_ui_css_url' )['path'] );
		$site  = Upload_Paths::path( Upload_Paths::DIR . '/site-' . $home . '.css' );
		if ( $site !== '' ) {
			$paths[] = $site;
		}
		foreach ( array_unique( array_filter( $paths ) ) as $path ) {
			if ( ! is_readable( $path ) || ! is_writable( $path ) ) {
				continue;
			}
			$css = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$new = Token_Styles::channelize_css( $css );
			if ( $new !== $css ) {
				file_put_contents( $path, $new ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}

	/**
	 * After an import or a page build: the design's binding for the current theme, proposed again from its colour
	 * use now, with the mode and the per-colour choices a person made kept. Nothing when the theme has no palette.
	 */
	public static function after_import( int $home ): void {
		if ( $home > 0 && Theme_Palette::current()['has_palette'] && is_array( get_post_meta( $home, Token_Styles::META, true ) ) ) {
			self::apply( $home );
		}
	}

	/**
	 * Designs on this site without a binding for the current theme get one: one design per admin request, in the
	 * background of normal work, so an update needs no step of its own.
	 */
	public static function upgrade(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) || ! Theme_Palette::current()['has_palette'] ) {
			return;
		}
		foreach ( \DXAI_UI\Structures\Design_Attach::designs() as $page ) {
			if ( self::get( (int) $page->ID ) === null && is_array( get_post_meta( (int) $page->ID, Token_Styles::META, true ) ) ) {
				self::apply( (int) $page->ID );
				return;
			}
		}
	}

	/**
	 * The design's solid and translucent colours: slug => value, alpha, OKLCH.
	 *
	 * @return array<string, array{value:string, alpha:float, lch:array{0:float,1:float,2:float}}>
	 */
	private static function design_colors( int $home ): array {
		$palette = get_post_meta( $home, Token_Styles::META, true );
		$out     = array();
		foreach ( is_array( $palette['colors'] ?? null ) ? $palette['colors'] : array() as $row ) {
			$slug = sanitize_key( (string) ( $row['slug'] ?? '' ) );
			$rgba = Color_Math::parse( (string) ( $row['value'] ?? '' ) );
			if ( $slug === '' || $rgba === null ) {
				continue;
			}
			$out[ $slug ] = array(
				'value' => (string) $row['value'],
				'alpha' => $rgba[3],
				'lch'   => Color_Math::oklch( $rgba ),
			);
		}

		return $out;
	}

	/** @return array<string, string> role => token slug */
	private static function design_roles( int $home ): array {
		$palette = get_post_meta( $home, Token_Styles::META, true );

		return is_array( $palette['roles'] ?? null ) ? array_map( 'strval', $palette['roles'] ) : array();
	}

	/**
	 * The theme's colours with a role each: brand, accent, ink (dark text and dark bands), surface, border.
	 *
	 * @param array<string, mixed> $palette
	 * @return array<string, array{hex:string, role:string, origin:string, lch:array{0:float,1:float,2:float}}>
	 */
	private static function theme_candidates( array $palette ): array {
		$out = array();
		foreach ( $palette['entries'] as $slug => $entry ) {
			if ( $entry['hex'] === '' ) {
				continue;
			}
			$lch         = Color_Math::oklch( Color_Math::parse( $entry['hex'] ) );
			$out[ $slug ] = array(
				'hex'    => $entry['hex'],
				'role'   => self::theme_role( $slug . ' ' . strtolower( $entry['name'] ), $lch ),
				'origin' => 'theme',
				'lch'    => $lch,
			);
		}
		foreach ( $palette['anchors'] as $slug => $hex ) {
			$lch         = Color_Math::oklch( Color_Math::parse( $hex ) );
			$out[ $slug ] = array(
				'hex'    => $hex,
				'role'   => $slug === 'white' ? 'surface' : 'ink',
				'origin' => 'anchor',
				'lch'    => $lch,
			);
		}

		return $out;
	}

	/**
	 * A theme colour's role, from its name and its value: a name can say "border" of a dark navy, and
	 * "secondary" of a light grey, so the value decides between the name's readings.
	 *
	 * @param array{0:float,1:float,2:float} $lch
	 */
	private static function theme_role( string $name, array $lch ): string {
		$neutral = $lch[1] < 0.03;
		$dark    = $lch[0] < 0.45;
		$light   = $lch[0] > 0.9;
		if ( preg_match( '/\b(border|line|divider|outline)\b/', $name ) ) {
			return 'border';
		}
		if ( $light && ( $neutral || preg_match( '/\b(base|background|surface|light|secondary|white|bg)\b/', $name ) ) ) {
			return 'surface';
		}
		if ( $dark && ( preg_match( '/\b(dark|contrast|text|foreground|heading|ink|body|black)\b/', $name ) || $neutral ) ) {
			return 'ink';
		}
		if ( ! $neutral && preg_match( '/\b(primary|brand|main)\b/', $name ) && ! preg_match( '/\b(light|dark)\b/', $name ) ) {
			return 'brand';
		}
		if ( ! $neutral && preg_match( '/\b(accent|cta|highlight|secondary|action)\b/', $name ) ) {
			return 'accent';
		}

		return $neutral ? ( $dark ? 'ink' : 'surface' ) : 'other';
	}

	/**
	 * Whether a design colour may take a theme colour of the same value: a neutral takes a neutral, a colour a
	 * colour; nothing takes a colour the theme uses for borders unless it is one.
	 *
	 * @param array{value:string, alpha:float, lch:array{0:float,1:float,2:float}} $d
	 * @param array{hex:string, role:string, origin:string, lch:array{0:float,1:float,2:float}} $t
	 */
	private static function compatible( array $d, array $t ): bool {
		return ( $d['lch'][1] < 0.03 ) === ( $t['lch'][1] < 0.03 ) && $t['role'] !== 'border';
	}

	/**
	 * @param array{0:float,1:float,2:float,3:float} $rgba
	 */
	private static function opaque( array $rgba ): string {
		return sprintf( '#%02x%02x%02x', (int) round( $rgba[0] ), (int) round( $rgba[1] ), (int) round( $rgba[2] ) );
	}

	/** An OKLCH colour as #rrggbb, clipped to sRGB. */
	private static function oklch_hex( float $l, float $c, float $h ): string {
		$a  = $c * cos( deg2rad( $h ) );
		$b  = $c * sin( deg2rad( $h ) );
		$l_ = $l + 0.3963377774 * $a + 0.2158037573 * $b;
		$m_ = $l - 0.1055613458 * $a - 0.0638541728 * $b;
		$s_ = $l - 0.0894841775 * $a - 1.2914855480 * $b;
		$l3 = $l_ ** 3;
		$m3 = $m_ ** 3;
		$s3 = $s_ ** 3;
		$rgb = array(
			4.0767416621 * $l3 - 3.3077115913 * $m3 + 0.2309699292 * $s3,
			-1.2684380046 * $l3 + 2.6097574011 * $m3 - 0.3413193965 * $s3,
			-0.0041960863 * $l3 - 0.7034186147 * $m3 + 1.7076147010 * $s3,
		);
		$out = array_map(
			static function ( $v ) {
				$v = $v <= 0.0031308 ? 12.92 * $v : 1.055 * ( $v ** ( 1 / 2.4 ) ) - 0.055;
				return (int) round( max( 0.0, min( 1.0, $v ) ) * 255 );
			},
			$rgb
		);

		return sprintf( '#%02x%02x%02x', $out[0], $out[1], $out[2] );
	}
}

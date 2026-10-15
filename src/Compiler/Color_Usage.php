<?php
/**
 * Where a design uses each of its colours.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Support\Upload_Paths;

/**
 * Before a design's colours follow a theme (Theme_Binding), two things have to
 * be known about each of its colour tokens: what it paints (text, background,
 * border), and which text sits on which background — so a theme colour that
 * would make text unreadable is caught before it is served.
 *
 * Read from what the design stores, not from a browser (hosting has none):
 *  - the block tree of the design's pages, pages built from them and its
 *    template parts: each block's own colours come from its classes
 *    (`text-dxai-T`, `bg-dxai-T`), its CSS (`dxaiCss`, `dxaiInner`) and core
 *    colour presets (`textColor`, `backgroundColor`); a text block's pair is
 *    its text colour (its own, or inherited) on the nearest background up the
 *    tree (the design root's when none);
 *  - the design sheet, for how often each token is used and for the root's own
 *    text and background.
 * Text over photos and colours set only by descendant rules of the sheet are
 * not seen; the render guard (Theme_Binding) keeps the design colour when a
 * pair it does see would fail.
 *
 * And which selectors paint each background, with the text colour they paint
 * it under (`painters`, keyed `<bg>|<fg>`): the importer's class, a core
 * preset's class, the Colour panel's inline style, a block's own rule class
 * (its `dxaiCss`, or an inline run of it). A text colour the theme's
 * background no longer reads on can then be given another one there alone
 * (Theme_Binding's scoped channels).
 */
final class Color_Usage {

	public const META = '_dxai_ui_color_usage';

	/** @var array<string, true> The design's translucent tokens (a text on one is read against what is under it). */
	private static array $translucent = array();

	/** Blocks whose own content is text a person reads. */
	private const TEXT_BLOCKS = array( 'core/paragraph', 'core/heading', 'core/list-item', 'core/button', 'core/quote', 'core/pullquote', 'dxai-ui/text', 'dxai-ui/link', 'dxai-ui/button' );

	/**
	 * Scan a design (its Home id) and store the result on the Home.
	 *
	 * @return array{tokens: array<string, array{fg:int, bg:int, border:int}>, pairs: array<string, int>, root: array{fg:string, bg:string}, painters: array<string, mixed>}
	 */
	public static function scan( int $home ): array {
		self::$translucent = self::translucent_tokens( $home );
		$root  = self::root_colors( $home );
		$usage = array(
			'tokens'   => array(),
			'pairs'    => array(),
			'root'     => $root,
			'painters' => array(),
		);
		foreach ( self::posts( $home ) as $post_id ) {
			foreach ( parse_blocks( (string) get_post_field( 'post_content', $post_id ) ) as $block ) {
				self::walk( $block, $root['fg'], $root['bg'], $usage );
			}
		}
		self::sheet_counts( $home, $usage );
		// Selectors as lists, a few dozen at most for one background and text.
		foreach ( $usage['painters'] as $key => $set ) {
			$usage['painters'][ $key ] = array_slice( array_keys( (array) $set ), 0, 40 );
		}
		update_post_meta( $home, self::META, $usage );

		return $usage;
	}

	/**
	 * The pairs as rows: [fg token, bg token, count, large].
	 *
	 * @param array<string, int> $pairs
	 * @return array<int, array{0:string, 1:string, 2:int, 3:bool}>
	 */
	public static function pair_rows( array $pairs ): array {
		$out = array();
		foreach ( $pairs as $key => $count ) {
			$parts = explode( '|', (string) $key );
			if ( count( $parts ) === 3 ) {
				$out[] = array( $parts[0], $parts[1], (int) $count, $parts[2] === '1' );
			}
		}

		return $out;
	}

	/**
	 * The posts that show the design: its Home, the pages built into its scope, and its template parts.
	 *
	 * @return array<int, int>
	 */
	public static function posts( int $home ): array {
		$ids   = array( $home );
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'private', 'future' ),
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(
					array(
						'key'   => Page_Scope::META,
						'value' => (string) $home,
					),
				),
			)
		);
		$ids = array_merge( $ids, array_map( 'intval', $pages ) );
		$key = \DXAI_UI\Chrome\Page_Chrome::part_key_for( $home );
		if ( $key !== '' ) {
			foreach ( array( 'header', 'footer' ) as $area ) {
				$parts = get_posts(
					array(
						'post_type'      => 'wp_template_part',
						'name'           => \DXAI_UI\Structures\Template_Part_Factory::slug( $area, $key ),
						'post_status'    => array( 'publish', 'draft', 'private' ),
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'no_found_rows'  => true,
					)
				);
				$ids   = array_merge( $ids, array_map( 'intval', $parts ) );
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param array<string, mixed> $block
	 * @param array<string, mixed> $usage
	 */
	private static function walk( array $block, string $fg, string $bg, array &$usage ): void {
		$name = (string) ( $block['blockName'] ?? '' );
		if ( $name === '' ) {
			return;
		}
		$own = self::block_colors( $block );
		foreach ( array( 'fg', 'bg' ) as $channel ) {
			if ( $own[ $channel ] !== '' ) {
				self::bump( $usage, $own[ $channel ], $channel );
			}
		}
		foreach ( $own['border'] as $slug ) {
			self::bump( $usage, $slug, 'border' );
		}
		// Text set in a colour that is no token (`text-white`, a literal) is no token's: nothing in it is paired with the one above.
		$fg = $own['fg'] !== '' ? $own['fg'] : ( $own['other'] ? '' : $fg );
		// A translucent background (white at 10% on a dark band) is read as the opaque one under it.
		$bg = $own['bg'] !== '' && ! isset( self::$translucent[ $own['bg'] ] ) ? $own['bg'] : $bg;
		if ( $own['bg'] !== '' && $own['painter'] !== '' && $fg !== '' && ! isset( self::$translucent[ $own['bg'] ] ) ) {
			$usage['painters'][ $own['bg'] . '|' . $fg ][ $own['painter'] ] = true;
		}
		if ( in_array( $name, self::TEXT_BLOCKS, true ) && trim( wp_strip_all_tags( (string) ( $block['innerHTML'] ?? '' ) ) ) !== '' ) {
			$large = $name === 'core/heading' && (int) ( $block['attrs']['level'] ?? 2 ) <= 3;
			self::pair( $usage, $fg, $bg, $large );
		}
		foreach ( $own['inner'] as $run ) {
			self::pair( $usage, $run, $bg, false );
			self::bump( $usage, $run, 'fg' );
		}
		// An inline run with a background of its own (Five Star's step numbers: a brand circle around the text they inherit).
		foreach ( $own['runs'] as $run ) {
			$run_fg = $run['fg'] !== '' ? $run['fg'] : $fg;
			if ( $run_fg === '' || isset( self::$translucent[ $run['bg'] ] ) ) {
				continue;
			}
			self::pair( $usage, $run_fg, $run['bg'], false );
			self::bump( $usage, $run['bg'], 'bg' );
			$usage['painters'][ $run['bg'] . '|' . $run_fg ][ '.' . $run['class'] ] = true;
		}
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $child ) {
			if ( is_array( $child ) ) {
				self::walk( $child, $fg, $bg, $usage );
			}
		}
	}

	/**
	 * A block's own colour tokens.
	 *
	 * @param array<string, mixed> $block
	 * @return array{fg:string, bg:string, border:array<int, string>, inner:array<int, string>, painter:string, runs:array<int, array{class:string, fg:string, bg:string}>, other:bool}
	 */
	private static function block_colors( array $block ): array {
		$attrs = (array) ( $block['attrs'] ?? array() );
		$out   = array(
			'fg'      => '',
			'bg'      => '',
			'border'  => array(),
			'inner'   => array(),
			'painter' => '',
			'runs'    => array(),
			'other'   => false,
		);
		$class = (string) ( $attrs['className'] ?? '' );
		if ( preg_match( '/^\s*<[a-z][a-z0-9]*\b[^>]*\bclass="([^"]*)"/i', (string) ( $block['innerHTML'] ?? '' ), $m ) ) {
			$class .= ' ' . $m[1];
		}
		if ( preg_match( '/(?:^|\s)text-dxai-([a-z0-9]+(?:-[a-z0-9]+)*)(?:\s|$)/', $class, $m ) ) {
			$out['fg'] = $m[1];
		}
		foreach ( preg_split( '/\s+/', trim( $class ) ) ?: array() as $name ) {
			if ( str_starts_with( $name, 'text-' ) && ! str_starts_with( $name, 'text-dxai-' ) && preg_match( '/(?:^|;)\s*color\s*:/', Utility_Classes::base_declarations( $name ) ) === 1 ) {
				$out['other'] = true;
			}
		}
		if ( is_string( $attrs['style']['color']['text'] ?? null ) && self::token_in( $attrs['style']['color']['text'] ) === '' ) {
			$out['other'] = true;
		}
		if ( preg_match( '/(?:^|\s)bg-dxai-([a-z0-9]+(?:-[a-z0-9]+)*)(?:\s|$)/', $class, $m ) ) {
			$out['bg']      = $m[1];
			$out['painter'] = '.bg-dxai-' . $m[1];
		}
		// Older imports carry the design's colours as core presets named after its tokens.
		if ( $out['fg'] === '' && is_string( $attrs['textColor'] ?? null ) ) {
			$out['fg'] = sanitize_key( $attrs['textColor'] );
		}
		// The Colour panel's custom colour that names a token (Native\Text_Color writes the class's own declaration there).
		if ( $out['fg'] === '' && is_string( $attrs['style']['color']['text'] ?? null ) ) {
			$out['fg'] = self::token_in( $attrs['style']['color']['text'] );
		}
		if ( $out['bg'] === '' && is_string( $attrs['backgroundColor'] ?? null ) ) {
			$out['bg']      = sanitize_key( $attrs['backgroundColor'] );
			$out['painter'] = '.has-' . $out['bg'] . '-background-color';
		}
		// …and its custom background that names one (Native\Background_Color writes the class's own declaration there): a card's colour
		// lives there, and the text in it was read against the section under the card.
		if ( $out['bg'] === '' && is_string( $attrs['style']['color']['background'] ?? null ) ) {
			$out['bg']      = self::token_in( $attrs['style']['color']['background'] );
			$out['painter'] = $out['bg'] !== '' ? '[style*="background-color:var(--dxai-' . $out['bg'] . ')"]' : '';
		}
		$own_css = self::declarations( (string) ( $attrs['dxaiCss'] ?? '' ) );
		if ( isset( $own_css['color'] ) && self::token_in( $own_css['color'] ) === '' && ! preg_match( '/^\s*(inherit|currentcolor)\s*$/i', (string) $own_css['color'] ) ) {
			$out['other'] = true;
		}
		foreach ( $own_css as $prop => $value ) {
			$token = self::token_in( $value );
			if ( $token === '' ) {
				continue;
			}
			if ( $prop === 'color' ) {
				$out['fg'] = $token;
			} elseif ( str_starts_with( $prop, 'background' ) ) {
				if ( $out['bg'] === '' ) {
					$out['bg']      = $token;
					$out['painter'] = '.' . Style_Hoister::css_class( (string) $attrs['dxaiCss'] );
				}
			} elseif ( str_starts_with( $prop, 'border' ) || str_starts_with( $prop, 'outline' ) ) {
				$out['border'][] = $token;
			}
		}
		foreach ( is_array( $attrs['dxaiInner'] ?? null ) ? $attrs['dxaiInner'] : array() as $run_class => $css ) {
			$decls  = self::declarations( (string) $css );
			$token  = isset( $decls['color'] ) ? self::token_in( $decls['color'] ) : '';
			$ground = '';
			foreach ( array( 'background', 'background-color' ) as $prop ) {
				$ground = $ground !== '' ? $ground : ( isset( $decls[ $prop ] ) ? self::token_in( $decls[ $prop ] ) : '' );
			}
			if ( $ground !== '' && is_string( $run_class ) && preg_match( '/^dxs-[a-z0-9]+$/', $run_class ) === 1 ) {
				$out['runs'][] = array(
					'class' => $run_class,
					'fg'    => $token,
					'bg'    => $ground,
				);
			} elseif ( $token !== '' ) {
				$out['inner'][] = $token;
			}
		}

		return $out;
	}

	/**
	 * The design's translucent colour tokens, from the palette it was tokenised against.
	 *
	 * @return array<string, true>
	 */
	private static function translucent_tokens( int $home ): array {
		$palette = get_post_meta( $home, Token_Styles::META, true );
		$out     = array();
		foreach ( is_array( $palette['colors'] ?? null ) ? $palette['colors'] : array() as $row ) {
			$rgba = \DXAI_UI\Support\Color_Math::parse( (string) ( $row['value'] ?? '' ) );
			if ( is_array( $row ) && $rgba !== null && $rgba[3] < 1 ) {
				$out[ (string) ( $row['slug'] ?? '' ) ] = true;
			}
		}

		return $out;
	}

	/**
	 * The design root's own text and background: the rule for `.dxai-ui.dxai-ui--{scope}` itself in its sheet.
	 *
	 * @return array{fg:string, bg:string}
	 */
	private static function root_colors( int $home ): array {
		$out   = array(
			'fg' => '',
			'bg' => '',
		);
		$css   = self::sheet( $home );
		$scope = preg_quote( '.dxai-ui.dxai-ui--' . $home, '/' );
		if ( preg_match_all( '/(?:^|[}\s])' . $scope . '\s*\{([^{}]*)\}/', $css, $m ) ) {
			foreach ( $m[1] as $body ) {
				$decls = self::declarations( $body );
				if ( isset( $decls['color'] ) && self::token_in( $decls['color'] ) !== '' ) {
					$out['fg'] = self::token_in( $decls['color'] );
				}
				foreach ( array( 'background', 'background-color' ) as $prop ) {
					if ( isset( $decls[ $prop ] ) && self::token_in( $decls[ $prop ] ) !== '' ) {
						$out['bg'] = self::token_in( $decls[ $prop ] );
					}
				}
			}
		}

		return $out;
	}

	/**
	 * How often the design sheet uses each token, by what it paints.
	 *
	 * @param array<string, mixed> $usage
	 */
	private static function sheet_counts( int $home, array &$usage ): void {
		if ( ! preg_match_all( '/\{([^{}]*)\}/', self::sheet( $home ), $m ) ) {
			return;
		}
		foreach ( $m[1] as $body ) {
			foreach ( self::declarations( $body ) as $prop => $value ) {
				if ( str_starts_with( $prop, '--' ) ) {
					continue;
				}
				$channel = in_array( $prop, array( 'color', 'fill', 'stroke', '-webkit-text-fill-color', 'text-decoration-color', 'caret-color' ), true ) ? 'fg'
					: ( str_starts_with( $prop, 'background' ) ? 'bg' : ( str_starts_with( $prop, 'border' ) || str_starts_with( $prop, 'outline' ) ? 'border' : '' ) );
				if ( $channel === '' ) {
					continue;
				}
				if ( preg_match_all( '/var\(\s*--dxai-([a-z0-9]+(?:-[a-z0-9]+)*)(?:--fg)?\s*[,)]/', $value, $vars ) ) {
					foreach ( array_unique( $vars[1] ) as $slug ) {
						self::bump( $usage, $slug, $channel );
					}
				}
			}
		}
	}

	/**
	 * The background a block paints of its own, as the design wrote it: `token:<slug>` for a design token, `preset:<slug>` for a colour
	 * preset of the site, a literal colour, `image` for a gradient or a picture, or '' when it paints none (what is under it shows).
	 *
	 * Read like scan() reads a block (the importer's `bg-dxai-*` classes, the Colour panel, `dxaiCss`), and also from the design's
	 * own class names, which a Tailwind design keeps (`bg-flame`): the rule the design's sheet has for the class names the token.
	 * For a converter that has to judge one colour against what lies behind it (Native\Button), not for the scan: the pairs it
	 * stores stay what they were.
	 *
	 * @param array<string, mixed> $attrs A block's attributes as the importer stored them.
	 * @param int                  $home  The design's Home, whose sheet holds the rules of its classes.
	 */
	public static function background_of( array $attrs, int $home ): string {
		if ( is_string( $attrs['backgroundColor'] ?? null ) && $attrs['backgroundColor'] !== '' ) {
			return 'preset:' . sanitize_key( $attrs['backgroundColor'] );
		}
		if ( ( $attrs['gradient'] ?? '' ) !== '' || ( $attrs['style']['color']['gradient'] ?? '' ) !== '' ) {
			return 'image';
		}
		$custom = $attrs['style']['color']['background'] ?? '';
		if ( is_string( $custom ) && $custom !== '' ) {
			return self::painted( $custom );
		}
		$own = self::painted_by( self::declarations( (string) ( $attrs['dxaiCss'] ?? '' ) ) );
		if ( $own !== '' ) {
			return $own;
		}
		$classes = preg_split( '/\s+/', trim( (string) ( $attrs['className'] ?? '' ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		foreach ( $classes as $class ) {
			if ( preg_match( '/^bg-dxai-([a-z0-9]+(?:-[a-z0-9]+)*)$/', $class, $m ) === 1 ) {
				return 'token:' . $m[1];
			}
		}
		$sheet = $home > 0 ? self::sheet_once( $home ) : '';
		foreach ( $classes as $class ) {
			// The class itself, not a variant of it (`hover:bg-…`, `md:bg-…`): what the block paints at rest.
			if ( ! str_starts_with( $class, 'bg-' ) || str_contains( $class, ':' ) || $sheet === '' ) {
				continue;
			}
			$escaped = (string) preg_replace( '/([^A-Za-z0-9_-])/', '\\\\$1', $class );
			if ( preg_match_all( '/(?:^|[{},])\s*([^{}]*?(?<![\w\\\\-])\.' . preg_quote( $escaped, '/' ) . '(?![\w\\\\-])[^{}]*)\{([^{}]*)\}/', $sheet, $rules, PREG_SET_ORDER ) < 1 ) {
				continue;
			}
			foreach ( $rules as $rule ) {
				$paint = self::painted_by( self::declarations( (string) $rule[2] ) );
				if ( $paint !== '' ) {
					return $paint;
				}
			}
		}

		return '';
	}

	/**
	 * What a declaration list paints as a background (see background_of()).
	 *
	 * @param array<string, string> $decls
	 */
	private static function painted_by( array $decls ): string {
		$image = trim( (string) ( $decls['background-image'] ?? '' ) );
		if ( $image !== '' && strtolower( $image ) !== 'none' ) {
			return 'image';
		}
		foreach ( array( 'background-color', 'background' ) as $prop ) {
			$value = trim( (string) ( $decls[ $prop ] ?? '' ) );
			if ( $value !== '' ) {
				return self::painted( $value );
			}
		}

		return '';
	}

	/** A background value as background_of() names it. */
	private static function painted( string $value ): string {
		if ( preg_match( '/\b(?:url|(?:linear|radial|conic)-gradient)\(/i', $value ) === 1 ) {
			return 'image';
		}
		$token = self::token_in( $value );
		if ( $token !== '' ) {
			return 'token:' . $token;
		}
		if ( preg_match( '/^var\(\s*--wp--preset--color--([a-z0-9-]+)\s*[,)]/i', $value, $m ) === 1 ) {
			return 'preset:' . strtolower( $m[1] );
		}

		return \DXAI_UI\Support\Color_Math::parse( $value ) !== null ? $value : '';
	}

	/** @var array<int, string> The design sheets background_of() has read in this request, by Home. */
	private static array $sheets = array();

	/** A design's sheet, read once per request. */
	private static function sheet_once( int $home ): string {
		if ( ! isset( self::$sheets[ $home ] ) ) {
			self::$sheets[ $home ] = self::sheet( $home );
		}

		return self::$sheets[ $home ];
	}

	/** The design's own stylesheet, as written ('' when it cannot be read). */
	private static function sheet( int $home ): string {
		$path = Upload_Paths::for_meta( $home, '_dxai_ui_css_url' )['path'];

		return $path !== '' && is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * A declaration list as property => value (last wins).
	 *
	 * @return array<string, string>
	 */
	private static function declarations( string $css ): array {
		$out = array();
		foreach ( preg_split( '/;(?![^(]*\))/', $css ) ?: array() as $decl ) {
			$at = strpos( $decl, ':' );
			if ( $at === false ) {
				continue;
			}
			$out[ strtolower( trim( substr( $decl, 0, $at ) ) ) ] = trim( substr( $decl, $at + 1 ) );
		}

		return $out;
	}

	/** The first design token a value reads ('' when none). */
	private static function token_in( string $value ): string {
		return preg_match( '/var\(\s*--dxai-([a-z0-9]+(?:-[a-z0-9]+)*?)(?:--fg)?\s*[,)]/', $value, $m ) ? $m[1] : '';
	}

	/** @param array<string, mixed> $usage */
	private static function bump( array &$usage, string $slug, string $channel ): void {
		if ( ! isset( $usage['tokens'][ $slug ] ) ) {
			$usage['tokens'][ $slug ] = array(
				'fg'     => 0,
				'bg'     => 0,
				'border' => 0,
			);
		}
		++$usage['tokens'][ $slug ][ $channel ];
	}

	/** @param array<string, mixed> $usage */
	private static function pair( array &$usage, string $fg, string $bg, bool $large ): void {
		if ( $fg === '' || $bg === '' ) {
			return;
		}
		$key                     = $fg . '|' . $bg . '|' . ( $large ? '1' : '0' );
		$usage['pairs'][ $key ] = (int) ( $usage['pairs'][ $key ] ?? 0 ) + 1;
	}
}

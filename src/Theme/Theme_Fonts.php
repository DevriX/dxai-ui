<?php
/**
 * The active theme's font settings, and a design following them.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Theme;

use DXAI_UI\Compiler\Design_Tokens;
use DXAI_UI\Compiler\Utility_Classes;
use DXAI_UI\Media\Font_Host;
use DXAI_UI\Structures\Design_Attach;
use DXAI_UI\Support\Upload_Paths;

/**
 * A theme that lets the site choose its fonts (the DevriX themes: Theme Global Settings > Fonts, a headline font and a
 * body font, as `--font-heading` and `--font-primary`) has already decided which font every element has: headings
 * the one, everything else the other. A design brings fonts of its own — a `font-family` on its root, another on its
 * display lines, a `--font-sans` and a `--font-display` its utilities read — and the plugin used to copy those fonts
 * into uploads so the page could draw them. On such a theme it does not: the page uses the theme's fonts.
 *
 *  - The theme's @font-face rules for the two fonts it is set to are printed with the design, and its two stacks are
 *    given names the design cannot shadow (`--dxai-theme-heading`, `--dxai-theme-body`) — a design that calls its own
 *    variable `--font-heading` must not read itself.
 *  - The design's font variables (`--font-sans`, `--font-display`, `--dxai-font`, …) are re-pointed at those two, by the
 *    role their name says; its root and its headings take them; a rule of its sheet that names a font in a class
 *    (`.rv-display { font-family: "Inter" }`) is written again with the role of that font; and so is a `font-family`
 *    in the CSS of a block (Style_Rules, via bind_css()). A font is the BODY font when it is the one the design's page
 *    root is set in, a HEADING font when it is any other, and a monospace web font is the BODY font too (a mono label
 *    is text like any other) — except in code (`pre`, `code`, `kbd`, `samp`), which is the system's monospace; an icon
 *    font is left alone.
 *  - Nothing is rewritten in the content or the stylesheet to do this — it is the rule printed with the page, so a
 *    theme switch or a change of the fonts in Theme Global Settings reaches every design at once and the design's own
 *    fonts come back when the theme stops managing them. What the plugin copied for the design is no longer needed
 *    and goes (Font_Host::adopt(), clean_files()).
 *
 * A design can keep its own fonts: the post meta MODE_META = 'keep', the filter `dxai_ui_theme_fonts_follow`, or fonts
 * the plugin cannot replace (an icon font in its stylesheets).
 *
 * Which themes manage fonts is `current()`: the DevriX contract (the `amr_*` typography functions) out of the box,
 * anything else through the filter `dxai_ui_theme_fonts`.
 */
final class Theme_Fonts {

	/** Post meta on a design's Home: 'keep' stops the design following the theme's fonts. */
	public const MODE_META = '_dxai_ui_theme_fonts';

	/** Post meta on a design's Home: what its stylesheet says about fonts (analysis()). */
	public const MAP_META = '_dxai_ui_font_map';

	/** Bumped when the analysis or the printed rule changes. */
	public const SCHEMA = 3;

	/** Option: the stylesheet and schema the last migration ran for. */
	public const DONE = 'dxai_ui_theme_fonts_done';

	/** The system's monospace, for code. */
	public const SYSTEM_MONO = 'ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace';

	/** Most rules of a sheet that are written again; a sheet with more keeps its own fonts. */
	private const MAX_RULES = 1500;

	/** What a font-family value can say that is not a font. */
	private const KEYWORDS = array( 'inherit', 'initial', 'unset', 'revert', 'revert-layer' );

	/** The default fonts of the system: a design that names one has not chosen a font (a display face such as Georgia or Impact is a choice). */
	private const SYSTEM = array( 'arial', 'helvetica', 'helvetica neue', 'verdana', 'tahoma', 'trebuchet ms', 'segoe ui', 'lucida grande', 'calibri', 'times', 'times new roman' );

	private const GENERIC = array( 'serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'ui-serif', 'ui-sans-serif', 'ui-monospace', 'ui-rounded', 'emoji', 'math', 'fangsong', '-apple-system', 'blinkmacsystemfont', 'inherit', 'initial', 'unset', 'revert', 'revert-layer' );

	/** An icon font names its glyphs, not its letters: it is the one thing that must keep its own face. */
	private const ICON = '/(?:icon|symbol|glyph|awesome|^fa[ -]|fontawesome|material|ionic|feather|lucide|dashicons|eicons?|remix|boxicons?|themify|typicons|linearicons|elegant|stroke 7|bootstrap)/i';

	/** @var array<string, mixed>|false|null */
	private static $memo = null;

	/** The design whose rules are being written (Style_Rules), or 0. */
	private static int $design = 0;

	/** @var array<int, array<string, mixed>> */
	private static array $analyses = array();

	/** @var array<string, bool> */
	private static array $follow = array();

	public function register(): void {
		add_action( 'admin_init', array( self::class, 'upgrade' ), 40 );
		add_action( 'switch_theme', array( self::class, 'reset' ) );
	}

	/* ------------------------------------------------------------------------------------------- the theme */

	/**
	 * The theme's fonts, once per request: null when the theme does not manage them.
	 *
	 * @return array{id:string, heading:string, body:string, heading_slug:string, body_slug:string, faces:string}|null
	 */
	public static function current(): ?array {
		if ( self::$memo !== null ) {
			return self::$memo === false ? null : self::$memo;
		}
		if ( ! did_action( 'after_setup_theme' ) ) {
			return null;
		}
		/**
		 * The fonts the active theme lets the site choose. The default is read from the DevriX typography functions;
		 * a theme with settings of its own returns the same shape, and `false` says the site's fonts are the design's.
		 *
		 * @param array<string, string>|null|false $fonts {id, heading, body, heading_slug, body_slug, faces}: the two CSS
		 *                                                font stacks, the slugs of the choices, and the @font-face rules to print.
		 */
		$found = apply_filters( 'dxai_ui_theme_fonts', self::read_theme() );
		$clean = self::clean( $found );
		self::$memo = $clean ?? false;

		return $clean;
	}

	/** Whether the theme manages the site's fonts. */
	public static function managed(): bool {
		return self::current() !== null;
	}

	/** Forget what was read on this request (a theme switch, a change of the fonts, tests). */
	public static function reset(): void {
		self::$memo     = null;
		self::$analyses = array();
		self::$follow   = array();
		self::$design   = 0;
	}

	/**
	 * Whether a design's fonts are the theme's: the theme manages them and the design was not told to keep its own.
	 */
	public static function follows( int $design ): bool {
		if ( ! self::managed() ) {
			return false;
		}
		$key = (string) $design . '|' . get_stylesheet();
		if ( ! isset( self::$follow[ $key ] ) ) {
			$keep = $design > 0 && (string) get_post_meta( $design, self::MODE_META, true ) === 'keep';
			/**
			 * Whether a design uses the theme's fonts.
			 *
			 * @param bool $follow Default: yes, unless the design was set to keep its own.
			 * @param int  $design The design's Home page id.
			 */
			self::$follow[ $key ] = (bool) apply_filters( 'dxai_ui_theme_fonts_follow', ! $keep, $design );
		}

		return self::$follow[ $key ];
	}

	/**
	 * Whether a design's own font files are replaced by the theme's: it follows, and has no font the theme cannot stand
	 * in for (an icon font), and its sheet is one this can describe.
	 */
	public static function adopts( int $design ): bool {
		if ( ! self::follows( $design ) ) {
			return false;
		}
		$analysis = self::analysis( $design );

		// An analysis with no key is a post without a readable sheet: not a design this can describe.
		return $analysis['k'] !== '' && ! $analysis['icons'] && ! $analysis['cut'];
	}

	/** The theme's choices as the DevriX theme reads them. */
	private static function read_theme(): ?array {
		foreach ( array( 'amr_get_acf_typography_slugs', 'amr_get_font_presets', 'amr_normalize_font_slug', 'amr_collect_font_face_rules' ) as $fn ) {
			if ( ! function_exists( $fn ) ) {
				return null;
			}
		}
		try {
			$slugs   = (array) amr_get_acf_typography_slugs();
			$presets = (array) amr_get_font_presets();
			$head    = (string) amr_normalize_font_slug( (string) ( $slugs['headlines'] ?? '' ) );
			$body    = (string) amr_normalize_font_slug( (string) ( $slugs['body'] ?? '' ) );
			$h       = (string) ( $presets[ $head ]['family'] ?? '' );
			$b       = (string) ( $presets[ $body ]['family'] ?? '' );
			if ( $h === '' || $b === '' ) {
				return null;
			}
			$rules = (array) amr_collect_font_face_rules( null, array_values( array_unique( array( $head, $body ) ) ) );
		} catch ( \Throwable $e ) {
			return null;
		}

		return array(
			'id'           => get_stylesheet(),
			'heading'      => $h,
			'body'         => $b,
			'heading_slug' => $head,
			'body_slug'    => $body,
			'faces'        => implode( "\n", array_map( 'strval', $rules ) ),
		);
	}

	/**
	 * What a theme (or a filter) gave, as the shape the rest reads, or null when it is not usable. A stack is a font
	 * list and nothing that could end a declaration or a rule; the @font-face rules cannot close a style element.
	 *
	 * @param mixed $found
	 * @return array{id:string, heading:string, body:string, heading_slug:string, body_slug:string, faces:string}|null
	 */
	private static function clean( $found ): ?array {
		if ( ! is_array( $found ) ) {
			return null;
		}
		$stack = static function ( $v ): string {
			$v = trim( (string) $v );

			return preg_match( '/^[A-Za-z0-9 ,\'"._-]{1,200}$/', $v ) === 1 ? $v : '';
		};
		$h = $stack( $found['heading'] ?? '' );
		$b = $stack( $found['body'] ?? '' );
		if ( $h === '' || $b === '' ) {
			return null;
		}
		$faces = (string) ( $found['faces'] ?? '' );
		if ( preg_match( '/<\/?(?:style|script)|\bexpression\(|@import/i', $faces ) === 1 ) {
			$faces = '';
		}

		return array(
			'id'           => (string) ( $found['id'] ?? get_stylesheet() ),
			'heading'      => $h,
			'body'         => $b,
			'heading_slug' => sanitize_key( (string) ( $found['heading_slug'] ?? '' ) ),
			'body_slug'    => sanitize_key( (string) ( $found['body_slug'] ?? '' ) ),
			'faces'        => $faces,
		);
	}

	/* ------------------------------------------------------------------------------------------- the roles */

	/**
	 * The families of a `font-family` value, unquoted and lower case, in order.
	 *
	 * @return array<int, string>
	 */
	public static function families( string $value ): array {
		$out   = array();
		$cur   = '';
		$quote = '';
		$depth = 0;
		for ( $i = 0, $n = strlen( $value ); $i <= $n; $i++ ) {
			$ch = $i < $n ? $value[ $i ] : ',';
			if ( $quote !== '' ) {
				if ( $ch === $quote ) {
					$quote = '';
				} else {
					$cur .= $ch;
				}
				continue;
			}
			if ( $ch === '"' || $ch === "'" ) {
				$quote = $ch;
				continue;
			}
			if ( $ch === '(' ) {
				++$depth;
			} elseif ( $ch === ')' ) {
				$depth = max( 0, $depth - 1 );
			}
			if ( $ch === ',' && $depth === 0 ) {
				$name = strtolower( trim( (string) preg_replace( '/\s+/', ' ', $cur ) ) );
				if ( $name !== '' ) {
					$out[] = $name;
				}
				$cur = '';
				continue;
			}
			$cur .= $ch;
		}

		return $out;
	}

	/** The first family of a stack that is a name, not a generic one; '' when there is none. */
	public static function first_named( string $value ): string {
		foreach ( self::families( $value ) as $name ) {
			if ( ! in_array( $name, self::GENERIC, true ) ) {
				return $name;
			}
		}

		return '';
	}

	/** Whether a family is an icon font. */
	public static function is_icon( string $family ): bool {
		return preg_match( self::ICON, $family ) === 1;
	}

	/**
	 * What a `font-family` value of a design is to be, or null to leave it as it is.
	 *
	 * Left alone: a value that reads a variable (those are re-pointed), a keyword (inherit, initial…), an icon font.
	 * A list of generic families only (`sans-serif`) and a web-safe system font (Arial, Georgia…) is the browser's
	 * default font, not a design's: the BODY font. A monospace stack is the BODY font (a mono label is text like any other), and the system's monospace
	 * where it is the font of code ($code: the rule's element is a `pre`, `code`, `kbd` or `samp`). Any other name is the
	 * BODY font when it is the one the design's page root is set in ($body, lower case), the HEADING font when it is
	 * another; with no root font known it is the body font — a display face on a body line is a smaller mistake than the
	 * other way round.
	 */
	public static function map_stack( string $value, string $body, bool $code = false ): ?string {
		$value = trim( (string) preg_replace( '/\s*!important\s*$/i', '', $value ) );
		if ( $value === '' || stripos( $value, 'var(' ) !== false || stripos( $value, 'env(' ) !== false ) {
			return null;
		}
		$families = self::families( $value );
		$first    = self::first_named( $value );
		if ( $families === array() || array_intersect( $families, self::KEYWORDS ) !== array() || ( $first !== '' && self::is_icon( $first ) ) ) {
			return null;
		}
		foreach ( $families as $name ) {
			if ( self::is_icon( $name ) && ! in_array( $name, self::GENERIC, true ) ) {
				return null;
			}
		}
		if ( in_array( 'monospace', $families, true ) || in_array( 'ui-monospace', $families, true ) || preg_match( '/(?:^|[ -])(?:mono|code|courier|consolas|menlo|monaco)(?:[ -]|$)/', $first ) === 1 ) {
			return $code ? self::SYSTEM_MONO : 'var(--dxai-theme-body)';
		}

		return $first === '' || $body === '' || $first === $body || in_array( $first, self::SYSTEM, true ) ? 'var(--dxai-theme-body)' : 'var(--dxai-theme-heading)';
	}

	/**
	 * Whether a selector list is about code: each selector of it ends in a `pre`, `code`, `kbd` or `samp`.
	 */
	private static function targets_code( string $selector ): bool {
		while ( preg_match( '/\([^()]*\)/', $selector ) === 1 ) {
			$selector = (string) preg_replace( '/\([^()]*\)/', '', $selector );
		}
		$n = 0;
		foreach ( explode( ',', $selector ) as $one ) {
			$one = trim( $one );
			if ( $one === '' ) {
				continue;
			}
			$compounds = preg_split( '/\s*[\s>+~]\s*/', $one );
			$last      = is_array( $compounds ) ? (string) end( $compounds ) : '';
			if ( preg_match( '/^(?:pre|code|kbd|samp)(?![\w-])/i', $last ) !== 1 ) {
				return false;
			}
			++$n;
		}

		return $n > 0;
	}

	/** The role a font variable's name says: heading, or body (a variable named for a monospace font too). */
	private static function var_role( string $name ): string {
		$name = strtolower( $name );

		return preg_match( '/display|heading|headline|head\b|title|serif|accent|brand/', $name ) === 1 ? 'heading' : 'body';
	}

	/* ------------------------------------------------------------------------------------------- the CSS of a block */

	/**
	 * Name the design whose rules are about to be written. Returns the one that was, to put back.
	 */
	public static function use_design( int $design ): int {
		$prior        = self::$design;
		self::$design = $design > 0 && Design_Attach::is_design( $design ) ? $design : 0;

		return $prior;
	}

	/**
	 * The declarations of a block's CSS with their `font-family` bound to the theme's roles, when the design being
	 * written follows the theme. Everything else is returned as it came.
	 */
	public static function bind_css( string $css ): string {
		if ( self::$design < 1 || stripos( $css, 'font-family' ) === false || ! self::follows( self::$design ) ) {
			return $css;
		}
		$body    = self::body_of( self::$design );
		$changed = false;
		$out     = array();
		foreach ( Utility_Classes::declarations( $css ) as $d ) {
			if ( $d['prop'] === 'font-family' ) {
				$new = self::map_stack( $d['value'], $body );
				if ( $new !== null ) {
					$out[]   = 'font-family:' . $new . ( $d['important'] ? ' !important' : '' );
					$changed = true;
					continue;
				}
			}
			$out[] = $d['raw'];
		}

		return $changed ? implode( ';', $out ) : $css;
	}

	/** What the cached rules of a design depend on from here: nothing when it does not follow. */
	public static function signature( int $design ): string {
		if ( $design < 1 || ! Design_Attach::is_design( $design ) || ! self::follows( $design ) ) {
			return '';
		}

		return md5( self::SCHEMA . '|' . self::body_of( $design ) );
	}

	/* ------------------------------------------------------------------------------------------- the sheet */

	/** The family the design's page is set in (lower case), '' when it does not say. */
	public static function body_of( int $design ): string {
		return self::analysis( $design )['body'];
	}

	/**
	 * What a design's stylesheet says about fonts, stored on its Home keyed by the sheet's version.
	 *
	 * @return array{k:string, body:string, vars:array<string, string>, rules:array<int, array{at:array<int, string>, s:string, v:string}>, icons:bool, cut:bool}
	 */
	public static function analysis( int $design ): array {
		if ( isset( self::$analyses[ $design ] ) ) {
			return self::$analyses[ $design ];
		}
		$empty = array(
			'k'     => '',
			'body'  => '',
			'vars'  => array(),
			'rules' => array(),
			'icons' => false,
			'cut'   => false,
		);
		$sheet = $design > 0 ? Upload_Paths::for_meta( $design, '_dxai_ui_css_url' ) : array( 'path' => '' );
		if ( (string) $sheet['path'] === '' || ! is_readable( (string) $sheet['path'] ) ) {
			return self::$analyses[ $design ] = $empty;
		}
		$tokens = Design_Tokens::for_page( $design );
		$token  = self::first_named( (string) ( $tokens['font'] ?? '' ) );
		$key    = self::SCHEMA . '|' . Upload_Paths::version( (string) $sheet['path'] ) . '|' . $token . '|' . md5( (string) wp_json_encode( get_post_meta( $design, '_dxai_ui_font_urls', true ) ) );
		$held   = get_post_meta( $design, self::MAP_META, true );
		if ( is_array( $held ) && ( $held['k'] ?? '' ) === $key && isset( $held['vars'], $held['rules'] ) ) {
			return self::$analyses[ $design ] = array_merge( $empty, $held );
		}
		$done = self::describe( (string) $sheet['path'], $design, $token );
		$done['k'] = $key;
		update_post_meta( $design, self::MAP_META, $done );

		return self::$analyses[ $design ] = $done;
	}

	/**
	 * Read a sheet: the font variables it defines, the rules that name a font, the family its page root is set in, and
	 * whether any font it loads is an icon font.
	 *
	 * @return array{k:string, body:string, vars:array<string, string>, rules:array<int, array{at:array<int, string>, s:string, v:string}>, icons:bool, cut:bool}
	 */
	private static function describe( string $path, int $design, string $token ): array {
		$css   = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$out   = array( 'k' => '', 'body' => $token, 'vars' => array(), 'rules' => array(), 'icons' => false, 'cut' => false );
		$rules = self::rules_of( $css );
		// The family the page root is set in: the rule for the design's scope element alone.
		$root = '';
		foreach ( $rules as $r ) {
			if ( $r['at'] === array() && preg_match( '/^\.dxai-ui\.dxai-ui--\d+\s*$/', $r['sel'] ) === 1 ) {
				foreach ( Utility_Classes::declarations( $r['body'] ) as $d ) {
					if ( $d['prop'] === 'font-family' && self::first_named( $d['value'] ) !== '' && stripos( $d['value'], 'var(' ) === false ) {
						$root = self::first_named( $d['value'] );
					}
				}
			}
		}
		// The variables: a custom property that is a font stack under a name that says "font".
		$vars = array();
		foreach ( $rules as $r ) {
			foreach ( Utility_Classes::declarations( $r['body'] ) as $d ) {
				if ( strncmp( $d['prop'], '--', 2 ) !== 0 || $d['prop'] === '--dxai-font' || $d['prop'] === '--font-heading-theme' ) {
					continue;
				}
				if ( preg_match( '/^--(?:[a-z0-9]+-)*(?:font|family)(?:-[a-z0-9]+)*$/', $d['prop'] ) !== 1 || preg_match( '/weight|size|style|stretch|feature|variation|smoothing|tracking|leading|line|^--tw-|^--wp--/', $d['prop'] ) === 1 ) {
					continue;
				}
				$role = self::var_role( $d['prop'] );
				if ( ! preg_match( '/[,"\']|var\(|serif|sans|inherit/i', $d['value'] ) ) {
					continue;
				}
				$vars[ $d['prop'] ] = $role;
				if ( $root === '' && $role === 'body' && $r['at'] === array() && stripos( $d['value'], 'var(' ) === false ) {
					$root = self::first_named( $d['value'] );
				}
			}
		}
		$body = $root !== '' ? $root : $token;
		// The rules that name a font in a class.
		$list = array();
		foreach ( $rules as $r ) {
			foreach ( Utility_Classes::declarations( $r['body'] ) as $d ) {
				if ( $d['prop'] !== 'font-family' ) {
					continue;
				}
				$new = self::map_stack( $d['value'], $body, self::targets_code( $r['sel'] ) );
				if ( $new === null ) {
					continue;
				}
				$list[] = array(
					'at' => $r['at'],
					's'  => $r['sel'],
					'v'  => $new . ( $d['important'] ? ' !important' : '' ),
				);
			}
		}
		$out['body']  = $body;
		$out['vars']  = $vars;
		$out['rules'] = array_slice( $list, 0, self::MAX_RULES );
		$out['cut']   = count( $list ) > self::MAX_RULES;
		$out['icons'] = self::loads_icon_font( $design, $css );

		return $out;
	}

	/** Whether the design loads an icon font: in its @font-face rules, its Google stylesheets, or what it listed. */
	private static function loads_icon_font( int $design, string $css ): bool {
		$names = array();
		if ( preg_match_all( '/@font-face\s*\{[^}]*?font-family\s*:\s*([^;}]+)/i', $css, $m ) ) {
			foreach ( $m[1] as $v ) {
				$names[] = self::first_named( $v );
			}
		}
		$urls = get_post_meta( $design, '_dxai_ui_font_urls', true );
		$urls = is_array( $urls ) ? array_map( 'strval', $urls ) : array();
		if ( preg_match_all( '#fonts\.googleapis\.com/css2?\?[^"\'\s)]+#i', $css, $m ) ) {
			$urls = array_merge( $urls, $m[0] );
		}
		foreach ( $urls as $url ) {
			if ( preg_match_all( '/family=([^:&;]+)/', Font_Host::normal( $url ), $m ) ) {
				foreach ( $m[1] as $family ) {
					$names[] = strtolower( str_replace( '+', ' ', rawurldecode( $family ) ) );
				}
			}
		}
		$path = Upload_Paths::for_meta( $design, '_dxai_ui_css_url' )['path'];
		foreach ( Font_Host::covered( (string) $path ) as $url ) {
			if ( preg_match_all( '/family=([^:&;]+)/', Font_Host::normal( (string) $url ), $m ) ) {
				foreach ( $m[1] as $family ) {
					$names[] = strtolower( str_replace( '+', ' ', rawurldecode( $family ) ) );
				}
			}
		}
		foreach ( $names as $name ) {
			if ( $name !== '' && self::is_icon( $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The style rules of a sheet with the at-rules around them: [ at => preludes, sel, body ]. @font-face, @keyframes
	 * and the like are not style rules; @media, @supports, @layer, @container and @scope are looked into.
	 *
	 * @return array<int, array{at:array<int, string>, sel:string, body:string}>
	 */
	private static function rules_of( string $css ): array {
		$css = (string) preg_replace( '~/\*.*?\*/~s', '', $css );
		$out = array();
		self::scan( $css, 0, strlen( $css ), array(), $out );

		return $out;
	}

	/**
	 * @param array<int, string>                                                        $at
	 * @param array<int, array{at:array<int, string>, sel:string, body:string}> $out
	 */
	private static function scan( string $css, int $i, int $end, array $at, array &$out ): void {
		$buf   = '';
		$quote = '';
		$paren = 0;
		while ( $i < $end ) {
			$ch = $css[ $i ];
			if ( $quote !== '' ) {
				$buf .= $ch;
				if ( $ch === '\\' && $i + 1 < $end ) {
					$buf .= $css[ ++$i ];
				} elseif ( $ch === $quote ) {
					$quote = '';
				}
				++$i;
				continue;
			}
			if ( $ch === '"' || $ch === "'" ) {
				$quote = $ch;
				$buf  .= $ch;
				++$i;
				continue;
			}
			if ( $ch === '(' ) {
				++$paren;
			} elseif ( $ch === ')' ) {
				$paren = max( 0, $paren - 1 );
			}
			if ( $paren === 0 && $ch === ';' ) {
				$buf = '';
				++$i;
				continue;
			}
			if ( $paren === 0 && $ch === '{' ) {
				$close   = self::closing( $css, $i, $end );
				$prelude = trim( $buf );
				$buf     = '';
				if ( $prelude !== '' && $prelude[0] === '@' ) {
					if ( preg_match( '/^@(?:media|supports|layer|container|scope)\b/i', $prelude ) === 1 ) {
						self::scan( $css, $i + 1, $close, array_merge( $at, array( (string) preg_replace( '/\s+/', ' ', $prelude ) ) ), $out );
					}
				} elseif ( $prelude !== '' ) {
					$out[] = array(
						'at'   => $at,
						'sel'  => (string) preg_replace( '/\s+/', ' ', $prelude ),
						'body' => substr( $css, $i + 1, $close - $i - 1 ),
					);
				}
				$i = $close + 1;
				continue;
			}
			$buf .= $ch;
			++$i;
		}
	}

	/** The index of the `}` that closes the `{` at $open (or $end when it never does). */
	private static function closing( string $css, int $open, int $end ): int {
		$depth = 0;
		$quote = '';
		for ( $i = $open; $i < $end; $i++ ) {
			$ch = $css[ $i ];
			if ( $quote !== '' ) {
				if ( $ch === '\\' ) {
					++$i;
				} elseif ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( $ch === '"' || $ch === "'" ) {
				$quote = $ch;
			} elseif ( $ch === '{' ) {
				++$depth;
			} elseif ( $ch === '}' ) {
				--$depth;
				if ( $depth === 0 ) {
					return $i;
				}
			}
		}

		return $end;
	}

	/* ------------------------------------------------------------------------------------------- what is printed */

	/**
	 * The rule that puts a design in the theme's fonts, printed with its stylesheet (Token_Styles::brand_css()):
	 * the theme's @font-face rules for the fonts it is set to, the two stacks under names of the plugin's, the
	 * design's font variables, its root and its headings, and the rules of its sheet that named a font. Empty for a
	 * design that keeps its own.
	 */
	public static function css( int $design ): string {
		$theme = self::current();
		if ( $theme === null || ! self::follows( $design ) ) {
			return '';
		}
		$a   = self::analysis( $design );
		$out = $theme['faces'] !== '' ? $theme['faces'] . "\n" : '';
		// A design page drops the theme's own typography (Theme_Compat): its two variables are written here, as it would.
		$define = Theme_Compat::is_converted_page() ? '--font-heading:' . $theme['heading'] . ';--font-primary:' . $theme['body'] . ';' : '';
		$out   .= ':root{' . $define . '--dxai-theme-heading:var(--font-heading,' . $theme['heading'] . ');--dxai-theme-body:var(--font-primary,' . $theme['body'] . ');}';
		// `.dxai-ui.dxai-ui` is (0,2,0), as the scoper's replacement of the design's `:root`, and this comes after it.
		$vars = array( '--dxai-font:var(--dxai-theme-body)' );
		foreach ( $a['vars'] as $name => $role ) {
			$vars[] = $name . ':var(--dxai-theme-' . ( $role === 'heading' ? 'heading' : 'body' ) . ')';
		}
		$out .= '.dxai-ui.dxai-ui{' . implode( ';', $vars ) . '}';
		$out .= '.dxai-ui.dxai-ui.dxai-ui{font-family:var(--dxai-theme-body)}';
		$out .= '.dxai-ui.dxai-ui :is(h1,h2,h3,h4,h5,h6,.wp-block-heading){font-family:var(--dxai-theme-heading)}';
		// Code is the one thing that stays monospace: the design's monospace variable is the body font now.
		$out .= '.dxai-ui.dxai-ui.dxai-ui :is(pre,code,kbd,samp){font-family:' . self::SYSTEM_MONO . '}';
		foreach ( $a['rules'] as $r ) {
			$rule = $r['s'] . '{font-family:' . $r['v'] . '}';
			foreach ( array_reverse( $r['at'] ) as $prelude ) {
				$rule = $prelude . '{' . $rule . '}';
			}
			$out .= $rule;
		}

		// Nothing in it can close a style element.
		return str_replace( '</', '<\\/', $out );
	}

	/* ------------------------------------------------------------------------------------------- migration */

	/**
	 * After an update, and after the theme or its fonts changed: every design that follows the theme is put in the
	 * theme's fonts (its own copied fonts leave its sheet, Font_Host::adopt()), and then the files nothing names are
	 * removed. A design that no longer follows gets its fonts back. A few designs per request.
	 */
	public static function upgrade(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state = self::state_key();
		if ( (string) get_option( self::DONE, '' ) === $state ) {
			return;
		}
		$deadline = time() + 8;
		$pending  = 0;
		// Every design, not the newest hundred the Library lists: a design left out would keep a copy nobody asked for.
		foreach ( Design_Attach::home_ids( 1000 ) as $home ) {
			if ( ! Design_Attach::is_design( $home ) ) {
				continue;
			}
			if ( time() > $deadline ) {
				$pending++;
				continue;
			}
			self::migrate( $home );
		}
		if ( $pending === 0 ) {
			if ( self::managed() ) {
				Font_Host::clean_files();
			}
			update_option( self::DONE, $state, false );
		}
	}

	/**
	 * One design after an update or a change of the theme's fonts: its own fonts leave its sheet when it follows the
	 * theme, and come back when it does not and they had left.
	 */
	public static function migrate( int $home ): void {
		if ( self::adopts( $home ) ) {
			if ( in_array( Font_Host::design_state( $home ), array( 'local', 'partial', 'remote' ), true ) ) {
				Font_Host::adopt_design( $home );
			}
		} elseif ( Font_Host::design_state( $home ) === 'theme' ) {
			// The theme stopped managing fonts (or the design was set to keep its own): its fonts come back.
			set_transient( 'dxai_ui_fonts_wait_' . $home, 1, 10 * MINUTE_IN_SECONDS );
			Font_Host::localize_design( $home, 8 );
		}
	}

	/** What the migration ran for: the theme, whether it manages fonts, this code's version of it. */
	private static function state_key(): string {
		return get_stylesheet() . '|' . ( self::managed() ? '1' : '0' ) . '|' . self::SCHEMA;
	}

	/** Ask for the migration to run again at the next admin request (the Library, the command line). */
	public static function rerun(): void {
		delete_option( self::DONE );
	}
}

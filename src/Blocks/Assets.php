<?php
/**
 * Shared editor/view assets for dynamic DXAI blocks.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks;

use DXAI_UI\Support\Upload_Paths;

final class Assets {

	/**
	 * File names this plugin writes per post into uploads/dxai-ui, and the
	 * post id each is named after: `pattern-{id}.css` and `.js`
	 * (Tailwind_Purger::persist(), persist_js()), `site-{id}.css`
	 * (Live_Content_Shell::persist_site_css()).
	 */
	private const OWNED_FILE = '/^(?:pattern-(\d+)\.(?:css|js)|site-(\d+)\.css)$/';

	public function register(): void {
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_page_assets' ) );
		// After the theme's own enqueue at the default priority.
		add_action( 'wp_enqueue_scripts', array( $this, 'drop_theme_styles' ), 100 );
		add_action( 'wp', array( $this, 'keep_design_punctuation' ) );
		add_filter( 'render_block_core/template-part', array( $this, 'unwrap_template_part' ), 10, 2 );
		// Registered here rather than in Plugin::boot(): these are the files
		// this class serves, so it is the one that knows which are whose.
		add_action( 'before_delete_post', array( $this, 'delete_post_files' ) );
	}

	/**
	 * A permanently deleted post takes its generated stylesheet and script
	 * with it.
	 *
	 * Nothing removed them before. Every import wrote a new
	 * `pattern-{id}.css` per section pattern and nothing ever deleted one, so
	 * this install reached 1478 sheets for 966 patterns, 511 of them belonging
	 * to no post at all. Runs on `before_delete_post` — emptying the trash, or
	 * a force delete; trashing keeps the files, since the post can come back.
	 */
	public function delete_post_files( int $post_id ): void {
		// Deleting a page deletes each of its revisions first, through this
		// same hook; a revision carries no generated files, and skipping it
		// saves a meta query per revision on a large purge.
		if ( get_post_type( $post_id ) === 'revision' ) {
			return;
		}
		foreach ( self::orphaned_files( $post_id ) as $file ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * The files delete_post_files() would remove for a post, without
	 * removing them.
	 *
	 * Deliberately narrow. Only names in OWNED_FILE, only directly inside
	 * uploads/dxai-ui (the resolved directory is compared, so neither a
	 * stored `..` nor a symlink leads anywhere else), and never a file
	 * another post still points at: every page crawled from a live site
	 * stores its Home's `site-{home}.css` and `pattern-{home}.js`, and some
	 * fall back to `pattern-{home}.css`, so deleting the Home must not strip
	 * the pages that share them. Besides the files named after this post, the
	 * files its own meta points at are considered too — that is how the last
	 * crawled page to go takes its deleted Home's shared sheet with it —
	 * but only once the post they are named after no longer exists.
	 *
	 * Public for probes, which must be able to check the choice without
	 * deleting anything.
	 *
	 * @return array<int, string> Absolute paths.
	 */
	public static function orphaned_files( int $post_id ): array {
		if ( $post_id < 1 ) {
			return array();
		}
		$dir  = Upload_Paths::path( Upload_Paths::DIR );
		$real = $dir !== '' ? realpath( $dir ) : false;
		if ( $real === false || ! is_dir( $real ) ) {
			return array();
		}

		$names = array( 'pattern-' . $post_id . '.css', 'pattern-' . $post_id . '.js', 'site-' . $post_id . '.css' );
		foreach ( array( '_dxai_ui_css_url', '_dxai_ui_js_url' ) as $key ) {
			$relative = Upload_Paths::for_meta( $post_id, $key )['relative'];
			if ( $relative !== '' && dirname( $relative ) === Upload_Paths::DIR ) {
				$names[] = basename( $relative );
			}
		}

		$out = array();
		foreach ( array_unique( $names ) as $name ) {
			if ( preg_match( self::OWNED_FILE, $name, $m ) !== 1 ) {
				continue;
			}
			$owner = (int) ( $m[1] !== '' ? $m[1] : ( $m[2] ?? 0 ) );
			if ( $owner !== $post_id && get_post( $owner ) instanceof \WP_Post ) {
				continue;
			}
			$file = realpath( $real . DIRECTORY_SEPARATOR . $name );
			if ( $file === false || ! is_file( $file ) || dirname( $file ) !== $real ) {
				continue;
			}
			if ( self::referenced_elsewhere( $name, $post_id ) ) {
				continue;
			}
			$out[] = $file;
		}

		return $out;
	}

	/**
	 * Whether any post other than $post_id stores a location ending in this
	 * file name. Matches both stored forms, relative and absolute, since the
	 * absolute ones end in the same `dxai-ui/{name}`; the `dxai-ui/` prefix
	 * keeps `pattern-12.css` from matching `pattern-112.css`. Trashed posts
	 * count — they can be restored.
	 */
	private static function referenced_elsewhere( string $name, int $post_id ): bool {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( Upload_Paths::DIR . '/' . $name );

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_dxai_ui_css_url','_dxai_ui_js_url') AND post_id <> %d AND ( meta_value LIKE %s OR meta_value LIKE %s ) LIMIT 1",
				$post_id,
				$like,
				$like . '?%'
			)
		);
	}

	/**
	 * The active theme's stylesheets stay off a converted page.
	 *
	 * The blank canvas skips the theme's chrome, but wp_head() still printed
	 * its CSS, and Twenty Twenty-Five's
	 * `h1, h2, h3, h4, h5, h6, blockquote, caption, figcaption, p { text-wrap:
	 * pretty }` re-flowed the design's text: Chrome moves a word down to avoid
	 * an orphan, so H2O's "commercial restoration" link measured 166px against
	 * the design's 637px at 768, and a Messaging Architecture span 370px
	 * against 405px. Nothing on our side can override it correctly — the
	 * theme's type selector outranks a `:where()`, and anything heavier
	 * outranks the design's own rules. The sheet has no business on this
	 * page at all, so every style the theme registers is dequeued.
	 */
	public function drop_theme_styles(): void {
		if ( ! is_singular() ) {
			return;
		}
		$id = get_the_ID();
		if ( ! $id || get_post_meta( $id, '_dxai_ui_css_url', true ) === '' ) {
			return;
		}
		$roots = array_unique( array_filter( array( (string) get_stylesheet_directory_uri(), (string) get_template_directory_uri() ) ) );
		foreach ( wp_styles()->registered as $handle => $style ) {
			$src = is_string( $style->src ?? null ) ? $style->src : '';
			foreach ( $roots as $root ) {
				if ( str_starts_with( $src, $root ) ) {
					wp_dequeue_style( (string) $handle );
					break;
				}
			}
		}
	}

	/**
	 * A dxai template part renders without core's wrapper element.
	 *
	 * core/template-part wraps what it renders in `<header
	 * class="wp-block-template-part">`. The design's header is a DIRECT child
	 * of its root, and RevOps styles it as one: `.dx-root > * { position:
	 * relative; z-index: 1 }` is what keeps its `.dx-head { position: sticky
	 * }` from ever sticking in the real build. With the wrapper between them
	 * the child combinator missed the header, so the page's header stuck where
	 * the design's scrolls away — position and z-index wrong at every width.
	 * The wrapper carries nothing of the design; the part's own landmark is
	 * the element the design had.
	 *
	 * @param array<string, mixed> $block
	 */
	public function unwrap_template_part( string $content, array $block ): string {
		$slug = (string) ( $block['attrs']['slug'] ?? '' );
		if ( ! str_starts_with( $slug, 'dxai-' ) ) {
			return $content;
		}
		if ( preg_match( '/^\s*<([a-z][a-z0-9]*)\b[^>]*\bclass="[^"]*\bwp-block-template-part\b[^"]*"[^>]*>([\s\S]*)<\/\1>\s*$/', $content, $m ) === 1 ) {
			return $m[2];
		}

		return $content;
	}

	public function register_assets(): void {
		wp_register_style(
			'dxai-ui-dynamic',
			DXAI_UI_URL . 'assets/css/dynamic.css',
			array(),
			(string) filemtime( DXAI_UI_DIR . 'assets/css/dynamic.css' )
		);

		if ( ! wp_style_is( 'dxai-ui-isolate', 'registered' ) ) {
			wp_register_style(
				'dxai-ui-isolate',
				DXAI_UI_URL . 'assets/css/frontend-isolate.css',
				array(),
				DXAI_UI_VERSION
			);
		}

		wp_register_script(
			'dxai-ui-blocks-editor',
			DXAI_UI_URL . 'assets/js/blocks-editor.js',
			// wp-api-fetch: the live utility-class preview asks the
			// utility-rules route; wp-notices: the snackbar shown when "Show
			// template" is turned back to the page itself. Both are used
			// lazily and guarded, and every block editor loads them anyway.
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-hooks', 'wp-compose', 'wp-data', 'wp-dom-ready', 'wp-api-fetch', 'wp-notices' ),
			(string) filemtime( DXAI_UI_DIR . 'assets/js/blocks-editor.js' ),
			true
		);

		wp_register_script(
			'dxai-ui-lucide',
			self::lucide_src(),
			array(),
			'0.544.0',
			true
		);

		wp_register_script(
			'dxai-ui-slider-view',
			DXAI_UI_URL . 'assets/js/slider-view.js',
			array(),
			DXAI_UI_VERSION,
			true
		);

		// The previous / next buttons of a row of cards that scrolls sideways (a design's reviews slider).
		wp_register_script(
			'dxai-ui-rail',
			DXAI_UI_URL . 'assets/js/rail.js',
			array(),
			(string) filemtime( DXAI_UI_DIR . 'assets/js/rail.js' ),
			true
		);

		wp_register_script(
			'dxai-ui-form-view',
			DXAI_UI_URL . 'assets/js/form-view.js',
			array(),
			DXAI_UI_VERSION,
			true
		);

		wp_localize_script(
			'dxai-ui-form-view',
			'dxaiUIForm',
			array(
				'restUrl' => esc_url_raw( rest_url( DXAI_UI_REST_NAMESPACE . '/forms/submit' ) ),
			)
		);
	}

	/**
	 * Leave a design page's punctuation exactly as the design wrote it.
	 *
	 * `wptexturize()` rewrites straight quotes as curly ones, `--` as an
	 * en dash, `...` as an ellipsis. On a page meant to be a copy of a design
	 * that is not an improvement, and it is not cosmetic either: a heading
	 * reading `Let's build the system` wrapped onto four lines with the
	 * design's apostrophe and three with WordPress's, a 65px difference from
	 * one character.
	 *
	 * The same argument covers emoji. WordPress ships a script that walks the
	 * rendered page and swaps every emoji character for an `<img>` from
	 * s.w.org, for browsers that cannot draw it. On a design page that inserts
	 * elements the design never had: the geometry oracle reported ARA as having
	 * three nodes the design does not contain, and they were
	 * `1f1fa-1f1f8.svg` and `1f1f2-1f1fd.svg` — the 🇺🇸 and 🇲🇽 flags in a
	 * sentence about Texas and Mexico, turned into images. It also means a text
	 * run's width depends on a remote asset loading.
	 */
	public function keep_design_punctuation(): void {
		if ( ! is_singular() ) {
			return;
		}
		$id = get_the_ID();
		if ( ! $id || \DXAI_UI\Structures\Design_Attach::source_for( (int) $id ) === 0 ) {
			return;
		}

		/*
		 * Every hook wptexturize is attached to, found by walking the filter
		 * registry rather than by listing names.
		 *
		 * The list used to be five hooks plus `render_block`, and it was both
		 * incomplete and partly imaginary: core attaches wptexturize to 22
		 * hooks and NOT to `render_block`, so the code removed something that
		 * was never there while missing whichever one actually carried the
		 * copy. Every imported page still rendered 1-3 texturize entities —
		 * ARA's mega-menu shipped `what you&#8217;re owed` where the design has
		 * a straight apostrophe.
		 *
		 * It stayed hidden because the emoji half of this function DID work, so
		 * the page looked treated. And it survived a direct search: grepping the
		 * served HTML for a raw U+2019 finds nothing, because wptexturize emits
		 * the NUMERIC ENTITY. Only comparing the rendered DOM caught it — the
		 * browser decodes `&#8217;` into a character, and the design's straight
		 * apostrophe is a different character.
		 *
		 * Walking the registry cannot go out of date when core adds a hook, and
		 * cannot be wrong about which hooks exist.
		 */
		/*
		 * `run_wptexturize` is core's own switch: wptexturize() consults it
		 * first and returns its input untouched when it is false, so this holds
		 * no matter which hook calls the function or when that hook was added.
		 *
		 * Unhooking by name cannot be made reliable here, and two rounds of
		 * trying proved it. The original list named five hooks plus
		 * `render_block` — core attaches wptexturize to 22 hooks and not to
		 * `render_block` at all, so it removed something that was never there
		 * and missed whatever carried the copy. Walking the whole filter
		 * registry on `wp` did better and still left two strings texturized on
		 * ARA, because the path that renders a template part attaches the
		 * filter after `wp` has already fired. A switch consulted at call time
		 * has no such ordering problem.
		 *
		 * What it cost while it was broken: every imported page shipped 1-3
		 * texturize entities, and ARA's mega-menu read
		 * `what you&#8217;re owed` where the design has a straight apostrophe.
		 * It hid for the whole session behind two things — the emoji half of
		 * this function worked, so the page looked treated; and a search of the
		 * served HTML for a raw U+2019 finds nothing, because wptexturize emits
		 * the NUMERIC ENTITY. Comparing the rendered DOM is what caught it: a
		 * browser decodes `&#8217;` to a character, and the design's straight
		 * apostrophe is a different character.
		 */
		add_filter( 'run_wptexturize', '__return_false' );

		// convert_smilies has no equivalent switch, so it is unhooked wherever
		// it is attached — by walking the registry rather than naming hooks,
		// for the same reason.
		global $wp_filter;
		foreach ( array_keys( (array) $wp_filter ) as $hook ) {
			$priority = has_filter( (string) $hook, 'convert_smilies' );
			if ( $priority !== false ) {
				remove_filter( (string) $hook, 'convert_smilies', (int) $priority );
			}
		}

		/*
		 * This runs on `wp`, which is before `wp_head` fires, so unhooking the
		 * detection script here is in time. Both spellings of the stylesheet
		 * hook are removed because core renamed it in 6.4 and a site may be on
		 * either.
		 */
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
	}

	public function maybe_enqueue_page_assets(): void {
		if ( ! is_singular() ) {
			return;
		}
		$id = get_the_ID();
		if ( ! $id ) {
			return;
		}
		$content = (string) get_post_field( 'post_content', $id );
		// A design page's header and footer are added around its content as it renders (Page_Chrome), so what they hold —
		// a button in the theme's style, an icon — is part of the page for what it needs loaded.
		$content = \DXAI_UI\Chrome\Page_Chrome::with_chrome( (int) $id, $content );
		// Template-part refs expand at render — scan linked dxai parts for icons.
		$content .= $this->template_part_markup( $content );
		// Buttons in the theme's button style: its rules, on the pages where the theme's stylesheet does not reach them
		// (a design page drops it, and a page holding a design's sections keeps it out of them). Anywhere else the theme's
		// own sheet is there, and a second copy would only weigh the page.
		if ( ( \DXAI_UI\Theme\Theme_Compat::is_converted_page() || \DXAI_UI\Theme\Theme_Fence::applies() ) && \DXAI_UI\Theme\Theme_Buttons::used_in( $content ) ) {
			\DXAI_UI\Theme\Theme_Buttons::enqueue();
		}
		// A slider's buttons move its row of cards: what the design's own script did, which the page script does not carry.
		if ( self::has_rail( $content ) ) {
			wp_enqueue_script( 'dxai-ui-rail' );
		}
		/*
		 * Both locations are built from the current upload URL, whichever form
		 * they were stored in, and versioned by the resolved file's mtime. The
		 * stored absolute URL used to be enqueued as-is, so a site moved to a
		 * new address or to https kept asking the old host for its CSS, and the
		 * cache-buster fell back to the plugin version because the URL-to-path
		 * guess found no file — a CDN then served the old sheet after every
		 * re-import. See Upload_Paths.
		 */
		// The design this page shows: its own, or — an ordinary page holding blocks copied from a design page —
		// that design's sheet, fonts and script (Design_Attach).
		$src      = \DXAI_UI\Structures\Design_Attach::source_for( (int) $id );
		$src      = $src > 0 ? $src : (int) $id;
		$css      = Upload_Paths::for_meta( $src, '_dxai_ui_css_url' );
		$js       = Upload_Paths::for_meta( $src, '_dxai_ui_js_url' );
		$fonts    = get_post_meta( $src, '_dxai_ui_font_urls', true );
		$attached = $src !== (int) $id && ! \DXAI_UI\Theme\Theme_Compat::is_converted_page();
		// The theme's own components among the copied blocks (its FAQ) keep their rules inside the design's sections, as on the page they came from.
		if ( \DXAI_UI\Theme\Theme_Fence::applies() ) {
			\DXAI_UI\Theme\Theme_Components::enqueue( $content, $css['path'] );
		}
		// The fonts the design's sheet carries itself (Font_Host) need no link to Google; a sheet that still asks Google for them is copied by cron.
		$carried = \DXAI_UI\Media\Font_Host::covered( $css['path'] );
		// A theme that lets the site choose its fonts draws the design in them (Theme_Fonts): its own are not loaded, and a
		// sheet that still has them is put in the theme's by cron.
		$theme_fonts = \DXAI_UI\Theme\Theme_Fonts::adopts( $src );
		if ( $theme_fonts && in_array( \DXAI_UI\Media\Font_Host::design_state( $src ), array( 'local', 'partial', 'remote' ), true ) ) {
			\DXAI_UI\Media\Font_Host::queue_design( $src );
		}
		// Its fonts left the sheet for the theme's, and the theme no longer draws it (the fonts were changed, or the design keeps its own):
		// until they are copied back, Google's stylesheets are linked as they were before the copy.
		$restoring = ! $theme_fonts && $css['path'] !== '' && \DXAI_UI\Media\Font_Host::design_state( $src ) === 'theme';
		if ( $restoring ) {
			$fonts   = array_values( array_unique( array_merge( is_array( $fonts ) ? array_map( 'strval', $fonts ) : array(), array_values( $carried ) ) ) );
			$carried = array();
		}
		if ( ! $theme_fonts && $css['path'] !== '' && ( $restoring || \DXAI_UI\Media\Font_Host::needs_work( $css['path'], is_array( $fonts ) ? $fonts : array() ) ) ) {
			\DXAI_UI\Media\Font_Host::queue_design( $src );
		}
		if ( ! $theme_fonts && is_array( $fonts ) ) {
			foreach ( array_values( $fonts ) as $i => $font_url ) {
				$font_url = $this->safe_font_url( (string) $font_url );
				if ( $font_url === '' || isset( $carried[ \DXAI_UI\Media\Font_Host::key( $font_url ) ] ) ) {
					continue;
				}
				// On a page of the theme, the copy from uploads: a theme's font stripper would take Google's link.
				if ( $attached ) {
					$font_url = Attached_Styles::local_font_url( $font_url );
				}
				wp_enqueue_style( 'dxai-ui-font-' . $id . '-' . $i, $font_url, array(), null );
			}
		}
		if ( $css['url'] !== '' ) {
			$ver = Upload_Paths::version( $css['path'] );
			/*
			 * The isolate sheet is Tailwind's box-sizing / image reset for a
			 * design that ran under Tailwind. A Claude Design export did not:
			 * H2O's layout is content-box throughout, and forcing border-box
			 * on it changes every padded width.
			 */
			$deps = array( 'dxai-ui-dynamic' );
			// Sections copied into a page of the theme from a design page that shows without it: first what their design page gives them (Attached_Styles).
			if ( $attached && \DXAI_UI\Theme\Theme_Fence::applies() ) {
				array_unshift( $deps, Attached_Styles::base( $src ) );
			}
			if ( ! get_post_meta( $src, '_dxai_ui_static_html', true ) ) {
				wp_enqueue_style( 'dxai-ui-isolate' );
				$deps[] = 'dxai-ui-isolate';
			}
			wp_enqueue_style( 'dxai-ui-page-' . $id, $css['url'], $deps, $ver );

			/*
			 * For the design that is the site's brand, point its tokens at the
			 * theme.json presets, so a colour changed in Site Editor > Styles
			 * reaches this page. Every other design's tokens are already defined
			 * as literals inside its own sheet (Token_Styles::tokenize_import()),
			 * so for those this is empty and the page renders exactly as
			 * imported.
			 *
			 * Decided here, at render time, and never baked into the sheet: when
			 * a later import becomes the brand, this page stops pointing at the
			 * presets on the next load instead of silently wearing the new
			 * brand's colours.
			 */
			$brand = \DXAI_UI\Compiler\Token_Styles::brand_css( $src );
			if ( $brand !== '' ) {
				wp_add_inline_style( 'dxai-ui-page-' . $id, $brand );
			}
		}

		$lucide = $this->enqueue_lucide( $content );
		if ( $js['url'] !== '' ) {
			$js_ver = Upload_Paths::version( $js['path'] );
			$deps   = $lucide !== '' ? array( $lucide ) : array();
			wp_enqueue_script( 'dxai-ui-page-' . $id, $js['url'], $deps, $js_ver, true );
			/*
			 * The page script loads the icon runtime itself when it meets a
			 * `data-lucide` this scan did not see — one a pattern block or an
			 * unscanned part renders — and it asks window.dxaiLucideUrl where
			 * the runtime is. enqueue_lucide() sets that only when it found an
			 * icon, and scripts persisted before this change fall back to the
			 * jsDelivr CDN when it is unset: a remote script plugin review
			 * flags and a strict CSP or a firewalled host blocks. So the
			 * bundled copy's URL is always set ahead of the page script.
			 */
			if ( $lucide === '' ) {
				wp_add_inline_script( 'dxai-ui-page-' . $id, 'window.dxaiLucideUrl=window.dxaiLucideUrl||' . wp_json_encode( self::lucide_src() ) . ';', 'before' );
			}
		} elseif ( $lucide !== '' && str_contains( $content, 'data-lucide' ) ) {
			// Icons still need createIcons() when there is no page JS blob.
			wp_add_inline_script(
				$lucide,
				'document.addEventListener("DOMContentLoaded",function(){if(window.lucide&&lucide.createIcons){lucide.createIcons({attrs:{"stroke-width":2}});}});',
				'after'
			);
		}
	}

	/**
	 * Whether markup holds a row that scrolls sideways and a previous or next control: the page needs assets/js/rail.js.
	 * The script finds the row and the buttons by what they are when one is pressed, so this only decides whether to send it.
	 */
	public static function has_rail( string $markup ): bool {
		return preg_match( '/aria-label="(?:next|prev)[^"]*"/i', $markup ) === 1
			&& preg_match( '/scroll-snap|snap-x|overflow-x\s*:|overflow-x-(?:auto|scroll)|data-track/i', $markup ) === 1;
	}

	/**
	 * Google Fonts CSS2 URLs contain `;` inside weight axes. Prefer a stable
	 * absolute URL and keep semicolons intact for the browser.
	 */
	private function safe_font_url( string $url ): string {
		$url = trim( $url );
		if ( $url === '' || ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		if ( str_contains( $url, 'fonts.googleapis.com' ) ) {
			// Reject truncated harvest leftovers such as `...family=X:ital`.
			if ( ! str_contains( $url, 'family=' ) || preg_match( '/family=[^&]+;(?:family=|&|$)/', $url ) ) {
				// keep
			}
			if ( preg_match( '/family=[^&]*:(?:ital)?$/i', $url ) ) {
				return '';
			}
			return $url;
		}

		return esc_url_raw( $url );
	}

	/**
	 * Expand what renders the header and footer — <!-- wp:template-part -->
	 * slugs, and the site header and footer blocks (their markup comes from
	 * Appearance > Menus and Widgets, not from the content) — so asset
	 * detection sees the chrome's HTML: a `data-lucide` icon in the header's
	 * buttons or the footer's social links needs the icon runtime as much as
	 * one in a section.
	 */
	private function template_part_markup( string $content ): string {
		$out = '';
		if ( str_contains( $content, '<!-- wp:' . \DXAI_UI\Chrome\Site_Header_Block::NAME ) ) {
			$spec = \DXAI_UI\Chrome\Header_Template::for_post( (int) get_the_ID() );
			if ( null !== $spec ) {
				$out .= "\n" . (string) ( $spec['markup'] ?? '' );
			}
		}
		if ( str_contains( $content, '<!-- wp:' . \DXAI_UI\Chrome\Site_Footer_Block::NAME ) ) {
			$out .= "\n" . \DXAI_UI\Chrome\Site_Footer_Block::markup();
		}
		if ( ! preg_match_all( '/<!--\s*wp:template-part\s+(\{.*?\})\s*\/?-->/', $content, $matches ) ) {
			return $out;
		}
		foreach ( $matches[1] as $json ) {
			$attrs = json_decode( $json, true );
			if ( ! is_array( $attrs ) || empty( $attrs['slug'] ) ) {
				continue;
			}
			$slug = sanitize_title( (string) $attrs['slug'] );
			if ( $slug === '' || ! str_starts_with( $slug, 'dxai-' ) ) {
				continue;
			}
			$parts = get_posts(
				array(
					'post_type'      => 'wp_template_part',
					'name'           => $slug,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
				)
			);
			if ( isset( $parts[0] ) && $parts[0] instanceof \WP_Post ) {
				$out .= "\n" . (string) $parts[0]->post_content;
			}
		}

		return $out;
	}

	/**
	 * @return string Registered handle when Lucide is needed, else empty.
	 */
	private function enqueue_lucide( string $content ): string {
		if ( ! str_contains( $content, 'data-lucide' ) ) {
			return '';
		}
		$src = self::lucide_src();
		wp_enqueue_script( 'dxai-ui-lucide' );
		wp_add_inline_script( 'dxai-ui-lucide', 'window.dxaiLucideUrl=' . wp_json_encode( $src ) . ';', 'before' );

		return 'dxai-ui-lucide';
	}

	/**
	 * Where the icon runtime lives.
	 *
	 * Resolved in one place because `wp_register_script()` ignores a second
	 * registration of the same handle: registering the bundled path up front
	 * and only then falling back to the CDN left the handle pointing at a file
	 * that may not be there, and the icons 404'd.
	 *
	 * Public so bin/design-oracle.php can mirror the same runtime: the oracle
	 * has to substitute icons exactly the way the page does, or every icon
	 * reads as a size mismatch.
	 *
	 * Always the copy bundled with the plugin (lucide v0.544.0, the version
	 * registered above). There used to be a jsDelivr fallback for when the
	 * file was missing; the file ships in assets/vendor/, and a remote script
	 * URL is something plugin review flags and a strict CSP or a firewalled
	 * host refuses to load — the icons would be gone there either way.
	 */
	public static function lucide_src(): string {
		return DXAI_UI_URL . 'assets/vendor/lucide.min.js';
	}
}

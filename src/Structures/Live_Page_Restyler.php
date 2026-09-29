<?php
/**
 * Restyle crawled live pages to match Home design language via LLM (DeepSeek).
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Structures;

use DXAI_UI\Compiler\Block_Normalizer;
use DXAI_UI\Compiler\Design_Css;
use DXAI_UI\Compiler\Fidelity_Validator;
use DXAI_UI\Compiler\Gutenberg_Mapper;
use DXAI_UI\Compiler\Html_To_Blocks;
use DXAI_UI\Compiler\Prompt_Builder;
use DXAI_UI\Engines\Engine_Factory;
use DXAI_UI\Engines\LLM_Provider_Interface;
use DXAI_UI\Support\Logger;

final class Live_Page_Restyler {

	private ?LLM_Provider_Interface $engine = null;

	/**
	 * The engine that rebuilds crawled pages: the one chosen in Settings.
	 *
	 * This used to prefer DeepSeek whenever a DeepSeek key was saved, which
	 * made the crawl spend DeepSeek credit on a site whose Settings said
	 * Claude — the import screen named one engine and the bill came from the
	 * other. The active engine wins now; DeepSeek (then any engine with a key)
	 * is only the fallback when the active one cannot run, e.g. its key is
	 * missing. `dxai_ui_restyle_engine` can name a specific engine id.
	 */
	public function engine(): LLM_Provider_Interface|\WP_Error {
		if ( $this->engine instanceof LLM_Provider_Interface ) {
			return $this->engine;
		}

		$wanted = (string) apply_filters( 'dxai_ui_restyle_engine', '' );
		$id     = $wanted !== '' ? $wanted : (string) ( \DXAI_UI\Settings\Options::get()['active_engine'] ?? '' );
		$active = Engine_Factory::make( $id !== '' ? $id : null );
		// Engine_Factory builds an engine whatever its key; one without a key
		// cannot run, so it does not count as available here.
		if ( ! is_wp_error( $active ) && self::has_key( $id ) ) {
			$this->engine = $active;

			return $this->engine;
		}

		if ( \DXAI_UI\Settings\Options::get_secret( 'deepseek_api_key' ) !== '' ) {
			$deepseek = Engine_Factory::make( 'deepseek' );
			if ( ! is_wp_error( $deepseek ) ) {
				$this->engine = $deepseek;

				return $this->engine;
			}
		}

		return is_wp_error( $active )
			? $active
			: new \WP_Error( 'dxai_ui_restyle_engine', __( 'The AI engine chosen in Settings has no API key, so pages cannot be rebuilt with AI.', 'dxai-ui' ) );
	}

	/** Whether the engine id has an API key saved. */
	private static function has_key( string $id ): bool {
		$secret = array(
			'claude'   => 'anthropic_api_key',
			'openai'   => 'openai_api_key',
			'grok'     => 'xai_api_key',
			'deepseek' => 'deepseek_api_key',
		)[ $id ] ?? '';

		return $secret !== '' && \DXAI_UI\Settings\Options::get_secret( $secret ) !== '';
	}

	/**
	 * @param array{title:string, path:string, html:string, images?:array<int, string>} $page
	 * @param array{home_html?:string, design_css?:string, home_title?:string, class_sample?:string, allowed_colors?:array<int, string>, style_recipes?:array<int, string>} $home
	 * @return array{markup:string, engine:string, restyled:bool, archetype?:string}|\WP_Error
	 */
	public function restyle( array $page, array $home ): array|\WP_Error {
		$engine = $this->engine();
		if ( is_wp_error( $engine ) ) {
			return $engine;
		}

		$title = sanitize_text_field( (string) ( $page['title'] ?? 'Page' ) );
		$html  = trim( (string) ( $page['html'] ?? '' ) );
		if ( $html === '' ) {
			return new \WP_Error( 'dxai_ui_restyle', __( 'Nothing to restyle.', 'dxai-ui' ) );
		}

		$page['content_facts'] = Prompt_Builder::content_facts_from_html( $html );
		$archetype             = Prompt_Builder::detect_page_archetype(
			$title,
			(string) ( $page['path'] ?? '' ),
			is_array( $page['content_facts'] ) ? $page['content_facts'] : array()
		);

		// Token mode whenever the home guide carries the design's palette: the
		// model is told to write colours only as var(--dxai-…) — see home_guide().
		$tokens = ! empty( $home['allowed_tokens'] ) && is_array( $home['palette'] ?? null ) && $home['palette'] !== array();
		$system = Prompt_Builder::system_live_restyle( $tokens );
		$user   = Prompt_Builder::user_live_restyle( $page, $home );
		$out    = $engine->generate( $system, $user, array( 'max_tokens' => 12288 ) );

		$accepted = ! is_wp_error( $out )
			? $this->accept_restyle( $out, $title, $html, $home, $tokens )
			: $out;

		/*
		 * One compact retry when the full prompt failed to generate, failed
		 * validation, or invented too many colours. pass1() stays a last resort
		 * in Site_From_Menu — not here.
		 */
		if ( is_wp_error( $accepted ) ) {
			Logger::store(
				array(
					Logger::entry(
						'warning',
						sprintf(
							'Restyle “%s” retrying compact (%s).',
							$title,
							$accepted->get_error_message()
						)
					),
				)
			);
			$out      = $engine->generate(
				$system,
				Prompt_Builder::user_live_restyle_compact( $page, $home ),
				array( 'max_tokens' => 12288 )
			);
			$accepted = is_wp_error( $out )
				? $out
				: $this->accept_restyle( $out, $title, $html, $home, $tokens );
		}
		if ( is_wp_error( $accepted ) ) {
			return $accepted;
		}

		Logger::store(
			array(
				Logger::entry(
					'info',
					sprintf(
						'Restyled “%s” (%s) with %s.',
						$title,
						$archetype,
						$engine->get_id()
					)
				),
			)
		);

		return array(
			'markup'    => self::publishable( $accepted ),
			'engine'    => $engine->get_id(),
			'restyled'  => true,
			'archetype' => $archetype,
		);
	}

	/**
	 * Pull gutenberg_markup from an engine answer and enforce brand/validity.
	 *
	 * @param array<string, mixed>                                                                 $out
	 * @param array{allowed_colors?:array<int, string>, palette?:array<string, mixed>} $home
	 * @return string|\WP_Error Validated markup, or error (caller may compact-retry).
	 */
	private function accept_restyle( array $out, string $title, string $html, array $home, bool $tokens ): string|\WP_Error {
		$markup = trim( (string) ( $out['gutenberg_markup'] ?? '' ) );
		if ( $markup === '' && is_array( $out['structures'] ?? null ) ) {
			$parts = array();
			foreach ( $out['structures'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$type = (string) ( $row['type'] ?? 'section' );
				if ( in_array( $type, array( 'header', 'footer', 'navigation' ), true ) ) {
					continue;
				}
				$chunk = trim( (string) ( $row['gutenberg_markup'] ?? '' ) );
				if ( $chunk !== '' ) {
					$parts[] = $chunk;
				}
			}
			$markup = implode( "\n\n", $parts );
		}
		if ( $markup === '' ) {
			return new \WP_Error( 'dxai_ui_restyle_empty', __( 'Restyle returned empty markup.', 'dxai-ui' ) );
		}

		// Never keep LLM-invented CSS — Home sheet already owns the design.
		if ( ! empty( $out['custom_css'] ) && is_string( $out['custom_css'] ) && trim( $out['custom_css'] ) !== '' ) {
			Logger::store(
				array(
					Logger::entry( 'warning', sprintf( 'Dropped invented custom_css on restyle “%s”.', $title ) ),
				)
			);
		}

		$invent = self::invented_color_pressure( $markup, $home, $tokens );
		if ( $invent > 3 ) {
			return new \WP_Error(
				'dxai_ui_restyle_invent',
				sprintf(
					/* translators: %d: count of invented colour values */
					__( 'Restyle invented %d colours outside the Home palette.', 'dxai-ui' ),
					$invent
				)
			);
		}

		$check = Fidelity_Validator::structure(
			array(
				'title'            => $title,
				'type'             => 'section',
				'gutenberg_markup' => $markup,
			),
			$html
		);
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$mapper = new Gutenberg_Mapper();
		$valid  = $mapper->validate( $markup, false );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$markup = $valid;

		/*
		 * Enforce the contract whatever the model did. In token mode every
		 * exact palette colour becomes its token and every invented one snaps to
		 * the nearest brand token — in style contexts only, so an anchor such as
		 * `href="#facade"` is no longer mistaken for a colour. Without a palette
		 * (a home page imported before tokenising) the old hex clamp still runs.
		 */
		$allowed = is_array( $home['allowed_colors'] ?? null ) ? $home['allowed_colors'] : array();
		$markup  = $tokens
			? \DXAI_UI\Compiler\Token_Styles::snap_markup( $markup, $home['palette'] )
			: self::clamp_colors( $markup, $allowed );

		// Body shell only — page-level .dxai-ui--{id} must wrap header+body+footer.
		$markup = preg_replace( '/\bdxai-ui--\d+\b/', '', $markup ) ?? $markup;
		$markup = preg_replace_callback( '/\bclassName":"([^"]*)"/', static function ( array $m ): string {
			$class = trim( preg_replace( '/\bdxai-ui\b/', '', $m[1] ) ?? $m[1] );
			$class = trim( preg_replace( '/\s+/', ' ', $class ) ?? $class );

			return 'className":"' . $class . '"';
		}, $markup ) ?? $markup;

		if ( ! str_contains( $markup, 'dxai-live-content' ) ) {
			$markup = "<!-- wp:group {\"tagName\":\"main\",\"className\":\"dxai-live-content\",\"layout\":{\"type\":\"default\"}} -->\n"
				. "<main class=\"wp-block-group dxai-live-content\">\n"
				. $markup
				. "\n</main>\n<!-- /wp:group -->";
		}

		return $markup;
	}

	/**
	 * How many hex/rgb colours in markup are outside the Home allow-list.
	 *
	 * @param array{allowed_colors?:array<int, string>, allowed_tokens?:array<int, array<string, string>>, palette?:array<string, mixed>} $home
	 */
	public static function invented_color_pressure( string $markup, array $home, bool $tokens ): int {
		$allowed = array();
		foreach ( is_array( $home['allowed_colors'] ?? null ) ? $home['allowed_colors'] : array() as $hex ) {
			$n = strtoupper( ltrim( (string) $hex, '#' ) );
			if ( $n !== '' ) {
				$allowed[ $n ] = true;
			}
		}
		// Always permit neutrals the prompt allows.
		foreach ( array( 'FFFFFF', 'FFF', '000000', '000', '111111', '111' ) as $n ) {
			$allowed[ $n ] = true;
		}
		if ( $tokens ) {
			foreach ( is_array( $home['allowed_tokens'] ?? null ) ? $home['allowed_tokens'] : array() as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$val = (string) ( $row['value'] ?? '' );
				if ( preg_match( '/#([0-9a-fA-F]{3,8})\b/', $val, $m ) ) {
					$allowed[ strtoupper( $m[1] ) ] = true;
				}
			}
		}

		$invented = 0;
		if ( preg_match_all( '/#([0-9a-fA-F]{3,8})\b/', $markup, $matches ) ) {
			foreach ( $matches[1] as $hex ) {
				$hex = strtoupper( $hex );
				if ( ! isset( $allowed[ $hex ] ) ) {
					++$invented;
				}
			}
		}
		// Token mode: any raw rgb()/rgba() is invented (prompt forbids them).
		if ( $tokens && preg_match_all( '/\brgba?\(/i', $markup, $rm ) ) {
			$invented += count( $rm[0] );
		}

		return $invented;
	}

	/**
	 * Pass-1 fallback when LLM is unavailable or fails.
	 */
	public function pass1( string $html, array $palette = array() ): string {
		$html = Live_Content_Shell::prepare_html( $html );
		if ( $html === '' ) {
			return '';
		}
		$shell  = '<main class="dxai-live-content">' . $html . '</main>';
		$markup = ( new Html_To_Blocks() )->convert( $shell );
		/*
		 * The live page's own colours are the OLD site's, not the redesign's —
		 * this path runs with no model to translate them. Snapped to the
		 * design's palette they become its tokens, so the page is on-brand and
		 * follows Styles like every other page of the design.
		 */
		if ( is_array( $palette['colors'] ?? null ) && $palette['colors'] !== array() ) {
			$markup = \DXAI_UI\Compiler\Token_Styles::snap_markup( $markup, $palette );
		}

		return self::publishable( $markup );
	}

	/**
	 * What leaves this class: sanitize(), then Block_Normalizer::normalize().
	 *
	 * sanitize() makes the markup safe; it does not make it open. The model
	 * writes block shapes whose save() does not reproduce the stored HTML —
	 * section comments inside a group, a core/image or core/column carrying
	 * `dxaiStyle`, a dynamic form stored with markup — and on the development
	 * site WordPress 7.1's own validator found 15 blocks on 6 of the 15
	 * crawled pages that open as "unexpected or invalid content", where the
	 * editor's recovery would have rebuilt them from their attributes and
	 * dropped their styling. The normalizer moves
	 * each onto the attributes the block's save() writes, or leaves it alone
	 * where the page would look different.
	 *
	 * The order is the point. Normalising the SANITISED markup means it sees
	 * the tree that is stored — after KSES and after allow_known_blocks() has
	 * removed foreign blocks — and it adds nothing KSES has not already
	 * passed: it removes comments and markup, and moves values that are in
	 * the document already (a declaration from a style="" into a block
	 * attribute and back out as its longhand, a data-* value into
	 * `dxaiData`), so its output needs no second filter. Markup it finds
	 * nothing to fix in is returned as the same string.
	 */
	private static function publishable( string $markup ): string {
		return Block_Normalizer::normalize( self::sanitize( $markup ) );
	}

	/**
	 * Crawled or model-written markup, made safe to publish.
	 *
	 * Both ways out of this class carry content nobody vetted. restyle()
	 * returns what the model wrote, and the model was prompted with the live
	 * page's own HTML — so a compromised or hostile site can steer it into
	 * emitting `<script>` or an `onerror=` through prompt injection. pass1()
	 * converts that same live HTML with no model at all, and Html_To_Blocks
	 * keeps whatever it cannot model as a verbatim island, script included.
	 *
	 * Neither was filtered. The importer runs as an administrator, and an
	 * administrator has `unfiltered_html`, so wp_insert_post() applies no KSES
	 * of its own on the way in: this was stored XSS on the WordPress site,
	 * reachable by anyone able to influence a page the design's menu links to.
	 *
	 * wp_kses_post() is block-aware — its `pre_kses` hook runs
	 * wp_pre_kses_block_attributes(), which filters every block comment's
	 * attributes as well as the markup — and it keeps `data-*`, so
	 * Motion_Runtime's markers survive. What it drops is exactly what should
	 * never have reached a published page: scripts, event handlers,
	 * `javascript:` URLs, and CSS properties core does not consider safe.
	 *
	 * KSES cannot vet the block NAME, though, and that is the second half:
	 * see allow_known_blocks().
	 */
	public static function sanitize( string $markup ): string {
		if ( $markup === '' ) {
			return '';
		}

		return self::allow_known_blocks( wp_kses_post( $markup ) );
	}

	/**
	 * Drop every block that is neither core/* nor dxai-ui/*.
	 *
	 * wp_kses_post() judges markup and attribute VALUES; it has no opinion on
	 * which block type they belong to. A block name is an instruction to run
	 * whatever render_callback the site has registered under it, with the
	 * comment's attributes as arguments — `<!-- wp:someplugin/widget
	 * {"id":123} /-->` passes KSES untouched (a void comment, an integer
	 * attribute) and then asks another plugin's PHP to render form 123, post
	 * 123, a remote URL, a shortcode. Nothing this class writes needs one:
	 * the only blocks Prompt_Builder::system_live_restyle() names are
	 * core/buttons and dxai-ui/form, and every block-name literal in
	 * Html_To_Blocks is core/* or dxai-ui/* (15 names, checked by grep). So
	 * a foreign name here is a hallucination or a prompt injection carried in
	 * from the crawled page, and it is removed rather than trusted.
	 *
	 * REMOVED, not converted to its inner HTML. Converting was considered
	 * and rejected for two reasons:
	 *   - inside a parent (and every restyled body is inside the
	 *     `dxai-live-content` group restyle() wraps it in) the freed HTML
	 *     lands in the parent's innerContent, so the parent's saved HTML no
	 *     longer matches its save() and the editor shows "This block contains
	 *     unexpected or invalid content" on the whole section — the opposite
	 *     of the editable-blocks goal. At the top level it would become a
	 *     Classic block instead;
	 *   - a dynamic block's innerHTML is empty or a placeholder, because its
	 *     real output IS the render_callback we are refusing to run. For the
	 *     static ones it is markup written for another plugin's CSS and JS,
	 *     which the design does not load.
	 * What a foreign block WRAPS is kept: its core/dxai-ui descendants are
	 * filtered the same way and take its place, one innerContent placeholder
	 * each, so a hallucinated container (a "row" or "section" block from a
	 * page builder) costs its own shell and attributes, not the paragraphs
	 * Fidelity_Validator::structure() has just counted. Freeform HTML between
	 * blocks (blockName null) is not a block type and stays; KSES has already
	 * vetted it as the raw HTML it is.
	 *
	 * Clean markup is returned as the very string it came in as, and
	 * serialize_blocks() only runs when something was actually removed. Note
	 * that "came in" means after KSES: wp_kses_post() already re-serialises
	 * every block through filter_block_content(), which is where each `-` of
	 * a clean page's `var(--x)` attribute becomes a JSON unicode escape
	 * (backslash, u002d) — valid JSON for the same value, and
	 * Site_From_Menu::save_chrome_page() wp_slash()es it, so the backslashes
	 * survive wp_insert_post(). Nothing is logged: Logger::store()
	 * replaces the transient rather than appending to it, so a warning written
	 * here would overwrite the "Restyled …" entry restyle() has just stored.
	 */
	private static function allow_known_blocks( string $markup ): string {
		if ( $markup === '' || ! str_contains( $markup, '<!-- wp:' ) ) {
			return $markup;
		}

		$removed = 0;
		$kept    = array();
		foreach ( parse_blocks( $markup ) as $block ) {
			foreach ( self::filter_block( $block, $removed ) as $survivor ) {
				$kept[] = $survivor;
			}
		}

		return $removed === 0 ? $markup : serialize_blocks( $kept );
	}

	/**
	 * One parsed block, filtered: itself (with filtered children) when its
	 * namespace is allowed, else its allowed descendants in its place.
	 *
	 * @param array<string, mixed> $block A parse_blocks() entry.
	 * @param int                  $removed Incremented once per dropped block.
	 * @return array<int, array<string, mixed>>
	 */
	private static function filter_block( array $block, int &$removed ): array {
		$inner   = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
		$content = is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array();

		// innerContent holds one null per inner block, in order; walking it is
		// what keeps each survivor at its original position in the markup.
		$kept_blocks  = array();
		$kept_content = array();
		$index        = 0;
		foreach ( $content as $chunk ) {
			if ( $chunk !== null ) {
				$kept_content[] = $chunk;
				continue;
			}
			$child = $inner[ $index ] ?? null;
			++$index;
			if ( ! is_array( $child ) ) {
				continue;
			}
			foreach ( self::filter_block( $child, $removed ) as $survivor ) {
				$kept_blocks[]  = $survivor;
				$kept_content[] = null;
			}
		}

		$name = $block['blockName'] ?? null;
		if ( $name === null
			|| str_starts_with( (string) $name, 'core/' )
			|| str_starts_with( (string) $name, 'dxai-ui/' )
		) {
			$block['innerBlocks']  = $kept_blocks;
			$block['innerContent'] = $kept_content;

			return array( $block );
		}

		++$removed;

		return $kept_blocks;
	}

	/**
	 * Compact Home style guide from compiled structures / CSS.
	 *
	 * @param array<int, array<string, mixed>> $structures
	 * @return array{home_html:string, design_css:string, home_title:string, class_sample:string, allowed_colors:array<int, string>, style_recipes:array<int, string>}
	 */
	public static function home_guide( array $structures, string $design_css = '', string $home_title = '', array $palette = array() ): array {
		$chunks  = array();
		$classes = array();
		$styles  = array();
		$blob    = $design_css;
		/*
		 * With the design's palette, everything the model is shown is shown in
		 * TOKENS: `background:var(--dxai-accent)` where it used to read
		 * `background:#F2A34A`. It copies what it sees, so this is the cheapest
		 * and most reliable way to get tokens back out — the prompt rule and the
		 * snap in restyle() are there for the cases where it does not.
		 */
		$tokenise = is_array( $palette['colors'] ?? null ) && $palette['colors'] !== array();

		foreach ( $structures as $structure ) {
			if ( ! is_array( $structure ) ) {
				continue;
			}
			$type = (string) ( $structure['type'] ?? '' );
			if ( in_array( $type, array( 'header', 'footer', 'navigation' ), true ) ) {
				continue;
			}
			$src = self::clean_home_html( (string) ( $structure['source_html'] ?? '' ) );
			if ( $src === '' ) {
				continue;
			}
			if ( $tokenise ) {
				$src = \DXAI_UI\Compiler\Token_Styles::rewrite_html( $src, $palette );
			}
			$blob    .= "\n" . $src;
			$chunks[] = $src;

			if ( preg_match_all( '/\bclass(?:Name)?=["\']([^"\']{1,240})["\']/', $src, $m ) ) {
				foreach ( $m[1] as $cl ) {
					foreach ( preg_split( '/\s+/', $cl ) ?: array() as $token ) {
						$token = trim( (string) $token );
						if ( $token === '' || str_starts_with( $token, 'wp-block' ) ) {
							continue;
						}
						// Prefer design tokens over Gutenberg chrome.
						if ( preg_match( '/^(dxai-|sc-|section-|btn|container|hero|cta)/i', $token )
							|| str_contains( $token, 'dxai-sh-' )
						) {
							$classes[ $token ] = ( $classes[ $token ] ?? 0 ) + 10;
						} else {
							$classes[ $token ] = ( $classes[ $token ] ?? 0 ) + 1;
						}
					}
				}
			}

			if ( preg_match_all( '/\bstyle=["\']([^"\']{20,500})["\']/', $src, $sm ) ) {
				foreach ( $sm[1] as $style ) {
					$decoded = html_entity_decode( $style, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					$decoded = preg_replace( '/url\([^)]+\)/i', 'url(IMAGE)', $decoded ) ?? $decoded;
					$role    = self::recipe_role( $decoded, $src );
					// Keep the densest sample per role (longest useful declaration set wins).
					$prev = $styles[ $role ] ?? '';
					if ( $prev === '' || substr_count( $decoded, ';' ) > substr_count( $prev, ';' ) ) {
						$styles[ $role ] = $decoded;
					}
				}
			}

			if ( strlen( implode( "\n", $chunks ) ) > 10000 ) {
				break;
			}
		}

		arsort( $classes );
		/*
		 * Prefer whole section chunks over mid-chunk truncation so style_recipes
		 * and home_html stay coherent samples the model can copy.
		 */
		$home_html = '';
		foreach ( $chunks as $i => $chunk ) {
			$piece = ( $i === 0 ? '' : "\n\n<!-- section -->\n\n" ) . $chunk;
			if ( $home_html !== '' && strlen( $home_html ) + strlen( $piece ) > 12000 ) {
				break;
			}
			$home_html .= $piece;
		}

		$colors = self::extract_colors( $blob );
		$css_hint = Design_Css::tokens_only( $design_css, 4000 );
		if ( $css_hint === '' && $design_css !== '' ) {
			// Design.com sheets often lack :root — keep a short color-bearing slice instead.
			$css_hint = self::color_css_slice( $design_css, 3500 );
		}

		/*
		 * The colours the model may use, as the tokens it must write:
		 * `{"token":"var(--dxai-accent)","name":"Accent","value":"#F2A34A"}`.
		 * The value is there so it can reason about contrast; the token is the
		 * only thing it may put in markup. Solid colours only — the same set
		 * that are presets in Styles — plus the design's shadows.
		 */
		$tokens = array();
		if ( $tokenise ) {
			foreach ( $palette['colors'] as $row ) {
				if ( ! empty( $row['preset'] ) ) {
					$tokens[] = array(
						'token' => 'var(--dxai-' . $row['slug'] . ')',
						'name'  => (string) $row['name'],
						'value' => (string) $row['value'],
					);
				}
			}
			foreach ( (array) ( $palette['shadows'] ?? array() ) as $row ) {
				$tokens[] = array(
					'token' => 'var(--dxai-' . $row['slug'] . ')',
					'name'  => (string) $row['name'] . ' (box-shadow)',
					'value' => (string) $row['value'],
				);
			}
		}

		// Role-keyed recipes: button / section / card / heading / other — denser than 24 random styles.
		$recipes = array();
		foreach ( array( 'button', 'heading', 'section', 'card', 'other' ) as $role ) {
			if ( ! empty( $styles[ $role ] ) ) {
				$recipes[] = $styles[ $role ];
			}
		}

		return array(
			'home_html'       => $home_html,
			'design_css'      => $css_hint,
			'home_title'      => $home_title,
			'class_sample'    => implode( ' ', array_slice( array_keys( $classes ), 0, 48 ) ),
			'allowed_colors'  => $colors,
			'allowed_tokens'  => $tokens,
			'palette'         => $tokenise ? $palette : array(),
			'style_recipes'   => $recipes,
		);
	}

	/**
	 * Classify an inline style sample so home_guide keeps one dense recipe per UI role.
	 */
	private static function recipe_role( string $style, string $chunk_context ): string {
		$hay = strtolower( $style . ' ' . substr( $chunk_context, 0, 400 ) );
		if ( preg_match( '/\b(button|btn|cta|bg-primary|background:[^;]*(accent|primary))/', $hay ) ) {
			return 'button';
		}
		if ( preg_match( '/\b(font-size:\s*(2|3|4|clamp)|h1|h2|heading|hero-title)/', $hay ) ) {
			return 'heading';
		}
		if ( preg_match( '/\b(padding:\s*[3-9]|section|band|full-bleed|min-height)/', $hay ) ) {
			return 'section';
		}
		if ( preg_match( '/\b(card|shadow|border-radius|rounded)/', $hay ) ) {
			return 'card';
		}

		return 'other';
	}

	/**
	 * Strip WP chrome / scripts so the LLM sees clean design HTML.
	 */
	public static function clean_home_html( string $html ): string {
		$html = trim( $html );
		if ( $html === '' ) {
			return '';
		}
		$html = preg_replace( '/<!--\s*\/?wp:template-part[^>]*-->/i', '', $html ) ?? $html;
		$html = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $html ) ?? $html;
		$html = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', '', $html ) ?? $html;
		$html = preg_replace( '/\sclass="[^"]*\b(sc-interp|hidden)\b[^"]*"/i', '', $html ) ?? $html;
		$html = preg_replace( '/\s{2,}/', ' ', $html ) ?? $html;

		return trim( $html );
	}

	/**
	 * @return array<int, string>
	 */
	public static function extract_colors( string $blob ): array {
		$found = array(
			'#FFFFFF' => true,
			'#000000' => true,
			'#111111' => true,
		);
		if ( preg_match_all( '/#([0-9a-fA-F]{3,8})\b/', $blob, $m ) ) {
			foreach ( $m[1] as $hex ) {
				$hex = strtoupper( $hex );
				if ( strlen( $hex ) === 3 ) {
					$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
				}
				if ( strlen( $hex ) === 6 || strlen( $hex ) === 8 ) {
					$found[ '#' . substr( $hex, 0, 6 ) ] = true;
				}
			}
		}
		if ( preg_match_all( '/rgba?\(\s*[\d.]+(?:\s*,\s*[\d.]+){2,3}\s*\)/i', $blob, $rm ) ) {
			foreach ( $rm[0] as $rgba ) {
				$found[ preg_replace( '/\s+/', '', strtolower( $rgba ) ) ?? $rgba ] = true;
				if ( count( $found ) >= 64 ) {
					break;
				}
			}
		}

		return array_keys( $found );
	}

	/**
	 * Short CSS excerpt containing color declarations (for Design.com sheets without :root).
	 */
	public static function color_css_slice( string $css, int $max = 3500 ): string {
		$lines = array();
		if ( preg_match_all( '/[^{}]+\{[^}]*#[0-9a-fA-F]{3,8}[^}]*\}/', $css, $m ) ) {
			foreach ( $m[0] as $rule ) {
				$lines[] = trim( $rule );
				if ( strlen( implode( "\n", $lines ) ) >= $max ) {
					break;
				}
			}
		}
		$out = implode( "\n", $lines );

		return strlen( $out ) > $max ? substr( $out, 0, $max ) : $out;
	}

	/**
	 * Replace invented hex colors with the nearest allowed Home color.
	 *
	 * @param array<int, string> $allowed
	 */
	public static function clamp_colors( string $markup, array $allowed ): string {
		$hexes = array();
		foreach ( $allowed as $c ) {
			if ( is_string( $c ) && preg_match( '/^#([0-9A-Fa-f]{6})$/', $c, $m ) ) {
				$hexes[] = strtoupper( $m[1] );
			}
		}
		if ( $hexes === array() ) {
			return $markup;
		}

		return (string) preg_replace_callback(
			'/#([0-9A-Fa-f]{6})\b/',
			static function ( array $m ) use ( $hexes ): string {
				$hex = strtoupper( $m[1] );
				if ( in_array( $hex, $hexes, true ) ) {
					return '#' . $hex;
				}
				// Near-white / near-black stay.
				if ( in_array( $hex, array( 'FFFFFF', 'FAFAFA', 'F8FAFC', 'F5F5F5', 'EEEEEE', '000000', '111111', '111827' ), true ) ) {
					return $hex === '111827' ? '#111111' : ( str_starts_with( $hex, 'F' ) || $hex === 'EEEEEE' ? '#FFFFFF' : '#' . $hex );
				}

				return '#' . self::nearest_hex( $hex, $hexes );
			},
			$markup
		);
	}

	/**
	 * @param array<int, string> $palette 6-char hex without #
	 */
	private static function nearest_hex( string $hex, array $palette ): string {
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		$best = $palette[0];
		$best_d = PHP_INT_MAX;
		foreach ( $palette as $p ) {
			$pr = hexdec( substr( $p, 0, 2 ) );
			$pg = hexdec( substr( $p, 2, 2 ) );
			$pb = hexdec( substr( $p, 4, 2 ) );
			$d  = ( $r - $pr ) ** 2 + ( $g - $pg ) ** 2 + ( $b - $pb ) ** 2;
			if ( $d < $best_d ) {
				$best_d = $d;
				$best   = $p;
			}
		}

		return $best;
	}
}

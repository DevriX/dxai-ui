<?php
/**
 * Verbatim DXAI-UI system prompt + user payload.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Prompt_Builder {

	public static function system(): string {
		$base = <<<'PROMPT'
You are DXAI-UI Engine, an advanced Frontend-to-Gutenberg Compiler. 
Your job is to translate raw Figma JSON / Lovable React+Tailwind code into clean, valid WordPress Gutenberg Block markup.

STRICT REQUIREMENTS:
1. Output valid WordPress Block Comment Syntax (e.g., <!-- wp:group -->, <!-- wp:columns -->, <!-- wp:heading -->).
2. Retain ALL Tailwind CSS utility classes in the class="" attribute.
3. Ensure exact alignment, spacing, padding, and mobile responsiveness as defined in the source design.
4. Return ONLY a valid JSON object (no markdown surrounding ticks) with the following key structure:
   {
     "block_title": "Descriptive Name",
     "gutenberg_markup": "<!-- wp:group ... -->...<!-- /wp:group -->",
     "custom_css": ".extracted-styles { ... }",
     "required_media": ["url1", "url2"]
   }
PROMPT;

		$structures = <<<'PROMPT'

5. Also include "custom_js" (string, may be empty) and "structures" (array). Split the design into WordPress structures. Never dump a whole site as one static HTML page.
6. Structure types (use only these): header, navigation, hero, blog, form, slider, footer, section.
7. Pixel-perfect Tailwind classes stay on every wrapper. Dynamic data must use WordPress blocks, not hardcoded cards:
   - header: core/group or core/template-part markup with core/site-logo, core/site-title, core/navigation. Fill menu_items from the design labels.
   - navigation / footer menus: core/navigation + menu_items [{label, url}]. Footer type is footer.
   - hero: core/cover (or group) with core/site-title or core/post-title when the title is the site/page title. Buttons stay core/buttons.
   - blog / news / cards of articles: ALWAYS core/query + core/post-template + core/post-title + core/post-featured-image + core/post-excerpt + core/post-date + core/query-pagination. Never fake posts with static headings.
   - form / contact / newsletter: use <!-- wp:dxai-ui/form {"fields":[...]} /--> and duplicate fields into form_fields. Do not output raw <form> HTML.
   - slider / carousel: wrap slides in <!-- wp:dxai-ui/slider --> with inner core/cover or core/image slides so they remain editable in Gutenberg.
   - other bands: type section using group/columns/media-text.
8. gutenberg_markup is the page BODY only (hero + sections + blog + forms + sliders). Do not repeat header/footer there; those live in structures[].
9. menu_items and form_fields must always be arrays (empty if not applicable).
10. Pixel-perfect assets: use harvested WordPress URLs from harvest.images / harvest.videos (core/image, core/cover, core/video, core/embed). Keep harvest.links as real hrefs on buttons/navigation. Fonts from harvest.fonts belong in custom_css via @font-face or the provided stylesheet URLs — do not substitute system fonts when a harvested family exists. Reuse harvest.tokens (colors, shadows, CSS variables) instead of approximating. Never invent stock photos when a harvested asset exists.
11. Prefer section shells + core/html for flex/grid/absolute chrome. Do NOT deep-nest core/group with is-layout-flow when the design owns layout via Tailwind or inline display:flex|grid.
12. When refining, treat source_html as the golden pixel reference. Improve Gutenberg fidelity; do not reinvent layout or drop Tailwind class strings.
PROMPT;

		return $base . $structures;
	}

	/**
	 * System prompt for Pass-2 structure refine (same verbatim base + refine focus).
	 */
	public static function system_refine(): string {
		return self::system() . "\n\n"
			. "REFINE MODE: You receive golden source_html plus Pass-1 Gutenberg for ONE structure.\n"
			. "Fix fidelity gaps listed in issues[]. Keep structures separated. Prefer shell + html chrome over deep group trees.\n"
			. "Return JSON with block_title, gutenberg_markup, structures (one item), custom_css (usually empty), custom_js (usually empty), required_media.\n";
	}

	/**
	 * @param array<string, mixed> $structure
	 * @param array<int, string>   $issues
	 */
	public static function user_refine_structure( array $structure, array $issues, string $mode = 'auto' ): string {
		$type   = (string) ( $structure['type'] ?? 'section' );
		$title  = (string) ( $structure['title'] ?? $type );
		$markup = (string) ( $structure['gutenberg_markup'] ?? '' );
		$source = (string) ( $structure['source_html'] ?? '' );
		if ( strlen( $source ) > 24000 ) {
			$source = substr( $source, 0, 24000 ) . "\n<!-- truncated -->";
		}
		if ( strlen( $markup ) > 20000 ) {
			$markup = substr( $markup, 0, 20000 ) . "\n<!-- truncated -->";
		}

		$payload = array(
			'mode'             => Fidelity_Gate::normalize_mode( $mode ),
			'type'             => $type,
			'title'            => $title,
			'issues'           => array_values( $issues ),
			'source_html'      => $source,
			'pass1_markup'     => $markup,
			'menu_items'       => $structure['menu_items'] ?? array(),
			'form_fields'      => $structure['form_fields'] ?? array(),
		);
		$blob = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$blob = is_string( $blob ) ? $blob : '{}';

		return "Refine ONE structure to match source_html pixel fidelity.\n"
			. "Keep every Tailwind / design class string. Prefer core/html for flex/grid chrome.\n"
			. "Do not output a whole-page single wp:html blob. Escape newlines as \\n.\n\n"
			. "Refine payload JSON:\n{$blob}\n\n" . self::rules();
	}

	/**
	 * @param array<string, mixed> $structure
	 * @param array<int, string>   $issues
	 */
	public static function user_refine_structure_compact( array $structure, array $issues ): string {
		$type   = (string) ( $structure['type'] ?? 'section' );
		$title  = (string) ( $structure['title'] ?? $type );
		$markup = (string) ( $structure['gutenberg_markup'] ?? '' );
		$source = (string) ( $structure['source_html'] ?? '' );
		$source = strlen( $source ) > 12000 ? substr( $source, 0, 12000 ) : $source;
		$markup = strlen( $markup ) > 10000 ? substr( $markup, 0, 10000 ) : $markup;
		$issue  = implode( ', ', $issues );

		return "Fix Gutenberg for structure type={$type} title={$title}. Issues: {$issue}.\n"
			. "Keep Tailwind classes. Prefer html for flex/grid. Return JSON only.\n\n"
			. "SOURCE_HTML:\n{$source}\n\nPASS1:\n{$markup}\n";
	}

	/**
	 * @param array<string, mixed> $source
	 */
	public static function user( array $source, string $notes = '' ): string {
		$blob  = wp_json_encode( $source, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$blob  = is_string( $blob ) ? $blob : '{}';
		$notes = sanitize_textarea_field( $notes );

		$extra = $notes !== '' ? "Designer notes:\n{$notes}\n\n" : '';

		return $extra . "Source document JSON:\n" . $blob . "\n\n" . self::rules();
	}

	/**
	 * Compact prompt for one ZIP/page chunk (avoids overflowing the model JSON).
	 *
	 * @param array<string, mixed> $source
	 * @param array<string, mixed> $chunk
	 */
	public static function user_chunk( array $source, array $chunk, string $notes, int $index, int $total ): string {
		$harvest = is_array( $source['payload']['harvest'] ?? null ) ? $source['payload']['harvest'] : array();
		$css     = (string) ( $source['payload']['design_css'] ?? $harvest['css'] ?? '' );
		$payload = array(
			'kind'    => $source['kind'] ?? 'lovable-zip',
			'title'   => $source['title'] ?? '',
			'page'    => $chunk['page'] ?? array(
				'slug'  => '/',
				'title' => 'Home',
			),
			'chunk'   => array(
				'index'     => $index,
				'of'        => $total,
				'id'        => $chunk['id'] ?? '',
				'title'     => $chunk['title'] ?? '',
				'type_hint' => $chunk['type'] ?? 'section',
				'code'      => (string) ( $chunk['code'] ?? '' ),
			),
			'harvest' => array(
				'images' => $harvest['images'] ?? array(),
				'videos' => $harvest['videos'] ?? array(),
				'fonts'  => $harvest['fonts'] ?? array(),
				'links'  => $harvest['links'] ?? array(),
				'tokens' => $harvest['tokens'] ?? array(),
				'css'    => Design_Css::tokens_only( $css ),
			),
		);

		$blob  = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$blob  = is_string( $blob ) ? $blob : '{}';
		$notes = sanitize_textarea_field( $notes );
		$extra = $notes !== '' ? "Designer notes:\n{$notes}\n\n" : '';

		return $extra
			. 'This is chunk ' . ( $index + 1 ) . " of {$total}. Convert ONLY this chunk to Gutenberg.\n"
			. "Keep every rv-* and Tailwind class name exactly. Do not rewrite the design stylesheet; leave custom_css empty unless a few extra rules are required.\n"
			. "Replace CountUp with <span class=\"rv-num\" data-countup=\"VALUE\" data-suffix=\"SUFFIX\">VALUE</span>. Keep useReveal targets as sections that can receive class is-in.\n"
			. "Escape every newline inside JSON strings as \\n. Return one JSON object only.\n\n"
			. "Source document JSON:\n" . $blob . "\n\n" . self::rules();
	}

	/**
	 * @param array<string, mixed> $chunk
	 */
	public static function user_chunk_compact( array $chunk, int $index, int $total ): string {
		$code = (string) ( $chunk['code'] ?? '' );
		$type = (string) ( $chunk['type'] ?? 'section' );
		$name = (string) ( $chunk['title'] ?? 'Section' );

		return "Convert this React/TSX chunk (" . ( $index + 1 ) . " of {$total}) into Gutenberg JSON only.\n"
			. "structure type_hint: {$type}. title: {$name}.\n"
			. "Keep rv-* and Tailwind classes. gutenberg_markup must contain <!-- wp: blocks. Escape newlines as \\n.\n"
			. "JSON keys: block_title, gutenberg_markup, structures, custom_css, custom_js, required_media.\n\n"
			. $code;
	}

	/**
	 * System prompt for restyling a crawled live page to match Home design language.
	 * Standalone — do not reuse the Tailwind compile system prompt (it causes invented utilities/colors).
	 */
	/**
	 * The live restyler's system prompt.
	 *
	 * With $tokens, the brand rules require colours as the design's tokens —
	 * `var(--dxai-accent)` — and forbid writing any hex or rgb() value at all.
	 * The hex version told the model to "copy inline style patterns" and to
	 * keep "every color hex" inside an allowed list, which is exactly how a
	 * restyled page ended up with 369 invented declarations and zero token
	 * uses: nothing on it could follow a colour change made in Styles.
	 */
	public static function system_live_restyle( bool $tokens = false ): string {
		if ( $tokens ) {
			return self::token_restyle_rules( self::system_live_restyle( false ) );
		}

		return <<<'PROMPT'
You are DXAI-UI Live Restyler — a senior product designer + Gutenberg engineer.
You rebuild ONE WordPress page BODY so it feels like a native continuation of the Home page: same brand chrome, higher UI/UX craft.

BRAND LOCK (non-negotiable):
1. Output valid Gutenberg block comment syntax only in gutenberg_markup.
2. REUSE Home visual language from home_sections / style_recipes / allowed_colors / class_sample. Copy inline style patterns and Home classes (dxai-sh-*, section shells, button recipes) — never invent a new brand.
3. FORBIDDEN: inventing colors, gradients, Tailwind color utilities (bg-slate-*, text-gray-*, bg-sky-*, purple/indigo/teal themes), new CSS variables, or custom_css. custom_css and custom_js MUST be empty strings.
4. Every color hex / rgba in your markup MUST appear in allowed_colors (or be white/black/transparent). If unsure, copy a style_recipes entry verbatim and only swap text/images.
5. Scraped content is FACTS only (copy, image URLs, structure signals) — never copy colors, classes, or styles from the live site.

UI/UX CRAFT (within Home language):
6. Follow ux_brief for this page_archetype. One job per section; clear hierarchy: page hero → primary content → supporting media/cards → closing CTA.
7. Mirror Home section rhythm: consistent vertical padding, max-width containers, heading scale, button styles, card/media gaps. Alternate visual density (full-bleed / contained / split) like Home — do not stack identical plain text blocks.
8. Typography: preserve scraped wording; improve scannability (short lead, readable paragraphs, list clusters). Prefer one H1-equivalent hero title, then H2 section titles. No wall-of-text sections.
9. Media: place images from images[] / content_facts intentionally (hero, split media-text, card grids). Prefer real URLs; never invent stock photos. Keep alt text meaningful when available.
10. Interactive: Contact / lead pages MUST use <!-- wp:dxai-ui/form {"fields":[...]} /--> with sensible fields from content_facts.form_signals. CTAs use Home button recipes (core/buttons), not plain links dressed as buttons.
11. Accessibility & polish: logical heading order, sufficient contrast using only allowed_colors, focusable controls via real blocks, no decorative empty groups, no overlapping absolute chrome unless Home already does it.
12. Mobile: stacks must read cleanly — columns collapse to single column; avoid cramped multi-column text; keep touch-friendly CTA spacing using Home patterns.
13. Do NOT output header or footer. Wrap body in group/main with class dxai-live-content as a shell only.
14. Return ONLY JSON (no markdown fences):
   {"block_title":"...","gutenberg_markup":"...","custom_css":"","custom_js":"","required_media":[]}
PROMPT;
	}

	/**
	 * The hex brand rules of the restyle prompt, rewritten for tokens.
	 *
	 * Rules 2, 4 and 11 are the ones that spoke about colour; everything else
	 * in the prompt — layout craft, accessibility, the JSON contract — stands.
	 * Should the base wording ever drift so a rule no longer matches, the token
	 * rules are appended rather than lost, because a prompt that silently kept
	 * "copy every hex" would undo the whole contract without any error.
	 */
	private static function token_restyle_rules( string $base ): string {
		$swaps = array(
			'2. REUSE Home visual language from home_sections / style_recipes / allowed_colors / class_sample. Copy inline style patterns and Home classes (dxai-sh-*, section shells, button recipes) — never invent a new brand.'
				=> '2. REUSE Home visual language from home_sections / style_recipes / allowed_tokens / class_sample. Reuse Home classes (dxai-sh-*, section shells, button recipes) and Home\'s style patterns — never invent a new brand.',
			'4. Every color hex / rgba in your markup MUST appear in allowed_colors (or be white/black/transparent). If unsure, copy a style_recipes entry verbatim and only swap text/images.'
				=> '4. COLOURS ARE TOKENS. Write every colour and every shadow ONLY as a token from allowed_tokens, exactly as given — e.g. background:var(--dxai-accent);color:var(--dxai-on-accent);box-shadow:var(--dxai-shadow-1). NEVER write a hex (#...), rgb(), rgba() or named colour. home_sections and style_recipes already show the tokens in use: copy them as they are. The site owner changes these colours once, in Styles, and every value you write must follow.',
			'sufficient contrast using only allowed_colors'
				=> 'sufficient contrast between the tokens you pair (allowed_tokens gives each token\'s value)',
		);

		$out    = $base;
		$missed = false;
		foreach ( $swaps as $from => $to ) {
			if ( ! str_contains( $out, $from ) ) {
				$missed = true;
				continue;
			}
			$out = str_replace( $from, $to, $out );
		}
		if ( $missed ) {
			$out .= "\n\nCOLOUR RULE (overrides anything above): " . $swaps[ array_keys( $swaps )[1] ];
		}

		return $out;
	}

	/**
	 * @param array{title?:string, path?:string, html?:string, images?:array<int, string>, content_facts?:array<string, mixed>} $page
	 * @param array{home_html?:string, design_css?:string, home_title?:string, class_sample?:string, allowed_colors?:array<int, string>, style_recipes?:array<int, string>} $home
	 */
	public static function user_live_restyle( array $page, array $home ): string {
		$facts = $page['content_facts'] ?? null;
		if ( ! is_array( $facts ) ) {
			$facts = self::content_facts_from_html( (string) ( $page['html'] ?? '' ) );
		}

		$title     = (string) ( $page['title'] ?? 'Page' );
		$path      = (string) ( $page['path'] ?? '' );
		$archetype = self::detect_page_archetype( $title, $path, $facts );
		$ux_brief  = self::ux_brief_for_archetype( $archetype, $facts );

		$home_html = (string) ( $home['home_html'] ?? '' );
		if ( strlen( $home_html ) > 12000 ) {
			$home_html = substr( $home_html, 0, 12000 ) . "\n<!-- truncated -->";
		}

		$payload = array(
			'page_title'      => $title,
			'page_path'       => $path,
			'page_archetype'  => $archetype,
			'ux_brief'        => $ux_brief,
			'content_facts'   => $facts,
			'images'          => array_values( array_slice( is_array( $page['images'] ?? null ) ? $page['images'] : array(), 0, 24 ) ),
			'home_title'      => (string) ( $home['home_title'] ?? '' ),
			'home_sections'   => $home_html,
			'class_sample'    => (string) ( $home['class_sample'] ?? '' ),
			'style_recipes'   => array_values( array_slice( is_array( $home['style_recipes'] ?? null ) ? $home['style_recipes'] : array(), 0, 24 ) ),
			'allowed_colors'  => array_values( array_slice( is_array( $home['allowed_colors'] ?? null ) ? $home['allowed_colors'] : array(), 0, 48 ) ),
			'design_css_hint' => (string) ( $home['design_css'] ?? '' ),
		);
		/*
		 * Token mode: the model gets the design's tokens and NOT a hex list.
		 * Showing it hex values it is forbidden to write only invites it to
		 * write them.
		 */
		$tokens = is_array( $home['allowed_tokens'] ?? null ) ? $home['allowed_tokens'] : array();
		if ( $tokens !== array() ) {
			unset( $payload['allowed_colors'] );
			$payload['allowed_tokens'] = array_values( array_slice( $tokens, 0, 60 ) );
		}
		$blob = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$blob = is_string( $blob ) ? $blob : '{}';

		return "Rebuild this page BODY as a polished UI/UX composition that clones Home's design system — do not invent a brand.\n"
			. "Archetype={$archetype}. Obey ux_brief. "
			. ( $tokens !== array()
				? "Write colours and shadows ONLY as var(--dxai-…) tokens from allowed_tokens — never a hex or rgb() value. Use style_recipes + Home classes.\n"
				: "Use only allowed_colors + style_recipes + Home classes.\n" )
			. "Keep real copy + image URLs from content_facts. No header/footer. custom_css empty.\n"
			. "Escape newlines as \\n. JSON only.\n\n"
			. "Restyle payload JSON:\n{$blob}\n";
	}

	/**
	 * @param array{title?:string, path?:string, html?:string, images?:array<int, string>, content_facts?:array<string, mixed>} $page
	 * @param array{home_html?:string, class_sample?:string, allowed_colors?:array<int, string>, style_recipes?:array<int, string>} $home
	 */
	public static function user_live_restyle_compact( array $page, array $home ): string {
		$facts = $page['content_facts'] ?? null;
		if ( ! is_array( $facts ) ) {
			$facts = self::content_facts_from_html( (string) ( $page['html'] ?? '' ) );
		}
		$title     = (string) ( $page['title'] ?? 'Page' );
		$path      = (string) ( $page['path'] ?? '' );
		$archetype = self::detect_page_archetype( $title, $path, $facts );
		$ux_brief  = self::ux_brief_for_archetype( $archetype, $facts );
		$brief_txt = implode( ' | ', $ux_brief );

		$facts_json = wp_json_encode( $facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$facts_json = is_string( $facts_json ) ? $facts_json : '{}';
		$home_html  = (string) ( $home['home_html'] ?? '' );
		$home_html  = strlen( $home_html ) > 7000 ? substr( $home_html, 0, 7000 ) : $home_html;
		$colors     = implode( ', ', array_slice( is_array( $home['allowed_colors'] ?? null ) ? $home['allowed_colors'] : array(), 0, 24 ) );
		$recipes    = implode( "\n---\n", array_slice( is_array( $home['style_recipes'] ?? null ) ? $home['style_recipes'] : array(), 0, 10 ) );
		$tokens     = is_array( $home['allowed_tokens'] ?? null ) ? $home['allowed_tokens'] : array();
		// Token mode lists `var(--dxai-accent) = Accent (#F2A34A)` instead of
		// bare hex, for the same reason as user_live_restyle().
		$palette_block = $tokens !== array()
			? "ALLOWED TOKENS — write colours and shadows ONLY as these, never as hex or rgb():\n"
				. implode(
					"\n",
					array_map(
						static fn( array $t ): string => (string) ( $t['token'] ?? '' ) . ' = ' . (string) ( $t['name'] ?? '' ) . ' (' . (string) ( $t['value'] ?? '' ) . ')',
						array_slice( $tokens, 0, 40 )
					)
				)
			: "ALLOWED COLORS:\n{$colors}";

		return "Restyle “{$title}” ({$archetype}) as polished UI/UX using Home styles only. Body Gutenberg. No invented colors. JSON only.\n"
			. "UX: {$brief_txt}\n\n"
			. $palette_block . "\n\n"
			. "HOME CLASSES:\n" . (string) ( $home['class_sample'] ?? '' ) . "\n\n"
			. "STYLE RECIPES:\n{$recipes}\n\n"
			. "HOME SECTIONS:\n{$home_html}\n\n"
			. "CONTENT FACTS:\n{$facts_json}\n";
	}

	/**
	 * Infer page archetype from title, path, and content signals.
	 *
	 * @param array<string, mixed> $facts
	 */
	public static function detect_page_archetype( string $title, string $path, array $facts = array() ): string {
		$hay = strtolower( trim( $title . ' ' . $path ) );
		$signals = is_array( $facts['signals'] ?? null ) ? $facts['signals'] : array();

		$rules = array(
			'contact'  => array( 'contact', 'get-in-touch', 'get in touch', 'reach-us', 'support', 'inquiry', 'enquiry' ),
			'about'    => array( 'about', 'our-story', 'our story', 'who-we-are', 'company', 'mission' ),
			'team'     => array( 'team', 'people', 'staff', 'leadership', 'experts', 'meet-' ),
			'services' => array( 'service', 'solution', 'what-we-do', 'offerings', 'capabilities' ),
			'product'  => array( 'product', 'platform', 'features', 'pricing', 'plans' ),
			'blog'     => array( 'blog', 'news', 'insights', 'articles', 'resources', 'press' ),
			'gallery'  => array( 'gallery', 'portfolio', 'work', 'projects', 'case-stud', 'showcase' ),
			'faq'      => array( 'faq', 'help', 'questions', 'knowledge' ),
			'legal'    => array( 'privacy', 'terms', 'cookie', 'legal', 'policy', 'gdpr' ),
			'careers'  => array( 'career', 'jobs', 'hiring', 'join-us', 'join us' ),
		);

		foreach ( $rules as $archetype => $needles ) {
			foreach ( $needles as $needle ) {
				if ( str_contains( $hay, $needle ) ) {
					return $archetype;
				}
			}
		}

		if ( ! empty( $signals['has_form'] ) ) {
			return 'contact';
		}
		if ( ! empty( $signals['image_count'] ) && (int) $signals['image_count'] >= 8 ) {
			return 'gallery';
		}
		if ( ! empty( $signals['list_heavy'] ) && ! empty( $signals['heading_count'] ) && (int) $signals['heading_count'] >= 6 ) {
			return 'faq';
		}

		return 'general';
	}

	/**
	 * Dynamic UX instructions for the detected archetype.
	 *
	 * @param array<string, mixed> $facts
	 * @return array<int, string>
	 */
	public static function ux_brief_for_archetype( string $archetype, array $facts = array() ): array {
		$shared = array(
			'Open with a focused page hero (title + short supporting line) using Home hero/band recipes.',
			'Keep one clear primary CTA near the end; secondary CTAs only if content demands.',
			'Reuse Home card / split / media-text patterns — never invent new chrome.',
			'Balance whitespace like Home: sections should breathe, not feel sparse or cramped.',
		);

		$by_type = array(
			'contact'  => array(
				'Lead with trust + how to reach you; put the form above the fold or immediately after a short intro.',
				'Use wp:dxai-ui/form with name, email, message (add phone/subject if content_facts.form_signals suggest them).',
				'Optional: compact info strip (address / phone / hours) beside or under the form using Home list/card styles.',
			),
			'about'    => array(
				'Story arc: mission → what we do → proof (stats/list) → team or values teaser → CTA.',
				'Use a media + text split early; follow with 3–4 value cards or icon-free feature bands from Home.',
			),
			'team'     => array(
				'Grid of people cards (image + name + role + short bio). Equal card heights via Home card recipes.',
				'Optional intro band; avoid long bios in the hero.',
			),
			'services' => array(
				'Service catalog: hero → overview → 3–6 service cards → process steps (if lists exist) → CTA.',
				'Each service card: title, 1–2 sentences, optional image; link/button only if a clear CTA exists in facts.',
			),
			'product'  => array(
				'Feature-led layout: hero → key benefits grid → deeper feature splits with images → pricing/CTA if present.',
				'Prioritize scannable benefit bullets over long paragraphs.',
			),
			'blog'     => array(
				'Listing feel: page intro + card grid of article teasers from headings/paragraphs (static cards OK if no WP posts).',
				'Consistent card meta (title + excerpt); do not invent author/dates.',
			),
			'gallery'  => array(
				'Visual-first: short intro then image-forward grid/masonry-like columns using Home media patterns.',
				'Captions from image_alts when available; avoid text-heavy bands.',
			),
			'faq'      => array(
				'Accordion-like stacked Q&A: each question as H2/H3, answer as paragraph/list. Tight vertical rhythm.',
				'Optional top search-style intro line; end with contact CTA.',
			),
			'legal'    => array(
				'Readable legal layout: clear H2 sections, comfortable measure, minimal decoration. No fake marketing CTAs.',
				'Preserve clause order from content_facts; do not rewrite legal meaning.',
			),
			'careers'  => array(
				'Culture intro → open roles as cards/list → benefits band → apply CTA / form if signals.has_form.',
			),
			'general'  => array(
				'Compose from content density: if many images → media-led; if many lists → cards/steps; else editorial sections.',
				'Always end with a Home-styled CTA band when a logical next action exists.',
			),
		);

		$specific = $by_type[ $archetype ] ?? $by_type['general'];
		$signals  = is_array( $facts['signals'] ?? null ) ? $facts['signals'] : array();
		$dynamic  = array();

		if ( ! empty( $signals['has_form'] ) && $archetype !== 'contact' ) {
			$dynamic[] = 'Include a wp:dxai-ui/form section — content signals a form on this page.';
		}
		if ( ! empty( $signals['cta_phrases'] ) && is_array( $signals['cta_phrases'] ) ) {
			$cta = array_slice( $signals['cta_phrases'], 0, 3 );
			$dynamic[] = 'Reuse these CTA labels when placing buttons: ' . implode( ', ', $cta ) . '.';
		}
		if ( ! empty( $signals['image_count'] ) && (int) $signals['image_count'] >= 4 ) {
			$dynamic[] = 'Lean visual: distribute images across hero + mid-page media, not one dump at the bottom.';
		}
		if ( ! empty( $signals['list_heavy'] ) ) {
			$dynamic[] = 'Promote list_items into structured cards or step rows rather than one long bullet dump.';
		}

		return array_values( array_merge( $shared, $specific, $dynamic ) );
	}

	/**
	 * Strip foreign styling from scraped HTML — keep headings, paragraphs, lists, images, UX signals.
	 *
	 * @return array{headings:array<int, string>, paragraphs:array<int, string>, list_items:array<int, string>, image_alts:array<int, string>, form_signals:array<int, string>, signals:array<string, mixed>}
	 */
	public static function content_facts_from_html( string $html ): array {
		$headings = array();
		if ( preg_match_all( '/<h([1-3])\b[^>]*>(.*?)<\/h\1>/is', $html, $hm ) ) {
			foreach ( $hm[2] as $chunk ) {
				$text = trim( wp_strip_all_tags( html_entity_decode( $chunk, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
				if ( $text !== '' ) {
					$headings[] = $text;
				}
				if ( count( $headings ) >= 12 ) {
					break;
				}
			}
		}

		$paragraphs = array();
		if ( preg_match_all( '/<p\b[^>]*>(.*?)<\/p>/is', $html, $pm ) ) {
			foreach ( $pm[1] as $chunk ) {
				$text = trim( wp_strip_all_tags( html_entity_decode( $chunk, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
				if ( $text === '' || strlen( $text ) < 12 ) {
					continue;
				}
				$paragraphs[] = $text;
				if ( count( $paragraphs ) >= 20 ) {
					break;
				}
			}
		}

		$list_items = array();
		if ( preg_match_all( '/<li\b[^>]*>(.*?)<\/li>/is', $html, $lm ) ) {
			foreach ( $lm[1] as $chunk ) {
				$text = trim( wp_strip_all_tags( html_entity_decode( $chunk, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
				if ( $text === '' || strlen( $text ) < 4 ) {
					continue;
				}
				$list_items[] = $text;
				if ( count( $list_items ) >= 24 ) {
					break;
				}
			}
		}

		$alts = array();
		if ( preg_match_all( '/<img\b[^>]*\balt=["\']([^"\']*)["\']/i', $html, $am ) ) {
			foreach ( $am[1] as $alt ) {
				$alt = trim( html_entity_decode( $alt, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				if ( $alt !== '' ) {
					$alts[] = $alt;
				}
				if ( count( $alts ) >= 12 ) {
					break;
				}
			}
		}

		$form_signals = array();
		if ( preg_match_all( '/<(?:input|textarea|select)\b[^>]*>/i', $html, $fm ) ) {
			foreach ( $fm[0] as $tag ) {
				$name = '';
				if ( preg_match( '/\bname=["\']([^"\']+)["\']/i', $tag, $nm ) ) {
					$name = strtolower( $nm[1] );
				} elseif ( preg_match( '/\btype=["\']([^"\']+)["\']/i', $tag, $tm ) ) {
					$name = strtolower( $tm[1] );
				} elseif ( preg_match( '/\bplaceholder=["\']([^"\']+)["\']/i', $tag, $pm2 ) ) {
					$name = strtolower( $pm2[1] );
				}
				$name = preg_replace( '/[^a-z0-9_\- ]+/', '', $name ) ?? $name;
				$name = trim( $name );
				if ( $name !== '' && ! in_array( $name, array( 'submit', 'button', 'hidden', 'csrf' ), true ) ) {
					$form_signals[] = $name;
				}
				if ( count( $form_signals ) >= 12 ) {
					break;
				}
			}
		}
		$form_signals = array_values( array_unique( $form_signals ) );

		$cta_phrases = array();
		if ( preg_match_all( '/<(?:a|button)\b[^>]*>(.*?)<\/(?:a|button)>/is', $html, $cm ) ) {
			foreach ( $cm[1] as $chunk ) {
				$text = trim( wp_strip_all_tags( html_entity_decode( $chunk, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
				if ( $text === '' || strlen( $text ) > 48 || strlen( $text ) < 2 ) {
					continue;
				}
				$cta_phrases[] = $text;
				if ( count( $cta_phrases ) >= 8 ) {
					break;
				}
			}
		}

		$image_count = 0;
		if ( preg_match_all( '/<img\b/i', $html, $img_m ) ) {
			$image_count = count( $img_m[0] );
		}

		return array(
			'headings'      => $headings,
			'paragraphs'    => $paragraphs,
			'list_items'    => $list_items,
			'image_alts'    => $alts,
			'form_signals'  => $form_signals,
			'signals'       => array(
				'has_form'      => $form_signals !== array() || (bool) preg_match( '/<form\b/i', $html ),
				'image_count'   => $image_count,
				'heading_count' => count( $headings ),
				'list_heavy'    => count( $list_items ) >= 8,
				'cta_phrases'   => array_values( array_unique( $cta_phrases ) ),
			),
		);
	}

	private static function rules(): string {
		return "Additional rules:\n"
			. "- Source may be Figma JSON, Lovable TSX, or a generated ZIP (HTML/CSS/JS, Vue, React, Next.js).\n"
			. "- Put Tailwind utilities on the wrapper via className in the block comment AND the matching HTML class attribute.\n"
			. "- Prefer WordPress media URLs from harvest.images / harvest.videos / the assets array when present.\n"
			. "- Self-hosted videos must be core/video (or a cover with a video background) using the sideloaded URL.\n"
			. "- A YouTube video is never a live iframe or a plain core/embed: write a core/embed of its watch URL and the plugin turns it into a facade (thumbnail and play button; the player loads only when pressed).\n"
			. "- Preserve harvested hyperlinks. Load harvested fonts (Google stylesheet or @font-face files).\n"
			. "- Split header, menus, footer, hero, blog, forms, and sliders into structures[].\n"
			. "- Blog listings MUST be core/query (dynamic posts), never static placeholder cards.\n"
			. "- Forms MUST be wp:dxai-ui/form. Sliders MUST be wp:dxai-ui/slider with inner blocks.\n"
			. "- Prefer shell + core/html for flex/grid/absolute chrome; never force is-layout-flow on design-owned layout.\n";
	}
}

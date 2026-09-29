<?php
/**
 * Everything a design carries, counted on both sides of the conversion.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Verification;

/**
 * Did all of it come across?
 *
 * The geometry oracle answers "does this page look like the design", block
 * validity answers "can the editor open it", and the island tally answers "how
 * much of it can be edited". None of them answers the question someone actually
 * asks about an export — *is all of it here* — because each one is blind to a
 * whole class of loss:
 *
 * - a `@keyframes` block the purger dropped: the page is pixel-identical in a
 *   screenshot and never animates;
 * - an `<svg>` whose `<path>` children were flattened: same box, no artwork;
 * - a `--token` the theme declared and the sheet lost: every colour derived
 *   from it silently falls back;
 * - an `onClick` in the TSX with no behaviour marker on the page: the control
 *   is there and does nothing;
 * - a font stylesheet that never travelled: the same layout in a different
 *   typeface.
 *
 * So each dimension is counted in the design and counted again on the page, and
 * the two numbers are reported side by side. Counting, not judging: where a
 * lower number on the page is legitimate — Tailwind's purge exists to remove
 * unused rules — the row is marked informational and can never fail. Where
 * every item in the design must appear on the page, a shortfall is a finding,
 * and the missing items are named.
 *
 * What cannot be measured is reported as unknown rather than as a pass. The
 * React sources are optional: with them, handlers and icon imports are counted
 * from the code the design was written in; without them, those rows say so.
 */
final class Design_Coverage {

	public const META = '_dxai_ui_coverage';

	/**
	 * Tokens in the `animation` shorthand that are not keyframe names.
	 *
	 * The shorthand mixes a name with timing functions, directions, fill modes
	 * and play states, so the name is whatever is left. Guessing by position
	 * fails — the name can come first or last — and guessing by "not a time"
	 * keeps every keyword.
	 */
	private const ANIMATION_KEYWORDS = array(
		'normal', 'reverse', 'alternate', 'alternate-reverse',
		'none', 'forwards', 'backwards', 'both',
		'running', 'paused', 'infinite',
		'linear', 'ease', 'ease-in', 'ease-out', 'ease-in-out',
		'step-start', 'step-end', 'initial', 'inherit', 'unset', 'revert',
	);

	/** Dimensions where the page may legitimately hold fewer than the design. */
	private const INFORMATIONAL = array(
		'css-rules',
		'media-queries',
		'transitions',
		'handlers',
		'archive-media',
		// The page reads these and neither side declares them: the design's own.
		'tokens-dead-in-design',
		// One motion element can need several markers, or none: a fade that
		// runs on load is CSS by the time it reaches the page.
		'motion-elements',
		// Likewise one Radix component can be several toggles or a link.
		'interactive-components',
		/*
		 * And an icon count taken across the whole archive includes components
		 * no imported route renders: 38 lucide elements in DevriX Elevate's
		 * source against 9 on its pages, with nothing lost. `icons-wired` is
		 * the part of that which can fail.
		 */
		'icons',
	);

	/**
	 * Count every dimension on both sides.
	 *
	 * @param array<string, mixed>  $result  A Source_Compiler result.
	 * @param int                   $page_id The page the import wrote.
	 * @param array<string, string> $sources The design's own source files, `path => code`, when available.
	 * @return array{rows: array<int, array<string, mixed>>, findings: int, unknown: int, score: int|null}
	 */
	public function measure( array $result, int $page_id, array $sources = array() ): array {
		/*
		 * Counted from the sources when they are here, and otherwise from what
		 * the compiler counted while it had them. Neither present means the two
		 * rows say unknown, which is the whole point of carrying the signals:
		 * the admin has no ZIP by the time a page exists.
		 */
		$signals = $sources !== array()
			? self::source_signals( $sources )
			: ( is_array( $result['source_signals'] ?? null ) ? $result['source_signals'] : null );
		$design_css = (string) ( $result['design_css_raw'] ?? '' );
		$design_html = (string) ( $result['source_html'] ?? '' );
		$harvest     = is_array( $result['design_assets'] ?? null ) ? $result['design_assets'] : array();

		$page_html = $this->page_markup( $page_id, $result );
		$page_css  = $this->page_css( $page_id );
		$page_js   = $this->page_js( $page_id, $result );

		$rows = array();

		/*
		 * Animation, in the three places a design can express it: keyframes the
		 * stylesheet defines, declarations that run them, and the transitions
		 * that carry every hover and open state.
		 *
		 * Only keyframes an `animation` declaration actually names are required
		 * on the page — a design's CSS routinely defines more than it uses, and
		 * demanding those would make the row fail forever for no reason.
		 */
		$design_keyframes = $this->keyframes( $design_css );
		$page_keyframes   = $this->keyframes( $page_css );
		$used             = array_values( array_intersect( $design_keyframes, $this->animation_names( $design_css . "\n" . $page_css . "\n" . $page_html ) ) );
		$rows[]           = $this->row(
			'keyframes',
			count( $used ),
			count( array_intersect( $used, $page_keyframes ) ),
			array_values( array_diff( $used, $page_keyframes ) ),
			sprintf( '%d defined in the design, %d of them run by an animation declaration', count( $design_keyframes ), count( $used ) )
		);

		$rows[] = $this->row(
			'animation-declarations',
			$this->count_all( '/(?<![\w-])animation(?:-name)?\s*:/i', $design_css ),
			$this->count_all( '/(?<![\w-])animation(?:-name)?\s*:/i', $page_css ),
			array(),
			'`animation` and `animation-name` declarations'
		);

		$rows[] = $this->row(
			'transitions',
			$this->count_all( '/(?<![\w-])transition(?:-property|-duration)?\s*:/i', $design_css ),
			$this->count_all( '/(?<![\w-])transition(?:-property|-duration)?\s*:/i', $page_css ),
			array(),
			'informational: Tailwind emits one per utility actually used'
		);

		/*
		 * Styles. Custom properties are required — every colour, radius and font
		 * in a v4 design is derived from one, so a lost token changes the page
		 * without changing a single class. Rule and media counts are
		 * informational because purging is the point of the purger.
		 */
		$design_vars = $this->custom_properties( $design_css );
		$page_vars   = $this->custom_properties( $page_css );
		$rows[]      = $this->row(
			'custom-properties',
			count( $design_vars ),
			count( array_intersect( $design_vars, $page_vars ) ),
			array_slice( array_values( array_diff( $design_vars, $page_vars ) ), 0, 20 ),
			'design tokens the page has to declare'
		);

		$rows[] = $this->row(
			'css-rules',
			$this->count_all( '/\{/', $design_css ),
			$this->count_all( '/\{/', $page_css ),
			array(),
			'informational: the purger removes rules the page does not use'
		);

		$rows[] = $this->row(
			'media-queries',
			count( $this->media_conditions( $design_css ) ),
			count( $this->media_conditions( $page_css ) ),
			array(),
			'informational: unused breakpoints are purged with their rules'
		);

		/*
		 * Typefaces. A page in the right layout and the wrong font is the
		 * failure this row exists for, and it has two halves: `@font-face`
		 * blocks the design's own CSS declares, and the hosted stylesheets the
		 * harvester found (Google Fonts, almost always).
		 */
		/*
		 * Assets a stylesheet paints with. A lost `url()` is a hero image that
		 * stopped loading, or a mask that stopped clipping, and no block check
		 * or screenshot diff at one width can see it.
		 */
		/*
		 * The page's sheet against itself: every animation it runs has to have
		 * keyframes, and every token it reads has to be declared. Neither can be
		 * caught by comparing the two sides — a Tailwind `animate-*` utility's
		 * keyframes come from the engine, not from the design, so a sheet with
		 * the declaration and no `@keyframes` looks identical on both sides and
		 * simply never moves.
		 */
		$referenced = $this->animation_names( $page_css );
		$defined    = $page_keyframes;
		$missing    = array();
		foreach ( $referenced as $name ) {
			// Only names that look like keyframes: the shorthand's other tokens
			// (`linear`, `infinite`, `both`) are filtered by the CSS keyword
			// list rather than by guessing.
			if ( in_array( strtolower( $name ), self::ANIMATION_KEYWORDS, true ) ) {
				continue;
			}
			if ( ! in_array( $name, $defined, true ) ) {
				$missing[] = $name;
			}
		}
		$missing = array_values( array_unique( $missing ) );
		$rows[]  = $this->row(
			'keyframes-resolved',
			count( $referenced ) - count( array_intersect( array_map( 'strtolower', $referenced ), self::ANIMATION_KEYWORDS ) ),
			count( $referenced ) - count( array_intersect( array_map( 'strtolower', $referenced ), self::ANIMATION_KEYWORDS ) ) - count( $missing ),
			array_slice( $missing, 0, 20 ),
			'animations the page runs, against the keyframes it defines'
		);

		/*
		 * Tokens the page reads and has no value for — minus the ones the
		 * design never declares either.
		 *
		 * RevOps Insight Engine reads `var(--t)` six times and `var(--n)` once
		 * in rules it wrote itself, and declares neither anywhere: the original
		 * build has the same dead variables, so the converted page reproducing
		 * them is faithful. Charging that to the conversion made a design's own
		 * bug look like a loss, which is the fastest way to make a report
		 * ignorable. They are counted on their own line instead.
		 */
		$reads         = $this->tokens_read( $page_css );
		$declared      = array_merge( $page_vars, $this->tokens_set_in_js( $page_js ) );
		$design_vars_l = $this->custom_properties( $design_css );
		$dead_in_design = array_values( array_diff( $reads, $declared, $design_vars_l ) );
		$owed          = array_values( array_intersect( $reads, $design_vars_l ) );
		$lost          = array_values( array_diff( $owed, $declared ) );
		$rows[]        = $this->row(
			'tokens-resolved',
			count( $owed ),
			count( $owed ) - count( $lost ),
			array_slice( $lost, 0, 20 ),
			'tokens the design declares and the page reads, against the ones the page declares in CSS or sets in script'
		);
		$rows[] = $this->row(
			'tokens-dead-in-design',
			count( $dead_in_design ),
			count( $dead_in_design ),
			array_slice( $dead_in_design, 0, 20 ),
			'informational: the page reads these and neither side declares them — the design\'s own dead variables'
		);

		$rows[] = $this->row(
			'css-url-assets',
			$this->count_all( '/url\(/i', $design_css ),
			$this->count_all( '/url\(/i', $page_css ),
			array(),
			'`url()` references in the stylesheet'
		);

		$rows[] = $this->row(
			'font-face',
			$this->count_all( '/@font-face/i', $design_css ),
			$this->count_all( '/@font-face/i', $page_css ),
			array(),
			'`@font-face` blocks'
		);

		$harvest_fonts = is_array( $harvest['fonts'] ?? null ) ? $harvest['fonts'] : array();
		$page_fonts    = array_filter( (array) get_post_meta( $page_id, '_dxai_ui_font_urls', true ) );
		$rows[]        = $this->row(
			'font-stylesheets',
			count( $harvest_fonts ),
			count( $page_fonts ),
			array(),
			'hosted font stylesheets the design links'
		);

		/*
		 * Vectors, counted as artwork rather than as elements: an `<svg>` that
		 * arrives with its children stripped passes an svg count and draws
		 * nothing, so the shapes inside are their own row — and they are the
		 * row that catches a flattened icon.
		 */
		$rows[] = $this->row(
			'inline-svg',
			$this->count_all( '/<svg[\s>]/i', $design_html ),
			$this->count_all( '/<svg[\s>]/i', $page_html ),
			array(),
			'inline `<svg>` elements'
		);

		$shapes = '/<(?:path|circle|rect|line|polyline|polygon|ellipse|use|image|text|g)[\s>\/]/i';
		$rows[] = $this->row(
			'svg-shapes',
			$this->count_all( $shapes, $this->svg_only( $design_html ) ),
			$this->count_all( $shapes, $this->svg_only( $page_html ) ),
			array(),
			'the geometry inside those elements'
		);

		$rows[] = $this->row(
			'svg-defs',
			$this->count_all( '/<(?:lineargradient|radialgradient|clippath|mask|filter|pattern|marker|symbol|defs)[\s>]/i', $this->svg_only( $design_html ) ),
			$this->count_all( '/<(?:lineargradient|radialgradient|clippath|mask|filter|pattern|marker|symbol|defs)[\s>]/i', $this->svg_only( $page_html ) ),
			array(),
			'gradients, clips, masks and filters the artwork references'
		);

		/*
		 * References, not files in the archive.
		 *
		 * An export ships whatever its author put in `public/` — Arcus carries
		 * 54 `.svg` files and its design draws 11 of them; Growth Story Hub
		 * carries 2 and draws none. Holding a page to the shipped count reported
		 * both as losses while both carry every vector the design asks for. What
		 * the archive ships is in archive-media below, where it cannot fail.
		 */
		$svg_ref = '/\.svg(?:["\')\s?]|$)/i';
		$rows[]  = $this->row(
			'svg-files',
			$this->count_all( $svg_ref, $design_html ),
			$this->count_all( $svg_ref, $page_html . "\n" . $page_css ),
			array(),
			'`.svg` files the design points at rather than inlining'
		);

		/*
		 * Icons. A Lovable design draws its icons from lucide-react, which is a
		 * component per icon in the source and a `data-lucide` marker on the
		 * page — so the source side is only countable with the sources, and
		 * says so when it has none.
		 */
		$icon_source = isset( $signals['lucide'] ) ? (int) $signals['lucide'] : null;
		$rows[]      = $this->row(
			'icons',
			$icon_source,
			$this->count_all( '/data-lucide=/i', $page_html ),
			array(),
			$icon_source === null
				? 'unknown: no design sources and no counts carried in the compile result'
				: 'lucide icon elements'
		);

		/*
		 * The part of the icon count that can fail: the mechanism. A design
		 * that draws icons and a page with no `data-lucide` marker anywhere has
		 * lost every one of them, and that is worth failing over; the ratio
		 * above is not, because a source-wide count includes components no
		 * imported route renders.
		 */
		$rows[] = $this->row(
			'icons-wired',
			$icon_source === null ? null : ( $icon_source > 0 ? 1 : 0 ),
			$this->count_all( '/data-lucide=/i', $page_html ) > 0 ? 1 : 0,
			array(),
			'the design draws icons, so the page has to carry icon markers'
		);

		/*
		 * Media, in two rows that answer two different questions.
		 *
		 * What the design renders has to reach the page, so `<img>` and
		 * `<video>` in the rendered design are counted against the page's own —
		 * a shortfall there is a picture that stopped appearing. What the
		 * archive ships is a different number and cannot fail: an export
		 * routinely carries art that no route uses, and DevriX Elevate's 12
		 * files against 5 rendered images was this row calling that a loss.
		 */
		$rows[] = $this->row(
			'images',
			$this->count_all( '/<img[\s>]/i', $design_html ),
			$this->count_all( '/<img[\s>]/i', $page_html ),
			array(),
			'pictures the design renders'
		);
		$rows[] = $this->row(
			'videos',
			$this->count_all( '/<video[\s>]/i', $design_html ),
			$this->count_all( '/<video[\s>]/i', $page_html ),
			array(),
			'videos the design renders'
		);

		$summary = is_array( $harvest['summary'] ?? null ) ? $harvest['summary'] : array();
		$shipped = (int) ( $summary['images'] ?? count( is_array( $harvest['images'] ?? null ) ? $harvest['images'] : array() ) )
			+ (int) ( $summary['videos'] ?? count( is_array( $harvest['videos'] ?? null ) ? $harvest['videos'] : array() ) );
		$rows[]  = $this->row(
			'archive-media',
			$shipped,
			$this->count_all( '/wp-content\/uploads\//i', $page_html ),
			array(),
			'informational: files the archive ships, against uploads the page points at'
		);

		/*
		 * Behaviour. The runtime reads markers off the markup — `data-dxai-*`
		 * and the `dxai-toggle-*` / `dxai-on-*` / `dxai-cls-*` class families —
		 * so a marker in the design's rendered HTML that is missing from the
		 * page is a behaviour that stopped working, exactly the loss a
		 * screenshot cannot see.
		 */
		$rows[] = $this->row(
			'behaviour-markers',
			$this->count_all( '/data-dxai-[a-z-]+=/i', $design_html ),
			$this->count_all( '/data-dxai-[a-z-]+=/i', $page_html ),
			array(),
			'`data-dxai-*` hooks the motion runtime reads'
		);

		$rows[] = $this->row(
			'state-classes',
			$this->count_all( '/\bdxai-(?:toggle|on|off|cls)-[\w-]+/i', $design_html ),
			$this->count_all( '/\bdxai-(?:toggle|on|off|cls)-[\w-]+/i', $page_html ),
			array(),
			'the state-machine class families'
		);

		$handlers = isset( $signals['handlers'] ) ? (int) $signals['handlers'] : null;
		$rows[]   = $this->row(
			'handlers',
			$handlers,
			$this->count_all( '/data-dxai-[a-z-]+=/i', $page_html ) + $this->count_all( '/\bdxai-(?:toggle|on|off|cls)-[\w-]+/i', $page_html ),
			array(),
			$handlers === null
				? 'unknown: no design sources and no counts carried in the compile result'
				: 'informational: one handler can need several markers, or none (a link)'
		);

		/*
		 * Motion and interaction as the design declares them.
		 *
		 * A Lovable project animates with framer-motion (`motion.div` and its
		 * `initial` / `animate` / `whileInView` props) and takes its
		 * interaction from Radix through shadcn — dialogs, popovers, tooltips,
		 * accordions, tabs, selects. Neither reaches the page as itself: both
		 * become markers the motion runtime reads. So the counts sit side by
		 * side for reference, and the row that can fail is the one that matters:
		 * a design that declares motion or interaction and a page with no
		 * behaviour markers at all means the whole mechanism is missing, which
		 * is the failure a screenshot cannot show.
		 */
		$markers = $this->count_all( '/data-dxai-[a-z-]+=/i', $page_html )
			+ $this->count_all( '/\bdxai-(?:toggle|on|off|cls)-[\w-]+/i', $page_html );

		$motion = isset( $signals['motion'] ) ? (int) $signals['motion'] : null;
		$rows[] = $this->row(
			'motion-elements',
			$motion,
			$markers,
			array(),
			$motion === null
				? 'unknown: no design sources and no counts carried in the compile result'
				: 'informational: framer-motion elements against the markers they became'
		);

		$radix  = isset( $signals['radix'] ) ? (int) $signals['radix'] : null;
		$rows[] = $this->row(
			'interactive-components',
			$radix,
			$markers,
			array(),
			$radix === null
				? 'unknown: no design sources and no counts carried in the compile result'
				: 'informational: Radix/shadcn components against the markers they became'
		);

		/*
		 * State the design takes from the scroll position: the sticky header
		 * that turns solid past a threshold, and every variant of it. The
		 * runtime reads that from a `data-dxai-scroll="state:threshold"`
		 * marker, so a design that declares one and a page with none is a
		 * behaviour that was dropped — silently, because the design side of the
		 * pixel oracle is compiled by the same compiler and comes out equally
		 * inert.
		 */
		$scroll = isset( $signals['scroll'] ) ? (int) $signals['scroll'] : null;
		$rows[] = $this->row(
			'scroll-state',
			$scroll === null ? null : ( $scroll > 0 ? 1 : 0 ),
			$this->count_all( '/data-dxai-scroll=/i', $page_html ) > 0 ? 1 : 0,
			array(),
			$scroll === null
				? 'unknown: no design sources and no counts carried in the compile result'
				: 'the design reads window.scrollY into state, so the page needs a scroll marker'
		);

		/*
		 * What must reach the page as a marker, and what must not be held to
		 * that.
		 *
		 * framer-motion elements, Radix components and a scroll state become
		 * markers by construction — the runtime has no other way to drive them
		 * — so a design that declares one and a page with no marker anywhere
		 * has lost the mechanism. A bare handler count does not belong in that
		 * test: a form's `onSubmit` is carried by the form block, an `onClick`
		 * that opens a window or scrolls into view needs no state at all, and
		 * ARA Redesign Studio's single handler made this row report a loss on a
		 * page with nothing wrong. The handler count stays, one row up, as
		 * information.
		 */
		$mechanisms = array_filter(
			array( $motion, $radix, $scroll ),
			static fn( $value ): bool => $value !== null
		);
		$rows[] = $this->row(
			'behaviour-wired',
			$mechanisms === array() ? null : ( array_sum( $mechanisms ) > 0 ? 1 : 0 ),
			$markers > 0 ? 1 : 0,
			array(),
			'the design declares motion, Radix interaction or a scroll state, so the page has to carry markers'
		);

		$rows[] = $this->row(
			'runtime-js',
			$this->count_all( '/\S/', (string) ( $result['custom_js'] ?? '' ) ) > 0 ? 1 : 0,
			$page_js !== '' ? 1 : 0,
			array(),
			'the page\'s own behaviour script'
		);

		$findings = 0;
		$unknown  = 0;
		foreach ( $rows as $row ) {
			if ( $row['unknown'] ) {
				++$unknown;
				continue;
			}
			if ( $row['short'] ) {
				++$findings;
			}
		}

		/*
		 * A score only over the rows that can fail and are known, so an
		 * informational row can never flatter the number and an unmeasurable
		 * one can never punish it.
		 */
		$gradeable = 0;
		$carried   = 0;
		foreach ( $rows as $row ) {
			if ( $row['unknown'] || $row['informational'] || $row['source'] === 0 ) {
				continue;
			}
			++$gradeable;
			if ( ! $row['short'] ) {
				++$carried;
			}
		}

		return array(
			'rows'     => $rows,
			'findings' => $findings,
			'unknown'  => $unknown,
			'score'    => $gradeable > 0 ? (int) round( ( $carried / $gradeable ) * 100 ) : null,
		);
	}

	/**
	 * One dimension's two numbers.
	 *
	 * @param array<int, string> $missing
	 * @return array<string, mixed>
	 */
	private function row( string $dimension, ?int $source, int $page, array $missing, string $note ): array {
		$informational = in_array( $dimension, self::INFORMATIONAL, true );

		return array(
			'dimension'     => $dimension,
			'source'        => $source,
			'page'          => $page,
			'missing'       => $missing,
			'note'          => $note,
			'informational' => $informational,
			'unknown'       => $source === null,
			'short'         => $source !== null && ! $informational && $page < $source,
		);
	}

	private function count_all( string $pattern, string $subject ): int {
		if ( $subject === '' ) {
			return 0;
		}

		return (int) preg_match_all( $pattern, $subject );
	}

	/**
	 * `@keyframes` names a stylesheet defines.
	 *
	 * @return array<int, string>
	 */
	private function keyframes( string $css ): array {
		preg_match_all( '/@(?:-webkit-)?keyframes\s+([A-Za-z_][\w-]*)/i', $css, $m );

		return array_values( array_unique( $m[1] ?? array() ) );
	}

	/**
	 * Animation names any declaration or utility class actually runs.
	 *
	 * Reads the shorthand as well as `animation-name`, and takes the page's
	 * markup too: a Tailwind `animate-*` utility names its keyframes in the
	 * rule the engine writes, not in the design's own CSS.
	 *
	 * @return array<int, string>
	 */
	private function animation_names( string $subject ): array {
		$names = array();
		preg_match_all( '/animation-name\s*:\s*([^;}]+)/i', $subject, $named );
		foreach ( $named[1] ?? array() as $list ) {
			foreach ( explode( ',', (string) $list ) as $one ) {
				$names[] = trim( $one );
			}
		}
		preg_match_all( '/(?<![\w-])animation\s*:\s*([^;}]+)/i', $subject, $short );
		foreach ( $short[1] ?? array() as $value ) {
			foreach ( explode( ',', (string) $value ) as $one ) {
				foreach ( preg_split( '/\s+/', trim( (string) $one ) ) ?: array() as $token ) {
					$token = trim( (string) $token );
					/*
					 * The shorthand's name is the one token that is not a time,
					 * a count, a keyword or a timing function. Keeping every
					 * candidate is safe: a name that matches no `@keyframes`
					 * drops out when this list is intersected with the defined
					 * ones.
					 */
					if ( $token !== '' && preg_match( '/^[A-Za-z_][\w-]*$/', $token ) === 1 ) {
						$names[] = $token;
					}
				}
			}
		}

		return array_values( array_unique( array_filter( $names ) ) );
	}

	/**
	 * Custom property names a stylesheet declares.
	 *
	 * @return array<int, string>
	 */
	private function custom_properties( string $css ): array {
		preg_match_all( '/(--[A-Za-z_][\w-]*)\s*:/', $css, $m );

		return array_values( array_unique( $m[1] ?? array() ) );
	}

	/**
	 * Tokens the page's behaviour script sets at runtime.
	 *
	 * Radix measures an accordion panel's open height into
	 * `--radix-accordion-content-height` and the design's keyframes animate to
	 * it; Motion_Runtime does the same. A token declared that way is declared,
	 * and calling it missing made the check report a working animation as a
	 * loss.
	 *
	 * @return array<int, string>
	 */
	private function tokens_set_in_js( string $js ): array {
		preg_match_all( '/setProperty\(\s*[\'"](--[A-Za-z_][\w-]*)[\'"]/', $js, $m );

		return array_values( array_unique( $m[1] ?? array() ) );
	}

	/**
	 * Tokens a stylesheet reads and has no fallback for.
	 *
	 * `var(--x, 1rem)` degrades to `1rem` and is not a loss, so it is left
	 * out. `--wp--*` is the theme's, declared outside this sheet, and counting
	 * it would make the row fail on every page for something that is not ours.
	 *
	 * @return array<int, string>
	 */
	private function tokens_read( string $css ): array {
		preg_match_all( '/var\(\s*(--[A-Za-z_][\w-]*)\s*([,)])/', $css, $m, PREG_SET_ORDER );
		$names = array();
		foreach ( $m as $hit ) {
			if ( ( $hit[2] ?? ')' ) === ',' ) {
				continue;
			}
			if ( str_starts_with( $hit[1], '--wp--' ) ) {
				continue;
			}
			$names[ $hit[1] ] = true;
		}

		return array_keys( $names );
	}

	/**
	 * @return array<int, string>
	 */
	private function media_conditions( string $css ): array {
		preg_match_all( '/@media([^{]+)\{/i', $css, $m );

		return array_values( array_unique( array_map( static fn( $c ): string => trim( (string) preg_replace( '/\s+/', ' ', (string) $c ) ), $m[1] ?? array() ) ) );
	}

	/** Only the SVG parts of a document, so `<text>` in copy is not a shape. */
	private function svg_only( string $html ): string {
		if ( $html === '' ) {
			return '';
		}
		preg_match_all( '#<svg[\s>][\s\S]*?</svg\s*>#i', $html, $m );

		return implode( "\n", $m[0] ?? array() );
	}

	/**
	 * What only the design's own code can say, counted while it is in hand.
	 *
	 * Source_Compiler calls this and carries the answer in its result, because
	 * the admin path saves a page from JSON long after the archive is gone —
	 * and a row nobody can count is a row that reports unknown forever.
	 *
	 * @param array<string, string> $sources `path => code`.
	 * @return array{lucide:int, handlers:int}
	 */
	public static function source_signals( array $sources ): array {
		$sources = self::rendered_only( $sources );
		$code    = implode( "\n", array_map( 'strval', $sources ) );
		$self    = new self();

		/*
		 * shadcn re-exports Radix under its own names, so both spellings are
		 * counted: the import from `@radix-ui/react-*` and the element that
		 * uses it. Counting elements rather than imports is deliberate — one
		 * import can be rendered a dozen times, and it is the dozen that have
		 * to work.
		 */
		$interactive = 0;
		foreach ( array( 'Dialog', 'Sheet', 'Drawer', 'Popover', 'Tooltip', 'HoverCard', 'DropdownMenu', 'ContextMenu', 'Menubar', 'NavigationMenu', 'Accordion', 'Collapsible', 'Tabs', 'Select', 'Combobox', 'Command', 'AlertDialog', 'Switch', 'Checkbox', 'RadioGroup', 'Slider', 'Toggle', 'Carousel' ) as $name ) {
			$interactive += $self->count_all( '/<' . $name . '(?:Trigger|Content|Root)?[\s\/>]/', $code );
		}

		return array(
			'lucide'   => $self->lucide_uses( $code ),
			// Every JSX event prop: the handlers a design's behaviour is made of.
			'handlers' => $self->count_all( '/\bon[A-Z][A-Za-z]+\s*=\s*\{/', $code ),
			/*
			 * framer-motion: the `motion.*` elements themselves, plus the props
			 * that make one animate. A `motion.div` with no animation props is
			 * a div, and a `whileInView` on a wrapper is an animation with no
			 * `motion.` prefix in sight.
			 */
			'motion'   => $self->count_all( '/<motion\.[a-z]+[\s\/>]/', $code )
				+ $self->count_all( '/\b(?:whileInView|whileHover|whileTap|animate|initial|variants)\s*=\s*\{/', $code ),
			'radix'    => $interactive,
			/*
			 * A scroll listener that writes a boolean into state:
			 * `setSolid(window.scrollY > 24)`, which is how every sticky
			 * header in this corpus turns solid. `pageYOffset` is the same
			 * read under its older name.
			 */
			'scroll'   => self::scroll_states_used( $code ),
		);
	}

	/**
	 * Scroll-driven states the design actually reads.
	 *
	 * The listener alone is not the behaviour: Brand Polish Pass keeps
	 * `setSolid(window.scrollY > 24)` and then writes `const dark = true; void
	 * solid;`, pinning its header solid on purpose. The page reproduces that,
	 * so counting the listener reported a loss where there was none.
	 *
	 * Jsx_Compiler::scroll_driven() applies the same rule when it decides
	 * whether to declare the state, so the two sides of the coverage row agree
	 * on what counts.
	 */
	private static function scroll_states_used( string $code ): int {
		$used = 0;
		if ( ! preg_match_all(
			'/\bset([A-Z][A-Za-z0-9]*)\s*\(\s*(?:!\s*)?(?:window\s*\.\s*)?(?:scrollY|pageYOffset)\s*[<>]/',
			$code,
			$hits,
			PREG_SET_ORDER
		) ) {
			// The other shape: a local holds the comparison, the setter takes it.
			return preg_match( '/\bconst\s+[A-Za-z_][\w]*\s*=\s*(?:window\s*\.\s*)?(?:scrollY|pageYOffset)\s*[<>]/', $code ) === 1 ? 1 : 0;
		}

		/*
		 * String literals go first: `\bsolid\b` matches the `solid` inside a
		 * `border-solid` class, which made every unused state look used —
		 * including Brand Polish Pass's, whose header is pinned solid on
		 * purpose with `const dark = true; void solid;`.
		 */
		$bare = (string) preg_replace_callback(
			'/`(?:[^`\\\\]|\\\\.)*`/',
			static function ( array $hit ): string {
				// Keep the `${…}` expressions: they are the reads.
				preg_match_all( '/\\$\\{([^{}]*(?:\\{[^{}]*\\}[^{}]*)*)\\}/', $hit[0], $parts );

				return ' ' . implode( ' ', $parts[1] ?? array() ) . ' ';
			},
			$code
		);
		$bare = (string) preg_replace(
			array( '/"(?:[^"\\\\\n]|\\\\.)*"/', "/'(?:[^'\\\\\n]|\\\\.)*'/" ),
			'""',
			$bare
		);
		foreach ( $hits as $hit ) {
			$state   = lcfirst( (string) $hit[1] );
			$without = (string) preg_replace(
				array(
					'/\bconst\s*\[\s*' . preg_quote( $state, '/' ) . '\s*,[^\]]*\]\s*=\s*useState[^;\n]*/',
					'/\bvoid\s+' . preg_quote( $state, '/' ) . '\s*;/',
				),
				'',
				$bare
			);
			if ( preg_match( '/\b' . preg_quote( $state, '/' ) . '\b/', $without ) === 1 ) {
				++$used;
			}
		}

		return $used;
	}

	/**
	 * The design's source files, minus the ones nothing renders.
	 *
	 * An export carries components its routes never use — Brand Polish Pass's
	 * FloatingCta is defined, exported and imported by nobody — and counting
	 * them makes the page look like it lost something it was never asked to
	 * draw. A file survives when it looks like an entry point, whose own
	 * component is never written as an element, or when a component it declares
	 * is used as one somewhere in the design.
	 *
	 * @param array<string, string> $sources
	 * @return array<string, string>
	 */
	private static function rendered_only( array $sources ): array {
		if ( $sources === array() ) {
			return $sources;
		}

		/*
		 * Where rendering starts. A route, page or app entry is never written
		 * as an element by anything else, so it has to be seeded; a set with
		 * none of them cannot say what is reachable — one component handed over
		 * on its own is never rendered inside itself — and is counted whole.
		 */
		$entries = array();
		$declares = array();
		$uses     = array();
		foreach ( $sources as $path => $code ) {
			$norm = strtolower( str_replace( '\\', '/', (string) $path ) );
			if ( preg_match( '#(?:^|/)(?:routes|pages|views|screens)/#', $norm ) === 1
				|| preg_match( '#(?:^|/)(?:app|main|index|root)\\.[jt]sx?$#', $norm ) === 1
			) {
				$entries[ $path ] = true;
			}

			preg_match_all( '/\\b(?:export\\s+)?(?:default\\s+)?(?:function|const)\\s+([A-Z][A-Za-z0-9_]*)/', (string) $code, $own );
			$declares[ $path ] = array_values( array_unique( $own[1] ?? array() ) );

			preg_match_all( '/<([A-Z][A-Za-z0-9_]*)[\\s\\/>]/', (string) $code, $used );
			$uses[ $path ] = array_values( array_unique( $used[1] ?? array() ) );
		}

		if ( $entries === array() ) {
			return $sources;
		}

		/*
		 * Which file declares a component. A name declared in two files is
		 * ambiguous without resolving imports, so both are kept — over-keeping
		 * a file costs a count that is too high, and dropping the right one
		 * would report a loss that is not there.
		 */
		$home = array();
		foreach ( $declares as $path => $names ) {
			foreach ( $names as $name ) {
				$home[ $name ][] = $path;
			}
		}

		$included = $entries;
		$queue    = array_keys( $entries );
		while ( $queue !== array() ) {
			$path = array_shift( $queue );
			foreach ( $uses[ $path ] ?? array() as $name ) {
				foreach ( $home[ $name ] ?? array() as $owner ) {
					if ( isset( $included[ $owner ] ) ) {
						continue;
					}
					$included[ $owner ] = true;
					$queue[]            = $owner;
				}
			}
		}

		$out = array();
		foreach ( $sources as $path => $code ) {
			// A file with nothing component-shaped in it — a stylesheet, a
			// config, a data table — carries no signal of its own and is kept.
			if ( isset( $included[ $path ] ) || ( $declares[ $path ] ?? array() ) === array() ) {
				$out[ $path ] = $code;
			}
		}

		return $out;
	}

	/**
	 * Lucide icon elements in the design's source.
	 *
	 * Counts uses, not imports: one import can be rendered five times, and it is
	 * the five drawn icons that have to reach the page.
	 */
	private function lucide_uses( string $code ): int {
		preg_match_all( '/import\s*\{([^}]*)\}\s*from\s*[\'"]lucide-react[\'"]/', $code, $imports );
		$names = array();
		foreach ( $imports[1] ?? array() as $list ) {
			foreach ( explode( ',', (string) $list ) as $one ) {
				// `Check as CheckIcon` is rendered under the local name.
				$parts = preg_split( '/\s+as\s+/', trim( (string) $one ) ) ?: array();
				$name  = trim( (string) end( $parts ) );
				if ( $name !== '' && preg_match( '/^[A-Z][A-Za-z0-9]*$/', $name ) === 1 ) {
					$names[ $name ] = true;
				}
			}
		}
		if ( $names === array() ) {
			return 0;
		}

		$uses = 0;
		foreach ( array_keys( $names ) as $name ) {
			$uses += $this->count_all( '/<' . preg_quote( $name, '/' ) . '[\s\/>]/', $code );
		}

		return $uses;
	}

	/**
	 * The markup a page will serve, template parts included.
	 *
	 * A page references its header and footer instead of holding them, so
	 * reading `post_content` alone loses every SVG, marker and image in the
	 * design's chrome — which is where a nav's icons live.
	 *
	 * @param array<string, mixed> $result
	 */
	private function page_markup( int $page_id, array $result ): string {
		$content = (string) get_post_field( 'post_content', $page_id );
		$parts   = array( $content );

		if ( preg_match_all( '/<!--\s+wp:template-part\s+(\{.*?\})\s+\/-->/', $content, $m ) ) {
			foreach ( $m[1] as $json ) {
				$attrs = json_decode( (string) $json, true );
				$slug  = is_array( $attrs ) ? (string) ( $attrs['slug'] ?? '' ) : '';
				if ( $slug === '' ) {
					continue;
				}
				$posts = get_posts(
					array(
						'post_type'      => 'wp_template_part',
						'post_status'    => 'any',
						'name'           => $slug,
						'posts_per_page' => 1,
						'fields'         => 'ids',
					)
				);
				if ( $posts !== array() ) {
					$parts[] = (string) get_post_field( 'post_content', (int) $posts[0] );
					continue;
				}
				// A part that lives in the theme's own files has no post.
				foreach ( get_block_templates( array( 'slug__in' => array( $slug ) ), 'wp_template_part' ) as $part ) {
					$parts[] = (string) ( $part->content ?? '' );
				}
			}
		}

		/*
		 * Every other route this import wrote, because the design side is the
		 * whole design: `source_html` is the full rendered project, so counting
		 * one page against it would report the other routes' artwork missing.
		 */
		foreach ( is_array( $result['pages'] ?? null ) ? $result['pages'] : array() as $extra ) {
			if ( is_array( $extra ) ) {
				$parts[] = (string) ( $extra['gutenberg_markup'] ?? '' );
			}
		}

		return implode( "\n", $parts );
	}

	/** The stylesheet bytes the page will actually serve. */
	private function page_css( int $page_id ): string {
		// Either stored form of the location — see Upload_Paths.
		$file = \DXAI_UI\Support\Upload_Paths::for_meta( $page_id, '_dxai_ui_css_url' )['path'];

		return $file !== '' && is_readable( $file ) ? (string) file_get_contents( $file ) : '';
	}

	/**
	 * The behaviour script the page will serve.
	 *
	 * @param array<string, mixed> $result
	 */
	private function page_js( int $page_id, array $result ): string {
		$file = \DXAI_UI\Support\Upload_Paths::for_meta( $page_id, '_dxai_ui_js_url' )['path'];

		return $file !== '' && is_readable( $file ) ? (string) file_get_contents( $file ) : '';
	}
}

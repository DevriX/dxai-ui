<?php
/**
 * Takes a converted page's inline styles out of its markup.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

/**
 * No `style=""` in stored markup.
 *
 * A converted design keeps its exact look in per-element CSS — grid tracks,
 * clamp() type, offsets — and that CSS used to live in every element's
 * `style` attribute. That is the one place a stylesheet cannot reach: a
 * value in it cannot be changed once for the whole site, KSES strips parts
 * of it for anyone without unfiltered_html, and it is the first thing a
 * person trying to restyle a page runs into.
 *
 * So after tokenising (colours are `var(--dxai-…)` by then), each block's CSS
 * moves out of the HTML:
 *
 *  - A block's own CSS (core blocks' and this plugin's `dxaiStyle`,
 *    dxai-ui/link's `style`) moves to the block attribute `dxaiCss`, and the
 *    element gets the class `dxs-{hash}` instead of the attribute. The
 *    attribute stays the block's editable source; the editor's save() writes
 *    the same class (assets/js/blocks-editor.js computes the same hash), and
 *    Style_Rules turns every such attribute into a rule when the page renders.
 *  - A text colour that is one of the site's presets (the brand design's
 *    palette, registered in theme.json by Design_Theme_Json) becomes core's
 *    own colour attribute — `textColor`, the `has-{slug}-color` class — so it
 *    shows in the block's colour picker and follows Styles. Only the text
 *    colour, and only on core blocks that support it: a preset background
 *    brings core's `has-background` padding with it, which would move boxes.
 *  - A style on an element that is not a block's own root (inline runs in a
 *    paragraph, the inside of a raw-HTML island) becomes a `dxs-{hash}` class
 *    too, and its CSS is kept on the block, in `dxaiInner`, for Style_Rules.
 *    Only inside blocks whose inner markup is content (CONTENT_BLOCKS,
 *    RAW_BLOCKS); markup a block's save() writes from attributes is left as
 *    it is written.
 *  - Whatever part of a block's own CSS one of the DevriX theme's utility
 *    classes declares exactly (`d-flex`, `p-4`, `gap-2-5`), or one of the
 *    plugin's own where the theme has none (`fw-900`, `text-14-5`,
 *    `text-dxai-ink` — Utility_Classes), becomes that class instead: added
 *    to the block's className attribute, which is where each of these
 *    blocks' save() reads its class list from, and to its element's class
 *    list, so the stored markup is what save() writes. Only the rest stays
 *    in `dxaiCss`, with the dxs- hash of that rest; nothing left, no dxaiCss
 *    and no dxs- class. apply_utilities() does the same for markup hoisted
 *    before this step. The design's own class names are never chosen
 *    (design_names()), and they are the same names whether the header and
 *    footer stored beside the page were written by this step before or not,
 *    so importing a design again gives the same classes again.
 *
 * The rules Style_Rules writes are given inline-style precedence on purpose
 * (see Style_Rules::rule()), so the page renders exactly as before; the
 * utilities are `!important` single classes, which renders the same for the
 * reasons Utility_Classes sets out.
 *
 * dxai-ui/svg keeps its root attributes as an ordered list of pairs, so it
 * is handled on its own (hoist_svg()): the style pair becomes a class pair.
 */
final class Style_Hoister {

	/** The block attribute that holds a block's own CSS once it is out of the markup. */
	public const ATTR = 'dxaiCss';

	/**
	 * Post meta: CSS of styled elements in markup that has no blocks to keep
	 * it on (class => declarations). Blocks keep theirs in INNER_ATTR.
	 */
	public const INNER_META = '_dxai_ui_inner_css';

	/**
	 * The block attribute that holds the CSS of the styled elements inside a
	 * block (class => declarations): inline runs in rich text, the inside of
	 * raw HTML, an SVG's root. On the block rather than in post meta, so a
	 * copy of the block — an unsynced pattern inserted into any page, a block
	 * pasted elsewhere — brings its rules with it.
	 */
	public const INNER_ATTR = 'dxaiInner';

	/**
	 * Post meta: the design's own class names that are also utility names
	 * (design_names()), recorded when an import is hoisted — while its
	 * stylesheet is certainly on disk and its markup is still the design's.
	 * Style_Rules writes no utility rule for them, even when the stylesheet
	 * cannot be read later (uploads offloaded to a CDN, a restore without
	 * them): the design's `p-4` stays the design's.
	 */
	public const DESIGN_NAMES_META = '_dxai_ui_design_names';

	/** Blocks whose whole markup is content (raw HTML kept as written). */
	private const RAW_BLOCKS = array( 'core/html', 'dxai-ui/html' );

	/**
	 * Blocks whose markup inside their own root element is content — rich
	 * text parsed back out of the stored HTML — so a style in it can become
	 * a class and the block still matches its save(). Every other block
	 * writes its inner markup from attributes (core/image's <img> sizes,
	 * core/button's <a> colours, dxai-ui/link's label): rewriting that would
	 * open the block as invalid, so it is left as the block wrote it. Each
	 * one here (and in RAW_BLOCKS) registers INNER_ATTR in the editor.
	 */
	private const CONTENT_BLOCKS = array( 'core/paragraph', 'core/heading', 'core/list-item', 'dxai-ui/text' );

	/** Core blocks whose text colour can be a preset attribute. */
	private const TEXT_COLOR_BLOCKS = array( 'core/paragraph', 'core/heading', 'core/group', 'core/list' );

	/**
	 * Blocks whose element's class list is their className attribute (plus
	 * the dxs- class), in both emitters, so a utility class added to one is
	 * written into the other: core's through the customClassName support,
	 * this plugin's through their save() (assets/js/blocks-editor.js).
	 */
	private const UTILITY_BLOCKS = array( 'core/paragraph', 'core/heading', 'core/group', 'core/list', 'core/list-item', 'dxai-ui/box', 'dxai-ui/text', 'dxai-ui/link', 'dxai-ui/image' );

	/**
	 * This plugin's blocks whose opening tag is written entirely from
	 * attributes, by a PHP mirror of their save() in Html_To_Blocks. Their
	 * tag is rebuilt with that mirror when its class list changes, so the
	 * class attribute lands where save() puts it (its position depends on
	 * whether className is empty and on attrOrder).
	 */
	private const OWN_TAG_BLOCKS = array( 'dxai-ui/box', 'dxai-ui/text', 'dxai-ui/link', 'dxai-ui/image' );

	/**
	 * The class a declaration list is written as: `dxs-` and the FNV-1a hash
	 * of its exact text, in base 36. The editor computes the same (dxaiCssClass
	 * in blocks-editor.js), so a block saved in either place names the same
	 * rule.
	 */
	public static function css_class( string $css ): string {
		$hash = 0x811c9dc5;
		$len  = strlen( $css );
		for ( $i = 0; $i < $len; $i++ ) {
			$hash ^= ord( $css[ $i ] );
			$hash  = ( $hash * 0x01000193 ) & 0xffffffff;
		}

		return 'dxs-' . base_convert( (string) $hash, 10, 36 );
	}

	/**
	 * Hoist every post an import wrote.
	 *
	 * The posts of one import are one design: the utility classes are chosen
	 * against all of their class names and design sheets together
	 * (utility_context()), so a name one post's design uses is not given a
	 * utility meaning on another. Markup of the same design that is not a
	 * post — the header kept for Appearance > Menus, the footer's widget
	 * blocks — goes in $extra_markup so its class names count too (it is not
	 * hoisted here; run hoist_markup() or apply_utilities() on it with
	 * utility_context() of the same posts and markup).
	 *
	 * The design's own utility-named classes are recorded on every post first
	 * (DESIGN_NAMES_META, remember_design_names()), from the markup as the
	 * import wrote it.
	 *
	 * @param array<int, int>    $post_ids
	 * @param array<int, string> $preset_slugs Colour slugs registered as presets (brand design only).
	 * @param array<int, string> $extra_markup
	 * @return array{posts:int, blocks:int, inner:int, presets:int, utilities:int}
	 */
	public static function hoist_import( array $post_ids, array $preset_slugs = array(), array $extra_markup = array() ): array {
		$total    = array(
			'posts'     => 0,
			'blocks'    => 0,
			'inner'     => 0,
			'presets'   => 0,
			'utilities' => 0,
		);
		$ids      = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
		$contents = array();
		foreach ( $ids as $id ) {
			$contents[ $id ] = (string) get_post_field( 'post_content', $id );
		}
		self::remember_design_names( $ids, $extra_markup );
		$context = self::utility_context( $ids, $extra_markup );
		foreach ( $ids as $id ) {
			$content = $contents[ $id ];
			if ( $content === '' || ! str_contains( $content, 'style' ) ) {
				continue;
			}
			$out = self::hoist_markup( $content, $preset_slugs, $context );
			if ( $out['markup'] === $content ) {
				continue;
			}
			wp_update_post(
				array(
					'ID'           => $id,
					'post_content' => wp_slash( $out['markup'] ),
				)
			);
			if ( $out['inner'] !== array() ) {
				update_post_meta( $id, self::INNER_META, $out['inner'] );
			} else {
				delete_post_meta( $id, self::INNER_META );
			}
			++$total['posts'];
			$total['blocks']    += $out['blocks'];
			$total['inner']     += count( $out['inner'] );
			$total['presets']   += $out['presets'];
			$total['utilities'] += $out['utilities'];
		}

		return $total;
	}

	/**
	 * @param array<int, string>        $preset_slugs
	 * @param array<string, mixed>|null $utilities Utility_Classes::match() options — pass
	 *                                             utility_context() of the posts the markup
	 *                                             renders with, so the design's class names
	 *                                             and animated properties are left alone;
	 *                                             null (the default) leaves every
	 *                                             declaration in dxaiCss. The markup's own
	 *                                             design names are always avoided.
	 * @return array{markup:string, inner:array<string, string>, blocks:int, presets:int, utilities:int}
	 */
	public static function hoist_markup( string $markup, array $preset_slugs = array(), ?array $utilities = null ): array {
		$state = array(
			'inner'     => array(),
			'blocks'    => 0,
			'presets'   => 0,
			'slugs'     => array_fill_keys( $preset_slugs, true ),
			'utilities' => $utilities === null ? null : self::with_design_names( $utilities, $markup ),
			'applied'   => 0,
		);
		if ( ! str_contains( $markup, '<!-- wp:' ) ) {
			return array(
				'markup'    => self::inner_html( $markup, $state, false ),
				'inner'     => $state['inner'],
				'blocks'    => 0,
				'presets'   => 0,
				'utilities' => 0,
			);
		}
		$changed = false;
		$blocks  = self::hoist_blocks( parse_blocks( $markup ), $state, $changed );

		return array(
			'markup'    => $changed ? serialize_blocks( $blocks ) : $markup,
			'inner'     => $state['inner'],
			'blocks'    => $state['blocks'],
			'presets'   => $state['presets'],
			'utilities' => $state['applied'],
		);
	}

	/**
	 * The utility step on its own, for markup whose styles were hoisted
	 * before it existed: every block carrying dxaiCss has the part of it a
	 * utility declares exactly moved to that class (className and element),
	 * dxaiCss and its dxs- class rewritten for the rest, or dropped when
	 * nothing is left. Blocks without dxaiCss, and everything else in the
	 * markup, are returned byte for byte; so is the whole markup when no
	 * block matches. Running it twice changes nothing the second time: what
	 * is left over has no utility that fits it.
	 *
	 * @param array<string, mixed> $options Utility_Classes::match() options, normally
	 *                                      utility_context() of the posts the markup
	 *                                      renders with. The markup's own design
	 *                                      names are always avoided.
	 */
	public static function apply_utilities( string $markup, array $options = array() ): string {
		if ( ! str_contains( $markup, '"' . self::ATTR . '"' ) ) {
			return $markup;
		}
		$state   = array(
			'utilities' => self::with_design_names( $options, $markup ),
			'applied'   => 0,
		);
		$changed = false;
		$blocks  = self::utility_blocks( parse_blocks( $markup ), $state, $changed, array() );

		return $changed ? serialize_blocks( $blocks ) : $markup;
	}

	/**
	 * Matching options for markup that renders with these posts: never a
	 * class name their design uses (design_names() of their markup and of
	 * $extra_markup — a header or footer rendered on the same page — their
	 * design sheets' classes, and the names recorded at import), never a
	 * property their @keyframes animate.
	 *
	 * The same for the same design whatever this step already did to the
	 * markup: a utility it placed is not a design name (design_names()), so
	 * a context built from a header or footer stored after an earlier import
	 * avoids exactly what one built from the fresh design does.
	 *
	 * @param array<int, int>    $post_ids
	 * @param array<int, string> $extra_markup
	 * @return array{avoid: array<int, string>, avoid_props: array<int, string>, sheet_classes: array<int, string>}
	 */
	public static function utility_context( array $post_ids, array $extra_markup = array() ): array {
		$found = self::design_context( $post_ids, $extra_markup, true );

		return array(
			'avoid'         => array_values( array_unique( array_merge( $found['sheet']['classes'], $found['names'] ) ) ),
			'avoid_props'   => $found['sheet']['props'],
			'sheet_classes' => $found['sheet']['classes'],
		);
	}

	/**
	 * Record the design names of these posts (and the markup that renders
	 * with them) on each of them, for Style_Rules (DESIGN_NAMES_META). Read
	 * from the posts as they are now — call it before their styles are
	 * hoisted or moved to utilities, as hoist_import() does; a migration of
	 * already-hoisted content calls it before apply_utilities(). Names this
	 * step places are not design names (design_names()), so calling it again
	 * later records the same list.
	 *
	 * @param array<int, int>    $post_ids
	 * @param array<int, string> $extra_markup
	 * @return array<int, string> The names recorded.
	 */
	public static function remember_design_names( array $post_ids, array $extra_markup = array() ): array {
		$ids   = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
		$names = self::design_context( $ids, $extra_markup, false )['names'];
		sort( $names );
		foreach ( $ids as $id ) {
			if ( get_post( $id ) === null ) {
				continue;
			}
			if ( $names === array() ) {
				delete_post_meta( $id, self::DESIGN_NAMES_META );
			} elseif ( get_post_meta( $id, self::DESIGN_NAMES_META, true ) !== $names ) {
				update_post_meta( $id, self::DESIGN_NAMES_META, $names );
			}
		}

		return $names;
	}

	/**
	 * The design sheets' context of these posts, and every design name of
	 * theirs that is a utility name: the sheets' own, their markup's and the
	 * extra markup's (design_names()), and — with $recorded — what was
	 * recorded at import on them and on their scope page.
	 *
	 * @param array<int, int>    $post_ids
	 * @param array<int, string> $extra_markup
	 * @return array{sheet: array{classes: array<int, string>, props: array<int, string>}, names: array<int, string>}
	 */
	private static function design_context( array $post_ids, array $extra_markup, bool $recorded ): array {
		$ids    = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
		$sheets = array();
		foreach ( $ids as $id ) {
			$sheets = array_merge( $sheets, Utility_Classes::design_sheets( $id ) );
		}
		$sheet = Utility_Classes::sheet_context( array_values( array_unique( $sheets ) ) );
		$names = array();
		foreach ( $ids as $id ) {
			foreach ( self::design_names( (string) get_post_field( 'post_content', $id ), $sheet['classes'] ) as $name ) {
				$names[ $name ] = true;
			}
			if ( $recorded ) {
				foreach ( array_unique( array_filter( array( $id, (int) get_post_meta( $id, \DXAI_UI\Structures\Page_Scope::META, true ) ) ) ) as $owner ) {
					foreach ( (array) get_post_meta( (int) $owner, self::DESIGN_NAMES_META, true ) as $name ) {
						if ( is_string( $name ) && $name !== '' ) {
							$names[ $name ] = true;
						}
					}
				}
			}
		}
		foreach ( $extra_markup as $markup ) {
			foreach ( self::design_names( (string) $markup, $sheet['classes'] ) as $name ) {
				$names[ $name ] = true;
			}
		}

		return array(
			'sheet' => $sheet,
			'names' => array_map( 'strval', array_keys( $names ) ),
		);
	}

	/**
	 * The design's own class names in a piece of markup that are also
	 * utility names (Utility_Classes::is_utility()) — the names match() must
	 * not choose and Style_Rules must not write a utility rule for:
	 *
	 *  - every utility name its design sheet defines ($sheet_classes);
	 *  - every class the design's runtime toggles (Utility_Classes::toggle_names());
	 *  - every utility name in a class attribute or className this step does
	 *    not write: raw HTML, rich-text runs, the inside of blocks, and
	 *    blocks it never gives utilities (guarded()).
	 *
	 * In markup this step has already been through, the class list of a block
	 * it gives utilities to — its className and its root element's class
	 * attribute — counts only for the names the design sheet defines. That
	 * list holds the design's classes, the utilities placed here and the
	 * classes a person typed into "Additional CSS class(es)"; reading a
	 * utility name in it as the design's would make the next import avoid
	 * every class this one placed (a header stored after one import, read
	 * into the next import's context, took 59 classes out of the page and
	 * brought 22 others in). Markup as the converter wrote it — its styles
	 * not hoisted yet (fresh(): inline styles, no dxaiCss) — holds nothing
	 * but the design's names, so there every utility name counts, and an
	 * import records a design element's `d-flex` even where its stylesheet
	 * gives it no rule.
	 *
	 * @param array<int, string> $sheet_classes
	 * @param bool|null          $fresh Whether the markup is the converter's own; null: fresh().
	 * @return array<int, string>
	 */
	public static function design_names( string $markup, array $sheet_classes = array(), ?bool $fresh = null ): array {
		$sheet = array_fill_keys( array_map( 'strval', $sheet_classes ), true );
		$found = $sheet;
		foreach ( Utility_Classes::toggle_names( $markup ) as $name ) {
			$found[ $name ] = true;
		}
		if ( $fresh ?? self::fresh( $markup ) ) {
			foreach ( Utility_Classes::class_tokens( $markup ) as $name ) {
				$found[ $name ] = true;
			}
		} elseif ( str_contains( $markup, '<!-- wp:' ) ) {
			self::design_names_in( parse_blocks( $markup ), $sheet, $found, array() );
		} else {
			foreach ( Utility_Classes::class_tokens( $markup ) as $name ) {
				$found[ $name ] = true;
			}
		}
		$out = array();
		foreach ( array_keys( $found ) as $name ) {
			$name = (string) $name;
			if ( Utility_Classes::is_utility( $name ) ) {
				$out[] = $name;
			}
		}

		return $out;
	}

	/**
	 * Whether block markup is as the converter wrote it: it still carries the
	 * attribute this step moves styles from (`dxaiStyle`, dxai-ui/link's
	 * `style`) and none it moved them to (`dxaiCss`). hoist_markup() moves
	 * every block's in one pass, so markup that has been through it has no
	 * source left. A `style=""` in the HTML proves nothing: core's style
	 * supports write one from a block's attributes after hoisting too, on a
	 * page whose every hoisted block may have become utility classes alone.
	 */
	private static function fresh( string $markup ): bool {
		if ( ! str_contains( $markup, '<!-- wp:' ) || str_contains( $markup, '"' . self::ATTR . '"' ) ) {
			return false;
		}
		if ( str_contains( $markup, '"dxaiStyle"' ) ) {
			return true;
		}
		if ( str_contains( $markup, '<!-- wp:dxai-ui/link {' ) && preg_match_all( '#<!-- wp:dxai-ui/link (\{.*?\}) /?-->#', $markup, $m ) ) {
			foreach ( $m[1] as $json ) {
				$attrs = json_decode( $json, true );
				if ( is_array( $attrs ) && is_string( $attrs['style'] ?? null ) && trim( $attrs['style'] ) !== '' ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * design_names() over a block list.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<string, bool>              $sheet
	 * @param array<string, bool>              $found  Filled in place.
	 * @param array<int, string>               $parent The parent block's class names.
	 */
	private static function design_names_in( array $blocks, array $sheet, array &$found, array $parent ): void {
		foreach ( $blocks as $block ) {
			$name  = (string) ( $block['blockName'] ?? '' );
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			// A block whose class list this step writes (className = element).
			$own  = $name !== '' && ! self::guarded( $name, $attrs, $parent );
			$take = static function ( string $token ) use ( $own, $sheet, &$found ): void {
				if ( ! $own || isset( $sheet[ $token ] ) ) {
					$found[ $token ] = true;
				}
			};
			foreach ( preg_split( '/\s+/', (string) ( $attrs['className'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $token ) {
				$take( $token );
			}
			$root = $own;
			foreach ( (array) ( $block['innerContent'] ?? array() ) as $chunk ) {
				if ( ! is_string( $chunk ) || trim( $chunk ) === '' ) {
					continue;
				}
				if ( preg_match_all( '/<[a-z][a-z0-9-]*\b[^>]*>/i', $chunk, $tags ) ) {
					foreach ( $tags[0] as $tag ) {
						foreach ( self::tag_classes( $tag ) as $token ) {
							if ( $root ) {
								$take( $token );
							} else {
								$found[ $token ] = true;
							}
						}
						$root = false;
					}
				}
				$root = false;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				self::design_names_in( $block['innerBlocks'], $sheet, $found, self::class_list( $block ) );
			}
		}
	}

	/**
	 * Options with the markup's own design names added to `avoid`.
	 *
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	private static function with_design_names( array $options, string $markup ): array {
		$sheet            = array_map( 'strval', (array) ( $options['sheet_classes'] ?? array() ) );
		$options['avoid'] = array_values( array_unique( array_merge( array_map( 'strval', (array) ( $options['avoid'] ?? array() ) ), self::design_names( $markup, $sheet ) ) ) );

		return $options;
	}

	/**
	 * apply_utilities() over a block list.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<string, mixed>             $state
	 * @param array<int, string>               $parent The parent block's class names.
	 * @return array<int, array<string, mixed>>
	 */
	private static function utility_blocks( array $blocks, array &$state, bool &$changed, array $parent ): array {
		foreach ( $blocks as $i => $block ) {
			$name  = (string) ( $block['blockName'] ?? '' );
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$css   = is_string( $attrs[ self::ATTR ] ?? null ) ? $attrs[ self::ATTR ] : '';
			if ( $name !== '' && trim( $css ) !== '' && ! self::guarded( $name, $attrs, $parent ) ) {
				$match = Utility_Classes::match( $css, self::block_options( $state['utilities'], $attrs ) );
				if ( $match['classes'] !== array() ) {
					$new = self::with_utilities( $block, $attrs, $css, $match );
					if ( $new !== null ) {
						$blocks[ $i ] = $new;
						$block        = $new;
						$changed      = true;
						++$state['applied'];
					}
				}
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$blocks[ $i ]['innerBlocks'] = self::utility_blocks( $block['innerBlocks'], $state, $changed, self::class_list( $block ) );
			}
		}

		return $blocks;
	}

	/**
	 * One already-hoisted block with its matched utilities: className gains
	 * them, dxaiCss becomes the remainder (gone when empty), and the element's
	 * class list trades the old dxs- class for the utilities and the
	 * remainder's dxs- class. Null when the element does not carry the old
	 * dxs- class — markup edited out of step with its attribute is left alone.
	 *
	 * @param array<string, mixed>                                     $block
	 * @param array<string, mixed>                                     $attrs
	 * @param array{classes: array<int, string>, remainder: string}    $match
	 * @return array<string, mixed>|null
	 */
	private static function with_utilities( array $block, array $attrs, string $css, array $match ): ?array {
		$old  = self::css_class( $css );
		$rest = trim( $match['remainder'] ) === '' ? '' : $match['remainder'];
		$add  = $match['classes'];
		if ( $rest !== '' ) {
			$add[] = self::css_class( $rest );
		}
		$attrs['className'] = trim( (string) ( $attrs['className'] ?? '' ) . ' ' . implode( ' ', $match['classes'] ) );
		if ( $rest === '' ) {
			unset( $attrs[ self::ATTR ] );
		} else {
			$attrs[ self::ATTR ] = $rest;
		}
		$attrs = self::with_class_in_order( $attrs );
		$name  = (string) $block['blockName'];
		foreach ( (array) ( $block['innerContent'] ?? array() ) as $j => $chunk ) {
			if ( ! is_string( $chunk ) || trim( $chunk ) === '' ) {
				continue;
			}
			if ( preg_match( '/^(\s*)(<[a-z][a-z0-9-]*\b[^>]*?\s*\/?>)/i', $chunk, $m ) !== 1 || ! in_array( $old, self::tag_classes( $m[2] ), true ) ) {
				return null;
			}
			$tag                          = self::rewrite_root_tag( $m[2], $name, $attrs, array( $old ), $add );
			$block['innerContent'][ $j ] = $m[1] . $tag . substr( $chunk, strlen( $m[0] ) );
			break;
		}
		$block['attrs'] = $attrs;
		if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
			$block['innerHTML'] = implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) );
		}

		return $block;
	}

	/**
	 * Whether a block must keep all of its CSS in dxaiCss.
	 *
	 * Blocks outside UTILITY_BLOCKS have no className-driven class list. The
	 * rest are left alone where the page's JavaScript writes inline styles
	 * that an `!important` utility would override — Motion_Runtime:
	 *
	 *  - toggle panels and their triggers (`dxai-on-`/`dxai-off-`/`dxai-toggle-`
	 *    classes: applyPanel sets max-height, opacity, grid-template-rows);
	 *  - Radix parts, found by role and aria state (rxSelect positions a
	 *    `[role=listbox]` with display/position/z-index/left/top/min-width,
	 *    dialogs and popovers get display/position/left/top/z-index/width)
	 *    and by the ids the converter mints for them (`dxai-rx-…`,
	 *    `…-content-N`/`…-overlay-N`, whose overlay has no role);
	 *  - a `data-state` element (Radix state), a `hidden` one (revealed by
	 *    rxShow), the reveal mask `.rv-mask-in` (transform);
	 *  - the method-rail progress bar, the child of `.rv-mbp-meta-bar`, whose
	 *    width the scroll handler sets.
	 *
	 * And a core/button wrapper: the theme pairs most utilities with
	 * `.wp-block-button.x>a`, which would style its link too.
	 *
	 * @param array<string, mixed> $attrs
	 * @param array<int, string>   $parent The parent block's class names.
	 */
	private static function guarded( string $name, array $attrs, array $parent ): bool {
		if ( ! in_array( $name, self::UTILITY_BLOCKS, true ) ) {
			return true;
		}
		foreach ( array( 'role', 'ariaExpanded', 'ariaControls', 'ariaHasPopup', 'ariaSelected', 'hidden' ) as $key ) {
			if ( is_string( $attrs[ $key ] ?? null ) && trim( $attrs[ $key ] ) !== '' ) {
				return true;
			}
		}
		$anchor = is_string( $attrs['anchor'] ?? null ) ? $attrs['anchor'] : '';
		if ( $anchor !== '' && preg_match( '/^dxai-rx-|-(?:content|overlay|trigger)-/', $anchor ) === 1 ) {
			return true;
		}
		$data = is_array( $attrs['dxaiData'] ?? null ) ? $attrs['dxaiData'] : array();
		if ( isset( $data['data-state'] ) ) {
			return true;
		}
		$classes = preg_split( '/\s+/', (string) ( $attrs['className'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		foreach ( $classes as $class ) {
			if ( $class === 'wp-block-button' || $class === 'rv-mask-in' || preg_match( '/^dxai-(?:on|off|toggle)-/', $class ) === 1 ) {
				return true;
			}
		}

		return in_array( 'rv-mbp-meta-bar', $parent, true );
	}

	/**
	 * The context's options for one block: also never a longhand a utility
	 * the block already carries sets — between two utilities the theme's rule
	 * order decides, and the one already there wins over the dxs- rule the
	 * declaration came from.
	 *
	 * Nor `display` on an element the converter hid until the runtime opens
	 * it (`data-dxai-portal`, Jsx_Compiler::hide_element()) and that guarded()
	 * lets through (a portalled component's own root): its `display:none`
	 * would become the important `d-none`, which no display the runtime sets
	 * inline (rxShow) can beat. Left in the dxs- rule, it can.
	 *
	 * @param array<string, mixed> $options
	 * @param array<string, mixed> $attrs
	 * @return array<string, mixed>
	 */
	private static function block_options( array $options, array $attrs ): array {
		if ( is_array( $attrs['dxaiData'] ?? null ) && isset( $attrs['dxaiData']['data-dxai-portal'] ) ) {
			$options['avoid_props'] = array_merge( (array) ( $options['avoid_props'] ?? array() ), array( 'display' ) );
		}
		$design = array_fill_keys( array_map( 'strval', (array) ( $options['sheet_classes'] ?? array() ) ), true );
		$live   = array();
		foreach ( preg_split( '/\s+/', (string) ( $attrs['className'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $class ) {
			if ( ! isset( $design[ $class ] ) && Utility_Classes::is_utility( $class ) ) {
				$live[] = $class;
			}
		}
		if ( $live !== array() ) {
			$options['avoid_props'] = array_merge( (array) ( $options['avoid_props'] ?? array() ), Utility_Classes::longhands( $live ) );
		}

		return $options;
	}

	/**
	 * attrOrder with `class` in it, for blocks that write attributes in a
	 * stored order: a block with utilities always has a class list, and an
	 * explicit order that does not name `class` would drop it from save().
	 * Appended, which is where save() put the dxs- class it had before.
	 *
	 * @param array<string, mixed> $attrs
	 * @return array<string, mixed>
	 */
	private static function with_class_in_order( array $attrs ): array {
		if ( isset( $attrs['attrOrder'] ) && is_array( $attrs['attrOrder'] ) && $attrs['attrOrder'] !== array()
			&& ! in_array( 'class', array_map( static fn( $n ) => strtolower( (string) $n ), $attrs['attrOrder'] ), true ) ) {
			$attrs['attrOrder'][] = 'class';
		}

		return $attrs;
	}

	/**
	 * A block's element class names: its className and, for its first markup
	 * chunk, the root tag's class attribute.
	 *
	 * @param array<string, mixed> $block
	 * @return array<int, string>
	 */
	private static function class_list( array $block ): array {
		$out = preg_split( '/\s+/', (string) ( $block['attrs']['className'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		foreach ( (array) ( $block['innerContent'] ?? array() ) as $chunk ) {
			if ( is_string( $chunk ) && trim( $chunk ) !== '' ) {
				if ( preg_match( '/^\s*(<[a-z][a-z0-9-]*\b[^>]*?\s*\/?>)/i', $chunk, $m ) === 1 ) {
					$out = array_merge( $out, self::tag_classes( $m[1] ) );
				}
				break;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * The class names in an opening tag.
	 *
	 * @return array<int, string>
	 */
	private static function tag_classes( string $tag ): array {
		if ( preg_match( '/\sclass=("([^"]*)"|\'([^\']*)\')/i', $tag, $c ) !== 1 ) {
			return array();
		}
		$value = $c[1][0] === '"' ? $c[2] : ( $c[3] ?? '' );

		return preg_split( '/\s+/', html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
	}

	/**
	 * A block's opening tag with its class list changed: `style` dropped,
	 * $remove taken out of the class attribute, $add appended (a class
	 * attribute added when there was none).
	 *
	 * For this plugin's own blocks the tag is then rebuilt by its PHP mirror
	 * of save() (Html_To_Blocks), which writes the class attribute in the
	 * place save() does — after `id` for a box or a text whose className is no
	 * longer empty, after src/alt on an image, where attrOrder says when there
	 * is one. The rebuilt tag is used only when it carries exactly the same
	 * attributes and values as the edited one (class compared as a set), so a
	 * tag the mirror would not reproduce keeps its edited form.
	 *
	 * @param array<string, mixed> $attrs The block's attributes after the change.
	 * @param array<int, string>   $remove
	 * @param array<int, string>   $add
	 */
	private static function rewrite_root_tag( string $tag, string $name, array $attrs, array $remove, array $add ): string {
		if ( preg_match( '/^(<[a-z][a-z0-9-]*)(\b[^>]*?)(\s*\/?>)$/i', $tag, $m ) !== 1 ) {
			return $tag;
		}
		$list = (string) preg_replace( '/\sstyle=("[^"]*"|\'[^\']*\')/i', '', $m[2], 1 );
		if ( $remove !== array() && preg_match( '/\sclass=("([^"]*)"|\'([^\']*)\')/i', $list, $c ) === 1 ) {
			$have = preg_split( '/\s+/', $c[1][0] === '"' ? $c[2] : ( $c[3] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
			$keep = array_values( array_diff( $have, $remove ) );
			$list = $keep === array() && $add === array()
				? str_replace( $c[0], '', $list )
				: str_replace( $c[0], ' class="' . implode( ' ', $keep ) . '"', $list );
		}
		$edited = $m[1] . self::with_classes( $list, $add ) . $m[3];
		if ( ! in_array( $name, self::OWN_TAG_BLOCKS, true ) ) {
			return $edited;
		}
		$rebuilt = self::own_open_tag( $name, $attrs, str_ends_with( rtrim( $m[3] ), '/>' ) );

		return $rebuilt !== '' && self::same_attributes( $edited, $rebuilt ) ? $rebuilt : $edited;
	}

	/**
	 * The opening tag this plugin's block's save() writes for $attrs: its PHP
	 * mirror, fed the class list save() builds (className, then the dxs-
	 * class of dxaiCss). '' for any other block.
	 *
	 * @param array<string, mixed> $attrs
	 */
	private static function own_open_tag( string $name, array $attrs, bool $void ): string {
		$css  = is_string( $attrs[ self::ATTR ] ?? null ) ? trim( $attrs[ self::ATTR ] ) : '';
		$with = $attrs;
		unset( $with['dxaiStyle'], $with['style'] );
		$with['className'] = trim( (string) ( $attrs['className'] ?? '' ) . ( $css !== '' ? ' ' . self::css_class( (string) $attrs[ self::ATTR ] ) : '' ) );
		switch ( $name ) {
			case 'dxai-ui/link':
				return Html_To_Blocks::link_open_tag( $with );
			case 'dxai-ui/image':
				return Html_To_Blocks::image_html( $with );
			case 'dxai-ui/box':
				return '<' . Html_To_Blocks::box_tag( $with ) . Html_To_Blocks::box_attrs( $with ) . ( $void ? '/>' : '>' );
			case 'dxai-ui/text':
				return '<' . Html_To_Blocks::text_tag( $with ) . Html_To_Blocks::text_attrs( $with ) . '>';
		}

		return '';
	}

	/**
	 * Whether two opening tags are the same element: same tag name, same
	 * attributes with the same values in any order, class compared as a set.
	 */
	private static function same_attributes( string $a, string $b ): bool {
		$parse = static function ( string $tag ): ?array {
			if ( preg_match( '/^<([a-z][a-z0-9-]*)/i', $tag, $n ) !== 1 ) {
				return null;
			}
			$body = (string) preg_replace( '/\s*\/?>$/', '', substr( $tag, strlen( $n[0] ) ) );
			preg_match_all( '/\s([^\s"\'>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/', $body, $all, PREG_SET_ORDER );
			$out = array( '' => strtolower( $n[1] ) );
			foreach ( $all as $hit ) {
				$key   = strtolower( $hit[1] );
				$value = ( $hit[2] ?? '' ) . ( $hit[3] ?? '' ) . ( $hit[4] ?? '' );
				if ( $key === 'class' ) {
					$tokens = preg_split( '/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
					sort( $tokens );
					$value = implode( ' ', $tokens );
				}
				if ( isset( $out[ $key ] ) ) {
					return null;
				}
				$out[ $key ] = $value;
			}
			ksort( $out );

			return $out;
		};
		$x = $parse( $a );

		return $x !== null && $x === $parse( $b );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<string, mixed>             $state
	 * @param array<int, string>               $parent The parent block's class names.
	 * @return array<int, array<string, mixed>>
	 */
	private static function hoist_blocks( array $blocks, array &$state, bool &$changed, array $parent = array() ): array {
		foreach ( $blocks as $i => $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( $name === 'dxai-ui/svg' ) {
				$outer          = $state['inner'];
				$state['inner'] = array();
				$blocks[ $i ]   = self::hoist_svg( $block, $state, $changed );
				if ( $state['inner'] !== array() ) {
					$blocks[ $i ]['attrs'] = self::with_inner( is_array( $blocks[ $i ]['attrs'] ?? null ) ? $blocks[ $i ]['attrs'] : array(), $state['inner'] );
				}
				$state['inner'] = $outer;
				continue;
			}
			if ( $name === '' ) {
				continue;
			}
			$attrs   = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$source  = self::source_attr( $name, $attrs );
			$root    = null;
			$rebuild = false;
			if ( $source !== '' ) {
				$css   = (string) $attrs[ $source ];
				$extra = array();
				// A preset text colour becomes core's colour attribute.
				if ( in_array( $name, self::TEXT_COLOR_BLOCKS, true ) && empty( $attrs['textColor'] ) ) {
					$slug = self::preset_color( $css, $state['slugs'] );
					if ( $slug !== '' ) {
						$attrs['textColor'] = $slug;
						$css                = self::without( $css, 'color' );
						$extra              = array( 'has-' . $slug . '-color', 'has-text-color' );
						++$state['presets'];
					}
				}
				unset( $attrs[ $source ] );
				// What a theme utility declares exactly becomes that class.
				$utilities = array();
				if ( $state['utilities'] !== null && ! self::guarded( $name, $attrs, $parent ) ) {
					$match = Utility_Classes::match( $css, self::block_options( $state['utilities'], $attrs ) );
					if ( $match['classes'] !== array() ) {
						$utilities          = $match['classes'];
						$css                = $match['remainder'];
						$attrs['className'] = trim( (string) ( $attrs['className'] ?? '' ) . ' ' . implode( ' ', $utilities ) );
						$extra              = array_merge( $extra, $utilities );
						++$state['applied'];
					}
				}
				if ( trim( $css ) !== '' ) {
					$attrs[ self::ATTR ] = $css;
					$extra[]             = self::css_class( $css );
				}
				$root    = $extra;
				$rebuild = $utilities !== array() && in_array( $name, self::OWN_TAG_BLOCKS, true );
				// The design's attribute order drops `style`; `class` takes its
				// place when the element had none (this plugin's blocks write
				// attributes in that stored order).
				if ( isset( $attrs['attrOrder'] ) && is_array( $attrs['attrOrder'] ) ) {
					$order = array_values( array_filter( $attrs['attrOrder'], static fn( $n ) => strtolower( (string) $n ) !== 'style' ) );
					if ( $extra !== array() && ! in_array( 'class', array_map( 'strtolower', $order ), true ) ) {
						$pos = array_search( 'style', array_map( 'strtolower', $attrs['attrOrder'] ), true );
						array_splice( $order, $pos === false ? count( $order ) : (int) $pos, 0, 'class' );
					}
					$attrs['attrOrder'] = $order;
				}
				$blocks[ $i ]['attrs'] = $attrs;
				++$state['blocks'];
				$changed = true;
			}

			$raw     = in_array( $name, self::RAW_BLOCKS, true );
			$content = $raw || in_array( $name, self::CONTENT_BLOCKS, true );
			$first   = true;
			// This block's inner CSS is collected on its own, for INNER_ATTR.
			$outer          = $state['inner'];
			$state['inner'] = array();
			foreach ( (array) ( $block['innerContent'] ?? array() ) as $j => $chunk ) {
				if ( ! is_string( $chunk ) || trim( $chunk ) === '' ) {
					continue;
				}
				if ( $raw ) {
					$new = self::inner_html( $chunk, $state, false );
				} elseif ( $first && $root !== null && $rebuild ) {
					$new = self::rebuilt_root_html( $chunk, $name, $blocks[ $i ]['attrs'], $root, $state, $content );
				} elseif ( $first && $root !== null ) {
					$new = self::root_html( $chunk, $root, $state, $content );
				} elseif ( $first ) {
					$new = self::past_root( $chunk, $state, $content );
				} else {
					$new = $content ? self::inner_html( $chunk, $state, false ) : $chunk;
				}
				$first = false;
				if ( $new !== $chunk ) {
					$blocks[ $i ]['innerContent'][ $j ] = $new;
					$changed                            = true;
				}
			}
			if ( $state['inner'] !== array() ) {
				$blocks[ $i ]['attrs'] = self::with_inner( is_array( $blocks[ $i ]['attrs'] ?? null ) ? $blocks[ $i ]['attrs'] : array(), $state['inner'] );
			}
			$state['inner'] = $outer;
			if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
				$blocks[ $i ]['innerHTML'] = implode( '', array_filter( (array) $blocks[ $i ]['innerContent'], 'is_string' ) );
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$blocks[ $i ]['innerBlocks'] = self::hoist_blocks( $block['innerBlocks'], $state, $changed, self::class_list( $blocks[ $i ] ) );
			}
		}

		return $blocks;
	}

	/**
	 * root_html() for one of this plugin's blocks whose class list gained
	 * utilities: its opening tag is rewritten by rewrite_root_tag(), so the
	 * class attribute sits where the block's save() writes it.
	 *
	 * @param array<string, mixed> $attrs   The block's final attributes.
	 * @param array<int, string>   $classes
	 * @param array<string, mixed> $state
	 */
	private static function rebuilt_root_html( string $chunk, string $name, array $attrs, array $classes, array &$state, bool $content ): string {
		if ( preg_match( '/^(\s*)(<[a-z][a-z0-9-]*\b[^>]*?\s*\/?>)/i', $chunk, $m ) !== 1 ) {
			return self::root_html( $chunk, $classes, $state, $content );
		}
		$rest = substr( $chunk, strlen( $m[0] ) );

		return $m[1] . self::rewrite_root_tag( $m[2], $name, $attrs, array(), $classes ) . ( $content ? self::inner_html( $rest, $state, false ) : $rest );
	}

	/**
	 * dxai-ui/svg. Its root attributes are an ordered list of pairs that its
	 * save() writes in order, so a `style` pair becomes a `class` pair in the
	 * same place (or joins the class pair it already has), with its CSS kept
	 * in the block's INNER_ATTR (the caller adds it). The children string is hoisted
	 * like any other markup. The stored HTML gets the same edits, so the
	 * block still matches what save() writes.
	 *
	 * @param array<string, mixed> $block
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	private static function hoist_svg( array $block, array &$state, bool &$changed ): array {
		$attrs    = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$pairs    = is_array( $attrs['svgAttrs'] ?? null ) ? array_values( $attrs['svgAttrs'] ) : array();
		$css      = null;
		$style_at = -1;
		$class_at = -1;
		foreach ( $pairs as $k => $pair ) {
			if ( ! is_array( $pair ) || count( $pair ) < 2 ) {
				continue;
			}
			$attr = strtolower( (string) $pair[0] );
			if ( $attr === 'style' && $style_at < 0 ) {
				$css      = (string) $pair[1];
				$style_at = $k;
			} elseif ( $attr === 'class' && $class_at < 0 ) {
				$class_at = $k;
			}
		}
		$inner     = is_string( $attrs['svgInner'] ?? null ) ? $attrs['svgInner'] : '';
		$new_inner = $inner === '' ? '' : self::inner_html( $inner, $state, false );
		if ( $css === null && $new_inner === $inner ) {
			return $block;
		}

		$class = '';
		if ( $css !== null ) {
			if ( trim( $css ) === '' ) {
				array_splice( $pairs, $style_at, 1 );
			} else {
				$class                    = self::css_class( $css );
				$state['inner'][ $class ] = $css;
				if ( $class_at > -1 ) {
					$pairs[ $class_at ][1] = trim( (string) $pairs[ $class_at ][1] . ' ' . $class );
					array_splice( $pairs, $style_at, 1 );
				} else {
					$pairs[ $style_at ] = array( 'class', $class );
				}
			}
			$attrs['svgAttrs'] = $pairs;
		}
		if ( $new_inner !== $inner ) {
			$attrs['svgInner'] = $new_inner;
		}
		$block['attrs'] = $attrs;

		foreach ( (array) ( $block['innerContent'] ?? array() ) as $j => $chunk ) {
			if ( ! is_string( $chunk ) || preg_match( '/^(\s*<svg)(\b[^>]*?)(\s*\/?>)/i', $chunk, $m ) !== 1 ) {
				continue;
			}
			$root = $m[2];
			if ( $css !== null ) {
				if ( $class === '' ) {
					$root = (string) preg_replace( '/\sstyle=("[^"]*"|\'[^\']*\')/i', '', $root, 1 );
				} elseif ( $class_at > -1 ) {
					$root = self::with_classes( (string) preg_replace( '/\sstyle=("[^"]*"|\'[^\']*\')/i', '', $root, 1 ), array( $class ) );
				} else {
					$root = (string) preg_replace( '/\sstyle=("[^"]*"|\'[^\']*\')/i', ' class="' . esc_attr( $class ) . '"', $root, 1 );
				}
			}
			$rest = substr( $chunk, strlen( $m[0] ) );
			if ( $new_inner !== $inner ) {
				$rest = self::inner_html( $rest, $state, false );
			}
			$block['innerContent'][ $j ] = $m[1] . $root . $m[3] . $rest;
			break;
		}
		if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
			$block['innerHTML'] = implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) );
		}
		++$state['blocks'];
		$changed = true;

		return $block;
	}

	/**
	 * Block attributes with $inner (class => declarations) added to INNER_ATTR.
	 *
	 * @param array<string, mixed>  $attrs
	 * @param array<string, string> $inner
	 * @return array<string, mixed>
	 */
	private static function with_inner( array $attrs, array $inner ): array {
		$have                      = is_array( $attrs[ self::INNER_ATTR ] ?? null ) ? $attrs[ self::INNER_ATTR ] : array();
		$attrs[ self::INNER_ATTR ] = array_merge( $have, $inner );

		return $attrs;
	}

	/**
	 * Which attribute holds the block's own CSS, '' when it has none.
	 *
	 * @param array<string, mixed> $attrs
	 */
	private static function source_attr( string $name, array $attrs ): string {
		if ( isset( $attrs['dxaiStyle'] ) && is_string( $attrs['dxaiStyle'] ) && trim( $attrs['dxaiStyle'] ) !== '' ) {
			return 'dxaiStyle';
		}
		if ( $name === 'dxai-ui/link' && isset( $attrs['style'] ) && is_string( $attrs['style'] ) && trim( $attrs['style'] ) !== '' ) {
			return 'style';
		}

		return '';
	}

	/**
	 * The block's opening tag without its style attribute and with $classes
	 * appended to its class list; the rest of the chunk goes through the
	 * inner pass.
	 *
	 * @param array<int, string>   $classes
	 * @param array<string, mixed> $state
	 */
	private static function root_html( string $chunk, array $classes, array &$state, bool $content ): string {
		if ( preg_match( '/^(\s*<[a-z][a-z0-9-]*)(\b[^>]*?)(\s*\/?>)/i', $chunk, $m ) !== 1 ) {
			return $content ? self::inner_html( $chunk, $state, false ) : $chunk;
		}
		$attrs = (string) preg_replace( '/\sstyle=("[^"]*"|\'[^\']*\')/i', '', $m[2], 1 );
		$attrs = self::with_classes( $attrs, $classes );
		$rest  = substr( $chunk, strlen( $m[0] ) );

		return $m[1] . $attrs . $m[3] . ( $content ? self::inner_html( $rest, $state, false ) : $rest );
	}

	/**
	 * A block's first chunk when its own style did not come from an
	 * attribute this class moves: the root tag is left exactly as the
	 * block's save() writes it (core's style supports put `style=""` there
	 * from the block's `style` object), and what follows it is hoisted only
	 * when it is content.
	 *
	 * @param array<string, mixed> $state
	 */
	private static function past_root( string $chunk, array &$state, bool $content ): string {
		if ( ! $content ) {
			return $chunk;
		}
		if ( preg_match( '/^\s*<[a-z][a-z0-9-]*\b[^>]*?\s*\/?>/i', $chunk, $m ) !== 1 ) {
			return self::inner_html( $chunk, $state, false );
		}

		return $m[0] . self::inner_html( substr( $chunk, strlen( $m[0] ) ), $state, false );
	}

	/**
	 * Every `style="…"` in a fragment of HTML, as a class.
	 *
	 * @param array<string, mixed> $state
	 */
	private static function inner_html( string $html, array &$state, bool $unused ): string {
		if ( ! str_contains( $html, 'style=' ) ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'/<([a-z][a-z0-9-]*)(\b[^>]*?)(\s*\/?>)/i',
			static function ( array $m ) use ( &$state ): string {
				if ( preg_match( '/\sstyle=("([^"]*)"|\'([^\']*)\')/i', $m[2], $s ) !== 1 ) {
					return $m[0];
				}
				$css = html_entity_decode( $s[1][0] === '"' ? $s[2] : ( $s[3] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$attrs = (string) preg_replace( '/\sstyle=("[^"]*"|\'[^\']*\')/i', '', $m[2], 1 );
				if ( trim( $css ) === '' ) {
					return '<' . $m[1] . $attrs . $m[3];
				}
				$class                    = self::css_class( $css );
				$state['inner'][ $class ] = $css;

				return '<' . $m[1] . self::with_classes( $attrs, array( $class ) ) . $m[3];
			},
			$html
		);
	}

	/**
	 * An attribute string with $classes appended to its class attribute
	 * (added at the end when there is none).
	 *
	 * @param array<int, string> $classes
	 */
	private static function with_classes( string $attrs, array $classes ): string {
		if ( $classes === array() ) {
			return $attrs;
		}
		$add = esc_attr( implode( ' ', $classes ) );
		if ( preg_match( '/\sclass=("([^"]*)"|\'([^\']*)\')/i', $attrs, $c ) === 1 ) {
			$have = $c[1][0] === '"' ? $c[2] : ( $c[3] ?? '' );
			$list = trim( $have . ' ' . $add );

			return str_replace( $c[0], ' class="' . $list . '"', $attrs );
		}

		return $attrs . ' class="' . $add . '"';
	}

	/**
	 * The preset slug of the declaration list's text colour, when it is
	 * exactly `color:var(--dxai-{slug})` and that slug is a preset.
	 *
	 * @param array<string, bool> $slugs
	 */
	private static function preset_color( string $css, array $slugs ): string {
		if ( $slugs === array() ) {
			return '';
		}
		if ( preg_match( '/(?:^|;)\s*color\s*:\s*var\(\s*--dxai-([a-z0-9-]+)\s*\)\s*(?:;|$)/i', $css, $m ) !== 1 ) {
			return '';
		}

		return isset( $slugs[ $m[1] ] ) ? $m[1] : '';
	}

	/**
	 * The declaration list without one property's declarations.
	 */
	private static function without( string $css, string $property ): string {
		$keep = array();
		foreach ( self::parts( $css ) as $part ) {
			$name = strtolower( trim( (string) strstr( $part, ':', true ) ) );
			if ( $name !== $property ) {
				$keep[] = $part;
			}
		}

		return implode( ';', $keep );
	}

	/**
	 * A declaration list split on the semicolons that end declarations —
	 * not those inside parentheses or quotes (a data: URI has one) — each
	 * part kept exactly as written, trimmed.
	 *
	 * @return array<int, string>
	 */
	private static function parts( string $css ): array {
		$out   = array();
		$depth = 0;
		$quote = '';
		$start = 0;
		$len   = strlen( $css );
		for ( $i = 0; $i <= $len; $i++ ) {
			$ch = $i < $len ? $css[ $i ] : ';';
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
				$part = trim( substr( $css, $start, $i - $start ) );
				if ( $part !== '' ) {
					$out[] = $part;
				}
				$start = $i + 1;
			}
		}

		return $out;
	}
}

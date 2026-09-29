<?php
/**
 * Model-written block markup, made to match what each block's save() writes.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

/**
 * The shapes a language model writes that the block editor refuses to open.
 *
 * Live_Page_Restyler stores what the model wrote after three checks —
 * Gutenberg_Mapper::validate() (structure), Token_Styles::snap_markup()
 * (colours) and sanitize() (KSES plus known block names). None of them asks
 * the question the editor asks the moment a page is opened: does this block's
 * save(), run on its attributes, write the HTML that is stored? Where it does
 * not, the block opens as "unexpected or invalid content", and the editor's
 * own "Attempt recovery" rebuilds it from the attributes — which, for every
 * shape below, throws away the styling the visitor was being shown.
 *
 * Measured on the 15 crawled pages of the development site (export of
 * 2026-09-24, 8,845 blocks over 56 posts). WordPress 7.1's own parser and
 * validator, run without a browser over the stored content (the real
 * block-library and block-editor bundles, assets/js/blocks-editor.js as
 * shipped), found 15 invalid blocks on 6 pages; a validator that emulates
 * save() without deprecations found 15 on 7. Every one was written by the
 * model, none by KSES. Between them, 21 blocks of these shapes:
 *
 *   - an HTML comment inside a block's own markup (`<!-- HERO SECTION -->`
 *     between a group's children): 5 core/group. save() never writes one;
 *   - core/heading without `wp-block-heading` (4) and core/list without
 *     `wp-block-list` (1, also one of the next two), the class save()
 *     always writes;
 *   - a core/list whose `<li>` rows are raw HTML rather than core/list-item
 *     blocks (2), the pre-6.1 list shape;
 *   - a core/group root carrying `data-screen-label` with no `dxaiData`
 *     attribute to hold it (1);
 *   - dxai-ui/form, a dynamic block whose save() returns null, stored with
 *     markup inside it (2);
 *   - dxai-ui/image whose `alt` attribute and HTML alt disagree (1);
 *   - core/image (3), core/columns (1) and core/column (2) carrying
 *     `dxaiStyle`, which the editor registers only on paragraph, heading,
 *     group, list and list-item — so for these three it is dropped on parse
 *     and save() writes none of that CSS.
 * The deprecation-less validator also flags the headings and the raw-`<li>`
 * lists; the real editor matches them against an old version of the block
 * and migrates them, which still leaves the post modified the moment it
 * opens — and the list migration drops each row's own `style=""`.
 *
 * After this pass over the same export: 1 invalid block left by the real
 * editor (the flex column below, left on purpose) and 0 by the emulating
 * validator; the 41 posts that are not crawled pages (design pages, template
 * parts) come back byte-identical; and rendered through do_blocks(), six of
 * the seven changed pages produce the same tags, attributes and text, the
 * seventh differing only by the equivalences documented on core_image(),
 * columns() and column().
 *
 * Every fix treats the stored HTML as the truth, because it is what visitors
 * are served — a static block renders its stored HTML — and moves the
 * block's attributes to match it, or rewrites the HTML only into an
 * equivalent spelling. Equivalent means on the page these blocks are
 * actually served on: Site_From_Menu puts every crawled page on the DXAI
 * blank template, which dequeues the `global-styles` sheet and so every
 * base layout rule (`body .is-layout-flex { display: flex }` among them)
 * while still printing the palette's `.has-{slug}-color` classes. Where
 * equivalence cannot be shown (a flex column, a column with a margin, an
 * image with a border) the block is left exactly as it was: an invalid block
 * is recoverable in the editor, a silently re-styled one is not.
 *
 * A document nothing matched is returned as the very string it came in as;
 * serialize_blocks() only runs when a block was changed. Block delimiters are
 * never edited as text — only parse_blocks() and serialize_blocks() write
 * them. Every fix removes its own trigger, so a second pass changes nothing.
 */
final class Block_Normalizer {

	/**
	 * Blocks whose save() writes a raw payload. For core/html and
	 * dxai-ui/html the payload IS the stored markup, so a comment in it is
	 * content the block reproduces; dxai-ui/svg writes `svgInner` verbatim,
	 * so a comment there is in the attribute as well, and removing only the
	 * HTML copy would break a valid block. The rest keep their text as text.
	 */
	private const RAW_BLOCKS = array(
		'core/html',
		'core/freeform',
		'core/shortcode',
		'core/code',
		'core/preformatted',
		'core/verse',
		'dxai-ui/html',
		'dxai-ui/svg',
	);

	/** The class core's save() generates, and the root tags it can go on. */
	private const DEFAULT_CLASS = array(
		'core/heading' => array( 'wp-block-heading', array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ) ),
		'core/list'    => array( 'wp-block-list', array( 'UL', 'OL' ) ),
	);

	/**
	 * Dynamic blocks whose editor save() returns null (assets/js/blocks-editor.js
	 * registers dxai-ui/form with `save: function () { return null; }`), so the
	 * only markup that validates is none at all.
	 */
	private const VOID_BLOCKS = array( 'dxai-ui/form' );

	/**
	 * Blocks the editor gives a `dxaiData` attribute and whose save() writes
	 * each `data-` key of it back onto the root (the dxai-ui/group-a11y and
	 * dxai-ui/list-item-data filters in assets/js/blocks-editor.js).
	 */
	private const DATA_BLOCKS = array( 'core/group', 'core/list', 'core/list-item' );

	/**
	 * core/image's own attributes for the declarations a model puts on its
	 * <img>. Mirrors Html_To_Blocks::IMAGE_STYLE_ATTRS: save() writes each of
	 * them back into the image's style="".
	 */
	private const IMAGE_STYLE_ATTRS = array(
		'aspect-ratio' => 'aspectRatio',
		'object-fit'   => 'scale',
		'width'        => 'width',
		'height'       => 'height',
	);

	/**
	 * The attributes core/image's save() writes on the <img> from something
	 * other than a flag. `role` is written only for `isDecorative`, and is
	 * checked on its own.
	 */
	private const IMAGE_TAG_ATTRS = array( 'src', 'alt', 'class', 'style', 'title' );

	/** `align-items` on core/columns, as its verticalAlignment attribute. */
	private const COLUMNS_ALIGN = array(
		'flex-start' => 'top',
		'start'      => 'top',
		'center'     => 'center',
		'flex-end'   => 'bottom',
		'end'        => 'bottom',
	);

	/** Preset and style attributes whose classes and CSS these fixes do not model. */
	private const UNMODELED_ATTRS = array( 'style', 'layout', 'backgroundColor', 'textColor', 'gradient', 'borderColor', 'fontSize', 'fontFamily', 'shadow' );

	/** CSS border-style keywords, to tell a `border` shorthand's parts apart. */
	private const BORDER_STYLES = array( 'none', 'hidden', 'dotted', 'dashed', 'solid', 'double', 'groove', 'ridge', 'inset', 'outset' );

	private const SIDES = array( 'top', 'right', 'bottom', 'left' );

	/**
	 * An HTML comment that does not look like a block delimiter. `<!-->` and
	 * `<!--->` are complete comments in HTML, so they are matched as such
	 * rather than read as the opening of one that runs to the next `-->`.
	 */
	private const COMMENT = '<!--(?!\s*\/?wp:)(?:>|->|[\s\S]*?-->)';

	/**
	 * The document with every fixable block fixed, or the input string itself
	 * when nothing needed it.
	 */
	public static function normalize( string $markup ): string {
		if ( $markup === '' || ! str_contains( $markup, '<!-- wp:' ) ) {
			return $markup;
		}
		$fixed  = 0;
		$blocks = self::normalize_blocks( parse_blocks( $markup ), $fixed );

		return $fixed === 0 ? $markup : serialize_blocks( $blocks );
	}

	/**
	 * The same, over blocks already parsed — for a caller that holds the tree
	 * and serialises once at the end (Style_Repair::repair_post()).
	 *
	 * @param array<int, array<string, mixed>> $blocks parse_blocks() output.
	 * @param int                              $fixed  Incremented once per block changed.
	 * @return array<int, array<string, mixed>>
	 */
	public static function normalize_blocks( array $blocks, int &$fixed ): array {
		foreach ( $blocks as $i => $block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::normalize_blocks( $block['innerBlocks'], $fixed );
			}
			$changed = false;
			$block   = self::normalize_block( $block, $changed );
			if ( $changed ) {
				++$fixed;
			}
			$blocks[ $i ] = $block;
		}

		return $blocks;
	}

	/**
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function normalize_block( array $block, bool &$changed ): array {
		$name           = (string) $block['blockName'];
		$block['attrs'] = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();

		if ( in_array( $name, self::VOID_BLOCKS, true ) ) {
			return self::void_dynamic( $block, $changed );
		}
		if ( ! in_array( $name, self::RAW_BLOCKS, true ) ) {
			$block = self::strip_comments( $block, $changed );
		}
		if ( isset( self::DEFAULT_CLASS[ $name ] ) ) {
			$block = self::default_class( $block, self::DEFAULT_CLASS[ $name ][0], self::DEFAULT_CLASS[ $name ][1], $changed );
		}
		if ( $name === 'core/list' ) {
			$block = self::list_items( $block, $changed );
		}
		if ( in_array( $name, self::DATA_BLOCKS, true ) ) {
			$block = self::sync_data( $block, $changed );
		}

		switch ( $name ) {
			case 'dxai-ui/image':
				return self::image_alt( $block, $changed );
			case 'core/image':
				return self::core_image( $block, $changed );
			case 'core/columns':
				return self::columns( $block, $changed );
			case 'core/column':
				return self::column( $block, $changed );
		}

		return $block;
	}

	/**
	 * HTML comments out of the block's own markup — the strings between and
	 * around its children, never a child's and never a delimiter, which
	 * parse_blocks() has already taken out of innerContent.
	 *
	 * The model annotates its sections (`<!-- CONTENT SECTION (using Home
	 * section styling patterns…) -->`), and the tokenizer the validator runs
	 * reports a comment as a token save() did not write: five sections on
	 * three pages opened invalid for a note nobody sees. A comment renders
	 * nothing, so removing it changes nothing a visitor gets.
	 *
	 * Skipped where `<!--` is not a comment or is content: inside a raw-text
	 * element (script, style, textarea…), and in a block whose attributes
	 * hold the same comment, because save() would write it back. On
	 * WordPress 6.5 and later each edited chunk is also re-read with core's
	 * own HTML tokenizer, and kept only if every token other than a comment —
	 * tags, attributes, text — reads the same before and after; a `<!--`
	 * the pattern took for a comment but the tokenizer did not (inside an
	 * attribute value, say) leaves that chunk as it was.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function strip_comments( array $block, bool &$changed ): array {
		if ( ! str_contains( (string) ( $block['innerHTML'] ?? '' ), '<!--' ) || self::attrs_contain( $block['attrs'], '<!--' ) ) {
			return $block;
		}

		$content = (array) ( $block['innerContent'] ?? array() );
		$touched = false;
		foreach ( $content as $n => $chunk ) {
			if ( ! is_string( $chunk ) || ! str_contains( $chunk, '<!--' ) ) {
				continue;
			}
			if ( preg_match( '/<(?:script|style|textarea|title|xmp|iframe|noembed|noframes|noscript|plaintext)\b/i', $chunk ) === 1 ) {
				continue;
			}
			// A comment on a line of its own takes the line with it; one
			// inside a run of text leaves the text around it exactly as it was.
			$new = preg_replace( '/^[ \t]*' . self::COMMENT . '[ \t]*(?:\r?\n)?/m', '', $chunk );
			$new = is_string( $new ) ? preg_replace( '/' . self::COMMENT . '/', '', $new ) : null;
			// null: a PCRE failure, which must not turn the chunk into ''.
			if ( ! is_string( $new ) || $new === $chunk || ! self::same_without_comments( $chunk, $new ) ) {
				continue;
			}
			$content[ $n ] = $new;
			$touched       = true;
		}
		if ( ! $touched ) {
			return $block;
		}
		$changed = true;

		return self::with_content( $block, $content );
	}

	/**
	 * Whether two fragments hold the same tags, attributes and text once
	 * comments are set aside (runs of whitespace compared as one space, as
	 * the validator compares them). False on WordPress 6.4, whose tag
	 * processor has no token walker to ask: without it a `<!--` inside an
	 * attribute value cannot be told from a comment, so nothing is stripped.
	 */
	private static function same_without_comments( string $before, string $after ): bool {
		$a = self::token_signature( $before );

		return $a !== null && $a === self::token_signature( $after );
	}

	/**
	 * A fragment's tokens other than comments, as one string; null when the
	 * tag processor cannot walk tokens (before WordPress 6.5).
	 */
	private static function token_signature( string $html ): ?string {
		$p = new \WP_HTML_Tag_Processor( $html );
		if ( ! method_exists( $p, 'next_token' ) || ! method_exists( $p, 'get_token_type' ) ) {
			return null;
		}
		$sig = '';
		while ( $p->next_token() ) {
			$type = (string) $p->get_token_type();
			if ( $type === '#comment' ) {
				continue;
			}
			if ( $type === '#text' ) {
				$sig .= (string) $p->get_modifiable_text();
				continue;
			}
			if ( $type !== '#tag' ) {
				$sig .= '<' . $type . '>';
				continue;
			}
			$sig .= '<' . ( $p->is_tag_closer() ? '/' : '' ) . $p->get_tag();
			foreach ( (array) $p->get_attribute_names_with_prefix( '' ) as $name ) {
				$value = $p->get_attribute( $name );
				$sig  .= ' ' . $name . '=' . ( is_string( $value ) ? $value : '' );
			}
			$sig .= '>' . (string) $p->get_modifiable_text();
		}
		if ( method_exists( $p, 'paused_at_incomplete_token' ) && $p->paused_at_incomplete_token() ) {
			$sig .= '<#incomplete>';
		}

		return (string) preg_replace( '/\s+/', ' ', $sig );
	}

	/**
	 * core's generated class on a root tag the model wrote without it.
	 *
	 * core/heading and core/list both declare `className` support, so save()
	 * always writes `wp-block-heading` / `wp-block-list`; 422 of the 426
	 * headings on these pages carry it and the other four were invalid. On
	 * the page nothing changes, because the class was already being served:
	 * core's own render callbacks, block_core_heading_render() and
	 * block_core_list_render(), add it to a stored root that lacks it —
	 * rendered through do_blocks(), the five blocks produce the same HTML
	 * before and after this fix.
	 *
	 * @param array<string, mixed> $block
	 * @param array<int, string>   $tags Upper-case tag names the root may have.
	 * @return array<string, mixed>
	 */
	private static function default_class( array $block, string $class, array $tags, bool &$changed ): array {
		$n = self::root_index( $block );
		if ( $n === null ) {
			return $block;
		}
		$p = new \WP_HTML_Tag_Processor( (string) $block['innerContent'][ $n ] );
		if ( ! $p->next_tag() || ! in_array( $p->get_tag(), $tags, true ) || $p->has_class( $class ) ) {
			return $block;
		}
		$p->add_class( $class );
		$content       = (array) $block['innerContent'];
		$content[ $n ] = $p->get_updated_html();
		$changed       = true;

		return self::with_content( $block, $content );
	}

	/**
	 * A core/list whose rows are raw `<li>` HTML, given core/list-item blocks.
	 *
	 * core/list has held its rows as core/list-item inner blocks since
	 * WordPress 6.1; save() writes InnerBlocks.Content between the tags and
	 * nothing else, so a list written with bare `<li>`s inside it only opens
	 * through a deprecation. That migration rebuilds each row from its text
	 * alone: on post 88091 it would have dropped the
	 * `style="display:flex;align-items:center;gap:10px"` every check-mark row
	 * is laid out by, the first time anyone saved the page.
	 *
	 * Each row becomes the list-item block whose save() writes that same
	 * `<li>`: its `style` as `dxaiStyle`, its `class` as `className`, its
	 * `data-*` as `dxaiData` (all three registered on core/list-item by
	 * assets/js/blocks-editor.js, and the shape Html_To_Blocks already writes),
	 * its `id` read back by the anchor support. The row's HTML is kept
	 * verbatim as the item's own markup and the whitespace between rows stays
	 * in the list's, so the page's HTML is byte-identical once the block
	 * comments are removed.
	 *
	 * Only flat rows are converted: a row holding a nested list, or carrying
	 * any other attribute (`value`, `aria-*`, `title`…), leaves the list alone.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function list_items( array $block, bool &$changed ): array {
		$content = (array) ( $block['innerContent'] ?? array() );
		if ( ! empty( $block['innerBlocks'] ) || count( $content ) !== 1 || ! is_string( $content[0] ) ) {
			return $block;
		}
		if ( preg_match( '/^(\s*<(ul|ol)\b[^>]*>)([\s\S]*?)(<\/\2>\s*)$/i', $content[0], $m ) !== 1 ) {
			return $block;
		}
		$row = '<li\b[^>]*>(?:(?!<\/?(?:li|ul|ol)\b)[\s\S])*<\/li>';
		if ( preg_match( '/^(?:\s*' . $row . ')+\s*$/i', $m[3] ) !== 1 ) {
			return $block;
		}
		preg_match_all( '/(\s*)(' . $row . ')/i', $m[3], $rows, PREG_SET_ORDER );

		$inner    = array();
		$chunks   = array( $m[1] );
		$consumed = 0;
		foreach ( $rows as $r ) {
			$attrs = self::row_attrs( $r[2] );
			if ( $attrs === null ) {
				return $block;
			}
			$chunks[ count( $chunks ) - 1 ] .= $r[1];
			$inner[]   = array(
				'blockName'    => 'core/list-item',
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => $r[2],
				'innerContent' => array( $r[2] ),
			);
			$chunks[]  = null;
			$chunks[]  = '';
			$consumed += strlen( $r[0] );
		}
		$chunks[ count( $chunks ) - 1 ] .= substr( $m[3], $consumed ) . $m[4];

		// parse_blocks() never records an empty string between two children.
		$chunks               = array_values( array_filter( $chunks, static fn( $c ) => $c === null || $c !== '' ) );
		$block['innerBlocks'] = $inner;
		$changed              = true;

		return self::with_content( $block, $chunks );
	}

	/**
	 * A raw `<li>`'s attributes as core/list-item's, or null when it has one
	 * the block cannot write back.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function row_attrs( string $row ): ?array {
		$p = new \WP_HTML_Tag_Processor( $row );
		if ( ! $p->next_tag() || $p->get_tag() !== 'LI' ) {
			return null;
		}
		$attrs = array();
		$data  = array();
		foreach ( (array) $p->get_attribute_names_with_prefix( '' ) as $name ) {
			$value = $p->get_attribute( $name );
			$value = is_string( $value ) ? $value : '';
			if ( $name === 'id' ) {
				continue;
			}
			if ( str_starts_with( $name, 'data-' ) ) {
				$data[ $name ] = $value;
				continue;
			}
			// save() skips an empty value, so an empty style="" or class=""
			// could never be written back.
			if ( trim( $value ) === '' ) {
				return null;
			}
			if ( $name === 'style' ) {
				$attrs['dxaiStyle'] = $value;
			} elseif ( $name === 'class' ) {
				$attrs['className'] = trim( (string) preg_replace( '/\s+/', ' ', $value ) );
			} else {
				return null;
			}
		}
		if ( $data !== array() ) {
			$attrs['dxaiData'] = $data;
		}

		return $attrs;
	}

	/**
	 * A root's data-* attributes, kept in `dxaiData`.
	 *
	 * For core/group, core/list and core/list-item the editor registers
	 * `dxaiData` and save() writes each `data-` key of it back onto the root,
	 * and nothing else writes a data attribute there. A root that carries
	 * `data-screen-label` with no attribute to hold it therefore saves
	 * without it, and one whose attribute names a key the HTML lacks saves
	 * with it. The HTML is left alone; the attribute is given exactly the
	 * data attributes the HTML already has. Keys that do not start with
	 * `data-` are never written by save(), so they are kept as they are.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function sync_data( array $block, bool &$changed ): array {
		$n = self::root_index( $block );
		if ( $n === null ) {
			return $block;
		}
		$p = new \WP_HTML_Tag_Processor( (string) $block['innerContent'][ $n ] );
		if ( ! $p->next_tag() ) {
			return $block;
		}
		$data = is_array( $block['attrs']['dxaiData'] ?? null ) ? $block['attrs']['dxaiData'] : array();
		$want = array();
		foreach ( $data as $key => $value ) {
			if ( ! str_starts_with( (string) $key, 'data-' ) ) {
				$want[ $key ] = $value;
			}
		}
		foreach ( (array) $p->get_attribute_names_with_prefix( 'data-' ) as $name ) {
			$value         = $p->get_attribute( $name );
			$want[ $name ] = is_string( $value ) ? $value : '';
		}
		$a = $want;
		$b = $data;
		ksort( $a );
		ksort( $b );
		if ( $a === $b ) {
			return $block;
		}
		if ( $want === array() ) {
			unset( $block['attrs']['dxaiData'] );
		} else {
			$block['attrs']['dxaiData'] = $want;
		}
		$changed = true;

		return $block;
	}

	/**
	 * dxai-ui/image's `alt` attribute, set to the alt its HTML carries.
	 *
	 * save() writes the attribute (dxaiImageProps() in blocks-editor.js); the
	 * block is static, so what visitors get is the stored HTML. The model wrote
	 * two different texts — a long alt in the attribute, a short one in the
	 * tag — and the one being served is the tag's, so that is the one kept.
	 *
	 * An empty `alt=""` is left alone: save() skips an empty value, so no
	 * attribute can reproduce it, and removing it from the HTML would turn a
	 * decorative image into one a screen reader announces by file name.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function image_alt( array $block, bool &$changed ): array {
		$n = self::root_index( $block );
		if ( $n === null ) {
			return $block;
		}
		$p = new \WP_HTML_Tag_Processor( (string) $block['innerContent'][ $n ] );
		if ( ! $p->next_tag() || $p->get_tag() !== 'IMG' ) {
			return $block;
		}
		$html = $p->get_attribute( 'alt' );
		// The model can write `"alt":["x"]`; casting that raises a warning
		// that would land in the crawl job's JSON response.
		$attr = is_string( $block['attrs']['alt'] ?? null ) ? $block['attrs']['alt'] : '';
		if ( $html === null ) {
			if ( $attr === '' ) {
				return $block;
			}
			unset( $block['attrs']['alt'] );
			$changed = true;

			return $block;
		}
		$html = is_string( $html ) ? $html : '';
		if ( $html === '' || $html === $attr ) {
			return $block;
		}
		$block['attrs']['alt'] = $html;
		$changed               = true;

		return $block;
	}

	/**
	 * A dynamic block whose save() returns null, stored with no markup.
	 *
	 * dxai-ui/form's render callback (Form_Block::render()) builds the form
	 * from `fields`, `submitLabel`, `successMessage` and `className`, and
	 * unsets the stored content before it does anything — so the wrapper the
	 * model wrote (`<div id="lead-form" style="…">`, a comment inside) has
	 * never reached a visitor, and emptying it changes nothing on the front
	 * end. Attributes the block type does not register (`formId`,
	 * `dxaiStyle`) are read by nothing, on either side, and go with it; the
	 * PHP and editor registrations list the same four, so the form itself
	 * cannot be lost. When the block type is not registered yet (called
	 * before `init`) every attribute is kept, since there is then nothing to
	 * tell them apart by.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function void_dynamic( array $block, bool &$changed ): array {
		if ( trim( (string) ( $block['innerHTML'] ?? '' ) ) === '' || ! empty( $block['innerBlocks'] ) ) {
			return $block;
		}
		$block['innerHTML']    = '';
		$block['innerContent'] = array();

		$type = class_exists( '\WP_Block_Type_Registry' )
			? \WP_Block_Type_Registry::get_instance()->get_registered( (string) $block['blockName'] )
			: null;
		if ( $type instanceof \WP_Block_Type && is_array( $type->attributes ) && $type->attributes !== array() ) {
			$block['attrs'] = array_intersect_key( $block['attrs'], $type->attributes );
		}
		$changed = true;

		return $block;
	}

	/**
	 * core/image with `dxaiStyle` or an id its <img> does not claim, moved
	 * onto core/image's own attributes.
	 *
	 * The shape measured, three times on one page:
	 *
	 *   {"id":1,"sizeSlug":"large","dxaiStyle":"margin:0;padding:0"}
	 *   <figure class="wp-block-image size-large" style="margin:0;padding:0">
	 *     <img src="…" alt="…" style="width:100%;height:180px;object-fit:cover;display:block"/>
	 *
	 * The editor drops `dxaiStyle` (not registered for core/image), so save()
	 * wrote no figure style, a `wp-image-1` class the <img> does not have, and
	 * no img style. Every declaration here has a registered home that renders
	 * the same thing, which is why this moves them rather than deleting them:
	 * deleting `height:180px;object-fit:cover` would turn three cropped card
	 * images into full-height ones.
	 *
	 *   - figure `margin` becomes `style.spacing.margin` (core/image supports
	 *     margin), written by save() as the four longhands — the same box;
	 *   - figure `padding` of zero is dropped: core/image has no padding
	 *     support, and zero is what a figure has anyway;
	 *   - img `width`, `height`, `object-fit`, `aspect-ratio` become `width`,
	 *     `height`, `scale`, `aspectRatio`, which save() writes back into the
	 *     img style (a lone width gets `height:auto`, as save() writes it), and
	 *     the figure gets the `is-resized` class save() adds with them — no
	 *     stylesheet core or this plugin loads has a rule for it;
	 *   - img `display:block` is dropped only beside `width:100%`: the blank
	 *     canvas already makes `figure.wp-block-image > img` a block, and
	 *     elsewhere core's `.wp-block-image img { vertical-align: bottom }`
	 *     leaves no line gap and a full-width image has no slack for
	 *     text-align to move;
	 *   - an `id` with no matching `wp-image-{id}` on the <img> is removed. The
	 *     stored HTML never claimed it, so the front end is unchanged; the ids
	 *     measured (1, 2, 3) were a post and two pages, not attachments, and
	 *     keeping them would point the editor's media panel at the wrong item.
	 * Anything else — a border, an unknown img attribute, a figure declaration
	 * with no home — leaves the block as it was.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function core_image( array $block, bool &$changed ): array {
		$attrs = $block['attrs'];
		$id    = isset( $attrs['id'] ) && is_numeric( $attrs['id'] ) ? (int) $attrs['id'] : 0;
		$n     = self::root_index( $block );
		if ( $n === null || ! empty( $block['innerBlocks'] ) ) {
			return $block;
		}
		$chunk = (string) $block['innerContent'][ $n ];
		$p     = new \WP_HTML_Tag_Processor( $chunk );
		if ( ! $p->next_tag() || $p->get_tag() !== 'FIGURE' ) {
			return $block;
		}
		$figure_style   = self::attr_string( $p, 'style' );
		$figure_classes = self::classes( $p );
		if ( ! $p->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			return $block;
		}
		$img_classes = self::classes( $p );
		$fake_id     = $id > 0 && ! in_array( 'wp-image-' . $id, $img_classes, true );
		if ( ! array_key_exists( 'dxaiStyle', $attrs ) && ! $fake_id ) {
			return $block;
		}
		foreach ( array( 'style', 'borderColor', 'focalPoint', 'shadow' ) as $key ) {
			if ( isset( $attrs[ $key ] ) ) {
				return $block;
			}
		}
		foreach ( (array) $p->get_attribute_names_with_prefix( '' ) as $name ) {
			if ( $name === 'role' && ! empty( $attrs['isDecorative'] ) && $p->get_attribute( 'role' ) === 'none' ) {
				continue;
			}
			if ( ! in_array( $name, self::IMAGE_TAG_ATTRS, true ) ) {
				return $block;
			}
		}
		$own_class = ( $id > 0 && ! $fake_id ) ? 'wp-image-' . $id : '';
		foreach ( $img_classes as $class ) {
			if ( $class !== $own_class ) {
				return $block;
			}
		}

		$figure = self::declarations( $figure_style );
		$img    = self::declarations( self::attr_string( $p, 'style' ) );
		if ( $figure === null || $img === null ) {
			return $block;
		}
		$margin = array();
		foreach ( $figure as $prop => $value ) {
			if ( str_contains( $value, '!' ) ) {
				return $block;
			}
			if ( $prop === 'margin' ) {
				$sides = self::box_sides( $value );
				if ( $sides === null ) {
					return $block;
				}
				$margin = array_merge( $margin, $sides );
				continue;
			}
			if ( preg_match( '/^margin-(top|right|bottom|left)$/', $prop, $side ) === 1 && count( self::tokens( $value ) ) === 1 ) {
				$margin[ $side[1] ] = $value;
				continue;
			}
			if ( preg_match( '/^padding(?:-(?:top|right|bottom|left))?$/', $prop ) === 1 && self::all_zero( $value ) ) {
				continue;
			}

			return $block;
		}
		$sized   = array();
		$display = false;
		foreach ( $img as $prop => $value ) {
			if ( str_contains( $value, '!' ) ) {
				return $block;
			}
			if ( isset( self::IMAGE_STYLE_ATTRS[ $prop ] ) ) {
				$sized[ self::IMAGE_STYLE_ATTRS[ $prop ] ] = $value;
				continue;
			}
			if ( $prop === 'display' && strtolower( $value ) === 'block' ) {
				$display = true;
				continue;
			}

			return $block;
		}
		if ( $display && ( $sized['width'] ?? '' ) !== '100%' ) {
			return $block;
		}
		// save() writes `height:auto` beside a lone width, so a stored image
		// with a width and no height is only reproducible when that is so.
		if ( isset( $sized['width'] ) && ! isset( $sized['height'] ) ) {
			return $block;
		}

		// The attributes, as the HTML says they are.
		unset( $attrs['dxaiStyle'] );
		if ( $fake_id ) {
			unset( $attrs['id'] );
		}
		foreach ( self::IMAGE_STYLE_ATTRS as $key ) {
			if ( isset( $sized[ $key ] ) ) {
				$attrs[ $key ] = $sized[ $key ];
			} else {
				unset( $attrs[ $key ] );
			}
		}
		$figure_css = array();
		$box        = array();
		foreach ( self::SIDES as $side ) {
			if ( isset( $margin[ $side ] ) ) {
				$figure_css[ 'margin-' . $side ] = $margin[ $side ];
				$box[ $side ]                    = $margin[ $side ];
			}
		}
		if ( $box !== array() ) {
			$attrs['style'] = array( 'spacing' => array( 'margin' => $box ) );
		}

		// The img style exactly as save() builds it: aspect-ratio, object-fit,
		// then width and height.
		$img_css = array();
		if ( isset( $sized['aspectRatio'] ) ) {
			$img_css['aspect-ratio'] = $sized['aspectRatio'];
		}
		if ( isset( $sized['scale'] ) ) {
			$img_css['object-fit'] = $sized['scale'];
		}
		if ( isset( $sized['width'] ) ) {
			$img_css['width'] = $sized['width'];
		}
		if ( isset( $sized['height'] ) ) {
			$img_css['height'] = $sized['height'];
		}

		// The figure classes save() writes for these attributes.
		$need = array( 'wp-block-image' );
		if ( ! empty( $attrs['sizeSlug'] ) && is_string( $attrs['sizeSlug'] ) ) {
			$need[] = 'size-' . $attrs['sizeSlug'];
		}
		if ( isset( $sized['width'] ) || isset( $sized['height'] ) ) {
			$need[] = 'is-resized';
		}
		if ( ! empty( $attrs['align'] ) && is_string( $attrs['align'] ) ) {
			$need[] = 'align' . $attrs['align'];
		}

		$p = new \WP_HTML_Tag_Processor( $chunk );
		$p->next_tag();
		foreach ( array_diff( $need, $figure_classes ) as $class ) {
			$p->add_class( $class );
		}
		self::put_style( $p, $figure_css );
		$p->next_tag( array( 'tag_name' => 'IMG' ) );
		self::put_style( $p, $img_css );

		$content        = (array) $block['innerContent'];
		$content[ $n ]  = $p->get_updated_html();
		$block['attrs'] = $attrs;
		$changed        = true;

		return self::with_content( $block, $content );
	}

	/**
	 * core/columns with `dxaiStyle`, moved onto core/columns' own attributes.
	 *
	 * save() writes no style="" on core/columns at all, so the measured
	 * `gap:32px;align-items:flex-start` made the block invalid. Both have a
	 * native home:
	 *
	 *   - `align-items` becomes `verticalAlignment` (the class
	 *     `are-vertically-aligned-top`). No visible change either way: core's
	 *     columns stylesheet declares `.wp-block-columns { align-items:
	 *     normal !important }`, which overrode the inline value just as it
	 *     overrides the class;
	 *   - `gap` becomes `style.spacing.blockGap`, the "Block spacing" control.
	 *     The layout support prints it as a rule on the columns' container
	 *     class — measured on post 88091:
	 *     `.wp-container-core-columns-is-layout-e54c7a8a{flex-wrap:nowrap;gap:32px;}`,
	 *     in the block-supports sheet the blank canvas keeps — and nothing
	 *     else sets a gap on these columns there, so it is the same 32px. It
	 *     prints only when the theme's global settings enable blockGap, the
	 *     condition wp_render_layout_support_flag() itself checks; where they
	 *     do not, the gap is left inline and the block as it was.
	 * Any other declaration leaves the block as it was.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function columns( array $block, bool &$changed ): array {
		$attrs = $block['attrs'];
		if ( ! array_key_exists( 'dxaiStyle', $attrs ) || self::has_unmodeled( $attrs ) ) {
			return $block;
		}
		$n = self::root_index( $block );
		if ( $n === null ) {
			return $block;
		}
		$chunk = (string) $block['innerContent'][ $n ];
		$p     = new \WP_HTML_Tag_Processor( $chunk );
		if ( ! $p->next_tag() || ! $p->has_class( 'wp-block-columns' ) ) {
			return $block;
		}
		$decls = self::declarations( self::attr_string( $p, 'style' ) );
		if ( $decls === null ) {
			return $block;
		}
		$align = null;
		$gap   = null;
		foreach ( $decls as $prop => $value ) {
			if ( str_contains( $value, '!' ) ) {
				return $block;
			}
			if ( $prop === 'align-items' && isset( self::COLUMNS_ALIGN[ strtolower( $value ) ] ) ) {
				$align = self::COLUMNS_ALIGN[ strtolower( $value ) ];
				continue;
			}
			if ( $prop === 'gap' && self::block_gap_prints() ) {
				$gap = self::block_gap( $value, true );
				if ( $gap === null ) {
					return $block;
				}
				continue;
			}

			return $block;
		}

		unset( $attrs['dxaiStyle'] );
		if ( $align !== null ) {
			$attrs['verticalAlignment'] = $align;
		}
		if ( $gap !== null ) {
			$attrs['style'] = array( 'spacing' => array( 'blockGap' => $gap ) );
		}

		$p->remove_attribute( 'style' );
		if ( ! empty( $attrs['verticalAlignment'] ) && is_string( $attrs['verticalAlignment'] ) ) {
			$p->add_class( 'are-vertically-aligned-' . $attrs['verticalAlignment'] );
		}
		$content        = (array) $block['innerContent'];
		$content[ $n ]  = $p->get_updated_html();
		$block['attrs'] = $attrs;
		$changed        = true;

		return self::with_content( $block, $content );
	}

	/**
	 * core/column with `dxaiStyle`, moved onto core/column's own supports.
	 *
	 * save() writes `flex-basis` from `width` and nothing else of the CSS, so
	 * the measured card column was invalid:
	 *
	 *   background:#FFFFFF;border:1px solid #E6E9EE;border-radius:16px;padding:32px
	 *
	 * core/column supports colour, border and padding, all serialised inline
	 * by save(), so these become `style.color.background`, `style.border` and
	 * `style.spacing.padding` and render as the same declarations in longhand
	 * (`background-color`, `padding-top`…). These are real controls in the
	 * inspector now, not an opaque string.
	 *
	 * The border is stored per side (`style.border.top` … `.left`, each with
	 * the same colour, style and width), which save() writes as
	 * `border-top-color`… — the same box as the shorthand. Stored flat, save()
	 * would also add `has-border-color`, and on this plugin's pages that class
	 * is not inert: Design_Theme_Json registers the design's border colour as
	 * the palette preset `border`, WordPress generates
	 * `.has-border-color { color: var(--wp--preset--color--border) !important }`
	 * for it, and the blank canvas prints the preset classes — measured on
	 * this install, it would have recoloured every word in the card. For the
	 * same reason `has-text-color` and `has-background`, which save() adds
	 * with a text or background colour, are checked against the palette the
	 * site has now, and a collision leaves the block as it was.
	 *
	 * A flex column (`display:flex;flex-direction:column;gap:20px`, the second
	 * column measured) is left as it was. Its only native home is the layout
	 * support, whose `display: flex` lives in the base layout rules of the
	 * `global-styles` sheet — which the blank canvas dequeues. Converted, the
	 * column would render as a plain block with its 20px gap gone.
	 *
	 * Anything else this cannot express — a margin, a per-side border, a
	 * background image — leaves the block as it was too.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function column( array $block, bool &$changed ): array {
		$attrs = $block['attrs'];
		if ( ! array_key_exists( 'dxaiStyle', $attrs ) || self::has_unmodeled( $attrs ) ) {
			return $block;
		}
		$n = self::root_index( $block );
		if ( $n === null ) {
			return $block;
		}
		$chunk = (string) $block['innerContent'][ $n ];
		$p     = new \WP_HTML_Tag_Processor( $chunk );
		if ( ! $p->next_tag() || ! $p->has_class( 'wp-block-column' ) ) {
			return $block;
		}
		$decls = self::declarations( self::attr_string( $p, 'style' ) );
		if ( $decls === null ) {
			return $block;
		}

		$border  = array();
		$color   = array();
		$padding = array();
		$basis   = null;
		foreach ( $decls as $prop => $value ) {
			if ( str_contains( $value, '!' ) ) {
				return $block;
			}
			switch ( $prop ) {
				case 'flex-basis':
					$basis = self::column_basis( $value );
					if ( $basis === null ) {
						return $block;
					}
					break;
				case 'background':
				case 'background-color':
					if ( ! self::is_color( $value ) ) {
						return $block;
					}
					$color['background'] = $value;
					break;
				case 'color':
					if ( ! self::is_color( $value ) ) {
						return $block;
					}
					$color['text'] = $value;
					break;
				case 'border':
					$parts = self::border_parts( $value );
					if ( $parts === null ) {
						return $block;
					}
					$border = array_merge( $border, $parts );
					break;
				case 'border-width':
				case 'border-style':
				case 'border-color':
					$key   = substr( $prop, 7 );
					$parts = self::border_parts( $value );
					if ( $parts === null || array_keys( $parts ) !== array( $key ) ) {
						return $block;
					}
					$border[ $key ] = $value;
					break;
				case 'border-radius':
					if ( count( self::tokens( $value ) ) !== 1 || ! self::is_length( $value ) ) {
						return $block;
					}
					$border['radius'] = $value;
					break;
				case 'padding':
					$sides = self::box_sides( $value );
					if ( $sides === null ) {
						return $block;
					}
					$padding = array_merge( $padding, $sides );
					break;
				case 'padding-top':
				case 'padding-right':
				case 'padding-bottom':
				case 'padding-left':
					if ( count( self::tokens( $value ) ) !== 1 ) {
						return $block;
					}
					$padding[ substr( $prop, 8 ) ] = $value;
					break;
				default:
					return $block;
			}
		}

		// The classes save() adds, none of which may be a palette preset's.
		$classes = array();
		if ( isset( $color['text'] ) ) {
			$classes[] = 'has-text-color';
		}
		if ( isset( $color['background'] ) ) {
			$classes[] = 'has-background';
		}
		foreach ( $classes as $class ) {
			if ( self::preset_class_taken( $class ) ) {
				return $block;
			}
		}

		// The attributes, as the HTML says they are.
		$style = array();
		$line  = array_intersect_key( $border, array_flip( array( 'color', 'style', 'width' ) ) );
		if ( $line !== array() ) {
			foreach ( self::SIDES as $side ) {
				foreach ( array( 'color', 'style', 'width' ) as $key ) {
					if ( isset( $line[ $key ] ) ) {
						$style['border'][ $side ][ $key ] = $line[ $key ];
					}
				}
			}
		}
		if ( isset( $border['radius'] ) ) {
			$style['border']['radius'] = $border['radius'];
		}
		foreach ( array( 'text', 'background' ) as $key ) {
			if ( isset( $color[ $key ] ) ) {
				$style['color'][ $key ] = $color[ $key ];
			}
		}
		foreach ( self::SIDES as $side ) {
			if ( isset( $padding[ $side ] ) ) {
				$style['spacing']['padding'][ $side ] = $padding[ $side ];
			}
		}
		unset( $attrs['dxaiStyle'] );
		if ( $style !== array() ) {
			$attrs['style'] = $style;
		}
		if ( $basis !== null ) {
			$attrs['width'] = $basis['width'];
		} else {
			unset( $attrs['width'] );
		}

		/*
		 * The style save() writes: the support styles, then the column's own
		 * flex-basis. The validator compares declarations as a set, so the
		 * order here is only for a reader.
		 */
		$css = array();
		if ( isset( $border['radius'] ) ) {
			$css['border-radius'] = $border['radius'];
		}
		foreach ( self::SIDES as $side ) {
			foreach ( array( 'color', 'style', 'width' ) as $key ) {
				if ( isset( $line[ $key ] ) ) {
					$css[ 'border-' . $side . '-' . $key ] = $line[ $key ];
				}
			}
		}
		if ( isset( $color['text'] ) ) {
			$css['color'] = $color['text'];
		}
		if ( isset( $color['background'] ) ) {
			$css['background-color'] = $color['background'];
		}
		foreach ( self::SIDES as $side ) {
			if ( isset( $padding[ $side ] ) ) {
				$css[ 'padding-' . $side ] = $padding[ $side ];
			}
		}
		if ( $basis !== null ) {
			$css['flex-basis'] = $basis['css'];
		}

		self::put_style( $p, $css );
		foreach ( $classes as $class ) {
			$p->add_class( $class );
		}
		$content        = (array) $block['innerContent'];
		$content[ $n ]  = $p->get_updated_html();
		$block['attrs'] = $attrs;
		$changed        = true;

		return self::with_content( $block, $content );
	}

	/**
	 * A column's flex-basis as its `width` attribute, and as save() writes it
	 * back: a percentage goes through Math.round( n * 1e12 ) / 1e12, so
	 * `55.0%` comes back as `55%`; anything else is written verbatim.
	 *
	 * @return array{width:string, css:string}|null
	 */
	private static function column_basis( string $value ): ?array {
		if ( count( self::tokens( $value ) ) !== 1 || preg_match( '/\d/', $value ) !== 1 ) {
			return null;
		}
		if ( preg_match( '/^\d*\.?\d+%$/', $value ) === 1 ) {
			$css = ( (string) ( round( (float) $value * 1e12 ) / 1e12 ) ) . '%';

			return array(
				'width' => $value,
				'css'   => $css,
			);
		}
		if ( ! self::is_length( $value ) ) {
			return null;
		}

		return array(
			'width' => $value,
			'css'   => $value,
		);
	}

	/**
	 * A `gap` as a blockGap value: one length as a string; for core/columns,
	 * whose blockGap has a vertical and a horizontal side, `row column` as
	 * `{ top, left }`.
	 *
	 * Only a value core will print. wp_render_layout_support_flag() nulls a
	 * gap that fails safecss's value test — anything with `(`, `\`, `&`,
	 * `=`, `}` or `/*` — so `clamp(24px,3vw,40px)`, `calc()`, `min()`,
	 * `max()` and `var(--dxai-…)` would come out as no gap at all, after the
	 * inline one was removed. Those stay inline (null: block unchanged). A
	 * spacing preset is the exception: `var(--wp--preset--spacing--50)` is
	 * written the way core stores it, `var:preset|spacing|50`, which passes.
	 *
	 * @return string|array<string, string>|null
	 */
	private static function block_gap( string $value, bool $two_sides ): string|array|null {
		$tokens = self::tokens( $value );
		foreach ( $tokens as $i => $token ) {
			if ( preg_match( '/^var\(\s*--wp--preset--spacing--([a-z0-9-]+)\s*\)$/i', $token, $preset ) === 1 ) {
				$tokens[ $i ] = 'var:preset|spacing|' . strtolower( $preset[1] );
				continue;
			}
			if ( ! self::is_length( $token ) || preg_match( '%[\\\(&=}]|/\*%', $token ) === 1 ) {
				return null;
			}
		}
		if ( count( $tokens ) === 1 ) {
			return $tokens[0];
		}
		if ( $two_sides && count( $tokens ) === 2 ) {
			return array(
				'top'  => $tokens[0],
				'left' => $tokens[1],
			);
		}

		return null;
	}

	/**
	 * Whether WordPress prints a block's blockGap on this site: layout styles
	 * not disabled by the theme, and `spacing.blockGap` set in the global
	 * settings — the two checks wp_render_layout_support_flag() makes before
	 * it writes a container's `gap`. Twenty Twenty-Five: true.
	 */
	private static function block_gap_prints(): bool {
		if ( ! function_exists( 'wp_get_global_settings' ) || current_theme_supports( 'disable-layout-styles' ) ) {
			return false;
		}

		return null !== wp_get_global_settings( array( 'spacing', 'blockGap' ) );
	}

	/**
	 * Whether a colour preset in the site's palette generates this exact
	 * class (`has-{slug}-color`, `-background-color`, `-border-color`), which
	 * would then style any block that carries it. Unknown counts as taken.
	 */
	private static function preset_class_taken( string $class ): bool {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return true;
		}
		$palette = wp_get_global_settings( array( 'color', 'palette' ) );
		$rows    = array();
		foreach ( (array) $palette as $origin ) {
			if ( is_array( $origin ) && isset( $origin['slug'] ) ) {
				$rows[] = $origin;
				continue;
			}
			foreach ( (array) $origin as $row ) {
				if ( is_array( $row ) && isset( $row['slug'] ) ) {
					$rows[] = $row;
				}
			}
		}
		foreach ( $rows as $row ) {
			$slug = (string) $row['slug'];
			$slug = function_exists( '_wp_to_kebab_case' ) ? _wp_to_kebab_case( $slug ) : $slug;
			if ( in_array( $class, array( 'has-' . $slug . '-color', 'has-' . $slug . '-background-color', 'has-' . $slug . '-border-color' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A `border` shorthand (or one of its longhands) as width / style / color.
	 * Null when a part is none of the three or appears twice.
	 *
	 * @return array<string, string>|null
	 */
	private static function border_parts( string $value ): ?array {
		$tokens = self::tokens( $value );
		if ( $tokens === array() || count( $tokens ) > 3 ) {
			return null;
		}
		$out = array();
		foreach ( $tokens as $token ) {
			if ( in_array( strtolower( $token ), self::BORDER_STYLES, true ) ) {
				$key = 'style';
			} elseif ( self::is_length( $token ) ) {
				$key = 'width';
			} elseif ( self::is_color( $token ) ) {
				$key = 'color';
			} else {
				return null;
			}
			if ( isset( $out[ $key ] ) ) {
				return null;
			}
			$out[ $key ] = $token;
		}

		return $out;
	}

	/**
	 * A 1–4 value box shorthand (margin, padding) as its four sides.
	 *
	 * @return array{top:string, right:string, bottom:string, left:string}|null
	 */
	private static function box_sides( string $value ): ?array {
		$t = self::tokens( $value );
		switch ( count( $t ) ) {
			case 1:
				return array( 'top' => $t[0], 'right' => $t[0], 'bottom' => $t[0], 'left' => $t[0] );
			case 2:
				return array( 'top' => $t[0], 'right' => $t[1], 'bottom' => $t[0], 'left' => $t[1] );
			case 3:
				return array( 'top' => $t[0], 'right' => $t[1], 'bottom' => $t[2], 'left' => $t[1] );
			case 4:
				return array( 'top' => $t[0], 'right' => $t[1], 'bottom' => $t[2], 'left' => $t[3] );
		}

		return null;
	}

	private static function all_zero( string $value ): bool {
		foreach ( self::tokens( $value ) as $token ) {
			if ( preg_match( '/^0(?:\.0+)?(?:px|em|rem|%|pt|vw|vh)?$/i', $token ) !== 1 ) {
				return false;
			}
		}

		return true;
	}

	private static function is_length( string $value ): bool {
		return preg_match( '/^(?:0|-?\d*\.?\d+(?:px|em|rem|%|pt|vw|vh|vmin|vmax|ch|ex|svh|dvh|lvh)|thin|medium|thick|(?:calc|clamp|min|max|var)\(.+\))$/i', $value ) === 1;
	}

	/**
	 * One colour value: hex, rgb()/hsl(), a custom property (the design
	 * tokens are `var(--dxai-…)`), or a named colour. Never a keyword that
	 * resets, and never anything with a space in it — `background:#fff url(…)`
	 * is an image, not a colour.
	 */
	private static function is_color( string $value ): bool {
		if ( in_array( strtolower( $value ), array( 'none', 'inherit', 'initial', 'unset', 'revert', 'revert-layer', 'auto' ), true ) ) {
			return false;
		}

		return preg_match( '/^(?:#[0-9a-f]{3,8}|(?:rgb|rgba|hsl|hsla)\([^()]*\)|var\(--[a-z0-9_-]+(?:\s*,[^()]*)?\)|[a-z]+)$/i', $value ) === 1;
	}

	/**
	 * A declaration list as property => value in source order, split on `;`
	 * outside parentheses and quotes so `url("data:image/svg+xml;utf8,…")`
	 * stays one value. A later declaration of a property replaces an earlier
	 * one, as in CSS. Null when a part has no `:`, which is not CSS at all.
	 *
	 * @return array<string, string>|null
	 */
	private static function declarations( string $css ): ?array {
		$out = array();
		foreach ( self::split_top( $css, ';' ) as $part ) {
			$part = trim( $part );
			if ( $part === '' ) {
				continue;
			}
			$colon = strpos( $part, ':' );
			if ( $colon === false ) {
				return null;
			}
			$prop  = strtolower( trim( substr( $part, 0, $colon ) ) );
			$value = trim( substr( $part, $colon + 1 ) );
			if ( $prop === '' || $value === '' ) {
				return null;
			}
			unset( $out[ $prop ] );
			$out[ $prop ] = $value;
		}

		return $out;
	}

	/**
	 * A CSS value's space-separated parts, keeping `clamp(24px, 4vw, 48px)`
	 * as one.
	 *
	 * @return array<int, string>
	 */
	private static function tokens( string $value ): array {
		return array_values( array_filter( self::split_top( trim( $value ), ' ' ), static fn( $t ) => $t !== '' ) );
	}

	/**
	 * $s split on $sep outside parentheses and quotes; a space separator
	 * splits on any whitespace.
	 *
	 * @return array<int, string>
	 */
	private static function split_top( string $s, string $sep ): array {
		$parts = array();
		$buf   = '';
		$depth = 0;
		$quote = '';
		$len   = strlen( $s );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $s[ $i ];
			if ( $quote !== '' ) {
				$buf .= $c;
				if ( $c === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( $c === '"' || $c === "'" ) {
				$quote = $c;
				$buf  .= $c;
				continue;
			}
			if ( $c === '(' ) {
				++$depth;
			} elseif ( $c === ')' && $depth > 0 ) {
				--$depth;
			}
			$is_sep = $sep === ' ' ? ctype_space( $c ) : $c === $sep;
			if ( $is_sep && $depth === 0 ) {
				$parts[] = $buf;
				$buf     = '';
				continue;
			}
			$buf .= $c;
		}
		$parts[] = $buf;

		return $parts;
	}

	/**
	 * Set the current tag's style="" to these declarations, or remove it when
	 * there are none — save() writes no empty style attribute.
	 *
	 * @param array<string, string> $css
	 */
	private static function put_style( \WP_HTML_Tag_Processor $p, array $css ): void {
		if ( $css === array() ) {
			$p->remove_attribute( 'style' );

			return;
		}
		$out = array();
		foreach ( $css as $prop => $value ) {
			$out[] = $prop . ':' . $value;
		}
		$p->set_attribute( 'style', implode( ';', $out ) );
	}

	/**
	 * @param array<string, mixed> $attrs
	 */
	private static function has_unmodeled( array $attrs ): bool {
		foreach ( self::UNMODELED_ATTRS as $key ) {
			if ( isset( $attrs[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether any string anywhere in the attributes contains $needle.
	 *
	 * @param array<string, mixed> $attrs
	 */
	private static function attrs_contain( array $attrs, string $needle ): bool {
		$found = false;
		array_walk_recursive(
			$attrs,
			static function ( $value ) use ( $needle, &$found ): void {
				if ( is_string( $value ) && str_contains( $value, $needle ) ) {
					$found = true;
				}
			}
		);

		return $found;
	}

	/** An attribute's value as a string: '' when absent or written bare. */
	private static function attr_string( \WP_HTML_Tag_Processor $p, string $name ): string {
		$value = $p->get_attribute( $name );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * @return array<int, string>
	 */
	private static function classes( \WP_HTML_Tag_Processor $p ): array {
		$out = array();
		foreach ( $p->class_list() as $class ) {
			$out[] = (string) $class;
		}

		return $out;
	}

	/**
	 * The index of the innerContent string that opens the block's own root
	 * tag, or null when a child comes first (the block has no root of its own
	 * to read).
	 *
	 * @param array<string, mixed> $block
	 */
	private static function root_index( array $block ): ?int {
		foreach ( (array) ( $block['innerContent'] ?? array() ) as $n => $chunk ) {
			if ( $chunk === null ) {
				return null;
			}
			if ( is_string( $chunk ) && trim( $chunk ) !== '' ) {
				return (int) $n;
			}
		}

		return null;
	}

	/**
	 * The block with new innerContent, and innerHTML rebuilt from it the way
	 * parse_blocks() builds it: every string chunk, in order.
	 *
	 * @param array<string, mixed>    $block
	 * @param array<int, string|null> $content
	 * @return array<string, mixed>
	 */
	private static function with_content( array $block, array $content ): array {
		$block['innerContent'] = $content;
		$block['innerHTML']    = implode( '', array_filter( $content, 'is_string' ) );

		return $block;
	}
}

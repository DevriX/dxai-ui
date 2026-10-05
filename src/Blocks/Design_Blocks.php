<?php
/**
 * Editable design primitives used when core blocks cannot keep the ZIP markup.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks;

final class Design_Blocks {

	/** The inserter category of the plugin's own blocks. */
	public const CATEGORY = 'dx-blocks';

	public function register(): void {
		add_action( 'init', array( $this, 'register_blocks' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_script' ) );
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_editor_canvas' ) );
		add_filter( 'wp_kses_allowed_html', array( $this, 'allow_design_html' ), 10, 2 );
		add_filter( 'block_categories_all', array( $this, 'add_category' ) );
		/*
		 * The front-end half of these blocks — reveals, dropdowns, tabs, the
		 * keyboard handling of hover menus — is Motion_Runtime, compiled into
		 * each page's stored script at import. Keeping those stored copies on
		 * the current runtime is part of keeping the blocks working, so it is
		 * registered with them: a runtime fix then reaches pages imported
		 * before it, instead of waiting for each to be re-imported.
		 */
		\DXAI_UI\Compiler\Motion_Runtime::register_refresh();
	}

	/**
	 * The inserter group for the blocks this plugin adds — the ones no core block (or the theme's) can stand in for.
	 * Everything the plugin can say with a core block is a core block; these are what is left, so they are together.
	 *
	 * @param mixed $categories Registered block categories.
	 * @return mixed
	 */
	public function add_category( $categories ) {
		if ( ! is_array( $categories ) ) {
			return $categories;
		}
		foreach ( $categories as $category ) {
			if ( is_array( $category ) && ( $category['slug'] ?? '' ) === self::CATEGORY ) {
				return $categories;
			}
		}
		$entry = array(
			'slug'  => self::CATEGORY,
			'title' => __( 'DX Blocks', 'dxai-ui' ),
			'icon'  => null,
		);
		// Next to core's Design group.
		foreach ( $categories as $i => $category ) {
			if ( is_array( $category ) && ( $category['slug'] ?? '' ) === 'design' ) {
				array_splice( $categories, $i + 1, 0, array( $entry ) );

				return $categories;
			}
		}
		$categories[] = $entry;

		return $categories;
	}

	public function register_blocks(): void {
		// Design chrome that no core block can model. Deliberately NOT
		// core/html: every core/html block carries a "Convert to Blocks" item
		// that runs Gutenberg's rawHandler, whose schema-driven cleaner drops
		// every class it is not told to keep — one click would strip the whole
		// Tailwind layer off a converted page, with only undo to recover.
		// BlockHTMLConvertButton is guarded by `block.name !== 'core/html'`, so
		// a block of our own simply never offers it.
		register_block_type(
			'dxai-ui/html',
			array(
				'api_version'   => 3,
				'title'         => __( 'DX HTML', 'dxai-ui' ),
				'category'      => 'dx-blocks',
				'editor_script' => 'dxai-ui-blocks-editor',
				'supports'      => array(
					// No generated class, and no "Edit as HTML" that could
					// rewrite the payload behind the compiler's back.
					'className'         => false,
					'customClassName'   => false,
					'html'              => false,
					'customCSS'         => false,
					'anchor'            => false,
				),
				'attributes'    => array(
					'content'   => array(
						'type'   => 'string',
						'source' => 'html',
					),
					// CSS of the styled elements inside, class => declarations
					// (Style_Hoister::INNER_ATTR).
					'dxaiInner' => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);

		/*
		 * The design's own text leaves — chips, badges, eyebrows, display
		 * numbers, `<dt>`/`<dd>` pairs. Html_To_Blocks::span() had no
		 * non-island exit, so every one of them shipped as raw HTML: the only
		 * alternatives were core/paragraph, which changes the box from a
		 * `<span>` to a `<p>` and picks up the design's own `.rv-obj p` rules,
		 * or nothing.
		 *
		 * Every attribute below is emitted by BOTH save()s — this one's PHP
		 * counterpart is Html_To_Blocks::text_html() and the editor's is in
		 * assets/js/blocks-editor.js. `attrOrder` exists because attribute
		 * order is part of reproducing the source markup byte for byte and the
		 * designs are not consistent about it: some write `class` before
		 * `aria-hidden`, some after.
		 */
		register_block_type(
			'dxai-ui/text',
			array(
				'api_version'   => 3,
				'title'         => __( 'DX Text', 'dxai-ui' ),
				'category'      => 'dx-blocks',
				'editor_script' => 'dxai-ui-blocks-editor',
				'supports'      => array(
					// No generated class and no custom-CSS class, so the saved
					// element carries exactly what the design put on it —
					// the same reason the 642 dxai-ui/html instances render
					// 1:1. customClassName would let the inspector add one.
					'className'       => false,
					'customClassName' => false,
					'customCSS'       => false,
					'html'            => false,
					// The anchor attribute round-trips the design's own id;
					// the support would add a second, editable one.
					'anchor'          => false,
					// Enter inside a converted run would otherwise shred it
					// into two blocks the design has no box for.
					'splitting'       => false,
					// Deliberately no align/spacing/typography: the inspector
					// must not be able to inject theme.json styles into a
					// design being reproduced exactly.
				),
				'attributes'    => array(
					// Free-form, as core/group's already is, so dt and dd ride
					// the same block rather than needing one each.
					'tagName'    => array(
						'type'    => 'string',
						'default' => 'span',
					),
					'className'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'dxaiStyle'  => array(
						'type'    => 'string',
						'default' => '',
					),
					// The block's own CSS once it is out of the markup: save() writes
					// a dxs- class for it and Style_Rules writes the rule (Style_Hoister).
					'dxaiCss'    => array(
						'type'    => 'string',
						'default' => '',
					),
					// CSS of the styled runs inside (Style_Hoister::INNER_ATTR).
					'dxaiInner'  => array(
						'type'    => 'object',
						'default' => array(),
					),
					'dxaiData'   => array(
						'type'    => 'object',
						'default' => array(),
					),
					'anchor'     => array(
						'type'    => 'string',
						'default' => '',
					),
					/*
					 * A link's own attributes, so a designed call-to-action —
					 * one anchor holding a glyph and a line of copy — rides
					 * this block instead of staying raw HTML. Mirrors
					 * Html_To_Blocks::TEXT_ATTR_MAP and DXAI_TEXT_ATTRS in
					 * assets/js/blocks-editor.js; bin/block-parity.cjs compares
					 * this registration against that bundle, so all three move
					 * together or none of them does.
					 */
					'url'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'target'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'rel'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'role'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'ariaLabel'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'labelledBy' => array(
						'type'    => 'string',
						'default' => '',
					),
					'ariaHidden' => array(
						'type'    => 'string',
						'default' => '',
					),
					'attrOrder'  => array(
						'type'    => 'array',
						'default' => array(),
					),
					// `*` and not the tag name: the selector has to match
					// whatever tagName holds, and hpq reads innerHTML off the
					// first element it finds. With no selector at all it would
					// hand back the whole `<span>…</span>` instead of the copy
					// inside it.
					'content'    => array(
						'type'     => 'string',
						'source'   => 'html',
						'selector' => '*',
						'default'  => '',
					),
				),
			)
		);

		/*
		 * dxai-ui/box: the design's own container element, with its children as
		 * editable blocks.
		 *
		 * The same element and attribute contract as dxai-ui/text, and the same
		 * `supports` refusals for the same reasons, with inner blocks in place
		 * of `content`. It exists because core/group cannot reproduce a
		 * container the design styles itself: core/group writes
		 * `wp-block-group` into the class list, which hands the element to
		 * WordPress layout CSS, and it has no attribute that can hold a
		 * `data-dxai-*` behaviour hook. Both were measured: every flex, grid,
		 * absolute or behaviour-marked container stayed raw HTML instead, 665
		 * of the 3001 raw-HTML islands, with another 1327 classed spans beside
		 * them. Raw HTML is markup nobody can edit in the editor.
		 *
		 * No `content` attribute at all, so the hpq round-trip hazard that
		 * governs dxai-ui/text does not apply here: the children are their own
		 * blocks and carry their own markup.
		 */
		register_block_type(
			'dxai-ui/box',
			array(
				'api_version'   => 3,
				'title'         => __( 'DX Box', 'dxai-ui' ),
				'category'      => 'dx-blocks',
				'editor_script' => 'dxai-ui-blocks-editor',
				'supports'      => array(
					// No generated class and no inspector-added one: the saved
					// element carries exactly what the design put on it.
					'className'       => false,
					'customClassName' => false,
					'customCSS'       => false,
					'html'            => false,
					// The design's own id travels in `anchor`; the support
					// would add a second, editable one.
					'anchor'          => false,
					// Deliberately no align, spacing, typography or layout: the
					// inspector must not inject theme.json styles into an
					// element being reproduced exactly.
				),
				'attributes'    => array(
					'tagName'         => array(
						'type'    => 'string',
						'default' => 'div',
					),
					'className'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'dxaiStyle'       => array(
						'type'    => 'string',
						'default' => '',
					),
					// The block's own CSS once it is out of the markup: save() writes
					// a dxs- class for it and Style_Rules writes the rule (Style_Hoister).
					'dxaiCss'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'dxaiData'        => array(
						'type'    => 'object',
						'default' => array(),
					),
					'anchor'          => array(
						'type'    => 'string',
						'default' => '',
					),
					'elType'          => array(
						'type'    => 'string',
						'default' => '',
					),
					'url'             => array(
						'type'    => 'string',
						'default' => '',
					),
					'src'             => array(
						'type'    => 'string',
						'default' => '',
					),
					'alt'             => array(
						'type'    => 'string',
						'default' => '',
					),
					'width'           => array(
						'type'    => 'string',
						'default' => '',
					),
					'height'          => array(
						'type'    => 'string',
						'default' => '',
					),
					'loading'         => array(
						'type'    => 'string',
						'default' => '',
					),
					'target'          => array(
						'type'    => 'string',
						'default' => '',
					),
					'rel'             => array(
						'type'    => 'string',
						'default' => '',
					),
					'title'           => array(
						'type'    => 'string',
						'default' => '',
					),
					'htmlFor'         => array(
						'type'    => 'string',
						'default' => '',
					),
					'name'            => array(
						'type'    => 'string',
						'default' => '',
					),
					'value'           => array(
						'type'    => 'string',
						'default' => '',
					),
					'placeholder'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'tabIndex'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'role'            => array(
						'type'    => 'string',
						'default' => '',
					),
					'dir'             => array(
						'type'    => 'string',
						'default' => '',
					),
					'hidden'          => array(
						'type'    => 'string',
						'default' => '',
					),
					'disabled'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'defaultValue'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'to'              => array(
						'type'    => 'string',
						'default' => '',
					),
					'strokeWidth'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'ariaLabel'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'labelledBy'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'describedBy'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'ariaHidden'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'ariaExpanded'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'ariaControls'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'ariaSelected'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'ariaCurrent'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'ariaHasPopup'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'ariaOrientation' => array(
						'type'    => 'string',
						'default' => '',
					),
					// See Html_To_Blocks::BOX_ATTR_MAP: an embedded map and an
					// open accordion, each previously worth a whole island.
					'referrerPolicy'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'open'            => array(
						'type'    => 'string',
						'default' => '',
					),
					'attrOrder'       => array(
						'type'    => 'array',
						'default' => array(),
					),
				),
			)
		);
		/*
		 * dxai-ui/svg: the design's own inline SVG, with its size and colours
		 * editable.
		 *
		 * `svgAttrs` is an ordered list of `[ name, value ]` pairs rather than
		 * one declared attribute per name, because an SVG's attribute surface
		 * is open: the corpus's 163 inline SVGs carry viewBox, fill, stroke,
		 * stroke-width, width, height, preserveAspectRatio, role, aria-hidden,
		 * focusable and data-dxai hooks between them. A list keeps the source
		 * order, which is part of reproducing the markup, and needs no schema
		 * change to grow.
		 *
		 * `svgInner` is the children as one string. A block per SVG shape with
		 * a declared attribute per geometry property would be a large surface
		 * nobody edits — what people change on an icon is its size and colour,
		 * and those are root attributes. Html_To_Blocks::svg_html() and the
		 * editor's save() both write the string untouched, so there is no
		 * escaping contract over it at all.
		 */
		register_block_type(
			'dxai-ui/svg',
			array(
				'api_version'   => 3,
				'title'         => __( 'DX Icon', 'dxai-ui' ),
				'category'      => 'dx-blocks',
				'editor_script' => 'dxai-ui-blocks-editor',
				'supports'      => array(
					'className'       => false,
					'customClassName' => false,
					'customCSS'       => false,
					'html'            => false,
					'anchor'          => false,
				),
				'attributes'    => array(
					'svgAttrs' => array(
						'type'    => 'array',
						'default' => array(),
					),
					'svgInner'  => array(
						'type'    => 'string',
						'default' => '',
					),
					// CSS of the root's and the shapes' classes
					// (Style_Hoister::INNER_ATTR).
					'dxaiInner' => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);


		/*
		 * The design's own links and buttons. Every attribute below is written
		 * by BOTH save()s — this one's PHP counterpart is
		 * Html_To_Blocks::link_html() and the editor's is in
		 * assets/js/blocks-editor.js — in the one order
		 * Html_To_Blocks::LINK_ATTR_MAP fixes.
		 *
		 * `ariaExpanded` and `ariaControls` close a latent invalid-block
		 * generator, not a new feature: link_open_tag() already wrote both
		 * attributes into the stored markup while nothing declared them, so
		 * Gutenberg dropped them when it read the block back and save()
		 * regenerated an element without them. That mismatch is what made 33
		 * blocks come back invalid once before. `title`, `target`, `rel` and
		 * `disabled` were never read at all, so a `target="_blank"
		 * rel="noreferrer"` link lost both on the page.
		 */
		register_block_type(
			'dxai-ui/link',
			array(
				'api_version'   => 3,
				'title'         => __( 'DX Link', 'dxai-ui' ),
				'category'      => 'dx-blocks',
				'editor_script' => 'dxai-ui-blocks-editor',
				'supports'      => array(
					'className' => true,
					'html'      => false,
					'anchor'    => true,
				),
				'attributes'    => array(
					'url'          => array(
						'type'    => 'string',
						'default' => '',
					),
					'text'         => array(
						'type'    => 'string',
						'default' => '',
					),
					'className'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'tagName'      => array(
						'type'    => 'string',
						'default' => 'a',
					),
					'suffix'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'suffixClass'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'ariaLabel'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'role'         => array(
						'type'    => 'string',
						'default' => '',
					),
					'dataLeak'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'dataFix'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'imageUrl'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'imageAlt'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'style'        => array(
						'type'    => 'string',
						'default' => '',
					),
					// The block's own CSS once it is out of the markup: save() writes
					// a dxs- class for it and Style_Rules writes the rule (Style_Hoister).
					'dxaiCss'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'elType'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'dxaiData'     => array(
						'type'    => 'object',
						'default' => array(),
					),
					'title'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'target'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'rel'          => array(
						'type'    => 'string',
						'default' => '',
					),
					// Boolean, because that is what the attribute is in HTML and
					// what both serializers write bare, with no `=""`.
					'disabled'     => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'ariaExpanded' => array(
						'type'    => 'string',
						'default' => '',
					),
					'ariaControls' => array(
						'type'    => 'string',
						'default' => '',
					),
					// The design's own `<span>` around a CTA label. Without it
					// the label went out as bare text and that element was
					// deleted from the page.
					'labelWrap'    => array(
						'type'    => 'boolean',
						'default' => false,
					),
					// Records the ABSENCE of white space between the label and
					// the glyph, because the emitter has always written it and
					// clean_attrs() drops a false — so "spaced" has to be the
					// default that survives a round trip.
					'suffixTight'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'hasInner'     => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		/*
		 * A bare `<img>` and no wrapper element. core/image puts the picture in
		 * a `<figure class="wp-block-image">`, and that figure is what a flex
		 * or grid parent then lays out instead of the image — which is why the
		 * governing rule sent most images to an island: over seven designs the
		 * converter emitted 11 core/image and left 12 images as raw HTML, so
		 * almost every picture was either a black box or lossy. core/image also
		 * has no attribute at all for `loading`, `decoding`, `srcset` or
		 * `sizes`, and its `width`/`height` are CSS lengths rather than the
		 * presentational attributes a design ships, so 10 of those 11 were
		 * dropping something.
		 *
		 * Emitted by Html_To_Blocks::image_html() and by the save() in
		 * assets/js/blocks-editor.js, in the same order, with nothing added:
		 * no generated class, no figure, no caption.
		 */
		register_block_type(
			'dxai-ui/image',
			array(
				'api_version'   => 3,
				'title'         => __( 'DX Image', 'dxai-ui' ),
				'category'      => 'dx-blocks',
				'editor_script' => 'dxai-ui-blocks-editor',
				'supports'      => array(
					// The point of the block is that the saved element carries
					// exactly what the design put on it, so nothing may append
					// a class, an id, or a theme.json style.
					'className'       => false,
					'customClassName' => false,
					'customCSS'       => false,
					'html'            => false,
					'anchor'          => false,
				),
				'attributes'    => array(
					'url'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'alt'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'className' => array(
						'type'    => 'string',
						'default' => '',
					),
					// A plain string, not core's `style` object: these designs
					// ship `aspect-ratio:5 / 4` and `transform:scale(1.06)`,
					// which theme.json cannot hold, and WP's custom-CSS support
					// rejects any value matching `</?\w+`.
					'dxaiStyle' => array(
						'type'    => 'string',
						'default' => '',
					),
					// The block's own CSS once it is out of the markup: save() writes
					// a dxs- class for it and Style_Rules writes the rule (Style_Hoister).
					'dxaiCss'    => array(
						'type'    => 'string',
						'default' => '',
					),
					// Strings, because these are the HTML attributes as the
					// design wrote them, not numbers we are free to reformat.
					'width'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'height'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'loading'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'decoding'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'srcset'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'sizes'     => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_block_type(
			'dxai-ui/details',
			array(
				'api_version'   => 3,
				'title'         => __( 'DX Details', 'dxai-ui' ),
				'category'      => 'dx-blocks',
				'editor_script' => 'dxai-ui-blocks-editor',
				'supports'      => array(
					'className' => true,
					'html'      => false,
				),
				'attributes'    => array(
					'num'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'question'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'className' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_block_type(
			'dxai-ui/countup',
			array(
				'api_version'   => 3,
				'title'         => __( 'DX Count Up', 'dxai-ui' ),
				'category'      => 'dx-blocks',
				'editor_script' => 'dxai-ui-blocks-editor',
				'supports'      => array(
					'className' => true,
					'html'      => false,
				),
				'attributes'    => array(
					'value'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'suffix'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'className' => array(
						'type'    => 'string',
						'default' => 'rv-num',
					),
					/*
					 * The class on the screen-reader copy the design nests
					 * inside the number, empty when it wrote none. The visible
					 * figure is animated from 0 by the runtime, so that copy is
					 * the only static statement of the final value a screen
					 * reader gets — Html_To_Blocks::countup() used to drop it,
					 * and the oracle counted four lost `span.sr-only` per width
					 * on GTM. A class rather than a flag, so a design that
					 * spells it `visually-hidden` travels too.
					 */
					'srClass'   => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	public function enqueue_editor_script(): void {
		wp_enqueue_script( 'dxai-ui-blocks-editor' );

		$post_id   = $this->editing_post_id();
		$converted = $post_id > 0 && \DXAI_UI\Support\Upload_Paths::for_meta( $post_id, '_dxai_ui_css_url' )['url'] !== '';

		wp_localize_script(
			'dxai-ui-blocks-editor',
			'dxaiUIEditor',
			array(
				'postId'       => $post_id,
				/*
				 * The scope classes the front end wraps the content in
				 * (Page_Scope). The sections are top-level blocks now, so
				 * nothing in the canvas carries them; blocks-editor.js puts
				 * them on the canvas body so the scoped stylesheet matches
				 * there too. Empty for a page stored with its scope group.
				 */
				'scopeClass'   => $post_id > 0 && \DXAI_UI\Structures\Design_Attach::scope_for( $post_id ) > 0
					? \DXAI_UI\Structures\Page_Scope::design_classes( $post_id )
					: '',
				'canvas'       => $this->canvas_config( $post_id, $converted ),
				/*
				 * Where the canvas asks for the rules of utility classes it
				 * has not got (Style_Rules::rest_rules(), editor form): a
				 * class typed into "Additional CSS class(es)" or pasted in
				 * with a block previews at once instead of after a save and
				 * reload. Empty in a build without the route.
				 */
				'utilityRules' => method_exists( Style_Rules::class, 'rest_rules' ) && defined( 'DXAI_UI_REST_NAMESPACE' )
					? '/' . (string) constant( 'DXAI_UI_REST_NAMESPACE' ) . '/utility-rules'
					: '',
			)
		);
	}

	/**
	 * What blocks-editor.js needs to make a converted page's canvas render
	 * the way its front end does. Empty for any other post.
	 *
	 * `blank` is the stored template; the script follows the edited one, so
	 * switching the template in the inspector switches the canvas with it.
	 * `tokens` is exactly what Blank_Template gives the front end back after
	 * dropping the theme's global styles — every `--wp--preset--*` property
	 * and the `.has-*` preset classes, no `--wp--style--*` — so the design's
	 * brand override (`--dxai-ink: var(--wp--preset--color--ink)`) resolves
	 * in the canvas once the theme's global styles are gone from it.
	 *
	 * @return array<string, mixed>
	 */
	private function canvas_config( int $post_id, bool $converted ): array {
		if ( ! $converted ) {
			// An ordinary page that holds sections copied from a design (Design_Attach): its canvas gets the design's scope, as the front
			// end wraps those sections in it (Page_Scope::wrap_runs()) — without it the design's stylesheet, scoped to that element,
			// matches nothing in the canvas — and, where the front end fences the theme's CSS off them (Theme_Fence), the same.
			if ( $post_id > 0 && \DXAI_UI\Structures\Design_Attach::attached( $post_id ) ) {
				return array(
					'converted'   => false,
					'attached'    => true,
					'fence'       => \DXAI_UI\Theme\Theme_Fence::fences( $post_id ),
					'themeRoots'  => array_values( array_unique( array_filter( array( (string) get_stylesheet_directory_uri(), (string) get_template_directory_uri() ) ) ) ),
					'themeBlocks' => self::theme_block_style_handles(),
				);
			}

			return array( 'converted' => false );
		}

		return array(
			'converted'     => true,
			'blank'         => get_page_template_slug( $post_id ) === \DXAI_UI\Theme\Blank_Template::SLUG,
			'blankTemplate' => \DXAI_UI\Theme\Blank_Template::SLUG,
			'static'        => (bool) get_post_meta( $post_id, '_dxai_ui_static_html', true ),
			'tokens'        => wp_get_global_stylesheet( array( 'variables', 'presets' ) ),
			// Assets::drop_theme_styles() dequeues every stylesheet served
			// from these on a converted page's front end; the canvas turns
			// off any that reach it (a theme's enqueue_block_assets sheet, or
			// its editor sheet core copies into the iframe).
			'themeRoots'    => array_values( array_unique( array_filter( array( (string) get_stylesheet_directory_uri(), (string) get_template_directory_uri() ) ) ) ),
			// Except the styles of the theme's own blocks: the front end keeps those (WordPress adds a block's style when the block is on the
			// page, after the drop above), and the canvas has to as well or a link box and a span lose their rules there.
			'themeBlocks'   => self::theme_block_style_handles(),
		);
	}

	/**
	 * The handles of the styles that the blocks of the theme (and its parent) register — their front-end and editor styles — as their
	 * ids appear in the canvas (`<handle>-css`).
	 *
	 * @return array<int, string>
	 */
	private static function theme_block_style_handles(): array {
		$roots = array_values( array_unique( array_filter( array( (string) get_stylesheet_directory_uri(), (string) get_template_directory_uri() ) ) ) );
		if ( $roots === array() ) {
			return array();
		}
		$styles = wp_styles();
		$out    = array();
		foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $type ) {
			foreach ( array_merge( (array) ( $type->style_handles ?? array() ), (array) ( $type->editor_style_handles ?? array() ) ) as $handle ) {
				$src = isset( $styles->registered[ $handle ] ) && is_string( $styles->registered[ $handle ]->src ?? null ) ? $styles->registered[ $handle ]->src : '';
				foreach ( $roots as $root ) {
					if ( $src !== '' && str_starts_with( $src, $root ) ) {
						$out[] = (string) $handle;
						break;
					}
				}
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Load design CSS inside the iframed editor (enqueue_block_editor_assets stays in the admin chrome).
	 *
	 * The canvas's only copy of the design's stylesheet and fonts, in the
	 * front end's order: the page sheet, then the brand override attached to
	 * it, so Styles wins over the imported token values as it does on the
	 * page. It used to be one of three: a copy in the editor settings'
	 * `styles` and <link>s blocks-editor.js added both loaded after it, and
	 * each restated the imported values over the brand override.
	 *
	 * editor-design.css — the canvas's copy of blank-canvas.css — comes after
	 * the page sheet, where blank-canvas.css is on the front end: its rules
	 * have that sheet's specificity, so at a tie with one of the design's
	 * (`.dxai-ui.dxai-ui--{id} p` against the paragraph reset, both (0,2,1))
	 * the order decides, and it has to decide the same way in both places.
	 * It loads for every post, not only a converted one: all of it is keyed
	 * on the canvas classes blocks-editor.js sets or on dxai-ui blocks, and
	 * the site header and footer previews it unwraps can sit in any post.
	 */
	public function enqueue_editor_canvas(): void {
		if ( ! is_admin() ) {
			return;
		}

		$post_id = $this->editing_post_id();
		if ( $post_id <= 0 ) {
			return;
		}

		// The rules for the dxs- classes (Style_Rules), so the inside of
		// raw-HTML blocks and inline runs looks right in the canvas too — on
		// any post, since a generated pattern can be inserted into an
		// ordinary one. The blocks' own CSS is also previewed from their
		// attribute.
		Style_Rules::attach( 'dxai-ui-rules-editor', Style_Rules::css_for_post( $post_id ) );
		// The theme's button styles, for the buttons that wear them.
		\DXAI_UI\Theme\Theme_Buttons::enqueue();

		// The design this page shows: its own, or the one its copied blocks come from (Design_Attach).
		$source = \DXAI_UI\Structures\Design_Attach::source_for( $post_id );
		$source = $source > 0 ? $source : $post_id;
		$sheet  = \DXAI_UI\Support\Upload_Paths::for_meta( $source, '_dxai_ui_css_url' );
		$css    = $sheet['url'];
		$design = array();
		if ( $css !== '' ) {
			// A Claude Design export never ran under Tailwind's reset — see Assets.
			$static = (bool) get_post_meta( $source, '_dxai_ui_static_html', true );
			if ( ! $static ) {
				wp_enqueue_style( 'dxai-ui-isolate' );
			}
			$ver = \DXAI_UI\Support\Upload_Paths::version( $sheet['path'] );
			wp_enqueue_style( 'dxai-ui-page-editor-' . $post_id, $css, $static ? array() : array( 'dxai-ui-isolate' ), $ver );
			// The same brand override the front end gets (Assets), so the canvas
			// shows the colours Styles is set to rather than the imported ones.
			$brand = \DXAI_UI\Compiler\Token_Styles::brand_css( $source );
			if ( $brand !== '' ) {
				wp_add_inline_style( 'dxai-ui-page-editor-' . $post_id, $brand );
			}
			$design = array( 'dxai-ui-page-editor-' . $post_id );
		}
		// Versioned by its mtime, as the editor script is (Assets): a browser
		// holding the previous copy would keep the old canvas rules.
		wp_enqueue_style(
			'dxai-ui-editor-design',
			DXAI_UI_URL . 'assets/css/editor-design.css',
			$design,
			(string) filemtime( DXAI_UI_DIR . 'assets/css/editor-design.css' )
		);
		if ( $css === '' ) {
			return;
		}

		$fonts = get_post_meta( $source, '_dxai_ui_font_urls', true );
		// The theme's fonts (Theme_Fonts) are in the canvas with the brand rule above; the design's own are not loaded.
		if ( ! is_array( $fonts ) || \DXAI_UI\Theme\Theme_Fonts::adopts( $source ) ) {
			return;
		}
		foreach ( array_values( $fonts ) as $i => $font_url ) {
			$font_url = esc_url_raw( (string) $font_url );
			if ( $font_url === '' ) {
				continue;
			}
			wp_enqueue_style( 'dxai-ui-font-editor-' . $post_id . '-' . $i, $font_url, array(), false );
		}
	}

	private function editing_post_id(): int {
		$post_id = 0;
		if ( isset( $_GET['post'] ) && is_scalar( $_GET['post'] ) ) {
			$post_id = absint( wp_unslash( (string) $_GET['post'] ) );
		}
		if ( $post_id <= 0 && isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof \WP_Post ) {
			$post_id = (int) $GLOBALS['post']->ID;
		}
		if ( $post_id <= 0 ) {
			$post_id = (int) get_the_ID();
		}

		return $post_id;
	}

	/**
	 * @param array<string, mixed> $tags
	 * @return array<string, mixed>
	 */
	public function allow_design_html( array $tags, string $context ): array {
		// Post content only. The 'data' context is also term and user
		// descriptions, which have no design markup to keep.
		if ( $context !== 'post' ) {
			return $tags;
		}

		$attrs = array(
			'class'            => true,
			'id'               => true,
			'style'            => true,
			'role'             => true,
			'type'             => true,
			'href'             => true,
			'src'              => true,
			'alt'              => true,
			'aria-label'       => true,
			'aria-hidden'      => true,
			'aria-selected'    => true,
			'aria-labelledby'  => true,
			'aria-expanded'    => true,
			'aria-controls'    => true,
			'data-*'           => true,
		);

		/*
		 * Not `style`. It was on this list, which let every Author and
		 * Contributor on the site save a <style> element into a post — CSS
		 * injection and defacement for anyone without unfiltered_html, on
		 * every request whether or not a design was ever imported. A design's
		 * CSS lives in its compiled stylesheet, never in post content.
		 */
		foreach ( array( 'button', 'details', 'summary', 'nav', 'section', 'header', 'footer', 'main', 'aside', 'article' ) as $tag ) {
			$existing     = is_array( $tags[ $tag ] ?? null ) ? $tags[ $tag ] : array();
			$tags[ $tag ] = array_merge( $existing, $attrs );
		}

		/*
		 * kses's own `img` list has src, alt, class, style, width, height and
		 * loading but NOT decoding, srcset or sizes — measured on this install,
		 * wp_kses_post() strips those three. dxai-ui/image declares all of
		 * them, so without this a responsive image saved by anyone without
		 * unfiltered_html would come back missing attributes its save() still
		 * writes, which is an invalid block.
		 */
		$existing     = is_array( $tags['img'] ?? null ) ? $tags['img'] : array();
		$tags['img'] = array_merge(
			$existing,
			array(
				'src'      => true,
				'alt'      => true,
				'class'    => true,
				'style'    => true,
				'width'    => true,
				'height'   => true,
				'loading'  => true,
				'decoding' => true,
				'srcset'   => true,
				'sizes'    => true,
			)
		);

		/*
		 * Inline SVG icons, as a fixed subset of shapes and presentation
		 * attributes. Without them KSES removed every <svg> from a page saved
		 * by a user without unfiltered_html — and from the dxai-ui/svg block's
		 * `svgInner` attribute too, which it filters the same way — so icons
		 * vanished and the blocks went invalid (measured: 1,108 SVG elements
		 * over the corpus). Nothing here can run script or fetch anything:
		 * no script, style, foreignObject, a, use, image, animate*, set or
		 * feImage, no href of any kind, and on* attributes are never allowed.
		 */
		$svg_attrs = array_fill_keys(
			array(
				'class', 'id', 'style', 'role', 'focusable', 'xmlns', 'viewbox', 'preserveaspectratio', 'width', 'height',
				'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'd', 'points', 'transform', 'pathlength',
				'fill', 'fill-opacity', 'fill-rule', 'clip-rule', 'clip-path', 'mask', 'opacity', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin',
				'stroke-dasharray', 'stroke-dashoffset', 'stroke-miterlimit', 'stroke-opacity', 'vector-effect',
				'offset', 'stop-color', 'stop-opacity', 'gradientunits', 'gradienttransform', 'spreadmethod', 'fx', 'fy',
				'font-size', 'font-family', 'font-weight', 'text-anchor', 'dominant-baseline', 'dx', 'dy', 'overflow',
			),
			true
		);
		foreach ( array( 'svg', 'g', 'path', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'rect', 'text', 'tspan', 'defs', 'clippath', 'lineargradient', 'radialgradient', 'stop', 'title', 'desc' ) as $tag ) {
			$existing     = is_array( $tags[ $tag ] ?? null ) ? $tags[ $tag ] : array();
			$tags[ $tag ] = array_merge( $existing, $svg_attrs );
		}
		// Inert placeholders the designs draw into or size: a chart canvas, a
		// lucide <i> icon (sized by attribute), a textarea's placeholder.
		$tags['canvas']   = array_merge( is_array( $tags['canvas'] ?? null ) ? $tags['canvas'] : array(), array_fill_keys( array( 'class', 'id', 'style', 'width', 'height' ), true ) );
		$tags['i']        = array_merge( is_array( $tags['i'] ?? null ) ? $tags['i'] : array(), array_fill_keys( array( 'class', 'style', 'width', 'height', 'stroke-width' ), true ) );
		$tags['textarea'] = array_merge( is_array( $tags['textarea'] ?? null ) ? $tags['textarea'] : array(), array( 'placeholder' => true ) );

		foreach ( array_keys( $tags ) as $tag ) {
			if ( is_array( $tags[ $tag ] ) ) {
				$tags[ $tag ]['data-*']          = true;
				$tags[ $tag ]['role']            = true;
				$tags[ $tag ]['aria-hidden']     = true;
				$tags[ $tag ]['aria-label']      = true;
				$tags[ $tag ]['aria-selected']   = true;
				$tags[ $tag ]['aria-labelledby'] = true;
				$tags[ $tag ]['aria-expanded']   = true;
				$tags[ $tag ]['aria-controls']   = true;
			}
		}

		return $tags;
	}
}

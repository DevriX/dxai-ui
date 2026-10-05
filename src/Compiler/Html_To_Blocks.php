<?php
/**
 * Turn compiled design HTML into native Gutenberg blocks (editable).
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Html_To_Blocks {

	private const GROUP_TAGS = array( 'header', 'footer', 'section', 'nav', 'main', 'aside', 'article', 'div' );
	private const HEADING_TAGS = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );
	private const PHRASE_TAGS = array( 'span', 'em', 'strong', 'i', 'b', 'br', 'small', 'code', 'mark', 'sub', 'sup', 'abbr', 'time', 'u' );

	/**
	 * Elements with no closing tag. dxai-ui/box writes one as `<tag …/>` and
	 * declares no children — the form the editor's serialiser writes for its
	 * own SELF_CLOSING_TAGS set (wp-includes/js/dist/element.js), which is
	 * what the stored markup has to match.
	 */
	private const VOID_TAGS = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' );

	/**
	 * dxai-ui/box's attributes, as `[ HTML name => block attribute ]`.
	 *
	 * TEXT_ATTR_MAP plus the names the corpus actually puts on the elements
	 * that were still raw HTML, measured over 2511 islands: `type` on 258,
	 * `aria-expanded` on 199, `href` on 118, `aria-selected` on 90, `tabindex`
	 * on 41, then src/alt/loading/width/height on images and
	 * aria-controls/title/rel/target on controls. Every one of them has to be
	 * declared or the element cannot travel as a block at all.
	 *
	 * The key order is the canonical write order; `attrOrder` carries the
	 * source's own when it differs. Mirrored by DXAI_BOX_ATTRS in
	 * assets/js/blocks-editor.js.
	 */
	private const BOX_ATTR_MAP = array(
		'id'               => 'anchor',
		'class'            => 'className',
		'style'            => 'dxaiStyle',
		'type'             => 'elType',
		'href'             => 'url',
		'src'              => 'src',
		'alt'              => 'alt',
		'width'            => 'width',
		'height'           => 'height',
		'loading'          => 'loading',
		'target'           => 'target',
		'rel'              => 'rel',
		'title'            => 'title',
		'for'              => 'htmlFor',
		'name'             => 'name',
		'value'            => 'value',
		'placeholder'      => 'placeholder',
		'tabindex'         => 'tabIndex',
		'role'             => 'role',
		'dir'              => 'dir',
		'hidden'           => 'hidden',
		'disabled'         => 'disabled',
		'defaultvalue'     => 'defaultValue',
		'to'               => 'to',
		'stroke-width'     => 'strokeWidth',
		'aria-label'       => 'ariaLabel',
		'aria-labelledby'  => 'labelledBy',
		'aria-describedby' => 'describedBy',
		'aria-hidden'      => 'ariaHidden',
		'aria-expanded'    => 'ariaExpanded',
		'aria-controls'    => 'ariaControls',
		'aria-selected'    => 'ariaSelected',
		'aria-current'     => 'ariaCurrent',
		'aria-haspopup'    => 'ariaHasPopup',
		'aria-orientation' => 'ariaOrientation',
		/*
		 * Appended rather than slotted in beside their relatives, so the
		 * canonical order every existing block was written with does not move.
		 * Both earn their place from one export: the H2O Away map is
		 * `<iframe … referrerpolicy="no-referrer-when-downgrade">` and its FAQ
		 * opens with `<details open>`, and a single undeclared attribute is
		 * enough to send the whole element to raw HTML.
		 */
		'referrerpolicy'   => 'referrerPolicy',
		'open'             => 'open',
	);

	/**
	 * Tags dxai-ui/text can stand in for when they hold nothing but text.
	 *
	 * `span` because span() had no non-island exit at all; `dt` and `dd`
	 * because they reached node()'s last resort with no branch of their own.
	 */
	/*
	 * The phrase tags joined `span`, `dt` and `dd` once dxai-ui/box existed.
	 * Before it, an `<em>` or `<strong>` inside a converted container had no
	 * branch of its own and reached node()'s last resort: 212 of them became
	 * raw HTML the moment their parent stopped being one. They are leaves in
	 * the same sense a classed span is, and text_block()'s identity check
	 * applies to them unchanged.
	 */
	private const TEXT_LEAF_TAGS = array( 'span', 'dt', 'dd', 'em', 'strong', 'b', 'i', 'small', 'code', 'mark', 'sub', 'sup', 'abbr', 'time', 'u' );

	/**
	 * The attributes dxai-ui/text declares, as `[ HTML name => block attribute ]`.
	 *
	 * The key order is also the order text_attrs() writes them in, so it is the
	 * canonical order an element is compared against before `attrOrder` has to
	 * carry anything. Anything a design puts on a text leaf that is neither
	 * here nor a data attribute has no block attribute to travel in, so that
	 * element keeps its source HTML instead of becoming a block whose save()
	 * would silently drop it.
	 */
	/*
	 * `href`, `target` and `rel` are here so a LINK whose contents this block
	 * can reproduce stops being a black box.
	 *
	 * A designed call-to-action is one element holding a glyph and a line of
	 * copy — `<a href="tel:…" class="…" style="…">📞 Call <span
	 * class="sc-interp">(855) 560-7463</span></a>`. dxai-ui/box cannot take it,
	 * because a box's children are blocks and the anchor has words of its own;
	 * link()'s leaf and tree paths cannot name that shape either. So every one
	 * of them fell through to raw HTML: on the H2O Away export, 12 of the 34
	 * islands were exactly this, and with them the whole page's phone number
	 * and every service link in the menu.
	 *
	 * This block already reproduces an element with an inline run inside it —
	 * that is what it is for — and it was only ever the missing href that kept
	 * an anchor out. Nothing here is taken on trust: text_block() ends by
	 * comparing text_html() with the element's own markup and returns null
	 * unless they are byte-identical, so a link this table cannot write back
	 * exactly still keeps its source HTML.
	 *
	 * Ordered to mirror BOX_ATTR_MAP, the sibling map, so the two agree about
	 * canonical attribute order and `attrOrder` travels for the same elements
	 * in both.
	 */
	/**
	 * Attribute names libxml writes without a value when the value is empty,
	 * so `name=""` in the source comes back as bare `name`.
	 *
	 * Measured against DOMDocument::saveHTML(), not taken from the HTML spec's
	 * boolean list — the two disagree, and it is libxml this has to match. See
	 * content_reparses().
	 *
	 * @var array<int, string>
	 */
	private const MINIMISED_WHEN_EMPTY = array(
		'disabled',
		'checked',
		'readonly',
		'selected',
		'multiple',
		'defer',
		'ismap',
	);

	private const TEXT_ATTR_MAP = array(
		'id'              => 'anchor',
		'class'           => 'className',
		'style'           => 'dxaiStyle',
		'href'            => 'url',
		'target'          => 'target',
		'rel'             => 'rel',
		'role'            => 'role',
		'aria-label'      => 'ariaLabel',
		'aria-labelledby' => 'labelledBy',
		'aria-hidden'     => 'ariaHidden',
	);

	private string $hover_css = '';

	/**
	 * Raw-HTML fallbacks this conversion emitted, as `[ tag => count ]`.
	 *
	 * @var array<string, int>
	 */
	private array $islands = array();

	/**
	 * Containers whose descendants the design styles by tag, as
	 * `[ tag => [ ancestor class, … ] ]` read from selectors like `.rv-obj p`.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $tag_scopes = array();

	/**
	 * @param string $design_css The design's own stylesheet, prepared.
	 */
	public function __construct( string $design_css = '' ) {
		$this->tag_scopes = self::tag_scopes( $design_css );
	}

	/**
	 * Read `.foo p { … }`-shaped rules out of a stylesheet.
	 *
	 * Promoting an element to a different tag opts it into the design's own
	 * rules for that tag. `.rv-obj-num` is a `<div>` the design sizes at 13px,
	 * and the same design says `.rv-obj p { font-size: 17px }` — so converting
	 * that div to core/paragraph made it a `<p>` inside `.rv-obj` and it grew.
	 * Knowing which (ancestor class, tag) pairs exist is enough to leave those
	 * elements alone without giving up the promotion everywhere else.
	 *
	 * @return array<string, array<int, string>>
	 */
	private static function tag_scopes( string $css ): array {
		if ( trim( $css ) === '' ) {
			return array();
		}

		$out = array();
		if ( ! preg_match_all( '/(?:^|[},])\s*([^{}@]{1,300}?)\s*\{/', $css, $matches ) ) {
			return array();
		}
		foreach ( $matches[1] as $selector_list ) {
			foreach ( explode( ',', $selector_list ) as $selector ) {
				if ( ! preg_match( '/\.([A-Za-z_][\w-]*)[^\s>]*\s*[>\s]\s*([a-z][a-z0-9]*)\s*$/', trim( $selector ), $m ) ) {
					continue;
				}
				$tag = strtolower( $m[2] );
				if ( ! isset( $out[ $tag ] ) ) {
					$out[ $tag ] = array();
				}
				if ( ! in_array( $m[1], $out[ $tag ], true ) ) {
					$out[ $tag ][] = $m[1];
				}
			}
		}

		return $out;
	}

	/**
	 * Whether rendering $el as $tag would put it under one of the design's own
	 * rules for that tag.
	 */
	private function inherits_tag_rules( \DOMElement $el, string $tag ): bool {
		$classes = $this->tag_scopes[ $tag ] ?? array();
		if ( $classes === array() ) {
			return false;
		}

		for ( $node = $el->parentNode; $node instanceof \DOMElement; $node = $node->parentNode ) {
			$own = ' ' . preg_replace( '/\s+/', ' ', $node->getAttribute( 'class' ) ) . ' ';
			foreach ( $classes as $class ) {
				if ( str_contains( $own, ' ' . $class . ' ' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/** @var array<string, true> Fingerprints of already-hoisted <style> blocks. */
	private array $seen_styles = array();

	public function convert( string $html ): string {
		$html = trim( $html );
		if ( $html === '' ) {
			return '';
		}
		$html = $this->repair_unicode_artifacts( $html );
		[ $html, $hover_css ] = Style_Hover::apply( $html );
		if ( $hover_css !== '' ) {
			$this->hover_css .= $hover_css;
		}

		$blocks = $this->nodes( $this->parse( $html ) );
		if ( $blocks === array() ) {
			return Design_Html::wrap( $html );
		}

		return $this->repair_unicode_artifacts( serialize_blocks( $this->comment_attrs( $blocks ) ) );
	}

	/**
	 * The block tree with everything Gutenberg's own serializer leaves OUT of
	 * the block comment taken out of it.
	 *
	 * serialize_blocks() writes every attribute it is handed, because PHP has
	 * no schema at that point. The editor's getCommentAttributes() consults the
	 * schema first and drops two kinds, and this is that function:
	 *
	 *   - an attribute declaring a `source`, because it is re-read from the
	 *     markup on parse, so the comment copy is never read;
	 *   - an attribute whose value equals its declared `default`, because
	 *     prepare_attributes_for_render() and the JS parser both put it back.
	 *
	 * Without this the markup we store is not the markup the editor writes, so
	 * the first time anyone saves one of these pages post_content changes under
	 * them with no edit behind it. Measured with bin/comment-attrs.php over the
	 * seven designs: six attribute classes, and `core/image url`, `core/image
	 * alt` and `core/heading level` are among them — core blocks whose own
	 * serializer has never written those bytes.
	 *
	 * Dead bytes are the smaller half. A sourced attribute in the comment is a
	 * SECOND source of truth for text that also lives in the markup, and PHP's
	 * parse_blocks() does not apply sources — so anything reading
	 * `attrs['content']` off a parsed block gets the stale copy rather than
	 * what the page shows. Every block carrying one here is static, so nothing
	 * renders from it today; dropping it is what keeps that true.
	 *
	 * The comparison is wp_json_encode() and not `===` deliberately: upstream
	 * compares JSON.stringify(), under which PHP's one array type has to be
	 * told apart as `[]` or `{}` the same way it is in bin/block-parity.cjs.
	 *
	 * Dynamic blocks are not exempted. A render_callback only ever sees comment
	 * attributes, so a sourced attribute on a dynamic block is already
	 * unreadable in the editor's own markup — which is why core does not render
	 * from one. core/image and core/heading are both dynamic and both appear
	 * above; render_block_core_image() reads neither `url` nor `alt`.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	private function comment_attrs( array $blocks ): array {
		$registry = \WP_Block_Type_Registry::get_instance();

		foreach ( $blocks as $i => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$blocks[ $i ]['innerBlocks'] = $this->comment_attrs( $block['innerBlocks'] );
			}
			$name  = (string) ( $block['blockName'] ?? '' );
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			if ( $name === '' || $attrs === array() ) {
				continue;
			}
			$type = $registry->get_registered( $name );
			if ( ! $type instanceof \WP_Block_Type ) {
				// An unregistered block keeps every byte: with no schema there
				// is nothing to say the editor would drop one.
				continue;
			}
			$schema = (array) $type->attributes;
			foreach ( array_keys( $attrs ) as $key ) {
				$spec = is_array( $schema[ $key ] ?? null ) ? $schema[ $key ] : null;
				if ( $spec === null ) {
					continue;
				}
				if ( isset( $spec['source'] ) ) {
					unset( $attrs[ $key ] );
					continue;
				}
				if ( array_key_exists( 'default', $spec )
					&& wp_json_encode( $spec['default'] ) === wp_json_encode( $attrs[ $key ] ) ) {
					unset( $attrs[ $key ] );
				}
			}
			$blocks[ $i ]['attrs'] = $attrs;
		}

		return $blocks;
	}

	/**
	 * CSS collected from `style-hover` rewrites and hoisted `<style>` blocks.
	 */
	public function drain_hover_css(): string {
		$css             = $this->hover_css;
		$this->hover_css = '';

		return $css;
	}

	/**
	 * Take an inline `<style>` element's rules into the collected CSS.
	 *
	 * A component rendered N times emits N identical blocks, so identical
	 * rules are kept once.
	 */
	private function collect_style( \DOMElement $node ): void {
		$css = trim( (string) $node->textContent );
		if ( $css === '' ) {
			return;
		}

		$key = md5( (string) preg_replace( '/\s+/', ' ', $css ) );
		if ( isset( $this->seen_styles[ $key ] ) ) {
			return;
		}
		$this->seen_styles[ $key ] = true;
		$this->hover_css          .= $css . "\n";
	}

	/**
	 * A `style="…"` fragment for the saved markup, or nothing.
	 *
	 * Paired with a `dxaiStyle` attribute and the extraProps filter in
	 * assets/js/blocks-editor.js so the editor regenerates the same attribute.
	 */
	private function style_attr( string $style ): string {
		$style = trim( $style );

		return $style === '' ? '' : ' style="' . esc_attr( $style ) . '"';
	}

	private function repair_unicode_artifacts( string $html ): string {
		// A leading backslash means this is a real JSON escape in a block
		// comment, not stripcslashes damage. serialize_block_attributes()
		// writes `--` as --, so eating those produced `\-` — an
		// invalid escape that made the whole attribute JSON unparseable and
		// silently dropped every attribute on the block. Any Tailwind class
		// holding a CSS custom property, e.g. bg-[var(--ara-tint)], hit it.
		return Design_Html::repair_unicode_artifacts( $html );
	}

	/**
	 * @return array<int, \DOMNode>
	 */
	private function parse( string $html ): array {
		$dom  = new \DOMDocument( '1.0', 'UTF-8' );
		$prev = libxml_use_internal_errors( true );
		$wrapped = '<div id="dxai-html-to-blocks">' . $html . '</div>';
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $wrapped, LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$root = $dom->getElementById( 'dxai-html-to-blocks' );
		if ( ! $root instanceof \DOMElement ) {
			return array();
		}
		$nodes = array();
		foreach ( $root->childNodes as $child ) {
			$nodes[] = $child;
		}

		return $nodes;
	}

	/**
	 * @param array<int, \DOMNode> $nodes
	 * @return array<int, array<string, mixed>>
	 */
	private function nodes( array $nodes ): array {
		$blocks = array();
		foreach ( $nodes as $node ) {
			$block = $this->node( $node );
			if ( $block === null ) {
				continue;
			}
			if ( isset( $block[0] ) && is_array( $block[0] ) ) {
				foreach ( $block as $row ) {
					if ( is_array( $row ) && ! empty( $row['blockName'] ) ) {
						$blocks[] = $row;
					}
				}
				continue;
			}
			if ( ! empty( $block['blockName'] ) ) {
				$blocks[] = $block;
			}
		}

		return $blocks;
	}

	/**
	 * @return array<string, mixed>|array<int, array<string, mixed>>|null
	 */
	private function node( \DOMNode $node ): array|null {
		if ( $node instanceof \DOMComment || $node instanceof \DOMProcessingInstruction ) {
			return null;
		}
		if ( $node instanceof \DOMText ) {
			$text = trim( $node->textContent );
			if ( $text === '' ) {
				return null;
			}

			return $this->paragraph( esc_html( $text ), '', esc_html( $text ) );
		}
		if ( ! $node instanceof \DOMElement ) {
			return null;
		}

		$tag = strtolower( $node->tagName );
		if ( $tag === 'style' ) {
			// Gutenberg has no block for a stylesheet, but designs put real
			// behaviour in scoped <style> — CSS-only accordions, ::after
			// arrows, scrollbar hiding. Hoist it into the pattern stylesheet
			// instead of dropping it on the floor.
			$this->collect_style( $node );

			return null;
		}
		if ( in_array( $tag, array( 'script', 'link', 'meta' ), true ) ) {
			return null;
		}
		if ( $tag === 'form' ) {
			return $this->html_block( $this->outer( $node ) );
		}
		if ( $tag === 'table' ) {
			return $this->table( $node );
		}
		/*
		 * core/heading and core/paragraph write back a class, an id and a style
		 * and nothing else, so an element carrying anything more goes the way
		 * every other element with an attribute a block cannot write goes — see
		 * core_text_carries().
		 */
		if ( in_array( $tag, self::HEADING_TAGS, true ) ) {
			return $this->core_text_carries( $node ) ? $this->heading( $node ) : $this->control_block( $node );
		}
		if ( $tag === 'p' ) {
			if ( ! $this->core_text_carries( $node ) ) {
				return $this->control_block( $node );
			}

			return $this->paragraph( $this->inner( $node ), $this->class_name( $node ), $this->inner( $node ), $this->anchor( $node ), $node->getAttribute( 'style' ) );
		}
		if ( $tag === 'img' ) {
			return $this->image( $node );
		}
		/*
		 * An icon element carries its whole meaning in `data-lucide` /
		 * `data-icon` and holds no text, so nothing could promote it: 344 of
		 * them were raw HTML. dxai-ui/box round-trips the data attribute and
		 * the class, and an empty box is exactly `<i … ></i>`.
		 */
		if ( $tag === 'i' && ( $node->hasAttribute( 'data-lucide' ) || $node->hasAttribute( 'data-icon' ) ) ) {
			return $this->box_block( $node, $this->nodes( iterator_to_array( $node->childNodes ) ) )
				?? $this->html_block( $this->outer( $node ) );
		}
		if ( $tag === 'a' || $tag === 'button' ) {
			/*
			 * A control the dxai-ui/link path cannot own — one carrying a
			 * behaviour marker, a data-dxai hook or an attribute that block
			 * does not declare — used to keep its source HTML: 380 of them.
			 * dxai-ui/box takes the element as written, with its children as
			 * blocks, which is the same guarantee with none of the loss.
			 */
			if ( $this->keep_source_control( $node ) ) {
				return $this->box_block( $node, $this->nodes( iterator_to_array( $node->childNodes ) ) )
					?? $this->html_block( $this->outer( $node ) );
			}

			return $this->link( $node );
		}
		if ( $tag === 'details' ) {
			/*
			 * dxai-ui/details rewrites the summary into the DevriX FAQ shape
			 * (`rv-faq-num` / `rv-faq-q` / a `+` glyph). A design whose
			 * summary is styled inline, or whose details starts `open`,
			 * would lose both — the H2O export's first FAQ came out closed
			 * with an unstyled 19px summary. Only the shape the block was
			 * written for goes through it.
			 */
			if ( ! $this->details_is_faq_shape( $node ) ) {
				/*
				 * Not the shape dxai-ui/details rewrites — but that is a reason
				 * to skip THAT block, not a reason to give up on the element.
				 * A `<details>` holds a `<summary>` and its content and no
				 * words of its own, which is exactly what dxai-ui/box takes, so
				 * the accordion keeps its own markup AND its children stay
				 * editable blocks. The H2O Away FAQ is six of these, each with
				 * an inline style and one of them `open`, and all six were
				 * raw HTML for want of this line.
				 */
				return $this->control_block( $node );
			}

			return $this->details( $node );
		}
		if ( $tag === 'blockquote' ) {
			return $this->quote( $node );
		}
		if ( $tag === 'ul' || $tag === 'ol' ) {
			return $this->list_block( $node );
		}
		if ( $tag === 'span' && $node->hasAttribute( 'data-countup' ) ) {
			$countup = $this->countup( $node );
			if ( $countup !== null ) {
				return $countup;
			}
			// The block could not reproduce this element, so it takes the
			// ordinary span route and keeps its markup — an island rather than
			// an approximation of the design's number.
		}
		if ( $tag === 'span' ) {
			return $this->span( $node );
		}
		if ( $tag === 'svg' ) {
			return $this->svg_block( $node ) ?? $this->html_block( $this->outer( $node ) );
		}
		/*
		 * A YouTube player is not put on the page as a player: it loads the player, its scripts and its trackers for every visitor,
		 * whether anyone watches or not. It is made the team's way (Video_Facade): hidden embeds for the editor and a facade for the
		 * phone and for the desktop, which the theme swaps for the player when it is pressed. Any other iframe is as below.
		 */
		if ( $tag === 'iframe' ) {
			$video = Video_Facade::iframe_id( $node );
			if ( $video !== '' ) {
				return Video_Facade::blocks( $video, Video_Facade::iframe_title( $node ) );
			}
		}
		/*
		 * An iframe, video, picture or canvas stays as the design wrote it: each
		 * one's behaviour lives in attributes and children a block would have to
		 * model one by one, and there are 16 of them across the corpus.
		 */
		if ( in_array( $tag, array( 'iframe', 'video', 'picture', 'canvas' ), true ) ) {
			return $this->html_block( $this->outer( $node ) );
		}
		// A block-level element holding one line of text is a paragraph, not a
		// group wrapped around one. Grouping it adds a block box inside the
		// element and changes its height.
		if ( in_array( $tag, self::GROUP_TAGS, true ) && $this->is_text_flow( $node ) ) {
			if ( $this->has_scripted_descendant( $node ) ) {
				return $this->html_block( $this->outer( $node ) );
			}
			// Becoming a <p> would opt this element into the design's own rules
			// for `p` inside one of its ancestors — see tag_scopes().
			if ( $tag !== 'p' && $this->inherits_tag_rules( $node, 'p' ) ) {
				return $this->html_block( $this->outer( $node ) );
			}

			/*
			 * A <div> has no margin of its own; a <p> has the browser's `1em 0`.
			 * Under Tailwind's preflight that difference is zeroed, but a static
			 * design keeps the browser's paragraph margins on purpose (see
			 * blank-canvas.css), so an element that was not a <p> says so —
			 * with a class, `dxai-not-p`, that blank-canvas.css zeroes at
			 * specificity (0,0,0).
			 *
			 * Not an inline `margin: 0`. That was tried twice. Exempting a
			 * style that already had a margin kept the browser's 1em on top of
			 * a div styled `margin-bottom: 14px` (17 paragraphs on Arcus, 140px
			 * of page). Writing it always then beat the DESIGN'S OWN RULES on
			 * Tailwind pages, where the margin lives in a class:
			 * `.rv-kpi-label { margin-top: 20px }` lost to the inline zero,
			 * 55 nodes on Messaging Architecture and 886px over three widths.
			 * A (0,0,0) author rule still beats the UA default and loses to
			 * every rule the design wrote, which is the exact order a div had.
			 */
			$class = $this->class_name( $node );
			if ( $tag !== 'p' ) {
				$class = trim( $class . ' dxai-not-p' );
			}

			return $this->paragraph(
				$this->inner( $node ),
				$class,
				$this->inner( $node ),
				$this->anchor( $node ),
				(string) $node->getAttribute( 'style' )
			);
		}

		if ( in_array( $tag, self::GROUP_TAGS, true ) ) {
			// A container holding nothing but an inline run is that run's own
			// box, not a group wrapped around one block per child.
			$run = $this->text_run( $node );
			if ( $run !== null ) {
				return $run;
			}

			return $this->group( $node );
		}

		if ( $this->has_block_child( $node ) ) {
			return $this->group( $node );
		}

		// Last resort before the black box: `<dt>Retention</dt>` and its `<dd>`
		// reached here with no branch of their own and became raw HTML, because
		// nothing emitted a `dt` carrying the design's class and nothing else.
		$text_leaf = $this->text_leaf( $node );
		if ( $text_leaf !== null ) {
			return $text_leaf;
		}

		// Anything else the design wrote, as the element it wrote, with its
		// children as blocks — or, when it has words of its own that a box
		// would have to discard, as one dxai-ui/text holding the whole run.
		// Only markup neither block can reproduce byte for byte is still
		// handed over as source HTML. `<summary>Do you work with my insurance
		// company?<span data-caret></span></summary>` is the shape that needs
		// the middle step here rather than in link(): five of them on the H2O
		// Away FAQ, none of them an anchor.
		return $this->control_block( $node );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function span( \DOMElement $el ): array {
		// A span holding nothing but text is the design's own inline box — a
		// chip, a badge, a display number — and dxai-ui/text reproduces it
		// exactly, so the copy inside becomes editable.
		$text_leaf = $this->text_leaf( $el );
		if ( $text_leaf !== null ) {
			return $text_leaf;
		}
		/*
		 * Nested phrasing — `<span class="chip"><strong>A</strong> B</span>` —
		 * is one dxai-ui/text when the emitter can put the run back byte for
		 * byte. text_leaf() refuses any element child; text_run() takes the
		 * whole span as a rich-text run (including loose text beside phrase
		 * children). That was the corpus's single largest island class.
		 */
		$run = $this->text_run( $el );
		if ( $run !== null ) {
			return $run;
		}
		/*
		 * Classed spans are chips, badges and labels: never promote one to a
		 * paragraph, which collapses a flex or grid row into stacked blocks.
		 *
		 * dxai-ui/box keeps the span exactly as written and turns the run
		 * inside it into blocks; text_block() is the mixed-content fallback
		 * when a box cannot hold loose words beside element children.
		 */
		if ( $this->class_name( $el ) !== '' || $el->hasAttribute( 'style' ) || $this->data_attrs( $el ) !== '' ) {
			$inner = $this->nodes( iterator_to_array( $el->childNodes ) );

			return $this->box_block( $el, $inner )
				?? $this->text_block( $el )
				?? $this->html_block( $this->outer( $el ) );
		}
		$text = trim( preg_replace( '/\s+/', ' ', $el->textContent ) ?? '' );
		if ( $text === '' ) {
			return $this->box_block( $el, array() ) ?? $this->html_block( $this->outer( $el ) );
		}
		if ( $this->has_block_child( $el ) ) {
			return $this->group( $el );
		}

		return $this->control_block( $el );
	}

	/**
	 * A text-only phrasing leaf as dxai-ui/text, or null when the block could
	 * not reproduce the element byte for byte.
	 *
	 * span() had no non-island exit: a classed span was sealed because
	 * promoting it to a paragraph collapses a flex row, and a bare span fell
	 * through to the same seal — so all 382 spans across seven designs came
	 * back as raw HTML. The input the old code could not express is simply
	 * `<span class="rv-chip">Legal Liability</span>`: no block emitted a
	 * `<span>` carrying the design's own class and nothing else, so the choice
	 * was a paragraph — a different box, under the design's own `.rv-obj p`
	 * rules — or a black box. dxai-ui/text is that block.
	 *
	 * What makes the swap safe rather than hopeful is the identity check in
	 * text_block(), which this hands the element to once it knows it is a leaf.
	 *
	 * @return array<string, mixed>|null
	 */
	private function text_leaf( \DOMElement $el ): array|null {
		$tag = strtolower( $el->tagName );
		if ( ! in_array( $tag, self::TEXT_LEAF_TAGS, true ) ) {
			return null;
		}
		// An element child means this is a run of nested inline markup, not a
		// leaf. A leaf cannot carry one — text_run() takes the container of
		// such a run instead — and 59 spans in the corpus are shaped this way.
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMElement ) {
				return null;
			}
		}
		// Gutenberg's rich text drops an inline element with no text in it, so
		// a decorative empty span — a bullet, a rule, a sized spacer — would
		// vanish on the first edit. 52 of them keep their source HTML.
		if ( trim( (string) preg_replace( '/\s+/', ' ', $el->textContent ) ) === '' ) {
			return null;
		}

		return $this->text_block( $el );
	}

	/**
	 * The element as one dxai-ui/text, or null when the block's own emitter
	 * does not already reproduce its markup.
	 *
	 * Shared by the two ways in — a phrasing leaf holding only text
	 * (text_leaf()) and a container holding one whole inline run (text_run())
	 * — because what makes either safe is the same thing: the identity check
	 * at the end. The element becomes a block only when text_html() already
	 * produces its markup exactly, tag, attribute order, escaping and inner
	 * HTML included. That is also what settles PHP-versus-JS agreement: in an
	 * attribute value the two emitters diverge on `'` alone — see the table on
	 * LINK_ESCAPE_HAZARD — and `'` is one DOMDocument leaves raw, so a value
	 * holding it fails the check and the element stays as it is.
	 *
	 * @return array<string, mixed>|null
	 */
	private function text_block( \DOMElement $el ): array|null {
		$tag = strtolower( $el->tagName );
		// Interactive chrome keeps its whole attribute surface — see group().
		// This is also what keeps a control-projected element out: the compiler
		// writes `data-dxai-on` / `data-dxai-off` next to a `dxai-cls-*` class,
		// and any `data-dxai*` attribute is prefer_html_wrapper()'s first test.
		if ( $this->prefer_html_wrapper( $el ) ) {
			return null;
		}
		// And what goes into `content` has to come back from the first edit —
		// see survives_first_edit(). The identity check below cannot see that:
		// it compares PHP with PHP.
		if ( ! $this->survives_first_edit( $el ) ) {
			return null;
		}

		$order = array();
		foreach ( $el->attributes ?? array() as $attr ) {
			if ( ! $attr instanceof \DOMAttr ) {
				continue;
			}
			$name = strtolower( $attr->name );
			if ( ! isset( self::TEXT_ATTR_MAP[ $name ] ) && ! str_starts_with( $name, 'data-' ) ) {
				return null;
			}
			if ( preg_match( '/^[a-z][a-z0-9-]*$/', $name ) !== 1 ) {
				return null;
			}
			/*
			 * Five characters are refused, and exactly ONE of them makes the
			 * two save()s disagree. Run side by side over the real
			 * implementations — esc_attr() against wp-includes/js/dist/
			 * escape-html.js, scratch: collapse/escaper-probe.{php,cjs} —
			 * they agree on a bare `&` (both `&amp;`), on an `&` that already
			 * looks like an entity (neither double-encodes), on `"` (both
			 * `&quot;`), on `<` and on `>`. They part company on `'` alone:
			 * esc_attr() writes `&#039;` and escapeAttribute() leaves it bare.
			 *
			 * DOMDocument::saveHTML() is the third escaper, and it diverges on
			 * a different axis rather than a fifth character: for `"` it
			 * switches the delimiter to `'` and leaves the value alone instead
			 * of encoding it. That never reaches block validity — an own
			 * attribute is read back off the block COMMENT, not the markup —
			 * but it does reach the identity check at the end of this method,
			 * which compares this emitter against saveHTML().
			 *
			 * So the guard is deliberately a superset of what it has to be.
			 * Measured cost of the four extra characters (scratch:
			 * collapse/refusal-cost.php over the source corpus): 10 attribute
			 * values refused, 2 of them for a real apostrophe. Of the other 8,
			 * one is an `aria-label` on an `a`, which takes dxai-ui/link and
			 * never reaches here at all; the remaining 7 are `class` values
			 * holding a Tailwind arbitrary variant — `[&>*]:w-[78%]` — on a
			 * `div` that text_run() could otherwise fold. Narrowing the guard
			 * to `'` is therefore worth at most 7 containers and changes
			 * routing, so it wants its own geometry run rather than a place in
			 * a batch.
			 */
			if ( preg_match( '/[&"\'<>]/', (string) $attr->value ) === 1 ) {
				return null;
			}
			$order[] = $name;
		}

		// Not inner(): that trims, and the boundary space in
		// `<span class="rv-obj-num"> 01 </span>` is layout, not formatting —
		// 92 of these leaves carry one.
		$content = '';
		foreach ( $el->childNodes as $child ) {
			$content .= $el->ownerDocument?->saveHTML( $child ) ?? '';
		}
		/*
		 * And the SVG spelling libxml lower-cased on the way in, for the same
		 * reason as inner() and outer(): a heading with an inline icon carries
		 * that icon inside this block's `content`, where a dead `viewbox` is as
		 * invisible as anywhere else. Done before content_reparses() below, so
		 * the round-trip check measures what will actually be stored.
		 */
		$content = Svg_Attrs::restore( $content );
		if ( $content === '' ) {
			return null;
		}
		if ( ! $this->content_reparses( $el, $content ) ) {
			return null;
		}

		$attrs = array( 'content' => $content );
		if ( $tag !== 'span' ) {
			$attrs['tagName'] = $tag;
		}
		foreach ( self::TEXT_ATTR_MAP as $name => $key ) {
			$value = $name === 'class' ? $this->class_name( $el ) : trim( $el->getAttribute( $name ) );
			if ( $value !== '' ) {
				$attrs[ $key ] = $value;
			}
		}
		$data = $this->data_map( $el );
		if ( $data !== array() ) {
			$attrs['dxaiData'] = $data;
		}
		// Two source orders exist in the corpus — 16 leaves write `class`
		// before `aria-hidden` and 7 write it after — so no single canonical
		// order reproduces both. The order only travels when it differs from
		// the canonical one, which leaves 275 of 282 blocks carrying nothing.
		$canonical = array();
		foreach ( array_merge( array_keys( self::TEXT_ATTR_MAP ), array_keys( $data ) ) as $name ) {
			if ( in_array( $name, $order, true ) ) {
				$canonical[] = $name;
			}
		}
		if ( $canonical !== $order ) {
			$attrs['attrOrder'] = $order;
		}

		$html = self::text_html( $attrs );
		if ( $html !== $this->outer( $el ) ) {
			return null;
		}

		return $this->leaf( 'dxai-ui/text', $attrs, $html );
	}

	/**
	 * Whether `content` comes back off the stored markup as the same bytes.
	 *
	 * `content` is the one attribute that does NOT travel in the block comment:
	 * `source: 'html'` means Gutenberg re-reads it from the markup with hpq, so
	 * it goes through a browser's HTML parser and serializer on the way back,
	 * and the block opens invalid if those write a byte libxml did not. The
	 * identity check at the end of text_block() cannot see this — it compares
	 * PHP against PHP.
	 *
	 * The list is measured, not reasoned: the editor bundle and real jsdom over
	 * markup this converter actually produced (scratch: hazard-rows.php into
	 * js-parity.cjs) disagree on exactly four things.
	 *
	 *   U+00A0             libxml writes it raw, the browser writes `&nbsp;`,
	 *                      so `1,000+<nbsp>Cases Won` came back changed. This
	 *                      one is not new to the collapse — a plain text leaf
	 *                      holding a no-break space had the same defect, and no
	 *                      design in the corpus happened to carry one.
	 *   CR                 the parser normalises it away before serializing.
	 *   `<` or `>` in an   libxml escapes both, the browser leaves them bare,
	 *   attribute value    so a Tailwind child selector `[&>svg]:h-4` on a
	 *                      folded child came back as `[&>svg]` unescaped.
	 *   `"` in an          libxml switches the delimiter to `'`, the browser
	 *   attribute value    keeps `"` and escapes the value instead.
	 *
	 * `&` and `'` in an attribute value round-trip byte for byte through both,
	 * so they are deliberately allowed. Both are refused on the element's OWN
	 * attributes, for a reason that does not reach here: those go through
	 * esc_attr() and escapeAttribute(), which disagree about `'` (the table on
	 * LINK_ESCAPE_HAZARD has the measurements; `&` is a superset the guard
	 * keeps deliberately). `content` is passed through untouched by both
	 * emitters, so neither escaper runs over it at all.
	 *
	 * The fifth disagreement is not about a character but about an empty value:
	 * libxml MINIMISES one whose name it knows to be boolean, writing `<span
	 * hidden>` where the browser writes `hidden=""`. Rather than keep libxml's
	 * own list of those names, any empty attribute value in the run is refused
	 * — a superset, and one that costs this corpus nothing.
	 *
	 * A literal `>` in TEXT is deliberately not refused here, and the reason is
	 * measured rather than assumed. libxml writes it `&gt;`; @wordpress/rich-
	 * text's serializer writes it bare, so the user's first caret rewrites the
	 * stored bytes without them typing anything, which is what makes it look
	 * like a defect. It is not one, for two reasons that were checked against
	 * the real implementations rather than reasoned about:
	 *
	 *   - The browser escapes `>` in a text node, so hpq reads `>` back OUT of
	 *     the markup as `&gt;` (scratch: collapse/gt-validity.cjs). The drift
	 *     is therefore bounded and self-healing: the next save writes `&gt;`
	 *     again. Normalising the stored content to a bare `>` — the obvious
	 *     "fix" — would be the actual bug, because the attribute would then
	 *     never match what hpq returns.
	 *   - It does not invalidate the block in between. getHTMLTokens() builds
	 *     `new Tokenizer( new DecodeEntityParser() )`, so isEquivalentHTML()
	 *     compares ENTITY-DECODED Chars tokens and `&gt;` is `>` to it (see
	 *     wp-includes/js/dist/blocks.js).
	 *
	 * Both spellings render the same character, so the page cannot move either
	 * way. Refusing the fold would trade a real editing surface for a byte
	 * that converges on its own.
	 */
	private function content_reparses( \DOMElement $el, string $content ): bool {
		if ( str_contains( $content, "\u{00a0}" ) || str_contains( $content, "\r" ) ) {
			return false;
		}
		foreach ( $el->getElementsByTagName( '*' ) as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}
			foreach ( $node->attributes ?? array() as $attr ) {
				if ( ! $attr instanceof \DOMAttr ) {
					continue;
				}
				$value = (string) $attr->value;
				if ( preg_match( '/["<>]/', $value ) !== 0 ) {
					return false;
				}
				/*
				 * An empty value is refused only where libxml would MINIMISE
				 * it, which is a much shorter list than "every attribute".
				 *
				 * This used to refuse any empty value at all, on the stated
				 * grounds that keeping libxml's own list of boolean names was
				 * not worth it and that the superset "costs this corpus
				 * nothing". It costs it something now: the H2O Away menu draws
				 * each service link as an icon plus a label, and the icon is
				 * `<img … alt="" aria-hidden="true">` — a decorative image
				 * saying so in the only way it can. Five links, one empty
				 * `alt` each, all five raw HTML.
				 *
				 * So the list is measured rather than assumed. Feeding every
				 * candidate through DOMDocument::saveHTML() (scratch:
				 * minimise.php) shows exactly seven names minimised — the ones
				 * below — and 39 others, `alt`, `hidden`, `open` and
				 * `required` among them, written back as `name=""` verbatim.
				 * Note `hidden`, `open` and `required` ARE boolean attributes
				 * that libxml nonetheless keeps, which is why the rule has to
				 * come from the measurement and not from the HTML spec.
				 */
				if ( $value === '' && in_array( strtolower( $attr->name ), self::MINIMISED_WHEN_EMPTY, true ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * A container whose whole child list is one inline run, as ONE
	 * dxai-ui/text — or null when it is not that shape, or the block could not
	 * reproduce it.
	 *
	 * `<div class="rv-diff-row"><span class="rv-check">✓</span><span>We do
	 * …</span></div>` is one line box in the design with one inline formatting
	 * context in it, and we gave the editor two sibling blocks with an appender
	 * between them. The input the old code could not express is that container:
	 * is_text_flow() ends `return $has_text`, so an element whose children are
	 * all inline but which owns no loose text node of its own was not a text
	 * flow at all — each inline child was recursed into and emitted separately.
	 * Measured on the seven designs: 138 containers are shaped this way, 88 of
	 * them the two-child `<span>icon</span><span>label</span>` pattern, and 85
	 * of them fold — 202 blocks, 2,391 down to 2,189. The other 53 are refused
	 * by the guards below and by text_block(), which is the correct outcome:
	 * they keep the blocks they have.
	 *
	 * Widening is_text_flow() is NOT the fix. That branch emits
	 * core/paragraph, so `<div class="rv-diff-row">` would come back as a `<p>`
	 * — a different box, and under the design's own `p` rules, which is the
	 * whole reason tag_scopes() exists. dxai-ui/text keeps the design's own
	 * element, so the collapse needs no new block and moves no markup: its
	 * tagName is free-form and its `content` is `source: 'html'` with
	 * `selector: '*'`, which resolves through hpq's `querySelector('*')` to the
	 * container itself, so the attribute reads back as exactly the inline run.
	 *
	 * This sits before group(), so it also answers for a container group()
	 * would have sealed as raw HTML: owns_design_layout() seals a positioned
	 * container because a core/group around it would let WP's layout CSS in,
	 * and a dxai-ui/text is that same element with nothing added, so the reason
	 * does not reach here. No container in the corpus took that path.
	 *
	 * "Nothing added" is the load-bearing half, and it is a property of the
	 * registration rather than a hope. core/group declares `layout`, `spacing`,
	 * `align`, `typography` and `__experimentalBorder`, which is what puts
	 * `is-layout-*` and `wp-container-*` on the element and pulls in core's
	 * rules for them; dxai-ui/text declares className, customClassName,
	 * customCSS, html, anchor and splitting, all false, so there is no support
	 * that can emit a class. Its save() is RichText.Content with the design's
	 * own attributes and NOT useBlockProps.save(), so the
	 * blocks.getSaveContent.extraProps filter — which useBlockProps.save()
	 * applies unconditionally — never runs over it either. dxai-ui/link is the
	 * contrast worth keeping in view: that one does add
	 * `wp-block-dxai-ui-link`, written by both save()s on purpose.
	 *
	 * @return array<string, mixed>|null
	 */
	private function text_run( \DOMElement $el ): array|null {
		$children = 0;
		$loose    = false;
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMText ) {
				if ( trim( $child->textContent ) !== '' ) {
					$loose = true;
				}
				continue;
			}
			if ( ! $child instanceof \DOMElement ) {
				// A comment or a CDATA section: node() drops it today and a
				// fold would keep it, so the container stays as it is rather
				// than have the collapse change what is stored.
				return null;
			}
			// The tag itself is answered by run_survives_rich_text(), which
			// tests every descendant rather than only this level.
			++$children;
		}
		if ( $children === 0 ) {
			return null;
		}
		if ( ! $this->run_survives_rich_text( $el ) ) {
			return null;
		}

		/*
		 * Loose copy beside phrase children — `<span class="chip"><strong>A</strong> B</span>` —
		 * is what dxai-ui/text is for: one rich-text run, source bytes kept so
		 * the identity check compares against the design. GROUP_TAGS that are
		 * a pure text flow already left via is_text_flow() → paragraph before
		 * text_run() is called, so this only reaches chips/spans and containers
		 * that must not become a `<p>`.
		 */
		if ( $loose ) {
			return $this->text_block( $el );
		}

		/*
		 * The white space between two inline children is not in the stored
		 * markup today: node() returns null for a text node that trims to
		 * nothing, so group() stores `<span>✓</span><span>We do …</span>`
		 * butted together even though the design's own source has a newline
		 * and an indent between them — 234 of the 237 all-inline containers in
		 * the corpus carry one. Folding the source run in verbatim would put
		 * those spaces back, and in a container that is not a flex box a
		 * restored space is a rendered space, so a routing change would move
		 * the page. The run is therefore assembled exactly the way group()
		 * already assembles it, on a detached copy so the live tree keeps its
		 * own bytes, and the identity check in text_block() then compares
		 * against that same normalisation instead of against markup we have
		 * never stored.
		 */
		$copy = $el->cloneNode( true );
		if ( ! $copy instanceof \DOMElement ) {
			return null;
		}
		foreach ( iterator_to_array( $copy->childNodes ) as $child ) {
			if ( $child instanceof \DOMText && trim( $child->textContent ) === '' ) {
				$copy->removeChild( $child );
			}
		}

		return $this->text_block( $copy );
	}

	/**
	 * Whether every element inside this run survives being rich text.
	 *
	 * Three things do not, and all three are tested on every descendant rather
	 * than on the children, because none of the three reasons is about depth.
	 *
	 * The first is the tag. PHRASE_TAGS, not INLINE_TAGS: the tags in the
	 * difference all have a block of their own — `a` and `button` have
	 * dxai-ui/link, an `img` has dxai-ui/image, a `label` reaches group() — and
	 * folding one into a run trades a typed block with its own editing surface
	 * for a string, which for a link also hands the href to rich text's
	 * escaper. This test used to sit in text_run()'s child loop, one level
	 * shallower than the other two, and the gap was reachable: a run holding
	 * `<span><a href="/guide">Read the guide</a></span>` was refused when the
	 * anchor was a child and folded when a span was wrapped round it, and so
	 * were `button`, `label`, and `div` — which is not phrasing content at all.
	 * `img`, `svg` and `input` escaped only because they carry no text and the
	 * zero-text clause below caught them, which is an accident, not a reason.
	 * No container in the corpus is shaped that way, so closing it moves no
	 * block; scratch: collapse/depth-probe.php puts each tag at both depths.
	 *
	 * Two more do not. Gutenberg's rich text cannot hold an inline element
	 * with no text in it — a format covers characters and there are none —
	 * unless a registered format type declares an `attributes` map; upstream
	 * asserts `<em></em>` reads back as `text: ''`. Run through the real
	 * @wordpress/rich-text, `<span class="rv-check" aria-hidden="true"></span>
	 * <span>label</span>` does not merely lose the empty span on the user's
	 * first edit, it comes back as `<span class="rv-check" aria-hidden="true">
	 * <span>label</span>` — the rest of the run swallowed into it. 17
	 * containers are refused for a zero-text child of their own.
	 *
	 * The walk is over every descendant and not just the children, because the
	 * child that carries the loss can carry text itself:
	 * `<div class="rv-out-head"><span class="rv-label"><span
	 * class="rv-label-rule" aria-hidden="true"></span>Outcomes</span></div>`
	 * passes a children-only test — the one span holds "Outcomes" — and folds
	 * to `<span class="rv-label"><span class="rv-label-rule"
	 * aria-hidden="true">Outcomes</span>` on the first edit, losing the design's
	 * decorative rule. It is the only container in the corpus shaped that way.
	 *
	 * And a data attribute on a descendant is behaviour, not markup — a
	 * counter, an icon marker, a reveal — which reaches the page through a
	 * block of its own today, so it is left one; see has_scripted_descendant(),
	 * which seals the paragraph path against the same hazard. Two containers
	 * are refused for it, both holding `<span class="rv-num" data-countup="62"
	 * data-suffix="%">`. The container's OWN data attributes are fine: they
	 * travel in `dxaiData` and come out of the same emitter, byte for byte.
	 */
	private function run_survives_rich_text( \DOMElement $el ): bool {
		foreach ( $el->getElementsByTagName( '*' ) as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}
			if ( ! in_array( strtolower( $node->tagName ), self::PHRASE_TAGS, true ) ) {
				return false;
			}
			if ( trim( (string) preg_replace( '/\s+/', ' ', $node->textContent ) ) === '' ) {
				return false;
			}
			if ( $this->data_attrs( $node ) !== '' ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether every element inside $el is still there after someone edits the
	 * words around it.
	 *
	 * text_leaf() hands text_block() a run with no element in it, and
	 * text_run() has already asked the stricter run_survives_rich_text(). The
	 * third way in, control_block(), asked nothing, so a call-to-action with its
	 * icon — `<a …><i data-lucide="phone" …></i>(214) 526-7900</a>`, or an
	 * inline `<svg>` — folded into one dxai-ui/text, and so did
	 * `<label>First name<input …></label>`. The front end stayed the same to
	 * the byte, which is why no harness saw it; the first edit of the label
	 * then deleted the icon or the field from the live page.
	 *
	 * The rule is measured, not reasoned: every run this path folded that held
	 * more than phrasing text — 45 across the corpus — went through
	 * @wordpress/rich-text's create() and toHTMLString() with one character
	 * typed (scratch: flipped-richtext.cjs). 25 lost an element, the other 20
	 * came back whole, and this line separates them exactly. An element with
	 * text of its own comes back, whatever its tag: a `<select>` of options, a
	 * nested `<div>` holding a link. One with no text comes back only as an
	 * object — childless, and carrying an attribute its format type declares
	 * — which in the formats the editor registers is an `img` (core/image
	 * declares src, alt, class and style) and a bare `span` with a `style`
	 * (core/underline declares style). Everything else with no text is
	 * dropped: the Lucide `<i>`, an `<svg>` and its `<path>`, an `<input>` or
	 * `<textarea>`, an unstyled empty span, and any children of a styled one.
	 * `<br>` is rich text's own line break.
	 *
	 * Deliberately wider than run_survives_rich_text(), which also refuses an
	 * `img` and every non-phrasing tag: that one decides whether a container's
	 * children are folded at all, and a refusal there keeps them as blocks. A
	 * refusal here makes the element raw HTML, so it refuses only what is
	 * actually lost — H2O Away's six FAQ questions (a styled empty caret) and
	 * its menu links (an `img` icon) stay editable, and survive being edited.
	 */
	private function survives_first_edit( \DOMElement $el ): bool {
		foreach ( $el->getElementsByTagName( '*' ) as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}
			if ( trim( (string) preg_replace( '/\s+/', ' ', $node->textContent ) ) !== '' ) {
				continue;
			}
			$tag = strtolower( $node->tagName );
			if ( $tag === 'br' || $tag === 'img' ) {
				continue;
			}
			if ( $tag === 'span' && trim( $node->getAttribute( 'style' ) ) !== '' && ! $this->has_element_child( $node ) ) {
				continue;
			}

			return false;
		}

		return true;
	}

	/**
	 * The tag dxai-ui/text writes. Free-form like core/group's, defaulting to
	 * `span`, so `dt` and `dd` travel on the same block.
	 *
	 * @param array<string, mixed> $attrs
	 */
	public static function text_tag( array $attrs ): string {
		$tag = strtolower( trim( (string) ( $attrs['tagName'] ?? 'span' ) ) );

		return preg_match( '/^[a-z][a-z0-9]*$/', $tag ) === 1 ? $tag : 'span';
	}

	/**
	 * dxai-ui/text's attribute list, in `attrOrder` if the element carried one
	 * and in TEXT_ATTR_MAP order otherwise.
	 *
	 * Mirrored by save() in assets/js/blocks-editor.js: every attribute the
	 * design put on the element is declared on the block and written by both
	 * emitters, or the block opens invalid the moment Gutenberg re-runs save()
	 * and compares.
	 *
	 * @param array<string, mixed> $attrs
	 */
	/**
	 * An attribute value, escaped the way the editor's own serialiser escapes
	 * it.
	 *
	 * Not esc_attr(). Gutenberg compares its save() output with the stored
	 * markup byte for byte, and the editor writes attribute values through
	 * @wordpress/escape-html's escapeAttribute(), measured in
	 * wp-includes/js/dist/escape-html.js: it encodes `&` (only when it does not
	 * already open an entity), `"`, `<` and `>`, and leaves `'` alone.
	 * esc_attr() also writes `'` as `&#039;`, and that one character was the
	 * whole disagreement — 218 containers over the corpus carried an
	 * apostrophe in an inline style (`font-family:'EB Garamond',serif`) or a
	 * data-URI SVG and had to stay raw HTML because of it.
	 *
	 * Nothing is lost by matching: the value always sits inside a
	 * double-quoted attribute, where `"`, `<`, `>` and `&` are the characters
	 * that can end it, and all four are still encoded. bin/escaper-parity.php
	 * and its .cjs half run both implementations over the same strings.
	 */
	public static function attr_value( string $value ): string {
		$value = (string) preg_replace( '/&(?!([a-z0-9]+|#[0-9]+|#x[a-f0-9]+);)/i', '&amp;', $value );
		$value = str_replace( array( '"', '<', '>' ), array( '&quot;', '&lt;', '&gt;' ), $value );

		return $value;
	}

	public static function text_attrs( array $attrs ): string {
		return self::attrs_from_map( self::TEXT_ATTR_MAP, $attrs );
	}

	/**
	 * dxai-ui/box's attribute list. Same rules as text_attrs(), a wider map.
	 *
	 * @param array<string, mixed> $attrs
	 */
	public static function box_attrs( array $attrs ): string {
		return self::attrs_from_map( self::BOX_ATTR_MAP, $attrs );
	}

	/**
	 * Serialise the attributes a block declares, in the element's own order.
	 *
	 * `attrOrder` carries the source order when it differs from the map's; the
	 * map's key order is the canonical fallback. Both emitters walk the same
	 * list in the same order, which is what keeps the stored markup and the
	 * editor's save() byte-identical.
	 *
	 * @param array<string, string> $map
	 * @param array<string, mixed>  $attrs
	 */
	private static function attrs_from_map( array $map, array $attrs ): string {
		$data     = is_array( $attrs['dxaiData'] ?? null ) ? $attrs['dxaiData'] : array();
		$explicit = is_array( $attrs['attrOrder'] ?? null ) && $attrs['attrOrder'] !== array();
		$order    = $explicit
			? $attrs['attrOrder']
			: array_merge( array_keys( $map ), array_keys( $data ) );

		$out = '';
		foreach ( $order as $name ) {
			$name = strtolower( (string) $name );
			if ( isset( $map[ $name ] ) ) {
				$value = (string) ( $attrs[ $map[ $name ] ] ?? '' );
			} elseif ( str_starts_with( $name, 'data-' ) ) {
				$value = (string) ( $data[ $name ] ?? '' );
			} else {
				continue;
			}
			/*
			 * An empty value is written when the element's own attribute list
			 * names it, and skipped otherwise.
			 *
			 * `alt=""` is how a decorative image says it is decorative and
			 * `class=""` survives a design's own class logic; both are real
			 * attributes with an empty value, and dropping them changed the
			 * element (28 images and 26 spans across the corpus, which then
			 * had to stay raw HTML). Without an explicit order there is no way
			 * to tell an empty value from an absent attribute, so the old rule
			 * still holds there.
			 */
			if ( $value === '' && ! $explicit ) {
				continue;
			}
			$out .= ' ' . $name . '="' . self::attr_value( $value ) . '"';
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $attrs
	 */
	public static function text_html( array $attrs ): string {
		$tag = self::text_tag( $attrs );

		return '<' . $tag . self::text_attrs( $attrs ) . '>'
			. (string) ( $attrs['content'] ?? '' )
			. '</' . $tag . '>';
	}

	/**
	 * dxai-ui/box: the design's own container element, with its children as
	 * blocks.
	 *
	 * The element is written exactly as the design wrote it — same tag, same
	 * attributes, same order, and NO generated class. That last part is the
	 * whole reason the block exists. core/group forces `wp-block-group`, which
	 * opts the element into WordPress layout CSS and into the resets
	 * blank-canvas.css has to keep undoing, so every container the design
	 * styles itself (flex, grid, absolute layers) and every container carrying
	 * a behaviour hook stayed raw HTML instead: 2000 of the 3001 raw-HTML
	 * islands measured across the corpus, which is markup nobody can edit in
	 * the editor.
	 *
	 * `dxaiData` carries every data attribute, `className` the classes, so the
	 * `dxai-toggle-*` / `dxai-on-*` markers and `data-dxai-*` payloads that
	 * Motion_Runtime drives survive intact — which is what prefer_html_wrapper()
	 * was protecting by refusing to convert at all.
	 *
	 * Shares text_attrs() with dxai-ui/text: one attribute serialiser, one
	 * order, one escaping contract, mirrored once in assets/js/blocks-editor.js.
	 *
	 * @param array<string, mixed> $attrs
	 */
	public static function box_html( array $attrs, string $inner = '' ): string {
		$tag  = self::box_tag( $attrs );
		$open = '<' . $tag . self::box_attrs( $attrs );

		// `<img …/>`, the form the editor's serialiser writes for a void tag.
		if ( in_array( $tag, self::VOID_TAGS, true ) ) {
			return $open . '/>';
		}

		return $open . '>' . $inner . '</' . $tag . '>';
	}

	/**
	 * The tag dxai-ui/box writes. Free-form like dxai-ui/text's and defaulting
	 * to `div` rather than `span`, because a box inserted by hand is a
	 * container. Mirrored by dxaiBoxTag() in assets/js/blocks-editor.js: both
	 * emitters must reject the same values, or one would fall back while the
	 * other wrote the typed tag and the block would open invalid.
	 *
	 * @param array<string, mixed> $attrs
	 */
	public static function box_tag( array $attrs ): string {
		$tag = strtolower( trim( (string) ( $attrs['tagName'] ?? 'div' ) ) );

		return preg_match( '/^[a-z][a-z0-9]*$/', $tag ) === 1 ? $tag : 'div';
	}

	/**
	 * The element as one dxai-ui/box wrapped around `$inner`, or null when the
	 * block's emitter does not already reproduce its markup.
	 *
	 * The identity check is the same discipline as text_block()'s: build the
	 * markup this block would save and compare it with the element's own
	 * serialisation. An attribute that cannot travel — one with no block
	 * attribute to hold it, or a value the two escapers disagree about — fails
	 * the comparison and the element keeps its source HTML.
	 *
	 * @param array<int, array<string, mixed>> $inner
	 * @return array<string, mixed>|null
	 */
	private function box_block( \DOMElement $el, array $inner ): array|null {
		$tag  = strtolower( $el->tagName );
		$void = in_array( $tag, self::VOID_TAGS, true );
		if ( preg_match( '/^[a-z][a-z0-9]*$/', $tag ) !== 1 ) {
			return null;
		}
		// A void element has nothing to hold, so one that arrived here with
		// converted children is not the element this block can write.
		if ( $void && $inner !== array() ) {
			return null;
		}
		/*
		 * Loose text beside element children is not a block.
		 *
		 * This block's children are blocks, and node() turns a bare text node
		 * into a paragraph — so boxing `<a class="…">1</a>` put that "1" inside
		 * a new `<p>`: the anchor lost its own text and the page gained a node.
		 * Measured the moment controls were routed here: 19 pages with extra
		 * nodes and differing text, from 3 on Portfolio Archive to 121 on
		 * Arcus.
		 *
		 * An element whose children are elements, or nothing, boxes exactly.
		 * One with words of its own keeps its source HTML for now: text has to
		 * travel in a declared `content` attribute so the editor's save() can
		 * write it back, which is what dxai-ui/text does and what this block
		 * would need before it can take them.
		 */
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMText && trim( $child->wholeText ) !== '' ) {
				return null;
			}
		}

		$attrs = array( 'tagName' => $tag );
		$order = array();
		foreach ( $el->attributes ?? array() as $attr ) {
			if ( ! $attr instanceof \DOMAttr ) {
				continue;
			}
			$name = strtolower( $attr->name );
			if ( ! isset( self::BOX_ATTR_MAP[ $name ] ) && ! str_starts_with( $name, 'data-' ) ) {
				return null;
			}
			if ( preg_match( '/^[a-z][a-z0-9-]*$/', $name ) !== 1 ) {
				return null;
			}
			$order[] = $name;
			if ( isset( self::BOX_ATTR_MAP[ $name ] ) ) {
				$attrs[ self::BOX_ATTR_MAP[ $name ] ] = (string) $attr->value;
			}
		}
		$data = $this->data_map( $el );
		if ( $data !== array() ) {
			$attrs['dxaiData'] = $data;
		}
		/*
		 * Empty values are kept rather than cleaned away: `alt=""` is how a
		 * decorative image says so and `class=""` is what a design's own class
		 * logic leaves behind. Both are attributes the element has, and
		 * dropping one changed the element, so 28 images and 26 spans had to
		 * stay raw HTML. attrOrder below names them and the serialiser writes
		 * them.
		 */
		$empty = in_array( '', $attrs, true );

		$canonical = array();
		foreach ( array_merge( array_keys( self::BOX_ATTR_MAP ), array_keys( $data ) ) as $name ) {
			if ( in_array( $name, $order, true ) ) {
				$canonical[] = $name;
			}
		}
		if ( $canonical !== $order || $empty ) {
			$attrs['attrOrder'] = $order;
		}

		/*
		 * Fidelity: parse the markup this block would save, and compare the
		 * ELEMENT it produces with the design's own — tag, attribute names,
		 * attribute values, and their order.
		 *
		 * Comparing bytes against DOMDocument's serialisation was the earlier
		 * test, and it refused work it should not have: DOMDocument writes a
		 * void element without the closing slash where the editor writes one,
		 * and it switches an attribute's delimiter to `'` where both save()
		 * emitters encode the `"` instead. Neither changes the element a
		 * browser builds. Parsing back tests the thing that matters — no
		 * attribute lost, none gained, no value altered — and leaves the
		 * byte-level agreement between the two save() emitters to
		 * attr_value(), which mirrors the editor's own escaper.
		 */
		if ( ! self::reproduces( $el, self::box_html( $attrs ) ) ) {
			return null;
		}

		$open  = self::box_html( $attrs );
		$close = $void ? '' : '</' . self::box_tag( $attrs ) . '>';
		if ( ! $void ) {
			$open = substr( $open, 0, strlen( $open ) - strlen( $close ) );
		}
		$content = array( $open );
		foreach ( $inner as $ignored ) {
			unset( $ignored );
			$content[] = null;
		}
		if ( $close !== '' ) {
			$content[] = $close;
		}

		return array(
			'blockName'    => 'dxai-ui/box',
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => $open . $close,
			'innerContent' => $content,
		);
	}

	/**
	 * Whether markup parses back to the element it came from.
	 *
	 * Tag, attribute names, attribute values and their order, compared after a
	 * round trip through the same parser that read the design. Escaping that
	 * round-trips is invisible here, which is the point: what must not change
	 * is the element, not the bytes that spell it.
	 */
	private static function reproduces( \DOMElement $el, string $html ): bool {
		$doc = new \DOMDocument();
		if ( ! @$doc->loadHTML( '<body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR ) ) {
			return false;
		}
		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		$mine = $body instanceof \DOMElement ? $body->firstChild : null;
		if ( ! $mine instanceof \DOMElement ) {
			return false;
		}
		if ( strtolower( $mine->tagName ) !== strtolower( $el->tagName ) ) {
			return false;
		}

		$pairs = static function ( \DOMElement $node ): array {
			$out = array();
			foreach ( $node->attributes ?? array() as $attr ) {
				if ( $attr instanceof \DOMAttr ) {
					$out[] = array( strtolower( $attr->name ), (string) $attr->value );
				}
			}

			return $out;
		};

		return $pairs( $el ) === $pairs( $mine );
	}
	/**
	 * dxai-ui/svg: the design's own inline SVG.
	 *
	 * The root attributes travel as an ORDERED LIST of name/value pairs rather
	 * than through a map of declared block attributes, because an SVG's
	 * attribute surface is open — the corpus's 163 inline SVGs carry viewBox,
	 * fill, stroke, stroke-width, width, height, preserveAspectRatio, role,
	 * aria-hidden, focusable and data-dxai hooks between them, and their
	 * children carry twenty more. A list keeps the source order, which is part
	 * of reproducing the markup, and needs no map to grow.
	 *
	 * The children stay as one string. Writing them as blocks would need a
	 * block per SVG shape and a declared attribute per geometry property, and
	 * nobody edits a path's `d` in an inspector; what people do change is the
	 * icon's size and colour, which are root attributes and are editable here.
	 *
	 * Names go through Svg_Attrs::name(): DOMDocument lower-cases them on the
	 * way in and the editor's serialiser restores the SVG spelling, so the two
	 * save() emitters would otherwise disagree on `viewBox` alone.
	 *
	 * @param array<string, mixed> $attrs
	 */
	public static function svg_html( array $attrs ): string {
		$pairs = is_array( $attrs['svgAttrs'] ?? null ) ? $attrs['svgAttrs'] : array();

		$out = '<svg';
		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) || ! array_key_exists( 0, $pair ) || ! array_key_exists( 1, $pair ) ) {
				continue;
			}
			$out .= ' ' . Svg_Attrs::name( (string) $pair[0] ) . '="' . self::attr_value( (string) $pair[1] ) . '"';
		}

		return $out . '>' . (string) ( $attrs['svgInner'] ?? '' ) . '</svg>';
	}

	/**
	 * The element as one dxai-ui/svg, or null when the block cannot reproduce
	 * it.
	 *
	 * @return array<string, mixed>|null
	 */
	private function svg_block( \DOMElement $el ): array|null {
		if ( strtolower( $el->tagName ) !== 'svg' ) {
			return null;
		}

		$pairs = array();
		foreach ( $el->attributes ?? array() as $attr ) {
			if ( ! $attr instanceof \DOMAttr ) {
				continue;
			}
			$name = strtolower( $attr->name );
			// `isValidAttributeName()` in the editor refuses these, so an
			// element carrying one could never round-trip.
			if ( preg_match( '/^[a-z][a-z0-9:_.-]*$/', $name ) !== 1 ) {
				return null;
			}
			$pairs[] = array( $name, (string) $attr->value );
		}

		$attrs = array(
			'svgAttrs' => $pairs,
			'svgInner' => $this->inner( $el ),
		);

		if ( ! self::reproduces( $el, self::svg_html( $attrs ) ) ) {
			return null;
		}

		return $this->leaf( 'dxai-ui/svg', $attrs, self::svg_html( $attrs ) );
	}


	/**
	 * @return array<string, mixed>
	 */
	private function group( \DOMElement $el ): array {
		$tag   = strtolower( $el->tagName );
		$class = $this->class_name( $el );
		$id    = $this->anchor( $el );
		$style = trim( $el->getAttribute( 'style' ) );
		$data  = $this->data_attrs( $el );
		/*
		 * Loose text beside element children keeps its source HTML.
		 *
		 * nodes() has no slot for a bare text node, so node() turns one into a
		 * paragraph: `<label>What do you need help with?<select/></label>`
		 * came out as a group holding a `<p>` and a `<select>`, the label's own
		 * text gone and a node added. It had always done that — the shape only
		 * became visible when containers stopped being raw HTML, because until
		 * then a parent island swallowed the whole form. H2O's two selects and
		 * ARA's instruction paragraph were the measured cases.
		 */
		$loose = false;
		$kids  = 0;
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMText ) {
				$loose = $loose || trim( $child->wholeText ) !== '';
				continue;
			}
			if ( $child instanceof \DOMElement ) {
				++$kids;
			}
		}
		/*
		 * Loose text beside element children: core/group cannot hold it, and a
		 * box cannot either, because both want children that are blocks.
		 *
		 * That used to end the matter. But it is the same mixed-content shape
		 * link() already answers with one dxai-ui/text over the whole run, and
		 * a form field is written exactly this way —
		 * `<label class="…">What do you need help with?<select …>…</select></label>`
		 * — so the H2O Away lead form contributed nine islands, one per field,
		 * and with them every question the form asks.
		 *
		 * control_block() still tries the box first, so a container whose
		 * children really are blocks is unaffected, and text_block() refuses
		 * anything it cannot reproduce byte for byte.
		 */
		if ( $loose && $kids > 0 ) {
			return $this->control_block( $el );
		}
		$inner = $this->nodes( iterator_to_array( $el->childNodes ) );
		if ( $inner === array() && trim( $el->textContent ) === '' ) {
			return $this->box_block( $el, $inner ) ?? $this->html_block( $this->outer( $el ) );
		}

		/*
		 * Interactive chrome, flex/grid (class or inline) and absolute layers
		 * must not become core/group: its generated `wp-block-group` class
		 * hands the element to WordPress layout CSS, and its attribute surface
		 * has nowhere to put a `data-dxai-*` hook.
		 *
		 * dxai-ui/box has neither problem — no generated class, and every
		 * class and data attribute carried verbatim — so it is tried first and
		 * only a container it cannot reproduce falls through to source HTML.
		 * These two guards were 665 of the corpus's 3001 islands.
		 */
		if ( $this->prefer_html_wrapper( $el ) || $this->owns_design_layout( $el ) ) {
			return $this->box_block( $el, $inner ) ?? $this->html_block( $this->outer( $el ) );
		}

		$attrs = array();
		// The opener below writes `<$tag>` whatever the tag is, so tagName has
		// to travel for every non-div — `<figure>`, `<label>`, `<li>` and the
		// rest reach here through has_block_child(). Restricting this to
		// GROUP_TAGS left those blocks permanently invalid: save() regenerated
		// a `<div>` against a stored `<figure>`. core/group's tagName is a
		// free-form string defaulting to "div", so any tag is accepted.
		if ( $tag !== 'div' ) {
			$attrs['tagName'] = $tag;
		}
		if ( $class !== '' ) {
			$attrs['className'] = $class;
		}
		if ( $id !== '' ) {
			$attrs['anchor'] = $id;
		}
		if ( $style !== '' ) {
			// Not `style: {css}`: WP's custom-CSS support rejects any value
			// matching `</?\w+`, which is exactly the data-URI SVG backgrounds
			// these designs use, adds a `has-custom-css` class save() expects
			// and the stored markup lacks, and is stripped for any user without
			// unfiltered_html. A plain string attribute re-emitted by
			// assets/js/blocks-editor.js round-trips instead.
			$attrs['dxaiStyle'] = $style;
		}
		$role = $el->getAttribute( 'role' );
		if ( $role !== '' ) {
			$attrs['role'] = $role;
		}
		if ( $el->hasAttribute( 'aria-hidden' ) ) {
			$hidden = $el->getAttribute( 'aria-hidden' );
			$attrs['ariaHidden'] = $hidden !== '' ? $hidden : 'true';
		}
		$labelled = $el->getAttribute( 'aria-labelledby' );
		if ( $labelled !== '' ) {
			$attrs['labelledBy'] = $labelled;
		}
		$label = $el->getAttribute( 'aria-label' );
		if ( $label !== '' ) {
			$attrs['ariaLabel'] = $label;
		}
		/*
		 * Data attributes drive behaviour — `data-reveal="1"` is how several of
		 * these designs mark a scroll-reveal section. They were written into the
		 * markup but had nowhere to live as a block attribute, so save() could
		 * not reproduce them and every section carrying one opened invalid: 13
		 * on one page alone. assets/js/blocks-editor.js re-emits this map.
		 */
		$map = $this->data_map( $el );
		if ( $map !== array() ) {
			$attrs['dxaiData'] = $map;
		}

		// Do not force is-layout-flow — it adds margin-block on children and
		// fights Tailwind / inline flex-grid when any slip through.
		$classes = trim( 'wp-block-group ' . $class );
		$open    = '<' . $tag . $this->id_attr( $id ) . $this->aria( $el ) . $data
			. ( $style !== '' ? ' style="' . esc_attr( $style ) . '"' : '' )
			. ' class="' . esc_attr( $classes ) . '">';
		$close   = '</' . $tag . '>';
		$content = array( $open );
		foreach ( $inner as $_ ) {
			$content[] = null;
		}
		$content[] = $close;

		return array(
			'blockName'    => 'core/group',
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => $open . $close,
			'innerContent' => $content,
		);
	}

	/**
	 * Mega-menus, FAQ panels, and design.com motion nodes need their original
	 * attribute surface; converting them to core/group drops data-* hooks.
	 */
	private function prefer_html_wrapper( \DOMElement $el ): bool {
		$class = $el->getAttribute( 'class' );
		if ( str_contains( $class, 'dxai-toggle-' ) || str_contains( $class, 'dxai-on-' ) ) {
			return true;
		}
		foreach ( $el->attributes ?? array() as $attr ) {
			if ( ! $attr instanceof \DOMAttr ) {
				continue;
			}
			$name = strtolower( $attr->name );
			if ( str_starts_with( $name, 'data-dxai' ) || $name === 'data-lucide' ) {
				return true;
			}
			if ( in_array( $name, array( 'data-state', 'data-open', 'data-closed', 'onclick' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * True when the element’s class or inline style owns flex/grid/absolute layout.
	 */
	/**
	 * Whether wrapping this element in a `core/group` would let WordPress take
	 * over its layout.
	 *
	 * The stated reason for sealing a flex or grid class is that core's layout
	 * CSS would fight the design. That reason does not hold: measured on the
	 * live page over 12 designed containers, 398 descendant nodes and 24
	 * properties, adding core's group classes changes nothing, because core's
	 * flow rules are `:where()`-wrapped at specificity 0 and their only effect,
	 * child `margin-block`, is already zeroed for our scope by
	 * assets/css/blank-canvas.css and assets/css/editor-design.css.
	 *
	 * Relaxing it first appeared to shift 11 of 16 sections, but that was two
	 * unrelated bugs surfacing once sections became real blocks: paragraph()
	 * and heading() dropped the inline `style` carrying the design's type
	 * scale, and the plugin's own reset in assets/css/blank-canvas.css scored
	 * (0,3,1), outranking a scoped utility at (0,3,0) and killing every
	 * `mt-*`. With both fixed, measured against real Tailwind CLI output on the
	 * design DOM, sealing and opening give the identical 155px total error over
	 * 12 sections — so the class seals nothing and those sections are editable.
	 *
	 * Positioned layers stay sealed: decorative overlays whose children carry
	 * no editable copy, where grouping buys nothing.
	 */
	private function owns_design_layout( \DOMElement $el ): bool {
		$class = $this->class_name( $el );
		if ( preg_match( '/(?:^|\s)(?:absolute|fixed)(?:\s|$)/', $class ) === 1 ) {
			return true;
		}
		$style = strtolower( $el->getAttribute( 'style' ) );
		if ( $style === '' ) {
			return false;
		}

		return (bool) preg_match( '/(?:^|;)\s*position\s*:\s*(?:absolute|fixed)\b/', $style );
	}

	/**
	 * Whether this element positions its children itself, as flex or grid.
	 *
	 * Such a parent lays out its immediate children as items, so wrapping any
	 * one of them changes what is being laid out.
	 */
	private function lays_out_children( \DOMElement $el ): bool {
		if ( preg_match( '/(?:^|\s)(?:flex|inline-flex|grid|inline-grid)(?:\s|$)/', $this->class_name( $el ) ) === 1 ) {
			return true;
		}

		return (bool) preg_match(
			'/(?:^|;)\s*display\s*:\s*(?:inline-)?(?:flex|grid)\b/',
			strtolower( $el->getAttribute( 'style' ) )
		);
	}

	/** Elements that lay out as part of a line rather than as their own block. */
	private const INLINE_TAGS = array(
		'span', 'a', 'em', 'strong', 'i', 'b', 'br', 'small', 'code', 'mark',
		'sub', 'sup', 'abbr', 'time', 'u', 's', 'del', 'ins', 'kbd', 'samp',
		'var', 'q', 'cite', 'bdi', 'bdo', 'wbr', 'label',
	);

	/**
	 * Whether this element's children make up one line of text rather than a
	 * stack of blocks.
	 *
	 * Loose text next to inline elements is an inline formatting context, and
	 * splitting it into blocks changes the layout: a text node promoted to
	 * core/paragraph becomes a block box, so `<a class="flex gap-2"><i/> Free
	 * Consultation</a>` lost the gap between its icon and its label, and a
	 * text-only `<div>` doubled in height once its own text sat inside a
	 * paragraph. Twelve nodes across the ARA page moved this way.
	 */
	private function is_text_flow( \DOMElement $el ): bool {
		$has_text = false;
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMText ) {
				$has_text = $has_text || trim( $child->textContent ) !== '';
				continue;
			}
			if ( $child instanceof \DOMComment ) {
				continue;
			}
			if ( ! $child instanceof \DOMElement ) {
				return false;
			}
			if ( ! in_array( strtolower( $child->tagName ), self::INLINE_TAGS, true ) ) {
				return false;
			}
		}

		return $has_text;
	}

	/**
	 * Whether anything in here is driven by a data attribute — an icon marker,
	 * a toggle, a counter. Those have to reach the page byte for byte, so the
	 * element goes out as raw HTML rather than through a block that would
	 * normalise them away.
	 */
	private function has_scripted_descendant( \DOMElement $el ): bool {
		if ( $this->data_attrs( $el ) !== '' ) {
			return true;
		}
		foreach ( $el->getElementsByTagName( '*' ) as $child ) {
			if ( $child instanceof \DOMElement && $this->data_attrs( $child ) !== '' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The element's data attributes, as a map a block attribute can hold.
	 *
	 * @return array<string, string>
	 */
	private function data_map( \DOMElement $el ): array {
		$out = array();
		foreach ( $el->attributes ?? array() as $attr ) {
			if ( ! $attr instanceof \DOMAttr ) {
				continue;
			}
			$name = strtolower( $attr->name );
			if ( str_starts_with( $name, 'data-' ) ) {
				$out[ $name ] = (string) $attr->value;
			}
		}

		return $out;
	}

	private function data_attrs( \DOMElement $el ): string {
		$out = '';
		foreach ( $el->attributes ?? array() as $attr ) {
			if ( ! $attr instanceof \DOMAttr ) {
				continue;
			}
			$name = strtolower( $attr->name );
			if ( ! str_starts_with( $name, 'data-' ) ) {
				continue;
			}
			$out .= ' ' . $name . '="' . esc_attr( $attr->value ) . '"';
		}

		return $out;
	}

	/**
	 * Whether core/heading and core/paragraph can write this element back with
	 * nothing lost.
	 *
	 * Both write a class, an id and a style — heading() and paragraph() read
	 * those three and nothing else — so any other attribute on a `<p>` or an
	 * `<h1>`–`<h6>` simply vanished, with no refusal, where box, text and
	 * link all refuse what they cannot write. The one that matters most is
	 * the compiler's own: a heading or paragraph whose class depends on a
	 * state carries a `dxai-cls-*` marker in its class and the class lists in
	 * `data-dxai-on` / `data-dxai-off` (Jsx_Compiler's
	 * attach_class_projection(), Dc_Renderer's per-state `data-dxai-on-*`).
	 * The marker survived and the lists did not, so the runtime found the
	 * element and projected nothing onto it: a row whose background turns ink
	 * on hover kept its dark heading. `aria-*`, `role`, `title` and `hidden`
	 * were lost the same way.
	 *
	 * The id is only carried when it is already one sanitize_html_class()
	 * leaves alone: anchor() rewrites it otherwise, and an `href="#…"` or
	 * `aria-labelledby` pointing at the design's own id stops matching.
	 *
	 * An element this refuses takes control_block(), which carries every
	 * attribute or keeps the element's source HTML.
	 */
	private function core_text_carries( \DOMElement $el ): bool {
		foreach ( $el->attributes ?? array() as $attr ) {
			if ( ! $attr instanceof \DOMAttr ) {
				continue;
			}
			$name = strtolower( $attr->name );
			if ( $name === 'class' || $name === 'style' ) {
				continue;
			}
			if ( $name === 'id' && $this->anchor( $el ) === (string) $attr->value ) {
				continue;
			}

			return false;
		}

		return true;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function heading( \DOMElement $el ): array {
		$level = (int) substr( strtolower( $el->tagName ), 1 );
		$class = $this->class_name( $el );
		$id    = $this->anchor( $el );
		$inner = $this->inner( $el );
		if ( preg_match( '/<(div|p|section|ul|ol|table|header|footer|nav|form)\b/i', $inner ) ) {
			return $this->html_block( $this->outer( $el ) );
		}
		$attrs = array( 'level' => $level );
		if ( $class !== '' ) {
			$attrs['className'] = $class;
		}
		if ( $id !== '' ) {
			$attrs['anchor'] = $id;
		}
		// Designs put the type scale in an inline style — `font-size:
		// clamp(22px, 3vw, 28px)` and the display family. Dropping it collapses
		// the heading to base size and takes the section's vertical rhythm with
		// it, which is what blocked widening owns_design_layout().
		$style = trim( $el->getAttribute( 'style' ) );
		if ( $style !== '' ) {
			$attrs['dxaiStyle'] = $style;
		}
		$classes = trim( 'wp-block-heading ' . $class );
		$html    = '<h' . $level . $this->id_attr( $id ) . $this->style_attr( $style ) . ' class="' . esc_attr( $classes ) . '">' . $inner . '</h' . $level . '>';

		return $this->leaf( 'core/heading', $attrs, $html );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function paragraph( string $inner, string $class = '', string $html_inner = '', string $id = '', string $style = '' ): array {
		$inner = $html_inner !== '' ? $html_inner : $inner;
		$attrs = array();
		if ( $class !== '' ) {
			$attrs['className'] = $class;
		}
		if ( $id !== '' ) {
			$attrs['anchor'] = $id;
		}
		// See heading(): the type scale lives in an inline style, and losing it
		// collapses the paragraph to base size.
		$style = trim( $style );
		if ( $style !== '' ) {
			$attrs['dxaiStyle'] = $style;
		}
		/*
		 * No `wp-block-paragraph` here: stock core/paragraph declares
		 * `className: false`, so its save() writes no generated class (WP 7.0+
		 * appends it at render, older versions never did). Design classes
		 * round-trip through `customClassName`, which is a different support
		 * and on by default. The editor used to switch `className` on to get
		 * them, and that made EVERY paragraph on the site — every existing
		 * post, not just converted pages — open as invalid, because core
		 * applies the same filter to the paragraph's deprecations too.
		 * Paragraphs stored with the class before this still validate: the
		 * editor keeps an unexpected class as a custom one.
		 */
		$html = '<p' . $this->id_attr( $id ) . $this->style_attr( $style ) . ( $class !== '' ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>' . $inner . '</p>';

		return $this->leaf( 'core/paragraph', $attrs, $html );
	}

	/**
	 * @return array<string, mixed>
	 */
	/**
	 * core/image's own attributes for the declarations a design puts on an
	 * <img>. Everything here round-trips through the block's save(), so the
	 * picture keeps its shape and stays natively editable.
	 */
	private const IMAGE_STYLE_ATTRS = array(
		'aspect-ratio' => 'aspectRatio',
		'object-fit'   => 'scale',
		'width'        => 'width',
		'height'       => 'height',
	);

	/**
	 * Whether these classes need the image's own proportions to size it.
	 *
	 * core/image puts the design's classes on the <figure> and lets the <img>
	 * fill it, which only holds when the classes pin a box. `h-full w-full
	 * object-cover` on a card does. One dimension alone does not: `h-7 w-auto`
	 * on a logo, or `mt-8 w-40` on a badge, mean "this wide, and as tall as the
	 * file wants" — and a block-level figure filled by its image resolved those
	 * to 363px and 1,982px. A replaced element's intrinsic ratio is not
	 * something the figure can stand in for.
	 */
	private function image_needs_own_ratio( string $class ): bool {
		if ( preg_match( '/(?:^|\s)(?:w|h|size)-auto(?:\s|$)/', $class ) === 1 ) {
			return true;
		}
		if ( preg_match( '/(?:^|\s)object-(?:cover|contain|fill|none|scale-down)(?:\s|$)/', $class ) === 1 ) {
			return false;
		}
		$width  = preg_match( '/(?:^|\s)(?:w|size)-/', $class ) === 1;
		$height = preg_match( '/(?:^|\s)(?:h|size)-/', $class ) === 1;

		return $width !== $height;
	}

	/**
	 * dxai-ui/image's attributes, as `[ HTML name => block attribute ]`, in the
	 * order image_html() writes them — and the order the save() in
	 * assets/js/blocks-editor.js sets its props.
	 */
	private const DESIGN_IMAGE_ATTRS = array(
		'src'      => 'url',
		'alt'      => 'alt',
		'class'    => 'className',
		'style'    => 'dxaiStyle',
		'width'    => 'width',
		'height'   => 'height',
		'loading'  => 'loading',
		'decoding' => 'decoding',
		'srcset'   => 'srcset',
		'sizes'    => 'sizes',
	);

	/**
	 * @return array<string, mixed>
	 */
	private function image( \DOMElement $el ): array {
		/*
		 * core/image or a black box used to be the whole choice, and core/image
		 * wraps the picture in a `<figure class="wp-block-image">` that becomes
		 * the box in the image's place — which is precisely why the governing
		 * rule sent most images to an island: 12 of the 23 `<img>` elements
		 * this branch saw. dxai-ui/image emits the `<img>` and nothing else, so
		 * the choice is now "core/image where it is lossless, our own block
		 * otherwise", and no image has to be a black box.
		 */
		$core = $this->core_image( $el );
		if ( $core !== null ) {
			return $core;
		}

		return $this->design_image( $el );
	}

	/**
	 * The image as a bare `<img>` on dxai-ui/image, or its source HTML when
	 * even that could not reproduce it.
	 *
	 * @return array<string, mixed>
	 */
	private function design_image( \DOMElement $el ): array {
		$attrs = array();
		foreach ( self::DESIGN_IMAGE_ATTRS as $name => $key ) {
			$value = $name === 'class' ? $this->class_name( $el ) : trim( $el->getAttribute( $name ) );
			if ( $value !== '' ) {
				$attrs[ $key ] = $value;
			}
		}
		// Anything else on the element has no block attribute to travel in. And
		// the characters below are the ones on which esc_attr()/esc_url() and
		// the editor's escaper write different bytes for the same string — see
		// LINK_ESCAPE_HAZARD, which works the cases out escaper by escaper. `&`
		// is deliberately allowed everywhere but the src, because
		// `alt="Angel Reyes &amp; Associates"` is a real alt in this corpus and
		// both sides write it identically.
		foreach ( $el->attributes ?? array() as $attr ) {
			if ( ! $attr instanceof \DOMAttr ) {
				continue;
			}
			$name = strtolower( $attr->name );
			if ( ! isset( self::DESIGN_IMAGE_ATTRS[ $name ] ) ) {
				return $this->box_block( $el, array() )
				?? $this->html_block( $this->outer( $el ) );
			}
			if ( preg_match( $name === 'src' ? '/[&\'<>]/' : '/[\'<>]/', (string) $attr->value ) === 1 ) {
				return $this->box_block( $el, array() )
				?? $this->html_block( $this->outer( $el ) );
			}
		}

		$html = self::image_html( $attrs );
		$got  = self::first_element( $html );
		if ( ! $got instanceof \DOMElement || self::render_sig( $el ) !== self::render_sig( $got ) ) {
			return $this->box_block( $el, array() )
				?? $this->html_block( $this->outer( $el ) );
		}

		return $this->leaf( 'dxai-ui/image', $attrs, $html );
	}

	/**
	 * @param array<string, mixed> $attrs
	 */
	public static function image_html( array $attrs ): string {
		$html = '<img';
		foreach ( self::DESIGN_IMAGE_ATTRS as $name => $key ) {
			$value = trim( (string) ( $attrs[ $key ] ?? '' ) );
			if ( $value === '' ) {
				continue;
			}
			$html .= ' ' . $name . '="' . ( $name === 'src' ? esc_url( $value ) : esc_attr( $value ) ) . '"';
		}

		return $html . '/>';
	}

	/**
	 * The image as core/image, or null when core/image would have to drop
	 * something.
	 *
	 * @return array<string, mixed>|null
	 */
	private function core_image( \DOMElement $el ): array|null {
		/*
		 * core/image's attributes cover the src, the alt, the design's classes
		 * on the `<figure>`, and the four style declarations IMAGE_STYLE_ATTRS
		 * maps. Read from the installed block.json, it has no attribute for
		 * `loading`, `decoding`, `srcset` or `sizes` at all, and its `width`
		 * and `height` are CSS lengths it writes into the image's inline style,
		 * not the presentational attributes a design ships. So an `<img>`
		 * carrying any of those is one core/image has to drop, and 10 of the 11
		 * that converted were dropping something: `loading="lazy"` on 9 of them
		 * and `width`/`height` on 7. They now go to dxai-ui/image, which keeps
		 * all of it.
		 */
		foreach ( $el->attributes ?? array() as $attr ) {
			if ( $attr instanceof \DOMAttr && ! in_array( strtolower( $attr->name ), array( 'src', 'alt', 'class', 'style' ), true ) ) {
				return null;
			}
		}

		$src   = $el->getAttribute( 'src' );
		$alt   = $el->getAttribute( 'alt' );
		$class = $this->class_name( $el );
		$attrs = array(
			'url'             => $src,
			'alt'             => $alt,
			'sizeSlug'        => 'full',
			'linkDestination' => 'none',
		);

		/*
		 * An <img> that carries an inline style is carrying its shape there,
		 * and dropping it is not cosmetic: the ARA hero declares
		 * `aspect-ratio:5 / 4` over a square source file, so losing it made the
		 * picture 113px taller than the design and pushed the whole section
		 * down. Map onto core's attributes where we can and fall back to raw
		 * HTML where we can't, so a style is never silently discarded.
		 */
		$declared = array();
		foreach ( explode( ';', $el->getAttribute( 'style' ) ) as $decl ) {
			if ( trim( $decl ) === '' ) {
				continue;
			}
			$parts = explode( ':', $decl, 2 );
			if ( count( $parts ) !== 2 ) {
				return null;
			}
			$prop = strtolower( trim( $parts[0] ) );
			if ( ! isset( self::IMAGE_STYLE_ATTRS[ $prop ] ) ) {
				return null;
			}
			$declared[ $prop ] = trim( $parts[1] );
		}
		foreach ( $declared as $prop => $value ) {
			$attrs[ self::IMAGE_STYLE_ATTRS[ $prop ] ] = $value;
		}

		/*
		 * core/image puts the design's classes on the <figure> and lets the
		 * <img> fill it. That works when the class set pins a box — `h-full
		 * w-full object-cover` on a card — but not when the design pins one
		 * dimension and asks for the other from the file's own proportions:
		 * `h-7 w-auto` on a logo means 28px tall and 71px wide, while `w-auto`
		 * on a block-level figure means "as wide as the container" and stretched
		 * the footer logo to 363px. A replaced element's intrinsic ratio is not
		 * something the figure can stand in for, so the <img> has to carry its
		 * own classes — which is now dxai-ui/image's job rather than raw HTML's.
		 */
		if ( $this->image_needs_own_ratio( $class ) ) {
			return null;
		}

		/*
		 * core/image wraps the picture in a <figure>, which then becomes the
		 * flex or grid item in the image's place. A footer lockup of
		 * `<img> <span>· RevOps</span>` in a flex row went from 21px to 42px
		 * because the figure, not the image, was what the row laid out.
		 *
		 * Whether the parent lays out its children is often only knowable from
		 * the design's stylesheet — `.rv-footer-brand { display: flex }` leaves
		 * no trace in the markup — so the class test below catches only the
		 * cases a utility class spells out. For an image with no classes of its
		 * own there is nothing for the figure to carry anyway, so the safe
		 * reading is that the wrapper is pure overhead. An image that *does*
		 * carry design classes keeps the block: there the figure is the box the
		 * design asked for. Both of the rejected shapes now go to
		 * dxai-ui/image, which is the same `<img>` with no wrapper at all.
		 */
		$parent = $el->parentNode;
		if ( $class === '' || ( $parent instanceof \DOMElement && $this->lays_out_children( $parent ) ) ) {
			return null;
		}

		if ( $class !== '' ) {
			$attrs['className'] = $class;
		}

		// Mirror core/image's save(): `is-resized` whenever either dimension is
		// set, and a missing height alongside a width becomes `auto`.
		$sized = isset( $declared['width'] ) || isset( $declared['height'] );

		$style = '';
		foreach ( array( 'aspect-ratio', 'object-fit' ) as $prop ) {
			if ( isset( $declared[ $prop ] ) ) {
				$style .= $prop . ':' . $declared[ $prop ] . ';';
			}
		}
		if ( $sized ) {
			$style .= 'width:' . ( $declared['width'] ?? 'auto' ) . ';';
			$style .= 'height:' . ( $declared['height'] ?? 'auto' ) . ';';
		}

		$classes = trim( 'wp-block-image size-full ' . ( $sized ? 'is-resized ' : '' ) . $class );
		$html    = '<figure class="' . esc_attr( $classes ) . '">'
			. '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '"'
			. $this->style_attr( rtrim( $style, ';' ) ) . '/></figure>';

		return $this->leaf( 'core/image', $attrs, $html );
	}

	/**
	 * The attributes dxai-ui/link declares, as `[ HTML name => block attribute ]`.
	 *
	 * The key order is the order link_open_tag() writes them in, and the save()
	 * in assets/js/blocks-editor.js writes the same names in the same order.
	 *
	 * Five of these were missing. `aria-expanded` and `aria-controls` were the
	 * dangerous pair: link_open_tag() already WROTE both, and nothing declared
	 * them, so a control carrying `aria-controls` without `aria-expanded` — the
	 * only shape keep_source_control() let through — became a block whose
	 * save() could not reproduce its own stored markup. That is the exact shape
	 * of the failure that once produced 33 invalid blocks; it is dormant in
	 * this corpus only because every `aria-controls` here happens to travel
	 * with an `aria-expanded`. `title`, `target` and `rel` were never read at
	 * all, so `target="_blank" rel="noreferrer" title="LinkedIn"` was simply
	 * gone from the page.
	 */
	private const LINK_ATTR_MAP = array(
		'class'         => 'className',
		'href'          => 'url',
		'target'        => 'target',
		'rel'           => 'rel',
		'title'         => 'title',
		'style'         => 'style',
		'role'          => 'role',
		'aria-label'    => 'ariaLabel',
		'aria-expanded' => 'ariaExpanded',
		'aria-controls' => 'ariaControls',
		'data-leak'     => 'dataLeak',
		'data-fix'      => 'dataFix',
	);

	/**
	 * Glyphs the leaf path recognises as a decorative trailing span.
	 *
	 * Unchanged from what the old simple path accepted, so no link that
	 * converts today stops converting.
	 */
	private const LINK_SUFFIX_GLYPHS = array( '→', '+', '↓' );

	/**
	 * Per-value: the characters on which this side's escaper and the editor's
	 * write different bytes for the same string. A value holding one of them
	 * keeps its source HTML instead of being reproduced by guesswork.
	 *
	 * DOMDocument decodes every reference on the way in, so both emitters start
	 * from the same plain string and the only question is what each writes back.
	 * Worked out per escaper, not assumed:
	 *
	 *   esc_attr()  encodes & " ' < >, and leaves an existing `&…;` alone.
	 *   esc_html()  encodes the same five.
	 *   esc_url()   rewrites `&` as `&#038;` and drops characters outside its
	 *               own allowlist, so it is the aggressive one.
	 *   the editor  uses @wordpress/escape-html, and its two entry points do
	 *               NOT encode the same set. escapeAttribute() is
	 *               __unstableEscapeGreaterThan(escapeLessThan(
	 *               escapeQuotationMark(escapeAmpersand(v)))) — & " < > —
	 *               while escapeHTML() is escapeLessThan(escapeAmpersand(v))
	 *               — & < only. Neither double-encodes an `&` that already
	 *               begins a reference.
	 *
	 * This is the canonical table for the file; the two comments on the text
	 * path point here rather than restate it. Measured against the real
	 * implementations, not read off the docs — scratch:
	 * collapse/escaper-probe.{php,cjs} runs esc_attr()/esc_html() against
	 * wp-includes/js/dist/escape-html.js:
	 *
	 *                 & (bare)  &amp;   "        '        <       >
	 *   esc_attr      &amp;     &amp;   &quot;   &#039;   &lt;    &gt;
	 *   escapeAttr    &amp;     &amp;   &quot;   '        &lt;    &gt;
	 *   esc_html      &amp;     &amp;   &quot;   &#039;   &lt;    &gt;
	 *   escapeHTML    &amp;     &amp;   "        '        &lt;    >
	 *
	 * So the two axes are not the same, which is why the constant below splits
	 * them. In an ATTRIBUTE value only `'` diverges. In TEXT, `"`, `'` and `>`
	 * all do. `&` and `<` agree everywhere except through esc_url().
	 *
	 * The `>` row is the one that has moved: escapeAttribute() used to stop at
	 * escapeLessThan(), and an earlier version of this comment said `'` and
	 * `>` "never agree". __unstableEscapeGreaterThan() was added upstream
	 * since, so `>` now agrees in an attribute and diverges only in text.
	 *
	 * That is why `alt="Angel Reyes &amp; Associates"` is fine and
	 * `<a>We'll show you</a>` is not.
	 *
	 * Guarding all five everywhere, as the text leaf does, would turn down 16
	 * a/button elements in these designs whose copy is simply `Pricing &amp;
	 * Packaging`. None of those 16 reaches this branch today — every one is
	 * nested inside an ancestor that is still an island — so the measured cost
	 * in this corpus is 0 either way; the narrow rule is here because it is the
	 * true one, and because those ancestors are what the next pass opens.
	 * Either rule leaves the block valid: Gutenberg's validator decodes
	 * character references before comparing. Byte identity between the two
	 * emitters is the stronger property, and it is the one this converter is
	 * verified against.
	 */
	private const LINK_ESCAPE_HAZARD = array(
		'url'      => '/[&\'<>]/',
		'imageUrl' => '/[&\'<>]/',
		'text'     => '/["\'>]/',
		'suffix'   => '/["\'>]/',
	);

	/**
	 * @return array<string, mixed>
	 */
	/**
	 * The last three answers for a control, in order of how much of it stays
	 * editable: a box whose children are blocks, then one dxai-ui/text holding
	 * the whole inline run, then the source HTML.
	 *
	 * The middle step is the new one. box_block() returns null for any element
	 * with words of its own — its children are blocks and a bare text node has
	 * nowhere to go — which is every designed call-to-action in this corpus,
	 * so they all landed on raw HTML. dxai-ui/text carries exactly that shape:
	 * the element's own tag and attributes, its inline run as `content`.
	 *
	 * Order matters. A box keeps the children as separate, individually
	 * editable blocks, so it is tried first and an icon-only control still
	 * gets one. Only when the element has text that a box would have to throw
	 * away does the text block take it.
	 *
	 * Safe by construction rather than by inspection: text_block() returns null
	 * unless text_html() reproduces the element byte for byte, and
	 * prefer_html_wrapper() keeps every `data-dxai*` control out of it, so a
	 * behaviour hook still travels as source HTML.
	 *
	 * @return array<string, mixed>
	 */
	private function control_block( \DOMElement $el ): array {
		return $this->box_block( $el, $this->nodes( iterator_to_array( $el->childNodes ) ) )
			?? $this->text_block( $el )
			?? $this->html_block( $this->outer( $el ) );
	}

	private function link( \DOMElement $el ): array {
		/*
		 * The leaf path is taken on a positive test now — "the block can put
		 * every one of these attributes back, and the children are a label plus
		 * at most one recognised glyph" — rather than on the absence of two
		 * bails.
		 *
		 * The is_text_flow() bail that used to stand here was the bigger of the
		 * two, and its stated reason belongs to the INNER-BLOCKS path below: a
		 * link whose contents are one line of text has nowhere to put them,
		 * because wrapping the line in a paragraph collapses `<i/> Free
		 * Consultation` into a single flex item. But one line of text is
		 * exactly what the leaf path was built for, and the bail ran first, so
		 * `<a class="rv-header-cta" href="#book">Book a GTM session <span
		 * aria-hidden="true">→</span></a>` — text plus the suffix the block
		 * already had `suffix` and `suffixClass` for — was a black box. 67 of
		 * the 139 a/button islands were caught here, 63 of them that shape.
		 */
		$leaf = $this->link_leaf( $el );
		if ( $leaf !== null ) {
			return $leaf;
		}

		// A line of text the leaf path could not name still cannot become inner
		// blocks, for the reason above.
		if ( $this->is_text_flow( $el ) ) {
			return $this->control_block( $el );
		}

		/*
		 * Mixed inline content — `<a><svg/>Book a parallel close</a>`, an
		 * icon drawn inline beside bare text — has the same problem in
		 * another shape: inner blocks have nowhere to put the bare text but a
		 * paragraph, and the tree path wrapped it in `<p class="wp-block-paragraph">`,
		 * a block box inside a flex link. The design's own markup loses
		 * nothing.
		 */
		if ( $this->has_own_text( $el ) && $this->has_element_child( $el ) ) {
			return $this->control_block( $el );
		}

		if ( $this->is_complex_link( $el ) ) {
			$tree = $this->link_tree( $el );
			if ( $tree !== null ) {
				return $tree;
			}
		}

		/*
		 * Neither a reproducible leaf nor a reproducible tree: one or two
		 * children the leaf path cannot name, or an element whose opening tag
		 * this block cannot write back. The old code fell through to the leaf
		 * emitter here and joined those children's text with a space, which
		 * deleted the elements the text lived in — `<a><span>A</span><span>B</span></a>`
		 * came out as `A B`, two lost nodes. Keeping the source markup loses
		 * nothing instead.
		 */
		return $this->control_block( $el );
	}

	/**
	 * The link as a dxai-ui/link wrapped around inner blocks, or null when the
	 * block could not put back the element the design wrote.
	 *
	 * This branch used to read a FIXED list of fourteen attributes off the
	 * element and throw the rest away, which is a different rule from the one
	 * the leaf path next door already lives by, and a losing one. Measured
	 * through the real converter, `<a id="cta" href="/x"><div class="c"><p>One</p></div><p>Two</p></a>`
	 * came back as `<a class="wp-block-dxai-ui-link" href="/x">` with attrs
	 * `{"url":"/x","tagName":"a","hasInner":true}`: the `id` was simply gone.
	 * So were `aria-hidden`, `aria-labelledby`, `data-ga-event`, `tabindex`,
	 * `download`, `name` and `hreflang`, and a `<button type="submit">` came
	 * back as `type="button"`, which changes what the button does.
	 *
	 * The cure is not a longer list — the list being incomplete is the defect.
	 * It is the invariant text_leaf() and link_leaf() already use: build the
	 * attribute set POSITIVELY, from the element's own attributes, and then
	 * make the emitter reproduce the element before the swap is allowed.
	 * Anything it cannot reproduce stays an island, where the source markup is
	 * kept byte for byte. That trades a handful of conversions for the promise
	 * that a conversion never loses anything, which is the trade the governing
	 * rule asks for.
	 *
	 * Only the opening tag is compared, not the content: the content is other
	 * blocks, and each of them answers for its own fidelity.
	 *
	 * @return array<string, mixed>|null
	 */
	private function link_tree( \DOMElement $el ): array|null {
		$attrs = $this->link_attrs( $el );
		if ( $attrs === null ) {
			return null;
		}
		// The same escaper disagreement the leaf path guards — see
		// LINK_ESCAPE_HAZARD. It matters here too, because `url` goes through
		// esc_url() on this side and out raw on the editor's, so the two
		// emitters would write different bytes for the same href.
		if ( ! self::link_hazard_free( $attrs ) ) {
			return null;
		}
		if ( ! $this->link_open_reproduces( $el, $attrs ) ) {
			return null;
		}

		$attrs['hasInner'] = true;
		$inner             = $this->nodes( iterator_to_array( $el->childNodes ) );
		$open              = self::link_open_tag( $attrs );
		$close             = self::link_close_tag( $attrs );
		$content           = array( $open );
		foreach ( $inner as $_ ) {
			$content[] = null;
		}
		$content[] = $close;

		return array(
			'blockName'    => 'dxai-ui/link',
			'attrs'        => $this->clean_attrs( $attrs ),
			'innerBlocks'  => $inner,
			'innerHTML'    => $open . $close,
			'innerContent' => $content,
		);
	}

	/**
	 * The link as a dxai-ui/link leaf, or null when the block could not put
	 * back everything the design put on the element.
	 *
	 * Positive, rather than a list of bails: every attribute has to have a
	 * block attribute that BOTH save()s write, the children have to be a label
	 * plus at most one recognised trailing glyph, and then the emitter's own
	 * output is compared back against the source element — same tag, same
	 * attribute set, same rendered content — before the swap is allowed. An
	 * element the check turns down keeps its source HTML, so widening the
	 * routing cannot quietly drop anything.
	 *
	 * @return array<string, mixed>|null
	 */
	private function link_leaf( \DOMElement $el ): array|null {
		$attrs = $this->link_attrs( $el );
		if ( $attrs === null ) {
			return null;
		}

		$children = $this->link_children( $el );
		if ( $children === null ) {
			return null;
		}
		$attrs = array_merge( $attrs, $children );

		// Characters on which this side's escaper and the editor's write
		// different bytes — see LINK_ESCAPE_HAZARD.
		if ( ! self::link_hazard_free( $attrs ) ) {
			return null;
		}

		/*
		 * And whatever the emitter writes has to be what the design had.
		 * Comparing its output back against the element is what makes this a
		 * measurement rather than a hope: it catches an attribute the map
		 * forgot, an escaper or esc_url() that rewrote a value, and the
		 * `type="button"` and `aria-selected="true"` the opener adds on its
		 * own initiative.
		 */
		if ( ! $this->link_reproduces( $el, $attrs ) ) {
			return null;
		}

		return $this->leaf( 'dxai-ui/link', $this->clean_attrs( $attrs ), self::link_html( $attrs ) );
	}

	/**
	 * The block attributes for the ELEMENT itself — everything except its
	 * children — or null when one of the design's attributes has nowhere to go.
	 *
	 * Positive, and shared by both routes into dxai-ui/link, because both have
	 * to answer the same question about the same element. The leaf path was
	 * built this way; the inner-blocks path was not, and read a fixed list
	 * instead, which is why `<a id="cta" href="/x">…` lost its `id`.
	 *
	 * A name that is not in LINK_ATTR_MAP is a refusal, not an omission. The
	 * three exceptions are attributes the emitter writes on its own initiative,
	 * and each is only skipped when the emitter would write exactly what is
	 * already there.
	 *
	 * @return array<string, mixed>|null
	 */
	private function link_attrs( \DOMElement $el ): array|null {
		$tag   = strtolower( $el->tagName ) === 'button' ? 'button' : 'a';
		$class = $this->class_name( $el );
		$attrs = array( 'tagName' => $tag );
		$data  = array();

		foreach ( $el->attributes ?? array() as $attr ) {
			if ( ! $attr instanceof \DOMAttr ) {
				continue;
			}
			$name  = strtolower( $attr->name );
			$value = trim( (string) $attr->value );
			if ( isset( self::LINK_ATTR_MAP[ $name ] ) ) {
				$attrs[ self::LINK_ATTR_MAP[ $name ] ] = $name === 'class' ? $class : $value;
				continue;
			}
			/*
			 * Carry the design's own `type` when present. Emitting type="button"
			 * for every <button> used to make link_reproduces() refuse buttons
			 * that had no type attribute (Continue / step CTAs), and would also
			 * rewrite type="submit" into a plain button.
			 */
			if ( $name === 'type' && $tag === 'button' ) {
				$attrs['elType'] = $value;
				continue;
			}
			/*
			 * A bare boolean attribute reaches DOMDocument with an empty value.
			 *
			 * `<button disabled>` only. `disabled` is not a valid attribute on
			 * an anchor in the first place, and this install's kses agrees:
			 * `wp_kses_allowed_html('post')` lists `disabled` for `button` and
			 * not for `a`, so `<a class="…" href="/x" disabled>Go</a>` comes
			 * back out of wp_kses_post() as `<a class="…" href="/x">Go</a>`
			 * — measured, not assumed. Any save by a user without
			 * `unfiltered_html` would then leave save() regenerating a
			 * `disabled` the stored markup no longer has, which is exactly the
			 * mismatch that makes a block open invalid.
			 *
			 * The alternative was to widen allow_design_html() so kses keeps
			 * it. That is the wrong trade: it would sanction a non-conforming
			 * attribute on every `<a>` in every post on the site to rescue one
			 * element that the island path already keeps byte for byte, and it
			 * would still leave the block emitting invalid HTML. Declining the
			 * element is the governing rule applied as written — this is the
			 * wrong block for that element.
			 */
			if ( $name === 'disabled' && $tag === 'button' && ( $value === '' || $value === 'disabled' ) ) {
				$attrs['disabled'] = true;
				continue;
			}
			// link_open_tag() synthesises aria-selected from an `is-on` class,
			// so it round-trips only when that is where it came from.
			if ( $name === 'aria-selected' && $value === 'true' && str_contains( $class, 'is-on' ) ) {
				continue;
			}
			/*
			 * Motion / step hooks (`data-dxai-init`, `data-dxai-on`, …) used to
			 * refuse the whole element. Same contract as dxai-ui/box + text:
			 * declare them on dxaiData so the CTA stays an editable link.
			 */
			if ( str_starts_with( $name, 'data-' ) && preg_match( '/^[a-z][a-z0-9-]*$/', $name ) === 1 ) {
				$data[ $name ] = (string) $attr->value;
				continue;
			}

			return null;
		}

		if ( $data !== array() ) {
			$attrs['dxaiData'] = $data;
		}

		return $attrs;
	}

	/**
	 * Whether every string attribute is clear of the characters this side's
	 * escaper and the editor's write differently — see LINK_ESCAPE_HAZARD.
	 *
	 * @param array<string, mixed> $attrs
	 */
	private static function link_hazard_free( array $attrs ): bool {
		foreach ( $attrs as $key => $value ) {
			if ( is_string( $value ) && preg_match( self::LINK_ESCAPE_HAZARD[ $key ] ?? '/[\'<>]/', $value ) === 1 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether link_open_tag() puts back exactly the opening tag the design
	 * wrote: same tag, same attribute set, same values.
	 *
	 * The shallow half of link_reproduces(), for the inner-blocks route. It
	 * compares a CHILDLESS clone, because the children of that route are other
	 * blocks and each of them answers for itself; what this block owns is the
	 * element. Render signatures for the same reason link_reproduces() uses
	 * them — the emitter is allowed to prepend `wp-block-dxai-ui-link` and to
	 * order attributes its own way, and nothing else.
	 *
	 * @param array<string, mixed> $attrs
	 */
	private function link_open_reproduces( \DOMElement $el, array $attrs ): bool {
		$want = $el->cloneNode( false );
		if ( ! $want instanceof \DOMElement ) {
			return false;
		}
		$want->setAttribute( 'class', trim( 'wp-block-dxai-ui-link ' . $this->class_name( $el ) ) );
		$got = self::first_element( self::link_open_tag( $attrs ) . self::link_close_tag( $attrs ) );

		return $got instanceof \DOMElement && self::render_sig( $want ) === self::render_sig( $got );
	}

	/**
	 * The `text` / `labelWrap` / `suffix` / `imageUrl` attributes for a link
	 * whose children the leaf path can name, or null for anything else.
	 *
	 * The shapes accepted are the four the designs actually ship: bare text,
	 * bare text plus a trailing glyph span, `<span>label</span>`, and
	 * `<span>label</span>` plus that glyph span. The wrapper span is the whole
	 * reason `labelWrap` exists — the design writes `<a><span>Book a GTM
	 * working session</span><span class="rv-cta-arrow">→</span></a>` and the
	 * emitter used to write the label as bare text, so the `<span>` around it
	 * was deleted from the page.
	 *
	 * @return array<string, mixed>|null
	 */
	private function link_children( \DOMElement $el ): array|null {
		$items = array();
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMComment ) {
				continue;
			}
			if ( $child instanceof \DOMText ) {
				$raw     = (string) $child->textContent;
				$items[] = array( trim( $raw ) === '' ? 'space' : 'text', $raw );
				continue;
			}
			if ( ! $child instanceof \DOMElement ) {
				return null;
			}
			$items[] = array( 'element', $child );
		}
		// White space at the two ends of an element's content is not rendered,
		// so it is not something the emitter has to reproduce.
		while ( $items !== array() && $items[0][0] === 'space' ) {
			array_shift( $items );
		}
		while ( $items !== array() && $items[ count( $items ) - 1 ][0] === 'space' ) {
			array_pop( $items );
		}
		if ( $items === array() ) {
			return null;
		}

		// One bare `<img>` and nothing else: the block's imageUrl/imageAlt
		// pair. link_html() writes src and alt, so anything else on the image
		// fails the reproduction check and the link keeps its source HTML —
		// where before, `<a>text<img/></a>` emitted the image and dropped the
		// text.
		if ( count( $items ) === 1 && $items[0][0] === 'element' && strtolower( $items[0][1]->tagName ) === 'img' ) {
			return array(
				'imageUrl' => trim( $items[0][1]->getAttribute( 'src' ) ),
				'imageAlt' => trim( $items[0][1]->getAttribute( 'alt' ) ),
			);
		}

		$label = array_shift( $items );
		$out   = array();
		if ( $label[0] === 'text' ) {
			$out['text'] = self::collapse( $label[1] );
			// The label's own trailing space is the separator before the glyph.
			$gap = preg_match( '/\s$/', $label[1] ) === 1;
		} elseif ( $label[0] === 'element' && self::link_label_span( $label[1] ) ) {
			$out['text']      = self::collapse( $label[1]->textContent );
			$out['labelWrap'] = true;
			$gap              = false;
		} else {
			return null;
		}
		if ( $out['text'] === '' ) {
			return null;
		}

		if ( $items !== array() && $items[0][0] === 'space' ) {
			$gap = true;
			array_shift( $items );
		}
		if ( $items === array() ) {
			return $out;
		}
		if ( count( $items ) > 1 || $items[0][0] !== 'element' ) {
			return null;
		}
		$suffix = self::link_suffix_span( $items[0][1] );
		if ( $suffix === null ) {
			return null;
		}
		$out['suffix']      = $suffix[0];
		$out['suffixClass'] = $suffix[1];
		if ( ! $gap ) {
			/*
			 * Two source shapes exist and they render differently: the GTM hero
			 * writes the glyph span on its own line, which collapses to a
			 * space, and the audit page writes it flush against the label. The
			 * emitter has always written the space, so the attribute records
			 * its absence rather than its presence — clean_attrs() drops a
			 * false, so a default of "spaced" is the one that survives.
			 */
			$out['suffixTight'] = true;
		}

		return $out;
	}

	/**
	 * Whether this is the bare `<span>` a design wraps a CTA label in.
	 *
	 * Bare because that is all `labelWrap` can say. A wrapper carrying a class
	 * of its own would need an attribute to hold it, and until one exists the
	 * link keeps its source HTML rather than losing the class.
	 */
	private static function link_label_span( \DOMElement $el ): bool {
		if ( strtolower( $el->tagName ) !== 'span' || $el->attributes->length > 0 ) {
			return false;
		}
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMElement ) {
				return false;
			}
		}

		return self::collapse( $el->textContent ) !== '';
	}

	/**
	 * The `[ suffix, suffixClass ]` of a recognised trailing glyph span, or null.
	 *
	 * link_html() writes `<span class="…" aria-hidden="true">`, so a span
	 * without that aria-hidden would come back with one it never had.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private static function link_suffix_span( \DOMElement $el ): array|null {
		if ( strtolower( $el->tagName ) !== 'span' ) {
			return null;
		}
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMElement ) {
				return null;
			}
		}
		foreach ( $el->attributes ?? array() as $attr ) {
			if ( $attr instanceof \DOMAttr && ! in_array( strtolower( $attr->name ), array( 'class', 'aria-hidden' ), true ) ) {
				return null;
			}
		}
		if ( trim( $el->getAttribute( 'aria-hidden' ) ) !== 'true' ) {
			return null;
		}
		$class = trim( (string) preg_replace( '/\s+/', ' ', $el->getAttribute( 'class' ) ) ?? '' );
		$text  = self::collapse( $el->textContent );
		if ( $text === '' ) {
			return null;
		}
		if ( ! str_contains( $class, 'rv-cta-arrow' ) && ! in_array( $text, self::LINK_SUFFIX_GLYPHS, true ) ) {
			return null;
		}

		return array( $text, $class );
	}

	/**
	 * Whether link_html() puts back exactly what the design had: same tag, same
	 * attributes, same rendered content.
	 *
	 * Compared as render signatures rather than bytes, because the emitter
	 * normalises two things on purpose — it prepends `wp-block-dxai-ui-link` to
	 * the class list, and it writes the source's indentation as the single
	 * space HTML collapses it to.
	 *
	 * @param array<string, mixed> $attrs
	 */
	private function link_reproduces( \DOMElement $el, array $attrs ): bool {
		$want = $el->cloneNode( true );
		if ( ! $want instanceof \DOMElement ) {
			return false;
		}
		$want->setAttribute( 'class', trim( 'wp-block-dxai-ui-link ' . $this->class_name( $el ) ) );
		$got = self::first_element( self::link_html( $attrs ) );

		return $got instanceof \DOMElement && self::render_sig( $want ) === self::render_sig( $got );
	}

	/**
	 * @param array<string, mixed> $attrs
	 */
	public static function link_open_tag( array $attrs ): string {
		$tag   = ( $attrs['tagName'] ?? 'a' ) === 'button' ? 'button' : 'a';
		$class = trim( 'wp-block-dxai-ui-link ' . (string) ( $attrs['className'] ?? '' ) );
		$html  = '<' . $tag . ' class="' . esc_attr( $class ) . '"';
		if ( $tag === 'a' ) {
			$html .= ' href="' . esc_url( (string) ( $attrs['url'] ?? '' ) ) . '"';
		} else {
			// Only when the design had a type — inventing type="button" made
			// Continue-style CTAs fail link_reproduces() and stay core/html.
			$type = trim( (string) ( $attrs['elType'] ?? '' ) );
			if ( $type !== '' ) {
				$html .= ' type="' . esc_attr( $type ) . '"';
			}
		}
		// LINK_ATTR_MAP order, minus class and href, which the tag opener above
		// has already written. The save() in assets/js/blocks-editor.js sets
		// its props in this same order, so the two write the same bytes.
		foreach ( self::LINK_ATTR_MAP as $name => $key ) {
			if ( $name === 'class' || $name === 'href' ) {
				continue;
			}
			$value = trim( (string) ( $attrs[ $key ] ?? '' ) );
			if ( $value !== '' ) {
				$html .= ' ' . $name . '="' . esc_attr( $value ) . '"';
			}
		}
		// Bare, with no `=""`, because that is what @wordpress/element's
		// serializer writes for a truthy prop on a boolean attribute — measured,
		// not assumed: it emitted `disabled` against this side's `disabled=""`
		// on the first run of the parity harness. Gutenberg's validator would
		// have let the difference through, since it compares boolean attributes
		// by presence, but the two emitters have to write the same bytes or the
		// claim that they do is worthless.
		if ( ! empty( $attrs['disabled'] ) ) {
			$html .= ' disabled';
		}
		$data = is_array( $attrs['dxaiData'] ?? null ) ? $attrs['dxaiData'] : array();
		ksort( $data );
		foreach ( $data as $name => $value ) {
			$name = strtolower( (string) $name );
			if ( ! str_starts_with( $name, 'data-' ) || preg_match( '/^[a-z][a-z0-9-]*$/', $name ) !== 1 ) {
				continue;
			}
			$html .= ' ' . $name . '="' . esc_attr( (string) $value ) . '"';
		}
		if ( str_contains( $class, 'is-on' ) ) {
			$html .= ' aria-selected="true"';
		}
		$html .= '>';

		return $html;
	}

	/**
	 * @param array<string, mixed> $attrs
	 */
	public static function link_close_tag( array $attrs ): string {
		$tag = ( $attrs['tagName'] ?? 'a' ) === 'button' ? 'button' : 'a';

		return '</' . $tag . '>';
	}

	/**
	 * @param array<string, mixed> $attrs
	 */
	public static function link_html( array $attrs ): string {
		$html   = self::link_open_tag( $attrs );
		$text   = (string) ( $attrs['text'] ?? '' );
		$suffix = (string) ( $attrs['suffix'] ?? '' );
		$sclass = (string) ( $attrs['suffixClass'] ?? '' );
		$img    = (string) ( $attrs['imageUrl'] ?? '' );
		$alt    = (string) ( $attrs['imageAlt'] ?? '' );
		if ( $img !== '' ) {
			$html .= '<img src="' . esc_url( $img ) . '" alt="' . esc_attr( $alt ) . '"/>';
		} else {
			// `labelWrap` is the design's own `<span>` around the label. Without
			// it the label went out as bare text and that element was deleted
			// from the page — a node the geometry oracle counts as "in the
			// design and not the page", and one that scores 0px of error
			// because a node that does not exist contributes no pixels.
			$html .= empty( $attrs['labelWrap'] )
				? esc_html( $text )
				: '<span>' . esc_html( $text ) . '</span>';
			if ( $suffix !== '' ) {
				$html .= empty( $attrs['suffixTight'] ) ? ' ' : '';
				$html .= '<span' . ( $sclass !== '' ? ' class="' . esc_attr( $sclass ) . '"' : '' ) . ' aria-hidden="true">' . esc_html( $suffix ) . '</span>';
			}
		}

		return $html . self::link_close_tag( $attrs );
	}

	private function is_complex_link( \DOMElement $el ): bool {
		$elements = 0;
		foreach ( $el->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			++$elements;
			if ( $this->has_element_child( $child ) ) {
				return true;
			}
			// The simple path keeps a child only as text, an image, or a
			// recognised arrow glyph, so any element with no text of its own
			// would vanish. That is not just icon placeholders: the hero's
			// section chips lead with an empty sized <span> as a bullet, and
			// six of them disappeared. Recursing preserves them.
			if ( strtolower( $child->tagName ) !== 'img' && trim( (string) $child->textContent ) === '' ) {
				return true;
			}
		}

		return $elements > 2;
	}

	/**
	 * Icon buttons, lucide marks, and menu toggles must keep their inner HTML.
	 * Reconstructing them as dxai-ui/link drops empty `<i data-lucide>` nodes.
	 */
	private function keep_source_control( \DOMElement $el ): bool {
		/*
		 * Text-bearing CTAs must reach dxai-ui/link (editable label) even when
		 * they also carry Motion_Runtime hooks (`dxai-toggle-*`, data-dxai-*).
		 * Sealing those first left Continue / Book / Submit as opaque `core/html`
		 * islands — the label was uneditable. Icon-only toggles still seal below.
		 */
		if ( $this->has_text( $el ) ) {
			return false;
		}
		$class = $el->getAttribute( 'class' );
		if ( str_contains( $class, 'dxai-toggle-' ) ) {
			return true;
		}
		/*
		 * The bail on `style` is gone, verified stale rather than assumed:
		 * link_open_tag() writes `style="…"` from the `style` attribute and the
		 * editor's save() sets the same prop, so an inline-styled anchor
		 * round-trips. It was sealing 38 of the 139 a/button islands, among
		 * them 25 plain `<a style="display:block;color:…">Glossary</a>` footer
		 * links whose whole content is one editable word. Design.com's
		 * `style-hover` is a different matter and stays: Style_Hover::apply()
		 * rewrites it into a class and a CSS rule, but its pattern needs
		 * another attribute before it, so `<a style-hover="…">` on its own
		 * survives to here and no block attribute can carry it.
		 *
		 * The bail on `aria-expanded` is gone too, and for the same reason: it
		 * is now declared as `ariaExpanded` and written by both save()s, which
		 * is the whole point of this change. What still seals a toggle is the
		 * `dxai-toggle-` class above — the control, not the attribute. Measured
		 * effect of dropping this clause on the corpus: none, all 19
		 * aria-expanded controls carry that class.
		 */
		if ( $el->hasAttribute( 'style-hover' ) || $el->hasAttribute( 'styleHover' ) ) {
			return true;
		}
		if ( $el->hasAttribute( 'data-lucide' ) ) {
			return true;
		}
		// A media descendant only forces raw HTML when it IS the control. An
		// icon-only button has nothing for dxai-ui/link to carry, so sealing it
		// is right. But most designed links are text plus a trailing chevron,
		// and link() recurses for those — the icon survives as a small nested
		// block while the copy becomes editable. Bailing on mere presence sealed
		// 29 islands, and with them the bulk of the page's editable text.

		foreach ( $el->getElementsByTagName( '*' ) as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			$tag = strtolower( $child->tagName );
			if ( in_array( $tag, array( 'svg', 'i', 'img', 'picture', 'path' ), true ) ) {
				return true;
			}
			if ( $child->hasAttribute( 'data-lucide' ) || $child->hasAttribute( 'data-icon' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the element carries copy of its own, ignoring icon glyphs.
	 */
	private function has_text( \DOMElement $el ): bool {
		$text = trim( (string) preg_replace( '/\s+/', ' ', $el->textContent ) );

		return $text !== '';
	}

	private function has_element_child( \DOMElement $el ): bool {
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMElement ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function details( \DOMElement $el ): array {
		$num      = '';
		$question = '';
		$summary  = null;
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMElement && strtolower( $child->tagName ) === 'summary' ) {
				$summary = $child;
				break;
			}
		}
		if ( $summary instanceof \DOMElement ) {
			foreach ( $summary->getElementsByTagName( 'span' ) as $span ) {
				$c = $span->getAttribute( 'class' );
				if ( str_contains( $c, 'rv-faq-num' ) ) {
					$num = trim( $span->textContent );
				}
				if ( str_contains( $c, 'rv-faq-q' ) ) {
					$question = trim( $span->textContent );
				}
			}
			if ( $question === '' ) {
				$question = trim( $summary->textContent );
			}
		}
		$rest = array();
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMElement && strtolower( $child->tagName ) === 'summary' ) {
				continue;
			}
			$rest[] = $child;
		}
		$inner = $this->nodes( $rest );
		$class = $this->class_name( $el );
		$attrs = array(
			'num'       => $num,
			'question'  => $question,
			'className' => $class,
		);
		$classes = trim( 'wp-block-dxai-ui-details ' . $class );
		$open    = '<details class="' . esc_attr( $classes ) . '"><summary><span class="rv-faq-num">' . esc_html( $num ) . '</span><span class="rv-faq-q">' . esc_html( $question ) . '</span><span class="rv-faq-plus" aria-hidden="true">+</span></summary>';
		$close   = '</details>';
		$content = array( $open );
		foreach ( $inner as $_ ) {
			$content[] = null;
		}
		$content[] = $close;

		return array(
			'blockName'    => 'dxai-ui/details',
			'attrs'        => $this->clean_attrs( $attrs ),
			'innerBlocks'  => $inner,
			'innerHTML'    => $open . $close,
			'innerContent' => $content,
		);
	}

	/**
	 * Whether a `<details>` is the DevriX FAQ item dxai-ui/details reproduces:
	 * only `class` on the element, a summary built from `rv-faq-*` spans and
	 * no inline styles anywhere in it.
	 */
	private function details_is_faq_shape( \DOMElement $el ): bool {
		foreach ( $el->attributes ?? array() as $attr ) {
			if ( $attr instanceof \DOMAttr && strtolower( $attr->name ) !== 'class' ) {
				return false;
			}
		}
		foreach ( $el->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement || strtolower( $child->tagName ) !== 'summary' ) {
				continue;
			}
			if ( $child->hasAttribute( 'style' ) || ! str_contains( $child->getAttribute( 'class' ) . ' ' . $this->inner( $child ), 'rv-faq-' ) ) {
				return false;
			}
			foreach ( $child->getElementsByTagName( '*' ) as $inner ) {
				if ( $inner instanceof \DOMElement && $inner->hasAttribute( 'style' ) ) {
					return false;
				}
			}

			return true;
		}

		return false;
	}

	/** Whether this element carries text of its own, not only inside children. */
	private function has_own_text( \DOMElement $el ): bool {
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMText && trim( $child->textContent ) !== '' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function quote( \DOMElement $el ): array {
		/*
		 * core/quote holds blocks, so any text a blockquote carries directly
		 * becomes a paragraph — and a paragraph inside `.wp-block-quote` picks
		 * up core's border and padding, so the text is narrower and wraps more.
		 * One design's testimonial grew 61px that way; another, where the text
		 * shared a line with a `<span class="rv-quote-mark">“</span>`, grew 41px
		 * because the wrapping broke the shared line box.
		 *
		 * So the block is only right for a blockquote that already contains
		 * blocks and no loose text of its own.
		 */
		if ( ! $this->has_block_child( $el ) || $this->has_own_text( $el ) ) {
			return $this->box_block( $el, $this->nodes( iterator_to_array( $el->childNodes ) ) )
				?? $this->html_block( $this->outer( $el ) );
		}

		$class = $this->class_name( $el );
		$inner = $this->nodes( iterator_to_array( $el->childNodes ) );
		if ( $inner === array() ) {
			$text = trim( preg_replace( '/\s+/', ' ', $el->textContent ) ?? '' );
			if ( $text !== '' ) {
				$inner[] = $this->paragraph( esc_html( $text ), '', esc_html( $text ) );
			}
		}
		$attrs = array();
		if ( $class !== '' ) {
			$attrs['className'] = $class;
		}
		$classes = trim( 'wp-block-quote ' . $class );
		$open    = '<blockquote class="' . esc_attr( $classes ) . '">';
		$close   = '</blockquote>';
		$content = array( $open );
		foreach ( $inner as $_ ) {
			$content[] = null;
		}
		$content[] = $close;

		return array(
			'blockName'    => 'core/quote',
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => $open . $close,
			'innerContent' => $content,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function table( \DOMElement $el ): array {
		$class = $this->class_name( $el );

		/*
		 * core/table's save() always writes the className onto its <figure> and
		 * the <table> gets nothing but `has-fixed-layout`. A design that styles
		 * the table itself — `.rv-leak-table { border-collapse; width; … }` —
		 * would have those declarations land on a wrapper where they do
		 * nothing, and there is no attribute that puts them back. So a table
		 * carrying its own class keeps its source HTML; a plain one becomes an
		 * editable block, which is strictly better than raw markup.
		 */
		if ( $class !== '' ) {
			return $this->html_block( $this->outer( $el ) );
		}

		$head  = $this->table_section( $el, 'thead' );
		$body  = $this->table_section( $el, 'tbody' );
		$foot  = $this->table_section( $el, 'tfoot' );
		if ( $head === array() && $body === array() && $foot === array() ) {
			$body = $this->table_rows( $el );
		}
		if ( $head === array() && $body === array() && $foot === array() ) {
			return $this->html_block( $this->outer( $el ) );
		}

		// Not forced: table-layout:fixed changes how columns size, and a design
		// that said nothing about it did not ask for that.
		$attrs = array( 'hasFixedLayout' => false );
		if ( $head !== array() ) {
			$attrs['head'] = $head;
		}
		if ( $body !== array() ) {
			$attrs['body'] = $body;
		}
		if ( $foot !== array() ) {
			$attrs['foot'] = $foot;
		}
		$html  = '<figure class="wp-block-table">';
		$html .= '<table>';
		$html .= $this->table_markup( 'thead', $head );
		$html .= $this->table_markup( 'tbody', $body );
		$html .= $this->table_markup( 'tfoot', $foot );
		$html .= '</table></figure>';

		return $this->leaf( 'core/table', $attrs, $html );
	}

	/**
	 * @return array<int, array{cells: array<int, array<string, mixed>>}>
	 */
	private function table_section( \DOMElement $table, string $tag ): array {
		$rows = array();
		foreach ( $table->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement || strtolower( $child->tagName ) !== $tag ) {
				continue;
			}
			$rows = array_merge( $rows, $this->table_rows( $child ) );
		}

		return $rows;
	}

	/**
	 * @return array<int, array{cells: array<int, array<string, mixed>>}>
	 */
	private function table_rows( \DOMElement $parent ): array {
		$rows = array();
		foreach ( $parent->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement || strtolower( $child->tagName ) !== 'tr' ) {
				continue;
			}
			$cells = array();
			foreach ( $child->childNodes as $cell ) {
				if ( ! $cell instanceof \DOMElement ) {
					continue;
				}
				$tag = strtolower( $cell->tagName );
				if ( $tag !== 'td' && $tag !== 'th' ) {
					continue;
				}
				$item = array(
					'content' => $this->inner( $cell ),
					'tag'     => $tag,
				);
				$colspan = $cell->getAttribute( 'colspan' );
				$rowspan = $cell->getAttribute( 'rowspan' );
				$scope   = $cell->getAttribute( 'scope' );
				if ( $colspan !== '' ) {
					$item['colspan'] = $colspan;
				}
				if ( $rowspan !== '' ) {
					$item['rowspan'] = $rowspan;
				}
				if ( $scope !== '' ) {
					$item['scope'] = $scope;
				}
				$cells[] = $item;
			}
			if ( $cells !== array() ) {
				$rows[] = array( 'cells' => $cells );
			}
		}

		return $rows;
	}

	/**
	 * @param array<int, array{cells: array<int, array<string, mixed>>}> $rows
	 */
	private function table_markup( string $tag, array $rows ): string {
		if ( $rows === array() ) {
			return '';
		}
		$html = '<' . $tag . '>';
		foreach ( $rows as $row ) {
			$html .= '<tr>';
			foreach ( is_array( $row['cells'] ?? null ) ? $row['cells'] : array() as $cell ) {
				if ( ! is_array( $cell ) ) {
					continue;
				}
				$cell_tag = ( $cell['tag'] ?? 'td' ) === 'th' ? 'th' : 'td';
				$attr     = '';
				if ( ! empty( $cell['colspan'] ) ) {
					$attr .= ' colspan="' . esc_attr( (string) $cell['colspan'] ) . '"';
				}
				if ( ! empty( $cell['rowspan'] ) ) {
					$attr .= ' rowspan="' . esc_attr( (string) $cell['rowspan'] ) . '"';
				}
				if ( $cell_tag === 'th' && ! empty( $cell['scope'] ) ) {
					$attr .= ' scope="' . esc_attr( (string) $cell['scope'] ) . '"';
				}
				$html .= '<' . $cell_tag . $attr . '>' . (string) ( $cell['content'] ?? '' ) . '</' . $cell_tag . '>';
			}
			$html .= '</tr>';
		}
		$html .= '</' . $tag . '>';

		return $html;
	}

	/**
	 * The animated number as dxai-ui/countup, or null when the block cannot
	 * put back the element the design wrote.
	 *
	 * This was the one leaf path with no identity check. It rebuilt the element
	 * from `data-countup` and `data-suffix` and wrote `$value . $suffix` as the
	 * whole of its content, so anything else inside was dropped with nothing to
	 * notice it — which is not the rule the rest of this file lives by; see
	 * link_tree() for the same argument made about a fixed attribute list.
	 *
	 * What it dropped is an accessibility affordance. Every countup in the
	 * corpus is shaped
	 *
	 *     <span class="rv-num" data-countup="62" data-suffix="%">62%<span
	 *     class="sr-only">62%</span></span>
	 *
	 * and the inner span is the point of it: the visible number is animated
	 * from 0 by the runtime, so the design gives a screen reader a second,
	 * static copy of the final value. Six countups across GTM and Project Page
	 * Duplicator, and the oracle reported four lost `span.sr-only` per width on
	 * GTM for exactly this reason.
	 *
	 * So the copy travels in `srClass` — the class the design put on it, empty
	 * when there is no copy — rather than a flag that assumes `sr-only`, and
	 * its text is `$value . $suffix`, which is what all six hold. The identity
	 * check is what makes that safe rather than a guess: a copy whose text
	 * differs, or a second child of any kind, fails to reproduce and the
	 * element stays what it was.
	 *
	 * @return array<string, mixed>|null
	 */
	private function countup( \DOMElement $el ): array|null {
		$value  = $el->getAttribute( 'data-countup' );
		$suffix = $el->getAttribute( 'data-suffix' );
		$class  = $this->class_name( $el );
		$attrs  = array(
			'value'     => $value,
			'suffix'    => $suffix,
			'className' => $class,
		);

		$sr_class = $this->countup_sr_class( $el );
		if ( $sr_class !== '' ) {
			$attrs['srClass'] = $sr_class;
		}

		$html = self::countup_html( $attrs );
		if ( ! $this->countup_reproduces( $el, $class, $html ) ) {
			return null;
		}

		return $this->leaf( 'dxai-ui/countup', $this->clean_attrs( $attrs ), $html );
	}

	/**
	 * The class on the screen-reader copy inside a countup, or '' when the
	 * element holds no such copy.
	 *
	 * Deliberately narrow: exactly one element child, a `span` carrying only
	 * text and a class. Anything else is left for the identity check to refuse,
	 * so a shape this does not understand keeps its markup instead of being
	 * approximated.
	 */
	private function countup_sr_class( \DOMElement $el ): string {
		$found = '';
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMText ) {
				continue;
			}
			if ( ! $child instanceof \DOMElement || $found !== '' ) {
				return '';
			}
			if ( strtolower( $child->tagName ) !== 'span' ) {
				return '';
			}
			$attrs = 0;
			foreach ( $child->attributes ?? array() as $attr ) {
				if ( $attr instanceof \DOMAttr ) {
					++$attrs;
				}
			}
			$class = trim( $child->getAttribute( 'class' ) );
			if ( $attrs !== 1 || $class === '' ) {
				return '';
			}
			$found = $class;
		}

		return $found;
	}

	/**
	 * Whether the emitter put back the element the design wrote.
	 *
	 * The same shape as link_reproduces(): the block adds a class of its own,
	 * so the comparison is against a clone carrying it, and render_sig() walks
	 * children as well as attributes — which is what makes the screen-reader
	 * copy part of the check rather than something taken on trust.
	 */
	private function countup_reproduces( \DOMElement $el, string $class, string $html ): bool {
		$want = $el->cloneNode( true );
		if ( ! $want instanceof \DOMElement ) {
			return false;
		}
		$want->setAttribute( 'class', trim( 'wp-block-dxai-ui-countup ' . $class ) );
		$got = self::first_element( $html );

		return $got instanceof \DOMElement && self::render_sig( $want ) === self::render_sig( $got );
	}

	/**
	 * dxai-ui/countup's markup. Mirrored by save() in
	 * assets/js/blocks-editor.js, and byte for byte: bin/countup-parity.cjs
	 * runs that save() through the real @wordpress/element serializer and
	 * compares.
	 *
	 * `class` is written LAST, which looks wrong beside link_open_tag() and is
	 * not. The editor's save() hands useBlockProps.save() only the two data
	 * attributes; the class arrives afterwards, appended to the props object by
	 * core's own extraProps filters — custom-class-name adds the block's
	 * `className` attribute and generated-class-name puts
	 * `wp-block-dxai-ui-countup` in front of it. renderToString writes props in
	 * insertion order, so the editor puts `class` after the data attributes,
	 * and this side has to do the same. It used to write `class` first, and
	 * while the validator did not mind — isEqualTagAttributePairs compares
	 * attributes as a map — the editor's first save rewrote every countup's
	 * bytes for no reason the user could see.
	 *
	 * @param array<string, mixed> $attrs
	 */
	public static function countup_html( array $attrs ): string {
		$value    = (string) ( $attrs['value'] ?? '' );
		$suffix   = (string) ( $attrs['suffix'] ?? '' );
		$sr_class = trim( (string) ( $attrs['srClass'] ?? '' ) );
		$classes  = trim( 'wp-block-dxai-ui-countup ' . (string) ( $attrs['className'] ?? '' ) );

		$html = '<span data-countup="' . esc_attr( $value ) . '"'
			. ' data-suffix="' . esc_attr( $suffix ) . '"'
			. ' class="' . esc_attr( $classes ) . '">'
			. esc_html( $value . $suffix );
		if ( $sr_class !== '' ) {
			$html .= '<span class="' . esc_attr( $sr_class ) . '">' . esc_html( $value . $suffix ) . '</span>';
		}

		return $html . '</span>';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function list_block( \DOMElement $el ): array {
		$ordered = strtolower( $el->tagName ) === 'ol';
		$class   = $this->class_name( $el );

		/*
		 * core/list holds core/list-item and nothing else, so anything that is
		 * not an `li` has to stay as source HTML rather than be skipped. A
		 * design whose list rows came from a component rendering `<span>`
		 * serialized as an empty `<ol></ol>` and lost five rows of copy — the
		 * page still rendered, which is why nobody noticed.
		 */
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMElement && strtolower( $child->tagName ) !== 'li' ) {
				return $this->html_block( $this->outer( $el ) );
			}
		}

		$items = array();
		foreach ( $el->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement || strtolower( $child->tagName ) !== 'li' ) {
				continue;
			}
			$li_class = $this->class_name( $child );
			/*
			 * Per-row inline styles carry real design: one funnel tapers by
			 * giving each `<li>` its own `width`, so dropping the attribute
			 * flattened the taper and the narrowest row stopped wrapping.
			 * core/list-item has no slot for it, so it travels as dxaiStyle and
			 * assets/js/blocks-editor.js writes it back.
			 */
			$li_style = trim( $child->getAttribute( 'style' ) );
			/*
			 * And the row's data attributes.
			 *
			 * This used to rebuild the `<li>` from class and style alone, so
			 * every other attribute on it was dropped — including
			 * `data-dxai-on`, which is how a row says which state shows it.
			 * RevOps Insight Engine writes six of them, one per `<li>` of a
			 * process rail, and losing them left the five arrows that drive
			 * that rail inert: the behaviour check read `controls 3/8` and the
			 * coverage check `behaviour-markers 24/30`, both of them pointing
			 * at markup that had simply been rewritten without them.
			 *
			 * Only `data-*` travels, because `dxaiData` is the one slot
			 * core/list-item has for an unmodelled attribute and
			 * assets/js/blocks-editor.js writes back exactly the `data-`
			 * prefixed keys — anything else would serialize here and vanish on
			 * the editor's next save, which is worse than not carrying it.
			 */
			$li_data = $this->data_attrs( $child );
			$li_html = '<li' . ( $li_class !== '' ? ' class="' . esc_attr( $li_class ) . '"' : '' )
				. $this->style_attr( $li_style ) . $li_data . '>' . $this->inner( $child ) . '</li>';
			$li_attrs = array();
			if ( $li_class !== '' ) {
				$li_attrs['className'] = $li_class;
			}
			if ( $li_style !== '' ) {
				$li_attrs['dxaiStyle'] = $li_style;
			}
			$li_map = $this->data_map( $child );
			if ( $li_map !== array() ) {
				$li_attrs['dxaiData'] = $li_map;
			}
			$items[] = $this->leaf( 'core/list-item', $li_attrs, $li_html );
		}
		$attrs = array();
		if ( $ordered ) {
			$attrs['ordered'] = true;
		}
		if ( $class !== '' ) {
			$attrs['className'] = $class;
		}
		$style = trim( $el->getAttribute( 'style' ) );
		if ( $style !== '' ) {
			$attrs['dxaiStyle'] = $style;
		}
		/*
		 * And the list's own data attributes, as for its rows above. A design
		 * hangs its responsive rules on them: Semper Dry's "promise" list is
		 * `<ul data-promise>`, and `@media (max-width:1000px){[data-promise]{
		 * grid-template-columns:repeat(2,…)}}` plus a 540px rule that stacks
		 * each row. Rebuilt from class and style alone, the list lost the
		 * attribute, neither rule matched, and it stayed four columns wide on
		 * a phone: 2046px of the page's error at 390px, all of it here.
		 * assets/js/blocks-editor.js writes the map back for core/list.
		 */
		$list_map = $this->data_map( $el );
		if ( $list_map !== array() ) {
			$attrs['dxaiData'] = $list_map;
		}

		$tag     = $ordered ? 'ol' : 'ul';
		$classes = trim( 'wp-block-list ' . $class );
		$open    = '<' . $tag . ' class="' . esc_attr( $classes ) . '"' . $this->style_attr( $style ) . $this->data_attrs( $el ) . '>';
		$close   = '</' . $tag . '>';
		$content = array( $open );
		foreach ( $items as $_ ) {
			$content[] = null;
		}
		$content[] = $close;

		return array(
			'blockName'    => 'core/list',
			'attrs'        => $attrs,
			'innerBlocks'  => $items,
			'innerHTML'    => $open . $close,
			'innerContent' => $content,
		);
	}

	/**
	 * Markup this converter could not turn into a block, kept verbatim.
	 *
	 * Counted, by the element it gave up on. A raw-HTML island renders exactly
	 * right — that is what the fallback is for — so neither the geometry oracle
	 * nor block validity can see one, and for a long time nobody had a number:
	 * it turned out to be 932 of 5396 blocks across 23 pages, markup nobody can
	 * edit in the editor. The count travels out through islands() so an import
	 * can report it instead of a harness having to go and find it.
	 *
	 * @return array<string, mixed>
	 */
	private function html_block( string $html ): array {
		$html = trim( $html );
		$tag  = preg_match( '/^<([a-z][a-z0-9]*)/i', $html, $m ) === 1 ? strtolower( $m[1] ) : '(text)';
		$this->islands[ $tag ] = ( $this->islands[ $tag ] ?? 0 ) + 1;

		if ( $html === '' ) {
			return $this->leaf( Design_Html::RAW_BLOCK, array(), '' );
		}

		return $this->leaf( Design_Html::RAW_BLOCK, array(), $html );
	}

	/**
	 * How much of this conversion stayed raw HTML, as `[ tag => count ]`.
	 *
	 * Read after convert(). Sorted with the worst first, because the shape that
	 * gave up most often is the one worth a block of its own.
	 *
	 * @return array<string, int>
	 */
	public function islands(): array {
		$out = $this->islands;
		arsort( $out );

		return $out;
	}

	/**
	 * @param array<string, mixed> $attrs
	 * @return array<string, mixed>
	 */
	private function leaf( string $name, array $attrs, string $html ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		);
	}

	private function has_block_child( \DOMElement $el ): bool {
		foreach ( $el->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			$tag = strtolower( $child->tagName );
			if ( ! in_array( $tag, self::PHRASE_TAGS, true ) ) {
				return true;
			}
		}

		return false;
	}

	/** White space as HTML renders it: runs become one space, ends go away. */
	private static function collapse( string $text ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}

	/** The first element in a fragment of HTML, for checking an emitter's output. */
	private static function first_element( string $html ): \DOMElement|null {
		$dom  = new \DOMDocument( '1.0', 'UTF-8' );
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?><div id="dxai-emitter-check">' . $html . '</div>', LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$root = $dom->getElementById( 'dxai-emitter-check' );
		foreach ( $root instanceof \DOMElement ? $root->childNodes : array() as $child ) {
			if ( $child instanceof \DOMElement ) {
				return $child;
			}
		}

		return null;
	}

	/**
	 * What an element renders as, as one comparable string: its tag, its
	 * attributes as a sorted map, and its children with white space collapsed
	 * the way HTML collapses it.
	 *
	 * Two elements with the same signature put the same box, the same
	 * attributes and the same words on the page. It is deliberately blind to
	 * attribute order and to indentation, because those are the two things an
	 * emitter is allowed to change — and Gutenberg's own block validator is
	 * blind to both as well. It is NOT blind to a missing attribute, a
	 * rewritten value, a deleted element or a dropped space between two inline
	 * elements, which are the losses this converter has to refuse.
	 */
	private static function render_sig( \DOMElement $el ): string {
		$attrs = array();
		foreach ( $el->attributes ?? array() as $attr ) {
			if ( $attr instanceof \DOMAttr ) {
				$attrs[ strtolower( $attr->name ) ] = self::collapse( (string) $attr->value );
			}
		}
		ksort( $attrs );
		$open = strtolower( $el->tagName ) . '{';
		foreach ( $attrs as $name => $value ) {
			$open .= $name . '=' . $value . ';';
		}

		$kids = array();
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMComment ) {
				continue;
			}
			if ( $child instanceof \DOMText ) {
				$kids[] = (string) preg_replace( '/\s+/', ' ', (string) $child->textContent );
				continue;
			}
			$kids[] = $child instanceof \DOMElement ? self::render_sig( $child ) : '?';
		}
		if ( $kids !== array() ) {
			$last          = count( $kids ) - 1;
			$kids[0]       = ltrim( $kids[0], ' ' );
			$kids[ $last ] = rtrim( $kids[ $last ], ' ' );
		}

		return $open . '}[' . implode( '', $kids ) . ']';
	}

	private function class_name( \DOMElement $el ): string {
		return trim( preg_replace( '/\s+/', ' ', $el->getAttribute( 'class' ) ) ?? '' );
	}

	private function anchor( \DOMElement $el ): string {
		return sanitize_html_class( $el->getAttribute( 'id' ) );
	}

	private function id_attr( string $id ): string {
		return $id !== '' ? ' id="' . esc_attr( $id ) . '"' : '';
	}

	private function aria( \DOMElement $el ): string {
		$out = '';
		foreach ( array( 'aria-label', 'aria-labelledby', 'aria-hidden', 'role' ) as $name ) {
			if ( ! $el->hasAttribute( $name ) ) {
				continue;
			}
			$val = $el->getAttribute( $name );
			/*
			 * Only a bare aria-hidden means "true". The group block stores
			 * role, aria-label and aria-labelledby only when they have a value,
			 * so writing `role="true"` for an empty one put an attribute in the
			 * markup its save() never writes — an invalid block — and an empty
			 * aria-label came back as the label "true".
			 */
			if ( $val === '' && $name !== 'aria-hidden' ) {
				continue;
			}
			$out .= ' ' . $name . '="' . esc_attr( $val !== '' ? $val : 'true' ) . '"';
		}

		return $out;
	}

	private function inner( \DOMElement $el ): string {
		$html = '';
		foreach ( $el->childNodes as $child ) {
			$html .= $el->ownerDocument?->saveHTML( $child ) ?? '';
		}

		// Same libxml lower-casing as outer(): an inline SVG inside a text leaf
		// or a converted container keeps its real attribute names.
		return Svg_Attrs::restore( trim( $html ) );
	}

	private function outer( \DOMElement $el ): string {
		/*
		 * saveHTML() gives back what libxml read, and libxml lower-cased every
		 * attribute name on the way in. SVG is case-sensitive, so a raw-HTML
		 * island or a box child would carry a dead `viewbox` and a dead
		 * `strokewidth` — 58 of them across the corpus even after the JSX side
		 * was repaired, because this is a second round trip.
		 */
		return Svg_Attrs::restore( trim( $el->ownerDocument?->saveHTML( $el ) ?? '' ) );
	}

	/**
	 * @param array<string, mixed> $attrs
	 * @return array<string, mixed>
	 */
	private function clean_attrs( array $attrs ): array {
		$out = array();
		foreach ( $attrs as $key => $value ) {
			if ( $value === '' || $value === null || $value === false ) {
				continue;
			}
			$out[ $key ] = $value;
		}

		return $out;
	}
}

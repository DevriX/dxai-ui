<?php
/**
 * Compile Lovable/React TSX into HTML that keeps class names 1:1.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Jsx_Compiler {

	private const HELPERS = array( 'usereveal', 'countup', 'label', 'section', 'cn', 'clsx', 'fragment' );

	/**
	 * Element names a polymorphic component may be asked to render as, via an
	 * `as` prop. Kept to a list so a scope value that merely happens to be a
	 * lowercase string is not mistaken for a tag name — see emit_element().
	 */
	private const POLYMORPHIC_TAGS = array(
		'div', 'span', 'p', 'section', 'article', 'aside', 'header', 'footer',
		'main', 'nav', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'figure', 'figcaption',
		'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'button', 'label',
		'form', 'fieldset', 'table', 'tbody', 'tr', 'td', 'th', 'picture', 'time',
	);

	private const SKIP_ATTR = array(
		'key', 'ref', 'onclick', 'onkeydown', 'onkeyup', 'onchange', 'onsubmit',
		'onfocus', 'onblur', 'onmouseenter', 'onmouseleave', 'onpointerdown',
		'onpointerup', 'ondragstart', 'onanimationend', 'ontransitionend',
		'initial', 'animate', 'exit', 'variants', 'whilehover', 'whiletap',
		'whileinview', 'whilefocus', 'layout', 'layoutid', 'transition',
		'viewport', 'drag', 'priority', 'quality', 'unoptimized',
		'loader', 'blurdataurl', 'aschild', 'as',
	);

	/** Sentinel for "this method is not implemented", since null is a valid result. */
	private const UNHANDLED = '__dxai_unhandled__';

	/** List methods whose argument is an arrow callback — see call_callback_method(). */
	private const CALLBACK_METHODS = array(
		'filter', 'find', 'findIndex', 'some', 'every', 'map', 'flatMap',
		'forEach', 'sort', 'reduce',
	);

	/**
	 * Class-name joiners, which every Lovable design imports from its own
	 * `lib/utils` and none of which the compiler can load: the identifier is
	 * lower-case, and load_imports() only follows capitalised names. Resolved
	 * to a sentinel instead — see resolve_ident() and class_list().
	 */
	private const CLASS_JOINERS = array( 'clsx', 'cx', 'classNames', 'classnames', 'twJoin' );

	/**
	 * The joiners that also RESOLVE CONFLICTS, which is a different function.
	 *
	 * Every shadcn project defines `cn` as `twMerge(clsx(inputs))`, and
	 * twMerge drops a class a later one overrides — so `cn("text-sm", "text-base")`
	 * is `text-base` alone, while `clsx` of the same two keeps both. Treating
	 * them alike meant our class list was longer than the one the real app
	 * renders. See Tailwind\Class_Merge.
	 */
	private const CLASS_MERGERS = array( 'cn', 'twMerge' );

	/**
	 * Attributes whose arrow value is a component to CALL, not a handler.
	 *
	 * React's render-prop convention. `children` is on the list because
	 * function-as-child is the same pattern spelled differently.
	 */
	private const RENDER_PROPS = array( 'render', 'children', 'renderItem', 'component' );

	/**
	 * Attributes that name another element, which must never be empty.
	 *
	 * An `id=""` or `aria-describedby=""` is invalid HTML and points nowhere.
	 */
	private const IDREF_ATTR = array(
		'id', 'for', 'headers', 'list', 'form',
		'aria-describedby', 'aria-labelledby', 'aria-controls', 'aria-owns',
		'aria-activedescendant', 'aria-details', 'aria-errormessage',
	);

	/** Sentinel for an unbound class-name joiner, handled in call_value(). */
	private const JOINER = '__dxai_class_joiner__';

	/** The same, for a joiner that merges. */
	private const MERGER = '__dxai_class_merger__';

	/** `Object.keys` / `.values` / `.entries` — see call_value(). */
	private const OBJECT_KEYS    = '__dxai_object_keys__';
	private const OBJECT_VALUES  = '__dxai_object_values__';
	private const OBJECT_ENTRIES = '__dxai_object_entries__';
	private const ARRAY_FROM     = '__dxai_array_from__';
	private const ARRAY_IS_ARRAY = '__dxai_array_is_array__';

	/** `useId()`, which mints one stable id per call — see call_value(). */
	private const USE_ID = '__dxai_use_id__';

	/** `createContext()` and `useContext()` — see $context_values. */
	private const CREATE_CONTEXT = '__dxai_create_context__';
	private const USE_CONTEXT    = '__dxai_use_context__';

	/** Key marking a value as a React context object. */
	private const CONTEXT = '__dxai_context__';

	/** Key marking a value as an icon component — see resolve_ident(). */
	private const ICON = '__dxai_icon__';

	/**
	 * A `cva()` result: the class-variance-authority recipe, held until called.
	 *
	 * cva is how the shadcn kit expresses every variant — Button's `variant`
	 * and `size`, Badge's tone, Alert's severity. `const buttonVariants =
	 * cva(base, { variants, defaultVariants })` produces a function, and
	 * `buttonVariants({ variant, size, className })` produces the class string.
	 * Without it a Button rendered with none of its variant classes: no
	 * `bg-primary`, no `h-10` for `size="lg"`, no `border-input` for
	 * `variant="outline"`.
	 */
	private const CVA = '__dxai_cva__';

	/** A `new Date()` value, held so its getters can be answered. */
	private const DATE = '__dxai_date__';

	private string $src = '';

	private int $i = 0;

	private int $len = 0;

	/** @var array<string, mixed> */
	private array $globals = array();

	/** @var array<string, string> */
	private array $components = array();

	/** @var array<string, string> */
	private array $images = array();

	private int $depth = 0;

	/** @var array<int, string> */
	private array $external = array();

	private string $wrapper_class = '';

	private string $wrapper_style = '';

	private int $section_seq = 0;

	private bool $hero_seen = false;

	/** @var array<string, string> Local JSX name => Lucide icon id. */
	private array $lucide = array();

	/**
	 * Components declared in a file that renders through a Radix portal.
	 *
	 * Their output belongs in a portal at the end of the body, shown only while
	 * the overlay is open — see load_imports(), which records this per file, and
	 * emit_element(), which hides it.
	 *
	 * @var array<string, true>
	 */
	private array $portalled = array();

	/**
	 * Namespace import => Radix package, e.g. `AccordionPrimitive` =>
	 * `accordion`. See {@see Radix_Primitives}.
	 *
	 * @var array<string, string>
	 */
	private array $radix = array();

	/**
	 * `const Accordion = AccordionPrimitive.Root` => `['accordion', 'Root']`.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private array $radix_alias = array();

	/**
	 * `const Form = FormProvider` => `Form` => `FormProvider`, for a name the
	 * ZIP re-exports from a package it does not carry.
	 *
	 * @var array<string, string>
	 */
	private array $external_alias = array();

	/** How many ids `useId()` has handed out on this page. */
	private int $ids = 0;

	/**
	 * An id minted for a component before its subtree was read.
	 *
	 * This compiler renders children BEFORE the component that wraps them, so
	 * a provider inside a component's own body cannot reach the children the
	 * caller passed it — by then they are already a string. React renders the
	 * other way round, which is how shadcn's FormItem gets one `useId()` onto
	 * a label's `for`, the control's `id` and the description's `id`.
	 *
	 * So the id is minted at the point the component is ENTERED, published to
	 * its context for the subtree, and then handed to the component's own
	 * `useId()` when it finally renders — so both agree on the value.
	 */
	private ?string $next_id = null;

	/**
	 * Components whose body publishes a context, keyed by name.
	 *
	 * @var array<string, array{key: string, value: string}|null>
	 */
	private array $provides = array();

	/**
	 * Open Radix roots and items, innermost last.
	 *
	 * Radix decides which tab is active and which accordion panel is open from
	 * the ROOT's `defaultValue`, and the root renders after its children here —
	 * so the selection is published when the root is entered, exactly like a
	 * context. Item scopes carry the `value` a trigger and a panel have to
	 * agree on, and the ids that pair them.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $radix_scopes = array();

	/** How many Radix roots have been entered, for unique id bases. */
	private int $radix_roots = 0;

	/**
	 * Which Radix part a component's body renders, cached by component name.
	 *
	 * @var array<string, array{0: string, 1: string}|null>
	 */
	private array $radix_part = array();

	/** How many contexts `createContext()` has created. */
	private int $contexts = 0;

	/**
	 * The value stack of each live context, innermost last.
	 *
	 * React resolves `useContext(C)` to the nearest enclosing
	 * `<C.Provider value={…}>`, so a stack per context is the whole model —
	 * pushed in parse_element() before the provider's children are read and
	 * popped after, which is exactly the lifetime React gives it.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private array $context_values = array();

	/**
	 * The default each context was created with, for a `useContext()` that
	 * sits outside every provider.
	 *
	 * @var array<string, mixed>
	 */
	private array $context_defaults = array();

	/** @var array<string, string> `setOpen` => `open` for this component render. */
	private array $setters = array();

	/** @var array<string, true> Boolean UI state that a click toggles. */
	private array $toggles = array();

	/** @var array{state:string,value:string}|null */
	private ?array $last_enum_gate = null;

	/**
	 * Class projections found in the className expression being parsed.
	 *
	 * Keyed by state flag, each `{on: string[], off: string[]}`. A design keys
	 * its live state on its OWN class names — `is-on` in Project Page
	 * Duplicator's `rv-cs-tab ${idx === i ? "is-on" : ""}`, `text-acid` in
	 * DevriX Elevate's `${open === item.label ? "text-acid" : ""}` — and every
	 * one of the seven stylesheets measured has zero `[aria-expanded]`
	 * selectors, so aria alone changes nothing on screen. Only the arm the
	 * compile-time state selected used to survive; the other was discarded and
	 * the class the runtime needed was gone.
	 *
	 * @var array<string, array{on:array<int,string>, off:array<int,string>}>
	 */
	private array $cls_gates = array();

	/**
	 * True only while a className/class expression is being evaluated, so the
	 * extra gate peek costs nothing on the other few thousand expressions.
	 */
	private bool $in_class_expr = false;

	/**
	 * The same, for a `style={{ ... }}` object, plus what the arms held.
	 *
	 * ARA's CTA strip collapses through
	 * `style={{ maxHeight: stripDismissed ? 0 : 44 }}` — no comparison, so no
	 * enum gate for attach_accordion_panel() to find, and its dismiss button
	 * was one of the two controls on that page that still did nothing.
	 *
	 * @var array<int, array{flag:string, on:mixed, off:mixed}>
	 */
	private array $style_gates = array();

	private bool $in_style_expr = false;

	/**
	 * The design's own stylesheet, for deciding whether a projected class is
	 * real.
	 *
	 * Already in this compiler's hands and previously unused:
	 * Lovable_Connector::with_design_css() folds the ZIP's CSS into
	 * `harvest['css']`, and Source_Compiler passes that same harvest to
	 * compile_file(). Without it prune_projections() would have to treat
	 * `.is-on` and `.is-active` as unstyled — Project Page Duplicator and GTM
	 * Strategy Hub key on exactly those, and they are authored, not generated,
	 * so no amount of markup scanning finds them.
	 */
	private string $design_css = '';

	/**
	 * How the transition the last toggle_from_handler() resolved behaves:
	 * `mode` = toggle (collapse when already on) or assign, `step` = a relative
	 * index move, `init` = the state's authored starting value.
	 *
	 * @var array<string, string>
	 */
	private array $toggle_meta = array();

	/** @var array<string, mixed> Authored useState initial, per state name. */
	private array $state_init = array();

	/**
	 * States some handler prop actually assigns, for this component render.
	 *
	 * A projection with no transition is not a control, and marking one costs
	 * real fidelity: ARA's mobile section nav projects
	 * `activeId === s.id ? "bg-ara-green ..." : "..."`, but `setActiveId` is
	 * called only from an IntersectionObserver, never from a handler. Marking
	 * those seven chips pushed them out of `dxai-ui/link` and into
	 * `dxai-ui/html`, because Html_To_Blocks keeps any element carrying a
	 * `dxai-` marker as raw source — a block-structure change bought for a
	 * control that cannot be clicked.
	 *
	 * @var array<string, true>
	 */
	private array $driven = array();

	/**
	 * States a scroll listener drives, as `state => threshold in px`.
	 *
	 * Filled by scroll_driven(); read by attach_class_projection(), which emits
	 * the `data-dxai-scroll` marker Motion_Runtime watches.
	 *
	 * @var array<string, float>
	 */
	private array $scroll_states = array();

	/**
	 * Booleans derived from other states, as `name => member states`.
	 *
	 * `const dark = solid || open !== null` makes `dark` a state of its own,
	 * true when any member is. Filled by derived_booleans(); emitted as
	 * `data-dxai-any` beside the class projection that reads it.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $derived = array();

	/** @var array<string, array{kind:string, source:string, count:int}> */
	private array $unresolved = array();

	/**
	 * Every report since reset_unevaluated(), across instances.
	 *
	 * Source_Compiler builds a fresh compiler per page and keeps only the HTML,
	 * so an instance accessor alone is unreadable from where an import is
	 * actually driven. This register survives that, and accumulates the whole
	 * design rather than its last page.
	 *
	 * @var array<string, array{kind:string, source:string, count:int}>
	 */
	private static array $reported = array();

	/**
	 * Expressions this compile could not evaluate, keyed by source text.
	 *
	 * Every defect this file has ever shipped looked the same from outside: a
	 * label, an href or a class that silently was not there. The geometry diff
	 * in bin/design-oracle.php cannot see any of them, because the oracle is
	 * built by running this compiler — a JSX-to-HTML loss is present on both
	 * sides and the diff reports 0px while the primary navigation is blank.
	 *
	 * So the evaluator reports instead of guessing. A list rather than an
	 * exception: a page that lost one expression is still worth importing, and
	 * the caller decides whether to gate on it. Entries are deduped by source
	 * with a count, and reset by compile_file().
	 *
	 * @return array<int, array{kind:string, source:string, count:int}>
	 */
	public function unevaluated(): array {
		return array_values( $this->unresolved );
	}

	/**
	 * The same reports, for a caller that never held the compiler — a
	 * verification script after Source_Compiler::compile(), say. Read it, then
	 * reset_unevaluated() before the next design.
	 *
	 * @return array<int, array{kind:string, source:string, count:int}>
	 */
	public static function all_unevaluated(): array {
		return array_values( self::$reported );
	}

	public static function reset_unevaluated(): void {
		self::$reported = array();
	}

	/**
	 * Catch-all for the whole class of defect: JSX source that reached the page
	 * as visible text.
	 *
	 * Every parser path that abandons an expression mid-way leaves the cursor
	 * inside it, and parse_children() then reads the remainder as a text node —
	 * which is how `MihailStoychev.map((w) => w[0]).join("").slice(0, 2)}` was
	 * published as an avatar label. The individual paths are guarded now, but
	 * they are guarded one at a time and this file grows; a check on the single
	 * funnel where raw source becomes page text catches the next one without
	 * anybody having to predict it. The markers are code punctuation no design
	 * copy contains, so a match means the evaluator, not the copywriter.
	 */
	private function note_residue( string $text ): void {
		if ( $text === '' ) {
			return;
		}
		// Cheap gate first: every marker below needs one of these three.
		if ( ! str_contains( $text, '(' ) && ! str_contains( $text, '$' ) && ! str_contains( $text, '=' ) ) {
			return;
		}
		if ( 1 !== preg_match( '/=>|\$\{|\.(?:map|filter|find|join|slice|split|reduce|forEach|toFixed)\s*\(|\b(?:className|useState|useEffect|useRef)\s*[({=]/', $text ) ) {
			return;
		}
		$this->note_unevaluated( 'leaked-as-text', $text );
	}

	/**
	 * @param string $kind   Where the evaluator gave up: see the call sites.
	 * @param string $source The expression source, as authored.
	 */
	private function note_unevaluated( string $kind, string $source ): void {
		$source = trim( (string) preg_replace( '/\s+/', ' ', $source ) );
		if ( $source === '' ) {
			return;
		}
		if ( strlen( $source ) > 200 ) {
			$source = substr( $source, 0, 200 ) . '…';
		}
		$key = $kind . '|' . $source;
		$row = array(
			'kind'   => $kind,
			'source' => $source,
			'count'  => 1,
		);

		if ( isset( $this->unresolved[ $key ] ) ) {
			++$this->unresolved[ $key ]['count'];
		} elseif ( count( $this->unresolved ) < 200 ) {
			// Bounded: a pathological file must not turn a report into a leak.
			$this->unresolved[ $key ] = $row;
		}

		if ( isset( self::$reported[ $key ] ) ) {
			++self::$reported[ $key ]['count'];
		} elseif ( count( self::$reported ) < 200 ) {
			self::$reported[ $key ] = $row;
		}
	}

	/**
	 * Wrapper classes of the page root, available after compile_file().
	 *
	 * The root element's own classes carry page-level styling (background,
	 * min-height, base type) that would be lost when its children are split
	 * into separate structures, so the caller re-applies them to the page group.
	 */
	public function wrapper_class(): string {
		return $this->wrapper_class;
	}

	/**
	 * Inline declarations from the page root, to re-apply on the wrapper.
	 */
	public function wrapper_style(): string {
		return $this->wrapper_style;
	}

	/**
	 * @param array<string, mixed>  $harvest
	 * @param array<string, string> $files Sibling source files, keyed by path.
	 * @return array<int, array{name:string, type:string, html:string}>
	 */
	public function compile_file( string $source, array $harvest = array(), array $files = array() ): array {
		$source = str_replace( "\r\n", "\n", $source );
		$this->images        = $this->image_map( $harvest );
		$this->external      = array();
		$this->wrapper_class = '';
		$this->wrapper_style = '';
		$this->section_seq   = 0;
		$this->hero_seen     = false;
		$this->lucide        = array();
		$this->setters       = array();
		$this->toggles       = array();
		$this->last_enum_gate = null;
		$this->cls_gates     = array();
		$this->in_class_expr = false;
		$this->style_gates   = array();
		$this->in_style_expr = false;
		$this->design_css    = is_string( $harvest['css'] ?? null ) ? (string) $harvest['css'] : '';
		$this->toggle_meta   = array();
		$this->state_init    = array();
		$this->unresolved    = array();
		$this->load_file( $source );
		$this->load_imports( $source, $files );
		/*
		 * Consts last: a module-level `const leaders = [{ photo: hero.url }]`
		 * reads identifiers that load_imports() binds, and evaluating it first
		 * left every such value empty — three portraits on one page rendered
		 * with no src while the same asset used inline in JSX resolved fine.
		 */
		$this->bind_consts( $source );

		$root = Tsx_Section_Splitter::root_component( $source );
		if ( $root !== '' && isset( $this->components[ $root ] ) ) {
			$sections = $this->split_rendered( trim( $this->render_named( $root ) ) );
			if ( $sections !== array() ) {
				return $this->prune_projections( $sections );
			}
		}

		$order = Tsx_Section_Splitter::page_order( $source );
		if ( $order === array() ) {
			foreach ( array_keys( $this->components ) as $name ) {
				if ( ! in_array( strtolower( $name ), self::HELPERS, true ) ) {
					$order[] = $name;
				}
			}
		}

		/*
		 * The props each of those components was given at its call site. This
		 * loop used to render every one by name with NO props, so a route's
		 * `<Hero title="…" />` or `<SiteFooter variant="desktop" />` silently
		 * fell back to the component's defaults — see page_instances().
		 */
		$instances = Tsx_Section_Splitter::page_instances( $source );

		$out = array();
		foreach ( $order as $name ) {
			if ( in_array( $name, $this->external, true ) ) {
				continue;
			}
			$html = trim( $this->render_named( $name, $this->props_from_source( (string) ( $instances[ $name ] ?? '' ) ) ) );
			if ( $html === '' ) {
				continue;
			}
			$out[] = array(
				'name' => $name,
				'type' => Tsx_Section_Splitter::type_hint( $name, $this->components[ $name ] ?? $html ),
				'html' => $this->strip_markers( $html ),
			);
		}

		if ( $out === array() ) {
			$blob = '';
			if ( $root !== '' ) {
				$blob = trim( $this->strip_markers( $this->render_named( $root ) ) );
			}
			if ( $blob === '' ) {
				foreach ( array_keys( $this->components ) as $name ) {
					if ( in_array( strtolower( (string) $name ), self::HELPERS, true ) ) {
						continue;
					}
					$blob = trim( $this->strip_markers( $this->render_named( (string) $name ) ) );
					if ( $blob !== '' ) {
						$root = (string) $name;
						break;
					}
				}
			}
			if ( $blob !== '' ) {
				$out[] = array(
					'name' => $root !== '' ? $root : 'Page',
					'type' => 'section',
					'html' => $blob,
				);
			}
		}

		return $this->prune_projections( $out );
	}

	/**
	 * Drop every projected class that nothing on the finished page can select.
	 *
	 * A projection naming a class no stylesheet matches is the same defect as
	 * the 396 `aria-expanded` attributes this corpus emits against zero
	 * `[aria-expanded]` selectors: correct-looking markup that moves no pixel.
	 * Measured before this ran: 28 distinct classes in `data-dxai-on` /
	 * `data-dxai-off`, 21 of them selectable — 75%.
	 *
	 * The seven that were not are all Tailwind utilities —
	 * `pointer-events-auto`, `translate-y-0`, `opacity-100`, `rotate-180` — and
	 * they are not misnamed. Both class harvesters that feed the generated
	 * sheet read `class="…"` / `className="…"` only
	 * (Tailwind\Engine::extract_classes(), Tailwind_Purger::extract_classes()),
	 * so a utility that exists ONLY inside a data attribute is never a
	 * candidate and is purged out of pattern-<id>.css. Their paired off-classes
	 * survive because the compile-time closed state writes them into a real
	 * class attribute.
	 *
	 * So the test here is exactly the one the sheet will apply, and it needs no
	 * access to the sheet:
	 *   the design's own CSS selects on `.token`            — harvest['css']
	 *   or the token is in a real class attribute on this page — the candidate
	 *                                                            set itself
	 * Validated against the seven pattern-<id>.css files actually on disk:
	 * 28 of 28 predictions correct, no class dropped that ships and none kept
	 * that does not.
	 *
	 * Losing an on-arm is not the same as losing the behaviour. Every utility
	 * dropped here is the CSS default of the off-arm token beside it —
	 * `opacity-100` against `opacity-0`, `pointer-events-auto` against
	 * `pointer-events-none`, `translate-y-0` against `-translate-y-1` — so
	 * removing the off-arm alone still opens the mega-menu. `rotate-180` on the
	 * chevron is the one real loss, and it never rotated: the class was not in
	 * the sheet.
	 *
	 * @param array<int, array{name:string, type:string, html:string}> $sections
	 * @return array<int, array{name:string, type:string, html:string}>
	 */
	private function prune_projections( array $sections ): array {
		$all = '';
		foreach ( $sections as $section ) {
			$all .= (string) ( $section['html'] ?? '' );
		}
		if ( ! str_contains( $all, 'data-dxai-on' ) && ! str_contains( $all, 'data-dxai-off' ) ) {
			return $sections;
		}

		$corpus = array();
		if ( preg_match_all( '/class(?:Name)?="([^"]*)"/', $all, $found ) ) {
			foreach ( $found[1] as $chunk ) {
				foreach ( preg_split( '/\s+/', html_entity_decode( $chunk, ENT_QUOTES ) ) ?: array() as $token ) {
					$token = trim( $token );
					if ( $token !== '' ) {
						$corpus[ $token ] = true;
					}
				}
			}
		}

		$css     = $this->design_css;
		$dropped = array();
		/*
		 * Every token any projection names, asked of the Tailwind engine in one
		 * go: it is the same compile the page's own sheet will run, and the
		 * answer is authoritative where the two tests below are inferences.
		 * One call for the whole design — the engine reads a markup string, so
		 * a synthetic class attribute holding every candidate is enough.
		 */
		$compilable = array();
		if ( preg_match_all( '/ data-dxai-(?:on|off)="([^"]*)"/', $all, $lists ) ) {
			$candidates = array();
			foreach ( $lists[1] as $chunk ) {
				foreach ( preg_split( '/\s+/', (string) $chunk ) ?: array() as $token ) {
					$token = trim( $token );
					if ( $token !== '' ) {
						$candidates[ $token ] = true;
					}
				}
			}
			if ( $candidates !== array() && Tailwind_Purger::engine_available() ) {
				$probe   = '<div class="' . implode( ' ', array_keys( $candidates ) ) . '"></div>';
				$missing = array();
				foreach ( ( new Tailwind_Purger() )->unresolved( $probe, $css ) as $class ) {
					$missing[ $class ] = true;
				}
				foreach ( array_keys( $candidates ) as $token ) {
					if ( ! isset( $missing[ $token ] ) ) {
						$compilable[ $token ] = true;
					}
				}
			}
		}
		// Memoised per token: css_selects() walks the whole sheet, and GTM
		// Strategy Hub ships 60 KB of it while its 6 projections name one
		// class between them.
		$verdict = array();
		foreach ( $sections as $index => $section ) {
			$html = (string) ( $section['html'] ?? '' );
			if ( ! str_contains( $html, 'data-dxai-' ) ) {
				continue;
			}

			$html = (string) preg_replace_callback(
				'/ data-dxai-(on|off)="([^"]*)"/',
				static function ( array $m ) use ( $corpus, $css, $compilable, &$dropped, &$verdict ): string {
					$keep = array();
					foreach ( preg_split( '/\s+/', $m[2] ) ?: array() as $token ) {
						$token = trim( $token );
						if ( $token === '' ) {
							continue;
						}
						if ( ! array_key_exists( $token, $verdict ) ) {
							$verdict[ $token ] = isset( $compilable[ $token ] )
								|| isset( $corpus[ $token ] )
								|| self::css_selects( $css, $token );
						}
						if ( $verdict[ $token ] ) {
							$keep[] = $token;
							continue;
						}
						$dropped[ $token ] = ( $dropped[ $token ] ?? 0 ) + 1;
					}

					return $keep === array() ? '' : ' data-dxai-' . $m[1] . '="' . implode( ' ', $keep ) . '"';
				},
				$html
			);

			// A marker with nothing left to project is the defect this method
			// exists to remove, so it goes too — and with it the reason
			// Html_To_Blocks would have sealed the element as raw source.
			if ( str_contains( $html, 'dxai-cls-' ) ) {
				$html = (string) preg_replace_callback(
					'/<[a-zA-Z][^\s>]*(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>/',
					static function ( array $m ): string {
						$tag = $m[0];
						if ( ! str_contains( $tag, 'dxai-cls-' ) || str_contains( $tag, 'data-dxai-on="' ) || str_contains( $tag, 'data-dxai-off="' ) ) {
							return $tag;
						}

						return (string) preg_replace( '/\s*\bdxai-cls-[A-Za-z0-9_-]+/', '', $tag );
					},
					$html
				);
			}

			$sections[ $index ]['html'] = $html;
		}

		foreach ( $dropped as $token => $count ) {
			$this->note_unevaluated( 'projection-class-unstyled', (string) $token . ' x' . $count );
		}

		return $sections;
	}

	/**
	 * Whether a stylesheet selects on `.$class`.
	 *
	 * No regex: Tailwind class names carry `:`, `/`, `.`, `[` and `!`, all of
	 * which a stylesheet escapes with a backslash at arbitrary positions
	 * (`.md\:text-2xl`, `.text-ink\/70`, `.w-\[min\(52rem\)\]`), so the
	 * comparison walks the two strings and skips backslashes rather than trying
	 * to reproduce the escaping.
	 */
	private static function css_selects( string $css, string $class ): bool {
		if ( $css === '' || $class === '' ) {
			return false;
		}

		$len  = strlen( $css );
		$want = strlen( $class );
		$from = 0;
		// Cursor loop: `$from` is set past the dot found on every iteration, so
		// it strictly increases and the scan cannot stall.
		while ( true ) {
			$at = strpos( $css, '.', $from );
			if ( false === $at ) {
				return false;
			}
			$from = $at + 1;
			$i    = $at + 1;
			$k    = 0;
			while ( $k < $want && $i < $len ) {
				if ( '\\' === $css[ $i ] ) {
					++$i;
					continue;
				}
				if ( $css[ $i ] !== $class[ $k ] ) {
					break;
				}
				++$i;
				++$k;
			}
			if ( $k !== $want ) {
				continue;
			}
			$next = $i < $len ? $css[ $i ] : '';
			if ( '' === $next || ( '\\' !== $next && preg_match( '/[A-Za-z0-9_-]/', $next ) !== 1 ) ) {
				return true;
			}
		}
	}

	/**
	 * Pull component definitions out of the files this page imports.
	 *
	 * A Lovable page routinely keeps its header and footer in
	 * `@/components/...`; without loading those the page renders with holes
	 * where its landmarks should be. Definitions already present in the page
	 * file win, and package imports are recorded as unresolvable instead.
	 *
	 * @param array<string, string> $files
	 */
	/**
	 * Bind `const Accordion = AccordionPrimitive.Root;`.
	 *
	 * Half of every shadcn file is re-exports in exactly this shape — the
	 * wrapper only exists for the parts that need classes — so resolving the
	 * dotted tag without also resolving the alias would still lose the
	 * outermost element of every such component. `<Accordion>` is not a member
	 * expression by the time the JSX sees it; it is a plain capitalised name
	 * bound to one.
	 */
	private function bind_radix_aliases( string $code ): void {
		if ( $this->radix === array() ) {
			return;
		}
		if ( ! preg_match_all( '/\bconst\s+([A-Z][A-Za-z0-9_]*)\s*=\s*([A-Za-z_$][\w$]*)\.([A-Za-z_$][\w$]*)\s*;/', $code, $rows, PREG_SET_ORDER ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$package = $this->radix[ $row[2] ] ?? '';
			if ( $package === '' ) {
				continue;
			}
			$this->radix_alias[ $row[1] ] = array( $package, $row[3] );
		}
	}

	/**
	 * Bind `const Form = FormProvider;` — a re-export of a package's own name.
	 *
	 * shadcn's form.tsx opens with exactly that, and it is the tag the page
	 * writes: `<Form {...form}>`. Without the alias, `Form` was an unresolved
	 * component whose name says nothing, so it became a placeholder `<span>`
	 * wrapping the whole `<form>` — one extra level, which is enough to
	 * unalign every node beneath it against the design's tree.
	 */
	private function bind_external_aliases( string $code ): void {
		if ( $this->external === array() ) {
			return;
		}
		if ( ! preg_match_all( '/\bconst\s+([A-Z][A-Za-z0-9_]*)\s*=\s*([A-Z][A-Za-z0-9_]*)\s*;/', $code, $rows, PREG_SET_ORDER ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			// Only for a name the ZIP cannot define, so a design's own
			// `const A = B` between two of its components is untouched.
			if ( in_array( $row[2], $this->external, true ) && ! isset( $this->components[ $row[2] ] ) ) {
				$this->external_alias[ $row[1] ] = $row[2];
			}
		}
	}

	private function load_imports( string $source, array $files ): void {
		$seen  = array();
		$queue = array( array( $source, '' ) );

		for ( $round = 0; $round < 8 && $queue !== array(); $round++ ) {
			$next = array();
			foreach ( $queue as $item ) {
				$imports        = Tsx_Section_Splitter::imports( $item[0] );
				$this->external = array_values( array_unique( array_merge( $this->external, $imports['external'] ) ) );
				/*
				 * Which namespace import is which Radix package. A shadcn file
				 * is a wrapper over one — `import * as AccordionPrimitive from
				 * "@radix-ui/react-accordion"` — and the part names alone do
				 * not say what to render: `Root` is a `div` for an accordion,
				 * a `nav` for a navigation menu and nothing at all for a
				 * dialog.
				 */
				foreach ( $imports['packages'] ?? array() as $name => $module ) {
					$package = Radix_Primitives::package( (string) $module );
					if ( $package !== '' ) {
						$this->radix[ (string) $name ] = $package;
					}
				}
				$this->bind_radix_aliases( $item[0] );
				$this->bind_external_aliases( $item[0] );
				foreach ( $imports['lucide'] ?? array() as $local => $icon ) {
					if ( is_int( $local ) ) {
						$this->lucide[ (string) $icon ] = Tsx_Section_Splitter::icon_id( (string) $icon );
						continue;
					}
					$this->lucide[ (string) $local ] = (string) $icon;
				}
				$this->bind_assets( $imports['assets'] );

				foreach ( $imports['local'] as $name => $module ) {
					$path = $this->resolve_module( $module, $item[1], $files );
					if ( $path === '' || isset( $seen[ $path ] ) ) {
						continue;
					}
					$seen[ $path ] = true;
					$code          = str_replace( "\r\n", "\n", (string) $files[ $path ] );

					/*
					 * This file's own asset imports, before its consts are
					 * evaluated below. Otherwise a component that collects its
					 * pictures into a module-level array —
					 * `const cases = [{ img: caseUnblu }]` — read an unbound
					 * identifier and three case-study images rendered with no
					 * src. The file's assets would only be bound on the next
					 * round, once it became a queue item in its own right.
					 */
					$child_imports = Tsx_Section_Splitter::imports( $code );
					$this->bind_assets( $child_imports['assets'] );

					/*
					 * Its icon imports too, and for the same reason: SiteFooter
					 * puts the component itself in the row —
					 * `{ label: "LinkedIn", href: …, Icon: Linkedin }` — so the
					 * name has to be resolvable before that const is evaluated,
					 * not one round later.
					 */
					foreach ( $child_imports['lucide'] ?? array() as $local_name => $icon_id ) {
						if ( is_string( $local_name ) && ! isset( $this->lucide[ $local_name ] ) ) {
							$this->lucide[ $local_name ] = (string) $icon_id;
						}
					}

					// Same ordering rule as compile_file(): a data array that
					// calls a module-scope helper — SiteHeader's `nav`, built
					// out of `link(label, href)` — needs the helper bound first.
					$this->bind_arrow_consts( $code );

					foreach ( Tsx_Section_Splitter::const_blocks( $code ) as $const => $block ) {
						if ( isset( $this->globals[ $const ] ) ) {
							continue;
						}
						$eq = strpos( $block, '=' );
						if ( false === $eq ) {
							continue;
						}
						$rhs = rtrim( $this->strip_as_cast( trim( substr( $block, $eq + 1 ) ) ), "; \t\n" );
						$this->globals[ $const ] = $this->eval_source( $rhs, $this->globals );
					}

					/*
					 * Whether this FILE renders through a Radix portal, recorded
					 * per component while the file is still in hand.
					 *
					 * A portal's content does not live where it is written — in
					 * the real app it is appended to the body and only while the
					 * overlay is open. Rendered inline it is simply visible, and
					 * the shadcn overlays are `fixed z-50` centred panels, so a
					 * Dialog body covered the page. The `data-[state=closed]:`
					 * classes do not save it: those are Tailwind variants keyed
					 * on a `data-state` attribute that is never emitted.
					 *
					 * The test WAS per file, because the portal is usually a
					 * local alias — dialog.tsx has `const DialogPortal =
					 * DialogPrimitive.Portal` and DialogContent's own body
					 * only mentions `<DialogPortal>`. But per file marks every
					 * component the file declares, and select.tsx declares the
					 * TRIGGER: the visible control, a 320px-wide button, came
					 * out `display:none`. The same was true of every overlay's
					 * trigger — the button you click to open a Dialog was
					 * hidden on any page that used one.
					 *
					 * So: per component, with the file's portal aliases in
					 * hand, which is what per-file was standing in for. A
					 * component is portalled when ITS OWN body renders a
					 * portal, directly or through one of those aliases. That
					 * still keeps `CardContent` visible — card.tsx has no
					 * portal at all — which is what a name rule on `*Content`
					 * would have broken.
					 */
					$portal_aliases = array();
					if ( preg_match_all( '/\bconst\s+([A-Za-z_$][\w$]*)\s*=\s*[A-Za-z_$][\w$]*\.Portal\s*;/', $code, $aliases ) ) {
						$portal_aliases = $aliases[1];
					}

					foreach ( Tsx_Section_Splitter::extract_components_public( $code ) as $fn ) {
						$fn_name = (string) $fn['name'];
						if ( ! isset( $this->components[ $fn_name ] ) ) {
							$own = (string) $fn['code'];
							$this->components[ $fn_name ] = $own;

							$portalled = str_contains( $own, '.Portal' );
							foreach ( $portal_aliases as $alias ) {
								// `#` delimited: a `/` inside a character class
								// ends a `/`-delimited pattern in PHP.
								if ( preg_match( '#<' . preg_quote( $alias, '#' ) . '[\s/>]#', $own ) === 1 ) {
									$portalled = true;
									break;
								}
							}
							if ( $portalled ) {
								$this->portalled[ $fn_name ] = true;
							}
						}
					}
					foreach ( self::builtin_named_components( $code ) as $fn_name => $own ) {
						if ( ! isset( $this->components[ $fn_name ] ) ) {
							$this->components[ $fn_name ] = $own;
						}
					}

					$next[] = array( $code, $path );
				}
			}
			$queue = $next;
		}

		$this->external = array_values( array_diff( $this->external, array_keys( $this->components ) ) );
	}

	/**
	 * Point asset identifiers at the media the harvester sideloaded.
	 *
	 * `import heroImg from "@/assets/hero.jpg"` leaves `src={heroImg}` with
	 * nothing to resolve unless the identifier is bound to the uploaded URL.
	 *
	 * @param array<string, string> $assets
	 */
	private function bind_assets( array $assets ): void {
		foreach ( $assets as $name => $module ) {
			if ( isset( $this->globals[ $name ] ) ) {
				continue;
			}
			// The harvester rewrites asset specifiers to the uploaded URL in
			// place, so the module is often already the final address; anything
			// still relative never resolved and would only yield a broken src.
			$url = $this->resolve_src( $module );
			if ( 1 !== preg_match( '#^(?:https?://|//|/|data:)#i', $url ) ) {
				continue;
			}
			/*
			 * Always the object shape. Some exports ship assets as descriptors
			 * and read a property — `import hero from "@/assets/x.png.asset.json"`
			 * then `src={hero.url}` — while others use the identifier directly
			 * as `src={hero}`. Keying that off whether the specifier ended in
			 * `.json` broke as soon as the harvester rewrote the specifier to
			 * the uploaded URL. stringify_attr() unwraps a lone `url`, so both
			 * spellings resolve to the same address.
			 */
			$this->globals[ $name ] = array( 'url' => $url );
		}
	}

	/**
	 * Map an import specifier onto a key in the harvested file set.
	 *
	 * @param array<string, string> $files
	 */
	private function resolve_module( string $module, string $from, array $files ): string {
		if ( str_starts_with( $module, '@/' ) || str_starts_with( $module, '~/' ) ) {
			$base = 'src/' . substr( $module, 2 );
		} elseif ( str_starts_with( $module, '.' ) ) {
			$dir  = $from === '' ? '' : dirname( $from );
			$base = $dir === '' || $dir === '.' ? ltrim( $module, './' ) : $dir . '/' . $module;
		} else {
			return '';
		}

		$base  = (string) preg_replace( '#/\./#', '/', $base );
		while ( preg_match( '#[^/]+/\.\./#', $base ) ) {
			$base = (string) preg_replace( '#[^/]+/\.\./#', '', $base, 1 );
		}
		$base = ltrim( $base, '/' );

		$candidates = array();
		foreach ( array( '.tsx', '.ts', '.jsx', '.js', '/index.tsx', '/index.ts', '/index.jsx', '/index.js', '' ) as $suffix ) {
			$candidates[] = $base . $suffix;
		}

		$lookup = array();
		foreach ( array_keys( $files ) as $path ) {
			$lookup[ ltrim( str_replace( '\\', '/', (string) $path ), './' ) ] = $path;
		}

		foreach ( $candidates as $candidate ) {
			if ( isset( $lookup[ $candidate ] ) ) {
				return (string) $lookup[ $candidate ];
			}
		}

		foreach ( $candidates as $candidate ) {
			foreach ( $lookup as $norm => $path ) {
				if ( $candidate !== '' && str_ends_with( $norm, '/' . $candidate ) ) {
					return (string) $path;
				}
			}
		}

		return '';
	}

	/**
	 * Break the rendered page into the structures the plugin persists.
	 *
	 * Splitting happens on the rendered tree rather than on component names so
	 * that sections written inline in the page body are kept, and so a section
	 * assembled from prop-driven helpers is captured with its props applied.
	 *
	 * @return array<int, array{name:string, type:string, html:string}>
	 */
	private function split_rendered( string $html ): array {
		if ( $html === '' ) {
			return array();
		}

		$dom  = new \DOMDocument( '1.0', 'UTF-8' );
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?><div id="dxai-page-root">' . $html . '</div>', LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$root = $dom->getElementById( 'dxai-page-root' );
		if ( ! $root instanceof \DOMElement ) {
			return array();
		}

		$nodes = $this->section_level( $root );

		$out = array();
		foreach ( $nodes as $node ) {
			$markup = (string) $dom->saveHTML( $node );
			/*
			 * libxml lower-cases every attribute name on the way in, and SVG is
			 * case-sensitive: `viewBox` came out as `viewbox` and `strokeWidth`
			 * as `strokewidth`, which are not attributes at all — the artwork
			 * loses its coordinate system and its line weights. Restored at the
			 * one place the round trip happens, so the section markup and
			 * `source_html` both carry the spelling the design wrote.
			 */
			$markup = Svg_Attrs::restore( $markup );
			$markup = trim( $this->strip_markers( $markup ) );
			if ( $markup === '' ) {
				continue;
			}
			$textless = wp_strip_all_tags( $markup ) === '';
			$visual   = str_contains( $markup, '<img' )
				|| str_contains( $markup, '<svg' )
				|| str_contains( $markup, '<i ' )
				|| str_contains( $markup, 'data-lucide' )
				|| str_contains( $markup, 'dxai-toggle-' );
			if ( $textless && ! $visual ) {
				continue;
			}
			$name   = $this->section_name( $node );
			$out[]  = array(
				'name' => $name,
				'type' => $this->section_type( $node, $name, $markup ),
				'html' => $markup,
			);
		}

		return $out;
	}

	/**
	 * The level whose children are the page's bands.
	 *
	 * A generated page wraps its bands in anything from nothing to
	 * `<div><main><div class="edge space-y-4">`, and the bands themselves can
	 * sit beside a `<header>` and `<footer>` inside a `<main>`. Taking the root
	 * children, or descending exactly one level, therefore captured the whole
	 * page body as a single structure on four of seven real designs — one of
	 * them ended up with no reusable header or footer at all.
	 *
	 * Two moves get to the right level without losing any box:
	 *
	 * 1. While a level holds exactly one in-flow container, descend into it and
	 *    carry its class and inline style up to the page wrapper. The wrapper
	 *    is a real element in the saved page, so `space-y-4` and `py-6` keep
	 *    working, and they keep applying once to the group rather than once per
	 *    band. Anything out of flow at that level (a fixed CTA) stays a sibling.
	 * 2. Then unwrap a `<main>` that carries no styling of its own, so the
	 *    header / bands / footer become siblings. A `<main>` with a class is
	 *    left alone: its box would have nowhere to go, and a page wrapper is the
	 *    wrong home for it while a header and footer sit outside.
	 *
	 * @return array<int, \DOMElement>
	 */
	private function section_level( \DOMElement $root ): array {
		$nodes  = $this->element_children( $root );
		$class  = array();
		$styles = array();

		while ( true ) {
			$flow = array();
			$out  = array();
			foreach ( $nodes as $node ) {
				if ( $this->is_out_of_flow( $node ) ) {
					$out[] = $node;
					continue;
				}
				$flow[] = $node;
			}
			if ( count( $flow ) !== 1 || ! $this->is_page_container( $flow[0] ) ) {
				break;
			}
			$inner = $this->element_children( $flow[0] );
			if ( count( $inner ) < 2 ) {
				break;
			}
			$class[]  = trim( $flow[0]->getAttribute( 'class' ) );
			$styles[] = trim( $flow[0]->getAttribute( 'style' ) );
			$nodes    = array_merge( $inner, $out );
		}

		$expanded = array();
		foreach ( $nodes as $node ) {
			// Unwrapped with its id moved to the first band, so an anchor to
			// it (a skip link) still lands; it used to be dropped. A style of
			// just display:block counts as none — see Design_Html.
			if ( Design_Html::is_plain_main( $node ) ) {
				foreach ( Design_Html::unwrap_main( $node ) as $child ) {
					$expanded[] = $child;
				}
				continue;
			}
			$expanded[] = $node;
		}

		// The root often carries the page's base font as an inline style;
		// dropping it leaves the whole document on the wrong family, so it
		// travels with the class.
		$this->wrapper_class = trim( implode( ' ', array_filter( $class ) ) );
		$this->wrapper_style = implode( ';', array_filter( array_map( static fn( $s ) => rtrim( $s, ';' ), $styles ) ) );

		return $expanded;
	}

	/** A wrapper around the page body, as opposed to a band of it. */
	private function is_page_container( \DOMElement $node ): bool {
		if ( trim( $node->getAttribute( 'id' ) ) !== '' ) {
			return false;
		}

		return in_array( strtolower( $node->nodeName ), array( 'div', 'main' ), true );
	}

	/** Fixed and absolute elements are siblings of the flow, not part of it. */
	private function is_out_of_flow( \DOMElement $node ): bool {
		$class = ' ' . preg_replace( '/\s+/', ' ', $node->getAttribute( 'class' ) ) . ' ';

		return str_contains( $class, ' fixed ' ) || str_contains( $class, ' absolute ' );
	}

	/**
	 * @return array<int, \DOMElement>
	 */
	private function element_children( \DOMElement $node ): array {
		$out = array();
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMElement ) {
				$out[] = $child;
			}
		}

		return $out;
	}

	private function is_landmark( \DOMElement $node ): bool {
		return in_array( strtolower( $node->nodeName ), array( 'header', 'footer', 'nav', 'section', 'main', 'article', 'form' ), true );
	}

	private function section_name( \DOMElement $node ): string {
		$id = trim( $node->getAttribute( 'id' ) );
		if ( $id !== '' ) {
			return $this->titleize( $id );
		}

		$component = trim( $node->getAttribute( 'data-dxai-component' ) );
		// A design's own `Section` is a band wrapper too: its heading names it.
		if ( $component !== '' && ! preg_match( '/^(?:FadeUp|Reveal|Animate|Motion|Fade|Wrapper|Container)|^Section$/i', $component ) ) {
			return $component;
		}

		foreach ( array( 'h1', 'h2', 'h3' ) as $tag ) {
			$found = $node->getElementsByTagName( $tag );
			if ( $found->length > 0 ) {
				$text = trim( wp_strip_all_tags( (string) $found->item( 0 )->textContent ) );
				if ( $text !== '' ) {
					return $this->clip( $text );
				}
			}
		}

		++$this->section_seq;
		return 'Section ' . $this->section_seq;
	}

	private function section_type( \DOMElement $node, string $name, string $markup ): string {
		$tag = strtolower( $node->nodeName );
		if ( $tag === 'header' ) {
			return 'header';
		}
		if ( $tag === 'footer' ) {
			return 'footer';
		}
		if ( $tag === 'nav' ) {
			return 'navigation';
		}
		if ( str_contains( $markup, '<form' ) ) {
			return 'form';
		}

		$component = trim( $node->getAttribute( 'data-dxai-component' ) );
		$hint      = Tsx_Section_Splitter::type_hint( $component !== '' ? $component : $name, $markup );
		if ( $hint !== 'section' ) {
			return $hint;
		}

		$hint = Tsx_Section_Splitter::type_hint( $name, $markup );
		if ( $hint !== 'section' ) {
			return $hint;
		}

		// A page states its subject in a single h1; the block carrying it is the
		// hero even when neither the component name nor the id says so.
		if ( ! $this->hero_seen && $node->getElementsByTagName( 'h1' )->length > 0 ) {
			$this->hero_seen = true;
			return 'hero';
		}

		return 'section';
	}

	private function titleize( string $value ): string {
		$value = trim( (string) preg_replace( '/[-_]+/', ' ', $value ) );
		return $value === '' ? 'Section' : ucwords( $value );
	}

	private function clip( string $text ): string {
		$text = (string) preg_replace( '/\s+/', ' ', $text );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > 60 ) {
			return rtrim( mb_substr( $text, 0, 60 ) ) . '…';
		}

		return $text;
	}

	private function strip_markers( string $html ): string {
		return (string) preg_replace( '/\s*data-dxai-component="[^"]*"/', '', $html );
	}

	/**
	 * Props for a component instance whose call site is only available as
	 * source text.
	 *
	 * The section-splitting path in compile_file() has a component's NAME and
	 * the raw attributes from the route, but not a parsed element — so the
	 * attribute text is run through the same parser the JSX path uses rather
	 * than through a second, divergent one. The parser's cursor is saved and
	 * restored because this runs in the middle of nothing: compile_file() is
	 * not parsing at that point, but a future caller might be.
	 *
	 * @return array<string, mixed>
	 */
	private function props_from_source( string $attr_source ): array {
		if ( trim( $attr_source ) === '' ) {
			return array();
		}

		$prev_src = $this->src;
		$prev_i   = $this->i;
		$prev_len = $this->len;

		// The trailing `/>` gives parse_attrs() the terminator it expects.
		$this->src = $attr_source . ' />';
		$this->i   = 0;
		$this->len = strlen( $this->src );

		try {
			$attrs = $this->parse_attrs( $this->globals );
		} catch ( \Throwable $e ) {
			$this->note_unevaluated( 'section-props', $attr_source );
			$attrs = array();
		}

		$this->src = $prev_src;
		$this->i   = $prev_i;
		$this->len = $prev_len;

		return $this->props_from_attrs( $attrs );
	}

	/**
	 * Whether a guarded `return null` fires before the component's JSX.
	 *
	 * Only the `null` form, and only when the condition evaluates: a guard we
	 * cannot read must leave the component rendering, because dropping a
	 * section on an unreadable condition is far worse than an extra empty
	 * element. Bare `return null` with no condition counts — a component whose
	 * first statement is that renders nothing in React either.
	 *
	 * @param array<string, mixed> $scope
	 */
	private function returns_early( string $code, array $scope ): bool {
		$jsx_at = strpos( $code, 'return (' );
		$head   = $jsx_at === false ? $code : substr( $code, 0, $jsx_at );

		if ( ! preg_match_all(
			'/\breturn\s+null\s*;|\bif\s*\(((?:[^()]|\([^()]*\))*)\)\s*(?:\{\s*)?return\s+null\s*;/',
			$head,
			$guards,
			PREG_SET_ORDER
		) ) {
			return false;
		}

		foreach ( $guards as $guard ) {
			$condition = trim( (string) ( $guard[1] ?? '' ) );
			if ( $condition === '' ) {
				// An unconditional `return null` above the JSX.
				return true;
			}
			/*
			 * Only when every name in the condition is actually bound.
			 *
			 * An unbound identifier evaluates to null, and `!null` is true —
			 * so `if (!items.length) return null` on a list this compiler
			 * could not resolve would silently delete the whole component.
			 * Dropping a section on a condition we cannot read is far worse
			 * than the empty element this guard exists to remove, so an
			 * unreadable guard leaves the component rendering.
			 */
			if ( ! $this->condition_is_bound( $condition, $scope ) ) {
				$this->note_unevaluated( 'return-guard', $condition );
				continue;
			}
			if ( $this->truthy( $this->eval_source( $condition, $scope ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether every identifier a condition reads is bound in this scope.
	 *
	 * @param array<string, mixed> $scope
	 */
	/**
	 * Report a child conditional that rendered nothing because its test could
	 * not be resolved.
	 *
	 * The distinction that matters is between a test that is genuinely false —
	 * `{open && <Panel/>}` on a closed panel, which is the page being right —
	 * and one whose identifiers nothing bound, where the empty arm is a section
	 * quietly falling off the page. condition_is_bound() separates them, so
	 * only the second is reported.
	 *
	 * @param array<string, mixed> $scope
	 */
	private function note_dropped_branch( string $source, mixed $value, array $scope ): void {
		if ( $value !== null && $value !== false && $value !== '' && $value !== array() ) {
			return;
		}
		$source = trim( $source );
		if ( $source === '' || ! preg_match( '/^([^?&|]{1,120}?)\s*(?:\?|&&)(?!&)/', $source, $hit ) ) {
			return;
		}
		$test = trim( $hit[1] );
		// A test with no identifier of its own — a literal, a bare call — is
		// not what this is about.
		if ( $test === '' || preg_match( '/[A-Za-z_$]/', $test ) !== 1 ) {
			return;
		}
		if ( ! $this->condition_is_bound( $test, $scope ) ) {
			$this->note_unevaluated( 'dropped-branch', $test );

			return;
		}

		/*
		 * Bound, but to nothing.
		 *
		 * `job.team` where `job` is null is the object failing to arrive, not
		 * the flag being false — Careers Page Builder's route computes
		 * `jobs[params.slug]` in a loader, so `job` reaches JobPage as a bound
		 * prop holding nothing, and two sections left the page in silence with
		 * condition_is_bound() answering yes.
		 *
		 * Only a member or index access counts. A plain `{open && …}` on a
		 * false state is the page being correct, and reporting that would bury
		 * the real losses in noise.
		 */
		if ( preg_match( '/^([A-Za-z_$][\w$]*)\s*(?:\.|\[)/', $test, $root ) !== 1 ) {
			return;
		}
		$name = (string) $root[1];
		if ( ! array_key_exists( $name, $scope ) && ! array_key_exists( $name, $this->globals ) ) {
			return;
		}
		$held = array_key_exists( $name, $scope ) ? $scope[ $name ] : $this->globals[ $name ];
		if ( $held !== null && $held !== false && $held !== '' && $held !== array() ) {
			return;
		}

		$this->note_unevaluated( 'dropped-branch', $test . ' (' . $name . ' is empty)' );
	}

	private function condition_is_bound( string $condition, array $scope ): bool {
		if ( ! preg_match_all( '/(?<![\w$.\'"])([A-Za-z_$][\w$]*)/', $condition, $names ) ) {
			return false;
		}

		foreach ( $names[1] as $name ) {
			if ( in_array( $name, array( 'true', 'false', 'null', 'undefined', 'length', 'typeof' ), true ) ) {
				continue;
			}
			if ( array_key_exists( $name, $scope ) || array_key_exists( $name, $this->globals ) ) {
				continue;
			}

			return false;
		}

		return true;
	}

	public function render_named( string $name, array $props = array() ): string {
		$code = $this->components[ $name ] ?? '';
		if ( $code === '' ) {
			return '';
		}

		return $this->render_component_source( $code, $props );
	}

	/**
	 * @param array<string, mixed> $props
	 */
	private function render_component_source( string $code, array $props ): string {
		if ( $this->depth > 24 ) {
			return '';
		}
		++$this->depth;

		$prev_setters = $this->setters;
		$prev_toggles = $this->toggles;
		$prev_init    = $this->state_init;
		/*
		 * Restored like the setters, and for a sharper reason: a component with
		 * no useState of its own leaves `driven` empty, and JSX children are
		 * evaluated before the component that wraps them. ARA renders
		 * `<Eyebrow>` and `<SectionHeading>` inside the same <FadeUpSection>
		 * as the FAQ list, so without this every projection after the first
		 * childless component on the page was silently dropped.
		 */
		$prev_driven  = $this->driven;

		$scope   = array_merge( $this->globals, $props );
		// `$props` as well as the merged scope: the rest element must collect the
		// component's OWN props and nothing else. Passing only the merged scope
		// swept the module globals into it, and `logoAsset` and `buttonVariants`
		// were spread onto the markup as `logoasset=""` and
		// `buttonvariants="__dxai_cva__"` attributes.
		$scope   = $this->apply_default_props( $code, $scope, $props );
		$code    = $this->select_branch( $code, $scope );
		$scope   = $this->bind_prelude( $code, $scope );
		/*
		 * `if (!body) { return null; }` — a component that bows out.
		 *
		 * shadcn's FormMessage is exactly this: with no validation error and
		 * no children it returns null, so a form at rest has NO message
		 * element. Ignoring the guard and taking the JSX below it published an
		 * empty `<p class="… text-destructive">` under every field — and since
		 * the field wrapper is `space-y-2`, each one added 8px the design does
		 * not have. React evaluates the guard; so does this now.
		 */
		$jsx     = $this->returns_early( $code, $scope ) ? '' : $this->extract_return_jsx( $code );
		$html    = $jsx === '' ? '' : $this->eval_jsx( $jsx, $scope );

		if ( $html !== '' && self::declares_reveal( $code ) ) {
			$html = $this->mark_reveal( $html );
		}

		$this->setters    = $prev_setters;
		$this->toggles    = $prev_toggles;
		$this->state_init = $prev_init;
		$this->driven     = $prev_driven;
		--$this->depth;
		return $html;
	}

	/**
	 * Evaluate the module's top-level consts into the global scope. Runs after
	 * load_imports() so anything they read is already bound.
	 */
	private function bind_consts( string $source ): void {
		$this->bind_arrow_consts( $source );
		foreach ( Tsx_Section_Splitter::const_blocks( $source ) as $name => $block ) {
			$eq = strpos( $block, '=' );
			if ( false === $eq ) {
				continue;
			}
			$rhs = $this->strip_as_cast( trim( substr( $block, $eq + 1 ) ) );
			$rhs = rtrim( $rhs, "; \t\n" );
			$this->globals[ $name ] = $this->eval_source( $rhs, $this->globals );
		}
	}

	/**
	 * Bind module-scope arrow-function consts — data helpers, not components.
	 *
	 * `const link = (label: string, href: string) => ({ label, href })` heads
	 * DevriX Elevate's SiteHeader, and every mega-panel entry is built by
	 * calling it: `children: [ link("RevOps auditing", "#services"), … ]`.
	 * Tsx_Section_Splitter deliberately skips arrow consts so that a component
	 * written as `const Card = ({ title }) => (<div/>)` is not mistaken for
	 * data, and the side effect was that `link` was never bound at all: every
	 * call returned null, so forty submenu anchors rendered with neither label
	 * nor href. Capitalised names stay excluded — those are components and
	 * extract_components() owns them.
	 *
	 * Runs before bind_consts() so the data arrays that call these resolve.
	 */
	private function bind_arrow_consts( string $source ): void {
		if ( ! preg_match_all( '/^const\s+([a-z_$][\w$]*)\s*(?::[^=\n]+)?=/m', $source, $rows, PREG_OFFSET_CAPTURE ) ) {
			return;
		}
		foreach ( $rows[0] as $idx => $row ) {
			$name = (string) $rows[1][ $idx ][0];
			if ( array_key_exists( $name, $this->globals ) ) {
				continue;
			}
			$rhs = ltrim( substr( $source, (int) $row[1] + strlen( (string) $row[0] ) ) );
			if ( ! $this->is_arrow_source( $rhs ) ) {
				continue;
			}
			/*
			 * Empty closure scope on purpose. resolve_ident() already falls
			 * back to $this->globals, so the callee sees later bindings too,
			 * and a snapshot of globals inside globals would copy every section
			 * data array once per helper.
			 */
			$this->globals[ $name ] = $this->make_fn( $this->arrow_extent( $rhs ), array() );
		}
	}

	/**
	 * Whether this right-hand side *is* an arrow function, not merely one that
	 * contains an arrow somewhere.
	 *
	 * The old test was `str_contains( $rhs, '=>' )`, which is also true of a
	 * call that takes an arrow callback. `const primary = nav.filter((i) =>
	 * i.children || i.label === "Insights")` was therefore handed to make_fn(),
	 * whose own pattern does not match it either, so `primary` became make_fn's
	 * fallback shape — an `__fn` record with no parameters. `primary.map()` then
	 * iterated that record's four keys, and every DevriX Elevate nav item
	 * rendered from a key name: no label, no href, and `open === item.label`
	 * true for all of them because both sides were the empty string.
	 */
	/**
	 * JavaScript's `===`, which has one number type.
	 *
	 * This evaluator has two: an index from `.map((c, i) => …)` or a literal
	 * arrives as a PHP int, while parse_add() casts every arithmetic result to
	 * float and the `Math:` builtins return `min()`/`max()` over
	 * `array_map( 'floatval', … )`. PHP's identity operator compares the type
	 * as well, so `0 === 0.0` is false and
	 * `const active = Math.min( n, Math.max( 0, Math.round( pos ) ) )` never
	 * equalled any row index: every `i === active ? A : B` in the corpus took
	 * the B arm, so no tab was active, no rail marker was set and no slide was
	 * shown — and because the arms both evaluate cleanly, nothing was reported.
	 *
	 * Numbers only. `is_int`/`is_float` are false for booleans and for
	 * numeric strings, which is what keeps `"1" === 1`, `true === 1` and
	 * `null === 0` false the way JavaScript does. NAN stays unequal to itself,
	 * because `(float) NAN === (float) NAN` is false in PHP too.
	 */
	private static function js_identical( mixed $left, mixed $right ): bool {
		if ( ( is_int( $left ) || is_float( $left ) ) && ( is_int( $right ) || is_float( $right ) ) ) {
			return (float) $left === (float) $right;
		}

		return $left === $right;
	}

	/**
	 * Whether an arrow's source starts a JSX element rather than a comparison.
	 *
	 * The test used to be "does it contain `<`", which is true of
	 * `enter <= 0`, `i < words.length` and `a > b` as well as of
	 * `=> (<div>`. OpportunityStack's `const state = (i) => ({ … hidden:
	 * enter <= 0 || 1 - exit <= 0 … })` was rejected as a component on that
	 * basis, so `state()` bound nothing and the whole stack lost its opacity,
	 * transform and aria-hidden.
	 *
	 * A JSX head has an element name directly after the `<`, and the `<` sits
	 * where a value begins: after `return`, `=>`, `(`, `,`, `{`, `[`,
	 * `=`, `:`, `?`, `&&`, `||` or a statement boundary. A comparison
	 * always has a value to its left instead.
	 */
	private static function heads_jsx( string $source ): bool {
		return 1 === preg_match( '/(?:^|\breturn\b|=>|[(,{\[=:?;]|&&|\|\|)\s*<\s*[A-Za-z_>]/', $source );
	}

	private function is_arrow_source( string $rhs ): bool {
		$rhs = ltrim( $rhs );
		if ( str_starts_with( $rhs, 'async' ) ) {
			$rhs = ltrim( substr( $rhs, 5 ) );
		}
		if ( $rhs === '' ) {
			return false;
		}
		if ( $rhs[0] === '(' ) {
			$close = $this->match_pair( $rhs, 0 );
			if ( $close < 0 ) {
				return false;
			}
			$rest = ltrim( substr( $rhs, $close + 1 ) );
			// A declared return type sits between params and arrow:
			// `(n: number): string => …`
			if ( str_starts_with( $rest, ':' ) ) {
				$arrow = strpos( $rest, '=>' );
				return false !== $arrow && ! str_contains( substr( $rest, 0, $arrow ), ';' );
			}
			return str_starts_with( $rest, '=>' );
		}

		return 1 === preg_match( '/^[A-Za-z_$][\w$]*\s*=>/', $rhs );
	}

	/**
	 * The arrow function at the head of `$rhs`, without whatever follows it.
	 *
	 * A helper's body is normally `({ … })` or `{ … }`, so the matching bracket
	 * ends it; anything else is a single-line expression body.
	 */
	private function arrow_extent( string $rhs ): string {
		$arrow = strpos( $rhs, '=>' );
		if ( false === $arrow ) {
			return $rhs;
		}
		$at = $arrow + 2;
		while ( isset( $rhs[ $at ] ) && ctype_space( $rhs[ $at ] ) ) {
			++$at;
		}
		$open = $rhs[ $at ] ?? '';
		if ( $open === '(' || $open === '{' || $open === '[' ) {
			$end = $this->match_pair( $rhs, $at );
			if ( $end > 0 ) {
				return substr( $rhs, 0, $end + 1 );
			}
		}

		return rtrim( substr( $rhs, 0, strcspn( $rhs, "\n" ) ), "; \t" );
	}

	private function load_file( string $source ): void {
		$this->globals    = array();
		$this->components = array();

		foreach ( Tsx_Section_Splitter::extract_components_public( $source ) as $fn ) {
			$this->components[ (string) $fn['name'] ] = (string) $fn['code'];
		}
		foreach ( self::builtin_named_components( $source ) as $name => $code ) {
			$this->components[ $name ] = $code;
		}

		if ( ! isset( $this->globals['logoAsset'] ) || ! is_array( $this->globals['logoAsset'] ) ) {
			$this->globals['logoAsset'] = array();
		}
		$logo = $this->pick_logo_url();
		if ( $logo !== '' ) {
			$this->globals['logoAsset']['url'] = $logo;
		}
	}

	/**
	 * The design's own `Section` and `CountUp`, which the splitter holds back.
	 *
	 * Tsx_Section_Splitter skips both definitions because this compiler has a
	 * builtin for each, so a ZIP's own Section never reached $this->components
	 * and emit_element() could not tell a design that has one from a design
	 * that does not. Whether the builtin may stand in is decided there now —
	 * see builtin_section_fits() — and that needs the source.
	 *
	 * The splitter is asked again with the two declarations renamed, so the
	 * code comes out of the same extraction every other component goes
	 * through: type erasure, concise arrows and all.
	 *
	 * @return array<string, string> Component name => its source.
	 */
	private static function builtin_named_components( string $source ): array {
		$declared = '/^((?:export\s+(?:default\s+)?)?(?:function|const)\s+)(Section|CountUp)\b/m';
		if ( 1 !== preg_match( $declared, $source ) ) {
			return array();
		}

		$out = array();
		foreach ( Tsx_Section_Splitter::extract_components_public( (string) preg_replace( $declared, '$1DxaiOwn$2', $source ) ) as $fn ) {
			$name = (string) $fn['name'];
			if ( ! str_starts_with( $name, 'DxaiOwn' ) ) {
				continue;
			}
			$name         = substr( $name, strlen( 'DxaiOwn' ) );
			$out[ $name ] = (string) preg_replace( '/\bDxaiOwn' . $name . '\b/', $name, (string) $fn['code'], 1 );
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $harvest
	 * @return array<string, string>
	 */
	private function image_map( array $harvest ): array {
		$map = array();
		foreach ( array_merge( $harvest['images'] ?? array(), $harvest['assets'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$url = (string) ( $item['url'] ?? '' );
			if ( $url === '' ) {
				continue;
			}
			$file = strtolower( (string) ( $item['filename'] ?? basename( (string) ( $item['original'] ?? '' ) ) ) );
			if ( $file !== '' ) {
				$map[ $file ] = $url;
			}
			$orig = (string) ( $item['original'] ?? '' );
			if ( $orig !== '' ) {
				$map[ $orig ] = $url;
			}
		}
		foreach ( $harvest['rewrites'] ?? array() as $from => $to ) {
			if ( is_string( $from ) && is_string( $to ) && $to !== '' ) {
				$map[ $from ] = $to;
			}
		}

		return $map;
	}

	/**
	 * A logo URL for designs that reference one without importing a file.
	 *
	 * Conventional filenames only. `devrix-logo.png` used to head this list —
	 * one client's filename hardcoded into a compiler that has to serve any
	 * design, and it made a fallback confidently pick the wrong file out of the
	 * three logos that ZIP ships.
	 */
	private function pick_logo_url(): string {
		foreach ( array( 'logo.png', 'logo.svg', 'logo.webp' ) as $name ) {
			if ( ! empty( $this->images[ $name ] ) ) {
				return (string) $this->images[ $name ];
			}
		}
		foreach ( $this->images as $file => $url ) {
			$hay = strtolower( (string) $file . ' ' . $url );
			if ( str_contains( $hay, 'favicon' ) || str_contains( $hay, '.ico' ) ) {
				continue;
			}
			if ( str_contains( $hay, 'logo' ) ) {
				return (string) $url;
			}
		}

		return '';
	}

	/**
	 * Scroll-reveal flags start false and are flipped by an IntersectionObserver
	 * that never runs during compilation, which would freeze every section at
	 * `opacity-0`. Compile the settled, visible state instead. Interaction flags
	 * such as `open` keep their authored default.
	 */
	/**
	 * Bind what a hook's destructured members must be, when the hook itself
	 * cannot be run.
	 *
	 * `const { ref, inView } = useInView(0.1)` is how this corpus writes an
	 * IntersectionObserver reveal. The call evaluates to nothing, so `inView`
	 * stayed unbound and every `cn("line-mask", shown && "is-in")` lost its
	 * `is-in`: DevriX Elevate's hero headline sat 111px below a 50px
	 * `overflow: hidden` mask — "Engineering / revenue / growth" hidden behind
	 * its own mask — and the orbit rings never got their draw class. Four
	 * `useInView()` calls on that one page.
	 *
	 * The useState pass already answers this for a flag it can see:
	 * is_reveal_flag() renders the settled, visible state, because the observer
	 * never runs at compile time. This is the same answer for a flag that
	 * arrives through a hook.
	 *
	 * Only names that rule recognises are bound, plus a ref, which exists to be
	 * handed to `ref={}` and is dropped from markup anyway. Anything else —
	 * `pos`, `progress` — is deliberately left unbound so it keeps reporting
	 * as unresolved rather than being given a value this compiler invented.
	 *
	 * @param array<string, mixed> $scope
	 */
	private function bind_hook_members( string $prelude, array &$scope ): void {
		// `useInView<HTMLSpanElement>(0.1)` — the generic is part of the shape.
		if ( ! preg_match_all( '/const\s*\{([^}]*)\}\s*=\s*use[A-Z][\w]*\s*(?:<[^>()]*>)?\s*\(/', $prelude, $rows, PREG_SET_ORDER ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			foreach ( self::destructured_names( '{' . $row[1] . '}' ) as $key => $alias ) {
				if ( array_key_exists( $alias, $scope ) ) {
					continue;
				}
				if ( self::is_reveal_flag( $key ) ) {
					$scope[ $alias ] = true;
					continue;
				}
				if ( preg_match( '/^(?:.*_)?ref$/i', $key ) === 1 ) {
					$scope[ $alias ] = null;
				}
			}
		}
	}

	private static function is_reveal_flag( string $name ): bool {
		return 1 === preg_match( '/^(?:is|has)?_?(?:shown|show|visible|revealed|reveal|inview|invisible|mounted|loaded|ready|animate|animated|entered|active_?reveal)$/i', $name );
	}

	/**
	 * @param array<string, mixed> $scope
	 * @return array<string, mixed>
	 */
	private function bind_prelude( string $code, array $scope ): array {
		$scope['visible'] = true;
		$scope['ref']     = null;
		$this->setters    = array();
		$this->toggles    = array();
		$this->driven     = array();

		$prelude = $code;
		if ( preg_match( '/\breturn\s*\(\s*</', $code, $hit, PREG_OFFSET_CAPTURE ) ) {
			$prelude = substr( $code, 0, (int) $hit[0][1] );
		} elseif ( preg_match( '/\breturn\s*</', $code, $hit, PREG_OFFSET_CAPTURE ) ) {
			$prelude = substr( $code, 0, (int) $hit[0][1] );
		}

		if ( preg_match_all( '/const\s*\[\s*([A-Za-z_][\w]*)\s*,\s*([A-Za-z_][\w]*)\s*\]\s*=\s*useState(?:<[^>]*>)?\(\s*([^)]*)\)/', $prelude, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$init = trim( $row[3] );
				$scope[ $row[1] ] = $init === '' ? 0 : $this->eval_source( $init, $scope );
				$this->setters[ $row[2] ] = $row[1];
				if ( self::is_reveal_flag( $row[1] ) ) {
					$scope[ $row[1] ] = true;
				} else {
					$this->toggles[ $row[1] ] = true;
					// A relative move — `go(i - 1)` on the case-study rail —
					// needs the index it starts from, which nothing in the
					// markup states once every arm has been resolved.
					$this->state_init[ $row[1] ] = $scope[ $row[1] ];
				}
			}
			$this->driven = $this->driven_states( $code );

			/*
			 * State the design takes from the scroll position, and the derived
			 * booleans built on it.
			 *
			 * `driven_states()` above reads only `on*={…}` handler bodies, which
			 * is right for a click: a projection nothing can transition is not a
			 * control, and marking one used to cost real fidelity. A scroll
			 * listener is a transition all the same — the sticky header that
			 * turns solid past a threshold, which ten of the nineteen designs in
			 * this corpus write as
			 *
			 *     const onScroll = () => setSolid(window.scrollY > 24);
			 *     const dark = solid || open !== null || mobile;
			 *
			 * and which came out permanently transparent because nothing here
			 * read either line. Motion_Runtime has watched
			 * `data-dxai-scroll="state:threshold"` since it was written and
			 * Dc_Renderer already emits it for Claude Design exports; this is
			 * the React path catching up.
			 *
			 * A scroll threshold beats the reveal-flag guess.
			 *
			 * is_reveal_flag() reads a name — `show`, `visible`, `shown` — and
			 * assumes an IntersectionObserver that never runs during
			 * compilation, so it renders the settled visible state and
			 * registers no toggle. That is right for a section that fades in on
			 * approach and wrong for a sticky CTA:
			 * `setShow(window.scrollY > 700)` on GTM Strategy Hub and
			 * `setVisible(…)` on Brand Polish Pass are scroll states with a
			 * threshold, and treating them as reveals pinned both permanently
			 * ON — a floating bar across the top of a page the design only
			 * shows further down.
			 *
			 * A listener with a threshold is evidence, and evidence beats the
			 * name: the state goes back to false, which is what the page is
			 * compiled at, and becomes a toggle the runtime can flip. Every
			 * name here came from `$this->setters`, so each one is a real
			 * useState of this component.
			 */
			$this->scroll_states = $this->scroll_driven( $prelude, $code );
			foreach ( array_keys( $this->scroll_states ) as $scrolled ) {
				$scope[ $scrolled ]            = false;
				$this->toggles[ $scrolled ]    = true;
				$this->driven[ $scrolled ]     = true;
				$this->state_init[ $scrolled ] = false;
			}

			/*
			 * A boolean assembled from other states. The class gate is keyed on
			 * the derived name, so without this the gate sees an ordinary const
			 * and inlines whichever arm the compile-time value picked.
			 */
			$this->derived = $this->derived_booleans( $prelude );
			foreach ( $this->derived as $derived_name => $members ) {
				$this->toggles[ $derived_name ]    = true;
				$this->driven[ $derived_name ]     = true;
				$this->state_init[ $derived_name ] = false;
			}
		}

		/*
		 * Members of a hook this compiler cannot run, bound before anything
		 * reads them — see bind_hook_members().
		 */
		$this->bind_hook_members( $prelude, $scope );

		/*
		 * Block-bodied arrow helpers first, because the data consts below may
		 * call them. Neither other pass sees these: scoped_const_blocks()
		 * leaves arrows to the component splitter, and the single-line pass
		 * needs the whole declaration on one line. RevOps' radar chart is
		 * drawn by `const pt = (k, r) => { const a = …; return [55 +
		 * Math.cos(a) * r, …] as const; };` and then `const shape =
		 * vals.map((v, k) => pt(k, …))` — every `pt(k, r)` was an unbound
		 * call, 12 per page: three empty `points` attributes and six circles
		 * at the origin.
		 */
		$bound  = array();
		$offset = 0;
		while ( preg_match( '/const\s+([A-Za-z_][\w]*)\s*(?::[^=;\n]+)?=\s*((?:\([^()]*\)|[A-Za-z_][\w]*)\s*(?::\s*[^=>;{\n]+)?=>\s*\{)/', $prelude, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
			$name   = (string) $m[1][0];
			$open   = (int) $m[2][1] + strlen( (string) $m[2][0] ) - 1;
			$close  = $this->match_pair( $prelude, $open );
			$offset = $open + 1;
			if ( $close < 0 ) {
				continue;
			}
			$rhs = substr( $prelude, (int) $m[2][1], $close - (int) $m[2][1] + 1 );
			if ( self::heads_jsx( $rhs ) ) {
				continue; // A JSX-returning arrow is a component, not a helper.
			}
			$scope[ $name ] = $this->make_fn( $rhs, $scope );
			$bound[ $name ] = true;
		}

		/*
		 * And a component-local arrow whose body is a parenthesised expression,
		 * which had no pass at all.
		 *
		 *     const lines = (kind) => (
		 *       <g>…</g>
		 *     );
		 *
		 * ImagePanel writes its stack layers that way, so `{lines("base")}` and
		 * `{lines("over")}` emitted nothing and six nodes were missing from
		 * DevriX Elevate's page. Module scope already binds exactly this shape
		 * (bind_arrow_consts() with arrow_extent()), JSX body and all —
		 * make_fn() returns the body as `{'__html' => …}` and markup_html()
		 * places it — so this is that same pass for the component's own
		 * prelude. No JSX veto: a local arrow that returns JSX is a render
		 * helper the component calls itself, not a component someone else
		 * mounts, and refusing it is what lost the layers.
		 */
		$offset = 0;
		while ( preg_match( '/const\s+([A-Za-z_][\w]*)\s*(?::[^=;\n]+)?=\s*((?:\([^()]*\)|[A-Za-z_][\w]*)\s*(?::\s*[^=>;{\n]+)?=>\s*\()/', $prelude, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
			$name   = (string) $m[1][0];
			$start  = (int) $m[2][1];
			$offset = $start + 1;
			if ( isset( $bound[ $name ] ) || array_key_exists( $name, $scope ) ) {
				continue;
			}
			$extent = $this->arrow_extent( substr( $prelude, $start ) );
			if ( trim( $extent ) === '' ) {
				continue;
			}
			$scope[ $name ] = $this->make_fn( $extent, $scope );
			$bound[ $name ] = true;
		}

		// Section data is normally a multi-line array local to the component, so
		// bind those with balanced brackets before the single-line pass.
		foreach ( Tsx_Section_Splitter::scoped_const_blocks( $prelude ) as $name => $block ) {
			if ( isset( $bound[ $name ] ) ) {
				continue;
			}
			$eq = strpos( $block, '=' );
			if ( false === $eq ) {
				continue;
			}
			$rhs = rtrim( $this->strip_as_cast( trim( substr( $block, $eq + 1 ) ) ), "; \t\n" );
			if ( $rhs === '' || str_starts_with( $rhs, 'use' ) ) {
				continue;
			}
			/*
			 * A multi-line arrow is a function, as the single-line pass below
			 * already knows: RevOps' radar chart has `const pt = (k, r) => {
			 * const a = …; return [55 + Math.cos(a) * r, …] as const; };` and
			 * evaluating that as an expression bound nothing, so every
			 * `pt(k, r)` in its polygons was an unbound call (12 per page).
			 */
			if ( $this->is_arrow_source( $rhs ) && ! self::heads_jsx( $rhs ) ) {
				$scope[ $name ] = $this->make_fn( $rhs, $scope );
				$bound[ $name ] = true;
				continue;
			}
			$scope[ $name ] = $this->eval_source( $rhs, $scope );
			$bound[ $name ] = true;
		}

		if ( preg_match_all( '/const\s+([A-Za-z_][\w]*)\s*(?::[^=;\n]+)?=\s*(?!use[A-Z])([^;\n]+);/', $prelude, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$name = $row[1];
				$rhs  = trim( $row[2] );
				/*
				 * Only a right-hand side that *is* an arrow becomes a function.
				 * `const primary = nav.filter((i) => i.children)` merely
				 * contains one — see is_arrow_source() for what that cost. The
				 * `<` guard is unchanged: a JSX-returning arrow is a component,
				 * not a data helper.
				 */
				if ( $this->is_arrow_source( $rhs ) && ! self::heads_jsx( $rhs ) ) {
					$scope[ $name ] = $this->make_fn( $rhs, $scope );
					continue;
				}
				if ( str_starts_with( $rhs, 'use' ) || isset( $bound[ $name ] ) ) {
					continue;
				}
				$scope[ $name ] = $this->eval_source( $rhs, $scope );
			}
		}

		/*
		 * `const { id } = itemContext;` — destructuring from a value already
		 * bound above, which is why this runs last. shadcn's useFormField
		 * reads its id that way, and without it the whole id chain ended at
		 * the context.
		 */
		if ( preg_match_all( '/const\s*\{([^}]*)\}\s*=\s*([^;\n]+);/', $prelude, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$source = trim( (string) $row[2] );
				if ( $source === '' ) {
					continue;
				}
				/*
				 * Hooks are NOT excluded by name here. `useFormField()` is a
				 * plain function the design defines and this compiler can run,
				 * and skipping it because it starts with `use` is what left
				 * every label without its `for`. A hook we genuinely cannot
				 * evaluate returns null and is skipped by the type test below,
				 * which is the honest test.
				 */
				$value = $this->eval_source( $source, $scope );
				if ( ! is_array( $value ) ) {
					// A hook this compiler cannot run is handled by
					// bind_hook_members(), which ran before the consts that
					// read its members.
					continue;
				}
				foreach ( self::destructured_names( '{' . $row[1] . '}' ) as $key => $alias ) {
					if ( ! array_key_exists( $alias, $scope ) ) {
						$scope[ $alias ] = $value[ $key ] ?? null;
					}
				}
			}
		}

		/*
		 * `const [head, ...rest] = t.split(" ");` — an array pattern over a
		 * value this compiler can produce. ICP Segmentation writes each trust
		 * cell that way inside a map callback, and with the pattern unread the
		 * callback's own source leaked onto the page as text:
		 * `= t.split(" "); return ( ); })}`. The useState form is bound at the
		 * top of this method and excluded here with the other hooks.
		 */
		// `\s*+` is possessive: a backtracking `\s*` let the lookahead test a
		// position inside the whitespace and every `useState(` slipped past it.
		if ( preg_match_all( '/const\s*\[([^\]]*)\]\s*(?::[^=;\n]+)?=\s*+(?!(?:React\.)?use[A-Z])([^;\n]+);/', $prelude, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$value = $this->eval_source( trim( (string) $row[2] ), $scope );
				// A list, not a hook marker or an object.
				if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
					continue;
				}
				foreach ( explode( ',', (string) $row[1] ) as $k => $name ) {
					// `[a = 1, b]` — the default is not part of the name.
					$name = trim( (string) preg_replace( '/=.*$/s', '', $name ) );
					if ( $name === '' ) {
						continue;
					}
					if ( str_starts_with( $name, '...' ) ) {
						$scope[ substr( $name, 3 ) ] = array_slice( $value, $k );
						break;
					}
					$scope[ $name ] = $value[ $k ] ?? null;
				}
			}
		}

		return $scope;
	}

	/**
	 * bind_prelude() for statements that are not a component's own body.
	 *
	 * bind_prelude() starts the state registers from empty, because it is
	 * where a component's render declares its state. A block-bodied helper
	 * called during that render goes through it too, and so does the body of
	 * a provider component read ahead of its children — and each call wiped
	 * the setters, toggles and projections of the component being rendered.
	 * A FAQ whose rows call an `initials(name)` helper ahead of the button
	 * lost the toggle on every row: no trigger class, and each
	 * `{open === i && …}` compiled as false, so the answers left the page.
	 * A provider such as FormItem ahead of a toggle did the same.
	 *
	 * The locals are bound the same way; the registers are put back as they
	 * were, before the helper's own return is evaluated, since that return is
	 * still part of the enclosing render.
	 *
	 * @param array<string, mixed> $scope
	 * @return array<string, mixed>
	 */
	private function bind_locals( string $code, array $scope ): array {
		$setters = $this->setters;
		$toggles = $this->toggles;
		$driven  = $this->driven;
		$init    = $this->state_init;
		$scroll  = $this->scroll_states;
		$derived = $this->derived;

		$scope = $this->bind_prelude( $code, $scope );

		$this->setters       = $setters;
		$this->toggles       = $toggles;
		$this->driven        = $driven;
		$this->state_init    = $init;
		$this->scroll_states = $scroll;
		$this->derived       = $derived;

		return $scope;
	}

	/**
	 * Resolve `if (cond) { …; return (<jsx>); }` branches above a component's JSX.
	 *
	 * RevOps' DxDeliveryChart is one function with three guarded returns —
	 * `if (i === 1) { const rows = …; return (<svg>…); }` and so on — and a
	 * default chart below them. Reading only the final return drew the default
	 * chart on every card (15 nodes wrong per width) and, because the `const d`
	 * for that chart sits below the guards, bound nothing for it either:
	 * `<path d=" L218 86 L2 86 Z">` threw "Expected moveto path command" on
	 * every load. React takes the first branch whose condition holds; so does
	 * this, splicing the branch's own statements in ahead of its return and
	 * dropping a branch whose condition fails, `else` arms included.
	 *
	 * A condition this compiler cannot evaluate stops the walk and leaves the
	 * code as it was — the default branch — and is noted as unevaluated.
	 * `return null` guards are not branches; returns_early() owns those.
	 *
	 * @param array<string, mixed> $scope
	 */
	private function select_branch( string $code, array $scope ): string {
		/*
		 * The code is the whole declaration — `function DelThumb({ i }: { i:
		 * number }) {` and its closing brace — so a statement at the body's
		 * own level sits one brace deep, not zero. Counting from zero skipped
		 * every guard in RevOps' chart and all four cards drew the default.
		 */
		$base = preg_match( '/^\s*(?:export\s+(?:default\s+)?)?(?:async\s+)?function\b|^\s*(?:export\s+)?(?:const|let|var)\s+\w+\s*(?::[^=]+)?=/', $code ) === 1 ? 1 : 0;
		$offset = 0;
		for ( $step = 0; $step < 40; $step++ ) {
			if ( ! preg_match( '/\bif\s*\(/', $code, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
				return $code;
			}
			$at   = (int) $m[0][1];
			$head = substr( $code, 0, $at );
			// Nothing below the first JSX return is reachable.
			if ( preg_match( '/\breturn\s*\(?\s*</', $head ) ) {
				return $code;
			}
			// Only a statement at the body's own level, not one inside a hook.
			if ( substr_count( $head, '{' ) - substr_count( $head, '}' ) !== $base ) {
				$offset = $at + 2;
				continue;
			}
			$open  = $at + strlen( (string) $m[0][0] ) - 1;
			$close = $this->match_pair( $code, $open );
			if ( $close < 0 ) {
				return $code;
			}
			$condition = trim( substr( $code, $open + 1, $close - $open - 1 ) );
			$brace     = $close + 1;
			while ( $brace < strlen( $code ) && ctype_space( $code[ $brace ] ) ) {
				$brace++;
			}
			if ( ( $code[ $brace ] ?? '' ) !== '{' ) {
				$offset = $close + 1;
				continue;
			}
			$end = $this->match_pair( $code, $brace );
			if ( $end < 0 ) {
				return $code;
			}
			$body = substr( $code, $brace + 1, $end - $brace - 1 );
			if ( ! preg_match( '/\breturn\s*\(?\s*</', $body ) ) {
				$offset = $end + 1;
				continue;
			}

			$local = $this->bind_prelude( $head, $scope );
			if ( ! $this->condition_is_bound( $condition, $local ) ) {
				$this->note_unevaluated( 'branch-guard', $condition );
				return $code;
			}
			if ( $this->truthy( $this->eval_source( $condition, $local ) ) ) {
				// The branch's statements, then its return; nested guards are
				// now at this level and the next pass reads them. The
				// declaration's own closing brace is kept balanced.
				$code   = $head . "\n" . $body . ( $base ? "\n}" : '' );
				$offset = $at;
				continue;
			}

			// Failed: drop the block. An `else {…}` arm takes its place; an
			// `else if` leaves its `if` standing for the next pass.
			$tail = $end + 1;
			if ( preg_match( '/\G\s*else\s*/', $code, $e, 0, $tail ) ) {
				$after = $tail + strlen( (string) $e[0] );
				if ( ( $code[ $after ] ?? '' ) === '{' ) {
					$else_end = $this->match_pair( $code, $after );
					if ( $else_end < 0 ) {
						return $code;
					}
					$code   = $head . "\n" . substr( $code, $after + 1, $else_end - $after - 1 ) . substr( $code, $else_end + 1 );
					$offset = $at;
					continue;
				}
				$tail = $after;
			}
			$code   = $head . substr( $code, $tail );
			$offset = $at;
		}

		return $code;
	}

	/**
	 * States a scroll listener writes, as `state => threshold in px`.
	 *
	 * The shape is always the same in this corpus: a setter called with a
	 * comparison against the scroll offset, whether the listener is a named
	 * const or written inline.
	 *
	 *     const onScroll = () => setSolid(window.scrollY > 24);
	 *     window.addEventListener("scroll", () => setShrunk(scrollY >= 80));
	 *
	 * A setter whose name is not a known `useState` setter is ignored: this
	 * reads the register `bind_prelude()` has just filled, so a helper that
	 * happens to look like one cannot invent a state.
	 *
	 * @return array<string, float>
	 */
	private function scroll_driven( string $code, string $whole = '' ): array {
		if ( ! str_contains( $code, 'scrollY' ) && ! str_contains( $code, 'pageYOffset' ) ) {
			return array();
		}

		/*
		 * Whether the component reads a state at all.
		 *
		 * Brand Polish Pass writes `const dark = true; void solid;` — the
		 * listener still runs and nothing looks at what it sets, so the header
		 * is pinned solid on purpose. Declaring a scroll state for it would
		 * make the page move where the design does not.
		 */
		/*
		 * Read across the WHOLE component, not just the prelude: a sticky CTA
		 * reads its state in the JSX and nowhere else, and asking the prelude
		 * alone dropped the state on five designs.
		 */
		$searchable = $whole !== '' ? $whole : $code;
		$reads      = static function ( string $state ) use ( $searchable ): bool {
			$without = (string) preg_replace(
				array(
					'/\bconst\s*\[\s*' . preg_quote( $state, '/' ) . '\s*,[^\]]*\]\s*=\s*useState[^;\n]*/',
					'/\bvoid\s+' . preg_quote( $state, '/' ) . '\s*;/',
				),
				'',
				$searchable
			);

			return preg_match( '/\b' . preg_quote( $state, '/' ) . '\b/', self::without_strings( $without ) ) === 1;
		};

		$out = array();
		if ( preg_match_all(
			'/\b([A-Za-z_][\w]*)\s*\(\s*(?:!\s*)?(?:window\s*\.\s*)?(?:scrollY|pageYOffset)\s*(?:>=?|<=?)\s*([0-9]+(?:\.[0-9]+)?)/',
			$code,
			$hits,
			PREG_SET_ORDER
		) ) {
			foreach ( $hits as $hit ) {
				$state = $this->setters[ (string) $hit[1] ] ?? '';
				if ( $state === '' || ! $reads( $state ) ) {
					continue;
				}
				$out[ $state ] = (float) $hit[2];
			}
		}

		/*
		 * The same read one hop away: a local holds the comparison and the
		 * setter is called with it, alone or beside other terms.
		 *
		 *     const pastHero = window.scrollY > 420;
		 *     setVisible(pastHero && !formVisible);
		 *
		 * Every term that is not the scroll one is reported: the runtime has no
		 * notion of "the contact form is on screen", and a page that shows the
		 * floating bar past 420px without ever hiding it again is the design
		 * minus one rule. Said out loud, it is a known gap; silent, it is the
		 * class of loss this whole mechanism was written to end.
		 */
		$locals = array();
		if ( preg_match_all(
			'/\bconst\s+([A-Za-z_][\w]*)\s*=\s*(?:!\s*)?(?:window\s*\.\s*)?(?:scrollY|pageYOffset)\s*(?:>=?|<=?)\s*([0-9]+(?:\.[0-9]+)?)/',
			$code,
			$found,
			PREG_SET_ORDER
		) ) {
			foreach ( $found as $hit ) {
				$locals[ (string) $hit[1] ] = (float) $hit[2];
			}
		}
		if ( $locals === array() ) {
			return $out;
		}

		if ( preg_match_all( '/\b([A-Za-z_][\w]*)\s*\(([^;]{0,200}?)\)\s*;/', $code, $calls, PREG_SET_ORDER ) ) {
			foreach ( $calls as $call ) {
				$state = $this->setters[ (string) $call[1] ] ?? '';
				if ( $state === '' || isset( $out[ $state ] ) || ! $reads( $state ) ) {
					continue;
				}
				$argument = (string) $call[2];
				$terms    = preg_split( '/&&|\|\|/', $argument ) ?: array();
				$threshold = null;
				$others    = array();
				foreach ( $terms as $term ) {
					$term = trim( $term );
					$bare = ltrim( $term, '!' );
					if ( isset( $locals[ $bare ] ) ) {
						$threshold = $threshold ?? $locals[ $bare ];
						continue;
					}
					if ( $term !== '' ) {
						$others[] = $term;
					}
				}
				if ( $threshold === null ) {
					continue;
				}
				$out[ $state ] = $threshold;
				foreach ( $others as $term ) {
					$this->note_unevaluated( 'scroll-state-term', $state . ' <- ' . substr( $term, 0, 60 ) );
				}
			}
		}

		return $out;
	}

	/**
	 * Booleans a component derives from its own states, as `name => members`.
	 *
	 * `const dark = solid || open !== null || mobile;` is one state as far as
	 * the class gate is concerned, and it is on when any member is. Only an OR
	 * chain is read — an AND would need the runtime to hold every member true
	 * at once, which nothing in the corpus asks for — and only members that are
	 * already states count. A term that is neither, `mobile` above, is
	 * reported: it is a real part of the condition that the page will not have,
	 * and a silent drop there is how this whole class of loss started.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function derived_booleans( string $code ): array {
		$out = array();
		if ( ! preg_match_all( '/\bconst\s+([A-Za-z_][\w]*)\s*(?::\s*boolean\s*)?=\s*([^;\n]*\|\|[^;\n]*);/', $code, $hits, PREG_SET_ORDER ) ) {
			return $out;
		}

		foreach ( $hits as $hit ) {
			$name = (string) $hit[1];
			if ( isset( $this->toggles[ $name ] ) || isset( $this->setters[ $name ] ) ) {
				continue;
			}
			$members = array();
			$unknown = array();
			foreach ( explode( '||', (string) $hit[2] ) as $term ) {
				$term = trim( $term );
				if ( $term === '' ) {
					continue;
				}
				// The term's own identifier: `open !== null` is the `open`
				// state, `!collapsed` is `collapsed`, `a.b` is neither.
				if ( preg_match( '/^!?\s*([A-Za-z_][\w]*)\s*(?:[!=]==?\s*null|[!=]==?\s*undefined)?$/', $term, $ident ) !== 1 ) {
					$unknown[] = $term;
					continue;
				}
				$ident_name = (string) $ident[1];
				if ( isset( $this->toggles[ $ident_name ] ) ) {
					if ( ! in_array( $ident_name, $members, true ) ) {
						$members[] = $ident_name;
					}
					continue;
				}
				$unknown[] = $term;
			}
			if ( $members === array() ) {
				continue;
			}
			$out[ $name ] = $members;
			foreach ( $unknown as $term ) {
				$this->note_unevaluated( 'derived-state-term', $name . ' <- ' . $term );
			}
		}

		return $out;
	}

	/**
	 * Which of this component's states a handler prop can actually change.
	 *
	 * Reads every `on*={...}` body, then follows one hop into any function the
	 * body calls — `onClick={() => go(idx)}` over
	 * `const go = (next) => setI(...)` — because that is how Project Page
	 * Duplicator's carousel is written. One hop, matching the one
	 * toggle_from_handler() resolves, so the two agree on what is drivable.
	 *
	 * @return array<string, true>
	 */
	private function driven_states( string $code ): array {
		$hay    = '';
		$offset = 0;
		while (
			$offset < strlen( $code )
			&& preg_match( '/\bon[A-Z][A-Za-z]*\s*=\s*\{/', $code, $m, PREG_OFFSET_CAPTURE, $offset )
		) {
			$brace  = (int) $m[0][1] + strlen( (string) $m[0][0] ) - 1;
			$close  = $this->match_pair( $code, $brace );
			$offset = $close < 0 ? $brace + 1 : $close + 1;
			if ( $close > $brace ) {
				$hay .= "\n" . substr( $code, $brace, $close - $brace + 1 );
			}
		}
		if ( $hay === '' ) {
			return array();
		}

		// One hop: pull in the declaration of anything those bodies call.
		$seen = array();
		if ( preg_match_all( '/\b([A-Za-z_][\w]*)\s*\(/', $hay, $calls ) ) {
			foreach ( $calls[1] as $callee ) {
				if ( isset( $seen[ $callee ] ) || isset( $this->setters[ $callee ] ) ) {
					continue;
				}
				$seen[ $callee ] = true;
				if ( preg_match( '/\bconst\s+' . preg_quote( $callee, '/' ) . '\b[^;\n]*[\s\S]{0,200}?;/', $code, $decl ) ) {
					$hay .= "\n" . $decl[0];
				}
			}
		}

		$driven = array();
		foreach ( $this->setters as $setter => $state ) {
			if ( $setter !== '' && str_contains( $hay, $setter ) ) {
				$driven[ $state ] = true;
			}
		}

		return $driven;
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	/**
	 * The bindings a destructured parameter introduces, as key => local name.
	 *
	 * `{ field }` is `field => field`; `{ field: f }` is `field => f`. A
	 * default (`{ field = x }`) keeps the key and drops the default, which is
	 * the best this evaluator can do without running the default expression.
	 *
	 * @return array<string, string>
	 */
	private static function destructured_names( string $param ): array {
		$inner = trim( $param, "{} \t\n" );
		$out   = array();
		foreach ( explode( ',', $inner ) as $piece ) {
			$piece = trim( $piece );
			if ( $piece === '' || str_starts_with( $piece, '...' ) ) {
				continue;
			}
			// Strip a default before reading an alias, so `a = 1` is not read
			// as an alias of `a`.
			$piece = trim( (string) preg_replace( '/=.*$/s', '', $piece ) );
			if ( str_contains( $piece, ':' ) ) {
				[ $key, $alias ] = explode( ':', $piece, 2 );
				$key             = trim( $key );
				$alias           = trim( $alias );
				if ( $key !== '' && preg_match( '/^[A-Za-z_$][\w$]*$/', $alias ) === 1 ) {
					$out[ $key ] = $alias;
				}
				continue;
			}
			if ( preg_match( '/^[A-Za-z_$][\w$]*$/', $piece ) === 1 ) {
				$out[ $piece ] = $piece;
			}
		}

		return $out;
	}

	private function make_fn( string $arrow, array $scope ): array {
		$arrow = trim( $arrow );
		$params = array();
		$body   = $arrow;
		if ( preg_match( '/^(?:\(([^)]*)\)|([A-Za-z_][\w]*))\s*=>\s*([\s\S]+)$/', $arrow, $m ) ) {
			$list = trim( $m[1] !== '' ? $m[1] : $m[2] );
			/*
			 * A single DESTRUCTURED parameter, kept whole: `({ field }) => …`,
			 * which is how every render prop is written. Splitting it on commas
			 * produced parameters literally named `{ field` and `fieldState }`,
			 * so the callee bound nothing and the render prop could not be
			 * called at all. call_value() unpacks this shape against its
			 * argument — see destructured_names().
			 */
			if ( str_starts_with( $list, '{' ) && str_ends_with( $list, '}' ) ) {
				$params[] = $list;
			} else {
				foreach ( explode( ',', $list ) as $param ) {
					$param = trim( (string) preg_replace( '/:.*$/', '', $param ) );
					if ( $param !== '' ) {
						$params[] = $param;
					}
				}
			}
			$body = $m[3];
		}

		return array(
			'__fn'   => true,
			'params' => $params,
			'body'   => rtrim( $body, "; \t" ),
			'scope'  => $scope,
		);
	}

	/**
	 * The JSX of a component whose body is a concise arrow expression.
	 *
	 * Handles the wrapper forms the shadcn kit uses, peeling them off before
	 * looking for the arrow:
	 *
	 *   const X = ({ a }) => ( <div/> )
	 *   const X = React.forwardRef(({ a }, ref) => ( <div/> ))
	 *   const X = React.forwardRef<HTMLDivElement, Props>((props, ref) => ( <div/> ))
	 *   const X = memo(forwardRef((props, ref) => ( <div/> )))
	 *
	 * The arrow is found by walking with bracket depth rather than by taking
	 * the first `=>` in the text, because a parameter default is itself an
	 * arrow — `({ onClick = () => {} })` — and that one must not be mistaken
	 * for the component's body.
	 *
	 * A brace-bodied arrow returns '' on purpose: that body has a `return` and
	 * belongs to the caller's existing path.
	 */
	private function arrow_body_jsx( string $code ): string {
		$eq = strpos( $code, '=' );
		if ( false === $eq ) {
			return '';
		}
		$rhs = ltrim( substr( $code, $eq + 1 ) );

		// Peel `forwardRef(`, `memo(`, `React.forwardRef<…>(` — possibly nested.
		for ( $round = 0; $round < 4; $round++ ) {
			if ( preg_match( '/^(?:React\.)?(?:forwardRef|memo)\s*(?:<[\s\S]*?>\s*)?\(/', $rhs, $m ) !== 1 ) {
				break;
			}
			$open  = strlen( $m[0] ) - 1;
			$close = $this->match_pair( $rhs, $open );
			if ( $close < 0 ) {
				return '';
			}
			$rhs = ltrim( substr( $rhs, $open + 1, $close - $open - 1 ) );
		}

		// Skip the parameter list, then require the arrow.
		$i      = 0;
		$length = strlen( $rhs );
		if ( $i < $length && $rhs[ $i ] === '(' ) {
			$close = $this->match_pair( $rhs, $i );
			if ( $close < 0 ) {
				return '';
			}
			$i = $close + 1;
		} else {
			// A single unparenthesised parameter: `props => (…)`.
			while ( $i < $length && preg_match( '/[A-Za-z0-9_$]/', $rhs[ $i ] ) === 1 ) {
				++$i;
			}
		}
		// A TypeScript return annotation can sit between the params and the
		// arrow: `(props): JSX.Element => (…)`.
		while ( $i < $length && $rhs[ $i ] !== '=' ) {
			++$i;
		}
		if ( $i + 1 >= $length || $rhs[ $i ] !== '=' || $rhs[ $i + 1 ] !== '>' ) {
			return '';
		}
		$body = ltrim( substr( $rhs, $i + 2 ) );

		if ( $body === '' || $body[0] === '{' ) {
			return '';
		}
		if ( $body[0] === '(' ) {
			$close = $this->match_pair( $body, 0 );
			if ( $close < 0 ) {
				return '';
			}
			$inner = trim( substr( $body, 1, $close - 1 ) );

			return str_starts_with( $inner, '<' ) ? $inner : '';
		}
		if ( $body[0] === '<' ) {
			// Trim the declaration's own tail — `);` or `));` and a displayName.
			$inner = (string) preg_replace( '/\s*\)*\s*;?\s*$/', '', $body );

			return trim( $inner );
		}

		return '';
	}

	private function extract_return_jsx( string $code ): string {
		if ( ! preg_match_all( '/return\s*\(\s*</', $code, $matches, PREG_OFFSET_CAPTURE ) ) {
			if ( preg_match( '/return\s*(<[\\s\\S]+);?\s*\}?\s*$/', $code, $m ) ) {
				return trim( rtrim( $m[1], ';' ) );
			}

			/*
			 * No `return` anywhere: a concise arrow body, which is how the
			 * shadcn/ui kit writes every one of its components —
			 * `const Card = React.forwardRef(({ className, ...props }, ref) => (
			 *   <div className={cn("rounded-xl border", className)} {...props} />
			 * ))`.
			 *
			 * Without this the method returned '' and the component rendered
			 * NOTHING — it deleted itself and its children. That single gap hid
			 * four others behind it: cn() merging, cva() variants, rest-spread
			 * and native tag preservation were all untestable while the body
			 * was never reached.
			 */
			return $this->arrow_body_jsx( $code );
		}

		$candidates = array();
		foreach ( $matches[0] as $row ) {
			// Align to the opening "(" before the JSX "<".
			$at = (int) $row[1];
			$open = strpos( $code, '(', $at );
			if ( false === $open ) {
				continue;
			}
			$end = $this->match_pair( $code, $open );
			if ( $end < 0 ) {
				continue;
			}
			$inner = trim( substr( $code, $open + 1, $end - $open - 1 ) );
			if ( $inner !== '' && str_starts_with( $inner, '<' ) ) {
				$candidates[] = array(
					'html'    => $inner,
					'guarded' => $this->return_is_if_guarded( $code, $at ),
				);
			}
		}

		if ( $candidates === array() ) {
			return '';
		}

		// Prefer the largest unguarded return so nested `.map(() => { return (...); })`
		// bodies do not steal the component's root markup.
		$best = null;
		$best_len = -1;
		foreach ( $candidates as $candidate ) {
			if ( ! empty( $candidate['guarded'] ) ) {
				continue;
			}
			$len = strlen( (string) $candidate['html'] );
			if ( $len > $best_len ) {
				$best     = $candidate['html'];
				$best_len = $len;
			}
		}
		if ( is_string( $best ) ) {
			return $best;
		}

		return $candidates[ count( $candidates ) - 1 ]['html'];
	}

	/**
	 * True when this return sits inside `if (...) { return (...); }`.
	 */
	private function return_is_if_guarded( string $code, int $return_at ): bool {
		$before = substr( $code, max( 0, $return_at - 120 ), min( 120, $return_at ) );
		return (bool) preg_match( '/\bif\s*\([^)]*\)\s*\{\s*$/', $before )
			|| (bool) preg_match( '/\bif\s*\([^)]*\)\s*$/', rtrim( $before ) );
	}

	/**
	 * Bind a component's destructured parameter list into its scope.
	 *
	 * Each part is `key`, optionally `: local` to rename, optionally
	 * `= default`. The rename form is how polymorphic components take their
	 * tag: `function Reveal({ children, as: Tag = "div" })` renders `<Tag>`.
	 * Reading only `key = default` left `Tag` unbound, `<Tag>` fell through to
	 * the unresolved-component placeholder, and a design that built its
	 * lifecycle list from `<Reveal as="li">` got `<span>`s inside its `<ol>` —
	 * which the list conversion then dropped, losing five rows of copy.
	 *
	 * @param array<string, mixed> $props
	 * @return array<string, mixed>
	 */
	private function apply_default_props( string $code, array $props, array $own = array() ): array {
		/*
		 * Matches a destructured parameter list followed by EITHER a block body
		 * or an arrow, and tolerates a second parameter after it.
		 *
		 * It used to require `) {`, so a concise-arrow component bound no
		 * defaults at all, and `React.forwardRef(({ className, ...props }, ref)
		 * => …)` failed twice over — once for the arrow and once for the `, ref`.
		 * That is the signature of every component in the shadcn kit.
		 */
		if ( ! preg_match( '/\(\s*\{([\s\S]*?)\}\s*(?::[^{=]+?)?\s*(?:,\s*[A-Za-z_][\w]*\s*)?\)\s*(?:=>|\{)/', $code, $m ) ) {
			return $props;
		}

		$named = array();
		$rest  = '';

		foreach ( $this->split_params( $m[1] ) as $part ) {
			$part = trim( $part );

			/*
			 * A rest element — `...props`. React puts everything not named
			 * explicitly into it, CHILDREN INCLUDED, which is how the kit
			 * forwards content: `({ className, ...props }) => <div {...props} />`.
			 * Nothing bound it before, so the spread contributed nothing and
			 * Card, CardContent and Button rendered with the right classes and
			 * no content whatsoever.
			 */
			if ( str_starts_with( $part, '...' ) ) {
				$rest = trim( substr( $part, 3 ) );
				continue;
			}

			if ( ! preg_match( '/^([A-Za-z_][\w]*)\s*(?::\s*([A-Za-z_][\w]*)\s*)?(?:=\s*([\s\S]+))?$/', $part, $pm ) ) {
				continue;
			}
			$named[] = $pm[1];
			$key     = $pm[1];
			$local   = ( $pm[2] ?? '' ) !== '' ? $pm[2] : $key;
			$default = $pm[3] ?? '';

			// A rename binds the local name to whatever the caller passed.
			if ( $local !== $key && array_key_exists( $key, $props ) ) {
				$props[ $local ] = $props[ $key ];
			}
			if ( $default === '' ) {
				continue;
			}
			if ( array_key_exists( $local, $props ) && $props[ $local ] !== null ) {
				continue;
			}
			$rhs = $this->strip_as_cast( rtrim( trim( $default ), " \t," ) );
			$props[ $local ] = $this->eval_source( $rhs, $props );
		}

		if ( $rest !== '' ) {
			$remainder = array();
			foreach ( $own as $key => $value ) {
				if ( ! in_array( (string) $key, $named, true ) ) {
					$remainder[ $key ] = $value;
				}
			}
			$props[ $rest ] = $remainder;
		}

		return $props;
	}

	/**
	 * Split a destructured parameter list on its top-level commas, so a default
	 * value that contains one — `as: Tag = cn("a", "b")` — stays in one piece.
	 *
	 * @return array<int, string>
	 */
	private function split_params( string $chunk ): array {
		$out   = array();
		$buf   = '';
		$depth = 0;
		$quote = '';
		$length = strlen( $chunk );

		for ( $i = 0; $i < $length; $i++ ) {
			$ch = $chunk[ $i ];
			if ( $quote !== '' ) {
				$buf .= $ch;
				if ( $ch === '\\' && $i + 1 < $length ) {
					$buf .= $chunk[ ++$i ];
					continue;
				}
				if ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( $ch === '"' || $ch === "'" || $ch === '`' ) {
				$quote = $ch;
				$buf  .= $ch;
				continue;
			}
			if ( str_contains( '([{', $ch ) ) {
				++$depth;
			} elseif ( str_contains( ')]}', $ch ) ) {
				--$depth;
			} elseif ( $ch === ',' && $depth === 0 ) {
				$out[] = $buf;
				$buf   = '';
				continue;
			}
			$buf .= $ch;
		}
		if ( trim( $buf ) !== '' ) {
			$out[] = $buf;
		}

		return $out;
	}

	private function match_pair( string $src, int $open ): int {
		$pairs = array(
			'(' => ')',
			'[' => ']',
			'{' => '}',
		);
		$open_ch = $src[ $open ];
		if ( ! isset( $pairs[ $open_ch ] ) ) {
			return -1;
		}
		$stack     = array( $pairs[ $open_ch ] );
		$length    = strlen( $src );
		$in_string = '';
		$escape    = false;
		for ( $i = $open + 1; $i < $length; $i++ ) {
			$char = $src[ $i ];
			if ( $in_string !== '' ) {
				if ( $escape ) {
					$escape = false;
					continue;
				}
				if ( $char === '\\' && $in_string !== '`' ) {
					$escape = true;
					continue;
				}
				if ( $char === $in_string ) {
					$in_string = '';
				}
				continue;
			}
			if ( $char === '"' || $char === '`' ) {
				$in_string = $char;
				continue;
			}
			if ( $char === "'" ) {
				$prev = $i > 0 ? $src[ $i - 1 ] : '';
				$nxt  = $i + 1 < $length ? $src[ $i + 1 ] : '';
				if ( preg_match( '/[A-Za-z]/', $prev ) && preg_match( '/[A-Za-z]/', $nxt ) ) {
					continue;
				}
				$in_string = "'";
				continue;
			}
			if ( isset( $pairs[ $char ] ) ) {
				$stack[] = $pairs[ $char ];
				continue;
			}
			if ( $stack !== array() && $char === $stack[ count( $stack ) - 1 ] ) {
				array_pop( $stack );
				if ( $stack === array() ) {
					return $i;
				}
			}
		}

		return -1;
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function eval_source( string $src, array $scope ): mixed {
		$src = self::strip_type_args( $this->strip_as_cast( trim( $src ) ) );
		$src = rtrim( $src, ';' );
		$prev_src = $this->src;
		$prev_i   = $this->i;
		$prev_len = $this->len;
		$this->src = $src;
		$this->i   = 0;
		$this->len = strlen( $src );
		try {
			$value = $this->parse_expression( $scope );
			$this->ws();
			return $value;
		} catch ( \Throwable $e ) {
			$this->note_unevaluated( 'threw:' . $e->getMessage(), $src );
			return null;
		} finally {
			$this->src = $prev_src;
			$this->i   = $prev_i;
			$this->len = $prev_len;
			unset( $e );
		}
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function eval_jsx( string $src, array $scope ): string {
		$src = trim( $src );
		$prev_src = $this->src;
		$prev_i   = $this->i;
		$prev_len = $this->len;
		$this->src = $src;
		$this->i   = 0;
		$this->len = strlen( $src );
		/*
		 * What a parse sets and puts back around a subtree, as it stood on
		 * entry: the class and style expression flags, provider and Radix
		 * stacks, and a child render's registers. A throw skips every restore
		 * between it and here, so without this the rest of the page was parsed
		 * as if still inside the class expression or provider it left.
		 */
		$entry = array(
			'in_class_expr'  => $this->in_class_expr,
			'in_style_expr'  => $this->in_style_expr,
			'cls_gates'      => $this->cls_gates,
			'style_gates'    => $this->style_gates,
			'radix_scopes'   => $this->radix_scopes,
			'context_values' => $this->context_values,
			'next_id'        => $this->next_id,
			'depth'          => $this->depth,
			'setters'        => $this->setters,
			'toggles'        => $this->toggles,
			'driven'         => $this->driven,
			'state_init'     => $this->state_init,
		);
		try {
			$html = $this->parse_jsx( $scope );
			return is_string( $html ) ? $html : $this->stringify( $html );
		} catch ( \Throwable $e ) {
			/*
			 * The whole subtree is lost, so it is reported: this used to return
			 * '' and say nothing, which is exactly the silent loss unevaluated()
			 * exists to surface.
			 */
			foreach ( $entry as $register => $value ) {
				$this->$register = $value;
			}
			$this->note_unevaluated( 'jsx-threw:' . $e->getMessage(), $src );
			return '';
		} finally {
			$this->src = $prev_src;
			$this->i   = $prev_i;
			$this->len = $prev_len;
			unset( $e );
		}
	}

	/**
	 * Drop a call's TYPE ARGUMENTS: `useForm<Values>({…})` → `useForm({…})`.
	 *
	 * The expression evaluator has no notion of them, and it read the `<` as an
	 * operator — so `React.createContext<Ctx | null>(null)` evaluated to
	 * `false` and the context was never created. A real build erases these.
	 *
	 * Deliberately narrow: only a `<…>` that sits between a callee name and the
	 * `(` of its argument list, with no parentheses inside. That shape cannot
	 * be a comparison — `a < b > (c)` would need the parenthesis to be part of
	 * an expression, and `a < b` alone never reaches a `>(`.
	 */
	private static function strip_type_args( string $src ): string {
		if ( ! str_contains( $src, '<' ) ) {
			return $src;
		}

		return (string) preg_replace(
			'/\b([A-Za-z_$][\w$]*(?:\.[A-Za-z_$][\w$]*)*)<[^<>()]*(?:<[^<>()]*>[^<>()]*)*>\s*\(/',
			'$1(',
			$src
		);
	}

	private function strip_as_cast( string $src ): string {
		$out        = '';
		$len        = strlen( $src );
		$i          = 0;
		$in_string  = '';
		$escape     = false;
		while ( $i < $len ) {
			$ch = $src[ $i ];
			if ( $in_string !== '' ) {
				$out .= $ch;
				if ( $escape ) {
					$escape = false;
				} elseif ( $ch === '\\' && $in_string !== '`' ) {
					$escape = true;
				} elseif ( $ch === $in_string ) {
					$in_string = '';
				}
				++$i;
				continue;
			}
			if ( $ch === '"' || $ch === "'" || $ch === '`' ) {
				$in_string = $ch;
				$out      .= $ch;
				++$i;
				continue;
			}
			$prev = $i > 0 ? $src[ $i - 1 ] : ' ';
			if (
				( $i + 2 ) <= $len
				&& $src[ $i ] === 'a'
				&& $src[ $i + 1 ] === 's'
				&& preg_match( '/[\s)\]\}"\']/', $prev )
				&& ( $i + 2 === $len || preg_match( '/\s/', $src[ $i + 2 ] ) )
			) {
				$j = $i + 2;
				while ( $j < $len && ctype_space( $src[ $j ] ) ) {
					++$j;
				}
				$rest = substr( $src, $j, 12 );
				if ( str_starts_with( $rest, 'const' ) && ( strlen( $rest ) === 5 || ! preg_match( '/\w/', $rest[5] ?? '' ) ) ) {
					$i = $j + 5;
					continue;
				}
				if ( $j < $len && preg_match( '/[A-Za-z_$]/', $src[ $j ] ) ) {
					$i     = $j;
					$depth = 0;
					while ( $i < $len ) {
						$c = $src[ $i ];
						if ( $c === '<' || $c === '(' || $c === '[' ) {
							++$depth;
						} elseif ( $c === '>' || $c === ')' || $c === ']' ) {
							if ( 0 === $depth ) {
								break;
							}
							--$depth;
						} elseif ( 0 === $depth && str_contains( ',}:;={', $c ) ) {
							break;
						}
						++$i;
					}
					continue;
				}
			}
			$out .= $ch;
			++$i;
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function parse_jsx( array $scope ): string {
		$this->ws();
		if ( $this->peek() !== '<' ) {
			return $this->stringify( $this->parse_expression( $scope ) );
		}

		return $this->parse_element( $scope );
	}

	/**
	 * The Radix package and part a tag renders, however it is spelled.
	 *
	 * Three spellings reach here: the member expression itself
	 * (`<TabsPrimitive.Trigger>`), a re-export of one (`<Tabs>` for
	 * `TabsPrimitive.Root`), and a wrapper component whose body renders one
	 * (`<TabsTrigger>`, a forwardRef around `<TabsPrimitive.Trigger>`). The
	 * third is how the whole shadcn kit is written, so resolving only the
	 * first two would see none of a real page.
	 *
	 * @param array<string, mixed> $scope
	 * @return array{0: string, 1: string}|null
	 */
	private function radix_part_of( string $name, array $scope ): ?array {
		if ( str_contains( $name, '.' ) ) {
			$parts = explode( '.', $name );
			$head  = array_shift( $parts );
			$package = $this->radix[ $head ] ?? '';

			return $package !== '' && count( $parts ) === 1 ? array( $package, (string) $parts[0] ) : null;
		}
		if ( isset( $this->radix_alias[ $name ] ) ) {
			return $this->radix_alias[ $name ];
		}

		if ( ! array_key_exists( $name, $this->radix_part ) ) {
			$this->radix_part[ $name ] = null;
			$code = $this->components[ $name ] ?? '';
			if ( $code !== '' && preg_match_all( '#<([A-Za-z_$][\w$]*)\.([A-Za-z_$][\w$]*)[\s/>]#', $code, $hits, PREG_SET_ORDER ) ) {
				foreach ( $hits as $hit ) {
					$package = $this->radix[ $hit[1] ] ?? '';
					if ( $package !== '' ) {
						$this->radix_part[ $name ] = array( $package, (string) $hit[2] );
						break;
					}
				}
			}
		}

		return $this->radix_part[ $name ];
	}

	/**
	 * The children a Radix part renders when it supplies its own.
	 *
	 * Only `Select.Value` does: it shows the selected item's text, or the
	 * `placeholder` when nothing is selected. Everything else passes through.
	 *
	 * @param array<string, mixed> $attrs
	 */
	private function radix_children( string $package, string $part, array $attrs, string $children ): string {
		if ( $package !== 'select' || $part !== 'Value' || trim( $children ) !== '' ) {
			return $children;
		}

		$chosen = '';
		foreach ( $this->radix_scopes as $open ) {
			if ( ( $open['kind'] ?? '' ) === 'root' && ( $open['package'] ?? '' ) === 'select' ) {
				$chosen = (string) ( $open['selected'] ?? '' );
			}
		}

		return esc_html( $chosen !== '' ? $chosen : $this->stringify_attr( $attrs['placeholder'] ?? '' ) );
	}

	/**
	 * The state attributes a Radix part carries, given the open scopes.
	 *
	 * @param array<string, mixed> $attrs
	 * @return array<string, string>
	 */
	private function radix_state_attrs( string $package, string $part, array $attrs ): array {
		$out = Radix_Primitives::attributes( $package, $part );

		/*
		 * An accordion's `type` is the one thing the front end cannot work out
		 * for itself. Radix keeps it in the root's props and puts nothing on
		 * the DOM, so a converted page had no way to tell `single` — where
		 * opening one panel closes the rest — from `multiple`, where they
		 * stack. The runtime followed the majority and closed the siblings of
		 * a multiple accordion, which is the opposite of what the design does.
		 *
		 * So it is published, under our own name because it is our addition
		 * rather than something Radix emits. `collapsible` comes with it:
		 * without it a single accordion refuses to close the open panel, and
		 * Radix marks that trigger `aria-disabled` rather than making the
		 * click a silent no-op.
		 */
		if ( $part === 'Root' && ( $package === 'accordion' || $package === 'collapsible' ) ) {
			$type = $this->stringify_attr( $attrs['type'] ?? '' );
			$out['data-dxai-accordion'] = $type !== '' ? $type : 'single';
			if ( ! empty( $attrs['collapsible'] ) || $type === 'multiple' ) {
				$out['data-dxai-collapsible'] = 'true';
			}
		}

		if ( ! Radix_Primitives::is_stateful( $package, $part ) ) {
			return $out;
		}

		$root = null;
		$item = null;
		foreach ( $this->radix_scopes as $scope ) {
			if ( (string) ( $scope['package'] ?? '' ) !== $package ) {
				continue;
			}
			if ( ( $scope['kind'] ?? '' ) === 'root' ) {
				$root = $scope;
			} else {
				$item = $scope;
			}
		}
		if ( $root === null ) {
			return $out;
		}

		$active = $root['active'] ?? null;
		$own    = $item !== null ? ( $item['value'] ?? null ) : $this->stringify_attr( $attrs['value'] ?? '' );
		$base   = (string) ( $root['base'] ?? 'dxai-rx' );
		/*
		 * The slot names the trigger/panel PAIR, and where it comes from
		 * differs by component. An accordion wraps each pair in an `Item`, so
		 * the item's ordinal identifies it. Tabs have no item: the trigger and
		 * the panel each carry the same `value`, which is what Radix itself
		 * uses — `radix-:r1:-trigger-day-1`. Taking the item's ordinal for
		 * both gave all three tabs the id `-trigger-0`, three duplicate ids on
		 * one page.
		 */
		$slot = $item !== null
			? (string) $item['slot']
			: sanitize_html_class( (string) $own );
		if ( $slot === '' ) {
			$slot = '0';
		}
		$on = $own !== null && $own !== '' && (string) $own === (string) $active;

		$trigger_id = $base . '-trigger-' . $slot;
		$content_id = $base . '-content-' . $slot;

		/*
		 * The overlays: a dialog, a menu, a popover. At rest every one is
		 * closed and Radix has not rendered its content at all; a converted
		 * page keeps the content hidden, so the trigger has to be able to
		 * find it — `aria-controls` at rest, which Radix adds only while open.
		 * A dialog's content is labelled by its own title and described by
		 * its own description; a menu is labelled by the trigger that opened
		 * it. Both measured on the React build, not taken from the docs.
		 */
		$overlays = array( 'dialog', 'alert-dialog', 'dropdown-menu', 'context-menu', 'menubar', 'popover', 'hover-card', 'tooltip' );
		if ( in_array( $package, $overlays, true ) ) {
			$menu    = in_array( $package, array( 'dropdown-menu', 'context-menu', 'menubar' ), true );
			$modal   = $package === 'dialog' || $package === 'alert-dialog';
			$hover   = $package === 'tooltip' || $package === 'hover-card';
			$suffix  = '-' . $slot;
			switch ( $part ) {
				case 'Trigger':
					$out['id']         = $trigger_id;
					$out['data-state'] = 'closed';
					if ( $package === 'tooltip' ) {
						/*
						 * A tooltip trigger has no aria-expanded and announces
						 * no popup. Radix ties it to its content with
						 * aria-describedby, and only while the tooltip is
						 * open; here it is emitted at rest for the reason
						 * aria-controls is below — the content is on the
						 * page, hidden, and the runtime finds it from the
						 * trigger. It also reads correctly at rest: a hidden
						 * element named by aria-describedby is still part of
						 * the accessible description, so the button is
						 * described by its tooltip's text.
						 */
						$out['aria-describedby'] = $content_id;
						$delay                   = (string) ( $root['delay'] ?? '' );
						if ( $delay !== '' ) {
							// `delayDuration` is a prop Radix consumes, published
							// under our name so the runtime can honour it.
							$out['data-dxai-delay'] = $delay;
						}
					} elseif ( $hover ) {
						// A hover card links nothing in ARIA at all, so the
						// pairing is ours.
						$out['data-dxai-controls'] = $content_id;
					} else {
						$out['aria-expanded'] = 'false';
						$out['aria-controls'] = $content_id;
					}
					break;
				case 'Content':
					$out['id']         = $content_id;
					$out['data-state'] = 'closed';
					if ( $menu ) {
						$out['aria-labelledby'] = $trigger_id;
					}
					if ( $modal ) {
						$out['aria-labelledby']  = $base . '-title' . $suffix;
						$out['aria-describedby'] = $base . '-description' . $suffix;
					} else {
						/*
						 * Everything that is not a dialog is a popper: the
						 * panel sits on one side of its trigger, aligned along
						 * it. Radix writes `data-side` and `data-align` on the
						 * content once it has placed it, and the kit's classes
						 * are keyed on them — `data-[side=bottom]:slide-in-from-top-2`
						 * — so a panel without them opened with no entrance
						 * motion. At rest they hold what the props say, or
						 * what Radix defaults to when they are absent: a
						 * tooltip opens above its trigger, everything else
						 * below, all of them centred. The runtime rewrites
						 * them if it has to flip the side to stay on screen,
						 * which is what Radix does too.
						 */
						$side  = $this->stringify_attr( $attrs['side'] ?? '' );
						$align = $this->stringify_attr( $attrs['align'] ?? '' );
						$out['data-side']  = $side !== '' ? $side : ( $package === 'tooltip' ? 'top' : 'bottom' );
						$out['data-align'] = $align !== '' ? $align : 'center';
						$offset = $this->stringify_attr( $attrs['sideOffset'] ?? '' );
						if ( $offset !== '' && $offset !== '0' ) {
							// The gap between trigger and panel. A consumed
							// prop, so under our name; the kit sets 4.
							$out['data-dxai-offset'] = $offset;
						}
					}
					break;
				case 'Overlay':
					// Derivable from the content id by the runtime, so the
					// two are paired without another attribute.
					$out['id']         = $base . '-overlay' . $suffix;
					$out['data-state'] = 'closed';
					break;
				case 'Title':
					$out['id'] = $base . '-title' . $suffix;
					break;
				case 'Description':
					$out['id'] = $base . '-description' . $suffix;
					break;
				case 'Close':
				case 'Cancel':
				case 'Action':
					/*
					 * Nothing in Radix's DOM distinguishes the button that
					 * closes a dialog from any other button inside it — the
					 * behaviour is a React handler. Our own attribute, named
					 * as ours, is the only way the front end can know.
					 */
					$out['data-dxai-dismiss'] = 'true';
					break;
			}

			return $out;
		}

		if ( $package === 'select' ) {
			/*
			 * A select at rest is closed and its list is not in the document
			 * at all — so only the item's checked state depends on the value.
			 */
			if ( $part === 'Item' ) {
				$out['data-state']    = $on ? 'checked' : 'unchecked';
				$out['aria-selected'] = $on ? 'true' : 'false';

				return $out;
			}
			$out['data-state'] = 'closed';
			/*
			 * The pair, so the front-end runtime can find one from the other.
			 * Radix sets `aria-controls` on the combobox only while open and
			 * does not render the list at all when closed; a converted page
			 * keeps the list hidden, so the link has to exist at rest or
			 * nothing could open it. An extra valid IDREF costs nothing.
			 */
			if ( $part === 'Trigger' ) {
				$out['aria-expanded'] = 'false';
				$out['aria-controls'] = $content_id;
			}
			if ( $part === 'Content' ) {
				$out['id'] = $content_id;
			}

			return $out;
		}

		if ( $package === 'tabs' ) {
			$out['data-state'] = $on ? 'active' : 'inactive';
			if ( $part === 'Trigger' ) {
				$out['aria-selected'] = $on ? 'true' : 'false';
				$out['id']            = $trigger_id;
				$out['aria-controls'] = $content_id;
			}
			if ( $part === 'Content' ) {
				$out['id']              = $content_id;
				$out['aria-labelledby'] = $trigger_id;
				if ( ! $on ) {
					// Radix keeps every panel mounted and hides the inactive
					// ones, which is why a converted page can hold them all.
					$out['hidden'] = 'true';
				}
			}

			return $out;
		}

		// accordion and collapsible
		$out['data-state'] = $on ? 'open' : 'closed';
		if ( $part === 'Trigger' ) {
			$out['aria-expanded'] = $on ? 'true' : 'false';
			$out['id']            = $trigger_id;
			if ( $on ) {
				// Radix adds this only while open — measured, not assumed.
				$out['aria-controls'] = $content_id;
				/*
				 * A `single` accordion that is not `collapsible` cannot close
				 * its open panel, and Radix says so on the element rather than
				 * letting the click look available and do nothing.
				 */
				$multiple = (string) ( $root['type'] ?? '' ) === 'multiple';
				if ( ! $multiple && empty( $root['collapsible'] ) ) {
					$out['aria-disabled'] = 'true';
				}
			}
		}
		if ( $part === 'Content' ) {
			$out['id']              = $content_id;
			$out['aria-labelledby'] = $trigger_id;
			if ( ! $on ) {
				$out['hidden'] = 'true';
			}
		}

		return $out;
	}

	/**
	 * The context a COMPONENT publishes around the children it is handed.
	 *
	 * Detected from the component's own source — a `<X.Provider value={…}>` in
	 * its body — and the value is evaluated here, with a freshly minted id
	 * armed so the component's `useId()` yields the same one. Returns null for
	 * every component that publishes nothing, which is almost all of them.
	 *
	 * @param array<string, mixed> $scope
	 * @return array{key: string, value: mixed, id: string}|null
	 */
	private function provided_context( string $name, array $scope ): ?array {
		$code = $this->components[ $name ] ?? '';
		if ( $code === '' || ! str_contains( $code, '.Provider' ) ) {
			return null;
		}

		if ( ! array_key_exists( $name, $this->provides ) ) {
			$this->provides[ $name ] = null;
			if ( preg_match( '/<([A-Za-z_$][\w$]*)\.Provider\s+value=\{([\s\S]*?)\}\s*>/', $code, $match ) === 1 ) {
				$holder = $this->globals[ $match[1] ] ?? null;
				$key    = is_array( $holder ) ? (string) ( $holder[ self::CONTEXT ] ?? '' ) : '';
				if ( $key !== '' ) {
					$this->provides[ $name ] = array( 'key' => $key, 'value' => trim( $match[2] ) );
				}
			}
		}

		$found = $this->provides[ $name ];
		if ( $found === null ) {
			return null;
		}

		++$this->ids;
		$id            = 'dxai-id-' . $this->ids;
		$this->next_id = $id;
		// The component's prelude binds whatever the value expression reads —
		// for FormItem that is the `const id = React.useId()` just armed.
		$local = $this->bind_locals( $code, array_merge( $this->globals, $scope ) );
		$value = $this->eval_source( $found['value'], $local );
		$this->next_id = null;

		return array( 'key' => $found['key'], 'value' => $value, 'id' => $id );
	}

	/**
	 * The context key a `<Something.Provider>` tag publishes, or ''.
	 *
	 * @param array<string, mixed> $scope
	 */
	private function context_of( string $name, array $scope ): string {
		if ( ! str_ends_with( $name, '.Provider' ) ) {
			return '';
		}
		$head  = substr( $name, 0, -strlen( '.Provider' ) );
		$value = $scope[ $head ] ?? $this->globals[ $head ] ?? null;

		return is_array( $value ) ? (string) ( $value[ self::CONTEXT ] ?? '' ) : '';
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function parse_element( array $scope ): string {
		$this->ws();
		if ( $this->peek() !== '<' ) {
			return '';
		}
		++$this->i;
		if ( $this->peek() === '>' ) {
			++$this->i;
			return $this->parse_children( $scope, '' );
		}
		if ( $this->peek() === '/' ) {
			return '';
		}

		$name = $this->read_tag_name();
		$attrs = $this->parse_attrs( $scope );
		$this->ws();
		$self = false;
		if ( $this->peek() === '/' ) {
			++$this->i;
			$self = true;
		}
		$this->ws();
		if ( $this->peek() === '>' ) {
			++$this->i;
		}

		/*
		 * A context provider must publish its value BEFORE its children are
		 * read, because that is the only window in which they can see it —
		 * children are rendered depth-first, so by the time emit_element()
		 * runs for the provider its subtree is already a string. Pushed here
		 * and popped after, which is the lifetime React gives it.
		 */
		$context = $this->context_of( $name, $scope );
		if ( $context !== '' ) {
			$this->context_values[ $context ][] = $attrs['value'] ?? null;
		}

		/*
		 * A COMPONENT that publishes a context to the children it is given —
		 * `<FormItem>` around a label and a control. Its own body cannot do it
		 * (see $next_id), so the value is computed here, before the subtree is
		 * read, and the component is handed the same id when it renders.
		 */
		$provided = $context === '' ? $this->provided_context( $name, $scope ) : null;
		if ( $provided !== null ) {
			$this->context_values[ $provided['key'] ][] = $provided['value'];
		}

		/*
		 * A Radix root or item, opened before its subtree is read. The root
		 * carries which value is selected — a trigger cannot know whether it
		 * is the active one without it — and the item carries the value its
		 * own trigger and panel must agree on.
		 */
		$radix   = $this->radix_part_of( $name, $scope );
		$pushed  = false;
		if ( $radix !== null && ( $radix[1] === 'Root' || $radix[1] === 'Item' ) ) {
			if ( $radix[1] === 'Root' ) {
				++$this->radix_roots;
				$active = $this->stringify_attr( $attrs['defaultValue'] ?? $attrs['value'] ?? '' );
				/*
				 * A select's TRIGGER shows the selected item's own text, and
				 * the items are written after it — `<SelectTrigger>` then
				 * `<SelectContent>`. Nothing has parsed them yet, so the text
				 * is read from the source ahead of the cursor, which is where
				 * the children still are. Without it the trigger rendered an
				 * empty span where the design says "Northwind UK".
				 */
				$selected = '';
				if ( $radix[0] === 'select' && $active !== '' ) {
					$ahead = substr( $this->src, $this->i, 6000 );
					if ( preg_match( '/value=(["\'])' . preg_quote( $active, '/' ) . '\1[^>]*>\s*([^<]{1,160}?)\s*</', $ahead, $hit ) === 1 ) {
						$selected = trim( (string) $hit[2] );
					}
				}
				$this->radix_scopes[] = array(
					'kind'     => 'root',
					'package'  => $radix[0],
					'active'   => $active,
					'selected' => $selected,
					// Carried for the parts: an accordion trigger's
					// aria-disabled depends on the root's `collapsible`.
					'type'     => $this->stringify_attr( $attrs['type'] ?? '' ),
					'collapsible' => ! empty( $attrs['collapsible'] ),
					// A tooltip's own `delayDuration`, for its trigger.
					'delay'    => $this->stringify_attr( $attrs['delayDuration'] ?? '' ),
					'base'     => 'dxai-rx-' . $this->radix_roots,
					'slots'    => 0,
				);
			} else {
				$slot = 0;
				for ( $at = count( $this->radix_scopes ) - 1; $at >= 0; $at-- ) {
					if ( ( $this->radix_scopes[ $at ]['kind'] ?? '' ) === 'root'
						&& (string) ( $this->radix_scopes[ $at ]['package'] ?? '' ) === $radix[0] ) {
						$slot = (int) $this->radix_scopes[ $at ]['slots'] + 1;
						$this->radix_scopes[ $at ]['slots'] = $slot;
						break;
					}
				}
				$this->radix_scopes[] = array(
					'kind'    => 'item',
					'package' => $radix[0],
					'value'   => $this->stringify_attr( $attrs['value'] ?? '' ),
					'slot'    => (string) $slot,
				);
			}
			$pushed = true;
		}

		$children = '';
		if ( ! $self ) {
			$children = $this->parse_children( $scope, $name );
		}

		if ( $context !== '' ) {
			array_pop( $this->context_values[ $context ] );
		}
		if ( $provided !== null ) {
			array_pop( $this->context_values[ $provided['key'] ] );
			$this->next_id = $provided['id'];
		}

		$html = $this->emit_element( $name, $attrs, $children, $scope );

		// After the element itself: a root or item part reads its own scope.
		if ( $pushed ) {
			array_pop( $this->radix_scopes );
		}

		return $html;
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function parse_children( array $scope, string $closing ): string {
		$html = '';
		$last = -1;
		while ( $this->i < $this->len ) {
			if ( ! $this->advanced( $last ) ) {
				break;
			}
			$ch = $this->peek();
			if ( $ch === '<' ) {
				if ( $this->peek_at( $this->i + 1 ) === '/' ) {
					$this->i += 2;
					$end_name = $this->read_tag_name();
					$this->ws();
					if ( $this->peek() === '>' ) {
						++$this->i;
					}
					unset( $end_name );
					break;
				}
				if ( $this->peek_at( $this->i + 1 ) === '>' ) {
					$html .= $this->parse_element( $scope );
					continue;
				}
				$html .= $this->parse_element( $scope );
				continue;
			}
			if ( $ch === '{' ) {
				$html .= $this->parse_child_expr( $scope );
				continue;
			}
			$next = $this->next_special();
			$text = substr( $this->src, $this->i, $next - $this->i );
			$this->i = $next;
			$this->note_residue( $text );
			// JSX whitespace, not HTML whitespace: see jsx_text().
			$html   .= $this->esc_text( self::jsx_text( $text ) );
		}

		return $html;
	}

	private function next_special(): int {
		$lt = strpos( $this->src, '<', $this->i );
		$br = strpos( $this->src, '{', $this->i );
		$candidates = array_filter( array( $lt, $br ), static fn( $v ) => false !== $v );
		if ( $candidates === array() ) {
			return $this->len;
		}

		return (int) min( $candidates );
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function parse_child_expr( array $scope ): string {
		if ( $this->peek() !== '{' ) {
			return '';
		}
		$brace = $this->i;
		++$this->i;
		$this->ws();
		if ( $this->peek() === '/' && $this->peek_at( $this->i + 1 ) === '*' ) {
			$this->skip_block_comment();
			$this->ws();
			if ( $this->peek() === '}' ) {
				++$this->i;
			}
			return '';
		}

		$slice = substr( $this->src, $this->i, 400 );
		if ( preg_match( '/^(?:\[[^\]]*\]|[A-Za-z_][\w]*(?:\.[A-Za-z_][\w]*)*)\s*\.\s*map\s*\(/', $slice ) ) {
			$html = $this->parse_map( $scope );
			$this->ws();
			// Swallow stray ", )" if map closed early, then the outer `}`.
			while ( $this->peek() === ',' || $this->peek() === ')' ) {
				++$this->i;
				$this->ws();
			}
			if ( $this->peek() === '}' ) {
				++$this->i;
			}
			return $html;
		}

		if ( $this->peek() === '<' ) {
			$html = $this->parse_element( $scope );
			$this->ws();
			if ( $this->peek() === '}' ) {
				++$this->i;
			}
			return $html;
		}

		$expr_at = $this->i;
		$value   = $this->parse_expression( $scope );
		$this->note_dropped_branch( substr( $this->src, $expr_at, $this->i - $expr_at ), $value, $scope );
		$this->ws();
		if ( $this->peek() === '}' ) {
			++$this->i;
			return $this->stringify( $value );
		}

		/*
		 * parse_postfix() rewinds onto `.map(` and hands the chain back when
		 * the callback returns markup, expecting a renderer to take it. The
		 * entry test above only recognises a plain member chain, so
		 * `{rows.filter((r) => r.kids).map((r) => <li/>)}` arrived here
		 * instead, with the cursor parked on that `.map(` — the filtered rows
		 * were stringified into the page and the callback never ran.
		 */
		if ( is_array( $value ) && 1 === preg_match( '/^\.\s*map\s*\(/', substr( $this->src, $this->i, 16 ) ) ) {
			$html = $this->map_children( $value, $scope );
			$this->ws();
			while ( $this->peek() === ',' || $this->peek() === ')' ) {
				++$this->i;
				$this->ws();
			}
			if ( $this->peek() === '}' ) {
				++$this->i;
				return $html;
			}
			// Something else follows the map; keep what it rendered and let the
			// guard below close the brace and report the rest.
			$value = array( '__html' => $html );
		}

		/*
		 * The evaluator stopped short of the closing brace, so the rest of the
		 * expression is still under the cursor and parse_children() would take
		 * it for text — that is how one design shipped
		 * `MihailStoychev.map((w) => w[0]).join("").slice(0, 2)}` as a visible
		 * avatar label. Skip to the brace so nothing leaks, keep whatever
		 * partial value was reached, and report the expression: silently
		 * emitting or silently dropping it is what made this class of defect
		 * invisible for so long.
		 */
		$end = $this->match_pair( $this->src, $brace );
		$this->note_unevaluated(
			'child-expression',
			substr( $this->src, $brace, ( $end < 0 ? $this->len : $end + 1 ) - $brace )
		);
		$this->i = $end < 0 ? $this->len : $end + 1;

		return $this->stringify( $value );
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function parse_map( array $scope ): string {
		return $this->map_children( $this->parse_member_root( $scope ), $scope );
	}

	/**
	 * Render `.map(cb)` over an already-evaluated list, cursor on the `.`.
	 *
	 * Split out of parse_map() so a child expression that reached the list some
	 * other way can still render it — see parse_child_expr().
	 *
	 * @param array<string, mixed> $scope
	 */
	private function map_children( mixed $target, array $scope ): string {
		$this->ws();
		if ( $this->peek() !== '.' ) {
			/*
			 * parse_postfix() consumed the whole chain because the callback
			 * returns a value, not markup — `{words.map((w) => w[0]).join("")}`
			 * matches this method's entry regex but is text, not children. The
			 * value it produced is what the child position wanted.
			 */
			return $this->stringify( $target );
		}
		++$this->i;
		$this->ws();
		$word = $this->read_ident();
		if ( $word !== 'map' ) {
			return '';
		}
		$this->ws();
		if ( $this->peek() !== '(' ) {
			return '';
		}
		++$this->i;
		$this->ws();
		$params = $this->parse_arrow_params();
		$this->ws();
		if ( substr( $this->src, $this->i, 2 ) === '=>' ) {
			$this->i += 2;
		}
		$this->ws();

		$list = is_array( $target ) ? $target : array();
		$html = '';
		$index = 0;
		foreach ( $list as $item ) {
			$local = $scope;
			$this->bind_params( $local, $params, $item, $index );
			$saved_i = $this->i;
			$html   .= $this->parse_arrow_body( $local );
			if ( $index < count( $list ) - 1 ) {
				$this->i = $saved_i;
			}
			++$index;
		}
		if ( $list === array() ) {
			$this->parse_arrow_body( $scope );
		}
		$this->ws();
		// Trailing comma after the callback: .map((x) => (...),)
		if ( $this->peek() === ',' ) {
			++$this->i;
			$this->ws();
		}
		if ( $this->peek() === ')' ) {
			++$this->i;
		}

		return $html;
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function parse_arrow_body( array $scope ): string {
		$this->ws();
		if ( $this->peek() === '(' ) {
			++$this->i;
			$this->ws();
			$html = $this->peek() === '<' ? $this->parse_element( $scope ) : $this->stringify( $this->parse_expression( $scope ) );
			$this->ws();
			if ( $this->peek() === ')' ) {
				++$this->i;
			}
			return $html;
		}
		if ( $this->peek() === '<' ) {
			return $this->parse_element( $scope );
		}
		if ( $this->peek() === '{' ) {
			++$this->i;
			$local = $scope;
			$html  = '';
			$last  = -1;
			while ( $this->i < $this->len && $this->peek() !== '}' ) {
				// This body ends in a bare parse_expression() for statements
				// the compiler does not model; on one design that returned
				// without consuming and the import spun for 20 minutes.
				if ( ! $this->advanced( $last ) ) {
					break;
				}
				$this->ws();
				if ( $this->peek() === '}' ) {
					break;
				}
				if ( preg_match( '/^const\s+/', substr( $this->src, $this->i, 20 ) ) ) {
					$this->i += 6;
					$this->ws();
					/*
					 * A destructuring pattern in place of a name — `const [head,
					 * ...rest] = t.split(" ")` in ICP Segmentation's trust
					 * cells. read_ident() saw the `[` and returned nothing, the
					 * pattern was then parsed as an array literal, and the
					 * cursor stopped on the `=` after it: the statement loop
					 * could not advance and `= t.split(" "); return ( ); })}`
					 * was emitted as text on the page, with every cell empty.
					 */
					$pattern = '';
					$name    = '';
					if ( $this->peek() === '[' || $this->peek() === '{' ) {
						$start = $this->i;
						$this->skip_balanced();
						$pattern = substr( $this->src, $start, $this->i - $start );
					} else {
						$name = $this->read_ident();
					}
					// A declared type sits between the name and the `=`:
					// `const accent: Tone = "blue"`. Without skipping it the
					// cursor stopped on the colon, the statement loop could not
					// advance, and the raw source of the rest of the component
					// was emitted as paragraph text on the page.
					$this->skip_type_ann();
					$this->ws();
					if ( $this->peek() === '=' ) {
						++$this->i;
					}
					$value = $this->parse_expression( $local );
					if ( $pattern !== '' ) {
						$this->bind_pattern( $local, $pattern, $value );
						$this->last_enum_gate = null;
						$this->ws();
						if ( $this->peek() === ';' ) {
							++$this->i;
						}
						continue;
					}
					$local[ $name ] = $value;
					// Preserve enum gates so `isOpen && <Panel/>` / style ternaries
					// still know which accordion/menu key they belong to, while
					// keeping the boolean open/closed result for aria-expanded.
					if ( is_bool( $local[ $name ] ) ) {
						$gate = $this->last_enum_gate;
						if ( is_array( $gate ) ) {
							$local[ $name ] = array_merge(
								$gate,
								array(
									'__open' => $local[ $name ],
								)
							);
						}
					}
					$this->last_enum_gate = null;
					$this->ws();
					if ( $this->peek() === ';' ) {
						++$this->i;
					}
					continue;
				}
				if ( preg_match( '/^return\b/', substr( $this->src, $this->i, 8 ) ) ) {
					$this->i += 6;
					$this->ws();
					if ( $this->peek() === '(' ) {
						++$this->i;
						$this->ws();
						$html = $this->peek() === '<' ? $this->parse_element( $local ) : $this->stringify( $this->parse_expression( $local ) );
						$this->ws();
						if ( $this->peek() === ')' ) {
							++$this->i;
						}
					} elseif ( $this->peek() === '<' ) {
						$html = $this->parse_element( $local );
					} else {
						$html = $this->stringify( $this->parse_expression( $local ) );
					}
					$this->ws();
					if ( $this->peek() === ';' ) {
						++$this->i;
					}
					continue;
				}
				$this->parse_expression( $local );
				$this->ws();
				if ( $this->peek() === ';' ) {
					++$this->i;
				}
			}
			if ( $this->peek() === '}' ) {
				++$this->i;
			}
			return $html;
		}

		return $this->stringify( $this->parse_expression( $scope ) );
	}

	/**
	 * Bind the names of a destructuring pattern from a value.
	 *
	 * `[head, ...rest]` takes the value's items by position, the rest element
	 * everything after it; `{ a, b: alias }` goes through destructured_names()
	 * like a destructured parameter does.
	 *
	 * @param array<string, mixed> $local
	 */
	private function bind_pattern( array &$local, string $pattern, mixed $value ): void {
		$value = is_array( $value ) ? $value : array();
		if ( str_starts_with( $pattern, '{' ) ) {
			foreach ( self::destructured_names( $pattern ) as $key => $alias ) {
				$local[ $alias ] = $value[ $key ] ?? null;
			}
			return;
		}
		$items = array_values( $value );
		foreach ( explode( ',', trim( substr( $pattern, 1, -1 ) ) ) as $k => $name ) {
			// `[a = 1, b]` — the default is not part of the name.
			$name = trim( (string) preg_replace( '/=.*$/s', '', $name ) );
			if ( $name === '' || str_contains( $name, '[' ) || str_contains( $name, '{' ) ) {
				continue;
			}
			if ( str_starts_with( $name, '...' ) ) {
				$local[ substr( $name, 3 ) ] = array_slice( $items, $k );
				return;
			}
			$local[ $name ] = $items[ $k ] ?? null;
		}
	}

	/**
	 * Parameters of a callback arrow, one slot per positional parameter.
	 *
	 * `names` is indexed by position and may hold '' where that position is a
	 * destructuring pattern, whose shape is then in `patterns` under the same
	 * index. Keeping the empty slot is load-bearing: the old loop `continue`d
	 * on `{` without appending anything, so
	 * `socials.map(({ label, href, Icon }, i) => …)` put `i` in slot 0 and
	 * bind_params() assigned it the whole row — `data-i` then stringified to
	 * the row's key names, "label href tone". With no index parameter the same
	 * skip bound nothing at all: DevriX Elevate, Growth Story Hub and Brand
	 * Polish Pass each shipped four social-link anchors with no href, no
	 * aria-label, no title and no icon, from
	 * `{socials.map(({ label, href, Icon }) => …)}` in SiteFooter.tsx.
	 *
	 * @return array{names:array<int,string>, pattern:string, patterns:array<int, array{props:array<int, array<string, mixed>>, rest:string}>}
	 */
	private function parse_arrow_params(): array {
		$this->ws();
		if ( $this->peek() === '(' ) {
			++$this->i;
			$this->ws();
			if ( $this->peek() === '[' ) {
				++$this->i;
				$names = array();
				while ( $this->i < $this->len && $this->peek() !== ']' ) {
					// read_ident() returns '' without moving on anything that
					// is not an identifier character, so this needs the same
					// forward-progress guard as parse_args().
					$before = $this->i;

					$this->ws();
					$names[] = $this->read_ident();
					$this->ws();
					if ( $this->peek() === ',' ) {
						++$this->i;
					}

					if ( $this->i === $before ) {
						break;
					}
				}
				if ( $this->peek() === ']' ) {
					++$this->i;
				}
				$this->skip_to_paren_end();
				return array( 'names' => $names, 'pattern' => 'tuple', 'patterns' => array() );
			}
			$names    = array();
			$patterns = array();
			$slot     = 0;
			while ( $this->i < $this->len && $this->peek() !== ')' ) {
				// Forward progress or stop — see parse_args(). A parameter
				// default (`{ a, b = 2 }`) leaves the cursor on `=`, which
				// read_ident() will not consume.
				$before = $this->i;

				$this->ws();
				if ( $this->peek() === '{' || $this->peek() === '[' ) {
					$open = $this->i;
					$this->skip_balanced();
					$patterns[ $slot ] = $this->destructure_pattern(
						substr( $this->src, $open + 1, max( 0, $this->i - $open - 2 ) ),
						$this->src[ $open ] === '['
					);
					$names[ $slot ] = '';
					++$slot;
					$this->skip_type_ann();
					$this->ws();
					if ( $this->peek() === ',' ) {
						++$this->i;
					}
					if ( $this->i === $before ) {
						break;
					}
					continue;
				}
				$ident          = $this->read_ident();
				$names[ $slot ] = $ident;
				++$slot;
				$this->skip_type_ann();
				$this->ws();
				if ( $this->peek() === ',' ) {
					++$this->i;
				}

				if ( $this->i === $before ) {
					break;
				}
			}
			if ( $this->peek() === ')' ) {
				++$this->i;
			}
			return array( 'names' => $names, 'pattern' => 'list', 'patterns' => $patterns );
		}

		$ident = $this->read_ident();
		return array(
			'names'    => $ident !== '' ? array( $ident ) : array(),
			'pattern'  => 'list',
			'patterns' => array(),
		);
	}

	/**
	 * The shape of one destructuring pattern, from the source between its
	 * brackets.
	 *
	 * Handles what Lovable designs actually write: plain keys, a rename
	 * (`label: name`), a default (`href = "#"`), the two combined, a rest
	 * (`...rest`) and one nesting level (`meta: { note }`). An array pattern
	 * (`[k, v]`) binds by position instead of by key.
	 *
	 * @return array{props:array<int, array<string, mixed>>, rest:string}
	 */
	private function destructure_pattern( string $inner, bool $positional = false ): array {
		$props = array();
		$rest  = '';
		$at    = 0;
		foreach ( $this->split_params( $inner ) as $part ) {
			$part = trim( $part );
			if ( $part === '' ) {
				continue;
			}
			if ( str_starts_with( $part, '...' ) ) {
				$rest = trim( substr( $part, 3 ) );
				continue;
			}
			if ( $positional ) {
				$eq   = strpos( $part, '=' );
				$name = trim( false === $eq ? $part : substr( $part, 0, $eq ) );
				$name = trim( (string) preg_replace( '/:.*$/s', '', $name ) );
				if ( $name !== '' ) {
					$props[] = array(
						'key'     => (string) $at,
						'local'   => $name,
						'default' => false === $eq ? '' : trim( substr( $part, $eq + 1 ) ),
						'nested'  => null,
					);
				}
				++$at;
				continue;
			}
			if ( ! preg_match( '/^([A-Za-z_$][\w$]*)\s*(?::\s*([\s\S]+?))?\s*(?:=\s*([\s\S]+))?$/', $part, $m ) ) {
				continue;
			}
			$key    = $m[1];
			$target = trim( $m[2] ?? '' );
			$nested = null;
			$local  = $key;
			if ( $target !== '' && ( $target[0] === '{' || $target[0] === '[' ) ) {
				$nested = $this->destructure_pattern(
					substr( $target, 1, max( 0, strlen( $target ) - 2 ) ),
					$target[0] === '['
				);
			} elseif ( $target !== '' && preg_match( '/^[A-Za-z_$][\w$]*$/', $target ) === 1 ) {
				$local = $target;
			}
			$props[] = array(
				'key'     => $key,
				'local'   => $local,
				'default' => trim( $m[3] ?? '' ),
				'nested'  => $nested,
			);
		}

		return array(
			'props' => $props,
			'rest'  => $rest,
		);
	}

	/**
	 * Bind one destructuring pattern against the value in that slot.
	 *
	 * @param array<string, mixed>                                     $scope
	 * @param array{props:array<int, array<string, mixed>>, rest:string} $pattern
	 */
	private function bind_destructured( array &$scope, array $pattern, mixed $value ): void {
		$row   = is_array( $value ) ? $value : array();
		$taken = array();
		foreach ( $pattern['props'] as $prop ) {
			$key           = (string) $prop['key'];
			$taken[ $key ] = true;
			$got           = array_key_exists( $key, $row ) ? $row[ $key ] : null;
			if ( is_array( $prop['nested'] ?? null ) ) {
				$this->bind_destructured( $scope, $prop['nested'], $got );
				continue;
			}
			if ( null === $got && (string) $prop['default'] !== '' ) {
				$got = $this->eval_source( (string) $prop['default'], $scope );
			}
			$scope[ (string) $prop['local'] ] = $got;
		}
		if ( $pattern['rest'] === '' ) {
			return;
		}
		$leftover = array();
		foreach ( $row as $key => $item ) {
			if ( ! isset( $taken[ (string) $key ] ) ) {
				$leftover[ $key ] = $item;
			}
		}
		$scope[ $pattern['rest'] ] = $leftover;
	}

	private function skip_to_paren_end(): void {
		$depth = 1;
		while ( $this->i < $this->len && $depth > 0 ) {
			$ch = $this->peek();
			if ( $ch === '(' ) {
				++$depth;
			} elseif ( $ch === ')' ) {
				--$depth;
				if ( $depth === 0 ) {
					++$this->i;
					return;
				}
			}
			++$this->i;
		}
	}

	/**
	 * @param array<string, mixed> $scope
	 * @param array{names:array<int,string>, pattern:string, patterns?:array<int, array{props:array<int, array<string, mixed>>, rest:string}>} $params
	 */
	private function bind_params( array &$scope, array $params, mixed $item, int $index ): void {
		$names = $params['names'];
		if ( ( $params['pattern'] ?? '' ) === 'tuple' && is_array( $item ) ) {
			foreach ( $names as $i => $name ) {
				if ( $name !== '' ) {
					$scope[ $name ] = $item[ $i ] ?? null;
				}
			}
			return;
		}
		if ( isset( $names[0] ) && $names[0] !== '' ) {
			$scope[ $names[0] ] = $item;
		}
		if ( isset( $names[1] ) && $names[1] !== '' ) {
			$scope[ $names[1] ] = $index;
		}
		// Slots 0 and 1 are the row and its index in every list callback the
		// compiler evaluates, so a pattern in either slot destructures the
		// value that slot's name would have received.
		foreach ( $params['patterns'] ?? array() as $slot => $pattern ) {
			$this->bind_destructured( $scope, $pattern, 1 === (int) $slot ? $index : $item );
		}
	}

	/**
	 * @param array<string, mixed> $scope
	 * @return array<string, mixed>
	 */
	private function parse_attrs( array $scope ): array {
		$attrs = array();
		$last  = -1;
		while ( $this->i < $this->len ) {
			if ( ! $this->advanced( $last ) ) {
				break;
			}
			$this->ws();
			$ch = $this->peek();
			if ( $ch === '/' || $ch === '>' || $ch === '' ) {
				break;
			}
			if ( $ch === '{' ) {
				// `{...props}` carries the whole row of a mapped list. Skipping
				// it renders the component with no props at all, which is how
				// every submenu row came out blank.
				$from = $this->i;
				++$this->i;
				$this->ws();
				if ( substr( $this->src, $this->i, 3 ) === '...' ) {
					$this->i += 3;
					$this->ws();
					$spread = $this->parse_expression( $scope );
					$this->ws();
					if ( $this->peek() === '}' ) {
						++$this->i;
					}
					if ( is_array( $spread ) ) {
						foreach ( $spread as $key => $val ) {
							// Later attributes still win, matching JSX.
							if ( is_string( $key ) ) {
								$attrs[ $key ] = $val;
							}
						}
					}
					continue;
				}
				$this->i = $from;
				$this->skip_balanced();
				continue;
			}
			$name = $this->read_attr_name();
			if ( $name === '' ) {
				++$this->i;
				continue;
			}
			$this->ws();
			$value = true;
			if ( $this->peek() === '=' ) {
				++$this->i;
				$this->ws();
				if ( $this->peek() === '"' || $this->peek() === "'" ) {
					$value = $this->read_quoted();
				} elseif ( $this->peek() === '{' ) {
					$slice = substr( $this->src, $this->i, 48 );
					/*
					 * A RENDER PROP is an arrow too, and it is not a handler.
					 *
					 * This branch claims any attribute whose value opens with
					 * `(…) =>` and throws the value away, which is right for
					 * `onClick` and wrong for `render`: it is how
					 * `<FormField render={({ field }) => …} />` — the entire
					 * body of every shadcn form field — was discarded before
					 * anything could call it. Named props only, so no other
					 * arrow-valued attribute changes behaviour.
					 */
					$is_render = in_array( $name, self::RENDER_PROPS, true );
					if (
						! $is_render && (
							str_starts_with( strtolower( $name ), 'on' )
							|| (bool) preg_match( '/^\{\s*(?:async\s*)?(?:\([^)]*\)|[A-Za-z_][\w]*)\s*=>/', $slice )
						)
					) {
						$from = $this->i;
						$this->skip_balanced();
						$body = substr( $this->src, $from, $this->i - $from );
						$toggle = $this->toggle_from_handler( $body, $scope );
						if ( $toggle !== '' ) {
							$attrs['__toggle'] = $toggle;
							if ( $this->toggle_meta !== array() ) {
								$attrs['__meta'] = $this->toggle_meta;
							}
							$lname = strtolower( $name );
							if ( in_array( $lname, array( 'onmouseenter', 'onmouseover', 'onpointerenter' ), true ) ) {
								$attrs['__hover'] = 'enter';
							} elseif ( in_array( $lname, array( 'onmouseleave', 'onpointerleave' ), true ) ) {
								$attrs['__hover'] = 'leave';
							}
						}
						$value = false;
					} else {
						$brace = $this->i;
						++$this->i;
						$this->ws();
						if ( $name === 'style' && $this->peek() === '{' ) {
							$was_style           = $this->in_style_expr;
							$outer_style_gates   = $this->style_gates;
							$this->in_style_expr = true;
							$this->style_gates   = array();

							$value = $this->style_from_object( $this->parse_expression( $scope ) );

							if ( $this->style_gates !== array() ) {
								$attrs['__style_gate'] = $this->style_gates;
							}
							$this->in_style_expr = $was_style;
							$this->style_gates   = $outer_style_gates;
						} elseif ( $name === 'className' || $name === 'class' ) {
							/*
							 * Flattened here so a class map or array reaches
							 * emit_element() as one string — but not when the
							 * expression yielded nothing. `undefined` and a
							 * false `&&` arm are how JSX says "no attribute",
							 * and stringifying them to '' defeated the
							 * null/false skip in html_attrs(): DevriX Elevate
							 * shipped 26 `class=""` elements from
							 * `className={hot && amount > 0.6 ? "text-gold" :
							 * undefined}` in Positioning.tsx and from
							 * `<span className={className}>` in
							 * OpportunityStack's WordReveal, whose caller
							 * passes no className at all. An empty *string*
							 * stays a string — `cn()` with every arm falsy
							 * renders `class=""` in React too.
							 */
							/*
							 * Only inside this expression does the gate peek
							 * run, and only here is the losing arm worth
							 * keeping. Saved and restored rather than simply
							 * cleared: a class expression can call a helper
							 * whose own body parses further attributes.
							 */
							$was_class       = $this->in_class_expr;
							$outer_gates     = $this->cls_gates;
							$this->in_class_expr = true;
							$this->cls_gates     = array();

							$value = $this->parse_expression( $scope );

							if ( $this->cls_gates !== array() ) {
								$attrs['__cls'] = $this->cls_gates;
							}
							$this->in_class_expr = $was_class;
							$this->cls_gates     = $outer_gates;

							if ( null !== $value && false !== $value ) {
								$value = $this->stringify_attr( $value );
							}
						} else {
							/*
							 * A RENDER PROP: `render={({ field }) => (…)}`.
							 *
							 * The expression evaluator has no arrow form, so
							 * this used to be parsed as an expression, fail,
							 * and be reported as attr-expression:render — and
							 * a `<FormField render={…}/>` therefore rendered
							 * NOTHING. Four of them are the whole body of a
							 * shadcn form: measured against the design's own
							 * React build, four labels, three inputs, a
							 * textarea and two descriptions were missing while
							 * the `<form>` and its submit button survived.
							 *
							 * Captured as a callable instead, so the component
							 * that owns the prop can invoke it — see the
							 * Controller branch in emit_element().
							 */
							$close = $this->match_pair( $this->src, $brace );
							$raw   = $close < 0 ? '' : trim( substr( $this->src, $brace + 1, $close - $brace - 1 ) );
							if ( $raw !== '' && $this->is_arrow_source( $raw ) ) {
								$value = $this->make_fn( $this->arrow_extent( $raw ), $scope );
								// Left ON the closing brace, which the shared
								// check below consumes.
								$this->i = $close;
							} else {
								$value = $this->parse_expression( $scope );
							}
						}
						$this->ws();
						if ( $this->peek() === '}' ) {
							++$this->i;
						} else {
							/*
							 * Same guard as parse_child_expr(), for the same
							 * reason: the evaluator stopped short of the
							 * closing brace, and the loop would go on to read
							 * the rest of the expression as further attribute
							 * names — `href={a ?? b}` could contribute a
							 * literal `b` attribute. Skip to the brace and
							 * report, rather than inventing markup.
							 */
							$end = $this->match_pair( $this->src, $brace );
							$this->note_unevaluated(
								'attr-expression:' . $name,
								substr( $this->src, $brace, ( $end < 0 ? $this->len : $end + 1 ) - $brace )
							);
							$this->i = $end < 0 ? $this->len : $end + 1;
						}
					}
				}
			}
			$attrs[ $name ] = $value;
		}

		return $attrs;
	}

	private function read_attr_name(): string {
		$start = $this->i;
		while ( $this->i < $this->len && preg_match( '/[A-Za-z0-9_:-]/', $this->src[ $this->i ] ) ) {
			++$this->i;
		}
		return substr( $this->src, $start, $this->i - $start );
	}

	/**
	 * The same code with every string literal blanked out.
	 *
	 * "Is this identifier referenced?" is a question about code, and a class
	 * string answers it wrongly: `\bsolid\b` matches the `solid` inside
	 * `"border-solid"`, so a state nothing reads looked read. Quotes are
	 * replaced rather than removed so offsets and line structure survive for
	 * anything measuring them.
	 */
	private static function without_strings( string $code ): string {
		/*
		 * A template literal first, and only its text: the `${…}` parts are
		 * expressions, and blanking them with the rest hid the only read of a
		 * state on two designs. One level of nested braces is kept, which is
		 * enough for a ternary whose arms are objects or nested templates.
		 */
		$code = (string) preg_replace_callback(
			'/`(?:[^`\\\\]|\\\\.)*`/',
			static function ( array $hit ): string {
				preg_match_all( '/\\$\\{([^{}]*(?:\\{[^{}]*\\}[^{}]*)*)\\}/', $hit[0], $parts );

				return ' ' . implode( ' ', $parts[1] ?? array() ) . ' ';
			},
			$code
		);

		return (string) preg_replace(
			array(
				'/"(?:[^"\\\\\n]|\\\\.)*"/',
				"/'(?:[^'\\\\\n]|\\\\.)*'/",
			),
			'""',
			$code
		);
	}

	/**
	 * A JavaScript string literal's escape sequences, as characters.
	 *
	 * `"\u00A0"` is a non-breaking space, not six characters — and the six
	 * characters are what shipped, once per word, in every design whose text
	 * uses the escape. Covers the numeric forms (`\uXXXX`, `\u{…}`,
	 * `\xNN`), the control shorthands, a quote or backslash protected by one,
	 * and a backslash before a newline, which is a line continuation and
	 * contributes nothing.
	 *
	 * An unknown escape keeps its character, which is what JavaScript does:
	 * `\q` is `q`.
	 */
	private static function decode_js_escapes( string $value ): string {
		if ( ! str_contains( $value, '\\' ) ) {
			return $value;
		}

		$out    = '';
		$length = strlen( $value );
		for ( $i = 0; $i < $length; $i++ ) {
			if ( $value[ $i ] !== '\\' || $i + 1 >= $length ) {
				$out .= $value[ $i ];
				continue;
			}
			$next = $value[ ++$i ];
			switch ( $next ) {
				case 'n':
					$out .= "\n";
					break;
				case 'r':
					$out .= "\r";
					break;
				case 't':
					$out .= "\t";
					break;
				case 'b':
					$out .= chr( 8 );
					break;
				case 'f':
					$out .= chr( 12 );
					break;
				case 'v':
					$out .= chr( 11 );
					break;
				case '0':
					$out .= chr( 0 );
					break;
				case "\n":
				case "\r":
					// A line continuation: the newline is not part of the value.
					break;
				case 'x':
					if ( preg_match( '/^[0-9a-fA-F]{2}/', substr( $value, $i + 1, 2 ), $hit ) === 1 ) {
						$out .= self::code_point( (int) hexdec( $hit[0] ) );
						$i   += 2;
						break;
					}
					$out .= $next;
					break;
				case 'u':
					if ( ( $value[ $i + 1 ] ?? '' ) === '{' ) {
						$close = strpos( $value, '}', $i + 2 );
						$inner = $close === false ? '' : substr( $value, $i + 2, $close - $i - 2 );
						if ( $inner !== '' && preg_match( '/^[0-9a-fA-F]{1,6}$/', $inner ) === 1 ) {
							$out .= self::code_point( (int) hexdec( $inner ) );
							$i    = (int) $close;
							break;
						}
						$out .= $next;
						break;
					}
					if ( preg_match( '/^[0-9a-fA-F]{4}/', substr( $value, $i + 1, 4 ), $hit ) === 1 ) {
						$out .= self::code_point( (int) hexdec( $hit[0] ) );
						$i   += 4;
						break;
					}
					$out .= $next;
					break;
				default:
					$out .= $next;
			}
		}

		return $out;
	}

	/** One code point as UTF-8, without depending on mbstring. */
	private static function code_point( int $code ): string {
		$char = mb_chr( $code, 'UTF-8' );

		return is_string( $char ) ? $char : (string) html_entity_decode( '&#' . $code . ';', ENT_QUOTES, 'UTF-8' );
	}

	private function read_quoted(): string {
		$q = $this->peek();
		++$this->i;
		$start = $this->i;
		while ( $this->i < $this->len && $this->src[ $this->i ] !== $q ) {
			if ( $this->src[ $this->i ] === '\\' ) {
				$this->i += 2;
				continue;
			}
			++$this->i;
		}
		$val = substr( $this->src, $start, $this->i - $start );
		if ( $this->peek() === $q ) {
			++$this->i;
		}
		return $val;
	}

	/**
	 * @param array<string, mixed> $attrs
	 * @param array<string, mixed> $scope
	 */
	private function emit_element( string $name, array $attrs, string $children, array $scope ): string {
		/*
		 * `children` arriving through a rest spread rather than as JSX children.
		 *
		 * React keeps children in props, so
		 * `({ className, ...props }) => <div {...props} />` renders whatever the
		 * caller nested — and that is how most of the shadcn kit passes them:
		 * Card, CardHeader, CardTitle, CardContent and Button all take children
		 * only through `{...props}`.
		 *
		 * The JSX spread merges every key into the attribute list, where
		 * nothing consumed `children`, so once those components started
		 * rendering at all they rendered structurally perfect and completely
		 * EMPTY — every heading and button label gone. Losing text is worse
		 * than the span soup it replaced, so this is part of the same fix.
		 *
		 * Unset unconditionally: `children` must never reach the markup as an
		 * attribute, and a component re-receives it through props_from_attrs()
		 * plus the `$props['children']` assignment further down.
		 */
		if ( isset( $attrs['children'] ) ) {
			if ( $children === '' ) {
				$children = $this->markup_html( $attrs['children'] );
			}
			unset( $attrs['children'] );
		}

		if ( $name === '' || $name === 'Fragment' || $name === 'React.Fragment' ) {
			return $children;
		}

		if ( str_starts_with( $name, 'motion.' ) || str_starts_with( $name, 'Motion.' ) ) {
			$name = substr( $name, (int) strpos( $name, '.' ) + 1 );
		}

		/*
		 * A member expression used as a tag: `<g.icon className="h-3.5 w-3.5" />`
		 * where `g` is a row from a data array and `icon` holds a component.
		 * ARA's guide cards are built that way —
		 * `{ icon: Car, label: "Types of Accidents" }` — and the tag name was
		 * being passed through to the emitter verbatim, so the page carried
		 * three `<g.icon class="…">` elements: not a valid tag, so a browser
		 * parses it as an unknown element with no box and the card's icon is
		 * simply gone.
		 *
		 * resolve_ident() already turns a lucide import into an ICON marker, and
		 * the branch further down already renders `<Icon />` for a BARE name
		 * bound that way. Only the dotted form was missing — and it slipped past
		 * that branch because is_unresolved_component() is false for a lowercase
		 * head like `g`, so it fell through to the plain-tag path.
		 *
		 * `motion.div` and `React.Fragment` are handled above and never reach
		 * here.
		 */
		if ( str_contains( $name, '.' ) ) {
			$parts   = explode( '.', $name );
			$head    = array_shift( $parts );
			$value   = $scope[ $head ] ?? $this->globals[ $head ] ?? null;
			foreach ( $parts as $part ) {
				$value = $value === null ? null : $this->member( $value, $part );
			}

			if ( is_array( $value ) && isset( $value[ self::ICON ] ) ) {
				return $this->lucide_icon( $name, $attrs, (string) $value[ self::ICON ] );
			}
			if ( is_string( $value ) && $value !== '' && isset( $this->components[ $value ] ) ) {
				return $this->emit_element( $value, $attrs, $children, $scope );
			}
			if ( is_string( $value ) && $value !== '' && isset( $this->lucide[ $value ] ) ) {
				return $this->lucide_icon( $value, $attrs );
			}

			/*
			 * A Radix primitive: `<AccordionPrimitive.Trigger>`. Its source is
			 * in node_modules and a ZIP does not carry node_modules, so there
			 * is nothing to resolve it to — but what each part renders is
			 * fixed, and a table of that beats a `<span>` by the whole
			 * component. See Radix_Primitives.
			 */
			$package = $this->radix[ $head ] ?? '';
			if ( $package !== '' && count( $parts ) === 1 ) {
				$part      = (string) $parts[0];
				$primitive = Radix_Primitives::element( $package, $part );
				if ( $primitive !== null ) {
					if ( $primitive['tag'] === '' ) {
						// A provider or a portal: no element of its own.
						return $children;
					}

					$children = $this->radix_children( $package, $part, $attrs, $children );

					/*
					 * `asChild` is Radix's Slot in prop form: render the CHILD
					 * with this part's props merged in, not a wrapper around
					 * it. `<SelectPrimitive.Icon asChild><ChevronDown/></…>`
					 * is a chevron, not a span containing one — and the extra
					 * span put the icon at a different position in the tree,
					 * so it read as both a lost and a new node.
					 */
					if ( ! empty( $attrs['asChild'] ) ) {
						return self::merge_into_root(
							$children,
							$this->html_attrs(
								array_merge(
									Radix_Primitives::strip_props( $attrs, '' ),
									$this->radix_state_attrs( $package, $part, $attrs )
								),
								''
							)
						);
					}

					$html = $this->emit_element(
						$primitive['tag'],
						array_merge(
							Radix_Primitives::strip_props( $attrs, $primitive['tag'] ),
							// The ARIA and data-state the kit's own
							// `data-[state=…]:` classes are keyed on.
							$this->radix_state_attrs( $package, $part, $attrs )
						),
						$children,
						$scope
					);

					return $primitive['hidden'] ? self::hide_element( $html ) : $html;
				}
			}

			/*
			 * A React context provider or consumer: `<FormFieldContext.Provider
			 * value={…}>`. It renders no element of its own, ever — that is
			 * what a context boundary IS — so the children stand in for it.
			 * shadcn's form.tsx wraps every field in one, and a placeholder
			 * `<span>` there put an inline element around a block form row.
			 */
			$tail = (string) end( $parts );
			if ( $tail === 'Provider' || $tail === 'Consumer' ) {
				return $children;
			}

			/*
			 * Unresolvable — a compound component like `<Accordion.Item />`
			 * lands here. Report it and emit the placeholder the rest of the
			 * unresolved-component path uses, because the one thing that must
			 * not happen is writing a dotted name into the markup as if it were
			 * a tag.
			 */
			$this->note_unevaluated( 'member-tag', '<' . $name . ' />' );

			return $this->placeholder( $attrs, $children );
		}

		/*
		 * Next's `<Image>` and a router's `<Link>` only when nothing else by
		 * that name exists. lucide exports an `Image` and a `Link` icon too,
		 * and taking either for the element turned the icon into an empty
		 * `<img src="">` or an `<a href="#">`.
		 */
		if ( ( $name === 'Image' || $name === 'NextImage' ) && ! isset( $this->components[ $name ] ) && ! isset( $this->lucide[ $name ] ) ) {
			return $this->emit_image( $attrs );
		}
		if ( $name === 'Link' && ! isset( $this->components['Link'] ) && ! isset( $this->lucide['Link'] ) ) {
			$attrs['href'] = $attrs['href'] ?? $attrs['to'] ?? '#';
			$name          = 'a';
			// React Router's own prop: its <Link> renders only href, so a
			// leftover `to` is markup the real build never had — and KSES
			// strips it from <a>, which made the block invalid on a
			// restricted save.
			unset( $attrs['to'] );
		}

		$asset_src = $this->asset_component_src( $name );
		if ( $asset_src !== '' ) {
			$attrs['src'] = $asset_src;
			return $this->emit_image( $attrs );
		}

		/*
		 * `<Tag>` where Tag is a local holding a tag name. A polymorphic
		 * wrapper takes it as a prop — `function Reveal({ as: Tag = "div" })`
		 * — and without this the capitalised name looked like an unresolved
		 * component and became a placeholder `<span>`, whatever the design
		 * asked for. Restricted to real element names so a global that merely
		 * holds a lowercase string cannot be mistaken for one.
		 */
		if ( ! isset( $this->components[ $name ] ) && preg_match( '/^[A-Z]/', $name ) === 1 ) {
			$bound = $scope[ $name ] ?? null;
			if ( is_string( $bound ) && in_array( strtolower( $bound ), self::POLYMORPHIC_TAGS, true ) ) {
				$name = strtolower( $bound );
			}
		}

		$lower = strtolower( $name );
		/*
		 * The design's own `Label` wins.
		 *
		 * This interception used to be unconditional, and `builtin_label()`
		 * takes no attributes at all — so every shadcn `<Label htmlFor=…>`
		 * became `<span class="rv-label">…</span>`: wrong element, no `for`,
		 * and the kit's own `text-sm font-medium leading-none` replaced by a
		 * decorative rule this design never asked for. Measured on a form:
		 * four labels, none of them a `<label>`. The builtin stays for the
		 * designs that write `<Label>` with no such component in the ZIP,
		 * which is what it was written for.
		 */
		if ( $name === 'Label' && ! isset( $this->components['Label'] ) && ! isset( $this->radix_alias['Label'] ) ) {
			return $this->builtin_label( $children );
		}
		if ( $name === 'Section' && $this->builtin_section_fits() ) {
			return $this->builtin_section( $attrs, $children );
		}
		/*
		 * A counter that animates keeps its number in state this compile never
		 * advances, so its own body renders the starting 0 — RevOps' CountUp
		 * came out as `<span>0</span>`. The builtin hands the count to
		 * Motion_Runtime, so it stands in for any of those; a CountUp that
		 * just prints its value is the design's own.
		 */
		if ( $name === 'CountUp' && ( ! isset( $this->components['CountUp'] ) || str_contains( $this->components['CountUp'], 'useState' ) ) ) {
			return $this->builtin_countup( $attrs );
		}

		if ( isset( $this->components[ $name ] ) && ! in_array( $lower, array( 'div', 'span', 'p' ), true ) ) {
			$props = $this->props_from_attrs( $attrs );
			$props['children'] = $children === '' ? '' : array( '__html' => $children );
			$html  = $this->mark_component( $this->render_named( $name, $props ), $name );
			if ( isset( $attrs['__toggle'] ) && is_string( $attrs['__toggle'] ) && $attrs['__toggle'] !== '' ) {
				$html = self::add_class( $html, 'dxai-toggle-' . sanitize_html_class( $attrs['__toggle'] ) );
			}
			/*
			 * Portal content is not inline content. Keep the markup — the
			 * editor should still hold a Dialog's body, and a projected control
			 * can reveal it — but it must not paint. See $portalled.
			 */
			if ( isset( $this->portalled[ $name ] ) ) {
				$html = self::hide_element( $html );
			}

			return $html;
		}

		if ( $this->is_unresolved_component( $name ) ) {
			if ( isset( $this->lucide[ $name ] ) ) {
				return $this->lucide_icon( $name, $attrs );
			}
			// `<Icon />` where Icon came out of a data row — see resolve_ident().
			$bound = $scope[ $name ] ?? $this->globals[ $name ] ?? null;
			if ( is_array( $bound ) && isset( $bound[ self::ICON ] ) ) {
				return $this->lucide_icon( $name, $attrs, (string) $bound[ self::ICON ] );
			}

			/*
			 * Radix's `Slot` renders NO element: it merges its own props into
			 * its single child. `FormControl` is a Slot carrying the field's
			 * `id`, `aria-describedby` and `aria-invalid`, so wrapping instead
			 * of merging put a `<span>` around every input — an inline element
			 * around a block control — and left the attributes on the wrapper.
			 *
			 * The child wins every conflict, which is Radix's own rule:
			 * mergeProps returns `{...slotProps, ...childProps}`, so an `id`
			 * the child already has is the one that survives.
			 */
			if ( $name === 'Slot' || $name === 'Slottable' ) {
				return self::merge_into_root( $children, $this->html_attrs( $attrs, '' ) );
			}

			/*
			 * A RENDER PROP on a component whose source the ZIP does not carry.
			 * `<Controller name="email" render={({ field }) => (…)} />` is the
			 * whole body of every shadcn form field, and react-hook-form is
			 * not in the archive — so the only way to see that subtree is to
			 * call the function ourselves.
			 *
			 * The argument is the shape react-hook-form passes, filled in as
			 * far as a static conversion honestly can: `field.name` comes from
			 * the element's own `name` prop, and the form is at rest, so
			 * fieldState carries no error and formState no errors — which is
			 * what makes FormMessage render nothing, exactly as it does in the
			 * real app. `field.value` is NOT modelled: it lives in the form's
			 * runtime state, and inventing one would put a value attribute on
			 * the page that the design may not have. That gap is reported.
			 */
			$render = $attrs['render'] ?? null;
			if ( is_array( $render ) && ! empty( $render['__fn'] ) ) {
				$field_name = $this->stringify_attr( $attrs['name'] ?? '' );
				$this->note_unevaluated( 'render-prop-state', '<' . $name . ' name="' . $field_name . '" />' );

				return $this->markup_html(
					$this->call_value(
						$render,
						array(
							array(
								'field'      => array( 'name' => $field_name ),
								'fieldState' => array( 'error' => null, 'invalid' => false, 'isDirty' => false, 'isTouched' => false ),
								'formState'  => array( 'errors' => array(), 'isSubmitting' => false, 'isValid' => false ),
							),
						),
						$scope
					)
				);
			}

			/*
			 * A provider from a package whose source the ZIP does not carry —
			 * `<Form {...form}>` where `const Form = FormProvider` from
			 * react-hook-form, `<QueryClientProvider>`, `<HelmetProvider>`.
			 * React's convention is load-bearing here: a Provider renders its
			 * children and nothing else, so a placeholder element is always
			 * wrong, and for `Form` it put a `<span>` around the whole form.
			 */
			// Through a re-export, so `const Form = FormProvider` counts.
			$external = $this->external_alias[ $name ] ?? $name;
			if ( str_ends_with( $external, 'Provider' ) ) {
				return $children;
			}

			// A re-exported Radix primitive — see bind_radix_aliases().
			if ( isset( $this->radix_alias[ $name ] ) ) {
				[ $package, $part ] = $this->radix_alias[ $name ];
				$primitive          = Radix_Primitives::element( $package, $part );
				if ( $primitive !== null ) {
					if ( $primitive['tag'] === '' ) {
						return $children;
					}
					/*
					 * The same two rules as the member-expression path above.
					 * They belong on both because the kit re-exports some
					 * parts and wraps others: `<SelectValue/>` is an alias and
					 * reaches here, `<SelectPrimitive.Icon asChild>` is written
					 * out and reaches the other. Adding them to one only left
					 * the select trigger with an empty span where the design
					 * shows the selected entity.
					 */
					$children = $this->radix_children( $package, $part, $attrs, $children );
					if ( ! empty( $attrs['asChild'] ) ) {
						return self::merge_into_root(
							$children,
							$this->html_attrs(
								array_merge(
									Radix_Primitives::strip_props( $attrs, '' ),
									$this->radix_state_attrs( $package, $part, $attrs )
								),
								''
							)
						);
					}
					$html = $this->emit_element(
						$primitive['tag'],
						array_merge(
							Radix_Primitives::strip_props( $attrs, $primitive['tag'] ),
							// The ARIA and data-state the kit's own
							// `data-[state=…]:` classes are keyed on.
							$this->radix_state_attrs( $package, $part, $attrs )
						),
						$children,
						$scope
					);

					return $primitive['hidden'] ? self::hide_element( $html ) : $html;
				}
			}

			return $this->placeholder( $attrs, $children );
		}

		$tag = strtolower( $name );
		if ( $tag === 'classname' ) {
			$tag = 'div';
		}

		$class = $this->stringify_attr( $attrs['className'] ?? $attrs['class'] ?? '' );
		if ( isset( $attrs['__toggle'] ) && is_string( $attrs['__toggle'] ) && $attrs['__toggle'] !== '' ) {
			$state = $attrs['__toggle'];
			$class = trim( $class . ' dxai-toggle-' . sanitize_html_class( $state ) );
			$attrs['className'] = $class;
			if ( ! isset( $attrs['aria-expanded'] ) && ! isset( $attrs['ariaExpanded'] ) ) {
				$attrs['aria-expanded'] = $this->toggle_is_expanded( $state, $scope ) ? 'true' : 'false';
			}
			if ( isset( $attrs['__hover'] ) ) {
				$attrs['data-dxai-hover'] = (string) $attrs['__hover'];
				unset( $attrs['__hover'] );
			} elseif ( str_contains( $state, 'open--' ) || preg_match( '/^open--/', $state ) === 1 ) {
				// Mega-menu triggers: hover to open like the React source.
				$attrs['data-dxai-hover'] = 'enter';
			}
			foreach ( is_array( $attrs['__meta'] ?? null ) ? $attrs['__meta'] : array() as $key => $meta ) {
				$attrs[ 'data-dxai-' . $key ] = (string) $meta;
			}
			unset( $attrs['__toggle'] );
		}
		unset( $attrs['__meta'] );
		[ $class, $attrs ] = $this->attach_class_projection( $class, $attrs );
		// Accordion panels gated by `open ? "1fr" : "0fr"` need dxai-on-* hooks.
		$attrs = $this->attach_accordion_panel( $attrs, $scope );
		if ( str_contains( $class, 'rv-hero-tab' ) ) {
			foreach ( array_reverse( $scope, true ) as $value ) {
				if ( is_array( $value ) && isset( $value['leak'], $value['fix'] ) ) {
					$attrs['data-leak'] = (string) $value['leak'];
					$attrs['data-fix']  = (string) $value['fix'];
					break;
				}
			}
		}
		if ( str_contains( $class, 'rv-mbp' ) && ! str_contains( $class, 'rv-mbp-' ) ) {
			$attrs['data-dxai-method'] = '1';
			// Carried as a class too, so the hook survives block conversion.
			$attrs['className'] = trim( $class . ' dxai-method' );
		}

		$html_attrs = $this->html_attrs( $attrs, $tag );
		if ( Design_Html::is_void( $tag ) ) {
			return '<' . $tag . $html_attrs . ' />';
		}

		return '<' . $tag . $html_attrs . '>' . $children . '</' . $tag . '>';
	}

	/**
	 * A capitalised tag with no definition in the ZIP — an icon or UI-kit
	 * import. Emitting it verbatim would produce an invalid element such as
	 * `<phone>`, so it becomes a sized placeholder instead.
	 */
	private function is_unresolved_component( string $name ): bool {
		if ( 1 !== preg_match( '/^[A-Z]/', $name ) ) {
			return false;
		}

		return in_array( $name, $this->external, true ) || ! isset( $this->components[ $name ] );
	}

	/**
	 * @param array<string, mixed> $attrs
	 */
	private function lucide_icon( string $name, array $attrs, string $icon = '' ): string {
		$icon  = $icon !== '' ? $icon : ( $this->lucide[ $name ] ?? Tsx_Section_Splitter::icon_id( $name ) );
		$class = trim( $this->stringify_attr( $attrs['className'] ?? $attrs['class'] ?? '' ) );
		$out   = '<i data-lucide="' . esc_attr( $icon ) . '"';
		if ( $class !== '' ) {
			$out .= ' class="' . esc_attr( $class ) . '"';
		}
		if ( isset( $attrs['size'] ) && ! isset( $attrs['width'] ) && ! isset( $attrs['height'] ) ) {
			$size = $this->stringify_attr( $attrs['size'] );
			$out .= ' width="' . esc_attr( $size ) . '" height="' . esc_attr( $size ) . '"';
		}
		foreach ( array( 'width', 'height', 'strokeWidth', 'stroke-width' ) as $prop ) {
			if ( ! isset( $attrs[ $prop ] ) ) {
				continue;
			}
			$attr = $prop === 'strokeWidth' ? 'stroke-width' : $prop;
			$out .= ' ' . $attr . '="' . esc_attr( $this->stringify_attr( $attrs[ $prop ] ) ) . '"';
		}
		if ( isset( $attrs['style'] ) ) {
			$style = is_array( $attrs['style'] ) ? $this->style_from_object( $attrs['style'] ) : $this->stringify_attr( $attrs['style'] );
			if ( $style !== '' ) {
				$out .= ' style="' . esc_attr( $style ) . '"';
			}
		}

		return $out . ' aria-hidden="true"></i>';
	}

	/**
	 * `import Logo from "./logo.svg"` used as `<Logo />`.
	 */
	private function asset_component_src( string $name ): string {
		if ( ! isset( $this->globals[ $name ] ) ) {
			return '';
		}
		$value = $this->globals[ $name ];
		if ( is_array( $value ) && isset( $value['url'] ) ) {
			$value = $value['url'];
		}
		if ( ! is_string( $value ) || $value === '' ) {
			return '';
		}
		if ( 1 !== preg_match( '#^(?:https?://|//|/|data:)#i', $value ) && ! isset( $this->images[ $value ] ) ) {
			return '';
		}

		return $this->resolve_src( $value );
	}

	/**
	 * @param array<string, mixed> $attrs
	 */
	private function emit_image( array $attrs ): string {
		$src   = $this->resolve_src( $attrs['src'] ?? '' );
		$alt   = $this->stringify_attr( $attrs['alt'] ?? '' );
		$class = trim( $this->stringify_attr( $attrs['className'] ?? $attrs['class'] ?? '' ) );
		if ( $this->truthy( $attrs['fill'] ?? false ) ) {
			$class = trim( $class . ' absolute inset-0 h-full w-full object-cover' );
		}
		$out = '<img src="' . esc_attr( $src ) . '" alt="' . esc_attr( $alt ) . '"';
		if ( $class !== '' ) {
			$out .= ' class="' . esc_attr( $class ) . '"';
		}
		foreach ( array( 'width', 'height', 'sizes', 'srcSet', 'srcset', 'loading', 'decoding' ) as $prop ) {
			if ( ! isset( $attrs[ $prop ] ) || $attrs[ $prop ] === false || $attrs[ $prop ] === null ) {
				continue;
			}
			$out .= ' ' . strtolower( $prop ) . '="' . esc_attr( $this->stringify_attr( $attrs[ $prop ] ) ) . '"';
		}

		return $out . ' />';
	}

	private function peek_toggle_state(): string {
		$saved = $this->i;
		$this->ws();
		$name = $this->read_ident();
		$this->i = $saved;
		if ( $name !== '' && isset( $this->toggles[ $name ] ) ) {
			return $name;
		}

		return '';
	}

	/**
	 * Detect `open === "practice"` / `open === it.key` gates used by mega-menus.
	 *
	 * @param array<string, mixed> $scope
	 * @return array{state:string,value:string}|null
	 */
	private function peek_enum_toggle( array $scope ): ?array {
		$saved = $this->i;
		$this->ws();
		$name = $this->read_ident();
		if ( $name === '' || ! isset( $this->toggles[ $name ] ) ) {
			// Local alias produced by `const isOpen = open === it.key`.
			if ( $name !== '' && isset( $scope[ $name ] ) && is_array( $scope[ $name ] ) && ! empty( $scope[ $name ]['__enum_gate'] ) ) {
				$gate = $scope[ $name ];
				$this->i = $saved;
				return array(
					'state' => (string) ( $gate['state'] ?? '' ),
					'value' => (string) ( $gate['value'] ?? '' ),
				);
			}
			$this->i = $saved;
			return null;
		}
		$this->ws();
		$op = $this->read_op( array( '===', '==' ) );
		if ( $op === '' ) {
			$this->i = $saved;
			return null;
		}
		$this->ws();
		$value = $this->parse_add( $scope );
		$this->i = $saved;
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return null;
		}

		return array(
			'state' => $name,
			'value' => (string) $value,
		);
	}

	private function enum_toggle_flag( string $state, string $value ): string {
		return sanitize_html_class( $state . '--' . $value );
	}

	/**
	 * The state gate in front of a conditional CLASS expression, whichever way
	 * round the design wrote the comparison.
	 *
	 * peek_enum_toggle() reads only `state === literal`, which is how a
	 * mega-menu writes it: `open === "practice"`. Every tab rail in the corpus
	 * writes the index first instead — `idx === i ? "is-on" : ""` in Project
	 * Page Duplicator's rv-cs-rail, `i === active` in TabbedPanels — so the
	 * state can sit on either side, and the value on the other side may be a
	 * member expression (`activeId === s.id`) rather than a literal. Reading
	 * only one order is why 390 triggers stood against 22 targets.
	 *
	 * The cursor is restored on every path; the caller re-parses the condition
	 * for its value.
	 *
	 * @param array<string, mixed> $scope
	 * @return array{state:string, value:?string, negate:bool}|null
	 */
	private function peek_class_gate( array $scope ): ?array {
		if ( $this->toggles === array() ) {
			return null;
		}

		$saved  = $this->i;
		$negate = false;
		$this->ws();
		while ( $this->peek() === '!' ) {
			$negate = ! $negate;
			++$this->i;
			$this->ws();
		}

		$left_at = $this->i;
		$name    = $this->read_ident();
		if ( $name === '' ) {
			$this->i = $saved;

			return null;
		}
		$left_end = $this->i;

		$this->ws();
		$op = $this->read_op( array( '===', '!==', '==', '!=' ) );
		if ( $op === '' ) {
			$gate = null;
			if ( isset( $this->toggles[ $name ] ) ) {
				$gate = array( 'state' => $name, 'value' => null, 'negate' => $negate );
			} elseif (
				is_array( $scope[ $name ] ?? null )
				&& ! empty( $scope[ $name ]['__enum_gate'] )
				&& isset( $scope[ $name ]['state'], $scope[ $name ]['value'] )
			) {
				// `const open = openFaq === i` — the alias the block-statement
				// loop bound, carrying the gate it came from.
				$gate = array(
					'state'  => (string) $scope[ $name ]['state'],
					'value'  => (string) $scope[ $name ]['value'],
					'negate' => $negate,
				);
			}
			$this->i = $saved;

			return $gate;
		}

		if ( $op === '!==' || $op === '!=' ) {
			$negate = ! $negate;
		}

		$this->ws();
		$right_at   = $this->i;
		$right_name = $this->read_ident();
		$this->i    = $right_at;
		$right      = $this->parse_add( $scope );
		$this->i    = $saved;

		if ( isset( $this->toggles[ $name ] ) ) {
			return $this->class_gate( $name, $right, $negate );
		}

		// State on the right: the left side is the per-row constant. Only a
		// bare identifier is read here — anything else would need the cursor
		// left where parse_add() put it, and this is a peek.
		if ( $right_name !== '' && isset( $this->toggles[ $right_name ] ) && $left_end > $left_at ) {
			return $this->class_gate( $right_name, $scope[ $name ] ?? null, $negate );
		}

		return null;
	}

	/**
	 * @return array{state:string, value:?string, negate:bool}|null
	 */
	private function class_gate( string $state, mixed $value, bool $negate ): ?array {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return null;
		}

		return array( 'state' => $state, 'value' => (string) $value, 'negate' => $negate );
	}

	/**
	 * Record `state -> {classes when on, classes when off}` for the element
	 * whose className expression is being parsed.
	 *
	 * @param array{state:string, value:?string, negate:bool} $gate
	 */
	private function record_class_gate( array $gate, mixed $on, mixed $off ): void {
		if ( ! isset( $this->driven[ $gate['state'] ] ) ) {
			return;
		}
		$on_text  = is_string( $on ) ? $on : '';
		$off_text = is_string( $off ) ? $off : '';
		if ( $gate['negate'] ) {
			[ $on_text, $off_text ] = array( $off_text, $on_text );
		}
		if ( trim( $on_text ) === '' && trim( $off_text ) === '' ) {
			return;
		}

		$flag = $gate['value'] === null
			? sanitize_html_class( $gate['state'] )
			: $this->enum_toggle_flag( $gate['state'], $gate['value'] );
		if ( $flag === '' ) {
			return;
		}

		$slot = $this->cls_gates[ $flag ] ?? array(
			'on'  => array(),
			'off' => array(),
		);
		foreach ( array( 'on' => $on_text, 'off' => $off_text ) as $side => $text ) {
			foreach ( preg_split( '/\s+/', trim( $text ) ) ?: array() as $token ) {
				if ( $token !== '' && ! in_array( $token, $slot[ $side ], true ) ) {
					$slot[ $side ][] = $token;
				}
			}
		}
		$this->cls_gates[ $flag ] = $slot;
	}

	/**
	 * Record a gated declaration inside a `style={{ ... }}` object.
	 *
	 * @param array{state:string, value:?string, negate:bool} $gate
	 */
	private function record_style_gate( array $gate, mixed $on, mixed $off ): void {
		if ( ! isset( $this->driven[ $gate['state'] ] ) ) {
			return;
		}
		if ( $gate['negate'] ) {
			[ $on, $off ] = array( $off, $on );
		}
		$flag = $gate['value'] === null
			? sanitize_html_class( $gate['state'] )
			: $this->enum_toggle_flag( $gate['state'], $gate['value'] );
		if ( $flag === '' ) {
			return;
		}

		$this->style_gates[] = array(
			'flag' => $flag,
			'on'   => $on,
			'off'  => $off,
		);
	}

	/**
	 * Which arm of a gated size declaration is the OPEN one.
	 *
	 * `open ? "1fr" : "0fr"` opens on the true arm; ARA's strip is written the
	 * other way round — `stripDismissed ? 0 : 44` — and a panel marked
	 * `dxai-on-*` when the state actually hides it would vanish on the click
	 * meant to reveal it. Bigger wins; equal or unreadable declines to guess.
	 */
	private static function open_arm( mixed $on, mixed $off ): ?bool {
		$size = static function ( mixed $value ): ?float {
			if ( is_int( $value ) || is_float( $value ) ) {
				return (float) $value;
			}
			if ( is_string( $value ) && preg_match( '/^\s*(-?[0-9]*\.?[0-9]+)/', $value, $m ) === 1 ) {
				return (float) $m[1];
			}

			return null;
		};
		$a = $size( $on );
		$b = $size( $off );
		if ( null === $a || null === $b || $a === $b ) {
			return null;
		}

		return $a > $b;
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function toggle_is_expanded( string $flag, array $scope ): bool {
		if ( array_key_exists( $flag, $scope ) ) {
			return $this->truthy( $scope[ $flag ] );
		}
		if ( preg_match( '/^(.+)--(.+)$/', $flag, $m ) !== 1 ) {
			return false;
		}
		$current = $scope[ $m[1] ] ?? null;
		if ( is_array( $current ) && array_key_exists( '__open', $current ) ) {
			return (bool) $current['__open'] && (string) ( $current['value'] ?? '' ) === $m[2];
		}

		return (string) $current === $m[2];
	}

	private function toggle_from_handler( string $body, array $scope = array(), int $depth = 0 ): string {
		if ( 0 === $depth ) {
			$this->toggle_meta = array();
		}

		foreach ( $this->setters as $setter => $state ) {
			if ( $setter === '' || ! str_contains( $body, $setter ) ) {
				continue;
			}
			if ( preg_match( '/' . preg_quote( $setter, '/' ) . '\(\s*[\'"]([^\'"]+)[\'"]/', $body, $m ) ) {
				return $this->enum_toggle_flag( $state, $m[1] );
			}
			if ( preg_match( '/' . preg_quote( $setter, '/' ) . '\([^)]*\bit\.key\b/', $body ) ) {
				$key = '';
				if ( isset( $scope['it'] ) && is_array( $scope['it'] ) && isset( $scope['it']['key'] ) ) {
					$key = (string) $scope['it']['key'];
				} elseif ( isset( $scope['key'] ) ) {
					$key = (string) $scope['key'];
				}
				if ( $key !== '' && $key !== 'loc' ) {
					return $this->enum_toggle_flag( $state, $key );
				}
			}
			// Accordion: setOpenFaq(open ? null : i) / setOpenFaq(i).
			if ( preg_match(
				'/' . preg_quote( $setter, '/' ) . '\(\s*(?:([\w.]+)\s*\?\s*null\s*:\s*)?([A-Za-z_][\w]*)\s*\)/',
				$body,
				$m
			) ) {
				$idx = $scope[ $m[2] ] ?? null;
				if ( is_int( $idx ) || ( is_string( $idx ) && $idx !== '' && ctype_digit( $idx ) ) ) {
					if ( ( $m[1] ?? '' ) !== '' ) {
						// `open ? null : i` collapses when it is already open;
						// `setActive(i)` on a tab rail does not.
						$this->toggle_meta['mode'] = 'toggle';
					}
					return $this->enum_toggle_flag( $state, (string) $idx );
				}
			}

			/*
			 * Last resort for a direct setter: evaluate whatever it is passed.
			 * DevriX Elevate's nav writes
			 * `onMouseEnter={() => setOpen(item.children ? item.label : null)}`,
			 * which none of the patterns above read, so all 46 of its triggers
			 * came out as the bare flag `open` while the anchors beside them
			 * project `open === item.label`. Trigger and target named
			 * different things, and every one of those anchors was inert.
			 */
			$arg = $this->call_argument( $body, $setter );
			if ( $arg !== '' ) {
				$value = $this->eval_source( $arg, $scope );
				if ( is_int( $value ) || ( is_string( $value ) && $value !== '' ) ) {
					return $this->enum_toggle_flag( $state, (string) $value );
				}
				/*
				 * A literal target, not a flip. Thirty-eight of DevriX
				 * Elevate's triggers are `setOpen(null)` — dismiss the
				 * mega-menu — and thirty-nine are `setMobile(false)` on the
				 * drawer's own links. Emitting a bare flag made every one of
				 * them a toggle, so a dismiss could just as easily re-open
				 * what it was meant to close.
				 */
				if ( preg_match( '/^(null|undefined|true|false)$/', $arg, $lit ) === 1 ) {
					// Read off the SOURCE, not the evaluated value: eval_source()
					// also returns null for an expression it could not read, and
					// a `setOpen(item.children ? item.label : null)` that failed
					// to evaluate must not be recorded as a dismiss.
					$this->toggle_meta['set'] = 'undefined' === $lit[1] ? 'null' : $lit[1];
				}
			}

			return $state;
		}

		/*
		 * Indirect: the handler calls a function the component declared, and
		 * that function calls the setter. Project Page Duplicator's case-study
		 * rail is `onClick={() => go(idx)}` over
		 * `const go = (next: number) => setI(((next % n) + n) % n)`. The body
		 * holds no setter name, this returned '' and no trigger was emitted at
		 * all — the four inert `BUTTON.rv-cs-tab` the behaviour probe named.
		 *
		 * Bounded at two hops, and each hop must name a *different* callee, so
		 * a helper that calls itself cannot spin. This file carries
		 * advanced() guards on seven cursor loops for that reason.
		 */
		if ( $depth < 2 ) {
			foreach ( $this->local_calls( $body ) as $callee => $arg_src ) {
				$fn = $scope[ $callee ] ?? null;
				if ( ! is_array( $fn ) || empty( $fn['__fn'] ) ) {
					continue;
				}
				$local  = is_array( $fn['scope'] ?? null ) ? $fn['scope'] : $scope;
				$params = (array) ( $fn['params'] ?? array() );
				if ( isset( $params[0] ) && $arg_src !== '' ) {
					$local[ (string) $params[0] ] = $this->eval_source( $arg_src, $scope );
				}
				$flag = $this->toggle_from_handler( (string) ( $fn['body'] ?? '' ), $local, $depth + 1 );
				if ( $flag === '' ) {
					continue;
				}

				/*
				 * `go(i - 1)` is a relative move, and the value it evaluates to
				 * is only right for the index the page was compiled at. Emit
				 * the step instead and let the runtime do the arithmetic.
				 */
				$state = explode( '--', $flag )[0];
				if ( preg_match( '/^\s*' . preg_quote( $state, '/' ) . '\s*([+-])\s*(\d+)\s*$/', $arg_src, $m ) ) {
					$this->toggle_meta['step'] = ( $m[1] === '-' ? '-' : '' ) . $m[2];
					$init = $this->state_init[ $state ] ?? null;
					if ( is_int( $init ) || ( is_string( $init ) && $init !== '' ) ) {
						$this->toggle_meta['init'] = (string) $init;
					}

					return sanitize_html_class( $state );
				}

				return $flag;
			}
		}

		return '';
	}

	/**
	 * The source text of the single argument `$name( ... )` is called with.
	 */
	private function call_argument( string $body, string $name ): string {
		/*
		 * Only when the handler calls it once. ARA's nav writes
		 * `it.hasDropdown ? setOpen(isOpen ? null : it.key) : setOpen(null)`,
		 * and reading the first call picked the dropdown branch for the one
		 * item that has no dropdown — a trigger named after a panel that does
		 * not exist. Two calls means the branch decides, and the branch is not
		 * modelled, so decline rather than guess.
		 */
		if ( preg_match_all( '/\b' . preg_quote( $name, '/' ) . '\s*\(/', $body ) !== 1 ) {
			return '';
		}
		if ( ! preg_match( '/\b' . preg_quote( $name, '/' ) . '\s*\(/', $body, $m, PREG_OFFSET_CAPTURE ) ) {
			return '';
		}
		$at   = (int) $m[0][1];
		$open = strpos( $body, '(', $at );
		if ( false === $open ) {
			return '';
		}
		$close = $this->match_pair( $body, $open );

		return $close < 0 ? '' : trim( substr( $body, $open + 1, $close - $open - 1 ) );
	}

	/**
	 * Every `name( ... )` call in a handler body that is not a setter, mapped
	 * to its argument source. Keywords are skipped so `if (`/`return (` cannot
	 * be mistaken for a component helper.
	 *
	 * @return array<string, string>
	 */
	private function local_calls( string $body ): array {
		$skip = array( 'if', 'for', 'while', 'switch', 'catch', 'return', 'function', 'typeof', 'String', 'Number', 'Boolean', 'Math' );
		$out  = array();
		if ( ! preg_match_all( '/\b([A-Za-z_][\w]*)\s*\(/', $body, $m, PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}
		foreach ( $m[1] as $at => $hit ) {
			$name = (string) $hit[0];
			if ( isset( $this->setters[ $name ] ) || in_array( $name, $skip, true ) || isset( $out[ $name ] ) ) {
				continue;
			}
			$open = strpos( $body, '(', (int) $m[0][ $at ][1] );
			if ( false === $open ) {
				continue;
			}
			$close = $this->match_pair( $body, $open );
			if ( $close < 0 ) {
				continue;
			}
			$out[ $name ] = trim( substr( $body, $open + 1, $close - $open - 1 ) );
		}

		return $out;
	}

	private function is_markup( mixed $value ): bool {
		if ( is_array( $value ) && isset( $value['__html'] ) ) {
			return str_contains( (string) $value['__html'], '<' );
		}

		return is_string( $value ) && str_contains( $value, '<' );
	}

	private function markup_html( mixed $value ): string {
		if ( is_array( $value ) && isset( $value['__html'] ) ) {
			return (string) $value['__html'];
		}

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Keep gated JSX in the DOM (hidden) so a click can reveal it 1:1.
	 */
	private function hide_until( string $html, string $state, bool $when_on ): string {
		$html = trim( $html );
		if ( $html === '' ) {
			return '';
		}
		$flag = sanitize_html_class( $state );
		if ( $when_on ) {
			$html = self::add_class( $html, 'hidden' );
			$html = self::add_class( $html, 'dxai-on-' . $flag );
		} else {
			$html = self::add_class( $html, 'dxai-off-' . $flag );
		}

		return $html;
	}

	/**
	 * @param array<string, mixed> $attrs
	 */
	private function placeholder( array $attrs, string $children ): string {
		$class = trim( $this->stringify_attr( $attrs['className'] ?? $attrs['class'] ?? '' ) );
		if ( $class === '' && trim( $children ) === '' ) {
			return '';
		}

		$out = '<span';
		if ( $class !== '' ) {
			$out .= ' class="' . esc_attr( $class ) . '"';
		}
		if ( trim( $children ) === '' ) {
			$out .= ' aria-hidden="true"';
		}

		return $out . '>' . $children . '</span>';
	}

	/**
	 * True when the component gates its markup on a scroll-reveal flag.
	 */
	private static function declares_reveal( string $code ): bool {
		if ( ! preg_match_all( '/const\s*\[\s*([A-Za-z_][\w]*)\s*,[^\]]+\]\s*=\s*useState(?:<[^>]*>)?\(\s*false\s*\)/', $code, $m ) ) {
			return false;
		}
		foreach ( $m[1] as $name ) {
			if ( self::is_reveal_flag( $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Flag the element so the motion runtime can replay the entrance the
	 * original played on scroll. The settled state stays in `class`, so the
	 * section is still fully visible if the runtime never loads.
	 *
	 * A class rather than a data attribute because `core/group` carries
	 * `className` through block conversion while arbitrary `data-*` is dropped.
	 */
	private function mark_reveal( string $html ): string {
		return self::add_class( $html, 'dxai-reveal' );
	}

	/**
	 * Merge a class into the first tag of a rendered fragment.
	 */
	/**
	 * Keep an element's markup but stop it painting.
	 *
	 * `display:none` inline, and inline for a reason: the `hidden` ATTRIBUTE
	 * would not do it. Tailwind's `[hidden]{display:none}` loses to any
	 * class-set display, and every shadcn overlay body carries one — a Dialog's
	 * content is `fixed … z-50 grid …`, so `grid` would win and the panel would
	 * still cover the page. An inline style outranks every class.
	 *
	 * The `data-dxai-portal` marker says why the element is hidden, so a later
	 * pass — or a projected control — can find these deliberately.
	 */
	/**
	 * Add attributes to the first element of a fragment, child wins.
	 *
	 * How a Radix `Slot` composes: the props it was given land on the child it
	 * wraps, except where the child already has that attribute. Reproducing
	 * the precedence matters — an `id` the design put on the control must not
	 * be replaced by the one the Slot would have supplied.
	 */
	private static function merge_into_root( string $html, string $attrs ): string {
		$attrs = trim( $attrs );
		if ( $attrs === '' || trim( $html ) === '' ) {
			return $html;
		}
		if ( preg_match( '/<([a-zA-Z][a-zA-Z0-9:-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*?)(\/?)>/', $html, $match, PREG_OFFSET_CAPTURE ) !== 1 ) {
			return $html;
		}

		$existing = array();
		if ( preg_match_all( '/([A-Za-z_:][-\w:.]*)\s*=/', (string) $match[2][0], $names ) ) {
			foreach ( $names[1] as $one ) {
				$existing[ strtolower( $one ) ] = true;
			}
		}

		$add = '';
		if ( preg_match_all( '/\s([A-Za-z_:][-\w:.]*)="([^"]*)"/', ' ' . $attrs, $pairs, PREG_SET_ORDER ) ) {
			foreach ( $pairs as $pair ) {
				if ( isset( $existing[ strtolower( $pair[1] ) ] ) ) {
					continue;
				}
				$add .= ' ' . $pair[1] . '="' . $pair[2] . '"';
			}
		}
		if ( $add === '' ) {
			return $html;
		}

		$open   = (string) $match[0][0];
		$offset = (int) $match[0][1];
		$closer = (string) $match[3][0];
		$rebuilt = rtrim( substr( $open, 0, strlen( $open ) - strlen( $closer ) - 1 ) ) . $add . $closer . '>';

		return substr( $html, 0, $offset ) . $rebuilt . substr( $html, $offset + strlen( $open ) );
	}

	private static function hide_element( string $html ): string {
		if ( preg_match( '/^\s*<([a-zA-Z][a-zA-Z0-9:-]*)([^>]*)>/', $html, $m ) !== 1 ) {
			return $html;
		}

		$open  = $m[0];
		$attrs = $m[2];

		if ( preg_match( '/\sstyle="([^"]*)"/', $attrs, $style ) === 1 ) {
			// Already hidden — a closed Radix part inside a portalled
			// component is hidden by both rules, and one declaration is enough.
			if ( preg_match( '/display\s*:\s*none/i', $style[1] ) === 1 ) {
				return $html;
			}
			$merged  = rtrim( trim( $style[1] ), ';' );
			$merged  = $merged === '' ? 'display:none' : $merged . ';display:none';
			$updated = str_replace( $style[0], ' style="' . esc_attr( $merged ) . '"', $open );
		} else {
			$updated = rtrim( $open, '>' );
			$updated = rtrim( $updated, '/' );
			$updated = $updated . ' style="display:none">';
		}

		if ( ! str_contains( $updated, 'data-dxai-portal' ) ) {
			$updated = (string) preg_replace( '/>$/', ' data-dxai-portal="1">', $updated, 1 );
		}

		return $updated . substr( $html, strlen( $open ) );
	}

	private static function add_class( string $html, string $class ): string {
		if ( ! preg_match( '/^\s*<([a-zA-Z][a-zA-Z0-9:-]*)([^>]*)>/', $html, $m ) ) {
			return $html;
		}

		$tag_end = strlen( $m[0] );
		$open    = $m[0];
		$attrs   = $m[2];

		if ( preg_match( '/\sclass="([^"]*)"/', $attrs, $existing ) ) {
			$classes = preg_split( '/\s+/', trim( $existing[1] ) ) ?: array();
			if ( in_array( $class, $classes, true ) ) {
				return $html;
			}
			$classes[] = $class;
			$replaced  = str_replace( $existing[0], ' class="' . esc_attr( implode( ' ', $classes ) ) . '"', $open );

			return $replaced . substr( $html, $tag_end );
		}

		$insert = strpos( $open, $m[1] );
		if ( false === $insert ) {
			return $html;
		}
		$insert += strlen( $m[1] );

		return substr( $open, 0, $insert ) . ' class="' . esc_attr( $class ) . '"' . substr( $open, $insert ) . substr( $html, $tag_end );
	}

	/**
	 * Record which component produced a fragment so section titles survive the
	 * split. Markers are stripped before the markup is persisted.
	 */
	private function mark_component( string $html, string $name ): string {
		if ( $html === '' || ! preg_match( '/^\s*<([a-zA-Z][a-zA-Z0-9:-]*)/', $html, $m ) ) {
			return $html;
		}

		$at = strpos( $html, $m[1] );
		if ( false === $at ) {
			return $html;
		}
		$at += strlen( $m[1] );

		return substr( $html, 0, $at ) . ' data-dxai-component="' . esc_attr( $name ) . '"' . substr( $html, $at );
	}

	/**
	 * @param array<string, mixed> $attrs
	 */
	private function builtin_section( array $attrs, string $children ): string {
		$class = trim( $this->stringify_attr( $attrs['className'] ?? $attrs['class'] ?? '' ) );
		$id    = $this->stringify_attr( $attrs['id'] ?? '' );
		$label = $this->stringify_attr( $attrs['labelledBy'] ?? $attrs['aria-labelledby'] ?? '' );
		$out   = '<section';
		if ( $id !== '' ) {
			$out .= ' id="' . esc_attr( $id ) . '"';
		}
		if ( $label !== '' ) {
			$out .= ' aria-labelledby="' . esc_attr( $label ) . '"';
		}
		// The reveal hook rides in the class list: block conversion keeps
		// `className` but discards arbitrary data attributes.
		$out .= ' class="' . esc_attr( trim( $class . ' dxai-reveal' ) ) . '"';
		$out .= ' data-reveal="1">' . $children . '</section>';
		return $out;
	}

	/**
	 * Whether builtin_section() may render in place of the design's own Section.
	 *
	 * The builtin reproduces one scroll-reveal helper: a `Section` that is a
	 * bare `<section>` around its children, driven by a `useReveal()`
	 * IntersectionObserver that cannot run here, so the builtin hands the
	 * reveal to Motion_Runtime instead. That is a stand-in only for the same
	 * component. Careers Page Builder's Section takes a `tone` and wraps its
	 * children in a max-width container, and RevOps' takes a `tone` for its
	 * `dx-sec--*` band. The builtin kept className and id, so the dark bands
	 * lost their background, padding and width, and RevOps' fit list, why
	 * rows and testimonial cards, which its CSS hides until `.dx-sec.is-in`,
	 * had no `.dx-sec` to be revealed under.
	 *
	 * So the builtin renders when the design has no Section, or when its own
	 * uses the reveal hook, takes no prop the builtin ignores and draws no
	 * element but the `<section>`. Anything else is the design's own and
	 * renders as written.
	 */
	private function builtin_section_fits(): bool {
		$code = $this->components['Section'] ?? '';
		if ( $code === '' ) {
			return true;
		}
		if ( 1 !== preg_match( '/\buseReveal\b/', $code ) ) {
			return false;
		}

		// The destructured parameter list, whether the first `(` opens it or a
		// forwardRef's arrow inside it does. `(props)` names nothing to check.
		if ( 1 !== preg_match( '/^[^(]*\(\s*(?:\(\s*)?\{/', $code, $open ) ) {
			return false;
		}
		$brace = strlen( $open[0] ) - 1;
		$close = $this->match_pair( $code, $brace );
		if ( $close < 0 ) {
			return false;
		}
		foreach ( $this->split_params( substr( $code, $brace + 1, $close - $brace - 1 ) ) as $part ) {
			$part = trim( $part );
			if ( $part === '' ) {
				continue;
			}
			// A rest element fails here too: it forwards props nobody listed.
			if ( 1 !== preg_match( '/^([A-Za-z_$][\w$]*)/', $part, $prop ) || ! in_array( $prop[1], array( 'children', 'className', 'id', 'labelledBy' ), true ) ) {
				return false;
			}
		}

		// Lower-case JSX tags only. The lookbehind keeps out a type argument
		// such as `useState<number>`, which an identifier always precedes.
		preg_match_all( '/(?<![\w$.])<([a-z][\w-]*)\b/', $code, $tags );

		return array_diff( $tags[1], array( 'section' ) ) === array();
	}

	private function builtin_label( string $children ): string {
		return '<span class="rv-label"><span class="rv-label-rule" aria-hidden="true"></span>' . $children . '</span>';
	}

	/**
	 * @param array<string, mixed> $attrs
	 */
	private function builtin_countup( array $attrs ): string {
		$value  = $attrs['value'] ?? 0;
		$suffix = $this->stringify_attr( $attrs['suffix'] ?? '' );
		$num    = is_numeric( $value ) ? (float) $value : 0;
		$shown  = $this->stringify_attr( $value );
		return '<span class="rv-num" data-countup="' . esc_attr( (string) $num ) . '" data-suffix="' . esc_attr( $suffix ) . '">'
			. esc_html( $shown . $suffix )
			. '<span class="sr-only">' . esc_html( $shown . $suffix ) . '</span></span>';
	}

	/**
	 * @param array<string, mixed> $attrs
	 * @return array<string, mixed>
	 */
	private function props_from_attrs( array $attrs ): array {
		$props = array();
		foreach ( $attrs as $name => $value ) {
			$props[ $name ] = $value;
			// Same reason as parse_attrs(): a component that forwards its own
			// `className` must be able to tell "not given" from "given empty",
			// or `className ?? "fallback"` inside it can never take the arm the
			// design wrote.
			if ( $name === 'className' && null !== $value && false !== $value ) {
				$props['className'] = $this->stringify_attr( $value );
			}
		}
		return $props;
	}

	/**
	 * @param array<string, mixed> $attrs
	 */
	private function html_attrs( array $attrs, string $tag ): string {
		$out = '';
		foreach ( $attrs as $name => $value ) {
			if ( str_starts_with( (string) $name, '__' ) ) {
				continue;
			}
			$lname = strtolower( (string) $name );
			if ( in_array( $lname, self::SKIP_ATTR, true ) ) {
				continue;
			}
			if ( $tag === 'img' && $lname === 'fill' ) {
				continue;
			}
			/*
			 * `placeholder` is two things: a form field's hint text, and Next's
			 * `<Image placeholder="blur">`. It sat in SKIP_ATTR for the second,
			 * which took "Your name" and "you@example.com" off every input on a
			 * form. Kept only where HTML defines it.
			 */
			if ( $lname === 'placeholder' && $tag !== 'input' && $tag !== 'textarea' ) {
				continue;
			}
			if ( 1 === preg_match( '/^(aria|data)[A-Z]/', (string) $name ) ) {
				$name  = strtolower( (string) preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', (string) $name ) );
				$lname = $name;
			}
			if ( $lname === 'classname' ) {
				$name  = 'class';
				$lname = 'class';
			}
			if ( $lname === 'htmlfor' ) {
				$name = 'for';
			}
			/*
			 * React stringifies booleans on aria-* and data-*:
			 * `aria-selected={false}` renders `aria-selected="false"`, and only
			 * null/undefined drop the attribute. ICP's method dots lost their
			 * "false" here, so the strip read as one tab selected and the rest
			 * unmarked — a contract every tab strip breaks that way.
			 */
			if ( $value === false && ( str_starts_with( $lname, 'aria-' ) || str_starts_with( $lname, 'data-' ) ) ) {
				$value = 'false';
			}
			if ( $value === false || $value === null ) {
				continue;
			}
			/*
			 * A function is never an attribute. Render props are captured as
			 * callables now (see RENDER_PROPS), and one that nothing called
			 * would otherwise be stringified onto the element — the same way a
			 * cva recipe once shipped as `buttonvariants="__dxai_cva__"`.
			 */
			if ( is_array( $value ) && ! empty( $value['__fn'] ) ) {
				continue;
			}
			if ( $value === true ) {
				$out .= ' ' . $name . '="true"';
				continue;
			}
			if ( $lname === 'style' && is_array( $value ) ) {
				$value = $this->style_from_object( $value );
			}
			if ( $lname === 'src' ) {
				$value = $this->resolve_src( $value );
			}
			$text = $this->stringify_attr( $value );
			/*
			 * An empty reference is not a reference. shadcn's FormControl
			 * builds `aria-describedby={`${formDescriptionId}`}` from a
			 * useId() this compiler cannot run, and the template literal over
			 * an unbound id collapses to '' — which shipped
			 * `aria-describedby=""`, an IDREF pointing at nothing. Absent is
			 * both valid and honest; the id wiring itself is reported as
			 * unevaluated.
			 */
			if ( $text === '' && in_array( $lname, self::IDREF_ATTR, true ) ) {
				continue;
			}
			if ( $lname === 'class' ) {
				$text = trim( preg_replace( '/\s+/', ' ', $text ) ?? $text );
			}
			$out .= ' ' . $name . '="' . esc_attr( $text ) . '"';
		}

		if ( $tag === 'button' && ! isset( $attrs['type'] ) && ! isset( $attrs['Type'] ) ) {
			$out .= ' type="button"';
		}

		return $out;
	}

	private function resolve_src( mixed $value ): string {
		if ( is_array( $value ) && isset( $value['url'] ) ) {
			$value = $value['url'];
		}
		$text = $this->stringify_attr( $value );
		if ( isset( $this->images[ $text ] ) ) {
			return $this->images[ $text ];
		}
		$base = strtolower( basename( strtok( $text, '?' ) ?: $text ) );
		if ( isset( $this->images[ $base ] ) ) {
			return $this->images[ $base ];
		}
		/*
		 * The logoAsset fallback, for a design that names a logo it never
		 * imported — see pick_logo_url(). It used to fire on any `src` merely
		 * CONTAINING `logo`, which meant it also overrode a logo that had
		 * resolved perfectly well from a real import, and replaced it with
		 * whichever logo-ish asset the harvest happened to list first.
		 *
		 * ARA ships two: AraLogo.tsx is `const src = color === "white" ?
		 * logoWhite : logoNavy`, DesktopNav takes the navy default and
		 * SiteFooter passes color="white". Both names bind correctly and the
		 * ternary picks correctly — and then this line threw the answer away,
		 * so the footer rendered the navy file. The two PNGs have different
		 * aspect ratios, so the nav logo laid out 74px wide against the
		 * design's 118px and the flex row redistributed around it: one
		 * structure swap and 32 box differences per width, none of which was
		 * anywhere near its real cause.
		 *
		 * Worse, it was not even stable. The override only runs when the two
		 * lookups above miss, and pick_logo_url() returns "the first image
		 * whose name contains logo" — an order that moves as the harvest
		 * changes between imports. The same ZIP resolved the footer correctly
		 * on one import and wrongly on the next.
		 *
		 * So the fallback now only answers for a reference that is NOT already
		 * an address: a bare identifier, or a path that never resolved. An
		 * `https?://`, protocol-relative or `data:` value has a real asset
		 * behind it and is returned untouched. A root-relative `/logo.png`
		 * stays eligible, which is the case the fallback was written for.
		 *
		 * `__l5e` keeps the old unconditional behaviour: that is Lovable's
		 * internal asset route (see Asset_Harvester), a placeholder that never
		 * resolves to a file on its own, so an absolute one still needs the
		 * substitution.
		 */
		$is_address = 1 === preg_match( '#^(?:https?:)?//|^data:#i', $text );
		if ( isset( $this->globals['logoAsset']['url'] )
			&& ( str_contains( $text, '__l5e' ) || ( str_contains( $text, 'logo' ) && ! $is_address ) ) ) {
			return (string) $this->globals['logoAsset']['url'];
		}

		return $text;
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function parse_expression( array $scope ): mixed {
		$value = $this->parse_ternary( $scope );
		$this->skip_ts_as();
		return $value;
	}

	private function skip_ts_as(): void {
		$this->ws();
		if ( ! preg_match( '/^as\b/', substr( $this->src, $this->i, 8 ) ) ) {
			return;
		}
		$this->i += 2;
		$depth = 0;
		while ( $this->i < $this->len ) {
			$ch = $this->peek();
			if ( $ch === '<' || $ch === '(' || $ch === '[' ) {
				++$depth;
			} elseif ( $ch === '>' || $ch === ')' || $ch === ']' ) {
				if ( $depth === 0 ) {
					return;
				}
				--$depth;
			} elseif ( 0 === $depth && ( $ch === ':' || $ch === ',' || $ch === '}' || $ch === ';' ) ) {
				return;
			}
			++$this->i;
		}
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function parse_ternary( array $scope ): mixed {
		$state = $this->peek_toggle_state();
		$gate  = $this->in_class_expr || $this->in_style_expr ? $this->peek_class_gate( $scope ) : null;
		$left  = $this->parse_or( $scope );
		$this->ws();
		if ( $this->peek() !== '?' ) {
			return $left;
		}
		if ( $this->peek_at( $this->i + 1 ) === '.' ) {
			return $this->parse_postfix( $left, $scope );
		}
		++$this->i;
		$this->ws();
		$mid = $this->parse_jsx_or_expr( $scope );
		$this->ws();
		if ( $this->peek() === ':' ) {
			++$this->i;
		}
		$this->ws();
		$right = $this->parse_jsx_or_expr( $scope );
		if ( $state !== '' && ( $this->is_markup( $mid ) || $this->is_markup( $right ) ) ) {
			$on  = $this->markup_html( $mid );
			$off = $this->markup_html( $right );

			return array(
				'__html' => $this->hide_until( $on, $state, true ) . $this->hide_until( $off, $state, false ),
			);
		}

		// Both arms are class fragments: keep the losing one as a projection
		// rather than dropping it. The returned value is unchanged, so the
		// element still renders the compile-time state exactly as before.
		if ( $gate !== null && ! $this->is_markup( $mid ) && ! $this->is_markup( $right ) ) {
			if ( $this->in_style_expr ) {
				$this->record_style_gate( $gate, $mid, $right );
			} else {
				$this->record_class_gate( $gate, $mid, $right );
			}
		}

		return $this->truthy( $left ) ? $mid : $right;
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function parse_jsx_or_expr( array $scope ): mixed {
		$this->ws();
		if ( $this->peek() === '(' ) {
			$save = $this->i;
			++$this->i;
			$this->ws();
			if ( $this->peek() === '<' ) {
				$html = $this->parse_element( $scope );
				$this->ws();
				if ( $this->peek() === ')' ) {
					++$this->i;
				}
				return array( '__html' => $html );
			}
			$this->i = $save;
		}
		if ( $this->peek() === '<' ) {
			return array( '__html' => $this->parse_element( $scope ) );
		}

		return $this->parse_ternary( $scope );
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function parse_or( array $scope ): mixed {
		$left = $this->parse_and( $scope );
		while ( true ) {
			$this->ws();
			if ( substr( $this->src, $this->i, 2 ) === '||' ) {
				$this->i += 2;
				$right = $this->parse_and( $scope );
				$left  = $this->truthy( $left ) ? $left : $right;
				continue;
			}
			/*
			 * `??` was handled nowhere, and parse_ternary() guards only `?.`,
			 * so it took the first `?` for a conditional and returned the
			 * unparsed middle — the whole expression evaluated to nothing,
			 * with no note, because the cursor still landed on the closing
			 * brace. `aria-label={ariaLabel ?? "Abstract wireframe figure"}`
			 * in Project Page Duplicator's rv-figure.tsx left seven
			 * `role="img"` canvases with no accessible name, and the
			 * `className={`rv-fig ${className ?? ""}`}` beside it dropped the
			 * caller's own `rv-svc-fig` as well. Only null/undefined take the
			 * right arm — an empty string or 0 does not, which is the whole
			 * point of the operator over `||`.
			 */
			if ( substr( $this->src, $this->i, 2 ) === '??' ) {
				$this->i += 2;
				$right = $this->parse_and( $scope );
				$left  = null === $left ? $right : $left;
				continue;
			}
			break;
		}
		return $left;
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function parse_and( array $scope ): mixed {
		$enum  = $this->peek_enum_toggle( $scope );
		$state = $enum === null ? $this->peek_toggle_state() : '';
		$gate  = $this->in_class_expr ? $this->peek_class_gate( $scope ) : null;
		$left  = $this->parse_compare( $scope );
		while ( true ) {
			$this->ws();
			if ( substr( $this->src, $this->i, 2 ) === '&&' ) {
				$this->i += 2;
				$right = $this->parse_jsx_or_expr( $scope );
				if ( $enum !== null && $this->is_markup( $right ) ) {
					$flag = $this->enum_toggle_flag( $enum['state'], $enum['value'] );
					if ( $flag !== '' ) {
						$left = array( '__html' => $this->hide_until( $this->markup_html( $right ), $flag, true ) );
						$enum = null;
						$state = '';
						continue;
					}
				}
				if ( is_array( $left ) && ! empty( $left['__enum_gate'] ) && $this->is_markup( $right ) ) {
					$flag = $this->enum_toggle_flag( (string) $left['state'], (string) $left['value'] );
					if ( $flag !== '' ) {
						$left = array( '__html' => $this->hide_until( $this->markup_html( $right ), $flag, true ) );
						$state = '';
						continue;
					}
				}
				if ( $state !== '' && ! $this->truthy( $left ) && $this->is_markup( $right ) ) {
					$left  = array( '__html' => $this->hide_until( $this->markup_html( $right ), $state, true ) );
					$state = '';
					continue;
				}
				// `cn(active === i && "is-active")` — one-armed, so the off
				// side is empty and the class simply comes and goes.
				if ( $gate !== null && is_string( $right ) && trim( $right ) !== '' ) {
					$this->record_class_gate( $gate, $right, '' );
				}
				$left  = $this->truthy( $left ) ? $right : $left;
				$state = '';
				$enum  = null;
				$gate  = null;
				continue;
			}
			break;
		}
		return $left;
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function parse_compare( array $scope ): mixed {
		$saved = $this->i;
		$left_name = '';
		$this->ws();
		if ( preg_match( '/^[A-Za-z_]/', (string) $this->peek() ) ) {
			$left_name = $this->read_ident();
			$this->i   = $saved;
		}
		$left = $this->parse_add( $scope );
		while ( true ) {
			$this->ws();
			$op = $this->read_op( array( '===', '!==', '==', '!=', '<=', '>=', '<', '>' ) );
			if ( $op === '' ) {
				break;
			}
			$this->ws();
			$right_at   = $this->i;
			$right_name = preg_match( '/^[A-Za-z_]/', (string) $this->peek() ) ? $this->read_ident() : '';
			$this->i    = $right_at;
			$right      = $this->parse_add( $scope );
			if (
				( $op === '===' || $op === '==' )
				&& $left_name !== ''
				&& isset( $this->toggles[ $left_name ] )
				&& ( is_string( $right ) || is_int( $right ) )
			) {
				$this->last_enum_gate = array(
					'state' => $left_name,
					'value' => (string) $right,
					'__enum_gate' => true,
				);
			} elseif (
				( $op === '===' || $op === '==' )
				&& $right_name !== ''
				&& isset( $this->toggles[ $right_name ] )
				&& ( is_string( $left ) || is_int( $left ) )
			) {
				/*
				 * State on the RIGHT: `const open = i === active;` in Integration
				 * Hub's delivery sheet, with the row index on the left. Only the
				 * left-hand spelling recorded a gate, so `open` reached the row's
				 * className as a plain boolean, no projection was written, and
				 * clicking D-02 or D-03 opened nothing. peek_class_gate() has
				 * read both orders for inline comparisons all along.
				 */
				$this->last_enum_gate = array(
					'state' => $right_name,
					'value' => (string) $left,
					'__enum_gate' => true,
				);
			}
			$left  = match ( $op ) {
				'===' => self::js_identical( $left, $right ),
				'!==' => ! self::js_identical( $left, $right ),
				'=='  => $left == $right, // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
				'!='  => $left != $right, // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
				'<='  => $left <= $right,
				'>='  => $left >= $right,
				'<'   => $left < $right,
				'>'   => $left > $right,
				default => false,
			};
			$left_name = '';
		}
		return $left;
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function parse_add( array $scope ): mixed {
		$left = $this->parse_mul( $scope );
		while ( true ) {
			$this->ws();
			$op = $this->peek();
			if ( $op !== '+' && $op !== '-' ) {
				break;
			}
			++$this->i;
			$right = $this->parse_mul( $scope );
			if ( $op === '+' && ( is_string( $left ) || is_string( $right ) ) ) {
				$left = $this->stringify_attr( $left ) . $this->stringify_attr( $right );
			} else {
				$left = $op === '+' ? ( (float) $left + (float) $right ) : ( (float) $left - (float) $right );
			}
		}
		return $left;
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function parse_mul( array $scope ): mixed {
		$left = $this->parse_unary( $scope );
		while ( true ) {
			$this->ws();
			$op = $this->peek();
			if ( $op !== '*' && $op !== '/' && $op !== '%' ) {
				break;
			}
			++$this->i;
			$right = $this->parse_unary( $scope );
			$left  = match ( $op ) {
				'*' => (float) $left * (float) $right,
				'/' => ( (float) $right ) == 0.0 ? 0 : ( (float) $left / (float) $right ),
				'%' => self::js_remainder( (float) $left, (float) $right ),
				default => $left,
			};
		}
		return $left;
	}

	/**
	 * JavaScript's `%`, which PHP's is not.
	 *
	 * PHP's operator works on integers: it truncates both sides, so `5.5 % 2`
	 * was 1 instead of 1.5, and it throws DivisionByZeroError where JavaScript
	 * returns NaN — for `% 0`, and for `% 0.5`, which truncates to 0. The throw
	 * took the whole component with it. `accents[active % accents.length]`
	 * over an array that did not resolve is exactly that `% 0`.
	 *
	 * Whole numbers keep the integer result they always had; NaN is null, the
	 * evaluator's "no value", which indexes nothing — as NaN does.
	 */
	private static function js_remainder( float $left, float $right ): int|float|null {
		if ( $right == 0.0 || is_nan( $left ) || is_nan( $right ) || is_infinite( $left ) ) {
			return null;
		}
		if ( floor( $left ) === $left && floor( $right ) === $right && abs( $left ) < PHP_INT_MAX && abs( $right ) < PHP_INT_MAX ) {
			return (int) $left % (int) $right;
		}

		return fmod( $left, $right );
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function parse_unary( array $scope ): mixed {
		$this->ws();
		if ( $this->peek() === '!' ) {
			++$this->i;
			return ! $this->truthy( $this->parse_unary( $scope ) );
		}
		if ( $this->peek() === '-' ) {
			++$this->i;
			return -1 * (float) $this->parse_unary( $scope );
		}
		if ( $this->peek() === '+' ) {
			++$this->i;
			return + (float) $this->parse_unary( $scope );
		}

		return $this->parse_postfix( $this->parse_primary( $scope ), $scope );
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function parse_primary( array $scope ): mixed {
		$this->ws();
		$ch = $this->peek();
		if ( $ch === '' ) {
			return null;
		}

		/*
		 * `new Date()` — in practice always a copyright year.
		 * `{new Date().getFullYear()}` sits in the footer of two of the seven
		 * designs, and with no handling the whole child expression was dropped:
		 * the page rendered `© DevriX. All rights reserved` with the year
		 * simply missing. It was reported the whole time —
		 * `child-expression x1 {new Date().getFullYear()}` — but nothing read
		 * the report, which is exactly the gap this is part of closing.
		 *
		 * Evaluating to the real current year is what a visitor sees, so it is
		 * the right answer rather than a placeholder. It does mean the compiled
		 * output changes once a year, which is the same thing the design does.
		 *
		 * Only the no-argument form. `new Date("2024-01-01")` means a specific
		 * date and a guess there would be worse than a report.
		 */
		if ( preg_match( '/^new\s+Date\s*\(\s*\)/', substr( $this->src, $this->i, 24 ), $m ) === 1 ) {
			$this->i += strlen( $m[0] );

			return array( self::DATE => time() );
		}

		if ( $ch === '`' ) {
			return $this->parse_template( $scope );
		}
		if ( $ch === '"' || $ch === "'" ) {
			// A JS string literal: its escapes are part of its value.
			return self::decode_js_escapes( $this->read_quoted() );
		}
		if ( $ch === '[' ) {
			return $this->parse_array( $scope );
		}
		if ( $ch === '{' ) {
			return $this->parse_object( $scope );
		}
		if ( $ch === '(' ) {
			++$this->i;
			$this->ws();
			if ( $this->peek() === '<' ) {
				$html = $this->parse_element( $scope );
				$this->ws();
				if ( $this->peek() === ')' ) {
					++$this->i;
				}
				return array( '__html' => $html );
			}
			$value = $this->parse_expression( $scope );
			$this->ws();
			if ( $this->peek() === ')' ) {
				++$this->i;
			}
			return $value;
		}
		if ( $ch === '<' ) {
			return array( '__html' => $this->parse_element( $scope ) );
		}
		if ( ctype_digit( $ch ) || ( $ch === '.' && ctype_digit( (string) $this->peek_at( $this->i + 1 ) ) ) ) {
			return $this->read_number();
		}

		$ident = $this->read_ident();
		$value = match ( $ident ) {
			'true'  => true,
			'false' => false,
			'null', 'undefined' => null,
			'NaN'   => 0,
			default => $this->resolve_ident( $ident, $scope ),
		};

		$called = $this->peeks_call();
		if ( ( $value === self::JOINER || $value === self::MERGER ) && ! $called ) {
			// `className={cn}` — the sentinel is only a callee, never a value.
			return null;
		}
		/*
		 * A helper the ZIP never defined, being called. This is the shape that
		 * made `cn()` return null for every className on two designs, and it is
		 * invisible in the output: the attribute is simply empty. Setters are
		 * exempt — `setOpen(null)` in a handler body is expected to be a no-op.
		 */
		if ( null === $value && $ident !== '' && $called && ! isset( $this->setters[ $ident ] ) ) {
			$this->note_unevaluated( 'unbound-call', $ident . '()' );
		}

		return $value;
	}

	/** Whether a call's `(` follows the cursor, ignoring whitespace. */
	private function peeks_call(): bool {
		for ( $at = $this->i; $at < $this->len; $at++ ) {
			if ( ! ctype_space( $this->src[ $at ] ) ) {
				return $this->src[ $at ] === '(';
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function parse_postfix( mixed $left, array $scope ): mixed {
		while ( true ) {
			$this->ws();
			if ( $this->peek() === '?' && $this->peek_at( $this->i + 1 ) === '.' ) {
				$this->i += 2;
				$this->ws();
				if ( $this->peek() === '[' ) {
					++$this->i;
					$key  = $this->parse_expression( $scope );
					$this->ws();
					if ( $this->peek() === ']' ) {
						++$this->i;
					}
					$left = is_array( $left ) ? ( $left[ $this->stringify_attr( $key ) ] ?? ( $left[ (int) $key ] ?? null ) ) : null;
					continue;
				}
				$prop = $this->read_ident();
				$left = $this->member( $left, $prop );
				continue;
			}
			if ( $this->peek() === '.' ) {
				++$this->i;
				$prop = $this->read_ident();
				/*
				 * A `.map()` whose callback returns markup belongs to
				 * parse_map(), which renders the children; hand the cursor back
				 * for that case only. It used to rewind for every `.map(`,
				 * including one that is a link in a value chain, and there the
				 * rewind ended evaluation with the source still under the
				 * cursor: `{t.name.split(" ").map((w) => w[0]).join("").slice(0,
				 * 2)}` emitted the stringified array followed by
				 * `.map((w) => w[0]).join("").slice(0, 2)}` as visible page
				 * text, where an avatar's initials should have been.
				 */
				if ( $prop === 'map' && $this->peek() === '(' && $this->callback_returns_jsx() ) {
					$this->i -= strlen( $prop ) + 1;
					return $left;
				}
				if ( $this->peek() === '(' ) {
					$left = $this->call_method( $left, $prop, $scope );
					continue;
				}
				$left = $this->member( $left, $prop );
				continue;
			}
			if ( $this->peek() === '[' ) {
				++$this->i;
				$key = $this->parse_expression( $scope );
				$this->ws();
				if ( $this->peek() === ']' ) {
					++$this->i;
				}
				if ( is_array( $left ) ) {
					if ( is_int( $key ) || ( is_numeric( $key ) && (string) (int) $key === (string) $key ) ) {
						$left = $left[ (int) $key ] ?? null;
					} else {
						$left = $left[ $this->stringify_attr( $key ) ] ?? null;
					}
				} elseif ( is_string( $left ) ) {
					$left = $left[ (int) $key ] ?? '';
				} else {
					$left = null;
				}
				continue;
			}
			if ( $this->peek() === '(' ) {
				$args = $this->parse_args( $scope );
				$left = $this->call_value( $left, $args, $scope );
				continue;
			}
			break;
		}

		return $left;
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function parse_member_root( array $scope ): mixed {
		return $this->parse_postfix( $this->parse_primary( $scope ), $scope );
	}

	/**
	 * @param array<int, mixed> $args
	 * @param array<string, mixed> $scope
	 */
	private function call_value( mixed $fn, array $args, array $scope ): mixed {
		if ( $fn === self::JOINER ) {
			return $this->class_list( $args );
		}
		if ( $fn === self::MERGER ) {
			return Tailwind\Class_Merge::merge( $this->class_list( $args ) );
		}
		if ( $fn === self::OBJECT_KEYS || $fn === self::OBJECT_VALUES || $fn === self::OBJECT_ENTRIES ) {
			$subject = $args[0] ?? null;
			if ( ! is_array( $subject ) ) {
				return array();
			}
			if ( $fn === self::OBJECT_KEYS ) {
				return array_map( 'strval', array_keys( $subject ) );
			}
			if ( $fn === self::OBJECT_VALUES ) {
				return array_values( $subject );
			}

			$pairs = array();
			foreach ( $subject as $key => $value ) {
				$pairs[] = array( (string) $key, $value );
			}

			return $pairs;
		}
		if ( $fn === self::ARRAY_FROM ) {
			// Without a callback; call_array_from() takes the form with one.
			return self::array_from_items( $args[0] ?? null );
		}
		if ( $fn === self::ARRAY_IS_ARRAY ) {
			return is_array( $args[0] ?? null ) && array_is_list( $args[0] );
		}
		if ( $fn === self::USE_ID && $this->next_id !== null ) {
			// The id this component was entered with — see $next_id.
			$id            = $this->next_id;
			$this->next_id = null;

			return $id;
		}
		if ( $fn === self::USE_ID ) {
			/*
			 * React 18 spells these `:r0:` and React 19 `_R_0_`; neither is
			 * design data, and a converted page cannot reproduce either. What
			 * has to hold is that the id is unique on the page and that the
			 * label, the control and the description agree on it — so the
			 * value is ours and the RELATIONSHIP is the design's.
			 */
			++$this->ids;

			return 'dxai-id-' . $this->ids;
		}
		if ( $fn === self::CREATE_CONTEXT ) {
			++$this->contexts;
			$key                            = 'ctx-' . $this->contexts;
			$this->context_defaults[ $key ] = $args[0] ?? null;

			return array( self::CONTEXT => $key );
		}
		if ( $fn === self::USE_CONTEXT ) {
			$context = $args[0] ?? null;
			$key     = is_array( $context ) ? (string) ( $context[ self::CONTEXT ] ?? '' ) : '';
			if ( $key === '' ) {
				return null;
			}
			$stack = $this->context_values[ $key ] ?? array();

			return $stack === array() ? ( $this->context_defaults[ $key ] ?? null ) : end( $stack );
		}
		if ( is_array( $fn ) && ! empty( $fn['__fn'] ) ) {
			$local = is_array( $fn['scope'] ?? null ) ? $fn['scope'] : $scope;
			foreach ( (array) ( $fn['params'] ?? array() ) as $i => $param ) {
				$param = (string) $param;
				// A destructured parameter binds each of its names from the
				// argument object rather than the object itself.
				if ( str_starts_with( $param, '{' ) ) {
					$argument = $args[ $i ] ?? null;
					foreach ( self::destructured_names( $param ) as $key => $alias ) {
						$local[ $alias ] = is_array( $argument ) ? ( $argument[ $key ] ?? null ) : null;
					}
					continue;
				}
				$local[ $param ] = $args[ $i ] ?? null;
			}

			$body = trim( (string) ( $fn['body'] ?? '' ) );
			/*
			 * A STATEMENT body, not an expression: `() => { const a = …;
			 * return { … }; }`. Handing that to the expression evaluator read
			 * the leading `{` as an object literal and produced nonsense —
			 * which is what `useFormField()` is, and it is the function every
			 * part of a shadcn form asks for its ids. Bind the statements the
			 * same way a component's prelude is bound, then evaluate what it
			 * returns.
			 */
			if ( str_starts_with( $body, '{' ) ) {
				$inner = trim( substr( $body, 1, -1 ) );
				$local = $this->bind_locals( $inner, $local );
				if ( preg_match( '/\breturn\s+([\s\S]+?);?\s*$/', $inner, $returned ) !== 1 ) {
					return null;
				}

				// `return [x, y] as const;` — the TypeScript cast is not a value.
				return $this->eval_source( $this->strip_as_cast( (string) $returned[1] ), $local );
			}

			return $this->eval_source( $body, $local );
		}
		if ( $fn === 'String' ) {
			/*
			 * `String(false)` is `"false"`, not the empty string.
			 *
			 * stringify_attr() maps false and null to '' because that is what a
			 * JSX ATTRIBUTE means — `className={false}` renders no class — but
			 * String() is a conversion, and a design writes
			 * `aria-hidden={String(hidden)}` or `data-live={String(s.live)}`
			 * precisely to get the word. Measured: `String(state(0).live)`
			 * rendered an empty attribute where the real build has "false".
			 *
			 * `null` keeps the empty string on purpose. This evaluator has one
			 * null and JavaScript has two values — `String(null)` is `"null"`,
			 * `String(undefined)` is `"undefined"` — and it also uses null for
			 * "could not resolve". Printing a word for that would put visible
			 * text on the page where an unresolved value should stay invisible,
			 * which is the worse of the two wrongs.
			 */
			$value = $args[0] ?? '';
			if ( $value === false ) {
				return 'false';
			}
			if ( $value === true ) {
				return 'true';
			}

			return $this->stringify_attr( $value );
		}

		/* `cva(base, config)` — hold the recipe until it is called. */
		if ( $fn === 'cva' ) {
			return array(
				self::CVA => array(
					'base'   => $this->stringify_attr( $args[0] ?? '' ),
					'config' => is_array( $args[1] ?? null ) ? $args[1] : array(),
				),
			);
		}

		/*
		 * Calling a cva recipe: base classes, then one class per selected
		 * variant, then whatever `className` the caller passed. A variant the
		 * caller left out falls back to `defaultVariants`, which is how
		 * `<Button>` with no props still gets `bg-primary` and `h-9`.
		 *
		 * `compoundVariants` is deliberately not implemented yet; a recipe that
		 * uses one reports rather than silently dropping those classes.
		 */
		if ( is_array( $fn ) && isset( $fn[ self::CVA ] ) ) {
			$recipe   = (array) $fn[ self::CVA ];
			$config   = is_array( $recipe['config'] ?? null ) ? $recipe['config'] : array();
			$props    = is_array( $args[0] ?? null ) ? $args[0] : array();
			$variants = is_array( $config['variants'] ?? null ) ? $config['variants'] : array();
			$defaults = is_array( $config['defaultVariants'] ?? null ) ? $config['defaultVariants'] : array();

			$classes = array( (string) ( $recipe['base'] ?? '' ) );
			foreach ( $variants as $name => $choices ) {
				if ( ! is_array( $choices ) ) {
					continue;
				}
				$picked = $props[ $name ] ?? $defaults[ $name ] ?? null;
				if ( $picked === null || $picked === false ) {
					continue;
				}
				$key = is_bool( $picked ) ? ( $picked ? 'true' : 'false' ) : $this->stringify_attr( $picked );
				if ( isset( $choices[ $key ] ) ) {
					$classes[] = $this->stringify_attr( $choices[ $key ] );
				}
			}
			if ( isset( $config['compoundVariants'] ) ) {
				$this->note_unevaluated( 'cva-compound-variants', 'cva({ compoundVariants: … })' );
			}
			if ( isset( $props['className'] ) ) {
				$classes[] = $this->stringify_attr( $props['className'] );
			}
			if ( isset( $props['class'] ) ) {
				$classes[] = $this->stringify_attr( $props['class'] );
			}

			$out = array();
			foreach ( $classes as $chunk ) {
				foreach ( preg_split( '/\s+/', trim( (string) $chunk ) ) ?: array() as $token ) {
					if ( $token !== '' && ! in_array( $token, $out, true ) ) {
						$out[] = $token;
					}
				}
			}

			return implode( ' ', $out );
		}
		if ( is_string( $fn ) && str_starts_with( $fn, 'Math:' ) ) {
			$which = substr( $fn, 5 );
			$nums  = array_map( 'floatval', $args );
			return match ( $which ) {
				'min'   => $nums === array() ? 0 : min( $nums ),
				'max'   => $nums === array() ? 0 : max( $nums ),
				'floor' => (int) floor( $nums[0] ?? 0 ),
				'round' => (int) round( $nums[0] ?? 0 ),
				'ceil'  => (int) ceil( $nums[0] ?? 0 ),
				'pow'   => ( $nums[0] ?? 0 ) ** ( $nums[1] ?? 1 ),
				'abs'   => abs( $nums[0] ?? 0 ),
				default => $nums[0] ?? 0,
			};
		}

		return $fn;
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function call_method( mixed $left, string $method, array $scope ): mixed {
		if (
			is_array( $left )
			&& array_is_list( $left )
			&& in_array( $method, self::CALLBACK_METHODS, true )
			&& $this->args_hold_arrow()
		) {
			return $this->call_callback_method( $left, $method, $scope );
		}
		/*
		 * `Array.from({ length: 6 }, (_, k) => …)` — the callback is read
		 * from the source like a list method's, because parse_args() has no
		 * value for an arrow literal and the mapper arrived as nothing: every
		 * ring of RevOps' radar chart was an empty `points`.
		 */
		if ( is_array( $left ) && $method === 'from' && ( $left['from'] ?? null ) === self::ARRAY_FROM && $this->args_hold_arrow() ) {
			return $this->call_array_from( $scope );
		}

		$args = $this->parse_args( $scope );

		/*
		 * A member that IS a callable: `React.useId()`, `React.createContext()`,
		 * `React.useContext()`. Without this the method dispatch below did not
		 * recognise the name and the whole call evaluated to the object it was
		 * called on — so `const ctx = React.createContext(null)` bound `ctx` to
		 * React itself and every `useContext(ctx)` missed.
		 */
		if ( is_array( $left ) && isset( $left[ $method ] ) ) {
			$member = $left[ $method ];
			if ( is_string( $member ) && str_starts_with( $member, '__dxai_' ) ) {
				return $this->call_value( $member, $args, $scope );
			}
			if ( is_array( $member ) && ! empty( $member['__fn'] ) ) {
				return $this->call_value( $member, $args, $scope );
			}
		}

		/*
		 * Date getters. Only the ones a design plausibly renders — a year in a
		 * footer, occasionally a month or day. Anything else reports rather than
		 * returning a number that looks right and is not.
		 */
		if ( is_array( $left ) && isset( $left[ self::DATE ] ) ) {
			$when = (int) $left[ self::DATE ];
			switch ( $method ) {
				case 'getFullYear':
					return (int) gmdate( 'Y', $when );
				case 'getMonth':
					// JavaScript months are zero-based.
					return (int) gmdate( 'n', $when ) - 1;
				case 'getDate':
					return (int) gmdate( 'j', $when );
				case 'getTime':
					return $when * 1000;
				case 'toISOString':
					return gmdate( 'c', $when );
			}
			$this->note_unevaluated( 'date-method', 'new Date().' . $method . '()' );

			return null;
		}

		/*
		 * Math is a namespace, not an object with methods, and it arrives here
		 * because parse_postfix() sends every `.name(` to this function. The
		 * arithmetic is already implemented in call_value(), reached through the
		 * `Math:<fn>` sentinel that member() builds — but nothing routed a CALL
		 * to it, so `Math.round(…)` fell through to the receiver-back fallback
		 * at the end of this method and evaluated to the string 'Math'.
		 *
		 * That reached real pages. GTM Strategy Hub has
		 * `const pct = Math.round((num / total) * 100)` behind a progress bar
		 * and a label, and the imported page carried
		 * `style="width:Math%"` — invalid CSS, so the bar rendered 0px wide —
		 * plus the literal text `Math%` shown to visitors four times, where the
		 * design shows a percentage. unevaluated() reported nothing, and the
		 * compiled oracle reported the page pixel-perfect, because that oracle
		 * is built by this same compiler and carried the identical mistake. It
		 * took measuring against the design's real React build to see it.
		 */
		if ( $left === 'Math' ) {
			return $this->call_value( $this->member( $left, $method ), $args, $scope );
		}

		if ( $method === 'toLocaleString' ) {
			return is_numeric( $left ) ? number_format( (float) $left ) : $this->stringify_attr( $left );
		}
		if ( $method === 'padStart' ) {
			$len = (int) ( $args[0] ?? 0 );
			$pad = (string) ( $args[1] ?? ' ' );
			// An empty pad leaves the string as it is; str_pad() throws on one.
			return $pad === '' ? (string) $left : str_pad( (string) $left, $len, $pad, STR_PAD_LEFT );
		}
		if ( $method === 'join' && is_array( $left ) ) {
			return implode( (string) ( $args[0] ?? ',' ), array_map( array( $this, 'stringify_attr' ), $left ) );
		}
		if ( $method === 'length' ) {
			return is_countable( $left ) ? count( $left ) : strlen( (string) $left );
		}
		if ( $left === 'String' || $method === 'String' ) {
			return $this->stringify_attr( $args[0] ?? $left );
		}

		if ( is_array( $left ) && array_is_list( $left ) ) {
			$listed = $this->call_list_method( $left, $method, $args );
			if ( $listed !== self::UNHANDLED ) {
				return $listed;
			}
		}

		if ( is_string( $left ) || is_int( $left ) || is_float( $left ) ) {
			$scalar = $this->call_scalar_method( $left, $method, $args );
			if ( $scalar !== self::UNHANDLED ) {
				return $scalar;
			}
		}

		/*
		 * A callback-driven method the block above did not take (no arrow in
		 * the argument list — `items.map(renderRow)`): receiver back, nothing
		 * lost. That is sound for a list method, where the receiver IS the data
		 * and handing it back loses only the transform.
		 *
		 * On a scalar it is not sound, and it was the worst failure mode in the
		 * evaluator: an unknown method returned its receiver, so an expression
		 * became a plausible-looking wrong VALUE with nothing reported.
		 * `Math.round(…)` evaluating to the string 'Math' is the case that
		 * exposed it — `style="width:Math%"` and the text `Math%` on a live
		 * page, while unevaluated() stayed empty and every check passed.
		 *
		 * So a scalar receiver now reports. The value is still returned rather
		 * than nulled: a caller that prints it gets the same bytes as before,
		 * and callers that gate on unevaluated() can now see it. Turning it
		 * into a hard failure is a separate decision — this one only makes it
		 * visible, which is the property that was missing.
		 */
		if ( ! is_array( $left ) && ! is_object( $left ) && $left !== null ) {
			$this->note_unevaluated(
				'unknown-method',
				$this->stringify_attr( $left ) . '.' . $method . '(' . implode( ', ', array_map( array( $this, 'stringify_attr' ), $args ) ) . ')'
			);
		}

		return $left;
	}

	/**
	 * Whether the argument list under the cursor contains an arrow function.
	 *
	 * parse_args() cannot read one: it takes `(i)` for a parenthesised
	 * expression and then stalls on `=>`, which both loses the callback and
	 * strands the cursor mid-expression. So a call whose argument is an arrow
	 * has to be recognised before parse_args() ever sees it.
	 */
	private function args_hold_arrow(): bool {
		if ( $this->peek() !== '(' ) {
			return false;
		}
		$close = $this->match_pair( $this->src, $this->i );
		$inner = $close < 0
			? substr( $this->src, $this->i + 1 )
			: substr( $this->src, $this->i + 1, $close - $this->i - 1 );

		return str_contains( $inner, '=>' );
	}

	/**
	 * Whether the callback under the cursor returns markup rather than a value.
	 *
	 * Read-only lookahead over the argument list — the cursor must be left on
	 * the `(` for whichever handler takes the call.
	 */
	private function callback_returns_jsx(): bool {
		if ( $this->peek() !== '(' ) {
			return false;
		}
		$close = $this->match_pair( $this->src, $this->i );
		$inner = $close < 0
			? substr( $this->src, $this->i + 1 )
			: substr( $this->src, $this->i + 1, $close - $this->i - 1 );

		$arrow = strpos( $inner, '=>' );
		if ( false === $arrow ) {
			// `.map(renderRow)` — a named callback the compiler cannot inline.
			return false;
		}
		$body = ltrim( substr( $inner, $arrow + 2 ) );
		if ( $body === '' ) {
			return false;
		}
		if ( $body[0] === '<' ) {
			return true;
		}
		if ( $body[0] === '(' ) {
			return 1 === preg_match( '/^\(\s*(?:<|\{\s*\/\*)/', $body );
		}
		if ( $body[0] === '{' ) {
			// A statement body: markup only if something is returned as markup.
			return 1 === preg_match( '/\breturn\s*\(?\s*</', $body );
		}

		return false;
	}

	/**
	 * Evaluate a list method whose argument is an arrow callback.
	 *
	 * Before this, `filter`/`find`/`some`/`every` fell through to "return the
	 * receiver unchanged", so `const primary = nav.filter((i) => i.children)`
	 * and `const secondary = nav.filter((i) => !i.children …)` both kept all
	 * nine DevriX Elevate nav items: the header rendered its whole menu twice
	 * once the labels came back. Cursor discipline: the callback body is
	 * re-parsed once per item from the same offset, and evaluation resumes past
	 * the call's own `)` whatever the body did, so a second argument or a
	 * statement the compiler does not model cannot strand it.
	 *
	 * @param array<int, mixed>    $left
	 * @param array<string, mixed> $scope
	 */
	/**
	 * The items `Array.from(source)` yields: n undefined slots for an
	 * array-like `{ length: n }`, the values of a list, the characters of a
	 * string.
	 *
	 * @return array<int, mixed>
	 */
	private static function array_from_items( mixed $source ): array {
		if ( is_array( $source ) && ! array_is_list( $source ) && array_key_exists( 'length', $source ) ) {
			return array_fill( 0, max( 0, (int) $source['length'] ), null );
		}
		if ( is_array( $source ) ) {
			return array_values( $source );
		}
		if ( is_string( $source ) ) {
			return preg_split( '//u', $source, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		}

		return array();
	}

	/**
	 * `Array.from(source, (item, index) => …)`, cursor on the `(`.
	 *
	 * @param array<string, mixed> $scope
	 * @return array<int, mixed>
	 */
	private function call_array_from( array $scope ): array {
		$close  = $this->match_pair( $this->src, $this->i );
		$resume = $close < 0 ? $this->len : $close + 1;
		++$this->i;
		$this->ws();
		$source = $this->parse_expression( $scope );
		$this->ws();
		if ( $this->peek() === ',' ) {
			++$this->i;
		}
		$this->ws();
		$params = $this->parse_arrow_params();
		$this->ws();
		if ( substr( $this->src, $this->i, 2 ) === '=>' ) {
			$this->i += 2;
		}
		$body_at = $this->i;

		$results = array();
		foreach ( self::array_from_items( $source ) as $index => $item ) {
			$local = $scope;
			$this->bind_params( $local, $params, $item, $index );
			$this->i   = $body_at;
			$results[] = $this->eval_callback_body( $local );
		}
		$this->i = $resume;

		return $results;
	}

	private function call_callback_method( array $left, string $method, array $scope ): mixed {
		$close   = $this->match_pair( $this->src, $this->i );
		$resume  = $close < 0 ? $this->len : $close + 1;
		++$this->i;
		$this->ws();
		$params = $this->parse_arrow_params();
		$this->ws();
		if ( substr( $this->src, $this->i, 2 ) === '=>' ) {
			$this->i += 2;
		}
		$body_at = $this->i;

		$results = array();
		$index   = 0;
		foreach ( $left as $item ) {
			$local = $scope;
			$this->bind_params( $local, $params, $item, $index );
			$this->i   = $body_at;
			$results[] = $this->eval_callback_body( $local );
			++$index;
		}

		$this->i = $resume;
		$items   = array_values( $left );

		switch ( $method ) {
			case 'filter':
				$out = array();
				foreach ( $items as $at => $item ) {
					if ( $this->truthy( $results[ $at ] ?? null ) ) {
						$out[] = $item;
					}
				}
				return $out;
			case 'find':
				foreach ( $items as $at => $item ) {
					if ( $this->truthy( $results[ $at ] ?? null ) ) {
						return $item;
					}
				}
				return null;
			case 'findIndex':
				foreach ( $items as $at => $item ) {
					unset( $item );
					if ( $this->truthy( $results[ $at ] ?? null ) ) {
						return $at;
					}
				}
				return -1;
			case 'some':
				foreach ( $results as $result ) {
					if ( $this->truthy( $result ) ) {
						return true;
					}
				}
				return false;
			case 'every':
				foreach ( $results as $result ) {
					if ( ! $this->truthy( $result ) ) {
						return false;
					}
				}
				return true;
			case 'map':
				return $results;
			case 'flatMap':
				$out = array();
				foreach ( $results as $result ) {
					if ( is_array( $result ) && array_is_list( $result ) ) {
						$out = array_merge( $out, $result );
						continue;
					}
					$out[] = $result;
				}
				return $out;
			case 'forEach':
				return null;
		}

		/*
		 * `sort`/`reduce` fold across pairs or an accumulator, which one pass
		 * per item cannot express. The callback source is consumed either way,
		 * so the leak is closed; the order is reported as unevaluated rather
		 * than quietly presented as the design's.
		 */
		$this->note_unevaluated( 'list-callback', '.' . $method . '()' );

		return $left;
	}

	/**
	 * One evaluation of an arrow callback body, from the cursor.
	 *
	 * @param array<string, mixed> $scope
	 */
	private function eval_callback_body( array $scope ): mixed {
		$this->ws();
		if ( $this->peek() !== '{' ) {
			return $this->parse_expression( $scope );
		}

		// A block body carries its value out through `return`; eval_source()
		// stops at the statement's `;` on its own.
		$start = $this->i;
		$end   = $this->match_pair( $this->src, $start );
		$inner = $end < 0 ? substr( $this->src, $start + 1 ) : substr( $this->src, $start + 1, $end - $start - 1 );
		$this->i = $end < 0 ? $this->len : $end + 1;
		if ( ! preg_match( '/\breturn\b([\s\S]*)$/', $inner, $hit ) ) {
			return null;
		}

		return $this->eval_source( trim( $hit[1] ), $scope );
	}

	/**
	 * @param array<int, mixed> $left
	 * @param array<int, mixed> $args
	 */
	private function call_list_method( array $left, string $method, array $args ): mixed {
		$count = count( $left );

		switch ( $method ) {
			case 'slice':
				$start = (int) ( $args[0] ?? 0 );
				$start = $start < 0 ? max( 0, $count + $start ) : $start;
				$len   = null;
				if ( array_key_exists( 1, $args ) && null !== $args[1] ) {
					$end = (int) $args[1];
					$end = $end < 0 ? $count + $end : $end;
					$len = max( 0, $end - $start );
				}
				return array_values( array_slice( $left, $start, $len ) );
			case 'concat':
				$out = $left;
				foreach ( $args as $arg ) {
					if ( is_array( $arg ) ) {
						$out = array_merge( $out, array_values( $arg ) );
						continue;
					}
					$out[] = $arg;
				}
				return array_values( $out );
			case 'reverse':
				return array_reverse( $left );
			case 'includes':
				return in_array( $args[0] ?? null, $left, false );
			case 'indexOf':
				$at = array_search( $args[0] ?? null, $left, false );
				return false === $at ? -1 : (int) $at;
			case 'at':
				$at = (int) ( $args[0] ?? 0 );
				$at = $at < 0 ? $count + $at : $at;
				return $left[ $at ] ?? null;
			case 'flat':
				$out = array();
				foreach ( $left as $item ) {
					if ( is_array( $item ) && array_is_list( $item ) ) {
						$out = array_merge( $out, $item );
						continue;
					}
					$out[] = $item;
				}
				return array_values( $out );
		}

		return self::UNHANDLED;
	}

	/**
	 * @param array<int, mixed> $args
	 */
	private function call_scalar_method( string|int|float $left, string $method, array $args ): mixed {
		$text = (string) $left;

		switch ( $method ) {
			case 'toUpperCase':
				return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $text ) : strtoupper( $text );
			case 'toLowerCase':
				return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
			case 'trim':
				return trim( $text );
			case 'trimStart':
				return ltrim( $text );
			case 'trimEnd':
				return rtrim( $text );
			case 'slice':
			case 'substring':
				$start = (int) ( $args[0] ?? 0 );
				if ( ! array_key_exists( 1, $args ) || null === $args[1] ) {
					return $method === 'slice' ? substr( $text, $start ) : substr( $text, max( 0, $start ) );
				}
				$end = (int) $args[1];
				if ( $method === 'substring' ) {
					$start = max( 0, $start );
					$end   = max( 0, $end );
					if ( $start > $end ) {
						[ $start, $end ] = array( $end, $start );
					}
					return substr( $text, $start, $end - $start );
				}
				$len = $end < 0 ? strlen( $text ) + $end - $start : $end - $start;
				return substr( $text, $start, max( 0, $len ) );
			case 'charAt':
				return substr( $text, (int) ( $args[0] ?? 0 ), 1 );
			case 'startsWith':
				return str_starts_with( $text, (string) $this->stringify_attr( $args[0] ?? '' ) );
			case 'endsWith':
				return str_ends_with( $text, (string) $this->stringify_attr( $args[0] ?? '' ) );
			case 'includes':
				$needle = (string) $this->stringify_attr( $args[0] ?? '' );
				return $needle !== '' && str_contains( $text, $needle );
			case 'indexOf':
				$at = strpos( $text, (string) $this->stringify_attr( $args[0] ?? '' ) );
				return false === $at ? -1 : $at;
			case 'split':
				$sep = (string) $this->stringify_attr( $args[0] ?? '' );
				return $sep === '' ? preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) : explode( $sep, $text );
			case 'replace':
				$search = (string) $this->stringify_attr( $args[0] ?? '' );
				$pos    = strpos( $text, $search );
				if ( $search === '' || false === $pos ) {
					return $text;
				}
				return substr_replace( $text, (string) $this->stringify_attr( $args[1] ?? '' ), $pos, strlen( $search ) );
			case 'replaceAll':
				$search = (string) $this->stringify_attr( $args[0] ?? '' );
				return $search === '' ? $text : str_replace( $search, (string) $this->stringify_attr( $args[1] ?? '' ), $text );
			case 'repeat':
				return str_repeat( $text, max( 0, (int) ( $args[0] ?? 0 ) ) );
			case 'padEnd':
				$pad = (string) ( $args[1] ?? ' ' );
				// As padStart: an empty pad is a no-op, not a ValueError.
				return $pad === '' ? $text : str_pad( $text, (int) ( $args[0] ?? 0 ), $pad, STR_PAD_RIGHT );
			case 'toFixed':
				return number_format( (float) $left, (int) ( $args[0] ?? 0 ), '.', '' );
		}

		return self::UNHANDLED;
	}

	/**
	 * Whether the cursor moved since the last time this was asked, updating the
	 * mark. Every loop below drives itself off the cursor, and the routines they
	 * call — parse_expression(), read_ident() — legitimately return without
	 * consuming anything when they meet syntax the compiler does not model. When
	 * the character they stop on is not one the loop itself skips, nothing
	 * advances and the loop runs forever: one ZIP died on a 1 GB allocation in
	 * parse_args(), and another simply spun. Calling this at the top of a loop
	 * covers every path through the body, `continue` included.
	 *
	 * @param int $mark Cursor position seen on the previous iteration.
	 */
	private function advanced( int &$mark ): bool {
		if ( $this->i === $mark ) {
			return false;
		}
		$mark = $this->i;

		return true;
	}

	/**
	 * @param array<string, mixed> $scope
	 * @return array<int, mixed>
	 */
	private function parse_args( array $scope ): array {
		$args = array();
		if ( $this->peek() !== '(' ) {
			return $args;
		}
		++$this->i;
		$last = -1;
		while ( $this->i < $this->len && $this->peek() !== ')' ) {
			if ( ! $this->advanced( $last ) ) {
				break;
			}
			$this->ws();
			if ( $this->peek() === ')' ) {
				break;
			}
			$args[] = $this->parse_expression( $scope );
			$this->ws();
			if ( $this->peek() === ',' ) {
				++$this->i;
			}
		}
		if ( $this->peek() === ')' ) {
			++$this->i;
		}
		return $args;
	}

	private function member( mixed $left, string $prop ): mixed {
		if ( $prop === 'length' ) {
			if ( is_array( $left ) ) {
				return count( $left );
			}
			return strlen( (string) $left );
		}
		if ( is_array( $left ) ) {
			if ( array_key_exists( $prop, $left ) ) {
				return $left[ $prop ];
			}
			if ( isset( $left[ (int) $prop ] ) ) {
				return $left[ (int) $prop ];
			}
		}
		if ( $left === 'Math' ) {
			// The constants are values, not methods: `(Math.PI / 3) * k` in
			// RevOps' radar chart read the string "Math:PI" and every vertex
			// landed at the origin.
			if ( $prop === 'PI' ) {
				return M_PI;
			}
			if ( $prop === 'E' ) {
				return M_E;
			}
			return 'Math:' . $prop;
		}
		if ( is_string( $left ) && str_starts_with( $left, 'Math:' ) ) {
			return $left;
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function resolve_ident( string $ident, array $scope ): mixed {
		if ( $ident === 'String' ) {
			return 'String';
		}
		/*
		 * `cva` comes from class-variance-authority, so its source is never in
		 * the ZIP and it can only be modelled. Recognised by name, and only
		 * when nothing in scope has bound it — a design that defines its own
		 * `cva` still wins.
		 */
		if ( $ident === 'cva' && ! array_key_exists( 'cva', $scope ) && ! array_key_exists( 'cva', $this->globals ) ) {
			return 'cva';
		}
		if ( $ident === 'Math' ) {
			return 'Math';
		}
		if ( array_key_exists( $ident, $scope ) ) {
			return $scope[ $ident ];
		}
		if ( array_key_exists( $ident, $this->globals ) ) {
			return $this->globals[ $ident ];
		}
		/*
		 * Last, so a design that defines its own `cn` still wins. Unbound, it
		 * used to resolve to null and call_value() handed that straight back,
		 * so `className={cn("reveal", shown && "is-in", className)}` on
		 * primitives.tsx's Reveal produced `class=""` — the base class and the
		 * caller's own classes both gone, 47 elements on DevriX Elevate and 30
		 * on Growth Story Hub. It can never be loaded from the ZIP either:
		 * load_imports() only follows capitalised import names, and every
		 * design spells this helper lower-case.
		 */
		/*
		 * The React hooks this compiler can honestly run. `useId` mints a
		 * stable id, and createContext/useContext together carry a value down
		 * the tree — which is how shadcn's Form gets one id onto a label's
		 * `for`, the control's `id` and the description's `id`. Without them
		 * the label and its input were not associated at all.
		 *
		 * `React` itself resolves to an object holding the same markers, so
		 * both `useId()` and `React.useId()` reach them.
		 */
		if ( $ident === 'useId' ) {
			return self::USE_ID;
		}
		if ( $ident === 'createContext' ) {
			return self::CREATE_CONTEXT;
		}
		if ( $ident === 'useContext' ) {
			return self::USE_CONTEXT;
		}
		/*
		 * `Object.entries(DAYS).map(([key, rows]) => …)` is how a design turns
		 * a keyed data object into a list of sections, and with no support for
		 * it the whole expression was reported as an unevaluated child — three
		 * tab panels, nineteen nodes and 1104px of page, gone. `entries`
		 * returns pairs so the callback's array pattern destructures, which
		 * bind_destructured() already does for `.map((row, i) => …)`.
		 */
		if ( $ident === 'Object' ) {
			return array(
				'keys'    => self::OBJECT_KEYS,
				'values'  => self::OBJECT_VALUES,
				'entries' => self::OBJECT_ENTRIES,
			);
		}
		/*
		 * `Array.from({ length: 6 }, (_, k) => …)` is how a design draws a
		 * ring of six points or a row of N placeholders without a data array
		 * to map over. RevOps' radar chart builds every polygon that way, and
		 * with the call unknown each `points` attribute came out empty.
		 */
		if ( $ident === 'Array' ) {
			return array(
				'from'    => self::ARRAY_FROM,
				'isArray' => self::ARRAY_IS_ARRAY,
			);
		}
		if ( $ident === 'React' ) {
			return array(
				'useId'         => self::USE_ID,
				'createContext' => self::CREATE_CONTEXT,
				'useContext'    => self::USE_CONTEXT,
			);
		}

		if ( in_array( $ident, self::CLASS_MERGERS, true ) ) {
			return self::MERGER;
		}
		if ( in_array( $ident, self::CLASS_JOINERS, true ) ) {
			return self::JOINER;
		}
		/*
		 * An icon component carried through data rather than written inline.
		 * `const socials = [{ label: "LinkedIn", href: …, Icon: Linkedin }]`
		 * then `{socials.map(({ Icon }) => <Icon size={17} />)}` — SiteFooter
		 * on three designs. `Linkedin` resolved to null, so `<Icon/>` became
		 * an empty placeholder and the four social links rendered as empty
		 * boxes. The marker is inert in text and attribute positions; only
		 * emit_element() reads it.
		 */
		if ( isset( $this->lucide[ $ident ] ) ) {
			return array( self::ICON => $this->lucide[ $ident ] );
		}

		return null;
	}

	/**
	 * clsx semantics: keep every truthy string, flatten arrays, and take an
	 * object's keys whose value is truthy.
	 *
	 * Joining only. Conflict resolution is what separates `cn` from `clsx`,
	 * and it runs on the result of this — see self::MERGER and
	 * Tailwind\Class_Merge. It used to be dismissed here as "a runtime nicety
	 * Tailwind's own cascade already settles", which was wrong twice over: the
	 * cascade settles it only if our emitted order happens to match, and the
	 * class list itself is part of what a converted page has to reproduce.
	 *
	 * @param array<int, mixed> $args
	 */
	private function class_list( array $args ): string {
		$parts = array();
		foreach ( $args as $arg ) {
			if ( is_string( $arg ) ) {
				$parts[] = $arg;
				continue;
			}
			if ( is_int( $arg ) || is_float( $arg ) ) {
				continue;
			}
			if ( ! is_array( $arg ) ) {
				continue;
			}
			if ( isset( $arg['__html'] ) ) {
				continue;
			}
			foreach ( $arg as $key => $value ) {
				if ( is_int( $key ) ) {
					$parts[] = $this->class_list( array( $value ) );
					continue;
				}
				if ( $this->truthy( $value ) ) {
					$parts[] = (string) $key;
				}
			}
		}

		return trim( (string) preg_replace( '/\s+/', ' ', implode( ' ', $parts ) ) );
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function parse_array( array $scope ): array {
		$out = array();
		++$this->i;
		$last = -1;
		while ( $this->i < $this->len && $this->peek() !== ']' ) {
			if ( ! $this->advanced( $last ) ) {
				break;
			}
			$this->ws();
			if ( $this->peek() === ']' ) {
				break;
			}
			if ( substr( $this->src, $this->i, 3 ) === '...' ) {
				$this->i += 3;
				$spread = $this->parse_expression( $scope );
				if ( is_array( $spread ) ) {
					foreach ( $spread as $item ) {
						$out[] = $item;
					}
				}
			} else {
				$out[] = $this->parse_expression( $scope );
			}
			$this->ws();
			if ( $this->peek() === ',' ) {
				++$this->i;
			}
		}
		if ( $this->peek() === ']' ) {
			++$this->i;
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $scope
	 * @return array<string, mixed>
	 */
	private function parse_object( array $scope ): array {
		$out = array();
		++$this->i;
		$last = -1;
		while ( $this->i < $this->len && $this->peek() !== '}' ) {
			if ( ! $this->advanced( $last ) ) {
				break;
			}
			$this->ws();
			if ( $this->peek() === '}' ) {
				break;
			}
			if ( substr( $this->src, $this->i, 3 ) === '...' ) {
				$this->i += 3;
				$spread = $this->parse_expression( $scope );
				if ( is_array( $spread ) ) {
					$out = array_merge( $out, $spread );
				}
				$this->ws();
				if ( $this->peek() === ',' ) {
					++$this->i;
				}
				continue;
			}
			// Forward progress or stop — see parse_args().
			$before = $this->i;

			$key = '';
			if ( $this->peek() === '"' || $this->peek() === "'" ) {
				$key = self::decode_js_escapes( $this->read_quoted() );
			} elseif ( $this->peek() === '[' ) {
				++$this->i;
				$key = $this->stringify_attr( $this->parse_expression( $scope ) );
				$this->ws();
				if ( $this->peek() === ']' ) {
					++$this->i;
				}
			} else {
				$key = $this->read_ident();
			}
			$this->ws();
			$value = null;
			if ( $this->peek() === ':' ) {
				++$this->i;
				$value = $this->parse_expression( $scope );
			} elseif ( array_key_exists( $key, $scope ) ) {
				$value = $scope[ $key ];
			}
			if ( $key !== '' ) {
				$out[ $key ] = $value;
			}
			$this->ws();
			if ( $this->peek() === ',' ) {
				++$this->i;
			}

			if ( $this->i === $before ) {
				break;
			}
		}
		if ( $this->peek() === '}' ) {
			++$this->i;
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function parse_template( array $scope ): string {
		++$this->i;
		$out = '';
		while ( $this->i < $this->len && $this->peek() !== '`' ) {
			if ( $this->peek() === '$' && $this->peek_at( $this->i + 1 ) === '{' ) {
				$this->i += 2;
				$out .= $this->stringify_attr( $this->parse_expression( $scope ) );
				$this->ws();
				if ( $this->peek() === '}' ) {
					++$this->i;
				}
				continue;
			}
			if ( $this->peek() === '\\' ) {
				++$this->i;
				$out .= $this->peek();
				++$this->i;
				continue;
			}
			$out .= $this->peek();
			++$this->i;
		}
		if ( $this->peek() === '`' ) {
			++$this->i;
		}
		return $out;
	}

	private function read_number(): float|int {
		$start = $this->i;
		while ( $this->i < $this->len && preg_match( '/[0-9._]/', $this->src[ $this->i ] ) ) {
			++$this->i;
		}
		$raw = str_replace( '_', '', substr( $this->src, $start, $this->i - $start ) );
		return str_contains( $raw, '.' ) ? (float) $raw : (int) $raw;
	}

	private function read_ident(): string {
		$this->ws();
		$start = $this->i;
		if ( $this->i < $this->len && preg_match( '/[A-Za-z_$]/', $this->src[ $this->i ] ) ) {
			++$this->i;
			while ( $this->i < $this->len && preg_match( '/[A-Za-z0-9_$]/', $this->src[ $this->i ] ) ) {
				++$this->i;
			}
		}
		return substr( $this->src, $start, $this->i - $start );
	}

	private function read_tag_name(): string {
		$this->ws();
		$start = $this->i;
		while ( $this->i < $this->len && preg_match( '/[A-Za-z0-9._-]/', $this->src[ $this->i ] ) ) {
			++$this->i;
		}
		return substr( $this->src, $start, $this->i - $start );
	}

	/**
	 * @param array<int, string> $ops
	 */
	private function read_op( array $ops ): string {
		foreach ( $ops as $op ) {
			if ( substr( $this->src, $this->i, strlen( $op ) ) === $op ) {
				$next = $this->src[ $this->i + strlen( $op ) ] ?? '';
				if ( ( $op === '<' || $op === '>' ) && ( $next === '=' || $next === '<' || $next === '>' ) ) {
					continue;
				}
				$this->i += strlen( $op );
				return $op;
			}
		}
		return '';
	}

	private function skip_value(): void {
		$depth = 0;
		while ( $this->i < $this->len ) {
			$ch = $this->peek();
			if ( $ch === '{' || $ch === '(' || $ch === '[' ) {
				++$depth;
			} elseif ( $ch === '}' || $ch === ')' || $ch === ']' ) {
				if ( $depth === 0 ) {
					return;
				}
				--$depth;
			} elseif ( $depth === 0 && ( $ch === ',' || $ch === ';' ) ) {
				return;
			} elseif ( $depth === 0 && $ch === ':' ) {
				return;
			} elseif ( $depth === 0 && $ch === '?' ) {
				return;
			}
			if ( $ch === '"' || $ch === "'" || $ch === '`' ) {
				$this->read_quoted_any( $ch );
				continue;
			}
			++$this->i;
		}
	}

	private function skip_balanced(): void {
		$open = $this->peek();
		$close = $open === '{' ? '}' : ( $open === '[' ? ']' : ( $open === '(' ? ')' : '' ) );
		if ( $close === '' ) {
			return;
		}
		$depth = 0;
		while ( $this->i < $this->len ) {
			$ch = $this->peek();
			if ( $ch === '"' || $ch === "'" || $ch === '`' ) {
				$this->read_quoted_any( $ch );
				continue;
			}
			if ( $ch === $open ) {
				++$depth;
			} elseif ( $ch === $close ) {
				--$depth;
				++$this->i;
				if ( $depth === 0 ) {
					return;
				}
				continue;
			}
			++$this->i;
		}
	}

	private function skip_type_ann(): void {
		$this->ws();
		if ( $this->peek() === '?' ) {
			++$this->i;
			$this->ws();
		}
		if ( $this->peek() !== ':' ) {
			return;
		}
		++$this->i;
		$depth = 0;
		while ( $this->i < $this->len ) {
			$ch = $this->peek();
			if ( $ch === '<' || $ch === '{' || $ch === '[' || $ch === '(' ) {
				++$depth;
			} elseif ( $ch === '>' || $ch === '}' || $ch === ']' || $ch === ')' ) {
				if ( $depth === 0 ) {
					return;
				}
				--$depth;
			} elseif ( $depth === 0 && ( $ch === ',' || $ch === '=' || $ch === ')' ) ) {
				return;
			}
			++$this->i;
		}
	}

	private function skip_block_comment(): void {
		$end = strpos( $this->src, '*/', $this->i + 2 );
		$this->i = false === $end ? $this->len : $end + 2;
	}

	private function read_quoted_any( string $q ): string {
		if ( $q === '`' ) {
			return $this->parse_template( array() );
		}
		return self::decode_js_escapes( $this->read_quoted() );
	}

	private function ws(): void {
		while ( $this->i < $this->len ) {
			$ch = $this->src[ $this->i ];
			if ( ctype_space( $ch ) ) {
				++$this->i;
				continue;
			}
			if ( $ch === '/' && ( $this->i + 1 ) < $this->len && $this->src[ $this->i + 1 ] === '/' ) {
				$nl = strpos( $this->src, "\n", $this->i );
				$this->i = false === $nl ? $this->len : $nl + 1;
				continue;
			}
			if ( $ch === '/' && ( $this->i + 1 ) < $this->len && $this->src[ $this->i + 1 ] === '*' ) {
				$this->skip_block_comment();
				continue;
			}
			break;
		}
	}

	private function peek(): string {
		return $this->i < $this->len ? $this->src[ $this->i ] : '';
	}

	private function peek_at( int $i ): string {
		return $i < $this->len ? $this->src[ $i ] : '';
	}

	private function truthy( mixed $value ): bool {
		if ( is_array( $value ) ) {
			if ( array_key_exists( '__open', $value ) ) {
				return (bool) $value['__open'];
			}
			if ( ! empty( $value['__enum_gate'] ) ) {
				return false;
			}
			if ( isset( $value['__html'] ) ) {
				return trim( (string) $value['__html'] ) !== '';
			}
			return $value !== array();
		}
		return (bool) $value;
	}

	private function stringify( mixed $value ): string {
		if ( is_array( $value ) && isset( $value['__html'] ) ) {
			return (string) $value['__html'];
		}
		if ( is_array( $value ) && isset( $value[ self::ICON ] ) ) {
			return '';
		}
		if ( $value === null || $value === false ) {
			return '';
		}
		if ( $value === true ) {
			return '';
		}
		if ( is_array( $value ) ) {
			if ( ! empty( $value['__enum_gate'] ) ) {
				return ! empty( $value['__open'] ) ? 'true' : 'false';
			}
			$html = '';
			foreach ( $value as $item ) {
				$html .= $this->stringify( $item );
			}
			return $html;
		}

		return $this->esc_text( (string) $value );
	}

	private function stringify_attr( mixed $value ): string {
		if ( is_array( $value ) && isset( $value['__html'] ) ) {
			return wp_strip_all_tags( (string) $value['__html'] );
		}
		if ( is_array( $value ) && isset( $value[ self::ICON ] ) ) {
			return '';
		}
		if ( is_array( $value ) && ! empty( $value['__enum_gate'] ) ) {
			return ! empty( $value['__open'] ) ? 'true' : 'false';
		}
		if ( $value === true ) {
			return 'true';
		}
		if ( $value === false || $value === null ) {
			return '';
		}
		// An asset binding: `{ url: … }` as produced by bind_assets(). Used
		// directly it means its address, not the string "url" the class-map
		// branch below would produce.
		if ( is_array( $value ) && isset( $value['url'] ) && is_string( $value['url'] ) ) {
			return $value['url'];
		}
		if ( is_array( $value ) ) {
			$parts = array();
			foreach ( $value as $k => $v ) {
				if ( is_int( $k ) ) {
					$parts[] = $this->stringify_attr( $v );
				} elseif ( $this->truthy( $v ) ) {
					$parts[] = (string) $k;
				}
			}
			return trim( implode( ' ', $parts ) );
		}
		if ( is_float( $value ) && floor( $value ) === $value ) {
			return (string) (int) $value;
		}

		return (string) $value;
	}

	/**
	 * Turn the class gates found in this element's className expression into
	 * markup the runtime can read back.
	 *
	 * The marker is a class, `dxai-cls-<state>[--<value>]`, because a class is
	 * what already survives the block round trip — Html_To_Blocks keeps any
	 * element carrying a `dxai-` marker or a `data-dxai-*` attribute as its
	 * own source HTML. The class LISTS ride in `data-dxai-on` /
	 * `data-dxai-off`, which a class name cannot hold: half of what these
	 * designs project is a Tailwind utility with characters
	 * sanitize_html_class() strips — `grid-rows-[1fr]`, `text-ink/70`,
	 * `md:text-2xl`. Both attributes are plain `data-*`, so
	 * Html_To_Blocks::data_map() carries them in the `dxaiData` object the
	 * blocks already declare and both emitters already write; no block
	 * attribute had to be added.
	 *
	 * Deliberately NOT named `dxai-on-*`: that marker means "hidden until the
	 * state is on" to the existing runtime, which would hide every element a
	 * class projection touched.
	 *
	 * @param array<string, mixed> $attrs
	 * @return array{0:string, 1:array<string, mixed>}
	 */
	private function attach_class_projection( string $class, array $attrs ): array {
		$gates = is_array( $attrs['__cls'] ?? null ) ? $attrs['__cls'] : array();
		unset( $attrs['__cls'] );
		if ( $gates === array() ) {
			return array( $class, $attrs );
		}

		$flag = (string) array_key_first( $gates );
		$slot = $gates[ $flag ];
		if ( count( $gates ) > 1 ) {
			// One element, two independent states. Nothing in the corpus does
			// this; report it rather than project the wrong one silently.
			$this->note_unevaluated( 'class-gate-multi', implode( ' ', array_keys( $gates ) ) );
		}

		$on  = implode( ' ', (array) ( $slot['on'] ?? array() ) );
		$off = implode( ' ', (array) ( $slot['off'] ?? array() ) );
		if ( trim( $on ) === '' && trim( $off ) === '' ) {
			return array( $class, $attrs );
		}

		$class              = trim( $class . ' dxai-cls-' . $flag );
		$attrs['className'] = $class;
		// Written only when non-empty: text_attrs() drops an empty attribute,
		// and a block whose stored markup carries one save() will not write
		// opens invalid on the first edit.
		if ( trim( $on ) !== '' ) {
			$attrs['data-dxai-on'] = $on;
		}
		if ( trim( $off ) !== '' ) {
			$attrs['data-dxai-off'] = $off;
		}

		/*
		 * And how the state this projection reads is driven.
		 *
		 * The class lists above say what to add and remove; these say when. A
		 * derived boolean carries its members so the runtime can recompute it —
		 * `data-dxai-any="dark:solid,open"`, true when any member is — and any
		 * member the design drives from the scroll offset carries its threshold
		 * in `data-dxai-scroll`, which Motion_Runtime has watched all along.
		 * Written on this element rather than the page root because this is the
		 * element that survives to the page: the runtime reads both attributes
		 * from anywhere inside it.
		 */
		$scrolled = array();
		if ( isset( $this->derived[ $flag ] ) ) {
			$attrs['data-dxai-any'] = $flag . ':' . implode( ',', $this->derived[ $flag ] );
			foreach ( $this->derived[ $flag ] as $member ) {
				if ( isset( $this->scroll_states[ $member ] ) ) {
					$scrolled[] = $member . ':' . self::px( $this->scroll_states[ $member ] );
				}
			}
		} elseif ( isset( $this->scroll_states[ $flag ] ) ) {
			$scrolled[] = $flag . ':' . self::px( $this->scroll_states[ $flag ] );
		}
		if ( $scrolled !== array() ) {
			$attrs['data-dxai-scroll'] = implode( ' ', $scrolled );
		}

		return array( $class, $attrs );
	}

	/** A threshold as the runtime reads it: `24`, not `24.0`. */
	private static function px( float $value ): string {
		return floor( $value ) === $value ? (string) (int) $value : (string) $value;
	}

	/**
	 * Accordion answer panels use style ternaries, not `open && <div/>`.
	 * Attach the same dxai-on-* hooks so Motion_Runtime can open/close them.
	 *
	 * @param array<string, mixed> $attrs
	 * @param array<string, mixed> $scope
	 * @return array<string, mixed>
	 */
	private function attach_accordion_panel( array $attrs, array $scope ): array {
		$style       = $attrs['style'] ?? null;
		$style_gates = is_array( $attrs['__style_gate'] ?? null ) ? $attrs['__style_gate'] : array();
		unset( $attrs['__style_gate'] );
		$css = is_array( $style ) ? $this->style_from_object( $style ) : ( is_string( $style ) ? $style : '' );
		if ( $css === '' || ( ! str_contains( $css, 'grid-template-rows' ) && ! str_contains( $css, 'max-height' ) ) ) {
			return $attrs;
		}
		$gate = null;
		foreach ( $scope as $value ) {
			if ( is_array( $value ) && ! empty( $value['__enum_gate'] ) && isset( $value['state'], $value['value'] ) ) {
				$gate = $value;
				break;
			}
		}

		$class   = trim( $this->stringify_attr( $attrs['className'] ?? $attrs['class'] ?? '' ) );
		$on_when = true;
		if ( $gate !== null ) {
			$flag = $this->enum_toggle_flag( (string) $gate['state'], (string) $gate['value'] );
			$open = ! empty( $gate['__open'] );
		} else {
			/*
			 * No comparison to key on — a bare boolean drives the size, as in
			 * `maxHeight: stripDismissed ? 0 : 44`. Take the gate the style
			 * expression itself recorded and work out which arm opens.
			 */
			$flag = '';
			$open = false;
			foreach ( $style_gates as $row ) {
				$arm = self::open_arm( $row['on'], $row['off'] );
				if ( null === $arm ) {
					continue;
				}
				$flag    = (string) $row['flag'];
				$on_when = $arm;
				// Whether it stands open right now follows from the state the
				// page was compiled at, so the rendered declaration and the
				// marker cannot disagree — and `hidden` is never added to
				// something the design is currently showing.
				$open    = $this->toggle_is_expanded( $flag, $scope ) === $arm;
				break;
			}
			if ( $flag === '' ) {
				return $attrs;
			}
		}

		$class = trim( $class . ' dxai-' . ( $on_when ? 'on' : 'off' ) . '-' . $flag );
		if ( ! $open ) {
			$class = trim( $class . ' hidden' );
		}
		$attrs['className'] = $class;
		if ( is_array( $style ) ) {
			$attrs['data-dxai-accordion'] = '1';
			if ( isset( $style['gridTemplateRows'] ) || isset( $style['grid-template-rows'] ) ) {
				$attrs['data-open-rows']  = '1fr';
				$attrs['data-closed-rows'] = '0fr';
			}
			if ( isset( $style['maxHeight'] ) || isset( $style['max-height'] ) ) {
				$attrs['data-open-max'] = '400px';
			}
		}

		return $attrs;
	}

	private function style_from_object( mixed $value ): string {
		if ( ! is_array( $value ) ) {
			return $this->stringify_attr( $value );
		}
		$parts = array();
		foreach ( $value as $k => $v ) {
			if ( $v === null || $v === false ) {
				continue;
			}
			$key = (string) $k;
			$key = strtolower( (string) preg_replace( '/[A-Z]/', '-$0', $key ) );
			$parts[] = $key . ':' . $this->css_style_value( $key, $v );
		}
		return implode( ';', $parts );
	}

	/**
	 * React appends `px` to unitless numbers for length properties; mirror that
	 * so inline styles like `height: 60` actually paint in HTML.
	 */
	private function css_style_value( string $property, mixed $value ): string {
		if ( is_array( $value ) ) {
			return $this->stringify_attr( $value );
		}
		if ( is_bool( $value ) || $value === null ) {
			return $this->stringify_attr( $value );
		}
		if ( is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) ) {
			if ( $this->css_unitless_number( $property ) ) {
				return is_float( $value ) && floor( $value ) === $value
					? (string) (int) $value
					: (string) $value;
			}
			$num = is_float( $value ) && floor( $value ) === $value ? (int) $value : $value;

			return $num . 'px';
		}

		return $this->stringify_attr( $value );
	}

	private function css_unitless_number( string $property ): bool {
		$property = strtolower( $property );
		static $unitless = array(
			'animation-iteration-count' => true,
			'aspect-ratio'              => true,
			'border-image-slice'        => true,
			'border-image-width'        => true,
			'column-count'              => true,
			'flex'                      => true,
			'flex-grow'                 => true,
			'flex-shrink'               => true,
			'font-weight'               => true,
			'grid-column'               => true,
			'grid-column-end'           => true,
			'grid-column-start'         => true,
			'grid-row'                  => true,
			'grid-row-end'              => true,
			'grid-row-start'            => true,
			'line-height'               => true,
			'opacity'                   => true,
			'order'                     => true,
			'orphans'                   => true,
			'scale'                     => true,
			'tab-size'                  => true,
			'widows'                    => true,
			'z-index'                   => true,
			'zoom'                      => true,
		);

		return isset( $unitless[ $property ] );
	}

	/**
	 * JSX's own rule for literal text between tags and expressions.
	 *
	 * HTML collapses a whitespace run to one space; JSX deletes it when it
	 * spans a line. The two disagree on exactly the shape every formatted
	 * component is written in:
	 *
	 *     {w}
	 *     {i < words.length - 1 ? " " : ""}
	 *
	 * React renders those two expressions adjacent. Emitting the source's
	 * newline and indent instead left the browser to collapse it, so every word
	 * of DevriX Elevate's heading reveal came out as `360  ` against the
	 * real build's `360 ` — 24 nodes with different words, 67 with
	 * different widths and 878px of error, all from one space.
	 *
	 * This is Babel's cleanJSXElementLiteralChild, transcribed: tabs become
	 * spaces, leading spaces go on every line but the first, trailing spaces on
	 * every line but the last, empty results are dropped, and each surviving
	 * line but the last gains one space. A single-line run is left exactly as
	 * it is, which is what keeps the space in `{a} {b}`.
	 */
	private static function jsx_text( string $text ): string {
		$lines = preg_split( '/\r\n|\n|\r/', $text );
		if ( ! is_array( $lines ) || count( $lines ) < 2 ) {
			return $text;
		}

		$last_non_empty = 0;
		foreach ( $lines as $index => $line ) {
			if ( preg_match( '/[^ \t]/', $line ) === 1 ) {
				$last_non_empty = $index;
			}
		}

		$out   = '';
		$count = count( $lines );
		foreach ( $lines as $index => $line ) {
			$trimmed = str_replace( "\t", ' ', $line );
			if ( $index !== 0 ) {
				$trimmed = (string) preg_replace( '/^ +/', '', $trimmed );
			}
			if ( $index !== $count - 1 ) {
				$trimmed = (string) preg_replace( '/ +$/', '', $trimmed );
			}
			if ( $trimmed === '' ) {
				continue;
			}
			if ( $index !== $last_non_empty ) {
				$trimmed .= ' ';
			}
			$out .= $trimmed;
		}

		return $out;
	}

	private function esc_text( string $text ): string {
		return htmlspecialchars( $text, ENT_NOQUOTES | ENT_HTML5, 'UTF-8', false );
	}
}

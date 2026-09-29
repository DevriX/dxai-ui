# JSX → Gutenberg: architecture of an autonomous conversion pipeline

This is the design for turning a JSX/TSX component tree into registered, editable
Gutenberg blocks and pages built from them, offline, deterministically, with an AI
fallback that repairs only what the offline path could not evaluate.

It is written against what this repository has already measured. Where a decision
here looks arbitrary, it is usually the residue of a specific failure, and the
failure is named. Three of them govern almost everything below:

1. **A block whose `save()` cannot reproduce what the design put on the element is
   the wrong block for that element — and the wrapper the block adds is part of the
   layout.** Derived from 33 blocks across 5 pages coming back invalid because a
   `core/group` cannot reproduce a stray `data-reveal="1"`.
2. **Cascade order is part of correctness.** Two utilities writing the same property
   must be emitted in the reference compiler's order. Ours tie-broke on markup order
   and 10 nodes across 3 designs rendered the wrong colour, invisible to every
   geometry check we had.
3. **A verification instrument that reports a false failure is worse than none.**
   Every check below has a stated false-positive rate or is explicitly advisory.

---

## Part 1 — The Gutenberg standard, file by file

A block built with `@wordpress/create-block` is a directory of five artifacts. What
follows is the lifecycle of each and, for every one, what it means for a pipeline
whose output must reproduce a design exactly.

### 1.1 `block.json` — the metadata contract

`block.json` is the single source of truth. PHP reads it via
`register_block_type( __DIR__ . '/build/my-block' )`; the build step reads it to
discover entry points; the editor reads the same JSON over the REST
`/wp/v2/block-types` endpoint. Registering the same block twice (once in JS with
`registerBlockType( name, settings )` and once from JSON) is the most common source
of attribute drift, because the two definitions then disagree.

```json
{
  "$schema": "https://schemas.wp.org/trunk/block.json",
  "apiVersion": 3,
  "name": "dxai-ui/hero",
  "title": "Hero",
  "category": "design",
  "textdomain": "dxai-ui",
  "attributes": {
    "tagName":   { "type": "string", "default": "section" },
    "className": { "type": "string", "default": "" },
    "dxaiStyle": { "type": "string", "default": "" },
    "dxaiData":  { "type": "object", "default": {} },
    "heading":   { "type": "string", "source": "html", "selector": "h1" },
    "content":   { "type": "rich-text", "source": "rich-text", "selector": "p" }
  },
  "supports": {
    "className": false,
    "customClassName": false,
    "html": false,
    "anchor": false,
    "splitting": false
  },
  "editorScript": "file:./index.js",
  "editorStyle":  "file:./index.css",
  "style":        "file:./style-index.css",
  "viewScript":   "file:./view.js"
}
```

**`apiVersion`.** Version 3 renders the editor canvas inside an **iframe**. This is
the single most consequential field for our purposes and it cuts both ways:

- *In our favour:* the design's stylesheet no longer leaks into the admin chrome.
  An iframe is a real document boundary, so a design that resets `*` or sets
  `html { font-size: 18px }` cannot reach the sidebar, the toolbar, or any other
  block. This is a stronger isolation guarantee than any prefixing scheme.
- *Against us:* anything not enqueued through `editorStyle` / `editorScript` does
  not exist inside that iframe. Styles injected by a `<style>` tag appended to
  `document.head` from an editor script land in the *outer* document and silently do
  nothing. Fonts must be enqueued for the iframe too, or the editor measures text at
  fallback metrics while the front end measures it correctly.

**`attributes`.** Three storage strategies, and the choice is not stylistic:

| `source` | Where the value lives | Consequence |
|---|---|---|
| *(omitted)* | In the block comment as JSON | Survives anything; bloats `post_content`; not editable as text in place |
| `"html"` | In the markup, read back by `selector` | Round-trips; the markup is the storage; requires the selector to be stable |
| `"rich-text"` | In the markup as rich text | Editable in place with formatting; inline markup is preserved through `core/unknown`'s wildcard format |
| `"attribute"` | An HTML attribute named by `attribute` | Best for `href`, `src`, `alt` |
| `"query"` | A repeated structure | For lists of sub-items without inner blocks |

For a conversion pipeline the rule is: **anything the design authored belongs in the
markup (`html` / `rich-text` / `attribute`); anything the pipeline decided belongs
in the comment.** That keeps `post_content` close to the design's own bytes and
keeps the diff against the design readable.

**`supports`.** For hand-authored blocks, `supports` is a gift: opting into
`typography`, `color` and `spacing` gives you an inspector and generates the classes
and inline styles for free. **For a pixel-perfect conversion pipeline every one of
them must be off.** They are a drift engine:

- `supports.className: true` appends `wp-block-dxai-ui-hero` to the saved markup. If
  a stylesheet ever targets that class, the design's box changes.
- `supports.color`, `spacing`, `typography` let the inspector write `theme.json`
  values into a design that already specifies them. The user gets two sources of
  truth for one padding.
- `supports.html: true` offers "Edit as HTML", which is how a user turns a valid
  block into an invalid one.
- `supports.anchor: true` writes an `id`, and the design may already use `id` as a
  scroll target or a `for`/`id` pair.

So the pipeline's blocks declare `{ className: false, customClassName: false,
html: false, anchor: false, splitting: false }` and carry the design's own classes
in an explicit `className` **attribute** instead. `splitting: false` matters
specifically for text blocks: without it, pressing Enter inside a converted run
splits it into two blocks and the design's single inline formatting context becomes
two elements.

### 1.2 `edit.js` — the editor component

```jsx
import { useBlockProps, InspectorControls, BlockControls, RichText } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToolbarGroup, ToolbarButton } from '@wordpress/components';

export default function Edit( { attributes, setAttributes } ) {
    const { tagName, className, dxaiStyle, content } = attributes;

    // useBlockProps MUST be spread onto the outermost rendered element. It supplies
    // the data-block id, the selected/hover classes, and the click handling that
    // makes the block selectable. A block that does not spread it cannot be
    // selected by clicking it, which reads to the user as a broken block.
    const blockProps = useBlockProps( {
        className,                      // the design's classes, not a generated one
        style: parseStyle( dxaiStyle ), // inline style the design authored
    } );

    return (
        <>
            {/* Sidebar. Settings about the block that are not the content. */}
            <InspectorControls>
                <PanelBody title="Element">
                    <TextControl
                        label="Tag"
                        value={ tagName }
                        onChange={ ( v ) => setAttributes( { tagName: v } ) }
                    />
                </PanelBody>
            </InspectorControls>

            {/* Toolbar. Actions on the block, shown above it in the canvas. */}
            <BlockControls>
                <ToolbarGroup>
                    <ToolbarButton icon="editor-code" label="Reset classes"
                        onClick={ () => setAttributes( { className: '' } ) } />
                </ToolbarGroup>
            </BlockControls>

            <RichText
                { ...blockProps }
                tagName={ tagName }
                value={ content }
                onChange={ ( v ) => setAttributes( { content: v } ) }
            />
        </>
    );
}
```

Three rules that are not obvious:

- **`useBlockProps` is not optional and its return value is not inert.** It contains
  a `ref` the editor uses to measure the block, plus `className` entries the editor
  toggles. Spreading it onto a `<div>` you then wrap in another `<div>` gives the
  editor the wrong box for drag-and-drop.
- **`InspectorControls` and `BlockControls` render through portals.** They appear in
  the sidebar and toolbar regardless of where you put them in the tree, so put them
  first for readability. They render only while the block is selected.
- **`useInnerBlocksProps` replaces `<InnerBlocks>` under API v3** when the container
  needs its own props:

  ```jsx
  const inner = useInnerBlocksProps( blockProps, {
      allowedBlocks: [ 'dxai-ui/text', 'core/heading', 'core/image' ],
      template: [ [ 'core/heading', { level: 2 } ], [ 'dxai-ui/text' ] ],
      templateLock: false,
      orientation: 'vertical',
  } );
  return <div { ...inner } />;
  ```

  `allowedBlocks` is the mechanism that stops a converted design filling up with
  blocks whose markup it cannot express.

### 1.3 `save.js` versus `render.php` — the decision that matters most

This is where a conversion pipeline lives or dies, so it deserves the mechanism in
full.

**Static (`save.js`).** `save()` returns markup. On save, the editor serializes it
into `post_content` between block comments. **On load, the editor runs `save()`
again with the stored attributes and compares its output to the stored markup.** If
they differ, the block is marked invalid and the user is offered "Attempt Block
Recovery". The comparison is not textual equality — `packages/blocks/src/api/validation`
tokenizes both sides and tolerates attribute order, boolean attribute spellings,
whitespace between tokens, and character-reference differences — but it does not
tolerate a missing attribute or an extra element.

This is the entire content of governing rule 1. Concretely, if the design wrote

```html
<div class="card" data-reveal="1" aria-expanded="false">
```

then a `core/group` will store that markup and, on load, regenerate
`<div class="wp-block-group card">` — no `data-reveal`, no `aria-expanded`, plus a
generated class. Three differences, one invalid block. The fix is never to strip the
attributes from the design; it is to declare them and emit them:

```jsx
export default function save( { attributes } ) {
    const { tagName: Tag, className, dxaiStyle, dxaiData, ariaExpanded, content } = attributes;
    const props = useBlockProps.save( {
        className,
        style: parseStyle( dxaiStyle ),
        ...dataAttrs( dxaiData ),                                  // data-*
        ...( ariaExpanded !== '' ? { 'aria-expanded': ariaExpanded } : {} ),
    } );
    return <RichText.Content { ...props } tagName={ Tag } value={ content } />;
}
```

**And the PHP must agree.** In this repository the editor definition lives in
`assets/js/blocks-editor.js` and the server definition in
`src/Blocks/Design_Blocks.php`. An attribute written by one and not the other is an
invalid block the moment somebody opens the editor. We shipped exactly that bug:
`link_open_tag()` wrote `aria-expanded` and `aria-controls` that no `save()`
reproduced. **Any generator must emit both sides from one spec**, which is why
Part 3 generates them together.

**A trap in `blocks.getSaveContent.extraProps`.** That filter is how you add an
attribute to somebody else's block — we use it to put the design's inline style
and `aria-*` on `core/group`, `core/paragraph` and `core/heading`, 265 instances
across the corpus. Reading core's bundle you will find the call site guarded:

```js
… hasFilter("blocks.getSaveContent.extraProps") && !((n.apiVersion ?? 0) > 1) …
```

and conclude the filter cannot fire for a modern block. It can. There are **two**
call sites, and the guard exists to stop them both firing:

```js
function Mr(e = {}) {            // this is useBlockProps.save()
    let { blockType: t, attributes: r } = Ir;
    return Mr.skipFilters ? e : applyFilters("blocks.getSaveContent.extraProps", { ...e }, t, r);
}
```

`useBlockProps.save()` applies it itself, unconditionally. The outer serializer
therefore applies it *only* for apiVersion ≤ 1, so a block whose `save()` calls
`useBlockProps.save()` — which every core block does — gets the filter exactly
once. Deleting our filters as "dead code" on the strength of the guarded site
would have silently stripped the style and a11y attributes from 265 elements.
The corroborating evidence was already there: block validity reports 18/18
clean, which it could not if `save()` were dropping them.

**Dynamic (`render.php` / `render_callback`).** The block comment stores only the
attributes; the markup is produced at request time.

```php
// block.json:  "render": "file:./render.php"
// render.php receives $attributes, $content, $block
$wrapper = get_block_wrapper_attributes( array( 'class' => $attributes['className'] ) );
printf( '<section %s>%s</section>', $wrapper, wp_kses_post( $attributes['content'] ) );
```

No validation ever runs, because there is no stored markup to compare. That
immunity is seductive and mostly wrong for us:

| | Static `save.js` | Dynamic `render.php` |
|---|---|---|
| Validation risk | Real; must be engineered against | None |
| Markup in `post_content` | Yes — greppable, diffable, portable | No |
| Survives plugin deactivation | Yes, as plain HTML | No — the page empties |
| Front-end cost | Zero | A PHP callback per block per request |
| Content editable by a person | Yes, in place | Only through the inspector |
| Reproduces a *fixed* design | Exactly what we want | Indirection with no benefit |

**The pipeline's rule:** static save for everything whose markup the design fixed —
which is nearly all of it, because reproducing a fixed design *is* the task.
Dynamic only where the content is genuinely not fixed: a query loop, a form that
must render a nonce, a widget reading live data, or markup containing tags
`wp_kses` would strip from a restricted user's save (`<input>`, `<svg>`,
`<iframe>`, `<source>`). That last case is a real one — a contributor saving a page
can otherwise silently delete a converted `<svg>` icon.

### 1.4 `view.js` — front-end interactivity

`viewScript` in `block.json` is enqueued **only on pages that contain the block**,
which is the property we want: a design's carousel script must not load on a page
without a carousel.

Two implementations, and they are not equivalent for our purposes.

**Interactivity API** (WordPress 6.5+). Declarative, directive-driven, hydration-free:

```php
// render.php or save.js output
<div
  data-wp-interactive="dxai-ui/tabs"
  <?php echo wp_interactivity_data_wp_context( array( 'active' => 0 ) ); ?>
>
  <button data-wp-on--click="actions.select" data-wp-bind--aria-selected="state.isActive">…</button>
  <div data-wp-bind--hidden="!state.isActive">…</div>
</div>
```

```js
// view.js
import { store, getContext } from '@wordpress/interactivity';
store( 'dxai-ui/tabs', {
    state: { get isActive() { return getContext().active === getContext().index; } },
    actions: { select() { getContext().active = getContext().index; } },
} );
```

It is the right long-term target: server-rendered, no flash of unstyled state, and
the directives live in the markup where a converted design can carry them.

**But there is a catch that decides the matter for a conversion pipeline.** The
directives are *additional attributes on the design's elements*. Every one of them
must therefore be declared on the block and emitted by `save()`, or you are back to
invalid blocks. And `data-wp-bind--hidden` changes the element's box — so a design
whose tabs are styled by `.is-active` must be *translated*, not annotated: the
design's own CSS keys off its own class names, and if we bind `hidden` while the
design keys `.is-active`, the panel is inert. We measured exactly this: 8 tab
controls writing `aria-expanded` against a design with zero `aria-expanded`
selectors in 188 KB of CSS.

**Vanilla `view.js`** driving the design's own class names is therefore the correct
first target, and the pipeline should emit the state machine described in Part 2.3.
Migrating to the Interactivity API is a later, separate step, and it requires
mapping the design's class vocabulary into directives rather than replacing it.

### 1.5 The lifecycle, end to end

```
authoring        block.json + edit.js + save.js  ──build──►  build/<block>/
registration     PHP: register_block_type( build dir )  ──►  server knows attributes
                 JS:  the editorScript self-registers    ──►  editor knows edit/save
insertion        user or pipeline writes the comment     ──►  post_content
load             parse_blocks( post_content )            ──►  attributes + innerHTML
validation       save( attributes ) vs stored innerHTML  ──►  valid | invalid
front end        static: innerHTML echoed as-is
                 dynamic: render.php called with attributes
                 viewScript enqueued iff the block is present
```

---

## Part 2 — The offline pixel-perfect algorithm

No network, no API, deterministic: the same input must produce byte-identical
output, because otherwise no diff against the design means anything.

### 2.1 JSX → HTML

**Do not use `ReactDOMServer`.** It needs the real component tree, which needs the
design's `node_modules`, which is the thing we do not have and cannot trust. The
pipeline is a *static evaluator over the AST*.

```
┌ 1. parse ───────────────────────────────────────────────────────────────┐
│ @babel/parser, plugins: ['jsx', 'typescript']                           │
│ → one AST per .tsx file. Keep the source text: every diagnostic below   │
│   quotes it, and the AI fallback in Part 4 needs the exact fragment.    │
└─────────────────────────────────────────────────────────────────────────┘
┌ 2. module graph ────────────────────────────────────────────────────────┐
│ Resolve imports within the ZIP (aliases `@/` and `~/` → src/).          │
│ Order matters and is a real bug we shipped: bind a file's asset and     │
│ icon imports BEFORE evaluating that file's module-scope consts, or a    │
│ const whose value references an imported asset resolves to nothing.     │
└─────────────────────────────────────────────────────────────────────────┘
┌ 3. entry selection ─────────────────────────────────────────────────────┐
│ Pick the route the connector chose, by content and not by filename.     │
│ Rank candidates by whether they actually render markup, and follow a    │
│ redirect stub's `to:` target.                                           │
└─────────────────────────────────────────────────────────────────────────┘
┌ 4. scope construction ──────────────────────────────────────────────────┐
│ A scope is a stack of frames: module consts, component props, callback  │
│ parameters, `useState` initial values.                                  │
│ Destructuring is not optional: `({ label, href, Icon }) => …` must bind │
│ all three AND consume one positional slot. Skipping the pattern binds   │
│ nothing and shifts the index parameter into slot 0 — which is how four  │
│ footer social links per design rendered as empty anchors.               │
│ Support: plain keys, renames (`label: name`), defaults (`href = "#"`),  │
│ rest (`...rest`), one nesting level, array patterns (`[k, v]`).         │
└─────────────────────────────────────────────────────────────────────────┘
┌ 5. expression evaluation ───────────────────────────────────────────────┐
│ Evaluate to a value or to NOTHING-AND-REPORTED. Never to a guess.       │
│  • literals, template literals, member chains                           │
│  • `? :`, `&&`, `||`, and `??` with real nullish semantics — `??` must   │
│    take the right arm only for null/undefined, not for '' or 0, which   │
│    is the whole reason it exists. Getting that wrong cost 7 canvases    │
│    their accessible name.                                               │
│  • `.map()` over a literal array, including in a child position and     │
│    including after `.filter()`                                          │
│  • `.split().map().join().slice()` chains                                │
│  • `cn()` / `clsx()` / `classnames()` — variadic, falsy arms dropped     │
│ CRITICAL: an absent className must yield NULL, not ''. JSX omits the    │
│ attribute for undefined or a false `&&` arm; flattening to '' emits     │
│ `class=""`, which is a difference from the design on every such node.   │
└─────────────────────────────────────────────────────────────────────────┘
┌ 6. emission ────────────────────────────────────────────────────────────┐
│ Walk the JSX tree; emit tags, attributes, children. Void elements self- │
│ close. Polymorphic `as` / `Tag` props resolve through the scope.        │
│ Attribute renames: className→class, htmlFor→for, tabIndex→tabindex.    │
└─────────────────────────────────────────────────────────────────────────┘
┌ 7. residue register ────────────────────────────────────────────────────┐
│ Every expression that could not be evaluated is RECORDED, with its      │
│ source text, file, offset and kind — never silently dropped and never   │
│ stringified into the page. This register is the input to Part 4.        │
└─────────────────────────────────────────────────────────────────────────┘
```

**Why a register and not an exception.** A page that lost one expression is still
worth importing; the caller should be able to gate on a list rather than lose the
whole conversion. But it must be a *list*, not silence — the failure mode we
actually shipped was an unevaluated expression leaking into the page as visible
text (`MihailStoychev.map((w) => w[0]).join("").slice(0, 2)}`, complete with the
trailing brace).

**What "pixel-perfect" can mean here.** The evaluator produces the **settled state**:
post-mount, pre-interaction. That is a deliberate definition with consequences —
a scroll-reveal is emitted in its revealed state, a count-up at its final value —
and every verification step must compare against the same definition or it will
report the clock as a defect.

### 2.2 CSS extraction and isolation

**Extraction, by source kind:**

| Source | How | Failure mode |
|---|---|---|
| Tailwind | Scan class candidates from the markup; generate utilities from the design's `@theme`. Needs a real engine — ours is 9,500 lines of PHP verified class-by-class against the CLI over all 24,941 utilities in the surface | A class that resolves *differently* is invisible to a coverage report; only a per-class diff against the reference compiler finds it |
| Plain CSS / `@import` | Concatenate in import order; resolve `url()` against the ZIP | `@layer` and `@supports` need structural parsing, not regex |
| CSS Modules | Read the hashed class map from the build output, or recompute it; rewrite `styles.foo` in the AST to the hashed name | If the ZIP has no build output the hash is unknowable → AI fallback |
| CSS-in-JS (styled-components, emotion) | **Not statically resolvable in general.** The template literal may interpolate props | → AI fallback, Part 4 |

**Isolation is two independent problems.** Conflating them is why naive prefixing
fails.

**(a) The design must not escape.** Prefix every selector with a scope class the
wrapper carries. Emit both forms, because the scope element itself may be the
target:

```css
/* .foo { … }  becomes: */
.dxai-ui.dxai-ui--0.foo, .dxai-ui.dxai-ui--0 .foo { … }
```

For the admin specifically, `apiVersion: 3`'s iframe already contains the design.
Enqueue the design stylesheet via `editorStyle` so it reaches the canvas, and
never through an editor script appending to `document.head` — that lands outside
the iframe and does nothing.

**(b) The theme must not break the design.** This is the harder direction and the
obvious tool does not work. `@layer` cannot help: **unlayered CSS beats every
layer**, so a theme's unlayered `p { margin: 0 0 1em }` defeats a design rule you
put in a layer. The mechanism that does work is `:where()`, which has zero
specificity:

```css
/* our reset, weakened so the design always wins */
:where(.dxai-ui) :where(h1, h2, h3) { margin: 0; }
/* the design's own rule, at its natural specificity */
.dxai-ui .hero-title { margin: 0 0 12px; }
```

Two failures worth recording: a `figure.wp-block-image > img` rule at specificity
(0,3,2) beat the design and made a footer logo 174px too tall; and a block-class
reset outranked the scoped utilities. Both were fixed by moving our own rules
inside `:where()` — never by raising their specificity, which only starts an arms
race.

**(c) Emission order is part of correctness.** Two utilities writing the same
property are resolved by whichever the stylesheet emits last. The rule, measured
against the reference compiler: within a property group, order by a **natural sort**
of the class name — alphabetical, with digit runs compared numerically. That
reproduces 232 of 265 property groups exactly. The remaining 33 are cross-family
cases whose order must be encoded explicitly from the reference build, e.g.

```
line-height             text → text-base → leading
border-top-left-radius  rounded-t → rounded-l → rounded-tl
width / height          sr-only → not-sr-only → container → size → w
```

The first matters constantly: a font-size utility also sets `line-height`, and
upstream emits the whole font-size family *before* `leading-*`, which is why
`text-lg leading-tight` leaves the explicit leading in charge.

**(d) Custom-property registration is load-bearing.** A `var(--x)` read with no
fallback and no `@property` registration is invalid at computed-value time and
silently kills the entire declaration. Worse, an *unregistered* custom property
**inherits**, so a nested utility picks up its ancestor's value — we measured
`contain-paint` inside `contain-layout` computing `layout paint` where the
reference computes `paint`. Emit `@property` for every private variable the output
references, and trim to the referenced set: emitting all of them unconditionally
cost 9 KB per page.

### 2.3 JS extraction: React state → front-end machine

The transformation is from React's *functional* model to a *declarative state
machine over class names*, because the design's CSS already keys off class names.

```
┌ 1. discover state ──────────────────────────────────────────────────────┐
│ `const [open, setOpen] = useState(false)`  →  a state slot:             │
│   { name: 'open', type: 'boolean', initial: false }                     │
│ `useState<string | null>(null)`            →  nullable string slot      │
│ `useState(0)`                              →  index slot                │
└─────────────────────────────────────────────────────────────────────────┘
┌ 2. discover transitions ────────────────────────────────────────────────┐
│ `onClick={() => setOpen(!open)}`     → toggle('open')                   │
│ `onClick={() => setTab(i)}`          → set('tab', i)   (i from scope)   │
│ `onMouseEnter={() => setOpen(true)}` → set('open', true) on pointerenter│
│ Record the OWNING element and give it a marker: dxai-toggle-open--0     │
└─────────────────────────────────────────────────────────────────────────┘
┌ 3. discover projections ────────────────────────────────────────────────┐
│ Every place the state reaches the DOM:                                  │
│   className={cn(open && "is-open")}   → class `is-open` when open       │
│   aria-expanded={open}                → attribute mirror                │
│   {open && <Panel/>}                  → the panel exists; emit it and   │
│                                         mark it dxai-on-open--0         │
│   style={{ height: open ? h : 0 }}    → inline style projection         │
│ CRITICAL: project the class the DESIGN keys on. Emitting aria-expanded  │
│ when the design's CSS has no aria-expanded selector produces an inert   │
│ control that still renders — 66 of them, measured.                      │
└─────────────────────────────────────────────────────────────────────────┘
┌ 4. discover effects ────────────────────────────────────────────────────┐
│ IntersectionObserver in useEffect      → the reveal runtime             │
│ setInterval/rAF counting to a target   → the count-up runtime           │
│ addEventListener('scroll')             → the sticky runtime             │
│ Anything else                          → residue, Part 4               │
└─────────────────────────────────────────────────────────────────────────┘
┌ 5. emit ────────────────────────────────────────────────────────────────┐
│ One machine description per scope, and a single small runtime that      │
│ interprets it. Not one bespoke script per block: a shared interpreter   │
│ is testable once and the per-block payload becomes data.                │
└─────────────────────────────────────────────────────────────────────────┘
```

**What this costs when it is missing.** Measured across the seven designs with
`bin/control-audit.php`:

```
              handlers  triggers  targets  aria  cssAria
TOTAL               92       390       22   384        0
```

`cssAria` is zero for **every** design: not one contains a single
`[aria-expanded]`, `[aria-selected]`, `[aria-pressed]` or `[aria-current]`
selector, and we emit 384 of them. Keep emitting them — they are right for
assistive technology and cost nothing — but they are not what makes something
look active. The number that matters is **targets per trigger: 0.06**. A trigger
with nothing to change is a control that does nothing, and the behaviour probe
duly reports 38 of 158 inert.

**The projection class is not always a convention.** Two of the seven designs
have no `.is-*` classes in their CSS at all, so the class to project is whatever
the conditional expression yields — often bare Tailwind utilities, as in
`cn(active && "bg-white text-ink")`. A rule that hunts for an `is-*` name finds
nothing on those designs. Derive the class from the expression, never from a
naming convention.

The emitted `view.js` is then an interpreter, roughly:

```js
// view.js — one runtime, driven by the markers the compiler emitted
document.querySelectorAll( '[data-dxai-scope]' ).forEach( ( scope ) => {
    const state = JSON.parse( scope.dataset.dxaiState || '{}' );

    const project = () => {
        for ( const [ key, value ] of Object.entries( state ) ) {
            scope.querySelectorAll( `[class*="dxai-on-${ key }--"]` ).forEach( ( el ) => {
                const want = el.className.match( new RegExp( `dxai-on-${ key }--([^\\s]+)` ) )[ 1 ];
                // Toggle the class the DESIGN keys on, read from the marker.
                el.classList.toggle( el.dataset.dxaiClass || 'is-on', String( value ) === want );
            } );
        }
    };

    scope.querySelectorAll( '[class*="dxai-toggle-"]' ).forEach( ( el ) => {
        el.addEventListener( 'click', ( e ) => {
            const key = el.className.match( /dxai-toggle-([a-z0-9]+)/i )[ 1 ];
            state[ key ] = typeof state[ key ] === 'boolean' ? ! state[ key ] : el.dataset.dxaiValue;
            project();
        } );
    } );

    project();
} );
```

**How to verify a behaviour rather than assert it.** Click every control and require
that *something* in its container changed — snapshot `innerHTML.length` plus every
descendant's computed `display`, `opacity`, `maxHeight`, `gridTemplateRows`,
`transform` and `visibility`, click, wait a frame, compare. This needs no
per-design expectation of what *should* happen, so it cannot go stale, and it is
enough to separate a live control from a dead one. Cancel navigation only for
anchors that would actually leave the page — a blanket `preventDefault()` suppresses
behaviour a control may depend on and makes a live control read as inert.

---

## Part 3 — Automation: generate the block, then build the page

### 3.1 One spec, two emitters

The generator's input is a single block spec; PHP and JS are both emitted from it,
so they cannot disagree. This is the structural answer to the
`aria-expanded`-without-a-`save()` bug.

```js
// spec.mjs — the single source of truth for one block
export const spec = {
  name: 'dxai-ui/hero',
  title: 'Hero',
  category: 'design',
  tag: { attribute: 'tagName', default: 'section' },
  // Every attribute states its storage AND how save() emits it.
  attributes: {
    tagName:      { type: 'string', default: 'section', emit: 'tag' },
    className:    { type: 'string', default: '',        emit: 'class' },
    dxaiStyle:    { type: 'string', default: '',        emit: 'style' },
    dxaiData:     { type: 'object', default: {},        emit: 'data' },
    ariaExpanded: { type: 'string', default: '',        emit: 'attr:aria-expanded' },
    content:      { type: 'rich-text', source: 'rich-text', emit: 'children' },
  },
  supports: { className: false, customClassName: false, html: false, anchor: false, splitting: false },
  interactive: false,
};
```

```js
#!/usr/bin/env node
// bin/generate-block.mjs — writes the whole block directory from one spec.
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const slug = ( name ) => name.split( '/' ).pop();

function blockJson( spec ) {
  const attributes = Object.fromEntries(
    Object.entries( spec.attributes ).map( ( [ key, a ] ) => [
      key,
      a.source
        ? { type: a.type, source: a.source, ...( a.selector ? { selector: a.selector } : {} ) }
        : { type: a.type, default: a.default },
    ] )
  );
  return {
    $schema: 'https://schemas.wp.org/trunk/block.json',
    apiVersion: 3,
    name: spec.name,
    title: spec.title,
    category: spec.category,
    textdomain: 'dxai-ui',
    attributes,
    supports: spec.supports,
    editorScript: 'file:./index.js',
    ...( spec.interactive ? { viewScript: 'file:./view.js' } : {} ),
  };
}

// The emit table is shared by both emitters, so PHP and JS cannot drift.
const EMIT = {
  class: ( k ) => ( { js: `className: a.${ k }`, php: `'class' => $a['${ k }']` } ),
  style: ( k ) => ( { js: `style: parseStyle(a.${ k })`, php: `'style' => $a['${ k }']` } ),
  data:  ( k ) => ( { js: `...dataAttrs(a.${ k })`, php: `...dxai_data_attrs($a['${ k }'])` } ),
};

function saveJs( spec ) {
  const props = Object.entries( spec.attributes )
    .filter( ( [ , a ] ) => EMIT[ a.emit ] )
    .map( ( [ k, a ] ) => EMIT[ a.emit ]( k ).js );
  const attrs = Object.entries( spec.attributes )
    .filter( ( [ , a ] ) => String( a.emit ).startsWith( 'attr:' ) )
    .map( ( [ k, a ] ) => `...(a.${ k } !== '' ? { '${ a.emit.slice( 5 ) }': a.${ k } } : {})` );

  return `import { useBlockProps, RichText } from '@wordpress/block-editor';
import { parseStyle, dataAttrs } from '../shared/attrs';

export default function save( { attributes: a } ) {
\tconst props = useBlockProps.save( { ${ [ ...props, ...attrs ].join( ', ' ) } } );
\treturn <RichText.Content { ...props } tagName={ a.${ spec.tag.attribute } } value={ a.content } />;
}
`;
}

function registerPhp( spec ) {
  const attrs = Object.entries( spec.attributes )
    .map( ( [ k, a ] ) => a.source
      ? `\t\t\t\t'${ k }' => array( 'type' => '${ a.type }', 'source' => '${ a.source }' ),`
      : `\t\t\t\t'${ k }' => array( 'type' => '${ a.type }', 'default' => ${ JSON.stringify( a.default ) } ),` )
    .join( '\n' );

  return `<?php
// GENERATED from spec.mjs — edit the spec, not this file.
register_block_type(
\t'${ spec.name }',
\tarray(
\t\t'api_version'   => 3,
\t\t'editor_script' => 'dxai-ui-blocks-editor',
\t\t'attributes'    => array(
${ attrs }
\t\t),
\t\t'supports'      => ${ JSON.stringify( spec.supports ) },
\t)
);
`;
}

const [ , , specPath, outDir ] = process.argv;
const { spec } = await import( specPath );
const dir = join( outDir, slug( spec.name ) );
mkdirSync( dir, { recursive: true } );
writeFileSync( join( dir, 'block.json' ), JSON.stringify( blockJson( spec ), null, 2 ) );
writeFileSync( join( dir, 'save.js' ), saveJs( spec ) );
writeFileSync( join( dir, 'register.php' ), registerPhp( spec ) );
writeFileSync( join( dir, 'index.js' ),
  `import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';
import save from './save';
registerBlockType( metadata.name, { edit: Edit, save } );
` );
console.log( 'wrote ' + dir );
```

**A generator is not finished until it self-checks.** Add a step that, for each
spec, asserts every attribute appears in *both* emitters, and fails the build
otherwise. That assertion is cheaper than the invalid-block bug it prevents.
`bin/block-parity.php` + `bin/block-parity.cjs` do this today without a
generator: the first dumps `WP_Block_Type_Registry`, the second executes the
editor bundle against a stubbed `wp` and diffs what each side registers.

**But declaration parity is not output parity, and the corpus under-tests.**
Two checks with different reach:

| check | what it proves | blind to |
|---|---|---|
| `block-parity` | both sides *declare* the same attributes | what `save()` does with them |
| `editor-roundtrip.cjs` | the real editor opens every page with `invalid=0` | any attribute the corpus does not use |

`dxai-ui/text` declares 11 attributes and the corpus exercises 6 — content 282,
className 248, ariaHidden 23, tagName 14, dxaiStyle 8, attrOrder 7. So both
checks can be green while a divergence sits in one of the other five. One did:
the JS wrote `( a.tagName || 'span' ).toLowerCase()` with no validation while
PHP validated against `/^[a-z][a-z0-9]*$/` and fell back, so a typo in the
inspector's Element field would have had PHP emit `span` and the JS emit
`<foo bar>`. It was found by generating synthetic attribute combinations and
comparing PHP's emitter against the real JS `save()` — the harness pattern is
in `scratchpad/text/js-parity.cjs`: load the bundle with the actual
`@wordpress/element`, replicate `getSaveElement` (including its
`!( apiVersion > 1 )` extraProps guard), render with WordPress's own
`renderToString`, and compare byte for byte. Worth promoting to `bin/` as a
synthetic attribute-matrix check; the corpus half is already covered by the
browser round-trip.

### 3.2 Serializing a page

The block comment format is exact:

```html
<!-- wp:dxai-ui/hero {"tagName":"section","className":"hero grid"} -->
<section class="hero grid">…</section>
<!-- /wp:dxai-ui/hero -->
```

Three rules that bite:

1. **Only attributes differing from their default are serialized.** The editor
   compares against `block.json` defaults, so hand-writing every attribute produces
   a comment the editor would not have written — harmless, but it makes diffs noisy.
2. **Attributes with a `source` are NOT in the comment.** They live in the markup.
   Putting `content` in the JSON *and* the markup gives two sources of truth and the
   markup wins on load.
3. **A void block has no closing comment:** `<!-- wp:dxai-ui/spacer /-->`.

Never build this string by hand. Build the array and let WordPress serialize:

```php
$blocks = array(
    array(
        'blockName'    => 'dxai-ui/hero',
        'attrs'        => array( 'tagName' => 'section', 'className' => 'hero grid' ),
        'innerBlocks'  => array(
            array(
                'blockName'   => 'core/heading',
                'attrs'       => array( 'level' => 1 ),
                'innerBlocks' => array(),
                'innerHTML'   => '<h1 class="hero-title">Ship faster</h1>',
                'innerContent'=> array( '<h1 class="hero-title">Ship faster</h1>' ),
            ),
        ),
        // innerContent interleaves literal strings and nulls; each null is
        // "the next inner block goes here". Getting this wrong is the most
        // common way a hand-built tree serializes with blocks in the wrong place.
        'innerHTML'    => '<section class="hero grid"></section>',
        'innerContent' => array( '<section class="hero grid">', null, '</section>' ),
    ),
);

$page_id = wp_insert_post(
    array(
        'post_type'    => 'page',
        'post_status'  => 'publish',
        'post_title'   => 'Hero demo',
        'post_content' => serialize_blocks( $blocks ),
    ),
    true
);
if ( is_wp_error( $page_id ) ) {
    WP_CLI::error( $page_id->get_error_message() );
}

// Record provenance. Without it, nothing relates the page back to its source,
// and a verifier cannot build the right reference to compare against — we
// guessed from the title once and it matched four designs of seven while
// reporting the other three as passing.
update_post_meta( $page_id, '_dxai_ui_source_zip', basename( $zip ) );
```

**WP-CLI**, for scripted runs:

```bash
wp post create --post_type=page --post_status=publish --post_title="Hero demo" \
  --post_content="$(cat page.html)" --porcelain
```

**REST**, for a remote pipeline:

```bash
curl -X POST https://example.com/wp-json/wp/v2/pages \
  -H 'Content-Type: application/json' \
  -u "$WP_USER:$WP_APP_PASSWORD" \
  -d '{"title":"Hero demo","status":"publish","content":"<!-- wp:dxai-ui/hero {\"className\":\"hero grid\"} -->\n<section class=\"hero grid\"></section>\n<!-- /wp:dxai-ui/hero -->"}'
```

REST applies `wp_kses` according to the authenticating user's capabilities. A
contributor-level token silently strips `<input>`, `<svg>` and `<iframe>` — which
is one more reason those belong in a dynamic block.

**And it strips inline styles, which is worse because it is silent.** Measured
over the seven designs by running `safecss_filter_attr()` across every style
attribute the converter emits: **376 of 978 (38%) lose at least one
declaration.** By frequency — `transition-delay` 210, `color` 66, `filter` 48,
`transition` 42, `animation-delay` 18, `box-shadow` 10, `animation` 8. The first
group is simply absent from core's property allow-list. The second group is more
interesting: `color` and `box-shadow` *are* allowed, so what is rejected is the
**value** — our `oklch()` and `color-mix()` functions.

The import itself is safe: it runs as an administrator, who has
`unfiltered_html`, so kses never sees the markup. The exposure is a
*contributor* opening a converted page and pressing Update, which rewrites the
design without telling anybody. Note before reaching for `safe_style_css`: that
filter has no post context, so widening it broadens what every user on the site
may write. The narrow fix is to scope the allowance to design pages, or to keep
the declarations out of the markup — not to open the allow-list globally.

### 3.3 The pipeline, assembled

```
ZIP ─► extract ─► module graph ─► entry route
                                     │
        ┌────────────────────────────┼────────────────────────────┐
        ▼                            ▼                            ▼
  HTML (2.1)                   CSS (2.2)                     JS (2.3)
  + residue register           + scoped stylesheet           + state machines
        │                            │                            │
        ▼                            ▼                            ▼
   block routing ──────────────► generate specs ──────────► generate view.js
        │                            │
        │                      generate-block.mjs
        │                     (block.json/edit/save/php)
        ▼
   serialize_blocks ─► wp_insert_post ─► _dxai_ui_source_zip
        │
        ▼
   VERIFY (all five, or the run does not count)
     1 geometry   node boxes vs a reference built with the REAL CSS compiler
     2 structure  lost/extra nodes — a deleted node contributes 0px, so this
                  is a separate number or it is not measured at all
     3 paint      computed colour/border/shadow/transform, normalised
     4 validity   every block parses and survives a save()/stored comparison
     5 behaviour  every control clicked, and something must change
```

The last box is the part most pipelines omit, and it is the part that makes the
rest trustworthy. Two cautions earned the hard way: **the reference must be
independent** — if the reference HTML is produced by the same compiler under test,
every compiler bug appears on both sides and reads as a perfect score — and **the
reference must not be an earlier output of the pipeline**, because that can already
be lossy in ways nobody has noticed.

---

## Part 4 — When offline fails: the DeepSeek fallback

### 4.1 Precise edge cases

These are the constructs where a static evaluator cannot succeed *in principle*, as
distinct from the ones that are merely unimplemented. Each is a trigger.

**A. Runtime-dependent CSS**
1. `styled-components` / `emotion` where the template literal interpolates a prop:
   `styled.div\`padding: ${ p => p.big ? 32 : 16 }px\``. The value exists only per
   render.
2. CSS Modules with no build output in the ZIP — the hashed name is unknowable.
3. A stylesheet generated at runtime (`new CSSStyleSheet()`, `insertRule`).

**B. State the evaluator cannot fold**
4. `useReducer` — the transition is a function body, not a mapping.
5. `useContext` / a provider higher than the entry route.
6. A custom hook whose body is another module's function returning derived state:
   `const { ref, inView } = useInView(0.05)`.
7. `useMemo` / `useCallback` over non-literal inputs.

**C. Expressions with no static value**
8. `Object.entries(x).map(([k, v]) => …)` — `Object` is not in scope.
9. `.reduce()` / `.sort()` with a callback — folds across an accumulator or pairs,
   which a one-pass-per-item evaluator cannot express.
10. `new Intl.NumberFormat(...).format(n)`, `new Date().getFullYear()`,
    `JSON.stringify` — locale- and clock-dependent. (The last one is a real live
    case: the footer copyright year on three designs.)
11. An IIFE `(() => { … })()`, `typeof x`, `satisfies`.

**D. External libraries**
12. `framer-motion` — the rendered DOM depends on an animation engine.
13. `react-hook-form`, `@tanstack/react-query` — markup depends on hook state.
14. Any component imported from a package rather than from the ZIP.

**E. Genuinely dynamic render**
15. Markup from `fetch` in `useEffect`.
16. `createPortal`.
17. Layout measured at runtime (`ref.current.offsetWidth` feeding a style).

**Routing rule.** A, B and D are *repairable* by an AI that can read the fragment and
write an equivalent static HTML/CSS/JS. E is not: no static output is correct, and
the honest outcome is a dynamic block or a reported gap. **Do not send E to the AI** —
it will produce plausible markup for something that has no fixed markup.

### 4.2 The prompts

The contract: the model receives **one failing fragment** plus the surrounding
scope and the exact expected output shape, and returns **a patch for that fragment
only**. It never sees, and never rewrites, the whole block.

> **Note before wiring this up.** Sending a fragment to an external API publishes
> that code to a third party. That is an outward-facing action and needs the
> project owner's explicit, per-project consent — and the request should carry only
> the fragment and its immediate scope, never the whole ZIP.

**System prompt**

```
You are a static-conversion repair function inside an offline JSX→HTML pipeline.

The pipeline evaluates JSX to HTML with no JavaScript runtime. It failed on ONE
fragment. Your only job is to return a static equivalent for THAT FRAGMENT.

CONTRACT
- Return ONLY a JSON object matching the response schema. No prose, no markdown
  fence, no explanation outside the JSON.
- Repair the given fragment. Do NOT restructure, rename, reformat or "improve"
  anything around it. Do NOT emit a whole component or block.
- Your output must be DETERMINISTIC: the same input must always give the same
  output. No clock, no locale, no randomness, no network. If the fragment's value
  genuinely depends on any of those, do not invent one — set "repairable": false
  and say which.
- Preserve every class, attribute, aria-*, data-* and inline style exactly as the
  fragment specifies. The consumer compares your output byte-for-byte against a
  reference rendering; an attribute you drop, add or reorder is a defect. Never add
  a class of your own.
- The target is the SETTLED state: after mount, before any user interaction. A
  scroll reveal is in its revealed state; a counter is at its final value.
- For a state-dependent fragment, emit the state the initial value produces, and
  describe the transitions separately in "machine" — never inline a handler
  attribute like onclick.
- If you cannot do this correctly, say so. "repairable": false with a precise
  "reason" is a SUCCESS. A plausible guess is the worst possible outcome, because
  it will pass review and fail silently in production.

RESPONSE SCHEMA
{
  "repairable": boolean,
  "reason": string,                  // required when repairable is false
  "kind": "html" | "css" | "machine",
  "html": string,                    // kind=html: the fragment's replacement markup
  "css": string,                     // kind=css: declarations or rules, unscoped
  "machine": {                       // kind=machine: extracted interactivity
    "state": [ { "name": string, "type": "boolean"|"index"|"string", "initial": * } ],
    "transitions": [ { "on": string, "selector": string, "set": string, "to": * } ],
    "projections": [ { "when": string, "equals": *, "selector": string, "addClass": string } ]
  },
  "assumptions": [ string ],         // anything you had to assume, however small
  "confidence": "high" | "medium" | "low"
}
```

**User prompt template**

```
FAILURE
  kind:     {{residue.kind}}          # e.g. unevaluated-expression | css-in-js | unsupported-hook
  file:     {{residue.file}}:{{residue.line}}
  message:  {{residue.message}}

FRAGMENT (the only thing you may change)
```{{residue.language}}
{{residue.source}}
```

ENCLOSING ELEMENT (context; do not change)
```jsx
{{residue.parentSource}}
```

SCOPE AVAILABLE AT THAT POINT (name → statically known value)
```json
{{residue.scope}}
```

RELEVANT IMPORTS
```jsx
{{residue.imports}}
```

DESIGN TOKENS IN SCOPE (from the design's @theme; use these, never literals)
```json
{{residue.tokens}}
```

WHAT THE PIPELINE EXPECTS BACK
  {{residue.expectation}}
  # e.g. "an HTML string for this JSX child position"
  #      "CSS declarations for the styled.div, with props resolved for the settled state"
  #      "a state machine for this useReducer; the panel's design class is .is-open"

ALREADY-COMPILED SIBLINGS (match this style and class vocabulary exactly)
```html
{{residue.siblingHtml}}
```
```

**Why this shape.** Every field exists to close a failure mode we actually hit:
`scope` because the evaluator's own bug was unbound names; `tokens` so the model
cannot invent a hex value where the design has a variable; `siblingHtml` so the
class vocabulary matches the design rather than the model's habits;
`machine` so interactivity comes back as data for the shared runtime instead of
inline handlers; and `assumptions` plus `repairable: false` so a refusal is a
first-class, machine-readable outcome.

### 4.3 Accepting or rejecting the repair

An AI patch is a *proposal*, and it enters the same gate as everything else:

1. **Determinism** — call twice; identical output or reject.
2. **Attribute conservation** — parse the returned HTML and the fragment's declared
   attributes; any dropped or invented attribute rejects the patch.
3. **Scoped compile** — the returned CSS must survive scoping and must not introduce
   a selector outside the scope.
4. **The five verifications** from Part 3.3, on the page as a whole.
5. **Quarantine on doubt** — `confidence: "low"`, a non-empty `assumptions`, or a
   failed gate means the fragment stays a reported gap rather than shipping. A
   reported gap costs somebody five minutes; a plausible wrong repair costs a
   pixel-archaeology session, and this project has spent several.

---

## Appendix — the state of this repository against the plan

| Stage | Status |
|---|---|
| JSX → HTML | Implemented, `src/Compiler/Jsx_Compiler.php`. Destructured callback params, `??`, `.filter().map()` in child position all fixed; residue register added |
| Tailwind engine | 24,941 / 24,941 utilities agree with the real CLI, per-class |
| CSS scoping | `:where()` ladder, both selector forms, `@property` trimmed to referenced vars |
| Emission order | Natural sort + 33 measured cross-family exceptions |
| Blocks registered | `dxai-ui/html`, `text`, `link`, `image`, `details`, `countup` |
| Editor/server declaration parity | **Built and gated.** `bin/block-parity.php` dumps `WP_Block_Type_Registry`; `bin/block-parity.cjs` executes the editor bundle against a stubbed `wp` and diffs what each side registers. Runs in the suite; 9 server / 8 editor blocks in agreement |
| Block generation from a spec | **Not built, and deprioritised.** Part 3.1 existed to prevent PHP/JS drift; the parity gate above prevents the same failure at a fraction of the cost and without refactoring six working blocks. Its remaining value is making *new* blocks cheap |
| Interactivity API | **Not adopted.** Vanilla runtime, class-projection model |
| Geometry verification | Node heights at 1280/768/390, plus width/margin/padding/gap/font-size reported |
| Structure verification | Measured; gate behind `--strict` |
| Paint verification | Measured with four normalisations; advisory |
| Behaviour verification | Every control clicked; 120/158 responded at last measurement |
| Control coverage | `bin/control-audit.php` — handlers vs triggers vs targets vs aria, and the state classes each design's CSS really selects on. Standalone, not a per-import gate: it recompiles every ZIP |
| Inline-run collapse | **Measured and scoped, in flight.** 156 containers hold 376 inline leaves; 103 of them hold exactly two — the `<span>icon</span><span>label</span>` shape split into siblings. Root cause is one line: `is_text_flow()` ends `return $has_text;`. Needs no new block — `dxai-ui/text` already carries `tagName` + `content(source:html)`. Scoped to the 112 containers whose every child has text; the other 44 hold a zero-text child and are gated on `registerFormatType` |
| Inline styles vs kses | **Known gap.** 376 of 978 style attributes lose a declaration on a contributor-level save. Import is unaffected (admin has `unfiltered_html`) |
| AI fallback | **Not built.** Part 4 is the design |

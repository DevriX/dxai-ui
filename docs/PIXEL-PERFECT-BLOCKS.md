# Pixel-perfect JSX/TS → Gutenberg (multi-site)

**Date:** 2026-09-04 (rev 2 — multi-site + DeepSeek)  
**Scope:** DXAI-UI must convert **many unrelated sites** (Lovable, Figma, HTML/CSS/JS, Vue, React, Next, Design.com) — not only ARA / Arcus.  
**Constraint:** Always **separated Gutenberg structures** (header / nav / footer / hero / blog / form / slider / section). Never one page-level `wp:html` blob.

## Product framing

| Reality | Implication |
|---------|-------------|
| Hundreds of client ZIPs, not 2 fixtures | Heuristics must be **source-agnostic**; no hard-coded ARA/Arcus selectors |
| Lovable / Claude ZIPs vary wildly | Deterministic compiler covers the common case; **LLM fills gaps** |
| DeepSeek (and Claude/GPT/Grok) already wired | Use as **fidelity refiner**, not as the only path |
| Agencies need editability + look | Section shells native; design chrome may stay HTML; dynamic WP for blog/form/slider |

## Industry findings (2025–2026)

Research across converters and agency tooling converges on the same pattern:

1. **Deterministic layout first, AI last mile**  
   [ready→made / wpconverters](https://wpconverters.com/figma-to-gutenberg): convert structure deterministically, then hand clean markup to an LLM for the last 10%. LLMs alone reinvent layout every run.

2. **Section mapping, not pixel dumps**  
   [10Web agentic Figma→WP](https://10web.io/blog/figma-to-wordpress/): extract → simplify → **map sections** → generate. Plugins that only emit CSS “look right in preview and fall apart in production.”

3. **Native blocks, not one Custom HTML**  
   [transjt.ai](https://transjt.ai/figma-to-wordpress/gutenberg): dumping into a single HTML block kills editability. Prefer Group/Columns/Heading/Image/Buttons with styles intact.

4. **Keep Tailwind class strings 1:1**  
   [Tailblocks](https://upperhorizon.com/tailwind-to-wordpress-blocks-converter): write HTML+Tailwind once; emit block markup that preserves utilities. Do not rewrite utilities into theme.json approximations.

5. **[jverneaut/html-to-gutenberg](https://github.com/jverneaut/html-to-gutenberg) (v2.5, MIT)** — different problem, useful ideas  
   Webpack plugin: annotated HTML (`data-bind`, `<inner-blocks>`, `<inspector-controls>`) → **custom block packages** (`edit.js` + `render.php` + `block.json`). Tailwind classes stay on the wrapper; layout HTML is the block shell; only bound leaves become RichText/MediaUpload. **Not** a ZIP→page `<!-- wp:* -->` converter and **not** a drop-in for Lovable dumps (needs author annotations + Node build). Steal: shell-default + editable leaves; dynamic InnerBlocks islands; contentOnly editing mode.

6. **Lovable-specific converters use AI QA**  
   [Lovable Converter](https://lovableconverter.com/lovable-to-wordpress): copy full component files → AI-powered Gutenberg with QA — validates that **source code + model** is the realistic Lovable path (no public Lovable API).

**Takeaway for DXAI-UI:** Hybrid wins at scale — rule-based shell + tiered leaves for every site; DeepSeek (or other engine) only where fidelity/structure confidence is low or the user demands 100% Lovable match. Adopt **html-to-gutenberg’s shell pattern** in Pass 1 (section HTML owns flex/grid; leaves editable); do **not** depend on their webpack toolchain for multi-site ZIP import.

## Current DXAI-UI pipeline

```
ZIP / Figma / paste
  → Connector + Asset_Harvester (media, fonts, tokens) — universal
  → IF Source_Compiler.can_compile → deterministic JSX/DC/HTML path
  → ELSE Chunked_Generator(DeepSeek|Claude|GPT|Grok) per Prompt_Builder
  → Dynamic_Rewriter + Blog_Hydrator
  → Structure_Repository (dxai-* parts, patterns, page)
  → Tailwind_Purger + Motion_Runtime
```

Today `Converter_Controller` prefers the deterministic compiler whenever `can_compile` is true, and only then falls through to the LLM. That is correct for speed/cost, but **wrong for 100% Lovable fidelity** on novel component trees: the compiler can “succeed” with visual gaps.

## Target architecture (multi-site)

### A. Universal ingest (already mostly there)

| Source | Adapter | Notes |
|--------|---------|--------|
| Lovable ZIP / paste | `Lovable_Connector` | No public API — ZIP/Git/paste only |
| Generated HTML/CSS/JS, Vue, React, Next | `Generated_Design_Connector` | Landmark + framework detect |
| Design.com `.dc.html` | `Xdc_Template_Renderer` | Desktop snapshot + toggles |
| Figma | `Figma_Connector` | JSON prune → LLM or section map |

Per import: harvest assets, rewrite URLs, keep **raw design CSS** (`design_css_raw`) for `@theme` / tokens.

### B. Two-pass conversion (new policy)

```
Pass 1 — Deterministic (always)
  source_html per structure
  section shells (header/footer/hero/…)
  Tier A dynamic (query/form/slider) when detected
  Tier B safe leaves (heading/p/img outside flex/grid)
  Tier C html for layout/interactive chrome
  scoped Tailwind from FULL source_html

Pass 2 — DeepSeek (or selected engine) when needed
  triggers below → refine ONE structure or ONE page chunk
  Prompt_Builder system rules (Tailwind verbatim + structures[])
  validate: parse_blocks, class retention, no whole-page html
  merge back into Structure_Repository
```

### C. When to call DeepSeek (fidelity gate)

Call the configured engine (DeepSeek recommended for cost/quality on markup JSON) if **any** of:

| Trigger | Example |
|---------|---------|
| User mode `fidelity=strict` / “pixel perfect” | Lovable handoff must match preview |
| Compiler confidence low | Unresolved components, leftover JSX, empty maps |
| Structure heuristics fail | No clear header/hero; odd landmarks |
| Visual / class audit fails | Missing utilities vs `source_html`; FOUC icons |
| Novel framework idioms | Framer motion, shadcn primitives Jsx_Compiler doesn’t know |
| Post-import “Improve with AI” | User clicks refine on one section |

**Do not** send the entire multi-page ZIP as one prompt. Chunk by structure (already: `Prompt_Builder::user_chunk` + `Chunked_Generator`).

### D. Leaf tiers (unchanged, source-agnostic)

| Tier | Output | Rule |
|------|--------|------|
| Shell | `core/group` / `dxai-*` template-part | Every structure root |
| A | `core/query`, `dxai-ui/form`, `dxai-ui/slider` | Detected blog/form/slider **and** `Rewrite_Policy` set to `dynamic` for that type; under `copy` (the default) the designed markup is kept |
| B | heading, paragraph, image, list | Parent not flex/grid/absolute |
| C | `core/html` under shell | Flex/grid, mega-menus, toggles, SVG, DC inline layouts |
| D | `dxai-ui/*` | Only if attrs preserve full class+style |

### E. Multi-site isolation

- Template parts: `dxai-header-{page-key}`, `dxai-footer-{page-key}` (never theme `header`/`footer`)
- CSS scope: `.dxai-ui.dxai-ui--{pageId}` per page; compile from that design’s `source_html` + `design_css_raw`
- No global Preflight; no shared “ARA tokens” baked into the plugin
- Menus: create `wp_navigation` for WP, but **do not flatten** designed headers into `core/navigation` when chrome already exists

## DeepSeek usage contract

Already implemented: `DeepSeek_Engine` → `api.deepseek.com/chat/completions`, JSON object, model `deepseek-v4-pro` (Flash for cheap refine).

**Prompt obligations** (`Prompt_Builder` — keep verbatim + strengthen):

1. Valid `<!-- wp:* -->` only; Tailwind classes unchanged.  
2. `structures[]` split — body markup without duplicating header/footer.  
3. Blog → `core/query`; form → `dxai-ui/form`; slider → `dxai-ui/slider` — only when `Rewrite_Policy` is `dynamic` for that type; under `copy` reproduce the design.  
4. Prefer harvested media URLs; reuse tokens.  
5. **New:** Prefer shell + html chrome over deep group trees when layout is flex/grid.  
6. **New:** Input includes `source_html` golden + failed heuristic notes so the model **refines**, not reinvents.

**Validation after every LLM response:**

- `parse_blocks` has named blocks  
- No single top-level `wp:html` for the whole page  
- Class tokens from `source_html` ⊆ output (or allowlist diffs)  
- Required structure types present when detected in source  

On failure: retry once with DeepSeek; else keep Pass 1 + flag in conversion meta.

## Acceptance (any site)

- Ingest works for Lovable / Figma / generated ZIPs without site-specific code  
- Structures always split; header/footer as `dxai-*` parts  
- Frontend matches provided design within visual-diff tolerance (header, hero, key grid, footer)  
- All design Tailwind tokens/utilities in scoped CSS  
- Interactions (menus, FAQ, reveal) work via Motion_Runtime or source JS  
- DeepSeek refine available when Pass 1 is insufficient or user requests strict fidelity  

## Roadmap (revised for multi-site)

| Phase | Focus | Outcome |
|-------|--------|---------|
| **0** | Confidence score + per-structure `source_html` golden | Measurable gates — **done 2026-09-04** (`Fidelity_Confidence`) |
| **1** | Stop bleeding: inline flex→html; no forced `is-layout-flow`; style-hover→CSS; no style-stripping links | Better Pass 1 for *all* DC/Tailwind sites — **done 2026-09-04** |
| **2** | Fidelity gate → DeepSeek chunk refine (strict mode + low confidence) | 100% Lovable path without abandoning structures — **done 2026-09-04** |
| **3** | Shell-default converter; safe leaf extract only | Editable + scalable |
| **4** | Visual regression suite (N sample ZIPs, not 2); unresolved-utility save gate | Agency ship bar |

## Measuring fidelity: the design oracle

Do not measure a conversion against an earlier conversion. A previous page is
not a baseline — it can be lossy in ways nobody has noticed yet, and three
separate times a good change looked like a regression only because the thing it
was compared against was already wrong.

`bin/design-oracle.php` + `bin/design-oracle.cjs` build the fixed point: the
design's own compiled DOM rendered with output from the **real Tailwind CLI**,
its **own web fonts** mirrored locally, the **same icon runtime** the page uses,
and none of the plugin's CSS. Both sides are then walked node by node and
compared by a signature that survives conversion.

```bash
wp eval-file bin/design-oracle.php "<zip>" /tmp/oracle
node bin/design-oracle.cjs --dir /tmp/oracle --url <live-url> --depth 20
node bin/design-oracle.cjs --dir /tmp/oracle --url <live-url> --section causes
```

Every input to the oracle is a place it can lie. Each one cost real time:

| Missing from the oracle | What it looked like |
|---|---|
| Web fonts | 155px of phantom error — headings wrapped at different points |
| Icon runtime | 200 nodes "missing" — `<i data-lucide>` vs the `<svg>` it becomes |
| Mutation-quiet wait | 2px that came and went between runs (wp-emoji) |

`--budget 0` makes it a gate: it exits non-zero above the budget.

The comparator treats four conversion-intentional differences as equivalences,
each of which would otherwise drown out real regressions: the `core/template-part`
wrapper element, the `<figure>` `core/image` adds, `<div>`→`<p>` for a line of
text, and WordPress's own block classes.

**ARA, 2026-09-05:** 752/752 nodes exact at 1280 / 768 / 500 / 390 px,
deterministic across runs; 0 invalid blocks in the page and both template parts.

At 1024px, 749/752 with 57px over three nested nodes. This one is the design's,
not ours: measured on the oracle alone — design DOM, real Tailwind, no plugin —
the header's chevron icons are declared `width="14"` and render between 6 and 10
pixels. The desktop nav no longer fits at 1024 and the `md:` breakpoint has not
taken over, so the whole row is in a flex-shrink cascade. Inside a cascade a
sub-pixel difference redistributes across every item, and the ~1px between the
two renderings is enough to flip one CTA's text onto a second line (19px × 3
nested boxes). Total page height still matches exactly (6883 → 6883).

Worth remembering when a diff appears at one viewport only: check whether the
design overflows there before looking for the bug in the conversion.

**Build one oracle per design.** Everything above was measured on ARA alone,
and ARA turned out to be the easy case: it is pure Tailwind utilities with the
page bands as direct children of one wrapper. Six other real ZIPs measured
between 1,102px and 19,956px on the same pipeline, and five of seven had invalid
blocks. `bin/design-oracle.php` takes any ZIP; run it per design, not per
project.

## Finding the section level

`Jsx_Compiler::section_level()` decides what becomes a structure — a pattern, or
a header / footer template part. Generated pages wrap their bands in anything
from nothing to `<div><main><div class="edge space-y-4">`, and the bands can sit
beside a `<header>` and `<footer>` inside a `<main>`.

Two moves reach the right level without losing a box:

1. **Descend the single-container chain**, carrying each container's class and
   inline style up to the page wrapper. The wrapper is a real element in the
   saved page, so `space-y-4` and `py-6` keep applying — and apply once to the
   group, not once per band. Out-of-flow siblings (a `fixed` CTA) stay siblings.
2. **Unwrap an unstyled `<main>`** so header / bands / footer become siblings.

A `<main>` that carries a class is left alone: its box would have nowhere to go,
and the page wrapper is the wrong home while a header and footer sit outside it.
That is why Brand Polish Pass (`<main class="pt-[76px]">`, offsetting a fixed
header) still yields 3 structures — declined deliberately, not missed.

| design | structures before | after |
|---|---|---|
| ARA / GTM / Revenue Operations | 17 / 18 / 18 | unchanged |
| Zendesk HubSpot | 3 | 10 |
| DevriX Elevate | 1 | 11 (footer became a template part) |
| Growth Story Hub | 1 | 12 |
| Brand Polish Pass | 3 | 3 (declined) |

Every design that changed also got closer to its oracle — Growth Story Hub by
1,824px, DevriX Elevate by 413px, Zendesk by 188px — and none regressed.

## Layers, without `@layer`

Tailwind decides conflicts by layer: base loses to utilities, whatever the
selectors say. Our CSS is one flat scoped file, so conflicts fall back to
specificity and source order — and that reversed the outcome twice on the same
element.

Real `@layer` is not available to us: unlayered CSS beats every layer, and the
theme's stylesheet is unlayered, so wrapping our own output in layers would hand
the theme the whole page. `:where()` gives the same ordering without that.

| what | emitted as | specificity |
|---|---|---|
| preflight (base) | `{scope} :where(h1, h2, …)` | (0,2,0) |
| a design's `@utility` | `{scope} :where(.eyebrow)` | (0,2,0), later in the file |
| a generated utility | `{scope} .text-3xl` | (0,3,0) |

Both bugs this fixed were invisible without an oracle per design:

- `@utility eyebrow { font-size: .75rem; line-height: 1 }` was passing through
  verbatim, and a browser ignores an at-rule it does not know — so the class did
  nothing and every label was 12px too tall. Four of seven designs use
  `@utility`; the one that measured pixel-exact was the one that does not.
- Once expanded, `.eyebrow` won conflicts it should lose. On
  `class="eyebrow text-[0.625rem]"` Tailwind gives 10px and we gave 12px; on
  `class="num lg:text-4xl"` its `line-height: .9` beat the utility's. `:where()`
  on the custom utility settles both.
- And preflight's own `h1..h6 { font-size: inherit }` at (0,2,1) beat
  `:where(.eyebrow)`, so a heading carrying a custom utility still rendered at
  the inherited size — hence `:where()` on preflight too.

Zendesk HubSpot, the design that exercised all three: **1,290px → 20px**, 208 of
209 nodes exact.

Two more rules had the same shape, found on the two `.rv-` designs:

- **Preflight is not optional.** `Preflight::css()` used to fall back to a
  "non-conflicting subset" when the design's CSS looked like it carried its own
  reset. But such a design still writes `@import "tailwindcss"`, so the real
  build applies preflight *on top of* that reset. Dropping ours cost
  `line-height: 1.5`, and every line of text came out 2–3px short — 176 nodes
  on each affected design, all at exactly −2 or −3. The subset existed so
  preflight could not fight the design; `:where()` already guarantees that, so
  the branch is gone.
- **Our own image rule was too strong.** `figure.wp-block-image > img
  { height: 100% }` scores (0,3,2) and beat a design's
  `.rv-footer-brand img { height: 20px }` at (0,3,1) — a footer logo 174px too
  tall on two designs. Now inside `:where()`, like everything else that exists
  to serve the design rather than override it.

## Read caps truncate silently

`Asset_Harvester` read every text file with a 180,000-byte cap. One design's
`styles.css` is 188,297 bytes, so the last 8 KB vanished — and that tail held a
later `grid-template-columns` override plus a whole carousel's rules. A
two-column section rendered as three columns with its first track collapsed to
44px, because the grid had an extra item and no override to stop it.

That single number was **16,000px** of the 19,956px error on that page. The cap
is now 400,000, matching the connectors that fall back to reading these files
themselves, and `Zip_Extractor::read_text()` cuts at the last newline when a cap
does bite, so a file never ends mid-line. A stylesheet that stops mid-rule is
worse than one that fails to load: the page still renders, and looks nearly
right.

## Polymorphic components

A design's list rows often come from a wrapper component rather than literal
`<li>` markup:

```tsx
export function Reveal({ children, className, delay = 0, as: Tag = "div" }) {
  return <Tag ref={ref} className={cn("reveal", className)}>{children}</Tag>;
}

<ol className="lg:col-span-9">
  {stages.map((s, i) => <Reveal as="li" key={s.num}>…</Reveal>)}
</ol>
```

Two things had to work for that, and neither did:

- `apply_default_props()` read only `key = default`, so the renamed binding in
  `as: Tag = "div"` bound nothing. `Tag` stayed unresolved, `<Tag>` looked like
  an unknown component, and `emit_element()` returned a placeholder `<span>`.
- `emit_element()` now resolves a capitalised name against the scope when it
  holds one of `POLYMORPHIC_TAGS`, so `<Tag>` renders the element the design
  asked for.

Then `list_block()` compounded it: `core/list` holds `core/list-item` and nothing
else, so it skipped every non-`li` child and serialized `<ol></ol>`. Five rows of
copy were gone and the page still rendered, which is why it went unnoticed. It
now falls back to source HTML for a list whose children are not all `li`.

Worth **3,358px** on the design that used it, and 241px on another that shares
the component.

## Layers must not survive

`Design_Css::prepare()` unwrapped `@layer base` and left the rest. But an
unlayered rule beats every layer, whatever the selectors say, and our preflight
is unlayered — so a design's `@layer components { .edge { padding-inline:
2.5rem } }` lost to `* { padding: 0 }`, and 80px of page gutter vanished. The
columns downstream were 26px wider, which was enough to change where headings
wrapped.

`unwrap_layers()` now flattens every `@layer`, wrapping what it contained in
`:where()` so the ladder Tailwind gets from real layers survives: base and
components carry no specificity and settle by file order, a utility class still
outranks both. `:root`, `html`, `body` and `@font-face` keep their weight — a
token defined once should not become the weakest declaration on the page.

Three designs improved, none regressed: **−1,515px**, **−1,824px**, **−476px**.

## `core/image` cannot stand in for an aspect ratio

`image()` puts the design's classes on the `<figure>` and lets the `<img>` fill
it. That is right when the class set pins a box — `h-full w-full object-cover`
on a card — and wrong when the design pins one dimension and asks for the other
from the file's own proportions. `h-7 w-auto` on a logo means 28px tall and 71px
wide; `w-auto` on a block-level figure means *as wide as the container*, and it
stretched a footer logo to 363px. A replaced element's intrinsic ratio is not
something a figure can express, so a class set containing `w-auto` / `h-auto`
now keeps its source HTML and the `<img>` carries its own classes.

The same design's header logo was always correct — it sat inside an HTML island,
so nothing moved its classes. Worth checking both when an image looks wrong:
the same picture can convert two ways on one page.

`image_needs_own_ratio()` is the test. `-auto` in either dimension is the
obvious case; the general one is a class set that pins **one** dimension and
does not use `object-*` to fill a box. `mt-8 w-40` on a badge reached 1,982px
before that was covered. `h-full w-full object-cover` on a card pins a box and
stays a `core/image`.

## Asset descriptors

One export ships raster assets as `*.asset.json` descriptors — the bytes live on
the vendor's CDN, and the source imports the descriptor and reads a property:

```tsx
import marioAsset from "@/assets/mario-peshev-2.png.asset.json";
const leaders = [{ photo: marioAsset.url, … }];
```

The harvester already fetched and sideloaded those images. Three separate things
still stopped them reaching the page, and each produced an `<img>` with no `src`
at all:

- **`alias_rewrites()` never emitted the `@/` alias.** Only the bare filename
  matched, so the substitution happened *inside* the specifier and left
  `"@/assets/http://host/uploads/hero.png"`, which resolves to nothing.
- **`bind_assets()` chose the object-vs-string shape from the specifier's
  extension.** Once the specifier was rewritten to the uploaded URL it no longer
  ended in `.json`, so `hero.url` had nothing to read. It now always binds
  `{ url: … }` and `stringify_attr()` unwraps a lone `url`, so `src={hero}` and
  `src={hero.url}` both resolve.
- **Consts were evaluated before imports were bound.** `compile_file()` called
  `load_file()` (which evaluated every module-level `const`) before
  `load_imports()`, so a `const` reading an imported asset got nothing. Const
  evaluation moved to `bind_consts()`, after imports.

## The oracle needs its own copy of the media

Two more oracle defects, both of which made the reference look *worse* than the
page it was judging:

- The oracle reverts its own uploads, so any `<img>` still pointing at one aimed
  at a deleted file and measured 0px. Referenced uploads are now copied into
  `<dir>/media/` first, and the remap skips `.asset.json` paths — pointing an
  image back at its descriptor renders nothing.
- **Lazy images never loaded.** Below the fold they stay `complete: false`
  forever in a viewport that never scrolls, and an image with no intrinsic box
  yet measures 0px — which read as the design wanting nothing there. The harness
  now forces `loading="eager"` on both sides before settling. That one line was
  worth 1,975px.

## Two things wrong in the oracle itself

Both found while chasing what looked like a conversion bug:

- **`design-oracle.php` passed only `payload['sources']`**, while
  `Source_Compiler` passes `components` merged over `sources`. Component files
  were missing, so the compiler could not resolve `<SiteHeader/>` and rendered a
  fallback. The oracle then disagreed with the page about things the conversion
  had got right — the one failure mode an oracle must not have. Rebuilding with
  the full set moved one design's section count from 3 to 10, which means the
  numbers measured before this fix were measured against an under-compiled
  reference.
- **`pick_logo_url()` had `devrix-logo.png` hardcoded first** — one client's
  filename inside a compiler that has to serve any design, and it made the
  fallback confidently pick the wrong file out of the three logos that ZIP
  ships. Conventional names only now.

Whenever a number moves after the oracle changes, it is not a regression or an
improvement — it is a different measurement. Say so rather than claiming either.

## The wrapper problem, three more times

`core/image` was not the only block that puts the design's class on a wrapper
and leaves the real element bare. The same shape turned up twice more, and a
fourth case is the mirror image of it:

- **`core/table`** always writes `className` onto its `<figure>`; the `<table>`
  gets only `has-fixed-layout`. A design that styles the table itself —
  `.rv-leak-table { border-collapse; width; … }` — would have those
  declarations land where they do nothing, and no attribute puts them back. A
  table carrying its own class now keeps its source HTML; a plain one still
  becomes an editable block. We also stopped forcing `hasFixedLayout`, which
  changes how columns size and which no design had asked for.
- **`core/quote`** holds blocks, so bare text in a `<blockquote>` becomes a
  paragraph — a block box. Where the design meant that text to flow inline
  beside a sibling (`<span class="rv-quote-mark">“</span>` then the quote), the
  wrapping broke the shared line box and the quote grew 41px.
  `has_mixed_inline_content()` is the test: own text plus element children is
  one inline flow, and the source markup has to stand.

The rule underneath all of them: **a block whose save() cannot reproduce what
the design put on the element is the wrong block for that element.** Falling
back to source HTML costs one editable leaf; converting anyway costs the layout.

## The metric was over-counting

Node keys were a document-global signature plus a global occurrence counter, so
`span`, `span[2]`, `span[3]`… ran across the whole page. One extra sibling
anywhere renumbered every later node of that shape, and the comparison reported
long chains of paired `+88 / -88` rows for content that had merely shifted
index. On one design that was 968px of "error" against a document that differed
by 58px in total.

Keys are now paths — parent key plus own signature — with the counter scoped to
the path. Correcting it moved four numbers a long way, with **no change to the
conversion**:

| design | index keys | path keys |
|---|---|---|
| GTM Strategy Hub | 2,225px | 298px |
| DevriX Elevate | 1,466px | 100px |
| Revenue Operations | 1,474px | 847px |
| Growth Story Hub | 1,675px | 1,675px |

Six fixes in one sitting came from the plugin and three from this harness. An
oracle is only worth what its keys are worth.

## Never link a family twice

History — the synthesis described here was removed on 2026-09-16. The rule now
is in `Source_Compiler::compile()`: the fonts a page loads are exactly the fonts
the harvest found in the export, nothing is added for a family the CSS merely
names. Market Insights Hub and ICP Segmentation ship no font source at all, so
their real build renders `font-family: Inter, …` in the fallback face; the
synthesised Inter made every nav link 3% wider (222 boxes at 1280px).

`ensure_design_fonts()` added a Google stylesheet for any family it found in the
design CSS, and skipped families it judged already covered. That test looked for
a literal weight in the existing URL — `800` for Inter, `700` for JetBrains Mono
— which a **variable** font request never contains. One design asked for
`Inter:ital,opsz,wght@0,14..32,400..900`, we read that as uncovered and linked a
second, static `Inter:wght@400;500;600;700;800;900`.

The browser then had two faces of one family. For `font-weight: 600` it
synthesised bold from the static 400 rather than using the variable face, and
every glyph came out about 7% wider:

```
                 oracle   live
"revenue" @64px   234px   251px
"system"  @64px   211px   225px
```

Identical markup, identical computed `font-family`, `font-size` and
`font-weight`, both faces reporting `loaded` — and a different line count on
every heading. Coverage is now "this family is named in that URL", whatever
weights follow, which also retired three hardcoded per-family branches.

Worth **4,068px** on that design, and it was invisible until the gutter below
was fixed — a wider container had been masking it by wrapping the text the same
number of times for the wrong reason.

## A class can land on the wrapper

`Css_Scoper` prefixed design CSS as a descendant only: `{scope} .edge`. But
`section_level()` carries a container's classes up to the page root when it
descends past it, so `edge` ends up on the scope element itself and a
descendant selector never matches. The design's
`.edge { padding-inline: 2.5rem }` — 80px of page gutter — silently did
nothing.

It now also emits the same-node form, `{scope}.edge`, exactly as the utility
layer already does for every class it generates, and only where that is
syntactically valid: attaching a scope to a type selector would read as one
word.

**Fixing this made the total error worse before it made it better** — 1,675px to
4,263px — because the missing gutter had been compensating for the duplicate
font. Two wrongs that partly cancelled.

## Two Tailwind engine gaps

Both found on the design with the cleanest profile — 9 divergent nodes out of
370, and a document height that already matched exactly. Small numbers are worth
opening: these two turned out to be shared, and fixing them took three designs
to zero.

**`space-y-*` / `space-x-*` changed selector.** Tailwind 4.2 emits
`> :not(:last-child)`; we still emitted the older
`> :not([hidden]) ~ :not([hidden])`. That matches every child but the *first*, so
`margin-block-end` landed after the **last** child rather than between them —
where it collapses out of the container and every list came out exactly one gap
short. `bin/smoke-jsx-1to1.php` was asserting the old selector, so it had to be
corrected too; the reference build is what settles it, not the test.

**`@property --tw-scale-*` was missing entirely.** `scale-y-0` emits only
`--tw-scale-y` and then `scale: var(--tw-scale-x) var(--tw-scale-y)`. Without
the registrations, `--tw-scale-x` resolves to nothing, the shorthand is invalid,
and `scale` falls back to `none`. An element the design had collapsed to zero
height rendered at full size — a dark curtain painted over an entire card, 464px
of it, twice on the page. Worth checking the whole `@property` table against a
real build; `--tw-translate-*` and `--tw-rotate-*` were there, the scale axes
simply were not.

Both were invisible in `transform` and in the computed `font`/box properties.
The first probe I ran read `transform` and `marginTop` and found nothing — the
values live in the `scale` property and `margin-block-end`. When a probe says
"identical" but the boxes differ, the probe is reading the wrong properties.

## A wrapper is also an extra layout item

`core/image`'s `<figure>` does not only take the design's classes — in a flex or
grid parent it becomes **the item being laid out**, in the image's place. A
footer lockup of `<img> <span>· RevOps</span>` in a flex row went from 21px to
42px because the row was laying out the figure, not the picture.

Whether the parent lays out its children is often only knowable from the
design's stylesheet: `.rv-footer-brand { display: flex }` leaves no trace in the
markup, so a class test catches only what a utility class spells out. For an
image with **no classes of its own** there is nothing for the figure to carry
anyway, so those keep their source HTML — the wrapper would be pure overhead.
An image that does carry design classes keeps the block; there the figure is the
box the design asked for. Eleven `core/image` blocks survive across the seven
designs, all of them carrying real classes.

`core/quote` needed the same treatment twice over. It is only right for a
blockquote that already contains blocks **and** no loose text of its own: a
paragraph inside `.wp-block-quote` picks up core's border and padding, so the
text is narrower and wraps more. One testimonial grew 61px that way.

## The last four

**`core/list-item` has no slot for an inline style.** One design tapers a funnel
by giving each `<li>` its own `width`, and dropping the attribute flattened the
taper — the narrowest row stopped wrapping. It travels as `dxaiStyle` now, like
every other style core cannot hold; `core/list` and `core/list-item` joined
`DXAI_STYLED` in `assets/js/blocks-editor.js`.

**Promoting an element changes which of the design's rules apply to it.**
`.rv-obj-num` is a `<div>` the design sizes at 13px, and the same design says
`.rv-obj p { font-size: 17px }` — at (0,3,1) against (0,3,0). Turning that div
into a `core/paragraph` made it a `<p>` inside `.rv-obj`, and it grew. So
`Html_To_Blocks` now takes the design's stylesheet and reads `.foo p`-shaped
rules out of it; `inherits_tag_rules()` walks an element's ancestors and declines
the promotion where it would opt in. The promotion survives everywhere else.

**`wptexturize()` rewrites the design's punctuation.** A heading reading
`Let's build the system` wrapped onto four lines with the design's apostrophe
and three with WordPress's curly one — **65px from one character**, and provably
so: substituting each apostrophe into the other document reproduced the other
document's height exactly. The filter is removed on design pages. `--` becoming
an en dash and `...` an ellipsis were the same hazard waiting.

**A component's own asset imports were bound too late.** `load_imports()` walks
imports transitively, but it evaluated an imported file's module-level `const`
blocks while loading it as a *child*, one round before that file's own assets
were bound. A component collecting its pictures into an array —
`const cases = [{ img: caseUnblu }]` — read an unbound identifier, and three
case-study images rendered with no `src` at all. Same ordering bug as at the
top level, one level down.

## A check that seeds must clean up

Four of the eight `bin/verify-*.php` scripts left content behind on every run:
`verify-structures.php` alone seeded 38 posts — a smoke page, its template parts
and navigations, eleven patterns, conversions, revisions — and three of them
sideload media. A clean import stayed clean only until the suite ran, and the
counts in any report taken afterwards were the design pages plus whatever the
checks had dropped.

`bin/verify-cleanup.php` fixes it generically. Snapshot before, clean up after:

```php
require DXAI_UI_DIR . 'bin/verify-cleanup.php';
$dxai_snapshot = dxai_verify_snapshot();
… the check …
dxai_verify_cleanup( $dxai_snapshot );
```

The snapshot is every post id **and every file under uploads**, so cleanup
removes exactly what appeared in between — no name matching to keep in step with
the fixtures as they change. The file half is needed because
`Media\Sideloader` writes an asset straight to disk when
`media_handle_sideload()` refuses it (a `.css`, a `.woff2`, an `.svg`), and those
have no attachment to delete: four files leaked per suite run until the snapshot
covered them.

**A first draft swept the whole uploads tree for orphans** and reported one
script removing 197 files, none of them its own. A check must not be able to
delete media it did not create; wiping what predates the run is the purge
script's job, where that is the stated intent. Scoping the sweep to files that
appeared during the run gives the same result without the footgun.

Measured: the full suite now leaves `page`, `attachment`, `wp_block`,
`wp_template_part`, `wp_navigation`, `dxai_conversion` and `revision` counts
unchanged, and 497 files before and after.

## One command after an import

```bash
node bin/verify-import.cjs --wp <wp-root> --zip "<a.zip>" --zip "<b.zip>"
node bin/verify-import.cjs --wp <wp-root> --skip-import --only geometry
```

Five stages, one verdict, non-zero exit naming what failed:

| stage | what it proves | how |
|---|---|---|
| `import` | every ZIP landed | `purge-and-import-real-zips.php` |
| `geometry` | the page matches the design | oracle per design, measured at 1280/768/390 |
| `blocks` | every block survives the editor | `editor-roundtrip.cjs` over pages **and** template parts |
| `behaviour` | reveals fire, images load, no JS errors | headless sweep of each live page |
| `suites` | the PHP checks | every `bin/smoke-*.php` and `bin/verify-*.php` |

Running these by hand was six invocations plus remembering which page ids the
import had just produced — so a change would land, one check would get run, and
the rest were assumed. Three separate times that is exactly what happened.

Three things it took to make the report trustworthy:

- **The design-to-ZIP pairing is recorded, not guessed.** A page's title comes
  from the design's own content, so a title-to-filename heuristic paired four of
  seven and reported the other three as *passing* while measuring nothing.
  `purge-and-import-real-zips.php` now writes `_dxai_ui_source_zip` on the page
  and `bin/import-manifest.php` hands it over.
- **An unmeasured page fails.** "Skipped" printed green is how those three rode
  along unchecked.
- **A PHP diagnostic counts only when it names a file inside this checkout.**
  Loading wp-admin includes under CLI makes core itself warn three times; the
  first attempt matched on the folder name `DXAI-UI`, which is also what the
  Local site is called, and blamed us for all of them. They are reported as
  `(+3 core warnings)` beside a passing check instead.

Worth knowing: the runner never pipes a child's output. A truncating reader
sends SIGPIPE, and an import killed that way leaves the site half-populated —
which reads exactly like a compiler bug.

## The panel audits its own import

A conversion that "succeeded" used to mean only that the posts saved. An empty
page, images with no source and raw component code rendered as body copy all
reached the success screen looking like a win, and were found days later by
measuring a design against its own source.

`src/Verification/Import_Audit.php` runs on the request that finishes an import
— `Converter_Controller::save_pattern()`, right after the page is written. The
result goes into the response and onto the page as `_dxai_ui_audit`, and
`ConvertWizard` shows it beside the buttons that invite someone to go and look.
`bin/verify-audit.php` runs the same class over every generated page, so the CLI
and the panel cannot disagree about whether a conversion is sound.

Eight rules, each standing for something that shipped silently at least once:

| check | catches |
|---|---|
| `not-empty` | a page that is only its wrapper — one design imported as 158 bytes |
| `blocks-registered` | a block name nothing renders |
| `blocks-parse` | markup stranded outside any block |
| `template-parts` | a referenced part that does not exist |
| `image-sources` | `src=""` from an asset import that never bound |
| `no-leaked-source` | component source rendered as page copy |
| `stylesheet` / `stylesheet-at-rules` | no stylesheet, or `@utility` / `@layer` left for a browser to ignore |
| `fonts-once` | one family requested twice, so weights get synthesised |

**Each rule was proven against a deliberately broken page** — 8 of 8 fire — and
the wiring was proven separately by importing through the REST route the panel
uses and reading `audit` back out of the response. A check that has only ever
passed has demonstrated nothing.

What it cannot do is the browser's work. Geometry against the design, block
validity as the editor computes it, and behaviour on a live page need a browser
and a reference build, so the audit's `note` says plainly that they are not
covered and points at `bin/verify-import.cjs`. Better to name the gap than to
let a green panel imply the page is fully verified.

## Where the seven designs stand

All seven measure **0px** against their oracle at 1280, 768 and 390px, with every
document height matching exactly.

| design | nodes compared | unmatched keys |
|---|---|---|
| ARA Guide | 751 | 1 |
| GTM Strategy Hub | 888 | 7 |
| DevriX Elevate | 782 | 0 |
| Growth Story Hub | 275 | 0 |
| Brand Polish Pass | 370 | 0 |
| Zendesk HubSpot | 209 | 0 |
| Revenue Operations | 685 | 2 |

An unmatched key is a node the comparator could not pair — a WordPress wrapper
or a screen-reader span with no counterpart — not a pixel of error. Every node
that pairs agrees to the pixel.

Every fix so far came from a cause shared across designs, and none of them was
visible on ARA — the design the pipeline was originally tuned against.

## Asking upstream instead of measuring pixels

Both engine gaps above were found by measuring a rendered page and working
backwards — hours each. Neither had to be. `Tailwind_Purger::unresolved()`
reported **no unresolved classes on any of the seven designs** while the engine
was silently wrong about `space-y-3` and `--tw-scale-*`: a coverage report sees
"resolved / not resolved" and is blind to *resolved differently* by
construction.

`bin/tailwind-parity.php` + `bin/tailwind-parity.cjs` ask upstream directly.
Our shipped stylesheet on one side, the real Tailwind CLI over the same classes
and the same `@theme` on the other, compared four ways — which classes each side
emits, the property set per class, the selector shape, and the `@property`
registrations. Values are deliberately not compared: we emit `1.5rem` where
Tailwind emits `calc(var(--spacing) * 6)`, so comparing them would flag
everything and the tool would be useless.

```bash
node bin/tailwind-parity.cjs --dir .parity/ara --zip "<design.zip>" \
  --wp "<wp-root>" --php <php> --php-ini <ini> --wp-cli <phar>
```

Add `--surface` and the comparison stops being about one design. Tailwind's own
design system lists every utility its theme can produce — **24,941** for a
typical design — and the engine has to answer for all of them. A design
exercises a few hundred, so a clean run on seven designs said almost nothing
about the eighth. `--explain <class>` prints what both sides recorded for one
class, which is the fastest way to tell a real divergence from a parser bug.

What the surface run found on its first execution, none of it visible to
geometry diffing:

- **`line-clamp-*` was a fatal.** The dispatcher called a method that did not
  exist, so any design using `line-clamp-3` — ordinary truncated card text —
  took the whole conversion down.
- **`fill-*` and `stroke-*` resolved to nothing.** Every inline SVG icon kept
  the browser's default paint however the design coloured it. A wrong colour
  moves no boxes, so the oracle cannot see it, and none of the seven designs
  used them.
- **`hover:` was not gated behind `@media (hover: hover)`.** Tailwind 4 wraps
  it so a tap on a touch screen does not leave the hover state stuck until the
  next tap elsewhere. 18 classes on ARA alone.
- **`before:`/`after:` never emitted `content`.** A generated pseudo-element
  with no `content` is not rendered at all, so a design's `::before`
  decoration silently disappeared unless the author also wrote
  `before:content-['']`.
- **`container` only emitted `width: 100%`.** A module returns one declaration
  block, and `container` needs a `max-width` per breakpoint — so Tailwind's own
  wrapper class gave a full-bleed page at every size above mobile. The Engine
  now carries `conditional_blocks()` for the two utilities that need extra
  at-rule blocks (`container`, `outline-hidden`).
- Composition bugs that only show when two classes meet: `touch-pan-x
  touch-pinch-zoom` fought over `touch-action` instead of writing separate
  slots, `divide-dashed` set `border-style` where the width utilities read
  `var(--tw-border-style)`, and `divide-*-reverse` did not resolve at all.
- Missing `-webkit-` prefixes on `select-*`, `hyphens-*` and
  `box-decoration-*`, all of which Safari still needs.
- `sr-only` used the deprecated `clip: rect(...)`; upstream moved to
  `clip-path: inset(50%)`. `bin/smoke-jsx-1to1.php` was asserting the old
  value, so it had to be corrected too — as with `space-y-*`, the reference
  build settles it, not the test.

All **24,941** utilities in the surface now agree with upstream: none
unemitted, none emitted that upstream does not emit, none emitted differently.
The backlog `--surface` used to print — masks across 18 families, the logical
properties, `text-shadow-*`, `placeholder-*`, `border-spacing-*` and the
table/containment/`color-scheme` singletons — is closed. Closing it also turned
up four things geometry diffing cannot see:

- **Tailwind 4.2 added four default palettes** — `mauve`, `mist`, `olive`,
  `taupe`. A design needs no `@theme` entry to use them, so `bg-mauve-500` was
  a plain utility that painted nothing. 2,284 of the missing classes were these
  four names multiplied across every colour-taking prefix.
- **`mask-[20px]` wrote `mask-image`**, which is invalid CSS on that property.
  `mask` is one functional utility over three properties and upstream picks by
  data type, trying image, percentage, position, bg-size, length, url and
  keeping the first that fits — so a bare length is a `<position>` before it is
  ever a length, and only an explicit `length:`/`size:` hint means `mask-size`.
- **`text-shadow-lg/50` was ten times too faint.** A colour's `/opacity`
  composites (`color-mix` towards transparent) but a *shadow's* replaces
  (`oklab(from … l a b / 50%)`). Every built-in text-shadow token is already
  translucent, so compositing gave 0.1 × 0.5 = 0.05. `Theme` now carries both
  operations, `with_alpha()` and `with_absolute_alpha()`, because they are
  genuinely different questions.
- **`placeholder-red-500/(--o)` emitted `calc((--o) * 100%)`** — invalid, so
  the browser dropped the declaration. Each colour family had been expanding
  `(--x)` to `var(--x)` at its own call site, and the newest one did not. That
  expansion now lives in `Theme::with_alpha()`, where a family cannot forget
  it.

Two families needed the Engine rather than a module, because a module answers
with one flat declaration block and these are not that shape.
`placeholder-*` puts its declaration under `&::placeholder`, so the suffix is
added by `child_combinator()` alongside the `> :not(:last-child)` that
`space-*` and `divide-*` need — the pseudo-element is part of the selector, not
of the declarations. And `mask-*` and `contain-*` each have members that
contribute a slot to a shared shorthand and members that overwrite it
outright; `SHORTHAND_OVERRIDES` sorts the contributors first, because with both
halves unranked they tied and the tie was broken by the order the classes
happened to appear in the markup — `contain-none contain-layout` came out
backwards about half the time.

### What the parity harness cannot see

Worth stating plainly, because a green run is easy to over-read:

- **Cascade order between two classes.** It compares each class on its own, so
  two individually correct classes can still be emitted in the wrong order.
  `UTILITY_ORDER` ranks 243 of the 13,700+ utilities the engine emits; the rest
  tie, and the tie only bites where two utilities on one element write the same
  property (a shorthand against its own longhand, `my-4` with `mbs-4`). Every
  ordering fact in that table was read off CLI output or traced by hand.
- **Values.** Comparing them would flag everything, so it compares property
  names, selector shapes, conditions and registrations. Both the text-shadow
  alpha bug and the `(--o)` bug above were value-only: the property set matched
  exactly. They were found by dumping the two sides and reading them.
- **Arbitrary values.** `--surface` enumerates the *static* utility surface;
  `mask-[…]`, `placeholder-…/(--x)` and friends are absent from it by
  construction. Pass an explicit class list as the fourth argument to
  `bin/tailwind-parity.php` and both sides compile it, which is how the
  arbitrary forms are checked.

### The tool has to be trustworthy first

Nine of the divergences it reported at first were its own. Worth recording,
because each one looked exactly like a plugin bug:

- **Tailwind 4.2 emits nested CSS.** `.divide-y { :where(& > :not(:last-child))
  { … } }` and `.hover\:opacity-100 { &:hover { @media (hover: hover) { … } } }`.
  A parser that reads only top-level rules attributes nothing to the class, and
  an at-rule nested inside a style rule holds *that rule's* declarations, not a
  child's.
- **A comment between two rules.** The cursor sits on a newline after a rule, so
  a comment on the next line stayed in the head and
  `/* … */@media (min-width: 901px)` stopped reading as an at-rule. Its rules
  were recorded with no condition — 47 design classes across two designs
  reported as applying at every width when the stylesheet had them behind a
  breakpoint all along.
- **Lightning CSS duplicates for wide gamut.** One authored
  `color-mix(in oklab, …)` becomes an sRGB fallback plus a copy under
  `@supports (color: color-mix(in lab, red, red))`; same for gradients. We
  resolve a single value, so the copy has no counterpart and must be dropped,
  not merely stripped of its condition.
- **`)` and `,` end a class name.** Without that, the `:where(.cta-primary)`
  Design_Css wraps an `@utility` in yields the name `cta-primary)`.
- **The first class in a selector is not the subject.** `group-hover:*` puts a
  companion class beside the utility, and the two sides order them differently —
  ours `.group:hover .group-hover\:x`, upstream
  `.group-hover\:x:is(:where(.group):hover *)`.
- **Comparing only the engine's output** reported all 36 `@property`
  registrations as missing, because they live in Preflight — and sent me looking
  for a divider bug that does not exist. The harness now emits what
  `Tailwind_Purger::compile()` ships.
- **A registration only matters for a variable read without a fallback.** Our
  gradient utilities write `var(--tw-gradient-via-position,50%)` everywhere,
  which resolves with no registration at all; nine of them were being counted
  as failures for being more defensive than upstream.

A tool that cries wolf is worse than no tool. Every count it prints now
survived being checked by hand against both stylesheets.

## Related

- Live ARA/Arcus audit notes: earlier section in git history / conversation 2026-09-04  
- Engines: `.cursor/skills/dxai-ui/engines.md`  
- Prompt: `src/Compiler/Prompt_Builder.php`  
- Entry: `src/API/Converter_Controller.php` (`can_compile` → Source vs Chunked_Generator)

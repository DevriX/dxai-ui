# DXAI-UI implementation plan

## Goal

Ship an enterprise WordPress plugin that turns Figma JSON, Lovable React+Tailwind, or a generated frontend ZIP (HTML/CSS/JS, Vue, React, Next.js) into native Gutenberg structures — headers, menus, heroes, blogs, working forms, editable sliders — routed through a selectable LLM.

## Phases

### Phase 0 — Environment (this session)

- [x] Research Local WP, APIs, Gutenberg, Tailwind, Lovable
- [x] Project skill + Cursor rule + docs
- [x] Create Local site `DXAI-UI` (PHP 8.2, WP latest)
- [x] Junction this repo into `wp-content/plugins/dxai-ui`

### Phase 1 — Core scaffolding

- [x] Entry `dxai-ui.php`, autoloader, `Plugin` bootstrap
- [x] Admin menu, asset enqueue (React SPA)
- [x] Options schema + encrypted secret store
- [x] `uninstall.php`

### Phase 2 — Admin SPA

- [x] Tabs: API Settings, Converter Playground, Block Library (runtime `assets/js/admin.js`; JSX sources under `assets/src/admin/`)

### Phase 3 — REST + connectors + engines

- [x] Settings save/test
- [x] Figma nodes + ZIP
- [x] Lovable ZIP / paste (no fake Lovable API)
- [x] Generated / Claude ZIP (HTML, Vue, React, Next.js)
- [x] Harvest images, video, fonts, links, tokens, and other design files
- [x] Four engines + factory
- [x] `process-source` / `generate-block` / `save-pattern`

### Phase 4 — Pixel-perfect CSS + dynamic WordPress

- [x] `Gutenberg_Mapper` validation
- [x] `Tailwind_Purger` scoped compile
- [x] Pattern CSS enqueue from `core/block` refs
- [x] Structure split → template parts, `wp_navigation`, Query Loop, form + slider blocks
- [x] Conversion CPT with revisions; blank page template

### Phase 5 — Verify

- [x] Activate on Local, REST smoke tests (`bin/verify-*.php`)
- [ ] PHPCS / `npm run build` (optional next step)
- [ ] Add provider API keys and run a live LLM conversion

## Decisions locked

| Topic | Decision |
|---|---|
| Autoload | Custom PSR-4 in `src/Autoloader.php` (no Composer required at runtime) |
| Secrets | libsodium secretbox keyed from WP salts |
| Lovable API tab | Label as “not available”; ZIP + code editor are primary |
| Model dropdown | Brief labels + current request IDs |
| Patterns | `wp_block` synced posts, not `register_block_pattern()` |
| Header/footer slugs | `dxai-header` / `dxai-footer` (never overwrite theme `header`/`footer`) |
| Blog | Always `core/query` + post-template |
| Forms / sliders | `dxai-ui/form` (entries CPT) and `dxai-ui/slider` (InnerBlocks) |
| Homepage | Never auto-set `show_on_front`; create/update a generated page |
| Admin Tailwind | Separate scoped stylesheet; do not leak to frontend |

## Out of scope for v0.2

- Figma OAuth app review flow (PAT first)
- Lovable MCP OAuth inside WordPress
- Streaming LLM responses
- Multi-site network UI

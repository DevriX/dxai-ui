# DXAI-UI research notes

Research date: 2026-09-02. Multi-agent + Context.dev review of Local WP, Figma REST, LLM providers, Gutenberg patterns, Tailwind isolation, and Lovable export.

## Local WP (Windows)

- App: Local 10.1.1 at `C:\Program Files (x86)\Local\Local.exe`.
- No official create-site CLI. Deprecated `@getflywheel/local-cli` cannot create sites and hardcodes a macOS GraphQL path.
- Create via GUI or GraphQL `addSite` while Local is running (`%APPDATA%\Local\graphql-connection-info.json`).
- Prefer PHP 8.2.29 + nginx + **MySQL** (MariaDB is slow on Windows).
- Plugin attach with `mklink /J` into `wp-content/plugins/dxai-ui`.

## Figma

- `GET /v1/files/{key}/nodes?ids=` with PAT `X-Figma-Token`.
- URL `node-id=32-9` must become `32:9`.
- Render images `/v1/images/{key}`; original fills `/v1/files/{key}/images`.
- No official HTML/CSS ZIP. Prune JSON before sending to an LLM.
- Rate limits tightened Nov 2025 (Tier 1 file/nodes/images). Honor `Retry-After`.

## LLM providers (Sept 2026)

Brief names (Claude 3.5/3.7, GPT-4o, Grok 3, DeepSeek R1) are legacy. Ship current IDs with aliases:

- Anthropic Messages: `claude-sonnet-5` (also `claude-opus-5`)
- OpenAI Responses: `gpt-5.6`
- xAI: `grok-4.6` at `api.x.ai`
- DeepSeek: `deepseek-v4-pro` at `api.deepseek.com`

`wp_remote_*` default timeout is 5s — use 120s. JSON-encode the body yourself.

## Gutenberg

- Synced patterns = `wp_block` posts; `wp_pattern_sync_status` empty = synced.
- Tailwind goes on `className` + wrapper `class` together.
- `has_block()` does not see inside synced pattern refs — enqueue from a dynamic wrapper block.

## Tailwind isolation

Compile a **per-pattern** scoped sheet at save time. Keep class strings 1:1. Disable global Preflight; emit utilities unlayered under `.dxai-ui`. Do not use Play CDN on the frontend.

## Lovable

No public REST/API key for fetching project source. ZIP, Git clone (user-side), and paste TSX are the ingest paths. Typical tree: Vite `src/components` or TanStack `app/`. PHP inventories files; the LLM compiles TSX.

## Pixel-perfect JSX/TS → Gutenberg (2026-09-04, rev 2)

**Multi-site product:** many client ZIPs (Lovable / Figma / HTML / Vue / React / Next / Design.com), not only ARA/Arcus.

**Architecture:** Pass 1 deterministic section shells + leaf tiers; Pass 2 **DeepSeek (or other engine)** refine when fidelity gate / strict mode / low confidence. Never one page `wp:html` blob.

Industry consensus: deterministic layout first, AI last mile; section mapping; Tailwind classes 1:1; native blocks not builder lock-in.

Full write-up: [`docs/PIXEL-PERFECT-BLOCKS.md`](PIXEL-PERFECT-BLOCKS.md).

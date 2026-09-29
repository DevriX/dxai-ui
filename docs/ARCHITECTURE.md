# Architecture

See also `.cursor/skills/dxai-ui/architecture.md` (agent-oriented). This file is the human overview.

DXAI-UI is a WordPress plugin (this repository). Develop here; Local WP loads it through a directory junction.

```
Documents/DXAI-UI  →  Local Sites/DXAI-UI/app/public/wp-content/plugins/dxai-ui
```

Layers:

1. **Admin SPA** — settings, playground, library
2. **REST** — capability-gated controllers
3. **Connectors** — Figma / Lovable / generated ZIP normalize to a source document
4. **Engines** — Claude, OpenAI, Grok, DeepSeek
5. **Compiler** — prompt, structure split, Gutenberg validation, Tailwind isolate
6. **Structures** — template parts, navigation, Query Loop, form/slider blocks, conversion revisions
7. **Patterns** — `wp_block` + scoped CSS in uploads

Constants (main file):

- `DXAI_UI_VERSION`
- `DXAI_UI_FILE`
- `DXAI_UI_DIR`
- `DXAI_UI_URL`
- `DXAI_UI_REST_NAMESPACE` = `dxai-ui/v1`

# Architecture

See also `.cursor/skills/dx-ui/architecture.md` (agent-oriented). This file is the human overview.

DX-UI is a WordPress plugin (this repository). Develop here; Local WP loads it through a directory junction.

```
Documents/DX-UI  →  Local Sites/DX-UI/app/public/wp-content/plugins/dx-ui
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

- `DX_UI_VERSION`
- `DX_UI_FILE`
- `DX_UI_DIR`
- `DX_UI_URL`
- `DX_UI_REST_NAMESPACE` = `dx-ui/v1`

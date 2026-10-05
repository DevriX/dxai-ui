# DX UI

WordPress plugin that turns a design export — **Lovable**, **Claude Design**, a **Vite/React**, **Vue**, **Next.js** or plain **HTML/CSS/JS** archive, a pasted component, or a **Figma** file — into editable Gutenberg pages: every section a block, the header and footer in Appearance › Menus and Widgets (or kept with the page), images and fonts in the site, and the look of the design intact.

> The plugin is called **DX UI** (menu: **DX Convert**); the repository, the text domain and the code still say `dxai-ui`.

[![Tour of the admin panel (1 min)](docs/media/admin-tour.png)](docs/media/admin-tour.mp4)

*Tour of the admin panel — click the picture to play it (about a minute): Convert, the Library and its tools, Form entries, Settings.*

- [Install](#install)
- [Using the plugin](#using-the-plugin)
- [Working on the plugin](#working-on-the-plugin)
- [More documentation](#more-documentation)

## Requirements

PHP 8.2+ (with `dom`, `libxml`, `mbstring`, `zip`), WordPress 6.6+, an administrator account with `unfiltered_html`, a writable `wp-content/uploads`. A block theme is not required: converted pages use the plugin's own **DX Blank** page template, which works under classic themes too. The full list is in [`readme.txt`](readme.txt).

## Install

1. Get `dxai-ui-<version>.zip` (a release, or build it yourself — see [Build a release](#build-a-release)).
2. **Plugins › Add New › Upload Plugin**, choose the ZIP, **Activate**.
3. Open **DX Convert** in the admin menu.

Activation stops and names any PHP extension that is missing. Do not update the plugin from wp-admin on a site where it is a junction to this repository (see [the working environment](#the-working-environment)).

## Using the plugin

The admin menu **DX Convert** has four screens: **Convert**, **Library**, **Form entries** and **Settings**.

### 1. Settings (only if you need an AI engine)

A design **archive** is compiled by the plugin itself — colours, images, fonts, motion, pages — with no AI call. The AI engines (Anthropic Claude, OpenAI, xAI Grok, DeepSeek) and the Figma token are for a **pasted component**, a **Figma file**, and for *Write with AI* in the Library. Add the keys in **DX Convert › Settings** (keys are encrypted at rest and never sent back to the browser), choose the provider and model, and press **Test active engine**.

### 2. Convert a design

**DX Convert › Convert** is a six-step wizard:

| Step | What you do |
| --- | --- |
| **1 · Pick** | *Design archive → Gutenberg* (one `.zip`), *Paste a component* (JSX/TSX and its CSS), or *Figma → Gutenberg* (a file URL). |
| **2 · Page or site** | *One page* — the design as one page. *Whole site* — the page, its header and footer and, if you like, every page its menu links to, built from the design's sections with the live site's own text and pictures. Then choose what happens to the header and footer: **install** the design's (the header becomes menus in Appearance › Menus, the footer becomes widgets in Appearance › Widgets) or **keep** the site's current ones (the design's stay with its pages as template parts and Menus and Widgets are not touched). |
| **3 · Add file** | Drop the `.zip` (or paste the code, or the Figma URL). |
| **4 · Convert** | The server unpacks and reads the archive, compiles it into blocks and renders a preview. Nothing is saved yet. |
| **5 · Preview** | Check the preview and the fidelity score. |
| **6 · Import** | **Add to WordPress** saves the page (and, for a whole site, its pages, menus and template parts) and runs the import checks. |

Importing the same design again updates its pages in place; nothing on the site is deleted.

### 3. Work with what was made — the Library

**DX Convert › Library** lists what the plugin made (pages, synced patterns, headers and footers, menus, conversion snapshots) with *View*, *Edit*, *Export* and *Restore*, and has one panel for each thing you may want to do with a design:

| Panel | What it does |
| --- | --- |
| **Pages from your live site** | Builds the pages your design links to, with each old page's own text and pictures arranged in the design's sections. The menus and the footer then open the new pages. |
| **Theme colours** | Lets a design take your theme's palette by role (and follow later changes) instead of its own copied colours. |
| **Native blocks** | Uses core and theme blocks (Group, Buttons, Image…) where they say the same thing as a DX block, so a page gets their controls. Reversible. |
| **Pages in the team's style** | Makes new pages (a service, a place, About, Contact, the questions, the reviews, the lists) from the design's own sections, in the order the team's sites use. What the Home has no section for is made in the Home's own cards from what the site and the Home say: a list of the site's other pages on each page, the page that lists the services or the places shows all of them, and the ways to reach the company (phone, e-mail, address) are on the contact and About pages. A service page opens with what the Home says about that service. Optionally an AI chooses the sections of each page (one request a page, off by default, its cost shown first, and a plan that breaks a rule is dropped for the usual one), and a page can be checked for free or sent for notes (they are only notes). |
| **Words for the pages** | The prompt for the copy of a page, filled in from the page. Copy it into any assistant, or press **Write with AI** to let the engine chosen in Settings write the words; only the words change and the page can be put back. |
| **Page speed** | See [Fonts and colours follow the theme](#fonts-and-colours-follow-the-theme). Also prints only the part of the theme's stylesheet each page uses. |
| **Generated pages / Import package** | **Export** a finished page with its styles, images, header and footer as a `.dxai.zip` and **import** it on another site where the plugin is active (for example from a local build to production). |

### 4. Fonts and colours follow the theme

A converted page can use the site's own fonts and colours instead of the design's:

- **Fonts**, on a theme that lets the site choose them (the DevriX themes: **Theme Global Settings › Fonts**). Headings use the headline font, everything else — paragraphs, spans, links, buttons, labels — the body font. The design's own fonts are not used and **are not uploaded again**: the page uses the theme's font files and asks for no others. Code (`pre`, `code`) stays monospace and icon fonts keep their glyphs. Nothing in the page's content is rewritten, so a change in Theme Global Settings reaches every design at once. A design that already had its fonts copied is put on the theme's with an update (its font rules leave its stylesheet, kept in a record so they can be put back, and the font files no stylesheet names are removed); a design can be set to keep its own fonts with **Library › Page speed › Use the design's own fonts**.
- A theme that does not manage fonts (a block theme such as Twenty Twenty-Five) leaves designs in their own fonts, copied to the site as before. Another theme can opt in with the `dxai_ui_theme_fonts` filter (the two font stacks, their slugs and the `@font-face` rules to print).
- **Colours**, on any theme with a palette (classic or block). **Library › Theme colours** binds a design's colours to the theme's palette, by role; switch *Follow the theme's colours* on for the design. Until then it keeps its own.

### 5. Form entries

Forms in converted pages store their submissions in WordPress. **DX Convert › Form entries** lists them (date, email, message, page) and exports a CSV.

### 6. From the command line

`wp dxai-ui …` (add `--user=<admin>` where a command writes):

| Command | What it does |
| --- | --- |
| `export <page-id> [--file=<path>]` | Export a converted page as a `.dxai.zip` package. |
| `import <file> [--chrome=keep\|install] [--dry-run]` | Import a package. |
| `native-blocks status \| apply \| revert [--design=<home-id>] [--dry-run]` | The native-blocks pass. |
| `page-order status \| apply \| revert [--design=<home-id>]` | The order of the sections of a page. |
| `speed status \| apply \| revert \| own \| theme \| clean [--design=<home-id>] [--dry-run]` | Fonts: copy, put back, keep a design's own, use the theme's, remove the files nothing names. `speed trim-on \| trim-off` prints the theme's stylesheet trimmed to each page, or whole. |
| `refresh-runtime` | Bring every stored page script up to the current runtime, now. |
| `repair-styles` | Run the repair of converted posts' stored styles now (it also runs by itself, a batch per admin page load). Every repair is a revision. |

## Working on the plugin

### The working environment

The plugin is developed on Windows with [Local](https://localwp.com/) (PHP 8.2, nginx, MySQL), and the repository *is* the plugin: Local loads it through a directory junction, so every saved file is live.

1. In Local create a site named `DXAI-UI` (domain `dxai-ui.local`, PHP 8.2, nginx, MySQL), as in [`docs/LOCAL-WP.md`](docs/LOCAL-WP.md).
2. Link the repository into the site's plugins folder (a junction, from a Command Prompt):
   ```bat
   mklink /J "C:\Users\<you>\Local Sites\DXAI-UI\app\public\wp-content\plugins\dxai-ui" "C:\path\to\DXAI-UI"
   ```
3. Activate it: `wp plugin activate dxai-ui` (or in wp-admin). Admin: <http://dxai-ui.local/wp-admin/admin.php?page=dxai-ui>.
4. Install the admin build tools: `npm install` (Node 20 or newer).

Local's command-line PHP has no `php.ini`, so scripts that need WordPress go through [`bin/wp-php.sh`](bin/wp-php.sh), which supplies the extensions and the site's MySQL port (Local changes it when it restarts the site; `DXAI_DB_PORT=<port>` overrides). `wp-cli` is not on the PATH either: keep a `wp-cli.phar` and a `dxai-cli.ini` in `.verify/tools/` (git-ignored) and run `php -c .verify/tools/dxai-cli.ini .verify/tools/wp-cli.phar --path=<wp-root> …`.

| Where | What |
| --- | --- |
| `src/` | The PHP plugin (PSR-4 `DXAI_UI\`): `Compiler/` (design → blocks), `Blocks/`, `Structures/`, `Theme/` (theme binding: colours, fonts, fence), `Media/`, `Chrome/` (header and footer), `Transfer/`, `Admin/`, `API/` (REST). |
| `assets/src/admin/` | The React admin app. Built into `assets/build/` (git-ignored). |
| `runtime/` | The small script that runs a converted page's motion and widgets. |
| `bin/` | Verification suites (`verify-*.php`, `verify-import.cjs`), oracles and the release script. |
| `docs/` | Architecture notes and plans. |

### Build the admin app

```bash
npm install          # once
npm run build        # production build into assets/build/
npm start            # watch mode while editing assets/src/admin/
```

The PHP needs no build. `assets/build/` is not committed: `npm run build` creates it, and the release builds it again so a ZIP can never ship a stale bundle.

### Run the checks

Each suite is a PHP script that loads WordPress, runs its checks offline against fixtures, prints `ok` / `FAIL`, and exits non-zero on a failure:

```bash
bash bin/wp-php.sh bin/verify-speed.php            # or: wp eval-file bin/verify-speed.php --user=1
bash bin/wp-php.sh bin/verify-theme-fonts.php
bash bin/wp-php.sh bin/verify-native-blocks.php
bash bin/wp-php.sh bin/verify-team-pages.php
```

| Suite | Covers |
| --- | --- |
| `verify-theme-fonts.php` | The theme's fonts: roles, the rule printed with a page, blocks bound to it, fonts leaving a sheet and coming back, the migration, the clean-up. |
| `verify-speed.php` | The stylesheet trim, the theme-sheet trim, the fonts copied into a design's sheet. |
| `verify-native-blocks.php` | The native-block converters. |
| `verify-team-pages.php`, `verify-page-order.php`, `verify-page-recipes.php`, `verify-copy.php` | Pages in the team's style (and whose page it is, the Home's header and footer on every page, the quality measure), section order, recipes, the copy writer. |
| `verify-section-variants.php` | How a section of the Home is shown another way (order of cards, questions kept, ticks and buttons, sides of a row, pictures), and that nothing the design styles by place is moved. |
| `verify-ai-planner.php` | The optional AI steps against a stand-in engine (nothing asks a provider): what a run costs and its ceiling, the plan an AI is asked for and every rule it is checked against, the pages made from a plan and from a plan that is dropped, what the copy writer may and may not change in the sections the site made, the notes on a page, and the same over REST. |
| `verify-section-blueprints.php` | The sections a page needs that the Home does not have (the site's other pages, the ways to reach the company), made in the Home's own cards: which sets of cards can be poured into, that what is made wears nothing but the Home's look and says only what the site and the Home say, and what the Home states (phone, e-mail, address). |
| `verify-team-quality.php` + `team-quality.cjs` | Not a suite but a measure: the pages made for a design against its Home, gate by gate (header and footer, colours, fonts, frame, reused sections — including a photograph of each section that is the Home's own, compared pixel by pixel with the Home's, mobile, valid blocks, variety, nothing foreign, speed). Needs a Home id and a browser: `bash bin/wp-php.sh bin/verify-team-quality.php <home-id> out=q.json`, then `node bin/team-quality.cjs q.json` (`pixels=0` leaves the photographs out). See [`docs/PLAN-TEAM-PAGES.md`](docs/PLAN-TEAM-PAGES.md). |
| `team-editor.cjs` | The same pages opened in the real block editor, every block checked against what its own code would save: `node bin/team-editor.cjs q.json cookies=<file>` (the two cookies of a test site's administrator; a site in a folder is handled). |
| `verify-converter.php`, `verify-lovable-compile.php`, `verify-lovable-zip.php`, `verify-generated-zip.php` | The compilers and the archive routes. |
| `verify-admin-titles.php`, `verify-structures.php`, `verify-assets.php`, `verify-coverage.php`, `verify-audit.php`, `verify-islands.php`, `verify-cleanup.php`, `verify-bootstrap.php`, `verify-admin-ui.php` | Design titles in the admin lists, saving, assets, coverage and audit of a conversion, islands, clean-up, boot, the admin screens. |
| `verify-import.cjs` | Imports a set of real design ZIPs and measures the result (geometry against the design, block validity in the real editor, behaviour). Needs the paths of PHP, its ini, `wp-cli` and the ZIPs — see its header. |

Run the suites a change can touch before you commit. For a conversion change, also run `verify-import.cjs` on the designs in `Documents/Lovable`.

### Build a release

1. Update the version in `dxai-ui.php` (the header and `DXAI_UI_VERSION`), `package.json`, and `readme.txt` (`Stable tag` and a new `= x.y.z =` entry in the changelog). Commit the change as `Release x.y.z`.
2. Build the ZIP:
   ```bash
   npm run release -- 0.4.0-beta.28      # same as: node bin/release.cjs 0.4.0-beta.28
   ```
   It builds the admin app, stamps the version into a snapshot, checks the promises a release must keep (the runtime ships; uninstall never deletes posts; the PHP and WordPress requirements agree), lints every PHP and JS file that ships, writes `dist/dxai-ui-<version>.zip` with forward-slash names under one `dxai-ui/` folder (the only shape WordPress's plugin upload installs on Linux hosts), and reads the ZIP back to check it. A release that fails a check is not written. `dist/` is git-ignored: attach the ZIP to a GitHub release.
3. Push the commits: `git push origin main`.

### Conventions

- Source files use CRLF line endings; keep a file's endings when you edit it.
- No inline styles in generated content: styles are `dxs-` classes and rules, colours and fonts come from the theme's presets and variables.
- Uninstall removes only the plugin's own settings and never a page, an upload or an import.
- Anything the plugin does must work on shared hosting and a VPS (no local-only scripts, `unfiltered_html` honoured, KSES on).

## More documentation

- [`readme.txt`](readme.txt) — the WordPress.org-style readme: description, requirements, export and import, and the changelog.
- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md), [`docs/PIPELINE-ARCHITECTURE.md`](docs/PIPELINE-ARCHITECTURE.md), [`docs/PIXEL-PERFECT-BLOCKS.md`](docs/PIXEL-PERFECT-BLOCKS.md) — how the compiler and the blocks work.
- [`docs/LOCAL-WP.md`](docs/LOCAL-WP.md) — the Local site and the junction.
- [`docs/PLAN-TEAM-PAGES.md`](docs/PLAN-TEAM-PAGES.md) — the plan for the pages made from a Home page: the rules, the gates, the phases.
- [`docs/PLAN.md`](docs/PLAN.md), [`docs/RESEARCH.md`](docs/RESEARCH.md) — plan and research.

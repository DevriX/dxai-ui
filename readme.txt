=== DXAI-UI ===
Contributors: devrix
Tags: gutenberg, figma, lovable, ai, blocks, patterns
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.4.0-beta.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convert Figma, Lovable, and generated frontend ZIPs into native Gutenberg structures using Claude, ChatGPT, Grok, or DeepSeek.

== Description ==

DXAI-UI is a WordPress plugin that routes Figma JSON, Lovable React+Tailwind, or a generated frontend ZIP (HTML/CSS/JS, Vue, React, Next.js) through a selectable LLM. Output is split into native WordPress structures: header/footer template parts, navigation menus, Query Loops, working forms, and editable sliders — not a single static HTML page.

= Sources =

* Figma REST API (file key + node ID or file URL)
* Figma ZIP of exported assets
* Lovable ZIP / pasted JSX+TSX (Lovable has no public source API)
* Claude / generated design ZIP (HTML/CSS/JS, Vue, React, Next.js)

= Engines =

Anthropic Claude, OpenAI, xAI Grok, and DeepSeek. API keys are encrypted at rest and never sent to the browser.

= Requirements =

* PHP 8.2 or newer with the dom, libxml, mbstring and zip extensions. Without zip, archives are read through the PclZip bundled with WordPress, which needs zlib and a filesystem WordPress writes to directly. Activation stops and names any extension that is missing.
* WordPress 6.6 or newer. A block theme is not required: converted pages render through the plugin's own "DXAI Blank" page template, which works under classic themes too. Each import asks what to do with the site's header and footer: install the design's (the header is built in Appearance > Menus, the footer in Appearance > Widgets) or keep the site's (the design's header and footer stay template parts on its pages, and Menus and Widgets are not touched). The automatic choice keeps them on a classic theme, which draws its own header and footer from those menus and widget areas, for a one-page import, and when another imported design's header and footer are the site's.
* A writable wp-content/uploads folder. Archives are unpacked in the system temp directory when PHP can write there (the `dxai_ui_zip_work_root` filter moves it), otherwise in uploads/dxai-ui behind an access-deny rule, and the work folder is removed when the request ends.
* Uploads are limited by PHP's upload_max_filesize and post_max_size; an archive over upload_max_filesize is reported together with the limit. Archive ceilings (entries, size per file, total size) can be changed with the `dxai_ui_zip_limits` filter.
* Outbound HTTPS from the server, to fetch the remote images and fonts a design references and to reach the AI engine you choose.
* An administrator account with the unfiltered_html capability to import. On multisite only Super Admins have it by default, and DISALLOW_UNFILTERED_HTML removes it everywhere.

== Installation ==

1. Copy this plugin to `wp-content/plugins/dxai-ui` (or junction it from the git repo).
2. Activate DXAI-UI.
3. Open **DXAI Convert** in wp-admin (or Settings → DXAI-UI) and add provider keys.

== Export and import ==

A converted page's look is not in its blocks: the blocks carry `dxs-` classes and design data, while the design stylesheet, tokens, fonts, runtime script, images and header/footer live in uploads, post meta, attachments, template parts or Appearance > Menus and Widgets. Copying blocks from one editor into another site therefore loses every style. To move a page to another site (for example from a local build to production), export it as a package and import it there; DXAI-UI must be active on both sites.

* **Export**: Pages > All Pages > "Export with styles" on a converted page, the Export button on the page in DXAI Convert > Library, `GET /wp-json/dxai-ui/v1/transfer/export/<id>`, or `wp dxai-ui export <page-id> [--file=<path>]`. The `.dxai.zip` holds the page (content, template, all `_dxai_ui_*` meta), its design CSS and JS, every image it and its parts, patterns, header and footer use (originals, with alt text, caption and title), its template parts and synced patterns, and its header and footer (the menus' items, the footer's widgets). No secrets and no user data.
* **Import**: DXAI Convert > Library > Import package, `POST /wp-json/dxai-ui/v1/transfer/import` (multipart `package`, `chrome`), or `wp dxai-ui import <file> [--chrome=keep|install] [--dry-run] --user=<admin>`. Choose **keep** to leave the site's menus and widgets alone (the page keeps its header and footer as template parts) or **install** to build them in Appearance > Menus and Widgets. On a classic theme, which draws the whole site's header and footer from those menus and widget areas, install changes them on every page, so keep is offered first there.
* Importing the same package again updates the same page, parts, patterns, menus and widgets in place. Images already in the media library are reused. Nothing on the site is deleted: a part the page stops using goes to the trash, displaced widgets to Inactive Widgets. The design becomes the site's brand only when the site has none (and never on a classic theme).
* Same rules as a design import: manage_options and unfiltered_html (a Super Admin on multisite). A package with an unsafe path, server-side code, an unknown manifest, a newer format or altered files is refused before anything is written.

== Changelog ==

= 0.3.1 =
* Requires WordPress 6.5.
* The import asks whether to install the design's header and footer (Appearance > Menus and Widgets) or keep the site's; automatic keeps them on classic themes, for one-page imports and when another design's are the site's. Installing over another design gives its pages their footer back as a template part.
* A crawl that finishes after its import no longer depends on the trash: with EMPTY_TRASH_DAYS set to 0 the header and footer are still re-linked to the crawled pages.
* Archive import checks every entry name before anything is written and unpacks only supported file types, one by one, into a private work folder. Path traversal, absolute paths and server-side code refuse the archive; entry and size ceilings stop zip bombs.
* Work folders are removed when the request ends, and ones a killed request left behind are removed by the next import.
* Upload errors (a file over upload_max_filesize, a partial upload, no temporary folder) are reported as what they are.
* Imports no longer depend on WordPress's filesystem method, so they work on hosts where it is not "direct".
* Activation checks the required PHP extensions; network activation sets up every site. Uninstall removes all plugin options and transients, on every site of a network.

= 0.3.0 =
* Convert wizard: Figma, Lovable, or Claude design to Gutenberg in five steps.
* Visible admin menus: Convert, Library, Form entries, Settings.
* Page vs whole-site save, live preview, harvest gallery, form inbox.

= 0.2.0 =
* Structure split: headers, menus, footers, heroes, blogs, forms, sliders.
* Generated ZIP ingest (HTML/CSS/JS, Vue, React, Next.js).
* Dynamic `dxai-ui/form` and `dxai-ui/slider` blocks; conversion revisions.

= 0.1.0 =
* Initial scaffolding: REST API, connectors, engines, admin SPA, pattern library.

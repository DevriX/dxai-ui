=== DXAI-UI ===
Contributors: devrix
Tags: gutenberg, figma, lovable, ai, blocks, patterns
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.4.0-beta.20
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

= 0.4.0-beta.20 =
* Fixed, on a block theme (Twenty Twenty-Five and the like): a group's layout was lost. WordPress writes a group's gap, wrapping and alignment as a `wp-container-…` rule and prints it in <head> for a block theme, because it renders a block theme's template first; the plugin's blank template is a PHP file whose content comes after the head, and WordPress does not print those rules in the footer for a block theme. The rules are now printed at the end of a converted page on a block theme. A classic theme gets them from core, as before. Measured on four designs at 1440, 768 and 412px: the pages are what the design was.

= 0.4.0-beta.19 =
* Checked against 28 designs (20 Lovable and Claude Design exports, a plain HTML+CSS site, a Tailwind-CDN page, a Tailwind v3 project, a Claude Design export) at 1440, 768 and 412px, before and after the native conversion. Everything but the buttons is pixel for pixel what the design was; the buttons are the theme's by design. What that turned up is fixed:
* Fixed: a picture with only a height from the design (`h-74px`, a logo) came out stretched. WordPress writes the file's own width and height on a media-library image; the design's image had none, so it kept its proportions. The image now takes its width from its proportions again, and any width the design sets still wins.
* Fixed: a picture inside a card got the card's border. The rule written for a picture (`.dxs-x img`) shares its class with any block that has the same declarations, and reached every image inside them. It now needs the picture's own marker on the same element.
* Fixed: a design that never ran under Tailwind (Claude Design exports) sized a bordered picture 2px narrower, because core sets `border-box` on the image inside a figure. It is named in the design's content-box rule.
* Fixed: a button the design sizes along its row (`flex: 1 1 200px`) came out 200px tall on a small screen, where the theme's rows stack in a column. A row like that no longer stacks.
* Fixed: a strip of chips or tabs (a row that scrolls sideways, or four or more links side by side) was turned into theme buttons stacked in a column. They stay the design's own links.
* Fixed: the theme's button styles were not loaded on a page whose only button is in the header or the footer, so it showed as plain text. The header and footer a design page carries are now counted for what the page needs loaded.
* Fixed: a converted button could shrink to its padding and break a word a letter at a time in a crowded row (core's `word-break: break-word`). It keeps its longest word whole, as the design's link did.
* Fixed: the gap between converted buttons read the design's container classes wrongly (a pattern split on the letter s), so it was always the theme's 12px.

= 0.4.0-beta.18 =
* The tail of an inner page in the order the team's sites have it. Measured on the eleven live sites (283 pages): every service, location and about page opens with its hero and closes with the related services, then the questions, then the call to action, whatever the sites differ in between. A page composed from an old page's content followed that page's reading order, so the related services could come after the call to action. `wp dxai-ui page-order status|apply|revert` puts the tail of an existing design's pages in that order (nothing else moves, no section changes; the Home, the header and the footer are never touched; a page without questions, one that ends in a form, and one with a section that leans on its neighbour stay as they are), and a page composed from now on comes out that way. Each page keeps its content from before; reverting puts it back byte for byte.

= 0.4.0-beta.17 =
* Rows and containers are the Group's own layout. The theme's pages arrange a group in the Layout panel (Flex, Constrained), not in classes; a design's rows were classes and CSS (`d-flex items-center justify-between gap-6`), so its groups had no layout at all. A row becomes a Flex layout (direction, justification, wrapping, gap), a centred container (`mx-auto max-w-1280px px-7`) a Constrained one, and the classes and declarations the layout says are removed. Only where WordPress writes the same page: a group whose children carry margins, a width or a position, one that changes at a breakpoint, or one that paints stays as it is. On the Semper Dry, Clean Joe, H2O Away and DevriX Elevate designs about a third of the groups per page get a Flex layout, with identical screenshots at 412, 768 and 1440 px and every page valid in the editor. It is applied to new imports and to existing designs from the Library (Native blocks) or `wp dxai-ui native-blocks apply`, and reverted the same way.
* Sections are named through a wrapper: a page written as one group around its header, main and sections names the sections ("Hero Section", "Header", the heading of the rest), not the wrapper. The never-rendered `<template id="__bundler_thumbnail">` a Claude Design export leaves at the top of a page is removed.
* Fixed: an image the plugin turned into core/image lost the size its utility class gave it when the same class was used for another block on the page (a header logo with `h-74px` stretched to the width of the page). The rules for the image inside are printed in that case too.
* Fixed: the rule that makes a Group's Flex layout flex has no weight, so the block's own container rule (nowrap, alignment) is the last word. The same for the Buttons block's row.

= 0.4.0-beta.16 =
* A copy of a design's sections looks like the page it was copied from. Sections copied onto another page of the theme were always kept off the theme's CSS, which matches a design page of the blank canvas. Where the design's own Home is a page of the theme (printed with the theme's whole stylesheet), that is another environment: the native blocks the team builds among the sections (the theme's FAQ, groups with a flex or constrained layout) were made with the theme's CSS on them, and the copy showed every FAQ answer open, headings with browser margins, another font and line height. The fence is now decided by the template of the design's source page: kept for the blank canvas, left off for a page of the theme, so the copy has the same CSS as the Home. The custom field `_dxai_ui_fence_theme` (1 or 0) on a page overrides it.

= 0.4.0-beta.15 =
* The theme-stylesheet trim also keeps the rules for widgets that other people's scripts put on a page after it has loaded (the reCAPTCHA badge, a map's controls, review widgets, sliders, lightboxes, consent banners, chat buttons, video players): neither the markup nor the site's own scripts name those classes, so a rule for them could have been cut. Results kept by an earlier version are made again.

= 0.4.0-beta.14 =
* Page speed. A design's pages waited for their fonts through a chain of requests (the page, its stylesheet, Google's stylesheet, the font files) and for the whole of the theme's stylesheet, which a DevriX theme prints inline in every page (1.1 MB, about 2% of it used). The fonts are now copied into the site's uploads and their rules put in the design's own stylesheet, and the theme's stylesheet is printed cut down to what the page can use. On the Semper Dry pages: mobile 81 → 91 in the lab, with the page unchanged (identical screenshots, identical computed styles in the open-menu, hover and form states).
* The fonts are copied when a design is imported, by cron the first time a page that still asks Google is viewed, from the Library (Page speed), or with `wp dxai-ui speed apply`. Only Google Fonts, only what Google's stylesheet names; a file that cannot be fetched keeps its address. What was replaced is kept in the sheet, so `Use Google again` (or `speed revert`) gives the imported sheet back byte for byte, and an export carries the sheet as it was imported.
* The theme's stylesheet is cut down per page for visitors who are not logged in, on pages that show a design: a rule stays when the page can match it (its classes and ids, its element names, the words of the scripts it loads; nothing that needs a hover, focus or an open menu counts against it). It is cached by the page's tokens and falls back to the whole stylesheet at any doubt. `wp dxai-ui speed trim-off` or the Library switch turns it off.
* Copied theme components. A page of the theme that holds sections copied from a design page keeps the theme's CSS off them, and that also took the theme's own components with it: the FAQ (`faq-question`, `faq-answer`, `faq-arrow`) showed every answer open, running into the next question. A class the page's blocks carry and only the theme defines now gets the theme's rules back inside the sections, at the same weight, and the states a script adds to it come with it.
* `bin/verify-speed.php` checks the trim, the fonts and the components against fixtures, offline.

= 0.4.0-beta.13 =
* Calls to action are the theme's buttons: a link of the design with padding and a fill or a border becomes a Buttons block with a Button in the theme's own style (primary for a filled one, secondary for an outlined or white one, small primary for a small one), the way the theme's pages are built. Adjacent calls to action in one container become one Buttons block, spaced as the container spaced them. The look is the theme's (fill, padding, radius, type follow the theme and its colours); only the design's placement (margin, width) stays. The text colour is the palette colour that reads on the theme's accent.
* The theme's button rules load where its stylesheet does not reach: on a design page, and on a page holding a design's sections. Anywhere else the theme's own sheet is there and no copy is added.
* Sections are named in the List View ("Hero Section", "FAQ Section", "Where we work Section"), as the theme's pages name theirs. Only the block's name is stored; the page does not change, and a name a person gave is kept.
* Menu items, social icons, the skip link, controls with ARIA state and links without a fill or border stay DX Links. `bin/verify-native-blocks.php` checks the converters against fixtures.

= 0.4.0-beta.12 =
* The blocks the plugin adds are named "DX Text", "DX Box", "DX Link", "DX Image" … and sit in one inserter group, "DX Blocks". Only the names change; no page is touched.
* Native blocks in place of the plugin's own, wherever a native block says the same thing: a link around content → the theme's Link box (american-restoration), a container → Group, a span of words → the theme's Span, a picture → Image. A picture becomes the media-library image it points to, so WordPress serves it with its own srcset, sizes, width, height, lazy loading and priority hint.
* Only where the page stays the same: a block the native one cannot carry in full (a control's state, an explicit size or srcset, a design that addresses a picture by its position) keeps the DX block. Pages look the same and open valid in the editor.
* New imports get it. Designs imported earlier: Library > Native blocks, or `wp dxai-ui native-blocks status|apply|revert` per site of a network. Each changed page keeps its content from before; putting the DX blocks back restores it, except for a page edited since, which stays as its editor left it.

= 0.4.0-beta.11 =
* Theme classes in the content: where a design's text colour follows a theme colour directly, its blocks carry the theme's own name for it — american-restoration's text-white, or core's text colour setting ("Primary" in the block's Colour panel). Only classes change; blocks stay valid and pages look the same. New imports get it; earlier imports from Library > Theme colours, where it can be undone.
* Pages built from the Home's sections: their header and footer links to Home sections no longer open as invalid blocks in the editor. New pages store the link in the block too; pages built before open valid with the link they show.

= 0.4.0-beta.10 =
* Theme colours: on a theme with colour settings (american-restoration and any theme whose palette WordPress knows), a design's colours follow the theme's. Brand, accent and ink take the theme colour with the same role; neutrals only where the theme has practically the same one; tints and shades follow relatively.
* The binding is applied when a page is shown, as references to the theme's presets, so a later change in the theme's colour settings reaches every page with nothing rewritten. It works for designs imported earlier (bound on the next admin visit), for pages built from the old site, for sections copied into ordinary pages, and with the plugin deactivated or deleted.
* Contrast is checked on every request: a text colour that would not read on its backgrounds keeps the design's own, or turns black or white. No new WCAG AA failure is served.
* Library > Theme colours: what follows what, what changed noticeably (marked for review), per-colour choices, and a switch to keep the design's own colours.
* On a block theme whose chrome the plugin owns, designs keep the existing behaviour (their colours become the theme's presets).

= 0.4.0-beta.9 =
* Blocks copied from a converted page into an ordinary page keep the design's look (styles, fonts, colours, interactions), also with the plugin deactivated or deleted. The theme's CSS no longer reaches into those sections, and pages imported by earlier versions work too.
* Header and footer: a page that carries them in its content never gets a second one. A body-only page gets the Home's own header and footer, the design's installed ones or its template parts, as its chrome mode says. Export and the admin-bar edit links include them.
* Old-site page builder: an honest User-Agent, robots.txt and Crawl-delay respected, a 429 waits, bot protection stops the crawl. Every section of a page is kept, including pages without a main landmark, and Elementor's mobile-only duplicates are skipped.
* HTML ZIP: only the site's own pages are published, a Home in a top-level folder is found, desktop and mobile headers are kept together, in-page headers and navs stay sections, and Tailwind-CDN designs keep their reset.
* Compiler: lucide Link/Image icons are told from router links and images, a CSS comment mentioning @import no longer disables the design CSS, and a sticky header keeps its scroll state.
* Runtime: a timeout or out-of-memory request no longer stands it down.
* Requires WordPress 6.6.
* Release ZIPs are built with npm run release, which refuses a ZIP with backslash paths or development files.

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

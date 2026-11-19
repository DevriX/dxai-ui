# Hooks

The filters and actions DX fires, from the code: 70 filters, 1 action. Each says what the comment above its call says, the values it passes (the first is the value a filter returns) and where it is called.

This file is written by `node bin/hooks-doc.cjs`; CI fails when it is not what the code says (`--check`). To document a hook, write the comment above the line that fires it.

**Filters:** [`dx_ui_accessible_markup`](#dx_ui_accessible_markup) · [`dx_ui_adopt_brand`](#dx_ui_adopt_brand) · [`dx_ui_arrange_sections`](#dx_ui_arrange_sections) · [`dx_ui_block_library_facts`](#dx_ui_block_library_facts) · [`dx_ui_block_library_themes`](#dx_ui_block_library_themes) · [`dx_ui_chrome_mode`](#dx_ui_chrome_mode) · [`dx_ui_copies_old_page`](#dx_ui_copies_old_page) · [`dx_ui_copy_engine`](#dx_ui_copy_engine) · [`dx_ui_crawl_use_ai`](#dx_ui_crawl_use_ai) · [`dx_ui_crawl_words`](#dx_ui_crawl_words) · [`dx_ui_default_template`](#dx_ui_default_template) · [`dx_ui_default_template_draws_design`](#dx_ui_default_template_draws_design) · [`dx_ui_design_system`](#dx_ui_design_system) · [`dx_ui_dx_themes`](#dx_ui_dx_themes) · [`dx_ui_fence_theme`](#dx_ui_fence_theme) · [`dx_ui_fold_wrappers`](#dx_ui_fold_wrappers) · [`dx_ui_font_fallbacks`](#dx_ui_font_fallbacks) · [`dx_ui_fonts_on_import`](#dx_ui_fonts_on_import) · [`dx_ui_footer_holders`](#dx_ui_footer_holders) · [`dx_ui_harmony`](#dx_ui_harmony) · [`dx_ui_harvest_unreferenced`](#dx_ui_harvest_unreferenced) · [`dx_ui_image_quality`](#dx_ui_image_quality) · [`dx_ui_image_sizes`](#dx_ui_image_sizes) · [`dx_ui_import_round_seconds`](#dx_ui_import_round_seconds) · [`dx_ui_inline_styles`](#dx_ui_inline_styles) · [`dx_ui_install_chrome`](#dx_ui_install_chrome) · [`dx_ui_interactivity`](#dx_ui_interactivity) · [`dx_ui_late_fonts`](#dx_ui_late_fonts) · [`dx_ui_lazy_below`](#dx_ui_lazy_below) · [`dx_ui_lcp_preload`](#dx_ui_lcp_preload) · [`dx_ui_legible_text`](#dx_ui_legible_text) · [`dx_ui_menu_pages_hosts`](#dx_ui_menu_pages_hosts) · [`dx_ui_menu_pages_live`](#dx_ui_menu_pages_live) · [`dx_ui_meta_description`](#dx_ui_meta_description) · [`dx_ui_native_blocks_on_import`](#dx_ui_native_blocks_on_import) · [`dx_ui_native_converters`](#dx_ui_native_converters) · [`dx_ui_network_activation_sites`](#dx_ui_network_activation_sites) · [`dx_ui_new_page_status`](#dx_ui_new_page_status) · [`dx_ui_page_css_trim`](#dx_ui_page_css_trim) · [`dx_ui_pages_engine`](#dx_ui_pages_engine) · [`dx_ui_picture_sizes`](#dx_ui_picture_sizes) · [`dx_ui_restyle_engine`](#dx_ui_restyle_engine) · [`dx_ui_seo_plugin`](#dx_ui_seo_plugin) · [`dx_ui_site_links`](#dx_ui_site_links) · [`dx_ui_style_rules_markup`](#dx_ui_style_rules_markup) · [`dx_ui_team_library`](#dx_ui_team_library) · [`dx_ui_template`](#dx_ui_template) · [`dx_ui_template_fonts_choice`](#dx_ui_template_fonts_choice) · [`dx_ui_template_fonts_live`](#dx_ui_template_fonts_live) · [`dx_ui_templates`](#dx_ui_templates) · [`dx_ui_theme_color_mode`](#dx_ui_theme_color_mode) · [`dx_ui_theme_components`](#dx_ui_theme_components) · [`dx_ui_theme_fonts`](#dx_ui_theme_fonts) · [`dx_ui_theme_fonts_follow`](#dx_ui_theme_fonts_follow) · [`dx_ui_theme_has_block_library`](#dx_ui_theme_has_block_library) · [`dx_ui_theme_has_youtube_facade`](#dx_ui_theme_has_youtube_facade) · [`dx_ui_theme_option`](#dx_ui_theme_option) · [`dx_ui_transfer_font_hosts`](#dx_ui_transfer_font_hosts) · [`dx_ui_transfer_step_seconds`](#dx_ui_transfer_step_seconds) · [`dx_ui_trim_min_bytes`](#dx_ui_trim_min_bytes) · [`dx_ui_trim_theme_css`](#dx_ui_trim_theme_css) · [`dx_ui_trusted_proxies`](#dx_ui_trusted_proxies) · [`dx_ui_update_channel`](#dx_ui_update_channel) · [`dx_ui_update_checkout`](#dx_ui_update_checkout) · [`dx_ui_update_repository`](#dx_ui_update_repository) · [`dx_ui_use_dx_picture`](#dx_ui_use_dx_picture) · [`dx_ui_video_hidden_embeds`](#dx_ui_video_hidden_embeds) · [`dx_ui_webp`](#dx_ui_webp) · [`dx_ui_zip_limits`](#dx_ui_zip_limits) · [`dx_ui_zip_work_root`](#dx_ui_zip_work_root)

**Actions:** [`dx_ui_theme_colors_changed`](#dx_ui_theme_colors_changed)

## Filters

### dx_ui_accessible_markup

Whether a design's markup is repaired for assistive technology as it is served (ref, aria-expanded, hidden links, names).
@param bool $on Whether the page is a design's.
@param int  $id The page.

- `src/Seo/Accessible_Markup.php:236` — value and arguments: `$id > 0 && Page_Meta::is_design_page( $id )`, `$id`

### dx_ui_adopt_brand

Not on a theme that renders the site itself (a classic theme, the
DevriX themes): its palette is the live site's, and it can rewrite
theme.json after this filter — american-restoration re-syncs its
ACF palette at priority 100 — so the design's presets vanished and
every `--dxui-*` token pointing at them turned black, while the
design's `accent` and `border` resolved to the theme's own. The page
then keeps its own literal colours from its stylesheet, and the
site's global styles are left as they are. `dx_ui_adopt_brand`
overrides this.

- `src/Theme/Design_Theme_Json.php:164` — value and arguments: `\DX_UI\Theme\Theme_Compat::may_install_chrome()`, `$page_id`

### dx_ui_arrange_sections

A list of sections in the team's order.

- `src/Pages/Page_Order.php:63` — value and arguments: `true`

### dx_ui_block_library_facts

What the library's sections are filled with.
@param array<string, mixed> $facts
@param int                  $home  The design's Home.

- `src/Pages/Block_Library.php:361` — value and arguments: `$facts`, `$home`

### dx_ui_block_library_themes

The themes whose pages may take the library's sections.
@param array<int, string> $themes Theme folder names (the child's or the parent's).

- `src/Pages/Block_Library.php:189` — value and arguments: `$themes`

### dx_ui_chrome_mode

The header and footer choice an import makes, after it is resolved.
Return install or keep; anything else keeps the resolved mode.
@param string               $mode      install or keep.
@param string               $requested install, keep or '' (automatic).
@param array<string, mixed> $design    The import (see resolve()).
@param string               $reason    Why.

- `src/Chrome/Chrome_Choice.php:237` — value and arguments: `$mode`, `$requested`, `$design`, `$reason`

### dx_ui_copies_old_page

_No comment above the call yet._

- `src/Network/Page_Copies.php:825` — value and arguments: `null`, `$url`

### dx_ui_copy_engine

The engine that answers: the one chosen in Settings (or the fallback the page restyler uses). A filter can give
another, which is how this is tested without asking a provider.

- `src/Pages/Copy_Writer.php:286` — value and arguments: `null`

### dx_ui_crawl_use_ai

The same switch Site_From_Menu::prepare() asks; with no key the
restyle fails and every page falls back to the deterministic pass.

Whether pages are restyled by the AI engine or by the
deterministic pass alone. Filterable, so a site can turn the
paid calls off for the crawl, and so the crawl can be tested
end to end without spending anyone's credit.

- `src/API/Converter_Controller.php:388` — value and arguments: `true`, `0`
- `src/Structures/Site_From_Menu.php:285` — value and arguments: `! is_wp_error( $restyler->engine() )`, `$home_id`

### dx_ui_crawl_words

Whether a page built from an old site's page has its words written by the AI chosen in Settings, place by place in the
measure of its Home's sections. Default: when an AI is chosen and has a key.
@param bool $write true.

- `src/Structures/Site_From_Menu.php:822` — value and arguments: `true`

### dx_ui_default_template

The template a design's pages are given when they are made.
@param string $slug 'dxui-template.php' (the default), 'dxui-blank.php', or 'default' (the theme's Default template: the theme's
                    header and footer, the design's sections kept off its CSS).

- `src/Theme/Blank_Template.php:121` — value and arguments: `self::TEMPLATE`

### dx_ui_default_template_draws_design

Whether a design's page on the theme's Default template is drawn as the DX template draws it — the design's header from
Appearance > Menus, its footer around Appearance > Widgets, the theme's own header and footer and its style sheets left out —
or by the theme, as any page of its own (the default: the theme's header and footer, the design's sections kept off its CSS).
@param bool $draws   Draw it as the design (default false).
@param int  $post_id The page, 0 when the import asks which template to give a new page.

- `src/Theme/Blank_Template.php:91` — value and arguments: `false`, `$post_id`

### dx_ui_design_system

The bounds of the site's design system, read from the theme's own classes: the widest `max-w-<n>px` it has (on a grid of 5px,
1200 on american-restoration) and the largest padding it has (its spacing scale ends at `24-5`: 98px). Null when the theme
has no such classes (no catalogue: there is nothing to keep within). `dx_ui_design_system` (array|null: max_width, padding in
px) changes them, or returns null to switch the fitting off.

- `src/Compiler/Utility_Classes.php:348` — value and arguments: `$found`

### dx_ui_dx_themes

The DX themes: the themes the team builds its sites on — american-restoration, and any child theme of it. They draw the site's header
and footer from the menu locations and widget areas an install writes (`primary-navigation`, `footer-column-1`…`4`,
`footer-copyright`): the header is a menu and the footer is widgets, edited in Appearance, never inside a page.
`dx_ui_dx_themes` adds a theme to the list or takes one off it. DX Base, the theme this plugin carries for a site that has none
of its own (Base_Theme), is on the list: it has the same locations and the same areas.

- `src/Theme/Theme_Compat.php:60` — value and arguments: `array_merge( Adapters\Amr_Adapter::SLUGS, array( Base_Theme::SLUG ) )`

### dx_ui_fence_theme

A page can say so itself (custom field _dx_ui_fence_theme: 1 fences, 0 does not).

- `src/Theme/Theme_Fence.php:112` — value and arguments: `$own === '1'`, `$post_id`
- `src/Theme/Theme_Fence.php:118` — value and arguments: `$default`, `$post_id`
- `src/Theme/Theme_Fence.php:131` — value and arguments: `$own === '1'`, `$post_id`
- `src/Theme/Theme_Fence.php:134` — value and arguments: `'' !== (string) get_post_meta( $post_id, '_dx_ui_static_html', true )`, `$post_id`

### dx_ui_fold_wrappers

Whether a design's page opens in the editor with its wrapper groups folded into the blocks they hold (the default), or with
every wrapper as a block of its own.
@param bool $fold    Fold them (default true for a design's page).
@param int  $post_id The page.

- `src/Blocks/Wrap_Fold.php:66` — value and arguments: `Blank_Template::is_design_page( $post_id )`, `$post_id`

### dx_ui_font_fallbacks

Whether the template's fonts are printed with a fallback face of their size (a local font with their line height and average
width), so the page does not move when they arrive.
@param bool $on Default true.

- `src/Theme/Template_Fonts.php:239` — value and arguments: `true`

### dx_ui_fonts_on_import

Whether a design fresh from an import gets its fonts copied here (the default).
@param bool $on   Copy.
@param int  $home The design's Home.

- `src/Media/Font_Host.php:165` — value and arguments: `true`, `$home`

### dx_ui_footer_holders

The pages of the site's current footer design that a design taking
the widget areas gives their footer back to (as a template part).
Nothing but those pages is written.
@param array<int, int>      $holders Post ids (Footer_Widgets::holders()).
@param array<string, mixed> $record  The stored footer they show.
@param int                  $page_id The page of the design being imported.

- `src/Structures/Structure_Repository.php:1484` — value and arguments: `$holders`, `$record`, `$page_id`

### dx_ui_harmony

The section with its titles and its padding as the Home's, on a ground of the Home's, its head set as the Home's are, and its cards,
eyebrows, questions and buttons in the Home's look.

Whether the same kind of element gets the same style across a design's pages (the Home's).
@param bool $on   On by default.
@param int  $home The design's Home.

- `src/Blocks/Native/Library_Style.php:65` — value and arguments: `true`, `$home`
- `src/Blocks/Native/Style_Harmony.php:81` — value and arguments: `true`, `$home`

### dx_ui_harvest_unreferenced

Only files the design names. Every binary in the archive used to be
uploaded to the media library, and a Claude Design export carries the
designer's own source photos beside the assets the page uses: Semper
Dry's `uploads/` folder is 100 MB of 6–15 MB camera JPEGs, none of
them referenced, and making attachments and thumbnails for them took
88 of the import's 92 seconds — past a real host's request limit
before any block was written. A file counts as named when its file
name (as written or URL-encoded) appears in any of the design's text
files; the `dx_ui_harvest_unreferenced` filter brings back the old
upload-everything behaviour for a design that builds paths at run time.

- `src/Media/Asset_Harvester.php:140` — value and arguments: `false`

### dx_ui_image_quality

Whether a design's photographs (lossy WebP, no transparency) are written again at the highest quality — down to 74 — that
meets Lighthouse's 1/6 of a byte a pixel, when WordPress's quality is over it.
@param bool $on Whether they are.
@param int  $id The attachment; 0 for a file written before it is one (the WebP of a JPEG, a crop for a slot).

- `src/Media/Image_Quality.php:254` — value and arguments: `true`, `$id`

### dx_ui_image_sizes

Whether the pictures of the plugin's own blocks are named after their attachment, so WordPress writes their width, height,
srcset and sizes.
@param bool $on Whether the page is a design's.
@param int  $id The page.

- `src/Media/Image_Sizes.php:102` — value and arguments: `$id > 0 && Page_Meta::is_design_page( $id )`, `$id`

### dx_ui_import_round_seconds

Seconds one round of an import in rounds adds files for: 20, a third of WP Engine's 60-second limit, like the steps of a
page package's import (Transfer\Page_Import::step_budget()). Filterable: `dx_ui_import_round_seconds`.

- `src/API/Converter_Controller.php:1100` — value and arguments: `20.0`

### dx_ui_inline_styles

Whether the plugin's small stylesheets are written into the page (WordPress decides which, by size).
@param bool $on Default true.

- `src/Media/Inline_Styles.php:39` — value and arguments: `true`

### dx_ui_install_chrome

Whether an import may write the design's header into Appearance > Menus
and its footer into Appearance > Widgets.
Not on a classic theme: it renders the site's header and footer itself
(header.php / footer.php) from the very locations and areas the import
would write — `primary-navigation` and `footer-column-1…4` on a DevriX
theme — so installing there would replace the live site's navigation and
move its footer widgets to Inactive on every page. The design keeps its
own header and footer as template parts on its pages instead, and the
site's menus and widgets stay as they are. `dx_ui_install_chrome`
overrides the answer.

- `src/Theme/Theme_Compat.php:126` — value and arguments: `$default`

### dx_ui_interactivity

Whether the site drives a design's states with the Interactivity API: the setting, or `dx_ui_interactivity`.

- `src/Blocks/Interactivity.php:56` — value and arguments: `! empty( Options::get()['interactivity'] )`

### dx_ui_late_fonts

Whether a design's web fonts are asked for once the page has loaded (drawn in their fallback faces until then), so they are
not among what Lighthouse counts before its first and largest paints.
@param bool $on Default true.

- `src/Media/Late_Fonts.php:55` — value and arguments: `true`

### dx_ui_lazy_below

For the footer the DX template draws: true on any page.

For a waiting background on a design's page: the same switch as its pictures'.

Whether the pictures and frames below a design's first screen load lazily.
@param bool $on Whether the page is a design's (for the content; the footer the DX template draws passes true).
@param int  $id The page.

- `src/Media/Image_Loading.php:199` — value and arguments: `true`, `$id`
- `src/Media/Image_Loading.php:269` — value and arguments: `true`, `$id`
- `src/Media/Image_Loading.php:340` — value and arguments: `$id > 0 && Page_Meta::is_design_page( $id )`, `$id`

### dx_ui_lcp_preload

The picture a design's page opens on, asked for first; '' asks for none.
@param string $url The picture behind the page's first section, or '' (with its stylesheet's pictures per screen width,
                   the one of the widest screen).
@param int    $id  The page.

- `src/Media/Lcp_Preload.php:54` — value and arguments: `$url !== '' ? $url : $base`, `$id`

### dx_ui_legible_text

Whether a design's text colours that nearly read on their backgrounds are given the nearest colour that does.
@param bool $on   Default true.
@param int  $home The design's Home.

- `src/Theme/Legible_Text.php:57` — value and arguments: `true`, `$home`

### dx_ui_menu_pages_hosts

Hosts a menu's address may have and still be a page of this site (the old address of a site that moved).
@param array<int, string> $hosts

- `src/Pages/Menu_Pages.php:240` — value and arguments: `$hosts`

### dx_ui_menu_pages_live

Whether the pages of a design's menu are matched with its live site's own menu (its header, menus and footer, read once and
kept for twelve hours): a page the live site has is made at the live site's address, and one the design's menu does not name
is made too.
@param bool $on   Read the live site's menu.
@param int  $home The design's Home.

- `src/Pages/Menu_Pages.php:94` — value and arguments: `true`, `$home`

### dx_ui_meta_description

The description of a design's page for search engines; '' prints none.
@param string $text The excerpt, else the page's lead paragraph, cut to 155 characters.
@param int    $id   The page.

- `src/Seo/Page_Meta.php:105` — value and arguments: `$text`, `$id`

### dx_ui_native_blocks_on_import

Whether a design fresh from an import gets native blocks (the default), or keeps the plugin's own until the
Library converts it.
@param bool $on   Convert.
@param int  $home The design's Home.

- `src/Blocks/Native/Native_Blocks.php:411` — value and arguments: `true`, `$home`

### dx_ui_native_converters

Converters, in the order they run.
@param array<int, Converter> $all

- `src/Blocks/Native/Native_Blocks.php:71` — value and arguments: `$all`

### dx_ui_network_activation_sites

`dx_ui_network_activation_sites`: how many sites of the network a network-wide (de)activation goes through, at most (500).

- `src/Plugin.php:225` — value and arguments: `500`
- `src/Plugin.php:337` — value and arguments: `500`
- `src/Support/Runtime.php:95` — value and arguments: `500`

### dx_ui_new_page_status

This filter is documented in src/Structures/Site_From_Menu.php

The status of a page an import makes new from its design's menu (a published page that is there already stays so):
a draft while a person looks at the whole site before it goes live (Network\Site_Review).
@param string $status  'publish'.
@param int    $home_id The design's Home.

- `src/Pages/Team_Pages.php:2033` — value and arguments: `'publish'`, `$home`
- `src/Structures/Site_From_Menu.php:982` — value and arguments: `'publish'`, `$home_id`

### dx_ui_page_css_trim

Whether this page's stylesheet keeps only the rules its markup can use (Page_Css_Trim).
@param bool $on      Default: when the sheet is over Page_Css_Trim::MIN_BYTES.
@param int  $page_id The page.

- `src/Compiler/Page_Css_Trim.php:47` — value and arguments: `$on`, `$page_id`

### dx_ui_pages_engine

`dx_ui_pages_engine`: what builds the pages the design's menu links to — 'design' (from its own sections) or 'ai' — for this Home.

- `src/Structures/Site_From_Menu.php:306` — value and arguments: `$engine === 'ai' ? 'ai' : 'design'`, `$home_id`

### dx_ui_picture_sizes

The image sizes of the block a design's picture gets, for the desktop and for the phone. The block's own default for a phone is a
4:5 crop (made for a hero), which changes the shape of any other picture.
@param array{desktop:string, mobile:string} $sizes         Defaults.
@param int                                  $attachment_id The image.

- `src/Blocks/Native/Picture.php:243` — value and arguments: `array( 'desktop' => 'dx_pic_desktop_hero', 'mobile' => 'dx_pic_desktop_hero', )`, `$attachment_id`

### dx_ui_restyle_engine

The engine that rebuilds crawled pages: the one chosen in Settings.
This used to prefer DeepSeek whenever a DeepSeek key was saved, which
made the crawl spend DeepSeek credit on a site whose Settings said
Claude — the import screen named one engine and the bill came from the
other. The active engine wins now; DeepSeek (then any engine with a key)
is only the fallback when the active one cannot run, e.g. its key is
missing. `dx_ui_restyle_engine` can name a specific engine id.

- `src/Structures/Live_Page_Restyler.php:41` — value and arguments: `''`

### dx_ui_seo_plugin

Whether an SEO plugin may write the pages' descriptions: then the plugin's waits for the head, and is printed only when it has none.
@param bool $on Whether one of the known SEO plugins is active.

- `src/Seo/Page_Meta.php:144` — value and arguments: `$on`

### dx_ui_site_links

Whether a design's links are read as the site has them: under its folder, and to the Home's sections from other pages.
@param bool $on Default true.

- `src/Chrome/Site_Links.php:225` — value and arguments: `true`

### dx_ui_style_rules_markup

Filters the block markup whose styles are written with a page's.
Markup the page renders that is not in its content or in a part or
pattern it references — the site header built from a menu, the
footer's widget contents. Each string's dxs- rules, dxuiInner rules
and utility classes are written with the page's rules, front
end and editor canvas. Part of the cache key, so return the same
strings for the same content.
@param array<int, string> $markup  Block markup strings.
@param int                $post_id The page being styled.

- `src/Blocks/Style_Rules.php:833` — value and arguments: `array()`, `$post_id`

### dx_ui_team_library

What the library is for in a run of the pages made from a Home (LIBRARY).
@param string $policy 'team' (the team's layouts), 'fill', 'prefer' or 'off'.

- `src/Pages/Team_Pages.php:258` — value and arguments: `$policy`

### dx_ui_template

The template to use, by slug ('generic' for the generic library); '' for the active theme's own.
@param string $slug

- `src/Pages/Template.php:116` — value and arguments: `''`

### dx_ui_template_fonts_choice

What a person chose for the site's fonts. A theme with settings of its own says so here (headlines, body); anything in it keeps the
template's fonts out of use.
@param array<string, string> $out The choice read from the DevriX theme's settings.

- `src/Theme/Template_Fonts.php:111` — value and arguments: `$out`

### dx_ui_template_fonts_live

Whether the template's fonts are used. A site that wants the theme's own fonts back says no here.
@param bool                 $live The default: installed, and no choice in the theme's settings.
@param array<string, mixed> $r    The record.

- `src/Theme/Template_Fonts.php:132` — value and arguments: `true`, `$r`

### dx_ui_templates

The templates a site has.
@param array<string, array<string, mixed>> $all By slug.

- `src/Pages/Template.php:67` — value and arguments: `$all`

### dx_ui_theme_color_mode

`dx_ui_theme_color_mode`: 'follow' (the design's colours bound to the theme's palette) or 'keep' (its own), for a Home and the palette.

- `src/Theme/Theme_Binding.php:170` — value and arguments: `$mode`, `$home`, `Theme_Palette::current()`

### dx_ui_theme_components

The classes of the page's content whose theme rules are kept inside the design's sections.
@param array<int, string> $classes The classes found.
@param string             $markup  The page's content.

- `src/Theme/Theme_Components.php:101` — value and arguments: `array_map( 'strval', array_keys( $out ) )`, `$markup`

### dx_ui_theme_fonts

The fonts the active theme lets the site choose. The default is read from the DevriX typography functions;
a theme with settings of its own returns the same shape, and `false` says the site's fonts are the design's.
@param array<string, string>|null|false $fonts {id, heading, body, heading_slug, body_slug, faces}: the two CSS
                                               font stacks, the slugs of the choices, and the @font-face rules to print.

- `src/Theme/Theme_Fonts.php:115` — value and arguments: `self::read_theme()`

### dx_ui_theme_fonts_follow

Whether a design uses the theme's fonts.
@param bool $follow Default: yes, unless the design was set to keep its own.
@param int  $design The design's Home page id.

- `src/Theme/Theme_Fonts.php:183` — value and arguments: `! $keep`, `$design`

### dx_ui_theme_has_block_library

Whether the active theme brings what the library's sections need (global styles, the FAQ's styles and script) on this page. On, the
plugin prints none of its own.
@param bool $can The theme is known to, and the page is not a converted one.

- `src/Blocks/Library_View.php:69` — value and arguments: `$can`

### dx_ui_theme_has_youtube_facade

Whether the active theme brings the facade's script and styles on this page. On, the plugin prints neither.
@param bool $can The theme is known to, and the page is not a converted one.

- `src/Blocks/Youtube_Facade_View.php:54` — value and arguments: `$can`

### dx_ui_theme_option

A value of Theme Global Settings, from somewhere else (a site that keeps them another way) or for a check.
@param mixed                $value The value ACF has, or null.
@param array<int, string>   $path  The names down to the field.

- `src/Theme/Theme_Options.php:55` — value and arguments: `$value`, `$path`

### dx_ui_transfer_font_hosts

Hosts a packaged page may load font stylesheets from.
@param array<int, string> $hosts

- `src/Transfer/Page_Import.php:1493` — value and arguments: `self::FONT_HOSTS`

### dx_ui_transfer_step_seconds

How long one step of an import from the admin screen may add media:
20 seconds — a third of WP Engine's 60 s request limit, and less when
PHP's own limit is lower and cannot be raised. Filterable
(`dx_ui_transfer_step_seconds`); 0 imports in one request.

- `src/Transfer/Page_Import.php:200` — value and arguments: `$budget`

### dx_ui_trim_min_bytes

`dx_ui_trim_min_bytes`: the smallest page, in bytes, whose unused theme CSS is trimmed (never under 16 KB).

- `src/Theme/Theme_Trim.php:97` — value and arguments: `self::MIN_BYTES`

### dx_ui_trim_theme_css

A DevriX theme prints its whole stylesheet inline in every page's head (american-restoration: 1.1 MB, 1.9 MB once
Theme_Fence has fenced it), and a page with a design on it uses about 2% of that. The browser parses all of it
before the first paint: on a phone that was most of the first-paint time of the Semper Dry pages (83 in
PageSpeed's mobile score, 92-99 with the sheet cut down).
The page is buffered, and the finished document decides: Css_Trim keeps the rules its classes, ids, element names
and scripts can match, and the block is replaced by that. What is kept is a subset of the sheet in its own order,
and nothing that needs a state (hover, focus, an open menu, a class a script adds) counts against a rule, so the
page renders as it did.
The result depends only on the page's tokens that the sheet has a name for (Css_Trim::vocabulary()), so it is
cached by exactly those, in uploads/dx-ui/trim/<sheet>/: a request that finds its file only reads the page for
its tokens, and a page whose markup changes (a new post in a list, a menu item) is trimmed again on its own.
Nothing here is content: the files are made again whenever they are missing. Every doubt serves the theme's
whole sheet as before — an unreadable sheet, a document flushed in pieces, a cache that cannot be written, a
page whose tokens keep changing.
Only for a visitor who is not logged in (the admin bar and the editor's tools are not what a visitor sees), on a
page that shows a design and still carries the theme's stylesheet (a design page on the blank template carries
none). `dx_ui_trim_theme_css` (bool, post id) and the setting `dx_ui_trim_theme` turn it off.

- `src/Theme/Theme_Trim.php:77` — value and arguments: `true`, `$id`

### dx_ui_trusted_proxies

The connecting address. X-Forwarded-For is only believed when the
connection comes from a proxy the site lists as trusted (the
dx_ui_trusted_proxies filter): anyone can send the header, so
trusting it by default let a bot pick a fresh address per request
and never reach the limit.

- `src/API/Form_Controller.php:227` — value and arguments: `array()`

### dx_ui_update_channel

`dx_ui_update_channel`: 'stable' (releases only) or 'beta' (pre-releases too); by default the channel of the version running.

- `src/Update/Github_Updater.php:72` — value and arguments: `$default`

### dx_ui_update_checkout

`dx_ui_update_checkout`: whether this copy is a development checkout, which is offered no update.

- `src/Update/Github_Updater.php:65` — value and arguments: `$checkout`

### dx_ui_update_repository

`dx_ui_update_repository`: the GitHub repository ("owner/name") DX updates from; '' switches the updates off.

- `src/Update/Github_Updater.php:48` — value and arguments: `self::REPOSITORY`

### dx_ui_use_dx_picture

Whether images become DX Picture where the site has the block. Off, they stay the core Image.
@param bool $on The block is registered.

- `src/Blocks/Native/Picture.php:71` — value and arguments: `parent::available()`

### dx_ui_video_hidden_embeds

Whether the two hidden embeds of the example are written. Off, a video is its two facades alone.
@param bool $can This WordPress knows block visibility.

- `src/Compiler/Video_Facade.php:117` — value and arguments: `$can`

### dx_ui_webp

Whether a design's JPEG or PNG picture is stored as WebP (when the WebP is smaller).
@param bool   $on       Whether the server can write WebP.
@param string $filename The picture's file name.

- `src/Media/Webp.php:62` — value and arguments: `$able`, `$filename`

### dx_ui_zip_limits

The ceilings, after `dx_ui_zip_limits`; a missing, zero or negative
value keeps the default rather than switching the guard off.

- `src/Connectors/Zip_Extractor.php:1067` — value and arguments: `self::LIMITS`

### dx_ui_zip_work_root

A new, empty folder for one import.
Under the system temp directory when PHP can write there, which the web
server does not serve; `dx_ui_zip_work_root` moves it, for a host
whose temp directory is unusable. Otherwise in uploads/dx-ui, which it
does serve. Either way the folder is made by PHP with mode 0700 and a
64-bit random name, directly in the root (never inside a shared
subfolder another account could have pre-made on a shared /tmp), and
gets an index.php and an .htaccess denying access before anything else
is written into it. get_temp_dir() itself falls back to wp-content
when nothing better is writable, and WP_TEMP_DIR can point anywhere, so
the guard files are written wherever the folder lands rather than only
in uploads.
On nginx the .htaccess means nothing; there the 0700 mode keeps a web
server running as another user out of the folder (php-fpm pools with
their own user are the usual VPS setup), and the random name keeps it
unguessable when it runs as the same user.
A root without room for $need bytes (plus a tenth) is passed over: a
small tmpfs /tmp or a nearly full cPanel securetmp is common, and the
uploads fallback is usually on the larger disk. Only when no root has
room is the import refused for space.

Remove import work folders left in the temp directory by a request that
was killed before it could clean up (Zip_Extractor makes them there as
`dx-ui-tmp-` plus 16 hex digits; the ones in uploads/dx-ui are removed
per site above). Only this exact pattern, and only folders PHP may write,
so another account's on a shared /tmp is left alone.

- `src/Connectors/Zip_Extractor.php:876` — value and arguments: `get_temp_dir()`
- `uninstall.php:293` — value and arguments: `get_temp_dir()`

## Actions

### dx_ui_theme_colors_changed

`dx_ui_theme_colors_changed`: a design's colours were bound to the theme anew (its Home given); what was drawn from the old binding is stale.

- `src/Theme/Theme_Binding.php:212` — arguments: `$home`

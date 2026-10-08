# План: архитектура на плъгина като общ конвертор (Claude Design / Lovable / Figma / HTML → Gutenberg)

Написан на 2026-10-07, след beta.32. Отговаря на въпроса „какво ни липсва на архитектурно ниво, за да е плъгинът общ конвертор на дизайни, а не инструмент за един дизайн". Другите два плана (`PLAN-TEAM-PAGES.md`, `PLAN-DX-TEMPLATE.md`) остават в сила за това, което описват; този е над тях.

## 1. Какво искаме

Един плъгин, който взима дизайн от какъвто и да е източник (Lovable/React, Claude Design `.dc.html`, Figma, HTML/ZIP, жив сайт; утре Figma Make, Framer, Webflow, v0, Bolt) и го превръща в редактируем Gutenberg сайт на всяка тема, пиксел за пиксел, с хедър в Menus и футър в Widgets, с още страници в стила на дизайна, и който казва честно какво е пренесъл и какво не. Всяка стъпка след четенето на източника трябва да е **независима от източника**, иначе всеки нов източник е нов плъгин.

## 2. Как е построен днес (честно)

```
Connectors ──► Source_Document ──► Source_Compiler ──► Html_To_Blocks ──► dxai-ui/* блокове + dxs-* класове + scoped CSS
Lovable (JSX), Dc (.dc.html),  (kind, title,         Jsx_Compiler /          (HTML → блокове)
Figma REST+ZIP, HTML ZIP,       payload, assets)      Dc_Renderer+Dc_Js /                  │
Live fetch, Generated ZIP                             HTML документ                        ▼
                                                                          Structure_Repository::save()
                                                                          страници, части, менюта, patterns, токени (Design_Tokens, евристика)
                                                                                           │
          след факта, от записаните блокове: Section_Library (секции), Section_Roles, Header_Template/Menu_Tree (хедър → Menus),
          Native converters (core/amr/dx блокове), Team_Pages (рецепти от 11 ресторационни сайта), Block_Library, Design_Coverage
```

Какво следва от това:

1. **Няма общ модел на дизайна.** `Source_Document` е плик: `kind`, `title`, `payload` (различен за всеки конектор), `assets`, `logs`. Всичко, което е „факт за дизайна" (страници, навигация, хедър/футър, токени, секции, шрифтове, breakpoints, поведения), се извлича **след** компилацията, от HTML-а и от блоковете, с евристики (`Section_Library::for_page` чете `post_content`; `Menu_Tree::from_html` чете HTML; `Design_Tokens::resolve` чете CSS). Фактите не са записани никъде като едно нещо — панелът, рецептите, хромът и проверките ги извличат всеки за себе си.
2. **Figma не е детерминистичен път.** `Figma_Connector` има REST и ZIP вход, но Figma дава дърво от възли с auto-layout, ограничения, променливи, компоненти — не HTML. Днес това минава през генериране с модел (`Prompt_Builder`), т.е. през AI, а Lovable и Claude Design — през детерминистичен компилатор. Двата пътя нямат обща среда.
3. **Токените са евристика, стилът е CSS.** `Design_Tokens` връща `source: heuristic` (OKLCH хрома над праг = бранд). Изходът е `dxs-*` класове и стилов лист на дизайна. Точен, но theme.json presets, Global Styles и block supports се заобикалят; `Text_Color` и `Sizes` са първите стъпки обратно към настройките на блока. Figma дава токените наготово — няма къде да ги сложим.
4. **Два свята от блокове, договор с темата по подразбиране.** Първо `dxai-ui/box|text|link|image|svg|html|details|countup`, после Native converters ги пренаписват в core/`amr/*`/`dx/picture`. Какво темата може (picture блок, стилове на бутони, палитра, шрифтове, размери, локации на менюта, widget areas) е разпръснато в `Theme_Compat`, `Theme_Fence`, `Theme_Trim`, `Theme_Buttons`, `Template_Fonts`, `Base_Theme`.
5. **Поведението е собствен runtime.** `Dc_Js*` интерпретира JavaScript на Claude Design, `Motion_Runtime`, scroll state и слайдерите живеят в `runtime/dxai-ui-runtime.php`. Interactivity API не се ползва.
6. **Страниците са за един вертикал.** `Team_Pages::KINDS`, рецептите (`data/team-pages.json`: 11 сайта, 283 страници) и библиотеката (Arcus/Archer) са ресторационни; „industry gate" е по речник.
7. **Качеството се мери извън продукта.** 60 проверки и puppeteer вратите са на тестов сайт на една машина; CI няма, PHPStan няма, phpcs не се пуска. В плъгина има `Fidelity_Gate`, `Design_Coverage`, `Import_Audit`, но човекът не вижда „доклад от конверсията".
8. **Жизнен цикъл.** Дизайн = страници + десетки `_dxai_ui_*` мета + опции, един `upgrade()`. Няма манифест (хеш на източника, версия на компилатора, опции), следователно няма „преконвертирай с новия компилатор и запази редакциите на човека".

## 3. Към какво отиваме

| Слой | Днес | Цел |
| --- | --- | --- |
| Източници | плик + път на конектор | **Design IR** (точка 4): един документ за дизайна, който конекторите *пълнят*, а всичко след тях *чете* |
| Стил | scoped CSS, евристични токени | **Tokens-first**: палитра/типография/разстояния/радиуси като presets на дизайна (theme.json style variation), блокове вързани към presets; остатъчен CSS само за неизразимото, с мерен бюджет |
| Блокове | dxai-ui/* → native пренаписване | **Регистър за съответствие** (разпознавател → блок + supports + fallback, с увереност) и **договор за възможности на темата** с адаптери (AMR, DX Base, друга класическа, блокова) |
| Поведение | собствен runtime | **Interactivity API** директиви + store; runtime остава fallback |
| Страници | ресторационни рецепти в кода | **Пакети рецепти по вертикал** като данни; анализаторът на живи сайтове като инструмент за учене |
| Качество | suites на една машина | **Доклад от конверсията** в продукта, пазен с дизайна; CI; eval за AI стъпките |
| Цикъл | мета + undo | **Манифест** в IR, версия на схемата с миграции, преконверсия с 3-way merge |
| Платформа | ръчен ZIP | CI, updater от GitHub releases, Playground blueprint, документирани hooks, `wp dxai-ui`, Abilities API |

## 4. Design IR — спецификацията

Един JSON документ на дизайн, записан на Home-а (`_dxai_ui_design_document`), версиониран (`v`), с отпечатък (`fingerprint` = хеш на `identity()`: само фактите за дизайна — адреси и заглавия на страниците, роля/вид/заглавие/думи/повторения на секциите, навигацията, токените, breakpoints, броят активи, поведенията; не и id-та на постове, имената, които импортът дава на секциите, подписите на блоковете, къде е сложен хромът — затова един дизайн има един отпечатък преди и след запис и на всеки сайт). Пространство `DXAI_UI\Design`. Правила: (а) всичко в него е факт за **дизайна**, не за сайта (id-та на страници са анотация, не ключ); (б) всяко поле казва откъде е (`source`: `design` — прочетено от източника, `compiled` — изведено от компилирания HTML/блокове, `heuristic`); (в) документ, който не може да се построи, никога не проваля импорта — записва се бележка.

```
Document
  v: 1
  source   { kind: lovable|claude-design|html|generated|figma|live, name (архивът), stack, static_html, hash }
  title, home_slug
  pages[]  Page { slug, title, file, id (след записа), words, sections[] Section, sections_source: compiled|saved, source_sections[] (имената от източника),
             forms, links[] {url, internal}, meta {title, description} }
  Section  { index, role (Section_Roles: hero|trust|process|two-col|cards|reviews|related|areas|form|faq|cta|content|text),
             kind (Section_Library), name, heading, words, repeats, has {image, form, button, video}, sig }
  regions  { header: Region|null, footer: Region|null }
  Region   { present, placed: content|part|menus|widgets, nav[] NavItem, logo {src, alt}|null, actions[] {label, url} }
  NavItem  { label, url, description, children[] }
  tokens   { source: theme|heuristic|default, roles {brand, accent, ink, body, muted, surface, border, radius, font},
             palette[] hex, fonts[] {family, weights[], url, files}, screens {name: px} }
  breakpoints[] px
  assets   { images, svg, videos, fonts, files, list[] {url, alt} (до 200) }
  behaviours[] { kind: handlers|motion|radix|scroll|media-query|lucide|countup|slider|form, count, compiled: true|false|null, note }
  layout   null във v1 (точка 7, фаза 1б): дърво на оформлението (stack/flex/grid, ограничения, размери по breakpoint)
  report   { unevaluated[] {kind, source, count}, notes[], coverage[] {dimension, source, page, short} }
  built    { plugin, compiler, at }
```

Кой чете IR (фаза 1в, по ред): докладът от конверсията (REST + CLI + карта), `Menu_Pages` (навигацията, когато хедърът е част, не меню), предложенията на панела (услуги/места от секциите вместо от детекторите), `Design_Coverage` (страната на източника), `Team_Quality` (броят на хрома), пакетите за пренос (`Package` носи документа). Кой го **пише**: `Structure_Repository::save()` в края, от резултата на компилатора и от записаното; конекторите добавят каквото знаят само те (Lovable: маршрути и секциите на `Tsx_Section_Splitter`; Claude Design: breakpoint, бележки на `Dc_Renderer`; Figma: възли и променливи — фаза 5).

## 5. Договор за възможности на темата (фаза 2)

`DXAI_UI\Theme\Capabilities` отговаря на: `picture_block()` (dx/picture | core/image), `button_styles()` (Theme_Buttons), `palette()` и `fonts()` (presets, Theme Global Settings, DX Base опции), `layout_sizes()` (content/wide, root padding), `menu_locations()`, `widget_areas()`, `draws_chrome()` (Chrome_Choice), `has_block( name )`, `stylesheet_mode()` (fence/trim). Адаптери: `Amr_Adapter`, `Dx_Base_Adapter`, `Classic_Adapter`, `Block_Theme_Adapter`. Нищо в компилатора и в страниците не пита за името на темата; пита договора. Проверка: един и същ дизайн през четирите адаптера дава едни и същи елементи и думи (по начина на `verify-base-theme`), и `grep` за `american-restoration` извън адаптера е 0.

## 6. Известни ограничения и рискове

- IR v1 извлича секциите, навигацията и токените **от компилирания изход** (`source: compiled`), защото той е доказан пиксел за пиксел на 28 дизайна; четенето им от самия източник (JSX дърво, Figma възли) е фаза 1б/5. Документът казва откъде е всяко поле, за да не се представя за повече.
- Преходът към tokens-first (фаза 2) може да счупи пиксел-перфектния изход. Пазач: корпусният harness като регресионна врата (един и същ изход преди/след, адаптер по адаптер), както при native blocks.
- Interactivity API (фаза 3) не покрива всичко, което `Dc_Js` интерпретира (произволни функции в `renderVals()`); runtime-ът остава за остатъка и докладът го казва.
- Figma (фаза 5) няма media queries: breakpoints се извеждат от кадрите по ширина; това е евристика и се казва.
- Документът не записва хоста на дизайн, свален от жив сайт (`source` е архивът): навигацията на Semper Dry в документа сочи `semperdrywr.com/service/…`, а `Menu_Pages` го чете като „друг сайт" и не предлага страниците (9 `external`, 9 `anchor`); Arcus е едностраничен (16 котви) — вярно. Поправката е `source.origin` в IR от `Site_Origin` при crawl (фаза 1б), тогава адресите на собствения стар сайт са вътрешни.

## 7. Фази и проверки

| Фаза | Какво | Проверка | Състояние |
| --- | --- | --- | --- |
| 1а | Design IR v1: `Design\Document` (+ `Page`, `Section`, `Region`, `Tokens`), `Document_Builder` от резултата на компилатора и записаното, `Document_Store` (мета на Home, версия, fingerprint), запис в края на `Structure_Repository::save()`, `GET /dxai-ui/v1/design/{id}`, `wp dxai-ui design <id>`; Claude Design дава breakpoint и бележки, Lovable — маршрути | `verify-design-document.php` (64 проверки, 13 мутации): документ от `.verify/dc-minimal.zip` и от Lovable ZIP (без запис), документ след запис (същият fingerprint, с id-та), кръг JSON, `problems()`, store, маршрут и права (и в таблицата на `verify-rest-routes`), CLI | **готово** (2026-10-07) |
| 1б | Произход и произход на секциите: `source.origin` (хостът на собствения сайт на дизайна — от crawl-а, от връзките на хедъра, от връзките на страницата; `Site_Origin`), и `Menu_Pages` го брои за този сайт (Semper Dry: 7 страници + 2 списъка от документа, преди 0); секциите се четат структура по структура и всяка знае компонента/екрана, от който компилаторът я е направил (`source_name`; Lovable: Hero, Problem…; Claude Design: screen label), и какво казват класовете на дизайна за оформлението ѝ (`layout`: колони по breakpoint, посока на flex, центриран текст, най-широко съдържание — само където дизайнът го казва с класове, иначе `null`). Дървото на оформлението (box моделът на всеки възел) остава за фаза 5 с Figma. | `verify-design-document.php` (80), `verify-menu-pages.php` (73); 5 мутации (4 хванати от документа, 1 от менюто). На корпуса (32 документа): 10 с собствен хост, 316 секции — 168 знаят структурата си, 102 имат оформление от класове | **готово** (2026-10-08) |
| 1в | Читатели на IR: карта Library › Design report (`GET /design`, `POST /design/{id}` rebuild), ред в резюмето на импорта (`created.document`), `Menu_Pages::tree_of()` от `regions.header.nav`, когато хедърът е част и няма менюта (панелът: „From the design's header"), предложенията на панела от `pages[].sections[].items` (секцията, която говори за услуги / места), документът в пакет за пренос (id-тата се търсят отново по адрес при четене). Не е направено: `Design_Coverage` от `report` (покритието вече е в документа; четенето на обратно би било само преместване) | `verify-design-document.php` (72), `verify-menu-pages.php` (71, +5), `verify-rest-routes.php` (26, новите маршрути в таблицата и с лош вход); 8 мутации за читателите (5 хванати от документа, 2 от менюто, 1 от маршрутите); картата отворена в браузър на тестовия сайт | **готово** (2026-10-08) |
| 2 | Tokens-first + договор за възможности на темата (точка 5) | корпусът преди/след: елементи и думи еднакви; остатъчен CSS в байтове на дизайн, мерен и записан в `report`; `grep` за името на темата извън адаптерите = 0 | не е започнато |
| 3 | Interactivity API за състоянията (меню, табове, акордеон, слайдер, scroll state) | breadth harness 28 × 3 ширини; какво остава на runtime — в `report.behaviours` | не е започнато |
| 4 | Платформа: CI (phpcs, PHPStan, `node --check`, suites върху wp-env/Playground), честен пакет (`verify-team-quality` с фикстура, SKIP код за `verify-template-fonts`), updater от GitHub releases, Playground blueprint, документирани hooks, eval за AI стъпките | зелен pipeline на `main`; release workflow качва ZIP-а | не е започнато; независимо от 1–3 |
| 5 | Figma детерминистично (възли → IR), рецепти по вертикал като данни | Figma фикстура → IR → страница; корпус от втори вертикал | не е започнато |

## 8. Решения, които чакат потребителя

1. Фаза 2: токените на дизайна като **style variation** на темата (едно theme.json на дизайн, превключваемо) или като **presets върху активната тема** (един дизайн на сайт)? Препоръка: style variation.
2. Фаза 4: CI върху **wp-env** (Docker) или **Playground** (без Docker, по-бавно за 60 suites)? Препоръка: wp-env за suites, Playground за demo.
3. Фаза 5: кой е вторият вертикал за рецепти (адвокати? ресторанти? SaaS от Lovable корпуса?).

## 9. Състояние

- 2026-10-07: планът написан; фаза 1а готова: `Design\Document` (+ `Section_Reader`, `Document_Builder`, `Document_Store`), запис в края на `Structure_Repository::save()`, `GET /dxai-ui/v1/design/{id}`, `wp dxai-ui design <id>|list|rebuild <id>|all` (документ за дизайн, импортиран преди документите, от снимката на конверсията, която всеки запис пази); компилаторът носи `source_kind`, `source_stack`, `source_hash`, `dc_breakpoint`, `source_sections`. Какво показа фикстурата (dc-minimal): четене на секциите само от markup взимаше футъра за секция (след него има фиксирана лента без inline CSS и `chrome_blocks()` не намира футър); документът чете тялото на страницата от структурите на компилатора без header/footer/navigation, както прави и записът — преди и след запис 6 секции, един и същ fingerprint. Нищо още не четеше документа вместо блоковете (фаза 1в).
- 2026-10-08: фаза 1в готова (виж таблицата). Фаза 1б готова същия ден: `source.origin`, `source_name` и `layout` на секциите (четени структура по структура: при склеен markup страница с повече секции, отколкото структури, не знаеше коя откъде е); `Menu_Pages` вече предлага страниците на свален сайт (Semper Dry). Казано честно: `layout` чете само имена на класове (Tailwind), не изчислен стил; Claude Design ги има отчасти (`text-center`, `max-w-*`), Lovable — изцяло. Секциите в документа носят `items` (имената на картите/стъпките/въпросите; за елемент без heading блок — първият heading таг, иначе първият кратък ред). Две неща, казани честно: предложенията от документа са само резервен път (когато детекторите на Home не намират нищо) и зависят от заглавие на секция, което говори за услуги или места; документът на дизайн, импортиран преди документите, казва `source.kind: unknown`.

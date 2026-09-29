#!/usr/bin/env node
/**
 * Import a set of design ZIPs and verify the result, in one command.
 *
 * The checks that matter after a conversion do not fit in one tool: geometry
 * against the design needs a reference build and a browser, block validity
 * needs the real editor, behaviour needs a live page, and the PHP suites need
 * WordPress loaded. Running them by hand meant six invocations and remembering
 * which page ids the import had just produced — so a change would land, one
 * check would get run, and the others would be assumed.
 *
 *   node bin/verify-import.cjs --wp <wp-root> --zip "<a.zip>" --zip "<b.zip>"
 *
 * Each archive is imported IN PLACE, one at a time — Zip_Router::import(),
 * Source_Compiler::compile(), Structure_Repository::save(), as an
 * administrator — and nothing is purged: a design's pages are identified by
 * (archive, slug), so a re-import updates them where they are, and pages the
 * run did not import (a person's own imports, paid crawled pages) are left
 * alone and are not measured.
 *
 * The site's header and footer are the site's, not a page's: an import that
 * installs them writes the design's header into the menu locations
 * (Appearance > Menus) and its footer into the one set of widget areas
 * (Appearance > Widgets), and only one design can hold them. So every archive
 * is imported with the header and footer KEPT (save()'s `chrome` = 'keep',
 * Chrome_Choice): each design's pages carry its own header and footer as
 * template parts, the site's menus and widgets are not touched — the run
 * checks that they were not — and every page is measured against its own
 * design. The stages that look at a page still run for each design right
 * after its own import, because the brand (the global-styles palette) is
 * the last import's on a block theme.
 *
 * Then the chrome stage re-imports ONE archive (--chrome-zip; the last
 * `--zip` that is not a repo fixture by default) with the header and footer
 * INSTALLED, and checks the direction end to end: the header built in
 * Appearance > Menus (the locations hold the design's own menus, each marked
 * as that location's header menu and owned by the design, none pointing at a
 * page that is gone), the footer in Appearance > Widgets (the stored footer
 * is this design's and its areas hold its blocks), the page
 * [site-header][skip link][sections][site-footer] with every section exactly
 * as the kept import wrote it, the header and footer blocks rendering the
 * same DOM as the template parts they were built from, no style="" anywhere
 * in what was saved, strict geometry against the design (0px, and nothing
 * lost, added, resized or repainted), the editor (0 invalid blocks, the
 * footer's widgets parsed clean), keyboard access to the menus the header now
 * renders, the pages a crawl made for it (when one ran) carrying the same two
 * blocks, and a second install landing in place (same page, same menus, no
 * widget created). A header whose menu slots cannot be read is refused by
 * the import and must keep its template part, published. Pass --chrome-zip
 * more than once to install several designs in turn: each earlier one must
 * keep its own header and footer on its pages after the next takes the site's.
 *
 * When the run is over, the site's header locations, footer, footer widgets,
 * brand and logo are put back as they were before it (--keep-chrome leaves
 * the chrome stage's last design in place). Nothing is deleted doing so:
 * widgets the run created end in Inactive widgets, and the menus it wrote
 * stay. A test run (--title-prefix) then moves to the trash what it wrote —
 * its pages, their conversions, patterns, template parts and navigation
 * posts (a part or pattern an import of them brought back from the
 * trash included), never a page older than the run or of another archive
 * (--keep-pages leaves them).
 *
 * To start from an empty site, run bin/purge-and-import-real-zips.php by
 * hand first (it DELETES every generated page, crawled ones included), then
 * verify with --skip-import.
 *
 * To verify a site that is already populated, still pass every `--zip`, and
 * add `--skip-import`. The ZIPs are what the geometry stage compiles its
 * oracles from, so without them there is nothing to measure against and that
 * stage fails rather than reporting a pass it did not earn. Only the design
 * whose footer is installed can then measure 0px around its footer; every
 * other design's page says whose footer it is showing:
 *
 *   node bin/verify-import.cjs --wp <wp-root> --skip-import --zip "<a.zip>" …
 *
 * Options:
 *   --wp          WordPress root                                  (required)
 *   --zip         a design ZIP; imported unless --skip-import, and in either
 *                 case the source the geometry oracle is built from
 *   --chrome-zip  the design the chrome stage installs as the site's header
 *                 and footer (repeatable; imported with the rest first, if it
 *                 is not among them). Default: the last --zip
 *   --no-chrome-repeat  skip the chrome stage's second, in-place install
 *   --skip-import verify what is already on the site, importing nothing
 *   --title-prefix  put this in front of every imported page's title (and so
 *                 its slug), e.g. "DXAI TEST ": a test run then writes pages
 *                 of its own instead of updating the real imports of the same
 *                 archives, and trashes them at the end
 *   --keep-pages  a test run leaves its pages published at the end
 *   --keep-chrome leave the chrome stage's last design's header, footer and
 *                 brand installed instead of restoring the site's
 *   --no-fixtures leave out the repo's own fixture designs. They are packed
 *                 from fixtures/ and appended to the ZIP list on every run, so
 *                 a project shape no export in Downloads happens to cover —
 *                 the classic Tailwind v3 template, most of all — is measured
 *                 every time rather than when somebody remembers to
 *   --strict      also fail geometry on a lost node, a spurious node, a
 *                 rendered-word difference, or a
 *                 width/margin/padding/gap/font-size difference. Off by
 *                 default: those three numbers are printed on every run, and
 *                 the gate should be flipped once a run is clean rather than
 *                 turned on over known failures. (The chrome stage's page is
 *                 always measured strictly, paint included.)
 *   --work        directory for the oracles          (default: <repo>/.verify)
 *   --widths      viewports to measure          (default: 1280,768,390)
 *   --only        run a subset: import,chrome,geometry,react,blocks,widgets,
 *                 behaviour,suites (chrome: the install stage above, plus how
 *                 each design's header and footer were built — kept as
 *                 template parts, Menus, Widgets, or refused and why;
 *                 widgets: the footer's block widgets in the real editor)
 *   --react       also measure every design against its REAL React build — see
 *                 bin/react-oracle.sh. Off by default: it installs and builds
 *                 each project, which costs minutes on the first run per design
 *                 (node_modules are cached per lockfile afterwards). This is the
 *                 only stage that can see a JSX-reading loss; the compiled
 *                 oracle builds its design side with our own Jsx_Compiler, so
 *                 such a loss sits on both of its sides and reports 0px.
 *   --strict-react  make the React pixel total a pass condition. Structure is
 *                 deliberately never gated here — a converted page carries
 *                 hidden dropdown panels that React renders only when opened.
 *   --react-port  first port for the React oracles, one per design (default 5199)
 *   --dc-port     first port for the Claude Design oracles — bin/dc-oracle.cjs
 *                 serves a .dc.html export's own runtime as the design side of
 *                 the geometry stage, one port per such design (default 5301)
 *   --php         PHP binary                               (default: php)
 *   --php-ini     a php.ini to pass as -c
 *   --wp-cli      wp-cli.phar                          (default: wp on PATH)
 *   --chrome      Chrome binary
 *   --allow-llm   also run the suites that call a paid model (the live
 *                 restyle smoke test, the site-from-menu crawl). Off by
 *                 default: each run would spend the site owner's credit, and
 *                 the crawl rewrites the pages it restyles
 *
 * Exits non-zero if any check fails, naming the ones that did.
 */

'use strict';

const { execFileSync, spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const repo = path.resolve(__dirname, '..');

function parseArgs(argv) {
	const out = { zip: [], 'chrome-zip': [] };
	for (let i = 0; i < argv.length; i++) {
		const key = argv[i].replace(/^--/, '');
		const next = argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[++i] : true;
		if (key === 'zip' || key === 'chrome-zip') { out[key].push(next); continue; }
		out[key] = next;
	}
	return out;
}

const args = parseArgs(process.argv.slice(2));
if (!args.wp) {
	console.error('usage: node bin/verify-import.cjs --wp <wp-root> [--zip <file>]… [--skip-import]');
	process.exit(2);
}

const work = path.resolve(args.work || path.join(repo, '.verify'));
const widths = String(args.widths || '1280,768,390').split(',').map((w) => w.trim()).filter(Boolean);
const only = args.only === undefined ? null : String(args.only).split(',').map((s) => s.trim());
const chrome = args.chrome || 'C:/Program Files/Google/Chrome/Application/chrome.exe';

const wants = (stage) => !only || only.includes(stage);

/**
 * Written to the work directory and run as its own process, so the page code
 * stays readable rather than becoming a string inside an evaluate() call.
 */
const BEHAVIOUR_PROBE = `'use strict';
const puppeteer = require(${JSON.stringify(path.join(repo, 'node_modules', 'puppeteer-core'))});

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/*
 * Keyboard access to the hover menus: every visible [data-dxai-hover="enter"]
 * element (a dropdown the design opens on mouseenter) is reached with a real
 * Tab, and must open; Tab walks into its panel with the panel staying open;
 * focus leaving the menu closes it; Escape inside it closes it and puts focus
 * back on its trigger. The click sweep above cannot see any of this — a
 * trigger is a link, links are left out of it, and a panel that only a mouse
 * can open has exactly the boxes and paint of one that works. The page is
 * loaded afresh first, so what the sweep toggled plays no part, and the
 * pointer is parked where no menu is, so :hover plays none either.
 *
 * A menu with no panel, or no trigger a keyboard can reach, is counted as
 * skipped: there is nothing of the runtime's to test there. (No backticks in
 * here: this whole probe is a template literal.)
 */
async function keyboardPass(page, url) {
  const out = { menus: 0, passed: 0, skipped: 0, failures: [] };
  await page.goto(url, { waitUntil: 'load', timeout: 60000 });
  await sleep(800);
  const park = await page.evaluate(() => {
    const spots = [[2, 898], [1270, 898], [640, 898], [2, 450]];
    for (const [x, y] of spots) {
      const el = document.elementFromPoint(x, y);
      if (!el || !el.closest('[data-dxai-hover]')) { return [x, y]; }
    }
    return [2, 898];
  });
  await page.mouse.move(park[0], park[1]);
  await sleep(150);
  const total = await page.evaluate(() => {
    const vis = (el) => {
      if (!el) { return false; }
      const r = el.getBoundingClientRect();
      return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden';
    };
    const focusable = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';
    window.__dxaiKb = [...document.querySelectorAll('[data-dxai-hover="enter"]')].filter(vis).map((w) => {
      const panel = w.querySelector("[class*='dxai-on-']");
      const trigger = [...w.querySelectorAll(focusable)].find((el) => vis(el) && !(panel && panel.contains(el))) || null;
      return { w, panel, trigger };
    });
    return window.__dxaiKb.length;
  });
  const state = (i) => page.evaluate((i) => {
    const m = window.__dxaiKb[i];
    const shown = (el) => {
      if (!el) { return false; }
      const s = getComputedStyle(el);
      return s.display !== 'none' && s.visibility !== 'hidden' && parseFloat(s.opacity) > 0.01 && el.getBoundingClientRect().height > 0;
    };
    const a = document.activeElement;
    const focusable = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';
    return {
      open: shown(m.panel),
      onTrigger: !!a && a === m.trigger,
      inWrap: !!a && m.w.contains(a),
      links: m.panel ? [...m.panel.querySelectorAll(focusable)].filter((el) => el.getBoundingClientRect().height > 0).length : 0,
      label: ((m.trigger && m.trigger.textContent) || '').replace(/\\s+/g, ' ').trim().slice(0, 24),
    };
  }, i);
  /*
   * Focus lands on the trigger by a real Tab from just outside the menu: an
   * empty, zero-size focusable placed right before it for that one key
   * press, then removed. (Shift+Tab out of the trigger and Tab back in does
   * not work where another dropdown's trigger precedes this one: Shift+Tab
   * focuses that trigger, which opens its panel, and Tab then walks into
   * that panel instead — measured on a header with three dropdowns in a row.)
   */
  const arrive = async (i) => {
    await page.evaluate((i) => {
      const m = window.__dxaiKb[i];
      if (document.activeElement && document.activeElement.blur) { document.activeElement.blur(); }
      const from = document.createElement('span');
      from.tabIndex = 0;
      from.id = 'dxai-kb-from';
      m.w.parentNode.insertBefore(from, m.w);
      from.focus();
    }, i);
    await sleep(150);
    await page.keyboard.press('Tab');
    await sleep(300);
    await page.evaluate(() => { const from = document.getElementById('dxai-kb-from'); if (from) { from.remove(); } });
    return state(i);
  };
  for (let i = 0; i < total; i++) {
    const has = await page.evaluate((i) => ({ panel: !!window.__dxaiKb[i].panel, trigger: !!window.__dxaiKb[i].trigger }), i);
    if (!has.panel || !has.trigger) { out.skipped++; continue; }
    out.menus++;
    let s = await arrive(i);
    const fail = (why) => { out.failures.push((s.label || ('menu ' + (i + 1))) + ': ' + why); };
    if (!s.onTrigger) { fail('Tab does not reach its trigger'); continue; }
    if (!s.open) { fail('its panel does not open on keyboard focus'); continue; }
    const links = Math.min(s.links, 12);
    let walked = 0;
    let closed = false;
    for (let k = 0; k < links; k++) {
      await page.keyboard.press('Tab');
      await sleep(200);
      s = await state(i);
      if (!s.inWrap) { break; }
      if (!s.open) { closed = true; break; }
      walked++;
    }
    if (closed) { fail('the panel closes while focus is inside it'); continue; }
    if (links > 0 && walked === 0) { fail('Tab does not move into the panel'); continue; }
    for (let k = 0; k < 4 && s.inWrap; k++) {
      await page.keyboard.press('Tab');
      await sleep(300);
      s = await state(i);
    }
    if (s.inWrap) { fail('focus never leaves the menu'); continue; }
    if (s.open) { fail('the panel stays open after focus leaves the menu'); continue; }
    s = await arrive(i);
    if (!s.open) { fail('the panel does not reopen on keyboard focus'); continue; }
    if (links > 0) {
      await page.keyboard.press('Tab');
      await sleep(200);
    }
    await page.keyboard.press('Escape');
    await sleep(300);
    s = await state(i);
    if (s.open) { fail('Escape does not close the panel'); continue; }
    if (!s.onTrigger) { fail('Escape does not put focus back on the trigger'); continue; }
    out.passed++;
  }
  return out;
}

(async () => {
  const [chrome, ...urls] = process.argv.slice(2);
  const launch = () => puppeteer.launch({
    executablePath: chrome, headless: 'shell', args: ['--no-sandbox'],
  });
  let browser = await launch();
  for (const url of urls) {
    /*
     * Inside the loop, and relaunched when the browser is gone.
     *
     * newPage() used to sit outside the try. Chrome died mid-evaluate on the
     * third page of ten — TargetCloseError: Protocol error — and the throw
     * escaped the loop, so seven pages emitted no line at all. The stage
     * records one check per line, so the run's total silently fell from 41 to
     * 34 and still printed "33/34 passed": seven unverified pages reading as
     * an absence rather than a failure. That is the failure mode this whole
     * file exists to prevent.
     */
    let page;
    try {
      if (!browser.connected) { browser = await launch(); }
      page = await browser.newPage();
    } catch (e) {
      try { browser = await launch(); page = await browser.newPage(); } catch (again) {
        console.log(JSON.stringify({
          slug: url, reveals: 0, shown: -1, imgs: 0, broken: 0,
          errors: 1, first: 'browser would not start: ' + String(again).slice(0, 60),
        }));
        continue;
      }
    }
    await page.setViewport({ width: 1280, height: 900 });
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e).slice(0, 60)));
    page.on('console', (m) => { if (m.type() === 'error') { errors.push(m.text().slice(0, 60)); } });
    try {
      await page.goto(url, { waitUntil: 'load', timeout: 60000 });
      await new Promise((r) => setTimeout(r, 1500));
      const stats = await page.evaluate(async () => {
        document.querySelectorAll('img[loading="lazy"]').forEach((i) => { i.loading = 'eager'; });
        const reveals = document.querySelectorAll('.dxai-reveal').length;
        const lit = () => [...document.querySelectorAll('.dxai-reveal')]
          .filter((e) => e.classList.contains('is-in')).length;
        /*
         * Motion_Runtime sweeps on a throttled timer, so step down the page
         * and give it time — but wait for every reveal to fire rather than for
         * the count to stop moving. Accepting any settled count made the check
         * load-dependent: on the page with the most reveals (31) a sweep
         * paused for longer than the settle window while the import was still
         * finishing, the count read as final at 29, and the check failed on
         * content that passes 31/31 on a quiet machine. A check that fails at
         * random is worse than no check.
         */
        const sweep = async () => {
          for (let y = 0; y < document.body.scrollHeight; y += 400) {
            window.scrollTo(0, y);
            await new Promise((r) => setTimeout(r, 90));
          }
          window.scrollTo(0, document.body.scrollHeight);
          await new Promise((r) => setTimeout(r, 400));
        };

        const deadline = Date.now() + 20000;
        do {
          await sweep();
        } while (lit() < reveals && Date.now() < deadline);

        /*
         * Drive the controls instead of only looking at them. Asserting that
         * reveals fired said nothing about the tabs, accordions and carousels,
         * and a research pass measured 66 of them inert — tabs writing
         * aria-expanded against a design that keys .is-active, carousel arrows
         * with no handler, mobile toggles that lost their id/for pair. All of
         * it passed, because a control that does nothing still renders.
         *
         * The assertion is deliberately weak: click it, and something in its
         * container must change. That needs no per-design expectation of WHAT
         * should happen, so it cannot go stale or flake on timing, and it is
         * enough to separate a live control from a dead one.
         */
        /*
         * What counts as a control this page offers, and what a click on it can
         * possibly prove.
         *
         * Four kinds of element used to be swept in and reported inert for
         * reasons that had nothing to do with the conversion:
         *
         * - anything with no box: a mobile menu's items at 1280 cannot change
         *   anything when clicked. The Claude Design page read 13 of 27 driven
         *   and 14 of those 27 were its mobile menu; the radix probe had the
         *   same blind spot and threw outright on four pages;
         * - the state TARGETS. dxai-on-*, dxai-off-* and dxai-cls-* are what
         *   the runtime writes to, not what a person clicks — a label, a select
         *   and an H3 inside a form step were three of the five "inert"
         *   controls on the Arcus page;
         * - links the probe itself holds. Navigation is cancelled below so the
         *   page survives the sweep, which means an anchor that would leave
         *   cannot change anything by construction;
         * - a control already in its on state. Clicking the active tab of a
         *   strip is a no-op in every tab implementation there is, so four
         *   rv-cs-tab buttons were reported dead for behaving correctly.
         *
         * An element that toggles (aria-expanded, a disclosure) stays in even
         * when it is open: clicking that one closes it, which is a change.
         *
         * (No backticks in here: this whole probe is a template literal.)
         */
        const controls = [...document.querySelectorAll(
          '[aria-expanded], [role="tab"], [role="button"], [class*="dxai-toggle-"]'
        )].filter((el) => {
          const box = el.getBoundingClientRect();
          if (box.width < 1 || box.height < 1) { return false; }
          const cls = String(el.className || '');
          if (/dxai-(?:on|off|cls)-/.test(cls)) { return false; }
          /*
           * No links at all. A link's job is to go somewhere: navigation is
           * cancelled below so the sweep survives, and an in-page jump moves
           * the viewport without changing the container this probe diffs. So a
           * link cannot prove anything here either way — 35 of DevriX
           * Elevate's 42 "controls" were anchors styled as cards. Where the
           * links go is checked by the asset and link passes instead.
           */
          if (el.tagName === 'A') { return false; }
          const active = el.getAttribute('aria-selected') === 'true'
            || el.getAttribute('data-state') === 'active'
            || /\\bis-active\\b/.test(cls);
          return !active;
        }).slice(0, 60);

        /*
         * Keep the page put — a live anchor would navigate and end the probe —
         * but cancel ONLY navigation. Calling preventDefault on every click
         * suppresses default behaviour a control may depend on, and a library
         * that checks defaultPrevented would then do nothing, which this probe
         * would read as an inert control. Narrowing it to anchors that would
         * actually leave the page keeps the false-positive path closed.
         */
        const hold = (e) => {
          const a = e.target && e.target.closest && e.target.closest('a[href]');
          if (!a) { return; }
          const href = a.getAttribute('href') || '';
          if (href === '' || href.startsWith('#') || href.toLowerCase().startsWith('javascript:')) { return; }
          e.preventDefault();
        };
        document.addEventListener('click', hold, true);

        const shot = (el) => {
          const scope = el.closest('section, header, footer, nav, article') || el.parentElement || el;
          const parts = [scope.innerHTML.length, scope.getAttribute('class') || ''];
          for (const d of [scope, ...scope.querySelectorAll('*')].slice(0, 120)) {
            const s = getComputedStyle(d);
            parts.push(s.display, s.opacity, s.maxHeight, s.gridTemplateRows, s.transform, s.visibility);
          }
          return parts.join('|');
        };

        const inert = [];
        let responded = 0;
        for (const el of controls) {
          const before = shot(el);
          el.click();
          await new Promise((r) => setTimeout(r, 260));
          if (shot(el) === before) {
            inert.push((el.tagName + '.' + String(el.className).split(/\\s+/).slice(0, 2).join('.')).slice(0, 60));
          } else {
            responded++;
          }
        }
        document.removeEventListener('click', hold, true);

        const imgs = [...document.images];
        return {
          reveals, shown: lit(), imgs: imgs.length,
          controls: controls.length, responded, inert: inert.slice(0, 5),
          broken: imgs.filter((i) => i.complete && i.naturalWidth === 0).length,
          // Named, so a genuine failure is diagnosable instead of a number.
          pending: [...document.querySelectorAll('.dxai-reveal')]
            .filter((e) => !e.classList.contains('is-in'))
            .slice(0, 4)
            .map((e) => (e.tagName + '.' + String(e.className).split(/\\s+/).slice(0, 3).join('.')).slice(0, 70)),
        };
      });
      let keyboard = { menus: 0, passed: 0, skipped: 0, failures: [] };
      try {
        keyboard = await keyboardPass(page, url);
      } catch (e) {
        keyboard.failures.push('the keyboard pass threw: ' + String(e).slice(0, 80));
      }
      console.log(JSON.stringify({
        slug: url.replace(/\\/$/, '').split('/').pop().replace(/^dxai-/, ''),
        ...stats, keyboard, errors: errors.length, first: errors[0] || '',
      }));
    } catch (e) {
      console.log(JSON.stringify({
        slug: url, reveals: 0, shown: -1, imgs: 0, broken: 0,
        errors: 1, first: String(e).slice(0, 60),
      }));
    }
    try { await page.close(); } catch (e) { /* the browser may already be gone */ }
  }
  try { await browser.close(); } catch (e) { /* already gone */ }
})();
`;

/*
 * Keyboard access to hover menus on the shapes the corpus may not have at
 * the time of a run, against the runtime the plugin ships NOW
 * (Motion_Runtime::javascript(), written to the work directory by the pack):
 *
 * - the state machine: a JSX-style trigger, a <button data-dxai-hover=enter>
 *   whose wrapper carries data-dxai-hover=leave; and a Header_Renderer-style
 *   dropdown holding a flyout (nested hover-self wrappers), where Escape
 *   closes the innermost menu first and hands focus to its trigger;
 * - the pre-machine path (no projection classes on the page): a hover-marked
 *   toggle, and a legacy dxai-toggle-open-- toggle with no hover marker,
 *   whose old focus behaviour must stay exactly as it was.
 *
 * Mouse hover is checked too: it must behave as before. Two static pages in
 * a temporary directory, opened from file:// — no WordPress page is needed or
 * written. (No backticks in here: this whole probe is a template literal.)
 */
const KB_SYNTH_PROBE = `'use strict';
const fs = require('fs');
const os = require('os');
const path = require('path');
const puppeteer = require(${JSON.stringify(path.join(repo, 'node_modules', 'puppeteer-core'))});
const [chrome, runtime] = process.argv.slice(2);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const results = [];
const check = (name, ok, detail) => { results.push({ name, ok: !!ok, detail: ok ? undefined : detail }); };
const url = (file) => 'file:///' + file.split(path.sep).join('/');
const css = '<style>.hidden{display:none}.dxai-ui [class*="dxai-on-"].hidden{display:none!important}a,button{display:inline-block;margin:4px;padding:4px}div{padding:4px}</style>';
const page = (body) => '<!doctype html><html><head><meta charset="utf-8">' + css + '</head><body><a href="#top">Top</a><div class="dxai-ui">' + body + '</div><a href="#end">End</a><script src="' + url(runtime) + '"></script></body></html>';
const M = page(
  '<nav>'
  + '<div class="relative dxai-toggle-open" data-dxai-hover="leave" data-dxai-set="null" id="jsxwrap">'
  + '<button class="dxai-toggle-open--svc" data-dxai-hover="enter" aria-expanded="false" id="svc">Services</button>'
  + '<div class="dxai-on-open--svc hidden" id="svcpanel"><a href="#1">One</a><a href="#2">Two</a></div>'
  + '</div>'
  + '<a href="#after" id="after">After</a>'
  + '<div class="dxai-toggle-openMenu--company" aria-expanded="false" data-dxai-hover="enter" data-dxai-hover-self="1" id="co">'
  + '<a href="#company" id="cotrig">Company</a>'
  + '<div class="dxai-on-openMenu--company hidden" id="copanel">'
  + '<div class="dxai-toggle-dxaiFly2--team dxai-hdr-flyout" aria-expanded="false" data-dxai-hover="enter" data-dxai-hover-self="1" id="fly">'
  + '<a href="#team" id="flytrig">Team</a>'
  + '<div class="dxai-on-dxaiFly2--team hidden" id="flypanel"><a href="#leadership" id="lead">Leadership</a></div>'
  + '</div>'
  + '<a href="#jobs" id="jobs">Jobs</a>'
  + '</div></div>'
  + '<a href="#last" id="last">Last</a>'
  + '</nav>'
);
const P = page(
  '<ul>'
  + '<li class="relative"><button class="dxai-toggle-open--a" data-dxai-hover="enter" aria-expanded="false" id="a">A</button><a href="#in" id="in">In</a></li>'
  + '<li><a href="#next" id="next">Next</a></li>'
  + '<li><button class="dxai-toggle-open--b" aria-expanded="false" id="b">B</button></li>'
  + '<li><a href="#tail" id="tail">Tail</a></li>'
  + '</ul>'
);
(async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'dxai-kbsynth-'));
  fs.writeFileSync(path.join(dir, 'm.html'), M);
  fs.writeFileSync(path.join(dir, 'p.html'), P);
  const browser = await puppeteer.launch({ executablePath: chrome, headless: 'shell', args: ['--no-sandbox', '--allow-file-access-from-files'] });
  try {
    const tab = await browser.newPage();
    const errors = [];
    tab.on('pageerror', (e) => errors.push(String(e).slice(0, 80)));
    await tab.setViewport({ width: 1000, height: 700 });
    const key = async (k, shift) => {
      if (shift) { await tab.keyboard.down('Shift'); }
      await tab.keyboard.press(k);
      if (shift) { await tab.keyboard.up('Shift'); }
      await sleep(40);
    };
    const st = () => tab.evaluate(() => {
      const vis = (id) => { const e = document.getElementById(id); return !!e && getComputedStyle(e).display !== 'none'; };
      const a = document.activeElement;
      const attr = (id) => { const e = document.getElementById(id); return e ? e.getAttribute('aria-expanded') : null; };
      return { active: a ? (a.id || a.tagName) : '', svc: vis('svcpanel'), co: vis('copanel'), fly: vis('flypanel'), a: attr('a'), b: attr('b') };
    });
    const tabTo = async (id) => { for (let i = 0; i < 20; i++) { await key('Tab'); const s = await st(); if (s.active === id) { return s; } } return st(); };

    await tab.goto(url(path.join(dir, 'm.html')));
    await sleep(200);
    await tab.mouse.move(900, 650);
    check('machine: the runtime ran', await tab.evaluate(() => !!document.querySelector('.dxai-ui.dxai-motion')));
    let s = await tabTo('svc');
    check('jsx: Tab to the trigger button opens its panel', s.active === 'svc' && s.svc, s);
    await key('Tab'); await key('Tab'); s = await st();
    check('jsx: Tab into the panel keeps it open', s.svc && s.active === 'A', s);
    await key('Tab'); s = await st();
    check('jsx: Tab out of the menu closes it', s.active === 'after' && !s.svc, s);
    await key('Tab', true); s = await st();
    check('jsx: Shift+Tab back to the trigger reopens it', s.active === 'svc' && s.svc, s);
    await key('Escape'); s = await st();
    check('jsx: Escape closes it, focus stays on the trigger', s.active === 'svc' && !s.svc, s);
    const at = (sel) => tab.evaluate((sel) => { const b = document.querySelector(sel).getBoundingClientRect(); return { x: b.left + 5, y: b.top + 5 }; }, sel);
    let r = await at('#svc');
    await tab.mouse.move(r.x, r.y, { steps: 3 }); await sleep(80); s = await st();
    const hov = s.svc;
    r = await at('#svcpanel a');
    await tab.mouse.move(r.x, r.y, { steps: 3 }); await sleep(80); s = await st();
    const inside = s.svc;
    await tab.mouse.move(900, 650, { steps: 3 }); await sleep(80); s = await st();
    check('jsx: mouse hover opens, inside keeps, leaving closes', hov && inside && !s.svc, { hov, inside, after: s.svc });
    s = await tabTo('cotrig');
    check('flyout: Tab to the dropdown trigger opens it', s.active === 'cotrig' && s.co && !s.fly, s);
    await key('Tab'); s = await st();
    check('flyout: Tab to the flyout trigger opens the flyout', s.active === 'flytrig' && s.co && s.fly, s);
    await key('Tab'); s = await st();
    check('flyout: Tab into the flyout, both open', s.active === 'lead' && s.co && s.fly, s);
    await key('Escape'); s = await st();
    check('flyout: Escape closes only the flyout, focus on its trigger', s.active === 'flytrig' && s.co && !s.fly, s);
    await key('Escape'); s = await st();
    check('flyout: a second Escape closes the dropdown, focus on its trigger', s.active === 'cotrig' && !s.co && !s.fly, s);
    await key('Tab'); s = await st();
    check('flyout: after Escape, Tab skips the closed panel', s.active === 'last' && !s.co, s);
    await key('Tab', true); await key('Tab'); await key('Tab'); await key('Tab'); s = await st();
    check('flyout: Tab from the flyout to the next item closes the flyout only', s.active === 'jobs' && s.co && !s.fly, s);
    await key('Tab'); s = await st();
    check('flyout: Tab out of the dropdown closes it', s.active === 'last' && !s.co && !s.fly, s);

    await tab.goto(url(path.join(dir, 'p.html')));
    await sleep(200);
    await tab.mouse.move(900, 650);
    check('pre-machine: the runtime ran, no projections', await tab.evaluate(() => !!document.querySelector('.dxai-ui.dxai-motion') && !document.querySelector("[class*='dxai-on-'],[class*='dxai-cls-'],[class*='dxai-off-']")));
    s = await tabTo('a');
    check('pre-machine: Tab to the toggle opens it', s.active === 'a' && s.a === 'true', s);
    await key('Tab'); s = await st();
    check('pre-machine: Tab within the wrap keeps it', s.active === 'in' && s.a === 'true', s);
    await key('Tab'); s = await st();
    check('pre-machine: Tab out of the wrap closes it', s.active === 'next' && s.a === 'false', s);
    await key('Tab', true); await key('Tab', true); s = await st();
    check('pre-machine: Shift+Tab back reopens it', s.active === 'a' && s.a === 'true', s);
    await key('Escape'); s = await st();
    check('pre-machine: Escape closes it, focus stays', s.active === 'a' && s.a === 'false', s);
    s = await tabTo('b');
    check('legacy: an unmarked toggle still opens on focus', s.active === 'b' && s.b === 'true', s);
    await key('Tab'); s = await st();
    check('legacy: ... and is left open as before when focus leaves', s.active === 'tail' && s.b === 'true', s);
    check('no page errors', errors.length === 0, errors);
  } catch (e) {
    check('the probe ran to the end', false, String(e).slice(0, 120));
  } finally {
    try { await browser.close(); } catch (e) { /* already gone */ }
    try { fs.rmSync(dir, { recursive: true, force: true }); } catch (e) { /* ignore */ }
  }
  const failed = results.filter((x) => !x.ok);
  console.log(JSON.stringify({ passed: results.length - failed.length, total: results.length, failures: failed.map((f) => f.name + (f.detail !== undefined ? ' ' + JSON.stringify(f.detail).slice(0, 120) : '')) }));
})();
`;

/*
 * The block widgets of the imported footer, for the widgets stage.
 *
 * A footer built into Appearance > Widgets is no template part any more, so
 * the blocks stage (which round-trips pages and parts) no longer reaches its
 * blocks. They are what a person edits in the Widgets screen: each column is
 * a widget area (Footer_Template's slots), each widget a `widget_block`
 * instance holding block markup. Written to the work directory and run with
 * `wp eval-file`, read-only. String.raw keeps the namespace separators and
 * the regex intact; there is no interpolation in it.
 */
const FOOTER_WIDGETS_PHP = String.raw`<?php
// Written by bin/verify-import.cjs. Read-only: the block widgets placed in
// the areas of the stored footer (Footer_Template::get()['slots']).
$cls      = 'DXAI_UI\Chrome\Footer_Template';
$foot     = class_exists( $cls ) ? call_user_func( array( $cls, 'get' ) ) : array();
$areas    = is_array( $foot ) && isset( $foot['slots'] ) && is_array( $foot['slots'] ) ? array_map( 'strval', array_keys( $foot['slots'] ) ) : array();
$sidebars = wp_get_sidebars_widgets();
$settings = get_option( 'widget_block', array() );
$out      = array();
foreach ( $areas as $area ) {
	foreach ( (array) ( $sidebars[ $area ] ?? array() ) as $id ) {
		if ( preg_match( '/^block-(\d+)$/', (string) $id, $m ) !== 1 ) {
			continue;
		}
		$content = is_array( $settings ) ? ( $settings[ (int) $m[1] ]['content'] ?? null ) : null;
		if ( is_string( $content ) ) {
			$out[] = array( 'id' => (string) $id, 'area' => $area, 'content' => $content );
		}
	}
}
echo wp_json_encode( array( 'areas' => $areas, 'widgets' => $out ) ), "\n";
`;

/*
 * Parses each footer widget's content in the real block editor — the same
 * wp.blocks.parse() the Widgets screen runs on every widget as it loads, and
 * the same validation the post editor's round trip reads — inside a post
 * editor session that sends no write: every non-GET request is aborted, and
 * the Widgets screen itself is never opened (its REST calls can rewrite the
 * widget placement through retrieve_widgets()). core/widget-group and
 * core/legacy-widget belong to that screen: where the post editor has them
 * (WordPress 7.1 registers core/widget-group there) they are validated like
 * any block, and where it does not they are counted as skipped rather than
 * as unregistered.
 *
 * (No backticks in here: this whole probe is a template literal.)
 */
const WIDGETS_PROBE = `'use strict';
const fs = require('fs');
const puppeteer = require(${JSON.stringify(path.join(repo, 'node_modules', 'puppeteer-core'))});

(async () => {
  const [chrome, editUrl, input, cookieName, cookieValue, authName, authValue] = process.argv.slice(2);
  const widgets = JSON.parse(fs.readFileSync(input, 'utf8')).widgets || [];
  const browser = await puppeteer.launch({ executablePath: chrome, headless: 'shell', args: ['--no-sandbox'] });
  try {
    const page = await browser.newPage();
    await page.setRequestInterception(true);
    page.on('request', (req) => {
      const m = req.method();
      if (m !== 'GET' && m !== 'HEAD' && m !== 'OPTIONS') { req.abort(); return; }
      req.continue();
    });
    const origin = new URL(editUrl).origin;
    await page.setCookie(
      { name: cookieName, value: cookieValue, url: origin, path: '/', httpOnly: true },
      { name: authName, value: authValue, url: origin, path: '/wp-admin', httpOnly: true }
    );
    await page.goto(editUrl, { waitUntil: 'domcontentloaded', timeout: 150000 });
    await page.waitForFunction(() => {
      const wp = window.wp;
      return !!(wp && wp.blocks && wp.blocks.parse && wp.blocks.getBlockType && wp.blocks.getBlockType('dxai-ui/box')
        && wp.data && wp.data.select('core/block-editor') && wp.data.select('core/block-editor').getBlocks().length);
    }, { timeout: 150000, polling: 500 });
    const res = await page.evaluate((list) => {
      const WIDGETS_SCREEN_ONLY = ['core/widget-group', 'core/legacy-widget'];
      return list.map((w) => {
        const out = { id: w.id, area: w.area, blocks: 0, invalid: [], unregistered: [], skipped: 0 };
        const walk = (blocks) => blocks.forEach((b) => {
          out.blocks++;
          if (b.name === 'core/missing') {
            const original = (b.attributes && b.attributes.originalName) || '?';
            if (WIDGETS_SCREEN_ONLY.includes(original)) { out.skipped++; } else { out.unregistered.push(original); }
          } else if (b.isValid === false) {
            out.invalid.push(b.name);
          }
          walk(b.innerBlocks || []);
        });
        walk(window.wp.blocks.parse(w.content));
        return out;
      });
    }, widgets);
    console.log(JSON.stringify({ ok: true, widgets: res }));
  } catch (e) {
    console.log(JSON.stringify({ ok: false, error: String(e).slice(0, 200) }));
  } finally {
    try { await browser.close(); } catch (e) { /* already gone */ }
  }
})();
`;

/*
 * One archive, imported in place: the same three calls wp-admin's import
 * makes (Zip_Router::import(), Source_Compiler::compile(),
 * Structure_Repository::save()), with nothing purged first. Run with
 * `wp eval-file <this> <zip> <title-prefix> <keep|install> <run-archives.json>`.
 *
 * - As an administrator who has unfiltered_html: save() refuses anyone else,
 *   because a save without it runs every page through KSES.
 * - The header and footer choice is the import screen's (`chrome`, see
 *   Chrome_Choice): keep, or install as the site's. The answer says which ran
 *   and why, so a save that ignores the choice is caught.
 * - Never a crawl: create_missing_pages is off and the crawl's model switch is
 *   filtered off too, so no run of this pack spends model credit or writes
 *   the pages of a live menu.
 * - Never a page the run did not import: a design that takes the widget
 *   areas gives the pages of the design whose footer they held their footer
 *   back (Structure_Repository::install_site_footer()); only pages of this
 *   run's archives may be among them (dxai_ui_footer_holders).
 * - The page ids are recorded on `_dxai_ui_source_zip`, as the old purge
 *   script did, so every stage can pair a page with the archive it measures
 *   it against.
 */
const IMPORT_PHP = String.raw`<?php
// Written by bin/verify-import.cjs: import ONE archive in place (no purge).
$zip    = (string) ( $args[0] ?? '' );
$prefix = (string) ( $args[1] ?? '' );
$mode   = (string) ( $args[2] ?? 'keep' );
$list   = (string) ( $args[3] ?? '' );
$run    = '' !== $list && is_readable( $list ) ? array_map( 'strval', (array) json_decode( (string) file_get_contents( $list ), true ) ) : array();
$name   = wp_basename( $zip );
$out    = array( 'name' => $name, 'ok' => false, 'requested' => $mode );
$emit   = static function ( array $out ): void {
	echo wp_json_encode( $out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";
};
add_filter( 'dxai_ui_crawl_use_ai', '__return_false' );
add_filter(
	'dxai_ui_footer_holders',
	static function ( $holders ) use ( $run ) {
		return array_values(
			array_filter(
				array_map( 'intval', (array) $holders ),
				static fn( int $id ): bool => in_array( (string) get_post_meta( $id, '_dxai_ui_source_zip', true ), $run, true )
			)
		);
	},
	99
);
if ( ! current_user_can( 'unfiltered_html' ) ) {
	$candidates = array();
	$named      = get_user_by( 'login', 'admin' );
	if ( $named instanceof WP_User ) {
		$candidates[] = $named;
	}
	if ( is_multisite() ) {
		foreach ( get_super_admins() as $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user instanceof WP_User ) {
				$candidates[] = $user;
			}
		}
	}
	foreach ( get_users( array( 'role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 20 ) ) as $user ) {
		$candidates[] = $user;
	}
	foreach ( $candidates as $user ) {
		wp_set_current_user( $user->ID );
		if ( current_user_can( 'unfiltered_html' ) ) {
			break;
		}
	}
}
if ( ! current_user_can( 'unfiltered_html' ) ) {
	$out['status'] = 'no_admin';
	$emit( $out );
	return;
}
if ( ! is_readable( $zip ) ) {
	$out['status'] = 'missing_zip';
	$emit( $out );
	return;
}
$started = microtime( true );
$doc     = DXAI_UI\Connectors\Zip_Router::import( array( 'tmp_name' => $zip, 'name' => $name ) );
if ( is_wp_error( $doc ) ) {
	$out['status']  = 'import_error';
	$out['message'] = $doc->get_error_message();
	$emit( $out );
	return;
}
$compiler = new DXAI_UI\Compiler\Source_Compiler();
if ( ! $compiler->can_compile( $doc ) ) {
	$out['status'] = 'cannot_compile';
	$emit( $out );
	return;
}
$result                         = $compiler->compile( $doc );
$result['create_missing_pages'] = false;
// The header and footer choice (Chrome_Choice): keep, or install as the site's.
$result['chrome'] = $mode;
if ( '' === (string) ( $result['source_name'] ?? '' ) ) {
	$result['source_name'] = $name;
}
if ( '' !== $prefix ) {
	$result['block_title'] = $prefix . (string) ( $result['block_title'] ?? '' );
	if ( '' !== trim( (string) ( $result['seo_title'] ?? '' ) ) ) {
		$result['seo_title'] = $prefix . (string) $result['seo_title'];
	}
	if ( is_array( $result['pages'] ?? null ) ) {
		foreach ( $result['pages'] as $i => $extra ) {
			if ( is_array( $extra ) && '' !== (string) ( $extra['block_title'] ?? '' ) ) {
				$result['pages'][ $i ]['block_title'] = $prefix . (string) $extra['block_title'];
			}
		}
	}
}
$saved = ( new DXAI_UI\Structures\Structure_Repository() )->save( $result );
if ( is_wp_error( $saved ) ) {
	$out['status']  = 'save_error';
	$out['message'] = $saved->get_error_message();
	$emit( $out );
	return;
}
$page_id  = (int) ( $saved['page_id'] ?? 0 );
$page_ids = array_values( array_filter( array_merge( array( $page_id ), array_map( 'intval', (array) ( $saved['extra_page_ids'] ?? array() ) ) ) ) );
foreach ( $page_ids as $one ) {
	update_post_meta( $one, '_dxai_ui_source_zip', $name );
}
$chrome  = array();
$written = array();
foreach ( array( 'header', 'footer' ) as $area ) {
	$answer          = (array) ( $saved['chrome'][ $area ] ?? array() );
	$chrome[ $area ] = array(
		'installed' => '' !== (string) ( $answer['block_markup'] ?? '' ),
		'error'     => (string) ( $answer['error'] ?? '' ),
		'reason'    => (string) ( $answer['reason'] ?? '' ),
		'message'   => wp_strip_all_tags( (string) ( $answer['message'] ?? '' ) ),
	);
	if ( 'header' === $area ) {
		$chrome[ $area ]['menus'] = array_map( 'intval', (array) ( $answer['menus'] ?? array() ) );
		$chrome[ $area ]['kept']  = array_map( 'strval', array_keys( (array) ( $answer['locations_kept'] ?? array() ) ) );
	} else {
		foreach ( array( 'created', 'reused', 'released', 'inactive', 'edited' ) as $key ) {
			$chrome[ $area ][ $key ] = is_array( $answer[ $key ] ?? null ) ? count( $answer[ $key ] ) : (int) ( $answer[ $key ] ?? 0 );
		}
		$back = is_array( $answer['handed_back'] ?? null ) ? $answer['handed_back'] : array();
		if ( $back !== array() ) {
			$chrome[ $area ]['handed_back'] = array(
				'part'  => (int) ( $back['part_id'] ?? 0 ),
				'pages' => array_map( 'intval', (array) ( $back['pages'] ?? array() ) ),
				'title' => (string) ( $back['title'] ?? '' ),
			);
			$written[] = (int) ( $back['part_id'] ?? 0 );
		}
	}
}
// Every post this save wrote, for the run's cleanup (trashed at the end of a test run).
foreach ( (array) ( $saved['patterns'] ?? array() ) as $pattern ) {
	$written[] = (int) ( is_array( $pattern ) ? ( $pattern['id'] ?? 0 ) : $pattern );
}
$parts = array(
	'header' => (int) ( $saved['header_id'] ?? 0 ),
	'footer' => (int) ( $saved['footer_id'] ?? 0 ),
);
// The parts an install replaced with the blocks (retired to the trash).
$replaced = array_map( 'intval', (array) ( $saved['chrome_parts'] ?? array() ) );
$refs     = array();
foreach ( array_merge( array_values( $parts ), array_values( $replaced ) ) as $part ) {
	if ( $part > 0 ) {
		$written[] = $part;
	}
	if ( $part > 0 && 'publish' === get_post_status( $part ) ) {
		$terms  = get_the_terms( $part, 'wp_theme' );
		$theme  = ( $terms && ! is_wp_error( $terms ) ) ? (string) $terms[0]->name : (string) get_stylesheet();
		$refs[] = $theme . '//' . (string) get_post_field( 'post_name', $part );
	}
}
$written = array_values( array_unique( array_filter( array_merge( $written, $page_ids, array_map( 'intval', (array) ( $saved['navigation_ids'] ?? array() ) ), array( (int) ( $saved['conversion_id'] ?? 0 ) ) ) ) ) );
$ran     = is_array( $saved['chrome_mode'] ?? null ) ? $saved['chrome_mode'] : null;
$audit             = is_array( $saved['audit'] ?? null ) ? $saved['audit'] : array();
$out['ok']         = $page_id > 0;
$out['status']     = $page_id > 0 ? 'ok' : 'no_page';
$out['page_id']    = $page_id;
$out['page_ids']   = $page_ids;
$out['page_key']   = (string) get_post_meta( $page_id, '_dxai_ui_page_key', true );
$out['findings']   = array_map( static fn( $f ): string => (string) ( $f['check'] ?? '?' ), is_array( $audit['findings'] ?? null ) ? $audit['findings'] : array() );
// Which choice save() made (Chrome_Choice::resolve()); null: this save() takes no choice.
$out['mode']       = null === $ran ? null : array(
	'mode'   => (string) ( $ran['mode'] ?? '' ),
	'reason' => (string) ( $ran['reason'] ?? '' ),
);
$out['chrome']     = $chrome;
$out['parts']      = $parts;
$out['replaced']   = $replaced;
$out['part_refs']  = array_values( array_unique( $refs ) );
$out['written']    = $written;
$out['conversion'] = (int) ( $saved['conversion_id'] ?? 0 );
$out['seconds']    = round( microtime( true ) - $started, 1 );
$emit( $out );
`;

/*
 * The header and footer as the chrome stage checks them, for the pages of
 * one design. Read-only: posts, options and renders. Run with
 * `wp eval-file <this> <input.json>`, the input naming each page and the
 * template parts its header and footer were built from:
 * {"home": id, "pages": [{"id", "header_part", "footer_part"}]}.
 *
 * Per page: its top-level blocks; the md5 of everything between its header
 * and its footer (what the chrome choice must not change); style=""
 * attributes in its saved markup; and its first and last block rendered
 * through the_content in the page's own context, next to the template part
 * they were built from rendered the same way, compared as DOM (every
 * element's tag, sorted attributes and text, in order; a template part
 * block's own wrapper element is not the design's and is left out). For the
 * home, the stored header spec with each of its menus (marked as that
 * location's header menu, owned by the design, items, items pointing at a
 * page that is gone, and whether the location holds it), and the stored
 * footer with its areas. Pages a crawl made for the home are listed with
 * their first and last blocks.
 */
const CHROME_CHECK_PHP = String.raw`<?php
// Written by bin/verify-import.cjs. Read-only.
$in     = json_decode( (string) file_get_contents( (string) ( $args[0] ?? '' ) ), true );
$in     = is_array( $in ) ? $in : array();
$home   = (int) ( $in['home'] ?? 0 );
$styles = static fn( string $html ): int => (int) preg_match_all( '/<[a-zA-Z][^>]*\sstyle\s*=/', $html );
$sig    = static function ( string $html ): array {
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?><div id="dxai-check-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	libxml_clear_errors();
	$out  = array();
	$walk = static function ( DOMNode $node ) use ( &$walk, &$out ): void {
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof DOMElement ) {
				$class = implode( ' ', array_filter( preg_split( '/\s+/', trim( (string) $child->getAttribute( 'class' ) ) ) ) );
				if ( preg_match( '/(?:^| )wp-block-template-part(?: |$)/', $class ) === 1 ) {
					$walk( $child );
					continue;
				}
				$attrs = array();
				foreach ( $child->attributes as $attr ) {
					$attrs[] = $attr->name . '=' . ( 'class' === $attr->name ? $class : $attr->value );
				}
				sort( $attrs );
				$out[] = '<' . $child->tagName . ' ' . implode( ' ', $attrs ) . '>';
				$walk( $child );
				$out[] = '</' . $child->tagName . '>';
			} elseif ( $child instanceof DOMText ) {
				$text = trim( (string) preg_replace( '/\s+/u', ' ', $child->wholeText ) );
				if ( '' !== $text ) {
					$out[] = '#' . $text;
				}
			}
		}
	};
	$root = $doc->getElementById( 'dxai-check-root' );
	if ( $root ) {
		$walk( $root );
	}
	return $out;
};
$describe = static function ( array $b ): array {
	$row = array( 'name' => (string) $b['blockName'] );
	if ( 'core/template-part' === $b['blockName'] ) {
		$row['slug'] = (string) ( $b['attrs']['slug'] ?? '' );
		$row['area'] = (string) ( $b['attrs']['area'] ?? '' );
		$parts       = '' === $row['slug'] ? array() : get_posts(
			array(
				'post_type'      => 'wp_template_part',
				'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future', 'trash' ),
				'name'           => $row['slug'],
				'posts_per_page' => 5,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);
		$live          = array_values( array_filter( $parts, static fn( $p ) => 'publish' === $p->post_status ) );
		$part          = $live[0] ?? ( $parts[0] ?? null );
		$row['status'] = $part ? (string) $part->post_status : 'missing';
		$row['part']   = $part ? (int) $part->ID : 0;
	} elseif ( 'dxai-ui/link' === $b['blockName'] ) {
		$row['url'] = (string) ( $b['attrs']['url'] ?? '' );
	}
	return $row;
};
$is_header = static fn( ?array $b ): bool => null !== $b && ( 'dxai-ui/site-header' === $b['blockName'] || ( 'core/template-part' === $b['blockName'] && 'header' === ( $b['attrs']['area'] ?? '' ) ) );
$is_footer = static fn( ?array $b ): bool => null !== $b && ( 'dxai-ui/site-footer' === $b['blockName'] || ( 'core/template-part' === $b['blockName'] && 'footer' === ( $b['attrs']['area'] ?? '' ) ) );
$render    = static function ( int $id, string $markup ): string {
	$post                    = get_post( $id );
	$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $id ) );
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
	$GLOBALS['post']         = $post;
	setup_postdata( $post );
	$html = (string) apply_filters( 'the_content', $markup );
	wp_reset_postdata();
	return $html;
};
$page_row = static function ( array $row ) use ( $sig, $styles, $describe, $is_header, $is_footer, $render ): array {
	$id   = (int) ( $row['id'] ?? 0 );
	$post = $id > 0 ? get_post( $id ) : null;
	if ( ! $post ) {
		return array( 'id' => $id, 'exists' => false );
	}
	$content = (string) $post->post_content;
	$top     = array_values( array_filter( parse_blocks( $content ), static fn( $b ) => null !== $b['blockName'] ) );
	$mid     = $top;
	if ( $mid && $is_header( $mid[0] ) ) {
		array_shift( $mid );
	}
	if ( $mid && $is_footer( $mid[ count( $mid ) - 1 ] ) ) {
		array_pop( $mid );
	}
	$out = array(
		'id'            => $id,
		'exists'        => true,
		'status'        => (string) $post->post_status,
		'title'         => (string) $post->post_title,
		'scope'         => (int) get_post_meta( $id, DXAI_UI\Structures\Page_Scope::META, true ),
		'crawled'       => '1' === (string) get_post_meta( $id, '_dxai_ui_from_live_menu', true ),
		'top'           => array_map( $describe, $top ),
		'mid_count'     => count( $mid ),
		'mid_md5'       => md5( serialize_blocks( $mid ) ),
		'content_md5'   => md5( $content ),
		'content_style' => $styles( $content ),
	);
	foreach ( array( 'header', 'footer' ) as $area ) {
		$block = 'header' === $area ? ( $top[0] ?? null ) : ( $top ? $top[ count( $top ) - 1 ] : null );
		if ( null === $block || ! ( 'header' === $area ? $is_header( $block ) : $is_footer( $block ) ) ) {
			$out[ $area ] = array( 'block' => '' );
			continue;
		}
		$html  = $render( $id, serialize_block( $block ) );
		$a     = $sig( $html );
		$entry = array(
			'block'   => (string) $block['blockName'],
			'bytes'   => strlen( $html ),
			'style'   => $styles( $html ),
			// The rendered DOM, to compare with a later render of the same page.
			'sig_md5' => md5( implode( "\n", $a ) ),
		);
		$part  = (int) ( $row[ $area . '_part' ] ?? 0 );
		if ( $part > 0 && get_post( $part ) ) {
			$source = (string) get_post_field( 'post_content', $part );
			$ref    = $render( $id, $source );
			$z      = $sig( $ref );
			$entry += array(
				'part'        => $part,
				'part_status' => (string) get_post_status( $part ),
				'part_md5'    => md5( $source ),
				'part_style'  => $styles( $source ),
				'part_bytes'  => strlen( $ref ),
				'nodes'       => count( $a ),
				'part_nodes'  => count( $z ),
				'same'        => $a === $z && count( $a ) > 0,
			);
			if ( $a !== $z ) {
				foreach ( $a as $i => $x ) {
					if ( ( $z[ $i ] ?? null ) !== $x ) {
						$entry['first_diff'] = array( 'at' => $i, 'block' => mb_substr( $x, 0, 160 ), 'part' => mb_substr( (string) ( $z[ $i ] ?? '(none)' ), 0, 160 ) );
						break;
					}
				}
				if ( ! isset( $entry['first_diff'] ) ) {
					$entry['first_diff'] = array( 'at' => count( $a ), 'block' => '(end)', 'part' => mb_substr( (string) ( $z[ count( $a ) ] ?? '(none)' ), 0, 160 ) );
				}
			}
		}
		$out[ $area ] = $entry;
	}
	return $out;
};
$pages = array();
foreach ( (array) ( $in['pages'] ?? array() ) as $row ) {
	$pages[] = $page_row( (array) $row );
}
// Pages a crawl made for this home: the same blocks as it.
$crawled = array();
if ( $home > 0 ) {
	$ids = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'post__not_in'   => array_map( static fn( $r ) => (int) ( $r['id'] ?? 0 ), (array) ( $in['pages'] ?? array() ) ),
			'meta_query'     => array(
				array( 'key' => DXAI_UI\Structures\Page_Scope::META, 'value' => (string) $home ),
				array( 'key' => '_dxai_ui_from_live_menu', 'value' => '1' ),
			),
		)
	);
	foreach ( $ids as $id ) {
		$crawled[] = $page_row( array( 'id' => (int) $id ) );
	}
}
// The header spec this home renders, and its menus.
$locations = array();
foreach ( (array) get_nav_menu_locations() as $location => $menu_id ) {
	$locations[ (string) $location ] = (int) $menu_id;
}
$spec  = $home > 0 && class_exists( 'DXAI_UI\Chrome\Header_Template' ) ? DXAI_UI\Chrome\Header_Template::for_post( $home ) : null;
$menus = array();
foreach ( ( is_array( $spec ) && is_array( $spec['menus'] ?? null ) ) ? $spec['menus'] : array() as $location => $menu_id ) {
	$menu_id = (int) $menu_id;
	$term    = $menu_id > 0 ? wp_get_nav_menu_object( $menu_id ) : false;
	if ( ! $term ) {
		$menus[ (string) $location ] = array( 'id' => $menu_id, 'exists' => false );
		continue;
	}
	$items   = get_posts(
		array(
			'post_type'              => 'nav_menu_item',
			'post_status'            => 'publish',
			'numberposts'            => -1,
			'orderby'                => 'menu_order',
			'order'                  => 'ASC',
			'update_post_term_cache' => false,
			'tax_query'              => array(
				array(
					'taxonomy' => 'nav_menu',
					'field'    => 'term_taxonomy_id',
					'terms'    => (int) $term->term_taxonomy_id,
				),
			),
		)
	);
	$invalid = array();
	$titles  = array();
	foreach ( $items as $item ) {
		$item = wp_setup_nav_menu_item( $item );
		if ( ! empty( $item->_invalid ) ) {
			$invalid[] = (string) $item->title;
		}
		if ( 0 === (int) $item->menu_item_parent ) {
			$titles[] = wp_strip_all_tags( (string) $item->title );
		}
	}
	$menus[ (string) $location ] = array(
		'id'       => $menu_id,
		'exists'   => true,
		'name'     => (string) $term->name,
		'marked'   => (string) get_term_meta( $menu_id, DXAI_UI\Structures\Navigation_Factory::HEADER_MENU_META, true ),
		'owner'    => (string) get_term_meta( $menu_id, DXAI_UI\Structures\Navigation_Factory::OWNER_META, true ),
		'items'    => count( $items ),
		'top'      => array_slice( $titles, 0, 12 ),
		'invalid'  => $invalid,
		'assigned' => ( $locations[ (string) $location ] ?? 0 ) === $menu_id,
	);
}
$footer   = class_exists( 'DXAI_UI\Chrome\Footer_Template' ) ? DXAI_UI\Chrome\Footer_Template::get() : array();
$sidebars = wp_get_sidebars_widgets();
$settings = get_option( 'widget_block', array() );
$areas    = array();
$w_style  = 0;
$w_blocks = 0;
foreach ( array_keys( (array) ( $footer['slots'] ?? array() ) ) as $area ) {
	$ids              = array_values( (array) ( $sidebars[ $area ] ?? array() ) );
	$areas[ $area ]   = count( $ids );
	foreach ( $ids as $widget ) {
		if ( preg_match( '/^block-(\d+)$/', (string) $widget, $m ) === 1 && is_array( $settings ) ) {
			$content   = (string) ( $settings[ (int) $m[1] ]['content'] ?? '' );
			$w_style  += $styles( $content );
			$w_blocks += count( array_filter( parse_blocks( $content ), static fn( $b ) => null !== $b['blockName'] ) );
		}
	}
}
echo wp_json_encode(
	array(
		'home'      => $home,
		'page_key'  => $home > 0 ? (string) get_post_meta( $home, '_dxai_ui_page_key', true ) : '',
		'pages'     => $pages,
		'crawled'   => $crawled,
		'locations' => $locations,
		'spec'      => is_array( $spec ) ? array(
			'scope'   => DXAI_UI\Chrome\Header_Template::scope_of( $spec ),
			'is_site' => DXAI_UI\Chrome\Header_Template::is_site( $spec ),
			'hash'    => (string) ( $spec['hash'] ?? '' ),
			'owner'   => (string) ( $spec['source']['owner'] ?? '' ),
			'style'   => $styles( (string) ( $spec['markup'] ?? '' ) ),
			'menus'   => $menus,
		) : null,
		'footer'    => $footer === array() ? null : array(
			'scope'          => (int) ( $footer['scope'] ?? 0 ),
			'page'           => (int) ( $footer['page_id'] ?? 0 ),
			'owner'          => (string) ( $footer['owner'] ?? '' ),
			'title'          => (string) ( $footer['title'] ?? '' ),
			'areas'          => $areas,
			'widget_blocks'  => $w_blocks,
			'widget_style'   => $w_style,
			'template_style' => $styles( (string) ( $footer['template'] ?? '' ) ),
		),
	),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
), "\n";
`;

/*
 * How each page's header and footer were built, for the chrome stage.
 * Read-only: raw options and posts, never an accessor that upgrades what it
 * reads (Header_Template::load() rewrites an older spec it meets). Run with
 * `wp eval-file <this> <comma-separated page ids>`.
 *
 * For each page: its first and last top-level blocks (the header and footer
 * block, or the template part and whether that part is still published);
 * its design scope; for the page that owns a conversion record, the install
 * answers save() recorded there (Structure_Repository::install_chrome():
 * installed, or which refusal — `header_unreadable`, `no_header` …); and
 * the header spec stored for that scope, with each of its menus: still
 * there, marked as that location's header menu, and whether any of its
 * items points at a page that is gone (core's `_invalid`, which
 * wp_get_nav_menu_items() drops outside wp-admin, so the items are read
 * directly). Pages a crawl made for a home (`_dxai_ui_from_live_menu`) are
 * added, since they have to carry the same chrome as it.
 */
const CHROME_PHP = String.raw`<?php
// Written by bin/verify-import.cjs. Read-only.
$ids   = array_values( array_filter( array_map( 'intval', explode( ',', (string) ( $args[0] ?? '' ) ) ) ) );
$menu  = static function ( int $menu_id ): array {
	$term = $menu_id > 0 ? wp_get_nav_menu_object( $menu_id ) : false;
	if ( ! $term ) {
		return array( 'id' => $menu_id, 'exists' => false );
	}
	$items   = get_posts(
		array(
			'post_type'              => 'nav_menu_item',
			'post_status'            => 'publish',
			'numberposts'            => -1,
			'orderby'                => 'menu_order',
			'order'                  => 'ASC',
			'update_post_term_cache' => false,
			'tax_query'              => array(
				array(
					'taxonomy' => 'nav_menu',
					'field'    => 'term_taxonomy_id',
					'terms'    => (int) $term->term_taxonomy_id,
				),
			),
		)
	);
	$invalid = array();
	foreach ( $items as $item ) {
		$item = wp_setup_nav_menu_item( $item );
		if ( ! empty( $item->_invalid ) ) {
			$invalid[] = (string) $item->title;
		}
	}
	return array(
		'id'       => $menu_id,
		'exists'   => true,
		'name'     => (string) $term->name,
		'location' => (string) get_term_meta( $menu_id, DXAI_UI\Structures\Navigation_Factory::HEADER_MENU_META, true ),
		'items'    => count( $items ),
		'invalid'  => $invalid,
	);
};
$block = static function ( ?array $b ): array {
	if ( null === $b ) {
		return array( 'name' => '' );
	}
	$row = array( 'name' => (string) $b['blockName'] );
	if ( 'core/template-part' === $b['blockName'] ) {
		$slug        = (string) ( $b['attrs']['slug'] ?? '' );
		$row['slug'] = $slug;
		$parts       = '' === $slug ? array() : get_posts(
			array(
				'post_type'      => 'wp_template_part',
				'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future', 'trash' ),
				'name'           => $slug,
				'posts_per_page' => 5,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);
		$live        = array_values( array_filter( $parts, static fn( $p ) => 'publish' === $p->post_status ) );
		$part        = $live[0] ?? ( $parts[0] ?? null );
		if ( ! $part ) {
			// A part a theme ships is a file, not a post.
			$row['status'] = get_block_file_template( get_stylesheet() . '//' . $slug, 'wp_template_part' ) ? 'publish' : 'missing';
		} else {
			$row['status'] = (string) $part->post_status;
			$row['part']   = (int) $part->ID;
			// style="" attributes in the part as saved (the user's rule: none).
			$row['style']  = (int) preg_match_all( '/<[a-zA-Z][^>]*\sstyle\s*=/', (string) $part->post_content );
		}
	}
	return $row;
};
$rows  = array();
$homes = array();
$row   = static function ( int $id, bool $crawled ) use ( $block, $menu ): array {
	$top = array_values( array_filter( parse_blocks( (string) get_post_field( 'post_content', $id ) ), static fn( $b ) => null !== $b['blockName'] ) );
	$out = array(
		'id'      => $id,
		'title'   => (string) get_the_title( $id ),
		'source'  => (string) get_post_meta( $id, '_dxai_ui_source_zip', true ),
		'scope'   => (int) get_post_meta( $id, DXAI_UI\Structures\Page_Scope::META, true ),
		'crawled' => $crawled || '1' === (string) get_post_meta( $id, '_dxai_ui_from_live_menu', true ),
		'first'   => $block( $top[0] ?? null ),
		'last'    => $block( $top ? $top[ count( $top ) - 1 ] : null ),
		// style="" attributes in the page as saved.
		'style'   => (int) preg_match_all( '/<[a-zA-Z][^>]*\sstyle\s*=/', (string) get_post_field( 'post_content', $id ) ),
	);
	$conv = get_posts(
		array(
			'post_type'      => DXAI_UI\Content\Content_Types::CONVERSION,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'meta_key'       => '_dxai_ui_page_id',
			'meta_value'     => (string) $id,
		)
	);
	if ( $conv ) {
		$record             = json_decode( (string) $conv[0]->post_content, true );
		$chrome             = is_array( $record['created']['chrome'] ?? null ) ? $record['created']['chrome'] : null;
		$out['conversion']  = array(
			'id'     => (int) $conv[0]->ID,
			'chrome' => null,
			// The header and footer choice the import made (Chrome_Choice): keep or install.
			'mode'   => (string) ( $record['created']['chrome_mode']['mode'] ?? '' ),
			'reason' => (string) ( $record['created']['chrome_mode']['reason'] ?? '' ),
		);
		if ( null !== $chrome ) {
			foreach ( array( 'header', 'footer' ) as $area ) {
				$answer                                   = (array) ( $chrome[ $area ] ?? array() );
				$out['conversion']['chrome'][ $area ] = array(
					'installed' => '' !== (string) ( $answer['block_markup'] ?? '' ),
					'error'     => (string) ( $answer['error'] ?? '' ),
					'message'   => wp_strip_all_tags( (string) ( $answer['message'] ?? '' ) ),
					// Locations a person had given a menu of their own: the install left them.
					'kept'      => array_map( 'strval', array_keys( (array) ( $answer['locations_kept'] ?? array() ) ) ),
				);
			}
		}
		$site  = get_option( DXAI_UI\Chrome\Header_Template::OPTION );
		$spec  = is_array( $site ) && DXAI_UI\Chrome\Header_Template::scope_of( $site ) === $id ? $site : get_option( DXAI_UI\Chrome\Header_Template::SCOPE_OPTION . $id );
		$menus = array();
		foreach ( ( is_array( $spec ) && is_array( $spec['menus'] ?? null ) ) ? $spec['menus'] : array() as $location => $menu_id ) {
			$menus[ (string) $location ] = $menu( (int) $menu_id );
		}
		$out['spec'] = is_array( $spec ) ? array( 'menus' => $menus ) : null;
	}
	return $out;
};
foreach ( $ids as $id ) {
	$one    = $row( $id, false );
	$rows[] = $one;
	if ( isset( $one['conversion'] ) ) {
		$homes[] = $id;
	}
}
foreach ( $homes as $home ) {
	$crawled = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'post__not_in'   => $ids,
			'meta_query'     => array(
				array( 'key' => DXAI_UI\Structures\Page_Scope::META, 'value' => (string) $home ),
				array( 'key' => '_dxai_ui_from_live_menu', 'value' => '1' ),
			),
		)
	);
	foreach ( $crawled as $id ) {
		$rows[] = $row( (int) $id, true );
	}
}
$locations = array();
foreach ( (array) get_nav_menu_locations() as $location => $menu_id ) {
	if ( in_array( $location, array( 'primary-navigation', 'header-top', 'header-actions' ), true ) ) {
		$locations[ $location ] = (int) $menu_id;
	}
}
$footer   = get_option( DXAI_UI\Chrome\Footer_Template::OPTION );
$footer   = is_array( $footer ) && is_array( $footer['slots'] ?? null ) ? $footer : array();
$sidebars = wp_get_sidebars_widgets();
$areas    = array();
foreach ( array_keys( $footer['slots'] ?? array() ) as $area ) {
	$areas[ (string) $area ] = count( (array) ( $sidebars[ $area ] ?? array() ) );
}
echo wp_json_encode(
	array(
		'may_install' => class_exists( 'DXAI_UI\Theme\Theme_Compat' ) ? DXAI_UI\Theme\Theme_Compat::may_install_chrome() : false,
		'locations'   => $locations,
		'footer'      => array(
			'scope' => (int) ( $footer['scope'] ?? 0 ),
			'page'  => (int) ( $footer['page_id'] ?? 0 ),
			'title' => (string) ( $footer['title'] ?? '' ),
			'areas' => $areas,
		),
		'pages'       => $rows,
	),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
), "\n";
`;

/*
 * The site-wide state an import writes: the header's menu locations and the
 * site header record, the one footer record and the widgets in its areas,
 * the brand (Design_Theme_Json) and the Site Logo. `save <file>` before the
 * run imports anything; `restore <file>` after. Values are kept serialized,
 * so they come back exactly as they were.
 *
 * Restoring deletes nothing: the widget placement goes back to what it was,
 * and every widget the run placed that the saved placement does not hold is
 * put in Inactive widgets; widget contents the run overwrote get their old
 * content back and the ones it created are kept. Menus the run wrote stay
 * where Appearance > Menus lists them. An option the run created is removed
 * again, which is what "as it was" means for it.
 *
 * `fingerprint` prints an md5 of each of those values except the brand, the
 * number of classic menus, and the highest post id: what an import that
 * KEEPS the site's header and footer must leave exactly as it found, and
 * where the run's own posts start.
 */
const CHROME_STATE_PHP = String.raw`<?php
// Written by bin/verify-import.cjs.
$mode    = (string) ( $args[0] ?? '' );
$file    = (string) ( $args[1] ?? '' );
$options = array( 'dxai_ui_site_header', 'dxai_ui_site_footer', 'dxai_ui_footer_widgets', 'dxai_ui_site_design', 'site_logo' );
$mods    = array( 'nav_menu_locations', 'custom_logo', 'sidebars_widgets' );
$pack    = static fn( $value ): ?string => null === $value ? null : base64_encode( serialize( $value ) );
$unpack  = static fn( string $value ) => unserialize( base64_decode( $value ), array( 'allowed_classes' => false ) );
$take    = static function () use ( $options, $mods, $pack ): array {
	$state = array( 'options' => array(), 'mods' => array() );
	foreach ( $options as $name ) {
		$state['options'][ $name ] = $pack( get_option( $name, null ) );
	}
	foreach ( $mods as $name ) {
		$state['mods'][ $name ] = $pack( get_theme_mod( $name, null ) );
	}
	$state['sidebars']     = $pack( get_option( 'sidebars_widgets', array() ) );
	$state['widget_block'] = $pack( get_option( 'widget_block', array() ) );
	return $state;
};
if ( 'fingerprint' === $mode ) {
	global $wpdb;
	$state = $take();
	$print = array();
	foreach ( $state['options'] as $name => $value ) {
		if ( 'dxai_ui_site_design' !== $name ) {
			$print[ $name ] = md5( (string) $value );
		}
	}
	foreach ( $state['mods'] as $name => $value ) {
		$print[ 'theme_mod ' . $name ] = md5( (string) $value );
	}
	$print['sidebars_widgets'] = md5( (string) $state['sidebars'] );
	$print['widget_block']     = md5( (string) $state['widget_block'] );
	$print['classic menus']    = (string) wp_count_terms( array( 'taxonomy' => 'nav_menu', 'hide_empty' => false ) );
	echo wp_json_encode( array( 'print' => $print, 'max_post' => (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ) ) ), "\n";
	return;
}
if ( 'save' === $mode ) {
	$state          = $take();
	$state['saved'] = gmdate( 'c' );
	file_put_contents( $file, wp_json_encode( $state ) );
	echo wp_json_encode( array( 'saved' => $file ) ), "\n";
	return;
}
if ( 'restore' !== $mode || ! is_readable( $file ) ) {
	echo wp_json_encode( array( 'error' => 'usage: save|restore <file>' ) ), "\n";
	return;
}
$was     = json_decode( (string) file_get_contents( $file ), true );
$now     = $take();
$changed = array();
foreach ( $options as $name ) {
	$old = $was['options'][ $name ] ?? null;
	if ( ( $now['options'][ $name ] ?? null ) === $old ) {
		continue;
	}
	if ( null === $old ) {
		delete_option( $name );
	} else {
		update_option( $name, $unpack( $old ) );
	}
	$changed[] = $name;
}
foreach ( $mods as $name ) {
	$old = $was['mods'][ $name ] ?? null;
	if ( ( $now['mods'][ $name ] ?? null ) === $old ) {
		continue;
	}
	if ( null === $old ) {
		remove_theme_mod( $name );
	} else {
		set_theme_mod( $name, $unpack( $old ) );
	}
	$changed[] = 'theme_mod ' . $name;
}
$old_widgets = is_string( $was['widget_block'] ?? null ) ? (array) $unpack( $was['widget_block'] ) : array();
$widgets     = (array) get_option( 'widget_block', array() );
$merged      = $widgets;
foreach ( $old_widgets as $key => $value ) {
	$merged[ $key ] = $value;
}
if ( $merged !== $widgets ) {
	update_option( 'widget_block', $merged );
	$changed[] = 'widget_block';
}
$old_sidebars = is_string( $was['sidebars'] ?? null ) ? (array) $unpack( $was['sidebars'] ) : array();
$sidebars     = (array) get_option( 'sidebars_widgets', array() );
$placed       = array();
foreach ( $old_sidebars as $area => $list ) {
	if ( is_array( $list ) ) {
		$placed = array_merge( $placed, $list );
	}
}
$moved = array();
foreach ( $sidebars as $area => $list ) {
	if ( ! is_array( $list ) ) {
		continue;
	}
	foreach ( $list as $id ) {
		if ( ! in_array( $id, $placed, true ) && ! in_array( $id, $moved, true ) ) {
			$moved[] = $id;
		}
	}
}
$target                        = $old_sidebars;
$target['wp_inactive_widgets'] = array_values( array_merge( (array) ( $target['wp_inactive_widgets'] ?? array() ), $moved ) );
unset( $target['array_version'] );
$current = $sidebars;
unset( $current['array_version'] );
if ( $target !== $current ) {
	wp_set_sidebars_widgets( $target );
	$changed[] = 'sidebars_widgets';
}
$after = $take();
$same  = $after['options'] === $was['options'] && $after['mods'] === $was['mods'];
echo wp_json_encode(
	array(
		'restored' => $changed,
		'inactive' => count( $moved ),
		'same'     => $same,
		'saved'    => (string) ( $was['saved'] ?? '' ),
	)
), "\n";
`;

/*
 * A test run's cleanup: everything it wrote goes to the trash. Run with
 * `wp eval-file <this> <input.json>`, the input holding the highest post id
 * before the run (`after`), the run's archives and the post ids its imports
 * reported. Also found from the site, so a suite's own import of the same
 * archives is caught too: pages created during the run that carry one of the
 * run's archives, pages a crawl made for them, their conversions, and every
 * post those conversions record (patterns, template parts, navigation
 * posts). Only the types an import writes; never a page older than the
 * run or of another archive, and an older template part only when it carries
 * one of the run's archives (an import revives a design's trashed part by its
 * key); wp_trash_post() only — nothing is deleted.
 */
const TRASH_PHP = String.raw`<?php
// Written by bin/verify-import.cjs.
global $wpdb;
$in       = json_decode( (string) file_get_contents( (string) ( $args[0] ?? '' ) ), true );
$after    = (int) ( $in['after'] ?? 0 );
$archives = array_values( array_filter( array_map( 'strval', (array) ( $in['archives'] ?? array() ) ) ) );
$ids      = array_map( 'intval', (array) ( $in['ids'] ?? array() ) );
$types    = array( 'page', 'wp_block', 'wp_template_part', 'wp_navigation', DXAI_UI\Content\Content_Types::CONVERSION );
if ( $after < 1 || $archives === array() ) {
	echo wp_json_encode( array( 'error' => 'no run to clean up' ) ), "\n";
	return;
}
$in_list = implode( ',', array_fill( 0, count( $archives ), '%s' ) );
$pages   = array_map(
	'intval',
	(array) $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_dxai_ui_source_zip' WHERE p.ID > %d AND p.post_type = 'page' AND m.meta_value IN ($in_list)",
			array_merge( array( $after ), $archives )
		)
	)
);
$pages   = array_values( array_unique( array_merge( $pages, array_values( array_filter( $ids, static fn( int $id ): bool => 'page' === get_post_type( $id ) && in_array( (string) get_post_meta( $id, '_dxai_ui_source_zip', true ), $archives, true ) ) ) ) ) );
if ( $pages !== array() ) {
	$scoped = array_map(
		'intval',
		(array) $wpdb->get_col(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '" . esc_sql( DXAI_UI\Structures\Page_Scope::META ) . "' AND meta_value IN ('" . implode( "','", array_map( 'intval', $pages ) ) . "')"
		)
	);
	$pages  = array_values( array_unique( array_merge( $pages, array_filter( $scoped, static fn( int $id ): bool => $id > $after && 'page' === get_post_type( $id ) ) ) ) );
}
$all = array_merge( $pages, $ids );
foreach ( $pages as $page ) {
	foreach ( get_posts( array( 'post_type' => DXAI_UI\Content\Content_Types::CONVERSION, 'post_status' => 'any', 'posts_per_page' => 5, 'fields' => 'ids', 'meta_key' => '_dxai_ui_page_id', 'meta_value' => (string) $page ) ) as $conversion ) {
		$all[]   = (int) $conversion;
		$record  = json_decode( (string) get_post_field( 'post_content', (int) $conversion ), true );
		$created = is_array( $record['created'] ?? null ) ? $record['created'] : array();
		foreach ( (array) ( $created['patterns'] ?? array() ) as $pattern ) {
			$all[] = (int) ( is_array( $pattern ) ? ( $pattern['id'] ?? 0 ) : $pattern );
		}
		foreach ( array_merge( (array) ( $created['navigation_ids'] ?? array() ), array_values( (array) ( $created['chrome_parts'] ?? array() ) ), array( $created['header_id'] ?? 0, $created['footer_id'] ?? 0 ) ) as $id ) {
			$all[] = (int) $id;
		}
	}
}
$trashed = array();
$skipped = 0;
foreach ( array_values( array_unique( array_filter( $all ) ) ) as $id ) {
	$type = get_post_type( $id );
	if ( ! in_array( $type, $types, true ) ) {
		++$skipped;
		continue;
	}
	if ( 'page' === $type && ( $id <= $after || ! in_array( $id, $pages, true ) ) ) {
		++$skipped;
		continue;
	}
	/*
	 * Older than the run: a post an import of the run's archives brought back
	 * from the trash and wrote again (a template part keyed to the design, a
	 * pattern, a navigation post of its conversion). A template part only when
	 * it says it is one of these archives'.
	 */
	if ( $id <= $after && 'wp_template_part' === $type && ! in_array( (string) get_post_meta( $id, '_dxai_ui_source_zip', true ), $archives, true ) ) {
		++$skipped;
		continue;
	}
	if ( 'trash' === get_post_status( $id ) ) {
		$trashed[ $type ]['already'] = ( $trashed[ $type ]['already'] ?? 0 ) + 1;
		continue;
	}
	if ( wp_trash_post( $id ) ) {
		$trashed[ $type ]['now'][] = $id;
	} else {
		$trashed[ $type ]['failed'][] = $id;
	}
}
echo wp_json_encode( array( 'trashed' => $trashed, 'skipped' => $skipped, 'pages' => $pages ) ), "\n";
`;

/** Run a command, capturing output; never throws. */
function run(cmd, argv, opts = {}) {
	const res = spawnSync(cmd, argv, {
		encoding: 'utf8',
		maxBuffer: 64 * 1024 * 1024,
		cwd: opts.cwd || repo,
		// Never a pipe: a truncating reader sends SIGPIPE and the child dies
		// mid-run. An import killed that way left the site half-populated and
		// the failure looked like a compiler bug.
		stdio: ['ignore', 'pipe', 'pipe'],
	});
	return {
		code: res.status === null ? 1 : res.status,
		out: (res.stdout || '') + (res.stderr || ''),
	};
}

/** wp-cli, however this machine provides it. */
function wp(argv) {
	if (args['wp-cli']) {
		const php = [];
		if (args['php-ini']) { php.push('-c', args['php-ini']); }
		return run(args.php || 'php', [...php, args['wp-cli'], '--path=' + args.wp, ...argv]);
	}
	return run('wp', ['--path=' + args.wp, ...argv]);
}

/** PHP CLI for the scripts that bootstrap WordPress themselves. */
function php(argv) {
	const pre = [];
	if (args['php-ini']) { pre.push('-c', args['php-ini']); }
	return run(args.php || 'php', [...pre, ...argv]);
}

/*
 * The repo's own fixture designs, packed and measured alongside the real ZIPs.
 *
 * Every Lovable export on hand is a TanStack Start project, so the classic
 * `vite_react_shadcn_ts` shape — Tailwind v3, tokens in `tailwind.config.ts`,
 * `@tailwind` directives and `@apply` in `src/index.css`, react-router pages in
 * `src/pages/` — had never been through the pipeline at all. It is most of what
 * exists: every project made before Lovable switched. A fixture in the repo is
 * the only way that shape gets measured on every run rather than when somebody
 * remembers, so it is added to the ZIP list here instead of being something to
 * pass by hand.
 */
/** The fixture archives appended below: never the chrome stage's default. */
const fixtureZips = [];
if (!args['no-fixtures']) {
	/*
	 * Two fixture shapes, one packer each: the classic Vite/Tailwind-v3
	 * Lovable template (legacy-shape.php) and a Claude Design `.dc.html`
	 * export (dc-shape.php). Each prints the ZIP it made; the geometry stage
	 * tells them apart by their entries (zipKind).
	 */
	for (const packer of ['legacy-shape.php', 'dc-shape.php']) {
		const built = php([path.join(repo, 'bin', packer), '--zip-only']);
		const made = built.out.match(/^zip=(.+?) files=\d+$/m);
		if (made && fs.existsSync(made[1])) {
			args.zip.push(made[1]);
			fixtureZips.push(made[1]);
		} else {
			console.error('could not pack the fixture with ' + packer + ':\n' + built.out.trim().slice(-400));
			process.exit(1);
		}
	}
}

const results = [];
const record = (stage, ok, note) => {
	results.push({ stage, ok, note });
	// Clipped, not just padded: a long design title ran into its own result and
	// the column stopped being readable.
	const label = stage.length > 30 ? stage.slice(0, 29) + '…' : stage;
	console.log((ok ? '  ok   ' : '  FAIL ') + label.padEnd(31) + (note || ''));
};

/* -------------------------------------------------------------- manifest --- */

/** What is on the site now: every generated page and every template part. */
function readManifest() {
	const res = wp(['eval-file', path.join(repo, 'bin', 'import-manifest.php')]);
	const line = res.out.trim().split('\n').filter((l) => l.trim().startsWith('{')).pop();
	if (!line) {
		console.error('could not read the import manifest:\n' + res.out.trim().slice(-600));
		process.exit(1);
	}
	return JSON.parse(line);
}

/**
 * The ZIP whose design a page came from.
 *
 * Read from the `_dxai_ui_source_zip` the import records, never guessed: a
 * design's title comes from its own content, so a title-to-filename heuristic
 * paired four of seven designs and reported the rest as passing unmeasured.
 */
function zipFor(page) {
	if (!page.source) { return null; }
	return args.zip.find((zip) => path.basename(zip) === page.source) || null;
}

/** A design's name for a result line: its archive, without the test prefix. */
const prefix = typeof args['title-prefix'] === 'string' ? args['title-prefix'] : '';
function designLabel(source) {
	return String(source || '').replace(/\.zip$/i, '').replace(/^DXAI TEST /, '');
}
function pageLabel(page) {
	const title = String(page.title || page.id);
	return (prefix && title.startsWith(prefix) ? title.slice(prefix.length) : title).slice(0, 20);
}

/**
 * Which kind of export a ZIP is, from its entry list alone.
 *
 * A Lovable export carries `.tsx`/`.jsx` sources; a Claude Design export
 * carries `<Name>.dc.html` plus its `support.js` runtime and no sources at
 * all. This is the test bin/purge-and-import-real-zips.php uses to pick a
 * connector, read the same way — from the ZIP's central directory, without
 * extracting — so the verifier and the importer cannot disagree about what a
 * file is. Anything that is not a .dc.html export is measured the TSX way.
 */
const kinds = new Map();
function zipKind(zip) {
	if (kinds.has(zip)) { return kinds.get(zip); }
	let names = [];
	try {
		const AdmZip = require(path.join(repo, 'node_modules', 'adm-zip'));
		names = new AdmZip(zip).getEntries().map((e) => e.entryName);
	} catch (e) {
		// No adm-zip on this checkout: Info-ZIP's listing says the same thing.
		names = run('unzip', ['-Z1', zip]).out.split('\n').map((l) => l.trim()).filter(Boolean);
	}
	const kind = names.some((n) => /\.dc\.html$/i.test(n)) && !names.some((n) => /\.(?:tsx|jsx)$/i.test(n))
		? 'dc' : 'tsx';
	kinds.set(zip, kind);
	return kind;
}

/** A script from this file, written to the work directory once. */
const written = new Set();
function script(name, body) {
	const file = path.join(work, name);
	if (!written.has(file)) {
		fs.mkdirSync(work, { recursive: true });
		fs.writeFileSync(file, body);
		written.add(file);
	}
	return file;
}

/** The last JSON line of a command's output, or null. */
function lastJson(out) {
	const line = String(out || '').trim().split('\n').filter((l) => l.trim().startsWith('{')).pop();
	try { return line ? JSON.parse(line) : null; } catch (e) { return null; }
}

/** An editor session minted on the server (no password): the argv the round trip takes. */
function editorSession() {
	const cookies = wp(['eval-file', path.join(repo, 'bin', 'editor-roundtrip-cookie.php')]);
	const field = (name) => {
		const m = cookies.out.match(new RegExp('^' + name + '=(.*)$', 'm'));
		return m ? m[1].trim() : '';
	};
	return {
		ok: Boolean(field('cookie_value')),
		name: field('cookie_name'), value: field('cookie_value'),
		authName: field('auth_cookie_name'), authValue: field('auth_cookie_value'),
	};
}

/* ---------------------------------------------------------------- import --- */

/** Every archive this run imports, for the holders guard and the cleanup (IMPORT_PHP, TRASH_PHP). */
const runArchives = new Set();
/** Every post id the run's imports reported writing. */
const runWritten = new Set();
/** The header/footer state right after the run's last import (fingerprint()). */
let lastWrite = null;

/**
 * Import one archive in place (IMPORT_PHP) and record it.
 *
 * @param {string} zip
 * @param {string} mode  'keep' (the header and footer stay template parts, the
 *                       site's menus and widgets untouched) or 'install'.
 * @return {object|null} what the import answered, or null when it failed.
 */
function importOne(zip, mode) {
	const name = path.basename(zip);
	/*
	 * Someone else wrote the site's header/footer since this run's previous
	 * import (a person in wp-admin, another process): theirs is now the
	 * state the site returns to at the end, so it is saved again — the one
	 * saved at the start would undo their change.
	 */
	if (saved && lastWrite) {
		const foreign = changedKeys(lastWrite, fingerprint());
		if (foreign.length) {
			const took = lastJson(wp(['eval-file', script('chrome-state.php', CHROME_STATE_PHP), 'save', stateFile]).out);
			console.log('note: ' + foreign.join(', ') + ' changed since this run\'s last import, by someone else — '
				+ (took && took.saved ? 'that is the state the run now puts back at the end' : 'COULD NOT SAVE IT AGAIN'));
		}
	}
	runArchives.add(name);
	const list = path.join(work, 'run-archives.json');
	fs.mkdirSync(work, { recursive: true });
	fs.writeFileSync(list, JSON.stringify([...runArchives]));
	const res = wp(['eval-file', script('import-one.php', IMPORT_PHP), zip, prefix, mode, list]);
	const answer = lastJson(res.out);
	const ok = Boolean(answer && answer.ok);
	// What the site's header/footer state is after this run's own write (the restore's guard).
	lastWrite = fingerprint();
	if (answer && Array.isArray(answer.written)) {
		answer.written.forEach((id) => runWritten.add(id));
	}
	const area = (a, where) => (a.installed ? where : 'part (' + (a.error || '?') + ')');
	const chromeNote = answer && answer.chrome
		? '  header ' + area(answer.chrome.header, 'in Menus') + ', footer ' + area(answer.chrome.footer, 'in Widgets')
		: '';
	const label = (mode === 'install' ? 'install: ' : 'import: ') + designLabel(name);
	record(label, ok, ok
		? 'page ' + answer.page_id + (answer.page_ids.length > 1 ? ' (+' + (answer.page_ids.length - 1) + ' route page(s))' : '')
			+ '  ' + answer.seconds + 's' + chromeNote
			+ (answer.findings.length ? '  audit FOUND(' + answer.findings.join(',') + ')' : '')
		: (answer ? answer.status + (answer.message ? ': ' + String(answer.message).slice(0, 120) : '') : 'no answer'));
	if (!answer) {
		console.log(res.out.trim().split('\n').slice(-12).map((l) => '       ' + l).join('\n'));
	}
	return ok ? answer : null;
}

/** The site's header/footer state as md5s, and the highest post id (CHROME_STATE_PHP fingerprint). */
function fingerprint() {
	return lastJson(wp(['eval-file', script('chrome-state.php', CHROME_STATE_PHP), 'fingerprint']).out);
}

/** The keys whose md5 differs between two fingerprints. */
function changedKeys(a, b) {
	if (!a || !b) { return ['(no fingerprint)']; }
	return Object.keys(Object.assign({}, a.print, b.print)).filter((k) => a.print[k] !== b.print[k]);
}

/**
 * An import that was asked to keep the site's header and footer: save() must
 * have made that choice, installed nothing, and left the site's menus,
 * locations, widgets, footer record and logo exactly as they were.
 */
function assertKept(zip, answer, before, after) {
	const label = 'keep: ' + designLabel(path.basename(zip));
	if (!answer) { return; }
	const problems = [];
	if (!answer.mode) {
		problems.push('save() reports no header/footer choice (chrome_mode) — it does not take the keep option');
	} else if (answer.mode.mode !== 'keep') {
		problems.push('save() chose ' + answer.mode.mode + ' (' + answer.mode.reason + ') where keep was asked for');
	}
	for (const a of ['header', 'footer']) {
		if (answer.chrome[a].installed) { problems.push('the ' + a + ' was installed'); }
	}
	const changed = changedKeys(before, after);
	if (changed.length) { problems.push('the site changed: ' + changed.join(', ')); }
	const kept = ['header', 'footer'].map((a) => a + ' ' + (answer.chrome[a].error === 'kept' ? 'part ' + answer.parts[a] : answer.chrome[a].error || '?')).join(', ');
	record(label, problems.length === 0, problems.length ? problems.join('; ') : kept + '; Menus and Widgets untouched');
}

/* ---------------------------------------------------------------- chrome --- */

/** How the header and footer of these pages were built (CHROME_PHP), or null. */
function readChrome(pages) {
	if (!pages.length) { return null; }
	const res = wp(['eval-file', script('chrome.php', CHROME_PHP), pages.map((p) => p.id).join(',')]);
	const info = lastJson(res.out);
	if (!info) {
		record('chrome', false, 'could not read the chrome of ' + pages.length + ' page(s): ' + res.out.trim().slice(-200));
		return null;
	}
	return info;
}

/** A page of a design's result line: its home, or which other page. */
function where0(r, home) {
	return r.id === (home && home.id) ? 'home' : (r.crawled ? 'crawled page ' : 'route page ') + r.id;
}

/** A top-level block as a result line names it. */
function describeBlock(b) {
	if (!b || !b.name) { return 'nothing'; }
	return b.name === 'core/template-part' ? 'template part ' + b.slug + ' (' + b.status + ')' : b.name;
}

/**
 * The user's direction for the chrome, asserted per design: the header built
 * in Appearance > Menus and the footer in Appearance > Widgets, on the home,
 * its route pages and every page a crawl made for it; a header whose menu
 * slots cannot be read (`header_unreadable`) kept as its template part, still
 * published and still referenced. A design whose chrome is simply missing on
 * a theme where it may be installed (Theme_Compat::may_install_chrome()) is a
 * failure: that is the regression where an import quietly falls back to
 * template parts, which every other stage measures as a pass.
 *
 * Returns, per page id, whose footer the page is showing when it is not its
 * own design's (the site has one footer), for the geometry stage to say.
 */
function stageChrome(info, opts) {
	const foreign = {};
	if (!info) { return foreign; }
	const byScope = new Map();
	for (const row of info.pages) {
		const key = row.scope || row.id;
		if (!byScope.has(key)) { byScope.set(key, []); }
		byScope.get(key).push(row);
	}
	for (const [scope, rows] of byScope) {
		const home = rows.find((r) => r.id === scope && r.conversion) || rows.find((r) => r.conversion);
		const label = 'chrome: ' + designLabel((home || rows[0]).source || (home || rows[0]).title);
		if (!home) {
			if (!opts.quiet) {
				record(label, false, 'no conversion record for ' + rows.map((r) => r.id).join(', ') + ' — nothing says how its chrome was built');
			}
			continue;
		}
		/*
		 * No style="" in what the import saved — the pages and the template
		 * parts they reference (the user's rule: the design's CSS lives in
		 * classes and stylesheets, never inline).
		 */
		if (!opts.quiet) {
			const found = [];
			const parts = new Set();
			for (const r of rows) {
				if (r.style) { found.push(where0(r, home) + ' ' + r.style); }
				for (const b of [r.first, r.last]) {
					if (b && b.part && b.style && !parts.has(b.part)) { parts.add(b.part); found.push('part ' + b.slug + ' ' + b.style); }
				}
			}
			record('styles: ' + designLabel((home || rows[0]).source || (home || rows[0]).title), found.length === 0,
				found.length ? 'style="" in the saved markup: ' + found.slice(0, 6).join(', ') : '0 style="" in ' + rows.length + ' page(s) and their template parts');
		}
		const problems = [];
		const notes = [];
		const H = home.conversion.chrome ? home.conversion.chrome.header : null;
		const F = home.conversion.chrome ? home.conversion.chrome.footer : null;
		const where = (r) => (r.id === home.id ? 'home' : (r.crawled ? 'crawled page ' : 'route page ') + r.id);
		if (home.conversion.mode === 'keep') {
			/*
			 * The import kept the site's header and footer: every page of the
			 * design carries its own as the template parts, still published,
			 * and neither block.
			 */
			for (const [area, answer, at] of [['header', H, 'first'], ['footer', F, 'last']]) {
				if (answer && answer.error === 'no_' + area) { notes.push('no ' + area + ' in the design'); continue; }
				for (const r of rows) {
					const b = r[at];
					if (b.name !== 'core/template-part') {
						problems.push(where(r) + ': the ' + area + ' is ' + describeBlock(b) + ', not its template part (the import kept the site\'s)');
					} else if (b.status !== 'publish') {
						problems.push(where(r) + ': the ' + area + ' part ' + b.slug + ' is ' + b.status);
					}
				}
			}
			notes.unshift('kept as template parts (' + (home.conversion.reason || 'keep') + ')');
			if (!opts.quiet) {
				record(label, problems.length === 0, notes.join('; ') + (rows.length > 1 ? '  [' + rows.length + ' pages]' : ''));
				for (const p of problems.slice(0, 12)) { console.log('       ' + p); }
			}
			continue;
		}
		if (!info.may_install) {
			const blocks = rows.filter((r) => r.first.name === 'dxai-ui/site-header' || r.last.name === 'dxai-ui/site-footer');
			if (blocks.length) {
				problems.push(blocks.length + ' page(s) carry the header/footer block on a theme that renders its own header and footer');
			}
			notes.push('the theme keeps its own header and footer; template parts kept');
		} else {
			/* ------ header ------ */
			if (!H) {
				problems.push('the conversion has no chrome report: imported before headers were built in Menus — re-import it');
			} else if (H.installed) {
				for (const r of rows) {
					if (r.first.name !== 'dxai-ui/site-header') {
						problems.push(where(r) + ': the header is ' + describeBlock(r.first) + ', not the Menus header block');
					}
				}
				const menus = home.spec ? home.spec.menus : {};
				const list = Object.entries(menus || {});
				if (!list.length) {
					problems.push('no header spec with menus stored for scope ' + home.id);
				}
				let items = 0;
				for (const [location, m] of list) {
					if (!m.exists) { problems.push('the ' + location + ' menu (' + m.id + ') is gone'); continue; }
					items += m.items;
					if (m.location !== location) {
						problems.push('menu ' + m.id + ' "' + m.name + '" is not marked as the ' + location + ' header menu (' + (m.location || 'unmarked') + ')');
					}
					if (m.invalid.length) {
						problems.push(m.invalid.length + ' item(s) of "' + m.name + '" point at a page that is gone: ' + m.invalid.slice(0, 3).join(', '));
					}
					// Right after its own import, the design holds the site's
					// locations — except one a person gave a menu of their own,
					// which the install leaves as they set it (locations_kept).
					if (opts.imported && !(H.kept || []).includes(location) && info.locations[location] !== m.id) {
						problems.push('location ' + location + ' holds menu ' + (info.locations[location] || 'none') + ', not this design\'s ' + m.id);
					}
				}
				notes.push('header in Menus (' + list.length + ' menu(s), ' + items + ' item(s))');
			} else if (H.error === 'no_header') {
				notes.push('no header in the design');
			} else {
				for (const r of rows) {
					if (r.first.name !== 'core/template-part') {
						problems.push(where(r) + ': the header was refused (' + H.error + ') and is ' + describeBlock(r.first) + ', not its template part');
					} else if (r.first.status !== 'publish') {
						problems.push(where(r) + ': the kept header part ' + r.first.slug + ' is ' + r.first.status);
					}
				}
				notes.push('header kept as a template part (' + H.error + ')');
			}
			/* ------ footer ------ */
			if (!F) {
				if (H) { problems.push('the conversion has no footer report'); }
			} else if (F.installed) {
				for (const r of rows) {
					if (r.last.name !== 'dxai-ui/site-footer') {
						problems.push(where(r) + ': the footer is ' + describeBlock(r.last) + ', not the Widgets footer block');
					}
				}
				const widgets = Object.values(info.footer.areas || {}).reduce((n, c) => n + c, 0);
				if (info.footer.scope !== home.id) {
					const whose = info.footer.title ? '"' + info.footer.title + '" (' + info.footer.scope + ')' : 'no design';
					if (opts.imported) {
						problems.push('the stored footer is ' + whose + '\'s, not this design\'s, right after its own import');
					} else {
						notes.push('footer in Widgets, but the site\'s footer is now ' + whose + '\'s (one footer per site)');
						for (const r of rows) {
							if (r.last.name === 'dxai-ui/site-footer') { foreign[r.id] = whose; }
						}
					}
				} else {
					if (widgets === 0) {
						problems.push('the footer\'s ' + Object.keys(info.footer.areas || {}).length + ' widget area(s) are empty');
					}
					notes.push('footer in Widgets (' + Object.keys(info.footer.areas || {}).length + ' area(s), ' + widgets + ' widget(s))');
				}
			} else if (F.error === 'no_footer') {
				notes.push('no footer in the design');
			} else {
				for (const r of rows) {
					if (r.last.name !== 'core/template-part') {
						problems.push(where(r) + ': the footer was refused (' + F.error + ') and is ' + describeBlock(r.last) + ', not its template part');
					} else if (r.last.status !== 'publish') {
						problems.push(where(r) + ': the kept footer part ' + r.last.slug + ' is ' + r.last.status);
					}
				}
				notes.push('footer kept as a template part (' + F.error + ')');
			}
		}
		if (opts.quiet) { continue; }
		const extra = rows.length - 1;
		record(label, problems.length === 0, notes.join('; ') + (extra ? '  [' + rows.length + ' pages]' : ''));
		for (const p of problems.slice(0, 12)) { console.log('       ' + p); }
		if (problems.length > 12) { console.log('       … and ' + (problems.length - 12) + ' more'); }
	}
	return foreign;
}

/* -------------------------------------------------------------- geometry --- */

// One port per Claude Design export in a run, so a leftover server from a
// previous design cannot be measured as this one.
let dcPort = parseInt(args['dc-port'], 10) || 5301;

/**
 * @param {object} [opts] strict: gate structure, box and text whatever --strict
 *                        says, and paint too (the chrome stage's page);
 *                        baseline: page id => the numbers the same page measured
 *                        before (with its template parts) — with strict, a page
 *                        passes at 0px when every number is 0 or all equal its
 *                        baseline, so what is measured is what the chrome
 *                        changed; title: the heading; tag: the lines' prefix.
 * @return {Array<object>} per page: id, worst, missing, extra, box, text, paint, ok.
 */
function stageGeometry(pages, foreign, opts = {}) {
	console.log('\n' + (opts.title || 'geometry (design oracle)'));
	const strict = opts.strict === undefined ? Boolean(args.strict) : Boolean(opts.strict);
	// Structure is gated here, not by the oracle's own exit code, unless strict
	// and nothing to compare with.
	const loose = !strict || Boolean(opts.baseline);
	const tag = opts.tag || 'geometry: ';
	const out = [];
	fs.mkdirSync(work, { recursive: true });
	let measured = 0;

	for (const page of pages) {
		const zip = zipFor(page);
		if (!zip) {
			// Not "skipped": an unmeasured page has not been verified, and
			// reporting it green is how three designs rode along unchecked.
			record(tag + pageLabel(page), false,
				page.source
					? 'no --zip given for ' + page.source
					: 'page has no recorded source ZIP — re-import to record one');
			continue;
		}
		let measure;
		if (zipKind(zip) === 'dc') {
			/*
			 * A Claude Design export has no TSX for design-oracle.php to
			 * compile — and compiling its template with our own parser would
			 * put a template-reading loss on both sides of the diff anyway,
			 * which is the blind spot the React stage exists to close for
			 * Lovable exports. Its design side is therefore the export's OWN
			 * runtime: bin/dc-oracle.cjs serves the .dc.html with support.js,
			 * React vendored and fonts mirrored (see that file for why each),
			 * and it is measured through the same --design-url path the React
			 * oracle uses. Stopped as soon as it has been read.
			 */
			const port = dcPort++;
			const stop = () => run(process.execPath, [
				path.join(repo, 'bin', 'dc-oracle.cjs'), '--stop', '--port', String(port), '--work', work,
			]);
			const served = run(process.execPath, [
				path.join(repo, 'bin', 'dc-oracle.cjs'), zip, '--port', String(port), '--work', work, '--chrome', chrome,
			]);
			if (served.code !== 0 || !/^serving /m.test(served.out)) {
				// Recorded as a failure, not skipped: an unmeasured page has
				// not been verified.
				record(tag + pageLabel(page), false, 'design runtime did not render');
				console.log(served.out.trim().split('\n').slice(-6).map((l) => '       ' + l).join('\n'));
				stop();
				continue;
			}
			// The root selector is dc-oracle's to know — it is where the
			// runtime puts the template wrapper — so it is read from the
			// output rather than repeated here. If that level ever moves, the
			// fix belongs in one place.
			const designRoot = ((served.out.match(/^design-root: (.+)$/m) || [])[1] || '#dc-root > .sc-host > div').trim();
			measure = run(process.execPath, [
				path.join(repo, 'bin', 'design-oracle.cjs'),
				'--design-url', 'http://127.0.0.1:' + port + '/oracle.dc.html', '--design-root', designRoot,
				'--url', page.url, '--depth', '20', '--chrome', chrome, '--styles',
				// As below: structure gates only where this stage says so.
				...(loose ? ['--max-missing', 'off', '--max-extra', 'off'] : []),
				...widths.flatMap((w) => ['--width', w]),
			]);
			stop();
		} else {
			const dir = path.join(work, 'oracle-' + page.id);
			// The route this page came from, so a design with several gets the
			// right one on its design side instead of the design's first page.
			const built = wp(['eval-file', path.join(repo, 'bin', 'design-oracle.php'), zip, dir,
				...(page.route ? [page.route] : [])]);
			if (!/sections=\d+/.test(built.out) || /sections=0/.test(built.out)) {
				record(tag + pageLabel(page), false, 'oracle build failed');
				continue;
			}
			measure = run(process.execPath, [
				path.join(repo, 'bin', 'design-oracle.cjs'),
				'--dir', dir, '--url', page.url, '--depth', '20', '--chrome', chrome,
				// Paint too. The mode existed but nothing passed the flag, so it
				// never ran in a build — and a wrong colour moves no boxes, so the
				// pixel total was silent about ten mis-coloured nodes across three
				// designs until it was run by hand.
				'--styles',
				/*
				 * Structure gates only under --strict, as the comment on the pass
				 * condition below says. design-oracle.cjs now gates missing/extra
				 * nodes at 0 BY DEFAULT and folds that into its exit code, so
				 * without this every page with one unpaired node failed a plain
				 * run through `measure.code === 0`, before the strict rule was
				 * ever consulted. Under --strict the oracle keeps its default and
				 * both agree.
				 */
				...(loose ? ['--max-missing', 'off', '--max-extra', 'off'] : []),
				...widths.flatMap((w) => ['--width', w]),
			]);
		}
		const errs = [...measure.out.matchAll(/(\d+)px total error/g)].map((m) => Number(m[1]));
		const worst = errs.length ? Math.max(...errs) : null;

		/*
		 * Three more numbers, each of which the pixel total cannot express.
		 * `missing` is the important one: a node the conversion deleted has no
		 * delta, so it contributes 0px, and "0px total error" has therefore
		 * never meant "nothing was lost".
		 */
		const sum = (re) => [...measure.out.matchAll(re)].reduce((n, m) => n + Number(m[1]), 0);
		const missing = sum(/structure: (\d+) node\(s\) in the design and not the page/g);
		const extra = sum(/structure: \d+ node\(s\) in the design and not the page, (\d+) node/g);
		const box = sum(/box: (\d+) node\(s\) differ/g);
		const paint = sum(/paint: (\d+) computed-value difference/g);
		/*
		 * Rendered words. Neither geometry nor paint can see a wrong VALUE — a
		 * label reading `Math%` instead of a percentage, and a footer missing
		 * its year, both measured 0px with no paint difference and passed every
		 * check here. Proven to report by changing one word in one heading:
		 * exactly one node named, both values shown, nothing else. At 0 against
		 * both the compiled and the React oracle, so it goes straight into the
		 * strict gate rather than being printed and left.
		 */
		const text = sum(/text: (\d+) node\(s\) whose rendered words differ/g);

		/*
		 * How many of the requested widths actually reported.
		 *
		 * The oracle died partway through one design and exited non-zero, so
		 * the gate caught it — but the note read "0px worst of 1280/768/390
		 * lost 0 extra 0 box 0 text 0", which is every number a pass has. Only
		 * two of the three widths had run. A failure whose note looks like a
		 * pass is the kind that gets waved through.
		 */
		const widthsSeen = (measure.out.match(/^@\d+px\b/gm) || []).length;

		/*
		 * Only the pixel total gates by default. The other numbers are printed
		 * on every run so they cannot be forgotten, and `--strict` puts
		 * structure, box and text into the pass condition.
		 */
		let ok = measure.code === 0 && worst === 0 && widthsSeen === widths.length
			&& ( ! strict || ( missing === 0 && extra === 0 && box === 0 && text === 0 ) );
		const numbers = { worst, missing, extra, box, text, paint };
		const base = opts.baseline ? opts.baseline[page.id] : null;
		const same = Boolean(base) && Object.keys(numbers).every((k) => base[k] === numbers[k]);
		const zero = Object.values(numbers).every((n) => n === 0);
		if (opts.strict) {
			ok = measure.code === 0 && widthsSeen === widths.length && (zero || (same && worst === 0));
		}
		out.push(Object.assign({ id: page.id, ok }, numbers));

		record(tag + pageLabel(page), ok,
			(errs.length
				? worst + 'px worst of ' + widths.join('/')
				+ '  lost ' + missing + '  extra ' + extra + '  box ' + box + '  text ' + text + '  paint ' + paint
				+ (widthsSeen === widths.length ? '' : '  ONLY ' + widthsSeen + '/' + widths.length + ' WIDTHS MEASURED')
				+ (opts.strict && !zero && base ? (same ? '  = as with its template parts' : '  (with its template parts: ' + base.worst + 'px lost ' + base.missing + ' extra ' + base.extra + ' box ' + base.box + ' text ' + base.text + ' paint ' + base.paint + ')') : '')
				: 'no measurement')
			// The site has one footer: this page shows another design's.
			+ (foreign && foreign[page.id] ? '\n       the footer on this page is ' + foreign[page.id] + '\'s: re-run without --skip-import to measure this design with its own' : ''));
		measured++;
		if (!ok) {
			console.log(measure.out.trim().split('\n').filter((l) => /delta|exact,|→|structure:|^\s*box:|paint:/.test(l)).slice(0, 14)
				.map((l) => '       ' + l.trim()).join('\n'));
		}
	}
	if (measured === 0 && pages.length) {
		record('geometry', false, 'nothing measured — pass --zip so oracles can be built');
	}
	return out;
}

/* ---------------------------------------------------------------- react --- */

/*
 * The same measurement against a design side that owes us nothing.
 *
 * The stage above builds its oracle with bin/design-oracle.php, which calls
 * this plugin's own Jsx_Compiler. That verifies the HTML-to-blocks half of the
 * pipeline and is blind to the half before it: a JSX-reading loss lands on both
 * sides and the diff reports 0px. Measured the day this was added — GTM Strategy
 * Hub scored 0px against the compiled oracle and 310px against its real React
 * build, with a progress bar rendering at zero width, a header logo 24px too
 * wide and a table border in the wrong colour.
 *
 * So this stage builds the design's ACTUAL app — real React, Radix, TanStack
 * Router, Tailwind and Lovable's own published vite config, no plugin code in
 * it anywhere — and measures against that. Running both is the point rather
 * than a duplication: react-vs-compiled isolates a JSX-reading loss,
 * compiled-vs-page isolates a block-conversion loss, and react-vs-page is what
 * the visitor sees.
 *
 * Off by default because an install and build per design costs minutes; node
 * modules are cached per lockfile by bin/react-oracle.sh, so the second run of
 * a design is much cheaper than the first.
 */
// One port per design in a run, so a leftover server from a previous design
// cannot be measured as this one.
let reactPort = parseInt(args['react-port'], 10) || 5199;

function stageReact(pages) {
	console.log('\ngeometry (real React build)');
	let measured = 0;

	for (const page of pages) {
		const zip = zipFor(page);
		if (!zip) {
			record('react: ' + pageLabel(page), false,
				page.source ? 'no --zip given for ' + page.source : 'no recorded source ZIP');
			continue;
		}

		const label = 'react: ' + pageLabel(page);
		if (zipKind(zip) === 'dc') {
			// A Claude Design export is not a React project to install and
			// build; its independent design side is the dc runtime the
			// geometry stage has already measured against. Recorded rather
			// than skipped, so the row count still matches the page count.
			record(label, true, 'n/a: not a React project');
			measured++;
			continue;
		}
		const port = reactPort++;
		const built = run('bash', [path.join(repo, 'bin', 'react-oracle.sh'), zip, '--port', String(port)]);
		if (built.code !== 0 || !/serving/.test(built.out)) {
			/*
			 * A build failure is recorded as a failure, not skipped. The whole
			 * reason this stage exists is that an unverified page was being
			 * reported green; doing that again here would repeat the mistake at
			 * a different level.
			 */
			record(label, false, 'react oracle build failed');
			console.log(built.out.trim().split('\n').slice(-6).map((l) => '       ' + l).join('\n'));
			continue;
		}

		const measure = run(process.execPath, [
			path.join(repo, 'bin', 'design-oracle.cjs'),
			'--design-url', 'http://127.0.0.1:' + port + '/',
			'--url', page.url, '--depth', '20', '--chrome', chrome, '--styles',
			...widths.flatMap((w) => ['--width', w]),
		]);
		run('bash', [path.join(repo, 'bin', 'react-oracle.sh'), '--stop', '--port', String(port)]);

		const errs = [...measure.out.matchAll(/(\d+)px total error/g)].map((m) => Number(m[1]));
		const worst = errs.length ? Math.max(...errs) : null;
		const sum = (re) => [...measure.out.matchAll(re)].reduce((n, m) => n + Number(m[1]), 0);
		const missing = sum(/structure: (\d+) node\(s\) in the design and not the page/g);
		const extra = sum(/structure: \d+ node\(s\) in the design and not the page, (\d+) node/g);
		const box = sum(/box: (\d+) node\(s\) differ/g);
		const paint = sum(/paint: (\d+) computed-value difference/g);
		const text = sum(/text: (\d+) node\(s\) whose rendered words differ/g);

		/*
		 * Only the pixel total gates, and only under --strict-react. The
		 * structure numbers are NOT a pass condition here and should not be: a
		 * converted page legitimately carries nodes the React build does not —
		 * a closed dropdown panel is materialised as hidden markup so the
		 * projected control can show it, where React renders it only when
		 * opened. Reporting that as a failure would make the check unreadable,
		 * which is how a check stops being believed.
		 */
		const ok = measure.code === 0
			&& (!args['strict-react'] || worst === 0);

		record(label, ok, errs.length
			? worst + 'px worst of ' + widths.join('/')
			+ '  unmatched design ' + missing + '  unmatched page ' + extra
			+ '  box ' + box + '  text ' + text + '  paint ' + paint
			: 'no measurement');
		measured++;
		if (worst !== 0 || box > 0 || paint > 0) {
			console.log(measure.out.trim().split('\n')
				.filter((l) => /delta|exact,|→|structure:|^\s*box:|paint:/.test(l)).slice(0, 14)
				.map((l) => '       ' + l.trim()).join('\n'));
		}
	}
	if (measured === 0 && pages.length) {
		record('react', false, 'nothing measured — pass --zip so React oracles can be built');
	}
}

/* ---------------------------------------------------------------- blocks --- */

/**
 * Round-trip each target through the real editor and record one check.
 *
 * @param {Array<{label:string, argv:string[]}>} targets
 */
function stageBlocks(targets, label) {
	if (!targets.length) { return; }
	console.log('\nblock validity (real editor)' + (label !== 'blocks' ? ' — ' + label.replace(/^blocks: /, '') : ''));
	const session = editorSession();
	if (!session.ok) {
		record(label, false, 'could not mint an editor session');
		return;
	}
	const firstUrl = (targetsBase && targetsBase.url) || 'http://localhost';
	const auth = [
		'--cookie-name', session.name, '--cookie-value', session.value,
		'--auth-cookie-name', session.authName, '--auth-cookie-value', session.authValue,
		'--admin-url', new URL('/wp-admin/post.php', firstUrl).toString(),
		'--timeout', '150000', '--chrome', chrome,
	];
	let bad = 0;
	let unmeasured = 0;
	for (const target of targets) {
		/*
		 * Retried once when the round trip returns no verdict.
		 *
		 * Chrome failing to start is not a block defect, and counting the two
		 * together made the check lie: one run reported "blocks 13/18 clean",
		 * which reads as five invalid blocks, when three targets had died on a
		 * profile lockfile and two had timed out. A retry clears the transient
		 * case; what survives it is reported as UNMEASURED, separately from
		 * unclean, because "we could not look" and "we looked and it was wrong"
		 * call for different responses.
		 */
		let res = run(process.execPath, [path.join(repo, 'bin', 'editor-roundtrip.cjs'), ...target.argv, ...auth]);
		let verdict = /invalid=\d+\s+unregistered=\d+/.test(res.out);
		if (!verdict) {
			res = run(process.execPath, [path.join(repo, 'bin', 'editor-roundtrip.cjs'), ...target.argv, ...auth]);
			verdict = /invalid=\d+\s+unregistered=\d+/.test(res.out);
		}
		const clean = /invalid=0\s+unregistered=0/.test(res.out);
		if (!verdict) { unmeasured++; }
		if (!clean) {
			if (verdict) { bad++; }
			/*
			 * Two different failures reach here and they need telling
			 * apart: the editor reported invalid blocks, or the round trip
			 * never finished. The second happens under load — the geometry
			 * stage has just been driving Chrome hard — and it used to
			 * print nothing at all, because the filter below matched no
			 * line. A timeout then looked exactly like 33 invalid blocks.
			 */
			const detail = res.out.trim().split('\n')
				.filter((l) => /INVALID|UNREGISTERED|invalid=/.test(l));
			console.log('       ' + target.label
				+ (detail.length
					? '\n' + detail.slice(0, 6).map((l) => '         ' + l.trim()).join('\n')
					: '  — the round trip did not report a verdict (exit '
						+ res.code + '); last output:\n'
						+ res.out.trim().split('\n').slice(-4)
							.map((l) => '         ' + l.trim()).join('\n')));
		}
	}
	record(label, bad === 0 && unmeasured === 0,
		targets.length - bad - unmeasured + '/' + targets.length + ' clean'
		+ (bad ? '  ' + bad + ' invalid' : '')
		+ (unmeasured ? '  ' + unmeasured + ' UNMEASURED (the editor never reported)' : ''));
}

/** Any page of the run, for the editor's admin URL. */
let targetsBase = null;

/* --------------------------------------------------------------- widgets --- */

/*
 * The footer's blocks, now that the footer lives in Appearance > Widgets.
 *
 * With the footer built into widget areas, its template part is trashed and
 * the blocks stage above has nothing of it left to round-trip. So the block
 * widgets in the stored footer's areas are parsed in the real editor here
 * (WIDGETS_PROBE): an invalid or unregistered block in one of them is what a
 * person would meet in the Widgets screen as "This block contains unexpected
 * or invalid content". A site whose footer is still a template part has no
 * such areas: the stage says so, and fails only when a page carries the
 * footer block all the same (there is then no footer for it to show). The
 * header needs no counterpart — it is built from menus, and its markup is
 * rendered by the server, not edited as blocks; the chrome stage checks its
 * menus.
 */
function stageWidgets(pages, info, label) {
	console.log('\nfooter widgets (real editor)');
	const read = wp(['eval-file', script('footer-widgets.php', FOOTER_WIDGETS_PHP)]);
	const found = lastJson(read.out);
	const wants_footer = info ? info.pages.filter((r) => r.last && r.last.name === 'dxai-ui/site-footer').length : 0;
	if (!found) {
		record(label, false, 'could not read the footer widget areas: ' + read.out.trim().slice(-200));
		return;
	}
	if (!found.areas.length) {
		if (wants_footer) {
			record(label, false, wants_footer + ' page(s) carry the footer block, and no footer is stored in Widgets');
		} else {
			console.log('       no footer widget areas on this site (no footer installed in Widgets) — nothing to check');
		}
		return;
	}
	if (!pages[0]) {
		record(label, false, 'no imported page to open an editor session on');
		return;
	}
	const session = editorSession();
	const input = path.join(work, 'footer-widgets.json');
	fs.writeFileSync(input, JSON.stringify(found));
	const probe = script('widgets.cjs', WIDGETS_PROBE);
	const editUrl = new URL('/wp-admin/post.php?post=' + pages[0].id + '&action=edit', pages[0].url).toString();
	const argv = [probe, chrome, editUrl, input, session.name, session.value, session.authName, session.authValue];
	let res = session.ok ? run(process.execPath, argv) : { code: 1, out: 'could not mint an editor session' };
	let verdict = lastJson(res.out);
	if (!verdict || !verdict.ok) {
		// Once more, as for the round trips: Chrome not starting is no block defect.
		res = session.ok ? run(process.execPath, argv) : res;
		verdict = lastJson(res.out);
	}
	if (!verdict || !verdict.ok) {
		record(label, false, 'UNMEASURED (the editor never reported): '
			+ ((verdict && verdict.error) || res.out.trim().split('\n').slice(-2).join(' ')).slice(0, 160));
	} else {
		const list = verdict.widgets;
		const bad = list.filter((w) => w.invalid.length || w.unregistered.length);
		const blocks = list.reduce((n, w) => n + w.blocks, 0);
		const skipped = list.reduce((n, w) => n + w.skipped, 0);
		for (const w of bad) {
			console.log('       ' + w.id + ' in ' + w.area
				+ (w.invalid.length ? '  INVALID ' + w.invalid.join(', ') : '')
				+ (w.unregistered.length ? '  UNREGISTERED ' + w.unregistered.join(', ') : ''));
		}
		record(label, bad.length === 0 && list.length > 0,
			(list.length - bad.length) + '/' + list.length + ' widget(s) clean in ' + found.areas.length + ' area(s), '
			+ blocks + ' block(s)' + (skipped ? ', ' + skipped + ' Widgets-screen-only block(s) not checked' : '')
			+ (list.length ? '' : ' — the areas are empty'));
	}
	try { fs.unlinkSync(input); } catch (e) { /* ignore */ }
}

/* ------------------------------------------------------------- behaviour --- */

function stageBehaviour(pages, suffix) {
	if (!pages.length) { return; }
	console.log('\nbehaviour (live pages)');
	const probe = script('behaviour.cjs', BEHAVIOUR_PROBE);
	const res = run(process.execPath, [probe, chrome, ...pages.map((p) => p.url)]);
	let bad = 0;
	let reported = 0;
	for (const line of res.out.trim().split('\n')) {
		if (!line.startsWith('{')) { continue; }
		const r = JSON.parse(line);
		reported++;
		const kb = r.keyboard || { menus: 0, passed: 0, skipped: 0, failures: [] };
		/*
		 * Keyboard access gates: a hover menu a keyboard cannot open is a menu
		 * part of the site cannot use, and the runtime has handled focus since
		 * the hover-dropdown fix — a failure here is a regression, not a known
		 * backlog like the inert controls below.
		 */
		const ok = r.errors === 0 && r.broken === 0 && r.shown === r.reveals && kb.failures.length === 0;
		if (!ok) { bad++; }
		record('behaviour: ' + r.slug.slice(0, 20), ok,
			'reveals ' + r.shown + '/' + r.reveals
			// Advisory until the inert list is empty. Reported on every run so
			// it cannot be forgotten, and not yet in the pass condition,
			// because 66 controls are known inert and a check that starts red
			// over known failures never gets read.
			+ '  controls ' + (r.responded === undefined ? '?' : r.responded + '/' + r.controls)
			+ '  keyboard ' + kb.passed + '/' + kb.menus + (kb.skipped ? ' (' + kb.skipped + ' skipped)' : '')
			+ '  imgs ' + r.imgs + ' (broken ' + r.broken + ')  js ' + r.errors
			+ (r.shown !== r.reveals && (r.pending || []).length ? '\n       unfired: ' + r.pending.join(', ') : '')
			+ ((r.inert || []).length ? '\n       inert: ' + r.inert.join(', ') : '')
			+ (kb.failures.length ? '\n       keyboard: ' + kb.failures.slice(0, 4).join('; ') : '')
			+ (r.first ? '  :: ' + r.first : ''));
	}
	if (bad === 0 && !/^\{/m.test(res.out)) {
		record('behaviour' + suffix, false, 'probe produced nothing:\n' + res.out.trim().slice(-400));
	}
	/*
	 * A page that produced no line is not a page that passed. Counting the
	 * lines against the manifest is what turns "seven pages went missing" into
	 * a failure instead of a smaller denominator.
	 */
	if (reported !== pages.length) {
		record('behaviour' + suffix, false,
			'only ' + reported + ' of ' + pages.length + ' page(s) reported — '
			+ (pages.length - reported) + ' unmeasured');
	}

	/*
	 * And whether the Radix components respond to a click.
	 *
	 * The stage above counts how many controls changed SOMETHING, which is a
	 * useful smoke signal and not an assertion: it cannot tell a tab that
	 * switched from a tab that merely repainted. This drives each component
	 * against its own contract — see bin/radix-interaction.cjs — because
	 * geometry is blind to it: an accordion whose panel never opens has
	 * exactly the boxes of one that works, and measured 0px for the whole
	 * time it was inert.
	 */
	const radix = run(process.execPath, [
		path.join(repo, 'bin', 'radix-interaction.cjs'),
		...pages.map((p) => p.url), '--chrome', chrome,
	]);
	const summary = radix.out.match(/^radix-interaction: (\d+) component\(s\) driven, (\d+) page/m);
	record('radix interaction' + suffix, radix.code === 0 && Boolean(summary),
		summary
			? summary[1] + ' component(s) driven, ' + summary[2] + ' page(s) with a failure'
			: 'the probe produced no summary');
	if (radix.code !== 0 || !summary) {
		console.log(radix.out.trim().split('\n').filter((l) => /"failures":\[".|threw/.test(l)).slice(0, 8)
			.map((l) => '       ' + l.trim()).join('\n'));
	}

	/*
	 * And whether a state the page takes from the scroll position fires.
	 *
	 * The sticky header that turns solid past a threshold: the design writes
	 * `setSolid(window.scrollY > 24)`, the page carries
	 * `data-dxai-scroll="solid:24"`, and until the compiler emitted that marker
	 * the header stayed transparent on ten of the nineteen designs in this
	 * corpus. Geometry cannot see it — its design side is built by this same
	 * compiler, so both sides were equally inert and agreed at 0px — and a
	 * click probe cannot either, because the design binds no click.
	 */
	const scrolls = run(process.execPath, [
		path.join(repo, 'bin', 'scroll-state.cjs'),
		...pages.map((p) => p.url), '--chrome', chrome,
	]);
	const scrollSummary = scrolls.out.match(/^scroll-state: (\d+)\/(\d+) marked/m);
	record('scroll state' + suffix, scrolls.code === 0 && Boolean(scrollSummary),
		scrollSummary
			? scrollSummary[1] + ' of ' + scrollSummary[2] + ' marked element(s) respond to scrolling'
			: 'the probe produced no summary');
	if (scrolls.code !== 0 || !scrollSummary) {
		console.log(scrolls.out.trim().split('\n').filter((l) => /"flipped":false|"errors":[1-9]/.test(l)).slice(0, 8)
			.map((l) => '       ' + l.trim()).join('\n'));
	}
}

/* ------------------------------------------------------- per-design stages --- */

/**
 * Every stage that looks at pages, for one set of them: one design right
 * after its own import, or — under --skip-import — the whole site as it is.
 *
 * @param {Array<object>} pages
 * @param {object} opts imported, label, parts (the design's template parts,
 *                      'theme//slug', round-tripped with its pages)
 * @return {Array<object>} the geometry numbers, per page (stageGeometry()).
 */
function stagesFor(pages, opts) {
	if (!targetsBase && pages[0]) { targetsBase = pages[0]; }
	const suffix = opts.label ? ': ' + opts.label : '';
	const needChrome = wants('chrome') || wants('geometry') || wants('widgets');
	const info = needChrome ? readChrome(pages) : null;
	if (wants('chrome')) {
		console.log('\nchrome (how the header and footer were built)');
	}
	// Whose footer each page shows, for geometry — recorded only when asked for.
	const foreign = stageChrome(info, Object.assign({}, opts, { quiet: !wants('chrome') }));
	const numbers = wants('geometry') ? stageGeometry(pages, foreign) : [];
	if (wants('react') && args.react) { stageReact(pages); }
	if (wants('blocks')) {
		stageBlocks(
			pages.map((p) => ({ label: String(p.id), argv: ['--post', String(p.id)] }))
				.concat((opts.parts || []).map((ref) => ({ label: ref.split('//').pop().slice(0, 34), argv: ['--template-part', ref] }))),
			'blocks' + suffix
		);
	}
	if (wants('widgets')) {
		/*
		 * Right after a design's own import, its footer's widgets — and only
		 * when that import built its footer into Widgets: otherwise the areas
		 * hold whichever design installed one before, which is not this
		 * design's to answer for.
		 */
		const home = info ? info.pages.find((r) => r.conversion) : null;
		const own = home && home.conversion.chrome && home.conversion.chrome.footer && home.conversion.chrome.footer.installed;
		if (opts.imported && !own) {
			console.log('\nfooter widgets (real editor)\n       this design\'s footer is not built into Widgets — nothing of its own to check');
		} else {
			stageWidgets(pages, info, 'footer widgets' + suffix);
		}
	}
	if (wants('behaviour')) { stageBehaviour(pages, suffix); }
	return numbers;
}

/* ------------------------------------------------------------ keyboard --- */

/*
 * Keyboard access to hover menus on synthetic pages (KB_SYNTH_PROBE), with
 * the runtime the plugin ships now — once per run: it depends on no import.
 */
const RUNTIME_PHP = String.raw`<?php
// Written by bin/verify-import.cjs: the runtime a page's script carries.
$js = DXAI_UI\Compiler\Motion_Runtime::javascript();
file_put_contents( (string) ( $args[0] ?? '' ), $js );
echo wp_json_encode( array( 'bytes' => strlen( $js ), 'version' => method_exists( 'DXAI_UI\Compiler\Motion_Runtime', 'version' ) ? DXAI_UI\Compiler\Motion_Runtime::version() : '' ) ), "\n";
`;

function stageKeyboardSynthetic() {
	console.log('\nkeyboard (synthetic hover menus, the runtime as shipped)');
	const file = path.join(work, 'motion-runtime.js');
	const made = lastJson(wp(['eval-file', script('runtime.php', RUNTIME_PHP), file]).out);
	if (!made || !made.bytes || !fs.existsSync(file)) {
		record('keyboard: synthetic menus', false, 'could not write the runtime');
		return;
	}
	const res = run(process.execPath, [script('kb-synth.cjs', KB_SYNTH_PROBE), chrome, file]);
	const out = lastJson(res.out);
	record('keyboard: synthetic menus', Boolean(out && out.total > 0 && out.failures.length === 0),
		out ? out.passed + '/' + out.total + ' (runtime ' + (made.version || '?') + ', ' + made.bytes + ' B)' : 'the probe produced nothing: ' + res.out.trim().slice(-200));
	for (const failure of (out ? out.failures : []).slice(0, 8)) { console.log('       ' + failure); }
}

/* -------------------------------------------------------- chrome install --- */

/** CHROME_CHECK_PHP for one design's pages: {home, pages:[{id, header_part, footer_part}]}. */
function chromeCheck(home, rows) {
	const input = path.join(work, 'chrome-check.json');
	fs.mkdirSync(work, { recursive: true });
	fs.writeFileSync(input, JSON.stringify({ home, pages: rows }));
	const res = wp(['eval-file', script('chrome-check.php', CHROME_CHECK_PHP), input]);
	const info = lastJson(res.out);
	if (!info) {
		console.log(res.out.trim().split('\n').slice(-8).map((l) => '       ' + l).join('\n'));
	}
	return info;
}

/** A page's top-level blocks, as [site-header][skip][14 sections][site-footer]. */
function shape(row) {
	if (!row || !row.top) { return '?'; }
	const top = row.top;
	const name = (b) => {
		if (b.name === 'dxai-ui/site-header') { return 'site-header'; }
		if (b.name === 'dxai-ui/site-footer') { return 'site-footer'; }
		if (b.name === 'core/template-part') { return (b.area || 'part') + ' part'; }
		if (b.name === 'dxai-ui/link' && /^#/.test(b.url || '')) { return 'skip'; }
		return '';
	};
	const head = [];
	let i = 0;
	while (i < top.length && name(top[i]) && head.length < 2 && name(top[i]) !== 'site-footer' && name(top[i]) !== 'footer part') { head.push(name(top[i])); i++; }
	let j = top.length;
	const tail = [];
	if (j > i && /footer/.test(name(top[j - 1]))) { tail.unshift(name(top[j - 1])); j--; }
	return head.concat([(j - i) + ' sections'], tail).map((x) => '[' + x + ']').join('');
}

/**
 * The chrome stage for one archive: re-import it with its header and footer
 * INSTALLED as the site's, and check everything the user's direction says
 * about them (see the top of this file). ctx.kept holds the kept import of
 * each archive (its answer and pages), ctx.geometry their geometry numbers,
 * ctx.installed the designs this stage installed before this one.
 */
function stageChromeInstall(zip, ctx) {
	const name = path.basename(zip);
	const label = designLabel(name).slice(0, 16);
	console.log('\n=== chrome: ' + name + ' — header into Appearance > Menus, footer into Appearance > Widgets');
	let kept = ctx.kept.get(name);
	if (!kept) {
		const was = fingerprint();
		const answer = importOne(zip, 'keep');
		if (!answer) { record('chrome: ' + label, false, 'the kept import it starts from failed'); return; }
		assertKept(zip, answer, was, fingerprint());
		kept = { answer, pages: readManifest().pages.filter((p) => p.source === name) };
		ctx.kept.set(name, kept);
	}
	const home = kept.answer.page_id;
	const rowsFor = (parts) => kept.pages.map((p) => ({ id: p.id, header_part: parts.header || 0, footer_part: parts.footer || 0 }));
	const before = chromeCheck(home, rowsFor(kept.answer.parts));
	const siteBefore = fingerprint();
	const answer = importOne(zip, 'install');
	if (!answer) { return; }
	if (!before) { record('chrome: ' + label, false, 'could not read its pages as the kept import left them'); return; }
	const replaced = Array.isArray(answer.replaced) ? {} : (answer.replaced || {});
	// The parts the blocks were built from: those the install retired, else those it kept.
	const parts = {
		header: replaced.header || answer.parts.header || kept.answer.parts.header,
		footer: replaced.footer || answer.parts.footer || kept.answer.parts.footer,
	};
	const after = chromeCheck(home, rowsFor(parts));
	if (!after) { record('chrome: ' + label, false, 'could not read its pages after the install'); return; }
	const H = answer.chrome.header;
	const F = answer.chrome.footer;
	// Refusals the import is allowed to make: the area then keeps its part.
	const REFUSALS = { header: ['header_unreadable', 'no_header'], footer: ['no_footer'] };

	/* ------ the choice ------ */
	{
		const problems = [];
		if (!answer.mode) { problems.push('save() reports no header/footer choice'); } else if (answer.mode.mode !== 'install') { problems.push('save() chose ' + answer.mode.mode + ' (' + answer.mode.reason + ') where install was asked for'); }
		if (answer.page_id !== home) { problems.push('the install wrote page ' + answer.page_id + ', not the kept import\'s ' + home + ' (not in place)'); }
		for (const [area, a] of [['header', H], ['footer', F]]) {
			if (!a.installed && !REFUSALS[area].includes(a.error)) { problems.push('the ' + area + ' was not installed: ' + (a.error || '?') + (a.message ? ' — ' + a.message.slice(0, 140) : '')); }
		}
		record('chrome: ' + label, problems.length === 0, problems.length ? problems.join('; ')
			: 'header ' + (H.installed ? 'in Menus' : 'kept as its part (' + H.error + ')') + ', footer ' + (F.installed ? 'in Widgets' : 'kept as its part (' + F.error + ')')
				+ (F.handed_back ? '; gave "' + F.handed_back.title + '" its footer back on ' + F.handed_back.pages.length + ' page(s)' : ''));
		if (H.error === 'header_unreadable' && H.message) { console.log('       header refused: ' + H.message.slice(0, 200)); }
	}

	/* ------ page structure: [site-header][skip][sections][site-footer] ------ */
	{
		const problems = [];
		for (const row of after.pages) {
			const was = before.pages.find((r) => r.id === row.id) || {};
			if (!row.exists) { problems.push('page ' + row.id + ' is gone'); continue; }
			const first = row.top[0] || {};
			const last = row.top[row.top.length - 1] || {};
			if (H.installed) {
				if (first.name !== 'dxai-ui/site-header') { problems.push(row.id + ': the first block is ' + (first.name || 'nothing') + ', not dxai-ui/site-header'); }
			} else if (H.error !== 'no_header') {
				if (first.name !== 'core/template-part' || first.status !== 'publish') { problems.push(row.id + ': the refused header is ' + (first.name || 'nothing') + ' (' + (first.status || '') + '), not its published template part'); }
				if (was.top && was.top[0] && first.slug !== was.top[0].slug) { problems.push(row.id + ': the kept header part is ' + first.slug + ', the design\'s was ' + was.top[0].slug); }
			}
			if (F.installed) {
				if (last.name !== 'dxai-ui/site-footer') { problems.push(row.id + ': the last block is ' + (last.name || 'nothing') + ', not dxai-ui/site-footer'); }
			} else if (F.error !== 'no_footer') {
				if (last.name !== 'core/template-part' || last.status !== 'publish') { problems.push(row.id + ': the refused footer is ' + (last.name || 'nothing') + ', not its published template part'); }
			}
			const parts = row.top.filter((b) => b.name === 'core/template-part').length;
			const allowed = (H.installed || H.error === 'no_header' ? 0 : 1) + (F.installed || F.error === 'no_footer' ? 0 : 1);
			if (parts !== allowed) { problems.push(row.id + ': ' + parts + ' template part reference(s) at the top level, ' + allowed + ' expected'); }
			if (row.mid_md5 !== was.mid_md5) { problems.push(row.id + ': the sections changed (' + was.mid_count + ' → ' + row.mid_count + ' blocks, content differs) — only the header and footer may'); }
		}
		const home_row = after.pages.find((r) => r.id === home);
		record('chrome structure: ' + label, problems.length === 0,
			(problems.length ? problems.slice(0, 4).join('; ') : shape(home_row) + ', sections as the kept import wrote them') + (after.pages.length > 1 ? '  [' + after.pages.length + ' pages]' : ''));
	}

	/* ------ the blocks render what the parts rendered ------ */
	for (const [area, a] of [['header', H], ['footer', F]]) {
		if (!a.installed) { continue; }
		const problems = [];
		let nodes = '';
		for (const row of after.pages) {
			const was = before.pages.find((r) => r.id === row.id) || {};
			const r = row[area] || {};
			if (!r.part) { problems.push(row.id + ': no part to compare with'); continue; }
			if (!r.same) {
				problems.push(row.id + ': ' + r.nodes + ' vs ' + r.part_nodes + ' nodes' + (r.first_diff ? '; first difference at node ' + r.first_diff.at + ': block ' + r.first_diff.block + ' | part ' + r.first_diff.part : ''));
			}
			if (was[area] && was[area].part_md5 && r.part_md5 !== was[area].part_md5) { problems.push(row.id + ': the part it was built from is not the ' + area + ' the kept import wrote'); }
			nodes = nodes || (r.nodes + '/' + r.part_nodes + ' nodes, ' + r.bytes + '/' + r.part_bytes + ' B');
		}
		record('chrome ' + area + ' DOM: ' + label, problems.length === 0,
			problems.length ? problems.slice(0, 3).join('; ') : 'the ' + (area === 'header' ? 'Menus' : 'Widgets') + ' ' + area + ' renders the same DOM as its template part (' + nodes + ')');
	}

	/* ------ Appearance > Menus ------ */
	if (H.installed) {
		const problems = [];
		const spec = after.spec;
		let items = 0;
		const where = [];
		if (!spec) {
			problems.push('no header spec for page ' + home);
		} else {
			if (spec.scope !== home) { problems.push('the header spec is scope ' + spec.scope + ', not ' + home); }
			if (!spec.is_site) { problems.push('the installed header is not the site\'s'); }
			const list = Object.entries(spec.menus || {});
			if (!list.length) { problems.push('the header spec holds no menus'); }
			for (const [location, m] of list) {
				if (!m.exists) { problems.push(location + ': menu ' + m.id + ' is gone'); continue; }
				items += m.items;
				where.push(location + ' "' + m.name + '" ' + m.items);
				if (m.marked !== location) { problems.push('"' + m.name + '" is not marked as the ' + location + ' header menu (' + (m.marked || 'unmarked') + ')'); }
				if (after.page_key && m.owner !== after.page_key) { problems.push('"' + m.name + '" belongs to ' + (m.owner || 'nobody') + ', not ' + after.page_key); }
				if (m.invalid.length) { problems.push(m.invalid.length + ' item(s) of "' + m.name + '" point at a page that is gone: ' + m.invalid.slice(0, 3).join(', ')); }
				if (!m.assigned && !(H.kept || []).includes(location)) { problems.push('the ' + location + ' location holds menu ' + (after.locations[location] || 'none') + ', not ' + m.id); }
				if (H.menus && H.menus[location] !== undefined && H.menus[location] !== m.id) { problems.push(location + ': the import wrote menu ' + H.menus[location] + ', the spec reads ' + m.id); }
			}
			if (items === 0) { problems.push('the header menus are empty'); }
		}
		record('chrome menus: ' + label, problems.length === 0, problems.length ? problems.slice(0, 4).join('; ')
			: where.join(', ') + ' item(s); each marked, owned by the design, in its location');
		const primary = spec && spec.menus && spec.menus['primary-navigation'];
		if (primary && primary.top && primary.top.length) { console.log('       primary: ' + primary.top.join(' | ')); }
	}

	/* ------ Appearance > Widgets ------ */
	if (F.installed) {
		const problems = [];
		const foot = after.footer;
		if (!foot) {
			problems.push('no footer stored');
		} else {
			if (foot.scope !== home) { problems.push('the stored footer is "' + foot.title + '" (' + foot.scope + '), not this design\'s ' + home); }
			if (after.page_key && foot.owner && foot.owner !== after.page_key) { problems.push('the stored footer belongs to ' + foot.owner); }
			const areas = Object.keys(foot.areas || {});
			const widgets = Object.values(foot.areas || {}).reduce((n, c) => n + c, 0);
			if (!areas.length) { problems.push('the stored footer has no widget areas'); }
			if (!widgets || !foot.widget_blocks) { problems.push('the footer\'s widget areas are empty'); }
		}
		record('chrome widgets: ' + label, problems.length === 0, problems.length ? problems.join('; ')
			: Object.keys(foot.areas).length + ' area(s), ' + Object.values(foot.areas).reduce((n, c) => n + c, 0) + ' widget(s), ' + foot.widget_blocks + ' block(s); created ' + F.created + ', reused ' + F.reused + (F.inactive ? ', ' + F.inactive + ' to Inactive' : ''));
	}

	/* ------ no style="" in what was saved ------ */
	{
		const counts = [];
		for (const row of after.pages) {
			if (row.content_style) { counts.push(row.id + ' content ' + row.content_style); }
			for (const area of ['header', 'footer']) {
				if (row[area] && row[area].style) { counts.push(row.id + ' ' + area + ' render ' + row[area].style); }
			}
		}
		if (after.spec && H.installed && after.spec.style) { counts.push('header spec ' + after.spec.style); }
		if (after.footer && F.installed && (after.footer.template_style || after.footer.widget_style)) { counts.push('footer frame ' + after.footer.template_style + ', widgets ' + after.footer.widget_style); }
		record('chrome styles: ' + label, counts.length === 0, counts.length ? 'style="" found: ' + counts.join(', ') : '0 style="" in the pages, the header spec, the footer frame and widgets, and their renders');
	}

	/* ------ pages a crawl made for it ------ */
	{
		const problems = [];
		for (const row of after.crawled || []) {
			const first = row.top[0] || {};
			const last = row.top[row.top.length - 1] || {};
			if (H.installed && first.name !== 'dxai-ui/site-header') { problems.push(row.id + ' starts with ' + (first.name || 'nothing')); }
			if (F.installed && last.name !== 'dxai-ui/site-footer') { problems.push(row.id + ' ends with ' + (last.name || 'nothing')); }
		}
		record('chrome crawled: ' + label, problems.length === 0, (after.crawled || []).length
			? (problems.length ? problems.join('; ') : after.crawled.length + ' crawled page(s) carry the same blocks as the home')
			: 'no crawled pages (the pack never crawls: create_missing_pages is off)');
	}

	/* ------ strict geometry, the editor, the footer's widgets, behaviour ------ */
	const pages = readManifest().pages.filter((p) => p.source === name);
	const numbers = stageGeometry(pages, null, {
		strict: true, baseline: ctx.geometry, tag: 'geometry chrome: ',
		title: 'geometry (strict, with the header from Menus and the footer from Widgets)',
	});
	stageBlocks(pages.map((p) => ({ label: String(p.id), argv: ['--post', String(p.id)] }))
		.concat((answer.part_refs || []).map((ref) => ({ label: ref.split('//').pop().slice(0, 34), argv: ['--template-part', ref] }))), 'blocks chrome: ' + label);
	if (F.installed) { stageWidgets(pages, null, 'footer widgets: ' + label); }
	stageBehaviour(pages, ': chrome ' + label);

	/* ------ the designs installed before this one keep their own ------ */
	for (const prev of ctx.installed) {
		const now = chromeCheck(prev.home, prev.rows);
		const problems = [];
		for (const row of now ? now.pages : []) {
			const was = prev.check.pages.find((r) => r.id === row.id) || {};
			for (const area of ['header', 'footer']) {
				if (!prev[area] || !was[area] || !was[area].sig_md5) { continue; }
				const r = row[area] || {};
				if (r.sig_md5 !== was[area].sig_md5) {
					problems.push(row.id + ': its ' + area + ' (' + (r.block || 'none') + ') no longer renders the DOM it had with its own ' + area + ' installed');
				}
			}
		}
		if (!now) { problems.push('could not read its pages'); }
		record('chrome keeps own: ' + prev.label, problems.length === 0, problems.length ? problems.slice(0, 3).join('; ')
			: 'after "' + label + '" took the site\'s, its pages still render their own header and footer ('
				+ (now.pages[0] ? (now.pages[0].header.block || 'none') + ' / ' + (now.pages[0].footer.block || 'none') : '') + ')');
		stageGeometry(prev.pages, null, { strict: true, baseline: prev.numbers, tag: 'geometry kept own: ', title: 'geometry — "' + prev.label + '" after "' + label + '" was installed' });
	}

	/* ------ a second install lands in place ------ */
	if (!args['no-chrome-repeat']) {
		const was = fingerprint();
		const again = importOne(zip, 'install');
		const now = again ? chromeCheck(home, rowsFor({
			header: (Array.isArray(again.replaced) ? {} : again.replaced || {}).header || again.parts.header || parts.header,
			footer: (Array.isArray(again.replaced) ? {} : again.replaced || {}).footer || again.parts.footer || parts.footer,
		})) : null;
		const problems = [];
		if (!again || !now) {
			problems.push('the second install failed');
		} else {
			if (again.page_id !== home) { problems.push('it wrote page ' + again.page_id + ', not ' + home); }
			for (const row of now.pages) {
				const one = after.pages.find((r) => r.id === row.id) || {};
				if (row.content_md5 !== one.content_md5) { problems.push(row.id + ': its content changed'); }
			}
			if (JSON.stringify(now.locations) !== JSON.stringify(after.locations)) { problems.push('the menu locations changed: ' + JSON.stringify(after.locations) + ' → ' + JSON.stringify(now.locations)); }
			if ((now.spec && now.spec.hash) !== (after.spec && after.spec.hash)) { problems.push('the header spec changed'); }
			if (again.chrome.footer.installed && again.chrome.footer.created) { problems.push(again.chrome.footer.created + ' widget(s) created'); }
			const moved = changedKeys(was, fingerprint()).filter((k) => /sidebars|widget_block|nav_menu_locations|classic menus/.test(k));
			if (moved.length) { problems.push('changed: ' + moved.join(', ')); }
		}
		record('chrome re-import: ' + label, problems.length === 0, problems.length ? problems.slice(0, 4).join('; ')
			: 'same page, same content, same menus and locations, widgets reused (' + again.chrome.footer.reused + '), 0 created');
	}
	// The site state the install changed, for the log.
	const touched = changedKeys(siteBefore, fingerprint());
	console.log('       site state the install wrote: ' + (touched.length ? touched.join(', ') : 'nothing'));
	ctx.installed.push({
		label, home, rows: rowsFor(parts), check: after, pages, numbers: Object.fromEntries(numbers.map((n) => [n.id, n])),
		header: H.installed, footer: F.installed,
	});
}

/* ------------------------------------------------------------------ run --- */

const importing = wants('import') && !args['skip-import'];
// The chrome stage imports too: its archives, kept first, then installed.
const chromeZips = (() => {
	if (args['skip-import'] || !wants('chrome')) { return []; }
	const given = args['chrome-zip'].filter((z) => typeof z === 'string');
	if (given.length) { return given; }
	const real = args.zip.filter((z) => !fixtureZips.includes(z));
	return real.length ? [real[real.length - 1]] : [];
})();
const stateFile = path.join(work, 'chrome-before.json');
let saved = false;
let runStart = null;
const ctx = { kept: new Map(), geometry: {}, installed: [] };

if (importing || chromeZips.length) {
	if (!args.zip.length && !chromeZips.length) {
		console.error('--zip is required unless --skip-import is given');
		process.exit(2);
	}
	runStart = fingerprint();
	if (!runStart) {
		record('site state', false, 'could not read the site\'s header/footer state — nothing imported');
		process.exit(1);
	}
	/*
	 * The site's header, footer and brand before anything is imported, so
	 * they can be put back at the end (CHROME_STATE_PHP).
	 */
	if (!args['keep-chrome']) {
		if (fs.existsSync(stateFile)) {
			const age = Math.round((Date.now() - fs.statSync(stateFile).mtimeMs) / 60000);
			console.log('note: ' + stateFile + ' is left from a run that did not finish (' + age + ' min old) — replaced; that run\'s last design may still be installed');
		}
		const took = lastJson(wp(['eval-file', script('chrome-state.php', CHROME_STATE_PHP), 'save', stateFile]).out);
		saved = Boolean(took && took.saved && fs.existsSync(stateFile));
		if (!saved) {
			record('chrome saved', false, 'could not save the site\'s header/footer before importing — nothing imported');
			process.exit(1);
		}
	}
	if (wants('behaviour')) { stageKeyboardSynthetic(); }
	const corpus = importing ? args.zip.slice() : [];
	for (const zip of chromeZips) {
		if (!corpus.some((z) => path.basename(z) === path.basename(zip))) { corpus.push(zip); }
	}
	for (const zip of corpus) {
		const name = path.basename(zip);
		console.log('\n=== ' + name);
		const was = fingerprint();
		const answer = importOne(zip, 'keep');
		if (!answer) { continue; }
		assertKept(zip, answer, was, fingerprint());
		const pages = readManifest().pages.filter((p) => p.source === name);
		if (!pages.length) {
			record('import: ' + designLabel(name), false, 'no published page carries this archive after its import');
			continue;
		}
		ctx.kept.set(name, { answer, pages });
		if (importing) {
			const numbers = stagesFor(pages, { imported: true, label: designLabel(name).slice(0, 14), parts: answer.part_refs });
			for (const n of numbers) { ctx.geometry[n.id] = n; }
		}
	}
	for (const zip of chromeZips) {
		stageChromeInstall(zip, ctx);
	}
} else {
	const manifest = readManifest();
	console.log('\n' + manifest.pages.length + ' page(s), ' + manifest.parts.length + ' template part(s)');
	stagesFor(manifest.pages, { imported: false, label: '' });
	if (wants('behaviour')) { stageKeyboardSynthetic(); }
	/*
	 * Template parts: the site's, once. A header a design could not build in
	 * Menus stays a part, and so does any a theme or a person made.
	 */
	if (wants('blocks')) {
		stageBlocks(manifest.parts.map((s) => ({ label: s.split('//').pop().slice(0, 34), argv: ['--template-part', s] })), 'blocks: template parts');
	}
}

/* ---------------------------------------------------------------- suites --- */

if (wants('suites')) {
	console.log('\nPHP suites');

	/*
	 * The editor's block definitions against the server's. Not one of the
	 * `verify-*.php` files below because it needs both a PHP dump and a Node
	 * run: the editor bundle is executed against a stubbed `wp` so the
	 * comparison is of what each side actually registers, not of two files
	 * agreeing with themselves. An attribute on one side only is an invalid
	 * block the moment somebody opens the editor, and that has cost this
	 * project 33 blocks across 5 pages once already.
	 */
	{
		const dump = path.join(work, 'server-blocks.json');
		fs.mkdirSync(work, { recursive: true });
		const dumped = wp(['eval-file', path.join(repo, 'bin', 'block-parity.php'), dump]);
		if (dumped.code !== 0 || !fs.existsSync(dump)) {
			record('block-parity', false, 'could not dump the server registry');
		} else {
			const res = run(process.execPath, [path.join(repo, 'bin', 'block-parity.cjs'), '--server', dump]);
			const counts = res.out.match(/^server (\d+) block\(s\), editor (\d+) block\(s\)/m);
			record('block-parity', res.code === 0, res.code === 0
				? (counts ? counts[1] + ' server / ' + counts[2] + ' editor blocks agree' : 'in agreement')
				: (res.out.match(/^\d+ disagreement\(s\)/m) || [ 'disagreement' ])[0]);
			if (res.code !== 0) {
				console.log(res.out.trim().split('\n').filter((l) => /—|server:|editor:/.test(l)).slice(0, 12)
					.map((l) => '       ' + l.trim()).join('\n'));
			}
		}
	}

	/*
	 * The admin's numbers, checked without a browser.
	 *
	 * assets/src/admin/fidelity.js is what the wizard's conversion report is
	 * computed from, and it reads the compile result the server returns — so a
	 * change to either side can make the screen report a gap that is not there
	 * or, worse, hide one that is. It runs under node against the real module,
	 * with fixtures for the shapes one import cannot cover.
	 */
	{
		const res = run(process.execPath, [path.join(repo, 'bin', 'fidelity-report.mjs')]);
		const tally = (res.out.match(/^fidelity-report: (.+)$/m) || [ , 'no result' ])[1];
		record('fidelity-report', res.code === 0, tally.slice(0, 52));
		if (res.code !== 0) {
			console.log(res.out.trim().split('\n').filter((l) => /^FAIL/.test(l)).slice(0, 8)
				.map((l) => '       ' + l).join('\n'));
		}
	}

	/*
	 * The same ZIP imported through wp-admin and on the command line has to
	 * produce the same page. This is the check for the class of bug where the
	 * wizard drops a field the repository reads — it ran with the archives this
	 * invocation was given, so it costs one extra compile per ZIP.
	 */
	if (args.zip.length > 0) {
		/*
		 * Capped, and the cap is printed. Each archive here costs a compile and
		 * two saves on top of the import stage's own, so a nine-ZIP run would
		 * spend longer on this one check than on geometry. Four is enough to
		 * cover both oracle kinds; `bash bin/wp-php.sh bin/admin-vs-cli.php
		 * <zips>` runs it over as many as you like.
		 */
		const CAP = 4;
		const picked = args.zip.slice(0, CAP);
		if (args.zip.length > picked.length) {
			console.log('       admin-vs-cli: first ' + picked.length + ' of ' + args.zip.length
				+ ' archives (run bin/admin-vs-cli.php directly for the rest)');
		}
		const res = php([path.join(repo, 'bin', 'admin-vs-cli.php'), ...picked]);
		const tally = (res.out.match(/^admin-vs-cli: (.+)$/m) || [ , 'no result' ])[1];
		record('admin-vs-cli', res.code === 0, tally.slice(0, 52) + ' (' + picked.length + '/' + args.zip.length + ')');
		if (res.code !== 0) {
			console.log(res.out.trim().split('\n').filter((l) => /DIFFERS|FAIL/.test(l)).slice(0, 8)
				.map((l) => '       ' + l).join('\n'));
		}
	}

	for (const file of fs.readdirSync(path.join(repo, 'bin')).sort()) {
		/*
		 * Two naming conventions, and only one of them was picked up.
		 *
		 * The glob matched `smoke-` and `verify-` prefixes, so every check added
		 * with a descriptive name ran only when somebody invoked it by hand — six
		 * of them, covering the component surface, the expression evaluator, the
		 * Tailwind preflight, the container-collapse guards, asset resolution and
		 * countup fidelity. They existed, they passed, and they gated nothing.
		 *
		 * Listed explicitly rather than by a looser glob: bin/ also holds helpers
		 * that dump data or drive a browser instead of asserting something, and
		 * sweeping those in would make the column meaningless.
		 *
		 * These self-load WordPress, like the smoke- checks, so they run directly
		 * rather than through `wp eval-file`.
		 */
		const NAMED_SUITES = [
			'collapse-guards.php',
			'asset-resolution.php',
			'countup-fidelity.php',
			'jsx-expression-parity.php',
			'preflight-parity.php',
			'component-kit.php',
			'legacy-shape.php',
			'class-merge.php',
			'dc-shape.php',
			'zip-router.php',
			'coverage-signals.php',
			'jsx-escapes.php',
		];
		if (!NAMED_SUITES.includes(file) && (!/^(smoke|verify)-.*.php$/.test(file) || file === 'verify-cleanup.php')) { continue; }
		// Dumps a registry for block-parity above rather than asserting
		// anything itself, so it is not a suite.
		if (file === 'block-parity.php') { continue; }
		/*
		 * A suite that reaches a paid model runs only when asked for. Both
		 * smoke-live-restyle.php and verify-site-from-menu.php match the
		 * smoke-/verify- glob, so they joined every pack unannounced: the first
		 * spent a DeepSeek call per run, and the second imports H2O with
		 * create_missing_pages, which crawls the live menu and restyles every
		 * page it finds, overwriting the ones already there. Recognised by
		 * what the file does, not by a list, so the next such check is caught
		 * too; a file can also say so outright with @dxai-spends-model-credit.
		 */
		if (!args['allow-llm']) {
			const body = fs.readFileSync(path.join(repo, 'bin', file), 'utf8');
			if (/@dxai-spends-model-credit|Live_Page_Restyler|\['create_missing_pages'\]\s*=\s*true/.test(body)) {
				console.log('  skip ' + file.replace(/\.php$/, '').padEnd(31) + 'calls a paid model: pass --allow-llm to run it');
				continue;
			}
		}
		const res = file.startsWith('smoke-') || NAMED_SUITES.includes(file)
			? php([path.join(repo, 'bin', file)])
			: wp(['eval-file', path.join(repo, 'bin', file)]);
		const out = res.out.trim();
		/*
		 * A PHP diagnostic counts only when it names a file inside this repo.
		 * Loading wp-admin includes under CLI makes core itself warn — from
		 * class-wp-site-health.php, where no screen exists — and failing on
		 * that would train everyone to ignore the column.
		 */
		const here = repo.replace(/\\/g, '/').toLowerCase();
		const ours = out.split('\n')
			.filter((l) => /\b(Warning|Notice|Deprecated|Fatal error|Uncaught)\b/.test(l))
			// The full repo path, not its folder name: this checkout is called
			// DXAI-UI and so is the Local site it runs against, so matching the
			// name alone blamed us for every core warning.
			.filter((l) => l.replace(/\\/g, '/').toLowerCase().includes(here));
		const bad = res.code !== 0 || /\b(FAIL|fail)\b/.test(out) || /\b(Fatal error|Uncaught)\b/.test(out) || ours.length > 0;
		const foreign = out.split('\n').filter((l) => /\bWarning\b/.test(l)).length - ours.length;
		record(file.replace(/\.php$/, ''), !bad,
			out.split('\n').filter((l) => !/^(Warning|Notice|Deprecated)\b/.test(l)).pop().slice(0, 52)
			+ (foreign > 0 ? '  (+' + foreign + ' core warning' + (foreign > 1 ? 's' : '') + ')' : ''));
		if (bad) {
			console.log(out.split('\n').slice(-8).map((l) => '       ' + l).join('\n'));
		}
	}
}

/* --------------------------------------------------------------- restore --- */

/*
 * The site's header, footer and brand as they were before the run. Last, so
 * everything the run wrote — the imports and the suites' own saves — is
 * behind it.
 */
/*
 * Only what this run left: when the header/footer state is no longer what
 * the run's last import wrote, someone else (a person in wp-admin, another
 * process) changed it during the run, and putting the saved state back would
 * undo their change. It is then left as it is, and the run says so.
 */
const foreignWrite = saved && lastWrite ? changedKeys(lastWrite, fingerprint()) : [];
if (saved && foreignWrite.length) {
	console.log('');
	record('chrome restored', false, 'NOT restored: ' + foreignWrite.join(', ') + ' changed after this run\'s last import, by someone else — left as they are; the state before the run is in ' + stateFile);
} else if (saved) {
	const answer = lastJson(wp(['eval-file', script('chrome-state.php', CHROME_STATE_PHP), 'restore', stateFile]).out);
	const ok = Boolean(answer && answer.same && Array.isArray(answer.restored));
	console.log('');
	record('chrome restored', ok, answer
		? (answer.restored.length ? 'put back: ' + answer.restored.join(', ') : 'nothing to put back')
			+ (answer.inactive ? '; ' + answer.inactive + ' widget(s) the run placed moved to Inactive widgets' : '')
			+ (answer.same ? '' : '  — NOT the saved state after restoring')
		: 'no answer from the restore');
	if (ok) {
		try { fs.unlinkSync(stateFile); } catch (e) { /* ignore */ }
	}
}

/* --------------------------------------------------------------- cleanup --- */

/*
 * A test run (--title-prefix) moves what it wrote to the trash, after the
 * site's header and footer are back (TRASH_PHP): no page older than the run,
 * nothing of another archive, nothing deleted.
 */
if (runStart && prefix && !args['keep-pages']) {
	const input = path.join(work, 'trash-run.json');
	fs.writeFileSync(input, JSON.stringify({ after: runStart.max_post, archives: [...runArchives], ids: [...runWritten] }));
	const answer = lastJson(wp(['eval-file', script('trash-run.php', TRASH_PHP), input]).out);
	const now = answer && answer.trashed ? Object.entries(answer.trashed) : [];
	const failed = now.reduce((n, [, t]) => n + (t.failed ? t.failed.length : 0), 0);
	console.log('');
	record('cleanup', Boolean(answer && answer.trashed) && failed === 0, answer && answer.trashed
		? 'moved to the trash: ' + (now.map(([type, t]) => (t.now ? t.now.length : 0) + ' ' + type + (t.already ? ' (+' + t.already + ' already)' : '')).join(', ') || 'nothing')
			+ (failed ? '; ' + failed + ' could not be trashed' : '')
		: 'no answer: ' + JSON.stringify(answer));
	if (answer && answer.pages) { console.log('       pages: ' + answer.pages.join(', ')); }
}

/* ---------------------------------------------------------------- report --- */

const failures = results.filter((r) => !r.ok);
console.log('\n' + (results.length - failures.length) + '/' + results.length + ' checks passed');
if (failures.length) {
	console.log('failed: ' + failures.map((r) => r.stage).join(', '));
}
process.exit(failures.length ? 1 : 0);

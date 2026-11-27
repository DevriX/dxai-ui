#!/usr/bin/env node
/**
 * Tabs whose panel shows the item a state picks from a list — `const d = DELIVERABLES[active]` and `{d.note}`, with a button per
 * item that calls `setActive(i)` — compiled and pressed in a real browser.
 *
 *   node bin/verify-picked-item.cjs        (DXUI_PHP names php, DXUI_PHP_INI its ini if it needs one, DXUI_CHROME names Chrome)
 *
 * The compiler used to render the panel for the starting item only: the tabs lit up when pressed, the panel under them kept the
 * first item's words (GTM Blueprint's "What you walk away with"). It writes the panel once for each item now, each copy shown for
 * the value of the state that picks it. This compiles small fixtures of that shape (bin/compile-tsx.php), loads the markup with the
 * page script's runtime, and checks what the markup says and what a visitor sees as the tabs are pressed:
 *
 *   - the panel React remounts (`key={active}`), a panel with no key, and a state nothing writes;
 *   - a panel that is a fragment: every root of every copy is marked, so no copy shows beside another;
 *   - what only spells the item's name is not a use of it (`<path d="…">` in a tab's icon, `.map((d) => …)`, "d" in a string),
 *     so the tabs are never copied;
 *   - consts made from the item or the state (`const tag = d.label.toUpperCase()`, a destructured item, a useMemo, a second pick
 *     of the same state, `const num = String(active + 1)`) are computed again for each copy, wherever they are shown;
 *   - ids: an id a copy has to itself is kept (`aria-controls` finds it), one the copies repeat is numbered per copy, and the
 *     labels, links and gradients inside a copy follow it;
 *   - controls inside the panel (a stepper's dots) are written once: the dot pressed stays on screen and keeps the focus;
 *   - what a copy cannot compute again (a `let`), or a use on the element that holds the tabs, leaves the panel as it was.
 *
 * Needs no site and no network; SKIP (exit 77) without PHP or Chrome. Exit code 1 when a check fails.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');
const puppeteer = require('puppeteer-core');

const ROOT = path.resolve(__dirname, '..');
const PHP = process.env.DXUI_PHP || 'php';
const INI = process.env.DXUI_PHP_INI || '';
const CHROME = process.env.DXUI_CHROME || ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe', '/usr/bin/google-chrome', '/usr/bin/chromium', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find((p) => fs.existsSync(p));

const php = spawnSync(PHP, [...(INI ? ['-c', INI] : []), '-r', 'echo PHP_VERSION;'], { encoding: 'utf8' });
if (php.error || php.status !== 0) {
	console.log('SKIP: PHP did not run (' + String(php.error || php.stderr || '').trim().slice(0, 200) + '; set DXUI_PHP)');
	process.exit(77);
}
if (!CHROME) {
	console.log('SKIP: no Chrome found (set DXUI_CHROME)');
	process.exit(77);
}

let pass = 0;
let fail = 0;
const expect = (what, ok, detail = '') => {
	if (ok) {
		pass++;
		console.log('  ok    ' + what);
	} else {
		fail++;
		console.log('  FAIL  ' + what + (detail ? '  — ' + detail : ''));
	}
};

const ITEMS = [
	{ label: 'The Roadmap', note: 'One operating document.', format: 'PDF', artifacts: ['ICP', 'Pricing', 'Channels'] },
	{ label: 'Channel Model', note: 'Demand and channel mix.', format: 'Sheets', artifacts: ['Keywords', 'CAC'] },
	{ label: 'Asset Architecture', note: 'Site IA and paths.', format: 'Figma', artifacts: ['Sitemap', 'Wireframes', 'Content', 'Tracking'] },
	{ label: 'KPI Tree', note: 'Measured before launch.', format: 'GTM', artifacts: ['North star'] },
];
const STAMPS = ['Wk 2', 'Wk 5', 'Wk 8', 'Wk 12'];

// A page with one component: its prelude after the state and the pick, and what it returns.
const page = (prelude, jsx) => `import { useState, useMemo } from "react";

const DELIVERABLES = ${JSON.stringify(ITEMS, null, 2)};
const STAMPS = ${JSON.stringify(STAMPS)};

export function Deliverables() {
  const [active, setActive] = useState(0);
  const d = DELIVERABLES[active];
${prelude}
  return (
${jsx}
  );
}

export default function Page() {
  return (
    <main>
      <Deliverables />
    </main>
  );
}
`;

// The tabs: a button per item that sets active. `icon` goes inside each button.
const tabs = (icon = '', more = '') => `      <aside className="nav" role="tablist" aria-label="Deliverables index">
        {DELIVERABLES.map((item, i) => (
          <button
            key={item.label}
            role="tab"
            aria-selected={active === i}
            className={\`row \${active === i ? "is-active" : ""}\`}
            onClick={() => setActive(i)}${more}
          >
            ${icon}<span className="name">{item.label}</span>
          </button>
        ))}
      </aside>`;

const keyedPanel = `      <article className="doc" key={active}>
        <div className="tab" aria-hidden><span>DOSSIER</span><span>D-{String(active + 1).padStart(2, "0")}</span></div>
        <div className="body">
          <div className="meta"><span>Format</span><b>{d.format}</b></div>
          <h3 className="title">{d.label}</h3>
          <p className="note">{d.note}</p>
          <ul>
            {d.artifacts.map((a, i) => (
              <li key={a}><span className="idx">{String(i + 1).padStart(2, "0")}</span>{a}</li>
            ))}
          </ul>
        </div>
      </article>`;

const FIXTURES = {
	// The panel React remounts per item, which also shows the state itself (`D-{…}`).
	keyed: page('', `    <section className="dossier">\n${tabs()}\n${keyedPanel}\n    </section>`),
	// No key: the panel is the smallest element around every use of the picked item.
	plain: page('', `    <section className="dossier">\n${tabs()}\n      <div className="doc">
        <p className="kicker">Deliverable</p>
        <div className="body">
          <h3 className="title">{d.label}</h3>
          <p className="note">{d.note}</p>
        </div>
      </div>\n    </section>`),
	// Nothing writes the state: only the starting item can ever show.
	still: page('', `    <section className="dossier">\n${tabs().replace('\n            onClick={() => setActive(i)}', '')}\n${keyedPanel}\n    </section>`),
	// The component's root is a fragment around the item and the tabs.
	fragment: page('', `    <>
      <h2 className="title">{d.label}</h2>
${tabs()}
      <p className="note">{d.note}</p>
    </>`),
	// Each tab has an icon with a `d` attribute, a title of "d", and the tabs' callback names its own item `d`.
	icons: page('', `    <section className="dossier">
      <aside className="nav" role="tablist">
        {DELIVERABLES.map((d, i) => (
          <button key={d.label} role="tab" title="d" onClick={() => setActive(i)}>
            <svg viewBox="0 0 10 10"><path d="M0 0L10 10" /></svg><span className="name">{d.label}</span>
          </button>
        ))}
      </aside>
${keyedPanel}
    </section>`),
	// Consts made from the item and the state, one of them shown above the tabs, outside the keyed panel.
	derived: page(`  const tag = d.label.toUpperCase();
  const { note, format: kind } = d;
  const stamp = STAMPS[active];
  const loud = useMemo(() => tag + "!", [tag]);
  const pct = Math.round(((active + 1) / DELIVERABLES.length) * 100);`, `    <section className="dossier">
      <header><h2 className="head">{tag}</h2></header>
${tabs()}
      <article className="doc" key={active}>
        <b className="tag">{tag}</b><p className="note">{note}</p><i className="kind">{kind}</i>
        <s className="stamp">{stamp}</s><u className="loud">{loud}</u><em className="pct">{pct}%</em>
      </article>
    </section>`),
	// An id each copy has to itself, ids every copy repeats, and what points at them.
	ids: page('', `    <section className="dossier">
${tabs('', '\n            aria-controls={`panel-${i}`}')}
      <article className="doc" key={active} id={\`panel-\${active}\`} aria-labelledby="doc-title">
        <h3 id="doc-title" className="title">{d.label}</h3>
        <svg viewBox="0 0 10 10"><defs><linearGradient id="doc-fill"><stop offset="0" /></linearGradient></defs><rect width="10" height="10" fill="url(#doc-fill)" /></svg>
        <label htmlFor="doc-input">{d.note}</label><input id="doc-input" />
        <a href="#doc-title">Top</a>
      </article>
    </section>`),
	// A stepper: the dots that set the state sit inside the panel, beside a number made from the state.
	stepper: page(`  const num = String(active + 1).padStart(2, "0");`, `    <section className="dossier">
      <article className="doc" key={active}>
        <div className="head"><span>DELIVERABLE <b className="num">{num}</b> / 04</span></div>
        <h3 className="title">{d.label}</h3>
        <p className="note">{d.note}</p>
        <div className="dots">
          {DELIVERABLES.map((item, i) => (
            <button key={item.label} type="button" className="dot" onClick={() => setActive(i)} aria-label={\`Show \${item.label}\`}>
              <span>D-{String(i + 1).padStart(2, "0")}</span>
            </button>
          ))}
        </div>
      </article>
    </section>`),
	// A `let` made from the item: code below may assign it, so no copy can compute it again.
	unsafe: page(`  let shout = d.label + "!";`, `    <section className="dossier">\n${tabs()}\n      <article className="doc" key={active}><b className="tag">{shout}</b><p className="note">{d.note}</p></article>\n    </section>`),
	// The item on the element that holds the tabs.
	wrapped: page('', `    <section className={\`dossier \${d.format}\`}>\n${tabs()}\n${keyedPanel}\n    </section>`),
};

const compile = (source) => {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'dxui-picked-'));
	fs.mkdirSync(path.join(dir, 'src/routes'), { recursive: true });
	fs.writeFileSync(path.join(dir, 'src/routes/index.tsx'), source);
	const r = spawnSync(PHP, [...(INI ? ['-c', INI] : []), path.join(ROOT, 'bin/compile-tsx.php'), dir, 'src/routes/index.tsx'], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
	fs.rmSync(dir, { recursive: true, force: true });
	if (r.status !== 0) {
		return { error: (r.stderr || r.stdout || String(r.error || 'php did not run')).slice(0, 400) };
	}
	try {
		const out = JSON.parse(r.stdout);
		out.html = out.sections.map((s) => s.html).join('\n');
		return out;
	} catch (e) {
		return { error: 'not JSON: ' + r.stdout.slice(0, 200) };
	}
};

// The opening tags of the elements whose class list holds `cls`.
const tagsWith = (html, cls) => [...html.matchAll(new RegExp('<[a-z0-9]+\\b[^>]*class="[^"]*\\b' + cls + '\\b[^"]*"[^>]*>', 'g'))].map((m) => m[0]);
// The element whose opening tag carries `class="… <cls> …"`, with its whole content: enough for this markup, which has no nesting of the same tag.
const elements = (html, tag, cls) => [...html.matchAll(new RegExp('<' + tag + '\\b[^>]*class="[^"]*\\b' + cls + '\\b[^"]*"[^>]*>[\\s\\S]*?</' + tag + '>', 'g'))].map((m) => m[0]);
const text = (html) => html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
const marks = (tags) => tags.map((t) => (t.match(/dxui-on-active--(\d+)/) || [])[1] + (/\bhidden\b/.test(t) ? 'h' : '')).join(',');
const SHOWN = '0,1h,2h,3h';

(async () => {
	const out = {};
	for (const [name, source] of Object.entries(FIXTURES)) {
		out[name] = compile(source);
		if (out[name].error) {
			expect(name + ': the fixture compiles', false, out[name].error);
		}
	}
	if (fail) {
		process.exit(1);
	}
	for (const [name, r] of Object.entries(out)) {
		expect(name + ': nothing the compiler could not read', r.unevaluated === 0, String(r.unevaluated));
	}

	console.log('\nA panel React remounts per item (key={active})');
	const html = out.keyed.html;
	const docs = elements(html, 'article', 'doc');
	expect('the panel is on the page once for each item, shown for its own value of active', marks(tagsWith(html, 'doc')) === SHOWN, marks(tagsWith(html, 'doc')));
	const each = docs.map(text);
	expect('each copy holds its own item: title, note and format', ITEMS.every((it, k) => (each[k] || '').includes(it.label) && each[k].includes(it.note) && each[k].includes(it.format)), each.join(' | '));
	expect('…its own number (D-01 … D-04), from the state itself', [1, 2, 3, 4].every((n, k) => (each[k] || '').includes('D-0' + n)), each.map((e) => (e.match(/D-\d+/) || [''])[0]).join(','));
	const lists = docs.map((d) => (d.match(/<li\b/g) || []).length).join(',');
	expect('…and its own list, however long (3, 2, 4, 1 artifacts)', lists === '3,2,4,1', lists);
	const tabTags = html.match(/<button[^>]*>/g) || [];
	expect('the tabs are written once, not once per item', tabTags.length === 4, String(tabTags.length));
	expect('…each one sets active to its own item', tabTags.every((b, i) => new RegExp('dxui-toggle-active--' + i + '\\b').test(b)), tabTags.join(' '));

	console.log('\nA panel with no key: the smallest element around every use of the item');
	const bodies = elements(out.plain.html, 'div', 'body');
	expect('the element around the item is written once per item, the item\'s own words in each', bodies.length === 4 && ITEMS.every((it, k) => text(bodies[k] || '').includes(it.label)), bodies.map(text).join(' | '));
	expect('…and what is the same for every item is not repeated (the kicker, the tabs)', (out.plain.html.match(/class="kicker"/g) || []).length === 1 && (out.plain.html.match(/<button\b/g) || []).length === 4);

	console.log('\nA state nothing writes');
	const stillDocs = elements(out.still.html, 'article', 'doc');
	expect('only the starting item is on the page, as before: nothing could show the others', stillDocs.length === 1 && !/dxui-on-active/.test(out.still.html) && text(stillDocs[0]).includes(ITEMS[0].label), String(stillDocs.length));

	console.log('\nA fragment around the item and the tabs');
	const frag = out.fragment.html;
	expect('the tabs are written once', (frag.match(/<button\b/g) || []).length === 4 && (frag.match(/role="tablist"/g) || []).length === 1);
	expect('the title is written per item, each copy marked and all but the first hidden', marks(tagsWith(frag, 'title')) === SHOWN, marks(tagsWith(frag, 'title')));
	expect('…and so is the note: no root of a copy is left unmarked', marks(tagsWith(frag, 'note')) === SHOWN, marks(tagsWith(frag, 'note')));

	console.log('\nWhat only spells the item\'s name is not a use of it');
	const icons = out.icons.html;
	expect('the tabs, with their <path d="…">, title="d" and their own `d`, are written once', (icons.match(/<button\b/g) || []).length === 4 && (icons.match(/<path\b/g) || []).length === 4, String((icons.match(/<button\b/g) || []).length));
	expect('…and the panel is the keyed article, once per item', marks(tagsWith(icons, 'doc')) === SHOWN, marks(tagsWith(icons, 'doc')));
	expect('…with nothing but the article copied (the section is on the page once)', (icons.match(/<section\b/g) || []).length === 1);

	console.log('\nConsts made from the item or the state, computed for each copy');
	const derived = out.derived.html;
	const field = (cls) => elements(derived, '[a-z0-9]+', cls).map(text);
	expect('const tag = d.label.toUpperCase(): each copy its own', field('tag').join('|') === ITEMS.map((i) => i.label.toUpperCase()).join('|'), field('tag').join('|'));
	expect('const { note, format: kind } = d: each copy its own', field('note').join('|') === ITEMS.map((i) => i.note).join('|') && field('kind').join('|') === ITEMS.map((i) => i.format).join('|'), field('note').join('|') + ' / ' + field('kind').join('|'));
	expect('a second pick of the same state (STAMPS[active]): each copy its own', field('stamp').join('|') === STAMPS.join('|'), field('stamp').join('|'));
	expect('useMemo(() => tag + "!"): each copy its own', field('loud').join('|') === ITEMS.map((i) => i.label.toUpperCase() + '!').join('|'), field('loud').join('|'));
	expect('const pct from the state alone: each copy its own', field('pct').join('|') === '25%|50%|75%|100%', field('pct').join('|'));
	expect('…and {tag} above the tabs, outside the keyed panel, is written per item too', field('head').join('|') === ITEMS.map((i) => i.label.toUpperCase()).join('|'), field('head').join('|'));
	const heads = [...derived.matchAll(/<header\b[^>]*>/g)].map((m) => m[0]);
	expect('…marked on the element around it', marks(heads) === SHOWN, marks(heads));

	console.log('\nIds');
	const ids = out.ids.html;
	const all = [...ids.matchAll(/\sid="([^"]*)"/g)].map((m) => m[1]);
	expect('no id is on the page twice', new Set(all).size === all.length, all.join(','));
	expect('an id each copy has to itself is kept (panel-0 … panel-3)', [0, 1, 2, 3].every((k) => all.includes('panel-' + k)), all.join(','));
	const controls = [...ids.matchAll(/aria-controls="([^"]*)"/g)].map((m) => m[1]);
	expect('…so every tab\'s aria-controls finds its panel', controls.length === 4 && controls.every((c) => all.includes(c)), controls.join(','));
	const copies = elements(ids, 'article', 'doc');
	const resolves = copies.every((c) => {
		const own = new Set([...c.matchAll(/\sid="([^"]*)"/g)].map((m) => m[1]));
		const refs = [...c.matchAll(/\s(?:for|aria-labelledby)="([^"]*)"|href="#([^"]*)"|url\(#([^)]*)\)/g)].map((m) => m[1] || m[2] || m[3]);
		return refs.length === 4 && refs.every((r) => own.has(r));
	});
	expect('the label, the link, aria-labelledby and the gradient in each copy point at that copy\'s own element', resolves && copies.length === 4, copies.map((c) => [...c.matchAll(/\sid="([^"]*)"/g)].map((m) => m[1]).join('+')).join(' | '));
	expect('…and the starting copy keeps the ids the design gave it', /id="doc-title"/.test(copies[0] || '') && /url\(#doc-fill\)/.test(copies[0] || ''), (copies[0] || '').slice(0, 200));

	console.log('\nA stepper: the dots inside the panel');
	const stepper = out.stepper.html;
	expect('the dots are written once', (stepper.match(/<button\b/g) || []).length === 4, String((stepper.match(/<button\b/g) || []).length));
	expect('the title and the note are written per item', marks(tagsWith(stepper, 'title')) === SHOWN && marks(tagsWith(stepper, 'note')) === SHOWN, marks(tagsWith(stepper, 'title')));
	const nums = elements(stepper, 'b', 'num').map(text).join(',');
	expect('…and the number made from the state (DELIVERABLE 01 … 04)', nums === '01,02,03,04', nums);

	console.log('\nWhat leaves the panel as it was');
	expect('a `let` made from the item: one panel, the starting item', !/dxui-on-active/.test(out.unsafe.html) && elements(out.unsafe.html, 'article', 'doc').length === 1);
	expect('the item on the element that holds the tabs: one panel, the starting item', !/dxui-on-active/.test(out.wrapped.html) && elements(out.wrapped.html, 'article', 'doc').length === 1);

	console.log('\nIn a browser');
	const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'shell', args: ['--no-sandbox'] });
	const view = await browser.newPage();
	await view.setViewport({ width: 1200, height: 800 });
	const errors = [];
	view.on('pageerror', (e) => errors.push(String(e)));
	const load = (r) => view.setContent(`<!doctype html><html><head><meta charset="utf-8"><style>.hidden{display:none}</style></head><body><div class="dx-ui">${r.html}</div><script>${r.runtime}</script></body></html>`, { waitUntil: 'load' });
	// On screen: a hidden ancestor hides it too, which its own computed display does not say.
	const visible = (selector) => view.evaluate((s) => [...document.querySelectorAll(s)].filter((el) => el.getClientRects().length > 0).map((el) => el.textContent.replace(/\s+/g, ' ').trim()).join(' | '), selector);
	// Presses button k the way a visitor does: it takes the focus, then the click.
	const press = (selector, k) => view.evaluate((s, i) => { const b = document.querySelectorAll(s)[i]; b.focus(); b.click(); }, selector, k);
	const focusKept = (selector, k) => view.evaluate((s, i) => { const b = document.querySelectorAll(s)[i]; return document.activeElement === b && b.getClientRects().length > 0; }, selector, k);

	await load(out.keyed);
	const shown = () => view.evaluate(() => [...document.querySelectorAll('article.doc')].filter((a) => a.getClientRects().length > 0).map((a) => a.querySelector('.title').textContent.trim() + ' ' + a.querySelector('.tab span:last-child').textContent.trim() + ' ' + a.querySelectorAll('li').length).join(' | '));
	const selected = () => view.evaluate(() => [...document.querySelectorAll('[role="tab"]')].map((b) => b.getAttribute('aria-selected')).join(','));
	expect('keyed, at first: the first item, D-01, its 3 artifacts', (await shown()) === 'The Roadmap D-01 3', await shown());
	await press('[role="tab"]', 2);
	expect('the third tab: its own item, D-03, its 4 artifacts, and nothing else', (await shown()) === 'Asset Architecture D-03 4', await shown());
	expect('…and the third tab is the selected one', (await selected()) === 'false,false,true,false', await selected());
	await press('[role="tab"]', 3);
	expect('the fourth tab: KPI Tree, D-04, 1 artifact', (await shown()) === 'KPI Tree D-04 1', await shown());
	await press('[role="tab"]', 0);
	expect('back to the first', (await shown()) === 'The Roadmap D-01 3', await shown());

	await load(out.fragment);
	expect('fragment, at first: one title and one note, the first item\'s', (await visible('.title, .note')) === 'The Roadmap | One operating document.', await visible('.title, .note'));
	await press('[role="tab"]', 2);
	expect('the third tab: the third item\'s title and note only', (await visible('.title, .note')) === 'Asset Architecture | Site IA and paths.', await visible('.title, .note'));
	expect('…and the tab pressed is on screen and keeps the focus', await focusKept('[role="tab"]', 2));

	await load(out.derived);
	await press('[role="tab"]', 1);
	expect('derived, second tab: every value shown is the second item\'s', (await visible('.head, .tag, .note, .kind, .stamp, .loud, .pct')) === 'CHANNEL MODEL | CHANNEL MODEL | Demand and channel mix. | Sheets | Wk 5 | CHANNEL MODEL! | 50%', await visible('.head, .tag, .note, .kind, .stamp, .loud, .pct'));

	await load(out.stepper);
	await press('.dot', 2);
	expect('stepper, third dot: DELIVERABLE 03 and the third item', (await visible('.num, .title')) === '03 | Asset Architecture', await visible('.num, .title'));
	expect('…and the dot pressed is on screen and keeps the focus', await focusKept('.dot', 2));
	await press('.dot', 3);
	expect('fourth dot: DELIVERABLE 04, KPI Tree', (await visible('.num, .title')) === '04 | KPI Tree', await visible('.num, .title'));
	expect('no script error', errors.length === 0, errors.join(' | '));

	await browser.close();
	console.log(`\n${pass} passed, ${fail} failed`);
	process.exit(fail ? 1 : 0);
})();

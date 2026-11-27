#!/usr/bin/env node
/**
 * Tabs whose panel shows the item a state picks from a list — `const d = DELIVERABLES[active]` and `{d.note}`, with a button per
 * item that calls `setActive(i)` — compiled and pressed in a real browser.
 *
 *   node bin/verify-picked-item.cjs        (DXUI_PHP and DXUI_PHP_INI name php and its ini, as for bin/release.cjs; DXUI_CHROME names Chrome)
 *
 * The compiler used to render the panel for the starting item only: the tabs lit up when pressed, the panel under them kept the
 * first item's words (GTM Blueprint's "What you walk away with"). It writes the panel once for each item now, each copy shown for
 * the value of the state that picks it. This compiles small fixtures of that shape (bin/compile-tsx.php), loads the markup with the
 * page script's runtime, and checks what the markup says and what a visitor sees as the tabs are pressed. Needs no site and no
 * network. Exit code 1 when a check fails.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');
const puppeteer = require('puppeteer-core');

const ROOT = path.resolve(__dirname, '..');
const PHP = process.env.DXUI_PHP || 'C:/Users/DevriX/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe';
const INI = process.env.DXUI_PHP_INI || path.join(ROOT, '.verify/tools/dxui-cli.ini');
const CHROME = process.env.DXUI_CHROME || ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe', '/usr/bin/google-chrome', '/usr/bin/chromium', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find((p) => fs.existsSync(p));

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

/*
 * keyed: the panel is the element React remounts per item (`key={active}`), and it also shows the state itself (`D-{…}`).
 * plain: no key; the panel is the smallest element around every use of the picked item.
 * still: nothing writes the state, so only the starting item can ever show.
 */
const fixture = (shape) => `import { useState } from "react";

const DELIVERABLES = ${JSON.stringify(ITEMS, null, 2)};

export function Deliverables() {
  const [active, setActive] = useState(0);
  const d = DELIVERABLES[active];
  return (
    <section className="dossier">
      <aside className="nav" role="tablist" aria-label="Deliverables index">
        {DELIVERABLES.map((item, i) => (
          <button
            key={item.label}
            role="tab"
            aria-selected={active === i}
            className={\`row \${active === i ? "is-active" : ""}\`}
${shape === 'still' ? '' : '            onClick={() => setActive(i)}\n'}          >
            <span className="name">{item.label}</span>
          </button>
        ))}
      </aside>
${shape !== 'plain'
		? `      <article className="doc" key={active}>
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
      </article>`
		: `      <div className="doc">
        <p className="kicker">Deliverable</p>
        <div className="body">
          <h3 className="title">{d.label}</h3>
          <p className="note">{d.note}</p>
        </div>
      </div>`}
    </section>
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

const compile = (source) => {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'dxui-picked-'));
	fs.mkdirSync(path.join(dir, 'src/routes'), { recursive: true });
	fs.writeFileSync(path.join(dir, 'src/routes/index.tsx'), source);
	const r = spawnSync(PHP, ['-c', INI, path.join(ROOT, 'bin/compile-tsx.php'), dir, 'src/routes/index.tsx'], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
	fs.rmSync(dir, { recursive: true, force: true });
	if (r.status !== 0) {
		return { error: (r.stderr || r.stdout || 'php did not run').slice(0, 400) };
	}
	try {
		return JSON.parse(r.stdout);
	} catch (e) {
		return { error: 'not JSON: ' + r.stdout.slice(0, 200) };
	}
};

// The element whose opening tag carries `class="… <cls> …"`, with its whole content: enough for this markup, which has no nesting of the same tag.
const elements = (html, tag, cls) => [...html.matchAll(new RegExp('<' + tag + '\\b[^>]*class="[^"]*\\b' + cls + '\\b[^"]*"[^>]*>[\\s\\S]*?</' + tag + '>', 'g'))].map((m) => m[0]);
const text = (html) => html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();

(async () => {
	console.log('What the compiler writes: a panel React remounts per item (key={active})');
	const keyed = compile(fixture('keyed'));
	if (keyed.error) {
		expect('the fixture compiles', false, keyed.error);
		process.exit(1);
	}
	const html = keyed.sections.map((s) => s.html).join('\n');
	const docs = elements(html, 'article', 'doc');
	expect('the panel is on the page once for each item', docs.length === 4, String(docs.length));
	const marks = docs.map((d) => ((d.match(/<article[^>]*>/) || [''])[0].match(/dxui-on-active--(\d+)/) || [])[1]).join(',');
	expect('…each shown for the value of active that picks it', marks === '0,1,2,3', marks);
	const hidden = docs.map((d) => /\bhidden\b/.test((d.match(/<article[^>]*>/) || [''])[0]));
	expect('…all but the starting one hidden', hidden.join(',') === 'false,true,true,true', hidden.join(','));
	const each = docs.map(text);
	expect('each copy holds its own item: title, note and format', ITEMS.every((it, k) => (each[k] || '').includes(it.label) && each[k].includes(it.note) && each[k].includes(it.format)), each.join(' | '));
	expect('…its own number (D-01 … D-04), from the state itself', [1, 2, 3, 4].every((n, k) => (each[k] || '').includes('D-0' + n)), each.map((e) => (e.match(/D-\d+/) || [''])[0]).join(','));
	const lists = docs.map((d) => (d.match(/<li\b/g) || []).length).join(',');
	expect('…and its own list, however long (3, 2, 4, 1 artifacts)', lists === '3,2,4,1', lists);
	const tabs = html.match(/<button[^>]*>/g) || [];
	expect('the tabs are written once, not once per item', tabs.length === 4, String(tabs.length));
	expect('…each one sets active to its own item', tabs.every((b, i) => new RegExp('dxui-toggle-active--' + i + '\\b').test(b)), tabs.join(' '));
	expect('nothing the compiler could not read', keyed.unevaluated === 0, String(keyed.unevaluated));

	console.log('\nA panel with no key: the smallest element around every use of the item');
	const plain = compile(fixture('plain'));
	const plainHtml = plain.error ? '' : plain.sections.map((s) => s.html).join('\n');
	const bodies = elements(plainHtml, 'div', 'body');
	expect('the element around the item is written once per item, the item\'s own words in each', !plain.error && bodies.length === 4 && ITEMS.every((it, k) => text(bodies[k] || '').includes(it.label)), plain.error || bodies.map(text).join(' | '));
	expect('…and what is the same for every item is not repeated (the kicker, the tabs)', (plainHtml.match(/class="kicker"/g) || []).length === 1 && (plainHtml.match(/<button\b/g) || []).length === 4);

	console.log('\nA state nothing writes');
	const still = compile(fixture('still'));
	const stillHtml = still.error ? '' : still.sections.map((s) => s.html).join('\n');
	const stillDocs = elements(stillHtml, 'article', 'doc');
	expect('only the starting item is on the page, as before: nothing could show the others', !still.error && stillDocs.length === 1 && !/dxui-on-active/.test(stillHtml) && text(stillDocs[0]).includes(ITEMS[0].label), still.error || String(stillDocs.length));

	if (!CHROME) {
		console.error('no Chrome found (set DXUI_CHROME)');
		process.exit(2);
	}
	console.log('\nIn a browser');
	const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'shell', args: ['--no-sandbox'] });
	const page = await browser.newPage();
	await page.setViewport({ width: 1200, height: 800 });
	const errors = [];
	page.on('pageerror', (e) => errors.push(String(e)));
	await page.setContent(
		`<!doctype html><html><head><meta charset="utf-8"><style>.hidden{display:none}</style></head><body><div class="dx-ui">${html}</div><script>${keyed.runtime}</script></body></html>`,
		{ waitUntil: 'load' }
	);
	const shown = () => page.evaluate(() => [...document.querySelectorAll('article.doc')].filter((a) => getComputedStyle(a).display !== 'none').map((a) => a.querySelector('.title').textContent.trim() + ' ' + a.querySelector('.tab span:last-child').textContent.trim() + ' ' + a.querySelectorAll('li').length).join(' | '));
	const selected = () => page.evaluate(() => [...document.querySelectorAll('[role="tab"]')].map((b) => b.getAttribute('aria-selected')).join(','));
	const press = (i) => page.evaluate((k) => document.querySelectorAll('[role="tab"]')[k].click(), i);
	expect('at first: the first item, D-01, its 3 artifacts', (await shown()) === 'The Roadmap D-01 3', await shown());
	await press(2);
	expect('the third tab: its own item, D-03, its 4 artifacts, and nothing else', (await shown()) === 'Asset Architecture D-03 4', await shown());
	expect('…and the third tab is the selected one', (await selected()) === 'false,false,true,false', await selected());
	await press(3);
	expect('the fourth tab: KPI Tree, D-04, 1 artifact', (await shown()) === 'KPI Tree D-04 1', await shown());
	await press(0);
	expect('back to the first', (await shown()) === 'The Roadmap D-01 3', await shown());
	expect('no script error', errors.length === 0, errors.join(' | '));

	await browser.close();
	console.log(`\n${pass} passed, ${fail} failed`);
	process.exit(fail ? 1 : 0);
})();

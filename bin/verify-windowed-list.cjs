#!/usr/bin/env node
/**
 * A list a design shows through a window — a testimonials slider written as `quotes.slice(start, start + 2)` with arrows that move
 * `start` — compiled and pressed in a real browser.
 *
 *   node bin/verify-windowed-list.cjs        (DXAI_PHP and DXAI_PHP_INI name php and its ini, as for bin/release.cjs; DXAI_CHROME names Chrome)
 *
 * The compiler used to render the starting window only: two of the nine cards on the page and arrows that did nothing (the one
 * that goes back was disabled for good). It keeps every card now, each one shown for the values of the state that put it in the window,
 * and the arrows step the state to the ends of the list. This compiles a small fixture of that shape (bin/compile-tsx.php), loads the
 * markup with the page script's runtime, and checks what the markup says and what a visitor sees as the arrows are pressed. Needs no site
 * and no network. Exit code 1 when a check fails.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');
const puppeteer = require('puppeteer-core');

const ROOT = path.resolve(__dirname, '..');
const PHP = process.env.DXAI_PHP || 'C:/Users/DevriX/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe';
const INI = process.env.DXAI_PHP_INI || path.join(ROOT, '.verify/tools/dxai-cli.ini');
const CHROME = process.env.DXAI_CHROME || ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe', '/usr/bin/google-chrome', '/usr/bin/chromium', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find((p) => fs.existsSync(p));

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

const names = ['Ann', 'Ben', 'Cy', 'Di', 'Ed', 'Flo', 'Gus', 'Hal', 'Ivy'];
const fixture = (buttons = true) => `import { useState } from "react";

const quotes = [
${names.map((n) => `  { quote: "${n} says hello", name: "${n}" },`).join('\n')}
];

const PER_VIEW = 2;

export function Testimonials() {
  const [start, setStart] = useState(0);
  const maxStart = Math.max(0, quotes.length - PER_VIEW);
  const visible = quotes.slice(start, start + PER_VIEW);

  const move = (dir: number) =>
    setStart((s) => Math.min(maxStart, Math.max(0, s + dir)));

  return (
    <section className="py-8">
      <div className="grid gap-6 md:grid-cols-2">
        {visible.map((q) => (
          <figure key={q.name} className="flex flex-col p-4">
            <blockquote>{q.quote}</blockquote>
            <figcaption>{q.name}</figcaption>
          </figure>
        ))}
      </div>
${buttons ? `      <button type="button" aria-label="Previous testimonials" disabled={start === 0} onClick={() => move(-PER_VIEW)} className="disabled:opacity-25">Prev</button>
      <button type="button" aria-label="Next testimonials" disabled={start >= maxStart} onClick={() => move(PER_VIEW)} className="disabled:opacity-25">Next</button>` : ''}
    </section>
  );
}

export default function Page() {
  return (
    <main>
      <Testimonials />
    </main>
  );
}
`;

const compile = (source) => {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'dxai-window-'));
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

(async () => {
	console.log('What the compiler writes');
	const out = compile(fixture());
	if (out.error) {
		expect('the fixture compiles', false, out.error);
		process.exit(1);
	}
	const html = out.sections.map((s) => s.html).join('\n');
	const figures = html.match(/<figure[^>]*>/g) || [];
	expect('every card is on the page, not the starting window only', figures.length === 9, String(figures.length));
	const hidden = figures.filter((f) => /\bhidden\b/.test(f)).length;
	expect('…the ones outside the starting window hidden: 7 of 9', hidden === 7, String(hidden));
	const marks = (i) => (figures[i].match(/dxai-on-start--(\d+)/g) || []).map((m) => m.replace('dxai-on-start--', '')).join(',');
	expect('each card is shown for the values of start that put it in the window (card 4: 2 and 3)', marks(0) === '0' && marks(1) === '0,1' && marks(3) === '2,3' && marks(8) === '7', [0, 1, 3, 8].map(marks).join(' | '));
	const buttons = html.match(/<button[^>]*>/g) || [];
	const prev = buttons.find((b) => /Previous/.test(b)) || '';
	const next = buttons.find((b) => /Next/.test(b)) || '';
	expect('the arrow that goes back steps start by -2 and stops at the ends', /data-dxai-step="-2"/.test(prev) && /data-dxai-clamp/.test(prev) && /dxai-toggle-start\b/.test(prev), prev);
	expect('the arrow that goes on steps it by 2', /data-dxai-step="2"/.test(next) && /data-dxai-clamp/.test(next) && /data-dxai-init="0"/.test(next), next);
	expect('the arrows are off where the design had them off: back at 0, on at 7 (the last window)', /data-dxai-disabled="start:0"/.test(prev) && /data-dxai-disabled="start:7"/.test(next), prev + ' | ' + next);
	expect('…and the one that goes back is disabled at the start, the other is not', /\sdisabled[\s>]/.test(prev) && !/\sdisabled[\s>]/.test(next));
	expect('an arrow that opens nothing does not say it is collapsed (no aria-expanded)', !/aria-expanded/.test(prev) && !/aria-expanded/.test(next));
	expect('nothing the compiler could not read', out.unevaluated === 0, String(out.unevaluated));

	console.log('\nA window that nothing slides (no button writes the state)');
	const still = compile(fixture(false));
	const stillFigures = still.error ? [] : (still.sections.map((s) => s.html).join('').match(/<figure[^>]*>/g) || []);
	expect('only the cards of the window are on the page, as before: nothing could show the others', !still.error && stillFigures.length === 2 && stillFigures.every((f) => !/dxai-on-/.test(f)), still.error || String(stillFigures.length));

	if (!CHROME) {
		console.error('no Chrome found (set DXAI_CHROME)');
		process.exit(2);
	}
	console.log('\nIn a browser');
	const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'shell', args: ['--no-sandbox'] });
	const page = await browser.newPage();
	await page.setViewport({ width: 1200, height: 800 });
	const errors = [];
	page.on('pageerror', (e) => errors.push(String(e)));
	await page.setContent(
		`<!doctype html><html><head><meta charset="utf-8"><style>.flex{display:flex}.grid{display:grid}.hidden{display:none}button:disabled{opacity:.25}.md\\:grid-cols-2{grid-template-columns:1fr 1fr}</style></head><body><div class="dxai-ui">${html}</div><script>${out.runtime}</script></body></html>`,
		{ waitUntil: 'load' }
	);
	const shown = () => page.evaluate(() => [...document.querySelectorAll('figure')].filter((f) => getComputedStyle(f).display !== 'none').map((f) => f.textContent.trim().split(/\s+/)[0] === '' ? '' : f.querySelector('figcaption').textContent.trim()).join(','));
	const state = () => page.evaluate(() => ({ prev: document.querySelector('[aria-label="Previous testimonials"]').disabled, next: document.querySelector('[aria-label="Next testimonials"]').disabled }));
	const press = async (label) => {
		await page.click('[aria-label="' + label + '"]');
	};
	expect('at first: the first two cards, back disabled', (await shown()) === 'Ann,Ben' && (await state()).prev === true && (await state()).next === false, await shown());
	await press('Next testimonials');
	expect('next: the next two (Cy, Di)', (await shown()) === 'Cy,Di', await shown());
	expect('…and back is on', (await state()).prev === false);
	await press('Next testimonials');
	await press('Next testimonials');
	expect('…two more times: Gus, Hal (start 6)', (await shown()) === 'Gus,Hal', await shown());
	await press('Next testimonials');
	expect('the last press stops at the end: the last two cards (Hal, Ivy), start 7', (await shown()) === 'Hal,Ivy', await shown());
	expect('…where next is disabled, as the design had it', (await state()).next === true && (await state()).prev === false, JSON.stringify(await state()));
	await press('Previous testimonials');
	expect('back from the end goes by two: start 5 (Flo, Gus)', (await shown()) === 'Flo,Gus', await shown());
	await press('Previous testimonials');
	await press('Previous testimonials');
	await press('Previous testimonials');
	expect('…and on to the start (Ann, Ben)', (await shown()) === 'Ann,Ben', await shown());
	expect('…where back is disabled again', (await state()).prev === true && (await state()).next === false, JSON.stringify(await state()));
	expect('no script error', errors.length === 0, errors.join(' | '));

	// A page of the theme that holds sections copied from a design has a scope element around each run of them; the runtime used to
	// read the first one only, so a slider in the second never worked. A scope inside another is part of it and is not read twice.
	console.log('\nOn a page with several design scopes (a block of the page\'s own between two runs of sections)');
	const css = '<style>.flex{display:flex}.grid{display:grid}.hidden{display:none}button:disabled{opacity:.25}</style>';
	await page.setContent(`<!doctype html><html><head><meta charset="utf-8">${css}</head><body>
<div class="dxai-ui" id="one"><p>the first run</p></div><p>a paragraph of the page</p>
<div class="dxai-ui" id="two">${html}</div>
<div class="dxai-ui" id="three"><div class="dxai-ui" id="inner">${html}</div></div>
<script>${out.runtime}</script></body></html>`, { waitUntil: 'load' });
	const marked = await page.evaluate(() => [...document.querySelectorAll('.dxai-ui')].map((s) => s.id + ':' + s.classList.contains('dxai-motion')).join(' '));
	expect('every scope that is not inside another is armed (the nested one is part of its parent)', marked === 'one:true two:true three:true inner:false', marked);
	const second = (sel) => page.evaluate((s) => [...document.querySelectorAll(s + ' figure')].filter((f) => getComputedStyle(f).display !== 'none').map((f) => f.querySelector('figcaption').textContent.trim()).join(','), sel);
	await page.click('#two [aria-label="Next testimonials"]');
	expect('a slider in the second scope works: next shows the next two', (await second('#two')) === 'Cy,Di', await second('#two'));
	expect('…and does not move the one in the third', (await second('#three')) === 'Ann,Ben', await second('#three'));
	await page.click('#inner [aria-label="Next testimonials"]');
	expect('one inside a nested scope moves once, not once for each scope around it', (await second('#inner')) === 'Cy,Di', await second('#inner'));
	expect('no script error', errors.length === 0, errors.join(' | '));

	await browser.close();
	console.log(`\n${pass} passed, ${fail} failed`);
	process.exit(fail ? 1 : 0);
})();

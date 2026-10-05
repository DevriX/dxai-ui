#!/usr/bin/env node
/**
 * The previous / next buttons of a slider that a design draws as a row which scrolls sideways (assets/js/rail.js).
 *
 *   node bin/verify-rail.cjs        (DXAI_PHP and DXAI_PHP_INI name php and its ini, as for bin/release.cjs; DXAI_CHROME names Chrome)
 *
 * A design's own script moved such a row; the plugin's page script carries a design's state, not what its code does to the DOM, so the
 * buttons turned up on the page and did nothing. This checks the two halves of what replaces it: Assets::has_rail() decides from a page's
 * markup whether the script is sent (a rail and a previous or next control, and nothing else), and the script, in a real browser,
 * moves the row by a card, stops at its ends, disables the button the design drew as disabled there, and leaves alone a link to another
 * page, a button of a form and a button the design's own script drives. Needs no site and no network. Exit code 1 when a check fails.
 */
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const puppeteer = require('puppeteer-core');

const ROOT = path.resolve(__dirname, '..');
const PHP = process.env.DXAI_PHP || 'C:/Users/DevriX/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe';
const INI = process.env.DXAI_PHP_INI || path.join(ROOT, '.verify/tools/dxai-cli.ini');
const CHROME = process.env.DXAI_CHROME || ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe', '/usr/bin/google-chrome', '/usr/bin/chromium', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find((p) => fs.existsSync(p));
const JS = path.join(ROOT, 'assets/js/rail.js');

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

// ---- Which pages get the script -------------------------------------------------------------------------------------------------
const hasRail = (markup) => {
	const code = [
		"define('DXAI_UI_DIR', " + JSON.stringify(ROOT + '/') + ');',
		"require DXAI_UI_DIR . 'src/Autoloader.php';",
		'DXAI_UI\\Autoloader::register();',
		'echo DXAI_UI\\Blocks\\Assets::has_rail($argv[1]) ? "yes" : "no";',
	].join('');
	const r = spawnSync(PHP, ['-c', INI, '-r', code, '--', markup], { encoding: 'utf8' });
	return r.status === 0 ? r.stdout === 'yes' : null;
};
console.log('Which pages get the script');
const rail = '<div class="flex gap-4 overflow-x-auto snap-x snap-mandatory" data-track><div>a</div><div>b</div></div>';
const cases = [
	['a row that scrolls sideways and a "Next reviews" button', rail + '<button aria-label="Next reviews">→</button>', true],
	['…a previous one too, the label in any case', rail + '<button aria-label="Previous testimonials"></button>', true],
	['…the row said in its own CSS (overflow-x:auto, scroll-snap)', '<div style="overflow-x:auto;scroll-snap-type:x mandatory">a</div><button aria-label="next">→</button>', true],
	['a row and no control', rail, false],
	['a control and no row (a pager: "Next page")', '<a aria-label="Next page" href="/page/2">Next</a>', false],
	['a plain page', '<p>Hello</p><button aria-label="Close">x</button>', false],
];
for (const [what, markup, want] of cases) {
	const got = hasRail(markup);
	expect(what + ': ' + (want ? 'sent' : 'not sent'), got === want, got === null ? 'php did not run' : 'got ' + got);
}

// ---- What the script does ---------------------------------------------------------------------------------------------------------
const PAGE = `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
body{margin:0;font:16px sans-serif}
.track{display:flex;gap:16px;width:700px;overflow-x:auto;scroll-snap-type:x mandatory}
.card{flex:0 0 300px;height:120px;background:#ddd;scroll-snap-align:start}
button:disabled{opacity:.4}
</style></head><body>
<section id="rail">
<div class="track" data-track><div class="card">1</div><div class="card">2</div><div class="card">3</div><div class="card">4</div><div class="card">5</div><div class="card">6</div></div>
<button id="prev" class="disabled:opacity-40" aria-label="Previous reviews">←</button>
<button id="next" class="disabled:opacity-40" aria-label="Next reviews">→</button>
</section>
<section id="plain">
<div class="track"><div class="card">1</div><div class="card">2</div><div class="card">3</div></div>
<button id="own" aria-label="Next reviews" data-dxai-step="x">→</button>
<form><button id="inform" type="button" aria-label="Next reviews">→</button></form>
<a id="pager" href="#after" aria-label="Next page">Next page</a>
<button id="stepper" aria-label="Next step">Next step</button>
<button id="submit" type="submit" aria-label="Next reviews">→</button>
</section>
<section id="norail"><div><button id="lonely" aria-label="Next">Next</button></div></section>
<div id="after"></div>
</body></html>`;

(async () => {
	if (!CHROME) {
		console.error('no Chrome found (set DXAI_CHROME)');
		process.exit(2);
	}
	const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'shell', args: ['--no-sandbox'] });
	const page = await browser.newPage();
	await page.setViewport({ width: 900, height: 700 });
	// A press moves the row at once instead of smoothly, so the checks do not wait for an animation.
	await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'reduce' }]);
	const errors = [];
	page.on('pageerror', (e) => errors.push(String(e)));
	await page.setContent(PAGE, { waitUntil: 'load' });
	await page.addScriptTag({ path: JS });
	// The script arms the buttons on `load`; the page has loaded already, so wake it as the browser would have.
	await page.evaluate(() => window.dispatchEvent(new Event('load')));
	const wait = (ms = 120) => new Promise((r) => setTimeout(r, ms));
	await wait();
	const at = () => page.evaluate(() => Math.round(document.querySelector('#rail .track').scrollLeft));
	const state = () => page.evaluate(() => ({ prev: document.getElementById('prev').disabled, next: document.getElementById('next').disabled }));
	const press = async (id) => {
		await page.click('#' + id);
		await wait();
	};

	console.log('\nA row of six cards, 300px each with 16px between, in 700px');
	const max = await page.evaluate(() => { const t = document.querySelector('#rail .track'); return t.scrollWidth - t.clientWidth; });
	expect('it scrolls: 1180px at most', max === 1180, String(max));
	let s = await state();
	expect('at the start the previous button is disabled and the next one is not (the design\'s own look)', s.prev === true && s.next === false, JSON.stringify(s));
	await press('next');
	expect('next moves the row by a card', (await at()) === 316, String(await at()));
	s = await state();
	expect('…and the previous button is enabled', s.prev === false && s.next === false, JSON.stringify(s));
	await press('next');
	await press('next');
	expect('…card by card', (await at()) === 948, String(await at()));
	await press('next');
	expect('the last press goes as far as the row can', (await at()) === 1180, String(await at()));
	s = await state();
	expect('…and the next button is disabled at the end', s.next === true && s.prev === false, JSON.stringify(s));
	await press('prev');
	expect('previous goes back to the card before the one at the edge', (await at()) === 948, String(await at()));
	await press('prev');
	await press('prev');
	await press('prev');
	expect('…to the start', (await at()) === 0, String(await at()));
	s = await state();
	expect('…where previous is disabled again', s.prev === true && s.next === false, JSON.stringify(s));

	console.log('\nWhat is left alone');
	await page.evaluate(() => { window.__pressed = []; document.addEventListener('click', (e) => window.__pressed.push([e.target.id, e.defaultPrevented]), false); });
	for (const id of ['own', 'inform', 'stepper', 'submit']) {
		await page.evaluate((i) => document.getElementById(i).click(), id);
	}
	await wait();
	const plainAt = await page.evaluate(() => Math.round(document.querySelector('#plain .track').scrollLeft));
	expect('a button the design\'s own script drives (data-dxai-*), a button of a form, a submit button and "Next step" move nothing', plainAt === 0, String(plainAt));
	await page.evaluate(() => document.getElementById('pager').click());
	const pager = await page.evaluate(() => window.__pressed.filter((p) => p[0] === 'pager').map((p) => p[1]));
	expect('a link to another place is not taken over (its click is not cancelled)', pager.length === 1 && pager[0] === false, JSON.stringify(pager));
	await page.evaluate(() => document.getElementById('lonely').click());
	await wait();
	expect('a "Next" button with no row around it does nothing, and nothing throws', errors.length === 0, errors.join(' | '));

	console.log('\nReloaded');
	await page.evaluate(() => { document.getElementById('next').click(); });
	await wait();
	await page.evaluate(() => window.dispatchEvent(new Event('load')));
	await wait();
	const armed = await page.evaluate(() => document.querySelectorAll('[data-rail-armed]').length);
	expect('a second wake (the load event again) arms nothing twice and does not move the row', (await at()) === 316 && armed === 2, 'at ' + (await at()) + ', armed ' + armed);

	await browser.close();
	console.log(`\n${pass} passed, ${fail} failed`);
	process.exit(fail ? 1 : 0);
})();

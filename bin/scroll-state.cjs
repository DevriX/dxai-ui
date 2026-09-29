/**
 * Does a scroll-driven state actually fire on the page?
 *
 * The design's sticky header turns solid once the page is scrolled past a
 * threshold — `setSolid(window.scrollY > 24)` in the source, a
 * `data-dxai-scroll="solid:24"` marker on the page, and Motion_Runtime
 * watching. Every part of that can be present and the header still never
 * change, which is exactly what shipped for months: the geometry oracle
 * compiles its design side with the same compiler, so both sides were equally
 * inert and agreed at 0px.
 *
 * So this scrolls a real browser and reads the computed style before and after.
 * A page with no scroll marker is reported and skipped, not failed — most
 * designs have none. A page that carries one and does not change is a failure.
 *
 * usage: node bin/scroll-state.cjs <url>… [--chrome <path>]
 *
 * @package DXAI_UI
 */

const path = require('path');
// puppeteer-core from this repo's own node_modules, like the other probes:
// the package is not installed globally and Chrome comes from --chrome.
const puppeteer = require(path.join(__dirname, '..', 'node_modules', 'puppeteer-core'));

const arg = (name, fallback) => {
	const i = process.argv.indexOf('--' + name);
	return i > -1 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
};

/**
 * What the marked element looks like right now.
 *
 * Background, border and backdrop filter, because those are what a header's
 * solid state is made of, plus the class list so a failure says which arm the
 * element is wearing.
 */
const READ = (selector) => {
	const el = document.querySelector(selector);
	if (!el) { return null; }
	const cs = getComputedStyle(el);
	return {
		cls: String(el.className || ''),
		bg: cs.backgroundColor,
		border: cs.borderBottomColor + '|' + cs.borderTopColor,
		blur: cs.backdropFilter,
		shadow: cs.boxShadow,
		opacity: cs.opacity,
		transform: cs.transform,
	};
};

/*
 * The same appearance, not the same string. The runtime toggles classes, so one
 * removed and added back lands at the end of the list: identical set, different
 * order. Comparing the strings reported a header that had restored perfectly as
 * having failed to. A class attribute is space-separated, so a plain split on
 * the space is all this needs.
 */
const classSet = (row) => String(row.cls || '').split(' ').filter(Boolean).sort().join(' ');
const same = (a, b) => Boolean(a) && Boolean(b) && a.bg === b.bg && a.border === b.border && a.blur === b.blur
	&& a.shadow === b.shadow && a.opacity === b.opacity && a.transform === b.transform
	&& classSet(a) === classSet(b);

(async () => {
	const chrome = arg('chrome', 'C:/Program Files/Google/Chrome/Application/chrome.exe');
	const urls = process.argv.slice(2).filter((a) => a.startsWith('http'));
	if (!urls.length) {
		console.error('usage: node bin/scroll-state.cjs <url>… [--chrome <path>]');
		process.exit(2);
	}

	const browser = await puppeteer.launch({ executablePath: chrome, headless: 'shell', args: ['--no-sandbox'] });
	let checked = 0;
	let bad = 0;

	for (const url of urls) {
		const page = await browser.newPage();
		await page.setViewport({ width: 1280, height: 900 });
		const errors = [];
		page.on('pageerror', (e) => errors.push(String(e).slice(0, 80)));
		await page.goto(url, { waitUntil: 'load', timeout: 60000 });
		await new Promise((r) => setTimeout(r, 1200));

		const slug = url.replace(/\/$/, '').split('/').pop();
		const specs = await page.evaluate(() => [...document.querySelectorAll('[data-dxai-scroll]')].map((el, i) => ({
			spec: el.getAttribute('data-dxai-scroll'),
			any: el.getAttribute('data-dxai-any'),
			// A selector this script can hand back to the page.
			selector: '[data-dxai-scroll]:nth-of-type(' + (i + 1) + ')',
			tag: el.tagName,
		})));

		if (!specs.length) {
			console.log(JSON.stringify({ slug, marked: 0, note: 'no scroll state on this page' }));
			await page.close();
			continue;
		}

		for (const spec of specs) {
			++checked;
			const selector = '[data-dxai-scroll="' + spec.spec.replace(/"/g, '') + '"]';
			const threshold = parseFloat((spec.spec.split(':')[1] || '0')) || 0;

			const atTop = await page.evaluate(READ, selector);
			// Past the threshold, then well past it, because a design can key
			// on a later point than the one it declares.
			await page.evaluate((y) => window.scrollTo(0, y), threshold + 40);
			await new Promise((r) => setTimeout(r, 600));
			const past = await page.evaluate(READ, selector);
			await page.evaluate(() => window.scrollTo(0, 0));
			// A colour transition (duration-500 here) is still running at 600ms, so the
			// restored read waits it out rather than catching a midpoint.
			await new Promise((r) => setTimeout(r, 1400));
			const back = await page.evaluate(READ, selector);
			const scrolled = await page.evaluate(() => window.scrollY);

			const flipped = !same(atTop, past);
			const restored = same(atTop, back);
			if (!flipped) { ++bad; }

			console.log(JSON.stringify({
				slug,
				tag: spec.tag,
				spec: spec.spec,
				any: spec.any,
				flipped,
				restored,
				scrollYreached: scrolled,
				atTop: atTop && { bg: atTop.bg, blur: atTop.blur },
				past: past && { bg: past.bg, blur: past.blur },
				back: back && { bg: back.bg, blur: back.blur },
				diff: (function(){ if(!atTop||!back) return "missing"; const keys=["bg","border","blur","shadow","opacity","transform"]; const out=keys.filter(function(k){return atTop[k]!==back[k];}); if(classSet(atTop)!==classSet(back)){ out.push("cls:" + classSet(atTop) + " != " + classSet(back)); } return out; })(),
				errors: errors.length,
			}));
		}

		await page.close();
	}

	await browser.close();
	console.log('scroll-state: ' + (checked - bad) + '/' + checked + ' marked element(s) respond to scrolling');
	process.exit(bad ? 1 : 0);
})();

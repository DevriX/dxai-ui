#!/usr/bin/env node
/**
 * Does a click do the same thing on the converted page as on the design?
 *
 * A Claude Design export keeps its interactivity in component state — a
 * dropdown is `s.dropdown === 'product'`, an FAQ item is `s.openFaq === i`,
 * a form step is `s.step` — and expresses it as inline styles and `<sc-if>`
 * branches. Nothing in the rendered DOM names those relationships: no
 * aria-expanded, no aria-controls, no data-state. So the Radix probe, which
 * drives ARIA contracts, has nothing to hold on to here, and the geometry
 * gate cannot see a dead button at all — a menu that never opens has exactly
 * the boxes of one that does.
 *
 * This probe therefore compares OUTCOMES, side by side. Every visible button
 * on the design is matched to the button with the same text on the page;
 * each is clicked in turn, on both sides, and what changed is measured the
 * same way on both: how many elements became visible or hidden, and how much
 * the document grew or shrank. A click that opens a 300px FAQ answer on the
 * design and nothing on the page is a mismatch; so is one that opens the
 * same answer 40px taller. After each click, a click on the page heading
 * checks that whatever should close on an outside click does so on both.
 *
 *   node bin/dc-interaction.cjs <design-url> <page-url> [--widths 1280,390] [--chrome <path>]
 *
 * Exits 1 on any mismatch. Both URLs are driven with real mouse events.
 */

'use strict';

const path = require('path');

const puppeteer = require(path.join(__dirname, '..', 'node_modules', 'puppeteer-core'));

function arg(name, fallback) {
	const i = process.argv.indexOf('--' + name);
	return i > -1 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}

const read = async (page) => { await page.bringToFront(); return page.evaluate(READ); };
const READ = () => {
	const visible = (el) => {
		const r = el.getBoundingClientRect();
		if (r.width <= 0 || r.height <= 0) { return false; }
		const cs = getComputedStyle(el);
		return cs.visibility !== 'hidden' && cs.opacity !== '0';
	};
	const all = [...document.body.querySelectorAll('*')];
	return {
		docHeight: document.documentElement.scrollHeight,
		visible: all.filter(visible).length,
		// A drawer that slides in with `transform` keeps its box but moves it
		// on screen: count elements whose box is inside the viewport too.
		onScreen: all.filter((el) => {
			if (!visible(el)) { return false; }
			const r = el.getBoundingClientRect();
			return r.right > 0 && r.left < window.innerWidth;
		}).length,
	};
};

const BUTTONS = () => [...document.querySelectorAll('button')]
	.filter((b) => { const r = b.getBoundingClientRect(); return r.width > 0 && r.height > 0; })
	.map((b, i) => ({
		i,
		text: (b.getAttribute('aria-label') || b.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 60),
	}));

async function open(browser, url, width) {
	const page = await browser.newPage();
	await page.setViewport({ width, height: 900 });
	const errors = [];
	page.on('pageerror', (e) => errors.push(String(e).slice(0, 100)));
	await page.goto(url, { waitUntil: 'load', timeout: 60000 });
	// The design side hydrates with React from a script; wait for its root.
	await page.waitForFunction(() => {
		const root = document.querySelector('#dc-root > .sc-host > div') || document.querySelector('.dxai-ui');
		return root && root.getBoundingClientRect().height > 0 && root.innerText.trim().length > 0;
	}, { timeout: 30000 }).catch(() => {});
	// Bounded: `document.fonts.ready` never settles on a page whose font
	// request is still pending, and an unbounded await here stalled the whole
	// probe on the first page it opened.
	await page.evaluate(() => Promise.race([document.fonts.ready.catch(() => null), new Promise((r) => setTimeout(r, 5000))]));
	// Both sides scroll instantly for the probe. Arcus sets `html {
	// scroll-behavior: smooth }`, so scrollIntoView() on its FAQ 5700px down
	// was still animating when the click landed on stale coordinates: eight
	// "design did nothing" mismatches that a click by hand never showed.
	await page.addStyleTag({ content: 'html, body { scroll-behavior: auto !important; }' });
	await new Promise((r) => setTimeout(r, 800));

	return { page, errors };
}

const settle = () => new Promise((r) => setTimeout(r, 500));
const VERBOSE = process.argv.includes('--verbose');
const trace = (m) => { if (VERBOSE) { process.stderr.write(new Date().toISOString().slice(11, 19) + ' ' + m + '\n'); } };

async function clickButton(page, index) {
	// Two tabs share one browser; the one behind is throttled by headless
	// Chrome and a click on it never comes back. Front it first.
	await page.bringToFront();
	const handles = await page.$$('button');
	const visibleHandles = [];
	for (const h of handles) {
		const box = await h.boundingBox();
		if (box && box.width > 0 && box.height > 0) { visibleHandles.push(h); }
	}
	const target = visibleHandles[index];
	if (!target) { return false; }
	await target.evaluate((el) => el.scrollIntoView({ block: 'center' }));
	await settle();
	await target.click();
	await settle();

	return true;
}

async function clickOutside(page) {
	await page.bringToFront();
	const h = (await page.$('h2')) || (await page.$('h1'));
	if (!h) { return; }
	await h.evaluate((el) => el.scrollIntoView({ block: 'center' }));
	await settle();
	await h.click().catch(() => {});
	await settle();
}

async function drive(browser, designUrl, pageUrl, width) {
	const design = await open(browser, designUrl, width);
	const live = await open(browser, pageUrl, width);
	const out = { width, driven: 0, mismatches: [], pageErrors: live.errors.length, designErrors: design.errors.length };

	const designButtons = await design.page.evaluate(BUTTONS);
	const liveButtons = await live.page.evaluate(BUTTONS);
	// Pair by text, in order. A button on one side only is itself a finding.
	const used = new Set();
	const pairs = [];
	for (const d of designButtons) {
		const match = liveButtons.find((l) => !used.has(l.i) && l.text === d.text);
		if (!match) {
			out.mismatches.push('button "' + d.text + '" exists on the design only');
			continue;
		}
		used.add(match.i);
		pairs.push({ text: d.text, d: d.i, l: match.i });
	}
	for (const l of liveButtons) {
		if (!used.has(l.i)) { out.mismatches.push('button "' + l.text + '" exists on the page only'); }
	}

	for (const pair of pairs) {
		trace('button ' + pair.text);
		const before = { d: await read(design.page), l: await read(live.page) };
		trace('  read before');
		const okD = await clickButton(design.page, pair.d);
		trace('  clicked design');
		const okL = await clickButton(live.page, pair.l);
		trace('  clicked page');
		if (!okD || !okL) { continue; }
		const after = { d: await read(design.page), l: await read(live.page) };
		out.driven++;
		const delta = (side) => ({
			height: after[side].docHeight - before[side].docHeight,
			visible: after[side].visible - before[side].visible,
			onScreen: after[side].onScreen - before[side].onScreen,
		});
		const dd = delta('d');
		const ld = delta('l');
		const changedD = dd.height !== 0 || dd.visible !== 0 || dd.onScreen !== 0;
		const changedL = ld.height !== 0 || ld.visible !== 0 || ld.onScreen !== 0;
		if (changedD !== changedL) {
			out.mismatches.push('"' + pair.text + '": design ' + (changedD ? 'changed' : 'did nothing') + ', page ' + (changedL ? 'changed' : 'did nothing')
				+ ' (design Δh ' + dd.height + ' Δvis ' + dd.visible + ' Δscreen ' + dd.onScreen + '; page Δh ' + ld.height + ' Δvis ' + ld.visible + ' Δscreen ' + ld.onScreen + ')');
		} else if (Math.abs(dd.height - ld.height) > 2) {
			out.mismatches.push('"' + pair.text + '": the page grew by ' + ld.height + 'px where the design grew by ' + dd.height + 'px');
		} else if (Math.sign(dd.visible) !== Math.sign(ld.visible) && Math.sign(dd.onScreen) !== Math.sign(ld.onScreen)) {
			out.mismatches.push('"' + pair.text + '": visibility moved the other way (design Δvis ' + dd.visible + '/Δscreen ' + dd.onScreen + ', page Δvis ' + ld.visible + '/Δscreen ' + ld.onScreen + ')');
		}

		// Whatever an outside click closes must close on both sides.
		await clickOutside(design.page);
		trace('  outside design');
		await clickOutside(live.page);
		trace('  outside page');
		const closed = { d: await read(design.page), l: await read(live.page) };
		const cd = closed.d.visible - after.d.visible;
		const cl = closed.l.visible - after.l.visible;
		if ((cd < 0) !== (cl < 0)) {
			out.mismatches.push('"' + pair.text + '": an outside click ' + (cd < 0 ? 'closed it on the design' : 'left it on the design') + ' but ' + (cl < 0 ? 'closed it on the page' : 'left it on the page'));
		}
	}

	await design.page.close();
	await live.page.close();

	return out;
}

(async () => {
	const urls = process.argv.slice(2).filter((a) => /^(https?|file):/.test(a));
	if (urls.length < 2) {
		console.error('usage: node bin/dc-interaction.cjs <design-url> <page-url> [--widths 1280,390] [--chrome <path>]');
		process.exit(2);
	}
	const chrome = arg('chrome', 'C:/Program Files/Google/Chrome/Application/chrome.exe');
	const widths = String(arg('widths', '1280,390')).split(',').map((w) => Number(w)).filter(Boolean);
	const browser = await puppeteer.launch({ executablePath: chrome, headless: 'shell', args: ['--no-sandbox', '--allow-file-access-from-files'], protocolTimeout: 45000 });
	let driven = 0;
	let bad = 0;
	for (const width of widths) {
		let row;
		try {
			row = await drive(browser, urls[0], urls[1], width);
		} catch (e) {
			row = { width, driven: 0, mismatches: ['probe threw: ' + String(e).slice(0, 120)], pageErrors: 0, designErrors: 0 };
		}
		driven += row.driven;
		bad += row.mismatches.length + row.pageErrors;
		console.log(JSON.stringify(row));
	}
	await browser.close();
	console.log('dc-interaction: ' + driven + ' control(s) driven, ' + bad + ' mismatch(es)');
	process.exit(bad ? 1 : 0);
})();

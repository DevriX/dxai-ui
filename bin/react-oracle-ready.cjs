#!/usr/bin/env node
/**
 * Is the React oracle serving the design's real DOM?
 *
 * bin/react-oracle.sh answers this from the served HTML for a TanStack Start
 * project, which renders on the server — an empty shell is then proof the build
 * went wrong, and refusing is right. A classic Lovable project is a client
 * rendered SPA, where an empty shell is what `dist/index.html` IS, and the DOM
 * only exists after React runs. Reading the response body there would refuse a
 * perfectly good oracle; skipping the check instead would let a genuinely
 * broken one through and measure the design as empty. So it is asked in a
 * browser, which is where the answer lives.
 *
 *   node bin/react-oracle-ready.cjs <url> [--chrome <path>] [--min 2]
 *
 * Prints `elements=<n> text=<n>`; exits 1 when the page rendered nothing.
 */

'use strict';

const path = require('path');

const CHROME = process.env.DXAI_CHROME
	|| 'C:/Program Files/Google/Chrome/Application/chrome.exe';

function arg(name, fallback) {
	const i = process.argv.indexOf('--' + name);
	return i > -1 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}

(async () => {
	const url = process.argv[2];
	if (!url || url.startsWith('--')) {
		console.error('usage: node bin/react-oracle-ready.cjs <url> [--chrome <path>]');
		process.exit(2);
	}
	const min = Number(arg('min', '2'));
	const puppeteer = require(path.join(__dirname, '..', 'node_modules', 'puppeteer-core'));

	const browser = await puppeteer.launch({
		executablePath: arg('chrome', CHROME),
		headless: 'shell',
		args: ['--no-sandbox'],
	});

	try {
		const page = await browser.newPage();
		await page.setViewport({ width: 1280, height: 900 });
		const errors = [];
		page.on('pageerror', (e) => errors.push(String(e).slice(0, 120)));
		await page.goto(url, { waitUntil: 'load', timeout: 60000 });

		// React mounts after load; wait for content rather than for a timer, so
		// a slow machine does not read as an empty design.
		await page.waitForFunction(
			(need) => document.querySelectorAll('section, header, footer, main, article').length >= need,
			{ timeout: 30000, polling: 250 },
			min
		).catch(() => {});

		const stats = await page.evaluate(() => ({
			elements: document.querySelectorAll('section, header, footer, main, article').length,
			text: (document.body.innerText || '').trim().length,
		}));

		console.log('elements=' + stats.elements + ' text=' + stats.text);
		if (errors.length) { console.log('pageerror: ' + errors[0]); }
		await browser.close();
		process.exit(stats.elements >= min && stats.text > 0 ? 0 : 1);
	} catch (err) {
		await browser.close();
		console.error('ready probe failed: ' + err.message);
		process.exit(1);
	}
})();

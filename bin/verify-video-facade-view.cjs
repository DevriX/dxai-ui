#!/usr/bin/env node
/**
 * The plugin's copy of the YouTube facade (assets/js/youtube-facade.js and assets/css/youtube-facade.css) in a real browser: what a
 * visitor gets on a converted page or on a theme that does not bring its own.
 *
 *   node bin/verify-video-facade-view.cjs [--theme-script <the theme's own youtubeFacade.js>]
 *
 * It builds a page from the example (one video: a facade for the phone and one for the desktop), loads the plugin's styles and script, and
 * checks: only one facade is shown at 1200 and at 500 px; before anything is pressed the page asks YouTube for nothing but the thumbnails
 * (no player, no cookies); a facade has its thumbnail, its play button and is a button for the keyboard and a screen reader; a click and the
 * Enter key each swap in the player of youtube-nocookie.com, with the video's id and title; and the script and the theme's own script,
 * both loaded, still make one player. Needs no site and no network (what YouTube would answer is faked). Exit code 1 when a check fails.
 *
 * Chrome: $DXAI_CHROME, else the usual places. puppeteer-core comes from node_modules.
 */
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer-core');

const ROOT = path.resolve(__dirname, '..');
const args = process.argv.slice(2);
const themeScript = args.includes('--theme-script') ? args[args.indexOf('--theme-script') + 1] : '';
const CHROME = process.env.DXAI_CHROME || ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe', '/usr/bin/google-chrome', '/usr/bin/chromium', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find((p) => fs.existsSync(p));
if (!CHROME) {
	console.error('no Chrome found (set DXAI_CHROME)');
	process.exit(2);
}
const JS = path.join(ROOT, 'assets/js/youtube-facade.js');
const CSS = path.join(ROOT, 'assets/css/youtube-facade.css');
const ID = 'dQw4w9WgXcQ';
const TITLE = 'Roof repair tour';

// What the page prints for one video (the two groups of the example; the hidden embeds print nothing).
const PAGE = `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0">
<main style="padding:16px">
<div class="wp-block-group youtube-embed mobile-show has-custom-css" style="border-top-left-radius:0px"><style data-wp-block-html="css">.youtube-embed iframe{min-height:300px}</style>
<div class="youtube-facade" data-video-id="${ID}" data-video-title="${TITLE}" style="position:relative;aspect-ratio:unset;background:#000 center/cover no-repeat; min-height: 300px;"></div></div>
<div class="wp-block-group youtube-embed mobile-hide has-custom-css"><style data-wp-block-html="css">.youtube-embed iframe{min-height:300px}</style>
<div class="youtube-facade" data-video-id="${ID}" data-video-title="${TITLE}" style="position:relative;aspect-ratio:16/9;background:#000 center/cover no-repeat;"></div></div>
</main></body></html>`;

let fail = 0;
let pass = 0;
const expect = (what, ok, detail = '') => {
	if (ok) { pass++; console.log('  ok    ' + what); } else { fail++; console.log('  FAIL  ' + what + (detail ? '  — ' + detail : '')); }
};

(async () => {
	const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'shell', args: ['--no-sandbox'] });
	const open = async (width, extra = []) => {
		const page = await browser.newPage();
		await page.setViewport({ width, height: 800 });
		const seen = [];
		await page.setRequestInterception(true);
		page.on('request', (r) => {
			const u = r.url();
			if (/youtube|ytimg|google/i.test(u)) {
				seen.push(u);
				// What YouTube would answer, faked: a picture, a page.
				if (r.resourceType() === 'image') return r.respond({ status: 200, contentType: 'image/gif', body: Buffer.from('R0lGODlhAQABAAAAACw=', 'base64') });
				return r.respond({ status: 200, contentType: 'text/html', body: '<!doctype html><title>player</title>' });
			}
			return r.continue();
		});
		await page.setContent(PAGE, { waitUntil: 'load' });
		await page.addStyleTag({ path: CSS });
		for (const f of extra.length ? extra : [JS]) await page.addScriptTag({ path: f });
		await new Promise((r) => setTimeout(r, 300));

		return { page, seen };
	};
	const shown = (page) => page.evaluate(() => [...document.querySelectorAll('.youtube-embed')].filter((g) => getComputedStyle(g).display !== 'none').map((g) => (g.classList.contains('mobile-show') ? 'mobile' : 'desktop')));

	console.log('On a computer (1200 px)');
	let { page, seen } = await open(1200);
	expect('the desktop facade is shown and the phone\'s is not', JSON.stringify(await shown(page)) === '["desktop"]', JSON.stringify(await shown(page)));
	const facade = await page.evaluate(() => {
		const f = document.querySelector('.youtube-embed.mobile-hide .youtube-facade');
		const r = f.getBoundingClientRect();
		return { thumb: !!f.querySelector('img.youtube-facade__thumb'), play: !!f.querySelector('.youtube-facade__play'), role: f.getAttribute('role'), tab: f.getAttribute('tabindex'), label: f.getAttribute('aria-label'), w: Math.round(r.width), h: Math.round(r.height), src: (f.querySelector('img') || {}).src };
	});
	expect('the facade has its thumbnail (the video\'s, from YouTube) and its play button', facade.thumb && facade.play && facade.src === `https://i.ytimg.com/vi/${ID}/hqdefault.jpg`, JSON.stringify(facade));
	expect('it is a button for the keyboard and for a screen reader, named by the video\'s title', facade.role === 'button' && facade.tab === '0' && facade.label === TITLE);
	expect('it is as wide as the page and 16:9', facade.w > 1000 && Math.abs(facade.h - facade.w * 9 / 16) <= 1, facade.w + 'x' + facade.h);
	expect('before it is pressed the page asks YouTube for nothing but the thumbnail: no player, no embed', seen.every((u) => u.startsWith('https://i.ytimg.com/')) && !seen.some((u) => /embed/.test(u)), seen.join(' | '));
	expect('and has warmed the two connections for later', await page.evaluate(() => [...document.querySelectorAll('link[rel=preconnect]')].map((l) => l.href).sort().join(',')) === 'https://i.ytimg.com/,https://www.youtube-nocookie.com/');
	await page.click('.youtube-embed.mobile-hide .youtube-facade');
	await new Promise((r) => setTimeout(r, 300));
	const player = await page.evaluate(() => { const i = document.querySelector('.youtube-embed.mobile-hide iframe'); return i ? { src: i.src, title: i.title, allow: i.allow, w: Math.round(i.getBoundingClientRect().width), h: Math.round(i.getBoundingClientRect().height), facade: !!document.querySelector('.youtube-embed.mobile-hide .youtube-facade') } : null; });
	expect('a click swaps the facade for the player of youtube-nocookie.com, with the video\'s id, autoplay and the title', !!player && player.src === `https://www.youtube-nocookie.com/embed/${ID}?autoplay=1&rel=0` && player.title === TITLE && /autoplay/.test(player.allow) && !player.facade, JSON.stringify(player));
	expect('the player has the facade\'s shape (16:9)', !!player && Math.abs(player.h - player.w * 9 / 16) <= 2, player ? player.w + 'x' + player.h : '');
	expect('only now was YouTube asked for the player', seen.some((u) => u.startsWith('https://www.youtube-nocookie.com/embed/' + ID)));
	await page.close();

	console.log('\nOn a phone (500 px)');
	({ page, seen } = await open(500));
	expect('the phone\'s facade is shown and the desktop\'s is not', JSON.stringify(await shown(page)) === '["mobile"]', JSON.stringify(await shown(page)));
	const h = await page.evaluate(() => Math.round(document.querySelector('.youtube-embed.mobile-show .youtube-facade').getBoundingClientRect().height));
	expect('it is as high as the example says (300px)', h === 300, String(h));
	await page.focus('.youtube-embed.mobile-show .youtube-facade');
	await page.keyboard.press('Enter');
	await new Promise((r) => setTimeout(r, 300));
	const key = await page.evaluate(() => { const i = document.querySelector('.youtube-embed.mobile-show iframe'); return i ? { src: i.src, h: Math.round(i.getBoundingClientRect().height) } : null; });
	expect('the Enter key swaps in the player too, at least 300px high', !!key && key.src.startsWith('https://www.youtube-nocookie.com/embed/' + ID) && key.h >= 300, JSON.stringify(key));
	await page.close();

	console.log('\nAt the edge (768 px is a phone, 769 is not)');
	for (const [w, want] of [[768, 'mobile'], [769, 'desktop']]) {
		const o = await open(w);
		const got = JSON.stringify(await shown(o.page));
		expect(`${w} px shows the ${want} facade`, got === JSON.stringify([want]), got);
		await o.page.close();
	}

	console.log('\nThe script twice, and with the theme\'s own');
	({ page } = await open(1200, [JS, JS]));
	await page.click('.youtube-embed.mobile-hide .youtube-facade');
	await new Promise((r) => setTimeout(r, 300));
	expect('the script loaded twice makes one player', await page.evaluate(() => document.querySelectorAll('.youtube-embed.mobile-hide iframe').length) === 1);
	await page.close();
	if (themeScript && fs.existsSync(themeScript)) {
		({ page } = await open(1200, [JS, themeScript]));
		const built = await page.evaluate(() => document.querySelectorAll('.youtube-embed.mobile-hide .youtube-facade__thumb, .youtube-embed.mobile-hide .youtube-facade__play').length);
		expect('with the theme\'s script after it, the facade is not built a second time', built === 2, String(built));
		await page.click('.youtube-embed.mobile-hide .youtube-facade');
		await new Promise((r) => setTimeout(r, 300));
		expect('and a click still makes one player', await page.evaluate(() => document.querySelectorAll('.youtube-embed.mobile-hide iframe').length) === 1);
		await page.close();
		({ page } = await open(1200, [themeScript, JS]));
		await page.click('.youtube-embed.mobile-hide .youtube-facade');
		await new Promise((r) => setTimeout(r, 300));
		expect('the theme\'s script first and this one after it: one player', await page.evaluate(() => document.querySelectorAll('.youtube-embed.mobile-hide iframe').length) === 1);
		await page.close();
	} else {
		console.log('  (the theme\'s own script was not given: --theme-script <file>; its two orders of loading are not checked)');
	}

	await browser.close();
	console.log(`\n${pass} passed, ${fail} failed`);
	process.exit(fail ? 1 : 0);
})().catch((e) => { console.error('FAILED', e); process.exit(2); });

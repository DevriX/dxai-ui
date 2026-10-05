#!/usr/bin/env node
/**
 * The pages made for a design, measured against its Home — the browser half.
 *
 * bin/verify-team-quality.php decides G1, G7, G8 and G9 from the database and writes a JSON file (the Home's sections, each
 * page's sections and where each comes from). This renders the Home and every page at 1440, 768 and 412 px (and the
 * overflow at 360 and 320) and adds:
 *
 *   G2  colours      every text, background and border colour on a page is one the Home has (or one of its tokens)
 *   G3  fonts        every font family on a page is one the Home has
 *   G4  the frame    the left edge of the headings, the padding above and below the sections and the width of the content are
 *                    the Home's (a page that follows the Home's frame has the same edges and the same rhythm)
 *   G5  the sections it reused   a section that has the Home's structure is styled the way the Home's section is (the style of
 *                    every element in it, computed), and where its words are the Home's, is as high
 *   G5p pixels       a section that is the Home's own word for word (the same blocks) is drawn as the Home draws it: the two are
 *                    photographed at each width and compared, a difference of more than a fifth of a percent of the pixels is told
 *                    (a section that a CSS rule of the design draws by its place — its neighbour, its number — shows here)
 *   G6  mobile       no sideways scroll at 320–768 beyond the Home's; in the sections that are new (the cards of the site's pages, the
 *                    ways to reach the company): no tap target, text size, narrow column, overflow or squeezed picture the Home's section
 *                    they are made from does not have, and no more columns than it has
 *   G10 speed        no style sheet or script the Home does not load, no more CSS than the Home (files and the page's own rules), no
 *                    picture without its size that the Home's pictures have, and no more layout shift than the Home plus a little
 *
 * pixels=0 leaves the photographs out (they are most of the run).
 *
 *   bash bin/wp-php.sh bin/verify-team-quality.php <home-id> out=<json> [allow=G8]
 *   node bin/team-quality.cjs <json> [allow=G8,G5] [pages=<id,id>] [report=<file>]
 *
 * Chrome: $DXAI_CHROME, else the usual places. puppeteer-core comes from node_modules. Reads only. Exit code 1 when a
 * gate that is not allowed fails.
 */
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer-core');

const input = process.argv.slice(2).find((a) => !a.includes('='));
const opt = Object.fromEntries(process.argv.slice(2).filter((a) => a.includes('=')).map((a) => [a.slice(0, a.indexOf('=')), a.slice(a.indexOf('=') + 1)]));
if (!input || !fs.existsSync(input)) {
	console.error('usage: node bin/team-quality.cjs <json from verify-team-quality.php> [allow=G8,G5] [pages=<id,id>] [report=<file>]');
	process.exit(2);
}
const data = JSON.parse(fs.readFileSync(input, 'utf8'));
const allow = new Set([...(data.allow || []), ...String(opt.allow || '').split(',').filter(Boolean)]);
const only = opt.pages ? new Set(opt.pages.split(',').map(Number)) : null;
const WIDTHS = [1440, 768, 412];
const OVERFLOW_WIDTHS = [360, 320];
const CHROME = process.env.DXAI_CHROME || ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe', '/usr/bin/google-chrome', '/usr/bin/chromium', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find((p) => fs.existsSync(p));
if (!CHROME) {
	console.error('no Chrome found (set DXAI_CHROME)');
	process.exit(2);
}

/** Runs in the page: what the gates need, for the sections that are not the header and footer. */
function collect(arg) {
	const chromeCounts = arg.chrome;
	const expected = Array.isArray(arg.expected) && arg.expected.every((e) => typeof e === 'string') ? arg.expected : null;
	const vis =(e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none' && cs.opacity !== '0'; };
	const SKIP = ['SCRIPT', 'STYLE', 'NOSCRIPT', 'LINK', 'META', 'TEMPLATE', 'SVG', 'PATH', 'DEFS', 'CLIPPATH', 'G'];
	const px = (v) => Math.round(parseFloat(v) || 0);
	const scope = document.querySelector('[class*="dxai-ui--"]');
	if (!scope) return { error: 'no design scope on the page' };
	// The page's own elements: the scope's children, one wrapper opened, then the header and footer taken off.
	let list = [...scope.children];
	if (list.length === 1 && list[0].children.length >= 3) list = [...list[0].children];
	list = list.slice(chromeCounts.header, list.length - chromeCounts.footer);
	// A header and a footer that are not in the page's content (template parts) are rendered around it: not sections.
	// A second bar stuck to the top (it shows on a phone) is the Home's header too, when it has no heading of its own (a sticky hero is content).
	// A bar fixed to the bottom is not taken off: the pages copy it as a section of their own, and it is counted on both sides.
	const floats = (e) => ['sticky', 'fixed'].includes(getComputedStyle(e).position) && !e.querySelector('h1, h2');
	if (chromeCounts.header === 0) while (list.length && (['HEADER', 'NAV'].includes(list[0].tagName) || (list[0].tagName === 'A' && list[0].children.length === 0) || floats(list[0]))) list.shift();
	if (chromeCounts.footer === 0) while (list.length && list[list.length - 1].tagName === 'FOOTER') list.pop();
	const sections = [];
	for (const el of list) {
		if (el.tagName === 'MAIN') sections.push(...el.children); else sections.push(el);
	}
	// What has no height (an anchor span, a mobile menu that is shown on a phone) is not a section.
	for (let i = sections.length - 1; i >= 0; i--) if (sections[i].getBoundingClientRect().height < 1) sections.splice(i, 1);
	// A page can hold elements that are not sections of the library (a marquee strip, a bar fixed to the screen): then there are more on the
	// screen than in the blocks, and every number after the first of them would be paired with another section. The ones that are sections
	// are found by their words (the first of what each says, from the blocks), in order; when they cannot all be found, nothing is changed.
	if (expected && expected.length && sections.length !== expected.length) {
		const norm = (s) => s.toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '');
		const textOf = (el) => {
			const w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, { acceptNode: (n) => (n.parentElement && ['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE'].includes(n.parentElement.tagName) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT) });
			let t = '';
			while (w.nextNode() && t.length < 1200) t += w.currentNode.nodeValue;
			return norm(t);
		};
		const texts = sections.map(textOf);
		const picked = [];
		let from = 0;
		let found = true;
		for (const e of expected) {
			let at = -1;
			for (let k = from; k < sections.length && at < 0; k++) {
				const t = texts[k];
				// A count-up shows another number first: the middle of the words is tried too.
				if (e === '' ? t === '' : t.includes(e.slice(0, 12)) || (e.length > 18 && t.includes(e.slice(6, 18)))) at = k;
			}
			if (at < 0) { found = false; break; }
			picked.push(sections[at]);
			from = at + 1;
		}
		if (found) sections.splice(0, sections.length, ...picked);
	}
	window.__dxSections = sections; // the photographs are taken of these
	const props = ['display', 'position', 'flexDirection', 'flexWrap', 'justifyContent', 'alignItems', 'gap', 'gridTemplateColumns', 'textAlign', 'color', 'backgroundColor', 'backgroundImage', 'fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'textTransform', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'marginTop', 'marginBottom', 'borderTopWidth', 'borderTopColor', 'borderTopLeftRadius'];
	const hash = (str) => { let h = 5381; for (let i = 0; i < str.length; i++) h = ((h << 5) + h + str.charCodeAt(i)) | 0; return (h >>> 0).toString(36); };
	const out = { colors: { fg: [], bg: [], border: [] }, fonts: [], headingLefts: [], sections: [], overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth, height: document.documentElement.scrollHeight };
	const fg = new Set(), bg = new Set(), bd = new Set(), fonts = new Set(), lefts = new Set();
	const vw = document.documentElement.clientWidth;
	for (const sec of sections) {
		const sr = sec.getBoundingClientRect();
		const cs = getComputedStyle(sec);
		let fp = '', l = 1e9, r = -1e9, ownText = 0;
		const styles = new Set();
		const names = {}; // which element each style is of, to say it when a section has one the Home's does not
		const loose = new Set(); // the same, without the size of the tracks of a grid (a set of cards with fewer cards has wider ones) and the margins (a link pushed to the foot of a card with less to say sits lower)
		const hl = [];
		const tiny = [], taps = [], body = [], narrow = [], wide = [], distorted = [];
		const smallSizes = new Set(); // the sizes under 16px that any paragraph or list item of the section has, of any length: what a made section may use
		let cols = 0; // the most columns a row or a grid of the section has
		const els = [sec, ...sec.querySelectorAll('*')];
		for (const e of els) {
			if (SKIP.includes(e.tagName.toUpperCase()) || !vis(e)) continue;
			const c = getComputedStyle(e);
			const one = e.tagName + ':' + props.map((p) => c[p]).join('|');
			fp += one + ';';
			// A link a card's heading was given (the menu points a card at its page) is the heading's own look: its colour is G2's, not a new style.
			if (!(e.tagName === 'A' && e.parentElement && /^H[1-6]$/.test(e.parentElement.tagName))) { styles.add(hash(one)); names[hash(one)] = e.tagName.toLowerCase() + '.' + [...e.classList].slice(0, 3).join('.'); loose.add(hash(e.tagName + ':' + props.map((p) => (['gridTemplateColumns', 'marginTop', 'marginBottom'].includes(p) ? '' : c[p])).join('|'))); }
			const own = [...e.childNodes].filter((n) => n.nodeType === 3).map((n) => n.textContent.trim()).join(' ').trim();
			const rr = e.getBoundingClientRect();
			// A heading whose words are in spans of their own (the Home's title in two colours) has none of its own: its edge counts too, or the
			// page's title (one run of words) would be an edge the Home does not have.
			if (!own && /^H[1-3]$/.test(e.tagName) && e.textContent.trim()) {
				const middle = c.textAlign === 'center' && Math.abs(rr.left + rr.width / 2 - vw / 2) <= 2;
				if (!middle) { lefts.add(Math.round(rr.left)); hl.push(Math.round(rr.left)); }
			}
			if (own) {
				fg.add(c.color); fonts.add(c.fontFamily.split(',')[0].replace(/["']/g, '').trim()); ownText++;
				l = Math.min(l, rr.left); r = Math.max(r, rr.right);
				// A heading centred on the page is on the frame by being centred: its left edge is its width's, not the Home's.
				const centred = c.textAlign === 'center' && Math.abs(rr.left + rr.width / 2 - vw / 2) <= 2;
				if (/^H[1-3]$/.test(e.tagName) && !centred) { lefts.add(Math.round(rr.left)); hl.push(Math.round(rr.left)); }
				if (['P', 'LI'].includes(e.tagName) && own.length >= 8 && parseFloat(c.fontSize) < 16) smallSizes.add(c.fontSize);
				if (['P', 'LI'].includes(e.tagName) && own.length >= 40 && parseFloat(c.fontSize) < 16) body.push(e.tagName + ' ' + c.fontSize + ' "' + own.slice(0, 20) + '"');
			}
			if (e.tagName === 'IMG') {
				// The part of a picture that shows: a background picture wider than its section is clipped by it.
				let il = rr.left, ir = rr.right;
				for (let a = e.parentElement; a && a !== document.body; a = a.parentElement) {
					if (getComputedStyle(a).overflowX !== 'visible') { const ar = a.getBoundingClientRect(); il = Math.max(il, ar.left); ir = Math.min(ir, ar.right); }
				}
				if (ir > il) { l = Math.min(l, il); r = Math.max(r, ir); }
			}
			const b = c.backgroundColor; if (b && !/rgba?\(0, 0, 0, 0\)|transparent/.test(b)) bg.add(b);
			if (parseFloat(c.borderTopWidth) > 0 && c.borderTopStyle !== 'none') bd.add(c.borderTopColor);
			if (rr.right > vw + 1 && !e.closest('[aria-hidden=true], .overflow-hidden, [style*="overflow"]')) wide.push(e.tagName + '.' + [...e.classList].slice(0, 2).join('.'));
			if (e.matches('a[href], button, input:not([type=hidden]), select, textarea, summary') && (rr.width < 44 || rr.height < 44) && !(e.tagName === 'A' && c.display === 'inline')) taps.push((e.textContent || e.tagName).trim().slice(0, 20) + ' ' + Math.round(rr.width) + 'x' + Math.round(rr.height));
			if (c.display === 'grid' || (c.display.includes('flex') && c.flexDirection.startsWith('row'))) {
				const kids = [...e.children].filter((k) => { const kr = k.getBoundingClientRect(); return kr.width > 0 && kr.height > 0; });
				if (kids.length >= 2) cols = Math.max(cols, new Set(kids.map((k) => Math.round(k.getBoundingClientRect().left / 4))).size);
			}
			if (e.tagName === 'IMG' && e.naturalWidth > 0 && rr.height > 0 && c.objectFit === 'fill' && Math.abs((rr.width / rr.height) / (e.naturalWidth / e.naturalHeight) - 1) > 0.03) distorted.push((e.getAttribute('src') || '').split('?')[0]);
			if (c.display.includes('flex') && c.flexDirection.startsWith('row') && vw < 600) {
				for (const k of e.children) { const kr = k.getBoundingClientRect(); if (kr.width > 0 && kr.width < 120 && k.textContent.trim().length > 12) narrow.push(Math.round(kr.width) + 'px'); }
			}
		}
		out.sections.push({
			top: Math.round(sr.top + window.scrollY), height: Math.round(sr.height), left: Math.round(sr.left), width: Math.round(sr.width),
			pt: px(cs.paddingTop), pb: px(cs.paddingBottom), l: l > 1e8 ? null : Math.round(l), r: r < -1e8 ? null : Math.round(r),
			fp: hash(fp), styles: [...styles], names, loose: [...loose], hl, texts: ownText, tiny: body, small: [...smallSizes], taps, narrow, wide, cols, distorted,
		});
	}
	out.colors = { fg: [...fg], bg: [...bg], border: [...bd] };
	out.fonts = [...fonts];
	out.headingLefts = [...lefts];
	return out;
}

/** Runs in the page: what loading it asked for, and how much it moved while it loaded. */
function speed() {
	const css = [], js = [];
	let cssBytes = 0, jsBytes = 0;
	for (const r of performance.getEntriesByType('resource')) {
		const u = r.name.split('?')[0];
		if (/\.css$/i.test(u)) { css.push(u); cssBytes += r.encodedBodySize || 0; } else if (/\.js$/i.test(u)) { js.push(u); jsBytes += r.encodedBodySize || 0; }
	}
	const inline = [...document.querySelectorAll('style')].reduce((n, s) => n + s.textContent.length, 0);
	const noDims = [...document.images].filter((i) => !i.hasAttribute('width') && !i.hasAttribute('height')).map((i) => (i.getAttribute('src') || '').split('?')[0]);

	return { css: [...new Set(css)], js: [...new Set(js)], cssBytes, jsBytes, inline, noDims, cls: window.__cls || 0 };
}

const hexToRgb = (h) => { const m = /^#?([0-9a-f]{6})$/i.exec(String(h).trim()); if (!m) return null; const n = parseInt(m[1], 16); return `rgb(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255})`; };
const near = (a, set, tol) => set.some((b) => Math.abs(a - b) <= tol);

// A browser that dies takes its connection's errors with it: they are not the measure's.
process.on('unhandledRejection', (e) => console.error('browser:', String((e && e.message) || e).slice(0, 80)));

(async () => {
	let browser = await puppeteer.launch({ executablePath: CHROME, headless: 'shell', args: ['--no-sandbox'] });
	const pages = data.pages.filter((p) => !only || only.has(p.id));
	const targets = [{ id: data.home.id, url: data.home.url, home: true, expected: data.home.sections.map((s) => s.txt) }, ...pages.map((p) => ({ id: p.id, url: p.url, expected: p.sections.map((s) => s.txt) }))];
	const seen = {};
	let opened = 0;
	const open = async (width) => {
		// A new browser now and then (a long run of pages is a lot for one) and whenever the old one is gone.
		if (!browser.connected || ++opened > 8) {
			try { await browser.close(); } catch (_) { /* gone already */ }
			browser = await puppeteer.launch({ executablePath: CHROME, headless: 'shell', args: ['--no-sandbox'] });
			opened = 1;
		}
		const page = await browser.newPage();
		await page.setViewport({ width, height: 900 });
		// What G10 reads: how much the page moved while it loaded, and every file it asked for.
		await page.evaluateOnNewDocument(() => {
			window.__cls = 0;
			try {
				performance.setResourceTimingBufferSize(2000);
				new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__cls += e.value; }).observe({ type: 'layout-shift', buffered: true });
			} catch (_) { /* a browser without layout-shift entries: nothing to read */ }
		});
		return page;
	};
	const measure = async (page, t) => {
		await page.goto(t.url, { waitUntil: 'networkidle2', timeout: 90000 });
		// Every picture is loaded before anything is measured: a lazy one far down a long page (the Home) is not, and one near the top of a short page (a made page) is, which is a difference of the measure and not of the pages.
		await page.evaluate(async () => {
			await document.fonts.ready;
			for (const i of document.images) i.loading = 'eager';
			const h = document.documentElement.scrollHeight;
			for (let y = 0; y < h; y += 600) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 40)); }
			await Promise.all([...document.images].map((i) => (i.complete ? 0 : new Promise((r) => { i.onload = i.onerror = r; setTimeout(r, 6000); }))));
			window.scrollTo(0, 0);
			await new Promise((r) => setTimeout(r, 300));
		});
		// A page that fades or slides its sections in is measured at whatever moment the measure happens to look: the same colour at another
		// opacity, a transform half done. The Home and the pages are both measured with nothing moving.
		await page.addStyleTag({ content: '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}html{scroll-behavior:auto!important}' });
		await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))));
		const m = await page.evaluate(collect, { chrome: data.home.chrome, expected: t.expected });
		if (m && !m.error) m.speed = await page.evaluate(speed);

		return m;
	};

	// The photographs (G5p): the Home's sections that pages show as they are, and the pages' own, at the same width.
	const PIXELS = opt.pixels !== '0';
	const PIX_LIMIT = 0.002;
	const canPixel = (s) => s.identical && s.origin === 'home' && s.home_index != null && !(s.ops || []).some((o) => /^(related|contact|list):/.test(o));
	const need = new Set();
	for (const p of pages) for (const s of p.sections) if (canPixel(s)) need.add(s.home_index);
	const homeShots = {};
	const pix = {};
	const stats = { compared: 0, same: 0, unstable: 0 };
	const shoot = async (page, idx) => {
		await page.addStyleTag({ content: '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}html{scroll-behavior:auto!important}' });
		// What floats over the page (a sticky bar, a call button) or plays (a video) is not the section's, and is where the scroll puts it.
		await page.evaluate(() => {
			for (const e of document.querySelectorAll('*')) {
				const p = getComputedStyle(e).position;
				if (p === 'fixed' || p === 'sticky' || e.tagName === 'IFRAME' || e.tagName === 'VIDEO') e.style.visibility = 'hidden';
			}
			window.scrollTo(0, 0);
		});
		// Every picture decoded before it is photographed: one that is loaded and not yet decoded is drawn as an empty frame.
		await page.evaluate(async () => {
			await Promise.all([...document.images].map((i) => (i.decode ? i.decode().catch(() => null) : null)));
			await new Promise((r) => setTimeout(r, 300));
		});
		const out = {};
		for (const i of idx) {
			// The section is moved to stand on a whole pixel (the page is let down by the fraction it was short of): a line of text sits at
			// the same fraction of a pixel in every photograph of the section, wherever on its page the section is.
			const box = await page.evaluate((n) => {
				const e = (window.__dxSections || [])[n];
				if (!e) return null;
				document.body.style.paddingTop = window.__dxPad || '';
				window.__dxPad = window.__dxPad || document.body.style.paddingTop;
				let r = e.getBoundingClientRect();
				const top = r.top + window.scrollY;
				const frac = top - Math.floor(top);
				if (frac > 0.002) {
					document.body.style.paddingTop = 'calc(' + getComputedStyle(document.body).paddingTop + ' + ' + (1 - frac) + 'px)';
					r = e.getBoundingClientRect();
				}

				return { x: Math.round(r.left + window.scrollX), y: Math.round(r.top + window.scrollY), width: Math.round(r.width), height: Math.round(r.height) };
			}, i);
			if (box && box.width > 0 && box.height > 0 && box.height < 15000) {
				// A section whose look follows the scroll (a progress bar, items that slide in as it passes) is drawn for where the page is
				// scrolled to: both are photographed with the section at the top of the screen, whatever the page has above it.
				await page.evaluate((y) => window.scrollTo(0, y), box.y);
				await new Promise((r) => setTimeout(r, 150));
				const now = await page.evaluate((n) => {
					const r = (window.__dxSections || [])[n].getBoundingClientRect();

					return { x: Math.round(r.left + window.scrollX), y: Math.round(r.top + window.scrollY), width: Math.round(r.width), height: Math.round(r.height) };
				}, i);
				out[i] = await page.screenshot({ type: 'png', clip: Math.abs(now.height - box.height) <= 2 ? now : box, captureBeyondViewport: true });
			}
		}

		return out;
	};
	const compare = async (a, b) => {
		const c = await browser.newPage();
		try {
			return await c.evaluate(async (x, y) => {
				const load = (s) => new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = rej; i.src = 'data:image/png;base64,' + s; });
				const [A, B] = await Promise.all([load(x), load(y)]);
				if (A.width !== B.width || A.height !== B.height) return { size: [A.width, A.height, B.width, B.height] };
				const data = (im) => { const cv = document.createElement('canvas'); cv.width = im.width; cv.height = im.height; const g = cv.getContext('2d'); g.drawImage(im, 0, 0); return g.getImageData(0, 0, im.width, im.height).data; };
				const da = data(A), db = data(B), w = A.width, h = A.height;
				// The sections were put at a whole pixel before they were photographed (shoot): the two are drawn the same way or not.
				// The first and the last row are not read: they are where the neighbour's colour blends in.
				let best = null;
				for (const dy of [0]) {
					let px = 0, first = -1, last = -1, rows = 0;
					for (let y = 1; y < h - 1; y++) {
						const yb = y + dy;
						if (yb < 1 || yb > h - 2) continue;
						rows++;
						for (let x = 0; x < w; x++) {
							const i = (y * w + x) * 4, j = (yb * w + x) * 4;
							if (Math.abs(da[i] - db[j]) + Math.abs(da[i + 1] - db[j + 1]) + Math.abs(da[i + 2] - db[j + 2]) > 24) { px++; if (first < 0) first = y; last = y; }
						}
					}
					if (best === null || px < best.px) best = { px, total: Math.max(1, rows * w), first, last, dy };
				}

				return best;
			}, Buffer.from(a).toString('base64'), Buffer.from(b).toString('base64'));
		} finally {
			await c.close();
		}
	};
	const photograph = async (page, t, width) => {
		if (t.home) {
			// Twice: a section that does not stay the same from one photograph to the next (a slider that moves) is not a measure.
			const a = await shoot(page, [...need]);
			const b = await shoot(page, [...need]);
			homeShots[width] = {};
			for (const i of need) {
				if (!a[i] || !b[i]) continue;
				const d = await compare(a[i], b[i]);
				if (d.px !== undefined && d.px / d.total <= PIX_LIMIT) homeShots[width][i] = a[i]; else stats.unstable++;
			}

			return;
		}
		const p = pages.find((x) => x.id === t.id);
		const P = seen[width + ':' + t.id];
		if (!p || !P || P.error || p.sections.length !== P.sections.length || !homeShots[width]) return;
		const idx = p.sections.map((s, i) => (canPixel(s) && homeShots[width][s.home_index] ? i : -1)).filter((i) => i >= 0);
		if (!idx.length) return;
		const shots = await shoot(page, idx);
		pix[width + ':' + t.id] = {};
		for (const i of idx) {
			if (!shots[i]) continue;
			const d = await compare(homeShots[width][p.sections[i].home_index], shots[i]);
			pix[width + ':' + t.id][i] = d;
			// The two photographs of a section that differs, to look at (DXAI_SHOTS=<directory>).
			if (process.env.DXAI_SHOTS && !(d.px !== undefined && d.px / d.total <= PIX_LIMIT)) {
				fs.mkdirSync(process.env.DXAI_SHOTS, { recursive: true });
				fs.writeFileSync(path.join(process.env.DXAI_SHOTS, `${t.id}-${width}-s${i + 1}-page.png`), shots[i]);
				fs.writeFileSync(path.join(process.env.DXAI_SHOTS, `${t.id}-${width}-s${i + 1}-home.png`), homeShots[width][p.sections[i].home_index]);
			}
		}
	};
	for (const width of [...WIDTHS, ...OVERFLOW_WIDTHS]) {
		let page = await open(width);
		for (const t of targets) {
			for (let attempt = 1; attempt <= 3; attempt++) {
				try {
					seen[width + ':' + t.id] = await measure(page, t);
					if (PIXELS && WIDTHS.includes(width) && !seen[width + ':' + t.id].error) await photograph(page, t, width);
					break;
				} catch (e) {
					seen[width + ':' + t.id] = { error: String((e && e.stack) || e).slice(0, 300) }; if (process.env.DXAI_DEBUG) console.error(e);
					// a page or a browser that is gone: a new one, and the same page again
					try { await page.close(); } catch (_) { /* gone already */ }
					page = await open(width);
				}
			}
		}
		try { await page.close(); } catch (_) { /* gone already */ }
	}
	await browser.close();

	const tokenColors = Object.values(data.tokens || {}).map(hexToRgb).filter(Boolean);
	const gates = { G2: {}, G3: {}, G4: {}, G5: {}, G6: {}, G10: {} };
	const add = (g, id, msg) => { (gates[g][id] = gates[g][id] || []).push(msg); };
	const notes = {};
	// A page whose sections the browser cannot tell from the Home's chrome at some width (it counts another number than the blocks do) is
	// not photographed against the Home: which element is which section is not known.
	const unmappedPages = new Set();
	for (const p of pages) for (const width of WIDTHS) { const P = seen[width + ':' + p.id]; if (P && !P.error && P.sections.length !== p.sections.length) unmappedPages.add(p.id); }
	for (const p of pages) {
		for (const width of WIDTHS) {
			const H = seen[width + ':' + data.home.id], P = seen[width + ':' + p.id];
			if (!P || P.error || !H || H.error) { add('G5', p.id, `${width}px: not measured (${(P && P.error) || (H && H.error)})`); continue; }
			const tag = `${width}px`;
			// G2 · colours
			if (width === 1440) {
				for (const k of ['fg', 'bg', 'border']) {
					const ok = new Set([...H.colors[k], ...tokenColors]);
					const foreign = P.colors[k].filter((c) => !ok.has(c));
					if (foreign.length) add('G2', p.id, `${k} colours the Home does not have: ${foreign.slice(0, 3).join(', ')}`);
				}
				// G3 · fonts
				const hf = new Set(H.fonts);
				const bad = P.fonts.filter((f) => !hf.has(f));
				if (bad.length) add('G3', p.id, `fonts the Home does not have: ${bad.join(', ')}`);
			}
			// G4 · the frame
			// Headings of a row whose sides were swapped on purpose (the flip variant) are on the other side.
			const flipped = new Set(p.sections.map((x, i) => ((x.ops || []).includes('flip') ? i : -1)).filter((i) => i >= 0));
			// A new section of cards (the site's pages, the ways to reach it) has as many cards as the site has things to say: its columns are the
			// Home's grid with fewer cards, so a title stands anywhere between the Home's titles (the content's left and right edges are G4's other rules).
			// (A page whose sections the browser cannot tell from the Home's chrome is read as a whole: one new section anywhere makes them all lenient.)
			const hasNew = (s) => (s.ops || []).some((o) => /^(related|contact|list):/.test(o));
			const mapped = p.sections.length === P.sections.length;
			const isNew = (i) => (mapped ? hasNew(p.sections[i]) : p.sections.some(hasNew));
			const lo = Math.min(...H.headingLefts), hi = Math.max(...H.headingLefts);
			const badLeft = P.sections.flatMap((q, i) => {
				if (flipped.has(i)) return [];
				if (!isNew(i)) return q.hl.filter((x) => !near(x, H.headingLefts, 2));
				return q.hl.filter((x) => x < lo - 2 || x > hi + 2);
			});
			if (badLeft.length) add('G4', p.id, `${tag}: headings start at ${badLeft.slice(0, 3).join(', ')}px, the Home's start at ${H.headingLefts.slice(0, 4).join(', ')}px`);
			const hpt = H.sections.map((s) => s.pt), hpb = H.sections.map((s) => s.pb);
			const badPad = P.sections.filter((s) => !near(s.pt, hpt, 1) || !near(s.pb, hpb, 1));
			if (badPad.length) add('G4', p.id, `${tag}: ${badPad.length} sections have a padding the Home's do not (${badPad.slice(0, 2).map((s) => s.pt + '/' + s.pb).join(', ')})`);
			const hw = Math.max(...H.sections.map((s) => (s.r != null ? s.r - s.l : 0)));
			const pw = Math.max(...P.sections.map((s) => (s.r != null ? s.r - s.l : 0)));
			if (pw > hw + 2) add('G4', p.id, `${tag}: the content is ${pw}px wide, the Home's is ${hw}px`);
			// G5 · what was reused
			const info = p.sections;
			if (info.length !== P.sections.length) {
				(notes[p.id] = notes[p.id] || new Set()).add(`${tag}: ${P.sections.length} sections on screen, ${info.length} in its blocks`);
			} else {
				info.forEach((s, i) => {
					if (!['home', 'derived'].includes(s.origin) || s.home_index == null || !H.sections[s.home_index]) return;
					const h = H.sections[s.home_index], q = P.sections[i];
					const poured = (s.ops || []).some((o) => /^(related|contact|list):/.test(o));
					const known = new Set(poured ? h.loose : h.styles);
					const extra = (poured ? q.loose : q.styles).filter((x) => !known.has(x));
					if (extra.length) add('G5', p.id, `${tag}: section ${i + 1} (${s.role}) uses ${extra.length} styles that the Home's section ${s.home_index + 1} does not (${(q.names && q.names[extra[0]]) || String(extra[0])})`);
					else if (q.left !== h.left || q.width !== h.width) add('G5', p.id, `${tag}: section ${i + 1} (${s.role}) sits at ${q.left}/${q.width}px, the Home's at ${h.left}/${h.width}px`);
					else if (s.identical && q.fp !== h.fp) add('G5', p.id, `${tag}: section ${i + 1} (${s.role}) is the Home's word for word and is not styled as it is`);
					else if (s.identical && Math.abs(q.height - h.height) > 2) add('G5', p.id, `${tag}: section ${i + 1} (${s.role}) is ${q.height}px high, the Home's is ${h.height}px`);
				});
			}
			// G5p · the sections that are the Home's own, photographed
			for (const [n, d] of Object.entries(unmappedPages.has(p.id) ? {} : pix[width + ':' + p.id] || {})) {
				const s = info[Number(n)];
				if (d.size) add('G5', p.id, `${tag}: section ${Number(n) + 1} (${s.role}) is the Home's own and is ${d.size[3]}px high, the Home's is ${d.size[1]}px (${d.size[2]} and ${d.size[0]} wide)`);
				else if (d.px / d.total > PIX_LIMIT) add('G5', p.id, `${tag}: section ${Number(n) + 1} (${s.role}) is the Home's own and is drawn differently: ${(100 * d.px / d.total).toFixed(1)}% of its pixels differ (rows ${d.first}–${d.last})`);
			}
			// G6 · the sections that are new, on a phone and a tablet: nothing the Home's section they are made from does not have
			if (width <= 768 && mapped) {
				info.forEach((s, i) => {
					if ((s.origin !== 'new' && !hasNew(s)) || !P.sections[i]) return;
					const q = P.sections[i];
					const h = s.home_index != null ? H.sections[s.home_index] : null;
					const sizeOf = (t) => String(t).split(' ').pop();
					const fontOf = (t) => String(t).split(' ')[1];
					const hTaps = new Set((h ? h.taps : []).map(sizeOf)), hTiny = new Set([...H.sections.flatMap((x) => x.small || x.tiny.map(fontOf))]);
					// (a size under 16px that any paragraph of the Home has, of any length, is the Home's own body text: a made section may use it)
					const taps = q.taps.filter((t) => !hTaps.has(sizeOf(t)));
					const tiny = q.tiny.filter((t) => !hTiny.has(fontOf(t)));
					const narrow = q.narrow.filter((w) => !(h && h.narrow.includes(w)));
					const wide = q.wide.filter((w) => !(h && h.wide.includes(w)));
					const squeezed = q.distorted.filter((u) => !(h && h.distorted.includes(u)));
					if (taps.length) add('G6', p.id, `${tag}: section ${i + 1} (${s.role}) has ${taps.length} targets under 44px that the Home's has not (${taps.slice(0, 2).join('; ')})`);
					if (tiny.length) add('G6', p.id, `${tag}: section ${i + 1} (${s.role}) has body text under 16px that the Home's has not (${tiny.slice(0, 2).join('; ')})`);
					if (narrow.length) add('G6', p.id, `${tag}: section ${i + 1} (${s.role}) has columns of ${narrow.slice(0, 2).join(', ')}`);
					if (wide.length) add('G6', p.id, `${tag}: section ${i + 1} (${s.role}) reaches past the screen (${wide.slice(0, 2).join(', ')})`);
					if (squeezed.length) add('G6', p.id, `${tag}: section ${i + 1} (${s.role}) shows a picture out of shape (${squeezed.slice(0, 2).join(', ')})`);
					if (h && q.cols > h.cols) add('G6', p.id, `${tag}: section ${i + 1} (${s.role}) has ${q.cols} columns, the Home's section has ${h.cols}`);
				});
			}
			// G10 · speed: what loading the page asks for, against what loading the Home asks for
			if ((width === 1440 || width === 412) && H.speed && P.speed) {
				const hs = H.speed, ps = P.speed;
				const css = ps.css.filter((u) => !hs.css.includes(u)), js = ps.js.filter((u) => !hs.js.includes(u));
				if (css.length) add('G10', p.id, `${tag}: loads ${css.length} style sheets the Home does not (${css.slice(0, 2).map((u) => u.split('/').pop()).join(', ')})`);
				if (js.length) add('G10', p.id, `${tag}: loads ${js.length} scripts the Home does not (${js.slice(0, 2).map((u) => u.split('/').pop()).join(', ')})`);
				if (ps.cssBytes > hs.cssBytes + 1024) add('G10', p.id, `${tag}: ${ps.cssBytes} bytes of CSS files, the Home's are ${hs.cssBytes}`);
				if (ps.inline > hs.inline * 1.1 + 1000) add('G10', p.id, `${tag}: ${ps.inline} characters of rules in the page, the Home's are ${hs.inline}`);
				const bare = ps.noDims.filter((u) => !hs.noDims.includes(u));
				if (bare.length) add('G10', p.id, `${tag}: ${bare.length} pictures without their size that the Home's pictures have (${bare.slice(0, 2).map((u) => u.split('/').pop()).join(', ')})`);
				if (ps.cls > Math.max(hs.cls + 0.02, 0.1)) add('G10', p.id, `${tag}: the page moves by ${ps.cls.toFixed(3)} while it loads, the Home by ${hs.cls.toFixed(3)}`);
			}
		}
		for (const width of [...WIDTHS.filter((w) => w <= 768), ...OVERFLOW_WIDTHS]) {
			const H = seen[width + ':' + data.home.id], P = seen[width + ':' + p.id];
			if (P && !P.error && H && !H.error && P.overflow > Math.max(0, H.overflow)) add('G6', p.id, `${width}px: scrolls sideways by ${P.overflow}px (the Home by ${H.overflow}px)`);
		}
	}

	// The report
	const names = { G1: "header and footer are the Home's", G2: 'colours are the Home\'s', G3: 'fonts are the Home\'s', G4: "the Home's frame", G5: 'reused sections are the Home\'s', G6: 'mobile', G7: 'blocks are valid', G8: 'pages are not copies (sections)', G8W: 'pages are not copies (words)', G9: 'nothing foreign' };
	names.G10 = 'speed is the Home\'s';
	const all = { ...(data.gates || {}), ...gates };
	const title = Object.fromEntries(data.pages.map((p) => [p.id, p.title]));
	console.log(`Home ${data.home.id} "${data.home.title}": ${data.home.sections.length} sections, ${pages.length} pages, measured at ${WIDTHS.join(', ')} px (overflow also at ${OVERFLOW_WIDTHS.join(', ')})\n`);
	const failed = [];
	const rows = [];
	for (const g of ['G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8', 'G8W', 'G9', 'G10']) {
		const bad = all[g] || {};
		const badIds = Object.keys(bad).filter((id) => !only || only.has(Number(id)));
		const ok = badIds.length === 0;
		const status = ok ? 'ok' : allow.has(g) ? 'FAIL (allowed)' : 'FAIL';
		rows.push({ g, name: names[g], pass: pages.length - badIds.length, of: pages.length, status });
		console.log(`${g}  ${names[g]} — ${status} (${pages.length - badIds.length} of ${pages.length} pages)`);
		for (const id of badIds.slice(0, 40)) console.log(`      #${id} ${String(title[id] || '').slice(0, 36)}: ${[].concat(bad[id]).slice(0, 4).join('; ')}`);
		if (badIds.length > 40) console.log(`      … and ${badIds.length - 40} more`);
		if (!ok && !allow.has(g)) failed.push(g);
	}
	const unmapped = Object.keys(notes).filter((id) => !only || only.has(Number(id)));
	if (unmapped.length) console.log(`\nG5 could not tell the sections of ${unmapped.length} pages from the other elements of their page (a Home whose header or footer is not marked as such); their styles were not compared: ${unmapped.slice(0, 8).join(', ')}`);
	// What was compared: the pages whose sections could be told apart.
	for (const [k, rows] of Object.entries(pix)) {
		if (unmappedPages.has(Number(k.split(':')[1]))) continue;
		for (const d of Object.values(rows)) { stats.compared++; if (d.px !== undefined && d.px / d.total <= PIX_LIMIT) stats.same++; }
	}
	if (PIXELS) console.log(`\nG5p  photographs: ${stats.compared} sections that are the Home's own compared with it, ${stats.same} drawn as it draws them${stats.unstable ? ` (${stats.unstable} Home sections left out: they do not stay still)` : ''}`);
	if (opt.report) fs.writeFileSync(opt.report, JSON.stringify({ rows, gates: all, measured: seen }, null, 1));
	process.exit(failed.length ? 1 : 0);
})();

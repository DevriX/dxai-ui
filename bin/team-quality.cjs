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
 *   G6  mobile       no sideways scroll at 320–768 beyond the Home's; in the sections that are new: a tap target of 44 px at least,
 *                    body text of 16 px, no column narrower than 120 px, nothing wider than the screen
 *
 * G10 (speed) is not measured yet; G5 compares styles and boxes, a pixel comparison comes with the new sections.
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
function collect(chromeCounts) {
	const vis = (e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none' && cs.opacity !== '0'; };
	const SKIP = ['SCRIPT', 'STYLE', 'NOSCRIPT', 'LINK', 'META', 'TEMPLATE', 'SVG', 'PATH', 'DEFS', 'CLIPPATH', 'G'];
	const px = (v) => Math.round(parseFloat(v) || 0);
	const scope = document.querySelector('[class*="dxai-ui--"]');
	if (!scope) return { error: 'no design scope on the page' };
	// The page's own elements: the scope's children, one wrapper opened, then the header and footer taken off.
	let list = [...scope.children];
	if (list.length === 1 && list[0].children.length >= 3) list = [...list[0].children];
	list = list.slice(chromeCounts.header, list.length - chromeCounts.footer);
	const sections = [];
	for (const el of list) {
		if (el.tagName === 'MAIN') sections.push(...el.children); else sections.push(el);
	}
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
		const tiny = [], taps = [], body = [], narrow = [], wide = [];
		const els = [sec, ...sec.querySelectorAll('*')];
		for (const e of els) {
			if (SKIP.includes(e.tagName.toUpperCase()) || !vis(e)) continue;
			const c = getComputedStyle(e);
			const one = e.tagName + ':' + props.map((p) => c[p]).join('|');
			fp += one + ';';
			styles.add(hash(one));
			const own = [...e.childNodes].filter((n) => n.nodeType === 3).map((n) => n.textContent.trim()).join(' ').trim();
			const rr = e.getBoundingClientRect();
			if (own) {
				fg.add(c.color); fonts.add(c.fontFamily.split(',')[0].replace(/["']/g, '').trim()); ownText++;
				l = Math.min(l, rr.left); r = Math.max(r, rr.right);
				if (/^H[1-3]$/.test(e.tagName)) lefts.add(Math.round(rr.left));
				if (['P', 'LI'].includes(e.tagName) && own.length >= 40 && parseFloat(c.fontSize) < 16) body.push(e.tagName + ' ' + c.fontSize + ' "' + own.slice(0, 20) + '"');
			}
			if (e.tagName === 'IMG') { l = Math.min(l, rr.left); r = Math.max(r, rr.right); }
			const b = c.backgroundColor; if (b && !/rgba?\(0, 0, 0, 0\)|transparent/.test(b)) bg.add(b);
			if (parseFloat(c.borderTopWidth) > 0 && c.borderTopStyle !== 'none') bd.add(c.borderTopColor);
			if (rr.right > vw + 1 && !e.closest('[aria-hidden=true], .overflow-hidden, [style*="overflow"]')) wide.push(e.tagName + '.' + [...e.classList].slice(0, 2).join('.'));
			if (e.matches('a[href], button, input:not([type=hidden]), select, textarea, summary') && (rr.width < 44 || rr.height < 44) && !(e.tagName === 'A' && c.display === 'inline')) taps.push((e.textContent || e.tagName).trim().slice(0, 20) + ' ' + Math.round(rr.width) + 'x' + Math.round(rr.height));
			if (c.display.includes('flex') && c.flexDirection.startsWith('row') && vw < 600) {
				for (const k of e.children) { const kr = k.getBoundingClientRect(); if (kr.width > 0 && kr.width < 120 && k.textContent.trim().length > 12) narrow.push(Math.round(kr.width) + 'px'); }
			}
		}
		out.sections.push({
			top: Math.round(sr.top + window.scrollY), height: Math.round(sr.height), left: Math.round(sr.left), width: Math.round(sr.width),
			pt: px(cs.paddingTop), pb: px(cs.paddingBottom), l: l > 1e8 ? null : Math.round(l), r: r < -1e8 ? null : Math.round(r),
			fp: hash(fp), styles: [...styles], texts: ownText, tiny: body, taps, narrow, wide,
		});
	}
	out.colors = { fg: [...fg], bg: [...bg], border: [...bd] };
	out.fonts = [...fonts];
	out.headingLefts = [...lefts];
	return out;
}

const hexToRgb = (h) => { const m = /^#?([0-9a-f]{6})$/i.exec(String(h).trim()); if (!m) return null; const n = parseInt(m[1], 16); return `rgb(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255})`; };
const near = (a, set, tol) => set.some((b) => Math.abs(a - b) <= tol);

(async () => {
	const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'shell', args: ['--no-sandbox'] });
	const pages = data.pages.filter((p) => !only || only.has(p.id));
	const targets = [{ id: data.home.id, url: data.home.url, home: true }, ...pages.map((p) => ({ id: p.id, url: p.url }))];
	const seen = {};
	for (const width of [...WIDTHS, ...OVERFLOW_WIDTHS]) {
		const page = await browser.newPage();
		await page.setViewport({ width, height: 900 });
		for (const t of targets) {
			try {
				await page.goto(t.url, { waitUntil: 'networkidle2', timeout: 90000 });
				await page.evaluate(async () => { await document.fonts.ready; const h = document.documentElement.scrollHeight; for (let y = 0; y < h; y += 600) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 40)); } window.scrollTo(0, 0); await new Promise((r) => setTimeout(r, 300)); });
				seen[width + ':' + t.id] = await page.evaluate(collect, data.home.chrome);
			} catch (e) {
				seen[width + ':' + t.id] = { error: String(e).slice(0, 120) };
			}
		}
		await page.close();
	}
	await browser.close();

	const tokenColors = Object.values(data.tokens || {}).map(hexToRgb).filter(Boolean);
	const gates = { G2: {}, G3: {}, G4: {}, G5: {}, G6: {} };
	const add = (g, id, msg) => { (gates[g][id] = gates[g][id] || []).push(msg); };
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
			const badLeft = P.headingLefts.filter((x) => !near(x, H.headingLefts, 2));
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
				add('G5', p.id, `${tag}: the page has ${P.sections.length} sections on screen and ${info.length} in its blocks — not compared`);
			} else {
				info.forEach((s, i) => {
					if (!['home', 'derived'].includes(s.origin) || s.home_index == null || !H.sections[s.home_index]) return;
					const h = H.sections[s.home_index], q = P.sections[i];
					const known = new Set(h.styles);
					const extra = q.styles.filter((x) => !known.has(x));
					if (extra.length) add('G5', p.id, `${tag}: section ${i + 1} (${s.role}) uses ${extra.length} styles that the Home's section ${s.home_index + 1} does not`);
					else if (q.left !== h.left || q.width !== h.width) add('G5', p.id, `${tag}: section ${i + 1} (${s.role}) sits at ${q.left}/${q.width}px, the Home's at ${h.left}/${h.width}px`);
					else if (s.identical && q.fp !== h.fp) add('G5', p.id, `${tag}: section ${i + 1} (${s.role}) is the Home's word for word and is not styled as it is`);
					else if (s.identical && Math.abs(q.height - h.height) > 2) add('G5', p.id, `${tag}: section ${i + 1} (${s.role}) is ${q.height}px high, the Home's is ${h.height}px`);
				});
			}
			// G6 · the sections that are new, on a phone and a tablet
			if (width <= 768) {
				info.forEach((s, i) => {
					if (s.origin !== 'new' || !P.sections[i]) return;
					const q = P.sections[i];
					if (q.taps.length) add('G6', p.id, `${tag}: section ${i + 1} (${s.role}) has ${q.taps.length} targets under 44px (${q.taps.slice(0, 2).join('; ')})`);
					if (q.tiny.length) add('G6', p.id, `${tag}: section ${i + 1} (${s.role}) has body text under 16px (${q.tiny.slice(0, 2).join('; ')})`);
					if (q.narrow.length) add('G6', p.id, `${tag}: section ${i + 1} (${s.role}) has columns of ${q.narrow.slice(0, 2).join(', ')}`);
					if (q.wide.length) add('G6', p.id, `${tag}: section ${i + 1} (${s.role}) reaches past the screen (${q.wide.slice(0, 2).join(', ')})`);
				});
			}
		}
		for (const width of [...WIDTHS.filter((w) => w <= 768), ...OVERFLOW_WIDTHS]) {
			const H = seen[width + ':' + data.home.id], P = seen[width + ':' + p.id];
			if (P && !P.error && H && !H.error && P.overflow > Math.max(0, H.overflow)) add('G6', p.id, `${width}px: scrolls sideways by ${P.overflow}px (the Home by ${H.overflow}px)`);
		}
	}

	// The report
	const names = { G1: "header and footer are the Home's", G2: 'colours are the Home\'s', G3: 'fonts are the Home\'s', G4: "the Home's frame", G5: 'reused sections are the Home\'s', G6: 'mobile', G7: 'blocks are valid', G8: 'pages are not copies', G9: 'nothing foreign' };
	const all = { ...(data.gates || {}), ...gates };
	const title = Object.fromEntries(data.pages.map((p) => [p.id, p.title]));
	console.log(`Home ${data.home.id} "${data.home.title}": ${data.home.sections.length} sections, ${pages.length} pages, measured at ${WIDTHS.join(', ')} px (overflow also at ${OVERFLOW_WIDTHS.join(', ')})\n`);
	const failed = [];
	const rows = [];
	for (const g of ['G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8', 'G9']) {
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
	console.log('\nG10  speed — not measured yet');
	if (opt.report) fs.writeFileSync(opt.report, JSON.stringify({ rows, gates: all, measured: seen }, null, 1));
	process.exit(failed.length ? 1 : 0);
})();

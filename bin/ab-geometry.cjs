#!/usr/bin/env node
/**
 * Live before/after geometry: did a change move anything on a real page?
 *
 * bin/design-oracle.cjs answers "does the page match the design". This answers
 * a narrower question the migration to core blocks keeps asking: "did THIS
 * change — a group turned into core/buttons, a literal colour turned into a
 * preset — move or repaint anything on a page that was already right?". Phase 0
 * of that migration promises no visual change at all, and the oracle cannot
 * hold it to that on its own: it compares against the design, sums only the
 * heights of nodes it can pair, and a node that stops pairing adds 0px. A
 * before/after of the same URL has no such blind spot, because both sides are
 * the page.
 *
 * usage:
 *   node bin/ab-geometry.cjs capture <url> <out.json> [--widths 1280,768,390]
 *   node bin/ab-geometry.cjs diff <a.json> <b.json> [--floor <noise.json>] [--json <out>]
 *   node bin/ab-geometry.cjs noise <url> <out.json> [--widths 1280,768,390]
 *
 * Common options: --root <selector> (default .dxai-ui), --chrome <path>,
 * --show <n> rows of examples per width (default 8), --live-clock to leave
 * infinite animations running instead of pausing them at t=0 (see snapshot()).
 *
 *   capture  records EVERY element under the root — hidden ones and SVG
 *            internals included — with its box (x, y, width, height in document
 *            pixels) and computed color, background-color, font-family and
 *            font-size, at each width.
 *   diff     pairs the two captures node for node and reports, per width:
 *            boxes moved and their total px, paint changes, and the node-count
 *            change. Exit 1 when anything changed beyond the floor, 0 when not.
 *   noise    captures the same URL twice and diffs the two. That is the FLOOR:
 *            what changes between two loads of an unchanged page. The scratch
 *            prototype's floor on DevriX Elevate was 67 boxes / 1123px at 1280
 *            and 72 at 390 — its orbit field caught at a different phase each
 *            load — and reading those as the change under test is exactly the
 *            kind of false alarm that gets a check ignored. snapshot() now
 *            pins animations (see there) and the measured floor on Elevate and
 *            on H2O is 0 at 1280/768/390; run noise anyway before trusting a
 *            diff on a new design, because script-driven motion cannot be
 *            pinned. Pass the noise file to `diff --floor` and the nodes that
 *            moved in it are reported separately instead of counted.
 *
 * Promoted from a scratch prototype (geom-snap.cjs + ab-diff.cjs) that paired
 * the two captures BY INDEX — the i-th element against the i-th element. That
 * works until the first change that adds an element, which is every change the
 * migration makes: one `div.wp-block-buttons` inserted near the top shifted
 * every later index, and every later node read as moved and repainted. Nodes
 * are paired here by the same path keys bin/design-oracle.cjs uses, with the
 * same class-strip rule and the same core-wrapper pass-through, so an inserted
 * wrapper is reported as a wrapper and its children still pair.
 *
 * @package DXAI_UI
 */

'use strict';

const fs = require('fs');
const path = require('path');

const repo = path.resolve(__dirname, '..');

/*
 * The class-strip rule and the core wrappers, copied from collector() in
 * bin/design-oracle.cjs — keep the two in step. A copy rather than a require
 * because that file runs as a script on load (it parses argv and exits), and
 * both lists have to be serialised into the page anyway. The copy is checked
 * against the original on every run (see checkDrift()) so a change to one that
 * misses the other is printed rather than silently producing keys that no
 * longer agree between the two instruments.
 */
const WP = /^(wp-block|is-layout|has-|is-style|dxai-|alignfull|alignwide|entry-|wp-image-|wp-elements|is-armed|is-in|lucide|wp-element-|wp-container-|wp-states-|wp-custom-css-|wp-settings-|wp-duotone-|wp-lightbox-|is-vertical|is-horizontal|is-nowrap|is-content-justification-|is-position-|items-justified-|is-responsive|open-on-|open-always|hidden-by-default|is-menu-open|aligncenter|alignleft|alignright|alignnone)|^scp[0-9a-z]+$|^sc-host$/;
const CORE_WRAPPERS = [
	'wp-block-buttons',
	'wp-block-button',
	'wp-block-navigation__responsive-container',
	'wp-block-navigation__responsive-close',
	'wp-block-navigation__responsive-dialog',
	'wp-block-navigation__responsive-container-content',
	'wp-block-navigation__container',
	'wp-block-navigation__submenu-container',
	'wp-block-navigation-item',
	'wp-block-navigation-item__label',
];

function parseArgs(argv) {
	const out = { _: [] };
	for (let i = 0; i < argv.length; i++) {
		if (!argv[i].startsWith('--')) { out._.push(argv[i]); continue; }
		const key = argv[i].slice(2);
		out[key] = argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[++i] : true;
	}
	return out;
}

const args = parseArgs(process.argv.slice(2));
const [command, first, second] = args._;
const chrome = args.chrome || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const rootSelector = typeof args.root === 'string' ? args.root : '.dxai-ui';
const show = parseInt(args.show, 10) || 8;
const widths = String(args.widths || '1280,768,390').split(',')
	.map((w) => parseInt(w.trim(), 10)).filter((w) => w > 0);

function usage() {
	console.error('usage: node bin/ab-geometry.cjs capture <url> <out.json> [--widths 1280,768,390] [--root .dxai-ui]');
	console.error('       node bin/ab-geometry.cjs diff <a.json> <b.json> [--floor <noise.json>] [--json <out.json>]');
	console.error('       node bin/ab-geometry.cjs noise <url> <out.json> [--widths 1280,768,390]');
	process.exit(2);
}

/**
 * Warn when the copied rules above no longer match bin/design-oracle.cjs.
 * Read from the source text, because that file cannot be required.
 */
function checkDrift() {
	let src = '';
	try {
		src = fs.readFileSync(path.join(__dirname, 'design-oracle.cjs'), 'utf8');
	} catch (e) {
		return;
	}
	const regex = (src.match(/^\s*const WP = \/(.+)\/;\s*$/m) || [])[1];
	const block = (src.match(/const CORE_WRAPPERS = \[([\s\S]*?)\];/) || [])[1];
	const names = block ? [...block.matchAll(/'([^']+)'/g)].map((m) => m[1]) : null;
	if (regex !== WP.source) {
		console.log('# warning: the class-strip rule differs from bin/design-oracle.cjs — keys may not agree between the two');
	}
	if (!names || names.join(' ') !== CORE_WRAPPERS.join(' ')) {
		console.log('# warning: the core-wrapper list differs from bin/design-oracle.cjs — keys may not agree between the two');
	}
}

/**
 * Collected in the page. Self-contained: puppeteer serialises it.
 *
 * Every element under the root is recorded, including hidden ones and SVG
 * internals. The oracle skips unrendered nodes because the design and the page
 * legitimately hold different things inside a closed panel; a before/after of
 * one page has no such excuse, and a node that went from visible to hidden IS
 * a change.
 */
function collect(rootSel, wpSource, wrapperNames) {
	const root = document.querySelector(rootSel);
	if (!root) { return { error: 'no ' + rootSel + ' root' }; }
	const WPC = new RegExp(wpSource);

	/*
	 * Keys, as bin/design-oracle.cjs builds them: an id the page wrote, an
	 * image's file, or the tag plus the classes that survive the strip rule.
	 * `modal-<n>` joins the generated-id list here because core/navigation
	 * mints it per instance (`modal-1`, `modal-1-content`), and a second
	 * navigation block above the first would renumber it.
	 */
	const GENERATED = /^(?:radix-|headlessui-|react-aria|:r[0-9a-z]+:|«r|_[Rr]_[0-9a-z]*_|dxai-id-|dxai-rx-|modal-\d)/;
	const sig = (el) => {
		if (el.id && !GENERATED.test(el.id)) { return '#' + el.id; }
		if (el.tagName === 'IMG') {
			let file = (el.getAttribute('src') || '').split('/').pop();
			try { file = decodeURIComponent(file); } catch (e) { /* keep as written */ }
			file = file.replace(/\s+/g, '-');
			return 'img:' + file.replace(/(?:-\d+)+(\.[a-z0-9]+)$/i, '$1');
		}
		const cls = [...el.classList].filter((c) => !WPC.test(c)).sort().join('.');
		let tag = el.tagName.toLowerCase();
		if (tag === 'svg' || el.hasAttribute('data-lucide')) {
			tag = 'icon';
		} else if (tag === 'p' || tag === 'div') {
			tag = 'block';
		}
		return tag + (cls ? '.' + cls : '');
	};

	/*
	 * Pass-through wrappers: recorded (so two captures that both have one
	 * pair it and compare it) but left out of their children's path (so a
	 * capture that gained one still pairs everything inside it). The oracle's
	 * transparent set — template part, core/image's figure, core/table's
	 * figure, an unstyled <main> — plus the core block wrappers, the latter
	 * only while they carry no class of their own. Recursive for all of them:
	 * unlike the oracle, this has no recorded keys to stay compatible with.
	 */
	const passThrough = (el) => el.classList.contains('wp-block-template-part')
		|| el.classList.contains('wp-block-image')
		|| el.classList.contains('wp-block-table')
		|| (el.tagName === 'MAIN' && !el.getAttribute('class') && !el.getAttribute('style'))
		|| (wrapperNames.some((name) => el.classList.contains(name)) && [...el.classList].every((k) => WPC.test(k)));

	/*
	 * Colours by what they paint, not how they are spelled. The migration
	 * moves literal colours onto presets, and the same colour then reaches
	 * the computed style by a different route: Tailwind v4's palette is
	 * oklch, so a class colour computes to `oklch(0.63 0.19 259)` where a hex
	 * preset of the same colour computes to `rgb(…)`. Comparing strings would
	 * report every recoloured-but-identical node. So each distinct value is
	 * painted onto a 1×1 canvas and read back as sRGB bytes, premultiplied so
	 * a faint colour's channels do not carry the huge rounding error that
	 * un-premultiplying an alpha of 0.04 introduces. A value the canvas
	 * cannot parse keeps its string (the sentinel fill survives), so an
	 * unparsed colour is compared as text rather than silently read as black.
	 */
	const canvas = document.createElement('canvas');
	canvas.width = 1;
	canvas.height = 1;
	const ctx = canvas.getContext('2d', { willReadFrequently: true });
	const painted = new Map();
	const srgb = (value) => {
		if (painted.has(value)) { return painted.get(value); }
		let out = 'raw:' + value;
		if (ctx) {
			ctx.fillStyle = '#010203';
			const sentinel = ctx.fillStyle;
			ctx.fillStyle = value;
			if (ctx.fillStyle !== sentinel || /^(?:#010203|rgb\(1, 2, 3\))$/.test(value)) {
				ctx.clearRect(0, 0, 1, 1);
				ctx.fillRect(0, 0, 1, 1);
				const [r, g, b, a] = ctx.getImageData(0, 0, 1, 1).data;
				const pre = (c) => Math.round(c * a / 255);
				out = [pre(r), pre(g), pre(b), a].join(',');
			}
		}
		painted.set(value, out);
		return out;
	};
	const firstFamily = (stack) => String(stack).split(',')[0].trim().replace(/^["']|["']$/g, '').toLowerCase();

	/*
	 * Elements that never paint. Not "hidden" — those are recorded — but
	 * elements that cannot have a box at all. Moving a design's scoped
	 * <style> out of the content and into a stylesheet is exactly the kind of
	 * change the no-inline-styles direction makes, and it should not read as
	 * a lost node on a page that looks identical.
	 */
	const inert = (el) => ['STYLE', 'SCRIPT', 'TEMPLATE', 'LINK', 'NOSCRIPT', 'META'].includes(el.tagName);

	const nodes = [];
	const seen = Object.create(null);
	const round = (v) => Math.round(v * 100) / 100;
	const walk = (el, parent, depth, carried) => {
		const box = el.getBoundingClientRect();
		const style = getComputedStyle(el);
		const wrapper = passThrough(el);
		const label = (wrapper ? '~' : '') + sig(el);
		const at = parent + '/' + label;
		const n = (seen[at] = (seen[at] || 0) + 1);
		// Informational, for the "on a node in motion" count: anything running
		// on this node or an ancestor, in any direction — a node inside a moving
		// parent moves with it whatever its own style says. Broader than the
		// oracle's sideways-only `moving`, because here y moves count too.
		const moving = Boolean(carried) || (style.animationName !== 'none' && style.animationName !== '')
			|| (typeof el.getAnimations === 'function' && el.getAnimations().some((a) => a.playState === 'running'));
		nodes.push({
			k: at + (n > 1 ? '[' + n + ']' : ''),
			d: depth,
			x: round(box.left + window.scrollX),
			y: round(box.top + window.scrollY),
			w: round(box.width),
			h: round(box.height),
			c: style.color,
			bg: style.backgroundColor,
			cn: srgb(style.color),
			bgn: srgb(style.backgroundColor),
			ff: firstFamily(style.fontFamily),
			fs: style.fontSize,
			an: style.animationName,
			vis: !(style.display === 'none' || style.visibility === 'hidden' || box.width + box.height === 0),
			mv: moving,
			wr: wrapper,
		});
		// A wrapper's children continue its PARENT's path, so they key the same
		// with or without it.
		const childPath = wrapper ? parent : at + (n > 1 ? '[' + n + ']' : '');
		for (const child of el.children) {
			if (!inert(child)) { walk(child, childPath, depth + 1, moving); }
		}
	};
	for (const child of root.children) {
		if (!inert(child)) { walk(child, '', 1, false); }
	}
	return { doc: document.documentElement.scrollHeight, count: nodes.length, nodes };
}

/**
 * Load one URL at one width and wait for it to stop moving, the same way
 * bin/design-oracle.cjs waits for the live side — then one step more.
 */
async function snapshot(context, url, width) {
	const page = await context.newPage();
	await page.setViewport({ width, height: 900 });
	try {
		await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
	} catch (e) {
		// A local dev server stalls a request now and then; one retry.
		console.log('# retrying ' + url);
		await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
	}
	const appeared = await page.waitForSelector(rootSelector, { timeout: 30000 }).then(() => true, () => false);
	if (!appeared) {
		await page.close();
		return { error: 'no ' + rootSelector + ' root within 30s at ' + url };
	}
	// Fonts and images, with lazy images forced eager so a viewport that never
	// scrolls does not measure them at 0px.
	await page.evaluate((ms) => {
		document.querySelectorAll('img[loading="lazy"]').forEach((i) => { i.loading = 'eager'; });
		const settled = Promise.all([
			document.fonts.ready,
			...[...document.images].filter((i) => !i.complete)
				.map((i) => new Promise((r) => { i.addEventListener('load', r); i.addEventListener('error', r); })),
		]);
		return Promise.race([settled, new Promise((r) => setTimeout(r, ms))]);
	}, 15000).catch(() => {});
	// Fonts again after a forced reflow: a face is only requested when a glyph
	// first needs it, so the first layout can ask for one the first await
	// never saw (see measure() in bin/design-oracle.cjs).
	await page.evaluate((ms) => {
		void document.documentElement.offsetHeight;
		const again = document.fonts.ready.then(() => new Promise((r) => requestAnimationFrame(() => r())));
		return Promise.race([again, new Promise((r) => setTimeout(r, ms))]);
	}, 5000).catch(() => {});
	// 600ms without a DOM mutation, capped at 15s: WordPress's emoji script
	// rewrites nodes after everything else has settled. Attributes are watched
	// too, which the oracle does not: a script-driven animation writes `style`
	// on every frame and changes no child list, and this capture should not
	// land in the middle of one. A page whose script never stops pays the 15s.
	await page.evaluate((quiet, cap) => new Promise((done) => {
		let timer = setTimeout(finish, quiet);
		const obs = new MutationObserver(() => {
			clearTimeout(timer);
			timer = setTimeout(finish, quiet);
		});
		obs.observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true });
		const hard = setTimeout(finish, cap);
		function finish() {
			clearTimeout(timer);
			clearTimeout(hard);
			obs.disconnect();
			done();
		}
	}), 600, 15000).catch(() => {});
	/*
	 * The step the oracle does not need: let every FINITE animation and
	 * transition run out. The oracle forces reveals to their end state and
	 * compares against a static design; here both sides are the live page
	 * with its own runtime, so it is left to reveal what it reveals on load
	 * (a change that breaks the reveal runtime should show up as a change)
	 * — but a 700ms entrance caught at 400ms on one load and 650ms on the
	 * next is a clock reading, not a difference. Infinite ones (the orbit
	 * field, the marquee) never finish; they are pinned next.
	 */
	await page.evaluate((cap) => {
		const finite = document.getAnimations().filter((a) => {
			const timing = a.effect && a.effect.getComputedTiming ? a.effect.getComputedTiming() : null;
			return timing && Number.isFinite(timing.endTime);
		});
		return Promise.race([
			Promise.all(finite.map((a) => a.finished.catch(() => {}))),
			new Promise((r) => setTimeout(r, cap)),
		]);
	}, 5000).catch(() => {});
	/*
	 * Then stop the clock on the infinite ones: pause each and seek it to its
	 * start. The prototype this replaces captured whatever phase the orbit
	 * spin (46s), float (9s) and marquee happened to be in, and two loads of
	 * an unchanged DevriX Elevate differed by 67 boxes / 1123px at 1280 and 72
	 * boxes at 390 — all of them SVG circles, groups and paths of the orbit
	 * field. Seeking every one to t=0 puts both captures at the same phase,
	 * so what is left in the floor is what genuinely varies between loads.
	 * An animation a change REMOVES still shows: each node's animation-name
	 * is compared as paint (see PAINT).
	 *
	 * On Elevate the finite-animation wait above already does most of this —
	 * its last orbit `bx-draw` ends ~3.2s after load, so both captures land
	 * on the same frame of the shared timeline, and --live-clock measured a
	 * 0 floor there too. A page with no finite animation to line up on has no
	 * such luck: a fixture with nothing but a 3s spin moved 7px between two
	 * loads with --live-clock and 0px pinned.
	 *
	 * Script-driven motion (requestAnimationFrame writing styles) cannot be
	 * paused from here; the attribute-mutation wait above holds the capture
	 * until it goes quiet, for up to 15s, and noise mode measures the rest.
	 */
	const frozen = args['live-clock'] ? 0 : await page.evaluate(() => {
		let n = 0;
		for (const a of document.getAnimations()) {
			const timing = a.effect && a.effect.getComputedTiming ? a.effect.getComputedTiming() : null;
			if (!timing || Number.isFinite(timing.endTime)) { continue; }
			a.pause();
			a.currentTime = 0;
			n++;
		}
		return n;
	}).catch(() => 0);
	const data = await page.evaluate(collect, rootSelector, WP.source, CORE_WRAPPERS);
	if (!data.error) { data.frozen = frozen; }
	await page.close();
	return data;
}

/**
 * One capture: every width, in a fresh browser context so a second capture
 * starts as cold as the first — no cached fonts or CSS making it settle on a
 * different schedule.
 */
async function capture(browser, url) {
	const context = await browser.createBrowserContext();
	const out = {
		kind: 'ab-geometry',
		version: 1,
		url,
		root: rootSelector,
		chrome: await browser.version(),
		// Whether infinite animations were paused at t=0 (see snapshot()).
		clock: args['live-clock'] ? 'live' : 'frozen',
		capturedAt: new Date().toISOString(),
		widths: {},
	};
	try {
		for (const width of widths) {
			const data = await snapshot(context, url, width);
			if (data.error) { throw new Error(data.error); }
			out.widths[width] = data;
		}
	} finally {
		await context.close();
	}
	return out;
}

async function launch() {
	const puppeteer = require(path.join(repo, 'node_modules', 'puppeteer-core'));
	return puppeteer.launch({ executablePath: chrome, headless: 'shell', args: ['--no-sandbox'] });
}

/* ------------------------------------------------------------------ diff --- */

const TOLERANCE = 0.5;
/*
 * `visible` rides along with the four paint values because `visibility:
 * hidden` keeps its box: a node that stopped showing would otherwise move
 * nothing and repaint nothing. `animation` (the animation-name) because
 * snapshot() freezes infinite animations at their start, where a spin is at
 * 0deg — the same box as no spin at all, so a lost animation needs its own
 * channel.
 */
const PAINT = [
	['color', (n) => n.cn],
	['background', (n) => n.bgn],
	['font-family', (n) => n.ff],
	['font-size', (n) => n.fs],
	['visible', (n) => n.vis],
	['animation', (n) => n.an],
];

/**
 * Two painted colours are the same when every premultiplied channel is within
 * one step: the same colour reached through oklch and through hex rounds to
 * neighbouring bytes, not always to the same one.
 */
function sameColour(a, b) {
	if (a === b) { return true; }
	if (String(a).startsWith('raw:') || String(b).startsWith('raw:')) { return false; }
	const pa = String(a).split(',').map(Number);
	const pb = String(b).split(',').map(Number);
	return pa.length === 4 && pb.length === 4 && pa.every((v, i) => Math.abs(v - pb[i]) <= 1);
}

function samePaint(property, a, b) {
	if (property === 'color' || property === 'background') { return sameColour(a, b); }
	if (property === 'font-size') { return Math.abs(parseFloat(a) - parseFloat(b)) <= 0.01 || a === b; }
	return a === b;
}

function diffWidth(A, B, floor) {
	const inA = new Map(A.nodes.map((n) => [n.k, n]));
	const inB = new Map(B.nodes.map((n) => [n.k, n]));
	const moved = [];
	const repainted = [];
	for (const n of A.nodes) {
		const m = inB.get(n.k);
		if (!m) { continue; }
		const d = { x: m.x - n.x, y: m.y - n.y, w: m.w - n.w, h: m.h - n.h };
		const off = Object.keys(d).filter((k) => Math.abs(d[k]) > TOLERANCE);
		if (off.length) {
			moved.push({
				k: n.k, a: n, b: m, d, off,
				px: off.reduce((sum, k) => sum + Math.abs(d[k]), 0),
				noisy: Boolean(floor && floor.moved.has(n.k)),
			});
		}
		const props = PAINT.filter(([name, read]) => !samePaint(name, read(n), read(m))).map(([name]) => name);
		if (props.length) {
			repainted.push({ k: n.k, a: n, b: m, props, noisy: Boolean(floor && floor.paint.has(n.k)) });
		}
	}
	const unpaired = (from, other, side) => from.nodes.filter((n) => !other.has(n.k))
		.map((n) => ({ k: n.k, n, side, wrapper: n.wr, noisy: Boolean(floor && floor.unpaired.has(n.k)) }));
	const onlyA = unpaired(A, inB, 'A');
	const onlyB = unpaired(B, inA, 'B');
	return { moved, repainted, onlyA, onlyB };
}

function sumBy(list, pick) {
	return list.reduce((sum, item) => sum + pick(item), 0);
}

/**
 * Print one width's diff and return its numbers. `beyond` is what the verdict
 * is made of: changes outside the floor, and unpaired nodes that are not
 * pass-through wrappers — a wrapper the change added is expected in a
 * migration, and whatever it does to layout shows up in its children's boxes.
 */
function report(width, A, B, result, hasFloor) {
	const { moved, repainted, onlyA, onlyB } = result;
	const real = (list) => list.filter((r) => !r.noisy);
	const movedReal = real(moved);
	const paintReal = real(repainted);
	const lostReal = real(onlyA).filter((r) => !r.wrapper);
	const gainedReal = real(onlyB).filter((r) => !r.wrapper);
	const axes = (list) => ['x', 'y', 'w', 'h']
		.map((k) => k + ' ' + Math.round(sumBy(list, (r) => (r.off.includes(k) ? Math.abs(r.d[k]) : 0))))
		.join(', ');
	const yOnly = moved.filter((r) => r.off.length === 1 && r.off[0] === 'y').length;
	const inMotion = moved.filter((r) => r.a.mv || r.b.mv).length;
	const byProp = (list) => PAINT.map(([name]) => name + ' ' + list.filter((r) => r.props.includes(name)).length).join(', ');

	console.log('');
	console.log('@' + width + 'px   doc ' + A.doc + ' → ' + B.doc + '   nodes ' + A.count + ' → ' + B.count
		+ ' (' + (B.count - A.count >= 0 ? '+' : '') + (B.count - A.count) + ')');
	console.log('  paired ' + (A.count - onlyA.length) + '   only in A ' + onlyA.filter((r) => !r.wrapper).length
		+ '   only in B ' + onlyB.filter((r) => !r.wrapper).length
		+ '   (pass-through wrappers: A ' + onlyA.filter((r) => r.wrapper).length + ', B ' + onlyB.filter((r) => r.wrapper).length + ')');
	console.log('  boxes moved ' + moved.length + ' (' + Math.round(sumBy(moved, (r) => r.px)) + 'px: ' + axes(moved) + ')'
		+ (yOnly ? '   ' + yOnly + ' only shifted vertically' : '')
		+ (inMotion ? '   ' + inMotion + ' on a node in motion' : ''));
	console.log('  paint changed ' + repainted.length + ' (' + byProp(repainted) + ')');
	if (hasFloor) {
		console.log('  beyond the floor: ' + movedReal.length + ' moved (' + Math.round(sumBy(movedReal, (r) => r.px)) + 'px), '
			+ paintReal.length + ' repainted, ' + lostReal.length + ' lost, ' + gainedReal.length + ' gained   ['
			+ (moved.length - movedReal.length) + ' moved / ' + (repainted.length - paintReal.length) + ' repainted / '
			+ (onlyA.length + onlyB.length - real(onlyA).length - real(onlyB).length) + ' unpaired inside the floor]');
	}
	const box = (n) => '[' + [n.x, n.y, n.w, n.h].map((v) => Math.round(v)).join(',') + ']';
	const worst = [...movedReal].sort((a, b) => b.px - a.px);
	for (const r of worst.slice(0, show)) {
		console.log('    moved  ' + r.k.slice(-60).padEnd(61) + box(r.a) + ' → ' + box(r.b));
	}
	for (const r of paintReal.slice(0, show)) {
		const read = (n, prop) => ({ color: n.c, background: n.bg, 'font-family': n.ff, 'font-size': n.fs, visible: n.vis, animation: n.an })[prop];
		console.log('    paint  ' + r.k.slice(-60).padEnd(61)
			+ r.props.map((p) => p + ' ' + read(r.a, p) + ' → ' + read(r.b, p)).join('  ').slice(0, 160));
	}
	for (const r of [...lostReal, ...gainedReal].slice(0, show)) {
		console.log('    only ' + r.side + ' ' + r.k.slice(-60).padEnd(61) + box(r.n));
	}
	return {
		doc: [A.doc, B.doc],
		nodes: [A.count, B.count],
		moved: moved.length,
		px: Math.round(sumBy(moved, (r) => r.px)),
		yOnly,
		inMotion,
		paint: repainted.length,
		onlyA: onlyA.filter((r) => !r.wrapper).length,
		onlyB: onlyB.filter((r) => !r.wrapper).length,
		wrappers: [onlyA.filter((r) => r.wrapper).length, onlyB.filter((r) => r.wrapper).length],
		beyond: {
			moved: movedReal.length,
			px: Math.round(sumBy(movedReal, (r) => r.px)),
			paint: paintReal.length,
			lost: lostReal.length,
			gained: gainedReal.length,
		},
		// The keys themselves, so a noise run can serve as the next diff's floor.
		noisy: {
			moved: moved.map((r) => r.k),
			paint: repainted.map((r) => r.k),
			unpaired: [...onlyA, ...onlyB].map((r) => r.k),
		},
	};
}

function readCapture(file) {
	const data = JSON.parse(fs.readFileSync(file, 'utf8'));
	if (data.kind !== 'ab-geometry' || !data.widths) {
		throw new Error(file + ' is not an ab-geometry capture');
	}
	return data;
}

/** Diff two captures; returns { changed, widths, common }. */
function diffCaptures(A, B, floorFile) {
	if (A.url !== B.url) { console.log('# note: different URLs — ' + A.url + ' vs ' + B.url); }
	if (A.chrome !== B.chrome) {
		console.log('# warning: captured with different browsers (' + A.chrome + ' vs ' + B.chrome + ') — text metrics can differ');
	}
	if (A.root !== B.root) { console.log('# warning: different roots — ' + A.root + ' vs ' + B.root); }
	if (A.clock !== B.clock) {
		console.log('# warning: one capture paused its animations and the other did not (' + A.clock + ' vs ' + B.clock + ') — every animated box will differ');
	}
	let floor = null;
	if (floorFile) {
		const noise = JSON.parse(fs.readFileSync(floorFile, 'utf8'));
		if (noise.kind !== 'ab-geometry-noise') { throw new Error(floorFile + ' is not an ab-geometry noise file'); }
		if (noise.url !== B.url) { console.log('# note: the floor was measured on ' + noise.url); }
		if (noise.a && noise.a.clock !== B.clock) {
			console.log('# warning: the floor was measured with a ' + noise.a.clock + ' clock and this capture has a ' + B.clock + ' one');
		}
		floor = noise.widths;
	}
	const common = Object.keys(A.widths).filter((w) => B.widths[w]);
	const skipped = [...Object.keys(A.widths), ...Object.keys(B.widths)].filter((w) => !common.includes(w));
	if (skipped.length) { console.log('# widths captured on one side only, not compared: ' + [...new Set(skipped)].join(', ')); }
	const out = {};
	let changed = false;
	for (const w of common) {
		const f = floor && floor[w] ? {
			moved: new Set(floor[w].noisy.moved),
			paint: new Set(floor[w].noisy.paint),
			unpaired: new Set(floor[w].noisy.unpaired),
		} : null;
		if (floor && !floor[w]) { console.log('# no floor for ' + w + 'px — every change there counts'); }
		const numbers = report(w, A.widths[w], B.widths[w], diffWidth(A.widths[w], B.widths[w], f), Boolean(f));
		out[w] = numbers;
		const b = numbers.beyond;
		if (b.moved || b.paint || b.lost || b.gained) { changed = true; }
	}
	return { changed, widths: out, common };
}

/* ------------------------------------------------------------------ main --- */

(async () => {
	if (command === 'capture') {
		if (!first || !second) { usage(); }
		checkDrift();
		const browser = await launch();
		try {
			const data = await capture(browser, first);
			fs.writeFileSync(second, JSON.stringify(data));
			console.log(second + '  ' + Object.entries(data.widths)
				.map(([w, d]) => '@' + w + ' doc=' + d.doc + ' nodes=' + d.count).join('   '));
		} finally {
			await browser.close();
		}
		return;
	}

	if (command === 'diff') {
		if (!first || !second) { usage(); }
		checkDrift();
		const result = diffCaptures(readCapture(first), readCapture(second), typeof args.floor === 'string' ? args.floor : null);
		if (typeof args.json === 'string') {
			fs.writeFileSync(args.json, JSON.stringify({ kind: 'ab-geometry-diff', a: first, b: second, ...result }, null, 2));
			console.log('\n# wrote ' + args.json);
		}
		console.log('\nA/B: ' + (result.changed ? 'CHANGED' : 'no change') + (args.floor ? ' beyond the floor' : '')
			+ ' at ' + result.common.map((w) => w + 'px').join('/'));
		process.exit(result.changed ? 1 : 0);
	}

	if (command === 'noise') {
		if (!first || !second) { usage(); }
		checkDrift();
		const browser = await launch();
		let a;
		let b;
		try {
			a = await capture(browser, first);
			b = await capture(browser, first);
		} finally {
			await browser.close();
		}
		console.log('# noise floor: two cold loads of ' + first + ', nothing changed in between');
		const result = diffCaptures(a, b, null);
		const floor = { kind: 'ab-geometry-noise', url: first, root: rootSelector, widths: result.widths, a, b };
		fs.writeFileSync(second, JSON.stringify(floor));
		console.log('\nfloor: ' + result.common.map((w) => {
			const r = result.widths[w];
			return '@' + w + ' ' + r.moved + ' moved (' + r.px + 'px), ' + r.paint + ' repainted, '
				+ (r.onlyA + r.onlyB) + ' unpaired, nodes ' + r.nodes[0] + '→' + r.nodes[1];
		}).join('   '));
		console.log('# wrote ' + second + ' — pass it to `diff --floor` to set these nodes aside');
		return;
	}

	usage();
})().catch((e) => {
	console.error(e.stack || String(e));
	process.exit(1);
});

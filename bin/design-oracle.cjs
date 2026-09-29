#!/usr/bin/env node
/**
 * Measure a converted page against the design oracle.
 *
 * TWO oracles, and the difference between them is the whole point.
 *
 * The COMPILED oracle (default) is the compiled design DOM rendered with output
 * from the real Tailwind CLI and none of the plugin's own CSS. It is a fixed
 * point for CSS and for the block conversion: an earlier converted page is not
 * a baseline, because it can be lossy in ways nobody has noticed yet. Three
 * times during the block-conversion work a change looked like a regression only
 * because the thing it was measured against was already wrong.
 *
 * But its HTML comes from this plugin's own Jsx_Compiler — design-oracle.php
 * calls compile_file() — so a JSX-to-HTML loss is present on BOTH sides and the
 * diff stays at 0px while the page is wrong. That is not a small blind spot:
 * everything downstream of reading the JSX is unverified by it. It found the
 * ARA logo bug only by luck, because two code paths inside our own compiler
 * happened to disagree with each other; a loss that is merely CONSISTENT is
 * invisible to it.
 *
 * The REACT oracle (`--design-url`) closes that. Point it at a real Vite build
 * of the same project — React, Radix, Tailwind and the router all genuinely
 * running, no plugin code anywhere in the design side — and the design side
 * becomes independent of us. bin/react-oracle.sh builds and serves it.
 *
 * Everything else is shared on purpose: one measure(), one set of
 * normalisations, one comparison. So the two oracles produce directly
 * comparable numbers, and the gap between them is exactly what the compiled
 * oracle was hiding.
 *
 *   wp eval-file bin/design-oracle.php <zip> <dir>
 *   node bin/design-oracle.cjs --dir <dir> --url <live-url>
 *   node bin/design-oracle.cjs --dir <dir> --url <live-url> --section causes
 *   node bin/design-oracle.cjs --design-url http://127.0.0.1:5199/ --url <live-url>
 *
 * Options:
 *   --dir       directory written by design-oracle.php   (required unless --design-url)
 *   --url       the converted page to measure                     (required)
 *   --design-root the design side's root selector       (default "body > div")
 *   --design-url  measure this URL as the DESIGN side instead of building the
 *               compiled oracle. Use a real React build — see
 *               bin/react-oracle.sh — so the design side owes nothing to the
 *               plugin's own JSX evaluation.
 *   --width     viewport width, repeatable                        (default 1280)
 *   --section   drill into one section and diff its subtree
 *   --depth     how many levels below the root to walk    (default 1, or 12 with --section)
 *   --budget    fail above this many pixels of total error        (default 0)
 *   --max-missing  fail when more design nodes than this have no counterpart
 *               on the page at any one width. Default 0 against the compiled
 *               oracle; OFF with --design-url unless given, because a real
 *               React build legitimately differs in structure (see the react
 *               stage of bin/verify-import.cjs). `off` or a negative number
 *               disables it.
 *   --max-extra    the same for page nodes the design does not have.
 *               The pixel total only sums PAIRED nodes, so a lost node adds
 *               0px — these two are what stop a total from falling while the
 *               page gets worse.
 *   --styles    also diff computed paint values — colour, weight, border,
 *               shadow, transform — on the nodes geometry already aligned.
 *               Advisory: reported, never added to the budget, because "0px"
 *               has only ever meant the boxes match. A wrong colour moves no
 *               boxes, so the geometry number is silent about it.
 *   --json      write the raw measurements here
 *   --chrome    path to a Chrome binary
 */

'use strict';

const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const SEP = String.fromCharCode(92);

function parseArgs(argv) {
	const out = { width: [], budget: 0 };
	for (let i = 0; i < argv.length; i++) {
		const key = argv[i].replace(/^--/, '');
		if (key === 'width') { out.width.push(parseInt(argv[++i], 10)); continue; }
		out[key] = argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[++i] : true;
	}
	if (!out.width.length) { out.width = [1280]; }
	out.budget = parseInt(out.budget, 10) || 0;
	/*
	 * The structure gate. Absent means "the default for this oracle", which is
	 * decided below once we know whether the design side is the compiled
	 * oracle or a real build; `off` / a negative number means never gate.
	 */
	for (const key of ['max-missing', 'max-extra']) {
		if (out[key] === undefined) { continue; }
		const n = parseInt(out[key], 10);
		out[key] = String(out[key]) === 'off' || n < 0 ? Infinity : (Number.isNaN(n) ? 0 : n);
	}
	return out;
}

const args = parseArgs(process.argv.slice(2));
// --design-url replaces the compiled oracle, so --dir is only needed without it.
if (!args.url || (!args.dir && !args['design-url'])) {
	console.error('usage: node bin/design-oracle.cjs --dir <dir> --url <live-url> [--section id] [--width 1280] [--budget 0]');
	console.error('   or: node bin/design-oracle.cjs --design-url <react-build-url> --url <live-url>');
	process.exit(2);
}

const dir = args.dir ? path.resolve(args.dir) : null;
const repo = path.resolve(__dirname, '..');
// Shallow by default so the summary stays readable; a drill-down goes deep.
const depth = parseInt(args.depth, 10) || (args.section ? 12 : 1);

function fileUrl(p) {
	return 'file:///' + p.split(SEP).join('/');
}

/*
 * Utility class names the converter may write onto a block.
 *
 * Style_Hoister moves a block's own CSS into utility classes wherever one
 * declares exactly the same thing: the DevriX theme's (shipped as
 * data/dx-utilities.php) and the plugin's own families (Utility_Classes::
 * family()). Like a dxs- class, such a name is the converter's, not the
 * design's: without this every block that gained one keyed as `div.d-flex`
 * against the design's `div`, so it read as a missing node AND an extra one
 * while the geometry was 0px (1216 of each on one page, 10 of 426 paired).
 *
 * The rule is deliberately narrow, so a real loss still shows:
 *  - it applies on the PAGE side only; the design side is keyed as before;
 *  - a name is ignored only when NO element of the design DOM carries it, so
 *    a Tailwind design's own `flex`/`p-4`/`hidden` (all DevriX names too) keep
 *    keying on both sides, and a page that dropped one is still missing it;
 *  - removing a node still removes it: only the class list of a node the page
 *    HAS is read differently.
 *
 * The theme's names are read from the data file itself rather than through
 * PHP, so the oracle keeps running without a PHP binary. FAMILY mirrors
 * Utility_Classes::parse_family() + KEYWORDS; being a superset is harmless,
 * because a name the design uses is never ignored.
 */
const UTILITY_FAMILY = /^(?:fw-[1-9]00|text-\d{1,3}(?:-\d{1,4})?|lh-\d{1,2}(?:-\d{1,4})?|ls-\d{1,3}(?:-\d{1,4})?(?:em|px)|rounded-\d{1,4}px|rounded-circle|(?:max-w|min-h|min-w|w|h)-\d{1,5}px|(?:text|bg|shadow)-dxai-[a-z0-9]+(?:-[a-z0-9]+)*|whitespace-[a-z-]+|list-style-none|cursor-[a-z-]+|italic|not-italic|object-fit-[a-z-]+|text-overflow-[a-z]+|pointer-events-[a-z]+)$/;
function utilityNames() {
	const file = path.join(repo, 'data', 'dx-utilities.php');
	if (!fs.existsSync(file)) { return new Set(); }
	const src = fs.readFileSync(file, 'utf8');
	const start = src.indexOf("\n'classes'=>[");
	const end = src.indexOf("\n'matchable'=>[", start + 1);
	if (start < 0 || end < 0) {
		console.error('warning: data/dx-utilities.php has no classes table; utility classes will not be ignored');
		return new Set();
	}
	return new Set([...src.slice(start + 13, end).matchAll(/'([^'\\]+)'=>\[/g)].map((m) => m[1]));
}
const UTILITY_NAMES = utilityNames();
const isUtilityName = (c) => UTILITY_NAMES.has(c) || UTILITY_FAMILY.test(c);

/**
 * Find the Tailwind CLI, installing a pinned copy beside the oracle if needed.
 * Resolves the package's own entry point rather than the .bin shim: node
 * refuses to spawn a .cmd without a shell, and a shell here would need the
 * path quoted on every platform.
 */
/**
 * Which Tailwind the design was built with, as bin/design-oracle.php recorded
 * it. Absent on an oracle built before that was written, and v4 is what those
 * all were.
 */
function tailwindTarget() {
	const marker = path.join(dir, 'oracle-tailwind.json');
	if (!fs.existsSync(marker)) { return { major: 4, config: '', cwd: '' }; }
	try {
		const parsed = JSON.parse(fs.readFileSync(marker, 'utf8'));
		return { major: Number(parsed.major) === 3 ? 3 : 4, config: parsed.config || '', cwd: parsed.cwd || '' };
	} catch (e) {
		return { major: 4, config: '', cwd: '' };
	}
}

function tailwindCli(major) {
	// v3 ships the CLI inside `tailwindcss` itself; v4 split it out.
	const relative = major === 3
		? ['tailwindcss', 'package.json']
		: ['@tailwindcss', 'cli', 'package.json'];

	const entry = () => {
		for (const root of [dir, repo]) {
			const pkg = path.join(root, 'node_modules', ...relative);
			if (!fs.existsSync(pkg)) { continue; }
			const meta = JSON.parse(fs.readFileSync(pkg, 'utf8'));
			if (major === 3 && !/^3\./.test(String(meta.version || ''))) { continue; }
			const rel = typeof meta.bin === 'string' ? meta.bin : Object.values(meta.bin || {})[0] || meta.main;
			const abs = path.join(path.dirname(pkg), rel);
			if (fs.existsSync(abs)) { return abs; }
		}
		return null;
	};

	let found = entry();
	if (!found) {
		/*
		 * v3 needs its plugins present too: the config `require`s them at load
		 * time, and `tailwindcss-animate` is in every shadcn v3 project. Without
		 * it the CLI dies resolving the config rather than producing a thin
		 * sheet, which at least fails loudly.
		 */
		const packages = major === 3
			? ['tailwindcss@3', 'tailwindcss-animate@1', 'postcss@8', 'autoprefixer@10']
			: ['@tailwindcss/cli@4', 'tailwindcss@4'];
		console.log('# installing ' + packages.join(' ') + ' into ' + dir);
		fs.writeFileSync(path.join(dir, 'package.json'), JSON.stringify({ name: 'dxai-oracle', private: true, version: '1.0.0' }));
		execFileSync(process.platform === 'win32' ? 'npm.cmd' : 'npm',
			['install', '--silent', '--no-audit', '--no-fund', ...packages],
			{ cwd: dir, stdio: 'inherit', shell: process.platform === 'win32' });
		found = entry();
	}
	if (!found) { throw new Error('tailwindcss v' + major + ' CLI not found after install'); }
	return found;
}

/** Build the oracle page: design DOM + real Tailwind output, no plugin CSS. */
function buildOracle() {
	const domPath = path.join(dir, 'oracle-dom.html');
	if (!fs.existsSync(domPath)) {
		throw new Error('missing ' + domPath + ' — run bin/design-oracle.php first');
	}
	const cssPath = path.join(dir, 'oracle.css');
	const target = tailwindTarget();
	/*
	 * v3 resolves the config's `content` globs against the working directory,
	 * so the CLI runs inside the extracted project — where `./src/**` means
	 * what the design meant by it. v4 takes its sources from `@source` in the
	 * entry file and stays put.
	 */
	const cwd = target.cwd ? path.join(dir, target.cwd) : dir;
	const argv = [
		tailwindCli(target.major),
		'-i', path.join(dir, 'tailwind-input.css'),
		'-o', cssPath,
		'--minify',
	];
	if (target.major === 3 && target.config) { argv.push('-c', target.config); }
	execFileSync(process.execPath, argv, { cwd, stdio: 'inherit' });

	const fontsPath = path.join(dir, 'oracle-fonts.css');
	const faces = fs.existsSync(fontsPath) ? fs.readFileSync(fontsPath, 'utf8') : '';
	if (!faces.includes('@font-face')) {
		console.log('# warning: no web fonts mirrored — heading heights are not comparable');
	}

	/*
	 * Refused, not warned.
	 *
	 * A warning here was invisible: bin/verify-import.cjs reads this output for
	 * `sections=` and nothing else, so a run whose oracle had no icon runtime
	 * looked normal. What it measures instead is `<i data-lucide>` against the
	 * converted page's real `<svg>` — which differ in font-style and overflow
	 * on EVERY icon. One missing 373KB CDN fetch produced 33 paint differences
	 * per width, none of them ours, and the page it happened to was reported as
	 * a regression.
	 *
	 * Only when the design actually has icons: a design with none is perfectly
	 * measurable without the runtime.
	 */
	const wantsIcons = fs.readFileSync(domPath, 'utf8').includes('data-lucide');
	const iconPath = path.join(dir, 'lucide.js');
	const hasIcons = fs.existsSync(iconPath) && fs.statSync(iconPath).size > 10000;
	if (wantsIcons && !hasIcons) {
		throw new Error(
			'the design has <i data-lucide> icons but ' + iconPath + ' is missing or truncated.\n'
			+ '   Every icon would be compared as an <i> against the page\'s <svg>, which differs\n'
			+ '   in font-style and overflow. Refusing rather than reporting that as a difference.'
		);
	}

	const page = '<!doctype html><html><head><meta charset="utf-8">'
		+ '<link rel="stylesheet" href="oracle-fonts.css">'
		+ '<link rel="stylesheet" href="oracle.css"><style>body{margin:0}</style></head><body>\n'
		+ fs.readFileSync(domPath, 'utf8')
		+ (hasIcons ? '<script src="lucide.js"></script><script>lucide.createIcons();</script>' : '')
		+ '</body></html>';
	const out = path.join(dir, 'oracle.html');
	fs.writeFileSync(out, page);
	console.log('# oracle ' + out + ' (' + fs.statSync(cssPath).size + ' B css)');
	return out;
}

/**
 * Collected in the page. Keys have to survive conversion, so drop the classes
 * WordPress adds to a block and keep only the design's own.
 */
function collector(sectionId, maxDepth, withPaint, rootSelector, ignoreList) {
	/*
	 * Classes WordPress or our own runtime adds. They say nothing about the
	 * design, and keeping them would stop a node matching its own counterpart.
	 *
	 * `dxai-` is a prefix rather than a list of names, and that matters for the
	 * React oracle. Against the COMPILED oracle an unstripped class of ours was
	 * harmless — that oracle's HTML also comes from Jsx_Compiler, so both sides
	 * carried it and the keys still matched. Against a real React build only our
	 * side has it, so every control the converter wires up read as a lost node
	 * AND a spurious one: `dxai-toggle-open--practice` alone accounted for a
	 * large share of a 98-lost / 307-extra report on ARA. The old list named
	 * dxai-ui and dxai-reveal and missed dxai-toggle-*, dxai-motion, dxai-cls-*
	 * and the rest.
	 *
	 * `is-*` is deliberately NOT widened past is-armed/is-in. Those two are
	 * purely ours, but `is-active` and `is-on` are the designs' own convention —
	 * their CSS styles `.rv-cs-tab.is-active` — and our runtime toggles the same
	 * names on purpose. Stripping them would hide a real difference between an
	 * active and an inactive tab.
	 */
	/*
	 * `scp<n>` and `sc-host` are the Claude Design runtime's, not the
	 * design's: support.js mints one `scp0`, `scp1`, … class per
	 * `style-hover="…"` attribute to carry the generated pseudo-class rule,
	 * and `sc-host` is the component host it wraps the template in. The
	 * converter's own hover classes are `dxai-sh-*`, already covered by
	 * `dxai-`, so without this every hoverable node keyed differently on
	 * the two sides. `sc-interp` is deliberately KEPT: it wraps every
	 * interpolated string on the design side and the converter emits the
	 * same span, so it is a design fact both sides share.
	 */
	/*
	 * The classes WordPress core GENERATES when it renders a block, as opposed
	 * to the ones a block's markup carries. Listed from WordPress 7.1.2's own
	 * source rather than from today's pages, because today's pages are all
	 * core/group + core/paragraph + core/heading + core/list and carry none of
	 * them — the survey of Elevate, GTM and H2O found only wp-block-*,
	 * is-layout-flow and alignfull. The point is the migration to core blocks:
	 * the first core/button would otherwise key as
	 * `a.wp-element-button.<design classes>` against the design's
	 * `a.<design classes>`, pair with nothing, and count as MISSING — which
	 * adds 0px, so the total FELL while the page got worse.
	 *
	 *   wp-element-          wp-element-button / -caption, which the elements
	 *                        API puts on every core/button link and caption
	 *                        (the existing `wp-elements` is the plural,
	 *                        per-block `wp-elements-<hash>` — a different class)
	 *   wp-container-        layout support: wp-container-core-group-is-layout-
	 *                        <hash>, wp-container-content-<hash>, and position
	 *                        support's wp-container-<n>
	 *   wp-states-           the 7.x state (hover/focus) support's
	 *                        wp-states-<8 hex>
	 *   wp-custom-css-       per-block custom CSS, wp-custom-css-<hash>
	 *   wp-settings-         per-block settings, wp-settings-<md5>
	 *   wp-duotone-          duotone filters
	 *   wp-lightbox-         core/image's lightbox container
	 *   is-vertical / is-horizontal / is-nowrap / is-content-justification- /
	 *   is-position-         layout and position support modifiers
	 *   items-justified- / is-responsive / open-on- / open-always /
	 *   hidden-by-default / is-menu-open
	 *                        core/navigation's own modifiers
	 *   aligncenter / alignleft / alignright / alignnone
	 *                        block alignment, next to the alignfull/alignwide
	 *                        already here
	 *   dxs-<hash>           this plugin: Style_Hoister's class for a block's own
	 *                        CSS, written in place of its style attribute
	 *
	 * Checked against all 1,956 distinct classes in the 134 compiled design
	 * DOMs under .verify/oracle-* plus the served .dc.html exports under
	 * .verify/dc-oracle: none of the additions occurs in a design. What does — and is
	 * therefore NOT here — is `is-open`, `is-active`, `is-on`, `is-next`,
	 * `is-out` (design state classes), `align-bottom` (Tailwind's
	 * vertical-align, hence no bare `align` prefix) and `size-4`/`size-5`
	 * (Tailwind), which is why core/image's `size-full`/`size-large` are left
	 * alone: `size-full` is also a Tailwind utility, and core/image puts it on
	 * the <figure>, which is transparent below anyway.
	 */
	const WP_NAMES = /^(wp-block|is-layout|has-|is-style|dxai-|alignfull|alignwide|entry-|wp-image-|wp-elements|is-armed|is-in|lucide|wp-element-|wp-container-|wp-states-|wp-custom-css-|wp-settings-|wp-duotone-|wp-lightbox-|is-vertical|is-horizontal|is-nowrap|is-content-justification-|is-position-|items-justified-|is-responsive|open-on-|open-always|hidden-by-default|is-menu-open|aligncenter|alignleft|alignright|alignnone)|^scp[0-9a-z]+$|^sc-host$|^dxs-[0-9a-z]+$/;
	/*
	 * Plus the utility names measure() found on THIS side and not in the
	 * design DOM (always empty on the design side); see UTILITY_FAMILY at the
	 * top. They are the converter's, exactly as a dxs- class is.
	 */
	const IGNORED = new Set(ignoreList || []);
	const WP = { test: (c) => WP_NAMES.test(c) || IGNORED.has(c) };
	const sig = (el) => {
		/*
		 * An id keys an element better than anything else — but only an id the
		 * design wrote. Radix mints one per instance from React's `useId`
		 * (`radix-:r0:`, `«r7»`), so against a real React build every accordion
		 * trigger and panel keyed by a counter that exists on one side only and
		 * changes between renders on that side. Those read as lost nodes while
		 * the markup was identical.
		 */
		/*
		 * React 18's useId emits `:r0:`; React 19 emits `_R_0_` / `_r_0_`, and
		 * the shadcn Form builds `${id}-form-item` on top of it — so the ids
		 * are both generated AND suffixed. Covering only the colon form left
		 * every React 19 form field keyed by a counter.
		 */
		/*
		 * `dxai-id-` is ours: the converter mints its own ids where the design
		 * used useId(), because the VALUE cannot be reproduced and only the
		 * relationship matters — a label's `for` agreeing with its control's
		 * `id`. Both sides' ids are generated, so neither can key a node.
		 */
		const GENERATED = /^(?:radix-|headlessui-|react-aria|:r[0-9a-z]+:|«r|_[Rr]_[0-9a-z]*_|dxai-id-|dxai-rx-)/;
		// An id the splitter moved off an unwrapped <main> onto its first band
		// (data-dxai-anchor) is the <main>'s, not the band's: the band keys as
		// it does on the design side, where it had no id.
		if (el.id && !GENERATED.test(el.id) && el.getAttribute('data-dxai-anchor') !== el.id) { return '#' + el.id; }
		// core/image moves the design's classes onto the <figure> and leaves the
		// <img> bare, so classes cannot key a picture. Its file can — minus the
		// numeric suffix WordPress appends when the same name is uploaded twice,
		// which differs between the oracle's copy and the page's.
		if (el.tagName === 'IMG') {
			/*
			 * Decoded and dash-joined before keying: a design writes
			 * `uploads/h2o%20away%20logo-white.png` for a file called
			 * "h2o away logo-white.png", and WordPress's sanitize_file_name()
			 * uploads that as `h2o-away-logo-white.png`. Same picture, two
			 * spellings — read as one node lost and one extra.
			 */
			let file = (el.getAttribute('src') || '').split('/').pop();
			try { file = decodeURIComponent(file); } catch (e) { /* keep as written */ }
			file = file.replace(/\s+/g, '-');
			// Strip every trailing numeric group, not one. The designs' own
			// names end in numbers too — mario-peshev-2.png,
			// badge-clutch-global-2024.webp — so stripping a fixed number of
			// suffixes left the two sides one level apart and the same picture
			// read as a missing node on one side and a new node on the other.
			return 'img:' + file.replace(/(?:-\d+)+(\.[a-z0-9]+)$/i, '$1');
		}
		const cls = [...el.classList].filter((c) => !WP.test(c)).sort().join('.');
		// The icon runtime replaces each <i data-lucide> with an <svg> that
		// carries its own generated classes. Same icon, different element — key
		// both sides the same way or every icon reads as a missing node.
		let tag = el.tagName.toLowerCase();
		if (el.tagName === 'svg' || el.hasAttribute('data-lucide')) {
			tag = 'icon';
		} else if (tag === 'p' || tag === 'div') {
			// A <div> holding one line of text is converted to core/paragraph,
			// which is a <p> with the same classes and the same box. Keying
			// them apart would report every one of those as a lost node; the
			// class signature still tells two different elements apart.
			tag = 'block';
		}
		return tag + (cls ? '.' + cls : '');
	};
	// Nothing here has a box, and where it sits in the tree is not a design fact.
	/*
	 * Not rendered is not comparable.
	 *
	 * A `display:none` subtree paints nothing on either side, and the two
	 * sides legitimately hold different things inside one. Radix empties a
	 * closed accordion panel and an inactive tab panel — `children: isOpen &&
	 * children` — while a converted page KEEPS that content hidden, because a
	 * CMS page whose day-2 tab is empty has lost the text for good, in the
	 * editor as well as on the front end. Counting those nodes reported 74
	 * spurious extras for content that is invisible on both sides.
	 *
	 * Symmetric, so it hides nothing real: a node the design shows and the
	 * page hides is still MISSING, and one the page shows and the design hides
	 * is still EXTRA. Only the mutually-invisible pairs drop out.
	 */
	const unrendered = (el) => {
		const style = getComputedStyle(el);
		return style.display === 'none' || style.visibility === 'hidden' || el.hasAttribute('hidden');
	};
	const skip = (el) => ['STYLE', 'SCRIPT', 'TEMPLATE', 'LINK'].includes(el.tagName) || unrendered(el);
	// An icon is one node. Its paths and rects are the icon set's business.
	const isIcon = (el) => el.tagName === 'svg' || el.hasAttribute('data-lucide');
	/*
	 * The design's outermost element. On a converted page and on the compiled
	 * oracle that is `.dxai-ui` — Jsx_Compiler hoists the design root's own
	 * class and style onto it, so the wrapper IS the design's outer div.
	 *
	 * A real React build has no such wrapper: TanStack's RootShell renders
	 * <body> and the route's own outermost element sits directly inside it. So
	 * the React oracle passes `body > div` and the two roots line up — same
	 * element in the design, named differently by each host.
	 */
	const selector = rootSelector || '.dxai-ui';
	const root = document.querySelector(selector);
	if (!root) { return { error: 'no ' + selector + ' root' }; }

	// Reveal animations park content at opacity 0 / translated; settle them so
	// geometry reflects the resting state on both sides.
	root.querySelectorAll('.dxai-reveal').forEach((el) => {
		el.classList.add('is-in');
		el.classList.remove('is-armed');
	});

	// A bare word is an id; anything else is taken as a selector, so a section
	// the design left unnamed can still be drilled into.
	const sel = !sectionId ? null
		: (/^[A-Za-z][\w-]*$/.test(sectionId) ? '#' + sectionId : sectionId);
	const scope = sel ? root.querySelector(sel) : root;
	if (!scope) { return { error: 'no match for ' + sel }; }

	// core/template-part renders its own wrapper element around the saved
	// content. It carries no design classes and no box of its own, so treat it
	// as transparent rather than as a node the design failed to produce.
	// Transparent on purpose: core/template-part's wrapper element, the
	// <figure> core/image adds, and a <main> with no styling of its own, which
	// the splitter unwraps so header / bands / footer become siblings. A <main>
	// that carries a class is kept by the splitter, so it is kept here too.
	const transparent = (c) => c.classList.contains('wp-block-template-part')
		|| c.classList.contains('wp-block-image')
		|| c.classList.contains('wp-block-table')
		// A style of only display:block is no style: a <main> is block already,
		// and the splitter unwraps such a <main> too (Design_Html::is_plain_main).
		|| (c.tagName === 'MAIN' && !c.getAttribute('class')
			&& ['', 'display:block'].includes(String(c.getAttribute('style') || '').replace(/[\s;]+/g, '').toLowerCase()));

	/*
	 * Core block wrappers that exist only because a core block renders them.
	 *
	 * The migration to core blocks puts elements between a design node and its
	 * parent that the design never had: core/buttons renders
	 * `div.wp-block-buttons > div.wp-block-button > a`, and core/navigation
	 * renders `nav > div.__responsive-container > div.__responsive-close >
	 * div.__responsive-dialog > div.__responsive-container-content >
	 * ul.__container > li.wp-block-navigation-item > a > span.…__label`. Keys
	 * are PATHS, so one such div renames every node beneath it; every one of
	 * those then counts as MISSING, and a missing node adds 0px. That is the
	 * trap: the total falls while the page gets worse.
	 *
	 * Measured on a fixture — a design with `nav > a×3` and `div.row >
	 * a.btn×2`, against the same content in core/navigation and core/buttons
	 * markup with the plumbing laid out as `display:contents`: before this and
	 * the class-strip additions, 6 of its 12 design nodes read MISSING (all
	 * five links and the nav) with 20 page nodes EXTRA; after, 12/12 pair.
	 *
	 * Passed through only while the wrapper carries NO class of its own after
	 * the strip rule above. A wrapper that does carry design classes is the
	 * design's element in core clothing — a design's `div.flex.gap-3` button
	 * row migrated to core/buttons with className "flex gap-3" — and dropping
	 * it would lift its children a level and unpair all of them. That is the
	 * same rule the <main> case above follows: kept when it carries a class.
	 *
	 * Lifted recursively, because these nest (buttons > button, and five
	 * levels inside core/navigation) — but only these. The older transparent
	 * wrappers above stay one level deep, exactly as before, so no key on a
	 * page that has none of these blocks changes; today that is every page.
	 *
	 * A wrapper that is not rendered takes its subtree with it. Lifting through
	 * it would be wrong: `display:none` does not inherit, so a child of a
	 * hidden core/navigation overlay reads its own computed display as
	 * `block` and would be measured as present.
	 *
	 * `__label` is in the list although it holds text rather than elements:
	 * core/navigation-link wraps the link's words in it, where the design's
	 * `<a>` holds them directly. Its text is folded into the parent's in
	 * walk() below, so the link keeps its words and no stray `span` appears.
	 * The hamburger buttons (`__responsive-container-open` / `-close`) are NOT
	 * here: they are controls the visitor sees, and a design that has no such
	 * button should hear about one it gained.
	 */
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
	const coreWrapper = (c) => CORE_WRAPPERS.some((name) => c.classList.contains(name))
		&& [...c.classList].every((k) => WP.test(k));
	const lift = (c) => {
		if (!coreWrapper(c)) { return [c]; }
		return unrendered(c) ? [] : [...c.children].flatMap(lift);
	};

	const kids = (el) => [...el.children]
		.flatMap((c) => (transparent(c) ? [...c.children] : [c]))
		.flatMap(lift)
		.filter((c) => !skip(c));

	/*
	 * A node's OWN words, plus those of any core wrapper directly inside it
	 * (recursively) — see `__label` above. On a page with no core wrappers this
	 * is exactly the old expression: text nodes in order, elements contributing
	 * nothing.
	 */
	const ownText = (el) => [...el.childNodes]
		.map((n) => (n.nodeType === 3 ? n.nodeValue : (n.nodeType === 1 && coreWrapper(n) ? ownText(n) : '')))
		.join('');

	const nodes = [];
	const seen = Object.create(null);
	/*
	 * Keys are paths, and the occurrence counter is scoped to the path rather
	 * than to the document. A document-global counter meant one extra sibling
	 * anywhere renumbered every later node of that shape: on one design a
	 * three-span insertion produced eleven paired +88/-88 rows — 968px of
	 * reported error against a document that differed by 58px in total.
	 */
	/*
	 * Paint, as opposed to layout. The geometry diff answers "is the box the
	 * right size and in the right place", which is not the same question as
	 * "does it look like the design" — a wrong colour, weight or shadow moves
	 * no boxes at all. Computed values are read rather than declared ones, so
	 * the browser has already normalised colours to `rgb()` and lengths to px
	 * on both sides, and both sides are the same Chrome.
	 */
	const PAINT = [
		'color', 'background-color', 'background-image', 'opacity',
		'font-family', 'font-weight', 'font-style', 'letter-spacing', 'line-height',
		'text-align', 'text-transform', 'text-decoration-line', 'white-space',
		'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
		'border-top-color', 'border-top-style', 'border-radius',
		/*
		 * `outline-color` is deliberately absent: it resolves to currentColor
		 * on almost everything, so it duplicated `color` in every row it ever
		 * appeared in — 8 of 8 on one design — and doubled the apparent size of
		 * a colour defect.
		 */
		'box-shadow', 'text-shadow',
		'transform', 'filter', 'backdrop-filter', 'mix-blend-mode',
		'overflow-x', 'overflow-y', 'z-index', 'position', 'display',
		'justify-content', 'align-items', 'flex-direction', 'grid-template-columns',
	];

	/*
	 * Whether a node's x or width is a clock reading: something running on it
	 * or on any ancestor can move it SIDEWAYS. `animated` below is the node's
	 * OWN CSS animation and stays exactly as it was for the paint check; this
	 * is a different question, for the x/w comparison only.
	 *
	 * Inherited because the first run of x reported 42 nodes per width on
	 * DevriX Elevate and 24 on GTM Strategy Hub sideways by 1-14px, and every
	 * one was a child of a marquee (`rv-logos-track` on GTM): the parent
	 * carries the animation, the children sit still inside it.
	 *
	 * Sideways only, because the next run — counting ANY running animation or
	 * transition — excluded 505 of GTM's 875 paired nodes: the collector
	 * forces every reveal to `is-in` a moment before measuring, so each
	 * revealing section is mid-transition, and whole sections (#scope 112
	 * nodes, #deliverables 104) dropped out of the comparison. A reveal's
	 * transition is opacity plus a vertical translate, which moves no box
	 * sideways. So an animation counts only when a keyframe touches a
	 * horizontal property (left/right/inset/width/margin/padding) or a
	 * transform that is not a pure vertical translation — a marquee's
	 * translateX, an orbit's rotate, a scale-in.
	 */
	const SIDEWAYS = /^(left|right|inset|insetInline|width|minWidth|maxWidth|margin|marginLeft|marginRight|marginInline|padding|paddingLeft|paddingRight|paddingInline|transform|translate|rotate|scale)$/;
	const vertical = (property, value) => {
		const v = String(value).trim();
		if (v === '' || v === 'none') { return true; }
		if (property === 'transform') {
			return /^translateY\([^()]*\)$/.test(v)
				|| /^translate(?:3d)?\(0(?:px)?,\s*[^,()]+(?:,\s*0(?:px)?)?\)$/.test(v)
				|| /^matrix\(1,\s*0,\s*0,\s*1,\s*0,\s*[^,()]+\)$/.test(v);
		}
		if (property === 'translate') { return /^0(?:px)?(?:\s+\S+)?(?:\s+0(?:px)?)?$/.test(v); }
		return false;
	};
	const shifts = (animation) => {
		if (animation.playState !== 'running') { return false; }
		let frames;
		try { frames = animation.effect.getKeyframes(); } catch (e) { return true; }
		return frames.some((frame) => Object.keys(frame).some((p) => SIDEWAYS.test(p) && !vertical(p, frame[p])));
	};
	const inMotion = (el) => typeof el.getAnimations === 'function' && el.getAnimations().some(shifts);
	let scopeMoving = false;
	for (let up = scope; up && up !== document.documentElement; up = up.parentElement) {
		if (inMotion(up)) { scopeMoving = true; break; }
	}

	const walk = (el, depth, parent, carried) => {
		const box = el.getBoundingClientRect();
		const style = getComputedStyle(el);
		const moving = Boolean(carried) || inMotion(el);
		const label = sig(el);
		const path = parent + '/' + label;
		const n = (seen[path] = (seen[path] || 0) + 1);
		const paint = {};
		if (withPaint) {
			for (const property of PAINT) {
				paint[property] = style.getPropertyValue(property);
			}
		}
		nodes.push({
			key: path + (n > 1 ? '[' + n + ']' : ''),
			label: label + (n > 1 ? '[' + n + ']' : ''),
			depth,
			h: Math.round(box.height),
			w: Math.round(box.width),
			/*
			 * Where the box starts horizontally, in viewport pixels. Nothing read
			 * this before: the budget sums heights, and a node pushed sideways —
			 * a button row that lost its justify-content inside a core/buttons
			 * wrapper, a nav that stopped centring — keeps every height and
			 * scored 0px. Viewport rather than root-relative on purpose: both
			 * sides render at the same viewport width with the root at the left
			 * edge (body{margin:0} on the oracle, the blank template on the
			 * page), and subtracting the root's left would hide a page whose
			 * whole root moved.
			 */
			x: Math.round(box.left),
			xRaw: box.left,
			mt: Math.round(parseFloat(style.marginTop)) || 0,
			pt: Math.round(parseFloat(style.paddingTop)) || 0,
			/*
			 * The same three unrounded, because the box comparison used to diff
			 * the ROUNDED integers and a node sitting on a half-pixel boundary
			 * then read as a 1px defect. DevriX Elevate showed it plainly: one
			 * span reported `w 281→280` at 1280px and `w 280→281` at 768px —
			 * the same node, the difference flipping direction with the
			 * viewport, which is rounding rather than fidelity. Keeping the
			 * rounded fields for display and comparing these for truth means a
			 * real 1px difference still fails while a rounding boundary does
			 * not.
			 */
			wRaw: box.width,
			mtRaw: parseFloat(style.marginTop) || 0,
			ptRaw: parseFloat(style.paddingTop) || 0,
			gap: style.rowGap,
			fs: style.fontSize,
			/*
			 * Whether our runtime governs this node's entrance, so the paint
			 * comparison can tell "hidden until scrolled in" from "wrong".
			 * `closest` rather than the node's own class: a reveal hides its
			 * whole subtree, and testing only the node itself let four
			 * `li.rv-gap-row` children of a revealing list report as opacity
			 * and transform defects.
			 */
			reveal: el.closest('.dxai-reveal, [data-reveal]') !== null,
			// A CSS animation is mid-flight on both sides at different phases,
			// so its transform is a clock reading, not a fidelity fact.
			animated: style.animationName !== 'none' && style.animationName !== '',
			moving,
			/*
			 * The node's OWN text — direct child text nodes only, not its
			 * descendants'. Nothing compared rendered text before this: the
			 * collectors captured geometry and computed paint, so a wrong VALUE
			 * passed every check. Two shipped that way this session — a progress
			 * label reading `Math%` where the design shows a percentage, and a
			 * footer reading `© DevriX` with the year missing entirely. Both
			 * were 0px, both were invisible.
			 *
			 * Own text rather than textContent so a difference is reported once,
			 * on the node that actually holds it, instead of on every ancestor
			 * up to the root.
			 *
			 * Whitespace is collapsed because the two sides legitimately differ
			 * in indentation, and entities are already decoded — textContent
			 * gives characters, so `&gt;` and `>` compare equal, which is what
			 * a reader sees.
			 *
			 * `counting` marks text our own runtime animates. A countup is
			 * literally a different number on each side at any instant, so its
			 * text is a clock reading in the same way `animated` transforms are.
			 */
			text: ownText(el)
				.replace(/\s+/g, ' ')
				.trim(),
			counting: el.closest('[data-countup], [data-to]') !== null,
			/*
			 * An image this side could not load. It keeps naturalWidth 0 and
			 * the browser lays it out from the width/height attributes, which
			 * is a different box from the file's own ratio — so any node whose
			 * height depends on it is measuring an asset, not a conversion.
			 * A design served from a CDN path a local build cannot reproduce
			 * (Lovable's `/__l5e/assets-v1/…`) hits this on every run.
			 */
			unloaded: (el.tagName === 'IMG' ? [el] : [...el.querySelectorAll('img')])
				.some((i) => i.complete && i.naturalWidth === 0),
			paint,
		});
		if (depth < maxDepth && !isIcon(el)) {
			kids(el).forEach((c) => walk(c, depth + 1, path, moving));
		}
	};
	kids(scope).forEach((c) => walk(c, 1, '', scopeMoving));

	return { doc: document.documentElement.scrollHeight, nodes };
}

(async () => {
	const puppeteer = require(path.join(repo, 'node_modules', 'puppeteer-core'));
	// The design side. A real React build is measured as-is; otherwise the
	// compiled oracle is assembled from the ZIP first.
	const designUrl = args['design-url'] ? String(args['design-url']) : fileUrl(buildOracle());
	console.log('# design side: ' + (args['design-url'] ? 'REAL React build — ' + designUrl : 'compiled oracle'));

	/*
	 * Which element is "the design root" depends on the host, not the design.
	 * The converted page and the compiled oracle both wrap it in `.dxai-ui`; a
	 * real React build has no wrapper, so the route's own outermost element
	 * sits directly under <body>. `--design-root` overrides the default for a
	 * design whose shell nests deeper.
	 */
	const designRoot = args['design-root'] ? String(args['design-root']) : 'body > div';
	const rootFor = (url) => (args['design-url'] && url === designUrl ? designRoot : '.dxai-ui');
	// Every class the design DOM carries, from the latest design-side measure().
	let designClasses = new Set();
	let ignoreReported = false;

	const chrome = args.chrome || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
	const browser = await puppeteer.launch({
		executablePath: chrome,
		headless: 'shell',
		args: ['--no-sandbox', '--allow-file-access-from-files'],
	});

	/*
	 * A real React build renders its scroll reveals in the PRE-reveal state:
	 * the IntersectionObserver that flips them has not fired for anything below
	 * the fold, so a section sits at `opacity-0 translate-y-8`. The converted
	 * page is settled — the compiler resolves reveal flags to their visible
	 * state and the collector forces `.dxai-reveal` to `is-in`. Comparing those
	 * two states reports the whole revealed subtree as missing on one side and
	 * spurious on the other: on ARA one 294px section plus its four KPI cards
	 * and their labels accounted for most of a 45-node "missing" report, none of
	 * it a real difference.
	 *
	 * So the design side is scrolled through its own height to let every
	 * observer fire, then returned to the top. Reveals are one-shot, so what is
	 * left is the resting state both sides are meant to be compared in.
	 *
	 * ONLY the design side. Scrolling the converted page was tried during the
	 * reveal work and made things worse — 0px became 123px — because its own
	 * runtime re-arms on scroll. That side is already settled by the collector,
	 * which is the cheaper and more reliable route.
	 */
	const settleReveals = async (page) => {
		await page.evaluate(async () => {
			const step = Math.max(200, Math.floor(window.innerHeight * 0.8));
			const end = document.documentElement.scrollHeight;
			for (let y = 0; y < end + step; y += step) {
				window.scrollTo(0, y);
				// One frame per step is enough for an IntersectionObserver to
				// fire; it does not wait for the transition to finish.
				await new Promise((r) => requestAnimationFrame(() => r()));
			}
			window.scrollTo(0, 0);
			// Now let the transitions themselves run out. The designs use
			// durations up to 700ms (`duration-700`), so this has to outlast
			// the slowest one rather than one frame.
			await new Promise((r) => setTimeout(r, 1200));
		});
	};

	const measure = async (url, width) => {
		const page = await browser.newPage();
		await page.setViewport({ width, height: 900 });
		// Neither 'load' nor 'networkidle' is reliable here: the converted page
		// keeps a reveal timer running and a single slow asset holds the load
		// event open indefinitely. Wait instead for exactly what moves geometry
		// — the web fonts and the images — and cap the wait.
		// A local dev server stalls a request now and then; one retry is enough
		// to keep a long measuring run from dying on it.
		try {
			await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
		} catch (e) {
			console.log('# retrying ' + url);
			await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
		}

		/*
		 * A design served by its own runtime — bin/dc-oracle.cjs, or a
		 * client-rendered SPA from bin/react-oracle.sh — has no DOM at
		 * domcontentloaded: the runtime renders after React has loaded. The
		 * font and quiet waits below would then settle on an empty shell and
		 * every design node would read as lost. So the design side waits for
		 * its root to exist with a box and words in it, and fails loudly when
		 * that never happens — the same way the collector fails on a root it
		 * cannot find, rather than measuring nothing and calling it a design.
		 */
		if (args['design-url'] && url === designUrl) {
			const rootSel = rootFor(url);
			const appeared = await page.waitForFunction((sel) => {
				const el = document.querySelector(sel);
				return Boolean(el) && el.getBoundingClientRect().height > 0 && (el.innerText || '').trim().length > 0;
			}, { timeout: 30000, polling: 250 }, rootSel).then(() => true, () => false);
			if (!appeared) {
				console.error('error: oracle=no ' + rootSel + ' root rendered within 30s at ' + url + ' live=not measured');
				await browser.close();
				process.exit(1);
			}
		}
		await page.evaluate((ms) => {
			// Force lazy images to load. Below the fold they stay `complete:
			// false` forever in a viewport that never scrolls, and an image
			// with no intrinsic box yet measures 0px tall — which read as the
			// design wanting nothing there. Both sides get the same treatment.
			document.querySelectorAll('img[loading="lazy"]').forEach((i) => {
				i.loading = 'eager';
			});
			const settled = Promise.all([
				document.fonts.ready,
				...[...document.images].filter((i) => !i.complete)
					.map((i) => new Promise((r) => { i.addEventListener('load', r); i.addEventListener('error', r); })),
			]);
			return Promise.race([settled, new Promise((r) => setTimeout(r, ms))]);
		}, 15000).catch(() => {});

		/*
		 * Wait for the fonts a second time. `document.fonts.ready` resolves for
		 * the faces requested SO FAR, and a face is only requested when a glyph
		 * first needs it — so the layout pass that follows the first await can
		 * itself request a new weight, and measuring then catches the fallback
		 * metrics. It showed up as one bold heading on ARA measuring 22px
		 * instead of 23px at 390px, in roughly half of runs, which failed the
		 * whole suite twice for no defect at all.
		 *
		 * Forcing a reflow between the two awaits is what makes the second one
		 * meaningful: it guarantees the layout that requests the late face has
		 * happened before we ask again.
		 */
		await page.evaluate((ms) => {
			void document.documentElement.offsetHeight;
			const again = document.fonts.ready.then(() => new Promise((r) => requestAnimationFrame(() => r())));
			return Promise.race([again, new Promise((r) => setTimeout(r, ms))]);
		}, 5000).catch(() => {});

		/*
		 * Deliberately NOT scrolled. Scrolling to settle the reveals and
		 * count-ups was tried and reverted: it fires everything else the page
		 * starts on scroll too — carousels advance, a sticky bar appears — and
		 * the oracle is a static DOM with no state to match any of it. Two
		 * designs went from 0px to 123px of height error, which is a worse
		 * trade than the artifact it removed. The measurement point is the
		 * page at rest, and the price is that a reveal's opacity/transform and
		 * a counter's initial text differ from the design's finished markup.
		 * Those are normalised in the comparison instead.
		 *
		 * Then wait for the DOM to stop moving. Scripts that run on their own
		 * schedule — WordPress's emoji replacement is the one that caught us —
		 * rewrite nodes after the fonts and images have settled, and measuring
		 * mid-rewrite produced a 2px error that came and went between runs.
		 */
		await page.evaluate((quiet, cap) => new Promise((done) => {
			let timer = setTimeout(finish, quiet);
			const obs = new MutationObserver(() => {
				clearTimeout(timer);
				timer = setTimeout(finish, quiet);
			});
			obs.observe(document.body, { childList: true, subtree: true, characterData: true });
			const hard = setTimeout(finish, cap);
			function finish() {
				clearTimeout(timer);
				clearTimeout(hard);
				obs.disconnect();
				done();
			}
		// 600ms of quiet, capped at 15s. WordPress's emoji replacement fetches
		// its settings before it rewrites, and under load it landed after a
		// 400ms/6s wait — which showed up as a 2px error that came and went.
		}), 600, 15000).catch(() => {});
		// Only the React design side needs this; see settleReveals().
		if (args['design-url'] && url === designUrl) {
			await settleReveals(page).catch(() => {});
		}
		/*
		 * Utility names to ignore on this side: those the page carries and the
		 * design DOM does not (see UTILITY_FAMILY). measure() always reads the
		 * design side first at each width, so its class set is known here.
		 */
		const present = await page.evaluate(() => [...new Set([...document.querySelectorAll('[class]')].flatMap((e) => [...e.classList]))]);
		let ignore = [];
		if (url === designUrl) {
			designClasses = new Set(present);
		} else {
			ignore = present.filter((c) => isUtilityName(c) && !designClasses.has(c));
			if (!ignoreReported) {
				ignoreReported = true;
				console.log('# utility classes ignored on the page side: ' + ignore.length
					+ ' (converter-placed, absent from the design DOM; ' + UTILITY_NAMES.size + ' theme names known)');
			}
		}
		const data = await page.evaluate(collector, args.section || null, depth, Boolean(args.styles), rootFor(url), ignore);
		await page.close();
		return data;
	};

	/*
	 * The structure gate's limits.
	 *
	 * On by default against the COMPILED oracle, where both sides come from
	 * the same Jsx_Compiler output and a node on one side only is a real loss
	 * or a real addition. Measured the day this went in: GTM Strategy Hub
	 * scored 0px at all three widths while its design header and footer
	 * (20 nodes at 1280px: header.rv-header, its nav, logo and CTA) were
	 * absent from the page and 21 nodes of a different header and footer (a
	 * breadcrumb `ol`, `header.bg-background.border-b`) stood in their place
	 * — a 0px pass, exit 0, for a page with the wrong header.
	 *
	 * Off by default with --design-url, because bin/verify-import.cjs's react
	 * stage decides deliberately that structure does not gate there (a real
	 * React build legitimately materialises less than a CMS page keeps) and
	 * only checks this script's exit code. Its geometry stage, which also
	 * uses --design-url for Claude Design exports, already gates structure
	 * itself under --strict. Pass --max-missing / --max-extra to gate anyway.
	 */
	const gate = {
		missing: args['max-missing'] !== undefined ? args['max-missing'] : (args['design-url'] ? Infinity : 0),
		extra: args['max-extra'] !== undefined ? args['max-extra'] : (args['design-url'] ? Infinity : 0),
	};
	const limit = (n) => (n === Infinity ? 'off' : '≤ ' + n);
	const structure = { missing: 0, extra: 0, worstMissing: 0, worstExtra: 0 };
	const across = { xOff: 0, xSum: 0, wOff: 0, wSum: 0 };

	const report = { url: args.url, section: args.section || null, widths: {} };
	let total = 0;

	for (const width of args.width) {
		const want = await measure(designUrl, width);
		const got = await measure(args.url, width);
		if (want.error || got.error) {
			console.error('error: oracle=' + (want.error || 'ok') + ' live=' + (got.error || 'ok'));
			process.exit(1);
		}

		const live = new Map(got.nodes.map((n) => [n.key, n]));
		const rows = [];
		let sum = 0;
		for (const n of want.nodes) {
			const m = live.get(n.key);
			const delta = m ? m.h - n.h : null;
			// An asset the design side never loaded cannot be compared: see
			// `unloaded` in measure().
			const assetGap = Boolean(n.unloaded) && delta !== null && delta !== 0;
			if (delta !== null && !assetGap) { sum += Math.abs(delta); }
			rows.push({
				key: n.key, label: n.label, depth: n.depth,
				want: n.h, got: m ? m.h : null, delta, missing: !m, assetGap,
				/*
				 * The budget has only ever counted height. The other four were
				 * collected for reading a failure, never compared — so a node
				 * half the design's width, or with the wrong padding, passed as
				 * "0px". They are diffed here and reported on their own line,
				 * outside the budget until the numbers are known.
				 */
				/*
				 * `w`, `mt` and `pt` are compared on their UNROUNDED values
				 * with a half-pixel tolerance; `gap` and `fs` stay exact
				 * because they are computed strings, not measured boxes.
				 *
				 * The tolerance is not there to make a number pass. It is there
				 * because the rounded comparison measured the wrong thing: a
				 * node at a half-pixel boundary reported a 1px difference whose
				 * SIGN changed with the viewport. Anything genuinely a pixel
				 * apart is still 1.0 away and still fails.
				 */
				box: m
					? ['w', 'mt', 'pt', 'gap', 'fs']
						.filter((k) => {
							const raw = k + 'Raw';
							if (typeof n[raw] === 'number' && typeof m[raw] === 'number') {
								return Math.abs(n[raw] - m[raw]) > 0.5;
							}
							return String(n[k]) !== String(m[k]);
						})
						.map((k) => {
							const raw = k + 'Raw';
							// Show the sub-pixel values when the rounded ones
							// would make a real difference look like nothing.
							return typeof n[raw] === 'number' && typeof m[raw] === 'number'
								? k + ' ' + Math.round(n[raw] * 100) / 100 + '→' + Math.round(m[raw] * 100) / 100
								: k + ' ' + n[k] + '→' + m[k];
						})
					: [],
				/*
				 * x and width as numbers on every paired row, so the JSON can
				 * be summed and diffed rather than parsed out of the `box`
				 * strings. Deltas are taken on the unrounded boxes for the same
				 * half-pixel reason as `box` above.
				 *
				 * `clock` marks a node whose box is a reading of an animation
				 * rather than of the layout: something running on it or on an
				 * ancestor (`moving` in the collector) moves it sideways with
				 * every frame, and the two sides are measured at different phases.
				 * The paint check already skips `transform` on animated nodes
				 * for that reason; the x/w summary skips these.
				 */
				x: m ? { want: n.x, got: m.x, delta: Math.round((m.xRaw - n.xRaw) * 100) / 100 } : null,
				w: m ? { want: n.w, got: m.w, delta: Math.round((m.wRaw - n.wRaw) * 100) / 100 } : null,
				clock: n.moving === true || Boolean(m && m.moving === true),
				unloaded: Boolean(n.unloaded),
			});
		}
		/*
		 * Rendered text, as its own channel.
		 *
		 * Neither geometry nor paint can see a wrong VALUE. A progress label
		 * reading `Math%` instead of a percentage, and a footer reading
		 * `© DevriX` with the year missing, both measured 0px with no paint
		 * difference and passed every check in the suite. Words are the thing a
		 * reader actually reads, so they get compared like anything else.
		 *
		 * Skipped where the two sides cannot agree by construction: a countup
		 * is a different number on each side at any instant. Not skipped for
		 * reveals — a revealing node's TEXT is the same whether or not it has
		 * scrolled in; only its opacity and transform differ.
		 */
		const textDiffs = [];
		for (const n of want.nodes) {
			const m = live.get(n.key);
			if (!m) { continue; }
			if (n.counting === true || m.counting === true) { continue; }
			if ((n.text || '') === (m.text || '')) { continue; }
			textDiffs.push({ key: n.key, label: n.label, depth: n.depth, want: n.text || '', got: m.text || '' });
		}
		console.log('  text: ' + textDiffs.length + ' node(s) whose rendered words differ');
		for (const d of textDiffs.slice(0, 8)) {
			console.log('    ' + d.label.slice(0, 30).padEnd(31)
				+ JSON.stringify(d.want.slice(0, 34)) + ' → ' + JSON.stringify(d.got.slice(0, 34)));
		}

		const extra = got.nodes.filter((n) => !want.nodes.some((w) => w.key === n.key)).map((n) => n.label);

		// `text` is carried in the record rather than assigned onto it afterwards:
		// the record does not exist until here, and assigning first threw.
		report.widths[width] = { total: sum, rows, extra, text: textDiffs, docWant: want.doc, docGot: got.doc };
		total += sum;

		/*
		 * Structure goes on the width's FIRST line, next to the document height,
		 * not only in the summary under a table that can run to hundreds of
		 * rows. It is the number that says whether the pixel total below is
		 * about the whole page or only the part of it that still pairs.
		 */
		const lost = rows.filter((r) => r.missing).length;
		console.log('');
		console.log('@' + width + 'px' + (args.section ? '  #' + args.section : '') + '   doc ' + want.doc + ' → ' + got.doc
			+ '   paired ' + (rows.length - lost) + '/' + rows.length + '   MISSING ' + lost + '   EXTRA ' + extra.length);
		console.log('  ' + 'node'.padEnd(46) + 'want   got  delta');
		for (const r of rows) {
			if (r.delta === 0) { continue; }
			const label = '  '.repeat(r.depth - 1) + r.label;
			console.log('  ' + label.slice(0, 45).padEnd(46)
				+ String(r.want).padStart(4) + '  '
				+ (r.missing ? ' n/a' : String(r.got).padStart(4)) + '  '
				+ (r.missing ? 'MISSING' : (r.delta > 0 ? '+' : '') + r.delta).padStart(7));
		}
		const matched = rows.filter((r) => r.delta === 0).length;
		/*
		 * Counted and printed on its own line, because the pixel total cannot
		 * express it: a node present in the design and absent from the page
		 * contributes no delta, so a *lost* node reads as 0px. "0px total
		 * error" has therefore always meant "every node we could pair matched",
		 * never "nothing went missing". Structure is its own question and gets
		 * its own number.
		 */
		const missing = lost;
		report.widths[width].missing = missing;
		report.widths[width].extraCount = extra.length;

		const assetGaps = rows.filter((r) => r.assetGap);
		console.log('  ' + matched + '/' + rows.length + ' exact by height, ' + sum + 'px total error');
		if (assetGaps.length) {
			console.log('  assets: ' + assetGaps.length + ' node(s) excluded — the design side could not load an image there ('
				+ assetGaps.reduce((n, r) => n + Math.abs(r.delta), 0) + 'px), e.g. ' + assetGaps[0].label.slice(0, 40));
		}
		console.log('  structure: ' + missing + ' node(s) in the design and not the page, '
			+ extra.length + ' node(s) only in the page');
		/*
		 * The gate, per width. Worded so it cannot be mistaken for the line
		 * above, which bin/verify-import.cjs parses by its exact phrasing.
		 */
		structure.missing += missing;
		structure.extra += extra.length;
		structure.worstMissing = Math.max(structure.worstMissing, missing);
		structure.worstExtra = Math.max(structure.worstExtra, extra.length);
		if (missing > gate.missing || extra.length > gate.extra) {
			console.log('  STRUCTURE GATE FAILS here: ' + missing + ' missing (gate ' + limit(gate.missing) + '), '
				+ extra.length + ' extra (gate ' + limit(gate.extra) + ') — an unpaired node adds 0px, so the height total cannot see it');
		}

		const boxed = rows.filter((r) => r.box && r.box.length);
		const byMetric = new Map();
		for (const r of boxed) {
			for (const d of r.box) {
				const metric = d.split(' ')[0];
				byMetric.set(metric, (byMetric.get(metric) || 0) + 1);
			}
		}
		console.log('  box: ' + boxed.length + ' node(s) differ on a metric the budget ignores'
			+ (byMetric.size ? '  (' + [...byMetric].map(([k, n]) => k + ' ' + n).join(', ') + ')' : ''));
		for (const r of boxed.slice(0, 8)) {
			console.log('    ' + r.label.slice(0, 34).padEnd(35) + r.box.slice(0, 3).join('  '));
		}
		if (extra.length) {
			console.log('    only in page: ' + extra.slice(0, 8).join(', ').slice(0, 150));
		}

		/*
		 * Paint, reported separately and never added to the pixel budget. A
		 * wrong colour or weight moves no boxes, so the geometry number cannot
		 * see it — but this comparison has not earned the right to gate a build
		 * yet, so it is opt-in and advisory until its false-positive rate is
		 * known. Treating an unproven check as a gate is how a suite stops
		 * being believed.
		 */
		if (args.styles) {
			/*
			 * Three normalisations, each for a difference that is real in the
			 * text and invisible on screen. Without them the check reported
			 * 133 differences across the seven designs and only 4 of them
			 * were about how the page looks.
			 */
			const same = (property, a, b) => {
				if (a === b) { return true; }

				/*
				 * A colour reaches the two sides by different routes — one
				 * through the design's own `oklch()`, one through our
				 * `color-mix()` — and Chrome prints the result to different
				 * precision: `oklab(0.964306 0.000165164 …)` against
				 * `oklab(0.9643 0.000209212 …)`. Same colour, 24 reported
				 * differences.
				 */
				const round = (s) => String(s).replace(/-?\d*\.\d+/g, (v) => String(Math.round(parseFloat(v) * 1000) / 1000));
				if (round(a) === round(b)) { return true; }

				/*
				 * The oracle loads its media from disk and the page from
				 * uploads, so every background image differs by its prefix
				 * and nothing else.
				 */
				/*
				 * Asset references. The oracle loads media from disk and the
				 * page from uploads, so the same picture reads as
				 * `url("file:///C:/…/hero.jpg")` against
				 * `url("http://…/uploads/2026/09/hero-2.jpg")`. Compared by
				 * basename, minus the numeric suffixes WordPress appends when a
				 * name is uploaded twice — the same rule `sig()` already
				 * applies to `<img src>` for exactly this reason.
				 *
				 * Not restricted to background-image: `mask-image`, `filter`
				 * and `content` can all carry a url().
				 */
				if (String(a).includes('url(') || String(b).includes('url(')) {
					const files = (s) => String(s).replace(
						/url\((["']?)([^)]*?)\1\)/g,
						(_, __, u) => 'url(' + u.split(/[/\\]/).pop().replace(/(?:-\d+)+(\.[a-z0-9]+)$/i, '$1') + ')'
					);
					if (files(a) === files(b)) { return true; }
				}

				/*
				 * The same gradient written two ways. `linear-gradient(90deg,…)`
				 * and `linear-gradient(to right,…)` are one gradient; the
				 * design's source and our engine disagree only on the spelling.
				 */
				const angles = (s) => String(s)
					.replace(/\bto right\b/g, '90deg')
					.replace(/\bto left\b/g, '270deg')
					.replace(/\bto bottom\b/g, '180deg')
					.replace(/\bto top\b/g, '0deg');
				if (round(angles(a)) === round(angles(b))) { return true; }

				return false;
			};

			// A reveal is hidden and offset until it scrolls in, and we measure
			// at rest on purpose (see above), so the design's finished markup
			// can never agree with it on these two.
			const REVEAL_ONLY = new Set(['opacity', 'transform']);

			const diffs = [];
			for (const n of want.nodes) {
				const m = live.get(n.key);
				if (!m) { continue; }
				const revealing = m.reveal === true || n.reveal === true;
				const animating = m.animated === true || n.animated === true;
				for (const property of Object.keys(n.paint)) {
					if (same(property, n.paint[property], m.paint[property])) { continue; }
					if (revealing && REVEAL_ONLY.has(property)) { continue; }
					if (animating && property === 'transform') { continue; }
					diffs.push({ key: n.key, label: n.label, depth: n.depth, property, want: n.paint[property], got: m.paint[property] });
				}
			}

			const byProperty = new Map();
			for (const d of diffs) { byProperty.set(d.property, (byProperty.get(d.property) || 0) + 1); }

			report.widths[width].paint = diffs;
			console.log('  paint: ' + diffs.length + ' computed-value difference(s) over '
				+ rows.filter((r) => !r.missing).length + ' matched node(s)');
			for (const [property, n] of [...byProperty].sort((a, b) => b[1] - a[1]).slice(0, 12)) {
				const worst = diffs.find((d) => d.property === property);
				console.log('    ' + property.padEnd(24) + String(n).padStart(4)
					+ '   e.g. ' + worst.label.slice(0, 28) + ': ' + String(worst.want).slice(0, 32)
					+ ' → ' + String(worst.got).slice(0, 32));
			}
		}

		/*
		 * Horizontal geometry: where each paired box starts and how wide it is.
		 *
		 * The budget is a sum of HEIGHT differences, and that is blind to
		 * everything sideways. The migration to core blocks is mostly
		 * sideways risk — core/buttons is a flex row with its own
		 * justification and gap, core/navigation lays out its own list — so a
		 * button that drifted 40px right, or a nav that stopped centring,
		 * keeps every height and would score 0px. Width was already diffed in
		 * `box` above as a count; this gives it and x a pixel sum each, so a
		 * before/after reads as a number instead of a list.
		 *
		 * Reported, not added to the budget: the budget's meaning ("heights
		 * agree") is what every recorded baseline was measured in, and
		 * changing it would make every old number incomparable. Placed after
		 * paint so bin/verify-import.cjs's 14-line failure excerpt, which
		 * picks lines containing "→", still shows the height rows first.
		 *
		 * Skipped: nodes something is moving sideways (`clock`, see the row) and
		 * nodes whose design-side image never loaded (the same exclusion the
		 * height total makes, for the same reason — a missing asset changes
		 * widths as well as heights).
		 */
		const horizontal = rows.filter((r) => !r.missing && !r.clock && !r.unloaded);
		const xOff = horizontal.filter((r) => Math.abs(r.x.delta) > 0.5);
		const wOff = horizontal.filter((r) => Math.abs(r.w.delta) > 0.5);
		const xSum = Math.round(xOff.reduce((n, r) => n + Math.abs(r.x.delta), 0));
		const wSum = Math.round(wOff.reduce((n, r) => n + Math.abs(r.w.delta), 0));
		const clocks = rows.filter((r) => !r.missing && r.clock).length;
		report.widths[width].x = { off: xOff.length, sum: xSum };
		report.widths[width].w = { off: wOff.length, sum: wSum };
		across.xOff += xOff.length;
		across.xSum += xSum;
		across.wOff += wOff.length;
		across.wSum += wSum;
		console.log('  x/w: ' + xOff.length + ' paired node(s) start at a different x (' + xSum + 'px), '
			+ wOff.length + ' differ in width (' + wSum + 'px) — reported, outside the height budget'
			+ (clocks ? '; ' + clocks + ' node(s) moving sideways not compared' : ''));
		const sideways = [...new Set([...xOff, ...wOff])]
			.sort((a, b) => (Math.abs(b.x.delta) + Math.abs(b.w.delta)) - (Math.abs(a.x.delta) + Math.abs(a.w.delta)));
		for (const r of sideways.slice(0, 6)) {
			console.log('    ' + r.label.slice(0, 34).padEnd(35)
				+ (Math.abs(r.x.delta) > 0.5 ? 'x ' + r.x.want + '→' + r.x.got + '  ' : '')
				+ (Math.abs(r.w.delta) > 0.5 ? 'w ' + r.w.want + '→' + r.w.got : ''));
		}
	}

	await browser.close();

	/*
	 * Pass/fail is the pixel budget AND the structure gate. The budget keeps
	 * its old meaning exactly (sum of paired height differences, every width);
	 * the gate is what stops a lower total from reading as progress when it
	 * was bought by nodes that stopped pairing.
	 */
	const structureFails = structure.worstMissing > gate.missing || structure.worstExtra > gate.extra;
	report.structure = {
		missing: structure.missing, extra: structure.extra,
		worstMissing: structure.worstMissing, worstExtra: structure.worstExtra,
		gate: { missing: limit(gate.missing), extra: limit(gate.extra) },
		fails: structureFails,
	};
	report.horizontal = across;

	if (args.json) {
		fs.writeFileSync(args.json, JSON.stringify(report, null, 2));
		console.log('\n# wrote ' + args.json);
	}

	console.log('\ntotal error ' + total + 'px (budget ' + args.budget + ')');
	console.log('unpaired: ' + structure.missing + ' missing, ' + structure.extra + ' extra over '
		+ args.width.length + ' width(s); worst width ' + structure.worstMissing + ' missing / '
		+ structure.worstExtra + ' extra (gate: missing ' + limit(gate.missing) + ', extra ' + limit(gate.extra) + ')'
		+ (structureFails ? '  FAIL' : ''));
	console.log('horizontal: x off on ' + across.xOff + ' node(s), ' + across.xSum + 'px; width off on '
		+ across.wOff + ' node(s), ' + across.wSum + 'px (reported, not gated)');
	process.exit(total > args.budget || structureFails ? 1 : 0);
})().catch((e) => {
	console.error(e.stack || String(e));
	process.exit(1);
});

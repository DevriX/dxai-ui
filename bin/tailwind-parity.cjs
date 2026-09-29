#!/usr/bin/env node
/**
 * Compare our Tailwind engine against upstream, class by class.
 *
 * `Tailwind_Purger::unresolved()` reports classes the engine could not resolve.
 * Across seven real designs it reports none — and the engine was still wrong
 * about two of them, silently. `space-y-3` emitted the selector Tailwind used
 * before 4.2 (`> :not([hidden]) ~ :not([hidden])` instead of
 * `> :not(:last-child)`), which put the gap after the last child instead of
 * between the children; and `--tw-scale-*` had no `@property` registration, so
 * `scale-y-0` produced an invalid shorthand and an element the design collapsed
 * rendered at full size. Both were found by measuring pixels on a page and
 * working backwards — hours each.
 *
 * A class the engine resolves *differently* is invisible to a coverage report
 * by construction. This asks upstream directly.
 *
 *   wp eval-file bin/tailwind-parity.php "<design.zip>" <dir>
 *   node bin/tailwind-parity.cjs --dir <dir>
 *
 * Three comparisons, chosen because they need no value normalisation — we emit
 * `1.5rem` where Tailwind emits `calc(var(--spacing) * 6)`, so comparing values
 * would flag everything:
 *
 *   1. which classes each side emits at all
 *   2. the property set per class
 *   3. the selector shape per class — the combinator and pseudo suffix
 *   4. the `@property` registrations
 *
 * Options:
 *   --dir      working directory                                   (required)
 *   --zip      a design ZIP; runs the PHP emitter itself, so this is one
 *              command instead of alternating between two
 *   --surface  compare the *whole* utility surface for the design's theme,
 *              not just the classes the design happens to use — Tailwind's
 *              own design system lists ~22,000 of them, and a design
 *              exercises a few hundred, so a clean run on one design says
 *              nothing about the next one
 *   --only     narrow --surface to a regex, e.g. "^mask-" — much faster loop
 *   --explain  print every entry both sides recorded for one class
 *   --scope    the prefix our side applies (default .dxai-ui.dxai-ui--0)
 *   --show     how many differing classes to print        (default 25)
 *   --wp       WordPress root                     (required with --zip)
 *   --php      PHP binary                                 (default: php)
 *   --php-ini  a php.ini to pass as -c
 *   --wp-cli   wp-cli.phar                            (default: wp on PATH)
 */

'use strict';

const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const repo = path.resolve(__dirname, '..');

function parseArgs(argv) {
	const out = {};
	for (let i = 0; i < argv.length; i++) {
		const key = argv[i].replace(/^--/, '');
		out[key] = argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[++i] : true;
	}
	return out;
}

const args = parseArgs(process.argv.slice(2));
if (!args.dir) {
	console.error('usage: node bin/tailwind-parity.cjs --dir <dir>   (after tailwind-parity.php)');
	process.exit(2);
}

const dir = path.resolve(args.dir);
const scope = String(args.scope || '.dxai-ui.dxai-ui--0');
const show = parseInt(args.show, 10) || 25;

/** Run the PHP emitter, optionally over a given class list. */
function emitOurs(classesFile) {
	if (!args.wp) {
		console.error('--zip needs --wp <wordpress-root>');
		process.exit(2);
	}
	const cli = args['wp-cli'] ? [String(args['wp-cli'])] : [];
	const bin = String(args.php || 'php');
	const ini = args['php-ini'] ? ['-c', String(args['php-ini'])] : [];
	const argv = cli.length
		? [...ini, ...cli, 'eval-file', path.join(__dirname, 'tailwind-parity.php'), String(args.zip), dir]
		: ['eval-file', path.join(__dirname, 'tailwind-parity.php'), String(args.zip), dir];
	if (classesFile) { argv.push(classesFile); }

	const run = execFileSync(cli.length ? bin : 'wp', argv, {
		cwd: String(args.wp),
		encoding: 'utf8',
		maxBuffer: 64 * 1024 * 1024,
	});
	process.stdout.write(run.split('\n').filter((l) => l.startsWith('scope=')).join('\n') + '\n');
}

/**
 * Every utility the design's own theme can produce, from Tailwind's own design
 * system — the list its IntelliSense offers.
 *
 * Run through a helper module because the API is ESM only, and because loading
 * `tailwindcss` needs a resolver rooted where it is installed rather than where
 * this file lives.
 */
function surfaceClasses() {
	const helper = path.join(dir, 'surface.mjs');
	fs.writeFileSync(helper, [
		"import { __unstable__loadDesignSystem } from 'tailwindcss';",
		"import { readFile } from 'node:fs/promises';",
		"import { createRequire } from 'node:module';",
		'const require = createRequire(import.meta.url);',
		"const theme = await readFile(process.argv[2], 'utf8');",
		'const design = await __unstable__loadDesignSystem(',
		'  \'@import "tailwindcss";\\n\' + theme.replace(/^@import[^;]*;\\s*$/gm, "").replace(/^@source[^;]*;\\s*$/gm, ""),',
		'  { loadStylesheet: async (id, base) => {',
		"      const file = id === 'tailwindcss' ? require.resolve('tailwindcss/index.css') : id;",
		"      return { base, content: await readFile(file, 'utf8'), path: file };",
		'    } },',
		');',
		'const names = design.getClassList().map((e) => (Array.isArray(e) ? e[0] : e));',
		"process.stdout.write(names.join('\\n'));",
	].join('\n'));

	const out = execFileSync(process.execPath, [helper, path.join(dir, 'theme.css')], {
		cwd: modulesRoot(),
		encoding: 'utf8',
		maxBuffer: 64 * 1024 * 1024,
	});
	/*
	 * `--only <regex>` narrows the surface to one family. The full 24,941-class
	 * run costs a couple of minutes on our side and the same again on
	 * upstream's, which is too slow a loop when iterating on a single family.
	 * Filtering here shrinks both sides, since the same list drives both.
	 */
	const only = args.only ? new RegExp(String(args.only)) : null;
	const all = out.split('\n').filter(Boolean);
	const names = only ? all.filter((n) => only.test(n)) : all;

	const file = path.join(dir, 'surface.txt');
	fs.writeFileSync(file, names.join('\n') + '\n');
	console.log('surface                 ' + names.length + ' utilities from the design system'
		+ (only ? ' matching ' + only + ' (of ' + all.length + ')' : ''));

	return file;
}

if (args.zip) {
	emitOurs();
	if (args.surface) { emitOurs(surfaceClasses()); }
}

for (const file of ['ours.css', 'classes.txt', 'theme.css']) {
	if (!fs.existsSync(path.join(dir, file))) {
		console.error('missing ' + file + ' — pass --zip, or run bin/tailwind-parity.php first');
		process.exit(2);
	}
}

/**
 * Where to look for an install: up the tree from the design's directory, then
 * the repo. One `npm i` beside a batch of designs serves them all — the CLI
 * plus its Lightning CSS WASM is over 100 MB, and copying it per design took
 * longer than the whole comparison.
 */
function searchRoots() {
	const roots = [];
	for (let at = dir; ; at = path.dirname(at)) {
		roots.push(at);
		if (path.dirname(at) === at) { break; }
	}
	return [...roots, repo];
}

/** The directory whose `node_modules` holds tailwindcss, for a child's cwd. */
function modulesRoot() {
	for (const root of searchRoots()) {
		if (fs.existsSync(path.join(root, 'node_modules', 'tailwindcss', 'package.json'))) {
			return root;
		}
	}
	throw new Error('tailwindcss not found — install it beside ' + dir);
}

/** The Tailwind CLI's own entry point, so we ask the real compiler. */
function tailwindCli() {
	for (const root of searchRoots()) {
		const pkg = path.join(root, 'node_modules', '@tailwindcss', 'cli', 'package.json');
		if (!fs.existsSync(pkg)) { continue; }
		const meta = JSON.parse(fs.readFileSync(pkg, 'utf8'));
		const rel = typeof meta.bin === 'string' ? meta.bin : Object.values(meta.bin || {})[0] || meta.main;
		const abs = path.join(path.dirname(pkg), rel);
		if (fs.existsSync(abs)) { return abs; }
	}
	throw new Error('@tailwindcss/cli not found — install it in ' + dir);
}

/* ------------------------------------------------------------- upstream --- */

const classes = fs.readFileSync(path.join(dir, 'classes.txt'), 'utf8')
	.split('\n').map((c) => c.trim()).filter(Boolean);

/*
 * A source file holding one class per line. `@source` scanning finds them the
 * same way it finds them in a component, so arbitrary values and stacked
 * variants are parsed by Tailwind's own extractor rather than ours.
 */
const scanDir = path.join(dir, 'scan');
fs.mkdirSync(scanDir, { recursive: true });
fs.writeFileSync(path.join(scanDir, 'candidates.html'),
	classes.map((c) => '<div class="' + c.replace(/"/g, '&quot;') + '"></div>').join('\n'));

// The design's own @theme, minus the directives that pull in its file tree.
const theme = fs.readFileSync(path.join(dir, 'theme.css'), 'utf8')
	.replace(/^@import\s+["'][^"']+["'][^;]*;\s*$/gm, '')
	.replace(/^@source\s+[^;]+;\s*$/gm, '');

fs.writeFileSync(path.join(dir, 'upstream-input.css'),
	'@import "tailwindcss" source(none);\n@source "./scan";\n@custom-variant dark (&:is(.dark *));\n' + theme);

execFileSync(process.execPath,
	[tailwindCli(), '-i', path.join(dir, 'upstream-input.css'), '-o', path.join(dir, 'upstream.css')],
	{ cwd: dir, stdio: ['ignore', 'ignore', 'inherit'] });

/* -------------------------------------------------------------- parsing --- */

/** Split a stylesheet into top-level rules, skipping comments and strings. */
function rules(css) {
	const out = [];
	let i = 0;
	while (i < css.length) {
		/*
		 * Whitespace and comments between rules, skipped together and
		 * repeatedly. Checking for a comment only at the exact cursor was not
		 * enough: after a rule the cursor sits on the newline, so a comment on
		 * the next line stayed in the head, and
		 * `/* … *\/@media (min-width: 901px)` then read as a selector rather
		 * than an at-rule. Its rules were recorded with no condition, which
		 * reported 47 design classes across two designs as applying at every
		 * width when the stylesheet had them behind a breakpoint all along.
		 */
		for (let moved = true; moved; ) {
			moved = false;
			while (i < css.length && /\s/.test(css[i])) { i++; moved = true; }
			if (css[i] === '/' && css[i + 1] === '*') {
				const end = css.indexOf('*/', i + 2);
				i = end === -1 ? css.length : end + 2;
				moved = true;
			}
		}
		if (i >= css.length) { break; }
		/*
		 * A statement at-rule ends at its semicolon and has no block:
		 * upstream opens with `@layer properties;` and
		 * `@layer theme, base, components, utilities;`. Skipping to the next
		 * `{` swallowed both into the following rule's selector, and every
		 * class in that block then failed to parse.
		 */
		const at = css.slice(i).match(/^\s*@[a-z-]+[^;{]*;/i);
		if (at) { i += at[0].length; continue; }

		const brace = css.indexOf('{', i);
		if (brace === -1) { break; }
		let depth = 0;
		let end = brace;
		for (; end < css.length; end++) {
			const ch = css[end];
			if (ch === '"' || ch === "'") {
				const quote = ch;
				for (end++; end < css.length && css[end] !== quote; end++) {
					if (css[end] === '\\') { end++; }
				}
				continue;
			}
			if (ch === '{') { depth++; } else if (ch === '}' && --depth === 0) { break; }
		}
		/*
		 * Tailwind 4.2 nests, so a body holds declarations *and* rules:
		 * `.divide-y { --tw-divide-y-reverse: 0; :where(& > …) { … } }`.
		 * Everything up to the last semicolon is those declarations, not part
		 * of the nested selector — a selector never contains one.
		 */
		let head = css.slice(i, brace);
		const semi = head.lastIndexOf(';');
		if (semi !== -1) { head = head.slice(semi + 1); }
		out.push({
			head: head.trim().replace(/^[}\s]+/, ''),
			body: css.slice(brace + 1, end),
		});
		i = end + 1;
	}
	return out;
}

/** Split a comma list, ignoring commas inside (), [] or quotes. */
function commas(text) {
	const out = [];
	let depth = 0;
	let buf = '';
	for (let i = 0; i < text.length; i++) {
		const ch = text[i];
		if (ch === '"' || ch === "'") {
			const quote = ch;
			buf += ch;
			for (i++; i < text.length && text[i] !== quote; i++) {
				if (text[i] === '\\') { buf += text[i++]; }
				buf += text[i];
			}
			buf += quote;
			continue;
		}
		if (ch === '(' || ch === '[') { depth++; }
		if (ch === ')' || ch === ']') { depth--; }
		if (ch === ',' && depth === 0) { out.push(buf.trim()); buf = ''; continue; }
		buf += ch;
	}
	if (buf.trim()) { out.push(buf.trim()); }
	return out;
}

/**
 * The property names a rule declares itself, at brace depth 0 — not the ones
 * its nested rules declare, which belong to a different selector.
 */
function declarations(body) {
	const out = [];
	let depth = 0;
	let buf = '';
	const take = () => {
		const hit = buf.match(/^\s*([-a-zA-Z][\w-]*)\s*:/);
		if (hit) { out.push(hit[1].toLowerCase()); }
		buf = '';
	};
	for (let i = 0; i < body.length; i++) {
		const ch = body[i];
		if (ch === '/' && body[i + 1] === '*') {
			const end = body.indexOf('*/', i + 2);
			i = end === -1 ? body.length : end + 1;
			continue;
		}
		if (ch === '"' || ch === "'") {
			const quote = ch;
			for (i++; i < body.length && body[i] !== quote; i++) {
				if (body[i] === '\\') { i++; }
			}
			continue;
		}
		if (ch === '{') { depth++; buf = ''; continue; }
		if (ch === '}') { depth--; buf = ''; continue; }
		if (depth > 0) { continue; }
		if (ch === ';') { take(); continue; }
		buf += ch;
	}
	take();
	return out.sort();
}

/**
 * Unwrap a `:where(…)` or `:is(…)` that wraps a whole selector. Upstream zeroes
 * a nested utility's specificity that way — `:where(& > :not(:last-child))` —
 * where we append the combinator directly, so the wrapper is not a difference
 * in what gets styled.
 */
function unwrap(selector) {
	for (;;) {
		const hit = selector.match(/^:(?:where|is)\(([\s\S]*)\)$/);
		if (!hit) { return selector; }
		let depth = 0;
		for (const ch of hit[1]) {
			if (ch === '(') { depth++; } else if (ch === ')' && --depth < 0) { return selector; }
		}
		if (depth !== 0) { return selector; }
		selector = hit[1].trim();
	}
}

/** A nested rule's selector, resolved against the rule that encloses it. */
function resolve(head, parent) {
	const parents = parent ? commas(parent) : [''];
	const out = [];
	for (const part of commas(head)) {
		for (const outer of parents) {
			if (!outer) { out.push(part); continue; }
			out.push(part.includes('&') ? part.replace(/&/g, outer) : outer + ' ' + part);
		}
	}
	return out.join(',');
}

/**
 * Put a media query in one form, so the same breakpoint compares equal however
 * it is written. Upstream emits `(width >= 48rem)` and we emit
 * `(min-width: 768px)` — the same query, and leaving them as text made every
 * responsive utility differ. Anything not recognised is passed through, so an
 * actual breakpoint mismatch still shows up.
 */
function condense(condition) {
	/*
	 * A wide-gamut duplicate, which is not a fact about the class.
	 *
	 * Lightning CSS turns one authored `color-mix(in oklab, …)` declaration
	 * into an sRGB fallback plus a copy under
	 * `@supports (color: color-mix(in lab, red, red))`. We resolve a single
	 * value, so the copy has no counterpart. Blanking the condition instead of
	 * dropping the entry left a second fingerprint line carrying a subset of
	 * the same properties, which read as "upstream emits a rule we don't" on
	 * 21 classes across three designs — every one of them a design that wrote
	 * its colours with color-mix.
	 */
	if (/@supports\s*\(\s*color\s*:\s*color-mix\(/i.test(condition)
		|| /@supports\s*\(\s*background-image\s*:\s*[a-z-]*gradient\(in\s/i.test(condition)) {
		return null;
	}

	return condition
		.toLowerCase()
		.replace(/\s+/g, ' ')
		.replace(/([\d.]+)rem/g, (m, n) => (parseFloat(n) * 16) + 'px')
		.replace(/\(\s*width\s*>=\s*([^)]+?)\s*\)/g, '(min-width:$1)')
		.replace(/\(\s*width\s*<=\s*([^)]+?)\s*\)/g, '(max-width:$1)')
		.replace(/\(\s*([a-z-]+)\s*:\s*/g, '($1:')
		.replace(/\s*\)/g, ')')
		.trim();
}

/** Drop `:is(`/`:where(` and the paren each one closes, keeping the contents. */
function ungroup(selector) {
	let out = '';
	const stack = [];
	for (let i = 0; i < selector.length; i++) {
		const hit = selector.slice(i).match(/^:(?:is|where)\(/i);
		if (hit) {
			stack.push(true);
			out += ' ';
			i += hit[0].length - 1;
			continue;
		}
		if (selector[i] === '(') { stack.push(false); out += '('; continue; }
		if (selector[i] === ')') { out += stack.pop() ? ' ' : ')'; continue; }
		out += selector[i];
	}
	return out;
}

/**
 * The structural part of a selector, as a sorted token set: combinators,
 * pseudo-classes and companion classes, with the utility's own class removed.
 *
 * A set rather than the literal text, because the two sides encode the same
 * structure in different orders — we write `.group:hover .group-hover\:x` and
 * upstream writes `.group-hover\:x:is(:where(.group):hover *)`. Sorting loses
 * ancestor-versus-descendant direction, which no generated rule gets wrong,
 * and keeps what does go wrong: a missing `:hover`, `>` in place of `~`,
 * `:last-child` in place of `:first-child`.
 */
function shapeOf(remainder) {
	const text = ungroup(remainder);
	const tokens = [];
	for (let i = 0; i < text.length; i++) {
		const ch = text[i];
		if (/\s/.test(ch)) { continue; }
		// The descendant placeholder, which we write as a combinator instead.
		if (ch === '*') { continue; }
		if (ch === '>' || ch === '+' || ch === '~') { tokens.push(ch); continue; }
		const hit = text.slice(i).match(/^(?:::?[\w-]+|\.(?:\\.|[^\s.:>+~[(),])+|\[[^\]]*\])/);
		if (!hit) { continue; }
		let token = hit[0];
		i += token.length - 1;
		if (text[i + 1] === '(') {
			let depth = 0;
			let end = i + 1;
			for (; end < text.length; end++) {
				if (text[end] === '(') { depth++; } else if (text[end] === ')' && --depth === 0) { break; }
			}
			token += text.slice(i + 1, end + 1).replace(/\s+/g, ' ');
			i = end;
		}
		tokens.push(token);
	}
	return tokens.sort().join(' ');
}

/**
 * Index a stylesheet by class: for each class it styles, the property names and
 * the selector shape — what follows the class in the selector, which is where
 * a combinator like `> :not(:last-child)` lives.
 */
function index(css, prefix) {
	const byClass = new Map();
	const properties = new Set();

	/** Record one fact about every class named at the head of these selectors. */
	const attribute = (selectors, props, condition) => {
		if (!props) { return; }
		const when = condense(condition || '');
		if (when === null) { return; }
		for (const raw of selectors) {
			let selector = unwrap(raw);
			// Strip the scope our engine prefixes, in both the descendant and
			// the same-node form it emits.
			if (prefix) {
				if (!selector.includes(prefix)) { continue; }
				selector = selector.split(prefix).pop().replace(/^\s+/, '');
			}
			/*
			 * Which class in the selector the rule is *about*. Not simply the
			 * first: a variant that needs a companion element puts another
			 * class beside the utility, and the two sides order them
			 * differently — ours reads `.group:hover .group-hover\:x`, upstream
			 * `.group-hover\:x:is(:where(.group):hover *)`. Taking the first
			 * class credited all five `group-hover:*` rules to `group`, so they
			 * reported as emitted by nobody and `group` as emitted by us alone.
			 *
			 * A utility carrying a variant always has `:` in its name, and a
			 * companion marker never does, which settles it.
			 */
			/*
			 * `)` and `,` end a class name as surely as `.` or `:` — without
			 * them, the `:where(.cta-primary)` that Design_Css wraps an
			 * `@utility` in produced the name `cta-primary)`, and all 13 of one
			 * design's utilities reported as emitted by nobody.
			 */
			const found = [...selector.matchAll(/\.((?:\\.|[^\s.:>+~[(),])+)/g)]
				.map((m) => ({ text: m[0], name: m[1].replace(/\\(.)/g, '$1') }));
			if (!found.length) { continue; }
			const variants = found.filter((c) => c.name.includes(':'));
			const owner = variants.length
				? variants.reduce((a, b) => (b.name.length > a.name.length ? b : a))
				: found[0];
			const shape = shapeOf(selector.split(owner.text).join(' '));

			/*
			 * Our own runtime, not a utility. Motion_Runtime keys the reveal
			 * animation on `[class*='dxai-on-']`, which no Tailwind output can
			 * contain, so upstream has nothing to compare and the extra line
			 * reported `hidden` as divergent on four designs.
			 */
			if (shape.includes('dxai-')) { continue; }

			if (!byClass.has(owner.name)) { byClass.set(owner.name, []); }
			byClass.get(owner.name).push({ shape, props, condition: when });
		}
	};

	const walk = (list, condition, parent) => {
		for (const rule of list) {
			const head = rule.head;
			if (head.startsWith('@')) {
				const name = (head.match(/^@([a-z-]+)/i) || [, ''])[1].toLowerCase();
				if (name === 'property') {
					properties.add((head.match(/@property\s+(--[\w-]+)/) || [, head])[1]);
					continue;
				}
				if (['media', 'supports', 'layer', 'container'].includes(name)) {
					/*
					 * Conditions travel with the rule: a class that only differs
					 * inside a media query is a different fact about it. Cascade
					 * layers are the exception — upstream wraps its utilities in
					 * `@layer utilities` and we emit unlayered on purpose,
					 * because a layered rule loses to the theme's unlayered CSS.
					 * Keeping the layer in the fingerprint made all 294 classes
					 * "differ" and buried anything real.
					 */
					const layered = /^@layer\b/i.test(head);
					const inner = layered ? condition : (condition ? condition + ' ' + head : head);
					/*
					 * An at-rule does not change the enclosing selector — and
					 * in nested CSS it can hold that selector's declarations
					 * directly: upstream writes a variant as
					 * `.md\:grid { @media (width >= 48rem) { display: grid } }`.
					 * Walking only for *rules* inside it recorded nothing for
					 * every responsive and hover utility, and all 52 then read
					 * as classes upstream does not emit.
					 */
					attribute(commas(parent), declarations(rule.body).join(','), inner);
					walk(rules(rule.body), inner, parent);
				}
				continue;
			}

			/*
			 * Resolve nesting before anything else. Tailwind 4.2 emits nested
			 * CSS, so the rule that names a class often declares nothing —
			 * `.divide-y { :where(& > :not(:last-child)) { … } }`, and
			 * `.hover\:opacity-100 { &:hover { @media (hover:hover) { … } } }`.
			 * Flattening both sides to selector + property set is what makes
			 * them comparable at all.
			 */
			const selectors = commas(resolve(head, parent));

			// A rule with no declarations of its own states nothing about the
			// class; recording it produced an empty fingerprint line that read
			// as a divergence on every nested utility.
			attribute(selectors, declarations(rule.body).join(','), condition);

			walk(rules(rule.body), condition, selectors.join(','));
		}
	};

	walk(rules(css), '', '');
	return { byClass, properties };
}

const ours = index(fs.readFileSync(path.join(dir, 'ours.css'), 'utf8'), scope);
const them = index(fs.readFileSync(path.join(dir, 'upstream.css'), 'utf8'), '');

/* -------------------------------------------------------------- compare --- */

/**
 * One comparable string per class, so a difference is a difference.
 *
 * Deduplicated: our engine emits every utility twice, as `{scope}.cls` and
 * `{scope} .cls`, so a class can carry the same fact more than once. Stripping
 * the scope makes those identical, and counting them made every class differ.
 */
function fingerprint(entries) {
	return [...new Set((entries || []).map((e) => [e.condition, e.shape, e.props].join('|')))]
		.sort()
		.join('\n');
}

const relevant = classes.filter((c) => them.byClass.has(c) || ours.byClass.has(c));
const missing = [];
const extra = [];
const differ = [];

for (const name of relevant) {
	const mine = ours.byClass.get(name);
	const theirs = them.byClass.get(name);
	if (!theirs) { extra.push(name); continue; }
	if (!mine) { missing.push(name); continue; }
	if (fingerprint(mine) !== fingerprint(theirs)) {
		differ.push({ name, mine: fingerprint(mine), theirs: fingerprint(theirs) });
	}
}

/*
 * `--explain <class>` prints every entry both sides recorded for one class.
 * Twice now a divergence turned out to be this tool mis-reading a stylesheet
 * the two sides actually agreed on, and each time I found that by hand-diffing
 * grep output. Asking the index what it holds is faster and does not guess.
 */
if (typeof args.explain === 'string') {
	for (const [label, side] of [['ours', ours], ['upstream', them]]) {
		const entries = side.byClass.get(args.explain) || [];
		console.log(label + ': ' + entries.length + ' entr' + (entries.length === 1 ? 'y' : 'ies'));
		for (const e of entries) {
			console.log('  condition ' + JSON.stringify(e.condition)
				+ '\n  shape     ' + JSON.stringify(e.shape)
				+ '\n  props     ' + e.props);
		}
	}
	process.exit(0);
}

/*
 * A registration only matters for a variable we read without a fallback.
 *
 * `scale: var(--tw-scale-x) var(--tw-scale-y)` with no registration and no
 * fallback is invalid at computed-value time, and the element renders
 * unscaled — that was the original bug this harness exists for. But our
 * gradient utilities write `var(--tw-gradient-via-position,50%)` everywhere,
 * which resolves with no registration at all and keeps working where
 * `@property` is unsupported. Counting those as missing marked 9 registrations
 * on two designs as failures for being implemented more defensively than
 * upstream.
 */
const oursCss = fs.readFileSync(path.join(dir, 'ours.css'), 'utf8');
const readBare = new Set(
	[...oursCss.matchAll(/var\(\s*(--[\w-]+)\s*\)/g)].map((m) => m[1])
);

const propsMissing = [...them.properties]
	.filter((p) => !ours.properties.has(p) && readBare.has(p)).sort();
const propsUnregistered = [...them.properties]
	.filter((p) => !ours.properties.has(p) && !readBare.has(p)).sort();
const propsExtra = [...ours.properties].filter((p) => !them.properties.has(p)).sort();

console.log('classes compared        ' + relevant.length + ' of ' + classes.length + ' used');
console.log('we emit nothing for     ' + missing.length);
console.log('we emit, upstream none  ' + extra.length);
console.log('we emit differently     ' + differ.length);
console.log('@property missing       ' + propsMissing.length + (propsMissing.length ? '  ' + propsMissing.slice(0, 8).join(' ') : '')
	+ (propsUnregistered.length ? '   (' + propsUnregistered.length + ' more unregistered but always read with a fallback)' : ''));
console.log('@property extra         ' + propsExtra.length + (propsExtra.length ? '  ' + propsExtra.slice(0, 8).join(' ') : ''));

/**
 * The utility family a class belongs to: `inset-be-1/12` → `inset-be`.
 *
 * Over the whole surface a gap is never one class, it is one family times
 * forty theme values. Printing 11,813 names says nothing; printing the ~90
 * families behind them is a work list.
 */
const COLORS = new Set(['red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal',
	'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose', 'slate',
	'gray', 'grey', 'zinc', 'neutral', 'stone', 'black', 'white', 'transparent', 'current', 'inherit']);

function family(name) {
	const parts = name.replace(/^-/, '').split('/')[0].split('-');
	const value = /^(\d[\d.]*|\[.*|\(.*|none|full|auto|screen|min|max|fit|reverse|normal|initial|inherit|unset|px|dvh|dvw|lvh|lvw|svh|svw)$/;
	while (parts.length > 1 && value.test(parts[parts.length - 1])) { parts.pop(); }
	// A colour is a value too, so `text-red-500` is the `text` family. Without
	// this the report split into 2,384 families, most of them one palette entry
	// of the same gap, which buried the handful that actually matter.
	if (parts.length > 1 && COLORS.has(parts[parts.length - 1])) { parts.pop(); }
	return parts.join('-');
}

/**
 * Group class names by family, largest group first, with one member as an
 * example.
 *
 * The example is not decoration: a family label has the value collapsed off,
 * so `table` as a label means the family that `table-auto` belongs to, and
 * reads as the class `table` — which is emitted and correct. I went and checked
 * that one by hand before noticing the label was the only thing wrong.
 */
function byFamily(names) {
	const groups = new Map();
	for (const name of names) {
		const key = family(name);
		const seen = groups.get(key);
		groups.set(key, { count: (seen ? seen.count : 0) + 1, example: seen ? seen.example : name });
	}
	return [...groups].sort((a, b) => b[1].count - a[1].count || a[0].localeCompare(b[0]));
}

if (missing.length) {
	const groups = byFamily(missing);
	console.log('\nno rule emitted — ' + groups.length + ' famil'
		+ (groups.length === 1 ? 'y' : 'ies') + ', ' + missing.length + ' classes:');
	for (const [name, group] of groups.slice(0, Math.max(show, 40))) {
		console.log('  ' + name.padEnd(26) + String(group.count).padStart(4)
			+ (group.count > 1 || group.example !== name ? '   e.g. ' + group.example : ''));
	}
	if (groups.length > Math.max(show, 40)) {
		console.log('  … ' + (groups.length - Math.max(show, 40)) + ' more families');
	}
}
/*
 * Not counted as failures: a design's own class (from plain CSS or `@utility`)
 * is not a Tailwind utility, so upstream is right to emit nothing. Listed
 * because the other reason for landing here is our engine inventing a utility
 * out of a name that merely looks like one.
 */
if (extra.length) {
	console.log('\nwe emit, upstream does not:\n  ' + extra.slice(0, show).join('\n  ')
		+ (extra.length > show ? '\n  … ' + (extra.length - show) + ' more' : ''));
}
if (propsMissing.length) {
	console.log('\n@property upstream registers and we do not:\n  ' + propsMissing.join('\n  '));
}
if (differ.length) {
	console.log('\ndifferent from upstream:');
	for (const row of differ.slice(0, show)) {
		console.log('  ' + row.name);
		const mine = row.mine.split('\n');
		const theirs = row.theirs.split('\n');
		for (const line of theirs.filter((l) => !mine.includes(l))) {
			console.log('    upstream  ' + line);
		}
		for (const line of mine.filter((l) => !theirs.includes(l))) {
			console.log('    ours      ' + line);
		}
	}
	if (differ.length > show) {
		console.log('  … ' + (differ.length - show) + ' more');
	}
}

const bad = missing.length + differ.length + propsMissing.length;
console.log('\n' + (bad === 0 ? 'parity: engine agrees with upstream' : 'parity: ' + bad + ' divergence(s)'));
process.exit(bad === 0 ? 0 : 1);

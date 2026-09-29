#!/usr/bin/env node
/**
 * Does the editor's block definition agree with the server's?
 *
 * Gutenberg re-runs a block's save() on load and compares its output to the
 * stored markup. So an attribute declared on one side and not the other is not
 * a tidiness problem — it is an invalid block the moment somebody opens the
 * editor, with an "Attempt Block Recovery" button where their content was. We
 * shipped exactly that: `link_open_tag()` wrote `aria-expanded` and
 * `aria-controls` that no save() reproduced, and an earlier round of the same
 * class of mismatch invalidated 33 blocks across 5 pages.
 *
 * The two definitions are read from the running systems rather than parsed out
 * of the source, because the source agreeing with itself is not the question:
 *
 *   server  WP_Block_Type_Registry, dumped by bin/block-parity.php
 *   editor  every editor script that registers a dxai-ui block, executed here
 *           against a stubbed `wp` that records each registerBlockType call:
 *           assets/js/blocks-editor.js (the design blocks) and
 *           assets/js/site-header-editor.js / site-footer-editor.js (the
 *           dynamic chrome blocks, dxai-ui/site-header and dxai-ui/site-footer)
 *
 * Executing the bundle is what makes this exact. Each file is a plain IIFE
 * over `window.wp`, so a stub with the namespaces it touches is enough to reach
 * every registration — and unlike a regex it cannot be fooled by a definition
 * built at runtime. Each runs in a sandbox of its own, as it is its own
 * <script> in the editor, and each must register at least one block: the
 * header script returns early when `wp.serverSideRender` is missing, and a
 * stub that lacked it would otherwise have read as "one side only" again.
 *
 * Besides attributes, `supports` is compared (with WordPress's defaults for
 * the keys it treats as on unless said otherwise). The editor's settings
 * replace the server's `supports` whole, so a chrome block the server keeps
 * out of the inserter and the editor offers is a real disagreement.
 *
 *   node bin/block-parity.cjs --server <server.json>
 *   node bin/block-parity.cjs --server <server.json> --editor <a.js>[,<b.js>]
 *
 * Exits non-zero on any disagreement, naming the block and the attribute.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

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
if (!args.server) {
	console.error('usage: node bin/block-parity.cjs --server <server.json>   (from bin/block-parity.php)');
	process.exit(2);
}

/*
 * Every editor script that registers a dxai-ui block. A new one has to be
 * added here, or its block reads as "one side only" and escapes the check;
 * the report below names any server block that no source registered.
 */
const EDITOR_SOURCES = [
	path.join(repo, 'assets', 'js', 'blocks-editor.js'),
	path.join(repo, 'assets', 'js', 'site-header-editor.js'),
	path.join(repo, 'assets', 'js', 'site-footer-editor.js'),
];
const editorPaths = args.editor && args.editor !== true
	? String(args.editor).split(',').filter(Boolean).map((p) => path.resolve(p))
	: EDITOR_SOURCES;
const serverPath = path.resolve(args.server);

for (const file of [...editorPaths, serverPath]) {
	if (!fs.existsSync(file)) {
		console.error('missing ' + file);
		process.exit(2);
	}
}

/* ---------------------------------------------------------------- editor --- */

/**
 * Enough of `wp` for the bundle to run to completion.
 *
 * Every value is a callable that also behaves as an object, because the file
 * destructures components at load time and only calls them inside edit()/save()
 * — which this never invokes. A missing name would throw at the destructuring
 * and take the whole file with it, so the proxy answers to anything.
 */
function stubNamespace(record) {
	const anything = new Proxy(function stub() { return null; }, {
		get: (target, prop) => {
			if (prop === 'then') { return undefined; }
			if (!(prop in target)) { target[prop] = stubNamespace(record); }
			return target[prop];
		},
		apply: () => null,
	});
	return anything;
}

const registrations = [];
const filters = [];

function makeWp(source) {
	return {
		element: { createElement: () => null, Fragment: 'Fragment', RawHTML: 'RawHTML' },
		blocks: {
			registerBlockType: (name, settings) => {
				registrations.push({ name, settings: settings || {}, source });
				return settings;
			},
			createBlock: () => null,
		},
		hooks: {
			addFilter: (hook, ns) => { filters.push({ hook, ns }); },
			applyFilters: (hook, value) => value,
		},
		blockEditor: stubNamespace(),
		components: stubNamespace(),
		data: stubNamespace(),
		// `const { createHigherOrderComponent } = wp.compose;` sits at the top of
		// the bundle, so a missing namespace here is not a missing stub for some
		// optional call — it throws before a single block registers, and the
		// comparison reports "the editor bundle threw" instead of a parity result.
		compose: stubNamespace(),
		// The chrome blocks' previews. site-header-editor.js returns before
		// registering anything when this namespace is absent.
		serverSideRender: stubNamespace(),
		apiFetch: stubNamespace(),
		notices: stubNamespace(),
		url: stubNamespace(),
		i18n: { __: (s) => s, _x: (s) => s, _n: (s) => s, sprintf: (s) => s },
	};
}

for (const editorPath of editorPaths) {
	const wp = makeWp(editorPath);
	const before = registrations.length;
	const sandbox = { window: { wp }, wp, console, JSON, Object, Array, String, Number, Boolean, RegExp, Math, Error };
	sandbox.globalThis = sandbox;
	try {
		vm.runInNewContext(fs.readFileSync(editorPath, 'utf8'), sandbox, { filename: editorPath, timeout: 20000 });
	} catch (e) {
		console.error('the editor script ' + path.relative(repo, editorPath) + ' threw while loading, so no comparison is possible:');
		console.error('  ' + (e && e.message ? e.message : String(e)));
		process.exit(1);
	}
	if (registrations.length === before) {
		console.error('the editor script ' + path.relative(repo, editorPath) + ' registered no blocks — the stub is probably missing a `wp` namespace it needs');
		process.exit(1);
	}
}

/* ---------------------------------------------------------------- compare --- */

const server = JSON.parse(fs.readFileSync(serverPath, 'utf8'));

const editor = {};
const duplicates = [];
for (const { name, settings, source } of registrations) {
	if (editor[name]) {
		duplicates.push({ name, what: 'registered twice in the editor (' + path.relative(repo, editor[name].source) + ', ' + path.relative(repo, source) + ')' });
	}
	const attributes = {};
	for (const [key, spec] of Object.entries(settings.attributes || {})) {
		attributes[key] = {
			type: spec && spec.type !== undefined ? spec.type : null,
			default: spec && Object.prototype.hasOwnProperty.call(spec, 'default') ? spec.default : '__none__',
			source: spec && spec.source !== undefined ? spec.source : null,
		};
	}
	editor[name] = {
		apiVersion: settings.apiVersion === undefined ? 1 : settings.apiVersion,
		attributes,
		supports: settings.supports && typeof settings.supports === 'object' ? settings.supports : {},
		hasSave: typeof settings.save === 'function',
		source,
	};
}

/*
 * `supports` keys WordPress treats as ON when a block does not mention them
 * (hasBlockSupport(…, true) in the editor, block_has_support(…, true) on the
 * server). Any other key absent on one side means "not supported".
 *
 * `customCSS` is one of them (wp-includes/block-supports/custom-css.php and
 * the editor's custom-CSS hook both default it to true): a block the server
 * registers with customCSS false and the editor leaves unset got the editor's
 * "Additional CSS" panel, and the server then printed none of it.
 *
 * The list is read from WordPress 7.1 itself: every `block_has_support(…,
 * true)` in wp-includes and every `hasBlockSupport(…, true)` in its editor
 * bundles. `visibility` (block-supports/block-visibility.php, the editor's
 * Hide block control) and `alignWide` are on it; without them an explicit
 * `visibility: true` on one side read as a disagreement with a side that
 * left it unset, which WordPress treats as the same thing.
 */
const SUPPORTS_ON = new Set(['html', 'className', 'customClassName', 'customCSS', 'multiple', 'reusable', 'inserter', 'lock', 'renaming', 'visibility', 'alignWide']);

/*
 * Server-rendered blocks the converter writes into content, so the editor
 * has to know them. A dynamic block stores no markup, which is why one with
 * no editor registration is otherwise only noted ("ONE SIDE ONLY"): nothing
 * of it can be invalid. But a stored `<!-- wp:dxai-ui/site-header /-->` whose
 * editor script is missing opens as "Your site doesn't include support for
 * this block" — so for these it is a failure, whatever list of sources the
 * run was given. dxai-ui/pattern is not here: nothing writes it any more.
 */
const REQUIRED_EDITOR = new Set(['dxai-ui/site-header', 'dxai-ui/site-footer', 'dxai-ui/form']);

/*
 * Keys only the editor reads. The server never consults them when it renders,
 * and the editor's own registration replaces the server's `supports` whole
 * (processBlockType: {...bootstrapped, ...settings}), so a difference changes
 * nothing that either side does. Reported as a note, not a failure — a
 * dynamic block's editor script can legitimately turn off "Edit as HTML"
 * that its server registration never mentions.
 */
const SUPPORTS_EDITOR_ONLY = new Set(['html', 'inserter', 'multiple', 'reusable', 'renaming', 'lock']);

/** A supports value in one comparable form: keys sorted, PHP's empty array as {}. */
function canonicalSupport(value) {
	if (Array.isArray(value)) {
		return value.length ? value.map(canonicalSupport) : {};
	}
	if (value && typeof value === 'object') {
		const out = {};
		for (const key of Object.keys(value).sort()) { out[key] = canonicalSupport(value[key]); }
		return out;
	}
	return value;
}

function supportsProblems(name, server, editorSupports) {
	const out = [];
	const keys = [...new Set([...Object.keys(server || {}), ...Object.keys(editorSupports || {})])].sort();
	for (const key of keys) {
		const fallback = SUPPORTS_ON.has(key) ? true : undefined;
		const s = Object.prototype.hasOwnProperty.call(server || {}, key) ? server[key] : fallback;
		const e = Object.prototype.hasOwnProperty.call(editorSupports || {}, key) ? editorSupports[key] : fallback;
		if (JSON.stringify(canonicalSupport(s)) !== JSON.stringify(canonicalSupport(e))) {
			out.push({
				name,
				what: SUPPORTS_EDITOR_ONLY.has(key) ? 'supports differ (editor-only key: the editor value governs)' : 'supports differ',
				attr: 'supports.' + key,
				server: s === undefined ? '(absent)' : s,
				editor: e === undefined ? '(absent)' : e,
				note: SUPPORTS_EDITOR_ONLY.has(key),
			});
		}
	}
	return out;
}

/*
 * Attributes WordPress core adds to every block itself. They appear in the
 * server registry and not in our editor settings, because the editor gets them
 * from core at runtime too — so reporting them is the instrument talking about
 * itself. On the first run they were 20 of 25 "disagreements".
 */
const CORE_ALWAYS = new Set( [ 'lock', 'metadata' ] );

/*
 * These core derives from `supports`, so whether their absence is a real
 * mismatch depends on the block's own supports rather than on the name.
 */
function coreDerives(attr, supports, spec) {
	const on = (key) => supports[key] !== undefined && supports[key] !== false;
	switch (attr) {
		case 'className':
			return on('className') || on('customClassName');
		case 'anchor':
			return on('anchor');
		case 'align':
			return on('align');
		/*
		 * Discriminated by type, not by name, because we declare a `style` of
		 * our own: `dxai-ui/link` carries the design's inline style as a
		 * STRING. Core's style-engine attribute is an OBJECT. Skipping the name
		 * outright would have hidden a real mismatch on link's own attribute —
		 * which is the whole failure this check exists to catch.
		 */
		case 'style':
			return spec && spec.type === 'object';
		case 'fontSize':
		case 'fontFamily':
		case 'backgroundColor':
		case 'textColor':
		case 'gradient':
		case 'borderColor':
			return on('color') || on('typography') || on('spacing') || on('border')
				|| on('shadow') || on('dimensions') || on('layout');
		default:
			return false;
	}
}

/**
 * An empty PHP array and an empty JS object are the same declaration.
 *
 * `array()` has no JSON representation that distinguishes a list from a map, so
 * a `'default' => array()` on an object-typed attribute dumps as `[]` while the
 * editor declares `{}`. Same default, and the difference is entirely an
 * artifact of crossing the language boundary.
 */
function canonical(spec) {
	const value = spec.default;
	const empty = Array.isArray(value) && value.length === 0;
	return {
		type: spec.type,
		source: spec.source,
		default: spec.type === 'object' && empty ? {} : value,
	};
}

const same = (a, b) => JSON.stringify(canonical(a)) === JSON.stringify(canonical(b));

const problems = [...duplicates];
const names = [...new Set([...Object.keys(server), ...Object.keys(editor)])].sort();

for (const name of names) {
	const s = server[name];
	const e = editor[name];

	if (!s) { problems.push({ name, what: 'registered in the editor and not on the server' }); continue; }
	if (!e) {
		// A dynamic block has no stored markup to validate, so the editor half
		// is optional — unless the converter writes it into content
		// (REQUIRED_EDITOR), where the editor would show it as unsupported.
		if (!s.dynamic) {
			problems.push({ name, what: 'registered on the server with a save-bearing definition and not in the editor' });
		} else if (REQUIRED_EDITOR.has(name)) {
			problems.push({ name, what: 'written into content by the converter and registered by no editor script (the editor would show it as unsupported)' });
		}
		continue;
	}

	/*
	 * apiVersion is not cosmetic: under 3 the editor canvas is an iframe, so a
	 * block the two sides disagree about renders with different styles
	 * available to it.
	 */
	if (s.api_version !== e.apiVersion) {
		problems.push({ name, what: 'apiVersion', server: s.api_version, editor: e.apiVersion });
	}

	// Compared for every block, dynamic ones included: the editor's own
	// `supports` is what the inserter, the toolbar and the sidebar obey.
	problems.push(...supportsProblems(name, s.supports || {}, e.supports));

	// A block with no save() stores no markup, so there is nothing to compare
	// against and an attribute mismatch cannot invalidate it.
	if (!e.hasSave) { continue; }

	const keys = [...new Set([...Object.keys(s.attributes), ...Object.keys(e.attributes)])].sort();
	for (const key of keys) {
		if (CORE_ALWAYS.has(key)) { continue; }

		const sa = s.attributes[key];
		const ea = e.attributes[key];
		if (!sa) { problems.push({ name, what: 'attribute only in the editor', attr: key }); continue; }
		if (!ea) {
			// Present server-side only because this block's supports told core
			// to add it — the editor receives the same one at runtime.
			if (coreDerives(key, s.supports || {}, sa)) { continue; }
			problems.push({ name, what: 'attribute only on the server', attr: key });
			continue;
		}
		if (!same(sa, ea)) {
			problems.push({ name, what: 'attribute differs', attr: key, server: sa, editor: ea });
		}
	}
}

/* ----------------------------------------------------------------- report --- */

const serverCount = Object.keys(server).length;
const editorCount = Object.keys(editor).length;
console.log('server ' + serverCount + ' block(s), editor ' + editorCount + ' block(s) from '
	+ editorPaths.map((p) => path.relative(repo, p).split(path.sep).join('/')).join(', '));
for (const name of names) {
	const s = server[name];
	const e = editor[name];
	const n = s ? Object.keys(s.attributes).length : 0;
	console.log('  ' + name.padEnd(20)
		+ String(n).padStart(3) + ' attr'
		+ (e && !e.hasSave ? '   dynamic (no save, nothing to invalidate)' : '')
		+ (e && s && s.dynamic && e.hasSave ? '   dynamic (server-rendered; supports compared)' : '')
		+ (s && e ? '' : (s && s.dynamic && !e
			? (REQUIRED_EDITOR.has(name) ? '   NO EDITOR REGISTRATION (required: the converter writes it)' : '   ONE SIDE ONLY (server-rendered, no editor registration)')
			: '   ONE SIDE ONLY')));
}

const notes = problems.filter((p) => p.note);
const failures = problems.filter((p) => !p.note);
const show = (p) => {
	console.log('  ' + p.name + (p.attr ? '  ' + p.attr : '') + '  — ' + p.what);
	if (p.server !== undefined) { console.log('      server: ' + JSON.stringify(p.server)); }
	if (p.editor !== undefined) { console.log('      editor: ' + JSON.stringify(p.editor)); }
};

if (notes.length) {
	console.log('\n' + notes.length + ' note(s), not failures:');
	notes.forEach(show);
}

if (!failures.length) {
	console.log('\nparity: the editor and the server declare the same blocks the same way');
	process.exit(0);
}

console.log('\n' + failures.length + ' disagreement(s) — each one is an invalid block waiting to happen:');
failures.forEach(show);
process.exit(1);

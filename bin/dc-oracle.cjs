#!/usr/bin/env node
/**
 * Serve a Claude Design export's REAL runtime, as an independent oracle.
 *
 * Why this exists
 * ---------------
 * A Claude Design export is a ZIP holding `<Name>.dc.html` — an `<x-dc>`
 * template with `<helmet>` metadata, a `<script data-dc-script>` component
 * class and interpolated `{{ … }}` strings — plus `support.js`, the runtime
 * that turns it into a React tree, and the pictures it uses under `assets/`
 * and/or `uploads/`. The converter reads that template with its own parser,
 * and bin/design-oracle.php would build the "design side" of a measurement
 * with the same code — so a template-reading loss would sit on BOTH sides and
 * the diff would report 0px while the page is wrong. That is the blind spot
 * bin/react-oracle.sh closes for Lovable exports, and this closes for these.
 *
 * So the design side owes us nothing: the file is rendered by its own
 * `support.js`, in Chrome, exactly as the export would open from disk. No
 * plugin code is anywhere in it.
 *
 * What it changes about the served copy
 * -------------------------------------
 * Two things, both to make the measurement DETERMINISTIC, neither of which
 * moves a box.
 *
 * 1. React is vendored. `support.js` loads React 18.3.1 and ReactDOM from
 *    unpkg, with SRI, and a slow or blocked CDN leaves the page as an empty
 *    `<x-dc>` — which would be measured as a design with nothing in it. The
 *    runtime documents `window.__resources[cdnUrl]` as a local override for
 *    exactly that, so `oracle.dc.html` is the original file with one
 *    `<script>` defining it inserted before `support.js`, and the two UMD
 *    files are downloaded once per machine and checked against the SRI
 *    hashes the runtime itself carries. If they cannot be had, this refuses
 *    rather than serving a design that might half-load.
 *
 * 2. Web fonts are mirrored. The `<helmet>` links a Google Fonts stylesheet;
 *    fetched over the network at measurement time it is non-deterministic,
 *    and a fallback face changes every heading height. The stylesheet and
 *    its woff2 files are copied beside the page as `oracle-fonts.css` +
 *    `fonts/`, the same way bin/design-oracle.php does for a TSX design, and
 *    the link in the served copy points at the mirror. If the mirror fails
 *    the live link stays and the output says so.
 *
 * Nothing else is touched: the pictures are served from where the export
 * put them, `support.js` is the export's own byte for byte, and the served
 * path keeps its `.dc.html` suffix because the runtime names the root
 * component from it.
 *
 * usage:
 *   node bin/dc-oracle.cjs <zip-or-dir> --port 5301 [--work <dir>] [--slug name]
 *   node bin/dc-oracle.cjs --stop --port 5301
 *
 * then:
 *   node bin/design-oracle.cjs --design-url http://127.0.0.1:5301/oracle.dc.html \
 *     --design-root "#dc-root > .sc-host > div" \
 *     --url <live-wp-url> --width 1280 --depth 20 --styles
 *
 * On success prints `serving <url>` and `design-root: <selector>`;
 * bin/verify-import.cjs reads both lines rather than assuming them.
 */

'use strict';

const { spawn, spawnSync } = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
const http = require('http');
const https = require('https');
const path = require('path');

const repo = path.resolve(__dirname, '..');

// Where the runtime fetches React from, and where it would fetch it from on a
// machine that has never run this. Read back out of support.js at run time
// when present — see reactUrls() — so a runtime that pins a different version
// gets the version it asked for rather than this one.
const REACT_URL = 'https://unpkg.com/react@18.3.1/umd/react.production.min.js';
const REACT_DOM_URL = 'https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js';

// Fonts are only served as woff2 to a browser that says it is one.
const AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

// The design's outermost element in the hydrated DOM. The runtime replaces
// `<x-dc>` with `#dc-root > .sc-host > <template wrapper>`, and it is the
// wrapper whose children are the design's header / sections / footer — the
// same level `.dxai-ui` holds on a converted page. `#dc-root` and `.sc-host`
// are pinned to the viewport height by the runtime's own CSS, so keying
// either of them would put the two sides one level apart and nothing would
// match.
const DESIGN_ROOT = '#dc-root > .sc-host > div';

const CHROME = process.env.DXAI_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';

function parseArgs(argv) {
	const out = {};
	for (let i = 0; i < argv.length; i++) {
		if (!argv[i].startsWith('--')) { out.src = argv[i]; continue; }
		const key = argv[i].slice(2);
		out[key] = argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[++i] : true;
	}
	return out;
}

const args = parseArgs(process.argv.slice(2));
const port = parseInt(args.port, 10);
const workRoot = path.join(path.resolve(args.work || path.join(repo, '.verify')), 'dc-oracle');

function fail(msg, code = 1) {
	console.error('dc-oracle: ' + msg);
	process.exit(code);
}

function slugOf(src) {
	return path.basename(src).replace(/\.zip$/i, '').replace(/[^A-Za-z0-9]+/g, '-')
		.replace(/^-|-$/g, '').toLowerCase() || 'design';
}

/* ------------------------------------------------------------------ ports --- */

/**
 * Whatever holds the port, by pid. Done by port rather than only by pid file,
 * for the same reason react-oracle.sh does: an interrupted run leaves the
 * server up with no file to find it by.
 */
function pidsOnPort(p) {
	if (process.platform !== 'win32') {
		const res = spawnSync('lsof', ['-t', '-iTCP:' + p, '-sTCP:LISTEN'], { encoding: 'utf8' });
		return (res.stdout || '').split(/\s+/).filter(Boolean);
	}
	const res = spawnSync('netstat', ['-ano'], { encoding: 'utf8' });
	const pids = new Set();
	for (const line of (res.stdout || '').split('\n')) {
		const m = line.match(/^\s*TCP\s+\S+:(\d+)\s+\S+\s+LISTENING\s+(\d+)/);
		if (m && Number(m[1]) === p && Number(m[2]) > 0) { pids.add(m[2]); }
	}
	return [...pids];
}

function kill(pid) {
	if (process.platform === 'win32') {
		spawnSync('taskkill', ['/F', '/PID', String(pid)], { stdio: 'ignore' });
	} else {
		try { process.kill(Number(pid), 'SIGKILL'); } catch (e) { /* already gone */ }
	}
}

function pidFile(p) {
	return path.join(workRoot, 'server-' + p + '.pid');
}

function releasePort(p) {
	const file = pidFile(p);
	if (fs.existsSync(file)) {
		try {
			const pid = JSON.parse(fs.readFileSync(file, 'utf8')).pid;
			if (pid) { kill(pid); }
		} catch (e) { /* a stale or half-written file; the port scan below covers it */ }
		fs.rmSync(file, { force: true });
	}
	for (const pid of pidsOnPort(p)) {
		if (Number(pid) !== process.pid) { kill(pid); }
	}
}

/* ---------------------------------------------------------------- server --- */

const TYPES = {
	'.html': 'text/html; charset=utf-8',
	'.js': 'text/javascript; charset=utf-8',
	'.mjs': 'text/javascript; charset=utf-8',
	'.css': 'text/css; charset=utf-8',
	'.json': 'application/json; charset=utf-8',
	'.png': 'image/png',
	'.jpg': 'image/jpeg',
	'.jpeg': 'image/jpeg',
	'.webp': 'image/webp',
	'.gif': 'image/gif',
	'.svg': 'image/svg+xml',
	'.ico': 'image/x-icon',
	'.avif': 'image/avif',
	'.woff2': 'font/woff2',
	'.woff': 'font/woff',
	'.ttf': 'font/ttf',
	'.otf': 'font/otf',
	'.pdf': 'application/pdf',
	'.txt': 'text/plain; charset=utf-8',
	'.map': 'application/json',
};

/**
 * The server itself. Runs in the detached child (`--serve`), so the CLI that
 * started it can exit and the caller can measure against it and stop it with
 * `--stop` — the same shape react-oracle.sh has.
 */
function serve(root, p, pidPath) {
	const server = http.createServer((req, res) => {
		let pathname;
		try {
			// File names in these exports carry spaces (`h2o away logo-white.png`),
			// so the path arrives percent-encoded and has to be decoded before it
			// can be looked up.
			pathname = decodeURIComponent(new URL(req.url, 'http://127.0.0.1').pathname);
		} catch (e) {
			res.writeHead(400); res.end('bad request'); return;
		}
		if (pathname === '/') {
			// The runtime names the root component from the pathname and wants
			// it to end in .dc.html, so `/` is not a page here — send the
			// caller to the one that is.
			res.writeHead(302, { Location: '/oracle.dc.html' }); res.end(); return;
		}
		const file = path.resolve(root, '.' + pathname);
		// Traversal guard: the resolved path has to stay inside the project.
		if (file !== root && !file.startsWith(root + path.sep)) {
			res.writeHead(403); res.end('forbidden'); return;
		}
		fs.stat(file, (err, st) => {
			if (err || !st.isFile()) {
				res.writeHead(404, { 'Content-Type': 'text/plain' }); res.end('not found: ' + pathname); return;
			}
			res.writeHead(200, {
				'Content-Type': TYPES[path.extname(file).toLowerCase()] || 'application/octet-stream',
				'Content-Length': st.size,
				// Measured many times in a run; never let a stale copy answer.
				'Cache-Control': 'no-store',
			});
			fs.createReadStream(file).pipe(res);
		});
	});
	server.on('error', (e) => {
		console.error('dc-oracle server: ' + e.message);
		process.exit(1);
	});
	server.listen(p, '127.0.0.1', () => {
		fs.mkdirSync(path.dirname(pidPath), { recursive: true });
		fs.writeFileSync(pidPath, JSON.stringify({ pid: process.pid, port: p, root }));
		console.log('dc-oracle server: pid ' + process.pid + ' serving ' + root + ' on 127.0.0.1:' + p);
	});
}

/* -------------------------------------------------------------- download --- */

function download(url, dest, redirects = 0) {
	return new Promise((resolve, reject) => {
		https.get(url, { headers: { 'User-Agent': AGENT } }, (res) => {
			if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location && redirects < 5) {
				res.resume();
				return resolve(download(new URL(res.headers.location, url).toString(), dest, redirects + 1));
			}
			if (res.statusCode !== 200) {
				res.resume();
				return reject(new Error('HTTP ' + res.statusCode + ' for ' + url));
			}
			const chunks = [];
			res.on('data', (c) => chunks.push(c));
			res.on('end', () => {
				const body = Buffer.concat(chunks);
				if (dest) { fs.writeFileSync(dest, body); }
				resolve(body);
			});
			res.on('error', reject);
		}).on('error', reject);
	});
}

/* --------------------------------------------------------------- sources --- */

/** The `<Name>.dc.html` a project directory holds, searched a few levels down. */
function findDc(dir, depth = 0) {
	const here = fs.readdirSync(dir, { withFileTypes: true });
	const hit = here.find((e) => e.isFile() && /\.dc\.html$/i.test(e.name) && e.name !== 'oracle.dc.html');
	if (hit) { return path.join(dir, hit.name); }
	if (depth >= 3) { return null; }
	for (const e of here) {
		if (e.isDirectory() && !['node_modules', 'vendor', 'fonts'].includes(e.name)) {
			const found = findDc(path.join(dir, e.name), depth + 1);
			if (found) { return found; }
		}
	}
	return null;
}

/**
 * Extract or copy the export into the work directory. Copied even when a
 * directory is given, because the oracle writes beside the design —
 * oracle.dc.html, vendor/, fonts/ — and the caller's directory is theirs.
 */
function stage(src, project) {
	fs.rmSync(project, { recursive: true, force: true });
	fs.mkdirSync(project, { recursive: true });
	if (fs.statSync(src).isDirectory()) {
		fs.cpSync(src, project, { recursive: true });
		return;
	}
	let AdmZip;
	try {
		AdmZip = require(path.join(repo, 'node_modules', 'adm-zip'));
	} catch (e) {
		AdmZip = null;
	}
	if (AdmZip) {
		new AdmZip(src).extractAllTo(project, true);
		return;
	}
	const res = spawnSync('unzip', ['-q', src, '-d', project], { stdio: 'inherit' });
	if (res.status !== 0) { fail('could not extract ' + src + ' (no adm-zip in node_modules and unzip failed)', 2); }
}

/**
 * The CDN URLs and SRI hashes the runtime carries, so the vendored copies are
 * checked against what the runtime would have checked them against. Falls
 * back to the known 18.3.1 values when a support.js does not spell them out.
 */
function reactUrls(supportJs) {
	const src = fs.existsSync(supportJs) ? fs.readFileSync(supportJs, 'utf8') : '';
	const pick = (name, fallback) => {
		const m = src.match(new RegExp('\\b' + name + '\\s*=\\s*"([^"]+)"'));
		return m ? m[1] : fallback;
	};
	return {
		react: { url: pick('REACT_URL', REACT_URL), sri: pick('REACT_SRI', '') },
		reactDom: { url: pick('REACT_DOM_URL', REACT_DOM_URL), sri: pick('REACT_DOM_SRI', '') },
	};
}

function sri384(buf) {
	return 'sha384-' + crypto.createHash('sha384').update(buf).digest('base64');
}

/**
 * React and ReactDOM, once per machine. A vendored file is trusted only when
 * it hashes to the SRI the runtime itself pins: the override drops the
 * browser's own integrity check, so it is done here instead. A file that
 * fails is deleted and fetched again; one that cannot be fetched is fatal —
 * measuring a design whose React never loaded reports it as empty, and that
 * must never pass silently.
 */
async function vendorReact(urls) {
	const dir = path.join(workRoot, 'vendor');
	fs.mkdirSync(dir, { recursive: true });
	const out = {};
	for (const [key, { url, sri }] of Object.entries(urls)) {
		const name = path.basename(new URL(url).pathname);
		const file = path.join(dir, name);
		let cached = fs.existsSync(file) && fs.statSync(file).size > 10000;
		if (cached && sri && sri384(fs.readFileSync(file)) !== sri) {
			console.log('dc-oracle: vendored ' + name + ' does not match the runtime\'s SRI — refetching');
			fs.rmSync(file, { force: true });
			cached = false;
		}
		if (!cached) {
			try {
				await download(url, file);
			} catch (e) {
				fail('could not download ' + url + ' (' + e.message + ') and no vendored copy exists at ' + file + '.\n'
					+ '   The runtime cannot render without it, and measuring a design whose React never\n'
					+ '   loaded would report it as empty. Refusing rather than producing a false result.');
			}
			const body = fs.readFileSync(file);
			if (body.length < 10000 || (sri && sri384(body) !== sri)) {
				fs.rmSync(file, { force: true });
				fail(name + ' downloaded from ' + url + ' does not match the SRI hash the runtime pins ('
					+ (sri || 'none') + '). Refusing to serve it.');
			}
		}
		out[key] = { url, file, name, cached };
	}
	return out;
}

/* ----------------------------------------------------------------- fonts --- */

/**
 * Mirror the Google Fonts stylesheet(s) the helmet links, plus their woff2
 * files, into the project — the same idea as bin/design-oracle.php's font
 * mirroring for TSX designs. Returns the rewritten HTML and a summary; on any
 * failure returns the HTML untouched so the live link still works, and says so.
 */
async function mirrorFonts(html, project) {
	const linkRe = /<link\b[^>]*href=["'](https:\/\/fonts\.googleapis\.com\/css2?\?[^"']+)["'][^>]*>/gi;
	const links = [...html.matchAll(linkRe)]
		.filter((m) => /rel=["'][^"']*stylesheet/i.test(m[0]))
		.map((m) => ({ tag: m[0], href: m[1] }));
	if (!links.length) { return { html, note: 'no Google Fonts link in the helmet', mirrored: false }; }

	fs.mkdirSync(path.join(project, 'fonts'), { recursive: true });
	let css = '';
	let files = 0;
	for (const link of links) {
		// The href sits in an HTML attribute, so `&` arrives as `&amp;`.
		const url = link.href.replace(/&amp;/g, '&');
		let sheet;
		try {
			sheet = (await download(url, null)).toString('utf8');
		} catch (e) {
			return { html, note: 'live (mirror failed: ' + e.message + ')', mirrored: false };
		}
		for (const remote of new Set([...sheet.matchAll(/url\((https:\/\/[^)]+)\)/g)].map((m) => m[1]))) {
			const ext = path.extname(new URL(remote).pathname) || '.woff2';
			const name = crypto.createHash('md5').update(remote).digest('hex') + ext;
			const local = path.join(project, 'fonts', name);
			if (!fs.existsSync(local)) {
				try {
					await download(remote, local);
				} catch (e) {
					return { html, note: 'live (mirror failed: ' + e.message + ')', mirrored: false };
				}
			}
			files++;
			sheet = sheet.split(remote).join('fonts/' + name);
		}
		css += sheet + '\n';
	}
	const faces = (css.match(/@font-face/g) || []).length;
	if (!faces) { return { html, note: 'live (mirror produced no @font-face)', mirrored: false }; }
	fs.writeFileSync(path.join(project, 'oracle-fonts.css'), css);
	for (const link of links) {
		html = html.replace(link.tag, link.tag.replace(link.href, 'oracle-fonts.css'));
	}
	return { html, note: 'mirrored — ' + faces + ' @font-face, ' + files + ' file(s)', mirrored: true };
}

/* ------------------------------------------------------------- readiness --- */

/**
 * Is the served page the design, rendered? Serving 200 is not that: the
 * runtime renders after DOMContentLoaded and after React has loaded, and an
 * empty `<x-dc>` is what the file IS until then. So it is asked in a browser
 * and required to have a root with a box and words in it, within 30 s.
 */
async function ready(url, chrome) {
	const puppeteer = require(path.join(repo, 'node_modules', 'puppeteer-core'));
	const browser = await puppeteer.launch({ executablePath: chrome, headless: 'shell', args: ['--no-sandbox'] });
	try {
		const page = await browser.newPage();
		await page.setViewport({ width: 1280, height: 900 });
		const errors = [];
		const external = new Set();
		const failedUrls = [];
		page.on('pageerror', (e) => errors.push(String(e).slice(0, 160)));
		page.on('console', (m) => { if (m.type() === 'error') { errors.push(m.text().slice(0, 160)); } });
		page.on('requestfailed', (r) => failedUrls.push(r.url().slice(0, 120)));
		page.on('request', (r) => {
			try {
				const host = new URL(r.url()).host;
				if (host && host !== '127.0.0.1:' + port) { external.add(host); }
			} catch (e) { /* data: urls */ }
		});
		await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
		let rendered = true;
		await page.waitForFunction((sel) => {
			const el = document.querySelector(sel);
			return Boolean(el) && el.getBoundingClientRect().height > 0 && (el.innerText || '').trim().length > 0;
		}, { timeout: 30000, polling: 250 }, DESIGN_ROOT).catch(() => { rendered = false; });
		const stats = await page.evaluate((sel) => {
			const el = document.querySelector(sel);
			return {
				height: el ? Math.round(el.getBoundingClientRect().height) : 0,
				text: el ? (el.innerText || '').trim().length : 0,
				sections: el ? el.querySelectorAll(':scope > header, :scope > section, :scope > footer, :scope > main, :scope > nav').length : 0,
				fonts: [...document.fonts].filter((f) => f.status === 'loaded').length,
				xdc: Boolean(document.querySelector('x-dc')),
			};
		}, DESIGN_ROOT);
		return { rendered, stats, errors, external: [...external], failedUrls };
	} finally {
		await browser.close();
	}
}

/* ------------------------------------------------------------------ main --- */

(async () => {
	if (args.serve) {
		// The detached child. `--serve <root> --port <n> --pid <file>`.
		if (!port) { fail('--serve needs --port', 2); }
		serve(path.resolve(String(args.serve)), port, String(args.pid || pidFile(port)));
		return;
	}

	if (!port) { fail('--port <n> is required', 2); }

	if (args.stop) {
		releasePort(port);
		console.log('dc-oracle: released port ' + port);
		return;
	}

	if (!args.src) { fail('need a ZIP or an extracted export directory', 2); }
	const src = path.resolve(String(args.src));
	if (!fs.existsSync(src)) { fail(src + ' does not exist', 2); }

	const slug = args.slug ? String(args.slug) : slugOf(src);
	const project = path.join(workRoot, slug, 'project');
	stage(src, project);

	const dcFile = findDc(project);
	if (!dcFile) { fail('no <Name>.dc.html inside ' + src, 2); }
	// The export's own directory is what gets served: support.js and the
	// pictures are addressed relative to the .dc.html, so they have to sit
	// beside the copy this writes.
	const root = path.dirname(dcFile);
	const supportJs = path.join(root, 'support.js');
	if (!fs.existsSync(supportJs)) { fail(path.basename(dcFile) + ' has no support.js beside it — not a Claude Design export', 2); }
	console.log('dc-oracle: ' + slug + ' — ' + path.basename(dcFile));

	// --- React
	const urls = reactUrls(supportJs);
	const vendored = await vendorReact(urls);
	fs.mkdirSync(path.join(root, 'vendor'), { recursive: true });
	const resources = {};
	for (const v of Object.values(vendored)) {
		fs.copyFileSync(v.file, path.join(root, 'vendor', v.name));
		resources[v.url] = 'vendor/' + v.name;
	}
	console.log('dc-oracle: vendored ' + Object.values(vendored).map((v) => v.name).join(' + ')
		+ (Object.values(vendored).every((v) => v.cached) ? ' (cached)' : ' (downloaded)'));

	// --- the served copy
	let html = fs.readFileSync(dcFile, 'utf8');
	const supportTag = html.match(/<script\b[^>]*\bsrc=["'](?:\.\/)?support\.js["'][^>]*>/i);
	if (!supportTag) { fail(path.basename(dcFile) + ' has no <script src="./support.js"> to hook — cannot vendor React', 2); }

	const fonts = await mirrorFonts(html, root);
	html = fonts.html;
	console.log('dc-oracle: fonts ' + fonts.note);

	// Before support.js, which runs synchronously in <head> and reads
	// `window.__resources` the moment it loads React. Defining it also skips
	// the runtime's re-fetch of location.href, which is only there to recover
	// the raw template from a document some host has already mutated.
	const hook = '<script>window.__resources=' + JSON.stringify(resources) + ';</script>\n';
	html = html.replace(supportTag[0], hook + supportTag[0]);
	fs.writeFileSync(path.join(root, 'oracle.dc.html'), html);

	// --- serve, detached
	releasePort(port);
	const log = path.join(workRoot, slug, 'server.log');
	const fd = fs.openSync(log, 'w');
	const child = spawn(process.execPath, [__filename, '--serve', root, '--port', String(port), '--pid', pidFile(port)], {
		detached: true,
		stdio: ['ignore', fd, fd],
		windowsHide: true,
	});
	child.unref();
	fs.closeSync(fd);

	// Wait for the socket, then for the design.
	const url = 'http://127.0.0.1:' + port + '/oracle.dc.html';
	const up = await new Promise((resolve) => {
		const deadline = Date.now() + 10000;
		const tick = () => {
			http.get(url, (res) => { res.resume(); resolve(res.statusCode === 200); })
				.on('error', () => (Date.now() > deadline ? resolve(false) : setTimeout(tick, 200)));
		};
		tick();
	});
	if (!up) {
		releasePort(port);
		fail('the server did not come up on port ' + port + ' within 10 s; see ' + log);
	}

	let probe;
	try {
		probe = await ready(url, args.chrome || CHROME);
	} catch (e) {
		releasePort(port);
		fail('readiness probe failed: ' + e.message);
	}
	const bad = probe.failedUrls.filter((u) => /vendor\/react|oracle-fonts|support\.js/.test(u));
	if (!probe.rendered || bad.length) {
		releasePort(port);
		console.error('dc-oracle: served 200 but the design did not render at ' + url);
		console.error('   root ' + DESIGN_ROOT + ': height=' + probe.stats.height + ' text=' + probe.stats.text
			+ ' x-dc still present=' + probe.stats.xdc);
		if (bad.length) { console.error('   failed requests: ' + bad.join(', ')); }
		if (probe.errors.length) { console.error('   page errors: ' + probe.errors.slice(0, 3).join(' | ')); }
		console.error('   Measuring it would report the design as empty. Refusing rather than producing a false 0px.');
		process.exit(1);
	}
	if (probe.errors.length) {
		console.log('dc-oracle: note — ' + probe.errors.length + ' page error(s): ' + probe.errors[0]);
	}
	// The whole point of vendoring: nothing about the render depends on a CDN.
	const cdn = probe.external.filter((h) => /unpkg|jsdelivr|cdnjs|gstatic|googleapis/.test(h));
	console.log('dc-oracle: rendered — root ' + probe.stats.height + 'px tall, ' + probe.stats.sections
		+ ' top-level layout element(s), ' + probe.stats.text + ' chars, ' + probe.stats.fonts + ' font face(s) loaded'
		+ (cdn.length ? '; STILL FETCHING FROM ' + cdn.join(', ') : '; no CDN requests'));
	console.log('serving ' + url);
	console.log('design-root: ' + DESIGN_ROOT);
	console.log('');
	console.log('  node bin/design-oracle.cjs --design-url ' + url + ' \\');
	console.log('    --design-root "' + DESIGN_ROOT + '" --url <live-wp-url> --width 1280 --depth 20 --styles');
	console.log('');
	console.log('  node bin/dc-oracle.cjs --stop --port ' + port + '   # when done');
})().catch((e) => {
	console.error('dc-oracle: ' + (e.stack || String(e)));
	if (port) { releasePort(port); }
	process.exit(1);
});

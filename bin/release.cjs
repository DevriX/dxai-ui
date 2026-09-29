// Build a release ZIP from this tree: snapshot, version stamp, checks, lint, zip, and a check of the ZIP itself.
//
//   node bin/release.cjs 0.4.0-beta.9            (or: npm run release -- 0.4.0-beta.9)
//
// Writes dist/dxai-ui-<version>.zip. Everything is checked before the ZIP is kept: a release that fails a check is
// not written. The ZIP is made by PHP's ZipArchive with forward-slash entry names under one `dxai-ui/` root — the
// only shape WordPress's plugin upload installs on a Linux host. (A ZIP made by Windows' Compress-Archive names its
// entries `dxai-ui\src\Plugin.php`; unzipped on WP Engine those are flat files with backslashes in their names, and
// the plugin does not load.)
//
// PHP: $DXAI_PHP, else Local's PHP 8.2. The CLI ini that loads the zip extension: $DXAI_PHP_INI, else
// .verify/tools/dxai-cli.ini.
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');

const VERSION = process.argv[2];
if (!/^\d+\.\d+\.\d+(-[a-z0-9.]+)?$/.test(VERSION || '')) {
	console.error('usage: node bin/release.cjs <version>   e.g. 0.4.0-beta.9');
	process.exit(2);
}
const ROOT = path.resolve(__dirname, '..');
const PHP = process.env.DXAI_PHP || 'C:/Users/DevriX/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe';
const INI = process.env.DXAI_PHP_INI || path.join(ROOT, '.verify/tools/dxai-cli.ini');
const WORK = fs.mkdtempSync(path.join(os.tmpdir(), 'dxai-release-'));
const DST = path.join(WORK, 'dxai-ui');
const OUT = path.join(ROOT, 'dist', 'dxai-ui-' + VERSION + '.zip');

// What ships. Nothing else: no assets/src, bin, fixtures, node_modules, logs.
const KEEP = ['dxai-ui.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'src', 'data', 'templates', 'languages', 'runtime', 'assets/build', 'assets/css', 'assets/js', 'assets/vendor', 'assets/index.php'];
const DEV = /[\\/](node_modules|\.git|\.verify|\.cursor|\.DS_Store)([\\/]|$)/;
const JUNK = /\.(bak|orig|log|map|tmp)$/i;

const fails = [];
const need = (ok, what) => { if (!ok) fails.push(what); };

for (const rel of KEEP) {
	const from = path.join(ROOT, rel);
	if (!fs.existsSync(from)) { console.log('missing (skipped):', rel); continue; }
	fs.cpSync(from, path.join(DST, rel), { recursive: true, filter: (p) => !DEV.test(p) && !JUNK.test(p) });
}

// The admin bundle is built from the current source first, so the ZIP can never ship a stale one
// (--no-build skips it when the build was just made).
if (!process.argv.includes('--no-build')) {
	const b = spawnSync('npm run build', { cwd: ROOT, encoding: 'utf8', shell: true });
	need(b.status === 0, 'npm run build failed: ' + ((b.stderr || b.stdout || '').trim().split('\n').slice(-3).join(' | ')));
}
for (const rel of ['assets/build']) {
	fs.rmSync(path.join(DST, rel), { recursive: true, force: true });
	fs.cpSync(path.join(ROOT, rel), path.join(DST, rel), { recursive: true, filter: (p) => !JUNK.test(p) });
}

// Version stamp, in the snapshot only.
const stamp = (rel, re, to) => {
	const f = path.join(DST, rel);
	const s = fs.readFileSync(f, 'utf8');
	const n = (s.match(new RegExp(re.source, 'g')) || []).length;
	need(n === 1, rel + ': version anchor found ' + n + ' times: ' + re);
	fs.writeFileSync(f, s.replace(re, to));
};
stamp('dxai-ui.php', /( \* Version:\s+)[^\r\n]+/, '$1' + VERSION);
stamp('dxai-ui.php', /define\( 'DXAI_UI_VERSION', '[^']+' \);/, "define( 'DXAI_UI_VERSION', '" + VERSION + "' );");
stamp('readme.txt', /(Stable tag:\s*)[^\r\n]+/, '$1' + VERSION);

// Promises the plugin makes that a release must keep.
const read = (rel) => fs.readFileSync(path.join(DST, rel), 'utf8');
need(fs.existsSync(path.join(DST, 'runtime/dxai-ui-runtime.php')), 'the runtime loader ships');
const un = read('uninstall.php');
need(!/wp_delete_post\s*\(/.test(un), 'uninstall.php never deletes posts');
need(/dxai_ui_keep_runtime/.test(un), 'uninstall.php keeps the runtime');
need(/render_only/.test(read('src/Plugin.php')), 'render-only boot (the runtime) exists');
need((read('dxai-ui.php').match(/Requires at least:\s*([\d.]+)/) || [])[1] === (read('readme.txt').match(/Requires at least:\s*([\d.]+)/) || [])[1], 'dxai-ui.php and readme.txt require the same WordPress version');

// Lint everything that ships.
let php = 0, js = 0;
const walk = (d) => {
	for (const f of fs.readdirSync(d)) {
		const p = path.join(d, f);
		if (fs.statSync(p).isDirectory()) { walk(p); continue; }
		if (p.endsWith('.php')) {
			php++;
			const r = spawnSync(PHP, ['-n', '-l', p], { encoding: 'utf8' });
			if (r.status !== 0) fails.push('PHP ' + path.relative(DST, p) + ': ' + (r.stdout + r.stderr).trim().split('\n')[0]);
		} else if (p.endsWith('.js') && !p.includes(path.sep + 'vendor' + path.sep)) {
			js++;
			const r = spawnSync(process.execPath, ['--check', p], { encoding: 'utf8' });
			if (r.status !== 0) fails.push('JS ' + path.relative(DST, p) + ': ' + (r.stderr || '').trim().split('\n')[0]);
		}
	}
};
walk(DST);
console.log('snapshot: php=' + php + ' js=' + js);
if (fails.length) {
	console.error('RELEASE CHECKS FAILED — nothing written:\n - ' + fails.join('\n - '));
	fs.rmSync(WORK, { recursive: true, force: true });
	process.exit(1);
}

// Zip, then read the ZIP back and check what WordPress will unpack.
fs.mkdirSync(path.dirname(OUT), { recursive: true });
const z = spawnSync(PHP, ['-c', INI, path.join(__dirname, 'release-zip.php'), DST, OUT, VERSION], { encoding: 'utf8' });
process.stdout.write(z.stdout);
process.stderr.write(z.stderr);
fs.rmSync(WORK, { recursive: true, force: true });
if (z.status !== 0) {
	fs.rmSync(OUT, { force: true });
	console.error('ZIP CHECK FAILED — the ZIP was removed.');
	process.exit(1);
}
console.log('release: ' + OUT);

#!/usr/bin/env node
/**
 * The block editor's canvas writes the rules of a block's own CSS the way the front end does.
 *
 * assets/js/blocks-editor.js writes a rule for every block's `dxaiCss` as it is now (so an edit shows at once), and
 * dxaiRule()/dxaiFgChannel() there are ports of Style_Rules::rule() and Token_Styles::fg_channel() in PHP. A port that
 * drifts shows the design's colour in the canvas where the page shows the theme's (a theme's binding keeps the colours it
 * has set apart on the `--fg` channel). This runs the PHP function and the script's on the same declarations and says
 * where they differ. It also checks the two properties the canvas depends on: a block whose class the server's copy of the
 * rules already has gets no rule of the script's, and the theme's block styles are kept in a converted page's canvas.
 *
 *   node bin/verify-editor-canvas.cjs        (DXAI_PHP and DXAI_PHP_INI name php and its ini, as for bin/release.cjs)
 *
 * Exit code 1 when a check fails.
 */
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const PHP = process.env.DXAI_PHP || 'C:/Users/DevriX/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe';
const INI = process.env.DXAI_PHP_INI || path.join(ROOT, '.verify/tools/dxai-cli.ini');
const js = fs.readFileSync(path.join(ROOT, 'assets/js/blocks-editor.js'), 'utf8').replace(/\r\n/g, '\n');

let pass = 0;
let fail = 0;
const expect = (what, ok, detail = '') => {
	if (ok) {
		pass++;
		console.log('  ok    ' + what);
	} else {
		fail++;
		console.log('  FAIL  ' + what + (detail ? '  — ' + detail : ''));
	}
};

// The script's own function, taken out of the file as it is.
const start = js.indexOf('function dxaiFgChannel( css ) {');
let end = js.indexOf('\n\t}\n', start);
const source = start > -1 && end > -1 ? js.slice(start, end + 4) : '';
expect('the editor script has dxaiFgChannel()', source !== '');
// eslint-disable-next-line no-new-func
const fgChannel = source ? new Function(source + '\nreturn dxaiFgChannel;')() : (css) => css;

const cases = [
	'color:var(--dxai-ink)',
	'margin:0 0 9px;font-family:Montserrat,sans-serif;color:var(--dxai-brand);font-size:19px',
	'color:var(--dxai-sky-38--fg,var(--dxai-sky-38))',
	'background:var(--dxai-brand);border:2px solid var(--dxai-sky-38)',
	'border-color:var(--dxai-brand);fill:var(--dxai-accent);stroke:var(--dxai-accent)',
	'-webkit-text-fill-color:var(--dxai-ink);text-decoration-color:var(--dxai-muted);caret-color:var(--dxai-brand)',
	'box-shadow:0 1px 2px var(--dxai-shadow-2);outline-color:var(--dxai-brand)',
	'font-size:14px',
	'COLOR : var( --dxai-neutral-100 )',
];
const php = (decl) => {
	const code = [
		"define('DXAI_UI_DIR', " + JSON.stringify(ROOT + '/') + ');',
		"require DXAI_UI_DIR . 'src/Autoloader.php';",
		'DXAI_UI\\Autoloader::register();',
		'echo DXAI_UI\\Compiler\\Token_Styles::fg_channel($argv[1]);',
	].join('');
	// `--`: a declaration may start with a dash (`-webkit-…`), which php would take for one of its own options.
	const r = spawnSync(PHP, ['-c', INI, '-r', code, '--', decl], { encoding: 'utf8' });
	return r.status === 0 ? r.stdout : null;
};
for (const decl of cases) {
	const want = php(decl);
	const got = fgChannel(decl);
	expect('the same channel for `' + decl.slice(0, 60) + '`', want !== null && got === want, want === null ? 'php did not run' : 'php: ' + want + ' | script: ' + got);
}

// A class the server's copy of the rules has needs no rule of the script's.
expect('live rules skip the classes the server wrote', /function dxaiLiveCss\( doc \) \{[\s\S]*?server\.has\( cls \)/.test(js));
expect('…decided for each canvas when it is written, not once when the blocks are read', /function dxaiWriteRules\( doc \) \{[\s\S]*?dxaiLiveCss\( doc \)/.test(js));
// The theme's own blocks keep their styles in the canvas, as they do on the front end.
expect('the theme\'s block styles are not switched off in the canvas', /blocks\.has\( link\.id \)/.test(js) && /themeBlocks/.test(js));
// An ordinary page with copied sections takes the design's scope, and fences the theme as the front end does.
expect('an attached page\'s canvas takes the scope classes', /! canvas\.converted && ! canvas\.attached/.test(js) && /dxai-ui-attached/.test(js));
expect('…and the theme\'s sheets go only where the front end fences them', /dxaiCanvasCfg\(\)\.converted \|\| dxaiCanvasCfg\(\)\.fence/.test(js));

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);

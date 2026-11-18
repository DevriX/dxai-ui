#!/usr/bin/env node
/**
 * The block editor's canvas writes the rules of a block's own CSS the way the front end does.
 *
 * assets/js/blocks-editor.js writes a rule for every block's `dxuiCss` as it is now (so an edit shows at once), and
 * dxuiRule()/dxuiFgChannel() there are ports of Style_Rules::rule() and Token_Styles::fg_channel() in PHP. A port that
 * drifts shows the design's colour in the canvas where the page shows the theme's (a theme's binding keeps the colours it
 * has set apart on the `--fg` channel). This runs the PHP function and the script's on the same declarations and says
 * where they differ. It also checks the two properties the canvas depends on: a block whose class the server's copy of the
 * rules already has gets no rule of the script's, and the theme's block styles are kept in a converted page's canvas.
 *
 *   node bin/verify-editor-canvas.cjs        (DXUI_PHP and DXUI_PHP_INI name php and its ini, as for bin/release.cjs)
 *
 * Exit code 1 when a check fails.
 */
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const PHP = process.env.DXUI_PHP || 'C:/Users/DevriX/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe';
const INI = process.env.DXUI_PHP_INI || path.join(ROOT, '.verify/tools/dxui-cli.ini');
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
const start = js.indexOf('function dxuiFgChannel( css ) {');
let end = js.indexOf('\n\t}\n', start);
const source = start > -1 && end > -1 ? js.slice(start, end + 4) : '';
expect('the editor script has dxuiFgChannel()', source !== '');
// eslint-disable-next-line no-new-func
const fgChannel = source ? new Function(source + '\nreturn dxuiFgChannel;')() : (css) => css;

const cases = [
	'color:var(--dxui-ink)',
	'margin:0 0 9px;font-family:Montserrat,sans-serif;color:var(--dxui-brand);font-size:19px',
	'color:var(--dxui-sky-38--fg,var(--dxui-sky-38))',
	'background:var(--dxui-brand);border:2px solid var(--dxui-sky-38)',
	'border-color:var(--dxui-brand);fill:var(--dxui-accent);stroke:var(--dxui-accent)',
	'-webkit-text-fill-color:var(--dxui-ink);text-decoration-color:var(--dxui-muted);caret-color:var(--dxui-brand)',
	'box-shadow:0 1px 2px var(--dxui-shadow-2);outline-color:var(--dxui-brand)',
	'font-size:14px',
	'COLOR : var( --dxui-neutral-100 )',
];
const php = (decl) => {
	const code = [
		"define('DX_UI_DIR', " + JSON.stringify(ROOT + '/') + ');',
		"require DX_UI_DIR . 'src/Autoloader.php';",
		'DX_UI\\Autoloader::register();',
		'echo DX_UI\\Compiler\\Token_Styles::fg_channel($argv[1]);',
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
expect('live rules skip the classes the server wrote', /function dxuiLiveCss\( doc \) \{[\s\S]*?server\.has\( cls \)/.test(js));
expect('…decided for each canvas when it is written, not once when the blocks are read', /function dxuiWriteRules\( doc \) \{[\s\S]*?dxuiLiveCss\( doc \)/.test(js));
// The theme's own blocks keep their styles in the canvas, as they do on the front end.
expect('the theme\'s block styles are not switched off in the canvas', /blocks\.has\( link\.id \)/.test(js) && /themeBlocks/.test(js));
// An ordinary page with copied sections takes the design's scope, and fences the theme as the front end does.
expect('an attached page\'s canvas takes the scope classes', /! canvas\.converted && ! canvas\.attached/.test(js) && /dx-ui-attached/.test(js));
expect('…and the theme\'s sheets go only where the front end fences them', /dxuiCanvasCfg\(\)\.converted \|\| dxuiCanvasCfg\(\)\.fence/.test(js));

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);

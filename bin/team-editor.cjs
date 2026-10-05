#!/usr/bin/env node
/**
 * The pages made for a design, opened in the real block editor — G7 as the editor sees it.
 *
 * The data half (bin/verify-team-quality.php) says that a page's blocks parse back to the same markup. The editor is the other judge: it
 * checks every block against what the block's own code would save, and a block that does not match shows "This block contains
 * unexpected or invalid content". This opens each page of the JSON in the editor (bin/editor-roundtrip.cjs) and reports the blocks
 * it cannot regenerate and the block types it does not know. Reads only; nothing is saved.
 *
 *   bash bin/wp-php.sh bin/verify-team-quality.php <home-id> out=q.json
 *   node bin/team-editor.cjs q.json cookies=<json> [pages=<id,id>]
 *
 * cookies: a file with the two cookies of a logged-in administrator, as [{name, value}, …] — the auth cookie first, then the
 * logged-in one (a test site's own session, never a person's: a short-lived one can be written with wp-cli on the test install).
 * Exit code 1 when a page has an invalid or unregistered block, 2 when it cannot run.
 */
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const input = process.argv.slice(2).find((a) => !a.includes('='));
const opt = Object.fromEntries(process.argv.slice(2).filter((a) => a.includes('=')).map((a) => [a.slice(0, a.indexOf('=')), a.slice(a.indexOf('=') + 1)]));
if (!input || !fs.existsSync(input) || !opt.cookies || !fs.existsSync(opt.cookies)) {
	console.error('usage: node bin/team-editor.cjs <json from verify-team-quality.php> cookies=<json> [pages=<id,id>]');
	process.exit(2);
}
const data = JSON.parse(fs.readFileSync(input, 'utf8'));
const cookies = JSON.parse(fs.readFileSync(opt.cookies, 'utf8'));
if (!Array.isArray(cookies) || cookies.length < 2) {
	console.error('cookies: the auth cookie and the logged-in cookie are both needed');
	process.exit(2);
}
const only = opt.pages ? new Set(opt.pages.split(',').map(Number)) : null;
const adminUrl = String(data.site || '').replace(/\/$/, '') + '/wp-admin/post.php';
const ids = [data.home.id, ...data.pages.map((p) => p.id)].filter((id) => !only || only.has(Number(id)));
const names = Object.fromEntries([[data.home.id, data.home.title], ...data.pages.map((p) => [p.id, p.title])]);
const harness = path.join(__dirname, 'editor-roundtrip.cjs');

let bad = 0;
console.log(`Home ${data.home.id} "${data.home.title}": ${ids.length} pages opened in the block editor\n`);
for (const id of ids) {
	const r = spawnSync(process.execPath, [harness, '--post', String(id), '--cookie-name', cookies[1].name, '--cookie-value', cookies[1].value, '--auth-cookie-name', cookies[0].name, '--auth-cookie-value', cookies[0].value, '--admin-url', adminUrl, '--timeout', '120000'], { encoding: 'utf8' });
	const out = (r.stdout + r.stderr).replace(new RegExp(cookies[0].value, 'g'), 'x').replace(new RegExp(cookies[1].value, 'g'), 'x');
	const line = (/invalid=\d+\s+unregistered=\d+/.exec(out) || [''])[0];
	const total = (/(\d+) blocks/.exec(out) || [0, '?'])[1];
	if (r.status === 0) {
		console.log(`  ok    #${id} ${String(names[id] || '').slice(0, 36)}: ${total} blocks, ${line}`);
	} else {
		bad++;
		console.log(`  FAIL  #${id} ${String(names[id] || '').slice(0, 36)}: ${r.status === 2 ? 'the editor did not open (' + out.trim().split('\n').pop().slice(0, 120) + ')' : out.split('\n').filter((l) => /INVALID|UNREGISTERED|at /.test(l)).slice(0, 4).join(' | ').slice(0, 300)}`);
	}
}
console.log(`\n${ids.length - bad} of ${ids.length} pages open in the editor with every block valid`);
process.exit(bad ? 1 : 0);

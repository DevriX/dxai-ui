#!/usr/bin/env node
/**
 * Builds data/team-pages.json — what the team's eleven live sites (283 pages) agree on and differ in about the order
 * of the sections of a page — from the sections of those pages read as roles (Section_Roles), one JSON file of
 * { site, slug, root, depth, sections: [{ role, … }] } per page.
 *
 *   node bin/build-team-model.cjs <pages.json> [data/team-pages.json]
 *
 * The model keeps, per kind of page, the order of roles each site uses (a site's pages repeat one or two orders;
 * sites differ from each other), and what is learned from all of them: how often a role appears, where it stands,
 * and which role follows which. Sites are numbered, not named.
 */
const fs = require('fs');
const path = require('path');

const input = process.argv[2];
const output = process.argv[3] || path.join(__dirname, '..', 'data', 'team-pages.json');
if (!input) {
	console.error('usage: node bin/build-team-model.cjs <pages.json> [out.json]');
	process.exit(2);
}
const pages = JSON.parse(fs.readFileSync(input, 'utf8'));

const kind = (p) => {
	const s = p.slug;
	if (/^(homepage|home)$/.test(s)) return 'home';
	if (p.depth >= 1 && /^services?$/.test(p.root)) return 'service';
	if (p.depth >= 1 && /^(locations?|service-areas?)$/.test(p.root)) return 'location';
	if (/^about/.test(s)) return 'about';
	if (/^contact/.test(s)) return 'contact';
	if (/^faq/.test(s)) return 'faq';
	if (/^testimonials?$|reviews/.test(s)) return 'testimonials';
	if (/^services?$/.test(s)) return 'services';
	if (/^(locations?|service-areas?)$/.test(s)) return 'areas';
	if (/privacy|terms|wchdpp/.test(s)) return 'privacy';
	return 'other';
};

// The vocabulary of Section_Roles: the extraction called the bar of badges 'trust-bar' and the links to other pages 'services-cards'.
const ROLE = { 'trust-bar': 'trust', 'services-cards': 'related' };

const sites = [...new Set(pages.map((p) => p.site))].sort((a, b) => a - b);
const siteNo = Object.fromEntries(sites.map((s, i) => [s, i]));
const types = {};
for (const p of pages) {
	const t = kind(p);
	if (t === 'other') continue;
	const roles = p.sections.map((s) => ROLE[s.role] || s.role);
	if (!roles.length) continue;
	const T = (types[t] = types[t] || { pages: 0, families: {}, roles: {}, next: {}, length: [] });
	T.pages++;
	(T.families[siteNo[p.site]] = T.families[siteNo[p.site]] || []).push(roles);
	T.length.push(roles.length);
	const seen = new Set();
	roles.forEach((r, i) => {
		const R = (T.roles[r] = T.roles[r] || { n: 0, m: 0, at: 0 });
		if (!seen.has(r)) { R.n++; seen.add(r); }
		R.m++;
		R.at += i / Math.max(1, roles.length - 1);
		const from = i === 0 ? '^' : roles[i - 1];
		const N = (T.next[from] = T.next[from] || {});
		N[r] = (N[r] || 0) + 1;
	});
	const last = (T.next[roles[roles.length - 1]] = T.next[roles[roles.length - 1]] || {});
	last.$ = (last.$ || 0) + 1;
}
const out = { version: 1, source: sites.length + ' live sites, ' + pages.length + ' pages', types: {} };
for (const [t, T] of Object.entries(types)) {
	if (T.pages < 4) continue;
	const roles = {};
	for (const [r, v] of Object.entries(T.roles)) roles[r] = { share: +(v.n / T.pages).toFixed(2), at: +(v.at / v.m).toFixed(2) };
	out.types[t] = {
		pages: T.pages,
		sites: Object.keys(T.families).length,
		length: { min: Math.min(...T.length), max: Math.max(...T.length), mean: +(T.length.reduce((a, b) => a + b, 0) / T.length.length).toFixed(1) },
		roles,
		next: T.next,
		// Each site's distinct orders, the commonest first.
		families: Object.fromEntries(Object.entries(T.families).map(([site, list]) => {
			const count = {};
			list.forEach((r) => { const k = r.join(' '); count[k] = (count[k] || 0) + 1; });
			return [site, Object.entries(count).sort((a, b) => b[1] - a[1]).map(([k, n]) => ({ n, roles: k.split(' ') }))];
		})),
	};
}
fs.mkdirSync(path.dirname(output), { recursive: true });
fs.writeFileSync(output, JSON.stringify(out));
console.log('wrote', output, fs.statSync(output).size, 'bytes;', Object.entries(out.types).map(([t, v]) => t + '=' + v.pages + 'p/' + v.sites + 's').join(' '));

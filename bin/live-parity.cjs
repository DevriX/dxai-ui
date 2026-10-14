#!/usr/bin/env node
/**
 * How far a page made for a design is from the page it replaces on the live site (docs/PLAN-PERFECT-PAGES.md, phase 0).
 *
 *   node bin/live-parity.cjs --pairs <pairs.json> [--out <report.json>]
 *   node bin/live-parity.cjs --self-test
 *
 * pairs.json: { "home": "<the new Home's address>", "live_host": "h2oaway.com",
 *               "pairs": [ { "name": "water", "live": "<live address>", "new": "<new address, or '' when it was not made>" } ] }
 *
 * For every pair both pages are opened at 1440 px (the new one also at 390 px), the header and footer left out, and:
 *   P2  content carried over: every heading, sentence, list item, question, review and figure of the live page, found in the new
 *       page's words (hidden ones too: a closed accordion still says its answer); forms counted;
 *   P3  in the right role: a question of the live page sits in an accordion on the new one, a review among reviews, not elsewhere;
 *   P4  order: the live page's sections as roles (hero, cards, figures, list, reviews, faq, form, cta, text) against the new page's;
 *   P8  address and links: the new page's path is the live one's; no link in its content leads to the old host;
 *   P5  (a part of it) the new page's text colours, backgrounds and font families are the new Home's;
 *   P9  (a part of it) at 390 px nothing makes the page scroll sideways.
 * Reads only. Chrome: $DXAI_CHROME, else the usual places. Exit code 0 (a measurement), 1 when --self-test fails.
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const puppeteer = require( 'puppeteer-core' );

const CHROME = process.env.DXAI_CHROME || [ 'C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe', '/usr/bin/google-chrome', '/usr/bin/chromium', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' ].find( ( p ) => fs.existsSync( p ) );
const args = process.argv.slice( 2 );
const arg = ( name ) => { const i = args.indexOf( '--' + name ); return i >= 0 ? args[ i + 1 ] : ''; };

/* --------------------------------------------------------------- read in the page */

// Runs in the page: the content (outside the header and the footer) as items, sections with roles, forms, links, and the values used.
function read( oldHost ) {
	const norm = ( t ) => String( t || '' ).toLowerCase().replace( /[\u2018\u2019]/g, "'" ).replace( /[^a-z0-9$%+/' ]+/g, ' ' ).replace( /\s+/g, ' ' ).trim();
	const words = ( t ) => norm( t ).split( ' ' ).filter( Boolean ).length;
	const W = document.documentElement.clientWidth;
	const shown = ( el ) => { const r = el.getBoundingClientRect(); const s = getComputedStyle( el ); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none'; };
	const chromeSel = 'header, footer, nav, [role="banner"], [role="contentinfo"], [role="navigation"]';
	const inChrome = ( el ) => !! el.closest( chromeSel );
	// A figure: a big number on its own (65+, 60 min, 24/7, $500).
	const isFigure = ( el, t ) => parseFloat( getComputedStyle( el ).fontSize ) >= 28 && /^[~<>]?\s*[\d$][\d,.]*\s*(\+|%|min|mins|hrs?|k|m)?$|^24\/7$/i.test( t );

	// The body's sections: the full-width children of the element that has the most of them; the deepest wins a tie.
	let root = document.body, best = 0;
	document.querySelectorAll( 'body, body *' ).forEach( ( el ) => {
		if ( /^(SCRIPT|STYLE|SVG|HEADER|FOOTER|NAV)$/i.test( el.tagName ) || inChrome( el ) ) return;
		const n = [ ...el.children ].filter( ( k ) => shown( k ) && k.getBoundingClientRect().width >= W * 0.9 && k.getBoundingClientRect().height >= 40 ).length;
		if ( n >= best ) { best = n; root = el; }
	} );
	const sections = [ ...root.children ].filter( ( k ) => shown( k ) && k.getBoundingClientRect().width >= W * 0.9 && k.getBoundingClientRect().height >= 40 && ! /^(SCRIPT|STYLE|TEMPLATE|LINK)$/.test( k.tagName ) && ! k.matches( chromeSel ) && ! k.querySelector( ':scope > nav' ) );

	// The role an element's words are in.
	const roleOf = ( el ) => {
		if ( el.closest( 'details, [aria-expanded], [class*="faq" i], [class*="accordion" i]' ) ) return 'faq';
		if ( el.closest( 'blockquote, [class*="review" i], [class*="testimonial" i], [class*="trustindex" i], [itemtype*="Review"]' ) ) return 'review';
		if ( el.closest( 'form' ) ) return 'form';
		return 'content';
	};
	// Items: what a reader takes from the page, each with its kind and role.
	const items = [];
	const add = ( kind, text, el ) => { const t = String( text || '' ).replace( /\s+/g, ' ' ).trim(); if ( t && words( t ) >= ( kind === 'figure' ? 1 : 2 ) ) items.push( { kind, text: t, role: roleOf( el ) } ); };
	const content = [ ...document.body.querySelectorAll( '*' ) ].filter( ( el ) => ! inChrome( el ) && ! /^(SCRIPT|STYLE|NOSCRIPT|TEMPLATE|SVG|PATH)$/i.test( el.tagName ) );
	content.forEach( ( el ) => {
		const tag = el.tagName.toLowerCase();
		// A heading in an FAQ that asks is its question (the AMR theme: h3 in .faq-question, the answer beside it).
		if ( /^h[1-6]$/.test( tag ) ) { add( roleOf( el ) === 'faq' && /\?$/.test( el.textContent.trim() ) ? 'question' : 'heading', el.textContent, el ); return; }
		if ( tag === 'summary' || el.matches( '[aria-expanded]' ) && /^(button|a|div|h[1-6]|span)$/.test( tag ) && words( el.textContent ) <= 30 ) { add( 'question', el.textContent, el ); return; }
		if ( tag === 'li' && ! el.querySelector( 'p, li, h1, h2, h3, h4, h5, h6' ) ) { add( 'item', el.textContent, el ); return; }
		if ( /^(p|blockquote|figcaption|dd|dt|td|th)$/.test( tag ) || tag === 'div' && [ ...el.childNodes ].some( ( n ) => n.nodeType === 3 && words( n.textContent ) >= 4 ) ) {
			if ( el.parentElement && el.parentElement.closest( 'p, li, blockquote, summary, [aria-expanded], h1, h2, h3, h4, h5, h6' ) ) return;
			const t = el.textContent.replace( /\s+/g, ' ' ).trim();
			if ( isFigure( el, t ) ) { add( 'figure', t, el ); return; }
			const kind = roleOf( el ) === 'review' ? 'review' : 'sentence';
			// A question written as a heading-like paragraph in an accordion is a question.
			if ( roleOf( el ) === 'faq' && /\?$/.test( t ) && words( t ) <= 30 ) { add( 'question', t, el ); return; }
			t.split( /(?<=[.!?])\s+(?=[A-Z0-9"“])/ ).forEach( ( part ) => { if ( words( part ) >= 4 ) add( kind, part, el ); } );
		}
		// A big figure written in a strong/b/span inside a paragraph.
		if ( /^(strong|b|span)$/.test( tag ) ) {
			const t = el.textContent.replace( /\s+/g, ' ' ).trim();
			if ( isFigure( el, t ) ) add( 'figure', t, el );
		}
	} );
	const text = norm( content.filter( ( el ) => ! el.closest( 'script, style, noscript, template' ) && el.children.length === 0 || /^(p|li|h[1-6]|summary|button|blockquote|figcaption|dd|dt|td|th|a|strong|b|span|cite|label)$/i.test( el.tagName ) ).map( ( el ) => el.textContent ).join( ' ' ) );

	const field = 'input:not([type=hidden]):not([type=search]):not([type=submit]):not([type=button]), textarea, select';
	// A form is a <form> with two fields or more, a form plugin's frame, or a section with two fields or more and no <form> (a step
	// form written a field to a block).
	const forms = [ ...document.querySelectorAll( 'form' ) ].filter( ( f ) => ! inChrome( f ) && f.querySelectorAll( field ).length >= 2 ).length
		+ [ ...document.querySelectorAll( 'iframe[src*="form" i]' ) ].filter( ( f ) => ! inChrome( f ) ).length
		+ sections.filter( ( s ) => ! s.querySelector( 'form' ) && [ ...s.querySelectorAll( field ) ].filter( ( i ) => ! i.closest( 'form' ) ).length >= 2 ).length;
	const oldLinks = oldHost ? [ ...document.querySelectorAll( 'a[href]' ) ].filter( ( a ) => ! inChrome( a ) && ( () => { try { const u = new URL( a.getAttribute( 'href' ), location.href ); return /^https?:$/.test( u.protocol ) && u.hostname.replace( /^www\./, '' ) === oldHost.replace( /^www\./, '' ) && u.hostname !== location.hostname; } catch ( e ) { return false; } } )() ).map( ( a ) => a.getAttribute( 'href' ) ) : [];

	// The role of each section, from what is in it.
	const roles = sections.map( ( sec, i ) => {
		const q = sec.querySelectorAll( 'summary, [aria-expanded]' ).length + [ ...sec.querySelectorAll( '[class*="faq" i] :is(p, h2, h3, h4, h5, h6), [class*="accordion" i] :is(p, h2, h3, h4, h5, h6)' ) ].filter( ( p ) => /\?$/.test( p.textContent.trim() ) ).length;
		const reviews = sec.querySelectorAll( 'blockquote, [class*="review" i]:not([class*="reviews" i]), [class*="testimonial" i]:not([class*="testimonials" i])' ).length;
		const figures = [ ...sec.querySelectorAll( '*' ) ].filter( ( el ) => el.children.length === 0 && isFigure( el, el.textContent.trim() ) ).length;
		const n = words( sec.textContent );
		const grid = [ ...sec.querySelectorAll( '*' ) ].some( ( el ) => { const s = getComputedStyle( el ); if ( s.display !== 'grid' && s.display !== 'flex' ) return false; const kids = [ ...el.children ].filter( shown ); return kids.length >= 3 && new Set( kids.map( ( k ) => k.tagName + k.children.length ) ).size === 1; } );
		if ( i === 0 && sec.querySelector( 'h1' ) ) return 'hero';
		if ( q >= 2 ) return 'faq';
		if ( reviews >= 2 ) return 'reviews';
		if ( sec.querySelector( 'form' ) || sec.querySelectorAll( field ).length >= 2 ) return 'form';
		if ( figures >= 3 ) return 'figures';
		if ( n <= 40 && sec.querySelector( 'a[href^="tel:"], .btn, .button, .wp-block-button, button' ) ) return 'cta';
		if ( grid ) return 'cards';
		if ( sec.querySelectorAll( 'li' ).length >= 3 ) return 'list';
		return 'text';
	} );

	// The values the content uses (the colours of its words and grounds, its families), for the system check.
	const values = { colour: {}, ground: {}, family: {} };
	content.forEach( ( el ) => {
		if ( ! shown( el ) ) return;
		const s = getComputedStyle( el );
		const own = [ ...el.childNodes ].some( ( n ) => n.nodeType === 3 && n.textContent.trim() );
		if ( own ) {
			values.colour[ s.color ] = ( values.colour[ s.color ] || 0 ) + 1;
			const fam = s.fontFamily.split( ',' )[ 0 ].replace( /["']/g, '' ).trim().toLowerCase();
			values.family[ fam ] = ( values.family[ fam ] || 0 ) + 1;
		}
		if ( s.backgroundColor && s.backgroundColor !== 'rgba(0, 0, 0, 0)' && s.backgroundColor !== 'transparent' ) values.ground[ s.backgroundColor ] = ( values.ground[ s.backgroundColor ] || 0 ) + 1;
	} );

	return { path: location.pathname + location.search, items, text, forms, oldLinks, roles, values, height: document.documentElement.scrollHeight };
}

// Runs in the page at a phone's width: what makes it scroll sideways.
function sideways() {
	const W = document.documentElement.clientWidth;
	const past = [ ...document.body.querySelectorAll( '*' ) ].filter( ( el ) => {
		const r = el.getBoundingClientRect();
		return r.width > 0 && r.height > 0 && r.right > W + 1 && getComputedStyle( el ).position !== 'fixed' && [ ...el.childNodes ].some( ( n ) => n.nodeType === 3 && n.textContent.trim() );
	} );
	return { scroll: document.documentElement.scrollWidth > W + 1, past: past.length, sample: past.slice( 0, 3 ).map( ( el ) => el.tagName.toLowerCase() + ': ' + el.textContent.trim().slice( 0, 40 ) ) };
}

/* --------------------------------------------------------------- compare */

const norm = ( t ) => String( t || '' ).toLowerCase().replace( /[\u2018\u2019]/g, "'" ).replace( /[^a-z0-9$%+/' ]+/g, ' ' ).replace( /\s+/g, ' ' ).trim();
const shingles = ( t, n = 4 ) => { const w = norm( t ).split( ' ' ); const out = new Set(); for ( let i = 0; i + n <= w.length; i++ ) out.add( w.slice( i, i + n ).join( ' ' ) ); return out; };
// An item is carried over when its words are in the new page, or (a sentence of 8+ words) 80% of its four-word runs are.
function carried( item, newText, newShingles ) {
	const t = norm( item.text );
	if ( t === '' ) return true;
	if ( ( ' ' + newText + ' ' ).includes( ' ' + t + ' ' ) ) return true;
	if ( t.split( ' ' ).length >= 8 ) {
		const sh = shingles( t );
		let hit = 0;
		sh.forEach( ( s ) => { if ( newShingles.has( s ) ) hit++; } );
		return sh.size > 0 && hit / sh.size >= 0.8;
	}
	return false;
}
function levenshtein( a, b ) {
	const d = Array.from( { length: a.length + 1 }, ( _, i ) => [ i, ...Array( b.length ).fill( 0 ) ] );
	for ( let j = 1; j <= b.length; j++ ) d[ 0 ][ j ] = j;
	for ( let i = 1; i <= a.length; i++ ) for ( let j = 1; j <= b.length; j++ ) d[ i ][ j ] = Math.min( d[ i - 1 ][ j ] + 1, d[ i ][ j - 1 ] + 1, d[ i - 1 ][ j - 1 ] + ( a[ i - 1 ] === b[ j - 1 ] ? 0 : 1 ) );
	return d[ a.length ][ b.length ];
}
const KINDS = [ 'heading', 'sentence', 'item', 'question', 'review', 'figure' ];

function compare( live, made, home, mobile ) {
	const out = { kinds: {}, lost: [], misroled: [] };
	const unique = [];
	const seen = new Set();
	live.items.forEach( ( it ) => { const k = it.kind + '|' + norm( it.text ); if ( ! seen.has( k ) ) { seen.add( k ); unique.push( it ); } } );
	const newSh = made ? shingles( made.text ) : new Set();
	unique.forEach( ( it ) => {
		const k = out.kinds[ it.kind ] = out.kinds[ it.kind ] || { total: 0, carried: 0 };
		k.total++;
		if ( made && carried( it, made.text, newSh ) ) {
			k.carried++;
			// The role: a question or a review of the live page is found in the same role on the new one.
			if ( ( it.kind === 'question' || it.kind === 'review' ) && ( it.role === 'faq' || it.role === 'review' ) ) {
				const t = norm( it.text );
				const there = made.items.filter( ( m ) => norm( m.text ).includes( t ) || t.includes( norm( m.text ) ) && norm( m.text ).split( ' ' ).length >= 4 );
				if ( there.length && ! there.some( ( m ) => m.role === it.role ) ) out.misroled.push( it.kind + ' (' + it.role + ' → ' + there[ 0 ].role + '): ' + it.text.slice( 0, 70 ) );
			}
		} else {
			out.lost.push( it.kind + ': ' + it.text.slice( 0, 90 ) );
		}
	} );
	const total = unique.length;
	const got = Object.values( out.kinds ).reduce( ( a, k ) => a + k.carried, 0 );
	out.coverage = total ? got / total : 1;
	out.forms = { live: live.forms, made: made ? made.forms : 0, lost: Math.max( 0, live.forms - ( made ? made.forms : 0 ) ) };
	out.order = made ? 1 - levenshtein( live.roles, made.roles ) / Math.max( live.roles.length, made.roles.length, 1 ) : 0;
	out.roles = { live: live.roles.join( ' ' ), made: made ? made.roles.join( ' ' ) : '' };
	out.path = { live: live.path, made: made ? made.path : '', same: !! made && live.path.replace( /\/$/, '' ) === made.path.replace( /\/$/, '' ) };
	out.oldLinks = made ? made.oldLinks.length : 0;
	out.oldLinkSample = made ? made.oldLinks.slice( 0, 3 ) : [];
	if ( made && home ) {
		const foreign = ( kind ) => Object.entries( made.values[ kind ] ).filter( ( [ v ] ) => ! ( v in home.values[ kind ] ) );
		out.system = { colour: foreign( 'colour' ), ground: foreign( 'ground' ), family: foreign( 'family' ) };
	}
	out.mobile = mobile || null;
	out.height = { live: live.height, made: made ? made.height : 0 };
	out.sections = { live: live.roles.length, made: made ? made.roles.length : 0 };
	return out;
}

/* --------------------------------------------------------------- run */

async function open( browser, url, width, fn, arg ) {
	const page = await browser.newPage();
	try {
		await page.setViewport( { width, height: 900 } );
		await page.goto( url, { waitUntil: 'networkidle2', timeout: 90000 } ).catch( () => null );
		// Lazy pictures and reveals: walk down the page once.
		await page.evaluate( async () => { for ( let y = 0; y < document.documentElement.scrollHeight; y += 700 ) { window.scrollTo( 0, y ); await new Promise( ( r ) => setTimeout( r, 80 ) ); } window.scrollTo( 0, 0 ); } ).catch( () => null );
		await new Promise( ( r ) => setTimeout( r, 600 ) );
		return await page.evaluate( fn, arg );
	} finally {
		await page.close();
	}
}

async function measure( spec ) {
	const browser = await puppeteer.launch( { executablePath: CHROME, headless: 'shell', args: [ '--no-sandbox', '--allow-file-access-from-files' ], protocolTimeout: 300000 } );
	const host = spec.live_host || '';
	const report = { home: spec.home || '', live_host: host, pages: [] };
	try {
		const home = spec.home ? await open( browser, spec.home, 1440, read, host ) : null;
		for ( const pair of spec.pairs ) {
			const live = await open( browser, pair.live, 1440, read, host );
			const made = pair.new ? await open( browser, pair.new, 1440, read, host ) : null;
			const mobile = pair.new ? await open( browser, pair.new, 390, sideways ) : null;
			const r = compare( live, made, home, mobile );
			r.name = pair.name;
			r.live = pair.live;
			r.new = pair.new || '';
			report.pages.push( r );
			if ( ! spec.quiet ) console.log( line( r ) );
		}
	} finally {
		await browser.close();
	}
	return report;
}

const pct = ( x ) => ( x * 100 ).toFixed( 0 ) + '%';
function line( r ) {
	const k = ( kind ) => r.kinds[ kind ] ? r.kinds[ kind ].carried + '/' + r.kinds[ kind ].total : '–';
	const sys = r.system ? r.system.colour.length + r.system.ground.length + r.system.family.length : '–';
	return [ r.name.padEnd( 16 ), ( r.new ? '' : 'NOT MADE ' ) + 'P2 ' + pct( r.coverage ).padStart( 4 ), 'head ' + k( 'heading' ), 'sent ' + k( 'sentence' ), 'list ' + k( 'item' ), 'q ' + k( 'question' ), 'rev ' + k( 'review' ), 'fig ' + k( 'figure' ), 'forms -' + r.forms.lost, 'P3 ' + r.misroled.length, 'P4 ' + pct( r.order ), 'P8 ' + ( r.path.same ? 'path' : 'other path' ) + ', old links ' + r.oldLinks, 'P5 ' + sys, 'P9 ' + ( r.mobile ? ( r.mobile.scroll ? 'SCROLLS' : 'ok' ) : '–' ), r.sections.live + '/' + r.sections.made + ' sections', r.height.live + '/' + r.height.made + ' px' ].join( ' · ' );
}

async function selfTest() {
	const dir = path.join( __dirname, '..', 'fixtures', 'live-parity' );
	const url = ( f ) => 'file:///' + path.join( dir, f ).replace( /\\/g, '/' );
	const report = await measure( {
		quiet: true,
		home: url( 'home.html' ),
		live_host: 'live.example',
		pairs: [
			{ name: 'good', live: url( 'live.html' ), new: url( 'new-good.html' ) },
			{ name: 'bad', live: url( 'live.html' ), new: url( 'new-bad.html' ) },
			{ name: 'missing', live: url( 'live.html' ), new: '' },
		],
	} );
	const [ good, bad, missing ] = report.pages;
	let pass = 0, fail = 0;
	const expect = ( what, ok, detail ) => { if ( ok ) { pass++; console.log( '  ok   ' + what ); } else { fail++; console.log( '  FAIL ' + what + ( detail !== undefined ? ' — ' + JSON.stringify( detail ) : '' ) ); } };
	console.log( 'A page carried over well:' );
	expect( 'every item of the live page is on it', good.coverage === 1, good.lost );
	expect( 'the live page has questions, reviews, list items, figures and sentences to find', [ 'question', 'review', 'item', 'figure', 'sentence', 'heading' ].every( ( k ) => good.kinds[ k ] && good.kinds[ k ].total >= 2 ), good.kinds );
	expect( 'three questions (one an h3 in .faq-question) and three figures (one ~60 min)', good.kinds.question.total === 3 && good.kinds.figure.total === 3, good.kinds );
	expect( 'no question or review in another role', good.misroled.length === 0, good.misroled );
	expect( 'no form lost', good.forms.lost === 0 && good.forms.live === 1, good.forms );
	expect( 'the same order of sections', good.order === 1, good.roles );
	expect( 'no link to the old host', good.oldLinks === 0, good.oldLinkSample );
	expect( 'only the Home\'s colours, grounds and families', good.system && good.system.colour.length + good.system.ground.length + good.system.family.length === 0, good.system );
	expect( 'no sideways scroll at 390 px', good.mobile && ! good.mobile.scroll, good.mobile );
	console.log( 'A page carried over badly:' );
	expect( 'the lost list item is named', bad.lost.some( ( l ) => l.includes( 'Moisture readings documented with photos' ) ), bad.lost );
	expect( 'coverage under 100%', bad.coverage < 1, bad.coverage );
	expect( 'the three questions are found among reviews', bad.misroled.filter( ( m ) => m.startsWith( 'question (faq → review)' ) ).length === 3, bad.misroled );
	expect( 'the form is lost', bad.forms.lost === 1, bad.forms );
	expect( 'the order differs', bad.order < 1, bad.roles );
	expect( 'the link to the old host is found', bad.oldLinks === 1, bad.oldLinkSample );
	expect( 'the foreign ground and family are found', bad.system && bad.system.ground.some( ( [ v ] ) => v === 'rgb(0, 255, 0)' ) && bad.system.family.some( ( [ v ] ) => v === 'courier new' ), bad.system );
	expect( 'it scrolls sideways at 390 px', bad.mobile && bad.mobile.scroll, bad.mobile );
	console.log( 'A page not made:' );
	expect( 'nothing is carried over', missing.coverage === 0 && missing.lost.length > 10, missing.coverage );
	expect( 'its form is lost', missing.forms.lost === 1, missing.forms );
	console.log( `\n${ pass } passed, ${ fail } failed` );
	return fail === 0;
}

( async () => {
	if ( ! CHROME ) {
		console.error( 'no Chrome found (set DXAI_CHROME)' );
		process.exit( 2 );
	}
	if ( args.includes( '--self-test' ) ) {
		process.exit( ( await selfTest() ) ? 0 : 1 );
	}
	const file = arg( 'pairs' );
	if ( ! file ) {
		console.error( 'usage: node bin/live-parity.cjs --pairs <pairs.json> [--out <report.json>] | --self-test' );
		process.exit( 2 );
	}
	const report = await measure( JSON.parse( fs.readFileSync( file, 'utf8' ) ) );
	const pages = report.pages;
	const avg = ( f ) => pages.length ? pages.reduce( ( a, p ) => a + f( p ), 0 ) / pages.length : 0;
	console.log( `\n${ pages.length } pages, ${ pages.filter( ( p ) => p.new ).length } made: content carried ${ pct( avg( ( p ) => p.coverage ) ) } on average, ${ pages.reduce( ( a, p ) => a + p.misroled.length, 0 ) } items in another role, ${ pages.reduce( ( a, p ) => a + p.forms.lost, 0 ) } forms lost, order ${ pct( avg( ( p ) => p.order ) ) }, ${ pages.filter( ( p ) => p.path.same ).length } at the live address, ${ pages.reduce( ( a, p ) => a + p.oldLinks, 0 ) } links to the old host` );
	if ( arg( 'out' ) ) fs.writeFileSync( arg( 'out' ), JSON.stringify( report, null, 1 ) );
} )();

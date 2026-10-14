#!/usr/bin/env node
/**
 * PageSpeed in the lab: Lighthouse with pagespeed.web.dev's mobile profile (a mid-range phone, slow 4G simulated) on pages of
 * this site or any other (docs/PLAN-PERFECT-PAGES.md, P13).
 *
 *   node bin/page-speed.cjs <url>... [--runs 3] [--desktop] [--warm] [--out report.json]
 *
 * For each page: the four scores (Performance, Accessibility, Best Practices, SEO), FCP, LCP, TBT, CLS and Speed Index, the
 * LCP element, and every audit that does not pass, with what it would save. With --runs the median run by Performance is
 * kept (Lighthouse varies from run to run). Reads only. Chrome: $DXAI_CHROME, else the usual places. Exit code 0.
 *
 * What is the host's and not the page's — the time to the first byte, HTTP/2, HTTPS, cache headers, compression — is listed
 * apart. With --warm the site is served as a good host serves it (warm()): from memory, gzipped, static files cached for a
 * year — so a local site whose PHP takes seconds to answer, uncompressed, is measured for its page, not for its server.
 */
'use strict';

const fs = require( 'fs' );
const http = require( 'http' );
const zlib = require( 'zlib' );
const os = require( 'os' );
const path = require( 'path' );

// The audits that measure the host, not the page.
const HOST = new Set( [ 'server-response-time', 'document-latency-insight', 'uses-http2', 'modern-http-insight', 'is-on-https', 'redirects-http', 'uses-long-cache-ttl', 'cache-insight', 'uses-text-compression' ] );

/**
 * The site as a good host serves it: every file of its own address from memory (a page cache), compressed, the static ones
 * with a year's cache. The page and its CSS are rewritten to ask this server for the site's files; other hosts are untouched.
 */
async function warm( url ) {
	const u = new URL( url );
	const files = new Map();
	const server = http.createServer( async ( req, out ) => {
		const origin = 'http://127.0.0.1:' + server.address().port;
		try {
			let f = files.get( req.url );
			if ( ! f ) {
				const r = await fetch( u.origin + req.url, { redirect: 'follow' } );
				const type = r.headers.get( 'content-type' ) || 'application/octet-stream';
				let body = Buffer.from( await r.arrayBuffer() );
				const text = /text\/|javascript|json|xml|svg/.test( type );
				if ( text ) {
					// The site's own address, plain and as WordPress writes it in JSON (http:\/\/host), now this server's.
					body = Buffer.from( body.toString( 'utf8' ).split( u.origin ).join( origin ).split( u.origin.replace( /\//g, '\\/' ) ).join( origin.replace( /\//g, '\\/' ) ) );
				}
				f = { status: r.status, type, body, gz: text ? zlib.gzipSync( body ) : null, page: /text\/html/.test( type ) };
				files.set( req.url, f );
			}
			const gzip = f.gz && /gzip/.test( String( req.headers[ 'accept-encoding' ] || '' ) );
			out.writeHead( f.status, { 'Content-Type': f.type, 'Cache-Control': f.page ? 'no-cache' : 'public, max-age=31536000, immutable', Vary: 'Accept-Encoding', ...( gzip ? { 'Content-Encoding': 'gzip' } : {} ) } );
			out.end( gzip ? f.gz : f.body );
		} catch ( e ) {
			out.writeHead( 502 );
			out.end();
		}
	} );
	await new Promise( ( r ) => server.listen( 0, '127.0.0.1', r ) );
	const local = 'http://127.0.0.1:' + server.address().port + u.pathname + u.search;
	// Warm the page itself before it is measured.
	await fetch( local ).then( ( r ) => r.arrayBuffer() );
	return { url: local, close: () => server.close() };
}

const CHROME = process.env.DXAI_CHROME || [ 'C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe', '/usr/bin/google-chrome', '/usr/bin/chromium', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' ].find( ( p ) => fs.existsSync( p ) );
const args = process.argv.slice( 2 );
const opt = ( name, fallback ) => { const i = args.indexOf( '--' + name ); return i >= 0 ? args[ i + 1 ] : fallback; };
const urls = args.filter( ( a, i ) => /^https?:\/\//.test( a ) && ! [ '--runs', '--out' ].includes( args[ i - 1 ] ) );
const RUNS = Math.max( 1, parseInt( opt( 'runs', '1' ), 10 ) || 1 );
const DESKTOP = args.includes( '--desktop' );
const CATEGORIES = [ 'performance', 'accessibility', 'best-practices', 'seo' ];
const METRICS = { 'first-contentful-paint': 'FCP', 'largest-contentful-paint': 'LCP', 'total-blocking-time': 'TBT', 'cumulative-layout-shift': 'CLS', 'speed-index': 'SI' };

function summary( lhr ) {
	const scores = {};
	CATEGORIES.forEach( ( c ) => { scores[ c ] = lhr.categories[ c ] ? Math.round( lhr.categories[ c ].score * 100 ) : null; } );
	const metrics = {};
	Object.entries( METRICS ).forEach( ( [ id, short ] ) => { const a = lhr.audits[ id ]; metrics[ short ] = a ? { value: a.numericValue, shown: a.displayValue } : null; } );
	const lcp = lhr.audits[ 'largest-contentful-paint-element' ];
	const lcpNode = lcp && lcp.details && lcp.details.items && lcp.details.items[ 0 ] && ( lcp.details.items[ 0 ].items || [] )[ 0 ];
	// Every audit that does not pass and counts (not informative, manual or not applicable).
	const failing = Object.values( lhr.audits )
		.filter( ( a ) => a.score !== null && a.score < 0.9 && ! [ 'informative', 'manual', 'notApplicable' ].includes( a.scoreDisplayMode ) )
		.map( ( a ) => ( { id: a.id, title: a.title, shown: a.displayValue || '', savings: a.metricSavings || {}, host: HOST.has( a.id ) } ) )
		.sort( ( x, y ) => ( ( y.savings.LCP || 0 ) + ( y.savings.FCP || 0 ) ) - ( ( x.savings.LCP || 0 ) + ( x.savings.FCP || 0 ) ) );
	// What the page weighs: in all, its HTML, its CSS, its JS, its pictures, its fonts; and its DOM.
	const reqs = ( lhr.audits[ 'network-requests' ] && lhr.audits[ 'network-requests' ].details && lhr.audits[ 'network-requests' ].details.items ) || [];
	const kb = ( type ) => Math.round( reqs.filter( ( r ) => r.resourceType === type ).reduce( ( a, r ) => a + ( r.transferSize || 0 ), 0 ) / 1024 );
	const weight = { total: Math.round( ( lhr.audits[ 'total-byte-weight' ] || {} ).numericValue / 1024 ) || 0, html: kb( 'Document' ), css: kb( 'Stylesheet' ), js: kb( 'Script' ), img: kb( 'Image' ), font: kb( 'Font' ), dom: ( lhr.audits[ 'dom-size' ] || {} ).numericValue || 0 };
	return { weight, url: lhr.finalDisplayedUrl || lhr.requestedUrl, scores, metrics, lcp: lcpNode && lcpNode.node ? ( lcpNode.node.snippet || lcpNode.node.nodeLabel || '' ).slice( 0, 140 ) : '', failing, version: lhr.lighthouseVersion };
}

( async () => {
	if ( ! CHROME || urls.length === 0 ) {
		console.error( ! CHROME ? 'no Chrome found (set DXAI_CHROME)' : 'usage: node bin/page-speed.cjs <url>... [--runs 3] [--desktop] [--out report.json]' );
		process.exit( 2 );
	}
	const { default: lighthouse } = await import( 'lighthouse' );
	const chromeLauncher = await import( 'chrome-launcher' );
	const report = [];
	for ( const url of urls ) {
		const runs = [];
		// One server a page for all its runs: the first run fills its cache, as a host's would be.
		const served = args.includes( '--warm' ) ? await warm( url ) : null;
		// A run that fails (Lighthouse loses the tab now and then) is tried again, up to two more times in all.
		let failed = '';
		for ( let i = 0, tries = 0; runs.length < RUNS && tries < RUNS + 2; i++, tries++ ) {
			// A profile of its own: the launcher removes a profile it made itself, and on Windows Chrome may still hold it (EPERM).
			const profile = fs.mkdtempSync( path.join( os.tmpdir(), 'dxai-page-speed-' ) );
			const chrome = await chromeLauncher.launch( { chromePath: CHROME, userDataDir: profile, chromeFlags: [ '--headless=new', '--no-sandbox', '--disable-gpu' ] } );
			try {
				const flags = { port: chrome.port, output: 'json', logLevel: 'error', onlyCategories: CATEGORIES };
				const config = DESKTOP ? ( await import( 'lighthouse/core/config/desktop-config.js' ) ).default : undefined;
				const result = await lighthouse( served ? served.url : url, flags, config );
				const s = summary( result.lhr );
				s.url = url;
				runs.push( s );
			} catch ( e ) {
				failed = String( e && e.message || e ).slice( 0, 160 );
			} finally {
				// On Windows Chrome can still hold its temporary profile when the launcher removes it (EPERM): the run is done either way.
				try {
					await chrome.kill();
				} catch ( e ) {
					if ( e.code !== 'EPERM' && e.code !== 'EBUSY' ) throw e;
				}
				// The profile goes once Chrome lets go of it (a few tries, then it is left to the system's temp cleaning).
				for ( let t = 0; t < 5 && fs.existsSync( profile ); t++ ) {
					try {
						fs.rmSync( profile, { recursive: true, force: true } );
					} catch ( e ) {
						await new Promise( ( r ) => setTimeout( r, 400 ) );
					}
				}
			}
		}
		if ( served ) served.close();
		if ( runs.length === 0 ) {
			report.push( { url, error: failed } );
			console.log( `${ url }\n  ERROR: no run finished — ${ failed }` );
			continue;
		}
		runs.sort( ( a, b ) => ( a.scores.performance || 0 ) - ( b.scores.performance || 0 ) );
		const r = runs[ Math.floor( runs.length / 2 ) ];
		r.runs = runs.map( ( x ) => x.scores.performance );
		report.push( r );
		const m = r.metrics;
		console.log( `${ r.url }\n  ${ CATEGORIES.map( ( c ) => r.scores[ c ] ).join( ' / ' ) }  (Performance / Accessibility / Best Practices / SEO${ RUNS > 1 ? ', Performance of the runs ' + r.runs.join( ' ' ) : '' })` );
		console.log( `  FCP ${ m.FCP && m.FCP.shown } · LCP ${ m.LCP && m.LCP.shown } · TBT ${ m.TBT && m.TBT.shown } · CLS ${ m.CLS && m.CLS.shown } · SI ${ m.SI && m.SI.shown }` );
		if ( r.lcp ) console.log( `  LCP element: ${ r.lcp }` );
		const w = r.weight;
		console.log( `  weight ${ w.total } KiB: HTML ${ w.html }, CSS ${ w.css }, JS ${ w.js }, pictures ${ w.img }, fonts ${ w.font } KiB · ${ w.dom } DOM nodes` );
		r.failing.filter( ( a ) => ! a.host ).slice( 0, 12 ).forEach( ( a ) => console.log( `  - ${ a.title }${ a.shown ? ' — ' + a.shown : '' }` ) );
		const host = r.failing.filter( ( a ) => a.host );
		if ( host.length ) console.log( `  host (not the page): ${ host.map( ( a ) => a.title + ( a.shown ? ' — ' + a.shown : '' ) ).join( '; ' ) }` );
	}
	if ( opt( 'out', '' ) ) fs.writeFileSync( opt( 'out', '' ), JSON.stringify( report, null, 1 ) );
	console.log( `\nLighthouse ${ report[ 0 ] && report[ 0 ].version }, ${ DESKTOP ? 'desktop' : 'mobile (pagespeed.web.dev\'s profile)' }, ${ RUNS } run(s) a page` );
} )();

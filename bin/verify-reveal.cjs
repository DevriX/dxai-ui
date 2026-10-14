#!/usr/bin/env node
/**
 * The page runtime's reveal on approach never hides what is on screen as the page opens (docs/PLAN-PERFECT-PAGES.md, T5.6).
 *
 *   node bin/verify-reveal.cjs [--from <Motion_Runtime.php>]
 *
 * A section that fades in is hidden (`is-armed`: opacity 0) until it is revealed. The runtime armed every one of them, those
 * already on screen too, so the first screen was blank until the script ran and the fade played, and Largest Contentful Paint
 * waited for both (norths.dxdemos.eu: the LCP element a paragraph, at 5.0 s). Against a page made of the runtime itself (its
 * script and rules from src/Compiler/Motion_Runtime.php, by PHP — no site), in a browser: the section on screen is never armed
 * and is in; the one far below is armed, not in, and comes in when scrolled to. --from runs another copy of the runtime's source
 * (the code before a change, to see the check fail). And it reads every box before it changes a class: on a page of 24 sections it
 * lays the page out once as it starts, not once per section (Lighthouse's "Forced reflow").
 *
 * Needs PHP ($DXAI_PHP, else `php`) and Chrome ($DXAI_CHROME, else the usual places). Exit code 1 when a check fails.
 */
'use strict';

const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );
const puppeteer = require( 'puppeteer-core' );

const args = process.argv.slice( 2 );
const from = args.includes( '--from' ) ? path.resolve( args[ args.indexOf( '--from' ) + 1 ] ) : path.join( __dirname, '..', 'src', 'Compiler', 'Motion_Runtime.php' );
const PHP = process.env.DXAI_PHP || 'php';
const CHROME = process.env.DXAI_CHROME || [ 'C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe', '/usr/bin/google-chrome', '/usr/bin/chromium', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' ].find( ( p ) => fs.existsSync( p ) );

// The runtime's script and its rules, as the plugin prints them.
const probe = path.join( fs.mkdtempSync( path.join( os.tmpdir(), 'dxai-reveal-' ) ), 'runtime.php' );
fs.writeFileSync( probe, '<?php require ' + JSON.stringify( from ) + '; echo \\DXAI_UI\\Compiler\\Motion_Runtime::javascript(), "\\n/*--css--*/\\n", \\DXAI_UI\\Compiler\\Motion_Runtime::css( ".dxai-ui" );' );
const php = spawnSync( PHP, process.env.DXAI_PHP_INI ? [ '-c', process.env.DXAI_PHP_INI, probe ] : [ probe ], { encoding: 'utf8' } );
fs.rmSync( path.dirname( probe ), { recursive: true, force: true } );
if ( php.status !== 0 || ! php.stdout.includes( '/*--css--*/' ) ) {
	console.log( 'SKIP: PHP could not print the runtime (' + ( php.stderr || php.error || '' ).toString().trim().slice( 0, 200 ) + ')' );
	process.exit( 77 );
}
const [ js, css ] = php.stdout.split( '\n/*--css--*/\n' );
if ( ! CHROME ) {
	console.log( 'SKIP: no Chrome found (set DXAI_CHROME)' );
	process.exit( 77 );
}

const html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
	+ '<style>body{margin:0;font:16px sans-serif}' + css + ' .dxai-reveal{transition:opacity .6s,transform .6s}</style></head><body>'
	+ '<div class="dxai-ui"><section class="dxai-reveal" id="top"><h1>On screen as the page opens</h1><p>The first thing a visitor sees.</p></section>'
	+ '<div style="height:4000px"></div><section class="dxai-reveal" id="below"><h2>Far below</h2><p>Comes in when scrolled to.</p></section></div>'
	// Every class #top ever has, from before the runtime runs.
	+ '<script>window.__classes=[];new MutationObserver(function(){window.__classes.push(document.getElementById("top").className);}).observe(document.getElementById("top"),{attributes:true,attributeFilter:["class"]});</script>'
	+ '<script>' + js + '</script></body></html>';

( async () => {
	let pass = 0, fail = 0;
	const expect = ( what, ok, detail ) => { if ( ok ) { pass++; console.log( '  ok    ' + what ); } else { fail++; console.log( '  FAIL  ' + what + ( detail !== undefined ? '  — ' + JSON.stringify( detail ) : '' ) ); } };
	const browser = await puppeteer.launch( { executablePath: CHROME, headless: 'shell', args: [ '--no-sandbox' ] } );
	try {
		const page = await browser.newPage();
		await page.setViewport( { width: 1280, height: 800 } );
		await page.setContent( html, { waitUntil: 'load' } );
		await new Promise( ( r ) => setTimeout( r, 400 ) );
		const at = await page.evaluate( () => ( { top: document.getElementById( 'top' ).className, below: document.getElementById( 'below' ).className, seen: window.__classes } ) );
		console.log( 'The section on screen as the page opens' );
		expect( 'it is never armed (hidden for an entrance), not even for a moment', ! at.seen.some( ( c ) => /\bis-armed\b/.test( c ) ), at.seen );
		expect( 'it is in', /\bis-in\b/.test( at.top ), at.top );
		console.log( 'The section far below' );
		expect( 'it is armed and not in until it is reached', /\bis-armed\b/.test( at.below ) && ! /\bis-in\b/.test( at.below ), at.below );
		await page.evaluate( () => document.getElementById( 'below' ).scrollIntoView() );
		await new Promise( ( r ) => setTimeout( r, 500 ) );
		const after = await page.evaluate( () => document.getElementById( 'below' ).className );
		expect( 'it comes in when scrolled to', /\bis-in\b/.test( after ), after );

			// 24 sections, six of them on screen: the runtime reads every box before it changes a class. Read after a change, each box
			// lays the page out again (Lighthouse's "Forced reflow"; the first version of the on-screen rule did it 25 times).
			let sections = '';
			for ( let i = 0; i < 24; i++ ) {
				sections += '<section class="dxai-reveal" style="height:120px"><h2>Section ' + i + '</h2></section>';
			}
			const many = await browser.newPage();
			await many.setViewport( { width: 1280, height: 800 } );
			await many.setContent( '<!doctype html><html><head><meta charset="utf-8"><style>body{margin:0}' + css + '</style></head><body><div class="dxai-ui">' + sections + '</div>'
				+ '<script>window.__run=function(){' + js + '}</script></body></html>', { waitUntil: 'load' } );
			await new Promise( ( r ) => setTimeout( r, 200 ) );
			const cdp = await many.target().createCDPSession();
			await cdp.send( 'Performance.enable' );
			const metrics = async () => Object.fromEntries( ( await cdp.send( 'Performance.getMetrics' ) ).metrics.map( ( x ) => [ x.name, x.value ] ) );
			const before = await metrics();
			await many.evaluate( () => window.__run() );
			await new Promise( ( r ) => setTimeout( r, 400 ) );
			const done = await metrics();
			const recalc = done.RecalcStyleCount - before.RecalcStyleCount;
			const layout = done.LayoutCount - before.LayoutCount;
			console.log( 'A page of 24 sections' );
			expect( 'the runtime lays it out once as it starts and sweeps, not once per section (style ≤ 3, layout ≤ 2)', recalc <= 3 && layout <= 2, { recalc, layout } );
	} finally {
		await browser.close();
	}
	console.log( `\n${ pass } passed, ${ fail } failed` );
	process.exit( fail > 0 ? 1 : 0 );
} )();

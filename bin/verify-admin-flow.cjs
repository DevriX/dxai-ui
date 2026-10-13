#!/usr/bin/env node
/**
 * The admin screens in a browser: Designs opens, a design opens from it, every tab of the design opens, every other
 * screen opens — with no error in the console, within a height a person can take in, and the old Library address lands
 * on Designs.
 *
 *   node bin/verify-admin-flow.cjs --base http://site.local/prodtest2 \
 *        --cookie-name <logged_in name> --cookie-value <value> --auth-name <auth name> --auth-value <value> [--design <id>] [--out dir]
 *
 * The cookies come from bin/editor-roundtrip-cookie.php (wp eval-file bin/editor-roundtrip-cookie.php), minted for the
 * TEST site: nothing here types a password. --out saves a screenshot of each screen and tab.
 *
 * What is measured (docs/PLAN-ADMIN-UX.md, U1): Designs at most 2 000 px tall at 1440 wide with up to twenty designs;
 * every tab of a design at most 4 000 px; zero console errors on every page; a design's tab is three clicks from the
 * menu (Designs → the design → the tab). Exit code 1 when a check fails.
 */
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );

const CHROME = process.env.DXAI_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PUPPETEER = path.join( __dirname, '..', 'node_modules', 'puppeteer-core' );

function arg( name, fallback ) {
	const i = process.argv.indexOf( '--' + name );
	return i > -1 && process.argv[ i + 1 ] ? process.argv[ i + 1 ] : fallback;
}

let pass = 0;
let fail = 0;
const expect = ( what, ok, detail = '' ) => {
	if ( ok ) {
		pass++;
		console.log( '  ok    ' + what );
	} else {
		fail++;
		console.log( '  FAIL  ' + what + ( detail ? '  — ' + detail : '' ) );
	}
};
const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

( async () => {
	const base = arg( 'base', '' ).replace( /\/$/, '' );
	const cookieName = arg( 'cookie-name' );
	const cookieValue = arg( 'cookie-value' );
	const authName = arg( 'auth-name' );
	const authValue = arg( 'auth-value' );
	const wanted = Number( arg( 'design', '0' ) );
	const out = arg( 'out', '' );
	if ( ! base || ! cookieName || ! cookieValue ) {
		console.error( 'missing required argument (--base, --cookie-name, --cookie-value)' );
		process.exit( 2 );
	}
	if ( out && ! fs.existsSync( out ) ) {
		fs.mkdirSync( out, { recursive: true } );
	}
	const host = new URL( base ).hostname;
	const admin = base + '/wp-admin/admin.php?page=';

	const puppeteer = require( PUPPETEER );
	const profile = path.join( os.tmpdir(), 'dxai-admin-flow-' + process.pid );
	const browser = await puppeteer.launch( { executablePath: CHROME, headless: 'shell', userDataDir: profile, args: [ '--no-sandbox' ] } );
	const page = await browser.newPage();
	await page.setViewport( { width: 1440, height: 900 } );
	const cookies = [ { name: cookieName, value: cookieValue, domain: host, path: '/' } ];
	if ( authName && authValue ) {
		cookies.push( { name: authName, value: authValue, domain: host, path: new URL( base ).pathname.replace( /\/$/, '' ) + '/wp-admin' } );
	}
	await page.setCookie( ...cookies );

	// Every error the page prints, by the page it was on.
	let errors = [];
	page.on( 'console', ( m ) => {
		if ( m.type() === 'error' ) {
			errors.push( m.text().slice( 0, 200 ) );
		}
	} );
	page.on( 'pageerror', ( e ) => errors.push( String( e.message || e ).slice( 0, 200 ) ) );

	const settled = async ( ms = 20000 ) => {
		// The app has drawn its page and no spinner is left.
		const until = Date.now() + ms;
		while ( Date.now() < until ) {
			const done = await page.evaluate( () => {
				const app = document.getElementById( 'dxai-ui-app' );
				return !! ( app && app.querySelector( '.dxai-page' ) && ! app.querySelector( '.components-spinner' ) && ! app.querySelector( '.dxai-ui-php-fallback' ) );
			} );
			if ( done ) {
				await sleep( 300 );
				return true;
			}
			await sleep( 250 );
		}
		return false;
	};
	const height = () => page.evaluate( () => document.documentElement.scrollHeight );
	const shot = async ( name ) => {
		if ( out ) {
			await page.screenshot( { path: path.join( out, name + '.png' ), fullPage: true } );
		}
	};
	const open = async ( url, what, max ) => {
		errors = [];
		await page.goto( url, { waitUntil: 'networkidle2', timeout: 120000 } );
		const ok = await settled();
		const h = await height();
		const title = await page.evaluate( () => ( document.querySelector( '#dxai-ui-app h1' ) || {} ).textContent || '' );
		expect( what + ' opens (h1 "' + title.trim() + '", ' + h + ' px)', ok && title.trim() !== '', ok ? 'no title' : 'the app did not settle' );
		expect( what + ': no error in the console', errors.length === 0, errors.join( ' | ' ) );
		if ( max ) {
			expect( what + ': at most ' + max + ' px tall', h <= max, h + ' px' );
		}
		return h;
	};

	console.log( '\n1. The screens' );
	await open( admin + 'dxai-ui', 'Designs', 2000 );
	await shot( 'designs' );
	const rows = await page.evaluate( () => document.querySelectorAll( '.dxai-designs tbody tr .dxai-designs__title' ).length );
	const empty = await page.evaluate( () => !! document.querySelector( '.dxai-empty' ) );
	expect( 'the list has designs, or says there are none', rows > 0 || empty, rows + ' rows' );
	const chips = await page.evaluate( () => document.querySelectorAll( '.dxai-designs tbody tr:first-child .dxai-chip' ).length );
	if ( rows > 0 ) {
		expect( 'a row says where its design is (six chips)', chips === 6, chips + ' chips' );
	}
	for ( const [ slug, name ] of [ [ 'dxai-ui-convert', 'Convert' ], [ 'dxai-ui-site', 'Site' ], [ 'dxai-ui-forms', 'Form entries' ], [ 'dxai-ui-settings', 'Settings' ] ] ) {
		await open( admin + slug, name );
		await shot( slug );
	}
	errors = [];
	await page.goto( admin + 'dxai-ui-library', { waitUntil: 'networkidle2', timeout: 120000 } );
	expect( 'the old Library address lands on Designs', /page=dxai-ui(?:#.*)?$/.test( page.url() ), page.url() );

	console.log( '\n2. A design and its tabs' );
	if ( rows === 0 ) {
		console.log( '  skip  no design on this site' );
	} else {
		await page.goto( admin + 'dxai-ui', { waitUntil: 'networkidle2', timeout: 120000 } );
		await settled();
		// Click 1: the menu's Designs (we are on it). Click 2: the design. Click 3: a tab.
		const picked = wanted > 0 ? '#/design/' + wanted : await page.evaluate( () => document.querySelector( '.dxai-designs tbody tr:first-child .dxai-designs__title' ).getAttribute( 'href' ) );
		errors = [];
		await page.click( '.dxai-designs tbody tr:first-child .dxai-designs__title' );
		if ( wanted > 0 ) {
			await page.evaluate( ( h ) => { window.location.hash = h; }, picked );
		}
		const ok = await settled();
		const title = await page.evaluate( () => ( document.querySelector( '#dxai-ui-app h1' ) || {} ).textContent || '' );
		expect( 'a design opens from the list, in two clicks from the menu (h1 "' + title.trim() + '")', ok && title.trim() !== '' && page.url().includes( picked ), page.url() );
		expect( 'the design: no error in the console', errors.length === 0, errors.join( ' | ' ) );
		const nChips = await page.evaluate( () => document.querySelectorAll( '.dxai-page--design > .dxai-chips .dxai-chip' ).length );
		expect( 'the design says where it is (six chips)', nChips === 6, nChips + ' chips' );
		const tabs = await page.evaluate( () => Array.from( document.querySelectorAll( '.dxai-tabs__tab' ) ).map( ( a ) => a.getAttribute( 'href' ).split( '/' ).pop().replace( /^\d+$/, 'overview' ) ) );
		expect( 'eight tabs: overview, pages, look, blocks, words, speed, transfer, history', tabs.join( ',' ) === 'overview,pages,look,blocks,words,speed,transfer,history', tabs.join( ',' ) );
		const crumbs = await page.evaluate( () => document.querySelectorAll( '.dxai-page__crumbs a' ).length );
		expect( 'a breadcrumb back to Designs', crumbs === 1 );
		// A tab has opened when it is the current one, nothing in it is still loading, and it has something to read.
		const tabOpened = async ( tab, ms = 45000 ) => {
			const until = Date.now() + ms;
			while ( Date.now() < until ) {
				const state = await page.evaluate( ( name ) => {
					const current = ( document.querySelector( '.dxai-tabs__tab.is-current' ) || {} ).textContent || '';
					const panel = document.querySelector( '.dxai-tab' );
					const app = document.getElementById( 'dxai-ui-app' );
					return {
						current: current.toLowerCase().startsWith( name ),
						loading: !! ( app && app.querySelector( '.components-spinner' ) ),
						filled: !! ( panel && panel.children.length > 0 && panel.textContent.trim() !== '' ),
					};
				}, tab );
				if ( state.current && ! state.loading && state.filled ) {
					await sleep( 300 );
					return 'opened';
				}
				await sleep( 250 );
			}
			return 'did not open in ' + ( ms / 1000 ) + ' s';
		};
		for ( const tab of tabs ) {
			errors = [];
			await page.click( '.dxai-tabs__tab[href$="' + ( tab === 'overview' ? picked : picked + '/' + tab ) + '"]' );
			const opened = await tabOpened( tab );
			const h = await height();
			expect( 'tab ' + tab + ' opens in one click (' + h + ' px)', opened === 'opened', opened );
			expect( 'tab ' + tab + ': no error in the console', errors.length === 0, errors.join( ' | ' ) );
			expect( 'tab ' + tab + ': at most 4000 px tall', h <= 4000, h + ' px' );
			await shot( 'design-' + tab );
		}
		// The browser's back button is the way back through the tabs.
		await page.goBack();
		await settled();
		const back = await page.evaluate( () => ( document.querySelector( '.dxai-tabs__tab.is-current' ) || {} ).textContent || '' );
		expect( 'the back button goes to the tab before', back.toLowerCase().startsWith( tabs[ tabs.length - 2 ] ), back );
		// A reload lands on the same tab.
		await page.reload( { waitUntil: 'networkidle2' } );
		await settled();
		const kept = await page.evaluate( () => ( document.querySelector( '.dxai-tabs__tab.is-current' ) || {} ).textContent || '' );
		expect( 'a reload keeps the tab', kept === back, kept );
		await page.click( '.dxai-page__crumbs a' );
		await settled();
		expect( 'the breadcrumb goes back to the list', /page=dxai-ui#\/?$/.test( page.url() ) && await page.evaluate( () => !! document.querySelector( '.dxai-designs' ) ), page.url() );
	}

	await browser.close();
	try {
		fs.rmSync( profile, { recursive: true, force: true } );
	} catch ( e ) {
		// a profile Chrome still holds is swept by the OS
	}
	console.log( '\n' + pass + ' passed, ' + fail + ' failed' );
	process.exit( fail > 0 ? 1 : 0 );
} )().catch( ( e ) => {
	console.error( 'error: ' + ( e && e.message ? e.message : e ) );
	process.exit( 1 );
} );

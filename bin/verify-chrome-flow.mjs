// What the import wizard offers for the header and footer on a DX theme (importFlow.js: chromeFacts(), chromeWarnings()).
//
//   node bin/verify-chrome-flow.mjs
//
// A DX theme installs the design's header and footer by itself when the site has nothing of its own in Menus and Widgets (Chrome_Choice: reason
// `dx`); when it has, they stay (`dx_busy`). The screen offers the answer the server names, does not warn that installing replaces the site's
// navigation when it replaces nothing, and offers a one-page import "keep" until the answer for that scope arrives, a DX theme included.
// Plain data in, plain data out: no browser, no WordPress. Exit code 1 when a check fails.
import { chromeFacts, chromeWarnings } from '../assets/src/admin/importFlow.js';

let fail = 0;
let pass = 0;
const ok = ( what, cond, detail = '' ) => {
	if ( cond ) {
		pass++;
		console.log( '  ok    ' + what );
	} else {
		fail++;
		console.log( '  FAIL  ' + what + ( detail ? '  — ' + detail : '' ) );
	}
};
const info = ( reason, mode, extra = {} ) => ( {
	chrome_default: mode,
	chrome_default_reason: reason,
	theme_chrome: true,
	theme_name: 'American Restoration',
	chrome_owner: { header: [], footer: [] },
	...extra,
} );

// A DX theme with nothing of the site's own: install is offered first, and installing is not warned about.
let f = chromeFacts( info( 'dx', 'install' ), { scope: 'site', scoped: true } );
ok( 'dx: install is the default', f.defaultChoice === 'install' && f.defaultReason === 'dx', JSON.stringify( [ f.defaultChoice, f.defaultReason ] ) );
ok( 'dx: no warning that installing replaces the site\'s navigation', chromeWarnings( f ).filter( ( w ) => w.key === 'theme' ).length === 0 );

// A DX theme whose site has its own menus or widgets: keep is the default and the warning stays for the install option.
f = chromeFacts( info( 'dx_busy', 'keep' ), { scope: 'site', scoped: true } );
ok( 'dx_busy: keep is the default', f.defaultChoice === 'keep' && f.defaultReason === 'dx_busy' );
ok( 'dx_busy: installing is still warned about', chromeWarnings( f ).some( ( w ) => w.key === 'theme' ) );

// Another classic theme: as before.
f = chromeFacts( info( 'theme', 'keep' ), { scope: 'site', scoped: true } );
ok( 'theme: keep, and the warning', f.defaultChoice === 'keep' && chromeWarnings( f ).some( ( w ) => w.key === 'theme' ) );

// This design's chrome is the site's already: install in place.
f = chromeFacts( info( 'own', 'install' ), { scope: 'site', scoped: true } );
ok( 'own: install, marked as the design\'s own', f.defaultChoice === 'install' && f.own === true );

// A one-page import before the answer for its scope arrives: the whole-site answer is install (dx), and the screen offers keep, as the server will.
f = chromeFacts( info( 'dx', 'install' ), { scope: 'page', scoped: false } );
ok( 'a one-page import is offered keep until the scoped answer arrives, also on a DX theme', f.defaultChoice === 'keep' && f.defaultReason === 'one_page', JSON.stringify( [ f.defaultChoice, f.defaultReason ] ) );
f = chromeFacts( info( 'theme', 'keep' ), { scope: 'page', scoped: false } );
ok( '…and another classic theme keeps its own reason', f.defaultChoice === 'keep' && f.defaultReason === 'theme' );
f = chromeFacts( info( 'one_page', 'keep' ), { scope: 'page', scoped: true } );
ok( 'the scoped answer is taken as it is', f.defaultChoice === 'keep' && f.defaultReason === 'one_page' );

// DX Base, the theme the plugin carries: a theme that is not a DX theme is told the plugin has one (import-info `dx_theme`, `base_theme`).
f = chromeFacts( info( 'theme', 'keep', { theme_name: 'Twenty Twenty-Five', dx_theme: false, base_theme: 'available' } ), { scope: 'site', scoped: true } );
ok( 'another theme: DX Base is offered, not installed yet', f.dxTheme === false && f.baseTheme === 'available', JSON.stringify( [ f.dxTheme, f.baseTheme ] ) );
f = chromeFacts( info( 'theme', 'keep', { dx_theme: false, base_theme: 'installed' } ), { scope: 'site', scoped: true } );
ok( '…and once it is installed the screen says to switch to it', f.dxTheme === false && f.baseTheme === 'installed' );
f = chromeFacts( info( 'dx', 'install', { dx_theme: true, base_theme: 'available' } ), { scope: 'site', scoped: true } );
ok( 'a DX theme is told nothing: the screen shows no offer where dxTheme is true', f.dxTheme === true );
f = chromeFacts( info( 'theme', 'keep', { dx_theme: false, base_theme: 'something else' } ), { scope: 'site', scoped: true } );
ok( 'an answer the screen does not know is no offer', f.baseTheme === '' );
f = chromeFacts( info( 'theme', 'keep' ), { scope: 'site', scoped: true } );
ok( 'a server that says nothing of it offers nothing', f.dxTheme === false && f.baseTheme === '' );

console.log( `\n${ pass } passed, ${ fail } failed` );
process.exit( fail ? 1 : 0 );

#!/usr/bin/env node
/**
 * The small drawings the team's layouts use (a tick, a phone, a shield, a drop), as files the plugin ships in
 * data/block-library/icons/, taken from the icon runtime it already carries (assets/vendor/lucide.min.js, ISC licence).
 *
 *   node bin/build-icons.cjs
 *
 * A layout names a drawing (`dxaiIcon`: check, phone, …) and Block_Library::icon() makes a media file of it on the site the
 * page is made on. This is a developer tool: the files it writes are shipped, the runtime is read, nothing is downloaded.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '..' );
const src = fs.readFileSync( path.join( ROOT, 'assets/vendor/lucide.min.js' ), 'utf8' );
const out = path.join( ROOT, 'data/block-library/icons' );

// kebab name the layouts use => the icon's name in the runtime.
const WANT = {
	'check': 'Check', 'phone': 'Phone', 'clock': 'Clock', 'shield': 'Shield', 'award': 'Award', 'star': 'Star', 'droplets': 'Droplets', 'flame': 'Flame',
	'leaf': 'Leaf', 'wind': 'Wind', 'house': 'House', 'map-pin': 'MapPin', 'truck': 'Truck', 'wrench': 'Wrench', 'hammer': 'Hammer', 'users': 'Users',
	'search': 'Search', 'file-check': 'FileCheck', 'dollar-sign': 'DollarSign', 'calendar': 'Calendar', 'heart-handshake': 'HeartHandshake',
};

let made = 0;
for ( const [ file, name ] of Object.entries( WANT ) ) {
	const ref = src.match( new RegExp( '[,{]' + name + ':([A-Za-z0-9_$]+)[,}]' ) );
	if ( ! ref ) {
		console.log( 'not in the runtime: ' + name );
		continue;
	}
	const v = ref[ 1 ].replace( /\$/g, '\\$' );
	const def = src.match( new RegExp( '[,;]' + v + '=(\\[\\[[\\s\\S]*?\\]\\])[,;]' ) );
	if ( ! def ) {
		console.log( 'no drawing for: ' + name );
		continue;
	}
	let nodes;
	try {
		nodes = new Function( 'return ' + def[ 1 ] )(); // the plugin's own file, read as data
	} catch ( e ) {
		console.log( 'unreadable drawing: ' + name );
		continue;
	}
	const inner = nodes.map( ( [ tag, attrs ] ) => '<' + tag + Object.entries( attrs ).map( ( [ k, val ] ) => ' ' + k + '="' + String( val ).replace( /"/g, '&quot;' ) + '"' ).join( '' ) + '/>' ).join( '' );
	const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + inner + '</svg>\n';
	fs.writeFileSync( path.join( out, file + '.svg' ), svg );
	made++;
}
console.log( made + ' icons written to data/block-library/icons' );

#!/usr/bin/env node
/**
 * Syntax of every file that runs: `php -l` on each PHP file of the plugin (src, bin, templates, themes, the root), `node --check`
 * on each plain script (assets/js, bin/*.cjs, bin/*.mjs). The admin's JSX sources are checked by `npm run build`, not here.
 *
 *   node bin/lint.cjs [--php <php binary and its flags>]
 *
 * The PHP binary is `php` unless --php or DXAI_PHP says otherwise. Exit code 1 when a file does not parse.
 *
 * @package DXAI_UI
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );

const ROOT = path.resolve( __dirname, '..' );
const args = process.argv.slice( 2 );
const at = args.indexOf( '--php' );
const php = at >= 0 && at + 1 < args.length ? args[ at + 1 ] : process.env.DXAI_PHP || 'php';
const SKIP = new Set( [ 'node_modules', 'vendor', '.git', '.verify', '.claude', 'dist', 'build', '.phpstan-cache', 'fixtures' ] );

const walk = ( dir, out = [] ) => {
	for ( const e of fs.readdirSync( dir, { withFileTypes: true } ) ) {
		if ( SKIP.has( e.name ) || e.name.startsWith( '.tmp-' ) ) {
			continue;
		}
		const p = path.join( dir, e.name );
		if ( e.isDirectory() ) {
			walk( p, out );
		} else {
			out.push( p );
		}
	}
	return out;
};
const rel = ( p ) => path.relative( ROOT, p ).split( path.sep ).join( '/' );
const all = walk( ROOT );
const phpFiles = all.filter( ( p ) => p.endsWith( '.php' ) );
const jsFiles = all.filter( ( p ) => /^(assets\/js\/.*\.js|bin\/[^/]+\.(cjs|mjs))$/.test( rel( p ) ) );

let bad = 0;
for ( const f of phpFiles ) {
	const r = spawnSync( php + ' -l "' + f + '"', { shell: true, encoding: 'utf8' } );
	if ( r.status !== 0 || ! /No syntax errors/.test( r.stdout ) ) {
		++bad;
		console.log( `PHP  ${ rel( f ) }\n     ${ ( r.stdout + r.stderr ).trim().split( /\r?\n/ ).join( '\n     ' ) }` );
	}
}
for ( const f of jsFiles ) {
	const r = spawnSync( process.execPath, [ '--check', f ], { encoding: 'utf8' } );
	if ( r.status !== 0 ) {
		++bad;
		console.log( `JS   ${ rel( f ) }\n     ${ r.stderr.trim().split( /\r?\n/ ).slice( 0, 4 ).join( '\n     ' ) }` );
	}
}
console.log( `${ phpFiles.length } PHP files, ${ jsFiles.length } scripts: ${ bad === 0 ? 'all parse' : bad + ' do not parse' }` );
process.exit( bad > 0 ? 1 : 0 );

#!/usr/bin/env node
/**
 * docs/HOOKS.md from the code: every filter and action the plugin fires (apply_filters / do_action with a `dxai_ui_` name), with
 * the comment written above the call, the values it passes and where it is called.
 *
 *   node bin/hooks-doc.cjs            write docs/HOOKS.md
 *   node bin/hooks-doc.cjs --check    exit 1 when docs/HOOKS.md is not what the code says (CI runs this)
 *
 * A hook is documented by the comment right above the line that fires it; one without a comment is listed as such.
 *
 * @package DXAI_UI
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '..' );
const OUT = path.join( ROOT, 'docs', 'HOOKS.md' );
const SOURCES = [ 'src', 'templates', 'themes', 'dxai-ui.php', 'uninstall.php' ];

const files = [];
const walk = ( p ) => {
	if ( ! fs.existsSync( p ) ) {
		return;
	}
	if ( fs.statSync( p ).isDirectory() ) {
		for ( const e of fs.readdirSync( p ).sort() ) {
			walk( path.join( p, e ) );
		}
	} else if ( p.endsWith( '.php' ) ) {
		files.push( p );
	}
};
SOURCES.forEach( ( s ) => walk( path.join( ROOT, s ) ) );

/** The text of a call's arguments: from the opening parenthesis to its match, strings and nesting respected. */
const callArgs = ( src, open ) => {
	let depth = 0;
	let quote = '';
	for ( let i = open; i < src.length; i++ ) {
		const c = src[ i ];
		if ( quote ) {
			if ( c === '\\' ) {
				i++;
			} else if ( c === quote ) {
				quote = '';
			}
			continue;
		}
		if ( c === "'" || c === '"' ) {
			quote = c;
		} else if ( c === '(' ) {
			depth++;
		} else if ( c === ')' && --depth === 0 ) {
			return src.slice( open + 1, i );
		}
	}
	return '';
};

/** Split the arguments at the top-level commas. */
const splitArgs = ( text ) => {
	const out = [];
	let depth = 0;
	let quote = '';
	let cur = '';
	for ( let i = 0; i < text.length; i++ ) {
		const c = text[ i ];
		if ( quote ) {
			cur += c;
			if ( c === '\\' ) {
				cur += text[ ++i ] || '';
			} else if ( c === quote ) {
				quote = '';
			}
			continue;
		}
		if ( c === "'" || c === '"' ) {
			quote = c;
		} else if ( '([{'.includes( c ) ) {
			depth++;
		} else if ( ')]}'.includes( c ) ) {
			depth--;
		}
		if ( c === ',' && depth === 0 ) {
			out.push( cur.trim() );
			cur = '';
			continue;
		}
		cur += c;
	}
	if ( cur.trim() !== '' ) {
		out.push( cur.trim() );
	}
	return out.map( ( a ) => a.replace( /\s+/g, ' ' ) );
};

/** The comment lines right above a line: // runs, or a block comment that ends there. */
const commentAbove = ( lines, at ) => {
	const got = [];
	let i = at - 1;
	if ( i >= 0 && /\*\/\s*$/.test( lines[ i ] ) ) {
		for ( ; i >= 0; i-- ) {
			got.unshift( lines[ i ] );
			if ( /^\s*\/\*/.test( lines[ i ] ) ) {
				break;
			}
		}
	} else {
		for ( ; i >= 0 && /^\s*\/\//.test( lines[ i ] ); i-- ) {
			got.unshift( lines[ i ] );
		}
	}
	return got
		.map( ( l ) => l.replace( /^\s*(\/\*\*?|\*\/|\*|\/\/)\s?/, '' ).replace( /\s*\*\/\s*$/, '' ).trimEnd() )
		.filter( ( l ) => l.trim() !== '' && ! /^@package\b/.test( l.trim() ) )
		.join( '\n' )
		.trim();
};

const hooks = new Map();
for ( const file of files ) {
	const src = fs.readFileSync( file, 'utf8' ).replace( /\r\n/g, '\n' );
	const lines = src.split( '\n' );
	const re = /\b(apply_filters|do_action)(_ref_array)?\s*\(\s*'(dxai_ui_[a-z0-9_]+)'/g;
	let m;
	while ( ( m = re.exec( src ) ) !== null ) {
		const kind = m[ 1 ] === 'do_action' ? 'action' : 'filter';
		const name = m[ 3 ];
		const open = src.indexOf( '(', m.index );
		const args = splitArgs( callArgs( src, open ) ).slice( 1 );
		const line = src.slice( 0, m.index ).split( '\n' ).length;
		// The comment belongs to the statement: the first line of it, which may begin before the call ($x = (bool) apply_filters(…)).
		let stmt = line - 1;
		while ( stmt > 0 && /[,(\[=.?:]\s*$|^\s*(\?|:|\.)/.test( lines[ stmt - 1 ] ) && ! /;\s*$/.test( lines[ stmt - 1 ] ) && ! /^\s*(\/\/|\*|\/\*)/.test( lines[ stmt - 1 ] ) ) {
			stmt--;
		}
		const where = path.relative( ROOT, file ).split( path.sep ).join( '/' ) + ':' + line;
		const h = hooks.get( name ) || { name, kind, calls: [] };
		let doc = commentAbove( lines, stmt );
		// A function that is little more than its hook says what the hook is for in its docblock: the call opens the function.
		let prev = stmt - 1;
		while ( prev >= 0 && lines[ prev ].trim() === '' ) {
			prev--;
		}
		if ( doc === '' && prev >= 0 && /\bfunction\s+[A-Za-z0-9_]+\s*\(.*\)\s*(:\s*[^{]+)?\{\s*$/.test( lines[ prev ] ) ) {
			doc = commentAbove( lines, prev ).split( '\n' ).filter( ( l ) => ! /^@(return|param|since)\b/.test( l.trim() ) ).join( '\n' ).trim();
		}
		// Else the nearest comment above the call (within 80 lines) that names the hook: a function's docblock often says what it is for.
		for ( let i = stmt - 1; doc === '' && i >= Math.max( 0, stmt - 80 ); i-- ) {
			if ( ! /\*\/\s*$|^\s*\/\//.test( lines[ i ] ) ) {
				continue;
			}
			const block = commentAbove( lines, i + 1 );
			if ( block.includes( name ) ) {
				doc = block.split( '\n' ).filter( ( l ) => ! /^@(return|param|since|var)\b/.test( l.trim() ) ).join( '\n' ).trim();
			}
			// Skip to the top of this block before looking further up.
			while ( i > 0 && /^\s*(\*|\/\/|\/\*)/.test( lines[ i - 1 ] ) ) {
				i--;
			}
		}
		h.calls.push( { where, args, doc } );
		hooks.set( name, h );
	}
}

const all = [ ...hooks.values() ].sort( ( a, b ) => a.name.localeCompare( b.name ) );
const filters = all.filter( ( h ) => h.kind === 'filter' );
const actions = all.filter( ( h ) => h.kind === 'action' );
const md = [];
md.push( '# Hooks' );
md.push( '' );
md.push( `The filters and actions DX fires, from the code: ${ filters.length } filters, ${ actions.length } action${ actions.length === 1 ? '' : 's' }. Each says what the comment above its call says, the values it passes (the first is the value a filter returns) and where it is called.` );
md.push( '' );
md.push( 'This file is written by `node bin/hooks-doc.cjs`; CI fails when it is not what the code says (`--check`). To document a hook, write the comment above the line that fires it.' );
md.push( '' );
const toc = ( list ) => list.map( ( h ) => `[\`${ h.name }\`](#${ h.name.replace( /_/g, '_' ) })` ).join( ' · ' );
md.push( `**Filters:** ${ toc( filters ) }` );
md.push( '' );
if ( actions.length ) {
	md.push( `**Actions:** ${ toc( actions ) }` );
	md.push( '' );
}
for ( const [ title, list ] of [ [ 'Filters', filters ], [ 'Actions', actions ] ] ) {
	if ( ! list.length ) {
		continue;
	}
	md.push( `## ${ title }` );
	md.push( '' );
	for ( const h of list ) {
		md.push( `### ${ h.name }` );
		md.push( '' );
		const docs = [ ...new Set( h.calls.map( ( c ) => c.doc ).filter( Boolean ) ) ];
		md.push( docs.length ? docs.join( '\n\n' ) : '_No comment above the call yet._' );
		md.push( '' );
		for ( const c of h.calls ) {
			const args = c.args.length ? c.args.map( ( a ) => '`' + a.replace( /`/g, "'" ) + '`' ).join( ', ' ) : 'none';
			md.push( `- \`${ c.where }\` — ${ h.kind === 'filter' ? 'value and arguments' : 'arguments' }: ${ args }` );
		}
		md.push( '' );
	}
}
const text = md.join( '\n' ).replace( /\n{3,}/g, '\n\n' ).trimEnd() + '\n';

if ( process.argv.includes( '--check' ) ) {
	const now = fs.existsSync( OUT ) ? fs.readFileSync( OUT, 'utf8' ).replace( /\r\n/g, '\n' ) : '';
	if ( now !== text ) {
		console.log( 'docs/HOOKS.md is not what the code says: run `node bin/hooks-doc.cjs` and commit it.' );
		process.exit( 1 );
	}
	console.log( `docs/HOOKS.md is up to date (${ filters.length } filters, ${ actions.length } actions).` );
	process.exit( 0 );
}
fs.writeFileSync( OUT, text );
console.log( `wrote docs/HOOKS.md: ${ filters.length } filters, ${ actions.length } actions, ${ all.filter( ( h ) => ! h.calls.some( ( c ) => c.doc ) ).length } without a comment` );

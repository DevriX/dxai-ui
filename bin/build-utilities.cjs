#!/usr/bin/env node
/**
 * Build data/dx-utilities.php: the DevriX theme utility classes, as data the
 * plugin ships.
 *
 * The american-restoration theme defines ~9,300 utility rules in
 * assets/src/sass/base/_utilities.scss (single-class, mostly `!important`,
 * Tailwind-like names with a hyphen breakpoint prefix: `md-p-4`). Converted
 * blocks use those class names wherever a block's own CSS is exactly what a
 * utility declares (src/Compiler/Utility_Classes.php). The theme is not
 * installed on the sites the plugin runs on, so the plugin carries the rules
 * itself and writes, per page, only the rules of the classes that page uses —
 * the whole compiled set is 928 KB and is never loaded into a page.
 *
 * Input is the theme's compiled utilities, exactly as production ships them:
 * Dart Sass, compressed, `@import "breakpoints"; @import "utilities";`. That
 * compile is byte-identical to the utilities region of the theme's
 * assets/dist/css/master.*.min.css (bytes 9011-937064 of
 * master.1790256385959.min.css, 928,053 bytes, checked 2026-09-24), so the
 * rule text, the media wrappers and above all the ORDER of the rules — which
 * decides which of two classes wins, not the order of the classes on the
 * element — are the theme's own.
 *
 * Regenerate:
 *
 *   # from a checkout of the theme (compiles with this repo's node_modules/sass)
 *   node bin/build-utilities.cjs --theme <path>/wp-content/themes/american-restoration
 *
 *   # or from an already compiled file (the compressed Dart Sass output above)
 *   node bin/build-utilities.cjs --css <utilities.min.css>
 *
 *   # optional cross-checks against the catalogue the research pass built
 *   # (ar_utilities.json: 14,131 entries, ar_utilities_index.json)
 *   node bin/build-utilities.cjs --css <f> --catalogue ar_utilities.json --index ar_utilities_index.json
 *
 * Output: data/dx-utilities.php (CRLF), a PHP array — opcache keeps it as an
 * immutable array, so including it costs nothing once cached:
 *
 *   version    sha1 of the compiled CSS (first 12 hex) — part of every cache key
 *   media      media query preludes, index 0 = no media
 *   decls      declaration blocks, interned (the sm/md/lg/xl copies share one)
 *   rules      in cascade order: [ media, selector, decls, form ]
 *                form 0: selector is a space-separated class list, each
 *                written `.class`; form 1: the same, each also with its
 *                core/button twin `.wp-block-button.class>a` listed after
 *                them (the theme's usual pair); form 2: the raw selector list
 *   classes    class => rule indexes (ascending), every rule naming the class
 *   matchable  classes a block's own CSS may be replaced with, best first
 *              (see eligible() for what qualifies)
 *
 * Dropped: the bracket spellings (`max-w-[300px]`, 4,820 selectors) —
 * sanitize_html_class() strips `[` and `]`, so no block can carry them; each has
 * a `max-w-300px` twin in the same rule.
 *
 * Not here, on purpose: what Utility_Classes does with these rules when it
 * writes them (the class each selector is filed under as `:where(.x)`, the
 * scope, rem as px) and the plugin's own classes for values the theme has no
 * class for (`fw-900`, `text-14-5`, `max-w-1280px`, `text-dxai-ink` —
 * Utility_Classes::family()). Those are code, versioned by its LAYER_VERSION;
 * this file is only the theme's own CSS. A plugin class yields to any theme
 * class of the same name, so a theme release that adds, say, `fw-900` takes
 * the name over as soon as this file is regenerated, with no other change.
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const crypto = require( 'crypto' );

const repo = path.resolve( __dirname, '..' );
const OUT = path.join( repo, 'data', 'dx-utilities.php' );

function args( argv ) {
	const out = {};
	for ( let i = 2; i < argv.length; i++ ) {
		const a = argv[ i ];
		if ( a.startsWith( '--' ) ) {
			const next = argv[ i + 1 ];
			if ( next === undefined || next.startsWith( '--' ) ) {
				out[ a.slice( 2 ) ] = true;
			} else {
				out[ a.slice( 2 ) ] = next;
				i++;
			}
		}
	}
	return out;
}

/** The theme's utilities, compiled the way production compiles them. */
function compile( theme ) {
	const sass = require( path.join( repo, 'node_modules', 'sass' ) );
	const base = path.join( theme, 'assets', 'src', 'sass', 'base' );
	const result = sass.compileString( '@import "breakpoints";\n@import "utilities";\n', {
		loadPaths: [ base ],
		syntax: 'scss',
		style: 'compressed',
		silenceDeprecations: [ 'import', 'slash-div', 'global-builtin', 'mixed-decls', 'legacy-js-api' ],
		logger: { warn: () => {}, debug: () => {} },
	} );
	return result.css;
}

/**
 * Compressed CSS into rules, in order. The input holds only style rules and
 * one level of @media (asserted), no comments, no strings with braces.
 */
function parse( css ) {
	const rules = [];
	let i = 0;
	const closeOf = ( open ) => {
		let depth = 0;
		for ( let k = open; k < css.length; k++ ) {
			if ( css[ k ] === '{' ) {
				depth++;
			} else if ( css[ k ] === '}' ) {
				depth--;
				if ( depth === 0 ) {
					return k;
				}
			}
		}
		throw new Error( 'unbalanced braces at ' + open );
	};
	const body = ( text, media ) => {
		let j = 0;
		while ( j < text.length ) {
			const open = text.indexOf( '{', j );
			const close = text.indexOf( '}', open );
			if ( open < 0 || close < 0 ) {
				throw new Error( 'bad rule near ' + text.slice( j, j + 80 ) );
			}
			const selector = text.slice( j, open );
			if ( selector.startsWith( '@' ) ) {
				throw new Error( 'nested at-rule: ' + selector );
			}
			rules.push( { media, selector, decls: text.slice( open + 1, close ) } );
			j = close + 1;
		}
	};
	while ( i < css.length ) {
		const open = css.indexOf( '{', i );
		if ( css[ i ] === '@' ) {
			const head = css.slice( i, open );
			if ( ! head.startsWith( '@media' ) ) {
				throw new Error( 'unexpected at-rule: ' + head );
			}
			const close = closeOf( open );
			body( css.slice( open + 1, close ), head.slice( '@media'.length ) );
			i = close + 1;
		} else {
			const close = css.indexOf( '}', open );
			rules.push( { media: '', selector: css.slice( i, open ), decls: css.slice( open + 1, close ) } );
			i = close + 1;
		}
	}
	return rules;
}

/** The rules back into CSS, grouping consecutive rules that share a media. */
function serialize( rules ) {
	let out = '';
	let open = null;
	for ( const r of rules ) {
		if ( r.media !== open ) {
			if ( open ) {
				out += '}';
			}
			if ( r.media ) {
				out += '@media' + r.media + '{';
			}
			open = r.media;
		}
		out += r.selector + '{' + r.decls + '}';
	}
	return out + ( open ? '}' : '' );
}

/** A selector list split on its top-level commas. */
function selectors( list ) {
	const out = [];
	let depth = 0;
	let start = 0;
	for ( let i = 0; i < list.length; i++ ) {
		const ch = list[ i ];
		if ( ch === '\\' ) {
			i++;
		} else if ( ch === '(' || ch === '[' ) {
			depth++;
		} else if ( ch === ')' || ch === ']' ) {
			depth--;
		} else if ( ch === ',' && depth === 0 ) {
			out.push( list.slice( start, i ) );
			start = i + 1;
		}
	}
	out.push( list.slice( start ) );
	return out;
}

const CLASS = /\.((?:\\.|[A-Za-z0-9_-])+)/g;
const unescape = ( s ) => s.replace( /\\(.)/g, '$1' );
const classesIn = ( sel ) => [ ...sel.matchAll( CLASS ) ].map( ( m ) => unescape( m[ 1 ] ) );
const SELF = /^\.([A-Za-z0-9_-]+)$/;
const TWIN = /^\.wp-block-button\.([A-Za-z0-9_-]+)>a$/;

/** Declarations as [property, value, important], in order. */
function declarations( text ) {
	const out = [];
	let depth = 0;
	let start = 0;
	for ( let i = 0; i <= text.length; i++ ) {
		const ch = i < text.length ? text[ i ] : ';';
		if ( ch === '(' ) {
			depth++;
		} else if ( ch === ')' ) {
			depth--;
		} else if ( ch === ';' && depth === 0 ) {
			const part = text.slice( start, i ).trim();
			start = i + 1;
			if ( part === '' ) {
				continue;
			}
			const colon = part.indexOf( ':' );
			let value = part.slice( colon + 1 ).trim();
			const important = /!\s*important$/i.test( value );
			if ( important ) {
				value = value.replace( /\s*!\s*important$/i, '' );
			}
			out.push( [ part.slice( 0, colon ).trim().toLowerCase(), value, important ] );
		}
	}
	return out;
}

/*
 * Never offered for matching, whatever they declare:
 *  - hidden: Motion_Runtime toggles `hidden` as its own panel state and
 *    Tailwind designs pair it with responsive classes (`hidden md:block`); a
 *    `.hidden{display:none !important}` on the page would pin those shut.
 *    `d-none` declares the same and is offered instead.
 *  - static: the select runtime switches a static list host to
 *    `position:relative` inline (Motion_Runtime rxSelect), which an
 *    `!important` static would defeat.
 */
const NEVER_MATCH = new Set( [ 'hidden', 'static' ] );

/**
 * Whether a class can stand in for a block's own declarations: only where
 * the theme gives it one meaning everywhere, on the element itself.
 *
 *  - every rule naming it is outside any media query: block CSS is one set
 *    of declarations for every width, and a class the theme redefines at a
 *    breakpoint (sm-px-16 is 16px up to 768px and 64px above) is not;
 *  - it is named only as `.class` or in the core/button twin
 *    `.wp-block-button.class>a` (which cannot match a block that is not a
 *    core/button wrapper, and Style_Hoister never matches one): no :hover,
 *    no descendant `img`/`a`/`>*` targets;
 *  - every declaration it ends up with is `!important` — the property that
 *    lets a single class stand where the inline style stood (see
 *    Utility_Classes). `flex-1`, `text-center`, `w-fit`, every `max-h-Npx`
 *    and the other non-important ones would lose to the design's scoped
 *    rules the inline style beat;
 *  - no property is written twice in one rule (a fallback pair such as
 *    `background:#0066c7;background:linear-gradient(…)` is not one value).
 */
function eligible( name, info, rules ) {
	if ( NEVER_MATCH.has( name ) ) {
		return null;
	}
	const merged = new Map();
	let self = 0;
	for ( const idx of info.rules ) {
		const r = rules[ idx ];
		if ( r.media !== '' ) {
			return null;
		}
		let isSelf = false;
		for ( const sel of r.kept ) {
			if ( ! classesIn( sel ).includes( name ) ) {
				continue;
			}
			if ( SELF.test( sel ) && SELF.exec( sel )[ 1 ] === name ) {
				isSelf = true;
			} else if ( ! ( TWIN.test( sel ) && TWIN.exec( sel )[ 1 ] === name ) ) {
				return null;
			}
		}
		if ( ! isSelf ) {
			continue;
		}
		self++;
		const seen = new Set();
		for ( const [ prop, value, important ] of declarations( r.decls ) ) {
			if ( seen.has( prop ) ) {
				return null;
			}
			seen.add( prop );
			const had = merged.get( prop );
			// Cascade within one class: a later rule wins unless the earlier
			// declaration is important and the later one is not.
			if ( ! had || important || ! had.important ) {
				merged.set( prop, { value, important } );
			}
		}
	}
	if ( self === 0 || merged.size === 0 ) {
		return null;
	}
	for ( const d of merged.values() ) {
		if ( ! d.important ) {
			return null;
		}
	}
	return merged;
}

function phpString( s ) {
	return "'" + s.replace( /\\/g, '\\\\' ).replace( /'/g, "\\'" ) + "'";
}

function main() {
	const opt = args( process.argv );
	let css;
	if ( opt.theme ) {
		css = compile( String( opt.theme ) );
	} else if ( opt.css ) {
		css = fs.readFileSync( String( opt.css ), 'utf8' );
	} else {
		console.error( 'usage: node bin/build-utilities.cjs --theme <theme dir> | --css <compiled utilities.min.css> [--catalogue ar_utilities.json] [--index ar_utilities_index.json]' );
		process.exit( 2 );
	}
	css = css.replace( /^﻿/, '' ).trim();
	const version = crypto.createHash( 'sha1' ).update( css ).digest( 'hex' ).slice( 0, 12 );
	// The same text for both routes: the two inputs are the same CSS (the
	// version is its hash), so the file is byte-identical whichever was used.
	const source = 'the compressed Dart Sass compile of american-restoration assets/src/sass/base/_breakpoints.scss + _utilities.scss (sha1 ' + version + ')';

	const rules = parse( css );
	// The parse is lossless, or nothing built from it can be trusted.
	if ( serialize( rules ) !== css ) {
		throw new Error( 'parse does not reproduce the input' );
	}

	// Drop the bracket spellings; index the rest.
	const classes = new Map();
	let aliases = 0;
	rules.forEach( ( r, idx ) => {
		r.kept = selectors( r.selector ).filter( ( sel ) => {
			const alias = /\\\[/.test( sel );
			aliases += alias ? 1 : 0;
			return ! alias;
		} );
		// A rule is filed under the class each selector LEADS with (its
		// first class; for the core/button twin, the class after
		// `.wp-block-button`). A class only met further in —
		// `.h-full.wp-block-heading`, `.x .underline`, a `:not(.…--unused)` —
		// is a qualifier of someone else's utility, not a utility of its own:
		// filing it would make every core heading on a page "use" a utility.
		for ( const sel of r.kept ) {
			const tokens = classesIn( sel );
			// `.wp-block-button.x>a`, `.wp-block-button.x:hover>a`: x's.
			const name = tokens[ 0 ] === 'wp-block-button' ? tokens[ 1 ] : tokens[ 0 ];
			if ( ! name ) {
				continue;
			}
			if ( ! classes.has( name ) ) {
				classes.set( name, { rules: [] } );
			}
			const list = classes.get( name ).rules;
			if ( list[ list.length - 1 ] !== idx ) {
				list.push( idx );
			}
		}
	} );
	const live = rules.filter( ( r ) => r.kept.length > 0 );
	if ( live.length !== rules.length ) {
		throw new Error( 'a rule held only bracket aliases' );
	}

	// Compact selector form: class list (+ core/button twins) or raw.
	for ( const r of rules ) {
		const self = [];
		const twin = [];
		let raw = false;
		for ( const sel of r.kept ) {
			if ( SELF.test( sel ) ) {
				if ( twin.length ) {
					raw = true;
				}
				self.push( SELF.exec( sel )[ 1 ] );
			} else if ( TWIN.test( sel ) ) {
				twin.push( TWIN.exec( sel )[ 1 ] );
			} else {
				raw = true;
			}
		}
		if ( ! raw && self.length > 0 && ( twin.length === 0 || twin.join( ' ' ) === self.join( ' ' ) ) ) {
			r.compact = { selector: self.join( ' ' ), twin: twin.length ? 1 : 0 };
		} else {
			r.compact = { selector: r.kept.join( ',' ), twin: 2 };
		}
		// The compact form has to write back the same selector list.
		const back = r.compact.twin === 2
			? r.compact.selector
			: r.compact.selector.split( ' ' ).map( ( c ) => '.' + c ).concat( r.compact.twin ? r.compact.selector.split( ' ' ).map( ( c ) => '.wp-block-button.' + c + '>a' ) : [] ).join( ',' );
		if ( back !== r.kept.join( ',' ) ) {
			throw new Error( 'compact selector does not round-trip: ' + r.selector );
		}
	}

	// Matchable classes, best first: a `-Npx` spelling ahead of its legacy
	// twin without the unit (max-w-640px before max-w-640), then cascade order.
	const matchable = [];
	for ( const [ name, info ] of classes ) {
		const merged = eligible( name, info, rules );
		if ( merged ) {
			matchable.push( { name, first: info.rules[ 0 ], legacy: classes.has( name + 'px' ) ? 1 : 0 } );
		}
	}
	matchable.sort( ( a, b ) => a.legacy - b.legacy || a.first - b.first );

	// Intern media preludes and declaration blocks.
	const media = [ '' ];
	const mediaIdx = new Map( [ [ '', 0 ] ] );
	const decls = [];
	const declIdx = new Map();
	const rows = rules.map( ( r ) => {
		if ( ! mediaIdx.has( r.media ) ) {
			mediaIdx.set( r.media, media.length );
			media.push( r.media );
		}
		if ( ! declIdx.has( r.decls ) ) {
			declIdx.set( r.decls, decls.length );
			decls.push( r.decls );
		}
		return [ mediaIdx.get( r.media ), r.compact.selector, declIdx.get( r.decls ), r.compact.twin ];
	} );

	// Cross-checks against the research catalogue, when given.
	const report = [];
	if ( opt.catalogue ) {
		const cat = JSON.parse( fs.readFileSync( String( opt.catalogue ), 'utf8' ) ).filter( ( e ) => ! e.extraFile && ! e.alias_of );
		const names = new Set( cat.map( ( e ) => e.class ) );
		const missing = [ ...names ].filter( ( n ) => ! classes.has( n ) );
		const extra = [ ...classes.keys() ].filter( ( n ) => ! names.has( n ) );
		report.push( 'catalogue: ' + names.size + ' classes (no aliases, no other files); missing here ' + missing.length + ( missing.length ? ' ' + missing.slice( 0, 10 ).join( ' ' ) : '' ) + '; not in catalogue ' + extra.length + ( extra.length ? ' ' + extra.slice( 0, 10 ).join( ' ' ) : '' ) );
		// Every catalogue entry's media must be one of the class's rule media here.
		const mediaBad = [];
		for ( const e of cat ) {
			const info = classes.get( e.class );
			if ( ! info ) {
				continue;
			}
			const want = e.media ? e.media.replace( /\s+/g, '' ) : '';
			if ( ! info.rules.some( ( idx ) => rules[ idx ].media.replace( /^\s*/, '' ).replace( /\s+/g, '' ) === want ) ) {
				mediaBad.push( e.class + ' @' + ( e.media || 'base' ) + ' (' + e.target + ')' );
			}
		}
		report.push( 'catalogue entries whose media no rule filed under the class has: ' + mediaBad.length + ( mediaBad.length ? ' — ' + mediaBad.slice( 0, 6 ).join( '; ' ) : '' ) );
		// A matchable class must be all-important, self-targeted, base in the catalogue too.
		const byClass = new Map();
		for ( const e of cat ) {
			if ( ! byClass.has( e.class ) ) {
				byClass.set( e.class, [] );
			}
			byClass.get( e.class ).push( e );
		}
		const disagree = matchable.filter( ( m ) => ( byClass.get( m.name ) || [] ).some( ( e ) => e.media !== null || ( e.target !== 'self' && e.target !== 'button-link only' ) || e.state ) );
		report.push( 'matchable classes the catalogue marks media/non-self/state: ' + disagree.length + ( disagree.length ? ' ' + disagree.slice( 0, 10 ).map( ( m ) => m.name ).join( ' ' ) : '' ) );
	}
	if ( opt.index ) {
		const idx = JSON.parse( fs.readFileSync( String( opt.index ), 'utf8' ) );
		const baseIndex = ( idx.index || {} ).base || {};
		const theirs = new Set( [].concat( ...Object.values( baseIndex ) ) );
		const ours = new Set( matchable.map( ( m ) => m.name ) );
		const unsafe = new Set( idx.unsafeClasses || [] );
		const onlyTheirs = [ ...theirs ].filter( ( n ) => ! ours.has( n ) );
		const onlyOurs = [ ...ours ].filter( ( n ) => ! theirs.has( n ) );
		const notImportant = onlyTheirs.filter( ( n ) => {
			const info = classes.get( n );
			return info && info.rules.some( ( r ) => declarations( rules[ r ].decls ).some( ( d ) => ! d[ 2 ] ) );
		} );
		report.push( 'index base bucket: ' + theirs.size + ' classes, matchable here ' + ours.size + '; in index only ' + onlyTheirs.length + ' (of which with a non-important declaration ' + notImportant.length + ', never-match ' + onlyTheirs.filter( ( n ) => NEVER_MATCH.has( n ) ).length + ': ' + onlyTheirs.filter( ( n ) => ! notImportant.includes( n ) && ! NEVER_MATCH.has( n ) ).slice( 0, 12 ).join( ' ' ) + '); here only ' + onlyOurs.length + ( onlyOurs.length ? ' ' + onlyOurs.slice( 0, 12 ).join( ' ' ) : '' ) + '; unsafe matchable here: ' + [ ...ours ].filter( ( n ) => unsafe.has( n ) ).length );
	}

	// Emit.
	const EOL = '\r\n';
	const lines = [];
	lines.push( '<?php' );
	lines.push( '/**' );
	lines.push( ' * GENERATED by bin/build-utilities.cjs — do not edit; regenerate instead.' );
	lines.push( ' *' );
	lines.push( ' * The DevriX theme utility classes (american-restoration' );
	lines.push( ' * assets/src/sass/base/_utilities.scss), in the theme\'s own cascade order.' );
	lines.push( ' * Read by DXAI_UI\\Compiler\\Utility_Classes. Source: ' + source + '.' );
	lines.push( ' *' );
	lines.push( ' * @package DXAI_UI' );
	lines.push( ' */' );
	lines.push( '' );
	lines.push( '// phpcs:ignoreFile -- generated data.' );
	lines.push( '' );
	lines.push( "defined( 'ABSPATH' ) || exit;" );
	lines.push( '' );
	lines.push( 'return [' );
	lines.push( "'version'=>" + phpString( version ) + ',' );
	lines.push( "'media'=>[" + media.map( phpString ).join( ',' ) + '],' );
	lines.push( "'decls'=>[" );
	for ( let i = 0; i < decls.length; i += 20 ) {
		lines.push( decls.slice( i, i + 20 ).map( phpString ).join( ',' ) + ',' );
	}
	lines.push( '],' );
	lines.push( "'rules'=>[" );
	for ( let i = 0; i < rows.length; i += 10 ) {
		lines.push( rows.slice( i, i + 10 ).map( ( r ) => '[' + r[ 0 ] + ',' + phpString( r[ 1 ] ) + ',' + r[ 2 ] + ',' + r[ 3 ] + ']' ).join( ',' ) + ',' );
	}
	lines.push( '],' );
	lines.push( "'classes'=>[" );
	const entries = [ ...classes.entries() ];
	for ( let i = 0; i < entries.length; i += 10 ) {
		lines.push( entries.slice( i, i + 10 ).map( ( [ n, info ] ) => phpString( n ) + '=>[' + info.rules.join( ',' ) + ']' ).join( ',' ) + ',' );
	}
	lines.push( '],' );
	lines.push( "'matchable'=>[" );
	for ( let i = 0; i < matchable.length; i += 20 ) {
		lines.push( matchable.slice( i, i + 20 ).map( ( m ) => phpString( m.name ) ).join( ',' ) + ',' );
	}
	lines.push( '],' );
	lines.push( '];' );
	fs.mkdirSync( path.dirname( OUT ), { recursive: true } );
	fs.writeFileSync( OUT, lines.join( EOL ) + EOL );

	const size = fs.statSync( OUT ).size;
	console.log( 'rules ' + rules.length + ', selectors dropped (bracket aliases) ' + aliases + ', classes ' + classes.size + ', matchable ' + matchable.length + ', media ' + media.length + ', decls ' + decls.length );
	console.log( 'version ' + version + ', ' + OUT + ' ' + size + ' bytes' );
	for ( const line of report ) {
		console.log( line );
	}
}

main();

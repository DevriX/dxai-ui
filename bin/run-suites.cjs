#!/usr/bin/env node
/**
 * The verification pack: every suite in bin/suites.json, one at a time, read by the protocol every suite keeps.
 *
 *   exit 0  PASS   the suite ran its checks and they held
 *   exit 1  FAIL   a check failed (the suite printed what)
 *   exit 77 SKIP   something it needs is not here (a line "SKIP: <why>" says what); it measured nothing
 *   other   ERROR  it did not finish: a fatal error, a timeout, wp-cli refusing
 *
 * A PASS that ran no checks ("0 passed, 0 failed") is an ERROR: the pack never counts a suite that did not run.
 *
 *   node bin/run-suites.cjs --wp "<command that runs wp-cli on the site>" [--group ci,local] [--only a,b] [--strict]
 *                           [--json report.json] [--user 1] [--no-prepare] [--list]
 *
 *   --wp       how to run wp-cli on the site to test, from this plugin's folder: "npx wp-env run cli --env-cwd=wp-content/plugins/dxai-ui wp"
 *              in CI, "bash /path/to/wrapper.sh" locally. Also read from DXAI_WP.
 *   --group    which groups (bin/suites.json): ci (a clean site: the plugin, DX Base, Twenty Twenty-Five), local (needs what a
 *              working site holds: imported designs, the team's theme), manual, browser and paid (asks a real AI provider; by
 *              hand). Default: ci,local.
 *   --strict   a SKIP fails the run too, and so does a bin/verify-* file the manifest does not list. CI runs the ci group with
 *              it: there every suite must run, and a new suite must say which group it is in.
 *   --list     print the manifest and stop.
 *
 * Runs bin/pack-fixtures.php first (the fixture archives are not in git). Exit code 1 when any suite is FAIL or ERROR
 * (or SKIP under --strict). In GitHub Actions it also writes the table to the job summary and an annotation per failure.
 *
 * @package DXAI_UI
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );

const ROOT = path.resolve( __dirname, '..' );
const SKIP_CODE = 77;

const args = process.argv.slice( 2 );
const opt = ( name, fallback ) => {
	const i = args.indexOf( '--' + name );
	return i >= 0 && i + 1 < args.length ? args[ i + 1 ] : fallback;
};
const flag = ( name ) => args.includes( '--' + name );

const manifest = JSON.parse( fs.readFileSync( path.join( ROOT, 'bin', 'suites.json' ), 'utf8' ) );
const groups = opt( 'group', 'ci,local' ).split( ',' ).map( ( g ) => g.trim() ).filter( Boolean );
const only = ( opt( 'only', '' ) || '' ).split( ',' ).map( ( s ) => s.trim() ).filter( Boolean );
const strict = flag( 'strict' );
const user = opt( 'user', '1' );
const wp = opt( 'wp', process.env.DXAI_WP || '' );

// Every suite file is in the manifest, so each says where it runs; one that is not is reported (and fails a --strict run).
const listed = new Set( manifest.suites.map( ( s ) => s.file ) );
const unlisted = fs.readdirSync( path.join( ROOT, 'bin' ) ).filter( ( f ) => /^verify-.*\.(php|cjs|mjs)$/.test( f ) && f !== 'verify-cleanup.php' && ! listed.has( f ) );

const picked = manifest.suites.filter( ( s ) => ( only.length ? only.includes( s.file ) || only.includes( s.file.replace( /\.php$/, '' ) ) : groups.includes( s.group ) ) );

if ( flag( 'list' ) ) {
	for ( const s of manifest.suites ) {
		console.log( `${ s.group.padEnd( 7 ) } ${ s.file.padEnd( 34 ) } ${ s.needs ? 'needs: ' + s.needs : '' }` );
	}
	process.exit( 0 );
}
if ( wp === '' ) {
	console.error( 'run-suites: say how to run wp-cli on the site: --wp "<command>" or DXAI_WP (see the header of this file)' );
	process.exit( 2 );
}
if ( picked.length === 0 ) {
	console.error( 'run-suites: no suite in ' + ( only.length ? only.join( ',' ) : 'the groups ' + groups.join( ',' ) ) );
	process.exit( 2 );
}

const run = ( command, timeoutSecs ) => {
	const started = Date.now();
	const r = spawnSync( command, { cwd: ROOT, shell: true, encoding: 'utf8', timeout: timeoutSecs * 1000, maxBuffer: 256 * 1024 * 1024 } );
	return {
		code: r.status,
		timedOut: r.error && r.error.code === 'ETIMEDOUT',
		out: ( r.stdout || '' ) + ( r.stderr || '' ),
		secs: ( Date.now() - started ) / 1000,
	};
};

/** What a suite's run means, by the protocol. */
const judge = ( r ) => {
	const lines = r.out.split( /\r?\n/ );
	const counts = [ ...r.out.matchAll( /(\d+) passed, (\d+) failed/g ) ].pop();
	const passed = counts ? Number( counts[ 1 ] ) : null;
	const failed = counts ? Number( counts[ 2 ] ) : null;
	const skipLine = lines.find( ( l ) => /^\s*SKIP:/.test( l ) );
	const failLines = lines.filter( ( l ) => /^\s*FAIL\b/.test( l ) );
	const fatal = lines.find( ( l ) => /PHP Fatal error|Fatal error:|^Error: /.test( l ) );
	if ( r.timedOut ) {
		return { status: 'ERROR', why: 'timed out', passed, failed };
	}
	if ( r.code === 0 ) {
		if ( counts && passed === 0 && failed === 0 ) {
			return { status: 'ERROR', why: 'ran no checks (0 passed, 0 failed)', passed, failed };
		}
		return { status: 'PASS', why: '', passed, failed };
	}
	if ( r.code === SKIP_CODE ) {
		return { status: 'SKIP', why: skipLine ? skipLine.replace( /^\s*SKIP:\s*/, '' ) : '(no SKIP line)', passed, failed };
	}
	if ( r.code === 1 && ! fatal && ( ( failed !== null && failed > 0 ) || failLines.length ) ) {
		return { status: 'FAIL', why: ( failLines[ 0 ] || `${ failed } failed` ).trim(), passed, failed };
	}
	return { status: 'ERROR', why: ( fatal || `exit ${ r.code }: ` + ( lines.filter( ( l ) => l.trim() ).pop() || '' ) ).trim().slice( 0, 300 ), passed, failed };
};

const results = [];
const t0 = Date.now();

if ( ! flag( 'no-prepare' ) ) {
	for ( const p of manifest.prepare || [] ) {
		const r = run( `${ wp } eval-file ${ p }`, 300 );
		if ( r.code !== 0 ) {
			console.error( `run-suites: preparing with ${ p } failed (exit ${ r.code }):\n${ r.out }` );
			process.exit( 2 );
		}
		console.log( r.out.trim() );
	}
}

for ( const s of picked ) {
	const extra = ( s.args || [] ).join( ' ' );
	const command = s.file.endsWith( '.php' )
		? `${ wp } eval-file bin/${ s.file } ${ extra } --user=${ user }`.replace( /\s+/g, ' ' )
		: `node bin/${ s.file } ${ extra }`.trim();
	const r = run( command, s.timeout || manifest.timeout || 600 );
	const j = judge( r );
	const row = { file: s.file, group: s.group, status: j.status, why: j.why, passed: j.passed, failed: j.failed, secs: Math.round( r.secs * 10 ) / 10, code: r.code };
	results.push( row );
	const counts = j.passed !== null ? `${ j.passed }/${ j.failed }` : '';
	console.log( `${ j.status.padEnd( 5 ) } ${ s.file.padEnd( 34 ) } ${ counts.padStart( 8 ) } ${ ( row.secs + 's' ).padStart( 7 ) }  ${ j.why }` );
	if ( j.status === 'FAIL' || j.status === 'ERROR' ) {
		// The suite's own words, so a red run says why without a rerun.
		const tail = r.out.split( /\r?\n/ ).filter( ( l ) => /FAIL|Fatal|Error|Warning/.test( l ) ).slice( 0, 12 );
		for ( const l of tail ) {
			console.log( '        ' + l.trim().slice( 0, 240 ) );
		}
	}
}

for ( const f of unlisted ) {
	console.log( `NEW   ${ f.padEnd( 34 ) } not in bin/suites.json: say which group it is in` );
}
const n = ( st ) => results.filter( ( r ) => r.status === st ).length;
const bad = n( 'FAIL' ) + n( 'ERROR' ) + ( strict ? n( 'SKIP' ) + unlisted.length : 0 );
const mins = Math.round( ( Date.now() - t0 ) / 600 ) / 100;
console.log( `\n${ results.length } suites: PASS ${ n( 'PASS' ) }, FAIL ${ n( 'FAIL' ) }, SKIP ${ n( 'SKIP' ) }${ strict && n( 'SKIP' ) ? ' (not allowed: --strict)' : '' }, ERROR ${ n( 'ERROR' ) } in ${ mins } min` );

const json = opt( 'json', '' );
if ( json ) {
	fs.writeFileSync( json, JSON.stringify( { groups, strict, results }, null, 1 ) );
}
if ( process.env.GITHUB_ACTIONS ) {
	for ( const r of results.filter( ( x ) => x.status === 'FAIL' || x.status === 'ERROR' || ( strict && x.status === 'SKIP' ) ) ) {
		console.log( `::error title=${ r.status } ${ r.file }::${ ( r.why || '' ).replace( /\r?\n/g, ' ' ) }` );
	}
	if ( process.env.GITHUB_STEP_SUMMARY ) {
		const table = [ '| Suite | Group | Result | Checks | Time | Why |', '| --- | --- | --- | --- | --- | --- |' ]
			.concat( results.map( ( r ) => `| ${ r.file } | ${ r.group } | ${ r.status } | ${ r.passed !== null ? r.passed + '/' + r.failed : '' } | ${ r.secs }s | ${ ( r.why || '' ).replace( /\|/g, '\\|' ) } |` ) );
		fs.appendFileSync( process.env.GITHUB_STEP_SUMMARY, `### Suites (${ groups.join( ', ' ) })\n\n${ table.join( '\n' ) }\n\n` );
	}
}
process.exit( bad > 0 ? 1 : 0 );

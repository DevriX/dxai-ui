/**
 * Render the admin's conversion report outside wp-admin, so it can be looked at.
 *
 * The screen it lives on is behind a login, and a hand-written copy of its
 * markup is not the thing that ships — the last redesign was checked that way
 * and the copy drifted from the component within a day. This transpiles and
 * renders the real FidelityReport with the real admin stylesheet, on real
 * conversion numbers, and writes one HTML file.
 *
 * usage: node bin/fidelity-preview.cjs <dumped-result.json> <out.html> [coverage.json]
 *        (the dump is any JSON of { result, harvest, structures })
 *
 * @package DXAI_UI
 */

const fs = require( 'fs' );
const path = require( 'path' );
const babel = require( '@babel/core' );
const { renderToStaticMarkup } = require( 'react-dom/server' );

const repo = path.join( __dirname, '..' );

/**
 * Load one of the admin's ESM/JSX modules as CommonJS.
 *
 * The bundle's own loader is webpack; here Babel does the same two transforms
 * (JSX to createElement, ESM to CJS) and the module is evaluated in place, so
 * what renders below is the file the build ships.
 */
function load( relative, cache = {} ) {
	const file = path.join( repo, relative );
	if ( cache[ file ] ) {
		return cache[ file ].exports;
	}

	const code = babel.transformFileSync( file, {
		configFile: false,
		babelrc: false,
		presets: [
			[ require.resolve( '@babel/preset-react' ), { runtime: 'classic', pragma: 'createElement', pragmaFrag: 'Fragment' } ],
			[ require.resolve( '@babel/preset-env' ), { targets: { node: 'current' }, modules: 'commonjs' } ],
		],
	} ).code;

	const module = { exports: {} };
	cache[ file ] = module;

	const require_ = ( request ) => {
		if ( request === '@wordpress/element' ) {
			return require( '@wordpress/element' );
		}
		if ( request === '@wordpress/i18n' ) {
			return require( '@wordpress/i18n' );
		}
		if ( request.startsWith( '.' ) ) {
			const next = path.relative( repo, path.resolve( path.dirname( file ), request ) ).replace( /\\/g, '/' );
			return load( next.endsWith( '.js' ) ? next : next + '.js', cache );
		}
		return require( request );
	};

	const { createElement, Fragment } = require( '@wordpress/element' );
	// eslint-disable-next-line no-new-func
	new Function( 'require', 'module', 'exports', 'createElement', 'Fragment', code )(
		require_,
		module,
		module.exports,
		createElement,
		Fragment
	);

	return module.exports;
}

const dump = process.argv[ 2 ];
const out = process.argv[ 3 ] || path.join( repo, 'fidelity-preview.html' );
if ( ! dump ) {
	console.error( 'usage: node bin/fidelity-preview.cjs <dumped-result.json> <out.html>' );
	process.exit( 1 );
}

const { createElement } = require( '@wordpress/element' );
const FidelityReport = load( 'assets/src/admin/components/FidelityReport.js' ).default;
const data = JSON.parse( fs.readFileSync( dump, 'utf8' ) );

/*
 * Three states, because each one styles differently and each one is a real
 * outcome: the conversion as it actually came out, one with every gap closed,
 * and one where the theme never travelled — the admin-vs-CLI failure this
 * report exists to make visible.
 */
const perfect = {
	...data.result,
	/*
	 * The markup has to lose its islands too, not just the tally. The report
	 * counts raw-HTML blocks in the serialised markup as well as reading the
	 * compiler's tally and keeps the larger number, so zeroing only the tally
	 * left this state reporting the 30 islands still in the markup — which is
	 * the check working, and a fixture that was not what it claimed.
	 */
	gutenberg_markup: String( data.result.gutenberg_markup || '' ).replace( /<!--\s+wp:dxai-ui\/html(?:\s+\/-->|[\s\S]*?<!--\s+\/wp:dxai-ui\/html\s+-->)/g, '' ),
	islands: {},
	unevaluated: [],
	unresolved_utilities: [],
	design_css_raw: data.result.design_css_raw || 'x'.repeat( 24000 ),
	tailwind_config: 'y'.repeat( 4096 ),
};
const themeless = {
	...data.result,
	design_css_raw: '',
	unresolved_utilities: [ 'bg-primary/40', 'rounded-lg', 'font-display', 'shadow-elegant' ],
};

/*
 * The coverage table, when a report is handed over as the third argument. Two
 * states: as measured, and with two dimensions short — the case the table
 * exists for, which a healthy import never produces on demand.
 */
const coverage = process.argv[ 4 ] ? JSON.parse( fs.readFileSync( process.argv[ 4 ], 'utf8' ) ) : null;
const broken = coverage
	? {
		...coverage,
		score: 82,
		findings: 2,
		rows: coverage.rows.map( ( row ) => {
			if ( row.dimension === 'keyframes' ) {
				return { ...row, page: Math.max( 0, row.source - 2 ), short: true, missing: [ 'fade-up', 'marquee' ] };
			}
			if ( row.dimension === 'custom-properties' ) {
				return { ...row, page: Math.max( 0, row.source - 3 ), short: true, missing: [ '--primary', '--radius-lg', '--font-display' ] };
			}
			return row;
		} ),
	}
	: null;

const cases = [
	[ 'As imported', data.result, false, null ],
	[ 'Every gap closed', perfect, false, null ],
	[ 'Theme did not travel', themeless, false, null ],
	[ 'On the success screen (compact), with coverage', data.result, true, coverage ],
	[ 'Coverage with two dimensions short', data.result, true, broken ],
].filter( ( row ) => row[ 3 ] !== undefined );

const body = cases
	.map( ( [ label, result, compact, coverageRows ] ) =>
		'<h2>' + label + '</h2>' +
		renderToStaticMarkup(
			createElement( FidelityReport, {
				result,
				harvest: data.harvest,
				structures: data.structures,
				compact,
				coverage: coverageRows || undefined,
			} )
		)
	)
	.join( '\n' );

const css = fs.readFileSync( path.join( repo, 'assets/css/admin.css' ), 'utf8' );

fs.writeFileSync(
	out,
	`<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Conversion report</title>
<style>${ css }</style>
<style>
	body { margin: 0; background: #090d12; }
	/* The admin screen's own frame, so the report is measured in its context. */
	.harness { max-width: 980px; margin: 0 auto; padding: 32px 20px 64px; }
	.harness h2 {
		margin: 32px 0 10px;
		font: 500 11px/1 "Inter var", Inter, sans-serif;
		letter-spacing: 0.14em;
		text-transform: uppercase;
		color: #8497a8;
	}
</style>
</head>
<body class="dxai-ui-wrap">
<div id="dxai-ui-app" class="dxai-ui-admin"><div class="harness">${ body }</div></div>
</body>
</html>
`
);

console.log( 'wrote ' + out + ' (' + cases.length + ' states)' );

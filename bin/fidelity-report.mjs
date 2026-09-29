/**
 * The admin's conversion report says what the compiler measured.
 *
 * The wizard's numbers used to be one line about Tailwind coverage, so nothing
 * checked them. Now that the screen reports raw-HTML islands, unevaluated
 * expressions, the theme and an editable share, each of those has to come out
 * of the compile result unchanged — a report that rounds a gap away is worse
 * than no report, because it ends the conversation.
 *
 * Runs on a real conversion record when one is handed to it, and on fixtures
 * for the shapes a single import cannot cover.
 *
 * usage: node bin/fidelity-report.mjs [dumped-result.json]
 *        (produce the dump with the probe in the session scratchpad, or any
 *         JSON of { result, harvest, structures })
 */

import { readFileSync } from 'node:fs';
import { allMarkup, blockCounts, measureFidelity, worstElements } from '../assets/src/admin/fidelity.js';

let ran = 0;
let bad = 0;

function is( got, want, label ) {
	ran++;
	if ( JSON.stringify( got ) !== JSON.stringify( want ) ) {
		bad++;
		console.log( `FAIL ${ label }: want ${ JSON.stringify( want ) } got ${ JSON.stringify( got ) }` );
		return;
	}
	console.log( `ok   ${ label } = ${ JSON.stringify( got ) }` );
}

/* Counting blocks from serialised markup. */
const markup = [
	'<!-- wp:dxai-ui/section {"tag":"section"} -->',
	'<!-- wp:dxai-ui/text {"tag":"h2","content":"Hi"} /-->',
	'<!-- wp:dxai-ui/html -->',
	'<svg viewBox="0 0 4 4"></svg>',
	'<!-- /wp:dxai-ui/html -->',
	'<!-- /wp:dxai-ui/section -->',
].join( '\n' );
is( blockCounts( markup ), { blocks: 3, islands: 1 }, 'block delimiters counted once' );
is( blockCounts( '' ), { blocks: 0, islands: 0 }, 'empty markup' );
// A self-closing island: `<!-- wp:dxai-ui/html /-->` has no closing delimiter.
is( blockCounts( '<!-- wp:dxai-ui/html /-->' ), { blocks: 1, islands: 1 }, 'self-closed island' );
// The core block is `core/html`; ours is `dxai-ui/html`. Neither may be missed,
// and no block whose name merely starts with those may be counted.
is(
	blockCounts( '<!-- wp:html -->x<!-- /wp:html -->\n<!-- wp:dxai-ui/htmlish -->y<!-- /wp:dxai-ui/htmlish -->' ),
	{ blocks: 2, islands: 1 },
	'core/html counts, htmlish does not'
);

/* Several pages of markup count as one conversion. */
is(
	blockCounts( [ '<!-- wp:dxai-ui/section /-->', '<!-- wp:dxai-ui/html -->x<!-- /wp:dxai-ui/html -->' ] ),
	{ blocks: 2, islands: 1 },
	'an array of pages counts as one'
);
is(
	allMarkup( {
		gutenberg_markup: '<!-- wp:a /-->',
		pages: [ { gutenberg_markup: '<!-- wp:b /-->' }, { gutenberg_markup: '' } ],
	} ),
	[ '<!-- wp:a /-->', '<!-- wp:b /-->' ],
	'extra routes join the primary page, empties dropped'
);
// A three-route design: 3 islands against 3 blocks on the primary page would
// report 0% editable if the extra routes' blocks were left out.
is(
	measureFidelity(
		{
			gutenberg_markup: '<!-- wp:dxai-ui/section /--><!-- wp:dxai-ui/text /--><!-- wp:dxai-ui/box /-->',
			pages: [
				{ gutenberg_markup: '<!-- wp:dxai-ui/section /--><!-- wp:dxai-ui/html /-->' },
				{ gutenberg_markup: '<!-- wp:dxai-ui/section /--><!-- wp:dxai-ui/html /-->' },
			],
			islands: { a: 2 },
			design_css_raw: 'x',
		},
		{},
		[]
	).editable,
	71,
	'extra routes are in the editable share'
);

/* A clean conversion is pixel perfect; anything short of it is not. */
const clean = measureFidelity(
	{
		gutenberg_markup: '<!-- wp:dxai-ui/section -->a<!-- /wp:dxai-ui/section -->',
		islands: {},
		unevaluated: [],
		unresolved_utilities: [],
		design_css_raw: 'x'.repeat( 4096 ),
		tailwind_config: 'y'.repeat( 512 ),
	},
	{ summary: { images: 3, videos: 0, fonts: 1, links: 9 } },
	[ { type: 'header' }, { type: 'hero' }, { type: 'footer' } ]
);
is( clean.perfect, true, 'no gaps means perfect' );
is( clean.editable, 100, 'no islands means 100% editable' );
is( clean.templateParts, 2, 'header and footer counted as template parts' );
is( clean.gaps, [], 'clean run has no gaps' );

const gappy = measureFidelity(
	{
		gutenberg_markup: '<!-- wp:dxai-ui/section -->a<!-- /wp:dxai-ui/section -->',
		islands: { span: 3, a: 1 },
		unevaluated: [ { kind: 'unbound-call', source: 'useInView()', count: 4 } ],
		unresolved_utilities: [ 'bg-primary/40' ],
		design_css_raw: '',
	},
	{},
	[]
);
is( gappy.gaps, [ 'islands', 'utilities', 'expressions', 'theme' ], 'every gap named' );
is( gappy.perfect, false, 'a gap is not perfect' );
is( gappy.expressions, 4, 'expressions sum their own counts, not their rows' );
is( gappy.islands, 4, 'the compiler tally wins over the delimiter count' );
// 4 islands against 1 counted block: blocks can never be fewer than islands.
is( gappy.blocks, 4, 'blocks floor at the island count' );
is( worstElements( gappy.byElement ), [ { tag: 'span', count: 3 }, { tag: 'a', count: 1 } ], 'worst element first' );

/* A design that never ran under Tailwind is not missing a theme. */
const staticDesign = measureFidelity(
	{ gutenberg_markup: '<!-- wp:dxai-ui/section /-->', islands: {}, design_css_raw: '', static_html: true },
	{},
	[]
);
is( staticDesign.gaps, [], 'static HTML design wants no Tailwind theme' );

/* The real thing, when a dump is handed over. */
const dump = process.argv[ 2 ];
if ( dump ) {
	const data = JSON.parse( readFileSync( dump, 'utf8' ) );
	const real = measureFidelity( data.result, data.harvest, data.structures );
	const tallied = Object.values( data.result.islands || {} ).reduce( ( a, b ) => a + b, 0 );

	console.log(
		`\nreal: sections=${ real.sections } blocks=${ real.blocks } islands=${ real.islands } ` +
		`editable=${ real.editable }% expressions=${ real.expressions } utilities=${ real.utilities.length } ` +
		`theme=${ real.theme.css }B config=${ real.theme.config }B gaps=[${ real.gaps }]`
	);
	console.log( `real: islands by element ${ JSON.stringify( worstElements( real.byElement ) ) }` );

	is( real.islands, Math.max( tallied, blockCounts( allMarkup( data.result ) ).islands ), 'real islands take the larger count' );
	ran++;
	if ( real.blocks < real.islands || real.editable === null || real.editable > 100 || real.editable < 0 ) {
		bad++;
		console.log( `FAIL real share out of range: blocks=${ real.blocks } islands=${ real.islands } editable=${ real.editable }` );
	} else {
		console.log( `ok   real share in range` );
	}
	// Every element the compiler tallied has to appear in the report's own map.
	ran++;
	const missing = Object.keys( data.result.islands || {} ).filter( ( tag ) => ! ( tag in real.byElement ) );
	if ( missing.length ) {
		bad++;
		console.log( `FAIL elements dropped from the report: ${ missing.join( ', ' ) }` );
	} else {
		console.log( `ok   every tallied element reported` );
	}
} else {
	console.log( '\n(no conversion dump given: fixtures only)' );
}

console.log( `\nfidelity-report: ${ ran - bad }/${ ran } pass` );
process.exit( bad > 0 ? 1 : 0 );

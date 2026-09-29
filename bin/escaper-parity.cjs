/**
 * The third escaper: @wordpress/escape-html, out of the real
 * wp-includes/js/dist/escape-html.js rather than a reimplementation.
 *
 * Reads the JSON written by bin/escaper-parity.php and prints the three
 * side by side, so the doc comments in Html_To_Blocks can name the characters
 * that actually diverge instead of the ones that look like they should.
 *
 * usage: bin/wp-php.sh bin/escaper-parity.php && node bin/escaper-parity.cjs
 */

const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );

/*
 * The editor's own escape-html bundle, in the WordPress install under test.
 * Its location is a fact about the checkout: DXAI_WP_ROOT names the site's
 * public directory, and `.verify/wp-root` is the local convention — the same
 * two bin/wp-boot.php reads, so one setting serves the whole pack. This used
 * to be one machine's absolute path.
 */
const WP = ( () => {
	const roots = [];
	if ( process.env.DXAI_WP_ROOT ) { roots.push( process.env.DXAI_WP_ROOT ); }
	const marker = path.join( __dirname, '..', '.verify', 'wp-root' );
	if ( fs.existsSync( marker ) ) { roots.push( fs.readFileSync( marker, 'utf8' ).trim() ); }
	if ( process.env.APPDATA ) {
		const sites = path.join( process.env.APPDATA, 'Local', 'sites.json' );
		if ( fs.existsSync( sites ) ) {
			try {
				for ( const site of Object.values( JSON.parse( fs.readFileSync( sites, 'utf8' ) ) ) ) {
					if ( site && site.path ) { roots.push( path.join( site.path, 'app', 'public' ) ); }
				}
			} catch ( e ) { /* a malformed site list is not this suite's problem */ }
		}
	}
	for ( const root of roots ) {
		const dist = path.join( String( root ).replace( /\\/g, '/' ), 'wp-includes', 'js', 'dist' );
		if ( fs.existsSync( path.join( dist, 'escape-html.js' ) ) ) { return dist; }
	}
	console.error( 'escaper-parity: could not find wp-includes/js/dist. Set DXAI_WP_ROOT or write .verify/wp-root.' );
	process.exit( 2 );
	return '';
} )();

const sandbox = { window: {}, self: {}, console };
sandbox.window.wp = {};
sandbox.self = sandbox.window;
sandbox.globalThis = sandbox;
vm.createContext( sandbox );
vm.runInContext( fs.readFileSync( path.join( WP, 'escape-html.js' ), 'utf8' ), sandbox );

const api = sandbox.window.wp?.escapeHtml ?? sandbox.wp?.escapeHtml;
if ( ! api ) {
	console.error( 'escape-html did not expose wp.escapeHtml; keys:', Object.keys( sandbox.window.wp ?? {} ) );
	process.exit( 1 );
}

const rows = JSON.parse( fs.readFileSync( path.join( require( 'os' ).tmpdir(), 'dxai-escaper-parity.json' ), 'utf8' ) );

const pad = ( s, n ) => String( s ).padEnd( n );
const q = ( s ) => JSON.stringify( s );

// The two axes are separate rules and the constant in Html_To_Blocks splits
// them, so they are reported separately here too. An own attribute goes
// through esc_attr()/escapeAttribute(); a link's `text` and `suffix` go
// through esc_html()/escapeHTML(), which encode a SMALLER set.
const axes = [
	[ 'attribute', 'attr_value', 'escapeAttribute', ( v ) => api.escapeAttribute( v ) ],
	[ 'text', 'esc_html', 'escapeHTML', ( v ) => api.escapeHTML( v ) ],
];

let diverge = 0;

for ( const [ axis, phpName, jsName, jsFn ] of axes ) {
	console.log( `\n--- ${ axis }: the two save() emitters ---` );
	console.log( pad( 'character', 16 ), pad( 'input', 14 ), pad( phpName, 14 ), pad( jsName, 14 ), 'verdict' );
	for ( const [ label, row ] of Object.entries( rows ) ) {
		// `&amp;` as an INPUT cannot occur: DOMDocument decodes every
		// reference on the way in, so both emitters always start from a plain
		// string. The row is kept only to show neither double-encodes.
		const php = axis === 'attribute' ? row.attr : row.esc_html;
		const js = jsFn( row.value );
		const same = php === js;
		if ( ! same ) {
			++diverge;
		}
		console.log(
			pad( label, 16 ),
			pad( q( row.value ), 14 ),
			pad( q( php ), 14 ),
			pad( q( js ), 14 ),
			same ? 'agree' : 'DIVERGE'
		);
	}
}

// saveHTML() is the third escaper, and it matters on its own axis: it is what
// the identity check at the end of text_block() compares against.
console.log( '\n--- read-back: what DOMDocument::saveHTML() writes ---' );
console.log( pad( 'character', 16 ), pad( 'input', 14 ), pad( 'saveHTML', 18 ), pad( 'esc_attr', 14 ), 'verdict' );
for ( const [ label, row ] of Object.entries( rows ) ) {
	// saveHTML's output carries its own delimiter; strip it to compare values.
	const saved = String( row.saveHTML ).replace( /^['"]|['"]$/g, '' );
	console.log(
		pad( label, 16 ),
		pad( q( row.value ), 14 ),
		pad( q( saved ), 18 ),
		pad( q( row.esc_attr ), 14 ),
		saved === row.esc_attr ? 'agree' : 'DIVERGE'
	);
}

console.log(
	`\n${ diverge } emitter divergence(s) across both axes.`,
	// The attribute axis now compares the emitter we ship,
	// Html_To_Blocks::attr_value(), which is a port of escapeAttribute() — so it
	// agrees on every character, including the apostrophe that esc_attr() wrote
	// as `&#039;` and cost 218 containers their block. The text axis still
	// diverges by design: esc_html() encodes more than escapeHTML() does.
	'Expected: 0 in an attribute and 3 in text (quote, apostrophe, greater-than).'
);

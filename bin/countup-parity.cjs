/**
 * Would the editor's save() write the same bytes as Html_To_Blocks for a
 * dxai-ui/countup?
 *
 * The block has two emitters that must agree exactly — Html_To_Blocks::
 * countup_html() on this side and save() in assets/js/blocks-editor.js on the
 * editor's — or the block opens "unexpected or invalid content" the moment
 * Gutenberg re-runs save() and compares.
 *
 * `srClass` made that a live risk rather than a theoretical one. The
 * screen-reader copy is a second child, so save() now returns an ARRAY of
 * children, and a React array child wants a `key`. `key` is React bookkeeping
 * and must not reach the markup; if @wordpress/element's renderToString
 * emitted it, every countup on every page would be invalid. That is not
 * something to reason about — this runs the real serializer.
 *
 * Loads assets/js/blocks-editor.js for real: it is a hand-written IIFE over
 * `window.wp`, so a stub whose `element` is the actual @wordpress/element is
 * enough to get the registered settings out.
 *
 * usage: node bin/countup-parity.cjs <cases.json>
 *        where cases.json is [ { attributes: {...}, html: "<span …>" }, … ]
 *        as written by bin/countup-fidelity.php --json
 * exit:  0 both emitters agree on every case, 1 they diverge, 2 bad usage
 */

const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '..' );
const req = ( name ) => require( path.join( ROOT, 'node_modules', name ) );

const element = req( '@wordpress/element' );
const hooks = req( '@wordpress/hooks' );
const shallow = req( '@wordpress/is-shallow-equal' );

const { renderToString, cloneElement } = element;
const isShallowEqual = shallow.default || shallow.isShallowEqual || shallow;

// A fresh function object per stub: Object.assign( noop, { Content } ) mutates
// the shared object, so one noop for everything lets one block's Content
// clobber another's.
const stub = () => () => null;
const registered = {};

// core's blockPropsProvider: getSaveElement sets it so useBlockProps.save()
// knows which block and attributes the filters are running for.
const blockPropsProvider = { blockType: null, attributes: {} };
const wp = {
	element,
	hooks: { addFilter: hooks.addFilter, applyFilters: hooks.applyFilters, hasFilter: hooks.hasFilter },
	blocks: {
		registerBlockType: ( name, settings ) => {
			registered[ name ] = settings;
			return settings;
		},
	},
	blockEditor: {
		useBlockProps: Object.assign(
			( props ) => Object.assign( {}, props ),
			{
				// getBlockProps: apply the extraProps filters, the way the real
				// one does. `blockPropsProvider` is set by getSaveElement in
				// core; here saveContent() sets it before calling save().
				save: ( props = {} ) =>
					hooks.applyFilters(
						'blocks.getSaveContent.extraProps',
						Object.assign( {}, props ),
						blockPropsProvider.blockType,
						blockPropsProvider.attributes
					),
			}
		),
		useInnerBlocksProps: Object.assign(
			( props ) => Object.assign( {}, props ),
			{ save: ( props ) => Object.assign( {}, props ) }
		),
		InspectorControls: stub(),
		RichText: Object.assign( stub(), { Content: stub() } ),
		MediaUpload: stub(),
		InnerBlocks: Object.assign( stub(), { Content: stub() } ),
	},
	components: {
		PanelBody: stub(),
		TextControl: stub(),
		TextareaControl: stub(),
		Button: stub(),
		SelectControl: stub(),
	},
};

/*
 * The two core filters that actually put the class on the element, replicated
 * from wp-includes/js/dist/block-editor.js rather than guessed, because
 * without them the stub renders no `class` at all and every case looks like a
 * divergence.
 *
 * useBlockProps.save IS __unstableGetBlockProps (block-editor.js:26352), and
 * getBlockProps (blocks.js:3584) applies `blocks.getSaveContent.extraProps`
 * UNCONDITIONALLY — the `apiVersion > 1` guard lives only in getSaveElement's
 * own extraProps step, which is a different call site. So an apiVersion 3
 * block still receives these.
 *
 * Order matters for byte identity, and it is registration order at equal
 * priority: custom-class-name (block-editor.js:94159) appends
 * attributes.className, then generated-class-name (block-editor.js:94206)
 * puts the block's default class FIRST and dedupes. `rv-num` therefore comes
 * out as `wp-block-dxai-ui-countup rv-num`, which is what
 * Html_To_Blocks::countup_html() writes.
 */
const defaultClassName = ( name ) => 'wp-block-' + name.replace( '/', '-' ).replace( /^core-/, '' );

hooks.addFilter(
	'blocks.getSaveContent.extraProps',
	'core/editor/custom-class-name/save-props',
	( extraProps, blockType, attributes ) => {
		if ( blockType.supports?.customClassName !== false && attributes.className ) {
			extraProps.className = [ extraProps.className, attributes.className ]
				.filter( Boolean )
				.join( ' ' );
		}
		return extraProps;
	}
);
hooks.addFilter(
	'blocks.getSaveContent.extraProps',
	'core/generated-class-name/save-props',
	( extraProps, blockType ) => {
		if ( blockType.supports?.className === false ) {
			return extraProps;
		}
		const generated = defaultClassName( blockType.name );
		extraProps.className =
			typeof extraProps.className === 'string'
				? [ ...new Set( [ generated, ...extraProps.className.split( ' ' ) ] ) ].join( ' ' ).trim()
				: generated;
		return extraProps;
	}
);

global.window = { wp };
new Function(
	'window',
	fs.readFileSync( path.join( ROOT, 'assets/js/blocks-editor.js' ), 'utf8' )
)( global.window );

const NAME = 'dxai-ui/countup';
const settings = registered[ NAME ];
if ( ! settings ) {
	console.error( NAME + ' was not registered by the editor bundle' );
	process.exit( 1 );
}

/** getSaveElement, including the guard that skips extraProps for apiVersion > 1. */
function saveContent( attributes ) {
	const blockType = Object.assign( { name: NAME }, settings );
	blockPropsProvider.blockType = blockType;
	blockPropsProvider.attributes = attributes;
	let el = settings.save( { attributes, innerBlocks: [] } );
	if (
		el !== null &&
		typeof el === 'object' &&
		hooks.hasFilter( 'blocks.getSaveContent.extraProps' ) &&
		! ( ( blockType.apiVersion ?? 0 ) > 1 )
	) {
		const props = hooks.applyFilters(
			'blocks.getSaveContent.extraProps',
			{ ...el.props },
			blockType,
			attributes
		);
		if ( ! isShallowEqual( props, el.props ) ) {
			el = cloneElement( el, props );
		}
	}
	return renderToString( hooks.applyFilters( 'blocks.getSaveElement', el, blockType, attributes ) );
}

const file = process.argv[ 2 ];
if ( ! file ) {
	console.error( 'usage: node bin/countup-parity.cjs <cases.json>' );
	process.exit( 2 );
}
const cases = JSON.parse( fs.readFileSync( file, 'utf8' ) );

const defaults = {};
for ( const [ key, spec ] of Object.entries( settings.attributes ) ) {
	if ( 'default' in spec ) {
		defaults[ key ] = spec.default;
	}
}

console.log( 'declared attributes:', Object.keys( settings.attributes ).join( ', ' ) );

let fail = 0;
for ( const row of cases ) {
	// The parser applies declared defaults before save() ever runs, so compare
	// against the same attribute set the editor would hold.
	const attributes = Object.assign( {}, defaults, row.attributes );
	let got;
	try {
		got = saveContent( attributes );
	} catch ( e ) {
		got = 'threw: ' + e.message;
	}
	const ok = got === row.html;
	if ( ! ok ) {
		++fail;
		console.log( 'DIVERGE  attrs ' + JSON.stringify( row.attributes ) );
		console.log( '    php  ' + JSON.stringify( row.html ) );
		console.log( '    js   ' + JSON.stringify( got ) );
		continue;
	}
	console.log( 'agree    ' + JSON.stringify( got ) );
}

// A `key` reaching the markup is the specific hazard srClass introduced, so
// say so plainly rather than leaving it inside a byte diff.
const leaked = cases.some( ( row ) => {
	const attributes = Object.assign( {}, defaults, row.attributes );
	try {
		return / key="/.test( saveContent( attributes ) );
	} catch {
		return false;
	}
} );
if ( leaked ) {
	console.log( '\nREACT `key` reached the markup — every countup would be invalid' );
	++fail;
}

console.log( `\n${ cases.length - fail } of ${ cases.length } cases agree` );
process.exit( fail === 0 ? 0 : 1 );

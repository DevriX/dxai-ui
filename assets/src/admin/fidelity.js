/**
 * What the wizard knows about how faithful a conversion came out.
 *
 * The compiler already measures five separate things and, until now, the admin
 * showed one of them: a Tailwind coverage line. So a page could arrive with a
 * quarter of its markup frozen as raw HTML, nine expressions the JSX evaluator
 * gave up on and a v3 theme that never travelled, and the screen said
 * "coverage looks clean for this conversion" — a true sentence about the one
 * thing it looked at.
 *
 * Everything here is read off the compile result the server already returns.
 * Nothing is estimated and nothing is rounded up: a gap we cannot see is
 * reported as unknown rather than as a pass.
 */

/**
 * Blocks and raw-HTML islands, counted from the serialised markup.
 *
 * Takes one string or several: a conversion is one page's markup plus a page
 * per extra route, and the compiler's island tally spans all of them, so the
 * block count has to span all of them too.
 */
export function blockCounts( markup ) {
	const text = ( Array.isArray( markup ) ? markup : [ markup ] )
		.filter( ( part ) => typeof part === 'string' && part !== '' )
		.join( '\n' );
	if ( text === '' ) {
		return { blocks: 0, islands: 0 };
	}
	// Opening delimiters only: `<!-- /wp:x -->` closes a block it already counted.
	const blocks = ( text.match( /<!--\s+wp:/g ) || [] ).length;
	const islands = ( text.match( /<!--\s+wp:(?:dxai-ui\/html|html)[\s-]/g ) || [] ).length;

	return { blocks, islands };
}

/**
 * Every page of markup a conversion produced, primary first.
 *
 * A single-route design has one; a Lovable project with src/pages has one per
 * route, carried in `pages`. Sections are not added: `gutenberg_markup` is
 * already assembled from them, and counting both would double every block.
 */
export function allMarkup( result ) {
	const pages = Array.isArray( result?.pages ) ? result.pages : [];

	return [ String( result?.gutenberg_markup || '' ) ].concat(
		pages.map( ( page ) => String( page?.gutenberg_markup || '' ) )
	).filter( Boolean );
}

/**
 * The whole report for one conversion.
 *
 * @param {Object} result     The compile result from /generate-block.
 * @param {Object} harvest    The asset harvest, when the source carried one.
 * @param {Array}  structures The sections as the wizard currently has them.
 */
export function measureFidelity( result, harvest, structures ) {
	const counts = blockCounts( allMarkup( result ) );

	/*
	 * `islands` from the compiler is the authoritative tally — it counts every
	 * fallback as it happens, including the ones inside a section's own markup
	 * that never reaches the combined string. The delimiter count is the
	 * cross-check; where they disagree the larger number is the honest one.
	 */
	const byElement = result?.islands && typeof result.islands === 'object' ? result.islands : {};
	const tallied = Object.values( byElement ).reduce( ( sum, n ) => sum + ( Number( n ) || 0 ), 0 );
	const islands = Math.max( tallied, counts.islands );
	const blocks = Math.max( counts.blocks, islands );
	const editable = blocks > 0 ? Math.round( ( ( blocks - islands ) / blocks ) * 100 ) : null;

	const unevaluated = Array.isArray( result?.unevaluated ) ? result.unevaluated : [];
	const expressions = unevaluated.reduce( ( sum, row ) => sum + ( Number( row?.count ) || 1 ), 0 );
	const utilities = Array.isArray( result?.unresolved_utilities ) ? result.unresolved_utilities : [];

	const summary = harvest?.summary || {};
	const assets = {
		images: Number( summary.images ?? ( harvest?.images || [] ).length ) || 0,
		videos: Number( summary.videos ?? ( harvest?.videos || [] ).length ) || 0,
		fonts: Number( summary.fonts ?? ( harvest?.fonts || [] ).length ) || 0,
		links: Number( summary.links ?? ( harvest?.links || [] ).length ) || 0,
	};

	const sections = Array.isArray( structures ) ? structures : [];
	const parts = sections.filter( ( item ) => item?.type === 'header' || item?.type === 'footer' ).length;

	/*
	 * Which theme the page will be painted with. A v3 design keeps its colours,
	 * radii, fonts and keyframes in tailwind.config.ts and declares only bare
	 * HSL channels in CSS, so an import that drops the config produces a page
	 * with Tailwind's default palette — the exact admin-vs-CLI divergence this
	 * screen exists to make visible.
	 */
	const theme = {
		config: String( result?.tailwind_config || '' ).length,
		css: String( result?.design_css_raw || '' ).length,
		// A design that never ran under Tailwind takes no Preflight and no reset.
		staticHtml: Boolean( result?.static_html ),
	};

	const score = typeof result?.fidelity_score?.score === 'number' ? result.fidelity_score.score : null;

	const gaps = [];
	if ( islands > 0 ) {
		gaps.push( 'islands' );
	}
	if ( utilities.length > 0 ) {
		gaps.push( 'utilities' );
	}
	if ( expressions > 0 ) {
		gaps.push( 'expressions' );
	}
	if ( theme.css === 0 && ! theme.staticHtml ) {
		gaps.push( 'theme' );
	}

	return {
		blocks,
		islands,
		byElement,
		editable,
		sections: sections.length,
		templateParts: parts,
		expressions,
		unevaluated,
		utilities,
		assets,
		theme,
		score,
		gaps,
		// Pixel perfect is every gap closed, not a good-enough score.
		perfect: gaps.length === 0 && blocks > 0,
	};
}

/** The elements that gave up most often, worst first. */
export function worstElements( byElement, limit = 6 ) {
	return Object.entries( byElement || {} )
		.map( ( [ tag, count ] ) => ( { tag, count: Number( count ) || 0 } ) )
		.sort( ( a, b ) => b.count - a.count )
		.slice( 0, limit );
}

export function extractClasses( markup ) {
	const set = new Set();
	const quoted = markup.matchAll( /class(?:Name)?="([^"]+)"/g );
	const json = markup.matchAll( /"className":"([^"]+)"/g );
	for ( const match of [ ...quoted, ...json ] ) {
		String( match[ 1 ] || '' )
			.split( /\s+/ )
			.forEach( ( token ) => {
				const value = token.trim();
				if ( value && ! value.startsWith( 'wp-' ) && ! value.startsWith( 'dxai-ui' ) ) {
					set.add( value );
				}
			} );
	}
	return [ ...set ];
}

/**
 * Compile Tailwind in the browser when the compiler is available.
 * Returns an empty string so PHP mapping stays the WP-CLI fallback.
 */
export async function compileTailwindCss( markup ) {
	const candidates = extractClasses( markup );
	if ( ! candidates.length ) {
		return '';
	}

	try {
		const mod = await import( /* webpackIgnore: true */ '@tailwindcss/browser' ).catch( () => null );
		if ( mod && typeof mod.compile === 'function' ) {
			const compiler = await mod.compile( '@import "tailwindcss";' );
			if ( compiler && typeof compiler.build === 'function' ) {
				return compiler.build( candidates );
			}
		}
	} catch ( error ) {
		return '';
	}

	return '';
}

export function markupFromStructures( structures ) {
	const items = ( structures || [] ).filter( ( item ) => item && item.included !== false );
	return items.map( ( item ) => item.gutenberg_markup || '' ).filter( Boolean ).join( '\n' );
}

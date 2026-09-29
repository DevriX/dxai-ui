/*
 * What a chosen .dxai.zip holds, read in the browser before anything is
 * uploaded: its manifest.json, found through the ZIP's central directory and
 * inflated with DecompressionStream. The import panel then says which page
 * and design this is (and from which site), whether it has a header and a
 * footer to install, and — through chromeFacts() — whether the site's header
 * and footer are this design's already or another's.
 *
 * Only a summary is kept, never the page itself. Anything this cannot read
 * (no manifest at the root, ZIP64, a browser without DecompressionStream)
 * answers null, and the server's own check decides as before; a manifest of
 * another format answers { invalid: true }.
 */

const EOCD = 0x06054b50;
const CENTRAL = 0x02014b50;
const LOCAL = 0x04034b50;
const MANIFEST_MAX = 33554432;

function view( buffer ) {
	return new window.DataView( buffer );
}

async function bytes( file, from, to ) {
	return view( await file.slice( from, to ).arrayBuffer() );
}

/**
 * @param {File} file
 * @return {Promise<Object|null>} title, siteUrl, pageId, archive, pageKey, header, footer
 *                                (none | part | block), media, parts, patterns, status,
 *                                pluginVersion — or { invalid: true }, or null.
 */
export async function readPackage( file ) {
	try {
		if ( ! file || typeof file.slice !== 'function' || typeof window.DataView !== 'function' || typeof window.TextDecoder !== 'function' ) {
			return null;
		}
		const tailSize = Math.min( file.size, 65557 );
		const tail = await bytes( file, file.size - tailSize, file.size );
		let end = -1;
		for ( let i = tail.byteLength - 22; i >= 0; i-- ) {
			if ( tail.getUint32( i, true ) === EOCD ) {
				end = i;
				break;
			}
		}
		if ( end < 0 ) {
			return null;
		}
		const size = tail.getUint32( end + 12, true );
		const offset = tail.getUint32( end + 16, true );
		if ( offset === 0xffffffff || offset + size > file.size ) {
			return null;
		}
		const dir = await bytes( file, offset, offset + size );
		const decoder = new window.TextDecoder();
		let at = 0;
		let entry = null;
		while ( at + 46 <= dir.byteLength && dir.getUint32( at, true ) === CENTRAL ) {
			const nameLength = dir.getUint16( at + 28, true );
			const extra = dir.getUint16( at + 30, true );
			const comment = dir.getUint16( at + 32, true );
			const name = decoder.decode( new Uint8Array( dir.buffer, dir.byteOffset + at + 46, nameLength ) );
			if ( name === 'manifest.json' ) {
				entry = {
					method: dir.getUint16( at + 10, true ),
					compressed: dir.getUint32( at + 20, true ),
					size: dir.getUint32( at + 24, true ),
					offset: dir.getUint32( at + 42, true ),
				};
				break;
			}
			at += 46 + nameLength + extra + comment;
		}
		if ( ! entry || entry.size > MANIFEST_MAX ) {
			return null;
		}
		const head = await bytes( file, entry.offset, entry.offset + 30 );
		if ( head.getUint32( 0, true ) !== LOCAL ) {
			return null;
		}
		const start = entry.offset + 30 + head.getUint16( 26, true ) + head.getUint16( 28, true );
		const blob = file.slice( start, start + entry.compressed );
		let text = '';
		if ( entry.method === 0 ) {
			text = await blob.text();
		} else if ( entry.method === 8 && typeof window.DecompressionStream === 'function' && typeof blob.stream === 'function' ) {
			text = await new window.Response( blob.stream().pipeThrough( new window.DecompressionStream( 'deflate-raw' ) ) ).text();
		} else {
			return null;
		}
		const m = JSON.parse( text );
		if ( ! m || typeof m !== 'object' || m.format !== 'dxai-ui-page' ) {
			return { invalid: true };
		}
		const page = m.page && typeof m.page === 'object' ? m.page : {};
		const meta = page.meta && typeof page.meta === 'object' ? page.meta : {};
		const source = m.source && typeof m.source === 'object' ? m.source : {};
		const chrome = m.chrome && typeof m.chrome === 'object' ? m.chrome : {};
		const modeOf = ( area ) => String( ( chrome[ area ] && chrome[ area ].mode ) || 'none' );
		const count = ( list ) => ( Array.isArray( list ) ? list.length : 0 );
		return {
			title: String( page.title || '' ),
			status: String( page.status || '' ),
			siteUrl: String( source.site_url || '' ),
			pageId: Number( source.page_id || 0 ) || 0,
			pageKey: String( meta._dxai_ui_page_key || source.page_key || '' ),
			archive: String( meta._dxai_ui_source_zip || '' ),
			header: modeOf( 'header' ),
			footer: modeOf( 'footer' ),
			media: count( m.media ),
			parts: count( m.parts ),
			patterns: count( m.patterns ),
			pluginVersion: String( m.plugin_version || '' ),
		};
	} catch ( e ) {
		return null;
	}
}

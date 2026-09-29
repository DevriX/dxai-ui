import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

export default function ConverterTab() {
	const [ sourceType, setSourceType ] = useState( 'lovable-code' );
	const [ figmaUrl, setFigmaUrl ] = useState( '' );
	const [ fileKey, setFileKey ] = useState( '' );
	const [ nodeId, setNodeId ] = useState( '' );
	const [ code, setCode ] = useState( '' );
	const [ css, setCss ] = useState( '' );
	const [ file, setFile ] = useState( null );
	const [ logs, setLogs ] = useState( [] );
	const [ result, setResult ] = useState( null );
	const [ harvest, setHarvest ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ progress, setProgress ] = useState( 0 );
	const [ busy, setBusy ] = useState( false );

	function appendLog( message ) {
		setLogs( ( current ) => current.concat( [ { time: new Date().toISOString(), message } ] ) );
	}

	function body() {
		const data = new window.FormData();
		data.append( 'source_type', sourceType );
		data.append( 'figma_url', figmaUrl );
		data.append( 'file_key', fileKey );
		data.append( 'node_id', nodeId );
		data.append( 'code', code );
		data.append( 'css', css );
		if ( file ) {
			data.append( 'file', file );
		}
		return data;
	}

	function run( path ) {
		setBusy( true );
		setError( '' );
		setProgress( 25 );
		appendLog( 'Starting ' + path );
		apiFetch( { path, method: 'POST', body: body() } )
			.then( ( res ) => {
				setProgress( 100 );
				if ( res.logs ) {
					setLogs( res.logs );
				}
				if ( res.source?.payload?.harvest ) {
					setHarvest( res.source.payload.harvest );
				}
				if ( res.result ) {
					setResult( res.result );
				}
			} )
			.catch( ( err ) => setError( err.message || 'Request failed.' ) )
			.finally( () => setBusy( false ) );
	}

	function savePattern() {
		if ( ! result ) {
			return;
		}
		setBusy( true );
		apiFetch( { path: '/dxai-ui/v1/save-pattern', method: 'POST', data: { ...result, synced: true } } )
			.then( ( res ) => {
				appendLog( 'Saved pattern #' + ( res.pattern?.id || '—' ) );
				if ( res.page?.id ) {
					appendLog( 'Saved WordPress page #' + res.page.id );
				}
				setResult( { ...result, saved: res } );
			} )
			.catch( ( err ) => setError( err.message || 'Save failed.' ) )
			.finally( () => setBusy( false ) );
	}

	return (
		<div>
			{ error && <div className="dxai-notice is-error">{ error }</div> }
			<div className="dxai-grid">
				<div>
					<label htmlFor="source_type">Input source</label>
					<select id="source_type" value={ sourceType } onChange={ ( event ) => setSourceType( event.target.value ) }>
						<option value="figma-api">Figma API</option>
						<option value="figma-zip">Figma ZIP</option>
						<option value="lovable-api">Lovable API (unavailable)</option>
						<option value="lovable-zip">Lovable ZIP</option>
						<option value="lovable-code">Lovable / code paste</option>
						<option value="generated-zip">Claude / generated ZIP (HTML, Vue, React, Next.js)</option>
						<option value="claude-zip">Claude ZIP (alias)</option>
					</select>
				</div>
				<div>
					<label htmlFor="zip">ZIP upload</label>
					<input id="zip" type="file" accept=".zip" onChange={ ( event ) => setFile( event.target.files[ 0 ] || null ) } />
				</div>
			</div>
			{ sourceType === 'figma-api' && (
				<div className="dxai-grid" style={ { marginTop: '16px' } }>
					<div style={ { gridColumn: '1 / -1' } }>
						<label>Figma file URL</label>
						<input value={ figmaUrl } onChange={ ( event ) => setFigmaUrl( event.target.value ) } placeholder="https://www.figma.com/design/…" />
					</div>
					<div>
						<label>File key (optional)</label>
						<input value={ fileKey } onChange={ ( event ) => setFileKey( event.target.value ) } />
					</div>
					<div>
						<label>Node ID (optional)</label>
						<input value={ nodeId } onChange={ ( event ) => setNodeId( event.target.value ) } />
					</div>
				</div>
			) }
			{ sourceType === 'lovable-code' && (
				<div style={ { marginTop: '16px' } }>
					<label>JSX / TSX</label>
					<textarea value={ code } onChange={ ( event ) => setCode( event.target.value ) } placeholder="Paste Lovable component source…" />
					<label style={ { marginTop: '12px' } }>Optional CSS</label>
					<textarea value={ css } onChange={ ( event ) => setCss( event.target.value ) } />
				</div>
			) }
			<div className="dxai-progress"><span style={ { width: progress + '%' } } /></div>
			<div className="dxai-actions">
				<button className="dxai-btn secondary" disabled={ busy } onClick={ () => run( '/dxai-ui/v1/process-source' ) }>Process source</button>
				<button className="dxai-btn" disabled={ busy } onClick={ () => run( '/dxai-ui/v1/generate-block' ) }>{ busy ? 'Generating…' : 'Convert to Gutenberg' }</button>
				{ result && (
					<button className="dxai-btn secondary" disabled={ busy } onClick={ savePattern }>
						{ result.structures?.length ? 'Save as WordPress structures' : 'Save synced pattern' }
					</button>
				) }
			</div>
			<div className="dxai-log">
				{ logs.length ? logs.map( ( entry, index ) => <div key={ index }>{ ( entry.time || '' ) + '  ' + ( entry.message || '' ) }</div> ) : 'System logs will appear here.' }
			</div>
			{ harvest?.summary && (
				<div className="dxai-card">
					<h3>Harvested design assets</h3>
					<p>{ ( harvest.summary.images || 0 ) + ' images · ' + ( harvest.summary.videos || 0 ) + ' videos · ' + ( harvest.summary.fonts || 0 ) + ' fonts · ' + ( harvest.summary.links || 0 ) + ' links' }</p>
				</div>
			) }
			{ result && (
				<div className="dxai-card">
					<h3>{ result.block_title }</h3>
					{ result.structures?.length ? <p>Structures: { result.structures.map( ( item ) => item.type ).join( ', ' ) }</p> : null }
					<textarea readOnly value={ result.gutenberg_markup } />
				</div>
			) }
		</div>
	);
}

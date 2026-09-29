import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

export default function SettingsTab() {
	const [ settings, setSettings ] = useState( null );
	const [ catalog, setCatalog ] = useState( [] );
	const [ message, setMessage ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		apiFetch( { path: '/dxai-ui/v1/settings' } )
			.then( ( res ) => {
				setSettings( res.settings );
				setCatalog( res.catalog || [] );
			} )
			.catch( ( err ) => setError( err.message || 'Unable to load settings.' ) );
	}, [] );

	if ( ! settings ) {
		return <p>Loading settings…</p>;
	}

	const engine = catalog.find( ( item ) => item.id === settings.active_engine );
	const models = engine ? engine.models : [];
	const modelField = {
		claude: 'anthropic_model',
		openai: 'openai_model',
		grok: 'grok_model',
		deepseek: 'deepseek_model',
	}[ settings.active_engine ] || 'anthropic_model';

	function update( field, value ) {
		setSettings( { ...settings, [ field ]: value } );
	}

	function save( testProvider = '' ) {
		setBusy( true );
		setError( '' );
		setMessage( '' );
		apiFetch( {
			path: '/dxai-ui/v1/settings',
			method: 'POST',
			data: { ...settings, test_provider: testProvider },
		} )
			.then( ( res ) => {
				setSettings( res.settings );
				setMessage( testProvider ? 'Connection verified.' : 'Settings saved.' );
			} )
			.catch( ( err ) => setError( err.message || 'Save failed.' ) )
			.finally( () => setBusy( false ) );
	}

	function Secret( { id, label, configuredKey } ) {
		return (
			<div>
				<label htmlFor={ id }>{ label }{ settings[ configuredKey ] ? ' (configured)' : '' }</label>
				<input
					id={ id }
					type="password"
					autoComplete="off"
					placeholder={ settings[ configuredKey ] ? '••••••••' : '' }
					value={ settings[ id ] || '' }
					onChange={ ( event ) => update( id, event.target.value ) }
				/>
			</div>
		);
	}

	return (
		<div>
			{ message && <div className="dxai-notice">{ message }</div> }
			{ error && <div className="dxai-notice is-error">{ error }</div> }
			<div className="dxai-grid">
				<div>
					<label htmlFor="active_engine">Active model provider</label>
					<select id="active_engine" value={ settings.active_engine } onChange={ ( event ) => update( 'active_engine', event.target.value ) }>
						{ catalog.map( ( item ) => <option key={ item.id } value={ item.id }>{ item.label }</option> ) }
					</select>
				</div>
				<div>
					<label htmlFor="active_model">Model</label>
					<select id="active_model" value={ settings[ modelField ] } onChange={ ( event ) => update( modelField, event.target.value ) }>
						{ models.map( ( item ) => <option key={ item.id } value={ item.id }>{ item.label }</option> ) }
					</select>
				</div>
				<Secret id="anthropic_api_key" label="Anthropic API key" configuredKey="anthropic_api_key_configured" />
				<Secret id="openai_api_key" label="OpenAI API key" configuredKey="openai_api_key_configured" />
				<Secret id="xai_api_key" label="xAI (Grok) API key" configuredKey="xai_api_key_configured" />
				<Secret id="deepseek_api_key" label="DeepSeek API key" configuredKey="deepseek_api_key_configured" />
				<Secret id="figma_api_token" label="Figma personal access token" configuredKey="figma_api_token_configured" />
				<div>
					<label>Lovable API key</label>
					<input type="text" disabled placeholder="Not available — Lovable has no public source API" />
				</div>
			</div>
			<div className="dxai-actions">
				<button className="dxai-btn" disabled={ busy } onClick={ () => save() }>{ busy ? 'Saving…' : 'Save settings' }</button>
				<button className="dxai-btn secondary" disabled={ busy } onClick={ () => save( settings.active_engine ) }>Test active engine</button>
				<button className="dxai-btn secondary" disabled={ busy } onClick={ () => save( 'figma' ) }>Test Figma token</button>
			</div>
		</div>
	);
}

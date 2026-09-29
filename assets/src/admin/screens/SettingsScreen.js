import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, Spinner, TextControl, SelectControl } from '@wordpress/components';

const SECRET_FIELDS = [
	{ id: 'anthropic_api_key', label: __( 'Anthropic API key', 'dxai-ui' ) },
	{ id: 'openai_api_key', label: __( 'OpenAI API key', 'dxai-ui' ) },
	{ id: 'xai_api_key', label: __( 'xAI (Grok) API key', 'dxai-ui' ) },
	{ id: 'deepseek_api_key', label: __( 'DeepSeek API key', 'dxai-ui' ) },
	{ id: 'figma_api_token', label: __( 'Figma personal access token', 'dxai-ui' ) },
];

function SecretField( { field, settings, update } ) {
	return (
		<div className="dxai-field-card">
			<div className="dxai-field-card-top">
				<strong>{ field.label }</strong>
				{ settings[ field.id + '_configured' ] && <span className="dxai-pill">{ __( 'Configured', 'dxai-ui' ) }</span> }
			</div>
			<TextControl
				label=""
				hideLabelFromVision
				type="password"
				autoComplete="off"
				placeholder={ settings[ field.id + '_configured' ] ? '••••••••' : '' }
				value={ settings[ field.id ] || '' }
				onChange={ ( value ) => update( field.id, value ) }
			/>
		</div>
	);
}

export default function SettingsScreen() {
	const [ settings, setSettings ] = useState( null );
	const [ catalog, setCatalog ] = useState( [] );
	const [ message, setMessage ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	function load() {
		setError( '' );
		apiFetch( { path: '/dxai-ui/v1/settings' } )
			.then( ( res ) => {
				setSettings( res.settings );
				setCatalog( res.catalog || [] );
			} )
			.catch( ( err ) => setError( err.message || __( 'Unable to load settings.', 'dxai-ui' ) ) );
	}

	useEffect( () => {
		load();
	}, [] );

	if ( error && ! settings ) {
		return (
			<div className="dxai-panel">
				<Notice status="error" isDismissible={ false }>{ error }</Notice>
				<Button variant="secondary" onClick={ load }>{ __( 'Try again', 'dxai-ui' ) }</Button>
			</div>
		);
	}

	if ( ! settings ) {
		return (
			<div className="dxai-panel">
				<Spinner />
				<p>{ __( 'Loading settings…', 'dxai-ui' ) }</p>
			</div>
		);
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
				setMessage( testProvider ? __( 'Connection verified.', 'dxai-ui' ) : __( 'Settings saved.', 'dxai-ui' ) );
			} )
			.catch( ( err ) => setError( err.message || __( 'Save failed.', 'dxai-ui' ) ) )
			.finally( () => setBusy( false ) );
	}

	const stats = [
		{ label: __( 'Active provider', 'dxai-ui' ), value: engine?.label || '—' },
		{ label: __( 'Models available', 'dxai-ui' ), value: models.length || 0 },
		{ label: __( 'Secrets configured', 'dxai-ui' ), value: SECRET_FIELDS.filter( ( field ) => settings[ field.id + '_configured' ] ).length },
	];

	return (
		<div className="dxai-screen">
			<ul className="dxai-stats">
				{ stats.map( ( item ) => (
					<li key={ item.label }>
						<strong>{ item.value }</strong>
						{ item.label }
					</li>
				) ) }
			</ul>
			<div className="dxai-panel dxai-panel--stack">
				{ message && <Notice status="success" isDismissible={ false }>{ message }</Notice> }
				{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
				<div className="dxai-section">
					<div className="dxai-section-head">
						<div>
							<p className="dxai-section-kicker">{ __( 'Routing', 'dxai-ui' ) }</p>
							<h3>{ __( 'Provider and model', 'dxai-ui' ) }</h3>
						</div>
					</div>
					<div className="dxai-grid">
						<SelectControl
							label={ __( 'Active model provider', 'dxai-ui' ) }
							value={ settings.active_engine }
							options={ catalog.map( ( item ) => ( { label: item.label, value: item.id } ) ) }
							onChange={ ( value ) => update( 'active_engine', value ) }
						/>
						<SelectControl
							label={ __( 'Model', 'dxai-ui' ) }
							value={ settings[ modelField ] }
							options={ models.map( ( item ) => ( { label: item.label, value: item.id } ) ) }
							onChange={ ( value ) => update( modelField, value ) }
						/>
					</div>
				</div>
				<div className="dxai-section">
					<div className="dxai-section-head">
						<div>
							<p className="dxai-section-kicker">{ __( 'Security', 'dxai-ui' ) }</p>
							<h3>{ __( 'Encrypted credentials', 'dxai-ui' ) }</h3>
						</div>
						<span className="dxai-pill">{ __( 'At rest', 'dxai-ui' ) }</span>
					</div>
					<p className="dxai-muted">{ __( 'API keys are encrypted at rest and never sent back to the browser after save.', 'dxai-ui' ) }</p>
					<div className="dxai-card-grid dxai-card-grid--tight">
						{ SECRET_FIELDS.map( ( field ) => (
							<SecretField key={ field.id } field={ field } settings={ settings } update={ update } />
						) ) }
						<div className="dxai-field-card dxai-field-card--muted">
							<div className="dxai-field-card-top">
								<strong>{ __( 'Lovable API key', 'dxai-ui' ) }</strong>
								<span className="dxai-pill">{ __( 'ZIP or paste', 'dxai-ui' ) }</span>
							</div>
							<p className="dxai-muted">{ __( 'Lovable has no public source API. Use ZIP or paste in Convert.', 'dxai-ui' ) }</p>
						</div>
					</div>
				</div>
				<div className="dxai-actions">
					<Button variant="primary" disabled={ busy } onClick={ () => save() }>
						{ busy ? __( 'Saving…', 'dxai-ui' ) : __( 'Save settings', 'dxai-ui' ) }
					</Button>
					<Button variant="secondary" disabled={ busy } onClick={ () => save( settings.active_engine ) }>
						{ __( 'Test active engine', 'dxai-ui' ) }
					</Button>
					<Button variant="secondary" disabled={ busy } onClick={ () => save( 'figma' ) }>
						{ __( 'Test Figma token', 'dxai-ui' ) }
					</Button>
				</div>
			</div>
		</div>
	);
}

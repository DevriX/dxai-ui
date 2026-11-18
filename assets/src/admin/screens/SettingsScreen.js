import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, Spinner, TextControl, SelectControl, ToggleControl } from '@wordpress/components';

const SECRET_FIELDS = [
	{ id: 'anthropic_api_key', label: __( 'Anthropic API key', 'dx-ui' ) },
	{ id: 'openai_api_key', label: __( 'OpenAI API key', 'dx-ui' ) },
	{ id: 'xai_api_key', label: __( 'xAI (Grok) API key', 'dx-ui' ) },
	{ id: 'deepseek_api_key', label: __( 'DeepSeek API key', 'dx-ui' ) },
	{ id: 'figma_api_token', label: __( 'Figma personal access token', 'dx-ui' ) },
];

function SecretField( { field, settings, update } ) {
	return (
		<div className="dxui-field-card">
			<div className="dxui-field-card-top">
				<strong>{ field.label }</strong>
				{ settings[ field.id + '_configured' ] && <span className="dxui-pill">{ __( 'Configured', 'dx-ui' ) }</span> }
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
		apiFetch( { path: '/dx-ui/v1/settings' } )
			.then( ( res ) => {
				setSettings( res.settings );
				setCatalog( res.catalog || [] );
			} )
			.catch( ( err ) => setError( err.message || __( 'Unable to load settings.', 'dx-ui' ) ) );
	}

	useEffect( () => {
		load();
	}, [] );

	if ( error && ! settings ) {
		return (
			<div className="dxui-panel">
				<Notice status="error" isDismissible={ false }>{ error }</Notice>
				<Button variant="secondary" onClick={ load }>{ __( 'Try again', 'dx-ui' ) }</Button>
			</div>
		);
	}

	if ( ! settings ) {
		return (
			<div className="dxui-panel">
				<Spinner />
				<p>{ __( 'Loading settings…', 'dx-ui' ) }</p>
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
			path: '/dx-ui/v1/settings',
			method: 'POST',
			data: { ...settings, test_provider: testProvider },
		} )
			.then( ( res ) => {
				setSettings( res.settings );
				setMessage( testProvider ? __( 'Connection verified.', 'dx-ui' ) : __( 'Settings saved.', 'dx-ui' ) );
			} )
			.catch( ( err ) => setError( err.message || __( 'Save failed.', 'dx-ui' ) ) )
			.finally( () => setBusy( false ) );
	}

	const stats = [
		{ label: __( 'Active provider', 'dx-ui' ), value: engine?.label || '—' },
		{ label: __( 'Models available', 'dx-ui' ), value: models.length || 0 },
		{ label: __( 'Secrets configured', 'dx-ui' ), value: SECRET_FIELDS.filter( ( field ) => settings[ field.id + '_configured' ] ).length },
	];

	return (
		<div className="dxui-screen">
			<ul className="dxui-stats">
				{ stats.map( ( item ) => (
					<li key={ item.label }>
						<strong>{ item.value }</strong>
						{ item.label }
					</li>
				) ) }
			</ul>
			<div className="dxui-panel dxui-panel--stack">
				{ message && <Notice status="success" isDismissible={ false }>{ message }</Notice> }
				{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
				<div className="dxui-section">
					<div className="dxui-section-head">
						<div>
							<p className="dxui-section-kicker">{ __( 'Routing', 'dx-ui' ) }</p>
							<h3>{ __( 'Provider and model', 'dx-ui' ) }</h3>
						</div>
					</div>
					<div className="dxui-grid">
						<SelectControl
							label={ __( 'Active model provider', 'dx-ui' ) }
							value={ settings.active_engine }
							options={ catalog.map( ( item ) => ( { label: item.label, value: item.id } ) ) }
							onChange={ ( value ) => update( 'active_engine', value ) }
						/>
						<SelectControl
							label={ __( 'Model', 'dx-ui' ) }
							value={ settings[ modelField ] }
							options={ models.map( ( item ) => ( { label: item.label, value: item.id } ) ) }
							onChange={ ( value ) => update( modelField, value ) }
						/>
					</div>
				</div>
				<div className="dxui-section">
					<div className="dxui-section-head">
						<div>
							<p className="dxui-section-kicker">{ __( 'Pages', 'dx-ui' ) }</p>
							<h3>{ __( 'How a design’s states run', 'dx-ui' ) }</h3>
						</div>
						<span className="dxui-pill">{ __( 'On by default', 'dx-ui' ) }</span>
					</div>
					<ToggleControl
						label={ __( 'Drive the states with WordPress’s Interactivity API', 'dx-ui' ) }
						help={ __( 'A menu that opens, a header that turns solid on scroll, a FAQ that unfolds: with this on, the page’s marks get Interactivity API directives as it renders and WordPress’s own runtime drives them; off, the plugin’s page runtime does, as before. Measured the same either way on every design with states, at rest and when touched. Nothing stored changes, so it can be switched at any time. The reveal on approach, the counting numbers and the positioned parts stay with the page runtime either way.', 'dx-ui' ) }
						checked={ !! settings.interactivity }
						onChange={ ( value ) => update( 'interactivity', value ) }
						__nextHasNoMarginBottom
					/>
				</div>
				<div className="dxui-section">
					<div className="dxui-section-head">
						<div>
							<p className="dxui-section-kicker">{ __( 'Security', 'dx-ui' ) }</p>
							<h3>{ __( 'Encrypted credentials', 'dx-ui' ) }</h3>
						</div>
						<span className="dxui-pill">{ __( 'At rest', 'dx-ui' ) }</span>
					</div>
					<p className="dxui-muted">{ __( 'API keys are encrypted at rest and never sent back to the browser after save.', 'dx-ui' ) }</p>
					<div className="dxui-card-grid dxui-card-grid--tight">
						{ SECRET_FIELDS.map( ( field ) => (
							<SecretField key={ field.id } field={ field } settings={ settings } update={ update } />
						) ) }
						<div className="dxui-field-card dxui-field-card--muted">
							<div className="dxui-field-card-top">
								<strong>{ __( 'Lovable API key', 'dx-ui' ) }</strong>
								<span className="dxui-pill">{ __( 'ZIP or paste', 'dx-ui' ) }</span>
							</div>
							<p className="dxui-muted">{ __( 'Lovable has no public source API. Use ZIP or paste in Convert.', 'dx-ui' ) }</p>
						</div>
					</div>
				</div>
				<div className="dxui-actions">
					<Button variant="primary" disabled={ busy } onClick={ () => save() }>
						{ busy ? __( 'Saving…', 'dx-ui' ) : __( 'Save settings', 'dx-ui' ) }
					</Button>
					<Button variant="secondary" disabled={ busy } onClick={ () => save( settings.active_engine ) }>
						{ __( 'Test active engine', 'dx-ui' ) }
					</Button>
					<Button variant="secondary" disabled={ busy } onClick={ () => save( 'figma' ) }>
						{ __( 'Test Figma token', 'dx-ui' ) }
					</Button>
				</div>
			</div>
		</div>
	);
}

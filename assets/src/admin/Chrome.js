import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const LINKS = [
	{ id: 'convert', href: 'admin.php?page=dxai-ui', label: __( 'Convert', 'dxai-ui' ) },
	{ id: 'library', href: 'admin.php?page=dxai-ui-library', label: __( 'Library', 'dxai-ui' ) },
	{ id: 'forms', href: 'admin.php?page=dxai-ui-forms', label: __( 'Entries', 'dxai-ui' ) },
	{ id: 'settings', href: 'admin.php?page=dxai-ui-settings', label: __( 'Settings', 'dxai-ui' ) },
];

function Mark() {
	return (
		<svg className="dxai-mark" viewBox="0 0 32 32" aria-hidden="true">
			<rect x="2" y="2" width="28" height="28" rx="9" fill="currentColor" opacity="0.12" />
			<path
				d="M9 22.5V9.5h6.2c3.4 0 5.5 1.9 5.5 4.7 0 1.9-1 3.3-2.6 4.1L22 22.5h-3.2l-3.5-4.2H12V22.5H9zm3-7.3h2.9c1.7 0 2.7-.9 2.7-2.3s-1-2.3-2.7-2.3H12v4.6z"
				fill="currentColor"
			/>
		</svg>
	);
}

function statusLabel( settings ) {
	if ( ! settings ) {
		return __( 'Loading', 'dxai-ui' );
	}

	const names = {
		claude: 'Claude',
		openai: 'OpenAI',
		grok: 'Grok',
		deepseek: 'DeepSeek',
	};

	return names[ settings.active_engine ] || __( 'Not set', 'dxai-ui' );
}

export default function Chrome( { screen, version, meta, children } ) {
	const [ settings, setSettings ] = useState( null );
	const [ library, setLibrary ] = useState( null );

	useEffect( () => {
		apiFetch( { path: '/dxai-ui/v1/settings' } )
			.then( ( res ) => setSettings( res.settings || {} ) )
			.catch( () => setSettings( {} ) );

		apiFetch( { path: '/dxai-ui/v1/patterns' } )
			.then( ( res ) => setLibrary( {
				patterns: ( res.patterns || [] ).length,
				conversions: ( res.conversions || [] ).length,
				pages: ( res.pages || [] ).length,
			} ) )
			.catch( () => setLibrary( { patterns: 0, conversions: 0, pages: 0 } ) );
	}, [] );

	const stats = useMemo(
		() => [
			{
				label: __( 'Active provider', 'dxai-ui' ),
				value: statusLabel( settings ),
			},
			{
				label: __( 'Pages saved', 'dxai-ui' ),
				value: library ? String( library.pages ) : '...'
			},
			{
				label: __( 'Conversions', 'dxai-ui' ),
				value: library ? String( library.conversions ) : '...'
			},
			{
				label: __( 'Plugin version', 'dxai-ui' ),
				value: version ? 'v' + version : '...'
			},
		],
		[ library, settings, version ]
	);

	return (
		<div className="dxai-shell">
			<aside className="dxai-rail">
				<a className="dxai-brand" href="admin.php?page=dxai-ui">
					<Mark />
					<span>
						<strong>DX Convert</strong>
						<em>{ __( 'Design to Gutenberg', 'dxai-ui' ) }</em>
					</span>
				</a>
				<nav className="dxai-nav" aria-label={ __( 'DX Convert', 'dxai-ui' ) }>
					{ LINKS.map( ( item ) => (
						<a
							key={ item.id }
							href={ item.href }
							className={ 'dxai-nav-link' + ( screen === item.id ? ' is-current' : '' ) }
							aria-current={ screen === item.id ? 'page' : undefined }
						>
							<span className={ 'dxai-nav-ico dxai-nav-ico--' + item.id } aria-hidden="true" />
							{ item.label }
						</a>
					) ) }
				</nav>
				<div className="dxai-rail-card">
					<p className="dxai-rail-label">{ __( 'Workspace', 'dxai-ui' ) }</p>
					<strong>{ __( 'Pixel-perfect Gutenberg pipeline', 'dxai-ui' ) }</strong>
					<p>{ __( 'Convert, review, store, and restore generated structures from one branded control center.', 'dxai-ui' ) }</p>
				</div>
				<p className="dxai-rail-meta">
					<span>{ __( 'DevriX', 'dxai-ui' ) }</span>
					{ version ? <span>v{ version }</span> : null }
				</p>
			</aside>
			<div className="dxai-main">
				<header className="dxai-shell-head">
					<div className="dxai-shell-copy">
						<p className="dxai-kicker">{ meta?.kicker || __( 'DX Studio', 'dxai-ui' ) }</p>
						<h1>{ meta?.title || __( 'DX Convert', 'dxai-ui' ) }</h1>
						<p>{ meta?.description || __( 'A premium control layer for design-to-Gutenberg workflows inside WordPress.', 'dxai-ui' ) }</p>
					</div>
					<div className="dxai-shell-actions">
						<a className="dxai-shell-link" href="admin.php?page=dxai-ui">
							{ __( 'New conversion', 'dxai-ui' ) }
						</a>
						<a className="dxai-shell-link" href="admin.php?page=dxai-ui-settings">
							{ __( 'Open settings', 'dxai-ui' ) }
						</a>
					</div>
				</header>
				<section className="dxai-shell-stats" aria-label={ __( 'DX status', 'dxai-ui' ) }>
					{ stats.map( ( item ) => (
						<div className="dxai-stat-card" key={ item.label }>
							<span>{ item.label }</span>
							<strong>{ item.value }</strong>
						</div>
					) ) }
				</section>
				{ children }
			</div>
		</div>
	);
}

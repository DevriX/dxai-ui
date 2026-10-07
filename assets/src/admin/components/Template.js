import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, Spinner } from '@wordpress/components';
import './template.css';

/*
 * "Template": what the site's header, footer and fonts are now, and DX Base — the small theme this plugin carries for a site that has no
 * DX theme (Template_Controller, Base_Theme).
 *
 * A DX site has one structure: the header is Appearance › Menus, the footer is Appearance › Widgets, drawn in the look of the design the
 * site was imported from, on every page. American Restoration draws them on every page; on another theme, only the design's own pages do.
 * DX Base draws them everywhere. Installing it copies it into the themes folder (or lists it from the plugin's own folder where that
 * cannot be written), and switching the site to it is a separate step the person takes: nothing here changes the theme by itself.
 */

function Where( { owner, fallback, href, linkLabel } ) {
	if ( ! owner ) {
		return <span className="dxai-muted">{ fallback }</span>;
	}
	return (
		<>
			<span>{ owner.title }</span>
			{ owner.live && owner.edit && (
				<>
					{ ' ' }
					<a href={ owner.edit }>{ __( 'Open the design’s page', 'dxai-ui' ) }</a>
				</>
			) }
			{ href && (
				<>
					{ ' · ' }
					<a href={ href }>{ linkLabel }</a>
				</>
			) }
		</>
	);
}

export default function Template() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );
	const [ said, setSaid ] = useState( '' );
	const [ sure, setSure ] = useState( false );

	useEffect( () => {
		apiFetch( { path: '/dxai-ui/v1/template' } )
			.then( setData )
			.catch( ( e ) => setError( e.message || __( 'The template could not be read.', 'dxai-ui' ) ) );
	}, [] );

	if ( error && ! data ) {
		return <Notice status="error" isDismissible={ false }>{ error }</Notice>;
	}
	if ( ! data ) {
		return <Spinner />;
	}

	const { theme, base, header, footer, fonts, logo, links } = data;

	function run( action, done ) {
		setBusy( action );
		setSaid( '' );
		setError( '' );
		setSure( false );
		apiFetch( { path: '/dxai-ui/v1/template', method: 'POST', data: { action } } )
			.then( ( res ) => {
				setData( res );
				setSaid( done( res ) );
			} )
			.catch( ( e ) => setError( e.message || __( 'That did not work.', 'dxai-ui' ) ) )
			.finally( () => setBusy( '' ) );
	}

	let themeText;
	if ( theme.base ) {
		themeText = sprintf(
			/* translators: %s: version of the DX Base theme. */
			__( 'DX Base %s: the header and the footer below are drawn on every page of the site.', 'dxai-ui' ),
			base.version
		);
	} else if ( theme.dx ) {
		themeText = sprintf(
			/* translators: %s: theme name. */
			__( '%s is a DX theme: the header and the footer below are drawn on every page of the site.', 'dxai-ui' ),
			theme.name
		);
	} else {
		themeText = sprintf(
			/* translators: %s: theme name. */
			__( '%s is not a DX theme: it draws its own header and footer on the pages that are not a design’s.', 'dxai-ui' ),
			theme.name
		);
	}

	const showOffer = ! theme.dx && ! base.foreign;
	const installed = base.installed;

	return (
		<section className="dxai-section dxai-template" aria-labelledby="dxai-template-title">
			<div className="dxai-section-head">
				<div>
					<p className="dxai-section-kicker">{ __( 'Your site', 'dxai-ui' ) }</p>
					<h3 id="dxai-template-title">{ __( 'Template', 'dxai-ui' ) }</h3>
					<p className="dxai-muted">
						{ __( 'The header is Appearance › Menus and the footer is Appearance › Widgets, in the look of the design the site was imported from. Edit them there, never inside a page.', 'dxai-ui' ) }
					</p>
				</div>
			</div>
			<ul className="dxai-template__list">
				<li>
					<strong>{ __( 'Theme', 'dxai-ui' ) }</strong>
					<span>{ themeText }</span>
				</li>
				<li>
					<strong>{ __( 'Header', 'dxai-ui' ) }</strong>
					<span>
						<Where
							owner={ header }
							fallback={ __( 'None installed yet. Import a design and choose to install its header.', 'dxai-ui' ) }
							href={ links.menus }
							linkLabel={ __( 'Menus', 'dxai-ui' ) }
						/>
					</span>
				</li>
				<li>
					<strong>{ __( 'Footer', 'dxai-ui' ) }</strong>
					<span>
						<Where
							owner={ footer }
							fallback={ __( 'None installed yet. Import a design and choose to install its footer.', 'dxai-ui' ) }
							href={ links.widgets }
							linkLabel={ __( 'Widgets', 'dxai-ui' ) }
						/>
					</span>
				</li>
				<li>
					<strong>{ __( 'Fonts', 'dxai-ui' ) }</strong>
					<span>
						{ fonts
							? sprintf(
								/* translators: 1: headline font, 2: body font, 3: design title. */
								__( '%1$s for headlines and %2$s for text, from “%3$s”.', 'dxai-ui' ),
								fonts.heading,
								fonts.body,
								fonts.title
							)
							: <span className="dxai-muted">{ __( 'The theme’s own.', 'dxai-ui' ) }</span> }
					</span>
				</li>
				<li>
					<strong>{ __( 'Logo', 'dxai-ui' ) }</strong>
					<span>
						{ logo && logo.url ? <img className="dxai-template__logo" src={ logo.url } alt={ logo.alt || '' } /> : null }
						{ ! logo && <span className="dxai-muted">{ __( 'None yet. ', 'dxai-ui' ) }</span> }
						<a href={ links.logo }>{ logo ? __( 'Change the logo', 'dxai-ui' ) : __( 'Set the logo', 'dxai-ui' ) }</a>
					</span>
				</li>
			</ul>

			{ base.foreign && (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'The theme in this site’s dx-base folder is not DX Base. It was not changed, and DX Base cannot be installed beside it: rename or remove that theme in Appearance › Themes first.', 'dxai-ui' ) }
				</Notice>
			) }
			{ said && <Notice status="success" isDismissible={ false }>{ said }</Notice> }
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }

			{ theme.base && base.update && ! base.foreign && (
				<div className="dxai-template__offer">
					<p>
						{ sprintf(
							/* translators: 1: installed version, 2: the plugin's version. */
							__( 'The plugin carries DX Base %2$s; the site has %1$s.', 'dxai-ui' ),
							base.version,
							base.bundled
						) }
					</p>
					<Button
						variant="secondary"
						isBusy={ busy === 'base-install' }
						disabled={ !! busy || ! base.can_install }
						onClick={ () => run( 'base-install', () => __( 'DX Base is up to date.', 'dxai-ui' ) ) }
					>
						{ __( 'Update DX Base', 'dxai-ui' ) }
					</Button>
				</div>
			) }

			{ showOffer && (
				<div className="dxai-template__offer">
					<h4>{ __( 'DX Base', 'dxai-ui' ) }</h4>
					<p>
						{ __( 'A small theme this plugin carries, with the structure of American Restoration: the header from Menus and the footer from Widgets on every page of the site (a page, a post, an archive, the search, the 404 page), in the look of the design. It has no colours, fonts or header of its own: the design’s are the site’s.', 'dxai-ui' ) }
					</p>
					{ ! installed && (
						<>
							<p className="dxai-muted">
								{ base.writable
									? __( 'Installing copies it into the site’s themes folder. Your current theme stays installed and the site keeps using it until you switch.', 'dxai-ui' )
									: __( 'The site’s themes folder cannot be written, so it would be listed from this plugin’s own folder instead. It then needs this plugin to stay active. Your current theme stays installed and the site keeps using it until you switch.', 'dxai-ui' ) }
							</p>
							<Button
								variant="primary"
								isBusy={ busy === 'base-install' }
								disabled={ !! busy || ! base.can_install }
								onClick={ () => run( 'base-install', ( res ) => ( res.done && res.done.mode === 'registered'
									? __( 'DX Base is listed in Appearance › Themes, from this plugin’s folder. Switch the site to it when you are ready.', 'dxai-ui' )
									: __( 'DX Base is installed in Appearance › Themes. Switch the site to it when you are ready.', 'dxai-ui' ) ) ) }
							>
								{ __( 'Install DX Base', 'dxai-ui' ) }
							</Button>
							{ ! base.can_install && <p className="dxai-muted">{ base.install_note }</p> }
						</>
					) }
					{ installed && ! theme.base && ! sure && (
						<Button
							variant="primary"
							disabled={ !! busy || ! base.can_activate }
							onClick={ () => setSure( true ) }
						>
							{ __( 'Switch the site to DX Base', 'dxai-ui' ) }
						</Button>
					) }
					{ installed && ! theme.base && sure && (
						<div className="dxai-template__sure" role="group" aria-label={ __( 'Switch the site to DX Base', 'dxai-ui' ) }>
							<p>
								{ sprintf(
									/* translators: %1$s: the name of the site's theme now. */
									__( 'The site’s theme changes from %1$s to DX Base. The pages the plugin made look the same, and the menus and widgets in the locations both themes share stay where they are. %1$s stays installed; you can switch back in Appearance › Themes.', 'dxai-ui' ),
									theme.name
								) }
							</p>
							<div className="dxai-actions">
								<Button
									variant="primary"
									isBusy={ busy === 'base-activate' }
									disabled={ !! busy }
									onClick={ () => run( 'base-activate', () => __( 'The site now runs DX Base.', 'dxai-ui' ) ) }
								>
									{ __( 'Yes, switch to DX Base', 'dxai-ui' ) }
								</Button>
								<Button variant="tertiary" disabled={ !! busy } onClick={ () => setSure( false ) }>
									{ __( 'Cancel', 'dxai-ui' ) }
								</Button>
							</div>
						</div>
					) }
					{ installed && ! theme.base && ! base.can_activate && <p className="dxai-muted">{ base.activate_note }</p> }
				</div>
			) }
		</section>
	);
}

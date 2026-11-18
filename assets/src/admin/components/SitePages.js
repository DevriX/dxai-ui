import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, SelectControl, Spinner, TextControl } from '@wordpress/components';
import './site-pages.css';

/*
 * "Pages from your live site": the pages an imported design links to on the
 * old site, each with the page built for it, and a way to build the ones the
 * person picks — any time after the import, as often as they like. The pages
 * are composed from the design's own sections with the old pages' words and
 * pictures (no AI), then the menus and the footer point at them.
 *
 * The job runs through the import's crawl routes: one POST /crawl-step per
 * few seconds of work, so a host that ends requests after 60 s is fine.
 */

const STATUS = {
	new: __( 'Not imported', 'dx-ui' ),
	imported: __( 'Imported', 'dx-ui' ),
	edited: __( 'Imported — edited since', 'dx-ui' ),
};

/** A site address without its scheme, as people write it. */
const bare = ( url ) => url.replace( /^https?:\/\//, '' );

const PHASE = {
	fetch: __( 'Reading the old page', 'dx-ui' ),
	images: __( 'Copying its pictures', 'dx-ui' ),
	restyle: __( 'Arranging it in the design', 'dx-ui' ),
	save: __( 'Saving', 'dx-ui' ),
};

export default function SitePages( { design: fixed = 0 } ) {
	const [ designs, setDesigns ] = useState( null );
	const [ home, setHome ] = useState( fixed );
	const [ listing, setListing ] = useState( null );
	const [ chosen, setChosen ] = useState( {} );
	const [ error, setError ] = useState( '' );
	const [ progress, setProgress ] = useState( null );
	const [ result, setResult ] = useState( null );
	// The old site's address: shown, and typed in when the design's links do not show it (or show another).
	const [ originInput, setOriginInput ] = useState( '' );
	const [ editOrigin, setEditOrigin ] = useState( false );
	// Build the chosen pages again even where someone edited them since their import (their edits are replaced).
	const [ rebuildEdited, setRebuildEdited ] = useState( false );
	const live = useRef( true );

	useEffect( () => {
		live.current = true;
		apiFetch( { path: '/dx-ui/v1/site-pages/designs' } )
			.then( ( res ) => {
				const list = res.designs || [];
				setDesigns( list );
				if ( ! fixed && list.length ) {
					setHome( list[ 0 ].id );
				}
			} )
			.catch( () => setDesigns( [] ) );
		return () => {
			live.current = false;
		};
	}, [] );

	function load( id, origin = '' ) {
		setListing( null );
		setError( '' );
		apiFetch( { path: '/dx-ui/v1/site-pages?home=' + id + ( origin ? '&origin=' + encodeURIComponent( origin ) : '' ) } )
			.then( ( res ) => {
				setListing( res );
				setOriginInput( bare( res.origin || '' ) );
				setEditOrigin( ! res.origin || ! ( res.pages || [] ).length );
				const pick = {};
				( res.pages || [] ).forEach( ( p ) => {
					pick[ p.path ] = p.status === 'new';
				} );
				setChosen( pick );
			} )
			.catch( ( err ) => setError( ( err && err.message ) || __( 'Could not read the design’s links.', 'dx-ui' ) ) );
	}

	useEffect( () => {
		if ( home ) {
			load( home );
		}
	}, [ home ] );

	if ( designs === null || designs.length === 0 ) {
		return null;
	}

	const pages = ( listing && listing.pages ) || [];
	const paths = pages.filter( ( p ) => chosen[ p.path ] ).map( ( p ) => p.path );
	const running = Boolean( progress && ! progress.done );

	function step( job ) {
		apiFetch( { path: '/dx-ui/v1/crawl-step', method: 'POST', data: { job } } )
			.then( ( res ) => {
				if ( ! live.current ) {
					return;
				}
				setProgress( res );
				if ( res.done ) {
					setResult( res.result || {} );
					load( home );
					return;
				}
				setTimeout( () => step( job ), 400 );
			} )
			.catch( ( err ) => {
				setError( ( err && err.message ) || __( 'The import stopped. Start it again — pages already built are kept and updated in place.', 'dx-ui' ) );
				setProgress( null );
			} );
	}

	function start() {
		setError( '' );
		setResult( null );
		setProgress( { index: 0, total: paths.length, phase: 'fetch', label: '' } );
		apiFetch( { path: '/dx-ui/v1/site-pages', method: 'POST', data: { home, paths, rebuild_edited: rebuildEdited } } )
			.then( ( res ) => step( res.job ) )
			.catch( ( err ) => {
				setError( ( err && err.message ) || __( 'Could not start the import.', 'dx-ui' ) );
				setProgress( null );
			} );
	}

	const pct = progress && progress.total ? Math.round( ( 100 * Math.min( progress.index, progress.total ) ) / progress.total ) : 0;

	return (
		<section className="dxui-section dxui-site-pages" aria-labelledby="dxui-site-pages-title">
			<div className="dxui-section-head">
				<div>
					<p className="dxui-section-kicker">{ __( 'Your old site', 'dx-ui' ) }</p>
					<h3 id="dxui-site-pages-title">{ __( 'Pages from your live site', 'dx-ui' ) }</h3>
					<p className="dxui-muted">
						{ __( 'Builds the pages your design links to, with each old page’s own text and pictures arranged in the design’s sections — no new styles, no AI. The menus and the footer then open the new pages.', 'dx-ui' ) }
					</p>
				</div>
			</div>

			{ ! fixed && designs.length > 1 && (
				<SelectControl
					label={ __( 'Design', 'dx-ui' ) }
					value={ String( home ) }
					options={ designs.map( ( d ) => ( { value: String( d.id ), label: d.title } ) ) }
					onChange={ ( v ) => setHome( Number( v ) ) }
					disabled={ running }
				/>
			) }

			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! listing && ! error && <Spinner /> }

			{ listing && (
				<div className="dxui-site-pages__origin">
					{ editOrigin ? (
						<form
							className="dxui-site-pages__origin-form"
							onSubmit={ ( e ) => {
								e.preventDefault();
								if ( originInput.trim() ) {
									load( home, originInput.trim() );
								}
							} }
						>
							<TextControl
								label={ __( 'Old site address', 'dx-ui' ) }
								value={ originInput }
								onChange={ setOriginInput }
								placeholder="example.com"
								help={ listing.origin
									? __( 'The site whose pages are built here. Change it if the design’s links point somewhere else.', 'dx-ui' )
									: __( 'The design’s links do not show where the old site is. Type its address and its pages are listed from its own menus.', 'dx-ui' ) }
								disabled={ running }
							/>
							<Button variant="secondary" type="submit" disabled={ running || ! originInput.trim() }>
								{ __( 'Find its pages', 'dx-ui' ) }
							</Button>
						</form>
					) : (
						<p>
							{ __( 'Old site:', 'dx-ui' ) } <strong>{ bare( listing.origin ) }</strong>{ ' ' }
							<Button variant="link" onClick={ () => setEditOrigin( true ) } disabled={ running }>{ __( 'Change', 'dx-ui' ) }</Button>
						</p>
					) }
				</div>
			) }

			{ listing && pages.length === 0 && listing.origin && (
				<p className="dxui-muted">{ __( 'No pages found on that site: neither the design’s links nor the site’s own menus lead to one.', 'dx-ui' ) }</p>
			) }

			{ listing && pages.length > 0 && (
				<fieldset className="dxui-site-pages__list" disabled={ running }>
					<legend className="dxui-site-pages__legend">
						{ sprintf(
							/* translators: %s: the old site's address */
							__( 'Pages on %s', 'dx-ui' ),
							bare( listing.origin )
						) }
					</legend>
					<div className="dxui-site-pages__bulk">
						<Button variant="link" onClick={ () => setChosen( Object.fromEntries( pages.map( ( p ) => [ p.path, true ] ) ) ) }>
							{ __( 'Select all', 'dx-ui' ) }
						</Button>
						<Button variant="link" onClick={ () => setChosen( Object.fromEntries( pages.map( ( p ) => [ p.path, p.status === 'new' ] ) ) ) }>
							{ __( 'Only the ones not imported', 'dx-ui' ) }
						</Button>
						<Button variant="link" onClick={ () => setChosen( {} ) }>{ __( 'None', 'dx-ui' ) }</Button>
					</div>
					<ul>
						{ pages.map( ( p ) => {
							const id = 'dxui-sp-' + p.path.replace( /[^a-z0-9]+/gi, '-' );
							return (
								<li key={ p.path } className={ 'dxui-site-pages__row is-' + p.status }>
									<input
										type="checkbox"
										id={ id }
										checked={ Boolean( chosen[ p.path ] ) }
										onChange={ ( e ) => setChosen( { ...chosen, [ p.path ]: e.target.checked } ) }
										aria-describedby={ id + '-status' }
									/>
									<label htmlFor={ id }>
										<strong>{ p.label }</strong>
										<span className="dxui-site-pages__path">{ p.path }</span>
									</label>
									<span id={ id + '-status' } className={ 'dxui-pill dxui-site-pages__status is-' + p.status }>{ STATUS[ p.status ] || p.status }</span>
									<span className="dxui-site-pages__links">
										{ p.view && <a href={ p.view }>{ __( 'View', 'dx-ui' ) }</a> }
										{ p.edit && <a href={ p.edit }>{ __( 'Edit', 'dx-ui' ) }</a> }
									</span>
								</li>
							);
						} ) }
					</ul>
					{ pages.some( ( p ) => p.status !== 'new' && chosen[ p.path ] ) && (
						<div className="dxui-site-pages__rebuild">
							<input
								type="checkbox"
								id="dxui-sp-rebuild-edited"
								checked={ rebuildEdited }
								onChange={ ( e ) => setRebuildEdited( e.target.checked ) }
							/>
							<label htmlFor="dxui-sp-rebuild-edited">
								<strong>{ __( 'Also rebuild the pages edited since their import', 'dx-ui' ) }</strong>
								<span>{ __( 'Off: an edited page is kept exactly as it is. On: it is built again from the old site, and the edits made to it are replaced — the edited version stays in the page’s revisions when revisions are on.', 'dx-ui' ) }</span>
							</label>
						</div>
					) }
				</fieldset>
			) }

			{ listing && pages.length > 0 && (
				<div className="dxui-actions">
					<Button variant="primary" onClick={ start } disabled={ running || paths.length === 0 } aria-describedby="dxui-site-pages-progress">
						{ running
							? __( 'Importing…', 'dx-ui' )
							: sprintf(
								/* translators: %d: number of pages */
								_n( 'Import %d page', 'Import %d pages', paths.length, 'dx-ui' ),
								paths.length
							) }
					</Button>
				</div>
			) }

			<div id="dxui-site-pages-progress" aria-live="polite">
				{ running && (
					<div className="dxui-site-pages__progress">
						<div className="dxui-site-pages__bar" role="progressbar" aria-valuemin={ 0 } aria-valuemax={ 100 } aria-valuenow={ pct } aria-label={ __( 'Pages done', 'dx-ui' ) }>
							<span style={ { width: pct + '%' } } />
						</div>
						<p>
							{ progress.finishing
								? __( 'Pointing the menus and the footer at the new pages…', 'dx-ui' )
								: sprintf(
									/* translators: 1: page number, 2: pages in total, 3: page name, 4: what happens now */
									__( 'Page %1$d of %2$d · %3$s · %4$s', 'dx-ui' ),
									Math.min( ( progress.index || 0 ) + 1, progress.total || 1 ),
									progress.total || paths.length,
									progress.label || '…',
									PHASE[ progress.phase ] || ''
								) }
						</p>
					</div>
				) }
				{ result && (
					<Notice status={ ( result.crawl_errors || [] ).length || ( result.pages_skipped || [] ).length ? 'warning' : 'success' } isDismissible={ false }>
						<p>
							{ sprintf(
								/* translators: 1: pages built, 2: menu items updated */
								__( '%1$d pages built · %2$d menu links now open them.', 'dx-ui' ),
								( result.pages_created || [] ).length,
								result.menus_updated || 0
							) }
						</p>
						{ ( result.crawl_errors || [] ).length > 0 && (
							<ul>
								{ result.crawl_errors.map( ( e, i ) => (
									<li key={ i }>{ ( e.label || e.url ) + ': ' + e.message }</li>
								) ) }
							</ul>
						) }
						{ ( result.pages_skipped || [] ).length > 0 && (
							<>
								<p>
									{ sprintf(
										/* translators: %d: pages kept as they are */
										_n( '%d page was kept as it is:', '%d pages were kept as they are:', result.pages_skipped.length, 'dx-ui' ),
										result.pages_skipped.length
									) }
								</p>
								<ul>
									{ result.pages_skipped.map( ( s, i ) => (
										<li key={ i }>
											{ ( s.label || s.path ) + ' — ' + ( s.reason === 'edited' ? __( 'edited since its import', 'dx-ui' ) : s.reason === 'home' ? __( 'it is the Home', 'dx-ui' ) : ( s.reason || '' ) ) }
										</li>
									) ) }
								</ul>
								{ result.pages_skipped.some( ( s ) => s.reason === 'edited' ) && (
									<p>{ __( 'To build them again anyway, tick “Also rebuild the pages edited since their import” and import them again.', 'dx-ui' ) }</p>
								) }
							</>
						) }
					</Notice>
				) }
			</div>
		</section>
	);
}

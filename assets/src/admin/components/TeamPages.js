import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, CheckboxControl, Notice, SelectControl, Spinner, TextControl, TextareaControl, ToggleControl } from '@wordpress/components';
import './team-pages.css';

/*
 * "Pages in the team's style": the pages of a site (a service, a place, About, Contact, the questions, the reviews),
 * made the way the team makes theirs, in the look of the design's Home. The order of each page's sections is worked
 * out from what the team's eleven live sites do, and each section is the Home's own of that kind. The words are the
 * Home's until they are written for the page (Words for the pages).
 */
const base = '/dxai-ui/v1/team-pages';

const ROLE = {
	hero: __( 'Hero', 'dxai-ui' ),
	trust: __( 'Trust', 'dxai-ui' ),
	process: __( 'Process', 'dxai-ui' ),
	'two-col': __( 'Text + picture', 'dxai-ui' ),
	cards: __( 'Cards', 'dxai-ui' ),
	reviews: __( 'Reviews', 'dxai-ui' ),
	related: __( 'Related', 'dxai-ui' ),
	contact: __( 'Contact details', 'dxai-ui' ),
	areas: __( 'Areas', 'dxai-ui' ),
	form: __( 'Form', 'dxai-ui' ),
	faq: __( 'FAQ', 'dxai-ui' ),
	cta: __( 'Call to action', 'dxai-ui' ),
	content: __( 'Text', 'dxai-ui' ),
	text: __( 'Text', 'dxai-ui' ),
};

const lines = ( text ) => text.split( '\n' ).map( ( l ) => l.trim() ).filter( Boolean );

export default function TeamPages() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ services, setServices ] = useState( '' );
	const [ places, setPlaces ] = useState( '' );
	const [ phrase, setPhrase ] = useState( '' );
	const [ general, setGeneral ] = useState( {} );
	const [ plan, setPlan ] = useState( null );
	const [ built, setBuilt ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	// The AI plan of the sections: off until asked for; its cost is told before anything is sent.
	const [ ai, setAi ] = useState( false );
	const [ estimate, setEstimate ] = useState( null );
	const [ priceIn, setPriceIn ] = useState( '' );
	const [ priceOut, setPriceOut ] = useState( '' );

	function load( design = 0 ) {
		setPlan( null );
		setBuilt( null );
		apiFetch( { path: base + ( design ? '?design=' + design : '' ) } )
			.then( ( res ) => {
				setData( res );
				setEstimate( null );
				if ( res.ai && res.ai.prices ) {
					setPriceIn( String( res.ai.prices.in ) );
					setPriceOut( String( res.ai.prices.out ) );
				}
				const s = res.suggestions;
				if ( s ) {
					setServices( s.services.join( '\n' ) );
					setPlaces( s.locations.join( '\n' ) );
					setPhrase( s.phrase );
					const on = {};
					s.general.forEach( ( g ) => {
						on[ g.type ] = true;
					} );
					setGeneral( on );
				}
			} )
			.catch( ( e ) => setError( e.message || __( 'The design could not be read.', 'dxai-ui' ) ) );
	}

	useEffect( () => {
		load();
	}, [] );

	if ( error ) {
		return <Notice status="error" isDismissible={ false }>{ error }</Notice>;
	}
	if ( ! data ) {
		return <Spinner />;
	}
	if ( ! data.designs.length || ! data.suggestions ) {
		return null;
	}

	function wanted() {
		const out = [];
		lines( services ).forEach( ( title ) => out.push( { type: 'service', title } ) );
		lines( places ).forEach( ( place ) => out.push( { type: 'location', title: ( phrase.trim() ? phrase.trim() + ' in ' : '' ) + place } ) );
		data.suggestions.general.forEach( ( g ) => {
			if ( general[ g.type ] ) {
				out.push( { type: g.type, title: g.title } );
			}
		} );

		return out;
	}

	function send( action ) {
		setBusy( action );
		setError( '' );
		const body = { action, design: data.design, wanted: wanted() };
		if ( action === 'build' ) {
			body.ai = ai;
		}
		if ( action === 'estimate' ) {
			body.price_in = parseFloat( priceIn ) || 0;
			body.price_out = parseFloat( priceOut ) || 0;
		}
		apiFetch( { path: base, method: 'POST', data: body } )
			.then( ( res ) => {
				if ( action === 'estimate' ) {
					setEstimate( res.estimate );
				} else if ( action === 'plan' ) {
					setPlan( res.plan );
					setBuilt( null );
				} else {
					setBuilt( res );
					setData( ( d ) => ( { ...d, existing: res.existing } ) );
					setPlan( null );
				}
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( '' ) );
	}

	const total = wanted().length;

	return (
		<section className="dxai-section dxai-team" aria-labelledby="dxai-team-title">
			<div className="dxai-section-head">
				<div>
					<p className="dxai-section-kicker">{ __( 'Your pages', 'dxai-ui' ) }</p>
					<h3 id="dxai-team-title">{ __( 'Pages in the team\'s style', 'dxai-ui' ) }</h3>
					<p className="dxai-muted">
						{ __( 'New pages made the way the team makes theirs, in the look of the Home: the sections and their order come from what the team\'s eleven sites do, and every section is one of the Home\'s. The words are the Home\'s until they are written for the page.', 'dxai-ui' ) }
					</p>
				</div>
			</div>

			{ data.designs.length > 1 && (
				<SelectControl
					label={ __( 'Design', 'dxai-ui' ) }
					value={ String( data.design ) }
					options={ data.designs.map( ( d ) => ( { value: String( d.id ), label: d.title } ) ) }
					onChange={ ( v ) => load( Number( v ) ) }
					__nextHasNoMarginBottom
				/>
			) }

			<div className="dxai-team__fields">
				<TextareaControl
					label={ __( 'Services (one to a line)', 'dxai-ui' ) }
					help={ data.suggestions.services.length ? __( 'Read from the Home; change them as you like.', 'dxai-ui' ) : __( 'The Home does not list services; type them here.', 'dxai-ui' ) }
					value={ services }
					onChange={ setServices }
					rows={ 6 }
					__nextHasNoMarginBottom
				/>
				<div>
					<TextareaControl
						label={ __( 'Places (one to a line)', 'dxai-ui' ) }
						help={ data.suggestions.locations.length ? __( 'Read from the Home; add the others, as "City, ST".', 'dxai-ui' ) : __( 'As "City, ST".', 'dxai-ui' ) }
						value={ places }
						onChange={ setPlaces }
						rows={ 4 }
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'A page for a place is titled "… in City, ST" after', 'dxai-ui' ) }
						value={ phrase }
						onChange={ setPhrase }
						__nextHasNoMarginBottom
					/>
				</div>
			</div>

			<fieldset className="dxai-team__general">
				<legend>{ __( 'General pages', 'dxai-ui' ) }</legend>
				{ data.suggestions.general.map( ( g ) => (
					<CheckboxControl
						key={ g.type }
						label={ g.title }
						checked={ !! general[ g.type ] }
						onChange={ ( on ) => setGeneral( { ...general, [ g.type ]: on } ) }
						__nextHasNoMarginBottom
					/>
				) ) }
			</fieldset>

			{ data.existing.length > 0 && (
				<p className="dxai-muted">
					{ sprintf(
						/* translators: %d: number of pages. */
						_n( '%d page was made here before. Making it again updates it in place, unless someone edited it.', '%d pages were made here before. Making them again updates them in place, unless someone edited them.', data.existing.length, 'dxai-ui' ),
						data.existing.length
					) }
				</p>
			) }

			<fieldset className="dxai-team__ai">
				<legend>{ __( 'AI (optional)', 'dxai-ui' ) }</legend>
				<ToggleControl
					label={ __( 'Let an AI choose the sections of each page', 'dxai-ui' ) }
					help={ __( 'Off by default. It is one request for each page, to the engine chosen in Settings, and only when you press "Make". The AI picks among the Home\'s own sections and the sections the site makes; it writes no words, and a plan that breaks a rule is dropped and the usual plan is used. The words stay the Home\'s until you write them (Words for the pages).', 'dxai-ui' ) }
					checked={ ai }
					onChange={ ( on ) => {
						setAi( on );
						setEstimate( null );
					} }
					__nextHasNoMarginBottom
				/>
				{ ai && (
					<div className="dxai-team__cost">
						<div className="dxai-team__prices">
							<TextControl
								label={ __( 'Price of a million tokens in (US$)', 'dxai-ui' ) }
								type="number"
								min="0"
								step="0.01"
								value={ priceIn }
								onChange={ setPriceIn }
								__nextHasNoMarginBottom
							/>
							<TextControl
								label={ __( 'Price of a million tokens out (US$)', 'dxai-ui' ) }
								type="number"
								min="0"
								step="0.01"
								value={ priceOut }
								onChange={ setPriceOut }
								__nextHasNoMarginBottom
							/>
						</div>
						<p className="dxai-muted">{ __( 'Type the prices as your provider lists them to see the cost in dollars; without them it is told in tokens. Nothing is sent to find this out.', 'dxai-ui' ) }</p>
						<Button variant="secondary" onClick={ () => send( 'estimate' ) } isBusy={ busy === 'estimate' } disabled={ !! busy || total === 0 }>
							{ __( 'Show what it would cost', 'dxai-ui' ) }
						</Button>
						{ estimate && (
							<Notice status={ estimate.within ? 'info' : 'warning' } isDismissible={ false }>
								{ sprintf(
									/* translators: 1: requests, 2: tokens in, 3: tokens out. */
									_n( '%1$d request, about %2$s tokens in and %3$s out.', '%1$d requests, about %2$s tokens in and %3$s out.', estimate.requests, 'dxai-ui' ),
									estimate.requests,
									estimate.input.toLocaleString(),
									estimate.output.toLocaleString()
								) }
								{ estimate.cost !== null && ' ' + sprintf( /* translators: %s: dollars. */ __( 'About US$%s.', 'dxai-ui' ), estimate.cost.toFixed( 2 ) ) }
								{ ' ' + sprintf( /* translators: %s: tokens. */ __( 'The ceiling for one run is %s tokens.', 'dxai-ui' ), estimate.cap.toLocaleString() ) }
								{ ! estimate.within && ' ' + __( 'This is over the ceiling: the pages after it is reached are made the usual way.', 'dxai-ui' ) }
							</Notice>
						) }
					</div>
				) }
			</fieldset>

			<div className="dxai-actions">
				<Button variant="secondary" onClick={ () => send( 'plan' ) } isBusy={ busy === 'plan' } disabled={ !! busy || total === 0 }>
					{ __( 'Show the plan', 'dxai-ui' ) }
				</Button>
				<Button variant="primary" onClick={ () => send( 'build' ) } isBusy={ busy === 'build' } disabled={ !! busy || total === 0 }>
					{ busy === 'build' ? __( 'Making the pages…', 'dxai-ui' ) : sprintf( /* translators: %d: number of pages. */ _n( 'Make %d page', 'Make %d pages', total, 'dxai-ui' ), total ) }
				</Button>
			</div>

			{ plan && (
				<ul className="dxai-team__plan">
					{ plan.map( ( p ) => (
						<li key={ p.type + p.slug }>
							<div className="dxai-team__head">
								<strong>{ p.title }</strong>
								<span className="dxai-copy__kind">{ data.kinds[ p.type ] }</span>
								{ p.exists > 0 && <span className="dxai-muted">{ p.edited ? __( 'made before, edited since: kept', 'dxai-ui' ) : __( 'made before: updated in place', 'dxai-ui' ) }</span> }
							</div>
							<ol className="dxai-team__roles">
								{ p.roles.map( ( r, i ) => (
									<li key={ i } className={ 'dxai-team__role dxai-team__role--' + r.role + ( r.source === '' ? ' is-missing' : '' ) } title={ r.source === '' ? __( 'The Home has no section like this, so it is left out.', 'dxai-ui' ) : r.source === 'new' ? __( 'Made in the Home\'s own cards, from what the site and the Home say.', 'dxai-ui' ) : '' }>
										{ ROLE[ r.role ] || r.role }
									</li>
								) ) }
							</ol>
							{ p.nearest > 0 && (
								<p className="dxai-muted">
									{ sprintf(
										/* translators: %d: number of sections. */
										_n( 'Differs from the nearest of the team\'s real pages by %d section.', 'Differs from the nearest of the team\'s real pages by %d sections.', p.nearest, 'dxai-ui' ),
										p.nearest
									) }
								</p>
							) }
						</li>
					) ) }
				</ul>
			) }

			{ built && (
				<div className="dxai-team__built">
					<Notice status="success" isDismissible={ false }>
						{ sprintf(
							/* translators: %d: number of pages. */
							_n( '%d page is made. Now write its words (Words for the pages, below).', '%d pages are made. Now write their words (Words for the pages, below).', built.pages.length, 'dxai-ui' ),
							built.pages.length
						) }
					</Notice>
					{ built.ai && built.ai.asked && (
						<Notice status={ built.ai.rejected + built.ai.skipped > 0 ? 'warning' : 'info' } isDismissible={ false }>
							{ sprintf(
								/* translators: 1: pages planned by the AI, 2: pages made the usual way. */
								__( 'The AI planned %1$d pages; %2$d were made the usual way (its plan broke a rule, it did not answer, or the ceiling was reached).', 'dxai-ui' ),
								built.ai.accepted,
								built.ai.rejected + built.ai.skipped
							) }
							{ built.ai.spent && built.ai.spent.requests > 0 && ' ' + sprintf( /* translators: 1: requests, 2: tokens. */ __( 'It spent %1$d requests and about %2$s tokens.', 'dxai-ui' ), built.ai.spent.requests, built.ai.spent.tokens.toLocaleString() ) }
						</Notice>
					) }
					{ built.kept.length > 0 && (
						<Notice status="warning" isDismissible={ false }>
							{ sprintf(
								/* translators: %s: page titles. */
								__( 'Kept as they are, because someone edited them: %s', 'dxai-ui' ),
								built.kept.map( ( k ) => k.title ).join( ', ' )
							) }
						</Notice>
					) }
					<ul className="dxai-team__links">
						{ built.pages.map( ( p ) => (
							<li key={ p.id }>
								<strong>{ p.title }</strong>
								<span className="dxai-muted">{ sprintf( /* translators: %d: number of sections. */ _n( '%d section', '%d sections', p.sections, 'dxai-ui' ), p.sections ) }</span>
								{ p.planned === 'ai' && <span className="dxai-copy__kind">{ __( 'planned by the AI', 'dxai-ui' ) }</span> }
								<Button variant="link" href={ p.edit }>{ __( 'Edit', 'dxai-ui' ) }</Button>
								<Button variant="link" href={ p.view }>{ __( 'View', 'dxai-ui' ) }</Button>
							</li>
						) ) }
					</ul>
				</div>
			) }
		</section>
	);
}

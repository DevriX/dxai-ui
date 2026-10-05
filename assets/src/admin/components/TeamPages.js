import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, CheckboxControl, Notice, RangeControl, SelectControl, Spinner, TextControl, TextareaControl } from '@wordpress/components';
import './team-pages.css';

/*
 * "Pages in the team's style": the pages of a site (a service, a place, About, Contact, the questions, the reviews),
 * made the way the team makes theirs, in the look of the design's Home. The order of each page's sections is worked
 * out from what the team's eleven live sites do, and each section is the Home's own of that kind (or the Home's cards
 * with the pages of the site poured in). The plan is shown as a strip of sections before anything is made — what is
 * shown is what is made — and a page can be shuffled (another page of the same site) with the sections you like locked.
 * The words are the Home's until they are written for the page (Words for the pages).
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

// How the sections are chosen: the three stops of the slider.
const MODES = [ 'home', 'new', 'ai' ];
const MODE_LABEL = {
	home: __( 'Only the Home', 'dxai-ui' ),
	new: __( 'Home + new', 'dxai-ui' ),
	ai: __( 'With AI', 'dxai-ui' ),
};
const MODE_HELP = {
	home: __( 'Every section is one of the Home\'s, as it is. Nothing is made: a page that lists other pages shows the Home\'s own cards.', 'dxai-ui' ),
	new: __( 'The Home\'s sections, and the sections the site makes in the Home\'s own cards: the list of the site\'s pages, the ways to reach the company.', 'dxai-ui' ),
	ai: __( 'An AI chooses the sections of each page, among the Home\'s own and the ones the site makes. One request for each page, when you press "Make"; the plan shown is the usual one it starts from. It writes no words.', 'dxai-ui' ),
};

// What was done to a section, in words (the plan says it in short names).
const VARIANT = [
	[ /^image:/, __( 'another picture', 'dxai-ui' ) ],
	[ /^order:/, __( 'cards in another order', 'dxai-ui' ) ],
	[ /^flip$/, __( 'sides swapped', 'dxai-ui' ) ],
	[ /^drop:/, __( 'one thing less', 'dxai-ui' ) ],
	[ /^faq:/, __( 'fewer questions', 'dxai-ui' ) ],
	[ /^lead:/, __( 'opens with the Home\'s words for it', 'dxai-ui' ) ],
];
const variants = ( ops ) => {
	const out = [];
	ops.forEach( ( op ) => {
		op.split( '+' ).forEach( ( part ) => {
			const hit = VARIANT.find( ( v ) => v[ 0 ].test( part ) );
			if ( hit && ! out.includes( hit[ 1 ] ) ) {
				out.push( hit[ 1 ] );
			}
		} );
	} );

	return out.join( ', ' );
};

// What the report says about each gate.
const GATE = {
	G1: __( 'Header and footer are the Home\'s', 'dxai-ui' ),
	G7: __( 'Blocks are valid', 'dxai-ui' ),
	G8: __( 'Not a copy of another page (sections)', 'dxai-ui' ),
	G8W: __( 'Not a copy of the Home (words)', 'dxai-ui' ),
	G9: __( 'Nothing foreign (phones, e-mails, other sites)', 'dxai-ui' ),
};

const lines = ( text ) => text.split( '\n' ).map( ( l ) => l.trim() ).filter( Boolean );
const keyOf = ( type, title ) => type + '|' + title;

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
	// How the sections are chosen; the AI's cost is told before anything is sent.
	const [ mode, setMode ] = useState( 'new' );
	const [ estimate, setEstimate ] = useState( null );
	const [ priceIn, setPriceIn ] = useState( '' );
	const [ priceOut, setPriceOut ] = useState( '' );
	const [ gave, setGave ] = useState( null );
	const [ confirm, setConfirm ] = useState( false );

	function load( design = 0 ) {
		setPlan( null );
		setBuilt( null );
		setGave( null );
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

	// What was shuffled and locked on each page is in the plan, as the server returned it (the locks that held, the shuffle):
	// that is what is sent back. A page that is not in a plan is arranged as it was last made.
	function wanted( override = {} ) {
		const kept = {};
		( plan || [] ).forEach( ( p ) => {
			kept[ keyOf( p.type, p.title ) ] = p.arrangement;
		} );
		const arrangement = { ...kept, ...override };
		const out = [];
		const add = ( type, title ) => out.push( { type, title, ...( arrangement[ keyOf( type, title ) ] || {} ) } );
		lines( services ).forEach( ( title ) => add( 'service', title ) );
		lines( places ).forEach( ( place ) => add( 'location', ( phrase.trim() ? phrase.trim() + ' in ' : '' ) + place ) );
		data.suggestions.general.forEach( ( g ) => {
			if ( general[ g.type ] ) {
				add( g.type, g.title );
			}
		} );

		return out;
	}

	function takePlan( rows ) {
		setPlan( rows );
	}

	function send( action, extra = {} ) {
		setBusy( action );
		setError( '' );
		const body = { action, design: data.design, wanted: wanted( extra.arr ), mode };
		if ( action === 'estimate' ) {
			body.price_in = parseFloat( priceIn ) || 0;
			body.price_out = parseFloat( priceOut ) || 0;
		}
		if ( extra.page ) {
			body.page = extra.page;
		}
		if ( action === 'put_back' ) {
			delete body.wanted;
		}
		return apiFetch( { path: base, method: 'POST', data: body } )
			.then( ( res ) => {
				if ( action === 'estimate' ) {
					setEstimate( res.estimate );
				} else if ( action === 'plan' ) {
					takePlan( res.plan );
					setBuilt( null );
				} else if ( action === 'build' ) {
					setBuilt( res );
					setGave( null );
					setConfirm( false );
					setData( ( d ) => ( { ...d, existing: res.existing, run: res.run } ) );
				} else {
					// Giving a run, or a page of it, back.
					setGave( { ...res, all: action === 'undo' } );
					setBuilt( null );
					setConfirm( false );
					setData( ( d ) => ( { ...d, existing: res.existing, run: res.run } ) );
				}
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( '' ) );
	}

	// A page made, then shown again as it is arranged now (so it can be shuffled again).
	function build() {
		send( 'build' ).then( () => {
			apiFetch( { path: base, method: 'POST', data: { action: 'plan', design: data.design, wanted: wanted(), mode } } )
				.then( ( res ) => takePlan( res.plan ) )
				.catch( () => {} );
		} );
	}

	function pickMode( index ) {
		const next = MODES[ index ];
		setMode( next );
		setEstimate( null );
		if ( plan ) {
			setBusy( 'plan' );
			apiFetch( { path: base, method: 'POST', data: { action: 'plan', design: data.design, wanted: wanted(), mode: next } } )
				.then( ( res ) => takePlan( res.plan ) )
				.catch( ( e ) => setError( e.message ) )
				.finally( () => setBusy( '' ) );
		}
	}

	// Another page of the same site: a new shuffle. With a section locked the order of the page is held and the others change.
	function shuffle( p, reset = false ) {
		const a = p.arrangement;
		const next = reset
			? { shuffle: 0, recipe: 0, locks: [] }
			: { shuffle: a.shuffle + 1, recipe: a.locks.length ? a.recipe : a.shuffle + 1, locks: a.locks };
		send( 'plan', { arr: { [ keyOf( p.type, p.title ) ]: next } } );
	}

	function toggleLock( p, x ) {
		// The page does not change by locking what it shows: only the mark does.
		setPlan( ( rows ) =>
			rows.map( ( q ) => {
				if ( q.type !== p.type || q.slug !== p.slug ) {
					return q;
				}
				const held = q.arrangement.locks.some( ( l ) => l.place === x.place );
				const locks = held
					? q.arrangement.locks.filter( ( l ) => l.place !== x.place )
					: [ ...q.arrangement.locks, { place: x.place, role: x.role, source: x.source, home: x.home, n: x.n, ops: x.ops } ].sort( ( l, m ) => l.place - m.place );

				return { ...q, arrangement: { ...q.arrangement, locks }, strip: q.strip.map( ( s ) => ( s.place === x.place ? { ...s, locked: ! held } : s ) ) };
			} )
		);
	}

	const total = wanted().length;
	const run = data.run && data.run.pages && data.run.pages.length ? data.run : null;
	const arranged = ( p ) => p.arrangement && ( p.arrangement.shuffle > 0 || p.arrangement.locks.length > 0 );

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
				<legend>{ __( 'Where the sections come from', 'dxai-ui' ) }</legend>
				<RangeControl
					className="dxai-team__mode"
					label={ __( 'How much is the Home\'s alone', 'dxai-ui' ) }
					hideLabelFromVision
					value={ MODES.indexOf( mode ) }
					onChange={ ( v ) => pickMode( Number( v ) ) }
					min={ 0 }
					max={ 2 }
					step={ 1 }
					marks={ MODES.map( ( m, i ) => ( { value: i, label: MODE_LABEL[ m ] } ) ) }
					withInputField={ false }
					showTooltip={ false }
					__nextHasNoMarginBottom
				/>
				<p className="dxai-muted">{ MODE_HELP[ mode ] }</p>
				{ mode === 'ai' && (
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
						<p className="dxai-muted">{ __( 'Type the prices as your provider lists them to see the cost in dollars; without them it is told in tokens. Nothing is sent to find this out. The engine is the one chosen in Settings; a plan that breaks a rule is dropped and the usual plan is used.', 'dxai-ui' ) }</p>
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
				<Button variant="primary" onClick={ build } isBusy={ busy === 'build' } disabled={ !! busy || total === 0 }>
					{ busy === 'build' ? __( 'Making the pages…', 'dxai-ui' ) : sprintf( /* translators: %d: number of pages. */ _n( 'Make %d page', 'Make %d pages', total, 'dxai-ui' ), total ) }
				</Button>
			</div>

			{ plan && (
				<>
					<p className="dxai-muted dxai-team__legend">
						{ mode === 'ai'
							? __( 'This is the usual plan the AI starts from; it chooses the sections when you press "Make".', 'dxai-ui' )
							: __( 'What is shown is what is made. Shuffle a page to see another page of the same site; lock a section you like and it stays through the next shuffles.', 'dxai-ui' ) }
					</p>
					<ul className="dxai-team__plan">
						{ plan.map( ( p ) => (
							<li key={ p.type + p.slug }>
								<div className="dxai-team__head">
									<strong>{ p.title }</strong>
									<span className="dxai-copy__kind">{ data.kinds[ p.type ] }</span>
									{ p.exists > 0 && <span className="dxai-muted">{ p.edited ? __( 'made before, edited since: kept', 'dxai-ui' ) : __( 'made before: updated in place', 'dxai-ui' ) }</span> }
									{ mode !== 'ai' && (
										<span className="dxai-team__tools">
											<Button variant="secondary" size="small" onClick={ () => shuffle( p ) } disabled={ !! busy }>
												{ __( 'Shuffle', 'dxai-ui' ) }
											</Button>
											{ arranged( p ) && (
												<Button variant="tertiary" size="small" onClick={ () => shuffle( p, true ) } disabled={ !! busy }>
													{ __( 'Back to the usual', 'dxai-ui' ) }
												</Button>
											) }
										</span>
									) }
								</div>
								<ol className="dxai-team__strip">
									{ p.strip.map( ( x ) => (
										<li
											key={ x.place }
											className={ 'dxai-team__sec dxai-team__role--' + x.role + ( x.source === '' ? ' is-missing' : '' ) + ( x.locked ? ' is-locked' : '' ) }
											title={ x.source === '' ? __( 'The Home has no section like this, so it is left out.', 'dxai-ui' ) : '' }
										>
											<span className="dxai-team__sec-role">{ ROLE[ x.role ] || x.role }</span>
											<span className="dxai-team__sec-from">
												{ x.source === 'home' && sprintf( /* translators: %d: the number of the Home's section. */ __( 'Home, section %d', 'dxai-ui' ), x.home ) }
												{ x.source === 'new' && sprintf( /* translators: %d: the number of the Home's section whose cards are used. */ __( 'New, in the cards of section %d', 'dxai-ui' ), x.home ) }
												{ x.source === '' && __( 'left out', 'dxai-ui' ) }
											</span>
											{ x.ops.length > 0 && <span className="dxai-team__sec-var">{ variants( x.ops ) }</span> }
											{ x.source !== '' && mode !== 'ai' && (
												<button
													type="button"
													className="dxai-team__lock"
													aria-pressed={ !! x.locked }
													onClick={ () => toggleLock( p, x ) }
												>
													{ x.locked ? __( 'Locked', 'dxai-ui' ) : __( 'Lock', 'dxai-ui' ) }
												</button>
											) }
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
				</>
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

					{ built.report && built.report.length > 0 && (
						<div className="dxai-team__report">
							<h4>{ __( 'How the pages measure up', 'dxai-ui' ) }</h4>
							<ul className="dxai-team__reports">
								{ built.report.map( ( r ) => (
									<li key={ r.id } className={ r.ok ? 'is-ok' : 'is-bad' }>
										<strong>{ r.title }</strong>
										<ul className="dxai-team__gates">
											{ r.gates.map( ( g ) => (
												<li key={ g.gate } className={ g.ok ? 'is-ok' : 'is-bad' } title={ g.problems.join( '\n' ) }>
													<span className="dxai-team__gate-mark" aria-hidden="true">{ g.ok ? '✓' : '✕' }</span>
													<span className="screen-reader-text">{ g.ok ? __( 'Passes:', 'dxai-ui' ) : __( 'Fails:', 'dxai-ui' ) }</span>
													{ GATE[ g.gate ] || g.label }
													{ ! g.ok && (
														<span className="dxai-team__gate-why">
															{ g.gate === 'G8W' ? __( 'The words are still the Home\'s: write them in "Words for the pages".', 'dxai-ui' ) : g.problems[ 0 ] }
														</span>
													) }
												</li>
											) ) }
										</ul>
									</li>
								) ) }
							</ul>
							<p className="dxai-muted">
								{ __( 'Colours, fonts, spacing, how the sections look next to the Home\'s, mobile and speed need a browser to be measured, so they are not shown here: the sections are the Home\'s own, and the plugin\'s quality tool (bin/team-quality.cjs) measures them on a copy of the site.', 'dxai-ui' ) }
							</p>
						</div>
					) }
				</div>
			) }

			{ gave && (
				<Notice status={ gave.skipped.length ? 'warning' : 'success' } isDismissible={ false }>
					{ gave.all
						? sprintf(
							/* translators: 1: pages put back, 2: pages moved to the trash. */
							__( 'The run is given back: %1$d pages put back as they were, %2$d moved to the trash (nothing is deleted).', 'dxai-ui' ),
							gave.restored.length,
							gave.removed.length
						)
						: __( 'The page is put back as it was.', 'dxai-ui' ) }
					{ gave.skipped.length > 0 && ' ' + sprintf(
						/* translators: %s: page titles. */
						__( 'Left as they are, because they were changed since: %s.', 'dxai-ui' ),
						gave.skipped.map( ( s ) => s.title ).join( ', ' )
					) }
				</Notice>
			) }

			{ run && (
				<div className="dxai-team__run">
					<h4>{ __( 'The last run', 'dxai-ui' ) }</h4>
					<p className="dxai-muted">
						{ sprintf(
							/* translators: 1: date and time, 2: pages made, 3: pages changed. */
							__( '%1$s — %2$d pages made, %3$d changed. Giving it back puts the pages that were there back as they were, moves the pages it made to the trash, and returns the Home\'s menu links.', 'dxai-ui' ),
							new Date( run.at * 1000 ).toLocaleString(),
							run.pages.filter( ( p ) => p.created ).length,
							run.pages.filter( ( p ) => ! p.created ).length
						) }
					</p>
					<ul className="dxai-team__links">
						{ run.pages.map( ( p ) => (
							<li key={ p.id }>
								<strong>{ p.title }</strong>
								<span className="dxai-copy__kind">{ p.created ? __( 'made new', 'dxai-ui' ) : __( 'changed', 'dxai-ui' ) }</span>
								{ p.changed && <span className="dxai-muted">{ __( 'changed since: it stays as it is', 'dxai-ui' ) }</span> }
								{ ! p.created && ! p.changed && (
									<Button variant="link" onClick={ () => send( 'put_back', { page: p.id } ) } disabled={ !! busy }>
										{ __( 'Put this page back', 'dxai-ui' ) }
									</Button>
								) }
							</li>
						) ) }
					</ul>
					<div className="dxai-actions">
						{ confirm ? (
							<>
								<Button variant="primary" isDestructive onClick={ () => send( 'undo' ) } isBusy={ busy === 'undo' } disabled={ !! busy }>
									{ __( 'Yes, give the run back', 'dxai-ui' ) }
								</Button>
								<Button variant="tertiary" onClick={ () => setConfirm( false ) }>
									{ __( 'No', 'dxai-ui' ) }
								</Button>
							</>
						) : (
							<Button variant="secondary" isDestructive onClick={ () => setConfirm( true ) } disabled={ !! busy }>
								{ __( 'Give the whole run back', 'dxai-ui' ) }
							</Button>
						) }
					</div>
				</div>
			) }
		</section>
	);
}

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
const base = '/dx-ui/v1/team-pages';

const ROLE = {
	hero: __( 'Hero', 'dx-ui' ),
	trust: __( 'Trust', 'dx-ui' ),
	process: __( 'Process', 'dx-ui' ),
	'two-col': __( 'Text + picture', 'dx-ui' ),
	cards: __( 'Cards', 'dx-ui' ),
	reviews: __( 'Reviews', 'dx-ui' ),
	related: __( 'Related', 'dx-ui' ),
	contact: __( 'Contact details', 'dx-ui' ),
	areas: __( 'Areas', 'dx-ui' ),
	form: __( 'Form', 'dx-ui' ),
	faq: __( 'FAQ', 'dx-ui' ),
	cta: __( 'Call to action', 'dx-ui' ),
	content: __( 'Text', 'dx-ui' ),
	text: __( 'Text', 'dx-ui' ),
};

// How the sections are chosen: the three stops of the slider.
const MODES = [ 'home', 'new', 'ai' ];
const MODE_LABEL = {
	home: __( 'Only the Home', 'dx-ui' ),
	new: __( 'Home + new', 'dx-ui' ),
	ai: __( 'With AI', 'dx-ui' ),
};
const MODE_HELP = {
	home: __( 'Every section is one of the Home\'s, as it is. Nothing is made: a page that lists other pages shows the Home\'s own cards.', 'dx-ui' ),
	new: __( 'The Home\'s sections, and the sections the site makes in the Home\'s own cards: the list of the site\'s pages, the ways to reach the company.', 'dx-ui' ),
	ai: __( 'An AI chooses the sections of each page, among the Home\'s own and the ones the site makes. One request for each page, when you press "Make"; the plan shown is the usual one it starts from. It writes no words.', 'dx-ui' ),
};

// The team's own sections (the library): to fill what the Home has none of, to take the Home's place too, or not used.
const LIBRARIES = [ 'team', 'fill', 'prefer', 'off' ];
const LIBRARY_LABEL = {
	team: __( 'The team’s layouts: the Home’s hero, then sections arranged as the team arranges theirs', 'dx-ui' ),
	fill: __( 'Where the Home has none', 'dx-ui' ),
	prefer: __( 'Instead of the Home\'s, where it has one', 'dx-ui' ),
	off: __( 'Not used', 'dx-ui' ),
};

// Why the library is not used for a design (what the server found it needs and the site does not have).
const libraryWhy = ( lib ) => {
	switch ( lib.code ) {
		case 'theme':
			return sprintf(
				/* translators: %s: the name of the active theme. */
				__( 'This site runs %s. The team\'s own sections are made of the American Restoration theme\'s blocks and styles, so they are used only on that theme: install and activate it to use them. Nothing is lost meanwhile; the pages are made from the Home\'s sections as before.', 'dx-ui' ),
				lib.theme
			);
		case 'industry':
			return __( 'This Home does not talk like a restoration company, and the words of the team\'s own sections are a restoration company\'s, so they are not used for it.', 'dx-ui' );
		case 'blocks':
			return __( 'The blocks the team\'s own sections are made of are not registered on this site, so they are not used.', 'dx-ui' );
		default:
			return __( 'The library has no sections to offer.', 'dx-ui' );
	}
};

// What was done to a section, in words (the plan says it in short names).
const VARIANT = [
	[ /^image:/, __( 'another picture', 'dx-ui' ) ],
	[ /^order:/, __( 'cards in another order', 'dx-ui' ) ],
	[ /^flip$/, __( 'sides swapped', 'dx-ui' ) ],
	[ /^drop:/, __( 'one thing less', 'dx-ui' ) ],
	[ /^faq:/, __( 'fewer questions', 'dx-ui' ) ],
	[ /^lead:/, __( 'opens with the Home\'s words for it', 'dx-ui' ) ],
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
	G1: __( 'Header and footer are the Home\'s', 'dx-ui' ),
	G7: __( 'Blocks are valid', 'dx-ui' ),
	G8: __( 'Not a copy of another page (sections)', 'dx-ui' ),
	G8W: __( 'Not a copy of the Home (words)', 'dx-ui' ),
	G9: __( 'Nothing foreign (phones, e-mails, other sites)', 'dx-ui' ),
};

const lines = ( text ) => text.split( '\n' ).map( ( l ) => l.trim() ).filter( Boolean );
const keyOf = ( type, title ) => type + '|' + title;

// The kinds a site has one page of (Team_Pages::ONCE): a page of one at an address the menu gave is that page, so the general page of the
// kind is not asked for beside it.
const ONCE = [ 'about', 'contact', 'faq', 'testimonials', 'services', 'areas' ];

// Why an item of the menu is not a page, in words (Menu_Pages: left_out[].why).
const LEFT_OUT = {
	anchor: __( 'a place on a page, not a page', 'dx-ui' ),
	kind: __( 'a page the team has no kind for', 'dx-ui' ),
	external: __( 'another site', 'dx-ui' ),
	file: __( 'a file', 'dx-ui' ),
	parent: __( 'the page above it is not in the menu', 'dx-ui' ),
};

// The rows the menu offers, as the panel keeps them: the lists the addresses imply first, then the pages; a page is on unless its address
// is somebody else's page.
const menuRowsOf = ( menu ) =>
	menu
		? [ ...( menu.lists || [] ), ...( menu.pages || [] ) ].map( ( p ) => ( {
			key: p.path,
			on: ! ( p.exists > 0 && ! p.mine ),
			type: p.type,
			title: p.title,
			path: p.path,
			item: p.item || 0,
			implied: !! p.implied,
			exists: p.exists || 0,
			mine: !! p.mine,
			trail: p.trail || '',
		} ) )
		: [];

export default function TeamPages( { design: fixed = 0 } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ services, setServices ] = useState( '' );
	const [ places, setPlaces ] = useState( '' );
	const [ phrase, setPhrase ] = useState( '' );
	const [ general, setGeneral ] = useState( {} );
	const [ menuRows, setMenuRows ] = useState( [] );
	const [ plan, setPlan ] = useState( null );
	const [ built, setBuilt ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	// How the sections are chosen; the AI's cost is told before anything is sent.
	const [ mode, setMode ] = useState( 'new' );
	const [ library, setLibrary ] = useState( 'team' );
	const [ estimate, setEstimate ] = useState( null );
	const [ priceIn, setPriceIn ] = useState( '' );
	const [ priceOut, setPriceOut ] = useState( '' );
	const [ gave, setGave ] = useState( null );
	// Arranging a page by hand: the page a section is being added to, the section picked, the section being dragged.
	const [ adding, setAdding ] = useState( '' );
	const [ pick, setPick ] = useState( '' );
	const [ dragging, setDragging ] = useState( null );
	const [ confirm, setConfirm ] = useState( false );

	function load( design = 0 ) {
		setPlan( null );
		setBuilt( null );
		setGave( null );
		apiFetch( { path: base + ( design ? '?design=' + design : '' ) } )
			.then( ( res ) => {
				setData( res );
				setEstimate( null );
				setMenuRows( menuRowsOf( res.menu ) );
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
			.catch( ( e ) => setError( e.message || __( 'The design could not be read.', 'dx-ui' ) ) );
	}

	useEffect( () => {
		load( fixed );
	}, [ fixed ] );

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
		const seen = new Set();
		const add = ( type, title, more = {} ) => {
			// A page asked for twice (typed, and named by the menu) is one page; the menu's, with its address, comes first.
			if ( ! seen.has( keyOf( type, title ) ) ) {
				seen.add( keyOf( type, title ) );
				out.push( { type, title, ...more, ...( arrangement[ keyOf( type, title ) ] || {} ) } );
			}
		};
		// The pages the menu names, at its addresses, and the menu item each is from.
		const fromMenu = menuRows.filter( ( r ) => r.on );
		fromMenu.forEach( ( r ) => add( r.type, r.title, { path: r.path, item: r.item } ) );
		// A kind the menu names, ticked or not, is the menu's: unticking it is not asking for a general page of the kind instead, and a page at
		// an address that is somebody else's is the site's page of that kind.
		const menuKinds = menuRows.map( ( r ) => r.type );
		lines( services ).forEach( ( title ) => add( 'service', title ) );
		lines( places ).forEach( ( place ) => add( 'location', ( phrase.trim() ? phrase.trim() + ' in ' : '' ) + place ) );
		data.suggestions.general.forEach( ( g ) => {
			// A page of a kind a site has one of that the menu names is that page.
			if ( general[ g.type ] && ! ( ONCE.includes( g.type ) && menuKinds.includes( g.type ) ) ) {
				add( g.type, g.title );
			}
		} );

		return out;
	}

	function takePlan( rows ) {
		setPlan( rows );
	}

	// One row of the menu's pages changed (ticked, another kind of page).
	function setMenuRow( key, change ) {
		setMenuRows( ( rows ) => rows.map( ( r ) => ( r.key === key ? { ...r, ...change } : r ) ) );
	}

	// What is there at the menu's addresses after pages were made (the rows the person set stay as they were set).
	function refreshMenu() {
		apiFetch( { path: base + '?design=' + data.design } )
			.then( ( res ) => {
				const there = {};
				[ ...( res.menu ? res.menu.lists : [] ), ...( res.menu ? res.menu.pages : [] ) ].forEach( ( p ) => {
					there[ p.path ] = p;
				} );
				setMenuRows( ( rows ) => rows.map( ( r ) => ( there[ r.path ] ? { ...r, exists: there[ r.path ].exists || 0, mine: !! there[ r.path ].mine } : r ) ) );
			} )
			.catch( () => {} );
	}

	function send( action, extra = {} ) {
		setBusy( action );
		setError( '' );
		const body = { action, design: data.design, wanted: wanted( extra.arr ), mode, library };
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
			refreshMenu();
			apiFetch( { path: base, method: 'POST', data: { action: 'plan', design: data.design, wanted: wanted(), mode, library } } )
				.then( ( res ) => takePlan( res.plan ) )
				.catch( () => {} );
		} );
	}

	// The plan again with another choice of where the sections come from (the arrangement a person gave the pages stays).
	function replan( next ) {
		setBusy( 'plan' );
		apiFetch( { path: base, method: 'POST', data: { action: 'plan', design: data.design, wanted: wanted(), mode: next.mode ?? mode, library: next.library ?? library } } )
			.then( ( res ) => takePlan( res.plan ) )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( '' ) );
	}

	function pickLibrary( next ) {
		setLibrary( next );
		if ( plan ) {
			replan( { library: next } );
		}
	}

	function pickMode( index ) {
		const next = MODES[ index ];
		setMode( next );
		setEstimate( null );
		if ( plan ) {
			replan( { mode: next } );
		}
	}

	// Another page of the same site: a new shuffle. With a section locked the order of the page is held and the others change.
	function shuffle( p, reset = false ) {
		const a = p.arrangement;
		// (A page arranged by hand keeps its places through a shuffle: only the sections that are not locked change.)
		const next = reset
			? { shuffle: 0, recipe: 0, locks: [], roles: [] }
			: { shuffle: a.shuffle + 1, recipe: a.locks.length ? a.recipe : a.shuffle + 1, locks: a.locks, ...( a.roles && a.roles.length ? { roles: a.roles } : {} ) };
		send( 'plan', { arr: { [ keyOf( p.type, p.title ) ]: next } } );
	}

	// A page arranged by hand: its places in the order given, each holding the section it shows, so moving, taking out or adding one
	// changes nothing else (a section unlocked afterwards is one a shuffle may change). A place the Home has nothing for stays a place.
	function arrange( p, strip ) {
		const a = p.arrangement || { shuffle: 0, recipe: 0 };
		const locks = strip
			.map( ( x, i ) => ( x.source ? { place: i, role: x.role, source: x.source, home: x.home || 0, n: x.n || 0, ops: x.ops || [] } : null ) )
			.filter( Boolean );
		send( 'plan', { arr: { [ keyOf( p.type, p.title ) ]: { shuffle: a.shuffle, recipe: a.recipe, locks, roles: strip.map( ( x ) => x.role ) } } } );
	}

	// The hero opens the page: it is not moved, and nothing goes above it.
	function move( p, from, to ) {
		if ( from < 1 || to < 1 || to >= p.strip.length || from === to ) {
			return;
		}
		const strip = [ ...p.strip ];
		const [ x ] = strip.splice( from, 1 );
		strip.splice( to, 0, x );
		arrange( p, strip );
	}

	function takeOut( p, at ) {
		arrange( p, p.strip.filter( ( x, i ) => i !== at ) );
	}

	// A section picked from the Home's or from the library, put at the end of the page (before nothing: it can be moved after).
	function addTo( p, value ) {
		const [ from, id ] = [ value.slice( 0, value.indexOf( ':' ) ), value.slice( value.indexOf( ':' ) + 1 ) ];
		const c = from === 'home'
			? ( data.choices.home || [] ).find( ( h ) => String( h.home ) === id )
			: ( data.choices.library || [] ).find( ( l ) => l.id === id );
		if ( ! c ) {
			return;
		}
		const x = from === 'home'
			? { role: c.role, source: 'home', home: c.home, n: 0, ops: [] }
			: { role: c.role, source: 'library', home: 0, n: 0, ops: [ 'lib:' + c.id ] };
		setAdding( '' );
		setPick( '' );
		arrange( p, [ ...p.strip, x ] );
	}

	// What can be added to a page: the Home's sections, and the library's when the library is used for this design.
	function choiceOptions() {
		const c = data.choices || { home: [], library: [] };
		const home = ( c.home || [] ).map( ( h ) => ( {
			value: 'home:' + h.home,
			label: sprintf( /* translators: 1: role, 2: the number of the Home's section, 3: its title. */ __( 'Home · %1$s · section %2$d — %3$s', 'dx-ui' ), ROLE[ h.role ] || h.role, h.home, h.label ),
		} ) );
		const lib = library !== 'off' && data.library && data.library.code === 'ok'
			? ( c.library || [] )
				.slice()
				.sort( ( m, n ) => ( ROLE[ m.role ] || m.role ).localeCompare( ROLE[ n.role ] || n.role ) )
				.map( ( l ) => ( { value: 'lib:' + l.id, label: sprintf( /* translators: 1: role, 2: what the section is. */ __( 'Library · %1$s — %2$s', 'dx-ui' ), ROLE[ l.role ] || l.role, l.label ) } ) )
			: [];

		return [ { value: '', label: __( 'Pick a section…', 'dx-ui' ) }, ...home, ...lib ];
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
	const arranged = ( p ) => p.arrangement && ( p.arrangement.shuffle > 0 || p.arrangement.locks.length > 0 || ( p.arrangement.roles && p.arrangement.roles.length > 0 ) );

	return (
		<section className="dxui-section dxui-team" aria-labelledby="dxui-team-title">
			<div className="dxui-section-head">
				<div>
					<p className="dxui-section-kicker">{ __( 'Your pages', 'dx-ui' ) }</p>
					<h3 id="dxui-team-title">{ __( 'Pages in the team\'s style', 'dx-ui' ) }</h3>
					<p className="dxui-muted">
						{ __( 'New pages made the way the team makes theirs, in the look of the Home: the sections and their order come from what the team\'s eleven sites do, and every section is one of the Home\'s. The words are the Home\'s until they are written for the page.', 'dx-ui' ) }
					</p>
				</div>
			</div>

			{ ! fixed && data.designs.length > 1 && (
				<SelectControl
					label={ __( 'Design', 'dx-ui' ) }
					value={ String( data.design ) }
					options={ data.designs.map( ( d ) => ( { value: String( d.id ), label: d.title } ) ) }
					onChange={ ( v ) => load( Number( v ) ) }
					__nextHasNoMarginBottom
				/>
			) }

			{ menuRows.length > 0 && (
				<fieldset className="dxui-team__menu">
					<legend>{ data.menu.source === 'document' ? __( 'From the design’s header', 'dx-ui' ) : __( 'From the site’s menu', 'dx-ui' ) }</legend>
					<p className="dxui-muted">
						{ data.menu.source === 'document'
							? __( 'The header was kept as a template part, so no menu was written; the design’s own header names these pages and where they sit (read from the design’s document). They are made at the design’s own addresses. Untick what you do not want.', 'dx-ui' )
							: sprintf(
								/* translators: %s: the names of the menus the header was built into. */
								__( 'The header menu (%s, in Appearance › Menus) names these pages and where they sit. They are made at the menu’s own addresses, so the menu opens them. Untick what you do not want.', 'dx-ui' ),
								( data.menu.menus || [] ).join( ', ' )
							) }
					</p>
					{ ! data.menu.pretty && (
						<Notice status="warning" isDismissible={ false }>
							{ __( 'Settings › Permalinks is set to “Plain”, so these addresses will not open until it is set to “Post name”.', 'dx-ui' ) }
						</Notice>
					) }
					<ul className="dxui-team__menu-rows">
						{ menuRows.map( ( r ) => (
							<li key={ r.key } className={ r.on ? 'is-on' : '' }>
								<CheckboxControl label={ r.title } checked={ r.on } onChange={ ( on ) => setMenuRow( r.key, { on } ) } __nextHasNoMarginBottom />
								<SelectControl
									label={ __( 'Kind of page', 'dx-ui' ) }
									hideLabelFromVision
									value={ r.type }
									options={ Object.keys( data.kinds ).map( ( t ) => ( { value: t, label: data.kinds[ t ] } ) ) }
									onChange={ ( type ) => setMenuRow( r.key, { type } ) }
									__nextHasNoMarginBottom
								/>
								<code>{ r.path }</code>
								{ r.implied && <span className="dxui-muted">{ __( 'the page above the addresses under it', 'dx-ui' ) }</span> }
								{ r.exists > 0 && <span className="dxui-muted">{ r.mine ? __( 'made before: updated in place', 'dx-ui' ) : __( 'the address is another page: kept as it is', 'dx-ui' ) }</span> }
							</li>
						) ) }
					</ul>
					{ data.menu.left_out.length > 0 && (
						<details className="dxui-team__left-out">
							<summary>
								{ sprintf(
									/* translators: %d: number of items. */
									_n( '%d item of the menu is not a page', '%d items of the menu are not pages', data.menu.left_out.length, 'dx-ui' ),
									data.menu.left_out.length
								) }
							</summary>
							<ul>
								{ data.menu.left_out.map( ( l, i ) => (
									<li key={ i }>
										<strong>{ l.title }</strong> <code>{ l.url }</code> <span className="dxui-muted">{ LEFT_OUT[ l.why ] || l.why }</span>
									</li>
								) ) }
							</ul>
						</details>
					) }
				</fieldset>
			) }

			<div className="dxui-team__fields">
				<TextareaControl
					label={ __( 'Services (one to a line)', 'dx-ui' ) }
					help={ data.suggestions.services.length
						? ( data.suggestions.from?.services === 'document' ? __( 'Read from the design’s document (the section that names its services); change them as you like.', 'dx-ui' ) : __( 'Read from the Home; change them as you like.', 'dx-ui' ) )
						: __( 'The Home does not list services; type them here.', 'dx-ui' ) }
					value={ services }
					onChange={ setServices }
					rows={ 6 }
					__nextHasNoMarginBottom
				/>
				<div>
					<TextareaControl
						label={ __( 'Places (one to a line)', 'dx-ui' ) }
						help={ data.suggestions.locations.length ? __( 'Read from the Home; add the others, as "City, ST".', 'dx-ui' ) : __( 'As "City, ST".', 'dx-ui' ) }
						value={ places }
						onChange={ setPlaces }
						rows={ 4 }
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'A page for a place is titled "… in City, ST" after', 'dx-ui' ) }
						value={ phrase }
						onChange={ setPhrase }
						__nextHasNoMarginBottom
					/>
				</div>
			</div>

			<fieldset className="dxui-team__general">
				<legend>{ __( 'General pages', 'dx-ui' ) }</legend>
				{ data.suggestions.general.map( ( g ) => {
					const named = ONCE.includes( g.type ) && menuRows.some( ( r ) => r.type === g.type );
					return (
						<CheckboxControl
							key={ g.type }
							label={ g.title }
							checked={ named || !! general[ g.type ] }
							disabled={ named }
							help={ named ? __( 'The menu names it: see above.', 'dx-ui' ) : undefined }
							onChange={ ( on ) => setGeneral( { ...general, [ g.type ]: on } ) }
							__nextHasNoMarginBottom
						/>
					);
				} ) }
			</fieldset>

			{ data.existing.length > 0 && (
				<p className="dxui-muted">
					{ sprintf(
						/* translators: %d: number of pages. */
						_n( '%d page was made here before. Making it again updates it in place, unless someone edited it.', '%d pages were made here before. Making them again updates them in place, unless someone edited them.', data.existing.length, 'dx-ui' ),
						data.existing.length
					) }
				</p>
			) }

			<fieldset className="dxui-team__ai">
				<legend>{ __( 'Where the sections come from', 'dx-ui' ) }</legend>
				<RangeControl
					className="dxui-team__mode"
					label={ __( 'How much is the Home\'s alone', 'dx-ui' ) }
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
				<p className="dxui-muted">{ MODE_HELP[ mode ] }</p>
				{ mode !== 'home' && (
					<>
						<SelectControl
							label={ __( 'The team\'s own sections (the library)', 'dx-ui' ) }
							help={ __( 'Sections the team wrote for its own sites: the questions, the steps of the work, the call to action, the places. They are made with what the Home says (its name, its phone) and are only put on the pages made here, never on the Home.', 'dx-ui' ) }
							value={ library }
							options={ LIBRARIES.map( ( l ) => ( { value: l, label: LIBRARY_LABEL[ l ] } ) ) }
							onChange={ pickLibrary }
							disabled={ !! data.library && data.library.code !== 'ok' }
							__nextHasNoMarginBottom
						/>
						{ data.library && data.library.code !== 'ok' && (
							<Notice status="warning" isDismissible={ false }>
								{ libraryWhy( data.library ) }
							</Notice>
						) }
						{ data.library && data.library.code === 'ok' && (
							<p className="dxui-muted">
								{ sprintf(
									/* translators: 1: how many sections can be made for this Home, 2: how many the library has. */
									__( '%1$d of the library\'s %2$d sections can be made for this Home. A section that needs a fact the Home does not say (a phone, places) waits for it.', 'dx-ui' ),
									data.library.usable,
									data.library.sections
								) }
								{ ' ' }
								{ data.library.generic
									? sprintf(
										/* translators: %s: the name of the active theme. */
										__( 'This theme (%s) has no template of its own, so the sections are made in the generic library: core blocks, in the colours this theme\'s palette has for the same roles.', 'dx-ui' ),
										data.library.theme
									)
									: sprintf(
										/* translators: %s: the name of the template. */
										__( 'Template: %s.', 'dx-ui' ),
										data.library.template_label
									) }
							</p>
						) }
					</>
				) }
				{ mode === 'ai' && (
					<div className="dxui-team__cost">
						<div className="dxui-team__prices">
							<TextControl
								label={ __( 'Price of a million tokens in (US$)', 'dx-ui' ) }
								type="number"
								min="0"
								step="0.01"
								value={ priceIn }
								onChange={ setPriceIn }
								__nextHasNoMarginBottom
							/>
							<TextControl
								label={ __( 'Price of a million tokens out (US$)', 'dx-ui' ) }
								type="number"
								min="0"
								step="0.01"
								value={ priceOut }
								onChange={ setPriceOut }
								__nextHasNoMarginBottom
							/>
						</div>
						<p className="dxui-muted">{ __( 'Type the prices as your provider lists them to see the cost in dollars; without them it is told in tokens. Nothing is sent to find this out. The engine is the one chosen in Settings; a plan that breaks a rule is dropped and the usual plan is used.', 'dx-ui' ) }</p>
						<Button variant="secondary" onClick={ () => send( 'estimate' ) } isBusy={ busy === 'estimate' } disabled={ !! busy || total === 0 }>
							{ __( 'Show what it would cost', 'dx-ui' ) }
						</Button>
						{ estimate && (
							<Notice status={ estimate.within ? 'info' : 'warning' } isDismissible={ false }>
								{ sprintf(
									/* translators: 1: requests, 2: tokens in, 3: tokens out. */
									_n( '%1$d request, about %2$s tokens in and %3$s out.', '%1$d requests, about %2$s tokens in and %3$s out.', estimate.requests, 'dx-ui' ),
									estimate.requests,
									estimate.input.toLocaleString(),
									estimate.output.toLocaleString()
								) }
								{ estimate.cost !== null && ' ' + sprintf( /* translators: %s: dollars. */ __( 'About US$%s.', 'dx-ui' ), estimate.cost.toFixed( 2 ) ) }
								{ ' ' + sprintf( /* translators: %s: tokens. */ __( 'The ceiling for one run is %s tokens.', 'dx-ui' ), estimate.cap.toLocaleString() ) }
								{ ! estimate.within && ' ' + __( 'This is over the ceiling: the pages after it is reached are made the usual way.', 'dx-ui' ) }
							</Notice>
						) }
					</div>
				) }
			</fieldset>

			<div className="dxui-actions">
				<Button variant="secondary" onClick={ () => send( 'plan' ) } isBusy={ busy === 'plan' } disabled={ !! busy || total === 0 }>
					{ __( 'Show the plan', 'dx-ui' ) }
				</Button>
				<Button variant="primary" onClick={ build } isBusy={ busy === 'build' } disabled={ !! busy || total === 0 }>
					{ busy === 'build' ? __( 'Making the pages…', 'dx-ui' ) : sprintf( /* translators: %d: number of pages. */ _n( 'Make %d page', 'Make %d pages', total, 'dx-ui' ), total ) }
				</Button>
			</div>

			{ plan && (
				<>
					<p className="dxui-muted dxui-team__legend">
						{ mode === 'ai'
							? __( 'This is the usual plan the AI starts from; it chooses the sections when you press "Make".', 'dx-ui' )
							: __( 'What is shown is what is made. Shuffle a page to see another page of the same site; lock a section you like and it stays through the next shuffles. Drag a section to move it, × takes it out, and "Add a section" puts in one of the Home\'s or of the library: a page arranged by hand holds what it shows (unlock a section to let a shuffle change it).', 'dx-ui' ) }
					</p>
					<ul className="dxui-team__plan">
						{ plan.map( ( p ) => (
							<li key={ p.type + p.slug }>
								<div className="dxui-team__head">
									<strong>{ p.title }</strong>
									<span className="dxui-copy__kind">{ data.kinds[ p.type ] }</span>
									{ p.path && <code>{ p.path }</code> }
									{ p.conflict > 0 && <span className="dxui-muted">{ __( 'the address is another page: kept as it is, this page is not made', 'dx-ui' ) }</span> }
									{ p.exists > 0 && <span className="dxui-muted">{ p.edited ? __( 'made before, edited since: kept', 'dx-ui' ) : __( 'made before: updated in place', 'dx-ui' ) }</span> }
									{ mode !== 'ai' && (
										<span className="dxui-team__tools">
											<Button variant="secondary" size="small" onClick={ () => shuffle( p ) } disabled={ !! busy }>
												{ __( 'Shuffle', 'dx-ui' ) }
											</Button>
											{ arranged( p ) && (
												<Button variant="tertiary" size="small" onClick={ () => shuffle( p, true ) } disabled={ !! busy }>
													{ __( 'Back to the usual', 'dx-ui' ) }
												</Button>
											) }
										</span>
									) }
								</div>
								<ol className="dxui-team__strip">
									{ p.strip.map( ( x, i ) => (
										<li
											key={ x.place }
											className={ 'dxui-team__sec dxui-team__role--' + x.role + ( x.source === '' ? ' is-missing' : '' ) + ( x.locked ? ' is-locked' : '' ) + ( dragging && dragging.k === p.type + p.slug && dragging.from === i ? ' is-dragging' : '' ) + ( mode !== 'ai' && i > 0 ? ' is-movable' : '' ) }
											title={ x.source === '' ? __( 'The Home has no section like this, so it is left out.', 'dx-ui' ) : '' }
											draggable={ mode !== 'ai' && i > 0 && ! busy }
											onDragStart={ ( e ) => {
												setDragging( { k: p.type + p.slug, from: i } );
												e.dataTransfer.effectAllowed = 'move';
												e.dataTransfer.setData( 'text/plain', String( i ) );
											} }
											onDragOver={ ( e ) => {
												if ( dragging && dragging.k === p.type + p.slug && i > 0 ) {
													e.preventDefault();
												}
											} }
											onDrop={ ( e ) => {
												e.preventDefault();
												if ( dragging && dragging.k === p.type + p.slug ) {
													move( p, dragging.from, i );
												}
												setDragging( null );
											} }
											onDragEnd={ () => setDragging( null ) }
										>
											<span className="dxui-team__sec-role">{ ROLE[ x.role ] || x.role }</span>
											<span className="dxui-team__sec-from">
												{ x.source === 'home' && sprintf( /* translators: %d: the number of the Home's section. */ __( 'Home, section %d', 'dx-ui' ), x.home ) }
												{ x.source === 'new' && sprintf( /* translators: %d: the number of the Home's section whose cards are used. */ __( 'New, in the cards of section %d', 'dx-ui' ), x.home ) }
												{ x.source === 'library' && sprintf( /* translators: %s: what the library's section is. */ __( 'Library: %s', 'dx-ui' ), x.label ) }
												{ x.source === '' && __( 'left out', 'dx-ui' ) }
											</span>
											{ x.ops.length > 0 && <span className="dxui-team__sec-var">{ variants( x.ops ) }</span> }
											{ x.source !== '' && mode !== 'ai' && (
												<button
													type="button"
													className="dxui-team__lock"
													aria-pressed={ !! x.locked }
													onClick={ () => toggleLock( p, x ) }
												>
													{ x.locked ? __( 'Locked', 'dx-ui' ) : __( 'Lock', 'dx-ui' ) }
												</button>
											) }
											{ mode !== 'ai' && i > 0 && (
												<span className="dxui-team__sec-tools">
													<button type="button" onClick={ () => move( p, i, i - 1 ) } disabled={ !! busy || i < 2 } aria-label={ sprintf( /* translators: %s: the section's role. */ __( 'Move %s up', 'dx-ui' ), ROLE[ x.role ] || x.role ) }>↑</button>
													<button type="button" onClick={ () => move( p, i, i + 1 ) } disabled={ !! busy || i >= p.strip.length - 1 } aria-label={ sprintf( /* translators: %s: the section's role. */ __( 'Move %s down', 'dx-ui' ), ROLE[ x.role ] || x.role ) }>↓</button>
													<button type="button" className="dxui-team__sec-out" onClick={ () => takeOut( p, i ) } disabled={ !! busy } aria-label={ sprintf( /* translators: %s: the section's role. */ __( 'Take %s out of the page', 'dx-ui' ), ROLE[ x.role ] || x.role ) }>×</button>
												</span>
											) }
										</li>
									) ) }
								</ol>
								{ mode !== 'ai' && data.choices && (
									<div className="dxui-team__add">
										{ adding === p.type + p.slug ? (
											<>
												<SelectControl
													label={ __( 'Add a section', 'dx-ui' ) }
													hideLabelFromVision
													value={ pick }
													options={ choiceOptions() }
													onChange={ setPick }
													__nextHasNoMarginBottom
												/>
												<Button variant="primary" size="small" onClick={ () => addTo( p, pick ) } disabled={ !! busy || pick === '' }>
													{ __( 'Add', 'dx-ui' ) }
												</Button>
												<Button variant="tertiary" size="small" onClick={ () => { setAdding( '' ); setPick( '' ); } }>
													{ __( 'Cancel', 'dx-ui' ) }
												</Button>
											</>
										) : (
											<Button variant="secondary" size="small" onClick={ () => { setAdding( p.type + p.slug ); setPick( '' ); } } disabled={ !! busy }>
												{ __( '+ Add a section', 'dx-ui' ) }
											</Button>
										) }
									</div>
								) }
								{ p.nearest > 0 && (
									<p className="dxui-muted">
										{ sprintf(
											/* translators: %d: number of sections. */
											_n( 'Differs from the nearest of the team\'s real pages by %d section.', 'Differs from the nearest of the team\'s real pages by %d sections.', p.nearest, 'dx-ui' ),
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
				<div className="dxui-team__built">
					<Notice status="success" isDismissible={ false }>
						{ sprintf(
							/* translators: %d: number of pages. */
							_n( '%d page is made. Now write its words (Words for the pages, below).', '%d pages are made. Now write their words (Words for the pages, below).', built.pages.length, 'dx-ui' ),
							built.pages.length
						) }
					</Notice>
					{ built.ai && built.ai.asked && (
						<Notice status={ built.ai.rejected + built.ai.skipped > 0 ? 'warning' : 'info' } isDismissible={ false }>
							{ sprintf(
								/* translators: 1: pages planned by the AI, 2: pages made the usual way. */
								__( 'The AI planned %1$d pages; %2$d were made the usual way (its plan broke a rule, it did not answer, or the ceiling was reached).', 'dx-ui' ),
								built.ai.accepted,
								built.ai.rejected + built.ai.skipped
							) }
							{ built.ai.spent && built.ai.spent.requests > 0 && ' ' + sprintf( /* translators: 1: requests, 2: tokens. */ __( 'It spent %1$d requests and about %2$s tokens.', 'dx-ui' ), built.ai.spent.requests, built.ai.spent.tokens.toLocaleString() ) }
						</Notice>
					) }
					{ built.kept.filter( ( k ) => ! k.why || k.why === 'edited' ).length > 0 && (
						<Notice status="warning" isDismissible={ false }>
							{ sprintf(
								/* translators: %s: page titles. */
								__( 'Kept as they are, because someone edited them: %s', 'dx-ui' ),
								built.kept.filter( ( k ) => ! k.why || k.why === 'edited' ).map( ( k ) => k.title ).join( ', ' )
							) }
						</Notice>
					) }
					{ built.kept.filter( ( k ) => k.why === 'address' ).length > 0 && (
						<Notice status="warning" isDismissible={ false }>
							{ sprintf(
								/* translators: %s: page titles. */
								__( 'Not made, because their address is already another page, which is left as it is: %s', 'dx-ui' ),
								built.kept.filter( ( k ) => k.why === 'address' ).map( ( k ) => k.title ).join( ', ' )
							) }
						</Notice>
					) }
					{ built.kept.filter( ( k ) => k.why === 'parent' ).length > 0 && (
						<Notice status="warning" isDismissible={ false }>
							{ sprintf(
								/* translators: %s: page titles. */
								__( 'Not made yet, because the page above them in the address is not there (tick the list page they sit under): %s', 'dx-ui' ),
								built.kept.filter( ( k ) => k.why === 'parent' ).map( ( k ) => k.title ).join( ', ' )
							) }
						</Notice>
					) }
					<ul className="dxui-team__links">
						{ built.pages.map( ( p ) => (
							<li key={ p.id }>
								<strong>{ p.title }</strong>
								<span className="dxui-muted">{ sprintf( /* translators: %d: number of sections. */ _n( '%d section', '%d sections', p.sections, 'dx-ui' ), p.sections ) }</span>
								{ p.planned === 'ai' && <span className="dxui-copy__kind">{ __( 'planned by the AI', 'dx-ui' ) }</span> }
								<Button variant="link" href={ p.edit }>{ __( 'Edit', 'dx-ui' ) }</Button>
								<Button variant="link" href={ p.view }>{ __( 'View', 'dx-ui' ) }</Button>
							</li>
						) ) }
					</ul>

					{ built.report && built.report.length > 0 && (
						<div className="dxui-team__report">
							<h4>{ __( 'How the pages measure up', 'dx-ui' ) }</h4>
							<ul className="dxui-team__reports">
								{ built.report.map( ( r ) => (
									<li key={ r.id } className={ r.ok ? 'is-ok' : 'is-bad' }>
										<strong>{ r.title }</strong>
										<ul className="dxui-team__gates">
											{ r.gates.map( ( g ) => (
												<li key={ g.gate } className={ g.ok ? 'is-ok' : 'is-bad' } title={ g.problems.join( '\n' ) }>
													<span className="dxui-team__gate-mark" aria-hidden="true">{ g.ok ? '✓' : '✕' }</span>
													<span className="screen-reader-text">{ g.ok ? __( 'Passes:', 'dx-ui' ) : __( 'Fails:', 'dx-ui' ) }</span>
													{ GATE[ g.gate ] || g.label }
													{ ! g.ok && (
														<span className="dxui-team__gate-why">
															{ g.gate === 'G8W' ? __( 'The words are still the Home\'s: write them in "Words for the pages".', 'dx-ui' ) : g.problems[ 0 ] }
														</span>
													) }
												</li>
											) ) }
										</ul>
									</li>
								) ) }
							</ul>
							<p className="dxui-muted">
								{ __( 'Colours, fonts, spacing, how the sections look next to the Home\'s, mobile and speed need a browser to be measured, so they are not shown here: the sections are the Home\'s own, and the plugin\'s quality tool (bin/team-quality.cjs) measures them on a copy of the site.', 'dx-ui' ) }
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
							__( 'The run is given back: %1$d pages put back as they were, %2$d moved to the trash (nothing is deleted).', 'dx-ui' ),
							gave.restored.length,
							gave.removed.length
						)
						: __( 'The page is put back as it was.', 'dx-ui' ) }
					{ gave.skipped.length > 0 && ' ' + sprintf(
						/* translators: %s: page titles. */
						__( 'Left as they are, because they were changed since: %s.', 'dx-ui' ),
						gave.skipped.map( ( s ) => s.title ).join( ', ' )
					) }
				</Notice>
			) }

			{ run && (
				<div className="dxui-team__run">
					<h4>{ __( 'The last run', 'dx-ui' ) }</h4>
					<p className="dxui-muted">
						{ sprintf(
							/* translators: 1: date and time, 2: pages made, 3: pages changed. */
							__( '%1$s — %2$d pages made, %3$d changed. Giving it back puts the pages that were there back as they were, moves the pages it made to the trash, and returns the Home\'s menu links.', 'dx-ui' ),
							new Date( run.at * 1000 ).toLocaleString(),
							run.pages.filter( ( p ) => p.created ).length,
							run.pages.filter( ( p ) => ! p.created ).length
						) }
					</p>
					<ul className="dxui-team__links">
						{ run.pages.map( ( p ) => (
							<li key={ p.id }>
								<strong>{ p.title }</strong>
								<span className="dxui-copy__kind">{ p.created ? __( 'made new', 'dx-ui' ) : __( 'changed', 'dx-ui' ) }</span>
								{ p.changed && <span className="dxui-muted">{ __( 'changed since: it stays as it is', 'dx-ui' ) }</span> }
								{ ! p.created && ! p.changed && (
									<Button variant="link" onClick={ () => send( 'put_back', { page: p.id } ) } disabled={ !! busy }>
										{ __( 'Put this page back', 'dx-ui' ) }
									</Button>
								) }
							</li>
						) ) }
					</ul>
					<div className="dxui-actions">
						{ confirm ? (
							<>
								<Button variant="primary" isDestructive onClick={ () => send( 'undo' ) } isBusy={ busy === 'undo' } disabled={ !! busy }>
									{ __( 'Yes, give the run back', 'dx-ui' ) }
								</Button>
								<Button variant="tertiary" onClick={ () => setConfirm( false ) }>
									{ __( 'No', 'dx-ui' ) }
								</Button>
							</>
						) : (
							<Button variant="secondary" isDestructive onClick={ () => setConfirm( true ) } disabled={ !! busy }>
								{ __( 'Give the whole run back', 'dx-ui' ) }
							</Button>
						) }
					</div>
				</div>
			) }
		</section>
	);
}

import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, Spinner } from '@wordpress/components';

/*
 * The two-click way to a site (docs/PLAN-NETWORK-PAGES.md, section 7 and decisions 7–9): drop the design, then
 * "Build my site". The import wizard does the work as it always does — the upload, the compile, the save in rounds, the
 * menu pages — with the answers already chosen: a design archive, the whole site, the pages its menu names, the header
 * and footer as this theme takes them. What it is about to do is said in a few lines with one button; every choice of the
 * wizard is one link away ("Change something").
 */

/** The first screen: one place to drop the design. */
export function QuickDrop( { canImport, refusal, onFile, onOther } ) {
	const take = ( list ) => {
		const file = list && list[ 0 ];
		if ( file && /\.zip$/i.test( file.name ) ) {
			onFile( file );
		}
	};
	return (
		<section className="dxai-stage-card dxai-quick">
			<h3 className="dxai-step-title">{ __( 'Drop your design', 'dxai-ui' ) }</h3>
			<p className="dxai-muted">
				{ __( 'One .zip from Lovable, Claude Design, Vite or React, Next.js or plain HTML. We read it and build the site: the Home, its header and footer, and the pages its menu names.', 'dxai-ui' ) }
			</p>
			{ ! canImport && (
				<Notice status="error" isDismissible={ false } className="dxai-cannot-import">
					{ refusal || __( 'Your account may not import designs on this site.', 'dxai-ui' ) }
				</Notice>
			) }
			<label
				className="dxai-quick__drop"
				onDragOver={ ( e ) => e.preventDefault() }
				onDrop={ ( e ) => {
					e.preventDefault();
					if ( canImport ) {
						take( e.dataTransfer.files );
					}
				} }
			>
				<input type="file" accept=".zip,application/zip" disabled={ ! canImport } onChange={ ( e ) => take( e.target.files ) } />
				<strong>{ __( 'Drop the .zip here', 'dxai-ui' ) }</strong>
				<span>{ __( 'or click to choose it', 'dxai-ui' ) }</span>
			</label>
			<p className="dxai-quick__other">
				<button type="button" className="dxai-link" onClick={ onOther }>
					{ __( 'Other ways: a Figma file, a pasted component, or every choice step by step', 'dxai-ui' ) }
				</button>
			</p>
		</section>
	);
}

/** The second screen: what is about to be done, in plain words, and one button. */
export function QuickPlan( { plan, canCrawl, crawlWanted, crawlCount, pagesEngine, aiName, chrome, chromeNote, front, sections, onBuild, onChange, busy } ) {
	const lines = [];
	lines.push( {
		key: 'home',
		text: front && front.state === 'own'
			? sprintf( /* translators: %s: the title of the site's front page */ __( 'The Home from the design. Your site keeps its own front page (“%s”); you can make the Home the front page when the site is built.', 'dxai-ui' ), front.title )
			: __( 'The Home from the design, as the site’s front page.', 'dxai-ui' ),
	} );
	if ( chrome === 'install' ) {
		lines.push( { key: 'chrome', text: __( 'Its header in Appearance › Menus and its footer in Appearance › Widgets.', 'dxai-ui' ) } );
	} else if ( chrome === 'keep' ) {
		lines.push( { key: 'chrome', text: chromeNote || __( 'The site keeps its own header and footer.', 'dxai-ui' ) } );
	}
	if ( plan && plan.hasMenu ) {
		if ( crawlWanted ) {
			const count = crawlCount > 0 ? crawlCount : ( plan.pages || [] ).length;
			const names = ( plan.pages || [] ).slice( 0, 6 ).map( ( p ) => p.label || p.path ).join( ', ' );
			lines.push( {
				key: 'pages',
				text: ( count > 0
					? sprintf( /* translators: %d: number of pages */ _n( '%d page from the menu', '%d pages from the menu', count, 'dxai-ui' ), count ) + ( names ? ' (' + names + ( count > 6 ? ', …' : '' ) + ')' : '' )
					: __( 'The pages the menu names', 'dxai-ui' ) ) +
					' — ' + ( plan.host
						? sprintf( /* translators: %s: the old site's host */ __( 'with the words and pictures of their pages on %s, in the look of this Home', 'dxai-ui' ), plan.host )
						: __( 'with the words and pictures of the old site’s pages, in the look of this Home', 'dxai-ui' ) ) +
					( pagesEngine === 'ai' ? ' ' + __( '(written again by the AI chosen in Settings)', 'dxai-ui' ) : '' ) +
					// The Home's sections, their words written place by place in the Home's measure (Slot_Writer).
					( pagesEngine !== 'ai' && aiName
						? '; ' + sprintf( /* translators: %s: the AI's name */ __( 'their words written by %s to the length each place has on the Home', 'dxai-ui' ), aiName )
						: '' ) + '.',
			} );
		} else {
			lines.push( {
				key: 'pages',
				text: canCrawl
					? __( 'The menu’s pages are not made now (switched off under “Change something”).', 'dxai-ui' )
					: __( 'The pages the menu names, made from the Home’s own sections in the order the team’s pages have, each with its own words (written by the AI chosen in Settings, or left for you with the Home’s words as hints — never words that stand in).', 'dxai-ui' ),
			} );
		}
	}
	lines.push( { key: 'look', text: __( 'The fonts and colours of your theme.', 'dxai-ui' ) } );

	return (
		<section className="dxai-stage-card dxai-quick">
			<h3 className="dxai-step-title">{ __( 'Your new site', 'dxai-ui' ) }</h3>
			<p className="dxai-muted">
				{ sprintf( /* translators: %d: sections read in the design */ _n( 'The design is read: %d section. This is what will be made.', 'The design is read: %d sections. This is what will be made.', sections, 'dxai-ui' ), sections ) }
			</p>
			<ul className="dxai-quick__plan">
				{ lines.map( ( l ) => (
					<li key={ l.key }>
						<span className="dxai-quick__tick" aria-hidden="true">✓</span>
						{ l.text }
					</li>
				) ) }
			</ul>
			<div className="dxai-actions">
				<Button variant="primary" disabled={ busy } onClick={ onBuild }>{ __( 'Build my site', 'dxai-ui' ) }</Button>
				<button type="button" className="dxai-link" onClick={ onChange }>{ __( 'Change something', 'dxai-ui' ) }</button>
			</div>
		</section>
	);
}

/*
 * The pages the menu names, when the old site they would come from is not known: made from the Home's own sections in the
 * order the network's pages have (Pages\Team_Pages, recipe `network`), each with its own words (`words` `write`: the AI chosen
 * in Settings, or its own lines left for a person) — one page a request, so a host that ends a request after a minute never
 * cuts one in half.
 */
export function MenuPagesRun( { home, onDone } ) {
	const [ state, setState ] = useState( { phase: 'reading', at: 0, of: 0, now: '', done: [], error: '' } );
	useEffect( () => {
		if ( ( state.phase === 'done' || state.phase === 'none' ) && onDone ) {
			onDone();
		}
	}, [ state.phase ] ); // eslint-disable-line react-hooks/exhaustive-deps
	useEffect( () => {
		let live = true;
		( async () => {
			try {
				const info = await apiFetch( { path: '/dxai-ui/v1/team-pages?design=' + home } );
				const rows = ( ( info && info.menu && info.menu.pages ) || [] ).filter( ( r ) => ! r.exists || r.mine );
				const s = { phase: rows.length ? 'making' : 'none', at: 0, of: rows.length, now: '', done: [], error: '' };
				if ( live ) {
					setState( { ...s } );
				}
				for ( const row of rows ) {
					if ( ! live ) {
						return;
					}
					s.now = row.title;
					setState( { ...s } );
					try {
						const res = await apiFetch( {
							path: '/dxai-ui/v1/team-pages',
							method: 'POST',
							data: { action: 'build', design: home, wanted: [ { type: row.type, title: row.title, path: row.path, item: row.item } ], recipe: 'network', words: 'write', library: 'fill' },
						} );
						( res.pages || [] ).forEach( ( p ) => s.done.push( p ) );
					} catch ( e ) {
						s.done.push( { id: 0, title: row.title, error: e.message } );
					}
					s.at++;
					if ( live ) {
						setState( { ...s } );
					}
				}
				s.phase = 'done';
				if ( live ) {
					setState( { ...s } );
				}
			} catch ( e ) {
				if ( live ) {
					setState( ( x ) => ( { ...x, phase: 'done', error: e.message } ) );
				}
			}
		} )();
		return () => {
			live = false;
		};
	}, [ home ] );

	if ( state.phase === 'none' ) {
		return null;
	}
	return (
		<section className="dxai-quick__pages" aria-live="polite">
			<h4>{ __( 'The menu’s pages, from the Home’s own sections', 'dxai-ui' ) }</h4>
			{ state.phase !== 'done' && (
				<p>
					<Spinner />
					{ state.phase === 'reading'
						? __( 'Reading the menu…', 'dxai-ui' )
						: sprintf( /* translators: 1: pages made, 2: pages in all, 3: the page being made */ __( '%1$d of %2$d made; now %3$s…', 'dxai-ui' ), state.at, state.of, state.now ) }
				</p>
			) }
			{ state.error && <Notice status="warning" isDismissible={ false }>{ state.error }</Notice> }
			{ state.done.length > 0 && (
				<ul className="dxai-done__list-plain">
					{ state.done.map( ( p, i ) => (
						<li key={ ( p.id || 0 ) + '-' + i }>
							<strong>{ p.title }</strong>{ ' ' }
							{ p.error
								? <span className="dxai-done__msg">{ p.error }</span>
								: ( p.words === 'ai'
									? __( 'written', 'dxai-ui' )
									: sprintf( /* translators: %d: lines a person writes */ _n( '%d line to write', '%d lines to write', p.places || 0, 'dxai-ui' ), p.places || 0 ) ) }
							{ p.id > 0 && (
								<>
									{ ' · ' }<a href={ p.view }>{ __( 'View', 'dxai-ui' ) }</a>{ ' · ' }<a href={ p.edit }>{ __( 'Edit', 'dxai-ui' ) }</a>
								</>
							) }
							{ p.quality && p.quality.grade !== 'good' && (
								<span className="dxai-done__msg">
									{ ' — ' + p.quality.checks.filter( ( c ) => c.state !== 'ok' ).map( ( c ) => c.text ).join( '; ' ) }
								</span>
							) }
						</li>
					) ) }
				</ul>
			) }
		</section>
	);
}

/**
 * The pages the import of this archive makes from now on are drafts, until the person has seen the whole site and chosen
 * (Network\Site_Review); another import's pages are not held.
 */
export function holdNewPages( archive ) {
	return apiFetch( { path: '/dxai-ui/v1/site-review', method: 'POST', data: { action: 'hold', archive: archive || '' } } ).catch( () => null );
}

// The kinds the network's pages are told apart by (Network_Model::kind_of()); another kind is shown as its own words.
const KIND_LABELS = {
	home: __( 'Home', 'dxai-ui' ),
	service: __( 'Service', 'dxai-ui' ),
	'services-index': __( 'All services', 'dxai-ui' ),
	location: __( 'Area', 'dxai-ui' ),
	'areas-index': __( 'All areas', 'dxai-ui' ),
	about: __( 'About', 'dxai-ui' ),
	contact: __( 'Contact', 'dxai-ui' ),
	reviews: __( 'Reviews', 'dxai-ui' ),
	faq: __( 'Questions', 'dxai-ui' ),
	'blog-index': __( 'Blog', 'dxai-ui' ),
	careers: __( 'Careers', 'dxai-ui' ),
	'financing-insurance': __( 'Financing & insurance', 'dxai-ui' ),
	gallery: __( 'Gallery', 'dxai-ui' ),
	audience: __( 'For whom', 'dxai-ui' ),
	legal: __( 'Legal', 'dxai-ui' ),
	'thank-you': __( 'Thank you', 'dxai-ui' ),
	other: __( 'Page', 'dxai-ui' ),
};
const kindLabel = ( kind ) => KIND_LABELS[ kind ] || ( kind ? kind.charAt( 0 ).toUpperCase() + kind.slice( 1 ).replace( /-/g, ' ' ) : __( 'Page', 'dxai-ui' ) );

/*
 * The whole site before its pages go live (docs/PLAN-NETWORK-PAGES.md, section 11, point 6): the Home and every page the
 * import made, each seen as it is (a live picture of its preview) with how it measures against the team's pages, and the
 * choice — every page, only the Home, or the ones ticked. The chosen ones are published; the others go to the trash
 * (Pages › Trash brings any back), and only pages the import made: a page of the site's own is never touched.
 */
export function SiteReview( { home } ) {
	const [ data, setData ] = useState( null );
	const [ picked, setPicked ] = useState( {} );
	const [ busy, setBusy ] = useState( false );
	const [ outcome, setOutcome ] = useState( null );
	const [ error, setError ] = useState( '' );
	// The one page drawn live beside the list (its id; 0: the Home), and at which width.
	const [ shown, setShown ] = useState( 0 );
	const [ width, setWidth ] = useState( 'desktop' );

	const load = () =>
		apiFetch( { path: '/dxai-ui/v1/site-review?home=' + home } )
			.then( ( res ) => {
				setData( res );
				const next = {};
				( res.pages || [] ).forEach( ( p ) => {
					if ( ! p.home && p.status === 'draft' ) {
						next[ p.id ] = true;
					}
				} );
				setPicked( next );
				return res;
			} )
			.catch( ( e ) => setError( e.message ) );

	useEffect( () => {
		load().then( ( res ) => {
			// Nothing waits for a choice: pages made from here on are published as they are made.
			if ( res && ! ( res.pages || [] ).some( ( p ) => ! p.home && p.status === 'draft' ) ) {
				apiFetch( { path: '/dxai-ui/v1/site-review', method: 'POST', data: { action: 'release' } } ).catch( () => null );
			}
		} );
	}, [ home ] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( error && ! data ) {
		return <Notice status="warning" isDismissible={ false }>{ error }</Notice>;
	}
	if ( ! data ) {
		return (
			<section className="dxai-review" aria-busy="true">
				<p><Spinner />{ __( 'Gathering the site’s pages…', 'dxai-ui' ) }</p>
			</section>
		);
	}

	const pages = data.pages || [];
	const waiting = pages.filter( ( p ) => ! p.home && p.status === 'draft' );
	if ( waiting.length === 0 && ! outcome ) {
		return null;
	}
	const parentOf = {};
	pages.forEach( ( p ) => {
		parentOf[ p.id ] = p.parent;
	} );
	// A ticked page needs the page it sits under; an unticked page takes the pages under it along.
	const toggle = ( id, on ) => {
		const next = { ...picked, [ id ]: on };
		if ( on ) {
			for ( let up = parentOf[ id ]; up && Object.prototype.hasOwnProperty.call( next, up ); up = parentOf[ up ] ) {
				next[ up ] = true;
			}
		} else {
			const under = ( of ) =>
				waiting.filter( ( p ) => p.parent === of ).forEach( ( p ) => {
					next[ p.id ] = false;
					under( p.id );
				} );
			under( id );
		}
		setPicked( next );
	};
	const decide = ( publish, drop ) => {
		setBusy( true );
		setError( '' );
		apiFetch( { path: '/dxai-ui/v1/site-review', method: 'POST', data: { action: 'decide', home, publish, drop } } )
			.then( ( res ) => {
				setOutcome( res );
				// What the choice did, at once (the full list, with each page measured again, follows).
				setData( ( d ) => ( {
					...d,
					holding: false,
					pages: ( d.pages || [] )
						.filter( ( p ) => ! res.trashed.includes( p.id ) )
						.map( ( p ) => ( res.published.includes( p.id ) ? { ...p, status: 'publish', view: p.view.replace( /([?&])preview=true(&?)/, ( m, a, b ) => ( b ? a : '' ) ) } : p ) ),
				} ) );
				return load();
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( false ) );
	};
	const made = waiting.filter( ( p ) => p.made ).map( ( p ) => p.id );
	const chosen = waiting.filter( ( p ) => picked[ p.id ] ).map( ( p ) => p.id );
	const left = made.filter( ( id ) => ! picked[ id ] );

	return (
		<section className="dxai-review" aria-live="polite">
			<h3 className="dxai-step-title">{ __( 'Your whole site, before it goes live', 'dxai-ui' ) }</h3>
			{ outcome ? (
				<Notice status="success" isDismissible={ false }>
					{ [
						sprintf( /* translators: %d: pages published */ _n( '%d page added to the site.', '%d pages added to the site.', outcome.published.length, 'dxai-ui' ), outcome.published.length ),
						outcome.trashed.length > 0
							? sprintf( /* translators: %d: pages moved to the trash */ _n( '%d page moved to the trash (Pages › Trash brings it back).', '%d pages moved to the trash (Pages › Trash brings them back).', outcome.trashed.length, 'dxai-ui' ), outcome.trashed.length )
							: '',
						outcome.kept.length > 0
							? sprintf( /* translators: %d: pages kept as drafts */ _n( '%d page kept as a draft: a published page sits under it, or the site had it before.', '%d pages kept as drafts: a published page sits under them, or the site had them before.', outcome.kept.length, 'dxai-ui' ), outcome.kept.length )
							: '',
					].filter( Boolean ).join( ' ' ) }
				</Notice>
			) : (
				<p className="dxai-muted">
					{ sprintf( /* translators: %d: pages waiting */ _n( 'The Home is live. %d page waits as a draft for your choice: look at it, then add it or keep only the Home.', 'The Home is live. %d pages wait as drafts for your choice: look at them, then add them all, only the ones you tick, or keep only the Home.', waiting.length, 'dxai-ui' ), waiting.length ) }
				</p>
			) }
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			<div className="dxai-review__body">
				<ul className="dxai-review__list">
					{ pages.map( ( p ) => {
						const open = ! p.home && p.status === 'draft';
						const issues = p.quality ? p.quality.checks.filter( ( c ) => c.state !== 'ok' ) : [];
						const current = ( shown || pages[ 0 ].id ) === p.id;
						return (
							<li key={ p.id } className={ 'dxai-review__card' + ( open && ! picked[ p.id ] ? ' is-off' : '' ) + ( current ? ' is-shown' : '' ) }>
								<button type="button" className="dxai-review__thumb" onClick={ () => setShown( p.id ) } aria-pressed={ current } aria-label={ sprintf( /* translators: %s: page title */ __( 'Show %s beside the list', 'dxai-ui' ), p.title ) }>
									{ p.picture ? <img src={ p.picture } alt="" loading="lazy" /> : <span aria-hidden="true">{ ( p.title || '?' ).slice( 0, 1 ) }</span> }
								</button>
								<div className="dxai-review__meta">
									{ open ? (
										<label className="dxai-review__pick">
											<input type="checkbox" checked={ !! picked[ p.id ] } disabled={ busy } onChange={ ( e ) => toggle( p.id, e.target.checked ) } />
											<strong>{ p.title }</strong>
										</label>
									) : (
										<strong>{ p.title }</strong>
									) }
									<span className="dxai-review__tags">
										<span className="dxai-review__tag">{ kindLabel( p.kind ) }</span>
										{ p.home && <span className="dxai-review__tag is-live">{ __( 'Live', 'dxai-ui' ) }</span> }
										{ ! p.home && p.status === 'publish' && <span className="dxai-review__tag is-live">{ __( 'On the site', 'dxai-ui' ) }</span> }
										{ open && p.quality && (
											<span className={ 'dxai-review__tag ' + ( p.quality.grade === 'good' ? 'is-good' : 'is-check' ) }>
												{ p.quality.grade === 'good' ? __( 'Like the team’s pages', 'dxai-ui' ) : __( 'Worth a look', 'dxai-ui' ) }
											</span>
										) }
									</span>
									{ open && issues.length > 0 && <span className="dxai-review__issues">{ issues.map( ( c ) => c.text ).join( '; ' ) }</span> }
									<span className="dxai-review__links">
										<a href={ p.view } target="_blank" rel="noreferrer">{ open ? __( 'Preview', 'dxai-ui' ) : __( 'View', 'dxai-ui' ) }</a>
										{ ' · ' }
										<a href={ p.edit }>{ __( 'Edit', 'dxai-ui' ) }</a>
									</span>
								</div>
							</li>
						);
					} ) }
				</ul>
				{ ( () => {
					const p = pages.find( ( x ) => x.id === ( shown || pages[ 0 ].id ) ) || pages[ 0 ];
					return (
						<div className="dxai-review__stage">
							<div className="dxai-review__bar">
								<strong>{ p.title }</strong>
								<span className="dxai-review__widths" role="group" aria-label={ __( 'Width', 'dxai-ui' ) }>
									<Button size="compact" variant={ width === 'desktop' ? 'primary' : 'secondary' } aria-pressed={ width === 'desktop' } onClick={ () => setWidth( 'desktop' ) }>{ __( 'Desktop', 'dxai-ui' ) }</Button>
									<Button size="compact" variant={ width === 'phone' ? 'primary' : 'secondary' } aria-pressed={ width === 'phone' } onClick={ () => setWidth( 'phone' ) }>{ __( 'Phone', 'dxai-ui' ) }</Button>
									<a href={ p.view } target="_blank" rel="noreferrer">{ __( 'Open in a new tab', 'dxai-ui' ) }</a>
								</span>
							</div>
							<div className={ 'dxai-review__screen is-' + width }>
								<iframe key={ p.id + width } src={ p.view } title={ sprintf( /* translators: %s: page title */ __( 'Preview of %s', 'dxai-ui' ), p.title ) } />
							</div>
						</div>
					);
				} )() }
			</div>
			{ waiting.length > 0 && (
				<div className="dxai-actions">
					<Button variant="primary" disabled={ busy } onClick={ () => decide( waiting.map( ( p ) => p.id ), [] ) }>
						{ sprintf( /* translators: %d: pages */ _n( 'Add the page', 'Add all %d pages', waiting.length, 'dxai-ui' ), waiting.length ) }
					</Button>
					{ chosen.length > 0 && chosen.length < waiting.length && (
						<Button variant="secondary" disabled={ busy } onClick={ () => decide( chosen, left ) }>
							{ sprintf( /* translators: %d: pages ticked */ _n( 'Add the %d ticked page', 'Add the %d ticked pages', chosen.length, 'dxai-ui' ), chosen.length ) }
						</Button>
					) }
					<Button variant="tertiary" disabled={ busy } onClick={ () => decide( [], made ) }>{ __( 'Only the Home', 'dxai-ui' ) }</Button>
					{ busy && <Spinner /> }
				</div>
			) }
		</section>
	);
}

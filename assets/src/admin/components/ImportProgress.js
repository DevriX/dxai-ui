import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { crawlStepText, formatDuration } from '../importFlow';
import './import.css';

/*
 * The import's progress, from the moment the archive is sent until the last
 * page is built.
 *
 * Every row is a phase the server actually goes through. The running one is
 * animated, finished ones are ticked with the time they took, and the only
 * bars are ones that count something real — bytes of the archive uploaded,
 * pages of the menu crawled. A phase that is one long request says so, and
 * says how long it has been going, instead of pretending to a percentage.
 *
 * Screen readers hear each phase change (and each new crawled page) through
 * a polite live region; the clock is left out of it so it does not chatter.
 */

const STATE_TEXT = {
	pending: __( 'Not started', 'dxai-ui' ),
	active: __( 'In progress', 'dxai-ui' ),
	waiting: __( 'Waiting for you', 'dxai-ui' ),
	done: __( 'Done', 'dxai-ui' ),
	warn: __( 'Done, with a problem', 'dxai-ui' ),
	skipped: __( 'Skipped', 'dxai-ui' ),
	error: __( 'Failed', 'dxai-ui' ),
};

function useClock( running ) {
	const [ now, setNow ] = useState( () => Date.now() );
	useEffect( () => {
		setNow( Date.now() );
		if ( ! running ) {
			return undefined;
		}
		const timer = window.setInterval( () => setNow( Date.now() ), 1000 );
		return () => window.clearInterval( timer );
	}, [ running ] );
	return now;
}

export function Meter( { value, max, label, text } ) {
	const pct = max > 0 ? Math.max( 0, Math.min( 100, ( value / max ) * 100 ) ) : 0;
	return (
		<div
			className="dxai-meter"
			role="progressbar"
			aria-label={ label }
			aria-valuemin={ 0 }
			aria-valuemax={ max }
			aria-valuenow={ value }
			aria-valuetext={ text }
		>
			<span className="dxai-meter__fill" style={ { width: pct + '%' } } />
		</div>
	);
}

export function Working( { label } ) {
	// No aria-valuenow: an indeterminate progressbar, which is the truth.
	return (
		<div className="dxai-meter is-indeterminate" role="progressbar" aria-label={ label } aria-valuetext={ __( 'Working, no estimate available', 'dxai-ui' ) }>
			<span className="dxai-meter__fill" />
		</div>
	);
}

function phaseTime( entry, now ) {
	if ( ! entry || ! entry.started ) {
		return '';
	}
	if ( entry.state === 'active' ) {
		return formatDuration( now - entry.started );
	}
	if ( entry.state === 'waiting' ) {
		return '';
	}
	// A phase read straight off an answer (menus, widgets) took no time of its own.
	return entry.ended && entry.ended - entry.started >= 1000 ? formatDuration( entry.ended - entry.started ) : '';
}

/** Elapsed working time: every phase's duration except the time spent reviewing. */
function workingTime( progress, now ) {
	let total = 0;
	Object.keys( progress.phases ).forEach( ( id ) => {
		const p = progress.phases[ id ];
		if ( id === 'review' || ! p || ! p.started ) {
			return;
		}
		if ( p.state === 'active' ) {
			total += now - p.started;
		} else if ( p.ended ) {
			total += p.ended - p.started;
		}
	} );
	return total;
}

function CrawlPanel( { crawl, engine, entry, now } ) {
	const p = crawl || {};
	const total = p.total || 0;
	const finished = Math.min( p.index || 0, total );
	const queue = Array.isArray( p.queue ) ? p.queue : [];
	const running = entry?.state === 'active';
	const [ stepSince, setStepSince ] = useState( { key: '', at: Date.now() } );
	const stepKey = ( p.index || 0 ) + ':' + ( p.phase || '' ) + ':' + ( p.finishing ? 1 : 0 );
	useEffect( () => {
		if ( stepKey !== stepSince.key ) {
			setStepSince( { key: stepKey, at: Date.now() } );
		}
	}, [ stepKey ] ); // eslint-disable-line react-hooks/exhaustive-deps

	return (
		<div className="dxai-crawl">
			{ running && total > 0 && ! p.finishing && (
				<p className="dxai-crawl__now">
					<span className="dxai-crawl__count">
						{ sprintf(
							/* translators: 1: page number, 2: page count */
							__( 'Page %1$d of %2$d', 'dxai-ui' ),
							Math.min( finished + 1, total ),
							total
						) }
					</span>
					<strong>{ p.label || p.path || '…' }</strong>
					{ p.path && p.path !== p.label && <code>{ p.path }</code> }
				</p>
			) }
			{ running && (
				<p className="dxai-crawl__step">
					{ total === 0 && ! p.finishing ? __( 'Planning the pages from the menu…', 'dxai-ui' ) : crawlStepText( p, engine ) }
					{ ' ' }
					<span className="dxai-crawl__clock">{ formatDuration( now - stepSince.at ) }</span>
				</p>
			) }
			{ total > 0 && (
				<>
					<Meter
						value={ finished }
						max={ total }
						label={ __( 'Menu pages', 'dxai-ui' ) }
						text={ sprintf(
							/* translators: 1: pages finished, 2: page count */
							__( '%1$d of %2$d pages finished', 'dxai-ui' ),
							finished,
							total
						) }
					/>
					<p className="dxai-crawl__tally">
						<span>
							{ sprintf(
								/* translators: 1: pages finished, 2: page count */
								__( '%1$d of %2$d pages finished', 'dxai-ui' ),
								finished,
								total
							) }
						</span>
						<span>{ sprintf( /* translators: %d: pages created */ _n( '%d created', '%d created', p.created || 0, 'dxai-ui' ), p.created || 0 ) }</span>
						{ p.ai !== false && (
							<span>{ sprintf( /* translators: %d: pages rebuilt with AI */ _n( '%d rebuilt with AI', '%d rebuilt with AI', p.restyled || 0, 'dxai-ui' ), p.restyled || 0 ) }</span>
						) }
						<span className={ p.errors ? 'is-bad' : '' }>{ sprintf( /* translators: %d: pages that failed */ _n( '%d error', '%d errors', p.errors || 0, 'dxai-ui' ), p.errors || 0 ) }</span>
					</p>
				</>
			) }
			{ running && p.busy && (
				<p className="dxai-crawl__note">
					{ __( 'The previous step is still running on the server (an AI answer can take a couple of minutes). Waiting for it to finish.', 'dxai-ui' ) }
				</p>
			) }
			{ running && p.retrying > 0 && (
				<p className="dxai-crawl__note is-retry">
					{ sprintf(
						/* translators: 1: attempt number, 2: what went wrong */
						__( 'The last request did not come back (attempt %1$d: %2$s). The server keeps working on the page; trying again in 5 seconds.', 'dxai-ui' ),
						p.retrying,
						p.lastError || __( 'no answer', 'dxai-ui' )
					) }
				</p>
			) }
			{ queue.length > 0 && (
				<details className="dxai-crawl__queue" open={ queue.length <= 12 }>
					<summary>
						{ sprintf(
							/* translators: %d: number of pages */
							_n( 'The %d page from the menu', 'All %d pages from the menu', queue.length, 'dxai-ui' ),
							queue.length
						) }
					</summary>
					<ol>
						{ queue.map( ( row, i ) => (
							<li key={ ( row.path || '' ) + i } className={ 'is-' + ( row.state || 'pending' ) }>
								<span className="dxai-crawl__dot" aria-hidden="true" />
								<span className="dxai-crawl__label">{ row.label || row.path }</span>
								{ row.path && <code>{ row.path }</code> }
								<span className="dxai-crawl__state">
									{ {
										created: __( 'created', 'dxai-ui' ),
										done: __( 'done', 'dxai-ui' ),
										skipped: row.reason === 'edited' ? __( 'kept — edited since the last import', 'dxai-ui' ) : ( row.reason === 'home' ? __( 'the home page', 'dxai-ui' ) : __( 'skipped', 'dxai-ui' ) ),
										error: row.message || __( 'failed', 'dxai-ui' ),
										current: entry?.state === 'error' ? __( 'stopped here', 'dxai-ui' ) : __( 'working on it', 'dxai-ui' ),
										pending: __( 'waiting', 'dxai-ui' ),
									}[ row.state ] || '' }
								</span>
							</li>
						) ) }
					</ol>
				</details>
			) }
			{ running && (
				<p className="dxai-crawl__note">
					{ __( 'Keep this tab open to watch it. If you close it, WordPress carries on in the background (when WP-Cron runs) and the pages appear in the Library.', 'dxai-ui' ) }
				</p>
			) }
		</div>
	);
}

function PhaseError( { failure, actions, titleRef } ) {
	return (
		<div className="dxai-phase-error" role="alert">
			<p className="dxai-phase-error__title" tabIndex={ -1 } ref={ titleRef }>
				{ failure.title }
			</p>
			<p className="dxai-phase-error__message">
				<span className="dxai-phase-error__k">{ __( 'The server said:', 'dxai-ui' ) }</span> { failure.message }
				{ failure.code && <code>{ failure.code }{ failure.status ? ' · HTTP ' + failure.status : '' }</code> }
			</p>
			{ failure.hint && (
				<p className="dxai-phase-error__hint">
					<span className="dxai-phase-error__k">{ __( 'What to do:', 'dxai-ui' ) }</span> { failure.hint }
				</p>
			) }
			{ actions && actions.length > 0 && (
				<div className="dxai-actions dxai-phase-error__actions">
					{ actions.map( ( action ) => ( action.href ? (
						<a key={ action.label } className={ 'components-button ' + ( action.primary ? 'is-primary' : 'is-secondary' ) } href={ action.href }>
							{ action.label }
						</a>
					) : (
						<button key={ action.label } type="button" className={ 'components-button ' + ( action.primary ? 'is-primary' : 'is-secondary' ) } onClick={ action.onClick }>
							{ action.label }
						</button>
					) ) ) }
				</div>
			) }
		</div>
	);
}

function announcementFor( plan, progress, crawl, engine ) {
	const id = progress.active;
	if ( ! id ) {
		return '';
	}
	const phase = plan.find( ( p ) => p.id === id );
	const entry = progress.phases[ id ] || {};
	if ( ! phase ) {
		return '';
	}
	if ( id === 'crawl' && crawl && crawl.total > 0 && ! crawl.finishing ) {
		return sprintf(
			/* translators: 1: page number, 2: page count, 3: page name */
			__( 'Building page %1$d of %2$d: %3$s', 'dxai-ui' ),
			Math.min( ( crawl.index || 0 ) + 1, crawl.total ),
			crawl.total,
			crawl.label || crawl.path || ''
		);
	}
	// While a lost answer is looked for, the detail carries a clock that
	// changes every few seconds: announce what is happening once, not the clock.
	if ( entry.watching ) {
		return phase.label + '. ' + __( 'The connection was cut; asking WordPress how the save is going.', 'dxai-ui' );
	}
	// The upload's byte count changes many times a second; announce the phase, not the bytes.
	return phase.label + ( entry.detail && ! entry.meter ? '. ' + entry.detail : '' );
}

/**
 * @param {Object}   props
 * @param {Array}    props.plan     planPhases() — the rows, in order
 * @param {Object}   props.progress progressReducer state
 * @param {Object}   props.crawl    last crawl progress from the server
 * @param {Object}   props.engine   crawl engine
 * @param {Object}   props.failure  describeFailure() + title, or null
 * @param {Array}    props.actions  buttons for the failure
 * @param {boolean}  props.compact  one line of chips instead of the full list
 * @param {Object}   props.headingRef focus target for the card title
 * @param {Object}   props.errorRef   focus target for a failure
 * @param {Element}  props.children  extra content under the list (logs)
 */
export default function ImportProgress( { plan, progress, crawl, engine, failure, actions, compact, headingRef, errorRef, children } ) {
	const running = Boolean( progress.active && progress.phases[ progress.active ]?.state === 'active' );
	const now = useClock( running );
	const liveRef = useRef( '' );
	const [ live, setLive ] = useState( '' );
	const announcement = announcementFor( plan, progress, crawl, engine );

	useEffect( () => {
		if ( announcement && announcement !== liveRef.current ) {
			liveRef.current = announcement;
			setLive( announcement );
		}
	}, [ announcement ] );

	const active = plan.find( ( p ) => p.id === progress.active );
	const activeEntry = active ? progress.phases[ active.id ] : null;
	const failed = plan.find( ( p ) => progress.phases[ p.id ]?.state === 'error' );
	const allDone = ! active && ! failed && plan.every( ( p ) => [ 'done', 'skipped', 'warn' ].includes( progress.phases[ p.id ]?.state ) );

	let headline = __( 'Getting ready…', 'dxai-ui' );
	if ( failed ) {
		headline = sprintf(
			/* translators: %s: the phase that failed */
			__( 'Stopped while %s', 'dxai-ui' ),
			failed.label.charAt( 0 ).toLowerCase() + failed.label.slice( 1 )
		);
	} else if ( active && activeEntry?.state === 'waiting' ) {
		headline = __( 'Ready for your review', 'dxai-ui' );
	} else if ( active ) {
		headline = active.label + '…';
	} else if ( allDone ) {
		headline = __( 'Import finished', 'dxai-ui' );
	}

	const elapsed = workingTime( progress, now );

	if ( compact ) {
		return (
			<nav className="dxai-progress dxai-progress--compact" aria-label={ __( 'Import progress', 'dxai-ui' ) }>
				<ol className="dxai-chips">
					{ plan.map( ( phase ) => {
						const entry = progress.phases[ phase.id ] || { state: 'pending' };
						const time = phaseTime( entry, now );
						return (
							<li key={ phase.id } className={ 'dxai-chipstep is-' + entry.state } aria-current={ entry.state === 'waiting' || entry.state === 'active' ? 'step' : undefined }>
								<span className="dxai-phase__mark" aria-hidden="true" />
								<span className="dxai-chipstep__label">{ phase.label }</span>
								<span className="screen-reader-text">{ ', ' + STATE_TEXT[ entry.state ] }</span>
								{ time && <span className="dxai-chipstep__time">{ time }</span> }
							</li>
						);
					} ) }
				</ol>
			</nav>
		);
	}

	return (
		<section className={ 'dxai-progress' + ( failed ? ' has-error' : '' ) } aria-labelledby="dxai-progress-title">
			<header className="dxai-progress__head">
				<div>
					<p className="dxai-section-kicker">{ __( 'Import progress', 'dxai-ui' ) }</p>
					<h3 id="dxai-progress-title" className="dxai-progress__title" tabIndex={ -1 } ref={ headingRef }>
						{ headline }
					</h3>
				</div>
				{ progress.startedAt > 0 && (
					<p className="dxai-progress__clock">
						<span>{ __( 'Elapsed', 'dxai-ui' ) }</span>
						<strong>{ formatDuration( elapsed ) }</strong>
					</p>
				) }
			</header>
			<ol className="dxai-phases">
				{ plan.map( ( phase, index ) => {
					const entry = progress.phases[ phase.id ] || { state: 'pending' };
					const time = phaseTime( entry, now );
					const isCrawl = phase.id === 'crawl';
					return (
						<li
							key={ phase.id }
							className={ 'dxai-phase is-' + entry.state }
							aria-current={ entry.state === 'active' || entry.state === 'waiting' ? 'step' : undefined }
						>
							<span className="dxai-phase__mark" aria-hidden="true">
								<span className="dxai-phase__num">{ index + 1 }</span>
							</span>
							<div className="dxai-phase__body">
								<div className="dxai-phase__line">
									<span className="dxai-phase__label">{ phase.label }</span>
									{ phase.where && <span className="dxai-phase__where">{ phase.where }</span> }
									<span className="screen-reader-text">{ ', ' + STATE_TEXT[ entry.state ] }</span>
									{ time && <span className="dxai-phase__time">{ time }</span> }
								</div>
								{ entry.detail && ! ( isCrawl && entry.state === 'active' ) && (
									<p className="dxai-phase__detail">{ entry.detail }</p>
								) }
								{ entry.state === 'active' && ! isCrawl && entry.meter && entry.meter.max > 0 && (
									<Meter value={ entry.meter.value } max={ entry.meter.max } label={ phase.label } text={ entry.meter.text } />
								) }
								{ entry.state === 'active' && ! isCrawl && ! entry.meter && <Working label={ phase.label } /> }
								{ /* Not while a lost answer is being looked for: the detail then says what is known. */ }
								{ entry.state === 'active' && ! isCrawl && ! entry.watching && entry.started && now - entry.started > 45000 && (
									<p className="dxai-phase__patience">
										{ now - entry.started > 180000
											? __( 'This is taking longer than usual. The server has not answered yet and nothing has failed; if the host cuts the request off, this screen will say so and what to do next.', 'dxai-ui' )
											: __( 'Still working — the server has not answered yet. Large designs take longer on shared hosting. Keep this tab open.', 'dxai-ui' ) }
									</p>
								) }
								{ isCrawl && ( entry.state === 'active' || entry.state === 'done' || entry.state === 'warn' || entry.state === 'error' ) && (
									<CrawlPanel crawl={ crawl } engine={ engine } entry={ entry } now={ now } />
								) }
								{ entry.link && entry.link.href && entry.state !== 'active' && (
									<a className="dxai-phase__link" href={ entry.link.href }>{ entry.link.label }</a>
								) }
								{ entry.state === 'error' && failure && failure.phase === phase.id && (
									<PhaseError failure={ failure } actions={ actions } titleRef={ errorRef } />
								) }
							</div>
						</li>
					);
				} ) }
			</ol>
			{ children }
			<div className="screen-reader-text" role="status" aria-live="polite" aria-atomic="true">
				{ live }
			</div>
		</section>
	);
}

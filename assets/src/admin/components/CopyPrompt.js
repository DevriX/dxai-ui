import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, SelectControl, Spinner, TextControl, TextareaControl, ToggleControl } from '@wordpress/components';
import './copy-prompt.css';

/*
 * "Words for the pages": the team's prompt for the copy of a page, filled in from the page (its address, what it is
 * about, the page of the old site it was made after). It can be copied into any assistant, or the engine chosen in
 * Settings writes the words: they are listed block by block, and nothing is saved on the page until they are applied.
 */
const base = '/dxai-ui/v1/copy';

const KIND = {
	service: __( 'Service', 'dxai-ui' ),
	location: __( 'Location', 'dxai-ui' ),
	about: __( 'About', 'dxai-ui' ),
	contact: __( 'Contact', 'dxai-ui' ),
	faq: __( 'FAQ', 'dxai-ui' ),
	testimonials: __( 'Reviews', 'dxai-ui' ),
	areas: __( 'Areas', 'dxai-ui' ),
	services: __( 'Services', 'dxai-ui' ),
	privacy: __( 'Legal', 'dxai-ui' ),
	sitemap: __( 'Sitemap', 'dxai-ui' ),
	blog: __( 'Blog', 'dxai-ui' ),
};

/** Plain text of a line of copy (it may carry <strong> and <br>). */
const plain = ( html ) => {
	const d = document.createElement( 'div' );
	d.innerHTML = html;
	return d.textContent || '';
};

function Row( { page, facts, engine, onChange } ) {
	const [ topic, setTopic ] = useState( page.topic );
	const [ reference, setReference ] = useState( page.reference );
	const [ open, setOpen ] = useState( false );
	const [ prompt, setPrompt ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	const [ proposal, setProposal ] = useState( null );
	const [ picked, setPicked ] = useState( {} );
	// Notes on the page: the checks are free; the AI's notes are one request, and what it costs is told first.
	const [ review, setReview ] = useState( null );

	const fields = { topic, reference, facts };

	useEffect( () => {
		setTopic( page.topic );
		setReference( page.reference );
	}, [ page.id, page.topic, page.reference ] );

	function call( action, extra = {} ) {
		return apiFetch( { path: base + '/' + page.id, method: 'POST', data: { action, ...fields, ...extra } } );
	}

	function loadPrompt() {
		return call( 'save' ).then( ( res ) => {
			setPrompt( res.prompt );
			onChange( res );

			return res.prompt;
		} );
	}

	function showPrompt() {
		if ( open ) {
			setOpen( false );

			return;
		}
		setBusy( 'prompt' );
		setError( '' );
		loadPrompt()
			.then( () => setOpen( true ) )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( '' ) );
	}

	function copy() {
		setBusy( 'copy' );
		setError( '' );
		loadPrompt()
			.then( ( text ) => navigator.clipboard.writeText( text ) )
			.then( () => setNote( __( 'The prompt is copied.', 'dxai-ui' ) ) )
			.catch( ( e ) => setError( e.message || __( 'The prompt could not be copied. Open it and copy it by hand.', 'dxai-ui' ) ) )
			.finally( () => setBusy( '' ) );
	}

	function generate() {
		setBusy( 'generate' );
		setError( '' );
		setNote( '' );
		call( 'generate' )
			.then( ( res ) => {
				setProposal( res );
				const on = {};
				res.blocks.forEach( ( b ) => {
					if ( b.changed ) {
						on[ b.id ] = b.after;
					}
				} );
				setPicked( on );
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( '' ) );
	}

	function check( withAi ) {
		setBusy( withAi ? 'notes' : 'check' );
		setError( '' );
		call( 'critique', { ai: withAi } )
			.then( setReview )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( '' ) );
	}

	function apply() {
		setBusy( 'apply' );
		setError( '' );
		call( 'apply', { changes: picked, fingerprint: proposal.fingerprint } )
			.then( ( res ) => {
				setNote( sprintf( /* translators: %d: number of blocks. */ _n( '%d block was rewritten.', '%d blocks were rewritten.', res.applied, 'dxai-ui' ), res.applied ) );
				setProposal( null );
				setPrompt( res.prompt );
				onChange( res );
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( '' ) );
	}

	function revert() {
		setBusy( 'revert' );
		setError( '' );
		call( 'revert' )
			.then( ( res ) => {
				setNote( __( 'The words are back as they were.', 'dxai-ui' ) );
				onChange( res );
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( '' ) );
	}

	const count = proposal ? Object.keys( picked ).length : 0;

	return (
		<li className="dxai-copy__page">
			<div className="dxai-copy__head">
				<strong>{ page.title }</strong>
				<span className={ 'dxai-copy__kind dxai-copy__kind--' + page.kind }>{ KIND[ page.kind ] || page.kind }</span>
				{ page.home && <span className="dxai-copy__kind">{ __( 'Home', 'dxai-ui' ) }</span> }
				<span className="dxai-muted">{ sprintf( /* translators: %d: number of blocks. */ _n( '%d block of text', '%d blocks of text', page.blocks, 'dxai-ui' ), page.blocks ) }</span>
				{ page.edit && <Button variant="link" href={ page.edit }>{ __( 'Edit', 'dxai-ui' ) }</Button> }
			</div>

			<div className="dxai-copy__fields">
				<TextControl
					label={ __( 'The page is about', 'dxai-ui' ) }
					value={ topic }
					onChange={ setTopic }
					onBlur={ () => call( 'save' ).then( onChange ).catch( () => {} ) }
					__nextHasNoMarginBottom
				/>
				<TextControl
					label={ __( 'Page to take inspiration from', 'dxai-ui' ) }
					help={ page.reference_guessed ? __( 'Worked out from the page address; check it.', 'dxai-ui' ) : undefined }
					value={ reference }
					onChange={ setReference }
					onBlur={ () => call( 'save' ).then( onChange ).catch( () => {} ) }
					placeholder="https://"
					__nextHasNoMarginBottom
				/>
			</div>

			<div className="dxai-actions">
				<Button variant="secondary" onClick={ copy } isBusy={ busy === 'copy' } disabled={ !! busy }>
					{ __( 'Copy prompt', 'dxai-ui' ) }
				</Button>
				<Button variant="tertiary" onClick={ showPrompt } disabled={ !! busy }>
					{ open ? __( 'Hide prompt', 'dxai-ui' ) : __( 'Show prompt', 'dxai-ui' ) }
				</Button>
				<Button
					variant="primary"
					onClick={ generate }
					isBusy={ busy === 'generate' }
					disabled={ !! busy || ! engine.ready || page.blocks === 0 }
					title={ engine.ready ? undefined : engine.message }
				>
					{ busy === 'generate' ? __( 'Writing…', 'dxai-ui' ) : __( 'Write with AI', 'dxai-ui' ) }
				</Button>
				<Button variant="tertiary" onClick={ () => check( false ) } isBusy={ busy === 'check' } disabled={ !! busy }>
					{ __( 'Check the page', 'dxai-ui' ) }
				</Button>
				{ page.revert && (
					<Button variant="link" isDestructive onClick={ revert } disabled={ !! busy }>
						{ __( 'Put the old words back', 'dxai-ui' ) }
					</Button>
				) }
			</div>

			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ note && <Notice status="success" onRemove={ () => setNote( '' ) }>{ note }</Notice> }

			{ open && (
				<TextareaControl
					label={ __( 'Prompt', 'dxai-ui' ) }
					value={ prompt }
					readOnly
					rows={ 16 }
					onChange={ () => {} }
					__nextHasNoMarginBottom
				/>
			) }

			{ review && (
				<div className="dxai-copy__review">
					{ review.checks.length === 0 ? (
						<p className="dxai-muted">{ __( 'The checks found nothing to say about this page.', 'dxai-ui' ) }</p>
					) : (
						<Notice status="warning" isDismissible={ false }>
							<strong>{ __( 'What the checks found', 'dxai-ui' ) }</strong>
							<ul>
								{ review.checks.map( ( n, i ) => <li key={ i }>{ n }</li> ) }
							</ul>
						</Notice>
					) }
					{ review.notes && (
						<Notice status="info" isDismissible={ false }>
							<strong>{ __( 'Notes from the AI (nothing was changed)', 'dxai-ui' ) }</strong>
							<ul>
								{ review.notes.map( ( n, i ) => <li key={ i }>{ n }</li> ) }
							</ul>
						</Notice>
					) }
					{ ! review.notes && (
						<div className="dxai-actions">
							<Button
								variant="secondary"
								onClick={ () => check( true ) }
								isBusy={ busy === 'notes' }
								disabled={ !! busy || ! engine.ready }
								title={ engine.ready ? undefined : engine.message }
							>
								{ sprintf(
									/* translators: %s: number of tokens. */
									__( 'Ask the AI for notes (one request, about %s tokens)', 'dxai-ui' ),
									( review.estimate.tokens || 0 ).toLocaleString()
								) }
							</Button>
							<span className="dxai-muted">
								{ review.estimate.cost !== null && review.estimate.cost !== undefined
									? sprintf( /* translators: %s: dollars. */ __( 'About US$%s.', 'dxai-ui' ), Number( review.estimate.cost ).toFixed( 3 ) )
									: __( 'Set the price of a million tokens under "AI" in Pages in the team\'s style to see the cost in dollars.', 'dxai-ui' ) }
							</span>
						</div>
					) }
				</div>
			) }

			{ proposal && (
				<div className="dxai-copy__proposal">
					<p className="dxai-muted">
						{ sprintf(
							/* translators: 1: engine name, 2: number of blocks. */
							_n( '%1$s rewrote %2$d block. Nothing is saved until you apply it.', '%1$s rewrote %2$d blocks. Nothing is saved until you apply it.', proposal.changed, 'dxai-ui' ),
							engine.label || proposal.engine,
							proposal.changed
						) }
					</p>
					{ proposal.flags.length > 0 && (
						<Notice status="warning" isDismissible={ false }>
							<strong>{ __( 'Mismatches it noticed', 'dxai-ui' ) }</strong>
							<ul>
								{ proposal.flags.map( ( f, i ) => <li key={ i }>{ f }</li> ) }
							</ul>
						</Notice>
					) }
					<ul className="dxai-copy__blocks">
						{ proposal.blocks.filter( ( b ) => b.changed ).map( ( b ) => (
							<li key={ b.id }>
								<label>
									<input
										type="checkbox"
										checked={ picked[ b.id ] !== undefined }
										onChange={ ( e ) => {
											const next = { ...picked };
											if ( e.target.checked ) {
												next[ b.id ] = b.after;
											} else {
												delete next[ b.id ];
											}
											setPicked( next );
										} }
									/>
									<span className="dxai-copy__where">{ b.section } · { b.kind }</span>
								</label>
								<del>{ plain( b.before ) }</del>
								<ins>{ plain( b.after ) }</ins>
							</li>
						) ) }
					</ul>
					<div className="dxai-actions">
						<Button variant="primary" onClick={ apply } isBusy={ busy === 'apply' } disabled={ !! busy || count === 0 }>
							{ sprintf( /* translators: %d: number of blocks. */ _n( 'Apply %d block', 'Apply %d blocks', count, 'dxai-ui' ), count ) }
						</Button>
						<Button variant="tertiary" onClick={ () => setProposal( null ) } disabled={ !! busy }>
							{ __( 'Discard', 'dxai-ui' ) }
						</Button>
					</div>
				</div>
			) }
		</li>
	);
}

export default function CopyPrompt() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ facts, setFacts ] = useState( false );

	function load( design = 0 ) {
		apiFetch( { path: base + ( design ? '?design=' + design : '' ) } )
			.then( setData )
			.catch( ( e ) => setError( e.message || __( 'The pages could not be read.', 'dxai-ui' ) ) );
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
	if ( ! data.designs.length ) {
		return null;
	}

	function update( res ) {
		setData( ( d ) => ( { ...d, pages: d.pages.map( ( p ) => ( p.id === res.id ? { ...p, ...res, home: p.home } : p ) ) } ) );
	}

	return (
		<section className="dxai-section dxai-copy" aria-labelledby="dxai-copy-title">
			<div className="dxai-section-head">
				<div>
					<p className="dxai-section-kicker">{ __( 'Your pages', 'dxai-ui' ) }</p>
					<h3 id="dxai-copy-title">{ __( 'Words for the pages', 'dxai-ui' ) }</h3>
					<p className="dxai-muted">
						{ __( 'The prompt for the copy of a page, filled in from the page. Copy it into any assistant, or let the AI engine chosen in Settings write the words. Only the words change: the blocks and their styles stay.', 'dxai-ui' ) }
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

			<ToggleControl
				label={ __( 'Add what the Home says about the company (name, phone, e-mail, current site)', 'dxai-ui' ) }
				checked={ facts }
				onChange={ setFacts }
				__nextHasNoMarginBottom
			/>

			{ ! data.engine.ready && (
				<Notice status="info" isDismissible={ false }>
					{ data.engine.message } { __( 'The prompt can still be copied.', 'dxai-ui' ) }
				</Notice>
			) }

			<ul className="dxai-copy__list">
				{ data.pages.map( ( p ) => (
					<Row key={ p.id } page={ p } facts={ facts } engine={ data.engine } onChange={ update } />
				) ) }
			</ul>
		</section>
	);
}

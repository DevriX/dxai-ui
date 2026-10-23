import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel, store as editorStore } from '@wordpress/editor';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, CheckboxControl, Notice, Spinner } from '@wordpress/components';

/*
 * "Make the rest from this page", in the editor of the page a person has just finished: the team designs one service (or city)
 * page by hand and copies it onto the others, then rewrites every copy. This makes the copies (Network\Page_Copies, the
 * /copies routes): the page's build and look kept, the site's shared sections word for word, the page's own words written for
 * each — one page per request, so a host that ends a request after a minute never cuts one in half.
 */

const PANEL = 'dxai-ui-make-the-rest';

/* How the editor names a plugin's panel when it keeps whether it is open: the plugin's name and the panel's. */
const PANEL_ID = PANEL + '/' + PANEL;

/* Opened from the list of pages: read now, the editor takes the address's own words off it as it starts. */
const ASKED = /[?&]dxai-copies=1\b/.test( window.location.search );

const STATE = {
	empty: __( 'empty', 'dxai-ui' ),
	copy: __( 'a copy of this page', 'dxai-ui' ),
	made: __( 'made from this page before', 'dxai-ui' ),
	menu: __( 'in the menu, no page yet: it is made', 'dxai-ui' ),
	own: __( 'has its own content', 'dxai-ui' ),
};

function Result( { r, onBack } ) {
	const [ busy, setBusy ] = useState( false );
	const [ back, setBack ] = useState( '' );
	const giveBack = () => {
		setBusy( true );
		apiFetch( { path: '/dxai-ui/v1/copies/' + r.id + '/give-back', method: 'POST' } )
			.then( () => {
				setBack( __( 'Given back.', 'dxai-ui' ) );
				onBack();
			} )
			.catch( ( e ) => setBack( e.message ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<li className="dxai-copies__result">
			<strong>{ r.title }</strong>{ ' ' }
			{ r.words === 'ai'
				? __( 'written', 'dxai-ui' )
				: sprintf( /* translators: %d: lines a person writes */ _n( '%d line to write', '%d lines to write', r.places, 'dxai-ui' ), r.places ) }
			{ r.pictures > 0 && (
				<span>{ ' · ' + sprintf( /* translators: %d: pictures */ _n( '%d picture is this page\'s', '%d pictures are this page\'s', r.pictures, 'dxai-ui' ), r.pictures ) }</span>
			) }
			<br />
			<a href={ r.link } target="_blank" rel="noreferrer">{ __( 'View', 'dxai-ui' ) }</a>{ ' · ' }
			<a href={ r.edit }>{ __( 'Edit', 'dxai-ui' ) }</a>{ ' · ' }
			<Button variant="link" onClick={ giveBack } disabled={ busy || back !== '' }>{ __( 'Give back', 'dxai-ui' ) }</Button>
			{ back && <span>{ ' ' + back }</span> }
			{ r.quality && (
				<p className={ 'dxai-copies__quality is-' + r.quality.grade }>
					{ r.quality.grade === 'good' ? __( 'Measures up to the team’s pages.', 'dxai-ui' ) : __( 'To look at:', 'dxai-ui' ) }
					{ r.quality.grade !== 'good' && ' ' + r.quality.checks.filter( ( c ) => c.state !== 'ok' ).map( ( c ) => c.text ).join( '; ' ) }
				</p>
			) }
			{ r.error && <Notice status="warning" isDismissible={ false }>{ r.error }</Notice> }
			{ r.flags && r.flags.length > 0 && (
				<ul className="dxai-copies__flags">
					{ r.flags.map( ( f, i ) => <li key={ i }>{ f }</li> ) }
				</ul>
			) }
		</li>
	);
}

function MakeTheRest() {
	const { id, type, dirty, saving, opened } = useSelect( ( select ) => {
		const ed = select( editorStore );
		return {
			id: ed.getCurrentPostId(),
			type: ed.getCurrentPostType(),
			dirty: ed.isEditedPostDirty(),
			saving: ed.isSavingPost(),
			opened: ed.isEditorPanelOpened( PANEL_ID ),
		};
	}, [] );
	const { toggleEditorPanelOpened } = useDispatch( editorStore );
	const [ info, setInfo ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ chosen, setChosen ] = useState( {} );
	const [ run, setRun ] = useState( null );
	const [ reload, setReload ] = useState( 0 );

	useEffect( () => {
		if ( type !== 'page' || ! id || saving ) {
			return;
		}
		apiFetch( { path: '/dxai-ui/v1/copies/' + id } )
			.then( ( data ) => {
				setInfo( data );
				const pick = {};
				( data.targets || [] ).forEach( ( t, i ) => ( pick[ i ] = !! t.chosen ) );
				setChosen( pick );
			} )
			.catch( ( e ) => setError( e.message ) );
	}, [ id, type, saving, reload ] );

	// Opened from the list of pages ("Make the rest from this page"): the panel opens by itself.
	useEffect( () => {
		if ( info && info.can && ! opened && ASKED ) {
			toggleEditorPanelOpened( PANEL_ID );
		}
	}, [ info && info.can ] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( type !== 'page' ) {
		return null;
	}
	if ( error ) {
		return null;
	}
	if ( ! info ) {
		return null;
	}
	if ( ! info.can ) {
		return null;
	}

	const targets = info.targets || [];
	const picked = targets.filter( ( t, i ) => chosen[ i ] );
	const shared = ( info.sections || [] ).filter( ( s ) => s.what !== 'page' ).length;
	const own = ( info.sections || [] ).filter( ( s ) => s.what === 'page' ).length;
	const busy = run && run.at < run.of;

	const start = async () => {
		const list = targets.filter( ( t, i ) => chosen[ i ] );
		const state = { at: 0, of: list.length, now: '', done: [] };
		setRun( { ...state } );
		for ( const t of list ) {
			state.now = t.title;
			setRun( { ...state } );
			try {
				const r = await apiFetch( {
					path: '/dxai-ui/v1/copies/' + id,
					method: 'POST',
					data: { target: { id: t.id, title: t.title, link: t.link } },
				} );
				state.done.push( r );
			} catch ( e ) {
				state.done.push( { id: t.id, title: t.title, link: t.link, edit: '', words: 'outline', places: 0, pictures: 0, flags: [], error: e.message } );
			}
			state.at++;
			setRun( { ...state } );
		}
		state.now = '';
		setRun( { ...state } );
		setReload( ( n ) => n + 1 );
	};

	return (
		<PluginDocumentSettingPanel name={ PANEL } title={ info.kind === 'location' ? __( 'Make the other cities from this page', 'dxai-ui' ) : __( 'Make the other services from this page', 'dxai-ui' ) } className="dxai-copies">
			{ info.copy_of > 0 && (
				<p>
					{ sprintf( /* translators: %s: the page this one was made from */ __( 'This page was made from “%s”.', 'dxai-ui' ), info.copy_of_title || '#' + info.copy_of ) }{ ' ' }
					{ info.give_back && (
						<Button
							variant="link"
							onClick={ () => apiFetch( { path: '/dxai-ui/v1/copies/' + id + '/give-back', method: 'POST' } ).then( () => window.location.reload() ).catch( ( e ) => setError( e.message ) ) }
						>
							{ __( 'Give it back', 'dxai-ui' ) }
						</Button>
					) }
				</p>
			) }
			<p>
				{ sprintf(
					/* translators: 1: sections written for each page, 2: sections kept as they are */
					__( 'Each page gets this page\'s build and look. %1$d sections are written for each page; %2$d stay as they are — the reviews, what the site says the same way on its other pages (the form, the call to action), the list of the other pages, the pictures.', 'dxai-ui' ),
					own,
					shared
				) }
			</p>
			<p>
				{ info.words.ai
					? sprintf( /* translators: %s: the AI engine */ __( 'Words: written by %s with the team\'s prompt, from the client\'s old page for the same service when the old site has one.', 'dxai-ui' ), info.words.engine )
					: __( 'No AI engine in DX › Settings: each page gets this page\'s headings with its own name, and its own lines are left empty for you to write — this page\'s words show as the hint in the editor, never on the page.', 'dxai-ui' ) }
			</p>
			{ dirty && <Notice status="info" isDismissible={ false }>{ __( 'Save the page first: the copies are made from what is saved.', 'dxai-ui' ) }</Notice> }
			{ targets.length === 0 && <p>{ __( 'There are no other pages of this kind next to it, and the menu names none.', 'dxai-ui' ) }</p> }
			{ targets.map( ( t, i ) => (
				<CheckboxControl
					key={ i }
					label={ t.title }
					help={ STATE[ t.state ] || t.state }
					checked={ !! chosen[ i ] }
					disabled={ !! busy }
					onChange={ ( v ) => setChosen( { ...chosen, [ i ]: v } ) }
					__nextHasNoMarginBottom
				/>
			) ) }
			{ targets.length > 0 && (
				<Button variant="primary" onClick={ start } disabled={ picked.length === 0 || dirty || !! busy }>
					{ sprintf( /* translators: %d: pages */ _n( 'Make %d page', 'Make %d pages', picked.length, 'dxai-ui' ), picked.length ) }
				</Button>
			) }
			{ busy && (
				<p>
					<Spinner />
					{ sprintf( /* translators: 1: pages done, 2: pages in all, 3: the page being made */ __( '%1$d of %2$d made; now %3$s…', 'dxai-ui' ), run.at, run.of, run.now ) }
				</p>
			) }
			{ run && run.done.length > 0 && (
				<ul className="dxai-copies__results">
					{ run.done.map( ( r, i ) => <Result key={ i } r={ r } onBack={ () => setReload( ( n ) => n + 1 ) } /> ) }
				</ul>
			) }
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( PANEL, { render: MakeTheRest } );

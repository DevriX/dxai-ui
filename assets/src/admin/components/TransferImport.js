import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, CheckboxControl, Notice, Spinner } from '@wordpress/components';
import { postForm } from '../upload';
import { CHROME_INSTALL, chromeFacts, formatBytes } from '../importFlow';
import ChromeChoice from './ChromeChoice';
import TransferResult from './TransferResult';
import { readPackage } from './TransferPackage';
import './import.css';

/*
 * "Import package": a .dxai.zip exported from another site's Library (or its
 * Pages list, or `wp dxai-ui export`) becomes a page here, with its design
 * stylesheet, fonts, images, parts and patterns.
 *
 * The decision asked is the header and footer — the question the ZIP import
 * asks (ChromeChoice), with the same meaning (Chrome_Choice on the server):
 * keep this site's menus and widgets, the page keeping the design's header
 * and footer as template parts, or install the design's into Appearance ›
 * Menus and Appearance › Widgets. The answer offered first is the server's
 * automatic one for a one-page import (keep); on a theme that draws the whole
 * site's header and footer from those menus and areas, and when another
 * design's are the site's now, the install option says so before anything is
 * uploaded — and so does a menu location a person's own menu holds, which an
 * install leaves as it is.
 *
 * A page the import creates is a draft unless "Publish" is ticked, so a
 * package from someone else is looked at before it is public.
 *
 * A long import runs in steps (the server answers 202 `continue` with a
 * token until it is done); a step that fails can be continued, and a failed
 * upload imported again — what was already imported is reused.
 */
export default function TransferImport( { info, infoError, onImported } ) {
	const [ file, setFile ] = useState( null );
	const [ pkg, setPkg ] = useState( null );
	const [ drag, setDrag ] = useState( false );
	const [ chrome, setChrome ] = useState( '' );
	const [ publish, setPublish ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ progress, setProgress ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ token, setToken ] = useState( '' );
	const [ result, setResult ] = useState( null );
	const input = useRef( null );
	const done = useRef( null );
	// The job the server keeps between steps, as the promise chain sees it now.
	const live = useRef( '' );

	const facts = useMemo(
		() => chromeFacts( info || {}, {
			title: pkg && ! pkg.invalid ? pkg.title : '',
			source: pkg && ! pkg.invalid ? pkg.archive : '',
			scope: 'page',
			scoped: true,
			structures: pkg && ! pkg.invalid
				? [ { type: 'header', included: pkg.header !== 'none' }, { type: 'footer', included: pkg.footer !== 'none' } ]
				: undefined,
		} ),
		[ info, pkg ]
	);
	const choice = chrome || facts.defaultChoice;
	const maxUpload = Number( ( info && info.max_upload ) || 0 );
	const tooBig = file && maxUpload > 0 && file.size > maxUpload;
	const kept = Array.isArray( info && info.menu_locations_kept ) ? info.menu_locations_kept : [];
	const hasHeader = ! pkg || pkg.invalid || pkg.header !== 'none';

	useEffect( () => {
		if ( result && done.current ) {
			const heading = done.current.querySelector( '.dxai-done__title' );
			if ( heading ) {
				heading.focus();
			}
		}
	}, [ result ] );

	function keep( value ) {
		live.current = value;
		setToken( value );
	}

	function pick( picked ) {
		setError( '' );
		keep( '' );
		setPkg( null );
		if ( picked && ! /\.zip$/i.test( picked.name ) ) {
			setError( __( 'Choose a .dxai.zip package exported by DXAI-UI.', 'dxai-ui' ) );
			return;
		}
		setFile( picked || null );
		if ( picked ) {
			readPackage( picked ).then( ( summary ) => setPkg( summary ) );
		}
	}

	function onDrop( event ) {
		event.preventDefault();
		setDrag( false );
		pick( event.dataTransfer.files[ 0 ] );
	}

	function finished( res ) {
		setResult( res );
		setFile( null );
		setPkg( null );
		keep( '' );
		if ( input.current ) {
			input.current.value = '';
		}
		if ( onImported ) {
			onImported( res );
		}
	}

	// A lost answer: what the person can do about it, in words.
	function failure( err, resumable ) {
		const status = Number( ( err && err.data && err.data.status ) || 0 );
		const message = ( err && err.message ) || __( 'The import failed.', 'dxai-ui' );
		if ( status === 0 || status >= 500 || ( err && ( err.code === 'invalid_json' || err.code === 'fetch_error' ) ) ) {
			return message + ' ' + ( resumable
				? __( 'The import waits on the server: continue it, and it goes on where it stopped.', 'dxai-ui' )
				: __( 'Nothing is lost: import the same package again and it continues where it stopped — the files already added to the media library are reused, and the page is updated in place.', 'dxai-ui' ) );
		}
		return message;
	}

	// One step after another, until the server has written the page.
	function steps( res ) {
		if ( ! res || res.status !== 'continue' || ! res.token ) {
			finished( res );
			return Promise.resolve();
		}
		keep( res.token );
		setProgress( { phase: 'media', done: Number( res.media && res.media.done ) || 0, total: Number( res.media && res.media.total ) || 0 } );
		return apiFetch( { path: '/dxai-ui/v1/transfer/import/continue', method: 'POST', data: { token: res.token } } ).then( steps );
	}

	function run( start ) {
		setBusy( true );
		setError( '' );
		start()
			.then( steps )
			.catch( ( err ) => {
				if ( Number( err && err.data && err.data.status ) === 404 ) {
					// The job is gone (finished, or left too long): only a new import helps.
					keep( '' );
				}
				setError( failure( err, Boolean( live.current ) ) );
			} )
			.finally( () => {
				setBusy( false );
				setProgress( null );
			} );
	}

	function submit() {
		if ( ! file || busy ) {
			return;
		}
		const body = new window.FormData();
		body.append( 'package', file );
		body.append( 'chrome', choice );
		body.append( 'publish', publish ? '1' : '0' );
		keep( '' );
		setProgress( { phase: 'upload', loaded: 0, total: file.size } );
		run( () => postForm( 'transfer/import', body, ( p ) => setProgress( ( prev ) => ( p.sent ? { phase: 'import' } : { ...( prev || {} ), phase: 'upload', loaded: p.loaded, total: p.total || file.size } ) ) ) );
	}

	function resume() {
		if ( ! token || busy ) {
			return;
		}
		setProgress( { phase: 'media', done: 0, total: 0 } );
		run( () => apiFetch( { path: '/dxai-ui/v1/transfer/import/continue', method: 'POST', data: { token } } ) );
	}

	function reset() {
		setResult( null );
		setError( '' );
	}

	let status = '';
	if ( busy && progress ) {
		if ( progress.phase === 'upload' ) {
			status = sprintf(
				/* translators: 1: bytes sent, 2: total bytes */
				__( 'Uploading %1$s of %2$s…', 'dxai-ui' ),
				formatBytes( progress.loaded || 0 ),
				formatBytes( progress.total || ( file ? file.size : 0 ) )
			);
		} else if ( progress.phase === 'media' && progress.total > 0 ) {
			status = sprintf(
				/* translators: 1: files done, 2: files in the package */
				__( 'Adding the images and fonts to the media library: %1$d of %2$d…', 'dxai-ui' ),
				progress.done,
				progress.total
			);
		} else {
			status = __( 'Uploaded. Importing the page, its media, header and footer…', 'dxai-ui' );
		}
	}

	return (
		<section className="dxai-library-group dxai-section" aria-labelledby="dxai-transfer-title" ref={ done }>
			<div className="dxai-section-head">
				<div>
					<p className="dxai-section-kicker">{ __( 'Move a page between sites', 'dxai-ui' ) }</p>
					<h3 id="dxai-transfer-title">{ __( 'Import package', 'dxai-ui' ) }</h3>
				</div>
			</div>
			<p className="dxai-muted">
				{ __( 'A package is a converted page exported with its styles: its blocks, design stylesheet and script, fonts, images, template parts and patterns, and its header and footer. Copying blocks from one editor into another site carries none of that — export the page on the site it was built on (Library › Export, or Pages › Export with styles) and import the file here.', 'dxai-ui' ) }
			</p>

			{ infoError && <Notice status="error" isDismissible={ false }>{ infoError }</Notice> }

			{ result && <TransferResult result={ result } onReset={ reset } /> }

			{ ! result && ! infoError && (
				<>
					<div
						className={ 'dxai-drop' + ( drag ? ' is-drag' : '' ) }
						onDragOver={ ( event ) => {
							event.preventDefault();
							setDrag( true );
						} }
						onDragLeave={ () => setDrag( false ) }
						onDrop={ onDrop }
					>
						<span className="dxai-drop-mark" aria-hidden="true" />
						<p>{ __( 'Drop a .dxai.zip package here', 'dxai-ui' ) }</p>
						<em className="dxai-drop-hint">{ __( 'Exported by DXAI-UI on another site', 'dxai-ui' ) }</em>
						<label className="dxai-file">
							<input
								ref={ input }
								type="file"
								accept=".zip,application/zip"
								onChange={ ( event ) => pick( event.target.files[ 0 ] ) }
							/>
							{ file ? file.name + ' · ' + formatBytes( file.size ) : __( 'or browse your files', 'dxai-ui' ) }
						</label>
					</div>

					{ pkg && ! pkg.invalid && (
						<p className="dxai-muted">
							{ sprintf(
								/* translators: 1: page title, 2: source site, 3: page id there, 4: number of media files */
								_n( '“%1$s” from %2$s (page %3$d there), with %4$d image or font file.', '“%1$s” from %2$s (page %3$d there), with %4$d image and font files.', pkg.media, 'dxai-ui' ),
								pkg.title,
								pkg.siteUrl,
								pkg.pageId,
								pkg.media
							) }
						</p>
					) }
					{ pkg && pkg.invalid && (
						<Notice status="warning" isDismissible={ false }>
							{ __( 'This ZIP is not a page package exported by DXAI-UI (its manifest.json is of another kind), so the import will refuse it. To import a Lovable or Claude Design export, use Convert instead.', 'dxai-ui' ) }
						</Notice>
					) }

					{ tooBig && (
						<Notice status="warning" isDismissible={ false }>
							{ sprintf(
								/* translators: 1: file size, 2: the server's upload limit */
								__( 'This package is %1$s, and this server accepts uploads up to %2$s, so the upload will be refused. Import it from the command line instead, where there is no upload limit: copy the file to the server and run `wp dxai-ui import <file> --user=<admin>`.', 'dxai-ui' ),
								formatBytes( file.size ),
								formatBytes( maxUpload )
							) }
						</Notice>
					) }

					<ChromeChoice facts={ facts } value={ choice } onChange={ setChrome } stage="transfer" />
					{ choice === CHROME_INSTALL && hasHeader && kept.length > 0 && (
						<Notice status="warning" isDismissible={ false }>
							<ul>
								{ kept.map( ( row ) => (
									<li key={ row.location }>
										{ sprintf(
											/* translators: 1: menu location, 2: the person's menu there */
											__( '%1$s holds your menu “%2$s”. Installing leaves it there, so the header — the site’s, and this page’s — shows “%2$s” in the design’s look, not the design’s own links. To show the design’s, assign its menu to %1$s afterwards in Appearance › Menus › Manage Locations.', 'dxai-ui' ),
											row.label || row.location,
											row.menu
										) }
									</li>
								) ) }
							</ul>
						</Notice>
					) }

					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __( 'Publish the page right away', 'dxai-ui' ) }
						help={ __( 'Off: a page this import creates is a draft, to preview before it is public. A page this package created here before keeps the status it has.', 'dxai-ui' ) }
						checked={ publish }
						onChange={ setPublish }
					/>

					{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }

					<div className="dxai-actions">
						<Button variant="primary" onClick={ submit } disabled={ ! file || busy } isBusy={ busy && ! token }>
							{ __( 'Import package', 'dxai-ui' ) }
						</Button>
						{ token && ! busy && error && (
							<Button variant="secondary" onClick={ resume }>
								{ __( 'Continue the import', 'dxai-ui' ) }
							</Button>
						) }
						{ busy && <Spinner /> }
					</div>
					<p className="dxai-muted" role="status" aria-live="polite">{ status }</p>
				</>
			) }
		</section>
	);
}

import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, Spinner, TextControl } from '@wordpress/components';
import TransferExport, { loadTransferInfo } from '../components/TransferExport';
import TransferImport from '../components/TransferImport';
import SitePages from '../components/SitePages';
import ThemeColors from '../components/ThemeColors';
import NativeBlocks from '../components/NativeBlocks';

function Group( { title, items, empty, onRestore, exportUrl } ) {
	return (
		<section className="dxai-library-group dxai-section">
			<div className="dxai-section-head">
				<div>
					<p className="dxai-section-kicker">{ __( 'Collection', 'dxai-ui' ) }</p>
					<h3>{ title }</h3>
				</div>
				<span className="dxai-pill">{ ( items || [] ).length }</span>
			</div>
			{ ( items || [] ).length === 0 ? (
				<div className="dxai-empty-state">
					<strong>{ __( 'Nothing here yet', 'dxai-ui' ) }</strong>
					<p className="dxai-muted">{ empty }</p>
				</div>
			) : (
				<div className="dxai-card-grid">
					{ items.map( ( item ) => (
						<article className="dxai-card dxai-card--library" key={ ( item.type || 'item' ) + '-' + item.id }>
							<div className="dxai-card-top">
								<span className="dxai-pill">{ item.type || 'pattern' }</span>
								<span className="dxai-card-id">ID { item.id }</span>
							</div>
							<strong>{ item.title }</strong>
							<p className="dxai-muted">{ __( 'Saved in your DXAI workspace and ready to open, edit, or restore.', 'dxai-ui' ) }</p>
							<div className="dxai-actions">
								{ item.view && <Button variant="link" href={ item.view }>{ __( 'View', 'dxai-ui' ) }</Button> }
								{ item.edit && <Button variant="link" href={ item.edit }>{ __( 'Edit', 'dxai-ui' ) }</Button> }
								{ exportUrl && item.type === 'page' && <TransferExport url={ exportUrl( item ) } title={ item.title } /> }
								{ onRestore && item.type === 'dxai_conversion' && (
									<Button variant="secondary" onClick={ () => onRestore( item ) }>
										{ __( 'Restore', 'dxai-ui' ) }
									</Button>
								) }
							</div>
						</article>
					) ) }
				</div>
			) }
		</section>
	);
}

export default function LibraryScreen() {
	const [ library, setLibrary ] = useState( null );
	const [ query, setQuery ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ message, setMessage ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ transfer, setTransfer ] = useState( null );
	const [ transferError, setTransferError ] = useState( '' );

	function loadTransfer() {
		loadTransferInfo()
			.then( ( res ) => {
				setTransfer( res );
				setTransferError( '' );
			} )
			.catch( ( err ) => setTransferError( ( err && err.message ) || __( 'Export and import are not available.', 'dxai-ui' ) ) );
	}

	function load() {
		setError( '' );
		apiFetch( { path: '/dxai-ui/v1/patterns' } )
			.then( ( res ) => setLibrary( {
				patterns: res.patterns || [],
				conversions: res.conversions || [],
				pages: res.pages || [],
				template_parts: res.template_parts || [],
				navigations: res.navigations || [],
			} ) )
			.catch( ( err ) => setError( err.message || __( 'Unable to load library.', 'dxai-ui' ) ) );
	}

	useEffect( () => {
		load();
		loadTransfer();
	}, [] );

	function matches( item ) {
		const hay = ( ( item.title || '' ) + ' ' + ( item.type || '' ) ).toLowerCase();
		return hay.includes( query.trim().toLowerCase() );
	}

	const filtered = useMemo( () => {
		if ( ! library ) {
			return null;
		}
		if ( ! query.trim() ) {
			return library;
		}
		return {
			pages: library.pages.filter( matches ),
			patterns: library.patterns.filter( matches ),
			template_parts: library.template_parts.filter( matches ),
			navigations: library.navigations.filter( matches ),
			conversions: library.conversions.filter( matches ),
		};
	}, [ library, query ] );

	function restore( item ) {
		/*
		 * Restoring runs the whole import again from the snapshot: the design's
		 * page is overwritten and published, and a snapshot that installed the
		 * site's header and footer installs them again, over the site's menus
		 * and footer widgets. So it is asked first, not done on one click next
		 * to View and Edit.
		 */
		const sure = window.confirm( sprintf(
			/* translators: %s: the conversion's title. */
			__( 'Restore “%s”?\n\nIts page is written again from this snapshot and published. Changes made to the page since are replaced; they stay in its revisions. If this conversion installed the site’s header and footer, they are installed again, replacing the site’s menus and footer widgets on every page.', 'dxai-ui' ),
			item.title || __( '(no title)', 'dxai-ui' )
		) );
		if ( ! sure ) {
			return;
		}
		const id = item.id;
		setBusy( true );
		setError( '' );
		setMessage( '' );
		apiFetch( {
			path: '/dxai-ui/v1/restore',
			method: 'POST',
			data: { conversion_id: id },
		} )
			.then( () => {
				setMessage( __( 'Conversion restored.', 'dxai-ui' ) );
				load();
			} )
			.catch( ( err ) => setError( err.message || __( 'Restore failed.', 'dxai-ui' ) ) )
			.finally( () => setBusy( false ) );
	}

	const empty = library && ! library.pages.length && ! library.conversions.length && ! library.patterns.length;
	const stats = library ? [
		{ label: __( 'Pages', 'dxai-ui' ), value: library.pages.length },
		{ label: __( 'Patterns', 'dxai-ui' ), value: library.patterns.length },
		{ label: __( 'Menus', 'dxai-ui' ), value: library.navigations.length },
		{ label: __( 'Revisions', 'dxai-ui' ), value: library.conversions.length },
	] : [];

	return (
		<div className="dxai-screen">
			<div className="dxai-screen-intro">
				<ul className="dxai-stats">
					{ stats.map( ( item ) => (
						<li key={ item.label }>
							<strong>{ item.value }</strong>
							{ item.label }
						</li>
					) ) }
				</ul>
			</div>
			<div className="dxai-panel dxai-panel--stack">
				{ message && <Notice status="success" isDismissible={ false }>{ message }</Notice> }
				{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
				{ ! library && ! error && <Spinner /> }
				{ library && (
					<>
						<div className="dxai-toolbar-card">
							<TextControl
								label={ __( 'Search the workspace', 'dxai-ui' ) }
								value={ query }
								onChange={ setQuery }
								placeholder={ __( 'Search pages, patterns, menus, conversions…', 'dxai-ui' ) }
							/>
						</div>
						{ empty && (
							<div className="dxai-card dxai-empty-state">
								<strong>{ __( 'Nothing converted yet.', 'dxai-ui' ) }</strong>
								<p className="dxai-muted">{ __( 'Run your first conversion and the resulting pages, patterns, and revisions will appear here.', 'dxai-ui' ) }</p>
								<Button variant="primary" href="admin.php?page=dxai-ui">{ __( 'Go to Convert', 'dxai-ui' ) }</Button>
							</div>
						) }
						<SitePages />
						<ThemeColors />
						<NativeBlocks />
						<Group
							title={ __( 'Generated pages', 'dxai-ui' ) }
							items={ filtered.pages }
							empty={ __( 'No generated pages yet.', 'dxai-ui' ) }
							exportUrl={ transfer ? ( item ) => transfer.links[ item.id ] || '' : null }
						/>
						<TransferImport
							info={ transfer }
							infoError={ transferError }
							onImported={ () => {
								load();
								loadTransfer();
							} }
						/>
						<Group title={ __( 'Synced patterns', 'dxai-ui' ) } items={ filtered.patterns } empty={ __( 'No patterns yet.', 'dxai-ui' ) } />
						<Group title={ __( 'Headers / footers', 'dxai-ui' ) } items={ filtered.template_parts } empty={ __( 'No template parts yet.', 'dxai-ui' ) } />
						<Group title={ __( 'Menus', 'dxai-ui' ) } items={ filtered.navigations } empty={ __( 'No menus yet.', 'dxai-ui' ) } />
						<Group
							title={ __( 'Conversions', 'dxai-ui' ) }
							items={ filtered.conversions }
							empty={ __( 'No conversion snapshots yet.', 'dxai-ui' ) }
							onRestore={ busy ? null : restore }
						/>
					</>
				) }
			</div>
		</div>
	);
}

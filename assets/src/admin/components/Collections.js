import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, SearchControl, Spinner } from '@wordpress/components';
import TransferExport, { loadTransferInfo } from './TransferExport';

/*
 * What the imports made for the whole site, as collections: every generated page (with its export), the synced patterns,
 * the headers and footers kept as template parts, the menus, and the conversion snapshots (with restore). These were the
 * bottom of the Library screen; they are on the Site screen now, since none of them belongs to one design.
 */

const ROWS_SHOWN = 20;

/** A collection as a compact list: the first twenty, the rest on a click. A site has hundreds of generated pages. */
function Group( { title, items, empty, onRestore, exportUrl } ) {
	const [ all, setAll ] = useState( false );
	const list = items || [];
	const shown = all || list.length <= ROWS_SHOWN + 2 ? list : list.slice( 0, ROWS_SHOWN );
	return (
		<section className="dxui-library-group dxui-section">
			<div className="dxui-section-head">
				<div>
					<h3>{ title }</h3>
				</div>
				<span className="dxui-pill">{ list.length }</span>
			</div>
			{ list.length === 0 ? (
				<p className="dxui-muted">{ empty }</p>
			) : (
				<table className="wp-list-table widefat fixed striped dxui-collection">
					<thead>
						<tr>
							<th scope="col">{ __( 'Title', 'dx-ui' ) }</th>
							<th scope="col" className="dxui-collection__type">{ __( 'Type', 'dx-ui' ) }</th>
							<th scope="col" className="dxui-collection__id">ID</th>
							<th scope="col" className="dxui-collection__actions">{ __( 'Actions', 'dx-ui' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ shown.map( ( item ) => (
							<tr key={ ( item.type || 'item' ) + '-' + item.id }>
								<td><strong>{ item.title || __( '(no title)', 'dx-ui' ) }</strong></td>
								<td className="dxui-collection__type">{ item.type || 'pattern' }</td>
								<td className="dxui-collection__id">{ item.id }</td>
								<td className="dxui-collection__actions">
									{ item.view && <a href={ item.view }>{ __( 'View', 'dx-ui' ) }</a> }
									{ item.view && item.edit && ' · ' }
									{ item.edit && <a href={ item.edit }>{ __( 'Edit', 'dx-ui' ) }</a> }
									{ exportUrl && item.type === 'page' && exportUrl( item ) && <> · <TransferExport url={ exportUrl( item ) } title={ item.title } /></> }
									{ onRestore && item.type === 'dxui_conversion' && (
										<> · <Button variant="link" onClick={ () => onRestore( item ) }>{ __( 'Restore', 'dx-ui' ) }</Button></>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			{ shown.length < list.length && (
				<p className="dxui-collection__more">
					<Button variant="link" onClick={ () => setAll( true ) }>
						{ /* translators: %d: number of items. */ sprintf( __( 'Show all %d', 'dx-ui' ), list.length ) }
					</Button>
				</p>
			) }
		</section>
	);
}

/** Ask before a restore: it writes the page again and may install the header and footer over the site's. */
export function confirmRestore( item ) {
	return window.confirm( sprintf(
		/* translators: %s: the conversion's title. */
		__( 'Restore “%s”?\n\nIts page is written again from this snapshot and published. Changes made to the page since are replaced; they stay in its revisions. If this conversion installed the site’s header and footer, they are installed again, replacing the site’s menus and footer widgets on every page.', 'dx-ui' ),
		item.title || __( '(no title)', 'dx-ui' )
	) );
}

export default function Collections() {
	const [ library, setLibrary ] = useState( null );
	const [ query, setQuery ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ message, setMessage ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ transfer, setTransfer ] = useState( null );

	function load() {
		setError( '' );
		apiFetch( { path: '/dx-ui/v1/patterns' } )
			.then( ( res ) => setLibrary( {
				patterns: res.patterns || [],
				conversions: res.conversions || [],
				pages: res.pages || [],
				template_parts: res.template_parts || [],
				navigations: res.navigations || [],
			} ) )
			.catch( ( err ) => setError( err.message || __( 'The collections could not be read.', 'dx-ui' ) ) );
		loadTransferInfo().then( setTransfer ).catch( () => setTransfer( null ) );
	}

	useEffect( () => {
		load();
	}, [] );

	const filtered = useMemo( () => {
		if ( ! library ) {
			return null;
		}
		const q = query.trim().toLowerCase();
		if ( ! q ) {
			return library;
		}
		const matches = ( item ) => ( ( item.title || '' ) + ' ' + ( item.type || '' ) ).toLowerCase().includes( q );
		return {
			pages: library.pages.filter( matches ),
			patterns: library.patterns.filter( matches ),
			template_parts: library.template_parts.filter( matches ),
			navigations: library.navigations.filter( matches ),
			conversions: library.conversions.filter( matches ),
		};
	}, [ library, query ] );

	function restore( item ) {
		if ( ! confirmRestore( item ) ) {
			return;
		}
		setBusy( true );
		setError( '' );
		setMessage( '' );
		apiFetch( { path: '/dx-ui/v1/restore', method: 'POST', data: { conversion_id: item.id } } )
			.then( () => {
				setMessage( __( 'Conversion restored.', 'dx-ui' ) );
				load();
			} )
			.catch( ( err ) => setError( err.message || __( 'Restore failed.', 'dx-ui' ) ) )
			.finally( () => setBusy( false ) );
	}

	return (
		<div className="dxui-collections">
			{ message && <Notice status="success" isDismissible={ false }>{ message }</Notice> }
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! library && ! error && <Spinner /> }
			{ filtered && (
				<>
					<div className="dxui-collections__search">
						<SearchControl
							label={ __( 'Search the collections', 'dx-ui' ) }
							value={ query }
							onChange={ setQuery }
							placeholder={ __( 'Search pages, patterns, menus, conversions…', 'dx-ui' ) }
							__nextHasNoMarginBottom
						/>
					</div>
					<Group
						title={ __( 'Generated pages', 'dx-ui' ) }
						items={ filtered.pages }
						empty={ __( 'No generated pages yet.', 'dx-ui' ) }
						exportUrl={ transfer ? ( item ) => transfer.links[ item.id ] || '' : null }
					/>
					<Group title={ __( 'Synced patterns', 'dx-ui' ) } items={ filtered.patterns } empty={ __( 'No patterns yet.', 'dx-ui' ) } />
					<Group title={ __( 'Headers / footers', 'dx-ui' ) } items={ filtered.template_parts } empty={ __( 'No template parts yet.', 'dx-ui' ) } />
					<Group title={ __( 'Menus', 'dx-ui' ) } items={ filtered.navigations } empty={ __( 'No menus yet.', 'dx-ui' ) } />
					<Group
						title={ __( 'Conversions', 'dx-ui' ) }
						items={ filtered.conversions }
						empty={ __( 'No conversion snapshots yet.', 'dx-ui' ) }
						onRestore={ busy ? null : restore }
					/>
				</>
			) }
		</div>
	);
}

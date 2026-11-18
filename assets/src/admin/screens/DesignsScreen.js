import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, SearchControl, SelectControl, Spinner } from '@wordpress/components';
import Page from '../components/Page';
import StatusChips, { sourceLabel } from '../components/StatusChips';
import { dateOf, designHash, link } from '../router';

/*
 * Designs: every design on the site, one row each — what it is, how many pages it has, where it is (the six chips) and
 * when it was last imported — with search and two filters. A row opens the design's page. This is the landing screen of
 * the DX menu: a design is everything one import made, and here is where each one is found again.
 */

const PER_PAGE = 20;

const STATES = [
	{ value: '', label: __( 'Any state', 'dx-ui' ) },
	{ value: 'not-exported', label: __( 'Not exported', 'dx-ui' ) },
	{ value: 'dx-blocks', label: __( 'Without native blocks', 'dx-ui' ) },
	{ value: 'own-colours', label: __( 'Own colours', 'dx-ui' ) },
	{ value: 'own-fonts', label: __( 'Own fonts', 'dx-ui' ) },
	{ value: 'no-document', label: __( 'Without a document', 'dx-ui' ) },
];

function inState( d, state ) {
	const s = d.status || {};
	switch ( state ) {
		case 'not-exported':
			return ! s.exported_at;
		case 'dx-blocks':
			return ! s.native;
		case 'own-colours':
			return s.colours !== 'follow';
		case 'own-fonts':
			return s.fonts === 'own';
		case 'no-document':
			return ! d.document;
		default:
			return true;
	}
}

export default function DesignsScreen() {
	const [ designs, setDesigns ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ source, setSource ] = useState( '' );
	const [ state, setState ] = useState( '' );
	const [ page, setPage ] = useState( 1 );

	useEffect( () => {
		apiFetch( { path: '/dx-ui/v1/design' } )
			.then( ( res ) => setDesigns( res.designs || [] ) )
			.catch( ( e ) => setError( e.message || __( 'The designs could not be read.', 'dx-ui' ) ) );
	}, [] );

	// The list is filtered here: a site has tens of designs, not thousands, and one answer holds them all.
	const sources = useMemo( () => {
		const seen = {};
		( designs || [] ).forEach( ( d ) => {
			const kind = ( d.status && d.status.source ) || '';
			if ( kind && sourceLabel( kind ) ) {
				seen[ kind ] = sourceLabel( kind );
			}
		} );
		return [ { value: '', label: __( 'Any source', 'dx-ui' ) } ].concat( Object.keys( seen ).sort().map( ( k ) => ( { value: k, label: seen[ k ] } ) ) );
	}, [ designs ] );

	const rows = useMemo( () => {
		const q = query.trim().toLowerCase();
		return ( designs || [] ).filter( ( d ) => {
			if ( q && ! ( ( d.title || '' ) + ' ' + ( ( d.status && d.status.archive ) || '' ) ).toLowerCase().includes( q ) ) {
				return false;
			}
			if ( source && ( ( d.status && d.status.source ) || '' ) !== source ) {
				return false;
			}
			return inState( d, state );
		} );
	}, [ designs, query, source, state ] );

	useEffect( () => setPage( 1 ), [ query, source, state ] );

	const pages = Math.max( 1, Math.ceil( rows.length / PER_PAGE ) );
	const current = Math.min( page, pages );
	const shown = rows.slice( ( current - 1 ) * PER_PAGE, current * PER_PAGE );

	const totals = useMemo( () => {
		const all = designs || [];
		let pagesN = 0;
		let last = '';
		let interactivity = null;
		all.forEach( ( d ) => {
			const s = d.status || {};
			pagesN += s.pages || 0;
			if ( s.imported_at && s.imported_at > last ) {
				last = s.imported_at;
			}
			if ( interactivity === null && typeof s.interactivity === 'boolean' ) {
				interactivity = s.interactivity;
			}
		} );
		return { designs: all.length, pages: pagesN, last, interactivity };
	}, [ designs ] );

	const summary = designs && designs.length > 0
		? [
			/* translators: %d: number of designs. */
			sprintf( _n( '%d design', '%d designs', totals.designs, 'dx-ui' ), totals.designs ),
			/* translators: %d: number of pages. */
			sprintf( _n( '%d page', '%d pages', totals.pages, 'dx-ui' ), totals.pages ),
			/* translators: %s: a date. */
			totals.last ? sprintf( __( 'last import %s', 'dx-ui' ), dateOf( totals.last ) ) : '',
			totals.interactivity === null ? '' : ( totals.interactivity ? __( 'Interactivity API on', 'dx-ui' ) : __( 'Interactivity API off', 'dx-ui' ) ),
		].filter( Boolean ).join( ' · ' )
		: __( 'A design is everything one import made: its pages, its header and footer, its colours and fonts, its blocks.', 'dx-ui' );

	const actions = (
		<>
			<a className="components-button is-primary" href={ link( 'convert' ) }>{ __( 'Convert a design', 'dx-ui' ) }</a>
			{ link( 'site' ) && <a className="components-button is-secondary" href={ link( 'site' ) }>{ __( 'Site', 'dx-ui' ) }</a> }
		</>
	);

	return (
		<Page title={ __( 'Designs', 'dx-ui' ) } meta={ summary } actions={ actions } className="dxui-page--designs">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! designs && ! error && <Spinner /> }

			{ designs && designs.length === 0 && (
				<div className="dxui-empty dxui-card">
					<h2>{ __( 'No designs yet', 'dx-ui' ) }</h2>
					<p>{ __( 'Convert a design — an archive from Claude Design or Lovable, a Figma file, a pasted component — and it appears here with its pages, its header and footer, and where it is.', 'dx-ui' ) }</p>
					<Button variant="primary" href={ link( 'convert' ) }>{ __( 'Convert a design', 'dx-ui' ) }</Button>
				</div>
			) }

			{ designs && designs.length > 0 && (
				<>
					<div className="dxui-toolbar">
						<SearchControl
							className="dxui-toolbar__search"
							label={ __( 'Search designs', 'dx-ui' ) }
							placeholder={ __( 'Search designs', 'dx-ui' ) }
							value={ query }
							onChange={ setQuery }
							__nextHasNoMarginBottom
						/>
						<SelectControl
							className="dxui-toolbar__filter"
							label={ __( 'Source', 'dx-ui' ) }
							hideLabelFromVision
							value={ source }
							options={ sources }
							onChange={ setSource }
							__nextHasNoMarginBottom
						/>
						<SelectControl
							className="dxui-toolbar__filter"
							label={ __( 'State', 'dx-ui' ) }
							hideLabelFromVision
							value={ state }
							options={ STATES }
							onChange={ setState }
							__nextHasNoMarginBottom
						/>
						<span className="dxui-toolbar__count">
							{ rows.length === designs.length
								/* translators: %d: number of designs. */
								? sprintf( _n( '%d design', '%d designs', rows.length, 'dx-ui' ), rows.length )
								/* translators: 1: designs shown, 2: designs in all. */
								: sprintf( __( '%1$d of %2$d designs', 'dx-ui' ), rows.length, designs.length ) }
						</span>
					</div>

					<table className="wp-list-table widefat fixed striped table-view-list dxui-designs">
						<thead>
							<tr>
								<th scope="col" className="dxui-designs__design">{ __( 'Design', 'dx-ui' ) }</th>
								<th scope="col" className="dxui-designs__num">{ __( 'Pages', 'dx-ui' ) }</th>
								<th scope="col" className="dxui-designs__num">{ __( 'Sections', 'dx-ui' ) }</th>
								<th scope="col" className="dxui-designs__state">{ __( 'State', 'dx-ui' ) }</th>
								<th scope="col" className="dxui-designs__date">{ __( 'Last import', 'dx-ui' ) }</th>
								<th scope="col" className="dxui-designs__actions">{ __( 'Actions', 'dx-ui' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ shown.length === 0 && (
								<tr>
									<td colSpan="6" className="dxui-designs__none">{ __( 'No design matches.', 'dx-ui' ) }</td>
								</tr>
							) }
							{ shown.map( ( d ) => {
								const s = d.status || {};
								const kind = sourceLabel( s.source );
								return (
									<tr key={ d.id }>
										<td className="dxui-designs__design">
											<a className="dxui-designs__title" href={ designHash( d.id ) }>{ d.title }</a>
											<div className="dxui-designs__sub">
												{ [ kind, s.archive ].filter( Boolean ).join( ' · ' ) }
												{ ! d.document && <span className="dxui-designs__nodoc"> · { __( 'no document', 'dx-ui' ) }</span> }
											</div>
										</td>
										<td className="dxui-designs__num">{ s.pages || 0 }</td>
										<td className="dxui-designs__num">{ s.sections || ( d.summary && d.summary.sections ) || 0 }</td>
										<td className="dxui-designs__state"><StatusChips status={ s } id={ d.id } small /></td>
										<td className="dxui-designs__date">{ dateOf( s.imported_at ) }</td>
										<td className="dxui-designs__actions">
											<a href={ designHash( d.id ) }>{ __( 'Open', 'dx-ui' ) }</a>
											{ s.home && <> · <a href={ s.home } target="_blank" rel="noreferrer">{ __( 'View', 'dx-ui' ) }</a></> }
											{ s.export && <> · <a href={ s.export }>{ __( 'Export', 'dx-ui' ) }</a></> }
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>

					{ pages > 1 && (
						<div className="tablenav bottom dxui-designs__nav">
							<div className="tablenav-pages">
								<span className="displaying-num">
									{ /* translators: %d: number of designs. */ sprintf( _n( '%d item', '%d items', rows.length, 'dx-ui' ), rows.length ) }
								</span>
								<span className="pagination-links">
									<Button variant="secondary" size="small" disabled={ current <= 1 } onClick={ () => setPage( current - 1 ) } aria-label={ __( 'Previous page', 'dx-ui' ) }>‹</Button>
									<span className="paging-input">
										{ /* translators: 1: current page, 2: number of pages. */ sprintf( __( '%1$d of %2$d', 'dx-ui' ), current, pages ) }
									</span>
									<Button variant="secondary" size="small" disabled={ current >= pages } onClick={ () => setPage( current + 1 ) } aria-label={ __( 'Next page', 'dx-ui' ) }>›</Button>
								</span>
							</div>
						</div>
					) }
				</>
			) }
		</Page>
	);
}

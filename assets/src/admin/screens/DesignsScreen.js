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
	{ value: '', label: __( 'Any state', 'dxai-ui' ) },
	{ value: 'not-exported', label: __( 'Not exported', 'dxai-ui' ) },
	{ value: 'dx-blocks', label: __( 'Without native blocks', 'dxai-ui' ) },
	{ value: 'own-colours', label: __( 'Own colours', 'dxai-ui' ) },
	{ value: 'own-fonts', label: __( 'Own fonts', 'dxai-ui' ) },
	{ value: 'no-document', label: __( 'Without a document', 'dxai-ui' ) },
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
		apiFetch( { path: '/dxai-ui/v1/design' } )
			.then( ( res ) => setDesigns( res.designs || [] ) )
			.catch( ( e ) => setError( e.message || __( 'The designs could not be read.', 'dxai-ui' ) ) );
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
		return [ { value: '', label: __( 'Any source', 'dxai-ui' ) } ].concat( Object.keys( seen ).sort().map( ( k ) => ( { value: k, label: seen[ k ] } ) ) );
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
			sprintf( _n( '%d design', '%d designs', totals.designs, 'dxai-ui' ), totals.designs ),
			/* translators: %d: number of pages. */
			sprintf( _n( '%d page', '%d pages', totals.pages, 'dxai-ui' ), totals.pages ),
			/* translators: %s: a date. */
			totals.last ? sprintf( __( 'last import %s', 'dxai-ui' ), dateOf( totals.last ) ) : '',
			totals.interactivity === null ? '' : ( totals.interactivity ? __( 'Interactivity API on', 'dxai-ui' ) : __( 'Interactivity API off', 'dxai-ui' ) ),
		].filter( Boolean ).join( ' · ' )
		: __( 'A design is everything one import made: its pages, its header and footer, its colours and fonts, its blocks.', 'dxai-ui' );

	const actions = (
		<>
			<a className="components-button is-primary" href={ link( 'convert' ) }>{ __( 'Convert a design', 'dxai-ui' ) }</a>
			{ link( 'site' ) && <a className="components-button is-secondary" href={ link( 'site' ) }>{ __( 'Site', 'dxai-ui' ) }</a> }
		</>
	);

	return (
		<Page title={ __( 'Designs', 'dxai-ui' ) } meta={ summary } actions={ actions } className="dxai-page--designs">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! designs && ! error && <Spinner /> }

			{ designs && designs.length === 0 && (
				<div className="dxai-empty dxai-card">
					<h2>{ __( 'No designs yet', 'dxai-ui' ) }</h2>
					<p>{ __( 'Convert a design — an archive from Claude Design or Lovable, a Figma file, a pasted component — and it appears here with its pages, its header and footer, and where it is.', 'dxai-ui' ) }</p>
					<Button variant="primary" href={ link( 'convert' ) }>{ __( 'Convert a design', 'dxai-ui' ) }</Button>
				</div>
			) }

			{ designs && designs.length > 0 && (
				<>
					<div className="dxai-toolbar">
						<SearchControl
							className="dxai-toolbar__search"
							label={ __( 'Search designs', 'dxai-ui' ) }
							placeholder={ __( 'Search designs', 'dxai-ui' ) }
							value={ query }
							onChange={ setQuery }
							__nextHasNoMarginBottom
						/>
						<SelectControl
							className="dxai-toolbar__filter"
							label={ __( 'Source', 'dxai-ui' ) }
							hideLabelFromVision
							value={ source }
							options={ sources }
							onChange={ setSource }
							__nextHasNoMarginBottom
						/>
						<SelectControl
							className="dxai-toolbar__filter"
							label={ __( 'State', 'dxai-ui' ) }
							hideLabelFromVision
							value={ state }
							options={ STATES }
							onChange={ setState }
							__nextHasNoMarginBottom
						/>
						<span className="dxai-toolbar__count">
							{ rows.length === designs.length
								/* translators: %d: number of designs. */
								? sprintf( _n( '%d design', '%d designs', rows.length, 'dxai-ui' ), rows.length )
								/* translators: 1: designs shown, 2: designs in all. */
								: sprintf( __( '%1$d of %2$d designs', 'dxai-ui' ), rows.length, designs.length ) }
						</span>
					</div>

					<table className="wp-list-table widefat fixed striped table-view-list dxai-designs">
						<thead>
							<tr>
								<th scope="col" className="dxai-designs__design">{ __( 'Design', 'dxai-ui' ) }</th>
								<th scope="col" className="dxai-designs__num">{ __( 'Pages', 'dxai-ui' ) }</th>
								<th scope="col" className="dxai-designs__num">{ __( 'Sections', 'dxai-ui' ) }</th>
								<th scope="col" className="dxai-designs__state">{ __( 'State', 'dxai-ui' ) }</th>
								<th scope="col" className="dxai-designs__date">{ __( 'Last import', 'dxai-ui' ) }</th>
								<th scope="col" className="dxai-designs__actions">{ __( 'Actions', 'dxai-ui' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ shown.length === 0 && (
								<tr>
									<td colSpan="6" className="dxai-designs__none">{ __( 'No design matches.', 'dxai-ui' ) }</td>
								</tr>
							) }
							{ shown.map( ( d ) => {
								const s = d.status || {};
								const kind = sourceLabel( s.source );
								return (
									<tr key={ d.id }>
										<td className="dxai-designs__design">
											<a className="dxai-designs__title" href={ designHash( d.id ) }>{ d.title }</a>
											<div className="dxai-designs__sub">
												{ [ kind, s.archive ].filter( Boolean ).join( ' · ' ) }
												{ ! d.document && <span className="dxai-designs__nodoc"> · { __( 'no document', 'dxai-ui' ) }</span> }
											</div>
										</td>
										<td className="dxai-designs__num">{ s.pages || 0 }</td>
										<td className="dxai-designs__num">{ s.sections || ( d.summary && d.summary.sections ) || 0 }</td>
										<td className="dxai-designs__state"><StatusChips status={ s } id={ d.id } small /></td>
										<td className="dxai-designs__date">{ dateOf( s.imported_at ) }</td>
										<td className="dxai-designs__actions">
											<a href={ designHash( d.id ) }>{ __( 'Open', 'dxai-ui' ) }</a>
											{ s.home && <> · <a href={ s.home } target="_blank" rel="noreferrer">{ __( 'View', 'dxai-ui' ) }</a></> }
											{ s.export && <> · <a href={ s.export }>{ __( 'Export', 'dxai-ui' ) }</a></> }
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>

					{ pages > 1 && (
						<div className="tablenav bottom dxai-designs__nav">
							<div className="tablenav-pages">
								<span className="displaying-num">
									{ /* translators: %d: number of designs. */ sprintf( _n( '%d item', '%d items', rows.length, 'dxai-ui' ), rows.length ) }
								</span>
								<span className="pagination-links">
									<Button variant="secondary" size="small" disabled={ current <= 1 } onClick={ () => setPage( current - 1 ) } aria-label={ __( 'Previous page', 'dxai-ui' ) }>‹</Button>
									<span className="paging-input">
										{ /* translators: 1: current page, 2: number of pages. */ sprintf( __( '%1$d of %2$d', 'dxai-ui' ), current, pages ) }
									</span>
									<Button variant="secondary" size="small" disabled={ current >= pages } onClick={ () => setPage( current + 1 ) } aria-label={ __( 'Next page', 'dxai-ui' ) }>›</Button>
								</span>
							</div>
						</div>
					) }
				</>
			) }
		</Page>
	);
}

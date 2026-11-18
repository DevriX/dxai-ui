import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, Spinner } from '@wordpress/components';
import Page from '../components/Page';
import StatusChips, { sourceLabel } from '../components/StatusChips';
import DesignReport from '../components/DesignReport';
import SitePages from '../components/SitePages';
import TeamPages from '../components/TeamPages';
import ThemeColors from '../components/ThemeColors';
import NativeBlocks from '../components/NativeBlocks';
import CopyPrompt from '../components/CopyPrompt';
import Speed from '../components/Speed';
import TransferImport from '../components/TransferImport';
import { loadTransferInfo } from '../components/TransferExport';
import { confirmRestore } from '../components/Collections';
import { TABS, dateOf, designHash, link } from '../router';

/*
 * One design's page: its name and facts, what to do with it, where it is (the six chips), and its work in tabs —
 * Overview (what's next and the design report), Pages, Look, Blocks, Words, Speed, Transfer, History. The panels in the
 * tabs are the Library's, given this design.
 */

const TAB_LABELS = {
	overview: __( 'Overview', 'dx-ui' ),
	pages: __( 'Pages', 'dx-ui' ),
	look: __( 'Look', 'dx-ui' ),
	blocks: __( 'Blocks', 'dx-ui' ),
	words: __( 'Words', 'dx-ui' ),
	speed: __( 'Speed', 'dx-ui' ),
	transfer: __( 'Transfer', 'dx-ui' ),
	history: __( 'History', 'dx-ui' ),
};

const KINDS = {
	home: __( 'Home', 'dx-ui' ),
	team: __( 'In the team’s style', 'dx-ui' ),
	site: __( 'From the old site', 'dx-ui' ),
	own: __( 'Of the design', 'dx-ui' ),
};

function Tabs( { id, tab, counts } ) {
	return (
		<nav className="dxui-tabs" aria-label={ __( 'The design', 'dx-ui' ) }>
			<ul role="tablist">
				{ TABS.map( ( name ) => (
					<li key={ name } role="presentation">
						<a
							role="tab"
							href={ designHash( id, name ) }
							aria-selected={ tab === name ? 'true' : 'false' }
							className={ 'dxui-tabs__tab' + ( tab === name ? ' is-current' : '' ) }
						>
							{ TAB_LABELS[ name ] }
							{ counts && counts[ name ] > 0 && <span className="dxui-tabs__count">{ counts[ name ] }</span> }
						</a>
					</li>
				) ) }
			</ul>
		</nav>
	);
}

function WhatsNext( { id, items } ) {
	if ( ! items ) {
		return null;
	}
	return (
		<section className="dxui-next dxui-card" aria-labelledby="dxui-next-title">
			<h2 id="dxui-next-title">{ __( 'What’s next', 'dx-ui' ) }</h2>
			{ items.length === 0 && <p className="dxui-muted">{ __( 'Nothing waits: the design is where it can be on this site.', 'dx-ui' ) }</p> }
			{ items.length > 0 && (
				<ol className="dxui-next__list">
					{ items.map( ( it ) => (
						<li key={ it.key }>
							<a className="dxui-next__title" href={ designHash( id, it.tab ) }>{ it.title }</a>
							<span className="dxui-next__why">{ it.why }</span>
						</li>
					) ) }
				</ol>
			) }
		</section>
	);
}

const PAGES_SHOWN = 10;

function PagesList( { items } ) {
	const [ all, setAll ] = useState( false );
	if ( ! items || ! items.length ) {
		return null;
	}
	const shown = all || items.length <= PAGES_SHOWN + 2 ? items : items.slice( 0, PAGES_SHOWN );
	return (
		<section className="dxui-section dxui-pages">
			<div className="dxui-section-head">
				<div>
					<h3>{ __( 'Pages of this design', 'dx-ui' ) }</h3>
					<p className="dxui-muted">{ __( 'The Home and every page made for it, whichever way it was made.', 'dx-ui' ) }</p>
				</div>
				<span className="dxui-pill">{ items.length }</span>
			</div>
			<table className="wp-list-table widefat fixed striped dxui-pages__table">
				<thead>
					<tr>
						<th scope="col">{ __( 'Page', 'dx-ui' ) }</th>
						<th scope="col" className="dxui-pages__kind">{ __( 'Made', 'dx-ui' ) }</th>
						<th scope="col" className="dxui-pages__status">{ __( 'Status', 'dx-ui' ) }</th>
						<th scope="col" className="dxui-pages__actions">{ __( 'Actions', 'dx-ui' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ shown.map( ( p ) => (
						<tr key={ p.id }>
							<td><strong>{ p.title || __( '(no title)', 'dx-ui' ) }</strong></td>
							<td className="dxui-pages__kind">{ KINDS[ p.kind ] || p.kind }</td>
							<td className="dxui-pages__status">{ p.status }</td>
							<td className="dxui-pages__actions">
								{ p.url && <a href={ p.url } target="_blank" rel="noreferrer">{ __( 'View', 'dx-ui' ) }</a> }
								{ p.url && p.edit && ' · ' }
								{ p.edit && <a href={ p.edit }>{ __( 'Edit', 'dx-ui' ) }</a> }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
			{ shown.length < items.length && (
				<p className="dxui-pages__more">
					<Button variant="link" onClick={ () => setAll( true ) }>
						{ /* translators: %d: number of pages. */ sprintf( __( 'Show all %d pages', 'dx-ui' ), items.length ) }
					</Button>
				</p>
			) }
		</section>
	);
}

function InteractivityCard( { status } ) {
	return (
		<section className="dxui-section dxui-interactivity">
			<div className="dxui-section-head">
				<div>
					<h3>{ __( 'What the page does when touched', 'dx-ui' ) }</h3>
					<p className="dxui-muted">
						{ status.states > 0
							? sprintf(
								/* translators: %d: number of states. */
								_n( 'The design has %d state (a menu, a panel, a tab) that changes when the page is touched.', 'The design has %d states (menus, panels, tabs) that change when the page is touched.', status.states, 'dx-ui' ),
								status.states
							)
							: __( 'Nothing on the design changes when the page is touched.', 'dx-ui' ) }
						{ ' ' }
						{ status.states > 0 && ( status.interactivity
							? __( 'WordPress’s Interactivity API drives them.', 'dx-ui' )
							: __( 'The plugin’s page runtime drives them; the Interactivity API is off.', 'dx-ui' ) ) }
					</p>
				</div>
				{ link( 'settings' ) && <a className="components-button is-secondary" href={ link( 'settings' ) }>{ __( 'Settings › Behaviour', 'dx-ui' ) }</a> }
			</div>
		</section>
	);
}

function TransferTab( { status, onImported } ) {
	const [ info, setInfo ] = useState( null );
	const [ infoError, setInfoError ] = useState( '' );

	function loadInfo() {
		loadTransferInfo()
			.then( ( res ) => {
				setInfo( res );
				setInfoError( '' );
			} )
			.catch( ( err ) => setInfoError( ( err && err.message ) || __( 'Export and import are not available.', 'dx-ui' ) ) );
	}

	useEffect( () => {
		loadInfo();
	}, [] );

	return (
		<>
			<section className="dxui-section dxui-export">
				<div className="dxui-section-head">
					<div>
						<h3>{ __( 'Export for the live site', 'dx-ui' ) }</h3>
						<p className="dxui-muted">
							{ __( 'A package carries the Home with its styles, pictures, fonts, header and footer, and its document; the live site’s DX imports it in one step.', 'dx-ui' ) }
							{ ' ' }
							{ status.exported_at
								/* translators: %s: a date and time. */
								? sprintf( __( 'Last downloaded %s.', 'dx-ui' ), dateOf( status.exported_at, true ) )
								: __( 'No package of this design has been downloaded yet.', 'dx-ui' ) }
						</p>
					</div>
					{ status.export
						? <a className="components-button is-primary" href={ status.export }>{ __( 'Download the package', 'dx-ui' ) }</a>
						: <span className="dxui-muted">{ __( 'Exporting needs an administrator who may publish unfiltered HTML.', 'dx-ui' ) }</span> }
				</div>
			</section>
			<TransferImport info={ info } infoError={ infoError } onImported={ () => { loadInfo(); onImported(); } } />
		</>
	);
}

function HistoryTab( { status, onRestored } ) {
	const [ busy, setBusy ] = useState( false );
	const [ said, setSaid ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const rows = status.conversions || [];

	function restore( item ) {
		if ( ! confirmRestore( item ) ) {
			return;
		}
		setBusy( true );
		setSaid( '' );
		setError( '' );
		apiFetch( { path: '/dx-ui/v1/restore', method: 'POST', data: { conversion_id: item.id } } )
			.then( () => {
				setSaid( __( 'Conversion restored.', 'dx-ui' ) );
				onRestored();
			} )
			.catch( ( err ) => setError( err.message || __( 'Restore failed.', 'dx-ui' ) ) )
			.finally( () => setBusy( false ) );
	}

	return (
		<section className="dxui-section dxui-history">
			<div className="dxui-section-head">
				<div>
					<h3>{ __( 'Imports of this design', 'dx-ui' ) }</h3>
					<p className="dxui-muted">{ __( 'Every import keeps a snapshot of what it compiled and what it made. Restoring one writes the Home again from it; the page’s own revisions keep every edit since.', 'dx-ui' ) }</p>
				</div>
				{ link( 'convert' ) && <a className="components-button is-secondary" href={ link( 'convert' ) }>{ __( 'Import again', 'dx-ui' ) }</a> }
			</div>
			{ said && <Notice status="success" isDismissible={ false }>{ said }</Notice> }
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ rows.length === 0 && <p className="dxui-muted">{ __( 'No snapshot is kept for this design: it was made another way, or before snapshots were kept.', 'dx-ui' ) }</p> }
			{ rows.length > 0 && (
				<table className="wp-list-table widefat fixed striped dxui-history__table">
					<thead>
						<tr>
							<th scope="col" className="dxui-history__date">{ __( 'When', 'dx-ui' ) }</th>
							<th scope="col">{ __( 'Snapshot', 'dx-ui' ) }</th>
							<th scope="col" className="dxui-history__actions">{ __( 'Actions', 'dx-ui' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( c ) => (
							<tr key={ c.id }>
								<td className="dxui-history__date">{ dateOf( c.date, true ) }</td>
								<td>{ c.title } <span className="dxui-muted">· ID { c.id }</span></td>
								<td className="dxui-history__actions">
									<Button variant="secondary" size="small" onClick={ () => restore( c ) } disabled={ busy }>{ __( 'Restore', 'dx-ui' ) }</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</section>
	);
}

export default function DesignScreen( { id, tab } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ stamp, setStamp ] = useState( 0 );

	useEffect( () => {
		setError( '' );
		apiFetch( { path: '/dx-ui/v1/design/' + id + '/next' } )
			.then( setData )
			.catch( ( e ) => setError( e.message || __( 'The design could not be read.', 'dx-ui' ) ) );
	}, [ id, stamp ] );

	const refresh = () => setStamp( ( s ) => s + 1 );
	const crumbs = [ { label: __( 'Designs', 'dx-ui' ), href: '#/' }, { label: data ? data.title : '…' } ];

	if ( error ) {
		return (
			<Page title={ __( 'Design', 'dx-ui' ) } crumbs={ crumbs }>
				<Notice status="error" isDismissible={ false }>{ error }</Notice>
				<p><a href="#/">{ __( 'Back to the designs', 'dx-ui' ) }</a></p>
			</Page>
		);
	}
	if ( ! data ) {
		return (
			<Page title="…" crumbs={ crumbs }>
				<Spinner />
			</Page>
		);
	}

	const s = data.status || {};
	const kind = sourceLabel( s.source );
	const meta = [
		kind,
		s.archive,
		/* translators: %s: a date. */
		s.imported_at ? sprintf( __( 'imported %s', 'dx-ui' ), dateOf( s.imported_at ) ) : '',
		/* translators: %d: number of pages. */
		sprintf( _n( '%d page', '%d pages', s.pages || 0, 'dx-ui' ), s.pages || 0 ),
	].filter( Boolean ).join( ' · ' );
	const actions = (
		<>
			{ s.home && <a className="components-button is-secondary" href={ s.home } target="_blank" rel="noreferrer">{ __( 'View home', 'dx-ui' ) }</a> }
			{ s.edit && <a className="components-button is-secondary" href={ s.edit }>{ __( 'Edit home', 'dx-ui' ) }</a> }
			{ s.export && <a className="components-button is-primary" href={ s.export }>{ __( 'Export', 'dx-ui' ) }</a> }
		</>
	);
	const counts = { pages: s.pages || 0, history: ( s.conversions || [] ).length };

	return (
		<Page title={ data.title } crumbs={ crumbs } meta={ meta } actions={ actions } className="dxui-page--design">
			<StatusChips status={ s } id={ id } />
			<Tabs id={ id } tab={ tab } counts={ counts } />
			<div className="dxui-tab" role="tabpanel" key={ tab }>
				{ tab === 'overview' && (
					<>
						<WhatsNext id={ id } items={ data.next } />
						<DesignReport design={ id } />
					</>
				) }
				{ tab === 'pages' && (
					<>
						<PagesList items={ s.items } />
						<SitePages design={ id } />
						<TeamPages design={ id } />
					</>
				) }
				{ tab === 'look' && <ThemeColors design={ id } /> }
				{ tab === 'blocks' && (
					<>
						<InteractivityCard status={ s } />
						<NativeBlocks design={ id } />
					</>
				) }
				{ tab === 'words' && <CopyPrompt design={ id } /> }
				{ tab === 'speed' && <Speed design={ id } /> }
				{ tab === 'transfer' && <TransferTab status={ s } onImported={ refresh } /> }
				{ tab === 'history' && <HistoryTab status={ s } onRestored={ refresh } /> }
			</div>
		</Page>
	);
}

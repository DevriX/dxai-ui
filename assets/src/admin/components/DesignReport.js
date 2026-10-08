import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, SelectControl, Spinner } from '@wordpress/components';
import './design-report.css';

/*
 * "Design report": a design's document (the Design IR, Design_Controller) — what the import read of the design and what it made of
 * it: its source, its pages and their sections, its header and footer and the navigation in them, its tokens, fonts and breakpoints,
 * its assets, what it does and whether that reached the page, and what the compiler could not evaluate. A design imported before
 * documents were kept gets one from its conversion snapshot ("Build the document").
 */

const PLACED = {
	content: __( 'in the page', 'dxai-ui' ),
	part: __( 'a template part', 'dxai-ui' ),
	menus: __( 'Appearance › Menus', 'dxai-ui' ),
	widgets: __( 'Appearance › Widgets', 'dxai-ui' ),
	none: __( 'none', 'dxai-ui' ),
};

const KINDS = {
	handlers: __( 'event handlers', 'dxai-ui' ),
	motion: __( 'animations', 'dxai-ui' ),
	radix: __( 'interactive components', 'dxai-ui' ),
	scroll: __( 'scroll state', 'dxai-ui' ),
	lucide: __( 'icons', 'dxai-ui' ),
	'media-query': __( 'layout by width', 'dxai-ui' ),
	countup: __( 'counting numbers', 'dxai-ui' ),
	form: __( 'forms', 'dxai-ui' ),
};

function Carried( { value } ) {
	if ( value === true ) {
		return <span className="dxai-report__yes">{ __( 'carried', 'dxai-ui' ) }</span>;
	}
	if ( value === false ) {
		return <span className="dxai-report__no">{ __( 'not carried', 'dxai-ui' ) }</span>;
	}
	return <span className="dxai-muted">{ __( 'not measured', 'dxai-ui' ) }</span>;
}

function Nav( { items, depth = 0 } ) {
	if ( ! items || ! items.length ) {
		return null;
	}
	return (
		<ul className={ 'dxai-report__nav' + ( depth ? ' is-child' : '' ) }>
			{ items.map( ( item, i ) => (
				<li key={ i }>
					<span>{ item.label || item.url }</span>
					{ item.url && <code>{ item.url }</code> }
					<Nav items={ item.children } depth={ depth + 1 } />
				</li>
			) ) }
		</ul>
	);
}

export default function DesignReport() {
	const [ list, setList ] = useState( null );
	const [ design, setDesign ] = useState( 0 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ open, setOpen ] = useState( false );

	useEffect( () => {
		apiFetch( { path: '/dxai-ui/v1/design' } )
			.then( ( res ) => {
				setList( res.designs || [] );
				if ( res.designs && res.designs.length ) {
					setDesign( res.designs[ 0 ].id );
				}
			} )
			.catch( ( e ) => setError( e.message || __( 'The designs could not be read.', 'dxai-ui' ) ) );
	}, [] );

	useEffect( () => {
		if ( ! design ) {
			return;
		}
		setData( null );
		setError( '' );
		apiFetch( { path: '/dxai-ui/v1/design/' + design } )
			.then( setData )
			.catch( ( e ) => {
				setData( { none: true, message: e.message || '' } );
			} );
	}, [ design ] );

	function rebuild() {
		setBusy( true );
		setError( '' );
		apiFetch( { path: '/dxai-ui/v1/design/' + design, method: 'POST', data: { action: 'rebuild' } } )
			.then( ( res ) => {
				setData( res );
				setList( ( l ) => ( l || [] ).map( ( d ) => ( d.id === design ? { ...d, document: true, summary: res.summary, error: '' } : d ) ) );
			} )
			.catch( ( e ) => setError( e.message || __( 'The document could not be built.', 'dxai-ui' ) ) )
			.finally( () => setBusy( false ) );
	}

	if ( error && ! list ) {
		return <Notice status="error" isDismissible={ false }>{ error }</Notice>;
	}
	if ( ! list ) {
		return <Spinner />;
	}
	if ( ! list.length ) {
		return null;
	}

	const doc = data && data.document;
	const s = data && data.summary;

	return (
		<section className="dxai-section dxai-report" aria-labelledby="dxai-report-title">
			<div className="dxai-section-head">
				<div>
					<p className="dxai-section-kicker">{ __( 'Your designs', 'dxai-ui' ) }</p>
					<h3 id="dxai-report-title">{ __( 'Design report', 'dxai-ui' ) }</h3>
					<p className="dxai-muted">
						{ __( 'What the import read of a design and what it made of it: its pages and their sections, its header and footer, its navigation, its tokens and fonts, what it does and what did not reach the page. Written at the end of every import; read by the pages in the team’s style.', 'dxai-ui' ) }
					</p>
				</div>
			</div>
			{ list.length > 1 && (
				<SelectControl
					label={ __( 'Design', 'dxai-ui' ) }
					value={ String( design ) }
					options={ list.map( ( d ) => ( { value: String( d.id ), label: d.title + ( d.document ? '' : ' — ' + __( 'no document', 'dxai-ui' ) ) } ) ) }
					onChange={ ( v ) => setDesign( parseInt( v, 10 ) ) }
					__nextHasNoMarginBottom
				/>
			) }
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! data && <Spinner /> }
			{ data && data.none && (
				<div className="dxai-report__none">
					<p>{ data.message || __( 'The design has no document yet.', 'dxai-ui' ) }</p>
					<Button variant="secondary" onClick={ rebuild } isBusy={ busy } disabled={ busy }>
						{ __( 'Build the document from the conversion snapshot', 'dxai-ui' ) }
					</Button>
				</div>
			) }
			{ doc && s && (
				<>
					<ul className="dxai-report__facts">
						<li><strong>{ __( 'Source', 'dxai-ui' ) }</strong><span>{ doc.source.kind }{ doc.source.name ? ' · ' + doc.source.name : '' }</span></li>
						<li><strong>{ __( 'Pages', 'dxai-ui' ) }</strong><span>{ sprintf( /* translators: 1: pages, 2: sections. */ __( '%1$d, with %2$d sections', 'dxai-ui' ), s.pages, s.sections ) }</span></li>
						<li><strong>{ __( 'Header', 'dxai-ui' ) }</strong><span>{ PLACED[ s.header ] || s.header }{ s.nav_items ? ' · ' + sprintf( /* translators: %d: menu items. */ _n( '%d menu item', '%d menu items', s.nav_items, 'dxai-ui' ), s.nav_items ) : '' }</span></li>
						<li><strong>{ __( 'Footer', 'dxai-ui' ) }</strong><span>{ PLACED[ s.footer ] || s.footer }</span></li>
						<li><strong>{ __( 'Tokens', 'dxai-ui' ) }</strong><span>{ s.tokens || __( 'none', 'dxai-ui' ) }{ doc.tokens.roles.brand ? ' · ' + doc.tokens.roles.brand : '' }{ doc.tokens.roles.accent ? ' / ' + doc.tokens.roles.accent : '' }</span></li>
						<li><strong>{ __( 'Fonts', 'dxai-ui' ) }</strong><span>{ doc.tokens.fonts.length ? doc.tokens.fonts.map( ( f ) => f.family ).join( ', ' ) : __( 'none of its own', 'dxai-ui' ) }</span></li>
						<li><strong>{ __( 'Breakpoints', 'dxai-ui' ) }</strong><span>{ doc.breakpoints.length ? doc.breakpoints.map( ( px ) => px + 'px' ).join( ', ' ) : __( 'none recorded', 'dxai-ui' ) }</span></li>
						<li><strong>{ __( 'Pictures', 'dxai-ui' ) }</strong><span>{ sprintf( /* translators: 1: images, 2: svg, 3: videos. */ __( '%1$d images, %2$d inline SVG, %3$d videos', 'dxai-ui' ), doc.assets.images, doc.assets.svg, doc.assets.videos ) }</span></li>
						<li><strong>{ __( 'Fingerprint', 'dxai-ui' ) }</strong><span><code>{ s.fingerprint.slice( 0, 12 ) }</code></span></li>
					</ul>

					<h4>{ __( 'Pages and their sections', 'dxai-ui' ) }</h4>
					<ul className="dxai-report__pages">
						{ doc.pages.map( ( p ) => (
							<li key={ p.slug }>
								<div>
									<strong>{ p.title || p.slug }</strong> <code>{ p.slug }</code>
									{ p.id > 0 && <a href={ 'post.php?post=' + p.id + '&action=edit' }>{ __( 'Edit', 'dxai-ui' ) }</a> }
									<span className="dxai-muted"> · { sprintf( /* translators: %d: words. */ _n( '%d word', '%d words', p.words, 'dxai-ui' ), p.words ) }</span>
								</div>
								<ol className="dxai-report__sections">
									{ p.sections.map( ( sec ) => (
										<li key={ sec.index }>
											<span className="dxai-report__role">{ sec.role }</span>
											{ sec.heading && <span>{ sec.heading }</span> }
											{ sec.items && sec.items.length > 0 && <span className="dxai-muted"> · { sec.items.slice( 0, 6 ).join( ', ' ) }{ sec.items.length > 6 ? '…' : '' }</span> }
										</li>
									) ) }
								</ol>
							</li>
						) ) }
					</ul>

					{ doc.nav.length > 0 && (
						<>
							<h4>{ __( 'Navigation', 'dxai-ui' ) }</h4>
							<Nav items={ doc.nav } />
						</>
					) }

					<h4>{ __( 'What the design does', 'dxai-ui' ) }</h4>
					{ doc.behaviours.length === 0 && <p className="dxai-muted">{ __( 'Nothing its code declares.', 'dxai-ui' ) }</p> }
					{ doc.behaviours.length > 0 && (
						<ul className="dxai-report__behaviours">
							{ doc.behaviours.map( ( b ) => (
								<li key={ b.kind }>
									<span>{ KINDS[ b.kind ] || b.kind }</span>
									<strong>{ b.count }</strong>
									<Carried value={ b.compiled } />
								</li>
							) ) }
						</ul>
					) }

					<Button variant="link" onClick={ () => setOpen( ! open ) }>
						{ open ? __( 'Hide the compiler’s report', 'dxai-ui' ) : __( 'Show the compiler’s report', 'dxai-ui' ) }
					</Button>
					{ open && (
						<div className="dxai-report__compiler">
							<p>
								{ doc.report.unevaluated.length
									? sprintf( /* translators: %d: rows. */ _n( '%d thing the compiler could not evaluate:', '%d things the compiler could not evaluate:', doc.report.unevaluated.length, 'dxai-ui' ), doc.report.unevaluated.length )
									: __( 'The compiler evaluated everything it noticed.', 'dxai-ui' ) }
							</p>
							{ doc.report.unevaluated.length > 0 && (
								<ul>
									{ doc.report.unevaluated.slice( 0, 30 ).map( ( u, i ) => (
										<li key={ i }><code>{ u.kind }</code> { u.source }{ u.count > 1 ? ' × ' + u.count : '' }</li>
									) ) }
								</ul>
							) }
							{ doc.report.coverage.filter( ( c ) => c.short ).length > 0 && (
								<>
									<p>{ __( 'Short of the design on the saved page:', 'dxai-ui' ) }</p>
									<ul>
										{ doc.report.coverage.filter( ( c ) => c.short ).map( ( c ) => (
											<li key={ c.dimension }><code>{ c.dimension }</code> { c.page } / { c.source }</li>
										) ) }
									</ul>
								</>
							) }
							{ doc.report.notes.length > 0 && (
								<ul>
									{ doc.report.notes.map( ( n, i ) => <li key={ i }>{ n }</li> ) }
								</ul>
							) }
							<p className="dxai-muted">
								{ sprintf( /* translators: 1: plugin version, 2: time. */ __( 'Built by %1$s at %2$s.', 'dxai-ui' ), doc.built.plugin, doc.built.at ) }
								{ ' ' }
								<Button variant="link" onClick={ rebuild } isBusy={ busy } disabled={ busy }>{ __( 'Build it again', 'dxai-ui' ) }</Button>
							</p>
						</div>
					) }
				</>
			) }
		</section>
	);
}

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
	content: __( 'in the page', 'dx-ui' ),
	part: __( 'a template part', 'dx-ui' ),
	menus: __( 'Appearance › Menus', 'dx-ui' ),
	widgets: __( 'Appearance › Widgets', 'dx-ui' ),
	none: __( 'none', 'dx-ui' ),
};

const KINDS = {
	handlers: __( 'event handlers', 'dx-ui' ),
	motion: __( 'animations', 'dx-ui' ),
	radix: __( 'interactive components', 'dx-ui' ),
	scroll: __( 'scroll state', 'dx-ui' ),
	lucide: __( 'icons', 'dx-ui' ),
	'media-query': __( 'layout by width', 'dx-ui' ),
	countup: __( 'counting numbers', 'dx-ui' ),
	form: __( 'forms', 'dx-ui' ),
	state: __( 'a state', 'dx-ui' ),
	reveal: __( 'sections that reveal', 'dx-ui' ),
	'radix-accordion': __( 'accordion', 'dx-ui' ),
	'radix-tabs': __( 'tabs', 'dx-ui' ),
	'radix-select': __( 'select', 'dx-ui' ),
	'radix-dialog': __( 'dialog', 'dx-ui' ),
	'radix-popover': __( 'popover', 'dx-ui' ),
	'radix-menu': __( 'menu', 'dx-ui' ),
	'radix-tooltip': __( 'tooltip', 'dx-ui' ),
	portal: __( 'portals', 'dx-ui' ),
	'hero-tabs': __( 'hero tabs', 'dx-ui' ),
	'method-rows': __( 'method rows', 'dx-ui' ),
	rail: __( 'a rail', 'dx-ui' ),
	'video-facade': __( 'video facade', 'dx-ui' ),
};

/** What the Interactivity API could do about a behaviour (Behaviour_Reader): directives alone, with a store callback, or nothing without the runtime. */
const LEVELS = {
	full: __( 'directives', 'dx-ui' ),
	partial: __( 'directives + callback', 'dx-ui' ),
	none: __( 'runtime', 'dx-ui' ),
};

function Interactivity( { row } ) {
	if ( ! row.interactivity || ! LEVELS[ row.interactivity ] ) {
		return null;
	}
	return (
		<span className={ `dxui-report__level dxui-report__level--${ row.interactivity }` } title={ row.why || '' }>
			{ LEVELS[ row.interactivity ] }
		</span>
	);
}

function Carried( { value } ) {
	if ( value === true ) {
		return <span className="dxui-report__yes">{ __( 'carried', 'dx-ui' ) }</span>;
	}
	if ( value === false ) {
		return <span className="dxui-report__no">{ __( 'not carried', 'dx-ui' ) }</span>;
	}
	return <span className="dxui-muted">{ __( 'not measured', 'dx-ui' ) }</span>;
}

function Nav( { items, depth = 0 } ) {
	if ( ! items || ! items.length ) {
		return null;
	}
	return (
		<ul className={ 'dxui-report__nav' + ( depth ? ' is-child' : '' ) }>
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

/*
 * The design's style variation (Style_Variation): its tokens as a theme.json style variation — applied, the site's look is the
 * design's (presets everywhere, the styles everywhere but on the design's own pages); downloaded as the file a theme lists; written
 * into the theme's styles folder when the theme is the plugin's own.
 */
function Variation( { design } ) {
	const [ data, setData ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ said, setSaid ] = useState( '' );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		setData( null );
		setSaid( '' );
		setError( '' );
		apiFetch( { path: '/dx-ui/v1/design/' + design + '/variation' } )
			.then( setData )
			.catch( ( e ) => setError( e.message || '' ) );
	}, [ design ] );

	if ( error ) {
		return <p className="dxui-muted">{ error }</p>;
	}
	if ( ! data ) {
		return null;
	}

	function run( action ) {
		setBusy( action );
		setSaid( '' );
		setError( '' );
		apiFetch( { path: '/dx-ui/v1/design/' + design + '/variation', method: 'POST', data: { action } } )
			.then( ( res ) => {
				setData( { ...data, ...res } );
				if ( action === 'apply' ) {
					setSaid( __( 'The site’s look is this design’s now: its colours and fonts are the theme’s presets, its buttons, links and headings the site’s. Its own pages are drawn as before.', 'dx-ui' ) );
				} else if ( action === 'clear' ) {
					setSaid( __( 'The site’s look is the theme’s own again.', 'dx-ui' ) );
				} else if ( res.result && res.result.path ) {
					setSaid( sprintf( /* translators: %s: file path. */ __( 'Written: %s', 'dx-ui' ), res.result.path ) );
				}
			} )
			.catch( ( e ) => setError( e.message || __( 'That did not work.', 'dx-ui' ) ) )
			.finally( () => setBusy( '' ) );
	}

	const blob = 'data:application/json;charset=utf-8,' + encodeURIComponent( data.json || '' );

	return (
		<div className="dxui-report__variation">
			<h4>{ __( 'Style variation', 'dx-ui' ) }</h4>
			<p className="dxui-muted">
				{ __( 'The design’s colours, fonts and content width as a theme.json style variation. Applied, it is the site’s look: the presets everywhere, the styles on every page but the design’s own, which keep their pixel-for-pixel rules.', 'dx-ui' ) }
			</p>
			<p>
				{ data.applied
					? <strong>{ __( 'Applied: the site’s look is this design’s.', 'dx-ui' ) }</strong>
					: <span className="dxui-muted">{ __( 'Not applied.', 'dx-ui' ) }</span> }
				{ data.written && <span className="dxui-muted"> · { sprintf( /* translators: %s: file name. */ __( 'in the theme as %s', 'dx-ui' ), data.file_name ) }</span> }
			</p>
			<div className="dxui-actions">
				{ ! data.applied && <Button variant="primary" onClick={ () => run( 'apply' ) } isBusy={ busy === 'apply' } disabled={ !! busy }>{ __( 'Apply as the site’s style', 'dx-ui' ) }</Button> }
				{ data.applied && <Button variant="secondary" onClick={ () => run( 'clear' ) } isBusy={ busy === 'clear' } disabled={ !! busy }>{ __( 'Back to the theme’s own', 'dx-ui' ) }</Button> }
				<a className="components-button is-secondary" href={ blob } download={ data.file_name }>{ __( 'Download the JSON', 'dx-ui' ) }</a>
				{ data.writable && <Button variant="secondary" onClick={ () => run( 'write' ) } isBusy={ busy === 'write' } disabled={ !! busy }>{ __( 'Write into the theme’s styles folder', 'dx-ui' ) }</Button> }
			</div>
			{ ! data.writable && data.capabilities && (
				<p className="dxui-muted">
					{ data.capabilities.theme && data.capabilities.theme.base
						? __( 'The styles folder cannot be written on this site.', 'dx-ui' )
						: sprintf( /* translators: %s: theme name. */ __( '%s is a team’s theme: the plugin does not write into it. Download the JSON and put it in the theme’s styles folder yourself, or apply it here.', 'dx-ui' ), ( data.capabilities.theme && data.capabilities.theme.name ) || data.theme ) }
				</p>
			) }
			{ said && <Notice status="success" isDismissible={ false }>{ said }</Notice> }
		</div>
	);
}

export default function DesignReport( { design: fixed = 0 } ) {
	const [ list, setList ] = useState( null );
	const [ design, setDesign ] = useState( fixed );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ open, setOpen ] = useState( false );

	useEffect( () => {
		apiFetch( { path: '/dx-ui/v1/design' } )
			.then( ( res ) => {
				setList( res.designs || [] );
				if ( ! fixed && res.designs && res.designs.length ) {
					setDesign( res.designs[ 0 ].id );
				}
			} )
			.catch( ( e ) => setError( e.message || __( 'The designs could not be read.', 'dx-ui' ) ) );
	}, [] );

	useEffect( () => {
		if ( ! design ) {
			return;
		}
		setData( null );
		setError( '' );
		apiFetch( { path: '/dx-ui/v1/design/' + design } )
			.then( setData )
			.catch( ( e ) => {
				setData( { none: true, message: e.message || '' } );
			} );
	}, [ design ] );

	function rebuild() {
		setBusy( true );
		setError( '' );
		apiFetch( { path: '/dx-ui/v1/design/' + design, method: 'POST', data: { action: 'rebuild' } } )
			.then( ( res ) => {
				setData( res );
				setList( ( l ) => ( l || [] ).map( ( d ) => ( d.id === design ? { ...d, document: true, summary: res.summary, error: '' } : d ) ) );
			} )
			.catch( ( e ) => setError( e.message || __( 'The document could not be built.', 'dx-ui' ) ) )
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
		<section className="dxui-section dxui-report" aria-labelledby="dxui-report-title">
			<div className="dxui-section-head">
				<div>
					<p className="dxui-section-kicker">{ __( 'Your designs', 'dx-ui' ) }</p>
					<h3 id="dxui-report-title">{ __( 'Design report', 'dx-ui' ) }</h3>
					<p className="dxui-muted">
						{ __( 'What the import read of a design and what it made of it: its pages and their sections, its header and footer, its navigation, its tokens and fonts, what it does and what did not reach the page. Written at the end of every import; read by the pages in the team’s style.', 'dx-ui' ) }
					</p>
				</div>
			</div>
			{ ! fixed && list.length > 1 && (
				<SelectControl
					label={ __( 'Design', 'dx-ui' ) }
					value={ String( design ) }
					options={ list.map( ( d ) => ( { value: String( d.id ), label: d.title + ( d.document ? '' : ' — ' + __( 'no document', 'dx-ui' ) ) } ) ) }
					onChange={ ( v ) => setDesign( parseInt( v, 10 ) ) }
					__nextHasNoMarginBottom
				/>
			) }
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! data && <Spinner /> }
			{ data && data.none && (
				<div className="dxui-report__none">
					<p>{ data.message || __( 'The design has no document yet.', 'dx-ui' ) }</p>
					<Button variant="secondary" onClick={ rebuild } isBusy={ busy } disabled={ busy }>
						{ __( 'Build the document from the conversion snapshot', 'dx-ui' ) }
					</Button>
				</div>
			) }
			{ doc && s && (
				<>
					<ul className="dxui-report__facts">
						<li><strong>{ __( 'Source', 'dx-ui' ) }</strong><span>{ doc.source.kind }{ doc.source.name ? ' · ' + doc.source.name : '' }</span></li>
						<li><strong>{ __( 'Pages', 'dx-ui' ) }</strong><span>{ sprintf( /* translators: 1: pages, 2: sections. */ __( '%1$d, with %2$d sections', 'dx-ui' ), s.pages, s.sections ) }</span></li>
						<li><strong>{ __( 'Header', 'dx-ui' ) }</strong><span>{ PLACED[ s.header ] || s.header }{ s.nav_items ? ' · ' + sprintf( /* translators: %d: menu items. */ _n( '%d menu item', '%d menu items', s.nav_items, 'dx-ui' ), s.nav_items ) : '' }</span></li>
						<li><strong>{ __( 'Footer', 'dx-ui' ) }</strong><span>{ PLACED[ s.footer ] || s.footer }</span></li>
						<li><strong>{ __( 'Tokens', 'dx-ui' ) }</strong><span>{ s.tokens || __( 'none', 'dx-ui' ) }{ doc.tokens.roles.brand ? ' · ' + doc.tokens.roles.brand : '' }{ doc.tokens.roles.accent ? ' / ' + doc.tokens.roles.accent : '' }</span></li>
						<li><strong>{ __( 'Fonts', 'dx-ui' ) }</strong><span>{ doc.tokens.fonts.length ? doc.tokens.fonts.map( ( f ) => f.family ).join( ', ' ) : __( 'none of its own', 'dx-ui' ) }</span></li>
						<li><strong>{ __( 'Breakpoints', 'dx-ui' ) }</strong><span>{ doc.breakpoints.length ? doc.breakpoints.map( ( px ) => px + 'px' ).join( ', ' ) : __( 'none recorded', 'dx-ui' ) }</span></li>
						<li><strong>{ __( 'Pictures', 'dx-ui' ) }</strong><span>{ sprintf( /* translators: 1: images, 2: svg, 3: videos. */ __( '%1$d images, %2$d inline SVG, %3$d videos', 'dx-ui' ), doc.assets.images, doc.assets.svg, doc.assets.videos ) }</span></li>
						<li><strong>{ __( 'Fingerprint', 'dx-ui' ) }</strong><span><code>{ s.fingerprint.slice( 0, 12 ) }</code></span></li>
					</ul>

					<h4>{ __( 'Pages and their sections', 'dx-ui' ) }</h4>
					<ul className="dxui-report__pages">
						{ doc.pages.map( ( p ) => (
							<li key={ p.slug }>
								<div>
									<strong>{ p.title || p.slug }</strong> <code>{ p.slug }</code>
									{ p.id > 0 && <a href={ 'post.php?post=' + p.id + '&action=edit' }>{ __( 'Edit', 'dx-ui' ) }</a> }
									<span className="dxui-muted"> · { sprintf( /* translators: %d: words. */ _n( '%d word', '%d words', p.words, 'dx-ui' ), p.words ) }</span>
								</div>
								<ol className="dxui-report__sections">
									{ p.sections.map( ( sec ) => (
										<li key={ sec.index }>
											<span className="dxui-report__role">{ sec.role }</span>
											{ sec.heading && <span>{ sec.heading }</span> }
											{ sec.items && sec.items.length > 0 && <span className="dxui-muted"> · { sec.items.slice( 0, 6 ).join( ', ' ) }{ sec.items.length > 6 ? '…' : '' }</span> }
										</li>
									) ) }
								</ol>
							</li>
						) ) }
					</ul>

					{ doc.nav.length > 0 && (
						<>
							<h4>{ __( 'Navigation', 'dx-ui' ) }</h4>
							<Nav items={ doc.nav } />
						</>
					) }

					<h4>{ __( 'What the design does', 'dx-ui' ) }</h4>
					{ doc.behaviours.length === 0 && <p className="dxui-muted">{ __( 'Nothing its code declares.', 'dx-ui' ) }</p> }
					{ doc.behaviours.length > 0 && (
						<ul className="dxui-report__behaviours">
							{ doc.behaviours.map( ( b ) => (
								<li key={ b.name ? `${ b.kind }:${ b.name }` : b.kind }>
									<span>{ b.kind === 'state' && b.name ? sprintf( /* translators: 1: the state's name, 2: its kind (boolean, value, index). */ __( 'state %1$s (%2$s)', 'dx-ui' ), b.name, b.type ) : KINDS[ b.kind ] || b.kind }</span>
									<strong>{ b.count }</strong>
									<Carried value={ b.compiled } />
									<Interactivity row={ b } />
								</li>
							) ) }
						</ul>
					) }

					<Variation design={ design } />

					<Button variant="link" onClick={ () => setOpen( ! open ) }>
						{ open ? __( 'Hide the compiler’s report', 'dx-ui' ) : __( 'Show the compiler’s report', 'dx-ui' ) }
					</Button>
					{ open && (
						<div className="dxui-report__compiler">
							<p>
								{ doc.report.unevaluated.length
									? sprintf( /* translators: %d: rows. */ _n( '%d thing the compiler could not evaluate:', '%d things the compiler could not evaluate:', doc.report.unevaluated.length, 'dx-ui' ), doc.report.unevaluated.length )
									: __( 'The compiler evaluated everything it noticed.', 'dx-ui' ) }
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
									<p>{ __( 'Short of the design on the saved page:', 'dx-ui' ) }</p>
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
							<p className="dxui-muted">
								{ sprintf( /* translators: 1: plugin version, 2: time. */ __( 'Built by %1$s at %2$s.', 'dx-ui' ), doc.built.plugin, doc.built.at ) }
								{ ' ' }
								<Button variant="link" onClick={ rebuild } isBusy={ busy } disabled={ busy }>{ __( 'Build it again', 'dx-ui' ) }</Button>
							</p>
						</div>
					) }
				</>
			) }
		</section>
	);
}

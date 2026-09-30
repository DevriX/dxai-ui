import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, Spinner, ToggleControl } from '@wordpress/components';
import './speed.css';

/*
 * "Page speed": the two things that cost a design's pages their first paint on a phone. Its fonts come from Google
 * through a chain of requests (Font_Host copies them here), and the theme prints all of its stylesheet in every
 * page (Theme_Trim prints only the part the page can use). Both leave the page looking the same.
 */
const kb = ( bytes ) => Math.max( 1, Math.round( bytes / 1024 ) );

export default function Speed() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );
	const [ note, setNote ] = useState( '' );

	useEffect( () => {
		apiFetch( { path: '/dxai-ui/v1/speed' } )
			.then( setData )
			.catch( ( e ) => setError( e.message || __( 'The page speed could not be read.', 'dxai-ui' ) ) );
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

	function run( action, design = 0 ) {
		setBusy( action + design );
		setNote( '' );
		apiFetch( { path: '/dxai-ui/v1/speed', method: 'POST', data: { action, design } } )
			.then( ( report ) => {
				setData( report );
				if ( action === 'fonts-apply' ) {
					setNote(
						report.done && report.done.complete
							? __( 'The fonts are on this site now.', 'dxai-ui' )
							: __( 'Some font files could not be fetched from Google just now. The page still shows them; the rest is copied the next time it is viewed.', 'dxai-ui' )
					);
				}
			} )
			.catch( ( e ) => setError( e.message || __( 'Could not change the page speed.', 'dxai-ui' ) ) )
			.finally( () => setBusy( '' ) );
	}

	const label = {
		local: __( 'From this site', 'dxai-ui' ),
		partial: __( 'Partly from this site', 'dxai-ui' ),
		remote: __( 'From Google', 'dxai-ui' ),
		none: __( 'No web fonts', 'dxai-ui' ),
	};

	return (
		<section className="dxai-section dxai-speed" aria-labelledby="dxai-speed-title">
			<div className="dxai-section-head">
				<div>
					<p className="dxai-section-kicker">{ __( 'Your pages', 'dxai-ui' ) }</p>
					<h3 id="dxai-speed-title">{ __( 'Page speed', 'dxai-ui' ) }</h3>
					<p className="dxai-muted">
						{ sprintf(
							/* translators: %s: theme name. */
							__( 'A phone waits for the fonts and for the whole of the %s stylesheet before it paints. Both are cut down without changing how the pages look.', 'dxai-ui' ),
							data.theme
						) }
					</p>
				</div>
			</div>

			<h4>{ __( 'Fonts', 'dxai-ui' ) }</h4>
			<ul className="dxai-speed__list">
				{ data.designs.map( ( d ) => (
					<li key={ d.id }>
						<div>
							<strong>{ d.title }</strong>
							<span className="dxai-muted">
								{ d.fonts.families.length ? d.fonts.families.join( ', ' ) : '' }
								{ d.fonts.files > 0 && ' · ' + sprintf(
									/* translators: 1: number of files, 2: size in KB. */
									_n( '%1$d file, %2$d KB', '%1$d files, %2$d KB', d.fonts.files, 'dxai-ui' ),
									d.fonts.files,
									kb( d.fonts.bytes )
								) }
							</span>
						</div>
						<span className={ 'dxai-speed__state dxai-speed__state--' + d.fonts.state }>{ label[ d.fonts.state ] }</span>
						{ ( d.fonts.state === 'remote' || d.fonts.state === 'partial' ) && (
							<Button variant="primary" onClick={ () => run( 'fonts-apply', d.id ) } isBusy={ busy === 'fonts-apply' + d.id } disabled={ !! busy }>
								{ __( 'Copy the fonts here', 'dxai-ui' ) }
							</Button>
						) }
						{ ( d.fonts.state === 'local' || d.fonts.state === 'partial' ) && (
							<Button variant="tertiary" onClick={ () => run( 'fonts-revert', d.id ) } disabled={ !! busy }>
								{ __( 'Use Google again', 'dxai-ui' ) }
							</Button>
						) }
					</li>
				) ) }
			</ul>
			{ note && <Notice status="success" isDismissible={ false }>{ note }</Notice> }

			<h4>{ __( 'Theme stylesheet', 'dxai-ui' ) }</h4>
			<ToggleControl
				label={ __( 'Print only the part of the theme’s stylesheet each page uses', 'dxai-ui' ) }
				help={ __( 'Applies to visitors who are not logged in, on pages that show a design. Anything the page needs — hover, open menus, classes a script adds — stays.', 'dxai-ui' ) }
				checked={ data.trim.enabled }
				onChange={ ( on ) => run( on ? 'trim-on' : 'trim-off' ) }
				disabled={ !! busy }
			/>
			{ data.trim.enabled && data.trim.pages.length > 0 && (
				<ul className="dxai-speed__list dxai-speed__list--sizes">
					{ data.trim.pages.map( ( p ) => (
						<li key={ p.id }>
							<span>{ p.title }</span>
							<strong>{ sprintf(
								/* translators: 1: size before in KB, 2: size after in KB. */
								__( '%1$d KB → %2$d KB', 'dxai-ui' ),
								kb( p.before ),
								kb( p.after )
							) }</strong>
						</li>
					) ) }
				</ul>
			) }
			{ data.trim.enabled && ! data.trim.pages.length && (
				<p className="dxai-muted">{ __( 'Nothing to show yet: the sizes appear after the first visit to a page.', 'dxai-ui' ) }</p>
			) }
		</section>
	);
}

import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, SelectControl, Spinner, ToggleControl } from '@wordpress/components';
import './theme-colors.css';

/*
 * "Theme colours": which of a design's colours follow the active theme's
 * (Theme_Binding). The binding is applied when a page is shown, so a change
 * here — or in the theme's own colour settings — reaches every page of the
 * design on the next load, and nothing in the pages is rewritten.
 */

const TIER = {
	auto: __( 'Automatic', 'dxai-ui' ),
	review: __( 'Review', 'dxai-ui' ),
	chosen: __( 'Chosen', 'dxai-ui' ),
};

function Swatch( { color, label } ) {
	return <span className="dxai-theme-colors__swatch" style={ { background: color } } title={ label || color } aria-hidden="true" />;
}

export default function ThemeColors() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ home, setHome ] = useState( 0 );
	const [ draft, setDraft ] = useState( {} );
	const [ follow, setFollow ] = useState( true );
	const [ showAll, setShowAll ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ saved, setSaved ] = useState( '' );

	useEffect( () => {
		apiFetch( { path: '/dxai-ui/v1/theme-colors' } )
			.then( ( res ) => {
				setData( res );
				if ( res.designs && res.designs.length ) {
					setHome( res.designs[ 0 ].id );
				}
			} )
			.catch( ( e ) => setError( e.message || __( 'The theme colours could not be read.', 'dxai-ui' ) ) );
	}, [] );

	const design = data && data.designs ? data.designs.find( ( d ) => d.id === home ) : null;

	useEffect( () => {
		if ( design ) {
			const next = {};
			design.rows.forEach( ( r ) => {
				next[ r.token ] = r.to;
			} );
			setDraft( next );
			setFollow( ( design.mode || design.default ) === 'follow' );
		}
	}, [ home, data ] );

	// The "Saved" note belongs to the design it was saved for.
	useEffect( () => setSaved( '' ), [ home ] );

	if ( error ) {
		return <Notice status="error" isDismissible={ false }>{ error }</Notice>;
	}
	if ( ! data ) {
		return <Spinner />;
	}
	if ( ! data.designs.length ) {
		return null;
	}

	const theme = data.theme;
	const entries = [ ...theme.entries, ...Object.keys( theme.anchors || {} ).map( ( slug ) => ( { slug, name: slug === 'white' ? __( 'White', 'dxai-ui' ) : __( 'Black', 'dxai-ui' ), hex: theme.anchors[ slug ] } ) ) ];
	const hexOf = ( slug ) => ( entries.find( ( e ) => e.slug === slug ) || {} ).hex || '';
	const options = [
		{ label: __( 'Keep the design’s colour', 'dxai-ui' ), value: '' },
		...entries.map( ( e ) => ( { label: `${ e.name } (${ e.hex || e.slug })`, value: e.slug } ) ),
	];
	const rows = design ? design.rows.filter( ( r ) => showAll || r.to || draft[ r.token ] || r.text ) : [];

	function classes( action ) {
		setBusy( true );
		setSaved( '' );
		apiFetch( { path: '/dxai-ui/v1/theme-colors/classes', method: 'POST', data: { design: home, action } } )
			.then( ( report ) => {
				setData( { ...data, designs: data.designs.map( ( d ) => ( d.id === report.id ? report : d ) ) } );
				setSaved(
					action === 'apply'
						? sprintf(
							/* translators: %d: number of pages. */
							_n( 'The theme’s classes are in %d page.', 'The theme’s classes are in %d pages.', report.done.posts, 'dxai-ui' ),
							report.done.posts
						)
						: __( 'The design’s own classes are back.', 'dxai-ui' )
				);
			} )
			.catch( ( e ) => setError( e.message || __( 'Could not change the classes.', 'dxai-ui' ) ) )
			.finally( () => setBusy( false ) );
	}

	function save( propose ) {
		setBusy( true );
		setSaved( '' );
		const overrides = {};
		design.rows.forEach( ( r ) => {
			if ( ( draft[ r.token ] || '' ) !== ( r.to || '' ) ) {
				overrides[ r.token ] = draft[ r.token ] || '';
			}
		} );
		apiFetch( {
			path: propose ? '/dxai-ui/v1/theme-colors/propose' : '/dxai-ui/v1/theme-colors',
			method: 'POST',
			data: propose ? { design: home } : { design: home, mode: follow ? 'follow' : 'keep', overrides },
		} )
			.then( ( report ) => {
				setData( { ...data, designs: data.designs.map( ( d ) => ( d.id === report.id ? report : d ) ) } );
				setSaved( propose ? __( 'Proposed again for this theme.', 'dxai-ui' ) : __( 'Saved. The pages of this design show it on their next load.', 'dxai-ui' ) );
			} )
			.catch( ( e ) => setError( e.message || __( 'Could not save.', 'dxai-ui' ) ) )
			.finally( () => setBusy( false ) );
	}

	return (
		<section className="dxai-section dxai-theme-colors" aria-labelledby="dxai-theme-colors-title">
			<div className="dxai-section-head">
				<div>
					<p className="dxai-section-kicker">{ __( 'Your theme', 'dxai-ui' ) }</p>
					<h3 id="dxai-theme-colors-title">{ __( 'Theme colours', 'dxai-ui' ) }</h3>
					<p className="dxai-muted">
						{ theme.has_palette
							? sprintf(
								/* translators: 1: theme name, 2: number of colours. */
								_n( '%1$s has %2$d colour of its own. A design that follows it takes the theme’s colours by role, and follows later changes of them.', '%1$s has %2$d colours of its own. A design that follows it takes the theme’s colours by role, and follows later changes of them.', theme.entries.length, 'dxai-ui' ),
								theme.name,
								theme.entries.length
							)
							: sprintf(
								/* translators: %s: theme name. */
								__( '%s has no colour settings, so designs keep their own colours.', 'dxai-ui' ),
								theme.name
							) }
					</p>
				</div>
			</div>
			{ theme.has_palette && (
				<>
					<div className="dxai-theme-colors__palette" aria-label={ __( 'The theme’s colours', 'dxai-ui' ) }>
						{ theme.entries.map( ( e ) => (
							<span key={ e.slug } className="dxai-theme-colors__chip">
								<Swatch color={ e.hex } />
								{ e.name }
							</span>
						) ) }
					</div>
					{ theme.user_override && (
						<Notice status="warning" isDismissible={ false }>
							{ __( 'Colours set in Appearance > Editor > Styles override the theme’s own settings: changes made in the theme’s options will not show until those are reset.', 'dxai-ui' ) }
						</Notice>
					) }
					{ data.designs.length > 1 && (
						<SelectControl
							label={ __( 'Design', 'dxai-ui' ) }
							value={ String( home ) }
							options={ data.designs.map( ( d ) => ( { label: d.title, value: String( d.id ) } ) ) }
							onChange={ ( v ) => setHome( Number( v ) ) }
						/>
					) }
					{ design && (
						<>
							<ToggleControl
								label={ __( 'Follow the theme’s colours', 'dxai-ui' ) }
								help={ follow ? __( 'Off keeps the design’s own colours, exactly as imported.', 'dxai-ui' ) : __( 'The design keeps its own colours.', 'dxai-ui' ) }
								checked={ follow }
								onChange={ setFollow }
							/>
							{ follow && design.review > 0 && (
								<Notice status="info" isDismissible={ false }>
									{ sprintf(
										/* translators: %d: number of colours. */
										_n( '%d colour changed noticeably to follow the theme. It is applied; check it below.', '%d colours changed noticeably to follow the theme. They are applied; check them below.', design.review, 'dxai-ui' ),
										design.review
									) }
								</Notice>
							) }
							{ follow && (
								<table className="widefat striped dxai-theme-colors__table">
									<thead>
										<tr>
											<th>{ __( 'Design colour', 'dxai-ui' ) }</th>
											<th>{ __( 'Follows', 'dxai-ui' ) }</th>
											<th>{ __( 'Text', 'dxai-ui' ) }</th>
										</tr>
									</thead>
									<tbody>
										{ rows.map( ( r ) => (
											<tr key={ r.token }>
												<td>
													<Swatch color={ r.design } />
													<code>{ r.token }</code>
													<span className="dxai-muted"> { r.design }</span>
												</td>
												<td>
													<div className="dxai-theme-colors__pick">
														{ draft[ r.token ] && <Swatch color={ hexOf( draft[ r.token ] ) } /> }
														<SelectControl
															label={ __( 'Theme colour', 'dxai-ui' ) }
															hideLabelFromVision
															value={ draft[ r.token ] || '' }
															options={ options }
															onChange={ ( v ) => setDraft( { ...draft, [ r.token ]: v } ) }
															__nextHasNoMarginBottom
														/>
														{ r.tier && draft[ r.token ] === r.to && <span className={ `dxai-theme-colors__tier is-${ r.tier }` }>{ TIER[ r.tier ] || r.tier }</span> }
													</div>
												</td>
												<td>
													{ r.text ? (
														<span title={ __( 'Chosen so the text stays readable on its backgrounds', 'dxai-ui' ) }>
															<Swatch color={ r.text } /> { r.text === r.design ? __( 'kept for readability', 'dxai-ui' ) : __( 'adjusted for readability', 'dxai-ui' ) }
														</span>
													) : (
														<span className="dxai-muted">—</span>
													) }
												</td>
											</tr>
										) ) }
									</tbody>
								</table>
							) }
							{ follow && (
								<ToggleControl
									label={ __( 'Show every colour of the design', 'dxai-ui' ) }
									checked={ showAll }
									onChange={ setShowAll }
								/>
							) }
							{ follow && design.classes && ( design.classes.applied || design.classes.swaps.length > 0 ) && (
								<div className="dxai-theme-colors__classes">
									<h4>{ __( 'Theme classes in the content', 'dxai-ui' ) }</h4>
									<p className="dxai-muted">
										{ design.classes.applied
											? __( 'The pages use the theme’s own names for these colours, so the block’s Colour panel shows the theme’s colour. Putting the design’s classes back restores each page as it was, unless it was edited since.', 'dxai-ui' )
											: __( 'The pages can use the theme’s own names for these colours, so the block’s Colour panel shows the theme’s colour. The pages look the same, and this can be undone.', 'dxai-ui' ) }
									</p>
									<ul className="dxai-theme-colors__swaps">
										{ design.classes.swaps.map( ( s ) => (
											<li key={ s.token }>
												<code>text-dxai-{ s.token }</code> → { s.class ? <code>{ s.class }</code> : sprintf(
													/* translators: %s: theme colour name. */
													__( 'the “%s” text colour', 'dxai-ui' ),
													s.setting
												) }
											</li>
										) ) }
									</ul>
									<Button variant="secondary" onClick={ () => classes( design.classes.applied ? 'revert' : 'apply' ) } disabled={ busy }>
										{ design.classes.applied ? __( 'Put the design’s classes back', 'dxai-ui' ) : __( 'Use the theme’s classes', 'dxai-ui' ) }
									</Button>
								</div>
							) }
							{ saved && <Notice status="success" isDismissible={ false }>{ saved }</Notice> }
							<div className="dxai-actions">
								<Button variant="primary" onClick={ () => save( false ) } isBusy={ busy } disabled={ busy }>
									{ __( 'Save', 'dxai-ui' ) }
								</Button>
								{ follow && (
									<Button variant="secondary" onClick={ () => save( true ) } disabled={ busy }>
										{ __( 'Propose again', 'dxai-ui' ) }
									</Button>
								) }
							</div>
						</>
					) }
				</>
			) }
		</section>
	);
}

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
	auto: __( 'Automatic', 'dx-ui' ),
	review: __( 'Review', 'dx-ui' ),
	chosen: __( 'Chosen', 'dx-ui' ),
};

function Swatch( { color, label } ) {
	return <span className="dxui-theme-colors__swatch" style={ { background: color } } title={ label || color } aria-hidden="true" />;
}

export default function ThemeColors( { design: fixed = 0 } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ home, setHome ] = useState( fixed );
	const [ draft, setDraft ] = useState( {} );
	const [ follow, setFollow ] = useState( true );
	const [ showAll, setShowAll ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ saved, setSaved ] = useState( '' );

	useEffect( () => {
		apiFetch( { path: '/dx-ui/v1/theme-colors' } )
			.then( ( res ) => {
				setData( res );
				if ( ! fixed && res.designs && res.designs.length ) {
					setHome( res.designs[ 0 ].id );
				}
			} )
			.catch( ( e ) => setError( e.message || __( 'The theme colours could not be read.', 'dx-ui' ) ) );
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
	const entries = [ ...theme.entries, ...Object.keys( theme.anchors || {} ).map( ( slug ) => ( { slug, name: slug === 'white' ? __( 'White', 'dx-ui' ) : __( 'Black', 'dx-ui' ), hex: theme.anchors[ slug ] } ) ) ];
	const hexOf = ( slug ) => ( entries.find( ( e ) => e.slug === slug ) || {} ).hex || '';
	const options = [
		{ label: __( 'Keep the design’s colour', 'dx-ui' ), value: '' },
		...entries.map( ( e ) => ( { label: `${ e.name } (${ e.hex || e.slug })`, value: e.slug } ) ),
	];
	const rows = design ? design.rows.filter( ( r ) => showAll || r.to || draft[ r.token ] || r.text ) : [];

	function classes( action ) {
		setBusy( true );
		setSaved( '' );
		apiFetch( { path: '/dx-ui/v1/theme-colors/classes', method: 'POST', data: { design: home, action } } )
			.then( ( report ) => {
				setData( { ...data, designs: data.designs.map( ( d ) => ( d.id === report.id ? report : d ) ) } );
				setSaved(
					action === 'apply'
						? sprintf(
							/* translators: %d: number of pages. */
							_n( 'The theme’s classes are in %d page.', 'The theme’s classes are in %d pages.', report.done.posts, 'dx-ui' ),
							report.done.posts
						)
						: __( 'The design’s own classes are back.', 'dx-ui' )
				);
			} )
			.catch( ( e ) => setError( e.message || __( 'Could not change the classes.', 'dx-ui' ) ) )
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
			path: propose ? '/dx-ui/v1/theme-colors/propose' : '/dx-ui/v1/theme-colors',
			method: 'POST',
			data: propose ? { design: home } : { design: home, mode: follow ? 'follow' : 'keep', overrides },
		} )
			.then( ( report ) => {
				setData( { ...data, designs: data.designs.map( ( d ) => ( d.id === report.id ? report : d ) ) } );
				setSaved( propose ? __( 'Proposed again for this theme.', 'dx-ui' ) : __( 'Saved. The pages of this design show it on their next load.', 'dx-ui' ) );
			} )
			.catch( ( e ) => setError( e.message || __( 'Could not save.', 'dx-ui' ) ) )
			.finally( () => setBusy( false ) );
	}

	return (
		<section className="dxui-section dxui-theme-colors" aria-labelledby="dxui-theme-colors-title">
			<div className="dxui-section-head">
				<div>
					<p className="dxui-section-kicker">{ __( 'Your theme', 'dx-ui' ) }</p>
					<h3 id="dxui-theme-colors-title">{ __( 'Theme colours', 'dx-ui' ) }</h3>
					<p className="dxui-muted">
						{ theme.has_palette
							? sprintf(
								/* translators: 1: theme name, 2: number of colours. */
								_n( '%1$s has %2$d colour of its own. A design that follows it takes the theme’s colours by role, and follows later changes of them.', '%1$s has %2$d colours of its own. A design that follows it takes the theme’s colours by role, and follows later changes of them.', theme.entries.length, 'dx-ui' ),
								theme.name,
								theme.entries.length
							)
							: sprintf(
								/* translators: %s: theme name. */
								__( '%s has no colour settings, so designs keep their own colours.', 'dx-ui' ),
								theme.name
							) }
					</p>
				</div>
			</div>
			{ theme.has_palette && (
				<>
					<div className="dxui-theme-colors__palette" aria-label={ __( 'The theme’s colours', 'dx-ui' ) }>
						{ theme.entries.map( ( e ) => (
							<span key={ e.slug } className="dxui-theme-colors__chip">
								<Swatch color={ e.hex } />
								{ e.name }
							</span>
						) ) }
					</div>
					{ theme.user_override && (
						<Notice status="warning" isDismissible={ false }>
							{ __( 'Colours set in Appearance > Editor > Styles override the theme’s own settings: changes made in the theme’s options will not show until those are reset.', 'dx-ui' ) }
						</Notice>
					) }
					{ ! fixed && data.designs.length > 1 && (
						<SelectControl
							label={ __( 'Design', 'dx-ui' ) }
							value={ String( home ) }
							options={ data.designs.map( ( d ) => ( { label: d.title, value: String( d.id ) } ) ) }
							onChange={ ( v ) => setHome( Number( v ) ) }
						/>
					) }
					{ design && (
						<>
							<ToggleControl
								label={ __( 'Follow the theme’s colours', 'dx-ui' ) }
								help={ follow ? __( 'Off keeps the design’s own colours, exactly as imported.', 'dx-ui' ) : __( 'The design keeps its own colours.', 'dx-ui' ) }
								checked={ follow }
								onChange={ setFollow }
							/>
							{ follow && design.review > 0 && (
								<Notice status="info" isDismissible={ false }>
									{ sprintf(
										/* translators: %d: number of colours. */
										_n( '%d colour changed noticeably to follow the theme. It is applied; check it below.', '%d colours changed noticeably to follow the theme. They are applied; check them below.', design.review, 'dx-ui' ),
										design.review
									) }
								</Notice>
							) }
							{ follow && (
								<table className="widefat striped dxui-theme-colors__table">
									<thead>
										<tr>
											<th>{ __( 'Design colour', 'dx-ui' ) }</th>
											<th>{ __( 'Follows', 'dx-ui' ) }</th>
											<th>{ __( 'Text', 'dx-ui' ) }</th>
										</tr>
									</thead>
									<tbody>
										{ rows.map( ( r ) => (
											<tr key={ r.token }>
												<td>
													<Swatch color={ r.design } />
													<code>{ r.token }</code>
													<span className="dxui-muted"> { r.design }</span>
												</td>
												<td>
													<div className="dxui-theme-colors__pick">
														{ draft[ r.token ] && <Swatch color={ hexOf( draft[ r.token ] ) } /> }
														<SelectControl
															label={ __( 'Theme colour', 'dx-ui' ) }
															hideLabelFromVision
															value={ draft[ r.token ] || '' }
															options={ options }
															onChange={ ( v ) => setDraft( { ...draft, [ r.token ]: v } ) }
															__nextHasNoMarginBottom
														/>
														{ r.tier && draft[ r.token ] === r.to && <span className={ `dxui-theme-colors__tier is-${ r.tier }` }>{ TIER[ r.tier ] || r.tier }</span> }
													</div>
												</td>
												<td>
													{ r.text ? (
														<span title={ __( 'Chosen so the text stays readable on its backgrounds', 'dx-ui' ) }>
															<Swatch color={ r.text } /> { r.text === r.design ? __( 'kept for readability', 'dx-ui' ) : __( 'adjusted for readability', 'dx-ui' ) }
														</span>
													) : (
														<span className="dxui-muted">—</span>
													) }
												</td>
											</tr>
										) ) }
									</tbody>
								</table>
							) }
							{ follow && (
								<ToggleControl
									label={ __( 'Show every colour of the design', 'dx-ui' ) }
									checked={ showAll }
									onChange={ setShowAll }
								/>
							) }
							{ follow && design.classes && ( design.classes.applied || design.classes.swaps.length > 0 ) && (
								<div className="dxui-theme-colors__classes">
									<h4>{ __( 'Theme classes in the content', 'dx-ui' ) }</h4>
									<p className="dxui-muted">
										{ design.classes.applied
											? __( 'The pages use the theme’s own names for these colours, so the block’s Colour panel shows the theme’s colour. Putting the design’s classes back restores each page as it was, unless it was edited since.', 'dx-ui' )
											: __( 'The pages can use the theme’s own names for these colours, so the block’s Colour panel shows the theme’s colour. The pages look the same, and this can be undone.', 'dx-ui' ) }
									</p>
									<ul className="dxui-theme-colors__swaps">
										{ design.classes.swaps.map( ( s ) => (
											<li key={ s.token }>
												<code>text-dxui-{ s.token }</code> → { s.class ? <code>{ s.class }</code> : sprintf(
													/* translators: %s: theme colour name. */
													__( 'the “%s” text colour', 'dx-ui' ),
													s.setting
												) }
											</li>
										) ) }
									</ul>
									<Button variant="secondary" onClick={ () => classes( design.classes.applied ? 'revert' : 'apply' ) } disabled={ busy }>
										{ design.classes.applied ? __( 'Put the design’s classes back', 'dx-ui' ) : __( 'Use the theme’s classes', 'dx-ui' ) }
									</Button>
								</div>
							) }
							{ saved && <Notice status="success" isDismissible={ false }>{ saved }</Notice> }
							<div className="dxui-actions">
								<Button variant="primary" onClick={ () => save( false ) } isBusy={ busy } disabled={ busy }>
									{ __( 'Save', 'dx-ui' ) }
								</Button>
								{ follow && (
									<Button variant="secondary" onClick={ () => save( true ) } disabled={ busy }>
										{ __( 'Propose again', 'dx-ui' ) }
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

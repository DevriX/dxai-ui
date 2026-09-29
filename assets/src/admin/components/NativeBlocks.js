import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, SelectControl, Spinner } from '@wordpress/components';
import './native-blocks.css';

/*
 * "Native blocks": the plugin adds a block of its own only where nothing else
 * says the same thing. A design imported before that rule holds some of them
 * anyway; this converts the ones that have a counterpart on this site (core's,
 * or the active theme's) and puts them back if asked. The pages look the same
 * (Native_Blocks keeps each page's content from before).
 */
export default function NativeBlocks() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ home, setHome ] = useState( 0 );
	const [ busy, setBusy ] = useState( false );
	const [ note, setNote ] = useState( '' );

	useEffect( () => {
		apiFetch( { path: '/dxai-ui/v1/native-blocks' } )
			.then( ( res ) => {
				setData( res );
				if ( res.designs && res.designs.length ) {
					setHome( res.designs[ 0 ].id );
				}
			} )
			.catch( ( e ) => setError( e.message || __( 'The native blocks could not be read.', 'dxai-ui' ) ) );
	}, [] );

	// The note belongs to the design it was written for.
	useEffect( () => setNote( '' ), [ home ] );

	if ( error ) {
		return <Notice status="error" isDismissible={ false }>{ error }</Notice>;
	}
	if ( ! data ) {
		return <Spinner />;
	}
	const design = data.designs.find( ( d ) => d.id === home );
	if ( ! design || ( ! design.applied && design.blocks === 0 ) ) {
		return null;
	}

	function run( action ) {
		setBusy( true );
		setNote( '' );
		apiFetch( { path: '/dxai-ui/v1/native-blocks', method: 'POST', data: { design: home, action } } )
			.then( ( report ) => {
				setData( { ...data, designs: data.designs.map( ( d ) => ( d.id === report.id ? report : d ) ) } );
				if ( action === 'apply' ) {
					setNote(
						sprintf(
							/* translators: 1: number of blocks, 2: number of pages. */
							_n( '%1$d block is now a native block, in %2$d page.', '%1$d blocks are now native blocks, in %2$d pages.', report.done.blocks, 'dxai-ui' ),
							report.done.blocks,
							report.done.posts
						)
					);
				} else if ( report.done.edited > 0 ) {
					setNote(
						sprintf(
							/* translators: 1: number of pages put back, 2: number of pages left as they are. */
							__( 'Put back in %1$d pages. %2$d pages were edited since and stay as they are.', 'dxai-ui' ),
							report.done.posts,
							report.done.edited
						)
					);
				} else {
					setNote( __( 'The plugin’s own blocks are back.', 'dxai-ui' ) );
				}
			} )
			.catch( ( e ) => setError( e.message || __( 'Could not change the blocks.', 'dxai-ui' ) ) )
			.finally( () => setBusy( false ) );
	}

	return (
		<section className="dxai-section dxai-native-blocks" aria-labelledby="dxai-native-blocks-title">
			<div className="dxai-section-head">
				<div>
					<p className="dxai-section-kicker">{ __( 'Your pages', 'dxai-ui' ) }</p>
					<h3 id="dxai-native-blocks-title">{ __( 'Native blocks', 'dxai-ui' ) }</h3>
					<p className="dxai-muted">
						{ sprintf(
							/* translators: %s: theme name. */
							__( 'Where WordPress or %s has a block that says the same thing, the page uses it instead of a DX block: you get its own controls, and the page looks the same.', 'dxai-ui' ),
							data.theme
						) }
					</p>
				</div>
			</div>
			{ data.designs.length > 1 && (
				<SelectControl
					label={ __( 'Design', 'dxai-ui' ) }
					value={ String( home ) }
					options={ data.designs.map( ( d ) => ( { label: d.title, value: String( d.id ) } ) ) }
					onChange={ ( v ) => setHome( Number( v ) ) }
				/>
			) }
			<ul className="dxai-native-blocks__list">
				{ design.converters.map( ( c ) => (
					<li key={ c.id }>
						<span>{ c.label }</span>
						<strong>{ c.count }</strong>
					</li>
				) ) }
			</ul>
			<p className="dxai-muted">
				{ design.applied
					? __( 'Put the DX blocks back to restore each page as it was before; a page you edited since is left as you made it.', 'dxai-ui' )
					: sprintf(
						/* translators: 1: number of blocks, 2: number of pages. */
						_n( '%1$d block in %2$d page can be replaced. This can be undone.', '%1$d blocks in %2$d pages can be replaced. This can be undone.', design.blocks, 'dxai-ui' ),
						design.blocks,
						design.posts
					) }
			</p>
			{ note && <Notice status="success" isDismissible={ false }>{ note }</Notice> }
			<div className="dxai-actions">
				{ design.blocks > 0 && (
					<Button variant="primary" onClick={ () => run( 'apply' ) } isBusy={ busy } disabled={ busy }>
						{ __( 'Use native blocks', 'dxai-ui' ) }
					</Button>
				) }
				{ design.applied && (
					<Button variant="secondary" onClick={ () => run( 'revert' ) } disabled={ busy }>
						{ __( 'Put the DX blocks back', 'dxai-ui' ) }
					</Button>
				) }
			</div>
		</section>
	);
}

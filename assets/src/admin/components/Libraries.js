import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, CheckboxControl, Notice, SelectControl, Spinner, TextControl } from '@wordpress/components';
import { dateOf } from '../router';
import './libraries.css';

/*
 * "Team libraries": the templates the site has for the pages made in the team's style — the built-in one (American Restoration's) and
 * the ones made here from the team's own exports (Libraries_Controller, Library_Store). Upload a WordPress export of a site the team
 * built (or a file of block markup) for a theme, and the plugin reads its sections into layouts; upload more to train it; the pages made
 * from a Home on that theme are laid out with them. A theme with none gets the generic library.
 */

const roleLine = ( byRole ) =>
	Object.keys( byRole || {} )
		.map( ( r ) => `${ r } ${ byRole[ r ] }` )
		.join( ', ' );

export default function Libraries() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );
	const [ said, setSaid ] = useState( '' );
	const [ label, setLabel ] = useState( '' );
	const [ theme, setTheme ] = useState( '' );
	const [ extend, setExtend ] = useState( true );
	const [ opened, setOpened ] = useState( null );
	const fileRef = useRef( null );
	const addRefs = useRef( {} );

	const load = () =>
		apiFetch( { path: '/dxai-ui/v1/libraries' } )
			.then( ( d ) => {
				setData( d );
				if ( ! theme ) {
					setTheme( d.theme );
				}
			} )
			.catch( ( e ) => setError( e.message || __( 'The libraries could not be read.', 'dxai-ui' ) ) );

	useEffect( () => {
		load();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( error && ! data ) {
		return <Notice status="error" isDismissible={ false }>{ error }</Notice>;
	}
	if ( ! data ) {
		return <Spinner />;
	}

	const builtIn = data.templates.find( ( t ) => ! t.stored );
	const canExtend = !! builtIn && ( builtIn.themes.includes( theme ) || theme === data.theme && data.current === builtIn.slug );
	const fail = ( e ) => {
		setBusy( '' );
		setError( e.message || __( 'That did not work.', 'dxai-ui' ) );
	};
	const done = ( d, what ) => {
		setBusy( '' );
		setError( '' );
		setSaid( what );
		if ( d && d.overview ) {
			setData( d.overview );
			if ( d.slug ) {
				setOpened( d );
			}
		} else {
			load();
		}
	};
	const create = ( ev ) => {
		ev.preventDefault();
		const file = fileRef.current && fileRef.current.files && fileRef.current.files[ 0 ];
		if ( ! label.trim() || ! file ) {
			setError( __( 'Give the library a name and choose the export to read.', 'dxai-ui' ) );
			return;
		}
		const fd = new window.FormData();
		fd.append( 'label', label.trim() );
		fd.append( 'theme', theme );
		fd.append( 'extends', extend && canExtend ? builtIn.slug : '' );
		fd.append( 'file', file );
		setBusy( 'create' );
		apiFetch( { path: '/dxai-ui/v1/libraries', method: 'POST', body: fd } )
			.then( ( d ) => {
				setLabel( '' );
				if ( fileRef.current ) {
					fileRef.current.value = '';
				}
				done( d, sprintf( /* translators: 1: the library's name, 2: how many layouts. */ __( 'Made "%1$s": %2$d layouts.', 'dxai-ui' ), d.label, d.layouts ) );
			} )
			.catch( fail );
	};
	const add = ( slug ) => {
		const input = addRefs.current[ slug ];
		const file = input && input.files && input.files[ 0 ];
		if ( ! file ) {
			return;
		}
		const fd = new window.FormData();
		fd.append( 'file', file );
		setBusy( 'add:' + slug );
		apiFetch( { path: `/dxai-ui/v1/libraries/${ slug }/sources`, method: 'POST', body: fd } )
			.then( ( d ) => {
				input.value = '';
				done( d, sprintf( /* translators: 1: the library's name, 2: how many layouts. */ __( 'Added to "%1$s" and built again: %2$d layouts.', 'dxai-ui' ), d.label, d.layouts ) );
			} )
			.catch( fail );
	};
	const build = ( slug ) => {
		setBusy( 'build:' + slug );
		apiFetch( { path: `/dxai-ui/v1/libraries/${ slug }/build`, method: 'POST' } )
			.then( ( d ) => done( d, sprintf( /* translators: 1: the library's name, 2: how many layouts. */ __( 'Built "%1$s" again: %2$d layouts.', 'dxai-ui' ), d.label, d.layouts ) ) )
			.catch( fail );
	};
	const remove = ( t ) => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( sprintf( /* translators: %s: the library's name. */ __( 'Remove the library "%s" with its exports and layouts? The pages already made keep their sections.', 'dxai-ui' ), t.label ) ) ) {
			return;
		}
		setBusy( 'delete:' + t.slug );
		apiFetch( { path: `/dxai-ui/v1/libraries/${ t.slug }`, method: 'DELETE' } )
			.then( ( d ) => {
				setOpened( null );
				done( d, sprintf( /* translators: %s: the library's name. */ __( 'Removed "%s".', 'dxai-ui' ), t.label ) );
			} )
			.catch( fail );
	};
	const open = ( t ) => {
		if ( opened && opened.slug === t.slug ) {
			setOpened( null );
			return;
		}
		apiFetch( { path: `/dxai-ui/v1/libraries/${ t.slug }` } ).then( setOpened ).catch( fail );
	};

	return (
		<section className="dxai-section dxai-libraries" aria-labelledby="dxai-libraries-title">
			<div className="dxai-section-head">
				<div>
					<p className="dxai-section-kicker">{ __( 'Your site', 'dxai-ui' ) }</p>
					<h3 id="dxai-libraries-title">{ __( 'Team libraries', 'dxai-ui' ) }</h3>
				</div>
			</div>
			<p className="dxai-muted">
				{ __( 'The sections the pages made in the team\'s style are laid out with, by theme. The plugin carries American Restoration\'s; a library made here from the team\'s own export (a WordPress export of a site they built, or a file of block markup) does the same for its theme — upload more exports to train it. A theme with no library gets the generic one: the same sections in core blocks, in the theme\'s colours.', 'dxai-ui' ) }
				{ ' ' }
				{ data.generic
					? sprintf( /* translators: %s: the theme's name. */ __( 'This site runs %s, which has no library of its own: the generic library is in use.', 'dxai-ui' ), data.themeName )
					: sprintf( /* translators: %s: the library's name. */ __( 'In use now: %s.', 'dxai-ui' ), ( data.templates.find( ( t ) => t.inUse ) || {} ).label || data.current ) }
			</p>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ said && (
				<Notice status="success" onRemove={ () => setSaid( '' ) }>
					{ said }
				</Notice>
			) }
			<table className="widefat striped dxai-libraries__table">
				<thead>
					<tr>
						<th>{ __( 'Library', 'dxai-ui' ) }</th>
						<th>{ __( 'For', 'dxai-ui' ) }</th>
						<th>{ __( 'Layouts', 'dxai-ui' ) }</th>
						<th>{ __( 'Exports', 'dxai-ui' ) }</th>
						<th>{ __( 'Last built', 'dxai-ui' ) }</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					{ data.templates.map( ( t ) => (
						<tr key={ t.slug }>
							<td>
								<strong>{ t.label }</strong>
								{ t.inUse && <span className="dxai-chip dxai-chip--done">{ __( 'in use', 'dxai-ui' ) }</span> }
								{ ! t.stored && <span className="dxai-muted"> · { __( 'built in', 'dxai-ui' ) }</span> }
								{ t.extends && <span className="dxai-muted"> · { sprintf( /* translators: %s: a template's slug. */ __( 'extends %s', 'dxai-ui' ), t.extends ) }</span> }
							</td>
							<td>{ t.themes.join( ', ' ) }{ t.kinds.includes( 'dx' ) ? ' ' + __( '+ DX themes', 'dxai-ui' ) : '' }</td>
							<td title={ roleLine( t.byRole ) }>{ t.layouts }</td>
							<td>{ t.stored ? t.sources.length : '—' }</td>
							<td>{ t.report ? dateOf( t.report.at ) : '—' }</td>
							<td className="dxai-libraries__actions">
								{ t.stored && (
									<>
										<Button variant="link" onClick={ () => open( t ) }>{ opened && opened.slug === t.slug ? __( 'Close', 'dxai-ui' ) : __( 'Open', 'dxai-ui' ) }</Button>
										<label className="dxai-libraries__add">
											<input type="file" accept=".xml,.html,.htm,.txt" ref={ ( el ) => ( addRefs.current[ t.slug ] = el ) } onChange={ () => add( t.slug ) } disabled={ !! busy } />
											<span className="components-button is-secondary is-small">{ busy === 'add:' + t.slug ? __( 'Reading…', 'dxai-ui' ) : __( 'Add an export', 'dxai-ui' ) }</span>
										</label>
										<Button variant="secondary" size="small" isBusy={ busy === 'build:' + t.slug } disabled={ !! busy } onClick={ () => build( t.slug ) }>{ __( 'Build again', 'dxai-ui' ) }</Button>
										<Button variant="tertiary" size="small" isDestructive isBusy={ busy === 'delete:' + t.slug } disabled={ !! busy } onClick={ () => remove( t ) }>{ __( 'Remove', 'dxai-ui' ) }</Button>
									</>
								) }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
			{ opened && (
				<div className="dxai-libraries__open">
					<h4>{ opened.label }</h4>
					{ opened.report && (
						<p className="dxai-muted">
							{ sprintf(
								/* translators: 1: how many layouts, 2: how many pages, 3: how many not taken. */
								__( '%1$d layouts from %2$d pages; %3$d sections not taken.', 'dxai-ui' ),
								opened.report.made,
								opened.report.pages,
								opened.report.errors.length
							) }
						</p>
					) }
					{ opened.layoutList && opened.layoutList.length > 0 && (
						<table className="widefat striped">
							<thead>
								<tr>
									<th>{ __( 'Layout', 'dxai-ui' ) }</th>
									<th>{ __( 'Role', 'dxai-ui' ) }</th>
									<th>{ __( 'List', 'dxai-ui' ) }</th>
									<th>{ __( 'Words kept', 'dxai-ui' ) }</th>
									<th>{ __( 'Blocks', 'dxai-ui' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ opened.layoutList.map( ( l ) => (
									<tr key={ l.id }>
										<td><code>{ l.id }</code></td>
										<td>{ l.role }</td>
										<td>{ l.list || '—' }</td>
										<td>{ l.kept } / { l.slots }</td>
										<td className="dxai-muted">{ l.blocks.join( ', ' ) }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
					{ opened.report && opened.report.lines && opened.report.lines.length > 0 && (
						<details>
							<summary>{ __( 'The build\'s report', 'dxai-ui' ) }</summary>
							<pre className="dxai-libraries__log">{ opened.report.lines.join( '\n' ) }</pre>
						</details>
					) }
				</div>
			) }
			<form className="dxai-libraries__new" onSubmit={ create }>
				<h4>{ __( 'A new library from an export', 'dxai-ui' ) }</h4>
				<div className="dxai-libraries__fields">
					<TextControl label={ __( 'Name', 'dxai-ui' ) } value={ label } onChange={ setLabel } placeholder={ __( 'The team\'s name, or the theme\'s', 'dxai-ui' ) } __nextHasNoMarginBottom />
					<SelectControl label={ __( 'For the theme', 'dxai-ui' ) } value={ theme } options={ data.themes.map( ( th ) => ( { value: th.slug, label: th.name + ( th.slug === data.theme ? ' ' + __( '(active)', 'dxai-ui' ) : '' ) } ) ) } onChange={ setTheme } __nextHasNoMarginBottom />
					<div className="components-base-control">
						<label className="components-base-control__label" htmlFor="dxai-library-file">{ __( 'The export', 'dxai-ui' ) }</label>
						<input id="dxai-library-file" type="file" accept=".xml,.html,.htm,.txt" ref={ fileRef } />
						<p className="components-base-control__help">{ __( 'A WordPress export (Tools › Export, pages) of a site the team built, or a page copied from the editor\'s code view. Up to 25 MB. Only its arrangement and the lines every page can say are kept: nothing of that site\'s names, numbers, pictures or forms.', 'dxai-ui' ) }</p>
					</div>
					{ canExtend && (
						<CheckboxControl label={ sprintf( /* translators: %s: the built-in template's name. */ __( 'Also use the built-in library (%s)', 'dxai-ui' ), builtIn.label ) } checked={ extend } onChange={ setExtend } __nextHasNoMarginBottom />
					) }
				</div>
				<Button variant="primary" type="submit" isBusy={ busy === 'create' } disabled={ !! busy }>{ busy === 'create' ? __( 'Reading and building…', 'dxai-ui' ) : __( 'Make the library', 'dxai-ui' ) }</Button>
			</form>
		</section>
	);
}

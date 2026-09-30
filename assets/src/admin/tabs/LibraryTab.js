import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

function Group( { title, items, empty } ) {
	return (
		<div>
			<h3>{ title }</h3>
			{ ( items || [] ).length === 0 ? (
				<p>{ empty }</p>
			) : (
				items.map( ( item ) => (
					<div className="dxai-card" key={ ( item.type || 'item' ) + '-' + item.id }>
						<strong>{ item.title }</strong>
						<p>{ ( item.type || 'pattern' ) + ' · ID ' + item.id }</p>
						{ item.view && <a href={ item.view } style={ { marginRight: '12px' } }>View</a> }
						{ item.edit && <a href={ item.edit }>Edit in WordPress</a> }
					</div>
				) )
			) }
		</div>
	);
}

export default function LibraryTab() {
	const [ library, setLibrary ] = useState( {
		patterns: [],
		conversions: [],
		pages: [],
		template_parts: [],
		navigations: [],
		form_entries: [],
	} );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		apiFetch( { path: '/dxai-ui/v1/patterns' } )
			.then( ( res ) => setLibrary( {
				patterns: res.patterns || [],
				conversions: res.conversions || [],
				pages: res.pages || [],
				template_parts: res.template_parts || [],
				navigations: res.navigations || [],
				form_entries: res.form_entries || [],
			} ) )
			.catch( ( err ) => setError( err.message || 'Unable to load library.' ) );
	}, [] );

	return (
		<div>
			{ error && <div className="dxai-notice is-error">{ error }</div> }
			<Group title="Generated pages" items={ library.pages } empty="No generated pages yet." />
			<Group title="Synced patterns" items={ library.patterns } empty="No DX UI patterns saved yet." />
			<Group title="Headers / footers" items={ library.template_parts } empty="No template parts yet." />
			<Group title="Menus" items={ library.navigations } empty="No navigation menus yet." />
			<Group title="Conversions (revisions)" items={ library.conversions } empty="No conversion snapshots yet." />
			<Group title="Form entries" items={ library.form_entries } empty="No form submissions yet." />
		</div>
	);
}

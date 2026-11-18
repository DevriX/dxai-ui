import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, Spinner } from '@wordpress/components';

export default function FormsScreen() {
	const [ entries, setEntries ] = useState( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		apiFetch( { path: '/dx-ui/v1/forms/entries' } )
			.then( ( res ) => setEntries( res.entries || [] ) )
			.catch( ( err ) => setError( err.message || __( 'Unable to load form entries.', 'dx-ui' ) ) );
	}, [] );

	function downloadCsv() {
		apiFetch( { path: '/dx-ui/v1/forms/export' } )
			.then( ( res ) => {
				const blob = new window.Blob( [ res.csv || '' ], { type: 'text/csv;charset=utf-8' } );
				const url = window.URL.createObjectURL( blob );
				const link = document.createElement( 'a' );
				link.href = url;
				link.download = res.filename || 'dxui-form-entries.csv';
				link.click();
				window.URL.revokeObjectURL( url );
			} )
			.catch( ( err ) => setError( err.message || __( 'Export failed.', 'dx-ui' ) ) );
	}

	const total = entries ? entries.length : 0;
	const withEmail = entries ? entries.filter( ( entry ) => entry.email ).length : 0;
	const stats = [
		{ label: __( 'Submissions', 'dx-ui' ), value: total },
		{ label: __( 'With email', 'dx-ui' ), value: withEmail },
		{ label: __( 'Export', 'dx-ui' ), value: __( 'CSV', 'dx-ui' ) },
	];

	return (
		<div className="dxui-screen">
			<ul className="dxui-stats">
				{ stats.map( ( item ) => (
					<li key={ item.label }>
						<strong>{ item.value }</strong>
						{ item.label }
					</li>
				) ) }
			</ul>
			<div className="dxui-panel dxui-panel--stack">
				{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
				{ entries === null && ! error && <Spinner /> }
				{ entries && (
					<>
						<div className="dxui-toolbar-card dxui-toolbar-card--spread">
							<div>
								<p className="dxui-section-kicker">{ __( 'Lead capture', 'dx-ui' ) }</p>
								<h3 className="dxui-toolbar-title">{ __( 'Submission inbox', 'dx-ui' ) }</h3>
								<p className="dxui-muted">{ __( 'Entries are stored in WordPress and can be exported whenever you need to hand them off.', 'dx-ui' ) }</p>
							</div>
							<div className="dxui-actions">
								<Button variant="secondary" onClick={ downloadCsv } disabled={ ! entries.length }>
									{ __( 'Download CSV', 'dx-ui' ) }
								</Button>
							</div>
						</div>
						{ entries.length === 0 ? (
							<div className="dxui-card dxui-empty-state">
								<strong>{ __( 'No submissions yet.', 'dx-ui' ) }</strong>
								<p className="dxui-muted">{ __( 'Once a generated form receives entries, they will appear here with export support.', 'dx-ui' ) }</p>
							</div>
						) : (
							<div className="dxui-table-wrap">
								<table className="dxui-data">
									<thead>
										<tr>
											<th>{ __( 'Date', 'dx-ui' ) }</th>
											<th>{ __( 'Email', 'dx-ui' ) }</th>
											<th>{ __( 'Message', 'dx-ui' ) }</th>
											<th>{ __( 'Page', 'dx-ui' ) }</th>
										</tr>
									</thead>
									<tbody>
										{ entries.map( ( entry ) => (
											<tr key={ entry.id }>
												<td>{ entry.date }</td>
												<td>{ entry.email || '—' }</td>
												<td className="dxui-cell-wrap">{ entry.message || '—' }</td>
												<td>{ entry.page_id || '—' }</td>
											</tr>
										) ) }
									</tbody>
								</table>
							</div>
						) }
					</>
				) }
			</div>
		</div>
	);
}

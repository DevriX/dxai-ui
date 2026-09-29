import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, Spinner } from '@wordpress/components';

export default function FormsScreen() {
	const [ entries, setEntries ] = useState( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		apiFetch( { path: '/dxai-ui/v1/forms/entries' } )
			.then( ( res ) => setEntries( res.entries || [] ) )
			.catch( ( err ) => setError( err.message || __( 'Unable to load form entries.', 'dxai-ui' ) ) );
	}, [] );

	function downloadCsv() {
		apiFetch( { path: '/dxai-ui/v1/forms/export' } )
			.then( ( res ) => {
				const blob = new window.Blob( [ res.csv || '' ], { type: 'text/csv;charset=utf-8' } );
				const url = window.URL.createObjectURL( blob );
				const link = document.createElement( 'a' );
				link.href = url;
				link.download = res.filename || 'dxai-form-entries.csv';
				link.click();
				window.URL.revokeObjectURL( url );
			} )
			.catch( ( err ) => setError( err.message || __( 'Export failed.', 'dxai-ui' ) ) );
	}

	const total = entries ? entries.length : 0;
	const withEmail = entries ? entries.filter( ( entry ) => entry.email ).length : 0;
	const stats = [
		{ label: __( 'Submissions', 'dxai-ui' ), value: total },
		{ label: __( 'With email', 'dxai-ui' ), value: withEmail },
		{ label: __( 'Export', 'dxai-ui' ), value: __( 'CSV', 'dxai-ui' ) },
	];

	return (
		<div className="dxai-screen">
			<ul className="dxai-stats">
				{ stats.map( ( item ) => (
					<li key={ item.label }>
						<strong>{ item.value }</strong>
						{ item.label }
					</li>
				) ) }
			</ul>
			<div className="dxai-panel dxai-panel--stack">
				{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
				{ entries === null && ! error && <Spinner /> }
				{ entries && (
					<>
						<div className="dxai-toolbar-card dxai-toolbar-card--spread">
							<div>
								<p className="dxai-section-kicker">{ __( 'Lead capture', 'dxai-ui' ) }</p>
								<h3 className="dxai-toolbar-title">{ __( 'Submission inbox', 'dxai-ui' ) }</h3>
								<p className="dxai-muted">{ __( 'Entries are stored in WordPress and can be exported whenever you need to hand them off.', 'dxai-ui' ) }</p>
							</div>
							<div className="dxai-actions">
								<Button variant="secondary" onClick={ downloadCsv } disabled={ ! entries.length }>
									{ __( 'Download CSV', 'dxai-ui' ) }
								</Button>
							</div>
						</div>
						{ entries.length === 0 ? (
							<div className="dxai-card dxai-empty-state">
								<strong>{ __( 'No submissions yet.', 'dxai-ui' ) }</strong>
								<p className="dxai-muted">{ __( 'Once a generated form receives entries, they will appear here with export support.', 'dxai-ui' ) }</p>
							</div>
						) : (
							<div className="dxai-table-wrap">
								<table className="dxai-data">
									<thead>
										<tr>
											<th>{ __( 'Date', 'dxai-ui' ) }</th>
											<th>{ __( 'Email', 'dxai-ui' ) }</th>
											<th>{ __( 'Message', 'dxai-ui' ) }</th>
											<th>{ __( 'Page', 'dxai-ui' ) }</th>
										</tr>
									</thead>
									<tbody>
										{ entries.map( ( entry ) => (
											<tr key={ entry.id }>
												<td>{ entry.date }</td>
												<td>{ entry.email || '—' }</td>
												<td className="dxai-cell-wrap">{ entry.message || '—' }</td>
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

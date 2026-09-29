import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/*
 * "Export with styles" for a converted page: a link to the nonce-protected
 * download (Transfer_Admin::export_url(), the same one the Pages list's row
 * action uses), which answers with the page's .dxai.zip package.
 *
 * loadTransferInfo() reads the converted pages with their export links, and
 * what the import panel needs to know about this site (the header and footer
 * choice an import makes here by itself, the theme, the upload limit). A user
 * who may not import or export gets the server's reason instead.
 */
export function loadTransferInfo() {
	return apiFetch( { path: '/dxai-ui/v1/transfer/pages' } ).then( ( res ) => {
		const links = {};
		( Array.isArray( res && res.pages ) ? res.pages : [] ).forEach( ( page ) => {
			if ( page && page.id && page.export_url ) {
				links[ page.id ] = page.export_url;
			}
		} );
		return { ...( res || {} ), links };
	} );
}

export default function TransferExport( { url, title } ) {
	if ( ! url ) {
		return null;
	}
	return (
		<a
			className="components-button is-secondary"
			href={ url }
			aria-label={ sprintf(
				/* translators: %s: page title */
				__( 'Export “%s” with its styles as a DXAI-UI package', 'dxai-ui' ),
				title || ''
			) }
		>
			{ __( 'Export', 'dxai-ui' ) }
		</a>
	);
}

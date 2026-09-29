/* global dxaiUI */
import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';
import App from './App';

const config = typeof dxaiUI !== 'undefined' ? dxaiUI : { restUrl: '', nonce: '', screen: 'convert' };

if ( config.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
}

const root = document.getElementById( 'dxai-ui-app' );
if ( root ) {
	createRoot( root ).render(
		<App screen={ config.screen || 'convert' } version={ config.version || '' } />
	);
}

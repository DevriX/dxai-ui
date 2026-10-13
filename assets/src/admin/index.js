import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';
import App from './App';
import { config } from './router';

if ( config.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
}

const root = document.getElementById( 'dxai-ui-app' );
if ( root ) {
	createRoot( root ).render( <App screen={ config.screen || 'designs' } /> );
}

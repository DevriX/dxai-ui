import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/*
 * A multipart POST to this plugin's REST routes that reports upload progress.
 *
 * apiFetch is built on fetch(), which cannot say how much of a request body
 * has been sent — and the design archive is sent twice (read, then compile),
 * up to tens of megabytes each time on a shared host's uplink. Without this
 * the screen could only say "working" while the browser was still uploading.
 *
 * It answers exactly like apiFetch: the parsed JSON on success, and on
 * failure an object with `code`, `message` and `data.status`, so callers
 * handle both the same way. Where XMLHttpRequest or the REST root is missing
 * it simply is apiFetch.
 */

function nonce() {
	const middleware = apiFetch.nonceMiddleware;
	if ( middleware && middleware.nonce ) {
		return middleware.nonce;
	}
	if ( window.wpApiSettings && window.wpApiSettings.nonce ) {
		return window.wpApiSettings.nonce;
	}
	return ( window.dxaiUI && window.dxaiUI.nonce ) || '';
}

/*
 * A fresh REST nonce, the way apiFetch gets one: its nonce middleware answers
 * a stale nonce (rest_cookie_invalid_nonce — a tab left open past the nonce's
 * 12–24 h life, or idle without the heartbeat) by asking core's rest-nonce
 * endpoint and retrying once. This request does not go through apiFetch, so it
 * does the same itself, and hands the new nonce to apiFetch's middleware so
 * the rest of the screen's requests use it too.
 */
function refreshNonce() {
	const endpoint = apiFetch.nonceEndpoint;
	if ( ! endpoint || typeof window.fetch !== 'function' ) {
		return Promise.reject( new Error( 'no nonce endpoint' ) );
	}
	return window.fetch( endpoint, { credentials: 'same-origin' } )
		.then( ( res ) => {
			if ( ! res.ok ) {
				throw new Error( 'HTTP ' + res.status );
			}
			return res.text();
		} )
		.then( ( text ) => {
			const fresh = String( text || '' ).trim();
			// The endpoint answers the nonce alone; anything else is a login page or an error.
			if ( ! /^[a-f0-9]{6,20}$/i.test( fresh ) ) {
				throw new Error( 'not a nonce' );
			}
			if ( apiFetch.nonceMiddleware ) {
				apiFetch.nonceMiddleware.nonce = fresh;
			}
			if ( window.dxaiUI ) {
				window.dxaiUI.nonce = fresh;
			}
			return fresh;
		} );
}

/**
 * @param {string}   route      Route under dxai-ui/v1, e.g. "process-source".
 * @param {FormData} body
 * @param {Function} onProgress Called with { loaded, total } while the body
 *                              uploads and { sent: true } once it has all gone.
 * @return {Promise<Object>} The route's JSON answer.
 */
export function postForm( route, body, onProgress ) {
	return send( route, body, onProgress ).catch( ( err ) => {
		if ( ! err || err.code !== 'rest_cookie_invalid_nonce' ) {
			throw err;
		}
		// Once: a nonce that is refused again is a session that has ended.
		return refreshNonce().then( () => send( route, body, onProgress ), () => {
			throw err;
		} );
	} );
}

function send( route, body, onProgress ) {
	const root = window.dxaiUI && window.dxaiUI.restUrl;
	if ( ! root || typeof window.XMLHttpRequest !== 'function' ) {
		return apiFetch( { path: '/dxai-ui/v1/' + route, method: 'POST', body } );
	}

	return new Promise( ( resolve, reject ) => {
		const xhr = new window.XMLHttpRequest();
		let url = root + route;
		url += ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + '_locale=user';
		xhr.open( 'POST', url, true );
		xhr.setRequestHeader( 'Accept', 'application/json, */*;q=0.1' );
		const token = nonce();
		if ( token ) {
			xhr.setRequestHeader( 'X-WP-Nonce', token );
		}
		if ( xhr.upload && typeof onProgress === 'function' ) {
			xhr.upload.onprogress = ( event ) => {
				onProgress( { loaded: event.loaded, total: event.lengthComputable ? event.total : 0 } );
			};
			xhr.upload.onload = () => onProgress( { sent: true } );
		}
		xhr.onerror = () => reject( {
			code: 'fetch_error',
			message: __( 'The connection to the server was lost before it answered.', 'dxai-ui' ),
			data: { status: 0 },
		} );
		xhr.onabort = () => reject( {
			code: 'fetch_error',
			message: __( 'The request was cancelled.', 'dxai-ui' ),
			data: { status: 0 },
		} );
		xhr.onload = () => {
			let json = null;
			try {
				json = JSON.parse( xhr.responseText );
			} catch ( e ) {
				json = null;
			}
			if ( xhr.status >= 200 && xhr.status < 300 ) {
				if ( json === null ) {
					reject( {
						code: 'invalid_json',
						message: __( 'The response is not a valid JSON response.', 'dxai-ui' ),
						data: { status: xhr.status },
					} );
					return;
				}
				resolve( json );
				return;
			}
			if ( json && json.code ) {
				reject( { ...json, data: { ...( json.data || {} ), status: ( json.data && json.data.status ) || xhr.status } } );
				return;
			}
			reject( {
				code: json === null && xhr.responseText ? 'invalid_json' : 'http_' + xhr.status,
				message: sprintf(
					/* translators: %d: HTTP status code */
					__( 'The server answered HTTP %d.', 'dxai-ui' ),
					xhr.status
				),
				data: { status: xhr.status },
			} );
		};
		xhr.send( body );
	} );
}

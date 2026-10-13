/* global dxaiUI */
import { useEffect, useState } from '@wordpress/element';

/*
 * The app's addresses. Each wp-admin screen is one page (admin.php?page=…); inside the Designs screen the hash says which
 * design and which of its tabs is open (#/design/56/look), so a link to a design's page can be shared, the browser's back
 * button works, and a reload lands where the person was.
 */

/** What WordPress told the app: the screen, the admin links, the version, the import facts. */
export const config = typeof dxaiUI !== 'undefined' ? dxaiUI : { restUrl: '', nonce: '', screen: 'designs', links: {}, labels: {} };

/** The admin URL of a screen (designs, convert, site, forms, settings, menus, widgets, themes), or '' when the site has none. */
export function link( name ) {
	return ( config.links && config.links[ name ] ) || '';
}

export const TABS = [ 'overview', 'pages', 'look', 'blocks', 'words', 'speed', 'transfer', 'history' ];

/** The route a hash names: the list, or one design and one of its tabs. */
export function parseHash( hash ) {
	const parts = String( hash || '' ).replace( /^#\/?/, '' ).split( '/' ).filter( Boolean );
	if ( parts[ 0 ] === 'design' && /^\d+$/.test( parts[ 1 ] || '' ) ) {
		const tab = TABS.includes( parts[ 2 ] ) ? parts[ 2 ] : 'overview';
		return { name: 'design', id: Number( parts[ 1 ] ), tab };
	}
	return { name: 'list', id: 0, tab: '' };
}

/** The hash of a design's page, or of one of its tabs. */
export function designHash( id, tab = 'overview' ) {
	return '#/design/' + id + ( tab && tab !== 'overview' ? '/' + tab : '' );
}

/** A full admin URL to a design's page, for links from the other screens. */
export function designUrl( id, tab = 'overview' ) {
	return link( 'designs' ) + designHash( id, tab );
}

/** The route the page is on now; changes with the hash. */
export function useRoute() {
	const [ route, setRoute ] = useState( () => parseHash( window.location.hash ) );
	useEffect( () => {
		const on = () => setRoute( parseHash( window.location.hash ) );
		window.addEventListener( 'hashchange', on );
		return () => window.removeEventListener( 'hashchange', on );
	}, [] );
	return route;
}

/** Go to a hash (the list: '#/'). */
export function go( hash ) {
	if ( window.location.hash !== hash ) {
		window.location.hash = hash;
	}
}

/** A date as the person reads it (their locale, their time zone), from the ISO 8601 the server gives; '' for none. */
export function dateOf( iso, withTime = false ) {
	if ( ! iso ) {
		return '';
	}
	const d = new Date( iso );
	if ( Number.isNaN( d.getTime() ) ) {
		return String( iso );
	}
	const opts = { day: 'numeric', month: 'short', year: 'numeric' };
	if ( withTime ) {
		opts.hour = '2-digit';
		opts.minute = '2-digit';
	}
	return d.toLocaleDateString( undefined, opts );
}

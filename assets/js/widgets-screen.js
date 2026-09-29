/**
 * Appearance > Widgets: the footer's areas in the footer's design (Widgets_Screen).
 *
 * The design's stylesheet is scoped to `.dxai-ui.dxai-ui--{id}`, and on the
 * front end the footer sits inside the element carrying those classes. In the
 * Widgets screen nothing does, so the scope classes are put on each footer
 * area's block container (`[data-widget-area-id]`, which holds the area's
 * blocks and nothing else), together with `dxai-ui-widget-area`, the hook for
 * the frame's paint (background, text colour, type) that Widgets_Screen
 * writes per area. React owns that element's class list and rewrites it when
 * the area is dragged over, so the classes are put back whenever they go.
 */
( function () {
	const cfg = window.dxaiUIWidgetsScreen || {};
	const areas = Array.isArray( cfg.areas ) ? cfg.areas : [];
	const names = String( cfg.scopeClass || '' ).split( /\s+/ ).filter( Boolean ).concat( [ 'dxai-ui-widget-area' ] );
	if ( ! areas.length ) {
		return;
	}

	function apply() {
		areas.forEach( function ( id ) {
			const nodes = document.querySelectorAll( '[data-widget-area-id="' + String( id ).replace( /["\\]/g, '' ) + '"]' );
			nodes.forEach( function ( node ) {
				names.forEach( function ( name ) {
					if ( ! node.classList.contains( name ) ) {
						node.classList.add( name );
					}
				} );
			} );
		} );
	}

	let queued = false;
	function schedule() {
		if ( queued ) {
			return;
		}
		queued = true;
		window.requestAnimationFrame( function () {
			queued = false;
			apply();
		} );
	}

	function start() {
		apply();
		const root = document.getElementById( 'widgets-editor' ) || document.body;
		// Areas mount as their panels open, and class lists are rewritten on
		// drag: watch both. apply() only adds a class that is missing, so its
		// own writes settle after one pass.
		new window.MutationObserver( schedule ).observe( root, {
			childList: true,
			subtree: true,
			attributes: true,
			attributeFilter: [ 'class' ],
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );

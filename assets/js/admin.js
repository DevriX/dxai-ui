/* global dxaiUI */
( function ( wp ) {
	const root = document.getElementById( 'dxai-ui-app' );
	if ( ! root || ! wp.element ) {
		return;
	}
	const el = wp.element.createElement;
	wp.element.createRoot( root ).render(
		el(
			'div',
			{ className: 'dxai-panel' },
			el( 'p', null, 'DX Convert needs a built admin bundle. Run npm run build in the plugin directory.' )
		)
	);
}( window.wp, window.dxaiUI ) );

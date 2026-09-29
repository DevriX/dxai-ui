( function () {
	function init( root ) {
		let track = root.querySelector( '.dxai-slider-track' );
		if ( ! track ) {
			track = document.createElement( 'div' );
			track.className = 'dxai-slider-track';
			while ( root.firstChild ) {
				track.appendChild( root.firstChild );
			}
			root.appendChild( track );
		}
		const slides = Array.from( track.children ).filter( function ( node ) {
			return node.nodeType === 1;
		} );
		if ( ! slides.length ) {
			return;
		}
		if ( ! root.querySelector( '.dxai-slider-prev' ) ) {
			const prev = document.createElement( 'button' );
			prev.type = 'button';
			prev.className = 'dxai-slider-prev';
			prev.setAttribute( 'aria-label', 'Previous slide' );
			prev.textContent = '‹';
			const next = document.createElement( 'button' );
			next.type = 'button';
			next.className = 'dxai-slider-next';
			next.setAttribute( 'aria-label', 'Next slide' );
			next.textContent = '›';
			root.appendChild( prev );
			root.appendChild( next );
		}
		let index = 0;
		function paint() {
			track.style.transform = 'translateX(' + ( -100 * index ) + '%)';
		}
		root.querySelector( '.dxai-slider-prev' ).addEventListener( 'click', function () {
			index = ( index - 1 + slides.length ) % slides.length;
			paint();
		} );
		root.querySelector( '.dxai-slider-next' ).addEventListener( 'click', function () {
			index = ( index + 1 ) % slides.length;
			paint();
		} );
		paint();
	}

	document.querySelectorAll( '[data-dxai-slider], .dxai-slider' ).forEach( init );
}() );

( function () {
	function bind( form ) {
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			const status = form.querySelector( '.dxai-form-status' );
			const data = new window.FormData( form );
			const payload = {};
			data.forEach( function ( value, key ) {
				payload[ key ] = value;
			} );
			const url = ( window.dxaiUIForm && window.dxaiUIForm.restUrl ) || form.getAttribute( 'action' );
			window.fetch( url, {
				method: 'POST',
				// A public endpoint: sending the visitor's cookies without the
				// REST nonce only made WordPress treat them as logged out.
				credentials: 'omit',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( payload ),
			} ).then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'Submit failed' );
				}
				if ( status ) {
					status.hidden = false;
					status.textContent = form.getAttribute( 'data-success' ) || 'Thank you.';
				}
				form.reset();
			} ).catch( function () {
				if ( status ) {
					status.hidden = false;
					status.textContent = 'Unable to send. Please try again.';
				}
			} );
		} );
	}

	document.querySelectorAll( 'form[data-dxai-form]' ).forEach( bind );
}() );

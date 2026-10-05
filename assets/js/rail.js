/*
 * Previous / next controls of a rail of cards that scrolls sideways (a reviews or testimonials slider).
 *
 * A design draws such a slider with a row that scrolls by itself (`overflow-x: auto`, usually with scroll snapping) and two buttons
 * that move it by a card; the design's own script did the moving, and the plugin's page script has nothing for it (it carries a
 * design's state, not what its code does to the DOM). So a pair of buttons turned up on the page and did nothing.
 *
 * This is that behaviour, for any page that has such buttons, found by what the buttons are and not by name of a design:
 *   - a control is a button (not a link to another page, not inside a form) whose label says previous or next
 *     ("Next reviews", "Previous testimonials", an arrow);
 *   - its rail is the nearest row around it that scrolls sideways — one marked `data-track` first;
 *   - a press moves the rail to the card before or after the one at its edge, smoothly, as far as it can go;
 *   - a button that has the design's `disabled:` look is disabled at the ends of the rail, as the design's code did.
 * A button the design's own script drives (any `data-dxai-*` attribute) is left alone.
 */
( function () {
	'use strict';

	var PREV = /^(?:prev(?:ious)?\b|←|‹|«)/i;
	var NEXT = /^(?:next\b|→|›|»)/i;
	// "Next step", "Next page": a form, a pager — not a rail.
	var NOT = /\b(?:step|page|pages|section|question|post|article|result|month|year|week|day)\b/i;
	var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	function label( el ) {
		return ( el.getAttribute( 'aria-label' ) || el.textContent || '' ).replace( /\s+/g, ' ' ).trim();
	}

	/** -1 for a previous control, 1 for a next one, 0 for anything else. */
	function direction( el ) {
		if ( el.tagName === 'A' && ! /^#?$/.test( el.getAttribute( 'href' ) || '' ) ) {
			return 0;
		}
		// `el.type` says "submit" for every button that does not name a type, and a design's buttons seldom do: only one that says so counts.
		if ( el.getAttribute( 'type' ) === 'submit' || ( el.closest && el.closest( 'form, nav' ) ) ) {
			return 0;
		}
		for ( var i = 0; i < el.attributes.length; i++ ) {
			if ( el.attributes[ i ].name.indexOf( 'data-dxai-' ) === 0 ) {
				return 0;
			}
		}
		var text = label( el );
		if ( ! text || NOT.test( text ) ) {
			return 0;
		}
		return PREV.test( text ) ? -1 : NEXT.test( text ) ? 1 : 0;
	}

	function scrolls( el ) {
		return el.scrollWidth > el.clientWidth + 2 && /(?:auto|scroll)/.test( window.getComputedStyle( el ).overflowX );
	}

	/** The nearest row that scrolls sideways around a control. */
	function railFor( control ) {
		var node = control.parentElement;
		for ( var hops = 0; node && node !== document.body && hops < 8; hops++, node = node.parentElement ) {
			var marked = node.querySelector( '[data-track]' );
			if ( marked ) {
				return marked;
			}
			var all = node.querySelectorAll( '*' );
			for ( var i = 0; i < all.length; i++ ) {
				if ( scrolls( all[ i ] ) ) {
					return all[ i ];
				}
			}
		}
		return null;
	}

	/** Where the cards start, from the left of the rail's scrolled content. */
	function starts( rail ) {
		var base = rail.getBoundingClientRect().left - rail.scrollLeft;
		return Array.prototype.filter.call( rail.children, function ( kid ) {
			return kid.offsetWidth > 0;
		} ).map( function ( kid ) {
			return kid.getBoundingClientRect().left - base;
		} );
	}

	function go( rail, dir ) {
		var at = rail.scrollLeft;
		var max = rail.scrollWidth - rail.clientWidth;
		var edges = starts( rail );
		var to;
		if ( dir > 0 ) {
			to = edges.filter( function ( x ) {
				return x > at + 2;
			} )[ 0 ];
			to = to === undefined ? max : to;
		} else {
			var before = edges.filter( function ( x ) {
				return x < at - 2;
			} );
			to = before.length ? before[ before.length - 1 ] : 0;
		}
		rail.scrollTo( { left: Math.max( 0, Math.min( max, to ) ), behavior: reduce ? 'auto' : 'smooth' } );
	}

	document.addEventListener( 'click', function ( event ) {
		var control = event.target && event.target.closest ? event.target.closest( 'button, [role="button"], a' ) : null;
		if ( ! control || control.disabled ) {
			return;
		}
		var dir = direction( control );
		var rail = dir ? railFor( control ) : null;
		if ( rail ) {
			event.preventDefault();
			go( rail, dir );
		}
	} );

	// The design's own look for an end of the rail: a button with `disabled:` classes is disabled there.
	function sync( control, rail, dir ) {
		var at = rail.scrollLeft;
		var max = rail.scrollWidth - rail.clientWidth;
		control.disabled = max <= 2 || ( dir < 0 ? at <= 2 : at >= max - 2 );
	}

	function arm() {
		var controls = document.querySelectorAll( 'button[class*="disabled:"]' );
		for ( var i = 0; i < controls.length; i++ ) {
			( function ( control ) {
				var dir = direction( control );
				var rail = dir ? railFor( control ) : null;
				if ( ! rail || control.getAttribute( 'data-rail-armed' ) ) {
					return;
				}
				control.setAttribute( 'data-rail-armed', '1' );
				sync( control, rail, dir );
				var queued = false;
				rail.addEventListener( 'scroll', function () {
					if ( ! queued ) {
						queued = true;
						window.requestAnimationFrame( function () {
							queued = false;
							sync( control, rail, dir );
						} );
					}
				}, { passive: true } );
				window.addEventListener( 'resize', function () {
					sync( control, rail, dir );
				} );
			}( controls[ i ] ) );
		}
	}

	if ( document.readyState === 'complete' ) {
		arm();
	} else {
		window.addEventListener( 'load', arm );
	}
}() );

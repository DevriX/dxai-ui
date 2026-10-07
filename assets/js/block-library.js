/**
 * The questions of the team's FAQ sections (Pages\Block_Library) on a page that does not have the theme's script: a click on a question
 * opens its answer and closes the others of the section. The same contract the theme's own script has — a group `faq-question` holding
 * its answer (`faq-answer`) and an arrow (`faq-arrow`); the answer that carries `faq-answer--default-open` is open to begin with — and
 * only for the library's sections (`.dxai-lib`), so it can never act on a question of a design.
 */
( function () {
	'use strict';

	var OPEN = 'is-open';
	var SCOPE = '.dxai-lib';
	var QUESTION = SCOPE + ' .faq-question';

	function reduced() {
		return !! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
	}

	function answerOf( question ) {
		return question.querySelector( '.faq-answer' );
	}

	/** The state on the card and its arrows, for the styles that draw them. */
	function sync( answer ) {
		var question = answer.closest( '.faq-question' );
		if ( ! question ) {
			return;
		}
		var open = answer.classList.contains( OPEN );
		var arrows = question.querySelectorAll( '.faq-arrow' );
		var i;
		question.classList.toggle( 'faq-question--expanded', open );
		question.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		for ( i = 0; i < arrows.length; i++ ) {
			arrows[ i ].classList.toggle( OPEN, open );
		}
	}

	function open( answer ) {
		answer.classList.add( OPEN );
		if ( reduced() ) {
			answer.style.removeProperty( 'max-height' );
			sync( answer );
			return;
		}
		answer.style.maxHeight = '0px';
		void answer.offsetHeight;
		var height = Math.max( answer.scrollHeight, 0 ) + 2;
		sync( answer );
		window.requestAnimationFrame( function () {
			if ( ! answer.classList.contains( OPEN ) ) {
				return;
			}
			answer.addEventListener(
				'transitionend',
				function ( e ) {
					if ( e.target === answer && e.propertyName === 'max-height' && answer.classList.contains( OPEN ) ) {
						answer.style.removeProperty( 'max-height' );
					}
				},
				{ once: true }
			);
			answer.style.maxHeight = height + 'px';
		} );
	}

	function close( answer, question ) {
		if ( question && answer.contains( document.activeElement ) && document.activeElement !== question ) {
			question.focus();
		}
		if ( reduced() ) {
			answer.classList.remove( OPEN );
			answer.style.maxHeight = '0px';
			sync( answer );
			return;
		}
		answer.style.maxHeight = answer.scrollHeight + 'px';
		void answer.offsetHeight;
		window.requestAnimationFrame( function () {
			answer.classList.remove( OPEN );
			answer.style.maxHeight = '0px';
			sync( answer );
		} );
	}

	/** The other open answers of the same section. */
	function closeOthers( keep ) {
		var section = keep.closest( SCOPE );
		var list = ( section || document ).querySelectorAll( SCOPE + ' .faq-answer.' + OPEN );
		var i;
		for ( i = 0; i < list.length; i++ ) {
			if ( list[ i ] !== keep ) {
				close( list[ i ], list[ i ].closest( '.faq-question' ) );
			}
		}
	}

	function toggle( question ) {
		var answer = answerOf( question );
		if ( ! answer ) {
			return;
		}
		if ( answer.classList.contains( OPEN ) ) {
			close( answer, question );
		} else {
			closeOthers( answer );
			open( answer );
		}
	}

	function init() {
		var list = document.querySelectorAll( QUESTION );
		var i;
		for ( i = 0; i < list.length; i++ ) {
			var answer = answerOf( list[ i ] );
			if ( ! answer ) {
				continue;
			}
			list[ i ].setAttribute( 'role', 'button' );
			list[ i ].setAttribute( 'tabindex', '0' );
			if ( answer.classList.contains( 'faq-answer--default-open' ) ) {
				answer.classList.add( OPEN );
			} else {
				answer.classList.remove( OPEN );
				answer.style.maxHeight = '0px';
			}
			sync( answer );
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var question = e.target && e.target.closest ? e.target.closest( QUESTION ) : null;
		if ( ! question || e.defaultPrevented ) {
			return;
		}
		// A click in the open answer (a link in it, a selection) does not close it; only the question does.
		var inside = e.target.closest( '.faq-answer' );
		if ( inside && question.contains( inside ) ) {
			return;
		}
		var link = e.target.closest( 'a, button, input, select, textarea, label' );
		if ( link && link !== question && question.contains( link ) ) {
			return;
		}
		toggle( question );
	} );

	document.addEventListener( 'keydown', function ( e ) {
		var t = e.target;
		if ( ( e.key === 'Enter' || e.key === ' ' ) && t && t.matches && t.matches( QUESTION ) ) {
			e.preventDefault();
			toggle( t );
		}
	} );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

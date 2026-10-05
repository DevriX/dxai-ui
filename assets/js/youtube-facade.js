/*
 * The YouTube facade (the team's example, Compiler\Video_Facade): `<div class="youtube-facade" data-video-id data-video-title>` is a
 * thumbnail and a play button until a visitor presses it, and only then the player (youtube-nocookie.com) is loaded. Nothing of YouTube is
 * asked for before that, apart from the thumbnail.
 *
 * This is the plugin's own copy of what the American Restoration theme's script does, for the pages where that script is not there: a
 * converted page (the theme's scripts are detached on it) and any other theme. It works together with the theme's script when both are
 * loaded: a facade that already has its play button is not built again, and whichever is pressed first swaps the player in (the other finds
 * the facade gone).
 */
( function () {
	'use strict';

	function preconnect( href ) {
		if ( document.querySelector( 'link[rel="preconnect"][href="' + href + '"]' ) ) {
			return;
		}
		var link = document.createElement( 'link' );
		link.rel = 'preconnect';
		link.href = href;
		link.crossOrigin = 'anonymous';
		document.head.appendChild( link );
	}

	function build( facade, id ) {
		// A thumbnail or a play button the page already has (its own, or the theme's script's) stays as it is.
		if ( facade.querySelector( '.youtube-facade__thumb, .youtube-facade__play' ) ) {
			return;
		}
		var title = facade.getAttribute( 'data-video-title' ) || 'Play video';

		var thumb = document.createElement( 'img' );
		thumb.className = 'youtube-facade__thumb';
		thumb.src = facade.getAttribute( 'data-video-thumbnail' ) || 'https://i.ytimg.com/vi/' + id + '/hqdefault.jpg';
		thumb.alt = '';
		thumb.loading = 'lazy';
		thumb.decoding = 'async';
		facade.appendChild( thumb );

		var play = document.createElement( 'span' );
		play.className = 'youtube-facade__play';
		play.setAttribute( 'aria-hidden', 'true' );
		facade.appendChild( play );

		if ( ! facade.hasAttribute( 'role' ) ) {
			facade.setAttribute( 'role', 'button' );
		}
		if ( ! facade.hasAttribute( 'tabindex' ) ) {
			facade.setAttribute( 'tabindex', '0' );
		}
		if ( ! facade.hasAttribute( 'aria-label' ) ) {
			facade.setAttribute( 'aria-label', title );
		}
	}

	function load( facade ) {
		var id = facade.getAttribute( 'data-video-id' );
		if ( ! id || ! facade.parentNode ) {
			return;
		}
		var frame = document.createElement( 'iframe' );
		// youtube-nocookie.com holds back third-party cookies and trackers until playback is asked for.
		frame.src = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent( id ) + '?autoplay=1&rel=0';
		frame.width = '100%';
		frame.height = '100%';
		frame.title = facade.getAttribute( 'data-video-title' ) || 'YouTube video player';
		frame.setAttribute( 'frameborder', '0' );
		frame.referrerPolicy = 'strict-origin-when-cross-origin';
		frame.allow = 'autoplay; encrypted-media; picture-in-picture; web-share';
		frame.allowFullscreen = true;
		// The facade's own box (its height, its shape) is the player's.
		frame.style.cssText = 'display:block;width:100%;height:100%;border:0;aspect-ratio:' + ( facade.style.aspectRatio && facade.style.aspectRatio !== 'unset' ? facade.style.aspectRatio : 'auto' ) + ';min-height:' + ( facade.style.minHeight || 'auto' );
		facade.parentNode.replaceChild( frame, facade );
		frame.focus();
	}

	function bind( facade ) {
		var id = facade.getAttribute( 'data-video-id' );
		// One that is bound already (by this script, or by the theme's: it has its play button) is left to whoever did.
		if ( ! id || facade.getAttribute( 'data-dxai-facade' ) || facade.querySelector( '.youtube-facade__play' ) ) {
			return;
		}
		facade.setAttribute( 'data-dxai-facade', '1' );
		build( facade, id );

		function activate() {
			facade.removeEventListener( 'click', activate );
			facade.removeEventListener( 'keydown', onKey );
			load( facade );
		}
		function onKey( event ) {
			if ( event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar' ) {
				event.preventDefault();
				activate();
			}
		}
		facade.addEventListener( 'click', activate );
		facade.addEventListener( 'keydown', onKey );
	}

	function init() {
		var facades = document.querySelectorAll( '.youtube-facade' );
		if ( ! facades.length ) {
			return;
		}
		// The connections are warmed now, so the player starts faster when it is asked for, without a live player's cost on every visit.
		preconnect( 'https://www.youtube-nocookie.com' );
		preconnect( 'https://i.ytimg.com' );
		for ( var i = 0; i < facades.length; i++ ) {
			bind( facades[ i ] );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

/**
 * The editor side of dxai-ui/site-footer (Site_Footer_Block).
 *
 * The block stores nothing: the footer is the design's frame around the
 * widgets of Appearance > Widgets, rendered on the server. So the canvas shows
 * exactly that — the server's own render, through ServerSideRender — and the
 * block's toolbar and sidebar send a person to where the footer's content is
 * edited. The preview is Disabled (inert), as core's server-rendered blocks
 * and the site header's are: its links take neither a click nor keyboard
 * focus, so nothing in it navigates away from the editor, and a click
 * selects the block. editor-design.css gives the Disabled element and
 * ServerSideRender's own <div> no box, so the footer is laid out as the
 * block's child, as it is on the front end.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const RawHTML = wp.element.RawHTML;
	const { registerBlockType } = wp.blocks;
	const { useBlockProps, BlockControls, InspectorControls } = wp.blockEditor;
	const { ToolbarGroup, ToolbarButton, PanelBody, Button, Placeholder, Spinner, Disabled } = wp.components;
	const { __ } = wp.i18n;
	const ServerSideRender = wp.serverSideRender;

	const cfg = window.dxaiUISiteFooter || {};

	/*
	 * Not offered where the footer's own areas are edited: a footer placed in
	 * one of its widget areas would render inside itself (the server renders
	 * nothing there, but the inserter should not suggest it).
	 */
	const inWidgetsEditor = window.pagenow === 'widgets' || window.pagenow === 'customize';

	/*
	 * The footer's first render, taken from the editor's preload while this
	 * script runs, and handed to the preview's own first request.
	 *
	 * Site_Footer_Block::preload() puts this post's render in the editor's
	 * preloaded API data, and nothing ever read it: WordPress 7.1's
	 * edit-post initializeEditor() calls clearPreloadedData() once the post
	 * and its settings have resolved — before a single block mounts — so
	 * ServerSideRender's request, made when the canvas renders the block,
	 * found the entry gone and went to the server. Measured on a 262-block
	 * page: the footer a 72px spinner for 6.3 s after the canvas mounted,
	 * with the header likewise empty for 3.2 s. (A probe that wraps
	 * createPreloadingMiddleware to count hits drops the CLEAR hook with
	 * it, which is why the preload read as working.)
	 *
	 * This script runs before initializeEditor (that waits for domReady), so
	 * the entry is still there. It is read with an already-aborted signal:
	 * the preloading middleware answers without looking at the signal, and
	 * when there is no entry — a post without the block, where the server
	 * preloads nothing — the fetch handler rejects at once instead of asking
	 * the server for a footer nobody shows.
	 *
	 * The answer is shown as it is, in the class-less <div> ServerSideRender
	 * puts a finished render in (Edit()), so the footer is there from the
	 * block's first frame: handed to ServerSideRender instead, it still drew
	 * an empty <div> for its first frame (measured: 0px, then 520px 282 ms
	 * later). The preload is the same request ServerSideRender would make,
	 * answered in the same page load, and the block has no attributes to
	 * change it, so nothing is lost. Should the block mount before the
	 * preload is read, ServerSideRender's own first request for the same path
	 * is answered from it (served once; a later request goes to the server as
	 * before) and the loading placeholder shows it. `cfg.initial`, if the
	 * server ever localizes the render itself, is used the same way.
	 */
	const firstPath = ( function () {
		const where = window.location || {};
		const post = /[?&]post=(\d+)(?:&|$)/.exec( where.search || '' );
		return post && /\/post\.php$/.test( where.pathname || '' ) && ! inWidgetsEditor
			? '/wp/v2/block-renderer/dxai-ui/site-footer?context=edit&post_id=' + post[ 1 ]
			: '';
	}() );
	let first = typeof cfg.initial === 'string' && cfg.initial !== '' ? cfg.initial : '';
	let shareFirst = first !== '';
	const samePath = function ( path ) {
		if ( typeof path !== 'string' || firstPath === '' ) {
			return false;
		}
		const normalize = wp.url && wp.url.normalizePath ? wp.url.normalizePath : function ( p ) {
			return p;
		};
		return normalize( path ) === normalize( firstPath );
	};
	if ( firstPath !== '' && wp.apiFetch && typeof wp.apiFetch.use === 'function' && typeof window.AbortController === 'function' ) {
		if ( first === '' ) {
			const aborted = new window.AbortController();
			aborted.abort();
			wp.apiFetch( { path: firstPath, signal: aborted.signal } ).then(
				function ( res ) {
					if ( res && typeof res.rendered === 'string' && res.rendered !== '' ) {
						first = res.rendered;
						shareFirst = true;
					}
				},
				function () {}
			);
		}
		wp.apiFetch.use( function ( options, next ) {
			if ( shareFirst && ( options.method || 'GET' ) === 'GET' && options.parse !== false && samePath( options.path ) ) {
				shareFirst = false;
				return Promise.resolve( { rendered: first } );
			}
			return next( options );
		} );
	}

	const icon = el(
		'svg',
		{ xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24', width: 24, height: 24, 'aria-hidden': true, focusable: false },
		el( 'path', { d: 'M18 5.5h-12c-.3 0-.5.2-.5.5v12c0 .3.2.5.5.5h12c.3 0 .5-.2.5-.5V6c0-.3-.2-.5-.5-.5zM6 4h12c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H6c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2zm1 11h10v2H7v-2z' } )
	);

	function editLink( variant ) {
		if ( ! cfg.widgetsUrl || ! cfg.canEdit ) {
			return null;
		}
		return el(
			Button,
			{ variant: variant || 'secondary', href: cfg.widgetsUrl, target: '_blank', rel: 'noopener' },
			__( 'Edit in Widgets', 'dxai-ui' )
		);
	}

	// Inside the Disabled preview, so no button of its own: the toolbar and
	// the sidebar carry "Edit in Widgets".
	function Empty() {
		return el(
			Placeholder,
			{ icon, label: __( 'Site Footer', 'dxai-ui' ) },
			el( 'p', null, cfg.hasFooter
				? __( 'The footer’s widget areas are empty. Add blocks to them in Appearance > Widgets.', 'dxai-ui' )
				: __( 'No footer has been imported yet. Import a design with a footer, and its footer appears here.', 'dxai-ui' ) )
		);
	}

	/*
	 * While the preview reloads, the footer it already shows stays exactly as
	 * it is. ServerSideRender asks the server again on every attribute change,
	 * and it hands its loading placeholder the previous render as `children`:
	 * the same class-less RawHTML <div> the finished render uses. Returned
	 * as-is, the canvas keeps the same elements and the same box (the
	 * editor-design.css rule gives that <div> none), where a spinner in its
	 * place collapsed the 520px footer to its 66px box on every reload.
	 * The first load shows the render read from the preload (above) in the
	 * same class-less <div>. Only when there is none — no preload, or its
	 * answer not in yet — does it get the spinner, which has a class of its
	 * own, so that rule leaves it a real, padded box.
	 */
	function Loading( props ) {
		if ( props && props.children ) {
			return props.children;
		}
		if ( first !== '' ) {
			return el( RawHTML, null, first );
		}
		return el( 'div', { className: 'dxai-ui-site-footer__loading', style: { padding: '24px', textAlign: 'center' } }, el( Spinner ) );
	}

	function Edit( props ) {
		// Full width in a theme's constrained canvas, as the frame's own
		// `alignfull` makes it on the front end (Site_Footer_Block::frame());
		// measured before: 645px of a 1000px canvas in an ordinary post.
		// Nothing is stored: the class is the editor wrapper's only.
		const blockProps = useBlockProps( { className: 'alignfull' } );
		return el(
			'div',
			blockProps,
			cfg.widgetsUrl && cfg.canEdit && el(
				BlockControls,
				{ group: 'other' },
				el(
					ToolbarGroup,
					null,
					el( ToolbarButton, { href: cfg.widgetsUrl, target: '_blank', rel: 'noopener', text: __( 'Edit in Widgets', 'dxai-ui' ), label: __( 'Edit the footer in Appearance > Widgets', 'dxai-ui' ) } )
				)
			),
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Footer content', 'dxai-ui' ) },
					el( 'p', null, __( 'The footer’s frame comes from the imported design. Its columns and bottom bar are widget areas: change their blocks in Appearance > Widgets, and every page with this block shows the change.', 'dxai-ui' ) ),
					editLink( 'secondary' )
				)
			),
			el(
				Disabled,
				null,
				first !== ''
					? el( RawHTML, null, first )
					: el( ServerSideRender, {
						block: 'dxai-ui/site-footer',
						attributes: props.attributes,
						EmptyResponsePlaceholder: Empty,
						LoadingResponsePlaceholder: Loading,
					} )
			)
		);
	}

	registerBlockType( 'dxai-ui/site-footer', {
		apiVersion: 3,
		title: __( 'Site Footer', 'dxai-ui' ),
		description: __( 'The footer of the imported design. Its content is edited in Appearance > Widgets.', 'dxai-ui' ),
		category: 'theme',
		keywords: [ 'footer', 'widgets' ],
		icon,
		attributes: {},
		supports: {
			html: false,
			className: false,
			customClassName: false,
			reusable: false,
			multiple: false,
			inserter: ! inWidgetsEditor,
		},
		edit: Edit,
		save: function () {
			return null;
		},
	} );
}( window.wp ) );

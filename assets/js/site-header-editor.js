/**
 * Editor side of dxai-ui/site-header (src/Chrome/Site_Header_Block.php).
 *
 * The block has nothing to edit in place: its links are Appearance > Menus
 * items and its logo is the Site Logo. So the canvas shows the REAL header —
 * a server-side render of exactly what the front end prints, inside the same
 * design scope — made inert, and the block's controls lead to where the
 * content lives: "Edit in Menus" in the toolbar, each location's menu in the
 * sidebar, and a Site Logo picker that edits the site setting the way core's
 * Site Logo block does (saved with the page's own Save, as site settings).
 *
 * The wrapper adds nothing that moves the header: the only class it asks for
 * is `alignfull`, which keeps the editor's constrained layout from capping
 * the header at the content width the front end never applies.
 */
( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.serverSideRender ) {
		return;
	}
	const el = wp.element.createElement;
	const Fragment = wp.element.Fragment;
	const RawHTML = wp.element.RawHTML;
	const { registerBlockType } = wp.blocks;
	const { useBlockProps, BlockControls, InspectorControls, MediaUpload, MediaUploadCheck } = wp.blockEditor;
	const { PanelBody, ToolbarGroup, ToolbarButton, Disabled, Button, ExternalLink, Placeholder, Notice, Spinner } = wp.components;
	const { useSelect, useDispatch } = wp.data;
	const { __ } = wp.i18n;
	const ServerSideRender = wp.serverSideRender;
	const cfg = window.dxaiSiteHeader || {};
	const designLogo = cfg.designLogo || { id: 0, url: '' };

	/*
	 * The header's first render, taken from the editor's preload while this
	 * script runs, and shown as the preview from the block's first frame.
	 *
	 * Site_Header_Block::preload() puts this post's render in the editor's
	 * preloaded API data, and nothing ever read it: WordPress 7.1's
	 * edit-post initializeEditor() calls clearPreloadedData() once the post
	 * and its settings have resolved, before a single block mounts, so
	 * ServerSideRender's request found the entry gone and went to the server.
	 * Measured on a 262-block page: the header block 0px tall, then a spinner,
	 * for 5.3 s after the canvas had its blocks, while the footer (which reads
	 * the preload the same way) was already drawn.
	 *
	 * This script runs before initializeEditor (that waits for domReady), so
	 * the entry is still there. It is read with an already-aborted signal: the
	 * preloading middleware answers without looking at the signal, and when
	 * there is no entry (a post without the block, where the server preloads
	 * nothing) the fetch handler rejects at once instead of asking the server.
	 *
	 * Shown as it is, not handed to ServerSideRender: that draws an empty
	 * <div> for its first frame whatever it is given, then its loading
	 * placeholder, then the answer. The preload is the same request
	 * ServerSideRender would make, answered in the same page load, so the
	 * <div> it is shown in is the one the finished preview uses. The preview
	 * turns into a live ServerSideRender once it has to show something the
	 * preload does not hold, an unsaved Site Logo, and stays live from then
	 * on; its first request is still answered from the preload (the
	 * middleware below) if the block mounted before the preload was read.
	 */
	const firstPath = ( function () {
		const where = window.location || {};
		const post = /[?&]post=(\d+)(?:&|$)/.exec( where.search || '' );
		return post && /\/post\.php$/.test( where.pathname || '' )
			? '/wp/v2/block-renderer/dxai-ui/site-header?context=edit&post_id=' + post[ 1 ]
			: '';
	}() );
	let first = '';
	let shareFirst = false;
	let staticFirst = true;
	const samePath = function ( p ) {
		if ( typeof p !== 'string' || firstPath === '' ) {
			return false;
		}
		const normalize = wp.url && wp.url.normalizePath ? wp.url.normalizePath : function ( x ) {
			return x;
		};
		return normalize( p ) === normalize( firstPath );
	};
	if ( firstPath !== '' && wp.apiFetch && typeof wp.apiFetch.use === 'function' && typeof window.AbortController === 'function' ) {
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
		wp.apiFetch.use( function ( options, next ) {
			if ( shareFirst && ( options.method || 'GET' ) === 'GET' && options.parse !== false && samePath( options.path ) ) {
				shareFirst = false;
				return Promise.resolve( { rendered: first } );
			}
			return next( options );
		} );
	}

	/*
	 * While a live preview reloads (a logo change), the header it already
	 * shows stays: ServerSideRender hands its loading placeholder the previous
	 * render as `children`, in the same class-less <div>. Before it has one,
	 * the preloaded header; only with neither, a spinner (with a class of its
	 * own, so editor-design.css leaves it a real box).
	 */
	function Loading( props ) {
		if ( props && props.children ) {
			return props.children;
		}
		if ( first !== '' ) {
			return el( RawHTML, null, first );
		}
		return el( 'div', { className: 'dxai-ui-site-header__loading', style: { padding: '24px', textAlign: 'center' } }, el( Spinner ) );
	}

	/**
	 * The Site Logo, as the editor's site entity holds it (unsaved edits
	 * included), for someone allowed to change it.
	 */
	function useSiteLogo() {
		const state = useSelect( function ( select ) {
			const core = select( 'core' );
			const canEdit = !! core.canUser( 'update', { kind: 'root', name: 'site' } );
			const site = canEdit ? core.getEditedEntityRecord( 'root', 'site' ) : undefined;
			const logoId = site && site.site_logo ? Number( site.site_logo ) : 0;
			return {
				canEdit: canEdit,
				logoId: logoId,
				dirty: canEdit ? core.hasEditsForEntityRecord( 'root', 'site' ) : false,
				media: logoId ? core.getMedia( logoId, { context: 'view' } ) : null,
			};
		}, [] );
		const { editEntityRecord } = useDispatch( 'core' );

		return Object.assign( {}, state, {
			setLogo: function ( id ) {
				editEntityRecord( 'root', 'site', undefined, { site_logo: id || null } );
			},
		} );
	}

	function LogoPanel( props ) {
		const logo = props.logo;
		if ( ! logo.canEdit ) {
			return el( 'p', null, __( 'The logo is the WordPress Site Logo. An administrator can change it here.', 'dxai-ui' ) );
		}
		let preview = '';
		if ( logo.media && logo.media.source_url ) {
			preview = logo.media.source_url;
		} else if ( ! logo.logoId ) {
			preview = designLogo.url;
		}

		return el(
			Fragment,
			null,
			preview
				? el( 'img', { src: preview, alt: '', className: 'dxai-site-header-editor__logo', style: { display: 'block', maxWidth: '100%', height: 'auto', marginBottom: '8px' } } )
				: null,
			el(
				MediaUploadCheck,
				null,
				el( MediaUpload, {
					allowedTypes: [ 'image' ],
					value: logo.logoId || undefined,
					onSelect: function ( media ) {
						logo.setLogo( media && media.id ? media.id : 0 );
					},
					render: function ( args ) {
						return el( Button, { variant: 'secondary', onClick: args.open }, logo.logoId ? __( 'Replace logo', 'dxai-ui' ) : __( 'Choose logo', 'dxai-ui' ) );
					},
				} )
			),
			designLogo.id && logo.logoId !== designLogo.id
				? el( Button, { variant: 'link', onClick: function () { logo.setLogo( designLogo.id ); } }, __( "Use the design's logo", 'dxai-ui' ) )
				: null,
			el( 'p', { className: 'description' }, __( 'Saved with the page: Save asks to update the site settings too.', 'dxai-ui' ) ),
			cfg.customizerUrl ? el( 'p', null, el( ExternalLink, { href: cfg.customizerUrl }, __( 'Site Identity in the Customizer', 'dxai-ui' ) ) ) : null
		);
	}

	function Edit( props ) {
		const blockProps = useBlockProps( { className: 'alignfull' } );
		const logo = useSiteLogo();
		// An unsaved logo is previewed by asking the renderer for it; the
		// server honours this only for someone who may change the logo.
		const query = logo.dirty ? { dxai_logo: logo.logoId || 0 } : {};
		const menus = Array.isArray( cfg.menus ) ? cfg.menus : [];

		const toolbar = cfg.canEditMenus
			? el(
				BlockControls,
				{ group: 'other' },
				el(
					ToolbarGroup,
					null,
					el( ToolbarButton, { icon: 'menu', label: __( 'Edit the header links in Appearance > Menus', 'dxai-ui' ), href: cfg.menusUrl, target: '_blank', rel: 'noopener noreferrer' }, __( 'Edit in Menus', 'dxai-ui' ) )
				)
			)
			: null;

		const inspector = el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{ title: __( 'Header content', 'dxai-ui' ), initialOpen: true },
				el( 'p', null, __( 'The links, trust items and buttons come from Appearance > Menus; the look comes from the imported design.', 'dxai-ui' ) ),
				cfg.design && ! cfg.siteHeader
					? el( 'p', { className: 'description' }, __( 'This page keeps the header of its own design, filled from that design’s menus below. The menu locations belong to the design imported last.', 'dxai-ui' ) )
					: null,
				1 === cfg.levels
					? el( 'p', { className: 'description' }, __( 'This design’s navigation has no dropdowns: sub-items in the primary menu are not shown in the bar.', 'dxai-ui' ) )
					: null,
				2 === cfg.levels
					? el( 'p', { className: 'description' }, __( 'The primary menu shows two levels here: top items and their dropdowns.', 'dxai-ui' ) )
					: null,
				menus.map( function ( m ) {
					return el(
						'p',
						{ key: m.location },
						el( 'strong', null, m.label ),
						el( 'br' ),
						m.menu ? m.menu + ' ' : __( 'No menu assigned', 'dxai-ui' ) + ' ',
						cfg.canEditMenus ? el( ExternalLink, { href: m.editUrl }, __( 'Edit', 'dxai-ui' ) ) : null
					);
				} ),
				cfg.canEditMenus ? el( 'p', null, el( ExternalLink, { href: cfg.locationsUrl }, __( 'Manage menu locations', 'dxai-ui' ) ) ) : null
			),
			el( PanelBody, { title: __( 'Site Logo', 'dxai-ui' ), initialOpen: false }, el( LogoPanel, { logo: logo } ) )
		);

		if ( logo.dirty && staticFirst ) {
			// Live from now on; the preload is not this header any more, so
			// a later request for the saved logo goes to the server too.
			staticFirst = false;
			shareFirst = false;
		}
		const preview = staticFirst && first !== ''
			? el( RawHTML, null, first )
			: el( ServerSideRender, { block: 'dxai-ui/site-header', attributes: props.attributes, urlQueryArgs: query, LoadingResponsePlaceholder: Loading } );
		const body = cfg.installed
			? el( Disabled, null, preview )
			: el( Placeholder, {
				icon: 'menu',
				label: __( 'Site Header', 'dxai-ui' ),
				instructions: __( 'No header is installed yet. Importing a design with DXAI-UI installs its header, with its links in Appearance > Menus.', 'dxai-ui' ),
			} );
		// Outside a converted page no design stylesheet styles the header
		// (both its desktop and its mobile rows show). Said above the
		// preview, not inside it, so the preview stays the front end's.
		const notice = cfg.installed && ! cfg.scoped
			? el( Notice, { status: 'warning', isDismissible: false }, __( 'This page is not a converted design, so the header shows here without the design’s styles. It belongs at the top of a page DXAI-UI imported.', 'dxai-ui' ) )
			: null;

		return el( Fragment, null, toolbar, inspector, el( 'div', blockProps, notice, body ) );
	}

	registerBlockType( 'dxai-ui/site-header', {
		apiVersion: 3,
		title: __( 'Site Header', 'dxai-ui' ),
		category: 'theme',
		icon: 'menu',
		attributes: {},
		usesContext: [ 'postId' ],
		// As the server registers it (Site_Header_Block): placed by the importer.
		supports: { html: false, multiple: false, reusable: false, className: false, customClassName: false, inserter: false },
		edit: Edit,
		save: function () {
			return null;
		},
	} );
}( window.wp ) );

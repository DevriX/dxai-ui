( function ( wp ) {
	const el = wp.element.createElement;
	const { registerBlockType } = wp.blocks;
	const { useBlockProps, useInnerBlocksProps, InspectorControls, RichText, MediaUpload, InnerBlocks } = wp.blockEditor;
	const { PanelBody, TextControl, TextareaControl, Button, SelectControl } = wp.components;
	const { addFilter } = wp.hooks;
	const { createHigherOrderComponent } = wp.compose;
	const { useRef, useMemo, useLayoutEffect } = wp.element;

	// Blocks that carry a raw inline style off the design. Core's own `style`
	// attribute is a theme.json object and cannot hold `font-size: clamp(...)`,
	// which is where these designs keep their type scale.
	// The theme's own Link box and Span take the same (Native_Blocks): the design's CSS rides their dxs- class too.
	const DXUI_STYLED = [ 'core/paragraph', 'core/heading', 'core/group', 'core/list', 'core/list-item', 'core/image', 'core/button', 'amr/link-box', 'amr/span' ];

	// Core blocks whose design data attributes save() writes (see the
	// getSaveContent filters below), so the canvas carries them too.
	const DXUI_DATA_CORE = [ 'core/group', 'core/list', 'core/list-item' ];

	/*
	 * The canvas shows a block the way the front end does: with the same
	 * dxs- class save() writes, and a rule for that class in a <style> of the
	 * canvas (see dxuiLiveRules()) — not an inline preview. The inline
	 * preview parsed the declarations through a detached element's
	 * CSSStyleDeclaration and copied its longhands, and every longhand of a
	 * shorthand holding var() reads back empty: 74 declarations on Semper Dry
	 * (every `background:var(--dxui-…)`, so every section background and the
	 * hero overlay) never reached the canvas.
	 */

	// A block's own CSS: dxuiCss, else the inline style it was stored with
	// before its CSS moved out of the markup (dx-ui/link keeps that one in
	// `style`). Only for the canvas — save() still writes the legacy style.
	function dxuiOwnCss( a, name ) {
		const css = ( a && ( a.dxuiCss || ( name === 'dx-ui/link' ? a.style : a.dxuiStyle ) ) ) || '';

		return typeof css === 'string' && css.trim() !== '' ? css : '';
	}

	// The dxs- class the canvas gives a block for its own CSS.
	function dxuiCanvasClass( a, name ) {
		const css = dxuiOwnCss( a, name );

		return css ? dxuiCssClass( css ) : '';
	}

	function dxuiJoin() {
		return Array.prototype.slice.call( arguments ).filter( Boolean ).join( ' ' ).trim();
	}

	// Attributes the block editor sets on every block element itself. A design
	// value for one of these would either be overwritten or break the editor
	// (a design tabindex or role on an editable block), so the canvas leaves
	// them to the editor. aria-hidden stays off every block element too: see
	// dxuiReactProps().
	const DXUI_EDITOR_OWNED = [ 'id', 'role', 'tabindex', 'aria-label', 'draggable', 'contenteditable', 'inert', 'autofocus', 'data-block', 'data-type', 'data-title', 'data-empty', 'data-align', 'data-draggable', 'data-clear' ];

	// A block's data-* attributes (dxuiData), for the canvas element.
	function dxuiCanvasData( a ) {
		const data = a && a.dxuiData && typeof a.dxuiData === 'object' ? a.dxuiData : null;
		if ( ! data ) {
			return null;
		}
		const out = {};
		Object.keys( data ).forEach( function ( key ) {
			const name = String( key ).toLowerCase();
			if ( name.indexOf( 'data-' ) === 0 && DXUI_EDITOR_OWNED.indexOf( name ) === -1 && data[ key ] !== undefined && data[ key ] !== null ) {
				out[ name ] = String( data[ key ] );
			}
		} );

		return Object.keys( out ).length ? out : null;
	}

	// HTML attribute names React DOM spells differently.
	const DXUI_REACT_NAMES = {
		for: 'htmlFor',
		referrerpolicy: 'referrerPolicy',
		srcset: 'srcSet',
		crossorigin: 'crossOrigin',
		readonly: 'readOnly',
		maxlength: 'maxLength',
		minlength: 'minLength',
		autocomplete: 'autoComplete',
		enctype: 'encType',
		novalidate: 'noValidate',
		colspan: 'colSpan',
		rowspan: 'rowSpan',
		usemap: 'useMap',
		datetime: 'dateTime',
		inputmode: 'inputMode',
		spellcheck: 'spellCheck',
		'accept-charset': 'acceptCharset',
		'stroke-width': 'strokeWidth',
	};

	// Boolean attributes: present at all, whatever the value (`open=""` is an
	// open <details>), where React would read '' as false and drop them.
	const DXUI_REACT_BOOLEAN = [ 'hidden', 'open', 'required', 'multiple', 'novalidate', 'readonly', 'autoplay', 'muted', 'loop', 'controls', 'playsinline', 'reversed' ];

	/*
	 * The attributes one of save()'s prop builders writes (HTML names, as the
	 * serializer takes them), as props React DOM renders the same element
	 * with in the canvas. `style` is left out — its CSS arrives as the dxs-
	 * class — and so is what the editor owns (DXUI_EDITOR_OWNED). A form
	 * control's value becomes its default value, so the canvas does not warn
	 * about a controlled input, and `disabled` becomes aria-disabled on an
	 * element with editable children, which a disabled button would stop
	 * receiving clicks for. `defaultvalue` is a React prop leaked into the
	 * design's HTML; the browser ignores it on the front end, so the canvas
	 * does too.
	 *
	 * aria-hidden is never written. These props go on the block's own
	 * element, which the editor makes focusable (tabindex 0, "Block: …") and
	 * focuses when the block is selected, so a selected decorative icon or
	 * image put focus inside an aria-hidden element, and a text block's copy
	 * went silent for a screen reader. No design sheet on the site selects
	 * on aria-hidden, so the canvas looks the same without it.
	 */
	function dxuiReactProps( props, tag, opts ) {
		const out = {};
		const options = opts || {};
		const control = [ 'input', 'textarea', 'select' ].indexOf( tag ) > -1;
		Object.keys( props || {} ).forEach( function ( key ) {
			const value = props[ key ];
			if ( key === 'className' ) {
				out.className = value;
				return;
			}
			const name = String( key ).toLowerCase();
			if ( name === 'style' || name === 'defaultvalue' || DXUI_EDITOR_OWNED.indexOf( name ) > -1 ) {
				return;
			}
			if ( name === 'aria-hidden' ) {
				return;
			}
			if ( name === 'disabled' ) {
				if ( options.inert ) {
					out.disabled = true;
				} else {
					out[ 'aria-disabled' ] = 'true';
				}
				return;
			}
			if ( DXUI_REACT_BOOLEAN.indexOf( name ) > -1 ) {
				out[ DXUI_REACT_NAMES[ name ] || name ] = true;
				return;
			}
			if ( control && name === 'value' ) {
				out.defaultValue = value;
				return;
			}
			if ( control && name === 'checked' ) {
				out.defaultChecked = true;
				return;
			}
			out[ DXUI_REACT_NAMES[ name ] || name ] = value;
		} );

		return out;
	}

	/*
	 * Style_Rules::rule(), safe() and closed() in src/Blocks/Style_Rules.php,
	 * ported so a rule written here is the rule the page is served with:
	 * `:is(.dxs-…,#dxui-h)` three times over, (3,0,0) — the precedence the
	 * inline style had — with braces and angle brackets escaped and a
	 * declaration that never closes cut off. PHP walks bytes and this walks
	 * UTF-16 units; every character the scan acts on is ASCII, which UTF-8
	 * never uses inside a multi-byte character, so both cut at the same place.
	 */
	function dxuiClosed( css ) {
		let depth = 0;
		let quote = '';
		let keep = 0;
		for ( let i = 0; i < css.length; i++ ) {
			const ch = css[ i ];
			if ( ch === '\\' ) {
				if ( i + 1 >= css.length ) {
					return css.slice( 0, keep );
				}
				i++;
				continue;
			}
			if ( quote !== '' ) {
				if ( ch === quote ) {
					quote = '';
				}
				continue;
			}
			if ( ch === '"' || ch === "'" ) {
				quote = ch;
			} else if ( ch === '(' || ch === '[' ) {
				depth++;
			} else if ( ( ch === ')' || ch === ']' ) && depth > 0 ) {
				depth--;
			} else if ( ch === ';' && depth === 0 ) {
				keep = i + 1;
			}
		}

		return quote === '' && depth === 0 ? css : css.slice( 0, keep );
	}

	function dxuiSafeCss( css ) {
		const escaped = String( css ).replace( /[<>{}]/g, function ( ch ) {
			return { '<': '\\3C ', '>': '\\3E ', '{': '\\7B ', '}': '\\7D ' }[ ch ];
		} );

		// PHP's trim() set, not String#trim()'s wider one.
		return dxuiClosed( escaped ).replace( /^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '' );
	}

	/*
	 * Token_Styles::fg_channel() in src/Compiler/Token_Styles.php: a colour a design takes from one of its tokens is read through
	 * the token's text channel (`var(--dxui-ink--fg,var(--dxui-ink))`), which is where a theme's binding keeps the colours it has
	 * set apart (Theme_Binding). The server writes every rule that way, and a rule written here has to as well, or the canvas shows
	 * the design's colour where the page shows the theme's.
	 */
	function dxuiFgChannel( css ) {
		if ( String( css ).indexOf( 'var(--dxui-' ) === -1 ) {
			return css;
		}

		return String( css ).replace( /(^|[;{\s])((?:-webkit-text-fill-|text-decoration-|text-emphasis-|caret-|column-rule-)?color|fill|stroke)(\s*:\s*)([^;{}]*)/gi, function ( match, before, prop, colon, value ) {
			return before + prop + colon + value.replace( /(?<!--fg,)var\(\s*--dxui-([a-z0-9]+(?:-[a-z0-9]+)*)\s*\)/gi, 'var(--dxui-$1--fg,var(--dxui-$1))' );
		} );
	}

	function dxuiRule( cls, css ) {
		const safe = dxuiSafeCss( dxuiFgChannel( css ) );
		if ( safe === '' || ! /^dxs-[a-z0-9]+$/.test( cls ) ) {
			return '';
		}
		const one = ':is(.' + cls + ',#dxui-h)';

		return one + one + one + '{' + safe + '}';
	}

	/*
	 * No inline styles in saved markup (Style_Hoister, PHP): a block's own CSS
	 * lives in its dxuiCss attribute, and save() writes the class
	 * dxs-{hash} in place of style="". Style_Rules writes the rule for it
	 * when the page renders. The hash is FNV-1a over the CSS text's UTF-8
	 * bytes, in base 36 — Style_Hoister::css_class() computes the same, so a
	 * block saved here names the rule the server writes.
	 */
	function dxuiCssClass( css ) {
		const bytes = new window.TextEncoder().encode( String( css ) );
		let hash = 0x811c9dc5;
		for ( let i = 0; i < bytes.length; i++ ) {
			hash ^= bytes[ i ];
			hash = Math.imul( hash, 0x01000193 ) >>> 0;
		}
		return 'dxs-' + ( hash >>> 0 ).toString( 36 );
	}

	// A block's saved props with its dxuiCss as a class instead of a style.
	function dxuiHoist( props, a ) {
		if ( ! a || ! a.dxuiCss ) {
			return props;
		}
		const out = Object.assign( {}, props );
		delete out.style;
		const cls = dxuiCssClass( a.dxuiCss );
		out.className = out.className ? out.className + ' ' + cls : cls;
		return out;
	}

	// The core blocks' canvas element gets what their save() writes: the dxs-
	// class and the design's data attributes, which the design's own rules
	// select on (`[data-desk]`/`[data-mob]` swap Semper Dry's header rows, and
	// with none in the canvas both rows showed). Their dx-ui counterparts
	// do the same in their edit(). role and the aria attributes stay with the
	// editor (DXUI_EDITOR_OWNED). Any block's anchor — the id save() writes,
	// which the editor replaces with its own — goes on as data-dxui-id, which
	// the canvas's copy of the design's id rules also selects on (see
	// dxuiTwinDesignIds()).
	addFilter(
		'editor.BlockListBlock',
		'dx-ui/canvas-design-attrs',
		createHigherOrderComponent( function ( BlockListBlock ) {
			return function ( props ) {
				const name = props.name || ( props.block && props.block.name ) || '';
				const attributes = props.attributes || {};
				const styled = DXUI_STYLED.indexOf( name ) > -1;
				const cls = styled ? dxuiCanvasClass( attributes, name ) : '';
				const data = styled && DXUI_DATA_CORE.indexOf( name ) > -1 ? dxuiCanvasData( attributes ) : null;
				const anchor = typeof attributes.anchor === 'string' ? attributes.anchor.trim() : '';
				if ( ! cls && ! data && ! anchor ) {
					return el( BlockListBlock, props );
				}
				const wrapperProps = Object.assign( {}, props.wrapperProps || {}, data || {} );
				if ( cls ) {
					wrapperProps.className = dxuiJoin( wrapperProps.className, cls );
				}
				if ( anchor ) {
					wrapperProps[ 'data-dxui-id' ] = anchor;
				}
				return el( BlockListBlock, Object.assign( {}, props, { wrapperProps: wrapperProps } ) );
			};
		}, 'dxUiCanvasDesignAttrs' )
	);

	/*
	 * A design's wrappers, folded into the block they hold when the editor
	 * opened the page (Wrap_Fold): the List View shows the heading, not the
	 * four groups around it. The canvas draws those groups' elements around
	 * the block again — their tag, classes, id, ARIA and data attributes, as
	 * the front end renders them — so the design's CSS reaches the block as
	 * there (`.gem-element-7a3257c .gem-heading-title`, a flex item, a padded
	 * box). A section lifted to the top of the page carries the page's frame
	 * (metadata.dxuiFrame), drawn the same way, outside the section's own
	 * wrappers. Nothing of this is saved: the page is unfolded on the server
	 * when the editor saves it.
	 */
	const dxuiWrapShapes = new Map();

	function dxuiWrapShape( wrap ) {
		const open = wrap && typeof wrap.open === 'string' ? wrap.open : '';
		if ( dxuiWrapShapes.has( open ) ) {
			return dxuiWrapShapes.get( open );
		}
		let shape = null;
		if ( /^\s*<[a-zA-Z][a-zA-Z0-9-]*[\s>]/.test( open ) ) {
			const doc = new window.DOMParser().parseFromString( '<template>' + open + ( typeof wrap.close === 'string' ? wrap.close : '' ) + '</template>', 'text/html' );
			const template = doc.querySelector( 'template' );
			const node = template && template.content ? template.content.firstElementChild : null;
			if ( node ) {
				const props = {};
				Array.prototype.forEach.call( node.attributes, function ( attr ) {
					const name = attr.name;
					if ( name === 'style' || /^on/i.test( name ) ) {
						return;
					}
					if ( name === 'class' ) {
						props.className = attr.value;
					} else if ( name === 'for' ) {
						props.htmlFor = attr.value;
					} else if ( name === 'tabindex' ) {
						props.tabIndex = attr.value;
					} else {
						props[ name ] = attr.value;
					}
				} );
				// What WordPress adds to a group's element when it renders one with no layout of its own.
				if ( wrap.name === 'core/group' && ! ( wrap.attrs && wrap.attrs.layout ) ) {
					props.className = dxuiJoin( props.className, 'is-layout-flow wp-block-group-is-layout-flow' );
				}
				// The canvas's copy of the design's id rules selects on data-dxui-id (dxuiTwinDesignIds()).
				if ( props.id ) {
					props[ 'data-dxui-id' ] = props.id;
				}
				shape = { tag: node.tagName.toLowerCase(), props: props };
			}
		}
		dxuiWrapShapes.set( open, shape );

		return shape;
	}

	addFilter(
		'editor.BlockListBlock',
		'dx-ui/folded-wrappers',
		createHigherOrderComponent( function ( BlockListBlock ) {
			return function ( props ) {
				const meta = props.attributes && props.attributes.metadata;
				const wraps = [];
				if ( meta && meta.dxuiFrame && Array.isArray( meta.dxuiFrame.wraps ) ) {
					Array.prototype.push.apply( wraps, meta.dxuiFrame.wraps );
				}
				if ( meta && Array.isArray( meta.dxuiWrap ) ) {
					Array.prototype.push.apply( wraps, meta.dxuiWrap );
				}
				let out = el( BlockListBlock, props );
				for ( let i = wraps.length - 1; i >= 0; i-- ) {
					const shape = dxuiWrapShape( wraps[ i ] );
					if ( shape ) {
						out = el( shape.tag, shape.props, out );
					}
				}

				return out;
			};
		}, 'dxUiFoldedWrappers' )
	);

	// A block's own CSS, editable. It is kept out of the markup (save()
	// writes a dxs- class for it), so this field is where it lives. Editing
	// a block still on its old inline style moves that style here.
	addFilter(
		'editor.BlockEdit',
		'dx-ui/design-css',
		createHigherOrderComponent( function ( BlockEdit ) {
			return function ( props ) {
				const type = wp.blocks.getBlockType( props.name );
				if ( ! props.isSelected || ! type || ! type.attributes || ! type.attributes.dxuiCss ) {
					return el( BlockEdit, props );
				}
				const a = props.attributes || {};
				const legacy = props.name === 'dx-ui/link' ? 'style' : 'dxuiStyle';
				return el( wp.element.Fragment, null,
					el( BlockEdit, props ),
					el( InspectorControls, null,
						el( PanelBody, { title: 'Design CSS', initialOpen: false },
							el( TextareaControl, {
								label: 'Declarations',
								help: 'Written to the page stylesheet, not into the block markup.',
								value: a.dxuiCss || a[ legacy ] || '',
								onChange: function ( value ) {
									const next = { dxuiCss: value };
									if ( a[ legacy ] ) {
										next[ legacy ] = type.attributes[ legacy ] && type.attributes[ legacy ].default !== undefined ? type.attributes[ legacy ].default : undefined;
									}
									props.setAttributes( next );
								},
							} )
						)
					)
				);
			};
		}, 'dxUiDesignCss' )
	);

	/*
	 * ---- The canvas document ----
	 *
	 * The design's stylesheet, the brand override, the dxs- rules stored with
	 * the page and the fonts reach the canvas once, as iframe assets
	 * (Design_Blocks::enqueue_editor_canvas()). They used to arrive three
	 * times — that channel, a copy in the editor settings and <link>s this
	 * script added — and the two later copies came after the brand override,
	 * so the canvas showed the imported token values instead of the ones
	 * Styles is set to. What only this script can do is below.
	 */
	function dxuiEditorCfg() {
		return window.dxUIEditor || {};
	}

	function dxuiCanvasCfg() {
		const canvas = dxuiEditorCfg().canvas;

		return canvas && typeof canvas === 'object' ? canvas : {};
	}

	// Whether the post renders on the plugin's blank template, read from the
	// edited value, so picking another template in the inspector switches
	// the canvas over with it.
	function dxuiOnBlank() {
		const canvas = dxuiCanvasCfg();
		if ( ! canvas.converted ) {
			return false;
		}
		let template;
		try {
			const store = wp.data.select( 'core/editor' );
			template = store && store.getEditedPostAttribute ? store.getEditedPostAttribute( 'template' ) : undefined;
		} catch ( e ) {
			template = undefined;
		}

		if ( typeof template === 'string' ) {
			return Array.isArray( canvas.blankTemplates ) ? canvas.blankTemplates.indexOf( template ) !== -1 : template === canvas.blankTemplate;
		}

		return !! canvas.blank;
	}

	/*
	 * The blank template's front end, in the editor's settings.
	 *
	 * Blank_Template dequeues the theme's global styles on the front end and
	 * gives back only their tokens (`variables` and `presets`), and the page's
	 * top-level blocks sit in a plain flow <div>. The canvas had neither
	 * difference: Twenty Twenty-Five's constrained root layout capped every
	 * section at 645px with 1.2rem between them (15,147px of canvas for an
	 * 8,758px page, 5,290px of it from this alone), and its body typography
	 * (21.76px / 300 / line-height 1.4) reached every element the design
	 * leaves at the browser's default — another 776px.
	 *
	 * Both come from the editor's settings, so both are corrected there. The
	 * root's width rule is written by the constrained layout only when the
	 * global layout names a contentSize or wideSize, so those two leave
	 * `__experimentalFeatures.layout` — which is also what the front end
	 * has: with the global styles dequeued, `--wp--style--global--content-
	 * size` is undefined there and an inheriting group is not capped either.
	 * (`supportsLayout` off would drop the rule too, but it also turns every
	 * core/group back into its classic-theme markup, with an inner-container
	 * <div> that took a grid's items out of the grid.) The theme's global
	 * styles, its editor stylesheet and core's default editor styles leave
	 * `styles`, with the front end's token sheet in their place.
	 * In the post editor of a block theme GlobalStylesRenderer rebuilds the
	 * global styles in the browser after the server's copy arrives, so this
	 * runs whenever the settings change rather than once, and only for a
	 * converted post on the blank template. What it takes out it keeps, and
	 * gives back if the template is switched away.
	 */
	const dxuiTheme = { seen: null, blank: null, layout: undefined, global: [], other: [] };

	function dxuiIsThemeStyle( style, defaults ) {
		if ( ! style || typeof style !== 'object' || style.dxuiTokens ) {
			return false;
		}
		if ( style.isGlobalStyles ) {
			// Duotone filter definitions, which the front end prints per block.
			return style.__unstableType !== 'svgs';
		}

		return style.__unstableType === 'theme' || defaults.indexOf( style ) > -1;
	}

	function dxuiReconcileSettings() {
		const canvas = dxuiCanvasCfg();
		if ( ! canvas.converted ) {
			return;
		}
		const store = wp.data.select( 'core/editor' );
		if ( ! store || ! store.getEditorSettings ) {
			return;
		}
		const settings = store.getEditorSettings();
		const blank = dxuiOnBlank();
		if ( settings === dxuiTheme.seen && blank === dxuiTheme.blank ) {
			return;
		}
		dxuiTheme.seen = settings;
		dxuiTheme.blank = blank;
		const styles = Array.isArray( settings.styles ) ? settings.styles : [];
		const isTokens = function ( style ) {
			return !! ( style && style.dxuiTokens );
		};
		const next = {};
		if ( blank ) {
			const defaults = Array.isArray( settings.defaultEditorStyles ) ? settings.defaultEditorStyles : [];
			const dropped = styles.filter( function ( style ) {
				return dxuiIsThemeStyle( style, defaults );
			} );
			const kept = styles.filter( function ( style ) {
				return dropped.indexOf( style ) === -1;
			} );
			const tokens = typeof canvas.tokens === 'string' && canvas.tokens !== '' && ! kept.some( isTokens );
			if ( dropped.length || tokens ) {
				const global = dropped.filter( function ( style ) {
					return style.isGlobalStyles;
				} );
				if ( global.length ) {
					dxuiTheme.global = global;
				}
				dropped.forEach( function ( style ) {
					if ( ! style.isGlobalStyles && dxuiTheme.other.indexOf( style ) === -1 ) {
						dxuiTheme.other.push( style );
					}
				} );
				next.styles = tokens ? [ { css: canvas.tokens, __unstableType: 'presets', dxuiTokens: true } ].concat( kept ) : kept;
			}
			const features = settings.__experimentalFeatures;
			const layout = features && features.layout;
			if ( layout && ( layout.contentSize || layout.wideSize ) ) {
				dxuiTheme.layout = layout;
				const open = Object.assign( {}, layout );
				delete open.contentSize;
				delete open.wideSize;
				next.__experimentalFeatures = Object.assign( {}, features, { layout: open } );
			}
		} else {
			if ( styles.some( isTokens ) ) {
				// Where the editor itself puts them: the theme's own sheets
				// first, the rebuilt global styles last.
				next.styles = dxuiTheme.other.concat( styles.filter( function ( style ) {
					return ! isTokens( style );
				} ), dxuiTheme.global );
				dxuiTheme.other = [];
				dxuiTheme.global = [];
			}
			const features = settings.__experimentalFeatures;
			const layout = features && features.layout;
			if ( dxuiTheme.layout && layout && ! layout.contentSize && ! layout.wideSize ) {
				next.__experimentalFeatures = Object.assign( {}, features, { layout: Object.assign( {}, layout, {
					contentSize: dxuiTheme.layout.contentSize,
					wideSize: dxuiTheme.layout.wideSize,
				} ) } );
			}
			dxuiTheme.layout = undefined;
		}
		if ( Object.keys( next ).length ) {
			wp.data.dispatch( 'core/editor' ).updateEditorSettings( next );
		}
	}

	/*
	 * "Show template" on a page that renders on the blank template.
	 *
	 * The blank template is a PHP template (Blank_Template), not a block
	 * template, so the editor has none to show: with the template shown it
	 * resolved the theme's page template instead (`twentytwentyfive//page`)
	 * and wrapped the content in its header part, a post-content group and
	 * its footer part — 131px above and 630px below that the page does not
	 * have, unstyled once the theme's styles are out of the canvas. While the
	 * post is on the blank template the canvas shows the post alone.
	 * setRenderingMode() changes this editor only; the person's "Show
	 * template" preference is setDefaultRenderingMode()'s, and is left as it
	 * is. The mode is given back if the template is switched away.
	 */
	const dxuiMode = { from: '', told: false };

	function dxuiKeepPostOnly() {
		if ( ! dxuiCanvasCfg().converted ) {
			return;
		}
		const select = wp.data.select( 'core/editor' );
		const dispatch = wp.data.dispatch( 'core/editor' );
		if ( ! select || ! select.getRenderingMode || ! dispatch || ! dispatch.setRenderingMode ) {
			return;
		}
		const mode = select.getRenderingMode();
		if ( dxuiOnBlank() ) {
			if ( ! mode || mode === 'post-only' ) {
				return;
			}
			dxuiMode.from = mode;
			dispatch.setRenderingMode( 'post-only' );
			const notices = wp.data.dispatch( 'core/notices' );
			if ( ! dxuiMode.told && notices && notices.createInfoNotice ) {
				dxuiMode.told = true;
				notices.createInfoNotice(
					wp.i18n.__( 'This page renders on the DX UI blank template, which has no theme header or footer, so the editor shows the page alone.', 'dx-ui' ),
					{ id: 'dx-ui-post-only', type: 'snackbar' }
				);
			}
			return;
		}
		if ( dxuiMode.from ) {
			const from = dxuiMode.from;
			dxuiMode.from = '';
			if ( mode === 'post-only' ) {
				dispatch.setRenderingMode( from );
			}
		}
	}

	/*
	 * The rules for the blocks as they are NOW, written into the canvas.
	 *
	 * The server's copy of the dxs- rules (Style_Rules, attached when the
	 * editor loads) knows the blocks as they were stored. A block restyled in
	 * the Design CSS panel gets a new hash, so its new class had no rule until
	 * the page was saved and reloaded. This keeps one <style> per canvas with
	 * a rule for every block's own CSS and its inner runs' (dxuiInner),
	 * rebuilt when an attribute changes — inside template parts and synced
	 * patterns too, whose blocks are in the same store.
	 */
	const DXUI_LIVE_ID = 'dx-ui-live-rules';
	const dxuiLive = { attrs: new Map(), css: '', rules: new Map() };

	/*
	 * Utility classes the canvas has no rule for yet.
	 *
	 * The server's copy (Style_Rules::css_for_post(), in the editor form)
	 * holds the rules of every utility class the stored page and the rest of
	 * the site use. A class a person types into "Additional CSS class(es)",
	 * or a block pasted in with classes nothing on the site used, had no rule
	 * until the page was saved and reopened. Those classes are asked for
	 * (Style_Rules' utility-rules route) and written into a second <style>.
	 *
	 * Only a change asks: a block's className edited, or a block that
	 * arrived after the page loaded — not the stored page, its template parts,
	 * its synced patterns or a widget area's blocks, which load after the
	 * rest and which the server's copy already covers. The request names
	 * every class of every block that changed, not only the new ones: between
	 * two utilities on one element the rule order decides, so the second
	 * sheet holds the whole set in the catalogue's order and, coming later,
	 * decides for those elements as the page's one sheet does.
	 * A name the route answers is not a utility — the design's own class, a
	 * state class — is not asked for again.
	 */
	const DXUI_UTIL_ID = 'dx-ui-live-utilities';
	const dxuiUtil = { seen: new Map(), loaded: false, want: new Set(), got: new Set(), none: new Set(), css: '', timer: 0, busy: false };

	function dxuiClassTokens( value ) {
		return typeof value === 'string' ? value.split( /\s+/ ).filter( Boolean ) : [];
	}

	// The classes of block `id` if they should be asked for: it was edited,
	// or it is new and not inside what the server rendered the rules for.
	function dxuiUtilChanged( store, id, a ) {
		const now = a && typeof a.className === 'string' ? a.className : '';
		const was = dxuiUtil.seen.get( id );
		dxuiUtil.seen.set( id, now );
		if ( was === undefined ) {
			if ( ! dxuiUtil.loaded || ! now ) {
				return [];
			}
			const parents = store.getBlockParentsByBlockName ? store.getBlockParentsByBlockName( id, [ 'core/template-part', 'core/block', 'core/widget-area' ] ) : [];

			return parents && parents.length ? [] : dxuiClassTokens( now );
		}
		if ( was === now ) {
			return [];
		}
		const before = dxuiClassTokens( was );
		const tokens = dxuiClassTokens( now );

		return tokens.some( function ( token ) {
			return before.indexOf( token ) === -1;
		} ) ? tokens : [];
	}

	function dxuiUtilQueue( tokens ) {
		let grew = false;
		tokens.forEach( function ( token ) {
			if ( ! dxuiUtil.got.has( token ) && ! dxuiUtil.want.has( token ) && ! dxuiUtil.none.has( token ) && /^[A-Za-z0-9_:%./[\]-]{1,80}$/.test( token ) && token.indexOf( 'dxs-' ) !== 0 ) {
				dxuiUtil.want.add( token );
				grew = true;
			}
		} );
		// Once typing pauses: a class name typed into the field arrives one
		// letter at a time, and each prefix is a class of its own.
		if ( grew ) {
			clearTimeout( dxuiUtil.timer );
			dxuiUtil.timer = setTimeout( dxuiUtilFetch, 500 );
		}
	}

	function dxuiUtilFetch() {
		dxuiUtil.timer = 0;
		const path = dxuiEditorCfg().utilityRules;
		if ( dxuiUtil.busy || ! dxuiUtil.want.size || typeof path !== 'string' || path === '' || ! wp.apiFetch ) {
			return;
		}
		// Only names still on a block now: the prefixes typed on the way
		// to a class name are gone again.
		const store = wp.data.select( 'core/block-editor' );
		const present = new Set();
		if ( store && store.getClientIdsWithDescendants ) {
			store.getClientIdsWithDescendants().forEach( function ( id ) {
				dxuiClassTokens( ( store.getBlockAttributes( id ) || {} ).className ).forEach( function ( token ) {
					present.add( token );
				} );
			} );
		}
		const fresh = Array.from( dxuiUtil.want ).filter( function ( token ) {
			return present.has( token );
		} );
		dxuiUtil.want.clear();
		if ( ! fresh.length ) {
			return;
		}
		// Every utility asked for so far, in one sheet (see above); the
		// route reads at most 500 names.
		const names = Array.from( dxuiUtil.got ).concat( fresh ).slice( -500 );
		dxuiUtil.busy = true;
		const post = Number( dxuiEditorCfg().postId ) || 0;
		wp.apiFetch( { path: path + ( path.indexOf( '?' ) > -1 ? '&' : '?' ) + 'classes=' + encodeURIComponent( names.join( ' ' ) ) + '&post=' + post } ).then( function ( res ) {
			const live = res && Array.isArray( res.classes ) ? res.classes.map( String ) : [];
			names.forEach( function ( name ) {
				if ( live.indexOf( name ) === -1 ) {
					dxuiUtil.none.add( name );
				}
			} );
			dxuiUtil.got = new Set( live );
			const css = res && typeof res.css === 'string' ? res.css : '';
			if ( css !== dxuiUtil.css ) {
				dxuiUtil.css = css;
				dxuiCanvasDocs().forEach( dxuiWriteRules );
			}
		} ).catch( function () {
			// No route (an older build) or no permission: the classes keep
			// no rule until the page is saved and reopened, as before.
		} ).then( function () {
			dxuiUtil.busy = false;
			// Classes that changed while this request was out.
			if ( dxuiUtil.want.size && ! dxuiUtil.timer ) {
				dxuiUtil.timer = setTimeout( dxuiUtilFetch, 500 );
			}
		} );
	}

	/*
	 * The dxs- classes the server's copy of the rules already writes into the canvas (Style_Rules, attached when the editor loads).
	 * A class is the hash of its CSS, so a block whose class is there needs no rule from this script — and the server's is the one
	 * the front end has: the fonts of a theme that sets them (Theme_Fonts), the colours on the channel a theme's binding keeps
	 * (Theme_Binding). A rule written here for the same class comes later in the canvas and wins, so the canvas showed the
	 * design's own where the page shows the theme's.
	 */
	const DXUI_SERVER_RULES = 'dx-ui-rules-editor-inline-css';
	const dxuiServerSeen = new WeakMap();

	function dxuiServerClasses( doc ) {
		const style = doc.getElementById( DXUI_SERVER_RULES );
		const text = style ? style.textContent : '';
		let seen = dxuiServerSeen.get( doc );
		if ( ! seen || seen.length !== text.length ) {
			seen = { length: text.length, names: new Set( ( text.match( /\.dxs-[a-z0-9]+/g ) || [] ).map( function ( name ) {
				return name.slice( 1 );
			} ) ) };
			dxuiServerSeen.set( doc, seen );
		}

		return seen.names;
	}

	// The rules to write into one canvas: every block's own, but those the server's copy in that canvas has.
	function dxuiLiveCss( doc ) {
		const server = dxuiServerClasses( doc );
		let css = '';
		dxuiLive.rules.forEach( function ( declarations, cls ) {
			if ( ! server.has( cls ) ) {
				css += dxuiRule( cls, declarations );
			}
		} );

		return css;
	}

	function dxuiLiveRules() {
		const store = wp.data.select( 'core/block-editor' );
		if ( ! store || ! store.getClientIdsWithDescendants ) {
			return false;
		}
		const ids = store.getClientIdsWithDescendants();
		const attrs = new Map();
		let changed = ids.length !== dxuiLive.attrs.size;
		const classes = [];
		ids.forEach( function ( id ) {
			const a = store.getBlockAttributes( id );
			attrs.set( id, a );
			if ( dxuiLive.attrs.get( id ) !== a ) {
				changed = true;
				Array.prototype.push.apply( classes, dxuiUtilChanged( store, id, a ) );
			}
		} );
		if ( ids.length ) {
			// The stored page is in the store from here on; what arrives
			// later was inserted.
			dxuiUtil.loaded = true;
		}
		if ( classes.length ) {
			dxuiUtilQueue( classes );
		}
		if ( ! changed ) {
			return false;
		}
		dxuiLive.attrs = attrs;
		const rules = new Map();
		attrs.forEach( function ( a, id ) {
			if ( ! a ) {
				return;
			}
			const own = dxuiOwnCss( a, store.getBlockName( id ) );
			if ( own ) {
				rules.set( dxuiCssClass( own ), own );
			}
			if ( a.dxuiInner && typeof a.dxuiInner === 'object' ) {
				Object.keys( a.dxuiInner ).forEach( function ( cls ) {
					if ( typeof a.dxuiInner[ cls ] === 'string' ) {
						rules.set( cls, a.dxuiInner[ cls ] );
					}
				} );
			}
		} );
		let css = '';
		rules.forEach( function ( declarations, cls ) {
			css += dxuiRule( cls, declarations );
		} );
		if ( css === dxuiLive.css ) {
			return false;
		}
		dxuiLive.css = css;
		dxuiLive.rules = rules;

		return true;
	}

	// Every document blocks are edited in: the canvas iframe(s), or this
	// document where the editor is not iframed (the Widgets screen).
	function dxuiCanvasDocs() {
		const docs = [];
		document.querySelectorAll( 'iframe[name="editor-canvas"]' ).forEach( function ( frame ) {
			let doc = null;
			try {
				doc = frame.contentDocument;
			} catch ( e ) {
				doc = null;
			}
			if ( ! frame.dxuiLoadHooked ) {
				frame.dxuiLoadHooked = true;
				frame.addEventListener( 'load', dxuiSync );
			}
			if ( doc && doc.body ) {
				docs.push( doc );
			}
		} );
		if ( ! docs.length && document.querySelector( '.editor-styles-wrapper .block-editor-block-list__layout' ) ) {
			docs.push( document );
		}

		return docs;
	}

	function dxuiWriteRules( doc ) {
		let style = doc.getElementById( DXUI_LIVE_ID );
		if ( ! style && doc.head ) {
			style = doc.createElement( 'style' );
			style.id = DXUI_LIVE_ID;
			doc.head.appendChild( style );
		}
		const live = dxuiLiveCss( doc );
		if ( style && style.textContent !== live ) {
			style.textContent = live;
		}
		let utilities = doc.getElementById( DXUI_UTIL_ID );
		if ( ! utilities && dxuiUtil.css !== '' && style && style.parentNode ) {
			utilities = doc.createElement( 'style' );
			utilities.id = DXUI_UTIL_ID;
			style.parentNode.insertBefore( utilities, style );
		}
		if ( utilities && utilities.textContent !== dxuiUtil.css ) {
			utilities.textContent = dxuiUtil.css;
		}
		if ( doc.body ) {
			dxuiQueueListItems( doc );
		}
	}

	/*
	 * The classes the canvas <body> (the `.editor-styles-wrapper`) carries
	 * for a converted post — editor-design.css keys its page rules on them:
	 * dx-ui-canvas for any converted post, the design's scope classes
	 * (Page_Scope; on the front end they are on a <div> around the content,
	 * and the scoped stylesheet matches either), dx-ui-static for a Claude
	 * Design export (the front end's body class) and dx-ui-blank while the
	 * post is on the blank template.
	 */
	function dxuiCanvasClasses() {
		const canvas = dxuiCanvasCfg();
		// An ordinary page with sections copied from a design (attached) takes the scope classes too: the front end wraps those sections in them.
		if ( ! canvas.converted && ! canvas.attached ) {
			return [];
		}
		const names = [ 'dx-ui-canvas' ];
		if ( canvas.attached ) {
			// editor-design.css lets the copied sections take the width the front end gives them.
			names.push( 'dx-ui-attached' );
		}
		if ( canvas.static ) {
			names.push( 'dx-ui-static' );
		}
		if ( dxuiOnBlank() ) {
			names.push( 'dx-ui-blank' );
		}
		String( dxuiEditorCfg().scopeClass || '' ).split( /\s+/ ).forEach( function ( name ) {
			if ( name ) {
				names.push( name );
			}
		} );

		return names;
	}

	function dxuiApplyClasses( body ) {
		const want = body.dxuiClasses || [];
		want.forEach( function ( name ) {
			if ( ! body.classList.contains( name ) ) {
				body.classList.add( name );
			}
		} );
	}

	// Clicks in the canvas do not leave the editor. The iframe only stops a
	// `#` link (it scrolls the canvas to the anchor instead); any other link
	// in a design block — a nav item, a CTA, a whole card that is one <a> —
	// navigated the canvas away, and a design <form> submitted. Links in
	// editor UI inside the canvas (placeholders, notices) are left alone.
	function dxuiGuardDoc( doc ) {
		doc.addEventListener( 'click', function ( event ) {
			const target = event.target && event.target.closest ? event.target : null;
			const link = target ? target.closest( 'a[href]' ) : null;
			if ( ! link || event.defaultPrevented || ( link.getAttribute( 'href' ) || '' ).charAt( 0 ) === '#' ) {
				return;
			}
			if ( ! link.closest( '[data-type^="dx-ui/"], .dx-ui-canvas .block-editor-block-list__layout' ) || link.closest( '[class*="components-"]' ) ) {
				return;
			}
			event.preventDefault();
		}, true );
		doc.addEventListener( 'submit', function ( event ) {
			const form = event.target;
			if ( form && form.closest && form.closest( '.block-editor-block-list__layout' ) ) {
				event.preventDefault();
			}
		}, true );
	}

	/*
	 * Core gives every rich-text element `white-space: pre-wrap` and
	 * `min-width: 1px` as inline style (set from a ref, once, when it mounts),
	 * so an editing caret sees every space. Inline, that beat the design's own
	 * rule for the element: a `white-space: nowrap` footer line wrapped onto
	 * two, the space before a nav item's ▼ kept its width (3px on every
	 * dropdown trigger), and the spaces between a list item's <p> and <h3>
	 * became lines of their own (38px on the process cards). No stylesheet
	 * can take an inline declaration back without also beating the design,
	 * so in a converted page's canvas the two are there only while the
	 * element is being edited — put back when it takes focus, taken off
	 * when it loses it, and never left on one that just mounted unfocused.
	 */
	const DXUI_RICH_TEXT = '.block-editor-rich-text__editable';

	function dxuiRestRichText( node, doc ) {
		if ( node === doc.activeElement ) {
			return;
		}
		if ( node.style.whiteSpace === 'pre-wrap' ) {
			node.style.removeProperty( 'white-space' );
		}
		if ( node.style.minWidth === '1px' ) {
			node.style.removeProperty( 'min-width' );
		}
	}

	function dxuiWatchRichText( doc, body ) {
		doc.addEventListener( 'focusin', function ( event ) {
			const node = event.target && event.target.closest ? event.target.closest( DXUI_RICH_TEXT ) : null;
			if ( node && body.classList.contains( 'dx-ui-canvas' ) ) {
				node.style.whiteSpace = 'pre-wrap';
				node.style.minWidth = '1px';
			}
		} );
		doc.addEventListener( 'focusout', function ( event ) {
			const node = event.target && event.target.closest ? event.target.closest( DXUI_RICH_TEXT ) : null;
			if ( node && body.classList.contains( 'dx-ui-canvas' ) ) {
				// After focus has moved, so activeElement is the new owner.
				setTimeout( function () {
					dxuiRestRichText( node, doc );
				}, 0 );
			}
		} );
		const View = doc.defaultView && doc.defaultView.MutationObserver;
		if ( ! View ) {
			return;
		}
		new View( function ( records ) {
			if ( ! body.classList.contains( 'dx-ui-canvas' ) ) {
				return;
			}
			records.forEach( function ( record ) {
				if ( record.type === 'attributes' ) {
					if ( record.target.matches && record.target.matches( DXUI_RICH_TEXT ) ) {
						dxuiRestRichText( record.target, doc );
					}
					return;
				}
				record.addedNodes.forEach( function ( added ) {
					if ( added.nodeType !== 1 ) {
						return;
					}
					if ( added.matches( DXUI_RICH_TEXT ) ) {
						dxuiRestRichText( added, doc );
					}
					added.querySelectorAll( DXUI_RICH_TEXT ).forEach( function ( node ) {
						dxuiRestRichText( node, doc );
					} );
				} );
			} );
		} ).observe( body, { childList: true, subtree: true, attributes: true, attributeFilter: [ 'style' ] } );
		body.querySelectorAll( DXUI_RICH_TEXT ).forEach( function ( node ) {
			dxuiRestRichText( node, doc );
		} );
	}

	/*
	 * The list items whose rich-text <div> takes over their layout
	 * (editor-design.css): those that lay out their children themselves —
	 * flex, grid, or inline — and those whose marker is inside, where the
	 * text runs on the marker's line. The rest keep core's block <div>;
	 * inheriting a `list-item` display made the <div> a second item of the
	 * list, and an ordered list numbered 1, 3, 5, 7.
	 *
	 * Read from each <li>'s computed display, so the design's own rules, a
	 * block's dxs- rule and a breakpoint all count, and read again whenever
	 * one of them can change: a block renders (its classes or the list's
	 * children change), the rules are rewritten, the canvas is resized (the
	 * Tablet and Mobile previews), a stylesheet finishes loading. Once per
	 * frame at most.
	 */
	const DXUI_LI_LAYOUTS = [ 'flex', 'inline-flex', 'grid', 'inline-grid', 'inline', 'inline-block' ];

	function dxuiLayoutListItems( doc ) {
		const view = doc.defaultView;
		const host = doc.body && doc.body.classList.contains( 'editor-styles-wrapper' ) ? doc.body : doc.querySelector( '.editor-styles-wrapper' );
		if ( ! view || ! host || ! host.classList.contains( 'dx-ui-canvas' ) ) {
			return;
		}
		host.querySelectorAll( 'li[data-type="core/list-item"]' ).forEach( function ( li ) {
			const style = view.getComputedStyle( li );
			const display = style.display;
			let want = DXUI_LI_LAYOUTS.indexOf( display ) > -1 ? display : '';
			if ( ! want && display.indexOf( 'list-item' ) > -1 && style.listStylePosition === 'inside' && ( style.listStyleType !== 'none' || style.listStyleImage !== 'none' ) ) {
				want = 'inside';
			}
			if ( ( li.getAttribute( 'data-dxui-li' ) || '' ) === want ) {
				return;
			}
			if ( want ) {
				li.setAttribute( 'data-dxui-li', want );
			} else {
				li.removeAttribute( 'data-dxui-li' );
			}
		} );
	}

	function dxuiQueueListItems( doc ) {
		const view = doc.defaultView;
		if ( ! view || doc.dxuiListQueued ) {
			return;
		}
		doc.dxuiListQueued = true;
		( view.requestAnimationFrame || view.setTimeout ).call( view, function () {
			doc.dxuiListQueued = false;
			dxuiLayoutListItems( doc );
		} );
	}

	function dxuiWatchListItems( doc, body ) {
		const view = doc.defaultView;
		if ( ! view || doc.dxuiListWatched ) {
			return;
		}
		doc.dxuiListWatched = true;
		const queue = function () {
			dxuiQueueListItems( doc );
		};
		view.addEventListener( 'resize', queue );
		doc.addEventListener( 'load', function ( event ) {
			if ( event.target && event.target.tagName === 'LINK' ) {
				dxuiTwinDesignIds( doc );
				queue();
			}
		}, true );
		if ( view.MutationObserver ) {
			// Class changes and added or removed blocks. The attribute this
			// sets is not watched, so it cannot feed itself.
			new view.MutationObserver( queue ).observe( body, { childList: true, subtree: true, attributes: true, attributeFilter: [ 'class' ] } );
		}
		queue();
	}

	/*
	 * The design's id selectors, in the canvas.
	 *
	 * The editor gives every block element an id of its own
	 * (`block-{clientId}`), so a design rule for a section's id —
	 * `#who.rv-whofor .rv-personas--columns`, in 73 of the 1,512 design
	 * sheets in this site's uploads — matched nothing in the canvas. A block's
	 * anchor rides as data-dxui-id instead, and each rule of the page's design
	 * sheet that names an id gets a twin right after it, in the canvas's copy
	 * of the sheet only, whose `#who` reads `:is(#who,[data-dxui-id="who"])`:
	 * the same specificity (an id's) at the same place in the cascade. Ids
	 * inside attribute selectors (`[href="#who"]`) are left alone, and so is
	 * `#dxui-h`, the id no element has that Style_Rules lifts with. A sheet
	 * served from another origin cannot be read and keeps its rules as they
	 * are.
	 */
	function dxuiIdTwinSelector( selector ) {
		let out = '';
		let quote = '';
		let bracket = 0;
		let changed = false;
		for ( let i = 0; i < selector.length; i++ ) {
			const ch = selector[ i ];
			if ( ch === '\\' ) {
				out += ch + ( selector[ i + 1 ] || '' );
				i++;
				continue;
			}
			if ( quote !== '' ) {
				out += ch;
				if ( ch === quote ) {
					quote = '';
				}
				continue;
			}
			if ( ch === '"' || ch === "'" ) {
				quote = ch;
			} else if ( ch === '[' ) {
				bracket++;
			} else if ( ch === ']' && bracket > 0 ) {
				bracket--;
			} else if ( ch === '#' && bracket === 0 ) {
				const m = /^-?[_a-zA-Z\u00a0-\uffff][\w\u00a0-\uffff-]*/.exec( selector.slice( i + 1 ) );
				if ( m && m[ 0 ] !== 'dxui-h' ) {
					out += ':is(#' + m[ 0 ] + ',[data-dxui-id="' + m[ 0 ] + '"])';
					i += m[ 0 ].length;
					changed = true;
					continue;
				}
			}
			out += ch;
		}

		return changed ? out : '';
	}

	// Backwards, so a twin inserted after its rule is never visited itself.
	function dxuiTwinRules( rules, host ) {
		for ( let i = rules.length - 1; i >= 0; i-- ) {
			const rule = rules[ i ];
			if ( typeof rule.selectorText === 'string' ) {
				const twin = dxuiIdTwinSelector( rule.selectorText );
				if ( twin && rule.cssText.indexOf( rule.selectorText ) === 0 ) {
					try {
						host.insertRule( twin + rule.cssText.slice( rule.selectorText.length ), i + 1 );
					} catch ( e ) {
						// A rule this browser serialises but will not parse back.
					}
				}
			}
			// @media, @supports, @layer, @container — and a style rule's own
			// nested rules.
			if ( rule.cssRules && rule.cssRules.length && typeof rule.insertRule === 'function' ) {
				dxuiTwinRules( rule.cssRules, rule );
			}
		}
	}

	function dxuiTwinDesignIds( doc ) {
		doc.querySelectorAll( 'link[rel="stylesheet"][id^="dx-ui-page-editor-"]' ).forEach( function ( link ) {
			const sheet = link.sheet;
			if ( ! sheet || sheet.dxuiIdTwins ) {
				return;
			}
			let rules = null;
			try {
				rules = sheet.cssRules;
			} catch ( e ) {
				rules = null;
			}
			sheet.dxuiIdTwins = true;
			if ( rules ) {
				dxuiTwinRules( rules, sheet );
			}
		} );
	}

	// Assets::drop_theme_styles() in the canvas: a stylesheet served from the
	// theme's directory is dequeued from a converted page's front end, and
	// its inline CSS goes with it, so both are switched off here too.
	function dxuiDropThemeSheets( doc ) {
		const roots = ( dxuiCanvasCfg().themeRoots || [] ).filter( Boolean );
		if ( ! roots.length ) {
			return;
		}
		// The styles of the theme's own blocks stay, as they do on the front end (the server lists them).
		const blocks = new Set( ( dxuiCanvasCfg().themeBlocks || [] ).map( function ( handle ) {
			return handle + '-css';
		} ) );
		doc.querySelectorAll( 'link[rel="stylesheet"][href]' ).forEach( function ( link ) {
			const href = link.getAttribute( 'href' ) || '';
			if ( link.disabled || blocks.has( link.id ) || ! roots.some( function ( root ) {
				return href.indexOf( root ) === 0;
			} ) ) {
				return;
			}
			link.disabled = true;
			const inline = link.id ? doc.getElementById( link.id.replace( /-css$/, '-inline-css' ) ) : null;
			if ( inline && inline.tagName === 'STYLE' ) {
				inline.disabled = true;
			}
		} );
	}

	function dxuiEnsureDoc( doc, classes ) {
		const body = doc.body && doc.body.classList.contains( 'editor-styles-wrapper' ) ? doc.body : doc.querySelector( '.editor-styles-wrapper' );
		if ( body ) {
			// A class this script added and no longer wants: dx-ui-blank
			// after the template was switched away.
			( body.dxuiClasses || [] ).forEach( function ( name ) {
				if ( classes.indexOf( name ) === -1 ) {
					body.classList.remove( name );
				}
			} );
			body.dxuiClasses = classes;
			dxuiApplyClasses( body );
			// The editor renders the body's class list itself and rewrites it
			// when its own classes change; put ours back before it paints.
			const View = doc.defaultView && doc.defaultView.MutationObserver;
			if ( View && ! body.dxuiObserver ) {
				body.dxuiObserver = new View( function () {
					dxuiApplyClasses( body );
				} );
				body.dxuiObserver.observe( body, { attributes: true, attributeFilter: [ 'class' ] } );
			}
			if ( classes.length && ! body.dxuiRichWatched ) {
				body.dxuiRichWatched = true;
				dxuiWatchRichText( doc, body );
			}
			if ( classes.length ) {
				dxuiWatchListItems( doc, body );
			}
		}
		dxuiWriteRules( doc );
		if ( classes.length ) {
			// The theme's CSS is off a converted page, and off the copied sections of an ordinary page where the front end fences it (Theme_Fence).
			if ( dxuiCanvasCfg().converted || dxuiCanvasCfg().fence ) {
				dxuiDropThemeSheets( doc );
			}
			dxuiTwinDesignIds( doc );
		}
		if ( ! doc.dxuiGuarded ) {
			doc.dxuiGuarded = true;
			dxuiGuardDoc( doc );
		}
	}

	let dxuiQueued = false;

	function dxuiSync() {
		if ( dxuiQueued ) {
			return;
		}
		dxuiQueued = true;
		const run = function () {
			dxuiQueued = false;
			const classes = dxuiCanvasClasses();
			dxuiCanvasDocs().forEach( function ( doc ) {
				dxuiEnsureDoc( doc, classes );
			} );
		};
		if ( window.requestAnimationFrame ) {
			window.requestAnimationFrame( run );
		} else {
			setTimeout( run, 16 );
		}
	}

	if ( wp.data && wp.data.subscribe ) {
		wp.data.subscribe( function () {
			dxuiReconcileSettings();
			dxuiKeepPostOnly();
			// Written at once, before the block that changed re-renders with
			// its new class, so it never paints without its rule.
			if ( dxuiLiveRules() ) {
				dxuiCanvasDocs().forEach( dxuiWriteRules );
			}
			dxuiSync();
		} );
	}
	if ( wp.domReady ) {
		wp.domReady( function () {
			dxuiSync();
			// A canvas the editor replaced without a store change (a new
			// iframe document) is picked up within a second.
			setInterval( dxuiSync, 1000 );
		} );
	}

	addFilter( 'blocks.registerBlockType', 'dx-ui/paragraph-class', function ( settings, name ) {
		/*
		 * core/paragraph's `className` support is deliberately left as core
		 * ships it (off). It used to be switched on here so design classes
		 * would round-trip — but those travel through `customClassName`, which
		 * is on by default. `className` only adds the generated
		 * `wp-block-paragraph` class to save(), and core runs this filter over
		 * the paragraph's deprecations as well, so with it on no stored
		 * paragraph anywhere on the site matched any version of the block:
		 * every ordinary post opened with its paragraphs marked invalid.
		 */
		if ( DXUI_STYLED.indexOf( name ) !== -1 ) {
			settings.attributes = Object.assign( {}, settings.attributes, {
				dxuiStyle: { type: 'string' },
				dxuiCss: { type: 'string' },
				// CSS of the styled runs inside, class => declarations
				// (Style_Hoister::INNER_ATTR). Kept on the block so a copy of
				// it brings its rules along; without it registered, the
				// editor would drop it on the first save.
				dxuiInner: { type: 'object' },
			} );
		}
		if ( name === 'core/html' ) {
			settings.attributes = Object.assign( {}, settings.attributes, {
				dxuiInner: { type: 'object' },
			} );
		}
		// The list and its rows both keep their data attributes: a design's
		// responsive rules select on them (`[data-promise]` on a <ul>).
		if ( name === 'core/list-item' || name === 'core/list' ) {
			// A row's data attributes — `data-dxui-on="is-open"` is how a
			// process-rail row says which state shows it. Html_To_Blocks keeps
			// them here; without the attribute registered, save() dropped them
			// and every such row opened as an invalid block.
			settings.attributes = Object.assign( {}, settings.attributes, {
				dxuiData: { type: 'object' },
			} );
		}
		if ( name === 'core/group' ) {
			settings.attributes = Object.assign( {}, settings.attributes, {
				role: { type: 'string' },
				ariaHidden: { type: 'string' },
				labelledBy: { type: 'string' },
				ariaLabel: { type: 'string' },
				// Behaviour the design drives off data attributes, `data-reveal`
				// above all. Without somewhere to keep them, save() dropped them
				// and every section that had one opened as an invalid block.
				dxuiData: { type: 'object' },
				// Raw inline style off the design. Core's own `style` attribute
				// is a theme.json object and cannot carry arbitrary CSS, and
				// custom-CSS support rejects markup-looking values such as the
				// data-URI SVG backgrounds these designs use.
				dxuiStyle: { type: 'string' },
			} );
		}
		return settings;
	} );

	/*
	 * Pages built from the Home's sections (Site_Pages) had their header's
	 * `href="#services"` pointed at the Home in the stored markup while the
	 * block's `url` kept `#services`, so every such link opened as an invalid
	 * block — and "Attempt recovery" put the bare anchor back, which leads
	 * nowhere on any page but the Home. This older shape reads `url` from the
	 * stored element's own href: the block opens valid, with the link it
	 * shows on the page, and the next save stores it in `url` too.
	 */
	addFilter( 'blocks.registerBlockType', 'dx-ui/stored-href', function ( settings, name ) {
		if ( [ 'dx-ui/link', 'dx-ui/text', 'dx-ui/box' ].indexOf( name ) === -1 || ! settings.attributes || ! settings.attributes.url || ! settings.save ) {
			return settings;
		}
		return Object.assign( {}, settings, {
			deprecated: ( settings.deprecated || [] ).concat( [ {
				apiVersion: settings.apiVersion,
				supports: settings.supports,
				save: settings.save,
				// `*` is the block's own element: hpq takes the first match.
				attributes: Object.assign( {}, settings.attributes, {
					url: { type: 'string', source: 'attribute', selector: '*', attribute: 'href', default: '' },
				} ),
			} ] ),
		} );
	} );

	addFilter( 'blocks.getSaveContent.extraProps', 'dx-ui/inline-style', function ( extra, blockType, attributes ) {
		if ( ! blockType || DXUI_STYLED.indexOf( blockType.name ) === -1 ) {
			return extra;
		}
		if ( attributes.dxuiCss ) {
			const cls = dxuiCssClass( attributes.dxuiCss );
			extra.className = extra.className ? extra.className + ' ' + cls : cls;
		} else if ( attributes.dxuiStyle ) {
			// A block stored before its CSS moved out of the markup.
			extra.style = attributes.dxuiStyle;
		}
		return extra;
	} );

	addFilter( 'blocks.getSaveContent.extraProps', 'dx-ui/list-item-data', function ( extra, blockType, attributes ) {
		if ( ! blockType || ( blockType.name !== 'core/list-item' && blockType.name !== 'core/list' ) ) {
			return extra;
		}
		if ( attributes.dxuiData && typeof attributes.dxuiData === 'object' ) {
			Object.keys( attributes.dxuiData ).forEach( function ( key ) {
				if ( key.indexOf( 'data-' ) === 0 ) {
					extra[ key ] = attributes.dxuiData[ key ];
				}
			} );
		}
		return extra;
	} );

	addFilter( 'blocks.getSaveContent.extraProps', 'dx-ui/group-a11y', function ( extra, blockType, attributes ) {
		if ( ! blockType || blockType.name !== 'core/group' ) {
			return extra;
		}
		if ( attributes.role ) {
			extra.role = attributes.role;
		}
		if ( attributes.ariaHidden ) {
			extra[ 'aria-hidden' ] = attributes.ariaHidden;
		}
		if ( attributes.labelledBy ) {
			extra[ 'aria-labelledby' ] = attributes.labelledBy;
		}
		if ( attributes.ariaLabel ) {
			extra[ 'aria-label' ] = attributes.ariaLabel;
		}
		if ( attributes.dxuiStyle ) {
			extra.style = attributes.dxuiStyle;
		}
		if ( attributes.dxuiData && typeof attributes.dxuiData === 'object' ) {
			Object.keys( attributes.dxuiData ).forEach( function ( key ) {
				if ( key.indexOf( 'data-' ) === 0 ) {
					extra[ key ] = attributes.dxuiData[ key ];
				}
			} );
		}
		return extra;
	} );

	/*
	 * A block whose saved markup is one element written verbatim (dx-ui/html,
	 * dx-ui/svg) renders that element itself in the canvas, as the block's
	 * own element — not inside a wrapper. Wrapped, it stopped being the flex
	 * or grid item the design lays out (an icon went from block to inline),
	 * and every design selector with a child combinator missed it.
	 *
	 * The markup is parsed once by the browser's own HTML parser, so the root
	 * carries exactly the attributes the front end's does, spelled the way the
	 * parser leaves them (`viewbox` becomes `viewBox` on an <svg>). React
	 * renders the element with the editor's props and the design's classes;
	 * the rest of the root's attributes are set on it directly (see
	 * useDxuiRootAttrs()), because React would warn about or mis-case half of
	 * them. null when the markup is not exactly one element.
	 */
	function dxuiParseRoot( markup ) {
		if ( typeof markup !== 'string' || markup.trim() === '' ) {
			return null;
		}
		const template = document.createElement( 'template' );
		template.innerHTML = markup;
		let root = null;
		const nodes = template.content.childNodes;
		for ( let i = 0; i < nodes.length; i++ ) {
			const node = nodes[ i ];
			if ( node.nodeType === 1 ) {
				if ( root ) {
					return null;
				}
				root = node;
			} else if ( node.nodeType === 3 && node.nodeValue.trim() !== '' ) {
				return null;
			}
		}
		if ( ! root || ! /^[a-z][a-z0-9-]*$/.test( root.localName ) || [ 'script', 'style', 'template' ].indexOf( root.localName ) > -1 ) {
			return null;
		}
		const attrs = [];
		for ( let i = 0; i < root.attributes.length; i++ ) {
			const attr = root.attributes[ i ];
			attrs.push( [ attr.namespaceURI, attr.name, attr.value ] );
		}

		return {
			tag: root.localName,
			cls: root.getAttribute( 'class' ) || '',
			attrs: attrs,
			inner: root.innerHTML,
			isVoid: DXUI_VOID_TAGS.indexOf( root.localName ) > -1,
		};
	}

	// The parsed root's attributes on the rendered element, except the ones
	// React already writes (class) or the editor owns, and aria-hidden: the
	// element is the block's own, which the editor focuses when the block is
	// selected (see dxuiReactProps()) — a selected icon held focus inside an
	// aria-hidden <svg>. Attributes set on an earlier render and gone from the
	// markup now are removed.
	function useDxuiRootAttrs( ref, parsed ) {
		const applied = useRef( [] );
		useLayoutEffect( function () {
			const node = ref.current;
			if ( ! node ) {
				return;
			}
			const now = [];
			( parsed ? parsed.attrs : [] ).forEach( function ( attr ) {
				const name = attr[ 1 ];
				const lower = name.toLowerCase();
				if ( lower === 'id' && ! attr[ 0 ] && attr[ 2 ] !== '' ) {
					// The editor's id stays; the design's rides as
					// data-dxui-id, as a core block's anchor does.
					now.push( 'data-dxui-id' );
					if ( node.getAttribute( 'data-dxui-id' ) !== attr[ 2 ] ) {
						node.setAttribute( 'data-dxui-id', attr[ 2 ] );
					}
					return;
				}
				if ( lower === 'class' || lower === 'aria-hidden' || DXUI_EDITOR_OWNED.indexOf( lower ) > -1 ) {
					return;
				}
				now.push( name );
				if ( node.getAttribute( name ) !== attr[ 2 ] ) {
					if ( attr[ 0 ] ) {
						node.setAttributeNS( attr[ 0 ], name, attr[ 2 ] );
					} else {
						node.setAttribute( name, attr[ 2 ] );
					}
				}
			} );
			applied.current.forEach( function ( name ) {
				if ( now.indexOf( name ) === -1 ) {
					node.removeAttribute( name );
				}
			} );
			applied.current = now;
		} );
	}

	// Design chrome core cannot model. Saves the payload verbatim, so unlike a
	// dynamic block the page still renders if the plugin is deactivated.
	registerBlockType( 'dx-ui/html', {
		apiVersion: 3,
		title: 'DX HTML',
		category: 'dx-blocks',
		icon: 'editor-code',
		supports: { className: false, customClassName: false, html: false, customCSS: false, anchor: false },
		attributes: {
			content: { type: 'string', source: 'html' },
			dxuiInner: { type: 'object', default: {} },
		},
		edit: function ( props ) {
			const content = props.attributes.content || '';
			const parsed = useMemo( function () {
				return dxuiParseRoot( content );
			}, [ content ] );
			const ref = useRef();
			useDxuiRootAttrs( ref, parsed );
			// No outline or padding of its own any more: the 2px padding moved
			// every island's box, and the editor outlines a selected block.
			if ( parsed ) {
				return el( parsed.tag, useBlockProps( {
					ref: ref,
					className: parsed.cls || undefined,
					dangerouslySetInnerHTML: parsed.isVoid ? undefined : { __html: parsed.inner },
				} ) );
			}

			return el( 'div', useBlockProps( { ref: ref, dangerouslySetInnerHTML: { __html: content } } ) );
		},
		save: function ( props ) {
			return el( wp.element.RawHTML, null, props.attributes.content || '' );
		},
	} );

	// dx-ui/text's attributes as `[ HTML name, block attribute ]`, in the
	// order save() writes them. Mirrors Html_To_Blocks::TEXT_ATTR_MAP — the two
	// emitters have to agree character for character or Gutenberg re-runs
	// save(), fails the comparison, and the block opens invalid.
	// Mirrors Html_To_Blocks::TEXT_ATTR_MAP, in its key order — including
	// href/target/rel, which is what lets a designed call-to-action ride this
	// block instead of staying raw HTML. See the comment on that map.
	const DXUI_TEXT_ATTRS = [
		[ 'id', 'anchor' ],
		[ 'class', 'className' ],
		[ 'style', 'dxuiStyle' ],
		[ 'href', 'url' ],
		[ 'target', 'target' ],
		[ 'rel', 'rel' ],
		[ 'role', 'role' ],
		[ 'aria-label', 'ariaLabel' ],
		[ 'aria-labelledby', 'labelledBy' ],
		[ 'aria-hidden', 'ariaHidden' ],
	];

	// Mirrors Html_To_Blocks::text_tag(). The tag is free-form because the
	// design's own element has to travel, and the Element field in the
	// inspector puts whatever a person types straight into it — so both
	// emitters have to reject the same values, or PHP would fall back to
	// `span` while this side wrote `<foo bar>` for the same attributes.
	function dxuiTextTag( a ) {
		const tag = String( a.tagName === undefined || a.tagName === '' ? 'span' : a.tagName ).trim().toLowerCase();

		return /^[a-z][a-z0-9]*$/.test( tag ) ? tag : 'span';
	}

	// The serializer renders attributes in prop insertion order, so inserting
	// them in the source's own order — attrOrder when the design's order was
	// not the canonical one — is what keeps the saved element byte-identical to
	// the markup the ZIP shipped.
	function dxuiTextProps( a ) {
		const named = {};
		DXUI_TEXT_ATTRS.forEach( function ( pair ) {
			named[ pair[ 0 ] ] = pair[ 1 ];
		} );
		const data = a.dxuiData && typeof a.dxuiData === 'object' ? a.dxuiData : {};
		const explicit = Array.isArray( a.attrOrder ) && a.attrOrder.length > 0;
		const order = explicit
			? a.attrOrder
			: DXUI_TEXT_ATTRS.map( function ( pair ) { return pair[ 0 ]; } ).concat( Object.keys( data ) );

		const props = {};
		order.forEach( function ( key ) {
			const name = String( key ).toLowerCase();
			let value;
			if ( named[ name ] ) {
				value = a[ named[ name ] ];
			} else if ( name.indexOf( 'data-' ) === 0 ) {
				value = data[ name ];
			} else {
				return;
			}
			value = value === undefined || value === null ? '' : String( value );
			// An empty value is written when the element's own attribute list
			// names it and skipped otherwise, matching
			// Html_To_Blocks::attrs_from_map(). Without an explicit order there
			// is no way to tell an empty value from an absent attribute.
			if ( value === '' && ! explicit ) {
				return;
			}
			props[ name === 'class' ? 'className' : name ] = value;
		} );

		return props;
	}

	// The design's own text leaves — chips, badges, eyebrows, display numbers,
	// dt/dd pairs. These used to be dx-ui/html because the only alternative
	// was core/paragraph, and that turns the `<span>` into a `<p>`, which the
	// designs style separately (`.rv-testimonial p { font-size: 20px }`).
	registerBlockType( 'dx-ui/text', {
		apiVersion: 3,
		title: 'DX Text',
		icon: 'editor-textcolor',
		category: 'dx-blocks',
		description: 'One inline run of the design’s own copy, in the design’s own element.',
		// No className/customClassName, so nothing is appended to the design's
		// own class list; no align/spacing/typography, so the inspector cannot
		// inject theme.json styles into a design being reproduced exactly;
		// no splitting, or Enter would shred a converted run in two.
		supports: {
			className: false,
			customClassName: false,
			customCSS: false,
			html: false,
			anchor: false,
			splitting: false,
		},
		attributes: {
			// Free-form as core/group's already is, so dt and dd ride the same
			// block rather than needing one each.
			tagName: { type: 'string', default: 'span' },
			className: { type: 'string', default: '' },
			dxuiStyle: { type: 'string', default: '' },
			dxuiCss: { type: 'string', default: '' },
			dxuiInner: { type: 'object', default: {} },
			dxuiData: { type: 'object', default: {} },
			anchor: { type: 'string', default: '' },
			// A link's own attributes — see DXUI_TEXT_ATTRS.
			url: { type: 'string', default: '' },
			target: { type: 'string', default: '' },
			rel: { type: 'string', default: '' },
			role: { type: 'string', default: '' },
			ariaLabel: { type: 'string', default: '' },
			labelledBy: { type: 'string', default: '' },
			ariaHidden: { type: 'string', default: '' },
			attrOrder: { type: 'array', default: [] },
			// `*`, not the tag name: the selector has to match whatever tagName
			// holds, and hpq reads innerHTML off the first element it finds.
			// With no selector it would hand back the whole `<span>…</span>`.
			content: { type: 'string', source: 'html', selector: '*', default: '' },
			// What the editor shows in an empty run (a copy of a page leaves its
			// own lines for a person with the original's words here); never saved.
			placeholder: { type: 'string', default: '' },
		},
		edit: function ( props ) {
			const a = props.attributes;
			const tag = dxuiTextTag( a );
			return el( wp.element.Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: 'Text' },
						el( TextControl, {
							label: 'Element',
							help: 'The tag the design used. Changing it changes the box.',
							value: a.tagName || 'span',
							onChange: function ( value ) { props.setAttributes( { tagName: value } ); },
						} ),
						el( TextControl, {
							label: 'Label',
							value: a.ariaLabel || '',
							onChange: function ( value ) { props.setAttributes( { ariaLabel: value } ); },
						} )
					)
				),
				// The element save() writes — its attributes, the design's class
				// and the dxs- class for its CSS — as the editable element.
				el( RichText, Object.assign( {}, useBlockProps( Object.assign( dxuiReactProps( dxuiTextProps( a ), tag ), {
					className: dxuiJoin( a.className, dxuiCanvasClass( a, 'dx-ui/text' ) ) || undefined,
				} ) ), {
					tagName: tag,
					value: a.content || '',
					// The design's own leaves carry no formatting, and letting
					// one in would put markup the design never had inside a
					// chip or a display number.
					allowedFormats: [],
					// `<span class="rv-obj-num"> 01 </span>` — the boundary
					// space is layout. Without this, rich text trims it at the
					// root and 92 of these leaves lose a space on first edit.
					preserveWhiteSpace: true,
					placeholder: a.placeholder || 'Text',
					onChange: function ( value ) { props.setAttributes( { content: value } ); },
				} ) )
			);
		},
		save: function ( props ) {
			const a = props.attributes;
			return el( RichText.Content, Object.assign( {}, dxuiHoist( dxuiTextProps( a ), a ), {
				tagName: dxuiTextTag( a ),
				value: a.content || '',
			} ) );
		},
	} );

	// Mirrors Html_To_Blocks::BOX_ATTR_MAP, in its key order: the canonical
	// write order when the element carried no attrOrder of its own.
	const DXUI_BOX_ATTRS = [
		[ 'id', 'anchor' ],
		[ 'class', 'className' ],
		[ 'style', 'dxuiStyle' ],
		[ 'type', 'elType' ],
		[ 'href', 'url' ],
		[ 'src', 'src' ],
		[ 'alt', 'alt' ],
		[ 'width', 'width' ],
		[ 'height', 'height' ],
		[ 'loading', 'loading' ],
		[ 'target', 'target' ],
		[ 'rel', 'rel' ],
		[ 'title', 'title' ],
		[ 'for', 'htmlFor' ],
		[ 'name', 'name' ],
		[ 'value', 'value' ],
		[ 'placeholder', 'placeholder' ],
		[ 'tabindex', 'tabIndex' ],
		[ 'role', 'role' ],
		[ 'dir', 'dir' ],
		[ 'hidden', 'hidden' ],
		[ 'disabled', 'disabled' ],
		[ 'defaultvalue', 'defaultValue' ],
		[ 'to', 'to' ],
		[ 'stroke-width', 'strokeWidth' ],
		[ 'aria-label', 'ariaLabel' ],
		[ 'aria-labelledby', 'labelledBy' ],
		[ 'aria-describedby', 'describedBy' ],
		[ 'aria-hidden', 'ariaHidden' ],
		[ 'aria-expanded', 'ariaExpanded' ],
		[ 'aria-controls', 'ariaControls' ],
		[ 'aria-selected', 'ariaSelected' ],
		[ 'aria-current', 'ariaCurrent' ],
		[ 'aria-haspopup', 'ariaHasPopup' ],
		[ 'aria-orientation', 'ariaOrientation' ],
		// Appended, like their PHP counterparts, so canonical order does not
		// move for blocks already written. See Html_To_Blocks::BOX_ATTR_MAP.
		[ 'referrerpolicy', 'referrerPolicy' ],
		[ 'open', 'open' ],
	];

	// Void elements, matching Html_To_Blocks::VOID_TAGS. The serializer writes
	// these as `<tag …/>` and they take no children.
	const DXUI_VOID_TAGS = [ 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' ];

	// Mirrors Html_To_Blocks::box_tag(). Same rule as dxuiTextTag(), defaulting
	// to `div` because a box inserted by hand is a container.
	function dxuiBoxTag( a ) {
		const tag = String( a.tagName === undefined || a.tagName === '' ? 'div' : a.tagName ).trim().toLowerCase();

		return /^[a-z][a-z0-9]*$/.test( tag ) ? tag : 'div';
	}

	// Mirrors Html_To_Blocks::attrs_from_map() over BOX_ATTR_MAP. An empty
	// value is written when the element's own attrOrder names it — `alt=""` on
	// a decorative image — and skipped when there is no explicit order, where
	// an empty value cannot be told apart from an absent attribute.
	function dxuiBoxProps( a ) {
		const named = {};
		DXUI_BOX_ATTRS.forEach( function ( pair ) {
			named[ pair[ 0 ] ] = pair[ 1 ];
		} );
		const data = a.dxuiData && typeof a.dxuiData === 'object' ? a.dxuiData : {};
		const explicit = Array.isArray( a.attrOrder ) && a.attrOrder.length > 0;
		const order = explicit
			? a.attrOrder
			: DXUI_BOX_ATTRS.map( function ( pair ) { return pair[ 0 ]; } ).concat( Object.keys( data ) );

		const props = {};
		order.forEach( function ( key ) {
			const name = String( key ).toLowerCase();
			let value;
			if ( named[ name ] ) {
				value = a[ named[ name ] ];
			} else if ( name.indexOf( 'data-' ) === 0 ) {
				value = data[ name ];
			} else {
				return;
			}
			value = value === undefined || value === null ? '' : String( value );
			if ( value === '' && ! explicit ) {
				return;
			}
			props[ name === 'class' ? 'className' : name ] = value;
		} );

		return props;
	}

	/*
	 * dx-ui/box — the design's own element, with its children as blocks.
	 *
	 * save() writes the element through dxuiBoxProps(), the mirror of
	 * Html_To_Blocks::box_attrs(), so one attribute order and one escaping
	 * contract cover both emitters. There is no `content` attribute: the
	 * children are their own blocks, so nothing here goes through the hpq
	 * round trip that governs dx-ui/text.
	 */
	wp.blocks.registerBlockType( 'dx-ui/box', {
		apiVersion: 3,
		title: 'DX Box',
		category: 'dx-blocks',
		icon: 'editor-code',
		attributes: {
			tagName: { type: 'string', default: 'div' },
			className: { type: 'string', default: '' },
			dxuiStyle: { type: 'string', default: '' },
			dxuiCss: { type: 'string', default: '' },
			dxuiData: { type: 'object', default: {} },
			anchor: { type: 'string', default: '' },
			elType: { type: 'string', default: '' },
			url: { type: 'string', default: '' },
			src: { type: 'string', default: '' },
			alt: { type: 'string', default: '' },
			width: { type: 'string', default: '' },
			height: { type: 'string', default: '' },
			loading: { type: 'string', default: '' },
			target: { type: 'string', default: '' },
			rel: { type: 'string', default: '' },
			title: { type: 'string', default: '' },
			htmlFor: { type: 'string', default: '' },
			name: { type: 'string', default: '' },
			value: { type: 'string', default: '' },
			placeholder: { type: 'string', default: '' },
			tabIndex: { type: 'string', default: '' },
			role: { type: 'string', default: '' },
			dir: { type: 'string', default: '' },
			hidden: { type: 'string', default: '' },
			disabled: { type: 'string', default: '' },
			defaultValue: { type: 'string', default: '' },
			to: { type: 'string', default: '' },
			strokeWidth: { type: 'string', default: '' },
			ariaLabel: { type: 'string', default: '' },
			labelledBy: { type: 'string', default: '' },
			describedBy: { type: 'string', default: '' },
			ariaHidden: { type: 'string', default: '' },
			ariaExpanded: { type: 'string', default: '' },
			ariaControls: { type: 'string', default: '' },
			ariaSelected: { type: 'string', default: '' },
			ariaCurrent: { type: 'string', default: '' },
			ariaHasPopup: { type: 'string', default: '' },
			ariaOrientation: { type: 'string', default: '' },
			// See DXUI_BOX_ATTRS: an embedded map and an open accordion.
			referrerPolicy: { type: 'string', default: '' },
			open: { type: 'string', default: '' },
			attrOrder: { type: 'array', default: [] },
		},
		supports: {
			className: false,
			customClassName: false,
			html: false,
			// As the server registers it (Design_Blocks). Absent here, the
			// editor defaults it to on and offered an "Additional CSS" panel
			// whose CSS the server never prints (block-parity.cjs).
			customCSS: false,
			anchor: false,
		},
		edit: function ( props ) {
			const a = props.attributes;
			const tag = dxuiBoxTag( a );
			const isVoid = DXUI_VOID_TAGS.indexOf( tag ) > -1;
			// Every attribute save() writes, through the same builder — an
			// <img> box without its src was a blank box, and a <details> box
			// without `open` a closed one — plus the design's class and the
			// dxs- class for its CSS. A void element has no editable children,
			// so it keeps a real `disabled`.
			const blockProps = useBlockProps( Object.assign( dxuiReactProps( dxuiBoxProps( a ), tag, { inert: isVoid } ), {
				className: dxuiJoin( a.className, dxuiCanvasClass( a, 'dx-ui/box' ) ) || undefined,
			} ) );
			const inner = useInnerBlocksProps( blockProps, {} );
			const fields = [
				el( TextControl, {
					key: 'tag',
					label: 'Element',
					help: 'The tag the design used. Changing it changes the box.',
					value: a.tagName || 'div',
					onChange: function ( value ) { props.setAttributes( { tagName: value } ); },
				} ),
				el( TextControl, {
					key: 'label',
					label: 'Label',
					value: a.ariaLabel || '',
					onChange: function ( value ) { props.setAttributes( { ariaLabel: value } ); },
				} ),
			];
			if ( a.url ) {
				fields.push( el( TextControl, {
					key: 'url',
					label: 'Link',
					value: a.url,
					onChange: function ( value ) { props.setAttributes( { url: value } ); },
				} ) );
			}
			if ( a.src ) {
				fields.push( el( TextControl, {
					key: 'src',
					label: 'Image source',
					value: a.src,
					onChange: function ( value ) { props.setAttributes( { src: value } ); },
				} ) );
				fields.push( el( TextControl, {
					key: 'alt',
					label: 'Alt text',
					value: a.alt || '',
					onChange: function ( value ) { props.setAttributes( { alt: value } ); },
				} ) );
			}
			return el( wp.element.Fragment, null,
				el( InspectorControls, null, el( PanelBody, { title: 'Box' }, fields ) ),
				// A void element takes no children, in the editor as in save().
				isVoid ? el( tag, blockProps ) : el( tag, inner )
			);
		},
		save: function ( props ) {
			const a = props.attributes;
			const tag = dxuiBoxTag( a );
			if ( DXUI_VOID_TAGS.indexOf( tag ) > -1 ) {
				return el( tag, dxuiHoist( dxuiBoxProps( a ), a ) );
			}

			return el( tag, dxuiHoist( dxuiBoxProps( a ), a ), el( InnerBlocks.Content ) );
		},
	} );

	/*
	 * dx-ui/svg — the design's own inline SVG.
	 *
	 * The root attributes are an ordered list of pairs, written as props in
	 * that order; the serializer's getNormalAttributeName() restores the SVG
	 * spelling (`viewbox` becomes `viewBox`), and PHP's Svg_Attrs::name() is
	 * generated from the same tables so the two agree. The children are one
	 * string, written verbatim by both emitters through
	 * dangerouslySetInnerHTML, which the serializer emits as content.
	 */
	const DXUI_SVG_EDITABLE = [
		[ 'width', 'Width' ],
		[ 'height', 'Height' ],
		[ 'fill', 'Fill' ],
		[ 'stroke', 'Stroke' ],
		[ 'stroke-width', 'Stroke width' ],
		[ 'class', 'Class' ],
	];

	function dxuiSvgPairs( a ) {
		return Array.isArray( a.svgAttrs ) ? a.svgAttrs.filter( function ( pair ) {
			return Array.isArray( pair ) && pair.length > 1;
		} ) : [];
	}

	function dxuiSvgProps( a ) {
		const props = {};
		dxuiSvgPairs( a ).forEach( function ( pair ) {
			props[ String( pair[ 0 ] ) ] = String( pair[ 1 ] );
		} );

		return props;
	}

	function dxuiSvgValue( a, name ) {
		const hit = dxuiSvgPairs( a ).find( function ( pair ) { return String( pair[ 0 ] ) === name; } );

		return hit ? String( hit[ 1 ] ) : '';
	}

	// Editing a root attribute keeps the source order: an existing pair is
	// replaced in place, a new one is appended, and clearing a value removes
	// the pair so the attribute disappears rather than becoming empty.
	function dxuiSvgSet( a, name, value ) {
		const pairs = dxuiSvgPairs( a ).map( function ( pair ) { return [ String( pair[ 0 ] ), String( pair[ 1 ] ) ]; } );
		const at = pairs.findIndex( function ( pair ) { return pair[ 0 ] === name; } );
		if ( value === '' ) {
			return at > -1 ? pairs.slice( 0, at ).concat( pairs.slice( at + 1 ) ) : pairs;
		}
		if ( at > -1 ) {
			pairs[ at ] = [ name, value ];

			return pairs;
		}

		return pairs.concat( [ [ name, value ] ] );
	}

	registerBlockType( 'dx-ui/svg', {
		apiVersion: 3,
		title: 'DX Icon',
		category: 'dx-blocks',
		icon: 'star-filled',
		description: 'The design’s own inline SVG, with its size and colours editable.',
		supports: {
			className: false,
			customClassName: false,
			customCSS: false,
			html: false,
			anchor: false,
		},
		attributes: {
			svgAttrs: { type: 'array', default: [] },
			svgInner: { type: 'string', default: '' },
			dxuiInner: { type: 'object', default: {} },
		},
		edit: function ( props ) {
			const a = props.attributes;
			const fields = DXUI_SVG_EDITABLE.map( function ( pair ) {
				return el( TextControl, {
					key: pair[ 0 ],
					label: pair[ 1 ],
					value: dxuiSvgValue( a, pair[ 0 ] ),
					onChange: function ( value ) {
						props.setAttributes( { svgAttrs: dxuiSvgSet( a, pair[ 0 ], value ) } );
					},
				} );
			} );
			fields.push( el( TextareaControl, {
				key: 'inner',
				label: 'Shapes',
				help: 'The SVG’s own children. Edit with care: this is written to the page verbatim.',
				value: a.svgInner || '',
				onChange: function ( value ) { props.setAttributes( { svgInner: value } ); },
			} ) );

			// The <svg> itself is the block's element (see dxuiParseRoot()):
			// inside two wrapper <div>s it was an inline box instead of the
			// flex or grid item the design lays out.
			const markup = dxuiSvgMarkup( a );
			const parsed = useMemo( function () {
				return dxuiParseRoot( markup );
			}, [ markup ] );
			const ref = useRef();
			useDxuiRootAttrs( ref, parsed );

			return el( wp.element.Fragment, null,
				el( InspectorControls, null, el( PanelBody, { title: 'Icon' }, fields ) ),
				el( 'svg', useBlockProps( {
					ref: ref,
					className: ( parsed && parsed.cls ) || undefined,
					dangerouslySetInnerHTML: { __html: a.svgInner || '' },
				} ) )
			);
		},
		save: function ( props ) {
			const a = props.attributes;

			return el( 'svg', Object.assign( {}, dxuiSvgProps( a ), {
				dangerouslySetInnerHTML: { __html: a.svgInner || '' },
			} ) );
		},
	} );

	// The canvas preview only: RawHTML needs one string, and the saved markup
	// is built by save() above rather than from this.
	function dxuiSvgMarkup( a ) {
		let out = '<svg';
		dxuiSvgPairs( a ).forEach( function ( pair ) {
			out += ' ' + String( pair[ 0 ] ) + '="' + String( pair[ 1 ] ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ) + '"';
		} );

		return out + '>' + ( a.svgInner || '' ) + '</svg>';
	}

	// dx-ui/link's attributes as `[ HTML name, block attribute ]`, in the one
	// order both save()s write them. Mirrors Html_To_Blocks::LINK_ATTR_MAP;
	// `class` and `href` are written by the tag opener before this list runs,
	// so they are not in it.
	//
	// aria-expanded and aria-controls are the two that mattered: the PHP
	// emitter already WROTE them while the block declared neither, so Gutenberg
	// dropped them reading the block back and save() regenerated an element
	// without them — an invalid block on first open. title, target and rel were
	// never carried at all.
	const DXUI_LINK_ATTRS = [
		[ 'target', 'target' ],
		[ 'rel', 'rel' ],
		[ 'title', 'title' ],
		[ 'style', 'style' ],
		[ 'role', 'role' ],
		[ 'aria-label', 'ariaLabel' ],
		[ 'aria-expanded', 'ariaExpanded' ],
		[ 'aria-controls', 'ariaControls' ],
		[ 'data-leak', 'dataLeak' ],
		[ 'data-fix', 'dataFix' ],
	];

	// The element's attributes as save() writes them — and, through
	// dxuiReactProps(), as edit() renders them.
	function dxuiLinkProps( a ) {
		const tag = a.tagName === 'button' ? 'button' : 'a';
		const className = [ 'wp-block-dx-ui-link', a.className ].filter( Boolean ).join( ' ' );
		// The serializer writes attributes in prop insertion order, so this
		// order is what keeps save() byte-identical to what the compiler
		// stored. It is Html_To_Blocks::link_open_tag()'s order.
		const extra = { className: className };
		if ( tag === 'a' ) {
			extra.href = a.url || '';
		} else if ( a.elType ) {
			// Only when the design had a type — inventing type="button"
			// made Continue-style CTAs fail PHP link_reproduces().
			extra.type = a.elType;
		}
		DXUI_LINK_ATTRS.forEach( function ( pair ) {
			if ( a[ pair[ 1 ] ] ) {
				extra[ pair[ 0 ] ] = a[ pair[ 1 ] ];
			}
		} );
		if ( a.disabled ) {
			extra.disabled = true;
		}
		const data = a.dxuiData && typeof a.dxuiData === 'object' ? a.dxuiData : {};
		Object.keys( data ).sort().forEach( function ( key ) {
			const name = String( key ).toLowerCase();
			if ( name.indexOf( 'data-' ) === 0 && data[ key ] !== undefined && data[ key ] !== null ) {
				extra[ name ] = String( data[ key ] );
			}
		} );
		if ( ( a.className || '' ).indexOf( 'is-on' ) !== -1 ) {
			extra[ 'aria-selected' ] = 'true';
		}
		// Its CSS as a class, not a style attribute (Style_Hoister).
		if ( a.dxuiCss ) {
			delete extra.style;
			extra.className = extra.className + ' ' + dxuiCssClass( a.dxuiCss );
		}

		return extra;
	}

	/*
	 * dx-ui/link `text` and dx-ui/details `question` are plain text: the
	 * compiler stores the element's textContent, and save() writes it as a
	 * text child, which escapes it. RichText works in HTML, so it is given
	 * the text escaped, and what it hands back is turned into text again.
	 * Stored as the HTML it hands back, an edited "Fire & Water" became
	 * "Fire &amp; Water", save() escaped that a second time, and the page
	 * showed "&amp;" (a line break, a literal "<br>"). Only & and < are
	 * escaped, as RichText's own HTML does, so the value it gets back after
	 * an edit is the one it gave and the caret stays put.
	 */
	function dxuiTextToHtml( text ) {
		return String( text || '' ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' );
	}

	function dxuiHtmlToText( html ) {
		return wp.richText.getTextContent( wp.richText.create( { html: html || '' } ) );
	}

	registerBlockType( 'dx-ui/link', {
		apiVersion: 3,
		title: 'DX Link',
		icon: 'admin-links',
		category: 'dx-blocks',
		description: 'Design link or button that keeps ZIP class names.',
		attributes: {
			url: { type: 'string', default: '' },
			text: { type: 'string', default: '' },
			className: { type: 'string', default: '' },
			tagName: { type: 'string', default: 'a' },
			suffix: { type: 'string', default: '' },
			suffixClass: { type: 'string', default: '' },
			// The design's own <span> around a CTA label. Without it the label
			// went out as bare text and that element was deleted from the page.
			labelWrap: { type: 'boolean', default: false },
			// Records the ABSENCE of the space between label and glyph: the
			// emitter has always written one, and a false attribute is dropped
			// from the block comment, so "spaced" is the default that survives.
			suffixTight: { type: 'boolean', default: false },
			ariaLabel: { type: 'string', default: '' },
			role: { type: 'string', default: '' },
			title: { type: 'string', default: '' },
			target: { type: 'string', default: '' },
			rel: { type: 'string', default: '' },
			disabled: { type: 'boolean', default: false },
			ariaExpanded: { type: 'string', default: '' },
			ariaControls: { type: 'string', default: '' },
			dataLeak: { type: 'string', default: '' },
			dataFix: { type: 'string', default: '' },
			imageUrl: { type: 'string', default: '' },
			imageAlt: { type: 'string', default: '' },
			style: { type: 'string', default: '' },
			dxuiCss: { type: 'string', default: '' },
			elType: { type: 'string', default: '' },
			dxuiData: { type: 'object', default: {} },
			hasInner: { type: 'boolean', default: false },
		},
		supports: { className: true, html: false, anchor: true },
		edit: function ( props ) {
			const a = props.attributes;
			const tag = a.tagName === 'button' ? 'button' : 'a';
			/*
			 * The element save() writes, from the same builder: the real <a>
			 * or <button> with its href, classes, dxs- class and data
			 * attributes. It was a <div class="dxui-link-editor"> with a
			 * <span> in it and none of the design's CSS — 339 declarations on
			 * Semper Dry's 49 links, so every nav item, button and the skip
			 * link rendered unstyled. Clicks do not navigate (dxuiGuardDoc()).
			 * A label that is the element's only content is the editable
			 * element itself, as dx-ui/text's is, so no <span> the design
			 * never had sits inside it for its `a span` rules to catch.
			 */
			const saved = dxuiLinkProps( a );
			const own = dxuiCanvasClass( a, 'dx-ui/link' );
			const base = dxuiReactProps( saved, tag );
			base.className = dxuiJoin( 'wp-block-dx-ui-link', a.className, own );
			const blockProps = useBlockProps( base );
			const inner = useInnerBlocksProps( blockProps, { allowedBlocks: [ 'core/paragraph', 'core/heading', 'core/group', 'core/html', 'dx-ui/link' ] } );
			const label = function ( extra ) {
				return el( RichText, Object.assign( {}, extra, {
					tagName: extra.tagName || 'span',
					value: dxuiTextToHtml( a.text ),
					placeholder: 'Link text',
					allowedFormats: [],
					disableLineBreaks: true,
					onChange: function ( value ) { props.setAttributes( { text: dxuiHtmlToText( value ) } ); },
				} ) );
			};
			let body;
			if ( a.hasInner ) {
				body = el( tag, inner );
			} else if ( a.imageUrl ) {
				body = el( tag, blockProps, el( 'img', { src: a.imageUrl, alt: a.imageAlt || '' } ) );
			} else if ( ! a.labelWrap && ! a.suffix ) {
				body = label( Object.assign( {}, blockProps, { tagName: tag } ) );
			} else {
				const children = [ label( { key: 'label' } ) ];
				if ( a.suffix ) {
					if ( ! a.suffixTight ) {
						children.push( ' ' );
					}
					children.push( el( 'span', { key: 'suffix', className: a.suffixClass || undefined, 'aria-hidden': 'true' }, a.suffix ) );
				}
				body = el( tag, blockProps, children );
			}
			return el( wp.element.Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: 'Link' },
						el( SelectControl, {
							label: 'Tag',
							value: a.tagName || 'a',
							options: [
								{ label: 'Link (a)', value: 'a' },
								{ label: 'Button', value: 'button' },
							],
							onChange: function ( value ) { props.setAttributes( { tagName: value } ); },
						} ),
						el( TextControl, {
							label: 'URL',
							value: a.url || '',
							onChange: function ( value ) { props.setAttributes( { url: value } ); },
						} ),
						el( TextControl, {
							label: 'Label',
							value: a.ariaLabel || '',
							onChange: function ( value ) { props.setAttributes( { ariaLabel: value } ); },
						} ),
						el( TextControl, {
							label: 'Title',
							value: a.title || '',
							onChange: function ( value ) { props.setAttributes( { title: value } ); },
						} ),
						el( TextControl, {
							label: 'Target',
							help: '_blank opens in a new tab.',
							value: a.target || '',
							onChange: function ( value ) { props.setAttributes( { target: value } ); },
						} ),
						el( TextControl, {
							label: 'Rel',
							value: a.rel || '',
							onChange: function ( value ) { props.setAttributes( { rel: value } ); },
						} ),
						el( TextControl, {
							label: 'Suffix',
							value: a.suffix || '',
							onChange: function ( value ) { props.setAttributes( { suffix: value } ); },
						} ),
						el( TextControl, {
							label: 'Image alt',
							value: a.imageAlt || '',
							onChange: function ( value ) { props.setAttributes( { imageAlt: value } ); },
						} ),
						el( MediaUpload, {
							onSelect: function ( media ) {
								props.setAttributes( { imageUrl: media.url || '', imageAlt: media.alt || a.imageAlt || '' } );
							},
							allowedTypes: [ 'image' ],
							render: function ( obj ) {
								return el( Button, { onClick: obj.open, variant: 'secondary' }, a.imageUrl ? 'Replace image' : 'Set image' );
							},
						} )
					)
				),
				body
			);
		},
		save: function ( props ) {
			const a = props.attributes;
			const tag = a.tagName === 'button' ? 'button' : 'a';
			const extra = dxuiLinkProps( a );
			if ( a.hasInner ) {
				return el( tag, extra, el( InnerBlocks.Content ) );
			}
			let children;
			if ( a.imageUrl ) {
				children = el( 'img', { src: a.imageUrl, alt: a.imageAlt || '' } );
			} else {
				children = [ a.labelWrap ? el( 'span', null, a.text || '' ) : ( a.text || '' ) ];
				if ( a.suffix ) {
					// class before aria-hidden, because that is the order the
					// PHP emitter writes and the order the stored markup has.
					const spanProps = {};
					if ( a.suffixClass ) {
						spanProps.className = a.suffixClass;
					}
					spanProps[ 'aria-hidden' ] = 'true';
					if ( ! a.suffixTight ) {
						children.push( ' ' );
					}
					children.push( el( 'span', spanProps, a.suffix ) );
				}
			}
			return el( tag, extra, children );
		},
	} );

	// dx-ui/image's attributes as `[ HTML name, block attribute ]`, in the
	// order save() writes them. Mirrors Html_To_Blocks::DESIGN_IMAGE_ATTRS.
	const DXUI_IMAGE_ATTRS = [
		[ 'src', 'url' ],
		[ 'alt', 'alt' ],
		[ 'class', 'className' ],
		[ 'style', 'dxuiStyle' ],
		[ 'width', 'width' ],
		[ 'height', 'height' ],
		[ 'loading', 'loading' ],
		[ 'decoding', 'decoding' ],
		[ 'srcset', 'srcset' ],
		[ 'sizes', 'sizes' ],
	];

	// The serializer lower-cases an unknown prop name and maps className to
	// class, so `srcSet` is how you get `srcset` out of it.
	const DXUI_IMAGE_PROP = { class: 'className', srcset: 'srcSet' };

	function dxuiImageProps( a, withStyle ) {
		const props = {};
		DXUI_IMAGE_ATTRS.forEach( function ( pair ) {
			// React DOM rejects a string `style` prop — edit() gets a parsed object.
			if ( pair[ 0 ] === 'style' && ! withStyle ) {
				return;
			}
			const value = a[ pair[ 1 ] ];
			if ( ! value ) {
				return;
			}
			props[ DXUI_IMAGE_PROP[ pair[ 0 ] ] || pair[ 0 ] ] = value;
		} );
		return props;
	}

	// A picture with no wrapper. core/image wraps its <img> in a
	// <figure class="wp-block-image">, and that figure is what a flex or grid
	// parent lays out instead of the image — so most of these designs' images
	// had to stay raw HTML. core/image also has no attribute for loading,
	// decoding, srcset or sizes, and its width/height are CSS lengths rather
	// than the presentational attributes the design shipped.
	registerBlockType( 'dx-ui/image', {
		apiVersion: 3,
		title: 'DX Image',
		icon: 'format-image',
		category: 'dx-blocks',
		description: 'The design’s own <img>, with no figure around it.',
		// Nothing may append a class, an id or a theme.json style: the saved
		// element carries exactly what the design put on it.
		supports: {
			className: false,
			customClassName: false,
			customCSS: false,
			html: false,
			anchor: false,
		},
		attributes: {
			url: { type: 'string', default: '' },
			alt: { type: 'string', default: '' },
			className: { type: 'string', default: '' },
			dxuiStyle: { type: 'string', default: '' },
			dxuiCss: { type: 'string', default: '' },
			// Strings: these are the HTML attributes as the design wrote them.
			width: { type: 'string', default: '' },
			height: { type: 'string', default: '' },
			loading: { type: 'string', default: '' },
			decoding: { type: 'string', default: '' },
			srcset: { type: 'string', default: '' },
			sizes: { type: 'string', default: '' },
		},
		edit: function ( props ) {
			const a = props.attributes;
			return el( wp.element.Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: 'Image' },
						el( TextControl, {
							label: 'Alt text',
							help: 'Empty means decorative.',
							value: a.alt || '',
							onChange: function ( value ) { props.setAttributes( { alt: value } ); },
						} ),
						el( TextControl, {
							label: 'Width',
							value: a.width || '',
							onChange: function ( value ) { props.setAttributes( { width: value } ); },
						} ),
						el( TextControl, {
							label: 'Height',
							value: a.height || '',
							onChange: function ( value ) { props.setAttributes( { height: value } ); },
						} ),
						el( MediaUpload, {
							onSelect: function ( media ) {
								props.setAttributes( { url: media.url || '', alt: media.alt || a.alt || '' } );
							},
							allowedTypes: [ 'image' ],
							render: function ( obj ) {
								return el( Button, { onClick: obj.open, variant: 'secondary' }, a.url ? 'Replace image' : 'Set image' );
							},
						} )
					)
				),
				// blockProps last: its className already carries the design's
				// class merged into the editor's own, and overwriting it would
				// take the block's id and selection classes with it.
				el( 'img', Object.assign(
					{},
					dxuiImageProps( a, false ),
					useBlockProps( {
						className: dxuiJoin( a.className, dxuiCanvasClass( a, 'dx-ui/image' ) ) || undefined,
					} )
				) )
			);
		},
		save: function ( props ) {
			return el( 'img', dxuiHoist( dxuiImageProps( props.attributes, true ), props.attributes ) );
		},
	} );

	registerBlockType( 'dx-ui/details', {
		apiVersion: 3,
		title: 'DX Details',
		icon: 'editor-ul',
		category: 'dx-blocks',
		description: 'FAQ details/summary that stays editable.',
		attributes: {
			num: { type: 'string', default: '' },
			question: { type: 'string', default: '' },
			className: { type: 'string', default: '' },
		},
		supports: { className: true, html: false },
		edit: function ( props ) {
			const blockProps = useBlockProps( { className: props.attributes.className } );
			const inner = useInnerBlocksProps(
				{},
				{ allowedBlocks: [ 'core/paragraph', 'core/list', 'core/heading', 'dx-ui/link' ] }
			);
			return el( wp.element.Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: 'FAQ item' },
						el( TextControl, {
							label: 'Number',
							value: props.attributes.num || '',
							onChange: function ( value ) { props.setAttributes( { num: value } ); },
						} )
					)
				),
				el( 'details', Object.assign( {}, blockProps, { open: true } ),
					el( 'summary', { onClick: function ( event ) { event.preventDefault(); } },
						el( 'span', { className: 'rv-faq-num' }, props.attributes.num || '' ),
						el( RichText, {
							tagName: 'span',
							className: 'rv-faq-q',
							value: dxuiTextToHtml( props.attributes.question ),
							placeholder: 'Question',
							allowedFormats: [],
							disableLineBreaks: true,
							onChange: function ( value ) { props.setAttributes( { question: dxuiHtmlToText( value ) } ); },
						} ),
						el( 'span', { className: 'rv-faq-plus', 'aria-hidden': 'true' }, '+' )
					),
					el( 'div', inner )
				)
			);
		},
		save: function ( props ) {
			const a = props.attributes;
			const blockProps = useBlockProps.save();
			return el( 'details', blockProps,
				el( 'summary', null,
					el( 'span', { className: 'rv-faq-num' }, a.num || '' ),
					el( 'span', { className: 'rv-faq-q' }, a.question || '' ),
					el( 'span', { className: 'rv-faq-plus', 'aria-hidden': 'true' }, '+' )
				),
				el( InnerBlocks.Content )
			);
		},
	} );

	registerBlockType( 'dx-ui/countup', {
		apiVersion: 3,
		title: 'DX Count Up',
		icon: 'chart-bar',
		category: 'dx-blocks',
		attributes: {
			value: { type: 'string', default: '' },
			suffix: { type: 'string', default: '' },
			className: { type: 'string', default: 'rv-num' },
			// The class on the screen-reader copy nested inside the number.
			// Mirrors Design_Blocks::register() and
			// Html_To_Blocks::countup_html() — both save()s write it, or the
			// block opens invalid the moment Gutenberg re-runs save().
			srClass: { type: 'string', default: '' },
		},
		supports: { className: true, html: false },
		edit: function ( props ) {
			const blockProps = useBlockProps();
			return el( wp.element.Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: 'Count up' },
						el( TextControl, {
							label: 'Value',
							value: props.attributes.value || '',
							onChange: function ( value ) { props.setAttributes( { value: value } ); },
						} ),
						el( TextControl, {
							label: 'Suffix',
							value: props.attributes.suffix || '',
							onChange: function ( value ) { props.setAttributes( { suffix: value } ); },
						} )
					)
				),
				el( 'span', blockProps, ( props.attributes.value || '' ) + ( props.attributes.suffix || '' ) )
			);
		},
		save: function ( props ) {
			const a = props.attributes;
			const blockProps = useBlockProps.save( {
				'data-countup': a.value || '',
				'data-suffix': a.suffix || '',
			} );
			const shown = ( a.value || '' ) + ( a.suffix || '' );
			// The screen-reader copy, when the design wrote one. The runtime
			// animates the visible figure from 0, so this is the only static
			// statement of the final value — see Design_Blocks::register().
			const children = a.srClass
				? [ shown, el( 'span', { className: a.srClass, key: 'sr' }, shown ) ]
				: shown;
			return el( 'span', blockProps, children );
		},
	} );

	registerBlockType( 'dx-ui/slider', {
		apiVersion: 3,
		title: 'DX Slider',
		icon: 'images-alt2',
		category: 'dx-blocks',
		description: 'Pixel-perfect slider whose slides stay editable in Gutenberg.',
		supports: { className: true, html: false, align: [ 'wide', 'full' ] },
		edit: function () {
			const blockProps = useBlockProps( { className: 'dxui-slider', 'data-dxui-slider': '1' } );
			const inner = useInnerBlocksProps(
				{ className: 'dxui-slider-track' },
				{
					allowedBlocks: [ 'core/cover', 'core/image', 'core/group', 'core/media-text' ],
					orientation: 'horizontal',
				}
			);
			return el( 'div', blockProps, el( 'div', inner ) );
		},
		save: function () {
			const blockProps = useBlockProps.save( { className: 'dxui-slider', 'data-dxui-slider': '1' } );
			const inner = useInnerBlocksProps.save( { className: 'dxui-slider-track' } );
			return el( 'div', blockProps, el( 'div', inner ) );
		},
	} );

	registerBlockType( 'dx-ui/form', {
		apiVersion: 3,
		title: 'DX Form',
		icon: 'email',
		category: 'dx-blocks',
		description: 'Working form that stores entries in WordPress.',
		attributes: {
			fields: { type: 'array', default: [] },
			submitLabel: { type: 'string', default: 'Send' },
			successMessage: { type: 'string', default: 'Thank you.' },
			className: { type: 'string', default: '' },
		},
		supports: { className: true, html: false },
		edit: function ( props ) {
			const blockProps = useBlockProps( { className: 'dxui-form-editor' } );
			const json = JSON.stringify( props.attributes.fields || [], null, 2 );
			return el( wp.element.Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: 'Form' },
						el( TextControl, {
							label: 'Submit label',
							value: props.attributes.submitLabel,
							onChange: function ( value ) { props.setAttributes( { submitLabel: value } ); },
						} ),
						el( TextControl, {
							label: 'Success message',
							value: props.attributes.successMessage,
							onChange: function ( value ) { props.setAttributes( { successMessage: value } ); },
						} ),
						el( TextareaControl, {
							label: 'Fields JSON',
							help: '[{ "name":"email","type":"email","label":"Email","required":true }]',
							value: json,
							onChange: function ( value ) {
								try {
									const parsed = JSON.parse( value );
									if ( Array.isArray( parsed ) ) {
										props.setAttributes( { fields: parsed } );
									}
								} catch ( e ) {}
							},
						} )
					)
				),
				el( 'div', blockProps,
					el( 'p', null, 'DX Form — ' + ( props.attributes.submitLabel || 'Send' ) ),
					el( 'p', { style: { opacity: 0.7 } }, ( props.attributes.fields || [] ).length + ' fields · submissions save as Form entries' )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
}( window.wp ) );

import { __, _n, sprintf } from '@wordpress/i18n';

/*
 * The import as a list of named phases, and the facts each one reports.
 *
 * Everything here is plain data in, plain data out, so the wizard can hand it
 * whatever the server answered — including answers from a server version that
 * does not report a phase at all — and get back something it can render
 * without a special case. Nothing here invents progress: a phase is active,
 * finished, skipped or failed because a request said so, and a bar only ever
 * measures something countable (bytes uploaded, pages crawled).
 */

export const PHASE_IDS = [ 'read', 'compile', 'review', 'save', 'menus', 'widgets', 'crawl', 'finish' ];

/** Short engine + model line, e.g. "Claude · claude-sonnet-5". */
export function engineLine( engine ) {
	if ( ! engine || ! engine.id ) {
		return '';
	}
	return [ engine.name || engine.id, engine.model ].filter( Boolean ).join( ' · ' );
}

/** The model's human name, e.g. "Claude Sonnet 5", for sentences. */
export function engineModelName( engine ) {
	if ( ! engine || ! engine.id ) {
		return __( 'AI', 'dxai-ui' );
	}
	return engine.model_label || engine.model || engine.name || engine.id;
}

/**
 * The phases this import will go through, in order.
 *
 * The header and footer rows are there only when this server's save handles
 * them (import-info `chrome` / `chrome_default`, see Converter_Controller) or
 * the save reported them anyway. Each row is named for what happens to that
 * part: built in Appearance › Menus / Widgets, or kept as a template part of
 * the design's page — the mode chosen before the save, and after it the mode
 * the save says actually ran (chromeOutcome()).
 *
 * @param {Object}  args
 * @param {string}  args.path   design | code | figma
 * @param {boolean} args.crawl  whether the menu pages will be built
 * @param {Object}  args.engine crawl engine from import-info
 * @param {Object}  args.chrome { menus, widgets }: which of the two rows to show;
 *                              { header, footer }: 'install' | 'keep' | … per row
 */
export function planPhases( { path, crawl, engine, chrome } ) {
	let read = __( 'Reading the archive', 'dxai-ui' );
	if ( path === 'figma' ) {
		read = __( 'Fetching the Figma file', 'dxai-ui' );
	} else if ( path === 'code' ) {
		read = __( 'Reading the component', 'dxai-ui' );
	}
	const list = [
		{ id: 'read', label: read },
		{ id: 'compile', label: __( 'Compiling the design', 'dxai-ui' ) },
		{ id: 'review', label: __( 'Your review', 'dxai-ui' ) },
		{ id: 'save', label: __( 'Saving the page', 'dxai-ui' ) },
	];
	if ( ! chrome || chrome.menus ) {
		list.push( chrome && chrome.header === CHROME_KEEP
			? { id: 'menus', label: __( 'Keeping the header as a template part', 'dxai-ui' ), where: __( 'with the design’s page', 'dxai-ui' ) }
			: { id: 'menus', label: __( 'Creating the header menus', 'dxai-ui' ), where: __( 'Appearance › Menus', 'dxai-ui' ) } );
	}
	if ( ! chrome || chrome.widgets ) {
		list.push( chrome && chrome.footer === CHROME_KEEP
			? { id: 'widgets', label: __( 'Keeping the footer as a template part', 'dxai-ui' ), where: __( 'with the design’s page', 'dxai-ui' ) }
			: { id: 'widgets', label: __( 'Creating the footer widgets', 'dxai-ui' ), where: __( 'Appearance › Widgets', 'dxai-ui' ) } );
	}
	if ( crawl ) {
		list.push( {
			id: 'crawl',
			label: engine && engine.ai && engine.pages === 'ai'
				? sprintf(
					/* translators: %s: AI model name, e.g. Claude Sonnet 5 */
					__( 'Building the menu pages with %s', 'dxai-ui' ),
					engineModelName( engine )
				)
				: __( 'Building the menu pages', 'dxai-ui' ),
			// The engine and model id that will spend the credit, as Settings names them.
			where: engine && engine.ai && engine.pages === 'ai' ? engineLine( engine ) : __( 'from the live site, in this design’s sections', 'dxai-ui' ),
		} );
	}
	list.push( { id: 'finish', label: __( 'Finishing', 'dxai-ui' ) } );
	return list;
}

export function initialProgress() {
	return { phases: {}, active: null, startedAt: 0, endedAt: 0, seq: 0 };
}

/*
 * One reducer for every phase change, so a late answer from a request the
 * screen has moved past (a slow status poll, say) cannot put a finished phase
 * back to running: `start` never reopens a phase that ended.
 */
export function progressReducer( state, action ) {
	const now = action.now || Date.now();
	const prev = state.phases[ action.id ] || {};
	const put = ( patch, extra = {} ) => ( {
		...state,
		...extra,
		seq: state.seq + 1,
		startedAt: state.startedAt || now,
		phases: { ...state.phases, [ action.id ]: { ...prev, ...patch } },
	} );
	switch ( action.type ) {
		case 'reset':
			return initialProgress();
		case 'start':
			if ( prev.state && prev.state !== 'pending' && prev.state !== 'waiting' && ! action.force ) {
				return state;
			}
			return put(
				// `watching`: the request's answer was lost and the screen is asking
				// the server how it went (ConvertWizard watchSave()).
				{ state: 'active', started: now, ended: 0, detail: action.detail || '', meter: action.meter || null, error: null, watching: Boolean( action.watching ) },
				{ active: action.id, endedAt: 0 }
			);
		case 'detail':
			if ( prev.state !== 'active' && prev.state !== 'waiting' ) {
				return state;
			}
			return put( {
				detail: action.detail !== undefined ? action.detail : prev.detail,
				meter: action.meter !== undefined ? action.meter : prev.meter,
				watching: action.watching !== undefined ? Boolean( action.watching ) : Boolean( prev.watching ),
			} );
		case 'wait':
			return put( { state: 'waiting', started: prev.started || now, ended: 0, detail: action.detail || '', meter: null }, { active: action.id } );
		case 'done':
		case 'skip':
		case 'warn': {
			const map = { done: 'done', skip: 'skipped', warn: 'warn' };
			// A phase ends once; a later report (the crawl's final tally after
			// the status poll already saw it finish) updates what it says, not
			// when it ended.
			const stillOpen = ! prev.ended || prev.state === 'active' || prev.state === 'waiting';
			return put(
				{
					state: map[ action.type ],
					started: prev.started || now,
					ended: stillOpen ? now : prev.ended,
					detail: action.detail !== undefined ? action.detail : prev.detail,
					meter: null,
					link: action.link || prev.link || null,
					items: action.items || prev.items || null,
				},
				{ active: state.active === action.id ? null : state.active }
			);
		}
		case 'fail':
			// What the phase was doing ("Sending the archive…") would read as
			// still happening next to the error, so it goes unless replaced.
			return put(
				{ state: 'error', started: prev.started || now, ended: now, meter: null, error: action.error || null, detail: action.detail || '' },
				{ active: null }
			);
		case 'clear': {
			// Back to "not started", for a phase the person steps back out of.
			const phases = { ...state.phases };
			delete phases[ action.id ];
			return { ...state, phases, active: state.active === action.id ? null : state.active, seq: state.seq + 1 };
		}
		case 'end':
			return { ...state, endedAt: now, active: null, seq: state.seq + 1 };
		default:
			return state;
	}
}

/** "42 s", "1:05", "1:02:03". */
export function formatDuration( ms ) {
	const total = Math.max( 0, Math.round( ( ms || 0 ) / 1000 ) );
	if ( total < 60 ) {
		return sprintf(
			/* translators: %d: seconds */
			__( '%d s', 'dxai-ui' ),
			total
		);
	}
	const s = String( total % 60 ).padStart( 2, '0' );
	const m = Math.floor( total / 60 );
	if ( m < 60 ) {
		return m + ':' + s;
	}
	return Math.floor( m / 60 ) + ':' + String( m % 60 ).padStart( 2, '0' ) + ':' + s;
}

export function formatBytes( bytes ) {
	const n = Number( bytes ) || 0;
	if ( n >= 1048576 ) {
		return ( n / 1048576 ).toFixed( n >= 10485760 ? 0 : 1 ) + ' MB';
	}
	if ( n >= 1024 ) {
		return Math.round( n / 1024 ) + ' KB';
	}
	return n + ' B';
}

/* ------------------------------------------------------------------ */
/* Which pages the menu crawl would create                             */
/* ------------------------------------------------------------------ */

function flattenMenu( tree, out = [] ) {
	( Array.isArray( tree ) ? tree : [] ).forEach( ( item ) => {
		if ( ! item || typeof item !== 'object' ) {
			return;
		}
		out.push( { label: String( item.label || '' ), url: String( item.url || '' ) } );
		flattenMenu( item.children, out );
	} );
	return out;
}

function parseUrl( url ) {
	try {
		return new window.URL( url );
	} catch ( e ) {
		return null;
	}
}

function beforeQuery( url ) {
	return String( url ).split( /[?#]/ )[ 0 ];
}

/** Site_Origin::path_of(). */
export function pathOf( url ) {
	let path = '/';
	const parsed = /^[a-z][a-z0-9+.-]*:\/\//i.test( url ) ? parseUrl( url ) : null;
	if ( parsed ) {
		path = parsed.pathname || '/';
	} else {
		path = beforeQuery( url ) || '/';
	}
	path = '/' + path.replace( /^\/+/, '' );
	if ( path !== '/' ) {
		path = path.replace( /\/+$/, '' );
	}
	return path === '' ? '/' : path;
}

function originOf( parsed ) {
	return parsed.protocol.replace( ':', '' ).toLowerCase() + '://' + parsed.hostname.toLowerCase() + ( parsed.port ? ':' + parsed.port : '' );
}

/** Site_Origin::absolutize(). */
function absolutize( url, origin ) {
	const u = String( url || '' ).trim();
	if ( u === '' || u === '#' || u.startsWith( 'tel:' ) || u.startsWith( 'mailto:' ) ) {
		return '';
	}
	if ( /^https?:\/\//i.test( u ) ) {
		return beforeQuery( u );
	}
	if ( u.startsWith( '//' ) ) {
		const scheme = ( parseUrl( origin )?.protocol || 'https:' ).replace( ':', '' );
		return scheme + ':' + beforeQuery( u );
	}
	if ( u.startsWith( '/' ) ) {
		return origin.replace( /\/+$/, '' ) + beforeQuery( u );
	}
	return '';
}

/**
 * The pages the menu crawl would visit, worked out from the compile result
 * the same way the server will (Structure_Repository merges the header and
 * footer menus; Site_Origin picks the most common origin and keeps same-host
 * paths; Site_From_Menu skips the home page).
 *
 * @param {Array}  structures compile result pieces (excluded ones are ignored,
 *                            as the server ignores them)
 * @param {Object} result     compile result (slug, source_html)
 * @param {number} max        Site_Origin::MAX_PAGES
 */
export function menuPlan( structures, result, max = 40 ) {
	let primaryTree = [];
	let footerTree = [];
	let primaryItems = [];
	let footerItems = [];
	( structures || [] ).forEach( ( s ) => {
		if ( ! s || s.included === false ) {
			return;
		}
		const tree = Array.isArray( s.menu_tree ) && s.menu_tree.length ? s.menu_tree : null;
		const items = Array.isArray( s.menu_items ) && s.menu_items.length ? s.menu_items : null;
		if ( s.type === 'header' || s.type === 'navigation' ) {
			primaryItems = items || primaryItems;
			primaryTree = tree || primaryTree;
		} else if ( s.type === 'footer' ) {
			footerItems = items || footerItems;
			footerTree = tree || footerTree;
		}
	} );
	let tree = primaryTree.concat( footerTree );
	if ( ! tree.length ) {
		tree = primaryItems.concat( footerItems ).map( ( i ) => ( { label: i?.label || '', url: i?.url || '', children: [] } ) );
	}
	const items = flattenMenu( tree );

	const counts = {};
	const order = [];
	items.forEach( ( item ) => {
		const parsed = /^https?:\/\//i.test( item.url ) ? parseUrl( item.url ) : null;
		if ( ! parsed || ! parsed.hostname ) {
			return;
		}
		const origin = originOf( parsed );
		if ( ! counts[ origin ] ) {
			counts[ origin ] = 0;
			order.push( origin );
		}
		counts[ origin ] += 1;
	} );
	let origin = '';
	order.forEach( ( o ) => {
		if ( ! origin || counts[ o ] > counts[ origin ] ) {
			origin = o;
		}
	} );
	let guessed = false;
	if ( ! origin && result?.source_html ) {
		const m = String( result.source_html ).match( /https?:\/\/[a-z0-9.-]+(?::\d+)?/i );
		const parsed = m ? parseUrl( m[ 0 ] ) : null;
		if ( parsed ) {
			origin = originOf( parsed );
			guessed = true;
		}
	}

	const pages = [];
	if ( origin ) {
		const host = parseUrl( origin )?.hostname.toLowerCase() || '';
		const home = pathOf( String( result?.slug || '/' ) );
		const seen = {};
		for ( const item of items ) {
			const abs = absolutize( item.url, origin );
			const parsed = abs ? parseUrl( abs ) : null;
			if ( ! parsed || parsed.hostname.toLowerCase() !== host ) {
				continue;
			}
			const path = pathOf( abs );
			if ( path === '' || path === '/' || seen[ path ] ) {
				continue;
			}
			seen[ path ] = true;
			if ( path === home ) {
				continue;
			}
			pages.push( { label: item.label, path, url: abs } );
			if ( pages.length >= max ) {
				break;
			}
		}
	}

	return {
		hasMenu: items.length > 0,
		links: items.length,
		origin,
		host: origin ? parseUrl( origin )?.hostname || '' : '',
		guessed,
		pages,
	};
}

/**
 * Whether the menu pages can be switched on: not a design whose menu is known
 * to link to no other page. (Relative links leave the site unknown; the server
 * may still find it, so that stays allowed.) The pages are built by the plugin
 * from the design's own sections, so no AI engine or key is needed — only the
 * 'ai' engine, which a site has to ask for, does.
 */
export function crawlAllowed( engine, plan, pagesEngine = 'design' ) {
	if ( pagesEngine === 'ai' && ( ! engine || ! engine.id || ! engine.ready ) ) {
		return false;
	}
	// A menu that links to no other page of the old site is fine: the server takes the pages from that site's
	// own menu (Site_Origin::from_site()).
	if ( plan && ! plan.hasMenu ) {
		return false;
	}
	return true;
}

/* ------------------------------------------------------------------ */
/* Header menus and footer widgets, from whatever the server reports    */
/* ------------------------------------------------------------------ */

/**
 * What the screen says, before the import, about where the header and footer
 * go — from what this server's save declares it builds (import-info
 * `chrome`), never from what a later version will do, and from the choice
 * the person made (chromeFacts(); `choice` is '' when this server offers
 * none). Each part is either built in its Appearance screen or saved with
 * the page as a template part.
 *
 * @param {Object} chrome { menus, widgets } booleans
 * @param {string} choice 'install' | 'keep' | '' (no choice offered)
 * @return {Object} intro, page, site and save sentences
 */
export function chromeCopy( chrome, choice = '' ) {
	const kept = choice === CHROME_KEEP;
	const menus = Boolean( chrome && chrome.menus ) && ! kept;
	const widgets = Boolean( chrome && chrome.widgets ) && ! kept;
	let intro;
	if ( isChoice( choice ) ) {
		// The choice itself is on the same screen; the intro only says it is one.
		intro = __( 'You choose below whether the design’s header and footer go into Appearance › Menus and Widgets or stay with its page.', 'dxai-ui' );
	} else if ( menus && widgets ) {
		intro = __( 'The header is built in Appearance › Menus and the footer in Appearance › Widgets, where you edit them afterwards.', 'dxai-ui' );
	} else if ( menus ) {
		intro = __( 'The header is built in Appearance › Menus, where you edit it afterwards; the footer is saved with the page as a template part.', 'dxai-ui' );
	} else if ( widgets ) {
		intro = __( 'The footer is built in Appearance › Widgets, where you edit it afterwards; the header is saved with the page as a template part.', 'dxai-ui' );
	} else {
		intro = __( 'The header and footer are saved with the page as template parts; the finished import links to both.', 'dxai-ui' );
	}
	intro += ' ' + __( 'The site’s homepage setting is never changed.', 'dxai-ui' );

	const header = menus ? __( 'its header menus', 'dxai-ui' ) : __( 'its header', 'dxai-ui' );
	const footer = widgets ? __( 'its footer widgets', 'dxai-ui' ) : __( 'its footer', 'dxai-ui' );
	return {
		intro,
		page: sprintf(
			/* translators: 1: "its header menus" or "its header", 2: "its footer widgets" or "its footer" */
			__( 'The design as one WordPress page, every section an editable block, with %1$s and %2$s.', 'dxai-ui' ),
			header,
			footer
		),
		site: sprintf(
			/* translators: 1: "its header menus" or "its header", 2: "its footer widgets" or "its footer" */
			__( 'The page, %1$s and %2$s — and, if you like, every page the menu links to, built from this design’s sections with the live site’s own text and pictures.', 'dxai-ui' ),
			header,
			footer
		),
		save: sprintf(
			/* translators: 1: "the header’s menus" or "its header", 2: "the footer’s widgets" or "its footer" */
			__( 'Writing the page and its sections, %1$s and %2$s. This is one request and can take a minute on shared hosting.', 'dxai-ui' ),
			menus ? __( 'the header’s menus', 'dxai-ui' ) : __( 'its header', 'dxai-ui' ),
			widgets ? __( 'the footer’s widgets', 'dxai-ui' ) : __( 'its footer', 'dxai-ui' )
		),
	};
}

/* ------------------------------------------------------------------ */
/* The header and footer: install the design's, or keep the site's      */
/* ------------------------------------------------------------------ */

/*
 * The one question an import asks about the site itself. "Install" builds the
 * design's header in Appearance › Menus and its footer in Appearance ›
 * Widgets — the menu locations and widget areas the site's header and footer
 * are drawn from. "Keep" leaves both screens alone: the design keeps its own
 * header and footer as template parts on its pages. Sent as the save's
 * `chrome` field.
 */
export const CHROME_INSTALL = 'install';
export const CHROME_KEEP = 'keep';

export function isChoice( value ) {
	return value === CHROME_INSTALL || value === CHROME_KEEP;
}

function plainObject( value ) {
	// PHP answers an empty array() as [] — no facts, not a list of them.
	return value && typeof value === 'object' && ! Array.isArray( value ) ? value : null;
}

function firstBool( candidates ) {
	for ( const v of candidates ) {
		if ( typeof v === 'boolean' ) {
			return v;
		}
	}
	return null;
}

function designKey( value ) {
	return String( value || '' ).trim().toLowerCase().replace( /\.zip$/, '' );
}

/** A design named in import-info (chrome_owner.header / .footer), or null. */
function ownerOf( raw ) {
	const o = plainObject( raw );
	if ( ! o ) {
		return null;
	}
	const source = String( o.archive || o.source || '' );
	const title = String( o.title || o.name || '' ).trim() || source.replace( /\.zip$/i, '' ).trim();
	const pageId = Number( o.page_id || o.id || 0 ) || 0;
	if ( ! title && ! pageId ) {
		return null;
	}
	return {
		title,
		pageId,
		source,
		// The design's page key, archive#slug (Chrome_Choice::owners()).
		owner: String( o.owner || '' ),
		// Whether its page is still there (outside the trash): a design whose
		// page is gone loses nothing (Chrome_Choice::held_by_others()).
		live: typeof o.live === 'boolean' ? o.live : null,
		// Footer only: whether any page shows it as the site footer block.
		held: typeof o.held === 'boolean' ? o.held : null,
		view: String( o.view || '' ),
	};
}

/**
 * Whether the design that holds the site's header or footer is the one being
 * imported (a new version of the same archive) — Chrome_Choice::is_design()
 * as far as the screen can tell: by archive name when both sides know it
 * (the page key starts with it), by title otherwise; unknown before a file
 * is chosen.
 */
function sameDesign( owner, design ) {
	if ( ! owner || ! design ) {
		return false;
	}
	const theirs = owner.source || ( owner.owner ? owner.owner.split( '#' )[ 0 ] : '' );
	if ( theirs && design.source ) {
		return designKey( theirs ) === designKey( design.source );
	}
	return Boolean( owner.title && design.title && designKey( owner.title ) === designKey( design.title ) );
}

/** The import-info fields the header/footer question reads. */
export const CHROME_INFO_KEYS = [ 'chrome_default', 'chrome_default_reason', 'chrome_default_message', 'theme_chrome', 'theme_name', 'chrome_owner' ];

/**
 * What the screen knows, before the save, about the site's header and footer:
 * whether this server lets the person choose, which answer to offer first and
 * why, whether the theme draws the whole site's header and footer from the
 * menus and widget areas an install writes, and which design holds them now.
 *
 * From import-info (Converter_Controller::import_info_payload(), answered by
 * Chrome_Choice): `chrome_default` — what an automatic save would do for this
 * archive and scope (/import-info?archive=&scope=) — with
 * `chrome_default_reason` (theme, one_page, owned, free, own), `theme_chrome`
 * and `theme_name`, and `chrome_owner` { header, footer }. Until the answer
 * for this scope arrives, a one-page import is offered "keep" the way
 * Chrome_Choice::resolve() decides it (the theme first, then the scope), so
 * the offered answer does not flicker. Read loosely: a server without
 * `chrome_default` that builds the chrome (`chrome.menus`) still gets the
 * question, the answer worked out from the theme; a server that builds
 * neither gets none — it writes template parts whatever is asked.
 *
 * @param {Object} info   import-info (merged with the answer for this scope)
 * @param {Object} design { title, source (archive name), structures, scope,
 *                        scoped (whether `info` already answers for this scope) }
 */
export function chromeFacts( info, design = {} ) {
	const i = plainObject( info ) || {};
	const built = plainObject( i.chrome ) || {};
	const theme = plainObject( i.theme ) || {};
	let serverDefault = [ i.chrome_default, built.default ].find( isChoice ) || '';
	let reason = serverDefault ? String( i.chrome_default_reason || '' ) : '';
	const themeDraws = firstBool( [
		i.theme_chrome,
		i.theme_draws_chrome,
		theme.draws_chrome,
		theme.renders_chrome,
		theme.classic,
		i.classic_theme,
	] );
	const offered = serverDefault !== '' || Boolean( built.menus || built.widgets );
	if ( serverDefault && ! design.scoped && design.scope === 'page' && ! themeDraws ) {
		// Chrome_Choice::resolve(): a one-page import keeps the site's.
		serverDefault = CHROME_KEEP;
		reason = 'one_page';
	}
	const owners = plainObject( i.chrome_owner ) || plainObject( i.chrome_owners ) || {};
	// Only a design whose page is still there loses its header or footer.
	const current = ( owner ) => ( owner && owner.live !== false && ! ( owner.live === null && owner.held === false ) ? owner : null );
	const header = current( ownerOf( owners.header ) );
	const footer = current( ownerOf( owners.footer ) );

	let hasHeader = null;
	let hasFooter = null;
	if ( Array.isArray( design.structures ) && design.structures.length ) {
		const kept = design.structures.filter( ( s ) => s && s.included !== false );
		hasHeader = kept.some( ( s ) => s.type === 'header' || s.type === 'navigation' );
		hasFooter = kept.some( ( s ) => s.type === 'footer' );
	}

	const others = [];
	[ [ 'header', header ], [ 'footer', footer ] ].forEach( ( [ part, owner ] ) => {
		if ( ! owner || sameDesign( owner, design ) ) {
			return;
		}
		const known = others.find( ( o ) => designKey( o.title ) === designKey( owner.title ) && o.pageId === owner.pageId );
		if ( known ) {
			known.parts.push( part );
		} else {
			others.push( { ...owner, parts: [ part ] } );
		}
	} );

	return {
		offered,
		defaultChoice: serverDefault || ( themeDraws ? CHROME_KEEP : CHROME_INSTALL ),
		// Why that answer is offered first: Chrome_Choice's reason, or
		// 'derived' when this screen worked it out from the theme alone.
		defaultReason: serverDefault ? reason : 'derived',
		themeName: String( i.theme_name || theme.name || '' ),
		themeDraws: themeDraws === true,
		header,
		footer,
		own: reason === 'own' || Boolean( ( header && sameDesign( header, design ) ) || ( footer && sameDesign( footer, design ) ) ),
		others,
		hasHeader,
		hasFooter,
	};
}

/**
 * The sentences that warn, before the save, that installing would change the
 * header or footer of the WHOLE site — each one said once, next to the
 * install option (and read with it, through aria-describedby):
 *  - `theme`: a theme that draws every page's header and footer from these
 *    menu locations and widget areas (a classic theme such as the DevriX
 *    ones) shows the design's navigation and footer widgets on every page;
 *  - `owner`: another design holds the site's header or footer now; it is
 *    named, and so is what happens to its own pages.
 *
 * @param {Object} facts chromeFacts()
 * @return {Array<{key:string, text:string}>} empty when installing touches nothing else
 */
export function chromeWarnings( facts ) {
	const f = facts || {};
	const out = [];
	if ( f.themeDraws ) {
		out.push( {
			key: 'theme',
			text: sprintf(
				/* translators: %s: theme name */
				__( '%s draws the header and footer of every page of this site from Appearance › Menus and Appearance › Widgets. Installing puts this design’s navigation and footer widgets on every page the theme shows — the whole site, not only this design’s page. Widgets already in the footer areas are moved to Inactive Widgets; nothing is deleted.', 'dxai-ui' ),
				f.themeName || __( 'This theme', 'dxai-ui' )
			),
		} );
	}
	( f.others || [] ).forEach( ( other ) => {
		const both = other.parts.includes( 'header' ) && other.parts.includes( 'footer' );
		const title = other.title || sprintf( /* translators: %d: page ID */ __( 'page %d', 'dxai-ui' ), other.pageId );
		let text;
		if ( both ) {
			text = sprintf(
				/* translators: %s: title of the design whose header and footer the site shows */
				__( 'The site’s header and footer are now those of “%s” (its menus hold the theme’s menu locations, its widgets the footer areas). Installing replaces them with this design’s for the whole site; the pages of “%s” keep their own header and footer.', 'dxai-ui' ),
				title,
				title
			);
		} else if ( other.parts.includes( 'header' ) ) {
			text = sprintf(
				/* translators: %s: title of the design whose header the site shows */
				__( 'The site’s header is now that of “%s” (its menus hold the theme’s menu locations). Installing replaces it with this design’s for the whole site; the pages of “%s” keep their own header.', 'dxai-ui' ),
				title,
				title
			);
		} else {
			text = sprintf(
				/* translators: %s: title of the design whose footer the site shows */
				__( 'The site’s footer is now that of “%s” (its widgets fill the footer areas). Installing replaces it with this design’s for the whole site; the pages of “%s” keep their own footer, as a template part.', 'dxai-ui' ),
				title,
				title
			);
		}
		out.push( { key: 'owner-' + ( other.pageId || designKey( title ) ), text } );
	} );
	return out;
}

/** 'install' | 'keep' | '' from a field the server may have filled in. */
function modeOf( value ) {
	if ( isChoice( value ) ) {
		return value;
	}
	if ( value === 'installed' || value === 'menus' || value === 'widgets' ) {
		return CHROME_INSTALL;
	}
	if ( value === 'kept' || value === 'part' || value === 'template_part' || value === 'template-part' ) {
		return CHROME_KEEP;
	}
	return '';
}

function rawChrome( res ) {
	const r = plainObject( res ) || {};
	const nested = plainObject( r.structures ) ? plainObject( r.structures.chrome ) : null;
	return plainObject( r.chrome ) || plainObject( r.site_chrome ) || nested || {};
}

/** A link that opens Appearance › Menus or Widgets, not a template part. */
function isScreenLink( url ) {
	return /(nav-menus|widgets)\.php/.test( String( url || '' ) );
}

/**
 * What the save did with the header and with the footer — the mode that
 * actually ran, which is what the progress rows and the done screen show,
 * whatever was asked:
 *
 *   install — built in Appearance › Menus / Widgets (the save lists them);
 *   keep    — kept as a template part of the design's page: because the
 *             person chose it, because the install was tried and failed
 *             (`refused`, with the server's reason), or because the site
 *             kept it although install was asked (`overridden`: a filter);
 *   none    — the design has no such part to build;
 *   unknown — the server did not say.
 *
 * `differs` marks a mode other than the one asked for (a server that does not
 * know the choice installs anyway, or refuses an install), so the screen can
 * say so instead of quietly showing something else.
 *
 * @param {Object} res       the save response (or the crawl's merged result)
 * @param {string} requested what the save was asked: 'install' | 'keep' | ''
 */
export function chromeOutcome( res, requested = '' ) {
	const report = chromeReport( res );
	const c = rawChrome( res );
	const r = plainObject( res ) || {};
	// Chrome_Choice::resolve() as the save ran it (save-pattern's chrome_mode).
	const ran = plainObject( r.chrome_mode ) || ( plainObject( r.structures ) ? plainObject( r.structures.chrome_mode ) : null ) || {};
	const page = plainObject( r.page ) || {};
	const asked = isChoice( requested ) ? requested : ( modeOf( ran.requested ) || modeOf( c.requested ) || '' );
	const said = modeOf( c.mode ) || modeOf( ran.mode );
	const cause = String( c.mode_reason || ran.reason || '' );
	const area = ( part ) => {
		const isHeader = part === 'header';
		const list = isHeader ? report.menus : report.widgets;
		const err = isHeader ? report.menusError : report.widgetsError;
		// A kept part is no error: the report holds its sentence (chrome_report()).
		const keptMsg = c[ isHeader ? 'menus_kept' : 'widgets_kept' ];
		const partLink = String( page[ isHeader ? 'edit_header' : 'edit_footer' ] || '' );
		let mode = 'unknown';
		if ( Array.isArray( list ) && list.length ) {
			mode = CHROME_INSTALL;
		} else if ( typeof keptMsg === 'string' || err ) {
			mode = CHROME_KEEP;
		} else if ( Array.isArray( list ) ) {
			// Reported, and there was nothing to build: the design has no such part.
			mode = 'none';
		} else if ( said ) {
			mode = said;
		} else if ( partLink && ! isScreenLink( partLink ) ) {
			// The saved page links its own template part: that is where it stayed.
			mode = CHROME_KEEP;
		} else if ( asked === CHROME_KEEP && Object.keys( c ).length > 0 ) {
			// A report that says nothing of this part after a "keep": it was kept.
			mode = CHROME_KEEP;
		}
		const kept = mode === CHROME_KEEP;
		return {
			mode,
			asked,
			// The install ran and failed (the header's menu slots could not be
			// read, an exception): kept as a template part, with the server's reason.
			refused: kept && Boolean( err ),
			// Install was asked, and the site kept it anyway (a dxai_ui_chrome_mode filter).
			overridden: kept && ! err && asked === CHROME_INSTALL,
			// Chrome_Choice's reason for the mode: asked, theme, one_page, owned, free, own, filter.
			cause,
			// The server's own sentence: why an install failed, or what keeping did.
			reason: kept ? ( err || ( typeof keptMsg === 'string' ? keptMsg : '' ) ) : '',
			differs: isChoice( asked ) && isChoice( mode ) && mode !== asked,
			// Where to edit the kept template part (the save's own link), never a
			// Menus / Widgets screen that the part is not in.
			partUrl: partLink && ! isScreenLink( partLink ) ? partLink : '',
		};
	};
	return {
		asked,
		mode: said,
		cause,
		message: String( c.mode_message || ran.message || '' ),
		header: area( 'header' ),
		footer: area( 'footer' ),
		notices: report.notices,
	};
}

function asList( value ) {
	if ( value === undefined || value === null || value === false ) {
		return undefined;
	}
	if ( Array.isArray( value ) ) {
		return value;
	}
	if ( typeof value === 'number' ) {
		return value > 0 ? Array.from( { length: value }, () => ( {} ) ) : [];
	}
	if ( typeof value === 'object' ) {
		// A map keyed by location or area: { primary: {...}, footer-1: [...] }.
		// A number there is a menu's term id (Header_Menus::install() answers
		// location => id), never a count.
		return Object.keys( value ).map( ( key ) => {
			const v = value[ key ];
			if ( v && typeof v === 'object' && ! Array.isArray( v ) ) {
				return { key, ...v };
			}
			if ( typeof v === 'number' ) {
				return { key, id: v };
			}
			return { key, items: v };
		} );
	}
	return undefined;
}

function countOf( v ) {
	if ( Array.isArray( v ) ) {
		return v.length;
	}
	if ( typeof v === 'number' ) {
		return v;
	}
	if ( v && typeof v === 'object' ) {
		return Object.keys( v ).length;
	}
	return null;
}

function normalizeEntry( entry ) {
	if ( entry === null || entry === undefined ) {
		return null;
	}
	if ( typeof entry !== 'object' ) {
		return { name: String( entry ), count: null, where: '', url: '' };
	}
	const name = entry.name || entry.title || entry.label || entry.menu || entry.area_name || entry.area || entry.sidebar || entry.key || entry.slug || ( entry.id ? '#' + entry.id : '' );
	// Where a menu is shown; a widget area's name already says where it is.
	const where = entry.location_label || entry.location || '';
	let count = null;
	[ entry.items, entry.item_count, entry.count, entry.widgets, entry.widget_count ].some( ( v ) => {
		const c = countOf( v );
		if ( c !== null ) {
			count = c;
			return true;
		}
		return false;
	} );
	return { name: String( name || '' ), where: String( where === name ? '' : where ), count, url: String( entry.edit_url || '' ) };
}

function firstDefined( candidates ) {
	for ( const c of candidates ) {
		const list = asList( c );
		if ( list !== undefined ) {
			return list;
		}
	}
	return undefined;
}

/**
 * What the save (or the crawl's finishing pass) says it did with the header
 * menus and footer widgets.
 *
 * The integration that creates them is newer than this screen, so the shape
 * is read loosely: `chrome.menus` / `chrome.widgets` preferred, with the
 * other names such an answer might plausibly use. `undefined` for a part
 * means the server did not report it at all; an empty list means it reported
 * creating none.
 */
export function chromeReport( res ) {
	const r = res && typeof res === 'object' ? res : {};
	const nested = r.structures && typeof r.structures === 'object' ? r.structures.chrome : null;
	const c = ( r.chrome && typeof r.chrome === 'object' && r.chrome ) ||
		( r.site_chrome && typeof r.site_chrome === 'object' && r.site_chrome ) ||
		// Structure_Repository::save()'s own result travels as `structures`.
		( nested && typeof nested === 'object' && nested ) ||
		{};
	const header = ( c.header && typeof c.header === 'object' && c.header ) || ( r.header && typeof r.header === 'object' && r.header ) || {};
	const footer = ( c.footer && typeof c.footer === 'object' && c.footer ) || ( r.footer && typeof r.footer === 'object' && r.footer ) || {};
	const s = ( r.structures && typeof r.structures === 'object' && r.structures ) || {};

	const menus = firstDefined( [ c.menus, header.menus, header.menu ? [ header.menu ] : undefined, r.menus, r.nav_menus, r.header_menus, s.menus, s.nav_menus, s.header_menus ] );
	const widgets = firstDefined( [ c.widgets, c.widget_areas, c.sidebars, footer.widgets, footer.areas, footer.widget_areas, r.widgets, r.widget_areas, r.footer_widgets, s.widgets, s.widget_areas, s.footer_widgets ] );

	const errorOf = ( ...candidates ) => {
		for ( const v of candidates ) {
			if ( typeof v === 'string' && v ) {
				return v;
			}
			if ( v && typeof v === 'object' && ( v.message || v.error ) ) {
				return String( v.message || v.error );
			}
		}
		return '';
	};

	return {
		menus: menus === undefined ? undefined : menus.map( normalizeEntry ).filter( Boolean ),
		widgets: widgets === undefined ? undefined : widgets.map( normalizeEntry ).filter( Boolean ),
		menusError: errorOf( c.menus_error, header.error, r.menus_error ),
		widgetsError: errorOf( c.widgets_error, footer.error, r.widgets_error ),
		menusUrl: c.menus_url || header.edit_url || header.url || r.menus_url || '',
		widgetsUrl: c.widgets_url || footer.edit_url || footer.url || r.widgets_url || '',
		// Someone's widgets that stood in the footer's areas, moved to
		// Inactive Widgets (Converter_Controller::chrome_report()).
		widgetsMoved: Number( c.widgets_moved || 0 ),
		logoSet: Boolean( c.logo_set ),
		// What changed for other designs and for the person's own menus, one
		// sentence each (header_replaced, locations_kept, site_kept, footer_replaced).
		notices: ( Array.isArray( c.notices ) ? c.notices : [] ).filter( ( n ) => typeof n === 'string' && n.trim() ),
	};
}

function describeEntries( list, isMenus ) {
	return list
		.slice( 0, 6 )
		.map( ( e ) => {
			const bits = [ e.name || ( isMenus ? __( 'Menu', 'dxai-ui' ) : __( 'Widgets', 'dxai-ui' ) ) ];
			if ( e.where ) {
				bits.push( '(' + e.where + ')' );
			}
			if ( e.count !== null && e.count !== undefined ) {
				bits.push( '· ' + ( isMenus
					? sprintf(
						/* translators: %d: number of menu items */
						_n( '%d item', '%d items', e.count, 'dxai-ui' ),
						e.count
					)
					: sprintf(
						/* translators: %d: number of widgets */
						_n( '%d widget', '%d widgets', e.count, 'dxai-ui' ),
						e.count
					) ) );
			}
			return bits.join( ' ' );
		} )
		.join( '; ' ) + ( list.length > 6 ? ' …' : '' );
}

/**
 * What happened to a header or footer that was kept as a template part, in
 * one or two sentences — for its progress row and its row on the done
 * screen: the install failed (the server's reason), install was asked and
 * the site kept it anyway, or the person chose to keep the site's. The
 * server's own sentence about what keeping did (chrome_report()'s
 * `menus_kept` / `widgets_kept`) is used when it gave one.
 *
 * @param {string} part 'header' | 'footer'
 * @param {Object} area chromeOutcome().header / .footer
 */
export function keptSentence( part, area ) {
	const isHeader = part === 'header';
	const a = area || {};
	if ( a.refused ) {
		return sprintf(
			/* translators: %s: why, as the server said it */
			isHeader
				? __( 'It could not be built in Appearance › Menus, so it was kept as a template part of the design’s page: %s', 'dxai-ui' )
				: __( 'It could not be built in Appearance › Widgets, so it was kept as a template part of the design’s page: %s', 'dxai-ui' ),
			a.reason || __( 'the server gave no reason.', 'dxai-ui' )
		);
	}
	const plain = isHeader
		? __( 'The header is a template part on this design’s pages; Appearance › Menus was not changed.', 'dxai-ui' )
		: __( 'The footer is a template part on this design’s pages; Appearance › Widgets was not changed.', 'dxai-ui' );
	const what = a.reason || plain;
	if ( a.overridden ) {
		return ( a.cause === 'filter'
			? __( 'You chose to install it, but this site keeps it as a template part (the dxai_ui_chrome_mode filter decided so).', 'dxai-ui' )
			: __( 'You chose to install it, but the server kept it as a template part.', 'dxai-ui' ) ) + ' ' + what;
	}
	if ( a.cause === 'asked' || a.asked === CHROME_KEEP ) {
		return __( 'You chose to keep the site’s header and footer.', 'dxai-ui' ) + ' ' + what;
	}
	return what;
}

/**
 * The reducer action for the menus or widgets phase, from chromeReport() and
 * the mode that ran (chromeOutcome()). Never leaves the phase running:
 * reported → done (or warn), not reported → skipped with a plain reason.
 *
 * @param {string} part   'menus' (the header) | 'widgets' (the footer)
 * @param {Object} report chromeReport()
 * @param {Object} links  import-info links
 * @param {Object} area   chromeOutcome().header / .footer, when known
 */
export function chromePhaseAction( part, report, links, area = null ) {
	const isMenus = part === 'menus';
	const list = isMenus ? report.menus : report.widgets;
	const err = isMenus ? report.menusError : report.widgetsError;
	const url = ( isMenus ? report.menusUrl : report.widgetsUrl ) || ( isMenus ? links?.menus : links?.widgets ) || '';
	const link = url ? { href: url, label: isMenus ? __( 'Open Appearance › Menus', 'dxai-ui' ) : __( 'Open Appearance › Widgets', 'dxai-ui' ) } : null;
	const partLink = area && area.partUrl
		? { href: area.partUrl, label: isMenus ? __( 'Edit the header template part', 'dxai-ui' ) : __( 'Edit the footer template part', 'dxai-ui' ) }
		: null;
	if ( area && area.mode === CHROME_KEEP ) {
		return {
			type: area.refused || area.overridden ? 'warn' : 'done',
			id: part,
			detail: keptSentence( part === 'menus' ? 'header' : 'footer', area ),
			link: partLink || ( area.refused ? link : null ),
		};
	}
	if ( err ) {
		return { type: 'warn', id: part, detail: err, link };
	}
	if ( list === undefined ) {
		return {
			type: 'skip',
			id: part,
			detail: __( 'Not reported by the server for this import.', 'dxai-ui' ),
		};
	}
	if ( ! list.length ) {
		return {
			type: 'skip',
			id: part,
			detail: isMenus ? __( 'This design has no header menu to create.', 'dxai-ui' ) : __( 'This design has no footer widgets to create.', 'dxai-ui' ),
			link,
		};
	}
	const extra = [];
	// Asked to keep, and built anyway (a server that does not know the choice): said, not hidden.
	const differs = Boolean( area && area.differs && area.mode === CHROME_INSTALL );
	if ( differs ) {
		extra.push( isMenus
			? __( 'You chose to keep the site’s header and footer, but this server built the header in Appearance › Menus anyway', 'dxai-ui' )
			: __( 'You chose to keep the site’s header and footer, but this server built the footer in Appearance › Widgets anyway', 'dxai-ui' ) );
	}
	if ( isMenus && report.logoSet ) {
		extra.push( __( 'The design’s logo is now the Site Logo.', 'dxai-ui' ) );
	}
	if ( ! isMenus && report.widgetsMoved > 0 ) {
		extra.push( sprintf(
			/* translators: %d: number of widgets */
			_n(
				'%d widget that was already in these areas was moved to Inactive Widgets — nothing was deleted.',
				'%d widgets that were already in these areas were moved to Inactive Widgets — nothing was deleted.',
				report.widgetsMoved,
				'dxai-ui'
			),
			report.widgetsMoved
		) );
	}
	return {
		type: differs ? 'warn' : 'done',
		id: part,
		detail: [
			isMenus
				? sprintf(
					/* translators: 1: number of menus, 2: list of menus */
					_n( '%1$d menu: %2$s', '%1$d menus: %2$s', list.length, 'dxai-ui' ),
					list.length,
					describeEntries( list, true )
				)
				: sprintf(
					/* translators: 1: number of widget areas, 2: list of areas */
					_n( '%1$d widget area: %2$s', '%1$d widget areas: %2$s', list.length, 'dxai-ui' ),
					list.length,
					describeEntries( list, false )
				),
		].concat( extra ).join( '. ' ),
		link,
		items: list,
	};
}

/* ------------------------------------------------------------------ */
/* Errors                                                               */
/* ------------------------------------------------------------------ */

const TIMEOUT_CODES = [ 'invalid_json', 'fetch_error', 'http_502', 'http_503', 'http_504', 'http_520', 'http_522', 'http_524', 'timeout' ];

/**
 * Whether a failed request may only have lost its answer: a proxy's error
 * page, a dropped connection, a gateway timeout — the cases where WordPress
 * can still be working, or already done. A WordPress error (JSON with a
 * code) is the save's own answer and ended it.
 */
export function lostAnswer( err ) {
	const e = err && typeof err === 'object' ? err : {};
	const status = Number( e.data?.status || e.status || 0 );
	return TIMEOUT_CODES.includes( String( e.code || '' ) ) || [ 502, 503, 504, 520, 522, 524 ].includes( status );
}

/** Why an answer did not arrive, in a few words for a sentence. */
export function lostWhy( err ) {
	const e = err && typeof err === 'object' ? err : {};
	const status = Number( e.data?.status || e.status || 0 );
	if ( e.code === 'invalid_json' ) {
		return __( 'the server or a proxy answered with an error page', 'dxai-ui' );
	}
	if ( e.code === 'fetch_error' ) {
		return __( 'the connection dropped', 'dxai-ui' );
	}
	// WordPress's own error says more than its status code.
	if ( e.message && e.code && ! /^http_/.test( String( e.code ) ) ) {
		return String( e.message );
	}
	if ( status ) {
		return sprintf(
			/* translators: %d: HTTP status code */
			__( 'HTTP %d', 'dxai-ui' ),
			status
		);
	}
	return String( e.message || '' ) || __( 'no answer', 'dxai-ui' );
}

/** A random token for one save attempt (Converter_Controller::save_pattern()). */
export function importToken() {
	const bytes = new Uint8Array( 16 );
	if ( window.crypto && typeof window.crypto.getRandomValues === 'function' ) {
		window.crypto.getRandomValues( bytes );
	} else {
		for ( let i = 0; i < bytes.length; i++ ) {
			bytes[ i ] = Math.floor( Math.random() * 256 );
		}
	}
	return Array.from( bytes, ( b ) => b.toString( 16 ).padStart( 2, '0' ) ).join( '' );
}

/**
 * A failed request, told in terms of the phase it failed in: what the
 * server said, and what to do about it.
 *
 * For a save, `context.save` says what /save-status found out about it
 * afterwards, which is what decides whether saving again is safe:
 *   unknown   — WordPress has no record of the request: nothing was written.
 *   failed    — WordPress recorded the save's own end with this error.
 *   stuck     — still recorded as running long after any save should end.
 *   unchecked — the status could not be read either.
 * `context.crawl` is whether this import builds the menu pages, which is
 * what a second save would start twice.
 *
 * @param {Object} err
 * @param {string} phase
 * @param {Object} context
 */
export function describeFailure( err, phase, context = {} ) {
	const e = err && typeof err === 'object' ? err : { message: String( err || '' ) };
	const code = String( e.code || '' );
	const status = Number( e.data?.status || e.status || 0 );
	let message = String( e.message || '' ).trim();
	let hint = '';
	const timedOut = TIMEOUT_CODES.includes( code ) || [ 502, 503, 504, 520, 522, 524 ].includes( status );

	if ( code === 'invalid_json' ) {
		message = __( 'The server answered with something other than WordPress’s response — usually an error page from PHP, the web server or a proxy.', 'dxai-ui' ) + ( status ? ' (HTTP ' + status + ')' : '' );
	} else if ( code === 'fetch_error' ) {
		message = __( 'The connection to the server was lost before it answered.', 'dxai-ui' );
	} else if ( ! message ) {
		message = status ? sprintf(
			/* translators: %d: HTTP status code */
			__( 'The server answered HTTP %d.', 'dxai-ui' ),
			status
		) : __( 'The request failed without a message.', 'dxai-ui' );
	}

	if ( code === 'rest_cookie_invalid_nonce' || code === 'rest_forbidden' || ( status === 401 && code !== 'dxai_ui_unfiltered_html' ) ) {
		hint = __( 'Your WordPress session has expired. Reload this page, sign in again if asked, and repeat the import.', 'dxai-ui' );
	} else if ( code === 'dxai_ui_unfiltered_html' ) {
		hint = __( 'Run the import as an administrator who may publish unfiltered HTML.', 'dxai-ui' );
	} else if ( status === 413 || /too large|upload_max_filesize|post_max_size/i.test( message ) ) {
		hint = __( 'Ask your host to raise upload_max_filesize and post_max_size, or export the design without unused assets.', 'dxai-ui' );
	} else if ( phase === 'save' && code === 'dxai_ui_save_fatal' ) {
		hint = __( 'PHP ran out of memory or time part-way through. Ask your host to raise memory_limit or max_execution_time, then try again — it updates the same page rather than adding a copy.', 'dxai-ui' );
	} else if ( phase === 'save' && context.save === 'unknown' ) {
		hint = __( 'WordPress has no record of receiving this save, so nothing was written. Trying again is safe.', 'dxai-ui' );
	} else if ( phase === 'save' && context.save === 'stuck' ) {
		hint = context.crawl
			? __( 'WordPress started this save long ago and has not recorded its end — the host may have stopped it. Check the Library first: if the page is there, the menu pages may already be building in the background, and saving again would build them twice and spend the API credit twice.', 'dxai-ui' )
			: __( 'WordPress started this save long ago and has not recorded its end — the host may have stopped it. Check the Library; trying again updates the same page rather than adding a copy.', 'dxai-ui' );
	} else if ( phase === 'save' && ( timedOut || context.save === 'unchecked' ) ) {
		hint = context.crawl
			? __( 'Hosts and proxies often stop waiting after 60–100 seconds while WordPress keeps working, and this screen could not ask WordPress how the save ended. Check again in a moment, or check the Library. Saving again while the first save is still running would build the menu pages twice and spend the API credit twice.', 'dxai-ui' )
			: __( 'Hosts and proxies often stop waiting after 60–100 seconds while WordPress keeps working. The page may have been saved anyway: check the Library before you try again (trying again updates the same page rather than adding a copy).', 'dxai-ui' );
	} else if ( phase === 'crawl' && timedOut ) {
		hint = __( 'One page can take longer than a proxy waits. The server finishes the step anyway; keep trying and the crawl continues where it is.', 'dxai-ui' );
	} else if ( timedOut ) {
		hint = __( 'The server took too long or stopped answering. Try again; a large archive may need a host with a longer time limit.', 'dxai-ui' );
	} else if ( code === 'dxai_ui_source_compile' ) {
		hint = __( 'Export the source (with the src folder), not only the built dist folder, and drop that archive instead.', 'dxai-ui' );
	} else if ( code === 'dxai_ui_crawl_job' ) {
		hint = __( 'The page itself is saved. Start the import again to build the menu pages.', 'dxai-ui' );
	} else if ( phase === 'read' || phase === 'compile' ) {
		hint = __( 'Check that the file is the design’s export and try again. “Show details” has the server’s log.', 'dxai-ui' );
	} else {
		hint = __( 'Try again. If it keeps failing, the site’s PHP error log says why.', 'dxai-ui' );
	}

	return { phase, code, status, message, hint, timedOut };
}

/* ------------------------------------------------------------------ */
/* The crawl                                                            */
/* ------------------------------------------------------------------ */

const PHASE_RANK = { fetch: 0, images: 1, restyle: 2, save: 3 };

/**
 * An ordering key for crawl progress, so an older answer (a status poll that
 * was already in flight) never replaces a newer one.
 */
export function crawlKey( p ) {
	if ( ! p ) {
		return -1;
	}
	if ( p.done ) {
		return Number.MAX_SAFE_INTEGER;
	}
	return ( ( p.index || 0 ) * 10 ) + ( PHASE_RANK[ p.phase ] ?? 0 ) + ( p.finishing ? 5 : 0 );
}

/** What the server is doing to the current page, in words. */
export function crawlStepText( p, engine ) {
	if ( ! p ) {
		return '';
	}
	if ( p.finishing ) {
		return __( 'Linking the menus to the new pages, rewriting links and applying the colour tokens…', 'dxai-ui' );
	}
	switch ( p.phase ) {
		case 'fetch':
			return __( 'Fetching the live page…', 'dxai-ui' );
		case 'images': {
			const left = p.images?.left;
			return typeof left === 'number' && left > 0
				? sprintf(
					/* translators: %d: images still to download */
					_n( 'Downloading its images — %d left…', 'Downloading its images — %d left…', left, 'dxai-ui' ),
					left
				)
				: __( 'Downloading its images…', 'dxai-ui' );
		}
		case 'restyle':
			return p.ai === false
				? __( 'Arranging its text and pictures in the design’s sections…', 'dxai-ui' )
				: sprintf(
					/* translators: %s: AI model name */
					__( 'Rebuilding it in this design with %s — usually 30 seconds to 2 minutes a page…', 'dxai-ui' ),
					engineModelName( engine )
				);
		case 'save':
			return __( 'Saving it as a WordPress page…', 'dxai-ui' );
		default:
			return __( 'Planning the pages…', 'dxai-ui' );
	}
}

/** Counts for the summary, from the save response merged with the crawl. */
export function summaryCounts( saved ) {
	const s = saved || {};
	const extra = Array.isArray( s.structures?.extra_page_ids ) ? s.structures.extra_page_ids.length : 0;
	const crawled = Array.isArray( s.pages_created ) ? s.pages_created.length : 0;
	const main = s.page?.id ? 1 : 0;
	return {
		main,
		extra,
		crawled,
		pages: main + extra + crawled,
		restyled: Number( s.pages_restyled || 0 ),
		errors: Array.isArray( s.crawl_errors ) ? s.crawl_errors.length : 0,
		skipped: Array.isArray( s.pages_skipped ) ? s.pages_skipped.filter( ( p ) => p && p.reason !== 'home' ).length : 0,
	};
}

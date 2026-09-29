import { useEffect, useMemo, useReducer, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, TextControl, TextareaControl, SelectControl } from '@wordpress/components';
import { markupFromStructures } from '../compileTailwind';
import FidelityReport from '../components/FidelityReport';
import ImportProgress from '../components/ImportProgress';
import PagesOption from '../components/PagesOption';
import ChromeChoice from '../components/ChromeChoice';
import ImportSummary from '../components/ImportSummary';
import { postForm } from '../upload';
import {
	CHROME_INFO_KEYS,
	chromeCopy,
	chromeFacts,
	chromeOutcome,
	chromePhaseAction,
	chromeReport,
	crawlAllowed,
	crawlKey,
	describeFailure,
	engineLine,
	formatBytes,
	formatDuration,
	importToken,
	initialProgress,
	isChoice,
	lostAnswer,
	lostWhy,
	menuPlan,
	planPhases,
	progressReducer,
} from '../importFlow';

/*
 * What someone has, not which tool made it.
 *
 * There used to be a Lovable card and a Generated-ZIP card, and the card
 * decided which connector read the archive — so the same export compiled two
 * different ways depending on which one was clicked, and anyone with a Claude
 * Design export had to know it counted as "generated". The archive says what
 * it is (Zip_Router reads its file list), so there is one drop zone for every
 * export and nothing left to classify.
 */
const PATHS = [
	{
		id: 'design',
		title: __( 'Design archive → Gutenberg', 'dxai-ui' ),
		blurb: __( 'Drop any export: Lovable, Claude Design, Vite/React, Vue, Next.js, or plain HTML. We read the archive and pick the compiler — colors, images, motion, pages, no LLM.', 'dxai-ui' ),
		hint: __( 'One .zip', 'dxai-ui' ),
	},
	{
		id: 'code',
		title: __( 'Paste a component', 'dxai-ui' ),
		blurb: __( 'A single JSX/TSX component and, if you have it, its CSS. Useful for one section at a time.', 'dxai-ui' ),
		hint: __( 'Paste', 'dxai-ui' ),
	},
	{
		id: 'figma',
		title: __( 'Figma → Gutenberg', 'dxai-ui' ),
		blurb: __( 'Paste a Figma file URL. We turn frames into WordPress blocks.', 'dxai-ui' ),
		hint: __( 'One URL', 'dxai-ui' ),
	},
];

/** What the server reports having read, for the screen to name it back. */
const KINDS = {
	'lovable-zip': __( 'React source (Lovable, Vite)', 'dxai-ui' ),
	'dc-zip': __( 'Claude Design export', 'dxai-ui' ),
	'generated-zip': __( 'Generated design (HTML, Vue, Next.js)', 'dxai-ui' ),
	'lovable-code': __( 'Pasted component', 'dxai-ui' ),
	'figma-api': __( 'Figma file', 'dxai-ui' ),
};

const LEVELS = [
	{ id: 'path', label: __( 'Pick', 'dxai-ui' ) },
	{ id: 'scope', label: __( 'Page or site', 'dxai-ui' ) },
	{ id: 'file', label: __( 'Add file', 'dxai-ui' ) },
	{ id: 'run', label: __( 'Convert', 'dxai-ui' ) },
	{ id: 'preview', label: __( 'Preview', 'dxai-ui' ) },
	{ id: 'import', label: __( 'Import', 'dxai-ui' ) },
];

const STRUCTURE_TYPES = [
	'header',
	'navigation',
	'hero',
	'blog',
	'form',
	'slider',
	'footer',
	'section',
];

/** The steps that show the full progress card. */
const PROGRESS_STEPS = [ 'run', 'saving', 'crawling' ];

/*
 * How long a save may stay "running" in its status record before the screen
 * stops waiting and says so. Longer than any host lets PHP run a request
 * (max_execution_time and PHP-FPM's request_terminate_timeout are minutes,
 * not tens of minutes); a save the host killed without a shutdown leaves the
 * record running, and this is how long that takes to show.
 */
const SAVE_STUCK_MS = 10 * 60 * 1000;

/* A save-status "unknown" this many times in a row means no save is recorded. */
const SAVE_UNKNOWN_CHECKS = 3;

/* Status reads that may fail in a row before the screen gives up asking. */
const SAVE_STATUS_MISSES = 5;

function mergeGeneration( acc, part ) {
	if ( ! part ) {
		return acc;
	}
	if ( ! acc ) {
		return { ...part };
	}
	const seen = { header: false, footer: false, navigation: false };
	const structures = [];
	[ ...( acc.structures || [] ), ...( part.structures || [] ) ].forEach( ( item ) => {
		if ( ! item ) {
			return;
		}
		if ( Object.prototype.hasOwnProperty.call( seen, item.type ) ) {
			if ( seen[ item.type ] ) {
				return;
			}
			seen[ item.type ] = true;
		}
		structures.push( item );
	} );
	const css = [ acc.custom_css, part.custom_css ].filter( Boolean ).join( '\n\n' );
	const js = [ acc.custom_js, part.custom_js ].filter( Boolean ).join( '\n' );
	const media = [ ...( acc.required_media || [] ), ...( part.required_media || [] ) ];
	return {
		...acc,
		...part,
		block_title: acc.block_title || part.block_title,
		gutenberg_markup: [ acc.gutenberg_markup, part.gutenberg_markup ].filter( Boolean ).join( '\n\n' ),
		structures,
		custom_css: css,
		custom_js: js,
		required_media: [ ...new Set( media ) ],
		design_assets: part.design_assets || acc.design_assets,
	};
}

function sourceType( path, file ) {
	if ( path === 'figma' ) {
		return 'figma-api';
	}
	/*
	 * `auto-zip` lets Zip_Router sniff the archive, which is the same call
	 * bin/purge-and-import-real-zips.php makes. That is what stops a ZIP
	 * imported here from compiling differently than the same ZIP on the command
	 * line — the divergence that made the two paths produce different pages.
	 */
	return file ? 'auto-zip' : 'lovable-code';
}

function hasLlm( settings ) {
	return Boolean( settings && settings.has_llm );
}

function failureTitle( phase, saveState ) {
	if ( phase === 'save' && saveState === 'unchecked' ) {
		return __( 'The save’s answer was lost on the way.', 'dxai-ui' );
	}
	if ( phase === 'save' && saveState === 'stuck' ) {
		return __( 'The save has not finished.', 'dxai-ui' );
	}
	return {
		read: __( 'The archive could not be read.', 'dxai-ui' ),
		compile: __( 'The design could not be compiled.', 'dxai-ui' ),
		save: __( 'The page could not be saved.', 'dxai-ui' ),
		crawl: __( 'Building the menu pages stopped.', 'dxai-ui' ),
	}[ phase ] || __( 'Something went wrong.', 'dxai-ui' );
}

function auditLine( audit ) {
	if ( ! audit ) {
		return __( 'Import finished.', 'dxai-ui' );
	}
	const findings = ( audit.findings || [] ).length;
	if ( findings ) {
		return sprintf(
			/* translators: %d: number of problems */
			_n( 'Import checks: %d problem found — see below.', 'Import checks: %d problems found — see below.', findings, 'dxai-ui' ),
			findings
		);
	}
	return sprintf(
		/* translators: %d: number of checks */
		__( 'Import checks: all %d passed.', 'dxai-ui' ),
		audit.checked || 0
	);
}

export default function ConvertWizard() {
	const [ step, setStep ] = useState( 'path' );
	const [ path, setPath ] = useState( '' );
	const [ scope, setScope ] = useState( 'page' );
	const [ createMissingPages, setCreateMissingPages ] = useState( false );
	// The menu pages the person unticked (paths); the rest are built.
	const [ pagesSkipped, setPagesSkipped ] = useState( [] );
	// The old site's address when the person types it (the menu's links did not show it, or show another).
	const [ pagesOrigin, setPagesOrigin ] = useState( '' );
	const [ fidelity, setFidelity ] = useState( 'auto' );
	const [ figmaUrl, setFigmaUrl ] = useState( '' );
	const [ nodeId, setNodeId ] = useState( '' );
	const [ file, setFile ] = useState( null );
	const [ code, setCode ] = useState( '' );
	const [ css, setCss ] = useState( '' );
	const [ drag, setDrag ] = useState( false );
	const [ settings, setSettings ] = useState( null );
	const [ catalog, setCatalog ] = useState( [] );
	const [ keys, setKeys ] = useState( {} );
	const [ figmaToken, setFigmaToken ] = useState( '' );
	const [ logs, setLogs ] = useState( [] );
	const [ showLogs, setShowLogs ] = useState( false );
	const [ harvest, setHarvest ] = useState( null );
	const [ detected, setDetected ] = useState( '' );
	const [ result, setResult ] = useState( null );
	const [ structures, setStructures ] = useState( [] );
	const [ preview, setPreview ] = useState( null );
	const [ viewport, setViewport ] = useState( 'desktop' );
	const [ showMarkup, setShowMarkup ] = useState( false );
	const [ showAdjust, setShowAdjust ] = useState( false );
	const [ saved, setSaved ] = useState( null );
	const [ crawl, setCrawl ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	// The import's phases, the failure (if any) and what the crawl came to.
	const [ progress, dispatch ] = useReducer( progressReducer, undefined, initialProgress );
	const [ failure, setFailure ] = useState( null );
	const [ crawlRan, setCrawlRan ] = useState( false );
	const [ crawlStopped, setCrawlStopped ] = useState( '' );
	// The header and footer: '' until the person picks (the offered answer
	// applies), then 'install' or 'keep'; and what the last save was asked.
	const [ chromeChoice, setChromeChoice ] = useState( '' );
	const [ chromeAsked, setChromeAsked ] = useState( '' );
	const chromeAskedRef = useRef( '' );
	// import-info's header/footer facts for this archive and scope ({ key, info }).
	const [ chromeScoped, setChromeScoped ] = useState( null );
	// Engine, upload limit and admin links: printed into the page, then refreshed.
	const [ info, setInfo ] = useState( () => {
		const printed = window.dxaiUI && window.dxaiUI.import;
		return printed && ! Array.isArray( printed ) ? printed : {};
	} );

	const runToken = useRef( 0 );
	const crawlStop = useRef( null );
	// The pending /save-status read, while a save's lost answer is looked for.
	const saveWatch = useRef( 0 );
	const crawlSeen = useRef( -1 );
	const crawlLast = useRef( null );
	const progressHeading = useRef( null );
	const doneHeading = useRef( null );
	const errorHeading = useRef( null );
	const lastStep = useRef( step );

	const engine = info.engine && info.engine.id !== undefined ? info.engine : null;
	const links = info.links || {};
	// What this server's save does with the header and footer (import-info
	// `chrome`); absent — an older server — means template parts, as it was.
	const chromeBuilt = {
		menus: Boolean( info.chrome && info.chrome.menus ),
		widgets: Boolean( info.chrome && info.chrome.widgets ),
	};
	/*
	 * Install the design's header and footer in Menus / Widgets, or keep the
	 * site's: asked on the options step and again before the save, answered
	 * first as import-info says for this theme (`chrome_default`), and sent
	 * as the save's `chrome`. '' when this server offers no choice.
	 */
	const archiveName = result?.source_name || file?.name || '';
	// The answer for THIS import: Chrome_Choice resolves it per archive and scope.
	const chromeKey = scope + '|' + archiveName;
	const scopedInfo = chromeScoped && chromeScoped.key === chromeKey ? chromeScoped.info : null;
	const facts = useMemo(
		() => chromeFacts( scopedInfo ? { ...info, ...scopedInfo } : info, {
			title: result?.block_title || '',
			source: archiveName,
			structures,
			scope,
			scoped: Boolean( scopedInfo ),
		} ),
		[ info, scopedInfo, result, archiveName, structures, scope ]
	);
	const chromeChosen = facts.offered ? ( isChoice( chromeChoice ) ? chromeChoice : facts.defaultChoice ) : '';
	/*
	 * Ask import-info again whenever the scope or the archive changes: its
	 * automatic answer depends on both (a one-page import keeps the site's
	 * header and footer; a re-import of the design that holds them updates
	 * them). Read-only. Only a server that knows the choice is asked.
	 */
	const chromeAskable = Boolean( info.chrome_default ) || chromeBuilt.menus || chromeBuilt.widgets;
	useEffect( () => {
		if ( ! chromeAskable ) {
			return undefined;
		}
		let current = true;
		const query = '?scope=' + encodeURIComponent( scope ) + ( archiveName ? '&archive=' + encodeURIComponent( archiveName ) : '' );
		apiFetch( { path: '/dxai-ui/v1/import-info' + query } )
			.then( ( res ) => {
				if ( ! current || ! res || typeof res !== 'object' || Array.isArray( res ) ) {
					return;
				}
				const picked = {};
				CHROME_INFO_KEYS.forEach( ( key ) => {
					if ( res[ key ] !== undefined ) {
						picked[ key ] = res[ key ];
					}
				} );
				setChromeScoped( { key: chromeKey, info: picked } );
			} )
			.catch( () => {} );
		return () => {
			current = false;
		};
	}, [ chromeKey, chromeAskable ] ); // eslint-disable-line react-hooks/exhaustive-deps
	const copy = chromeCopy( chromeBuilt, chromeChosen );
	// false only when the server says this account may not import (no unfiltered_html).
	const canImport = info.can_import !== false;

	function refreshInfo() {
		apiFetch( { path: '/dxai-ui/v1/import-info' } )
			.then( ( res ) => {
				if ( res && typeof res === 'object' && ! Array.isArray( res ) ) {
					setInfo( res );
				}
			} )
			.catch( () => {} );
	}

	useEffect( () => {
		apiFetch( { path: '/dxai-ui/v1/settings' } )
			.then( ( res ) => {
				setSettings( res.settings || {} );
				setCatalog( res.catalog || [] );
			} )
			.catch( () => setSettings( {} ) );
		refreshInfo();
		return () => {
			// Leaving the screen stops the crawl loop's timers (the job itself
			// carries on server-side and through WP-Cron).
			runToken.current += 1;
			if ( crawlStop.current ) {
				crawlStop.current();
			}
			window.clearTimeout( saveWatch.current );
		};
	}, [] );

	// Keyboard and screen-reader users land where the news is.
	useEffect( () => {
		const from = lastStep.current;
		lastStep.current = step;
		if ( ( step === 'run' || step === 'saving' ) && ! PROGRESS_STEPS.includes( from ) ) {
			window.requestAnimationFrame( () => progressHeading.current && progressHeading.current.focus() );
		}
		if ( step === 'success' ) {
			window.requestAnimationFrame( () => doneHeading.current && doneHeading.current.focus() );
		}
	}, [ step ] );

	useEffect( () => {
		if ( failure ) {
			window.requestAnimationFrame( () => errorHeading.current && errorHeading.current.focus() );
		}
	}, [ failure ] );

	// The save is one request: leaving mid-way would not stop it, but it would
	// hide how it ended.
	useEffect( () => {
		if ( step !== 'saving' || ! busy ) {
			return undefined;
		}
		const warn = ( event ) => {
			event.preventDefault();
			event.returnValue = '';
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ step, busy ] );

	const stepIndex = useMemo( () => {
		const map = { path: 0, scope: 1, file: 2, engine: 2, run: 3, preview: 4, saving: 5, crawling: 5, success: 6 };
		return map[ step ] ?? 0;
	}, [ step ] );

	const plan = useMemo( () => menuPlan( structures, result, info.max_pages || 40 ), [ structures, result, info.max_pages ] );
	const pagesEngine = engine && engine.pages === 'ai' ? 'ai' : 'design';
	const canCrawl = crawlAllowed( engine, result ? plan : null, pagesEngine );
	// The pages that will be built: the menu's, less the ones unticked. With
	// the site unknown here (relative links) the server finds them all.
	const chosenPaths = plan.origin ? plan.pages.map( ( page ) => page.path ).filter( ( p ) => ! pagesSkipped.includes( p ) ) : [];
	const crawlWanted = createMissingPages && canCrawl && ( ! plan.origin || plan.pages.length === 0 || chosenPaths.length > 0 );
	const showCrawlRow = [ 'saving', 'crawling', 'success' ].includes( step ) ? crawlRan : crawlWanted;
	// A header/footer row is shown when the server builds or offers that part,
	// or when a save reported it anyway (its phase then has an entry).
	const showMenusRow = chromeBuilt.menus || facts.offered || Boolean( progress.phases.menus );
	const showWidgetsRow = chromeBuilt.widgets || facts.offered || Boolean( progress.phases.widgets );
	// Each row is named for the mode that ran once the save says (a refused
	// install is kept as a template part); until then for the one asked.
	const outcome = useMemo( () => ( saved ? chromeOutcome( saved, chromeAsked ) : null ), [ saved, chromeAsked ] );
	// Before a save (or back at the preview after one failed) the rows follow the current choice.
	const askedMode = [ 'saving', 'crawling', 'success' ].includes( step ) && chromeAsked ? chromeAsked : chromeChosen;
	const headerMode = outcome && isChoice( outcome.header.mode ) ? outcome.header.mode : askedMode;
	const footerMode = outcome && isChoice( outcome.footer.mode ) ? outcome.footer.mode : askedMode;
	const phasePlan = useMemo(
		() => planPhases( { path, crawl: showCrawlRow, engine, chrome: { menus: showMenusRow, widgets: showWidgetsRow, header: headerMode, footer: footerMode } } ),
		[ path, showCrawlRow, engine, showMenusRow, showWidgetsRow, headerMode, footerMode ]
	);
	// Working time for the summary: every finished phase except the review.
	const elapsed = useMemo( () => Object.keys( progress.phases ).reduce( ( sum, id ) => {
		const p = progress.phases[ id ];
		return id !== 'review' && p && p.started && p.ended ? sum + ( p.ended - p.started ) : sum;
	}, 0 ), [ progress ] );

	function formBody( chunkIndex ) {
		const data = new window.FormData();
		data.append( 'source_type', sourceType( path, file ) );
		data.append( 'figma_url', figmaUrl );
		data.append( 'node_id', nodeId );
		data.append( 'code', code );
		data.append( 'css', css );
		if ( file ) {
			data.append( 'file', file );
		}
		if ( typeof chunkIndex === 'number' ) {
			data.append( 'chunk', String( chunkIndex ) );
		}
		data.append( 'fidelity', fidelity || 'auto' );
		return data;
	}

	function fileReady() {
		if ( path === 'figma' ) {
			const tokenOk = Boolean( settings?.has_figma || figmaToken );
			return figmaUrl.trim() !== '' && tokenOk;
		}
		if ( path === 'code' ) {
			return code.trim() !== '';
		}
		return Boolean( file ) || code.trim() !== '';
	}

	function goFileNext() {
		setError( '' );
		if ( path === 'figma' && figmaToken && ! settings?.has_figma ) {
			setBusy( true );
			apiFetch( {
				path: '/dxai-ui/v1/settings',
				method: 'POST',
				data: { figma_api_token: figmaToken },
			} )
				.then( ( res ) => {
					setSettings( res.settings || settings );
					advanceFromFile( res.settings || settings );
				} )
				.catch( ( err ) => {
					setBusy( false );
					setError( err.message || __( 'Could not save the Figma token.', 'dxai-ui' ) );
				} );
			return;
		}
		advanceFromFile( settings );
	}

	function advanceFromFile( nextSettings ) {
		if ( path === 'figma' && ! hasLlm( nextSettings ) ) {
			setStep( 'engine' );
			return;
		}
		startConvert();
	}

	function saveEngineAndRun() {
		setBusy( true );
		setError( '' );
		apiFetch( {
			path: '/dxai-ui/v1/settings',
			method: 'POST',
			data: keys,
		} )
			.then( ( res ) => {
				setSettings( res.settings || {} );
				refreshInfo();
				if ( ! hasLlm( res.settings ) ) {
					setBusy( false );
					setError( __( 'Add at least one engine key to convert.', 'dxai-ui' ) );
					return;
				}
				startConvert();
			} )
			.catch( ( err ) => {
				setBusy( false );
				setError( err.message || __( 'Could not save keys.', 'dxai-ui' ) );
			} );
	}

	/*
	 * Upload progress for one phase: a real byte count while the browser is
	 * still sending the archive, then an honest "the server is on it" until
	 * the answer arrives. Throttled — progress events fire many times a second.
	 */
	function uploadWatcher( id, token ) {
		let last = 0;
		return ( evt ) => {
			if ( token !== runToken.current || ! file ) {
				return;
			}
			if ( evt.sent ) {
				dispatch( {
					type: 'detail',
					id,
					meter: null,
					detail: id === 'read'
						? __( 'Uploaded. The server is unpacking the archive and reading what is in it…', 'dxai-ui' )
						: __( 'Uploaded. The server is compiling the design into blocks…', 'dxai-ui' ),
				} );
				return;
			}
			const stamp = Date.now();
			if ( ! evt.total || stamp - last < 200 ) {
				return;
			}
			last = stamp;
			const text = sprintf(
				/* translators: 1: bytes sent, 2: file size */
				__( '%1$s of %2$s uploaded', 'dxai-ui' ),
				formatBytes( evt.loaded ),
				formatBytes( evt.total )
			);
			dispatch( {
				type: 'detail',
				id,
				detail: ( id === 'read' ? __( 'Uploading the archive — ', 'dxai-ui' ) : __( 'Sending the archive to the compiler — ', 'dxai-ui' ) ) + text,
				meter: { value: evt.loaded, max: evt.total, text },
			} );
		};
	}

	function startConvert() {
		runToken.current += 1;
		const token = runToken.current;
		if ( crawlStop.current ) {
			crawlStop.current();
		}
		setStep( 'run' );
		setLogs( [] );
		setError( '' );
		setFailure( null );
		setSaved( null );
		setCrawl( null );
		setCrawlRan( false );
		setCrawlStopped( '' );
		setBusy( true );
		dispatch( { type: 'reset' } );

		let phase = 'read';
		let readDetail = __( 'Reading the pasted code…', 'dxai-ui' );
		if ( file ) {
			readDetail = sprintf(
				/* translators: 1: file name, 2: file size */
				__( 'Sending %1$s (%2$s) to the server…', 'dxai-ui' ),
				file.name,
				formatBytes( file.size )
			);
		} else if ( path === 'figma' ) {
			readDetail = __( 'Asking Figma for the file…', 'dxai-ui' );
		}
		dispatch( { type: 'start', id: 'read', detail: readDetail } );

		postForm( 'process-source', formBody(), uploadWatcher( 'read', token ) )
			.then( async ( res ) => {
				if ( token !== runToken.current ) {
					return 'stale';
				}
				if ( res.logs ) {
					setLogs( res.logs );
				}
				const readHarvest = res.source?.payload?.harvest || null;
				if ( readHarvest ) {
					setHarvest( readHarvest );
				}
				// Which compiler the archive turned out to call for.
				if ( res.source?.kind ) {
					setDetected( res.source.kind );
				}
				const found = [ KINDS[ res.source?.kind ] || res.source?.kind || '' ];
				if ( readHarvest?.summary ) {
					found.push( sprintf(
						/* translators: 1: images, 2: fonts */
						__( '%1$d images, %2$d fonts', 'dxai-ui' ),
						readHarvest.summary.images || 0,
						readHarvest.summary.fonts || 0
					) );
				}
				dispatch( { type: 'done', id: 'read', detail: found.filter( Boolean ).join( ' · ' ) } );

				phase = 'compile';
				dispatch( {
					type: 'start',
					id: 'compile',
					detail: file ? __( 'Sending the archive to the compiler…', 'dxai-ui' ) : __( 'Compiling the design into blocks…', 'dxai-ui' ),
				} );
				let generated = null;
				let last = await postForm( 'generate-block', formBody(), uploadWatcher( 'compile', token ) );
				generated = last.result || null;
				while (
					token === runToken.current &&
					generated?.compiler !== 'source' &&
					generated?.chunk &&
					typeof generated.chunk.index === 'number' &&
					generated.chunk.index < generated.chunk.total - 1
				) {
					if ( last.logs ) {
						setLogs( ( current ) => current.concat( last.logs ) );
					}
					dispatch( {
						type: 'detail',
						id: 'compile',
						detail: sprintf(
							/* translators: 1: part number, 2: part count, 3: part name */
							__( 'Part %1$d of %2$d: %3$s', 'dxai-ui' ),
							generated.chunk.index + 2,
							generated.chunk.total,
							generated.chunk.title || ''
						),
						meter: { value: generated.chunk.index + 1, max: generated.chunk.total, text: sprintf(
							/* translators: 1: parts done, 2: part count */
							__( '%1$d of %2$d parts compiled', 'dxai-ui' ),
							generated.chunk.index + 1,
							generated.chunk.total
						) },
					} );
					last = await postForm( 'generate-block', formBody( generated.chunk.index + 1 ) );
					generated = mergeGeneration( generated, last.result );
				}
				if ( token !== runToken.current ) {
					return 'stale';
				}
				if ( last.logs ) {
					setLogs( ( current ) => current.concat( last.logs ) );
				}
				if ( last.source?.payload?.harvest ) {
					setHarvest( last.source.payload.harvest );
				}
				const next = ( generated?.structures || [] ).map( ( item, index ) => ( {
					...item,
					included: item.included !== false,
					key: String( index ),
				} ) );
				setResult( generated );
				setStructures( next );
				dispatch( { type: 'detail', id: 'compile', meter: null, detail: __( 'Rendering the preview…', 'dxai-ui' ) } );
				await loadPreview( generated, next );
				const score = generated?.fidelity_score?.score;
				dispatch( {
					type: 'done',
					id: 'compile',
					detail: sprintf(
						/* translators: %d: number of sections */
						_n( '%d section compiled', '%d sections compiled', next.length, 'dxai-ui' ),
						next.length
					) + ( typeof score === 'number' ? ' · ' + sprintf(
						/* translators: %d: fidelity score out of 100 */
						__( 'fidelity %d/100', 'dxai-ui' ),
						score
					) : '' ),
				} );
				dispatch( { type: 'wait', id: 'review', detail: __( 'Nothing is saved yet. Check the preview, then choose Add to WordPress.', 'dxai-ui' ) } );
				return 'ok';
			} )
			.then( ( outcome ) => {
				if ( outcome === 'ok' ) {
					setStep( 'preview' );
				}
			} )
			.catch( ( err ) => {
				if ( token !== runToken.current ) {
					return;
				}
				dispatch( { type: 'fail', id: phase, error: err } );
				setFailure( { ...describeFailure( err, phase ), title: failureTitle( phase ) } );
			} )
			.finally( () => {
				if ( token === runToken.current ) {
					setBusy( false );
				}
			} );
	}

	function loadPreview( generated, nextStructures ) {
		const markup = markupFromStructures( nextStructures ) || generated?.gutenberg_markup || '';
		return apiFetch( {
			path: '/dxai-ui/v1/preview',
			method: 'POST',
			data: {
				gutenberg_markup: markup,
				custom_css: generated?.custom_css || '',
				custom_js: generated?.custom_js || '',
				structures: nextStructures,
				design_css_raw: generated?.design_css_raw || '',
				wrapper_class: generated?.wrapper_class || '',
			},
		} )
			.then( ( res ) => setPreview( res ) )
			.catch( ( err ) => {
				setPreview( { html: '', css: '' } );
				setError( err.message || __( 'Preview failed, but Gutenberg was still generated. You can add it to WordPress.', 'dxai-ui' ) );
			} );
	}

	function updateStructure( index, patch ) {
		setStructures( ( current ) => current.map( ( item, i ) => ( i === index ? { ...item, ...patch } : item ) ) );
	}

	function addToWordPress() {
		if ( ! result ) {
			return;
		}
		const token = runToken.current;
		const crawlOn = crawlWanted;
		const included = structures.filter( ( item ) => item.included !== false ).length;
		// What this save is asked to do with the header and footer.
		const asked = chromeChosen;
		chromeAskedRef.current = asked;
		setChromeAsked( asked );
		setCrawlRan( crawlOn );
		setCrawlStopped( '' );
		setCrawl( null );
		setFailure( null );
		setBusy( true );
		setError( '' );
		setStep( 'saving' );
		if ( progress.phases.review?.state === 'waiting' || ! progress.phases.review ) {
			dispatch( {
				type: 'done',
				id: 'review',
				detail: sprintf(
					/* translators: 1: pieces included, 2: pieces in the design */
					__( '%1$d of %2$d pieces included', 'dxai-ui' ),
					included,
					structures.length
				),
			} );
		}
		[ 'menus', 'widgets', 'crawl', 'finish' ].forEach( ( id ) => dispatch( { type: 'clear', id } ) );
		dispatch( {
			type: 'start',
			id: 'save',
			force: true,
			detail: copy.save,
		} );
		// One token per attempt: /save-status says how THIS save ended if its
		// answer is lost (Converter_Controller::save_pattern()).
		const attempt = importToken();
		/*
		 * No browser-side Tailwind build.
		 *
		 * This used to compile the page's classes with @tailwindcss/browser
		 * from a bare `@import "tailwindcss"` — Tailwind's DEFAULT theme, with
		 * none of the design's own tokens, and v4 even for a v3 design. The
		 * server prefers a supplied sheet over its own engine
		 * (Tailwind_Purger::compile), so the admin path shipped pages styled
		 * with default colours, radii and fonts while the same ZIP on the
		 * command line came out right. The server compiles against the design's
		 * full source, its `@theme` and its tailwind.config, so letting it do
		 * the work is what makes the two paths the same conversion.
		 */
		apiFetch( {
			path: '/dxai-ui/v1/save-pattern',
			method: 'POST',
			data: {
				...result,
				structures,
				// Building the menu pages is a whole-site import; the scope
				// only ever decides whether the crawl runs.
				scope: crawlOn ? 'site' : scope,
				synced: true,
				create_missing_pages: crawlOn,
				// Built from the design's own sections unless this site asks for AI;
				// only the ticked pages when some were unticked.
				pages_engine: pagesEngine,
				...( crawlOn && plan.origin && plan.pages.length > 0 && pagesSkipped.length > 0 ? { pages_only: chosenPaths } : {} ),
				...( crawlOn && pagesOrigin.trim() ? { pages_origin: pagesOrigin.trim() } : {} ),
				// The crawl runs as a job, a step per request (see runCrawl):
				// in one request it outlived every real host's time limit.
				defer_crawl: crawlOn,
				design_assets: harvest || result.design_assets || {},
				wrapper_class: result.wrapper_class || '',
				design_css_raw: result.design_css_raw || '',
				import_token: attempt,
				// 'install': the header into Appearance › Menus and the footer into
				// Appearance › Widgets; 'keep': both stay template parts of the
				// design's page and the site's menus and widgets are not touched.
				// Not sent to a server that offers no choice.
				...( asked ? { chrome: asked } : {} ),
			},
		} )
			.then( ( res ) => {
				if ( token === runToken.current ) {
					onSaved( res, crawlOn, '' );
				}
			} )
			.catch( ( err ) => {
				if ( token !== runToken.current ) {
					return;
				}
				// A proxy's 504, an error page, a dropped connection: the save
				// may still be running, or done. Ask before offering it again.
				if ( lostAnswer( err ) ) {
					watchSave( { attempt, cause: err, crawlOn, reopen: false } );
					return;
				}
				failSave( err, { attempt, cause: err, crawlOn, saveState: 'failed' } );
			} );
	}

	/** The save answered — directly, or through /save-status after its answer was lost. */
	function onSaved( res, crawlOn, note ) {
		window.clearTimeout( saveWatch.current );
		setSaved( res );
		setBusy( false );
		setFailure( null );
		const title = result?.block_title || '';
		dispatch( {
			type: 'done',
			id: 'save',
			detail: ( res?.page?.id
				? sprintf(
					/* translators: 1: page title, 2: page ID */
					__( 'Page “%1$s” saved (ID %2$d).', 'dxai-ui' ),
					title,
					res.page.id
				)
				: __( 'Saved.', 'dxai-ui' ) ) + ( note ? ' ' + note : '' ),
		} );
		/*
		 * The header and footer rows: from the save's report and the mode that
		 * actually ran (installed, kept as asked, or kept because the install
		 * was refused — with the server's reason); when the save said nothing,
		 * "not reported" only where this server said it would build or offer
		 * that part — a server that writes template parts gets no row.
		 */
		const report = chromeReport( res );
		const ran = chromeOutcome( res, chromeAskedRef.current );
		if ( chromeBuilt.menus || facts.offered || report.menus !== undefined || report.menusError ) {
			dispatch( chromePhaseAction( 'menus', report, links, ran.header ) );
		}
		if ( chromeBuilt.widgets || facts.offered || report.widgets !== undefined || report.widgetsError ) {
			dispatch( chromePhaseAction( 'widgets', report, links, ran.footer ) );
		}
		if ( res && res.crawl_job ) {
			runCrawl( res.crawl_job, res );
			return;
		}
		if ( crawlOn ) {
			dispatch( { type: 'skip', id: 'crawl', detail: __( 'The server did not start building the menu pages for this import.', 'dxai-ui' ) } );
		}
		completeImport( res );
	}

	/*
	 * The save's answer did not arrive. That is not the same as the save
	 * failing: a proxy stops waiting after 60–100 s while PHP carries on. So
	 * the screen asks /save-status what WordPress recorded for this attempt,
	 * every few seconds, and says what it hears:
	 *
	 *   done    — the save finished; carry on with its answer, and drive the
	 *             crawl it planned (never a second one).
	 *   running — still saving; keep waiting, with the time it has taken.
	 *   failed  — WordPress recorded the error (a PHP fatal included).
	 *   unknown — no record, asked a few times: the request never reached
	 *             WordPress, so nothing was written and saving again is safe.
	 *
	 * Only when the status itself cannot be read does the screen fall back
	 * to "the answer was lost" — and then, for an import that builds the menu
	 * pages, it offers to check again before it offers to save again.
	 */
	function watchSave( { attempt, cause, crawlOn, reopen, limit } ) {
		const token = runToken.current;
		const patience = limit || SAVE_STUCK_MS;
		const why = lostWhy( cause );
		let misses = 0;
		let unknowns = 0;
		// Checking again from the failure: the phase is reopened once, then updated.
		let reopenNext = Boolean( reopen );
		window.clearTimeout( saveWatch.current );
		setFailure( null );
		setBusy( true );
		setStep( 'saving' );
		const say = ( detail ) => {
			dispatch( { type: reopenNext ? 'start' : 'detail', id: 'save', force: true, watching: true, detail } );
			reopenNext = false;
		};
		say( sprintf(
			/* translators: %s: what went wrong, e.g. "HTTP 504" */
			__( 'The answer did not come back (%s). That does not mean the save failed — asking WordPress how it is going…', 'dxai-ui' ),
			why
		) );
		const later = ( ms ) => {
			saveWatch.current = window.setTimeout( ask, ms );
		};
		const live = () => token === runToken.current;
		const ask = () => {
			if ( ! live() ) {
				return;
			}
			apiFetch( { path: '/dxai-ui/v1/save-status?token=' + encodeURIComponent( attempt ) } )
				.then( ( rec ) => {
					if ( ! live() ) {
						return;
					}
					misses = 0;
					const state = rec && rec.state;
					if ( state === 'done' && rec.payload && typeof rec.payload === 'object' ) {
						onSaved( rec.payload, crawlOn, __( 'The connection was cut before WordPress answered, but the save finished — this screen asked WordPress for the result.', 'dxai-ui' ) );
						return;
					}
					if ( state === 'failed' ) {
						failSave(
							{ code: rec.code || 'dxai_ui_save', message: rec.message || '', data: { status: rec.status || 0 } },
							{ attempt, cause, crawlOn, saveState: 'failed' }
						);
						return;
					}
					if ( state === 'running' ) {
						unknowns = 0;
						const age = Math.max( 0, ( Number( rec.now ) || 0 ) - ( Number( rec.started ) || 0 ) ) * 1000;
						if ( age > patience ) {
							failSave(
								{
									code: 'dxai_ui_save_stuck',
									message: sprintf(
										/* translators: %s: duration, e.g. 10:02 */
										__( 'WordPress started this save %s ago and has not recorded its end.', 'dxai-ui' ),
										formatDuration( age )
									),
									data: {},
								},
								{ attempt, cause, crawlOn, saveState: 'stuck', age }
							);
							return;
						}
						say( sprintf(
							/* translators: 1: what went wrong, e.g. "HTTP 504", 2: duration */
							__( 'The connection was cut (%1$s), but WordPress is still saving — it started %2$s ago. Waiting for it to finish; nothing has failed.', 'dxai-ui' ),
							why,
							formatDuration( age )
						) );
						later( 4000 );
						return;
					}
					unknowns += 1;
					if ( unknowns >= SAVE_UNKNOWN_CHECKS ) {
						failSave( cause, { attempt, cause, crawlOn, saveState: 'unknown' } );
						return;
					}
					// A status read can overtake a save still queued for a PHP worker.
					later( 3000 );
				} )
				.catch( ( err ) => {
					if ( ! live() ) {
						return;
					}
					misses += 1;
					if ( misses >= SAVE_STATUS_MISSES ) {
						failSave( cause, { attempt, cause, crawlOn, saveState: 'unchecked' } );
						return;
					}
					say( sprintf(
						/* translators: 1: what went wrong with the save, 2: what went wrong with the status read, 3: attempt number, 4: attempts allowed */
						__( 'The answer did not come back (%1$s), and asking WordPress how the save is going failed too (%2$s). Trying again — %3$d of %4$d…', 'dxai-ui' ),
						why,
						lostWhy( err ),
						misses,
						SAVE_STATUS_MISSES
					) );
					later( 5000 );
				} );
		};
		later( 1500 );
	}

	function failSave( err, context ) {
		window.clearTimeout( saveWatch.current );
		setBusy( false );
		dispatch( { type: 'fail', id: 'save', error: err } );
		setFailure( {
			...describeFailure( err, 'save', { save: context.saveState, crawl: context.crawlOn } ),
			title: failureTitle( 'save', context.saveState ),
			...context,
		} );
	}

	/** The finishing pass is the save's own for a page import — it only reads the audit. */
	function completeImport( finalSaved, stoppedNote ) {
		dispatch( { type: 'start', id: 'finish', detail: __( 'Reading the import checks…', 'dxai-ui' ) } );
		dispatch( {
			type: finalSaved?.audit?.findings?.length ? 'warn' : 'done',
			id: 'finish',
			detail: auditLine( finalSaved?.audit ),
		} );
		dispatch( { type: 'end' } );
		setCrawlStopped( stoppedNote || '' );
		setSaved( finalSaved );
		setFailure( null );
		setStep( 'success' );
	}

	/*
	 * Advance the site-from-menu crawl until it is done. Each call does as
	 * much as fits in a short time budget on the server, always at least one
	 * step — fetch a page, sideload some of its images, restyle it, save it —
	 * and the last one runs the finishing pass (menus, links, colour tokens).
	 *
	 * A call can fail without the crawl failing: an AI restyle can take longer
	 * than a proxy waits, and the server finishes that step anyway. So a
	 * failed call is retried after a pause, and a "busy" answer (the previous
	 * step is still running) is simply waited out. Only a job the server no
	 * longer has ends the loop with an error.
	 *
	 * While a step request is out — one AI answer can take two minutes — the
	 * crawl-status route is read every few seconds, so the page being worked on
	 * and the phase it is in stay current instead of freezing on the last answer.
	 */
	function runCrawl( job, base ) {
		const token = runToken.current;
		if ( crawlStop.current ) {
			crawlStop.current();
		}
		setStep( 'crawling' );
		setFailure( null );
		setCrawl( ( current ) => current || { total: 0, index: 0, label: '', phase: '', created: 0, errors: 0 } );
		crawlSeen.current = -1;
		dispatch( { type: 'start', id: 'crawl', force: true, detail: __( 'Planning the pages from the menu…', 'dxai-ui' ) } );

		let failures = 0;
		let inFlight = false;
		let stopped = false;
		let poller = 0;
		const stop = () => {
			stopped = true;
			window.clearInterval( poller );
		};
		crawlStop.current = stop;
		const live = () => ! stopped && token === runToken.current;

		const apply = ( p, extra ) => {
			if ( ! live() || ! p || typeof p !== 'object' ) {
				return;
			}
			const key = crawlKey( p );
			if ( key < crawlSeen.current ) {
				return;
			}
			crawlSeen.current = key;
			crawlLast.current = p;
			setCrawl( { ...p, ...( extra || {} ) } );
			if ( p.finishing && ! p.done ) {
				dispatch( {
					type: p.errors ? 'warn' : 'done',
					id: 'crawl',
					detail: crawlTally( p ),
				} );
				dispatch( { type: 'start', id: 'finish', detail: __( 'Linking the menus to the new pages, rewriting links and applying the colour tokens…', 'dxai-ui' ) } );
			} else if ( p.total > 0 && ! p.done ) {
				dispatch( {
					type: 'detail',
					id: 'crawl',
					detail: sprintf(
						/* translators: 1: page number, 2: page count, 3: page name */
						__( 'Page %1$d of %2$d: %3$s', 'dxai-ui' ),
						Math.min( ( p.index || 0 ) + 1, p.total ),
						p.total,
						p.label || p.path || ''
					),
				} );
			}
		};

		const tick = () => {
			if ( ! live() ) {
				return;
			}
			inFlight = true;
			apiFetch( { path: '/dxai-ui/v1/crawl-step', method: 'POST', data: { job } } )
				.then( ( p ) => {
					inFlight = false;
					if ( ! live() ) {
						return;
					}
					failures = 0;
					apply( p );
					if ( p && p.done ) {
						stop();
						finishCrawl( p, base );
						return;
					}
					window.setTimeout( tick, p && p.busy ? 4000 : 300 );
				} )
				.catch( ( err ) => {
					inFlight = false;
					if ( ! live() ) {
						return;
					}
					if ( err && err.code === 'dxai_ui_crawl_job' ) {
						stop();
						failCrawl( err, job, base, 'missing' );
						return;
					}
					failures += 1;
					const why = lostWhy( err );
					setCrawl( ( current ) => ( { ...( current || {} ), retrying: failures, lastError: why } ) );
					if ( failures > 30 ) {
						stop();
						failCrawl(
							{ code: err?.code || 'crawl_stalled', message: __( 'The crawl stopped answering after 30 tries in a row.', 'dxai-ui' ), data: err?.data },
							job,
							base,
							'stalled'
						);
						return;
					}
					window.setTimeout( tick, 5000 );
				} );
		};

		poller = window.setInterval( () => {
			if ( ! inFlight || ! live() ) {
				return;
			}
			apiFetch( { path: '/dxai-ui/v1/crawl-status?job=' + encodeURIComponent( job ) } )
				.then( ( p ) => {
					// A status read is never "done" business: finishCrawl belongs to the step.
					if ( p && ! p.done ) {
						apply( p );
					}
				} )
				.catch( () => {} );
		}, 3000 );

		tick();
	}

	function crawlTally( p ) {
		return [
			sprintf( /* translators: %d: pages created */ _n( '%d page created', '%d pages created', p.created || 0, 'dxai-ui' ), p.created || 0 ),
			p.ai !== false ? sprintf( /* translators: %d: pages rebuilt with AI */ _n( '%d rebuilt with AI', '%d rebuilt with AI', p.restyled || 0, 'dxai-ui' ), p.restyled || 0 ) : '',
			sprintf( /* translators: %d: errors */ _n( '%d error', '%d errors', p.errors || 0, 'dxai-ui' ), p.errors || 0 ),
		].filter( Boolean ).join( ' · ' );
	}

	function finishCrawl( p, base ) {
		const r = p.result || {};
		const merged = {
			...base,
			pages_created: r.pages_created || [],
			pages_skipped: r.pages_skipped || [],
			crawl_errors: r.crawl_errors || [],
			pages_restyled: r.pages_restyled || 0,
			restyle_engine: r.restyle_engine || base.restyle_engine || '',
		};
		// The finishing pass rebuilds the menus with the new pages; when it
		// reports them, that report is the one that stands.
		const report = chromeReport( r );
		if ( report.menus !== undefined || report.menusError ) {
			merged.chrome = { ...( base.chrome || {} ), ...( r.chrome || {} ) };
			dispatch( chromePhaseAction( 'menus', report, links, chromeOutcome( merged, chromeAskedRef.current ).header ) );
		}
		if ( report.widgets !== undefined || report.widgetsError ) {
			merged.chrome = { ...( merged.chrome || base.chrome || {} ), ...( r.chrome || {} ) };
			dispatch( chromePhaseAction( 'widgets', report, links, chromeOutcome( merged, chromeAskedRef.current ).footer ) );
		}
		const tally = {
			created: merged.pages_created.length,
			restyled: merged.pages_restyled,
			errors: merged.crawl_errors.length,
			ai: p.ai,
		};
		dispatch( { type: tally.errors ? 'warn' : 'done', id: 'crawl', detail: crawlTally( tally ) } );
		dispatch( { type: 'start', id: 'finish', detail: '' } );
		dispatch( {
			type: 'done',
			id: 'finish',
			detail: __( 'Menus linked to the new pages, links rewritten, colour tokens applied.', 'dxai-ui' ) + ' ' + auditLine( merged.audit ),
		} );
		dispatch( { type: 'end' } );
		setSaved( merged );
		setStep( 'success' );
	}

	function failCrawl( err, job, base, kind ) {
		const last = crawlLast.current;
		dispatch( {
			type: 'fail',
			id: 'crawl',
			error: err,
			detail: last && last.total > 0 && ! last.finishing
				? sprintf(
					/* translators: 1: page number, 2: page count, 3: page name */
					__( 'Stopped at page %1$d of %2$d: %3$s', 'dxai-ui' ),
					Math.min( ( last.index || 0 ) + 1, last.total ),
					last.total,
					last.label || last.path || ''
				)
				: '',
		} );
		setFailure( {
			...describeFailure( err, 'crawl' ),
			title: kind === 'missing'
				? __( 'The crawl is no longer on the server.', 'dxai-ui' )
				: __( 'The crawl stopped answering.', 'dxai-ui' ),
			hint: kind === 'missing'
				? __( 'The page itself is saved. Start the import again to build the menu pages.', 'dxai-ui' )
				: __( 'If WordPress cron runs on this site, the crawl carries on in the background and the pages appear in the Library. You can also keep trying from here.', 'dxai-ui' ),
			kind,
			job,
			base,
		} );
	}

	function crawlPartial( base ) {
		const c = crawl || {};
		const created = ( c.queue || [] ).filter( ( row ) => row.state === 'created' ).map( ( row ) => ( { id: row.id, label: row.label, path: row.path } ) );
		return {
			...base,
			pages_created: created,
			pages_skipped: ( c.queue || [] ).filter( ( row ) => row.state === 'skipped' ).map( ( row ) => ( { label: row.label, path: row.path, reason: row.reason } ) ),
			crawl_errors: ( c.queue || [] ).filter( ( row ) => row.state === 'error' ).map( ( row ) => ( { label: row.label, url: row.path, message: row.message } ) ),
			// The server counts a restyle as soon as the AI answers; a page that
			// was rebuilt but never saved is not one to report.
			pages_restyled: Math.min( c.restyled || 0, created.length ),
		};
	}

	function failureActions() {
		if ( ! failure ) {
			return [];
		}
		if ( failure.phase === 'read' || failure.phase === 'compile' ) {
			return [
				{ label: __( 'Try again', 'dxai-ui' ), primary: true, onClick: startConvert },
				{
					label: path === 'design' ? __( 'Choose another file', 'dxai-ui' ) : __( 'Back', 'dxai-ui' ),
					onClick: () => {
						setFailure( null );
						setStep( 'file' );
					},
				},
			];
		}
		if ( failure.phase === 'save' ) {
			const back = {
				label: __( 'Back to the preview', 'dxai-ui' ),
				onClick: () => {
					setFailure( null );
					dispatch( { type: 'clear', id: 'save' } );
					dispatch( { type: 'wait', id: 'review', detail: __( 'Check the preview, then choose Add to WordPress.', 'dxai-ui' ) } );
					setStep( 'preview' );
				},
			};
			const library = links.library ? { label: __( 'Check the Library', 'dxai-ui' ), href: links.library } : null;
			// Only when WordPress could not say how the save ended is it worth asking again.
			const unsure = failure.saveState === 'unchecked' || failure.saveState === 'stuck';
			const check = unsure && failure.attempt
				? {
					label: failure.saveState === 'stuck' ? __( 'Keep waiting', 'dxai-ui' ) : __( 'Check again', 'dxai-ui' ),
					onClick: () => watchSave( {
						attempt: failure.attempt,
						cause: failure.cause,
						crawlOn: failure.crawlOn,
						reopen: true,
						limit: failure.saveState === 'stuck' ? ( failure.age || 0 ) + SAVE_STUCK_MS : SAVE_STUCK_MS,
					} ),
				}
				: null;
			/*
			 * A save that may still be running must not be the first thing
			 * offered again when the import builds the menu pages: the second
			 * save plans a second crawl of the same menu, and both run — every
			 * page rebuilt, and paid for, twice.
			 */
			if ( unsure && failure.crawlOn && check ) {
				return [
					{ ...check, primary: true },
					library,
					{ label: __( 'Save again anyway', 'dxai-ui' ), onClick: addToWordPress },
					back,
				].filter( Boolean );
			}
			return [
				{ label: __( 'Try again', 'dxai-ui' ), primary: true, onClick: addToWordPress },
				check,
				back,
				library,
			].filter( Boolean );
		}
		if ( failure.phase === 'crawl' ) {
			const base = failure.base || saved || {};
			const summary = {
				label: __( 'See what was saved', 'dxai-ui' ),
				onClick: () => completeImport(
					crawlPartial( base ),
					failure.kind === 'missing'
						? __( 'Building the menu pages stopped: the crawl is no longer on the server. The pages listed here were finished before it stopped.', 'dxai-ui' )
						: __( 'Building the menu pages stopped answering here. If WordPress cron runs on this site it carries on in the background; the remaining pages will appear in the Library.', 'dxai-ui' )
				),
			};
			if ( failure.kind === 'stalled' && failure.job ) {
				return [
					{ label: __( 'Keep trying', 'dxai-ui' ), primary: true, onClick: () => runCrawl( failure.job, base ) },
					summary,
				];
			}
			return [ { ...summary, primary: true }, { label: __( 'Start a new import', 'dxai-ui' ), onClick: resetGame } ];
		}
		return [];
	}

	function resetGame() {
		runToken.current += 1;
		if ( crawlStop.current ) {
			crawlStop.current();
		}
		window.clearTimeout( saveWatch.current );
		setStep( 'path' );
		setPath( '' );
		setScope( 'page' );
		setCreateMissingPages( false );
		setPagesSkipped( [] );
		setPagesOrigin( '' );
		setFile( null );
		setCode( '' );
		setCss( '' );
		setFigmaUrl( '' );
		setNodeId( '' );
		setResult( null );
		setStructures( [] );
		setPreview( null );
		setHarvest( null );
		setDetected( '' );
		setSaved( null );
		setCrawl( null );
		setCrawlRan( false );
		setCrawlStopped( '' );
		setChromeChoice( '' );
		setChromeAsked( '' );
		chromeAskedRef.current = '';
		setError( '' );
		setFailure( null );
		setLogs( [] );
		setBusy( false );
		dispatch( { type: 'reset' } );
	}

	function onDrop( event ) {
		event.preventDefault();
		setDrag( false );
		const dropped = event.dataTransfer.files[ 0 ];
		if ( dropped && /\.zip$/i.test( dropped.name ) ) {
			setFile( dropped );
		}
	}

	function toggleCrawl( on ) {
		setCreateMissingPages( on );
		// Building the menu pages is what a whole-site import is.
		if ( on ) {
			setScope( 'site' );
		}
	}

	const fontLinks = ( harvest?.fonts || [] )
		.map( ( item ) => item?.stylesheet )
		.filter( Boolean )
		.map( ( url ) => `<link rel="stylesheet" href="${ String( url ).replace( /"/g, '' ) }">` )
		.join( '' );
	const previewJs = String( preview?.js || result?.custom_js || '' ).replace( /<\/script/gi, '<\\/script' );
	const hasLucide = /data-lucide/.test( String( preview?.html || '' ) );
	// The plugin's own copy (Dashboard::assets() passes its URL), not a CDN
	// that a host's CSP or firewall may block.
	const lucideSrc = String( ( window.dxaiUI && window.dxaiUI.lucide ) || '' ).replace( /"/g, '' );
	const lucideTag = hasLucide && lucideSrc
		? '<script src="' + lucideSrc + '"><\/script>'
		: '';
	const wrapClass = [ 'dxai-ui', 'dxai-ui--0', preview?.wrapper_class || result?.wrapper_class || '' ]
		.filter( Boolean )
		.join( ' ' )
		.replace( /"/g, '' );
	const srcDoc = preview
		? `<!DOCTYPE html><html><head><meta charset="utf-8">${ fontLinks }<style>body{margin:0;}${ preview.css || '' }</style></head><body><div class="${ wrapClass }">${ preview.html || '' }</div>${ lucideTag }<script>${ previewJs }</script></body></html>`
		: '';
	const previewStats = [
		{
			label: __( 'Source', 'dxai-ui' ),
			value: detected
				? ( KINDS[ detected ] || detected )
				: ( path ? PATHS.find( ( item ) => item.id === path )?.title || path : __( 'Not selected', 'dxai-ui' ) ),
		},
		{ label: __( 'Scope', 'dxai-ui' ), value: scope === 'site' ? __( 'Whole site', 'dxai-ui' ) : __( 'One page', 'dxai-ui' ) },
		{ label: __( 'Pieces', 'dxai-ui' ), value: structures.length || 0 },
		{ label: __( 'Assets', 'dxai-ui' ), value: harvest ? ( harvest.summary?.images || 0 ) + ( harvest.summary?.videos || 0 ) + ( harvest.summary?.fonts || 0 ) : 0 },
	];
	function improveStructure( index ) {
		const item = structures[ index ];
		if ( ! item ) {
			return;
		}
		setBusy( true );
		setError( '' );
		apiFetch( {
			path: '/dxai-ui/v1/refine-structure',
			method: 'POST',
			data: {
				structure: item,
				fidelity: fidelity || 'strict',
				issues: result?.fidelity_score?.structures?.[ index ]?.issues || [],
			},
		} )
			.then( ( res ) => {
				if ( ! res?.structure ) {
					throw new Error( __( 'Refine returned nothing.', 'dxai-ui' ) );
				}
				const next = structures.map( ( row, i ) => ( i === index ? { ...row, ...res.structure, key: row.key } : row ) );
				setStructures( next );
				const nextResult = { ...result, structures: next };
				setResult( nextResult );
				return loadPreview( nextResult, next );
			} )
			.catch( ( err ) => setError( err.message || __( 'Improve with AI failed.', 'dxai-ui' ) ) )
			.finally( () => setBusy( false ) );
	}

	const activeLine = engine && engine.active && engine.active.id ? engineLine( engine.active ) : __( 'the active engine', 'dxai-ui' );
	const tooBig = file && info.max_upload > 0 && file.size > info.max_upload;
	const crawlCount = chosenPaths.length;

	return (
		<div className="dxai-screen">
			<ul className="dxai-stats">
				{ previewStats.map( ( item ) => (
					<li key={ item.label }>
						<strong>{ item.value }</strong>
						{ item.label }
					</li>
				) ) }
			</ul>
			<ol className="dxai-levels">
				{ LEVELS.map( ( item, index ) => (
					<li key={ item.id } className={ index === stepIndex ? 'is-current' : ( index < stepIndex ? 'is-done' : '' ) }>
						<span>{ index + 1 }</span>
						{ /* The third step is a drop zone, a URL or a paste box depending on the path. */ }
						{ item.id === 'file' && path === 'code'
							? __( 'Paste code', 'dxai-ui' )
							: ( item.id === 'file' && path === 'figma' ? __( 'Add URL', 'dxai-ui' ) : item.label ) }
					</li>
				) ) }
			</ol>
			<div className="dxai-panel dxai-panel--stack">
				{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }

				{ step === 'path' && (
					<section className="dxai-stage-card">
						<h3 className="dxai-step-title">{ __( 'Pick your design', 'dxai-ui' ) }</h3>
						<p className="dxai-muted">{ __( 'Start with what you have. Every path runs the same compiler, and an archive is read for what it actually contains.', 'dxai-ui' ) }</p>
						{ /* Said here, before the archive is uploaded twice only to be refused. */ }
						{ ! canImport && (
							<Notice status="error" isDismissible={ false } className="dxai-cannot-import">
								{ info.import_refusal || __( 'Your account may not import designs on this site.', 'dxai-ui' ) }
							</Notice>
						) }
						<div className="dxai-paths">
							{ PATHS.map( ( item ) => (
								<button
									key={ item.id }
									type="button"
									className={ 'dxai-path' + ( path === item.id ? ' is-selected' : '' ) }
									onClick={ () => setPath( item.id ) }
								>
									<span className={ 'dxai-path-glyph dxai-path-glyph--' + item.id } aria-hidden="true" />
									<strong>{ item.title }</strong>
									<p>{ item.blurb }</p>
									<em>{ item.hint }</em>
								</button>
							) ) }
						</div>
						<div className="dxai-actions">
							<Button variant="primary" disabled={ ! path || ! canImport } onClick={ () => setStep( 'scope' ) }>
								{ __( 'Next', 'dxai-ui' ) }
							</Button>
						</div>
					</section>
				) }

				{ step === 'scope' && (
					<section className="dxai-stage-card">
						<h3 className="dxai-step-title">{ __( 'One page or a whole site?', 'dxai-ui' ) }</h3>
						<p className="dxai-muted">{ copy.intro }</p>
						<div className="dxai-paths dxai-paths--two">
							<button
								type="button"
								className={ 'dxai-path' + ( scope === 'page' ? ' is-selected' : '' ) }
								onClick={ () => {
									setScope( 'page' );
									setCreateMissingPages( false );
								} }
							>
								<span className="dxai-path-glyph dxai-path-glyph--page" aria-hidden="true" />
								<strong>{ __( 'One page', 'dxai-ui' ) }</strong>
								<p>{ copy.page }</p>
							</button>
							<button
								type="button"
								className={ 'dxai-path' + ( scope === 'site' ? ' is-selected' : '' ) }
								onClick={ () => {
									// A whole site means its pages too: on, and asked again
									// at the review with the list to choose from.
									if ( scope !== 'site' ) {
										setCreateMissingPages( true );
									}
									setScope( 'site' );
								} }
							>
								<span className="dxai-path-glyph dxai-path-glyph--site" aria-hidden="true" />
								<strong>{ __( 'Whole site', 'dxai-ui' ) }</strong>
								<p>{ copy.site }</p>
							</button>
						</div>
						{ scope === 'site' && (
							<PagesOption
								stage="scope"
								engine={ engine }
								checked={ createMissingPages }
								onChange={ toggleCrawl }
								plan={ null }
							/>
						) }
						{ facts.offered && (
							<ChromeChoice stage="scope" facts={ facts } value={ chromeChosen } onChange={ setChromeChoice } />
						) }
						<div className="dxai-stack" style={ { marginTop: 20 } }>
							<p className="dxai-section-kicker">{ __( 'Fidelity', 'dxai-ui' ) }</p>
							<SelectControl
								label={ __( 'When to use AI refine', 'dxai-ui' ) }
								value={ fidelity }
								options={ [
									{ label: __( 'Auto — Pass 1, AI only on low confidence', 'dxai-ui' ), value: 'auto' },
									{ label: __( 'Strict — pixel-perfect refine (needs LLM key)', 'dxai-ui' ), value: 'strict' },
									{ label: __( 'Off — deterministic only', 'dxai-ui' ), value: 'off' },
								] }
								onChange={ ( value ) => setFidelity( value ) }
								help={ sprintf(
									/* translators: %s: active engine and model, e.g. Claude · claude-sonnet-5 */
									__( 'Strict mode runs a second pass with %s on flagged sections, so matches stay editable and separated.', 'dxai-ui' ),
									activeLine
								) }
							/>
						</div>
						<div className="dxai-actions">
							<Button variant="secondary" onClick={ () => setStep( 'path' ) }>{ __( 'Back', 'dxai-ui' ) }</Button>
							<Button variant="primary" onClick={ () => setStep( 'file' ) }>{ __( 'Next', 'dxai-ui' ) }</Button>
						</div>
					</section>
				) }

				{ step === 'file' && (
					<section className="dxai-stage-card">
						<h3 className="dxai-step-title">
							{ path === 'figma' && __( 'Add your Figma file', 'dxai-ui' ) }
							{ path === 'design' && __( 'Drop your design archive', 'dxai-ui' ) }
							{ path === 'code' && __( 'Paste your component', 'dxai-ui' ) }
						</h3>
						{ path === 'design' && (
							<p className="dxai-muted">
								{ __( 'Any export works. We read the file list and compile it with the right connector — you do not have to say which generator made it.', 'dxai-ui' ) }
							</p>
						) }
						{ path === 'figma' && (
							<div className="dxai-stack">
								<TextControl
									label={ __( 'Figma file URL', 'dxai-ui' ) }
									value={ figmaUrl }
									onChange={ setFigmaUrl }
									placeholder="https://www.figma.com/design/…"
								/>
								<TextControl
									label={ __( 'Node ID (optional)', 'dxai-ui' ) }
									value={ nodeId }
									onChange={ setNodeId }
								/>
								{ ! settings?.has_figma && (
									<TextControl
										label={ __( 'Figma personal access token', 'dxai-ui' ) }
										type="password"
										value={ figmaToken }
										onChange={ setFigmaToken }
										help={ __( 'Saved encrypted. Used only to read this file.', 'dxai-ui' ) }
									/>
								) }
							</div>
						) }
						{ path === 'design' && (
							<div
								className={ 'dxai-drop' + ( drag ? ' is-drag' : '' ) }
								onDragOver={ ( event ) => {
									event.preventDefault();
									setDrag( true );
								} }
								onDragLeave={ () => setDrag( false ) }
								onDrop={ onDrop }
							>
								<span className="dxai-drop-mark" aria-hidden="true" />
								<p>{ __( 'Drop a .zip here', 'dxai-ui' ) }</p>
								<em className="dxai-drop-hint">
									{ __( 'Lovable · Claude Design · Vite/React · Vue · Next.js · plain HTML', 'dxai-ui' ) }
								</em>
								<label className="dxai-file">
									<input
										type="file"
										accept=".zip"
										onChange={ ( event ) => setFile( event.target.files[ 0 ] || null ) }
									/>
									{ file ? file.name + ' · ' + formatBytes( file.size ) : __( 'or browse your files', 'dxai-ui' ) }
								</label>
							</div>
						) }
						{ tooBig && (
							<Notice status="warning" isDismissible={ false }>
								{ sprintf(
									/* translators: 1: file size, 2: the server's upload limit */
									__( 'This archive is %1$s, and this server accepts uploads up to %2$s (upload_max_filesize / post_max_size). The upload will probably be refused — ask your host to raise the limit.', 'dxai-ui' ),
									formatBytes( file.size ),
									formatBytes( info.max_upload )
								) }
							</Notice>
						) }
						{ path === 'code' && (
							<div className="dxai-stack">
								<p className="dxai-muted">{ __( 'One component, compiled into editable blocks on its own.', 'dxai-ui' ) }</p>
								<TextareaControl
									label={ __( 'JSX / TSX', 'dxai-ui' ) }
									value={ code }
									onChange={ setCode }
									rows={ 8 }
								/>
								<TextareaControl
									label={ __( 'Optional CSS', 'dxai-ui' ) }
									value={ css }
									onChange={ setCss }
									rows={ 4 }
								/>
							</div>
						) }
						<div className="dxai-actions">
							<Button variant="secondary" onClick={ () => setStep( 'scope' ) }>{ __( 'Back', 'dxai-ui' ) }</Button>
							<Button variant="primary" disabled={ ! fileReady() || busy } onClick={ goFileNext }>
								{ busy ? __( 'Saving…', 'dxai-ui' ) : __( 'Next', 'dxai-ui' ) }
							</Button>
						</div>
					</section>
				) }

				{ step === 'engine' && (
					<section className="dxai-stage-card">
						<h3 className="dxai-step-title">{ __( 'Connect an engine', 'dxai-ui' ) }</h3>
						<p className="dxai-muted">{ __( 'One key is enough. Claude, OpenAI, Grok, or DeepSeek.', 'dxai-ui' ) }</p>
						<div className="dxai-stack">
							{ catalog.map( ( item ) => (
								<TextControl
									key={ item.id }
									label={ item.label }
									type="password"
									value={ keys[ {
										claude: 'anthropic_api_key',
										openai: 'openai_api_key',
										grok: 'xai_api_key',
										deepseek: 'deepseek_api_key',
									}[ item.id ] ] || '' }
									onChange={ ( value ) => setKeys( {
										...keys,
										[ {
											claude: 'anthropic_api_key',
											openai: 'openai_api_key',
											grok: 'xai_api_key',
											deepseek: 'deepseek_api_key',
										}[ item.id ] ]: value,
									} ) }
								/>
							) ) }
							<SelectControl
								label={ __( 'Active engine', 'dxai-ui' ) }
								value={ keys.active_engine || settings?.active_engine || 'claude' }
								options={ catalog.map( ( item ) => ( { label: item.label, value: item.id } ) ) }
								onChange={ ( value ) => setKeys( { ...keys, active_engine: value } ) }
							/>
						</div>
						<div className="dxai-actions">
							<Button variant="secondary" onClick={ () => setStep( 'file' ) }>{ __( 'Back', 'dxai-ui' ) }</Button>
							<Button variant="primary" disabled={ busy } onClick={ saveEngineAndRun }>
								{ __( 'Save and convert', 'dxai-ui' ) }
							</Button>
						</div>
					</section>
				) }

				{ PROGRESS_STEPS.includes( step ) && (
					<ImportProgress
						plan={ phasePlan }
						progress={ progress }
						crawl={ crawl }
						engine={ engine }
						failure={ failure }
						actions={ failureActions() }
						headingRef={ progressHeading }
						errorRef={ errorHeading }
					>
						{ step === 'run' && (
							<div className="dxai-progress__logs">
								<button type="button" className="dxai-link" aria-expanded={ showLogs } onClick={ () => setShowLogs( ! showLogs ) }>
									{ showLogs ? __( 'Hide details', 'dxai-ui' ) : __( 'Show details', 'dxai-ui' ) }
								</button>
								{ showLogs && (
									<div className="dxai-log">
										{ logs.length ? logs.map( ( entry, index ) => (
											<div key={ index }>{ ( entry.time || '' ) + '  ' + ( entry.message || '' ) }</div>
										) ) : __( 'The server’s log appears here after each request answers.', 'dxai-ui' ) }
									</div>
								) }
							</div>
						) }
					</ImportProgress>
				) }

				{ step === 'preview' && result && (
					<section className="dxai-stage-card">
						<ImportProgress compact plan={ phasePlan } progress={ progress } crawl={ crawl } engine={ engine } />
						<h3 className="dxai-step-title" style={ { marginTop: 18 } }>{ __( 'Your WordPress preview', 'dxai-ui' ) }</h3>
						<FidelityReport result={ result } harvest={ harvest } structures={ structures } />
						{ harvest && <HarvestGallery harvest={ harvest } /> }
						<div className="dxai-preview-stage dxai-preview-stage--premium">
							<div className="dxai-preview-toolbar dxai-seg">
								<Button variant={ viewport === 'desktop' ? 'primary' : 'secondary' } onClick={ () => setViewport( 'desktop' ) }>
									{ __( 'Desktop', 'dxai-ui' ) }
								</Button>
								<Button variant={ viewport === 'mobile' ? 'primary' : 'secondary' } onClick={ () => setViewport( 'mobile' ) }>
									{ __( 'Mobile', 'dxai-ui' ) }
								</Button>
							</div>
							{ /*
							  * The design's own scripts run in here, before anyone has
							  * accepted the design. Without allow-same-origin the frame
							  * has an opaque origin: with it, a srcdoc frame is wp-admin
							  * itself, and those scripts could use the admin's nonce and
							  * session through `parent` (create a user, install a plugin).
							  * The cost is the preview only: storage and cookies throw in
							  * it, and a font on this site's own host needs a CORS header.
							  */ }
							<iframe
								title={ __( 'Conversion preview', 'dxai-ui' ) }
								className={ 'dxai-preview is-' + viewport }
								sandbox="allow-scripts"
								srcDoc={ srcDoc }
							/>
						</div>
						<ul className="dxai-pieces">
							{ structures.map( ( item, index ) => (
								<li key={ item.key || index }>
									<label className={ 'dxai-chip' + ( item.included !== false ? ' is-on' : '' ) }>
										<input
											type="checkbox"
											checked={ item.included !== false }
											onChange={ ( event ) => updateStructure( index, { included: event.target.checked } ) }
										/>
										{ item.title || item.type }
									</label>
								</li>
							) ) }
						</ul>
						<button type="button" className="dxai-link" onClick={ () => setShowAdjust( ! showAdjust ) }>
							{ showAdjust ? __( 'Hide adjust pieces', 'dxai-ui' ) : __( 'Adjust pieces', 'dxai-ui' ) }
						</button>
						{ showAdjust && (
							<div className="dxai-adjust">
								{ structures.map( ( item, index ) => (
									<div className="dxai-card" key={ item.key || index }>
										<SelectControl
											label={ item.title || item.type }
											value={ item.type }
											options={ STRUCTURE_TYPES.map( ( type ) => ( { label: type, value: type } ) ) }
											onChange={ ( value ) => updateStructure( index, { type: value } ) }
										/>
										<Button
											variant="secondary"
											disabled={ busy }
											onClick={ () => improveStructure( index ) }
										>
											{ __( 'Improve with AI', 'dxai-ui' ) }
										</Button>
									</div>
								) ) }
							</div>
						) }
						<button type="button" className="dxai-link" onClick={ () => setShowMarkup( ! showMarkup ) }>
							{ showMarkup ? __( 'Hide markup', 'dxai-ui' ) : __( 'View markup', 'dxai-ui' ) }
						</button>
						{ showMarkup && <textarea readOnly value={ markupFromStructures( structures ) || result.gutenberg_markup } /> }
						{ ( plan.hasMenu || facts.offered ) && (
							<div className="dxai-review-options">
								<p className="dxai-section-kicker">{ __( 'Before you import', 'dxai-ui' ) }</p>
								{ /* The same question as on the options step, now with what the design has. */ }
								{ facts.offered && (
									<ChromeChoice stage="review" facts={ facts } value={ chromeChosen } onChange={ setChromeChoice } />
								) }
								{ plan.hasMenu && (
									<PagesOption
										stage="review"
										engine={ engine }
										checked={ createMissingPages }
										onChange={ toggleCrawl }
										plan={ plan }
										skipped={ pagesSkipped }
										onSkip={ setPagesSkipped }
										origin={ pagesOrigin }
										onOrigin={ setPagesOrigin }
									/>
								) }
							</div>
						) }
						<div className="dxai-actions">
							<Button variant="secondary" onClick={ () => setStep( 'file' ) }>{ __( 'Back', 'dxai-ui' ) }</Button>
							<Button variant="primary" disabled={ busy } onClick={ addToWordPress }>
								{ crawlWanted && crawlCount > 0
									? sprintf(
										/* translators: %d: number of menu pages */
										_n( 'Add to WordPress and build %d menu page', 'Add to WordPress and build %d menu pages', crawlCount, 'dxai-ui' ),
										crawlCount
									)
									: __( 'Add to WordPress', 'dxai-ui' ) }
							</Button>
						</div>
					</section>
				) }

				{ step === 'success' && (
					<>
						<ImportSummary
							saved={ saved }
							links={ links }
							elapsed={ elapsed }
							crawlRan={ crawlRan }
							crawlStopped={ crawlStopped }
							chromeAsked={ chromeAsked }
							headingRef={ doneHeading }
							onReset={ resetGame }
						>
							{ /* Coverage is counted against the saved page, so it can only appear here. */ }
							<FidelityReport result={ result } harvest={ harvest } structures={ structures } coverage={ saved?.coverage } compact />
							<ImportAudit audit={ saved?.audit } />
						</ImportSummary>
						<ImportProgress compact plan={ phasePlan } progress={ progress } crawl={ crawl } engine={ engine } />
					</>
				) }
			</div>
		</div>
	);
}

/**
 * What the post-import audit found.
 *
 * A conversion that "succeeded" used to mean only that the posts saved, so an
 * empty page, images with no source, or component code rendered as body copy
 * all ended on this screen looking like a win. The server checks for those now
 * and this is where the answer belongs — next to the buttons that invite
 * someone to go and look.
 */
function ImportAudit( { audit } ) {
	if ( ! audit ) {
		return null;
	}

	const findings = audit.findings || [];
	const notices = audit.notices || [];

	/*
	 * Advisory measurements, shown whether the checks passed or not. They
	 * cannot fail an import — each one reports a gap we know about — but
	 * "all checks passed" on a page where a quarter of the blocks are not
	 * editable and half the controls do nothing is a true sentence that
	 * leaves the wrong impression.
	 */
	const measured = notices.length ? (
		<ul className="dxai-audit-notices">
			{ notices.map( ( notice ) => (
				<li key={ notice.check }>
					<code>{ notice.check }</code> { notice.detail }
				</li>
			) ) }
		</ul>
	) : null;

	if ( ! findings.length ) {
		return (
			<div className="dxai-audit dxai-audit--ok">
				<p>
					{ sprintf(
						/* translators: %d: number of checks that ran. */
						__( 'All %d import checks passed.', 'dxai-ui' ),
						audit.checked || 0
					) }
					{ audit.note ? ' ' + audit.note : '' }
				</p>
				{ measured }
			</div>
		);
	}

	return (
		<div className="dxai-audit dxai-audit--warn">
			<p>
				{ sprintf(
					/* translators: %d: number of problems found. */
					__( 'The import finished, but %d check(s) found a problem:', 'dxai-ui' ),
					findings.length
				) }
			</p>
			<ul>
				{ findings.map( ( finding ) => (
					<li key={ finding.check }>
						<code>{ finding.check }</code> { finding.detail }
					</li>
				) ) }
			</ul>
			{ measured }
			{ audit.note && <p className="dxai-audit-note">{ audit.note }</p> }
		</div>
	);
}

function HarvestGallery( { harvest } ) {
	const images = harvest.images || [];
	const videos = harvest.videos || [];
	const fonts = harvest.fonts || [];
	const links = harvest.links || [];
	if ( ! images.length && ! videos.length && ! fonts.length && ! links.length ) {
		return null;
	}
	return (
		<div className="dxai-harvest">
			{ images.length > 0 && (
				<div className="dxai-thumbs">
					{ images.slice( 0, 12 ).map( ( item, index ) => (
						<button
							type="button"
							key={ item.url || index }
							onClick={ () => {
								if ( item.url && navigator.clipboard ) {
									navigator.clipboard.writeText( item.url );
								}
							} }
							title={ item.url }
						>
							<img src={ item.url } alt={ item.filename || '' } />
						</button>
					) ) }
				</div>
			) }
			<p className="dxai-muted">
				{ ( harvest.summary?.images || images.length ) + ' images · ' + ( harvest.summary?.videos || videos.length ) + ' videos · ' + ( harvest.summary?.fonts || fonts.length ) + ' fonts · ' + ( harvest.summary?.links || links.length ) + ' links' }
			</p>
			{ fonts.length > 0 && (
				<p className="dxai-muted">{ fonts.map( ( font ) => font.family || font.name ).filter( Boolean ).join( ', ' ) }</p>
			) }
		</div>
	);
}

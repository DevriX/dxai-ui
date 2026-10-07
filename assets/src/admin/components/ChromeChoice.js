import { __, sprintf } from '@wordpress/i18n';
import { CHROME_INSTALL, CHROME_KEEP, chromeWarnings } from '../importFlow';
import './import.css';

/*
 * The header and footer question, asked before anything is saved.
 *
 * Installing builds the design's header in Appearance › Menus and its footer
 * in Appearance › Widgets — the menu locations and widget areas the site's
 * own header and footer are drawn from. On a classic theme (the DevriX
 * themes production runs) that is every page of the site, and when another
 * design holds them now, that design loses them. Keeping leaves both screens
 * as they are, and the design keeps its own header and footer on its pages
 * as template parts. The person answers; the answer offered first is the one
 * the server names for this theme (import-info `chrome_default`).
 *
 * A native radio group in a fieldset: its legend names the question, each
 * option is labelled, arrow keys move between them, and the install option
 * is described by the warning (aria-describedby) as well as its own text, so
 * a screen reader hears what installing changes before choosing it. The
 * whole card is the label's click target; the radio itself stays the
 * focusable control.
 *
 * @param {Object}   props
 * @param {Object}   props.facts    chromeFacts()
 * @param {string}   props.value    'install' | 'keep'
 * @param {Function} props.onChange called with the new value
 * @param {string}   props.stage    'scope' | 'review' — keeps the ids unique per step
 */
/**
 * Why the answer offered first is the one offered (Chrome_Choice::resolve()'s
 * reason for an automatic import): a few words under that option.
 *
 * @param {Object} f chromeFacts()
 */
function suggestedWhy( f ) {
	switch ( f.defaultReason ) {
		case 'theme':
			return __( 'Suggested: the theme draws its own header and footer', 'dxai-ui' );
		case 'one_page':
			return __( 'Suggested for a one-page import', 'dxai-ui' );
		case 'owned':
			return __( 'Suggested: another design’s are the site’s now', 'dxai-ui' );
		case 'free':
			return __( 'Suggested: no design’s are the site’s yet', 'dxai-ui' );
		case 'own':
			return __( 'Suggested: they are this design’s already', 'dxai-ui' );
		case 'dx':
			return __( 'Suggested: this DX theme has no menus or footer widgets of the site’s own yet', 'dxai-ui' );
		case 'dx_busy':
			return __( 'Suggested: the site already has its own menus or footer widgets', 'dxai-ui' );
		case 'derived':
			return __( 'Suggested for this theme', 'dxai-ui' );
		default:
			return __( 'Suggested for this site', 'dxai-ui' );
	}
}

export default function ChromeChoice( { facts, value, onChange, stage } ) {
	const f = facts || {};
	const id = 'dxai-chrome-' + ( stage || 'x' );
	const warnings = chromeWarnings( f );
	const warnId = id + '-warn';
	const nowId = id + '-now';
	// The warning is read as its parts, so each sentence stays a sentence for a screen reader.
	const warnParts = warnings.length ? [ warnId + '-title' ].concat( warnings.map( ( w, i ) => warnId + '-' + i ) ) : [];

	// Known only once the design is compiled (the review step).
	if ( f.hasHeader === false && f.hasFooter === false ) {
		return (
			<div className="dxai-chrome-choice is-empty">
				<p className="dxai-chrome-choice__legend">{ __( 'The header and footer', 'dxai-ui' ) }</p>
				<p className="dxai-chrome-choice__now">
					{ __( 'This design has no header or footer, so there is nothing to install: the site’s header and footer stay as they are.', 'dxai-ui' ) }
				</p>
			</div>
		);
	}

	let now = '';
	if ( f.own ) {
		now = __( 'The site’s header and footer are already this design’s, from its last import. Installing again updates them in place.', 'dxai-ui' );
	} else if ( f.others && f.others.length ) {
		now = f.others.length === 1
			? sprintf(
				/* translators: %s: design title */
				__( 'The site’s header and footer now come from the design “%s”.', 'dxai-ui' ),
				f.others[ 0 ].title
			)
			: sprintf(
				/* translators: 1: design title (header), 2: design title (footer) */
				__( 'The site’s header now comes from “%1$s” and its footer from “%2$s”.', 'dxai-ui' ),
				( f.others.find( ( o ) => o.parts.includes( 'header' ) ) || f.others[ 0 ] ).title,
				( f.others.find( ( o ) => o.parts.includes( 'footer' ) ) || f.others[ 1 ] ).title
			);
	} else if ( f.defaultReason === 'dx' ) {
		now = sprintf(
			/* translators: %s: theme name */
			__( '%s is a DX theme and nothing of the site’s own is in Appearance › Menus or Widgets yet, so this design’s header and footer can go there.', 'dxai-ui' ),
			f.themeName || __( 'This theme', 'dxai-ui' )
		);
	} else if ( f.defaultReason === 'dx_busy' ) {
		now = sprintf(
			/* translators: %s: theme name */
			__( '%s draws the site’s header and footer from Appearance › Menus and Appearance › Widgets, and the site already has its own menus or footer widgets there.', 'dxai-ui' ),
			f.themeName || __( 'This theme', 'dxai-ui' )
		);
	} else if ( f.themeDraws ) {
		now = sprintf(
			/* translators: %s: theme name */
			__( '%s draws the site’s header and footer itself, from Appearance › Menus and Appearance › Widgets.', 'dxai-ui' ),
			f.themeName || __( 'This theme', 'dxai-ui' )
		);
	} else {
		now = __( 'No imported design holds the site’s header or footer yet.', 'dxai-ui' );
	}
	if ( f.hasHeader === true && f.hasFooter === false ) {
		now += ' ' + __( 'This design has a header but no footer.', 'dxai-ui' );
	} else if ( f.hasHeader === false && f.hasFooter === true ) {
		now += ' ' + __( 'This design has a footer but no header.', 'dxai-ui' );
	}

	const options = [
		{
			value: CHROME_INSTALL,
			title: __( 'Install the design’s header in Appearance › Menus and footer in Appearance › Widgets', 'dxai-ui' ),
			desc: __( 'The header’s links become menus in the theme’s menu locations and the footer’s columns become widgets in its footer areas, where you edit them afterwards. Widgets already in those areas are moved to Inactive Widgets; nothing is deleted.', 'dxai-ui' ),
		},
		{
			value: CHROME_KEEP,
			title: __( 'Keep the site’s current header and footer', 'dxai-ui' ),
			desc: __( 'This design keeps its own header and footer on its pages, saved with them as template parts. Appearance › Menus and Widgets are not changed.', 'dxai-ui' ),
		},
	];

	return (
		<fieldset className="dxai-chrome-choice" aria-describedby={ nowId }>
			<legend className="dxai-chrome-choice__legend">{ __( 'The header and footer', 'dxai-ui' ) }</legend>
			<p id={ nowId } className="dxai-chrome-choice__now">{ now }</p>
			{ ! f.dxTheme && f.baseTheme && (
				<p className="dxai-chrome-choice__base">
					{ f.baseTheme === 'installed'
						? __( 'DX Base is installed. Switch the site to it in Library › Template and this header and footer are drawn on every page of the site, not only on this design’s pages.', 'dxai-ui' )
						: __( 'The header and footer are drawn on this design’s pages only. DX Base, a small theme this plugin carries, draws them on every page of the site: install it in Library › Template.', 'dxai-ui' ) }
					{ ' ' }
					<a href="admin.php?page=dxai-ui-library">{ __( 'Open Library', 'dxai-ui' ) }</a>
				</p>
			) }
			<div className="dxai-chrome-choice__options">
				{ options.map( ( opt ) => {
					const inputId = id + '-' + opt.value;
					const selected = value === opt.value;
					const warned = opt.value === CHROME_INSTALL && warnings.length > 0;
					return (
						<div key={ opt.value } className={ 'dxai-chrome-option' + ( selected ? ' is-selected' : '' ) + ( warned ? ' has-warning' : '' ) }>
							<input
								type="radio"
								id={ inputId }
								name={ id }
								value={ opt.value }
								checked={ selected }
								onChange={ () => onChange( opt.value ) }
								aria-describedby={ [ inputId + '-desc' ].concat( warned ? warnParts : [] ).join( ' ' ) }
							/>
							<label htmlFor={ inputId } className="dxai-chrome-option__title">{ opt.title }</label>
							<p id={ inputId + '-desc' } className="dxai-chrome-option__desc">{ opt.desc }</p>
							{ f.defaultChoice === opt.value && (
								<span className="dxai-chrome-option__tag">{ suggestedWhy( f ) }</span>
							) }
						</div>
					);
				} ) }
			</div>
			{ warnings.length > 0 && (
				<div id={ warnId } className={ 'dxai-chrome-choice__warn' + ( value === CHROME_INSTALL ? ' is-armed' : '' ) }>
					<p id={ warnId + '-title' } className="dxai-chrome-choice__warn-title">{ __( 'Installing changes the header and footer of the whole site.', 'dxai-ui' ) }</p>
					<ul>
						{ warnings.map( ( w, i ) => <li key={ w.key } id={ warnId + '-' + i }>{ w.text }</li> ) }
					</ul>
				</div>
			) }
		</fieldset>
	);
}

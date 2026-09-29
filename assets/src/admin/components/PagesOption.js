import { __, _n, sprintf } from '@wordpress/i18n';
import { crawlAllowed, engineLine } from '../importFlow';
import './import.css';

/*
 * "Create the pages from your live site" — the question the import asks
 * before it adds anything: build the pages the design's menu links to, from
 * the live site's own text and pictures laid out in this design's sections,
 * and which of them. The plugin does it (no AI, no API credit) unless a site
 * asked for the AI engine (the dxai_ui_pages_engine filter).
 *
 * A switch rather than a checkbox inside a <label>: the admin sheet styles
 * every label as an uppercase caption and every input as a text field, and a
 * role="switch" button needs neither. The page list's checkboxes get their
 * own rules in import.css for the same reason.
 *
 * `skipped` is the paths the person unticked, so a page the plan adds later
 * starts ticked.
 */
export default function PagesOption( { engine, checked, onChange, plan, stage, skipped = [], onSkip, origin = '', onOrigin } ) {
	const pagesEngine = engine && engine.pages === 'ai' ? 'ai' : 'design';
	const known = Boolean( plan && plan.origin );
	const count = known ? plan.pages.length : null;
	const chosen = known ? plan.pages.filter( ( page ) => ! skipped.includes( page.path ) ).length : null;
	const disabled = ! crawlAllowed( engine, plan, pagesEngine );
	const on = checked && ! disabled;
	const id = 'dxai-pages-' + ( stage || 'x' );

	let pagesText;
	if ( ! plan ) {
		pagesText = __( 'Counted once the design is compiled, before anything is saved. You choose which ones there.', 'dxai-ui' );
	} else if ( ! known ) {
		pagesText = plan.hasMenu
			? __( 'The menu’s links are relative, so the live site could not be worked out from the design. WordPress looks for it when the page is saved.', 'dxai-ui' )
			: __( 'This design has no menu.', 'dxai-ui' );
	} else if ( count === 0 ) {
		pagesText = sprintf(
			/* translators: %s: site host name */
			__( 'The menu links to no other page of %s, so the pages are taken from that site’s own menu when the page is saved.', 'dxai-ui' ),
			plan.host
		);
	} else {
		pagesText = sprintf(
			/* translators: 1: number of pages, 2: site host name */
			_n( '%1$d page linked from the menu on %2$s', '%1$d pages linked from the menu on %2$s', count, 'dxai-ui' ),
			count,
			plan.host
		) + ( plan.guessed ? ' ' + __( '(site guessed from the design’s links)', 'dxai-ui' ) : '' );
	}

	function toggle( path ) {
		onSkip( skipped.includes( path ) ? skipped.filter( ( p ) => p !== path ) : [ ...skipped, path ] );
	}

	return (
		<div className={ 'dxai-ai-option' + ( on ? ' is-on' : '' ) + ( disabled ? ' is-disabled' : '' ) }>
			<div className="dxai-ai-option__head">
				<span className="dxai-ai-option__glyph" aria-hidden="true" />
				<div className="dxai-ai-option__copy">
					<h4 id={ id + '-title' } className="dxai-ai-option__title">
						{ __( 'Create the pages from your live site', 'dxai-ui' ) }
					</h4>
					<p id={ id + '-desc' } className="dxai-ai-option__desc">
						{ __( 'Fetches each page the design’s menu links to on the live site and builds it from this design’s own sections, with that page’s own text and pictures — no new styles. The menu and the footer then open the new pages.', 'dxai-ui' ) }
					</p>
				</div>
				<button
					type="button"
					role="switch"
					className="dxai-switch"
					aria-checked={ on }
					aria-labelledby={ id + '-title' }
					aria-describedby={ id + '-desc ' + id + '-facts' }
					disabled={ disabled }
					onClick={ () => onChange( ! on ) }
				>
					<span className="dxai-switch__track" aria-hidden="true">
						<span className="dxai-switch__thumb" />
					</span>
					<span className="dxai-switch__text" aria-hidden="true">{ on ? __( 'On', 'dxai-ui' ) : __( 'Off', 'dxai-ui' ) }</span>
				</button>
			</div>
			<dl id={ id + '-facts' } className="dxai-ai-option__facts">
				<div>
					<dt>{ __( 'Built by', 'dxai-ui' ) }</dt>
					<dd>
						{ pagesEngine === 'ai'
							? (
								<>
									{ engine && engine.id ? <code>{ engineLine( engine ) }</code> : __( 'None configured', 'dxai-ui' ) }
									<span className="dxai-ai-option__aside">
										{ __( 'This site asks for the AI engine (the dxai_ui_pages_engine filter): one request per page, which uses API credit.', 'dxai-ui' ) }
									</span>
								</>
							)
							: __( 'The plugin, from this design’s sections — no AI and no API credit.', 'dxai-ui' ) }
					</dd>
				</div>
				<div>
					<dt>{ __( 'Pages', 'dxai-ui' ) }</dt>
					<dd>{ pagesText }</dd>
				</div>
				<div>
					<dt>{ __( 'Time', 'dxai-ui' ) }</dt>
					<dd>{ __( 'A few seconds a page, plus downloading its pictures. You can watch every page here.', 'dxai-ui' ) }</dd>
				</div>
			</dl>
			{ on && plan && onOrigin && (
				<div className="dxai-ai-option__origin">
					<label htmlFor={ id + '-origin' }>{ __( 'Old site address', 'dxai-ui' ) }</label>
					<input
						type="text"
						id={ id + '-origin' }
						value={ origin }
						placeholder={ known ? plan.host : 'example.com' }
						onChange={ ( e ) => onOrigin( e.target.value ) }
						aria-describedby={ id + '-origin-help' }
					/>
					<p id={ id + '-origin-help' }>
						{ known
							? __( 'Leave it empty to use the site the menu links to, or type another address if that one is wrong.', 'dxai-ui' )
							: __( 'The menu’s links do not show where the old site is. Type its address, and the pages are found from its own menus.', 'dxai-ui' ) }
					</p>
				</div>
			) }
			{ on && known && count > 0 && (
				<fieldset className="dxai-ai-option__pick">
					<legend>
						{ sprintf(
							/* translators: 1: pages chosen, 2: pages in the menu */
							__( 'Which pages — %1$d of %2$d', 'dxai-ui' ),
							chosen,
							count
						) }
					</legend>
					<div className="dxai-ai-option__bulk">
						<button type="button" className="dxai-link" onClick={ () => onSkip( [] ) } disabled={ chosen === count }>
							{ __( 'Select all', 'dxai-ui' ) }
						</button>
						<button type="button" className="dxai-link" onClick={ () => onSkip( plan.pages.map( ( page ) => page.path ) ) } disabled={ chosen === 0 }>
							{ __( 'None', 'dxai-ui' ) }
						</button>
					</div>
					<ul>
						{ plan.pages.map( ( page, i ) => (
							<li key={ page.path }>
								<input
									type="checkbox"
									id={ id + '-page-' + i }
									checked={ ! skipped.includes( page.path ) }
									onChange={ () => toggle( page.path ) }
								/>
								<label htmlFor={ id + '-page-' + i }>
									<span>{ page.label || page.path }</span>
									<code>{ page.path }</code>
								</label>
							</li>
						) ) }
					</ul>
				</fieldset>
			) }
			{ pagesEngine === 'ai' && disabled && plan && ( ! known || count > 0 ) && (
				<p className="dxai-ai-option__why">
					{ engine && engine.id
						? sprintf(
							/* translators: %s: engine name */
							__( 'No API key is saved for %s, so the AI engine this site asks for cannot build the pages.', 'dxai-ui' ),
							engine.name || engine.id
						)
						: __( 'No AI engine is configured, so the AI engine this site asks for cannot build the pages.', 'dxai-ui' ) }
				</p>
			) }
			{ on && known && count > 0 && chosen === 0 && (
				<p className="dxai-ai-option__why">
					{ __( 'No page is ticked, so none will be created. Tick at least one, or switch this off.', 'dxai-ui' ) }
				</p>
			) }
			{ on && ( ! known || count === 0 || chosen > 0 ) && (
				<p className="dxai-ai-option__confirm">
					{ known && count > 0
						? sprintf(
							/* translators: %d: number of pages */
							_n( 'On: after the page is saved, %d page is built from the live site and added to the menu.', 'On: after the page is saved, %d pages are built from the live site and added to the menu.', chosen, 'dxai-ui' ),
							chosen
						)
						: __( 'On: after the page is saved, the menu’s pages are built from the live site and added to the menu.', 'dxai-ui' ) }
					{ ' ' }
					{ __( 'Pages built by an earlier import are updated in place, unless someone edited them since.', 'dxai-ui' ) }
				</p>
			) }
		</div>
	);
}

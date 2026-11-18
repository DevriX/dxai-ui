import { __, _n, sprintf } from '@wordpress/i18n';
import { CHROME_INSTALL, CHROME_KEEP, chromeOutcome, chromeReport, formatDuration, keptSentence, summaryCounts } from '../importFlow';
import './import.css';

/**
 * A part kept as a template part of the design's page: why, and where it is
 * edited — the save's own link to the part, never Menus or Widgets, which
 * the import left alone.
 */
function keptRow( part, area ) {
	const isHeader = part === 'header';
	return {
		key: isHeader ? 'menus' : 'widgets',
		bad: area.refused || area.overridden,
		kept: true,
		title: isHeader ? __( 'Header', 'dx-ui' ) : __( 'Footer', 'dx-ui' ),
		where: __( 'kept as a template part', 'dx-ui' ),
		href: area.partUrl,
		linkLabel: isHeader ? __( 'Edit the header', 'dx-ui' ) : __( 'Edit the footer', 'dx-ui' ),
		text: keptSentence( part, area ),
	};
}

/*
 * Where the header and footer went, and what failed there.
 *
 * A page import moves straight from the save to this screen, so the menus
 * and widgets phases are on screen for a moment only; a footer that could
 * not be written would otherwise be a single amber chip. Each part says what
 * actually happened to it (chromeOutcome()) and where to edit it — built in
 * Appearance › Menus / Widgets, or kept as a template part of the design's
 * page, with the reason — and a part the server did not report is left out
 * rather than guessed at.
 */
function ChromeRows( { report, outcome, menusUrl, widgetsUrl } ) {
	const rows = [];
	if ( outcome.header.mode === CHROME_KEEP ) {
		rows.push( keptRow( 'header', outcome.header ) );
	} else if ( report.menusError ) {
		rows.push( { key: 'menus', bad: true, title: __( 'Header menus', 'dx-ui' ), where: __( 'Appearance › Menus', 'dx-ui' ), href: menusUrl, text: report.menusError } );
	} else if ( Array.isArray( report.menus ) && report.menus.length ) {
		rows.push( {
			key: 'menus',
			title: __( 'Header menus', 'dx-ui' ),
			where: __( 'Appearance › Menus', 'dx-ui' ),
			href: menusUrl,
			list: report.menus.map( ( m ) => ( {
				name: m.name,
				url: m.url,
				meta: [
					m.where,
					m.count !== null && m.count !== undefined
						? sprintf( /* translators: %d: menu items */ _n( '%d item', '%d items', m.count, 'dx-ui' ), m.count )
						: '',
				].filter( Boolean ).join( ', ' ),
			} ) ),
			text: [
				outcome.header.differs ? __( 'You chose to keep the site’s header and footer, but this server built the header in Appearance › Menus anyway.', 'dx-ui' ) : '',
				report.logoSet ? __( 'The design’s logo is now the site’s Site Logo.', 'dx-ui' ) : '',
			].filter( Boolean ).join( ' ' ),
			bad: outcome.header.differs,
		} );
	}
	if ( outcome.footer.mode === CHROME_KEEP ) {
		rows.push( keptRow( 'footer', outcome.footer ) );
	} else if ( report.widgetsError ) {
		rows.push( { key: 'widgets', bad: true, title: __( 'Footer widgets', 'dx-ui' ), where: __( 'Appearance › Widgets', 'dx-ui' ), href: widgetsUrl, text: report.widgetsError } );
	} else if ( Array.isArray( report.widgets ) && report.widgets.length ) {
		rows.push( {
			key: 'widgets',
			title: __( 'Footer widgets', 'dx-ui' ),
			where: __( 'Appearance › Widgets', 'dx-ui' ),
			href: widgetsUrl,
			list: report.widgets.map( ( w ) => ( {
				name: w.name,
				url: '',
				meta: w.count !== null && w.count !== undefined
					? sprintf( /* translators: %d: widgets */ _n( '%d widget', '%d widgets', w.count, 'dx-ui' ), w.count )
					: '',
			} ) ),
			text: [
				outcome.footer.differs ? __( 'You chose to keep the site’s header and footer, but this server built the footer in Appearance › Widgets anyway.', 'dx-ui' ) : '',
				report.widgetsMoved > 0
					? sprintf(
						/* translators: %d: number of widgets */
						_n(
							'%d widget that was already in these areas is now under Inactive Widgets — nothing was deleted.',
							'%d widgets that were already in these areas are now under Inactive Widgets — nothing was deleted.',
							report.widgetsMoved,
							'dx-ui'
						),
						report.widgetsMoved
					)
					: '',
			].filter( Boolean ).join( ' ' ),
			bad: outcome.footer.differs,
		} );
	}
	if ( ! rows.length && ! report.notices.length ) {
		return null;
	}
	return (
		<>
			{ rows.length > 0 && (
				<ul className="dxui-done__chrome">
					{ rows.map( ( row ) => (
						<li key={ row.key } className={ [ row.bad ? 'is-bad' : '', row.kept ? 'is-kept' : '' ].filter( Boolean ).join( ' ' ) || undefined }>
							<p className="dxui-done__chrome-head">
								<strong>{ row.title }</strong>
								{ /* A kept part says so, then links to it; a built one links its screen. */ }
								{ row.kept && <span>{ row.where }</span> }
								{ ! row.kept && ( row.href ? <a href={ row.href }>{ row.where }</a> : <span>{ row.where }</span> ) }
							</p>
							{ row.kept && row.href && (
								<p className="dxui-done__chrome-note"><a href={ row.href }>{ row.linkLabel }</a></p>
							) }
							{ row.list && (
								<ul>
									{ row.list.map( ( item, i ) => (
										<li key={ ( item.name || '' ) + i }>
											{ item.url ? <a href={ item.url }>{ item.name }</a> : <span>{ item.name }</span> }
											{ item.meta && <em>{ item.meta }</em> }
										</li>
									) ) }
								</ul>
							) }
							{ row.text && <p className="dxui-done__chrome-note">{ row.text }</p> }
						</li>
					) ) }
				</ul>
			) }
			{ /* What changed for other designs, and for the person's own menus (Converter_Controller::chrome_report()). */ }
			{ report.notices.length > 0 && (
				<ul className="dxui-done__chrome-notices">
					{ report.notices.map( ( text, i ) => <li key={ i }>{ text }</li> ) }
				</ul>
			) }
		</>
	);
}

/*
 * The end of an import: what exists now, where to go to see or change it,
 * and — as plainly as the successes — what did not work.
 */
export default function ImportSummary( { saved, links, elapsed, crawlStopped, crawlRan, chromeAsked, headingRef, onReset, front, onFront, children } ) {
	const counts = summaryCounts( saved );
	const report = chromeReport( saved );
	// What actually happened to the header and footer, whatever was asked.
	const outcome = chromeOutcome( saved, chromeAsked );
	const created = Array.isArray( saved?.pages_created ) ? saved.pages_created : [];
	const errors = Array.isArray( saved?.crawl_errors ) ? saved.crawl_errors : [];
	const kept = Array.isArray( saved?.pages_skipped ) ? saved.pages_skipped.filter( ( p ) => p && p.reason && p.reason !== 'home' ) : [];
	const problems = counts.errors > 0 || Boolean( crawlStopped ) || Boolean( report.menusError ) || Boolean( report.widgetsError ) ||
		outcome.header.refused || outcome.footer.refused || outcome.header.differs || outcome.footer.differs;

	const menusUrl = report.menusUrl || links?.menus || ( report.menus && report.menus.length ? 'nav-menus.php' : '' );
	const widgetsUrl = report.widgetsUrl || links?.widgets || ( report.widgets && report.widgets.length ? 'widgets.php' : '' );
	/*
	 * The buttons under the counts: the Appearance screen of a part that was
	 * built there, and the template part of one that was kept — not "Menus"
	 * for a header that stayed with its page. A part the server did not
	 * report keeps the screen link it always had (an older server builds them).
	 */
	const headerBuilt = outcome.header.mode === CHROME_INSTALL || outcome.header.mode === 'unknown';
	const footerBuilt = outcome.footer.mode === CHROME_INSTALL || outcome.footer.mode === 'unknown';
	const headerEdit = saved?.page?.edit_header && ! /nav-menus\.php/.test( saved.page.edit_header ) ? saved.page.edit_header : '';
	const footerEdit = saved?.page?.edit_footer && ! /widgets\.php/.test( saved.page.edit_footer ) ? saved.page.edit_footer : '';

	return (
		<section className="dxui-done" aria-labelledby="dxui-done-title">
			<div className={ 'dxui-success-mark' + ( problems ? ' is-warn' : '' ) } aria-hidden="true" />
			<h3 id="dxui-done-title" className="dxui-done__title" tabIndex={ -1 } ref={ headingRef }>
				{ problems ? __( 'Saved — with problems to look at', 'dx-ui' ) : __( 'It is in WordPress', 'dx-ui' ) }
			</h3>
			<p className="dxui-done__lede">
				{ elapsed > 0
					? sprintf(
						/* translators: %s: duration, e.g. 2:14 */
						__( 'Saved as native blocks in %s.', 'dx-ui' ),
						formatDuration( elapsed )
					)
					: __( 'Saved as native blocks.', 'dx-ui' ) }
				{ ' ' }
				{ /* The site's front page (Network\Front_Page): made the Home when the site had none of its own, else kept, with one button. */ }
				{ front && ( front.made || front.state === 'this' )
					? __( 'The Home is the site’s front page now.', 'dx-ui' )
					: ( front && front.state === 'own'
						? sprintf( /* translators: %s: the title of the site's front page */ __( 'The site’s front page is still “%s”.', 'dx-ui' ), front.title )
						: __( 'The site’s front page was not changed.', 'dx-ui' ) ) }
				{ front && front.state === 'own' && ! front.made && onFront && (
					<>
						{ ' ' }
						<button type="button" className="dxui-link" onClick={ onFront }>{ __( 'Make the Home the front page', 'dx-ui' ) }</button>
					</>
				) }
			</p>

			<ul className="dxui-done__counts">
				<li>
					<strong>{ counts.pages }</strong>
					<span>{ _n( 'page created', 'pages created', counts.pages, 'dx-ui' ) }</span>
					{ ( counts.extra > 0 || counts.crawled > 0 ) && (
						<em>
							{ [
								counts.main ? __( '1 design page', 'dx-ui' ) : '',
								counts.extra ? sprintf( /* translators: %d: route pages */ _n( '%d route', '%d routes', counts.extra, 'dx-ui' ), counts.extra ) : '',
								counts.crawled ? sprintf( /* translators: %d: pages built from the menu */ _n( '%d from the menu', '%d from the menu', counts.crawled, 'dx-ui' ), counts.crawled ) : '',
							].filter( Boolean ).join( ' · ' ) }
						</em>
					) }
				</li>
				{ crawlRan && (
					<li>
						<strong>{ counts.restyled }</strong>
						<span>
							{ counts.restyleEngine === 'design' || counts.restyleEngine === ''
								? _n( 'page built from the design’s sections', 'pages built from the design’s sections', counts.restyled, 'dx-ui' )
								: _n( 'page rebuilt with AI', 'pages rebuilt with AI', counts.restyled, 'dx-ui' ) }
						</span>
						{ counts.crawled > counts.restyled && (
							<em>{ sprintf( /* translators: %d: pages kept as the old site had them */ _n( '%d kept as on the old site', '%d kept as on the old site', counts.crawled - counts.restyled, 'dx-ui' ), counts.crawled - counts.restyled ) }</em>
						) }
					</li>
				) }
				{ /* Counted only when the save reported them; an older server says nothing and gets no tile. */ }
				{ Array.isArray( report.menus ) && report.menus.length > 0 && (
					<li>
						<strong>{ report.menus.length }</strong>
						<span>{ _n( 'header menu', 'header menus', report.menus.length, 'dx-ui' ) }</span>
						<em>{ __( 'in Appearance › Menus', 'dx-ui' ) }</em>
					</li>
				) }
				{ Array.isArray( report.widgets ) && report.widgets.length > 0 && (
					<li>
						<strong>{ report.widgets.length }</strong>
						<span>{ _n( 'footer widget area', 'footer widget areas', report.widgets.length, 'dx-ui' ) }</span>
						<em>{ __( 'in Appearance › Widgets', 'dx-ui' ) }</em>
					</li>
				) }
				<li className={ counts.errors ? 'is-bad' : '' }>
					<strong>{ counts.errors }</strong>
					<span>{ _n( 'error', 'errors', counts.errors, 'dx-ui' ) }</span>
				</li>
				{ /* The design's document: what the import read of the design (Designs › the design › Overview). An older server says nothing. */ }
				{ saved?.document && (
					<li>
						<strong>{ saved.document.sections }</strong>
						<span>{ _n( 'section read in the design', 'sections read in the design', saved.document.sections, 'dx-ui' ) }</span>
						<em>
							{ sprintf(
								/* translators: 1: number of pages, 2: number of navigation items, 3: number of behaviours the page does not carry. */
								__( '%1$d page(s), %2$d menu items, %3$d behaviour(s) not carried · Designs › the design › Overview', 'dx-ui' ),
								saved.document.pages,
								saved.document.nav_items,
								saved.document.behaviours_left
							) }
						</em>
					</li>
				) }
				{ counts.skipped > 0 && (
					<li>
						<strong>{ counts.skipped }</strong>
						<span>{ _n( 'page kept as edited', 'pages kept as edited', counts.skipped, 'dx-ui' ) }</span>
					</li>
				) }
			</ul>

			{ crawlStopped && (
				<p className="dxui-done__warn" role="note">{ crawlStopped }</p>
			) }

			<ChromeRows report={ report } outcome={ outcome } menusUrl={ menusUrl } widgetsUrl={ widgetsUrl } />

			{ created.length > 0 ? (
				<p className="dxui-done__next" role="note">
					{ __( 'Next: open one service page, finish it as you want the others, then choose “Make the other services from this page” in its editor (the Page tab): the others get its build and look, and their own words.', 'dx-ui' ) }
				</p>
			) : ( outcome.header.mode === CHROME_INSTALL && links?.designs && (
				<p className="dxui-done__next" role="note">
					{ __( 'Next: the header menu names the pages of the site. On the design’s page (Designs › this design › Pages) they are made at the menu’s own addresses, in the look of this Home, so the menu opens them.', 'dx-ui' ) }
				</p>
			) ) }

			<div className="dxui-actions dxui-done__actions">
				{ saved?.page?.view && <a className="components-button is-primary" href={ saved.page.view }>{ __( 'View page', 'dx-ui' ) }</a> }
				{ saved?.page?.edit && <a className="components-button is-secondary" href={ saved.page.edit }>{ __( 'Edit page', 'dx-ui' ) }</a> }
				{ /* A part kept as a template part is edited there; one built in Menus / Widgets through the screen buttons after these. */ }
				{ headerEdit && <a className="components-button is-secondary" href={ headerEdit }>{ __( 'Edit header', 'dx-ui' ) }</a> }
				{ footerEdit && <a className="components-button is-secondary" href={ footerEdit }>{ __( 'Edit footer', 'dx-ui' ) }</a> }
				{ menusUrl && headerBuilt && <a className="components-button is-secondary" href={ menusUrl }>{ __( 'Appearance › Menus', 'dx-ui' ) }</a> }
				{ widgetsUrl && footerBuilt && <a className="components-button is-secondary" href={ widgetsUrl }>{ __( 'Appearance › Widgets', 'dx-ui' ) }</a> }
				{ links?.designs && <a className="components-button is-secondary" href={ links.designs + ( saved?.page?.id ? '#/design/' + saved.page.id : '' ) }>{ __( 'Open the design', 'dx-ui' ) }</a> }
				<button type="button" className="components-button is-tertiary" onClick={ onReset }>{ __( 'Convert another', 'dx-ui' ) }</button>
			</div>

			{ created.length > 0 && (
				<details className="dxui-done__list" open={ created.length <= 8 }>
					<summary>
						{ sprintf( /* translators: %d: pages built from the menu */ _n( '%d page built from the menu', '%d pages built from the menu', created.length, 'dx-ui' ), created.length ) }
					</summary>
					<ul>
						{ created.map( ( page ) => (
							<li key={ page.id || page.path }>
								<span className="dxui-done__name">{ page.label || page.path }</span>
								{ page.path && <code>{ page.path }</code> }
								{ page.id > 0 && (
									<span className="dxui-done__links">
										{ /* The server's own links when it sends them; this screen sits in wp-admin, so the fallbacks resolve there. */ }
										<a href={ page.edit || 'post.php?post=' + page.id + '&action=edit' }>{ __( 'Edit', 'dx-ui' ) }</a>
										<a href={ page.view || '../?page_id=' + page.id }>{ __( 'View', 'dx-ui' ) }</a>
									</span>
								) }
							</li>
						) ) }
					</ul>
				</details>
			) }

			{ errors.length > 0 && (
				<details className="dxui-done__list is-bad" open>
					<summary>
						{ sprintf( /* translators: %d: number of errors */ _n( '%d page could not be built', '%d problems while building the menu pages', errors.length, 'dx-ui' ), errors.length ) }
					</summary>
					<ul>
						{ errors.map( ( err, i ) => (
							<li key={ ( err.url || '' ) + i }>
								{ err.label && <span className="dxui-done__name">{ err.label }</span> }
								{ err.url && <code>{ err.url }</code> }
								<span className="dxui-done__msg">{ err.message }</span>
							</li>
						) ) }
					</ul>
				</details>
			) }

			{ kept.length > 0 && (
				<details className="dxui-done__list">
					<summary>
						{ sprintf( /* translators: %d: pages left alone */ _n( '%d page left as it was', '%d pages left as they were', kept.length, 'dx-ui' ), kept.length ) }
					</summary>
					<ul>
						{ kept.map( ( page, i ) => (
							<li key={ ( page.path || '' ) + i }>
								<span className="dxui-done__name">{ page.label || page.path }</span>
								{ page.path && <code>{ page.path }</code> }
								<span className="dxui-done__msg">
									{ page.reason === 'edited' ? __( 'Edited since the last import, so the import left it alone.', 'dx-ui' ) : page.reason }
								</span>
							</li>
						) ) }
					</ul>
				</details>
			) }

			{ /* What the compiler measured (coverage, raw HTML, Tailwind, the checks): for whoever wants it, not in the way. */ }
			{ children && (
				<details className="dxui-done__details">
					<summary>{ __( 'Details', 'dx-ui' ) }</summary>
					{ children }
				</details>
			) }
		</section>
	);
}

import { __, _n, sprintf } from '@wordpress/i18n';
import { chromeReport } from '../importFlow';
import './import.css';

/*
 * What a package import made, in the finished-import screen's own shape
 * (ImportSummary): the page and its status, where its header and footer went
 * and what that changed for the site, the media and parts it wrote or
 * reused, and — as plainly — what did not go as asked.
 *
 * The header and footer read from the server's chrome report
 * (Converter_Controller::chrome_report(), as for a ZIP import): each menu
 * with the location it is in — or the location a person's own menu keeps,
 * in which case the header draws that menu, not the design's — and one
 * sentence per thing that changed for another design or for the person's
 * menus (a footer handed back to another design's pages, a location kept).
 */

function chromeLine( area, row, report ) {
	if ( ! row || row.mode === 'none' ) {
		return area === 'header'
			? __( 'The package has no header.', 'dxai-ui' )
			: __( 'The package has no footer.', 'dxai-ui' );
	}
	if ( row.mode === 'installed' ) {
		const kept = area === 'header' && report && Array.isArray( report.locationsKept ) && report.locationsKept.length > 0;
		if ( kept ) {
			return __( 'Built in Appearance › Menus: the page shows the site header block — which draws, in each location a menu of yours keeps, that menu.', 'dxai-ui' );
		}
		return area === 'header'
			? __( 'Built in Appearance › Menus: the page shows the site header block.', 'dxai-ui' )
			: __( 'Built in Appearance › Widgets: the page shows the site footer block.', 'dxai-ui' );
	}
	if ( row.reason === 'refused' ) {
		return area === 'header'
			? __( 'Could not be built from menus, so it stays a template part of the page.', 'dxai-ui' )
			: __( 'Could not be built from widgets, so it stays a template part of the page.', 'dxai-ui' );
	}
	return area === 'header'
		? __( 'Kept as the page’s own template part; this site’s menus were not changed.', 'dxai-ui' )
		: __( 'Kept as the page’s own template part; this site’s widgets were not changed.', 'dxai-ui' );
}

function brandLine( brand ) {
	const action = brand && brand.action;
	if ( action === 'adopted' ) {
		return __( 'This site had no brand, so the design’s colours are now its presets in Styles.', 'dxai-ui' );
	}
	if ( action === 'updated' ) {
		return __( 'The page is the site’s brand; its presets were updated.', 'dxai-ui' );
	}
	if ( action === 'theme' ) {
		return __( 'The theme keeps its own colour palette; the page uses the design’s colours from its own stylesheet.', 'dxai-ui' );
	}
	return __( 'The site’s brand was left as it was; the page uses the design’s colours from its own stylesheet.', 'dxai-ui' );
}

function statusLine( page ) {
	if ( page.status === 'publish' ) {
		return '';
	}
	if ( page.created ) {
		return page.status === 'draft'
			? __( 'The page is a draft: preview it, then publish it from the editor when it looks right.', 'dxai-ui' )
			: sprintf( /* translators: %s: post status */ __( 'The page is %s, as it was on the source site.', 'dxai-ui' ), page.status );
	}
	return sprintf( /* translators: %s: post status */ __( 'The page kept the status it has here (%s).', 'dxai-ui' ), page.status );
}

export default function TransferResult( { result, onReset } ) {
	const page = result.page || {};
	const media = result.media || {};
	const chrome = result.chrome || {};
	const parts = Array.isArray( result.parts ) ? result.parts : [];
	const patterns = Array.isArray( result.patterns ) ? result.patterns : [];
	const warnings = Array.isArray( result.warnings ) ? result.warnings : [];
	const raw = result.chrome_report && typeof result.chrome_report === 'object' ? result.chrome_report : {};
	const report = { ...chromeReport( { chrome: raw } ), locationsKept: Array.isArray( raw.locations_kept ) ? raw.locations_kept : [] };
	const mode = result.chrome_mode && typeof result.chrome_mode === 'object' ? result.chrome_mode : {};
	const problems = warnings.length > 0 || Number( media.failed || 0 ) > 0;
	const source = result.source || {};
	const draft = page.status && page.status !== 'publish';

	return (
		<section className="dxai-done" aria-labelledby="dxai-transfer-done-title">
			<div className={ 'dxai-success-mark' + ( problems ? ' is-warn' : '' ) } aria-hidden="true" />
			<h3 id="dxai-transfer-done-title" className="dxai-done__title" tabIndex={ -1 }>
				{ problems ? __( 'Imported — with notes to look at', 'dxai-ui' ) : __( 'The page is on this site', 'dxai-ui' ) }
			</h3>
			<p className="dxai-done__lede">
				{ sprintf(
					/* translators: 1: page title, 2: source site URL, 3: page id on the source site */
					page.created ? __( '“%1$s” was created from %2$s (page %3$d there), with its styles, fonts and images.', 'dxai-ui' ) : __( '“%1$s” was updated in place from %2$s (page %3$d there): this package was imported here before.', 'dxai-ui' ),
					page.title || '',
					source.site_url || '',
					Number( source.page_id || 0 )
				) }
				{ statusLine( page ) && ' ' + statusLine( page ) }
			</p>

			<ul className="dxai-done__counts">
				<li>
					<strong>1</strong>
					<span>{ page.created ? __( 'page created', 'dxai-ui' ) : __( 'page updated', 'dxai-ui' ) }</span>
					<em>{ page.status }</em>
				</li>
				<li>
					<strong>{ Number( media.created || 0 ) }</strong>
					<span>{ _n( 'file added to Media', 'files added to Media', Number( media.created || 0 ), 'dxai-ui' ) }</span>
					{ Number( media.reused || 0 ) > 0 && (
						<em>{ sprintf( /* translators: %d: files already in the media library */ _n( '%d already here, reused', '%d already here, reused', Number( media.reused ), 'dxai-ui' ), Number( media.reused ) ) }</em>
					) }
				</li>
				{ ( parts.length > 0 || patterns.length > 0 ) && (
					<li>
						<strong>{ parts.length + patterns.length }</strong>
						<span>{ __( 'template parts and patterns', 'dxai-ui' ) }</span>
					</li>
				) }
				<li className={ Number( media.failed || 0 ) ? 'is-bad' : '' }>
					<strong>{ Number( media.failed || 0 ) }</strong>
					<span>{ _n( 'file failed', 'files failed', Number( media.failed || 0 ), 'dxai-ui' ) }</span>
				</li>
			</ul>

			{ mode.message && <p className="dxai-done__chrome-note">{ mode.message }</p> }

			<ul className="dxai-done__chrome">
				{ [ 'header', 'footer' ].map( ( area ) => {
					const row = chrome[ area ];
					const bad = row && row.reason === 'refused';
					const list = area === 'header'
						? ( Array.isArray( report.menus ) ? report.menus : [] )
						: ( Array.isArray( report.widgets ) ? report.widgets : [] );
					return (
						<li key={ area } className={ bad ? 'is-bad' : '' }>
							<p className="dxai-done__chrome-head">
								<strong>{ area === 'header' ? __( 'Header', 'dxai-ui' ) : __( 'Footer', 'dxai-ui' ) }</strong>
								{ row && row.mode === 'installed' && (
									<a href={ area === 'header' ? report.menusUrl || 'nav-menus.php' : report.widgetsUrl || 'widgets.php' }>
										{ area === 'header' ? __( 'Appearance › Menus', 'dxai-ui' ) : __( 'Appearance › Widgets', 'dxai-ui' ) }
									</a>
								) }
								{ row && row.mode === 'part' && <span>{ row.part }</span> }
							</p>
							{ row && row.mode === 'installed' && list.length > 0 && (
								<ul>
									{ list.map( ( item, i ) => (
										<li key={ ( item.name || '' ) + i }>
											{ item.url ? <a href={ item.url }>{ item.name }</a> : <span>{ item.name }</span> }
											{ item.where && <span>{ ' — ' + item.where }</span> }
											{ item.count !== null && item.count !== undefined && (
												<em>
													{ area === 'header'
														? sprintf( /* translators: %d: menu items */ _n( '%d item', '%d items', item.count, 'dxai-ui' ), item.count )
														: sprintf( /* translators: %d: widgets */ _n( '%d widget', '%d widgets', item.count, 'dxai-ui' ), item.count ) }
												</em>
											) }
										</li>
									) ) }
								</ul>
							) }
							<p className="dxai-done__chrome-note">{ chromeLine( area, row, report ) }</p>
							{ area === 'footer' && report.widgetsMoved > 0 && (
								<p className="dxai-done__chrome-note">
									{ sprintf(
										/* translators: %d: number of widgets */
										_n( '%d widget that was already in these areas is now under Inactive Widgets — nothing was deleted.', '%d widgets that were already in these areas are now under Inactive Widgets — nothing was deleted.', report.widgetsMoved, 'dxai-ui' ),
										report.widgetsMoved
									) }
								</p>
							) }
							{ area === 'header' && report.logoSet && (
								<p className="dxai-done__chrome-note">{ __( 'The site had no logo, so the design’s logo is now its Site Logo.', 'dxai-ui' ) }</p>
							) }
						</li>
					);
				} ) }
			</ul>

			{ report.notices.length > 0 && (
				<ul className="dxai-done__chrome-notices">
					{ report.notices.map( ( text, i ) => <li key={ i }>{ text }</li> ) }
				</ul>
			) }

			<p className="dxai-muted">{ brandLine( result.brand ) }</p>

			{ warnings.length > 0 && (
				<details className="dxai-done__list is-bad" open>
					<summary>
						{ sprintf( /* translators: %d: number of notes */ _n( '%d note', '%d notes', warnings.length, 'dxai-ui' ), warnings.length ) }
					</summary>
					<ul>
						{ warnings.map( ( text, i ) => (
							<li key={ i }>
								<span className="dxai-done__msg">{ text }</span>
							</li>
						) ) }
					</ul>
				</details>
			) }

			<div className="dxai-actions dxai-done__actions">
				{ page.view && <a className="components-button is-primary" href={ page.view }>{ draft ? __( 'Preview page', 'dxai-ui' ) : __( 'View page', 'dxai-ui' ) }</a> }
				{ page.edit && <a className="components-button is-secondary" href={ page.edit }>{ draft ? __( 'Edit and publish', 'dxai-ui' ) : __( 'Edit page', 'dxai-ui' ) }</a> }
				<button type="button" className="components-button is-tertiary" onClick={ onReset }>{ __( 'Import another package', 'dxai-ui' ) }</button>
			</div>
		</section>
	);
}

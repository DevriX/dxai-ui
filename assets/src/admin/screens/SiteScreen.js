import { __ } from '@wordpress/i18n';
import Page from '../components/Page';
import Template from '../components/Template';
import Libraries from '../components/Libraries';
import Collections from '../components/Collections';
import { config, link } from '../router';

/*
 * Site: what is the site's, not one design's — its template (the theme, the header in Menus, the footer in Widgets, the
 * logo, the fonts, DX Base), and the collections every import adds to (pages, patterns, template parts, menus, snapshots).
 */
export default function SiteScreen() {
	const meta = config.theme
		/* translators: %s: the active theme's name. */
		? __( 'The site’s template and what the imports made for the whole site.', 'dxai-ui' ) + ' ' + __( 'Theme:', 'dxai-ui' ) + ' ' + config.theme
		: __( 'The site’s template and what the imports made for the whole site.', 'dxai-ui' );
	const actions = (
		<>
			{ link( 'menus' ) && <a className="components-button is-secondary" href={ link( 'menus' ) }>{ __( 'Menus', 'dxai-ui' ) }</a> }
			{ link( 'widgets' ) && <a className="components-button is-secondary" href={ link( 'widgets' ) }>{ __( 'Widgets', 'dxai-ui' ) }</a> }
			{ link( 'themes' ) && <a className="components-button is-secondary" href={ link( 'themes' ) }>{ __( 'Themes', 'dxai-ui' ) }</a> }
		</>
	);

	return (
		<Page title={ __( 'Site', 'dxai-ui' ) } meta={ meta } actions={ actions } className="dxai-page--site">
			<div className="dxai-panel dxai-panel--stack">
				<Template />
				<Libraries />
				<Collections />
			</div>
		</Page>
	);
}

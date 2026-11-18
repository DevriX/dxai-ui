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
		? __( 'The site’s template and what the imports made for the whole site.', 'dx-ui' ) + ' ' + __( 'Theme:', 'dx-ui' ) + ' ' + config.theme
		: __( 'The site’s template and what the imports made for the whole site.', 'dx-ui' );
	const actions = (
		<>
			{ link( 'menus' ) && <a className="components-button is-secondary" href={ link( 'menus' ) }>{ __( 'Menus', 'dx-ui' ) }</a> }
			{ link( 'widgets' ) && <a className="components-button is-secondary" href={ link( 'widgets' ) }>{ __( 'Widgets', 'dx-ui' ) }</a> }
			{ link( 'themes' ) && <a className="components-button is-secondary" href={ link( 'themes' ) }>{ __( 'Themes', 'dx-ui' ) }</a> }
		</>
	);

	return (
		<Page title={ __( 'Site', 'dx-ui' ) } meta={ meta } actions={ actions } className="dxui-page--site">
			<div className="dxui-panel dxui-panel--stack">
				<Template />
				<Libraries />
				<Collections />
			</div>
		</Page>
	);
}

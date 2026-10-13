import { __ } from '@wordpress/i18n';
import Page from './components/Page';
import DesignsScreen from './screens/DesignsScreen';
import DesignScreen from './screens/DesignScreen';
import ConvertWizard from './screens/ConvertWizard';
import SiteScreen from './screens/SiteScreen';
import FormsScreen from './screens/FormsScreen';
import SettingsScreen from './screens/SettingsScreen';
import { useRoute } from './router';
import './screens/screens.css';

/*
 * The app is one of five screens, told by WordPress which (dxaiUI.screen); the menu, the admin bar and the page's
 * frame are WordPress's own. Inside Designs the hash says whether the list or one design's page is open.
 */

function Designs() {
	const route = useRoute();
	if ( route.name === 'design' ) {
		return <DesignScreen id={ route.id } tab={ route.tab } />;
	}
	return <DesignsScreen />;
}

export default function App( { screen } ) {
	switch ( screen ) {
		case 'convert':
			return (
				<Page title={ __( 'Convert', 'dxai-ui' ) } meta={ __( 'A design archive, a pasted component or a Figma file, compiled into editable blocks.', 'dxai-ui' ) } className="dxai-page--convert">
					<ConvertWizard />
				</Page>
			);
		case 'site':
			return <SiteScreen />;
		case 'forms':
			return (
				<Page title={ __( 'Form entries', 'dxai-ui' ) } meta={ __( 'What the forms of the converted pages received.', 'dxai-ui' ) } className="dxai-page--forms">
					<FormsScreen />
				</Page>
			);
		case 'settings':
			return (
				<Page title={ __( 'Settings', 'dxai-ui' ) } meta={ __( 'The AI engines and their keys, and how the pages behave.', 'dxai-ui' ) } className="dxai-page--settings">
					<SettingsScreen />
				</Page>
			);
		default:
			return <Designs />;
	}
}

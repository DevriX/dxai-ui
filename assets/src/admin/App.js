import { __ } from '@wordpress/i18n';
import Chrome from './Chrome';
import ConvertWizard from './screens/ConvertWizard';
import LibraryScreen from './screens/LibraryScreen';
import FormsScreen from './screens/FormsScreen';
import SettingsScreen from './screens/SettingsScreen';

const SCREEN_META = {
	convert: {
		kicker: __( 'DX Studio', 'dxai-ui' ),
		title: __( 'Convert the design, keep the craft.', 'dxai-ui' ),
		description: __( 'Figma, Lovable, or a generated ZIP compiled into native Gutenberg with structure, assets, and motion intact.', 'dxai-ui' ),
	},
	library: {
		kicker: __( 'DX Library', 'dxai-ui' ),
		title: __( 'Review every generated asset.', 'dxai-ui' ),
		description: __( 'Pages, patterns, template parts, menus, and revisions in one product-style workspace.', 'dxai-ui' ),
	},
	forms: {
		kicker: __( 'DX Entries', 'dxai-ui' ),
		title: __( 'Track submissions from generated forms.', 'dxai-ui' ),
		description: __( 'Inspect captured leads, export them quickly, and keep delivery quality visible from inside the plugin.', 'dxai-ui' ),
	},
	settings: {
		kicker: __( 'DX Engine Room', 'dxai-ui' ),
		title: __( 'Control providers, models, and secrets.', 'dxai-ui' ),
		description: __( 'Encrypted credentials, active model routing, and connection checks in one place.', 'dxai-ui' ),
	},
};

export default function App( { screen, version } ) {
	const view = screen || 'convert';
	const meta = SCREEN_META[ view ] || SCREEN_META.convert;

	return (
		<Chrome screen={ view } version={ version } meta={ meta }>
			{ view === 'library' && <LibraryScreen /> }
			{ view === 'forms' && <FormsScreen /> }
			{ view === 'settings' && <SettingsScreen /> }
			{ ( view === 'convert' || ! [ 'library', 'forms', 'settings' ].includes( view ) ) && <ConvertWizard /> }
		</Chrome>
	);
}

<?php
/**
 * REST bootstrap.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\API;

final class Rest_Registrar {

	public function register(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				// While the plugin is deactivated and only kept rendering, the forms
				// on converted pages still submit; nothing else is served.
				if ( \DXAI_UI\Support\Runtime::render_only() ) {
					( new Form_Controller() )->register_routes();
					return;
				}
				( new Settings_Controller() )->register_routes();
				( new Converter_Controller() )->register_routes();
				( new Form_Controller() )->register_routes();
				( new Transfer_Controller() )->register_routes();
				( new Site_Pages_Controller() )->register_routes();
				( new Theme_Colors_Controller() )->register_routes();
			}
		);
	}
}

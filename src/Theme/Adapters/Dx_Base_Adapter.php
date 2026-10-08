<?php
/**
 * DX Base: the theme the plugin carries for a site that has no DX theme of its own.
 *
 * @package DXAI_UI\Theme\Adapters
 */

declare(strict_types=1);

namespace DXAI_UI\Theme\Adapters;

use DXAI_UI\Theme\Theme_Compat;

/**
 * DX Base (Theme\Base_Theme) has the same menu locations and widget areas as a DX theme and draws the design's header and footer on every
 * page the way one does; it has no colours, fonts or blocks of its own, so a design's pictures stay the core Image and its colours become
 * the site's presets (it lets a design be the site's brand). Tried first: it is a DX theme by Theme_Compat::dx_themes() too.
 */
final class Dx_Base_Adapter extends Theme_Adapter {

	public function id(): string {
		return 'dx-base';
	}

	public function matches(): bool {
		return Theme_Compat::is_base_theme();
	}

	public function is_dx(): bool {
		return true;
	}
}

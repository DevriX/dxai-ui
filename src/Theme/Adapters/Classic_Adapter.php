<?php
/**
 * Any other classic theme.
 *
 * @package DXAI_UI\Theme\Adapters
 */

declare(strict_types=1);

namespace DXAI_UI\Theme\Adapters;

/**
 * A classic theme that is not a DX theme (Astra, Kadence, GeneratePress, a custom one): it renders the site's header and footer itself from
 * its own locations and areas, so an import keeps a design's chrome on the design's pages (Chrome_Choice), the design follows the theme's
 * palette where it has one (Theme_Binding), and its elements stay core blocks. The adapter every theme gets when no other reads it.
 */
final class Classic_Adapter extends Theme_Adapter {

	public function id(): string {
		return 'classic';
	}

	public function matches(): bool {
		return true;
	}
}

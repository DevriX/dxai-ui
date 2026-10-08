<?php
/**
 * A block theme: Twenty Twenty-Five and the like.
 *
 * @package DXAI_UI\Theme\Adapters
 */

declare(strict_types=1);

namespace DXAI_UI\Theme\Adapters;

/**
 * A theme made of block templates, with a theme.json. It draws nothing of the site's chrome itself, so an import may install a design's
 * header and footer as template parts and menus (Theme_Compat::may_install_chrome()), and a design's colours become its presets
 * (Design_Theme_Json). It has no blocks of its own that a design's elements become.
 */
final class Block_Theme_Adapter extends Theme_Adapter {

	public function id(): string {
		return 'block';
	}

	public function matches(): bool {
		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	}
}

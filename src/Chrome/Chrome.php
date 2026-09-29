<?php
/**
 * The site's header and footer, edited where WordPress edits them.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Chrome;

/**
 * Converted design's header and footer are not stored in page content: the
 * header is built from Appearance > Menus and the footer from Appearance >
 * Widgets (or kept as template parts). Page_Chrome injects them around the
 * body on the front; each component below registers its own pieces.
 */
final class Chrome {

	/** Components, registered in this order when their class exists. */
	private const COMPONENTS = array(
		Header_Menus::class,
		Site_Header_Block::class,
		Footer_Widgets::class,
		Site_Footer_Block::class,
		Widgets_Screen::class,
		Page_Chrome::class,
	);

	public function register(): void {
		foreach ( self::COMPONENTS as $class ) {
			if ( class_exists( $class ) ) {
				( new $class() )->register();
			}
		}
	}
}

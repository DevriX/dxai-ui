<?php
/**
 * A DX theme: American Restoration and the themes built on it.
 *
 * @package DXAI_UI\Theme\Adapters
 */

declare(strict_types=1);

namespace DXAI_UI\Theme\Adapters;

use DXAI_UI\Theme\Theme_Compat;

/**
 * The theme the team builds its sites on, and any child of it or theme the `dxai_ui_dx_themes` filter names (Theme_Compat::dx_themes()).
 * It draws the site's header from Menus and its footer from Widgets on every page, registers the blocks its own pages are made of — DX
 * Picture (a server-rendered <picture> with the right file for the screen), Link box (a link around any blocks) and Span (an inline label)
 * — and brings the YouTube facade's script and styles and what the block library's sections need on its own pages.
 *
 * The one place in the plugin that spells the theme's folder name.
 */
final class Amr_Adapter extends Theme_Adapter {

	/** The theme's folder names: the parent theme; a child theme is read through its parent. */
	public const SLUGS = array( 'american-restoration' );

	public function id(): string {
		return 'amr';
	}

	public function matches(): bool {
		return Theme_Compat::is_dx_theme() && ! Theme_Compat::is_base_theme();
	}

	public function is_dx(): bool {
		return true;
	}

	public function blocks(): array {
		return array(
			'picture'  => 'dx/picture',
			'link_box' => 'amr/link-box',
			'span'     => 'amr/span',
		);
	}

	public function markup(): array {
		return array(
			'amr/span' => array(
				'tag'     => 'span',
				'classes' => array( 'wp-block-amr-span', 'amr-span' ),
			),
		);
	}

	public function media_id_attrs(): array {
		return array(
			'dx/picture' => array( 'imageId', 'mobileImageId' ),
		);
	}

	public function picture_shape(): array {
		return array(
			'id'     => 'imageId',
			'url'    => 'imageUrl',
			'alt'    => 'imageAlt',
			'width'  => 'imageWidth',
			'height' => 'imageHeight',
			'mobile' => array( 'mobileImageId', 'mobileImageUrl', 'mobileImageWidth', 'mobileImageHeight' ),
		);
	}

	public function brings(): array {
		return array( 'video-facade', 'library-styles' );
	}
}

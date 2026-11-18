<?php
/**
 * Editable Gutenberg slider (inner slides stay as blocks).
 *
 * @package DX_UI
 */

declare(strict_types=1);

namespace DX_UI\Blocks;

final class Slider_Block {

	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	public function register_block(): void {
		register_block_type(
			'dx-ui/slider',
			array(
				'api_version'   => 3,
				'editor_script' => 'dx-ui-blocks-editor',
				'view_script'   => 'dx-ui-slider-view',
				'style'         => 'dx-ui-dynamic',
				'supports'      => array(
					'className' => true,
					'html'      => false,
					'align'     => array( 'wide', 'full' ),
				),
			)
		);
	}
}

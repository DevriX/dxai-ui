<?php
/**
 * Editable Gutenberg slider (inner slides stay as blocks).
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks;

final class Slider_Block {

	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	public function register_block(): void {
		register_block_type(
			'dxai-ui/slider',
			array(
				'api_version'   => 3,
				'editor_script' => 'dxai-ui-blocks-editor',
				'view_script'   => 'dxai-ui-slider-view',
				'style'         => 'dxai-ui-dynamic',
				'supports'      => array(
					'className' => true,
					'html'      => false,
					'align'     => array( 'wide', 'full' ),
				),
			)
		);
	}
}

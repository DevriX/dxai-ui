<?php
/**
 * The team's own sections as block patterns in the editor.
 *
 * @package DXAI_UI\Pages
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

/**
 * The library (Block_Library) is what the pages made from a Home are built from. It is also what a person building a page by hand
 * wants in the inserter: the same sections, in a category of their own ("DX Library"), made for the site they are inserted on. Each is
 * a pattern whose content is written when the editor asks for it (a pattern file that prints Block_Library::pattern()), so nothing is
 * made on a page of the site that is not in the editor.
 *
 * Only on a theme the sections are made for (Block_Library::theme_ok()): on another theme they would be the team's classes with nothing
 * to style them.
 */
final class Library_Patterns {

	/** The inserter's category. */
	public const CATEGORY = 'dxai-library';

	public function register(): void {
		add_action( 'init', array( $this, 'add' ) );
	}

	public function add(): void {
		if ( ! function_exists( 'register_block_pattern' ) || ! Block_Library::theme_ok() ) {
			return;
		}
		register_block_pattern_category(
			self::CATEGORY,
			array(
				'label'       => __( 'DX Library', 'dxai-ui' ),
				'description' => __( 'The team\'s own sections: the questions, the steps of the work, the places, the call to action.', 'dxai-ui' ),
			)
		);
		foreach ( Block_Library::entries() as $id => $entry ) {
			$file = DXAI_UI_DIR . Block_Library::DIR . 'patterns/' . $id . '.php';
			if ( ! is_readable( $file ) ) {
				continue;
			}
			register_block_pattern(
				'dxai-ui/library-' . $id,
				array(
					'title'         => (string) $entry['label'],
					'description'   => (string) $entry['label'],
					'categories'    => array( self::CATEGORY ),
					'keywords'      => array( 'dx', 'library', (string) $entry['role'] ),
					'viewportWidth' => 1280,
					'filePath'      => $file,
				)
			);
		}
	}
}

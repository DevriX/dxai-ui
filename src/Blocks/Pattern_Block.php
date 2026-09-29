<?php
/**
 * Dynamic wrapper block so pattern CSS loads even for synced refs.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks;

final class Pattern_Block {

	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_pattern_css' ) );
		add_filter( 'render_block', array( $this, 'maybe_enqueue_from_core_block' ), 10, 2 );
	}

	public function register_block(): void {
		register_block_type(
			'dxai-ui/pattern',
			array(
				'api_version'     => 3,
				'render_callback' => array( $this, 'render' ),
				'attributes'      => array(
					'patternId' => array(
						'type'    => 'number',
						'default' => 0,
					),
				),
			)
		);

		wp_register_style(
			'dxai-ui-isolate',
			DXAI_UI_URL . 'assets/css/frontend-isolate.css',
			array(),
			DXAI_UI_VERSION
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public function render( array $attributes, string $content ): string {
		$id = isset( $attributes['patternId'] ) ? (int) $attributes['patternId'] : 0;
		if ( $id > 0 ) {
			$this->enqueue_id( $id );
		}

		return $content;
	}

	public function enqueue_pattern_css(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$this->enqueue_from_content( (string) $post->post_content );
	}

	/**
	 * @param array<string, mixed> $block
	 */
	public function maybe_enqueue_from_core_block( string $content, array $block ): string {
		if ( ( $block['blockName'] ?? '' ) === 'core/block' && ! empty( $block['attrs']['ref'] ) ) {
			$ref = (int) $block['attrs']['ref'];
			if ( $ref && get_post_meta( $ref, '_dxai_ui_generated', true ) ) {
				$this->enqueue_id( $ref );
			}
		}

		return $content;
	}

	private function enqueue_from_content( string $content ): void {
		if ( ! str_contains( $content, 'dxai-ui' ) && ! str_contains( $content, 'wp:block' ) ) {
			return;
		}

		if ( preg_match_all( '/wp:block\s+\{[^}]*"ref":(\d+)/', $content, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				if ( get_post_meta( (int) $id, '_dxai_ui_generated', true ) ) {
					$this->enqueue_id( (int) $id );
				}
			}
		}
	}

	private function enqueue_id( int $id ): void {
		/*
		 * Read through Upload_Paths: new patterns store the sheet relative to
		 * uploads ('dxai-ui/pattern-N.css'), which wp_enqueue_style() would
		 * have prefixed with the site URL into a broken address, and older ones
		 * an absolute URL that may name a previous host. Versioned by the
		 * file's mtime, so a pattern updated in place is not served stale.
		 */
		$sheet = \DXAI_UI\Support\Upload_Paths::for_meta( $id, '_dxai_ui_css_url' );
		if ( $sheet['url'] === '' ) {
			return;
		}

		wp_enqueue_style( 'dxai-ui-isolate' );
		wp_enqueue_style( 'dxai-ui-pattern-' . $id, $sheet['url'], array( 'dxai-ui-isolate' ), \DXAI_UI\Support\Upload_Paths::version( $sheet['path'] ) );
	}
}

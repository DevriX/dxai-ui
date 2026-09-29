<?php
/**
 * Force WordPress-native dynamic blocks for blog, forms, sliders, and menus.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Dynamic_Rewriter {

	private Rewrite_Policy $policy;

	/**
	 * Without an explicit policy the site setting decides, which defaults to
	 * reproducing the design rather than replacing it.
	 */
	public function __construct( ?Rewrite_Policy $policy = null ) {
		$this->policy = $policy ?? Rewrite_Policy::from_options();
	}

	/**
	 * @param array<int, array<string, mixed>> $structures
	 * @return array<int, array<string, mixed>>
	 */
	public function rewrite_all( array $structures ): array {
		foreach ( $structures as $i => $structure ) {
			$structures[ $i ] = $this->rewrite( $structure );
		}

		return $structures;
	}

	/**
	 * @param array<string, mixed> $structure
	 * @return array<string, mixed>
	 */
	public function rewrite( array $structure ): array {
		$type   = (string) ( $structure['type'] ?? 'section' );
		$markup = (string) ( $structure['gutenberg_markup'] ?? '' );
		$class  = $this->extract_class( $markup );

		if ( 'blog' === $type && ! str_contains( $markup, '<!-- wp:query' ) && $this->policy->is_dynamic( $structure, 'blog' ) ) {
			// Prefer hydrating designed card chrome into a Query Loop over
			// discarding it — Blog_Hydrator runs on fidelity HTML too.
			if ( ! $this->is_designed( $markup ) ) {
				$structure['gutenberg_markup'] = $this->query_loop( $class );
			} else {
				$hydrated = ( new Blog_Hydrator() )->hydrate( $markup, (string) ( $structure['title'] ?? 'Blog' ) );
				$structure['gutenberg_markup'] = $hydrated['markup'];
			}
		}

		// `is_designed()` used to veto this branch as a stand-in for "do not
		// destroy the design". The policy states that outright now, so asking
		// for a native form gets one even when the markup is fully designed.
		if ( 'form' === $type && ! str_contains( $markup, 'wp:dxai-ui/form' ) && $this->policy->is_dynamic( $structure, 'form' ) ) {
			$block = $this->form_block( $structure['form_fields'] ?? array(), $class );
			if ( str_contains( $markup, '<form' ) && ! $this->wraps_raw_html( $markup ) ) {
				// Swap the element in place so surrounding blocks survive.
				$structure['gutenberg_markup'] = (string) preg_replace( '/<form\b[^>]*>.*?<\/form>/is', $block, $markup, 1 );
			} else {
				// A `wp:html` block is opaque: splicing a block comment inside
				// it would nest block delimiters, so replace it wholesale.
				$structure['gutenberg_markup'] = $block;
			}
		}

		if ( 'slider' === $type && ! str_contains( $markup, 'wp:dxai-ui/slider' ) && $this->policy->is_dynamic( $structure, 'slider' ) ) {
			$structure['gutenberg_markup'] = sprintf(
				'<!-- wp:dxai-ui/slider {"className":"%1$s"} -->' . "\n" .
				'<div class="wp-block-dxai-ui-slider dxai-slider %1$s">%2$s</div>' . "\n" .
				'<!-- /wp:dxai-ui/slider -->',
				esc_attr( $class ),
				$markup
			);
		}

		if ( 'hero' === $type && ! str_contains( $markup, 'wp:cover' ) && ! str_contains( $markup, 'wp:group' ) && ! str_contains( $markup, 'wp:html' ) && ! str_contains( $markup, 'wp:' . Design_Html::RAW_BLOCK ) ) {
			$structure['gutenberg_markup'] = sprintf(
				'<!-- wp:cover {"className":"%1$s","dimRatio":0,"isUserOverlayColor":false} -->' .
				'<div class="wp-block-cover %1$s"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-0 has-background-dim"></span><div class="wp-block-cover__inner-container">%2$s</div></div>' .
				'<!-- /wp:cover -->',
				esc_attr( $class !== '' ? $class : 'dxai-hero' ),
				$markup
			);
		}

		return $structure;
	}

	/**
	 * @param array<int, array<string, mixed>> $fields
	 */
	public function form_block( array $fields, string $class = '' ): string {
		if ( $fields === array() ) {
			$fields = array(
				array(
					'name'     => 'email',
					'type'     => 'email',
					'label'    => __( 'Email', 'dxai-ui' ),
					'required' => true,
				),
				array(
					'name'     => 'message',
					'type'     => 'textarea',
					'label'    => __( 'Message', 'dxai-ui' ),
					'required' => true,
				),
			);
		}

		$encoded = wp_json_encode(
			array(
				'fields'       => $fields,
				'submitLabel'  => __( 'Send', 'dxai-ui' ),
				'className'    => $class,
			)
		);

		return sprintf( '<!-- wp:dxai-ui/form %s /-->', is_string( $encoded ) ? $encoded : '{}' );
	}

	private function query_loop( string $class ): string {
		$class = $class !== '' ? $class : 'grid grid-cols-1 md:grid-cols-3 gap-6';

		return <<<HTML
<!-- wp:query {"queryId":1,"query":{"perPage":6,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false},"className":"{$class}"} -->
<div class="wp-block-query {$class}">
	<!-- wp:post-template {"layout":{"type":"grid","columnCount":3}} -->
		<!-- wp:group {"className":"flex flex-col gap-3"} -->
		<div class="wp-block-group flex flex-col gap-3">
			<!-- wp:post-featured-image {"isLink":true} /-->
			<!-- wp:post-title {"isLink":true} /-->
			<!-- wp:post-date /-->
			<!-- wp:post-excerpt {"moreText":"Read more"} /-->
		</div>
		<!-- /wp:group -->
	<!-- /wp:post-template -->
	<!-- wp:query-pagination -->
		<!-- wp:query-pagination-previous /-->
		<!-- wp:query-pagination-numbers /-->
		<!-- wp:query-pagination-next /-->
	<!-- /wp:query-pagination -->
	<!-- wp:query-no-results -->
		<!-- wp:paragraph -->
		<p>No posts yet.</p>
		<!-- /wp:paragraph -->
	<!-- /wp:query-no-results -->
</div>
<!-- /wp:query -->
HTML;
	}

	/**
	 * True when the section already has a native Gutenberg tree worth keeping.
	 * A typed "blog" or "form" hint must not wipe pixel-perfect cards/fields.
	 */
	/**
	 * True when the form lives inside a raw-HTML block, whose contents are not
	 * block markup and cannot host a block comment.
	 */
	private function wraps_raw_html( string $markup ): bool {
		if ( ! preg_match( Design_Html::raw_block_pattern(), $markup, $m ) ) {
			return false;
		}

		return str_contains( $m[1], '<form' );
	}

	private function is_designed( string $markup ): bool {
		if ( $markup === '' ) {
			return false;
		}

		return str_contains( $markup, '<!-- wp:group' )
			|| str_contains( $markup, '<!-- wp:heading' )
			|| ( str_contains( $markup, '<!-- wp:html' ) || str_contains( $markup, '<!-- wp:' . Design_Html::RAW_BLOCK ) )
			|| str_contains( $markup, '<!-- wp:image' )
			|| str_contains( $markup, 'wp:dxai-ui/link' );
	}

	private function extract_class( string $markup ): string {
		if ( preg_match( '/"className":"([^"]+)"/', $markup, $match ) ) {
			return html_entity_decode( $match[1], ENT_QUOTES );
		}
		if ( preg_match( '/class="([^"]+)"/', $markup, $match ) ) {
			return html_entity_decode( $match[1], ENT_QUOTES );
		}

		return '';
	}
}

<?php
/**
 * The design's scope element around a converted page's content.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Structures;

/**
 * A converted page's stylesheet is scoped to `.dxai-ui.dxai-ui--{id}`, so the
 * page's markup has to sit inside an element carrying those classes. That
 * element used to be stored IN the page: one core/group wrapping the header
 * part, every section and the footer part. In the editor the whole page was
 * then a single block — select it, open it, and only then reach a section —
 * which is not how a person expects to edit a page.
 *
 * The page now stores its sections as top-level blocks, and this filter adds
 * the scope element around the rendered content: the same element with the
 * same classes the group rendered (`wp-block-group alignfull dxai-ui
 * dxai-ui--{id} {design root classes} is-layout-flow
 * wp-block-group-is-layout-flow`), so the front end is byte-for-byte what it
 * was and every scoped rule still matches. On `the_content`, not in the page
 * template, so it holds whichever template the page is given — the plugin's
 * blank canvas or a theme's.
 *
 * A page stored before this change still carries its group; it has no
 * META, so it is left exactly as it is.
 */
final class Page_Scope {

	/** The page whose scope a page renders in: its own id, or Home's for a crawled page. */
	public const META = '_dxai_ui_scope_id';

	public function register(): void {
		// After do_blocks (9), wpautop and shortcodes: wraps the final HTML.
		add_filter( 'the_content', array( self::class, 'wrap' ), 20 );
		// Before do_blocks: an ordinary page's copied sections, each run of them in its own scope element.
		add_filter( 'the_content', array( self::class, 'wrap_runs' ), 8 );
	}

	/**
	 * On an ordinary page holding sections copied from a design page (Design_Attach), the scope element goes
	 * around those sections only — each run of consecutive design blocks — not around the whole content: the
	 * page's own blocks stay the theme's, styled as on any other page of it, and the theme's CSS is fenced off
	 * the design's sections (Theme_Fence). The element is raw HTML between the blocks, which do_blocks() passes
	 * through as it is.
	 *
	 * @param string|mixed $content
	 * @return string|mixed
	 */
	public static function wrap_runs( $content ) {
		if ( ! is_string( $content ) || ! str_contains( $content, '<!-- wp:' ) ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post instanceof \WP_Post || ! Design_Attach::attached( (int) $post->ID ) ) {
			return $content;
		}
		$scope = Design_Attach::scope_for( (int) $post->ID );
		if ( $scope < 1 ) {
			return $content;
		}
		$spans = self::top_level_blocks( $content );
		if ( $spans === array() ) {
			return $content;
		}
		// Runs of design blocks, joined across nothing but whitespace.
		$runs = array();
		foreach ( $spans as $span ) {
			if ( ! Design_Attach::holds_design_blocks( substr( $content, $span[0], $span[1] - $span[0] ) ) ) {
				continue;
			}
			$last = count( $runs ) - 1;
			if ( $last >= 0 && trim( substr( $content, $runs[ $last ][1], $span[0] - $runs[ $last ][1] ) ) === '' ) {
				$runs[ $last ][1] = $span[1];
			} else {
				$runs[] = $span;
			}
		}
		$open = '<div class="' . esc_attr( self::classes( (int) $post->ID, $scope ) ) . '">';
		foreach ( array_reverse( $runs ) as $run ) {
			$content = substr( $content, 0, $run[0] ) . $open . "\n" . substr( $content, $run[0], $run[1] - $run[0] ) . "\n</div>" . substr( $content, $run[1] );
		}

		return $content;
	}

	/**
	 * Where each top-level block starts and ends in serialized content: [start, end) byte offsets, found with the
	 * delimiter pattern of core's block parser. Empty when the delimiters do not balance.
	 *
	 * @return array<int, array{0:int, 1:int}>
	 */
	public static function top_level_blocks( string $content ): array {
		if ( ! preg_match_all( '/<!--\s+(\/)?wp:(?:[a-z][a-z0-9_-]*\/)?[a-z][a-z0-9_-]*\s+(?:\{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(\/)?-->/s', $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		$spans = array();
		$depth = 0;
		$start = 0;
		foreach ( $m as $token ) {
			$at     = (int) $token[0][1];
			$end    = $at + strlen( (string) $token[0][0] );
			$closer = isset( $token[1] ) && $token[1][1] !== -1 && $token[1][0] === '/';
			$void   = isset( $token[2] ) && $token[2][1] !== -1 && $token[2][0] === '/';
			if ( $closer ) {
				--$depth;
				if ( $depth < 0 ) {
					return array();
				}
				if ( $depth === 0 ) {
					$spans[] = array( $start, $end );
				}
			} elseif ( $void ) {
				if ( $depth === 0 ) {
					$spans[] = array( $at, $end );
				}
			} else {
				if ( $depth === 0 ) {
					$start = $at;
				}
				++$depth;
			}
		}

		return $depth === 0 ? $spans : array();
	}

	/**
	 * @param string|mixed $content
	 * @return string|mixed
	 */
	public static function wrap( $content ) {
		if ( ! is_string( $content ) || $content === '' ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return $content;
		}
		// Its own scope. An ordinary page showing a design has its copied sections wrapped already (wrap_runs()).
		$scope = Design_Attach::scope_for( (int) $post->ID );
		if ( $scope < 1 || Design_Attach::attached( (int) $post->ID ) ) {
			return $content;
		}
		// Already inside one (a legacy wrapped page re-saved with the meta).
		if ( preg_match( '/^\s*<div\b[^>]*\bclass="[^"]*\bdxai-ui--' . $scope . '\b/', $content ) ) {
			return $content;
		}

		return '<div class="' . esc_attr( self::classes( $post->ID, $scope ) ) . '">' . $content . '</div>';
	}

	/**
	 * The scope element's classes, in the order core/group rendered them.
	 */
	public static function classes( int $post_id, int $scope = 0 ): string {
		return 'wp-block-group alignfull ' . self::design_classes( $post_id, $scope ) . ' is-layout-flow wp-block-group-is-layout-flow';
	}

	/**
	 * Only the design's own scope classes — what the editor canvas needs for
	 * the scoped stylesheet to match, without core's group and layout classes
	 * (those would restyle the canvas body itself).
	 */
	public static function design_classes( int $post_id, int $scope = 0 ): string {
		$scope = $scope > 0 ? $scope : Design_Attach::scope_for( $post_id );
		$extra = trim( (string) get_post_meta( $post_id, '_dxai_ui_wrapper_class', true ) );
		// An ordinary page showing a design: that design's own wrapper classes.
		if ( $extra === '' && Design_Attach::attached( $post_id ) ) {
			$extra = trim( (string) get_post_meta( Design_Attach::source_for( $post_id ), '_dxai_ui_wrapper_class', true ) );
		}

		return trim( 'dxai-ui dxai-ui--' . $scope . ' ' . $extra );
	}
}

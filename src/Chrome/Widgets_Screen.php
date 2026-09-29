<?php
/**
 * Appearance > Widgets, styled as the footer it edits.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Chrome;

use DXAI_UI\Compiler\Token_Styles;
use DXAI_UI\Support\Upload_Paths;

/**
 * The footer's content is edited in Appearance > Widgets, so that screen
 * shows it in the design: the brand design's stylesheet, its fonts and
 * tokens, and the dxs- rules of the widgets' blocks are loaded, and each
 * footer area's block container gets the design's scope classes
 * (widgets-screen.js) plus the paint of the frame around it — the footer's
 * background and text colour, its type — so light text on a dark footer is
 * not edited as light text on white.
 *
 * The block widgets editor is not an iframe: whatever is enqueued for the
 * page reaches the blocks directly. The areas are stacked 700px panels, not
 * the footer's grid; what is reproduced is how each block looks, not where
 * the columns stand.
 */
final class Widgets_Screen {

	private const HANDLE = 'dxai-ui-widgets-screen';

	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * @param string $hook_suffix
	 */
	public static function enqueue( $hook_suffix = '' ): void {
		if ( $hook_suffix !== 'widgets.php' || ! function_exists( 'wp_use_widgets_block_editor' ) || ! wp_use_widgets_block_editor() ) {
			return;
		}
		$footer = Footer_Template::get();
		if ( $footer === array() ) {
			return;
		}
		$page  = (int) ( $footer['page_id'] ?? 0 );
		$scope = (int) ( $footer['scope'] ?? 0 );
		$deps  = array();

		if ( $page > 0 ) {
			$fonts = get_post_meta( $page, '_dxai_ui_font_urls', true );
			foreach ( is_array( $fonts ) ? array_values( $fonts ) : array() as $i => $url ) {
				$url = esc_url_raw( (string) $url );
				if ( $url !== '' && preg_match( '#^https?://#i', $url ) === 1 ) {
					wp_enqueue_style( self::HANDLE . '-font-' . $i, $url, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- a font CSS URL is versioned by its query.
				}
			}
			$sheet = Upload_Paths::for_meta( $page, '_dxai_ui_css_url' );
			if ( $sheet['url'] !== '' ) {
				wp_enqueue_style( self::HANDLE . '-design', $sheet['url'], array(), Upload_Paths::version( $sheet['path'] ) );
				// The brand's tokens pointed at the Styles presets, as on the
				// front end (Assets).
				$brand = Token_Styles::brand_css( $page );
				if ( $brand !== '' ) {
					wp_add_inline_style( self::HANDLE . '-design', $brand );
				}
				$deps[] = self::HANDLE . '-design';
			}
		}

		$file = DXAI_UI_DIR . 'assets/css/widgets-screen.css';
		wp_enqueue_style( self::HANDLE, DXAI_UI_URL . 'assets/css/widgets-screen.css', $deps, is_readable( $file ) ? (string) filemtime( $file ) : DXAI_UI_VERSION );

		/*
		 * The preset custom properties (--wp--preset--color--…), which the
		 * frame's text colour and the blocks' colour classes name. The editor
		 * styles define them inside each area too; defined on :root as well,
		 * they resolve wherever the paint is applied.
		 */
		$tokens = wp_get_global_stylesheet( array( 'variables' ) );
		$css    = $tokens;
		// A footer whose design is no longer the brand (another design was
		// imported since, without a footer) names presets the site may not
		// define any more; its colours are read from the design's own tokens
		// instead, as on the front end (Site_Footer_Block::preset_fallbacks()).
		$is_brand = $page > 0 && Token_Styles::is_brand( $page );
		foreach ( $footer['slots'] as $area => $slot ) {
			$id    = preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $area ) );
			$paint = str_replace( array( '<', '>', '{', '}' ), '', (string) ( is_array( $slot ) ? ( $slot['paint'] ?? '' ) : '' ) );
			if ( ! $is_brand ) {
				// The design's token of that name; a preset the design never
				// had (a theme utility's `text-white`) keeps the site's.
				$paint = (string) preg_replace( '/var\(--wp--preset--color--([a-z0-9_-]+)\)/', 'var(--dxai-$1,var(--wp--preset--color--$1))', $paint );
			}
			if ( $id !== '' && $paint !== '' ) {
				$css .= 'body .dxai-ui.dxai-ui-widget-area[data-widget-area-id="' . $id . '"]{' . $paint . '}';
			}
		}
		// The widgets' own rules, and the frame's (a block moved out of the
		// frame by hand keeps its class).
		$markup = Site_Footer_Block::markup();
		$css   .= Site_Footer_Block::rules_css( $markup );
		$css   .= Site_Footer_Block::preset_fallbacks( $markup, $page, $scope, 'body ' );
		wp_add_inline_style( self::HANDLE, $css );

		$js = DXAI_UI_DIR . 'assets/js/widgets-screen.js';
		wp_enqueue_script( self::HANDLE, DXAI_UI_URL . 'assets/js/widgets-screen.js', array(), is_readable( $js ) ? (string) filemtime( $js ) : DXAI_UI_VERSION, true );
		wp_localize_script(
			self::HANDLE,
			'dxaiUIWidgetsScreen',
			array(
				'areas'      => Footer_Template::areas(),
				'scopeClass' => $page > 0 ? \DXAI_UI\Structures\Page_Scope::design_classes( $page, $scope ) : '',
			)
		);
	}
}

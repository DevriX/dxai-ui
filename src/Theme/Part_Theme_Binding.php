<?php
/**
 * Keeps the generated header and footer attached to whichever theme is active.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Theme;

/**
 * A generated page references its header and footer as
 * `<!-- wp:template-part {"slug":"dxai-header-…","theme":"twentytwentyfive"} /-->`,
 * and the part itself is a wp_template_part post tagged with that theme.
 * Core renders the reference only when BOTH match the active theme
 * (wp-includes/blocks/template-part.php): its "theme" attribute must equal
 * get_stylesheet(), and the post must carry that theme's wp_theme term.
 *
 * So on a real site, switching the theme — or activating a child theme —
 * made every converted page lose its header and footer: measured, a
 * reference whose theme differs from the active one renders 0 bytes. And a
 * re-import after the switch could not find the old part (Template_Part_Factory
 * looked it up by the active theme's term) and wrote a duplicate.
 *
 * Two layers:
 *  - at render time, a reference to one of this plugin's parts always asks
 *    for the active theme, so the front end never depends on the stored
 *    attribute;
 *  - once per theme change, the parts are re-tagged with the active theme and
 *    the stored references are rewritten to name it, so the block editor —
 *    which reads the attribute as stored — finds them too.
 *
 * Only `dxai-` parts that this plugin generated are touched; a theme's own
 * parts and anything a person made are left alone.
 */
final class Part_Theme_Binding {

	/** The stylesheet the generated parts were last bound to. */
	public const OPTION = 'dxai_ui_parts_theme';

	public function register(): void {
		add_filter( 'render_block_data', array( $this, 'bind_reference' ) );
		add_action( 'init', array( $this, 'maybe_rebind' ), 20 );
	}

	/**
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	public function bind_reference( array $block ): array {
		if ( ( $block['blockName'] ?? '' ) !== 'core/template-part' ) {
			return $block;
		}
		$slug = (string) ( $block['attrs']['slug'] ?? '' );
		if ( ! str_starts_with( $slug, 'dxai-' ) ) {
			return $block;
		}
		$block['attrs']['theme'] = get_stylesheet();

		return $block;
	}

	/**
	 * Re-bind when the active theme is not the one the parts were bound to.
	 * One autoloaded option read per request when nothing changed.
	 *
	 * Not while a theme is being previewed: the Customizer and the Site
	 * Editor's Live Preview filter get_stylesheet() to the previewed theme
	 * for that request and its ajax/REST calls, so a rebind there would move
	 * every part and page to a theme that is not active, and the next normal
	 * request would move them all back — a full rewrite per flip, with live
	 * visitors in between asking for parts tagged with the wrong theme.
	 */
	public function maybe_rebind(): void {
		if ( is_customize_preview() || ! empty( $_GET['wp_theme_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only presence check, the same one core makes.
			return;
		}
		$theme = (string) get_stylesheet();
		if ( $theme === '' || get_option( self::OPTION, '' ) === $theme ) {
			return;
		}
		self::rebind( $theme );
		update_option( self::OPTION, $theme, true );
	}

	/**
	 * Tag every generated part with $theme and point every stored reference
	 * to one of them at $theme.
	 *
	 * The references are rewritten with a direct update of the one attribute,
	 * not wp_update_post(): that would re-save each whole page, and where the
	 * current user has no unfiltered_html the re-save runs KSES over content
	 * this change does not touch. The value written is get_stylesheet(), a
	 * directory name, and nothing else in the post changes.
	 *
	 * @return array{parts:int, posts:int}
	 */
	public static function rebind( string $theme ): array {
		global $wpdb;

		$done  = array(
			'parts' => 0,
			'posts' => 0,
		);
		$parts = get_posts(
			array(
				'post_type'      => 'wp_template_part',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_dxai_ui_generated',
				'meta_value'     => '1',
			)
		);
		if ( taxonomy_exists( 'wp_theme' ) ) {
			foreach ( $parts as $id ) {
				wp_set_object_terms( (int) $id, $theme, 'wp_theme' );
				++$done['parts'];
			}
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_type <> 'revision' AND post_status <> 'trash'",
				'%' . $wpdb->esc_like( '"slug":"dxai-' ) . '%'
			)
		);
		foreach ( (array) $ids as $id ) {
			$id      = (int) $id;
			$content = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $id ) );
			$bound   = preg_replace_callback(
				'/<!--\s+wp:template-part\s+(\{.*?\})\s+\/-->/',
				static function ( array $m ) use ( $theme ): string {
					$attrs = json_decode( $m[1], true );
					if ( ! is_array( $attrs ) || ! str_starts_with( (string) ( $attrs['slug'] ?? '' ), 'dxai-' ) || ( $attrs['theme'] ?? '' ) === $theme ) {
						return $m[0];
					}
					$attrs['theme'] = $theme;

					return '<!-- wp:template-part ' . serialize_block_attributes( $attrs ) . ' /-->';
				},
				$content
			);
			// preg_replace_callback() returns null when PCRE gives up (backtrack
			// or JIT stack limit — an unclosed opener before a long single-line
			// island is enough). Writing that would blank the page with no
			// revision, so a failed match leaves the post as it is.
			if ( ! is_string( $bound ) || $bound === '' || $bound === $content ) {
				continue;
			}
			$wpdb->update( $wpdb->posts, array( 'post_content' => $bound ), array( 'ID' => $id ) );
			clean_post_cache( $id );
			++$done['posts'];
		}

		return $done;
	}
}

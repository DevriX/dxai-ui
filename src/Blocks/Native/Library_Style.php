<?php
/**
 * The look of a design's Home, for the sections the library makes for its other pages.
 *
 * @package DXAI_UI\Blocks\Native
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks\Native;

use DXAI_UI\Compiler\Utility_Classes;

/**
 * A section of the library (Pages\Block_Library) is the team's own block, written with the team's own sizes: its title at 42px, 96px of
 * padding above and below. On a page made for a design it stands among the Home's sections, and the rule of a design's pages is that a
 * section title is the size the Home's section titles are, and a section has the padding the Home's sections have (Heading_Scale,
 * Section_Spacing). This gives a library section those two, once, when it is made for a page.
 *
 * It is the Home that is the measure and the Home is never changed by it: it reads what the two passes would say about the Home (their
 * profiles) and writes with their own hand (restyle), on the one section the library made. It is not a converter — it is in no list of
 * them, so no import, conversion or page of a design runs it — and nothing the passes do is different for it being here. A Home that says
 * nothing (too few titles or sections to tell) leaves the section as the team wrote it.
 */
final class Library_Style extends Style_Harmony {

	/** What the Typography panel writes for a title's size, weight, line, tracking and case. */
	private const OWN_TYPE = array( 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'textTransform', 'fontStyle' );

	public function id(): string {
		return 'library_style';
	}

	public function label(): string {
		return __( 'The look of the Home, for the library\'s sections', 'dxai-ui' );
	}

	public function source(): string {
		return 'core/group';
	}

	public function target(): string {
		return 'core/group';
	}

	/** Never a pass: there is no profile of its own. */
	protected static function profile_of( array $blocks ): ?array {
		return null;
	}

	/**
	 * The section with its titles and its padding as the Home's.
	 *
	 * @param array<string, mixed> $section A parse_blocks() block, the section's root.
	 * @return array<string, mixed>
	 */
	public static function follow( array $section, int $home ): array {
		if ( $home < 1 || get_post_status( $home ) === false || ! (bool) apply_filters( 'dxai_ui_harmony', true, $home ) ) {
			return $section;
		}

		$profile = Heading_Scale::profile( $home );

		return self::spacing( $profile !== null && isset( $profile[2] ) ? self::titles( $section, $home ) : $section, $home );
	}

	/**
	 * The section titles (the H2) in the style the Home's are: the pass's own work on each, done on a title that has had the typography of
	 * its own block settings taken off (a title set by hand in the Typography panel is left alone by the pass, and the team set theirs there).
	 * A title the pass would not change keeps what the team gave it.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function titles( array $block, int $home ): array {
		if ( ( $block['blockName'] ?? '' ) === 'core/heading' && (int) ( $block['attrs']['level'] ?? 2 ) === 2 ) {
			$align = $block['attrs']['style']['typography']['textAlign'] ?? null;
			$bare  = self::without_type( $block );
			Converter::set_context(
				array(
					'home' => $home,
					'post' => 0,
				)
			);
			try {
				$done = ( new Heading_Scale() )->page( array( $bare ) );
			} finally {
				Converter::set_context( array() );
			}
			if ( $done === null ) {
				return $block;
			}
			$new = $done['blocks'][0];
			// The alignment is the Typography panel's too, and a title with anything in it counts as set by hand: it is put back after.
			if ( is_string( $align ) ) {
				$new['attrs']['style']['typography']['textAlign'] = $align;
			}

			return $new;
		}
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $i => $child ) {
			if ( is_array( $child ) ) {
				$block['innerBlocks'][ $i ] = self::titles( $child, $home );
			}
		}

		return $block;
	}

	/**
	 * A title without the typography its own block settings gave it: the preset size, the Typography panel's values, and what they wrote in
	 * the tag (the classes and the inline style), so what the Home says can take their place.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function without_type( array $block ): array {
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		unset( $attrs['fontSize'], $attrs['style']['typography']['textAlign'] );
		foreach ( self::OWN_TYPE as $key ) {
			unset( $attrs['style']['typography'][ $key ] );
		}
		if ( isset( $attrs['style']['typography'] ) && $attrs['style']['typography'] === array() ) {
			unset( $attrs['style']['typography'] );
		}
		if ( isset( $attrs['style'] ) && $attrs['style'] === array() ) {
			unset( $attrs['style'] );
		}
		$block['attrs'] = $attrs;
		foreach ( (array) $block['innerContent'] as $k => $chunk ) {
			if ( ! is_string( $chunk ) || trim( $chunk ) === '' ) {
				continue;
			}
			// Only the title's own opening tag (the first one in its markup).
			$block['innerContent'][ $k ] = (string) preg_replace_callback(
				'/^(\s*<h[1-6]\b)([^>]*)(>)/i',
				static function ( array $m ): string {
					$attr = (string) preg_replace( '/\shas-[a-z0-9-]+-font-size\b/', '', $m[2] );
					$attr = (string) preg_replace_callback(
						'/\sstyle="([^"]*)"/',
						static function ( array $s ): string {
							$css = (string) preg_replace( '/(?:^|;)\s*(?:font-size|font-weight|font-style|line-height|letter-spacing|text-transform)\s*:[^;"]*/i', ';', $s[1] );
							$css = trim( (string) preg_replace( '/;{2,}/', ';', $css ), '; ' );

							return $css === '' ? '' : ' style="' . $css . '"';
						},
						$attr
					);

					return $m[1] . $attr . $m[3];
				},
				$chunk,
				1
			);
			break;
		}
		$block['innerHTML'] = implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) );

		return $block;
	}

	/**
	 * The section with the padding above and below that the Home's sections have: what the section set for itself (the block's padding
	 * settings, and a `py-` class) comes off and the Home's goes on. A section with none of its own (its content is padded inside), a
	 * Home with no rhythm to follow, or a section the pass cannot write to is left as the team wrote it.
	 *
	 * @param array<string, mixed> $section
	 * @return array<string, mixed>
	 */
	private static function spacing( array $section, int $home ): array {
		if ( ( $section['blockName'] ?? '' ) !== 'core/group' ) {
			return $section;
		}
		$dom = Section_Spacing::profile( $home );
		if ( $dom === null || ( $dom['top'] === null && $dom['bottom'] === null && $dom['mine_class'] === array() ) ) {
			return $section;
		}
		$attrs  = is_array( $section['attrs'] ?? null ) ? $section['attrs'] : array();
		$pad    = is_array( $attrs['style']['spacing']['padding'] ?? null ) ? $attrs['style']['spacing']['padding'] : array();
		$tokens = self::tokens( (string) ( $attrs['className'] ?? '' ) );
		$own    = array_values( array_filter( $tokens, static fn( string $t ): bool => preg_match( '/^(?:py|pt|pb)-\S+$/', self::base( $t ) ) === 1 ) );
		if ( ! isset( $pad['top'] ) && ! isset( $pad['bottom'] ) && $own === array() ) {
			return $section;
		}
		$whole = $section;
		unset( $attrs['style']['spacing']['padding']['top'], $attrs['style']['spacing']['padding']['bottom'] );
		if ( isset( $attrs['style']['spacing']['padding'] ) && $attrs['style']['spacing']['padding'] === array() ) {
			unset( $attrs['style']['spacing']['padding'] );
		}
		if ( isset( $attrs['style']['spacing'] ) && $attrs['style']['spacing'] === array() ) {
			unset( $attrs['style']['spacing'] );
		}
		if ( isset( $attrs['style'] ) && $attrs['style'] === array() ) {
			unset( $attrs['style'] );
		}
		$section['attrs'] = $attrs;
		// What those settings wrote in the tag.
		foreach ( (array) $section['innerContent'] as $k => $chunk ) {
			if ( is_string( $chunk ) && trim( $chunk ) !== '' ) {
				$section['innerContent'][ $k ] = (string) preg_replace_callback(
					'/^(\s*<[a-z][a-z0-9]*\b[^>]*?)\sstyle="([^"]*)"/i',
					static function ( array $m ): string {
						$css = trim( (string) preg_replace( '/(?:^|;)\s*padding-(?:top|bottom)\s*:[^;"]*/i', ';', $m[2] ), '; ' );

						return $m[1] . ( $css === '' ? '' : ' style="' . (string) preg_replace( '/;{2,}/', ';', $css ) . '"' );
					},
					$chunk,
					1
				);
				break;
			}
		}
		$section['innerHTML'] = implode( '', array_filter( (array) $section['innerContent'], 'is_string' ) );
		$put                  = array();
		if ( $dom['top'] !== null ) {
			$put[] = 'padding-top:' . $dom['top'];
		}
		if ( $dom['bottom'] !== null ) {
			$put[] = 'padding-bottom:' . $dom['bottom'];
		}
		$class = implode( ' ', array_merge( array_values( array_diff( $tokens, $own ) ), $dom['mine_class'] ) );
		$css   = Utility_Classes::fit_system( implode( ';', array_filter( array( trim( (string) ( $attrs['dxaiCss'] ?? '' ), '; ' ), implode( ';', $put ) ) ) ) );

		return self::restyle( $section, $class, $css ) ?? $whole;
	}
}

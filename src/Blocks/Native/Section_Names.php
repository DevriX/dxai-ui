<?php
/**
 * The name of a page's sections in the List View.
 *
 * @package DXAI_UI\Blocks\Native
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks\Native;

/**
 * The theme's pages name each section in the List View — "Hero Section", "FAQ Section", "CTA Banner" — through the
 * block's `metadata.name`, so a long page is navigated by what its sections are, not by forty blocks called
 * "Group". This does the same for the top-level groups of a design: a name from the section's anchor, else from what
 * it holds (the page's first heading is the hero, a form is the contact section), else from its heading.
 *
 * The name is an attribute of the block comment: the HTML, the classes and the look do not change, and a person's own
 * name for a section is kept.
 */
final class Section_Names extends Converter {

	public function id(): string {
		return 'names';
	}

	public function label(): string {
		return __( 'Sections named in the List View', 'dxai-ui' );
	}

	public function source(): string {
		return 'core/group';
	}

	public function target(): string {
		return 'core/group';
	}

	public function available(): bool {
		return true;
	}

	public function convert( array $block, ?array $parent = null ): ?array {
		// Sections are the top-level groups of a post.
		if ( $parent !== null || ( $block['blockName'] ?? '' ) !== 'core/group' || ( $block['innerBlocks'] ?? array() ) === array() ) {
			return null;
		}
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		if ( ! empty( $attrs['metadata']['name'] ) ) {
			return null;
		}
		$name = self::name( $block );
		if ( $name === '' ) {
			return null;
		}
		$attrs['metadata'] = array_merge( is_array( $attrs['metadata'] ?? null ) ? $attrs['metadata'] : array(), array( 'name' => $name ) );
		$block['attrs']    = $attrs;

		return $block;
	}

	/**
	 * @param array<string, mixed> $block
	 */
	private static function name( array $block ): string {
		$attrs = $block['attrs'];
		$tag   = (string) ( $attrs['tagName'] ?? 'div' );
		if ( $tag === 'header' || $tag === 'footer' ) {
			return $tag === 'header' ? __( 'Header', 'dxai-ui' ) : __( 'Footer', 'dxai-ui' );
		}
		$anchor = (string) ( $attrs['anchor'] ?? '' );
		$found  = self::scan( $block );
		if ( $found['h1'] ) {
			return __( 'Hero Section', 'dxai-ui' );
		}
		if ( $anchor !== '' && ! in_array( $anchor, array( 'main', 'content', 'top' ), true ) ) {
			return self::title( $anchor ) . ' ' . __( 'Section', 'dxai-ui' );
		}
		if ( $found['form'] ) {
			return __( 'Contact Section', 'dxai-ui' );
		}
		if ( $found['heading'] !== '' ) {
			return self::short( $found['heading'] );
		}
		if ( $found['buttons'] ) {
			return __( 'Call to Action', 'dxai-ui' );
		}

		return '';
	}

	/**
	 * What a section holds: whether a level-1 heading, a form or buttons, and its first heading's text.
	 *
	 * @param array<string, mixed> $block
	 * @return array{h1:bool, form:bool, buttons:bool, heading:string}
	 */
	private static function scan( array $block ): array {
		$out  = array(
			'h1'      => false,
			'form'    => false,
			'buttons' => false,
			'heading' => '',
		);
		$walk = static function ( array $b ) use ( &$walk, &$out ): void {
			$name = (string) ( $b['blockName'] ?? '' );
			if ( $name === 'core/heading' ) {
				if ( (int) ( $b['attrs']['level'] ?? 2 ) === 1 ) {
					$out['h1'] = true;
				}
				if ( $out['heading'] === '' ) {
					$out['heading'] = trim( html_entity_decode( wp_strip_all_tags( (string) ( $b['innerHTML'] ?? '' ) ), ENT_QUOTES, 'UTF-8' ) );
				}
			} elseif ( $name === 'core/buttons' ) {
				$out['buttons'] = true;
			} elseif ( $name === 'dxai-ui/form' || $name === 'gravityforms/form' || $name === 'core/shortcode' && str_contains( (string) ( $b['innerHTML'] ?? '' ), '[gravityform' ) ) {
				$out['form'] = true;
			}
			foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $child ) {
				if ( is_array( $child ) ) {
					$walk( $child );
				}
			}
		};
		foreach ( (array) $block['innerBlocks'] as $child ) {
			if ( is_array( $child ) ) {
				$walk( $child );
			}
		}

		return $out;
	}

	/** `where-we-work` → `Where we work`. */
	private static function title( string $anchor ): string {
		$words = trim( (string) preg_replace( '/[\s_-]+/', ' ', (string) preg_replace( '/([a-z])([A-Z])/', '$1 $2', $anchor ) ) );
		$known = array(
			'faq'  => 'FAQ',
			'faqs' => 'FAQ',
			'cta'  => 'CTA',
			'seo'  => 'SEO',
		);

		return $known[ strtolower( $words ) ] ?? ucfirst( $words );
	}

	private static function short( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );

		return mb_strlen( $text ) > 34 ? rtrim( mb_substr( $text, 0, 33 ) ) . '…' : $text;
	}
}

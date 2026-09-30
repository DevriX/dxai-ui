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
		// A page written as one wrapper around its header, main and sections: the sections are named, not the wrapper.
		if ( self::shell( $block ) ) {
			$inside = self::inside( $block );

			return $inside === $block ? null : $inside;
		}
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
		if ( $found['header'] && ! $found['h1'] && $found['heading'] === '' ) {
			return __( 'Header', 'dxai-ui' );
		}
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
	 * @return array{h1:bool, form:bool, buttons:bool, heading:string, header:bool}
	 */
	private static function scan( array $block ): array {
		$out  = array(
			'header'  => false,
			'h1'      => false,
			'form'    => false,
			'buttons' => false,
			'heading' => '',
		);
		$walk = static function ( array $b ) use ( &$walk, &$out ): void {
			$name = (string) ( $b['blockName'] ?? '' );
			if ( $name === 'core/group' && in_array( (string) ( $b['attrs']['tagName'] ?? '' ), array( 'header', 'nav' ), true ) ) {
				$out['header'] = true;
			}
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

	/**
	 * Whether a group only holds the page's parts: two or more groups (its header, main, sections) and nothing that
	 * says anything itself — no heading, text, list, picture or buttons.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function shell( array $block ): bool {
		if ( ! in_array( (string) ( $block['attrs']['tagName'] ?? 'div' ), array( 'div', 'main' ), true ) || ! empty( $block['attrs']['metadata']['name'] ) ) {
			return false;
		}
		$groups = 0;
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $child ) {
			$name = is_array( $child ) ? (string) ( $child['blockName'] ?? '' ) : '';
			if ( $name === 'core/group' ) {
				++$groups;
			} elseif ( ! in_array( $name, array( '', 'dxai-ui/link', 'dxai-ui/svg', 'dxai-ui/html', 'dxai-ui/box' ), true ) ) {
				return false;
			}
		}

		return $groups >= 2;
	}

	/**
	 * The shell with its parts named (a part that is itself a shell — the page's main — is named through).
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function inside( array $block ): array {
		foreach ( (array) $block['innerBlocks'] as $i => $child ) {
			if ( ! is_array( $child ) || ( $child['blockName'] ?? '' ) !== 'core/group' || ( $child['innerBlocks'] ?? array() ) === array() ) {
				continue;
			}
			if ( self::shell( $child ) ) {
				$block['innerBlocks'][ $i ] = self::inside( $child );
				continue;
			}
			$attrs = is_array( $child['attrs'] ?? null ) ? $child['attrs'] : array();
			if ( ! empty( $attrs['metadata']['name'] ) || (string) ( $attrs['tagName'] ?? 'div' ) === 'template' ) {
				continue;
			}
			$name = self::name( $child );
			if ( $name === '' ) {
				continue;
			}
			$attrs['metadata']          = array_merge( is_array( $attrs['metadata'] ?? null ) ? $attrs['metadata'] : array(), array( 'name' => $name ) );
			$child['attrs']             = $attrs;
			$block['innerBlocks'][ $i ] = $child;
		}

		return $block;
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

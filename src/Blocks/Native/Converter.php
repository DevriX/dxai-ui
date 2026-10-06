<?php
/**
 * One kind of custom block that has a native counterpart.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks\Native;

/**
 * A converter turns one stored block into the native block that says the same
 * thing, or declines (null) when the native block could not keep something the
 * block carries. It is only ever offered a block it may change: a decline is
 * always safe, a wrong conversion is not, so every converter lists the exact
 * attributes it knows how to move and refuses the rest.
 *
 * Converters work on parse_blocks() arrays and return one, so the same code
 * serves a fresh import and a page imported years ago.
 */
abstract class Converter {

	/** Stable id (stored in the summary, shown in the Library). */
	abstract public function id(): string;

	/** What it does, in words a person recognises. */
	abstract public function label(): string;

	/** The native block this converter writes. */
	abstract public function target(): string;

	/**
	 * Every custom block it reads (the one it is named for, unless it reads more).
	 *
	 * @return array<int, string>
	 */
	public function sources(): array {
		return array( $this->source() );
	}

	/** The custom block this converter reads. */
	abstract public function source(): string;

	/**
	 * Whether the native block is available on this site (a theme's own block is only there on that theme).
	 */
	public function available(): bool {
		return \WP_Block_Type_Registry::get_instance()->is_registered( $this->target() );
	}

	/**
	 * The converted block, or null to leave it as it is.
	 *
	 * @param array<string, mixed>      $block  A parse_blocks() block, its inner blocks already converted.
	 * @param array<string, mixed>|null $parent The attributes of the block it sits in, when it sits in one.
	 * @return array<string, mixed>|null
	 */
	abstract public function convert( array $block, ?array $parent = null ): ?array;

	/**
	 * A pass over the whole page, once every block has been converted one by one: for what depends on the page around a block and not on
	 * the block alone (the same style for the same kind of heading across the page, the same spacing for its sections). Null leaves the
	 * page as it is.
	 *
	 * @param array<int, array<string, mixed>> $blocks The page's top-level blocks (parse_blocks()).
	 * @return array{blocks:array<int, array<string, mixed>>, count:int}|null The blocks, and how many of them changed.
	 */
	public function page( array $blocks ): ?array {
		return null;
	}

	/**
	 * Runs of adjacent siblings this converter turns into ONE block (several calls to action into one Buttons block).
	 * Each: [ 'start' => first index, 'end' => last index, 'block' => the block that replaces them ].
	 *
	 * @param array<int, array<string, mixed>>  $siblings The blocks of one container, already converted one by one.
	 * @param array<string, mixed>|null         $parent   The attributes of that container.
	 * @param array<int, string|null>|null      $content  The container's innerContent (null at the top level of a post).
	 * @return array<int, array{start:int, end:int, block:array<string, mixed>}>
	 */
	public function runs( array $siblings, ?array $parent, ?array $content = null ): array {
		return array();
	}

	/**
	 * What the converters may need to know about the content they are working on: [ 'home' => the design's Home, 'post' => the post ].
	 *
	 * @var array<string, int>
	 */
	protected static array $context = array();

	/**
	 * @param array<string, int> $context
	 */
	public static function set_context( array $context ): void {
		self::$context = $context;
	}

	/**
	 * A block's attributes must all be ones the converter knows how to carry.
	 *
	 * @param array<string, mixed> $attrs
	 * @param array<int, string>   $known
	 */
	protected static function only( array $attrs, array $known ): bool {
		return array_diff( array_keys( $attrs ), $known ) === array();
	}

	/**
	 * The attributes of an opening tag as name => value, or null when the tag is not the one element expected or
	 * carries something odd (a duplicated attribute, a bare attribute with no value, unquoted values).
	 *
	 * @return array<string, string>|null
	 */
	protected static function tag_attributes( string $open, string $tag ): ?array {
		if ( preg_match( '/^<' . preg_quote( $tag, '/' ) . '((?:\s+[a-z][a-z0-9:_-]*="[^"]*")*)\s*>$/i', $open, $m ) !== 1 ) {
			return null;
		}
		$out = array();
		if ( preg_match_all( '/\s+([a-z][a-z0-9:_-]*)="([^"]*)"/i', $m[1], $pairs, PREG_SET_ORDER ) ) {
			foreach ( $pairs as $pair ) {
				$name = strtolower( $pair[1] );
				if ( isset( $out[ $name ] ) ) {
					return null;
				}
				$out[ $name ] = html_entity_decode( $pair[2], ENT_QUOTES, 'UTF-8' );
			}
		}

		return $out;
	}

	/** The class list of a block as save() writes it after the ones the block itself generates. */
	protected static function class_tail( array $attrs ): string {
		$class = trim( (string) ( $attrs['className'] ?? '' ) );
		$css   = $attrs['dxaiCss'] ?? '';
		if ( is_string( $css ) && trim( $css ) !== '' ) {
			$class = trim( $class . ' ' . \DXAI_UI\Compiler\Style_Hoister::css_class( $css ) );
		}

		return $class;
	}

	/** Whether the block's own classes or CSS say which display it has (a utility's !important, or its dxs- rule). */
	protected static function states_display( array $attrs ): bool {
		$class = ' ' . trim( (string) ( $attrs['className'] ?? '' ) ) . ' ';
		if ( preg_match( '/\s(?:d-(?:block|flex|grid|inline|inline-block|inline-flex|contents|none)|block|flex|grid|inline-block|inline-flex|flex-inline|inline|contents|hidden)\s/', $class ) === 1 ) {
			return true;
		}

		return preg_match( '/(?:^|;)\s*display\s*:/i', (string) ( $attrs['dxaiCss'] ?? '' ) ) === 1;
	}

	/** Whether the block's own classes or CSS say its text colour. */
	protected static function states_color( array $attrs ): bool {
		$class = ' ' . trim( (string) ( $attrs['className'] ?? '' ) ) . ' ';
		if ( preg_match( '/\s(?:text-dxai-[a-z0-9-]+|has-[a-z0-9-]+-color|text-inherit|text-current)\s/', $class ) === 1 ) {
			return true;
		}

		return preg_match( '/(?:^|;)\s*color\s*:/i', (string) ( $attrs['dxaiCss'] ?? '' ) ) === 1;
	}

	/** Characters on which the editor's attribute escaper and PHP's write different bytes for the same value. */
	protected static function hazard( string $value ): bool {
		return preg_match( '/[\'<>"]/', $value ) === 1;
	}
}

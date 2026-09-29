<?php
/**
 * Whether a structure keeps the design or becomes a WordPress-native block.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

use DXAI_UI\Settings\Options;

/**
 * A blog section can be a pixel copy of the designed cards, or a Query Loop
 * that pulls real posts. It cannot be both, and the same trade-off applies to
 * forms, sliders and menus: the native block is editable and data-driven, the
 * copy is faithful. That choice belongs to whoever runs the conversion, per
 * structure, so it is resolved here instead of being hard-coded in the
 * rewriters.
 *
 * Default is `copy`: an import reproduces what the designer built, and going
 * dynamic is opted into.
 */
final class Rewrite_Policy {

	/** Keep the compiled design exactly as it is. */
	public const COPY = 'copy';

	/** Replace the design with the WordPress-native dynamic block. */
	public const DYNAMIC = 'dynamic';

	/**
	 * Structure types whose handling is a choice rather than a fallback.
	 *
	 * `hero` is absent on purpose: its rewrite only fires when the markup is
	 * not already valid blocks, so it never overwrites a designed hero.
	 *
	 * @var array<int, string>
	 */
	public const TYPES = array( 'blog', 'form', 'slider', 'navigation' );

	/** @var array<string, string> */
	private array $modes;

	/**
	 * @param array<string, string> $modes
	 */
	public function __construct( array $modes = array() ) {
		$this->modes = self::sanitize( $modes );
	}

	/**
	 * Reproduce the design everywhere.
	 */
	public static function fidelity(): self {
		return new self();
	}

	/**
	 * Convert every supported structure to its native block.
	 */
	public static function dynamic(): self {
		$modes = array();
		foreach ( self::TYPES as $type ) {
			$modes[ $type ] = self::DYNAMIC;
		}

		return new self( $modes );
	}

	/**
	 * The site-wide setting, falling back to fidelity.
	 */
	public static function from_options(): self {
		if ( ! function_exists( 'get_option' ) ) {
			return self::fidelity();
		}

		$settings = Options::get();
		$stored   = $settings['structure_mode'] ?? array();

		return new self( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Build from a generation payload, honouring an explicit `structure_mode`
	 * map and otherwise the saved setting.
	 *
	 * @param array<string, mixed> $result
	 */
	public static function from_result( array $result ): self {
		if ( isset( $result['structure_mode'] ) && is_array( $result['structure_mode'] ) ) {
			return new self( $result['structure_mode'] );
		}

		return self::from_options();
	}

	/**
	 * Mode for a type, after any per-structure override.
	 *
	 * @param array<string, mixed> $structure
	 */
	public function mode_for( array $structure, string $type ): string {
		$override = $structure['mode'] ?? ( $structure['rewrite'] ?? null );
		if ( is_string( $override ) ) {
			$override = strtolower( trim( $override ) );
			if ( self::DYNAMIC === $override || self::COPY === $override ) {
				return $override;
			}
		}

		return $this->mode( $type );
	}

	public function mode( string $type ): string {
		return $this->modes[ $type ] ?? self::COPY;
	}

	/**
	 * @param array<string, mixed> $structure
	 */
	public function is_dynamic( array $structure, string $type ): bool {
		return self::DYNAMIC === $this->mode_for( $structure, $type );
	}

	/**
	 * @return array<string, string>
	 */
	public function to_array(): array {
		$out = array();
		foreach ( self::TYPES as $type ) {
			$out[ $type ] = $this->mode( $type );
		}

		return $out;
	}

	/**
	 * Keep only known types, and only the two modes.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, string>
	 */
	public static function sanitize( array $input ): array {
		$out = array();
		foreach ( self::TYPES as $type ) {
			$value = $input[ $type ] ?? null;
			if ( ! is_string( $value ) ) {
				continue;
			}
			$value = strtolower( trim( $value ) );
			if ( self::DYNAMIC === $value ) {
				$out[ $type ] = self::DYNAMIC;
			} elseif ( self::COPY === $value ) {
				$out[ $type ] = self::COPY;
			}
		}

		return $out;
	}
}

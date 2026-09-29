<?php
/**
 * Decide whether Pass-2 LLM refine should run.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Fidelity_Gate {

	/**
	 * @param array{
	 *   score:int,
	 *   issues:array<int, string>,
	 *   needs_refine:array<int, int>
	 * } $assessment
	 */
	public static function should_refine( array $assessment, string $mode ): bool {
		$mode = self::normalize_mode( $mode );
		if ( $mode === 'off' ) {
			return false;
		}
		if ( $mode === 'strict' ) {
			return ( $assessment['needs_refine'] ?? array() ) !== array()
				|| (int) ( $assessment['score'] ?? 100 ) < Fidelity_Confidence::THRESHOLD_STRICT;
		}

		// auto
		if ( (int) ( $assessment['score'] ?? 100 ) < Fidelity_Confidence::THRESHOLD_AUTO ) {
			return true;
		}

		return ( $assessment['needs_refine'] ?? array() ) !== array();
	}

	public static function normalize_mode( string $mode ): string {
		$mode = sanitize_key( $mode );

		return in_array( $mode, array( 'auto', 'strict', 'off' ), true ) ? $mode : 'auto';
	}

	/**
	 * Max structures refined in one convert (cost guard).
	 */
	public static function max_structures( string $mode ): int {
		return self::normalize_mode( $mode ) === 'strict' ? 10 : 6;
	}
}

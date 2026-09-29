<?php
/**
 * Conversion log helper (admin-only, not secrets).
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Support;

final class Logger {

	public const TRANSIENT = 'dxai_ui_last_log';

	/**
	 * @param array<int, array{level:string, message:string, time:string}> $entries
	 */
	public static function store( array $entries ): void {
		set_transient( self::TRANSIENT, $entries, HOUR_IN_SECONDS );
	}

	/**
	 * @return array<int, array{level:string, message:string, time:string}>
	 */
	public static function get(): array {
		$stored = get_transient( self::TRANSIENT );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * @return array{level:string, message:string, time:string}
	 */
	public static function entry( string $level, string $message ): array {
		return array(
			'level'   => sanitize_key( $level ),
			'message' => sanitize_text_field( $message ),
			'time'    => gmdate( 'c' ),
		);
	}
}

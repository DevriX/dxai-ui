<?php
/**
 * What the plugin calls in WP-CLI and in plugins a site may have (ACF), for PHPStan only (phpstan.neon.dist scans it; nothing
 * loads it). php-stubs/wp-cli-stubs wants WordPress stubs before 7.0, so the few methods are described here.
 *
 * @package DXAI_UI
 */

// phpcs:ignoreFile

namespace {
	/** WP-CLI's static facade. */
	class WP_CLI {
		/**
		 * @param string               $name
		 * @param callable|string      $callable
		 * @param array<string, mixed> $args
		 */
		public static function add_command( $name, $callable, $args = array() ): bool {
			return true;
		}

		/** @param string $message */
		public static function line( $message = '' ): void {}

		/** @param string $message */
		public static function log( $message ): void {}

		/** @param string $message */
		public static function success( $message ): void {}

		/** @param string $message */
		public static function warning( $message ): void {}

		/**
		 * @param string|\WP_Error|\Exception $message
		 * @param bool|int                     $exit
		 * @return never
		 */
		public static function error( $message, $exit = true ) {
			exit( 1 );
		}
	}

	/**
	 * Advanced Custom Fields, when a site runs it (Theme_Options asks first).
	 *
	 * @param string          $selector
	 * @param int|string|bool $post_id
	 * @param bool            $format_value
	 * @return mixed
	 */
	function get_field( $selector, $post_id = false, $format_value = true ) {
		return null;
	}
}

namespace WP_CLI\Utils {
	/**
	 * @param string                           $format
	 * @param array<int, array<string, mixed>> $items
	 * @param array<int, string>|string        $fields
	 */
	function format_items( $format, $items, $fields ): void {}
}

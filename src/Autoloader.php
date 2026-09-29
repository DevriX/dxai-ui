<?php
/**
 * PSR-4 autoloader for the DXAI_UI namespace.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI;

final class Autoloader {

	private const PREFIX = 'DXAI_UI\\';

	/**
	 * Class names whose files do not match PSR-4 1:1.
	 *
	 * @var array<string, string>
	 */
	private const ALIASES = array(
		'Engines\\LLM_Provider_Interface' => 'Engines/LLM_Interface.php',
	);

	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	public static function load( string $class ): void {
		if ( ! str_starts_with( $class, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		$key      = str_replace( '/', '\\', $relative );
		$mapped   = self::ALIASES[ $key ] ?? ( str_replace( '\\', '/', $relative ) . '.php' );
		$path     = DXAI_UI_DIR . 'src/' . $mapped;
		$path     = str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $path );

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}

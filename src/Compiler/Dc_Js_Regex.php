<?php
/**
 * A JavaScript regular-expression literal, for `.replace(/\D/g, '')`.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Dc_Js_Regex {

	public function __construct(
		public readonly string $pattern,
		public readonly string $flags,
	) {}

	public function is_global(): bool {
		return str_contains( $this->flags, 'g' );
	}

	/** The PCRE form: `#`-delimited, with the JS flags PCRE shares. */
	public function pcre(): string {
		$mods = '';
		foreach ( array( 'i', 'm', 's', 'u' ) as $flag ) {
			if ( str_contains( $this->flags, $flag ) ) {
				$mods .= $flag;
			}
		}
		if ( $mods === '' || ! str_contains( $mods, 'u' ) ) {
			$mods .= 'u';
		}

		return '#' . str_replace( '#', '\#', $this->pattern ) . '#' . $mods;
	}
}

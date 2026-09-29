<?php
/**
 * Contract for one namespace of Tailwind utilities.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler\Tailwind;

/**
 * Modules are consulted in registration order and the first non-null answer
 * wins, so each one must recognise only utilities it genuinely owns and return
 * null for everything else. Returning declarations for a class it does not
 * understand would mask a later module that does.
 */
interface Utility_Module {

	/**
	 * Declarations for this candidate, or null when the module does not own it.
	 *
	 * Keys are CSS property names, values are CSS values, both already
	 * resolved against the theme. Declaration order is preserved. Returning an
	 * empty array means "owned, but emits nothing" and suppresses the class.
	 *
	 * @return array<string, string>|null
	 */
	public function resolve( Candidate $candidate, Theme $theme ): ?array;

	/**
	 * `@keyframes` blocks this module needs, keyed by animation name.
	 *
	 * Called once after all classes are resolved. Return an empty array when
	 * the module contributes none.
	 *
	 * @return array<string, string>
	 */
	public function keyframes(): array;
}

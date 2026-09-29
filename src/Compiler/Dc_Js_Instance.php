<?php
/**
 * The `this` of a Claude Design `class Component extends DCLogic`.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

/**
 * State, props, the instance fields the class declares (`vals = {}`) and its
 * methods. `setState()` is recorded rather than applied: at render time the
 * template is evaluated against ONE state, and which states a handler can
 * reach is worked out from the handler's source, not by running it.
 */
final class Dc_Js_Instance {

	/** @var array<string, mixed> */
	public array $state = array();

	/** @var array<string, mixed> */
	public array $props = array();

	/** @var array<string, mixed> Instance fields other than state/props. */
	public array $fields = array();

	/** @var array<string, Dc_Js_Fn> */
	public array $methods = array();

	/** @var array<int, array<string, mixed>> Patches passed to setState() while evaluating. */
	public array $patches = array();

	public function has_method( string $name ): bool {
		return isset( $this->methods[ $name ] );
	}
}

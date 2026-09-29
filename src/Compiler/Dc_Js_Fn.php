<?php
/**
 * A JavaScript function value inside the Claude Design logic interpreter.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

/**
 * Arrow functions and class methods alike: parameters, a body (an expression
 * for `x => x + 1`, a statement list otherwise), the scope they closed over,
 * and the `this` they were created under — an arrow keeps the `this` of its
 * definition, which in a `renderVals()` body is the component instance.
 */
final class Dc_Js_Fn {

	/**
	 * @param array<int, array{name: string, default: mixed, rest: bool, pattern: mixed}> $params
	 * @param array<mixed>                                                               $body
	 * @param array<string, mixed>                                                       $env
	 */
	public function __construct(
		public readonly array $params,
		public readonly array $body,
		public readonly bool $expression,
		public readonly array $env,
		public readonly ?Dc_Js_Instance $this_ref,
		public readonly string $source = '',
	) {}
}

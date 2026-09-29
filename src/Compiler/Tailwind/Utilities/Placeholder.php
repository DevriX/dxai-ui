<?php
/**
 * Placeholder utilities: the `placeholder-*` colour family.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler\Tailwind\Utilities;

use DXAI_UI\Compiler\Tailwind\Candidate;
use DXAI_UI\Compiler\Tailwind\Theme;
use DXAI_UI\Compiler\Tailwind\Utility_Module;

/**
 * Owns `placeholder-<colour>` — the utility that paints an input's placeholder
 * text — and nothing else in the namespace.
 *
 * One property, one value, and the narrowest module in the directory. What
 * makes it worth its own file rather than a few lines inside `Typography` is
 * where the declaration lands: upstream emits
 *
 *     .placeholder-red-500 { &::placeholder { color: var(--color-red-500); } }
 *
 * so the `color` belongs to a pseudo-element, not to the element the class sits
 * on. A module returns a flat declaration array and cannot say that, so the
 * `::placeholder` half is the *selector's* business and `Engine` adds it — the
 * same split `space-*` and `divide-*` already use for their
 * `> :not(:last-child)`. This file therefore emits the bare `color` and trusts
 * that suffix; read on its own the declaration looks like it would repaint the
 * whole field, and it would, without the Engine's half.
 *
 * Not to be confused with three neighbours that share the prefix and are not
 * ours. `placeholder:text-red-500` is the pseudo-element *variant*, which
 * `Variants::PSEUDO_ELEMENTS` resolves — its base is `text-red-500`, so it
 * never reaches the guard below. `placeholder-shown:` is a pseudo-*class*
 * variant. And `place-items-center` in `Flexgrid` is the near-collision to keep
 * in mind when editing the guard: `place-` and `placeholder-` first differ at
 * the sixth character, so a whole-prefix `str_starts_with` separates them but a
 * looser test on `place` would not.
 */
final class Placeholder implements Utility_Module {

	/**
	 * Colour keywords Tailwind resolves without touching the theme.
	 *
	 * Checked ahead of the theme, as in every other colour family here, so a
	 * design that happens to declare `--color-current` cannot capture the CSS
	 * keyword. `currentColor` is spelled camel-case to match `Theme::COLORS`
	 * and the five sibling tables; upstream prints the equivalent lower-case
	 * `currentcolor`, and changing one of the six without the rest would be the
	 * only inconsistency in the sheet.
	 */
	private const COLOR_KEYWORDS = array(
		'inherit'     => 'inherit',
		'current'     => 'currentColor',
		'transparent' => 'transparent',
	);

	/**
	 * @return array<string, string>|null
	 */
	public function resolve( Candidate $candidate, Theme $theme ): ?array {
		$base = $candidate->base;

		/*
		 * The single guard that keeps this module honest. Everything it owns
		 * begins at the `placeholder-` root, so keying on that whole prefix
		 * leaves `place-items-center`, `p-4`, `pl-2` and the two `placeholder`
		 * variants — whose bases are the utility they decorate — to the modules
		 * that own them.
		 */
		if ( ! str_starts_with( $base, 'placeholder-' ) ) {
			return null;
		}

		/*
		 * Upstream registers this as a colour utility with no negative form, so
		 * `-placeholder-red-500` is not a class at all. Refusing it here is
		 * what stops the engine inventing a shadow copy of the whole family.
		 */
		if ( $candidate->negative ) {
			return null;
		}

		$token = substr( $base, strlen( 'placeholder-' ) );
		if ( '' === $token ) {
			return null;
		}

		$color = self::is_wrapped( $token )
			? self::arbitrary_color( $token )
			: $this->theme_color( $theme, $token );

		if ( null === $color || '' === $color ) {
			return null;
		}

		if ( '' !== $candidate->modifier ) {
			$color = $theme->with_alpha( $color, $candidate->modifier );
			if ( '' === $color ) {
				return null;
			}
		}

		/*
		 * One declaration, and deliberately not the `@supports (color:
		 * color-mix(in lab, red, red))` twin upstream pairs with a translucent
		 * colour: `Theme::with_alpha()` has already folded the alpha into a
		 * single `color-mix(in oklab, …)`, which is how every colour family in
		 * this compiler resolves a `/opacity`. A module returns one block and
		 * could not express the second one anyway.
		 */
		return array( 'color' => $color );
	}

	/**
	 * @return array<string, string>
	 */
	public function keyframes(): array {
		return array();
	}

	/**
	 * A named colour: the three CSS keywords first, then the theme.
	 *
	 * Only the `--color-*` namespace is consulted. Upstream reads
	 * `["--background-color", "--color"]` for this family — literally the
	 * background namespace, not a `--placeholder-color` one, which is a v3
	 * compatibility leftover rather than a namespace anything declares. `Theme`
	 * does not model it and `caret-*` / `accent-*` skip their own equivalents
	 * the same way, so adding it for this one family would be the odd case out.
	 */
	private function theme_color( Theme $theme, string $value ): ?string {
		if ( isset( self::COLOR_KEYWORDS[ $value ] ) ) {
			return self::COLOR_KEYWORDS[ $value ];
		}

		return $theme->color( $value );
	}

	/**
	 * `placeholder-[#fff]`, `placeholder-[color:var(--x)]` and
	 * `placeholder-(--x)`.
	 *
	 * The value needs no data-type test: this utility writes one property, so
	 * an unhinted arbitrary value is a colour by construction — which is also
	 * exactly what upstream does, its colour handler passing the arbitrary text
	 * straight through without inspecting it. A `color:` hint is accepted for
	 * the same reason and any other hint is refused rather than emitted as a
	 * colour it plainly is not.
	 */
	private static function arbitrary_color( string $token ): ?string {
		$parsed = self::unwrap( $token );
		if ( null === $parsed ) {
			return null;
		}

		list( $hint, $value ) = $parsed;

		if ( '' !== $hint && 'color' !== $hint ) {
			return null;
		}

		return $value;
	}

	/**
	 * True for `[...]` and `(...)` values.
	 */
	private static function is_wrapped( string $value ): bool {
		return ( str_starts_with( $value, '[' ) && str_ends_with( $value, ']' ) )
			|| ( str_starts_with( $value, '(' ) && str_ends_with( $value, ')' ) );
	}

	/**
	 * Split a wrapped value into its optional `type:` hint and its value,
	 * expanding the `(--var)` shorthand into a `var()` reference.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private static function unwrap( string $value ): ?array {
		$parenthesised = str_starts_with( $value, '(' );
		$inner         = Candidate::decode_arbitrary( substr( $value, 1, -1 ) );
		if ( '' === $inner ) {
			return null;
		}

		$hint = '';
		if ( preg_match( '/^([a-z-]+):(.*)$/s', $inner, $match ) === 1 ) {
			$hint  = $match[1];
			$inner = trim( $match[2] );
		}

		if ( '' === $inner ) {
			return null;
		}

		// `placeholder-(red)` is not a utility: the parenthesised form is the
		// custom-property shorthand and nothing else.
		if ( $parenthesised && ! str_starts_with( $inner, '--' ) ) {
			return null;
		}

		if ( str_starts_with( $inner, '--' ) ) {
			$inner = 'var(' . $inner . ')';
		}

		return array( $hint, $inner );
	}
}

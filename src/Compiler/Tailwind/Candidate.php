<?php
/**
 * One parsed Tailwind class name.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler\Tailwind;

/**
 * A class name split into the parts a utility module needs.
 *
 * `md:hover:-mt-4` parses to variants `['md','hover']`, negative `true`,
 * base `mt-4`. `bg-ara-navy/90` parses to base `bg-ara-navy`, modifier `90`.
 * Bracketed segments are opaque: `data-[state=open]:` stays one variant and
 * `shadow-[0_2px_4px_rgba(0,0,0,.2)]` keeps its commas and slashes.
 */
final class Candidate {

	/** Full class name as authored. */
	public string $raw = '';

	/** Utility with variants stripped, modifier still attached. */
	public string $utility = '';

	/** Utility with variants, negation and modifier stripped. */
	public string $base = '';

	/** Text after the last unbracketed `/`, or '' when absent. */
	public string $modifier = '';

	/** True when the utility was authored with a leading `-`. */
	public bool $negative = false;

	/** @var array<int, string> Variants in author order, outermost first. */
	public array $variants = array();

	public static function parse( string $class ): ?self {
		$class = trim( $class );
		if ( $class === '' ) {
			return null;
		}

		$parts = self::split_top_level( $class, ':' );
		if ( $parts === array() ) {
			return null;
		}

		$utility = (string) array_pop( $parts );
		if ( $utility === '' ) {
			return null;
		}

		$candidate           = new self();
		$candidate->raw      = $class;
		$candidate->variants = $parts;
		$candidate->utility  = $utility;

		$base = $utility;
		if ( str_starts_with( $base, '-' ) ) {
			$candidate->negative = true;
			$base                = substr( $base, 1 );
		}

		$slashed = self::split_top_level( $base, '/' );
		if ( count( $slashed ) > 1 ) {
			$candidate->modifier = (string) array_pop( $slashed );
			$base                = implode( '/', $slashed );
		}

		$candidate->base = $base;

		return $candidate->base === '' ? null : $candidate;
	}

	/**
	 * True when the utility's value came from square brackets.
	 */
	public function is_arbitrary(): bool {
		return str_contains( $this->base, '[' ) && str_ends_with( $this->base, ']' );
	}

	/**
	 * Value inside the trailing `[...]`, with Tailwind's `_` placeholders
	 * turned back into spaces. Empty when the utility is not arbitrary.
	 */
	public function arbitrary_value(): string {
		if ( ! preg_match( '/\[(.*)\]$/s', $this->base, $m ) ) {
			return '';
		}

		return self::decode_arbitrary( $m[1] );
	}

	/**
	 * Tailwind encodes spaces as `_` inside brackets; a literal underscore is
	 * escaped as `\_`.
	 */
	public static function decode_arbitrary( string $value ): string {
		$value = str_replace( '\\_', "\0", $value );
		$value = str_replace( '_', ' ', $value );
		$value = str_replace( "\0", '_', $value );

		return trim( $value );
	}

	/**
	 * Split on a delimiter, ignoring delimiters inside (), [] or {}.
	 *
	 * @return array<int, string>
	 */
	public static function split_top_level( string $value, string $delimiter ): array {
		$out   = array();
		$buf   = '';
		$depth = 0;
		$len   = strlen( $value );

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $value[ $i ];
			if ( $char === '[' || $char === '(' || $char === '{' ) {
				++$depth;
			} elseif ( $char === ']' || $char === ')' || $char === '}' ) {
				$depth = max( 0, $depth - 1 );
			}

			if ( $char === $delimiter && $depth === 0 ) {
				$out[] = $buf;
				$buf   = '';
				continue;
			}

			$buf .= $char;
		}

		$out[] = $buf;

		return $out;
	}

	/**
	 * Escape a class name for use inside a CSS selector.
	 *
	 * A LEADING DIGIT needs a hex escape, not a backslash. CSS identifiers may
	 * not begin with a digit at all, so `.2xl\:p-8` is not an ill-advised
	 * selector — it is an invalid one, and a browser drops the entire rule.
	 * This escaped every non-alphanumeric and left the first character alone,
	 * so every utility carrying Tailwind's own `2xl:` breakpoint emitted CSS
	 * that silently did nothing. Tailwind writes `.\32 xl\:p-8`; the trailing
	 * space terminates the hex escape and is part of it.
	 *
	 * Not reachable from the seven designs in the corpus — they use `2xl:`
	 * nowhere, so the emitted CSS contains no such selector and every geometry
	 * run was clean. It would break an arbitrary design that uses a standard
	 * breakpoint, which is the whole category of defect a corpus cannot find.
	 */
	public static function escape( string $class ): string {
		$escaped = (string) preg_replace( '/([^a-zA-Z0-9_-])/', '\\\\$1', $class );

		if ( $escaped !== '' && ctype_digit( $escaped[0] ) ) {
			$escaped = '\\' . dechex( ord( $escaped[0] ) ) . ' ' . substr( $escaped, 1 );
		}

		return $escaped;
	}
}

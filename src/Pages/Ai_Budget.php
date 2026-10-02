<?php
/**
 * What an AI step may cost: counted before it is asked, and stopped at a ceiling.
 *
 * @package DXAI_UI\Pages
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

/**
 * The AI steps of the pages (the planner, the critic; the words have their own button) are off until a person asks, and never
 * ask without saying what they will cost. Nothing here knows what a provider charges: the price is what the person
 * has typed in (US dollars for a million tokens, in and out, as the provider lists it); without it the cost is told in tokens
 * alone. A token is counted as four characters, which is near enough to plan by and always on the safe side for English.
 */
final class Ai_Budget {

	/** Option: the price of a million tokens, array{in:float, out:float}, as the person typed it. */
	public const PRICES = 'dxai_ui_ai_prices';

	/** Option: the most tokens one run may spend (a run is one press of a button). */
	public const CAP = 'dxai_ui_ai_cap';

	/** The ceiling when none is set: about forty pages planned. */
	public const DEFAULT_CAP = 150000;

	/** What the answer of a plan is expected to take: a list of sections and a sentence. */
	public const PLAN_OUT = 700;

	/** What the answer of a critique is expected to take: a few notes. */
	public const NOTES_OUT = 600;

	private int $cap;
	private int $in       = 0;
	private int $out      = 0;
	private int $requests = 0;

	public function __construct( ?int $cap = null ) {
		$this->cap = $cap ?? self::cap();
	}

	/** Tokens in a text: four characters to a token. */
	public static function tokens( string $text ): int {
		return (int) ceil( mb_strlen( $text ) / 4 );
	}

	/** The ceiling of a run. */
	public static function cap(): int {
		$cap = (int) get_option( self::CAP, self::DEFAULT_CAP );

		return $cap > 0 ? $cap : self::DEFAULT_CAP;
	}

	/**
	 * The price the person typed in, or null when they have not.
	 *
	 * @return array{in:float, out:float}|null
	 */
	public static function prices(): ?array {
		$p = get_option( self::PRICES, null );
		if ( ! is_array( $p ) || ! isset( $p['in'], $p['out'] ) || (float) $p['in'] < 0 || (float) $p['out'] < 0 || ( (float) $p['in'] === 0.0 && (float) $p['out'] === 0.0 ) ) {
			return null;
		}

		return array(
			'in'  => (float) $p['in'],
			'out' => (float) $p['out'],
		);
	}

	/** Keep the price a person typed: dollars for a million tokens, in and out. An empty or zero price is forgotten. */
	public static function save_prices( float $in, float $out ): void {
		if ( $in <= 0 && $out <= 0 ) {
			delete_option( self::PRICES );

			return;
		}
		update_option(
			self::PRICES,
			array(
				'in'  => max( 0.0, $in ),
				'out' => max( 0.0, $out ),
			),
			false
		);
	}

	/**
	 * What a set of requests would cost.
	 *
	 * @param array<int, array{in:int, out:int}> $calls Tokens in and tokens out expected, one entry for each request.
	 * @return array{requests:int, input:int, output:int, tokens:int, cost:float|null, cap:int, within:bool}
	 */
	public static function estimate( array $calls ): array {
		$in  = (int) array_sum( array_column( $calls, 'in' ) );
		$out = (int) array_sum( array_column( $calls, 'out' ) );
		$p   = self::prices();
		$cap = self::cap();

		return array(
			'requests' => count( $calls ),
			'input'    => $in,
			'output'   => $out,
			'tokens'   => $in + $out,
			'cost'     => $p === null ? null : round( $in / 1000000 * $p['in'] + $out / 1000000 * $p['out'], 4 ),
			'cap'      => $cap,
			'within'   => $in + $out <= $cap,
		);
	}

	/**
	 * Whether a request of this size is still within the ceiling of the run.
	 */
	public function allows( int $in, int $out ): bool {
		return $this->in + $this->out + $in + $out <= $this->cap;
	}

	/** Count a request that was made. */
	public function spend( int $in, int $out ): void {
		$this->in  += $in;
		$this->out += $out;
		++$this->requests;
	}

	/**
	 * What the run has spent, by the same count as estimate().
	 *
	 * @return array{requests:int, input:int, output:int, tokens:int, cost:float|null, cap:int, within:bool}
	 */
	public function spent(): array {
		$e = self::estimate( $this->requests > 0 ? array( array( 'in' => $this->in, 'out' => $this->out ) ) : array() );

		return array_merge( $e, array( 'requests' => $this->requests, 'cap' => $this->cap, 'within' => $this->in + $this->out <= $this->cap ) );
	}
}

<?php
/**
 * The `tailwindcss-animate` utilities.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler\Tailwind\Utilities;

use DXAI_UI\Compiler\Tailwind\Candidate;
use DXAI_UI\Compiler\Tailwind\Theme;
use DXAI_UI\Compiler\Tailwind\Utility_Module;

/**
 * `animate-in`, `animate-out` and the enter/exit modifiers that steer them.
 *
 * Not part of Tailwind. It is a plugin, and every Tailwind v3 shadcn/ui
 * project depends on it: the dialog, dropdown, popover, select, tooltip and
 * sheet primitives all open with
 *
 *     data-[state=open]:animate-in data-[state=open]:fade-in-0
 *     data-[state=closed]:animate-out data-[state=closed]:fade-out-0
 *     data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95
 *
 * and the classic Lovable template lists the plugin in its config. The v4
 * successor is the `tw-animate-css` stylesheet, which ships the same class
 * names as real CSS and therefore needs nothing here.
 *
 * The scheme is one keyframes pair driven by custom properties: `animate-in`
 * names `enter` and resets the five `--tw-enter-*` slots, and each modifier
 * fills one slot. So a class list composes the way the plugin's does, and a
 * modifier without `animate-in` sets a property nothing reads, exactly as
 * upstream.
 */
final class Animate implements Utility_Module {

	/** What `animate-in`/`animate-out` run for when nothing says otherwise. */
	private const DEFAULT_DURATION = '150ms';

	/** `--tw-enter-scale` / `--tw-exit-scale` steps, as percentages. */
	private const SCALE = array(
		'0' => '0', '50' => '.5', '75' => '.75', '90' => '.9', '95' => '.95',
		'100' => '1', '105' => '1.05', '110' => '1.1', '125' => '1.25', '150' => '1.5',
	);

	/** `--tw-enter-rotate` / `--tw-exit-rotate` steps, in degrees. */
	private const ROTATE = array(
		'0' => '0deg', '1' => '1deg', '2' => '2deg', '3' => '3deg', '6' => '6deg',
		'12' => '12deg', '45' => '45deg', '90' => '90deg', '180' => '180deg',
	);

	/** Which axis a slide names, and whether it runs against the axis. */
	private const SIDES = array(
		'top'    => array( 'translate-y', true ),
		'bottom' => array( 'translate-y', false ),
		'left'   => array( 'translate-x', true ),
		'right'  => array( 'translate-x', false ),
	);

	/** Set once a class in this module resolved, so the pair is emitted. */
	private bool $used = false;

	/**
	 * @return array<string, string>|null
	 */
	public function resolve( Candidate $candidate, Theme $theme ): ?array {
		$base = $candidate->base;

		if ( $base === 'animate-in' || $base === 'animate-out' ) {
			$this->used = true;
			$slot       = $base === 'animate-in' ? 'enter' : 'exit';

			return array(
				'animation-name'         => $slot,
				'animation-duration'     => self::DEFAULT_DURATION,
				'--tw-' . $slot . '-opacity'     => 'initial',
				'--tw-' . $slot . '-scale'       => 'initial',
				'--tw-' . $slot . '-rotate'      => 'initial',
				'--tw-' . $slot . '-translate-x' => 'initial',
				'--tw-' . $slot . '-translate-y' => 'initial',
			);
		}

		$found = $this->fade( $base ) ?? $this->scale( $base ) ?? $this->rotate( $base ) ?? $this->slide( $base, $theme );
		if ( $found !== null ) {
			$this->used = true;
		}

		return $found;
	}

	/**
	 * `fade-in-0`, `fade-out-80`, and the bare forms, which mean zero.
	 *
	 * @return array<string, string>|null
	 */
	private function fade( string $base ): ?array {
		if ( preg_match( '/^fade-(in|out)(?:-(\d{1,3}))?$/', $base, $match ) !== 1 ) {
			return null;
		}

		$slot   = $match[1] === 'in' ? 'enter' : 'exit';
		$number = isset( $match[2] ) ? (int) $match[2] : 0;
		if ( $number > 100 ) {
			return null;
		}

		// The opacity scale, so `fade-in-0` is 0 and `fade-in-100` is 1.
		$value = $number === 0 ? '0' : rtrim( rtrim( number_format( $number / 100, 2, '.', '' ), '0' ), '.' );

		return array( '--tw-' . $slot . '-opacity' => $value );
	}

	/**
	 * @return array<string, string>|null
	 */
	private function scale( string $base ): ?array {
		if ( preg_match( '/^zoom-(in|out)(?:-(\d{1,3}))?$/', $base, $match ) !== 1 ) {
			return null;
		}

		$slot  = $match[1] === 'in' ? 'enter' : 'exit';
		$token = $match[2] ?? '0';
		if ( ! isset( self::SCALE[ $token ] ) ) {
			return null;
		}

		return array( '--tw-' . $slot . '-scale' => self::SCALE[ $token ] );
	}

	/**
	 * @return array<string, string>|null
	 */
	private function rotate( string $base ): ?array {
		if ( preg_match( '/^spin-(in|out)(?:-(\d{1,3}))?$/', $base, $match ) !== 1 ) {
			return null;
		}

		$slot = $match[1] === 'in' ? 'enter' : 'exit';
		// The bare form is 30deg upstream, which is not a step on the scale.
		if ( ! isset( $match[2] ) ) {
			return array( '--tw-' . $slot . '-rotate' => '30deg' );
		}
		if ( ! isset( self::ROTATE[ $match[2] ] ) ) {
			return null;
		}

		return array( '--tw-' . $slot . '-rotate' => self::ROTATE[ $match[2] ] );
	}

	/**
	 * `slide-in-from-top-2`, `slide-out-to-right-full`.
	 *
	 * @return array<string, string>|null
	 */
	private function slide( string $base, Theme $theme ): ?array {
		if ( preg_match( '/^slide-(?:in-from|out-to)-(top|bottom|left|right)-(full|[\d.]+)$/', $base, $match ) !== 1 ) {
			return null;
		}

		$slot = str_starts_with( $base, 'slide-in-from-' ) ? 'enter' : 'exit';
		[ $axis, $against ] = self::SIDES[ $match[1] ];

		$value = $match[2] === 'full' ? '100%' : $theme->spacing( $match[2] );
		if ( ! is_string( $value ) || $value === '' ) {
			return null;
		}

		/*
		 * A slide names where the element comes FROM, so entering from the top
		 * starts above its resting place — a negative Y. `slide-out-to-top` is
		 * the same sign for the opposite reason: it ends above.
		 */
		if ( $against ) {
			$value = str_starts_with( $value, '-' ) ? substr( $value, 1 ) : '-' . $value;
		}

		return array( '--tw-' . $slot . '-' . $axis => $value );
	}

	/**
	 * @return array<string, string>
	 */
	public function keyframes(): array {
		if ( ! $this->used ) {
			return array();
		}

		/*
		 * Both frames read every slot through a fallback, so a class list that
		 * sets only opacity still produces a valid transform. That is what lets
		 * the modifiers compose without the plugin generating a keyframes block
		 * per combination.
		 */
		$transform = static function ( string $slot ): string {
			return 'translate3d(var(--tw-' . $slot . '-translate-x, 0), var(--tw-' . $slot . '-translate-y, 0), 0)'
				. ' scale3d(var(--tw-' . $slot . '-scale, 1), var(--tw-' . $slot . '-scale, 1), var(--tw-' . $slot . '-scale, 1))'
				. ' rotate(var(--tw-' . $slot . '-rotate, 0))';
		};

		return array(
			'enter' => "@keyframes enter {\n  from { opacity: var(--tw-enter-opacity, 1); transform: "
				. $transform( 'enter' ) . "; }\n}",
			'exit'  => "@keyframes exit {\n  to { opacity: var(--tw-exit-opacity, 1); transform: "
				. $transform( 'exit' ) . "; }\n}",
		);
	}
}

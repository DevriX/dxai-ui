<?php
/**
 * What a converted page does when it is touched, read from its markup: the states the compiler made and the rest the runtime does.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Design;

use DXAI_UI\Blocks\Assets;

/**
 * The compiler writes a design's behaviour into the markup as marks the page runtime (Compiler\Motion_Runtime) reads: a state's
 * triggers (`dxai-toggle-<state>[--<value>]`, with `data-dxai-set`, `-step`, `-clamp`, `-init`, `-mode`, `-hover`), its projections
 * (`dxai-cls-…` with `data-dxai-on`/`-off` class lists, `dxai-on-…`/`dxai-off-…` panels, `data-dxai-text-<state>` word maps,
 * `data-dxai-disabled`, the aria the trigger carries), its other drivers (`data-dxai-scroll`, `data-dxai-outside`, `data-dxai-any`), and
 * beside the states the things the runtime does on its own: sections that reveal on approach, numbers that count up, the Radix
 * components by their ARIA, portals, two hand-coded families (rv-hero, rv-mbp), rails, the video facade, icons.
 *
 * This reads them back into the design's document (phase 3а of docs/PLAN-ARCHITECTURE.md): one row per state — its kind, its starting
 * value, its values, what drives it and what it projects — and one row per kind of the rest, each saying whether the Interactivity API
 * could express it with directives and a store (`full`), with a store callback the directives hand control to (`partial`), or not
 * without the runtime's own code (`none`), and why. Nothing here changes a page: it is the measure phase 3б builds on.
 */
final class Behaviour_Reader {

	public const LEVELS = array( 'full', 'partial', 'none' );

	/** The kinds this reads beside the states, each with what the Interactivity API could do about it. */
	public const RUNTIME = array(
		'reveal'          => array( 'partial', 'sections fade in as they come on screen: an IntersectionObserver a store callback sets up (data-wp-init), not a directive' ),
		'countup'         => array( 'partial', 'a number counts up on approach: an observer and an animation frame loop in a store callback' ),
		'radix-accordion' => array( 'partial', 'opening and closing are directives; the panel height the design\'s keyframes animate to (--radix-accordion-content-height) is measured at open time' ),
		'radix-tabs'      => array( 'full', '' ),
		'radix-select'    => array( 'none', 'the list is positioned against its trigger at open time and the chosen words are copied into it' ),
		'radix-dialog'    => array( 'none', 'the overlay and the panel move to a host under <body> at open time (a portal), out from under any transform' ),
		'radix-popover'   => array( 'none', 'positioned against its trigger and flipped to stay in the viewport, in a portal' ),
		'radix-menu'      => array( 'none', 'positioned against its trigger, in a portal' ),
		'radix-tooltip'   => array( 'none', 'opens after a delay, positioned against its trigger, closes as the pointer leaves the pair' ),
		'portal'          => array( 'none', 'an element the design portals moves under <body> at open time' ),
		'hero-tabs'       => array( 'none', 'a hand-coded tab strip of one design family (rv-hero)' ),
		'method-rows'     => array( 'none', 'rows that light up with the scroll position (rv-mbp)' ),
		'rail'            => array( 'none', 'previous and next buttons that scroll a row sideways (rail.js)' ),
		'video-facade'    => array( 'none', 'the facade\'s own script swaps the player in (youtube-facade.js)' ),
		'lucide'          => array( 'none', 'icons drawn by the icon runtime' ),
	);

	/** Why every state is `full`: each of its drivers and projections has a directive. */
	private const STATE_WHY = array(
		'click'    => 'data-wp-on--click',
		'hover'    => 'data-wp-on--mouseenter/mouseleave and focusin/focusout',
		'outside'  => 'data-wp-on-document--click',
		'scroll'   => 'data-wp-on-window--scroll',
		'derived'  => 'a derived getter in the store',
		'step'     => 'an action that moves along the values',
		'classes'  => 'data-wp-bind--class',
		'panels'   => 'data-wp-bind--hidden and data-wp-style--grid-template-rows',
		'text'     => 'data-wp-text',
		'disabled' => 'data-wp-bind--disabled',
		'aria'     => 'data-wp-bind--aria-expanded',
	);

	/**
	 * The behaviours in a page's markup.
	 *
	 * @return array{states: array<int, array<string, mixed>>, runtime: array<int, array<string, mixed>>}
	 */
	public static function from_markup( string $markup ): array {
		$states   = array();
		$triggers = array(); // state => values in trigger order
		$counts   = array_fill_keys( array_keys( self::RUNTIME ), 0 );
		$popups   = array(); // aria-controls ids of dialog triggers, to tell a popover (a panel with a side) from a dialog
		$sided    = array(); // ids of panels that name a side

		$state = static function ( string $name ) use ( &$states ): void {
			if ( ! isset( $states[ $name ] ) ) {
				$states[ $name ] = array(
					'name'        => $name,
					'values'      => array(),
					'drivers'     => array(),
					'members'     => array(),
					'threshold'   => null,
					'init'        => null,
					'live'        => null,
					'projections' => array(
						'classes'  => 0,
						'panels'   => 0,
						'text'     => 0,
						'disabled' => 0,
						'aria'     => 0,
					),
				);
			}
		};
		$value = static function ( string $name, ?string $v ) use ( &$states ): void {
			if ( $v !== null && $v !== '' && ! in_array( $v, $states[ $name ]['values'], true ) ) {
				$states[ $name ]['values'][] = $v;
			}
		};
		$drive = static function ( string $name, string $driver ) use ( &$states ): void {
			if ( ! in_array( $driver, $states[ $name ]['drivers'], true ) ) {
				$states[ $name ]['drivers'][] = $driver;
			}
		};

		foreach ( self::tags( $markup ) as $tag ) {
			$name    = $tag['tag'];
			$attrs   = $tag['attrs'];
			$classes = self::tokens( (string) ( $attrs['class'] ?? '' ) );

			// --- The states.
			foreach ( self::marks( $classes, 'toggle' ) as $mark ) {
				$state( $mark['state'] );
				$value( $mark['state'], $mark['value'] );
				if ( $mark['value'] !== null ) {
					$triggers[ $mark['state'] ][] = $mark['value'];
				}
				$hover = (string) ( $attrs['data-dxai-hover'] ?? '' );
				if ( $hover !== '' ) {
					$drive( $mark['state'], 'hover' );
				}
				if ( isset( $attrs['data-dxai-step'] ) ) {
					$drive( $mark['state'], 'step' );
				} elseif ( $hover !== 'leave' ) {
					$drive( $mark['state'], 'click' );
				}
				if ( isset( $attrs['data-dxai-init'] ) ) {
					$states[ $mark['state'] ]['init'] = (string) $attrs['data-dxai-init'];
				}
				if ( ! isset( $attrs['data-dxai-step'] ) && ! isset( $attrs['data-dxai-set'] ) ) {
					++$states[ $mark['state'] ]['projections']['aria'];
				}
			}
			foreach ( self::marks( $classes, 'cls' ) as $mark ) {
				$state( $mark['state'] );
				$value( $mark['state'], $mark['value'] );
				++$states[ $mark['state'] ]['projections']['classes'];
				$on = self::tokens( self::attr_for( $attrs, 'on', $mark ) );
				if ( $on !== array() && $states[ $mark['state'] ]['live'] === null && array() === array_diff( $on, $classes ) ) {
					$states[ $mark['state'] ]['live'] = $mark['value'] ?? true;
				}
			}
			$shown = ! in_array( 'hidden', $classes, true );
			foreach ( self::marks( $classes, 'on' ) as $mark ) {
				$state( $mark['state'] );
				$value( $mark['state'], $mark['value'] );
				++$states[ $mark['state'] ]['projections']['panels'];
				if ( $shown && $states[ $mark['state'] ]['live'] === null ) {
					$states[ $mark['state'] ]['live'] = $mark['value'] ?? true;
				}
			}
			foreach ( self::marks( $classes, 'off' ) as $mark ) {
				$state( $mark['state'] );
				$value( $mark['state'], $mark['value'] );
				++$states[ $mark['state'] ]['projections']['panels'];
				if ( $shown && $states[ $mark['state'] ]['live'] === null ) {
					$states[ $mark['state'] ]['live'] = $mark['value'] === null ? false : 'null';
				}
			}
			foreach ( $attrs as $key => $v ) {
				if ( str_starts_with( $key, 'data-dxai-text-' ) ) {
					$s = substr( $key, strlen( 'data-dxai-text-' ) );
					$state( $s );
					++$states[ $s ]['projections']['text'];
				}
			}
			if ( isset( $attrs['data-dxai-disabled'] ) && preg_match( '/^([A-Za-z_$][\w$]*):(.*)$/', (string) $attrs['data-dxai-disabled'], $m ) === 1 ) {
				$state( $m[1] );
				++$states[ $m[1] ]['projections']['disabled'];
				foreach ( explode( ',', $m[2] ) as $v ) {
					$value( $m[1], trim( $v ) );
				}
			}
			foreach ( self::tokens( (string) ( $attrs['data-dxai-any'] ?? '' ) ) as $spec ) {
				$cut = strpos( $spec, ':' );
				if ( $cut === false || $cut < 1 ) {
					continue;
				}
				$d       = substr( $spec, 0, $cut );
				$members = array_values( array_filter( array_map( 'trim', explode( ',', substr( $spec, $cut + 1 ) ) ) ) );
				$state( $d );
				$drive( $d, 'derived' );
				$states[ $d ]['members'] = array_values( array_unique( array_merge( $states[ $d ]['members'], $members ) ) );
				foreach ( $members as $member ) {
					$state( $member );
				}
			}
			foreach ( self::tokens( (string) ( $attrs['data-dxai-scroll'] ?? '' ) ) as $spec ) {
				$cut = strpos( $spec, ':' );
				$s   = $cut !== false && $cut > 0 ? substr( $spec, 0, $cut ) : $spec;
				$state( $s );
				$drive( $s, 'scroll' );
				// Pixels, whole (a float would not survive the document's JSON: 24.0 comes back as 24).
				$states[ $s ]['threshold'] = $cut !== false && $cut > 0 ? (int) round( (float) substr( $spec, $cut + 1 ) ) : 0;
			}
			foreach ( self::tokens( (string) ( $attrs['data-dxai-outside'] ?? '' ) ) as $s ) {
				$state( $s );
				$drive( $s, 'outside' );
			}

			// --- The rest the runtime does.
			if ( in_array( $name, array( 'section', 'header', 'footer' ), true ) || isset( $attrs['data-reveal'] ) || array_intersect( array( 'rv-close', 'rv-leadmagnet', 'dxai-reveal' ), $classes ) !== array() ) {
				++$counts['reveal'];
			}
			if ( isset( $attrs['data-countup'] ) || ( in_array( 'rv-num', $classes, true ) && isset( $attrs['data-to'] ) ) ) {
				++$counts['countup'];
			}
			if ( in_array( 'rv-hero', $classes, true ) ) {
				++$counts['hero-tabs'];
			}
			if ( isset( $attrs['data-dxai-method'] ) || array_intersect( array( 'rv-mbp', 'dxai-method' ), $classes ) !== array() ) {
				++$counts['method-rows'];
			}
			if ( isset( $attrs['data-dxai-portal'] ) ) {
				++$counts['portal'];
			}
			if ( isset( $attrs['data-lucide'] ) ) {
				++$counts['lucide'];
			}
			$role  = (string) ( $attrs['role'] ?? '' );
			$popup = (string) ( $attrs['aria-haspopup'] ?? '' );
			$id    = (string) ( $attrs['id'] ?? '' );
			if ( $role === 'tab' ) {
				++$counts['radix-tabs'];
			} elseif ( $role === 'combobox' ) {
				++$counts['radix-select'];
			} elseif ( $role === 'tooltip' ) {
				++$counts['radix-tooltip'];
			} elseif ( $popup === 'menu' ) {
				++$counts['radix-menu'];
			} elseif ( $popup === 'dialog' ) {
				$popups[] = (string) ( $attrs['aria-controls'] ?? '' );
			} elseif ( isset( $attrs['aria-expanded'] ) && str_contains( $id, '-trigger-' ) && $popup === '' ) {
				++$counts['radix-accordion'];
			}
			if ( $id !== '' && isset( $attrs['data-side'] ) ) {
				$sided[ $id ] = true;
			}
		}
		foreach ( $popups as $target ) {
			++$counts[ isset( $sided[ $target ] ) ? 'radix-popover' : 'radix-dialog' ];
		}
		if ( Assets::has_rail( $markup ) ) {
			$counts['rail'] = 1;
		}
		// The facade as Compiler\Video_Facade writes it (an HTML block the theme's or the plugin's script swaps the player into).
		$counts['video-facade'] = preg_match_all( '/class="youtube-facade"/', $markup );

		$rows = array();
		foreach ( $states as $name => $s ) {
			$rows[] = self::state_row( $s, $triggers[ $name ] ?? array() );
		}
		$runtime = array();
		foreach ( $counts as $kind => $count ) {
			if ( $count < 1 ) {
				continue;
			}
			$runtime[] = array(
				'kind'          => $kind,
				'count'         => (int) $count,
				'compiled'      => true,
				'note'          => self::RUNTIME[ $kind ][1] !== '' ? self::RUNTIME[ $kind ][1] : 'directives alone express it',
				'interactivity' => self::RUNTIME[ $kind ][0],
				'why'           => self::RUNTIME[ $kind ][1],
			);
		}

		return array(
			'states'  => $rows,
			'runtime' => $runtime,
		);
	}

	/**
	 * A state as a row of the document: its kind, its starting value, its values in the order the runtime moves through them, what
	 * drives it and what it projects.
	 *
	 * @param array<string, mixed> $s
	 * @param array<int, string>   $trigger_order
	 * @return array<string, mixed>
	 */
	private static function state_row( array $s, array $trigger_order ): array {
		$values = array_map( 'strval', $s['values'] );
		if ( count( $values ) > 1 && array() === array_filter( $values, static fn( string $v ): bool => preg_match( '/^-?\d+(\.\d+)?$/', $v ) !== 1 ) ) {
			usort( $values, static fn( string $a, string $b ): int => (float) $a <=> (float) $b );
		} elseif ( count( $values ) > 1 ) {
			$ordered = array();
			foreach ( array_merge( $trigger_order, $values ) as $v ) {
				if ( ! in_array( $v, $ordered, true ) ) {
					$ordered[] = $v;
				}
			}
			$values = $ordered;
		}
		$type = $values === array() ? 'boolean' : ( array() === array_filter( $values, static fn( string $v ): bool => preg_match( '/^-?\d+$/', $v ) !== 1 ) ? 'index' : 'value' );
		if ( $s['init'] !== null ) {
			$initial = (string) $s['init'];
		} elseif ( $s['live'] !== null ) {
			$initial = $s['live'];
		} else {
			$initial = $type === 'boolean' ? false : null;
		}
		$drivers = $s['drivers'];
		sort( $drivers );
		$what = array();
		foreach ( $s['projections'] as $kind => $n ) {
			if ( $n > 0 ) {
				$what[] = $n . ' ' . $kind;
			}
		}
		$why = array();
		foreach ( array_merge( $drivers, array_keys( array_filter( $s['projections'] ) ) ) as $part ) {
			if ( isset( self::STATE_WHY[ $part ] ) ) {
				$why[] = $part . ': ' . self::STATE_WHY[ $part ];
			}
		}

		return array(
			'kind'          => 'state',
			'count'         => 1,
			'compiled'      => true,
			'note'          => $s['name'] . ': ' . $type . ( $drivers !== array() ? ', driven by ' . implode( ', ', $drivers ) : ', driven by nothing on the page' ) . ( $what !== array() ? '; projects ' . implode( ', ', $what ) : '' ),
			'name'          => $s['name'],
			'type'          => $type,
			'initial'       => $initial,
			'values'        => $values,
			'drivers'       => $drivers,
			'members'       => array_values( $s['members'] ),
			'threshold'     => $s['threshold'],
			'projections'   => $s['projections'],
			'interactivity' => 'full',
			'why'           => implode( '; ', $why ),
		);
	}

	/**
	 * Every tag of the markup with its attributes (the block comments are not tags: they begin with `<!--`).
	 *
	 * @return array<int, array{tag:string, attrs:array<string, string>}>
	 */
	private static function tags( string $markup ): array {
		$out = array();
		if ( ! preg_match_all( '/<([a-zA-Z][\w-]*)\b([^>]*)>/', $markup, $m, PREG_SET_ORDER ) ) {
			return $out;
		}
		foreach ( $m as $tag ) {
			$attrs = array();
			// Names as the compiler wrote them: a state's name rides in some (`data-dxai-text-openFaq`) and is a JavaScript identifier, with its case.
			if ( preg_match_all( '/([a-zA-Z_:][-\w:.]*)\s*=\s*"([^"]*)"/', $tag[2], $a, PREG_SET_ORDER ) ) {
				foreach ( $a as $pair ) {
					$attrs[ $pair[1] ] = html_entity_decode( $pair[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				}
			}
			if ( preg_match_all( '/(?<=\s)(data-[a-z0-9-]+|hidden|disabled)(?=[\s\/>]|$)/i', ' ' . $tag[2], $bare ) ) {
				foreach ( $bare[1] as $key ) {
					$key = strtolower( $key );
					if ( ! isset( $attrs[ $key ] ) ) {
						$attrs[ $key ] = '';
					}
				}
			}
			$out[] = array(
				'tag'   => strtolower( $tag[1] ),
				'attrs' => $attrs,
			);
		}

		return $out;
	}

	/**
	 * The marks of one kind in a class list: `dxai-<kind>-<state>[--<value>]`. A state name is a JavaScript identifier and so never
	 * holds `-`; a value may (`open--how-we-help`), so the split is at the first `--`.
	 *
	 * @param array<int, string> $classes
	 * @return array<int, array{state:string, value:string|null}>
	 */
	private static function marks( array $classes, string $kind ): array {
		$prefix = 'dxai-' . $kind . '-';
		$out    = array();
		foreach ( $classes as $c ) {
			if ( ! str_starts_with( $c, $prefix ) ) {
				continue;
			}
			$flag = substr( $c, strlen( $prefix ) );
			$cut  = strpos( $flag, '--' );
			if ( $cut !== false && $cut > 0 ) {
				$out[] = array(
					'state' => substr( $flag, 0, $cut ),
					'value' => substr( $flag, $cut + 2 ),
				);
			} elseif ( $flag !== '' && preg_match( '/^[A-Za-z_$][\w$]*$/', $flag ) === 1 ) {
				$out[] = array(
					'state' => $flag,
					'value' => null,
				);
			}
		}

		return $out;
	}

	/**
	 * The class list a projection adds, looked up as the runtime does: per state and value, then per state, then the shared one.
	 *
	 * @param array<string, string>            $attrs
	 * @param array{state:string, value:string|null} $mark
	 */
	private static function attr_for( array $attrs, string $kind, array $mark ): string {
		if ( $mark['value'] !== null && isset( $attrs[ 'data-dxai-' . $kind . '-' . $mark['state'] . '-' . $mark['value'] ] ) ) {
			return $attrs[ 'data-dxai-' . $kind . '-' . $mark['state'] . '-' . $mark['value'] ];
		}
		if ( isset( $attrs[ 'data-dxai-' . $kind . '-' . $mark['state'] ] ) ) {
			return $attrs[ 'data-dxai-' . $kind . '-' . $mark['state'] ];
		}

		return (string) ( $attrs[ 'data-dxai-' . $kind ] ?? '' );
	}

	/** @return array<int, string> */
	private static function tokens( string $value ): array {
		return array_values( array_filter( preg_split( '/\s+/', trim( $value ) ) ?: array(), static fn( string $t ): bool => $t !== '' ) );
	}
}

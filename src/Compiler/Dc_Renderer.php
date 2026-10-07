<?php
/**
 * Render a Claude Design template to static, responsive, interactive HTML.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

/**
 * The runtime renders the template once per state and re-renders on every
 * change: a `matchMedia()` result, a click, a scroll. A page has no React to
 * re-render with, so every state the component can reach is rendered HERE,
 * and the differences between them are written into the markup as things
 * CSS and the front-end runtime can switch:
 *
 *   - viewport state (`isMobile`) → both branches in the markup, one hidden
 *     by a media query at the design's own breakpoint; a style declaration
 *     that differs between the two lives in a class with a media override
 *     instead of inline, because inline would win over the override;
 *   - handler state (`menuOpen`, `dropdown`, `openFaq`, `formStep`…) →
 *     Motion_Runtime's state machine: triggers carry `dxai-toggle-<state>`,
 *     gated blocks `dxai-on-<state>[--value]`, and an element whose style or
 *     text depends on the state carries a class projection
 *     (`dxai-cls-<state>` + the classes to add per value) or a text map;
 *   - scroll state and click-outside dismissal → declared on the root for
 *     the runtime, since no template attribute says so.
 *
 * Everything is derived by evaluating `renderVals()` under each state and
 * comparing the results, never by pattern-matching the template: the same
 * mechanism handles a style string, a spliced value, a list item's
 * per-index fields and a text label alike.
 */
final class Dc_Renderer {

	/** Tags whose text content must stay plain text — no interpolation span. */
	private const TEXT_ONLY = array( 'textarea', 'option', 'title', 'script', 'style' );

	private const VOID = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' );

	/**
	 * Attributes React DOM treats as boolean: a bare `required` reaches the
	 * runtime as the empty string, which React reads as false and removes,
	 * so the rendered design does not carry them. Dropped here to match.
	 */
	private const BOOLEAN_ATTRS = array( 'required', 'multiple', 'disabled', 'checked', 'selected', 'readonly', 'autofocus', 'hidden', 'open', 'muted', 'loop', 'controls', 'autoplay', 'playsinline', 'novalidate', 'reversed', 'default', 'allowfullscreen' );

	private Dc_Script $script;

	/** @var array<int, array{state: array<string, mixed>, mobile: bool, key: ?string, value: mixed, kind: string}> */
	private array $variants = array();

	/** @var array<int, array<string, mixed>> vals per variant */
	private array $vals = array();

	/** @var array<string, array{query: string, max: int}> media key → breakpoint */
	private array $media = array();

	/** @var array<string, string> state key → 'bool' | 'enum' */
	private array $key_kind = array();

	/** @var array<string, mixed> */
	private array $base_state = array();

	/** @var array<string, int> */
	private array $scroll = array();

	/** @var array<int, string> */
	private array $outside = array();

	private int $seq = 0;
	private string $css = '';
	private int $breakpoint = 0;

	/** @var array<int, string> */
	private array $notes = array();

	/** @var array<string, bool> state keys that got a data-dxai-init already */
	private array $init_done = array();

	/** @var array<int, array{selector: string, attr: string, done: bool}> Attributes componentDidMount sets on the first match. */
	private array $mount_attrs = array();

	public function __construct( Dc_Script $script ) {
		$this->script = $script;
	}

	/** @return array<int, string> */
	public function notes(): array {
		return array_values( array_unique( array_merge( $this->notes, $this->script->unevaluated() ) ) );
	}

	/** The pixel breakpoint the design switches layout at, 0 when it has none. */
	public function breakpoint(): int {
		return $this->breakpoint;
	}

	/**
	 * @param string $template The markup between `<x-dc>` and `</x-dc>`, helmet removed.
	 * @return array{html: string, css: string, root_attrs: array<string, string>}
	 */
	public function render( string $template ): array {
		$this->plan_variants();

		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $this->prepare( $template ) . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body instanceof \DOMElement ) {
			return array( 'html' => '', 'css' => '', 'root_attrs' => array() );
		}

		$html = $this->render_children( $body, array(), 0, array() );

		$root_attrs = array();
		if ( $this->outside !== array() ) {
			$root_attrs['data-dxai-outside'] = implode( ' ', $this->outside );
		}
		foreach ( $this->scroll as $key => $threshold ) {
			$root_attrs['data-dxai-scroll'] = trim( ( $root_attrs['data-dxai-scroll'] ?? '' ) . ' ' . $key . ':' . $threshold );
		}
		if ( $this->breakpoint > 0 ) {
			$this->css .= '@media (max-width: ' . $this->breakpoint . "px) { .dxai-dc-desktop { display: none !important; } }\n";
			$this->css .= '@media (min-width: ' . ( $this->breakpoint + 1 ) . "px) { .dxai-dc-mobile { display: none !important; } }\n";
		}

		return array(
			'html'       => $html,
			'css'        => $this->css,
			'root_attrs' => $root_attrs,
		);
	}

	/* --------------------------------------------------------- planning */

	/**
	 * Every state worth rendering: the initial one, its mobile twin, and for
	 * each key a handler can change, each value it can be given — in both
	 * viewport states, since a mobile-only branch has handlers of its own.
	 */
	private function plan_variants(): void {
		$state   = $this->script->initial_state();
		$effects = $this->script->mount_effects();
		$this->base_state = $state;
		$this->scroll     = $effects['scroll'];
		$this->outside    = $effects['outside'];
		foreach ( $effects['open'] ?? array() as $open ) {
			$this->mount_attrs[] = array( 'selector' => (string) $open['selector'], 'attr' => (string) $open['attr'], 'done' => false );
		}
		foreach ( $effects['media'] as $key => $query ) {
			if ( preg_match( '/max-width:\s*(\d+)/', $query, $m ) === 1 ) {
				$this->media[ $key ] = array( 'query' => $query, 'max' => (int) $m[1] );
				$at                  = (int) $m[1];
			} elseif ( preg_match( '/min-width:\s*(\d+)/', $query, $m ) === 1 ) {
				// `matches` is true on the WIDE side: the key is "isDesktop"-like.
				$this->media[ $key ] = array( 'query' => $query, 'max' => (int) $m[1] - 1, 'inverted' => true );
				$at                  = (int) $m[1] - 1;
			} else {
				continue;
			}
			// The layout switches at the first query's breakpoint (the mobile one). A second query — Five Star's "wide" one, at
			// 1120px — is a state of its own, worked out at the width a variant stands for (media_matches()), not a second switch.
			if ( 0 === $this->breakpoint ) {
				$this->breakpoint = $at;
			}
		}

		$base_vals = $this->script->render_vals( $state );
		$domains   = $this->domains( $base_vals );
		foreach ( $this->scroll as $key => $threshold ) {
			$domains[ $key ] = array( true, false );
		}

		$mobile_states = array( false );
		if ( $this->media !== array() ) {
			$mobile_states[] = true;
		}
		foreach ( $mobile_states as $mobile ) {
			$s = $state;
			foreach ( $this->media as $key => $bp ) {
				// Each key at the width its variant stands for: the mobile twin at the breakpoint, the primary one wider than any.
				$s[ $key ] = self::media_matches( (string) $bp['query'], $mobile ? $this->breakpoint : self::DESKTOP_WIDTH );
			}
			$this->variants[] = array( 'state' => $s, 'mobile' => $mobile, 'key' => null, 'value' => null, 'kind' => $mobile ? 'media' : 'base' );
			foreach ( $domains as $key => $values ) {
				foreach ( $values as $value ) {
					if ( $this->same( $value, $state[ $key ] ?? null ) ) {
						continue;
					}
					$v = $s;
					$v[ $key ] = $value;
					$this->variants[] = array( 'state' => $v, 'mobile' => $mobile, 'key' => $key, 'value' => $value, 'kind' => 'state' );
				}
			}
		}
		foreach ( $this->variants as $i => $variant ) {
			$this->vals[ $i ] = $i === 0 ? $base_vals : $this->script->render_vals( $variant['state'] );
		}
	}

	/** A width wider than any breakpoint: the viewport the primary variant stands for. */
	private const DESKTOP_WIDTH = 100000;

	/** Whether a media query's width conditions hold for a viewport this wide. */
	private static function media_matches( string $query, int $width ): bool {
		$ok = true;
		if ( preg_match( '/max-width:\s*(\d+)/', $query, $m ) === 1 ) {
			$ok = $ok && $width <= (int) $m[1];
		}
		if ( preg_match( '/min-width:\s*(\d+)/', $query, $m ) === 1 ) {
			$ok = $ok && $width >= (int) $m[1];
		}

		return $ok;
	}

	/**
	 * For every state key a handler patches, the values it can take.
	 *
	 * @param array<string, mixed> $vals
	 * @return array<string, array<int, mixed>>
	 */
	private function domains( array $vals ): array {
		$state   = $this->script->initial_state();
		$domains = array();
		$fns     = array();
		foreach ( $vals as $value ) {
			if ( $value instanceof Dc_Js_Fn ) {
				$fns[] = $value;
			} elseif ( is_array( $value ) && array_is_list( $value ) ) {
				foreach ( $value as $item ) {
					if ( is_array( $item ) ) {
						foreach ( $item as $field ) {
							if ( $field instanceof Dc_Js_Fn ) {
								$fns[] = $field;
							}
						}
					}
				}
			}
		}
		foreach ( $fns as $fn ) {
			foreach ( $this->script->patches( $fn ) as $key => $descriptors ) {
				if ( ! array_key_exists( $key, $state ) || is_array( $state[ $key ] ) ) {
					continue;
				}
				$set = $domains[ $key ] ?? array( $state[ $key ] );
				foreach ( $descriptors as $d ) {
					if ( isset( $d['flip'] ) ) {
						$set[] = true;
						$set[] = false;
					} elseif ( array_key_exists( 'set', $d ) ) {
						$set[] = $this->normalise( $key, $d['set'] );
					} elseif ( isset( $d['step'] ) ) {
						$from  = (int) Dc_Js::to_number( $state[ $key ] );
						$bound = isset( $d['bound'] ) && is_numeric( $d['bound'] ) ? (int) $d['bound'] : $from + $d['step'] * 3;
						for ( $n = min( $from, $bound ); $n <= max( $from, $bound ); $n++ ) {
							$set[] = $n;
						}
					}
				}
				$domains[ $key ] = $set;
			}
		}
		foreach ( $domains as $key => $set ) {
			$unique = array();
			foreach ( $set as $v ) {
				$dup = false;
				foreach ( $unique as $u ) {
					if ( $this->same( $u, $v ) ) {
						$dup = true;
						break;
					}
				}
				if ( ! $dup ) {
					$unique[] = $v;
				}
			}
			$domains[ $key ] = $unique;
			$bools = array_filter( $unique, static fn( $v ) => ! is_bool( $v ) && $v !== null );
			$this->key_kind[ $key ] = $bools === array() ? 'bool' : 'enum';
		}
		foreach ( $this->scroll as $key => $t ) {
			$this->key_kind[ $key ] = 'bool';
		}
		foreach ( $this->media as $key => $m ) {
			$this->key_kind[ $key ] = 'media';
		}

		return $domains;
	}

	/**
	 * `-1` on an index-like state means "none", which the runtime's machine
	 * spells `null`; the same for `false` on a state that otherwise holds values.
	 */
	private function normalise( string $key, mixed $value ): mixed {
		if ( is_int( $value ) && $value < 0 ) {
			return null;
		}

		return $value;
	}

	private function same( mixed $a, mixed $b ): bool {
		if ( ( is_int( $a ) || is_float( $a ) ) && ( is_int( $b ) || is_float( $b ) ) ) {
			return (float) $a === (float) $b;
		}

		return $a === $b;
	}

	/* -------------------------------------------------------- rendering */

	private function prepare( string $template ): string {
		$template = preg_replace( '/<helmet\b[^>]*>[\s\S]*?<\/helmet>/i', '', $template ) ?? $template;
		$template = preg_replace( '/<!--\s*BUILD-MARKER\s*-->/', '', $template ) ?? $template;
		// `<x-import …/>` self-closing custom elements would swallow siblings.
		$template = preg_replace( '/<(x-import|dc-import)(\s[^>]*?)\/>/i', '<$1$2></$1>', $template ) ?? $template;

		return $template;
	}

	/**
	 * @param array<int, array<string, mixed>> $loop  Loop variables per variant: [variant][name] => value.
	 * @param array<string, mixed>             $gate  {mobile: ?bool} — the media branch this subtree lives in.
	 */
	private function render_children( \DOMNode $parent, array $loop, int $primary, array $gate ): string {
		$out = '';
		foreach ( iterator_to_array( $parent->childNodes ) as $child ) {
			$out .= $this->render_node( $child, $loop, $primary, $gate );
		}

		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $loop
	 * @param array<string, mixed>             $gate
	 */
	private function render_node( \DOMNode $node, array $loop, int $primary, array $gate ): string {
		if ( $node instanceof \DOMText ) {
			return $this->render_text( $node, $loop, $primary, $gate );
		}
		if ( $node instanceof \DOMComment ) {
			return '';
		}
		if ( ! $node instanceof \DOMElement ) {
			return '';
		}
		$tag = strtolower( $node->tagName );
		switch ( $tag ) {
			case 'sc-if':
				return $this->render_if( $node, $loop, $primary, $gate );
			case 'sc-for':
				return $this->render_for( $node, $loop, $primary, $gate );
			case 'sc-helmet':
			case 'helmet':
				return '';
			case 'x-import':
			case 'dc-import':
				$this->notes[] = 'unsupported <' . $tag . '>';
				return '';
		}

		return $this->render_element( $node, $tag, $loop, $primary, $gate );
	}

	/**
	 * Evaluate a `{{ }}` binding under every variant.
	 *
	 * @param array<int, array<string, mixed>> $loop
	 * @return array<int, mixed> value per variant
	 */
	private function evaluate_all( string $expr, array $loop ): array {
		$out = array();
		foreach ( $this->vals as $i => $vals ) {
			$out[ $i ] = $this->resolve( $expr, $vals, $loop[ $i ] ?? array() );
		}

		return $out;
	}

	/**
	 * The template's expression language: paths, equality, negation, literals.
	 *
	 * @param array<string, mixed> $vals
	 * @param array<string, mixed> $loop
	 */
	private function resolve( string $expr, array $vals, array $loop ): mixed {
		$expr = trim( $expr );
		if ( $expr === '' ) {
			return null;
		}
		if ( $expr[0] === '(' && $expr[ strlen( $expr ) - 1 ] === ')' && self::parens_wrap( $expr ) ) {
			return $this->resolve( substr( $expr, 1, -1 ), $vals, $loop );
		}
		$eq = self::top_level_equality( $expr );
		if ( $eq !== null ) {
			$l = $this->resolve( substr( $expr, 0, $eq[0] ), $vals, $loop );
			$r = $this->resolve( substr( $expr, $eq[0] + strlen( $eq[1] ) ), $vals, $loop );
			return match ( $eq[1] ) {
				'===' => $l === $r,
				'!==' => $l !== $r,
				'=='  => $l == $r, // phpcs:ignore Universal.Operators.StrictComparisons
				default => $l != $r, // phpcs:ignore Universal.Operators.StrictComparisons
			};
		}
		if ( $expr[0] === '!' ) {
			return ! Dc_Js::truthy( $this->resolve( substr( $expr, 1 ), $vals, $loop ) );
		}
		if ( $expr === 'true' ) {
			return true;
		}
		if ( $expr === 'false' ) {
			return false;
		}
		if ( $expr === 'null' || $expr === 'undefined' ) {
			return null;
		}
		if ( preg_match( '/^-?\d+(\.\d+)?$/', $expr ) === 1 ) {
			return str_contains( $expr, '.' ) ? (float) $expr : (int) $expr;
		}
		if ( strlen( $expr ) >= 2 && ( $expr[0] === '"' || $expr[0] === "'" ) && $expr[ strlen( $expr ) - 1 ] === $expr[0] ) {
			return substr( $expr, 1, -1 );
		}

		return $this->resolve_path( $expr, $vals, $loop );
	}

	/**
	 * @param array<string, mixed> $vals
	 * @param array<string, mixed> $loop
	 */
	private function resolve_path( string $expr, array $vals, array $loop ): mixed {
		if ( preg_match( '/^[A-Za-z_$][A-Za-z0-9_$]*/', $expr, $m ) !== 1 ) {
			return null;
		}
		$head = $m[0];
		$rest = substr( $expr, strlen( $head ) );
		$cur  = array_key_exists( $head, $loop ) ? $loop[ $head ] : ( $vals[ $head ] ?? null );
		while ( $rest !== '' && $cur !== null ) {
			if ( $rest[0] === '.' ) {
				if ( preg_match( '/^\.([A-Za-z0-9_$]+)/', $rest, $mm ) !== 1 ) {
					break;
				}
				$cur  = $this->member( $cur, $mm[1] );
				$rest = substr( $rest, strlen( $mm[0] ) );
				continue;
			}
			if ( $rest[0] === '[' ) {
				$depth = 0;
				$end   = -1;
				for ( $i = 0, $n = strlen( $rest ); $i < $n; $i++ ) {
					if ( $rest[ $i ] === '[' ) {
						++$depth;
					} elseif ( $rest[ $i ] === ']' ) {
						--$depth;
						if ( $depth === 0 ) {
							$end = $i;
							break;
						}
					}
				}
				if ( $end < 0 ) {
					break;
				}
				$key  = $this->resolve( substr( $rest, 1, $end - 1 ), $vals, $loop );
				$cur  = $this->member( $cur, Dc_Js::to_string( $key ) );
				$rest = substr( $rest, $end + 1 );
				continue;
			}
			break;
		}

		return $rest === '' ? $cur : null;
	}

	private function member( mixed $obj, string $prop ): mixed {
		if ( is_array( $obj ) ) {
			if ( $prop === 'length' && array_is_list( $obj ) ) {
				return count( $obj );
			}
			if ( array_key_exists( $prop, $obj ) ) {
				return $obj[ $prop ];
			}
			if ( is_numeric( $prop ) && array_key_exists( (int) $prop, $obj ) ) {
				return $obj[ (int) $prop ];
			}
			return null;
		}
		if ( is_string( $obj ) && $prop === 'length' ) {
			return mb_strlen( $obj );
		}
		if ( $obj instanceof Dc_Js_Element && $prop === 'props' ) {
			return $obj->props;
		}

		return null;
	}

	private static function parens_wrap( string $expr ): bool {
		$depth = 0;
		$n     = strlen( $expr );
		for ( $i = 0; $i < $n; $i++ ) {
			if ( $expr[ $i ] === '(' ) {
				++$depth;
			} elseif ( $expr[ $i ] === ')' ) {
				--$depth;
				if ( $depth === 0 && $i < $n - 1 ) {
					return false;
				}
			}
		}

		return true;
	}

	/** @return array{0: int, 1: string}|null offset and operator */
	private static function top_level_equality( string $expr ): ?array {
		$depth = 0;
		$n     = strlen( $expr );
		for ( $i = 0; $i < $n; $i++ ) {
			$ch = $expr[ $i ];
			if ( $ch === '(' || $ch === '[' ) {
				++$depth;
			} elseif ( $ch === ')' || $ch === ']' ) {
				--$depth;
			} elseif ( $depth === 0 && ( $ch === '=' || $ch === '!' ) ) {
				foreach ( array( '===', '!==', '==', '!=' ) as $op ) {
					if ( substr( $expr, $i, strlen( $op ) ) === $op ) {
						return array( $i, $op );
					}
				}
			}
		}

		return null;
	}

	/**
	 * Which variants a condition is truthy in, and how that relates to the
	 * primary variant.
	 *
	 * @param array<int, mixed> $values
	 * @return array{same: bool, media: bool, keys: array<string, array<int, mixed>>, on_primary: bool}
	 *         `keys`: state key → the values under which the condition is truthy
	 *         (differing from the primary), `media`: differs with the viewport.
	 */
	private function classify( array $values, int $primary ): array {
		$base = Dc_Js::truthy( $values[ $primary ] );
		$out  = array( 'same' => true, 'media' => false, 'keys' => array(), 'on_primary' => $base );
		$pm   = $this->variants[ $primary ]['mobile'];
		foreach ( $values as $i => $v ) {
			$t = Dc_Js::truthy( $v );
			if ( $t === $base ) {
				continue;
			}
			$variant = $this->variants[ $i ];
			if ( $variant['key'] === null ) {
				// The other viewport's base.
				if ( $variant['mobile'] !== $pm ) {
					$out['media'] = true;
					$out['same']  = false;
				}
				continue;
			}
			if ( $variant['mobile'] !== $pm ) {
				continue; // A state variant of the other viewport; the media flag covers it.
			}
			$out['same'] = false;
			$out['keys'][ $variant['key'] ][] = $variant['value'];
		}

		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $loop
	 * @param array<string, mixed>             $gate
	 */
	private function render_if( \DOMElement $el, array $loop, int $primary, array $gate ): string {
		$expr   = self::mustache( (string) $el->getAttribute( 'value' ) );
		$values = $this->evaluate_all( $expr, $loop );
		$class  = $this->classify( $values, $primary );

		if ( $class['same'] ) {
			return $class['on_primary'] ? $this->render_children( $el, $loop, $primary, $gate ) : '';
		}

		$marks = array();
		$sub_primary = $primary;
		$sub_gate    = $gate;

		if ( $class['media'] && ! isset( $gate['mobile'] ) ) {
			// Truthy on one side of the breakpoint only.
			$mobile_side = ! $class['on_primary'];
			$marks[]     = $mobile_side ? 'dxai-dc-mobile' : 'dxai-dc-desktop';
			$sub_gate['mobile'] = $mobile_side;
			if ( $mobile_side ) {
				$sub_primary = $this->variant_index( true, null, null );
			}
		}

		$hidden = false;
		foreach ( $class['keys'] as $key => $on_values ) {
			$kind = $this->key_kind[ $key ] ?? 'enum';
			if ( $class['on_primary'] ) {
				// Shown now, hidden for these values → the block is "off" for them.
				if ( $kind === 'bool' ) {
					$marks[] = $this->base_state_bool( $key, $sub_primary ) ? 'dxai-on-' . $key : 'dxai-off-' . $key;
				} else {
					// Shown at the primary value, so gate on it.
					$pv      = $this->variants[ $sub_primary ]['state'][ $key ] ?? null;
					$marks[] = $pv === null || $pv === false ? 'dxai-off-' . $key : 'dxai-on-' . $key . '--' . self::slug( $pv );
				}
			} else {
				$hidden = true;
				if ( $kind === 'bool' ) {
					$marks[] = in_array( true, $on_values, true ) ? 'dxai-on-' . $key : 'dxai-off-' . $key;
				} else {
					foreach ( $on_values as $v ) {
						$marks[] = $v === null || $v === false ? 'dxai-off-' . $key : 'dxai-on-' . $key . '--' . self::slug( $v );
					}
				}
				// Render the branch as it looks when it IS shown.
				$first = $on_values[0] ?? null;
				$idx   = $this->variant_index( $this->variants[ $sub_primary ]['mobile'], $key, $first );
				if ( $idx !== null ) {
					$sub_primary = $idx;
				}
			}
		}
		if ( $hidden ) {
			$marks[] = 'hidden';
		}

		return $this->render_children_marked( $el, $loop, $sub_primary, $sub_gate, $marks );
	}

	private function base_state_bool( string $key, int $primary ): bool {
		return Dc_Js::truthy( $this->variants[ $primary ]['state'][ $key ] ?? null );
	}

	/** The variant with this viewport and state override, if planned. */
	private function variant_index( bool $mobile, ?string $key, mixed $value ): ?int {
		foreach ( $this->variants as $i => $v ) {
			if ( $v['mobile'] !== $mobile ) {
				continue;
			}
			if ( $key === null && $v['key'] === null ) {
				return $i;
			}
			if ( $key !== null && $v['key'] === $key && $this->same( $v['value'], $value ) ) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * Render children, adding classes to each element child.
	 *
	 * @param array<int, array<string, mixed>> $loop
	 * @param array<string, mixed>             $gate
	 * @param array<int, string>               $marks
	 */
	private function render_children_marked( \DOMElement $el, array $loop, int $primary, array $gate, array $marks ): string {
		$out = '';
		foreach ( iterator_to_array( $el->childNodes ) as $child ) {
			if ( $child instanceof \DOMElement ) {
				$tag = strtolower( $child->tagName );
				if ( $tag === 'sc-if' || $tag === 'sc-for' ) {
					// Marks fall through a nested directive onto ITS element children.
					$out .= $this->with_pending_marks( $marks, fn() => $this->render_node( $child, $loop, $primary, $gate ) );
					continue;
				}
				$out .= $this->render_element( $child, $tag, $loop, $primary, $gate, $marks );
				continue;
			}
			if ( $child instanceof \DOMText && trim( $child->nodeValue ?? '' ) !== '' ) {
				// Bare text in a gated branch has nothing to carry a class; wrap it.
				$text = $this->render_text( $child, $loop, $primary, $gate );
				$out .= '<span class="' . esc_attr( implode( ' ', $marks ) ) . '">' . $text . '</span>';
				continue;
			}
			$out .= $this->render_node( $child, $loop, $primary, $gate );
		}

		return $out;
	}

	/** @var array<int, array<int, string>> */
	private array $pending_marks = array();

	/** @param array<int, string> $marks */
	private function with_pending_marks( array $marks, callable $render ): string {
		$this->pending_marks[] = $marks;
		$out = $render();
		array_pop( $this->pending_marks );

		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $loop
	 * @param array<string, mixed>             $gate
	 */
	private function render_for( \DOMElement $el, array $loop, int $primary, array $gate ): string {
		$expr = self::mustache( (string) $el->getAttribute( 'list' ) );
		$as   = $el->getAttribute( 'as' ) !== '' ? (string) $el->getAttribute( 'as' ) : 'item';
		$lists = $this->evaluate_all( $expr, $loop );
		$base  = $lists[ $primary ];
		if ( ! is_array( $base ) ) {
			if ( $base !== null ) {
				$this->notes[] = 'sc-for list is not an array: ' . $expr;
			}
			return '';
		}
		$base = array_values( $base );
		$out  = '';
		foreach ( $base as $i => $item ) {
			$sub = $loop;
			foreach ( $this->vals as $vi => $vals ) {
				$list = $lists[ $vi ];
				$list = is_array( $list ) ? array_values( $list ) : array();
				$sub[ $vi ]          = $loop[ $vi ] ?? array();
				$sub[ $vi ][ $as ]    = array_key_exists( $i, $list ) ? $list[ $i ] : $item;
				$sub[ $vi ]['$index'] = $i;
			}
			$out .= $this->render_children( $el, $sub, $primary, $gate );
		}

		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $loop
	 * @param array<string, mixed>             $gate
	 * @param array<int, string>               $marks Extra classes from an enclosing gated branch.
	 */
	private function render_element( \DOMElement $el, string $tag, array $loop, int $primary, array $gate, array $marks = array() ): string {
		foreach ( $this->pending_marks as $pending ) {
			$marks = array_merge( $marks, $pending );
		}
		$this->pending_marks_consumed();

		$classes    = $marks;
		$attrs      = array();
		$data       = array();
		$this_id    = 0;
		$style_base = '';
		$css_vars   = array();

		foreach ( iterator_to_array( $el->attributes ) as $attr ) {
			if ( ! $attr instanceof \DOMAttr ) {
				continue;
			}
			$name  = strtolower( $attr->name );
			$value = (string) $attr->value;

			if ( $name === 'sc-name' || $name === 'data-dc-tpl' || str_starts_with( $name, 'hint-' ) ) {
				continue;
			}
			if ( str_starts_with( $name, 'style-' ) ) {
				$classes[] = $this->pseudo_class( substr( $name, 6 ), $value );
				continue;
			}
			if ( str_starts_with( $name, 'on' ) && strlen( $name ) > 2 ) {
				$this->wire_handler( $name, $value, $loop, $primary, $classes, $data, $attrs );
				continue;
			}
			if ( ! str_contains( $value, '{{' ) ) {
				if ( in_array( $name, self::BOOLEAN_ATTRS, true ) && $value === '' ) {
					continue;
				}
				if ( $name === 'style' ) {
					$style_base = $value;
					continue;
				}
				if ( $name === 'class' ) {
					$classes = array_merge( $classes, preg_split( '/\s+/', trim( $value ) ) ?: array() );
					continue;
				}
				$attrs[ $name ] = $value;
				continue;
			}

			$values = $this->attribute_values( $value, $loop );
			if ( $name === 'style' ) {
				$this_id = $this_id ?: ++$this->seq;
				$style_base = $this->project_style( $values, $primary, $this_id, $gate, $classes, $data );
				continue;
			}
			if ( $name === 'class' ) {
				$classes = array_merge( $classes, preg_split( '/\s+/', trim( Dc_Js::to_string( $values[ $primary ] ) ) ) ?: array() );
				continue;
			}
			$v = $values[ $primary ];
			if ( ( $name === 'value' || $name === 'checked' ) && $v === null ) {
				$v = $name === 'checked' ? false : '';
			}
			if ( $v === null || $v === false ) {
				continue;
			}
			if ( $v === true ) {
				$attrs[ $name ] = '';
				continue;
			}
			$attrs[ $name ] = Dc_Js::to_string( $v );
		}

		// Static inline style, plus whatever project_style() left inline.
		if ( $style_base !== '' ) {
			$attrs['style'] = $style_base;
		}

		// What componentDidMount() sets on the first element matching a
		// selector — `first.open = true` on the first FAQ.
		foreach ( $this->mount_attrs as $i => $mount ) {
			if ( $mount['done'] || ! self::matches( $tag, $attrs, $classes, $mount['selector'] ) ) {
				continue;
			}
			$this->mount_attrs[ $i ]['done'] = true;
			$attrs[ strtolower( $mount['attr'] ) ] = '';
		}

		$classes = array_values( array_unique( array_filter( array_map( 'trim', $classes ) ) ) );
		$html    = '<' . $tag;
		if ( $classes !== array() ) {
			$html .= ' class="' . esc_attr( implode( ' ', $classes ) ) . '"';
		}
		foreach ( $attrs as $name => $value ) {
			if ( $name === 'class' ) {
				continue;
			}
			$html .= ' ' . $name . '="' . esc_attr( $value ) . '"';
		}
		foreach ( $data as $name => $value ) {
			$html .= ' ' . $name . '="' . esc_attr( $value ) . '"';
		}
		$html .= '>';
		if ( in_array( $tag, self::VOID, true ) ) {
			return $html;
		}
		$html .= $this->render_children( $el, $loop, $primary, $gate );

		return $html . '</' . $tag . '>';
	}

	/**
	 * A simple selector — `details[data-faq-first]`, `#id`, `.class`,
	 * `tag[attr="v"]` — against an element being emitted.
	 *
	 * @param array<string, string> $attrs
	 * @param array<int, string>    $classes
	 */
	private static function matches( string $tag, array $attrs, array $classes, string $selector ): bool {
		$selector = trim( $selector );
		if ( preg_match( '/^([a-z][a-z0-9-]*)?((?:[#.\[][^#.\[\]]*\]?)*)$/i', $selector, $m ) !== 1 ) {
			return false;
		}
		if ( $m[1] !== '' && strtolower( $m[1] ) !== $tag ) {
			return false;
		}
		if ( preg_match_all( '/#([\w-]+)|\.([\w-]+)|\[([\w-]+)(?:=["\']?([^"\'\]]*)["\']?)?\]/', (string) $m[2], $parts, PREG_SET_ORDER ) ) {
			foreach ( $parts as $part ) {
				if ( isset( $part[1] ) && $part[1] !== '' ) {
					if ( ( $attrs['id'] ?? '' ) !== $part[1] ) {
						return false;
					}
				} elseif ( isset( $part[2] ) && $part[2] !== '' ) {
					if ( ! in_array( $part[2], $classes, true ) ) {
						return false;
					}
				} elseif ( isset( $part[3] ) ) {
					if ( ! array_key_exists( strtolower( $part[3] ), $attrs ) ) {
						return false;
					}
					if ( isset( $part[4] ) && $part[4] !== '' && $attrs[ strtolower( $part[3] ) ] !== $part[4] ) {
						return false;
					}
				}
			}
		}

		return true;
	}

	private function pending_marks_consumed(): void {
		// Marks carried through a nested directive apply to every element it
		// renders; they are popped by with_pending_marks(), not here.
	}

	/**
	 * A `{{ }}`-bearing attribute under every variant: the raw value for a
	 * whole-attribute binding, a joined string for a mixed one.
	 *
	 * @param array<int, array<string, mixed>> $loop
	 * @return array<int, mixed>
	 */
	private function attribute_values( string $raw, array $loop ): array {
		if ( preg_match( '/^\s*\{\{([\s\S]+?)\}\}\s*$/', $raw, $m ) === 1 ) {
			return $this->evaluate_all( $m[1], $loop );
		}
		$parts = preg_split( '/\{\{([\s\S]+?)\}\}/', $raw, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: array( $raw );
		$out   = array();
		foreach ( $this->vals as $i => $vals ) {
			$s = '';
			foreach ( $parts as $k => $part ) {
				if ( $k % 2 === 1 ) {
					$v  = $this->resolve( $part, $vals, $loop[ $i ] ?? array() );
					$s .= $v === null ? '' : Dc_Js::to_string( $v );
				} else {
					$s .= $part;
				}
			}
			$out[ $i ] = $s;
		}

		return $out;
	}

	private static function mustache( string $raw ): string {
		return preg_match( '/^\s*\{\{([\s\S]+?)\}\}\s*$/', $raw, $m ) === 1 ? trim( $m[1] ) : trim( $raw );
	}

	/**
	 * Split a CSS text into declarations, first-colon, keeping order.
	 *
	 * @return array<string, string>
	 */
	private static function declarations( string $css ): array {
		$out = array();
		foreach ( self::split_declarations( $css ) as $decl ) {
			$i = strpos( $decl, ':' );
			if ( $i === false ) {
				continue;
			}
			$prop = strtolower( trim( substr( $decl, 0, $i ) ) );
			if ( $prop === '' ) {
				continue;
			}
			$out[ $prop ] = trim( substr( $decl, $i + 1 ) );
		}

		return $out;
	}

	/** Split on `;` outside parentheses and quotes. */
	private static function split_declarations( string $css ): array {
		$out   = array();
		$depth = 0;
		$quote = '';
		$cur   = '';
		for ( $i = 0, $n = strlen( $css ); $i < $n; $i++ ) {
			$ch = $css[ $i ];
			if ( $quote !== '' ) {
				$cur .= $ch;
				if ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( $ch === '"' || $ch === "'" ) {
				$quote = $ch;
			} elseif ( $ch === '(' ) {
				++$depth;
			} elseif ( $ch === ')' ) {
				--$depth;
			} elseif ( $ch === ';' && $depth === 0 ) {
				$out[] = $cur;
				$cur   = '';
				continue;
			}
			$cur .= $ch;
		}
		if ( trim( $cur ) !== '' ) {
			$out[] = $cur;
		}

		return $out;
	}

	/**
	 * Static declarations stay inline; the ones that change with state move
	 * into classes the runtime (or a media query) switches.
	 *
	 * @param array<int, mixed>    $values Style text per variant.
	 * @param array<string, mixed> $gate
	 * @param array<int, string>   $classes
	 * @param array<string, string> $data
	 * @return string The inline style to keep.
	 */
	private function project_style( array $values, int $primary, int $id, array $gate, array &$classes, array &$data ): string {
		$decls = array();
		foreach ( $values as $i => $v ) {
			$decls[ $i ] = self::declarations( Dc_Js::to_string( $v ?? '' ) );
		}
		$base = $decls[ $primary ];
		$pm   = $this->variants[ $primary ]['mobile'];

		// Which properties vary, and with what.
		$media_diff = array();
		$key_diff   = array(); // key → value-slug → decls
		foreach ( $decls as $i => $d ) {
			if ( $i === $primary ) {
				continue;
			}
			$variant = $this->variants[ $i ];
			$changed = array();
			foreach ( array_unique( array_merge( array_keys( $base ), array_keys( $d ) ) ) as $prop ) {
				if ( ( $base[ $prop ] ?? null ) !== ( $d[ $prop ] ?? null ) ) {
					$changed[ $prop ] = $d[ $prop ] ?? 'initial';
				}
			}
			if ( $changed === array() ) {
				continue;
			}
			if ( $variant['key'] === null ) {
				if ( $variant['mobile'] !== $pm && ! isset( $gate['mobile'] ) ) {
					$media_diff = $changed;
				}
				continue;
			}
			if ( $variant['mobile'] !== $pm ) {
				continue;
			}
			$key_diff[ $variant['key'] ][ self::slug( $variant['value'] ) ] = array( 'value' => $variant['value'], 'decls' => $changed );
		}
		if ( $media_diff === array() && $key_diff === array() ) {
			return self::join_declarations( $base );
		}

		$variable = array_keys( $media_diff );
		foreach ( $key_diff as $per_value ) {
			foreach ( $per_value as $entry ) {
				$variable = array_merge( $variable, array_keys( $entry['decls'] ) );
			}
		}
		$variable = array_unique( $variable );

		$inline = array();
		$moved  = array();
		foreach ( $base as $prop => $val ) {
			if ( in_array( $prop, $variable, true ) ) {
				$moved[ $prop ] = $val;
			} else {
				$inline[ $prop ] = $val;
			}
		}
		$cls = 'dxai-dc-' . $id;
		$classes[] = $cls;
		if ( $moved !== array() ) {
			$this->css .= '.' . $cls . ' { ' . self::join_declarations( $moved ) . " }\n";
		}
		if ( $media_diff !== array() && $this->breakpoint > 0 ) {
			$query = $pm ? '(min-width: ' . ( $this->breakpoint + 1 ) . 'px)' : '(max-width: ' . $this->breakpoint . 'px)';
			$this->css .= '@media ' . $query . ' { .' . $cls . ' { ' . self::join_declarations( $media_diff ) . " } }\n";
		}
		foreach ( $key_diff as $key => $per_value ) {
			$kind = $this->key_kind[ $key ] ?? 'enum';
			if ( $kind === 'bool' ) {
				// One class, added while the state is on (or off).
				$on_now = $this->base_state_bool( $key, $primary );
				$entry  = reset( $per_value );
				$vc     = $cls . '--' . $key . '-' . ( $on_now ? 'off' : 'on' );
				$this->css .= '.' . $vc . ' { ' . self::join_declarations( $entry['decls'] ) . " }\n";
				$classes[]  = 'dxai-cls-' . $key;
				$data[ 'data-dxai-' . ( $on_now ? 'off' : 'on' ) . '-' . $key ] = $vc;
				continue;
			}
			foreach ( $per_value as $slug => $entry ) {
				$vc = $cls . '--' . $key . '-' . $slug;
				$this->css .= '.' . $vc . ' { ' . self::join_declarations( $entry['decls'] ) . " }\n";
				if ( $entry['value'] === null || $entry['value'] === false ) {
					// The "none" value: applied while no valued projection holds.
					$classes[] = 'dxai-cls-' . $key;
					$data[ 'data-dxai-off-' . $key ] = $vc;
					continue;
				}
				$classes[] = 'dxai-cls-' . $key . '--' . $slug;
				$data[ 'data-dxai-on-' . $key . '-' . $slug ] = $vc;
			}
		}

		return self::join_declarations( $inline );
	}

	/** @param array<string, string> $decls */
	private static function join_declarations( array $decls ): string {
		$out = array();
		foreach ( $decls as $prop => $val ) {
			$out[] = $prop . ':' . $val;
		}

		return implode( ';', $out );
	}

	/** `style-hover="…"` → `.dxai-sh-N:hover { … !important }`, as the runtime does. */
	private function pseudo_class( string $pseudo, string $decls ): string {
		$class = 'dxai-sh-' . ( ++$this->seq );
		$parts = array();
		foreach ( self::declarations( $decls ) as $prop => $val ) {
			$parts[] = $prop . ':' . $val . ( in_array( $pseudo, array( 'before', 'after' ), true ) ? '' : ' !important' );
		}
		$colon = in_array( $pseudo, array( 'before', 'after', 'placeholder', 'marker', 'selection' ), true ) ? '::' : ':';
		$this->css .= '.' . $class . $colon . $pseudo . ' { ' . implode( ';', $parts ) . " }\n";

		return $class;
	}

	/**
	 * `onClick="{{ handler }}"` → the runtime markers that reproduce it.
	 *
	 * @param array<int, array<string, mixed>> $loop
	 * @param array<int, string>               $classes
	 * @param array<string, string>            $data
	 * @param array<string, string>            $attrs
	 */
	private function wire_handler( string $event, string $raw, array $loop, int $primary, array &$classes, array &$data, array &$attrs ): void {
		$expr = self::mustache( $raw );
		$fn   = $this->resolve( $expr, $this->vals[ $primary ], $loop[ $primary ] ?? array() );
		if ( ! $fn instanceof Dc_Js_Fn ) {
			return;
		}
		$hover = in_array( $event, array( 'onmouseenter', 'onmouseover', 'onpointerenter', 'onfocus' ), true ) ? 'enter'
			: ( in_array( $event, array( 'onmouseleave', 'onmouseout', 'onpointerleave', 'onblur' ), true ) ? 'leave' : '' );
		if ( $event !== 'onclick' && $hover === '' ) {
			return; // onChange / onInput / onSubmit: form state, no layout.
		}
		$patches = $this->script->patches( $fn );
		foreach ( $patches as $key => $descriptors ) {
			if ( ! array_key_exists( $key, $this->base_state ) || is_array( $this->base_state[ $key ] ) ) {
				continue;
			}
			$sets = array();
			$flip = false;
			$step = null;
			foreach ( $descriptors as $d ) {
				if ( isset( $d['flip'] ) ) {
					$flip = true;
				} elseif ( array_key_exists( 'set', $d ) ) {
					$sets[] = $this->normalise( $key, $d['set'] );
				} elseif ( isset( $d['step'] ) ) {
					$step = $d;
				}
			}
			if ( $hover === 'leave' ) {
				// Leaving closes: the wrap's mouseleave handles a hover-opened
				// value; a leave handler on the trigger itself is marked so.
				$data['data-dxai-hover-self'] = '1';
				if ( ! in_array( 'dxai-toggle-' . $key, $classes, true ) && ! self::has_prefix( $classes, 'dxai-toggle-' . $key . '--' ) ) {
					$classes[] = 'dxai-toggle-' . $key;
					$data['data-dxai-set'] = 'null';
					$data['data-dxai-hover'] = 'leave';
				}
				continue;
			}
			if ( $step !== null ) {
				$classes[]              = 'dxai-toggle-' . $key;
				$data['data-dxai-step'] = (string) $step['step'];
				if ( isset( $step['clamp'] ) ) {
					$data['data-dxai-clamp'] = '1';
				}
				$this->init_marker( $key, $data );
			} elseif ( $flip ) {
				$classes[] = 'dxai-toggle-' . $key;
				$attrs['aria-expanded'] = Dc_Js::truthy( $this->base_state[ $key ] ) ? 'true' : 'false';
			} else {
				$nulls  = array_filter( $sets, static fn( $v ) => $v === null || $v === false );
				$values = array_values( array_filter( $sets, static fn( $v ) => $v !== null && $v !== false ) );
				if ( $values === array() ) {
					$classes[]             = 'dxai-toggle-' . $key;
					$data['data-dxai-set'] = 'null';
				} elseif ( count( $values ) === 1 && $values[0] === true ) {
					$classes[]             = 'dxai-toggle-' . $key;
					$data['data-dxai-set'] = 'true';
				} else {
					$classes[] = 'dxai-toggle-' . $key . '--' . self::slug( $values[0] );
					if ( $nulls !== array() ) {
						$data['data-dxai-mode'] = 'toggle';
					}
					$attrs['aria-expanded'] = $this->same( $this->base_state[ $key ], $values[0] ) ? 'true' : 'false';
					$this->init_marker( $key, $data );
				}
			}
			if ( $hover === 'enter' ) {
				$data['data-dxai-hover'] = 'enter';
			}
		}
	}

	/** @param array<int, string> $classes */
	private static function has_prefix( array $classes, string $prefix ): bool {
		foreach ( $classes as $c ) {
			if ( str_starts_with( $c, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * An enum state starting at a real value (openFaq: 0, formStep: 1) has
	 * to tell the machine so, once, or a relative step has no origin.
	 *
	 * @param array<string, string> $data
	 */
	private function init_marker( string $key, array &$data ): void {
		if ( isset( $this->init_done[ $key ] ) ) {
			return;
		}
		$v = $this->base_state[ $key ] ?? null;
		if ( $v === null || is_bool( $v ) ) {
			return;
		}
		$this->init_done[ $key ]  = true;
		$data['data-dxai-init']   = Dc_Js::to_string( $v );
	}

	/** A state value as a class-safe token. */
	private static function slug( mixed $value ): string {
		$s = sanitize_html_class( Dc_Js::to_string( $value ) );

		return $s === '' ? '0' : $s;
	}

	/**
	 * @param array<int, array<string, mixed>> $loop
	 * @param array<string, mixed>             $gate
	 */
	private function render_text( \DOMText $node, array $loop, int $primary, array $gate ): string {
		$text = $node->nodeValue ?? '';
		$parent = $node->parentNode instanceof \DOMElement ? strtolower( $node->parentNode->tagName ) : '';
		$plain  = in_array( $parent, self::TEXT_ONLY, true );
		if ( ! str_contains( $text, '{{' ) ) {
			// The runtime drops whitespace-only text that has no space character.
			if ( trim( $text ) === '' && ! str_contains( $text, ' ' ) ) {
				return '';
			}
			return $plain ? esc_html( $text ) : esc_html( $text );
		}
		$parts = preg_split( '/\{\{([\s\S]+?)\}\}/', $text, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: array( $text );
		$out   = '';
		foreach ( $parts as $k => $part ) {
			if ( $k % 2 === 0 ) {
				$out .= esc_html( $part );
				continue;
			}
			$values = $this->evaluate_all( $part, $loop );
			$v      = $values[ $primary ];
			if ( $v instanceof Dc_Js_Element || ( is_array( $v ) && array_is_list( $v ) ) ) {
				$out .= Dc_Js_Element::child_html( $v );
				continue;
			}
			if ( $v === null || is_bool( $v ) ) {
				continue;
			}
			$string = Dc_Js::to_string( $v );
			if ( $plain ) {
				$out .= esc_html( $string );
				continue;
			}
			$out .= $this->text_span( $string, $values, $primary );
		}

		return $out;
	}

	/**
	 * `<span class="sc-interp">` as the runtime renders it — with a text map
	 * when the words change with state ("+ 8 more cities" / "− Show fewer").
	 *
	 * @param array<int, mixed> $values
	 */
	private function text_span( string $text, array $values, int $primary ): string {
		$maps = array();
		$pm   = $this->variants[ $primary ]['mobile'];
		foreach ( $values as $i => $v ) {
			$variant = $this->variants[ $i ];
			if ( $variant['key'] === null || $variant['mobile'] !== $pm ) {
				continue;
			}
			$s = $v === null || is_bool( $v ) ? '' : Dc_Js::to_string( $v );
			if ( $s !== $text ) {
				$maps[ $variant['key'] ][ self::machine_key( $variant['value'] ) ] = $s;
			}
		}
		$attrs = '';
		foreach ( $maps as $key => $map ) {
			$map[ self::machine_key( $this->base_state[ $key ] ?? null ) ] = $text;
			$attrs .= ' data-dxai-text-' . $key . '="' . esc_attr( (string) wp_json_encode( $map ) ) . '"';
		}

		return '<span class="sc-interp"' . $attrs . '>' . esc_html( $text ) . '</span>';
	}

	/** How the runtime's machine spells a state value as a map key. */
	private static function machine_key( mixed $value ): string {
		if ( $value === null || ( is_int( $value ) && $value < 0 ) ) {
			return 'null';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		return Dc_Js::to_string( $value );
	}
}

<?php
/**
 * The logic class of a Claude Design export, parsed and runnable.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

/**
 * `<script type="text/x-dc" data-dc-script>` holds `class Component extends
 * DCLogic { state = {…}; componentDidMount() {…} renderVals() {…} … }`. The
 * runtime renders the template against `{ ...props, ...renderVals() }`, so
 * this is what the converter has to be able to do too — for the initial
 * state and, to know what the page looks like after a click or on a phone,
 * for every state a handler or a media query can put the component in.
 *
 * Besides evaluating, it reads three things off `componentDidMount()` that
 * no template attribute records: which state keys follow a `matchMedia()`
 * query (responsive layout done in JavaScript, which the page has to redo
 * with CSS), which follow `window.scrollY` (a header that changes once the
 * page is scrolled), and which a document-level click resets (a dropdown
 * that closes when you click elsewhere).
 */
final class Dc_Script {

	private Dc_Js $js;

	/** @var array<string, array<string, mixed>> Field name → initialiser AST. */
	private array $fields = array();

	/** @var array<string, array{params: array<int, array<string, mixed>>, body: array<int, array<string, mixed>>, source: string}> */
	private array $methods = array();

	/** @var array<string, mixed> */
	private array $props_meta;

	/** @var array<string, mixed>|null */
	private ?array $initial_state = null;

	/**
	 * @param string               $script    The script element's text.
	 * @param array<string, mixed> $props_meta Decoded `data-props` JSON, if any.
	 */
	public function __construct( string $script, array $props_meta = array() ) {
		$this->js         = new Dc_Js();
		$this->props_meta = $props_meta;

		$body = self::class_body( $script );
		if ( $body !== '' ) {
			$parsed        = $this->js->parse_class_body( $body );
			$this->fields  = $parsed['fields'];
			$this->methods = $parsed['methods'];
		}
	}

	/** Whether a renderable component was found at all. */
	public function is_component(): bool {
		return isset( $this->methods['renderVals'] ) || $this->fields !== array();
	}

	/** @return array<int, string> */
	public function unevaluated(): array {
		return $this->js->unevaluated();
	}

	/** @return array<int, string> */
	public function method_names(): array {
		return array_keys( $this->methods );
	}

	/**
	 * The class-field `state = {…}` evaluated once.
	 *
	 * @return array<string, mixed>
	 */
	public function initial_state(): array {
		if ( $this->initial_state === null ) {
			$state = array();
			if ( isset( $this->fields['state'] ) ) {
				$inst  = $this->instance( array(), $this->props_defaults() );
				$value = $this->js->evaluate( $this->fields['state'], array(), $inst );
				$state = is_array( $value ) ? $value : array();
			}
			$this->initial_state = $state;
		}

		return $this->initial_state;
	}

	/**
	 * `default` of every entry of `data-props`.
	 *
	 * @return array<string, mixed>
	 */
	public function props_defaults(): array {
		$out = array();
		foreach ( $this->props_meta as $name => $meta ) {
			if ( ! is_string( $name ) || str_starts_with( $name, '$' ) ) {
				continue;
			}
			if ( is_array( $meta ) && array_key_exists( 'default', $meta ) ) {
				$out[ $name ] = $meta['default'];
			}
		}

		return $out;
	}

	/**
	 * A `this` with the given state, its props, fields and bound methods.
	 *
	 * @param array<string, mixed> $state
	 * @param array<string, mixed> $props
	 */
	public function instance( array $state, array $props ): Dc_Js_Instance {
		$inst        = new Dc_Js_Instance();
		$inst->state = $state;
		$inst->props = $props;
		foreach ( $this->methods as $name => $method ) {
			$inst->methods[ $name ] = new Dc_Js_Fn( $method['params'], $method['body'], false, array(), $inst, $method['source'] );
		}
		foreach ( $this->fields as $name => $ast ) {
			if ( $name === 'state' ) {
				continue;
			}
			$inst->fields[ $name ] = $this->js->evaluate( $ast, array(), $inst );
		}

		return $inst;
	}

	/**
	 * What the template renders against for one state: props merged with
	 * `renderVals()`, exactly as the runtime builds it.
	 *
	 * @param array<string, mixed>      $state
	 * @param array<string, mixed>|null $props
	 * @return array<string, mixed>
	 */
	public function render_vals( array $state, ?array $props = null ): array {
		$props = $props ?? $this->props_defaults();
		$inst  = $this->instance( $state, $props );
		$vals  = array();
		if ( isset( $inst->methods['renderVals'] ) ) {
			$out  = $this->js->call( $inst->methods['renderVals'], array(), $inst );
			$vals = is_array( $out ) ? $out : array();
		}

		return array_merge( $props, $vals );
	}

	/**
	 * The state transitions a handler value can make — see Dc_Js::patches().
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function patches( Dc_Js_Fn $handler ): array {
		return $this->js->patches( $handler );
	}

	/**
	 * What `componentDidMount()` wires the state to.
	 *
	 * @return array{media: array<string, string>, scroll: array<string, int>, outside: array<int, string>, mount: array<string, mixed>}
	 */
	public function mount_effects(): array {
		$out = array(
			'media'   => array(),
			'scroll'  => array(),
			'outside' => array(),
			'mount'   => array(),
			'open'    => array(),
		);
		$mount = $this->methods['componentDidMount'] ?? null;
		if ( $mount === null ) {
			return $out;
		}
		$body = $mount['body'];

		/*
		 * `const first = document.querySelector('details[data-faq-first]');
		 * if (first) first.open = true;` — the design opens an element after
		 * mount instead of writing `open` in the markup. The selector is
		 * recorded so the renderer can write the attribute.
		 */
		$selectors = array();
		self::walk( $body, static function ( array $node ) use ( &$selectors ): void {
			if ( ( $node['k'] ?? '' ) !== 'var' ) {
				return;
			}
			foreach ( $node['decl'] as $decl ) {
				$init = $decl['init'];
				if ( $init !== null && $init['k'] === 'call' && $init['f']['k'] === 'mem' && $init['f']['p'] === 'querySelector' && isset( $init['args'][0] ) && $init['args'][0]['k'] === 'str' && $decl['target']['k'] === 'id' ) {
					$selectors[ (string) $decl['target']['n'] ] = (string) $init['args'][0]['v'];
				}
			}
		} );
		self::walk( $body, static function ( array $node ) use ( &$selectors, &$out ): void {
			if ( ( $node['k'] ?? '' ) !== 'assign' ) {
				return;
			}
			$t = $node['t'];
			if ( $t['k'] === 'mem' && $t['o']['k'] === 'id' && isset( $selectors[ (string) $t['o']['n'] ] ) && $node['v']['k'] === 'lit' && $node['v']['v'] === true ) {
				$out['open'][] = array( 'selector' => $selectors[ (string) $t['o']['n'] ], 'attr' => (string) $t['p'] );
			}
		} );

		// The media queries the component watches.
		$queries = array();
		foreach ( self::find_calls( $body, 'matchMedia' ) as $call ) {
			$arg = $call['args'][0] ?? null;
			if ( is_array( $arg ) && $arg['k'] === 'str' ) {
				$queries[] = (string) $arg['v'];
			}
		}

		/*
		 * Which query each name holds: `this.mq = window.matchMedia('(max-width: 860px)')` is `mq`, and the key that follows
		 * `this.mq.matches` follows THAT query. A component with two (a mobile one and a wide one: Five Star's header) has a key
		 * for each; every key used to read the first query, so the wide one followed the mobile breakpoint, inverted.
		 */
		$names = self::query_names( $body );

		// Locals declared in the mount body, so `const s = window.scrollY > 20;
		// … setState({ scrolled: s })` can be followed.
		$locals = array();
		self::collect_locals( $body, $locals );

		// Listeners are as often stored first and registered by name —
		// `this._onDoc = (e) => { … }; document.addEventListener('click',
		// this._onDoc)` — so the handler functions are collected up front and
		// the registration is resolved back to them.
		$handlers = array();
		self::collect_handlers( $body, $handlers );

		foreach ( self::find_setstate( $body, false, $handlers ) as $found ) {
			$patch = $found['arg'];
			if ( $patch['k'] === 'fn' ) {
				$patch = $patch['expr'] ? $patch['body'] : null;
			}
			if ( $patch === null || $patch['k'] !== 'obj' ) {
				continue;
			}
			foreach ( $patch['props'] as $prop ) {
				if ( isset( $prop['spread'] ) || $prop['computed'] ) {
					continue;
				}
				$key  = (string) $prop['key'];
				$expr = $prop['v'];
				if ( $expr['k'] === 'id' && isset( $locals[ (string) $expr['n'] ] ) ) {
					$expr = $locals[ (string) $expr['n'] ];
				}
				if ( self::mentions( $expr, 'matches' ) ) {
					$out['media'][ $key ] = self::query_of( $expr, $names ) ?? ( $queries[0] ?? '' );
					continue;
				}
				if ( self::mentions( $expr, 'scrollY' ) || self::mentions( $expr, 'pageYOffset' ) ) {
					$out['scroll'][ $key ] = self::threshold( $expr );
					continue;
				}
				if ( $found['on_document_click'] ) {
					$out['outside'][] = $key;
				}
			}
		}
		$out['outside'] = array_values( array_unique( $out['outside'] ) );

		return $out;
	}

	/* ---------------------------------------------------------- helpers */

	/** The text between the class's braces. */
	private static function class_body( string $script ): string {
		if ( preg_match( '/class\s+\w+(?:\s+extends\s+[\w.]+)?\s*\{/', $script, $m, PREG_OFFSET_CAPTURE ) !== 1 ) {
			return '';
		}
		$open  = (int) $m[0][1] + strlen( $m[0][0] ) - 1;
		$depth = 0;
		$len   = strlen( $script );
		for ( $i = $open; $i < $len; $i++ ) {
			$ch = $script[ $i ];
			if ( $ch === '"' || $ch === "'" || $ch === '`' ) {
				$i = self::skip_quoted( $script, $i ) - 1;
				continue;
			}
			if ( $ch === '/' && $i + 1 < $len && $script[ $i + 1 ] === '/' ) {
				$nl = strpos( $script, "\n", $i );
				$i  = $nl === false ? $len : $nl;
				continue;
			}
			if ( $ch === '/' && $i + 1 < $len && $script[ $i + 1 ] === '*' ) {
				$close = strpos( $script, '*/', $i + 2 );
				$i     = $close === false ? $len : $close + 1;
				continue;
			}
			if ( $ch === '{' ) {
				++$depth;
			} elseif ( $ch === '}' ) {
				--$depth;
				if ( $depth === 0 ) {
					return substr( $script, $open + 1, $i - $open - 1 );
				}
			}
		}

		return substr( $script, $open + 1 );
	}

	private static function skip_quoted( string $src, int $at ): int {
		$q = $src[ $at ];
		$n = strlen( $src );
		$j = $at + 1;
		while ( $j < $n && $src[ $j ] !== $q ) {
			if ( $src[ $j ] === '\\' ) {
				++$j;
			}
			++$j;
		}

		return $j + 1;
	}

	/**
	 * Every call node whose callee ends with `name`.
	 *
	 * @param array<int, mixed> $nodes
	 * @return array<int, array<string, mixed>>
	 */
	private static function find_calls( array $nodes, string $name ): array {
		$out = array();
		self::walk( $nodes, static function ( array $node ) use ( &$out, $name ): void {
			if ( ( $node['k'] ?? '' ) !== 'call' ) {
				return;
			}
			$f = $node['f'];
			if ( ( $f['k'] === 'id' && $f['n'] === $name ) || ( $f['k'] === 'mem' && $f['p'] === $name ) ) {
				$out[] = $node;
			}
		} );

		return $out;
	}

	/**
	 * Every `this.setState(ARG)`, with whether it sits inside a document
	 * click listener.
	 *
	 * @param array<int, mixed> $nodes
	 * @return array<int, array{arg: array<string, mixed>, on_document_click: bool}>
	 */
	private static function find_setstate( array $nodes, bool $in_doc_click = false, array $handlers = array() ): array {
		$out = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$k = $node['k'] ?? '';
			if ( $k === 'call' ) {
				$f = $node['f'];
				if ( $f['k'] === 'mem' && $f['p'] === 'setState' && $f['o']['k'] === 'this' && isset( $node['args'][0] ) ) {
					$out[] = array( 'arg' => $node['args'][0], 'on_document_click' => $in_doc_click );
				}
				if ( $f['k'] === 'mem' && $f['p'] === 'addEventListener' ) {
					$target = $f['o'];
					$event  = $node['args'][0] ?? null;
					$is_doc = ( $target['k'] === 'id' && $target['n'] === 'document' )
						|| ( $target['k'] === 'mem' && $target['p'] === 'document' );
					if ( $is_doc && is_array( $event ) && $event['k'] === 'str' && in_array( $event['v'], array( 'click', 'pointerdown', 'mousedown' ), true ) ) {
						$listener = $node['args'][1] ?? null;
						if ( is_array( $listener ) && $listener['k'] !== 'fn' ) {
							$name = $listener['k'] === 'mem' ? (string) $listener['p'] : ( $listener['k'] === 'id' ? (string) $listener['n'] : '' );
							if ( isset( $handlers[ $name ] ) ) {
								$listener = $handlers[ $name ];
							}
						}
						if ( is_array( $listener ) ) {
							$out = array_merge( $out, self::find_setstate( array( $listener ), true, $handlers ) );
						}
						continue;
					}
				}
			}
			foreach ( $node as $child ) {
				if ( is_array( $child ) ) {
					$out = array_merge( $out, self::find_setstate( array_is_list( $child ) ? $child : array( $child ), $in_doc_click, $handlers ) );
				}
			}
		}

		return $out;
	}

	/**
	 * `this.NAME = fn` and `const NAME = fn` in a body: handler functions by name.
	 *
	 * @param array<int, mixed>                  $nodes
	 * @param array<string, array<string, mixed>> $out
	 */
	private static function collect_handlers( array $nodes, array &$out ): void {
		self::walk( $nodes, static function ( array $node ) use ( &$out ): void {
			if ( ( $node['k'] ?? '' ) === 'assign' && $node['v']['k'] === 'fn' ) {
				$t = $node['t'];
				if ( $t['k'] === 'mem' && $t['o']['k'] === 'this' ) {
					$out[ (string) $t['p'] ] = $node['v'];
				} elseif ( $t['k'] === 'id' ) {
					$out[ (string) $t['n'] ] = $node['v'];
				}
			}
			if ( ( $node['k'] ?? '' ) === 'var' ) {
				foreach ( $node['decl'] as $decl ) {
					if ( $decl['target']['k'] === 'id' && $decl['init'] !== null && $decl['init']['k'] === 'fn' ) {
						$out[ (string) $decl['target']['n'] ] = $decl['init'];
					}
				}
			}
		} );
	}

	/**
	 * `const NAME = EXPR` anywhere in a body, including nested arrows.
	 *
	 * @param array<int, mixed>                  $nodes
	 * @param array<string, array<string, mixed>> $out
	 */
	private static function collect_locals( array $nodes, array &$out ): void {
		self::walk( $nodes, static function ( array $node ) use ( &$out ): void {
			if ( ( $node['k'] ?? '' ) !== 'var' ) {
				return;
			}
			foreach ( $node['decl'] as $decl ) {
				if ( $decl['target']['k'] === 'id' && $decl['init'] !== null ) {
					$out[ (string) $decl['target']['n'] ] = $decl['init'];
				}
			}
			// Also `this._onScroll = () => { const s = …; this.setState({ scrolled: s }) }`
			// keeps its local inside the arrow; walk() reaches it.
		} );
	}

	/**
	 * The media query each name was given: `this.mq = window.matchMedia('…')` and `const wq = matchMedia('…')`.
	 *
	 * @param array<int, mixed> $body
	 * @return array<string, string> Name => query.
	 */
	private static function query_names( array $body ): array {
		$out  = array();
		$take = static function ( string $name, mixed $init ) use ( &$out ): void {
			if ( $name === '' || ! is_array( $init ) || ( $init['k'] ?? '' ) !== 'call' ) {
				return;
			}
			$f   = $init['f'];
			$arg = $init['args'][0] ?? null;
			if ( ( ( $f['k'] === 'mem' && $f['p'] === 'matchMedia' ) || ( $f['k'] === 'id' && $f['n'] === 'matchMedia' ) ) && is_array( $arg ) && $arg['k'] === 'str' ) {
				$out[ $name ] = (string) $arg['v'];
			}
		};
		self::walk(
			$body,
			static function ( array $node ) use ( $take ): void {
				$k = $node['k'] ?? '';
				if ( $k === 'assign' ) {
					$t = $node['t'];
					$take( $t['k'] === 'mem' && $t['o']['k'] === 'this' ? (string) $t['p'] : ( $t['k'] === 'id' ? (string) $t['n'] : '' ), $node['v'] );
				} elseif ( $k === 'var' ) {
					foreach ( $node['decl'] as $decl ) {
						if ( $decl['target']['k'] === 'id' ) {
							$take( (string) $decl['target']['n'], $decl['init'] );
						}
					}
				}
			}
		);

		return $out;
	}

	/**
	 * The query an expression's `.matches` reads: `this.wq.matches`, `mq.matches` or `matchMedia('…').matches`; null when it
	 * cannot be told.
	 *
	 * @param array<string, mixed>  $expr
	 * @param array<string, string> $names query_names()
	 */
	private static function query_of( array $expr, array $names ): ?string {
		$found = null;
		self::walk(
			array( $expr ),
			static function ( array $n ) use ( &$found, $names ): void {
				if ( null !== $found || ( $n['k'] ?? '' ) !== 'mem' || $n['p'] !== 'matches' ) {
					return;
				}
				$o = $n['o'];
				if ( $o['k'] === 'call' && isset( $o['args'][0] ) && $o['args'][0]['k'] === 'str' ) {
					$found = (string) $o['args'][0]['v'];
					return;
				}
				$name = $o['k'] === 'mem' && $o['o']['k'] === 'this' ? (string) $o['p'] : ( $o['k'] === 'id' ? (string) $o['n'] : '' );
				if ( isset( $names[ $name ] ) ) {
					$found = $names[ $name ];
				}
			}
		);

		return $found;
	}

	/** Whether an AST mentions a member or identifier of this name. */
	private static function mentions( array $node, string $name ): bool {
		$hit = false;
		self::walk( array( $node ), static function ( array $n ) use ( &$hit, $name ): void {
			if ( ( ( $n['k'] ?? '' ) === 'mem' && $n['p'] === $name ) || ( ( $n['k'] ?? '' ) === 'id' && $n['n'] === $name ) ) {
				$hit = true;
			}
		} );

		return $hit;
	}

	/** The literal a scroll comparison uses: `window.scrollY > 20` → 20. */
	private static function threshold( array $node ): int {
		$found = 0;
		self::walk( array( $node ), static function ( array $n ) use ( &$found ): void {
			if ( ( $n['k'] ?? '' ) === 'bin' && in_array( $n['op'], array( '>', '>=', '<', '<=' ), true ) ) {
				if ( $n['r']['k'] === 'num' ) {
					$found = (int) $n['r']['v'];
				} elseif ( $n['l']['k'] === 'num' ) {
					$found = (int) $n['l']['v'];
				}
			}
		} );

		return $found;
	}

	/**
	 * Depth-first over every array node.
	 *
	 * @param array<int, mixed> $nodes
	 */
	private static function walk( array $nodes, callable $visit ): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( isset( $node['k'] ) ) {
				$visit( $node );
			}
			foreach ( $node as $child ) {
				if ( is_array( $child ) ) {
					self::walk( array_is_list( $child ) ? $child : array( $child ), $visit );
				}
			}
		}
	}
}

<?php
/**
 * A small JavaScript interpreter for Claude Design component logic.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

/**
 * Why a second evaluator exists next to Jsx_Compiler's.
 *
 * A Claude Design export (`<Name>.dc.html`) carries its page logic as a plain
 * JavaScript class — `class Component extends DCLogic { state = {…};
 * renderVals() { … } }` — and the template only ever reads the flat object
 * `renderVals()` returns. Everything that decides how the page looks is
 * computed in that method: `heroStyle: 'min-height:' + (s.isMobile ? '620px'
 * : 'calc(100vh - 100px)')`, `faqs: this._faqData().map((f, i) => ({ …,
 * answerStyle: …, toggle: () => this.setState({ openFaq: i }) }))`, inline
 * SVG icons built with `React.createElement`. Rendering the template at rest
 * therefore means running that method, against the class's initial state.
 *
 * Jsx_Compiler evaluates JSX expressions, where `this` does not exist and a
 * component is a function of props; a class with methods, instance fields,
 * regex literals and `React.createElement` trees is a different language
 * surface, and bolting it onto a 7000-line compiler that every Lovable design
 * depends on would risk all of them for two exports. This interpreter is
 * self-contained: tokenizer, parser to a small AST, evaluator, plus the one
 * piece of analysis a converter needs and an interpreter alone cannot give —
 * which states a handler can move the component to (see patches()).
 *
 * Deliberately NOT modelled: loops, exceptions, `new` (apart from returning
 * null), async, getters/setters, prototypes. `undefined` and `null` are both
 * PHP null. Anything unknown evaluates to null and is reported through
 * unevaluated(), never silently as a wrong value of another type.
 */
final class Dc_Js {

	/** @var array<int, array{t: string, v: mixed, p: int}> */
	private array $toks = array();
	private int $i = 0;

	/** @var array<int, string> */
	private array $unevaluated = array();

	/** Viewport the desktop baseline is rendered at, for `window.innerWidth`. */
	public int $viewport_width = 1280;

	/* ------------------------------------------------------------- public */

	/** Everything the interpreter met and could not do, deduplicated. */
	public function unevaluated(): array {
		return array_values( array_unique( $this->unevaluated ) );
	}

	/**
	 * Parse an expression into an AST.
	 *
	 * @return array<string, mixed>
	 */
	public function parse_expression_source( string $src ): array {
		$this->load( $src );
		$node = $this->parse_expression();

		return $node;
	}

	/**
	 * Parse a statement list (a method or arrow body) into an AST list.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function parse_body_source( string $src ): array {
		$this->load( $src );
		$out = array();
		while ( $this->peek()['t'] !== 'eof' ) {
			$stmt = $this->parse_statement();
			if ( $stmt !== null ) {
				$out[] = $stmt;
			}
		}

		return $out;
	}

	/**
	 * Split a class body into its members.
	 *
	 * @return array{fields: array<string, array<string, mixed>>, methods: array<string, array{params: array<int, array<string, mixed>>, body: array<int, array<string, mixed>>, source: string}>}
	 */
	public function parse_class_body( string $src ): array {
		$this->load( $src );
		$fields  = array();
		$methods = array();
		while ( $this->peek()['t'] !== 'eof' ) {
			$tok = $this->peek();
			if ( $tok['t'] === 'punct' && $tok['v'] === ';' ) {
				$this->next();
				continue;
			}
			if ( $tok['t'] === 'id' && in_array( $tok['v'], array( 'static', 'async', 'get', 'set' ), true ) ) {
				$after = $this->peek( 1 );
				if ( $after['t'] === 'id' ) {
					$this->next();
					$tok = $this->peek();
				}
			}
			if ( $tok['t'] !== 'id' && $tok['t'] !== 'str' ) {
				$this->next();
				continue;
			}
			$name = (string) $tok['v'];
			$this->next();
			$after = $this->peek();
			if ( $after['t'] === 'punct' && $after['v'] === '=' ) {
				$this->next();
				$fields[ $name ] = $this->parse_expression();
				continue;
			}
			if ( $after['t'] === 'punct' && $after['v'] === '(' ) {
				$params = $this->parse_params();
				$start  = $this->peek()['p'];
				$body   = $this->parse_block_statements();
				$end    = $this->i > 0 ? $this->toks[ $this->i - 1 ]['p'] : $start;
				$methods[ $name ] = array(
					'params' => $params,
					'body'   => $body,
					'source' => substr( $this->source, $start, max( 0, $end - $start + 1 ) ),
				);
				continue;
			}
		}

		return array( 'fields' => $fields, 'methods' => $methods );
	}

	/**
	 * Evaluate an AST with the given variables and `this`.
	 *
	 * @param array<string, mixed> $node
	 * @param array<string, mixed> $scope
	 */
	public function evaluate( array $node, array $scope, ?Dc_Js_Instance $this_ref = null ): mixed {
		return $this->ev( $node, $scope, $this_ref );
	}

	/**
	 * Call a function value.
	 *
	 * @param array<int, mixed> $args
	 */
	public function call( mixed $fn, array $args, ?Dc_Js_Instance $this_ref = null ): mixed {
		if ( $fn instanceof Dc_Js_Fn ) {
			return $this->invoke( $fn, $args, $this_ref ?? $fn->this_ref );
		}
		$this->note( 'call of non-function' );

		return null;
	}

	/**
	 * Which state each `this.setState()` in a handler can produce.
	 *
	 * Handlers are not run — running `() => this.setState({ menuOpen:
	 * !s.menuOpen })` once would tell us one next state and nothing about the
	 * one after. The body is read instead: for every key the handler patches,
	 * what the value expression can evaluate to.
	 *
	 * @return array<string, array<int, array<string, mixed>>> key → list of
	 *         descriptors: {set: value} | {flip: true} | {step: ±1, bound: n}
	 *         | {keep: true} | {unknown: true}
	 */
	public function patches( Dc_Js_Fn $fn ): array {
		$out   = array();
		$calls = array();
		$this->collect_setstate( $fn->expression ? array( $fn->body ) : $fn->body, $calls );
		foreach ( $calls as $arg ) {
			$obj = $arg;
			if ( $obj['k'] === 'fn' ) {
				// `p => ({ open: !p.open })`: the updater's return is the patch.
				$obj = $obj['expr'] ? $obj['body'] : $this->returned_expression( $obj['body'] );
			}
			if ( $obj === null || $obj['k'] !== 'obj' ) {
				continue;
			}
			foreach ( $obj['props'] as $prop ) {
				if ( isset( $prop['spread'] ) ) {
					continue;
				}
				$key = $prop['computed'] ? null : $prop['key'];
				if ( ! is_string( $key ) ) {
					continue;
				}
				$out[ $key ] = array_merge( $out[ $key ] ?? array(), $this->enumerate( $prop['v'], $fn->env, $fn->this_ref ) );
			}
		}

		return $out;
	}

	/* ------------------------------------------------------- conversions */

	/** JavaScript's ToString. */
	public static function to_string( mixed $value ): string {
		if ( $value === null ) {
			return 'null';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_float( $value ) ) {
			if ( is_nan( $value ) ) {
				return 'NaN';
			}
			if ( is_infinite( $value ) ) {
				return $value > 0 ? 'Infinity' : '-Infinity';
			}
			if ( floor( $value ) === $value && abs( $value ) < 1e15 ) {
				return (string) (int) $value;
			}
			$text = json_encode( $value );

			return is_string( $text ) ? $text : (string) $value;
		}
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( $value instanceof Dc_Js_Element ) {
			return '[object Object]';
		}
		if ( $value instanceof Dc_Js_Fn ) {
			return $value->source;
		}
		if ( $value instanceof Dc_Js_Regex ) {
			return '/' . $value->pattern . '/' . $value->flags;
		}
		if ( is_array( $value ) ) {
			if ( array_is_list( $value ) ) {
				return implode( ',', array_map( array( self::class, 'to_string' ), $value ) );
			}
			return '[object Object]';
		}

		return '';
	}

	/** JavaScript truthiness. */
	public static function truthy( mixed $value ): bool {
		if ( $value === null || $value === false || $value === '' || $value === 0 || $value === 0.0 ) {
			return false;
		}
		if ( is_float( $value ) && is_nan( $value ) ) {
			return false;
		}

		return true;
	}

	/** JavaScript's ToNumber. */
	public static function to_number( mixed $value ): int|float {
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		if ( $value === null || $value === false ) {
			return 0;
		}
		if ( $value === true ) {
			return 1;
		}
		if ( is_string( $value ) ) {
			$trim = trim( $value );
			if ( $trim === '' ) {
				return 0;
			}
			if ( is_numeric( $trim ) ) {
				return str_contains( $trim, '.' ) || stripos( $trim, 'e' ) !== false ? (float) $trim : (int) $trim;
			}
			return NAN;
		}
		if ( is_array( $value ) && array_is_list( $value ) && count( $value ) === 1 ) {
			return self::to_number( $value[0] );
		}

		return NAN;
	}

	/* ------------------------------------------------------------ lexer */

	private string $source = '';

	private function load( string $src ): void {
		$this->source = $src;
		$this->toks   = $this->tokenize( $src );
		$this->i      = 0;
	}

	/**
	 * @return array<int, array{t: string, v: mixed, p: int}>
	 */
	public function tokenize( string $src ): array {
		$toks = array();
		$len  = strlen( $src );
		$i    = 0;
		$last = null;
		while ( $i < $len ) {
			$ch = $src[ $i ];
			// Whitespace.
			if ( ctype_space( $ch ) ) {
				++$i;
				continue;
			}
			// Comments.
			if ( $ch === '/' && $i + 1 < $len && $src[ $i + 1 ] === '/' ) {
				$nl = strpos( $src, "\n", $i );
				$i  = $nl === false ? $len : $nl + 1;
				continue;
			}
			if ( $ch === '/' && $i + 1 < $len && $src[ $i + 1 ] === '*' ) {
				$close = strpos( $src, '*/', $i + 2 );
				$i     = $close === false ? $len : $close + 2;
				continue;
			}
			$start = $i;
			// Numbers.
			if ( ctype_digit( $ch ) || ( $ch === '.' && $i + 1 < $len && ctype_digit( $src[ $i + 1 ] ) ) ) {
				$j = $i;
				if ( $ch === '0' && $j + 1 < $len && ( $src[ $j + 1 ] === 'x' || $src[ $j + 1 ] === 'X' ) ) {
					$j += 2;
					while ( $j < $len && ctype_xdigit( $src[ $j ] ) ) {
						++$j;
					}
					$toks[] = array( 't' => 'num', 'v' => hexdec( substr( $src, $i + 2, $j - $i - 2 ) ), 'p' => $start );
					$i      = $j;
					$last   = 'num';
					continue;
				}
				while ( $j < $len && ( ctype_digit( $src[ $j ] ) || $src[ $j ] === '.' || $src[ $j ] === '_' ) ) {
					++$j;
				}
				if ( $j < $len && ( $src[ $j ] === 'e' || $src[ $j ] === 'E' ) ) {
					$k = $j + 1;
					if ( $k < $len && ( $src[ $k ] === '+' || $src[ $k ] === '-' ) ) {
						++$k;
					}
					if ( $k < $len && ctype_digit( $src[ $k ] ) ) {
						$j = $k;
						while ( $j < $len && ctype_digit( $src[ $j ] ) ) {
							++$j;
						}
					}
				}
				$text = str_replace( '_', '', substr( $src, $i, $j - $i ) );
				$toks[] = array( 't' => 'num', 'v' => str_contains( $text, '.' ) || stripos( $text, 'e' ) !== false ? (float) $text : (int) $text, 'p' => $start );
				$i      = $j;
				$last   = 'num';
				continue;
			}
			// Identifiers and keywords.
			if ( ctype_alpha( $ch ) || $ch === '_' || $ch === '$' || ord( $ch ) > 127 ) {
				$j = $i;
				while ( $j < $len && ( ctype_alnum( $src[ $j ] ) || $src[ $j ] === '_' || $src[ $j ] === '$' || ord( $src[ $j ] ) > 127 ) ) {
					++$j;
				}
				$toks[] = array( 't' => 'id', 'v' => substr( $src, $i, $j - $i ), 'p' => $start );
				$i      = $j;
				$last   = 'id';
				continue;
			}
			// Strings.
			if ( $ch === '"' || $ch === "'" ) {
				$j   = $i + 1;
				$out = '';
				while ( $j < $len && $src[ $j ] !== $ch ) {
					if ( $src[ $j ] === '\\' && $j + 1 < $len ) {
						$out .= $this->unescape( $src, $j );
						continue;
					}
					$out .= $src[ $j ];
					++$j;
				}
				$toks[] = array( 't' => 'str', 'v' => $out, 'p' => $start );
				$i      = $j + 1;
				$last   = 'str';
				continue;
			}
			// Template literals.
			if ( $ch === '`' ) {
				$j     = $i + 1;
				$parts = array();
				$text  = '';
				while ( $j < $len && $src[ $j ] !== '`' ) {
					if ( $src[ $j ] === '\\' && $j + 1 < $len ) {
						$text .= $this->unescape( $src, $j );
						continue;
					}
					if ( $src[ $j ] === '$' && $j + 1 < $len && $src[ $j + 1 ] === '{' ) {
						$parts[] = array( 's' => $text );
						$text    = '';
						$depth   = 1;
						$k       = $j + 2;
						while ( $k < $len && $depth > 0 ) {
							$c = $src[ $k ];
							if ( $c === '{' ) {
								++$depth;
							} elseif ( $c === '}' ) {
								--$depth;
								if ( $depth === 0 ) {
									break;
								}
							} elseif ( $c === '"' || $c === "'" || $c === '`' ) {
								$k = $this->skip_string( $src, $k );
								continue;
							}
							++$k;
						}
						$parts[] = array( 'e' => substr( $src, $j + 2, $k - $j - 2 ) );
						$j       = $k + 1;
						continue;
					}
					$text .= $src[ $j ];
					++$j;
				}
				$parts[] = array( 's' => $text );
				$toks[]  = array( 't' => 'tpl', 'v' => $parts, 'p' => $start );
				$i       = $j + 1;
				$last    = 'str';
				continue;
			}
			// Regex literal, where a value is expected.
			if ( $ch === '/' && $this->regex_allowed( $last ) ) {
				$j        = $i + 1;
				$in_class = false;
				while ( $j < $len ) {
					$c = $src[ $j ];
					if ( $c === '\\' ) {
						$j += 2;
						continue;
					}
					if ( $c === '[' ) {
						$in_class = true;
					} elseif ( $c === ']' ) {
						$in_class = false;
					} elseif ( $c === '/' && ! $in_class ) {
						break;
					} elseif ( $c === "\n" ) {
						break;
					}
					++$j;
				}
				$pattern = substr( $src, $i + 1, $j - $i - 1 );
				$k       = $j + 1;
				while ( $k < $len && ctype_alpha( $src[ $k ] ) ) {
					++$k;
				}
				$toks[] = array( 't' => 'regex', 'v' => array( $pattern, substr( $src, $j + 1, $k - $j - 1 ) ), 'p' => $start );
				$i      = $k;
				$last   = 'str';
				continue;
			}
			// Punctuators, longest first.
			$matched = '';
			foreach ( array( '>>>=', '...', '===', '!==', '**=', '<<=', '>>=', '=>', '?.', '??', '==', '!=', '<=', '>=', '&&', '||', '++', '--', '+=', '-=', '*=', '/=', '%=', '**' ) as $p ) {
				if ( substr( $src, $i, strlen( $p ) ) === $p ) {
					$matched = $p;
					break;
				}
			}
			if ( $matched === '' ) {
				$matched = $ch;
			}
			// `?.` followed by a digit is a ternary with a decimal, not optional chaining.
			if ( $matched === '?.' && $i + 2 < $len && ctype_digit( $src[ $i + 2 ] ) ) {
				$matched = '?';
			}
			$toks[] = array( 't' => 'punct', 'v' => $matched, 'p' => $start );
			$i     += strlen( $matched );
			$last   = in_array( $matched, array( ')', ']', '}' ), true ) ? 'close' : 'punct';
		}
		$toks[] = array( 't' => 'eof', 'v' => '', 'p' => $len );

		return $toks;
	}

	private function regex_allowed( ?string $last ): bool {
		// After a value a slash divides; after an operator, an opening bracket
		// or the start of input it begins a regex. `}` counts as a value end
		// here, which is wrong for `} /re/` after a block — not written in
		// any of these designs.
		return $last === null || $last === 'punct';
	}

	private function unescape( string $src, int &$j ): string {
		$next = $src[ $j + 1 ];
		$j   += 2;
		switch ( $next ) {
			case 'n':
				return "\n";
			case 't':
				return "\t";
			case 'r':
				return "\r";
			case '0':
				return "\0";
			case 'u':
				if ( isset( $src[ $j ] ) && $src[ $j ] === '{' ) {
					$close = strpos( $src, '}', $j );
					$hex   = substr( $src, $j + 1, $close - $j - 1 );
					$j     = $close + 1;
				} else {
					$hex = substr( $src, $j, 4 );
					$j  += 4;
				}
				return mb_chr( (int) hexdec( $hex ), 'UTF-8' ) ?: '';
			case 'x':
				$hex = substr( $src, $j, 2 );
				$j  += 2;
				return mb_chr( (int) hexdec( $hex ), 'UTF-8' ) ?: '';
			default:
				return $next;
		}
	}

	private function skip_string( string $src, int $at ): int {
		$q = $src[ $at ];
		$j = $at + 1;
		$n = strlen( $src );
		while ( $j < $n && $src[ $j ] !== $q ) {
			if ( $src[ $j ] === '\\' ) {
				++$j;
			}
			++$j;
		}

		return $j + 1;
	}

	/* ----------------------------------------------------------- parser */

	/** @return array{t: string, v: mixed, p: int} */
	private function peek( int $ahead = 0 ): array {
		return $this->toks[ min( $this->i + $ahead, count( $this->toks ) - 1 ) ];
	}

	/** @return array{t: string, v: mixed, p: int} */
	private function next(): array {
		$tok = $this->peek();
		if ( $this->i < count( $this->toks ) - 1 ) {
			++$this->i;
		}

		return $tok;
	}

	private function is_punct( string $v, int $ahead = 0 ): bool {
		$tok = $this->peek( $ahead );

		return $tok['t'] === 'punct' && $tok['v'] === $v;
	}

	private function is_id( string $v, int $ahead = 0 ): bool {
		$tok = $this->peek( $ahead );

		return $tok['t'] === 'id' && $tok['v'] === $v;
	}

	private function expect( string $v ): void {
		if ( ! $this->is_punct( $v ) ) {
			$tok = $this->peek();
			$this->note( 'expected ' . $v . ' at ' . $tok['p'] . ' got ' . self::to_string( $tok['v'] ) );
			return;
		}
		$this->next();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function parse_statement(): ?array {
		$tok = $this->peek();
		if ( $tok['t'] === 'punct' && $tok['v'] === ';' ) {
			$this->next();
			return null;
		}
		if ( $tok['t'] === 'punct' && $tok['v'] === '{' ) {
			return array( 'k' => 'block', 'b' => $this->parse_block_statements() );
		}
		if ( $tok['t'] === 'id' ) {
			switch ( $tok['v'] ) {
				case 'const':
				case 'let':
				case 'var':
					$this->next();
					$decls = array();
					do {
						$target = $this->parse_binding_target();
						$init   = null;
						if ( $this->is_punct( '=' ) ) {
							$this->next();
							$init = $this->parse_expression();
						}
						$decls[] = array( 'target' => $target, 'init' => $init );
						if ( $this->is_punct( ',' ) ) {
							$this->next();
							continue;
						}
						break;
					} while ( true );
					$this->skip_semicolon();
					return array( 'k' => 'var', 'decl' => $decls );
				case 'return':
					$this->next();
					$e = null;
					if ( ! $this->is_punct( ';' ) && ! $this->is_punct( '}' ) && $this->peek()['t'] !== 'eof' ) {
						$e = $this->parse_expression();
					}
					$this->skip_semicolon();
					return array( 'k' => 'return', 'e' => $e );
				case 'if':
					$this->next();
					$this->expect( '(' );
					$c = $this->parse_expression();
					$this->expect( ')' );
					$t = $this->parse_statement();
					$f = null;
					if ( $this->is_id( 'else' ) ) {
						$this->next();
						$f = $this->parse_statement();
					}
					return array( 'k' => 'if', 'c' => $c, 't' => $t, 'f' => $f );
				case 'function':
					$this->next();
					$name = $this->peek()['t'] === 'id' ? (string) $this->next()['v'] : '';
					$params = $this->parse_params();
					$body   = $this->parse_block_statements();
					$fn     = array( 'k' => 'fn', 'params' => $params, 'body' => $body, 'expr' => false, 'src' => $name );
					return $name !== ''
						? array( 'k' => 'var', 'decl' => array( array( 'target' => array( 'k' => 'id', 'n' => $name ), 'init' => $fn ) ) )
						: array( 'k' => 'expr', 'e' => $fn );
				case 'for':
				case 'while':
				case 'do':
				case 'switch':
				case 'try':
				case 'throw':
				case 'class':
					// Not modelled: skip the whole construct.
					$this->note( 'statement ' . $tok['v'] );
					$this->skip_construct();
					return null;
			}
		}
		$e = $this->parse_expression();
		$this->skip_semicolon();

		return array( 'k' => 'expr', 'e' => $e );
	}

	private function skip_semicolon(): void {
		if ( $this->is_punct( ';' ) ) {
			$this->next();
		}
	}

	/** Skip a statement we do not model, by balanced brackets up to `;` or a block. */
	private function skip_construct(): void {
		$this->next();
		$depth = 0;
		while ( $this->peek()['t'] !== 'eof' ) {
			$tok = $this->next();
			if ( $tok['t'] !== 'punct' ) {
				continue;
			}
			if ( in_array( $tok['v'], array( '(', '[', '{' ), true ) ) {
				++$depth;
			} elseif ( in_array( $tok['v'], array( ')', ']', '}' ), true ) ) {
				--$depth;
				if ( $depth <= 0 && $tok['v'] === '}' ) {
					// `} else {`, `} catch {`, `} while (` continue the construct.
					if ( $this->is_id( 'else' ) || $this->is_id( 'catch' ) || $this->is_id( 'finally' ) || $this->is_id( 'while' ) ) {
						continue;
					}
					return;
				}
			} elseif ( $tok['v'] === ';' && $depth <= 0 ) {
				return;
			}
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function parse_block_statements(): array {
		$this->expect( '{' );
		$out = array();
		while ( ! $this->is_punct( '}' ) && $this->peek()['t'] !== 'eof' ) {
			$before = $this->i;
			$stmt   = $this->parse_statement();
			if ( $stmt !== null ) {
				$out[] = $stmt;
			}
			if ( $this->i === $before ) {
				$this->next();
			}
		}
		$this->expect( '}' );

		return $out;
	}

	/**
	 * An identifier or a destructuring pattern.
	 *
	 * @return array<string, mixed>
	 */
	private function parse_binding_target(): array {
		if ( $this->is_punct( '{' ) ) {
			$this->next();
			$props = array();
			while ( ! $this->is_punct( '}' ) && $this->peek()['t'] !== 'eof' ) {
				if ( $this->is_punct( '...' ) ) {
					$this->next();
					$props[] = array( 'rest' => (string) $this->next()['v'] );
				} else {
					$key   = (string) $this->next()['v'];
					$alias = $key;
					$def   = null;
					if ( $this->is_punct( ':' ) ) {
						$this->next();
						$alias = (string) $this->next()['v'];
					}
					if ( $this->is_punct( '=' ) ) {
						$this->next();
						$def = $this->parse_assignment();
					}
					$props[] = array( 'key' => $key, 'alias' => $alias, 'default' => $def );
				}
				if ( $this->is_punct( ',' ) ) {
					$this->next();
				}
			}
			$this->expect( '}' );
			return array( 'k' => 'objpat', 'props' => $props );
		}
		if ( $this->is_punct( '[' ) ) {
			$this->next();
			$items = array();
			while ( ! $this->is_punct( ']' ) && $this->peek()['t'] !== 'eof' ) {
				if ( $this->is_punct( ',' ) ) {
					$this->next();
					$items[] = null;
					continue;
				}
				if ( $this->is_punct( '...' ) ) {
					$this->next();
					$items[] = array( 'rest' => (string) $this->next()['v'] );
				} else {
					$name = (string) $this->next()['v'];
					$def  = null;
					if ( $this->is_punct( '=' ) ) {
						$this->next();
						$def = $this->parse_assignment();
					}
					$items[] = array( 'name' => $name, 'default' => $def );
				}
				if ( $this->is_punct( ',' ) ) {
					$this->next();
				}
			}
			$this->expect( ']' );
			return array( 'k' => 'arrpat', 'items' => $items );
		}

		return array( 'k' => 'id', 'n' => (string) $this->next()['v'] );
	}

	/**
	 * `( a, b = 1, ...rest )` → parameter list. Cursor on `(`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function parse_params(): array {
		$this->expect( '(' );
		$params = array();
		while ( ! $this->is_punct( ')' ) && $this->peek()['t'] !== 'eof' ) {
			$rest = false;
			if ( $this->is_punct( '...' ) ) {
				$this->next();
				$rest = true;
			}
			$target = $this->parse_binding_target();
			$def    = null;
			if ( $this->is_punct( '=' ) ) {
				$this->next();
				$def = $this->parse_assignment();
			}
			$params[] = array( 'target' => $target, 'default' => $def, 'rest' => $rest );
			if ( $this->is_punct( ',' ) ) {
				$this->next();
			}
		}
		$this->expect( ')' );

		return $params;
	}

	/** @return array<string, mixed> */
	private function parse_expression(): array {
		$left = $this->parse_assignment();
		// The comma operator: evaluate all, keep the last.
		while ( $this->is_punct( ',' ) && $this->comma_is_sequence() ) {
			$this->next();
			$right = $this->parse_assignment();
			$left  = array( 'k' => 'seq', 'l' => $left, 'r' => $right );
		}

		return $left;
	}

	/** Inside argument lists and literals a comma separates; parse_expression is only
	 * reached there through parse_assignment, so a top-level comma here is a sequence. */
	private function comma_is_sequence(): bool {
		return false;
	}

	/** @return array<string, mixed> */
	private function parse_assignment(): array {
		// Arrow function?
		$arrow = $this->try_parse_arrow();
		if ( $arrow !== null ) {
			return $arrow;
		}
		$left = $this->parse_conditional();
		$tok  = $this->peek();
		if ( $tok['t'] === 'punct' && in_array( $tok['v'], array( '=', '+=', '-=', '*=', '/=', '%=' ), true ) ) {
			$this->next();
			$right = $this->parse_assignment();
			return array( 'k' => 'assign', 'op' => $tok['v'], 't' => $left, 'v' => $right );
		}

		return $left;
	}

	/** @return array<string, mixed>|null */
	private function try_parse_arrow(): ?array {
		$tok = $this->peek();
		$start_i = $this->i;
		$start_p = $tok['p'];
		if ( $tok['t'] === 'id' && $tok['v'] === 'async' && ( $this->peek( 1 )['t'] === 'id' || $this->is_punct( '(', 1 ) ) ) {
			$this->next();
			$tok = $this->peek();
		}
		if ( $tok['t'] === 'id' && $this->is_punct( '=>', 1 ) && ! in_array( $tok['v'], array( 'true', 'false', 'null' ), true ) ) {
			$this->next();
			$this->next();
			$params = array( array( 'target' => array( 'k' => 'id', 'n' => (string) $tok['v'] ), 'default' => null, 'rest' => false ) );
			return $this->parse_arrow_body( $params, $start_p );
		}
		if ( $tok['t'] === 'punct' && $tok['v'] === '(' ) {
			// Look for the matching `)` followed by `=>`.
			$depth = 0;
			$j     = $this->i;
			$n     = count( $this->toks );
			while ( $j < $n ) {
				$t = $this->toks[ $j ];
				if ( $t['t'] === 'punct' ) {
					if ( in_array( $t['v'], array( '(', '[', '{' ), true ) ) {
						++$depth;
					} elseif ( in_array( $t['v'], array( ')', ']', '}' ), true ) ) {
						--$depth;
						if ( $depth === 0 ) {
							break;
						}
					}
				}
				if ( $t['t'] === 'eof' ) {
					break;
				}
				++$j;
			}
			if ( $j + 1 < $n && $this->toks[ $j + 1 ]['t'] === 'punct' && $this->toks[ $j + 1 ]['v'] === '=>' ) {
				$params = $this->parse_params();
				$this->expect( '=>' );
				return $this->parse_arrow_body( $params, $start_p );
			}
		}
		$this->i = $start_i;

		return null;
	}

	/**
	 * @param array<int, array<string, mixed>> $params
	 * @return array<string, mixed>
	 */
	private function parse_arrow_body( array $params, int $start_p ): array {
		if ( $this->is_punct( '{' ) ) {
			$body = $this->parse_block_statements();
			$end  = $this->toks[ $this->i - 1 ]['p'];
			return array( 'k' => 'fn', 'params' => $params, 'body' => $body, 'expr' => false, 'src' => substr( $this->source, $start_p, $end - $start_p + 1 ) );
		}
		$body = $this->parse_assignment();
		$end  = $this->i > 0 ? $this->toks[ $this->i - 1 ]['p'] + 1 : $start_p;

		return array( 'k' => 'fn', 'params' => $params, 'body' => $body, 'expr' => true, 'src' => substr( $this->source, $start_p, max( 0, $end - $start_p ) ) );
	}

	/** @return array<string, mixed> */
	private function parse_conditional(): array {
		$c = $this->parse_nullish();
		if ( $this->is_punct( '?' ) ) {
			$this->next();
			$t = $this->parse_assignment();
			$this->expect( ':' );
			$f = $this->parse_assignment();
			return array( 'k' => 'cond', 'c' => $c, 't' => $t, 'f' => $f );
		}

		return $c;
	}

	/** @return array<string, mixed> */
	private function parse_nullish(): array {
		$left = $this->parse_or();
		while ( $this->is_punct( '??' ) ) {
			$this->next();
			$left = array( 'k' => 'log', 'op' => '??', 'l' => $left, 'r' => $this->parse_or() );
		}

		return $left;
	}

	/** @return array<string, mixed> */
	private function parse_or(): array {
		$left = $this->parse_and();
		while ( $this->is_punct( '||' ) ) {
			$this->next();
			$left = array( 'k' => 'log', 'op' => '||', 'l' => $left, 'r' => $this->parse_and() );
		}

		return $left;
	}

	/** @return array<string, mixed> */
	private function parse_and(): array {
		$left = $this->parse_equality();
		while ( $this->is_punct( '&&' ) ) {
			$this->next();
			$left = array( 'k' => 'log', 'op' => '&&', 'l' => $left, 'r' => $this->parse_equality() );
		}

		return $left;
	}

	/** @return array<string, mixed> */
	private function parse_equality(): array {
		$left = $this->parse_relational();
		while ( true ) {
			$tok = $this->peek();
			if ( $tok['t'] === 'punct' && in_array( $tok['v'], array( '===', '!==', '==', '!=' ), true ) ) {
				$this->next();
				$left = array( 'k' => 'bin', 'op' => $tok['v'], 'l' => $left, 'r' => $this->parse_relational() );
				continue;
			}
			break;
		}

		return $left;
	}

	/** @return array<string, mixed> */
	private function parse_relational(): array {
		$left = $this->parse_additive();
		while ( true ) {
			$tok = $this->peek();
			if ( $tok['t'] === 'punct' && in_array( $tok['v'], array( '<', '>', '<=', '>=' ), true ) ) {
				$this->next();
				$left = array( 'k' => 'bin', 'op' => $tok['v'], 'l' => $left, 'r' => $this->parse_additive() );
				continue;
			}
			if ( $tok['t'] === 'id' && ( $tok['v'] === 'in' || $tok['v'] === 'instanceof' ) ) {
				$this->next();
				$left = array( 'k' => 'bin', 'op' => $tok['v'], 'l' => $left, 'r' => $this->parse_additive() );
				continue;
			}
			break;
		}

		return $left;
	}

	/** @return array<string, mixed> */
	private function parse_additive(): array {
		$left = $this->parse_multiplicative();
		while ( true ) {
			$tok = $this->peek();
			if ( $tok['t'] === 'punct' && ( $tok['v'] === '+' || $tok['v'] === '-' ) ) {
				$this->next();
				$left = array( 'k' => 'bin', 'op' => $tok['v'], 'l' => $left, 'r' => $this->parse_multiplicative() );
				continue;
			}
			break;
		}

		return $left;
	}

	/** @return array<string, mixed> */
	private function parse_multiplicative(): array {
		$left = $this->parse_unary();
		while ( true ) {
			$tok = $this->peek();
			if ( $tok['t'] === 'punct' && in_array( $tok['v'], array( '*', '/', '%', '**' ), true ) ) {
				$this->next();
				$left = array( 'k' => 'bin', 'op' => $tok['v'], 'l' => $left, 'r' => $this->parse_unary() );
				continue;
			}
			break;
		}

		return $left;
	}

	/** @return array<string, mixed> */
	private function parse_unary(): array {
		$tok = $this->peek();
		if ( $tok['t'] === 'punct' && in_array( $tok['v'], array( '!', '-', '+', '++', '--' ), true ) ) {
			$this->next();
			return array( 'k' => 'un', 'op' => $tok['v'], 'e' => $this->parse_unary() );
		}
		if ( $tok['t'] === 'id' && in_array( $tok['v'], array( 'typeof', 'void', 'delete', 'await' ), true ) ) {
			$this->next();
			return array( 'k' => 'un', 'op' => $tok['v'], 'e' => $this->parse_unary() );
		}
		$e = $this->parse_postfix();
		$tok = $this->peek();
		if ( $tok['t'] === 'punct' && ( $tok['v'] === '++' || $tok['v'] === '--' ) ) {
			$this->next();
			return array( 'k' => 'postfix', 'op' => $tok['v'], 'e' => $e );
		}

		return $e;
	}

	/** @return array<string, mixed> */
	private function parse_postfix(): array {
		$e = $this->parse_primary();
		while ( true ) {
			$tok = $this->peek();
			if ( $tok['t'] !== 'punct' ) {
				break;
			}
			if ( $tok['v'] === '.' ) {
				$this->next();
				$name = $this->next();
				$e    = array( 'k' => 'mem', 'o' => $e, 'p' => (string) $name['v'], 'opt' => false );
				continue;
			}
			if ( $tok['v'] === '?.' ) {
				$this->next();
				if ( $this->is_punct( '(' ) ) {
					$e = array( 'k' => 'call', 'f' => $e, 'args' => $this->parse_arguments(), 'opt' => true );
					continue;
				}
				if ( $this->is_punct( '[' ) ) {
					$this->next();
					$idx = $this->parse_expression();
					$this->expect( ']' );
					$e = array( 'k' => 'idx', 'o' => $e, 'i' => $idx, 'opt' => true );
					continue;
				}
				$name = $this->next();
				$e    = array( 'k' => 'mem', 'o' => $e, 'p' => (string) $name['v'], 'opt' => true );
				continue;
			}
			if ( $tok['v'] === '[' ) {
				$this->next();
				$idx = $this->parse_expression();
				$this->expect( ']' );
				$e = array( 'k' => 'idx', 'o' => $e, 'i' => $idx, 'opt' => false );
				continue;
			}
			if ( $tok['v'] === '(' ) {
				$e = array( 'k' => 'call', 'f' => $e, 'args' => $this->parse_arguments(), 'opt' => false );
				continue;
			}
			break;
		}

		return $e;
	}

	/** @return array<int, array<string, mixed>> */
	private function parse_arguments(): array {
		$this->expect( '(' );
		$args = array();
		while ( ! $this->is_punct( ')' ) && $this->peek()['t'] !== 'eof' ) {
			if ( $this->is_punct( '...' ) ) {
				$this->next();
				$args[] = array( 'k' => 'spread', 'e' => $this->parse_assignment() );
			} else {
				$args[] = $this->parse_assignment();
			}
			if ( $this->is_punct( ',' ) ) {
				$this->next();
			}
		}
		$this->expect( ')' );

		return $args;
	}

	/** @return array<string, mixed> */
	private function parse_primary(): array {
		$tok = $this->next();
		switch ( $tok['t'] ) {
			case 'num':
				return array( 'k' => 'num', 'v' => $tok['v'] );
			case 'str':
				return array( 'k' => 'str', 'v' => $tok['v'] );
			case 'regex':
				return array( 'k' => 'regex', 'src' => $tok['v'][0], 'flags' => $tok['v'][1] );
			case 'tpl':
				$parts = array();
				foreach ( $tok['v'] as $part ) {
					if ( isset( $part['e'] ) ) {
						$sub     = new self();
						$parts[] = array( 'e' => $sub->parse_expression_source( (string) $part['e'] ) );
						$this->unevaluated = array_merge( $this->unevaluated, $sub->unevaluated );
					} else {
						$parts[] = array( 's' => (string) $part['s'] );
					}
				}
				return array( 'k' => 'tpl', 'parts' => $parts );
			case 'id':
				switch ( $tok['v'] ) {
					case 'true':
						return array( 'k' => 'lit', 'v' => true );
					case 'false':
						return array( 'k' => 'lit', 'v' => false );
					case 'null':
					case 'undefined':
						return array( 'k' => 'lit', 'v' => null );
					case 'NaN':
						return array( 'k' => 'lit', 'v' => NAN );
					case 'Infinity':
						return array( 'k' => 'lit', 'v' => INF );
					case 'this':
						return array( 'k' => 'this' );
					case 'new':
						$callee = $this->parse_postfix_no_call();
						$args   = $this->is_punct( '(' ) ? $this->parse_arguments() : array();
						return array( 'k' => 'new', 'f' => $callee, 'args' => $args );
					case 'function':
						$name = $this->peek()['t'] === 'id' ? (string) $this->next()['v'] : '';
						$params = $this->parse_params();
						$body   = $this->parse_block_statements();
						return array( 'k' => 'fn', 'params' => $params, 'body' => $body, 'expr' => false, 'src' => $name );
				}
				return array( 'k' => 'id', 'n' => (string) $tok['v'] );
			case 'punct':
				if ( $tok['v'] === '(' ) {
					$e = $this->parse_expression();
					$this->expect( ')' );
					return $e;
				}
				if ( $tok['v'] === '[' ) {
					$items = array();
					while ( ! $this->is_punct( ']' ) && $this->peek()['t'] !== 'eof' ) {
						if ( $this->is_punct( ',' ) ) {
							$this->next();
							$items[] = array( 'k' => 'lit', 'v' => null );
							continue;
						}
						if ( $this->is_punct( '...' ) ) {
							$this->next();
							$items[] = array( 'k' => 'spread', 'e' => $this->parse_assignment() );
						} else {
							$items[] = $this->parse_assignment();
						}
						if ( $this->is_punct( ',' ) ) {
							$this->next();
						}
					}
					$this->expect( ']' );
					return array( 'k' => 'arr', 'items' => $items );
				}
				if ( $tok['v'] === '{' ) {
					return $this->parse_object_rest();
				}
				break;
		}
		$this->note( 'unexpected token ' . self::to_string( $tok['v'] ) . ' at ' . $tok['p'] );

		return array( 'k' => 'lit', 'v' => null );
	}

	/** A `new` callee: member chain without a call. */
	private function parse_postfix_no_call(): array {
		$e = array( 'k' => 'id', 'n' => (string) $this->next()['v'] );
		while ( $this->is_punct( '.' ) ) {
			$this->next();
			$e = array( 'k' => 'mem', 'o' => $e, 'p' => (string) $this->next()['v'], 'opt' => false );
		}

		return $e;
	}

	/** Object literal; cursor just after `{`. */
	private function parse_object_rest(): array {
		$props = array();
		while ( ! $this->is_punct( '}' ) && $this->peek()['t'] !== 'eof' ) {
			if ( $this->is_punct( '...' ) ) {
				$this->next();
				$props[] = array( 'spread' => $this->parse_assignment() );
			} else {
				$computed = false;
				$tok      = $this->next();
				if ( $tok['t'] === 'punct' && $tok['v'] === '[' ) {
					$key = $this->parse_assignment();
					$this->expect( ']' );
					$computed = true;
				} elseif ( $tok['t'] === 'num' ) {
					$key = self::to_string( $tok['v'] );
				} else {
					$key = (string) $tok['v'];
				}
				if ( $this->is_punct( '(' ) ) {
					// Method shorthand `name() { … }`.
					$params  = $this->parse_params();
					$body    = $this->parse_block_statements();
					$props[] = array( 'key' => $key, 'computed' => $computed, 'v' => array( 'k' => 'fn', 'params' => $params, 'body' => $body, 'expr' => false, 'src' => (string) ( is_string( $key ) ? $key : '' ) ) );
				} elseif ( $this->is_punct( ':' ) ) {
					$this->next();
					$props[] = array( 'key' => $key, 'computed' => $computed, 'v' => $this->parse_assignment() );
				} else {
					// Shorthand `{ a, b }`.
					$props[] = array( 'key' => $key, 'computed' => false, 'v' => array( 'k' => 'id', 'n' => (string) $key ) );
				}
			}
			if ( $this->is_punct( ',' ) ) {
				$this->next();
			}
		}
		$this->expect( '}' );

		return array( 'k' => 'obj', 'props' => $props );
	}

	/* -------------------------------------------------------- evaluator */

	private function note( string $what ): void {
		$this->unevaluated[] = $what;
	}

	/**
	 * @param array<string, mixed> $node
	 * @param array<string, mixed> $scope
	 */
	private function ev( array $node, array &$scope, ?Dc_Js_Instance $this_ref ): mixed {
		switch ( $node['k'] ) {
			case 'num':
			case 'str':
			case 'lit':
				return $node['v'];
			case 'regex':
				return new Dc_Js_Regex( (string) $node['src'], (string) $node['flags'] );
			case 'tpl':
				$out = '';
				foreach ( $node['parts'] as $part ) {
					if ( isset( $part['e'] ) ) {
						$v    = $this->ev( $part['e'], $scope, $this_ref );
						$out .= $v === null ? '' : self::to_string( $v );
					} else {
						$out .= $part['s'];
					}
				}
				return $out;
			case 'this':
				return $this_ref;
			case 'id':
				return $this->lookup( (string) $node['n'], $scope, $this_ref );
			case 'arr':
				$out = array();
				foreach ( $node['items'] as $item ) {
					if ( $item['k'] === 'spread' ) {
						$v = $this->ev( $item['e'], $scope, $this_ref );
						if ( is_array( $v ) ) {
							foreach ( $v as $x ) {
								$out[] = $x;
							}
						} elseif ( is_string( $v ) ) {
							foreach ( mb_str_split( $v ) as $x ) {
								$out[] = $x;
							}
						}
						continue;
					}
					$out[] = $this->ev( $item, $scope, $this_ref );
				}
				return $out;
			case 'obj':
				$out = array();
				foreach ( $node['props'] as $prop ) {
					if ( isset( $prop['spread'] ) ) {
						$v = $this->ev( $prop['spread'], $scope, $this_ref );
						if ( is_array( $v ) ) {
							foreach ( $v as $k => $x ) {
								$out[ $k ] = $x;
							}
						}
						continue;
					}
					$key = $prop['computed'] ? self::to_string( $this->ev( $prop['key'], $scope, $this_ref ) ) : (string) $prop['key'];
					$out[ $key ] = $this->ev( $prop['v'], $scope, $this_ref );
				}
				return $out;
			case 'fn':
				return new Dc_Js_Fn( $node['params'], $node['body'], (bool) $node['expr'], $scope, $this_ref, (string) ( $node['src'] ?? '' ) );
			case 'un':
				return $this->unary( (string) $node['op'], $node['e'], $scope, $this_ref );
			case 'postfix':
				return $this->ev( $node['e'], $scope, $this_ref );
			case 'bin':
				return $this->binary( (string) $node['op'], $this->ev( $node['l'], $scope, $this_ref ), $this->ev( $node['r'], $scope, $this_ref ) );
			case 'log':
				$l = $this->ev( $node['l'], $scope, $this_ref );
				switch ( $node['op'] ) {
					case '&&':
						return self::truthy( $l ) ? $this->ev( $node['r'], $scope, $this_ref ) : $l;
					case '||':
						return self::truthy( $l ) ? $l : $this->ev( $node['r'], $scope, $this_ref );
					default:
						return $l === null ? $this->ev( $node['r'], $scope, $this_ref ) : $l;
				}
			case 'cond':
				return self::truthy( $this->ev( $node['c'], $scope, $this_ref ) )
					? $this->ev( $node['t'], $scope, $this_ref )
					: $this->ev( $node['f'], $scope, $this_ref );
			case 'seq':
				$this->ev( $node['l'], $scope, $this_ref );
				return $this->ev( $node['r'], $scope, $this_ref );
			case 'mem':
				$obj = $this->ev( $node['o'], $scope, $this_ref );
				if ( $obj === null && $node['opt'] ) {
					return null;
				}
				return $this->member( $obj, (string) $node['p'] );
			case 'idx':
				$obj = $this->ev( $node['o'], $scope, $this_ref );
				if ( $obj === null && $node['opt'] ) {
					return null;
				}
				$key = $this->ev( $node['i'], $scope, $this_ref );
				return $this->member( $obj, self::to_string( $key ), true );
			case 'call':
				return $this->call_node( $node, $scope, $this_ref );
			case 'new':
				$this->note( 'new ' . $this->callee_name( $node['f'] ) );
				return null;
			case 'assign':
				return $this->assign( $node, $scope, $this_ref );
			case 'spread':
				return $this->ev( $node['e'], $scope, $this_ref );
		}
		$this->note( 'node ' . (string) $node['k'] );

		return null;
	}

	private function lookup( string $name, array $scope, ?Dc_Js_Instance $this_ref ): mixed {
		if ( array_key_exists( $name, $scope ) ) {
			return $scope[ $name ];
		}
		switch ( $name ) {
			case 'Math':
			case 'React':
			case 'Array':
			case 'Object':
			case 'JSON':
			case 'Number':
			case 'window':
			case 'document':
			case 'console':
			case 'globalThis':
			case 'navigator':
			case 'location':
			case 'Date':
			case 'Intl':
				return array( '__host' => $name );
			case 'String':
			case 'Boolean':
			case 'parseInt':
			case 'parseFloat':
			case 'isNaN':
			case 'isFinite':
			case 'encodeURIComponent':
			case 'decodeURIComponent':
			case 'encodeURI':
				return array( '__host' => $name );
		}
		$this->note( 'unbound ' . $name );

		return null;
	}

	private function unary( string $op, array $e, array &$scope, ?Dc_Js_Instance $this_ref ): mixed {
		if ( $op === 'typeof' ) {
			$v = $this->ev( $e, $scope, $this_ref );
			if ( $v === null ) {
				return $e['k'] === 'id' && ! array_key_exists( $e['n'], $scope ) ? 'undefined' : 'object';
			}
			if ( is_bool( $v ) ) {
				return 'boolean';
			}
			if ( is_int( $v ) || is_float( $v ) ) {
				return 'number';
			}
			if ( is_string( $v ) ) {
				return 'string';
			}
			if ( $v instanceof Dc_Js_Fn ) {
				return 'function';
			}
			return 'object';
		}
		$v = $this->ev( $e, $scope, $this_ref );
		switch ( $op ) {
			case '!':
				return ! self::truthy( $v );
			case '-':
				$n = self::to_number( $v );
				return is_int( $n ) ? -$n : -1.0 * $n;
			case '+':
				return self::to_number( $v );
			case '++':
				return self::to_number( $v ) + 1;
			case '--':
				return self::to_number( $v ) - 1;
			case 'void':
				return null;
		}

		return $v;
	}

	private function binary( string $op, mixed $l, mixed $r ): mixed {
		switch ( $op ) {
			case '+':
				if ( is_string( $l ) || is_string( $r ) || $l instanceof Dc_Js_Element || $r instanceof Dc_Js_Element || ( is_array( $l ) ) || ( is_array( $r ) ) ) {
					return self::to_string( $l ) . self::to_string( $r );
				}
				return self::arith( self::to_number( $l ) + self::to_number( $r ) );
			case '-':
				return self::arith( self::to_number( $l ) - self::to_number( $r ) );
			case '*':
				return self::arith( self::to_number( $l ) * self::to_number( $r ) );
			case '/':
				$d = self::to_number( $r );
				if ( $d == 0 ) { // phpcs:ignore Universal.Operators.StrictComparisons
					$n = self::to_number( $l );
					return $n == 0 ? NAN : ( $n > 0 ? INF : -INF ); // phpcs:ignore Universal.Operators.StrictComparisons
				}
				return self::arith( self::to_number( $l ) / $d );
			case '%':
				$d = self::to_number( $r );
				return $d == 0 ? NAN : self::arith( fmod( (float) self::to_number( $l ), (float) $d ) ); // phpcs:ignore Universal.Operators.StrictComparisons
			case '**':
				return self::arith( pow( self::to_number( $l ), self::to_number( $r ) ) );
			case '===':
				return self::strict_equal( $l, $r );
			case '!==':
				return ! self::strict_equal( $l, $r );
			case '==':
				return self::loose_equal( $l, $r );
			case '!=':
				return ! self::loose_equal( $l, $r );
			case '<':
			case '>':
			case '<=':
			case '>=':
				if ( is_string( $l ) && is_string( $r ) ) {
					$c = strcmp( $l, $r );
				} else {
					$a = self::to_number( $l );
					$b = self::to_number( $r );
					if ( ( is_float( $a ) && is_nan( $a ) ) || ( is_float( $b ) && is_nan( $b ) ) ) {
						return false;
					}
					$c = $a <=> $b;
				}
				return match ( $op ) {
					'<'  => $c < 0,
					'>'  => $c > 0,
					'<=' => $c <= 0,
					default => $c >= 0,
				};
			case 'in':
				return is_array( $r ) && array_key_exists( self::to_string( $l ), $r );
			case 'instanceof':
				return false;
		}
		$this->note( 'operator ' . $op );

		return null;
	}

	/** Keep integers integral where JavaScript would print them without a decimal. */
	private static function arith( int|float $n ): int|float {
		if ( is_float( $n ) && ! is_nan( $n ) && ! is_infinite( $n ) && floor( $n ) === $n && abs( $n ) < PHP_INT_MAX ) {
			return (int) $n;
		}

		return $n;
	}

	private static function strict_equal( mixed $l, mixed $r ): bool {
		if ( ( is_int( $l ) || is_float( $l ) ) && ( is_int( $r ) || is_float( $r ) ) ) {
			return (float) $l === (float) $r;
		}
		if ( is_object( $l ) || is_object( $r ) ) {
			return $l === $r;
		}

		return $l === $r;
	}

	private static function loose_equal( mixed $l, mixed $r ): bool {
		if ( $l === null || $r === null ) {
			return $l === null && $r === null;
		}
		if ( ( is_int( $l ) || is_float( $l ) || is_bool( $l ) ) && is_string( $r ) ) {
			return (float) self::to_number( $l ) === (float) self::to_number( $r );
		}
		if ( is_string( $l ) && ( is_int( $r ) || is_float( $r ) || is_bool( $r ) ) ) {
			return (float) self::to_number( $l ) === (float) self::to_number( $r );
		}

		return self::strict_equal( $l, $r );
	}

	/** Property read on any value. */
	private function member( mixed $obj, string $prop, bool $computed = false ): mixed {
		if ( $obj === null ) {
			return null;
		}
		if ( $obj instanceof Dc_Js_Instance ) {
			if ( $prop === 'state' ) {
				return $obj->state;
			}
			if ( $prop === 'props' ) {
				return $obj->props;
			}
			if ( array_key_exists( $prop, $obj->fields ) ) {
				return $obj->fields[ $prop ];
			}
			if ( isset( $obj->methods[ $prop ] ) ) {
				return $obj->methods[ $prop ];
			}
			if ( $prop === 'setState' || $prop === 'forceUpdate' ) {
				return array( '__host' => 'this.' . $prop, '__this' => $obj );
			}
			return null;
		}
		if ( is_array( $obj ) ) {
			if ( isset( $obj['__host'] ) && count( $obj ) <= 2 ) {
				return $this->host_member( (string) $obj['__host'], $prop );
			}
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
		if ( is_string( $obj ) ) {
			if ( $prop === 'length' ) {
				return mb_strlen( $obj );
			}
			if ( is_numeric( $prop ) ) {
				return mb_substr( $obj, (int) $prop, 1 );
			}
			return null;
		}
		if ( $obj instanceof Dc_Js_Element ) {
			if ( $prop === 'props' ) {
				return $obj->props;
			}
			if ( $prop === 'type' ) {
				return $obj->tag;
			}
			return null;
		}
		if ( $obj instanceof Dc_Js_Regex ) {
			return $prop === 'source' ? $obj->pattern : ( $prop === 'flags' ? $obj->flags : null );
		}

		return null;
	}

	private function host_member( string $host, string $prop ): mixed {
		switch ( $host ) {
			case 'Math':
				return match ( $prop ) {
					'PI'    => M_PI,
					'E'     => M_E,
					default => array( '__host' => 'Math.' . $prop ),
				};
			case 'React':
				if ( $prop === 'Fragment' ) {
					return '';
				}
				return array( '__host' => 'React.' . $prop );
			case 'Number':
				return match ( $prop ) {
					'MAX_SAFE_INTEGER' => PHP_INT_MAX,
					'EPSILON'          => PHP_FLOAT_EPSILON,
					default            => array( '__host' => 'Number.' . $prop ),
				};
			case 'window':
			case 'globalThis':
				return match ( $prop ) {
					'innerWidth'  => $this->viewport_width,
					'innerHeight' => 900,
					'scrollY'     => 0,
					'scrollX'     => 0,
					'devicePixelRatio' => 1,
					'location'    => array( '__host' => 'location' ),
					'navigator'   => array( '__host' => 'navigator' ),
					'document'    => array( '__host' => 'document' ),
					'React'       => array( '__host' => 'React' ),
					default       => array( '__host' => 'window.' . $prop ),
				};
			case 'location':
				return match ( $prop ) {
					'pathname' => '/',
					'href'     => '/',
					'hash'     => '',
					'search'   => '',
					default    => '',
				};
			case 'navigator':
				return $prop === 'language' ? 'en-US' : ( $prop === 'userAgent' ? 'Mozilla/5.0' : null );
			case 'document':
				return match ( $prop ) {
					'documentElement', 'body' => array( '__host' => 'element' ),
					'title'                   => '',
					default                   => array( '__host' => 'document.' . $prop ),
				};
			case 'element':
				return match ( $prop ) {
					'clientWidth', 'offsetWidth' => $this->viewport_width,
					'clientHeight', 'offsetHeight' => 900,
					'scrollTop', 'scrollHeight' => 0,
					default => array( '__host' => 'element.' . $prop ),
				};
			default:
				return array( '__host' => $host . '.' . $prop );
		}
	}

	private function callee_name( array $node ): string {
		if ( $node['k'] === 'id' ) {
			return (string) $node['n'];
		}
		if ( $node['k'] === 'mem' ) {
			return $this->callee_name( $node['o'] ) . '.' . $node['p'];
		}
		if ( $node['k'] === 'this' ) {
			return 'this';
		}

		return $node['k'];
	}

	/**
	 * @param array<string, mixed> $node
	 * @param array<string, mixed> $scope
	 */
	private function call_node( array $node, array &$scope, ?Dc_Js_Instance $this_ref ): mixed {
		$args = array();
		foreach ( $node['args'] as $arg ) {
			if ( $arg['k'] === 'spread' ) {
				$v = $this->ev( $arg['e'], $scope, $this_ref );
				if ( is_array( $v ) ) {
					foreach ( $v as $x ) {
						$args[] = $x;
					}
				}
				continue;
			}
			$args[] = $this->ev( $arg, $scope, $this_ref );
		}
		$callee = $node['f'];
		if ( $callee['k'] === 'mem' ) {
			$obj = $this->ev( $callee['o'], $scope, $this_ref );
			if ( $obj === null ) {
				if ( ! $callee['opt'] && ! $node['opt'] ) {
					$this->note( 'method on null: ' . $this->callee_name( $callee ) );
				}
				return null;
			}
			return $this->call_method( $obj, (string) $callee['p'], $args, $this_ref );
		}
		$fn = $this->ev( $callee, $scope, $this_ref );
		if ( $fn instanceof Dc_Js_Fn ) {
			return $this->invoke( $fn, $args, $fn->this_ref );
		}
		if ( is_array( $fn ) && isset( $fn['__host'] ) ) {
			return $this->call_host( (string) $fn['__host'], $args, $fn );
		}
		if ( $fn === null && $node['opt'] ) {
			return null;
		}
		$this->note( 'call ' . $this->callee_name( $callee ) );

		return null;
	}

	/** @param array<int, mixed> $args */
	private function call_method( mixed $obj, string $method, array $args, ?Dc_Js_Instance $this_ref ): mixed {
		if ( $obj instanceof Dc_Js_Instance ) {
			if ( isset( $obj->methods[ $method ] ) ) {
				return $this->invoke( $obj->methods[ $method ], $args, $obj );
			}
			if ( $method === 'setState' ) {
				$patch = $args[0] ?? null;
				if ( $patch instanceof Dc_Js_Fn ) {
					$patch = $this->invoke( $patch, array( $obj->state ), $obj );
				}
				if ( is_array( $patch ) ) {
					$obj->patches[] = $patch;
				}
				return null;
			}
			if ( $method === 'forceUpdate' ) {
				return null;
			}
			$field = $obj->fields[ $method ] ?? null;
			if ( $field instanceof Dc_Js_Fn ) {
				return $this->invoke( $field, $args, $obj );
			}
			$this->note( 'this.' . $method . '()' );
			return null;
		}
		if ( is_array( $obj ) && isset( $obj['__host'] ) && count( $obj ) <= 2 ) {
			return $this->call_host( $obj['__host'] . '.' . $method, $args, $obj );
		}
		if ( is_string( $obj ) ) {
			return $this->string_method( $obj, $method, $args );
		}
		if ( is_int( $obj ) || is_float( $obj ) ) {
			return $this->number_method( $obj, $method, $args );
		}
		if ( is_bool( $obj ) ) {
			return $method === 'toString' ? self::to_string( $obj ) : null;
		}
		if ( is_array( $obj ) ) {
			if ( array_is_list( $obj ) ) {
				return $this->array_method( $obj, $method, $args, $this_ref );
			}
			$member = $obj[ $method ] ?? null;
			if ( $member instanceof Dc_Js_Fn ) {
				return $this->invoke( $member, $args, $member->this_ref );
			}
			if ( $method === 'hasOwnProperty' ) {
				return array_key_exists( self::to_string( $args[0] ?? '' ), $obj );
			}
			if ( $method === 'toString' ) {
				return '[object Object]';
			}
			$this->note( 'object method ' . $method );
			return null;
		}
		if ( $obj instanceof Dc_Js_Regex ) {
			$subject = self::to_string( $args[0] ?? '' );
			if ( $method === 'test' ) {
				return preg_match( $obj->pcre(), $subject ) === 1;
			}
			if ( $method === 'exec' ) {
				return preg_match( $obj->pcre(), $subject, $m ) === 1 ? array_values( $m ) : null;
			}
		}
		if ( $obj instanceof Dc_Js_Fn ) {
			if ( $method === 'call' ) {
				return $this->invoke( $obj, array_slice( $args, 1 ), $obj->this_ref );
			}
			if ( $method === 'apply' ) {
				return $this->invoke( $obj, is_array( $args[1] ?? null ) ? $args[1] : array(), $obj->this_ref );
			}
			if ( $method === 'bind' ) {
				return $obj;
			}
		}
		$this->note( 'method ' . $method . ' on ' . gettype( $obj ) );

		return null;
	}

	/** @param array<int, mixed> $args */
	private function call_host( string $name, array $args, array $host ): mixed {
		switch ( $name ) {
			case 'this.setState':
				$inst  = $host['__this'] ?? null;
				$patch = $args[0] ?? null;
				if ( $inst instanceof Dc_Js_Instance ) {
					if ( $patch instanceof Dc_Js_Fn ) {
						$patch = $this->invoke( $patch, array( $inst->state ), $inst );
					}
					if ( is_array( $patch ) ) {
						$inst->patches[] = $patch;
					}
				}
				return null;
			case 'Math.min':
				$nums = array_map( array( self::class, 'to_number' ), $args );
				return $nums === array() ? INF : self::arith( min( $nums ) );
			case 'Math.max':
				$nums = array_map( array( self::class, 'to_number' ), $args );
				return $nums === array() ? -INF : self::arith( max( $nums ) );
			case 'Math.round':
				$n = self::to_number( $args[0] ?? 0 );
				return is_float( $n ) && is_nan( $n ) ? NAN : (int) floor( $n + 0.5 );
			case 'Math.floor':
				return (int) floor( self::to_number( $args[0] ?? 0 ) );
			case 'Math.ceil':
				return (int) ceil( self::to_number( $args[0] ?? 0 ) );
			case 'Math.trunc':
				return (int) self::to_number( $args[0] ?? 0 );
			case 'Math.abs':
				return self::arith( abs( self::to_number( $args[0] ?? 0 ) ) );
			case 'Math.sign':
				return self::to_number( $args[0] ?? 0 ) <=> 0;
			case 'Math.pow':
				return self::arith( pow( self::to_number( $args[0] ?? 0 ), self::to_number( $args[1] ?? 0 ) ) );
			case 'Math.sqrt':
				return self::arith( sqrt( self::to_number( $args[0] ?? 0 ) ) );
			case 'Math.random':
				return 0.5;
			case 'Math.hypot':
				return self::arith( sqrt( array_sum( array_map( static fn( $a ) => self::to_number( $a ) ** 2, $args ) ) ) );
			case 'Array.isArray':
				return is_array( $args[0] ?? null ) && array_is_list( $args[0] );
			case 'Array.from':
				$src = $args[0] ?? null;
				$list = is_array( $src ) ? array_values( $src ) : ( is_string( $src ) ? mb_str_split( $src ) : array() );
				if ( is_array( $src ) && isset( $src['length'] ) && count( $src ) === 1 ) {
					$list = array_fill( 0, (int) $src['length'], null );
				}
				if ( isset( $args[1] ) && $args[1] instanceof Dc_Js_Fn ) {
					$out = array();
					foreach ( $list as $i => $x ) {
						$out[] = $this->invoke( $args[1], array( $x, $i ), $args[1]->this_ref );
					}
					return $out;
				}
				return $list;
			case 'Array.of':
				return $args;
			case 'Object.keys':
				return is_array( $args[0] ?? null ) ? array_map( 'strval', array_keys( $args[0] ) ) : array();
			case 'Object.values':
				return is_array( $args[0] ?? null ) ? array_values( $args[0] ) : array();
			case 'Object.entries':
				$out = array();
				foreach ( ( is_array( $args[0] ?? null ) ? $args[0] : array() ) as $k => $v ) {
					$out[] = array( (string) $k, $v );
				}
				return $out;
			case 'Object.assign':
				$out = array();
				foreach ( $args as $a ) {
					if ( is_array( $a ) ) {
						foreach ( $a as $k => $v ) {
							$out[ $k ] = $v;
						}
					}
				}
				return $out;
			case 'Object.fromEntries':
				$out = array();
				foreach ( ( is_array( $args[0] ?? null ) ? $args[0] : array() ) as $pair ) {
					if ( is_array( $pair ) && count( $pair ) >= 2 ) {
						$out[ self::to_string( $pair[0] ) ] = $pair[1];
					}
				}
				return $out;
			case 'JSON.stringify':
				return wp_json_encode( $args[0] ?? null );
			case 'JSON.parse':
				return json_decode( self::to_string( $args[0] ?? '' ), true );
			case 'String':
				return self::to_string( $args[0] ?? '' );
			case 'Number':
				return self::to_number( $args[0] ?? 0 );
			case 'Boolean':
				return self::truthy( $args[0] ?? null );
			case 'parseInt':
			case 'Number.parseInt':
				$text = trim( self::to_string( $args[0] ?? '' ) );
				return preg_match( '/^[-+]?\d+/', $text, $m ) === 1 ? (int) $m[0] : NAN;
			case 'parseFloat':
			case 'Number.parseFloat':
				$text = trim( self::to_string( $args[0] ?? '' ) );
				return preg_match( '/^[-+]?(?:\d+\.?\d*|\.\d+)(?:e[-+]?\d+)?/i', $text, $m ) === 1 ? self::arith( (float) $m[0] ) : NAN;
			case 'isNaN':
			case 'Number.isNaN':
				$n = self::to_number( $args[0] ?? null );
				return is_float( $n ) && is_nan( $n );
			case 'isFinite':
			case 'Number.isFinite':
				$n = self::to_number( $args[0] ?? null );
				return ! ( is_float( $n ) && ( is_nan( $n ) || is_infinite( $n ) ) );
			case 'Number.isInteger':
				$v = $args[0] ?? null;
				return is_int( $v ) || ( is_float( $v ) && floor( $v ) === $v );
			case 'encodeURIComponent':
				return rawurlencode( self::to_string( $args[0] ?? '' ) );
			case 'decodeURIComponent':
			case 'decodeURI':
				return rawurldecode( self::to_string( $args[0] ?? '' ) );
			case 'encodeURI':
				return str_replace( array( '%3A', '%2F', '%3F', '%3D', '%26', '%23' ), array( ':', '/', '?', '=', '&', '#' ), rawurlencode( self::to_string( $args[0] ?? '' ) ) );
			case 'React.createElement':
				return $this->create_element( $args );
			case 'React.isValidElement':
				return ( $args[0] ?? null ) instanceof Dc_Js_Element;
			case 'React.createRef':
			case 'React.useRef':
				return array( 'current' => null );
			case 'window.matchMedia':
			case 'matchMedia':
				return array( 'matches' => false, 'media' => self::to_string( $args[0] ?? '' ) );
			case 'window.requestAnimationFrame':
			case 'window.setTimeout':
			case 'window.setInterval':
			case 'requestAnimationFrame':
			case 'setTimeout':
			case 'setInterval':
				return 1;
			case 'document.querySelector':
			case 'document.getElementById':
				return null;
			case 'document.querySelectorAll':
				return array();
		}
		if ( str_starts_with( $name, 'console.' ) || str_starts_with( $name, 'window.' ) || str_starts_with( $name, 'document.' ) || str_starts_with( $name, 'element.' ) || str_starts_with( $name, 'location.' ) ) {
			return null;
		}
		$this->note( 'host ' . $name . '()' );

		return null;
	}

	/** @param array<int, mixed> $args */
	private function create_element( array $args ): Dc_Js_Element {
		$type     = $args[0] ?? 'div';
		$props    = is_array( $args[1] ?? null ) ? $args[1] : array();
		$children = array_slice( $args, 2 );
		if ( isset( $props['children'] ) && $children === array() ) {
			$children = is_array( $props['children'] ) && array_is_list( $props['children'] ) ? $props['children'] : array( $props['children'] );
		}
		if ( $type instanceof Dc_Js_Fn ) {
			// A function component: render it with its props.
			$props['children'] = $children;
			$out = $this->invoke( $type, array( $props ), $type->this_ref );
			return $out instanceof Dc_Js_Element ? $out : new Dc_Js_Element( '', array(), array( $out ) );
		}

		return new Dc_Js_Element( is_string( $type ) ? $type : '', $props, $children );
	}

	/** @param array<int, mixed> $args */
	private function string_method( string $s, string $method, array $args ): mixed {
		switch ( $method ) {
			case 'toString':
			case 'valueOf':
			case 'toLocaleString':
				return $s;
			case 'trim':
				return trim( $s );
			case 'trimStart':
				return ltrim( $s );
			case 'trimEnd':
				return rtrim( $s );
			case 'toUpperCase':
			case 'toLocaleUpperCase':
				return mb_strtoupper( $s );
			case 'toLowerCase':
			case 'toLocaleLowerCase':
				return mb_strtolower( $s );
			case 'includes':
				return str_contains( $s, self::to_string( $args[0] ?? '' ) );
			case 'startsWith':
				return str_starts_with( $s, self::to_string( $args[0] ?? '' ) );
			case 'endsWith':
				return str_ends_with( $s, self::to_string( $args[0] ?? '' ) );
			case 'indexOf':
				$pos = mb_strpos( $s, self::to_string( $args[0] ?? '' ), (int) self::to_number( $args[1] ?? 0 ) );
				return $pos === false ? -1 : $pos;
			case 'lastIndexOf':
				$pos = mb_strrpos( $s, self::to_string( $args[0] ?? '' ) );
				return $pos === false ? -1 : $pos;
			case 'charAt':
				return mb_substr( $s, (int) self::to_number( $args[0] ?? 0 ), 1 );
			case 'charCodeAt':
				$ch = mb_substr( $s, (int) self::to_number( $args[0] ?? 0 ), 1 );
				return $ch === '' ? NAN : mb_ord( $ch );
			case 'at':
				$i = (int) self::to_number( $args[0] ?? 0 );
				return mb_substr( $s, $i < 0 ? mb_strlen( $s ) + $i : $i, 1 );
			case 'slice':
				$len   = mb_strlen( $s );
				$start = (int) self::to_number( $args[0] ?? 0 );
				$end   = isset( $args[1] ) && $args[1] !== null ? (int) self::to_number( $args[1] ) : $len;
				$start = $start < 0 ? max( 0, $len + $start ) : min( $start, $len );
				$end   = $end < 0 ? max( 0, $len + $end ) : min( $end, $len );
				return $end <= $start ? '' : mb_substr( $s, $start, $end - $start );
			case 'substring':
				$len   = mb_strlen( $s );
				$start = max( 0, min( $len, (int) self::to_number( $args[0] ?? 0 ) ) );
				$end   = isset( $args[1] ) && $args[1] !== null ? max( 0, min( $len, (int) self::to_number( $args[1] ) ) ) : $len;
				if ( $start > $end ) {
					[ $start, $end ] = array( $end, $start );
				}
				return mb_substr( $s, $start, $end - $start );
			case 'substr':
				return mb_substr( $s, (int) self::to_number( $args[0] ?? 0 ), isset( $args[1] ) ? (int) self::to_number( $args[1] ) : null );
			case 'split':
				$sep = $args[0] ?? null;
				if ( $sep === null ) {
					return array( $s );
				}
				if ( $sep instanceof Dc_Js_Regex ) {
					$parts = preg_split( $sep->pcre(), $s );
					return is_array( $parts ) ? $parts : array( $s );
				}
				$sep = self::to_string( $sep );
				$parts = $sep === '' ? mb_str_split( $s ) : explode( $sep, $s );
				if ( isset( $args[1] ) ) {
					$parts = array_slice( $parts, 0, (int) self::to_number( $args[1] ) );
				}
				return $parts;
			case 'replace':
			case 'replaceAll':
				$search = $args[0] ?? '';
				$repl   = $args[1] ?? '';
				if ( $search instanceof Dc_Js_Regex ) {
					$limit = $search->is_global() || $method === 'replaceAll' ? -1 : 1;
					if ( $repl instanceof Dc_Js_Fn ) {
						$fn  = $repl;
						$out = preg_replace_callback( $search->pcre(), fn( array $m ) => self::to_string( $this->invoke( $fn, array_values( $m ), $fn->this_ref ) ), $s, $limit );
					} else {
						$out = preg_replace( $search->pcre(), self::js_replacement( self::to_string( $repl ) ), $s, $limit );
					}
					return is_string( $out ) ? $out : $s;
				}
				$search = self::to_string( $search );
				if ( $repl instanceof Dc_Js_Fn ) {
					$repl = self::to_string( $this->invoke( $repl, array( $search ), $repl->this_ref ) );
				} else {
					$repl = str_replace( '$&', $search, self::to_string( $repl ) );
				}
				if ( $method === 'replaceAll' ) {
					return str_replace( $search, $repl, $s );
				}
				$pos = strpos( $s, $search );
				return $pos === false ? $s : substr_replace( $s, $repl, $pos, strlen( $search ) );
			case 'match':
				$re = $args[0] ?? null;
				if ( ! ( $re instanceof Dc_Js_Regex ) ) {
					$re = new Dc_Js_Regex( preg_quote( self::to_string( $re ), '#' ), '' );
				}
				if ( $re->is_global() ) {
					return preg_match_all( $re->pcre(), $s, $m ) > 0 ? $m[0] : null;
				}
				return preg_match( $re->pcre(), $s, $m ) === 1 ? array_values( $m ) : null;
			case 'padStart':
				return str_pad( $s, (int) self::to_number( $args[0] ?? 0 ), self::to_string( $args[1] ?? ' ' ), STR_PAD_LEFT );
			case 'padEnd':
				return str_pad( $s, (int) self::to_number( $args[0] ?? 0 ), self::to_string( $args[1] ?? ' ' ), STR_PAD_RIGHT );
			case 'repeat':
				return str_repeat( $s, max( 0, (int) self::to_number( $args[0] ?? 0 ) ) );
			case 'concat':
				return $s . implode( '', array_map( array( self::class, 'to_string' ), $args ) );
			case 'localeCompare':
				return strcmp( $s, self::to_string( $args[0] ?? '' ) ) <=> 0;
			case 'normalize':
				return $s;
		}
		$this->note( 'string.' . $method );

		return null;
	}

	/** JavaScript `$1` back-references are PCRE `$1` too; `$&` is `$0`. */
	private static function js_replacement( string $repl ): string {
		return str_replace( '$&', '$0', $repl );
	}

	/** @param array<int, mixed> $args */
	private function number_method( int|float $n, string $method, array $args ): mixed {
		switch ( $method ) {
			case 'toString':
				return self::to_string( $n );
			case 'toFixed':
				return number_format( (float) $n, (int) self::to_number( $args[0] ?? 0 ), '.', '' );
			case 'toLocaleString':
				$locale = self::to_string( $args[0] ?? 'en-US' );
				$opts   = is_array( $args[1] ?? null ) ? $args[1] : array();
				$min    = isset( $opts['minimumFractionDigits'] ) ? (int) $opts['minimumFractionDigits'] : null;
				$max    = isset( $opts['maximumFractionDigits'] ) ? (int) $opts['maximumFractionDigits'] : 3;
				$dec    = is_int( $n ) ? 0 : min( $max, max( $min ?? 0, strlen( rtrim( substr( strrchr( (string) $n, '.' ) ?: '', 1 ), '0' ) ) ) );
				if ( $min !== null ) {
					$dec = max( $dec, $min );
				}
				$sep = str_starts_with( $locale, 'de' ) || str_starts_with( $locale, 'bg' ) ? '.' : ',';
				$dot = $sep === '.' ? ',' : '.';
				if ( str_starts_with( $locale, 'bg' ) ) {
					$sep = ' ';
				}
				return number_format( (float) $n, $dec, $dot, $sep );
			case 'valueOf':
				return $n;
		}
		$this->note( 'number.' . $method );

		return null;
	}

	/**
	 * @param array<int, mixed> $list
	 * @param array<int, mixed> $args
	 */
	private function array_method( array $list, string $method, array $args, ?Dc_Js_Instance $this_ref ): mixed {
		$fn = $args[0] ?? null;
		$callback = static fn() => null;
		if ( $fn instanceof Dc_Js_Fn ) {
			$callback = fn( array $a ) => $this->invoke( $fn, $a, $fn->this_ref );
		}
		switch ( $method ) {
			case 'map':
				$out = array();
				foreach ( $list as $i => $x ) {
					$out[] = $callback( array( $x, $i, $list ) );
				}
				return $out;
			case 'filter':
				$out = array();
				foreach ( $list as $i => $x ) {
					if ( self::truthy( $callback( array( $x, $i, $list ) ) ) ) {
						$out[] = $x;
					}
				}
				return $out;
			case 'forEach':
				foreach ( $list as $i => $x ) {
					$callback( array( $x, $i, $list ) );
				}
				return null;
			case 'find':
				foreach ( $list as $i => $x ) {
					if ( self::truthy( $callback( array( $x, $i, $list ) ) ) ) {
						return $x;
					}
				}
				return null;
			case 'findIndex':
				foreach ( $list as $i => $x ) {
					if ( self::truthy( $callback( array( $x, $i, $list ) ) ) ) {
						return $i;
					}
				}
				return -1;
			case 'some':
				foreach ( $list as $i => $x ) {
					if ( self::truthy( $callback( array( $x, $i, $list ) ) ) ) {
						return true;
					}
				}
				return false;
			case 'every':
				foreach ( $list as $i => $x ) {
					if ( ! self::truthy( $callback( array( $x, $i, $list ) ) ) ) {
						return false;
					}
				}
				return true;
			case 'reduce':
				$acc   = $args[1] ?? null;
				$start = 0;
				if ( count( $args ) < 2 ) {
					$acc   = $list[0] ?? null;
					$start = 1;
				}
				for ( $i = $start; $i < count( $list ); $i++ ) {
					$acc = $callback( array( $acc, $list[ $i ], $i, $list ) );
				}
				return $acc;
			case 'flatMap':
				$out = array();
				foreach ( $list as $i => $x ) {
					$v = $callback( array( $x, $i, $list ) );
					if ( is_array( $v ) && array_is_list( $v ) ) {
						foreach ( $v as $y ) {
							$out[] = $y;
						}
					} else {
						$out[] = $v;
					}
				}
				return $out;
			case 'flat':
				$out = array();
				foreach ( $list as $x ) {
					if ( is_array( $x ) && array_is_list( $x ) ) {
						foreach ( $x as $y ) {
							$out[] = $y;
						}
					} else {
						$out[] = $x;
					}
				}
				return $out;
			case 'slice':
				$len   = count( $list );
				$start = (int) self::to_number( $args[0] ?? 0 );
				$end   = isset( $args[1] ) && $args[1] !== null ? (int) self::to_number( $args[1] ) : $len;
				$start = $start < 0 ? max( 0, $len + $start ) : min( $start, $len );
				$end   = $end < 0 ? max( 0, $len + $end ) : min( $end, $len );
				return $end <= $start ? array() : array_slice( $list, $start, $end - $start );
			case 'concat':
				$out = $list;
				foreach ( $args as $a ) {
					if ( is_array( $a ) && array_is_list( $a ) ) {
						foreach ( $a as $x ) {
							$out[] = $x;
						}
					} else {
						$out[] = $a;
					}
				}
				return $out;
			case 'join':
				return implode( isset( $args[0] ) ? self::to_string( $args[0] ) : ',', array_map( static fn( $x ) => $x === null ? '' : self::to_string( $x ), $list ) );
			case 'includes':
				foreach ( $list as $x ) {
					if ( self::strict_equal( $x, $args[0] ?? null ) ) {
						return true;
					}
				}
				return false;
			case 'indexOf':
				foreach ( $list as $i => $x ) {
					if ( self::strict_equal( $x, $args[0] ?? null ) ) {
						return $i;
					}
				}
				return -1;
			case 'at':
				$i = (int) self::to_number( $args[0] ?? 0 );
				return $list[ $i < 0 ? count( $list ) + $i : $i ] ?? null;
			case 'reverse':
				return array_reverse( $list );
			case 'sort':
				if ( $fn instanceof Dc_Js_Fn ) {
					usort( $list, fn( $a, $b ) => (int) self::to_number( $callback( array( $a, $b ) ) ) );
				} else {
					usort( $list, static fn( $a, $b ) => strcmp( self::to_string( $a ), self::to_string( $b ) ) );
				}
				return $list;
			case 'keys':
				return array_keys( $list );
			case 'entries':
				$out = array();
				foreach ( $list as $i => $x ) {
					$out[] = array( $i, $x );
				}
				return $out;
			case 'push':
				return count( $list ) + count( $args );
			case 'toString':
				return self::to_string( $list );
			case 'fill':
				return array_fill( 0, count( $list ), $args[0] ?? null );
			case 'length':
				return count( $list );
		}
		$this->note( 'array.' . $method );

		return null;
	}

	/**
	 * Run a function: bind parameters into a fresh scope over the closure's.
	 *
	 * @param array<int, mixed> $args
	 */
	private function invoke( Dc_Js_Fn $fn, array $args, ?Dc_Js_Instance $this_ref ): mixed {
		$scope = $fn->env;
		foreach ( $fn->params as $i => $param ) {
			if ( $param['rest'] ) {
				$this->bind( $param['target'], array_slice( $args, $i ), $scope, $this_ref );
				break;
			}
			$value = array_key_exists( $i, $args ) ? $args[ $i ] : null;
			if ( $value === null && $param['default'] !== null ) {
				$value = $this->ev( $param['default'], $scope, $this_ref );
			}
			$this->bind( $param['target'], $value, $scope, $this_ref );
		}
		if ( $fn->expression ) {
			return $this->ev( $fn->body, $scope, $this_ref );
		}
		[ , $value ] = $this->run( $fn->body, $scope, $this_ref );

		return $value;
	}

	/**
	 * Bind a value to an identifier or pattern.
	 *
	 * @param array<string, mixed> $target
	 * @param array<string, mixed> $scope
	 */
	private function bind( array $target, mixed $value, array &$scope, ?Dc_Js_Instance $this_ref ): void {
		switch ( $target['k'] ) {
			case 'id':
				$scope[ (string) $target['n'] ] = $value;
				return;
			case 'objpat':
				$taken = array();
				foreach ( $target['props'] as $prop ) {
					if ( isset( $prop['rest'] ) ) {
						$rest = array();
						if ( is_array( $value ) ) {
							foreach ( $value as $k => $v ) {
								if ( ! in_array( (string) $k, $taken, true ) ) {
									$rest[ $k ] = $v;
								}
							}
						}
						$scope[ (string) $prop['rest'] ] = $rest;
						continue;
					}
					$taken[] = (string) $prop['key'];
					$v       = $this->member( $value, (string) $prop['key'] );
					if ( $v === null && $prop['default'] !== null ) {
						$v = $this->ev( $prop['default'], $scope, $this_ref );
					}
					$scope[ (string) $prop['alias'] ] = $v;
				}
				return;
			case 'arrpat':
				$list = is_array( $value ) ? array_values( $value ) : ( is_string( $value ) ? mb_str_split( $value ) : array() );
				foreach ( $target['items'] as $i => $item ) {
					if ( $item === null ) {
						continue;
					}
					if ( isset( $item['rest'] ) ) {
						$scope[ (string) $item['rest'] ] = array_slice( $list, $i );
						break;
					}
					$v = $list[ $i ] ?? null;
					if ( $v === null && $item['default'] !== null ) {
						$v = $this->ev( $item['default'], $scope, $this_ref );
					}
					$scope[ (string) $item['name'] ] = $v;
				}
				return;
		}
	}

	/**
	 * Run statements; returns [returned?, value].
	 *
	 * @param array<int, array<string, mixed>> $body
	 * @param array<string, mixed>             $scope
	 * @return array{0: bool, 1: mixed}
	 */
	private function run( array $body, array &$scope, ?Dc_Js_Instance $this_ref ): array {
		foreach ( $body as $stmt ) {
			switch ( $stmt['k'] ) {
				case 'var':
					foreach ( $stmt['decl'] as $decl ) {
						$value = $decl['init'] === null ? null : $this->ev( $decl['init'], $scope, $this_ref );
						$this->bind( $decl['target'], $value, $scope, $this_ref );
					}
					break;
				case 'return':
					return array( true, $stmt['e'] === null ? null : $this->ev( $stmt['e'], $scope, $this_ref ) );
				case 'if':
					$branch = self::truthy( $this->ev( $stmt['c'], $scope, $this_ref ) ) ? $stmt['t'] : $stmt['f'];
					if ( $branch !== null ) {
						$res = $this->run( $branch['k'] === 'block' ? $branch['b'] : array( $branch ), $scope, $this_ref );
						if ( $res[0] ) {
							return $res;
						}
					}
					break;
				case 'block':
					$res = $this->run( $stmt['b'], $scope, $this_ref );
					if ( $res[0] ) {
						return $res;
					}
					break;
				case 'expr':
					$this->ev( $stmt['e'], $scope, $this_ref );
					break;
			}
		}

		return array( false, null );
	}

	/**
	 * `a = b`, `a.b = c`, `a[b] = c`, compound forms. Writes into the local
	 * scope or, for members, into the instance's fields / a nested array
	 * reachable from the scope; anything else is lost (and noted).
	 */
	private function assign( array $node, array &$scope, ?Dc_Js_Instance $this_ref ): mixed {
		$value  = $this->ev( $node['v'], $scope, $this_ref );
		$target = $node['t'];
		if ( $node['op'] !== '=' ) {
			$current = $this->ev( $target, $scope, $this_ref );
			$value   = $this->binary( substr( (string) $node['op'], 0, 1 ), $current, $value );
		}
		if ( $target['k'] === 'id' ) {
			$scope[ (string) $target['n'] ] = $value;
			return $value;
		}
		if ( $target['k'] === 'mem' || $target['k'] === 'idx' ) {
			$key = $target['k'] === 'mem' ? (string) $target['p'] : self::to_string( $this->ev( $target['i'], $scope, $this_ref ) );
			$path = array( $key );
			$root = $target['o'];
			while ( $root['k'] === 'mem' || $root['k'] === 'idx' ) {
				array_unshift( $path, $root['k'] === 'mem' ? (string) $root['p'] : self::to_string( $this->ev( $root['i'], $scope, $this_ref ) ) );
				$root = $root['o'];
			}
			if ( $root['k'] === 'this' && $this_ref !== null ) {
				$first = array_shift( $path );
				if ( $first === 'state' ) {
					self::set_path( $this_ref->state, $path, $value );
				} elseif ( $first !== null ) {
					if ( $path === array() ) {
						$this_ref->fields[ $first ] = $value;
					} else {
						if ( ! isset( $this_ref->fields[ $first ] ) || ! is_array( $this_ref->fields[ $first ] ) ) {
							$this_ref->fields[ $first ] = array();
						}
						self::set_path( $this_ref->fields[ $first ], $path, $value );
					}
				}
				return $value;
			}
			if ( $root['k'] === 'id' && array_key_exists( (string) $root['n'], $scope ) && is_array( $scope[ (string) $root['n'] ] ) ) {
				self::set_path( $scope[ (string) $root['n'] ], $path, $value );
				return $value;
			}
		}
		$this->note( 'assignment to ' . $this->callee_name( $target ) );

		return $value;
	}

	/**
	 * @param array<string, mixed> $arr
	 * @param array<int, string>   $path
	 */
	private static function set_path( array &$arr, array $path, mixed $value ): void {
		$key = array_shift( $path );
		if ( $key === null ) {
			return;
		}
		if ( $path === array() ) {
			$arr[ $key ] = $value;
			return;
		}
		if ( ! isset( $arr[ $key ] ) || ! is_array( $arr[ $key ] ) ) {
			$arr[ $key ] = array();
		}
		self::set_path( $arr[ $key ], $path, $value );
	}

	/* --------------------------------------------------------- analysis */

	/**
	 * Every `this.setState(ARG)` in a body, as its ARG node.
	 *
	 * @param array<int, mixed>        $nodes
	 * @param array<int, array<mixed>> $out
	 */
	private function collect_setstate( array $nodes, array &$out ): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( isset( $node['k'] ) && $node['k'] === 'call' && $node['f']['k'] === 'mem' && $node['f']['p'] === 'setState' && $node['f']['o']['k'] === 'this' ) {
				if ( isset( $node['args'][0] ) ) {
					$out[] = $node['args'][0];
				}
			}
			foreach ( $node as $child ) {
				if ( is_array( $child ) ) {
					$this->collect_setstate( array_is_list( $child ) ? $child : array( $child ), $out );
				}
			}
		}
	}

	/** The expression a statement body returns, when it is a single return. */
	private function returned_expression( array $body ): ?array {
		foreach ( $body as $stmt ) {
			if ( $stmt['k'] === 'return' && $stmt['e'] !== null ) {
				return $stmt['e'];
			}
		}

		return null;
	}

	/**
	 * What a patch value expression can evaluate to, read from its shape.
	 *
	 * @param array<string, mixed> $node
	 * @param array<string, mixed> $env
	 * @return array<int, array<string, mixed>>
	 */
	private function enumerate( array $node, array $env, ?Dc_Js_Instance $this_ref ): array {
		switch ( $node['k'] ) {
			case 'num':
			case 'str':
			case 'lit':
				return array( array( 'set' => $node['v'] ) );
			case 'un':
				if ( $node['op'] === '!' ) {
					// `!s.open` flips; `!true` is a literal.
					if ( $this->reads_state( $node['e'], $env ) ) {
						return array( array( 'flip' => true ) );
					}
					$inner = $this->enumerate( $node['e'], $env, $this_ref );
					return array_map( static fn( array $d ) => isset( $d['set'] ) ? array( 'set' => ! self::truthy( $d['set'] ) ) : $d, $inner );
				}
				if ( $node['op'] === '-' && in_array( $node['e']['k'], array( 'num' ), true ) ) {
					return array( array( 'set' => -$node['e']['v'] ) );
				}
				break;
			case 'cond':
				return array_merge( $this->enumerate( $node['t'], $env, $this_ref ), $this->enumerate( $node['f'], $env, $this_ref ) );
			case 'log':
				return array_merge( $this->enumerate( $node['l'], $env, $this_ref ), $this->enumerate( $node['r'], $env, $this_ref ) );
			case 'id':
				if ( array_key_exists( (string) $node['n'], $env ) ) {
					$v = $env[ (string) $node['n'] ];
					if ( is_scalar( $v ) || $v === null ) {
						return array( array( 'set' => $v ) );
					}
				}
				break;
			case 'mem':
			case 'idx':
				if ( $this->reads_state( $node, $env ) ) {
					return array( array( 'keep' => true ) );
				}
				break;
			case 'bin':
				if ( ( $node['op'] === '+' || $node['op'] === '-' ) && $this->reads_state( $node['l'], $env ) && $node['r']['k'] === 'num' ) {
					return array( array( 'step' => ( $node['op'] === '+' ? 1 : -1 ) * (int) $node['r']['v'] ) );
				}
				break;
			case 'call':
				$name = $this->callee_name( $node['f'] );
				if ( $name === 'Math.min' || $name === 'Math.max' ) {
					foreach ( $node['args'] as $arg ) {
						$inner = $this->enumerate( $arg, $env, $this_ref );
						foreach ( $inner as $d ) {
							if ( isset( $d['step'] ) ) {
								$bound = null;
								foreach ( $node['args'] as $other ) {
									if ( $other['k'] === 'num' ) {
										$bound = $other['v'];
									}
								}
								return array( array( 'step' => $d['step'], 'bound' => $bound, 'clamp' => $name === 'Math.min' ? 'max' : 'min' ) );
							}
						}
					}
				}
				break;
		}
		// Anything else: try evaluating it as it stands.
		$scope = $env;
		$value = $this->ev( $node, $scope, $this_ref );
		if ( is_scalar( $value ) || $value === null ) {
			return array( array( 'set' => $value, 'weak' => true ) );
		}

		return array( array( 'unknown' => true ) );
	}

	/** Whether an expression reads component state (`s.x`, `this.state.x`, `p.x`, `prev.x`). */
	private function reads_state( array $node, array $env ): bool {
		if ( $node['k'] !== 'mem' && $node['k'] !== 'idx' ) {
			return false;
		}
		$o = $node['o'];
		if ( $o['k'] === 'mem' && $o['p'] === 'state' && $o['o']['k'] === 'this' ) {
			return true;
		}
		if ( $o['k'] === 'id' ) {
			$name = (string) $o['n'];
			if ( in_array( $name, array( 's', 'p', 'prev', 'st', 'state' ), true ) ) {
				return true;
			}
			// A local alias of the state object.
			return array_key_exists( $name, $env ) && $env[ $name ] instanceof Dc_Js_Instance;
		}

		return false;
	}
}

<?php
/**
 * Split React/TSX page files into LLM-sized visual sections.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Tsx_Section_Splitter {

	/*
	 * `label` is deliberately NOT here.
	 *
	 * These names are skipped because the compiler has a builtin for each, for
	 * the designs that use `<Label>` or `<Section>` without defining one. But
	 * the shadcn kit DOES define `Label`, and skipping it here meant the
	 * definition never reached the compiler, so `emit_element()` fell to
	 * `builtin_label()` — which takes no attributes — and every form label
	 * became `<span class="rv-label">` with no `for` and none of the kit's own
	 * classes. The builtin is now the fallback for when no component exists,
	 * which is the precedence it should always have had.
	 */
	private const SKIP = array(
		'usereveal',
		'countup',
		'section',
		'cn',
		'clsx',
		'fragment',
	);

	private const MAX_CHUNK_CHARS = 12000;

	/**
	 * @return array<int, array{id:string, title:string, type:string, code:string}>
	 */
	public static function sections( string $source ): array {
		$source = str_replace( "\r\n", "\n", $source );
		$consts = self::extract_consts( $source );
		$fns    = self::extract_components( $source );

		if ( $fns === array() ) {
			$code = trim( $source );
			if ( $code === '' ) {
				return array();
			}

			return array(
				array(
					'id'    => 'page',
					'title' => 'Page',
					'type'  => 'section',
					'code'  => $code,
				),
			);
		}

		$sections = array();
		foreach ( $fns as $fn ) {
			$name = (string) $fn['name'];
			if ( self::is_composer( $fn['code'] ) ) {
				continue;
			}

			$needed = array();
			foreach ( $consts as $const_name => $const_code ) {
				if ( preg_match( '/\b' . preg_quote( $const_name, '/' ) . '\b/', $fn['code'] ) ) {
					$needed[] = $const_code;
				}
			}

			$code = trim( implode( "\n\n", $needed ) . "\n\n" . $fn['code'] );
			$sections[] = array(
				'id'    => sanitize_title( $name ),
				'title' => $name,
				'type'  => self::type_hint( $name, $code ),
				'code'  => $code,
			);
		}

		if ( $sections === array() ) {
			return array(
				array(
					'id'    => 'page',
					'title' => 'Page',
					'type'  => 'section',
					'code'  => trim( $source ),
				),
			);
		}

		return $sections;
	}

	/**
	 * @param array<int, array{id:string, title:string, type:string, code:string}> $sections
	 * @return array<int, array{id:string, title:string, type:string, code:string, sections:array<int, array{id:string, title:string, type:string, code:string}>}>
	 */
	public static function chunks( array $sections, int $max_chars = self::MAX_CHUNK_CHARS ): array {
		$chunks  = array();
		$current = array();
		$size    = 0;

		foreach ( $sections as $section ) {
			$len = strlen( (string) $section['code'] );
			if ( $current !== array() && ( $size + $len ) > $max_chars ) {
				$chunks[] = self::pack_chunk( $current );
				$current  = array();
				$size     = 0;
			}
			$current[] = $section;
			$size     += $len;
		}

		if ( $current !== array() ) {
			$chunks[] = self::pack_chunk( $current );
		}

		return $chunks;
	}

	/**
	 * @param array<int, array{id:string, title:string, type:string, code:string}> $sections
	 * @return array{id:string, title:string, type:string, code:string, sections:array<int, array{id:string, title:string, type:string, code:string}>}
	 */
	private static function pack_chunk( array $sections ): array {
		$titles = array();
		$codes  = array();
		$type   = (string) ( $sections[0]['type'] ?? 'section' );
		foreach ( $sections as $section ) {
			$titles[] = (string) $section['title'];
			$codes[]  = (string) $section['code'];
			if ( in_array( $section['type'], array( 'header', 'footer', 'hero', 'blog', 'form', 'slider' ), true ) && $type === 'section' ) {
				$type = (string) $section['type'];
			}
		}

		return array(
			'id'       => (string) ( $sections[0]['id'] ?? 'chunk' ),
			'title'    => implode( ' + ', $titles ),
			'type'     => $type,
			'code'     => implode( "\n\n", $codes ),
			'sections' => $sections,
		);
	}

	/**
	 * @return array<string, string>
	 */
	private static function extract_consts( string $source, bool $indented = false ): array {
		$out     = array();
		$pattern = $indented
			? '/^[ \t]*const\s+([A-Za-z_][A-Za-z0-9_]*)\s*(?::[^=]+)?=/m'
			: '/^const\s+([A-Za-z_][A-Za-z0-9_]*)\s*(?::[^=]+)?=/m';
		if ( ! preg_match_all( $pattern, $source, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}

		foreach ( $matches[1] as $row ) {
			$name  = $row[0];
			$start = (int) $row[1];
			$line  = (int) strrpos( substr( $source, 0, $start ), "\n" ) + 1;
			if ( self::looks_like_component_const( $source, $start ) ) {
				continue;
			}
			$block = self::extract_assignment( $source, $line );
			if ( $block !== '' ) {
				$out[ $name ] = $block;
			}
		}

		return $out;
	}

	/**
	 * @return array<int, array{name:string, code:string}>
	 */
	private static function extract_components( string $source ): array {
		/*
		 * Normalised BEFORE the scan, not per block afterwards.
		 *
		 * extract_from() ends a component at its first balanced `{…}`, and a
		 * type argument list can hold one: `forwardRef<A, B & { inset?:
		 * boolean }>(…)` was cut at the type's closing brace, so the block
		 * handed to erase_type_params() had no call left to erase and no body
		 * left to render. Erasing over the whole file first means the scan
		 * never sees a brace that is not code.
		 */
		$source = self::erase_call_type_args( $source );
		$source = (string) preg_replace(
			'/^(\s*(?:export\s+(?:default\s+)?)?const\s+[A-Z][A-Za-z0-9]*\s*=\s*(?:async\s*)?)<[\s\S]{0,400}?>(\s*\()/m',
			'$1$2',
			$source
		);

		$out     = array();
		/*
		 * Three declaration forms, not one.
		 *
		 * `const X = React.forwardRef(...)` and `const X = memo(...)` used to be
		 * invisible here, because the pattern demanded a `(` immediately after
		 * the `=`. That is how the shadcn/ui kit is written — and every Lovable
		 * export ships that kit, 44-46 files of it. Measured on ARA's own copy:
		 * the splitter saw 34 of ~247 declared components, and a page built from
		 * Card/Button/Input/Separator came out as `<span>` soup with the border,
		 * background, radius and padding gone, no `<button>`, and Input and
		 * Separator missing altogether.
		 *
		 * The corpus never caught it because all seven designs are hand-rolled
		 * lowercase markup and use none of the kit.
		 */
		/*
		 * A fourth form: a GENERIC arrow, where the `=` is followed by a type
		 * parameter list rather than by `(`.
		 *
		 *     const FormField = <
		 *       TFieldValues extends FieldValues = FieldValues,
		 *       TName extends FieldPath<TFieldValues> = FieldPath<TFieldValues>,
		 *     >({ ...props }: ControllerProps<TFieldValues, TName>) => { … }
		 *
		 * That is shadcn's `form.tsx`, verbatim, in every Lovable export. The
		 * `<` looked like the start of JSX to this pattern, so `FormField` was
		 * never a component — and a page whose form is four `<FormField>`
		 * elements rendered the `<form>`, the submit button, and nothing in
		 * between: four labels, three inputs, a textarea and two descriptions
		 * gone. Measured against the design's own React build.
		 *
		 * The type list runs to the first `>` that is followed by the real
		 * parameter list's `(` — not to the first `>` at all, because the
		 * parameters contain `FieldPath<TFieldValues>` — and it cannot be
		 * `[^=]*` either, since a default type parameter is itself an `=`.
		 * Capped at 400 characters so a `const X = <div>` that never reaches a
		 * `>(` cannot send the scan down the rest of the file.
		 */
		$pattern = '/^(?:export\s+(?:default\s+)?)?(?:function\s+([A-Z][A-Za-z0-9]*)\s*\(|const\s+([A-Z][A-Za-z0-9]*)\s*=\s*(?:async\s*)?(?:\(|<[\s\S]{0,400}?>\s*\(|(?:React\.)?(?:forwardRef|memo)\b))/m';
		if ( ! preg_match_all( $pattern, $source, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}

		$count = count( $matches[0] );
		for ( $i = 0; $i < $count; $i++ ) {
			$name = $matches[1][ $i ][0] !== '' ? $matches[1][ $i ][0] : $matches[2][ $i ][0];
			if ( in_array( strtolower( $name ), self::SKIP, true ) ) {
				continue;
			}
			$start = (int) $matches[0][ $i ][1];
			$block = self::extract_from( $source, $start );
			if ( $block === '' ) {
				continue;
			}
			$block = self::erase_type_params( $block );
			$out[] = array(
				'name' => $name,
				'code' => $block,
			);
		}

		return $out;
	}

	private static function looks_like_component_const( string $source, int $name_offset ): bool {
		$slice = substr( $source, $name_offset, 180 );
		if ( preg_match( '/^[A-Za-z0-9_]*\s*=\s*(?:async\s*)?(?:\([^)]*\)|[A-Za-z_][A-Za-z0-9_]*)\s*=>/', $slice ) === 1 ) {
			return true;
		}

		// A forwardRef/memo wrapper is a component too, and without this it was
		// taken for a data const and evaluated as a value — see
		// extract_components() for what the shadcn kit costs.
		return preg_match( '/^[A-Za-z0-9_]*\s*=\s*(?:React\.)?(?:forwardRef|memo)\b/', $slice ) === 1;
	}

	private static function extract_assignment( string $source, int $start ): string {
		$eq = strpos( $source, '=', $start );
		if ( false === $eq ) {
			return '';
		}
		$i      = $eq + 1;
		$length = strlen( $source );
		while ( $i < $length && ctype_space( $source[ $i ] ) ) {
			++$i;
		}
		if ( $i >= $length ) {
			return '';
		}

		$first = $source[ $i ];
		if ( $first === '{' || $first === '[' || $first === '(' ) {
			$end = self::matching_end( $source, $i );
			if ( $end === -1 ) {
				return trim( substr( $source, $start ) );
			}
			/*
			 * A bracketed value is not necessarily the whole right-hand side.
			 * This used to stop at the matching bracket, allowing only a `;`
			 * within three characters after it, so everything that CONTINUES
			 * past the bracket was thrown away:
			 *
			 *   const pct = (num / total) * 100;   ->  const pct = (num / total)
			 *   const cls = (open ? "a" : "b") + c ->  const cls = (open ? "a" : "b")
			 *   const n   = [1, 2, 3].length;      ->  const n   = [1, 2, 3]
			 *
			 * The first shape is GTM's progress bar: `(num / total) * 100`
			 * evaluated to 0.25 instead of 25, silently, because the `* 100`
			 * was never handed to the evaluator. The same expression written
			 * inline in JSX was correct all along, which is what made it look
			 * like an evaluator bug rather than an extraction one.
			 *
			 * The bracket walk still has to happen — it is what lets a
			 * multi-line array or object survive, since those contain newlines
			 * and the plain path below stops at the first one. It just must not
			 * be mistaken for the end of the statement.
			 */
			$stop = self::assignment_end( $source, $end + 1 );
			return trim( substr( $source, $start, $stop - $start ) );
		}

		/*
		 * The same statement walker as the bracketed path above, not "up to the
		 * first newline".
		 *
		 * A right-hand side that begins with an identifier can still span lines,
		 * and the shadcn kit's variant recipes all do:
		 *
		 *   const buttonVariants = cva(
		 *     "inline-flex items-center …",
		 *     { variants: { … }, defaultVariants: { … } },
		 *   );
		 *
		 * Cutting at the newline captured `const buttonVariants = cva(` and
		 * nothing else, so the recipe evaluated to its base classes with every
		 * variant missing — a Button with no `bg-primary`, no `h-10` for
		 * `size="lg"`, no `border-input` for `variant="outline"`.
		 *
		 * assignment_end() stops at the statement's own `;` at depth 0, or at a
		 * line that starts a new statement when there is no semicolon, so a
		 * single-line declaration is unaffected.
		 */
		$stop = self::assignment_end( $source, $i );

		return trim( substr( $source, $start, $stop - $start ) );
	}

	/**
	 * Drop a generic type parameter list from a component declaration.
	 *
	 * `const FormField = <T extends F = F, N extends P<T> = P<T>>({ …props }) => …`
	 * becomes `const FormField = ({ …props }) => …`.
	 *
	 * Recognising the shape in extract_components() was only half of it: every
	 * path that then reads the component — the body finder below, the
	 * concise-arrow reader, the parameter binder — locates the parameters by
	 * the `(` that follows the `=`, and here a type list sits in between. So
	 * the declaration is normalised once, here, into the form they all already
	 * handle, rather than teaching each of them about TypeScript. A real build
	 * erases these types too, so nothing is lost.
	 *
	 * Only a list directly after the `=` is touched, which leaves
	 * `React.forwardRef<HTMLDivElement, Props>(…)` alone — there the type list
	 * belongs to the call, and that path already works.
	 */
	private static function erase_type_params( string $block ): string {
		$pattern = '/^(\s*(?:export\s+(?:default\s+)?)?const\s+[A-Z][A-Za-z0-9]*\s*=\s*(?:async\s*)?)<[\s\S]{0,400}?>(\s*\()/';
		if ( preg_match( $pattern, $block, $match ) === 1 ) {
			$block = $match[1] . $match[2] . substr( $block, strlen( $match[0] ) );
		}

		return self::erase_call_type_args( $block );
	}

	/**
	 * Drop the type arguments of a `forwardRef<…>(` / `memo<…>(` call.
	 *
	 * `React.forwardRef<HTMLDivElement, Props>(…)` worked, and the reason it
	 * worked hid a hole: the readers downstream find a component's parameter
	 * list and body by brace, and a type list with no braces in it is
	 * invisible to them. shadcn's dropdown-menu.tsx writes
	 *
	 *     React.forwardRef<
	 *       React.ElementRef<typeof DropdownMenuPrimitive.Item>,
	 *       React.ComponentPropsWithoutRef<typeof DropdownMenuPrimitive.Item> & {
	 *         inset?: boolean;
	 *       }
	 *     >(({ className, inset, ...props }, ref) => …)
	 *
	 * and that `{ inset?: boolean; }` is the first brace after the name. Every
	 * DropdownMenuItem and DropdownMenuLabel rendered NOTHING — silently, with
	 * no unevaluated report — while DropdownMenuSeparator, whose type has no
	 * object in it, rendered fine. Measured on the fixture: a menu with a
	 * separator and no items.
	 *
	 * A scanner rather than a regex, because the list nests: `<` and `>` open
	 * and close, and the `=>` of a function type must not count as a close.
	 */
	private static function erase_call_type_args( string $block ): string {
		$offset = 0;
		while ( preg_match( '/\b(?:forwardRef|memo)\s*</', $block, $match, PREG_OFFSET_CAPTURE, $offset ) === 1 ) {
			$open  = (int) $match[0][1] + strlen( (string) $match[0][0] ) - 1;
			$depth = 0;
			$len   = strlen( $block );
			$close = -1;
			for ( $i = $open; $i < $len; $i++ ) {
				$ch = $block[ $i ];
				if ( $ch === '<' ) {
					++$depth;
				} elseif ( $ch === '>' ) {
					// `=>` inside a function type is an arrow, not a close.
					if ( $i > 0 && $block[ $i - 1 ] === '=' ) {
						continue;
					}
					--$depth;
					if ( $depth === 0 ) {
						$close = $i;
						break;
					}
				}
			}
			if ( $close < 0 ) {
				break;
			}
			// Only when the list really is a call's type arguments: a `(` must
			// follow. Otherwise leave the text alone and move past it.
			$after = $close + 1;
			while ( $after < $len && ctype_space( $block[ $after ] ) ) {
				++$after;
			}
			if ( $after < $len && $block[ $after ] === '(' ) {
				$block  = substr( $block, 0, $open ) . substr( $block, $close + 1 );
				$offset = $open;
				continue;
			}
			$offset = $close + 1;
		}

		return $block;
	}

	/**
	 * Offset of a component's body opener, skipping its parameter list.
	 *
	 * `function X({ a, b }: { a: T }) {` opens a brace inside the parameters;
	 * taking the first `{` after the name captures the destructuring pattern
	 * instead of the body, so walk past the balanced parameter list first.
	 */
	private static function body_start( string $source, int $start ): int {
		$paren = strpos( $source, '(', $start );
		$brace = strpos( $source, '{', $start );
		if ( false === $paren ) {
			return false === $brace ? -1 : $brace;
		}
		if ( false !== $brace && $brace < $paren ) {
			return $brace;
		}

		$close = self::matching_end( $source, $paren );
		if ( -1 === $close ) {
			return false === $brace ? -1 : $brace;
		}

		$length = strlen( $source );
		for ( $i = $close + 1; $i < $length; $i++ ) {
			$char = $source[ $i ];
			if ( '{' === $char || '(' === $char ) {
				return $i;
			}
			if ( ';' === $char ) {
				return -1;
			}
		}

		return -1;
	}

	private static function extract_from( string $source, int $start ): string {
		$brace = self::body_start( $source, $start );
		if ( -1 === $brace ) {
			/*
			 * No `{` body. That is not a malformed component — it is a concise
			 * arrow, which is how the whole shadcn/ui kit is written:
			 *
			 *   const Card = React.forwardRef(({ className, ...props }, ref) => (
			 *     <div className={cn("rounded-xl border", className)} {...props} />
			 *   ));
			 *
			 * body_start() walks past the balanced parameter list looking for a
			 * brace and finds none, so this returned '' and extract_components()
			 * skipped the component entirely — the pattern had already matched
			 * it. Capturing the whole declaration instead is what makes the kit
			 * visible at all.
			 *
			 * assignment_end() is the right walker here: starting at the
			 * declaration keyword it tracks bracket depth, so the `(` of the
			 * wrapper call and the `(` of the arrow body are both inside it and
			 * only the statement's own `;` ends the capture.
			 */
			$stop = self::assignment_end( $source, $start );

			return trim( substr( $source, $start, $stop - $start ) );
		}
		$end = self::matching_end( $source, $brace );
		if ( $end === -1 ) {
			if ( preg_match( '/\n(?:export\s+(?:default\s+)?)?(?:function\s+[A-Z]|const\s+[A-Z])/', $source, $next, PREG_OFFSET_CAPTURE, $brace + 1 ) ) {
				return trim( substr( $source, $start, (int) $next[0][1] - $start ) );
			}
			return trim( substr( $source, $start ) );
		}

		return trim( substr( $source, $start, $end - $start + 1 ) );
	}

	/**
	 * Where an assignment that continues past a bracketed value ends.
	 *
	 * Called with the offset just after the matching bracket, and walks on to
	 * the statement's own end so `* 100`, `? a : b` or `.length` are not lost —
	 * see extract_assignment() for what that cost.
	 *
	 * Two stopping rules, because generated sources are not guaranteed to
	 * terminate a statement:
	 *
	 *   - a `;` at depth 0 ends it, which is the normal case;
	 *   - failing that, a line whose first token begins a NEW statement ends it.
	 *     Without the second rule a const with no semicolon would swallow
	 *     everything down to the next `;` anywhere in the file.
	 *
	 * Strings, template literals and comments are skipped so a `;` inside one
	 * cannot end the statement early.
	 */
	private static function assignment_end( string $source, int $from ): int {
		$length = strlen( $source );
		$depth  = 0;
		$quote  = '';
		$escape = false;
		$line   = false;
		$block  = false;
		$bol    = $from;

		for ( $i = $from; $i < $length; $i++ ) {
			$char = $source[ $i ];
			$next = $i + 1 < $length ? $source[ $i + 1 ] : '';

			if ( $line ) {
				if ( $char === "\n" ) {
					$line = false;
					$bol  = $i + 1;
				}
				continue;
			}
			if ( $block ) {
				if ( $char === '*' && $next === '/' ) {
					$block = false;
					++$i;
				}
				continue;
			}
			if ( $quote !== '' ) {
				if ( $escape ) {
					$escape = false;
				} elseif ( $char === '\\' ) {
					$escape = true;
				} elseif ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( $char === '/' && $next === '/' ) {
				$line = true;
				++$i;
				continue;
			}
			if ( $char === '/' && $next === '*' ) {
				$block = true;
				++$i;
				continue;
			}
			if ( $char === '"' || $char === "'" || $char === '`' ) {
				$quote = $char;
				continue;
			}
			if ( $char === '(' || $char === '[' || $char === '{' ) {
				++$depth;
				continue;
			}
			if ( $char === ')' || $char === ']' || $char === '}' ) {
				// A `}` at depth 0 closes the enclosing function body, so the
				// statement ended on the previous line.
				if ( $depth === 0 ) {
					return $bol;
				}
				--$depth;
				continue;
			}
			if ( $char === ';' && $depth === 0 ) {
				return $i + 1;
			}
			if ( $char === "\n" ) {
				$bol = $i + 1;
				if ( $depth !== 0 ) {
					continue;
				}
				// Does the next line start a new statement? If so this one is
				// over, semicolon or not.
				if ( preg_match(
					'/^[ \t]*(const|let|var|return|if|for|while|switch|export|function|\}|\/\/)\b/',
					substr( $source, $bol, 40 )
				) === 1 ) {
					return $bol;
				}
			}
		}

		return $length;
	}

	private static function matching_end( string $source, int $open ): int {
		$pairs  = array(
			'{' => '}',
			'[' => ']',
			'(' => ')',
		);
		$open_ch = $source[ $open ];
		if ( ! isset( $pairs[ $open_ch ] ) ) {
			return -1;
		}

		$stack     = array( $pairs[ $open_ch ] );
		$length    = strlen( $source );
		$in_string = '';
		$escape    = false;
		$in_line   = false;
		$in_block  = false;

		for ( $i = $open + 1; $i < $length; $i++ ) {
			$char = $source[ $i ];
			$next = $i + 1 < $length ? $source[ $i + 1 ] : '';

			if ( $in_line ) {
				if ( $char === "\n" ) {
					$in_line = false;
				}
				continue;
			}
			if ( $in_block ) {
				if ( $char === '*' && $next === '/' ) {
					$in_block = false;
					++$i;
				}
				continue;
			}
			if ( $in_string !== '' ) {
				if ( $escape ) {
					$escape = false;
					continue;
				}
				if ( $char === '\\' && $in_string !== '`' ) {
					$escape = true;
					continue;
				}
				if ( $char === $in_string ) {
					$in_string = '';
				}
				continue;
			}

			if ( $char === '/' && $next === '/' ) {
				$in_line = true;
				++$i;
				continue;
			}
			if ( $char === '/' && $next === '*' ) {
				$in_block = true;
				++$i;
				continue;
			}
			if ( $char === '"' || $char === '`' ) {
				$in_string = $char;
				continue;
			}
			if ( $char === "'" ) {
				$prev = $i > 0 ? $source[ $i - 1 ] : '';
				if ( preg_match( '/[A-Za-z]/', $prev ) && preg_match( '/[A-Za-z]/', $next ) ) {
					continue;
				}
				$in_string = "'";
				continue;
			}

			if ( isset( $pairs[ $char ] ) ) {
				$stack[] = $pairs[ $char ];
				continue;
			}
			if ( $stack !== array() && $char === $stack[ count( $stack ) - 1 ] ) {
				array_pop( $stack );
				if ( $stack === array() ) {
					return $i;
				}
			}
		}

		return -1;
	}

	/**
	 * Visual sections in page order (Header, Hero, …) skipping page composers and helpers.
	 *
	 * @return array<int, string>
	 */
	/**
	 * The page's component instances in source order, each with the raw
	 * attribute text from its call site.
	 *
	 * page_order() returns names only, and Jsx_Compiler's section-splitting
	 * fallback then re-rendered each one by NAME with no props at all. So a
	 * route's `<SiteFooter variant="desktop" />` or `<Hero title="…" eyebrow="…" />`
	 * lost everything it was passed, and the component fell back to its
	 * defaults — silently, because a default is a plausible value.
	 *
	 * Measured: the same component with the same props resolves correctly when
	 * instantiated inside another component and incorrectly when instantiated
	 * in the route's own JSX. ARA's route passes `variant="desktop"`, which
	 * happens to equal the default, so nothing looked wrong.
	 *
	 * Deduplicated by name, keeping the first instance, to match page_order()'s
	 * contract — the two are read together and must stay in step.
	 *
	 * @return array<string, string> name => raw attribute source
	 */
	public static function page_instances( string $source ): array {
		$fns = self::extract_components( $source );
		foreach ( $fns as $fn ) {
			$is_page = (bool) preg_match( '/(?:Page|App|Layout)$/', (string) $fn['name'] );
			if ( ! $is_page && ! self::is_composer( $fn['code'] ) ) {
				continue;
			}
			if ( ! preg_match_all( '/<([A-Z][A-Za-z0-9]*)\b([^>]*?)\/?>/', (string) $fn['code'], $m, PREG_SET_ORDER ) ) {
				continue;
			}
			$out = array();
			foreach ( $m as $row ) {
				$name = (string) $row[1];
				if ( in_array( strtolower( $name ), self::SKIP, true ) || isset( $out[ $name ] ) ) {
					continue;
				}
				$out[ $name ] = trim( (string) ( $row[2] ?? '' ) );
			}
			if ( $out !== array() ) {
				return $out;
			}
		}

		return array();
	}

	public static function page_order( string $source ): array {
		$fns = self::extract_components( $source );
		foreach ( $fns as $fn ) {
			$is_page = (bool) preg_match( '/(?:Page|App|Layout)$/', (string) $fn['name'] );
			if ( ! $is_page && ! self::is_composer( $fn['code'] ) ) {
				continue;
			}
			if ( ! preg_match_all( '/<([A-Z][A-Za-z0-9]*)\b/', $fn['code'], $m ) ) {
				continue;
			}
			$names = array();
			foreach ( $m[1] as $name ) {
				if ( in_array( strtolower( $name ), self::SKIP, true ) ) {
					continue;
				}
				if ( ! in_array( $name, $names, true ) ) {
					$names[] = $name;
				}
			}
			if ( $names !== array() ) {
				return $names;
			}
		}

		$names = array();
		foreach ( $fns as $fn ) {
			$name = (string) $fn['name'];
			if ( in_array( strtolower( $name ), self::SKIP, true ) || self::is_composer( $fn['code'] ) ) {
				continue;
			}
			$names[] = $name;
		}

		return $names;
	}

	/**
	 * @return array<string, string>
	 */
	public static function const_blocks( string $source ): array {
		return self::extract_consts( $source );
	}

	/**
	 * Consts declared inside a component body, where indentation is expected.
	 *
	 * Lovable pages keep their section data in multi-line arrays local to the
	 * page component, so these have to be matched with balanced brackets rather
	 * than to end of line.
	 *
	 * @return array<string, string>
	 */
	public static function scoped_const_blocks( string $source ): array {
		return self::extract_consts( $source, true );
	}

	/**
	 * @return array<int, array{name:string, code:string}>
	 */
	public static function extract_components_public( string $source ): array {
		return self::extract_components( $source );
	}

	/**
	 * Component identifiers this file imports, split by origin.
	 *
	 * `local` maps a name to its module specifier so the compiler can load the
	 * defining file; `external` names come from packages (icon sets, UI kits)
	 * whose source is not in the ZIP and can only be approximated.
	 *
	 * `packages` keeps the module specifier a non-local name came from, which
	 * `external` throws away. Radix is the reason: `<AccordionPrimitive.Trigger>`
	 * is a `<button>` and `<DialogPrimitive.Root>` is nothing at all, and the
	 * only thing that says which package a namespace import belongs to is the
	 * specifier.
	 *
	 * @return array{local:array<string, string>, external:array<int, string>, assets:array<string, string>, lucide:array<string, string>, packages:array<string, string>}
	 */
	public static function imports( string $source ): array {
		$local    = array();
		$external = array();
		$assets   = array();
		$lucide   = array();
		$packages = array();

		if ( ! preg_match_all( '/^\s*import\s+([^;]+?)\s+from\s*["\']([^"\']+)["\']/m', $source, $rows, PREG_SET_ORDER ) ) {
			return array(
				'local'    => $local,
				'external' => $external,
				'assets'   => $assets,
				'lucide'   => $lucide,
				'packages' => $packages,
			);
		}

		foreach ( $rows as $row ) {
			$module   = trim( $row[2] );
			$is_local = str_starts_with( $module, '.' ) || str_starts_with( $module, '@/' ) || str_starts_with( $module, '~/' );
			$names    = self::import_names( $row[1] );
			$aliased  = self::named_imports( $row[1] );

			// A bundler turns an asset import into a URL; the identifier is
			// normally lower-case and is the only handle the JSX has on the file.
			if ( 1 === preg_match( '/\.(?:jpe?g|png|gif|svg|webp|avif|ico|bmp|mp4|webm|ogg|mp3|wav|woff2?|ttf|otf|eot|json)$/i', $module ) ) {
				foreach ( $names as $name ) {
					$assets[ $name ] = $module;
				}
				continue;
			}

			$icon_pack = self::icon_pack( $module );
			foreach ( $names as $name ) {
				if ( 1 !== preg_match( '/^[A-Z]/', $name ) ) {
					continue;
				}
				if ( $icon_pack !== '' ) {
					$export         = $aliased[ $name ] ?? $name;
					$lucide[ $name ] = self::icon_id( $export, $icon_pack );
				}
				if ( $is_local ) {
					$local[ $name ] = $module;
					continue;
				}
				$external[]        = $name;
				$packages[ $name ] = $module;
			}
		}

		return array(
			'local'    => $local,
			'external' => array_values( array_unique( $external ) ),
			'assets'   => $assets,
			'lucide'   => $lucide,
			'packages' => $packages,
		);
	}

	/**
	 * Local identifier => exported name for `{ Menu as MenuIcon }`.
	 *
	 * @return array<string, string>
	 */
	private static function named_imports( string $clause ): array {
		$out = array();
		if ( ! preg_match( '/\{([^}]*)\}/', $clause, $braced ) ) {
			return $out;
		}
		foreach ( explode( ',', $braced[1] ) as $part ) {
			$part = trim( $part );
			if ( $part === '' ) {
				continue;
			}
			if ( preg_match( '/^([A-Za-z_$][\w$]*)\s+as\s+([A-Za-z_$][\w$]*)$/', $part, $alias ) ) {
				$out[ $alias[2] ] = $alias[1];
				continue;
			}
			if ( preg_match( '/^([A-Za-z_$][\w$]*)/', $part, $plain ) ) {
				$out[ $plain[1] ] = $plain[1];
			}
		}

		return $out;
	}

	private static function icon_pack( string $module ): string {
		$mod = strtolower( $module );
		if ( str_contains( $mod, 'lucide' ) ) {
			return 'lucide';
		}
		if ( str_contains( $mod, 'heroicons' ) ) {
			return 'heroicons';
		}
		if ( str_contains( $mod, 'radix-ui' ) && str_contains( $mod, 'icon' ) ) {
			return 'radix';
		}
		if ( str_contains( $mod, 'react-icons' ) ) {
			return 'react-icons';
		}

		return '';
	}

	/**
	 * Lucide icon id used at runtime (`Menu` → `menu`, `Bars3Icon` → `menu`).
	 */
	public static function icon_id( string $export, string $pack = 'lucide' ): string {
		$base = (string) preg_replace( '/Icon$/', '', $export );
		$id   = (string) preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $base );
		$id   = (string) preg_replace( '/([A-Z]+)([A-Z][a-z])/', '$1-$2', $id );
		$id   = (string) preg_replace( '/([A-Za-z])(\d)/', '$1-$2', $id );
		$id   = strtolower( $id );

		$aliases = array(
			'bars'         => 'menu',
			'bars-2'       => 'menu',
			'bars-3'       => 'menu',
			'bars-4'       => 'menu',
			'hamburger'    => 'menu',
			'x-mark'       => 'x',
			'x-circle'     => 'circle-x',
			'magnifying-glass' => 'search',
			'ellipsis-horizontal' => 'ellipsis',
			'ellipsis-vertical'   => 'ellipsis-vertical',
		);
		if ( $pack === 'react-icons' ) {
			$id = (string) preg_replace( '/^[a-z]{2}-/', '', $id );
		}
		if ( isset( $aliases[ $id ] ) ) {
			return $aliases[ $id ];
		}

		return $id !== '' ? $id : 'circle';
	}

	/**
	 * @return array<int, string>
	 */
	private static function import_names( string $clause ): array {
		$names = array();
		$clause = trim( $clause );

		if ( preg_match( '/\{([^}]*)\}/', $clause, $braced ) ) {
			foreach ( explode( ',', $braced[1] ) as $part ) {
				$part = trim( $part );
				if ( $part === '' ) {
					continue;
				}
				if ( preg_match( '/\bas\s+([A-Za-z_$][\w$]*)$/', $part, $alias ) ) {
					$names[] = $alias[1];
					continue;
				}
				$names[] = preg_replace( '/[^\w$].*$/', '', $part ) ?? '';
			}
			$clause = trim( (string) preg_replace( '/\{[^}]*\}/', '', $clause ), " ,\t" );
		}

		if ( preg_match( '/^\*\s+as\s+([A-Za-z_$][\w$]*)/', $clause, $star ) ) {
			$names[] = $star[1];
			$clause  = '';
		}

		$clause = trim( $clause, " ,\t" );
		if ( $clause !== '' && preg_match( '/^[A-Za-z_$][\w$]*$/', $clause ) ) {
			$names[] = $clause;
		}

		return array_values( array_filter( $names ) );
	}

	/**
	 * The component that renders the whole page.
	 *
	 * Route files name it through `component:`; plain React files export it as
	 * default. Falls back to a Page/App/Layout suffix, then to the largest
	 * component in the file.
	 */
	public static function root_component( string $source ): string {
		if ( preg_match( '/\bcomponent\s*:\s*([A-Z][A-Za-z0-9_]*)/', $source, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/export\s+default\s+function\s+([A-Z][A-Za-z0-9_]*)/', $source, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/export\s+default\s+([A-Z][A-Za-z0-9_]*)\s*;/', $source, $m ) ) {
			return $m[1];
		}

		$fns = self::extract_components( $source );
		foreach ( $fns as $fn ) {
			if ( preg_match( '/(?:Page|App|Layout|Screen|View)$/', (string) $fn['name'] ) ) {
				return (string) $fn['name'];
			}
		}

		$best = '';
		$size = 0;
		foreach ( $fns as $fn ) {
			$len = strlen( (string) $fn['code'] );
			if ( $len > $size && self::is_composer( (string) $fn['code'] ) ) {
				$best = (string) $fn['name'];
				$size = $len;
			}
		}

		return $best;
	}

	private static function is_composer( string $code ): bool {
		$components = preg_match_all( '/<[A-Z][A-Za-z0-9]*\b/', $code );
		$landmarks  = preg_match( '/<(section|header|footer|nav|form|article|h1|h2)\b/', $code );
		return $components >= 4 && 1 !== $landmarks;
	}

	public static function type_hint( string $name, string $code ): string {
		$n   = strtolower( $name );
		$hay = strtolower( $code );
		return match ( true ) {
			str_contains( $n, 'header' ) && ! str_contains( $n, 'hero' ) => 'header',
			str_contains( $n, 'footer' ) => 'footer',
			str_contains( $n, 'nav' ) && ! str_contains( $n, 'hero' ) => 'navigation',
			str_contains( $n, 'hero' ) => 'hero',
			str_contains( $n, 'slider' ) || str_contains( $n, 'carousel' ) || str_contains( $hay, 'swiper' ) => 'slider',
			str_contains( $hay, '<form' ) || str_contains( $n, 'form' ) || str_contains( $n, 'newsletter' ) => 'form',
			str_contains( $n, 'blog' ) || str_contains( $n, 'news' ) => 'blog',
			default => 'section',
		};
	}
}

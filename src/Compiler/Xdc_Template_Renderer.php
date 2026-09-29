<?php
/**
 * Best-effort server-side renderer for Design.com x-dc templates.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Xdc_Template_Renderer {

	/** @var array<string, string> */
	private const ICONS = array(
		'iconPhone'    => 'phone',
		'iconShield'   => 'shield',
		'iconCheck'    => 'check',
		'iconCheckSm'  => 'check',
		'iconCheckBig' => 'check',
		'iconPinSm'    => 'map-pin',
	);

	public function render( string $html ): string {
		$template = $this->extract_between( $html, '<x-dc', '</x-dc>' );
		if ( $template === '' ) {
			$template = $html;
		}
		$script  = $this->dc_script( $html );
		$state   = $this->state_defaults( $script );
		$context = $this->render_context( $script, $state );

		$template = preg_replace( '/<helmet\b[^>]*>[\s\S]*?<\/helmet>/i', '', $template ) ?? $template;
		$template = preg_replace( '/<!--\s*BUILD-MARKER\s*-->/', '', $template ) ?? $template;
		// Bind toggles from Design.com onClick placeholders before they collapse
		// to empty onClick="" during placeholder substitution.
		$template = $this->wire_dropdown_toggles( $template );
		$template = $this->render_fragment( $template, $context, array() );
		$template = preg_replace( '/\s+(?:on[A-Z][\w-]*|hint-placeholder-[\w-]+)="[^"]*"/', '', $template ) ?? $template;
		$template = preg_replace( '/\{\{[\s\S]*?\}\}/', '', $template ) ?? $template;

		return $this->repair_unicode_artifacts( trim( $template ) );
	}

	/**
	 * Repair `u00b7`-style damage from stripcslashes on `\uXXXX` sequences.
	 */
	private function repair_unicode_artifacts( string $html ): string {
		return Design_Html::repair_unicode_artifacts( $html );
	}

	/**
	 * Map Design.com dropdown click handlers onto Motion_Runtime toggle classes.
	 */
	private function wire_dropdown_toggles( string $html ): string {
		$map = array(
			'onServicesClick'  => 'dropdown--services',
			'onAreasClick'     => 'dropdown--areas',
			'onResourcesClick' => 'dropdown--resources',
			'toggleMenu'       => 'menu--mobile',
			'toggleCities'     => 'cities--more',
		);
		foreach ( $map as $handler => $toggle ) {
			$class = 'dxai-toggle-' . $toggle;
			$html  = preg_replace_callback(
				'/<([a-z0-9]+)([^>]*?)\s+onClick="\{\{\s*' . preg_quote( $handler, '/' ) . '\s*\}\}"([^>]*)>/i',
				static function ( array $m ) use ( $class ): string {
					$tag   = $m[1];
					$attrs = $m[2] . $m[3];
					if ( preg_match( '/\bclass=("|\')(.*?)\1/i', $attrs, $cm ) === 1 ) {
						$attrs = preg_replace(
							'/\bclass=("|\')(.*?)\1/i',
							'class=$1' . $class . ' $2$1',
							$attrs,
							1
						) ?? $attrs;
					} else {
						$attrs .= ' class="' . $class . '"';
					}
					if ( ! str_contains( $attrs, 'aria-expanded=' ) ) {
						$attrs .= ' aria-expanded="false"';
					}

					return '<' . $tag . $attrs . '>';
				},
				$html
			) ?? $html;
		}

		return $html;
	}

	/**
	 * @param array<string, mixed> $globals
	 * @param array<string, mixed> $locals
	 */
	private function render_fragment( string $html, array $globals, array $locals ): string {
		$last = null;
		while ( $html !== $last ) {
			$last = $html;
			$html = $this->replace_sc_blocks( $html, 'sc-if', $globals, $locals );
			$html = $this->replace_sc_blocks( $html, 'sc-for', $globals, $locals );
		}

		return $this->substitute_placeholders( $html, $globals, $locals );
	}

	/**
	 * Expand one balanced sc-* block per pass (innermost first) so nested
	 * menus like desktop nav + dropdowns keep their DOM structure.
	 *
	 * @param array<string, mixed> $globals
	 * @param array<string, mixed> $locals
	 */
	private function replace_sc_blocks( string $html, string $tag, array $globals, array $locals ): string {
		$open = '<' . $tag;
		$pos  = 0;
		while ( ( $start = stripos( $html, $open, $pos ) ) !== false ) {
			$after = $start + strlen( $open );
			$ch    = $html[ $after ] ?? '';
			if ( $ch !== '>' && $ch !== ' ' && $ch !== "\t" && $ch !== "\n" && $ch !== "\r" ) {
				$pos = $after;
				continue;
			}
			$gt = strpos( $html, '>', $start );
			if ( false === $gt ) {
				break;
			}
			$end = $this->find_balanced_close( $html, $tag, $gt + 1 );
			if ( $end < 0 ) {
				$pos = $gt + 1;
				continue;
			}
			$inner = substr( $html, $gt + 1, $end - ( $gt + 1 ) );
			// Prefer innermost: if this block still contains the same tag, skip for now.
			if ( stripos( $inner, $open ) !== false ) {
				$pos = $gt + 1;
				continue;
			}
			$attrs  = substr( $html, $start + strlen( $open ), $gt - ( $start + strlen( $open ) ) );
			$close  = stripos( $html, '</' . $tag . '>', $end );
			$close_end = false === $close ? $end : $close + strlen( '</' . $tag . '>' );
			$replacement = strtolower( $tag ) === 'sc-for'
				? $this->expand_sc_for( $attrs, $inner, $globals, $locals )
				: $this->expand_sc_if( $attrs, $inner, $globals, $locals );
			$html = substr( $html, 0, $start ) . $replacement . substr( $html, $close_end );
			$pos  = $start + strlen( $replacement );
		}

		return $html;
	}

	private function find_balanced_close( string $html, string $tag, int $from ): int {
		$open  = '<' . $tag;
		$close = '</' . $tag . '>';
		$depth = 1;
		$i     = $from;
		$len   = strlen( $html );
		$tlen  = strlen( $tag );
		while ( $i < $len && $depth > 0 ) {
			$next_open  = stripos( $html, $open, $i );
			$next_close = stripos( $html, $close, $i );
			if ( false === $next_close ) {
				return -1;
			}
			if ( false !== $next_open && $next_open < $next_close ) {
				$after = $next_open + strlen( $open );
				$ch    = $html[ $after ] ?? '';
				if ( $ch === '>' || $ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r" ) {
					++$depth;
					$i = $after;
					continue;
				}
				$i = $after;
				continue;
			}
			--$depth;
			if ( 0 === $depth ) {
				return $next_close;
			}
			$i = $next_close + strlen( $close );
		}

		return -1;
	}

	/**
	 * @param array<string, mixed> $globals
	 * @param array<string, mixed> $locals
	 */
	private function expand_sc_if( string $attrs, string $body, array $globals, array $locals ): string {
		$expr = $this->attr_placeholder( $attrs, 'value' );
		$hint = $this->attr_placeholder( $attrs, 'hint-placeholder-val' );
		$show = null;
		// Design.com ships the authored snapshot in hint-placeholder-val.
		// Prefer it for server render so desktop chrome isn't dropped when
		// runtime expressions like window.innerWidth can't be evaluated.
		if ( $hint === 'true' || $hint === 'false' ) {
			$show = $hint === 'true';
		} elseif ( $expr !== '' ) {
			$show = $this->resolve_bool( $expr, $globals, $locals );
		}

		$rendered = $this->render_fragment( $body, $globals, $locals );
		if ( $show === false ) {
			// Keep nav dropdown panels in the DOM (hidden) so Motion_Runtime
			// toggles can open them — dropping them makes the ▼ menus inert.
			$toggle = $this->dropdown_toggle_name( $expr );
			if ( $toggle !== null ) {
				return $this->mark_toggle_panel( $rendered, $toggle );
			}

			return '';
		}

		return $rendered;
	}

	private function dropdown_toggle_name( string $expr ): ?string {
		return match ( trim( $expr ) ) {
			'servicesOpen'  => 'dropdown--services',
			'areasOpen'     => 'dropdown--areas',
			'resourcesOpen' => 'dropdown--resources',
			'menuOpen'      => 'menu--mobile',
			default         => null,
		};
	}

	private function mark_toggle_panel( string $html, string $name ): string {
		$html = ltrim( $html );
		if ( $html === '' ) {
			return '';
		}
		$class = 'hidden dxai-on-' . sanitize_html_class( $name );
		if ( preg_match( '/^<([a-z0-9]+)(\s[^>]*)?>/i', $html, $m ) !== 1 ) {
			return '<div class="' . esc_attr( $class ) . '">' . $html . '</div>';
		}
		$tag   = $m[1];
		$attrs = $m[2] ?? '';
		if ( preg_match( '/\bclass=("|\')(.*?)\1/i', $attrs, $cm ) === 1 ) {
			$attrs = preg_replace(
				'/\bclass=("|\')(.*?)\1/i',
				'class=$1' . $class . ' $2$1',
				$attrs,
				1
			) ?? $attrs;
		} else {
			$attrs .= ' class="' . esc_attr( $class ) . '"';
		}

		return '<' . $tag . $attrs . '>' . substr( $html, strlen( $m[0] ) );
	}

	/**
	 * @param array<string, mixed> $globals
	 * @param array<string, mixed> $locals
	 */
	private function expand_sc_for( string $attrs, string $body, array $globals, array $locals ): string {
		$list  = $this->attr_placeholder( $attrs, 'list' );
		$alias = $this->plain_attr( $attrs, 'as' );
		$count = (int) $this->plain_attr( $attrs, 'hint-placeholder-count' );
		$items = $this->resolve_value( $list, $globals, $locals );

		if ( ! is_array( $items ) ) {
			$items = $count > 0 ? array_fill( 0, $count, array() ) : array();
		}

		$out = '';
		$index = 0;
		foreach ( $items as $item ) {
			$next = $locals;
			if ( $alias !== '' ) {
				$next[ $alias ] = $item;
			}
			$next['__index'] = $index;
			$piece = $body;
			if ( is_array( $item ) && ( isset( $item['q'], $item['a'] ) || isset( $item['answerStyle'] ) ) ) {
				$piece = $this->prepare_faq_item_template( $piece, $index, $item );
			}
			$chunk = $this->render_fragment( $piece, $globals, $next );
			if ( is_array( $item ) && ( isset( $item['q'], $item['a'] ) || isset( $item['answerStyle'] ) ) ) {
				$chunk = $this->mark_faq_panel( $chunk, $index, $item );
			}
			$out .= $chunk;
			++$index;
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function prepare_faq_item_template( string $html, int $index, array $item ): string {
		$flag = 'openFaq--' . $index;
		$open = ( (string) ( $item['sign'] ?? '' ) ) === '-';
		$html = preg_replace_callback(
			'/<button([^>]*?)\s+onClick="\{\{\s*f\.toggle\s*\}\}"([^>]*)>/i',
			static function ( array $m ) use ( $flag, $open ): string {
				$attrs = $m[1] . $m[2];
				$class = 'dxai-toggle-' . $flag;
				if ( preg_match( '/\bclass=("|\')(.*?)\1/i', $attrs, $cm ) === 1 ) {
					$attrs = preg_replace(
						'/\bclass=("|\')(.*?)\1/i',
						'class=$1' . $class . ' $2$1',
						$attrs,
						1
					) ?? $attrs;
				} else {
					$attrs .= ' class="' . $class . '"';
				}
				if ( ! str_contains( $attrs, 'aria-expanded=' ) ) {
					$attrs .= ' aria-expanded="' . ( $open ? 'true' : 'false' ) . '"';
				}

				return '<button' . $attrs . '>';
			},
			$html,
			1
		) ?? $html;

		return $html;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function mark_faq_panel( string $html, int $index, array $item ): string {
		$flag        = 'openFaq--' . $index;
		$open        = ( (string) ( $item['sign'] ?? '' ) ) === '-';
		$panel_class = 'dxai-on-' . $flag . ( $open ? '' : ' hidden' );

		return preg_replace_callback(
			'/<div(\s+style="[^"]*max-height:[^"]*")>/i',
			static function ( array $m ) use ( $panel_class ): string {
				return '<div class="' . esc_attr( $panel_class ) . '" data-dxai-accordion="1" data-open-max="400px"' . $m[1] . '>';
			},
			$html,
			1
		) ?? $html;
	}

	/**
	 * @param array<string, mixed> $globals
	 * @param array<string, mixed> $locals
	 */
	private function substitute_placeholders( string $html, array $globals, array $locals ): string {
		return preg_replace_callback(
			'/\{\{\s*([^}]+?)\s*\}\}/',
			function ( array $matches ) use ( $globals, $locals ): string {
				$key = trim( (string) ( $matches[1] ?? '' ) );
				if ( isset( self::ICONS[ $key ] ) ) {
					return '<i data-lucide="' . esc_attr( self::ICONS[ $key ] ) . '"></i>';
				}
				$value = $this->resolve_value( $key, $globals, $locals );
				if ( is_bool( $value ) ) {
					return $value ? 'true' : 'false';
				}
				if ( is_scalar( $value ) ) {
					return (string) $value;
				}

				return '';
			},
			$html
		) ?? $html;
	}

	private function dc_script( string $html ): string {
		if ( preg_match( '/<script\b[^>]*data-dc-script[^>]*>([\s\S]*?)<\/script>/i', $html, $m ) ) {
			return trim( (string) $m[1] );
		}

		return '';
	}

	private function state_defaults( string $script ): array {
		$expr = $this->capture_assignment( $script, 'state' );
		$data = is_string( $expr ) ? $this->parse_jsonish_literal( $expr ) : null;

		return is_array( $data ) ? $data : array();
	}

	/**
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	private function render_context( string $script, array $state ): array {
		// Static ZIP import always paints the desktop snapshot.
		$state = array_merge(
			array(
				'isMobile'  => false,
				'menuOpen'  => false,
				'servicesOpen' => false,
				'areasOpen' => false,
				'resourcesOpen' => false,
			),
			$state,
			array(
				'isMobile' => false,
			)
		);
		$context = $state;
		$context['isDesktop'] = true;
		// Consts declared inside renderVals (navLinkStyle, dropStyle, …) are
		// returned via object shorthand — resolve them before the return props.
		foreach ( $this->capture_method_consts( $script, 'renderVals' ) as $name => $expr ) {
			$context[ $name ] = $this->evaluate_expression( $expr, $context );
		}
		$return = $this->capture_method_return_object( $script, 'renderVals' );
		if ( $return !== '' ) {
			foreach ( array(
				'isMobile', 'isDesktop', 'servicesOpen', 'areasOpen', 'resourcesOpen', 'navLinkStyle',
				'dropStyle', 'dropItem', 'dropItemLast', 'navHeight', 'logoHeight', 'headerWrapStyle',
				'navStyle', 'headerCtaStyle', 'overlayStyle', 'mobileMenuStyle', 'mLink', 'mLinkLast',
				'heroStyle', 'heroInnerStyle', 'badgeStyle', 'citiesExpanded', 'citiesLabel', 'whereCols',
				'whyCols', 'footerCols', 'footerPadBottom', 'formCols', 'formSubmitted', 'formActive',
				'isStep1', 'isStep2', 'isStep3', 'stepLabel', 'stepPct', 'stepWidth', 'fHelp', 'fWhen',
				'fFirst', 'fLast', 'fPhone', 'fEmail', 'fAddress', 'fDetails', 'primaryBtn', 'secondaryBtn',
				'fieldLabel', 'fieldInput',
			) as $key ) {
				$expr = $this->property_expression( $return, $key );
				if ( $expr === null ) {
					continue;
				}
				// Shorthand `navLinkStyle,` — already filled from const bindings.
				if ( preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $expr ) === 1 && array_key_exists( $expr, $context ) ) {
					$context[ $key ] = $context[ $expr ];
					continue;
				}
				$context[ $key ] = $this->evaluate_expression( $expr, $context );
			}
			foreach ( array( 'steps', 'officeCities', 'coveredCities', 'moreCities', 'reasons', 'reviews' ) as $key ) {
				$expr = $this->property_expression( $return, $key );
				if ( $expr === null ) {
					continue;
				}
				$data = $this->parse_jsonish_literal( $expr );
				if ( is_array( $data ) ) {
					$context[ $key ] = $data;
				}
			}
		}

		$faqs = $this->capture_method_return_array( $script, '_faqData' );
		$data = $faqs !== '' ? $this->parse_jsonish_literal( $faqs ) : null;
		if ( is_array( $data ) ) {
			$open = isset( $state['openFaq'] ) ? (int) $state['openFaq'] : 0;
			$rows = array();
			foreach ( array_values( $data ) as $index => $faq ) {
				if ( ! is_array( $faq ) ) {
					continue;
				}
				$is_open = $index === $open;
				$rows[]  = array(
					'q'           => (string) ( $faq['q'] ?? '' ),
					'a'           => (string) ( $faq['a'] ?? '' ),
					'sign'        => $is_open ? '-' : '+',
					'iconBg'      => $is_open ? '#C51527' : '#f1ece9',
					'iconColor'   => $is_open ? '#ffffff' : '#C51527',
					'answerStyle' => 'overflow:hidden;transition:max-height .3s ease,opacity .25s;max-height:' . ( $is_open ? '400px' : '0' ) . ';opacity:' . ( $is_open ? '1' : '0' ),
				);
			}
			$context['faqs'] = $rows;
		}

		$context['isMobile']  = false;
		$context['isDesktop'] = true;

		return $context;
	}

	/**
	 * @param array<string, mixed> $globals
	 * @param array<string, mixed> $locals
	 */
	private function resolve_value( string $path, array $globals, array $locals ): mixed {
		$path = trim( $path );
		if ( $path === '' ) {
			return null;
		}
		$parts = explode( '.', $path );
		$root  = array_shift( $parts );
		$value = array_key_exists( (string) $root, $locals ) ? $locals[ $root ] : ( $globals[ (string) $root ] ?? null );
		foreach ( $parts as $part ) {
			if ( is_array( $value ) && array_key_exists( $part, $value ) ) {
				$value = $value[ $part ];
				continue;
			}

			return null;
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $globals
	 * @param array<string, mixed> $locals
	 */
	private function resolve_bool( string $expr, array $globals, array $locals ): ?bool {
		$expr = trim( $expr );
		if ( $expr === 'true' ) {
			return true;
		}
		if ( $expr === 'false' ) {
			return false;
		}
		if ( str_starts_with( $expr, '!' ) ) {
			$inner = $this->resolve_bool( substr( $expr, 1 ), $globals, $locals );
			return $inner === null ? null : ! $inner;
		}
		$value = $this->resolve_value( $expr, $globals, $locals );
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_scalar( $value ) ) {
			return (bool) $value;
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function evaluate_expression( string $expr, array $state ): mixed {
		$expr = trim( $expr );
		if ( $expr === '' ) {
			return '';
		}
		// Unwrap a single layer of grouping parens.
		while ( strlen( $expr ) >= 2 && $expr[0] === '(' && str_ends_with( $expr, ')' ) && $this->wrapping_parens( $expr ) ) {
			$expr = trim( substr( $expr, 1, -1 ) );
		}
		// Only a *single* quoted literal — not `'a' + 'b'` (which also starts
		// and ends with quotes and would fool /^'.*'$/s).
		if ( $expr !== '' && ( $expr[0] === "'" || $expr[0] === '"' ) ) {
			$end = $this->skip_string( $expr, 0 );
			if ( $end === strlen( $expr ) - 1 ) {
				return $this->decode_js_string( substr( $expr, 1, -1 ) );
			}
		}
		if ( $expr === 'true' || $expr === 'false' ) {
			return $expr === 'true';
		}
		if ( $expr === 'null' ) {
			return null;
		}
		if ( preg_match( '/^-?\d+(?:\.\d+)?$/', $expr ) === 1 ) {
			return str_contains( $expr, '.' ) ? (float) $expr : (int) $expr;
		}
		$ternary = $this->split_ternary( $expr );
		if ( $ternary !== null ) {
			return $this->evaluate_condition( $ternary['condition'], $state )
				? $this->evaluate_expression( $ternary['if'], $state )
				: $this->evaluate_expression( $ternary['else'], $state );
		}
		$parts = $this->split_top_level( $expr, '+' );
		if ( count( $parts ) > 1 ) {
			$out = '';
			foreach ( $parts as $part ) {
				$value = $this->evaluate_expression( $part, $state );
				$out  .= is_scalar( $value ) || $value === null ? (string) $value : '';
			}

			return $out;
		}
		if ( preg_match( '/^s\.([A-Za-z0-9_]+)$/', $expr, $m ) ) {
			return $state[ $m[1] ] ?? null;
		}
		// dropItem.replace(';border-bottom:…', '') and similar const transforms.
		if ( preg_match(
			'/^([A-Za-z_][A-Za-z0-9_]*)\.replace\(\s*([\'"])((?:\\\\.|(?!\2).)*)\2\s*,\s*([\'"])((?:\\\\.|(?!\4).)*)\4\s*\)$/s',
			$expr,
			$m
		) === 1 ) {
			$base = $state[ $m[1] ] ?? '';
			if ( ! is_string( $base ) ) {
				$base = is_scalar( $base ) || $base === null ? (string) $base : '';
			}

			return str_replace(
				$this->decode_js_string( $m[3] ),
				$this->decode_js_string( $m[5] ),
				$base
			);
		}
		if ( preg_match( '/^([A-Za-z_][A-Za-z0-9_]*)$/', $expr, $m ) === 1 && array_key_exists( $m[1], $state ) ) {
			return $state[ $m[1] ];
		}
		// Comparison used as a value: s.dropdown === 'services'.
		if ( preg_match( '/^s\.([A-Za-z0-9_]+)\s*===\s*(.+)$/', $expr, $m ) === 1 ) {
			return ( $state[ $m[1] ] ?? null ) === $this->evaluate_expression( $m[2], $state );
		}
		if ( str_starts_with( $expr, '!' ) ) {
			return ! $this->evaluate_condition( substr( $expr, 1 ), $state );
		}

		return '';
	}

	/**
	 * @return array<string, string> name => expression (ordered)
	 */
	private function capture_method_consts( string $script, string $name ): array {
		$body = $this->capture_method_body( $script, $name );
		if ( $body === '' ) {
			return array();
		}
		$out  = array();
		$len  = strlen( $body );
		$pos  = 0;
		while ( $pos < $len ) {
			$next = strpos( $body, 'const ', $pos );
			if ( $next === false ) {
				break;
			}
			$pos = $next + 6;
			if ( preg_match( '/\G([A-Za-z_][A-Za-z0-9_]*)\s*=\s*/', $body, $m, 0, $pos ) !== 1 ) {
				continue;
			}
			$const_name = $m[1];
			$pos       += strlen( $m[0] );
			$end        = $this->find_statement_end( $body, $pos );
			if ( $end === null ) {
				break;
			}
			$out[ $const_name ] = trim( substr( $body, $pos, $end - $pos ) );
			$pos                = $end + 1;
		}

		return $out;
	}

	private function capture_method_body( string $script, string $name ): string {
		$pos = strpos( $script, $name . '()' );
		if ( $pos === false ) {
			return '';
		}
		$brace = strpos( $script, '{', $pos );
		if ( $brace === false ) {
			return '';
		}
		$block = $this->capture_balanced( $script, $brace, '{', '}' );

		return strlen( $block ) > 2 ? substr( $block, 1, -1 ) : '';
	}

	private function find_statement_end( string $source, int $start ): ?int {
		$depth = 0;
		$len   = strlen( $source );
		for ( $i = $start; $i < $len; $i++ ) {
			if ( $this->is_quote_boundary( $source, $i ) ) {
				$i = $this->skip_string( $source, $i );
				continue;
			}
			$char = $source[ $i ];
			if ( $char === '(' || $char === '[' || $char === '{' ) {
				++$depth;
			} elseif ( $char === ')' || $char === ']' || $char === '}' ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( $char === ';' && $depth === 0 ) {
				return $i;
			}
		}

		return null;
	}

	private function wrapping_parens( string $expr ): bool {
		$depth = 0;
		$len   = strlen( $expr );
		for ( $i = 0; $i < $len; $i++ ) {
			if ( $this->is_quote_boundary( $expr, $i ) ) {
				$i = $this->skip_string( $expr, $i );
				continue;
			}
			$char = $expr[ $i ];
			if ( $char === '(' ) {
				++$depth;
			} elseif ( $char === ')' ) {
				--$depth;
				if ( $depth === 0 && $i < $len - 1 ) {
					return false;
				}
			}
		}

		return $depth === 0;
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function evaluate_condition( string $expr, array $state ): bool {
		$expr = trim( $expr );
		if ( str_starts_with( $expr, '!' ) ) {
			return ! $this->evaluate_condition( substr( $expr, 1 ), $state );
		}
		if ( preg_match( '/^s\.([A-Za-z0-9_]+)\s*===\s*(.+)$/', $expr, $m ) ) {
			return ( $state[ $m[1] ] ?? null ) === $this->evaluate_expression( $m[2], $state );
		}
		if ( preg_match( '/^s\.([A-Za-z0-9_]+)$/', $expr, $m ) ) {
			return ! empty( $state[ $m[1] ] );
		}

		return false;
	}

	/**
	 * @return array{condition:string, if:string, else:string}|null
	 */
	private function split_ternary( string $expr ): ?array {
		$depth = 0;
		$q_at  = null;
		$len   = strlen( $expr );
		for ( $i = 0; $i < $len; $i++ ) {
			$char = $expr[ $i ];
			if ( $this->is_quote_boundary( $expr, $i ) ) {
				$i = $this->skip_string( $expr, $i );
				continue;
			}
			if ( $char === '(' || $char === '[' || $char === '{' ) {
				++$depth;
			} elseif ( $char === ')' || $char === ']' || $char === '}' ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( $char === '?' && $depth === 0 && $q_at === null ) {
				$q_at = $i;
			} elseif ( $char === ':' && $depth === 0 && $q_at !== null ) {
				return array(
					'condition' => trim( substr( $expr, 0, $q_at ) ),
					'if'        => trim( substr( $expr, $q_at + 1, $i - $q_at - 1 ) ),
					'else'      => trim( substr( $expr, $i + 1 ) ),
				);
			}
		}

		return null;
	}

	/**
	 * @return array<int, string>
	 */
	private function split_top_level( string $expr, string $delimiter ): array {
		$parts = array();
		$buf   = '';
		$depth = 0;
		$len   = strlen( $expr );
		for ( $i = 0; $i < $len; $i++ ) {
			if ( $this->is_quote_boundary( $expr, $i ) ) {
				$end  = $this->skip_string( $expr, $i );
				$buf .= substr( $expr, $i, $end - $i + 1 );
				$i    = $end;
				continue;
			}
			$char = $expr[ $i ];
			if ( $char === '(' || $char === '[' || $char === '{' ) {
				++$depth;
			} elseif ( $char === ')' || $char === ']' || $char === '}' ) {
				$depth = max( 0, $depth - 1 );
			}
			if ( $char === $delimiter && $depth === 0 ) {
				$parts[] = trim( $buf );
				$buf     = '';
				continue;
			}
			$buf .= $char;
		}
		$parts[] = trim( $buf );

		return $parts;
	}

	private function capture_assignment( string $script, string $name ): ?string {
		$pos = strpos( $script, $name . ' =' );
		if ( $pos === false ) {
			return null;
		}
		$eq = strpos( $script, '=', $pos );
		if ( $eq === false ) {
			return null;
		}

		return $this->capture_literal_at( $script, $eq + 1 );
	}

	private function capture_method_return_object( string $script, string $name ): string {
		$pos = strpos( $script, $name . '()' );
		if ( $pos === false ) {
			return '';
		}
		$return = strpos( $script, 'return {', $pos );
		if ( $return === false ) {
			return '';
		}

		return $this->capture_balanced( $script, strpos( $script, '{', $return ), '{', '}' );
	}

	private function capture_method_return_array( string $script, string $name ): string {
		$pos = strpos( $script, $name . '()' );
		if ( $pos === false ) {
			return '';
		}
		$return = strpos( $script, 'return [', $pos );
		if ( $return === false ) {
			return '';
		}

		return $this->capture_balanced( $script, strpos( $script, '[', $return ), '[', ']' );
	}

	private function capture_literal_at( string $source, int $offset ): ?string {
		for ( $i = $offset, $len = strlen( $source ); $i < $len; $i++ ) {
			if ( ctype_space( $source[ $i ] ) ) {
				continue;
			}
			if ( $source[ $i ] === '{' ) {
				return $this->capture_balanced( $source, $i, '{', '}' );
			}
			if ( $source[ $i ] === '[' ) {
				return $this->capture_balanced( $source, $i, '[', ']' );
			}
		}

		return null;
	}

	private function capture_balanced( string $source, int $start, string $open, string $close ): string {
		$depth = 0;
		$len   = strlen( $source );
		for ( $i = $start; $i < $len; $i++ ) {
			if ( $this->is_quote_boundary( $source, $i ) ) {
				$i = $this->skip_string( $source, $i );
				continue;
			}
			if ( $source[ $i ] === $open ) {
				++$depth;
			} elseif ( $source[ $i ] === $close ) {
				--$depth;
				if ( $depth === 0 ) {
					return substr( $source, $start, $i - $start + 1 );
				}
			}
		}

		return '';
	}

	private function property_expression( string $object, string $key ): ?string {
		$needle = $key . ':';
		$pos    = strpos( $object, $needle );
		if ( $pos === false ) {
			return null;
		}
		$start = $pos + strlen( $needle );
		$depth = 0;
		$len   = strlen( $object );
		for ( $i = $start; $i < $len; $i++ ) {
			if ( $this->is_quote_boundary( $object, $i ) ) {
				$i = $this->skip_string( $object, $i );
				continue;
			}
			$char = $object[ $i ];
			if ( $char === '(' || $char === '[' || $char === '{' ) {
				++$depth;
			} elseif ( $char === ')' || $char === ']' || $char === '}' ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( $char === ',' && $depth === 0 ) {
				return trim( substr( $object, $start, $i - $start ) );
			}
		}

		return trim( rtrim( substr( $object, $start ), '}' ) );
	}

	private function parse_jsonish_literal( string $expr ): ?array {
		if ( preg_match( '/\b(?:this\.|React\.|=>|\bnew\b|\bfunction\b)/', $expr ) === 1 ) {
			return null;
		}
		$json = preg_replace_callback(
			"/'((?:\\\\.|[^'\\\\])*)'/s",
			function ( array $m ): string {
				return wp_json_encode( $this->decode_js_string( $m[1] ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ?: '""';
			},
			$expr
		);
		if ( ! is_string( $json ) ) {
			return null;
		}
		$json = preg_replace( '/([{,]\s*)([A-Za-z_][A-Za-z0-9_]*)\s*:/', '$1"$2":', $json ) ?? $json;
		$data = json_decode( $json, true );

		return is_array( $data ) ? $data : null;
	}

	private function extract_between( string $source, string $open_tag, string $close_tag ): string {
		$start = stripos( $source, $open_tag );
		$end   = stripos( $source, $close_tag );
		if ( $start === false || $end === false || $end <= $start ) {
			return '';
		}
		$gt = strpos( $source, '>', $start );
		if ( $gt === false || $gt >= $end ) {
			return '';
		}

		return substr( $source, $gt + 1, $end - $gt - 1 );
	}

	private function attr_placeholder( string $attrs, string $name ): string {
		if ( preg_match( '/\b' . preg_quote( $name, '/' ) . '="\{\{\s*([^}]+?)\s*\}\}"/i', $attrs, $m ) ) {
			return trim( (string) $m[1] );
		}

		return '';
	}

	private function plain_attr( string $attrs, string $name ): string {
		if ( preg_match( '/\b' . preg_quote( $name, '/' ) . '="([^"]*)"/i', $attrs, $m ) ) {
			return trim( (string) $m[1] );
		}

		return '';
	}

	private function decode_js_string( string $value ): string {
		// Decode `\uXXXX` before stripcslashes — otherwise `\u00b7` becomes literal "u00b7".
		$value = preg_replace_callback(
			'/\\\\u([0-9a-fA-F]{4})/',
			static function ( array $m ): string {
				$code = hexdec( $m[1] );
				if ( function_exists( 'mb_chr' ) ) {
					$char = mb_chr( $code, 'UTF-8' );

					return is_string( $char ) ? $char : '';
				}

				return html_entity_decode( '&#' . $code . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			},
			$value
		) ?? $value;

		return stripcslashes( $value );
	}

	private function is_quote_boundary( string $source, int $offset ): bool {
		$char = $source[ $offset ] ?? '';
		if ( $char !== '"' && $char !== '\'' ) {
			return false;
		}

		return $offset === 0 || $source[ $offset - 1 ] !== '\\';
	}

	private function skip_string( string $source, int $start ): int {
		$quote = $source[ $start ];
		$len   = strlen( $source );
		for ( $i = $start + 1; $i < $len; $i++ ) {
			if ( $source[ $i ] === $quote && $source[ $i - 1 ] !== '\\' ) {
				return $i;
			}
		}

		return $len - 1;
	}
}

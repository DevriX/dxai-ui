<?php
/**
 * Typography utilities: fonts, text size and colour, alignment, decoration,
 * leading, tracking, wrapping, lists and text indentation.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler\Tailwind\Utilities;

use DXAI_UI\Compiler\Tailwind\Candidate;
use DXAI_UI\Compiler\Tailwind\Theme;
use DXAI_UI\Compiler\Tailwind\Utility_Module;

/**
 * Everything Tailwind files under "Typography", plus the text-* colour scale.
 *
 * `text-*` is the one genuinely ambiguous namespace in Tailwind: the same
 * prefix carries alignment keywords, the font-size scale, the wrapping
 * keywords and the text colour registry. The order in {@see self::text()} is
 * the disambiguation contract — keywords, then bracketed data types, then the
 * font-size scale, then colour — and anything that matches none of them is
 * handed back as null so a later module can claim it.
 *
 * `text-shadow-*` is claimed by {@see self::text_shadow()} *before* that
 * disambiguation ever runs, which is the whole reason it is a separate branch:
 * read as a `text-*` value, `text-shadow-lg` is the font size `shadow-lg` and
 * `text-shadow-ara-navy` the colour `shadow-ara-navy`, and both miss silently
 * and look like a missing family rather than a mis-claim.
 */
final class Typography implements Utility_Module {

	/**
	 * Tailwind v4 default font-size scale with its paired line-height.
	 *
	 * The font-size is only a fallback: a design may redefine `--text-sm` in
	 * its `@theme` block, so the theme is asked first. The line-height has no
	 * accessor on the theme, so the default pairing is used whenever the token
	 * is one of the built-ins.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const TEXT_SCALE = array(
		'xs'   => array( '0.75rem', '1rem' ),
		'sm'   => array( '0.875rem', '1.25rem' ),
		'base' => array( '1rem', '1.5rem' ),
		'lg'   => array( '1.125rem', '1.75rem' ),
		'xl'   => array( '1.25rem', '1.75rem' ),
		'2xl'  => array( '1.5rem', '2rem' ),
		'3xl'  => array( '1.875rem', '2.25rem' ),
		'4xl'  => array( '2.25rem', '2.5rem' ),
		'5xl'  => array( '3rem', '1' ),
		'6xl'  => array( '3.75rem', '1' ),
		'7xl'  => array( '4.5rem', '1' ),
		'8xl'  => array( '6rem', '1' ),
		'9xl'  => array( '8rem', '1' ),
	);

	/** @var array<string, string> */
	private const FONT_WEIGHTS = array(
		'thin'       => '100',
		'extralight' => '200',
		'light'      => '300',
		'normal'     => '400',
		'medium'     => '500',
		'semibold'   => '600',
		'bold'       => '700',
		'extrabold'  => '800',
		'black'      => '900',
	);

	/** @var array<string, string> */
	private const FONT_FAMILIES = array(
		'sans'  => 'ui-sans-serif, system-ui, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji"',
		'serif' => 'ui-serif, Georgia, Cambria, "Times New Roman", Times, serif',
		'mono'  => 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace',
	);

	/** @var array<string, string> */
	private const LEADING = array(
		'none'    => '1',
		'tight'   => '1.25',
		'snug'    => '1.375',
		'normal'  => '1.5',
		'relaxed' => '1.625',
		'loose'   => '2',
	);

	/** @var array<string, string> */
	private const TRACKING = array(
		'tighter' => '-0.05em',
		'tight'   => '-0.025em',
		'normal'  => '0em',
		'wide'    => '0.025em',
		'wider'   => '0.05em',
		'widest'  => '0.1em',
	);

	/** @var array<string, string> */
	private const TEXT_ALIGN = array(
		'left'    => 'left',
		'center'  => 'center',
		'right'   => 'right',
		'justify' => 'justify',
		'start'   => 'start',
		'end'     => 'end',
	);

	/** @var array<string, string> */
	private const TEXT_WRAP = array(
		'wrap'    => 'wrap',
		'nowrap'  => 'nowrap',
		'balance' => 'balance',
		'pretty'  => 'pretty',
	);

	/** @var array<string, string> */
	private const TEXT_OVERFLOW = array(
		'ellipsis' => 'ellipsis',
		'clip'     => 'clip',
	);

	/** @var array<string, string> */
	private const WHITESPACE = array(
		'normal'       => 'normal',
		'nowrap'       => 'nowrap',
		'pre'          => 'pre',
		'pre-line'     => 'pre-line',
		'pre-wrap'     => 'pre-wrap',
		'break-spaces' => 'break-spaces',
	);

	/** @var array<string, string> */
	private const HYPHENS = array(
		'none'   => 'none',
		'manual' => 'manual',
		'auto'   => 'auto',
	);

	/** @var array<string, string> */
	private const VERTICAL_ALIGN = array(
		'baseline'    => 'baseline',
		'top'         => 'top',
		'middle'      => 'middle',
		'bottom'      => 'bottom',
		'text-top'    => 'text-top',
		'text-bottom' => 'text-bottom',
		'sub'         => 'sub',
		'super'       => 'super',
	);

	/** @var array<string, string> */
	private const DECORATION_STYLE = array(
		'solid'  => 'solid',
		'double' => 'double',
		'dotted' => 'dotted',
		'dashed' => 'dashed',
		'wavy'   => 'wavy',
	);

	/** @var array<string, string> */
	private const FONT_STRETCH = array(
		'normal'          => 'normal',
		'ultra-condensed' => 'ultra-condensed',
		'extra-condensed' => 'extra-condensed',
		'condensed'       => 'condensed',
		'semi-condensed'  => 'semi-condensed',
		'semi-expanded'   => 'semi-expanded',
		'expanded'        => 'expanded',
		'extra-expanded'  => 'extra-expanded',
		'ultra-expanded'  => 'ultra-expanded',
	);

	/**
	 * Tailwind composes the numeric variants out of five slots so that
	 * `ordinal tabular-nums` produces both, instead of the last class winning.
	 */
	private const NUMERIC_SLOTS = 'var(--tw-ordinal,) var(--tw-slashed-zero,) var(--tw-numeric-figure,) var(--tw-numeric-spacing,) var(--tw-numeric-fraction,)';

	/** @var array<string, string> */
	private const NUMERIC = array(
		'ordinal'            => '--tw-ordinal',
		'slashed-zero'       => '--tw-slashed-zero',
		'lining-nums'        => '--tw-numeric-figure',
		'oldstyle-nums'      => '--tw-numeric-figure',
		'proportional-nums'  => '--tw-numeric-spacing',
		'tabular-nums'       => '--tw-numeric-spacing',
		'diagonal-fractions' => '--tw-numeric-fraction',
		'stacked-fractions'  => '--tw-numeric-fraction',
	);

	/** Colour keywords Tailwind ships in its colour namespace. */
	private const COLOR_KEYWORDS = array(
		'inherit'     => 'inherit',
		'current'     => 'currentColor',
		'transparent' => 'transparent',
	);

	/**
	 * Tokens a shadow layer may carry that are neither geometry nor colour.
	 * v4 skips them while looking for the colour slot, so `inset 0 1px red`
	 * still recolours `red` rather than `inset`.
	 */
	private const SHADOW_KEYWORDS = array( 'inset', 'inherit', 'initial', 'revert', 'unset' );

	/**
	 * CSS-wide keywords that read as a whole `text-shadow` value rather than a
	 * colour, so `text-shadow-[unset]` sets the property and does not become a
	 * colour named `unset`. `inherit` sits here and not in COLOR_KEYWORDS
	 * because v4 declines to infer a colour from it inside brackets, even
	 * though the bare `text-shadow-inherit` spelling is a colour.
	 */
	private const SHADOW_NON_COLORS = array( 'inset', 'none', 'inherit', 'initial', 'revert', 'unset' );

	/**
	 * @return array<string, string>|null
	 */
	public function resolve( Candidate $candidate, Theme $theme ): ?array {
		$base = $candidate->base;

		$static = self::statics( $base );
		if ( $static !== null ) {
			return $static;
		}

		if ( isset( self::NUMERIC[ $base ] ) ) {
			return array(
				self::NUMERIC[ $base ] => $base,
				'font-variant-numeric' => self::NUMERIC_SLOTS,
			);
		}

		if ( str_starts_with( $base, 'font-stretch-' ) ) {
			return $this->font_stretch( substr( $base, 13 ), $candidate );
		}

		if ( str_starts_with( $base, 'font-' ) ) {
			return $this->font( substr( $base, 5 ), $candidate, $theme );
		}

		$shadow = self::after( $base, 'text-shadow' );
		if ( $shadow !== null ) {
			/*
			 * Ahead of the `text-` branch below on purpose: `text-shadow-lg`
			 * would otherwise be offered to the font-size scale as `shadow-lg`
			 * and then to the colour registry as `shadow-lg`, and
			 * `text-shadow-ara-navy` as the colour `shadow-ara-navy`. All
			 * three miss, so the mis-claim would read as a gap in this family.
			 *
			 * But only when it produces a rule. Upstream tries every reading of
			 * a class and keeps whichever compiles, so a design that declares
			 * `--color-shadow-blue` really does get `text-shadow-blue` as a
			 * text colour. Returning this branch's null outright took that
			 * away — a class the code resolved correctly before the family was
			 * added.
			 */
			$found = $this->text_shadow( $shadow, $candidate, $theme );
			if ( $found !== null ) {
				return $found;
			}
		}

		if ( str_starts_with( $base, 'text-' ) ) {
			return $this->text( substr( $base, 5 ), $candidate, $theme );
		}

		if ( str_starts_with( $base, 'line-clamp-' ) ) {
			return $this->line_clamp( substr( $base, 11 ), $candidate );
		}

		if ( str_starts_with( $base, 'leading-' ) ) {
			return $this->leading( substr( $base, 8 ), $candidate, $theme );
		}

		if ( str_starts_with( $base, 'tracking-' ) ) {
			return $this->tracking( substr( $base, 9 ), $candidate, $theme );
		}

		if ( str_starts_with( $base, 'decoration-' ) ) {
			return $this->decoration( substr( $base, 11 ), $candidate, $theme );
		}

		if ( str_starts_with( $base, 'underline-offset-' ) ) {
			return $this->underline_offset( substr( $base, 17 ), $candidate );
		}

		if ( str_starts_with( $base, 'whitespace-' ) ) {
			return $this->keyword( 'white-space', substr( $base, 11 ), self::WHITESPACE, $candidate );
		}

		if ( str_starts_with( $base, 'hyphens-' ) ) {
			// Safari needs the prefix, so upstream emits both properties.
			$hyphens = $this->keyword( 'hyphens', substr( $base, 8 ), self::HYPHENS, $candidate );

			return $hyphens === null
				? null
				: array( '-webkit-hyphens' => $hyphens['hyphens'] ) + $hyphens;
		}

		if ( str_starts_with( $base, 'align-' ) ) {
			return $this->keyword( 'vertical-align', substr( $base, 6 ), self::VERTICAL_ALIGN, $candidate );
		}

		if ( str_starts_with( $base, 'indent-' ) ) {
			return $this->indent( substr( $base, 7 ), $candidate, $theme );
		}

		if ( str_starts_with( $base, 'list-' ) ) {
			return $this->list_style( substr( $base, 5 ), $candidate );
		}

		if ( str_starts_with( $base, 'content-' ) ) {
			return $this->content( substr( $base, 8 ), $candidate );
		}

		return null;
	}

	/**
	 * @return array<string, string>
	 */
	public function keyframes(): array {
		return array();
	}

	/**
	 * Utilities with no value segment at all.
	 *
	 * @return array<string, string>|null
	 */
	private static function statics( string $base ): ?array {
		switch ( $base ) {
			case 'italic':
				return array( 'font-style' => 'italic' );
			case 'not-italic':
				return array( 'font-style' => 'normal' );
			case 'normal-nums':
				return array( 'font-variant-numeric' => 'normal' );
			case 'antialiased':
				return array(
					'-webkit-font-smoothing'  => 'antialiased',
					'-moz-osx-font-smoothing' => 'grayscale',
				);
			case 'subpixel-antialiased':
				return array(
					'-webkit-font-smoothing'  => 'auto',
					'-moz-osx-font-smoothing' => 'auto',
				);
			case 'underline':
				return array( 'text-decoration-line' => 'underline' );
			case 'overline':
				return array( 'text-decoration-line' => 'overline' );
			case 'line-through':
				return array( 'text-decoration-line' => 'line-through' );
			case 'no-underline':
				return array( 'text-decoration-line' => 'none' );
			case 'uppercase':
				return array( 'text-transform' => 'uppercase' );
			case 'lowercase':
				return array( 'text-transform' => 'lowercase' );
			case 'capitalize':
				return array( 'text-transform' => 'capitalize' );
			case 'normal-case':
				return array( 'text-transform' => 'none' );
			case 'truncate':
				return array(
					'overflow'      => 'hidden',
					'text-overflow' => 'ellipsis',
					'white-space'   => 'nowrap',
				);
			case 'break-normal':
				return array(
					'overflow-wrap' => 'normal',
					'word-break'    => 'normal',
				);
			case 'break-words':
				return array( 'overflow-wrap' => 'break-word' );
			case 'break-all':
				return array( 'word-break' => 'break-all' );
			case 'break-keep':
				return array( 'word-break' => 'keep-all' );
			case 'wrap-normal':
				return array( 'overflow-wrap' => 'normal' );
			case 'wrap-break-word':
				return array( 'overflow-wrap' => 'break-word' );
			case 'wrap-anywhere':
				return array( 'overflow-wrap' => 'anywhere' );
			case 'list-item':
				return array( 'display' => 'list-item' );
		}

		return null;
	}

	/**
	 * `font-*` is either the family namespace or the weight namespace. Weight
	 * names win because Tailwind registers `--font-weight-*` alongside
	 * `--font-*`, and a bracketed number is always a weight.
	 *
	 * @return array<string, string>|null
	 */
	private function font( string $token, Candidate $candidate, Theme $theme ): ?array {
		if ( $candidate->is_arbitrary() || self::is_paren( $token ) ) {
			$value = $candidate->is_arbitrary() ? $candidate->arbitrary_value() : self::paren_value( $token );
			if ( $value === '' ) {
				return null;
			}

			$hint = self::data_hint( $value );
			if ( $hint !== null ) {
				$value = $hint[1];
				if ( $hint[0] === 'family-name' ) {
					return array( 'font-family' => $value );
				}
				if ( $hint[0] === 'number' || $hint[0] === 'integer' ) {
					return self::weight( $value );
				}
			}

			if ( preg_match( '/^\d+(\.\d+)?$/', $value ) === 1 ) {
				return self::weight( $value );
			}

			return array( 'font-family' => $value );
		}

		$weight = $theme->value( 'font-weight', $token );
		if ( $weight === null && isset( self::FONT_WEIGHTS[ $token ] ) ) {
			$weight = self::FONT_WEIGHTS[ $token ];
		}
		if ( $weight !== null ) {
			return self::weight( $weight );
		}

		$family = $theme->value( 'font', $token );
		if ( $family === null && isset( self::FONT_FAMILIES[ $token ] ) ) {
			$family = self::FONT_FAMILIES[ $token ];
		}
		if ( $family !== null ) {
			return array( 'font-family' => $family );
		}

		return null;
	}

	/**
	 * @return array<string, string>
	 */
	private static function weight( string $value ): array {
		return array(
			'--tw-font-weight' => $value,
			'font-weight'      => $value,
		);
	}

	/**
	 * @return array<string, string>|null
	 */
	private function font_stretch( string $token, Candidate $candidate ): ?array {
		if ( $candidate->is_arbitrary() ) {
			$value = $candidate->arbitrary_value();

			return $value === '' ? null : array( 'font-stretch' => $value );
		}

		if ( self::is_paren( $token ) ) {
			return array( 'font-stretch' => self::paren_var( $token ) );
		}

		if ( isset( self::FONT_STRETCH[ $token ] ) ) {
			return array( 'font-stretch' => self::FONT_STRETCH[ $token ] );
		}

		if ( preg_match( '/^\d+(\.\d+)?%$/', $token ) === 1 ) {
			return array( 'font-stretch' => $token );
		}

		return null;
	}

	/**
	 * `text-shadow-*`: two families sharing one prefix.
	 *
	 * The size half (`text-shadow-lg`) writes the whole `text-shadow` list
	 * with every layer's colour behind `var(--tw-text-shadow-color, <its own>)`,
	 * and the colour half (`text-shadow-ara-navy`) writes only
	 * `--tw-text-shadow-color`, so the two compose: a colour class recolours a
	 * size class already on the element instead of replacing it.
	 *
	 * The order is v4's own — the three statics, then a bracketed value's
	 * declared or inferred data type, then the `--text-shadow-*` size
	 * namespace, then the colour registry — and it matters: `none` has to be
	 * intercepted before the namespace lookup because our theme carries a
	 * `text-shadow` `none` entry that v4 does not, and v4 emits the keyword.
	 *
	 * @return array<string, string>|null
	 */
	private function text_shadow( string $token, Candidate $candidate, Theme $theme ): ?array {
		if ( $candidate->negative ) {
			return null;
		}

		/*
		 * v4 registers these three as statics, and a static takes no modifier,
		 * so `text-shadow-none/50` is not a class. `inherit` is one of them
		 * rather than a plain colour lookup, which is why it alone among the
		 * keyword colours refuses the modifier.
		 */
		if ( 'none' === $token || 'initial' === $token || 'inherit' === $token ) {
			if ( $candidate->modifier !== '' ) {
				return null;
			}

			return 'none' === $token
				? array( 'text-shadow' => 'none' )
				: array( '--tw-text-shadow-color' => $token );
		}

		if ( $candidate->is_arbitrary() || self::is_paren( $token ) ) {
			$value = $candidate->is_arbitrary() ? $candidate->arbitrary_value() : self::paren_value( $token );
			if ( $value === '' ) {
				return null;
			}

			$hint = self::data_hint( $value );
			if ( $hint !== null ) {
				// Only `color:` diverts here; `length:` and the rest are lists.
				return $hint[0] === 'color'
					? $this->color_declaration( '--tw-text-shadow-color', $hint[1], $candidate, $theme )
					: $this->shadow_list( $hint[1], $candidate, $theme );
			}

			if ( self::is_paren( $token ) ) {
				/*
				 * Unlike `text-(--x)`, which is a colour, a bare custom
				 * property here is the whole shadow list: v4 cannot infer a
				 * colour from a `var()` and falls through to its list branch.
				 */
				return $this->shadow_list( self::paren_var( $token ), $candidate, $theme );
			}

			return self::is_shadow_color( $value )
				? $this->color_declaration( '--tw-text-shadow-color', $value, $candidate, $theme )
				: $this->shadow_list( $value, $candidate, $theme );
		}

		$size = $theme->value( 'text-shadow', $token );
		if ( $size !== null && $size !== '' ) {
			return $this->shadow_list( $size, $candidate, $theme );
		}

		$color = $this->lookup_color( $token, $theme );

		return $color === null
			? null
			: $this->color_declaration( '--tw-text-shadow-color', $color, $candidate, $theme );
	}

	/**
	 * A shadow list with every layer's colour moved behind the colour slot.
	 *
	 * @return array<string, string>|null
	 */
	private function shadow_list( string $value, Candidate $candidate, Theme $theme ): ?array {
		$layers = array();
		foreach ( Candidate::split_top_level( $value, ',' ) as $layer ) {
			$layer = trim( $layer );
			if ( $layer === '' ) {
				continue;
			}

			$layers[] = $this->compose_shadow_layer( $layer, $candidate->modifier, $theme );
		}

		if ( $layers === array() ) {
			return null;
		}

		$out = array();

		/*
		 * Upstream states the alpha as a property of its own before the
		 * shadow, so a later `text-shadow-<colour>` on the same element fades
		 * to the same degree. We fold the alpha into each layer as well, since
		 * a module cannot emit upstream's `@supports` twin — but the property
		 * still has to be here, or the two sides disagree on what the class
		 * declares.
		 */
		if ( $candidate->modifier !== '' ) {
			$alpha = self::alpha( $candidate->modifier );
			if ( $alpha !== '' ) {
				$out['--tw-text-shadow-alpha'] = $theme->alpha_value( $alpha );
			}
		}

		$out['text-shadow'] = implode( ', ', $layers );

		return $out;
	}

	/**
	 * Rewrite one layer so its colour reads `var(--tw-text-shadow-color, X)`,
	 * X being the layer's own colour with any `/opacity` folded in — the slot
	 * is what lets a later colour class win over a size class.
	 *
	 * A layer needs two lengths before v4 will treat it as geometry at all;
	 * with fewer it cannot say where the colour sits and hands the layer back
	 * untouched, which is how `text-shadow-(--x)` stays a plain `var(--x)` and
	 * `text-shadow-[unset]` stays the keyword.
	 */
	private function compose_shadow_layer( string $layer, string $modifier, Theme $theme ): string {
		$tokens  = self::tokenize( $layer );
		$lengths = 0;
		$index   = null;

		foreach ( $tokens as $position => $token ) {
			if ( in_array( strtolower( $token ), self::SHADOW_KEYWORDS, true ) ) {
				continue;
			}

			// Geometry is anything starting with a figure; `calc()` is not.
			if ( preg_match( '/^-?(?:\d+|\.\d+)/', $token ) === 1 ) {
				++$lengths;
				continue;
			}

			if ( $index === null ) {
				$index = $position;
			}
		}

		if ( $lengths < 2 ) {
			return $layer;
		}

		// A layer with no colour of its own inherits one, as plain CSS would.
		$color = $index === null ? 'currentcolor' : $tokens[ $index ];
		/*
		 * A shadow's alpha is replaced, not composited — see
		 * Theme::with_absolute_alpha(). Every built-in text-shadow token
		 * already carries alpha, so using the compositing form here made
		 * `text-shadow-lg/50` ten times too faint.
		 */
		$alpha = self::alpha( $modifier );
		$slot  = 'var(--tw-text-shadow-color, '
			. ( $alpha === '' ? $color : $theme->with_absolute_alpha( $color, $alpha ) )
			. ')';

		if ( $index === null ) {
			$tokens[] = $slot;
		} else {
			$tokens[ $index ] = $slot;
		}

		return implode( ' ', $tokens );
	}

	/**
	 * The `text-*` disambiguation: alignment and wrapping keywords, then the
	 * declared data type of a bracketed value, then the font-size scale, then
	 * the colour registry. Unknown tokens fall through to null so a later
	 * module can still claim them.
	 *
	 * `text-shadow-*` never arrives here — the dispatcher takes it first — and
	 * must not, since `shadow-lg` is neither a size token nor a colour name.
	 *
	 * @return array<string, string>|null
	 */
	private function text( string $token, Candidate $candidate, Theme $theme ): ?array {
		if ( isset( self::TEXT_ALIGN[ $token ] ) && $candidate->modifier === '' ) {
			return array( 'text-align' => self::TEXT_ALIGN[ $token ] );
		}

		if ( isset( self::TEXT_WRAP[ $token ] ) && $candidate->modifier === '' ) {
			return array( 'text-wrap' => self::TEXT_WRAP[ $token ] );
		}

		if ( isset( self::TEXT_OVERFLOW[ $token ] ) && $candidate->modifier === '' ) {
			return array( 'text-overflow' => self::TEXT_OVERFLOW[ $token ] );
		}

		if ( $candidate->is_arbitrary() || self::is_paren( $token ) ) {
			$value = $candidate->is_arbitrary() ? $candidate->arbitrary_value() : self::paren_value( $token );
			if ( $value === '' ) {
				return null;
			}

			$hint = self::data_hint( $value );
			if ( $hint !== null ) {
				if ( $hint[0] === 'color' ) {
					return $this->color_declaration( 'color', $hint[1], $candidate, $theme );
				}

				return $this->size_declaration( $hint[1], null, $candidate, $theme );
			}

			if ( self::is_paren( $token ) ) {
				// A bare custom property on `text-*` is a colour in Tailwind v4.
				return $this->color_declaration( 'color', self::paren_var( $token ), $candidate, $theme );
			}

			if ( self::is_length( $value ) ) {
				return $this->size_declaration( $value, null, $candidate, $theme );
			}

			return $this->color_declaration( 'color', $value, $candidate, $theme );
		}

		$size = $theme->value( 'text', $token );
		$pair = self::TEXT_SCALE[ $token ] ?? null;
		if ( $size === null && $pair !== null ) {
			$size = $pair[0];
		}
		if ( $size !== null ) {
			return $this->size_declaration( $size, $pair === null ? null : $pair[1], $candidate, $theme );
		}

		$color = $this->lookup_color( $token, $theme );
		if ( $color !== null ) {
			return $this->color_declaration( 'color', $color, $candidate, $theme );
		}

		return null;
	}

	/**
	 * Font size plus its line-height. The paired line-height is emitted behind
	 * `--tw-leading` exactly as Tailwind v4 does, so a `leading-*` class on the
	 * same element still wins regardless of which rule the cascade sees last.
	 *
	 * @return array<string, string>
	 */
	private function size_declaration( string $size, ?string $paired, Candidate $candidate, Theme $theme ): array {
		if ( $candidate->negative ) {
			$size = self::negate( $size );
		}

		$out = array( 'font-size' => $size );

		if ( $candidate->modifier !== '' ) {
			$leading = $this->leading_value( $candidate->modifier, $theme );
			if ( $leading !== null ) {
				$out['--tw-leading'] = $leading;
				$out['line-height']  = $leading;

				return $out;
			}
		}

		if ( $paired !== null ) {
			/*
			 * v3 has no `--tw-leading`: its `text-2xl` writes `line-height`
			 * outright, so which of `text-2xl` and `leading-tight` wins is
			 * decided by source order — and a responsive `md:text-6xl`, being
			 * inside a media query, comes last and beats an unprefixed
			 * `leading-[1.08]`. Under v4's indirection the `leading` always
			 * wins. Measured against a v3 build of the same page that was a
			 * heading 4.8px taller and 10px of document height.
			 */
			$out['line-height'] = $theme->is_legacy() ? $paired : 'var(--tw-leading, ' . $paired . ')';
		}

		return $out;
	}

	/**
	 * @return array<string, string>|null
	 */
	private function leading( string $token, Candidate $candidate, Theme $theme ): ?array {
		if ( $candidate->is_arbitrary() ) {
			$value = $candidate->arbitrary_value();
			if ( $value === '' ) {
				return null;
			}
		} elseif ( self::is_paren( $token ) ) {
			$value = self::paren_var( $token );
		} elseif ( 'px' === $token ) {
			/*
			 * v4 builds `leading-*` on its spacing helper, and that helper
			 * registers `<name>-px` as a literal `1px` static beside the
			 * numeric scale — `px` is not a token in the spacing namespace.
			 * Our resolution only reaches the spacing scale for a bare number,
			 * so `px` never got there. Being a static also means it exists
			 * only here: `text-sm/px` is not a class, so the shared
			 * {@see self::leading_value()} must not learn it.
			 */
			$value = '1px';
		} else {
			$value = $this->leading_value( $token, $theme );
		}

		if ( $value === null || $value === '' ) {
			return null;
		}

		if ( $candidate->negative ) {
			$value = self::negate( $value );
		}

		return array(
			'--tw-leading' => $value,
			'line-height'  => $value,
		);
	}

	/**
	 * Named leading token, spacing-scale number, or bracketed literal. Shared
	 * with the `text-sm/6` line-height modifier.
	 */
	private function leading_value( string $token, Theme $theme ): ?string {
		$token = self::unbracket( $token );
		if ( $token === '' ) {
			return null;
		}

		if ( str_starts_with( $token, '--' ) ) {
			return 'var(' . $token . ')';
		}

		$named = $theme->value( 'leading', $token );
		if ( $named === null && isset( self::LEADING[ $token ] ) ) {
			$named = self::LEADING[ $token ];
		}
		if ( $named !== null ) {
			return $named;
		}

		if ( preg_match( '/^\d+(\.\d+)?$/', $token ) === 1 ) {
			$spacing = $theme->spacing( $token );
			if ( $spacing !== null ) {
				return $spacing;
			}
		}

		if ( self::is_length( $token ) || preg_match( '/^\d*\.\d+$/', $token ) === 1 ) {
			return $token;
		}

		return null;
	}

	/**
	 * `line-clamp-3`, `line-clamp-none`, `line-clamp-[5]`.
	 *
	 * The dispatcher called this before it existed, so every `line-clamp-*`
	 * class took the whole conversion down with a fatal. None of the designs on
	 * hand used one; running the parity harness over Tailwind's full utility
	 * surface rather than one design's few hundred classes found it on the
	 * first attempt.
	 *
	 * @return array<string, string>|null
	 */
	private function line_clamp( string $token, Candidate $candidate ): ?array {
		if ( $candidate->negative || $candidate->modifier !== '' ) {
			return null;
		}

		if ( $token === 'none' ) {
			return array(
				'overflow'            => 'visible',
				'display'             => 'block',
				'-webkit-box-orient'  => 'horizontal',
				'-webkit-line-clamp'  => 'unset',
			);
		}

		if ( $candidate->is_arbitrary() ) {
			$value = $candidate->arbitrary_value();
		} elseif ( self::is_paren( $token ) ) {
			$value = self::paren_var( $token );
		} else {
			$value = preg_match( '/^\d+$/', $token ) === 1 ? $token : null;
		}

		if ( $value === null || $value === '' ) {
			return null;
		}

		return array(
			'overflow'           => 'hidden',
			'display'            => '-webkit-box',
			'-webkit-box-orient' => 'vertical',
			'-webkit-line-clamp' => $value,
		);
	}

	/**
	 * @return array<string, string>|null
	 */
	private function tracking( string $token, Candidate $candidate, Theme $theme ): ?array {
		if ( $candidate->is_arbitrary() ) {
			$value = $candidate->arbitrary_value();
		} elseif ( self::is_paren( $token ) ) {
			$value = self::paren_var( $token );
		} else {
			$value = $theme->value( 'tracking', $token );
			if ( $value === null && isset( self::TRACKING[ $token ] ) ) {
				$value = self::TRACKING[ $token ];
			}
		}

		if ( $value === null || $value === '' ) {
			return null;
		}

		if ( $candidate->negative ) {
			$value = self::negate( $value );
		}

		return array(
			'--tw-tracking'  => $value,
			'letter-spacing' => $value,
		);
	}

	/**
	 * `decoration-*` carries three data types: style keywords, thickness
	 * (keywords, integers and lengths) and colour.
	 *
	 * @return array<string, string>|null
	 */
	private function decoration( string $token, Candidate $candidate, Theme $theme ): ?array {
		if ( isset( self::DECORATION_STYLE[ $token ] ) && $candidate->modifier === '' ) {
			return array( 'text-decoration-style' => self::DECORATION_STYLE[ $token ] );
		}

		if ( $candidate->modifier === '' && ( 'auto' === $token || 'from-font' === $token ) ) {
			return array( 'text-decoration-thickness' => $token );
		}

		if ( $candidate->is_arbitrary() || self::is_paren( $token ) ) {
			$value = $candidate->is_arbitrary() ? $candidate->arbitrary_value() : self::paren_value( $token );
			if ( $value === '' ) {
				return null;
			}

			$hint = self::data_hint( $value );
			if ( $hint !== null ) {
				if ( $hint[0] === 'color' ) {
					return $this->color_declaration( 'text-decoration-color', $hint[1], $candidate, $theme );
				}

				return array( 'text-decoration-thickness' => $hint[1] );
			}

			if ( self::is_paren( $token ) ) {
				return $this->color_declaration( 'text-decoration-color', self::paren_var( $token ), $candidate, $theme );
			}

			if ( self::is_length( $value ) ) {
				return array( 'text-decoration-thickness' => $value );
			}

			return $this->color_declaration( 'text-decoration-color', $value, $candidate, $theme );
		}

		if ( preg_match( '/^\d+$/', $token ) === 1 && $candidate->modifier === '' ) {
			return array( 'text-decoration-thickness' => $token . 'px' );
		}

		$color = $this->lookup_color( $token, $theme );
		if ( $color !== null ) {
			return $this->color_declaration( 'text-decoration-color', $color, $candidate, $theme );
		}

		return null;
	}

	/**
	 * @return array<string, string>|null
	 */
	private function underline_offset( string $token, Candidate $candidate ): ?array {
		if ( $candidate->is_arbitrary() ) {
			$value = $candidate->arbitrary_value();
		} elseif ( self::is_paren( $token ) ) {
			$value = self::paren_var( $token );
		} elseif ( 'auto' === $token ) {
			return array( 'text-underline-offset' => 'auto' );
		} elseif ( preg_match( '/^\d+$/', $token ) === 1 ) {
			$value = $token . 'px';
		} else {
			return null;
		}

		if ( $value === '' ) {
			return null;
		}

		if ( $candidate->negative ) {
			$value = self::negate( $value );
		}

		return array( 'text-underline-offset' => $value );
	}

	/**
	 * @return array<string, string>|null
	 */
	private function indent( string $token, Candidate $candidate, Theme $theme ): ?array {
		if ( $candidate->is_arbitrary() ) {
			$value = $candidate->arbitrary_value();
		} elseif ( self::is_paren( $token ) ) {
			$value = self::paren_var( $token );
		} else {
			$value = $theme->spacing( $token );
		}

		if ( $value === null || $value === '' ) {
			return null;
		}

		if ( $candidate->negative ) {
			$value = self::negate( $value );
		}

		return array( 'text-indent' => $value );
	}

	/**
	 * `list-*` covers position, marker type and marker image.
	 *
	 * @return array<string, string>|null
	 */
	private function list_style( string $token, Candidate $candidate ): ?array {
		if ( 'inside' === $token || 'outside' === $token ) {
			return array( 'list-style-position' => $token );
		}

		if ( str_starts_with( $token, 'image-' ) ) {
			$image = substr( $token, 6 );

			if ( 'none' === $image ) {
				return array( 'list-style-image' => 'none' );
			}
			if ( $candidate->is_arbitrary() ) {
				$value = $candidate->arbitrary_value();

				return $value === '' ? null : array( 'list-style-image' => $value );
			}
			if ( self::is_paren( $image ) ) {
				return array( 'list-style-image' => self::paren_var( $image ) );
			}

			return null;
		}

		if ( $candidate->is_arbitrary() ) {
			$value = $candidate->arbitrary_value();

			return $value === '' ? null : array( 'list-style-type' => $value );
		}

		if ( self::is_paren( $token ) ) {
			return array( 'list-style-type' => self::paren_var( $token ) );
		}

		$types = array(
			'none'        => 'none',
			'disc'        => 'disc',
			'decimal'     => 'decimal',
			'circle'      => 'circle',
			'square'      => 'square',
			'roman'       => 'upper-roman',
			'upper-roman' => 'upper-roman',
			'lower-roman' => 'lower-roman',
			'upper-alpha' => 'upper-alpha',
			'lower-alpha' => 'lower-alpha',
		);

		return isset( $types[ $token ] ) ? array( 'list-style-type' => $types[ $token ] ) : null;
	}

	/**
	 * Only `content-none` and arbitrary content belong here; `content-center`
	 * and the rest of that family are align-content in the flex/grid module.
	 *
	 * @return array<string, string>|null
	 */
	private function content( string $token, Candidate $candidate ): ?array {
		if ( 'none' === $token ) {
			return array(
				'--tw-content' => 'none',
				'content'      => 'none',
			);
		}

		if ( $candidate->is_arbitrary() ) {
			$value = $candidate->arbitrary_value();
			if ( $value === '' ) {
				return null;
			}

			return array(
				'--tw-content' => $value,
				'content'      => $value,
			);
		}

		if ( self::is_paren( $token ) ) {
			$value = self::paren_var( $token );

			return array(
				'--tw-content' => $value,
				'content'      => $value,
			);
		}

		return null;
	}

	/**
	 * Shared shape for the plain keyword namespaces, with arbitrary support.
	 *
	 * @param array<string, string> $map
	 *
	 * @return array<string, string>|null
	 */
	private function keyword( string $property, string $token, array $map, Candidate $candidate ): ?array {
		if ( isset( $map[ $token ] ) ) {
			return array( $property => $map[ $token ] );
		}

		if ( $candidate->is_arbitrary() ) {
			$value = $candidate->arbitrary_value();
			if ( $value === '' ) {
				return null;
			}
			if ( $candidate->negative ) {
				$value = self::negate( $value );
			}

			return array( $property => $value );
		}

		if ( self::is_paren( $token ) ) {
			return array( $property => self::paren_var( $token ) );
		}

		return null;
	}

	/**
	 * Apply the `/opacity` modifier, if any, to an already resolved colour.
	 *
	 * @return array<string, string>
	 */
	private function color_declaration( string $property, string $color, Candidate $candidate, Theme $theme ): array {
		return array( $property => $this->faded( $color, $candidate->modifier, $theme ) );
	}

	/**
	 * One resolved colour with an `/opacity` modifier folded in. Split out of
	 * {@see self::color_declaration()} because a shadow list needs the same
	 * fade applied inside a value rather than to a whole declaration.
	 *
	 * Upstream instead keeps the modifier in `--tw-text-shadow-alpha` and
	 * repeats the declaration under `@supports (color: color-mix(in lab, ...))`
	 * for a wide-gamut mix. A module answers with one declaration block and
	 * cannot express that, so alpha is resolved here, exactly as every other
	 * colour family in this file does.
	 */
	private function faded( string $color, string $modifier, Theme $theme ): string {
		if ( $modifier === '' ) {
			return $color;
		}

		$alpha = self::alpha( $modifier );

		return $alpha === '' ? $color : $theme->with_alpha( $color, $alpha );
	}

	/**
	 * Resolve a colour token through the theme, falling back to the three
	 * keyword colours Tailwind keeps in its colour namespace.
	 */
	private function lookup_color( string $token, Theme $theme ): ?string {
		$color = $theme->color( $token );
		if ( $color !== null ) {
			return $color;
		}

		return self::COLOR_KEYWORDS[ $token ] ?? null;
	}

	/**
	 * Normalise an opacity modifier to the percentage figure `with_alpha()`
	 * expects: `70` stays `70`, `[0.06]` becomes `6`, `[50%]` becomes `50`.
	 */
	private static function alpha( string $modifier ): string {
		$value = Candidate::decode_arbitrary( self::unbracket( $modifier ) );
		if ( $value === '' ) {
			return '';
		}

		if ( str_ends_with( $value, '%' ) ) {
			$value = substr( $value, 0, -1 );
		}

		if ( preg_match( '/^\d*\.\d+$/', $value ) === 1 ) {
			$percent = (float) $value * 100.0;

			return rtrim( rtrim( number_format( $percent, 4, '.', '' ), '0' ), '.' );
		}

		return $value;
	}

	/**
	 * Tailwind lets an arbitrary value declare its own data type, as in
	 * `text-[length:var(--x)]` or `font-[family-name:var(--x)]`.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private static function data_hint( string $value ): ?array {
		if ( preg_match( '/^(length|color|family-name|number|integer|percentage|image|url|angle)\s*:\s*(.+)$/is', $value, $m ) !== 1 ) {
			return null;
		}

		$type = strtolower( $m[1] );
		if ( 'percentage' === $type ) {
			$type = 'length';
		}

		return array( $type, self::wrap_var( trim( $m[2] ) ) );
	}

	/**
	 * The value segment of a utility, or null when the class belongs to some
	 * other namespace. Bare `text-shadow` yields '', `text-shadow-lg` yields
	 * 'lg'. Used where a prefix must be claimed whole, before a shorter prefix
	 * of it gets a chance to read the rest as its own value.
	 */
	private static function after( string $base, string $prefix ): ?string {
		if ( $base === $prefix ) {
			return '';
		}

		return str_starts_with( $base, $prefix . '-' )
			? substr( $base, strlen( $prefix ) + 1 )
			: null;
	}

	/**
	 * Top-level tokens of a value, so `rgb(0 0 0 / 0.1)` stays one token.
	 *
	 * @return array<int, string>
	 */
	private static function tokenize( string $value ): array {
		$tokens = array();
		foreach ( Candidate::split_top_level( $value, ' ' ) as $token ) {
			$token = trim( $token );
			if ( $token !== '' ) {
				$tokens[] = $token;
			}
		}

		return $tokens;
	}

	/**
	 * True when a whole arbitrary value is a colour rather than a shadow list.
	 *
	 * Tested by exclusion the way v4 infers the data type: one token, not a
	 * CSS-wide keyword and not a figure, so hex triplets, colour functions and
	 * every named CSS colour pass without a lookup table. A `var()` fails on
	 * purpose — v4 will not call one a colour, which is what sends
	 * `text-shadow-[var(--x)]` down the list branch.
	 */
	private static function is_shadow_color( string $value ): bool {
		$tokens = self::tokenize( $value );
		if ( count( $tokens ) !== 1 ) {
			return false;
		}

		$token = strtolower( $tokens[0] );

		if ( in_array( $token, self::SHADOW_NON_COLORS, true ) ) {
			return false;
		}

		if ( 'currentcolor' === $token || 'transparent' === $token ) {
			return true;
		}

		if ( preg_match( '/^#[0-9a-f]{3,8}$/', $token ) === 1 ) {
			return true;
		}

		if ( preg_match( '/^(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color|color-mix|light-dark)\(/', $token ) === 1 ) {
			return true;
		}

		return preg_match( '/^[a-z]+$/', $token ) === 1;
	}

	/**
	 * True for anything that reads as a CSS length, percentage or size
	 * calculation rather than a colour.
	 */
	private static function is_length( string $value ): bool {
		$value = trim( $value );
		if ( $value === '' ) {
			return false;
		}

		if ( preg_match( '/^-?(\d+(\.\d+)?|\.\d+)(px|rem|em|%|pt|pc|in|q|cm|mm|ex|ch|cap|ic|lh|rlh|vw|vh|vi|vb|vmin|vmax|svw|svh|lvw|lvh|dvw|dvh)$/i', $value ) === 1 ) {
			return true;
		}

		if ( preg_match( '/^-?(\d+(\.\d+)?|\.\d+)$/', $value ) === 1 ) {
			return true;
		}

		if ( preg_match( '/^(calc|clamp|min|max|round|mod|rem)\s*\(/i', $value ) === 1 ) {
			return true;
		}

		return false;
	}

	/** True for the `(--custom-property)` shorthand Tailwind v4 accepts. */
	private static function is_paren( string $token ): bool {
		return str_starts_with( $token, '(' ) && str_ends_with( $token, ')' );
	}

	/** Contents of the `(...)` shorthand, decoded. */
	private static function paren_value( string $token ): string {
		return Candidate::decode_arbitrary( substr( $token, 1, -1 ) );
	}

	/** The `(...)` shorthand wrapped as a `var()` reference. */
	private static function paren_var( string $token ): string {
		$value = self::paren_value( $token );
		if ( $value === '' ) {
			return '';
		}

		$hint = self::data_hint( $value );
		if ( $hint !== null ) {
			return $hint[1];
		}

		return self::wrap_var( $value );
	}

	/** A bare `--custom-property` becomes a `var()` reference. */
	private static function wrap_var( string $value ): string {
		return str_starts_with( $value, '--' ) ? 'var(' . $value . ')' : $value;
	}

	/** Strip one layer of square brackets, if present. */
	private static function unbracket( string $value ): string {
		if ( str_starts_with( $value, '[' ) && str_ends_with( $value, ']' ) ) {
			return substr( $value, 1, -1 );
		}

		return $value;
	}

	/**
	 * Negate a resolved value, keeping the literal form where a leading minus
	 * is valid CSS and wrapping in calc() when it is not.
	 */
	private static function negate( string $value ): string {
		if ( '0' === $value || '0px' === $value || '0rem' === $value ) {
			return $value;
		}

		if ( str_starts_with( $value, '-' ) ) {
			return substr( $value, 1 );
		}

		if ( preg_match( '/^(\d+(\.\d+)?|\.\d+)[a-z%]*$/i', $value ) === 1 ) {
			return '-' . $value;
		}

		return 'calc(' . $value . ' * -1)';
	}
}

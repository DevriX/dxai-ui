<?php
/**
 * A call to action: core's Buttons, in the theme's button style.
 *
 * @package DXAI_UI\Blocks\Native
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks\Native;

use DXAI_UI\Support\Color_Math;
use DXAI_UI\Theme\Theme_Binding;
use DXAI_UI\Theme\Theme_Buttons;
use DXAI_UI\Theme\Theme_Palette;

/**
 * A `dxai-ui/link` that is a button of the design — padding and a fill or a border — becomes core's Buttons block
 * with one Button per call to action, wearing the theme's own button style: the filled one `primary-button`, the
 * outlined or white one `secondary-button`, a small filled one `small-primary-button`. That is how the theme's own
 * pages are built (a Buttons block, its Button blocks, `is-style-…`), so the button is edited in the Button block's
 * controls and looks like every other button of the site — and follows the theme's colours and radius when they
 * change. It is not a copy of the design's button: its fill, padding, radius, shadow and type come from the theme.
 *
 * Adjacent calls to action in one container (the hero's two) become one Buttons block, as the theme's pages have
 * them. Only the design's layout stays on the button (margin, width, position) — those classes and declarations
 * ride the Button block's wrapper; what the theme's style decides does not.
 *
 * Not a button: a menu item or a control (data attributes, ARIA), a link without a fill or a border, the skip link.
 */
final class Button extends Converter {

	private const KNOWN = array( 'url', 'text', 'className', 'dxaiCss', 'target', 'rel', 'title', 'suffix', 'suffixClass', 'suffixTight' );

	/** The design's classes that place the button and stay on it. */
	private const KEEP_CLASS = '/^(?:(?:xs|sm|md|lg|xl)-)?(?:m[trblxy]?-[\w.-]+|w-(?:full|auto|fit|[\w.-]+)|min-w-[\w.-]+|max-w-[\w.-]+|self-[\w-]+|grow|shrink|flex-none|flex-1|order-[\w-]+|d-none|d-block|hidden)$/';

	/** The declarations that place it. */
	private const KEEP_PROP = '/^(?:margin(?:-[a-z]+)?|width|min-width|max-width|align-self|flex|flex-grow|flex-shrink|order|z-index)$/';

	public function id(): string {
		return 'button';
	}

	public function label(): string {
		return __( 'Calls to action → Buttons', 'dxai-ui' );
	}

	public function source(): string {
		return 'dxai-ui/link';
	}

	public function target(): string {
		return 'core/buttons';
	}

	public function available(): bool {
		return parent::available() && \WP_Block_Type_Registry::get_instance()->is_registered( 'core/button' ) && Theme_Buttons::has( 'primary-button' );
	}

	/** One call to action is converted with its neighbours (runs()), never alone. */
	public function convert( array $block, ?array $parent = null ): ?array {
		return null;
	}

	public function runs( array $siblings, ?array $parent, ?array $content = null ): array {
		$out = array();
		$run = array();
		$flush = function () use ( &$run, &$out, $parent ): void {
			if ( $run !== array() ) {
				if ( ! self::chips( $run, $parent ) ) {
					$out[] = array(
						'start' => $run[0]['index'],
						'end'   => $run[ count( $run ) - 1 ]['index'],
						'block' => $this->buttons_block( $run, $parent ),
					);
				}
				$run = array();
			}
		};
		$prev = -2;
		foreach ( array_values( $siblings ) as $i => $block ) {
			$cta = is_array( $block ) ? $this->cta( $block ) : null;
			if ( $cta === null || ( $run !== array() && ! self::adjacent( $content, $prev, $i ) ) ) {
				$flush();
			}
			if ( $cta !== null ) {
				$cta['index'] = $i;
				$run[]        = $cta;
				$prev         = $i;
			}
		}
		$flush();

		return $out;
	}

	/**
	 * Whether nothing but whitespace lies between two siblings (parent's innerContent; null for the top level,
	 * where the blocks in between are checked by the caller).
	 *
	 * @param array<int, string|null>|null $content
	 */
	private static function adjacent( ?array $content, int $a, int $b ): bool {
		if ( $b !== $a + 1 ) {
			return false;
		}
		if ( $content === null ) {
			return true;
		}
		$seen = -1;
		$hold = '';
		foreach ( $content as $chunk ) {
			if ( $chunk === null ) {
				++$seen;
				continue;
			}
			if ( $seen === $a && trim( $chunk ) !== '' ) {
				$hold = $chunk;
			}
		}

		return $hold === '';
	}

	/**
	 * What a call to action needs, or null when the block is not one.
	 *
	 * @param array<string, mixed> $block
	 * @return array{url:string, text:string, style:string, target:string, rel:string, title:string, classes:string, css:string, align:string, fill:string}|null
	 */
	private function cta( array $block ): ?array {
		if ( ( $block['blockName'] ?? '' ) !== $this->source() || ( $block['innerBlocks'] ?? array() ) !== array() ) {
			return null;
		}
		$a = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$url = (string) ( $a['url'] ?? '' );
		$text = trim( (string) ( $a['text'] ?? '' ) );
		if ( $url === '' || $text === '' || ! self::only( $a, self::KNOWN ) || ! empty( $a['hasInner'] ) ) {
			return null;
		}
		foreach ( array( 'url', 'text', 'title', 'target', 'rel', 'suffix' ) as $key ) {
			if ( self::hazard( (string) ( $a[ $key ] ?? '' ) ) ) {
				return null;
			}
		}
		$class = ' ' . trim( (string) ( $a['className'] ?? '' ) ) . ' ';
		$css   = (string) ( $a['dxaiCss'] ?? '' );
		// The skip link, anything positioned out of the flow, and the hooks of the design's own controls.
		if ( preg_match( '/\s(?:absolute|fixed|sticky)\s/', $class ) === 1 || preg_match( '/-\d{3,}px|(?:^|;)\s*position\s*:\s*(?:absolute|fixed)/', $css ) === 1
			|| preg_match( '/\sdxai-(?:toggle|on|set|open|close|tab|reveal)[\w-]*\s/', $class ) === 1 ) {
			return null;
		}
		$look = self::look( $class, $css );
		if ( $look === null ) {
			return null;
		}
		$style = $look;
		if ( $look === 'primary-button' && preg_match( '/\stext-(?:1[0-4](?:-5)?|xs|sm)\s/', $class ) === 1 ) {
			$style = 'small-primary-button';
		}
		if ( ! Theme_Buttons::has( $style ) ) {
			$style = Theme_Buttons::has( 'primary-button' ) ? ( $look === 'secondary-button' && Theme_Buttons::has( 'secondary-button' ) ? 'secondary-button' : 'primary-button' ) : '';
		}
		if ( $style === '' ) {
			return null;
		}
		$suffix = trim( (string) ( $a['suffix'] ?? '' ) );

		return array(
			'url'     => $url,
			'text'    => $suffix !== '' ? $text . ' ' . $suffix : $text,
			'style'   => $style,
			'target'  => (string) ( $a['target'] ?? '' ),
			'rel'     => (string) ( $a['rel'] ?? '' ),
			'title'   => (string) ( $a['title'] ?? '' ),
			'classes' => self::keep_classes( (string) ( $a['className'] ?? '' ) ),
			'css'     => self::keep_css( $css ),
			'align'   => '',
			// The design's own fill, for a button the theme's style would lose on its band (on_band()).
			'fill'    => \DXAI_UI\Compiler\Color_Usage::background_of( $a, (int) ( self::$context['home'] ?? 0 ) ),
		);
	}

	/** Which of the theme's styles the design's button is: filled → primary, white or outlined → secondary. */
	private static function look( string $class, string $css ): ?string {
		$padded = preg_match( '/\s(?:p[xy]?|p[trbl])-\d/', $class ) === 1 || preg_match( '/(?:^|;)\s*padding(?:-[a-z]+)?\s*:/', $css ) === 1;
		if ( ! $padded ) {
			return null;
		}
		$transparent = preg_match( '/(?:^|;)\s*background(?:-color)?\s*:\s*(?:transparent|none)/i', $css ) === 1;
		$white       = preg_match( '/\sbg-(?:dxai-neutral-100|white)\s/', $class ) === 1 || preg_match( '/(?:^|;)\s*background(?:-color)?\s*:\s*(?:#fff\b|#ffffff|white|var\(--dxai-neutral-100\))/i', $css ) === 1;
		$filled      = ! $transparent && ( preg_match( '/\sbg-dxai-(?!neutral-\d+-a\d)[a-z0-9-]+\s/', $class ) === 1 || preg_match( '/\sbg-[a-z]/', $class ) === 1 || preg_match( '/(?:^|;)\s*background(?:-color)?\s*:\s*(?!transparent|none)/i', $css ) === 1 );
		$border      = preg_match( '/\sborder(?:-[0-9a-z-]+)?\s/', $class ) === 1 || preg_match( '/(?:^|;)\s*border(?:-width)?\s*:\s*[1-9]/', $css ) === 1;
		if ( $white || ( $border && ! $filled ) ) {
			return 'secondary-button';
		}
		if ( $filled ) {
			return 'primary-button';
		}

		return null;
	}

	/**
	 * Distance (OKLab × 100) under which a button cannot be told from the band it sits on. Measured against american-restoration's
	 * accent #e64804: the same accent 0, Tailwind's orange-600 2.9 and orange-500 9.2 — one orange to the eye — while the design's
	 * own flame #f28c28 is 13.9, the theme's primary blue 36 and white 43.
	 */
	public const APART = 10.0;

	/**
	 * A call to action whose theme style would vanish into what it sits on: the theme's filled button on a band of the same colour
	 * (a design's orange band, once its colours follow american-restoration, is the theme's accent — the primary button's own fill).
	 * Such a button keeps the design's own fill, when that is a colour of the theme's palette, in the Button's Colour panel with the
	 * palette's black or white for its text, whichever reads; failing that it wears the theme's other style (white). Every other
	 * button is the theme's, as before.
	 *
	 * @param array<string, mixed>      $cta    What cta() read.
	 * @param array<string, mixed>|null $parent The attributes of the block the buttons sit in.
	 * @return array{cta:array<string, mixed>, fill:string, text:string} The call to action (its style may change), and the presets
	 *                                                                      of its background and text ('' for the style's own).
	 */
	private static function on_band( array $cta, ?array $parent ): array {
		$keep = array(
			'cta'  => $cta,
			'fill' => '',
			'text' => '',
		);
		if ( ! in_array( $cta['style'], array( 'primary-button', 'small-primary-button' ), true ) ) {
			return $keep;
		}
		$home  = (int) ( self::$context['home'] ?? 0 );
		$band  = self::band( $parent, $home );
		$theme = Theme_Buttons::fill( (string) $cta['style'] );
		if ( $band === '' || $theme === '' || Color_Math::distance( $theme, $band ) >= self::APART ) {
			return $keep;
		}
		$preset = self::preset_of( (string) ( $cta['fill'] ?? '' ), $home );
		$hex    = $preset !== '' ? Theme_Palette::hex( $preset ) : '';
		if ( $hex !== '' && Color_Math::distance( $hex, $band ) >= self::APART ) {
			$text = '';
			$best = 0.0;
			foreach ( array( 'white', 'black' ) as $slug ) {
				$on = Theme_Palette::hex( $slug );
				if ( $on !== '' && Color_Math::contrast( $on, $hex ) > $best ) {
					$best = Color_Math::contrast( $on, $hex );
					$text = $slug;
				}
			}

			return array(
				'cta'  => $cta,
				'fill' => $preset,
				'text' => $text,
			);
		}
		$other = Theme_Buttons::has( 'secondary-button' ) ? Theme_Buttons::fill( 'secondary-button' ) : '';
		if ( $other !== '' && Color_Math::distance( $other, $band ) >= self::APART ) {
			$cta['style'] = 'secondary-button';

			return array(
				'cta'  => $cta,
				'fill' => '',
				'text' => '',
			);
		}

		return $keep;
	}

	/**
	 * The colour (#rrggbb) of what a run of buttons sits on: the nearest block around them that paints a background of its own,
	 * read as the importer stored it ('' when none does, or it is a picture or a gradient: nothing to judge against).
	 *
	 * @param array<string, mixed>|null $parent
	 */
	private static function band( ?array $parent, int $home ): string {
		$around = array_reverse( self::$ancestors );
		if ( $parent !== null ) {
			array_unshift( $around, $parent );
		}
		foreach ( $around as $attrs ) {
			$paint = \DXAI_UI\Compiler\Color_Usage::background_of( is_array( $attrs ) ? $attrs : array(), $home );
			if ( $paint === '' ) {
				continue;
			}
			if ( $paint === 'image' ) {
				return '';
			}
			$value = self::value_of( $paint, $home );
			$rgba  = $value !== '' ? Color_Math::parse( $value ) : null;
			if ( $rgba === null ) {
				return '';
			}
			// A translucent band (white at 10%) shows what is under it.
			if ( $rgba[3] < 1 ) {
				continue;
			}

			return Color_Math::hex( $value );
		}

		return '';
	}

	/**
	 * The colour (#rrggbb) a block paints as its own ground on this site: '' when it paints none, a picture, a gradient or a see-through
	 * colour (the Home's grounds and a section's, read by Library_Style).
	 *
	 * @param array<string, mixed> $attrs A block's attributes as the importer or the library stored them.
	 */
	public static function ground_hex( array $attrs, int $home, bool $on_white = false ): string {
		$paint = \DXAI_UI\Compiler\Color_Usage::background_of( $attrs, $home );
		if ( $paint === '' || $paint === 'image' ) {
			return '';
		}
		$value = self::value_of( $paint, $home );
		$rgba  = $value !== '' ? Color_Math::parse( $value ) : null;
		if ( $rgba !== null && $rgba[3] < 1 && $on_white ) {
			// A see-through colour on a white page (the team's grey at half strength) is the colour it shows there.
			$mix = static fn( float $c ): int => (int) round( $c * $rgba[3] + 255 * ( 1 - $rgba[3] ) );

			return sprintf( '#%02x%02x%02x', $mix( (float) $rgba[0] ), $mix( (float) $rgba[1] ), $mix( (float) $rgba[2] ) );
		}

		return $rgba !== null && $rgba[3] >= 1 ? Color_Math::hex( $value ) : '';
	}

	/**
	 * The colour a background_of() answer shows on this site: a token the design's colours follow, as the theme colour it follows
	 * (Theme_Binding); any other token as the design wrote it; a preset as the palette has it.
	 */
	private static function value_of( string $paint, int $home ): string {
		$preset = self::preset_of( $paint, $home );
		if ( $preset !== '' && Theme_Palette::hex( $preset ) !== '' ) {
			return Theme_Palette::hex( $preset );
		}
		if ( preg_match( '/^(?:token|preset):([a-z0-9-]+)$/', $paint, $m ) === 1 ) {
			$palette = get_post_meta( $home, \DXAI_UI\Compiler\Token_Styles::META, true );
			foreach ( is_array( $palette['colors'] ?? null ) ? $palette['colors'] : array() as $row ) {
				if ( is_array( $row ) && (string) ( $row['slug'] ?? '' ) === $m[1] ) {
					return (string) ( $row['value'] ?? '' );
				}
			}

			return '';
		}

		return $paint;
	}

	/**
	 * The theme preset a background_of() answer is ('' when it is none): a preset itself, or a token the design's colours follow
	 * to one (Theme_Binding).
	 */
	private static function preset_of( string $paint, int $home ): string {
		if ( preg_match( '/^preset:([a-z0-9-]+)$/', $paint, $m ) === 1 ) {
			return Theme_Palette::has( $m[1] ) ? $m[1] : '';
		}
		if ( $home < 1 || preg_match( '/^token:([a-z0-9-]+)$/', $paint, $m ) !== 1 || ! Theme_Binding::follows( $home ) ) {
			return '';
		}
		$to = (string) ( Theme_Binding::get( $home )['tokens'][ $m[1] ]['to'] ?? '' );

		return $to !== '' && Theme_Palette::has( $to ) ? $to : '';
	}

	private static function keep_classes( string $classes ): string {
		$keep = array();
		foreach ( preg_split( '/\s+/', trim( $classes ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $token ) {
			if ( preg_match( self::KEEP_CLASS, $token ) === 1 ) {
				$keep[] = $token;
			}
		}

		return implode( ' ', $keep );
	}

	private static function keep_css( string $css ): string {
		$keep = array();
		foreach ( \DXAI_UI\Compiler\Utility_Classes::declarations( $css ) as $d ) {
			if ( $d['prop'] !== '' && preg_match( self::KEEP_PROP, $d['prop'] ) === 1 ) {
				$keep[] = $d['raw'];
			}
		}

		return implode( ';', $keep );
	}

	/**
	 * The Buttons block for a run of calls to action.
	 *
	 * @param array<int, array<string, mixed>> $run
	 * @param array<string, mixed>|null        $parent The attributes of the block they sit in.
	 * @return array<string, mixed>
	 */
	private function buttons_block( array $run, ?array $parent ): array {
		$slug   = Theme_Buttons::text_slug();
		$inner  = array();
		$stack  = self::stacks( $run );
		$chunks = array( '<div class="' . ( $stack ? 'wp-block-buttons sm-flex-column' : 'wp-block-buttons' ) . '">' );
		foreach ( $run as $i => $cta ) {
			$own     = self::on_band( $cta, $parent );
			$cta     = $own['cta'];
			$text    = $own['text'] !== '' ? $own['text'] : ( in_array( $cta['style'], array( 'primary-button', 'small-primary-button' ), true ) ? $slug : '' );
			// (A colour whose class WordPress gives a meaning of its own — `has-border-color` is a border — is left to the button's style.)
			if ( $text !== '' && ! \DXAI_UI\Theme\Capabilities::text_slug_ok( $text ) ) {
				$text = '';
			}
			$fill    = $own['fill'];
			$colored = $text !== '';
			$attrs   = array();
			if ( $colored ) {
				$attrs['textColor'] = $text;
			}
			if ( $fill !== '' ) {
				$attrs['backgroundColor'] = $fill;
			}
			$attrs['className'] = trim( $cta['classes'] . ' is-style-' . $cta['style'] );
			if ( $colored ) {
				$attrs['style'] = array( 'elements' => array( 'link' => array( 'color' => array( 'text' => 'var:preset|color|' . $text ) ) ) );
			}
			if ( $cta['css'] !== '' ) {
				$attrs['dxaiCss'] = $cta['css'];
			}
			$wrap = 'wp-block-button ' . self::class_tail( $attrs );
			// The classes core's Button writes for its colour settings, in core's order.
			$link = 'wp-block-button__link'
				. ( $colored ? ' has-' . $text . '-color' : '' )
				. ( $fill !== '' ? ' has-' . $fill . '-background-color' : '' )
				. ( $colored ? ' has-text-color' : '' )
				. ( $fill !== '' ? ' has-background' : '' )
				. ( $colored ? ' has-link-color' : '' )
				. ' wp-element-button';
			$a    = '<a class="' . esc_attr( $link ) . '" href="' . esc_url( $cta['url'] ) . '"'
				. ( $cta['title'] !== '' ? ' title="' . esc_attr( $cta['title'] ) . '"' : '' )
				. ( $cta['target'] !== '' ? ' target="' . esc_attr( $cta['target'] ) . '"' : '' )
				. ( $cta['rel'] !== '' ? ' rel="' . esc_attr( $cta['rel'] ) . '"' : '' )
				. '>' . esc_html( $cta['text'] ) . '</a>';
			$html = '<div class="' . esc_attr( trim( $wrap ) ) . '">' . $a . '</div>';
			$inner[] = array(
				'blockName'    => 'core/button',
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => $html,
				'innerContent' => array( $html ),
			);
			$chunks[] = null;
		}
		$chunks[] = '</div>';
		$layout   = array(
			'type'        => 'flex',
			'orientation' => 'horizontal',
			'flexWrap'    => 'nowrap',
		);
		$justify  = self::justify( $parent );
		if ( $justify !== '' ) {
			$layout['justifyContent'] = $justify;
		}
		$attrs = array();
		if ( $stack ) {
			$attrs['className'] = 'sm-flex-column';
		}
		$attrs['style']  = array( 'spacing' => array( 'blockGap' => array( 'top' => self::gap( $parent ), 'left' => self::gap( $parent ) ) ) );
		$attrs['layout'] = $layout;

		return array(
			'blockName'    => 'core/buttons',
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => '<div class="' . ( $stack ? 'wp-block-buttons sm-flex-column' : 'wp-block-buttons' ) . '"></div>',
			'innerContent' => $chunks,
		);
	}

	/**
	 * Whether a run of links is a strip of chips or tabs rather than calls to action: they sit in a row that scrolls
	 * sideways (`overflow-x-auto`, `snap-x`), or there are four or more side by side. The theme's buttons stack on a
	 * small screen, which would turn a row that scrolls into a column, so these stay the design's own links.
	 *
	 * @param array<int, array<string, mixed>> $run
	 * @param array<string, mixed>|null        $parent
	 */
	private static function chips( array $run, ?array $parent ): bool {
		if ( count( $run ) >= 4 ) {
			return true;
		}
		$class = ' ' . trim( (string) ( $parent['className'] ?? '' ) ) . ' ';
		$css   = (string) ( $parent['dxaiCss'] ?? '' );

		return preg_match( '/\s(?:(?:xs|sm|md|lg|xl)-)?(?:overflow-(?:x-)?(?:auto|scroll)|snap-x|no-scrollbar|scrollbar-(?:none|hide))\s/', $class ) === 1
			|| preg_match( '/(?:^|;)\s*overflow(?:-x)?\s*:\s*(?:auto|scroll)/', $css ) === 1;
	}

	/**
	 * Whether the row stacks its buttons on a small screen, as the theme's pages have it (`sm-flex-column`). A design
	 * that sizes its buttons along the row itself (`flex: 1 1 200px`, `flex-1`, `grow`) keeps its own way: in a column
	 * the same basis is the button's height, and a 200px-wide button came out 200px tall.
	 *
	 * @param array<int, array<string, mixed>> $run
	 */
	private static function stacks( array $run ): bool {
		foreach ( $run as $cta ) {
			if ( preg_match( '/(?:^|;)\s*flex(?:-[a-z]+)?\s*:/', (string) $cta['css'] ) === 1 || preg_match( '/(?:^|\s)(?:(?:xs|sm|md|lg|xl)-)?(?:flex-1|flex-none|grow|shrink)(?:\s|$)/', (string) $cta['classes'] ) === 1 ) {
				return false;
			}
		}

		return true;
	}

	/** The space between the buttons: the container's own gap when the design set one, else the theme pages' 12px. */
	private static function gap( ?array $parent ): string {
		foreach ( preg_split( '/\s+/', trim( (string) ( $parent['className'] ?? '' ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $token ) {
			if ( preg_match( '/^gap-[\w.-]+$/', $token ) === 1 && preg_match( '/gap:\s*(\d+(?:\.\d+)?)px/', \DXAI_UI\Compiler\Utility_Classes::base_declarations( $token ), $m ) === 1 ) {
				return $m[1] . 'px';
			}
		}

		return '12px';
	}

	/** Where the buttons sit in their container, as the design's container aligns its children. */
	private static function justify( ?array $parent ): string {
		$class = ' ' . trim( (string) ( $parent['className'] ?? '' ) ) . ' ';
		if ( preg_match( '/\s(?:justify-center|text-center)\s/', $class ) === 1 ) {
			return 'center';
		}
		if ( preg_match( '/\s(?:justify-end|text-right)\s/', $class ) === 1 ) {
			return 'right';
		}

		return '';
	}
}

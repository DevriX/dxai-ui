<?php
/**
 * The look of a design's Home, for the sections the library makes for its other pages.
 *
 * @package DXAI_UI\Blocks\Native
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks\Native;

use DXAI_UI\Compiler\Utility_Classes;
use DXAI_UI\Pages\Block_Tree;
use DXAI_UI\Pages\Section_Library;

/**
 * A section of the library (Pages\Block_Library) is the team's own block, written with the team's own sizes: its title at 42px, 96px of
 * padding above and below. On a page made for a design it stands among the Home's sections, and the rule of a design's pages is that a
 * section title is the size the Home's section titles are, and a section has the padding the Home's sections have (Heading_Scale,
 * Section_Spacing). This gives a library section those two, once, when it is made for a page.
 *
 * It is the Home that is the measure and the Home is never changed by it: it reads what the two passes would say about the Home (their
 * profiles) and writes with their own hand (restyle), on the one section the library made. It is not a converter — it is in no list of
 * them, so no import, conversion or page of a design runs it — and nothing the passes do is different for it being here. A Home that says
 * nothing (too few titles or sections to tell) leaves the section as the team wrote it.
 */
final class Library_Style extends Style_Harmony {

	/** What the Typography panel writes for a title's size, weight, line, tracking and case. */
	private const OWN_TYPE = array( 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'textTransform', 'fontStyle' );

	public function id(): string {
		return 'library_style';
	}

	public function label(): string {
		return __( 'The look of the Home, for the library\'s sections', 'dxai-ui' );
	}

	public function source(): string {
		return 'core/group';
	}

	public function target(): string {
		return 'core/group';
	}

	/** Never a pass: there is no profile of its own. */
	protected static function profile_of( array $blocks ): ?array {
		return null;
	}

	/**
	 * The section with its titles and its padding as the Home's.
	 *
	 * @param array<string, mixed> $section A parse_blocks() block, the section's root.
	 * @return array<string, mixed>
	 */
	public static function follow( array $section, int $home ): array {
		if ( $home < 1 || get_post_status( $home ) === false || ! (bool) apply_filters( 'dxai_ui_harmony', true, $home ) ) {
			return $section;
		}

		$profile = Heading_Scale::profile( $home );
		$section = self::spacing( $profile !== null && isset( $profile[2] ) ? self::titles( $section, $home ) : $section, $home );

		// Its cards, eyebrows and buttons as the Home's.
		return self::dress( $section, self::look( $home ) );
	}

	/**
	 * The section titles (the H2) in the style the Home's are: the pass's own work on each, done on a title that has had the typography of
	 * its own block settings taken off (a title set by hand in the Typography panel is left alone by the pass, and the team set theirs there).
	 * A title the pass would not change keeps what the team gave it.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function titles( array $block, int $home ): array {
		if ( ( $block['blockName'] ?? '' ) === 'core/heading' && (int) ( $block['attrs']['level'] ?? 2 ) === 2 ) {
			$align = $block['attrs']['style']['typography']['textAlign'] ?? null;
			$bare  = self::without_type( $block );
			Converter::set_context(
				array(
					'home' => $home,
					'post' => 0,
				)
			);
			try {
				$done = ( new Heading_Scale() )->page( array( $bare ) );
			} finally {
				Converter::set_context( array() );
			}
			if ( $done === null ) {
				return $block;
			}
			$new = $done['blocks'][0];
			// The alignment is the Typography panel's too, and a title with anything in it counts as set by hand: it is put back after.
			if ( is_string( $align ) ) {
				$new['attrs']['style']['typography']['textAlign'] = $align;
			}

			return $new;
		}
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $i => $child ) {
			if ( is_array( $child ) ) {
				$block['innerBlocks'][ $i ] = self::titles( $child, $home );
			}
		}

		return $block;
	}

	/**
	 * A title without the typography its own block settings gave it: the preset size, the Typography panel's values, and what they wrote in
	 * the tag (the classes and the inline style), so what the Home says can take their place.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function without_type( array $block ): array {
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		unset( $attrs['fontSize'], $attrs['style']['typography']['textAlign'] );
		foreach ( self::OWN_TYPE as $key ) {
			unset( $attrs['style']['typography'][ $key ] );
		}
		if ( isset( $attrs['style']['typography'] ) && $attrs['style']['typography'] === array() ) {
			unset( $attrs['style']['typography'] );
		}
		if ( isset( $attrs['style'] ) && $attrs['style'] === array() ) {
			unset( $attrs['style'] );
		}
		$block['attrs'] = $attrs;
		foreach ( (array) $block['innerContent'] as $k => $chunk ) {
			if ( ! is_string( $chunk ) || trim( $chunk ) === '' ) {
				continue;
			}
			// Only the title's own opening tag (the first one in its markup).
			$block['innerContent'][ $k ] = (string) preg_replace_callback(
				'/^(\s*<h[1-6]\b)([^>]*)(>)/i',
				static function ( array $m ): string {
					$attr = (string) preg_replace( '/\shas-[a-z0-9-]+-font-size\b/', '', $m[2] );
					$attr = (string) preg_replace_callback(
						'/\sstyle="([^"]*)"/',
						static function ( array $s ): string {
							$css = (string) preg_replace( '/(?:^|;)\s*(?:font-size|font-weight|font-style|line-height|letter-spacing|text-transform)\s*:[^;"]*/i', ';', $s[1] );
							$css = trim( (string) preg_replace( '/;{2,}/', ';', $css ), '; ' );

							return $css === '' ? '' : ' style="' . $css . '"';
						},
						$attr
					);

					return $m[1] . $attr . $m[3];
				},
				$chunk,
				1
			);
			break;
		}
		$block['innerHTML'] = implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) );

		return $block;
	}

	/**
	 * The section with the padding above and below that the Home's sections have: what the section set for itself (the block's padding
	 * settings, and a `py-` class) comes off and the Home's goes on. A section with none of its own (its content is padded inside), a
	 * Home with no rhythm to follow, or a section the pass cannot write to is left as the team wrote it.
	 *
	 * @param array<string, mixed> $section
	 * @return array<string, mixed>
	 */
	private static function spacing( array $section, int $home ): array {
		if ( ( $section['blockName'] ?? '' ) !== 'core/group' ) {
			return $section;
		}
		$dom = Section_Spacing::profile( $home );
		if ( $dom === null || ( $dom['top'] === null && $dom['bottom'] === null && $dom['mine_class'] === array() ) ) {
			return $section;
		}
		$attrs  = is_array( $section['attrs'] ?? null ) ? $section['attrs'] : array();
		$pad    = is_array( $attrs['style']['spacing']['padding'] ?? null ) ? $attrs['style']['spacing']['padding'] : array();
		$tokens = self::tokens( (string) ( $attrs['className'] ?? '' ) );
		$own    = array_values( array_filter( $tokens, static fn( string $t ): bool => preg_match( '/^(?:py|pt|pb)-\S+$/', self::base( $t ) ) === 1 ) );
		if ( ! isset( $pad['top'] ) && ! isset( $pad['bottom'] ) && $own === array() ) {
			return $section;
		}
		$whole = $section;
		unset( $attrs['style']['spacing']['padding']['top'], $attrs['style']['spacing']['padding']['bottom'] );
		if ( isset( $attrs['style']['spacing']['padding'] ) && $attrs['style']['spacing']['padding'] === array() ) {
			unset( $attrs['style']['spacing']['padding'] );
		}
		if ( isset( $attrs['style']['spacing'] ) && $attrs['style']['spacing'] === array() ) {
			unset( $attrs['style']['spacing'] );
		}
		if ( isset( $attrs['style'] ) && $attrs['style'] === array() ) {
			unset( $attrs['style'] );
		}
		$section['attrs'] = $attrs;
		// What those settings wrote in the tag.
		foreach ( (array) $section['innerContent'] as $k => $chunk ) {
			if ( is_string( $chunk ) && trim( $chunk ) !== '' ) {
				$section['innerContent'][ $k ] = (string) preg_replace_callback(
					'/^(\s*<[a-z][a-z0-9]*\b[^>]*?)\sstyle="([^"]*)"/i',
					static function ( array $m ): string {
						$css = trim( (string) preg_replace( '/(?:^|;)\s*padding-(?:top|bottom)\s*:[^;"]*/i', ';', $m[2] ), '; ' );

						return $m[1] . ( $css === '' ? '' : ' style="' . (string) preg_replace( '/;{2,}/', ';', $css ) . '"' );
					},
					$chunk,
					1
				);
				break;
			}
		}
		$section['innerHTML'] = implode( '', array_filter( (array) $section['innerContent'], 'is_string' ) );
		$put                  = array();
		if ( $dom['top'] !== null ) {
			$put[] = 'padding-top:' . $dom['top'];
		}
		if ( $dom['bottom'] !== null ) {
			$put[] = 'padding-bottom:' . $dom['bottom'];
		}
		$class = implode( ' ', array_merge( array_values( array_diff( $tokens, $own ) ), $dom['mine_class'] ) );
		$css   = Utility_Classes::fit_system( implode( ';', array_filter( array( trim( (string) ( $attrs['dxaiCss'] ?? '' ), '; ' ), implode( ';', $put ) ) ) ) );

		return self::restyle( $section, $class, $css ) ?? $whole;
	}

	/* ------------------------------------------------------------------------------------------- the look of the Home */

	/** The classes that are a box's look: its corners, its shadow, its border, its background, its padding. */
	private const BOX_CLASS = '/^(?:(?:sm|md|lg|xl|2xl)[:-])*(?:rounded(?:-|$)|shadow|dxai-sh-|border(?:-|$)|bg-|p[xytblr]?-)/';
	/** The declarations that are a box's look. */
	private const BOX_PROP = '/^(?:border(?:-[a-z-]+)?|box-shadow|background(?:-[a-z-]+)?|padding(?:-[a-z]+)?)$/';
	/** The classes that are a line's type: its size, weight, case, tracking, leading, colour. */
	private const TYPE_CLASS = '/^(?:(?:sm|md|lg|xl|2xl)[:-])*(?:text-|uppercase$|capitalize$|fw-|font-|ls-|tracking-|lh-|leading-)/';
	/** The declarations that are a line's type. */
	private const TYPE_PROP = '/^(?:font(?:-[a-z]+)?|letter-spacing|text-transform|line-height|color)$/';
	/** The block's own settings a box's look is written in (the Border, Shadow, Colour and Dimensions panels), and a line's (Typography, Colour). */
	private const BOX_SETTINGS  = array( 'style.border', 'style.shadow', 'backgroundColor', 'style.color.background', 'style.spacing.padding' );
	private const TYPE_SETTINGS = array( 'fontSize', 'style.typography', 'textColor', 'style.color.text' );
	/** A button's own look beside its style: its colours, its padding and its type (the Colour, Dimensions and Typography panels). */
	private const BUTTON_PROP     = '/^(?:color|background(?:-color)?|padding(?:-[a-z]+)?|font(?:-[a-z]+)?|letter-spacing|text-transform|line-height)$/';
	private const BUTTON_SETTINGS = array( 'textColor', 'backgroundColor', 'style.color', 'style.elements', 'style.spacing.padding', 'fontSize', 'style.typography' );
	private const BUTTON_TAG_CLASS = '/^(?:has-text-color|has-background|has-link-color|has-custom-font-size|has-[a-z0-9-]+-color|has-[a-z0-9-]+-background-color|has-[a-z0-9-]+-font-size)$/';

	/** @var array<string, array<string, mixed>> The look of a Home, by Home and its last change. */
	private static array $looks = array();

	/**
	 * The look of the Home's cards, eyebrows and buttons: what most of them have, as classes and declarations the Home's own stylesheet
	 * knows (its pages carry it). Null for a kind the Home has too few of, or no two alike.
	 *
	 * @return array{card: array<string, mixed>|null, eyebrow: array<string, mixed>|null, button: array<string, mixed>|null}
	 */
	public static function look( int $home ): array {
		$content = (string) get_post_field( 'post_content', $home );
		$key     = $home . '@' . md5( $content );
		if ( ! isset( self::$looks[ $key ] ) ) {
			$blocks              = parse_blocks( $content );
			self::$looks[ $key ] = array(
				'card'    => self::card_of( $home ),
				'eyebrow' => self::eyebrow_of( $blocks ),
				'button'  => self::buttons_of( $blocks ),
			);
		}

		return self::$looks[ $key ];
	}

	/**
	 * The look of the Home's cards: the repeated items of its sections that are cards (Section_Library), the box each is.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function card_of( int $home ): ?array {
		$found = array();
		foreach ( Section_Library::for_page( $home ) as $component ) {
			$block = (array) $component['block'];
			foreach ( (array) ( $component['repeats'] ?? array() ) as $rep ) {
				if ( (string) ( $rep['kind'] ?? '' ) !== 'card' ) {
					continue;
				}
				try {
					$parent = Block_Tree::at( $block, (array) $rep['path'] );
				} catch ( \RuntimeException $e ) {
					continue;
				}
				foreach ( (array) $rep['items'] as $i ) {
					$item = $parent['innerBlocks'][ $i ] ?? null;
					if ( is_array( $item ) ) {
						$look = self::look_of( $item, self::BOX_CLASS, self::BOX_PROP );
						if ( $look['key'] !== '' ) {
							$found[] = $look;
						}
					}
				}
			}
		}

		return self::dominant( $found, self::MIN_EVIDENCE, true );
	}

	/**
	 * The look of the Home's eyebrows: a line of one to four words right before a section title ("What we do").
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<string, mixed>|null
	 */
	private static function eyebrow_of( array $blocks ): ?array {
		$found = array();
		$walk  = static function ( array $b ) use ( &$walk, &$found ): void {
			if ( self::is_chrome( $b ) ) {
				return;
			}
			$kids = array_values( array_filter( (array) ( $b['innerBlocks'] ?? array() ), static fn( $c ) => is_array( $c ) && ! empty( $c['blockName'] ) ) );
			foreach ( $kids as $i => $c ) {
				$next = $kids[ $i + 1 ] ?? null;
				if ( in_array( (string) $c['blockName'], array( 'core/paragraph', 'amr/span', 'dxai-ui/text' ), true ) && is_array( $next ) && (string) $next['blockName'] === 'core/heading' && (int) ( $next['attrs']['level'] ?? 2 ) <= 2 ) {
					$n = str_word_count( trim( wp_strip_all_tags( (string) $c['innerHTML'] ) ) );
					if ( $n >= 1 && $n <= 4 ) {
						$look = self::look_of( $c, self::TYPE_CLASS, self::TYPE_PROP );
						if ( $look['key'] !== '' ) {
							$found[] = $look;
						}
					}
				}
				$walk( $c );
			}
		};
		foreach ( $blocks as $b ) {
			if ( is_array( $b ) && ! empty( $b['blockName'] ) ) {
				$walk( $b );
			}
		}

		return self::dominant( $found, 2, true );
	}

	/**
	 * The look of the Home's buttons: the classes most of them have (`primary`), and the classes of the ones that are its second kind
	 * (`secondary`: a style named so, or an outline), when it has one.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array{primary: array<string, mixed>, secondary: array<string, mixed>|null}|null
	 */
	private static function buttons_of( array $blocks ): ?array {
		$all  = array();
		$walk = static function ( array $b ) use ( &$walk, &$all ): void {
			if ( self::is_chrome( $b ) ) {
				return;
			}
			// A button with no style of its own says nothing (it is the theme's default): it does not outvote the ones with one.
			if ( (string) ( $b['blockName'] ?? '' ) === 'core/button' && trim( (string) ( $b['attrs']['className'] ?? '' ) ) !== '' ) {
				$class = self::tokens( (string) ( $b['attrs']['className'] ?? '' ) );
				$all[] = array(
					'key'   => implode( ' ', $class ),
					'class' => $class,
					'css'   => array(),
				);
			}
			foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $c ) {
				if ( is_array( $c ) && ! empty( $c['blockName'] ) ) {
					$walk( $c );
				}
			}
		};
		foreach ( $blocks as $b ) {
			if ( is_array( $b ) && ! empty( $b['blockName'] ) ) {
				$walk( $b );
			}
		}
		$primary = self::dominant( $all, 2, true );
		if ( $primary === null || $primary['key'] === '' ) {
			return null;
		}
		$second = self::dominant( array_values( array_filter( $all, static fn( $x ) => $x['key'] !== $primary['key'] && preg_match( '/secondary|outline|ghost/i', (string) $x['key'] ) === 1 ) ), 1, true );

		return array(
			'primary'   => $primary,
			'secondary' => $second,
		);
	}

	/**
	 * What a block's look of a kind is: the classes of it, and the declarations of it (its own CSS, and its own settings as declarations).
	 *
	 * @param array<string, mixed> $block
	 * @return array{key: string, class: array<int, string>, css: array<int, string>}
	 */
	private static function look_of( array $block, string $class_re, string $prop_re ): array {
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$class = array_values( array_filter( self::tokens( (string) ( $attrs['className'] ?? '' ) ), static fn( string $t ): bool => preg_match( $class_re, $t ) === 1 ) );
		$css   = self::split_css( (string) ( $attrs['dxaiCss'] ?? '' ), static fn( string $p ): bool => preg_match( $prop_re, $p ) === 1 )['mine'];
		foreach ( self::settings_css( $attrs ) as $d ) {
			if ( preg_match( $prop_re, (string) strstr( $d, ':', true ) ) === 1 ) {
				$css[] = $d;
			}
		}

		// A block with no look of the kind at all (a plain group among the cards) says nothing: it does not count against the ones that have one.
		return array(
			'key'   => $class === array() && $css === array() ? '' : implode( ' ', $class ) . '|' . implode( ';', $css ),
			'class' => $class,
			'css'   => $css,
		);
	}

	/**
	 * A block's own settings (the Border, Shadow, Colour, Dimensions and Typography panels) as the declarations they write.
	 *
	 * @param array<string, mixed> $attrs
	 * @return array<int, string>
	 */
	private static function settings_css( array $attrs ): array {
		$var = static function ( $v ): string {
			$v = (string) $v;

			return preg_match( '/^var:preset\|([a-z-]+)\|([a-z0-9-]+)$/i', $v, $m ) === 1 ? 'var(--wp--preset--' . $m[1] . '--' . $m[2] . ')' : $v;
		};
		$out   = array();
		$style = is_array( $attrs['style'] ?? null ) ? $attrs['style'] : array();
		if ( isset( $attrs['backgroundColor'] ) ) {
			$out[] = 'background-color:var(--wp--preset--color--' . (string) $attrs['backgroundColor'] . ')';
		} elseif ( isset( $style['color']['background'] ) ) {
			$out[] = 'background-color:' . $var( $style['color']['background'] );
		}
		$border = is_array( $style['border'] ?? null ) ? $style['border'] : array();
		if ( isset( $border['radius'] ) ) {
			$r     = $border['radius'];
			$out[] = is_array( $r ) ? 'border-radius:' . implode( ' ', array( (string) ( $r['topLeft'] ?? '0' ), (string) ( $r['topRight'] ?? '0' ), (string) ( $r['bottomRight'] ?? '0' ), (string) ( $r['bottomLeft'] ?? '0' ) ) ) : 'border-radius:' . (string) $r;
		}
		if ( isset( $border['width'] ) ) {
			$out[] = 'border:' . (string) $border['width'] . ' ' . (string) ( $border['style'] ?? 'solid' ) . ' ' . $var( $border['color'] ?? ( isset( $attrs['borderColor'] ) ? 'var:preset|color|' . $attrs['borderColor'] : 'currentColor' ) );
		}
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			if ( isset( $border[ $side ]['width'] ) ) {
				$out[] = 'border-' . $side . ':' . (string) $border[ $side ]['width'] . ' ' . (string) ( $border[ $side ]['style'] ?? 'solid' ) . ' ' . $var( $border[ $side ]['color'] ?? 'currentColor' );
			}
		}
		if ( isset( $style['shadow'] ) ) {
			$out[] = 'box-shadow:' . $var( $style['shadow'] );
		}
		foreach ( (array) ( $style['spacing']['padding'] ?? array() ) as $side => $v ) {
			if ( in_array( (string) $side, array( 'top', 'right', 'bottom', 'left' ), true ) ) {
				$out[] = 'padding-' . $side . ':' . $var( $v );
			}
		}
		if ( isset( $attrs['fontSize'] ) ) {
			$out[] = 'font-size:var(--wp--preset--font-size--' . (string) $attrs['fontSize'] . ')';
		}
		foreach ( array( 'fontSize' => 'font-size', 'fontWeight' => 'font-weight', 'letterSpacing' => 'letter-spacing', 'textTransform' => 'text-transform', 'lineHeight' => 'line-height', 'fontStyle' => 'font-style' ) as $k => $prop ) {
			if ( isset( $style['typography'][ $k ] ) ) {
				$out[] = $prop . ':' . $var( $style['typography'][ $k ] );
			}
		}
		if ( isset( $attrs['textColor'] ) ) {
			$out[] = 'color:var(--wp--preset--color--' . (string) $attrs['textColor'] . ')';
		} elseif ( isset( $style['color']['text'] ) ) {
			$out[] = 'color:' . $var( $style['color']['text'] );
		}

		return $out;
	}

	/**
	 * The section's cards, eyebrows and buttons in the look the Home's have (look()): each marked block (the builder marks them:
	 * `dxai-lib-card`, `dxai-lib-eyebrow`; a button is one by its block) has its own look of the kind taken off and the Home's put on.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function dress( array $block, array $look ): array {
		$class = (string) ( $block['attrs']['className'] ?? '' );
		$name  = (string) ( $block['blockName'] ?? '' );
		if ( $look['card'] !== null && preg_match( '/(^|\s)dxai-lib-card(\s|$)/', $class ) === 1 ) {
			$block = self::put_look( $block, $look['card'], self::BOX_CLASS, self::BOX_PROP, self::BOX_SETTINGS, '/^(?:has-background|has-[a-z0-9-]+-background-color|has-border-color|has-[a-z0-9-]+-border-color)$/' );
		}
		if ( $look['eyebrow'] !== null && preg_match( '/(^|\s)dxai-lib-eyebrow(\s|$)/', $class ) === 1 ) {
			$block = self::put_look( $block, $look['eyebrow'], self::TYPE_CLASS, self::TYPE_PROP, self::TYPE_SETTINGS, '/^(?:has-text-color|has-[a-z0-9-]+-color|has-[a-z0-9-]+-font-size)$/' );
		}
		// A button drawn as a link (a card's "Learn more") is a link, not a button: it keeps its own look.
		if ( $look['button'] !== null && $name === 'core/button' && preg_match( '/is-style-link|(^|\s)[a-z0-9-]*link-button(\s|$)/i', $class ) !== 1 ) {
			$kind  = preg_match( '/secondary|outline|ghost/i', $class ) === 1 && $look['button']['secondary'] !== null ? $look['button']['secondary'] : $look['button']['primary'];
			// The button's own colours, padding and type go with its style: the Home's style draws it whole. (A button's settings write
			// their classes and declarations on the link inside the wrapper, so the link's tag is cleaned as the wrapper's is.)
			$block = self::put_look( $block, $kind, '/^is-style-/', self::BUTTON_PROP, self::BUTTON_SETTINGS, self::BUTTON_TAG_CLASS );
			$block = self::strip_anchor( $block, self::BUTTON_TAG_CLASS, self::BUTTON_PROP );
		}
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $i => $child ) {
			if ( is_array( $child ) ) {
				$block['innerBlocks'][ $i ] = self::dress( $child, $look );
			}
		}

		return $block;
	}

	/**
	 * The link inside a button's wrapper with the classes of a kind and the declarations of a kind taken off it (what the button's own
	 * settings wrote there), in the block's markup and in its chunks.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function strip_anchor( array $block, string $tag_class_re, string $prop_re ): array {
		$clean = static function ( string $html ) use ( $tag_class_re, $prop_re ): string {
			return (string) preg_replace_callback(
				'/(<a\b)([^>]*)(>)/i',
				static function ( array $m ) use ( $tag_class_re, $prop_re ): string {
					$attr = (string) preg_replace_callback(
						'/\sclass="([^"]*)"/',
						static function ( array $c ) use ( $tag_class_re ): string {
							$kept = array_values( array_filter( self::tokens( html_entity_decode( $c[1], ENT_QUOTES, 'UTF-8' ) ), static fn( string $t ): bool => preg_match( $tag_class_re, $t ) !== 1 ) );

							return $kept === array() ? '' : ' class="' . esc_attr( implode( ' ', $kept ) ) . '"';
						},
						$m[2],
						1
					);
					$attr = (string) preg_replace_callback(
						'/\sstyle="([^"]*)"/',
						static function ( array $s ) use ( $prop_re ): string {
							$kept = array();
							foreach ( Utility_Classes::declarations( html_entity_decode( $s[1], ENT_QUOTES, 'UTF-8' ) ) as $d ) {
								if ( $d['prop'] === '' || preg_match( $prop_re, $d['prop'] ) !== 1 ) {
									$kept[] = $d['raw'];
								}
							}

							return $kept === array() ? '' : ' style="' . esc_attr( implode( ';', $kept ) ) . '"';
						},
						$attr,
						1
					);

					return $m[1] . $attr . $m[3];
				},
				$html,
				1
			);
		};
		$block['innerHTML'] = $clean( (string) ( $block['innerHTML'] ?? '' ) );
		foreach ( (array) ( $block['innerContent'] ?? array() ) as $k => $chunk ) {
			if ( is_string( $chunk ) && str_contains( $chunk, '<a' ) ) {
				$block['innerContent'][ $k ] = $clean( $chunk );
			}
		}

		return $block;
	}

	/**
	 * A block with the Home's look of a kind in place of its own: its classes of the kind, its declarations of the kind, its settings of
	 * the kind (and what they wrote in the tag) go; the Home's classes and declarations come. The block as it was when it cannot be written.
	 *
	 * @param array<string, mixed> $block
	 * @param array<string, mixed> $look     A look (look_of()): `class`, `css`.
	 * @param array<int, string>   $settings The attribute paths of the block's own settings of the kind.
	 * @return array<string, mixed>
	 */
	private static function put_look( array $block, array $look, string $class_re, string $prop_re, array $settings, string $tag_class_re ): array {
		$whole = $block;
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		foreach ( $settings as $path ) {
			$keys = explode( '.', $path );
			$ref  = &$attrs;
			$ok   = true;
			for ( $i = 0; $i < count( $keys ) - 1; $i++ ) {
				if ( ! isset( $ref[ $keys[ $i ] ] ) || ! is_array( $ref[ $keys[ $i ] ] ) ) {
					$ok = false;
					break;
				}
				$ref = &$ref[ $keys[ $i ] ];
			}
			if ( $ok ) {
				unset( $ref[ $keys[ count( $keys ) - 1 ] ] );
			}
			unset( $ref );
		}
		foreach ( array( 'color', 'spacing', 'typography', 'border' ) as $k ) {
			if ( isset( $attrs['style'][ $k ] ) && $attrs['style'][ $k ] === array() ) {
				unset( $attrs['style'][ $k ] );
			}
		}
		if ( isset( $attrs['style'] ) && $attrs['style'] === array() ) {
			unset( $attrs['style'] );
		}
		$block['attrs'] = $attrs;
		$split = self::split_class( (string) ( $attrs['className'] ?? '' ), static fn( string $t ): bool => preg_match( $class_re, $t ) === 1 );
		$class = implode( ' ', array_merge( $split['rest'], (array) $look['class'] ) );
		$css   = self::split_css( (string) ( $attrs['dxaiCss'] ?? '' ), static fn( string $p ): bool => preg_match( $prop_re, $p ) === 1 );
		$css   = implode( ';', array_merge( $css['rest'], (array) $look['css'] ) );
		// What the settings wrote in the tag: the generated classes, and the inline declarations.
		foreach ( (array) $block['innerContent'] as $k => $chunk ) {
			if ( ! is_string( $chunk ) || trim( $chunk ) === '' ) {
				continue;
			}
			$block['innerContent'][ $k ] = (string) preg_replace_callback(
				'/^(\s*<[a-z][a-z0-9]*\b)([^>]*)(>)/i',
				static function ( array $m ) use ( $tag_class_re, $prop_re ): string {
					$attr = (string) preg_replace_callback(
						'/\sclass="([^"]*)"/',
						static function ( array $c ) use ( $tag_class_re ): string {
							$kept = array_values( array_filter( self::tokens( html_entity_decode( $c[1], ENT_QUOTES, 'UTF-8' ) ), static fn( string $t ): bool => preg_match( $tag_class_re, $t ) !== 1 ) );

							return $kept === array() ? '' : ' class="' . esc_attr( implode( ' ', $kept ) ) . '"';
						},
						$m[2],
						1
					);
					$attr = (string) preg_replace_callback(
						'/\sstyle="([^"]*)"/',
						static function ( array $s ) use ( $prop_re ): string {
							$kept = array();
							foreach ( Utility_Classes::declarations( html_entity_decode( $s[1], ENT_QUOTES, 'UTF-8' ) ) as $d ) {
								if ( $d['prop'] === '' || preg_match( $prop_re, $d['prop'] ) !== 1 ) {
									$kept[] = $d['raw'];
								}
							}

							return $kept === array() ? '' : ' style="' . esc_attr( implode( ';', $kept ) ) . '"';
						},
						$attr,
						1
					);

					return $m[1] . $attr . $m[3];
				},
				$chunk,
				1
			);
			break;
		}
		$block['innerHTML'] = implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) );

		return self::restyle( $block, $class, Utility_Classes::fit_system( $css ) ) ?? $whole;
	}
}

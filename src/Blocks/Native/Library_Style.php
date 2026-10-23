<?php
/**
 * The look of a design's Home, for the sections the library makes for its other pages.
 *
 * @package DXAI_UI\Blocks\Native
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks\Native;

use DXAI_UI\Compiler\Utility_Classes;
use DXAI_UI\Pages\Block_Library;
use DXAI_UI\Pages\Block_Tree;
use DXAI_UI\Pages\Section_Library;
use DXAI_UI\Pages\Section_Roles;
use DXAI_UI\Support\Color_Math;

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
	 * The section with its titles and its padding as the Home's, on a ground of the Home's, its head set as the Home's are, and its cards,
	 * eyebrows, questions and buttons in the Home's look.
	 *
	 * @param array<string, mixed> $section A parse_blocks() block, the section's root.
	 * @param string               $role    The place the section takes on its page (Block_Library::section()): a call to action is a band.
	 * @return array<string, mixed>
	 */
	public static function follow( array $section, int $home, string $role = '' ): array {
		if ( $home < 1 || get_post_status( $home ) === false || ! (bool) apply_filters( 'dxai_ui_harmony', true, $home ) ) {
			return $section;
		}

		$profile = Heading_Scale::profile( $home );
		$section = self::spacing( $profile !== null && isset( $profile[2] ) ? self::titles( $section, $home ) : $section, $home );

		$look    = self::look( $home );
		$section = self::contained( $section, true );
		$section = self::ground( $section, $look, $role, $home );
		$section = self::head( $section, $look, $home );

		// Its cards, eyebrows, questions and buttons as the Home's.
		return self::dress( $section, $look, $role );
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
	private const BOX_CLASS = '/^(?:(?:xs|sm|md|lg|xl|2xl)[:-])*(?:rounded(?:-|$)|shadow|dxai-sh-|border(?:-|$)|bg-|p[xytblr]?-)/';
	/** The declarations that are a box's look. */
	private const BOX_PROP = '/^(?:border(?:-[a-z-]+)?|box-shadow|background(?:-[a-z-]+)?|padding(?:-[a-z]+)?)$/';
	/** The classes that are a line's type: its size, weight, case, tracking, leading, colour. */
	private const TYPE_CLASS = '/^(?:(?:sm|md|lg|xl|2xl)[:-])*(?:text-(?!(?:center|left|right|justify|start|end)$)|uppercase$|capitalize$|fw-|font-|ls-|tracking-|lh-|leading-)/';
	/** The declarations that are a line's type. */
	private const TYPE_PROP = '/^(?:font(?:-[a-z]+)?|letter-spacing|text-transform|line-height|color)$/';
	/** The block's own settings a box's look is written in (the Border, Shadow, Colour and Dimensions panels), and a line's (Typography, Colour). */
	private const BOX_SETTINGS  = array( 'style.border', 'style.shadow', 'backgroundColor', 'style.color.background', 'style.spacing.padding' );
	private const TYPE_SETTINGS = array( 'fontSize', 'style.typography', 'textColor', 'style.color.text' );
	/** A button's own look beside its style: its colours, its padding and its type (the Colour, Dimensions and Typography panels). */
	private const BUTTON_PROP     = '/^(?:color|background(?:-color)?|padding(?:-[a-z]+)?|font(?:-[a-z]+)?|letter-spacing|text-transform|line-height)$/';
	private const BUTTON_SETTINGS = array( 'textColor', 'backgroundColor', 'style.color', 'style.elements', 'style.spacing.padding', 'fontSize', 'style.typography' );
	private const BUTTON_TAG_CLASS = '/^(?:has-text-color|has-background|has-link-color|has-custom-font-size|has-[a-z0-9-]+-color|has-[a-z0-9-]+-background-color|has-[a-z0-9-]+-font-size)$/';
	/** The classes, declarations and settings of a section's ground (its background, a picture or a gradient on it included). */
	private const GROUND_CLASS     = '/^(?:(?:sm|md|lg|xl|2xl)[:-])*bg-/';
	private const GROUND_PROP      = '/^background(?:-[a-z-]+)?$/';
	private const GROUND_SETTINGS  = array( 'backgroundColor', 'gradient', 'style.color.background', 'style.color.gradient', 'style.background' );
	private const GROUND_TAG_CLASS = '/^(?:has-background|has-[a-z0-9-]+-background-color|has-[a-z0-9-]+-gradient-background)$/';
	/** What centres a line or a box of a section's head: the classes, as the Typography panel and the design write them. */
	private const CENTRE_CLASS = array( 'text-center', 'has-text-align-center', 'justify-center', 'mx-auto' );

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
				'button'  => self::buttons_of( $blocks ) ?? self::link_buttons_of( $blocks, $home ),
				'ground'  => self::grounds_of( $home ),
				'head'    => self::head_of( $home ),
				'faq'     => self::faq_of( $home ),
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
						// A card is a box (a ground, a border or a shadow: a row of icons with a little padding is none), and the class each
						// card has for its own hover (`dxai-sh-26`, `dxai-sh-27`…) does not make eight cards of one look eight looks.
						if ( $look['key'] !== '' && self::surface( $look ) ) {
							$look['key'] = implode( ' ', array_filter( $look['class'], static fn( string $t ): bool => preg_match( '/^dxai-sh-\d+$/', $t ) !== 1 ) ) . '|' . implode( ';', $look['css'] );
							$found[]     = $look;
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
				if ( ( in_array( (string) $c['blockName'], array( 'core/paragraph', 'dxai-ui/text' ), true ) || \DXAI_UI\Theme\Capabilities::is_block( (string) $c['blockName'], 'span' ) ) &&is_array( $next ) && (string) $next['blockName'] === 'core/heading' && (int) ( $next['attrs']['level'] ?? 2 ) <= 2 ) {
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
	 * The look of the Home's buttons when it draws them as links (a design kept as it was: `dxai-ui/link` with a fill of the
	 * design's colours, `bg-dxai-accent text-dxai-ink`) and has no Button blocks of a style to go by: the fill and the text most of
	 * its filled calls to action have, as the theme presets those colours follow (Theme_Binding). A button of a library section
	 * keeps the theme's style and takes these two in its Colour panel, so it reads as the Home's buttons do. Null when the Home has
	 * fewer than two alike, or its colours follow no preset.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array{primary: array<string, mixed>, secondary: null}|null
	 */
	private static function link_buttons_of( array $blocks, int $home ): ?array {
		$all  = array();
		$walk = static function ( array $b ) use ( &$walk, &$all, $home ): void {
			if ( self::is_chrome( $b ) ) {
				return;
			}
			// A link of the design's: its link block, or a text or a box it drew as a link (a call with its icon: `<a>` of a tel: number).
			$name = (string) ( $b['blockName'] ?? '' );
			$link = $name === 'dxai-ui/link' || ( in_array( $name, array( 'dxai-ui/text', 'dxai-ui/box' ), true ) && strtolower( (string) ( $b['attrs']['tagName'] ?? '' ) ) === 'a' );
			if ( $link && Button::style_of( ' ' . (string) ( $b['attrs']['className'] ?? '' ) . ' ', (string) ( $b['attrs']['dxaiCss'] ?? '' ) ) === 'primary-button' ) {
				$class = self::tokens( (string) ( $b['attrs']['className'] ?? '' ) );
				$fill  = '';
				$text  = '';
				foreach ( $class as $t ) {
					if ( $fill === '' && preg_match( '/^bg-dxai-([a-z0-9-]+)$/', $t, $m ) === 1 ) {
						$fill = Button::preset_for( 'token:' . $m[1], $home );
					} elseif ( $text === '' && preg_match( '/^text-dxai-([a-z0-9-]+)$/', $t, $m ) === 1 ) {
						$text = Button::preset_for( 'token:' . $m[1], $home );
					}
				}
				if ( $fill !== '' ) {
					$all[] = array(
						'key'   => $fill . '|' . $text,
						'class' => array(),
						'css'   => array(),
						'fill'  => $fill,
						'text'  => $text !== '' && \DXAI_UI\Theme\Capabilities::text_slug_ok( $text ) ? $text : '',
					);
				}
			}
			foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $c ) {
				if ( is_array( $c ) && ! empty( $c['blockName'] ) ) {
					$walk( $c );
				}
			}
		};
		foreach ( $blocks as $b ) {
			if ( ! empty( $b['blockName'] ) ) {
				$walk( $b );
			}
		}
		$primary = self::dominant( $all, 2, true );
		if ( $primary === null || (string) ( $primary['fill'] ?? '' ) === '' ) {
			return null;
		}

		return array(
			'primary'   => $primary,
			'secondary' => null,
		);
	}

	/**
	 * A Button in its own style with the Home's fill and text in its Colour panel (the classes core's Button writes for them on its
	 * link, in core's order), its own colours taken off first.
	 *
	 * @param array<string, mixed> $block A core/button.
	 * @return array<string, mixed>
	 */
	private static function paint_button( array $block, string $fill, string $text ): array {
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		unset( $attrs['backgroundColor'], $attrs['textColor'], $attrs['gradient'], $attrs['style']['color'], $attrs['style']['elements']['link'] );
		if ( isset( $attrs['style']['elements'] ) && $attrs['style']['elements'] === array() ) {
			unset( $attrs['style']['elements'] );
		}
		$attrs['backgroundColor'] = $fill;
		if ( $text !== '' ) {
			$attrs['textColor']                                       = $text;
			$attrs['style']['elements']['link']['color']['text'] = 'var:preset|color|' . $text;
		}
		if ( isset( $attrs['style'] ) && $attrs['style'] === array() ) {
			unset( $attrs['style'] );
		}
		$block['attrs'] = $attrs;
		$block          = self::strip_anchor( $block, self::BUTTON_TAG_CLASS, '/^(?:color|background(?:-color)?|background-image)$/' );
		$add            = ( $text !== '' ? ' has-' . $text . '-color' : '' ) . ' has-' . $fill . '-background-color' . ( $text !== '' ? ' has-text-color' : '' ) . ' has-background' . ( $text !== '' ? ' has-link-color' : '' );
		$put            = static fn( string $html ): string => (string) preg_replace( '/(<a\b[^>]*\sclass="[^"]*?\bwp-block-button__link)\b/', '$1' . $add, $html, 1 );
		$block['innerHTML'] = $put( (string) ( $block['innerHTML'] ?? '' ) );
		foreach ( (array) ( $block['innerContent'] ?? array() ) as $k => $chunk ) {
			if ( is_string( $chunk ) && str_contains( $chunk, 'wp-block-button__link' ) ) {
				$block['innerContent'][ $k ] = $put( $chunk );
			}
		}

		return $block;
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
	 * `dxai-lib-card`, `dxai-lib-eyebrow`; a button is one by its block) has its own look of the kind taken off and the Home's put on. A
	 * question of a section of questions takes the look of the Home's questions (a line under each), not its cards'; a run of boxes the
	 * builder did not mark (the steps of a section set as cards) takes the cards'.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function dress( array $block, array $look, string $role = '' ): array {
		$class = (string) ( $block['attrs']['className'] ?? '' );
		$name  = (string) ( $block['blockName'] ?? '' );
		$faq   = $role === 'faq' && ( $look['faq'] ?? null ) !== null;
		if ( $faq && preg_match( '/(^|\s)dxai-lib-card(\s|$)/', $class ) === 1 ) {
			// A question keeps its own room (its padding): the Home's line under it, and no box of its own.
			$block = self::put_look( $block, $look['faq'], self::LINE_CLASS, self::LINE_PROP, self::LINE_SETTINGS, self::BOX_TAG_CLASS );
		} elseif ( $look['card'] !== null && preg_match( '/(^|\s)dxai-lib-card(\s|$)/', $class ) === 1 && ! self::is_chip( $block ) ) {
			$block = self::put_card( $block, $look['card'] );
		}
		if ( $look['eyebrow'] !== null && preg_match( '/(^|\s)dxai-lib-eyebrow(\s|$)/', $class ) === 1 ) {
			$block = self::put_look( $block, $look['eyebrow'], self::TYPE_CLASS, self::TYPE_PROP, self::TYPE_SETTINGS, '/^(?:has-text-color|has-[a-z0-9-]+-color|has-[a-z0-9-]+-font-size)$/' );
		}
		// A button drawn as a link (a card's "Learn more") is a link, not a button: it keeps its own look.
		if ( isset( $look['button']['primary']['fill'] ) && $name === 'core/button' && preg_match( '/is-style-link|(^|\s)[a-z0-9-]*link-button(\s|$)|secondary|outline|ghost/i', $class ) !== 1 ) {
			// A Home that draws its buttons as links: the button keeps its style and wears the Home's fill and text (its Colour panel).
			$block = self::paint_button( $block, (string) $look['button']['primary']['fill'], (string) $look['button']['primary']['text'] );
		} elseif ( $look['button'] !== null && ! isset( $look['button']['primary']['fill'] ) && $name === 'core/button' && preg_match( '/is-style-link|(^|\s)[a-z0-9-]*link-button(\s|$)/i', $class ) !== 1 ) {
			$kind  = preg_match( '/secondary|outline|ghost/i', $class ) === 1 && $look['button']['secondary'] !== null ? $look['button']['secondary'] : $look['button']['primary'];
			// The button's own colours, padding and type go with its style: the Home's style draws it whole. (A button's settings write
			// their classes and declarations on the link inside the wrapper, so the link's tag is cleaned as the wrapper's is.)
			$block = self::put_look( $block, $kind, '/^is-style-/', self::BUTTON_PROP, self::BUTTON_SETTINGS, self::BUTTON_TAG_CLASS );
			$block = self::strip_anchor( $block, self::BUTTON_TAG_CLASS, self::BUTTON_PROP );
		}
		$kids  = (array) ( $block['innerBlocks'] ?? array() );
		$boxes = $look['card'] !== null && $role !== 'faq' ? array_keys( array_filter( $kids, static fn( $c ): bool => is_array( $c ) && self::is_box( $c ) ) ) : array();
		foreach ( $kids as $i => $child ) {
			if ( is_array( $child ) ) {
				if ( count( $boxes ) >= 2 && in_array( $i, $boxes, true ) ) {
					$child = self::put_card( $child, $look['card'] );
				}
				$block['innerBlocks'][ $i ] = self::dress( $child, $look, $role );
			}
		}

		return $block;
	}

	/** The classes a box's settings write in its tag. */
	private const BOX_TAG_CLASS = '/^(?:has-background|has-[a-z0-9-]+-background-color|has-border-color|has-[a-z0-9-]+-border-color)$/';
	/** A box's look without its padding: what a question of a section of questions takes from the Home's (a line, no ground). */
	private const LINE_CLASS    = '/^(?:(?:xs|sm|md|lg|xl|2xl)[:-])*(?:rounded(?:-|$)|shadow|[a-z]+-shadow$|dxai-sh-|border(?:-|$)|bg-)/';
	private const LINE_PROP     = '/^(?:border(?:-[a-z-]+)?|box-shadow|background(?:-[a-z-]+)?)$/';
	private const LINE_SETTINGS = array( 'style.border', 'style.shadow', 'backgroundColor', 'style.color.background' );

	/**
	 * A box the builder did not mark as a card: a group of its own ground with a border or a shadow (one of a run of them, dress() says).
	 *
	 * @param array<string, mixed> $block
	 */
	private static function is_box( array $block ): bool {
		$a = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		if ( (string) ( $block['blockName'] ?? '' ) !== 'core/group' || preg_match( '/(^|\s)dxai-lib-card(\s|$)/', (string) ( $a['className'] ?? '' ) ) === 1 ) {
			return false;
		}
		$ground = isset( $a['backgroundColor'] ) || isset( $a['style']['color']['background'] );
		$edge   = isset( $a['style']['border']['width'] ) || isset( $a['style']['border']['color'] ) || isset( $a['style']['shadow'] ) || preg_match( '/(^|\s)(?:[a-z]+-)?(?:shadow|border)(?:-[a-z0-9-]+)?(\s|$)/', (string) ( $a['className'] ?? '' ) ) === 1;

		return $ground && $edge;
	}

	/**
	 * A chip, not a card: a box of a few words and no title (a place's name with a tick). The Home's card round three words is a slab.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function is_chip( array $block ): bool {
		$html = serialize_block( $block );

		return preg_match( '/<h[1-6]\b/i', $html ) !== 1 && str_word_count( trim( wp_strip_all_tags( $html ) ) ) <= 6;
	}

	/** @param array<string, mixed> $look A look_of() that is a box: a ground, a border or a shadow, by class or by declaration. */
	private static function surface( array $look ): bool {
		foreach ( (array) $look['class'] as $t ) {
			if ( preg_match( '/^(?:(?:xs|sm|md|lg|xl|2xl)[:-])*(?:bg-|border(?:-|$)|shadow|[a-z]+-shadow)/', (string) $t ) === 1 ) {
				return true;
			}
		}
		foreach ( (array) $look['css'] as $d ) {
			if ( preg_match( '/^(?:background|border|box-shadow)/', (string) $d ) === 1 ) {
				return true;
			}
		}

		return false;
	}

	/* ------------------------------------------------------------------------------------------- the ground and the head of a section */

	/**
	 * A box inside a section set full width with nothing of its own to show at that width (no ground, no picture) gives the room at the
	 * sides back: on a theme whose root has padding, a full-width box takes the section's padding away (a negative margin of the root's
	 * padding), and on a phone its titles and cards stood on the edge of the screen (cards · team layout 6, and a third of the team's
	 * layouts like it). It keeps its width (its wide grid stays as wide) and takes the root's padding as its own, in its Dimensions panel.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function contained( array $block, bool $root = false, array $side = array() ): array {
		if ( ! $root && (string) ( $block['blockName'] ?? '' ) === 'core/group' && ( $block['attrs']['align'] ?? '' ) === 'full' && ! self::paints( $block ) ) {
			$wide = true;
			foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $child ) {
				if ( is_array( $child ) && ( in_array( (string) ( $child['blockName'] ?? '' ), array( 'core/cover', 'core/image', 'core/video' ), true ) || \DXAI_UI\Theme\Capabilities::is_block( (string) ( $child['blockName'] ?? '' ), 'picture' ) ) ) {
					$wide = false;
				}
			}
			$pad = (array) ( $block['attrs']['style']['spacing']['padding'] ?? array() );
			if ( $wide && ! isset( $pad['left'] ) && ! isset( $pad['right'] ) ) {
				// (What the box around takes back from it: its own padding, which the layout's negative margin is; else the root's.)
				$left  = is_string( $side['left'] ?? null ) && $side['left'] !== '' ? (string) $side['left'] : 'var(--wp--style--root--padding-left)';
				$right = is_string( $side['right'] ?? null ) && $side['right'] !== '' ? (string) $side['right'] : 'var(--wp--style--root--padding-right)';
				$block['attrs']['style']['spacing']['padding']['left']  = $left;
				$block['attrs']['style']['spacing']['padding']['right'] = $right;
				$room = 'padding-right:' . $right . ';padding-left:' . $left;
				foreach ( (array) $block['innerContent'] as $k => $chunk ) {
					if ( is_string( $chunk ) && trim( $chunk ) !== '' ) {
						$block['innerContent'][ $k ] = preg_match( '/^\s*<[a-z][a-z0-9]*\b[^>]*\sstyle="/i', $chunk ) === 1
							? (string) preg_replace( '/^(\s*<[a-z][a-z0-9]*\b[^>]*\sstyle=")([^"]*)"/i', '$1$2;' . $room . '"', $chunk, 1 )
							: (string) preg_replace( '/^(\s*<[a-z][a-z0-9]*\b[^>]*?)(\s*>)/i', '$1 style="' . $room . '"$2', $chunk, 1 );
						break;
					}
				}
				$block['innerHTML'] = implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) );
			}
		}
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $i => $child ) {
			if ( is_array( $child ) && ! empty( $child['blockName'] ) ) {
				$block['innerBlocks'][ $i ] = self::contained( $child, false, (array) ( $block['attrs']['style']['spacing']['padding'] ?? array() ) );
			}
		}

		return $block;
	}

	/** A colour that white reads on better than black. */
	private static function dark( string $hex ): bool {
		return Color_Math::contrast( '#ffffff', $hex ) > Color_Math::contrast( '#000000', $hex );
	}

	/**
	 * The Home's grounds: the light one its sections stand on between the white ones (the most common that is not white), the band of its
	 * call to action, and the band of its other dark sections (its steps), the hero's left out. Null for one the Home has not.
	 *
	 * @return array{light: array<string, mixed>|null, cta: array<string, mixed>|null, dark: array<string, mixed>|null}
	 */
	private static function grounds_of( int $home ): array {
		$light = array();
		$dark  = array();
		$cta   = null;
		foreach ( Section_Library::for_page( $home ) as $n => $component ) {
			$root = (array) $component['block'];
			if ( (string) ( $root['blockName'] ?? '' ) !== 'core/group' ) {
				continue;
			}
			$hex  = Button::ground_hex( is_array( $root['attrs'] ?? null ) ? $root['attrs'] : array(), $home );
			$look = self::look_of( $root, self::GROUND_CLASS, self::GROUND_PROP );
			if ( $hex === '' || $look['key'] === '' ) {
				continue;
			}
			$role = Section_Roles::of( $root, 0 === (int) $n );
			if ( self::dark( $hex ) ) {
				if ( $role === 'cta' ) {
					$cta = $cta ?? $look;
				} elseif ( $role !== 'hero' ) {
					$dark[] = $look;
				}
			} elseif ( Color_Math::distance( $hex, '#ffffff' ) >= 1.0 ) {
				$light[] = $look;
			}
		}

		return array(
			'light' => self::dominant( $light, 1, true ),
			'cta'   => $cta,
			'dark'  => self::dominant( $dark, 1, true ),
		);
	}

	/**
	 * The section on a ground of the Home's: a call to action on the band the Home's call to action is (a white one, an orange one, a navy
	 * one with stripes: the team's sites' own), a dark section on the Home's other band, a light tint (the team's #f9fafb, a grey at half
	 * strength) on the Home's light ground. White, or a ground the Home has none of, stays. Its words are made to read on the new ground
	 * after (Block_Library::legible()).
	 *
	 * @param array<string, mixed> $section
	 * @return array<string, mixed>
	 */
	private static function ground( array $section, array $look, string $role, int $home ): array {
		if ( (string) ( $section['blockName'] ?? '' ) !== 'core/group' ) {
			return $section;
		}
		$grounds = is_array( $look['ground'] ?? null ) ? $look['ground'] : array();
		$hex     = Button::ground_hex( is_array( $section['attrs'] ?? null ) ? $section['attrs'] : array(), $home, true );
		$put     = null;
		// (A call to action is a band with a title: a strip of points and a button is a strip, and a strip on a dark ground of the team's
		// stands on the page's own: its drawings are in the Home's colours, and vanish on the Home's band of the same colour.)
		$titled = self::title_path( $section ) !== null;
		if ( $role === 'cta' && $titled ) {
			$put = $grounds['cta'] ?? $grounds['dark'] ?? null;
		} elseif ( $hex !== '' && self::dark( $hex ) && ! $titled ) {
			$put = array(
				'class' => array(),
				'css'   => array(),
			);
		} elseif ( $hex !== '' && self::dark( $hex ) ) {
			$put = $grounds['dark'] ?? $grounds['cta'] ?? null;
		} elseif ( $hex !== '' && Color_Math::distance( $hex, '#ffffff' ) >= 1.0 ) {
			$put = $grounds['light'] ?? null;
		}

		if ( $put === null ) {
			return $section;
		}
		// A ground the Home sets in its Colour panel (a preset of the palette) is set in the section's own, not written as CSS.
		if ( (array) $put['class'] === array() && preg_match( '/^background-color:var\(--wp--preset--color--([a-z0-9-]+)\)$/', implode( ';', (array) $put['css'] ), $m ) === 1 ) {
			$bare = array(
				'class' => array(),
				'css'   => array(),
			);

			return Block_Library::set_colour( self::put_look( $section, $bare, self::GROUND_CLASS, self::GROUND_PROP, self::GROUND_SETTINGS, self::GROUND_TAG_CLASS ), 'background', $m[1] );
		}

		return self::put_look( $section, $put, self::GROUND_CLASS, self::GROUND_PROP, self::GROUND_SETTINGS, self::GROUND_TAG_CLASS );
	}

	/**
	 * The Home's section heads: whether its section titles stand at the left (more of them not centred than centred), and the colour most
	 * of the ones on a light ground have (the Colour panel's preset; '' when no one colour is most of them). Null with fewer than two.
	 *
	 * @return array{left: bool, color: string}|null
	 */
	private static function head_of( int $home ): ?array {
		$left   = 0;
		$centre = 0;
		$colors = array();
		$light  = 0;
		foreach ( Section_Library::for_page( $home ) as $n => $component ) {
			$root = (array) $component['block'];
			$path = 0 === (int) $n ? null : self::title_path( $root );
			if ( $path === null ) {
				continue;
			}
			$title = Block_Tree::at( $root, $path );
			if ( self::centred( $title ) ) {
				++$centre;
			} else {
				++$left;
			}
			$hex = Button::ground_hex( is_array( $root['attrs'] ?? null ) ? $root['attrs'] : array(), $home );
			if ( $hex !== '' && self::dark( $hex ) ) {
				continue;
			}
			++$light;
			$slug = (string) ( $title['attrs']['textColor'] ?? '' );
			if ( $slug === '' && preg_match( '/^var:preset\|color\|([a-z0-9-]+)$/', (string) ( $title['attrs']['style']['color']['text'] ?? '' ), $m ) === 1 ) {
				$slug = $m[1];
			}
			if ( $slug !== '' ) {
				$colors[ $slug ] = ( $colors[ $slug ] ?? 0 ) + 1;
			}
		}
		if ( $left + $centre < 2 ) {
			return null;
		}
		arsort( $colors );
		$color = (string) array_key_first( $colors );

		return array(
			'left'  => $left > $centre,
			'color' => $color !== '' && $colors[ $color ] * 2 > $light ? $color : '',
		);
	}

	/**
	 * The section's head set as the Home's are: where the Home's titles stand at the left, the section's title, the line of a few words
	 * over it and the paragraph under it are not centred, nor is the box they stand in (its content to the left); on a light ground the
	 * title is in the Home's titles' colour. A section with no title, or a Home that says nothing, is left as it is.
	 *
	 * @param array<string, mixed> $section
	 * @return array<string, mixed>
	 */
	private static function head( array $section, array $look, int $home ): array {
		$head = $look['head'] ?? null;
		$path = is_array( $head ) ? self::title_path( $section ) : null;
		if ( ! is_array( $head ) || $path === null ) {
			return $section;
		}
		$i      = (int) array_pop( $path );
		$parent = &Block_Tree::at( $section, $path );
		$kids   = (array) $parent['innerBlocks'];
		// The line over the title as the Home's eyebrow: a team's badge round it (a skewed box of colour) is no box any more.
		if ( $i > 0 && is_array( $kids[ $i - 1 ] ?? null ) && ! self::is_eyebrow( $kids[ $i - 1 ] ) && self::holds_eyebrow( $kids[ $i - 1 ] ) ) {
			$parent['innerBlocks'][ $i - 1 ] = self::unbadge( $kids[ $i - 1 ], $look, (bool) $head['left'] );
			$kids                            = (array) $parent['innerBlocks'];
		}
		if ( $head['left'] ) {
			$lines = array( $i );
			if ( $i > 0 && is_array( $kids[ $i - 1 ] ?? null ) && self::is_eyebrow( $kids[ $i - 1 ] ) ) {
				$lines[] = $i - 1;
			}
			if ( (string) ( $kids[ $i + 1 ]['blockName'] ?? '' ) === 'core/paragraph' ) {
				$lines[] = $i + 1;
			}
			$moved = false;
			foreach ( $lines as $k ) {
				if ( self::centred( $kids[ $k ] ) ) {
					$parent['innerBlocks'][ $k ] = self::uncentre( $kids[ $k ] );
					$moved                       = true;
				}
			}
			if ( $moved && $path !== array() ) {
				$parent = self::uncentre( $parent );
				if ( (string) ( $parent['attrs']['layout']['type'] ?? '' ) === 'constrained' ) {
					$parent['attrs']['layout']['justifyContent'] = 'left';
				}
			}
		}
		unset( $parent );
		// The head's box among the section's other boxes: over a wide grid it is as wide as the grid (its left edge is the grid's); a narrow
		// column of steps beside it in the middle stands at the left with it.
		if ( $head['left'] && $path !== array() ) {
			$at  = (int) array_pop( $path );
			$box = &Block_Tree::at( $section, $path );
			// (A box that puts its content in the middle — the head and a row of icons under it — puts it at the left.)
			if ( $path !== array() && ( $box['attrs']['layout']['justifyContent'] ?? '' ) === 'center' ) {
				$box = self::uncentre( $box );
			}
			$wide = false;
			foreach ( (array) $box['innerBlocks'] as $k => $sib ) {
				if ( $k !== $at && is_array( $sib ) && in_array( (string) ( $sib['attrs']['align'] ?? '' ), array( 'wide', 'full' ), true ) ) {
					$wide = true;
				}
			}
			foreach ( (array) $box['innerBlocks'] as $k => $sib ) {
				if ( ! is_array( $sib ) ) {
					continue;
				}
				if ( $k === $at && $wide ) {
					$box['innerBlocks'][ $k ] = self::widen( $sib );
				} elseif ( $k !== $at && (string) ( $sib['attrs']['layout']['type'] ?? '' ) === 'constrained' && isset( $sib['attrs']['layout']['contentSize'] ) && ! isset( $sib['attrs']['layout']['justifyContent'] ) ) {
					$box['innerBlocks'][ $k ]['attrs']['layout']['justifyContent'] = 'left';
				}
			}
			unset( $box );
			$path[] = $at;
		}
		$hex = Button::ground_hex( is_array( $section['attrs'] ?? null ) ? $section['attrs'] : array(), $home );
		if ( $head['color'] !== '' && ( $hex === '' || ! self::dark( $hex ) ) ) {
			$title = &Block_Tree::at( $section, array_merge( $path, array( $i ) ) );
			$title = Block_Library::set_colour( $title, 'text', $head['color'] );
			unset( $title );
		}

		return $section;
	}

	/**
	 * Where the section's title is: the first H2 that is not in a card or a list's row (a card's own title is an H3, but a team's card may
	 * hold an H2). Null when it has none.
	 *
	 * @param array<string, mixed> $block
	 * @return array<int, int>|null
	 */
	private static function title_path( array $block ): ?array {
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $i => $child ) {
			if ( ! is_array( $child ) || isset( $child['attrs']['dxaiRepeat'] ) || preg_match( '/(^|\s)dxai-lib-card(\s|$)/', (string) ( $child['attrs']['className'] ?? '' ) ) === 1 ) {
				continue;
			}
			if ( (string) ( $child['blockName'] ?? '' ) === 'core/heading' && (int) ( $child['attrs']['level'] ?? 2 ) === 2 ) {
				return array( (int) $i );
			}
			$below = self::title_path( $child );
			if ( $below !== null ) {
				return array_merge( array( (int) $i ), $below );
			}
		}

		return null;
	}

	/**
	 * A box round the line over a title (a team's badge: a group with a ground of its own, skewed by its custom CSS), one inside the other
	 * down to the line.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function holds_eyebrow( array $block ): bool {
		for ( $depth = 0; $depth < 3; $depth++ ) {
			$kids = array_values( array_filter( (array) ( $block['innerBlocks'] ?? array() ), static fn( $c ): bool => is_array( $c ) && ! empty( $c['blockName'] ) ) );
			if ( (string) ( $block['blockName'] ?? '' ) !== 'core/group' || count( $kids ) !== 1 ) {
				return false;
			}
			if ( self::is_eyebrow( $kids[0] ) ) {
				return true;
			}
			$block = $kids[0];
		}

		return false;
	}

	/**
	 * The badge round a line over a title made plain, as the Home's eyebrows are: the boxes lose their ground, border, corners, shadow and
	 * their own CSS (the skew), and the line takes the Home's eyebrow's look (centred no more where the Home's titles stand at the left).
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function unbadge( array $block, array $look, bool $left ): array {
		$plain = array(
			'class' => array(),
			'css'   => array(),
		);
		if ( self::is_eyebrow( $block ) ) {
			// (The badge's room round the words goes with it: the line stands where the title does.)
			$block = self::put_look( $block, $plain, self::BOX_CLASS, self::BOX_PROP, self::BOX_SETTINGS, self::BOX_TAG_CLASS );
			if ( ( $look['eyebrow'] ?? null ) !== null ) {
				$block = self::put_look( $block, $look['eyebrow'], self::TYPE_CLASS, self::TYPE_PROP, self::TYPE_SETTINGS, '/^(?:has-text-color|has-[a-z0-9-]+-color|has-[a-z0-9-]+-font-size)$/' );
			}

			return $left && self::centred( $block ) ? self::uncentre( $block ) : $block;
		}
		unset( $block['attrs']['style']['css'] );
		if ( isset( $block['attrs']['style'] ) && $block['attrs']['style'] === array() ) {
			unset( $block['attrs']['style'] );
		}
		$block = self::put_look( $block, $plain, self::BOX_CLASS, '/^(?:border(?:-[a-z-]+)?|box-shadow|background(?:-[a-z-]+)?|padding(?:-[a-z]+)?|transform)$/', self::BOX_SETTINGS, self::BOX_TAG_CLASS );
		foreach ( (array) $block['innerBlocks'] as $k => $child ) {
			if ( is_array( $child ) && ! empty( $child['blockName'] ) ) {
				$block['innerBlocks'][ $k ] = self::unbadge( $child, $look, $left );
			}
		}

		return $block;
	}

	/** @param array<string, mixed> $block A line of one to four words (a paragraph or a theme's label). */
	private static function is_eyebrow( array $block ): bool {
		$name = (string) ( $block['blockName'] ?? '' );
		if ( ! in_array( $name, array( 'core/paragraph', 'dxai-ui/text' ), true ) && ! \DXAI_UI\Theme\Capabilities::is_block( $name, 'span' ) ) {
			return false;
		}
		$n = str_word_count( trim( wp_strip_all_tags( (string) ( $block['innerHTML'] ?? '' ) ) ) );

		return $n >= 1 && $n <= 6;
	}

	/**
	 * Whether a line or a box is centred: by its own alignment setting, a centring class, `text-align:center`, or the content of its
	 * layout put in the middle.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function centred( array $block ): bool {
		$a = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		if ( ( $a['textAlign'] ?? '' ) === 'center' || ( $a['align'] ?? '' ) === 'center' || ( $a['style']['typography']['textAlign'] ?? '' ) === 'center' || ( $a['layout']['justifyContent'] ?? '' ) === 'center' ) {
			return true;
		}
		foreach ( self::tokens( (string) ( $a['className'] ?? '' ) ) as $t ) {
			if ( in_array( self::base( $t ), self::CENTRE_CLASS, true ) ) {
				return true;
			}
		}

		return preg_match( '/(?:^|;)\s*text-align\s*:\s*center/i', (string) ( $a['dxaiCss'] ?? '' ) ) === 1 || preg_match( '/^\s*<[a-z0-9]+\b[^>]*\bclass="[^"]*\bhas-text-align-center\b/i', (string) ( $block['innerHTML'] ?? '' ) ) === 1;
	}

	/**
	 * A line or a box no longer centred: its alignment setting, its centring classes and `text-align:center` off (in its attributes and
	 * what they wrote in its tag), and the content of a flex layout to the start. The block as it was when its tag cannot be written.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function uncentre( array $block ): array {
		$whole = $block;
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		foreach ( array( 'textAlign', 'align' ) as $k ) {
			if ( ( $attrs[ $k ] ?? '' ) === 'center' ) {
				unset( $attrs[ $k ] );
			}
		}
		if ( ( $attrs['style']['typography']['textAlign'] ?? '' ) === 'center' ) {
			unset( $attrs['style']['typography']['textAlign'] );
			if ( $attrs['style']['typography'] === array() ) {
				unset( $attrs['style']['typography'] );
			}
			if ( $attrs['style'] === array() ) {
				unset( $attrs['style'] );
			}
		}
		if ( ( $attrs['layout']['justifyContent'] ?? '' ) === 'center' ) {
			$attrs['layout']['justifyContent'] = 'left';
		}
		$block['attrs'] = $attrs;
		$split          = self::split_class( (string) ( $attrs['className'] ?? '' ), static fn( string $t ): bool => in_array( self::base( $t ), self::CENTRE_CLASS, true ) );
		$css            = self::split_css( (string) ( $attrs['dxaiCss'] ?? '' ), static fn( string $p ): bool => $p === 'text-align' );
		// What the alignment setting wrote in the tag: its class, and the declaration.
		foreach ( (array) $block['innerContent'] as $k => $chunk ) {
			if ( is_string( $chunk ) && trim( $chunk ) !== '' ) {
				$block['innerContent'][ $k ] = (string) preg_replace_callback(
					'/^(\s*<[a-z][a-z0-9]*\b)([^>]*)(>)/i',
					static function ( array $m ): string {
						$attr = (string) preg_replace( '/(\sclass="[^"]*?)\s*\bhas-text-align-center\b/', '$1', $m[2] );
						$attr = (string) preg_replace( '/\sclass="\s*"/', '', $attr );
						$attr = (string) preg_replace( '/(\sstyle="[^"]*?)text-align\s*:\s*center;?/i', '$1', $attr );
						$attr = (string) preg_replace( '/\sstyle="\s*"/', '', $attr );

						return $m[1] . $attr . $m[3];
					},
					$chunk,
					1
				);
				break;
			}
		}
		$block['innerHTML'] = implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) );
		// Its own classes and CSS rewritten only when they centre it (a tag that says a little less than its attributes is left so).
		if ( $split['mine'] === array() && $css['mine'] === array() ) {
			return $block;
		}

		return self::restyle( $block, implode( ' ', $split['rest'] ), implode( ';', $css['rest'] ) ) ?? $whole;
	}

	/**
	 * A box made as wide as the wide blocks beside it (`alignwide`), so a head stands over the left edge of the grid of cards it heads.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function widen( array $block ): array {
		if ( isset( $block['attrs']['align'] ) || (string) ( $block['blockName'] ?? '' ) !== 'core/group' ) {
			return $block;
		}
		$block['attrs']['align'] = 'wide';
		foreach ( (array) $block['innerContent'] as $k => $chunk ) {
			if ( is_string( $chunk ) && trim( $chunk ) !== '' ) {
				$block['innerContent'][ $k ] = (string) preg_replace( '/^(\s*<[a-z][a-z0-9]*\b[^>]*?\sclass=")(wp-block-group)\b/i', '$1$2 alignwide', $chunk, 1 );
				break;
			}
		}
		$block['innerHTML'] = implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) );

		return $block;
	}

	/** @param array<string, mixed> $block A block that paints a ground of its own (its Colour panel, or a background class). */
	private static function paints( array $block ): bool {
		$a = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();

		return isset( $a['backgroundColor'] ) || isset( $a['style']['color']['background'] ) || preg_match( '/(^|\s)bg-/', (string) ( $a['className'] ?? '' ) ) === 1;
	}

	/**
	 * A card in the Home's cards' look. A team's card is often boxes in boxes, one inside the other (a column with rounded corners and a
	 * shadow, the link box in it painted white, a group in that painted white again and padded): the card is the outer box that paints, and
	 * takes the Home's look; the boxes around it and in it lose their own ground, border, corners and shadow, so it is one card, not a white
	 * box in a grey one. Words already padded inside it do not take the Home's padding as well.
	 *
	 * @param array<string, mixed> $block
	 * @param array<string, mixed> $look
	 * @return array<string, mixed>
	 */
	private static function put_card( array $block, array $look ): array {
		// The run of boxes one inside the other, from the marked block down.
		$paths = array( array() );
		$node  = $block;
		for ( $depth = 0; $depth < 3; $depth++ ) {
			$kids = array_keys( array_filter( (array) ( $node['innerBlocks'] ?? array() ), static fn( $c ): bool => is_array( $c ) && ! empty( $c['blockName'] ) ) );
			// (Boxes only: a picture alone in a card keeps its corners.)
			$name = count( $kids ) === 1 ? (string) $node['innerBlocks'][ $kids[0] ]['blockName'] : '';
			if ( ! in_array( $name, array( 'core/group', 'core/column' ), true ) && ! \DXAI_UI\Theme\Capabilities::is_block( $name, 'link_box' ) ) {
				break;
			}
			$paths[] = array_merge( end( $paths ), array( (int) $kids[0] ) );
			$node    = (array) $node['innerBlocks'][ $kids[0] ];
		}
		$card = 0;
		foreach ( $paths as $n => $path ) {
			if ( self::paints( Block_Tree::at( $block, $path ) ) ) {
				$card = $n;
				break;
			}
		}
		$padded = false;
		foreach ( array_slice( $paths, $card + 1 ) as $path ) {
			$padded = $padded || isset( Block_Tree::at( $block, $path )['attrs']['style']['spacing']['padding'] );
		}
		if ( $padded ) {
			$look['class'] = array_values( array_filter( (array) $look['class'], static fn( string $t ): bool => preg_match( '/^(?:(?:xs|sm|md|lg|xl|2xl)[:-])*p[xytblr]?-/', $t ) !== 1 ) );
			$look['css']   = array_values( array_filter( (array) $look['css'], static fn( string $d ): bool => preg_match( '/^padding/', $d ) !== 1 ) );
		}
		$none = array(
			'class' => array(),
			'css'   => array(),
		);
		foreach ( $paths as $n => $path ) {
			$box = &Block_Tree::at( $block, $path );
			$box = $n === $card ? self::put_look( $box, $look, self::BOX_CLASS, self::BOX_PROP, self::BOX_SETTINGS, self::BOX_TAG_CLASS ) : self::put_look( $box, $none, self::LINE_CLASS, self::LINE_PROP, self::LINE_SETTINGS, self::BOX_TAG_CLASS );
			unset( $box );
		}

		return $block;
	}

	/**
	 * The look of the Home's questions: the box each question of its section of questions is (a line under it, no ground of its own).
	 *
	 * @return array<string, mixed>|null
	 */
	private static function faq_of( int $home ): ?array {
		$found = array();
		foreach ( Section_Library::for_page( $home ) as $component ) {
			$block = (array) $component['block'];
			foreach ( (array) ( $component['repeats'] ?? array() ) as $rep ) {
				if ( (string) ( $rep['kind'] ?? '' ) !== 'faq' ) {
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

		return self::dominant( $found, 2, true );
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

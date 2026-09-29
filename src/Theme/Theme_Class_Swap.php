<?php
/**
 * The theme's own colour classes in a design's content.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Theme;

use DXAI_UI\Compiler\Color_Usage;
use DXAI_UI\Compiler\Token_Styles;
use DXAI_UI\Compiler\Utility_Classes;

/**
 * Where a design's text colour follows a theme colour directly (Theme_Binding),
 * its blocks can carry the theme's own class for it instead of the plugin's:
 * `text-dxai-neutral-100` becomes american-restoration's `text-white`, and a
 * core paragraph or heading coloured `text-dxai-brand` gets core's colour
 * setting `textColor: primary` (`has-primary-color`) — the colour a person
 * sees selected in the block's Colour panel, named as the theme names it.
 *
 * Only classes change; the blocks' CSS (`dxaiCss`, `dxaiInner`) and its hashes
 * stay, so every block stays valid. Not for a text colour kept or adjusted for
 * readability (it would no longer follow its contrast check), nor for a slug
 * the design also uses as a token of its own. A swapped class is still
 * anchored to its token inside the design (css()), so it keeps the contrast
 * check and shows the design's colour again when the design stops following
 * the theme.
 *
 * New imports get it (Theme_Binding::after_import()); designs imported before
 * get it from the Library, where it can be reverted: each post's content
 * before the swap is kept, and restored as long as the post was not edited
 * since.
 */
final class Theme_Class_Swap {

	/** On the design's Home: what was swapped, and in which posts. */
	public const META = '_dxai_ui_theme_classes';

	/** On each swapped post: its content before the swap. */
	public const BEFORE = '_dxai_ui_before_theme_classes';

	/** Core blocks with a text colour setting that take `textColor`. */
	private const CORE_TEXT = array( 'core/paragraph', 'core/heading', 'core/list', 'core/group', 'core/quote' );

	/** Whether a design's content carries theme classes. */
	public static function applied( int $home ): bool {
		$meta = get_post_meta( $home, self::META, true );

		return is_array( $meta ) && ! empty( $meta['posts'] );
	}

	/**
	 * For the Library: whether the content carries theme classes, and which — the ones swapped in, or else the ones
	 * that can be.
	 *
	 * @return array{applied:bool, swaps:array<int, array{token:string, class:string, setting:string}>}
	 */
	public static function summary( int $home ): array {
		$applied = self::applied( $home );
		$meta    = get_post_meta( $home, self::META, true );
		$plan    = $applied ? (array) ( $meta['plan'] ?? array() ) : self::plan( $home );
		$entries = (array) ( Theme_Palette::current()['entries'] ?? array() );
		$swaps   = array();
		foreach ( $plan as $token => $swap ) {
			$slug    = (string) ( $swap['text_color'] ?? '' );
			$swaps[] = array(
				'token'   => (string) $token,
				'class'   => (string) ( $swap['class'] ?? '' ),
				'setting' => $slug === '' ? '' : (string) ( $entries[ $slug ]['name'] ?? $slug ),
			);
		}

		return array(
			'applied' => $applied,
			'swaps'   => $swaps,
		);
	}

	/**
	 * What each directly bound text colour becomes: a theme utility class, or core's text colour setting.
	 *
	 * @return array<string, array{class?:string, text_color?:string, to:string}> token => swap
	 */
	public static function plan( int $home ): array {
		if ( ! Theme_Binding::follows( $home ) ) {
			return array();
		}
		$binding = Theme_Binding::get( $home );
		$design  = (array) ( $binding['design'] ?? array() );
		$text    = Theme_Binding::text_values( $home );
		$palette = Theme_Palette::current();
		$out     = array();
		foreach ( (array) ( $binding['tokens'] ?? array() ) as $token => $bound ) {
			$row = array( 'token' => (string) $token );
			$to  = (string) ( $bound['to'] ?? '' );
			$fgs = (int) ( $binding['usage']['tokens'][ $token ]['fg'] ?? 0 );
			if ( $to === '' || isset( $text[ $token ] ) || $fgs < 1 ) {
				continue;
			}
			$match = Utility_Classes::match( 'color:var(--wp--preset--color--' . $to . ')' );
			if ( count( $match['classes'] ) === 1 && $match['remainder'] === '' ) {
				$out[ $row['token'] ] = array(
					'class' => (string) $match['classes'][0],
					'to'    => $to,
				);
			} elseif ( isset( $palette['entries'][ $to ] ) && ! isset( $design[ $to ] ) ) {
				$out[ $row['token'] ] = array(
					'text_color' => $to,
					'to'         => $to,
				);
			}
		}

		return $out;
	}

	/**
	 * Swap the classes in every post of the design.
	 *
	 * @return array{posts:int, swaps:int}
	 */
	public static function apply( int $home ): array {
		$plan = self::plan( $home );
		$meta = get_post_meta( $home, self::META, true );
		$meta = is_array( $meta ) ? $meta : array( 'posts' => array() );
		$sum  = array(
			'posts' => 0,
			'swaps' => 0,
		);
		if ( $plan === array() ) {
			return $sum;
		}
		foreach ( Color_Usage::posts( $home ) as $post_id ) {
			$before = (string) get_post_field( 'post_content', $post_id );
			$count  = 0;
			$blocks = parse_blocks( $before );
			foreach ( $blocks as $i => $block ) {
				$blocks[ $i ] = self::swap_block( $block, $plan, $count );
			}
			if ( $count === 0 ) {
				continue;
			}
			$after = serialize_blocks( $blocks );
			// The content before the first swap — or, when the post changed since the last one (a new import, an
			// edit), before this one: an older copy would put back content the post no longer has.
			if ( get_post_meta( $post_id, self::BEFORE, true ) === '' || md5( $before ) !== ( $meta['posts'][ $post_id ] ?? '' ) ) {
				update_post_meta( $post_id, self::BEFORE, wp_slash( $before ) );
			}
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => wp_slash( $after ),
				)
			);
			$meta['posts'][ $post_id ] = md5( (string) get_post_field( 'post_content', $post_id ) );
			++$sum['posts'];
			$sum['swaps'] += $count;
		}
		$meta['plan']        = array_merge( (array) ( $meta['plan'] ?? array() ), $plan );
		$meta['applied_gmt'] = gmdate( 'c' );
		update_post_meta( $home, self::META, $meta );

		return $sum;
	}

	/**
	 * Put the design's own classes back: each post's content from before the swap where it was not edited since,
	 * else the swapped classes turned back one by one.
	 *
	 * @return array{posts:int}
	 */
	public static function revert( int $home ): array {
		$meta = get_post_meta( $home, self::META, true );
		$sum  = array( 'posts' => 0 );
		if ( ! is_array( $meta ) ) {
			return $sum;
		}
		$plan = (array) ( $meta['plan'] ?? array() );
		foreach ( (array) ( $meta['posts'] ?? array() ) as $post_id => $hash ) {
			$post_id = (int) $post_id;
			$now     = (string) get_post_field( 'post_content', $post_id );
			$before  = (string) get_post_meta( $post_id, self::BEFORE, true );
			$content = $before !== '' && md5( $now ) === $hash ? $before : serialize_blocks( array_map( static fn( $b ) => self::unswap_block( $b, $plan ), parse_blocks( $now ) ) );
			if ( $content !== $now ) {
				wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => wp_slash( $content ),
					)
				);
				++$sum['posts'];
			}
			delete_post_meta( $post_id, self::BEFORE );
		}
		delete_post_meta( $home, self::META );

		return $sum;
	}

	/**
	 * Swapped classes anchored to their tokens inside the design: they show exactly what the token shows — the
	 * theme colour, the colour the contrast check chose, or the design's own when it does not follow the theme.
	 */
	public static function css( int $post_id ): string {
		$home = Theme_Binding::home_of( $post_id );
		$meta = get_post_meta( $home, self::META, true );
		if ( ! is_array( $meta ) || empty( $meta['plan'] ) ) {
			return '';
		}
		$scope = '.dxai-ui.dxai-ui--' . $home;
		$out   = '';
		foreach ( (array) $meta['plan'] as $token => $swap ) {
			$token = sanitize_key( (string) $token );
			$sel   = isset( $swap['class'] ) ? '.' . sanitize_html_class( (string) $swap['class'] ) : '.has-' . sanitize_key( (string) ( $swap['text_color'] ?? '' ) ) . '-color';
			if ( $token === '' || $sel === '.' || $sel === '.has--color' ) {
				continue;
			}
			$out .= $scope . ' ' . $sel . '{color:var(--dxai-' . $token . '--fg,var(--dxai-' . $token . ')) !important}';
		}

		return $out;
	}

	/**
	 * @param array<string, mixed>                $block
	 * @param array<string, array<string, string>> $plan
	 * @return array<string, mixed>
	 */
	private static function swap_block( array $block, array $plan, int &$count ): array {
		$class = (string) ( $block['attrs']['className'] ?? '' );
		foreach ( $plan as $token => $swap ) {
			$from = 'text-dxai-' . $token;
			// On the block itself, or (a class swap) on a run inside its text.
			if ( ! self::has_class( $class, $from ) && ! ( isset( $swap['class'] ) && str_contains( (string) ( $block['innerHTML'] ?? '' ), $from ) ) ) {
				continue;
			}
			if ( isset( $swap['class'] ) ) {
				$block = self::rename( $block, $from, (string) $swap['class'] );
				++$count;
			} elseif ( in_array( (string) ( $block['blockName'] ?? '' ), self::CORE_TEXT, true ) && empty( $block['attrs']['textColor'] ) && self::root_has( $block, $from ) ) {
				$slug                         = (string) $swap['text_color'];
				$block                        = self::rename( $block, $from, '', true );
				$block['attrs']['textColor'] = $slug;
				$block                        = self::add_root_classes( $block, array( 'has-' . $slug . '-color', 'has-text-color' ) );
				++$count;
			}
			$class = (string) ( $block['attrs']['className'] ?? '' );
		}
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $i => $child ) {
			$block['innerBlocks'][ $i ] = self::swap_block( (array) $child, $plan, $count );
		}

		return $block;
	}

	/**
	 * @param array<string, mixed>                $block
	 * @param array<string, array<string, string>> $plan
	 * @return array<string, mixed>
	 */
	private static function unswap_block( array $block, array $plan ): array {
		foreach ( $plan as $token => $swap ) {
			$to = 'text-dxai-' . $token;
			if ( isset( $swap['class'] ) && self::has_class( (string) ( $block['attrs']['className'] ?? '' ), (string) $swap['class'] ) ) {
				$block = self::rename( $block, (string) $swap['class'], $to );
			} elseif ( isset( $swap['text_color'] ) && ( $block['attrs']['textColor'] ?? '' ) === $swap['text_color'] ) {
				unset( $block['attrs']['textColor'] );
				$block = self::rename( $block, 'has-' . $swap['text_color'] . '-color', '', true );
				$block = self::rename( $block, 'has-text-color', '', true );
				$block = self::add_root_classes( $block, array( $to ), true );
			}
		}
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $i => $child ) {
			$block['innerBlocks'][ $i ] = self::unswap_block( (array) $child, $plan );
		}

		return $block;
	}

	/**
	 * A class renamed (or removed) in the block's className and in its markup; only the root element's when
	 * $root_only.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function rename( array $block, string $from, string $to, bool $root_only = false ): array {
		$re = '/(?<![\w-])' . preg_quote( $from, '/' ) . '(?![\w-])/';
		if ( isset( $block['attrs']['className'] ) ) {
			$name = trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( $re, $to, (string) $block['attrs']['className'] ) ) );
			if ( $name === '' ) {
				unset( $block['attrs']['className'] );
			} else {
				$block['attrs']['className'] = $name;
			}
		}
		$edit = static function ( string $html ) use ( $re, $to, $root_only ): string {
			if ( ! $root_only ) {
				return (string) preg_replace_callback( '/\sclass="([^"]*)"/', static fn( $m ) => ' class="' . trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( $re, $to, $m[1] ) ) ) . '"', $html );
			}

			return (string) preg_replace_callback( '/^(\s*<[a-z][a-z0-9]*\b[^>]*?\sclass=")([^"]*)(")/i', static fn( $m ) => $m[1] . trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( $re, $to, $m[2] ) ) ) . $m[3], $html, 1 );
		};
		$block['innerHTML'] = $edit( (string) ( $block['innerHTML'] ?? '' ) );
		foreach ( (array) ( $block['innerContent'] ?? array() ) as $i => $chunk ) {
			if ( is_string( $chunk ) ) {
				$block['innerContent'][ $i ] = $i === 0 || ! $root_only ? $edit( $chunk ) : $chunk;
			}
		}
		// Markup a block keeps in an attribute too (dxai-ui/text's `content`): save() writes it from there, so it
		// has to say the same as the stored HTML.
		if ( ! $root_only ) {
			foreach ( (array) ( $block['attrs'] ?? array() ) as $name => $value ) {
				if ( is_string( $value ) && ! in_array( $name, array( 'className', 'dxaiCss', 'dxaiStyle' ), true ) && str_contains( $value, 'class=' ) ) {
					$block['attrs'][ $name ] = $edit( $value );
				}
			}
		}

		return $block;
	}

	/**
	 * Classes added to the block's root element (and, with $to_name, to its className too).
	 *
	 * @param array<string, mixed> $block
	 * @param array<int, string>   $classes
	 * @return array<string, mixed>
	 */
	private static function add_root_classes( array $block, array $classes, bool $to_name = false ): array {
		$add = static fn( string $list ): string => trim( $list . ' ' . implode( ' ', array_filter( $classes, static fn( $c ) => ! self::has_class( $list, $c ) ) ) );
		if ( $to_name ) {
			$block['attrs']['className'] = $add( (string) ( $block['attrs']['className'] ?? '' ) );
		}
		$edit = static fn( string $html ): string => (string) preg_replace_callback( '/^(\s*<[a-z][a-z0-9]*\b[^>]*?\sclass=")([^"]*)(")/i', static fn( $m ) => $m[1] . $add( $m[2] ) . $m[3], $html, 1 );
		$block['innerHTML'] = $edit( (string) ( $block['innerHTML'] ?? '' ) );
		if ( isset( $block['innerContent'][0] ) && is_string( $block['innerContent'][0] ) ) {
			$block['innerContent'][0] = $edit( $block['innerContent'][0] );
		}

		return $block;
	}

	private static function has_class( string $list, string $class ): bool {
		return preg_match( '/(?<![\w-])' . preg_quote( $class, '/' ) . '(?![\w-])/', $list ) === 1;
	}

	/** @param array<string, mixed> $block */
	private static function root_has( array $block, string $class ): bool {
		return preg_match( '/^\s*<[a-z][a-z0-9]*\b[^>]*?\sclass="([^"]*)"/i', (string) ( $block['innerHTML'] ?? '' ), $m ) === 1 && self::has_class( $m[1], $class );
	}
}

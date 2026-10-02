<?php
/**
 * Another way of showing one of the Home's sections, made only of what the Home has.
 *
 * @package DXAI_UI\Pages
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

use DXAI_UI\Support\Upload_Paths;

/**
 * Pages made from the Home's own sections look like each other when each section is the Home's section untouched. A variant
 * changes how a section is shown without adding a style or a colour: the order of its cards, the questions it keeps, whether the
 * ticks under its heading are there, which side its picture is on, and which of the Home's pictures it shows. Every change is a
 * move, a removal or a swap of blocks the Home already has, so the section is still styled by the Home's own classes — pixel for
 * pixel the look of the Home — and is a different section to read.
 *
 * What is changed is chosen by a seed (the page, the place of the section), so the same page is made the same each time and two
 * pages are made differently. A change that the design's stylesheet could not take — a class that is styled by its place
 * (`:first-child`, `:nth-child`) — is not made. Each change that was made is named ("rotate:card:2", "drop:list", "flip",
 * "faq:-2", "image:123"): the names are kept with the page, and the quality check counts two sections as one only when they have
 * the same name.
 */
final class Section_Variants {

	/** What a repeated group may be reordered (items that are interchangeable). */
	private const REORDER = array( 'card', 'testimonial', 'logo', 'faq', 'stat', 'contact', 'row', 'gallery' );

	/** Most questions a section keeps no fewer than. */
	private const FAQ_MIN = 4;

	/** Of five, how many times a kind of change is made when the section can take it. */
	public const CHANCE = 4;

	/**
	 * The classes the design's stylesheet styles by their place among their siblings: moving a block that has one changes how
	 * it looks.
	 *
	 * @return array<string, true>|null Null when the stylesheet cannot be read (then nothing is moved).
	 */
	public static function positional_classes( int $home ): ?array {
		$sheet = Upload_Paths::for_meta( $home, '_dxai_ui_css_url' );
		if ( (string) $sheet['path'] === '' || ! is_readable( (string) $sheet['path'] ) ) {
			return null;
		}
		$css = (string) file_get_contents( (string) $sheet['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$css = (string) preg_replace( '~/\*.*?\*/~s', '', $css );
		$out = array();
		if ( preg_match_all( '/([^{}]*:(?:first|last|only|nth|nth-last)-(?:child|of-type)[^{}]*)\{/', $css, $m ) ) {
			foreach ( $m[1] as $selector ) {
				if ( preg_match_all( '/\.((?:\\\\.|[A-Za-z0-9_-])+)/', (string) $selector, $c ) ) {
					foreach ( $c[1] as $class ) {
						$out[ str_replace( '\\', '', $class ) ] = true;
					}
				}
			}
		}

		return $out;
	}

	/**
	 * The Home's pictures a section may show instead of its own: those of its other sections, with the shape they have.
	 *
	 * @param array<int, array<string, mixed>> $library Section_Library::for_page() of the Home.
	 * @return array<int, array{id:int, url:string, alt:string, ratio:float, from:int}>
	 */
	public static function pool( array $library ): array {
		$out = array();
		foreach ( array_values( $library ) as $i => $component ) {
			// A section with a play button or a widget shows a picture that belongs to it (a video's cover): not for another page's hero.
			if ( (array) $component['controls'] !== array() || (array) $component['widgets'] !== array() ) {
				continue;
			}
			foreach ( (array) $component['images'] as $slot ) {
				$block = Block_Tree::at( $component['block'], (array) $slot['path'] );
				$id    = (int) ( $block['attrs']['id'] ?? 0 );
				if ( $id < 1 || isset( $out[ $id ] ) || self::decorative( $block ) ) {
					continue;
				}
				$meta = wp_get_attachment_metadata( $id );
				$mime = (string) get_post_mime_type( $id );
				$url  = (string) wp_get_attachment_url( $id );
				if ( ! is_array( $meta ) || empty( $meta['width'] ) || empty( $meta['height'] ) || $url === '' || ! str_starts_with( $mime, 'image/' ) || $mime === 'image/svg+xml' ) {
					continue;
				}
				// A logo, a badge, an icon: small pictures are the Home's marks, not its photographs.
				if ( (int) $meta['width'] < 400 || (int) $meta['height'] < 240 ) {
					continue;
				}
				$out[ $id ] = array(
					'id'    => $id,
					'url'   => $url,
					'alt'   => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
					'ratio' => (float) $meta['width'] / (float) $meta['height'],
					'from'  => $i,
				);
			}
		}

		return array_values( $out );
	}

	/**
	 * A section, varied.
	 *
	 * @param array<string, mixed> $component A Section_Library::analyze() of the Home's section.
	 * @param array{seed:string, positional:array<string, true>|null, pool:array<int, array<string, mixed>>, used:array<int, int>, index:int, role:string} $ctx
	 *        `used` is the pictures already put on this page (by reference of the caller: returned in `images`).
	 * @return array{block:array<string, mixed>, ops:array<int, string>, images:array<int, int>}
	 */
	public static function apply( array $component, array $ctx ): array {
		$block  = (array) $component['block'];
		$ops    = array();
		$images = array();
		$h      = static fn( string $k ): int => (int) sprintf( '%u', crc32( $ctx['seed'] . '|' . $k ) );
		// Each kind of change is tried in turn; whether it is made is the seed's (CHANCE times in five).
		foreach ( array( 'image', 'flip', 'drop', 'faq', 'rotate' ) as $kind ) {
			if ( $kind !== 'drop' && $h( 'try:' . $kind ) % 5 >= self::CHANCE ) {
				continue;
			}
			try {
				$now = Section_Library::analyze( $block, (int) $ctx['index'] );
				$one = self::try_one( $kind, $now, $block, $ctx, $h, $images );
			} catch ( \Throwable $e ) {
				$one = null;
			}
			if ( $one === null ) {
				continue;
			}
			// Whatever was done, the section must still be blocks that come back the same.
			if ( is_wp_error( Block_Tree::serialize_checked( array( $one['block'] ) ) ) ) {
				continue;
			}
			$block = $one['block'];
			$ops[] = $one['op'];
			if ( isset( $one['image'] ) ) {
				$images[] = (int) $one['image'];
			}
		}

		return array( 'block' => $block, 'ops' => $ops, 'images' => $images );
	}

	/**
	 * One kind of change, or null when the section has nothing it can be made to.
	 *
	 * @param array<string, mixed> $now   The section as it is now, analysed.
	 * @param array<string, mixed> $block
	 * @param array<string, mixed> $ctx
	 * @param callable             $h
	 * @param array<int, int>      $images The pictures this section has taken already.
	 * @return array{block:array<string, mixed>, op:string, image?:int}|null
	 */
	private static function try_one( string $kind, array $now, array $block, array $ctx, callable $h, array $images ): ?array {
		switch ( $kind ) {
			case 'image':
				return self::swap_image( $now, $block, $ctx, $h, $images );
			case 'flip':
				return self::flip( $now, $block, $ctx, $h );
			case 'drop':
				return self::drop_optional( $now, $block, $ctx, $h );
			case 'faq':
				return self::trim_faq( $now, $block, $h );
			case 'rotate':
				return self::rotate( $now, $block, $ctx, $h );
		}

		return null;
	}

	/* ------------------------------------------------------------------------------------------------ the changes */

	/**
	 * Show another of the Home's pictures: one of its other sections', of nearly the shape of this one's.
	 *
	 * @return array{block:array<string, mixed>, op:string, image:int}|null
	 */
	private static function swap_image( array $now, array $block, array $ctx, callable $h, array $images ): ?array {
		if ( $now['images'] === array() || (array) $now['controls'] !== array() || (array) $now['widgets'] !== array() || (array) $ctx['pool'] === array() || ! in_array( (string) $ctx['role'], array( 'hero', 'two-col', 'text', 'content', 'cta' ), true ) ) {
			return null;
		}
		$slot = (array) $now['images'][0];
		$path = (array) $slot['path'];
		$img  = Block_Tree::at( $block, $path );
		$id   = (int) ( $img['attrs']['id'] ?? 0 );
		if ( $id < 1 || self::decorative( $img ) || self::in_repeat( $now, $path ) ) {
			return null;
		}
		$meta = wp_get_attachment_metadata( $id );
		if ( ! is_array( $meta ) || empty( $meta['width'] ) || empty( $meta['height'] ) ) {
			return null;
		}
		$ratio = (float) $meta['width'] / (float) $meta['height'];
		// A picture that covers its box (object-fit) takes any shape; one that does not must be nearly the same.
		$tolerance = str_contains( Block_Tree::css_of( $img ), 'object-fit:cover' ) ? 0.35 : 0.08;
		$fits      = array_values(
			array_filter(
				(array) $ctx['pool'],
				static fn( $p ) => (int) $p['id'] !== $id && (int) $p['from'] !== (int) $ctx['index'] && ! in_array( (int) $p['id'], array_merge( (array) $ctx['used'], $images ), true ) && abs( (float) $p['ratio'] / $ratio - 1 ) <= $tolerance
			)
		);
		if ( $fits === array() ) {
			return null;
		}
		$pick = $fits[ $h( 'image' ) % count( $fits ) ];
		$tgt  = &Block_Tree::at( $block, $path );
		Block_Tree::set_image( $tgt, (string) $pick['url'], (string) $pick['alt'], (int) $pick['id'] );
		unset( $tgt );

		return array( 'block' => $block, 'op' => 'image:' . $pick['id'], 'image' => (int) $pick['id'] );
	}

	/**
	 * A row of two — words on one side, a picture on the other — with its two sides changed.
	 *
	 * @return array{block:array<string, mixed>, op:string}|null
	 */
	private static function flip( array $now, array $block, array $ctx, callable $h ): ?array {
		if ( $ctx['positional'] === null || ! in_array( (string) $ctx['role'], array( 'two-col', 'text', 'content', 'cta' ), true ) ) {
			return null;
		}
		$found = self::find_pair( $block, array(), (array) $ctx['positional'] );
		if ( $found === null ) {
			return null;
		}
		$parent = &Block_Tree::at( $block, $found );
		$parent['innerBlocks'] = array_reverse( (array) $parent['innerBlocks'] );
		unset( $parent );

		return array( 'block' => $block, 'op' => 'flip' );
	}

	/**
	 * Take out one thing a section can do without: the ticks under its heading, or its second button.
	 *
	 * @return array{block:array<string, mixed>, op:string}|null
	 */
	private static function drop_optional( array $now, array $block, array $ctx, callable $h ): ?array {
		if ( ! in_array( (string) $ctx['role'], array( 'hero', 'cta', 'two-col', 'text', 'content', 'trust' ), true ) ) {
			return null;
		}
		$kinds = array_keys( self::optional( $now, $block ) );
		// A section keeps its heading and some words.
		$texts = count( array_filter( (array) $now['slots'], static fn( $s ) => $s['type'] === 'text' && ! $s['decor'] ) );
		if ( $kinds === array() || $texts < 1 ) {
			return null;
		}
		// Each thing it can do without is out or in as the seed says: two of them are four ways.
		$mask = $h( 'drop' ) % ( 1 << count( $kinds ) );
		if ( $mask === 0 ) {
			return null;
		}
		$gone = array();
		foreach ( $kinds as $bit => $kind ) {
			if ( ( $mask >> $bit ) & 1 ) {
				// The paths shift as things go: find this one in the section as it is now.
				$options = self::optional( Section_Library::analyze( $block, (int) $ctx['index'] ), $block );
				if ( isset( $options[ $kind ] ) ) {
					self::remove( $block, $options[ $kind ] );
					$gone[] = $kind;
				}
			}
		}

		return $gone === array() ? null : array( 'block' => $block, 'op' => 'drop:' . implode( '+', $gone ) );
	}

	/**
	 * What a section can do without, by kind: the path of the ticks under its heading (a list), and of the last of buttons side by
	 * side (the first, the call to action, stays).
	 *
	 * @param array<string, mixed> $now
	 * @param array<string, mixed> $block
	 * @return array<string, array<int, int>>
	 */
	private static function optional( array $now, array $block ): array {
		$out = array();
		foreach ( (array) $now['slots'] as $s ) {
			if ( $s['decor'] || (array) $s['flags'] !== array() || $s['type'] !== 'list' || isset( $out['list'] ) ) {
				continue;
			}
			$list = Block_Tree::at( $block, (array) $s['path'] );
			if ( count( (array) $list['innerBlocks'] ) >= 2 ) {
				$out['list'] = (array) $s['path'];
			}
		}
		foreach ( (array) $now['repeats'] as $r ) {
			$items = array_values( (array) $r['items'] );
			if ( ( $r['kind'] ?? '' ) === 'button' && count( $items ) >= 2 && ! isset( $out['button'] ) ) {
				$out['button'] = array_merge( (array) $r['path'], array( (int) end( $items ) ) );
			}
		}

		return $out;
	}

	/**
	 * Fewer questions: a list of six keeps four or five.
	 *
	 * @return array{block:array<string, mixed>, op:string}|null
	 */
	private static function trim_faq( array $now, array $block, callable $h ): ?array {
		foreach ( (array) $now['repeats'] as $r ) {
			if ( ( $r['kind'] ?? '' ) !== 'faq' || count( (array) $r['items'] ) < self::FAQ_MIN + 1 ) {
				continue;
			}
			$items  = array_values( (array) $r['items'] );
			$spare  = count( $items ) - self::FAQ_MIN;
			$cut    = 1 + ( $h( 'faq:n' ) % min( 2, $spare ) );
			$parent = &Block_Tree::at( $block, (array) $r['path'] );
			// Never the first (the question people ask first), and from the later ones, the seed's choice, the last of the indexes first.
			$pool = array_slice( $items, 1 );
			$gone = array();
			for ( $k = 0; $k < $cut; $k++ ) {
				$at     = $h( 'faq:' . $k ) % count( $pool );
				$gone[] = $pool[ $at ];
				array_splice( $pool, $at, 1 );
			}
			$drop = $gone;
			rsort( $drop );
			foreach ( $drop as $i ) {
				Block_Tree::drop( $parent, (int) $i );
			}
			unset( $parent );

			sort( $gone );

			return array( 'block' => $block, 'op' => 'faq:-' . implode( '-', array_map( static fn( $i ) => (string) $i, $gone ) ) );
		}

		return null;
	}

	/**
	 * The same cards in another order: the first goes to the back, some turns over.
	 *
	 * @return array{block:array<string, mixed>, op:string}|null
	 */
	private static function rotate( array $now, array $block, array $ctx, callable $h ): ?array {
		if ( $ctx['positional'] === null ) {
			return null;
		}
		$done = array();
		foreach ( (array) $now['repeats'] as $n => $r ) {
			$items = array_values( (array) $r['items'] );
			$kind  = (string) ( $r['kind'] ?? '' );
			if ( ! in_array( $kind, self::REORDER, true ) || count( $items ) < 3 ) {
				continue;
			}
			$parent = Block_Tree::at( $block, (array) $r['path'] );
			$run    = array();
			$class  = null;
			$ok     = true;
			foreach ( $items as $i ) {
				$item = (array) $parent['innerBlocks'][ $i ];
				// Items are one kind when their classes are (cards); logos are each their own size and stay interchangeable.
				$c     = in_array( $kind, array( 'logo', 'gallery' ), true ) ? '' : self::kind_of_class( $item );
				$class = $class ?? $c;
				if ( $c !== $class || isset( $item['attrs']['dxaiData']['data-mob'] ) || isset( $item['attrs']['dxaiData']['data-desk'] ) || self::positional( $item, (array) $ctx['positional'] ) ) {
					$ok = false;
					break;
				}
				$run[] = $item;
			}
			if ( ! $ok ) {
				continue;
			}
			$order = self::shuffled( count( $run ), $h, 'order:' . $n );
			$p     = &Block_Tree::at( $block, (array) $r['path'] );
			foreach ( $items as $k => $i ) {
				$p['innerBlocks'][ $i ] = $run[ $order[ $k ] ];
			}
			unset( $p );
			$done[] = 'order:' . $kind . ':' . implode( '-', $order );
		}

		return $done === array() ? null : array( 'block' => $block, 'op' => implode( '+', $done ) );
	}

	/**
	 * An order of $n things that is not the order they are in: a seeded shuffle (three things have five, four have twenty-three).
	 *
	 * @return array<int, int>
	 */
	private static function shuffled( int $n, callable $h, string $key ): array {
		for ( $salt = 0; $salt < 8; $salt++ ) {
			$order = range( 0, $n - 1 );
			for ( $i = $n - 1; $i > 0; $i-- ) {
				$j = $h( $key . ':' . $salt . ':' . $i ) % ( $i + 1 );
				[ $order[ $i ], $order[ $j ] ] = array( $order[ $j ], $order[ $i ] );
			}
			if ( $order !== range( 0, $n - 1 ) ) {
				return $order;
			}
		}

		// The seed kept the order: the first goes to the back.
		return array_merge( range( 1, $n - 1 ), array( 0 ) );
	}

	/* ------------------------------------------------------------------------------------------------ helpers */

	/**
	 * What a block is called, without the importer's per-block helper classes (`dxai-sh-26`, `dxai-sh-27`: one each, with the same
	 * rules), so that items of one kind are told as one.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function kind_of_class( array $block ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/\bdxai-sh-\d+\b/', '', (string) ( $block['attrs']['className'] ?? '' ) ) ) );
	}

	/** Whether any class of a block (or its blocks) is one the design styles by place. @param array<string, mixed> $block @param array<string, true> $positional */
	private static function positional( array $block, array $positional ): bool {
		foreach ( (array) preg_split( '/\s+/', trim( (string) ( $block['attrs']['className'] ?? '' ) ) ) as $c ) {
			if ( $c !== '' && isset( $positional[ $c ] ) ) {
				return true;
			}
		}
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $child ) {
			if ( self::positional( (array) $child, $positional ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The path of the container whose two children are a side of words and a side with a picture, laid out as a row.
	 *
	 * @param array<string, mixed>  $block
	 * @param array<int, int>       $path
	 * @param array<string, true>   $positional
	 * @return array<int, int>|null
	 */
	private static function find_pair( array $block, array $path, array $positional ): ?array {
		$kids = array_values( (array) ( $block['innerBlocks'] ?? array() ) );
		if ( count( $kids ) === 2 && in_array( (string) ( $block['blockName'] ?? '' ), array( 'core/group', 'core/columns', 'dxai-ui/box' ), true ) ) {
			$class = (string) ( $block['attrs']['className'] ?? '' );
			$css   = Block_Tree::css_of( $block );
			$row   = (string) $block['blockName'] === 'core/columns' || preg_match( '/(^|\s)(d-flex|flex|d-grid|grid|grid-cols-\d)(\s|$)/', $class ) === 1 || preg_match( '/display:(flex|grid)/', $css ) === 1;
			$col   = str_contains( $css, 'flex-direction:column' ) || preg_match( '/(^|\s)(flex-col|flex-column)(\s|$)/', $class ) === 1;
			$has   = static fn( array $b ): array => array( self::has_image( $b ), self::has_words( $b ) );
			[ $a_img, $a_words ] = $has( (array) $kids[0] );
			[ $b_img, $b_words ] = $has( (array) $kids[1] );
			if ( $row && ! $col && ( ( $a_img && ! $a_words && $b_words ) || ( $b_img && ! $b_words && $a_words ) ) && ! self::positional( (array) $kids[0], $positional ) && ! self::positional( (array) $kids[1], $positional ) ) {
				return $path;
			}
		}
		foreach ( $kids as $i => $child ) {
			$found = self::find_pair( (array) $child, array_merge( $path, array( (int) $i ) ), $positional );
			if ( $found !== null ) {
				return $found;
			}
		}

		return null;
	}

	/** @param array<string, mixed> $b */
	private static function has_image( array $b ): bool {
		if ( in_array( (string) ( $b['blockName'] ?? '' ), array( 'core/image', 'dxai-ui/image' ), true ) ) {
			return true;
		}
		foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $c ) {
			if ( self::has_image( (array) $c ) ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string, mixed> $b */
	private static function has_words( array $b ): bool {
		if ( in_array( (string) ( $b['blockName'] ?? '' ), array( 'core/heading', 'core/paragraph' ), true ) ) {
			return true;
		}
		foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $c ) {
			if ( self::has_words( (array) $c ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Take the block at a path out, and any container that is left with nothing in it.
	 *
	 * @param array<string, mixed> $block
	 * @param array<int, int>      $path
	 */
	private static function remove( array &$block, array $path ): void {
		Block_Tree::remove( $block, $path );
	}

	/** Whether a path is inside one of the repeated groups of the section. @param array<string, mixed> $now @param array<int, int> $path */
	private static function in_repeat( array $now, array $path ): bool {
		foreach ( (array) $now['repeats'] as $r ) {
			$base = (array) $r['path'];
			if ( count( $path ) > count( $base ) && array_slice( $path, 0, count( $base ) ) === $base && in_array( $path[ count( $base ) ], (array) $r['items'], true ) ) {
				return true;
			}
		}

		return false;
	}

	/** A picture that is there for its looks only (it says nothing: aria-hidden, an empty alt on a background). @param array<string, mixed> $b */
	private static function decorative( array $b ): bool {
		return str_contains( (string) ( $b['innerHTML'] ?? '' ), 'aria-hidden="true"' );
	}
}

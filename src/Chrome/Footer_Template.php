<?php
/**
 * A converted design's footer, split into its frame and its widget areas.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Chrome;

use DXAI_UI\Compiler\Utility_Classes;

/**
 * The design supplies the footer's structure; Appearance > Widgets holds its
 * content. This splits the footer's block markup along that line, the way a
 * DevriX theme's footer.php does it by hand: the element whose children are
 * the footer's columns (brand/about, link lists, contact…) is found, and each
 * column's CHILDREN become the widgets of `footer-column-N` while the column
 * element itself — its classes, its dxaiCss, its place in the grid — stays in
 * the frame. The bottom bar (copyright, legal and social links) is split the
 * same way into `footer-copyright`. Everything else — the `<footer>`, its
 * containers, background decorations — is the frame, and each place a column's
 * children were is left holding one `dxai-ui/footer-slot` block naming its
 * area.
 *
 * The slot is a block rather than a marker string so the column keeps the
 * shape core renders it from. A group's layout support finds the element to
 * add `is-layout-flow` to by looking at the markup before its first inner
 * block — only when the block HAS inner blocks — so a column emptied down to
 * a comment would be decorated differently from the original. With one inner
 * block standing where the others were, the frame renders the column exactly
 * as the design's markup did, and Site_Footer_Block puts the area's widgets
 * where the slot renders. The slot type is never registered: it only exists
 * in the stored frame, which no editor opens.
 *
 * One footer per site, like the brand (Design_Theme_Json): the last import
 * that installs its footer wins (the person chose to, or no other design's
 * was the site's — Chrome_Choice). The design it replaces does not lose its
 * footer: its pages get it back as a template part (assemble(), and
 * Structure_Repository::install_site_footer()), as every design's footer
 * was before footers moved to Widgets.
 */
final class Footer_Template {

	/** The stored footer: its frame, its areas and where it came from. */
	public const OPTION = 'dxai_ui_site_footer';

	/** The block that stands in the frame where an area's widgets render. */
	public const SLOT = 'dxai-ui/footer-slot';

	/** The DevriX theme's ids, so the areas coincide with such a theme's own. */
	public const COLUMN_PREFIX = 'footer-column-';
	public const COPYRIGHT     = 'footer-copyright';

	private const VERSION = 1;

	/**
	 * Blocks whose inner blocks can each stand alone as a widget. A list is
	 * not one (a list item cannot leave its list), and neither is a
	 * core/columns row (a column cannot leave its row) — core/column itself is.
	 */
	private const CONTAINERS = array( 'core/group', 'core/column', 'dxai-ui/box' );

	/**
	 * What a copyright line looks like. The bar that holds it is the footer's
	 * bottom bar.
	 */
	private const COPYRIGHT_TEXT = '/©|&copy;|&#0*169;|&#x0*a9;|\bcopyright\b|all rights reserved/i';

	/**
	 * Properties the Widgets screen copies from the frame onto an area, so
	 * its blocks are shown on the footer's own background, in its own type.
	 * Inherited text properties and the painted background — not layout.
	 */
	private const PAINT = array(
		'color',
		'background',
		'background-color',
		'background-image',
		'background-position',
		'background-size',
		'background-repeat',
		'font',
		'font-family',
		'font-size',
		'font-weight',
		'font-style',
		'line-height',
		'letter-spacing',
		'word-spacing',
		'text-transform',
		'text-align',
		'text-shadow',
	);

	/**
	 * Split footer block markup into its frame and its areas' widgets.
	 *
	 * @return array{template:string, columns:int, slots:array<string, array{kind:string, number:int, wrapped:bool, paint:string, blocks:array<int, string>}>}
	 */
	public static function build( string $markup ): array {
		$empty = array(
			'template' => '',
			'columns'  => 0,
			'slots'    => array(),
		);
		$roots = array_values(
			array_filter(
				parse_blocks( $markup ),
				static function ( $block ): bool {
					// Whitespace between top-level blocks parses as a
					// nameless block; it renders nothing.
					return is_array( $block ) && ( ! empty( $block['blockName'] ) || trim( (string) ( $block['innerHTML'] ?? '' ) ) !== '' );
				}
			)
		);
		if ( $roots === array() ) {
			return $empty;
		}

		// The spine: the first block, from the top, with more than one child.
		// Single-child wrappers above it (the <footer>, a container) are frame.
		$spine    = array();
		$children = $roots;
		while ( count( $children ) === 1 && self::is_container( $children[0] ) ) {
			$spine[]  = 0;
			$children = $children[0]['innerBlocks'];
		}
		$spine_block = $spine === array() ? null : self::node( $roots, $spine );

		/*
		 * A spine that lays its children out side by side is the grid
		 * itself, and a copyright line in it belongs to its column — unless
		 * the row is only the line and one other block (copyright | socials),
		 * which is a bottom bar standing alone.
		 */
		$bar  = null;
		$grid = null;
		if ( $spine_block !== null && count( $children ) >= 2 && self::lays_out_columns( $spine_block ) && ! self::holds_copyright_only_in_one( $spine_block ) ) {
			$grid = $spine;
		} else {
			// The bottom bar: the spine's child holding the copyright line,
			// when that is not its first child.
			foreach ( $children as $i => $child ) {
				if ( $i > 0 && self::holds_copyright( $child ) ) {
					$bar = array_merge( $spine, array( $i ) );
					break;
				}
			}
			// The columns: the outermost block before the bar that lays its
			// children out side by side; among the shallowest, the one with
			// the most children.
			$grid = self::find_grid( $roots, $spine, $children, $bar === null ? count( $children ) : (int) end( $bar ) );
		}

		$slots = array();
		if ( $grid !== null ) {
			$columns = self::node( $roots, $grid )['innerBlocks'];
			foreach ( array_keys( $columns ) as $i ) {
				$slots[] = self::slot_for( $roots, array_merge( $grid, array( $i ) ), self::COLUMN_PREFIX . ( $i + 1 ), 'column', $i + 1 );
			}
		} else {
			// No grid: what stands before the bar is one column.
			$before = $bar === null ? count( $children ) : (int) end( $bar );
			if ( $before === 1 && self::is_container( $children[0] ) ) {
				$slots[] = self::slot_for( $roots, array_merge( $spine, array( 0 ) ), self::COLUMN_PREFIX . '1', 'column', 1 );
			} elseif ( $before > 0 ) {
				$slots[] = self::run_slot( $roots, $spine, 0, $before - 1, self::COLUMN_PREFIX . '1', 'column', 1 );
			}
		}
		if ( $bar !== null ) {
			$bar_block = self::node( $roots, $bar );
			$slots[]   = self::is_container( $bar_block )
				? self::slot_for( $roots, $bar, self::COPYRIGHT, 'copyright', 0 )
				// A bare copyright line: it and whatever follows it on the spine.
				: self::run_slot( $roots, $spine, (int) end( $bar ), count( $children ) - 1, self::COPYRIGHT, 'copyright', 0 );
		}
		$slots = array_values( array_filter( $slots ) );
		if ( $slots === array() ) {
			return $empty;
		}

		// Replace from the last slot to the first, so a run replaced near the
		// end never shifts a path recorded before it.
		usort(
			$slots,
			static function ( array $a, array $b ): int {
				return self::compare_paths( $b['parent'], $a['parent'] ) ?: ( $b['from'] <=> $a['from'] );
			}
		);
		$out = array();
		foreach ( $slots as $slot ) {
			$roots = self::replace( $roots, $slot );
			$out[ $slot['area'] ] = array(
				'kind'    => $slot['kind'],
				'number'  => $slot['number'],
				'wrapped' => $slot['wrapped'],
				'paint'   => $slot['paint'],
				'blocks'  => $slot['blocks'],
			);
		}
		// Areas in reading order: the columns, then the bar.
		uasort(
			$out,
			static function ( array $a, array $b ): int {
				$ka = $a['kind'] === 'copyright' ? PHP_INT_MAX : $a['number'];
				$kb = $b['kind'] === 'copyright' ? PHP_INT_MAX : $b['number'];

				return $ka <=> $kb;
			}
		);

		return array(
			'template' => serialize_blocks( $roots ),
			'columns'  => count(
				array_filter(
					$out,
					static function ( array $slot ): bool {
						return $slot['kind'] === 'column';
					}
				)
			),
			'slots'    => $out,
		);
	}

	/**
	 * Keep a built footer as the site's footer. The widgets themselves are
	 * not stored here — they live in the widget areas (Footer_Widgets).
	 *
	 * @param array<string, mixed> $built   build()'s result.
	 * @param array<string, mixed> $context page_id, scope, source, title.
	 * @return array<string, mixed> The stored record.
	 */
	public static function store( array $built, array $context = array() ): array {
		$slots = array();
		foreach ( (array) ( $built['slots'] ?? array() ) as $area => $slot ) {
			$slots[ (string) $area ] = array(
				'kind'    => (string) ( $slot['kind'] ?? 'column' ),
				'number'  => (int) ( $slot['number'] ?? 0 ),
				'wrapped' => ! empty( $slot['wrapped'] ),
				'paint'   => (string) ( $slot['paint'] ?? '' ),
			);
		}
		$page_id = (int) ( $context['page_id'] ?? 0 );
		$scope   = (int) ( $context['scope'] ?? 0 );
		if ( $scope < 1 && $page_id > 0 ) {
			$scope = (int) get_post_meta( $page_id, \DXAI_UI\Structures\Page_Scope::META, true );
		}
		$record = array(
			'version'  => self::VERSION,
			'template' => (string) ( $built['template'] ?? '' ),
			'columns'  => (int) ( $built['columns'] ?? 0 ),
			'slots'    => $slots,
			'page_id'  => $page_id,
			'scope'    => $scope > 0 ? $scope : $page_id,
			'source'   => (string) ( $context['source'] ?? '' ),
			'title'    => (string) ( $context['title'] ?? '' ),
			/*
			 * Whose footer this is (the design's page key, archive#slug) and
			 * the key its template part was saved under: when another design
			 * takes the widget areas, this design's pages get their footer
			 * back as that part (Structure_Repository::hand_back_footer()).
			 */
			'owner'    => (string) ( $context['owner'] ?? '' ),
			'part_key' => (string) ( $context['part_key'] ?? '' ),
			'updated'  => time(),
		);
		// Autoloaded: widgets_init reads it on every request to register the
		// areas, and it is a few kilobytes of block markup.
		update_option( self::OPTION, $record, true );

		return $record;
	}

	/**
	 * The stored footer, or an empty array when none was imported.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$record = get_option( self::OPTION );
		if ( ! is_array( $record ) || ! is_string( $record['template'] ?? null ) || $record['template'] === '' || ! is_array( $record['slots'] ?? null ) ) {
			return array();
		}

		return $record;
	}

	/**
	 * The stored footer as one block markup again: the frame, with each slot
	 * replaced by the block widgets its area holds now — a person's edits in
	 * Appearance > Widgets included — in their order there.
	 *
	 * For a design that stops being the site's footer: its pages keep this
	 * footer as a template part (Structure_Repository::hand_back_footer()),
	 * so they go on rendering it after another design takes the areas. The
	 * slot stands exactly where build() lifted the area's blocks out, and
	 * each widget carries the whitespace that followed it in the design, so
	 * the result renders as the footer did. A legacy widget has no block
	 * markup and is not part of it (it stays in Appearance > Widgets). Empty
	 * when no footer is stored.
	 */
	public static function assemble(): string {
		$record = self::get();
		if ( $record === array() ) {
			return '';
		}

		return (string) preg_replace_callback(
			'/<!--\s*wp:' . preg_quote( self::SLOT, '/' ) . '\s+(\{.*?\})\s*\/-->/',
			static function ( array $m ): string {
				$attrs = json_decode( $m[1], true );
				$area  = is_array( $attrs ) ? (string) ( $attrs['area'] ?? '' ) : '';

				return $area === '' ? '' : implode( '', Footer_Widgets::block_contents( $area ) );
			},
			(string) $record['template']
		);
	}

	/**
	 * The stored footer's area ids, columns first.
	 *
	 * @return array<int, string>
	 */
	public static function areas(): array {
		$record = self::get();

		return $record === array() ? array() : array_map( 'strval', array_keys( $record['slots'] ) );
	}

	/**
	 * The slot record for one column (or the bar) at $path.
	 *
	 * A container's children become the widgets and the container stays in
	 * the frame, after stepping down through wrappers that hold nothing but
	 * one more container. Anything else — a list standing as a column, a
	 * lone image — is a widget itself, and the slot takes its place.
	 *
	 * @param array<int, array<string, mixed>> $roots
	 * @param array<int, int>                  $path
	 * @return array<string, mixed>|null
	 */
	private static function slot_for( array $roots, array $path, string $area, string $kind, int $number ): ?array {
		$block = self::node( $roots, $path );
		if ( self::is_container( $block ) ) {
			while ( count( $block['innerBlocks'] ) === 1 && self::is_container( $block['innerBlocks'][0] ) ) {
				$path[] = 0;
				$block  = $block['innerBlocks'][0];
			}
			$slot = self::run_slot( $roots, $path, 0, count( $block['innerBlocks'] ) - 1, $area, $kind, $number );
			if ( $slot !== null ) {
				$slot['wrapped'] = true;

				return $slot;
			}
		}
		$parent = array_slice( $path, 0, -1 );
		$index  = (int) end( $path );

		return self::run_slot( $roots, $parent, $index, $index, $area, $kind, $number );
	}

	/**
	 * The slot record for children $from..$to of the block at $parent (or of
	 * the top level, for an empty path).
	 *
	 * Null when the run cannot be lifted out as widgets: text standing
	 * between two of its blocks has no widget to go into. The caller then
	 * lifts the whole container instead.
	 *
	 * @param array<int, array<string, mixed>> $roots
	 * @param array<int, int>                  $parent
	 * @return array<string, mixed>|null
	 */
	private static function run_slot( array $roots, array $parent, int $from, int $to, string $area, string $kind, int $number ): ?array {
		$owner    = $parent === array() ? null : self::node( $roots, $parent );
		$children = $owner === null ? $roots : $owner['innerBlocks'];
		if ( $from < 0 || $to < $from || ! isset( $children[ $to ] ) ) {
			return null;
		}
		$between = $owner === null ? array() : self::separators( $owner, $from, $to );
		if ( $between === null ) {
			return null;
		}
		$blocks = array();
		for ( $i = $from; $i <= $to; $i++ ) {
			// Whitespace the design had between two blocks rides with the
			// first, so inline siblings keep their gap.
			$blocks[] = serialize_block( $children[ $i ] ) . ( $between[ $i ] ?? '' );
		}

		return array(
			'area'    => $area,
			'kind'    => $kind,
			'number'  => $number,
			'parent'  => $parent,
			'from'    => $from,
			'to'      => $to,
			'wrapped' => false,
			'paint'   => self::paint( $roots, $parent ),
			'blocks'  => $blocks,
		);
	}

	/**
	 * What stands between the inner blocks $from..$to of $owner, keyed by
	 * the index of the block it follows; null when any of it is more than
	 * whitespace.
	 *
	 * @param array<string, mixed> $owner
	 * @return array<int, string>|null
	 */
	private static function separators( array $owner, int $from, int $to ): ?array {
		$out   = array();
		$index = -1;
		foreach ( (array) ( $owner['innerContent'] ?? array() ) as $chunk ) {
			if ( $chunk === null ) {
				++$index;
				continue;
			}
			if ( $index >= $from && $index < $to ) {
				if ( trim( (string) $chunk ) !== '' ) {
					return null;
				}
				$out[ $index ] = ( $out[ $index ] ?? '' ) . (string) $chunk;
			}
		}

		return $out;
	}

	/**
	 * The frame with one slot put in place of the blocks it lifted out.
	 *
	 * @param array<int, array<string, mixed>> $roots
	 * @param array<string, mixed>             $slot
	 * @return array<int, array<string, mixed>>
	 */
	private static function replace( array $roots, array $slot ): array {
		$marker = array(
			'blockName'    => self::SLOT,
			'attrs'        => array( 'area' => $slot['area'] ),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
		if ( $slot['parent'] === array() ) {
			array_splice( $roots, $slot['from'], $slot['to'] - $slot['from'] + 1, array( $marker ) );

			return $roots;
		}
		$owner = self::node( $roots, $slot['parent'] );
		array_splice( $owner['innerBlocks'], $slot['from'], $slot['to'] - $slot['from'] + 1, array( $marker ) );
		// innerContent: one null for the slot where the run's nulls were, the
		// chunks before and after the run kept as they are.
		$content = array();
		$index   = -1;
		foreach ( (array) ( $owner['innerContent'] ?? array() ) as $chunk ) {
			if ( $chunk === null ) {
				++$index;
				if ( $index < $slot['from'] || $index > $slot['to'] ) {
					$content[] = null;
				} elseif ( $index === $slot['from'] ) {
					$content[] = null;
				}
				continue;
			}
			if ( $index >= $slot['from'] && $index < $slot['to'] ) {
				continue;
			}
			$content[] = $chunk;
		}
		$owner['innerContent'] = $content;
		$owner['innerHTML']    = implode( '', array_filter( $content, 'is_string' ) );

		return self::set_node( $roots, $slot['parent'], $owner );
	}

	/**
	 * The block at a path of inner-block indexes.
	 *
	 * @param array<int, array<string, mixed>> $roots
	 * @param array<int, int>                  $path
	 * @return array<string, mixed>
	 */
	private static function node( array $roots, array $path ): array {
		$block = array( 'innerBlocks' => $roots );
		foreach ( $path as $i ) {
			$block = $block['innerBlocks'][ $i ];
		}

		return $block;
	}

	/**
	 * @param array<int, array<string, mixed>> $roots
	 * @param array<int, int>                  $path
	 * @param array<string, mixed>             $block
	 * @return array<int, array<string, mixed>>
	 */
	private static function set_node( array $roots, array $path, array $block ): array {
		$i = array_shift( $path );
		if ( $path === array() ) {
			$roots[ $i ] = $block;
		} else {
			$roots[ $i ]['innerBlocks'] = self::set_node( $roots[ $i ]['innerBlocks'], $path, $block );
		}

		return $roots;
	}

	private static function compare_paths( array $a, array $b ): int {
		$n = min( count( $a ), count( $b ) );
		for ( $i = 0; $i < $n; $i++ ) {
			if ( $a[ $i ] !== $b[ $i ] ) {
				return $a[ $i ] <=> $b[ $i ];
			}
		}

		return count( $a ) <=> count( $b );
	}

	/**
	 * The shallowest block before the bar that lays out two or more
	 * children side by side; at that depth, the one with the most children.
	 * Nothing from the bar on is searched: a row of social icons after the
	 * copyright line is part of the bar, however many icons it has.
	 *
	 * @param array<int, array<string, mixed>> $roots
	 * @param array<int, int>                  $spine
	 * @param array<int, array<string, mixed>> $children The spine's children.
	 * @param int                              $before   Index of the bar among them (their count when none).
	 * @return array<int, int>|null
	 */
	private static function find_grid( array $roots, array $spine, array $children, int $before ): ?array {
		$level = array();
		foreach ( array_keys( $children ) as $i ) {
			if ( $i < $before ) {
				$level[] = array_merge( $spine, array( $i ) );
			}
		}
		while ( $level !== array() ) {
			$best  = null;
			$count = 1;
			$next  = array();
			foreach ( $level as $path ) {
				$block = self::node( $roots, $path );
				$inner = (array) ( $block['innerBlocks'] ?? array() );
				if ( self::lays_out_columns( $block ) && count( $inner ) > $count && ! self::holds_copyright_only_in_one( $block ) ) {
					$best  = $path;
					$count = count( $inner );
				}
				foreach ( array_keys( $inner ) as $i ) {
					$next[] = array_merge( $path, array( $i ) );
				}
			}
			if ( $best !== null ) {
				return $best;
			}
			$level = $next;
		}

		return null;
	}

	/**
	 * A row whose children include the copyright line and nothing much else
	 * is a bottom bar the spine did not separate (copyright | socials), not
	 * the footer's columns.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function holds_copyright_only_in_one( array $block ): bool {
		$inner = (array) ( $block['innerBlocks'] ?? array() );
		if ( count( $inner ) > 2 ) {
			return false;
		}
		foreach ( $inner as $child ) {
			if ( self::holds_copyright( $child ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a block lays its children out side by side: a columns row, a
	 * grid or row layout, or the same said in its design CSS or classes.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function lays_out_columns( array $block ): bool {
		$name  = (string) ( $block['blockName'] ?? '' );
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		if ( $name === 'core/columns' ) {
			return true;
		}
		$layout = is_array( $attrs['layout'] ?? null ) ? $attrs['layout'] : array();
		if ( ( $layout['type'] ?? '' ) === 'grid' ) {
			return true;
		}
		if ( ( $layout['type'] ?? '' ) === 'flex' ) {
			return ( $layout['orientation'] ?? 'horizontal' ) !== 'vertical';
		}
		/*
		 * Said in the block's CSS, or in utility classes: Tailwind's (`grid
		 * md:grid-cols-4`, `flex md:flex-row`) and the DevriX theme's
		 * (`d-grid`, `grid-4`, `lg-grid-5`, `d-flex`, `flex-column`), which
		 * Utility_Classes writes in place of the declarations they match — so
		 * `display` may be a class while the columns are still CSS.
		 */
		$css     = strtolower( ';' . (string) ( $attrs['dxaiCss'] ?? '' ) . ';' . (string) ( $attrs['dxaiStyle'] ?? '' ) );
		$class   = ' ' . strtolower( (string) ( $attrs['className'] ?? '' ) ) . ' ';
		$variant = '(?:[a-z0-9-]+:|(?:sm|md|lg|xl)-)?';
		$tracks  = '/\s' . $variant . '(?:grid-cols|grid)-(?:[2-9]|1[0-2])\s/';
		$grid    = preg_match( '/;\s*display\s*:\s*(?:inline-)?grid\b/', $css ) === 1 || preg_match( '/\s' . $variant . '(?:d-grid|grid|inline-grid)\s/', $class ) === 1;
		$flex    = preg_match( '/;\s*display\s*:\s*(?:inline-)?flex\b/', $css ) === 1 || preg_match( '/\s' . $variant . '(?:d-flex|flex|inline-flex|flex-inline)\s/', $class ) === 1;
		if ( $grid ) {
			if ( preg_match( $tracks, $class ) === 1 ) {
				return true;
			}
			if ( preg_match( '/grid-template-columns\s*:\s*([^;]+)/', $css, $m ) === 1 ) {
				// One track stacks its children.
				return preg_match( '/^\s*(?:none|auto|1fr|100%|minmax\([^()]*\))\s*$/', $m[1] ) !== 1;
			}

			return preg_match( '/grid-auto-flow\s*:\s*column/', $css ) === 1;
		}
		if ( $flex ) {
			$column = preg_match( '/flex-direction\s*:\s*column/', $css ) === 1 || preg_match( '/\s(?:flex-col|flex-column)\s/', $class ) === 1;

			return ! $column || preg_match( '/\s(?:[a-z0-9-]+:|(?:sm|md|lg|xl)-)flex-row\s/', $class ) === 1;
		}

		// The theme's `grid-N` sets display:grid itself.
		return preg_match( '/\s(?:(?:sm|md|lg|xl)-)?grid-(?:[2-9]|1[0-2])\s/', $class ) === 1;
	}

	/**
	 * @param array<string, mixed> $block
	 */
	private static function is_container( array $block ): bool {
		return in_array( (string) ( $block['blockName'] ?? '' ), self::CONTAINERS, true ) && ! empty( $block['innerBlocks'] );
	}

	/**
	 * @param array<string, mixed> $block
	 */
	private static function holds_copyright( array $block ): bool {
		if ( preg_match( self::COPYRIGHT_TEXT, (string) ( $block['innerHTML'] ?? '' ) ) === 1 ) {
			return true;
		}
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $inner ) {
			if ( is_array( $inner ) && self::holds_copyright( $inner ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The frame's paint an area's widgets sit in: the inherited type and the
	 * background of every block from the top down to the slot's parent,
	 * nearest last. Declarations, for the Widgets screen.
	 *
	 * A block's paint can be in three places: the theme utility classes
	 * Style_Hoister wrote for the longhands a utility declares exactly
	 * (`text-16`, `fw-700`, `uppercase`), what is left in its dxaiCss, and
	 * its preset colours. The utilities are read first — by construction they
	 * never set a longhand that is still in dxaiCss.
	 *
	 * @param array<int, array<string, mixed>> $roots
	 * @param array<int, int>                  $parent
	 */
	private static function paint( array $roots, array $parent ): string {
		$chain = array();
		$block = array( 'innerBlocks' => $roots );
		foreach ( $parent as $i ) {
			$block   = $block['innerBlocks'][ $i ];
			$chain[] = $block;
		}
		$props = array();
		foreach ( $chain as $block ) {
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			foreach ( self::utility_paint( (string) ( $attrs['className'] ?? '' ) ) as $prop => $value ) {
				unset( $props[ $prop ] );
				$props[ $prop ] = $value;
			}
			foreach ( self::declarations( (string) ( $attrs['dxaiCss'] ?? '' ) ) as $prop => $value ) {
				if ( in_array( $prop, self::PAINT, true ) ) {
					unset( $props[ $prop ] );
					$props[ $prop ] = $value;
				}
			}
			$preset = array(
				'color'            => $attrs['textColor'] ?? '',
				'background-color' => $attrs['backgroundColor'] ?? '',
			);
			foreach ( $preset as $prop => $slug ) {
				if ( is_string( $slug ) && $slug !== '' ) {
					unset( $props[ $prop ] );
					$props[ $prop ] = 'var(--wp--preset--color--' . sanitize_key( $slug ) . ')';
				}
			}
			if ( is_string( $attrs['gradient'] ?? null ) && $attrs['gradient'] !== '' ) {
				unset( $props['background'] );
				$props['background'] = 'var(--wp--preset--gradient--' . sanitize_key( $attrs['gradient'] ) . ')';
			}
		}
		$out = '';
		foreach ( $props as $prop => $value ) {
			$out .= $prop . ':' . $value . ';';
		}

		return $out;
	}

	/**
	 * The paint properties the theme utility classes in a class list set on
	 * the element itself, at every width: each class's own rules outside any
	 * media query (a `lg-` variant is a wide screen's, and the Widgets
	 * screen's areas are 700px panels), as the catalogue writes them
	 * (Utility_Classes, `!important` included).
	 *
	 * @return array<string, string>
	 */
	private static function utility_paint( string $class_name ): array {
		$out = array();
		if ( trim( $class_name ) === '' || ! class_exists( Utility_Classes::class ) ) {
			return $out;
		}
		foreach ( preg_split( '/\s+/', trim( $class_name ) ) ?: array() as $class ) {
			if ( ! Utility_Classes::is_utility( $class ) ) {
				continue;
			}
			/*
			 * The class's own base declarations, from Utility_Classes: the
			 * theme's rules that name the class itself outside any media query,
			 * in cascade order, and the plugin's own classes too (`fw-900`,
			 * `text-dxai-*`, `bg-dxai-*`), which a read of the theme catalogue
			 * alone missed. Lengths as px, `!important` kept.
			 */
			foreach ( self::declarations( Utility_Classes::base_declarations( $class ) ) as $prop => $value ) {
				if ( in_array( $prop, self::PAINT, true ) ) {
					unset( $out[ $prop ] );
					$out[ $prop ] = $value;
				}
			}
		}

		return $out;
	}

	/**
	 * A declaration list as property => value, split on the semicolons that
	 * end a declaration (not those inside a url() or a string).
	 *
	 * @return array<string, string>
	 */
	public static function declarations( string $css ): array {
		$out   = array();
		$depth = 0;
		$quote = '';
		$start = 0;
		$len   = strlen( $css );
		for ( $i = 0; $i <= $len; $i++ ) {
			$ch = $i < $len ? $css[ $i ] : ';';
			if ( $quote !== '' ) {
				if ( $ch === '\\' ) {
					++$i;
				} elseif ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( $ch === '"' || $ch === "'" ) {
				$quote = $ch;
			} elseif ( $ch === '(' ) {
				++$depth;
			} elseif ( $ch === ')' && $depth > 0 ) {
				--$depth;
			} elseif ( $ch === ';' && $depth === 0 ) {
				$decl  = substr( $css, $start, $i - $start );
				$start = $i + 1;
				$colon = strpos( $decl, ':' );
				if ( $colon === false ) {
					continue;
				}
				$prop  = strtolower( trim( substr( $decl, 0, $colon ) ) );
				$value = trim( substr( $decl, $colon + 1 ) );
				if ( $prop !== '' && $value !== '' ) {
					$out[ $prop ] = $value;
				}
			}
		}

		return $out;
	}
}

<?php
/**
 * The installed header, filled from the current menus.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Chrome;

/**
 * Renders the header template (Header_Template) with every slot holding the
 * CURRENT menu items: label, URL, target, rel, title attribute, the classes
 * a person gave the item, its description as the eyebrow, its children in
 * the dropdown panel.
 *
 * Works on the parsed blocks, not on rendered HTML: each item is a clone of
 * a prototype block with its HTML edited in place (Header_Html), the slot's
 * run of children is replaced by the clones, and the result goes through
 * render_block() exactly as the header part did through do_blocks(). With
 * the menus holding the design's items every edit is a no-op, the tree is
 * the template's own, and the output is the part's output.
 *
 * Behaviour survives cloning. The design's dropdowns are driven by the
 * runtime (Motion_Runtime) through `dxai-toggle-<state>--<value>` on the
 * trigger and `dxai-on-<state>--<value>` on the panel; the runtime reads the
 * states and their values out of the DOM when the page loads. An item drawn
 * at its own design position keeps the design's value; a clone gets a value
 * of its own, so a dropdown someone adds in Appearance > Menus opens and
 * closes by itself, and never together with the one it was cloned from.
 */
final class Header_Renderer {

	/** Deepest menu level rendered: bar, dropdown, flyout. */
	public const MAX_DEPTH = 3;

	/** Classes the synthesized flyout carries (assets/css/site-header-front.css). */
	public const FLYOUT       = 'dxai-hdr-flyout';
	public const FLYOUT_PANEL = 'dxai-hdr-flyout__panel';

	/** @var array<string, mixed> */
	private array $spec;

	/** @var array<string, array<int, array<string, mixed>>|null> location => item tree, null: no menu. */
	private array $menus;

	/** @var array{id?:int, url?:string, alt?:string} */
	private array $logo;

	/** @var array<string, array<string, bool>> State values in use: state => value => true. */
	private array $used = array();

	/** Whether this render drew a flyout the design has no prototype for. */
	private bool $flyout = false;

	/** Clone counter, for ids made unique. */
	private int $clones = 0;

	/**
	 * @param array<string, mixed>                                $spec  Header_Template spec.
	 * @param array<string, array<int, array<string, mixed>>|null> $menus location => nodes of
	 *                                                                    ['item' => menu item object, 'children' => nodes];
	 *                                                                    null or absent keeps the design's items.
	 * @param array{id?:int, url?:string, alt?:string}            $logo  The Site Logo, [] for the design's.
	 */
	public function __construct( array $spec, array $menus, array $logo = array() ) {
		$this->spec  = $spec;
		$this->menus = $menus;
		$this->logo  = $logo;
	}

	/**
	 * The header's HTML.
	 */
	public function render(): string {
		return self::html( $this->root() );
	}

	/**
	 * A filled tree's HTML: each top-level block through render_block(), as
	 * do_blocks() renders the header part.
	 *
	 * @param array<string, mixed> $root root()'s result.
	 */
	public static function html( array $root ): string {
		$out = '';
		foreach ( Header_Template::kids( $root ) as $block ) {
			$out .= render_block( $block );
		}

		return $out;
	}

	/**
	 * A filled tree as block markup: what Style_Rules reads for the header's
	 * rules — the clones, and the classes a person gave the items, included.
	 *
	 * @param array<string, mixed> $root root()'s result.
	 */
	public static function markup( array $root ): string {
		return serialize_blocks( Header_Template::kids( $root ) );
	}

	/**
	 * Whether the last render drew a synthesized flyout.
	 */
	public function drew_flyout(): bool {
		return $this->flyout;
	}

	/**
	 * The filled block tree, under a virtual root.
	 *
	 * @return array<string, mixed>
	 */
	public function root(): array {
		$markup = (string) $this->spec['markup'];
		$root   = Header_Template::root_of( $markup );
		$this->seed( $markup );
		$this->flyout = false;

		$plans = array();
		foreach ( (array) ( $this->spec['slots'] ?? array() ) as $slot ) {
			$location = (string) ( $slot['location'] ?? '' );
			if ( ! isset( $this->menus[ $location ] ) || ! is_array( $this->menus[ $location ] ) ) {
				continue;
			}
			$this->plan_slot( $root, $slot, $this->menus[ $location ], $plans, array( 'depth' => 1 ) );
		}
		$this->plan_logos( $plans );

		return $this->rebuild( $root, array(), $plans ) ?? $root;
	}

	/**
	 * Plan one slot: which prototype each item is drawn with, and the runs of
	 * children they replace.
	 *
	 * @param array<string, mixed>                $base  Block the slot's paths start from.
	 * @param array<string, mixed>                $slot
	 * @param array<int, array<string, mixed>>    $nodes Menu items at this level.
	 * @param array<string, array<string, mixed>> $plans Receives the plan, keyed by path.
	 * @param array<string, mixed>                $ctx   depth; the dropdown this panel belongs to.
	 */
	private function plan_slot( array $base, array $slot, array $nodes, array &$plans, array $ctx ): void {
		$positions = array_values( (array) ( $slot['positions'] ?? array() ) );
		$groups    = array_values( (array) ( $slot['groups'] ?? array() ) );
		if ( $positions === array() || $groups === array() ) {
			return;
		}

		/*
		 * A condensed or diverged mirror (Header_Template::rank()) is its
		 * design's own drawing of the menu — one mobile trust line for four
		 * items, a drawer listing other pages than the bar — and is left
		 * exactly as drawn while the menu is exactly the design's.
		 */
		if ( ( ! empty( $slot['condensed'] ) || ! empty( $slot['diverged'] ) ) && isset( $slot['design_sig'] ) && Header_Template::node_sig( $nodes ) === (string) $slot['design_sig'] ) {
			return;
		}

		/*
		 * A flat mirror of a navigation that has dropdowns — the mobile drawer
		 * — shows the design's own dropdowns as the one link the design drew
		 * for each. A dropdown the design did not have would lose its children
		 * there, out of reach on a phone, so they follow it in the list.
		 */
		if ( empty( $slot['primary'] ) && Header_Template::NAV === ( $slot['location'] ?? '' ) && 1 === (int) $ctx['depth'] && ! self::has_dropdowns( $positions ) ) {
			$drops = $this->design_dropdowns();
			if ( $drops !== array() ) {
				$nodes = self::flatten_new( $nodes, $drops );
			}
		}

		$assign = ! empty( $slot['condensed'] )
			? $this->assign_condensed( $positions, $nodes )
			: $this->assign( $positions, $nodes, $ctx );

		// Each item goes into its prototype's group; an item drawn with the
		// common prototype follows the item before it.
		$group = 0;
		foreach ( $assign as $a => $row ) {
			if ( $row['positional'] ) {
				$group = (int) $positions[ $row['pos'] ]['group'];
			}
			$assign[ $a ]['group'] = $group;
		}

		foreach ( $groups as $g => $spec ) {
			$container = Header_Template::node_in( $base, (array) $spec['path'] );
			$kids      = Header_Template::kids( $container );
			$span      = array_merge( (array) $spec['items'], (array) ( $spec['seps'] ?? array() ) );
			foreach ( $positions as $p ) {
				if ( (int) $p['group'] === $g && is_array( $p['eyebrow'] ?? null ) ) {
					$span[] = (int) $p['eyebrow']['child'];
				}
			}
			if ( $span === array() ) {
				continue;
			}

			$blocks = array();
			foreach ( $assign as $row ) {
				if ( $row['group'] !== $g ) {
					continue;
				}
				if ( $blocks !== array() && null !== ( $spec['sep'] ?? null ) && isset( $kids[ (int) $spec['sep'] ] ) ) {
					$blocks[] = $kids[ (int) $spec['sep'] ];
				}
				$blocks = array_merge( $blocks, $this->draw( $base, $groups, $positions, $row, $ctx ) );
			}

			$key                     = self::key( (array) $spec['path'] );
			$plans[ $key ]['runs'][] = array(
				'start'  => min( $span ),
				'end'    => max( $span ),
				'blocks' => $blocks,
			);
			if ( $blocks === array() && ! empty( $spec['prune'] ) ) {
				$plans[ $key ]['prune'] = true;
			}
		}
	}

	/**
	 * The blocks one item becomes: the item, and its eyebrow when it has one.
	 *
	 * @param array<string, mixed>             $base
	 * @param array<int, array<string, mixed>> $groups
	 * @param array<int, array<string, mixed>> $positions
	 * @param array<string, mixed>             $row
	 * @param array<string, mixed>             $ctx
	 * @return array<int, array<string, mixed>>
	 */
	private function draw( array $base, array $groups, array $positions, array $row, array $ctx ): array {
		$pos   = $positions[ $row['pos'] ];
		$path  = array_merge( (array) $groups[ (int) $pos['group'] ]['path'], array( (int) $pos['child'] ) );
		$proto = Header_Template::node_in( $base, $path );
		$node  = $row['node'];

		if ( $row['mode'] === 'flyout' ) {
			$block = $this->flyout( $ctx, $proto, $pos, $node );
		} else {
			$block = $this->item( $proto, $pos, $node, $row['same'], $row['positional'], $row['mode'] === 'trigger', $ctx );
		}
		$out = array( $block );

		if ( is_array( $pos['eyebrow'] ?? null ) ) {
			$eyebrow_path = array_merge( (array) $groups[ (int) $pos['group'] ]['path'], array( (int) $pos['eyebrow']['child'] ) );
			$eyebrow      = Header_Template::node_in( $base, $eyebrow_path );
			$description  = trim( (string) ( $node['item']->description ?? '' ) );
			if ( $description !== '' ) {
				// A mirror's eyebrow keeps its own wording while the description
				// is the primary's design text (Header_Template::rank()).
				if ( Header_Html::norm( $description ) !== Header_Html::norm( (string) ( $pos['eyebrow']['design'] ?? $pos['eyebrow']['label'] ) ) ) {
					$eyebrow = $this->fill_label(
						$eyebrow,
						array(
							'path' => array(),
							'el'   => 'root',
						),
						Header_Html::label_html( $description )
					);
				}
				if ( ! empty( $pos['eyebrow']['after'] ) ) {
					$out[] = $eyebrow;
				} else {
					array_unshift( $out, $eyebrow );
				}
			}
		}

		return $out;
	}

	/**
	 * Which prototype draws each item.
	 *
	 * Item i is drawn with position i. When the design's last item looks
	 * unlike the rest — Semper Dry's last mobile link has no rule under it —
	 * the LAST item takes it, whatever the count, and the items the design
	 * did not have take the most common look. An item with children takes a
	 * dropdown prototype, one without a link prototype; when the position
	 * holds the other kind the most common one of the right kind stands in.
	 *
	 * @param array<int, array<string, mixed>> $positions
	 * @param array<int, array<string, mixed>> $nodes
	 * @param array<string, mixed>             $ctx
	 * @return array<int, array<string, mixed>>
	 */
	private function assign( array $positions, array $nodes, array $ctx ): array {
		$k      = count( $positions );
		$n      = count( $nodes );
		$counts = array_count_values( array_map( static fn( array $p ): string => (string) $p['sig'], $positions ) );
		arsort( $counts );
		$common = (string) array_key_first( $counts );
		$last   = (string) $positions[ $k - 1 ]['sig'];
		$tail   = $k >= 3 && $last !== $common && 1 === ( $counts[ $last ] ?? 0 );

		$has_drop = false;
		$has_leaf = false;
		foreach ( $positions as $p ) {
			if ( $p['kind'] === 'dropdown' && is_array( $p['panel'] ?? null ) ) {
				$has_drop = true;
			} elseif ( $p['kind'] !== 'dropdown' ) {
				$has_leaf = true;
			}
		}

		$out = array();
		foreach ( array_values( $nodes ) as $i => $node ) {
			$kids = is_array( $node['children'] ?? null ) ? $node['children'] : array();
			$want = $kids !== array() && (int) $ctx['depth'] < self::MAX_DEPTH ? 'dropdown' : 'leaf';
			if ( $tail ) {
				$p = $i === $n - 1 ? $k - 1 : ( $i < $k - 1 ? $i : null );
			} else {
				$p = $i < $k ? $i : null;
			}

			$mode = 'item';
			if ( $want === 'dropdown' && ! $has_drop ) {
				if ( isset( $ctx['dropdown'] ) && $has_leaf ) {
					// A level the design never drew: a flyout from the
					// dropdown above, carrying this panel's link look.
					$mode = 'flyout';
					$want = 'leaf';
				} else {
					// Nothing to open: the design shows this level flat (the
					// mobile drawer lists only the top items).
					$want = 'leaf';
				}
			}
			if ( $want === 'leaf' && ! $has_leaf ) {
				$mode = 'trigger';
				$want = 'dropdown';
			}

			$positional = null !== $p && self::fits( $positions[ $p ], $want );
			$use        = $positional ? $p : self::common( $positions, $want );
			$out[]      = array(
				'node'       => $node,
				'pos'        => $use,
				'positional' => $positional,
				'mode'       => $mode,
				'same'       => self::same_label( (string) ( $node['item']->title ?? '' ), (string) ( $positions[ $use ]['design'] ?? $positions[ $use ]['label'] ) ),
			);
		}

		return $out;
	}

	/**
	 * A condensed mirror (one mobile trust line for four desktop items) once
	 * the menu is not the design's any more (plan_slot() leaves it as drawn
	 * until then): its first items, in the menu's words.
	 *
	 * @param array<int, array<string, mixed>> $positions
	 * @param array<int, array<string, mixed>> $nodes
	 * @return array<int, array<string, mixed>>
	 */
	private function assign_condensed( array $positions, array $nodes ): array {
		$out = array();
		foreach ( array_slice( array_values( $nodes ), 0, count( $positions ) ) as $i => $node ) {
			$out[] = array(
				'node'       => $node,
				'pos'        => $i,
				'positional' => true,
				'mode'       => $positions[ $i ]['kind'] === 'dropdown' ? 'trigger' : 'item',
				'same'       => false,
			);
		}

		return $out;
	}

	/**
	 * Whether any of these positions is a dropdown with a panel.
	 *
	 * @param array<int, array<string, mixed>> $positions
	 */
	private static function has_dropdowns( array $positions ): bool {
		foreach ( $positions as $p ) {
			if ( ( $p['kind'] ?? '' ) === 'dropdown' && is_array( $p['panel'] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The labels of the design's own dropdowns (the primary navigation's),
	 * as same_label() compares them.
	 *
	 * @return array<string, bool>
	 */
	private function design_dropdowns(): array {
		$out = array();
		foreach ( (array) ( $this->spec['slots'] ?? array() ) as $slot ) {
			if ( Header_Template::NAV !== ( $slot['location'] ?? '' ) || empty( $slot['primary'] ) ) {
				continue;
			}
			foreach ( (array) $slot['positions'] as $p ) {
				if ( ( $p['kind'] ?? '' ) === 'dropdown' && is_array( $p['panel'] ?? null ) ) {
					$out[ Header_Html::strip_glyphs( Header_Html::norm( (string) $p['label'] ) ) ] = true;
				}
			}
		}

		return $out;
	}

	/**
	 * $nodes with the descendants of every node that has children and is not
	 * one of the design's dropdowns listed right after it, as items of their
	 * own.
	 *
	 * @param array<int, array<string, mixed>> $nodes
	 * @param array<string, bool>              $drops design_dropdowns()
	 * @return array<int, array<string, mixed>>
	 */
	private static function flatten_new( array $nodes, array $drops ): array {
		$out  = array();
		$down = static function ( array $kids ) use ( &$down ): array {
			$flat = array();
			foreach ( $kids as $kid ) {
				$flat[] = array(
					'item'     => $kid['item'],
					'children' => array(),
				);
				$flat   = array_merge( $flat, $down( (array) ( $kid['children'] ?? array() ) ) );
			}

			return $flat;
		};
		foreach ( $nodes as $node ) {
			$out[] = $node;
			$kids  = (array) ( $node['children'] ?? array() );
			$label = Header_Html::strip_glyphs( Header_Html::norm( (string) ( $node['item']->title ?? '' ) ) );
			if ( $kids !== array() && ! isset( $drops[ $label ] ) ) {
				$out = array_merge( $out, $down( $kids ) );
			}
		}

		return $out;
	}

	/**
	 * How many menu levels a header draws: 1 for a navigation with no
	 * dropdown, 2 with dropdowns, 3 when a dropdown's panel holds links a
	 * flyout can be made from (Header_Renderer::MAX_DEPTH). The editor tells
	 * a person what a sub-item in Appearance > Menus will do.
	 *
	 * @param array<string, mixed> $spec
	 */
	public static function levels( array $spec ): int {
		$levels = 0;
		foreach ( (array) ( $spec['slots'] ?? array() ) as $slot ) {
			if ( Header_Template::NAV !== ( $slot['location'] ?? '' ) || empty( $slot['primary'] ) ) {
				continue;
			}
			$levels = 1;
			foreach ( (array) $slot['positions'] as $p ) {
				if ( ( $p['kind'] ?? '' ) === 'dropdown' && is_array( $p['panel'] ?? null ) ) {
					$levels = max( $levels, 2 );
					foreach ( (array) $p['panel']['positions'] as $q ) {
						if ( ( $q['kind'] ?? '' ) !== 'dropdown' ) {
							$levels = self::MAX_DEPTH;
						}
					}
				}
			}
		}

		return $levels;
	}

	/**
	 * Whether a menu item's label is the design's: compared as text, less
	 * the glyphs a design label is read without ("Services ▼" is "Services").
	 */
	private static function same_label( string $title, string $design ): bool {
		return Header_Html::strip_glyphs( Header_Html::norm( $title ) ) === Header_Html::strip_glyphs( Header_Html::norm( $design ) );
	}

	/**
	 * @param array<string, mixed> $position
	 */
	private static function fits( array $position, string $want ): bool {
		return $want === 'dropdown'
			? $position['kind'] === 'dropdown' && is_array( $position['panel'] ?? null )
			: $position['kind'] !== 'dropdown';
	}

	/**
	 * The prototype an item the design did not have is drawn with: the most
	 * common look among positions of the right kind, the last one wearing it
	 * — and of those, one whose label carries no decoration of its own. The
	 * rating's stars belong to the rating: a fifth trust item cloned from it
	 * would wear them too.
	 *
	 * @param array<int, array<string, mixed>> $positions
	 */
	private static function common( array $positions, string $want ): int {
		$counts = array();
		$last   = array();
		$plain  = array();
		foreach ( $positions as $i => $p ) {
			if ( ! self::fits( $p, $want ) ) {
				continue;
			}
			$sig            = (string) $p['sig'];
			$counts[ $sig ] = ( $counts[ $sig ] ?? 0 ) + 1;
			$last[ $sig ]   = $i;
			if ( ! empty( $p['plain'] ) ) {
				$plain[ $sig ] = $i;
			}
		}
		if ( $counts === array() ) {
			return count( $positions ) - 1;
		}
		arsort( $counts );
		$sig = (string) array_key_first( $counts );

		return (int) ( $plain[ $sig ] ?? $last[ $sig ] );
	}

	/**
	 * One item drawn from its prototype.
	 *
	 * @param array<string, mixed> $proto
	 * @param array<string, mixed> $pos
	 * @param array<string, mixed> $node
	 * @param array<string, mixed> $ctx
	 * @return array<string, mixed>
	 */
	private function item( array $proto, array $pos, array $node, bool $same, bool $positional, bool $trigger_only, array $ctx ): array {
		$item  = $node['item'];
		$block = $proto;

		if ( $pos['kind'] === 'dropdown' && $trigger_only ) {
			// A dropdown's trigger standing in for a plain link: the slot has
			// no link look of its own. Its caret goes; there is no panel.
			$trigger = (array) $pos['trigger'];
			if ( $trigger === array() ) {
				// A list item's own link: the item without its nested list.
				$block = self::with_kids( $proto, array() );
			} else {
				$block = Header_Template::node_in( $proto, $trigger );
			}
			$block = $this->fill( $block, $pos, $item, false, true );
		} elseif ( $pos['kind'] === 'dropdown' ) {
			if ( ! $positional ) {
				// Renamed before its panel is filled, so a dropdown or flyout
				// drawn inside it keeps the value it is given there.
				$block = $this->reclone( $block, (string) ( $item->title ?? '' ) );
			}
			$block = $this->patch( $block, (array) $pos['trigger'], fn( array $t ): array => $this->fill( $t, $pos, $item, $same ) );
			if ( is_array( $pos['panel'] ?? null ) ) {
				$plans = array();
				$this->plan_slot(
					$block,
					$pos['panel'],
					is_array( $node['children'] ?? null ) ? $node['children'] : array(),
					$plans,
					array(
						'depth'    => (int) $ctx['depth'] + 1,
						'dropdown' => array(
							'block' => $proto,
							'pos'   => $pos,
						),
					)
				);
				$block = $this->rebuild( $block, array(), $plans ) ?? $block;
			}
		} else {
			$block = $this->fill( $block, $pos, $item, $same );
			if ( ! $positional ) {
				$block = $this->reclone( $block, (string) ( $item->title ?? '' ) );
			}
		}

		return $this->add_classes( $block, $item );
	}

	/**
	 * A third level the design never drew: the dropdown above, cloned, with
	 * this panel's link look as its trigger and its panel beside it rather
	 * than below. Its state is renamed to one of its own, so opening it does
	 * not close the dropdown it sits in.
	 *
	 * @param array<string, mixed> $ctx
	 * @param array<string, mixed> $leaf     This panel's link prototype.
	 * @param array<string, mixed> $leaf_pos
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 */
	private function flyout( array $ctx, array $leaf, array $leaf_pos, array $node ): array {
		$item   = $node['item'];
		$parent = (array) $ctx['dropdown'];
		$pos    = (array) $parent['pos'];
		$block  = (array) $parent['block'];

		$trigger = $this->fill( $leaf, $leaf_pos, $item, false );
		// Where the design put the toggle on its trigger rather than on the
		// dropdown's box (Arcus Restoration's `<button class="dxai-toggle-…">`),
		// the link that replaces the trigger carries it, or nothing opens the flyout.
		$trigger = self::carry_toggle( Header_Template::node_in( $block, (array) $pos['trigger'] ), $trigger );
		$block   = $this->patch( $block, (array) $pos['trigger'], static fn(): array => $trigger );

		$plans = array();
		$this->plan_slot(
			$block,
			(array) $pos['panel'],
			is_array( $node['children'] ?? null ) ? $node['children'] : array(),
			$plans,
			array( 'depth' => (int) $ctx['depth'] + 1 )
		);
		$block = $this->rebuild( $block, array(), $plans ) ?? $block;
		$block = $this->reclone( $block, (string) ( $item->title ?? '' ), 'dxaiFly' . (int) $ctx['depth'] );
		$block = $this->patch( $block, array(), static fn( array $b ): array => self::chunk_class( $b, self::FLYOUT ) );
		$block = $this->patch( $block, (array) $pos['panel_root'], static fn( array $b ): array => self::chunk_class( $b, self::FLYOUT_PANEL ) );

		$this->flyout = true;

		return $this->add_classes( $block, $item );
	}

	/**
	 * $to with the runtime toggle $from's own element carries: its
	 * `dxai-toggle-*` classes and the attributes that say how it toggles.
	 *
	 * @param array<string, mixed> $from
	 * @param array<string, mixed> $to
	 * @return array<string, mixed>
	 */
	private static function carry_toggle( array $from, array $to ): array {
		$tag     = Header_Template::root_tag( $from );
		$classes = preg_split( '/\s+/', (string) ( $tag['attrs']['class'] ?? '' ) ) ?: array();
		$toggles = array_values( array_filter( $classes, static fn( string $c ): bool => str_starts_with( $c, 'dxai-toggle-' ) ) );
		if ( $toggles === array() ) {
			return $to;
		}
		$p = new \WP_HTML_Tag_Processor( Header_Template::chunk( $to ) );
		if ( ! $p->next_tag() ) {
			return $to;
		}
		foreach ( $toggles as $class ) {
			$p->add_class( $class );
		}
		foreach ( (array) ( $tag['attrs'] ?? array() ) as $name => $value ) {
			if ( in_array( $name, array( 'aria-expanded', 'aria-haspopup' ), true ) || str_starts_with( (string) $name, 'data-dxai-' ) ) {
				$p->set_attribute( (string) $name, (string) $value );
			}
		}

		return self::set_chunk( $to, $p->get_updated_html() );
	}

	/**
	 * An item's link and label, written into its prototype.
	 *
	 * @param array<string, mixed> $block
	 * @param array<string, mixed> $pos
	 * @param object               $item
	 * @return array<string, mixed>
	 */
	private function fill( array $block, array $pos, $item, bool $same, bool $strip = false ): array {
		$url  = (string) ( $item->url ?? '' );
		$link = is_array( $pos['link'] ?? null ) ? $pos['link'] : null;

		if ( null !== $link ) {
			$block = $this->patch(
				$block,
				(array) $link['path'],
				static fn( array $b ): array => self::set_chunk( $b, self::link_attrs( Header_Template::chunk( $b ), (string) $link['el'], $item, $pos ) )
			);
		}

		// A text item (a trust line) that has a real URL becomes a link.
		$wrap = null === $link && $url !== '' && $url !== '#';
		if ( $same && ! $wrap && ! $strip ) {
			return $block;
		}

		$at = is_array( $pos['label_at'] ?? null ) ? $pos['label_at'] : null;
		if ( null === $at ) {
			// An icon link: its label is its accessible name.
			if ( null !== $link && ! $same ) {
				$name  = Header_Html::norm( (string) ( $item->title ?? '' ) );
				$block = $this->patch(
					$block,
					(array) $link['path'],
					static function ( array $b ) use ( $link, $name ): array {
						$p = new \WP_HTML_Tag_Processor( Header_Template::chunk( $b ) );
						if ( 'root' === $link['el'] ? $p->next_tag() : $p->next_tag( array( 'tag_name' => 'a' ) ) ) {
							$p->set_attribute( 'aria-label', $name );
						}

						return self::set_chunk( $b, $p->get_updated_html() );
					}
				);
			}

			return $block;
		}

		$title = (string) ( $item->title ?? '' );
		if ( ! empty( $pos['glyphs'] ) && ! str_contains( $title, '<' ) ) {
			// The design's caret stays in the text around the label (fill());
			// a menu label still carrying it would show it twice.
			$title = Header_Html::strip_glyphs( Header_Html::norm( $title ) );
		}
		$label = $same ? esc_html( (string) $pos['label'] ) : Header_Html::label_html( $title );
		$open  = '';
		$close = '';
		if ( $wrap ) {
			$open  = '<a href="' . esc_url( $url ) . '"' . self::wrap_attrs( $item ) . '>';
			$close = '</a>';
		}

		return $this->fill_label( $block, $at, $label, $open, $close, $strip );
	}

	/**
	 * $block with the label at $at replaced.
	 *
	 * @param array<string, mixed>                  $block
	 * @param array{path:array<int, int>, el:string} $at
	 * @return array<string, mixed>
	 */
	private function fill_label( array $block, array $at, string $label_html, string $open = '', string $close = '', bool $strip = false ): array {
		return $this->patch(
			$block,
			(array) $at['path'],
			static function ( array $b ) use ( $at, $label_html, $open, $close, $strip ): array {
				$chunk = Header_Template::chunk( $b );
				$inner = Header_Html::inner( $chunk, (string) $at['el'] );
				if ( null === $inner ) {
					return $b;
				}
				if ( $strip ) {
					$inner = Header_Html::strip_decorations( $inner );
				}

				return self::set_chunk( $b, Header_Html::replace_inner( $chunk, (string) $at['el'], Header_Html::fill( $inner, $label_html, $open, $close ) ) );
			}
		);
	}

	/**
	 * The link element's attributes from the menu item. Each is written only
	 * when it differs from what the design put there, so a design item's
	 * element keeps every byte.
	 *
	 * @param object               $item
	 * @param array<string, mixed> $pos
	 */
	private static function link_attrs( string $chunk, string $which, $item, array $pos ): string {
		$p     = new \WP_HTML_Tag_Processor( $chunk );
		$found = 'root' === $which ? $p->next_tag() : $p->next_tag( array( 'tag_name' => 'a' ) );
		if ( ! $found || 'A' !== strtoupper( (string) $p->get_tag() ) ) {
			return $chunk;
		}

		$url = (string) ( $item->url ?? '' );
		$old = $p->get_attribute( 'href' );
		if ( $url === '' ) {
			if ( null !== $old ) {
				$p->remove_attribute( 'href' );
			}
		} elseif ( ! is_string( $old ) || $old !== $url ) {
			// set_attribute() runs esc_url() on a URL attribute; tel: and
			// mailto: are allowed protocols.
			$p->set_attribute( 'href', $url );
		}

		$target = (string) ( $item->target ?? '' );
		$rel    = trim( (string) ( $item->xfn ?? '' ) );
		if ( $rel === '' && $target === '_blank' && (string) ( $pos['target'] ?? '' ) !== '_blank' ) {
			// Core's walker does the same for a new-tab link with no rel.
			$rel = 'noopener';
		}
		$title = (string) ( $item->attr_title ?? '' );
		foreach ( array(
			'target' => $target,
			'rel'    => $rel,
			'title'  => $title,
		) as $name => $want ) {
			$have = $p->get_attribute( $name );
			if ( $want === '' ) {
				if ( is_string( $have ) && $have !== '' ) {
					$p->remove_attribute( $name );
				}
			} elseif ( $have !== $want ) {
				$p->set_attribute( $name, $want );
			}
		}
		if ( ! empty( $item->current ) ) {
			$p->set_attribute( 'aria-current', 'page' );
		}

		return $p->get_updated_html();
	}

	/**
	 * target/rel/title for a link a text item gained.
	 *
	 * @param object $item
	 */
	private static function wrap_attrs( $item ): string {
		$out    = '';
		$target = (string) ( $item->target ?? '' );
		$rel    = trim( (string) ( $item->xfn ?? '' ) );
		if ( $target !== '' ) {
			$out .= ' target="' . esc_attr( $target ) . '"';
			if ( $rel === '' && $target === '_blank' ) {
				$rel = 'noopener';
			}
		}
		if ( $rel !== '' ) {
			$out .= ' rel="' . esc_attr( $rel ) . '"';
		}
		if ( (string) ( $item->attr_title ?? '' ) !== '' ) {
			$out .= ' title="' . esc_attr( (string) $item->attr_title ) . '"';
		}
		if ( ! empty( $item->current ) ) {
			$out .= ' aria-current="page"';
		}

		return $out;
	}

	/**
	 * The classes a person gave the item (CSS Classes in Appearance > Menus),
	 * on the item's own element — the `<li>` for core's walker, the link or
	 * the dropdown box here. Utility classes work there the same way.
	 *
	 * @param array<string, mixed> $block
	 * @param object               $item
	 * @return array<string, mixed>
	 */
	private function add_classes( array $block, $item ): array {
		$classes = array_filter( array_map( 'sanitize_html_class', array_map( 'strval', (array) ( $item->classes ?? array() ) ) ) );
		if ( $classes === array() ) {
			return $block;
		}

		return self::chunk_class( $block, implode( ' ', $classes ) );
	}

	/**
	 * $block with classes added to its own element.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function chunk_class( array $block, string $classes ): array {
		$p = new \WP_HTML_Tag_Processor( Header_Template::chunk( $block ) );
		if ( ! $p->next_tag() ) {
			return $block;
		}
		foreach ( preg_split( '/\s+/', trim( $classes ) ) ?: array() as $class ) {
			if ( $class !== '' ) {
				$p->add_class( $class );
			}
		}

		return self::set_chunk( $block, $p->get_updated_html() );
	}

	/**
	 * A clone that sits beside its prototype: new state values for its
	 * dropdowns (or a new state name, for a flyout), and new ids.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private function reclone( array $block, string $label, string $state = '' ): array {
		$html = Header_Template::html( $block );
		$map  = array();
		if ( preg_match_all( '/\bdxai-(?:toggle|on|off|cls)-([A-Za-z_][A-Za-z0-9_]*)--([A-Za-z0-9_-]+)/', $html, $marks, PREG_SET_ORDER ) ) {
			$base = sanitize_title( remove_accents( Header_Html::norm( $label ) ) );
			$base = preg_replace( '/[^a-z0-9-]/', '', $base ) ?? '';
			$base = $base !== '' ? $base : 'item';
			foreach ( $marks as $mark ) {
				$from = $mark[1] . '--' . $mark[2];
				if ( isset( $map[ $from ] ) ) {
					continue;
				}
				$to_state     = $state !== '' ? $state : $mark[1];
				$map[ $from ] = $to_state . '--' . $this->unique( $to_state, $base );
			}
		}
		$ids = array();
		if ( preg_match_all( '/\sid="([^"]+)"/', $html, $found ) ) {
			++$this->clones;
			foreach ( array_unique( $found[1] ) as $id ) {
				$ids[ $id ] = $id . '-' . $this->clones;
			}
		}
		if ( $map === array() && $ids === array() ) {
			return $block;
		}

		return self::map_chunks(
			$block,
			static function ( string $chunk ) use ( $map, $ids ): string {
				foreach ( $map as $from => $to ) {
					$quoted = preg_quote( $from, '/' );
					list( $from_state, $from_value ) = explode( '--', $from, 2 );
					list( $to_state, $to_value )     = explode( '--', $to, 2 );
					$chunk = preg_replace( '/\bdxai-(toggle|on|off|cls)-' . $quoted . '(?![A-Za-z0-9_-])/', 'dxai-$1-' . $to, $chunk ) ?? $chunk;
					// Per-value projection attributes: data-dxai-on-openFaq-2.
					$chunk = preg_replace( '/\bdata-dxai-(on|off)-' . preg_quote( $from_state . '-' . $from_value, '/' ) . '(?=[\s=>])/', 'data-dxai-$1-' . $to_state . '-' . $to_value, $chunk ) ?? $chunk;
				}
				foreach ( $ids as $from => $to ) {
					$q     = preg_quote( $from, '/' );
					$chunk = preg_replace( '/(\s(?:id|for|aria-controls|aria-labelledby|aria-describedby)=")' . $q . '"/', '${1}' . $to . '"', $chunk ) ?? $chunk;
					$chunk = preg_replace( '/(\shref="#)' . $q . '"/', '${1}' . $to . '"', $chunk ) ?? $chunk;
				}

				return $chunk;
			}
		);
	}

	/**
	 * A state value no other element of the header uses.
	 */
	private function unique( string $state, string $base ): string {
		$value = $base;
		$n     = 2;
		while ( isset( $this->used[ $state ][ $value ] ) ) {
			$value = $base . '-' . $n++;
		}
		$this->used[ $state ][ $value ] = true;

		return $value;
	}

	/**
	 * Every state value the design uses, so no clone is given one.
	 */
	private function seed( string $markup ): void {
		$this->used = array();
		if ( preg_match_all( '/\bdxai-(?:toggle|on|off|cls)-([A-Za-z_][A-Za-z0-9_]*)--([A-Za-z0-9_-]+)/', $markup, $marks, PREG_SET_ORDER ) ) {
			foreach ( $marks as $mark ) {
				$this->used[ $mark[1] ][ $mark[2] ] = true;
			}
		}
	}

	/**
	 * The Site Logo in every logo of the design, when it is not the design's.
	 *
	 * @param array<string, array<string, mixed>> $plans
	 */
	private function plan_logos( array &$plans ): void {
		$url = (string) ( $this->logo['url'] ?? '' );
		if ( $url === '' ) {
			return;
		}
		$design_id = (int) ( $this->spec['logo']['attachment_id'] ?? 0 );
		$alt       = (string) ( $this->logo['alt'] ?? '' );
		foreach ( (array) ( $this->spec['logos'] ?? array() ) as $logo ) {
			if ( ( $design_id > 0 && (int) ( $this->logo['id'] ?? 0 ) === $design_id ) || $url === (string) ( $logo['src'] ?? '' ) ) {
				continue;
			}
			$plans[ self::key( (array) $logo['path'] ) ]['patch'][] = static fn( array $b ): array => self::map_chunks(
				$b,
				static function ( string $chunk ) use ( $url, $alt ): string {
					$p = new \WP_HTML_Tag_Processor( $chunk );
					if ( ! $p->next_tag( array( 'tag_name' => 'img' ) ) ) {
						return $chunk;
					}
					$p->set_attribute( 'src', $url );
					if ( $alt !== '' ) {
						$p->set_attribute( 'alt', $alt );
					}
					// They described the design's image, not this one.
					foreach ( array( 'srcset', 'sizes', 'width', 'height' ) as $gone ) {
						$p->remove_attribute( $gone );
					}

					return $p->get_updated_html();
				}
			);
		}
	}

	/**
	 * Apply the plans: replace each planned run of children, drop a group
	 * container left empty, patch what is patched — children first, so a
	 * run's indexes are always the template's own.
	 *
	 * @param array<string, mixed>                $block
	 * @param array<int, int>                     $path
	 * @param array<string, array<string, mixed>> $plans
	 * @return array<string, mixed>|null Null when the block is dropped.
	 */
	private function rebuild( array $block, array $path, array $plans ): ?array {
		$kids  = Header_Template::kids( $block );
		$plan  = $plans[ self::key( $path ) ] ?? array();
		$runs  = (array) ( $plan['runs'] ?? array() );
		$slots = array();
		foreach ( $kids as $i => $kid ) {
			$slots[ $i ] = null;
		}
		foreach ( $runs as $run ) {
			for ( $i = (int) $run['start']; $i <= (int) $run['end']; $i++ ) {
				$slots[ $i ] = array();
			}
			$slots[ (int) $run['start'] ] = (array) $run['blocks'];
		}
		foreach ( $kids as $i => $kid ) {
			if ( null === $slots[ $i ] ) {
				$built       = $this->rebuild( $kid, array_merge( $path, array( $i ) ), $plans );
				$slots[ $i ] = null === $built ? array() : array( $built );
			}
		}

		$changed = $runs !== array();
		foreach ( $kids as $i => $kid ) {
			if ( count( $slots[ $i ] ) !== 1 || $slots[ $i ][0] !== $kid ) {
				$changed = true;
				break;
			}
		}
		if ( $changed ) {
			$block = self::with_kids( $block, $slots );
			if ( ! empty( $plan['prune'] ) && Header_Template::kids( $block ) === array() ) {
				return null;
			}
		}
		foreach ( (array) ( $plan['patch'] ?? array() ) as $fn ) {
			$block = $fn( $block );
		}

		return $block;
	}

	/**
	 * $block with child i replaced by $slots[i] (zero, one or many blocks),
	 * its inner content re-threaded to match.
	 *
	 * @param array<string, mixed>                          $block
	 * @param array<int, array<int, array<string, mixed>>> $slots
	 * @return array<string, mixed>
	 */
	private static function with_kids( array $block, array $slots ): array {
		$content = array();
		$kids    = array();
		$k       = 0;
		foreach ( (array) ( $block['innerContent'] ?? array() ) as $chunk ) {
			if ( is_string( $chunk ) ) {
				$content[] = $chunk;
				continue;
			}
			foreach ( (array) ( $slots[ $k ] ?? array() ) as $kid ) {
				$content[] = null;
				$kids[]    = $kid;
			}
			++$k;
		}
		$block['innerBlocks']  = $kids;
		$block['innerContent'] = $content;
		$block['innerHTML']    = implode( '', array_filter( $content, 'is_string' ) );

		return $block;
	}

	/**
	 * $block with the block at $path replaced by $fn( that block ).
	 *
	 * @param array<string, mixed> $block
	 * @param array<int, int>      $path
	 * @return array<string, mixed>
	 */
	private function patch( array $block, array $path, callable $fn ): array {
		if ( $path === array() ) {
			return $fn( $block );
		}
		$i = (int) array_shift( $path );
		if ( ! isset( $block['innerBlocks'][ $i ] ) ) {
			return $block;
		}
		$block['innerBlocks'][ $i ] = $this->patch( $block['innerBlocks'][ $i ], $path, $fn );

		return $block;
	}

	/**
	 * $block with its first HTML chunk replaced.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function set_chunk( array $block, string $chunk ): array {
		$content = (array) ( $block['innerContent'] ?? array() );
		if ( $content === array() ) {
			$content = array( $chunk );
		} else {
			foreach ( $content as $i => $part ) {
				if ( is_string( $part ) ) {
					$content[ $i ] = $chunk;
					break;
				}
			}
		}
		$block['innerContent'] = $content;
		$block['innerHTML']    = implode( '', array_filter( $content, 'is_string' ) );

		return $block;
	}

	/**
	 * $block with $fn applied to every HTML chunk of it and its descendants.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function map_chunks( array $block, callable $fn ): array {
		$content = (array) ( $block['innerContent'] ?? array() );
		foreach ( $content as $i => $part ) {
			if ( is_string( $part ) ) {
				$content[ $i ] = $fn( $part );
			}
		}
		$block['innerContent'] = $content;
		$block['innerHTML']    = implode( '', array_filter( $content, 'is_string' ) );
		if ( isset( $block['attrs']['className'] ) && is_string( $block['attrs']['className'] ) ) {
			$block['attrs']['className'] = $fn( ' class="' . $block['attrs']['className'] . '"' );
			$block['attrs']['className'] = (string) preg_replace( '/^ class="(.*)"$/s', '$1', $block['attrs']['className'] );
		}
		foreach ( Header_Template::kids( $block ) as $i => $kid ) {
			$block['innerBlocks'][ $i ] = self::map_chunks( $kid, $fn );
		}

		return $block;
	}

	/**
	 * @param array<int, int> $path
	 */
	private static function key( array $path ): string {
		return implode( '.', $path );
	}
}

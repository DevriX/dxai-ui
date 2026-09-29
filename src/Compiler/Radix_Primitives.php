<?php
/**
 * What each Radix primitive renders as.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

/**
 * Radix ships no markup of its own that we can read: the components are
 * compiled JavaScript in node_modules, which a Lovable ZIP does not carry. But
 * every shadcn/ui file is a thin wrapper over one — `AccordionPrimitive.Trigger`,
 * `DialogPrimitive.Content` — so a page that uses the kit is mostly Radix, and
 * with nothing to resolve them to, every one of those tags became a `<span>`.
 *
 * Measured on a page with one accordion: the panel bodies rendered open and
 * inline where the real app has them collapsed, and the section came out 199px
 * too tall; the triggers were spans, so no `<button>` existed on the page at
 * all.
 *
 * The table is the element each part renders, plus two behaviours that are not
 * a tag:
 *
 *   pass   — renders no element of its own; the children stand in for it.
 *            Context providers and portals are this.
 *   hidden — renders, but closed. A disclosure body and every overlay panel
 *            start closed, and publishing them visible is worse than a wrong
 *            tag: a Dialog's body is a `fixed z-50` panel and covers the page.
 */
final class Radix_Primitives {

	/** Parts that render nothing of their own, in every package. */
	private const PASS = array( 'Portal', 'Provider', 'Sub', 'Group', 'RadioGroup', 'Menu', 'Slottable' );

	/**
	 * The element a part renders when its package says nothing else.
	 *
	 * @var array<string, string>
	 */
	private const PARTS = array(
		'Root'           => 'div',
		'Trigger'        => 'button',
		'Content'        => 'div',
		'Item'           => 'div',
		'Header'         => 'div',
		'Title'          => 'h2',
		'Description'    => 'p',
		'Label'          => 'div',
		'Separator'      => 'div',
		'List'           => 'div',
		'Viewport'       => 'div',
		'Overlay'        => 'div',
		'Anchor'         => 'div',
		'Close'          => 'button',
		'Action'         => 'button',
		'Cancel'         => 'button',
		'Link'           => 'a',
		'Value'          => 'span',
		'Icon'           => 'span',
		'Indicator'      => 'span',
		'Thumb'          => 'span',
		'Arrow'          => 'span',
		'Image'          => 'img',
		'Fallback'       => 'span',
		'ItemText'       => 'span',
		'ItemIndicator'  => 'span',
		'Track'          => 'span',
		'Range'          => 'span',
		'Corner'         => 'div',
		'Scrollbar'      => 'div',
		'RadioItem'      => 'div',
		'CheckboxItem'   => 'div',
		'ScrollUpButton' => 'div',
		'ScrollDownButton' => 'div',
		'SubTrigger'     => 'div',
		'SubContent'     => 'div',
	);

	/**
	 * Per-package overrides, and which parts start closed.
	 *
	 * @var array<string, array{parts?: array<string, string>, closed?: array<int, string>}>
	 */
	private const PACKAGES = array(
		'accordion'        => array(
			'parts'  => array( 'Header' => 'h3' ),
			'closed' => array( 'Content' ),
		),
		'collapsible'      => array( 'closed' => array( 'Content' ) ),
		'dialog'           => array(
			'parts'  => array( 'Root' => '' ),
			'closed' => array( 'Content', 'Overlay' ),
		),
		'alert-dialog'     => array(
			'parts'  => array( 'Root' => '' ),
			'closed' => array( 'Content', 'Overlay' ),
		),
		'dropdown-menu'    => array(
			'parts'  => array( 'Root' => '', 'Label' => 'div' ),
			'closed' => array( 'Content', 'SubContent' ),
		),
		'context-menu'     => array(
			'parts'  => array( 'Root' => '' ),
			'closed' => array( 'Content', 'SubContent' ),
		),
		'menubar'          => array( 'closed' => array( 'Content', 'SubContent' ) ),
		'popover'          => array(
			'parts'  => array( 'Root' => '' ),
			'closed' => array( 'Content' ),
		),
		'hover-card'       => array(
			// The trigger is a link: Radix renders `Primitive.a`, since a
			// hover card previews what the link leads to.
			'parts'  => array( 'Root' => '', 'Trigger' => 'a' ),
			'closed' => array( 'Content' ),
		),
		'tooltip'          => array(
			'parts'  => array( 'Root' => '' ),
			'closed' => array( 'Content' ),
		),
		'select'           => array(
			'parts'  => array( 'Root' => '', 'Item' => 'div' ),
			'closed' => array( 'Content' ),
		),
		'navigation-menu'  => array(
			'parts'  => array( 'Root' => 'nav', 'List' => 'ul', 'Item' => 'li' ),
			'closed' => array( 'Content' ),
		),
		/*
		 * Tabs deliberately has no `closed` list. Radix mounts only the active
		 * panel, so hiding every one would delete content that IS on the page —
		 * and losing text is worse than showing a panel early, which is the
		 * same trade the overlay guard makes in the other direction because an
		 * overlay covers what is underneath and a tab panel does not.
		 */
		'tabs'             => array( 'parts' => array( 'List' => 'div' ) ),
		'avatar'           => array( 'parts' => array( 'Root' => 'span' ) ),
		'switch'           => array( 'parts' => array( 'Root' => 'button' ) ),
		'checkbox'         => array( 'parts' => array( 'Root' => 'button' ) ),
		'radio-group'      => array( 'parts' => array( 'Item' => 'button' ) ),
		'slider'           => array( 'parts' => array( 'Root' => 'span' ) ),
		'toggle'           => array( 'parts' => array( 'Root' => 'button' ) ),
		'toggle-group'     => array( 'parts' => array( 'Item' => 'button' ) ),
		'label'            => array( 'parts' => array( 'Root' => 'label' ) ),
		'toast'            => array(
			'parts' => array( 'Root' => 'li', 'Viewport' => 'ol', 'Title' => 'div', 'Description' => 'div' ),
		),
		'progress'         => array( 'parts' => array( 'Indicator' => 'div' ) ),
		'scroll-area'      => array( 'parts' => array( 'Thumb' => 'div' ) ),
	);

	/**
	 * Props Radix consumes itself and never puts on the element.
	 *
	 * React drops an unknown prop from the DOM; we are not React, so every one
	 * of these reached the markup — `<div type="single" collapsible="true">`
	 * and an `<div value="Does this replace our general ledger?">` carrying a
	 * whole sentence as an attribute.
	 *
	 * @var array<int, string>
	 */
	private const CONSUMED = array(
		'asChild', 'forceMount', 'present', 'value', 'defaultValue', 'onValueChange',
		'open', 'defaultOpen', 'onOpenChange', 'onCheckedChange', 'onPressedChange',
		'collapsible', 'orientation', 'dir', 'loop', 'modal', 'container',
		'sideOffset', 'alignOffset', 'side', 'align', 'avoidCollisions',
		'collisionPadding', 'collisionBoundary', 'sticky', 'hideWhenDetached',
		'delayDuration', 'skipDelayDuration', 'disableHoverableContent',
		'decorative', 'scrollHideDelay', 'ratio', 'pressed', 'defaultPressed',
		'minStepsBetweenThumbs', 'inverted', 'getValueLabel', 'position',
		'onEscapeKeyDown', 'onPointerDownOutside', 'onInteractOutside',
		'onFocusOutside', 'onCloseAutoFocus', 'onOpenAutoFocus', 'onSelect',
	);

	/**
	 * Drop the props Radix consumes, so only real attributes are emitted.
	 *
	 * @param array<string, mixed> $attrs
	 * @return array<string, mixed>
	 */
	public static function strip_props( array $attrs, string $tag ): array {
		foreach ( self::CONSUMED as $prop ) {
			unset( $attrs[ $prop ] );
		}

		// `type` is a Radix prop on an Accordion (`"single"`) and a real
		// attribute on a button or an input, and only the tag tells them apart.
		if ( ! in_array( $tag, array( 'button', 'input', 'ol', 'ul', 'li' ), true ) ) {
			unset( $attrs['type'] );
		}

		return $attrs;
	}

	/**
	 * The package name inside a Radix module specifier, or '' when the
	 * specifier is not Radix at all.
	 */
	public static function package( string $module ): string {
		if ( preg_match( '#^@radix-ui/react-([a-z-]+)$#', trim( $module ), $match ) !== 1 ) {
			return '';
		}

		return $match[1];
	}

	/**
	 * The ARIA and state attributes each part carries, by package and part.
	 *
	 * Read off the real thing rather than from the documentation: a React
	 * build of each component was rendered and its attributes dumped in both
	 * states. `state` marks the parts whose `data-state` depends on which
	 * value the root has selected, which the compiler fills in — the rest are
	 * fixed and can be written here.
	 *
	 * Why it matters beyond accessibility: the kit's own classes are keyed on
	 * these. `data-[state=active]:bg-background` on a tab trigger paints
	 * nothing at all unless `data-state` is on the element, so the selected
	 * tab rendered with no background, no shadow and the wrong text colour.
	 *
	 * @var array<string, array<string, array<string, string>>>
	 */
	private const PART_ATTRS = array(
		'accordion' => array(
			'Root'    => array( 'data-orientation' => 'vertical' ),
			'Item'    => array( 'data-orientation' => 'vertical' ),
			'Header'  => array( 'data-orientation' => 'vertical' ),
			'Trigger' => array( 'type' => 'button', 'data-orientation' => 'vertical' ),
			'Content' => array( 'role' => 'region', 'data-orientation' => 'vertical' ),
		),
		'tabs'      => array(
			'Root'    => array( 'data-orientation' => 'horizontal' ),
			'List'    => array( 'role' => 'tablist', 'aria-orientation' => 'horizontal', 'data-orientation' => 'horizontal', 'tabindex' => '0' ),
			'Trigger' => array( 'role' => 'tab', 'type' => 'button', 'data-orientation' => 'horizontal', 'tabindex' => '-1' ),
			'Content' => array( 'role' => 'tabpanel', 'tabindex' => '0', 'data-orientation' => 'horizontal' ),
		),
		'select'    => array(
			'Trigger' => array( 'role' => 'combobox', 'type' => 'button', 'dir' => 'ltr', 'aria-autocomplete' => 'none' ),
			'Content' => array( 'role' => 'listbox' ),
			'Item'    => array( 'role' => 'option' ),
		),
		'collapsible' => array(
			'Trigger' => array( 'type' => 'button' ),
		),
		/*
		 * The overlays. Read off a React build of each, at rest and open: the
		 * trigger announces what it opens through `aria-haspopup`, and the
		 * content carries the role. Everything that depends on WHICH trigger
		 * owns which panel — ids, aria-controls, aria-labelledby — is stateful
		 * and filled in by the compiler.
		 */
		'dialog'        => array(
			'Trigger' => array( 'type' => 'button', 'aria-haspopup' => 'dialog' ),
			'Content' => array( 'role' => 'dialog', 'tabindex' => '-1' ),
			'Close'   => array( 'type' => 'button' ),
		),
		'alert-dialog'  => array(
			'Trigger' => array( 'type' => 'button', 'aria-haspopup' => 'dialog' ),
			'Content' => array( 'role' => 'alertdialog', 'tabindex' => '-1' ),
			'Cancel'  => array( 'type' => 'button' ),
			'Action'  => array( 'type' => 'button' ),
		),
		'dropdown-menu' => array(
			'Trigger'      => array( 'type' => 'button', 'aria-haspopup' => 'menu' ),
			'Content'      => array( 'role' => 'menu', 'aria-orientation' => 'vertical', 'dir' => 'ltr', 'tabindex' => '-1', 'data-orientation' => 'vertical' ),
			'Item'         => array( 'role' => 'menuitem', 'tabindex' => '-1', 'data-orientation' => 'vertical' ),
			'CheckboxItem' => array( 'role' => 'menuitemcheckbox', 'tabindex' => '-1', 'data-orientation' => 'vertical' ),
			'RadioItem'    => array( 'role' => 'menuitemradio', 'tabindex' => '-1', 'data-orientation' => 'vertical' ),
			'Separator'    => array( 'role' => 'separator', 'aria-orientation' => 'horizontal' ),
			'SubTrigger'   => array( 'role' => 'menuitem', 'aria-haspopup' => 'menu', 'tabindex' => '-1' ),
		),
		'popover'       => array(
			'Trigger' => array( 'type' => 'button', 'aria-haspopup' => 'dialog' ),
			'Content' => array( 'role' => 'dialog', 'tabindex' => '-1' ),
		),
		/*
		 * A tooltip trigger carries NO `type="button"`, on purpose: Radix
		 * leaves it off because tooltip triggers are as often anchors, where
		 * `type` means a MIME type. And the content itself is the tooltip —
		 * `role="tooltip"` and the id the trigger's aria-describedby names sit
		 * on the content element, with the text as its direct children; the
		 * separate visually-hidden copy older versions rendered is gone.
		 * Both read off the React build of @radix-ui/react-tooltip 1.1.
		 */
		'tooltip'       => array(
			'Content' => array( 'role' => 'tooltip' ),
		),
	);

	/**
	 * Which parts have a `data-state` the root's selection decides.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const STATEFUL = array(
		'accordion'     => array( 'Item', 'Header', 'Trigger', 'Content' ),
		'tabs'          => array( 'Trigger', 'Content' ),
		'select'        => array( 'Trigger', 'Content', 'Item' ),
		'collapsible'   => array( 'Trigger', 'Content' ),
		// Title and Description are stateful only in that their ids have to
		// be the ones the content's aria-labelledby / aria-describedby name.
		'dialog'        => array( 'Trigger', 'Content', 'Overlay', 'Title', 'Description', 'Close' ),
		'alert-dialog'  => array( 'Trigger', 'Content', 'Overlay', 'Title', 'Description', 'Cancel', 'Action' ),
		'dropdown-menu' => array( 'Trigger', 'Content' ),
		'popover'       => array( 'Trigger', 'Content' ),
		'tooltip'       => array( 'Trigger', 'Content' ),
		'hover-card'    => array( 'Trigger', 'Content' ),
	);

	/**
	 * The fixed attributes of one part.
	 *
	 * @return array<string, string>
	 */
	public static function attributes( string $package, string $part ): array {
		return self::PART_ATTRS[ $package ][ $part ] ?? array();
	}

	/** Whether this part's `data-state` depends on the root's selection. */
	public static function is_stateful( string $package, string $part ): bool {
		return in_array( $part, self::STATEFUL[ $package ] ?? array(), true );
	}

	/**
	 * How to render `<Package.Part>`.
	 *
	 * @return array{tag: string, hidden: bool}|null Null when the part is not
	 *         one this table knows, so the caller can report it rather than
	 *         guess an element.
	 */
	public static function element( string $package, string $part ): ?array {
		$overrides = self::PACKAGES[ $package ] ?? array();
		$parts     = is_array( $overrides['parts'] ?? null ) ? $overrides['parts'] : array();
		$closed    = is_array( $overrides['closed'] ?? null ) ? $overrides['closed'] : array();

		if ( array_key_exists( $part, $parts ) ) {
			$tag = (string) $parts[ $part ];
		} elseif ( in_array( $part, self::PASS, true ) ) {
			$tag = '';
		} elseif ( isset( self::PARTS[ $part ] ) ) {
			$tag = self::PARTS[ $part ];
		} else {
			return null;
		}

		return array(
			'tag'    => $tag,
			'hidden' => in_array( $part, $closed, true ),
		);
	}
}

<?php
/**
 * What one kind of theme offers a design: the shape every adapter fills in.
 *
 * @package DXAI_UI\Theme\Adapters
 */

declare(strict_types=1);

namespace DXAI_UI\Theme\Adapters;

/**
 * The plugin never asks for a theme's name outside this folder (docs/PLAN-ARCHITECTURE.md, section 5): it asks Theme\Capabilities, and
 * Capabilities asks the adapter of the active theme. An adapter knows its theme's name (its folder slugs), whether the theme is a DX theme
 * (header from Menus, footer from Widgets, on every page), the blocks of its own that stand in for a design's pictures, link boxes and
 * labels (and how those blocks are written: their tag, the classes they write themselves, the attributes that hold an attachment), and what
 * the theme brings on its own pages so the plugin prints nothing of its own there (a video facade's script, a block library's styles).
 *
 * Four adapters, tried in order: DX Base (the plugin's own theme), a DX theme (American Restoration and its children, and any theme the
 * `dxai_ui_dx_themes` filter adds), a block theme, and the rest (a classic theme). A theme is read through exactly one of them.
 */
abstract class Theme_Adapter {

	/** A short name for the kind of theme: `dx-base`, `amr`, `block`, `classic`. */
	abstract public function id(): string;

	/** Whether the active theme (or the theme it is a child of) is one this adapter reads. */
	abstract public function matches(): bool;

	/** Whether the theme is a DX theme: it draws the site's header from Menus and its footer from Widgets on every page. */
	public function is_dx(): bool {
		return false;
	}

	/**
	 * The theme's own blocks that stand in for a design's elements, by the part they play: `picture`, `link_box`, `span`.
	 *
	 * @return array<string, string> role => block name.
	 */
	public function blocks(): array {
		return array();
	}

	/**
	 * How the theme's blocks are written: the tag each saves ('' when its attributes say) and the classes the block writes on it itself.
	 *
	 * @return array<string, array{tag:string, classes:array<int, string>}> block name => its markup.
	 */
	public function markup(): array {
		return array();
	}

	/**
	 * The attributes of the theme's blocks that hold an attachment id (a transfer carries the attachment along).
	 *
	 * @return array<string, array<int, string>> block name => attribute names.
	 */
	public function media_id_attrs(): array {
		return array();
	}

	/**
	 * The attributes of the theme's picture block, for the plugin to read and set a picture through: `id`, `url`, `alt`, `width`, `height`,
	 * and `mobile` (the attributes of a phone's own picture, dropped when the picture changes). Empty when the theme has no picture block.
	 *
	 * @return array{id:string, url:string, alt:string, width:string, height:string, mobile:array<int, string>}|array{}
	 */
	public function picture_shape(): array {
		return array();
	}

	/**
	 * What the theme brings on its own pages, so the plugin prints nothing of its own there (Capabilities::FEATURES).
	 *
	 * @return array<int, string>
	 */
	public function brings(): array {
		return array();
	}
}

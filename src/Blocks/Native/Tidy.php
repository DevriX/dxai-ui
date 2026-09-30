<?php
/**
 * What an export leaves in a page that nothing shows.
 *
 * @package DXAI_UI\Blocks\Native
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks\Native;

/**
 * A Claude Design export carries a `<template id="__bundler_thumbnail">` with the preview picture of its own
 * bundler. A `template` element is never rendered, so it is not part of the page, but it stood in the List View as a
 * "Group" with an SVG in it, at the top of every page. It is taken out; the page looks the same.
 *
 * Only a top-level `template` group that holds nothing but that: no text, no link, no script.
 */
final class Tidy extends Converter {

	public function id(): string {
		return 'tidy';
	}

	public function label(): string {
		return __( 'Leftovers of the design\'s export removed', 'dxai-ui' );
	}

	public function source(): string {
		return 'core/group';
	}

	public function target(): string {
		return 'core/group';
	}

	public function available(): bool {
		return true;
	}

	public function convert( array $block, ?array $parent = null ): ?array {
		return null;
	}

	public function runs( array $siblings, ?array $parent, ?array $content = null ): array {
		if ( $parent !== null || $content !== null ) {
			return array();
		}
		$out = array();
		foreach ( array_values( $siblings ) as $i => $block ) {
			if ( is_array( $block ) && self::inert( $block ) ) {
				$out[] = array(
					'start' => $i,
					'end'   => $i,
					// Nothing: a block with no name and no markup serialises to nothing.
					'block' => array(
						'blockName'    => null,
						'attrs'        => array(),
						'innerBlocks'  => array(),
						'innerHTML'    => '',
						'innerContent' => array(),
					),
				);
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $block
	 */
	private static function inert( array $block ): bool {
		if ( ( $block['blockName'] ?? '' ) !== 'core/group' || ( $block['attrs']['tagName'] ?? '' ) !== 'template' ) {
			return false;
		}
		$walk = static function ( array $b ) use ( &$walk ): bool {
			$name = (string) ( $b['blockName'] ?? '' );
			if ( $name !== '' && ! in_array( $name, array( 'core/group', 'dxai-ui/svg', 'dxai-ui/box', 'core/image' ), true ) ) {
				return false;
			}
			foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $child ) {
				if ( is_array( $child ) && ! $walk( $child ) ) {
					return false;
				}
			}

			return true;
		};

		return $walk( $block ) && ! str_contains( (string) ( $block['innerHTML'] ?? '' ), '<script' );
	}
}

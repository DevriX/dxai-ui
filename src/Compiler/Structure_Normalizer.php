<?php
/**
 * Normalize structure arrays from the LLM or a single markup blob.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Structure_Normalizer {

	public const TYPES = array( 'header', 'navigation', 'hero', 'blog', 'form', 'slider', 'footer', 'section' );

	/**
	 * @param mixed  $raw
	 * @return array<int, array<string, mixed>>
	 */
	public static function from_json( mixed $raw, string $fallback_markup, string $title ): array {
		$out = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $item ) {
				$normalized = self::item( $item );
				if ( null !== $normalized ) {
					$out[] = $normalized;
				}
			}
		}

		if ( $out === array() && str_contains( $fallback_markup, '<!-- wp:' ) ) {
			$out[] = array(
				'type'             => self::guess_type( $fallback_markup ),
				'title'            => $title !== '' ? $title : __( 'Section', 'dxai-ui' ),
				'gutenberg_markup' => $fallback_markup,
				'menu_items'       => array(),
				'form_fields'      => array(),
			);
		}

		return $out;
	}

	/**
	 * @param mixed $item
	 * @return array<string, mixed>|null
	 */
	private static function item( mixed $item ): ?array {
		if ( ! is_array( $item ) ) {
			return null;
		}

		$type   = sanitize_key( (string) ( $item['type'] ?? 'section' ) );
		$markup = (string) ( $item['gutenberg_markup'] ?? '' );
		if ( $markup === '' || ! str_contains( $markup, '<!-- wp:' ) ) {
			return null;
		}
		if ( ! in_array( $type, self::TYPES, true ) ) {
			$type = self::guess_type( $markup );
		}

		$menu = array();
		if ( isset( $item['menu_items'] ) && is_array( $item['menu_items'] ) ) {
			foreach ( $item['menu_items'] as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$label = sanitize_text_field( (string) ( $entry['label'] ?? '' ) );
				$url   = esc_url_raw( (string) ( $entry['url'] ?? '' ) );
				if ( $label !== '' ) {
					$menu[] = array(
						'label' => $label,
						'url'   => $url !== '' ? $url : '#',
					);
				}
			}
		}

		$fields = array();
		if ( isset( $item['form_fields'] ) && is_array( $item['form_fields'] ) ) {
			foreach ( $item['form_fields'] as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$name = sanitize_key( (string) ( $field['name'] ?? '' ) );
				if ( $name === '' ) {
					continue;
				}
				$fields[] = array(
					'name'     => $name,
					'type'     => sanitize_key( (string) ( $field['type'] ?? 'text' ) ),
					'label'    => sanitize_text_field( (string) ( $field['label'] ?? $name ) ),
					'required' => ! empty( $field['required'] ),
				);
			}
		}

		return array(
			'type'             => $type,
			'title'            => sanitize_text_field( (string) ( $item['title'] ?? ucfirst( $type ) ) ),
			'gutenberg_markup' => $markup,
			'menu_items'       => $menu,
			'form_fields'      => $fields,
		);
	}

	public static function guess_type( string $markup ): string {
		$hay = strtolower( $markup );
		return match ( true ) {
			str_contains( $hay, 'wp:query' ) || str_contains( $hay, 'wp:post-template' ) => 'blog',
			str_contains( $hay, 'dxai-ui/form' ) || str_contains( $hay, '<form' ) => 'form',
			str_contains( $hay, 'dxai-ui/slider' ) || str_contains( $hay, 'swiper' ) => 'slider',
			str_contains( $hay, 'wp:cover' ) && ( str_contains( $hay, 'hero' ) || str_contains( $hay, 'min-h-screen' ) ) => 'hero',
			str_contains( $hay, 'wp:navigation' ) && str_contains( $hay, 'site-title' ) => 'header',
			str_contains( $hay, 'wp:navigation' ) => 'navigation',
			str_contains( $hay, 'footer' ) => 'footer',
			default => 'section',
		};
	}
}

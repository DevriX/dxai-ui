<?php
/**
 * Rewrite design-internal hrefs to the WordPress pages created for them.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Internal_Links {

	/**
	 * @param array<int, array{id:int, paths:array<int, string>}> $pages
	 */
	/**
	 * Markup with its links to the pages' old paths pointing at the pages (a widget's content, say).
	 *
	 * @param array<int, array{id:int, paths:array<int, string>}> $pages
	 */
	public function rewrite_html( string $markup, array $pages ): string {
		$map = $this->permalink_map( $pages );

		return $map === array() ? $markup : $this->rewrite_markup( $markup, $map );
	}

	public function rewrite_posts( array $pages ): void {
		$map = $this->permalink_map( $pages );
		if ( $map === array() ) {
			return;
		}

		$ids = array();
		foreach ( $pages as $page ) {
			$id = (int) ( $page['id'] ?? 0 );
			if ( $id > 0 ) {
				$ids[ $id ] = true;
			}
		}

		foreach ( array_keys( $ids ) as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$updated = $this->rewrite_markup( (string) $post->post_content, $map );
			if ( $updated === (string) $post->post_content ) {
				continue;
			}
			wp_update_post(
				array(
					'ID'           => $id,
					'post_content' => wp_slash( $updated ),
				)
			);
		}
	}

	/**
	 * @param array<int, array{id:int, paths:array<int, string>}> $pages
	 * @return array<string, string> normalized path / absolute URL => permalink
	 */
	private function permalink_map( array $pages ): array {
		$map = array();
		foreach ( $pages as $page ) {
			$id  = (int) ( $page['id'] ?? 0 );
			$url = $id > 0 ? get_permalink( $id ) : '';
			if ( ! is_string( $url ) || $url === '' ) {
				continue;
			}
			foreach ( is_array( $page['paths'] ?? null ) ? $page['paths'] : array() as $path ) {
				foreach ( $this->variants( (string) $path ) as $norm ) {
					$map[ $norm ] = $url;
				}
			}
		}

		uksort(
			$map,
			static function ( string $a, string $b ): int {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		return $map;
	}

	/**
	 * @return array<int, string>
	 */
	private function variants( string $path ): array {
		$path = trim( $path );
		if ( $path === '' || $path === '#' ) {
			return array();
		}
		if ( preg_match( '#^(?:mailto|tel|javascript):#i', $path ) ) {
			return array();
		}

		$out = array();
		if ( preg_match( '#^https?://#i', $path ) ) {
			$clean = (string) strtok( $path, '?#' );
			$out[] = $clean;
			$out[] = rtrim( $clean, '/' );
			if ( ! str_ends_with( $clean, '/' ) ) {
				$out[] = $clean . '/';
			}
			$rel = $this->path_only( $clean );
			if ( $rel !== '' ) {
				foreach ( $this->path_alts( $rel ) as $alt ) {
					$out[] = $alt;
				}
			}

			return array_values( array_unique( array_filter( $out ) ) );
		}

		return $this->path_alts( $this->path_only( $path ) );
	}

	/**
	 * @return array<int, string>
	 */
	private function path_alts( string $path ): array {
		if ( $path === '' ) {
			return array();
		}
		$alts = array( $path );
		if ( $path !== '/' ) {
			$alts[] = rtrim( $path, '/' );
			$alts[] = $path . '/';
		}

		return array_values( array_unique( $alts ) );
	}

	private function path_only( string $path ): string {
		if ( preg_match( '#^https?://#i', $path ) ) {
			$path = (string) ( wp_parse_url( $path, PHP_URL_PATH ) ?: '/' );
		}
		$path = (string) strtok( $path, '?#' );
		$path = '/' . ltrim( $path, '/' );
		if ( $path !== '/' ) {
			$path = rtrim( $path, '/' );
		}

		return $path === '' ? '/' : $path;
	}

	/**
	 * @param array<string, string> $map
	 */
	private function rewrite_markup( string $markup, array $map ): string {
		foreach ( $map as $path => $permalink ) {
			$markup = str_replace( 'href="' . $path . '"', 'href="' . $permalink . '"', $markup );
			$markup = str_replace( "href='" . $path . "'", 'href="' . $permalink . '"', $markup );
			$markup = str_replace( '"url":"' . $path . '"', '"url":"' . $permalink . '"', $markup );
			// JSON-escaped slashes sometimes appear in block comment attrs.
			$esc_path = str_replace( '/', '\\/', $path );
			$esc_perm = str_replace( '/', '\\/', $permalink );
			if ( $esc_path !== $path ) {
				$markup = str_replace( '"url":"' . $esc_path . '"', '"url":"' . $esc_perm . '"', $markup );
				$markup = str_replace( 'href=\"' . $esc_path . '\"', 'href=\"' . $esc_perm . '\"', $markup );
			}
		}

		return $markup;
	}
}

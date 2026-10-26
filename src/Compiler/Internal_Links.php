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
	 * @param bool $addresses_only Only links that name the old site by its address (https://old-host/about/): in markup that is
	 *                             not the design's own a bare "/about/" is a page of this site, and stays.
	 */
	public function rewrite_html( string $markup, array $pages, bool $addresses_only = false ): string {
		$map = $this->permalink_map( $pages, $addresses_only );

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
	private function permalink_map( array $pages, bool $addresses_only = false ): array {
		$map = array();
		foreach ( $pages as $page ) {
			$id  = (int) ( $page['id'] ?? 0 );
			$url = $id > 0 ? get_permalink( $id ) : '';
			if ( ! is_string( $url ) || $url === '' ) {
				continue;
			}
			foreach ( is_array( $page['paths'] ?? null ) ? $page['paths'] : array() as $path ) {
				foreach ( $this->variants( (string) $path, $addresses_only ) as $norm ) {
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
	private function variants( string $path, bool $addresses_only = false ): array {
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
			$rel = $addresses_only ? '' : $this->path_only( $clean );
			if ( $rel !== '' ) {
				foreach ( $this->path_alts( $rel ) as $alt ) {
					$out[] = $alt;
				}
			}

			return array_values( array_unique( array_filter( $out ) ) );
		}

		return $addresses_only ? array() : $this->path_alts( $this->path_only( $path ) );
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
	 * Links to a page's old path, and to a part of it or with a query after it (`/about#careers`, `/search?q=`): those keep what
	 * follows the path on the page's address. Only the path alone used to be matched, and a link to a part of a page kept the
	 * design's path, which no page of the site has.
	 *
	 * Each link is looked up once: the value of every `href="…"`, `href='…'`, `"url":"…"` and, JSON-escaped in a block's
	 * attributes, `"url":"\/…"` and `href=\"\/…\"`, up to its first `#` or `?` — no path in the map has either (variants()). It
	 * used to be a search of the whole markup for each of the map's spellings, thirteen times each: Charter Construction's 96 pages
	 * make 1,000 spellings, and the link pass took 53 of the save's 418 seconds. The answer is the one that search gave
	 * (bin/verify-internal-links.php holds the two to it); a page's address that is itself a design path is the one case the search
	 * rewrote twice.
	 *
	 * @param array<string, string> $map
	 */
	private function rewrite_markup( string $markup, array $map ): string {
		if ( $map === array() || ( ! str_contains( $markup, 'href=' ) && ! str_contains( $markup, '"url":"' ) ) ) {
			return $markup;
		}
		/*
		 * The value with its path swapped for the page's address, or null. Escaped: a JSON value whose slashes are all written
		 * `\/`, matched as the path written so and answered the same way, as the search did for a path with a slash in it.
		 */
		$swap = static function ( string $value, bool $escaped ) use ( $map ): ?string {
			$cut  = strcspn( $value, '#?' );
			$path = substr( $value, 0, $cut );
			if ( $escaped ) {
				$plain = str_replace( '\\/', '/', $path );
				if ( $plain === $path || str_replace( '/', '\\/', $plain ) !== $path || ! isset( $map[ $plain ] ) ) {
					return null;
				}

				return str_replace( '/', '\\/', $map[ $plain ] ) . substr( $value, $cut );
			}

			return isset( $map[ $path ] ) ? $map[ $path ] . substr( $value, $cut ) : null;
		};
		$markup = (string) preg_replace_callback(
			'/href="([^"]*)"/',
			static function ( array $m ) use ( $swap ): string {
				$to = $swap( $m[1], false );

				return null === $to ? $m[0] : 'href="' . $to . '"';
			},
			$markup
		);
		// A whole value in single quotes came out in double ones; one with a part or a query after the path kept its quotes.
		$markup = (string) preg_replace_callback(
			"/href='([^']*)'/",
			static function ( array $m ) use ( $swap ): string {
				$to = $swap( $m[1], false );
				if ( null === $to ) {
					return $m[0];
				}

				return strcspn( $m[1], '#?' ) === strlen( $m[1] ) ? 'href="' . $to . '"' : "href='" . $to . "'";
			},
			$markup
		);
		$markup = (string) preg_replace_callback(
			'/"url":"([^"]*)"/',
			static function ( array $m ) use ( $swap ): string {
				$to = $swap( $m[1], false ) ?? $swap( $m[1], true );

				return null === $to ? $m[0] : '"url":"' . $to . '"';
			},
			$markup
		);

		return (string) preg_replace_callback(
			'/href=\\\\"(.*?)\\\\"/',
			static function ( array $m ) use ( $swap ): string {
				$to = $swap( $m[1], true );

				return null === $to ? $m[0] : 'href=\\"' . $to . '\\"';
			},
			$markup
		);
	}
}

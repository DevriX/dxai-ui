<?php
/**
 * A Claude Design export that is a whole site: several `.dc.html` pages, and the parts they share.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Connectors;

/**
 * Claude Design keeps a site as one `.dc.html` per page, and what every page shares — the header, the footer — as
 * `.dc.html` files of their own that the pages pull in with `<dc-import name="…">`. The connector used to read the
 * first file of the archive as THE page: the other pages were dropped, an import rendered as nothing (the header and
 * the footer were gone), and a link between pages (`href="WTS%20Why.dc.html"`) named a file no site has.
 *
 * This reads the archive the way the design's own runtime does:
 *  - a file another page imports is a component, not a page;
 *  - a file that is a copy of another page (`… -export-`, `… (copy)`) is left out;
 *  - the Home is the page named for it (home, homepage, index), else the page the header's first link opens;
 *  - every other page gets a route from its own name, without the words every page's name starts with
 *    ("WTS Why" → /why), and a title: its `<title>`, else the label the site's own links give it ("Why WTS");
 *  - every link to a page is written as that route, so the import's link rewrite finds the page it was made into.
 *
 * Nothing here is named after a design: the same rules read any export.
 */
final class Dc_Site {

	/** Words a copy of a page is named with, at the end of its name. */
	private const COPY = '/^(.+?)\s*[-(\[]+\s*(?:export|copy|offline|standalone|backup|old|draft)\s*[-)\]]*\s*$/i';

	/**
	 * @param array<string, string> $files Archive path → text, every `.dc.html` of the archive.
	 * @return array{
	 *   home: string,
	 *   pages: array<int, array{name:string, path:string, route:string, title:string, parts:array<string, mixed>}>,
	 *   components: array<string, array<string, mixed>>,
	 *   skipped: array<int, string>
	 * } Pages in order, the Home first; components by the name an import uses.
	 */
	public static function read( array $files ): array {
		$parts = array();
		$paths = array();
		foreach ( $files as $path => $html ) {
			// A page's name: its file's, without .dc.html (or .html: a handoff's index.html).
			$name = (string) preg_replace( '/(?:\.dc)?\.html?$/i', '', basename( str_replace( '\\', '/', (string) $path ) ) );
			if ( $name === '' || isset( $parts[ $name ] ) ) {
				continue;
			}
			$parts[ $name ] = Dc_Connector::split( (string) $html );
			$paths[ $name ] = (string) $path;
		}
		ksort( $parts, SORT_NATURAL | SORT_FLAG_CASE );

		// What the pages import is not a page of its own.
		$imported = array();
		foreach ( $parts as $p ) {
			foreach ( self::imports( (string) $p['template'] ) as $import ) {
				$imported[ $import ] = true;
			}
		}
		// A page sits at the archive's shallowest level (helper HTML lives deeper); a component is found wherever it is.
		$depth = static fn( string $path ): int => substr_count( trim( str_replace( '\\', '/', $path ), '/' ), '/' );
		$top   = min( array_map( $depth, $paths ?: array( '' ) ) );
		$components = array();
		$candidates = array();
		foreach ( $parts as $name => $p ) {
			if ( isset( $imported[ $name ] ) ) {
				$components[ $name ] = $p;
			} elseif ( $depth( $paths[ $name ] ) === $top ) {
				$candidates[ $name ] = $p;
			}
		}
		if ( $candidates === array() ) {
			// Every top-level file is imported by another (or one imports itself): the shallowest file is the page, as before.
			$first = '';
			foreach ( $paths as $name => $path ) {
				if ( $depth( $path ) === $top ) {
					$first = $name;
					break;
				}
			}
			$candidates[ $first ] = $parts[ $first ];
			unset( $components[ $first ] );
		}

		/*
		 * A file that is nothing but a header, a footer or a menu (one such landmark and nothing else) is a part, not a page,
		 * even when no page imports it: an export whose pages carry the header and footer written in keeps the parts beside
		 * them, and each became a page of its own named "Nav" or "Footer".
		 */
		if ( count( $candidates ) > 1 ) {
			foreach ( $candidates as $name => $p ) {
				if ( self::is_landmark_only( (string) $p['template'] ) ) {
					$components[ $name ] = $p;
					unset( $candidates[ $name ] );
				}
			}
		}

		// A copy of a page another file already is.
		$skipped = array();
		foreach ( array_keys( $candidates ) as $name ) {
			if ( preg_match( self::COPY, $name, $m ) === 1 && isset( $candidates[ trim( $m[1] ) ] ) && count( $candidates ) > 1 ) {
				$skipped[] = $name;
				unset( $candidates[ $name ] );
			}
		}

		$home   = self::home( $candidates, $components );
		$prefix = self::common_prefix( array_keys( $candidates ) );
		$routes = array();
		$taken  = array();
		foreach ( array_keys( $candidates ) as $name ) {
			if ( $name === $home ) {
				$routes[ $name ] = '/';
				continue;
			}
			$base = sanitize_title( trim( substr( $name, strlen( $prefix ) ) ) );
			$base = $base !== '' ? $base : sanitize_title( $name );
			$slug = $base;
			for ( $n = 2; isset( $taken[ $slug ] ); $n++ ) {
				$slug = $base . '-' . $n;
			}
			$taken[ $slug ]  = true;
			$routes[ $name ] = '/' . $slug;
		}

		// What the site's own links call each page, header and footer first.
		$labels = array();
		foreach ( array_merge( $components, $candidates ) as $p ) {
			foreach ( self::links( (string) $p['template'] ) as $link ) {
				if ( $link['hash'] === '' && $link['label'] !== '' && isset( $routes[ $link['target'] ] ) ) {
					$labels[ $link['target'] ][] = $link['label'];
				}
			}
		}

		// Every link to a page names the route it becomes.
		$rewrite = static function ( array $p ) use ( $routes ): array {
			$p['template'] = self::rewrite_links( (string) $p['template'], $routes );

			return $p;
		};
		$pages = array();
		foreach ( $candidates as $name => $p ) {
			$row = array(
				'name'  => $name,
				'path'  => $paths[ $name ],
				'route' => $routes[ $name ],
				'title' => self::title( $name, $prefix, $p, $labels[ $name ] ?? array() ),
				'parts' => $rewrite( $p ),
			);
			if ( $name === $home ) {
				array_unshift( $pages, $row );
			} else {
				$pages[] = $row;
			}
		}
		foreach ( $components as $name => $p ) {
			$components[ $name ] = $rewrite( $p );
		}

		return array(
			'home'       => $home,
			'pages'      => $pages,
			'components' => $components,
			'skipped'    => $skipped,
		);
	}

	/** Whether a template is a single header, footer or nav and nothing else. */
	private static function is_landmark_only( string $template ): bool {
		$body = trim( (string) preg_replace( array( '/<helmet\b[^>]*>[\s\S]*?<\/helmet>/i', '/<!--[\s\S]*?-->/', '/<script\b[^>]*>[\s\S]*?<\/script>/i' ), '', $template ) );
		if ( preg_match( '/^<(header|footer|nav)\b/i', $body, $open ) !== 1 ) {
			return false;
		}
		$tag  = strtolower( $open[1] );
		$dom  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $body . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$root = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $root instanceof \DOMElement ) {
			return false;
		}
		$nodes = array_values( array_filter( iterator_to_array( $root->childNodes ), static fn( $n ) => $n instanceof \DOMElement || ( $n instanceof \DOMText && trim( $n->textContent ) !== '' ) ) );

		return count( $nodes ) === 1 && $nodes[0] instanceof \DOMElement && strtolower( $nodes[0]->tagName ) === $tag;
	}

	/**
	 * The names a template imports.
	 *
	 * @return array<int, string>
	 */
	public static function imports( string $template ): array {
		if ( preg_match_all( '/<(?:dc-import|x-import)\b[^>]*\bname\s*=\s*("|\')(.*?)\1/i', $template, $m ) < 1 ) {
			return array();
		}

		return array_values( array_unique( array_map( static fn( $n ) => trim( html_entity_decode( (string) $n, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), $m[2] ) ) );
	}

	/**
	 * The Home: the page named for it, else the page the header's first link to a page opens, else the first page.
	 *
	 * @param array<string, array<string, mixed>> $candidates
	 * @param array<string, array<string, mixed>> $components
	 */
	private static function home( array $candidates, array $components ): string {
		$named = array();
		foreach ( array_keys( $candidates ) as $name ) {
			if ( preg_match( '/\b(home|homepage|index)\b/i', $name ) === 1 ) {
				$named[] = $name;
			}
		}
		if ( count( $named ) === 1 ) {
			return $named[0];
		}
		foreach ( $components as $p ) {
			foreach ( self::links( (string) $p['template'] ) as $link ) {
				if ( isset( $candidates[ $link['target'] ] ) && ( $named === array() || in_array( $link['target'], $named, true ) ) ) {
					return $link['target'];
				}
			}
		}

		return $named !== array() ? $named[0] : (string) array_key_first( $candidates );
	}

	/**
	 * The words every name starts with ("WTS "), when every name has more after them.
	 *
	 * @param array<int, string> $names
	 */
	private static function common_prefix( array $names ): string {
		if ( count( $names ) < 2 ) {
			return '';
		}
		$words = preg_split( '/(?<=\s)/', (string) $names[0] ) ?: array();
		$out   = '';
		foreach ( $words as $word ) {
			$next = $out . $word;
			foreach ( $names as $name ) {
				if ( ! str_starts_with( $name, $next ) || trim( substr( $name, strlen( $next ) ) ) === '' ) {
					return $out;
				}
			}
			$out = $next;
		}

		return $out;
	}

	/**
	 * A page's title: its `<title>` without the site's name after it, else the label of a link to it that shares most words with
	 * its own name, else its name.
	 *
	 * @param array<string, mixed> $parts
	 * @param array<int, string>   $labels
	 */
	private static function title( string $name, string $prefix, array $parts, array $labels ): string {
		$own = trim( (string) ( $parts['title'] ?? '' ) );
		if ( $own !== '' ) {
			$short = trim( (string) preg_replace( '/\s+[|–—]\s+[^|–—]+$/u', '', $own ) );

			return $short !== '' ? $short : $own;
		}
		$plain = trim( substr( $name, strlen( $prefix ) ) );
		$words = array_filter( preg_split( '/[^a-z0-9]+/', strtolower( $plain ) ) ?: array() );
		$best  = '';
		$score = 0.0;
		foreach ( array_unique( $labels ) as $label ) {
			$mine = array_filter( preg_split( '/[^a-z0-9]+/', strtolower( $label ) ) ?: array() );
			if ( $mine === array() || $words === array() ) {
				continue;
			}
			$shared = count( array_intersect( $mine, $words ) );
			$value  = $shared / max( count( $mine ), count( $words ) );
			if ( $shared > 0 && ( $value > $score || ( $value === $score && strlen( $label ) < strlen( $best ) ) ) ) {
				$best  = $label;
				$score = $value;
			}
		}

		return $best !== '' ? $best : ( $plain !== '' ? $plain : $name );
	}

	/**
	 * The links of a template to `.dc.html` files: the file's name, the fragment, the text.
	 *
	 * @return array<int, array{target:string, hash:string, label:string}>
	 */
	private static function links( string $template ): array {
		$out = array();
		if ( preg_match_all( '/<a\b[^>]*\bhref\s*=\s*("|\')([^"\']*?\.dc\.html)(#[^"\']*)?\1[^>]*>([\s\S]*?)<\/a>/i', $template, $m, PREG_SET_ORDER ) < 1 ) {
			return $out;
		}
		foreach ( $m as $link ) {
			// A dropdown's caret is a span of its own (`<span class="caret">▼</span>`): not a word of the label.
			$text  = (string) preg_replace( '/<span\b[^>]*>[\s▼▾▸›→←↓+]*<\/span>/u', '', (string) $link[4] );
			$label = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$label = (string) preg_replace( array( '/\s+/u', '/^[\s▼▾▸›→←]+|[\s▼▾▸›→←]+$/u' ), array( ' ', '' ), $label );
			$out[] = array(
				'target' => (string) preg_replace( '/\.dc\.html$/i', '', basename( rawurldecode( html_entity_decode( (string) $link[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) ),
				'hash'   => (string) ( $link[3] ?? '' ),
				'label'  => $label,
			);
		}

		return $out;
	}

	/**
	 * A template with each link to a page written as the page's route (`WTS%20Why.dc.html#x` → `/why#x`).
	 *
	 * @param array<string, string> $routes Page name → route.
	 */
	public static function rewrite_links( string $template, array $routes ): string {
		return (string) preg_replace_callback(
			'/(\bhref\s*=\s*)("|\')([^"\']*?\.dc\.html)(#[^"\']*)?\2/i',
			static function ( array $m ) use ( $routes ): string {
				$name = (string) preg_replace( '/\.dc\.html$/i', '', basename( rawurldecode( html_entity_decode( $m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) );
				if ( ! isset( $routes[ $name ] ) ) {
					return $m[0];
				}

				return $m[1] . $m[2] . $routes[ $name ] . ( $m[4] ?? '' ) . $m[2];
			},
			$template
		);
	}
}
